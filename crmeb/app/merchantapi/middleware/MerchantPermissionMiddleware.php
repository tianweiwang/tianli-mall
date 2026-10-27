<?php
declare(strict_types=1);

namespace app\merchantapi\middleware;

use app\Request;
use app\services\marketplace\MerchantPermissions;
use Closure;
use crmeb\interfaces\MiddlewareInterface;
use think\exception\HttpException;

final class MerchantPermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next)
    {
        $permission = $request->rule()?->getOption('merchant_permission');
        if (!is_string($permission) || !in_array($permission, MerchantPermissions::ALL, true)) {
            throw new HttpException(503, '服务暂不可用', null, [], 503);
        }
        $request->merchantContext()->require($permission);
        // Keep authentication and permission denials ahead of the optional business-request quota.
        return app(MerchantRequestQuotaMiddleware::class)->handle($request, $next);
    }
}
