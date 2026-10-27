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
use app\services\marketplace\order\MarketplaceOrderPostCommitServices;
use app\services\marketplace\product\MarketplaceProductScanGcTimer;
use app\services\order\StoreOrderInvoiceServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreOrderTakeServices;
use app\services\product\product\StoreProductServices;
use app\services\system\attachment\SystemAttachmentServices;
use app\services\user\UserSignServices;
use crmeb\services\RuntimeLog;
use think\facade\Cache;

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
        'marketplaceProductScanGc' => '商品扫码临时图片清理',
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

    /** One-second scheduler wake-up; the timer applies the configured Redis-time GC cadence. */
    public function marketplaceProductScanGc(): array
    {
        $timerRun = $this->beginTimerRun('marketplaceProductScanGc');
        try {
            return app()->make(MarketplaceProductScanGcTimer::class)->tick();
        } catch (\Throwable $error) {
            $this->timerRunEvent($timerRun, 'timer.exception', $error);
            return ['state' => 'unavailable'];
        } finally {
            $this->endTimerRun($timerRun);
        }
    }

    /** Scan only durable V1 post-commit responsibilities; this does not dispatch other timers. */
    public function marketplaceOrderPostCommit(): array
    {
        $timerRun = $this->beginTimerRun('marketplaceOrderPostCommit');
        try {
            // A progress hint only: candidates and completion always come from the durable ledger.
            $cursor = 0;
            try {
                $hint = Cache::get('marketplace_order_post_commit_cursor');
                if ((is_int($hint) || is_string($hint)) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$hint) === 1
                    && (int)$hint <= 2147483647) $cursor = (int)$hint;
            } catch (\Throwable $error) {
                $this->timerRunEvent($timerRun, 'timer.cursor_read_unavailable', $error);
            }
            $result = app()->make(MarketplaceOrderPostCommitServices::class)->scanPending($cursor, 100);
            $stored = false;
            $writeError = null;
            try { $stored = Cache::set('marketplace_order_post_commit_cursor', $result['next_cursor']) === true; }
            catch (\Throwable $error) { $writeError = $error; }
            if (!$stored) {
                $this->timerRunEvent($timerRun, 'timer.cursor_write_unavailable', $writeError);
            }
        } catch (\Throwable $error) {
            $this->timerRunEvent($timerRun, 'timer.exception', $error);
            throw $error;
        }
        $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('unknown');
        $this->timerRunEvent($timerRun, 'timer.unknown_task');
        $this->endTimerRun($timerRun);
    }

    /** Invocation-local only; the two existing always-on diagnostic paths still need an identity. */
    private function beginTimerRun(string $taskName): array
    {
        try {
            if (!config("log.timer_log", false) && !in_array($taskName, ['marketplaceProductScanGc', 'marketplaceOrderPostCommit'], true)) return [];
            $started = hrtime(true);
            if (!is_int($started) || $started < 0) return [];
            $run = ['task_name' => $taskName, 'timer_run_id' => bin2hex(random_bytes(16)), 'started_ns' => $started];
            $this->timerRunEvent($run, 'timer.started');
            return $run;
        } catch (\Throwable $ignored) {
            return [];
        }
    }

    /** Accept no free text; custom eval may change locals without changing its original business scope. */
    private function timerRunEvent($run, string $event, ?\Throwable $error = null): void
    {
        try {
            if (!is_array($run) || array_keys($run) !== ['task_name', 'timer_run_id', 'started_ns']
                || !is_string($run['task_name']) || !is_string($run['timer_run_id'])
                || !is_int($run['started_ns']) || $run['started_ns'] < 0) return;
            $now = hrtime(true);
            if (!is_int($now) || $now < $run['started_ns']) return;
            $elapsed = $event === 'timer.started' ? 0 : min(2147483647, intdiv($now - $run['started_ns'], 1000000));
            RuntimeLog::timerEvent(app(), $run['task_name'], $event, $run['timer_run_id'], $elapsed, $error);
        } catch (\Throwable $ignored) {
            // Logging, timing and malformed diagnostics never replace the task's original result.
        }
    }

    private function endTimerRun($run): void
    {
        try {
            $this->timerRunEvent($run, 'timer.returned');
        } catch (\Throwable $ignored) {
            // Preserve the original return or swallowed business exception.
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
        $timerRun = $this->beginTimerRun('orderCancel');
        try {
            app()->make(StoreOrderServices::class)->orderUnpaidCancel();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('pinkExpiration');
        try {
            app()->make(StorePinkServices::class)->statusPink();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('agentUnbind');
        try {
            app()->make(AgentManageServices::class)->removeSpread();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('liveProductStatus');
        try {
            app()->make(LiveGoodsServices::class)->syncGoodStatus();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('liveRoomStatus');
        try {
            app()->make(LiveRoomServices::class)->syncRoomStatus();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('takeDelivery');
        try {
            app()->make(StoreOrderTakeServices::class)->autoTakeOrder();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('advanceOff');
        try {
            app()->make(StoreProductServices::class)->downAdvance();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('productReplay');
        try {
            app()->make(StoreOrderServices::class)->autoComment();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('clearPoster');
        try {
            app()->make(SystemAttachmentServices::class)->emptyYesterdayAttachment();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('autoInvoice');
        try {
            $invoiceServices = app()->make(StoreOrderInvoiceServices::class);
            $invoiceServices->autoInvoice();
            $invoiceServices->autoInvoiceRed();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('signRemind');
        try {
            app()->make(UserSignServices::class)->sendSignRemind();
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun, 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun);
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
        $timerRun = $this->beginTimerRun('customTimer');
        try {
            eval($customCode);
        } catch (\Throwable $e) {
            $this->timerRunEvent($timerRun ?? [], 'timer.exception', $e);
        } finally {
            $this->endTimerRun($timerRun ?? []);
        }
    }
}
