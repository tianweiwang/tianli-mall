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

namespace app\jobs;


use app\services\order\StoreOrderServices;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;

/**
 * 未支付10分钟后发送短信
 * Class UnpaidOrderSend
 * @package crmeb\jobs
 */
class UnpaidOrderSend extends BaseJobs
{
    use QueueTrait;

    public function doJob($id)
    {
        /** @var StoreOrderServices $services */
        $services = app()->make(StoreOrderServices::class);
        $orderInfo = $services->get($id);
        if (!$orderInfo) {
            return true;
        }
        $savedOrder = $orderInfo->getData();
        if (!array_key_exists('marketplace_trade_version', $savedOrder)) throw new \crmeb\exceptions\AuthUnavailableException();
        if (!\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($savedOrder)) {
            $pid = $savedOrder['pid'] ?? null;
            if ((!is_int($pid) && !is_string($pid)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string)$pid) !== 1
                || bccomp((string)$pid, '2147483647', 0) > 0) throw new \crmeb\exceptions\AuthUnavailableException();
            if ((int)$pid > 0) return true;
        } else {
            \app\services\marketplace\order\MarketplaceTradePolicy::assertLegacyOrder($savedOrder);
        }
        if ($orderInfo->paid) {
            return true;
        }
        if ($orderInfo->is_del) {
            return true;
        }
        //收货给用户发送消息
        event('NoticeListener', [['order' => $orderInfo], 'order_pay_false']);
        return true;
    }

}
