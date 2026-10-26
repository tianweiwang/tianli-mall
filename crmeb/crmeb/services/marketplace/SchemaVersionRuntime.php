<?php
declare(strict_types=1);

namespace crmeb\services\marketplace;

use crmeb\compat\think\Db as CompatDb;
use crmeb\exceptions\MarketplaceSchemaException as Failure;
use PDO;
use think\App;
use Throwable;

final class SchemaVersionRuntime
{
    public static function assertApp(App $app): array
    {
        $manifest = SchemaVersionGate::manifest($app->getRootPath() . 'marketplace-release.json');
        if (self::offlineMetadata($app)) { return ['offline_metadata_only' => true]; }
        [$pdo, $prefix] = self::primary($app);
        return SchemaVersionGate::assertCompatible($pdo, $prefix, $manifest);
    }

    /** Standalone checks load the official Env/config, never initialize or boot the application. */
    public static function standalone(string $applicationRoot): array
    {
        try {
            $real = realpath($applicationRoot);
            if (!is_string($real) || !is_dir($real) || !is_file($real . '/config/database.php')) {
                throw new Failure(Failure::IDENTITY);
            }
            $app = new App($real);
            $app->loadEnv();
            $app->config->load($app->getConfigPath() . 'database.php', 'database');
            $database = $app->config->get('database');
            $default = is_array($database) ? ($database['default'] ?? null) : null;
            if (!is_string($default) || $default === ''
                || !is_array($database['connections'][$default] ?? null)) { throw new Failure(Failure::UNAVAILABLE); }
            // Only this fresh standalone App's private configuration copy changes.
            // The official CONNECT listener includes a DSN and requires the full Log config.
            // A standalone read-only check must not activate that listener or its dependencies.
            $database['connections'][$default]['trigger_sql'] = false;
            $app->config->set($database, 'database');
            // Same binding as AppService; the framework Db factory would eagerly open cache.
            $app->bind(\think\Db::class, CompatDb::class);
            return self::primary($app);
        } catch (Failure $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new Failure(Failure::UNAVAILABLE);
        }
    }

    private static function primary(App $app): array
    {
        try {
            $connection = $app->make(\think\Db::class)->connect();
            $prefix = $connection->getConfig('prefix');
            if (!is_string($prefix)) { throw new Failure(Failure::INCOMPATIBLE); }
            SchemaVersionGate::table($prefix);
            // Official query(master=true) selects/initializes the actual master handle.
            $probe = $connection->query('SELECT 1 AS marketplace_schema_probe', [], true);
            if (count($probe) !== 1 || (string) ($probe[0]['marketplace_schema_probe'] ?? '') !== '1') {
                throw new Failure(Failure::UNAVAILABLE);
            }
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO) { throw new Failure(Failure::UNAVAILABLE); }
            return [$pdo, $prefix];
        } catch (Failure $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new Failure(Failure::UNAVAILABLE);
        }
    }

    private static function offlineMetadata(App $app): bool
    {
        if (PHP_SAPI !== 'cli' || !$app->runningInConsole()) { return false; }
        $arguments = $_SERVER['argv'] ?? null;
        $rootThink = realpath($app->getRootPath() . 'think');
        $included = get_included_files();
        if (!is_string($rootThink) || !is_array($arguments) || array_keys($arguments) !== [0, 1]
            || ($_SERVER['argc'] ?? null) !== 2 || !is_string($arguments[0]) || !is_string($arguments[1])
            || realpath($arguments[0]) !== $rootThink
            || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== $rootThink
            || !isset($included[0]) || realpath($included[0]) !== $rootThink) { return false; }
        $official = [
            'service:discover' => \think\console\command\ServiceDiscover::class,
            'vendor:publish' => \think\console\command\VendorPublish::class,
        ];
        $name = $arguments[1];
        if (!isset($official[$name])) { return false; }
        if ($app->getAlias('console') !== \think\Console::class
            || $app->getAlias(\think\Console::class) !== \think\Console::class) {
            throw new Failure(Failure::IDENTITY);
        }
        $expectedClass = $official[$name];
        $expectedFile = $app->getRootPath() . 'vendor/topthink/framework/src/think/console/command/'
            . ($name === 'service:discover' ? 'ServiceDiscover.php' : 'VendorPublish.php');
        $consoleFile = $app->getRootPath() . 'vendor/topthink/framework/src/think/Console.php';
        // Container invokes this only after the Console constructor has loaded all command
        // overrides and run all starting callbacks. Never recursively resolve Console here.
        $app->resolving(\think\Console::class, static function ($console, $container) use ($app, $name, $expectedClass, $expectedFile, $consoleFile): void {
            try {
                if ($container !== $app || get_class($console) !== \think\Console::class) {
                    throw new Failure(Failure::IDENTITY);
                }
                $consoleOrigin = (new \ReflectionClass(\think\Console::class))->getFileName();
                if (!is_string($consoleOrigin) || realpath($consoleFile) === false
                    || realpath($consoleOrigin) !== realpath($consoleFile)) { throw new Failure(Failure::IDENTITY); }
                $property = new \ReflectionProperty(\think\Console::class, 'commands');
                $property->setAccessible(true);
                $commands = $property->getValue($console);
                if (!is_array($commands) || ($commands[$name] ?? null) !== $expectedClass) {
                    throw new Failure(Failure::IDENTITY);
                }
                // Read the class definition's origin; do not instantiate a substituted command.
                $reflection = new \ReflectionClass($expectedClass);
                $filename = $reflection->getFileName();
                $canonical = realpath($expectedFile);
                if (!is_string($filename) || !is_string($canonical)
                    || realpath($filename) !== $canonical
                    || !$reflection->isSubclassOf(\think\console\Command::class)) {
                    throw new Failure(Failure::IDENTITY);
                }
            } catch (Throwable $error) {
                throw new Failure(Failure::IDENTITY);
            }
        });
        return true;
    }
}
