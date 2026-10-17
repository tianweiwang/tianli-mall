<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao as Facts;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\system\config\SystemConfigDao;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\context\MerchantContext;
use app\services\order\StoreOrderCreateServices;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use Throwable;
use think\db\ConnectionInterface;
use think\facade\Db;
use think\facade\Log;

/** Durable normal Task6 only; no routes, Timer registration, legacy events, payment or stock writes. */
final class MarketplaceOrderFulfillmentServices
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const NORMAL_ZERO=['is_cancel','is_del','is_system_del','refund_status','refund_type','refund_reason_time',
        'combination_id','pink_id','seckill_id','bargain_id','advance_id','virtual_type','is_gift'];
    private const CHILD_ZERO=['paid','pay_time','is_stock_up','delivery_uid','spread_uid','spread_two_uid','is_mer_check',
        'store_id','clerk_id','is_remind','staff_id','agent_id','division_id','gift_uid','coupon_id'];
    private const CHILD_EMPTY=['trade_no','pay_type','refund_express','refund_express_name','refund_reason_wap_img',
        'refund_reason_wap_explain','refund_reason_wap','refund_reason','kuaidi_label','kuaidi_task_id','kuaidi_order_id',
        'fictitious_content','verify_code','express_dump','virtual_info','remark','gift_mark'];
    private const CHILD_MONEY_ZERO=['freight_price','deduction_price','coupon_price','refund_price','gain_integral','use_integral',
        'back_integral','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage','gift_price'];

    public function __construct(private Facts $dao,private MarketplaceOrderDao $orders,private MarketplacePaymentDao $payments,
        private EmployeeAuthServices $auth,private StoreOrderCreateServices $numbers,private SystemConfigDao $configuration){}

    /**
     * Pure internal consumer of complete, separately authorized same-snapshot facts.
     * Saved owner IDs are data, never actor credentials; no nullable after-sale fallback.
     */
    public function readHistoryFromFacts(array $root,array $paymentObservation,array $original,array $family,array $operations,array $logs):array
    {
        if(PHP_INT_SIZE<8)self::unavailable();
        Facts::keys($paymentObservation,['locked','root_payment_consistent']);
        if($paymentObservation['root_payment_consistent']!==true||!is_array($paymentObservation['locked']))self::unavailable();
        $payment=$paymentObservation['locked'];Facts::keys($payment,['ledger','root','merchant','registry','attempts']);
        foreach($payment as$value)if(!is_array($value))self::unavailable();
        if(!array_is_list($payment['attempts']))self::unavailable();
        foreach($payment['attempts'] as$row)if(!is_array($row))self::unavailable();
        Facts::integer($root['marketplace_trade_version']??null,1,1);Facts::integer($root['pid']??null,0,0);
        $candidate=['root_order_id'=>Facts::integer($root['id']??null,1,self::INT_MAX),
            'root_order_no'=>Facts::text($root['order_id']??null,32,true),
            'uid'=>Facts::integer($root['uid']??null,1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id']??null,1,self::UINT_MAX)];
        Facts::keys($original,['root','items','selection','reserves','children','child_orders']);
        if(!is_array($original['root']))self::unavailable();
        Facts::keys($original['root'],['id','uid','mer_id','order_no']);
        Facts::stored($original['root'],['id'=>$candidate['root_order_id'],'uid'=>$candidate['uid'],
            'mer_id'=>$candidate['mer_id'],'order_no'=>$candidate['root_order_no']]);
        foreach(['items','selection','reserves','children','child_orders'] as$key)
            if(!is_array($original[$key])||!array_is_list($original[$key]))self::unavailable();
        Facts::keys($family,['children','items','ranges','refunds']);
        foreach($family as$value)if(!is_array($value)||!array_is_list($value))self::unavailable();
        if(!array_is_list($operations)||!array_is_list($logs))self::unavailable();
        foreach($operations as$row)if(!is_array($row))self::unavailable();
        foreach($logs as$row)if(!is_array($row))self::unavailable();
        self::paid($candidate,$root,$payment);
        return $this->history($root,$original,$family,$operations,$logs);
    }

    public function ship(MerchantContext $ctx,mixed $rootId,array $raw):array
    {
        $normalized=MarketplaceOrderFulfillmentInput::ship($rootId,$raw);
        $ctx->require('order.ship');$this->auth->authorizeAction($ctx,'order.ship',false);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertStructure($pdo);
        $candidate=$this->dao->merchantCandidate($pdo,$normalized['request']['root_order_id'],Facts::integer($ctx->merId,1,self::UINT_MAX));
        return $this->run($connection,$pdo,$candidate,'ship',$ctx,null,$normalized,false);
    }

    public function receiveForCustomer(int $actualUid,string $childNo,array $raw):array
    {
        Facts::integer($actualUid,1,self::INT_MAX);Facts::text($childNo,32,true);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertStructure($pdo);
        $candidate=$this->dao->customerChildCandidate($pdo,$actualUid,$childNo);
        $normalized=MarketplaceOrderFulfillmentInput::receive($candidate['root_order_id'],$candidate['child_order_id'],$raw);
        return $this->run($connection,$pdo,$candidate,'receive',null,$actualUid,$normalized,false);
    }

    /** @internal Dedicated actual Worker lifecycle is an activation dependency, never a public actor parameter. */
    public function receiveDue(int $childId):array
    {
        Facts::integer($childId,1,self::INT_MAX);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertStructure($pdo);
        $candidate=$this->dao->internalChildCandidate($pdo,$childId);
        return $this->run($connection,$pdo,$candidate,'receive',null,null,null,true);
    }

    /** @internal Cursor is only a hint; every child is resolved and locked afresh in receiveDue. */
    public function scanDue(int $dueCursor=0,string $shipCursor='0',int $batch=100):array
    {
        Facts::integer($dueCursor,0,self::UINT_MAX);Facts::u64($shipCursor,true);
        if($batch<1||$batch>100)self::unavailable();
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertStructure($pdo);
        $rows=$this->dao->dueCandidates($pdo,$dueCursor,$shipCursor,self::now(),$batch);$this->outside($connection,$pdo);
        $out=['processed'=>0,'received'=>0,'already_received'=>0,'not_due'=>0,'pending'=>0,
            'next_due_at'=>0,'next_ship_id'=>'0','wrapped'=>count($rows)<$batch];
        foreach($rows as$row){
            ++$out['processed'];
            try{
                $result=$this->receiveDue($row['child_order_id']);
                if(($result['action']??null)==='receive')++$out['received'];
                elseif(in_array($result['state']??null,['already_received','not_due'],true))++$out[$result['state']];
                else self::unavailable();
            }catch(Throwable $error){
                ++$out['pending'];self::pending('scan_outcome_unproved',$row['root_order_id'],$row['child_order_id'],$row['ship_operation_id']);
            }
            $out['next_due_at']=$row['due_at'];$out['next_ship_id']=$row['ship_operation_id'];
        }
        if($out['wrapped']){$out['next_due_at']=0;$out['next_ship_id']='0';}
        return $out;
    }

    private function run(ConnectionInterface $connection,PDO $pdo,array $candidate,string $action,?MerchantContext $ctx,
        ?int $customer,?array $normalized,bool $due):array
    {
        $phase='locked_payment';$childId=$candidate['child_order_id']??null;$operationId=null;
        try{
            $this->outside($connection,$pdo);
            $result=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$candidate,$action,$ctx,
                $customer,$normalized,$due,&$phase,&$childId,&$operationId):array{
                $this->inside($actual,$connection,$pdo);
                // Fixed UID -> ledger -> root -> merchant -> registry -> every attempt.
                $payment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);
                $root=$this->dao->lockRoot($pdo,$candidate);self::paid($candidate,$root,$payment);
                if($action==='ship'){
                    if($ctx===null||$ctx->merId!==$candidate['mer_id'])self::unavailable();
                    $this->inside($actual,$connection,$pdo);$this->auth->authorizeAction($ctx,'order.ship',true);
                    $this->inside($actual,$connection,$pdo);
                }elseif($customer!==null){
                    if($customer!==$candidate['uid'])self::unavailable();$this->dao->lockCustomer($pdo,$customer);
                }elseif(!$due)self::unavailable();
                $phase='locked_operations';
                $operations=$this->dao->lockOperations($pdo,$candidate['root_order_id']);
                $phase='locked_original_items_reserves';
                $original=$this->orders->lockPaymentApplicationFacts($pdo,$candidate['uid'],$candidate['root_order_id']);
                $phase='locked_complete_family';
                $family=$this->dao->lockFamily($pdo,$root);
                $logs=$this->dao->lockStatusLogs($pdo,$candidate['root_order_id']);
                $history=$this->history($root,$original,$family,$operations,$logs);
                $this->inside($actual,$connection,$pdo);
                $target=null;$mapping=[];
                if($action==='receive'){
                    $childId=$candidate['child_order_id'];
                    $target=$history['ships_by_child'][$childId]??null;
                    if($target===null||($history['children'][$childId]['order_id']??null)!==$candidate['child_order_no'])self::unavailable();
                    $mapping=self::eligible($target,$history);
                    if($due){
                        $now=self::now();
                        if($target['receipt_due_at']===0||$target['receipt_due_at']>$now)
                            return $this->noOperation($actual,$connection,$pdo,'not_due',$candidate,$phase);
                        if($mapping===[])return $this->noOperation($actual,$connection,$pdo,'already_received',$candidate,$phase);
                        $key='auto:'.$target['id'].':'.hash('sha256',Facts::json(self::coordinates($mapping,true),65536));
                        $normalized=MarketplaceOrderFulfillmentInput::receive($candidate['root_order_id'],$childId,
                            ['idem_key'=>$key,'selection'=>['mode'=>'all_eligible']]);
                    }
                }
                if($normalized===null)self::unavailable();
                $phase='saved_replay';
                $saved=self::findOperation($operations,$action,$normalized['idem_key']);
                if($saved!==null){
                    if(Facts::json($saved['request_payload'],65536)!==Facts::json($normalized['request'],65536)
                        ||!hash_equals($saved['request_digest'],hash('sha256',Facts::json($normalized['request'],65536),true)))self::unavailable();
                    $this->inside($actual,$connection,$pdo);$phase='commit_outcome_unknown';return $saved['result_payload']['public_result'];
                }
                // These new-action gates never override a genuine committed replay.
                $phase='new_action_admission';self::holdClear($payment['registry']);
                if($action==='ship'){
                    self::shipAdmission($payment['merchant']);
                    $plan=MarketplaceOrderFulfillmentPlan::plan($root,$original['items'],$original['reserves'],
                        self::coordinates($history['ship_ranges']),$normalized['request']['quantities']);
                    MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$plan);
                    $now=self::now();$this->inside($actual,$connection,$pdo);
                    $deadline=$this->deadline($now);$this->inside($actual,$connection,$pdo);
                    $phase='new_child_number';
                    $number=Facts::text($this->numbers->getNewOrderId('cf'),32,true);
                    $this->inside($actual,$connection,$pdo);
                    $childData=self::childData($root,$plan,$normalized['request'],$now,$number,bin2hex(random_bytes(16)));
                    $phase='insert_child';$childId=$this->dao->insertChild($pdo,$childData);
                    $child=$this->dao->child($pdo,$childId);self::childStored($child,$childData);
                    $mapping=array_map(static fn(array $a):array=>$a+['source_ship_range_id'=>null,'child_item_id'=>null],$plan['allocations']);
                }else{
                    if($mapping===[])return $this->noOperation($actual,$connection,$pdo,'already_received',$candidate,$phase);
                    $mapping=self::selectReceive($mapping,$normalized['request']['selection']);
                    $plan=MarketplaceOrderFulfillmentPlan::exactRangesPlan($root,$original['items'],$original['reserves'],self::coordinates($mapping));
                    MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$plan);
                    self::sameIntervals($plan['allocations'],$mapping);$now=self::now();$deadline=null;
                }
                $phase='insert_operation';
                $operation=self::operationData($root,$childId,$action,$normalized,$plan,$mapping,$target,$ctx,$customer,$now,$deadline);
                $operationId=$this->dao->insertOperation($pdo,$operation);
                if($action==='ship'){
                    $sourceItems=self::indexRows($original['items'],'id');$itemMap=[];
                    foreach(self::itemGroups($plan) as$sourceId=>$allocations){
                        $data=self::childItemData($root,$childId,$operationId,$sourceItems[$sourceId],$allocations);
                        $itemMap[$sourceId]=$this->dao->insertChildItem($pdo,$data);
                    }
                    foreach($mapping as&$row)$row['child_item_id']=$itemMap[$row['source_item_id']];unset($row);
                }
                $rangeRefs=[];$phase='insert_ranges';
                foreach($plan['allocations'] as$i=>$allocation){
                    $range=self::rangeData($operation+['id'=>$operationId],$allocation,$mapping[$i]);
                    $rangeId=$this->dao->insertRange($pdo,$range);
                    $rangeRefs[]=['range_id'=>$rangeId,'source_item_id'=>$allocation['source_item_id'],
                        'child_item_id'=>$mapping[$i]['child_item_id'],'source_ship_range_id'=>$mapping[$i]['source_ship_range_id'],
                        'unit_start'=>$allocation['unit_start'],'unit_end'=>$allocation['unit_end']];
                }
                // Re-read the complete native family while the one new state0 is still private to this transaction.
                $phase='native_readback';
                $allOperations=$this->dao->lockOperations($pdo,$candidate['root_order_id']);
                $allFamily=$this->dao->lockFamily($pdo,$root);
                $own=null;foreach($allOperations as$row)if($row['id']===$operationId)$own=$row;
                $expectedOwn=Facts::operation(['id'=>$operationId]+$operation);
                if($own!==$expectedOwn)self::unavailable();
                $summary=self::summary($root,$original['items'],$allFamily['children'],$allFamily['ranges']);
                foreach($allFamily['children'] as$row){
                    $id=Facts::integer($row['id'],1,self::INT_MAX);
                    $next=self::childReceived($id,$allFamily['ranges'])?2:1;
                    $this->dao->status($pdo,$row,$next);
                }
                $newStatus=self::rootStatus($summary);$oldStatus=Facts::integer($root['status'],0,2);
                $afterRoot=$this->dao->status($pdo,$root,$newStatus);
                if($newStatus!==$oldStatus)$this->dao->statusLog($pdo,$candidate['root_order_id'],$action,$now);
                $child=$this->dao->child($pdo,$childId);
                $committedAt=self::now();if($committedAt<$now)self::unavailable();
                $internal=self::result($operation+['id'=>$operationId],$afterRoot,$child,$plan,$rangeRefs,$summary,$committedAt);
                $phase='finish_operation';$this->dao->finishOperation($pdo,$operationId,$internal,$committedAt);
                // Original source rows/ledger/payment must stay native-equal, except root.status and actual new child list.
                $finalPayment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);
                $expectedPayment=$payment;$expectedPayment['root']['status']=$newStatus;
                if($finalPayment!==$expectedPayment)self::unavailable();
                $finalOriginal=$this->orders->lockPaymentApplicationFacts($pdo,$candidate['uid'],$candidate['root_order_id']);
                foreach(['root','items','selection','reserves','children'] as$key)if($finalOriginal[$key]!==$original[$key])self::unavailable();
                $finalRoot=$this->dao->lockRoot($pdo,$candidate);
                if($finalRoot!==$afterRoot)self::unavailable();
                $finalFamily=$this->dao->lockFamily($pdo,$finalRoot);
                $finalOperations=$this->dao->lockOperations($pdo,$candidate['root_order_id']);
                $finalLogs=$this->dao->lockStatusLogs($pdo,$candidate['root_order_id']);
                $finalChildren=self::indexRows($finalFamily['children'],'id');
                foreach($family['children'] as$prior){
                    $id=Facts::integer($prior['id'],1,self::INT_MAX);
                    $status=self::childReceived($id,$finalFamily['ranges'])?2:1;
                    $prior['status']=is_int($prior['status'])?$status:(string)$status;
                    if(($finalChildren[$id]??null)!==$prior)self::unavailable();
                }
                $finalItems=self::indexRows($finalFamily['items'],'id');
                foreach($family['items'] as$prior)if(($finalItems[Facts::integer($prior['id'],1,self::INT_MAX)]??null)!==$prior)self::unavailable();
                $finalOperationMap=[];foreach($finalOperations as$row)$finalOperationMap[$row['id']]=$row;
                foreach($operations as$prior)if(($finalOperationMap[$prior['id']]??null)!==$prior)self::unavailable();
                $finalRangeMap=[];foreach($finalFamily['ranges'] as$row)$finalRangeMap[$row['id']]=$row;
                foreach($family['ranges'] as$prior)if(($finalRangeMap[$prior['id']]??null)!==$prior)self::unavailable();
                $this->history($finalRoot,$finalOriginal,$finalFamily,$finalOperations,$finalLogs);
                $stored=self::findOperation($finalOperations,$action,$normalized['idem_key']);
                if($stored===null||$stored['id']!==$operationId||!self::same($stored['result_payload'],$internal))self::unavailable();
                $this->inside($actual,$connection,$pdo);$phase='commit_outcome_unknown';return $internal['public_result'];
            });
            // This may throw after a genuine commit; catch does not claim rollback.
            $phase='post_commit_boundary';$this->outside($connection,$pdo);return $result;
        }catch(Throwable $error){
            self::pending($phase,$candidate['root_order_id'],$childId,$operationId);self::unavailable();
        }
    }

    private function noOperation(ConnectionInterface $actual,ConnectionInterface $connection,PDO $pdo,string $state,array $candidate,string &$phase):array
    {
        $this->inside($actual,$connection,$pdo);$phase='commit_outcome_unknown';
        return ['state'=>$state,'operation_created'=>false,'root_order_id'=>$candidate['root_order_id'],'child_order_id'=>$candidate['child_order_id']];
    }

    private static function paid(array $candidate,array $root,array $payment):void
    {
        Facts::keys($payment,['ledger','root','merchant','registry','attempts']);
        foreach($payment['root'] as$key=>$value){
            if(!array_key_exists($key,$root))self::unavailable();
            if(is_int($value)){if(Facts::integer($root[$key],0,self::UINT_MAX)!==$value)self::unavailable();}
            elseif($root[$key]!==$value)self::unavailable();
        }
        if($payment['root']['paid']!==1||$payment['root']['id']!==$candidate['root_order_id']
            ||$payment['root']['uid']!==$candidate['uid']||$payment['root']['mer_id']!==$candidate['mer_id'])self::unavailable();
        $winner=Facts::u64($payment['registry']['winner_attempt_id']??null);$found=0;
        foreach($payment['attempts'] as$attempt){
            $application=$attempt['evidence']['application']??null;
            if($application!==null){
                if($attempt['id']!==$winner||($application['order_pk']??null)!==$candidate['root_order_id']
                    ||($application['attempt_id']??null)!==$winner||!is_array($application['root_payment']??null)||!self::same($application['root_payment'],[
                        'paid'=>1,'pay_time'=>$payment['root']['pay_time'],'pay_type'=>$payment['root']['pay_type'],'trade_no'=>$payment['root']['trade_no']]))self::unavailable();
                ++$found;
            }
        }if($found!==1)self::unavailable();
    }

    private static function holdClear(array $registry):void
    {
        if(($registry['fulfillment_hold']??null)!==0||$registry['hold_reason']!==null
            ||$registry['hold_attempt_id']!==null||$registry['hold_at']!==null)self::unavailable();
    }
    private static function shipAdmission(array $merchant):void
    {
        if(($merchant['review_status']??null)!==1||($merchant['security_locked']??null)!==0
            ||($merchant['closed_at']??null)!==null||($merchant['fulfillment_enabled']??null)!==1)self::unavailable();
    }

    /** Complete history validation also reconstructs each original result, not today's replanned remainder. */
    private function history(array $root,array $original,array $family,array $operations,array $logs):array
    {
        Facts::keys($original,['root','items','selection','reserves','children','child_orders']);
        Facts::keys($family,['children','items','ranges','refunds']);
        self::normal($root);
        if($original['children']!==[]||$family['refunds']!==[])self::unavailable();
        $items=self::indexRows($original['items'],'id');$quantities=[];
        foreach($items as$id=>$item){
            if(Facts::integer($item['oid']??null,1,self::INT_MAX)!==Facts::integer($root['id'],1,self::INT_MAX)
                ||Facts::integer($item['refund_num']??null,0,0)!==0
                ||Facts::integer($item['split_status']??null,0,0)!==0
                ||Facts::integer($item['surplus_num']??null,1,self::INT_MAX)!==Facts::integer($item['cart_num']??null,1,self::INT_MAX))self::unavailable();
            $cart=Facts::decode($item['cart_info']??null,65535);
            if(array_key_exists('marketplace_fulfillment',$cart))self::unavailable();
            $quantities[]=['source_item_id'=>$id,'quantity'=>Facts::integer($item['cart_num'],1,self::INT_MAX)];
        }
        $reference=MarketplaceOrderFulfillmentPlan::plan($root,$original['items'],$original['reserves'],[],$quantities);
        MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$reference);
        $children=self::indexRows($family['children'],'id');$childItems=self::indexRows($family['items'],'id');
        foreach($children as$child){self::normal($child);self::childDefaults($child);
            if(Facts::integer($child['pid'],1,self::INT_MAX)!==Facts::integer($root['id'],1,self::INT_MAX)
                ||Facts::integer($child['uid'],1,self::INT_MAX)!==Facts::integer($root['uid'],1,self::INT_MAX)
                ||Facts::integer($child['mer_id'],1,self::UINT_MAX)!==Facts::integer($root['mer_id'],1,self::UINT_MAX))self::unavailable();
        }
        $nativeChildren=self::indexRows($original['child_orders'],'id');
        if(array_keys($nativeChildren)!==array_keys($children))self::unavailable();
        foreach($nativeChildren as$id=>$row)Facts::stored($children[$id],$row);
        $byOperation=[];$allRangeIds=[];$lastRange='0';
        foreach($family['ranges'] as$range){
            if(bccomp($range['id'],$lastRange,0)<=0||isset($allRangeIds[$range['id']]))self::unavailable();
            $lastRange=$range['id'];$allRangeIds[$range['id']]=$range;
            $byOperation[$range['operation_id']][]=$range;
        }
        $ships=[];$operationMap=[];$prefixRanges=[];$prefixChildren=[];$usedItems=[];$expectedLogs=[];$priorStatus=0;$last='0';$lastTime=0;
        foreach($operations as$operation){
            $id=$operation['id'];$action=$operation['action'];$childId=$operation['child_order_id'];
            if(bccomp($id,$last,0)<=0||isset($operationMap[$id])||$operation['contract_version']!==1||$operation['state']!==1
                ||!in_array($action,['ship','receive'],true)||$operation['root_order_id']!==Facts::integer($root['id'],1,self::INT_MAX)
                ||$operation['uid']!==Facts::integer($root['uid'],1,self::INT_MAX)
                ||$operation['mer_id']!==Facts::integer($root['mer_id'],1,self::UINT_MAX)
                ||!isset($children[$childId])||$operation['created_at']<1
                ||$operation['committed_at']===null||$operation['committed_at']<$operation['created_at']
                ||$operation['committed_at']<$lastTime)self::unavailable();
            $last=$id;$lastTime=$operation['committed_at'];$child=$children[$childId];
            $request=self::request($operation);$saved=$operation['result_payload'];
            if(!is_array($saved))self::unavailable();
            Facts::keys($saved,['schema','operation_id','action','root_order_id','root_order_no','child_order_id','child_order_no',
                'target_operation_id','actor_type','actor_id','created_at','committed_at','shipped_at','receipt_due_at','received_at',
                'plan','ranges','summary','public_result']);
            if(!is_array($saved['plan'])||!is_array($saved['ranges'])||!array_is_list($saved['ranges']))self::unavailable();
            $plan=$saved['plan'];MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$plan);
            if($plan['source_snapshot_sha256']!==$reference['source_snapshot_sha256']
                ||!hash_equals($operation['source_snapshot_digest'],self::sourceDigest($plan)))self::unavailable();
            $actualRanges=$byOperation[$id]??[];
            if(count($actualRanges)!==count($plan['allocations'])||count($saved['ranges'])!==count($actualRanges))self::unavailable();
            $mapping=[];
            foreach($plan['allocations'] as$i=>$allocation){
                $ref=$saved['ranges'][$i];
                if(!is_array($ref))self::unavailable();
                Facts::keys($ref,['range_id','source_item_id','child_item_id','source_ship_range_id','unit_start','unit_end']);
                if(!is_string($ref['range_id'])||Facts::u64($ref['range_id'])!==$actualRanges[$i]['id']
                    ||!is_int($ref['child_item_id'])||!is_int($ref['source_item_id'])||!is_int($ref['unit_start'])||!is_int($ref['unit_end'])
                    ||$ref['source_item_id']!==$allocation['source_item_id']||$ref['unit_start']!==$allocation['unit_start']
                    ||$ref['unit_end']!==$allocation['unit_end']||!isset($childItems[$ref['child_item_id']]))self::unavailable();
                $map=$allocation+['source_ship_range_id'=>$ref['source_ship_range_id'],'child_item_id'=>$ref['child_item_id']];
                $expectedRange=['id'=>$ref['range_id']]+self::rangeData($operation,$allocation,$map);
                if(!self::same($actualRanges[$i],$expectedRange))self::unavailable();
                if($action==='ship'){
                    if($ref['source_ship_range_id']!==null)self::unavailable();
                }else{
                    if(!is_string($ref['source_ship_range_id']))self::unavailable();
                    $parent=$allRangeIds[$ref['source_ship_range_id']]??null;
                    if($parent===null||$parent['operation_action']!=='ship'||$parent['operation_id']!==$operation['target_operation_id']
                        ||$parent['child_order_id']!==$childId||$parent['source_item_id']!==$allocation['source_item_id']
                        ||$parent['child_item_id']!==$ref['child_item_id']||$allocation['unit_start']<$parent['unit_start']
                        ||$allocation['unit_end']>$parent['unit_end']||bccomp($parent['operation_id'],$id,0)>=0)self::unavailable();
                }
                $mapping[]=$map;
            }
            $target=null;
            if($action==='ship'){
                if(isset($ships[$childId])||$operation['target_operation_id']!==null||$operation['target_action']!==null
                    ||$operation['ship_child_order_id']!==$childId||$operation['actor_type']!=='employee'
                    ||$operation['actor_id']===null||$operation['actor_id']<1||$operation['shipped_at']!==$operation['created_at']
                    ||$operation['received_at']!==null||$operation['receipt_due_at']===null
                    ||($operation['receipt_due_at']!==0&&$operation['receipt_due_at']<$operation['shipped_at']))self::unavailable();
                if(!self::same(self::quantityRequest($plan),$request['quantities']))self::unavailable();
                $expectedChild=self::childData($root,$plan,$request,$operation['shipped_at'],
                    Facts::text($child['order_id'],32,true),Facts::text($child['unique'],32,true));
                self::childStored($child,$expectedChild,false);
                $groups=self::itemGroups($plan);
                foreach($groups as$sourceId=>$allocations){
                    $mapped=[];foreach($mapping as$m)if($m['source_item_id']===$sourceId)$mapped[$m['child_item_id']]=true;
                    if(count($mapped)!==1)self::unavailable();$itemId=array_key_first($mapped);
                    if(isset($usedItems[$itemId]))self::unavailable();$usedItems[$itemId]=true;
                    $expectedItem=self::childItemData($root,$childId,$id,$items[$sourceId],$allocations);
                    $actualItem=$childItems[$itemId];$expectedItem=['id'=>$itemId,'old_cart_id'=>'']+$expectedItem;
                    Facts::keys($actualItem,array_keys($expectedItem));self::itemStored($actualItem,$expectedItem);
                }
                $ships[$childId]=$operation;$prefixChildren[]=$child;
            }else{
                $target=$ships[$childId]??null;
                if($target===null||$operation['ship_child_order_id']!==null||$operation['target_operation_id']!==$target['id']
                    ||$operation['target_action']!=='ship'||$operation['shipped_at']!==null||$operation['receipt_due_at']!==null
                    ||$operation['received_at']!==$operation['created_at']
                    ||!(($operation['actor_type']==='customer'&&$operation['actor_id']===$operation['uid'])
                        ||($operation['actor_type']==='system'&&$operation['actor_id']===null)))self::unavailable();
                $priorHistory=['ranges'=>$prefixRanges];
                $eligible=self::eligible($target,$priorHistory);
                $selected=self::selectReceive($eligible,$request['selection']);
                self::sameIntervals($plan['allocations'],$selected);
                foreach($mapping as$i=>$map)if($map['source_ship_range_id']!==$selected[$i]['source_ship_range_id']
                    ||$map['child_item_id']!==$selected[$i]['child_item_id'])self::unavailable();
                if($operation['actor_type']==='system'){
                    if($target['receipt_due_at']===0||$target['receipt_due_at']>$operation['received_at']
                        ||!self::same($request['selection'],['mode'=>'all_eligible'])
                        ||$operation['idem_key']!=='auto:'.$target['id'].':'.hash('sha256',Facts::json(self::coordinates($eligible,true),65536)))self::unavailable();
                }
            }
            if(!hash_equals($operation['allocation_digest'],self::allocationDigest($operation,$plan,$mapping)))self::unavailable();
            $prefixRanges=array_merge($prefixRanges,$actualRanges);
            $summary=self::summary($root,$original['items'],$prefixChildren,$prefixRanges);
            $expected=self::result($operation,$root,$child,$plan,$saved['ranges'],$summary,$operation['committed_at']);
            if(!self::same($saved,$expected))self::unavailable();
            $nextStatus=self::rootStatus($summary);
            if($nextStatus!==$priorStatus){
                $expectedLogs[]=['oid'=>Facts::integer($root['id'],1,self::INT_MAX),
                    'change_type'=>$action==='ship'?'marketplace_fulfillment_ship':'marketplace_fulfillment_receive',
                    'change_message'=>$action==='ship'?'多商户履约发货状态更新':'多商户履约收货状态更新','change_time'=>$operation['created_at']];
            }
            $priorStatus=$nextStatus;$operationMap[$id]=$operation;
        }
        if(count($operationMap)!==count($byOperation)||count($ships)!==count($children)||count($usedItems)!==count($childItems))self::unavailable();
        foreach($byOperation as$id=>$rows)if(!isset($operationMap[$id]))self::unavailable();
        $summary=self::summary($root,$original['items'],$family['children'],$family['ranges']);
        if(Facts::integer($root['status'],0,2)!==self::rootStatus($summary))self::unavailable();
        foreach($children as$id=>$child)if(Facts::integer($child['status'],1,2)!==(self::childReceived($id,$family['ranges'])?2:1))self::unavailable();
        $actualLogs=[];
        foreach($logs as$row){
            Facts::keys($row,['oid','change_type','change_message','change_time']);
            $row['oid']=Facts::integer($row['oid'],1,self::INT_MAX);$row['change_time']=Facts::integer($row['change_time'],1,self::UINT_MAX);
            $actualLogs[]=Facts::json($row,2048);
        }
        $wantedLogs=array_map(static fn(array $r):string=>Facts::json($r,2048),$expectedLogs);
        sort($actualLogs,SORT_STRING);sort($wantedLogs,SORT_STRING);if($actualLogs!==$wantedLogs)self::unavailable();
        return ['children'=>$children,'child_items'=>$childItems,'ships_by_child'=>$ships,'operations'=>$operationMap,
            'ranges'=>$family['ranges'],'ship_ranges'=>array_values(array_filter($family['ranges'],static fn(array $r):bool=>$r['operation_action']==='ship')),
            'summary'=>$summary,'source_snapshot_sha256'=>$reference['source_snapshot_sha256']];
    }

    private static function normal(array $row):void
    {
        foreach(self::NORMAL_ZERO as$key)if(Facts::integer($row[$key]??null,0,0)!==0)self::unavailable();
        if(Facts::integer($row['marketplace_trade_version']??null,1,1)!==1
            ||Facts::integer($row['shipping_type']??null,1,1)!==1||($row['refund_price']??null)!=='0.00')self::unavailable();
    }

    private static function childDefaults(array $row):void
    {
        foreach(self::CHILD_ZERO as$key)if(Facts::integer($row[$key]??null,0,0)!==0)self::unavailable();
        foreach(self::CHILD_EMPTY as$key)if(($row[$key]??null)!=='')self::unavailable();
        foreach(self::CHILD_MONEY_ZERO as$key)if(($row[$key]??null)!=='0.00')self::unavailable();
    }

    private static function request(array $operation):array
    {
        $request=$operation['request_payload'];$action=$operation['action'];
        if($action==='ship'){
            Facts::keys($request,['schema','action','root_order_id','quantities','delivery']);
            $normal=MarketplaceOrderFulfillmentInput::ship($operation['root_order_id'],['idem_key'=>$operation['idem_key'],
                'quantities'=>$request['quantities'],'delivery'=>$request['delivery']]);
        }else{
            Facts::keys($request,['schema','action','root_order_id','child_order_id','selection']);
            $normal=MarketplaceOrderFulfillmentInput::receive($operation['root_order_id'],$operation['child_order_id'],
                ['idem_key'=>$operation['idem_key'],'selection'=>$request['selection']]);
        }
        if(!self::same($request,$normal['request'])||!hash_equals($operation['request_digest'],hash('sha256',Facts::json($request,65536),true)))self::unavailable();
        return $request;
    }

    private static function findOperation(array $operations,string $action,string $key):?array
    {
        $found=null;foreach($operations as$row)if($row['action']===$action&&$row['idem_key']===$key){
            if($found!==null)self::unavailable();$found=$row;
        }return $found;
    }

    private static function indexRows(array $rows,string $key):array
    {
        if(!array_is_list($rows))self::unavailable();$out=[];
        foreach($rows as$row){
            if(!is_array($row))self::unavailable();$id=Facts::integer($row[$key]??null,1,self::INT_MAX);
            if(isset($out[$id]))self::unavailable();$out[$id]=$row;
        }ksort($out,SORT_NUMERIC);return $out;
    }

    private static function itemGroups(array $plan):array
    {
        $out=[];foreach($plan['allocations'] as$allocation)$out[$allocation['source_item_id']][]=$allocation;
        ksort($out,SORT_NUMERIC);return $out;
    }

    private static function quantityRequest(array $plan):array
    {
        $out=[];foreach(self::itemGroups($plan) as$id=>$rows){$quantity=0;foreach($rows as$row)$quantity+=$row['quantity'];
            $out[]=['source_item_id'=>$id,'quantity'=>$quantity];}return $out;
    }

    private static function coordinates(array $ranges,bool $parents=false):array
    {
        $out=[];foreach($ranges as$row){
            $range=['source_item_id'=>$row['source_item_id'],'unit_start'=>$row['unit_start'],'unit_end'=>$row['unit_end']];
            if($parents)$range['source_ship_range_id']=$row['source_ship_range_id'];$out[]=$range;
        }
        usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:($a['unit_start']<=>$b['unit_start'])?:($a['unit_end']<=>$b['unit_end']));
        return $out;
    }

    private static function sameIntervals(array $allocations,array $mapping):void
    {
        if(self::coordinates($allocations)!==self::coordinates($mapping)||count($allocations)!==count($mapping))self::unavailable();
    }

    /** Subtract R from each real S parent, retaining that parent's actual item and ID. */
    private static function eligible(array $ship,array $history):array
    {
        $parents=[];$receives=[];
        foreach($history['ranges'] as$row){
            if($row['operation_action']==='ship'&&$row['operation_id']===$ship['id'])$parents[]=$row;
            elseif($row['operation_action']==='receive'&&$row['child_order_id']===$ship['child_order_id'])
                $receives[$row['source_ship_range_id']][]=$row;
        }
        $out=[];
        foreach($parents as$parent){
            $rows=$receives[$parent['id']]??[];
            usort($rows,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);
            $cursor=$parent['unit_start'];
            foreach($rows as$row){
                if($row['unit_start']<$cursor||$row['unit_end']>$parent['unit_end'])self::unavailable();
                if($cursor<$row['unit_start'])$out[]=self::eligiblePart($parent,$cursor,$row['unit_start']);
                $cursor=$row['unit_end'];
            }
            if($cursor<$parent['unit_end'])$out[]=self::eligiblePart($parent,$cursor,$parent['unit_end']);
        }
        usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:($a['unit_start']<=>$b['unit_start']));
        return $out;
    }

    private static function eligiblePart(array $parent,int $start,int $end):array
    {
        return ['source_item_id'=>$parent['source_item_id'],'unit_start'=>$start,'unit_end'=>$end,
            'source_ship_range_id'=>$parent['id'],'child_item_id'=>$parent['child_item_id']];
    }

    /** A manual interval must be entirely eligible; it cannot be silently clipped. */
    private static function selectReceive(array $eligible,array $selection):array
    {
        if(self::same($selection,['mode'=>'all_eligible']))return $eligible;
        if(($selection['mode']??null)!=='ranges')self::unavailable();$out=[];
        foreach($selection['ranges'] as$requested){
            $cursor=$requested['unit_start'];
            foreach($eligible as$part){
                if($part['source_item_id']!==$requested['source_item_id']||$part['unit_end']<=$cursor
                    ||$part['unit_start']>=$requested['unit_end'])continue;
                if($part['unit_start']>$cursor)self::unavailable();
                $end=min($part['unit_end'],$requested['unit_end']);
                $out[]=array_replace($part,['unit_start'=>$cursor,'unit_end'=>$end]);$cursor=$end;
                if($cursor===$requested['unit_end'])break;
            }
            if($cursor!==$requested['unit_end'])self::unavailable();
        }
        if($out===[])self::unavailable();return $out;
    }

    private static function summary(array $root,array $items,array $children,array $ranges):array
    {
        $quantities=[];$original=0;
        foreach($items as$item){$id=Facts::integer($item['id'],1,self::INT_MAX);$q=Facts::integer($item['cart_num'],1,self::INT_MAX);
            $quantities[$id]=$q;$original+=$q;Facts::integer($original,1,self::UINT_MAX);}
        $ship=[];$receive=[];$parentMap=[];
        foreach($ranges as$row){
            if(!isset($quantities[$row['source_item_id']])||$row['unit_start']<0||$row['unit_end']<=$row['unit_start']
                ||$row['unit_end']>$quantities[$row['source_item_id']]||$row['quantity']!==$row['unit_end']-$row['unit_start'])self::unavailable();
            if($row['operation_action']==='ship'){$ship[$row['source_item_id']][]=$row;$parentMap[$row['id']]=$row;}
            elseif($row['operation_action']==='receive')$receive[$row['source_item_id']][]=$row;
            else self::unavailable();
        }
        $shipped=0;$received=0;
        foreach($ship as$rows){usort($rows,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);$last=0;
            foreach($rows as$row){if($row['unit_start']<$last)self::unavailable();$last=$row['unit_end'];$shipped+=$row['quantity'];}}
        foreach($receive as$rows){usort($rows,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);$last=0;
            foreach($rows as$row){
                $parent=$parentMap[$row['source_ship_range_id']]??null;
                if($parent===null||$parent['source_item_id']!==$row['source_item_id']||$parent['child_order_id']!==$row['child_order_id']
                    ||$parent['child_item_id']!==$row['child_item_id']
                    ||$row['unit_start']<$last||$row['unit_start']<$parent['unit_start']||$row['unit_end']>$parent['unit_end'])self::unavailable();
                $last=$row['unit_end'];$received+=$row['quantity'];
            }}
        if($original!==Facts::integer($root['total_num'],1,self::UINT_MAX)||$received>$shipped||$shipped>$original)self::unavailable();
        $toShip=$original-$shipped;$toReceive=$shipped-$received;
        return ['shipping_state'=>$shipped===0?'unshipped':($toShip>0?'partial':'shipped'),
            'receipt_state'=>$shipped===0?'not_shipped':($received===$shipped?'received':($received===0?'pending':'partial')),
            'closed'=>$toShip===0&&$toReceive===0,'original'=>$original,'shipped'=>$shipped,'received'=>$received,
            'cancelled_unshipped'=>0,'returned_accepted'=>0,'to_ship'=>$toShip,'to_receive'=>$toReceive,'child_count'=>count($children)];
    }

    private static function childReceived(int $id,array $ranges):bool
    {
        $shipped=0;$received=0;
        foreach($ranges as$row)if($row['child_order_id']===$id){
            if($row['operation_action']==='ship')$shipped+=$row['quantity'];
            elseif($row['operation_action']==='receive')$received+=$row['quantity'];
        }
        if($shipped<1||$received>$shipped)self::unavailable();return $received===$shipped;
    }
    private static function rootStatus(array $summary):int
    {
        if($summary['to_ship']>0)return 0;
        if($summary['shipped']<1)self::unavailable();return $summary['to_receive']===0?2:1;
    }

    private static function childData(array $root,array $plan,array $request,int $now,string $number,string $unique):array
    {
        if(preg_match('/\Acf[0-9]+\z/D',$number)!==1||preg_match('/\A[0-9a-f]{32}\z/D',$unique)!==1
            ||$unique===($root['unique']??null))self::unavailable();
        $products=array_values(array_unique(array_column($plan['allocations'],'product_id'),SORT_NUMERIC));
        $forms=MarketplaceOrderSnapshotProjection::customForms($root['custom_form']??null,$products);
        $refs=[];foreach($plan['allocations'] as$a)if(!in_array($a['cart_ref'],$refs,true))$refs[]=$a['cart_ref'];
        $data=['pid'=>Facts::integer($root['id'],1,self::INT_MAX),'order_id'=>$number,
            'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX),
            'marketplace_trade_version'=>1,'unique'=>$unique,'cart_id'=>Facts::json($refs,65535),
            'total_num'=>Facts::integer($plan['totals']['quantity'],1,self::UINT_MAX),'total_price'=>$plan['totals']['goods'],
            'total_postage'=>$plan['totals']['postage'],'pay_price'=>$plan['totals']['payable'],'pay_postage'=>$plan['totals']['postage'],
            'cost'=>$plan['totals']['cost'],'add_time'=>$now,'pay_uid'=>Facts::integer($root['uid'],1,self::INT_MAX),
            'shipping_type'=>1,'is_gift'=>0,'status'=>1,'paid'=>0,'pay_time'=>0,'pay_type'=>'','trade_no'=>'',
            'delivery_type'=>'express','delivery_code'=>$request['delivery']['carrier_code'],
            'delivery_name'=>$request['delivery']['carrier_name'],'delivery_id'=>$request['delivery']['tracking_no'],
            'custom_form'=>Facts::json($forms,65535)];
        foreach(['real_name'=>32,'user_phone'=>18,'user_address'=>100,'channel_type'=>255,'province'=>255,'mark'=>512,
            'merchant_name_snapshot'=>128,'merchant_after_sale_contact_snapshot'=>255] as$key=>$max)
            $data[$key]=Facts::text($root[$key]??null,$max,in_array($key,['real_name','user_phone','user_address','merchant_name_snapshot'],true));
        $data['is_channel']=Facts::integer($root['is_channel']??null,0,1);
        return $data;
    }

    private static function childStored(array $actual,array $data,bool $new=true):void
    {
        self::normal($actual);self::childDefaults($actual);
        if(!$new)unset($data['status']);
        Facts::stored($actual,$data);
    }

    private static function childItemData(array $root,int $childId,string $shipId,array $source,array $allocations):array
    {
        $sourceId=Facts::integer($source['id'],1,self::INT_MAX);
        $cart=Facts::decode($source['cart_info'],65535);
        if(isset($cart['marketplace_fulfillment']))self::unavailable();
        $quantity=0;$goods=$postage=$origin='0.00';
        foreach($allocations as$a){
            if($a['source_item_id']!==$sourceId||$a['cart_ref']!==$source['cart_id'])self::unavailable();
            $quantity+=$a['quantity'];$goods=bcadd($goods,$a['goods'],2);
            $postage=bcadd($postage,$a['postage'],2);$origin=bcadd($origin,$a['origin_postage'],2);
        }
        $quantity=Facts::integer($quantity,1,self::INT_MAX);
        $cart['cart_num']=$quantity;$cart['sum_true_price']=$goods;$cart['postage_price']=$postage;$cart['origin_postage_price']=$origin;
        $cart['marketplace_fulfillment']=['schema'=>1,'root_order_id'=>Facts::integer($root['id'],1,self::INT_MAX),
            'source_item_id'=>$sourceId,'ship_operation_id'=>Facts::u64($shipId)];
        return ['oid'=>$childId,'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX),
            'cart_id'=>Facts::text($source['cart_id'],50,true),'product_id'=>Facts::integer($source['product_id'],1,self::INT_MAX),
            'cart_num'=>$quantity,'surplus_num'=>$quantity,'refund_num'=>0,'split_status'=>0,
            'unique'=>md5($source['cart_id'].$childId),'cart_info'=>Facts::json($cart,65535)];
    }

    private static function itemStored(array $actual,array $expected):void
    {
        foreach($expected as$key=>$value){
            if($key==='cart_info'){
                if(!self::same(Facts::decode($actual[$key]??null,65535),Facts::decode($value,65535)))self::unavailable();
            }else Facts::stored($actual,[$key=>$value]);
        }
    }

    private static function sourceDigest(array $plan):string
    {
        $hex=$plan['source_snapshot_sha256']??null;
        if(!is_string($hex)||preg_match('/\A[0-9a-f]{64}\z/D',$hex)!==1)self::unavailable();
        $raw=hex2bin($hex);if(!is_string($raw)||strlen($raw)!==32)self::unavailable();return $raw;
    }

    private static function allocationDigest(array $operation,array $plan,array $mapping):string
    {
        self::sameIntervals($plan['allocations'],$mapping);$allocations=[];
        foreach($plan['allocations'] as$i=>$row){
            $parent=$mapping[$i]['source_ship_range_id'];
            if($operation['action']==='ship'){if($parent!==null)self::unavailable();}
            else{if(!is_string($parent))self::unavailable();Facts::u64($parent);}
            $allocations[]=$row+['source_ship_range_id'=>$parent];
        }
        return hash('sha256',Facts::json(['schema'=>1,'action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'child_order_id'=>$operation['child_order_id'],'source_snapshot_sha256'=>$plan['source_snapshot_sha256'],
            'target_operation_id'=>$operation['target_operation_id'],'allocations'=>$allocations],1048576),true);
    }

    private static function operationData(array $root,int $childId,string $action,array $normalized,array $plan,array $mapping,
        ?array $target,?MerchantContext $ctx,?int $customer,int $now,?int $deadline):array
    {
        $data=['contract_version'=>1,'root_order_id'=>Facts::integer($root['id'],1,self::INT_MAX),'child_order_id'=>$childId,
            'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX),
            'action'=>$action,'idem_key'=>$normalized['idem_key'],'request_digest'=>hash('sha256',Facts::json($normalized['request'],65536),true),
            'source_snapshot_digest'=>self::sourceDigest($plan),'allocation_digest'=>'',
            'request_payload'=>Facts::json($normalized['request'],65536),'result_payload'=>null,'state'=>0,
            'ship_child_order_id'=>$action==='ship'?$childId:null,'target_operation_id'=>$target['id']??null,
            'target_action'=>$action==='ship'?null:'ship','actor_type'=>$action==='ship'?'employee':($customer===null?'system':'customer'),
            'actor_id'=>$action==='ship'?Facts::integer($ctx?->employeeId,1,self::UINT_MAX):$customer,
            'created_at'=>$now,'committed_at'=>null,'shipped_at'=>$action==='ship'?$now:null,
            'receipt_due_at'=>$action==='ship'?$deadline:null,'received_at'=>$action==='receive'?$now:null];
        $data['allocation_digest']=self::allocationDigest($data,$plan,$mapping);return $data;
    }

    private static function rangeData(array $operation,array $allocation,array $mapping):array
    {
        $row=['operation_id'=>$operation['id'],'operation_action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'child_order_id'=>$operation['child_order_id'],'uid'=>$operation['uid'],'mer_id'=>$operation['mer_id'],
            'source_item_id'=>$allocation['source_item_id'],'child_item_id'=>Facts::integer($mapping['child_item_id'],1,self::INT_MAX),
            'reserve_id'=>Facts::u64($allocation['reserve_id']),'source_ship_range_id'=>$mapping['source_ship_range_id'],
            'source_range_action'=>$operation['action']==='ship'?null:'ship'];
        foreach(['product_id','sku_id','sku_type','sku_unique','cart_ref','original_quantity','unit_start','unit_end','quantity',
            'unit_price','price_type','goods','postage','origin_postage','payable','cost','base_goods','discount'] as$key)$row[$key]=$allocation[$key];
        return $row;
    }

    private static function result(array $operation,array $root,array $child,array $plan,array $ranges,array $summary,int $committedAt):array
    {
        $public=['schema'=>1,'operation_id'=>$operation['id'],'action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'root_order_no'=>Facts::text($root['order_id'],32,true),'child_order_id'=>$operation['child_order_id'],
            'child_order_no'=>Facts::text($child['order_id'],32,true),'quantity'=>$plan['totals']['quantity'],
            'created_at'=>$operation['created_at'],'committed_at'=>$committedAt,'shipped_at'=>$operation['shipped_at'],
            'receipt_due_at'=>$operation['receipt_due_at'],'received_at'=>$operation['received_at'],'fulfillment_summary'=>$summary];
        $internal=['schema'=>1,'operation_id'=>$operation['id'],'action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'root_order_no'=>$public['root_order_no'],'child_order_id'=>$operation['child_order_id'],'child_order_no'=>$public['child_order_no'],
            'target_operation_id'=>$operation['target_operation_id'],'actor_type'=>$operation['actor_type'],'actor_id'=>$operation['actor_id'],
            'created_at'=>$operation['created_at'],'committed_at'=>$committedAt,'shipped_at'=>$operation['shipped_at'],
            'receipt_due_at'=>$operation['receipt_due_at'],'received_at'=>$operation['received_at'],
            'plan'=>$plan,'ranges'=>$ranges,'summary'=>$summary,'public_result'=>$public];
        Facts::json($internal,1048576);return $internal;
    }

    private function deadline(int $now):int
    {
        $rows=$this->configuration->getConfigAll(['system_delivery_time']);
        if(!is_array($rows)||!array_key_exists('system_delivery_time',$rows)||!is_string($rows['system_delivery_time'])
            ||$rows['system_delivery_time']==='')self::unavailable();
        try{$value=json_decode($rows['system_delivery_time'],true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(Throwable $error){self::unavailable();}
        if(is_int($value)&&$value>=0)$digits=(string)$value;
        elseif(is_string($value)&&preg_match('/\A[0-9]+\z/D',$value)===1)$digits=$value;
        else self::unavailable();
        $days=bcadd($digits,'0',0);if($days==='0')return 0;
        $seconds=bcmul($days,'86400',0);
        if(bccomp($seconds,bcsub((string)self::UINT_MAX,(string)$now,0),0)>0)self::unavailable();
        return Facts::integer(bcadd((string)$now,$seconds,0),$now,self::UINT_MAX);
    }

    private function defaultConnection():array
    {
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO)self::unavailable();$this->outside($connection,$pdo);return [$connection,$pdo];
    }
    private function outside(ConnectionInterface $connection,PDO $pdo):void
    {
        $this->connection($connection,$pdo);if($pdo->inTransaction())self::unavailable();
    }
    private function connection(ConnectionInterface $connection,PDO $pdo):void
    {
        if(PHP_INT_SIZE<8||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }
    private function inside(ConnectionInterface $actual,ConnectionInterface $connection,PDO $pdo):void
    {
        if($actual!==$connection)self::unavailable();$this->connection($connection,$pdo);if(!$pdo->inTransaction())self::unavailable();
    }
    private static function same(array $a,array $b):bool{return Facts::json($a,1048576)===Facts::json($b,1048576);}
    private static function now():int{return Facts::integer(time(),1,self::UINT_MAX);}
    private static function pending(string $phase,int $rootId,?int $childId,?string $operationId):void
    {
        try{Log::error('MARKETPLACE_FULFILLMENT_OUTCOME_UNPROVED',
            ['phase'=>$phase,'root_order_id'=>$rootId,'child_order_id'=>$childId,'operation_id'=>$operationId]);}catch(Throwable $ignored){}
    }
    private static function unavailable():never{throw new AuthUnavailableException();}
}
