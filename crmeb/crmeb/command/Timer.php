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
namespace crmeb\command;

use app\services\system\crontab\SystemCrontabServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Workerman\Worker;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\services\CacheService;
use think\facade\Log;
use Workerman\Events\EventInterface;
use Workerman\Lib\Timer as WorkerTimer;

class Timer extends Command
{
    /**
     * @var int
     */
    protected $timer;

    /**
     * @var int|float
     */
    protected $interval = 1;

    protected function configure()
    {
        // 指令配置
        $this->setName('timer')
            ->addArgument('status', Argument::REQUIRED, 'start/stop/reload/status/connections')
            ->addOption('d', null, Option::VALUE_NONE, 'daemon（守护进程）方式启动')
            ->addOption('i', null, Option::VALUE_OPTIONAL, '多长时间执行一次,可以精确到0.001')
            ->setDescription('start/stop/restart 定时任务');
    }

    protected function init(Input $input, Output $output)
    {
        global $argv;

        if ($input->hasOption('i'))
            $this->interval = floatval($input->getOption('i'));

        $argv[1] = $input->getArgument('status') ?: 'start';
        if ($input->hasOption('d')) {
            $argv[2] = '-d';
        } else {
            unset($argv[2]);
        }
    }

    protected function execute(Input $input, Output $output)
    {
        $this->init($input, $output);
        Worker::$pidFile = app()->getRootPath() . 'runtime/timer.pid';
        Worker::$logFile = app()->getRootPath() . 'runtime/timer.log';
        $task = new Worker();
        date_default_timezone_set('PRC');
        $task->count = 1;
        $start = null;
        $start = function (Worker $worker) use ($task, &$start) {
            app()->make(SystemCrontabServices::class)->crontabCommandRun($task);
            $log = static function (string $code): void {
                try { Log::error($code); } catch (\Throwable $ignored) {}
            };
            $pid = getmypid(); $loop = Worker::getEventLoop(); $workerId = $task->workerId;
            $active = false; $busy = false; $closed = false; $timerId = null; $stop = null;
            $owned = static function () use ($task, &$start, $pid, $loop, $workerId): bool {
                $workers = Worker::getAllWorkers();
                return is_int($pid) && $pid > 0 && getmypid() === $pid
                    && is_string($workerId) && $workerId !== '' && $task->workerId === $workerId
                    && count($workers) === 1 && ($workers[$workerId] ?? null) === $task
                    && $task->count === 1 && $task->id === 0 && $task->getMainSocket() === null
                    && $loop instanceof EventInterface && Worker::getEventLoop() === $loop
                    && $task->onWorkerStart === $start;
            };
            if ($worker !== $task || !$owned() || Worker::getStatus() !== Worker::STATUS_RUNNING
                || $task->stopping !== false || $task->onWorkerStop !== null) {
                $log('MARKETPLACE_FULFILLMENT_WORKER_UNAVAILABLE'); return;
            }
            $live = static function () use ($task, $owned, &$active, &$closed, &$timerId, &$stop): bool {
                return $active && !$closed && is_int($timerId) && $timerId > 0 && $owned()
                    && Worker::getStatus() === Worker::STATUS_RUNNING && $task->stopping === false
                    && $task->onWorkerStop === $stop;
            };
            $trace = static function (string $phase, mixed $actualTimerId, array $details) use
                ($task, $owned, $loop, &$active, &$busy, &$closed): void {
                try {
                    if (!in_array($phase, ['timer_started', 'scan_returned', 'timer_removed'], true)
                        || !is_int($actualTimerId) || $actualTimerId < 1) return;
                    if ($phase === 'timer_started' && (!is_int($details['add_started_ns'] ?? null)
                        || $details['add_started_ns'] < 1)) return;
                    $now = hrtime(true);
                    if (!is_int($now) || $now < 1) return;
                    $workers = Worker::getAllWorkers();
                    $record = ['schema' => 1, 'phase' => $phase, 'pid' => getmypid(),
                        'worker_id' => $task->workerId, 'loop_class' => get_class($loop),
                        'loop_object_id' => spl_object_id($loop), 'timer_id' => $actualTimerId,
                        'monotonic_ns' => $now, 'active' => $active, 'busy' => $busy,
                        'closed' => $closed, 'owned' => $owned(), 'worker_count' => count($workers),
                        'slot' => $task->id, 'count' => $task->count,
                        'socketless' => $task->getMainSocket() === null, 'details' => (object)$details];
                    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    if (strlen($json) > 8192 || str_contains($json, "\n") || str_contains($json, "\r")) return;
                    Log::channel('file')->write('MARKETPLACE_FULFILLMENT_WORKER_EVENT ' . $json, 'info');
                } catch (\Throwable $ignored) {}
            };
            $cursor = static function (mixed $due, mixed $ship): array {
                $due = MarketplaceOrderInput::unsigned($due, '到期游标');
                if (!is_string($ship) || preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', $ship) !== 1
                    || bccomp($ship, '18446744073709551615', 0) > 0
                    || (($due === 0) !== ($ship === '0'))) {
                    throw new \RuntimeException('MARKETPLACE_FULFILLMENT_CURSOR_UNAVAILABLE');
                }
                return [$due, $ship];
            };
            $stop = static function (Worker $worker) use ($task, $owned, $loop, $log, $trace,
                &$active, &$closed, &$timerId, &$stop): void {
                $active = false; $closed = true;
                if ($worker !== $task || !$owned() || $task->onWorkerStop !== $stop) {
                    $log('MARKETPLACE_FULFILLMENT_TIMER_STOP_PENDING'); return;
                }
                $ownedId = $timerId; $timerId = null;
                if (is_int($ownedId) && $ownedId > 0) {
                    try {
                        if ($loop->del($ownedId, EventInterface::EV_TIMER) !== true) {
                            $log('MARKETPLACE_FULFILLMENT_TIMER_STOP_PENDING');
                        } else {
                            $trace('timer_removed', $ownedId, []);
                        }
                    } catch (\Throwable $ignored) { $log('MARKETPLACE_FULFILLMENT_TIMER_STOP_PENDING'); }
                }
            };
            $task->onWorkerStop = $stop;
            $tick = static function () use ($live, $cursor, $log, $trace, &$busy, &$timerId): void {
                if ($busy || !$live()) return;
                $busy = true;
                try {
                    $due = 0; $ship = '0';
                    try {
                        $hint = CacheService::get('marketplace_fulfillment_receive_cursor', null);
                        if ($hint !== null) {
                            if (!is_array($hint) || count($hint) !== 2 || !isset($hint['due_at'], $hint['ship_id'])) {
                                throw new \RuntimeException('MARKETPLACE_FULFILLMENT_CURSOR_UNAVAILABLE');
                            }
                            [$due, $ship] = $cursor($hint['due_at'], $hint['ship_id']);
                        }
                    } catch (\Throwable $ignored) { $due = 0; $ship = '0'; }
                    if (!$live()) return;
                    $scan = app()->make(MarketplaceOrderFulfillmentServices::class)->scanDue($due, $ship, 100);
                    [$nextDue, $nextShip] = $cursor($scan['next_due_at'] ?? null, $scan['next_ship_id'] ?? null);
                    if (!$live()) return;
                    if (CacheService::set('marketplace_fulfillment_receive_cursor', ['due_at'=>$nextDue, 'ship_id'=>$nextShip]) !== true) {
                        $log('MARKETPLACE_FULFILLMENT_CURSOR_PENDING');
                    } else {
                        $trace('scan_returned', $timerId, ['scan' => $scan, 'cursor_saved' => true]);
                    }
                } catch (\Throwable $ignored) { $log('MARKETPLACE_FULFILLMENT_SCAN_PENDING'); }
                finally { $busy = false; }
            };
            $addStartedNs = null;
            try { $addStartedNs = hrtime(true); } catch (\Throwable $ignored) {}
            try {
                $timerId = WorkerTimer::add(60, $tick, [], true);
                if (!is_int($timerId) || $timerId < 1 || $closed || !$owned()
                    || Worker::getStatus() !== Worker::STATUS_RUNNING || $task->stopping !== false
                    || $task->onWorkerStop !== $stop) {
                    throw new \RuntimeException('MARKETPLACE_FULFILLMENT_WORKER_UNAVAILABLE');
                }
                $active = true;
                $trace('timer_started', $timerId,
                    ['period_seconds' => 60, 'batch' => 100, 'add_started_ns' => $addStartedNs]);
            } catch (\Throwable $ignored) {
                $active = false;
                if (is_int($timerId) && $timerId > 0 && $owned()) {
                    $ownedId = $timerId; $timerId = null;
                    try {
                        if ($loop->del($ownedId, EventInterface::EV_TIMER) !== true) {
                            $log('MARKETPLACE_FULFILLMENT_TIMER_STOP_PENDING');
                        }
                    } catch (\Throwable $ignored) { $log('MARKETPLACE_FULFILLMENT_TIMER_STOP_PENDING'); }
                }
                $log('MARKETPLACE_FULFILLMENT_WORKER_UNAVAILABLE');
            }
        };
        $task->onWorkerStart = $start;
        $task->runAll();
    }
}
