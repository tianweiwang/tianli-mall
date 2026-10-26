<?php
declare(strict_types=1);

function preview_config(): array
{
    $config = json_decode(file_get_contents('/run/preview-app/config.json'), true, 512, JSON_THROW_ON_ERROR);
    if (is_file('/run/preview-bootstrap/config.json')) {
        $private = json_decode(file_get_contents('/run/preview-bootstrap/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $config['database']['migrator'] = $private['migrator'];
        $config['credentials'] = $private['credentials'];
    }
    if (getenv('PREVIEW_ISOLATED') !== '1' || $config['project'] !== getenv('PREVIEW_PROJECT')
        || $config['run_id'] !== getenv('PREVIEW_RUN_ID') || $config['database']['host'] !== 'preview-mysql'
        || $config['database']['name'] !== 'mall_preview'
        || !preg_match('/\Amall-preview-[a-z0-9-]+\z/D', $config['project'])) {
        throw new RuntimeException('Preview identity mismatch');
    }
    return $config;
}

function preview_pdo(bool $migrator = false): PDO
{
    $config = preview_config();
    $account = $config['database'][$migrator ? 'migrator' : 'app'] ?? null;
    if (!is_array($account)) throw new RuntimeException('Preview database role unavailable');
    return new PDO('mysql:host=preview-mysql;port=3306;dbname=mall_preview;charset=utf8mb4',
        $account['username'], $account['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function preview_assert_isolated(): void
{
    $config = preview_config();
    $db = preview_pdo();
    if ($db->query('SELECT run_id FROM audit_sentinel')->fetchColumn() !== $config['run_id']) {
        throw new RuntimeException('Preview database sentinel mismatch');
    }
    $redis = new Redis();
    $redis->connect('preview-redis', 6379, 3);
    $redis->auth($config['redis_password']);
    if ($redis->get('preview:sentinel') !== $config['run_id']) {
        throw new RuntimeException('Preview Redis sentinel mismatch');
    }
    $redis->close();
}

function preview_app(bool $migrator = false): \think\App
{
    preview_assert_isolated();
    $config = preview_config();
    $role = $config['database'][$migrator ? 'migrator' : 'app'];
    $environment = [
        'APP_ENV' => 'testing', 'PHP_APP_DEBUG' => 'false', 'PHP_DATABASE_DEBUG' => 'false',
        'PHP_APP_APP_KEY' => $config['signing_key'],
        'PHP_DATABASE_HOSTNAME' => 'preview-mysql', 'PHP_DATABASE_HOSTPORT' => '3306',
        'PHP_DATABASE_DATABASE' => 'mall_preview', 'PHP_DATABASE_PREFIX' => 'eb_',
        'PHP_DATABASE_USERNAME' => $role['username'], 'PHP_DATABASE_PASSWORD' => $role['password'],
        'PHP_CACHE_DRIVER' => 'redis', 'PHP_REDIS_REDIS_HOSTNAME' => 'preview-redis',
        'PHP_REDIS_PORT' => '6379', 'PHP_REDIS_REDIS_PASSWORD' => $config['redis_password'],
        'PHP_AUTH_SESSION_EPOCH' => $config['auth']['session_epoch'],
        'PHP_AUTH_STAMP_KEY' => $config['auth']['stamp_key'], 'PHP_AUTH_ISSUER' => $config['auth']['issuer'],
        'PHP_MARKETPLACE_ENABLED' => 'true', 'PHP_MARKETPLACE_TRADE_ENABLED' => 'true',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED' => 'true',
        'PHP_MARKETPLACE_AFTERSALE_ENABLED' => 'true',
        'PHP_MARKETPLACE_SELF_OPERATED_MER_ID' => (string)($config['self_operated_mer_id'] ?? ''),
        'PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT' => '/private/customer-form',
        'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT' => '/private/aftersale',
        'PHP_MARKETPLACE_CUSTOMER_REFUND_PRIVATE_ROOT' => '/private/refund',
        'PHP_MARKETPLACE_CUSTOMER_LICENCE_PRIVATE_ROOT' => '/private/licence',
        'PHP_MARKETPLACE_CUSTOMER_WITHDRAWAL_QR_PRIVATE_ROOT' => '/private/withdrawal',
        'PHP_MARKETPLACE_PLATFORM_CHAT_PRIVATE_ROOT' => '/private/chat',
        'PHP_UPGRADE_REMOTE_ENABLED' => 'false',
    ];
    foreach ($environment as $name => $value) putenv($name . '=' . $value);
    require_once '/workspace/crmeb/vendor/autoload.php';
    $app = new \think\App('/workspace/crmeb/');
    $app->setRuntimePath('/workspace/crmeb/runtime/');
    $app->initialize();
    $workerman = $app->config->get('workerman');
    $workerman['channel']['ip'] = getenv('PREVIEW_EVENTS_BIND') === '1' ? '0.0.0.0' : 'preview-events';
    $app->config->set($workerman, 'workerman');
    return $app;
}

function preview_error(Throwable $error): void
{
    // No request bodies, secrets, SQL text or exception messages in public logs.
    $diagnostic = ['error' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
    if ($error instanceof PDOException) {
        $diagnostic['sqlstate'] = $error->errorInfo[0] ?? null;
        $diagnostic['vendor_code'] = $error->errorInfo[1] ?? null;
        if (preg_match("/(?:Field|Unknown column) '([a-zA-Z0-9_]+)'/", $error->getMessage(), $field)) {
            $diagnostic['field'] = $field[1];
        }
    }
    if (preg_match('/\APreview seed: [a-z0-9 _-]+\z/D', $error->getMessage())) {
        $diagnostic['reason'] = $error->getMessage();
    }
    $diagnostic['calls'] = array_map(static fn(array $frame): array => [
        'file' => basename($frame['file'] ?? ''), 'line' => $frame['line'] ?? 0,
        'function' => $frame['function'] ?? ''], array_slice($error->getTrace(), 0, 5));
    file_put_contents('php://stderr', json_encode($diagnostic, JSON_THROW_ON_ERROR) . "\n");
}
