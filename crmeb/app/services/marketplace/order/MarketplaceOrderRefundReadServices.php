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
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantForbiddenException;
use PDO;
use Throwable;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\facade\Db;

/** Fixed read/quote entrypoints only. Saved A2 and private evidence objects are never rewritten by a read view. */
final class MarketplaceOrderRefundReadServices
{
    private const INT_MAX=2147483647;
    private const UINT_MAX=4294967295;
    private const ROOT_ZERO=['is_del','is_system_del','refund_status','refund_type','refund_reason_time',
        'combination_id','pink_id','seckill_id','bargain_id','advance_id','virtual_type','is_gift'];

    public function __construct(private MarketplaceOrderRefundDao $refunds,private MarketplaceOrderFulfillmentDao $families,
        private MarketplaceOrderDao $orders,private MarketplacePaymentDao $payments,private MarketplaceOrderFulfillmentServices $fulfillment,
        private MarketplaceAftersaleFileServices $files,private EmployeeAuthServices $employees,private PlatformAccessServices $platform,
        private AuditLogServices $audit){}

    public function quoteForCustomer(Request $request,int $actualUid,mixed $orderId,array $query):array
    {
        $identity=$this->customer($request,$actualUid);$id=MarketplaceOrderRefundInput::id($orderId);
        return $this->read($request,$identity,'quote',$id,$query,null,null);
    }
    public function pageForCustomer(Request $request,int $actualUid,array $query):array
    {
        $identity=$this->customer($request,$actualUid);$normal=$this->query($request,'customer',$query);
        return $this->read($request,$identity,'customer_page',null,$normal,null,null);
    }
    public function detailForCustomer(Request $request,int $actualUid,string $refundNo):array
    {
        $identity=$this->customer($request,$actualUid);$this->detailRequest($request);self::text($refundNo,50);
        return $this->read($request,$identity,'customer_detail',$refundNo,[],null,null);
    }
    public function pageForMerchant(Request $request,MerchantContext $context,array $query):array
    {
        $identity=$this->merchant($request,$context);$this->employees->authorizeAction($context,'order.read',false);
        $normal=$this->query($request,'merchant',$query);
        return $this->read($request,$identity,'merchant_page',null,$normal,$context,null);
    }
    public function detailForMerchant(Request $request,MerchantContext $context,mixed $refundId):array
    {
        $identity=$this->merchant($request,$context);$this->employees->authorizeAction($context,'order.read',false);
        $this->detailRequest($request);$id=MarketplaceOrderRefundInput::id($refundId);
        return $this->read($request,$identity,'merchant_detail',$id,[],$context,null);
    }
    public function pageForPlatform(Request $request,PlatformScope $scope,array $query):array
    {
        $identity=$this->administrator($request,$scope,'marketplace-aftersales-list');
        $this->platform->authorizeScope($scope,'marketplace-aftersales-list');$normal=$this->query($request,'platform',$query);
        return $this->read($request,$identity,'platform_page',null,$normal,null,$scope);
    }
    public function detailForPlatform(Request $request,PlatformScope $scope,mixed $refundId):array
    {
        $identity=$this->administrator($request,$scope,'marketplace-aftersales-read');
        $this->platform->authorizeScope($scope,'marketplace-aftersales-read');$this->detailRequest($request);
        return $this->read($request,$identity,'platform_detail',MarketplaceOrderRefundInput::id($refundId),[],null,$scope);
    }

    private function read(Request $request,array $identity,string $kind,int|string|null $locator,array $query,
        ?MerchantContext $merchant,?PlatformScope $scope):array
    {
        [$connection,$pdo,$prefix]=$this->defaultConnection();$options=['now'=>self::integer(time(),1,self::UINT_MAX),
            'timezone'=>date_default_timezone_get(),'config'=>[],'status_images'=>[]];
        $enabled=Config::get('marketplace.aftersale.enabled',false)===true;
        $set=false;$began=false;$committing=false;$auditResource=null;
        try{
            $this->outside($connection,$pdo,$prefix);$this->sameIdentity($request,$identity,$kind,$merchant,$scope);
            $set=true;if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false)self::unavailable();
            $this->outside($connection,$pdo,$prefix);if($pdo->beginTransaction()!==true)self::unavailable();$began=true;
            $this->snapshot($connection,$pdo,$prefix);
            $ready=$this->families->readAftersaleStructureReadiness($connection,$pdo);self::ready($ready);
            $rights=$this->readAuthorization($connection,$pdo,$prefix,$request,$identity,$kind,$merchant,$scope);
            $selectedChild=null;$selected=[];$count=0;
            if($kind==='quote'){
                if(!is_int($locator))self::unavailable();
                $located=$this->refunds->readCustomerQuoteOrder($connection,$pdo,$identity['actor_id'],$locator);
                if($located===null)self::unavailable();$root=$located;
                if(self::integer($located['pid'],0,self::INT_MAX)>0){
                    $selectedChild=$located;$root=$this->refunds->readCustomerQuoteOrder($connection,$pdo,$identity['actor_id'],self::id($located['pid']));
                    if($root===null)self::unavailable();
                    self::storedIdentity($located,['uid'=>$identity['actor_id'],'mer_id'=>self::integer($root['mer_id'],1,self::UINT_MAX),
                        'pid'=>self::id($root['id']),'marketplace_trade_version'=>1]);
                }
                if(self::integer($root['pid'],0,0)!==0)self::unavailable();
                $query=$this->query($request,'quote',$query);$roots=[$root];
            }else{
                if(str_ends_with($kind,'_page')){
                    $page=$query['page'];$limit=$query['limit'];
                    $native=match($kind){
                        'customer_page'=>$this->refunds->readCustomerRefundPage($connection,$pdo,$identity['actor_id'],$query,$page,$limit),
                        'merchant_page'=>$merchant===null?self::unavailable():$this->refunds->readMerchantRefundPage($connection,$pdo,$merchant->merId,$query,$page,$limit),
                        'platform_page'=>$this->refunds->readPlatformRefundPage($connection,$pdo,$query,$page,$limit),default=>self::unavailable()};
                    self::keys($native,['count','list']);$selected=$native['list'];$count=self::integer($native['count'],0,PHP_INT_MAX);
                }else{
                    $row=match($kind){
                        'customer_detail'=>is_string($locator)?$this->refunds->readCustomerRefundByNo($connection,$pdo,$identity['actor_id'],$locator):self::unavailable(),
                        'merchant_detail'=>$merchant!==null&&is_int($locator)?$this->refunds->readMerchantRefundById($connection,$pdo,$merchant->merId,$locator):self::unavailable(),
                        'platform_detail'=>is_int($locator)?$this->refunds->readPlatformRefundById($connection,$pdo,$locator):self::unavailable(),default=>self::unavailable()};
                    if($row===null)self::unavailable();$selected=[$row];$count=1;
                }
                self::list($selected);$rootMap=$this->refunds->readRootsForRefunds($connection,$pdo,$selected);$roots=array_values($rootMap);
            }
            $this->snapshot($connection,$pdo,$prefix);$ids=[];
            foreach($roots as$root){
                $id=self::id($root['id']??null);if(isset($ids[$id]))self::unavailable();$ids[$id]=true;
                self::storedIdentity($root,['marketplace_trade_version'=>1,'pid'=>0]);
                if($identity['actor_type']==='customer')self::storedIdentity($root,['uid'=>$identity['actor_id']]);
                if($merchant!==null)self::storedIdentity($root,['mer_id'=>$merchant->merId]);
            }
            $payments=$this->payments->readObservationsForRoots($pdo,$roots);$this->snapshot($connection,$pdo,$prefix);
            $facts8=$this->orders->readPaymentApplicationCaptureFactsBatch($connection,$pdo,$roots);$this->snapshot($connection,$pdo,$prefix);
            $families=$this->families->readFulfillmentFamiliesForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo,$prefix);
            $refunds=$this->refunds->readRefundsForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo,$prefix);
            $actions=$this->refunds->readActionsForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo,$prefix);
            $logs=$this->refunds->readStatusLogsForRoots($connection,$pdo,$roots);$this->snapshot($connection,$pdo,$prefix);
            foreach([$payments,$facts8,$families,$refunds,$actions,$logs] as$map)if(array_keys($map)!==array_keys($ids))self::unavailable();
            $snapshots=[];
            foreach($roots as$root){
                $id=self::id($root['id']);$owner=self::id($root['uid']);$observation=$payments[$id];
                self::keys($observation,['locked','root_payment_consistent']);
                if($observation['root_payment_consistent']!==true||!is_array($observation['locked']))self::unavailable();
                self::paid($root,$observation['locked']);$capture=$this->orders->capturePaymentApplicationFactsFromFacts($owner,$id,$facts8[$id]);
                if($capture['root']!==$root)self::unavailable();$raw=$families[$id];
                self::keys($raw,['children','child_items','operations','ranges']);foreach($raw as$rows)self::list($rows);
                $family=['children'=>$raw['children'],'items'=>$raw['child_items'],'ranges'=>$raw['ranges'],'refunds'=>$refunds[$id]];
                $cut=$actions[$id]===[]?null:self::big($actions[$id][count($actions[$id])-1]['id']);
                $context=['schema'=>2,'cut_id'=>$cut,'actions'=>$actions[$id],'refunds'=>$refunds[$id],
                    'operations'=>$raw['operations'],'ranges'=>$raw['ranges'],'logs'=>$logs[$id]];
                $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$observation['locked']);
                $this->fulfillment->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);
                $bound=$this->files->readClaimedForRefunds($connection,$pdo,$root,$refunds[$id]);
                self::fileCorrespondence($refunds[$id],$bound);$this->snapshot($connection,$pdo,$prefix);
                $snapshots[$id]=['root'=>$root,'capture'=>$capture,'raw'=>$raw,'family'=>$family,'context'=>$context,
                    'inspection'=>$inspection,'observation'=>$observation,'ready'=>self::currentActionsReady($root,$observation['locked'])];
            }
            if($kind==='quote'){
                if(count($snapshots)!==1)self::unavailable();$snapshot=reset($snapshots);
                if($selectedChild!==null){
                    $nativeChildren=self::indexRows($snapshot['family']['children']);$cid=self::id($selectedChild['id']);
                    if(!isset($nativeChildren[$cid])||$nativeChildren[$cid]!==$selectedChild)self::unavailable();
                }
                $capacity=MarketplaceOrderRefundFacts::quoteSelectionFromInspection($snapshot['inspection']);
                $packages=$this->packages($connection,$pdo,$prefix,$snapshot,$query['fulfillment_page'],$query['fulfillment_limit'],$capacity['returns_by_child'],$options);
                $root=$snapshot['root'];$data=['schema'=>2,'scope'=>'marketplace_aftersale_quote_v1',
                    'root'=>['id'=>self::id($root['id']),'order_no'=>self::text($root['order_id'],32),
                        'merchant_name_snapshot'=>$root['merchant_name_snapshot']],
                    'capacity'=>$capacity['capacity'],'root_items'=>$capacity['root_items'],'packages'=>$packages,
                    'allowed_actions'=>['apply'=>$snapshot['ready']&&$enabled&&bccomp($capacity['capacity']['available'],'0.00',2)>0]];
            }else{
                $views=[];
                foreach($selected as$row){
                    $rid=self::id($row['id']);$rootId=self::id($row['marketplace_root_order_id']);$snapshot=$snapshots[$rootId]??null;
                    if($snapshot===null)self::unavailable();$complete=self::indexRows($snapshot['family']['refunds']);
                    if(!isset($complete[$rid])||$complete[$rid]!==$row)self::unavailable();
                    $view=MarketplaceOrderRefundFacts::refundReadViewFromInspection($snapshot['inspection'],$rid);
                    $view['allowed_actions']=self::viewActions($snapshot,$rid,$identity,$rights);
                    if(!$snapshot['ready']&&$view['return_selection']!==null)foreach($view['return_selection']['items'] as&$item){
                        $item['eligible_quantity']=0;$item['eligible_segments']=[];
                    }unset($item);
                    $views[]=$view;
                }
                $data=str_ends_with($kind,'_page')?['schema'=>2,'page'=>$query['page'],'limit'=>$query['limit'],'count'=>$count,'list'=>$views]:($views[0]??self::unavailable());
                if($scope!==null)$auditResource=str_ends_with($kind,'_page')?['mer'=>null,'id'=>'global']:
                    ['mer'=>self::integer($selected[0]['mer_id'],1,self::UINT_MAX),'id'=>(string)self::id($selected[0]['id'])];
            }
            if($this->families->readAftersaleStructureReadiness($connection,$pdo)!==$ready||date_default_timezone_get()!==$options['timezone'])self::unavailable();
            $this->sameIdentity($request,$identity,$kind,$merchant,$scope);$this->snapshot($connection,$pdo,$prefix);$committing=true;
            if($pdo->commit()!==true)self::unavailable();$began=false;$this->outside($connection,$pdo,$prefix);
        }catch(Throwable $error){
            if($set){
                $clean=false;$rolled=false;
                try{
                    if($pdo->inTransaction()){$rolled=$pdo->rollBack()===true;if(!$rolled||$pdo->inTransaction())self::unavailable();}
                    if($began&&!$committing&&$rolled){$this->outside($connection,$pdo,$prefix);$clean=true;}
                }catch(Throwable $ignored){}
                if(!$clean){try{$connection->close();}catch(Throwable $ignored){}}
            }
            self::unavailable();
        }
        $this->sameIdentity($request,$identity,$kind,$merchant,$scope);
        if($scope!==null){
            if($auditResource===null)self::unavailable();
            $this->auditRead($connection,$pdo,$prefix,$request,$identity,$kind,$scope,$auditResource);
        }
        if($kind==='quote')$data=self::quoteImages($data);
        return $data;
    }

    private function packages(ConnectionInterface $connection,PDO $pdo,string $prefix,array $snapshot,int $page,int $limit,
        array $returnSelections,array $options):array
    {
        $root=$snapshot['root'];$inspection=$snapshot['inspection'];$family=$snapshot['family'];
        $native=$this->families->readFulfillmentChildrenPage($connection,$pdo,$root,$page,$limit);$this->snapshot($connection,$pdo,$prefix);
        self::keys($native,['count','list']);self::list($native['list']);$ships=array_values($inspection['fulfillment']['ships']);
        usort($ships,static fn(array $a,array $b):int=>bccomp($b['id'],$a['id'],0));
        if(!is_int($native['count'])||$native['count']!==count($ships)||$page-1>intdiv(PHP_INT_MAX,$limit))self::unavailable();
        $expected=array_slice($ships,($page-1)*$limit,$limit);if(count($expected)!==count($native['list']))self::unavailable();
        $rawOperations=[];
        foreach($snapshot['raw']['operations'] as$operation){$id=self::big($operation['id']);if(isset($rawOperations['o:'.$id]))self::unavailable();$rawOperations['o:'.$id]=$operation;}
        $children=self::indexRows($family['children']);$s1=null;$list=[];
        if($inspection['last_action_id']===null){
            $legacyLogs=array_values(array_filter($snapshot['context']['logs'],static fn(array $row):bool=>
                in_array($row['change_type'],['marketplace_fulfillment_ship','marketplace_fulfillment_receive'],true)));
            $s1=$this->fulfillment->readHistoryFromFacts($root,$snapshot['observation'],$inspection['credit_facts'],$family,
                $snapshot['raw']['operations'],$legacyLogs);
        }
        foreach($native['list'] as$index=>$raw){
            $id=self::big($raw['id']);$childId=self::id($raw['child_order_id']);$op=$expected[$index];
            if($id!==$op['id']||$childId!==$op['child_order_id']||!isset($rawOperations['o:'.$id])||$raw!==$rawOperations['o:'.$id]
                ||!isset($children[$childId])||!isset($returnSelections[$childId]))self::unavailable();
            $childItems=[];foreach($family['items'] as$item)if(self::id($item['oid'])===$childId)$childItems[]=$item;
            if($inspection['last_action_id']===null){
                if($s1===null)self::unavailable();$package=MarketplaceOrderSnapshotProjection::fulfillmentChildFromFacts($root,$snapshot['capture']['items'],
                    $children[$childId],$childItems,$s1,$options,false);
            }else $package=MarketplaceOrderSnapshotProjection::fulfillmentChildAftersaleFromFacts($root,$snapshot['capture']['items'],
                $children[$childId],$childItems,$inspection,$options,false);
            $selection=$returnSelections[$childId];
            if(!$snapshot['ready'])foreach($selection['items'] as&$item){$item['eligible_quantity']=0;$item['eligible_segments']=[];}unset($item);
            $list[]=['package'=>$package,'return_selection'=>$selection];
        }
        return ['page'=>$page,'limit'=>$limit,'count'=>$native['count'],'list'=>$list];
    }

    private function readAuthorization(ConnectionInterface $connection,PDO $pdo,string $prefix,Request $request,array $identity,string $kind,
        ?MerchantContext $merchant,?PlatformScope $scope):array
    {
        $this->sameIdentity($request,$identity,$kind,$merchant,$scope);$rights=['approve'=>false,'reject'=>false,'accept_return'=>false];
        if($identity['actor_type']==='customer')$this->refunds->readCustomerStatus($connection,$pdo,$identity['actor_id']);
        elseif($merchant!==null){
            $this->employees->authorizeAction($merchant,'order.read',false);
            try{$this->employees->authorizeAction($merchant,'aftersale.handle',false);$rights=['approve'=>true,'reject'=>true,'accept_return'=>true];}
            catch(MerchantForbiddenException $error){}
        }elseif($scope!==null){
            $this->platform->authorizeScope($scope,self::permission($kind));$rights=$this->platform->aftersaleReadActions($scope);
        }else self::unavailable();
        self::keys($rights,['approve','reject','accept_return']);foreach($rights as$value)if(!is_bool($value))self::unavailable();
        $this->snapshot($connection,$pdo,$prefix);return $rights;
    }

    private function auditRead(ConnectionInterface $connection,PDO $pdo,string $prefix,Request $request,array $identity,string $kind,
        PlatformScope $scope,array $resource):void
    {
        try{
            $this->outside($connection,$pdo,$prefix);
            $connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$prefix,$request,$identity,$kind,$scope,$resource):void{
                if($actual!==$connection)self::unavailable();$this->writer($connection,$pdo,$prefix);
                $this->sameIdentity($request,$identity,$kind,null,$scope);$permission=self::permission($kind);
                $this->platform->authorizeScope($scope,$permission);
                $this->audit->record('admin',$scope->adminId,$resource['mer'],$permission,'aftersale',$resource['id'],'success');
                $this->writer($connection,$pdo,$prefix);$this->sameIdentity($request,$identity,$kind,null,$scope);
            });
            $this->outside($connection,$pdo,$prefix);$this->sameIdentity($request,$identity,$kind,null,$scope);
        }catch(Throwable $error){self::unavailable();}
    }

    private static function viewActions(array $snapshot,int $refundId,array $identity,array $rights):array
    {
        $out=['withdraw'=>false,'submit_return'=>false,'approve'=>false,'reject'=>false,'accept_return'=>false];
        if(!$snapshot['ready'])return $out;$refund=$snapshot['inspection']['history']['refunds'][$refundId]??null;
        if(!is_array($refund))self::unavailable();$state=$refund['after'];$requested=$state['business_state']==='REQUESTED';
        $unprocessed=$state['confirmed_amount']==='0.00'&&$state['finance_state']==='NOT_SUBMITTED'&&$refund['accepted']===[];
        $return=$refund['kind']==='return_goods'&&$state['business_state']==='APPROVED';
        if($identity['actor_type']==='customer'){$out['withdraw']=$requested&&$unprocessed;$out['submit_return']=$return;}
        else{
            $out['approve']=$rights['approve']&&$requested;$out['reject']=$rights['reject']&&$requested&&$unprocessed;
            $out['accept_return']=$rights['accept_return']&&$return&&self::quantity($refund['accepted'])<self::quantity($refund['allocations']);
        }
        return $out;
    }

    private static function currentActionsReady(array $root,array $payment):bool
    {
        $ready=true;foreach(self::ROOT_ZERO as$key)if(self::integer($root[$key]??null,0,self::UINT_MAX)!==0)$ready=false;
        if(($root['refund_price']??null)!=='0.00'||self::integer($root['shipping_type']??null,1,self::UINT_MAX)!==1)$ready=false;
        $registry=$payment['registry'];
        if(($registry['fulfillment_hold']??null)!==0||($registry['hold_reason']??null)!==null
            ||($registry['hold_attempt_id']??null)!==null||($registry['hold_at']??null)!==null)$ready=false;
        return $ready;
    }

    private static function paid(array $root,array $payment):void
    {
        self::integer($root['paid']??null,1,1);self::integer($root['is_cancel']??null,0,0);
        foreach($payment['root'] as$key=>$value){if(!array_key_exists($key,$root))self::unavailable();
            if(is_int($value)){if(self::integer($root[$key],0,self::UINT_MAX)!==$value)self::unavailable();}
            elseif($root[$key]!==$value)self::unavailable();}
        if(($payment['root']['paid']??null)!==1)self::unavailable();
    }

    private static function fileCorrespondence(array $refunds,array $files):void
    {
        $ids=[];foreach($refunds as$row)$ids[]=self::id($row['id']);if(array_keys($files)!==$ids)self::unavailable();
        foreach($refunds as$row){
            $list=$files[self::id($row['id'])];self::list($list);
            try{$images=json_decode($row['refund_img'],false,16,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
            if(!is_array($images)||!array_is_list($images)||count($images)!==count($list)||count($images)>9)self::unavailable();
            foreach($list as$index=>$file){
                self::keys($file,['id','uid','root_order_id','mer_id','refund_id','image_position','object_key','media_type','byte_size',
                    'width','height','content_sha256','upload_key','state','created_at','claim_expires_at','claimed_at','purged_at']);
                if(!is_string($images[$index])||self::big($file['id'])!==$images[$index])self::unavailable();
                self::storedIdentity($file,['uid'=>self::id($row['uid']),'root_order_id'=>self::id($row['marketplace_root_order_id']),
                    'mer_id'=>self::integer($row['mer_id'],1,self::UINT_MAX),'refund_id'=>self::id($row['id']),'image_position'=>$index]);
                if($file['state']!=='CLAIMED'||$file['purged_at']!==null||!is_string($file['content_sha256'])||strlen($file['content_sha256'])!==32)self::unavailable();
            }
        }
    }

    private function query(Request $request,string $kind,array $provided):array
    {
        if($request->method(true)!=='GET'||$request->getContent()!=='')self::unavailable();$actual=$request->get(false);
        if(!is_array($actual))self::unavailable();
        $normalize=static fn(array $query):array=>match($kind){'quote'=>MarketplaceOrderRefundInput::quote($query),
            'customer'=>MarketplaceOrderRefundInput::customerPage($query),'merchant'=>MarketplaceOrderRefundInput::merchantPage($query),
            'platform'=>MarketplaceOrderRefundInput::platformPage($query),default=>self::unavailable()};
        $first=$normalize($actual);if(MarketplaceOrderInput::canonical($first)!==MarketplaceOrderInput::canonical($normalize($provided)))self::unavailable();return $first;
    }
    private function detailRequest(Request $request):void
    {
        if($request->method(true)!=='GET'||$request->getContent()!==''||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='')self::unavailable();
    }
    private function current(Request $request,string $app):string
    {
        if(app()->request!==$request||app()->http->getName()!==$app)self::unavailable();$fp=$request->verifiedTokenFingerprint();
        if(preg_match('/\A[a-f0-9]{64}\z/D',$fp)!==1)self::unavailable();return $fp;
    }
    private function customer(Request $request,int $uid):array
    {
        $fp=$this->current($request,'api');self::id($uid);
        if($request->isLogin()!==true||self::id($request->uid())!==$uid)self::unavailable();
        return ['app'=>'api','actor_type'=>'customer','actor_id'=>$uid,'session_sha256'=>$fp];
    }
    private function merchant(Request $request,MerchantContext $context):array
    {
        $fp=$this->current($request,'merchantapi');if($request->merchantContext()!==$context)self::unavailable();
        self::integer($context->merId,1,self::UINT_MAX);self::integer($context->employeeId,1,self::UINT_MAX);$context->require('order.read');
        return ['app'=>'merchantapi','actor_type'=>'employee','actor_id'=>$context->employeeId,'session_sha256'=>$fp];
    }
    private function administrator(Request $request,PlatformScope $scope,string $permission):array
    {
        $fp=$this->current($request,'adminapi');
        if($request->platformScope()!==$scope||$scope->permission!==$permission||$scope->targetMerId!==null
            ||$request->isAdminLogin()!==true||self::integer($request->adminId(),1,self::UINT_MAX)!==$scope->adminId)self::unavailable();
        return ['app'=>'adminapi','actor_type'=>'admin','actor_id'=>$scope->adminId,'session_sha256'=>$fp];
    }
    private function sameIdentity(Request $request,array $identity,string $kind,?MerchantContext $merchant,?PlatformScope $scope):void
    {
        $actual=match($identity['app']){'api'=>$this->customer($request,$identity['actor_id']),
            'merchantapi'=>$merchant===null?self::unavailable():$this->merchant($request,$merchant),
            'adminapi'=>$scope===null?self::unavailable():$this->administrator($request,$scope,self::permission($kind)),default=>self::unavailable()};
        if($actual!==$identity)self::unavailable();
    }
    private static function permission(string $kind):string
    {
        return match($kind){'platform_page'=>'marketplace-aftersales-list','platform_detail'=>'marketplace-aftersales-read',default=>self::unavailable()};
    }

    private function defaultConnection():array
    {
        $connection=Db::connect();if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::unavailable();
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO)self::unavailable();$prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::unavailable();
        $this->outside($connection,$pdo,$prefix);return [$connection,$pdo,$prefix];
    }
    private function connection(ConnectionInterface $connection,PDO $pdo,string $prefix):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('prefix')!==$prefix||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }
    private function outside(ConnectionInterface $connection,PDO $pdo,string $prefix):void
    {
        $this->connection($connection,$pdo,$prefix);if($connection->transactionDepth()!==0||$pdo->inTransaction())self::unavailable();
    }
    private function snapshot(ConnectionInterface $connection,PDO $pdo,string $prefix):void
    {
        $this->connection($connection,$pdo,$prefix);if($connection->transactionDepth()!==0||!$pdo->inTransaction())self::unavailable();
    }
    private function writer(ConnectionInterface $connection,PDO $pdo,string $prefix):void
    {
        $this->connection($connection,$pdo,$prefix);if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::unavailable();
    }
    private static function ready(array $ready):void
    {
        self::keys($ready,['schema','state']);if($ready!==['schema'=>2,'state'=>'READY'])self::unavailable();
    }
    private static function quoteImages(array $quote):array
    {
        foreach($quote['packages']['list'] as&$entry)foreach($entry['package']['items'] as&$item){
            $thumb=get_thumb_water(['image'=>$item['image']]);if(!is_array($thumb)||!is_string($thumb['image']??null))self::unavailable();$item['image']=$thumb['image'];
        }unset($entry,$item);return $quote;
    }
    private static function indexRows(array $rows):array
    {
        self::list($rows);$out=[];foreach($rows as$row){if(!is_array($row))self::unavailable();$id=self::id($row['id']??null);
            if(isset($out[$id]))self::unavailable();$out[$id]=$row;}return $out;
    }
    private static function quantity(array $parts):int
    {
        $out=0;foreach($parts as$part){$out+=$part['unit_end']-$part['unit_start'];self::integer($out,0,self::UINT_MAX);}return $out;
    }
    private static function storedIdentity(array $row,array $expected):void
    {
        foreach($expected as$key=>$value)if(!array_key_exists($key,$row)||self::integer($row[$key],0,self::UINT_MAX)!==$value)self::unavailable();
    }
    private static function list(mixed $value):void {if(!is_array($value)||!array_is_list($value))self::unavailable();}
    private static function keys(array $value,array $expected):void
    {
        $keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected)self::unavailable();
    }
    private static function integer(mixed $value,int $min,int $max):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&bccomp($value,(string)$max,0)<=0)$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::unavailable();return $value;
    }
    private static function id(mixed $value):int {return self::integer($value,1,self::INT_MAX);}
    private static function big(mixed $value):string
    {
        if(is_int($value)&&$value>0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1||bccomp($value,'18446744073709551615',0)>0)self::unavailable();return $value;
    }
    private static function text(mixed $value,int $max):string
    {
        if(!is_string($value)||$value===''||strlen($value)>$max||str_contains($value,"\0")||preg_match('//u',$value)!==1)self::unavailable();return $value;
    }
    private static function unavailable():never {throw new AuthUnavailableException();}
}
