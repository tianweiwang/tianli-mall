<?php
declare(strict_types=1);

namespace app\merchantapi;

use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use think\exception\Handle;
use think\exception\HttpException;
use think\Response;
use Throwable;

final class MerchantApiExceptionHandle extends Handle
{
    public function report(Throwable $exception): void
    {
        if ($exception instanceof AuthSessionException
            || ($exception instanceof HttpException
                && in_array($exception->getStatusCode(), [401, 403, 404, 422], true))) {
            return;
        }

        // Fixed event names keep arguments, credentials and driver details out
        // of error logs, including when the global application is in debug mode.
        try {
            $this->app->log->record(
                $exception instanceof AuthUnavailableException
                    ? 'merchantapi.auth_unavailable'
                    : 'merchantapi.unhandled_exception',
                'error'
            );
        } catch (Throwable $loggingError) {
            // Reporting must not replace the fixed response with a log failure.
        }
    }

    public function render($request, Throwable $exception): Response
    {
        if ($exception instanceof AuthUnavailableException) {
            return self::failure(503, '认证服务暂不可用');
        }
        if ($exception instanceof AuthSessionException) {
            return self::failure(401, '认证信息无效，请重新登录');
        }
        if ($exception instanceof HttpException) {
            $messages = [
                401 => '请先登录商家账号',
                403 => '没有权限访问',
                404 => '资源不存在',
                422 => '请求参数不正确',
            ];
            $status = $exception->getStatusCode();
            if (isset($messages[$status])) {
                return self::failure($status, $messages[$status]);
            }
        }
        return self::failure(503, '服务暂不可用');
    }

    private static function failure(int $status, string $message): Response
    {
        return Response::create(['status' => $status, 'msg' => $message, 'data' => []], 'json', $status);
    }
}
