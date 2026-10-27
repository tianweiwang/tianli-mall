<?php
declare(strict_types=1);

namespace app\merchantapi\middleware;

use app\Request;
use app\services\marketplace\MerchantRequestQuotaServices;
use Closure;
use crmeb\interfaces\MiddlewareInterface;
use crmeb\services\RuntimeLog;
use think\Response;
use Throwable;

final class MerchantRequestQuotaMiddleware implements MiddlewareInterface
{
    public function __construct(private MerchantRequestQuotaServices $services)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $context = $request->merchantContext();
        $decision = $this->services->admit($context);
        if ($decision['log'] === true) {
            try {
                $route = $request->rule()?->getName();
                RuntimeLog::merchantQuota(app(), $context, is_string($route) && $route !== '' ? $route : 'unknown', $decision);
            } catch (Throwable $ignored) {
                // Diagnostics must not replace the quota response or the controller result.
            }
        }
        if ($decision['outcome'] === 'disabled' || $decision['outcome'] === 'admitted') return $next($request);
        if ($decision['outcome'] === 'limited') {
            return Response::create(['status' => 429, 'msg' => '请求过于频繁，请稍后再试', 'data' => []], 'json', 429)
                ->header(['Retry-After' => (string)$decision['retry_after_seconds']]);
        }
        return Response::create(['status' => 503, 'msg' => '服务暂不可用', 'data' => []], 'json', 503);
    }
}
