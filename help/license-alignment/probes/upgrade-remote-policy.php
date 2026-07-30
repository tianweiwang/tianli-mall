<?php
declare(strict_types=1);

const UPGRADE_POLICY_SENTINEL = '__UPGRADE_REMOTE_POLICY__|';

$state = [
    'probe' => 'upgrade-remote-policy',
    'status' => 'fail',
    'checks' => [],
    'diagnostics' => [],
    'details' => [],
    'throwable' => null,
];

function getLang($message, array $replace = []): string
{
    if ($replace === []) {
        return (string)$message;
    }
    $keys = array_map(static fn(string $key): string => '{:' . $key . '}', array_keys($replace));
    return str_replace($keys, array_values($replace), (string)$message);
}

function probeCheck(array &$state, string $name, bool $passed): void
{
    $state['checks'][$name] = $passed;
}

function probeThrowable(Throwable $throwable): array
{
    return [
        'class' => get_class($throwable),
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
    ];
}

function probeRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function probeAutoloadPath(): string
{
    $candidates = [
        '/var/www/vendor/autoload.php',
        dirname(__DIR__, 3) . '/crmeb/vendor/autoload.php',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Unable to locate CRMEB Composer autoload.php');
}

final class ProbeCache
{
    public int $reads = 0;

    public function get(string $name, $default = null)
    {
        $this->reads++;
        throw new RuntimeException('Unexpected cache read: ' . $name);
    }
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$state): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    $state['diagnostics'][] = [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ];
    return true;
}, E_ALL);

$temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'upgrade-policy-' . bin2hex(random_bytes(6));

try {
    if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
        throw new RuntimeException('Unable to create temporary application root');
    }
    mkdir($temporaryRoot . DIRECTORY_SEPARATOR . 'runtime', 0700, true);

    $autoload = probeAutoloadPath();
    require $autoload;
    if (!function_exists('app')) {
        require_once dirname($autoload) . '/topthink/framework/src/helper.php';
    }
    $distributionRoot = dirname($autoload, 2);
    require_once $distributionRoot . '/upgrade/VersionManager.php';

    $app = new think\App($temporaryRoot);
    $config = new think\Config();
    $config->set([
        'remote_enabled' => false,
        'remote' => [
            'login_url' => 'https://upgrade.example.test/api/login',
            'upgrade_url' => 'http://upgrade.example.test/api/list',
        ],
        'versions' => [],
        'min_version' => [],
    ], 'upgrade');
    $config->set([
        'default' => 'mysql',
        'connections' => [
            'mysql' => ['prefix' => 'eb_'],
        ],
    ], 'database');
    $app->instance('config', $config);
    $cache = new ProbeCache();
    $app->instance('cache', $cache);

    probeCheck($state, 'autoload_loaded', class_exists(app\services\system\UpgradeServices::class));
    probeCheck($state, 'temporary_root_has_no_version_file', !file_exists($temporaryRoot . DIRECTORY_SEPARATOR . '.version'));

    $service = null;
    try {
        $service = new app\services\system\UpgradeServices(new app\dao\system\upgrade\UpgradeLogDao());
        probeCheck($state, 'constructor_without_version_file', true);
    } catch (Throwable $throwable) {
        probeCheck($state, 'constructor_without_version_file', false);
        $state['details']['constructor'] = probeThrowable($throwable);
    }

    if ($service !== null) {
        probeCheck(
            $state,
            'capability_default_false',
            method_exists($service, 'isRemoteEnabled') && $service->isRemoteEnabled() === false
        );

        try {
            $service->getUpgradeList();
            probeCheck($state, 'remote_call_rejected', false);
        } catch (Throwable $throwable) {
            probeCheck($state, 'remote_call_rejected', $throwable->getMessage() === '官方在线升级服务未启用');
            $state['details']['remote_call'] = probeThrowable($throwable);
        }

        $httpsAccepted = false;
        $httpRejected = false;
        if (method_exists($service, 'getRemoteUrl')) {
            $method = new ReflectionMethod($service, 'getRemoteUrl');
            $method->setAccessible(true);
            try {
                $httpsAccepted = $method->invoke($service, 'login_url') === 'https://upgrade.example.test/api/login';
            } catch (Throwable $throwable) {
                $state['details']['https_url'] = probeThrowable($throwable);
            }
            try {
                $method->invoke($service, 'upgrade_url');
            } catch (Throwable $throwable) {
                $httpRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
                $state['details']['http_url'] = probeThrowable($throwable);
            }
        }
        probeCheck($state, 'https_remote_url_accepted', $httpsAccepted);
        probeCheck($state, 'http_remote_url_rejected', $httpRejected);

        try {
            $overview = $service->getCrossVersionUpgradeOverview();
            probeCheck($state, 'local_cross_version_overview_available', is_array($overview));
        } catch (Throwable $throwable) {
            probeCheck($state, 'local_cross_version_overview_available', false);
            $state['details']['local_overview'] = probeThrowable($throwable);
        }
    } else {
        foreach ([
            'capability_default_false',
            'remote_call_rejected',
            'https_remote_url_accepted',
            'http_remote_url_rejected',
            'local_cross_version_overview_available',
        ] as $name) {
            probeCheck($state, $name, false);
        }
    }

    probeCheck($state, 'upgrade_auth_token_reads_zero', $cache->reads === 0);
} catch (Throwable $throwable) {
    $state['throwable'] = probeThrowable($throwable);
} finally {
    probeRemoveTree($temporaryRoot);
    restore_error_handler();
}

$passed = $state['throwable'] === null && $state['diagnostics'] === [] && $state['checks'] !== [];
foreach ($state['checks'] as $check) {
    if ($check !== true) {
        $passed = false;
        break;
    }
}
$state['status'] = $passed ? 'pass' : 'fail';

$json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    $json = '{"probe":"upgrade-remote-policy","status":"fail","throwable":{"message":"JSON encoding failed"}}';
    $passed = false;
}
fwrite(STDOUT, UPGRADE_POLICY_SENTINEL . $json . PHP_EOL);
exit($passed ? 0 : 1);
