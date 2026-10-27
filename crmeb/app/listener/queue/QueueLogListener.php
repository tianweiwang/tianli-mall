<?php
declare(strict_types=1);

namespace app\listener\queue;

use crmeb\services\RuntimeLog;
use think\queue\event\JobExceptionOccurred;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use Throwable;

final class QueueLogListener
{
    public function handle(mixed $event): void
    {
        try {
            $name = match (true) {
                $event instanceof JobProcessing => 'queue.processing',
                $event instanceof JobProcessed => 'queue.returned',
                $event instanceof JobExceptionOccurred => 'queue.exception',
                $event instanceof JobFailed => 'queue.failed',
                default => null,
            };
            if ($name === null) return;
            $error = $event instanceof JobExceptionOccurred || $event instanceof JobFailed
                ? $event->exception : null;
            RuntimeLog::queueEvent(app(), $event->job, $name, $error);
        } catch (Throwable $loggingError) {
            // Diagnostic failures must not change worker control or task outcome.
        }
    }
}
