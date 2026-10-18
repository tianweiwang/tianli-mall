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
namespace app\services\system\crontab;

use app\services\activity\combination\StorePinkServices;
use app\services\activity\live\LiveGoodsServices;
use app\services\activity\live\LiveRoomServices;
use app\services\agent\AgentManageServices;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\marketplace\order\MarketplaceOrderPostCommitServices;
use app\services\order\StoreOrderInvoiceServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;
use app\services\product\product\StoreProductServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\user\UserSignServices;
use think\facade\Cache;
use think\facade\Log;

/**
 * 执行定时任务
 * @author 吴汐
 * @email 442384644@qq.com
 * @date 2023/03/01
 */
class CrontabRunServices
{
    /**
     * 定时任务类型 每一个定义的类型会对应CrontabRunServices类中的一个方法
     * @var string[]
     */
    public $markList = [
        'orderCancel' => '未支付自动取消订单',
        'marketplaceOrderPostCommit' => '多商户订单提交后收尾',
        'marketplaceFulfillmentReceipt' => '多商户物流自动收货',
        'pinkExpiration' => '拼团到期订单处理',
        'agentUnbind' => '到期自动解绑上级',
        'liveProductStatus' => '自动更新直播商品状态',
        'liveRoomStatus' => '自动更新直播间状态',
        'takeDelivery' => '订单自动收货',
        'advanceOff' => '预售商品到期自动下架',
        'productReplay' => '订单商品自动好评',
        'clearPoster' => '清除昨日海报',
        'autoInvoice' => '自动开具发票以及退款自动冲红',
        'signRemind' => '未签到提醒',
        'customTimer' => '自定义定时任务',
    ];

    /** Scan only durable V1 post-commit responsibilities; this does not dispatch other timers. */
    public function marketplaceOrderPostCommit(): array
    {
        // A progress hint only: candidates and completion always come from the durable ledger.
        $cursor = 0;
        try {
            $hint = Cache::get('marketplace_order_post_commit_cursor');
            if ((is_int($hint) || is_string($hint)) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$hint) === 1
                && (int)$hint <= 2147483647) $cursor = (int)$hint;
        } catch (\Throwable $error) {
            try { Log::error('MARKETPLACE_POST_COMMIT_CURSOR_READ_UNAVAILABLE'); } catch (\Throwable $ignored) {}
        }
        $result = app()->make(MarketplaceOrderPostCommitServices::class)->scanPending($cursor, 100);
        $stored = false;
        try { $stored = Cache::set('marketplace_order_post_commit_cursor', $result['next_cursor']) === true; }
        catch (\Throwable $error) {}
        if (!$stored) {
            try { Log::error('MARKETPLACE_POST_COMMIT_CURSOR_WRITE_UNAVAILABLE'); } catch (\Throwable $ignored) {}
        }
        return $result;
    }

    /** Dedicated physical-receipt scan; cached cursors are hints, never completion evidence. */
    public function marketplaceFulfillmentReceipt(): array
    {
        $validCursor = static function (mixed $cursor): bool {
            if (!is_array($cursor) || array_keys($cursor) !== ['due_at', 'ship_id']
                || !is_int($cursor['due_at']) || $cursor['due_at'] < 0 || $cursor['due_at'] > 4294967295
                || !is_string($cursor['ship_id'])
                || preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', $cursor['ship_id']) !== 1
                || (strlen($cursor['ship_id']) === 20 && strcmp($cursor['ship_id'], '18446744073709551615') > 0)) return false;
            return ($cursor['due_at'] === 0) === ($cursor['ship_id'] === '0');
        };
        $cursor = ['due_at' => 0, 'ship_id' => '0'];
        try {
            $hint = Cache::get('marketplace_fulfillment_receipt_cursor');
            if ($validCursor($hint)) $cursor = $hint;
        } catch (\Throwable $error) {
            try { Log::error('MARKETPLACE_FULFILLMENT_RECEIPT_CURSOR_READ_UNAVAILABLE'); } catch (\Throwable $ignored) {}
        }
        // Let actual scan failures propagate; do not advance a cursor for an unproved result.
        $result = app()->make(MarketplaceOrderFulfillmentServices::class)->scanDue($cursor['due_at'], $cursor['ship_id'], 100);
        $next = ['due_at' => $result['next_due_at'] ?? null, 'ship_id' => $result['next_ship_id'] ?? null];
        if (!$validCursor($next) || !is_bool($result['wrapped'] ?? null)
            || ($result['wrapped'] && $next !== ['due_at' => 0, 'ship_id' => '0'])) {
            throw new \crmeb\exceptions\AuthUnavailableException();
        }
        $stored = false;
        try { $stored = Cache::set('marketplace_fulfillment_receipt_cursor', $next) === true; }
        catch (\Throwable $error) {}
        if (!$stored) {
            try { Log::error('MARKETPLACE_FULFILLMENT_RECEIPT_CURSOR_WRITE_UNAVAILABLE'); } catch (\Throwable $ignored) {}
        }
        return $result;
    }

    /**
     * 调用不存在的方法
     * @param $name
     * @param $arguments
     * @return mixed|void
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function __call($name, $arguments)
    {
        $this->crontabLog($name . '方法不存在');
    }

    /**
     * 定时任务日志
     * @param $msg
     */
    protected function crontabLog($msg)
    {
        $timer_log_open = config("log.timer_log", false);
        if ($timer_log_open) {
            $date = date('Y-m-d H:i:s', time());
            Log::write($date . $msg, 'crontab');
        }
    }

    /**
     * 未支付自动取消订单
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function orderCancel()
    {
        try {
            app()->make(StoreOrderServices::class)->orderUnpaidCancel();
            $this->crontabLog(' 执行未支付自动取消订单');
        } catch (\Throwable $e) {
            $this->crontabLog('自动取消订单失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 拼团到期订单处理
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function pinkExpiration()
    {
        try {
            app()->make(StorePinkServices::class)->statusPink();
            $this->crontabLog(' 执行拼团到期订单处理');
        } catch (\Throwable $e) {
            $this->crontabLog('拼团到期订单处理失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 自动解除上级绑定
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function agentUnbind()
    {
        try {
            app()->make(AgentManageServices::class)->removeSpread();
            $this->crontabLog(' 执行自动解绑上级绑定');
        } catch (\Throwable $e) {
            $this->crontabLog('自动解除上级绑定失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 更新直播商品状态
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function liveProductStatus()
    {
        try {
            app()->make(LiveGoodsServices::class)->syncGoodStatus();
            $this->crontabLog(' 执行更新直播商品状态');
        } catch (\Throwable $e) {
            $this->crontabLog('更新直播商品状态失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 更新直播间状态
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function liveRoomStatus()
    {
        try {
            app()->make(LiveRoomServices::class)->syncRoomStatus();
            $this->crontabLog(' 执行更新直播间状态');
        } catch (\Throwable $e) {
            $this->crontabLog('更新直播间状态失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 自动收货
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function takeDelivery()
    {
        try {
            app()->make(StoreOrderTakeServices::class)->autoTakeOrder();
            $this->crontabLog(' 执行自动收货');
        } catch (\Throwable $e) {
            $this->crontabLog('自动收货失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 预售到期商品自动下架
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function advanceOff()
    {
        try {
            app()->make(StoreProductServices::class)->downAdvance();
            $this->crontabLog(' 执行预售到期商品自动下架');
        } catch (\Throwable $e) {
            $this->crontabLog('预售到期商品自动下架失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 自动好评
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function productReplay()
    {
        try {
            app()->make(StoreOrderServices::class)->autoComment();
            $this->crontabLog(' 执行自动好评');
        } catch (\Throwable $e) {
            $this->crontabLog('自动好评失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 清除昨日海报
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function clearPoster()
    {
        try {
            app()->make(SystemAttachmentServices::class)->emptyYesterdayAttachment();
            $this->crontabLog(' 执行清除昨日海报');
        } catch (\Throwable $e) {
            $this->crontabLog('清除昨日海报失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 执行自动开具/冲红电子发票
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function autoInvoice()
    {
        try {
            $invoiceServices = app()->make(StoreOrderInvoiceServices::class);
            $invoiceServices->autoInvoice();
            $invoiceServices->autoInvoiceRed();
            $this->crontabLog(' 执行自动开具/冲红电子发票');
        } catch (\Throwable $e) {
            $this->crontabLog('自动开具/冲红电子发票失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 未签到提醒
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2023/9/30
     */
    public function signRemind()
    {
        try {
            app()->make(UserSignServices::class)->sendSignRemind();
            $this->crontabLog(' 执行未签到提醒');
        } catch (\Throwable $e) {
            $this->crontabLog('未签到提醒失败,失败原因:' . $e->getMessage());
        }
    }

    /**
     * 自定义定时器
     * @param string $customCode
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2024/6/6
     */
    public function customTimer($customCode = '')
    {
        try {
            eval($customCode);
            $this->crontabLog(' 自定义定时器执行成功');
        } catch (\Throwable $e) {
            $this->crontabLog('自定义定时器执行失败,失败原因:' . $e->getMessage());
        }
    }
}
