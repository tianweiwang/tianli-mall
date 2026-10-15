<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\system\config\SystemConfigDao;
use app\services\order\StoreOrderComputedServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\user\UserInvoiceServices;
use app\services\user\UserServices;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Internal V1 creation. The HTTP controller still enforces the unfinished trade gate. */
final class MarketplaceOrderCreateServices
{
    private const UINT_MAX=4294967295;
    private const CONFIGURATION=['order_cancel_time','offline_postage','offline_pay_status','pay_weixin_open',
        'ali_pay_status','friend_pay_status','allin_pay_status'];

    public function __construct(private MarketplaceOrderDao $dao,private StoreOrderServices $orders,
        private StoreOrderComputedServices $computed,private UserServices $users,private UserInvoiceServices $invoices,
        private SystemConfigDao $configuration,private StoreOrderCreateServices $orderNumbers,
        private MarketplaceOrderPostCommitServices $postCommit) {}

    /** The caller supplies the actual authenticated UID, route key, and unfiltered parsed POST. */
    public function create(int $uid,string $routeKey,array $rawPost):array
    {
        $input=MarketplaceOrderInput::create($uid,$routeKey,$rawPost);$request=$input['request'];
        $committed=Db::transaction(function(ConnectionInterface $connection)use($uid,$routeKey,$rawPost,$input,$request):array{
            $pdo=self::pdo($connection);$now=time();
            // UID lock and durable replay precede every cache/current-configuration dependency.
            $claim=$this->dao->claim($pdo,$request,$input['idem_key'],$now,0);
            if(!$claim['claimed'])return ['order_id'=>$claim['order']['order_id'],'key'=>$routeKey,
                'order_pk'=>MarketplaceOrderInput::unsigned($claim['order']['id'],'订单编号',2147483647)];
            if(!MarketplaceTradePolicy::active())throw new ApiException('当前商城下单尚未开放');
            $options=$request['options'];MarketplaceTradePolicy::preview($rawPost,$options);
            $cached=CacheService::get('user_order_'.$uid.$routeKey);
            if(!is_array($cached)||!is_array($cached['cartInfo']??null))throw new ApiException('订单已过期,请刷新当前页面');
            $frame=MarketplaceCartSelection::frame($cached,$uid);
            if($frame['mer_id']!==$request['mer_id']||$frame['source_kind']!==$request['source_kind']
                ||$frame['cart_refs']!==$request['cart_refs'])throw new ApiException('购物车选择已变化，请重新确认');
            // No ordinary SQL read before all selected catalog/freight/address/cart rows are locked.
            $locked=$this->dao->lockInputs($pdo,$uid,$request['mer_id'],$frame,$request['address_id'],$options['invoice_id']);
            $group=$this->orders->getCacheOrderInfo($uid,$routeKey);
            if(!is_array($group)||MarketplaceCartSelection::frame($group,$uid)!==$frame)throw new ApiException('请重新确认本店商品');
            $userModel=$this->users->getUserInfo($uid);
            if($userModel===null)throw new AuthUnavailableException();
            $user=$userModel->toArray();
            if((int)($user['uid']??0)!==$uid)throw new AuthUnavailableException();
            $rawConfiguration=$this->configuration->getConfigAll(self::CONFIGURATION);
            self::validatePayment($options['payType'],$rawConfiguration);
            $cancelAt=self::cancelAt($now,$rawConfiguration['order_cancel_time']??null);
            $group['other']['offlinePostage']=self::configurationValue($rawConfiguration,'offline_postage','0');
            if($options['invoice_id']!==0)$this->invoices->checkInvoice($options['invoice_id'],$uid);
            $computed=$this->computed->setParamData([])->computedOrder($uid,$user,$group,$request['address_id'],
                $options['payType'],false,0,true,1,0,true);
            if(self::pdo($connection)!==$pdo)throw new AuthUnavailableException();
            $quote=MarketplaceOrderLockedQuote::verify($uid,$request['mer_id'],$request['address_id'],$locked,$group,$computed);
            $customForm=MarketplaceOrderCustomForm::snapshot($frame['custom_form'],$options['custom_form'],
                $locked['products'],$locked['selection'],$locked['source_kind']);
            $address=$quote['address'];$orderNumber=$this->orderNumbers->getNewOrderId('cp');
            $order=['uid'=>$uid,'mer_id'=>$request['mer_id'],'order_id'=>$orderNumber,'unique'=>$routeKey,
                'cart_id'=>$locked['cart_refs'],'total_num'=>array_sum(array_column($locked['selection'],'qty')),
                'total_price'=>$group['priceGroup']['totalPrice'],'total_postage'=>$computed['pay_postage'],
                'pay_price'=>$computed['pay_price'],'pay_postage'=>$computed['pay_postage'],'cost'=>$group['priceGroup']['costPrice'],
                'add_time'=>$now,'real_name'=>$address['real_name'],'user_phone'=>$address['phone'],
                'user_address'=>$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail'],
                'mark'=>$options['mark'],'gift_mark'=>$options['gift_mark'],'pay_type'=>$options['payType'],
                'channel_type'=>$user['user_type'],'province'=>'','custom_form'=>$customForm,
                'merchant_name_snapshot'=>$locked['merchant']['name'],
                'merchant_after_sale_contact_snapshot'=>$locked['merchant']['after_sale_contact']];
            $orderId=$this->dao->insertOrder($pdo,$order,$locked['invoice']);
            $reserves=$this->dao->reserve($pdo,$uid,$request['mer_id'],$orderId,$locked,$now);
            $this->dao->insertItems($pdo,$uid,$request['mer_id'],$orderId,$quote['cartInfo'],$reserves);
            $this->dao->markCreated($pdo,$uid,$claim['idem_key'],$orderId,$locked,$cancelAt);
            // The internal PK wakes the finisher only after this transaction actually ends.
            return ['order_id'=>$orderNumber,'key'=>$routeKey,'order_pk'=>$orderId];
        });
        // Only completion is best effort. A post-commit/logging error must not turn an existing order into a create failure.
        try{
            $finished=$this->postCommit->finish($committed['order_pk']);
            if($finished['post_commit_done']!==1)MarketplaceOrderPostCommitServices::recordPending($committed['order_pk']);
        }catch(\Throwable $error){MarketplaceOrderPostCommitServices::recordPending($committed['order_pk']);}
        return ['order_id'=>$committed['order_id'],'key'=>$committed['key']];
    }

    /** A point-in-time owner-only result lookup, not cancellation or proof that a delayed request ended. */
    public function lookupConfirmation(int $uid,string $key):array
    {
        if(MarketplaceOrderInput::unsigned($uid,'uid',2147483647)===0)throw new ApiException('顾客编号格式错误');
        $key=MarketplaceOrderInput::confirmationKey($key);
        return Db::transaction(function(ConnectionInterface $connection)use($uid,$key):array{
            $pdo=self::pdo($connection);
            $result=$this->dao->lookupConfirmation($pdo,$uid,$key);
            if(self::pdo($connection)!==$pdo)throw new AuthUnavailableException();
            return $result;
        });
    }

    /** Exact raw JSON hours from the existing configuration producer; zero disables timeout. */
    public static function cancelAt(int $now,mixed $rawHours):int
    {
        if($now<1||$now>self::UINT_MAX)throw new AuthUnavailableException();
        try{$hours=MarketplaceDecimal::jsonNumber($rawHours);}
        catch(\Throwable $error){throw new AuthUnavailableException();}
        $scale=strlen(explode('.',$hours,2)[1]??'');$sign=bccomp($hours,'0',$scale);
        if($sign<0)throw new AuthUnavailableException();
        if($sign===0)return 0;
        $seconds=bcmul($hours,'3600',0);
        if(bccomp($seconds,(string)(self::UINT_MAX-$now),0)>0)throw new AuthUnavailableException();
        return (int)bcadd((string)$now,$seconds,0);
    }

    /** Empty defers selection; nonempty channels follow the existing actual switches. No payment is initiated. */
    public static function validatePayment(string $payType,array $rawConfiguration):void
    {
        if($payType==='')return;
        if($payType===PayServices::YUE_PAY)MarketplaceTradePolicy::denyFeature('balance');
        if(!array_key_exists($payType,PayServices::PAY_TYPE))throw new ApiException('支付方式不可用');
        $enabled=match($payType){
            PayServices::WEIXIN_PAY=>!in_array(self::configurationValue($rawConfiguration,'pay_weixin_open','0'),['','0'],true),
            PayServices::OFFLINE_PAY=>self::configurationValue($rawConfiguration,'offline_pay_status','0')=='1',
            PayServices::ALIAPY_PAY=>!in_array(self::configurationValue($rawConfiguration,'ali_pay_status','0'),['','0'],true),
            PayServices::FRIEND=>self::configurationValue($rawConfiguration,'friend_pay_status','0')=='1',
            PayServices::ALLIN_PAY=>self::configurationValue($rawConfiguration,'allin_pay_status','0')=='1',
            default=>false,
        };
        if(!$enabled)throw new ApiException('支付方式未开启');
    }

    private static function configurationValue(array $raw,string $name,string $default):string
    {
        $value=$raw[$name]??null;
        if($value===null)return $default;
        if(!is_string($value))throw new AuthUnavailableException();
        try{$value=json_decode($value,true,8,JSON_THROW_ON_ERROR);}
        catch(\JsonException $error){throw new AuthUnavailableException();}
        if($value===null)return $default;
        if(!is_scalar($value))throw new AuthUnavailableException();
        if(is_bool($value))return $value?'1':'0';
        return trim((string)$value);
    }

    private static function pdo(ConnectionInterface $connection):PDO
    {
        $default=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO||$connection!==$default||$pdo!==$default->getPdo()||!$pdo->inTransaction()
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new AuthUnavailableException();
        $emulate=$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if($emulate!==false&&$emulate!==0)throw new AuthUnavailableException();
        return $pdo;
    }
}
