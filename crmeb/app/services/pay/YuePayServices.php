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

namespace app\services\pay;

use app\services\BaseServices;
use app\services\order\OtherOrderServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\user\UserMoneyServices;
use app\services\user\UserServices;
use crmeb\exceptions\ApiException;

/**
 * 余额支付
 * Class YuePayServices
 * @package app\services\pay
 */
class YuePayServices extends BaseServices
{

    /**
     * 订单余额支付
     * @param $order_id
     * @param $uid
     * @return bool
     */
    public function yueOrderPay(array $orderInfo, $uid)
    {
        if (!$orderInfo) {
            throw new ApiException('订单不存在');
        }
        $isMember = isset($orderInfo['member_type']);
        $type = $isMember ? 'pay_member' : 'pay_product';
        /** @var UserServices $services */
        $services = app()->make(UserServices::class);
        $orderServices = app()->make($isMember ? OtherOrderServices::class : StoreOrderSuccessServices::class);

        return $this->transaction(function () use ($services, $orderServices, $orderInfo, $uid, $type) {
            // Lock the order before the account so duplicate requests cannot debit twice.
            $order = $orderServices->selectModel(['id' => $orderInfo['id']])->lock(true)->find();
            if (!$order) {
                throw new ApiException('订单不存在');
            }
            if ($order['paid']) {
                throw new ApiException('订单已支付');
            }
            $orderInfo = $order->toArray();
            if (!empty($orderInfo['is_cancel']) || !empty($orderInfo['is_del']) || !empty($orderInfo['is_system_del'])) {
                throw new ApiException('订单已经取消，无法支付');
            }

            // Use the current locked balance for both the debit and its ledger entry.
            $userInfo = $services->selectModel(['uid' => $uid])->lock(true)->find();
            if (!$userInfo) {
                throw new ApiException('用户不存在');
            }
            if (bccomp((string)$userInfo['now_money'], (string)$orderInfo['pay_price'], 2) < 0) {
                return ['status' => 'pay_deficiency', 'msg' => '余额不足' . floatval($orderInfo['pay_price'])];
            }
            $now_money = bcsub((string)$userInfo['now_money'], (string)$orderInfo['pay_price'], 2);
            $userInfo->now_money = $now_money;
            $res = $userInfo->save();
            /** @var UserMoneyServices $userMoneyServices */
            $userMoneyServices = app()->make(UserMoneyServices::class);
            $res = $res && $userMoneyServices->income($type, (int)$userInfo['uid'], $orderInfo['pay_price'], $now_money, $orderInfo['id']);
            $res = $res && $orderServices->paySuccess($orderInfo, PayServices::YUE_PAY);
            if (!$res) {
                throw new ApiException('余额支付失败');
            }
            return ['status' => true];
        });
    }
}
