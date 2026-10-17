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

use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use app\services\product\product\StoreProductReplyServices;
use crmeb\basic\BaseJobs;
use crmeb\traits\QueueTrait;

class AutoCommentJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 自动评价
     * @param $id
     * @param $cart_ids
     * @return bool
     */
    public function doJob($id, $cart_ids)
    {
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var  $replyServices */
        $replyServices = app()->make(StoreProductReplyServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $targetOrder = $orderServices->get((int)$id, ['id', 'pid', 'marketplace_trade_version']);
        if (!$targetOrder) return true;
        $targetSaved = $targetOrder->getData();
        if (!array_key_exists('marketplace_trade_version', $targetSaved)) throw new \crmeb\exceptions\AuthUnavailableException();
        if (!\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($targetSaved)) return true;
        \app\services\marketplace\order\MarketplaceTradePolicy::assertLegacyOrder($targetSaved);
        if ($targetSaved['pid'] > 0) {
            $targetParent = $orderServices->get((int)$targetSaved['pid'], ['id', 'marketplace_trade_version']);
            if (!$targetParent) return true;
            $parentSaved = $targetParent->getData();
            if (!array_key_exists('marketplace_trade_version', $parentSaved)) throw new \crmeb\exceptions\AuthUnavailableException();
            if (!\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($parentSaved)) return true;
            \app\services\marketplace\order\MarketplaceTradePolicy::assertLegacyOrder($parentSaved);
        }
        $list = $cartInfoServices->getColumn([['cart_id', 'in', $cart_ids]], 'cart_info,uid,oid,unique,product_id');
        if ($list) {
            $actualOrders = $orderServices->getColumn([['id', 'in', array_values(array_unique(array_column($list, 'oid'), SORT_REGULAR))]], 'id,pid,marketplace_trade_version', 'id');
            $parentIds = [];
            foreach ($list as $item) {
                $actualOrder = $actualOrders[$item['oid']] ?? null;
                if (!$actualOrder) return true;
                if (!array_key_exists('marketplace_trade_version', $actualOrder)) throw new \crmeb\exceptions\AuthUnavailableException();
                if (!\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($actualOrder)) return true;
                \app\services\marketplace\order\MarketplaceTradePolicy::assertLegacyOrder($actualOrder);
                if ($actualOrder['pid'] > 0) $parentIds[] = $actualOrder['pid'];
            }
            if ($parentIds) {
                $actualParents = $orderServices->getColumn([['id', 'in', array_values(array_unique($parentIds, SORT_REGULAR))]], 'id,marketplace_trade_version', 'id');
                foreach ($parentIds as $parentId) {
                    $actualParent = $actualParents[$parentId] ?? null;
                    if (!$actualParent) return true;
                    if (!array_key_exists('marketplace_trade_version', $actualParent)) throw new \crmeb\exceptions\AuthUnavailableException();
                    if (!\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($actualParent)) return true;
                    \app\services\marketplace\order\MarketplaceTradePolicy::assertLegacyOrder($actualParent);
                }
            }
        }
        $uids = array_column($list, 'uid');
        $userInfos = $userServices->getColumn([['uid', 'in', $uids]], 'nickname,avatar', 'uid');
        $reply = [];
        foreach ($list as $item) {
            $reply[] = [
                'uid' => $item['uid'],
                'oid' => $item['oid'],
                'unique' => $item['unique'],
                'product_id' => json_decode($item['cart_info'],true)['product_id'],
                'reply_type' => 'product',
                'nickname' => $userInfos[$item['uid']]['nickname'],
                'avatar' => $userInfos[$item['uid']]['avatar'],
                'comment' => sys_config('comment_content',''),
                'product_score' => 5,
                'service_score' => 5,
                'add_time' => time(),
            ];
        }
        if (count($reply)) {
            $replyServices->saveAll($reply);
        }
        $orderServices->checkOrderOver($replyServices, $cartInfoServices->getCartColunm(['oid' => $id], 'unique', ''), $id);
        return true;
    }
}
