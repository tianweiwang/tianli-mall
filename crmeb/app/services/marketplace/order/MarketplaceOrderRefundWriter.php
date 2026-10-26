<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\Request;
use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\PlatformAccessServices;
use app\services\order\StoreOrderCreateServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use stdClass;
use Throwable;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;

/** Owned Task7 business writes only. This class never drives or simulates a financial refund. */
final class MarketplaceOrderRefundWriter
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const REFUND_FIELDS=['id','store_order_id','store_id','order_id','uid','refund_type','refund_num','refund_price',
        'refunded_price','refund_phone','refund_express','refund_express_name','refund_explain','refund_img','refund_reason',
        'refuse_reason','remark','refunded_time','cart_info','is_cancel','is_pink_cancel','is_del','add_time','is_system_del',
        'mer_id','marketplace_refund_mode','marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind',
        'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'];
    private const ACTION_FIELDS=['id','contract_version','root_order_id','uid','mer_id','refund_id','payment_attempt_id','action',
        'actor_type','actor_id','actor_ref','idem_key','request_digest','request_payload','result_payload','state',
        'previous_refund_action_id','fulfillment_cut_id','apply_refund_id','created_at','committed_at'];
    private const ROOT_ZERO=['is_cancel','is_del','is_system_del','refund_status','refund_type','refund_reason_time',
        'combination_id','pink_id','seckill_id','bargain_id','advance_id','virtual_type','is_gift'];

    public function __construct(private MarketplaceOrderRefundDao $refunds,private MarketplaceOrderFulfillmentDao $family,
        private MarketplaceOrderDao $orders,private MarketplacePaymentDao $payments,private MarketplaceOrderFulfillmentServices $fulfillment,
        private MarketplaceAftersaleFileServices $files,private EmployeeAuthServices $employees,private PlatformAccessServices $platform,
        private AuditLogServices $audit,private StoreOrderCreateServices $numbers){}

    /** locator is the actual commercial root PK for apply, and the native refund number for the other two commands. */
    public function forCustomer(Request $request,int $actualUid,string $action,mixed $locator,array $raw): array
    {
        $identity=$this->customer($request,$actualUid);
        if(!in_array($action,['apply','withdraw','express'],true)) self::unavailable();
        $body=$this->wire($request,$action,$raw); [$connection,$pdo]=$this->defaultConnection(); $target=null;
        if($action==='apply'){
            $root=$this->refunds->locateRoot($connection,$pdo,MarketplaceOrderRefundInput::id($locator));
        }else{
            $number=self::text($locator,50);
            if($action==='express'&&$body['refund_no']!==$number) self::unavailable();
            $target=$this->refunds->locateCustomerRefund($connection,$pdo,$actualUid,$number);
            if($target===null) self::unavailable();
            $root=$this->refunds->locateRoot($connection,$pdo,self::id($target['marketplace_root_order_id']??null));
        }
        $this->outside($connection,$pdo); if($root===null) self::unavailable();
        $candidate=self::locator($root); if($candidate['uid']!==$actualUid) self::unavailable();
        return $this->run($connection,$pdo,$request,$identity,$candidate,$target,$action,$body,null,null);
    }

    public function forMerchant(Request $request,MerchantContext $context,string $action,mixed $refundId,array $raw): array
    {
        $identity=$this->merchant($request,$context); self::handlerCommand($action);
        $this->employees->authorizeAction($context,'aftersale.handle',false);
        $body=$this->wire($request,$action,$raw); [$connection,$pdo]=$this->defaultConnection();
        $target=$this->refunds->locateRefund($connection,$pdo,MarketplaceOrderRefundInput::id($refundId));
        if($target===null||self::integer($target['mer_id']??null,1,self::UINT_MAX)!==$context->merId) self::unavailable();
        $root=$this->refunds->locateRoot($connection,$pdo,self::id($target['marketplace_root_order_id']??null));
        $this->outside($connection,$pdo); if($root===null) self::unavailable(); $candidate=self::locator($root);
        if($candidate['mer_id']!==$context->merId) self::unavailable();
        return $this->run($connection,$pdo,$request,$identity,$candidate,$target,$action,$body,$context,null);
    }

    public function forPlatform(Request $request,PlatformScope $scope,string $action,mixed $refundId,array $raw): array
    {
        self::handlerCommand($action); $permission=self::permission($action); $identity=$this->administrator($request,$scope,$permission);
        $this->platform->authorizeScope($scope,$permission);
        $body=$this->wire($request,$action,$raw); [$connection,$pdo]=$this->defaultConnection();
        $target=$this->refunds->locateRefund($connection,$pdo,MarketplaceOrderRefundInput::id($refundId));
        if($target===null) self::unavailable();
        $root=$this->refunds->locateRoot($connection,$pdo,self::id($target['marketplace_root_order_id']??null));
        $this->outside($connection,$pdo); if($root===null) self::unavailable();
        return $this->run($connection,$pdo,$request,$identity,self::locator($root),$target,$action,$body,null,$scope);
    }

    private function run(ConnectionInterface $connection,PDO $pdo,Request $request,array $identity,array $candidate,?array $locatedRefund,
        string $action,array $body,?MerchantContext $merchant,?PlatformScope $scope): array
    {
        $phase='before_transaction'; $refundId=$locatedRefund===null?null:self::id($locatedRefund['id']??null); $actionId=null;
        try{
            $this->outside($connection,$pdo); $this->sameIdentity($request,$identity,$action,$merchant,$scope);
            $result=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$identity,$candidate,
                $locatedRefund,$action,$body,$merchant,$scope,&$phase,&$refundId,&$actionId):array{
                $this->inside($actual,$connection,$pdo); $this->sameIdentity($request,$identity,$action,$merchant,$scope);
                $phase='payment';
                $payment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);
                $this->writer($connection,$pdo);
                $root=$this->family->lockRoot($pdo,$candidate); $this->writer($connection,$pdo);
                $this->authorize($connection,$pdo,$request,$identity,$action,$root,$merchant,$scope);
                $phase='complete_prefix';
                $before=$this->capture($connection,$pdo,$candidate,$root,$payment);
                $inspection=$before['inspection']; $lockedRefund=null;
                if($refundId!==null){
                    $lockedRefund=self::refundRow($before['family']['refunds'],$refundId);
                    if($lockedRefund===null||$locatedRefund===null) self::unavailable();
                    foreach(['id','store_order_id','uid','mer_id','order_id','marketplace_root_order_id'] as $key)
                        if(($lockedRefund[$key]??null)!==($locatedRefund[$key]??null)) self::unavailable();
                }
                $filesBefore=[];
                foreach($before['family']['refunds'] as $row){
                    $filesBefore[self::id($row['id'])]=$this->files->readClaimedForReplay($connection,$pdo,$root,$row);
                    $this->writer($connection,$pdo);
                }
                $actorRef=$identity['actor_type'].':'.$identity['actor_id']; $phase='committed_replay';
                $saved=self::savedAction($inspection['actions'],$actorRef,$body['idem_key']);
                if($saved!==null){
                    if($saved['action']!==$action||($refundId!==null&&$saved['refund_id']!==$refundId)) self::unavailable();
                    $expected=['schema'=>1,'action'=>$action,'root_order_id'=>$candidate['root_order_id'],
                        'refund_id'=>$saved['refund_id'],'public_body'=>$body];
                    if(self::canonical($saved['request_payload'])!==self::canonical($expected)
                        ||!hash_equals($saved['request_digest'],hash('sha256',self::canonical(['actor_ref'=>$actorRef,'request_payload'=>$expected]),true))) self::unavailable();
                    if(self::refundRow($before['family']['refunds'],$saved['refund_id'])===null) self::unavailable();
                    $refundId=$saved['refund_id']; $actionId=$saved['id'];
                    $this->sameIdentity($request,$identity,$action,$merchant,$scope); $this->writer($connection,$pdo);
                    $phase='commit_outcome_unknown'; return self::tree($saved['result_payload']['public_result']);
                }
                // An observed foreign/same-root binding cannot be turned into a second action or another root lock.
                $observed=$this->refunds->findCommittedActionByActorKey($connection,$pdo,$actorRef,$body['idem_key']);
                $this->writer($connection,$pdo); if($observed!==null) self::unavailable();
                $phase='new_action_admission'; self::paid($candidate,$root,$payment); self::normalRoot($root); self::holdClear($payment['registry']);
                if($action==='apply'&&Config::get('marketplace.aftersale.enabled',false)!==true) self::unavailable();
                $this->sameIdentity($request,$identity,$action,$merchant,$scope);
                $proposal=MarketplaceOrderRefundFacts::prepareAction($before['capture'],$before['context'],$payment,$action,$refundId,$body);
                $this->writer($connection,$pdo); self::reserveBudget($before['capture'],$proposal['inventory_plan']);
                $staged=[];
                if($action==='apply'){
                    $staged=$this->files->lockForApply($connection,$pdo,$root,$identity['actor_id'],$body['images']);
                    $this->writer($connection,$pdo);
                }
                $inventory=null;
                if($proposal['inventory_plan']!==[]){
                    $inventory=$this->refunds->lockCreditInventory($connection,$pdo,$root,$proposal['inventory_plan']);
                    $this->writer($connection,$pdo);
                }
                // No business INSERT has occurred before the complete plan, budget, files and inventory gates above.
                $createdAt=self::nowSigned(); $phase='insert_refund';
                if($action==='apply'){
                    $number=self::text($this->numbers->getNewOrderId(''),50); $this->writer($connection,$pdo);
                    $data=self::initialRefund($proposal,$number,$createdAt);
                    $lockedRefund=$this->refunds->insertRefund($connection,$pdo,$data); $this->writer($connection,$pdo);
                    self::refundNative($lockedRefund); self::refundStored($lockedRefund,$data); $refundId=self::id($lockedRefund['id']);
                }
                if($lockedRefund===null||$refundId===null) self::unavailable();
                $phase='insert_action';
                $data=self::actionData($proposal,$action,$identity,$actorRef,$body,$refundId,$createdAt);
                $pending=$this->refunds->insertAction($connection,$pdo,$data); $this->writer($connection,$pdo);
                self::pendingStored($pending,$data); $actionId=self::big($pending['id']);
                $written=[]; $phase='actual_credits';
                if($proposal['physical']!==[]){
                    if($inventory===null) self::unavailable();
                    $written=$this->refunds->writeCredits($connection,$pdo,$pending,$inventory); $this->writer($connection,$pdo);
                }
                $movementRefs=self::movementReferences($proposal,$pending,$written);
                $view=self::refundView($proposal,$lockedRefund,$action,$identity,$createdAt);
                $mirror=self::mirror($proposal,$lockedRefund,$pending,$view,$action,$createdAt);
                $phase='actual_mirror';
                $afterRefund=$this->refunds->updateRefundMirror($connection,$pdo,$lockedRefund,$mirror); $this->writer($connection,$pdo);
                self::refundNative($afterRefund); self::refundStored($afterRefund,$mirror);
                $expectedFiles=$filesBefore;
                if($action==='apply'){
                    // FileDao must fresh-read the real complete mirror, never the eight-NULL bootstrap or a manufactured row.
                    $expectedFiles[$refundId]=$this->files->claimForApply($connection,$pdo,$root,$afterRefund,$staged);
                    $this->writer($connection,$pdo);
                }
                $phase='actual_status_and_audit'; $physical=self::physicalAfter($proposal,$action);
                $summary=self::summary($proposal['snapshot']['source'],$physical);
                $expectedChildren=[];
                foreach($before['family']['children'] as $child){
                    $id=self::id($child['id']); $status=self::childStatus($id,$physical);
                    $expectedChildren[$id]=$this->family->status($pdo,$child,$status); $this->writer($connection,$pdo);
                }
                $newStatus=self::rootStatus($summary); $oldStatus=self::integer($root['status'],0,2);
                $afterRoot=$this->family->status($pdo,$root,$newStatus); $this->writer($connection,$pdo);
                if($newStatus!==$oldStatus){
                    $this->refunds->writeAftersaleStatusLog($connection,$pdo,$afterRoot,$createdAt); $this->writer($connection,$pdo);
                }
                if($identity['actor_type']!=='customer'){
                    $auditActor=$identity['actor_type']==='employee'?'merchant':'admin';
                    $auditAction=$scope===null?'aftersale.'.$action:self::permission($action);
                    $changedFields=[]; foreach($lockedRefund as $key=>$value) if($afterRefund[$key]!==$value) $changedFields[]=$key;
                    $this->audit->record($auditActor,$identity['actor_id'],$candidate['mer_id'],$auditAction,'aftersale',(string)$refundId,
                        'success',['changed_fields'=>$changedFields]);
                    $this->writer($connection,$pdo);
                }
                $committedAt=self::nowUnsigned(); if($committedAt<$createdAt) self::unavailable();
                $acceptance=[];
                if($action==='accept_return') foreach($movementRefs as $ref)
                    if(!in_array($ref['movement_id'],$acceptance,true)) $acceptance[]=$ref['movement_id'];
                $public=['schema'=>2,'scope'=>'marketplace_aftersale_action_v1','action_id'=>$actionId,'action'=>$action,
                    'refund'=>$view,'committed_at'=>$committedAt,'acceptance_ids'=>$acceptance];
                $internal=['schema'=>1,'before'=>$proposal['before'],'after'=>$proposal['after'],'allocations'=>$proposal['allocations'],
                    'movements'=>$movementRefs,'public_result'=>$public];
                if(strlen(self::canonical($internal))>1048576) self::unavailable();
                $phase='finish_actual_action';
                $finished=$this->refunds->finishAction($connection,$pdo,$pending,$internal,$committedAt); $this->writer($connection,$pdo);
                self::finishedStored($finished,$pending,$internal,$committedAt);
                // Every input below is a new native read, including the real state1 row. No proposal is a committed context.
                $phase='final_payment';
                $finalPayment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);
                $this->writer($connection,$pdo);
                $finalRoot=$this->family->lockRoot($pdo,$candidate); $this->writer($connection,$pdo);
                $finalOperations=$this->refunds->lockOperationsForRoot($connection,$pdo,$finalRoot); $this->writer($connection,$pdo);
                $finalCapture=$this->orders->capturePaymentApplicationFacts($pdo,$candidate['uid'],$candidate['root_order_id']);
                $this->writer($connection,$pdo);
                $finalFamily=$this->family->lockFamily($pdo,$finalRoot); $this->writer($connection,$pdo);
                $finalLogs=$this->refunds->lockStatusLogsForRoot($connection,$pdo,$finalRoot); $this->writer($connection,$pdo);
                $finalActions=$this->refunds->lockActionsForRoot($connection,$pdo,$finalRoot); $this->writer($connection,$pdo);
                $finalContext=self::context($finalActions,$finalFamily['refunds'],$finalOperations,$finalFamily['ranges'],$finalLogs);
                $finalInspection=MarketplaceOrderRefundFacts::inspectForAftersale($finalCapture,$finalContext,$finalPayment);
                $this->fulfillment->validateAftersaleFamilyFromFacts($finalRoot,$finalCapture,$finalFamily,$finalInspection);
                $this->writer($connection,$pdo);
                self::preserved($before,$payment,$root,$afterRoot,$expectedChildren,$afterRefund,$finished,$written,$summary,
                    $newStatus!==$oldStatus,$createdAt,$finalPayment,$finalRoot,$finalCapture,$finalFamily,$finalOperations,$finalLogs,$finalActions,$finalInspection);
                $seenFiles=[];
                foreach($finalFamily['refunds'] as $row){
                    $id=self::id($row['id']); if(!array_key_exists($id,$expectedFiles)) self::unavailable();
                    $actualFiles=$this->files->readFinalClaimed($connection,$pdo,$finalRoot,$row); $this->writer($connection,$pdo);
                    if($actualFiles!==$expectedFiles[$id]) self::unavailable(); $seenFiles[$id]=true;
                }
                if(count($seenFiles)!==count($expectedFiles)) self::unavailable();
                $committed=self::savedAction($finalInspection['actions'],$actorRef,$body['idem_key']);
                if($committed===null||$committed['id']!==$actionId) self::unavailable();
                $this->sameIdentity($request,$identity,$action,$merchant,$scope); $this->inside($actual,$connection,$pdo);
                $phase='commit_outcome_unknown'; return self::tree($committed['result_payload']['public_result']);
            });
            $phase='post_commit_boundary'; $this->outside($connection,$pdo);
            $this->sameIdentity($request,$identity,$action,$merchant,$scope); return $result;
        }catch(Throwable $error){
            self::outcomeUnproved($phase,$candidate['root_order_id'],$refundId,$actionId); self::unavailable();
        }
    }

    /** The caller already holds payment/root/current authorization. Every collection is complete and unpaged. */
    private function capture(ConnectionInterface $connection,PDO $pdo,array $candidate,array $root,array $payment): array
    {
        $operations=$this->refunds->lockOperationsForRoot($connection,$pdo,$root); $this->writer($connection,$pdo);
        $capture=$this->orders->capturePaymentApplicationFacts($pdo,$candidate['uid'],$candidate['root_order_id']); $this->writer($connection,$pdo);
        $family=$this->family->lockFamily($pdo,$root); $this->writer($connection,$pdo);
        $logs=$this->refunds->lockStatusLogsForRoot($connection,$pdo,$root); $this->writer($connection,$pdo);
        $actions=$this->refunds->lockActionsForRoot($connection,$pdo,$root); $this->writer($connection,$pdo);
        if($capture['root']!==$root) self::unavailable();
        $context=self::context($actions,$family['refunds'],$operations,$family['ranges'],$logs);
        $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$payment);
        // Required S2 integration leaf: the credit context alone does not validate complete child headers/items.
        $this->fulfillment->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection); $this->writer($connection,$pdo);
        return ['capture'=>$capture,'family'=>$family,'operations'=>$operations,'logs'=>$logs,'actions'=>$actions,
            'context'=>$context,'inspection'=>$inspection];
    }

    private function authorize(ConnectionInterface $connection,PDO $pdo,Request $request,array $identity,string $action,array $root,
        ?MerchantContext $merchant,?PlatformScope $scope): void
    {
        $this->sameIdentity($request,$identity,$action,$merchant,$scope); $this->writer($connection,$pdo);
        if($identity['actor_type']==='customer'){
            if($identity['actor_id']!==self::id($root['uid'])) self::unavailable();
            $this->family->lockCustomer($pdo,$identity['actor_id']);
        }elseif($identity['actor_type']==='employee'){
            if($merchant===null||$merchant->merId!==self::integer($root['mer_id'],1,self::UINT_MAX)) self::unavailable();
            $this->employees->authorizeAction($merchant,'aftersale.handle',true);
        }elseif($identity['actor_type']==='admin'){
            if($scope===null) self::unavailable(); $this->platform->authorizeScope($scope,self::permission($action));
        }else self::unavailable();
        $this->writer($connection,$pdo); $this->sameIdentity($request,$identity,$action,$merchant,$scope);
    }

    private function wire(Request $request,string $action,array $raw): array
    {
        if($request->method(true)!=='POST'||strtolower($request->contentType())!=='application/json'
            ||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='') self::unavailable();
        $original=$request->getContent(); if(!is_string($original)) self::unavailable();
        $fromBytes=self::body($action,MarketplaceOrderRefundInput::decode($original)); $fromCaller=self::body($action,$raw);
        if(self::canonical($fromBytes)!==self::canonical($fromCaller)) self::unavailable(); return $fromBytes;
    }

    private static function body(string $action,array $raw): array
    {
        return match($action){'apply'=>MarketplaceOrderRefundInput::apply($raw),'withdraw'=>MarketplaceOrderRefundInput::withdraw($raw),
            'approve'=>MarketplaceOrderRefundInput::approve($raw),'reject'=>MarketplaceOrderRefundInput::reject($raw),
            'express'=>MarketplaceOrderRefundInput::express($raw),'accept_return'=>MarketplaceOrderRefundInput::acceptReturn($raw),
            default=>self::unavailable()};
    }

    private static function context(array $actions,array $refunds,array $operations,array $ranges,array $logs): array
    {
        foreach([$actions,$refunds,$operations,$ranges,$logs] as $rows) if(!array_is_list($rows)) self::unavailable();
        $cut=$actions===[]?null:self::big($actions[count($actions)-1]['id']);
        return ['schema'=>2,'cut_id'=>$cut,'actions'=>$actions,'refunds'=>$refunds,'operations'=>$operations,'ranges'=>$ranges,'logs'=>$logs];
    }

    private static function reserveBudget(array $capture,array $plan): void
    {
        $reserves=[]; $used=[];
        foreach($capture['reserves'] as $row){$id=self::big($row['id']);$reserves[$id]=self::integer($row['qty'],1,self::UINT_MAX);$used[$id]=0;}
        foreach($capture['credit_rows'] as $row){
            $id=self::big($row['parent_reserve_id']); if(!isset($reserves[$id])) self::unavailable();
            $used[$id]+=self::integer($row['qty'],1,self::UINT_MAX); if($used[$id]>$reserves[$id]) self::unavailable();
        }
        foreach($plan as $item){
            $id=self::big($item['reserve_id']); if(!isset($reserves[$id])) self::unavailable();
            $quantity=self::integer($item['quantity'],1,self::INT_MAX);
            if($quantity>$reserves[$id]-$used[$id]) self::unavailable(); $used[$id]+=$quantity;
        }
    }

    private static function initialRefund(array $proposal,string $number,int $createdAt): array
    {
        $source=$proposal['snapshot']['source']; $after=$proposal['after']; $body=$proposal['body'];
        $items=self::publicItems($source,$proposal['allocations'],[]); $cart=[]; foreach($items as $item) $cart[]=$item['item'];
        return ['store_order_id'=>$source['identity']['id'],'store_id'=>0,'order_id'=>$number,'uid'=>$source['identity']['uid'],
            'refund_type'=>$after['refund_type'],'refund_num'=>self::integer(self::quantity($proposal['allocations']),1,self::INT_MAX),
            'refund_price'=>$body['amount'],'refunded_price'=>'0.00','refund_phone'=>'','refund_express'=>'','refund_express_name'=>'',
            'refund_explain'=>'','refund_img'=>self::canonical($body['images']),'refund_reason'=>$body['reason'],'refuse_reason'=>'',
            'remark'=>'','refunded_time'=>0,'cart_info'=>self::canonical($cart),'is_cancel'=>0,'is_pink_cancel'=>0,'is_del'=>0,
            'add_time'=>$createdAt,'is_system_del'=>0,'mer_id'=>$source['identity']['mer_id'],
            'marketplace_refund_mode'=>$proposal['operation_kind']==='return_goods'?'return_goods':'money_only',
            'marketplace_root_order_id'=>null,'marketplace_payment_attempt_id'=>null,'marketplace_kind'=>null,'marketplace_state'=>null,
            'marketplace_physical_state'=>null,'marketplace_finance_state'=>null,'marketplace_apply_action_id'=>null,'marketplace_last_action_id'=>null];
    }

    private static function actionData(array $proposal,string $action,array $identity,string $actorRef,array $body,int $refundId,int $createdAt): array
    {
        $snapshot=$proposal['snapshot']; $source=$snapshot['source'];
        $previous=$action==='apply'?null:$snapshot['history']['refunds'][$refundId]['last']['id'];
        $request=['schema'=>1,'action'=>$action,'root_order_id'=>$source['identity']['id'],'refund_id'=>$refundId,'public_body'=>$body];
        return ['contract_version'=>1,'root_order_id'=>$source['identity']['id'],'uid'=>$source['identity']['uid'],
            'mer_id'=>$source['identity']['mer_id'],'refund_id'=>$refundId,'payment_attempt_id'=>$snapshot['winner'],'action'=>$action,
            'actor_type'=>$identity['actor_type'],'actor_id'=>$identity['actor_id'],'actor_ref'=>$actorRef,'idem_key'=>$body['idem_key'],
            'request_digest'=>hash('sha256',self::canonical(['actor_ref'=>$actorRef,'request_payload'=>$request]),true),
            'request_payload'=>$request,'result_payload'=>null,'state'=>0,'previous_refund_action_id'=>$previous,
            'fulfillment_cut_id'=>$snapshot['last_operation_id'],'apply_refund_id'=>$action==='apply'?$refundId:null,
            'created_at'=>$createdAt,'committed_at'=>null];
    }

    private static function movementReferences(array $proposal,array $pending,array $rows): array
    {
        if(!array_is_list($rows)) self::unavailable(); $map=[]; $ids=[];
        foreach($rows as $row){
            if(!is_array($row)||count($row)!==25) self::unavailable();
            $sourceId=self::id($row['order_item_id']??null); $id=self::big($row['id']??null);
            if(isset($map[$sourceId])||isset($ids['m:'.$id])) self::unavailable();
            $map[$sourceId]=$row; $ids['m:'.$id]=true;
        }
        if(count($map)!==count($proposal['inventory_plan'])) self::unavailable();
        $kind=$pending['action']==='approve'&&$proposal['operation_kind']==='cancel_unshipped'?'release':
            ($pending['action']==='accept_return'?'return_in':null);
        if($kind===null){if($rows!==[]||$proposal['physical']!==[]) self::unavailable();return [];}
        foreach($proposal['inventory_plan'] as $item){
            $row=$map[$item['source_item_id']]??null; if($row===null) self::unavailable();
            self::stored($row,['order_id'=>$proposal['snapshot']['source']['identity']['id'],
                'mer_id'=>$proposal['snapshot']['source']['identity']['mer_id'],'refund_id'=>self::id($pending['refund_id']),
                'order_item_id'=>$item['source_item_id'],'product_id'=>$item['product_id'],'sku_id'=>$item['sku_id'],
                'sku_type'=>0,'sku_unique'=>$item['sku_unique'],'kind'=>$kind,'qty'=>$item['quantity'],
                'actor_type'=>$pending['actor_type'],'actor_id'=>self::integer($pending['actor_id'],1,self::UINT_MAX)]);
            if(self::big($row['parent_reserve_id'])!==$item['reserve_id']) self::unavailable();
        }
        $out=[];
        foreach($proposal['physical'] as $part){
            $row=$map[$part['source_item_id']]??null; if($row===null) self::unavailable();
            $out[]=['movement_id'=>self::big($row['id']),'kind'=>$kind,'source_item_id'=>$part['source_item_id'],
                'reserve_id'=>$part['reserve_id'],'unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end'],
                'quantity'=>$part['unit_end']-$part['unit_start']];
        }
        return $out;
    }

    private static function refundView(array $proposal,array $nativeRefund,string $action,array $identity,int $createdAt): array
    {
        $snapshot=$proposal['snapshot']; $source=$snapshot['source']; $id=self::id($nativeRefund['id']);
        if($action==='apply'){$body=$proposal['body'];$firstAt=$createdAt;$accepted=[];}
        else{
            $history=$snapshot['history']['refunds'][$id]; $body=$history['apply']['request_payload']['public_body'];
            $firstAt=$history['apply']['created_at']; $accepted=$history['accepted'];
        }
        if($action==='accept_return') $accepted=array_merge($accepted,$proposal['physical']);
        $after=$proposal['after']; $kind=$proposal['operation_kind']; $customer=$identity['actor_type']==='customer';
        $handler=in_array($identity['actor_type'],['employee','admin'],true); $requested=$after['business_state']==='REQUESTED';
        $approvedReturn=$after['business_state']==='APPROVED'&&$kind==='return_goods';
        $view=['schema'=>2,'scope'=>'marketplace_aftersale_v1','id'=>$id,'refund_no'=>self::text($nativeRefund['order_id'],50),
            'root_order_id'=>$source['identity']['id'],'root_order_no'=>$source['identity']['order_no'],
            'merchant_name_snapshot'=>$source['root']['merchant_name_snapshot'],'operation_kind'=>$kind,
            'mode'=>$kind==='return_goods'?'return_goods':'money_only','business_state'=>$after['business_state'],
            'physical_state'=>$after['physical_state'],'finance_state'=>$after['finance_state'],'requested_amount'=>$after['requested_amount'],
            'confirmed_amount'=>$after['confirmed_amount'],'held_amount'=>$after['held_amount'],'created_at'=>$firstAt,'updated_at'=>$createdAt,
            'reason'=>$body['reason'],'images'=>$body['images'],'return_delivery'=>$after['return_delivery'],
            'items'=>self::publicItems($source,$proposal['allocations'],$accepted),
            'allowed_actions'=>['withdraw'=>$customer&&$requested,'submit_return'=>$customer&&$approvedReturn,
                'approve'=>$handler&&$requested,'reject'=>$handler&&$requested,
                'accept_return'=>$handler&&$approvedReturn&&self::quantity($accepted)<self::quantity($proposal['allocations'])],
            'return_selection'=>self::returnSelection($snapshot,$body,$kind,$after,$accepted)];
        $destination=null;
        if($action==='approve'&&$kind==='return_goods'){
            $destination=MarketplaceOrderRefundInput::returnDestination($proposal['body']['return_destination']);
        }elseif($action!=='apply'){
            $destination=MarketplaceOrderRefundFacts::returnDestinationFromRefund($history);
        }
        if($destination!==null) $view['return_destination']=$destination;
        return $view;
    }

    /** Projection only: saved allocation goods/postage have already been independently checked by prepareAction. */
    private static function publicItems(array $source,array $allocations,array $accepted): array
    {
        $items=[];
        foreach($allocations as $part){
            $id=$part['source_item_id'];
            if(!isset($items[$id])){
                $item=$source['commercial']['public_items'][$id]; $item['quantity']=0; $item['line_price']='0.00'; $item['postage_price']='0.00';
                $items[$id]=['item'=>$item,'selected_quantity'=>0,'accepted_quantity'=>0,'confirmed_amount'=>'0.00'];
            }
            $items[$id]['item']['quantity']+=$part['quantity']; $items[$id]['selected_quantity']+=$part['quantity'];
            $items[$id]['item']['line_price']=bcadd($items[$id]['item']['line_price'],$part['goods'],2);
            $items[$id]['item']['postage_price']=bcadd($items[$id]['item']['postage_price'],$part['postage'],2);
            $items[$id]['confirmed_amount']=bcadd($items[$id]['confirmed_amount'],$part['confirmed_amount'],2);
        }
        foreach($accepted as $part){
            if(!isset($items[$part['source_item_id']])) self::unavailable();
            $items[$part['source_item_id']]['accepted_quantity']+=$part['unit_end']-$part['unit_start'];
        }
        ksort($items,SORT_NUMERIC); return array_values($items);
    }

    private static function returnSelection(array $snapshot,array $body,string $kind,array $after,array $accepted): ?array
    {
        if($kind!=='return_goods') return null; $selection=$body['selection']; $source=$snapshot['source']; $child=null;
        foreach($source['child_orders'] as $id=>$row) if($row['order_id']===$selection['child_order_no']){
            if($child!==null) self::unavailable(); $child=$id;
        }
        if($child===null) self::unavailable(); $ship=$snapshot['fulfillment']['ships'][$child]; $groups=[];
        foreach($snapshot['fulfillment']['ranges'] as $range) if($range['operation_action']==='ship'&&$range['operation_id']===$ship['id'])
            $groups[$range['child_item_id']][]=$range;
        $items=[];
        foreach($selection['items'] as $item){
            $parents=$groups[$item['item_id']]??null; if($parents===null) self::unavailable();
            usort($parents,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']); $quantity=0; $done=[];
            foreach($parents as $parent){
                foreach($accepted as $part) if($part['child_item_id']===$item['item_id']&&$part['source_ship_range_id']===$parent['id']){
                    if($part['unit_start']<$parent['unit_start']||$part['unit_end']>$parent['unit_end']) self::unavailable();
                    $done[]=['first'=>$quantity+$part['unit_start']-$parent['unit_start']+1,
                        'last'=>$quantity+$part['unit_end']-$parent['unit_start']];
                }
                $quantity+=$parent['quantity'];
            }
            usort($done,static fn(array $a,array $b):int=>($a['first']<=>$b['first'])?:($a['last']<=>$b['last'])); $eligible=[];
            if($after['business_state']==='APPROVED') foreach($item['segments'] as $segment){
                $cursor=$segment['first'];
                foreach($done as $part){
                    if($part['last']<$cursor||$part['first']>$segment['last']) continue;
                    if($part['first']>$cursor) $eligible[]=['first'=>$cursor,'last'=>min($segment['last'],$part['first']-1)];
                    $cursor=max($cursor,$part['last']+1); if($cursor>$segment['last']) break;
                }
                if($cursor<=$segment['last']) $eligible[]=['first'=>$cursor,'last'=>$segment['last']];
            }
            $items[]=['item_id'=>$item['item_id'],'quantity'=>$quantity,'selected_quantity'=>self::localQuantity($item['segments']),
                'selected_segments'=>$item['segments'],'accepted_quantity'=>self::localQuantity($done),'accepted_segments'=>$done,
                'eligible_quantity'=>self::localQuantity($eligible),'eligible_segments'=>$eligible];
        }
        return ['schema'=>1,'coordinate'=>'child_item_ordinal','child_order_no'=>$selection['child_order_no'],'items'=>$items];
    }

    private static function mirror(array $proposal,array $before,array $pending,array $view,string $action,int $createdAt): array
    {
        $after=$before; $state=$proposal['after']; $source=$proposal['snapshot']['source']; $id=self::id($before['id']);
        $applyId=$action==='apply'?self::big($pending['id']):$proposal['snapshot']['history']['refunds'][$id]['apply']['id'];
        $delivery=$state['return_delivery'];
        $values=['refund_type'=>$state['refund_type'],'refunded_price'=>$state['confirmed_amount'],
            'refund_express'=>$delivery===null?'':$delivery['tracking_no'],'refund_express_name'=>$delivery===null?'':$delivery['carrier_name'],
            'refunded_time'=>$action==='apply'?0:$createdAt,'is_cancel'=>$state['is_cancel'],
            'marketplace_root_order_id'=>$source['identity']['id'],'marketplace_payment_attempt_id'=>$proposal['snapshot']['winner'],
            'marketplace_kind'=>$proposal['operation_kind'],'marketplace_state'=>$state['business_state'],
            'marketplace_physical_state'=>$state['physical_state'],'marketplace_finance_state'=>$state['finance_state'],
            'marketplace_apply_action_id'=>$applyId,'marketplace_last_action_id'=>self::big($pending['id'])];
        if($action==='reject') $values['refuse_reason']=$proposal['body']['reason'];
        foreach($values as $key=>$value) $after[$key]=$value;
        if($view['refund_no']!==$after['order_id']||$view['id']!==$id) self::unavailable(); return $after;
    }

    private static function physicalAfter(array $proposal,string $action): array
    {
        $history=$proposal['snapshot']['history'];
        $out=['ships'=>$history['ships'],'received'=>$history['received'],'cancel'=>$history['cancel'],'returned'=>$history['returned']];
        if($action==='approve'&&$proposal['operation_kind']==='cancel_unshipped') $out['cancel']=array_merge($out['cancel'],$proposal['physical']);
        if($action==='accept_return') $out['returned']=array_merge($out['returned'],$proposal['physical']); return $out;
    }

    private static function summary(array $source,array $physical): array
    {
        $original=self::integer($source['root']['total_num'],1,self::UINT_MAX); $s=self::quantity($physical['ships']);
        $r=self::quantity($physical['received']); $c=self::quantity($physical['cancel']); $t=self::quantity($physical['returned']);
        $intersection=self::intersection($physical['received'],$physical['returned']); $toShip=$original-$s-$c; $toReceive=$s-$r-$t+$intersection;
        if($toShip<0||$toReceive<0) self::unavailable(); $children=[];
        foreach($physical['ships'] as $part) $children[$part['child_order_id']]=true;
        return ['shipping_state'=>$s===0?'unshipped':($toShip>0?'partial':'shipped'),
            'receipt_state'=>$s===0?'not_shipped':($r===$s?'received':($r===0?'pending':'partial')),
            'closed'=>$toShip===0&&$toReceive===0,'original'=>$original,'shipped'=>$s,'received'=>$r,'cancelled_unshipped'=>$c,
            'returned_accepted'=>$t,'to_ship'=>$toShip,'to_receive'=>$toReceive,'child_count'=>count($children)];
    }

    private static function childStatus(int $child,array $physical): int
    {
        $sets=[];
        foreach(['ships','received','returned'] as $key){
            $sets[$key]=[]; foreach($physical[$key] as $part) if($part['child_order_id']===$child) $sets[$key][]=$part;
        }
        $s=self::quantity($sets['ships']); if($s<1) self::unavailable();
        $covered=self::quantity($sets['received'])+self::quantity($sets['returned'])-self::intersection($sets['received'],$sets['returned']);
        if($covered<0||$covered>$s) self::unavailable(); return $covered===$s?2:1;
    }

    private static function rootStatus(array $summary): int
    {
        return $summary['to_ship']>0?0:($summary['to_receive']>0?1:($summary['shipped']>0?2:0));
    }

    /** Permit only this actual action's mutations; complete validators above remain independently mandatory. */
    private static function preserved(array $before,array $payment,array $root,array $afterRoot,array $children,array $refund,array $finished,
        array $written,array $summary,bool $logged,int $createdAt,array $finalPayment,array $finalRoot,array $finalCapture,array $finalFamily,
        array $finalOperations,array $finalLogs,array $finalActions,array $inspection): void
    {
        $expectedPayment=$payment; $status=self::integer($afterRoot['status'],0,2);
        $expectedPayment['root']['status']=is_int($payment['root']['status'])?$status:(string)$status;
        if($finalPayment!==$expectedPayment||$finalRoot!==$afterRoot||$finalCapture['root']!==$finalRoot
            ||$finalOperations!==$before['operations']||$inspection['summary']!==$summary) self::unavailable();
        $expectedRoot=$root; $expectedRoot['status']=is_int($root['status'])?$status:(string)$status;
        if($afterRoot!==$expectedRoot||self::integer($finalRoot['is_cancel'],0,0)!==0) self::unavailable();
        foreach(['items','selection','reserves'] as $key) if($finalCapture[$key]!==$before['capture'][$key]) self::unavailable();
        self::sameRows($finalFamily['children'],array_values($children),'id',false);
        if($finalFamily['items']!==$before['family']['items']||$finalFamily['ranges']!==$before['family']['ranges']) self::unavailable();
        $headers=$before['capture']['child_orders'];
        foreach($headers as &$header){
            $id=self::id($header['id']); if(!isset($children[$id])) self::unavailable(); $next=self::integer($children[$id]['status'],0,2);
            $header['status']=is_int($header['status'])?$next:(string)$next;
        } unset($header);
        self::sameRows($finalCapture['child_orders'],$headers,'id',false);
        $expectedCredits=array_merge($before['capture']['credit_rows'],$written);
        self::sameRows($finalCapture['credit_rows'],$expectedCredits,'id',true);
        $expectedRefunds=[]; $found=false; $id=self::id($refund['id']);
        foreach($before['family']['refunds'] as $row){
            if(self::id($row['id'])===$id){$expectedRefunds[]=$refund;$found=true;}else $expectedRefunds[]=$row;
        }
        if(!$found) $expectedRefunds[]=$refund; self::sameRows($finalFamily['refunds'],$expectedRefunds,'id',false);
        self::sameRows($finalActions,array_merge($before['actions'],[$finished]),'id',false);
        $expectedLogs=$before['logs'];
        if($logged) $expectedLogs[]=['oid'=>self::id($root['id']),'change_type'=>'marketplace_aftersale',
            'change_message'=>'多商户售后状态更新','change_time'=>$createdAt];
        if(self::logMultiset($finalLogs)!==self::logMultiset($expectedLogs)) self::unavailable();
        $saved=self::savedAction($inspection['actions'],$finished['actor_ref'],$finished['idem_key']);
        if($saved===null||$saved['id']!==self::big($finished['id'])
            ||self::canonical($saved['result_payload'])!==self::canonical(self::jsonObject($finished['result_payload']))) self::unavailable();
    }

    private static function sameRows(array $actual,array $expected,string $id,bool $movement): void
    {
        if(!array_is_list($actual)||!array_is_list($expected)||count($actual)!==count($expected)) self::unavailable(); $map=[];
        foreach($expected as $row){$key='id:'.self::big($row[$id]);if(isset($map[$key])) self::unavailable();$map[$key]=$row;}
        foreach($actual as $row){
            $key='id:'.self::big($row[$id]); if(!isset($map[$key])) self::unavailable(); $prior=$map[$key];
            if($movement){
                foreach(['id','parent_reserve_id'] as $field){$row[$field]=self::big($row[$field]);$prior[$field]=self::big($prior[$field]);}
            }
            self::keys($row,array_keys($prior));
            foreach($prior as $field=>$value) if($row[$field]!==$value) self::unavailable();
            unset($map[$key]);
        }
        if($map!==[]) self::unavailable();
    }

    private static function logMultiset(array $rows): array
    {
        $out=[];
        foreach($rows as $row){
            self::keys($row,['oid','change_type','change_message','change_time']);
            $key=self::canonical(['oid'=>self::id($row['oid']),'change_type'=>self::characters($row['change_type'],32),
                'change_message'=>self::characters($row['change_message'],256,true),'change_time'=>self::integer($row['change_time'],0,self::UINT_MAX)]);
            $out[$key]=($out[$key]??0)+1;
        }
        ksort($out,SORT_STRING); return $out;
    }

    private static function pendingStored(array $pending,array $data): void
    {
        self::keys($pending,self::ACTION_FIELDS); self::big($pending['id']); $expected=$data; unset($expected['request_payload']);
        foreach(['payment_attempt_id','previous_refund_action_id','fulfillment_cut_id'] as $key)
            if($pending[$key]!==null) $pending[$key]=self::big($pending[$key]);
        self::stored($pending,$expected);
        if(self::canonical(self::jsonObject($pending['request_payload']))!==self::canonical($data['request_payload'])) self::unavailable();
    }

    private static function finishedStored(array $finished,array $pending,array $internal,int $at): void
    {
        self::keys($finished,self::ACTION_FIELDS); self::keys($pending,self::ACTION_FIELDS);
        foreach($pending as $key=>$value){
            if(in_array($key,['state','result_payload','committed_at'],true)) continue;
            if($finished[$key]!==$value) self::unavailable();
        }
        self::stored($finished,['state'=>1,'committed_at'=>$at]);
        if(self::canonical(self::jsonObject($finished['result_payload']))!==self::canonical($internal)) self::unavailable();
    }

    private static function refundNative(array $row): void {self::keys($row,self::REFUND_FIELDS);self::id($row['id']);}
    private static function refundStored(array $row,array $expected): void
    {
        foreach(['marketplace_payment_attempt_id','marketplace_apply_action_id','marketplace_last_action_id'] as $key){
            if(array_key_exists($key,$row)&&$row[$key]!==null) $row[$key]=self::big($row[$key]);
            if(array_key_exists($key,$expected)&&$expected[$key]!==null) $expected[$key]=self::big($expected[$key]);
        }
        self::stored($row,$expected);
    }
    private static function refundRow(array $rows,int $id): ?array
    {
        $found=null;
        foreach($rows as $row) if(self::id($row['id'])===$id){if($found!==null) self::unavailable();self::refundNative($row);$found=$row;}
        return $found;
    }
    private static function savedAction(array $actions,string $actorRef,string $key): ?array
    {
        $found=null; foreach($actions as $row) if($row['actor_ref']===$actorRef&&$row['idem_key']===$key){
            if($found!==null) self::unavailable(); $found=$row;
        }return $found;
    }

    private static function paid(array $candidate,array $root,array $payment): void
    {
        foreach($payment['root'] as $key=>$value) self::stored($root,[$key=>$value]);
        if($payment['root']['paid']!==1||$payment['root']['id']!==$candidate['root_order_id']
            ||$payment['root']['uid']!==$candidate['uid']||$payment['root']['mer_id']!==$candidate['mer_id']) self::unavailable();
        $winner=self::big($payment['registry']['winner_attempt_id']??null); $found=0;
        foreach($payment['attempts'] as $attempt){
            $application=$attempt['evidence']['application']??null;
            if($application!==null){
                if($attempt['id']!==$winner||($application['order_pk']??null)!==$candidate['root_order_id']
                    ||($application['attempt_id']??null)!==$winner||!is_array($application['root_payment']??null)
                    ||self::canonical($application['root_payment'])!==self::canonical(['paid'=>1,'pay_time'=>$payment['root']['pay_time'],
                        'pay_type'=>$payment['root']['pay_type'],'trade_no'=>$payment['root']['trade_no']])) self::unavailable(); ++$found;
            }
        }
        if($found!==1) self::unavailable();
    }
    private static function normalRoot(array $root): void
    {
        foreach(self::ROOT_ZERO as $key) self::integer($root[$key]??null,0,0);
        self::integer($root['marketplace_trade_version']??null,1,1); self::integer($root['pid']??null,0,0);
        self::integer($root['shipping_type']??null,1,1); if(($root['refund_price']??null)!=='0.00') self::unavailable();
    }
    private static function holdClear(array $registry): void
    {
        if(($registry['fulfillment_hold']??null)!==0||($registry['hold_reason']??null)!==null
            ||($registry['hold_attempt_id']??null)!==null||($registry['hold_at']??null)!==null) self::unavailable();
    }
    private static function locator(array $root): array
    {
        self::integer($root['marketplace_trade_version']??null,1,1); self::integer($root['pid']??null,0,0);
        return ['root_order_id'=>self::id($root['id']??null),'root_order_no'=>self::text($root['order_id']??null,32),
            'uid'=>self::id($root['uid']??null),'mer_id'=>self::integer($root['mer_id']??null,1,self::UINT_MAX)];
    }

    private function current(Request $request,string $application): string
    {
        if(app()->request!==$request||app()->http->getName()!==$application) self::unavailable();
        $fingerprint=$request->verifiedTokenFingerprint(); if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1) self::unavailable(); return $fingerprint;
    }
    private function customer(Request $request,int $uid): array
    {
        $fingerprint=$this->current($request,'api'); self::id($uid);
        if($request->isLogin()!==true||self::id($request->uid())!==$uid) self::unavailable();
        return ['app'=>'api','actor_type'=>'customer','actor_id'=>$uid,'session_sha256'=>$fingerprint];
    }
    private function merchant(Request $request,MerchantContext $context): array
    {
        $fingerprint=$this->current($request,'merchantapi');
        if($request->merchantContext()!==$context) self::unavailable(); self::integer($context->employeeId,1,self::UINT_MAX);
        self::integer($context->merId,1,self::UINT_MAX); $context->require('aftersale.handle');
        return ['app'=>'merchantapi','actor_type'=>'employee','actor_id'=>$context->employeeId,'session_sha256'=>$fingerprint];
    }
    private function administrator(Request $request,PlatformScope $scope,string $permission): array
    {
        $fingerprint=$this->current($request,'adminapi');
        if($request->platformScope()!==$scope||$scope->permission!==$permission||$scope->targetMerId!==null
            ||$request->isAdminLogin()!==true||self::integer($request->adminId(),1,self::UINT_MAX)!==$scope->adminId) self::unavailable();
        return ['app'=>'adminapi','actor_type'=>'admin','actor_id'=>$scope->adminId,'session_sha256'=>$fingerprint];
    }
    private function sameIdentity(Request $request,array $identity,string $action,?MerchantContext $merchant,?PlatformScope $scope): void
    {
        $current=match($identity['app']){'api'=>$this->customer($request,$identity['actor_id']),
            'merchantapi'=>$merchant===null?self::unavailable():$this->merchant($request,$merchant),
            'adminapi'=>$scope===null?self::unavailable():$this->administrator($request,$scope,self::permission($action)),
            default=>self::unavailable()};
        if($current!==$identity) self::unavailable();
    }
    private static function handlerCommand(string $action): void
    {
        if(!in_array($action,['approve','reject','accept_return'],true)) self::unavailable();
    }
    private static function permission(string $action): string
    {
        return match($action){'approve'=>'marketplace-aftersales-approve','reject'=>'marketplace-aftersales-reject',
            'accept_return'=>'marketplace-aftersales-return-accept',default=>self::unavailable()};
    }

    private function defaultConnection(): array
    {
        $connection=Db::connect(); if(!$connection instanceof Mysql||$connection->transactionDepth()!==0) self::unavailable();
        $pdo=$connection->getPdo(); if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO) self::unavailable(); $this->outside($connection,$pdo);
        $this->family->assertAftersaleStructure($pdo); $this->outside($connection,$pdo); return [$connection,$pdo];
    }
    private function connection(ConnectionInterface $connection,PDO $pdo): void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)) self::unavailable();
    }
    private function outside(ConnectionInterface $connection,PDO $pdo): void
    {
        $this->connection($connection,$pdo); if($connection->transactionDepth()!==0||$pdo->inTransaction()) self::unavailable();
    }
    private function writer(ConnectionInterface $connection,PDO $pdo): void
    {
        $this->connection($connection,$pdo); if($connection->transactionDepth()!==1||!$pdo->inTransaction()) self::unavailable();
    }
    private function inside(ConnectionInterface $actual,ConnectionInterface $connection,PDO $pdo): void
    {
        if($actual!==$connection) self::unavailable(); $this->writer($connection,$pdo);
    }

    private static function quantity(array $parts): int
    {
        $quantity=0; foreach($parts as $part){$quantity+=$part['unit_end']-$part['unit_start'];self::integer($quantity,0,self::UINT_MAX);}return $quantity;
    }
    private static function localQuantity(array $segments): int
    {
        $quantity=0;foreach($segments as $part){$quantity+=$part['last']-$part['first']+1;self::integer($quantity,0,self::UINT_MAX);}return $quantity;
    }
    private static function intersection(array $left,array $right): int
    {
        $quantity=0;
        foreach($left as $a) foreach($right as $b) if($a['source_item_id']===$b['source_item_id'])
            $quantity+=max(0,min($a['unit_end'],$b['unit_end'])-max($a['unit_start'],$b['unit_start']));
        return self::integer($quantity,0,self::UINT_MAX);
    }
    private static function jsonObject(mixed $raw): array
    {
        if(!is_string($raw)||$raw===''||strlen($raw)>1048576) self::unavailable();
        try{$value=json_decode($raw,false,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $error){self::unavailable();}
        if(!$value instanceof stdClass) self::unavailable(); return self::tree($value);
    }
    private static function tree(mixed $value): mixed
    {
        if($value instanceof stdClass){$out=[];foreach(get_object_vars($value) as $key=>$item)$out[$key]=self::tree($item);return $out;}
        if(is_array($value)){foreach($value as $key=>$item)$value[$key]=self::tree($item);}return $value;
    }
    private static function canonical(array $value): string {return MarketplaceOrderInput::canonical(self::tree($value));}
    private static function keys(array $row,array $fields): void
    {
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($fields,SORT_STRING);if($actual!==$fields)self::unavailable();
    }
    private static function stored(array $row,array $expected): void
    {
        foreach($expected as $key=>$value){
            if(!array_key_exists($key,$row)) self::unavailable();
            if(is_int($value)){if(self::integer($row[$key],0,self::UINT_MAX)!==$value)self::unavailable();}
            elseif($row[$key]!==$value)self::unavailable();
        }
    }
    private static function integer(mixed $value,int $minimum,int $maximum): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&bccomp($value,(string)$maximum,0)<=0)$value=(int)$value;
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::unavailable();return $value;
    }
    private static function id(mixed $value): int {return self::integer($value,1,self::INT_MAX);}
    private static function big(mixed $value): string
    {
        if(is_int($value)&&$value>0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1||bccomp($value,'18446744073709551615',0)>0)self::unavailable();return $value;
    }
    private static function text(mixed $value,int $maximum): string
    {
        if(!is_string($value)||$value===''||strlen($value)>$maximum||str_contains($value,"\0")||preg_match('//u',$value)!==1)self::unavailable();return $value;
    }
    private static function characters(mixed $value,int $maximum,bool $empty=false): string
    {
        if(!is_string($value)||(!$empty&&$value==='')||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$maximum)self::unavailable();return $value;
    }
    private static function nowSigned(): int {return self::integer(time(),1,self::INT_MAX);}
    private static function nowUnsigned(): int {return self::integer(time(),1,self::UINT_MAX);}
    private static function outcomeUnproved(string $phase,int $rootId,?int $refundId,?string $actionId): void
    {
        try{Log::error('MARKETPLACE_AFTERSALE_OUTCOME_UNPROVED',['phase'=>$phase,'root_order_id'=>$rootId,'refund_id'=>$refundId,'action_id'=>$actionId]);}
        catch(Throwable $ignored){}
    }
    private static function unavailable(): never {throw new AuthUnavailableException();}
}
