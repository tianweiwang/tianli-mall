<?php
declare(strict_types=1);
namespace app\adminapi\middleware;

use app\Request;
use app\services\marketplace\PlatformAccessServices;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\connector\Mysql;
use think\facade\Db;

/** Configure this request before its administrator credential reader opens PDO. */
final class MarketplaceBoundaryMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        $request->clearPlatformScope();
        $original = null; $configured = null; $configurationChanged = false;
        try {
            if (!PlatformAccessServices::isMarketplace($request)) throw new AuthUnavailableException();
            $original = app()->config->get('database');
            if (!is_array($original) || ($original['default'] ?? '') !== 'mysql'
                || !is_array($original['connections']['mysql'] ?? null)) throw new AuthUnavailableException();
            // Creating a cached Connection is lazy; getPdo observes without opening it.
            $existing = Db::connect();
            if (!$existing instanceof Mysql) throw new AuthUnavailableException();
            $pdo = $existing->getPdo();
            if ($pdo instanceof PDO && $pdo->inTransaction()) throw new AuthUnavailableException();
            $database = $original;
            $database['connections']['mysql']['debug'] = false;
            $database['connections']['mysql']['trigger_sql'] = false;
            $database['connections']['mysql']['charset'] = 'utf8mb4';
            $database['connections']['mysql']['break_reconnect'] = false;
            // think\Db retains this actual Config object, not an array copy.
            app()->config->set($database, 'database');
            $configurationChanged = true;
            $configured = Db::connect(null, true);
            if (!$configured instanceof Mysql || $configured->getConfig('debug') !== false
                || $configured->getConfig('trigger_sql') !== false || $configured->getConfig('charset') !== 'utf8mb4'
                || $configured->getConfig('break_reconnect') !== false || !$configured->connect() instanceof PDO) {
                throw new AuthUnavailableException();
            }
            return $next($request);
        } catch (\think\exception\HttpException|\crmeb\exceptions\AuthException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        } finally {
            $request->clearPlatformScope();
            if ($configurationChanged && is_array($original)) {
                try {
                    app()->config->set($original, 'database');
                    $pdo = $configured?->getPdo();
                    if ($pdo instanceof PDO && $pdo->inTransaction()) throw new AuthUnavailableException();
                    // Restore a lazy default for any later independent request on this app.
                    Db::connect(null, true);
                } catch (\Throwable $cleanupError) {
                    throw new AuthUnavailableException();
                }
            }
        }
    }
}
