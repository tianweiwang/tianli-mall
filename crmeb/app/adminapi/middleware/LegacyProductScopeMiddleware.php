<?php
declare(strict_types=1);
namespace app\adminapi\middleware;
use app\Request;
use app\services\marketplace\product\LegacyProductAccessServices;

final class LegacyProductScopeMiddleware
{
    public function handle(Request $request,\Closure $next)
    {
        if(!LegacyProductAccessServices::isWriter($request))return $next($request);
        $request->setPlatformScope(app()->make(LegacyProductAccessServices::class)->scopeForRequest($request));
        return $next($request);
    }
}
