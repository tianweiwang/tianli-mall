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
namespace app\listener\order;

use app\services\order\StoreOrderStatusServices;
use app\services\order\StoreOrderTakeServices;
use app\services\marketplace\order\MarketplaceTradePolicy;
use crmeb\exceptions\AuthUnavailableException;
use app\services\user\UserBillServices;
use crmeb\interfaces\ListenerInterface;
use think\facade\Log;

/**
 * 订单确认收货
 * Class OrderTakeListener
 * @package app\listener\order
 */
class OrderTakeListener implements ListenerInterface
{
    public function handle($event): void
    {
        [$order, $userInfo, $storeTitle] = $event;
        $receiptSource = $order instanceof \think\Model ? $order->getData() : $order;
        if (!is_array($receiptSource) || !isset($receiptSource['id'])
            || (!is_int($receiptSource['id']) && !is_string($receiptSource['id']))
            || preg_match('/\A[1-9][0-9]*\z/D', (string)$receiptSource['id']) !== 1) {
            throw new AuthUnavailableException();
        }
        $receiptServices = app()->make(StoreOrderTakeServices::class);
        $actualOrder = $receiptServices->get($receiptSource['id'], ['id', 'marketplace_trade_version']);
        if (!$actualOrder) {
            return;
        }
        $actualReceipt = $actualOrder->getData();
        if (array_key_exists('marketplace_trade_version', $actualReceipt)
            && in_array($actualReceipt['marketplace_trade_version'], [1, '1'], true)) {
            return;
        }
        MarketplaceTradePolicy::assertLegacyOrder($actualReceipt);
        try {
            //修改收货状态
            /** @var UserBillServices $userBillServices */
            $userBillServices = app()->make(UserBillServices::class);
            $userBillServices->takeUpdate((int)$order['uid'], (int)$order['id']);

            //增加收货订单状态
            /** @var StoreOrderStatusServices $statusService */
            $statusService = app()->make(StoreOrderStatusServices::class);
            $statusService->save([
                'oid' => $order['id'],
                'change_type' => 'take_delivery',
                'change_message' => '已收货',
                'change_time' => time()
            ]);

            //检查主订单是否需要修改状态
            if ($order['pid'] > 0) {
                /** @var StoreOrderTakeServices $storeOrderTake */
                $storeOrderTake = app()->make(StoreOrderTakeServices::class);
                $storeOrderTake->checkMaster($order['pid']);
            }
        } catch (\Throwable $e) {
            Log::error($e->getMessage());
        }
    }
}
