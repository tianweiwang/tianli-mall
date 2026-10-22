<?php
declare(strict_types=1);
namespace app\adminapi\middleware;

use app\Request;
use app\services\marketplace\order\MarketplaceOrderFormPlatformAccessServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\interfaces\MiddlewareInterface;

/** Sole original order-info grant for the two fixed private form read routes. */
final class OrderFormReadPermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request,\Closure $next)
    {
        $id=MarketplaceOrderInput::unsigned($request->route('root_id',null,null),'订单编号',2147483647);
        if($id<1)throw new AuthUnavailableException();
        app()->make(MarketplaceOrderFormPlatformAccessServices::class)->authorizeRequest($request,$id);
        return $next($request);
    }
}
