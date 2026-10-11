<?php
declare(strict_types=1);

// Task 2 integration suite: real ThinkPHP, Redis, separate PHP processes, and
// the manifest-owned fault controller. No class, transport, or language stub.
// Task 1 has passed; HASH/redeem/owner contracts are recorded in plan 05.
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');

function ms0b_store_boot_framework(): void
{
    require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
    $app = new \think\App(audit_repo_dir() . '/crmeb/');
    $config = audit_config();
    $GLOBALS['ms0b_store_lang_prefix'] = 'audit:lang:' . getenv('AUDIT_RUN_ID') . ':' . getmypid() . ':';
    $app->env->set([
        'APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
        'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
        'DATABASE_PASSWORD' => $config['database']['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis',
        'REDIS_PORT' => '6379', 'REDIS_REDIS_PASSWORD' => $config['redis_password'],
        'CACHE_CACHE_PREFIX' => $GLOBALS['ms0b_store_lang_prefix'],
    ]);
    $app->initialize();
    audit_expect(function_exists('getLang'), 'real application common functions must be loaded');
    audit_expect($app->config->get('cache.stores.redis.prefix') === $GLOBALS['ms0b_store_lang_prefix'], 'real CLI Redis language-cache prefix must be configured');
    // The real cache uses a fresh prefix in this CLI process. No language cache
    // is warmed: AuthSessionException/Unavailable must bypass getLang entirely.
}
ms0b_store_boot_framework();

// Child payload travels through private stdin, never command arguments/files.
// Separate PHP processes create fresh framework and Redis connections.
if (($argv[1] ?? '') === '--redeem-child') {
    $payload = json_decode((string)fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    audit_expect(is_array($payload), 'concurrent worker input must be structured');
    $store = new \crmeb\services\auth\AuthSessionStore(audit_redis(), $payload['epoch']);
    echo "READY\n"; flush();
    audit_expect(trim((string)fgets(STDIN)) === 'GO', 'concurrent worker needs release barrier');
    try {
        $store->redeem($payload['app'], $payload['old_sid'], $payload['id'], $payload['session']);
        $result = ['outcome' => 'SUCCESS', 'code' => 0];
    } catch (\crmeb\exceptions\AuthException $error) {
        $result = ['outcome' => 'AUTH', 'code' => $error->getCode()];
    } catch (Throwable $error) {
        $result = ['outcome' => 'OTHER', 'class' => get_class($error), 'code' => $error->getCode()];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$GLOBALS['ms0b_store_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
$GLOBALS['ms0b_store_counter'] = 0;
$GLOBALS['ms0b_store_keys'] = [];
$GLOBALS['ms0b_store_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect((bool)preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table), 'unexpected snapshot table rejected');
    $GLOBALS['ms0b_store_spec']['tables'][$table] = null;
}

function ms0b_store_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['ms0b_store_spec']);
    $keys = array_keys($snapshot['redis']['*']);
    $snapshot['expires'] = [];
    if ($keys === []) { return $snapshot; }
    // DUMP excludes expiry. A single read-only Lua call takes Redis TIME and
    // PTTL together; compare absolute deadlines, never a naturally falling TTL.
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
function ms0b_store_same_expiry(int $before, int $after): bool
{
    // -1 (persistent) and -2 (absent) must match exactly. Positive absolute
    // deadlines allow only the one-millisecond TIME/PTTL rounding boundary.
    return $before < 0 || $after < 0 ? $before === $after : abs($before - $after) <= 1;
}
function ms0b_store_snapshot_summary(array $snapshot): array
{
    return ['database_tables' => count($snapshot['tables']), 'redis_values' => count($snapshot['redis']['*']),
        'all_table_values_sha256' => hash('sha256', json_encode($snapshot['tables'], JSON_THROW_ON_ERROR)),
        'all_redis_values_sha256' => hash('sha256', json_encode($snapshot['redis']['*'], JSON_THROW_ON_ERROR)),
        'redis_expiry_entries' => count($snapshot['expires']),
        'all_redis_absolute_deadlines_sha256' => hash('sha256', json_encode($snapshot['expires'], JSON_THROW_ON_ERROR)),
        'deadline_rounding_tolerance_ms' => 1];
}
function ms0b_store_changed(array $before, array $after, array $allowed = []): array
{
    // Never emit raw cache keys, session IDs, token digests, or stamps.
    $differences = [];
    foreach ($before['tables'] as $table => $row) {
        if ($row !== ($after['tables'][$table] ?? null)) { $differences[] = 'table:' . $table; }
    }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowed, true)) { continue; }
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) {
            $differences[] = 'cache-sha256:' . substr(hash('sha256', $key), 0, 12);
        }
        if (!ms0b_store_same_expiry($before['expires'][$key] ?? -2, $after['expires'][$key] ?? -2)) {
            $differences[] = 'cache-sha256:' . substr(hash('sha256', $key), 0, 12) . ':expiry';
        }
    }
    return $differences;
}
function ms0b_store_context(string $app = 'adminapi'): array
{
    $number = ++$GLOBALS['ms0b_store_counter'];
    $epoch = 'audit-ms0b-' . getenv('AUDIT_RUN_ID') . '-' . $number;
    $id = (string)(900000 + $number);
    $context = ['epoch' => $epoch, 'app' => $app, 'id' => $id];
    $context['account_key'] = ms0b_store_account_key($context);
    $context['store'] = ms0b_store_make($context);
    return $context;
}
function ms0b_store_track(string $key): string
{
    audit_expect(!isset($GLOBALS['ms0b_store_keys'][$key]), 'disposable test key must be uniquely registered');
    audit_expect(audit_redis()->exists($key) === 0, 'disposable test must never overwrite existing state');
    $GLOBALS['ms0b_store_keys'][$key] = true;
    return $key;
}
function ms0b_store_account_key(array $context): string
{
    return ms0b_store_track('auth:v2:' . $context['epoch'] . ':' . $context['app'] . ':account:' . $context['id']);
}
function ms0b_store_session_key(array $context, string $sid): string
{
    return ms0b_store_track('auth:v2:' . $context['epoch'] . ':' . $context['app'] . ':session:' . $sid);
}
function ms0b_store_make(array $context, ?Redis $redis = null): object
{
    audit_expect(class_exists(\crmeb\services\auth\AuthSessionStore::class), 'AuthSessionStore has not been implemented');
    return new \crmeb\services\auth\AuthSessionStore($redis ?? audit_redis(), $context['epoch']);
}
function ms0b_store_session(array $context, string $version, int $lifetime = 600): array
{
    $session = [
        'sid' => bin2hex(random_bytes(16)),
        'type' => $context['app'] === 'adminapi' ? 'admin' : 'out',
        'audience' => $context['app'] . '-audit',
        'session_version' => $version,
        'token_digest' => hash('sha256', random_bytes(32)),
        'credential_stamp' => hash_hmac('sha256', random_bytes(32), random_bytes(32)),
        'expires_at' => time() + $lifetime,
    ];
    if (isset($context['session'])) {
        $session['previous_token_digest'] = $context['session']['token_digest'];
        $session['credential_stamp'] = $context['session']['credential_stamp'];
        $session['type'] = $context['session']['type'];
        $session['audience'] = $context['session']['audience'];
    }
    ms0b_store_session_key($context, $session['sid']);
    return $session;
}
function ms0b_store_ready(string $app = 'adminapi', int $lifetime = 600): array
{
    $context = ms0b_store_context($app);
    $context['version'] = $context['store']->initAccount($app, $context['id']);
    audit_expect((bool)preg_match('/^[a-f0-9]{32}$/D', $context['version']), 'version must contain 128 random bits as lowercase hex');
    $context['session'] = ms0b_store_session($context, $context['version'], $lifetime);
    $context['session_key'] = 'auth:v2:' . $context['epoch'] . ':' . $app . ':session:' . $context['session']['sid'];
    $context['store']->createSession($app, $context['id'], $context['version'], $context['session']);
    return $context;
}
function ms0b_store_verify(array $context, ?array $overrides = null, ?object $store = null): void
{
    $parameters = array_replace([
        'app' => $context['app'], 'sid' => $context['session']['sid'], 'id' => $context['id'],
        'type' => $context['session']['type'], 'audience' => $context['session']['audience'],
        'version' => $context['version'], 'digest' => $context['session']['token_digest'],
        'stamp' => $context['session']['credential_stamp'],
    ], $overrides ?? []);
    ($store ?? $context['store'])->verify(...array_values($parameters));
}
function ms0b_store_exception(callable $operation, string $class, int $code): Throwable
{
    $caught = null;
    try { $operation(); } catch (Throwable $error) { $caught = $error; }
    audit_expect($caught !== null && is_a($caught, $class) && $caught->getCode() === $code,
        'must reject with controlled ' . $class . ' code=' . $code . '; observed=' . ($caught ? get_class($caught) . '/' . $caught->getCode() : 'success'));
    return $caught;
}
function ms0b_store_deny(callable $operation, string $class = \crmeb\exceptions\AuthException::class, int $code = 401): void
{
    $before = ms0b_store_snapshot();
    $classificationFailure = null;
    try { ms0b_store_exception($operation, $class, $code); } catch (Throwable $error) { $classificationFailure = $error; }
    $after = ms0b_store_snapshot();
    $differences = ms0b_store_changed($before, $after);
    $GLOBALS['ms0b_store_observations'][] = ['exception' => $class, 'code' => $code, 'before' => ms0b_store_snapshot_summary($before),
        'after' => ms0b_store_snapshot_summary($after), 'classification_matched' => $classificationFailure === null, 'unexpected_changes' => $differences];
    if ($classificationFailure !== null) { throw $classificationFailure; }
    audit_expect($differences === [], 'rejection must preserve every Redis value and every database table');
}
function ms0b_store_case(string $id, string $subcase, string $expected, callable $operation): void
{
    $only = null;
    foreach ($GLOBALS['argv'] as $argument) { if (str_starts_with($argument, '--only=')) { $only = explode(',', substr($argument, 7)); } }
    if ($only !== null && !in_array($id, $only, true)) { return; }
    audit_case($id, $subcase, 'INTEGRATION', static function () use ($id, $subcase, $expected, $operation): array {
        audit_guard_assert();
        $GLOBALS['ms0b_store_keys'] = [];
        $GLOBALS['ms0b_store_observations'] = [];
        $before = ms0b_store_snapshot();
        $failure = null;
        try {
            audit_expect(class_exists(\crmeb\services\auth\AuthSessionStore::class), 'AuthSessionStore has not been implemented');
            $operation();
        } catch (Throwable $error) { $failure = $error; }
        finally {
            $redis = audit_redis();
            foreach (array_keys($GLOBALS['ms0b_store_keys']) as $key) { $redis->del($key); }
        }
        $after = ms0b_store_snapshot();
        $differences = ms0b_store_changed($before, $after);
        $allUnexpected = array_values(array_unique(array_merge($differences, ...array_column($GLOBALS['ms0b_store_observations'], 'unexpected_changes'))));
        $passed = $failure === null && $differences === [];
        $reference = 'evidence/store-' . (getenv('AUDIT_PHASE') ?: 'after') . '-' . $GLOBALS['ms0b_store_execution'] . '-' . $id . '-' . $subcase . '.json';
        audit_write_json(audit_run_dir() . '/' . $reference, [
            'milestone' => 'MS0b', 'case_id' => $id, 'subcase' => $subcase,
            'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'run_id' => getenv('AUDIT_RUN_ID'),
            'expected' => $expected, 'passed' => $passed,
            'failure_class' => $failure ? get_class($failure) : null,
            'failure_detail' => $failure instanceof RuntimeException && !$failure instanceof RedisException && !$failure instanceof PDOException ? $failure->getMessage() : null,
            'operations' => $GLOBALS['ms0b_store_observations'], 'unexpected_changes_after_exact_cleanup' => $differences,
            'before' => ms0b_store_snapshot_summary($before), 'after_exact_cleanup' => ms0b_store_snapshot_summary($after),
            'database_tables_checked' => count($before['tables']), 'all_redis_values_checked' => true,
            'all_redis_absolute_deadlines_checked' => true, 'deadline_rounding_tolerance_ms' => 1,
            'mysql_and_redis_sentinels' => 'verified before case; included in all-value snapshots',
        ]);
        return [
            'status' => $passed ? 'PASS' : 'FAIL', 'expected' => $expected,
            'actual' => $failure ? 'controlled case failure: ' . get_class($failure) . ($failure instanceof RuntimeException && !$failure instanceof RedisException && !$failure instanceof PDOException ? ': ' . $failure->getMessage() : '') : 'checks passed; all database tables and non-test Redis values unchanged',
            'db_diff' => array_values(array_filter($allUnexpected, static fn(string $value): bool => str_starts_with($value, 'table:'))),
            'cache_diff' => array_values(array_filter($allUnexpected, static fn(string $value): bool => str_starts_with($value, 'cache-sha256:'))),
            'evidence_ref' => $reference,
            'notes' => 'Real Redis and framework; setup/cleanup use only registered exact disposable keys; negative/read-only operations compare full values and absolute Redis expiry deadlines before cleanup, with at most 1ms rounding tolerance.',
        ];
    });
}

ms0b_store_case('A23', 'v2-cold-language-cache-zero-writes', 'cold actual language cache stays absent during controlled session rejection', static function (): void {
    $redis = audit_redis(); $cursor = null; $keys = [];
    do { foreach ($redis->scan($cursor, $GLOBALS['ms0b_store_lang_prefix'] . '*', 100) ?: [] as $key) { $keys[] = $key; } } while ($cursor !== 0);
    audit_expect($keys === [], 'real CLI language cache must be cold before rejection');
    $context = ms0b_store_ready();
    ms0b_store_deny(static fn() => ms0b_store_verify($context, ['digest' => hash('sha256', 'wrong-digest')]));
    $cursor = null; $keys = [];
    do { foreach ($redis->scan($cursor, $GLOBALS['ms0b_store_lang_prefix'] . '*', 100) ?: [] as $key) { $keys[] = $key; } } while ($cursor !== 0);
    audit_expect($keys === [], 'controlled Store errors must never invoke language cache initialization');
});

foreach (['adminapi', 'outapi'] as $app) {
    ms0b_store_case('A28', 'v2-create-verify-' . $app, 'valid initialized account can create and verify its bound session without rewriting it', static function () use ($app): void {
        $context = ms0b_store_ready($app);
        $redis = audit_redis();
        $account = $redis->hGetAll($context['account_key']);
        $session = $redis->hGetAll($context['session_key']);
        audit_expect(($account['session_version'] ?? null) === $context['version'] && ($account['blocked'] ?? null) === '0' && ($account['initialized'] ?? null) === '1', 'initialized account hash must expose its documented state');
        foreach (['subject_id', 'type', 'audience', 'session_version', 'token_digest', 'credential_stamp', 'expires_at', 'status'] as $field) {
            audit_expect(array_key_exists($field, $session), 'created session must include ' . $field);
        }
        audit_expect($session['subject_id'] === $context['id'] && $session['status'] === 'active', 'session must bind the exact subject and active status');
        audit_expect(!array_key_exists('previous_token_digest', $session), 'previous token digest is a redeem check parameter, never a stored new-session field');
        $before = ms0b_store_snapshot();
        ms0b_store_verify($context); ms0b_store_verify($context);
        audit_expect(ms0b_store_changed($before, ms0b_store_snapshot()) === [], 'successful verify must also be read-only');
        audit_expect($context['store']->initAccount($app, $context['id']) === $context['version'], 'repeat valid login initialization must reuse the current account version');
        audit_expect($redis->ttl($context['account_key']) === -1, 'repeated account initialization must retain persistence');
    });
}

foreach (['session_version', 'blocked', 'initialized'] as $field) {
    ms0b_store_case('A17', 'v2-account-missing-' . $field, 'missing account required field rejects with 401 and zero writes', static function () use ($field): void {
        $context = ms0b_store_ready(); audit_redis()->hDel($context['account_key'], $field);
        ms0b_store_deny(static fn() => ms0b_store_verify($context));
        $new = ms0b_store_session($context, $context['version']);
        ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
        ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
    });
}
foreach (['empty-version' => ['session_version', ''], 'wrong-version' => ['session_version', bin2hex(random_bytes(16))], 'blocked' => ['blocked', '1'], 'bad-blocked' => ['blocked', '2'], 'uninitialized' => ['initialized', '0'], 'bad-initialized' => ['initialized', '2']] as $name => [$field, $value]) {
    ms0b_store_case('A17', 'v2-account-' . $name, 'invalid account field rejects with 401 and zero writes', static function () use ($field, $value): void {
        $context = ms0b_store_ready(); audit_redis()->hSet($context['account_key'], $field, $value);
        ms0b_store_deny(static fn() => ms0b_store_verify($context));
        $new = ms0b_store_session($context, $context['version']);
        ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
        ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
    });
}
foreach (['subject_id', 'type', 'audience', 'session_version', 'token_digest', 'credential_stamp', 'expires_at', 'status'] as $field) {
    ms0b_store_case('A17', 'v2-session-missing-' . $field, 'missing session required field rejects with 401 and zero writes', static function () use ($field): void {
        $context = ms0b_store_ready(); audit_redis()->hDel($context['session_key'], $field);
        ms0b_store_deny(static fn() => ms0b_store_verify($context));
        $new = ms0b_store_session($context, $context['version']);
        ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
    });
}
foreach (['subject_id' => '1', 'type' => 'out', 'audience' => 'other-audience', 'session_version' => '', 'token_digest' => hash('sha256', 'other'), 'credential_stamp' => hash('sha256', 'other-stamp'), 'expires_at' => 'invalid', 'status' => 'consumed'] as $field => $value) {
    ms0b_store_case('A17', 'v2-session-wrong-' . $field, 'invalid session required field rejects with 401 and zero writes', static function () use ($field, $value): void {
        $context = ms0b_store_ready(); audit_redis()->hSet($context['session_key'], $field, $value);
        ms0b_store_deny(static fn() => ms0b_store_verify($context));
        $new = ms0b_store_session($context, $context['version']);
        ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
    });
}
foreach (['account', 'session'] as $kind) {
    ms0b_store_case('A17', 'v2-' . $kind . '-wrong-redis-type', 'wrong Redis value type rejects before Lua writes', static function () use ($kind): void {
        $context = ms0b_store_ready(); $key = $context[$kind . '_key'];
        $redis = audit_redis(); $redis->del($key); $redis->set($key, 'invalid-type');
        ms0b_store_deny(static fn() => ms0b_store_verify($context));
        $new = ms0b_store_session($context, $context['version']);
        ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
        if ($kind === 'account') {
            ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
        }
    });
}
foreach (['id' => '1', 'type' => 'out', 'audience' => 'other-audience', 'version' => bin2hex(random_bytes(16)), 'digest' => hash('sha256', 'wrong-digest'), 'stamp' => hash('sha256', 'wrong-stamp')] as $field => $value) {
    ms0b_store_case('A18', 'v2-request-mismatch-' . $field, 'request binding mismatch rejects without deleting or rewriting any session', static function () use ($field, $value): void {
        $context = ms0b_store_ready();
        ms0b_store_deny(static fn() => ms0b_store_verify($context, [$field => $value]));
        ms0b_store_verify($context);
    });
}
foreach (['adminapi' => 'outapi', 'outapi' => 'adminapi'] as $correct => $wrong) {
    ms0b_store_case('A18', 'v2-wrong-app-' . $correct, 'wrong app namespace rejects; original app session remains valid and unchanged', static function () use ($correct, $wrong): void {
        $context = ms0b_store_ready($correct);
        ms0b_store_deny(static fn() => ms0b_store_verify($context, ['app' => $wrong]));
        ms0b_store_verify($context);
    });
}
foreach (['sid', 'type', 'audience', 'session_version', 'token_digest', 'credential_stamp', 'expires_at'] as $field) {
    ms0b_store_case('A17', 'v2-create-missing-' . $field, 'incomplete create input rejects before creating a session', static function () use ($field): void {
        $context = ms0b_store_ready(); $new = ms0b_store_session($context, $context['version']); unset($new[$field]);
        ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    });
}
foreach (['sid' => '', 'type' => '', 'audience' => '', 'session_version' => '', 'token_digest' => '', 'credential_stamp' => '', 'expires_at' => 'not-a-time'] as $field => $value) {
    ms0b_store_case('A17', 'v2-create-invalid-' . $field, 'invalid create input rejects before any Redis writes', static function () use ($field, $value): void {
        $context = ms0b_store_ready(); $new = ms0b_store_session($context, $context['version']); $new[$field] = $value;
        ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    });
}
foreach (['empty' => '', 'stale' => bin2hex(random_bytes(16))] as $name => $version) {
    ms0b_store_case('A17', 'v2-create-version-' . $name, 'create must CAS the nonempty current account version', static function () use ($version): void {
        $context = ms0b_store_ready(); $new = ms0b_store_session($context, $context['version']);
        ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $version, $new));
    });
}
ms0b_store_case('A17', 'v2-create-existing-sid', 'create cannot overwrite an existing session', static function (): void {
    $context = ms0b_store_ready();
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $context['session']));
});
ms0b_store_case('A17', 'v2-create-mismatched-session-version', 'create input version must match both expected and current account version', static function (): void {
    $context = ms0b_store_ready(); $new = ms0b_store_session($context, bin2hex(random_bytes(16)));
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    ms0b_store_verify($context);
});
ms0b_store_case('A17', 'v2-create-existing-destination-wrong-type', 'wrong-type destination is rejected before any create writes', static function (): void {
    $context = ms0b_store_ready(); $new = ms0b_store_session($context, $context['version']);
    audit_redis()->set('auth:v2:' . $context['epoch'] . ':' . $context['app'] . ':session:' . $new['sid'], 'wrong-type-destination');
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    ms0b_store_verify($context);
});

ms0b_store_case('A23', 'v2-missing-session', 'missing session is AuthException 401 and never recreated', static function (): void {
    $context = ms0b_store_ready(); audit_redis()->del($context['session_key']);
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    audit_expect(audit_redis()->exists($context['session_key']) === 0, 'verify must never recreate missing session');
});
foreach (['pause' => 'unpause', 'stop' => 'start'] as $fault => $restore) {
    ms0b_store_case('A23', 'v2-real-redis-' . $fault, 'real dependency outage is bounded AuthUnavailableException 503; recovery preserves all values', static function () use ($fault, $restore): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_store_ready();
        $before = ms0b_store_snapshot(); $changedService = false; $elapsed = null;
        try {
            audit_fault_redis($fault); $changedService = true; $start = microtime(true);
            ms0b_store_exception(static fn() => ms0b_store_verify($context), \crmeb\exceptions\AuthUnavailableException::class, 503);
            $elapsed = microtime(true) - $start;
        } finally { if ($changedService) { audit_fault_redis($restore); } }
        audit_guard_assert();
        audit_expect(is_float($elapsed) && $elapsed <= 10, 'dependency error must have a bounded timeout');
        audit_expect(ms0b_store_changed($before, ms0b_store_snapshot()) === [], 'dependency failure and recovery must preserve all database and Redis values');
        ms0b_store_verify($context, null, ms0b_store_make($context));
        $GLOBALS['ms0b_store_observations'][] = ['real_service_fault' => $fault, 'classification' => 'AuthUnavailableException', 'code' => 503, 'bounded_seconds' => round($elapsed, 3), 'unexpected_changes' => []];
    });
}
foreach ([
    'before-eval' => ['when' => 'before', 'action' => 'throw'],
    'after-eval-response-loss' => ['when' => 'after', 'action' => 'throw'],
    'after-eval-disconnect' => ['when' => 'after', 'action' => 'disconnect'],
] as $name => $fault) {
    ms0b_store_case('A23', 'v2-verify-fault-' . $name, 'verify Redis errors are controlled 503 with zero writes', static function () use ($fault): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_store_ready(); $delegate = audit_redis();
        $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1] + $fault]);
        $store = ms0b_store_make($context, $faulty);
        ms0b_store_deny(static fn() => ms0b_store_verify($context, null, $store), \crmeb\exceptions\AuthUnavailableException::class, 503);
        if ($fault['action'] === 'disconnect') { audit_expect(!$delegate->isConnected(), 'fault must actually close real connection'); }
        ms0b_store_verify($context);
    });
}

ms0b_store_case('A28', 'v2-session-ttl', 'session expires no later than exp+60; account cannot expire before referencing session', static function (): void {
    $context = ms0b_store_ready('adminapi', 120); $redis = audit_redis();
    $sessionTtl = $redis->ttl($context['session_key']); $accountTtl = $redis->ttl($context['account_key']);
    audit_expect($sessionTtl > 0 && $sessionTtl <= $context['session']['expires_at'] - time() + 60, 'session TTL must respect exp plus 60-second allowance');
    audit_expect($accountTtl === -1, 'account HASH must be persistent, independent of its current session TTL');
});
ms0b_store_case('A17', 'v2-session-expired-beyond-leeway', 'session past exp+60 rejects with no write or revival', static function (): void {
    $context = ms0b_store_ready(); audit_redis()->hSet($context['session_key'], 'expires_at', (string)(time() - 61));
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    $validNew = ms0b_store_session($context, $context['version']);
    ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $validNew));
    $new = ms0b_store_session($context, $context['version'], -61);
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
});
ms0b_store_case('A28', 'v2-revoke-only-own-sid', 'revoke invalidates only the specified app session and is idempotent', static function (): void {
    $context = ms0b_store_ready(); $other = ms0b_store_ready('outapi'); $before = ms0b_store_snapshot();
    $context['store']->revokeSession($context['app'], $context['session']['sid']);
    audit_expect(ms0b_store_changed($before, ms0b_store_snapshot(), [$context['session_key']]) === [], 'revoke may change only its exact session');
    ms0b_store_deny(static fn() => ms0b_store_verify($context)); ms0b_store_verify($other);
    $before = ms0b_store_snapshot(); $context['store']->revokeSession($context['app'], $context['session']['sid']);
    audit_expect(ms0b_store_changed($before, ms0b_store_snapshot()) === [], 'repeat revoke must be idempotent');
});
ms0b_store_case('A21', 'v2-rotate-account', 'version rotation invalidates all earlier sessions; new-version session succeeds', static function (): void {
    $context = ms0b_store_ready(); $second = ms0b_store_session($context, $context['version']);
    $context['store']->createSession($context['app'], $context['id'], $context['version'], $second);
    $newVersion = $context['store']->rotateAccount($context['app'], $context['id']);
    audit_expect((bool)preg_match('/^[a-f0-9]{32}$/D', $newVersion) && $newVersion !== $context['version'], 'rotation must create a fresh 128-bit version');
    audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'rotated account must remain persistent');
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    ms0b_store_deny(static fn() => ms0b_store_verify(array_replace($context, ['session' => $second])));
    $new = ms0b_store_session($context, $newVersion);
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    $context['store']->createSession($context['app'], $context['id'], $newVersion, $new);
    ms0b_store_verify(array_replace($context, ['session' => $new, 'version' => $newVersion]));
});
ms0b_store_case('A21', 'v2-basic-block-unblock', 'blocked account cannot initialize/create/verify/redeem; ordinary unblock restores unchanged version', static function (): void {
    $context = ms0b_store_ready(); $new = ms0b_store_session($context, $context['version']);
    $context['store']->block($context['app'], $context['id']);
    audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'ordinary block must retain account persistence');
    ms0b_store_deny(static fn() => $context['store']->initAccount($context['app'], $context['id']));
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
    $context['store']->unblock($context['app'], $context['id']); ms0b_store_verify($context);
    audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'ordinary unblock must retain account persistence');
});

function ms0b_store_worker_line($stream, int $timeout = 20): string
{
    $read = [$stream]; $write = null; $except = null;
    audit_expect(stream_select($read, $write, $except, $timeout) === 1, 'independent refresh worker response timed out');
    $line = fgets($stream);
    audit_expect(is_string($line), 'independent refresh worker closed without a result');
    return trim($line);
}
ms0b_store_case('A25', 'v2-two-process-single-redeem', 'two concurrent independent processes redeem one old sid; exactly one succeeds and repeat fails', static function (): void {
    $context = ms0b_store_ready('outapi'); $sessions = [ms0b_store_session($context, $context['version']), ms0b_store_session($context, $context['version'])];
    $oldExpiry = ms0b_store_snapshot()['expires'][$context['session_key']];
    $workers = []; $results = [];
    try {
        foreach ($sessions as $index => $session) {
            $process = proc_open([PHP_BINARY, __FILE__, '--redeem-child'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            audit_expect(is_resource($process), 'real refresh worker must start');
            $workers[$index] = ['process' => $process, 'pipes' => $pipes, 'pid' => proc_get_status($process)['pid']];
            fwrite($pipes[0], json_encode(['epoch' => $context['epoch'], 'app' => $context['app'], 'id' => $context['id'], 'old_sid' => $context['session']['sid'], 'session' => $session], JSON_THROW_ON_ERROR) . "\n");
        }
        foreach ($workers as $worker) { audit_expect(ms0b_store_worker_line($worker['pipes'][1]) === 'READY', 'both actual PHP workers must reach the barrier'); }
        foreach ($workers as $worker) { fwrite($worker['pipes'][0], "GO\n"); fflush($worker['pipes'][0]); }
        foreach ($workers as $index => $worker) { $results[$index] = json_decode(ms0b_store_worker_line($worker['pipes'][1]), true, 512, JSON_THROW_ON_ERROR); }
    } finally {
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $status = proc_get_status($worker['process']);
            if ($status['running']) { proc_terminate($worker['process']); }
            proc_close($worker['process']);
        }
    }
    audit_expect(count(array_filter($results, static fn(array $result): bool => $result === ['outcome' => 'SUCCESS', 'code' => 0])) === 1, 'exactly one simultaneous refresh must succeed');
    audit_expect(count(array_filter($results, static fn(array $result): bool => $result === ['outcome' => 'AUTH', 'code' => 401])) === 1, 'loser must be controlled 401');
    foreach ($results as $index => $result) {
        if ($result['outcome'] === 'SUCCESS') { ms0b_store_verify(array_replace($context, ['session' => $sessions[$index]])); }
        else { audit_expect(audit_redis()->exists('auth:v2:' . $context['epoch'] . ':' . $context['app'] . ':session:' . $sessions[$index]['sid']) === 0, 'loser must not create a session'); }
    }
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    audit_expect(ms0b_store_same_expiry($oldExpiry, ms0b_store_snapshot()['expires'][$context['session_key']]), 'concurrent redeem must retain the old SID absolute expiry deadline');
    $repeat = ms0b_store_session($context, $context['version']);
    ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $repeat));
    audit_expect(count(array_unique(array_column($workers, 'pid'))) === 2, 'refresh workers must have two distinct actual process IDs');
    $GLOBALS['ms0b_store_observations'][] = ['actual_processes' => 2, 'worker_pids' => array_column($workers, 'pid'), 'ready_barrier' => true, 'results' => $results, 'successful_exchanges' => 1];
});
foreach ([
    'before-eval' => ['when' => 'before', 'action' => 'throw'],
    'after-eval-response-loss' => ['when' => 'after', 'action' => 'throw'],
    'after-eval-disconnect' => ['when' => 'after', 'action' => 'disconnect'],
] as $name => $fault) {
    ms0b_store_case('A25', 'v2-redeem-fault-' . $name, 'failed exchange returns no success; executed exchange never restores old sid; unrelated values remain unchanged', static function () use ($fault): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_store_ready('outapi'); $new = ms0b_store_session($context, $context['version']);
        $newKey = 'auth:v2:' . $context['epoch'] . ':' . $context['app'] . ':session:' . $new['sid'];
        $delegate = audit_redis(); $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1] + $fault]);
        $store = ms0b_store_make($context, $faulty); $returned = false; $before = ms0b_store_snapshot();
        ms0b_store_exception(static function () use ($store, $context, $new, &$returned): void { $store->redeem($context['app'], $context['session']['sid'], $context['id'], $new); $returned = true; }, \crmeb\exceptions\AuthUnavailableException::class, 503);
        audit_expect($returned === false, 'faulted exchange must never return success to caller');
        if ($fault['action'] === 'disconnect') { audit_expect(!$delegate->isConnected(), 'disconnect must close actual Redis connection'); }
        if ($fault['when'] === 'before') {
            audit_expect(ms0b_store_changed($before, ms0b_store_snapshot()) === [], 'before-execution error must preserve the old session and all values');
            ms0b_store_verify($context); audit_expect(audit_redis()->exists($newKey) === 0, 'no pre-EVAL replacement session may exist');
        } else {
            audit_expect(ms0b_store_changed($before, ms0b_store_snapshot(), [$context['session_key'], $newKey]) === [], 'after-execution loss may change only the two exact exchange keys');
            audit_expect(audit_redis()->exists($newKey) === 1, 'independent observer must see actually executed replacement session');
            audit_expect(audit_redis()->hGet($context['session_key'], 'status') === 'consumed' && audit_redis()->ttl($context['session_key']) > 0, 'executed exchange must leave old sid consumed with its remaining TTL');
            $after = ms0b_store_snapshot();
            audit_expect(ms0b_store_same_expiry($before['expires'][$context['session_key']], $after['expires'][$context['session_key']]), 'executed exchange must not renew or remove the old SID expiry deadline');
            audit_expect(audit_redis()->ttl($newKey) > 0 && audit_redis()->ttl($newKey) <= $new['expires_at'] - time() + 60, 'replacement session TTL must also be bounded by its expiry plus leeway');
            audit_expect(!array_key_exists('previous_token_digest', audit_redis()->hGetAll($newKey)), 'redeem must not persist its previous digest check parameter');
            ms0b_store_deny(static fn() => ms0b_store_verify($context));
            ms0b_store_verify(array_replace($context, ['session' => $new]));
            $repeat = ms0b_store_session($context, $context['version']);
            ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $repeat));
        }
        $GLOBALS['ms0b_store_observations'][] = ['fault_when' => $fault['when'], 'fault_action' => $fault['action'], 'caller_got_success' => false, 'exchange_executed' => $fault['when'] === 'after'];
    });
}
foreach (['missing-version', 'wrong-version', 'wrong-type', 'wrong-audience', 'wrong-stamp', 'missing-previous-digest', 'wrong-previous-digest', 'missing-token-digest', 'invalid-token-digest', 'same-token-digest', 'missing-expiry', 'invalid-expiry', 'expired-expiry', 'overflow-expiry', 'same-sid', 'existing-destination'] as $variant) {
    ms0b_store_case('A25', 'v2-redeem-precondition-' . $variant, 'all redeem preconditions are checked before old sid consumption or destination write', static function () use ($variant): void {
        $context = ms0b_store_ready('outapi'); $new = ms0b_store_session($context, $context['version']);
        switch ($variant) {
            case 'missing-version': unset($new['session_version']); break;
            case 'wrong-version': $new['session_version'] = bin2hex(random_bytes(16)); break;
            case 'wrong-type': $new['type'] = 'admin'; break;
            case 'wrong-audience': $new['audience'] = 'other-audience'; break;
            case 'wrong-stamp': $new['credential_stamp'] = hash('sha256', 'other-stamp'); break;
            case 'missing-previous-digest': unset($new['previous_token_digest']); break;
            case 'wrong-previous-digest': $new['previous_token_digest'] = hash('sha256', 'other-previous-token'); break;
            case 'missing-token-digest': unset($new['token_digest']); break;
            case 'invalid-token-digest': $new['token_digest'] = 'not-a-sha256-digest'; break;
            case 'same-token-digest': $new['token_digest'] = $context['session']['token_digest']; break;
            case 'missing-expiry': unset($new['expires_at']); break;
            case 'invalid-expiry': $new['expires_at'] = 'not-a-time'; break;
            case 'expired-expiry': $new['expires_at'] = time() - 61; break;
            case 'overflow-expiry': $new['expires_at'] = PHP_INT_MAX; break;
            case 'same-sid': $new['sid'] = $context['session']['sid']; break;
            case 'existing-destination': audit_redis()->set('auth:v2:' . $context['epoch'] . ':' . $context['app'] . ':session:' . $new['sid'], 'wrong-type-destination'); break;
        }
        ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
        ms0b_store_verify($context);
    });
}

ms0b_store_case('A29', 'v2-missing-account-no-revival', 'lost account makes verify/redeem/rotate fail with 401; none recreates version', static function (): void {
    $context = ms0b_store_ready(); audit_redis()->del($context['account_key']); $new = ms0b_store_session($context, $context['version']);
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $new));
    ms0b_store_deny(static fn() => $context['store']->rotateAccount($context['app'], $context['id']));
    audit_expect(audit_redis()->exists($context['account_key']) === 0, 'read/refresh/rotate must never initialize lost account');
    $version = $context['store']->initAccount($context['app'], $context['id']);
    audit_expect($version !== $context['version'], 'only explicit credential-login primitive may initialize a fresh version');
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
});
ms0b_store_case('A29', 'v2-epoch-namespace-separation', 'new epoch rejects earlier sessions without touching their namespace; new login creates independent session', static function (): void {
    $old = ms0b_store_ready(); $new = $old; $new['epoch'] .= '-next';
    $new['account_key'] = ms0b_store_account_key($new); $new['store'] = ms0b_store_make($new);
    ms0b_store_deny(static fn() => ms0b_store_verify($new));
    $replacement = ms0b_store_session($new, $old['version']);
    ms0b_store_deny(static fn() => $new['store']->redeem($new['app'], $old['session']['sid'], $new['id'], $replacement));
    ms0b_store_verify($old);
    $new['version'] = $new['store']->initAccount($new['app'], $new['id']);
    audit_expect($new['version'] !== $old['version'], 'new epoch login must create a fresh version');
    $new['session'] = ms0b_store_session($new, $new['version']);
    $new['store']->createSession($new['app'], $new['id'], $new['version'], $new['session']);
    ms0b_store_verify($new); ms0b_store_verify($old);
});
ms0b_store_case('A29', 'v2-missing-account-change-tombstone', 'change of missing account creates protected uninitialized marker, no usable version; owner completion still cannot authorize', static function (): void {
    $context = ms0b_store_context(); $owner = $context['store']->beginChange($context['app'], $context['id']); $redis = audit_redis();
    $marker = $redis->hGetAll($context['account_key']);
    audit_expect(($marker['initialized'] ?? null) === '0' && ($marker['blocked'] ?? null) === '1' && !array_key_exists('session_version', $marker) && ($marker['owner'] ?? null) === $owner, 'tombstone must be blocked with owner and no authorization version');
    audit_expect($redis->ttl($context['account_key']) === -1, 'uninitialized owner marker must also be persistent');
    ms0b_store_deny(static fn() => $context['store']->initAccount($context['app'], $context['id']));
    $context['version'] = bin2hex(random_bytes(16)); $context['session'] = ms0b_store_session($context, $context['version']);
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    ms0b_store_deny(static fn() => $context['store']->redeem($context['app'], $context['session']['sid'], $context['id'], $context['session']));
    $context['store']->completeChange($context['app'], $context['id'], $owner); $marker = $redis->hGetAll($context['account_key']);
    audit_expect(($marker['initialized'] ?? null) === '0' && ($marker['blocked'] ?? null) === '0' && !array_key_exists('session_version', $marker) && !array_key_exists('owner', $marker), 'successful change completion may not initialize authorization or retain owner');
    audit_expect($redis->ttl($context['account_key']) === -1, 'completed uninitialized marker must remain persistent');
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    $version = $context['store']->initAccount($context['app'], $context['id']);
    audit_expect((bool)preg_match('/^[a-f0-9]{32}$/D', $version), 'explicit login initialization after completion must create 128-bit version');
});
ms0b_store_case('A29', 'v2-lost-account-change-no-old-session-revival', 'lost account with surviving old session uses uninitialized change marker; completion and later login never revive the old version', static function (): void {
    $context = ms0b_store_ready(); audit_redis()->del($context['account_key']);
    $owner = $context['store']->beginChange($context['app'], $context['id']);
    $marker = audit_redis()->hGetAll($context['account_key']);
    audit_expect(($marker['initialized'] ?? null) === '0' && ($marker['blocked'] ?? null) === '1'
        && !array_key_exists('session_version', $marker) && ($marker['owner'] ?? null) === $owner, 'lost account change must never inherit or synthesize a usable authorization version');
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    $context['store']->completeChange($context['app'], $context['id'], $owner);
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    $newVersion = $context['store']->initAccount($context['app'], $context['id']);
    audit_expect((bool)preg_match('/^[a-f0-9]{32}$/D', $newVersion) && $newVersion !== $context['version'], 'later explicit credential initialization must create a fresh 128-bit version');
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    $new = ms0b_store_session($context, $newVersion);
    $context['store']->createSession($context['app'], $context['id'], $newVersion, $new);
    ms0b_store_verify(array_replace($context, ['session' => $new, 'version' => $newVersion]));
});
ms0b_store_case('A21', 'v2-owner-cas-change', 'begin atomically blocks and rotates; wrong owner/ordinary unblock/concurrent begin cannot remove protection', static function (): void {
    $context = ms0b_store_ready(); $owner = $context['store']->beginChange($context['app'], $context['id']);
    $account = audit_redis()->hGetAll($context['account_key']);
    audit_expect((bool)preg_match('/^[a-f0-9]{32}$/D', $owner) && (bool)preg_match('/^[a-f0-9]{32}$/D', $account['session_version']) && $account['session_version'] !== $context['version'] && $account['blocked'] === '1' && $account['owner'] === $owner, 'begin must return nonce, rotate to a 128-bit version, and acquire exact owner');
    audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'normal begin must retain account persistence');
    ms0b_store_deny(static fn() => $context['store']->completeChange($context['app'], $context['id'], bin2hex(random_bytes(16))));
    ms0b_store_deny(static fn() => $context['store']->unblock($context['app'], $context['id']));
    ms0b_store_deny(static fn() => $context['store']->beginChange($context['app'], $context['id']));
    ms0b_store_deny(static fn() => $context['store']->initAccount($context['app'], $context['id']));
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    $new = ms0b_store_session($context, $context['version']);
    ms0b_store_deny(static fn() => $context['store']->createSession($context['app'], $context['id'], $context['version'], $new));
    $context['store']->completeChange($context['app'], $context['id'], $owner);
    $completed = audit_redis()->hGetAll($context['account_key']);
    audit_expect($completed['blocked'] === '0' && !array_key_exists('owner', $completed), 'owner completion must remove only its own protection');
    audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'normal completion must retain account persistence');
    ms0b_store_deny(static fn() => $context['store']->completeChange($context['app'], $context['id'], $owner));
    ms0b_store_deny(static fn() => ms0b_store_verify($context));
    audit_expect($context['store']->initAccount($context['app'], $context['id']) === $account['session_version'], 'owner completion must retain the newly rotated version');
    $replacement = ms0b_store_session($context, $account['session_version']);
    $context['store']->createSession($context['app'], $context['id'], $account['session_version'], $replacement);
    ms0b_store_verify(array_replace($context, ['session' => $replacement, 'version' => $account['session_version']]));
});
foreach ([
    'before-eval' => ['when' => 'before', 'action' => 'throw'],
    'after-eval-response-loss' => ['when' => 'after', 'action' => 'throw'],
    'after-eval-disconnect' => ['when' => 'after', 'action' => 'disconnect'],
] as $name => $fault) {
    ms0b_store_case('A21', 'v2-begin-change-fault-' . $name, 'unexecuted begin preserves state; executed begin stays protected with rotated version; neither returns success after response loss', static function () use ($fault): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_store_ready(); $delegate = audit_redis();
        $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1] + $fault]);
        $store = ms0b_store_make($context, $faulty); $ownerReceived = false; $before = ms0b_store_snapshot();
        ms0b_store_exception(static function () use ($store, $context, &$ownerReceived): void {
            $store->beginChange($context['app'], $context['id']); $ownerReceived = true;
        }, \crmeb\exceptions\AuthUnavailableException::class, 503);
        audit_expect($ownerReceived === false, 'begin with lost response must never report an owner/success to caller');
        if ($fault['action'] === 'disconnect') { audit_expect(!$delegate->isConnected(), 'begin fault must close the real Redis connection'); }
        $after = ms0b_store_snapshot();
        if ($fault['when'] === 'before') {
            audit_expect(ms0b_store_changed($before, $after) === [], 'unexecuted begin must preserve every value and expiry');
            ms0b_store_verify($context);
        } else {
            audit_expect(ms0b_store_changed($before, $after, [$context['account_key']]) === [], 'executed begin may change only the exact account HASH');
            $account = audit_redis()->hGetAll($context['account_key']);
            audit_expect(($account['blocked'] ?? null) === '1' && ($account['initialized'] ?? null) === '1'
                && (bool)preg_match('/^[a-f0-9]{32}$/D', $account['owner'] ?? '')
                && (bool)preg_match('/^[a-f0-9]{32}$/D', $account['session_version'] ?? '')
                && $account['session_version'] !== $context['version'], 'executed begin must retain its owner protection and fresh version after response loss');
            audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'executed begin account must remain persistent');
            ms0b_store_deny(static fn() => ms0b_store_verify($context));
            ms0b_store_deny(static fn() => $context['store']->initAccount($context['app'], $context['id']));
            ms0b_store_deny(static fn() => $context['store']->unblock($context['app'], $context['id']));
        }
        $GLOBALS['ms0b_store_observations'][] = ['change_operation' => 'begin', 'fault_when' => $fault['when'], 'fault_action' => $fault['action'], 'caller_got_success' => false, 'change_executed' => $fault['when'] === 'after'];
    });
    ms0b_store_case('A21', 'v2-complete-change-fault-' . $name, 'unexecuted completion stays blocked; executed completion retains rotated version and never reports success after response loss', static function () use ($fault): void {
        require_once __DIR__ . '/lib/fault.php';
        $context = ms0b_store_ready(); $owner = $context['store']->beginChange($context['app'], $context['id']);
        $protected = audit_redis()->hGetAll($context['account_key']); $delegate = audit_redis();
        $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1] + $fault]);
        $store = ms0b_store_make($context, $faulty); $returned = false; $before = ms0b_store_snapshot();
        // This Store-only fixture exercises the completion primitive. Task 6
        // separately proves that a real DB commit precedes this invocation.
        ms0b_store_exception(static function () use ($store, $context, $owner, &$returned): void {
            $store->completeChange($context['app'], $context['id'], $owner); $returned = true;
        }, \crmeb\exceptions\AuthUnavailableException::class, 503);
        audit_expect($returned === false, 'completion with lost response must not report success');
        if ($fault['action'] === 'disconnect') { audit_expect(!$delegate->isConnected(), 'completion fault must close the real Redis connection'); }
        $after = ms0b_store_snapshot(); $account = audit_redis()->hGetAll($context['account_key']);
        if ($fault['when'] === 'before') {
            audit_expect(ms0b_store_changed($before, $after) === [], 'unexecuted completion must preserve every value and expiry');
            audit_expect($account === $protected, 'unexecuted completion must retain owner and blocked state');
            ms0b_store_deny(static fn() => $context['store']->initAccount($context['app'], $context['id']));
        } else {
            audit_expect(ms0b_store_changed($before, $after, [$context['account_key']]) === [], 'executed completion may change only the exact account HASH');
            audit_expect(($account['blocked'] ?? null) === '0' && !array_key_exists('owner', $account)
                && ($account['initialized'] ?? null) === '1' && ($account['session_version'] ?? null) === $protected['session_version'], 'executed completion must preserve the rotated version rather than compensate or revive the earlier version');
            audit_expect(audit_redis()->ttl($context['account_key']) === -1, 'completed account must remain persistent');
            audit_expect($context['store']->initAccount($context['app'], $context['id']) === $protected['session_version'], 'lost completion response must not require another rotation or restore earlier version');
        }
        ms0b_store_deny(static fn() => ms0b_store_verify($context));
        $GLOBALS['ms0b_store_observations'][] = ['change_operation' => 'complete', 'fault_when' => $fault['when'], 'fault_action' => $fault['action'], 'caller_got_success' => false, 'change_executed' => $fault['when'] === 'after', 'old_version_revived' => false];
    });
}
ms0b_store_case('A29', 'v2-reused-instance-no-last-subject', 'one store reused for A/B/A cannot inherit the previous verified subject', static function (): void {
    $first = ms0b_store_ready(); $second = $first; $second['id'] = (string)((int)$first['id'] + 1000000);
    $second['account_key'] = ms0b_store_account_key($second);
    $second['version'] = $first['store']->initAccount($second['app'], $second['id']);
    $second['session'] = ms0b_store_session($second, $second['version']);
    $first['store']->createSession($second['app'], $second['id'], $second['version'], $second['session']);
    ms0b_store_verify($first); ms0b_store_verify($second, null, $first['store']);
    ms0b_store_deny(static fn() => ms0b_store_verify($first, ['id' => $second['id']], $first['store']));
    ms0b_store_verify($first, null, $first['store']);
});

audit_finish();
