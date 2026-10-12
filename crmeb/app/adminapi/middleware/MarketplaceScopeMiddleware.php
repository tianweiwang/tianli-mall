<?php
declare(strict_types=1);
namespace app\adminapi\middleware;

use app\Request;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\PlatformAccessServices;
use crmeb\exceptions\AuthUnavailableException;

final class MarketplaceScopeMiddleware
{
    public function __construct(private PlatformAccessServices $access) {}

    public function handle(Request $request, \Closure $next)
    {
        $permission = $request->rule()->getOption('marketplace_permission');
        if (!is_string($permission) || !isset(PlatformAccessServices::ACTIONS[$permission])) throw new AuthUnavailableException();
        $merId = PlatformAccessServices::ACTIONS[$permission][2]
            ? MerchantManagementInput::integer($request->route('id', null, null), 1, 4294967295, true) : null;
        $request->setPlatformScope($this->access->scopeForRequest($request, $permission, $merId));
        return $next($request);
    }
}
