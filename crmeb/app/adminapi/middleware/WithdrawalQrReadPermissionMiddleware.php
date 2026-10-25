<?php
declare(strict_types=1);
namespace app\adminapi\middleware;
use app\Request;
use app\dao\marketplace\assets\CustomerWithdrawalQrAssetDao as Assets;
use app\services\marketplace\assets\CustomerWithdrawalQrPlatformAccessServices;
use crmeb\interfaces\MiddlewareInterface;
/** No menu, role or approve/edit grant is added for these fixed material leaves. */
final class WithdrawalQrReadPermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request,\Closure $next)
    {
        $id=Assets::integer($request->route('extract_id',null,''),1,4294967295);
        app()->make(CustomerWithdrawalQrPlatformAccessServices::class)->authorizeRequest($request,$id);
        return $next($request);
    }
}
