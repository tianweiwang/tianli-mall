<?php
declare(strict_types=1);

// Install as isolated/ms0b-bootstrap.php before running. No framework,
// database, Redis or transport stubs; every worker uses the actual application.
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/fault.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');

class BootstrapAssertionFailure extends RuntimeException {}
function bs_check(bool $condition, string $literal): void
{
    if (!$condition) { throw new BootstrapAssertionFailure($literal); }
}
function bs_emit(string $stage, array $data = []): void
{
    echo json_encode(['stage' => $stage] + $data, JSON_THROW_ON_ERROR) . "\n";
    flush();
}
function bs_app(): \think\App
{
    require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
    $private = audit_config();
    bs_check(is_array($private['auth'] ?? null), 'isolated deployment auth configuration must exist');
    $app = new \think\App(audit_repo_dir() . '/crmeb/');
    $app->env->set([
        'APP_DEBUG' => false, 'APP_APP_KEY' => $private['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
        'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
        'DATABASE_PASSWORD' => $private['database']['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
        'REDIS_REDIS_PASSWORD' => $private['redis_password'],
        'CACHE_CACHE_PREFIX' => 'c:', 'CACHE_CACHE_TAG_PREFIX' => 'CRMEB:',
        'AUTH_SESSION_EPOCH' => $private['auth']['session_epoch'],
        'AUTH_STAMP_KEY' => $private['auth']['stamp_key'], 'AUTH_ISSUER' => $private['auth']['issuer'],
    ]);
    return $app;
}
function bs_layout(string $mode, string $execution): array
{
    bs_check(preg_match('/^[0-9]{14}[a-f0-9]{6}$/D', $execution) === 1, 'test execution identity must be exact');
    $named = str_starts_with($mode, 'named-');
    $prefix = $named ? 'audit:bootstrap-named:' . getenv('AUDIT_RUN_ID') . ':' : 'c:';
    $logical = 'audit:bootstrap:' . getenv('AUDIT_RUN_ID') . ':' . $execution . ':' . $mode . ':';
    $tag = $logical . 'tag';
    return ['prefix' => $prefix, 'first' => $logical . 'k1', 'second' => $logical . 'k2', 'tag' => $tag,
        'physical' => [$prefix . $logical . 'k1', $prefix . $logical . 'k2', $prefix . 'CRMEB:' . md5($tag)]];
}
function bs_native_options(Redis $redis): array
{
    $options = [];
    foreach (['OPT_PREFIX', 'OPT_SERIALIZER', 'OPT_READ_TIMEOUT', 'OPT_MAX_RETRIES',
        'OPT_BACKOFF_ALGORITHM', 'OPT_BACKOFF_BASE', 'OPT_BACKOFF_CAP'] as $name) {
        if (defined('Redis::' . $name)) { $options[$name] = $redis->getOption(constant('Redis::' . $name)); }
    }
    return $options;
}
function bs_cache_context(\think\App $app, string $mode, array $layout): array
{
    $manager = $app->cache;
    $selected = 'redis';
    $db = $app->db;
    $defaultNative = null; $defaultOptions = null;
    if (str_starts_with($mode, 'named-')) {
        // Exercise the actual resolved Db::__make named-store contract, not a
        // future wrapper constructor. Config is real and uses this same Redis.
        $cache = $app->config->get('cache');
        $cache['stores']['bootstrap_named'] = $cache['stores']['redis'];
        $cache['stores']['bootstrap_named']['prefix'] = $layout['prefix'];
        $app->config->set(['cache' => $cache]);
        $defaultNative = $manager->store('redis')->handler();
        $defaultOptions = bs_native_options($defaultNative);
        $database = $app->config->get('database');
        $database['cache_store'] = 'bootstrap_named';
        $app->config->set(['database' => $database]);
        $class = get_class($db);
        $db = $class::__make($app->event, $app->config, $app->log, $manager);
        bs_check($db->getConfig('cache_store') === 'bootstrap_named', 'real Db factory must select the named store');
        $selected = 'bootstrap_named';
    }
    $ormCache = $db->connect()->getCache();
    bs_check($ormCache instanceof \Psr\SimpleCache\CacheInterface, 'ORM cache must preserve the PSR16 contract');
    $driver = $manager->store($selected);
    $native = $driver->handler();
    bs_check($native instanceof Redis && $ormCache->handler() === $native,
        'ORM and original selected cache must share the actual native connection');
    bs_check($driver->getCacheKey($layout['first']) === $layout['physical'][0]
        && $driver->getCacheKey($layout['second']) === $layout['physical'][1]
        && $driver->getCacheKey($driver->getTagKey($layout['tag'])) === $layout['physical'][2],
        'selected shared driver must preserve physical prefix and tag layout');
    return ['db' => $db, 'orm' => $ormCache, 'driver' => $driver, 'native' => $native,
        'options' => bs_native_options($native), 'class' => get_class($ormCache),
        'default_native' => $defaultNative, 'default_options' => $defaultOptions, 'manager' => $manager];
}
function bs_assert_physical(string $key, $value): void
{
    $redis = audit_redis();
    $encoded = is_numeric($value) ? (string)$value : serialize($value);
    bs_check($redis->get($key) === $encoded, 'independent observer must see original physical serialization');
    $ttl = $redis->ttl($key);
    bs_check(is_int($ttl) && $ttl > 0 && $ttl <= 20, 'actual Redis expiry must preserve explicit PSR16 TTL');
}
function bs_child(string $mode, string $execution): never
{
    $facts = [];
    try {
        $app = bs_app(); $layout = bs_layout($mode, $execution);
        // No cache.store/handler observer call occurs before this barrier.
        bs_emit('READY');
        bs_check(trim((string)fgets(STDIN)) === 'GO', 'real worker requires the parent start barrier');
        $started = microtime(true);
        $app->initialize();
        $bootMs = round((microtime(true) - $started) * 1000, 3);
        bs_emit('BOOT_DONE', ['elapsed_ms' => $bootMs, 'db_class' => get_class($app->db)]);
        if ($mode === 'paused-bootstrap') {
            $factoryStarted = microtime(true); $error = null;
            try { \crmeb\services\auth\AuthSessionStore::fromConfig(); }
            catch (Throwable $caught) { $error = $caught; }
            $factoryMs = round((microtime(true) - $factoryStarted) * 1000, 3);
            $facts = ['bootstrap_elapsed_ms' => $bootMs, 'factory_elapsed_ms' => $factoryMs,
                'factory_exception_class' => $error ? get_class($error) : null,
                'factory_code' => $error ? (int)$error->getCode() : 0,
                'factory_previous_absent' => $error !== null && $error->getPrevious() === null,
                'factory_fixed_message' => $error !== null && $error->getMessage() === '认证服务暂不可用'];
            bs_check($bootMs < 1000, 'bootstrap must finish while Redis remains paused within one second');
            bs_check($error instanceof \crmeb\exceptions\AuthUnavailableException && $error->getCode() === 503
                && $error->getPrevious() === null && $error->getMessage() === '认证服务暂不可用',
                'first actual auth dependency access must return controlled 503');
            bs_check($factoryMs >= 500 && $factoryMs < 2500, 'paused auth read must fail at its bounded timeout');
        } elseif ($mode === 'healthy-lazy-bootstrap') {
            // Inspect existing manager bookkeeping without resolving a driver.
            // This standard framework state makes a previously eager native
            // driver visible without creating the observer's own connection.
            $drivers = (new ReflectionProperty(\think\Manager::class, 'drivers'))->getValue($app->cache);
            $connection = $app->db->connect();
            $facts = ['cache_driver_count_before_operations' => count($drivers),
                'orm_query_count_before_operations' => $app->db->getQueryTimes(),
                'pdo_handle_absent_before_operations' => $connection->getPdo() === false];
            bs_check($drivers === [] && $app->db->getQueryTimes() === 0 && $connection->getPdo() === false,
                'bootstrap must not open a shared cache driver or execute ORM queries');
        } elseif ($mode === 'db-entrypoint-identity') {
            $db = $app->db;
            $modelDb = (new ReflectionProperty(\think\Model::class, 'db'))->getValue();
            bs_check($app->make('db') === $db && $app->make(\think\Db::class) === $db
                && $app->make(\think\DbManager::class) === $db
                && \think\facade\Db::instance() === $db && $modelDb === $db,
                'all actual framework and Model Db entrypoints must resolve one instance');
            $model = new \app\model\system\admin\SystemAdmin();
            bs_check($model->db()->getConnection() === $db->connect(),
                'the real application Model must retain the same Db connection');
            $facts = ['all_entrypoints_same_instance' => true, 'db_class' => get_class($db)];
        } else {
            bs_check(in_array($mode, ['default-psr16', 'named-psr16', 'default-tag', 'named-tag', 'orm-cache-read'], true),
                'unknown test operation must be rejected');
            $context = bs_cache_context($app, $mode, $layout);
            $orm = $context['orm']; $driver = $context['driver'];
            if (str_ends_with($mode, '-psr16')) {
                $value = ['compatibility' => 'shared', 'integer' => 7, 'boolean' => true];
                bs_check($orm->set($layout['first'], $value, 20) === true
                    && $orm->has($layout['first']) === true
                    && $orm->get($layout['first']) === $value
                    && $driver->get($layout['first']) === $value, 'ORM PSR16 must delegate actual set/get/has');
                bs_assert_physical($layout['physical'][0], $value);
                bs_check($driver->set($layout['second'], 'reverse-cache', 20) === true
                    && $orm->get($layout['second']) === 'reverse-cache'
                    && $orm->delete($layout['second']) === true && $driver->has($layout['second']) === false,
                    'original shared writes and ORM deletion must interoperate');
                bs_check($orm->get($layout['second'], 'missing-default') === 'missing-default',
                    'PSR16 missing-key default must retain original behavior');
            } elseif (str_ends_with($mode, '-tag')) {
                bs_check($orm->set($layout['second'], 'untagged-keeper', 20) === true, 'untagged control must persist');
                $value = ['tagged' => 'shared-cache'];
                $tag = $orm->tag($layout['tag']);
                bs_check($tag->set($layout['first'], $value, 20) === true, 'actual ORM tag write must persist');
                bs_assert_physical($layout['physical'][0], $value);
                bs_check(audit_redis()->type($layout['physical'][2]) === Redis::REDIS_SET
                    && audit_redis()->sMembers($layout['physical'][2]) === [$layout['physical'][0]],
                    'actual tag must reference exactly the owned physical key');
                bs_check($tag->clear() === true && $driver->has($layout['first']) === false
                    && audit_redis()->exists($layout['physical'][2]) === 0
                    && $orm->get($layout['second']) === 'untagged-keeper',
                    'clearing the owned tag must preserve untagged shared data');
            } else {
                $fixtures = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
                $id = (int)$fixtures['admin_super']['id'];
                $expectedAccount = (string)$fixtures['admin_super']['account']; unset($fixtures);
                $db = $context['db']; $queryBefore = $db->getQueryTimes();
                $first = $db->name('system_admin')->where('id', $id)->cache($layout['first'], 20)->value('account');
                $queryAfterFirst = $db->getQueryTimes();
                $second = \think\facade\Db::name('system_admin')->where('id', $id)->cache($layout['first'], 20)->value('account');
                bs_check($first === $expectedAccount && $second === $first && $queryAfterFirst > $queryBefore
                    && $db->getQueryTimes() === $queryAfterFirst, 'real ORM cache hit must reuse the original query result without another SQL');
                bs_assert_physical($layout['physical'][0], $first);
            }
            bs_check(bs_native_options($context['native']) === $context['options']
                && $orm->handler() === $context['native'] && $driver->handler() === $context['native'],
                'ORM cache operations must not replace the native client or change native flags');
            if (str_starts_with($mode, 'named-')) {
                bs_check(audit_redis()->exists('c:' . $layout['first']) === 0
                    && audit_redis()->exists('c:' . $layout['second']) === 0,
                    'named ORM cache must not write into the default physical namespace');
                bs_check($context['manager']->getDefaultDriver() === 'redis'
                    && $context['manager']->store('redis')->handler() === $context['default_native']
                    && bs_native_options($context['default_native']) === $context['default_options'],
                    'named ORM cache must preserve the original default store client and options');
            }
            $facts = ['orm_cache_class' => $context['class'], 'shared_native_identity_preserved' => true,
                'native_options_before_sha256' => hash('sha256', json_encode($context['options'], JSON_THROW_ON_ERROR)),
                'native_options_after_sha256' => hash('sha256', json_encode(bs_native_options($context['native']), JSON_THROW_ON_ERROR)),
                'physical_serialization_and_ttl_verified' => true];
        }
        bs_emit('DONE', ['success' => true, 'observations' => $facts]);
    } catch (Throwable $error) {
        bs_emit('DONE', ['success' => false, 'exception_class' => get_class($error), 'code' => (int)$error->getCode(),
            'assertion' => $error instanceof BootstrapAssertionFailure ? $error->getMessage() : null, 'observations' => $facts]);
    }
    exit(0);
}
if (($argv[1] ?? '') === '--child') { bs_child($argv[2] ?? '', $argv[3] ?? ''); }

$GLOBALS['bs_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'snapshot table scope rejected');
    $GLOBALS['bs_spec']['tables'][$table] = null;
}
$GLOBALS['bs_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
function bs_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['bs_spec']); $snapshot['expires'] = [];
    $keys = array_keys($snapshot['redis']['*']);
    if ($keys === []) { return $snapshot; }
    $script = "local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local n=redis.call('PTTL',k); if n>=0 then r[i]=now+n else r[i]=n end end; return r";
    $deadlines = audit_redis()->eval($script, $keys, count($keys));
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'full absolute expiry observation required');
    foreach ($keys as $index => $key) {
        audit_expect(is_int($deadlines[$index]), 'absolute expiry must be an integer');
        $snapshot['expires'][$key] = $deadlines[$index];
    }
    return $snapshot;
}
function bs_diff(array $before, array $after, array $owned = []): array
{
    $diff = ['db' => [], 'cache' => []];
    foreach ($before['tables'] as $table => $value) {
        if ($value !== ($after['tables'][$table] ?? null)) { $diff['db'][] = $table; }
    }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $owned, true)) { continue; }
        $label = 'key-sha256:' . hash('sha256', $key);
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $diff['cache'][] = $label . ':value'; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $diff['cache'][] = $label . ':expiry'; }
    }
    return $diff;
}
function bs_runtime(): array
{
    audit_expect(is_dir('/run/audit-app-runtime'), 'actual application runtime mount must be observable');
    $files = [];
    foreach ([audit_repo_dir() . '/crmeb/runtime', '/run/audit-app-runtime'] as $scope => $root) {
        if (!is_dir($root)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) { continue; }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('~(^|/)(cache|log)(/|$)~', $relative) !== 1) { continue; }
            $files[$scope . ':' . hash('sha256', $relative)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files); return $files;
}
function bs_collect($stdout, array &$events, int &$discarded, float $seconds): void
{
    $read = [$stdout]; $write = null; $except = null;
    $whole = (int)$seconds; $fraction = max(0, (int)(($seconds - $whole) * 1000000));
    if (@stream_select($read, $write, $except, $whole, $fraction) !== 1) { return; }
    while (($line = fgets($stdout)) !== false) {
        $event = json_decode(trim($line), true);
        if (is_array($event) && in_array($event['stage'] ?? '', ['READY', 'BOOT_DONE', 'DONE'], true)) { $events[] = $event; }
        else { ++$discarded; } // Never retain arbitrary driver/framework output.
    }
}
function bs_run(string $mode): array
{
    audit_guard_assert();
    $layout = bs_layout($mode, $GLOBALS['bs_execution']);
    $owned = in_array($mode, ['default-psr16', 'named-psr16', 'default-tag', 'named-tag', 'orm-cache-read'], true) ? $layout['physical'] : [];
    foreach ($owned as $key) { audit_expect(audit_redis()->exists($key) === 0, 'owned test key must not replace existing state'); }
    $before = bs_snapshot(); $runtimeBefore = bs_runtime();
    $process = null; $pipes = []; $events = []; $discarded = 0; $paused = false; $watchdog = false;
    $terminated = false; $controlFailure = null; $restoreMs = null;
    try {
        $process = proc_open([PHP_BINARY, __FILE__, '--child', $mode, $GLOBALS['bs_execution']],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
        audit_expect(is_resource($process), 'real bootstrap worker must start');
        stream_set_blocking($pipes[1], false);
        $readyDeadline = microtime(true) + 10.0;
        do {
            bs_collect($pipes[1], $events, $discarded, 0.1);
            $ready = in_array('READY', array_column($events, 'stage'), true);
        } while (!$ready && proc_get_status($process)['running'] && microtime(true) < $readyDeadline);
        audit_expect($ready, 'worker must reach ready before App initialize');
        if ($mode === 'paused-bootstrap') { $paused = true; audit_fault_redis('pause'); }
        $go = microtime(true); fwrite($pipes[0], "GO\n"); fflush($pipes[0]);
        $deadline = $go + ($paused ? 3.0 : 15.0);
        while (microtime(true) < $deadline) {
            bs_collect($pipes[1], $events, $discarded, 0.1);
            if (in_array('DONE', array_column($events, 'stage'), true) || !proc_get_status($process)['running']) { break; }
        }
        $doneBeforeRestore = in_array('DONE', array_column($events, 'stage'), true);
        if ($paused) {
            $watchdog = !$doneBeforeRestore;
            $restoreMs = round((microtime(true) - $go) * 1000, 3);
            audit_fault_redis('unpause'); $paused = false;
        }
        $finishDeadline = microtime(true) + 5.0;
        do {
            bs_collect($pipes[1], $events, $discarded, 0.1);
            $running = proc_get_status($process)['running'];
        } while ($running && microtime(true) < $finishDeadline);
        if ($running) { $terminated = true; proc_terminate($process, 9); usleep(100000); }
        bs_collect($pipes[1], $events, $discarded, 0.0);
    } catch (Throwable $error) {
        $controlFailure = ['class' => get_class($error), 'code' => (int)$error->getCode()];
    } finally {
        if ($paused) { audit_fault_redis('unpause'); }
        if (is_resource($process) && proc_get_status($process)['running']) { $terminated = true; proc_terminate($process, 9); usleep(100000); }
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($process)) { proc_close($process); }
    }
    audit_guard_assert();
    $after = bs_snapshot(); $runtimeAfter = bs_runtime();
    $window = bs_diff($before, $after, $owned);
    // Observe the complete failure/operation window before exact known cleanup.
    foreach ($owned as $key) { audit_redis()->del($key); }
    $afterCleanup = bs_snapshot(); $cleanup = bs_diff($before, $afterCleanup);
    $done = array_values(array_filter($events, static fn(array $event): bool => $event['stage'] === 'DONE'));
    $passed = $controlFailure === null && !$watchdog && !$terminated && $discarded === 0
        && count($done) === 1 && ($done[0]['success'] ?? false) === true
        && $window === ['db' => [], 'cache' => []] && $cleanup === ['db' => [], 'cache' => []]
        && $runtimeBefore === $runtimeAfter;
    $reference = 'evidence/bootstrap-' . (getenv('AUDIT_PHASE') ?: 'after') . '-' . $GLOBALS['bs_execution'] . '-' . $mode . '.json';
    audit_write_json(audit_run_dir() . '/' . $reference, [
        'run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'mode' => $mode,
        'milestone' => 'MS0b', 'passed' => $passed, 'events' => $events, 'control_failure' => $controlFailure,
        'watchdog_target_ms' => $mode === 'paused-bootstrap' ? 3000 : null,
        'watchdog_triggered' => $watchdog, 'unpause_request_elapsed_ms' => $restoreMs,
        'terminated_after_recovery' => $terminated, 'unstructured_output_discarded' => $discarded,
        'tables_checked' => count($before['tables']), 'all_redis_values_and_absolute_expiries_checked' => true,
        'expiry_rounding_tolerance_ms' => 1, 'window_unexpected_changes_before_cleanup' => $window,
        'changes_after_exact_cleanup' => $cleanup, 'owned_key_fingerprints' => array_map(static fn(string $key): string => hash('sha256', $key), $owned),
        'runtime_cache_log_unchanged' => $runtimeBefore === $runtimeAfter,
        'runtime_before_sha256' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)),
        'runtime_after_sha256' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR)),
        'full_guard_after_recovery' => true,
    ]);
    return ['status' => $passed ? 'PASS' : 'FAIL',
        'actual' => $passed ? 'real bootstrap/cache compatibility passed; isolated business state preserved'
            : 'bootstrap/cache contract failed; inspect redacted stage and full-state evidence',
        'db_diff' => array_values(array_unique(array_merge($window['db'], $cleanup['db']))),
        'cache_diff' => array_values(array_unique(array_merge($window['cache'], $cleanup['cache']))),
        'evidence_ref' => $reference,
        'notes' => 'Real subprocess READY/GO; parent never resolves a cache store. Watchdog recovery does not turn old blocking into PASS; defaults/named PSR/tag are compatibility regressions, not missing-class assertions.'];
}

$cases = [
    'paused-bootstrap' => ['A23', 'paused Redis: App initializes in under1s without client IO; first actual auth access is bounded fixed503 before watchdog recovery'],
    'healthy-lazy-bootstrap' => ['Z07', 'healthy bootstrap resolves no shared cache driver/PDO/query before cache operations'],
    'db-entrypoint-identity' => ['Z07', 'actual db, think.Db, DbManager, facade and real Model entrypoints retain one Db instance'],
    'default-psr16' => ['Z07', 'default ORM PSR16 preserves shared native driver, get/set/delete/has, original serialization/prefix/TTL/options'],
    'named-psr16' => ['Z07', 'actual named-store Db factory preserves PSR16/shared native identity and distinct physical namespace'],
    'default-tag' => ['Z07', 'default shared tags clear only the owned tagged key and preserve untagged data/native flags'],
    'named-tag' => ['Z07', 'named shared tags retain exact store/prefix and preserve default namespace/native flags'],
    'orm-cache-read' => ['Z07', 'real ORM/facade query cache reuses installed synthetic subject without another SQL or business mutation'],
];
foreach ($cases as $mode => [$case, $expected]) {
    audit_case($case, 'bootstrap-' . $mode, 'INTEGRATION', static function () use ($mode, $expected): array {
        return ['expected' => $expected] + bs_run($mode);
    });
}
audit_finish();
