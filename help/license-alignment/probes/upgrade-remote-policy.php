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
    public int $tokenReads = 0;
    private array $values = [];

    public function get(string $name, $default = null)
    {
        if ($name === 'upgrade_auth_token') {
            $this->tokenReads++;
        }
        return $this->values[$name] ?? $default;
    }

    public function set(string $name, $value, $expire = null): bool
    {
        $this->values[$name] = $value;
        return true;
    }

    public function tag($name): self
    {
        return $this;
    }
}

final class ProbeLog
{
    public array $warnings = [];
    public array $entries = [];

    public function warning($message, array $context = []): void
    {
        $this->warnings[] = compact('message', 'context');
    }

    public function __call(string $name, array $arguments)
    {
        $this->entries[] = ['level' => $name, 'arguments' => $arguments];
        return $this;
    }
}

final class ProbeHttpService
{
    public static int $requests = 0;

    public static function getRequest(...$arguments)
    {
        self::$requests++;
        throw new RuntimeException('Unexpected HTTP GET request');
    }

    public static function postRequest(...$arguments)
    {
        self::$requests++;
        throw new RuntimeException('Unexpected HTTP POST request');
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
    if (!class_exists(crmeb\services\HttpService::class, false)) {
        class_alias(ProbeHttpService::class, crmeb\services\HttpService::class);
    }
    if (!function_exists('app')) {
        require_once dirname($autoload) . '/topthink/framework/src/helper.php';
    }
    $distributionRoot = dirname($autoload, 2);
    require_once $distributionRoot . '/upgrade/VersionManager.php';
    if (!defined('DS')) {
        define('DS', DIRECTORY_SEPARATOR);
    }

    $app = new think\App($temporaryRoot);
    $config = new think\Config();
    $config->set([
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
    $log = new ProbeLog();
    $app->instance('log', $log);
    $app->instance('json', new crmeb\utils\Json());

    probeCheck($state, 'autoload_loaded', class_exists(app\services\system\UpgradeServices::class));
    probeCheck($state, 'temporary_root_has_no_version_file', !file_exists($temporaryRoot . DIRECTORY_SEPARATOR . '.version'));

    $dao = new class extends app\dao\system\upgrade\UpgradeLogDao {
        public function getList(array $field, int $page = 0, int $limit = 0): array
        {
            return [];
        }

        public function getOne(array $where, ?string $field = '*', array $with = [])
        {
            return [
                'first_version' => '6',
                'second_version' => '0',
                'third_version' => '0',
                'fourth_version' => '0',
                'package_link' => '',
                'data_link' => '',
            ];
        }
    };

    $service = null;
    try {
        $service = new app\services\system\UpgradeServices($dao);
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

        $remoteCalls = [
            'auth' => static fn() => $service->getAuth(),
            'sign' => static fn() => $service->getSign(1),
            'list' => static fn() => $service->getUpgradeList(),
            'upgradeable_list' => static fn() => $service->getUpgradeableList(),
            'agreement' => static fn() => $service->getAgreement(),
            'status' => static fn() => $service->getUpgradeStatus(),
            'package' => static fn() => $service->packageDownload('probe-package'),
            'package_transfer' => static fn() => $service->download(
                'probe-package',
                'https://upgrade.example.test/package.zip',
                $temporaryRoot,
                'package.zip'
            ),
            'log' => static fn() => $service->sendUpgradeLog('probe-token'),
        ];
        $remoteFailures = [];
        foreach ($remoteCalls as $name => $call) {
            try {
                $call();
                $remoteFailures[$name] = ['message' => 'call unexpectedly succeeded'];
            } catch (Throwable $throwable) {
                if ($throwable->getMessage() !== '官方在线升级服务未启用' || $throwable->getCode() !== 400) {
                    $remoteFailures[$name] = probeThrowable($throwable);
                }
            }
        }
        probeCheck($state, 'remote_calls_stable_400', $remoteFailures === []);
        $state['details']['remote_failures'] = $remoteFailures;

        try {
            $controller = new app\adminapi\controller\UpgradeController($service);
            $response = $controller->capability();
            $payload = $response->getData();
            probeCheck(
                $state,
                'capability_response_200_boolean',
                ($payload['status'] ?? null) === 200
                && ($payload['data']['remote_enabled'] ?? null) === false
                && is_bool($payload['data']['remote_enabled'] ?? null)
            );

            try {
                $controller->packageDownload('probe-package');
                probeCheck($state, 'package_controller_stable_400', false);
            } catch (Throwable $throwable) {
                probeCheck(
                    $state,
                    'package_controller_stable_400',
                    $throwable->getMessage() === '官方在线升级服务未启用' && $throwable->getCode() === 400
                );
                $state['details']['package_controller'] = probeThrowable($throwable);
            }
        } catch (Throwable $throwable) {
            probeCheck($state, 'capability_response_200_boolean', false);
            probeCheck($state, 'package_controller_stable_400', false);
            $state['details']['capability_response'] = probeThrowable($throwable);
        }
        $routeSource = file_get_contents($distributionRoot . '/app/adminapi/route/system.php');
        probeCheck(
            $state,
            'capability_route_registered',
            strpos((string)$routeSource, "Route::get('upgrade/capability', 'UpgradeController/capability')") !== false
        );

        $httpsAccepted = false;
        $httpRejected = false;
        $missingRejected = false;
        $disabledTokenReads = $cache->tokenReads;
        $config->set(['remote_enabled' => true], 'upgrade');
        if (method_exists($service, 'getRemoteUrl')) {
            $method = new ReflectionMethod($service, 'getRemoteUrl');
            $method->setAccessible(true);
            try {
                $httpsAccepted = $method->invoke($service, 'login_url') === 'https://upgrade.example.test/api/login';
            } catch (Throwable $throwable) {
                $state['details']['https_url'] = probeThrowable($throwable);
            }
            $warningCountBeforeHttp = count($log->warnings);
            try {
                $method->invoke($service, 'upgrade_url');
            } catch (Throwable $throwable) {
                $httpRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
                $state['details']['http_url'] = probeThrowable($throwable);
            }
            probeCheck($state, 'http_remote_url_single_warning', count($log->warnings) - $warningCountBeforeHttp === 1);
            $warningCountBeforeMissing = count($log->warnings);
            try {
                $method->invoke($service, 'missing_url');
            } catch (Throwable $throwable) {
                $missingRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
                $state['details']['missing_url'] = probeThrowable($throwable);
            }
            probeCheck($state, 'missing_remote_url_single_warning', count($log->warnings) - $warningCountBeforeMissing === 1);
        }
        probeCheck($state, 'https_remote_url_accepted', $httpsAccepted);
        probeCheck($state, 'http_remote_url_rejected', $httpRejected);
        probeCheck($state, 'missing_remote_url_rejected', $missingRejected);

        $packageListRejected = false;
        $warningCountBeforePackageList = count($log->warnings);
        if (method_exists($service, 'validatePackageDownloadUrls')) {
            $method = new ReflectionMethod($service, 'validatePackageDownloadUrls');
            $method->setAccessible(true);
            try {
                $method->invoke($service, [[
                    'client_package_link' => 'http://upgrade.example.test/client.zip',
                ]]);
            } catch (Throwable $throwable) {
                $packageListRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
                $state['details']['package_list_url'] = probeThrowable($throwable);
            }
        }
        probeCheck($state, 'package_list_http_rejected', $packageListRejected);
        probeCheck($state, 'package_list_http_single_warning', count($log->warnings) - $warningCountBeforePackageList === 1);

        $queuePackageRejected = false;
        $warningCountBeforeQueuePackage = count($log->warnings);
        $method = new ReflectionMethod($service, 'downloadFile');
        $method->setAccessible(true);
        try {
            $method->invoke($service, 'http://upgrade.example.test/queue.zip', 'probe-queue');
        } catch (Throwable $throwable) {
            $queuePackageRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
            $state['details']['queue_package_url'] = probeThrowable($throwable);
        }
        probeCheck($state, 'queue_package_http_rejected', $queuePackageRejected);
        probeCheck($state, 'queue_package_http_single_warning', count($log->warnings) - $warningCountBeforeQueuePackage === 1);

        $workerPackageRejected = false;
        $warningCountBeforeWorkerPackage = count($log->warnings);
        try {
            $service->download('probe-worker', 'http://upgrade.example.test/worker.zip', $temporaryRoot, 'worker.zip');
        } catch (Throwable $throwable) {
            $workerPackageRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
            $state['details']['worker_package_url'] = probeThrowable($throwable);
        }
        probeCheck($state, 'worker_package_http_rejected', $workerPackageRejected);
        probeCheck($state, 'worker_package_http_single_warning', count($log->warnings) - $warningCountBeforeWorkerPackage === 1);
        probeCheck($state, 'worker_package_file_not_created', !file_exists($temporaryRoot . DIRECTORY_SEPARATOR . 'worker.zip'));

        file_put_contents(
            $temporaryRoot . DIRECTORY_SEPARATOR . '.version',
            implode(PHP_EOL, [
                'version=CRMEB-BZ v6.0.0',
                'version_code=600',
                'platform=CRMEB',
                'app_id=probe-app',
                'app_key=probe-key',
                '',
            ])
        );
        $flowUrlRejected = false;
        $warningCountBeforeFlow = count($log->warnings);
        $requestCountBeforeFlow = ProbeHttpService::$requests;
        $tokenReadsBeforeFlow = $cache->tokenReads;
        try {
            $service->getUpgradeList();
        } catch (Throwable $throwable) {
            $flowUrlRejected = $throwable->getMessage() === '官方在线升级服务地址必须使用 HTTPS';
            $state['details']['flow_url'] = probeThrowable($throwable);
        }
        probeCheck($state, 'invalid_flow_url_rejected', $flowUrlRejected);
        probeCheck($state, 'invalid_flow_url_single_warning', count($log->warnings) - $warningCountBeforeFlow === 1);
        probeCheck($state, 'invalid_flow_url_requests_zero', ProbeHttpService::$requests - $requestCountBeforeFlow === 0);
        probeCheck($state, 'invalid_flow_url_token_reads_zero', $cache->tokenReads - $tokenReadsBeforeFlow === 0);
        $config->set(['remote_enabled' => false], 'upgrade');
        $app->instance('log', new class($app) extends think\Log {
            public function warning($message, array $context = []): void
            {
            }

            public function error($message, array $context = []): void
            {
            }

            public function notice($message, array $context = []): void
            {
            }
        });
        $config->set([
            'default' => 'file',
            'stores' => [
                'file' => [
                    'type' => 'File',
                    'path' => $temporaryRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache',
                    'prefix' => '',
                    'expire' => 0,
                    'tag_prefix' => 'tag:',
                    'serialize' => [],
                ],
            ],
        ], 'cache');
        $localCache = new class($app) extends think\Cache {
            public int $tokenReads = 0;

            public function get($key, $default = null)
            {
                if ($key === 'upgrade_auth_token') {
                    $this->tokenReads++;
                }
                return parent::get($key, $default);
            }
        };
        $app->instance('cache', $localCache);
        $app->instance(crmeb\services\FileService::class, new class extends crmeb\services\FileService {
            public function handleDir(string $old_path, string $new_path, string $type = 'copy', bool $overWrite = false, array $ignore = [])
            {
                if (!is_dir($new_path)) {
                    mkdir($new_path, 0700, true);
                }
                return true;
            }

            public function addZip($source, $destination, $folder = '')
            {
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0700, true);
                }
                return file_put_contents($destination, 'probe archive') !== false;
            }
        });
        $app->instance(app\services\system\SystemDatabackupServices::class, new class($temporaryRoot) extends app\services\system\SystemDatabackupServices {
            public function __construct(string $root)
            {
                $this->dbBackup = new class($root) {
                    private string $root;
                    private array $file = [];

                    public function __construct(string $root)
                    {
                        $this->root = $root;
                    }

                    public function setFile($file = null): self
                    {
                        $this->file = [
                            'filename' => (string)($file['name'] ?? 'probe') . '-1',
                            'filepath' => $this->root . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR,
                        ];
                        return $this;
                    }

                    public function getFile($type = '', $time = 0): array
                    {
                        return $this->file;
                    }
                };
            }

            public function getDataList()
            {
                return ['list' => [['name' => 'probe_table']], 'count' => 1];
            }

            public function backup(string $tables)
            {
                $file = $this->dbBackup->getFile();
                if (!is_dir($file['filepath'])) {
                    mkdir($file['filepath'], 0700, true);
                }
                file_put_contents($file['filepath'] . $file['filename'] . '.gz', 'probe database archive');
                return '';
            }
        });

        foreach (['app', 'config', 'crmeb'] as $directory) {
            $directoryPath = $temporaryRoot . DIRECTORY_SEPARATOR . $directory;
            mkdir($directoryPath, 0700, true);
            file_put_contents($directoryPath . DIRECTORY_SEPARATOR . 'placeholder.txt', $directory);
        }

        $localCalls = [
            'cross_version_overview' => static fn() => $service->getCrossVersionUpgradeOverview(),
            'pending_versions' => static fn() => $service->getPendingVersions(),
            'sql_preview' => static fn() => $service->getAllPendingUpgradeSql(),
            'database_backup' => static fn() => $service->databaseBackup('probe-local'),
            'project_backup' => static fn() => $service->projectBackup('probe-local'),
            'single_step_execution' => static fn() => $service->executeCrossVersionUpgrade(0),
            'all_execution' => static fn() => $service->executeAllCrossVersionUpgrade(),
            'backup_status' => static fn() => $service->getBackupStatus(),
            'upgrade_progress' => static fn() => $service->getUpgradeProgressDetail(),
            'rollback_versions' => static fn() => $service->getRollbackVersions(),
            'rollback_execution' => static fn() => $service->rollbackToVersion(1),
        ];
        $localFailures = [];
        $localOutcomes = [];
        foreach ($localCalls as $name => $call) {
            try {
                $localOutcomes[$name] = ['result_type' => gettype($call())];
            } catch (Throwable $throwable) {
                $localOutcomes[$name] = probeThrowable($throwable);
                if ($throwable->getMessage() === '官方在线升级服务未启用') {
                    $localFailures[$name] = probeThrowable($throwable);
                }
            }
        }
        probeCheck($state, 'local_operations_available', $localFailures === []);
        $state['details']['local_failures'] = $localFailures;
        $state['details']['local_outcomes'] = $localOutcomes;
        probeCheck($state, 'local_upgrade_auth_token_reads_zero', $localCache->tokenReads === 0);
    } else {
        foreach ([
            'capability_default_false',
            'remote_calls_stable_400',
            'capability_response_200_boolean',
            'package_controller_stable_400',
            'capability_route_registered',
            'https_remote_url_accepted',
            'http_remote_url_rejected',
            'http_remote_url_single_warning',
            'missing_remote_url_rejected',
            'missing_remote_url_single_warning',
            'package_list_http_rejected',
            'package_list_http_single_warning',
            'queue_package_http_rejected',
            'queue_package_http_single_warning',
            'worker_package_http_rejected',
            'worker_package_http_single_warning',
            'worker_package_file_not_created',
            'invalid_flow_url_rejected',
            'invalid_flow_url_single_warning',
            'invalid_flow_url_requests_zero',
            'invalid_flow_url_token_reads_zero',
            'local_operations_available',
            'local_upgrade_auth_token_reads_zero',
        ] as $name) {
            probeCheck($state, $name, false);
        }
    }

    probeCheck($state, 'upgrade_auth_token_reads_zero', ($disabledTokenReads ?? $cache->tokenReads) === 0);
    probeCheck($state, 'http_requests_zero', ProbeHttpService::$requests === 0);
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
