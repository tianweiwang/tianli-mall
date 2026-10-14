<?php
declare(strict_types=1);
namespace app\adminapi\middleware;
use app\Request;
use app\services\marketplace\product\LegacyProductAccessServices;

/** Reuse the existing connection boundary only for a finite server-marked writer. */
final class LegacyProductBoundaryMiddleware
{
    public function handle(Request $request,\Closure $next)
    {
        if(!LegacyProductAccessServices::isWriter($request))return $next($request);
        return app()->make(MarketplaceBoundaryMiddleware::class)->handle($request,$next);
    }
}
