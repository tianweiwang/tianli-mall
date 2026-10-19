<?php
declare(strict_types=0);

/** Frozen original selected-row formatter reference for this normal legacy fixture only. No HTTP/auth adapter. */
namespace app\services\order;

use app\dao\order\StoreOrderDao;
use app\dao\marketplace\order\MarketplaceOrderDao;
use app\jobs\AutoCommentJob;
use app\services\activity\combination\StorePinkServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\BaseServices;
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderCancelServices;
use app\services\marketplace\order\MarketplaceOrderSnapshotProjection as SavedOrder;
use app\services\marketplace\order\MarketplaceTradePolicy;
use app\services\other\PosterServices;
use app\services\other\QrcodeServices;
use app\services\other\UploadService;
use app\services\pay\OrderPayServices;
use app\services\pay\PayServices;
use app\services\product\product\StoreProductLogServices;
use app\services\serve\ServeServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\system\store\SystemStoreServices;
use app\services\system\SystemTicketServices;
use app\services\user\UserInvoiceServices;
use app\services\user\UserRechargeServices;
use app\services\user\UserServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\user\UserAddressServices;
use app\services\user\UserBillServices;
use app\services\user\UserLevelServices;
use app\services\wechat\WechatUserServices;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\PayException;
use crmeb\services\app\MiniProgramService;
use crmeb\services\CacheService;
use crmeb\services\easywechat\orderShipping\MiniOrderService;
use crmeb\services\FormBuilder as Form;
use crmeb\services\printer\Printer;
use crmeb\services\SystemConfigService;
use crmeb\utils\Arr;
use Guzzle\Http\EntityBody;
use think\facade\Log;

use PDO;

final class M4ThreeLegacyReference
{
    private int $ownerUid;
    private StoreOrderDao $dao;

    public function __construct(int $actualOwnerUid)
    {
        \m4fn_expect($actualOwnerUid>0,'TASK6_REFERENCE_REAL_SAVED_OWNER');
        $this->ownerUid=$actualOwnerUid;
        $this->dao=app()->make(StoreOrderDao::class);
    }

    /** Independent original Model selection, never the candidate selected-header materializer. */
    private function load(PDO $pdo,int $id,array $with):\app\model\order\StoreOrder
    {
        $default=\think\facade\Db::connect()->getPdo();
        \m4fn_expect($id>0&&$default instanceof PDO&&!$default->inTransaction()&&!$pdo->inTransaction(),
            'TASK6_REFERENCE_OUTSIDE_OWNED_TRANSACTION');
        $rows=\m5pcp_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$id]);
        \m4fn_expect(count($rows)===1,'TASK6_REFERENCE_ACTUAL_SINGLE_LEGACY');$saved=$rows[0];
        \m4fn_expect(array_key_exists('marketplace_trade_version',$saved)
            &&in_array($saved['marketplace_trade_version'],[null,0,'0'],true)
            &&(string)$saved['uid']===(string)$this->ownerUid,'TASK6_REFERENCE_ACTUAL_LEGACY_SAME_BUYER');
        foreach(['pid'=>0,'paid'=>0,'is_cancel'=>0,'is_gift'=>0,'virtual_type'=>0,'shipping_type'=>1,
            'store_id'=>0,'delivery_uid'=>0,'is_channel'=>0]as$key=>$expected)
            \m4fn_expect(array_key_exists($key,$saved)&&(is_int($saved[$key])||is_string($saved[$key]))
                &&(string)$saved[$key]===(string)$expected,'TASK6_REFERENCE_FIXED_NORMAL_LEGACY_MATERIAL');
        \m4fn_expect(($saved['verify_code']??null)==='','TASK6_REFERENCE_NO_EXTERNAL_QR_OR_MANAGED_SAMPLE');
        $model=(new \app\model\order\StoreOrder())->with($with)->where('id',$id)->find();
        \m4fn_expect($model instanceof \app\model\order\StoreOrder,'TASK6_REFERENCE_ACTUAL_ORIGINAL_MODEL');
        $actual=$model->getData();
        foreach($saved as$key=>$value)\m4fn_expect(array_key_exists($key,$actual)&&$actual[$key]===$value,
            'TASK6_REFERENCE_MODEL_MATCHES_INDEPENDENT_NATIVE_HEADER');
        return $model;
    }

    public function customerList(PDO $pdo,int $id):array
    {
        $model=$this->load($pdo,$id,['user','spread','refund']);
        return $this->selectedCustomerList([$model->toArray()]);
    }
    public function platformList(PDO $pdo,int $id):array
    {
        $model=$this->load($pdo,$id,['user','spread','refund',
            'split'=>static function($query){$query->field('id,pid');},'pink','invoice','division']);
        return $this->selectedPlatformList([$model->toArray()]);
    }
    public function mobileList(PDO $pdo,int $id):array
    {
        $model=$this->load($pdo,$id,['user','spread','refund','pink']);
        return $this->selectedMobileList([$model->toArray()]);
    }
    public function customerDetail(PDO $pdo,int $id):array
    {
        $model=$this->load($pdo,$id,['split','invoice','user']);
        return $this->selectedCustomerDetail(app()->make(StoreOrderEconomizeServices::class),$model,$this->ownerUid);
    }
    public function mobileDetail(PDO $pdo,int $id):array
    {
        $model=$this->load($pdo,$id,['split','invoice','user']);
        $data=$this->selectedCustomerDetail(app()->make(StoreOrderEconomizeServices::class),$model,0);
        $data['nickname']=app()->make(UserServices::class)->value(['uid'=>$data['uid']],'nickname');
        return $data;
    }
    public function platformDetail(PDO $pdo,int $id):array
    {
        return $this->selectedPlatformDetail($this->load($pdo,$id,['refund','invoice']));
    }

    public $deliveryType = [
        'send' => '商家配送',
        'express' => '快递配送',
        'fictitious' => '虚拟发货',
        'delivery_part_split' => '拆分部分发货',
        'delivery_split' => '拆分发货完成'
    ];


    public function tidyOrder($order, bool $detail = false, $isPic = false)
    {
        if ($detail == true && isset($order['id'])) {
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartInfos = $cartServices->getCartColunm(['oid' => $order['id']], 'cart_num,surplus_num,cart_info,refund_num', 'unique');
            $info = [];
            /** @var StoreProductReplyServices $replyServices */
            $replyServices = app()->make(StoreProductReplyServices::class);
            foreach ($cartInfos as $k => $cartInfo) {
                $cart = json_decode($cartInfo['cart_info'], true);
                $cart['cart_num'] = $cartInfo['cart_num'];
                $cart['surplus_num'] = $cartInfo['surplus_num'];
                $cart['refund_num'] = $cartInfo['refund_num'];
                $cart['surplus_refund_num'] = $cartInfo['surplus_num'] - $cartInfo['refund_num'];
                $cart['unique'] = $k;
                //新增是否评价字段
                $cart['is_reply'] = $replyServices->count(['unique' => $k]);
                if (isset($cart['productInfo']['attrInfo'])) {
                    $cart['productInfo']['attrInfo'] = get_thumb_water($cart['productInfo']['attrInfo']);
                }
                $cart['productInfo'] = get_thumb_water($cart['productInfo']);
                //一种商品买多件  计算总优惠
                $cart['vip_sum_truePrice'] = bcmul($cart['vip_truePrice'], $cart['cart_num'] ? $cart['cart_num'] : 1, 2);
                $cart['is_valid'] = 1;
                array_push($info, $cart);
                unset($cart);
            }
            $order['cartInfo'] = $info;
        }
        /** @var StoreOrderStatusServices $statusServices */
        $statusServices = app()->make(StoreOrderStatusServices::class);
        $status = [];
        if ($order['is_cancel']) {
            $status['_type'] = 4;
            $status['_title'] = '已取消';
            $status['_msg'] = '您已取消订单,感谢您的使用';
            $status['_class'] = 'nobuy';
        } else {
            if (!$order['paid'] && $order['pay_type'] == 'offline' && !$order['status'] >= 2) {
                $status['_type'] = 9;
                $status['_title'] = '线下付款,未支付';
                $status['_msg'] = '等待商家处理,请耐心等待';
                $status['_class'] = 'nobuy';
            } else if (!$order['paid']) {
                $status['_type'] = 0;
                $status['_title'] = '未支付';
                //系统预设取消订单时间段
                $keyValue = ['order_cancel_time', 'order_activity_time', 'order_bargain_time', 'order_seckill_time', 'order_pink_time'];
                //获取配置
                $systemValue = SystemConfigService::more($keyValue);
                //格式化数据
                $systemValue = Arr::setValeTime($keyValue, is_array($systemValue) ? $systemValue : []);
                if ($order['pink_id'] || $order['combination_id']) {
                    $order_pink_time = $systemValue['order_pink_time'] ?: $systemValue['order_activity_time'];
                    $time = $order['add_time'] + $order_pink_time * 3600;
                    $status['_msg'] = '请在' . date('m-d H:i:s', $time) . '前完成支付!';
                } else if ($order['seckill_id']) {
                    $order_seckill_time = $systemValue['order_seckill_time'] ?: $systemValue['order_activity_time'];
                    $time = $order['add_time'] + $order_seckill_time * 3600;
                    $status['_msg'] = '请在' . date('m-d H:i:s', $time) . '前完成支付!';
                } else if ($order['bargain_id']) {
                    $order_bargain_time = $systemValue['order_bargain_time'] ?: $systemValue['order_activity_time'];
                    $time = $order['add_time'] + $order_bargain_time * 3600;
                    $status['_msg'] = '请在' . date('m-d H:i:s', $time) . '前完成支付!';
                } else {
                    $time = $order['add_time'] + $systemValue['order_cancel_time'] * 3600;
                    $status['_msg'] = '请在' . date('m-d H:i:s', (int)$time) . '前完成支付!';
                }
                $status['_class'] = 'nobuy';
            } else if ($order['status'] == 4) {
                if ($order['delivery_type'] == 'send') {//TODO 送货
                    $status['_type'] = 1;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery'], 'change_time')) . '服务商已送货';
                    $status['_class'] = 'state-ysh';
                } elseif ($order['delivery_type'] == 'express') {//TODO  发货
                    $status['_type'] = 1;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_goods'], 'change_time')) . '服务商已发货';
                    $status['_class'] = 'state-ysh';
                } elseif ($order['delivery_type'] == 'split') {//拆分发货
                    $status['_type'] = 1;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_part_split'], 'change_time')) . '服务商已拆分多个包裹发货';
                    $status['_class'] = 'state-ysh';
                } else {
                    $status['_type'] = 1;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_fictitious'], 'change_time')) . '服务商已虚拟发货';
                    $status['_class'] = 'state-ysh';
                }
            } else if ($order['refund_status'] == 1) {
                if (in_array($order['refund_type'], [0, 1, 2])) {
                    $status['_type'] = -1;
                    $status['_title'] = '申请退款中';
                    $status['_msg'] = '商家审核中,请耐心等待';
                    $status['_class'] = 'state-sqtk';
                } elseif ($order['refund_type'] == 4) {
                    $status['_type'] = -1;
                    $status['_title'] = '申请退款中';
                    $status['_msg'] = '商家同意退款,请填写退货订单号';
                    $status['_class'] = 'state-sqtk';
                    $status['refund_name'] = sys_config('refund_name', '');
                    $status['refund_phone'] = sys_config('refund_phone', '');
                    $status['refund_address'] = sys_config('refund_address', '');
                } elseif ($order['refund_type'] == 5) {
                    $status['_type'] = -1;
                    $status['_title'] = '申请退款中';
                    $status['_msg'] = '等待商家收货';
                    $status['_class'] = 'state-sqtk';
                    $status['refund_name'] = sys_config('refund_name', '');
                    $status['refund_phone'] = sys_config('refund_phone', '');
                    $status['refund_address'] = sys_config('refund_address', '');
                }
            } else if ($order['refund_status'] == 2 || $order['refund_type'] == 6) {
                $status['_type'] = -2;
                $status['_title'] = '已退款';
                $status['_msg'] = '已为您退款,感谢您的支持';
                $status['_class'] = 'state-sqtk';
            } else if ($order['refund_status'] == 3) {
                $status['_type'] = -1;
                $status['_title'] = '部分退款（子订单）';
                $status['_msg'] = '拆分发货，部分退款';
                $status['_class'] = 'state-sqtk';
            } else if ($order['refund_status'] == 4) {
                $status['_type'] = -1;
                $status['_title'] = '子订单已全部申请退款中';
                $status['_msg'] = '拆分发货，全部退款';
                $status['_class'] = 'state-sqtk';
            } else if (!$order['status']) {
                if ($order['pink_id']) {
                    /** @var StorePinkServices $pinkServices */
                    $pinkServices = app()->make(StorePinkServices::class);
                    if ($pinkServices->getCount(['id' => $order['pink_id'], 'status' => 1])) {
                        $status['_type'] = 1;
                        $status['_title'] = '拼团中';
                        $status['_msg'] = '等待其他人参加拼团';
                        $status['_class'] = 'state-nfh';
                    } else {
                        $status['_type'] = 1;
                        $status['_title'] = '未发货';
                        $status['_msg'] = '商家未发货,请耐心等待';
                        $status['_class'] = 'state-nfh';
                    }
                } else {
                    if ($order['shipping_type'] === 1) {
                        $status['_type'] = 1;
                        $status['_title'] = '未发货';
                        if ($order['advance_id']) {
                            $status['_msg'] = date('Y-m-d', $order['cartInfo'][0]['productInfo']['presale_end_time']) . '预售结束后' . $order['cartInfo'][0]['productInfo']['presale_day'] . '天内发货,请耐心等待';
                        } else {
                            $status['_msg'] = '商家未发货,请耐心等待';
                        }
                        $status['_class'] = 'state-nfh';
                    } elseif ($order['shipping_type'] === 2) {
                        $status['_type'] = 1;
                        $status['_title'] = '待核销';
                        $status['_msg'] = '待核销,请到核销点进行核销';
                        $status['_class'] = 'state-nfh';
                    } else {
                        $status['_type'] = 1;
                        $status['_title'] = '待领取';
                        $status['_msg'] = '待领取，将礼品转赠给好友吧!';
                        $status['_class'] = 'state-nfh';
                    }
                }
            } else if ($order['status'] == 1) {
                if ($order['delivery_type'] == 'send') {//TODO 送货
                    $status['_type'] = 2;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery'], 'change_time')) . '服务商已送货';
                    $status['_class'] = 'state-ysh';
                } elseif ($order['delivery_type'] == 'express') {//TODO  发货
                    $status['_type'] = 2;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_goods'], 'change_time')) . '服务商已发货';
                    $status['_class'] = 'state-ysh';
                } elseif ($order['delivery_type'] == 'split') {//拆分发货
                    $status['_type'] = 2;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_split'], 'change_time')) . '服务商已拆分多个包裹发货';
                    $status['_class'] = 'state-ysh';
                } else {
                    $status['_type'] = 2;
                    $status['_title'] = '待收货';
                    $status['_msg'] = date('m月d日H时i分', $statusServices->value(['oid' => $order['id'], 'change_type' => 'delivery_fictitious'], 'change_time')) . '服务商已虚拟发货';
                    $status['_class'] = 'state-ysh';
                }
            } else if ($order['status'] == 2) {
                $status['_type'] = 3;
                $status['_title'] = '待评价';
                $status['_msg'] = '已收货,快去评价一下吧';
                $status['_class'] = 'state-ypj';
            } else if ($order['status'] == 3) {
                $status['_type'] = 4;
                $status['_title'] = '交易完成';
                $status['_msg'] = '交易完成,感谢您的支持';
                $status['_class'] = 'state-ytk';
            }
        }
        if (isset($order['pay_type']))
            $status['_payType'] = $status['_type'] == 0 ? '' :
                (in_array($order['marketplace_trade_version'] ?? null, [1, '1'], true) && $order['pay_type'] === 'marketplace_test'
                    ? '测试支付' : (PayServices::PAY_TYPE[$order['pay_type']] ?? '其他方式'));
        if (isset($order['delivery_type']))
            $status['_deliveryType'] = $this->deliveryType[$order['delivery_type']] ?? '其他方式';
        $order['_status'] = $status;
        $order['_pay_time'] = isset($order['pay_time']) && $order['pay_time'] != null ? date('Y-m-d H:i:s', $order['pay_time']) : '';
        $order['_add_time'] = isset($order['add_time']) ? (strstr((string)$order['add_time'], '-') === false ? date('Y-m-d H:i:s', $order['add_time']) : $order['add_time']) : '';

        //系统预设取消订单时间段
        $keyValue = ['order_cancel_time', 'order_activity_time', 'order_bargain_time', 'order_seckill_time', 'order_pink_time'];
        //获取配置
        $systemValue = SystemConfigService::more($keyValue);
        //格式化数据
        $systemValue = Arr::setValeTime($keyValue, is_array($systemValue) ? $systemValue : []);
        if ($order['seckill_id']) {
            $secs = $systemValue['order_seckill_time'] ? $systemValue['order_seckill_time'] : $systemValue['order_activity_time'];
        } elseif ($order['bargain_id']) {
            $secs = $systemValue['order_bargain_time'] ? $systemValue['order_bargain_time'] : $systemValue['order_activity_time'];
        } elseif ($order['combination_id']) {
            $secs = $systemValue['order_pink_time'] ? $systemValue['order_pink_time'] : $systemValue['order_activity_time'];
        } else {
            $secs = $systemValue['order_cancel_time'];
        }
        $order['stop_time'] = $secs * 3600 + $order['add_time'];
        $order['status_pic'] = '';
        //获取商品状态图片
        if ($isPic) {
            $order_details_images = sys_data('order_details_images') ?: [];
            foreach ($order_details_images as $image) {
                if (isset($image['order_status']) && $image['order_status'] == $order['_status']['_type']) {
                    $order['status_pic'] = $image['pic'];
                    break;
                }
            }
        }
        if ($order['seckill_id'] || $order['bargain_id'] || $order['combination_id'] || $order['advance_id']) {
            if ($order['seckill_id']) $order['type'] = 1;
            if ($order['bargain_id']) $order['type'] = 2;
            if ($order['combination_id']) $order['type'] = 3;
            if ($order['advance_id']) $order['type'] = 4;
        }
        $order['offlinePayStatus'] = (int)sys_config('offline_pay_status') ?? (int)2;
        $log = $statusServices->getColumn(['oid' => $order['id']], 'change_time', 'change_type');
        if (isset($log['delivery'])) {
            $delivery = date('Y-m-d', $log['delivery']);
        } elseif (isset($log['delivery_goods'])) {
            $delivery = date('Y-m-d', $log['delivery_goods']);
        } elseif (isset($log['delivery_fictitious'])) {
            $delivery = date('Y-m-d', $log['delivery_fictitious']);
        } else {
            $delivery = '';
        }
        $order['order_log'] = [
            'create' => isset($log['cache_key_create_order']) ? date('Y-m-d', $log['cache_key_create_order']) : '',
            'pay' => isset($log['pay_success']) ? date('Y-m-d', $log['pay_success']) : '',
            'delivery' => $delivery,
            'take' => isset($log['take_delivery']) ? date('Y-m-d', $log['take_delivery']) : '',
            'complete' => isset($log['check_order_over']) ? date('Y-m-d', $log['check_order_over']) : '',
        ];

        $order['gift_user_info'] = [
            'gift_uid' => $order['gift_uid'],
            'gift_nickname' => '',
            'gift_avatar' => '',
        ];
        if ($order['gift_uid'] != 0) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $giftUser = $userServices->get($order['gift_uid'], ['nickname', 'avatar']);
            $order['gift_user_info'] = [
                'gift_uid' => $order['gift_uid'],
                'gift_nickname' => $giftUser['nickname'],
                'gift_avatar' => $giftUser['avatar'],
            ];
        }
        return $order;
    }


    public function tidyOrderList(array $data)
    {
        /** @var StoreOrderCartInfoServices $services */
        $services = app()->make(StoreOrderCartInfoServices::class);
        foreach ($data as &$item) {
            $item['_info'] = $services->getOrderCartInfo((int)$item['id']);
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['_refund_time'] = isset($item['refund_reason_time']) && $item['refund_reason_time'] ? date('Y-m-d H:i:s', $item['refund_reason_time']) : '';
            $item['_pay_time'] = isset($item['pay_time']) && $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
            if (($item['pink_id'] || $item['combination_id']) && isset($item['pinkStatus'])) {
                switch ($item['pinkStatus']) {
                    case 1:
                        $item['pink_name'] = '[拼团订单]正在进行中';
                        $item['color'] = '#f00';
                        break;
                    case 2:
                        $item['pink_name'] = '[拼团订单]已完成';
                        $item['color'] = '#00f';
                        break;
                    case 3:
                        $item['pink_name'] = '[拼团订单]未完成';
                        $item['color'] = '#f0f';
                        break;
                    default:
                        $item['pink_name'] = '[拼团订单]历史订单';
                        $item['color'] = '#FF7D00';
                        break;
                }
            } elseif ($item['combination_id']) {
                $item['pink_name'] = '[拼团订单]';
                $item['color'] = '#FF7D00';
            } elseif ($item['seckill_id']) {
                $item['pink_name'] = '[秒杀订单]';
                $item['color'] = '#3491FA';
            } elseif ($item['bargain_id']) {
                $item['pink_name'] = '[砍价订单]';
                $item['color'] = '#F7BA1E';
            } elseif ($item['advance_id']) {
                $item['pink_name'] = '[预售订单]';
                $item['color'] = '#B27FEB';
            } else {
                if ($item['shipping_type'] == 1) {
                    $item['pink_name'] = '[普通订单]';
                    $item['color'] = '#333';
                } else if ($item['shipping_type'] == 2) {
                    $item['pink_name'] = '[核销订单]';
                    $item['color'] = '#8956E8';
                }
            }
            if ($item['paid'] == 1 && in_array($item['marketplace_trade_version'] ?? null, [1, '1'], true)
                && $item['pay_type'] === 'marketplace_test') {
                $item['pay_type_name'] = '测试支付';
            } elseif ($item['paid'] == 1) {
                switch ($item['pay_type']) {
                    case PayServices::WEIXIN_PAY:
                        $item['pay_type_name'] = '微信支付';
                        break;
                    case PayServices::YUE_PAY:
                        $item['pay_type_name'] = '余额支付';
                        break;
                    case PayServices::OFFLINE_PAY:
                        $item['pay_type_name'] = '线下支付';
                        break;
                    case PayServices::ALIAPY_PAY:
                        $item['pay_type_name'] = '支付宝支付';
                        break;
                    case PayServices::ALLIN_PAY:
                        $item['pay_type_name'] = '通联支付';
                        break;
                    default:
                        $item['pay_type_name'] = '其他支付';
                        break;
                }
            } else {
                switch ($item['pay_type']) {
                    case 'offline':
                        $item['pay_type_name'] = '线下支付';
                        $item['pay_type_info'] = 1;
                        break;
                    default:
                        $item['pay_type_name'] = '';
                        break;
                }
            }
            $status_name = ['status_name' => '', 'pics' => []];
            if ($item['paid'] == 0 && $item['status'] == 0) {
                $status_name['status_name'] = $item['is_cancel'] == 0 ? '未支付' : '已取消';
            } else if ($item['paid'] == 1 && $item['status'] == 0 && $item['shipping_type'] == 1 && $item['refund_status'] == 0) {
                $status_name['status_name'] = $item['combination_id'] && isset($item['pinkStatus']) && $item['pinkStatus'] == 1 ? '未发货(拼团中)' : '未发货';
            } else if ($item['paid'] == 1 && $item['status'] == 4 && $item['shipping_type'] == 1 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '部分发货';
            } else if ($item['paid'] == 1 && $item['status'] == 0 && $item['shipping_type'] == 2 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '未核销';
            } else if ($item['paid'] == 1 && $item['status'] == 1 && $item['shipping_type'] == 1 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '待收货';
            } else if ($item['paid'] == 1 && $item['status'] == 1 && $item['shipping_type'] == 2 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '未核销';
            } else if ($item['paid'] == 1 && $item['status'] == 2 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '待评价';
            } else if ($item['paid'] == 1 && $item['status'] == 3 && $item['refund_status'] == 0) {
                $status_name['status_name'] = '已完成';
            } else if ($item['paid'] == 1 && $item['refund_status'] == 1) {
                $refundReasonTime = date('Y-m-d H:i', $item['refund_reason_time']);
                $refundReasonWapImg = json_decode($item['refund_reason_wap_img'], true);
                $refundReasonWapImg = $refundReasonWapImg ?: [];
                $img = [];
                if (count($refundReasonWapImg)) {
                    foreach ($refundReasonWapImg as $itemImg) {
                        if (strlen(trim($itemImg)))
                            $img[] = $itemImg;
                    }
                }
                $status_name['status_name'] = '退款中';
                $status_name['pics'] = $img;
            } else if ($item['paid'] == 1 && $item['refund_status'] == 2) {
                $status_name['status_name'] = '已退款';
            } else if ($item['paid'] == 1 && $item['refund_status'] == 3) {
                $status_name['status_name'] = <<<HTML
<b style="color:#f124c7">部分退款</b><br/>
HTML;
            } else if ($item['paid'] == 1 && $item['refund_status'] == 4) {
                $status_name['status_name'] = <<<HTML
<b style="color:#f124c7">退款中</b><br/>
HTML;
            }
            $item['status_name'] = $status_name;
            if ($item['paid'] == 0 && $item['status'] == 0 && $item['refund_status'] == 0) {
                $item['_status'] = 1;//未支付
            } else if ($item['paid'] == 1 && $item['status'] == 0 && $item['refund_status'] == 0) {
                $item['_status'] = 2;//已支付 未发货
            } else if ($item['paid'] == 1 && $item['status'] == 4 && $item['refund_status'] == 0) {
                $item['_status'] = 8;//已支付 部分发货
            } else if ($item['paid'] == 1 && $item['refund_status'] == 1) {
                $item['_status'] = 3;//已支付 申请退款中
            } else if ($item['paid'] == 1 && $item['status'] == 1 && $item['refund_status'] == 0) {
                $item['_status'] = 4;//已支付 待收货
            } else if ($item['paid'] == 1 && $item['status'] == 2 && $item['refund_status'] == 0) {
                $item['_status'] = 5;//已支付 待评价
            } else if ($item['paid'] == 1 && $item['status'] == 3 && $item['refund_status'] == 0) {
                $item['_status'] = 6;//已支付 已完成
            } else if ($item['paid'] == 1 && $item['refund_status'] == 2) {
                $item['_status'] = 7;//已支付 已退款
            } else if ($item['paid'] == 1 && $item['refund_status'] == 3 && $item['status'] == 4) {
                $item['_status'] = 9;//拆单发货 部分申请退款
            } else if ($item['paid'] == 1 && $item['refund_status'] == 4) {
                $item['_status'] = 10;//拆单发货 已全部申请退款
            } else if ($item['paid'] == 1 && $item['refund_status'] == 3 && $item['status'] == 0) {
                $item['_status'] = 11;//拆单退款 未发货
            }
            if ($item['clerk_id'] == 0 && !isset($item['clerk_name'])) {
                $item['clerk_name'] = '总平台';
            }

            if ($item['store_id']) {
                $store = app()->make(SystemStoreServices::class);
                $storeOne = $store->value(['id' => $item['store_id']], 'name');
                if ($storeOne) $item['store_name'] = $storeOne;
            }

            //根据核销员更改store_name
            if ($item['clerk_id'] && isset($item['staff_store_id']) && $item['staff_store_id']) {
                /** @var SystemStoreServices $store */
                $store = app()->make(SystemStoreServices::class);
                $storeOne = $store->value(['id' => $item['staff_store_id']], 'name');
                if ($storeOne) $item['store_name'] = $storeOne;
            }
        }
        return $data;
    }


    private function selectedCustomerList(array $data):array
    {
        $marketplaceScope='';$viewer=null;
        foreach ($data as &$item) {
            if($marketplaceScope!=='' && !array_key_exists('marketplace_trade_version',$item))throw new AuthUnavailableException();
            $publicV1=$marketplaceScope!=='' && !MarketplaceTradePolicy::savedOrderIsLegacy($item);
            if($publicV1 && $viewer!==null && (string)$item['uid']!==(string)$viewer)throw new AuthUnavailableException();
            $item = $this->tidyOrder($item, true);
            foreach ($item['cartInfo'] ?: [] as $key => $product) {
                if ($item['_status']['_type'] == 3) {
                    $item['cartInfo'][$key]['add_time'] = isset($product['add_time']) ? date('Y-m-d H:i', (int)$product['add_time']) : '时间错误';
                }
                $item['cartInfo'][$key]['productInfo']['price'] = $product['truePrice'] ?? 0;
            }
            if (count($item['refund'])) {
                $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
                $cart_num = array_sum(array_column($item['cartInfo'], 'cart_num'));
                $item['is_all_refund'] = $refund_num == $cart_num ? true : false;
            } else {
                $item['is_all_refund'] = false;
            }
            if($publicV1)$item=SavedOrder::orderListRow($item,$marketplaceScope);
        }
        return $data;
    }


    private function selectedPlatformList(array $data):array
    {
        $marketplaceScope='';$viewer=null;
        $data = $this->tidyOrderList($data);
        foreach ($data as &$item) {
            if($marketplaceScope!=='' && !array_key_exists('marketplace_trade_version',$item))throw new AuthUnavailableException();
            $publicV1=$marketplaceScope!=='' && !MarketplaceTradePolicy::savedOrderIsLegacy($item);
            $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
            $cart_num = 0;
            $vipTruePrice = 0;
            foreach ($item['_info'] as $items) {
                $cart_num += $items['cart_info']['cart_num'];
                $vipTruePrice = bcadd((string)$vipTruePrice, bcmul((string)$items['cart_info']['vip_truePrice'], (string)$items['cart_info']['cart_num'], 2), 2);
            }
            if(!$publicV1)$item['total_price'] = bcadd($item['total_price'], $vipTruePrice, 2);
            $item['is_all_refund'] = $refund_num == $cart_num;
            if($publicV1){
                if($viewer!==null && (string)$item['uid']!==(string)$viewer)throw new AuthUnavailableException();
                $item=SavedOrder::orderListRow($item,$marketplaceScope);
            }else $item['pay_price'] = (float)$item['pay_price'];
        }
        return $data;
    }


    private function selectedMobileList(array $list):array
    {
        $marketplaceScope='';$viewer=null;$orderServices=$this;
        $list = $orderServices->tidyOrderList($list);
        foreach ($list as &$item) {
            if($marketplaceScope!=='' && !array_key_exists('marketplace_trade_version',$item))throw new \crmeb\exceptions\AuthUnavailableException();
            $refund_num = array_sum(array_column($item['refund'], 'refund_num'));
            $cart_num = 0;
            foreach ($item['_info'] as $items) {
                $cart_num += $items['cart_info']['cart_num'];
            }
            $item['is_all_refund'] = $refund_num == $cart_num ? true : false;
            if($marketplaceScope!=='' && !MarketplaceTradePolicy::savedOrderIsLegacy($item)){
                if($viewer!==null && (string)$item['uid']!==(string)$viewer)throw new \crmeb\exceptions\AuthUnavailableException();
                $item=SavedOrder::orderListRow($item,$marketplaceScope);
            }
        }
        return $list;
    }

    private function selectedCustomerDetail(StoreOrderEconomizeServices $services, \app\model\order\StoreOrder $selected, int $uid): array
    {
        $order = $selected;
        if (!$order) throw new ApiException('商品不存在');
        $savedCustomForm = $order->getData('custom_form');
        $order = $order->toArray();
        $savedMarketplaceV1 = false; // load() already proved this actual native row is legacy.
        if($savedMarketplaceV1){
            if($uid<1 || (string)($order['uid'] ?? '')!==(string)$uid)throw new ApiException('订单不存在');
            $order['custom_form']=$savedCustomForm;
        }
        $splitNum = [];
        //是否开启门店自提
        $store_self_mention = sys_config('store_self_mention');
        //关闭门店自提后 订单隐藏门店信息
        if ($store_self_mention == 0) $order['shipping_type'] = 1;
        if ($order['verify_code']) {
            $verify_code = $order['verify_code'];
            $verify[] = substr($verify_code, 0, 4);
            $verify[] = substr($verify_code, 4, 4);
            $verify[] = substr($verify_code, 8);
            $order['_verify_code'] = implode(' ', $verify);
        }
        $order['add_time_y'] = date('Y-m-d', $order['add_time']);
        $order['add_time_h'] = date('H:i:s', $order['add_time']);
        $order['system_store'] = false;
        if ($order['store_id']) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $order['system_store'] = $storeServices->getStoreDispose($order['store_id']);
        }
        $order['code'] = '';
        if (($order['shipping_type'] === 2 || $order['delivery_uid'] != 0) && $order['verify_code']) {
//            $name = $order['verify_code'] . '.jpg';
//            /** @var SystemAttachmentServices $attachmentServices */
//            $attachmentServices = app()->make(SystemAttachmentServices::class);
//            $imageInfo = $attachmentServices->getInfo(['name' => $name]);
//            $siteUrl = sys_config('site_url');
//            if (!$imageInfo) {
//                $imageInfo = PosterServices::getQRCodePath($order['verify_code'], $name);
//                if (is_array($imageInfo)) {
//                    $attachmentServices->attachmentAdd($imageInfo['name'], $imageInfo['size'], $imageInfo['type'], $imageInfo['dir'], $imageInfo['thumb_path'], 1, $imageInfo['image_type'], $imageInfo['time'], 2);
//                    $url = $imageInfo['dir'];
//                } else
//                    $url = '';
//            } else $url = $imageInfo['att_dir'];
//            if (isset($imageInfo['image_type']) && $imageInfo['image_type'] == 1) $url = $siteUrl . $url;
//            $order['code'] = $url;
            try {
                $verifyName = 'verify_code_' . $order['verify_code'] . '.jpg';
                $data = 'verify_code=' . $order['verify_code'];
                /** @var SystemAttachmentServices $systemAttachmentService */
                $systemAttachmentService = app()->make(SystemAttachmentServices::class);
                $imageInfo = $systemAttachmentService->getOne(['name' => $verifyName]);
                $siteUrl = sys_config('site_url');
                if (!$imageInfo) {
                    $res = MiniProgramService::appCodeUnlimitService($data, 'pages/admin/order_cancellation/index', 280);
                    if (!$res) throw new ApiException('小程序核销码生成失败');
                    $uploadType = (int)sys_config('upload_type', 1);
                    $upload = UploadService::init();
                    $res = (string)EntityBody::factory($res);
                    $res = $upload->to('routine/product')->validate()->setAuthThumb(false)->stream($res, $verifyName);
                    if ($res === false) throw new ApiException('小程序核销码生成失败');
                    $imageInfo = $upload->getUploadInfo();
                    $imageInfo['image_type'] = $uploadType;
                    if ($imageInfo['image_type'] == 1) $remoteImage = PosterServices::remoteImage($siteUrl . $imageInfo['dir']);
                    else $remoteImage = PosterServices::remoteImage($imageInfo['dir']);
                    if (!$remoteImage['status']) throw new ApiException('小程序核销码生成失败');
                    $systemAttachmentService->save([
                        'name' => $imageInfo['name'],
                        'att_dir' => $imageInfo['dir'],
                        'satt_dir' => $imageInfo['thumb_path'],
                        'att_size' => $imageInfo['size'],
                        'att_type' => $imageInfo['type'],
                        'image_type' => $imageInfo['image_type'],
                        'module_type' => 2,
                        'time' => time(),
                        'pid' => 1,
                        'type' => 2
                    ]);
                    $url = $imageInfo['dir'];
                } else $url = $imageInfo['att_dir'];
                if ($imageInfo['image_type'] == 1) $url = $siteUrl . $url;
                $order['code'] = $url;
            } catch (\Exception $e) {
            }
        }
        $order['mapKey'] = sys_config('tengxun_map_key');
        $order['yue_pay_status'] = (int)sys_config('balance_func_status') && (int)sys_config('yue_pay_status') == 1 ? (int)1 : (int)2;//余额支付 1 开启 2 关闭
        $order['pay_weixin_open'] = sys_config('pay_weixin_open') != '0';//微信支付 1 开启 0 关闭
        $order['ali_pay_status'] = sys_config('ali_pay_status', '0') != '0';//支付包支付 1 开启 0 关闭
        $order['friend_pay_status'] = (int)sys_config('friend_pay_status') ?? 0;//好友代付 1 开启 0 关闭
        $orderData = $this->tidyOrder($order, true, true);
        if($savedMarketplaceV1 && in_array($order['pid']??null,[0,'0'],true)
            &&in_array($order['paid']??null,[0,'0'],true)&&in_array($order['is_cancel']??null,[0,'0'],true)
            &&($order['pay_type']??null)!==PayServices::OFFLINE_PAY&&($orderData['_status']['_type']??null)===0){
            $deadline=app()->make(MarketplaceOrderDao::class)->savedPaymentDeadline($order);
            $orderData['_status']['_msg']=$deadline===0?'订单尚未支付，请完成支付':
                '请在'.date('m-d H:i:s',$deadline).'前完成支付!';
        }
        $vipTruePrice = $memberPrice = $levelPrice = 0;
        foreach ($orderData['cartInfo'] ?? [] as $key => $cart) {
            $vipTruePrice = bcadd((string)$vipTruePrice, (string)$cart['vip_sum_truePrice'], 2);
            if ($cart['price_type'] == 'member') $memberPrice = bcadd((string)$memberPrice, (string)$cart['vip_sum_truePrice'], 2);
            if ($cart['price_type'] == 'level') $levelPrice = bcadd((string)$levelPrice, (string)$cart['vip_sum_truePrice'], 2);
            if (isset($splitNum[$cart['id']])) {
                $orderData['cartInfo'][$key]['cart_num'] = $cart['cart_num'] - $splitNum[$cart['id']];
                if ($orderData['cartInfo'][$key]['cart_num'] == 0) unset($orderData['cartInfo'][$key]);
            }
        }
        $orderData['cartInfo'] = array_merge($orderData['cartInfo']);
        $orderData['vip_true_price'] = $vipTruePrice;
        $orderData['levelPrice'] = $levelPrice;
        $orderData['memberPrice'] = $memberPrice;
        $economize = $services->get(['order_id' => $order['order_id']], ['postage_price', 'member_price']);
        if ($economize) {
            $orderData['postage_price'] = $economize['postage_price'];
            $orderData['member_price'] = $economize['member_price'];
        } else {
            $orderData['postage_price'] = 0;
            $orderData['member_price'] = 0;
        }
        $orderData['routine_contact_type'] = sys_config('routine_contact_type', 0);
        /** @var UserInvoiceServices $userInvoice */
        $userInvoice = app()->make(UserInvoiceServices::class);
        $invoice_func = $userInvoice->invoiceFuncStatus();
        $orderData['invoice_func'] = $invoice_func['invoice_func'];
        $orderData['special_invoice'] = $invoice_func['special_invoice'];
        $orderData['refund_cartInfo'] = $orderData['cartInfo'];
        $orderData['refund_total_num'] = $orderData['total_num'];
        $orderData['refund_pay_price'] = $orderData['pay_price'];
        $orderData['is_apply_refund'] = true;
        $orderData['help_info'] = [
            'pay_uid' => $orderData['pay_uid'],
            'pay_nickname' => '',
            'pay_avatar' => '',
            'help_status' => 0
        ];
        $orderData['gift_user_info'] = [
            'gift_uid' => $orderData['gift_uid'],
            'gift_nickname' => '',
            'gift_avatar' => '',
        ];
        if ($orderData['uid'] != $orderData['pay_uid']) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $payUser = $userServices->get($orderData['pay_uid'], ['nickname', 'avatar']);
            $orderData['help_info'] = [
                'pay_uid' => $orderData['pay_uid'],
                'pay_nickname' => $payUser['nickname'],
                'pay_avatar' => $payUser['avatar'],
                'help_status' => 1
            ];
        }
        if ($orderData['gift_uid'] != 0) {
            /** @var UserServices $userServices */
            $userServices = app()->make(UserServices::class);
            $giftUser = $userServices->get($orderData['gift_uid'], ['nickname', 'avatar']);
            $orderData['gift_user_info'] = [
                'gift_uid' => $orderData['gift_uid'],
                'gift_nickname' => $giftUser['nickname'],
                'gift_avatar' => $giftUser['avatar'],
            ];
        }
        // 判断是否开启小程序订单管理
        $orderData['order_shipping_open'] = false;
        if (sys_config('order_shipping_open', 0) && $order['pay_price'] > 0 && $order['is_channel'] == 1 && $order['pay_type'] == 'weixin' && MiniOrderService::isManaged()) {
            // 判断是否存在子未收货子订单
            if ($order['pid'] > 0) {
                if ($this->checkSubOrderNotTake((int)$order['pid'], (int)$order['id'])) {
                    $orderData['order_shipping_open'] = true;
                }
            } else {
                $orderData['order_shipping_open'] = true;
            }

        }
        $orderData['is_refund_available'] = $this->isRefundAvailable((int)$order['id']);

        $orderData['gift_key'] = $orderData['gift_code'] = '';
        if ($order['is_gift'] == 1) {
            $orderData['gift_key'] = md5($order['id'] . '_' . $order['order_id'] . '_' . $order['uid']);
            /** @var QrcodeServices $qrcodeService */
            $qrcodeService = app()->make(QrcodeServices::class);
            $orderData['gift_code'] = $qrcodeService->getRoutineQrcodePath($order['id'], $order['uid'], 7, ['gift_key' => $orderData['gift_key']]);
        }
        $orderData['avatar'] = set_file_url($orderData['avatar']);
        return $orderData; // The original customerOrder legacy branch is exact identity.
    }


    public function isRefundAvailable($oid)
    {
        $refundTimeAvailable = (int)sys_config('refund_time_available');
        if ($refundTimeAvailable == 0) return true;
        $statusInfo = app()->make(StoreOrderStatusServices::class)->get(['oid' => $oid, 'change_type' => 'take_delivery']);
        if (!$statusInfo) return true;
        $changeTime = preg_match('/^\d+$/', $statusInfo['change_time']) ? intval($statusInfo['change_time']) : strtotime($statusInfo['change_time']);
        if (($changeTime + ($refundTimeAvailable * 86400)) < time()) {
            return false;
        }
        return true;
    }


    public function checkSubOrderNotTake(int $pid, int $order_id)
    {
        $order_count = $this->dao->getSubOrderNotTake($pid, $order_id);
        if ($order_count > 0) {
            return false;
        } else {
            return true;
        }
    }


    private function selectedPlatformDetail(\app\model\order\StoreOrder $selected):array
    {
        $orderInfo=$selected;
        $savedCustomForm=$orderInfo->getData('custom_form');
        $legacy=true; // load() already proved this actual native row is legacy.
        /** @var UserServices $services */
        $services = app()->make(UserServices::class);
        $userInfo = $legacy ? $services->get($orderInfo['uid']) : $services->get($orderInfo['uid'],['real_name']);
        if (!$userInfo) throw new \RuntimeException('TASK6_REFERENCE_REAL_LEGACY_BUYER_REQUIRED');
        if($legacy){
        $userInfo = $userInfo->hidden(['pwd', 'add_ip', 'last_ip', 'login_type']);
        $userInfo['spread_name'] = '无';
        if ($userInfo['spread_uid']) {
            $spreadName = $services->value(['uid' => $userInfo['spread_uid']], 'nickname');
            if ($spreadName) {
                $userInfo['spread_name'] = $spreadName;
            } else {
                $userInfo['spread_uid'] = '';
            }
        } else {
            $userInfo['spread_uid'] = '';
        }
        }

        $orderInfo = $this->tidyOrder($orderInfo->toArray(), true, true);
        if(!$legacy)$orderInfo['custom_form']=$savedCustomForm;
        //核算优惠金额
        $vipTruePrice = $levelPrice = $memberPrice = 0;
        foreach ($orderInfo['cartInfo'] as $cart) {
            $vipTruePrice = bcadd((string)$vipTruePrice, (string)$cart['vip_sum_truePrice'], 2);
            if ($cart['price_type'] == 'member') $memberPrice = bcadd((string)$memberPrice, (string)$cart['vip_sum_truePrice'], 2);
            if ($cart['price_type'] == 'level') $levelPrice = bcadd((string)$levelPrice, (string)$cart['vip_sum_truePrice'], 2);
        }
        $orderInfo['vip_true_price'] = $vipTruePrice;
        $orderInfo['levelPrice'] = $levelPrice;
        $orderInfo['memberPrice'] = $memberPrice;
        if($legacy)$orderInfo['total_price'] = bcadd($orderInfo['total_price'], $orderInfo['vip_true_price'], 2);
        if ($orderInfo['store_id'] && $orderInfo['shipping_type'] == 2) {
            /** @var  $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $orderInfo['_store_name'] = $storeServices->value(['id' => $orderInfo['store_id']], 'name');
        } else
            $orderInfo['_store_name'] = '';
        if($legacy)$orderInfo['spread_name'] = $services->value(['uid' => $orderInfo['spread_uid']], 'nickname') ?? '无';
        $orderInfo['_info'] = app()->make(StoreOrderCartInfoServices::class)->getOrderCartInfo((int)$orderInfo['id']);
        $cart_num = 0;
        $refund_num = array_sum(array_column($orderInfo['refund'], 'refund_num'));
        foreach ($orderInfo['_info'] as $items) {
            $cart_num += $items['cart_info']['cart_num'];
        }
        $orderInfo['is_all_refund'] = $refund_num == $cart_num;
        $userInfo = $legacy ? $userInfo->toArray() : ['real_name'=>$userInfo['real_name']];
        if(!$legacy)$orderInfo=\app\services\marketplace\order\MarketplaceOrderSnapshotProjection::platformOrder($orderInfo);
        return compact('orderInfo', 'userInfo');
    }

}
