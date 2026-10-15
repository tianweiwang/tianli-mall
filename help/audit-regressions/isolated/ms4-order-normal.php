<?php
declare(strict_types=1);

require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/ms4-saved-order-normal.php';
require __DIR__ . '/lib/ms4-post-commit-normal.php';
require __DIR__ . '/lib/ms4-cancel-normal.php';
require __DIR__ . '/lib/ms4-merchant-read-normal.php';
require __DIR__ . '/lib/ms4-fulfillment-plan-normal.php';

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceOrderCreateServices;
use app\services\marketplace\order\MarketplaceOrderCustomForm;
use app\services\marketplace\order\MarketplaceOrderLockedQuote;
use app\services\order\StoreOrderComputedServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\order\StoreCartServices;
use app\services\user\UserInvoiceServices;
use app\services\user\UserServices;
use crmeb\services\CacheService;
use think\facade\Db;

const M4O_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4O_ROOTS = ['crmeb/app','crmeb/crmeb','crmeb/vendor','crmeb/config'];
const M4O_FILES = ['crmeb/composer.json','crmeb/composer.lock','help/audit-regressions/isolated/ms4-order-normal.php',
    'help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/lib/guard.php',
    'help/audit-regressions/isolated/lib/ms4-saved-order-normal.php',
    'help/audit-regressions/isolated/lib/ms4-post-commit-normal.php',
    'help/audit-regressions/isolated/lib/ms4-cancel-normal.php',
    'help/audit-regressions/isolated/lib/ms4-merchant-read-normal.php',
    'help/audit-regressions/isolated/lib/ms4-fulfillment-plan-normal.php'];
const M4O_FIXTURE_TABLES = ['eb_user','eb_marketplace_merchant','eb_store_product','eb_store_product_attr',
    'eb_store_product_attr_value','eb_store_cart','eb_user_address','eb_user_invoice','eb_system_config',
    'eb_system_user_level','eb_user_level','eb_member_right',
    'eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup'];
const M4O_FACT_TABLES = ['eb_store_order','eb_store_order_invoice','eb_store_order_cart_info',
    'eb_marketplace_order_idempotency','eb_marketplace_stock_movement','eb_store_product','eb_store_product_attr_value','eb_store_cart',
    'eb_user','eb_marketplace_merchant','eb_store_product_attr','eb_user_address','eb_user_invoice','eb_system_config',
    'eb_system_user_level','eb_user_level','eb_member_right','eb_store_order_status','eb_store_product_log','eb_system_timer',
    'eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup'];

function m4o_expect(bool $ok, string $code): void
{
    $GLOBALS['m4o_assertions'] = ($GLOBALS['m4o_assertions'] ?? 0) + 1;
    $phase = $GLOBALS['m4o_phase'] ?? 'source';
    $GLOBALS['m4o_phase_assertions'][$phase] = ($GLOBALS['m4o_phase_assertions'][$phase] ?? 0) + 1;
    if (!$ok) { $GLOBALS['m4o_failed_assertion'] = $code; throw new RuntimeException('NORMAL_ASSERTION'); }
}
function m4o_json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
function m4o_read(string $path): array
{
    m4o_expect(is_file($path) && !is_link($path) && filesize($path) <= 8 * 1024 * 1024, 'REGULAR_RECEIPT');
    $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    m4o_expect(is_array($value) && !array_is_list($value), 'RECEIPT_OBJECT'); return $value;
}
function m4o_new(string $path, array $value): void
{
    m4o_expect(!file_exists($path) && !is_link($path), 'CREATE_ONLY_RECEIPT');
    $bytes = m4o_json($value); $stream = fopen($path, 'xb'); m4o_expect(is_resource($stream), 'RECEIPT_CREATE');
    try { $offset = 0; while ($offset < strlen($bytes)) { $n = fwrite($stream, substr($bytes,$offset)); m4o_expect(is_int($n) && $n > 0,'RECEIPT_WRITE'); $offset += $n; } m4o_expect(fflush($stream),'RECEIPT_FLUSH'); }
    finally { fclose($stream); }
    m4o_expect(chmod($path,0600),'RECEIPT_MODE'); clearstatcache(true,$path);
    $stat = lstat($path); m4o_expect(is_array($stat) && ($stat['mode'] & 0777) === 0600 && $stat['uid'] === posix_geteuid()
        && $stat['size'] === strlen($bytes) && hash_file('sha256',$path) === hash('sha256',$bytes),'RECEIPT_READBACK');
}
function m4o_mount(array $facts, string $destination): array
{
    $rows = array_values(array_filter($facts['mounts'] ?? [],static fn(array $m): bool => ($m['Destination'] ?? '') === $destination));
    m4o_expect(count($rows) === 1,'EXACT_MOUNT'); return $rows[0];
}
function m4o_inventory(string $root): array
{
    $paths = M4O_FILES;
    foreach (M4O_ROOTS as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            m4o_expect(!$file->isLink(),'SOURCE_NO_SYMLINK');
            if (!$file->isFile()) continue;
            $relative = substr($file->getPathname(),strlen($root)+1);
            $paths[] = $relative;
        }
    }
    sort($paths,SORT_STRING); m4o_expect(count($paths) === count(array_unique($paths,SORT_STRING)),'SOURCE_UNIQUE_PATHS'); return $paths;
}
/** This entry has its own actual role/full source proof; no old migration proof is substituted. */
function m4o_source(array &$includedSet): array
{
    $base = audit_run_dir().'/evidence/'; $manifest = audit_manifest();
    $facts = m4o_read($base.'ms4-order-normal-container-created.json');
    $binding = m4o_read($base.'ms4-order-normal-source-binding.json');
    $environment = m4o_read($base.'ms4-order-test-environment.json');
    $merchantEnvironment = m4o_read($base.'ms4-order-merchant-environment.json');
    $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA');
    m4o_expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
        && count($_SERVER['argv']) === 1 && audit_repo_dir() === '/workspace' && audit_run_dir() === '/run/audit','ACTUAL_NORMAL_ENTRY');
    m4o_expect(getenv('AUDIT_ISOLATED') === '1' && getenv('AUDIT_GUARD_MODE') === 'read-only'
        && preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run) === 1 && preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha) === 1
        && ($manifest['run_id'] ?? '') === $run && ($manifest['commit_sha'] ?? '') === $sha
        && ($manifest['project'] ?? '') === 'tianli-audit-'.$run && ($manifest['guard_mode'] ?? '') === 'read-only'
        && ($manifest['isolation'] ?? '') === 'ISO-FULL' && ($manifest['network_internal'] ?? null) === true,'ACTUAL_ISOLATION_SOURCE');
    $roles = array_keys($manifest['services'] ?? []); sort($roles,SORT_STRING);
    m4o_expect($roles === ['audit-ms4-order-normal','audit-mysql','audit-redis'],'EXACT_NORMAL_ROLES');
    $service = $manifest['services']['audit-ms4-order-normal'];
    $freezePath = audit_run_dir().'/preparation-source-binding.json';
    $publicEnvironment = ['APP_ENV'=>'testing','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED'=>'true'];
    $environmentLines = ['APP_ENV=testing','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED=true'];
    $environmentHashes = [];
    foreach ([$environmentLines,array_reverse($environmentLines)] as $lines) {
        foreach (["\n","\n\n"] as $ending) $environmentHashes[] = hash('sha256',implode("\n",$lines).$ending);
    }
    m4o_expect(is_file($freezePath) && !is_link($freezePath)
        && ($environment['schema'] ?? null) === 1 && ($environment['scope'] ?? '') === 'MS4_TEST_PAYMENT_ENVIRONMENT'
        && ($environment['run_id'] ?? '') === $run && ($environment['source_sha'] ?? '') === $sha
        && ($environment['container_id'] ?? '') === ($service['id'] ?? null)
        && ($environment['freeze_sha256'] ?? '') === hash_file('sha256',$freezePath)
        && ($environment['environment'] ?? null) === $publicEnvironment
        && ($environment['native_inspect']['exit_code'] ?? null) === 0
        && ($environment['native_inspect']['stderr_sha256'] ?? '') === hash('sha256','')
        && in_array($environment['native_inspect']['stdout_sha256'] ?? '',$environmentHashes,true)
        && ($binding['test_environment_sha256'] ?? '') === hash_file('sha256',$base.'ms4-order-test-environment.json')
        && getenv('APP_ENV') === 'testing' && getenv('PHP_MARKETPLACE_PAYMENT_TEST_ENABLED') === 'true',
        'ACTUAL_CREATED_TEST_PAYMENT_ENVIRONMENT');
    m4o_expect(($merchantEnvironment['schema'] ?? null) === 1
        && ($merchantEnvironment['scope'] ?? '') === 'MS4_MERCHANT_CACHE_ENVIRONMENT'
        && ($merchantEnvironment['run_id'] ?? '') === $run && ($merchantEnvironment['source_sha'] ?? '') === $sha
        && ($merchantEnvironment['container_id'] ?? '') === ($service['id'] ?? null)
        && ($merchantEnvironment['freeze_sha256'] ?? '') === hash_file('sha256',$freezePath)
        && ($merchantEnvironment['environment'] ?? null) === ['PHP_CACHE_DRIVER'=>'redis']
        && ($merchantEnvironment['native_inspect']['exit_code'] ?? null) === 0
        && ($merchantEnvironment['native_inspect']['stderr_sha256'] ?? '') === hash('sha256','')
        && in_array($merchantEnvironment['native_inspect']['stdout_sha256'] ?? '',
            [hash('sha256',"PHP_CACHE_DRIVER=redis\n"),hash('sha256',"PHP_CACHE_DRIVER=redis\n\n")],true)
        && ($binding['merchant_environment_sha256'] ?? '') === hash_file('sha256',$base.'ms4-order-merchant-environment.json')
        && getenv('PHP_CACHE_DRIVER') === 'redis','ACTUAL_CREATED_MERCHANT_REDIS_ENVIRONMENT');
    m4o_expect(($facts['run_id'] ?? '') === $run && ($binding['run_id'] ?? '') === $run
        && ($facts['source_sha'] ?? '') === $sha && ($binding['source_sha'] ?? '') === $sha
        && preg_match('/\A[a-f0-9]{64}\z/D',(string)($service['id'] ?? '')) === 1 && ($facts['id'] ?? '') === $service['id']
        && gethostname() === substr($service['id'],0,12) && ($facts['hostname'] ?? '') === gethostname()
        && ($facts['image'] ?? '') === M4O_IMAGE && ($service['image_id'] ?? '') === M4O_IMAGE
        && ($facts['status'] ?? '') === 'created' && ($facts['read_only'] ?? null) === true && ($facts['privileged'] ?? null) === false
        && ($facts['network_mode'] ?? '') === ($manifest['network']['name'] ?? null)
        && ($facts['labels']['tianli.audit'] ?? '') === '1' && ($facts['labels']['tianli.audit.run'] ?? '') === $run
        && ($facts['labels']['com.docker.compose.project'] ?? '') === $manifest['project']
        && ($facts['labels']['com.docker.compose.service'] ?? '') === 'audit-ms4-order-normal'
        && ($facts['entrypoint'] ?? null) === ['php'] && ($facts['command'] ?? null) === ['help/audit-regressions/isolated/ms4-order-normal.php']
        && ($facts['workdir'] ?? '') === '/workspace' && ($facts['restart_count'] ?? null) === 0
        && ($facts['cap_drop'] ?? null) === ['ALL']
        && in_array($facts['security_options'] ?? null,[['no-new-privileges'],['no-new-privileges:true']],true),'NATIVE_CREATED_NORMAL_ROLE');
    m4o_expect(empty($facts['port_bindings']),'NO_PUBLISHED_PORT');
    foreach ($facts['ports'] ?? [] as $value) m4o_expect($value === null || $value === [],'NO_NATIVE_HOST_PORT');
    foreach (['/workspace','/source'] as $destination) {
        $mount = m4o_mount($facts,$destination);
        m4o_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === false
            && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($binding['requested_repo_source'] ?? ''),'OWN_SOURCE_RO');
    }
    $mount = m4o_mount($facts,'/run/audit');
    m4o_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === true
        && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($binding['requested_run_source'] ?? ''),'OWN_RUN_BIND');
    $mount = m4o_mount($facts,'/run/audit-app'); $volume = $manifest['project'].'_app-secrets';
    m4o_expect(($mount['Type'] ?? '') === 'volume' && ($mount['RW'] ?? null) === false && ($mount['Name'] ?? '') === $volume
        && in_array($volume,$manifest['volumes'] ?? [],true) && isset($manifest['volume_identities'][$volume]),'OWN_APP_CONFIG_RO');
    foreach ($facts['mounts'] ?? [] as $mount) m4o_expect(!str_contains((string)($mount['Source'] ?? ''),'docker.sock')
        && !str_contains((string)($mount['Destination'] ?? ''),'docker.sock') && ($mount['Destination'] ?? '') !== '/run/audit-bootstrap','NO_SOCKET_OR_MIGRATOR_MOUNT');
    m4o_expect(isset($facts['tmpfs']['/tmp']),'TMPFS_CREATED'); $tmpfs = false;
    foreach (file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ',$line,2); $left = explode(' ',$parts[0]); $right = explode(' ',$parts[1] ?? '');
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmpfs = true;
    }
    m4o_expect($tmpfs && !file_exists('/workspace/crmeb/.env') && !file_exists('/workspace/crmeb/public/install.lock'),'OWN_RUNTIME_NO_HOST_CONFIG');
    m4o_expect(($binding['schema'] ?? null) === 1 && ($binding['entry_sha256'] ?? '') === hash_file('sha256',__FILE__),'OWN_ENTRY_BINDING');
    $expected = m4o_inventory('/workspace'); m4o_expect(m4o_inventory('/source') === $expected,'SAME_COMPLETE_SOURCE_INVENTORY');
    $paths = []; $includedSet = [];
    foreach ($binding['source_files'] ?? [] as $item) {
        $relative = $item['path'] ?? '';
        m4o_expect(is_string($relative) && in_array($relative,$expected,true) && !isset($paths[$relative]) && is_int($item['bytes'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D',(string)($item['sha256'] ?? '')) === 1,'EXACT_BOUND_SOURCE');
        $paths[$relative] = true;
        foreach (['/workspace','/source'] as $root) {
            $path = $root.'/'.$relative;
            m4o_expect(is_file($path) && !is_link($path) && filesize($path) === $item['bytes'] && hash_file('sha256',$path) === $item['sha256'],'SOURCE_BYTES');
            $includedSet[realpath($path)] = true;
        }
    }
    $actual = array_keys($paths); sort($actual,SORT_STRING); m4o_expect($actual === $expected,'COMPLETE_SOURCE_CLOSURE');
    return ['container_id'=>$service['id'],'entry_namespace_pid'=>getmypid(),'source_sha'=>$sha,
        'binding_sha256'=>hash_file('sha256',$base.'ms4-order-normal-source-binding.json'),
        'test_environment_sha256'=>hash_file('sha256',$base.'ms4-order-test-environment.json'),
        'merchant_environment_sha256'=>hash_file('sha256',$base.'ms4-order-merchant-environment.json'),
        'native_created_sha256'=>hash_file('sha256',$base.'ms4-order-normal-container-created.json'),'source_files'=>count($actual)];
}
function m4o_prerequisites(): int
{
    $base = audit_run_dir().'/evidence/'; $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA');
    $init = m4o_read($base.'initializer-result.json'); $created = m4o_read($base.'initializer-created.json');
    $migration = m4o_read($base.'ms4-migration-normal.json'); $formal = m4o_read($base.'migration-formal-manifest.json');
    m4o_expect(($init['run_id'] ?? '') === $run && ($init['source_sha'] ?? '') === $sha
        && ($init['status'] ?? '') === 'VERIFIED_MS4_PREREQUISITES_ONLY' && ($init['native_exit'] ?? null) === 0
        && ($init['private_cleanup_verified'] ?? null) === true && ($init['binding']['container_id'] ?? '') === ($created['id'] ?? null)
        && ($created['run_id'] ?? '') === $run && ($created['source_sha'] ?? '') === $sha,'REAL_INITIALIZER_RECEIPT');
    $id = $init['actual_self_mer_id'] ?? null;
    m4o_expect(is_int($id) && $id > 0 && count($init['children'] ?? []) === 6
        && ($init['children'][4]['result']['self_mer_id'] ?? null) === $id && ($init['children'][5]['result']['self_mer_id'] ?? null) === $id,'ACTUAL_MS2_SELF_ID');
    m4o_expect(($migration['status'] ?? '') === 'VERIFIED_NORMAL_STRUCTURE_ONLY' && ($migration['scope'] ?? '') === 'NORMAL_STRUCTURE_ONLY'
        && ($migration['native_exit'] ?? null) === 0 && ($migration['private_cleanup_verified'] ?? null) === true
        && ($migration['binding']['source_sha'] ?? '') === $sha && ($formal['run_id'] ?? '') === $run && ($formal['commit_sha'] ?? '') === $sha
        && ($migration['binding']['container_id'] ?? '') === ($formal['services']['audit-ms4-migration']['id'] ?? null)
        && ($migration['actual_command_counts'] ?? null) === ['check'=>2,'apply-structure'=>2],'ACTUAL_MS4_MIGRATION');
    $rows = m4o_rows(audit_db(),'SELECT `mer_id` FROM `eb_marketplace_merchant` WHERE `mer_id` = ?',[$id]);
    m4o_expect(count($rows) === 1 && (string)$rows[0]['mer_id'] === (string)$id,'ACTUAL_INITIALIZED_SELF_ROW'); return $id;
}
function m4o_rows(PDO $pdo, string $sql, array $args = []): array
{
    $statement = $pdo->prepare($sql); m4o_expect($statement instanceof PDOStatement,'ACTUAL_PREPARE');
    foreach (array_values($args) as $i=>$value) m4o_expect($statement->bindValue($i+1,$value === null ? null : (string)$value,$value === null ? PDO::PARAM_NULL : PDO::PARAM_STR),'EXACT_SQL_BIND');
    m4o_expect($statement->execute(),'ACTUAL_EXECUTE'); return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function m4o_business_pdo(\think\db\ConnectionInterface $transactionConnection): PDO
{
    $connection = Db::connect(); $pdo = $transactionConnection->getPdo(); $native = $pdo instanceof PDO;
    $emulate = $native ? $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) : null;
    $state = ['callback_connection_same'=>$transactionConnection === $connection,
        'default_pdo_same'=>$native && $pdo === $connection->getPdo(), 'pdo_instance'=>$native,
        'in_transaction'=>$native && $pdo->inTransaction(),
        'driver_mysql'=>$native && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql',
        'exception_error_mode'=>$native && $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION,
        'emulate_type'=>get_debug_type($emulate), 'emulate_off_false'=>$emulate === false, 'emulate_off_zero'=>$emulate === 0];
    $GLOBALS['m4o_pdo_observations'][$GLOBALS['m4o_phase']] = $state;
    m4o_expect($state['callback_connection_same'] && $state['default_pdo_same'] && $state['pdo_instance']
        && $state['in_transaction'] && $state['driver_mysql'] && $state['exception_error_mode']
        && ($state['emulate_off_false'] || $state['emulate_off_zero']),'ACTUAL_DEFAULT_TRANSACTION_PDO');
    return $pdo;
}
function m4o_insert(PDO $pdo, string $table, array $values, string $pk = 'id'): array
{
    m4o_expect(in_array($table,M4O_FIXTURE_TABLES,true) && in_array($pk,['id','uid','mer_id'],true),'FIXED_FIXTURE_TABLE');
    foreach (array_keys($values) as $field) m4o_expect(preg_match('/\A[a-z_][a-z0-9_]*\z/D',$field) === 1,'FIXED_FIXTURE_COLUMN');
    $columns = implode(',',array_map(static fn(string $field): string => '`'.$field.'`',array_keys($values)));
    $sql = 'INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($values),'?')).')';
    $statement = $pdo->prepare($sql); m4o_expect($statement instanceof PDOStatement,'NATIVE_FIXTURE_PREPARE');
    foreach (array_values($values) as $i=>$value) m4o_expect($statement->bindValue($i+1,$value === null ? null : (string)$value,$value === null ? PDO::PARAM_NULL : PDO::PARAM_STR),'NATIVE_FIXTURE_BIND');
    m4o_expect($statement->execute(),'NATIVE_FIXTURE_INSERT');
    $id = $pdo->lastInsertId();
    m4o_expect($statement->rowCount() === 1 && is_string($id) && preg_match('/\A[1-9][0-9]*\z/D',$id) === 1,'NATIVE_FIXTURE_ID');
    $rows = m4o_rows($pdo,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'` = ?',[$id]);
    m4o_expect(count($rows) === 1 && (string)$rows[0][$pk] === $id,'ACTUAL_FIXTURE_READBACK');
    foreach ($values as $field=>$value) m4o_expect($value === null ? $rows[0][$field] === null : (string)$rows[0][$field] === (string)$value,'FIXTURE_FIELDS_READBACK');
    return $rows[0];
}
/** Register the real application/model services and dedicated Redis/auth configuration, without HTTP or AppInit jobs. */
function m4o_app(): \think\App
{
    require_once '/workspace/crmeb/vendor/autoload.php';
    $config = audit_config(); m4o_expect(($config['database']['username'] ?? '') === 'audit_app','APP_DML_CONFIG');
    $app = new \think\App('/workspace/crmeb/');
    require_once '/workspace/crmeb/vendor/topthink/framework/src/helper.php';
    m4o_expect(function_exists('app') && app() === $app && !$app->initialized(),'REAL_FRAMEWORK_HELPER_WITHOUT_APP_INIT');
    $runtime = '/tmp/ms4-order-normal-'.getenv('AUDIT_RUN_ID').'/';
    m4o_expect(!file_exists($runtime) && mkdir($runtime,0700),'OWN_RUNTIME_CREATE'); $app->setRuntimePath($runtime);
    m4o_expect(getenv('PHP_CACHE_DRIVER') === 'redis'
        && ($config['auth']['session_epoch'] ?? '') === 'audit-http-'.getenv('AUDIT_RUN_ID')
        && is_string($config['auth']['stamp_key'] ?? null) && strlen($config['auth']['stamp_key']) >= 32
        && is_string($config['auth']['issuer'] ?? null) && $config['auth']['issuer'] !== '',
        'DEDICATED_ORIGINAL_AUTH_CONFIGURATION');
    $cachePrefix = 'audit:'.getenv('AUDIT_RUN_ID').':ms4-app:';
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306','DATABASE_DATABASE'=>'audit_auth',
        'DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4',
        'DATABASE_DEBUG'=>false,'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379',
        'REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,'CACHE_CACHE_PREFIX'=>$cachePrefix,
        'AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],
        'AUTH_ISSUER'=>$config['auth']['issuer']]);
    $app->register(\app\AppService::class); $app->bootService($app->getService(\app\AppService::class));
    $app->bind(require '/workspace/crmeb/app/provider.php');
    foreach (['app','cache','log','marketplace','auth'] as $name) $app->config->set(require '/workspace/crmeb/config/'.$name.'.php',$name);
    $database = require '/workspace/crmeb/config/database.php';
    $database['connections']['mysql']['params'] = [PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    $database['connections']['mysql']['break_reconnect'] = false; $app->config->set($database,'database');
    require_once '/workspace/crmeb/app/common.php';
    $app->register(\think\service\ModelService::class); $app->bootService($app->getService(\think\service\ModelService::class));
    date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));
    m4o_expect($app->db instanceof \crmeb\compat\think\Db && $app->config->get('marketplace.trade.enabled') === false,'REAL_APP_SERVICES_GATE_CLOSED');
    m4o_expect(getenv('APP_ENV') === 'testing' && getenv('PHP_MARKETPLACE_PAYMENT_TEST_ENABLED') === 'true'
        && $app->config->get('marketplace.payment.test_enabled') === true,'REAL_TEST_PAYMENT_ENVIRONMENT_LOADED');
    m4o_expect($app->config->get('cache.default') === 'redis'
        && $app->config->get('cache.stores.redis.type') === 'redis'
        && $app->config->get('cache.stores.redis.host') === 'audit-redis'
        && (string)$app->config->get('cache.stores.redis.port') === '6379'
        && $app->config->get('cache.stores.redis.select') === 0
        && $app->config->get('cache.stores.redis.prefix') === $cachePrefix
        && $app->config->get('cache.stores.redis.password') === $config['redis_password']
        && $app->env->get('app.app_key') === $config['signing_key']
        && $app->config->get('auth.session_epoch') === $config['auth']['session_epoch']
        && $app->config->get('auth.stamp_key') === $config['auth']['stamp_key']
        && $app->config->get('auth.issuer') === $config['auth']['issuer'],'REAL_DEFAULT_REDIS_AND_PRIVATE_AUTH_LOADED');
    return $app;
}
function m4o_fixture(): array
{
    return Db::transaction(static function (\think\db\ConnectionInterface $transactionConnection): array {
        $pdo = m4o_business_pdo($transactionConnection); $now = time();
        foreach (['eb_user','eb_store_product','eb_store_cart','eb_user_address','eb_user_invoice','eb_system_config'] as $table)
            m4o_expect((int)m4o_rows($pdo,'SELECT COUNT(*) AS `n` FROM `'.$table.'`')[0]['n'] === 0,'FRESH_FIXTURE_TABLE');
        foreach (['store_free_postage'=>'10000.00','image_thumb_status'=>0,'member_func_status'=>0,'member_card_status'=>0,
            'member_price_status'=>0,'store_self_mention'=>0,'invoice_func_status'=>1,'special_invoice_status'=>0,'offline_pay_status'=>1,
            'offline_postage'=>0,'integral_ratio'=>'0.00','queue_open'=>0,'order_cancel_time'=>'0'] as $key=>$value)
            m4o_insert($pdo,'eb_system_config',['menu_name'=>$key,'type'=>'text','value'=>json_encode($value,JSON_THROW_ON_ERROR)]);
        $user = m4o_insert($pdo,'eb_user',['account'=>'ms4-'.getenv('AUDIT_RUN_ID'),'nickname'=>'普通验证顾客','user_type'=>'h5','is_money_level'=>0,'add_time'=>$now],'uid');
        $merchant = m4o_insert($pdo,'eb_marketplace_merchant',['name'=>'普通验证店铺','review_status'=>1,'accept_orders'=>1,'fulfillment_enabled'=>1,
            'security_locked'=>0,'add_time'=>$now,'update_time'=>$now],'mer_id');
        $product = m4o_insert($pdo,'eb_store_product',['mer_id'=>$merchant['mer_id'],'store_name'=>'普通规格商品','unit_name'=>'件','spec_type'=>1,
            'stock'=>20,'sales'=>0,'price'=>'20.15','cost'=>'7.45','postage'=>'2.67','freight'=>2,'temp_id'=>0,'is_show'=>1,'review_status'=>2,
            'is_vip'=>0,'logistics'=>'1','custom_form'=>'[]','min_qty'=>1,'add_time'=>$now]);
        m4o_insert($pdo,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>'甲,乙']);
        $skus = [];
        foreach ([['suk'=>'甲','stock'=>8,'price'=>'20.15','cost'=>'7.45'],['suk'=>'乙','stock'=>12,'price'=>'30.25','cost'=>'9.35']] as $data)
            $skus[] = m4o_insert($pdo,'eb_store_product_attr_value',$data+['product_id'=>$product['id'],'type'=>0,'unique'=>bin2hex(random_bytes(4)),
                'sales'=>0,'is_show'=>1,'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00']);
        $carts = [];
        foreach ([[0,2],[0,1],[1,2]] as $index=>$line) $carts[] = m4o_insert($pdo,'eb_store_cart',['uid'=>$user['uid'],'type'=>'0',
            'product_id'=>$product['id'],'product_attr_unique'=>$skus[$line[0]]['unique'],'cart_num'=>$line[1],'add_time'=>$now+$index,
            'is_pay'=>0,'is_del'=>0,'is_new'=>0,'status'=>1]);
        $unselected_cart = m4o_insert($pdo,'eb_store_cart',['uid'=>$user['uid'],'type'=>'0','product_id'=>$product['id'],
            'product_attr_unique'=>$skus[0]['unique'],'cart_num'=>1,'add_time'=>$now+3,'is_pay'=>0,'is_del'=>0,'is_new'=>0,'status'=>1]);
        $address = m4o_insert($pdo,'eb_user_address',['uid'=>$user['uid'],'real_name'=>'隔离验证','phone'=>'00000000000',
            'province'=>'验证省','city'=>'验证市','district'=>'验证区','detail'=>'专用隔离地址','is_default'=>1,'add_time'=>$now]);
        $invoice = m4o_insert($pdo,'eb_user_invoice',['uid'=>$user['uid'],'header_type'=>1,'type'=>1,'name'=>'普通验证抬头','is_default'=>1,'add_time'=>$now]);
        return compact('user','merchant','product','skus','carts','unselected_cart','address','invoice');
    });
}
function m4o_facts(PDO $pdo): array
{
    $facts = [];
    foreach (M4O_FACT_TABLES as $table) {
        $order = match ($table) { 'eb_marketplace_order_idempotency'=>'`uid`,`idem_key`',
            'eb_store_order_status'=>'`oid`,`change_type`,`change_message`,`change_time`',
            'eb_user'=>'`uid`','eb_marketplace_merchant'=>'`mer_id`',default=>'`id`' };
        $rows = m4o_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY '.$order);
        $facts[$table] = ['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
    }
    return $facts;
}
function m4o_verify(PDO $pdo, array $fixture, array $created): array
{
    $id = $created['order_id']; $uid = (int)$fixture['user']['uid']; $mer = (int)$fixture['merchant']['mer_id'];
    $orders = m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$id]); m4o_expect(count($orders) === 1,'COMMITTED_REAL_ORDER');
    $order = $orders[0]; m4o_expect((int)$order['uid'] === $uid && (int)$order['mer_id'] === $mer && (int)$order['marketplace_trade_version'] === 1
        && $order['unique'] === $created['key'] && (int)$order['paid'] === 0 && (int)$order['is_gift'] === 0,'COMMITTED_ORDER_IDENTITY');
    foreach (['total_price','pay_price','pay_postage','cost'] as $field) m4o_expect($order[$field] === $created['payload'][$field],'COMMITTED_QUOTE_TOTAL');
    m4o_expect($order['gift_mark'] === $created['payload']['gift_mark'],'COMMITTED_NORMAL_TEXT');
    $items = m4o_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]);
    $movements = m4o_rows($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `sku_id`',[$id]);
    m4o_expect(count($items) === 3 && count($movements) === 2,'THREE_ITEMS_TWO_AGGREGATE_RESERVES');
    $byId = []; $quantity = []; $postage = $goods = $cost = '0.00';
    foreach ($movements as $movement) {
        m4o_expect((int)$movement['mer_id'] === $mer && $movement['kind'] === 'reserve' && $movement['actor_type'] === 'customer'
            && (int)$movement['actor_id'] === $uid && $movement['order_item_id'] === null && $movement['parent_reserve_id'] === null,'REAL_RESERVE_ACTOR');
        $byId[(string)$movement['id']] = $movement;
    }
    foreach ($items as $item) {
        $cart = json_decode($item['cart_info'],true,32,JSON_THROW_ON_ERROR); $ref = (string)$item['cart_id']; $lineage = $cart['marketplace'];
        m4o_expect((int)$item['uid'] === $uid && (int)$item['mer_id'] === $mer && $cart['id'] === $ref && $lineage['cart_ref'] === $ref
            && is_string($lineage['reserve_id']) && isset($byId[$lineage['reserve_id']]),'COMMITTED_EXACT_ITEM_LINEAGE');
        $movement = $byId[$lineage['reserve_id']];
        m4o_expect((int)$movement['sku_id'] === $lineage['sku_id'] && $movement['sku_unique'] === $lineage['sku_unique']
            && (int)$movement['product_id'] === (int)$item['product_id'],'REAL_PHYSICAL_SKU_LINEAGE');
        $actualSku = null;
        foreach ($fixture['skus'] as $candidate) if ((string)$candidate['id'] === (string)$movement['sku_id']) $actualSku = $candidate;
        m4o_expect($actualSku !== null && $cart['sum_price'] === $actualSku['price'] && $cart['truePrice'] === $actualSku['price']
            && $cart['costPrice'] === $actualSku['cost'] && $cart['sum_true_price'] === bcmul($actualSku['price'],(string)$item['cart_num'],2),
            'COMMITTED_REAL_UNIT_PRICE_COST_AND_LINE_AMOUNT');
        m4o_expect($cart['coupon_price'] === '0.00' && $cart['integral_price'] === '0.00','NORMAL_DISABLED_RIGHTS_SNAPSHOT');
        foreach (['one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage'] as $field)
            m4o_expect($cart[$field] === '0.00','NORMAL_ZERO_COMMISSION_SNAPSHOT');
        $goods = bcadd($goods,$cart['sum_true_price'],2);
        $cost = bcadd($cost,bcmul($cart['costPrice'],(string)$item['cart_num'],2),2);
        $quantity[$lineage['reserve_id']] = ($quantity[$lineage['reserve_id']] ?? 0) + (int)$item['cart_num'];
        m4o_expect(is_string($cart['postage_price']) && $cart['postage_price'] === $cart['origin_postage_price'],'ACTUAL_CHARGED_LINE_POSTAGE');
        $postage = bcadd($postage,$cart['postage_price'],2);
    }
    $rootStock = (int)$fixture['product']['stock']; $rootSales = (int)$fixture['product']['sales'];
    foreach ($movements as $movement) {
        $sku = null; foreach ($fixture['skus'] as $candidate) if ((string)$candidate['id'] === (string)$movement['sku_id']) $sku = $candidate;
        m4o_expect($sku !== null && $quantity[(string)$movement['id']] === (int)$movement['qty'],'ACTUAL_AGGREGATE_QUANTITY');
        $qty = (int)$movement['qty'];
        m4o_expect((int)$movement['root_stock_before'] === $rootStock && (int)$movement['root_sales_before'] === $rootSales
            && (int)$movement['sku_stock_before'] === (int)$sku['stock'] && (int)$movement['sku_sales_before'] === (int)$sku['sales'],'REAL_BEFORE_STOCK_VALUES');
        $rootStock -= $qty; $rootSales += $qty;
        m4o_expect((int)$movement['root_stock_after'] === $rootStock && (int)$movement['root_sales_after'] === $rootSales
            && (int)$movement['sku_stock_after'] === (int)$sku['stock']-$qty && (int)$movement['sku_sales_after'] === (int)$sku['sales']+$qty,'REAL_AFTER_STOCK_VALUES');
        $actual = m4o_rows($pdo,'SELECT `stock`,`sales` FROM `eb_store_product_attr_value` WHERE `id` = ?',[$sku['id']])[0];
        m4o_expect((int)$actual['stock'] === (int)$movement['sku_stock_after'] && (int)$actual['sales'] === (int)$movement['sku_sales_after'],'INDEPENDENT_SKU_STOCK_READBACK');
    }
    $actual = m4o_rows($pdo,'SELECT `stock`,`sales` FROM `eb_store_product` WHERE `id` = ?',[$fixture['product']['id']])[0];
    m4o_expect((int)$actual['stock'] === $rootStock && (int)$actual['sales'] === $rootSales && $postage === $order['pay_postage'],'INDEPENDENT_ROOT_AND_POSTAGE');
    m4o_expect($goods === $order['total_price'] && $cost === $order['cost']
        && bcadd($goods,$postage,2) === $order['pay_price'],'INDEPENDENT_COMMITTED_LINE_TOTALS');
    $idem = m4o_rows($pdo,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `uid` = ? AND `idem_key` = ?',[$uid,$created['idem']]);
    m4o_expect(count($idem) === 1 && (int)$idem[0]['state'] === 1 && (int)$idem[0]['order_id'] === $id && (int)$idem[0]['post_commit_done'] === 0
        && hash_equals($idem[0]['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($created['request']),true))
        && hash_equals($idem[0]['confirmation_ref'],hash('sha256',$created['key'],true)),'COMMITTED_PERSISTENT_IDEMPOTENCY');
    $remaining = m4o_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    m4o_expect(count($remaining) === 1 && (string)$remaining[0]['id'] === (string)$fixture['unselected_cart']['id'],
        'ONLY_REAL_UNSELECTED_CART_REMAINS');
    $remainingRefs = array_map(static fn(array $row): string => (string)$row['id'],$remaining);
    foreach ($fixture['carts'] as $selected) m4o_expect(!in_array((string)$selected['id'],$remainingRefs,true),'COMMITTED_SELECTED_CART_CONSUMED');
    m4o_expect(array_keys($remaining[0]) === array_keys($fixture['unselected_cart']),'UNSELECTED_CART_COLUMNS_COMPLETE');
    foreach ($fixture['unselected_cart'] as $field=>$value) m4o_expect($value === null ? $remaining[0][$field] === null
        : $remaining[0][$field] !== null && (string)$remaining[0][$field] === (string)$value,'UNSELECTED_CART_VALUE_UNCHANGED');
    $invoices = m4o_rows($pdo,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id]);
    m4o_expect(count($invoices) === 1 && (int)$invoices[0]['uid'] === $uid && (int)$invoices[0]['invoice_id'] === (int)$fixture['invoice']['id']
        && (int)$invoices[0]['is_pay'] === 0,'COMMITTED_ACTUAL_INVOICE');
    foreach (['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number'] as $field)
        m4o_expect((string)$invoices[0][$field] === (string)$fixture['invoice'][$field],'ACTUAL_INVOICE_HEADING_SNAPSHOT');
    return ['order_id'=>$id,'item_count'=>count($items),'reserve_count'=>count($movements),'line_postage_sum'=>$postage,
        'persistent_cart_consumption'=>['selected_count'=>count($fixture['carts']),'remaining_count'=>count($remaining),'unselected_unchanged'=>true],
        'fixture_identity_sha256'=>hash('sha256',serialize([$uid,$mer,$fixture['product']['id'],array_column($fixture['skus'],'id'),
            array_column($fixture['carts'],'id'),$fixture['unselected_cart']['id']]))];
}
/** Separate real records for each internal-service case; the original DAO fixture remains intact. */
function m4o_service_fixture(string $kind): array
{
    m4o_expect(in_array($kind,['persistent','temporary'],true),'ORDINARY_SERVICE_SOURCE_KIND');
    return Db::transaction(static function (\think\db\ConnectionInterface $connection) use ($kind): array {
        $pdo=m4o_business_pdo($connection);$now=time();
        $config=m4o_rows($pdo,'SELECT `id`,`value` FROM `eb_system_config` WHERE `menu_name` = ? FOR UPDATE',['order_cancel_time']);
        $previous=$kind==='persistent'?'0':'0.5';$hours=$kind==='persistent'?'0.5':'0';
        m4o_expect(count($config)===1&&$config[0]['value']===json_encode($previous,JSON_THROW_ON_ERROR),'ACTUAL_OWN_CANCEL_CONFIGURATION_BEFORE');
        $value=json_encode($hours,JSON_THROW_ON_ERROR);
        $statement=$pdo->prepare('UPDATE `eb_system_config` SET `value` = ? WHERE `id` = ? AND `menu_name` = ? AND `value` = ?');
        m4o_expect($statement instanceof PDOStatement,'ACTUAL_CANCEL_CONFIGURATION_PREPARE');
        foreach([$value,(string)$config[0]['id'],'order_cancel_time',$config[0]['value']] as $index=>$part)
            m4o_expect($statement->bindValue($index+1,$part,PDO::PARAM_STR),'ACTUAL_CANCEL_CONFIGURATION_BIND');
        m4o_expect($statement->execute()&&$statement->rowCount()===1,'ACTUAL_CANCEL_CONFIGURATION_UPDATE');
        $stored=m4o_rows($pdo,'SELECT `id`,`value` FROM `eb_system_config` WHERE `id` = ?',[$config[0]['id']]);
        m4o_expect(count($stored)===1&&$stored[0]['value']===$value,'ACTUAL_CANCEL_CONFIGURATION_READBACK');
        $user=m4o_insert($pdo,'eb_user',['account'=>'ms4-'.getenv('AUDIT_RUN_ID').($kind==='persistent'?'-p':'-t'),
            'nickname'=>'内部创建顾客','user_type'=>'h5','is_money_level'=>0,'add_time'=>$now],'uid');
        $merchant=m4o_insert($pdo,'eb_marketplace_merchant',['name'=>'内部创建店铺','review_status'=>1,'accept_orders'=>1,
            'fulfillment_enabled'=>1,'security_locked'=>0,'add_time'=>$now,'update_time'=>$now],'mer_id');
        $products=$skus=$first=[];
        foreach(['甲','乙'] as $index=>$name){
            $forms=[['title'=>'同名资料','label'=>'text','status'=>true,'value'=>'']];
            if($index===0)$forms[]=['title'=>'同名资料','label'=>'number','status'=>false,'value'=>''];
            $product=m4o_insert($pdo,'eb_store_product',['mer_id'=>$merchant['mer_id'],'store_name'=>'内部创建商品'.$name,
                'unit_name'=>'件','spec_type'=>1,'stock'=>20,'sales'=>0,'price'=>'20.15','cost'=>'7.45','postage'=>'2.67',
                'freight'=>2,'temp_id'=>0,'is_show'=>1,'review_status'=>2,'is_vip'=>0,'logistics'=>'1','min_qty'=>1,
                'custom_form'=>json_encode($forms,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'add_time'=>$now]);
            $products[(int)$product['id']]=$product;
            m4o_insert($pdo,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>'甲,乙']);
            foreach([['suk'=>'甲','stock'=>8,'price'=>'20.15','cost'=>'7.45'],['suk'=>'乙','stock'=>12,'price'=>'30.25','cost'=>'9.35']] as $position=>$data){
                $sku=m4o_insert($pdo,'eb_store_product_attr_value',$data+['product_id'=>$product['id'],'type'=>0,
                    'unique'=>bin2hex(random_bytes(4)),'sales'=>0,'is_show'=>1,'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00']);
                $skus[(int)$sku['id']]=$sku;if($position===0)$first[]=$sku;
            }
        }
        $plan=[['sku'=>$first[0],'qty'=>2],['sku'=>$first[0],'qty'=>1],['sku'=>$first[1],'qty'=>2]];$carts=[];
        if($kind==='persistent')foreach($plan as $index=>$line)$carts[]=m4o_insert($pdo,'eb_store_cart',[
            'uid'=>$user['uid'],'type'=>'0','product_id'=>$line['sku']['product_id'],'product_attr_unique'=>$line['sku']['unique'],
            'cart_num'=>$line['qty'],'add_time'=>$now+$index,'is_pay'=>0,'is_del'=>0,'is_new'=>0,'status'=>1]);
        $unselected=m4o_insert($pdo,'eb_store_cart',['uid'=>$user['uid'],'type'=>'0','product_id'=>$first[0]['product_id'],
            'product_attr_unique'=>$first[0]['unique'],'cart_num'=>1,'add_time'=>$now+3,'is_pay'=>0,'is_del'=>0,'is_new'=>0,'status'=>1]);
        $address=m4o_insert($pdo,'eb_user_address',['uid'=>$user['uid'],'real_name'=>'内部验证','phone'=>'00000000000',
            'province'=>'验证省','city'=>'验证市','district'=>'验证区','detail'=>'专用内部验证地址','is_default'=>1,'add_time'=>$now]);
        $invoice=m4o_insert($pdo,'eb_user_invoice',['uid'=>$user['uid'],'header_type'=>1,'type'=>1,'name'=>'内部验证抬头','is_default'=>1,'add_time'=>$now]);
        return compact('user','merchant','products','skus','plan','carts','unselected','address','invoice','hours');
    });
}

/** Actual lookup and its complete SQL/cache no-write observation; no synthetic ledger states. */
function m4o_service_lookup(MarketplaceOrderCreateServices $service,int $uid,string $key,?string $orderNumber): void
{
    $before=m4o_facts(audit_db());$cached=CacheService::get('user_order_'.$uid.$key);
    $temporary=[];if(is_array($cached)&&($cached['source_kind']??null)==='temporary')
        foreach($cached['cart_refs'] as $ref)$temporary[$ref]=CacheService::get($ref);
    $result=$service->lookupConfirmation($uid,$key);
    m4o_expect(m4o_facts(audit_db())===$before&&CacheService::get('user_order_'.$uid.$key)===$cached,'ACTUAL_LOOKUP_NO_SQL_OR_CONFIRMATION_WRITE');
    foreach($temporary as $ref=>$payload)m4o_expect(CacheService::get((string)$ref)===$payload,'ACTUAL_LOOKUP_TEMPORARY_CACHE_UNCHANGED');
    $keys=array_keys($result);sort($keys,SORT_STRING);
    m4o_expect(($result['state']??null)===($orderNumber===null?'not_found':'created')&&($result['key']??null)===$key,
        'ACTUAL_LOOKUP_NORMAL_STATE_AND_KEY');
    m4o_expect($keys===($orderNumber===null?['key','state']:['key','orderId','state'])
        &&($orderNumber===null||$result['orderId']===$orderNumber),'ACTUAL_LOOKUP_CREATED_IDENTITY');
    m4o_expect(!Db::connect()->getPdo()->inTransaction()&&!audit_db()->inTransaction(),'LOOKUP_OWN_TRANSACTION_FINISHED');
}

/** Independent real rows, not a second expected-price payload derived from service output. */
function m4o_verify_service(PDO $pdo,array $fixture,array $request,string $idemKey,array $frame,array $answers,array $result,string $kind,array $sqlCartBefore,?array $membership=null,?int $cancelSeconds=null): array
{
    $uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];
    m4o_expect(!$pdo->inTransaction()&&!Db::connect()->getPdo()->inTransaction()&&$pdo!==Db::connect()->getPdo(),'SERVICE_COMMITTED_INDEPENDENT_PDO');
    $orders=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$uid,$result['order_id']]);
    m4o_expect(count($orders)===1,'SERVICE_ACTUAL_ORDER_READBACK');$order=$orders[0];$id=(int)$order['id'];
    m4o_expect((int)$order['mer_id']===$mer&&(int)$order['marketplace_trade_version']===1&&$order['unique']===$request['confirm_key']
        &&$result['key']===$request['confirm_key']&&(int)$order['paid']===0&&(int)$order['is_gift']===0,'SERVICE_ACTUAL_ORDER_IDENTITY');
    m4o_expect(json_decode($order['cart_id'],true,32,JSON_THROW_ON_ERROR)===$request['cart_refs']
        &&$order['mark']===$request['options']['mark']&&$order['gift_mark']===$request['options']['gift_mark']
        &&$order['pay_type']===$request['options']['payType'],'SERVICE_ACTUAL_INPUT_SNAPSHOTS');
    $address=$fixture['address'];
    m4o_expect($order['real_name']===$address['real_name']&&$order['user_phone']===$address['phone']
        &&$order['user_address']===$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail']
        &&$order['merchant_name_snapshot']===$fixture['merchant']['name']
        &&$order['merchant_after_sale_contact_snapshot']===$fixture['merchant']['after_sale_contact'],'SERVICE_ACTUAL_ADDRESS_SHOP_SNAPSHOT');
    $items=m4o_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]);
    $moves=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `sku_id`',[$id]);
    m4o_expect(count($items)===3&&count($moves)===2,'SERVICE_THREE_ITEMS_TWO_RESERVES');
    $selected=[];foreach($frame['selection'] as $line)$selected[$line['cart_ref']]=$line;
    $byMovement=[];foreach($moves as $move)$byMovement[(string)$move['id']]=$move;
    $quantities=$seen=[];$goods=$cost=$postage='0.00';$total=0;
    $membershipOriginal=$membershipLevel=$membershipPaid='0.00';
    foreach($items as $item){
        $cart=json_decode($item['cart_info'],true,32,JSON_THROW_ON_ERROR);$ref=(string)$item['cart_id'];$line=$selected[$ref]??null;
        m4o_expect(is_array($line)&&!isset($seen[$ref]),'SERVICE_ACTUAL_SELECTED_ITEM');$seen[$ref]=true;
        $sku=$fixture['skus'][$line['sku_id']];$root=$fixture['products'][$line['product_id']];$qty=$line['qty'];$lineage=$cart['marketplace'];
        m4o_expect((int)$item['uid']===$uid&&(int)$item['mer_id']===$mer&&(int)$item['product_id']===$line['product_id']
            &&(int)$item['cart_num']===$qty&&(int)$item['surplus_num']===$qty&&(int)$item['refund_num']===0
            &&$cart['id']===$ref&&$cart['cart_num']===$qty&&$item['unique']===md5($ref.$id),'SERVICE_SAVED_ITEM_SELECTION');
        m4o_expect($lineage['cart_ref']===$ref&&$lineage['sku_id']===$line['sku_id']&&$lineage['sku_type']===0
            &&$lineage['sku_unique']===$sku['unique']&&is_string($lineage['reserve_id'])&&isset($byMovement[$lineage['reserve_id']]),
            'SERVICE_SAVED_RESERVE_LINEAGE');
        m4o_expect((int)$cart['productInfo']['id']===$line['product_id']&&(int)$cart['productInfo']['mer_id']===$mer
            &&(int)$cart['productInfo']['attrInfo']['id']===$line['sku_id']&&(int)$cart['productInfo']['attrInfo']['type']===0
            &&(int)$cart['productInfo']['attrInfo']['product_id']===$line['product_id']&&$cart['productInfo']['attrInfo']['unique']===$sku['unique'],
            'SERVICE_SAVED_PHYSICAL_PRODUCT_SKU');
        $move=$byMovement[$lineage['reserve_id']];
        m4o_expect((int)$move['product_id']===$line['product_id']&&(int)$move['sku_id']===$line['sku_id']
            &&$move['sku_unique']===$sku['unique'],'SERVICE_REAL_PHYSICAL_SKU');
        if($membership===null){
        m4o_expect($cart['sum_price']===$sku['price']&&$cart['truePrice']===$sku['price']&&$cart['costPrice']===$sku['cost']
            &&$cart['productInfo']['attrInfo']['price']===$sku['price']&&$cart['productInfo']['attrInfo']['cost']===$sku['cost']
            &&$cart['sum_true_price']===bcmul($sku['price'],(string)$qty,2),'SERVICE_REAL_PRIVATE_PRICE_COST');
        m4o_expect($cart['postage_price']===bcmul($root['postage'],(string)$qty,2)
            &&$cart['origin_postage_price']===$cart['postage_price'],'SERVICE_ACTUAL_FIXED_CHARGED_LINE_POSTAGE');
        }else{
            $expected=$membership['lines'][$line['sku_id'].':'.$qty]??null;
            m4o_expect(is_array($expected)&&$cart['sum_price']===$expected['base']&&$cart['sum_price']===$sku['price']
                &&$cart['truePrice']===$expected['unit']&&$cart['vip_truePrice']===$expected['saving']
                &&$cart['price_type']===$expected['type']&&$cart['costPrice']===$expected['cost']&&$cart['costPrice']===$sku['cost']
                &&$cart['productInfo']['attrInfo']['price']===$expected['base']
                &&$cart['productInfo']['attrInfo']['vip_price']===$expected['vip']
                &&$cart['productInfo']['attrInfo']['cost']===$expected['cost']
                &&$cart['sum_true_price']===bcmul($expected['unit'],(string)$qty,2),'MEMBERSHIP_REAL_PRIVATE_PRICE_COST_AND_TYPE');
            m4o_expect($cart['postage_price']===$expected['postage']&&$cart['origin_postage_price']===$expected['postage'],
                'MEMBERSHIP_INDEPENDENT_CHARGED_LINE_POSTAGE');
            $membershipOriginal=bcadd($membershipOriginal,bcmul($cart['sum_price'],(string)$qty,2),2);
            $savedSaving=bcmul($cart['vip_truePrice'],(string)$qty,2);
            if($cart['price_type']==='member')$membershipPaid=bcadd($membershipPaid,$savedSaving,2);
            else $membershipLevel=bcadd($membershipLevel,$savedSaving,2);
        }
        foreach(['coupon_price','integral_price','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage'] as $field)
            m4o_expect($cart[$field]==='0.00','SERVICE_ZERO_DISABLED_RIGHTS_SNAPSHOT');
        $goods=bcadd($goods,$cart['sum_true_price'],2);$cost=bcadd($cost,bcmul($sku['cost'],(string)$qty,2),2);
        $postage=bcadd($postage,$cart['postage_price'],2);$total+=$qty;
        $quantities[$lineage['reserve_id']]=($quantities[$lineage['reserve_id']]??0)+$qty;
    }
    m4o_expect(count($seen)===count($selected)&&$goods===$order['total_price']&&$cost===$order['cost']
        &&$postage===$order['pay_postage']&&$postage===$order['total_postage']&&bcadd($goods,$postage,2)===$order['pay_price']
        &&(int)$order['total_num']===$total,'SERVICE_INDEPENDENT_ORDER_TOTALS');
    if($membership!==null)m4o_expect($membershipOriginal===$membership['amounts']['goods_original']
        &&$membershipLevel===$membership['amounts']['level_discount']&&$membershipPaid===$membership['amounts']['member_discount']
        &&$goods===$membership['amounts']['goods_charged']&&$cost===$membership['amounts']['cost']
        &&$postage===$membership['amounts']['postage']&&$order['pay_price']===$membership['amounts']['payable'],
        'MEMBERSHIP_SAVED_ITEM_SUMS_AND_DISCOUNT_CLASSIFICATION');
    $rootState=[];foreach($fixture['products'] as $productId=>$root)$rootState[$productId]=['stock'=>(int)$root['stock'],'sales'=>(int)$root['sales']];
    foreach($moves as $move){
        $skuId=(int)$move['sku_id'];$productId=(int)$move['product_id'];$sku=$fixture['skus'][$skuId];$qty=(int)$move['qty'];
        m4o_expect((int)$move['mer_id']===$mer&&$move['kind']==='reserve'&&$move['actor_type']==='customer'
            &&(int)$move['actor_id']===$uid&&(int)$move['sku_type']===0&&$move['parent_reserve_id']===null
            &&$move['order_item_id']===null&&$move['refund_id']===null&&$qty===$quantities[(string)$move['id']],
            'SERVICE_ACTUAL_RESERVE_ACTOR_AND_QUANTITY');
        m4o_expect((int)$move['root_stock_before']===$rootState[$productId]['stock']&&(int)$move['root_sales_before']===$rootState[$productId]['sales']
            &&(int)$move['sku_stock_before']===(int)$sku['stock']&&(int)$move['sku_sales_before']===(int)$sku['sales'],'SERVICE_REAL_STOCK_BEFORE');
        $rootState[$productId]['stock']-=$qty;$rootState[$productId]['sales']+=$qty;
        m4o_expect((int)$move['root_stock_after']===$rootState[$productId]['stock']&&(int)$move['root_sales_after']===$rootState[$productId]['sales']
            &&(int)$move['sku_stock_after']===(int)$sku['stock']-$qty&&(int)$move['sku_sales_after']===(int)$sku['sales']+$qty,'SERVICE_REAL_STOCK_AFTER');
        $actual=m4o_rows($pdo,'SELECT `stock`,`sales` FROM `eb_store_product_attr_value` WHERE `id` = ?',[$skuId])[0];
        m4o_expect((int)$actual['stock']===(int)$move['sku_stock_after']&&(int)$actual['sales']===(int)$move['sku_sales_after'],'SERVICE_INDEPENDENT_SKU_STOCK');
    }
    foreach($rootState as $productId=>$state){
        $actual=m4o_rows($pdo,'SELECT `stock`,`sales` FROM `eb_store_product` WHERE `id` = ?',[$productId])[0];
        m4o_expect((int)$actual['stock']===$state['stock']&&(int)$actual['sales']===$state['sales'],'SERVICE_INDEPENDENT_ROOT_STOCK');
    }
    $ledger=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `uid` = ? AND `confirmation_ref` = ?',
        [$uid,hash('sha256',$request['confirm_key'],true)]);
    m4o_expect(count($ledger)===1&&(int)$ledger[0]['state']===1&&(int)$ledger[0]['order_id']===$id&&(int)$ledger[0]['mer_id']===$mer
        &&$ledger[0]['source_kind']===$kind&&json_decode($ledger[0]['cart_refs'],true,32,JSON_THROW_ON_ERROR)===$request['cart_refs']
        &&$ledger[0]['idem_key']===$idemKey&&(int)$ledger[0]['post_commit_done']===1
        &&hash_equals($ledger[0]['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($request),true)),
        'SERVICE_ACTUAL_COMMITTED_LEDGER');
    $cancelConfig=m4o_rows($pdo,'SELECT `value` FROM `eb_system_config` WHERE `menu_name` = ?',['order_cancel_time']);
    m4o_expect(count($cancelConfig)===1&&$cancelConfig[0]['value']===json_encode($fixture['hours'],JSON_THROW_ON_ERROR),'SERVICE_REAL_CANCEL_CONFIGURATION_UNCHANGED');
    $hours=json_decode($cancelConfig[0]['value'],true,8,JSON_THROW_ON_ERROR);
    $deadline=$hours==='0'?0:(int)$order['add_time']+(int)bcmul($hours,'3600',0);
    if($cancelSeconds===null){
    m4o_expect((int)$ledger[0]['add_time']===(int)$order['add_time']
        &&(int)$ledger[0]['cancel_at']===$deadline&&$deadline===($kind==='persistent'?(int)$order['add_time']+1800:0),'SERVICE_ACTUAL_CONFIGURED_DEADLINE');
    }else{
        m4o_expect($kind==='persistent'&&$fixture['hours']==='0.0000001'&&$cancelSeconds===0
            &&bccomp($hours,'0',7)>0&&(int)$ledger[0]['add_time']===(int)$order['add_time']
            &&(int)$ledger[0]['cancel_at']===$deadline&&$deadline===(int)$order['add_time']+$cancelSeconds,
            'SERVICE_ACTUAL_POSITIVE_SUBSECOND_DEADLINE');
    }
    $storedForms=json_decode($order['custom_form'],true,32,JSON_THROW_ON_ERROR);
    m4o_expect(is_array($storedForms)&&count($storedForms)===2&&count($frame['custom_form'])===2,'SERVICE_TWO_SAVED_FORM_GROUPS');
    $answerByProduct=[];foreach($answers as $answer)$answerByProduct[$answer['product_id']]=$answer;
    $required=0;$zero=false;
    foreach($frame['custom_form'] as $index=>$definition){
        $saved=$storedForms[$index];$productId=$definition['product_id'];$root=$fixture['products'][$productId];
        m4o_expect($saved['product_id']===$productId&&$saved['product_name']===$root['store_name']
            &&$saved['cart_refs']===$definition['cart_refs']&&$saved['definition_sha256']===$definition['definition_sha256']
            &&count($saved['fields'])===count($definition['fields']),'SERVICE_SAVED_FORM_IDENTITY_AND_ORIGINAL_HASH');
        $rawFields=json_decode($root['custom_form'],true,32,JSON_THROW_ON_ERROR);$values=[];
        foreach($answerByProduct[$productId]['values'] as $answer)$values[$answer['field_index']]=$answer['value'];
        foreach($definition['fields'] as $fieldIndex=>$field){
            $stored=$saved['fields'][$fieldIndex];$raw=$rawFields[$fieldIndex];
            m4o_expect($stored['field_index']===$fieldIndex&&$stored['title']===$raw['title']&&$stored['label']===$raw['label']
                &&$stored['status']===$raw['status']&&$stored['value']===$values[$fieldIndex],'SERVICE_REAL_DEFINITION_AND_ANSWER_SNAPSHOT');
            if($raw['status']){m4o_expect(is_string($stored['value'])&&trim($stored['value'])!=='','SERVICE_NONEMPTY_REQUIRED_FORM');++$required;}
            if(!$raw['status']&&$raw['label']==='number'&&$stored['value']==='0')$zero=true;
        }
    }
    m4o_expect($required===2&&$zero,'SERVICE_NONEMPTY_MULTI_PRODUCT_AND_OPTIONAL_ZERO');
    $remaining=m4o_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    if($kind==='persistent'){
        m4o_expect(count($remaining)===1&&(string)$remaining[0]['id']===(string)$fixture['unselected']['id'],'SERVICE_ONLY_UNSELECTED_CART_REMAINS');
        m4o_expect(array_keys($remaining[0])===array_keys($fixture['unselected']),'SERVICE_UNSELECTED_CART_COLUMNS_COMPLETE');
        foreach($fixture['unselected'] as $field=>$value)m4o_expect($value===null?$remaining[0][$field]===null
            :$remaining[0][$field]!==null&&(string)$remaining[0][$field]===(string)$value,'SERVICE_UNSELECTED_CART_VALUE_UNCHANGED');
        $remainingRefs=array_map(static fn(array $row):string=>(string)$row['id'],$remaining);
        foreach($request['cart_refs'] as $ref)m4o_expect(!in_array($ref,$remainingRefs,true),'SERVICE_SELECTED_CART_CONSUMED');
    }else m4o_expect($remaining===$sqlCartBefore,'SERVICE_TEMPORARY_SQL_CART_COMPLETE_UNCHANGED');
    $invoices=m4o_rows($pdo,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id]);
    m4o_expect(count($invoices)===1&&(int)$invoices[0]['uid']===$uid&&(int)$invoices[0]['invoice_id']===(int)$fixture['invoice']['id']
        &&(int)$invoices[0]['is_pay']===0,'SERVICE_ACTUAL_INVOICE_SNAPSHOT');
    foreach(['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number'] as $field)
        m4o_expect((string)$invoices[0][$field]===(string)$fixture['invoice'][$field],'SERVICE_ACTUAL_INVOICE_HEADING');
    return ['order_id'=>$id,'item_count'=>count($items),'reserve_count'=>count($moves),'form_group_count'=>count($storedForms),
        'fixture_identity_sha256'=>hash('sha256',serialize([$uid,$mer,array_keys($fixture['products']),array_keys($fixture['skus']),$request['cart_refs']]))];
}

function m4o_service_case(\think\App $app,string $kind,array &$contexts,?string $fixtureHours=null,?int $cancelSeconds=null): array
{
    $phaseKind=$fixtureHours===null&&$cancelSeconds===null?$kind:$kind.'_cancel_tiny';
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_fixtures';$fixture=m4o_service_fixture($kind);
    if($fixtureHours!==null||$cancelSeconds!==null){
        m4o_expect($kind==='persistent'&&$fixtureHours==='0.0000001'&&$cancelSeconds===0&&$fixture['hours']==='0.5',
            'ORDINARY_TINY_FIXTURE_ONLY');
        Db::transaction(static function(\think\db\ConnectionInterface $connection)use($fixtureHours):void{
            $pdo=m4o_business_pdo($connection);
            $rows=m4o_rows($pdo,'SELECT `id`,`value` FROM `eb_system_config` WHERE `menu_name` = ?',['order_cancel_time']);
            m4o_expect(count($rows)===1&&$rows[0]['value']===json_encode('0.5',JSON_THROW_ON_ERROR),'TINY_CONFIG_ACTUAL_BEFORE');
            $value=json_encode($fixtureHours,JSON_THROW_ON_ERROR);
            $statement=$pdo->prepare('UPDATE `eb_system_config` SET `value` = ? WHERE `id` = ? AND `menu_name` = ? AND `value` = ?');
            m4o_expect($statement instanceof PDOStatement,'TINY_CONFIG_NATIVE_PREPARE');
            foreach([$value,(string)$rows[0]['id'],'order_cancel_time',$rows[0]['value']]as$i=>$part)
                m4o_expect($statement->bindValue($i+1,$part,PDO::PARAM_STR),'TINY_CONFIG_NATIVE_BIND');
            m4o_expect($statement->execute()&&$statement->rowCount()===1,'TINY_CONFIG_CONDITIONAL_WRITE');
            m4o_expect(m4o_rows($pdo,'SELECT `value` FROM `eb_system_config` WHERE `id` = ?',[$rows[0]['id']])===[['value'=>$value]],
                'TINY_CONFIG_ACTUAL_READBACK');
            m4o_expect(m4o_business_pdo($connection)===$pdo,'TINY_CONFIG_SAME_DEFAULT_PDO');
        });
        $fixture['hours']=$fixtureHours;
        CacheService::delete('system_config_order_cancel_time');
        m4o_expect(sys_config('order_cancel_time')===$fixtureHours,'TINY_CONFIG_REAL_GETTER');
    }
    $uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];$addressId=(int)$fixture['address']['id'];
    $sqlBefore=m4o_rows(audit_db(),'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);$refs=[];
    if($kind==='temporary'){
        $GLOBALS['m4o_phase']='service_temporary_actual_cart_producer';$cartService=$app->make(StoreCartServices::class);
        foreach($fixture['plan'] as $line){
            $sku=$line['sku'];$ref=$cartService->setCart($uid,(int)$sku['product_id'],$line['qty'],$sku['unique'],0,true);
            m4o_expect(is_string($ref)&&MarketplaceOrderInput::reference($ref,'temporary')===$ref&&!in_array($ref,$refs,true),'ACTUAL_TEMPORARY_PRODUCER_REFERENCE');
            $payload=CacheService::get($ref);
            m4o_expect(is_array($payload)&&($payload['catalog_cart_version']??null)===1&&($payload['uid']??null)===$uid
                &&($payload['cartData']['id']??null)===$ref&&($payload['cartData']['uid']??null)===$uid
                &&($payload['cartData']['product_id']??null)===(int)$sku['product_id']&&($payload['cartData']['product_attr_unique']??null)===$sku['unique']
                &&($payload['cartData']['cart_num']??null)===$line['qty'],'ACTUAL_TEMPORARY_CACHE_READBACK');
            $refs[]=$ref;
        }
        m4o_expect(m4o_rows(audit_db(),'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid])===$sqlBefore,'TEMPORARY_PRODUCER_NO_SQL_CART_CHANGE');
    }else $refs=array_map(static fn(array $row):string=>(string)$row['id'],$fixture['carts']);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_actual_confirmation';
    $choice=MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>implode(',',$refs),'addressId'=>$addressId,'new'=>$kind==='temporary']);
    $user=$app->make(UserServices::class)->getUserInfo($uid);m4o_expect($user!==null&&(int)$user->getData('uid')===$uid,'SERVICE_REAL_USER_MODEL');
    $orders=$app->make(StoreOrderServices::class);
    $confirmation=$orders->getOrderConfirmData($user->toArray(),$choice['cartId'],$choice['new'],$addressId,1,0,$choice);
    $key=$confirmation['orderKey'];$cached=CacheService::get('user_order_'.$uid.$key);
    m4o_expect(is_string($key)&&is_array($cached),'SERVICE_REAL_CONFIRMATION_CACHE');$frame=MarketplaceCartSelection::frame($cached,$uid);
    m4o_expect($frame['mer_id']===$mer&&$frame['source_kind']===$kind&&$frame['cart_refs']===$choice['cart_refs']
        &&count($frame['selection'])===3&&count($frame['custom_form'])===2,'SERVICE_ACTUAL_MULTI_PRODUCT_FRAME');
    $answers=[];foreach($frame['custom_form'] as $group){
        $values=[];foreach($group['fields'] as $field)$values[]=['field_index'=>$field['field_index'],
            'value'=>$field['label']==='number'?'0':'内部填写'.$group['product_name']];
        $answers[]=['product_id'=>$group['product_id'],'definition_sha256'=>$group['definition_sha256'],'values'=>$values];
    }
    $raw=['mer_id'=>$mer,'cartId'=>$choice['cartId'],'new'=>$choice['new'],'addressId'=>$addressId,
        'invoice_id'=>(int)$fixture['invoice']['id'],'idem_key'=>'internal-'.bin2hex(random_bytes(16)),
        'payType'=>'','mark'=>'内部创建普通验证','gift_mark'=>'内部普通文本','custom_form'=>$answers];
    $input=MarketplaceOrderInput::create($uid,$key,$raw);$service=$app->make(MarketplaceOrderCreateServices::class);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_lookup_before';m4o_service_lookup($service,$uid,$key,null);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_actual_creation';
    m4o_expect(!Db::connect()->getPdo()->inTransaction(),'NO_TEST_OUTER_CREATE_TRANSACTION');$result=$service->create($uid,$key,$raw);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_independent_readback';audit_guard_assert();
    $proof=m4o_verify_service(audit_db(),$fixture,$input['request'],$input['idem_key'],$frame,$input['request']['options']['custom_form'],$result,$kind,$sqlBefore,null,$cancelSeconds);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_post_commit';$proof['post_commit']=m4o_post_commit_verify($app,audit_db(),$proof['order_id']);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_lookup_created';m4o_service_lookup($service,$uid,$key,$result['order_id']);
    $GLOBALS['m4o_phase']='service_'.$phaseKind.'_ordinary_replay';$before=m4o_facts(audit_db());
    $replay=$service->create($uid,$key,$raw);
    m4o_expect($replay===$result&&m4o_facts(audit_db())===$before,'SERVICE_NORMAL_REPLAY_SAME_ORDER_NO_DATA_WRITE');
    m4o_expect(!Db::connect()->getPdo()->inTransaction()&&$app->config->get('marketplace.trade.enabled')===false,'SERVICE_OWN_COMMIT_GATE_CLOSED');
    $proof+=['verified'=>true,'replay_unchanged'=>true,'lookup_created'=>true,
        'cancel_mode'=>$kind==='persistent'?'configured_hours':'disabled'];
    if($kind==='persistent')$proof+=['cart_consumed'=>true,'unselected_unchanged'=>true];
    else $proof+=['sql_cart_unchanged'=>true,'ledger_retained'=>true];
    $contexts[$kind]=['source_kind'=>$kind,'uid'=>$uid,'order_no'=>$result['order_id']];
    return $proof;
}

/** Change only this run's three original switches; real getters repopulate their exact keys. */
function m4o_membership_configuration(): void
{
    $keys=['member_func_status','member_card_status','member_price_status'];
    Db::transaction(static function(\think\db\ConnectionInterface $connection)use($keys):void{
        $pdo=m4o_business_pdo($connection);$now=time();
        foreach(['eb_system_user_level','eb_user_level','eb_member_right'] as $table)
            m4o_expect((int)m4o_rows($pdo,'SELECT COUNT(*) AS `n` FROM `'.$table.'`')[0]['n']===0,'FRESH_MEMBERSHIP_FIXTURE_TABLE');
        foreach($keys as $key){
            $rows=m4o_rows($pdo,'SELECT `id`,`value` FROM `eb_system_config` WHERE `menu_name` = ? FOR UPDATE',[$key]);
            m4o_expect(count($rows)===1&&$rows[0]['value']==='0','ACTUAL_MEMBERSHIP_CONFIGURATION_BEFORE');
            $statement=$pdo->prepare('UPDATE `eb_system_config` SET `value` = ? WHERE `id` = ? AND `menu_name` = ? AND `value` = ?');
            m4o_expect($statement instanceof PDOStatement,'ACTUAL_MEMBERSHIP_CONFIGURATION_PREPARE');
            foreach(['1',(string)$rows[0]['id'],$key,$rows[0]['value']] as $index=>$part)
                m4o_expect($statement->bindValue($index+1,$part,PDO::PARAM_STR),'ACTUAL_MEMBERSHIP_CONFIGURATION_BIND');
            m4o_expect($statement->execute()&&$statement->rowCount()===1,'ACTUAL_MEMBERSHIP_CONFIGURATION_UPDATE');
        }
        foreach(['vip_price'=>1,'express'=>80] as $type=>$number)
            m4o_insert($pdo,'eb_member_right',['right_type'=>$type,'title'=>'普通报价权益','number'=>$number,'status'=>1,'add_time'=>$now]);
        m4o_expect(m4o_business_pdo($connection)===$pdo,'MEMBERSHIP_CONFIGURATION_SAME_DEFAULT_PDO');
    });
    foreach($keys as $key){
        $rows=m4o_rows(audit_db(),'SELECT `value` FROM `eb_system_config` WHERE `menu_name` = ?',[$key]);
        m4o_expect(count($rows)===1&&$rows[0]['value']==='1','MEMBERSHIP_CONFIGURATION_INDEPENDENT_READBACK');
        m4o_expect(app()->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($key)===1,'MEMBERSHIP_ACTUAL_JSON_CONFIGURATION_GETTER');
        CacheService::delete('system_config_'.$key);
        m4o_expect(sys_config($key)==='1'&&CacheService::get('system_config_'.$key)==='1','MEMBERSHIP_ACTUAL_CONFIGURATION_GETTER');
    }
}

/** Existing tables and real eligibility writer only; no payment, member-order or card records. */
function m4o_membership_fixture(\think\App $app,string $case): array
{
    m4o_expect(in_array($case,['level','paid'],true),'ORDINARY_MEMBERSHIP_CASE');
    return Db::transaction(static function(\think\db\ConnectionInterface $connection)use($app,$case):array{
        $pdo=m4o_business_pdo($connection);$now=time();$kind=$case==='level'?'persistent':'temporary';
        $config=m4o_rows($pdo,'SELECT `id`,`value` FROM `eb_system_config` WHERE `menu_name` = ? FOR UPDATE',['order_cancel_time']);
        $previous=$kind==='persistent'?'0':'0.5';$hours=$kind==='persistent'?'0.5':'0';
        m4o_expect(count($config)===1&&$config[0]['value']===json_encode($previous,JSON_THROW_ON_ERROR),'MEMBERSHIP_CANCEL_CONFIGURATION_BEFORE');
        $statement=$pdo->prepare('UPDATE `eb_system_config` SET `value` = ? WHERE `id` = ? AND `menu_name` = ? AND `value` = ?');
        m4o_expect($statement instanceof PDOStatement,'MEMBERSHIP_CANCEL_CONFIGURATION_PREPARE');
        foreach([json_encode($hours,JSON_THROW_ON_ERROR),(string)$config[0]['id'],'order_cancel_time',$config[0]['value']] as $index=>$part)
            m4o_expect($statement->bindValue($index+1,$part,PDO::PARAM_STR),'MEMBERSHIP_CANCEL_CONFIGURATION_BIND');
        m4o_expect($statement->execute()&&$statement->rowCount()===1,'MEMBERSHIP_CANCEL_CONFIGURATION_UPDATE');
        $level=m4o_insert($pdo,'eb_system_user_level',['name'=>'普通九折等级','discount'=>'90.00','is_show'=>1,'is_del'=>0,
            'grade'=>1,'is_pay'=>0,'money'=>'0.00','valid_date'=>30,'is_forever'=>0,'add_time'=>$now]);
        m4o_expect(bccomp((string)$level['id'],'0',0)>0&&bccomp((string)$level['id'],'255',0)<=0,'NATIVE_LEVEL_ID_FITS_USER_COLUMN');
        $user=m4o_insert($pdo,'eb_user',['account'=>'ms4-'.getenv('AUDIT_RUN_ID').'-m-'.$case,'nickname'=>'会员价格验证顾客',
            'user_type'=>'h5','level'=>$level['id'],'is_money_level'=>0,'is_ever_level'=>0,'overdue_time'=>0,'add_time'=>$now],'uid');
        $userLevel=m4o_insert($pdo,'eb_user_level',['uid'=>$user['uid'],'level_id'=>$level['id'],'grade'=>1,'status'=>1,'is_del'=>0,
            'discount'=>90,'valid_time'=>bcadd((string)$now,'2592000',0),'is_forever'=>0,'add_time'=>$now]);
        $qualificationBefore=$qualificationAfter=$now;
        if($case==='paid'){
            $qualificationBefore=time();
            $changed=$app->make(UserServices::class)->setMemberOverdueTime(30,(int)$user['uid'],1,'month');
            $qualificationAfter=time();
            m4o_expect($changed instanceof \app\model\user\User&&(string)$changed->getData('is_money_level')==='1'
                &&(string)$changed->getData('is_ever_level')==='0','REAL_EXISTING_QUALIFICATION_WRITER');
            $rows=m4o_rows($pdo,'SELECT * FROM `eb_user` WHERE `uid` = ?',[$user['uid']]);
            m4o_expect(count($rows)===1,'QUALIFICATION_NATIVE_USER_READBACK');$user=$rows[0];
        }
        $merchant=m4o_insert($pdo,'eb_marketplace_merchant',['name'=>'会员价格验证店铺','review_status'=>1,'accept_orders'=>1,
            'fulfillment_enabled'=>1,'security_locked'=>0,'add_time'=>$now,'update_time'=>$now],'mer_id');
        $products=$skus=$selected=[];
        foreach(['甲','乙'] as $index=>$name){
            $forms=[['title'=>'同名资料','label'=>'text','status'=>true,'value'=>'']];
            if($index===0)$forms[]=['title'=>'同名资料','label'=>'number','status'=>false,'value'=>''];
            $product=m4o_insert($pdo,'eb_store_product',['mer_id'=>$merchant['mer_id'],'store_name'=>'会员价格商品'.$name,
                'unit_name'=>'件','spec_type'=>1,'stock'=>20,'sales'=>0,'price'=>'20.15','cost'=>'7.45','vip_price'=>'16.00',
                'postage'=>'2.67','freight'=>2,'temp_id'=>0,'is_show'=>1,'review_status'=>2,'is_vip'=>1,'logistics'=>'1','min_qty'=>1,
                'custom_form'=>json_encode($forms,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'add_time'=>$now]);
            $products[(int)$product['id']]=$product;
            m4o_insert($pdo,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>'甲,乙']);
            foreach([['suk'=>'甲','stock'=>8,'price'=>'20.15','vip_price'=>'16.00','cost'=>'7.45'],
                ['suk'=>'乙','stock'=>12,'price'=>'30.25','vip_price'=>'28.00','cost'=>'9.35']] as $position=>$data){
                $sku=m4o_insert($pdo,'eb_store_product_attr_value',$data+['product_id'=>$product['id'],'type'=>0,
                    'unique'=>bin2hex(random_bytes(4)),'sales'=>0,'is_show'=>1,'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00']);
                $skus[(int)$sku['id']]=$sku;if($position===$index)$selected[]=$sku;
            }
        }
        $plan=[['sku'=>$selected[0],'qty'=>2],['sku'=>$selected[0],'qty'=>1],['sku'=>$selected[1],'qty'=>2]];$carts=[];
        if($kind==='persistent')foreach($plan as $index=>$line)$carts[]=m4o_insert($pdo,'eb_store_cart',[
            'uid'=>$user['uid'],'type'=>'0','product_id'=>$line['sku']['product_id'],'product_attr_unique'=>$line['sku']['unique'],
            'cart_num'=>$line['qty'],'add_time'=>$now+$index,'is_pay'=>0,'is_del'=>0,'is_new'=>0,'status'=>1]);
        $unselected=m4o_insert($pdo,'eb_store_cart',['uid'=>$user['uid'],'type'=>'0','product_id'=>$selected[0]['product_id'],
            'product_attr_unique'=>$selected[0]['unique'],'cart_num'=>1,'add_time'=>$now+3,'is_pay'=>0,'is_del'=>0,'is_new'=>0,'status'=>1]);
        $address=m4o_insert($pdo,'eb_user_address',['uid'=>$user['uid'],'real_name'=>'内部验证','phone'=>'00000000000',
            'province'=>'验证省','city'=>'验证市','district'=>'验证区','detail'=>'专用内部验证地址','is_default'=>1,'add_time'=>$now]);
        $invoice=m4o_insert($pdo,'eb_user_invoice',['uid'=>$user['uid'],'header_type'=>1,'type'=>1,'name'=>'会员报价验证抬头','is_default'=>1,'add_time'=>$now]);
        m4o_expect(m4o_business_pdo($connection)===$pdo,'MEMBERSHIP_FIXTURE_SAME_DEFAULT_PDO');
        return compact('user','merchant','products','skus','plan','carts','unselected','address','invoice','hours','level','userLevel',
            'qualificationBefore','qualificationAfter','kind','case');
    });
}

/** Derive expectations from independent raw records, not any quote or saved order output. */
function m4o_membership_expected(\think\App $app,array $fixture): array
{
    $pdo=audit_db();$uid=(int)$fixture['user']['uid'];$case=$fixture['case'];
    m4o_expect(!$pdo->inTransaction()&&!Db::connect()->getPdo()->inTransaction()&&$pdo!==Db::connect()->getPdo(),'MEMBERSHIP_EXPECTATION_INDEPENDENT_PDO');
    $users=m4o_rows($pdo,'SELECT * FROM `eb_user` WHERE `uid` = ?',[$uid]);
    $levels=m4o_rows($pdo,'SELECT * FROM `eb_system_user_level` WHERE `id` = ?',[$fixture['level']['id']]);
    $userLevels=m4o_rows($pdo,'SELECT * FROM `eb_user_level` WHERE `uid` = ?',[$uid]);
    m4o_expect(count($users)===1&&count($levels)===1&&count($userLevels)===1,'MEMBERSHIP_NATIVE_QUALIFICATION_ROWS');
    $user=$users[0];$level=$levels[0];$userLevel=$userLevels[0];
    m4o_expect((string)$user['level']===(string)$level['id']&&(string)$userLevel['level_id']===(string)$level['id']
        &&(string)$userLevel['id']===(string)$fixture['userLevel']['id']&&$level['discount']==='90.00'
        &&(int)$level['is_show']===1&&(int)$level['is_del']===0&&(int)$userLevel['status']===1&&(int)$userLevel['is_del']===0
        &&(int)$userLevel['valid_time']>time(),'MEMBERSHIP_REAL_LEVEL_MAPPING_AND_VALIDITY');
    $model=$app->make(UserServices::class)->getUserInfo($uid);
    m4o_expect($model!==null,'MEMBERSHIP_REAL_QUALIFICATION_GETTER');
    foreach(['uid','level','is_money_level','is_ever_level','overdue_time'] as $field)
        m4o_expect((string)$model->getData($field)===(string)$user[$field],'MEMBERSHIP_GETTER_MATCHES_INDEPENDENT_USER');
    if($case==='paid')m4o_expect((int)$user['is_money_level']===1&&(int)$user['is_ever_level']===0
        &&bccomp((string)$user['overdue_time'],bcadd((string)$fixture['qualificationBefore'],'2592000',0),0)>=0
        &&bccomp((string)$user['overdue_time'],bcadd((string)$fixture['qualificationAfter'],'2592000',0),0)<=0
        &&bccomp((string)$user['overdue_time'],(string)time(),0)>0,'MEMBERSHIP_ACTUAL_SAVED_ACTIVE_QUALIFICATION');
    else m4o_expect((int)$user['is_money_level']===0&&(int)$user['is_ever_level']===0&&(string)$user['overdue_time']==='0',
        'MEMBERSHIP_REAL_LEVEL_ONLY_QUALIFICATION');
    foreach(['member_func_status','member_card_status','member_price_status'] as $key){
        $config=m4o_rows($pdo,'SELECT `value` FROM `eb_system_config` WHERE `menu_name` = ?',[$key]);
        m4o_expect(count($config)===1&&$config[0]['value']==='1'&&sys_config($key)==='1','MEMBERSHIP_REAL_CONFIGURATION_UNCHANGED');
    }
    $rights=[];foreach(['vip_price'=>1,'express'=>80] as $type=>$number){
        $rows=m4o_rows($pdo,'SELECT * FROM `eb_member_right` WHERE `right_type` = ?',[$type]);
        m4o_expect(count($rows)===1&&(int)$rows[0]['status']===1&&(int)$rows[0]['number']===$number,'MEMBERSHIP_REAL_SINGLE_RIGHT_ROW');
        $rights[$type]=$rows[0];
    }
    $factor=bcdiv($level['discount'],'100',2);$active=(int)$user['is_money_level']>0;
    $rate=$active?bcdiv((string)$rights['express']['number'],'100',4):'1.0000';
    $lines=$weights=[];$gross=$goods=$levelDiscount=$memberDiscount=$cost=$originalPostage=$chargedPostage='0.00';
    $targetPostage=$case==='paid'?['4.27','2.14','4.27']:['5.34','2.67','5.34'];
    foreach($fixture['plan'] as $index=>$line){
        $rows=m4o_rows($pdo,'SELECT * FROM `eb_store_product_attr_value` WHERE `id` = ?',[$line['sku']['id']]);
        m4o_expect(count($rows)===1,'MEMBERSHIP_NATIVE_EXPECTED_SKU');$sku=$rows[0];$qty=$line['qty'];
        $roots=m4o_rows($pdo,'SELECT * FROM `eb_store_product` WHERE `id` = ?',[$sku['product_id']]);
        m4o_expect(count($roots)===1&&(int)$roots[0]['is_vip']===1&&(int)$roots[0]['freight']===2
            &&$roots[0]['postage']==='2.67'&&(int)$roots[0]['mer_id']===(int)$fixture['merchant']['mer_id'],'MEMBERSHIP_NATIVE_EXPECTED_ROOT');
        m4o_expect($sku['price']===($index===2?'30.25':'20.15')&&$sku['vip_price']===($index===2?'28.00':'16.00')
            &&$sku['cost']===($index===2?'9.35':'7.45')&&$qty===($index===1?1:2),'MEMBERSHIP_EXACT_ORDINARY_PRICE_INPUTS');
        $levelUnit=bcmul($sku['price'],$factor,2);
        $useMember=$active&&bccomp($sku['vip_price'],'0.00',2)>0&&bccomp($sku['vip_price'],$levelUnit,2)<0;
        $unit=$useMember?$sku['vip_price']:$levelUnit;$type=$useMember?'member':'level';$saving=bcsub($sku['price'],$unit,2);
        $lineKey=$sku['id'].':'.$qty;m4o_expect(!isset($lines[$lineKey]),'MEMBERSHIP_DISTINCT_EXPECTED_SELECTION');
        $lines[$lineKey]=['base'=>$sku['price'],'vip'=>$sku['vip_price'],'unit'=>$unit,'saving'=>$saving,'type'=>$type,
            'cost'=>$sku['cost'],'postage'=>$targetPostage[$index]];
        $gross=bcadd($gross,bcmul($sku['price'],(string)$qty,2),2);$goods=bcadd($goods,bcmul($unit,(string)$qty,2),2);
        $discount=bcmul($saving,(string)$qty,2);
        if($type==='member')$memberDiscount=bcadd($memberDiscount,$discount,2);else $levelDiscount=bcadd($levelDiscount,$discount,2);
        $cost=bcadd($cost,bcmul($sku['cost'],(string)$qty,2),2);
        $linePostage=bcmul($roots[0]['postage'],(string)$qty,2);$originalPostage=bcadd($originalPostage,$linePostage,2);
        $weights[]=bcmul($linePostage,$rate,6);$chargedPostage=bcadd($chargedPostage,$targetPostage[$index],2);
    }
    $postage=bcmul($originalPostage,$rate,2);$budget=bcmul($postage,'100',0);$weightTotal='0.000000';$floorTotal='0';$remainders=[];
    foreach($weights as $weight)$weightTotal=bcadd($weightTotal,$weight,6);
    foreach($weights as $index=>$weight){
        $product=bcmul($budget,$weight,6);$floor=bcdiv($product,$weightTotal,0);$floorTotal=bcadd($floorTotal,$floor,0);
        $remainders[$index]=bcsub($product,bcmul($floor,$weightTotal,6),6);
        $expectedCents=bcadd($floor,$case==='paid'&&$index===1?'1':'0',0);
        m4o_expect($expectedCents===bcmul($targetPostage[$index],'100',0),'MEMBERSHIP_INDEPENDENT_WHOLE_CENT_ALLOCATION');
    }
    m4o_expect(bcsub($budget,$floorTotal,0)===($case==='paid'?'1':'0')
        &&($case!=='paid'||(bccomp($remainders[1],$remainders[0],6)>0&&bccomp($remainders[1],$remainders[2],6)>0))
        &&$postage===$chargedPostage,'MEMBERSHIP_INDEPENDENT_REMAINDER_AND_POSTAGE_BUDGET');
    $amounts=['goods_original'=>$gross,'goods_charged'=>$goods,'level_discount'=>$levelDiscount,'member_discount'=>$memberDiscount,
        'cost'=>$cost,'postage'=>$postage,'payable'=>bcadd($goods,$postage,2)];
    $target=$case==='paid'?['120.95','102.44','6.06','12.45','41.05','10.68','113.12']
        :['120.95','108.83','12.12','0.00','41.05','13.35','122.18'];
    m4o_expect(array_values($amounts)===$target&&$originalPostage==='13.35','MEMBERSHIP_INDEPENDENT_EXACT_AMOUNTS');
    return ['lines'=>$lines,'amounts'=>$amounts,'postage_discount'=>bcsub($originalPostage,$postage,2)];
}

/** Inspect the real shared confirmation and creation inputs against raw-record expectations. */
function m4o_membership_quote(array $group,array $expected): void
{
    $prices=$group['priceGroup'];$amounts=$expected['amounts'];
    foreach(['sumPrice'=>'goods_original','totalPrice'=>'goods_charged','costPrice'=>'cost','levelPrice'=>'level_discount',
        'memberPrice'=>'member_discount','storePostage'=>'postage'] as $field=>$key)
        m4o_expect($prices[$field]===$amounts[$key],'MEMBERSHIP_SHARED_QUOTE_EXACT_AMOUNTS');
    m4o_expect($prices['vipPrice']===bcadd($amounts['level_discount'],$amounts['member_discount'],2)
        &&$prices['storePostageDiscount']===$expected['postage_discount'],'MEMBERSHIP_SHARED_QUOTE_DISCOUNT_CLASSIFICATION');
    m4o_expect(count($group['cartInfo'])===3,'MEMBERSHIP_SHARED_QUOTE_THREE_LINES');$seen=[];
    foreach($group['cartInfo'] as $cart){
        $key=$cart['productInfo']['attrInfo']['id'].':'.$cart['cart_num'];$line=$expected['lines'][$key]??null;
        m4o_expect(is_array($line)&&!isset($seen[$key])&&$cart['sum_price']===$line['base']&&$cart['truePrice']===$line['unit']
            &&$cart['vip_truePrice']===$line['saving']&&$cart['price_type']===$line['type']&&$cart['costPrice']===$line['cost'],
            'MEMBERSHIP_SHARED_QUOTE_TRUE_PRICES');$seen[$key]=true;
    }
    m4o_expect(count($seen)===count($expected['lines']),'MEMBERSHIP_SHARED_QUOTE_COMPLETE_SELECTION');
}

function m4o_membership_case(\think\App $app,string $case): array
{
    $GLOBALS['m4o_phase']='membership_'.$case.'_fixtures';$fixture=m4o_membership_fixture($app,$case);$kind=$fixture['kind'];
    $uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];$addressId=(int)$fixture['address']['id'];
    $expected=m4o_membership_expected($app,$fixture);
    $sqlBefore=m4o_rows(audit_db(),'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);$refs=[];
    if($kind==='temporary'){
        $GLOBALS['m4o_phase']='membership_paid_actual_cart_producer';$cartService=$app->make(StoreCartServices::class);
        foreach($fixture['plan'] as $line){
            $sku=$line['sku'];$ref=$cartService->setCart($uid,(int)$sku['product_id'],$line['qty'],$sku['unique'],0,true);
            m4o_expect(is_string($ref)&&MarketplaceOrderInput::reference($ref,'temporary')===$ref&&!in_array($ref,$refs,true),'MEMBERSHIP_ACTUAL_TEMPORARY_REFERENCE');
            $payload=CacheService::get($ref);
            m4o_expect(is_array($payload)&&($payload['catalog_cart_version']??null)===1&&($payload['uid']??null)===$uid
                &&($payload['cartData']['id']??null)===$ref&&($payload['cartData']['uid']??null)===$uid
                &&($payload['cartData']['product_id']??null)===(int)$sku['product_id']
                &&($payload['cartData']['product_attr_unique']??null)===$sku['unique']&&($payload['cartData']['cart_num']??null)===$line['qty'],
                'MEMBERSHIP_ACTUAL_TEMPORARY_CACHE_READBACK');$refs[]=$ref;
        }
        m4o_expect(m4o_rows(audit_db(),'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid])===$sqlBefore,'MEMBERSHIP_TEMPORARY_PRODUCER_NO_SQL_CHANGE');
    }else $refs=array_map(static fn(array $row):string=>(string)$row['id'],$fixture['carts']);
    $GLOBALS['m4o_phase']='membership_'.$case.'_actual_confirmation';
    $choice=MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>implode(',',$refs),'addressId'=>$addressId,'new'=>$kind==='temporary']);
    $user=$app->make(UserServices::class)->getUserInfo($uid);m4o_expect($user!==null&&(int)$user->getData('uid')===$uid,'MEMBERSHIP_REAL_USER_MODEL');
    $orders=$app->make(StoreOrderServices::class);
    $confirmation=$orders->getOrderConfirmData($user->toArray(),$choice['cartId'],$choice['new'],$addressId,1,0,$choice);
    $key=$confirmation['orderKey'];$cached=CacheService::get('user_order_'.$uid.$key);
    m4o_expect(is_string($key)&&is_array($cached),'MEMBERSHIP_REAL_CONFIRMATION_CACHE');$frame=MarketplaceCartSelection::frame($cached,$uid);
    m4o_expect($frame['mer_id']===$mer&&$frame['source_kind']===$kind&&$frame['cart_refs']===$choice['cart_refs']
        &&count($frame['selection'])===3&&count($frame['custom_form'])===2,'MEMBERSHIP_ACTUAL_MULTI_PRODUCT_FRAME');
    m4o_membership_quote($confirmation,$expected);m4o_membership_quote($cached,$expected);
    m4o_expect(($confirmation['userInfo']['vip']??null)===true
        &&(string)($confirmation['userInfo']['vip_id']??'')===(string)$fixture['userLevel']['id']
        &&($confirmation['userInfo']['discount']??null)===90,'MEMBERSHIP_CONFIRMATION_REAL_LEVEL_METADATA');
    $answers=[];foreach($frame['custom_form'] as $group){
        $values=[];foreach($group['fields'] as $field)$values[]=['field_index'=>$field['field_index'],
            'value'=>$field['label']==='number'?'0':'会员报价填写'.$group['product_name']];
        $answers[]=['product_id'=>$group['product_id'],'definition_sha256'=>$group['definition_sha256'],'values'=>$values];
    }
    $raw=['mer_id'=>$mer,'cartId'=>$choice['cartId'],'new'=>$choice['new'],'addressId'=>$addressId,
        'invoice_id'=>(int)$fixture['invoice']['id'],'idem_key'=>'membership-'.bin2hex(random_bytes(16)),
        'payType'=>'','mark'=>'会员价格普通验证','gift_mark'=>'普通文本','custom_form'=>$answers];
    $input=MarketplaceOrderInput::create($uid,$key,$raw);$service=$app->make(MarketplaceOrderCreateServices::class);
    $GLOBALS['m4o_phase']='membership_'.$case.'_lookup_before';m4o_service_lookup($service,$uid,$key,null);
    $GLOBALS['m4o_phase']='membership_'.$case.'_actual_creation';
    m4o_expect(!Db::connect()->getPdo()->inTransaction(),'MEMBERSHIP_NO_TEST_OUTER_TRANSACTION');$result=$service->create($uid,$key,$raw);
    $GLOBALS['m4o_phase']='membership_'.$case.'_independent_readback';audit_guard_assert();
    $proof=m4o_verify_service(audit_db(),$fixture,$input['request'],$input['idem_key'],$frame,$input['request']['options']['custom_form'],
        $result,$kind,$sqlBefore,$expected);
    $GLOBALS['m4o_phase']='membership_'.$case.'_post_commit';$proof['post_commit']=m4o_post_commit_verify($app,audit_db(),$proof['order_id']);
    $actual=m4o_rows(audit_db(),'SELECT `total_price`,`cost`,`pay_postage`,`total_postage`,`pay_price` FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',
        [$uid,$result['order_id']]);$amounts=$expected['amounts'];
    m4o_expect(count($actual)===1&&$actual[0]['total_price']===$amounts['goods_charged']&&$actual[0]['cost']===$amounts['cost']
        &&$actual[0]['pay_postage']===$amounts['postage']&&$actual[0]['total_postage']===$amounts['postage']
        &&$actual[0]['pay_price']===$amounts['payable'],'MEMBERSHIP_SAVED_ORDER_INDEPENDENT_EXACT_AMOUNTS');
    m4o_expect(m4o_membership_expected($app,$fixture)===$expected,'MEMBERSHIP_QUALIFICATION_AND_RAW_INPUTS_UNCHANGED');
    $GLOBALS['m4o_phase']='membership_'.$case.'_lookup_created';m4o_service_lookup($service,$uid,$key,$result['order_id']);
    $GLOBALS['m4o_phase']='membership_'.$case.'_ordinary_replay';$before=m4o_facts(audit_db());$replay=$service->create($uid,$key,$raw);
    m4o_expect($replay===$result&&m4o_facts(audit_db())===$before,'MEMBERSHIP_NORMAL_REPLAY_NO_WRITE');
    m4o_expect(!Db::connect()->getPdo()->inTransaction()&&$app->config->get('marketplace.trade.enabled')===false,'MEMBERSHIP_COMMITTED_GATE_CLOSED');
    $proof+=['verified'=>true,'prices_verified'=>true,'qualification_verified'=>true,'amounts'=>$amounts,
        'replay_unchanged'=>true,'lookup_created'=>true,'source_kind'=>$kind,'cancel_mode'=>$kind==='persistent'?'configured_hours':'disabled'];
    if($kind==='persistent')$proof+=['cart_consumed'=>true,'unselected_unchanged'=>true];
    else $proof+=['sql_cart_unchanged'=>true,'ledger_retained'=>true];
    return $proof;
}

function m4o_main(): int
{
    $phase = 'source'; $evidence = ['schema'=>1,'status'=>'FAIL','scope'=>'NORMAL_ORDER_INTERNAL_ONLY','binding'=>null,
        'HTTP_authentication_creation_UI_DOM_actual_payment_refund_template_freight'=>'NOT RUN',
        'cancel_wallclock_daemon_and_crossprocess_payment'=>'NOT RUN',
        'post_commit_wallclock_daemon'=>'NOT RUN',
        'stopped_cases'=>'NOT RUN','private_credentials_emitted'=>false]; $included = [];
    $GLOBALS['m4o_phase'] =& $phase;
    try {
        ini_set('display_errors','0'); ini_set('log_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
        set_error_handler(static function (): never { throw new RuntimeException('PHP_RUNTIME_WARNING'); });
        $evidence['binding'] = m4o_source($included); audit_guard_assert();
        $phase = 'prerequisites'; $self = m4o_prerequisites();
        $phase = 'app_services'; $app = m4o_app();
        $phase = 'actual_fixtures'; $fixture = m4o_fixture();
        $cartQuery = (new \app\model\order\StoreCart())->db();
        $nativeTypes = $cartQuery->getConnection()->getFieldsType($cartQuery->getTable());
        m4o_expect($cartQuery->getFieldBindType('id') === PDO::PARAM_STR
            && $cartQuery->getFieldsType() === array_replace($nativeTypes,['id'=>'string']),'ACTUAL_CART_FULL_TYPE_MAP_STRING_ID');
        $marketplace = $app->config->get('marketplace'); $marketplace['enabled'] = true; $marketplace['self_operated_mer_id'] = $self;
        $app->config->set($marketplace,'marketplace'); m4o_expect($app->config->get('marketplace.trade.enabled') === false,'CREATE_GATE_REMAINS_CLOSED');
        $uid = (int)$fixture['user']['uid']; $mer = (int)$fixture['merchant']['mer_id']; $addressId = (int)$fixture['address']['id'];
        $refs = array_map(static fn(array $row): string => (string)$row['id'],$fixture['carts']);
        $choice = MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>implode(',',$refs),'addressId'=>$addressId]);
        $userModel = $app->make(UserServices::class)->getUserInfo($uid); m4o_expect($userModel !== null && (int)$userModel->getData('uid') === $uid,'ACTUAL_USER_MODEL');
        $user = $userModel->toArray(); $orders = $app->make(StoreOrderServices::class); $dao = $app->make(MarketplaceOrderDao::class);
        $phase = 'actual_confirmation'; $confirmation = $orders->getOrderConfirmData($user,$choice['cartId'],false,$addressId,1,0,$choice);
        $key = $confirmation['orderKey']; m4o_expect(is_string($key) && $key !== '' && CacheService::has('user_order_'.$uid.$key),'ACTUAL_CONFIRMATION_CACHE');
        $group = $orders->getCacheOrderInfo($uid,$key); $frame = MarketplaceCartSelection::frame($group,$uid);
        m4o_expect($frame['mer_id'] === $mer && $frame['cart_refs'] === $choice['cart_refs'] && count($frame['selection']) === 3,'REAL_CAPTURED_FRAME');
        $idem = 'normal-'.bin2hex(random_bytes(16));
        $input = MarketplaceOrderInput::create($uid,$key,['mer_id'=>$mer,'cartId'=>$choice['cartId'],'addressId'=>$addressId,
            'invoice_id'=>(int)$fixture['invoice']['id'],'idem_key'=>$idem,'payType'=>'','mark'=>'普通订单验证','gift_mark'=>'普通文本快照']);
        $phase = 'atomic_dao_creation';
        $created = Db::transaction(static function (\think\db\ConnectionInterface $transactionConnection) use ($app,$dao,$orders,$uid,$mer,$frame,$addressId,$fixture,$input,$key,$idem,$user): array {
            $pdo = m4o_business_pdo($transactionConnection); $now = time(); $claim = $dao->claim($pdo,$input['request'],$idem,$now,$now+3600);
            m4o_expect($claim['claimed'] === true && $claim['order'] === null,'REAL_FIRST_CLAIM');
            $locked = $dao->lockInputs($pdo,$uid,$mer,$frame,$addressId,(int)$fixture['invoice']['id']);
            $group = $orders->getCacheOrderInfo($uid,$key); $current = MarketplaceCartSelection::frame($group,$uid);
            m4o_expect($current === $frame && m4o_business_pdo($transactionConnection) === $pdo,'LOCKED_ACTUAL_FRAME');
            $app->make(UserInvoiceServices::class)->checkInvoice((int)$fixture['invoice']['id'],$uid);
            $computed = $app->make(StoreOrderComputedServices::class)->setParamData([])->computedOrder($uid,$user,$group,$addressId,'',false,0,true,1,0,true);
            m4o_expect(m4o_business_pdo($transactionConnection) === $pdo && bccomp($computed['pay_postage'],'0.00',2) > 0,'ACTUAL_LOCKED_SHARED_QUOTE');
            $GLOBALS['m4o_address_projection'] = MarketplaceOrderLockedQuote::addressProjection($locked['address'],$group['addr'] ?? null);
            $quote = MarketplaceOrderLockedQuote::verify($uid,$mer,$addressId,$locked,$group,$computed,'m4o_expect');
            $cartInfo = $quote['cartInfo']; $address = $quote['address'];
            $customForm = MarketplaceOrderCustomForm::snapshot($frame['custom_form'],$input['request']['options']['custom_form'],
                $locked['products'],$locked['selection'],$locked['source_kind']);
            $payload = ['uid'=>$uid,'mer_id'=>$mer,'order_id'=>$app->make(StoreOrderCreateServices::class)->getNewOrderId('cp'),
                'unique'=>$key,'cart_id'=>$locked['cart_refs'],'total_num'=>array_sum(array_column($locked['selection'],'qty')),
                'total_price'=>$group['priceGroup']['totalPrice'],'total_postage'=>$computed['pay_postage'],'pay_price'=>$computed['pay_price'],
                'pay_postage'=>$computed['pay_postage'],'cost'=>$group['priceGroup']['costPrice'],'add_time'=>$now,
                'real_name'=>$address['real_name'],'user_phone'=>$address['phone'],
                'user_address'=>$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail'],
                'mark'=>$input['request']['options']['mark'],'gift_mark'=>$input['request']['options']['gift_mark'],'pay_type'=>'','channel_type'=>$user['user_type'],
                'province'=>'','custom_form'=>$customForm,'merchant_name_snapshot'=>$locked['merchant']['name'],
                'merchant_after_sale_contact_snapshot'=>$locked['merchant']['after_sale_contact']];
            $id = $dao->insertOrder($pdo,$payload,$locked['invoice']); $reserves = $dao->reserve($pdo,$uid,$mer,$id,$locked,$now);
            $items = $dao->insertItems($pdo,$uid,$mer,$id,$cartInfo,$reserves); $dao->markCreated($pdo,$uid,$claim['idem_key'],$id,$locked);
            m4o_expect(m4o_business_pdo($transactionConnection) === $pdo && count($items) === 3 && count($reserves) === 2,'SIX_DAO_BOUNDARIES_SAME_PDO');
            return ['order_id'=>$id,'key'=>$key,'idem'=>$idem,'request'=>$input['request'],'payload'=>$payload];
        });
        $phase = 'independent_committed_facts'; audit_guard_assert(); $independent = audit_db();
        m4o_expect(!$independent->inTransaction() && !Db::connect()->getPdo()->inTransaction() && $independent !== Db::connect()->getPdo(),'COMMITTED_INDEPENDENT_CONNECTION');
        $evidence['committed'] = m4o_verify($independent,$fixture,$created); $before = m4o_facts($independent);
        $phase = 'ordinary_same_request_replay';
        $replay = Db::transaction(static function (\think\db\ConnectionInterface $transactionConnection) use ($dao,$input,$idem): array {
            $pdo = m4o_business_pdo($transactionConnection); $now = time(); return $dao->claim($pdo,$input['request'],$idem,$now,$now+3600);
        });
        m4o_expect($replay['claimed'] === false && (int)$replay['order']['id'] === $created['order_id'] && $replay['idem_key'] === $idem,'ACTUAL_SAME_ORDER_REPLAY');
        m4o_expect(m4o_facts(audit_db()) === $before,'REPLAY_NO_ORDER_STOCK_ITEM_OR_CART_WRITE');
        $phase='post_commit_registered_handler';$evidence['post_commit_schedule']=m4o_post_commit_schedule($app,$independent,$created['order_id']);
        $contexts=[];
        $evidence['service_creations']['persistent'] = m4o_service_case($app,'persistent',$contexts);
        $evidence['service_creations']['temporary'] = m4o_service_case($app,'temporary',$contexts);
        $phase='membership_configuration';m4o_membership_configuration();
        $evidence['membership_creations']['level']=m4o_membership_case($app,'level');
        $evidence['membership_creations']['paid']=m4o_membership_case($app,'paid');
        $phase='saved_reads';$evidence['saved_reads']=m4o_saved_order_normal($app,audit_db(),$contexts);
        $phase='fulfillment_plans';$evidence['fulfillment_plans']=m4o_fulfillment_plan_normal($independent,$evidence['committed'],$evidence['service_creations'],$evidence['membership_creations']);
        $phase='ordinary_cancel';$evidence['cancellations']=m4o_cancel_normal($app,audit_db(),$evidence['service_creations'],$evidence['membership_creations']);
        $phase='merchant_saved_reads';$evidence['merchant_reads']=m4o_merchant_read_normal($app,audit_db(),$evidence['service_creations'],$evidence['cancellations']);
        $phase = 'final_source'; $finalSet = []; m4o_expect(m4o_source($finalSet) === $evidence['binding'] && $finalSet === $included,'FINAL_SOURCE_UNCHANGED');
        foreach (get_included_files() as $file) m4o_expect(isset($included[realpath($file)]),'ACTUAL_INCLUDED_SOURCE_CLOSED');
        audit_guard_assert(); m4o_expect($app->config->get('marketplace.trade.enabled') === false,'FINAL_GATE_CLOSED');
        $evidence['included_files'] = count(get_included_files()); $evidence['replay_unchanged'] = true;
        $evidence['status'] = 'VERIFIED_NORMAL_ORDER_INTERNAL';
    } catch (Throwable $error) {
        $evidence['diagnostic'] = ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
            'assertion_code'=>$GLOBALS['m4o_failed_assertion'] ?? ($error instanceof RuntimeException && $error->getMessage() === 'PHP_RUNTIME_WARNING' ? 'PHP_RUNTIME_WARNING' : 'UNCLASSIFIED_APPLICATION_FAILURE')];
    }
    $evidence['phase'] = $phase; $evidence['assertions'] = $GLOBALS['m4o_assertions'] ?? 0;
    $evidence['assertions_by_phase'] = $GLOBALS['m4o_phase_assertions'] ?? [];
    $evidence['pdo_observations'] = $GLOBALS['m4o_pdo_observations'] ?? [];
    $evidence['address_projection'] = $GLOBALS['m4o_address_projection'] ?? null;
    $evidence['native_exit'] = $evidence['status'] === 'VERIFIED_NORMAL_ORDER_INTERNAL' ? 0 : 1;
    try { m4o_new(audit_run_dir().'/evidence/ms4-order-normal.json',$evidence); }
    catch (Throwable $error) { echo m4o_json(['status'=>'FAIL','scope'=>'NORMAL_ORDER_INTERNAL_ONLY','native_exit'=>1,'phase'=>'receipt_write']); return 1; }
    echo m4o_json(['status'=>$evidence['status'],'scope'=>'NORMAL_ORDER_INTERNAL_ONLY','native_exit'=>$evidence['native_exit'],'phase'=>$phase,
        'assertions'=>$evidence['assertions'],'evidence_ref'=>'evidence/ms4-order-normal.json','HTTP_acceptance'=>'NOT RUN']);
    return $evidence['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(m4o_main());
