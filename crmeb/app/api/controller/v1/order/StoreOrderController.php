<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceOrderCreateServices;
use app\services\marketplace\order\MarketplaceTradePolicy;
use app\services\pay\PayServices;
use app\services\shipping\ExpressServices;
use app\services\system\admin\SystemAdminServices;
use app\services\activity\{lottery\LuckLotteryServices,
    combination\StorePinkServices
};
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\order\{StoreCartServices,
    StoreOrderCartInfoServices,
    StoreOrderComputedServices,
    StoreOrderCreateServices,
    StoreOrderEconomizeServices,
    StoreOrderInvoiceServices,
    StoreOrderRefundServices,
    StoreOrderServices,
    StoreOrderStatusServices,
    StoreOrderSuccessServices,
    StoreOrderTakeServices
};
use app\services\pay\OrderPayServices;
use app\services\pay\YuePayServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\shipping\ShippingTemplatesServices;
use crmeb\services\CacheService;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use Psr\SimpleCache\InvalidArgumentException;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Log;
use think\Response;

/**
 * 订单控制器
 * Class StoreOrderController
 * @package app\api\controller\order
 */
class StoreOrderController
{

    /**
     * @var StoreOrderServices
     */
    protected $services;

    /**
     * @var int[]
     */
    protected $getChennel = [
        'wechat' => 0,
        'routine' => 1,
        'h5' => 2,
        'pc' => 3,
        'app' => 4
    ];

    /**
     * StoreOrderController constructor.
     * @param StoreOrderServices $services
     */
    public function __construct(StoreOrderServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取确认订单页面是否展示快递配送和到店自提
     * @param Request $request
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function checkShipping(Request $request)
    {
        [$cartId, $new] = $request->postMore(['cartId', 'new'], true);
        return app('json')->success($this->services->checkShipping($request->uid(), $cartId, $new));
    }

    /**
     * 订单确认
     * @param Request $request
     * @param ShippingTemplatesServices $services
     * @return mixed
     * @throws InvalidArgumentException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function confirm(Request $request, ShippingTemplatesServices $services)
    {
        if(MarketplaceTradePolicy::active()){
            $raw=$request->param(false);
            if(!is_array($raw))throw new ApiException('参数错误');
            $choice=MarketplaceOrderInput::confirm($raw);
            MarketplaceTradePolicy::preview($raw,$choice);
            $uid=(int)$request->uid();$user=$request->user()->toArray();
            if((int)($user['uid']??0)!==$uid)throw new AuthUnavailableException();
            $data=$this->services->getOrderConfirmData($user,$choice['cartId'],$choice['new'],$choice['addressId'],
                $choice['shipping_type'],$choice['is_gift'],$choice);
            return app('json')->success(app()->make(\app\services\marketplace\PublicCatalogServices::class)->quoteOutput($data,$uid));
        }
        if (!\app\services\marketplace\freight\MerchantFreightPolicy::enabled() && !$services->get(1, ['id'])) {
            return app('json')->fail('默认运费模板未配置，无法下单');
        }
        [$cartId, $new, $addressId, $shipping_type, $is_gift] = $request->postMore([
            'cartId',
            'new',
            ['addressId', 0],
            ['shipping_type', 1],
            ['is_gift', 0],
        ], true);
        if (!is_string($cartId) || !$cartId) {
            return app('json')->fail('请提交购买的商品');
        }
        $user = $request->user()->toArray();
        return app('json')->success(app()->make(\app\services\marketplace\PublicCatalogServices::class)->quoteOutput($this->services->getOrderConfirmData($user, $cartId, !!$new, $addressId, (int)$shipping_type, (int)$is_gift),(int)$request->uid()));
    }

    /**
     * 计算订单金额
     * @param Request $request
     * @param StoreOrderComputedServices $computedServices
     * @param $key
     * @return mixed
     */
    public function computedOrder(Request $request, StoreOrderComputedServices $computedServices, $key)
    {
        if (!$key) return app('json')->fail('参数错误');
        $uid = $request->uid();
        // Inspect only the original order table before depending on new schema, config or cache.
        $saved=$this->services->getOne(['order_id|unique'=>$key,'uid'=>$uid,'is_del'=>0]);
        if($saved){
            if(MarketplaceTradePolicy::savedOrderIsLegacy($saved->toArray()))return app('json')->status('extend_order','订单不存在',['orderId'=>$key,'key'=>$key]);
            $confirmationKey=(string)$saved['unique'];
            $result=app()->make(MarketplaceOrderCreateServices::class)->lookupConfirmation((int)$uid,$confirmationKey);
            if($result['state']!=='created'||$result['key']!==$confirmationKey||$result['orderId']!==$saved['order_id'])
                throw new AuthUnavailableException();
            return app('json')->status('SUCCESS','订单已创建',$result);
        }
        $marketplace=MarketplaceTradePolicy::active()||$this->services->hasMarketplaceConfirmation((int)$uid,(string)$key);
        if($marketplace){
            $raw=$request->param(false);
            if(!is_array($raw))throw new ApiException('参数错误');
            $options=MarketplaceOrderInput::computed($raw);
            MarketplaceTradePolicy::preview($raw,$options);
            $cartGroup=$this->services->getCacheOrderInfo((int)$uid,(string)$key);
            if(!$cartGroup)throw new ApiException('订单已过期,请刷新当前页面');
            if(($cartGroup['marketplace_confirmation_version']??null)!==MarketplaceTradePolicy::CONFIRMATION_VERSION)throw new ApiException('请按店铺重新确认商品');
            if(array_key_exists('mer_id',$raw)&&MarketplaceOrderInput::unsigned($raw['mer_id'],'mer_id')!==$cartGroup['mer_id'])throw new ApiException('请重新确认结算店铺');
            $result=$computedServices->setParamData([])->computedOrder((int)$uid,$request->user()->toArray(),$cartGroup,
                $options['addressId'],$options['payType'],false,0,false,$options['shipping_type'],$options['is_gift'],true);
            foreach(['marketplace_confirmation_version','mer_id','source_kind','store']as$field)$result[$field]=$cartGroup[$field];
            $result['marketplace_create_enabled']=MarketplaceTradePolicy::v1CreateEnabled();
            return app('json')->status('NONE','操作成功',$result);
        }
        list($addressId, $couponId, $payType, $useIntegral, $mark, $combinationId, $pinkId, $seckill_id, $bargainId, $shipping_type, $is_gift) = $request->postMore([
            'addressId',
            'couponId',
            ['payType', ''],
            ['useIntegral', 0],
            'mark',
            ['combinationId', 0],
            ['pinkId', 0],
            ['seckill_id', 0],
            ['bargainId', 0],
            ['shipping_type', 1],
            ['is_gift', 0],
        ], true);
        $payType = strtolower($payType);
        $cartGroup = $this->services->getCacheOrderInfo($uid, $key);
        if (!$cartGroup) return app('json')->fail('订单已过期,请刷新当前页面');
        $priceGroup = $computedServices->setParamData([
            'combinationId' => $combinationId,
            'pinkId' => $pinkId,
            'seckill_id' => $seckill_id,
            'bargainId' => $bargainId,
        ])->computedOrder($request->uid(), $request->user()->toArray(), $cartGroup, $addressId, $payType, !!$useIntegral, (int)$couponId, false, (int)$shipping_type, $is_gift);
        if ($priceGroup)
            return app('json')->status('NONE', '操作成功', $priceGroup);
        else
            return app('json')->fail('获取失败');
    }

    /** Result lookup stays available for the authenticated owner without opening creation. */
    public function confirmation(Request $request, MarketplaceOrderCreateServices $services, $key)
    {
        $uid=(int)$request->uid();
        $user=$request->user()->toArray();
        if($uid<1 || (int)($user['uid']??0)!==$uid)throw new AuthUnavailableException();
        return app('json')->success($services->lookupConfirmation($uid,(string)$key));
    }

    /** Restore only this owner's original V1 confirmation; durable results take precedence. */
    public function restoreConfirmation(Request $request, MarketplaceOrderCreateServices $services, $key)
    {
        $uid=(int)$request->uid();$user=$request->user()->toArray();
        if($uid<1||(int)($user['uid']??0)!==$uid)throw new AuthUnavailableException();
        return app('json')->success($services->restoreConfirmation($uid,(string)$key,$user,$request->post(false)));
    }

    /**
     * 订单创建
     * @param Request $request
     * @param StoreOrderCreateServices $createServices
     * @param $key
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function create(Request $request, StoreOrderCreateServices $createServices, $key)
    {
        if (!$key) return app('json')->fail('参数错误');
        $userInfo = $request->user()->toArray();
        $uid=(int)$request->uid();
        if((int)($userInfo['uid']??0)!==$uid)throw new AuthUnavailableException();
        $checkOrder=$this->services->getOne(['order_id|unique'=>$key,'uid'=>$uid,'is_del'=>0]);
        if(($checkOrder&&!MarketplaceTradePolicy::savedOrderIsLegacy($checkOrder->toArray()))||(!$checkOrder&&MarketplaceTradePolicy::active())
            ||$this->services->hasMarketplaceConfirmation($uid,(string)$key)){
            $order=app()->make(MarketplaceOrderCreateServices::class)->create($uid,(string)$key,$request->post(false));
            $orderId=$order['order_id'];
            return app('json')->status('success','订单创建成功',compact('orderId','key'));
        }
        if ($checkOrder)
            return app('json')->status('extend_order', '订单已创建，请点击查看完成支付', ['orderId' => $checkOrder['order_id'], 'key' => $key]);
        [$addressId, $couponId, $payType, $useIntegral, $mark, $combinationId, $pinkId, $seckillId, $bargainId, $shipping_type, $real_name, $phone, $storeId, $news, $invoice_id, $advanceId, $customForm, $is_gift, $gift_mark] = $request->postMore([
            [['addressId', 'd'], 0],
            [['couponId', 'd'], 0],
            ['payType', ''],
            ['useIntegral', 0],
            ['mark', ''],
            [['combinationId', 'd'], 0],
            [['pinkId', 'd'], 0],
            [['seckill_id', 'd'], 0],
            [['bargainId', 'd'], ''],
            [['shipping_type', 'd'], 1],
            ['real_name', ''],
            ['phone', ''],
            [['store_id', 'd'], 0],
            ['new', 0],
            [['invoice_id', 'd'], 0],
            [['advanceId', 'd'], 0],
            ['custom_form', []],
            ['is_gift', 0],
            ['gift_mark', ''],
        ], true);
        $payType = strtolower($payType);
        $order = CacheService::lock('orderCreate' . $key, function () use ($createServices, $userInfo, $key, $addressId, $payType, $useIntegral, $couponId, $mark, $combinationId, $pinkId, $seckillId, $bargainId, $shipping_type, $real_name, $phone, $storeId, $news, $advanceId, $customForm, $invoice_id, $is_gift, $gift_mark) {
            return $createServices->createOrder($userInfo['uid'], $key, $userInfo, $addressId, $payType, !!$useIntegral, $couponId, $mark, $combinationId, $pinkId, $seckillId, $bargainId, $shipping_type, $real_name, $phone, $storeId, !!$news, $advanceId, $customForm, $invoice_id, $is_gift, $gift_mark);
        });
        $orderId = $order['order_id'];
        return app('json')->status('success', '订单创建成功', compact('orderId', 'key'));
    }

    /**
     * 订单 再次下单
     * @param Request $request
     * @param StoreCartServices $services
     * @return mixed
     */
    public function again(Request $request, StoreCartServices $services)
    {
        list($uni) = $request->postMore([
            ['uni', ''],
        ], true);
        $uid = (int)$request->uid();
        $cateId = $this->services->againOrder($services, $uni, $uid);
        $result = ['cateId' => implode(',', $cateId)];
        if (MarketplaceTradePolicy::active()) {
            $result['marketplace_again_version'] = 1;
            $result['source_kind'] = 'temporary';
            $result['groups'] = $services->getTemporaryCartGroups($uid, $cateId);
        }
        return app('json')->success($result);
    }

    /**
     * @param Request $request
     * @param $orderId
     * @param string $type
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2023/2/13
     */
    public function cashier(Request $request, $orderId, $type = 'order')
    {
        if (!$orderId) {
            return app('json')->fail('参数错误');
        }
        return app('json')->success($this->services->getCashierInfo((int)$request->uid(), $orderId, $type));
    }

    /**
     * 订单支付
     * @param Request $request
     * @param StorePinkServices $services
     * @param OrderPayServices $payServices
     * @param YuePayServices $yuePayServices
     * @return mixed
     */
    public function pay(Request $request, StorePinkServices $services, OrderPayServices $payServices, YuePayServices $yuePayServices)
    {
        [$uni, $paytype, $quitUrl, $type] = $request->postMore([
            ['uni', ''],
            ['paytype', ''],
            ['quitUrl', ''],
            ['type', 0]
        ], true);
        if (!$uni) return app('json')->fail('参数错误');
        $orderInfo = $this->services->get(['order_id' => $uni]);
        if (!$orderInfo) return app('json')->fail('订单不存在');
        MarketplaceTradePolicy::assertLegacyOrder($orderInfo->toArray());
        $payLock = CacheService::get('PAY_LOCK_' . $uni);
        if ($payLock) return app('json')->fail('订单支付中，请勿重复支付');
        CacheService::set('PAY_LOCK_' . $uni, 'PAY_LOCK', 2);
        if ($orderInfo->is_cancel == 1 || $orderInfo->is_del == 1 || $orderInfo->is_system_del == 1) return app('json')->fail('订单已经超过系统支付时间，无法支付，请重新下单');
        $uid = $type == 1 ? (int)$request->uid() : $orderInfo->uid;
        $orderInfo->is_channel = $this->getChennel[$request->getFromType()] ?? ($request->isApp() ? 0 : 1);
        $orderInfo->order_id = $uid != $orderInfo->pay_uid ? app()->make(StoreOrderCreateServices::class)->getNewOrderId('cp') : $uni;
        $orderInfo->pay_uid = $uid;
        $orderInfo->save();
        $orderInfo = $orderInfo->toArray();
        $order = $this->services->get(['order_id' => $orderInfo['order_id']]);
        if (!$order)
            return app('json')->fail('订单不存在');
        if ($order['paid'])
            return app('json')->fail('订单已支付');
        if ($order['pink_id'] && $services->isPinkStatus($order['pink_id'])) {
            return app('json')->fail('该订单已失效');
        }

        //0元支付
        if (bcsub((string)$orderInfo['pay_price'], '0', 2) <= 0) {
            //创建订单jspay支付
            /** @var StoreOrderSuccessServices $success */
            $success = app()->make(StoreOrderSuccessServices::class);
            $payPriceStatus = $success->zeroYuanPayment($orderInfo, $uid);
            if ($payPriceStatus)//0元支付成功
                return app('json')->status('success', '支付成功', ['order_id' => $orderInfo['order_id'], 'key' => $orderInfo['unique']]);
            else
                return app('json')->status('pay_error', '支付失败');
        }

        switch ($paytype) {
            case PayServices::YUE_PAY:
                $pay = $yuePayServices->yueOrderPay($order->toArray(), $request->uid());
                if ($pay['status'] === true)
                    return app('json')->status('success', '余额支付成功');
                else {
                    if (is_array($pay))
                        return app('json')->status($pay['status'], $pay['msg']);
                    else
                        return app('json')->status('pay_error', $pay);
                }
            case PayServices::OFFLINE_PAY:
                if ($this->services->setOrderTypePayOffline($order['order_id'])) {
                    event('NoticeListener', [$order->toArray(), 'admin_pay_success_code']);
                    return app('json')->status('success', '订单创建成功');
                } else {
                    return app('json')->status('success', '支付失败');
                }
            default:
                $payInfo = $payServices->beforePay($order->toArray(), $paytype, ['quitUrl' => $quitUrl]);
                return app('json')->status($payInfo['status'], $payInfo['payInfo']);
        }
    }

    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function lst(Request $request)
    {
        $where = $request->getMore([
            ['type', '', '', 'status'],
            ['search', '', '', 'real_name'],
            ['refund_type', '', '', 'refundTypes']
        ]);
        $list=$this->services->readCustomerOrderPage((int)$request->uid(),$where);
        return app('json')->success($list);
    }

    /**
     * 订单详情
     * @param Request $request
     * @param StoreOrderEconomizeServices $services
     * @param $uni
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function detail(Request $request, StoreOrderEconomizeServices $services, $uni)
    {
        if (!strlen(trim($uni))) return app('json')->fail('参数错误');
        $orderData=$this->services->readCustomerOrderDetail($services,$uni,(int)$request->uid(),
            $request->get('fulfillment_page',1,''),$request->get('fulfillment_limit',20,''));
        return app('json')->success($orderData);
    }

    /**
     * 代付订单详情
     * @param Request $request
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function friendDetail(Request $request)
    {
        [$orderId] = $request->getMore([
            ['order_id', '']
        ], true);
        $info = $this->services->getFriendDetail($orderId, $request->uid());
        return app('json')->success(compact('info'));
    }

    /**
     * TODO 弃用
     * 退款订单详情
     * @param Request $request
     * @param $uni
     * @param string $cartId
     * @return mixed
     */
    public function refund_detail(Request $request, $uni, $cartId = '')
    {
        if (!strlen(trim($uni))) return app('json')->fail('参数错误');
        /** @var StoreOrderCartInfoServices $storeOrderCartInfoServices */
        $storeOrderCartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        $order = $this->services->getUserOrderDetail($uni, (int)$request->uid(), ['split', 'invoice']);
        if (!$order) return app('json')->fail('订单不存在');
        $order = $order->toArray();
        $orderData = $this->services->tidyOrder($order, true, true);
        $splitNum = $storeOrderCartInfoServices->getSplitCartNum($order['cart_id']);
        foreach ($orderData['cartInfo'] ?? [] as $key => $cart) {
            $orderData['cartInfo'][$key]['one_postage_price'] = isset($cart['postage_price']) ? bcdiv($cart['postage_price'], $cart['cart_num'], 2) : 0;
            if ($cartId != '') {
                if ($cart['id'] != $cartId) {
                    unset($orderData['cartInfo'][$key]);
                } else {
                    if (isset($splitNum[$cart['id']])) {
                        $orderData['total_num'] = $orderData['cartInfo'][$key]['cart_num'] = $cart['cart_num'] - $splitNum[$cart['id']];
                        $orderData['pay_price'] = bcadd(bcmul($cart['truePrice'], $orderData['total_num'], 4), bcmul($orderData['total_num'], $orderData['cartInfo'][$key]['one_postage_price'], 4), 2);
                    } else {
                        $orderData['total_num'] = $orderData['cartInfo'][$key]['cart_num'];
                        $orderData['pay_price'] = bcadd(bcmul($cart['truePrice'], $cart['cart_num'], 4), $cart['postage_price'], 2);
                    }
                }
            } else {
                if (isset($splitNum[$cart['id']])) {
                    $orderData['cartInfo'][$key]['cart_num'] = $cart['cart_num'] - $splitNum[$cart['id']];
                    $orderData['total_num'] = $orderData['total_num'] - $splitNum[$cart['id']];
                    if ($orderData['cartInfo'][$key]['cart_num'] == 0) unset($orderData['cartInfo'][$key]);
                }
            }
        }
        if ($cartId == '') {
            $orderData['pay_price'] = bcsub($orderData['pay_price'], $this->services->sum(['pid' => $orderData['id']], 'pay_price'), 2);
        }
        $orderData['cartInfo'] = array_merge($orderData['cartInfo']);
        return app('json')->success($orderData);
    }


    /**
     * 订单删除
     * @param Request $request
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function del(Request $request)
    {
        [$uni] = $request->postMore([
            ['uni', ''],
        ], true);
        if (!$uni) return app('json')->fail('参数错误');
        $res = $this->services->removeOrder($uni, (int)$request->uid());
        if ($res) {
            return app('json')->success('删除成功');
        } else {
            return app('json')->fail('删除失败');
        }
    }

    /**
     * 订单收货
     * @param Request $request
     * @param StoreOrderTakeServices $services
     * @param StoreCouponIssueServices $issueServices
     * @return mixed
     */
    public function take(Request $request, StoreOrderTakeServices $services, StoreCouponIssueServices $issueServices)
    {
        list($uni) = $request->postMore([
            ['uni', ''],
        ], true);
        if (!$uni) return app('json')->fail('参数错误');
        $order = $services->takeOrder($uni, (int)$request->uid());
        if ($order) {
            return app('json')->success('收货成功');
        } else
            return app('json')->fail('收货失败');
    }


    /**
     * 订单 查看物流
     * @param Request $request
     * @param StoreOrderCartInfoServices $services
     * @param ExpressServices $expressServices
     * @param $uni
     * @param string $type
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function express(Request $request, StoreOrderCartInfoServices $services, ExpressServices $expressServices, $uni, $type = '')
    {
        if ($type == 'refund') {
            /** @var StoreOrderRefundServices $refundService */
            $refundService = app()->make(StoreOrderRefundServices::class);
            $order = $refundService->getUserRefundDetail((string)$uni,(int)$request->uid());
            $express = $order['refund_express'];
            $cacheName = $uni . $express;
            $orderInfo = [];
            $info = [];
            $cartNew = [];
            foreach ($order['cart_info'] as $k => $cart) {
                $cartNew['cart_num'] = $cart['cart_num'];
                $cartNew['truePrice'] = $cart['truePrice'];
                $cartNew['postage_price'] = $cart['postage_price'];
                $cartNew['productInfo']['image'] = $cart['productInfo']['image'];
                $cartNew['productInfo']['store_name'] = $cart['productInfo']['store_name'];
                $cartNew['productInfo']['unit_name'] = $cart['productInfo']['unit_name'] ?? '';
                array_push($info, $cartNew);
                unset($cart);
            }
            $orderInfos = $this->services->get($order['store_order_id']);
            $orderInfo['cartInfo'] = $info;
            $orderInfo['send_address'] = $orderInfos['user_address'];
            $orderInfo['send_city'] = $services->addressHandle($orderInfos['user_address'])['city'] ?? '';
            $orderInfo['delivery_id'] = $express;
            $orderInfo['delivery_name'] = $order['refund_express_name'];
            $orderInfo['delivery_code'] = '';
            $orderInfo['delivery_type'] = $order['delivery_type'] ?? 1;
            $orderInfo['user_address'] = $order['user_address'] ?? '';
            $address = explode(' ', $orderInfos['user_address']);
            $orderInfo['user_city'] = isset($address[0]) && in_array($address[0], ['北京', '上海', '天津', '重庆', '香港', '澳门', '台湾']) ? $address[0] : ($address[1] ?? '');
            $orderInfo['user_mark'] = $order['mark'] ?? '';
            $orderInfo['user_phone'] = $orderInfos['user_phone'] ?? '';
            $orderInfo['user_name'] = $orderInfos['real_name'] ?? '';
        } else {
            if (!$uni || !($order = $this->services->getUserOrderDetail($uni, $request->uid(), []))) return app('json')->fail('订单不存在');
            if ($type != 'refund' && ($order['delivery_type'] != 'express' || !$order['delivery_id'])) return app('json')->fail('快递单号不存在');
            $express = $type == 'refund' ? $order['refund_express'] : $order['delivery_id'];
            $cacheName = $uni . $express;
            $orderInfo = [];
            $cartInfo = $services->getCartColunm(['oid' => $order['id']], 'cart_info', 'unique');
            $info = [];
            $cartNew = [];
            foreach ($cartInfo as $cart) {
                $cart = json_decode($cart, true);
                $cartNew['cart_num'] = $cart['cart_num'];
                $cartNew['truePrice'] = $cart['truePrice'];
                $cartNew['postage_price'] = $cart['postage_price'];
                $cartNew['productInfo']['image'] = $cart['productInfo']['image'];
                $cartNew['productInfo']['store_name'] = $cart['productInfo']['store_name'];
                $cartNew['productInfo']['unit_name'] = $cart['productInfo']['unit_name'] ?? '';
                array_push($info, $cartNew);
                unset($cart);
            }
            $orderInfo['delivery_id'] = $express;
            $orderInfo['delivery_name'] = $type == 'refund' ? '用户退回' : $order['delivery_name'];;
            $orderInfo['delivery_code'] = $type == 'refund' ? '' : $order['delivery_code'];
            $orderInfo['delivery_type'] = $order['delivery_type'];
            $orderInfo['user_address'] = $order['user_address'];
            $orderInfo['user_mark'] = $order['mark'];
            $orderInfo['cartInfo'] = $info;
            $orderInfo['user_name'] = $order['real_name'];
            $orderInfo['user_phone'] = $order['user_phone'];
            $orderInfo['send_address'] = $order['user_address'];
            $orderInfo['send_city'] = $services->addressHandle($order['user_address'])['city'] ?? '';
            $orderInfo['delivery_type'] = $order['delivery_type'] ?? 1;
            $address = explode(' ', $order['user_address']);
            $orderInfo['user_city'] = isset($address[0]) && in_array($address[0], ['北京', '上海', '天津', '重庆', '香港', '澳门', '台湾']) ? $address[0] : ($address[1] ?? '');
            $orderInfo['user_mark'] = $order['mark'] ?? '';
        }
        return app('json')->success([
            'order' => $orderInfo,
            'express' => [
                'result' => ['list' => $expressServices->query($cacheName, $orderInfo['delivery_id'], $orderInfo['delivery_code'], $order['user_phone'])
                ]
            ]
        ]);
    }

    /**
     * 旧订单评价图片具名上传。
     */
    public function uploadLegacyReview(Request $request, \app\services\marketplace\assets\CustomerLegacyReviewAssetServices $assets)
    {
        try {
            $data = $assets->upload($request, (int)$request->uid(), (string)$request->route('unique', '', null));
            return app('json')->success('图片上传成功', $data);
        } catch (\app\services\marketplace\attachment\MerchantAttachmentOperationException $error) {
            return \think\Response::create(['status'=>503,'msg'=>'图片操作未完成，请保留本次结果','data'=>$error->receipt], 'json', 503);
        }
    }

    /**
     * 订单评价
     * @param Request $request
     * @param StoreOrderCartInfoServices $cartInfoServices
     * @param StoreProductReplyServices $replyServices
     * @return Response|void
     * @throws InvalidArgumentException
     */
    public function comment(Request $request, StoreOrderCartInfoServices $cartInfoServices, StoreProductReplyServices $replyServices)
    {

        $group = $request->postMore([
            ['unique', ''], ['comment', ''], ['pics', ''], ['product_score', 5], ['service_score', 5]
        ]);
        $unique = $group['unique'];
        unset($group['unique']);
        if (!$unique) return app('json')->fail('参数错误');
        $cartInfo = $cartInfoServices->getOne(['unique' => $unique]);
        $uid = $request->uid();
        $user_info = $request->user();
        $group['nickname'] = $user_info['nickname'];
        $group['avatar'] = $user_info['avatar'];
        if (!$cartInfo) return app('json')->fail('商品不存在');
        $orderInfo = $this->services->get($cartInfo['oid']);
        if (!$orderInfo) return app('json')->fail('订单不存在');
        if ($uid != $orderInfo['uid'] && $uid != $orderInfo['gift_uid']) return app('json')->fail('不是您自己的订单，无法评价');
        MarketplaceTradePolicy::assertLegacyOrder($orderInfo->getData());
        if ($orderInfo['pid'] > 0) {
            $parentOrder = $this->services->get((int)$orderInfo['pid'], ['id', 'marketplace_trade_version']);
            if (!$parentOrder) return app('json')->fail('订单不存在');
            MarketplaceTradePolicy::assertLegacyOrder($parentOrder->getData());
        }
        if ($replyServices->be(['oid' => $cartInfo['oid'], 'unique' => $unique]))
            return app('json')->fail('订单商品已评价');
        $group['comment'] = htmlspecialchars(trim($group['comment']));
        if ($group['product_score'] < 1) return app('json')->fail('请为商品评分');
        else if ($group['service_score'] < 1) return app('json')->fail('请为商家服务评分');
        if ($cartInfo['cart_info']['combination_id']) $productId = $cartInfo['cart_info']['product_id'];
        else if ($cartInfo['cart_info']['seckill_id']) $productId = $cartInfo['cart_info']['product_id'];
        else if ($cartInfo['cart_info']['bargain_id']) $productId = $cartInfo['cart_info']['product_id'];
        else $productId = $cartInfo['product_id'];
        if ($group['pics']) $group['pics'] = json_encode(is_array($group['pics']) ? $group['pics'] : explode(',', $group['pics']));
        $group = array_merge($group, [
            'uid' => $uid,
            'oid' => $cartInfo['oid'],
            'unique' => $unique,
            'product_id' => $productId,
            'add_time' => time(),
            'reply_type' => 'product',
            'suk' => $cartInfo['cart_info']['productInfo']['attrInfo']['suk']
        ]);
        //评价是否需要审核
        $group['status'] = sys_config('product_reply_examine') == 1 ? 0 : 1;

        $body = $request->post(false);
        if (is_array($body) && array_key_exists('review_asset_ids', $body)) {
            if (!is_array($body['review_asset_ids'])) return app('json')->fail('评价图片格式错误');
        }
        $assetIds = is_array($body) ? ($body['review_asset_ids'] ?? []) : [];
        if ($assetIds !== []) {
            try {
                $assets = app()->make(\app\services\marketplace\assets\CustomerLegacyReviewAssetServices::class);
                $res = $assets->save($request, (int)$uid, (string)$unique, $group, $assetIds,
                    static function ($connection, $pdo, array $actualGroup) use ($replyServices) {
                        return $replyServices->save($actualGroup);
                    });
            } catch (\app\services\marketplace\attachment\MerchantAttachmentOperationException $error) {
                return \think\Response::create(['status'=>503,'msg'=>'评价图片操作未完成，请保留本次结果','data'=>$error->receipt], 'json', 503);
            }
        } else {
            $res = $replyServices->save($group);
        }
        if (!$res) {
            return app('json')->fail('评价失败');
        }

        //自定义事件-订单评价
        event('CustomEventListener', ['order_comment', [
            'uid' => $uid,
            'oid' => $cartInfo['oid'],
            'unique' => $unique,
            'product_id' => $productId,
            'add_time' => date('Y-m-d H:i:s'),
            'suk' => $cartInfo['cart_info']['productInfo']['attrInfo']['suk']
        ]]);

        try {
            $this->services->checkOrderOver($replyServices, $cartInfoServices->getCartColunm(['oid' => $cartInfo['oid']], 'unique', ''), $cartInfo['oid']);
        } catch (\Exception $e) {
            return app('json')->fail('评价失败');
        }
        //缓存抽奖次数
        /** @var LuckLotteryServices $luckLotteryServices */
        $luckLotteryServices = app()->make(LuckLotteryServices::class);
        $luckLotteryServices->setCacheLotteryNum((int)$uid == $orderInfo['uid'] ? $orderInfo['uid'] : $orderInfo['gift_uid'], 'comment');

        /** @var SystemAdminServices $systemAdmin */
        $systemAdmin = app()->make(SystemAdminServices::class);
        $systemAdmin->adminNewPush();

        $lottery = $luckLotteryServices->getFactorLottery(4);
        if (!$lottery) {
            return app('json')->success(['to_lottery' => false]);
        }
        $lottery = $lottery->toArray();
        try {
            $luckLotteryServices->checkoutUserAuth($uid, (int)$lottery['id'], [], $lottery);
            $lottery_num = $luckLotteryServices->getLotteryNum($uid, (int)$lottery['id'], [], $lottery);
            if ($lottery_num > 0) return app('json')->success(['to_lottery' => true]);
        } catch (\Exception $e) {
            return app('json')->success(['to_lottery' => false]);
        }
    }

    /**
     * 订单统计数据
     * @param Request $request
     * @return mixed
     * @throws \ReflectionException
     */
    public function data(Request $request)
    {
        return app('json')->success($this->services->getOrderData((int)$request->uid()));
    }

    /**
     * 订单退款理由
     * @return mixed
     */
    public function refund_reason()
    {
        $reason = sys_config('stor_reason') ?: [];//退款理由
        $reason = str_replace("\r\n", "\n", $reason);//防止不兼容
        $reason = explode("\n", $reason);
        return app('json')->success($reason);
    }

    /**
     * 获取可以退货的订单商品列表
     * @param Request $request
     * @param StoreOrderCartInfoServices $services
     * @param $id
     * @return mixed
     */
    public function refundCartInfo(Request $request, StoreOrderCartInfoServices $services, $id)
    {
        $query=$request->get(false);
        if(is_array($query)&&array_key_exists('contract',$query)) {
            $owned=$this->services->get(['id'=>$id,'uid'=>$request->uid()],['*']);
            if(!$owned||MarketplaceTradePolicy::savedOrderIsLegacy($owned->getData()))throw new ApiException('订单不存在');
            return app('json')->success(app()->make(\app\services\marketplace\order\MarketplaceOrderRefundReadServices::class)
                ->quoteForCustomer($request,(int)$request->uid(),$id,$query));
        }
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        [$cart_ids] = $request->postMore([
            ['cart_ids', []]
        ], true);
        $uid = (int)$request->uid();
        if ($uid < 1 || !$this->services->get(['id' => (int)$id, 'uid' => $uid], ['id'])) {
            return app('json')->fail('订单不存在');
        }
        $list = $services->getRefundCartList((int)$id);
        if ($cart_ids) {
            foreach ($cart_ids as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num'] || $cart['cart_num'] <= 0) {
                    return app('json')->fail('请重新选择退款商品或件数');
                }
            }
            $cart_ids = array_combine(array_column($cart_ids, 'cart_id'), $cart_ids);
            foreach ($list as &$item) {
                if (isset($cart_ids[$item['cart_id']]['cart_num'])) $item['cart_num'] = $cart_ids[$item['cart_id']]['cart_num'];
            }
        }
        return app('json')->success($list);
    }

    /**
     * 获取退货商品列表
     * @param Request $request
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function refundCartInfoList(Request $request)
    {
        [$cart_ids, $id] = $request->postMore([
            ['cart_ids', []],
            ['id', 0],
        ], true);
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        return app('json')->success($this->services->refundCartInfoList((array)$cart_ids, (int)$id, (int)$request->uid()));
    }

    /**
     * 用户申请退款
     * @param Request $request
     * @param StoreOrderRefundServices $services
     * @param StoreOrderServices $storeOrderServices
     * @param $id
     * @return mixed
     * @throws InvalidArgumentException
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function applyRefund(Request $request, StoreOrderRefundServices $services, StoreOrderServices $storeOrderServices, $id)
    {
        $owned=$storeOrderServices->get(['id'=>$id,'uid'=>$request->uid()],['*']);
        if($owned&&!MarketplaceTradePolicy::savedOrderIsLegacy($owned->getData())) {
            $raw=\app\services\marketplace\order\MarketplaceOrderRefundInput::decode($request->getContent());
            return app('json')->success(app()->make(\app\services\marketplace\order\MarketplaceOrderRefundWriter::class)
                ->forCustomer($request,(int)$request->uid(),'apply',$id,$raw));
        }
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        $data = $request->postMore([
            ['text', ''],
            ['refund_reason_wap_img', ''],
            ['refund_reason_wap_explain', ''],
            ['refund_type', 1],
            ['refund_price', 0.00],
            ['cart_ids', []]
        ]);
        if ($data['text'] == '') return app('json')->fail('参数错误');
        if ($data['cart_ids']) {
            foreach ($data['cart_ids'] as $cart) {
                if (!isset($cart['cart_id']) || !$cart['cart_id'] || !isset($cart['cart_num']) || !$cart['cart_num']) {
                    return app('json')->fail('请重新选择退款商品或件数');
                }
            }
        }

        $order = $storeOrderServices->get($id);
        $uid = (int)$request->uid();
        if (!$order || $uid != $order['uid']) {
            return app('json')->fail('订单不存在');
        }
        if ($order['pid'] == -1) return app('json')->fail('主订单已拆单，请刷新页面');
        $refundData = [
            'refund_reason' => $data['text'],
            'refund_explain' => $data['refund_reason_wap_explain'],
            'refund_img' => json_encode($data['refund_reason_wap_img'] != '' ? explode(',', $data['refund_reason_wap_img']) : []),
        ];
        try {
            $res = $services->applyRefund((int)$id, $uid, $order, $data['cart_ids'], (int)$data['refund_type'], (float)$data['refund_price'], $refundData);
        } catch (\app\services\marketplace\attachment\MerchantAttachmentOperationException $error) {
            return \think\Response::create(['status'=>503,'msg'=>'退款申请结果未确认，请保留草稿并先读取已保存退款','data'=>$error->receipt],'json',503)
                ->allowCache(false)->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }
        if ($res)
            return app('json')->success('申请成功');
        else
            return app('json')->fail('申请失败');
    }

    /**
     * 用户退货提交快递单号
     * @param Request $request
     * @param StoreOrderRefundServices $services
     * @return mixed
     */
    public function refund_express(Request $request, StoreOrderRefundServices $services)
    {
        [$id, $express_id] = $request->postMore([
            ['id', ''],
            ['express_id', '']
        ], true);
        if ($id == '' || $express_id == '') return app('json')->fail('参数错误');
        $res = $services->editRefundExpress($id, $express_id);
        if ($res)
            return app('json')->success('提交成功');
        else
            return app('json')->fail('提交失败');
    }

    /**
     * 订单取消   未支付的订单回退积分,回退优惠券,回退库存
     * @param Request $request
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function cancel(Request $request)
    {
        list($id) = $request->postMore([['id', 0]], true);
        if (!$id) return app('json')->fail('参数错误');
        if ($this->services->cancelOrder($id, (int)$request->uid()))
            return app('json')->success('取消成功');
        return app('json')->fail('取消失败');
    }

    /**
     * 订单商品信息
     * @param Request $request
     * @param StoreOrderCartInfoServices $services
     * @return mixed
     */
    public function product(Request $request, StoreOrderCartInfoServices $services)
    {
        list($unique) = $request->postMore([['unique', '']], true);
        if (!$unique || !($cartInfo = $services->getOne(['unique' => $unique]))) return app('json')->fail('商品不存在');
        $savedOrder = $this->services->get((int)$cartInfo['oid']);
        if (!$savedOrder) return app('json')->fail('订单不存在');
        $actualUid = (int)$request->uid();
        if ($actualUid !== (int)$savedOrder['uid'] && $actualUid !== (int)$savedOrder['gift_uid'])
            return app('json')->fail('不是您自己的订单，无法评价');
        MarketplaceTradePolicy::assertLegacyOrder($savedOrder->getData());
        if ((int)$savedOrder['pid'] > 0) {
            $savedParent = $this->services->get((int)$savedOrder['pid'], ['id','marketplace_trade_version']);
            if (!$savedParent) return app('json')->fail('订单不存在');
            MarketplaceTradePolicy::assertLegacyOrder($savedParent->getData());
        }
        $cartInfo = $cartInfo->toArray();
        $cartProduct = [];
        $cartProduct['cart_num'] = $cartInfo['cart_info']['cart_num'];
        $cartProduct['productInfo']['image'] = get_thumb_water($cartInfo['cart_info']['productInfo']['image'] ?? '');
        $cartProduct['productInfo']['price'] = $cartInfo['cart_info']['productInfo']['price'] ?? 0;
        $cartProduct['productInfo']['store_name'] = $cartInfo['cart_info']['productInfo']['store_name'] ?? '';
        if (isset($cartInfo['cart_info']['productInfo']['attrInfo'])) {
            $cartProduct['productInfo']['attrInfo']['product_id'] = $cartInfo['cart_info']['productInfo']['attrInfo']['product_id'] ?? '';
            $cartProduct['productInfo']['attrInfo']['suk'] = $cartInfo['cart_info']['productInfo']['attrInfo']['suk'] ?? '';
            $cartProduct['productInfo']['attrInfo']['price'] = $cartInfo['cart_info']['productInfo']['attrInfo']['price'] ?? '';
            $cartProduct['productInfo']['attrInfo']['image'] = get_thumb_water($cartInfo['cart_info']['productInfo']['attrInfo']['image'] ?? '');
        }
        $cartProduct['product_id'] = $cartInfo['cart_info']['product_id'] ?? 0;
        $cartProduct['combination_id'] = $cartInfo['cart_info']['combination_id'] ?? 0;
        $cartProduct['seckill_id'] = $cartInfo['cart_info']['seckill_id'] ?? 0;
        $cartProduct['bargain_id'] = $cartInfo['cart_info']['bargain_id'] ?? 0;
        $cartProduct['order_id'] = $this->services->value(['id' => $cartInfo['oid']], 'order_id');
        return app('json')->success($cartProduct);
    }

    /**
     * 商家寄件回调
     * @param Request $request
     * @return Response
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2023/6/12
     */
    public function callBack(Request $request)
    {
        $data = $request->postMore([
            ['type', ''],
            ['data', ''],
        ]);
        $data['data'] = $this->decrypt($data['data'], sys_config('sms_token'));
        switch ($data['type']) {
            case 'detection'://检测回调地址
                return \json($data['data']);
                break;
            case 'order_success'://下单成功
                $update = [
                    'label' => $data['data']['label'] ?? '',
                ];
                //韵达会异步推送单号
                if (isset($data['kuaidinum'])) {
                    $update['delivery_id'] = $data['kuaidinum'];
                }
                if (isset($data['task_id'])) {
                    $matchedOrders = $this->services->selectList(['kuaidi_task_id' => $data['task_id']], 'id,marketplace_trade_version');
                    foreach ($matchedOrders as $matchedOrder) {
                        MarketplaceTradePolicy::assertLegacyOrder($matchedOrder->getData());
                    }
                    $this->services->update(['kuaidi_task_id' => $data['task_id']], $update);
                }
                break;
            case 'order_take'://取件
                if (isset($data['data']['task_id'])) {
                    $orderInfo = $this->services->get(['kuaidi_task_id' => $data['data']['task_id']]);
                    if (!$orderInfo) {
                        return app('json')->fail('订单不存在');
                    }
                    $this->services->transaction(function () use ($data, $orderInfo) {
                        $matchedOrders = $this->services->selectList(['kuaidi_task_id' => $data['data']['task_id']], 'id,marketplace_trade_version');
                        foreach ($matchedOrders as $matchedOrder) {
                            MarketplaceTradePolicy::assertLegacyOrder($matchedOrder->getData());
                        }
                        $this->services->update(['kuaidi_task_id' => $data['data']['task_id']], [
                            'status' => 1,
                            'is_stock_up' => 0
                        ]);
                        /** @var StoreOrderStatusServices $services */
                        $services = app()->make(StoreOrderStatusServices::class);
                        $services->save([
                            'oid' => $orderInfo->id,
                            'change_time' => time(),
                            'change_type' => 'delivery_goods',
                            'change_message' => '已发货 快递公司：' . $orderInfo->delivery_name . ' 快递单号：' . $orderInfo->delivery_id
                        ]);
                    });
                }
                break;
            case 'order_cancel'://取消寄件
                if (isset($data['data']['task_id'])) {
                    $orderInfo = $this->services->get(['kuaidi_task_id' => $data['data']['task_id']]);
                    if (!$orderInfo) {
                        return app('json')->fail('订单不存在');
                    }
                    $matchedOrders = $this->services->selectList(['kuaidi_task_id' => $data['data']['task_id']], 'id,marketplace_trade_version');
                    foreach ($matchedOrders as $matchedOrder) {
                        MarketplaceTradePolicy::assertLegacyOrder($matchedOrder->getData());
                    }
                    if ($orderInfo->is_stock_up && $orderInfo->status == 0) {
                        app()->make(StoreOrderStatusServices::class)->save([
                            'oid' => $orderInfo->id,
                            'change_time' => time(),
                            'change_type' => 'delivery_goods_cancel',
                            'change_message' => '已取消发货，取消原因：用户手动取消'
                        ]);

                        $orderInfo->status = 0;
                        $orderInfo->is_stock_up = 0;
                        $orderInfo->kuaidi_task_id = '';
                        $orderInfo->kuaidi_order_id = '';
                        $orderInfo->express_dump = '';
                        $orderInfo->kuaidi_label = '';
                        $orderInfo->delivery_id = '';
                        $orderInfo->delivery_code = '';
                        $orderInfo->delivery_name = '';
                        $orderInfo->delivery_type = '';
                        $orderInfo->save();
                    } else {
                        Log::error('商家寄件自动回调，订单状态不正确：', [
                            'kuaidi_task_id' => $data['data']['task_id']
                        ]);
                    }
                }
                break;
            case 'success'://电子发票回调
                $oid = $this->services->value(['order_id' => $data['data']['unique']], 'id');
                if ($oid) {
                    $invoiceServices = app()->make(StoreOrderInvoiceServices::class);
                    $invoiceServices->update([
                        'category' => 'order',
                        'order_id' => $oid,
                    ], [
                        'unique' => $data['data']['unique'],
                        'invoice_type' => $data['data']['invoice_type'],
                        'invoice_num' => $data['data']['invoice_num'],
                        'invoice_serial_number' => $data['data']['invoice_serial_number'],
                        'is_invoice' => 1,
                        'invoice_time' => time()
                    ]);
                }
                break;
        }

        return app('json')->success();
    }

    /**
     * 解密商家寄件回调
     * @param string $encryptedData
     * @param string $key
     * @return false|string
     * @author: 吴汐
     * @email: 442384644@qq.com
     * @date: 2023/8/31
     */
    function decrypt(string $encryptedData, string $key)
    {
        $key = substr($key, 0, 32);
        $decodedData = base64_decode($encryptedData);
        $iv = substr($decodedData, 0, 16);
        $encrypted = substr($decodedData, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return json_decode($decrypted, true);
    }

    public function giftDetail($oid)
    {
        if (!$oid) {
            return app('json')->fail('缺少参数');
        }
        return app('json')->success($this->services->giftDetail($oid));
    }

    public function receiveGift(Request $request, $oid)
    {
        [$gift_key, $shipping_type, $name, $phone, $address_id, $store_id] = $request->postMore([
            ['gift_key', ''],
            ['shipping_type', 1],
            ['name', ''],
            ['phone', ''],
            ['address_id', 0],
            ['store_id', 0],
        ], true);
        if (!$oid) {
            return app('json')->fail('缺少参数');
        }
        if ($shipping_type == 1 && $address_id == 0) {
            return app('json')->fail('请选择收货地址');
        }
        $uid = $request->uid();
        $res = $this->services->receiveGift($uid, $oid, $gift_key, $shipping_type, $name, $phone, $address_id, $store_id);
        if ($res) {
            return app('json')->success('领取成功', ['status' => 1]);
        } else {
            return app('json')->success('该礼品已经被别人领取', ['status' => 0]);
        }

    }
}
