<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
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

/** Durable Task6/Task7 fulfillment; routes and Timer registration stay outside, with no payment or stock writes. */
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
        private EmployeeAuthServices $auth,private StoreOrderCreateServices $numbers,private SystemConfigDao $configuration,private MarketplaceOrderRefundDao $refunds){}

    /**
     * Pure native-family readback after the actual complete Context2 inspection.
     * This is neither authentication nor a replacement for the caller's owned locks and final inspection.
     */
    public function validateAftersaleFamilyFromFacts(array $root,array $capture,array $family,array $inspection):void
    {
        if(PHP_INT_SIZE<8)self::unavailable();
        Facts::keys($capture,['root','items','selection','reserves','credit_rows','child_orders']);
        Facts::keys($family,['children','items','ranges','refunds']);
        Facts::keys($inspection,['source','winner','actions','fulfillment','history','summary','aftersale','credit_facts','last_action_id','last_operation_id']);
        if(!is_array($capture['root'])||$capture['root']!==$root)self::unavailable();
        foreach(['items','selection','reserves','credit_rows','child_orders'] as$key)
            if(!is_array($capture[$key])||!array_is_list($capture[$key]))self::unavailable();
        foreach($family as$rows)if(!is_array($rows)||!array_is_list($rows))self::unavailable();
        foreach(['source','fulfillment','history','summary','credit_facts'] as$key)if(!is_array($inspection[$key]))self::unavailable();
        $source=$inspection['source'];$fulfillment=$inspection['fulfillment'];$history=$inspection['history'];
        Facts::keys($source,['root','identity','items','lines','groups','reserves','child_orders','capture','plan','commercial']);
        foreach($source as$value)if(!is_array($value))self::unavailable();
        if($source['root']!==$root||$source['capture']!==$capture)self::unavailable();
        Facts::stored($root,['id'=>Facts::integer($source['identity']['id']??null,1,self::INT_MAX),
            'uid'=>Facts::integer($source['identity']['uid']??null,1,self::INT_MAX),
            'mer_id'=>Facts::integer($source['identity']['mer_id']??null,1,self::UINT_MAX),
            'order_id'=>Facts::text($source['identity']['order_no']??null,32,true),'marketplace_trade_version'=>1,'pid'=>0]);
        $items=self::indexRows($capture['items'],'id');$children=self::indexRows($family['children'],'id');
        $childItems=self::indexRows($family['items'],'id');$heads=self::indexRows($capture['child_orders'],'id');
        if($source['items']!==$items||$source['child_orders']!==$heads||array_keys($children)!==array_keys($heads))self::unavailable();
        foreach($family['children'] as$child)foreach($child as$key=>$value)
            if(!is_string($key)||($value!==null&&!is_int($value)&&!is_string($value)))self::unavailable();
        foreach($family['items'] as$item)foreach($item as$key=>$value)
            if(!is_string($key)||($value!==null&&!is_int($value)&&!is_string($value)))self::unavailable();
        foreach($heads as$id=>$head){Facts::keys($head,['id','uid','mer_id','pid','marketplace_trade_version','order_id','paid','status','refund_status','is_cancel']);
            Facts::stored($children[$id],$head);}
        // This leaf retains the single original 34-column mirror contract, including the complete empty set.
        MarketplaceOrderRefundFacts::validateRefundMirrorsFromInspection($family['refunds'],$inspection);
        Facts::keys($fulfillment,['operations','ranges','ships']);
        if(!is_array($fulfillment['operations'])||!array_is_list($fulfillment['operations'])
            ||!is_array($fulfillment['ranges'])||!is_array($fulfillment['ships']))self::unavailable();
        Facts::keys($history,['refunds','movements','ships','received','cancel','returned','expected_logs','fulfillment_completed_at']);
        foreach(['ships','received','cancel','returned','expected_logs'] as$key)
            if(!is_array($history[$key])||!array_is_list($history[$key]))self::unavailable();
        $commercial=MarketplaceOrderFulfillmentPlan::refundSavedSource($root,$capture['items'],$capture['reserves']);
        if($commercial!==$source['commercial'])self::unavailable();
        $ranges=[];$byOperation=[];$shipRanges=[];$last='0';$shipCount=0;$receiveCount=0;
        foreach($family['ranges'] as$raw){
            if(!is_array($raw))self::unavailable();$range=Facts::rangeRow($raw);$id=Facts::u64($range['id']);$key='r:'.$id;
            if(bccomp($id,$last,0)<=0||isset($ranges[$key])||!isset($fulfillment['ranges'][$key])
                ||!is_array($fulfillment['ranges'][$key])||!self::same($range,$fulfillment['ranges'][$key]))self::unavailable();
            $last=$id;Facts::stored($range,['root_order_id'=>Facts::integer($root['id'],1,self::INT_MAX),
                'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX)]);
            if(!isset($children[$range['child_order_id']])||!isset($childItems[$range['child_item_id']])
                ||!isset($items[$range['source_item_id']]))self::unavailable();
            Facts::stored($childItems[$range['child_item_id']],['oid'=>$range['child_order_id']]);
            $ranges[$key]=$range;$byOperation['o:'.$range['operation_id']][]=$range;
            if($range['operation_action']==='ship'){$shipRanges[$key]=$range;++$shipCount;}
            elseif($range['operation_action']==='receive')++$receiveCount;else self::unavailable();
        }
        if(count($ranges)!==count($fulfillment['ranges']))self::unavailable();
        foreach($fulfillment['ranges'] as$key=>$range)if(!isset($ranges[$key]))self::unavailable();
        $operations=[];$ships=[];$last='0';
        foreach($fulfillment['operations'] as$operation){
            if(!is_array($operation)||!is_array($operation['validated_ranges']??null)||!array_is_list($operation['validated_ranges']))self::unavailable();
            $id=Facts::u64($operation['id']??null);$key='o:'.$id;$childId=Facts::integer($operation['child_order_id']??null,1,self::INT_MAX);
            if(bccomp($id,$last,0)<=0||isset($operations[$key])||!isset($children[$childId]))self::unavailable();$last=$id;
            $actual=$byOperation[$key]??[];
            if(count($actual)!==count($operation['validated_ranges']))self::unavailable();
            foreach($actual as$index=>$range)if(!is_array($operation['validated_ranges'][$index])
                ||!self::same($range,$operation['validated_ranges'][$index]))self::unavailable();
            Facts::stored($operation,['root_order_id'=>Facts::integer($root['id'],1,self::INT_MAX),
                'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX),'state'=>1]);
            if($operation['action']==='ship'){
                $ship=$fulfillment['ships'][$childId]??null;$plain=$operation;unset($plain['validated_ranges']);
                // Keep native BINARY digests native; a JSON comparison of the operation would corrupt this boundary.
                if(!is_array($ship)||$ship!==$plain||isset($ships[$childId]))self::unavailable();$ships[$childId]=$ship;
            }elseif($operation['action']!=='receive')self::unavailable();
            $operations[$key]=$operation;
        }
        if(count($operations)!==count($byOperation)||count($ships)!==count($children)||count($ships)!==count($fulfillment['ships']))self::unavailable();
        foreach($byOperation as$key=>$rows)if(!isset($operations[$key]))self::unavailable();
        foreach($fulfillment['ships'] as$id=>$ship)if(!isset($ships[$id]))self::unavailable();
        $seenShips=[];$seenReceives=[];$covered=[];
        foreach(['ships'=>'ship','received'=>'receive'] as$historyKey=>$action){
            foreach($history[$historyKey] as$raw){
                if(!is_array($raw))self::unavailable();$part=Facts::rangeRow($raw);$key='r:'.$part['id'];
                if(!isset($ranges[$key])||$part['operation_action']!==$action||!self::same($part,$ranges[$key]))self::unavailable();
                if($action==='ship'){
                    if(isset($seenShips[$key]))self::unavailable();$seenShips[$key]=true;
                }else{
                    if(isset($seenReceives[$key]))self::unavailable();$seenReceives[$key]=true;
                    $parentKey='r:'.Facts::u64($part['source_ship_range_id']);$parent=$shipRanges[$parentKey]??null;
                    if($parent===null||$parent['child_order_id']!==$part['child_order_id']||$parent['child_item_id']!==$part['child_item_id']
                        ||$parent['source_item_id']!==$part['source_item_id']||$parent['reserve_id']!==$part['reserve_id']
                        ||$part['unit_start']<$parent['unit_start']||$part['unit_end']>$parent['unit_end']||$part['unit_end']<=$part['unit_start'])self::unavailable();
                    $covered[$parentKey][]=['start'=>$part['unit_start'],'end'=>$part['unit_end']];
                }
            }
        }
        if(count($seenShips)!==$shipCount||count($seenReceives)!==$receiveCount)self::unavailable();
        foreach($history['returned'] as$part){
            if(!is_array($part))self::unavailable();
            Facts::keys($part,['source_item_id','reserve_id','source_ship_range_id','child_order_id','child_item_id','unit_start','unit_end']);
            $parentKey='r:'.Facts::u64($part['source_ship_range_id']);$parent=$shipRanges[$parentKey]??null;
            if($parent===null||$parent['child_order_id']!==$part['child_order_id']||$parent['child_item_id']!==$part['child_item_id']
                ||$parent['source_item_id']!==$part['source_item_id']||$parent['reserve_id']!==$part['reserve_id']
                ||!is_int($part['unit_start'])||!is_int($part['unit_end'])||$part['unit_start']<$parent['unit_start']
                ||$part['unit_end']>$parent['unit_end']||$part['unit_end']<=$part['unit_start'])self::unavailable();
            $covered[$parentKey][]=['start'=>$part['unit_start'],'end'=>$part['unit_end']];
        }
        $usedItems=[];
        foreach($ships as$childId=>$ship){
            $child=$children[$childId];$request=$ship['request_payload']??null;$saved=$ship['result_payload']??null;
            if(!is_array($request)||!is_array($saved)||!is_array($saved['plan']??null))self::unavailable();$plan=$saved['plan'];
            if(!is_array($plan['allocations']??null)||!array_is_list($plan['allocations'])||$plan['allocations']===[])self::unavailable();
            $expectedPlan=MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($root,$capture['items'],$capture['reserves'],self::coordinates($plan['allocations']));
            if(!self::same($expectedPlan,$plan))self::unavailable();
            $expectedChild=self::childData($root,$plan,$request,Facts::integer($ship['shipped_at']??null,1,self::UINT_MAX),
                Facts::text($child['order_id']??null,32,true),Facts::text($child['unique']??null,32,true));
            self::childStored($child,$expectedChild,false);$closed=true;$parents=0;
            $mapping=$byOperation['o:'.$ship['id']]??[];
            foreach($mapping as$parent){
                if($parent['operation_action']!=='ship'||$parent['child_order_id']!==$childId)self::unavailable();++$parents;
                $parts=$covered['r:'.$parent['id']]??[];
                usort($parts,static fn(array $a,array $b):int=>($a['start']<=>$b['start'])?:($a['end']<=>$b['end']));
                $cursor=$parent['unit_start'];
                foreach($parts as$part){
                    if($part['end']<=$cursor)continue;
                    if($part['start']>$cursor)$closed=false;$cursor=max($cursor,$part['end']);
                }
                if($cursor<$parent['unit_end'])$closed=false;
            }
            if($parents<1||Facts::integer($child['status']??null,1,2)!==($closed?2:1))self::unavailable();
            foreach(self::itemGroups($plan) as$sourceId=>$allocations){
                if(!isset($items[$sourceId]))self::unavailable();$mapped=[];
                foreach($mapping as$range)if($range['source_item_id']===$sourceId)$mapped[$range['child_item_id']]=true;
                if(count($mapped)!==1)self::unavailable();$itemId=array_key_first($mapped);
                if(isset($usedItems[$itemId])||!isset($childItems[$itemId]))self::unavailable();$usedItems[$itemId]=true;
                $expectedItem=['id'=>$itemId,'old_cart_id'=>'']+self::childItemData($root,$childId,$ship['id'],$items[$sourceId],$allocations);
                Facts::keys($childItems[$itemId],array_keys($expectedItem));self::itemStored($childItems[$itemId],$expectedItem);
            }
        }
        if(count($usedItems)!==count($childItems))self::unavailable();
        $summary=$inspection['summary'];Facts::keys($summary,['shipping_state','receipt_state','closed','original','shipped','received',
            'cancelled_unshipped','returned_accepted','to_ship','to_receive','child_count']);
        $status=Facts::integer($summary['to_ship'],0,self::UINT_MAX)>0?0:
            (Facts::integer($summary['to_receive'],0,self::UINT_MAX)>0?1:(Facts::integer($summary['shipped'],0,self::UINT_MAX)>0?2:0));
        if(Facts::integer($root['status']??null,0,2)!==$status)self::unavailable();
    }

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

    /** Actual merchant read authorization precedes input parsing and the owned native snapshot. */
    public function readMerchantPage(MerchantContext $ctx,array $query=[]):array
    {
        $ship=$this->merchantReadAuthorization($ctx);$input=MerchantOrderInput::listing($query);
        try{return $this->runRead($ctx,$ship,$input['filter'],$input['page'],$input['limit'],null);}
        catch(\crmeb\exceptions\MerchantNotFoundException|AuthUnavailableException $error){throw $error;}
        catch(Throwable $error){self::unavailable();}
    }

    public function readMerchantRoot(MerchantContext $ctx,mixed $rootId):array
    {
        $ship=$this->merchantReadAuthorization($ctx);$id=MerchantOrderInput::id($rootId);
        try{return $this->runRead($ctx,$ship,[],1,1,$id);}
        catch(\crmeb\exceptions\MerchantNotFoundException|AuthUnavailableException $error){throw $error;}
        catch(Throwable $error){self::unavailable();}
    }

    /** These six fixed entry points receive only identities from their existing authenticated callers. */
    public function readCustomerPage(int $actualUid,array $query,int $page=0,int $limit=0):array
    {
        Facts::integer($actualUid,1,self::INT_MAX);$filter=MarketplaceOrderInput::customerRead($query);$window=MarketplaceOrderInput::readPage($page,$limit);
        return $this->runThreeRead(['kind'=>'customer_page','actor'=>$actualUid,'locator'=>null,'children_page'=>null,'children_limit'=>null,
            'options'=>$this->captureReadDisplayOptions('customer_list')],$filter,$window);
    }

    public function readPlatformPage(int $actualAdminId,array $query,int $page=0,int $limit=0):array
    {
        Facts::integer($actualAdminId,1,self::UINT_MAX);$filter=MarketplaceOrderInput::platformRead($query);$window=MarketplaceOrderInput::readPage($page,$limit);
        return $this->runThreeRead(['kind'=>'platform_page','actor'=>$actualAdminId,'locator'=>null,'children_page'=>null,'children_limit'=>null,
            'options'=>$this->captureReadDisplayOptions('platform_list')],$filter,$window);
    }

    public function readMobilePage(int $actualUid,array $query,int $page=0,int $limit=0):array
    {
        Facts::integer($actualUid,1,self::INT_MAX);$filter=MarketplaceOrderInput::mobileRead($query);$window=MarketplaceOrderInput::readPage($page,$limit);
        return $this->runThreeRead(['kind'=>'mobile_page','actor'=>$actualUid,'locator'=>null,'children_page'=>null,'children_limit'=>null,
            'options'=>$this->captureReadDisplayOptions('mobile_list')],$filter,$window);
    }

    public function readCustomerRoot(int $actualUid,string $originalLocator,mixed $childrenPage=1,mixed $childrenLimit=20):array
    {
        Facts::integer($actualUid,1,self::UINT_MAX);
        return $this->runThreeRead(['kind'=>'customer_root','actor'=>$actualUid,'locator'=>$originalLocator,'children_page'=>$childrenPage,'children_limit'=>$childrenLimit,
            'options'=>$this->captureReadDisplayOptions('customer_detail')],[],[],(bool)sys_config('order_shipping_open',0));
    }

    public function readPlatformRoot(int $actualAdminId,int|string $actualPk,mixed $childrenPage=1,mixed $childrenLimit=20):array
    {
        Facts::integer($actualAdminId,1,self::UINT_MAX);
        return $this->runThreeRead(['kind'=>'platform_root','actor'=>$actualAdminId,'locator'=>$actualPk,'children_page'=>$childrenPage,'children_limit'=>$childrenLimit,
            'options'=>$this->captureReadDisplayOptions('platform_detail')],[],[]);
    }

    public function readMobileRoot(int $actualUid,string $originalLocator,mixed $childrenPage=1,mixed $childrenLimit=20):array
    {
        Facts::integer($actualUid,1,self::UINT_MAX);
        return $this->runThreeRead(['kind'=>'mobile_root','actor'=>$actualUid,'locator'=>$originalLocator,'children_page'=>$childrenPage,'children_limit'=>$childrenLimit,
            'options'=>$this->captureReadDisplayOptions('mobile_detail')],[],[],(bool)sys_config('order_shipping_open',0));
    }

    private function runThreeRead(array $request,array $filter,array $page,bool $managedEnabled=false):array
    {
        try{return $this->runRead(null,false,$filter,0,0,null,['request'=>$request,'page'=>$page,'managed_enabled'=>$managedEnabled]);}
        catch(\crmeb\exceptions\ApiException|AuthUnavailableException $error){throw $error;}
        catch(Throwable $error){self::unavailable();}
    }

    /** Configuration, cache-backed display materials and the clock are captured before SET/begin. */
    private function captureReadDisplayOptions(string $view):array
    {
        $config=[];$images=[];
        if(!in_array($view,['customer_list','platform_list','mobile_list','customer_detail','platform_detail','mobile_detail'],true))self::unavailable();
        if(!in_array($view,['platform_list','mobile_list'],true)){
            $keys=['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time'];
            $values=\crmeb\services\SystemConfigService::more($keys);
            $values=\crmeb\utils\Arr::setValeTime($keys,is_array($values)?$values:[]);
            foreach($keys as$key)$config[$key]=$values[$key];
        }
        if(str_ends_with($view,'_detail')){
            $config['offline_pay_status']=(int)sys_config('offline_pay_status')??(int)2;
            $images=sys_data('order_details_images')?:[];
        }
        if(in_array($view,['customer_detail','mobile_detail'],true)){
            $config['store_self_mention']=sys_config('store_self_mention');
            $config['balance_func_status']=(int)sys_config('balance_func_status');
            $config['yue_pay_status']=(int)sys_config('yue_pay_status');
            $config['pay_weixin_open']=sys_config('pay_weixin_open');
            $config['ali_pay_status']=sys_config('ali_pay_status','0');
            $config['friend_pay_status']=(int)sys_config('friend_pay_status')??0;
            $config['routine_contact_type']=sys_config('routine_contact_type',0);
            $config['invoice_func_status']=(bool)sys_config('invoice_func_status',0);
            $config['special_invoice_status']=sys_config('special_invoice_status',0);
            $config['refund_time_available']=(int)sys_config('refund_time_available');
        }
        return ['now'=>time(),'timezone'=>date_default_timezone_get(),'config'=>$config,'status_images'=>$images];
    }

    public function readMerchantPackages(MerchantContext $ctx,mixed $rootId,mixed $page=1,mixed $limit=20):array
    {
        $ship=$this->merchantReadAuthorization($ctx);$id=MerchantOrderInput::id($rootId);
        try{return $this->runRead($ctx,$ship,[],1,1,$id,null,
            ['kind'=>'page','child_id'=>null,'page'=>$page,'limit'=>$limit,'options'=>self::packageReadOptions()]);}
        catch(\crmeb\exceptions\MerchantNotFoundException|\crmeb\exceptions\ApiException|AuthUnavailableException $error){throw $error;}
        catch(Throwable $error){self::unavailable();}
    }

    public function readMerchantPackage(MerchantContext $ctx,mixed $childId):array
    {
        $ship=$this->merchantReadAuthorization($ctx);$id=MerchantOrderInput::id($childId);
        try{return $this->runRead($ctx,$ship,[],1,1,null,null,
            ['kind'=>'detail','child_id'=>$id,'page'=>null,'limit'=>null,'options'=>self::packageReadOptions()]);}
        catch(\crmeb\exceptions\MerchantNotFoundException|AuthUnavailableException $error){throw $error;}
        catch(Throwable $error){self::unavailable();}
    }

    public function readCustomerPackage(int $actualUid,string $childNo):array
    {
        Facts::integer($actualUid,1,self::INT_MAX);Facts::text($childNo,32,true);
        return $this->runThreeRead(['kind'=>'customer_package','actor'=>$actualUid,'locator'=>$childNo,'children_page'=>1,'children_limit'=>20,
            'options'=>self::packageReadOptions()],[],[]);
    }

    private static function packageReadOptions():array
    {
        return ['now'=>time(),'timezone'=>date_default_timezone_get(),'config'=>[],'status_images'=>[]];
    }

    public function receivePackageForCustomer(int $actualUid,string $childNo,array $raw):array
    {
        Facts::integer($actualUid,1,self::INT_MAX);Facts::text($childNo,32,true);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertAftersaleStructure($pdo);
        $candidate=$this->dao->customerChildCandidate($pdo,$actualUid,$childNo);
        $public=MarketplaceOrderFulfillmentInput::receivePublic($raw);
        if($public['selection']['mode']==='all_eligible'){
            $normal=MarketplaceOrderFulfillmentInput::receive($candidate['root_order_id'],$candidate['child_order_id'],$public);
            return $this->run($connection,$pdo,$candidate,'receive',null,$actualUid,$normal,false);
        }
        return $this->run($connection,$pdo,$candidate,'receive',null,$actualUid,null,false,$public);
    }

    private function merchantReadAuthorization(MerchantContext $ctx):bool
    {
        $ctx->require('order.read');$this->auth->authorizeAction($ctx,'order.read',false);
        try{$this->auth->authorizeAction($ctx,'order.ship',false);return true;}
        catch(\crmeb\exceptions\MerchantForbiddenException $error){return false;}
    }

    /** Fixed business branches only: no external callback, actor mode, ORM SQL or writer call. */
    private function runRead(?MerchantContext $ctx,bool $freshShip,array $filter,int $page,int $limit,?int $rootId,?array $three=null,?array $merchantPackage=null):array
    {
        if(($three===null)!==($ctx instanceof MerchantContext)||($merchantPackage!==null&&$three!==null))self::unavailable();
        if($merchantPackage!==null){
            Facts::keys($merchantPackage,['kind','child_id','page','limit','options']);
            if(!in_array($merchantPackage['kind'],['page','detail'],true)||!is_array($merchantPackage['options']))self::unavailable();
        }
        $merchantOptions=$merchantPackage['options']??self::packageReadOptions();
        [$connection,$pdo,$prefix]=$this->readDefaultConnection();$this->dao->assertAftersaleStructure($pdo);
        $setAttempted=false;$began=false;$commitAttempted=false;$diagnostics=[];
        try{
            $this->readConnectionGate($connection,$pdo,$prefix,false);$setAttempted=true;
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false)self::unavailable();
            $this->readConnectionGate($connection,$pdo,$prefix,false);
            if($pdo->beginTransaction()!==true)self::unavailable();$began=true;
            $this->readConnectionGate($connection,$pdo,$prefix,true);
            $initialReadiness=$this->dao->readAftersaleStructureReadiness($connection,$pdo);
            if($three===null){
                $locatedPackage=null;$packagePaging=null;
                if($merchantPackage!==null&&$merchantPackage['kind']==='detail'){
                    $locatedPackage=$this->dao->readMerchantFulfillmentChild($connection,$pdo,$ctx->merId,$merchantPackage['child_id']);
                    $rootId=Facts::integer($locatedPackage['pid'],1,self::INT_MAX);
                }
                if($rootId===null)$selected=$this->dao->readMerchantRootPage($connection,$pdo,$ctx->merId,$filter,$page,$limit);
                else $selected=['list'=>[$this->dao->readMerchantRoot($connection,$pdo,$ctx->merId,$rootId)],'count'=>1];
                Facts::keys($selected,['list','count']);
                if(!is_array($selected['list'])||!array_is_list($selected['list'])||!is_int($selected['count'])
                    ||$selected['count']<0||count($selected['list'])>$limit||count($selected['list'])>$selected['count'])self::unavailable();
                $roots=$selected['list'];
                if($merchantPackage!==null){
                    if(count($roots)!==1)self::unavailable();
                    if($locatedPackage!==null)Facts::stored($locatedPackage,['pid'=>Facts::integer($roots[0]['id'],1,self::INT_MAX),
                        'uid'=>Facts::integer($roots[0]['uid'],1,self::INT_MAX),'mer_id'=>$ctx->merId]);
                    if($merchantPackage['kind']==='page')$packagePaging=self::readChildrenPage($merchantPackage['page'],$merchantPackage['limit']);
                }
                $items=$this->dao->readMerchantRootItems($connection,$pdo,$ctx->merId,$roots);
                $paidRoots=[];
                foreach($roots as$root){
                    if(!is_array($root))self::unavailable();
                    if(Facts::integer($root['paid']??null,0,1)===1)$paidRoots[]=$root;
                }
                $this->readConnectionGate($connection,$pdo,$prefix,true);
                $payments=$this->payments->readObservationsForRoots($pdo,$paidRoots);
                $this->readConnectionGate($connection,$pdo,$prefix,true);
                $snapshot=$this->readOwnedFulfillmentSnapshots($connection,$pdo,$roots,$paidRoots,$payments);
                $originals=$snapshot['originals'];$histories=$snapshot['histories'];$inspections=$snapshot['inspections'];
                $families=['readiness'=>$snapshot['readiness'],'roots'=>$snapshot['roots']];
                Facts::keys($families,['readiness','roots']);
                if(!is_array($families['roots']))self::unavailable();
                $expected=[];foreach($roots as$root)$expected[]=Facts::integer($root['id']??null,1,self::INT_MAX);
                if(array_keys($items)!==$expected||array_keys($families['roots'])!==$expected)self::unavailable();
                $expectedPaid=[];foreach($paidRoots as$root)$expectedPaid[]=Facts::integer($root['id'],1,self::INT_MAX);
                if(array_keys($payments)!==$expectedPaid||array_keys($originals)!==$expectedPaid)self::unavailable();
                $list=[];
                foreach($roots as$root){
                    $id=Facts::integer($root['id'],1,self::INT_MAX);$family=$families['roots'][$id];
                    if(!is_array($family))self::unavailable();
                    Facts::keys($family,['children','child_items','refunds','logs','operations','ranges']);
                    foreach(['children','child_items','refunds','logs'] as$key)
                        if(!is_array($family[$key])||!array_is_list($family[$key]))self::unavailable();
                    $summary=null;$ship=false;$history=null;
                    if(Facts::integer($root['paid'],0,1)===0){
                        $this->readUnpaidRoot($root,$items[$id]);
                        if($family['children']!==[]||$family['child_items']!==[]||$family['refunds']!==[]||$family['logs']!==[]
                            ||($family['operations']!==null&&$family['operations']!==[])
                            ||($family['ranges']!==null&&$family['ranges']!==[]))self::unavailable();
                        $diagnostics[]=['root_order_id'=>$id,'reason'=>Facts::integer($root['is_cancel']??null,0,1)===1?'UNPAID_CANCELLED':'NOT_PAID'];
                    }else{
                        $observation=$payments[$id];$original=$originals[$id];$history=$histories[$id]??self::unavailable();
                        self::readOriginalItemCorrespondence($items[$id],$original['items']);$summary=$history['summary'];
                        $inspection=$inspections[$id]??self::unavailable();
                        $registry=$observation['locked']['registry'];$merchant=$observation['locked']['merchant'];
                        $paused=$inspection['aftersale']['pending_cancel_quantity']??0;
                        $ship=$freshShip&&$summary['to_ship']>$paused&&$registry['fulfillment_hold']===0
                            &&$registry['hold_reason']===null&&$registry['hold_attempt_id']===null&&$registry['hold_at']===null
                            &&$merchant['review_status']===1&&$merchant['security_locked']===0
                            &&$merchant['closed_at']===null&&$merchant['fulfillment_enabled']===1;
                    }
                    $inspection=$inspections[$id]??null;$s2=$inspection!==null&&$inspection['last_action_id']!==null;
                    $rootDto=$s2?MarketplaceOrderSnapshotProjection::aftersaleRootFromFacts($rootId===null?'merchant_list':'merchant_detail',
                        $root,array_values($inspection['source']['items']),[],$merchantOptions,$inspection,null,['ship'=>$ship,'receive'=>false]):
                        MarketplaceOrderSnapshotProjection::merchantOrderWithFulfillment($root,$items[$id],$summary,['ship'=>$ship,'receive'=>false],$rootId!==null);
                    if($merchantPackage===null)$list[]=$rootDto;
                    elseif($merchantPackage['kind']==='page'){
                        if($packagePaging===null)self::unavailable();
                        $packageResult=['root'=>$rootDto,
                            'packages'=>$history===null?null:$this->packagePage($connection,$pdo,$root,$items[$id],$history,$packagePaging,$merchantPackage['options'],$s2?$inspection:null),
                            'shipping_items'=>$history===null?null:($s2?self::shippingAftersaleItems($items[$id],$inspection):self::shippingItems($items[$id],$history))];
                    }else{
                        if($history===null||$locatedPackage===null)self::unavailable();$childId=Facts::integer($locatedPackage['id'],1,self::INT_MAX);
                        if(!isset($history['children'][$childId])||!isset($history['ships_by_child'][$childId]))self::unavailable();
                        Facts::stored($history['children'][$childId],$locatedPackage);
                        $childItems=self::readChildItems($family['child_items'],$childId);
                        $packageResult=['package'=>$s2?MarketplaceOrderSnapshotProjection::fulfillmentChildAftersaleFromFacts($root,array_values($inspection['source']['items']),$history['children'][$childId],
                            $childItems,$inspection,$merchantPackage['options'],true):MarketplaceOrderSnapshotProjection::fulfillmentChildFromFacts($root,$items[$id],$history['children'][$childId],
                            $childItems,$history,$merchantPackage['options'],true)];
                    }
                }
                $observedReadiness=$families['readiness'];
            }else{
                $threeResult=$this->readThreeSnapshot($connection,$pdo,$prefix,$filter,$three,$diagnostics);
                $observedReadiness=$threeResult['readiness'];
            }
            $this->readConnectionGate($connection,$pdo,$prefix,true);
            if(($observedReadiness!==null&&$observedReadiness!==$initialReadiness)
                ||$this->dao->readAftersaleStructureReadiness($connection,$pdo)!==$initialReadiness)self::unavailable();
            if($three!==null&&date_default_timezone_get()!==$three['request']['options']['timezone'])self::unavailable();
            if($three===null&&date_default_timezone_get()!==$merchantOptions['timezone'])self::unavailable();
            $this->readConnectionGate($connection,$pdo,$prefix,true);$commitAttempted=true;
            if($pdo->commit()!==true)self::unavailable();
            $this->readConnectionGate($connection,$pdo,$prefix,false);
            $result=$three===null?($merchantPackage!==null?($packageResult??self::unavailable()):
                ($rootId===null?['list'=>$list,'count'=>$selected['count']]:($list[0]??self::unavailable()))):$threeResult['plan'];
        }catch(Throwable $error){
            if($setAttempted){
                $clean=false;$rolled=false;
                try{
                    if($pdo->inTransaction()){$rolled=$pdo->rollBack()===true;if(!$rolled||$pdo->inTransaction())self::unavailable();}
                    if($began&&!$commitAttempted&&$rolled){$this->readConnectionGate($connection,$pdo,$prefix,false);$clean=true;}
                }catch(Throwable $cleanupError){}
                if(!$clean)$this->discardOwnedReadConnection($connection);
            }
            throw $error;
        }
        if($three!==null)$result=$this->finishThreeManaged($result,$threeResult['managed_candidates'],$three['managed_enabled']);
        foreach($diagnostics as$diagnostic){
            try{Log::warning('MARKETPLACE_FULFILLMENT_READ_UNAVAILABLE',$diagnostic);}catch(Throwable $ignored){}
        }
        return $result;
    }

    /** Execute one of six private fixed selections within the existing owned lifecycle. */
    private function readThreeSnapshot(ConnectionInterface $connection,PDO $pdo,string $prefix,array $filter,array $three,array &$diagnostics):array
    {
        Facts::keys($three,['request','page','managed_enabled']);$request=$three['request'];$page=$three['page'];
        if(!is_array($request)||!is_array($page)||!is_bool($three['managed_enabled']))self::unavailable();
        Facts::keys($request,['kind','actor','locator','children_page','children_limit','options']);
        $kind=$request['kind'];$actor=Facts::integer($request['actor'],1,self::UINT_MAX);
        if(!in_array($kind,['customer_page','platform_page','mobile_page','customer_root','platform_root','mobile_root','customer_package'],true))self::unavailable();
        $listing=str_ends_with($kind,'_page');$view=$kind==='customer_package'?'customer_detail':str_replace(['_page','_root'],['_list','_detail'],$kind);
        $readiness=null;$slots=[];$count=0;$detail=null;$managedCandidates=[];
        if($listing){
            if($kind==='customer_page')$count=$this->dao->readCustomerRootCount($connection,$pdo,$actor,$filter);
            elseif($kind==='platform_page')$count=$this->dao->readPlatformRootCount($connection,$pdo,$filter);
            else $count=$this->dao->readMobileRootCount($connection,$pdo,$actor,$filter);
            Facts::keys($page,['mode','offset','limit']);$after=null;$seen=[];
            do{
                $this->readConnectionGate($connection,$pdo,$prefix,true);
                if($kind==='customer_page')$rows=$this->dao->readCustomerRootWindow($connection,$pdo,$actor,$filter,$page,$after);
                elseif($kind==='platform_page')$rows=$this->dao->readPlatformRootWindow($connection,$pdo,$filter,$page,$after);
                else $rows=$this->dao->readMobileRootWindow($connection,$pdo,$actor,$filter,$page,$after);
                $roots=[];
                foreach($rows as$row){
                    $id=Facts::integer($row['id']??null,1,self::UINT_MAX);
                    if(isset($seen[$id]))self::unavailable();$seen[$id]=true;
                    if(!self::readLegacyHeader($row)){
                        Facts::stored($row,['pid'=>0,'is_system_del'=>0]);
                        if($kind!=='platform_page')Facts::stored($row,['uid'=>$actor]);
                        $roots[]=$row;
                    }
                }
                $loaded=$this->readThreeWindow($connection,$pdo,$prefix,$roots,$request,$view,false,null,null,null,$diagnostics);
                self::mergeReadiness($readiness,$loaded['readiness']);
                foreach($rows as$row){
                    $id=Facts::integer($row['id'],1,self::UINT_MAX);$legacy=self::readLegacyHeader($row);
                    $slots[]=['id'=>$id,'legacy'=>$legacy,'header'=>$legacy?$row:null,'dto'=>$legacy?null:$loaded['dtos'][$id]];
                }
                if(count($slots)>$count)self::unavailable();
                if($page['mode']==='page'){
                    if(count($slots)!==min($page['limit'],max(0,$count-$page['offset'])))self::unavailable();
                    break;
                }
                if(count($slots)===$count)break;
                if(count($rows)!==100)self::unavailable();$last=$rows[array_key_last($rows)];
                $after=['add_time'=>Facts::integer($last['add_time'],0,self::UINT_MAX),'id'=>Facts::integer($last['id'],1,self::UINT_MAX)];
            }while(true);
            if($page['mode']==='all'&&count($slots)!==$count)self::unavailable();
        }else{
            $lookupUid=$actor;
            if($kind==='customer_package')$selected=$this->dao->readCustomerFulfillmentChild($connection,$pdo,$actor,$request['locator']);
            elseif($kind==='customer_root')$selected=$this->dao->readCustomerRoot($connection,$pdo,$actor,$request['locator']);
            elseif($kind==='platform_root')$selected=$this->dao->readPlatformRoot($connection,$pdo,$request['locator']);
            else{
                $lookup=$this->dao->readMobileRoot($connection,$pdo,$actor,$request['locator']);
                Facts::keys($lookup,['probe','lookup_uid','root']);$selected=$lookup['root'];$lookupUid=$lookup['lookup_uid'];
                if(!is_int($lookupUid)||!in_array($lookupUid,[0,$actor],true))self::unavailable();
            }
            $detail=['state'=>'ROOT_NOT_FOUND','user_info'=>null];
            if($selected!==null){
                if(!is_array($selected))self::unavailable();$id=Facts::integer($selected['id']??null,1,self::UINT_MAX);$legacy=self::readLegacyHeader($selected);
                if($legacy){
                    if($kind==='customer_package')self::unavailable();
                    $slots[]=['id'=>$id,'legacy'=>true,'header'=>$selected,'dto'=>null];$count=1;$detail=['state'=>'FOUND','user_info'=>null];
                }else{
                    Facts::integer($selected['id'],1,self::INT_MAX);$owner=Facts::integer($selected['uid']??null,1,self::INT_MAX);
                    if($kind!=='platform_root'&&($lookupUid<1||$owner!==$lookupUid||$owner!==$actor))throw new \crmeb\exceptions\ApiException('订单不存在');
                    $buyer=null;
                    if($kind==='platform_root')$buyer=$this->dao->readPlatformDetailBuyerForHeader($connection,$pdo,$selected);
                    if($kind==='platform_root'&&$buyer===null)$detail=['state'=>'BUYER_NOT_FOUND','user_info'=>null];
                    else{
                        // Only after the selected saved row has passed its original actor and buyer checks.
                        $childrenPage=self::readChildrenPage($request['children_page'],$request['children_limit']);
                        $pid=Facts::integer($selected['pid']??null,0,self::INT_MAX);$child=$pid>0?$selected:null;
                        if($kind==='customer_package'&&$child===null)self::unavailable();
                        $root=$child===null?$selected:$this->dao->readFulfillmentParentForChild($connection,$pdo,$child);
                        $loaded=$this->readThreeWindow($connection,$pdo,$prefix,[$root],$request,$view,true,$child,$buyer,$childrenPage,$diagnostics);
                        $rootId=Facts::integer($root['id'],1,self::INT_MAX);self::mergeReadiness($readiness,$loaded['readiness']);
                        if($child===null&&in_array($kind,['customer_root','mobile_root'],true)){
                            if(!array_key_exists('is_channel',$root))self::unavailable();
                            $managedCandidates[$rootId]=bccomp($root['pay_price'],'0.00',2)>0&&$root['is_channel']==1&&$root['pay_type']==='weixin';
                        }
                        $slots[]=['id'=>$id,'legacy'=>false,'header'=>null,'dto'=>$loaded['dtos'][$rootId]];$count=1;
                        $detail=['state'=>'FOUND','user_info'=>$kind==='platform_root'?['real_name'=>$buyer['real_name']]:null];
                    }
                }
            }
        }
        if(date_default_timezone_get()!==$request['options']['timezone'])self::unavailable();
        return ['plan'=>['kind'=>$kind,'slots'=>$slots,'count'=>$count,'detail'=>$detail],'readiness'=>$readiness,'managed_candidates'=>$managedCandidates];
    }

    private static function readLegacyHeader(array $row):bool
    {
        if(!array_key_exists('marketplace_trade_version',$row))self::unavailable();$version=$row['marketplace_trade_version'];
        if(in_array($version,[null,0,'0'],true))return true;
        if(in_array($version,[1,'1'],true))return false;self::unavailable();
    }

    private static function readChildrenPage(mixed $page,mixed $limit):array
    {
        $values=[];
        foreach(['page'=>$page,'limit'=>$limit] as$key=>$value){
            if(!is_int($value)&&(!is_string($value)||preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)!==1))throw new \crmeb\exceptions\ApiException('履约分页格式错误');
            if((is_int($value)&&$value<1)||bccomp((string)$value,(string)($key==='limit'?100:self::INT_MAX),0)>0)
                throw new \crmeb\exceptions\ApiException('履约分页格式错误');
            $values[$key]=(int)$value;
        }
        return $values;
    }

    private static function mergeReadiness(?array &$observed,mixed $current):void
    {
        if($current===null)return;
        if(!is_array($current))self::unavailable();Facts::keys($current,['schema','state']);if($current!==['schema'=>2,'state'=>'READY'])self::unavailable();
        if($observed!==null&&$observed!==$current)self::unavailable();$observed=$current;
    }

    /** All V1 rows in a window share the captured native snapshot; legacy relations stay outside it. */
    private function readThreeWindow(ConnectionInterface $connection,PDO $pdo,string $prefix,array $roots,array $request,string $view,
        bool $detail,?array $selectedChild,?array $firstBuyer,?array $childrenPage,array &$diagnostics):array
    {
        $this->readConnectionGate($connection,$pdo,$prefix,true);
        if($roots===[])return ['dtos'=>[],'readiness'=>$this->dao->readAftersaleStructureReadiness($connection,$pdo)];
        $ids=[];$paidRoots=[];
        foreach($roots as$root){
            $id=Facts::integer($root['id']??null,1,self::INT_MAX);if(isset($ids[$id]))self::unavailable();$ids[$id]=true;
            Facts::stored($root,['marketplace_trade_version'=>1,'pid'=>0]);
            foreach(['combination_id','pink_id','seckill_id','bargain_id','advance_id','virtual_type','is_gift'] as$field)
                Facts::integer($root[$field]??null,0,0);
            if(Facts::integer($root['paid']??null,0,1)===1)$paidRoots[]=$root;
        }
        $items=$this->dao->readOriginalRootItemsForRoots($connection,$pdo,$roots);
        $this->readConnectionGate($connection,$pdo,$prefix,true);
        $payments=$this->payments->readObservationsForRoots($pdo,$paidRoots);
        $this->readConnectionGate($connection,$pdo,$prefix,true);
        $snapshot=$this->readOwnedFulfillmentSnapshots($connection,$pdo,$roots,$paidRoots,$payments);
        $originals=$snapshot['originals'];$histories=$snapshot['histories'];$inspections=$snapshot['inspections'];
        $families=['readiness'=>$snapshot['readiness'],'roots'=>$snapshot['roots']];
        if(array_keys($items)!==array_keys($ids))self::unavailable();
        $this->readConnectionGate($connection,$pdo,$prefix,true);
        $rawLogs=$this->dao->readRootDisplayStatusLogs($connection,$pdo,$roots);$logs=[];
        foreach($roots as$root)$logs[Facts::integer($root['id'],1,self::INT_MAX)]=[];
        foreach($rawLogs as$row){$id=Facts::integer($row['oid']??null,1,self::INT_MAX);if(!isset($ids[$id]))self::unavailable();$logs[$id][]=$row;}
        $this->readConnectionGate($connection,$pdo,$prefix,true);
        $aux=$this->dao->readDisplayAuxiliariesForRoots($connection,$pdo,$roots,$view);
        $display=$this->readDisplayFacts($roots,$items,$aux,$logs,$view);
        if($firstBuyer!==null){
            if(count($roots)!==1||$view!=='platform_detail')self::unavailable();
            $buyer=$display[array_key_first($ids)]['buyer'];if($buyer!==$firstBuyer)self::unavailable();
        }
        $deadlineRoots=[];
        if(in_array($view,['customer_detail','mobile_detail'],true))foreach($roots as$root){
            if(Facts::integer($root['paid'],0,1)===0&&Facts::integer($root['is_cancel']??null,0,1)===0
                &&($root['pay_type']??null)!=='offline')$deadlineRoots[]=$root;
        }
        $deadlines=$this->orders->readSavedPaymentDeadlinesForRoots($connection,$pdo,$deadlineRoots);
        $expectedDeadlines=[];foreach($deadlineRoots as$root)$expectedDeadlines[]=Facts::integer($root['id'],1,self::INT_MAX);
        if(array_keys($deadlines)!==$expectedDeadlines)self::unavailable();
        $dtos=[];
        foreach($roots as$root){
            $id=Facts::integer($root['id'],1,self::INT_MAX);$family=$families['roots'][$id];
            if(!is_array($family))self::unavailable();Facts::keys($family,['children','child_items','refunds','logs','operations','ranges']);
            foreach(['children','child_items','refunds','logs'] as$key)if(!is_array($family[$key])||!array_is_list($family[$key]))self::unavailable();
            self::readDisplayLogCorrespondence($logs[$id],$family['logs']);
            $summary=null;$history=null;$historyTimes=['last_shipped_at'=>null,'last_received_at'=>null,'received_completed_at'=>null];
            if(Facts::integer($root['paid'],0,1)===0){
                $this->readUnpaidRoot($root,$items[$id]);
                if($family['children']!==[]||$family['child_items']!==[]||$family['refunds']!==[]||$family['logs']!==[]
                    ||($family['operations']!==null&&$family['operations']!==[])||($family['ranges']!==null&&$family['ranges']!==[]))self::unavailable();
                $diagnostics[]=['root_order_id'=>$id,'reason'=>Facts::integer($root['is_cancel']??null,0,1)===1?'UNPAID_CANCELLED':'NOT_PAID'];
            }else{
                $observation=$payments[$id];$original=$originals[$id];self::readOriginalItemCorrespondence($items[$id],$original['items']);
                $history=$histories[$id]??self::unavailable();$summary=$history['summary'];$historyTimes=self::readHistoryTimes($history);
            }
            $inspection=$inspections[$id]??null;$s2=$inspection!==null&&$inspection['last_action_id']!==null;
            $facts=$display[$id];
            foreach($family['refunds'] as$refund)if(Facts::integer($refund['is_cancel']??null,0,1)===0
                &&Facts::integer($refund['refund_type']??null,0,6)!==3)$facts['refunds'][]=$refund;
            $facts['payment_deadline']=array_key_exists($id,$deadlines)?Facts::integer($deadlines[$id],0,self::UINT_MAX):null;
            $children=null;
            if($detail&&$history!==null&&$selectedChild===null){
                if($childrenPage===null)self::unavailable();
                $page=$this->dao->readFulfillmentChildrenPage($connection,$pdo,$root,$childrenPage['page'],$childrenPage['limit']);
                Facts::keys($page,['count','list']);
                if(!is_int($page['count'])||$page['count']!==count($history['ships_by_child'])||!is_array($page['list'])||!array_is_list($page['list']))self::unavailable();
                $expectedShips=array_values($history['ships_by_child']);
                usort($expectedShips,static fn(array $a,array $b):int=>bccomp($b['id'],$a['id'],0));
                $offset=($childrenPage['page']-1)*$childrenPage['limit'];
                $expectedShips=array_slice($expectedShips,$offset,$childrenPage['limit']);
                if(count($page['list'])!==count($expectedShips))self::unavailable();$childDtos=[];
                foreach($page['list'] as$index=>$rawOperation){
                    $operation=Facts::operation($rawOperation);$expected=$expectedShips[$index];
                    if(!self::same($operation,$expected))self::unavailable();$childId=$operation['child_order_id'];
                    if(!isset($history['children'][$childId])||!isset($history['ships_by_child'][$childId])
                        ||!self::same($history['ships_by_child'][$childId],$operation))self::unavailable();
                    $childItems=self::readChildItems($family['child_items'],$childId);
                    $childDtos[]=$s2?MarketplaceOrderSnapshotProjection::fulfillmentChildAftersaleFromFacts($root,array_values($inspection['source']['items']),$history['children'][$childId],
                        $childItems,$inspection,$request['options'],false):MarketplaceOrderSnapshotProjection::fulfillmentChildFromFacts($root,$items[$id],$history['children'][$childId],
                        $childItems,$history,$request['options'],false);
                }
                $children=['page'=>$childrenPage['page'],'limit'=>$childrenPage['limit'],'count'=>$page['count'],'list'=>$childDtos];
            }
            if($selectedChild!==null){
                if(!$detail||count($roots)!==1||$history===null)self::unavailable();$childId=Facts::integer($selectedChild['id'],1,self::INT_MAX);
                if(!isset($history['children'][$childId])||!isset($history['ships_by_child'][$childId]))self::unavailable();
                Facts::stored($history['children'][$childId],$selectedChild);
                $childItems=self::readChildItems($family['child_items'],$childId);
                $package=$s2?MarketplaceOrderSnapshotProjection::fulfillmentChildAftersaleFromFacts($root,array_values($inspection['source']['items']),$history['children'][$childId],
                    $childItems,$inspection,$request['options'],true):MarketplaceOrderSnapshotProjection::fulfillmentChildFromFacts($root,$items[$id],$history['children'][$childId],
                    $childItems,$history,$request['options'],true);
                if($request['kind']==='customer_package'){
                    $this->dao->readReceiptCustomerForChild($connection,$pdo,$request['actor'],$history['children'][$childId]);
                    $dtos[$id]=$s2?self::receiptAftersalePackage($package,$history,$inspection,$observation['locked']['registry']):self::receiptPackage($package,$history,$observation['locked']['registry']);
                }else $dtos[$id]=$package;
            }else{
                $extension=['summary'=>$summary,'actions'=>['ship'=>false,'receive'=>false],'history_times'=>$historyTimes,'children'=>$children];
                if($s2)$dtos[$id]=MarketplaceOrderSnapshotProjection::aftersaleRootFromFacts($view,$root,array_values($inspection['source']['items']),$facts,$request['options'],$inspection,$children,['ship'=>false,'receive'=>false]);
                else switch($view){
                    case 'customer_list':$dtos[$id]=MarketplaceOrderSnapshotProjection::customerListFromFacts($root,$items[$id],$facts,$request['options'],$extension);break;
                    case 'platform_list':$dtos[$id]=MarketplaceOrderSnapshotProjection::platformListFromFacts($root,$items[$id],$facts,$request['options'],$extension);break;
                    case 'mobile_list':$dtos[$id]=MarketplaceOrderSnapshotProjection::mobileListFromFacts($root,$items[$id],$facts,$request['options'],$extension);break;
                    case 'customer_detail':$dtos[$id]=MarketplaceOrderSnapshotProjection::customerRootFromFacts($root,$items[$id],$facts,$request['options'],$extension);break;
                    case 'platform_detail':$dtos[$id]=MarketplaceOrderSnapshotProjection::platformRootFromFacts($root,$items[$id],$facts,$request['options'],$extension);break;
                    case 'mobile_detail':$dtos[$id]=MarketplaceOrderSnapshotProjection::mobileRootFromFacts($root,$items[$id],$facts,$request['options'],$extension);break;
                    default:self::unavailable();
                }
            }
        }
        $this->readConnectionGate($connection,$pdo,$prefix,true);
        return ['dtos'=>$dtos,'readiness'=>$families['readiness']];
    }

    /** One captured RR snapshot: raw producers first, then explicit business-stage interpretation. */
    private function readOwnedFulfillmentSnapshots(ConnectionInterface $connection,PDO $pdo,array $roots,array $paidRoots,array $payments):array
    {
        $ready=$this->dao->readAftersaleStructureReadiness($connection,$pdo);
        $facts=$this->orders->readPaymentApplicationCaptureFactsBatch($connection,$pdo,$paidRoots);
        $core=$this->dao->readFulfillmentFamiliesForRoots($connection,$pdo,$roots);
        $refunds=$this->dao->readRefundRowsForRoots($connection,$pdo,$roots);
        $actions=$this->refunds->readActionsForRoots($connection,$pdo,$roots);
        $logs=$this->refunds->readStatusLogsForRoots($connection,$pdo,$roots);
        $ids=[];$paidIds=[];foreach($roots as$root)$ids[]=Facts::integer($root['id'],1,self::INT_MAX);
        foreach($paidRoots as$root)$paidIds[]=Facts::integer($root['id'],1,self::INT_MAX);
        foreach([$core,$refunds,$actions,$logs] as$map)if(array_keys($map)!==$ids)self::unavailable();
        if(array_keys($facts)!==$paidIds||array_keys($payments)!==$paidIds)self::unavailable();
        $families=[];$originals=[];$histories=[];$inspections=[];
        foreach($roots as$root){
            $id=Facts::integer($root['id'],1,self::INT_MAX);$native=$core[$id];
            Facts::keys($native,['children','child_items','operations','ranges']);
            foreach($native as$rows)if(!is_array($rows)||!array_is_list($rows))self::unavailable();
            $fulfillmentLogs=[];
            foreach($logs[$id] as$row)if(in_array($row['change_type'],['marketplace_fulfillment_ship','marketplace_fulfillment_receive'],true))$fulfillmentLogs[]=$row;
            $families[$id]=['children'=>$native['children'],'child_items'=>$native['child_items'],'refunds'=>$refunds[$id],
                'logs'=>$fulfillmentLogs,'operations'=>$native['operations'],'ranges'=>$native['ranges']];
            if(Facts::integer($root['paid'],0,1)===0){
                if($actions[$id]!==[]||$refunds[$id]!==[]||$native['children']!==[]||$native['child_items']!==[]
                    ||$native['operations']!==[]||$native['ranges']!==[]||$fulfillmentLogs!==[])self::unavailable();
                foreach($logs[$id] as$row)if($row['change_type']==='marketplace_aftersale')self::unavailable();
                continue;
            }
            $observation=$payments[$id];Facts::keys($observation,['locked','root_payment_consistent']);
            if($observation['root_payment_consistent']!==true||!is_array($observation['locked']))self::unavailable();
            self::paid(['root_order_id'=>$id,'root_order_no'=>Facts::text($root['order_id'],32,true),
                'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX)],$root,$observation['locked']);
            $capture=$this->orders->capturePaymentApplicationFactsFromFacts(Facts::integer($root['uid'],1,self::INT_MAX),$id,$facts[$id]);
            if($capture['root']!==$root)self::unavailable();
            $context=self::aftersaleContext($actions[$id],$refunds[$id],$native['operations'],$native['ranges'],$logs[$id]);
            $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$observation['locked']);
            $family=['children'=>$native['children'],'items'=>$native['child_items'],'ranges'=>$native['ranges'],'refunds'=>$refunds[$id]];
            $this->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);
            $originals[$id]=$inspection['credit_facts'];$inspections[$id]=$inspection;
            if($inspection['last_action_id']===null){
                $typedOps=array_map([Facts::class,'operation'],$native['operations']);$typedRanges=array_map([Facts::class,'rangeRow'],$native['ranges']);
                foreach($typedOps as$row)if($row['contract_version']!==1||$row['aftersale_cut_id']!==null)self::unavailable();
                $histories[$id]=$this->readHistoryFromFacts($root,$observation,$originals[$id],
                    ['children'=>$native['children'],'items'=>$native['child_items'],'ranges'=>$typedRanges,'refunds'=>$refunds[$id]],$typedOps,$fulfillmentLogs);
            }else $histories[$id]=self::historyFromAftersaleInspection($family,$inspection);
        }
        if($this->dao->readAftersaleStructureReadiness($connection,$pdo)!==$ready)self::unavailable();
        return ['readiness'=>$ready,'roots'=>$families,'originals'=>$originals,'histories'=>$histories,'inspections'=>$inspections];
    }

    /** Native complete sets only; the cut is derived from the actual last committed row, never a caller hint. */
    private static function aftersaleContext(array $actions,array $refunds,array $operations,array $ranges,array $logs):array
    {
        foreach([$actions,$refunds,$operations,$ranges,$logs] as$rows)if(!array_is_list($rows))self::unavailable();
        $last=null;foreach($actions as$row){$id=Facts::u64($row['id']??null);
            if($last!==null&&bccomp($id,$last,0)<=0)self::unavailable();$last=$id;}
        return ['schema'=>2,'cut_id'=>$last,'actions'=>$actions,'refunds'=>$refunds,'operations'=>$operations,'ranges'=>$ranges,'logs'=>$logs];
    }

    /** Explicit adapter from the complete proved S2 snapshot, not a fallback or an empty S1 history. */
    private static function historyFromAftersaleInspection(array $family,array $inspection):array
    {
        Facts::keys($family,['children','items','ranges','refunds']);
        if($inspection['last_action_id']===null)self::unavailable();$ops=[];$ships=[];
        foreach($inspection['fulfillment']['operations'] as$operation){
            if(!array_key_exists('validated_ranges',$operation))self::unavailable();unset($operation['validated_ranges']);
            $id=Facts::u64($operation['id']);if(isset($ops[$id]))self::unavailable();$ops[$id]=$operation;
            if($operation['action']==='ship'){
                $child=Facts::integer($operation['child_order_id'],1,self::INT_MAX);if(isset($ships[$child]))self::unavailable();$ships[$child]=$operation;
            }
        }
        return ['children'=>self::indexRows($family['children'],'id'),'child_items'=>self::indexRows($family['items'],'id'),
            'ships_by_child'=>$ships,'operations'=>$ops,'ranges'=>array_values($inspection['fulfillment']['ranges']),
            'ship_ranges'=>$inspection['history']['ships'],'summary'=>$inspection['summary'],
            'source_snapshot_sha256'=>$inspection['source']['plan']['source_snapshot_sha256']];
    }

    /** C1 ordinal slots stay tied to immutable S ranges; actual R/T/P determine current eligibility. */
    private static function receiptAftersalePackage(array $package,array $history,array $inspection,array $registry):array
    {
        $child=$package['id'];$blocks=self::packageBlocks($history,$child);
        $availability=MarketplaceOrderRefundFacts::receiptAvailabilityFromInspection($inspection,$child);
        $enabled=$registry['fulfillment_hold']===0&&$registry['hold_reason']===null&&$registry['hold_attempt_id']===null&&$registry['hold_at']===null;
        $items=[];$totals=['quantity'=>0,'received'=>0,'returned'=>0,'intersection'=>0,'paused'=>0,'eligible'=>0];
        foreach($blocks as$id=>$item){
            $received=[];$returned=[];
            foreach($inspection['history']['received'] as$range)if($range['child_order_id']===$child&&$range['child_item_id']===$id)$received[]=$range;
            foreach($inspection['history']['returned'] as$range)if($range['child_order_id']===$child&&$range['child_item_id']===$id)$returned[]=$range;
            $r=self::coordinateQuantity($received);$t=self::coordinateQuantity($returned);$intersection=self::coordinateIntersection($received,$returned);
            $paused=0;$eligible=0;$segments=[];
            foreach($availability['paused'] as$range)if($range['child_item_id']===$id)$paused+=$range['unit_end']-$range['unit_start'];
            foreach($availability['eligible'] as$range)if($range['child_item_id']===$id){
                $found=false;foreach($item['blocks'] as$block)if($range['source_ship_range_id']===$block['range_id']){
                    if($found||$range['source_item_id']!==$block['source_item_id']||$range['unit_start']<$block['unit_start']||$range['unit_end']>$block['unit_end'])self::unavailable();$found=true;
                    if($enabled){$segments[]=['first'=>$block['first']+$range['unit_start']-$block['unit_start'],
                        'last'=>$block['first']+$range['unit_end']-$block['unit_start']-1];$eligible+=$range['unit_end']-$range['unit_start'];}
                }if(!$found)self::unavailable();
            }
            usort($segments,static fn(array $a,array $b):int=>($a['first']<=>$b['first'])?:($a['last']<=>$b['last']));$merged=[];
            foreach($segments as$segment){$last=count($merged)-1;if($last>=0&&$segment['first']<=$merged[$last]['last'])self::unavailable();
                if($last>=0&&$segment['first']===$merged[$last]['last']+1)$merged[$last]['last']=$segment['last'];else $merged[]=$segment;}
            $remaining=$item['quantity']-$r-$t+$intersection;
            if($remaining<0||$paused>$remaining||($enabled&&$eligible!==$remaining-$paused)||(!$enabled&&$eligible!==0))self::unavailable();
            $items[]=['item_id'=>$id,'quantity'=>$item['quantity'],'received_quantity'=>$r,'returned_quantity'=>$t,
                'returned_after_receipt'=>$intersection,'paused_quantity'=>$paused,'eligible_quantity'=>$eligible,'eligible_segments'=>$merged];
            foreach(['quantity'=>$item['quantity'],'received'=>$r,'returned'=>$t,'intersection'=>$intersection,'paused'=>$paused,'eligible'=>$eligible] as$key=>$q)$totals[$key]+=$q;
        }
        $published=[];foreach($package['items'] as$item)$published[$item['id']]=$item['quantity'];$expected=[];foreach($blocks as$id=>$item)$expected[$id]=$item['quantity'];
        if($published!==$expected||$totals['quantity']!==$package['original']||$totals['received']!==$package['received']
            ||$totals['returned']!==$package['returned_accepted']||$totals['intersection']!==$package['aftersale']['returned_after_receipt'])self::unavailable();
        return ['package'=>$package,'allowed_actions'=>['receive'=>$enabled&&$totals['eligible']>0],
            'receipt_selection'=>['schema'=>2,'coordinate'=>'child_item_ordinal','items'=>$items]];
    }

    private static function coordinateQuantity(array $rows):int
    {
        $q=0;foreach($rows as$row){$first=Facts::integer($row['unit_start'],0,self::INT_MAX);$last=Facts::integer($row['unit_end'],1,self::INT_MAX);
            if($last<=$first)self::unavailable();$q+=$last-$first;Facts::integer($q,0,self::UINT_MAX);}return $q;
    }

    private static function coordinateIntersection(array $left,array $right):int
    {
        $q=0;foreach($left as$a)foreach($right as$b)if($a['source_item_id']===$b['source_item_id']
            &&$a['source_ship_range_id']===$b['source_ship_range_id'])$q+=max(0,min($a['unit_end'],$b['unit_end'])-max($a['unit_start'],$b['unit_start']));
        return Facts::integer($q,0,self::UINT_MAX);
    }

    /** M1 publishes genuinely available unshipped quantities while preserving its original four keys. */
    private static function shippingAftersaleItems(array $items,array $inspection):array
    {
        $capacity=MarketplaceOrderRefundFacts::quoteSelectionFromInspection($inspection);$rows=[];$total=0;$shipped=0;$available=0;
        foreach($items as$item){$id=Facts::integer($item['id'],1,self::INT_MAX);$q=Facts::integer($item['cart_num'],1,self::INT_MAX);$s=0;
            foreach($inspection['history']['ships'] as$range)if($range['source_item_id']===$id)$s+=$range['quantity'];
            $matches=[];foreach($capacity['root_items'] as$row)if($row['item']['id']===$id)$matches[]=$row;
            if(count($matches)!==1)self::unavailable();$free=Facts::integer($matches[0]['available_cancel_quantity'],0,$q);
            if($s>$q||$free>$q-$s)self::unavailable();$rows[]=['item_id'=>$id,'original_quantity'=>$q,'shipped_quantity'=>$s,'to_ship'=>$free];
            $total+=$q;$shipped+=$s;$available+=$free;
        }
        if($total!==$inspection['summary']['original']||$shipped!==$inspection['summary']['shipped']
            ||$available!==$inspection['summary']['to_ship']-$inspection['aftersale']['pending_cancel_quantity'])self::unavailable();return $rows;
    }

    private static function readOriginalItemCorrespondence(array $fullItems,mixed $originalItems):void
    {
        if(!is_array($originalItems)||!array_is_list($originalItems)||count($fullItems)!==count($originalItems))self::unavailable();
        foreach($originalItems as$index=>$item){
            if(!is_array($item)||!isset($fullItems[$index]))self::unavailable();Facts::stored($fullItems[$index],$item);
        }
    }

    private static function readDisplayLogCorrespondence(array $complete,array $fulfillment):void
    {
        $actual=[];$expected=[];
        foreach($complete as$row)if(in_array($row['change_type'],['marketplace_fulfillment_ship','marketplace_fulfillment_receive'],true))$actual[]=Facts::json($row,2048);
        foreach($fulfillment as$row)$expected[]=Facts::json($row,2048);
        sort($actual,SORT_STRING);sort($expected,SORT_STRING);if($actual!==$expected)self::unavailable();
    }

    private static function readHistoryTimes(array $history):array
    {
        $ship=null;$receive=null;
        foreach($history['operations'] as$operation){
            if($operation['action']==='ship'){$time=Facts::integer($operation['shipped_at'],1,self::UINT_MAX);$ship=$ship===null?$time:max($ship,$time);}
            elseif($operation['action']==='receive'){$time=Facts::integer($operation['received_at'],1,self::UINT_MAX);$receive=$receive===null?$time:max($receive,$time);}
            else self::unavailable();
        }
        $summary=$history['summary'];$complete=$summary['received']===$summary['original']?$receive:null;
        if(($summary['shipped']>0&&$ship===null)||($summary['received']>0&&$receive===null)||($summary['received']===$summary['original']&&$complete===null))self::unavailable();
        return ['last_shipped_at'=>$ship,'last_received_at'=>$receive,'received_completed_at'=>$complete];
    }

    private static function readChildItems(array $completeFamilyItems,int $childId):array
    {
        $items=[];
        foreach($completeFamilyItems as$item)if(Facts::integer($item['oid']??null,1,self::INT_MAX)===$childId)$items[]=$item;
        if($items===[]||count($items)>500)self::unavailable();return $items;
    }

    /** Turn actual page-wide batches into fixed per-root display facts, checking both complete item sets. */
    private function readDisplayFacts(array $roots,array $items,array $aux,array $logs,string $view):array
    {
        Facts::keys($aux,['users','invoices','economize','reply_counts','stores']);
        $usesUsers=in_array($view,['platform_list','customer_detail','platform_detail','mobile_detail'],true);
        $usesInvoices=str_ends_with($view,'_detail');
        $usesEconomize=in_array($view,['customer_detail','mobile_detail'],true);
        $usesReplies=in_array($view,['customer_list','customer_detail','platform_detail','mobile_detail'],true);
        $usesStores=$usesEconomize;
        foreach(['users'=>$usesUsers,'invoices'=>$usesInvoices,'economize'=>$usesEconomize,'reply_counts'=>$usesReplies,'stores'=>$usesStores] as$key=>$used){
            if($used){if(!is_array($aux[$key])||!array_is_list($aux[$key]))self::unavailable();}
            elseif($aux[$key]!==null)self::unavailable();
        }
        $rootMap=[];$wantedUsers=[];$numbers=[];$wantedStores=[];
        foreach($roots as$root){
            $id=Facts::integer($root['id'],1,self::INT_MAX);$uid=Facts::integer($root['uid'],1,self::INT_MAX);$rootMap[$id]=$root;
            $wantedUsers[$uid]=true;$numbers['n:'.$root['order_id']]=$id;
            if($usesEconomize&&(string)$root['pay_uid']!==(string)$root['uid'])$wantedUsers[(string)$root['pay_uid']]=true;
            if($usesStores&&(string)$root['store_id']!=='0')$wantedStores[(string)$root['store_id']]=true;
        }
        $users=[];$invoices=[];$economize=[];$stores=[];$replies=[];
        foreach($aux['users']??[] as$row){
            $uid=Facts::integer($row['uid']??null,1,self::UINT_MAX);
            if(!isset($wantedUsers[$uid])||isset($users[$uid]))self::unavailable();$users[$uid]=$row;
        }
        foreach($aux['invoices']??[] as$row){
            $id=Facts::integer($row['order_id']??null,1,self::INT_MAX);
            if(!isset($rootMap[$id])||isset($invoices[$id]))self::unavailable();Facts::stored($row,['uid'=>Facts::integer($rootMap[$id]['uid'],1,self::INT_MAX)]);$invoices[$id]=$row;
        }
        foreach($aux['economize']??[] as$row){
            $key='n:'.Facts::text($row['order_id']??null,32,true);$id=$numbers[$key]??null;
            if($id===null||isset($economize[$id]))self::unavailable();Facts::stored($row,['uid'=>Facts::integer($rootMap[$id]['uid'],1,self::INT_MAX)]);$economize[$id]=$row;
        }
        foreach($aux['stores']??[] as$row){
            $id=Facts::integer($row['id']??null,1,self::UINT_MAX);
            if(!isset($wantedStores[$id])||isset($stores[$id]))self::unavailable();$stores[$id]=$row;
        }
        if($usesReplies)foreach($roots as$root)$replies[Facts::integer($root['id'],1,self::INT_MAX)]=[];
        foreach($aux['reply_counts']??[] as$row){
            Facts::keys($row,['item_id','root_order_id','uid','mer_id','unique','reply_count']);
            $rid=Facts::integer($row['root_order_id'],1,self::INT_MAX);$id=Facts::integer($row['item_id'],1,self::INT_MAX);
            if(!isset($rootMap[$rid])||isset($replies[$rid][$id]))self::unavailable();$replies[$rid][$id]=$row;
        }
        $result=[];
        foreach($roots as$root){
            $id=Facts::integer($root['id'],1,self::INT_MAX);$uid=Facts::integer($root['uid'],1,self::INT_MAX);
            if($usesReplies){
                $expected=[];
                foreach($items[$id] as$item){
                    $itemId=Facts::integer($item['id'],1,self::INT_MAX);$expected[]=$itemId;$reply=$replies[$id][$itemId]??null;
                    if(!is_array($reply))self::unavailable();
                    Facts::stored($reply,['item_id'=>$itemId,'root_order_id'=>$id,'uid'=>$uid,'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX),
                        'unique'=>Facts::text($item['unique']??null,128)]);
                    Facts::integer($reply['reply_count'],0,PHP_INT_MAX);
                    Facts::stored($item,['oid'=>$id,'uid'=>$uid,'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX)]);
                }
                if(array_keys($replies[$id])!==$expected)self::unavailable();
            }
            $differentPayer=$usesEconomize&&(string)$root['pay_uid']!==(string)$root['uid'];
            $payer=$differentPayer?($users[(string)$root['pay_uid']]??null):null;
            if($differentPayer&&$payer===null)self::unavailable();
            $result[$id]=['buyer'=>$usesUsers?($users[$uid]??null):null,'payer'=>$payer,'invoice'=>$usesInvoices?($invoices[$id]??null):null,
                'economize'=>$usesEconomize?($economize[$id]??null):null,'reply_counts'=>$usesReplies?$replies[$id]:null,
                'store'=>$usesStores?($stores[(string)$root['store_id']]??null):null,'refunds'=>[],'status_logs'=>$logs[$id],'payment_deadline'=>null];
        }
        return $result;
    }

    /** The original external managed observation runs only after a confirmed commit, never in RR. */
    private function finishThreeManaged(array $plan,array $candidates,bool $enabled):array
    {
        if(!$enabled||$candidates===[])return $plan;
        if(count($candidates)!==1||!in_array($plan['kind'],['customer_root','mobile_root'],true)||count($plan['slots'])!==1)self::unavailable();
        $id=array_key_first($candidates);$eligible=$candidates[$id];
        if(!is_bool($eligible)||$plan['slots'][0]['id']!==$id||$plan['slots'][0]['legacy']!==false
            ||!is_array($plan['slots'][0]['dto'])||($plan['slots'][0]['dto']['order_shipping_open']??null)!==false)self::unavailable();
        if($eligible)$plan['slots'][0]['dto']['order_shipping_open']=(bool)\crmeb\services\easywechat\orderShipping\MiniOrderService::isManaged();
        return $plan;
    }

    /** Every local unit is anchored to immutable first-ship facts, never to today's remaining units. */
    private static function packageBlocks(array $history,int $childId):array
    {
        $ship=$history['ships_by_child'][$childId]??null;
        if(!is_array($ship)||($ship['action']??null)!=='ship'||($ship['child_order_id']??null)!==$childId)self::unavailable();
        $items=[];
        foreach($history['child_items'] as$item){
            if(Facts::integer($item['oid']??null,1,self::INT_MAX)!==$childId)continue;
            $id=Facts::integer($item['id']??null,1,self::INT_MAX);
            if(isset($items[$id]))self::unavailable();
            $items[$id]=['quantity'=>Facts::integer($item['cart_num']??null,1,self::INT_MAX),'source_item_id'=>null,'blocks'=>[]];
        }
        if($items===[]||count($items)>500)self::unavailable();ksort($items,SORT_NUMERIC);
        foreach($history['ship_ranges'] as$range){
            if(($range['operation_id']??null)!==$ship['id'])continue;
            $itemId=Facts::integer($range['child_item_id']??null,1,self::INT_MAX);
            $source=Facts::integer($range['source_item_id']??null,1,self::INT_MAX);
            if(($range['operation_action']??null)!=='ship'||($range['child_order_id']??null)!==$childId||!isset($items[$itemId])
                ||($items[$itemId]['source_item_id']!==null&&$items[$itemId]['source_item_id']!==$source))self::unavailable();
            $start=Facts::integer($range['unit_start']??null,0,self::INT_MAX);$end=Facts::integer($range['unit_end']??null,1,self::INT_MAX);
            if($end<=$start||Facts::integer($range['quantity']??null,1,self::INT_MAX)!==$end-$start)self::unavailable();
            $items[$itemId]['source_item_id']=$source;
            $items[$itemId]['blocks'][]=['range_id'=>Facts::u64($range['id']),'source_item_id'=>$source,'unit_start'=>$start,'unit_end'=>$end];
        }
        foreach($items as&$item){
            usort($item['blocks'],static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);$cursor=0;$previousEnd=0;
            foreach($item['blocks'] as&$block){
                if($block['unit_start']<$previousEnd)self::unavailable();$previousEnd=$block['unit_end'];
                $length=$block['unit_end']-$block['unit_start'];$block['first']=$cursor+1;$cursor+=$length;$block['last']=$cursor;
            }unset($block);
            if($item['source_item_id']===null||$cursor!==$item['quantity'])self::unavailable();
        }unset($item);
        return $items;
    }

    /** Called only after actual customer/payment/family locks and complete history, before saved replay. */
    private static function normalizePublicReceipt(array $candidate,array $history,array $public):array
    {
        $canonical=MarketplaceOrderFulfillmentInput::receivePublic($public);
        if(!self::same($canonical,$public)||$public['selection']['mode']!=='items')self::unavailable();
        $blocks=self::packageBlocks($history,$candidate['child_order_id']);$ranges=[];
        foreach($public['selection']['items'] as$selected){
            $id=$selected['item_id'];$item=$blocks[$id]??null;if($item===null)self::unavailable();
            foreach($selected['segments'] as$segment){
                if($segment['last']>$item['quantity'])self::unavailable();$mapped=0;
                foreach($item['blocks'] as$block){
                    $first=max($segment['first'],$block['first']);$last=min($segment['last'],$block['last']);
                    if($first>$last)continue;
                    $start=$block['unit_start']+$first-$block['first'];$end=$block['unit_start']+$last-$block['first']+1;
                    $ranges[]=['source_item_id'=>$block['source_item_id'],'unit_start'=>$start,'unit_end'=>$end];$mapped+=$end-$start;
                }
                if($mapped!==$segment['last']-$segment['first']+1)self::unavailable();
            }
        }
        return MarketplaceOrderFulfillmentInput::receive($candidate['root_order_id'],$candidate['child_order_id'],
            ['idem_key'=>$public['idem_key'],'selection'=>['mode'=>'ranges','ranges'=>$ranges]]);
    }

    private static function receiptPackage(array $package,array $history,array $registry):array
    {
        $childId=$package['id'];$blocks=self::packageBlocks($history,$childId);
        $enabled=$registry['fulfillment_hold']===0&&$registry['hold_reason']===null&&$registry['hold_attempt_id']===null&&$registry['hold_at']===null;
        $eligible=self::eligible($history['ships_by_child'][$childId],$history);$items=[];$receivedTotal=0;$eligibleTotal=0;$quantityTotal=0;
        foreach($blocks as$id=>$item){
            $received=0;
            foreach($history['ranges'] as$range)if($range['operation_action']==='receive'&&$range['child_order_id']===$childId&&$range['child_item_id']===$id)
                $received+=Facts::integer($range['quantity'],1,self::INT_MAX);
            $segments=[];$eligibleQuantity=0;
            if($enabled)foreach($eligible as$part){
                if($part['child_item_id']!==$id)continue;$found=false;
                foreach($item['blocks'] as$block)if($part['source_ship_range_id']===$block['range_id']){
                    if($found||$part['source_item_id']!==$block['source_item_id']||$part['unit_start']<$block['unit_start']
                        ||$part['unit_end']>$block['unit_end']||$part['unit_end']<=$part['unit_start'])self::unavailable();
                    $found=true;$segments[]=['first'=>$block['first']+$part['unit_start']-$block['unit_start'],
                        'last'=>$block['first']+$part['unit_end']-$block['unit_start']-1];$eligibleQuantity+=$part['unit_end']-$part['unit_start'];
                }
                if(!$found)self::unavailable();
            }
            usort($segments,static fn(array $a,array $b):int=>$a['first']<=>$b['first']);$merged=[];
            foreach($segments as$segment){$last=count($merged)-1;
                if($last>=0&&$segment['first']<=$merged[$last]['last'])self::unavailable();
                if($last>=0&&$segment['first']===$merged[$last]['last']+1)$merged[$last]['last']=$segment['last'];else $merged[]=$segment;
            }
            if($received>$item['quantity']||$eligibleQuantity>$item['quantity']-$received)self::unavailable();
            $items[]=['item_id'=>$id,'quantity'=>$item['quantity'],'received_quantity'=>$received,
                'eligible_quantity'=>$eligibleQuantity,'eligible_segments'=>$merged];
            $receivedTotal+=$received;$eligibleTotal+=$eligibleQuantity;$quantityTotal+=$item['quantity'];
        }
        $published=[];foreach($package['items'] as$item)$published[$item['id']]=$item['quantity'];
        $expected=[];foreach($blocks as$id=>$item)$expected[$id]=$item['quantity'];
        if($published!==$expected||$receivedTotal!==$package['received']||$quantityTotal!==$package['original'])self::unavailable();
        return ['package'=>$package,'allowed_actions'=>['receive'=>$enabled&&$eligibleTotal>0],
            'receipt_selection'=>['schema'=>1,'coordinate'=>'child_item_ordinal','items'=>$items]];
    }

    private function packagePage(ConnectionInterface $connection,PDO $pdo,array $root,array $items,array $history,array $page,array $options,?array $inspection=null):array
    {
        $selected=$this->dao->readFulfillmentChildrenPage($connection,$pdo,$root,$page['page'],$page['limit']);Facts::keys($selected,['count','list']);
        $ships=array_values($history['ships_by_child']);usort($ships,static fn(array $a,array $b):int=>bccomp($b['id'],$a['id'],0));
        if(!is_int($selected['count'])||$selected['count']!==count($ships)||!is_array($selected['list'])||!array_is_list($selected['list']))self::unavailable();
        $ships=array_slice($ships,($page['page']-1)*$page['limit'],$page['limit']);if(count($ships)!==count($selected['list']))self::unavailable();$list=[];
        foreach($selected['list'] as$index=>$raw){$operation=Facts::operation($raw);
            if(!self::same($operation,$ships[$index]))self::unavailable();$id=$operation['child_order_id'];
            if(!isset($history['children'][$id])||!self::same($history['ships_by_child'][$id],$operation))self::unavailable();
            $childItems=self::readChildItems(array_values($history['child_items']),$id);
            $list[]=$inspection===null?MarketplaceOrderSnapshotProjection::fulfillmentChildFromFacts($root,$items,$history['children'][$id],
                $childItems,$history,$options,false):MarketplaceOrderSnapshotProjection::fulfillmentChildAftersaleFromFacts($root,array_values($inspection['source']['items']),$history['children'][$id],
                $childItems,$inspection,$options,false);
        }
        return ['page'=>$page['page'],'limit'=>$page['limit'],'count'=>$selected['count'],'list'=>$list];
    }

    private static function shippingItems(array $items,array $history):array
    {
        $rows=[];$quantity=0;$shippedTotal=0;
        foreach($items as$item){$id=Facts::integer($item['id'],1,self::INT_MAX);$q=Facts::integer($item['cart_num'],1,self::INT_MAX);
            if(isset($rows[$id]))self::unavailable();$rows[$id]=['item_id'=>$id,'original_quantity'=>$q,'shipped_quantity'=>0,'to_ship'=>$q];}
        ksort($rows,SORT_NUMERIC);
        foreach($history['ship_ranges'] as$range){$id=$range['source_item_id'];if(!isset($rows[$id]))self::unavailable();
            $q=Facts::integer($range['quantity'],1,self::INT_MAX);$rows[$id]['shipped_quantity']+=$q;$rows[$id]['to_ship']-=$q;}
        foreach($rows as$row){if($row['to_ship']<0)self::unavailable();$quantity+=$row['original_quantity'];$shippedTotal+=$row['shipped_quantity'];}
        if($quantity!==$history['summary']['original']||$shippedTotal!==$history['summary']['shipped'])self::unavailable();return array_values($rows);
    }

    /** Missing objects remain null facts; do not manufacture an empty history or summary. */
    private function readPendingRoot(array $root,array $original,array $family):void
    {
        Facts::keys($original,['root','items','selection','reserves','children','child_orders']);self::normal($root);
        Facts::integer($root['status']??null,0,0);
        foreach(['children','child_items','refunds','logs'] as$key)if($family[$key]!==[])self::unavailable();
        if(($family['operations']!==null&&$family['operations']!==[])||($family['ranges']!==null&&$family['ranges']!==[])
            ||$original['children']!==[]||$original['child_orders']!==[])self::unavailable();
        $quantities=[];
        foreach($original['items'] as$item){
            if(Facts::integer($item['oid']??null,1,self::INT_MAX)!==Facts::integer($root['id'],1,self::INT_MAX)
                ||Facts::integer($item['refund_num']??null,0,0)!==0||Facts::integer($item['split_status']??null,0,0)!==0
                ||Facts::integer($item['surplus_num']??null,1,self::INT_MAX)!==Facts::integer($item['cart_num']??null,1,self::INT_MAX))self::unavailable();
            $cart=Facts::decode($item['cart_info']??null,65535);if(array_key_exists('marketplace_fulfillment',$cart))self::unavailable();
            $quantities[]=['source_item_id'=>Facts::integer($item['id'],1,self::INT_MAX),
                'quantity'=>Facts::integer($item['cart_num'],1,self::INT_MAX)];
        }
        $plan=MarketplaceOrderFulfillmentPlan::plan($root,$original['items'],$original['reserves'],[],$quantities);
        MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$plan);
    }

    private function readUnpaidRoot(array $root,array $items):void
    {
        Facts::integer($root['pay_time']??null,0,0);
        if(($root['trade_no']??null)!=='')self::unavailable();
        foreach(['status','refund_status','refund_type','refund_reason_time'] as$key)Facts::integer($root[$key]??null,0,0);
        if(($root['refund_price']??null)!=='0.00')self::unavailable();
        foreach($items as$item){
            Facts::integer($item['refund_num']??null,0,0);Facts::integer($item['split_status']??null,0,0);
            if(Facts::integer($item['surplus_num']??null,1,self::INT_MAX)!==Facts::integer($item['cart_num']??null,1,self::INT_MAX))self::unavailable();
            $cart=Facts::decode($item['cart_info']??null,65535);if(array_key_exists('marketplace_fulfillment',$cart))self::unavailable();
        }
    }

    private function readDefaultConnection():array
    {
        $connection=Db::connect();
        if(!$connection instanceof \crmeb\compat\think\orm\db\connector\Mysql||$connection->transactionDepth()!==0
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false)self::unavailable();
        $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::unavailable();
        $pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO)self::unavailable();
        $this->readConnectionGate($connection,$pdo,$prefix,false);return [$connection,$pdo,$prefix];
    }

    private function readConnectionGate(ConnectionInterface $connection,PDO $pdo,string $prefix,bool $transaction):void
    {
        if(!$connection instanceof \crmeb\compat\think\orm\db\connector\Mysql||$connection->transactionDepth()!==0
            ||$connection->getConfig('prefix')!==$prefix)self::unavailable();
        $this->connection($connection,$pdo);if($pdo->inTransaction()!==$transaction)self::unavailable();
    }

    private function discardOwnedReadConnection(ConnectionInterface $connection):void
    {
        try{$connection->close();}catch(Throwable $ignored){}
    }

    public function ship(MerchantContext $ctx,mixed $rootId,array $raw):array
    {
        $normalized=MarketplaceOrderFulfillmentInput::ship($rootId,$raw);
        $ctx->require('order.ship');$this->auth->authorizeAction($ctx,'order.ship',false);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertAftersaleStructure($pdo);
        $candidate=$this->dao->merchantCandidate($pdo,$normalized['request']['root_order_id'],Facts::integer($ctx->merId,1,self::UINT_MAX));
        return $this->run($connection,$pdo,$candidate,'ship',$ctx,null,$normalized,false);
    }

    public function receiveForCustomer(int $actualUid,string $childNo,array $raw):array
    {
        Facts::integer($actualUid,1,self::INT_MAX);Facts::text($childNo,32,true);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertAftersaleStructure($pdo);
        $candidate=$this->dao->customerChildCandidate($pdo,$actualUid,$childNo);
        $normalized=MarketplaceOrderFulfillmentInput::receive($candidate['root_order_id'],$candidate['child_order_id'],$raw);
        return $this->run($connection,$pdo,$candidate,'receive',null,$actualUid,$normalized,false);
    }

    /** @internal Dedicated actual Worker lifecycle is an activation dependency, never a public actor parameter. */
    public function receiveDue(int $childId):array
    {
        Facts::integer($childId,1,self::INT_MAX);
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertAftersaleStructure($pdo);
        $candidate=$this->dao->internalChildCandidate($pdo,$childId);
        return $this->run($connection,$pdo,$candidate,'receive',null,null,null,true);
    }

    /** @internal Cursor is only a hint; every child is resolved and locked afresh in receiveDue. */
    public function scanDue(int $dueCursor=0,string $shipCursor='0',int $batch=100):array
    {
        Facts::integer($dueCursor,0,self::UINT_MAX);Facts::u64($shipCursor,true);
        if($batch<1||$batch>100)self::unavailable();
        [$connection,$pdo]=$this->defaultConnection();$this->dao->assertAftersaleStructure($pdo);
        $rows=$this->dao->dueCandidates($pdo,$dueCursor,$shipCursor,self::now(),$batch);$this->outside($connection,$pdo);
        $out=['processed'=>0,'received'=>0,'already_received'=>0,'not_due'=>0,'pending'=>0,
            'next_due_at'=>0,'next_ship_id'=>'0','wrapped'=>count($rows)<$batch,'aftersale_paused'=>0,'fulfillment_closed'=>0];
        foreach($rows as$row){
            ++$out['processed'];
            try{
                $result=$this->receiveDue($row['child_order_id']);
                if(($result['action']??null)==='receive')++$out['received'];
                elseif(in_array($result['state']??null,['already_received','not_due','aftersale_paused','fulfillment_closed'],true))++$out[$result['state']];
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
        ?int $customer,?array $normalized,bool $due,?array $publicSelection=null):array
    {
        if($publicSelection!==null&&($action!=='receive'||$customer===null||$due||$normalized!==null))self::unavailable();
        $phase='locked_payment';$childId=$candidate['child_order_id']??null;$operationId=null;
        try{
            $this->outside($connection,$pdo);
            $result=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$candidate,$action,$ctx,
                $customer,$normalized,$due,$publicSelection,&$phase,&$childId,&$operationId):array{
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
                $phase='locked_complete_native_snapshot';
                $snapshot=$this->lockFulfillmentSnapshot($connection,$pdo,$root,$payment);
                $capture=$snapshot['capture'];$context=$snapshot['context'];$family=$snapshot['family'];
                $inspection=$snapshot['inspection'];$history=$snapshot['history'];$original=$inspection['credit_facts'];
                $operations=array_values($history['operations']);$s2=$inspection['last_action_id']!==null;
                $this->inside($actual,$connection,$pdo);
                $target=null;$mapping=[];
                if($action==='receive'){
                    $childId=$candidate['child_order_id'];
                    $target=$history['ships_by_child'][$childId]??null;
                    if($target===null||($history['children'][$childId]['order_id']??null)!==$candidate['child_order_no'])self::unavailable();
                    $availability=$s2?MarketplaceOrderRefundFacts::receiptAvailabilityFromInspection($inspection,$childId):null;
                    $mapping=$s2?$availability['eligible']:self::eligible($target,$history);
                    if($due){
                        $now=self::now();
                        if($target['receipt_due_at']===0||$target['receipt_due_at']>$now)
                            return $s2?$this->noAftersaleOperation($actual,$connection,$pdo,'not_due',$candidate,$inspection,$phase):$this->noOperation($actual,$connection,$pdo,'not_due',$candidate,$phase);
                        if($mapping===[])return $s2?$this->noAftersaleOperation($actual,$connection,$pdo,self::emptyAftersaleReceiptState($inspection,$childId,$availability),$candidate,$inspection,$phase):$this->noOperation($actual,$connection,$pdo,'already_received',$candidate,$phase);
                        $key='auto:'.$target['id'].':'.hash('sha256',Facts::json(self::coordinates($mapping,true),65536));
                        $normalized=MarketplaceOrderFulfillmentInput::receive($candidate['root_order_id'],$childId,
                            ['idem_key'=>$key,'selection'=>['mode'=>'all_eligible']]);
                    }
                }
                if($publicSelection!==null){
                    $this->inside($actual,$connection,$pdo);
                    $normalized=self::normalizePublicReceipt($candidate,$history,$publicSelection);
                    $this->inside($actual,$connection,$pdo);
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
                $phase='new_action_admission';if($s2)self::normal($root);self::holdClear($payment['registry']);
                $proposal=null;$childData=null;$insertedItems=[];$rangeWrites=[];$now=self::now();
                if($action==='ship'){
                    self::shipAdmission($payment['merchant']);
                    if($s2){$proposal=MarketplaceOrderRefundFacts::prepareFulfillment($capture,$context,$payment,$action,$normalized['request'],$now);
                        $plan=$proposal['plan'];$mapping=$proposal['mapping'];}
                    else{$plan=MarketplaceOrderFulfillmentPlan::plan($root,$original['items'],$original['reserves'],
                        self::coordinates($history['ship_ranges']),$normalized['request']['quantities']);
                        MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$plan);}
                    $this->inside($actual,$connection,$pdo);
                    $deadline=$this->deadline($now);$this->inside($actual,$connection,$pdo);
                    $phase='new_child_number';
                    $number=Facts::text($this->numbers->getNewOrderId('cf'),32,true);
                    $this->inside($actual,$connection,$pdo);
                    $childData=self::childData($root,$plan,$normalized['request'],$now,$number,bin2hex(random_bytes(16)));
                    $phase='insert_child';$childId=$this->dao->insertChild($pdo,$childData);
                    $child=$this->dao->child($pdo,$childId);self::childStored($child,$childData);
                    if(!$s2)$mapping=array_map(static fn(array $a):array=>$a+['source_ship_range_id'=>null,'child_item_id'=>null],$plan['allocations']);
                }else{
                    if($mapping===[])return $s2?$this->noAftersaleOperation($actual,$connection,$pdo,self::emptyAftersaleReceiptState($inspection,$childId,$availability),$candidate,$inspection,$phase):
                        $this->noOperation($actual,$connection,$pdo,'already_received',$candidate,$phase);
                    if($s2){$proposal=MarketplaceOrderRefundFacts::prepareFulfillment($capture,$context,$payment,$action,$normalized['request'],$now);
                        $plan=$proposal['plan'];$mapping=$proposal['mapping'];}
                    else{$mapping=self::selectReceive($mapping,$normalized['request']['selection']);
                        $plan=MarketplaceOrderFulfillmentPlan::exactRangesPlan($root,$original['items'],$original['reserves'],self::coordinates($mapping));
                        MarketplaceOrderFulfillmentPlan::savedProjection($root,$original['items'],$plan);}
                    self::sameIntervals($plan['allocations'],$mapping);$deadline=null;
                }
                $phase='insert_operation';
                $operation=self::operationData($root,$childId,$action,$normalized,$plan,$mapping,$target,$ctx,$customer,$now,$deadline,$proposal);
                $operationId=$this->dao->insertOperation($pdo,$operation);
                if($action==='ship'){
                    $sourceItems=self::indexRows($original['items'],'id');$itemMap=[];
                    foreach(self::itemGroups($plan) as$sourceId=>$allocations){
                        $data=self::childItemData($root,$childId,$operationId,$sourceItems[$sourceId],$allocations);
                        $itemMap[$sourceId]=$this->dao->insertChildItem($pdo,$data);$insertedItems[$itemMap[$sourceId]]=$data;
                    }
                    foreach($mapping as&$row)$row['child_item_id']=$itemMap[$row['source_item_id']];unset($row);
                }
                $rangeRefs=[];$phase='insert_ranges';
                foreach($plan['allocations'] as$i=>$allocation){
                    $range=self::rangeData($operation+['id'=>$operationId],$allocation,$mapping[$i]);
                    $rangeId=$this->dao->insertRange($pdo,$range);$rangeWrites['r:'.$rangeId]=Facts::rangeRow(['id'=>$rangeId]+$range);
                    $rangeRefs[]=['range_id'=>$rangeId,'source_item_id'=>$allocation['source_item_id'],
                        'child_item_id'=>$mapping[$i]['child_item_id'],'source_ship_range_id'=>$mapping[$i]['source_ship_range_id'],
                        'unit_start'=>$allocation['unit_start'],'unit_end'=>$allocation['unit_end']];
                }
                // Re-read the complete native family while the one new state0 is still private to this transaction.
                $phase='native_readback';
                $allOperations=$this->dao->lockOperations($pdo,$candidate['root_order_id']);
                $allFamily=$this->dao->lockFamily($pdo,$root);
                self::pendingFulfillmentDelta($family,$allFamily,$operations,$allOperations,$operationId,$operation,$childData,$insertedItems,$rangeWrites);
                $summary=$s2?$proposal['summary']:self::summary($root,$original['items'],$allFamily['children'],$allFamily['ranges']);
                if($s2){
                    $s=0;$r=0;foreach($allFamily['ranges'] as$row){if($row['operation_action']==='ship')$s+=$row['quantity'];else $r+=$row['quantity'];}
                    $t=$inspection['history']['returned'];$received=[];foreach($allFamily['ranges'] as$row)if($row['operation_action']==='receive')$received[]=$row;
                    $intersection=self::coordinateIntersection($received,$t);
                    if($s!==$summary['shipped']||$r!==$summary['received']||$summary['cancelled_unshipped']!==$inspection['summary']['cancelled_unshipped']
                        ||$summary['returned_accepted']!==self::coordinateQuantity($t)||$summary['to_ship']!==$summary['original']-$s-$summary['cancelled_unshipped']
                        ||$summary['to_receive']!==$s-$r-self::coordinateQuantity($t)+$intersection
                        ||$summary['child_count']!==count($allFamily['children'])||$proposal['aftersale']['returned_after_receipt']!==$intersection)self::unavailable();
                }
                foreach($allFamily['children'] as$row){
                    $id=Facts::integer($row['id'],1,self::INT_MAX);
                    $closed=$s2?self::childAftersaleClosed($id,$allFamily['ranges'],$inspection['history']['returned']):self::childReceived($id,$allFamily['ranges']);
                    $this->dao->status($pdo,$row,$closed?2:1);
                }
                $newStatus=$s2?($summary['to_ship']>0?0:($summary['to_receive']>0?1:($summary['shipped']>0?2:0))):self::rootStatus($summary);$oldStatus=Facts::integer($root['status'],0,2);
                $afterRoot=$this->dao->status($pdo,$root,$newStatus);
                if($newStatus!==$oldStatus)$this->dao->statusLog($pdo,$candidate['root_order_id'],$action,$now);
                $child=$this->dao->child($pdo,$childId);
                $committedAt=self::now();if($committedAt<$now)self::unavailable();
                $internal=self::result($operation+['id'=>$operationId],$afterRoot,$child,$plan,$rangeRefs,$summary,$committedAt,$proposal['aftersale']??null);
                $phase='finish_operation';$this->dao->finishOperation($pdo,$operationId,$internal,$committedAt);
                // Original source rows/ledger/payment must stay native-equal, except root.status and actual new child list.
                $finalPayment=$this->payments->lockPayment($pdo,$candidate['uid'],$candidate['root_order_id'],$candidate['root_order_no']);
                $expectedPayment=$payment;$expectedPayment['root']['status']=$newStatus;
                if($finalPayment!==$expectedPayment)self::unavailable();
                $finalRoot=$this->dao->lockRoot($pdo,$candidate);if($finalRoot!==$afterRoot)self::unavailable();
                $finalSnapshot=$this->lockFulfillmentSnapshot($connection,$pdo,$finalRoot,$finalPayment);
                $finalCapture=$finalSnapshot['capture'];$finalContext=$finalSnapshot['context'];$finalFamily=$finalSnapshot['family'];
                $finalInspection=$finalSnapshot['inspection'];$finalHistory=$finalSnapshot['history'];$finalOperations=array_values($finalHistory['operations']);
                foreach(['items','selection','reserves','credit_rows'] as$key)if($finalCapture[$key]!==$capture[$key])self::unavailable();
                if($finalContext['actions']!==$context['actions']||$finalContext['refunds']!==$context['refunds']
                    ||$finalContext['cut_id']!==$context['cut_id']||!self::same($finalInspection['summary'],$summary)
                    ||($s2&&!self::same($finalInspection['aftersale'],$proposal['aftersale'])))self::unavailable();
                $finalChildren=self::indexRows($finalFamily['children'],'id');
                foreach($family['children'] as$prior){
                    $id=Facts::integer($prior['id'],1,self::INT_MAX);
                    $closed=$s2?self::childAftersaleClosed($id,$finalFamily['ranges'],$finalInspection['history']['returned']):self::childReceived($id,$finalFamily['ranges']);
                    $status=$closed?2:1;$prior['status']=is_int($prior['status'])?$status:(string)$status;
                    if(($finalChildren[$id]??null)!==$prior)self::unavailable();
                }
                $finalItems=self::indexRows($finalFamily['items'],'id');foreach($family['items'] as$prior)
                    if(($finalItems[Facts::integer($prior['id'],1,self::INT_MAX)]??null)!==$prior)self::unavailable();
                $finalOperationMap=[];foreach($finalOperations as$row)$finalOperationMap[$row['id']]=$row;
                foreach($operations as$prior)if(!isset($finalOperationMap[$prior['id']])||!self::same($finalOperationMap[$prior['id']],$prior))self::unavailable();
                $finalRangeMap=self::rangeRowsById($finalFamily['ranges']);foreach($family['ranges'] as$prior)
                    if(($finalRangeMap['r:'.$prior['id']]??null)!==$prior)self::unavailable();
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

    /** Fixed lock order after actual payment/root/actor admission, complete native inputs before interpretation. */
    private function lockFulfillmentSnapshot(ConnectionInterface $connection,PDO $pdo,array $root,array $payment):array
    {
        $operations=$this->refunds->lockOperationsForRoot($connection,$pdo,$root);
        $capture=$this->orders->capturePaymentApplicationFacts($pdo,Facts::integer($root['uid'],1,self::INT_MAX),Facts::integer($root['id'],1,self::INT_MAX));
        if($capture['root']!==$root)self::unavailable();
        $family=$this->dao->lockFamily($pdo,$root);
        $logs=$this->refunds->lockStatusLogsForRoot($connection,$pdo,$root);
        $actions=$this->refunds->lockActionsForRoot($connection,$pdo,$root);
        $context=self::aftersaleContext($actions,$family['refunds'],$operations,$family['ranges'],$logs);
        $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$payment);
        $this->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);
        if($inspection['last_action_id']===null){
            $typed=array_map([Facts::class,'operation'],$operations);foreach($typed as$op)
                if($op['contract_version']!==1||$op['aftersale_cut_id']!==null)self::unavailable();
            $fulfillmentLogs=[];foreach($logs as$row)if(in_array($row['change_type'],['marketplace_fulfillment_ship','marketplace_fulfillment_receive'],true))$fulfillmentLogs[]=$row;
            $history=$this->history($root,$inspection['credit_facts'],$family,$typed,$fulfillmentLogs);
        }else $history=self::historyFromAftersaleInspection($family,$inspection);
        return ['capture'=>$capture,'context'=>$context,'family'=>$family,'inspection'=>$inspection,'history'=>$history];
    }

    /** One real private state0 and its exact native rows; no simulated state1 is sent to the business replay. */
    private static function pendingFulfillmentDelta(array $beforeFamily,array $afterFamily,array $priorOperations,array $allOperations,string $operationId,array $operation,
        ?array $childData,array $insertedItems,array $rangeWrites):void
    {
        $map=[];foreach($allOperations as$row){$id=$row['id'];if(isset($map[$id]))self::unavailable();$map[$id]=$row;}
        if(count($map)!==count($priorOperations)+1||($map[$operationId]??null)!==Facts::operation(['id'=>$operationId]+$operation))self::unavailable();
        foreach($priorOperations as$row)if(!isset($map[$row['id']])||!self::same($map[$row['id']],$row))self::unavailable();
        foreach(['children','items','ranges'] as$key){
            $before=$key==='ranges'?self::rangeRowsById($beforeFamily[$key]):self::indexRows($beforeFamily[$key],'id');$after=$key==='ranges'?self::rangeRowsById($afterFamily[$key]):self::indexRows($afterFamily[$key],'id');
            foreach($before as$id=>$row)if(($after[$id]??null)!==$row)self::unavailable();
            $extra=array_diff_key($after,$before);
            if($key==='children'){
                if(count($extra)!==($childData===null?0:1))self::unavailable();
                if($childData!==null){if(!isset($extra[$operation['child_order_id']]))self::unavailable();self::childStored($extra[$operation['child_order_id']],$childData);}
            }elseif($key==='items'){
                if(count($extra)!==count($insertedItems)||array_keys($extra)!==array_keys($insertedItems))self::unavailable();
                foreach($insertedItems as$id=>$expected){self::itemStored($extra[$id],$expected);if(($extra[$id]['old_cart_id']??null)!==null)self::unavailable();}
            }else{
                if(count($extra)!==count($rangeWrites)||array_keys($extra)!==array_keys($rangeWrites))self::unavailable();
                foreach($rangeWrites as$id=>$expected)if($extra[$id]!==$expected)self::unavailable();
            }
        }
        if($afterFamily['refunds']!==$beforeFamily['refunds'])self::unavailable();
    }

    private static function rangeRowsById(array $rows):array
    {
        if(!array_is_list($rows))self::unavailable();$out=[];$previous='0';
        foreach($rows as$row){$id=Facts::u64($row['id']);if(bccomp($id,$previous,0)<=0)self::unavailable();$previous=$id;$out['r:'.$id]=$row;}return $out;
    }
    private static function childAftersaleClosed(int $childId,array $ranges,array $returned):bool
    {
        $ships=[];$received=[];$returns=[];
        foreach($ranges as$row)if($row['child_order_id']===$childId){if($row['operation_action']==='ship')$ships[]=$row;elseif($row['operation_action']==='receive')$received[]=$row;else self::unavailable();}
        foreach($returned as$row)if($row['child_order_id']===$childId)$returns[]=$row;
        $q=self::coordinateQuantity($ships);if($q<1)self::unavailable();
        $left=$q-self::coordinateQuantity($received)-self::coordinateQuantity($returns)+self::coordinateIntersection($received,$returns);
        if($left<0)self::unavailable();return $left===0;
    }

    private function noAftersaleOperation(ConnectionInterface $actual,ConnectionInterface $connection,PDO $pdo,string $state,array $candidate,array $inspection,string &$phase):array
    {
        if(!in_array($state,['already_received','not_due','aftersale_paused','fulfillment_closed'],true)||$inspection['last_action_id']===null)self::unavailable();
        $this->inside($actual,$connection,$pdo);$phase='commit_outcome_unknown';
        return ['schema'=>2,'state'=>$state,'operation_created'=>false,'root_order_id'=>$candidate['root_order_id'],
            'child_order_id'=>$candidate['child_order_id'],'aftersale'=>$inspection['aftersale']];
    }

    private static function emptyAftersaleReceiptState(array $inspection,int $childId,array $availability):string
    {
        if($availability['eligible']!==[])self::unavailable();
        if($availability['remaining']!==[]){if($availability['paused']===[])self::unavailable();return 'aftersale_paused';}
        $s=0;$r=0;foreach($inspection['history']['ships'] as$row)if($row['child_order_id']===$childId)$s+=$row['quantity'];
        foreach($inspection['history']['received'] as$row)if($row['child_order_id']===$childId)$r+=$row['quantity'];
        if($s<1||$r>$s)self::unavailable();return $r===$s?'already_received':'fulfillment_closed';
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

    private static function allocationDigest(array $operation,array $plan,array $mapping,?array $aftersale=null):string
    {
        self::sameIntervals($plan['allocations'],$mapping);$allocations=[];
        foreach($plan['allocations'] as$i=>$row){
            $parent=$mapping[$i]['source_ship_range_id'];
            if($operation['action']==='ship'){if($parent!==null)self::unavailable();}
            else{if(!is_string($parent))self::unavailable();Facts::u64($parent);}
            $allocations[]=$row+['source_ship_range_id'=>$parent];
        }
        $base=['schema'=>1,'action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'child_order_id'=>$operation['child_order_id'],'source_snapshot_sha256'=>$plan['source_snapshot_sha256'],
            'target_operation_id'=>$operation['target_operation_id'],'allocations'=>$allocations];
        if($operation['contract_version']===1){if($operation['aftersale_cut_id']!==null||$aftersale!==null)self::unavailable();$digest=$base;}
        elseif($operation['contract_version']===2){if($aftersale===null||$aftersale['revision']!==$operation['aftersale_cut_id'])self::unavailable();
            $digest=['schema'=>2,'base'=>$base,'aftersale_cut_id'=>$operation['aftersale_cut_id'],'aftersale'=>$aftersale];}
        else self::unavailable();return hash('sha256',Facts::json($digest,1048576),true);
    }

    private static function operationData(array $root,int $childId,string $action,array $normalized,array $plan,array $mapping,
        ?array $target,?MerchantContext $ctx,?int $customer,int $now,?int $deadline,?array $proposal=null):array
    {
        $data=['contract_version'=>$proposal===null?1:2,'root_order_id'=>Facts::integer($root['id'],1,self::INT_MAX),'child_order_id'=>$childId,
            'uid'=>Facts::integer($root['uid'],1,self::INT_MAX),'mer_id'=>Facts::integer($root['mer_id'],1,self::UINT_MAX),
            'action'=>$action,'idem_key'=>$normalized['idem_key'],'request_digest'=>hash('sha256',Facts::json($normalized['request'],65536),true),
            'source_snapshot_digest'=>self::sourceDigest($plan),'allocation_digest'=>'',
            'request_payload'=>Facts::json($normalized['request'],65536),'result_payload'=>null,'state'=>0,
            'ship_child_order_id'=>$action==='ship'?$childId:null,'target_operation_id'=>$target['id']??null,
            'target_action'=>$action==='ship'?null:'ship','actor_type'=>$action==='ship'?'employee':($customer===null?'system':'customer'),
            'actor_id'=>$action==='ship'?Facts::integer($ctx?->employeeId,1,self::UINT_MAX):$customer,
            'created_at'=>$now,'committed_at'=>null,'shipped_at'=>$action==='ship'?$now:null,
            'receipt_due_at'=>$action==='ship'?$deadline:null,'received_at'=>$action==='receive'?$now:null];
        $data['aftersale_cut_id']=$proposal['aftersale_cut_id']??null;
        $data['allocation_digest']=self::allocationDigest($data,$plan,$mapping,$proposal['aftersale']??null);return $data;
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

    private static function result(array $operation,array $root,array $child,array $plan,array $ranges,array $summary,int $committedAt,?array $aftersale=null):array
    {
        $public=['schema'=>$operation['contract_version'],'operation_id'=>$operation['id'],'action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'root_order_no'=>Facts::text($root['order_id'],32,true),'child_order_id'=>$operation['child_order_id'],
            'child_order_no'=>Facts::text($child['order_id'],32,true),'quantity'=>$plan['totals']['quantity'],
            'created_at'=>$operation['created_at'],'committed_at'=>$committedAt,'shipped_at'=>$operation['shipped_at'],
            'receipt_due_at'=>$operation['receipt_due_at'],'received_at'=>$operation['received_at'],'fulfillment_summary'=>$summary];
        $internal=['schema'=>$operation['contract_version'],'operation_id'=>$operation['id'],'action'=>$operation['action'],'root_order_id'=>$operation['root_order_id'],
            'root_order_no'=>$public['root_order_no'],'child_order_id'=>$operation['child_order_id'],'child_order_no'=>$public['child_order_no'],
            'target_operation_id'=>$operation['target_operation_id'],'actor_type'=>$operation['actor_type'],'actor_id'=>$operation['actor_id'],
            'created_at'=>$operation['created_at'],'committed_at'=>$committedAt,'shipped_at'=>$operation['shipped_at'],
            'receipt_due_at'=>$operation['receipt_due_at'],'received_at'=>$operation['received_at'],
            'plan'=>$plan,'ranges'=>$ranges,'summary'=>$summary,'public_result'=>$public];
        if($operation['contract_version']===2){if($aftersale===null||$aftersale['revision']!==$operation['aftersale_cut_id'])self::unavailable();
            $public['aftersale']=$aftersale;$internal['public_result']=$public;$internal['aftersale_cut_id']=$operation['aftersale_cut_id'];$internal['aftersale']=$aftersale;
        }elseif($operation['contract_version']!==1||$aftersale!==null||$operation['aftersale_cut_id']!==null)self::unavailable();
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
        if(!$pdo instanceof PDO)self::unavailable();$this->outside($connection,$pdo);$this->dao->assertAftersaleStructure($pdo);return [$connection,$pdo];
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
