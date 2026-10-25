<?php
declare(strict_types=1);
namespace app\adminapi\middleware;

use app\Request;
use app\dao\marketplace\assets\CustomerLegacyRefundAssetDao as Assets;
use app\services\marketplace\assets\CustomerLegacyRefundPlatformAccessServices;
use crmeb\interfaces\MiddlewareInterface;

/** Fixed list/detail read routes inherit the corresponding existing original action grant. */
final class LegacyRefundReadPermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request,\Closure $next)
    {
        $id=Assets::integer($request->route('refund_id',null,''),1,2147483647);
        app()->make(CustomerLegacyRefundPlatformAccessServices::class)->authorizeRequest($request,$id);
        return $next($request);
    }
}
