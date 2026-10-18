<?php
declare(strict_types=1);

namespace app\merchantapi\middleware;

use app\Request;
use app\services\marketplace\EmployeeAuthServices;
use Closure;
use crmeb\exceptions\MerchantUnauthenticatedException;
use crmeb\interfaces\MiddlewareInterface;

final class MerchantAuthTokenMiddleware implements MiddlewareInterface
{
    public function __construct(private EmployeeAuthServices $services)
    {
    }

    /** The new application accepts session credentials only in this header. */
    public static function token(Request $request): string
    {
        $header = $request->header('Authorization');
        if (!is_string($header) || preg_match('/\ABearer ([^\s]+)\z/', $header, $matches) !== 1) {
            throw new MerchantUnauthenticatedException();
        }
        return $matches[1];
    }

    public function handle(Request $request, Closure $next)
    {
        $request->clearMerchantContext();
        $request->clearVerifiedTokenFingerprint();
        try {
            $token = self::token($request);
            $context = $this->services->authenticate($token);
            $request->setVerifiedTokenFingerprint(hash('sha256', $token));
            $request->setMerchantContext($context);
            return $next($request);
        } finally {
            $request->clearMerchantContext();
            $request->clearVerifiedTokenFingerprint();
        }
    }
}
