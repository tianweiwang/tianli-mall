<?php
declare(strict_types=1);

namespace crmeb\services;

use app\Request;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use think\App;
use think\exception\HttpException;
use think\queue\Job;
use think\Response;
use Throwable;

/** Fixed runtime diagnostics only; business audit rows and queue payloads are separate. */
final class RuntimeLog
{
    public static function attach(App $app, Response $response): void
    {
        try {
            $request = $app->request;
            if ($request instanceof Request) {
                $response->header(['X-Request-ID' => $request->runtimeRequestId()]);
            }
        } catch (Throwable $ignored) {
            // Diagnostics must not replace the application's response.
        }
    }

    public static function response(App $app, Response $response): void
    {
        try {
            $record = self::request($app);
            if ($record === null) return;
            // Read only the numeric envelope status; never serialize response data.
            $data = $response->getData();
            $status = is_array($data) ? self::status($data['status'] ?? null) : null;
            unset($data);
            $record['event'] = 'http.response';
            $record['http_status'] = self::httpStatus($response->getCode());
            $record['business_status'] = $status;
            $level = 'info';
            if ($status === 200 && $app->config->get('log.success_log', false)) {
                $level = 'success';
            } elseif ($status !== null && $status !== 200 && $app->config->get('log.fail_log', false)) {
                $level = 'fail';
            }
            self::write($app, $record, $level);
        } catch (Throwable $ignored) {
            // This also covers log configuration and storage failures.
        }
    }

    public static function exception(App $app, Throwable $error): void
    {
        try {
            $record = self::request($app);
            if ($record === null) return;
            $status = self::status($error->getCode());
            $httpStatus = $error instanceof HttpException ? self::httpStatus($error->getStatusCode()) : null;
            $denied = ($error instanceof AuthException && in_array($status, [401, 403], true))
                || ($error instanceof HttpException && in_array($httpStatus, [401, 403], true));
            $record['event'] = $error instanceof AuthUnavailableException ? 'http.auth_unavailable'
                : ($denied ? 'http.access_denied' : 'http.exception');
            // Legacy renderers may return HTTP 200 for a 401/403 business error.
            // Only response() observes the actual transport and envelope status.
            $record += self::exceptionMetadata($app, $error);
            self::write($app, $record, $denied ? 'warning' : 'error');
        } catch (Throwable $ignored) {
            // Never log the logging failure's message or replace the original error.
        }
    }

    /** Called inside the authenticated stack, before its context is cleared. No request payload is retained. */
    public static function merchantQuota(App $app, MerchantContext $context, string $routeName, array $decision): void
    {
        try {
            $outcome = $decision['outcome'] ?? null;
            if (($decision['log'] ?? null) !== true || !in_array($outcome, ['admitted', 'limited', 'unavailable'], true)) return;
            $record = self::request($app);
            if ($record === null || $record['surface'] !== 'merchantapi'
                || !self::requestIdValid($context->requestId) || $record['request_id'] !== $context->requestId) return;

            $version = $decision['policy_version'] ?? null;
            $version = is_string($version) && preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,31}\z/D', $version) === 1 ? $version : null;
            $window = $decision['window_seconds'] ?? null;
            $window = is_int($window) && $window >= 1 && $window <= 86400 ? $window : null;
            $limit = $decision['max_requests'] ?? null;
            $limit = is_int($limit) && $limit >= 1 && $limit <= 1000000 ? $limit : null;
            $used = $decision['used'] ?? null;
            $remaining = $decision['remaining'] ?? null;
            $retry = $decision['retry_after_seconds'] ?? null;
            if ($outcome === 'unavailable') {
                if ($used !== null || $remaining !== null || $retry !== null) return;
            } elseif ($version === null || $window === null || $limit === null
                || !is_int($used) || $used < 1 || $used > $limit
                || !is_int($remaining) || $remaining !== $limit - $used
                || !is_int($retry) || $retry < 1 || $retry > $window
                || ($outcome === 'limited' && ($used !== $limit || $remaining !== 0))) {
                return;
            }

            $record += ['event' => 'merchant.request_quota', 'mer_id' => $context->merId,
                'route_name' => preg_match('/\Amerchant\.[A-Za-z0-9_.-]{1,100}\z/D', $routeName) === 1 ? $routeName : 'unknown',
                'policy_version' => $version, 'outcome' => $outcome, 'window_seconds' => $window,
                'max_requests' => $limit, 'used' => $used, 'remaining' => $remaining, 'retry_after_seconds' => $retry];
            self::write($app, $record, $outcome === 'admitted' ? 'info' : ($outcome === 'limited' ? 'warning' : 'error'));
        } catch (Throwable $ignored) {
            // Diagnostics must not replace quota or business responses, or disclose driver messages.
        }
    }

    public static function queueEvent(App $app, Job $job, string $event, ?Throwable $error = null, ?string $taskRequestId = null): void
    {
        try {
            if (!in_array($event, ['queue.processing', 'queue.returned', 'queue.exception', 'queue.failed',
                'queue.handler_started', 'queue.handler_error', 'queue.audit_completed'], true)) return;
            if ($taskRequestId !== null && !self::requestIdValid($taskRequestId)) return;
            $attempt = $job->attempts();
            $attempt = is_int($attempt) && $attempt > 0 ? $attempt : null;
            $record = ['event' => $event, 'job_id' => self::jobId($job->getJobId()),
                'connection' => self::connection($job->getConnection()),
                'job_type' => $job->getName() === \app\jobs\marketplace\AuditLogJob::class ? 'marketplace.audit' : 'legacy',
                'attempt' => $attempt, 'repeat_attempt' => $attempt !== null && $attempt > 1,
                // Framework state only: these flags do not confirm Redis operations.
                'deleted' => (bool) $job->isDeleted(), 'released' => (bool) $job->isReleased(),
                'failed' => (bool) $job->hasFailed(), 'task_request_id' => $taskRequestId];
            if ($error !== null) $record += self::exceptionMetadata($app, $error);
            $level = in_array($event, ['queue.exception', 'queue.failed', 'queue.handler_error'], true) ? 'error' : 'info';
            self::write($app, $record, $level);
        } catch (Throwable $ignored) {
            // Observing a task must never change its execution or release/delete path.
        }
    }

    public static function queueSubmitted(App $app, mixed $result, ?string $contextRequestId = null, ?string $taskRequestId = null): void
    {
        try {
            if ($contextRequestId !== null || $taskRequestId !== null) {
                if (!self::requestIdValid($contextRequestId) || !self::requestIdValid($taskRequestId)) return;
                // The marketplace caller verifies its real queue acknowledgement first.
                $record = ['event' => 'queue.submitted', 'source_kind' => 'merchant_context',
                    'origin_request_id' => $contextRequestId, 'task_request_id' => $taskRequestId,
                    'job_id' => self::jobId($result), 'connection' => 'marketplace'];
            } else {
                $console = $app->runningInConsole();
                $originRequestId = null;
                if (!$console) {
                    $request = $app->request;
                    if (!$request instanceof Request) return;
                    $originRequestId = $request->runtimeRequestId();
                }
                $record = ['event' => $result ? 'queue.submit_returned' : 'queue.enqueue_failed',
                    'source_kind' => $console ? 'console' : 'http_request',
                    'origin_request_id' => $originRequestId, 'task_request_id' => null,
                    'job_id' => self::jobId($result),
                    'connection' => self::connection($app->config->get('queue.default', 'redis'))];
            }
            self::write($app, $record, $record['event'] === 'queue.enqueue_failed' ? 'error' : 'info');
        } catch (Throwable $ignored) {
            // Preserve the producer's original return value and error handling.
        }
    }

    public static function timerEvent(App $app, string $taskName, string $event, string $runId, int $elapsedMs, ?Throwable $error = null): void
    {
        try {
            $always = ($taskName === 'marketplaceProductScanGc' && $event === 'timer.exception')
                || ($taskName === 'marketplaceOrderPostCommit'
                    && in_array($event, ['timer.exception', 'timer.cursor_read_unavailable', 'timer.cursor_write_unavailable'], true));
            if ((!$app->config->get('log.timer_log', false) && !$always)
                || !in_array($taskName, ['orderCancel', 'marketplaceOrderPostCommit', 'marketplaceProductScanGc',
                    'pinkExpiration', 'agentUnbind', 'liveProductStatus', 'liveRoomStatus', 'takeDelivery',
                    'advanceOff', 'productReplay', 'clearPoster', 'autoInvoice', 'signRemind', 'customTimer', 'unknown'], true)
                || !in_array($event, ['timer.started', 'timer.returned', 'timer.exception', 'timer.unknown_task',
                    'timer.cursor_read_unavailable', 'timer.cursor_write_unavailable'], true)
                || !self::requestIdValid($runId) || $elapsedMs < 0 || $elapsedMs > 2147483647
                || ($event === 'timer.started' && $elapsedMs !== 0)
                || ($event === 'timer.exception' && $error === null)
                || ($error !== null && !in_array($event, ['timer.exception', 'timer.cursor_read_unavailable', 'timer.cursor_write_unavailable'], true))) return;
            $record = ['event' => $event, 'source_kind' => 'timer', 'task_name' => $taskName,
                'timer_run_id' => $runId, 'elapsed_ms' => $elapsedMs];
            if ($error !== null) $record += self::exceptionMetadata($app, $error);
            self::write($app, $record, 'crontab');
        } catch (Throwable $ignored) {
            // Task arguments, custom code and exception messages never enter timer diagnostics.
        }
    }

    public static function consoleException(App $app, Throwable $error): void
    {
        try {
            // CLI processes may retain a Request instance; never treat it as HTTP provenance.
            $record = ['event' => 'console.exception', 'diagnostic_id' => bin2hex(random_bytes(16))];
            $record += self::exceptionMetadata($app, $error);
            self::write($app, $record, 'error');
        } catch (Throwable $ignored) {
            // Reporting failures must not replace the original CLI exception.
        }
    }

    public static function paymentSaved(App $app, string $event, int $orderId, string $attemptId, string $operation,
        string $savedState, int $fulfillmentHold, ?string $holdReason, array $exceptionCodes): void
    {
        try {
            if (!in_array($event, ['payment.fact_saved', 'payment.hold_saved'], true)
                || $orderId < 1 || $orderId > 2147483647
                || preg_match('/\A[1-9][0-9]{0,19}\z/D', $attemptId) !== 1
                || (strlen($attemptId) === 20 && strcmp($attemptId, '18446744073709551615') > 0)
                || !in_array($operation, ['createPayment', 'queryPayment', 'closePayment'], true)
                || !in_array($savedState, ['applied', 'succeeded_observed', 'closed', 'pending', 'unresolved'], true)
                || !in_array($fulfillmentHold, [0, 1], true)) return;
            $codes = ['BINDING_MISMATCH', 'CLOSED_THEN_SUCCESS', 'SUCCESS_REF_CHANGED', 'OTHER_WINNER',
                'ROOT_CANCELLED', 'RESERVE_RELEASED', 'MERCHANT_UNAVAILABLE', 'FULFILLMENT_DISABLED', 'ROOT_PAYMENT_MISMATCH'];
            if (($fulfillmentHold === 0 && $holdReason !== null)
                || ($fulfillmentHold === 1 && !in_array($holdReason, [...$codes, 'EVIDENCE_CAPACITY', 'FACT_KEY_COLLISION'], true))
                || !array_is_list($exceptionCodes) || count($exceptionCodes) > count($codes)) return;
            foreach ($exceptionCodes as $code) if (!in_array($code, $codes, true)) return;
            $exceptionCodes = array_values(array_unique($exceptionCodes));
            sort($exceptionCodes);
            $source = self::paymentSource($app);
            if ($source === null) return;
            $record = ['event' => $event] + $source + ['order_id' => $orderId, 'attempt_id' => $attemptId,
                'operation' => $operation, 'saved_state' => $savedState, 'fulfillment_hold' => $fulfillmentHold,
                'hold_reason' => $holdReason, 'exception_codes' => $exceptionCodes];
            self::write($app, $record, $fulfillmentHold === 1 ? 'warning' : 'info');
        } catch (Throwable $ignored) {
            // A diagnostic failure never changes a committed payment result or the caller's error.
        }
    }

    public static function paymentDiagnostic(App $app, string $event, ?Throwable $error = null): void
    {
        try {
            $allin = in_array($event, ['payment.allin_response_parsed', 'payment.allin_callback_received',
                'payment.allin_callback_verified', 'payment.allin_callback_verification_returned'], true);
            if (!$allin && !in_array($event, ['payment.alipay_callback_error', 'payment.alipay_verification_error',
                'payment.alipay_transfer_failed', 'payment.alipay_transfer_error'], true)) return;
            $source = self::paymentSource($app);
            if ($source === null) return;
            $record = ['event' => $event] + $source;
            if ($error !== null) $record += self::exceptionMetadata($app, $error);
            self::write($app, $record, $allin ? 'info' : 'error');
        } catch (Throwable $ignored) {
            // Provider data, callback parameters and exception messages never enter this record.
        }
    }

    public static function orderMaintenanceDiagnostic(App $app, string $event, ?Throwable $error = null): void
    {
        try {
            $exceptions = ['order.cancel_exception', 'order.receipt_exception'];
            if (!in_array($event, [...$exceptions, 'order.cancel_returned_false', 'order.receipt_returned_false'], true)
                || (in_array($event, $exceptions, true) !== ($error !== null))) return;
            // Use actual HTTP/CLI provenance; no task context or order data is retained.
            $source = self::paymentSource($app);
            if ($source === null) return;
            $record = ['event' => $event] + $source;
            if ($error !== null) $record += self::exceptionMetadata($app, $error);
            self::write($app, $record, 'error');
        } catch (Throwable $ignored) {
            // Diagnostics never change a maintenance transaction or its original catch behavior.
        }
    }

    private static function paymentSource(App $app): ?array
    {
        if ($app->runningInConsole()) {
            return ['source_kind' => 'console', 'request_id' => null, 'diagnostic_id' => bin2hex(random_bytes(16))];
        }
        $request = $app->request;
        if (!$request instanceof Request) return null;
        return ['source_kind' => 'http_request', 'request_id' => $request->runtimeRequestId(), 'diagnostic_id' => null];
    }

    private static function exceptionMetadata(App $app, Throwable $error): array
    {
        $class = get_class($error);
        $file = self::sourceFile($app, $error);
        $line = $error->getLine();
        return ['exception_code' => self::status($error->getCode()),
            'reported_http_status' => $error instanceof HttpException ? self::httpStatus($error->getStatusCode()) : null,
            'exception_class' => strlen($class) <= 160
                && preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]*\z/D', $class) === 1 ? $class : 'unknown',
            'source_file' => $file,
            'source_line' => $file !== 'unknown' && $line > 0 && $line <= 2147483647 ? $line : 0];
    }

    private static function requestIdValid(?string $value): bool
    {
        return $value !== null && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1;
    }

    private static function jobId(mixed $value): int|string|null
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value)) return null;
        if (preg_match('/\A[A-Za-z0-9]{32}\z/D', $value) === 1) return $value;
        // Database IDs may arrive as canonical decimal strings; keep their native representation.
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) return null;
        $maximum = (string) PHP_INT_MAX;
        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0) ? $value : null;
    }

    private static function connection(mixed $value): string
    {
        return in_array($value, ['marketplace', 'redis', 'database', 'sync'], true) ? $value : 'unknown';
    }

    private static function request(App $app): ?array
    {
        $request = $app->request;
        if (!$request instanceof Request) return null;
        $surface = $app->http->getName();
        $surface = in_array($surface, ['api', 'adminapi', 'merchantapi', 'kefuapi', 'outapi'], true) ? $surface : 'unknown';
        $method = $request->method(true);
        $method = in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'], true)
            ? $method : 'OTHER';
        $started = $request->time(true);
        $elapsed = is_int($started) || is_float($started) ? (microtime(true) - $started) * 1000 : 0;
        $elapsed = is_finite($elapsed) ? (int) min(2147483647, max(0, ceil($elapsed))) : 0;
        return ['request_id' => $request->runtimeRequestId(), 'surface' => $surface,
            'method' => $method, 'elapsed_ms' => $elapsed];
    }

    private static function status(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\A[0-9]{1,6}\z/D', $value) === 1) $value = (int) $value;
        return is_int($value) && $value >= 0 && $value <= 999999 ? $value : null;
    }

    private static function httpStatus(mixed $value): ?int
    {
        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }

    private static function sourceFile(App $app, Throwable $error): string
    {
        $root = rtrim(str_replace('\\', '/', $app->getRootPath()), '/') . '/';
        $file = str_replace('\\', '/', $error->getFile());
        if (!str_starts_with($file, $root)) return 'unknown';
        $relative = substr($file, strlen($root));
        if (strlen($relative) > 240 || preg_match('/\A(?:app|crmeb|vendor)\/[A-Za-z0-9_.\/-]+\.php\z/D', $relative) !== 1
            || in_array('..', explode('/', $relative), true) || in_array('.', explode('/', $relative), true)
            || str_contains($relative, '//')) return 'unknown';
        return $relative;
    }

    private static function write(App $app, array $record, string $level): void
    {
        $app->log->write(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $level);
    }
}
