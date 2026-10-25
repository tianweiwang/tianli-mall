<?php
declare(strict_types=1);
namespace app\adminapi\middleware;

use app\Request;
use app\dao\marketplace\assets\CustomerAgentLicenceAssetDao;
use app\services\marketplace\assets\CustomerAgentLicencePlatformAccessServices;
use crmeb\interfaces\MiddlewareInterface;

/** Retain the actual current agent-application-list permission, not a new login-only exception. */
final class AgentLicenceReadPermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request,\Closure $next)
    {
        $id=CustomerAgentLicenceAssetDao::integer($request->route('apply_id',null,null),1,2147483647);
        app()->make(CustomerAgentLicencePlatformAccessServices::class)->authorizeRequest($request,$id);
        return $next($request);
    }
}
