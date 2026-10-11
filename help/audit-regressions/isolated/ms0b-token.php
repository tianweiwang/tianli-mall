<?php
declare(strict_types=1);

// Task 3 review draft only. Install beside the isolated harness before running.
// Proposed minimal constructor: AuthTokenV2(AuthSessionStore $store).
// Its configuration is obtained from the real AuthConfigGuard on each call.
// No JWT, language, framework, Redis, transport or credential-reader stub.
// Credential callbacks read existing isolated fixtures. No fixture DB mutation.
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');

function ms0b_token_boot(): void
{
    require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
    $app = new \think\App(audit_repo_dir() . '/crmeb/');
    $config = audit_config();
    $GLOBALS['ms0b_token_lang_prefix'] = 'audit:token-lang:' . getenv('AUDIT_RUN_ID') . ':' . getmypid() . ':';
    $app->env->set([
        'APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
        'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
        'DATABASE_PASSWORD' => $config['database']['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis',
        'REDIS_PORT' => '6379', 'REDIS_REDIS_PASSWORD' => $config['redis_password'],
        'CACHE_CACHE_PREFIX' => $GLOBALS['ms0b_token_lang_prefix'],
    ]);
    $app->initialize();
    audit_expect(function_exists('getLang'), 'real application common functions must be loaded');
    audit_expect($app->config->get('cache.stores.redis.prefix') === $GLOBALS['ms0b_token_lang_prefix'], 'cold real language prefix must be selected');
    $GLOBALS['ms0b_token_app'] = $app;
    $GLOBALS['ms0b_token_cache_config'] = $app->config->get('cache');
    $GLOBALS['ms0b_token_signing_key'] = $config['signing_key'];
    $GLOBALS['ms0b_token_stamp_key'] = bin2hex(random_bytes(32));
}
ms0b_token_boot();
$GLOBALS['ms0b_token_counter'] = 0;
$GLOBALS['ms0b_token_case_counter'] = 0;
$GLOBALS['ms0b_token_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
$fixtureMetadata = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['ms0b_token_fixture_ids'] = [];
foreach (['admin_primary' => 'admin_super', 'admin_second' => 'admin_limited', 'out_primary' => 'out_101', 'out_second' => 'out_102'] as $name => $fixture) {
    audit_expect(is_int($fixtureMetadata[$fixture]['id'] ?? null) && $fixtureMetadata[$fixture]['id'] > 0, 'explicit isolated fixture metadata must contain its synthetic subject');
    $GLOBALS['ms0b_token_fixture_ids'][$name] = (string)$fixtureMetadata[$fixture]['id'];
}
unset($fixtureMetadata); // Keep only verified IDs, never fixture passwords.
$GLOBALS['ms0b_token_snapshot_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect((bool)preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table), 'unexpected snapshot table rejected');
    $GLOBALS['ms0b_token_snapshot_spec']['tables'][$table] = null;
}

function ms0b_token_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['ms0b_token_snapshot_spec']);
    $keys = array_keys($snapshot['redis']['*']); $snapshot['expires'] = [];
    if ($keys === []) { return $snapshot; }
    // Redis DUMP excludes expiry: atomically collect TIME+PTTL and compare
    // absolute deadlines so silent renewal cannot pass a value-only snapshot.
    $script = <<<'LUA'
local now = redis.call('TIME')
local milliseconds = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
local result = {}
for index, key in ipairs(KEYS) do
    local ttl = redis.call('PTTL', key)
    if ttl >= 0 then result[index] = milliseconds + ttl else result[index] = ttl end
end
return result
LUA;
    $deadlines = audit_redis()->eval($script, $keys, count($keys));
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'real Redis expiry snapshot must cover every value key');
    foreach ($keys as $index => $key) {
        audit_expect(is_int($deadlines[$index]), 'real Redis deadline must be an integer');
        $snapshot['expires'][$key] = $deadlines[$index];
    }
    return $snapshot;
}
function ms0b_token_same_expiry(int $before, int $after): bool
{
    return $before < 0 || $after < 0 ? $before === $after : abs($before - $after) <= 1;
}
function ms0b_token_runtime_snapshot(): array
{
    $paths = [
        ($GLOBALS['ms0b_token_case_cache_config'] ?? $GLOBALS['ms0b_token_cache_config'])['stores']['file']['path'],
        $GLOBALS['ms0b_token_app']->getRuntimePath() . 'log/',
    ];
    $files = [];
    foreach ($paths as $index => $path) {
        if (!is_dir($path)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($path));
                // Names and log contents are never emitted; compare every file.
                $files[$index . ':' . hash('sha256', $relative)] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($files);
    return $files;
}
function ms0b_token_summary(array $snapshot): array
{
    return [
        'database_tables' => count($snapshot['tables']), 'redis_values' => count($snapshot['redis']['*']),
        'database_values_sha256' => hash('sha256', json_encode($snapshot['tables'], JSON_THROW_ON_ERROR)),
        'redis_values_sha256' => hash('sha256', json_encode($snapshot['redis']['*'], JSON_THROW_ON_ERROR)),
        'redis_expiry_entries' => count($snapshot['expires']),
        'redis_absolute_deadlines_sha256' => hash('sha256', json_encode($snapshot['expires'], JSON_THROW_ON_ERROR)),
        'deadline_rounding_tolerance_ms' => 1,
    ];
}
function ms0b_token_changes(array $before, array $after, array $allowed = []): array
{
    $changed = [];
    foreach ($before['tables'] as $table => $value) {
        if ($value !== ($after['tables'][$table] ?? null)) { $changed[] = 'table:' . $table; }
    }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowed, true)) { continue; }
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) {
            $changed[] = 'cache-sha256:' . substr(hash('sha256', $key), 0, 12);
        }
        if (!ms0b_token_same_expiry($before['expires'][$key] ?? -2, $after['expires'][$key] ?? -2)) {
            $changed[] = 'cache-sha256:' . substr(hash('sha256', $key), 0, 12) . ':expiry';
        }
    }
    return $changed;
}
function ms0b_token_config(string $epoch): array
{
    return [
        'session_epoch' => $epoch, 'stamp_key' => $GLOBALS['ms0b_token_stamp_key'], 'issuer' => 'tianli-audit',
        'apps' => [
            'adminapi' => ['type' => 'admin', 'audience' => 'adminapi-audit', 'ttl' => 600],
            'outapi' => ['type' => 'out', 'audience' => 'outapi-audit', 'ttl' => 900],
        ],
    ];
}
function ms0b_token_set_config(array $config): void
{
    $app = $GLOBALS['ms0b_token_app'];
    // Config::set($config, 'auth') merges and would restore a removed field.
    // Replacing the top-level entry makes missing-field probes actually missing.
    $app->config->set(['auth' => $config]);
    $app->env->set([
        'AUTH_SESSION_EPOCH' => $config['session_epoch'] ?? null,
        'AUTH_STAMP_KEY' => $config['stamp_key'] ?? null,
        'AUTH_ISSUER' => $config['issuer'] ?? null,
    ]);
}
function ms0b_token_require_classes(): void
{
    audit_expect(class_exists(\crmeb\services\auth\AuthSessionStore::class), 'AuthSessionStore has not been implemented');
    audit_expect(class_exists(\crmeb\services\auth\AuthTokenV2::class), 'AuthTokenV2 has not been implemented');
    audit_expect(class_exists(\crmeb\services\auth\CredentialStamp::class), 'CredentialStamp has not been implemented');
    audit_expect(method_exists(\crmeb\services\AuthConfigGuard::class, 'authConfig'), 'AuthConfigGuard::authConfig has not been implemented');
}
function ms0b_token_scan(string $pattern): array
{
    $redis = audit_redis(); $cursor = null; $keys = [];
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 100) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING);
    return array_values(array_unique($keys));
}
function ms0b_token_prefix(string $epoch): string
{
    audit_expect((bool)preg_match('/^audit-token-[a-z0-9-]+$/D', $epoch), 'test-owned epoch must be a safe exact prefix');
    $prefix = 'auth:v2:' . $epoch . ':';
    audit_expect(ms0b_token_scan($prefix . '*') === [], 'test epoch must not overwrite pre-existing state');
    $GLOBALS['ms0b_token_prefixes'][] = $prefix;
    return $prefix;
}
function ms0b_token_digest(string $app, string $id): string
{
    audit_expect(in_array($app, ['adminapi', 'outapi'], true), 'credential query app must be fixed');
    $sql = $app === 'adminapi' ? 'SELECT pwd FROM eb_system_admin WHERE id=?' : 'SELECT appsecret FROM eb_out_account WHERE id=?';
    $statement = audit_db()->prepare($sql); $statement->execute([$id]); $digest = $statement->fetchColumn();
    audit_expect(is_string($digest) && $digest !== '', 'existing isolated credential fixture must be present');
    return $digest;
}
function ms0b_token_context(string $app = 'adminapi', string $id = ''): array
{
    if ($id === '') { $id = $GLOBALS['ms0b_token_fixture_ids'][$app === 'adminapi' ? 'admin_primary' : 'out_primary']; }
    $epoch = 'audit-token-' . getenv('AUDIT_RUN_ID') . '-' . ++$GLOBALS['ms0b_token_counter'];
    $prefix = ms0b_token_prefix($epoch); $config = ms0b_token_config($epoch); ms0b_token_set_config($config);
    $store = new \crmeb\services\auth\AuthSessionStore(audit_redis(), $epoch);
    $version = $store->initAccount($app, $id);
    $digest = ms0b_token_digest($app, $id);
    $stamp = \crmeb\services\auth\CredentialStamp::for($app, $id, $digest);
    return [
        'app' => $app, 'id' => $id, 'epoch' => $epoch, 'prefix' => $prefix, 'config' => $config,
        'store' => $store, 'version' => $version, 'digest' => $digest, 'stamp' => $stamp,
        'token_service' => new \crmeb\services\auth\AuthTokenV2($store),
        'account_key' => $prefix . $app . ':account:' . $id,
    ];
}
function ms0b_token_reader(array $context, ?int &$reads = null): Closure
{
    return static function (string $id) use ($context, &$reads): string {
        if ($reads !== null) { ++$reads; }
        audit_expect($id === $context['id'], 'credential reader must receive only this verified subject');
        return ms0b_token_digest($context['app'], $id);
    };
}
function ms0b_token_claims(string $token): array
{
    \Firebase\JWT\JWT::$leeway = 60;
    // Decode only our already issued/controlled test token, never unverified input.
    return json_decode(json_encode(\Firebase\JWT\JWT::decode($token, $GLOBALS['ms0b_token_signing_key'], ['HS256']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}
function ms0b_token_issue(array $context): array
{
    $issued = $context['token_service']->issue($context['app'], $context['id'], $context['version'], $context['stamp']);
    audit_expect(is_array($issued) && is_string($issued['token'] ?? null) && $issued['token'] !== ''
        && is_int($issued['exp'] ?? null) && is_string($issued['sid'] ?? null), 'issue must return the documented token/exp/sid envelope');
    audit_expect((bool)preg_match('/^[a-f0-9]{32}$/D', $issued['sid']), 'issue sid must have 128 random bits in lowercase hex');
    return $issued;
}
function ms0b_token_ready(string $app = 'adminapi', string $id = ''): array
{
    $context = ms0b_token_context($app, $id);
    $context['issued'] = ms0b_token_issue($context);
    $context['claims'] = ms0b_token_claims($context['issued']['token']);
    $context['session_key'] = $context['prefix'] . $app . ':session:' . $context['issued']['sid'];
    return $context;
}
function ms0b_token_exception(callable $operation, string $class, int $code): Throwable
{
    $error = null;
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    audit_expect($error !== null && is_a($error, $class) && $error->getCode() === $code, 'operation must reject with the documented controlled exception/code');
    audit_expect($error->getPrevious() === null, 'controlled authentication failure must not retain a sensitive driver exception');
    foreach ([$GLOBALS['ms0b_token_signing_key'], $GLOBALS['ms0b_token_stamp_key'], $GLOBALS['ms0b_token_private_error_marker'] ?? ''] as $secret) {
        if ($secret !== '') { audit_expect(!str_contains($error->getMessage(), $secret), 'controlled exception may not contain a private input or credential'); }
    }
    return $error;
}
function ms0b_token_deny(callable $operation, string $class = \crmeb\exceptions\AuthException::class, int $code = 401): void
{
    $before = ms0b_token_snapshot(); $runtimeBefore = ms0b_token_runtime_snapshot(); $failure = null;
    try { ms0b_token_exception($operation, $class, $code); } catch (Throwable $error) { $failure = $error; }
    $after = ms0b_token_snapshot(); $changes = ms0b_token_changes($before, $after);
    $runtimeAfter = ms0b_token_runtime_snapshot();
    $GLOBALS['ms0b_token_observations'][] = [
        'expected_exception' => $class, 'expected_code' => $code, 'classification_matched' => $failure === null,
        'before' => ms0b_token_summary($before), 'after' => ms0b_token_summary($after), 'unexpected_changes' => $changes,
        'runtime_cache_and_log_unchanged' => $runtimeBefore === $runtimeAfter,
        'runtime_before_sha256' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)),
        'runtime_after_sha256' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR)),
    ];
    if ($failure !== null) { throw $failure; }
    audit_expect($changes === [], 'rejection must preserve all database tables and every Redis value before cleanup');
    audit_expect($runtimeBefore === $runtimeAfter, 'controlled rejection may not write file language cache or detailed runtime logs');
}
function ms0b_token_verify(array $context, ?string $token = null, ?object $service = null): array
{
    return ($service ?? $context['token_service'])->verify($context['app'], $token ?? $context['issued']['token'], ms0b_token_reader($context));
}
function ms0b_token_case(string $id, string $subcase, string $expected, callable $operation): void
{
    foreach ($GLOBALS['argv'] as $argument) {
        if (str_starts_with($argument, '--only=') && !in_array($id, explode(',', substr($argument, 7)), true)) { return; }
    }
    audit_case($id, $subcase, 'INTEGRATION', static function () use ($id, $subcase, $expected, $operation): array {
        audit_guard_assert();
        $GLOBALS['ms0b_token_prefixes'] = []; $GLOBALS['ms0b_token_observations'] = [];
        $app = $GLOBALS['ms0b_token_app'];
        $app->env->set('APP_APP_KEY', $GLOBALS['ms0b_token_signing_key']);
        $caseNumber = ++$GLOBALS['ms0b_token_case_counter'];
        $GLOBALS['ms0b_token_lang_prefix'] = 'audit:token-lang:' . getenv('AUDIT_RUN_ID') . ':' . getmypid() . ':' . $caseNumber . ':';
        $cache = $GLOBALS['ms0b_token_cache_config'];
        $cache['stores']['redis']['prefix'] = $GLOBALS['ms0b_token_lang_prefix'];
        $cache['stores']['file']['path'] = $app->getRuntimePath() . 'token-language/' . getmypid() . '/' . $caseNumber . '/';
        $GLOBALS['ms0b_token_case_cache_config'] = $cache;
        $app->config->set(['cache' => $cache]);
        // Real manager drops only client instances; it does not delete any key
        // or warm any cache. Every case gets a new cold actual language prefix.
        $app->cache->forgetDriver(['redis', 'file']);
        $before = ms0b_token_snapshot(); $failure = null;
        try { ms0b_token_require_classes(); $operation(); } catch (Throwable $error) { $failure = $error; }
        finally {
            // Returned and unreturned random SIDs are discovered only inside a
            // freshly reserved test epoch, then deleted by their exact names.
            $redis = audit_redis();
            foreach ($GLOBALS['ms0b_token_prefixes'] as $prefix) {
                foreach (ms0b_token_scan($prefix . '*') as $key) { $redis->del($key); }
            }
            $app->env->set('APP_APP_KEY', $GLOBALS['ms0b_token_signing_key']);
            $app->config->set(['cache' => $GLOBALS['ms0b_token_case_cache_config']]);
        }
        $after = ms0b_token_snapshot(); $changes = ms0b_token_changes($before, $after);
        $unexpected = array_values(array_unique(array_merge($changes, ...array_column($GLOBALS['ms0b_token_observations'], 'unexpected_changes'))));
        $passed = $failure === null && $changes === [];
        // Only audit_expect's literal messages are emitted. Production driver
        // or exception messages, tokens, hashes and config values are excluded.
        $detail = $failure !== null && get_class($failure) === RuntimeException::class ? $failure->getMessage() : null;
        $reference = 'evidence/token-' . (getenv('AUDIT_PHASE') ?: 'after') . '-' . $GLOBALS['ms0b_token_execution'] . '-' . $id . '-' . $subcase . '.json';
        audit_write_json(audit_run_dir() . '/' . $reference, [
            'milestone' => 'MS0b', 'case_id' => $id, 'subcase' => $subcase,
            'run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
            'expected' => $expected, 'passed' => $passed,
            'failure_class' => $failure ? get_class($failure) : null, 'failure_detail' => $detail,
            'operations' => $GLOBALS['ms0b_token_observations'],
            'before' => ms0b_token_summary($before), 'after_exact_cleanup' => ms0b_token_summary($after),
            'unexpected_changes_after_cleanup' => $changes,
            'database_tables_checked' => count($before['tables']), 'all_redis_values_checked' => true,
            'mysql_and_redis_sentinels' => 'verified before case; included in all-value snapshots',
        ]);
        return [
            'status' => $passed ? 'PASS' : 'FAIL', 'expected' => $expected,
            'actual' => $failure ? 'controlled test failure: ' . get_class($failure) . ($detail !== null ? ': ' . $detail : '') : 'contract passed; all database tables and non-test Redis values unchanged',
            'db_diff' => array_values(array_filter($unexpected, static fn(string $value): bool => str_starts_with($value, 'table:'))),
            'cache_diff' => array_values(array_filter($unexpected, static fn(string $value): bool => str_starts_with($value, 'cache-sha256:'))),
            'evidence_ref' => $reference,
            'notes' => 'Real framework/Redis/PDO fixture reads; no language cache prewarm; negative full snapshots occur before exact test-epoch cleanup.',
        ];
    });
}

ms0b_token_case('A10', 'v2-config-real-file-defaults', 'real config loads explicit mandatory env and preserves issuer/30-day TTL defaults', static function (): void {
    $file = audit_repo_dir() . '/crmeb/config/auth.php';
    audit_expect(is_file($file), 'auth.php has not been implemented');
    $app = $GLOBALS['ms0b_token_app'];
    $epoch = 'audit-token-' . getenv('AUDIT_RUN_ID') . '-' . ++$GLOBALS['ms0b_token_counter'];
    $app->env->set(['AUTH_SESSION_EPOCH' => $epoch, 'AUTH_STAMP_KEY' => $GLOBALS['ms0b_token_stamp_key'], 'AUTH_ISSUER' => null]);
    $loaded = include $file;
    audit_expect(is_array($loaded) && ($loaded['session_epoch'] ?? null) === $epoch
        && ($loaded['stamp_key'] ?? null) === $GLOBALS['ms0b_token_stamp_key'], 'config must read mandatory independent deployment env');
    audit_expect(($loaded['issuer'] ?? null) === 'tianli-mall', 'issuer default must be stable deployment identity');
    foreach (['adminapi' => 'admin', 'outapi' => 'out'] as $appName => $type) {
        audit_expect(($loaded['apps'][$appName]['type'] ?? null) === $type
            && is_string($loaded['apps'][$appName]['audience'] ?? null) && $loaded['apps'][$appName]['audience'] !== ''
            && ($loaded['apps'][$appName]['ttl'] ?? null) === 30 * 86400, 'default app binding and 30-day compatibility lifetime must be explicit');
    }
});

$configurationVariants = [
    'missing-epoch', 'empty-epoch', 'unsafe-epoch-colon', 'unsafe-epoch-wildcard', 'overlong-epoch',
    'missing-stamp', 'empty-stamp', 'short-stamp', 'non-string-stamp',
    'empty-issuer', 'non-string-issuer', 'overlong-issuer', 'missing-apps', 'missing-admin-app', 'missing-out-app',
    'missing-admin-type', 'empty-admin-type', 'wrong-admin-type', 'wrong-out-type',
    'missing-admin-audience', 'empty-admin-audience', 'non-string-out-audience', 'overlong-audience',
    'missing-admin-ttl', 'zero-admin-ttl', 'negative-out-ttl', 'non-int-admin-ttl', 'overflowing-ttl',
];
foreach ($configurationVariants as $variant) {
    ms0b_token_case('A10', 'v2-config-' . $variant, 'invalid effective mandatory v2 configuration returns controlled 503 and performs no language/DB/cache write', static function () use ($variant): void {
        $context = ms0b_token_context(); $config = $context['config'];
        switch ($variant) {
            case 'missing-epoch': unset($config['session_epoch']); break;
            case 'empty-epoch': $config['session_epoch'] = ''; break;
            case 'unsafe-epoch-colon': $config['session_epoch'] = 'bad:epoch'; break;
            case 'unsafe-epoch-wildcard': $config['session_epoch'] = 'bad*epoch'; break;
            case 'overlong-epoch': $config['session_epoch'] = str_repeat('a', 129); break;
            case 'missing-stamp': unset($config['stamp_key']); break;
            case 'empty-stamp': $config['stamp_key'] = ''; break;
            case 'short-stamp': $config['stamp_key'] = str_repeat('x', 31); break;
            case 'non-string-stamp': $config['stamp_key'] = ['invalid']; break;
            case 'empty-issuer': $config['issuer'] = ''; break;
            case 'non-string-issuer': $config['issuer'] = ['invalid']; break;
            case 'overlong-issuer': $config['issuer'] = str_repeat('a', 4096); break;
            case 'missing-apps': unset($config['apps']); break;
            case 'missing-admin-app': unset($config['apps']['adminapi']); break;
            case 'missing-out-app': unset($config['apps']['outapi']); break;
            case 'missing-admin-type': unset($config['apps']['adminapi']['type']); break;
            case 'empty-admin-type': $config['apps']['adminapi']['type'] = ''; break;
            case 'wrong-admin-type': $config['apps']['adminapi']['type'] = 'out'; break;
            case 'wrong-out-type': $config['apps']['outapi']['type'] = 'admin'; break;
            case 'missing-admin-audience': unset($config['apps']['adminapi']['audience']); break;
            case 'empty-admin-audience': $config['apps']['adminapi']['audience'] = ''; break;
            case 'non-string-out-audience': $config['apps']['outapi']['audience'] = ['invalid']; break;
            case 'overlong-audience': $config['apps']['adminapi']['audience'] = str_repeat('a', 4096); break;
            case 'missing-admin-ttl': unset($config['apps']['adminapi']['ttl']); break;
            case 'zero-admin-ttl': $config['apps']['adminapi']['ttl'] = 0; break;
            case 'negative-out-ttl': $config['apps']['outapi']['ttl'] = -1; break;
            case 'non-int-admin-ttl': $config['apps']['adminapi']['ttl'] = '600'; break;
            case 'overflowing-ttl': $config['apps']['adminapi']['ttl'] = PHP_INT_MAX; break;
        }
        ms0b_token_set_config($config);
        ms0b_token_deny(static fn() => \crmeb\services\AuthConfigGuard::authConfig(), \crmeb\exceptions\AuthUnavailableException::class, 503);
        $returned = false;
        ms0b_token_deny(static function () use ($context, &$returned): void { $context['token_service']->issue($context['app'], $context['id'], $context['version'], $context['stamp']); $returned = true; }, \crmeb\exceptions\AuthUnavailableException::class, 503);
        audit_expect($returned === false, 'invalid configuration must never return a token');
    });
}
foreach (['missing-signing-key', 'default-signing-key', 'file-store'] as $variant) {
    ms0b_token_case('A10', 'v2-config-' . $variant, 'v2 preserves signing-key/Redis dependency guard with controlled 503 and cold language zero writes', static function () use ($variant): void {
        $context = ms0b_token_context(); $app = $GLOBALS['ms0b_token_app'];
        if ($variant === 'file-store') {
            $cache = $GLOBALS['ms0b_token_case_cache_config']; $cache['default'] = 'file'; $app->config->set(['cache' => $cache]);
        } else { $app->env->set('APP_APP_KEY', $variant === 'default-signing-key' ? 'default' : ''); }
        ms0b_token_deny(static fn() => $context['token_service']->issue($context['app'], $context['id'], $context['version'], $context['stamp']), \crmeb\exceptions\AuthUnavailableException::class, 503);
    });
}

ms0b_token_case('A10', 'v2-live-epoch-mismatch-existing-instance', 'changed configured epoch cannot use a long-lived Store from the previous epoch; controlled 503 with no old/new namespace writes', static function (): void {
    $context = ms0b_token_ready();
    audit_expect(method_exists($context['store'], 'epoch') && $context['store']->epoch() === $context['epoch'], 'store must expose only its immutable configuration epoch');
    $changed = $context['config']; $changed['session_epoch'] .= '-next'; ms0b_token_prefix($changed['session_epoch']);
    ms0b_token_set_config($changed);
    ms0b_token_deny(static fn() => $context['token_service']->issue($context['app'], $context['id'], $context['version'], $context['stamp']), \crmeb\exceptions\AuthUnavailableException::class, 503);
    ms0b_token_deny(static fn() => ms0b_token_verify($context), \crmeb\exceptions\AuthUnavailableException::class, 503);
    ms0b_token_deny(static fn() => $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context)), \crmeb\exceptions\AuthUnavailableException::class, 503);
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $context['issued']['token']), \crmeb\exceptions\AuthUnavailableException::class, 503);
    ms0b_token_set_config($context['config']); ms0b_token_verify($context);
});
ms0b_token_case('A29', 'v2-new-epoch-new-store-old-token-rejected', 'fresh Store on new epoch rejects old JWT at claim gate; old namespace survives unmodified', static function (): void {
    $old = ms0b_token_ready('outapi'); $config = $old['config']; $config['session_epoch'] .= '-next';
    ms0b_token_prefix($config['session_epoch']); ms0b_token_set_config($config);
    $store = new \crmeb\services\auth\AuthSessionStore(audit_redis(), $config['session_epoch']);
    $service = new \crmeb\services\auth\AuthTokenV2($store); $reads = 0;
    $reader = ms0b_token_reader($old, $reads);
    ms0b_token_deny(static fn() => $service->verify('outapi', $old['issued']['token'], $reader));
    audit_expect($reads === 0, 'old epoch claim may not reach credential callback');
    ms0b_token_set_config($old['config']); ms0b_token_verify($old);
});
ms0b_token_case('A10', 'v2-production-factory-isolated-native-connection', 'factories use independent raw Redis connections; existing Cache native prefix/serializer/timeout options remain unchanged', static function (): void {
    $context = ms0b_token_context(); $cacheClient = $GLOBALS['ms0b_token_app']->cache->store('redis')->handler();
    audit_expect($cacheClient instanceof Redis, 'real shared cache handler must be native Redis in ISO-FULL');
    $options = [Redis::OPT_PREFIX, Redis::OPT_SERIALIZER, Redis::OPT_READ_TIMEOUT]; $old = [];
    foreach ($options as $option) { $old[$option] = $cacheClient->getOption($option); }
    try {
        audit_expect($cacheClient->setOption(Redis::OPT_PREFIX, 'audit-cache-option:') === true
            && $cacheClient->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP) === true
            && $cacheClient->setOption(Redis::OPT_READ_TIMEOUT, 1.25) === true, 'shared-cache test options must actually be installed');
        $expected = [];
        foreach ($options as $option) { $expected[$option] = $cacheClient->getOption($option); }
        $store = \crmeb\services\auth\AuthSessionStore::fromConfig();
        audit_expect($store->epoch() === $context['epoch'], 'Store factory must bind current guarded configuration epoch');
        $service = \crmeb\services\auth\AuthTokenV2::fromConfig();
        $issued = $service->issue($context['app'], $context['id'], $context['version'], $context['stamp']);
        audit_expect(is_string($issued['sid'] ?? null) && audit_redis()->type($context['prefix'] . $context['app'] . ':session:' . $issued['sid']) === Redis::REDIS_HASH, 'factory session keys must be raw documented auth namespace and HASH, free of cache prefix/serialization');
        $actual = [];
        foreach ($options as $option) { $actual[$option] = $cacheClient->getOption($option); }
        audit_expect($actual === $expected, 'production factories may not modify a shared cache connection option');
        audit_expect($service->verify($context['app'], $issued['token'], ms0b_token_reader($context)) === ['id' => $context['id'], 'sid' => $issued['sid']], 'independent factory connection must read its persisted raw record');
    } finally {
        foreach ($old as $option => $value) { $cacheClient->setOption($option, $value); }
    }
});
ms0b_token_case('A23', 'v2-production-factory-refused-redis-connection', 'actual refused connection on verified isolated Redis host returns bounded controlled 503 without fallback or language write', static function (): void {
    $context = ms0b_token_context(); $cache = $GLOBALS['ms0b_token_case_cache_config'];
    audit_expect(gethostbyname($cache['stores']['redis']['host']) === audit_manifest()['services']['audit-redis']['address'], 'failure target must remain the actual isolated Redis service');
    $cache['stores']['redis']['port'] = 1; $GLOBALS['ms0b_token_app']->config->set(['cache' => $cache]);
    $started = microtime(true);
    ms0b_token_deny(static fn() => \crmeb\services\auth\AuthTokenV2::fromConfig(), \crmeb\exceptions\AuthUnavailableException::class, 503);
    audit_expect(microtime(true) - $started < 10.0, 'production auth factory connect failure must be bounded');
    $GLOBALS['ms0b_token_app']->config->set(['cache' => $GLOBALS['ms0b_token_case_cache_config']]);
    ms0b_token_verify(array_replace($context, ['issued' => ms0b_token_issue($context)]));
});

ms0b_token_case('A22', 'v2-cold-language-zero-writes', 'actual cold language prefix stays absent across controlled schema rejection', static function (): void {
    audit_expect(ms0b_token_scan($GLOBALS['ms0b_token_lang_prefix'] . '*') === [], 'language cache must actually be cold before test');
    $context = ms0b_token_ready(); $claims = $context['claims']; unset($claims['auth_schema']);
    $token = \Firebase\JWT\JWT::encode($claims, $GLOBALS['ms0b_token_signing_key'], 'HS256');
    ms0b_token_deny(static fn() => ms0b_token_verify($context, $token));
    audit_expect(ms0b_token_scan($GLOBALS['ms0b_token_lang_prefix'] . '*') === [], 'controlled token error must never initialize real language cache');
});
foreach (['adminapi', 'outapi'] as $appName) {
    ms0b_token_case('A28', 'v2-issue-verify-' . $appName, 'issue returns only after real session persistence; claims bind fixed app and omit all credential material; verify is read-only', static function () use ($appName): void {
        $context = ms0b_token_ready($appName); $issued = $context['issued']; $claims = $context['claims'];
        audit_expect(($claims['auth_schema'] ?? null) === 2 && ($claims['session_epoch'] ?? null) === $context['epoch']
            && ($claims['sid'] ?? null) === $issued['sid'] && ($claims['session_version'] ?? null) === $context['version']
            && ($claims['iss'] ?? null) === $context['config']['issuer']
            && ($claims['aud'] ?? null) === $context['config']['apps'][$appName]['audience']
            && (string)($claims['jti']['id'] ?? '') === $context['id']
            && ($claims['jti']['type'] ?? null) === $context['config']['apps'][$appName]['type'], 'issued claims must match configured server binding');
        audit_expect(is_int($claims['iat'] ?? null) && is_int($claims['nbf'] ?? null) && is_int($claims['exp'] ?? null)
            && $claims['nbf'] >= $claims['iat'] && $claims['exp'] - $claims['iat'] === $context['config']['apps'][$appName]['ttl']
            && $issued['exp'] === $claims['exp'], 'integer time claims must use configured lifetime');
        foreach (['pwd', 'appsecret', 'credential_stamp', 'token_digest', 'stamp_key'] as $field) {
            audit_expect(!array_key_exists($field, $claims), 'JWT may not contain server-side credential material');
        }
        $record = audit_redis()->hGetAll($context['session_key']);
        audit_expect(($record['token_digest'] ?? null) === hash('sha256', $issued['token'])
            && ($record['credential_stamp'] ?? null) === $context['stamp'] && ($record['status'] ?? null) === 'active', 'independent real Redis observer must see successful persistence before returned token use');
        $ttl = audit_redis()->ttl($context['session_key']);
        audit_expect($ttl > 0 && $ttl <= $issued['exp'] - time() + 60 && audit_redis()->ttl($context['account_key']) === -1, 'issued session lifetime cannot exceed exp+60 and account must be persistent');
        $before = ms0b_token_snapshot(); $identity = ms0b_token_verify($context);
        audit_expect($identity === ['id' => $context['id'], 'sid' => $issued['sid']], 'verify returns only current validated identity');
        audit_expect(ms0b_token_changes($before, ms0b_token_snapshot()) === [], 'successful verify must be entirely read-only');
    });
}

$claimVariants = [
    'missing-schema', 'schema-one', 'schema-string-two', 'missing-epoch', 'wrong-epoch', 'array-epoch',
    'missing-issuer', 'wrong-issuer', 'array-issuer', 'missing-audience', 'wrong-audience', 'array-audience',
    'missing-jti', 'scalar-jti', 'missing-id', 'array-id', 'object-id', 'float-id', 'bool-id',
    'empty-id', 'zero-id', 'negative-id', 'leading-zero-id', 'overlong-id',
    'missing-type', 'wrong-type', 'array-type',
    'missing-sid', 'short-sid', 'uppercase-sid', 'array-sid', 'missing-version', 'short-version', 'array-version',
    'missing-iat', 'string-iat', 'float-iat', 'future-iat-past-nbf', 'missing-nbf', 'string-nbf', 'float-nbf', 'future-nbf',
    'missing-exp', 'string-exp', 'float-exp', 'expired-beyond-leeway', 'expiry-before-issued',
    'expiry-equals-issued', 'not-before-after-expiry', 'forbidden-pwd',
];
foreach ($claimVariants as $variant) {
    ms0b_token_case('A22', 'v2-signed-claims-' . $variant, 'signature-valid malformed or incompatible v2 claims reject with 401 before credential reader and without writes', static function () use ($variant): void {
        $context = ms0b_token_ready(); $claims = $context['claims'];
        switch ($variant) {
            case 'missing-schema': unset($claims['auth_schema']); break;
            case 'schema-one': $claims['auth_schema'] = 1; break;
            case 'schema-string-two': $claims['auth_schema'] = '2'; break;
            case 'missing-epoch': unset($claims['session_epoch']); break;
            case 'wrong-epoch': $claims['session_epoch'] .= '-other'; break;
            case 'array-epoch': $claims['session_epoch'] = ['invalid']; break;
            case 'missing-issuer': unset($claims['iss']); break;
            case 'wrong-issuer': $claims['iss'] = 'other-issuer'; break;
            case 'array-issuer': $claims['iss'] = ['invalid']; break;
            case 'missing-audience': unset($claims['aud']); break;
            case 'wrong-audience': $claims['aud'] = 'outapi-audit'; break;
            case 'array-audience': $claims['aud'] = ['adminapi-audit']; break;
            case 'missing-jti': unset($claims['jti']); break;
            case 'scalar-jti': $claims['jti'] = 'invalid'; break;
            case 'missing-id': unset($claims['jti']['id']); break;
            case 'array-id': $claims['jti']['id'] = ['invalid']; break;
            case 'object-id': $claims['jti']['id'] = (object)['value' => '101']; break;
            case 'float-id': $claims['jti']['id'] = 101.5; break;
            case 'bool-id': $claims['jti']['id'] = true; break;
            case 'empty-id': $claims['jti']['id'] = ''; break;
            case 'zero-id': $claims['jti']['id'] = '0'; break;
            case 'negative-id': $claims['jti']['id'] = '-101'; break;
            case 'leading-zero-id': $claims['jti']['id'] = '0101'; break;
            case 'overlong-id': $claims['jti']['id'] = str_repeat('1', 4096); break;
            case 'missing-type': unset($claims['jti']['type']); break;
            case 'wrong-type': $claims['jti']['type'] = 'out'; break;
            case 'array-type': $claims['jti']['type'] = ['admin']; break;
            case 'missing-sid': unset($claims['sid']); break;
            case 'short-sid': $claims['sid'] = 'abc'; break;
            case 'uppercase-sid': $claims['sid'] = strtoupper(str_repeat('a', 32)); break;
            case 'array-sid': $claims['sid'] = ['invalid']; break;
            case 'missing-version': unset($claims['session_version']); break;
            case 'short-version': $claims['session_version'] = 'abc'; break;
            case 'array-version': $claims['session_version'] = ['invalid']; break;
            case 'missing-iat': unset($claims['iat']); break;
            case 'string-iat': $claims['iat'] = (string)$claims['iat']; break;
            case 'float-iat': $claims['iat'] = $claims['iat'] + 0.5; break;
            // This repository's firebase JWT.php checks future iat independently
            // of nbf. Preserve that rejection, including a past nbf; the module
            // additionally enforces integer/required/order contracts.
            case 'future-iat-past-nbf': $claims['iat'] = time() + 3600; $claims['nbf'] = time() - 1; $claims['exp'] = $claims['iat'] + 600; break;
            case 'missing-nbf': unset($claims['nbf']); break;
            case 'string-nbf': $claims['nbf'] = (string)$claims['nbf']; break;
            case 'float-nbf': $claims['nbf'] = $claims['nbf'] + 0.5; break;
            case 'future-nbf': $claims['nbf'] = time() + 3600; break;
            case 'missing-exp': unset($claims['exp']); break;
            case 'string-exp': $claims['exp'] = (string)$claims['exp']; break;
            case 'float-exp': $claims['exp'] = $claims['exp'] + 0.5; break;
            case 'expired-beyond-leeway': $claims['iat'] = time() - 1000; $claims['nbf'] = $claims['iat']; $claims['exp'] = time() - 61; break;
            case 'expiry-before-issued': $claims['exp'] = $claims['iat'] - 1; break;
            case 'expiry-equals-issued': $claims['exp'] = $claims['iat']; break;
            case 'not-before-after-expiry': $claims['iat'] = time() - 30; $claims['nbf'] = time(); $claims['exp'] = time() - 10; break;
            case 'forbidden-pwd': $claims['pwd'] = ''; break;
        }
        $token = \Firebase\JWT\JWT::encode($claims, $GLOBALS['ms0b_token_signing_key'], 'HS256');
        $reads = 0; $reader = ms0b_token_reader($context, $reads);
        ms0b_token_deny(static fn() => $context['token_service']->verify($context['app'], $token, $reader));
        audit_expect($reads === 0, 'incompatible signed claims may not reach current credential reader');
        ms0b_token_verify($context);
    });
}
foreach (['wrong-key', 'hs384', 'malformed'] as $variant) {
    ms0b_token_case('A22', 'v2-signature-' . $variant, 'wrong signature/algorithm/malformed encoding reject before reader and preserve valid session', static function () use ($variant): void {
        $context = ms0b_token_ready();
        $token = match ($variant) {
            'wrong-key' => \Firebase\JWT\JWT::encode($context['claims'], bin2hex(random_bytes(32)), 'HS256'),
            'hs384' => \Firebase\JWT\JWT::encode($context['claims'], $GLOBALS['ms0b_token_signing_key'], 'HS384'),
            default => 'invalid.invalid.invalid',
        };
        $reads = 0; $reader = ms0b_token_reader($context, $reads);
        ms0b_token_deny(static fn() => $context['token_service']->verify($context['app'], $token, $reader));
        audit_expect($reads === 0, 'bad signature must not reach credential reader'); ms0b_token_verify($context);
    });
}
ms0b_token_case('A18', 'v2-fixed-app-selection', 'token purpose cannot select another app authenticator; other app rejection leaves original session usable', static function (): void {
    $context = ms0b_token_ready(); $reads = 0;
    ms0b_token_deny(static function () use ($context, &$reads): void {
        $context['token_service']->verify('outapi', $context['issued']['token'], static function (string $id) use (&$reads): string { ++$reads; return ms0b_token_digest('outapi', $id); });
    });
    audit_expect($reads === 0, 'cross-app binding rejection must happen before credential query'); ms0b_token_verify($context);
    ms0b_token_deny(static fn() => $context['token_service']->verify('unlisted', $context['issued']['token'], ms0b_token_reader($context)));
});
ms0b_token_case('A18', 'v2-exact-signed-token-digest', 'well-formed signature-valid alternate encoding/extra claim cannot reuse the original sid because server binds exact JWT digest', static function (): void {
    $context = ms0b_token_ready(); $claims = $context['claims']; $claims['audit_annotation'] = 'different-signed-token';
    $alternate = \Firebase\JWT\JWT::encode($claims, $GLOBALS['ms0b_token_signing_key'], 'HS256');
    audit_expect($alternate !== $context['issued']['token'], 'controlled alternate token must actually differ');
    ms0b_token_deny(static fn() => ms0b_token_verify($context, $alternate));
    ms0b_token_verify($context);
});
foreach (['unknown-sid', 'wrong-version'] as $variant) {
    ms0b_token_case('A17', 'v2-valid-shaped-claims-' . $variant, 'valid-shaped but noncurrent sid/version claims reject through real session validation without rewriting original record', static function () use ($variant): void {
        $context = ms0b_token_ready(); $claims = $context['claims'];
        $claims[$variant === 'unknown-sid' ? 'sid' : 'session_version'] = bin2hex(random_bytes(16));
        $token = \Firebase\JWT\JWT::encode($claims, $GLOBALS['ms0b_token_signing_key'], 'HS256');
        ms0b_token_deny(static fn() => ms0b_token_verify($context, $token)); ms0b_token_verify($context);
    });
}

ms0b_token_case('A21', 'v2-credential-stamp-binding', 'server HMAC binds app/id/current digest and independent key; no stamp is present in JWT', static function (): void {
    $context = ms0b_token_ready(); $stamp = $context['stamp'];
    audit_expect($stamp === hash_hmac('sha256', $context['app'] . '|' . $context['id'] . '|' . $context['digest'], $GLOBALS['ms0b_token_stamp_key'])
        && (bool)preg_match('/^[a-f0-9]{64}$/D', $stamp), 'credential stamp must implement exact independent keyed binding');
    audit_expect(\crmeb\services\auth\CredentialStamp::for('outapi', $context['id'], $context['digest']) !== $stamp
        && \crmeb\services\auth\CredentialStamp::for($context['app'], $GLOBALS['ms0b_token_fixture_ids']['admin_second'], $context['digest']) !== $stamp
        && \crmeb\services\auth\CredentialStamp::for($context['app'], $context['id'], hash('sha256', 'changed')) !== $stamp, 'app, subject and current digest each affect stamp');
    $changed = $context['config']; $changed['stamp_key'] = bin2hex(random_bytes(32)); ms0b_token_set_config($changed);
    audit_expect(\crmeb\services\auth\CredentialStamp::for($context['app'], $context['id'], $context['digest']) !== $stamp, 'independent key rotation must change stamp');
    ms0b_token_deny(static fn() => ms0b_token_verify($context));
    ms0b_token_set_config($context['config']); ms0b_token_verify($context);
});
foreach (['changed-digest', 'empty-digest', 'missing-subject'] as $variant) {
    ms0b_token_case('A21', 'v2-current-credential-' . $variant, 'current credential change or missing subject rejects readonly; original digest still proves original session survives denial', static function () use ($variant): void {
        $context = ms0b_token_ready();
        $reader = match ($variant) {
            'changed-digest' => static fn(string $id): string => hash('sha256', 'different-current-credential'),
            'empty-digest' => static fn(string $id): string => '',
            default => static function (string $id): ?string {
                $statement = audit_db()->prepare('SELECT pwd FROM eb_system_admin WHERE id=?');
                $statement->execute(['909999']); $value = $statement->fetchColumn();
                audit_expect($value === false, 'absent isolated subject must actually be absent'); return null;
            },
        };
        ms0b_token_deny(static fn() => $context['token_service']->verify($context['app'], $context['issued']['token'], $reader));
        ms0b_token_verify($context);
    });
}
ms0b_token_case('A23', 'v2-current-credential-real-pdo-failure', 'actual refused isolated database connection in current-reader becomes controlled 503, no token/state change or sensitive previous', static function (): void {
    $context = ms0b_token_ready(); $config = audit_config();
    $reader = static function (string $id) use ($config): string {
        audit_expect(gethostbyname('audit-mysql') === audit_manifest()['services']['audit-mysql']['address'], 'failed reader must target only verified isolated database service');
        // The actual dedicated service remains healthy; only this reader's
        // connection is refused at a closed port. It is not a DB service outage.
        new PDO('mysql:host=audit-mysql;port=1;dbname=audit_auth;charset=utf8mb4', 'audit_app', $config['database']['password'], [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        throw new RuntimeException('closed isolated database port unexpectedly accepted connection');
    };
    ms0b_token_deny(static fn() => $context['token_service']->verify($context['app'], $context['issued']['token'], $reader), \crmeb\exceptions\AuthUnavailableException::class, 503);
    ms0b_token_verify($context);
});
ms0b_token_case('A21', 'v2-reader-controlled-auth-failure', 'credential reader controlled AuthException remains 401 and cannot initialize language/cache state', static function (): void {
    $context = ms0b_token_ready();
    ms0b_token_deny(static fn() => $context['token_service']->verify($context['app'], $context['issued']['token'], static function (string $id): string { throw new \crmeb\exceptions\AuthSessionException(); }));
    ms0b_token_verify($context);
});
foreach (['stale-version', 'blocked-account', 'missing-account'] as $variant) {
    ms0b_token_case('A21', 'v2-issue-cas-' . $variant, 'issuance against stale, blocked or missing authorization state returns no token and never initializes missing account', static function () use ($variant): void {
        $context = ms0b_token_context();
        if ($variant === 'stale-version') { $context['store']->rotateAccount($context['app'], $context['id']); }
        elseif ($variant === 'blocked-account') { $context['store']->block($context['app'], $context['id']); }
        else { audit_redis()->del($context['account_key']); }
        $returned = false;
        ms0b_token_deny(static function () use ($context, &$returned): void { ms0b_token_issue($context); $returned = true; });
        audit_expect($returned === false, 'CAS rejection must never return a token envelope');
        if ($variant === 'missing-account') { audit_expect(audit_redis()->exists($context['account_key']) === 0, 'issue cannot perform credential-login account initialization'); }
    });
}

foreach ([
    'before-eval' => ['when' => 'before', 'action' => 'throw'],
    'after-eval-response-loss' => ['when' => 'after', 'action' => 'throw'],
    'after-eval-disconnect' => ['when' => 'after', 'action' => 'disconnect'],
] as $name => $fault) {
    ms0b_token_case('A25', 'v2-issue-fault-' . $name, 'real persistence failure/acknowledgement loss never returns token; only actually executed session write may exist', static function () use ($fault): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_token_context(); $delegate = audit_redis();
        $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1] + $fault]);
        $service = new \crmeb\services\auth\AuthTokenV2(new \crmeb\services\auth\AuthSessionStore($faulty, $context['epoch']));
        $before = ms0b_token_snapshot(); $returned = false;
        ms0b_token_exception(static function () use ($service, $context, &$returned): void { $service->issue($context['app'], $context['id'], $context['version'], $context['stamp']); $returned = true; }, \crmeb\exceptions\AuthUnavailableException::class, 503);
        audit_expect($returned === false, 'failed persistence acknowledgement must not return any token envelope');
        if ($fault['action'] === 'disconnect') { audit_expect(!$delegate->isConnected(), 'actual Redis connection must have been closed'); }
        $created = ms0b_token_scan($context['prefix'] . $context['app'] . ':session:*');
        audit_expect(count($created) === ($fault['when'] === 'after' ? 1 : 0), 'independent observer must distinguish unexecuted and executed issuance');
        audit_expect(ms0b_token_changes($before, ms0b_token_snapshot(), $created) === [], 'faulted issuance may only add its one actually executed test session');
        $GLOBALS['ms0b_token_observations'][] = ['fault_when' => $fault['when'], 'fault_action' => $fault['action'], 'token_returned' => false, 'actually_created_sessions' => count($created)];
    });
}
foreach (['throw', 'disconnect'] as $action) {
    ms0b_token_case('A23', 'v2-verify-real-redis-fault-' . $action, 'real Redis validation fault becomes controlled 503 and writes nothing', static function () use ($action): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_token_ready(); $delegate = audit_redis();
        $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1, 'when' => 'after', 'action' => $action]]);
        $service = new \crmeb\services\auth\AuthTokenV2(new \crmeb\services\auth\AuthSessionStore($faulty, $context['epoch']));
        ms0b_token_deny(static fn() => ms0b_token_verify($context, null, $service), \crmeb\exceptions\AuthUnavailableException::class, 503);
        if ($action === 'disconnect') { audit_expect(!$delegate->isConnected(), 'real validation connection must close'); }
        ms0b_token_verify($context);
    });
}
ms0b_token_case('A28', 'v2-refresh-single-exchange', 'refresh returns a different persisted sid/token; original and repeated refresh reject, replacement validates', static function (): void {
    $context = ms0b_token_ready('outapi'); $before = ms0b_token_snapshot();
    $fresh = $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context));
    audit_expect(is_array($fresh) && is_string($fresh['token'] ?? null) && is_string($fresh['sid'] ?? null) && is_int($fresh['exp'] ?? null)
        && $fresh['sid'] !== $context['issued']['sid'] && $fresh['token'] !== $context['issued']['token'], 'refresh must return a new random session/token envelope');
    $newKey = $context['prefix'] . $context['app'] . ':session:' . $fresh['sid'];
    audit_expect(audit_redis()->hGet($context['session_key'], 'status') === 'consumed'
        && audit_redis()->hGet($newKey, 'token_digest') === hash('sha256', $fresh['token'])
        && audit_redis()->hGet($newKey, 'credential_stamp') === $context['stamp'], 'independent observer sees consumed old and fully persisted bound replacement');
    audit_expect(ms0b_token_changes($before, ms0b_token_snapshot(), [$context['session_key'], $newKey]) === [], 'refresh may modify only the exact old and replacement sessions');
    $after = ms0b_token_snapshot();
    audit_expect(ms0b_token_same_expiry($before['expires'][$context['session_key']], $after['expires'][$context['session_key']]), 'consuming the old sid may not extend its absolute expiry');
    audit_expect(audit_redis()->ttl($newKey) > 0 && audit_redis()->ttl($newKey) <= $fresh['exp'] - time() + 60, 'replacement expiry must stay bounded by its returned JWT exp');
    ms0b_token_deny(static fn() => ms0b_token_verify($context));
    ms0b_token_deny(static fn() => $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context)));
    audit_expect(ms0b_token_verify($context, $fresh['token']) === ['id' => $context['id'], 'sid' => $fresh['sid']], 'replacement token must be usable');
});
foreach ([
    'before-redeem' => ['when' => 'before', 'action' => 'throw'],
    'after-redeem-response-loss' => ['when' => 'after', 'action' => 'throw'],
    'after-redeem-disconnect' => ['when' => 'after', 'action' => 'disconnect'],
] as $name => $fault) {
    ms0b_token_case('A25', 'v2-refresh-fault-' . $name, 'failed refresh never returns token; executed redemption remains consumed and cannot be repeated', static function () use ($fault): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_token_ready('outapi'); $delegate = audit_redis();
        // Recorded API design: refresh verifies through read-only store.verify
        // (EVAL 1), then executes store.redeem (EVAL 2). Confirm this call flow
        // before installing this draft; do not change assertions to fit a fault
        // that missed redemption. A combined script requires a reviewed target.
        $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 2] + $fault]);
        $service = new \crmeb\services\auth\AuthTokenV2(new \crmeb\services\auth\AuthSessionStore($faulty, $context['epoch']));
        $before = ms0b_token_snapshot(); $returned = false;
        ms0b_token_exception(static function () use ($service, $context, &$returned): void { $service->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context)); $returned = true; }, \crmeb\exceptions\AuthUnavailableException::class, 503);
        audit_expect($returned === false, 'faulted refresh may not return any token envelope');
        if ($fault['action'] === 'disconnect') { audit_expect(!$delegate->isConnected(), 'actual redemption connection must close'); }
        $keys = ms0b_token_scan($context['prefix'] . $context['app'] . ':session:*');
        $newKeys = array_values(array_filter($keys, static fn(string $key): bool => $key !== $context['session_key']));
        if ($fault['when'] === 'before') {
            audit_expect($newKeys === [] && ms0b_token_changes($before, ms0b_token_snapshot()) === [], 'unexecuted redemption keeps all values and old session usable');
            ms0b_token_verify($context);
        } else {
            audit_expect(count($newKeys) === 1 && audit_redis()->hGet($context['session_key'], 'status') === 'consumed', 'executed redemption is independently visible; old sid must never be revived');
            audit_expect(ms0b_token_changes($before, ms0b_token_snapshot(), [$context['session_key'], $newKeys[0]]) === [], 'acknowledgement loss changes only two exact exchange records');
            $after = ms0b_token_snapshot();
            audit_expect(ms0b_token_same_expiry($before['expires'][$context['session_key']], $after['expires'][$context['session_key']]), 'lost acknowledgement may not renew the consumed old session');
            audit_expect(!array_key_exists('previous_token_digest', audit_redis()->hGetAll($newKeys[0])), 'validation-only old digest must not be persisted in replacement');
            ms0b_token_deny(static fn() => ms0b_token_verify($context));
            ms0b_token_deny(static fn() => $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context)));
        }
        $GLOBALS['ms0b_token_observations'][] = ['fault_when' => $fault['when'], 'fault_action' => $fault['action'], 'token_returned' => false, 'actually_created_replacements' => count($newKeys)];
    });
}
ms0b_token_case('A28', 'v2-revoke-only-own-app-sid', 'authenticated revoke affects only one bound sid; same-account second session and other app stay usable', static function (): void {
    $context = ms0b_token_ready(); $second = ms0b_token_issue($context);
    $out = $context; $out['app'] = 'outapi'; $out['id'] = $GLOBALS['ms0b_token_fixture_ids']['out_primary']; $out['digest'] = ms0b_token_digest('outapi', $out['id']);
    $out['version'] = $context['store']->initAccount('outapi', $out['id']);
    $out['stamp'] = \crmeb\services\auth\CredentialStamp::for('outapi', $out['id'], $out['digest']);
    $out['issued'] = ms0b_token_issue($out);
    $before = ms0b_token_snapshot();
    $context['token_service']->revoke($context['app'], $context['issued']['token']);
    audit_expect(ms0b_token_changes($before, ms0b_token_snapshot(), [$context['session_key']]) === [], 'revoke may alter only its exact authenticated app session');
    ms0b_token_deny(static fn() => ms0b_token_verify($context));
    ms0b_token_verify($context, $second['token']); ms0b_token_verify($out);
});
ms0b_token_case('A18', 'v2-revoke-cross-app-denied', 'cross-app revoke cannot delete original session or any other state', static function (): void {
    $context = ms0b_token_ready();
    ms0b_token_deny(static fn() => $context['token_service']->revoke('outapi', $context['issued']['token']));
    ms0b_token_verify($context);
});
ms0b_token_case('A18', 'v2-revoke-exact-token-digest-required', 'signature-valid same-sid alternate JWT cannot revoke the original bound token session', static function (): void {
    $context = ms0b_token_ready(); $claims = $context['claims']; $claims['audit_annotation'] = 'alternate-revoke-input';
    $token = \Firebase\JWT\JWT::encode($claims, $GLOBALS['ms0b_token_signing_key'], 'HS256');
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $token));
    ms0b_token_verify($context);
});
ms0b_token_case('A17', 'v2-revoke-wrong-subject-binding', 'valid-shaped signature-valid other-subject claims may not delete the original sid record', static function (): void {
    $context = ms0b_token_ready(); $otherId = $GLOBALS['ms0b_token_fixture_ids']['admin_second'];
    $otherVersion = $context['store']->initAccount($context['app'], $otherId);
    $claims = $context['claims']; $claims['jti']['id'] = $otherId; $claims['session_version'] = $otherVersion;
    $token = \Firebase\JWT\JWT::encode($claims, $GLOBALS['ms0b_token_signing_key'], 'HS256');
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $token));
    ms0b_token_verify($context);
});
ms0b_token_case('A29', 'v2-revoke-missing-authority-no-delete-or-revival', 'lost account or session returns controlled 401; revoke cannot bypass missing authority or recreate it', static function (): void {
    $context = ms0b_token_ready(); audit_redis()->del($context['account_key']);
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $context['issued']['token']));
    audit_expect(audit_redis()->exists($context['account_key']) === 0
        && audit_redis()->hGet($context['session_key'], 'status') === 'active', 'missing-account revoke must neither recreate account nor delete active session');
    $context = ms0b_token_ready(); audit_redis()->del($context['session_key']);
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $context['issued']['token']));
    audit_expect(audit_redis()->exists($context['session_key']) === 0, 'missing session must not be revived by revoke');
});
ms0b_token_case('A25', 'v2-revoke-consumed-session-denied', 'consumed refresh sid cannot be revoked as an active session; replacement stays valid and old TTL unchanged', static function (): void {
    $context = ms0b_token_ready('outapi');
    $fresh = $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context));
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $context['issued']['token']));
    audit_expect(audit_redis()->hGet($context['session_key'], 'status') === 'consumed', 'revoke denial must preserve consumed marker');
    ms0b_token_verify($context, $fresh['token']);
});
ms0b_token_case('A17', 'v2-revoke-corrupt-server-stamp-denied', 'revoke without DB callback must still require structurally valid stored credential stamp before deleting sid', static function (): void {
    $context = ms0b_token_ready(); audit_redis()->hSet($context['session_key'], 'credential_stamp', 'invalid');
    ms0b_token_deny(static fn() => $context['token_service']->revoke($context['app'], $context['issued']['token']));
    audit_expect(audit_redis()->exists($context['session_key']) === 1, 'malformed bound record must remain unchanged on denial');
});
ms0b_token_case('A29', 'v2-token-missing-account-or-session', 'token verify/refresh reject lost state with 401 and never recreate it', static function (): void {
    $context = ms0b_token_ready('outapi'); audit_redis()->del($context['account_key']);
    ms0b_token_deny(static fn() => ms0b_token_verify($context));
    ms0b_token_deny(static fn() => $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context)));
    audit_expect(audit_redis()->exists($context['account_key']) === 0, 'lost account must remain absent');
    $context = ms0b_token_ready('outapi'); audit_redis()->del($context['session_key']);
    ms0b_token_deny(static fn() => ms0b_token_verify($context));
    ms0b_token_deny(static fn() => $context['token_service']->refresh($context['app'], $context['issued']['token'], ms0b_token_reader($context)));
    audit_expect(audit_redis()->exists($context['session_key']) === 0, 'lost session must remain absent');
});
ms0b_token_case('A29', 'v2-token-reused-instance-a-b-a', 'same token service instance verifies A/B/A without retaining token, identity or credential callback', static function (): void {
    $a = ms0b_token_ready(); $b = $a; $b['id'] = $GLOBALS['ms0b_token_fixture_ids']['admin_second'];
    $b['version'] = $a['store']->initAccount($b['app'], $b['id']); $b['digest'] = ms0b_token_digest($b['app'], $b['id']);
    $b['stamp'] = \crmeb\services\auth\CredentialStamp::for($b['app'], $b['id'], $b['digest']); $b['issued'] = ms0b_token_issue($b);
    audit_expect(ms0b_token_verify($a)['id'] === $a['id'] && ms0b_token_verify($b, null, $a['token_service'])['id'] === $b['id'], 'A then B must resolve their own current identities');
    ms0b_token_deny(static fn() => $a['token_service']->verify($a['app'], $a['issued']['token'], static fn(string $id): string => $b['digest']));
    audit_expect(ms0b_token_verify($a, null, $a['token_service'])['id'] === $a['id'], 'A after B and failed callback must still resolve A');
});

audit_finish();
