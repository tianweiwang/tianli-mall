<?php
declare(strict_types=1);

/*
 * Task 4 real HTTP integration tests.
 * No application test hook, mocked framework, forged production v2 session,
 * password/token response logging or environment mutation outside this RUN.
 *
 * Preconditions provided by the harness in its fresh RUN:
 * private config.auth.session_epoch / config.auth.stamp_key; the supervisor
 * supplies PHP_AUTH_*; restricted overrides below; runner gets a READ-ONLY
 * app-runtime mount at /run/audit-app-runtime. This script never creates a
 * missing deployment configuration to make an old-schema refusal pass.
 */
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/token-forge.php';
require __DIR__ . '/lib/fault.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');

$GLOBALS['m4_config'] = audit_config();
$auth = $GLOBALS['m4_config']['auth'] ?? [];
audit_expect(is_string($auth['session_epoch'] ?? null)
    && preg_match('/^audit-http-[a-z0-9-]+$/D', $auth['session_epoch']) === 1,
    'fresh RUN must already have a private, test-owned HTTP auth epoch');
audit_expect(is_string($auth['stamp_key'] ?? null) && strlen($auth['stamp_key']) >= 32
    && !hash_equals($GLOBALS['m4_config']['signing_key'], $auth['stamp_key']),
    'fresh RUN must already have an independent private stamp key');
audit_expect(is_dir('/run/audit-app-runtime'), 'real HTTP application runtime requires the read-only observation mount');
$GLOBALS['m4_auth'] = $auth;
$GLOBALS['m4_accounts'] = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['admin_super', 'admin_limited', 'admin_disabled', 'admin_deleted'] as $name) {
    $fixture = $GLOBALS['m4_accounts'][$name] ?? [];
    audit_expect(is_int($fixture['id'] ?? null) && $fixture['id'] > 0
        && is_string($fixture['account'] ?? null) && is_string($fixture['password'] ?? null),
        'existing private synthetic administrator metadata must be present');
}
$GLOBALS['m4_counter'] = 0;
$GLOBALS['m4_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
$GLOBALS['m4_cache_prefixes'] = [];
$GLOBALS['m4_snapshot_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'snapshot table identity must be safe');
    $GLOBALS['m4_snapshot_spec']['tables'][$table] = null;
}
$GLOBALS['m4_v2_prefix'] = 'auth:v2:' . $auth['session_epoch'] . ':';
audit_expect(m4_scan($GLOBALS['m4_v2_prefix'] . '*') === [], 'fresh HTTP epoch must have no pre-existing sessions');

// Real CLI framework is used only for independent JWT validation and the
// documented credential-stamp algorithm. HTTP remains the actual SUT.
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
$app = new \think\App(audit_repo_dir() . '/crmeb/');
$app->env->set([
    'APP_DEBUG' => false, 'APP_APP_KEY' => $GLOBALS['m4_config']['signing_key'],
    'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
    'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
    'DATABASE_PASSWORD' => $GLOBALS['m4_config']['database']['password'],
    'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => false,
    'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis',
    'REDIS_PORT' => '6379', 'REDIS_REDIS_PASSWORD' => $GLOBALS['m4_config']['redis_password'],
    'CACHE_CACHE_PREFIX' => 'audit:http-cli:' . getenv('AUDIT_RUN_ID') . ':',
    'AUTH_SESSION_EPOCH' => $auth['session_epoch'], 'AUTH_STAMP_KEY' => $auth['stamp_key'],
    'AUTH_ISSUER' => $auth['issuer'] ?? 'tianli-mall',
]);
$app->initialize();
$GLOBALS['m4_app'] = $app;

function m4_scan(string $pattern): array
{
    $redis = audit_redis(); $cursor = null; $keys = [];
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING);
    return array_values(array_unique($keys));
}
function m4_check(bool $condition, string $description): void
{
    $GLOBALS['m4_case']['checks'][] = ['passed' => $condition, 'description' => $description];
}
function m4_status(array $response): ?int
{
    return is_numeric($response['json']['status'] ?? null) ? (int)$response['json']['status'] : null;
}
function m4_http(string $method, string $path, ?string $token = null, mixed $body = null): array
{
    if (is_array($body)) {
        foreach (['pwd', 'new_pwd', 'conf_pwd'] as $field) {
            if (is_string($body[$field] ?? null) && $body[$field] !== '') { $GLOBALS['m4_request_secrets'][] = $body[$field]; }
        }
    }
    $headers = $token === null ? [] : ['Authori-zation' => 'Bearer ' . $token];
    $response = audit_http($method, 'http://audit-app:8000' . $path, $headers, $body);
    if (isset($GLOBALS['m4_case'])) {
        $GLOBALS['m4_case']['requests'][] = [
            'method' => $method, 'path' => $path, 'http' => $response['http'],
            'business_status' => m4_status($response),
            'issued_token' => !empty($response['json']['data']['token']),
            'protected_data_present' => !empty($response['json']['data']),
        ];
    }
    return $response;
}
function m4_start(array $overrides = []): string
{
    audit_guard_assert();
    $prefix = 'audit:http-lang:' . getenv('AUDIT_RUN_ID') . ':' . ++$GLOBALS['m4_counter'] . ':';
    audit_expect(m4_scan($prefix . '*') === [], 'new HTTP cache prefix must be cold');
    $GLOBALS['m4_cache_prefixes'][] = $prefix;
    audit_app_request('start', $overrides + ['CACHE_PREFIX' => $prefix]);
    // audit_app_start calls login/info and would warm getLang before the case.
    // TCP readiness proves listening without entering any application route.
    for ($attempt = 0; $attempt < 80; ++$attempt) {
        $socket = @fsockopen('audit-app', 8000, $errno, $message, 0.2);
        if (is_resource($socket)) { fclose($socket); $GLOBALS['m4_current_prefix'] = $prefix; return $prefix; }
        usleep(50000);
    }
    throw new RuntimeException('isolated HTTP application did not listen');
}
function m4_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['m4_snapshot_spec']);
    $keys = array_keys($snapshot['redis']['*']); $snapshot['expires'] = [];
    if ($keys === []) { return $snapshot; }
    $script = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local result = {}
for i, key in ipairs(KEYS) do
  local ttl = redis.call('PTTL', key)
  if ttl >= 0 then result[i] = now + ttl else result[i] = ttl end
end
return result
LUA;
    $deadlines = audit_redis()->eval($script, $keys, count($keys));
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'all Redis absolute expiry points must be observed');
    foreach ($keys as $index => $key) { $snapshot['expires'][$key] = $deadlines[$index]; }
    return $snapshot;
}
function m4_runtime_snapshot(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/run/audit-app-runtime', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) { continue; }
        $relative = substr($file->getPathname(), strlen('/run/audit-app-runtime/'));
        // ThinkPHP multi-app runtime can be app-specific; cover all real
        // cache/log directories rather than inspecting the runner volume.
        if (preg_match('~(^|/)(cache|log)(/|$)~', $relative) !== 1) { continue; }
        $files[hash('sha256', $relative)] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return $files;
}
function m4_sensitive_log_free(): bool
{
    $secrets = [$GLOBALS['m4_config']['signing_key'], $GLOBALS['m4_auth']['stamp_key'],
        $GLOBALS['m4_config']['redis_password'], $GLOBALS['m4_config']['database']['password']];
    foreach ($GLOBALS['m4_accounts'] as $fixture) {
        if (is_string($fixture['password'] ?? null)) { $secrets[] = $fixture['password']; }
    }
    foreach ($GLOBALS['m4_live_tokens'] ?? [] as $token) { $secrets[] = $token; }
    foreach ($GLOBALS['m4_request_secrets'] ?? [] as $secret) { $secrets[] = $secret; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/run/audit-app-runtime', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || preg_match('~(^|/)log(/|$)~', $file->getPathname()) !== 1) { continue; }
        $bytes = file_get_contents($file->getPathname());
        foreach ($secrets as $secret) { if ($secret !== '' && str_contains($bytes, $secret)) { return false; } }
    }
    // Server access logging is expected to grow; test contents, not its hash.
    $serverLog = audit_run_dir() . '/evidence/app-runtime.log';
    if (is_file($serverLog)) {
        $bytes = file_get_contents($serverLog);
        foreach ($secrets as $secret) { if ($secret !== '' && str_contains($bytes, $secret)) { return false; } }
    }
    return true;
}
function m4_differences(array $before, array $after, array $allowKeys = []): array
{
    $diff = ['db' => [], 'cache' => []];
    foreach ($before['tables'] as $table => $value) {
        if ($value !== ($after['tables'][$table] ?? null)) { $diff['db'][] = $table; }
    }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowKeys, true)) { continue; }
        $label = 'key-sha256:' . hash('sha256', $key);
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $diff['cache'][] = $label . ':value'; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $diff['cache'][] = $label . ':expiry'; }
    }
    return $diff;
}
function m4_negative(callable $request, int|array $expected, array $allowKeys = [], ?callable $recover = null, bool $strictRuntime = true, ?array $legacyTag = null): array
{
    audit_guard_assert();
    $before = m4_snapshot(); $runtimeBefore = m4_runtime_snapshot(); $response = [];
    $tagMembers = [];
    if ($legacyTag !== null) {
        $tagType = audit_redis()->type($legacyTag['key']);
        audit_expect(in_array($tagType, [Redis::REDIS_NOT_FOUND, Redis::REDIS_SET], true), 'existing limiter tag must be absent or a SET');
        $tagMembers = $tagType === Redis::REDIS_SET ? audit_redis()->sMembers($legacyTag['key']) : [];
        $allowKeys[] = $legacyTag['key'];
    }
    try { $response = $request(); }
    catch (Throwable $error) { m4_check(false, 'HTTP request must return a controlled response (' . get_class($error) . ')'); }
    finally { if ($recover !== null) { $recover(); } }
    audit_guard_assert();
    $after = m4_snapshot(); $runtimeAfter = m4_runtime_snapshot();
    $diff = m4_differences($before, $after, $allowKeys);
    if ($legacyTag !== null) {
        $expectedMembers = array_values(array_unique(array_merge($tagMembers, [$legacyTag['counter']])));
        sort($expectedMembers, SORT_STRING);
        $afterType = audit_redis()->type($legacyTag['key']);
        $actualMembers = $afterType === Redis::REDIS_SET ? audit_redis()->sMembers($legacyTag['key']) : [];
        sort($actualMembers, SORT_STRING);
        $beforeDeadline = $before['expires'][$legacyTag['key']] ?? -2;
        $afterDeadline = $after['expires'][$legacyTag['key']] ?? -2;
        $sameDeadline = $beforeDeadline === -2 ? $afterDeadline === -1
            : (($beforeDeadline < 0 || $afterDeadline < 0) ? $beforeDeadline === $afterDeadline : abs($beforeDeadline - $afterDeadline) <= 1);
        $exactTag = $afterType === Redis::REDIS_SET && $actualMembers === $expectedMembers && $sameDeadline;
        m4_check($exactTag, 'legacy limiter tag only adds this exact counter member, preserving every other member and expiry point');
        if (!$exactTag) { $diff['cache'][] = 'key-sha256:' . hash('sha256', $legacyTag['key']) . ':unexpected-tag-delta'; }
        $GLOBALS['m4_case']['observations'][] = ['legacy_tag_exact_delta' => $exactTag,
            'tag_members_before' => count($tagMembers), 'tag_members_after' => count($actualMembers)];
    }
    $GLOBALS['m4_case']['db_diff'] = array_merge($GLOBALS['m4_case']['db_diff'], $diff['db']);
    $GLOBALS['m4_case']['cache_diff'] = array_merge($GLOBALS['m4_case']['cache_diff'], $diff['cache']);
    $GLOBALS['m4_case']['observations'][] = [
        'database_tables' => count($before['tables']), 'redis_values_before' => count($before['redis']['*']),
        'redis_values_after' => count($after['redis']['*']), 'allowed_cache_keys' => array_map(static fn(string $key): string => hash('sha256', $key), $allowKeys),
        'db_snapshot_sha256_before' => hash('sha256', json_encode($before['tables'], JSON_THROW_ON_ERROR)),
        'db_snapshot_sha256_after' => hash('sha256', json_encode($after['tables'], JSON_THROW_ON_ERROR)),
        'runtime_file_count_before' => count($runtimeBefore), 'runtime_file_count_after' => count($runtimeAfter),
        'runtime_snapshot_sha256_before' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)),
        'runtime_snapshot_sha256_after' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR)),
    ];
    m4_check(in_array(m4_status($response), (array)$expected, true), 'business status must meet the exact documented failure contract');
    m4_check(($response['http'] ?? 0) === 200, 'legacy HTTP 200 envelope must contain the controlled business error');
    m4_check(empty($response['json']['data']['token']), 'failure must not issue a token');
    if (in_array(m4_status($response), [401, 503], true)) {
        m4_check(($response['json']['data'] ?? null) === [], 'authentication errors must have the exact empty-data envelope without a debug trace');
    }
    m4_check($diff['db'] === [], 'all installation and sentinel tables must remain unchanged');
    m4_check($diff['cache'] === [], 'all unapproved Redis values and absolute expiry points must remain unchanged');
    if ($strictRuntime) { m4_check($runtimeBefore === $runtimeAfter, 'controlled authentication errors must not write real HTTP file cache or business logs'); }
    m4_check(m4_sensitive_log_free(), 'actual application and server logs must contain no private credentials or token');
    return $response;
}
function m4_case(string $id, string $subcase, string $expected, callable $fn): void
{
    audit_case($id, $subcase, 'INTEGRATION', static function () use ($id, $subcase, $expected, $fn): array {
        $GLOBALS['m4_case'] = ['checks' => [], 'requests' => [], 'observations' => [], 'db_diff' => [], 'cache_diff' => []];
        try { $fn(); }
        catch (Throwable $error) { m4_check(false, 'case preparation/recovery must succeed (' . get_class($error) . ')'); }
        $capture = $GLOBALS['m4_case']; unset($GLOBALS['m4_case']);
        $pass = $capture['checks'] !== [] && !in_array(false, array_column($capture['checks'], 'passed'), true);
        $ref = 'evidence/admin-http-' . $GLOBALS['m4_execution'] . '-' . $id . '-' . $subcase . '.json';
        audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'),
            'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'phase' => getenv('AUDIT_PHASE') ?: 'after',
            'case_id' => $id, 'subcase' => $subcase, 'expected' => $expected] + $capture);
        return ['status' => $pass ? 'PASS' : 'FAIL', 'expected' => $expected,
            'actual' => 'checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks'])
                . '; business ' . implode(',', array_map(static fn(array $r): string => (string)($r['business_status'] ?? 'none'), $capture['requests']))
                . '; DB/cache changes ' . count($capture['db_diff']) . '/' . count($capture['cache_diff']),
            'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'],
            'evidence_ref' => $ref, 'notes' => 'Real isolated HTTP; Task 6 account-guard and true concurrent HTTP acceptance remain separately pending.'];
    });
}
function m4_login(string $fixture): array
{
    $account = $GLOBALS['m4_accounts'][$fixture];
    $response = m4_http('POST', '/adminapi/login', null, ['account' => $account['account'], 'pwd' => $account['password']]);
    $token = $response['json']['data']['token'] ?? null;
    audit_expect(m4_status($response) === 200 && is_string($token) && $token !== '', 'valid synthetic credentials must issue a real HTTP token');
    $GLOBALS['m4_live_tokens'][] = $token;
    return ['token' => $token, 'response' => $response, 'fixture' => $fixture];
}
function m4_claims(string $token): array
{
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token, $GLOBALS['m4_config']['signing_key'], ['HS256']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
}
function m4_binding(array $login): array
{
    $claims = m4_claims($login['token']); $fixture = $GLOBALS['m4_accounts'][$login['fixture']];
    m4_check(($claims['auth_schema'] ?? null) === 2, 'real login must issue auth_schema 2');
    m4_check(!array_key_exists('pwd', $claims), 'v2 login JWT must contain no credential pwd field');
    m4_check(($claims['session_epoch'] ?? null) === $GLOBALS['m4_auth']['session_epoch'], 'login must use the independently provisioned deployment epoch');
    m4_check(($claims['iss'] ?? null) === ($GLOBALS['m4_auth']['issuer'] ?? 'tianli-mall')
        && ($claims['aud'] ?? null) === 'adminapi' && ($claims['jti']['type'] ?? null) === 'admin'
        && (string)($claims['jti']['id'] ?? '') === (string)$fixture['id'], 'real JWT issuer/audience/type/subject must match adminapi');
    $sid = $claims['sid'] ?? ''; $version = $claims['session_version'] ?? '';
    m4_check(is_string($sid) && preg_match('/^[a-f0-9]{32}$/D', $sid) === 1
        && is_string($version) && preg_match('/^[a-f0-9]{32}$/D', $version) === 1, 'sid/version must be independent 128-bit server values');
    if (!is_string($sid) || preg_match('/^[a-f0-9]{32}$/D', $sid) !== 1) { return []; }
    $sessionKey = $GLOBALS['m4_v2_prefix'] . 'adminapi:session:' . $sid;
    $accountKey = $GLOBALS['m4_v2_prefix'] . 'adminapi:account:' . $fixture['id'];
    $redis = audit_redis();
    m4_check($redis->type($sessionKey) === Redis::REDIS_HASH && $redis->type($accountKey) === Redis::REDIS_HASH, 'HTTP login must persist real session/account HASH records');
    $session = $redis->hGetAll($sessionKey); $account = $redis->hGetAll($accountKey);
    $statement = audit_db()->prepare('SELECT pwd FROM eb_system_admin WHERE id=?'); $statement->execute([$fixture['id']]); $digest = $statement->fetchColumn();
    audit_expect(is_string($digest) && $digest !== '', 'current synthetic database credential digest must be present');
    $stamp = hash_hmac('sha256', 'adminapi|' . $fixture['id'] . '|' . $digest, $GLOBALS['m4_auth']['stamp_key']);
    m4_check(($session['subject_id'] ?? '') === (string)$fixture['id']
        && ($session['type'] ?? '') === 'admin' && ($session['audience'] ?? '') === 'adminapi'
        && ($session['session_version'] ?? '') === $version && ($session['status'] ?? '') === 'active'
        && ($session['token_digest'] ?? '') === hash('sha256', $login['token'])
        && ($session['credential_stamp'] ?? '') === $stamp
        && ($session['expires_at'] ?? '') === (string)($claims['exp'] ?? ''),
        'independent Redis record must bind exact identity, token digest, current credential stamp and JWT expiry');
    m4_check(($account['initialized'] ?? '') === '1' && ($account['blocked'] ?? '') === '0'
        && ($account['session_version'] ?? '') === $version && $redis->ttl($accountKey) === -1, 'initialized account version must be unblocked and persistent');
    $time = $redis->time(); $now = (int)$time[0];
    $ttl = $redis->ttl($sessionKey);
    m4_check($ttl > 0 && $ttl <= (int)($claims['exp'] ?? 0) + 60 - $now, 'server session expiry must not outlive token exp plus the documented tolerance');
    m4_check((int)($login['response']['json']['data']['expires_time'] ?? 0) === (int)($claims['exp'] ?? -1), 'legacy expires_time field must retain the JWT expiry contract');
    m4_check(m4_scan($GLOBALS['m4_current_prefix'] . '*' . md5($login['token'])) === [], 'new real login must not persist a legacy md5(token) whitelist fallback');
    return ['claims' => $claims, 'session_key' => $sessionKey, 'account_key' => $accountKey];
}

m4_case('A28', 'admin-login-v2-binding', 'real successful admin credential login produces a credential-free v2 JWT with independently checked server records', static function (): void {
    m4_start(); $fixture = $GLOBALS['m4_accounts']['admin_super'];
    $statement = audit_db()->prepare('SELECT login_count FROM eb_system_admin WHERE id=?'); $statement->execute([$fixture['id']]); $before = (int)$statement->fetchColumn();
    $login = m4_login('admin_super'); m4_binding($login);
    $statement->execute([$fixture['id']]);
    m4_check((int)$statement->fetchColumn() === $before + 1, 'successful credential login increments its own statistic exactly once');
    m4_check(m4_status(m4_http('GET', '/adminapi/product/product/rule', $login['token'])) === 200, 'new v2 token must work on the real protected probe');
});
m4_case('A05', 'limited-granted-read-v2', 'limited role retains its existing registered product-rule grant after real v2 login', static function (): void {
    m4_start(); $login = m4_login('admin_limited'); m4_binding($login);
    m4_check(m4_status(m4_http('GET', '/adminapi/product/product/rule', $login['token'])) === 200, 'existing granted limited read must remain available');
});
foreach (['search-menus' => '/adminapi/menusList', 'own-menus' => '/adminapi/menus',
    'own-menu-unique' => '/adminapi/setting/menus/unique', 'own-info' => '/adminapi/setting/info'] as $name => $path) {
    m4_case('A06', 'v2-allowlist-' . $name, 'approved authenticated GET whitelist remains available with the current v2 session', static function () use ($name, $path): void {
        m4_start(); $login = m4_login('admin_limited');
        $response = m4_http('GET', $path, $login['token']);
        m4_check(m4_status($response) === 200, 'approved exact authenticated GET remains successful');
        if ($name === 'own-info') {
            m4_check((string)($response['json']['data']['id'] ?? '') === (string)$GLOBALS['m4_accounts']['admin_limited']['id']
                && !array_key_exists('pwd', $response['json']['data'] ?? []), 'self-info must return only the current identity and omit pwd');
        }
        if ($name === 'own-menus' || $name === 'own-menu-unique') {
            m4_check(($response['json']['data']['menus'] ?? null) === [], 'API-only limited fixture must retain role-filtered empty navigation');
        }
    });
}
m4_case('A06', 'v2-allowlist-own-profile', 'self-update whitelist edits only current name/avatar and retains current v2 sessions', static function (): void {
    m4_start(); $login = m4_login('admin_limited'); $id = $GLOBALS['m4_accounts']['admin_limited']['id'];
    $statement = audit_db()->prepare('SELECT * FROM eb_system_admin WHERE id=?'); $statement->execute([$id]); $before = $statement->fetch();
    $super = audit_db()->prepare('SELECT * FROM eb_system_admin WHERE id=?'); $super->execute([$GLOBALS['m4_accounts']['admin_super']['id']]); $other = $super->fetch();
    try {
        $response = m4_http('PUT', '/adminapi/setting/update_admin', $login['token'], ['real_name' => 'AUDIT V2 PROFILE', 'head_pic' => $before['head_pic']]);
        m4_check(m4_status($response) === 200, 'approved self-profile update remains successful');
        $statement->execute([$id]); $after = $statement->fetch();
        m4_check(($after['real_name'] ?? null) === 'AUDIT V2 PROFILE', 'real self-profile update persists its intended name');
        foreach (['account', 'pwd', 'roles', 'level', 'status', 'is_del', 'division_id'] as $column) { m4_check(($after[$column] ?? null) === $before[$column], 'self-update preserves protected field ' . $column); }
        $super->execute([$GLOBALS['m4_accounts']['admin_super']['id']]); m4_check($super->fetch() === $other, 'self-profile update preserves the other synthetic administrator');
        m4_check(m4_status(m4_http('GET', '/adminapi/product/product/rule', $login['token'])) === 200, 'noncredential profile update preserves the current v2 authorization');
    } finally { audit_db()->prepare('UPDATE eb_system_admin SET real_name=?,head_pic=? WHERE id=?')->execute([$before['real_name'], $before['head_pic'], $id]); }
});
m4_case('A28', 'v2-logout-only-current-sid', 'real logout removes only the presented sid; second same-account session and second administrator remain usable', static function (): void {
    m4_start(); $first = m4_login('admin_limited'); $firstBinding = m4_binding($first);
    $second = m4_login('admin_limited'); $secondBinding = m4_binding($second);
    $other = m4_login('admin_super'); $otherBinding = m4_binding($other);
    m4_check($first['token'] !== $second['token'], 'two logins within the same second must have distinct session ids');
    audit_expect(isset($firstBinding['session_key'], $secondBinding['session_key'], $otherBinding['session_key']), 'actual v2 login records must exist before logout');
    // Warm approved probes outside the logout/no-effects observation.
    m4_http('GET', '/adminapi/product/product/rule', $second['token']);
    m4_http('GET', '/adminapi/product/product/rule', $other['token']);
    $readAuditRows = static function (): array {
        $rows = [];
        foreach (audit_db()->query('SELECT * FROM eb_system_log ORDER BY id')->fetchAll() as $row) { $rows[(string)$row['id']] = $row; }
        return $rows;
    };
    $auditBefore = $readAuditRows();
    $pageQuery = audit_db()->prepare('SELECT menu_name FROM eb_system_menus WHERE api_url=? LIMIT 1');
    $pageQuery->execute(['setting/admin/logout']);
    $expectedPage = $pageQuery->fetchColumn() ?: '未知';
    $before = m4_snapshot(); $requestStart = time();
    m4_check(m4_status(m4_http('GET', '/adminapi/setting/admin/logout', $first['token'])) === 200, 'approved logout route must retain its success envelope');
    $requestEnd = time();
    $after = m4_snapshot(); $diff = m4_differences($before, $after, [$firstBinding['session_key']]);
    $auditAfter = $readAuditRows(); $priorRowsUnchanged = true;
    foreach ($auditBefore as $id => $row) { if (($auditAfter[$id] ?? null) !== $row) { $priorRowsUnchanged = false; } }
    $added = array_diff_key($auditAfter, $auditBefore); $row = count($added) === 1 ? reset($added) : [];
    $fixture = $GLOBALS['m4_accounts']['admin_limited'];
    $columns = array_keys($row); sort($columns, SORT_STRING);
    $expectedColumns = ['id', 'admin_id', 'admin_name', 'path', 'page', 'method', 'ip', 'type', 'add_time', 'merchant_id'];
    sort($expectedColumns, SORT_STRING);
    $exactAudit = $priorRowsUnchanged && count($added) === 1 && count($auditAfter) === count($auditBefore) + 1
        && $columns === $expectedColumns && (int)($row['id'] ?? 0) > max([0, ...array_keys($auditBefore)])
        && (string)($row['admin_id'] ?? '') === (string)$fixture['id'] && ($row['admin_name'] ?? '') === $fixture['account']
        && ($row['method'] ?? '') === 'adminapi' && ($row['path'] ?? '') === 'setting/admin/logout'
        && ($row['type'] ?? '') === 'system' && ($row['page'] ?? '') === $expectedPage
        && ($row['ip'] ?? '') === gethostbyname(gethostname()) && (int)($row['merchant_id'] ?? -1) === 0
        && (int)($row['add_time'] ?? 0) >= $requestStart && (int)($row['add_time'] ?? 0) <= $requestEnd;
    m4_check($exactAudit, 'successful logout appends exactly its existing authenticated audit row; every prior row and audit field is preserved');
    $unexpectedTables = array_values(array_diff($diff['db'], ['eb_system_log']));
    m4_check($exactAudit && $unexpectedTables === [] && $diff['cache'] === [], 'logout changes only the exact presented sid and its one precisely verified audit row');
    $GLOBALS['m4_case']['observations'][] = ['database_tables' => count($before['tables']), 'approved_audit_table' => 'eb_system_log',
        'added_audit_rows' => count($added), 'prior_audit_rows_unchanged' => $priorRowsUnchanged, 'exact_logout_audit' => $exactAudit,
        'unapproved_db_diff' => $unexpectedTables, 'unapproved_cache_diff' => $diff['cache']];
    $GLOBALS['m4_case']['db_diff'] = array_merge($GLOBALS['m4_case']['db_diff'], $unexpectedTables, $exactAudit ? [] : ['eb_system_log']);
    $GLOBALS['m4_case']['cache_diff'] = array_merge($GLOBALS['m4_case']['cache_diff'], $diff['cache']);
    m4_check(audit_redis()->exists($firstBinding['session_key']) === 0, 'logout must really delete the current v2 sid');
    m4_negative(static fn(): array => m4_http('GET', '/adminapi/product/product/rule', $first['token']), 401);
    m4_check(m4_status(m4_http('GET', '/adminapi/product/product/rule', $second['token'])) === 200
        && m4_status(m4_http('GET', '/adminapi/product/product/rule', $other['token'])) === 200, 'same-account and other-account sessions remain usable');
});
foreach (['product-list' => '/adminapi/product/product', 'system-info' => '/adminapi/system/info',
    'route-import' => '/adminapi/route/import_api', 'download' => '/adminapi/download/AUDITmissing' . getenv('AUDIT_RUN_ID')] as $name => $path) {
    m4_case('A03', 'v2-unregistered-' . $name, 'unregistered protected route is rejected before controller with its existing role failure contract', static function () use ($path): void {
        m4_start(); $login = m4_login('admin_limited');
        m4_http('GET', '/adminapi/product/product/rule', $login['token']);
        $response = m4_negative(static fn(): array => m4_http('GET', $path, $login['token']), 400);
        m4_check(($response['json']['msg'] ?? null) === '您暂时没有访问权限' && empty($response['json']['data']), 'role middleware must preserve its exact denial and expose no protected data');
    });
}
m4_case('A24', 'old-admin-schema-existing-md5-key', 'signed legacy admin JWT is 401 while its exact serialized md5 whitelist remains; no business/cache/runtime writes', static function (): void {
    $prefix = m4_start(['APP_DEBUG' => 'true']); $fixture = $GLOBALS['m4_accounts']['admin_super'];
    $statement = audit_db()->prepare('SELECT pwd FROM eb_system_admin WHERE id=?'); $statement->execute([$fixture['id']]); $digest = $statement->fetchColumn();
    $now = time(); $legacy = audit_forge_jwt(['iss' => 'audit-app', 'aud' => 'audit-app', 'iat' => $now, 'nbf' => $now,
        'exp' => $now + 3600, 'jti' => ['id' => $fixture['id'], 'type' => 'admin'], 'pwd' => md5($digest)], $GLOBALS['m4_config']['signing_key']);
    $GLOBALS['m4_live_tokens'][] = $legacy;
    $legacyKey = $prefix . md5($legacy);
    audit_redis()->setex($legacyKey, 3660, serialize(['uid' => $fixture['id'], 'type' => 'admin', 'token' => $legacy, 'exp' => 3660]));
    try {
        audit_expect(audit_redis()->exists($legacyKey) === 1, 'legacy md5 whitelist must actually exist before the negative request');
        $response = m4_negative(static fn(): array => m4_http('GET', '/adminapi/product/product/rule', $legacy), 401);
        m4_check(empty($response['json']['data']), 'debug mode must expose no trace or source context for controlled rejection');
        m4_check(audit_redis()->exists($legacyKey) === 1, 'legacy key remains present after refusal; no legacy cleanup or fallback is allowed');
    } finally { audit_redis()->del($legacyKey); }
});
m4_case('A23', 'real-admin-v2-session-missing', 'a signature-valid real v2 login token with its own sid deleted is 401 and does not rebuild the sid or mutate another session', static function (): void {
    m4_start(); $login = m4_login('admin_super'); $binding = m4_binding($login);
    audit_expect(isset($binding['session_key']), 'actual v2 login must supply the exact server sid before deletion');
    $second = m4_login('admin_limited'); m4_binding($second);
    m4_http('GET', '/adminapi/product/product/rule', $second['token']);
    audit_redis()->del($binding['session_key']);
    m4_negative(static fn(): array => m4_http('GET', '/adminapi/product/product/rule', $login['token']), 401);
    m4_check(audit_redis()->exists($binding['session_key']) === 0, 'authentication must never rebuild a missing sid');
    m4_check(m4_status(m4_http('GET', '/adminapi/product/product/rule', $second['token'])) === 200, 'another actual administrator session remains usable after the refusal');
});
foreach (['missing-epoch' => ['AUTH_SESSION_EPOCH' => ''], 'missing-stamp' => ['AUTH_STAMP_KEY' => ''],
    'short-stamp' => ['AUTH_STAMP_KEY' => 'short'], 'file-driver' => ['CACHE_DRIVER' => 'file']] as $name => $override) {
    foreach (['login', 'protected'] as $operation) {
        m4_case('A10', $name . '-admin-' . $operation, 'cold real HTTP configuration error is direct business 503 with no token, business/cache/file/log write', static function () use ($override, $operation): void {
            m4_start(); $valid = m4_login('admin_super');
            $coldPrefix = m4_start($override + ['APP_DEBUG' => 'true']);
            audit_expect(m4_scan($coldPrefix . '*') === [], 'configuration failure observation must start before any language/cache warming');
            try {
                $response = m4_negative(static function () use ($operation, $valid): array {
                    if ($operation === 'protected') { return m4_http('GET', '/adminapi/product/product/rule', $valid['token']); }
                    $fixture = $GLOBALS['m4_accounts']['admin_super'];
                    return m4_http('POST', '/adminapi/login', null, ['account' => $fixture['account'], 'pwd' => $fixture['password']]);
                }, 503);
                m4_check(empty($response['json']['data']) && ($response['json']['msg'] ?? null) === '认证服务暂不可用', 'debug failure must be the fixed direct JSON503 without trace or driver details');
                m4_check(m4_scan($coldPrefix . '*') === [], 'controlled 503 must not initialize getLang or limiter caches');
            } finally { m4_start(); }
        });
    }
}
foreach (['wrong-password', 'disabled-account', 'deleted-account'] as $mode) {
    m4_case('A28', 'credential-refusal-' . $mode, 'real credential failure emits no token and leaves all DB/login statistics and sessions unchanged; only explicit existing limiter may change', static function () use ($mode): void {
        $prefix = m4_start();
        $fixtureName = match ($mode) { 'disabled-account' => 'admin_disabled', 'deleted-account' => 'admin_deleted', default => 'admin_super' };
        $fixture = $GLOBALS['m4_accounts'][$fixtureName];
        // This credential-failure window observes an ordinarily initialized
        // application. A real successful login initializes input-filter caches
        // before the window; cold configuration/dependency cases stay separate.
        m4_login('admin_super');
        m4_http('GET', '/adminapi/login/info');
        $response = m4_negative(static fn(): array => m4_http('POST', '/adminapi/login', null,
            ['account' => $fixture['account'], 'pwd' => $mode === 'wrong-password' ? bin2hex(random_bytes(12)) : $fixture['password']]),
            400, $mode === 'disabled-account' ? [] : [$prefix . 'login_captcha'], null, false,
            $mode === 'disabled-account' ? null : ['key' => $prefix . 'CRMEB:' . md5('crmeb'), 'counter' => $prefix . 'login_captcha']);
        m4_check(empty($response['json']['data']['token']), 'inactive/deleted/wrong credential account receives no authorization');
    });
}
foreach (['login', 'protected'] as $operation) {
    m4_case('A23', 'redis-pause-admin-' . $operation, 'actual isolated Redis timeout returns fixed business503 before login statistics or protected business writes', static function () use ($operation): void {
        m4_start(); $valid = m4_login('admin_super'); m4_start(['APP_DEBUG' => 'true']);
        $response = m4_negative(static function () use ($operation, $valid): array {
            audit_fault_redis('pause');
            if ($operation === 'protected') { return m4_http('GET', '/adminapi/product/product/rule', $valid['token']); }
            $fixture = $GLOBALS['m4_accounts']['admin_super'];
            return m4_http('POST', '/adminapi/login', null, ['account' => $fixture['account'], 'pwd' => $fixture['password']]);
        }, 503, [], static function (): void { audit_fault_redis('unpause'); });
        m4_check(empty($response['json']['data']) && ($response['json']['msg'] ?? null) === '认证服务暂不可用', 'Redis outage must use fixed direct JSON503 without debug trace');
    });
    m4_case('A23', 'database-connection-admin-' . $operation, 'application connection to the same isolated MySQL host at a closed port returns direct503 with zero statistics/cache/log writes', static function () use ($operation): void {
        m4_start(); $valid = m4_login('admin_super');
        m4_start(['DB_PORT' => '1', 'APP_DEBUG' => 'true']);
        try {
            $response = m4_negative(static function () use ($operation, $valid): array {
                if ($operation === 'protected') { return m4_http('GET', '/adminapi/product/product/rule', $valid['token']); }
                $fixture = $GLOBALS['m4_accounts']['admin_super'];
                return m4_http('POST', '/adminapi/login', null, ['account' => $fixture['account'], 'pwd' => $fixture['password']]);
            }, 503);
            m4_check(empty($response['json']['data']) && ($response['json']['msg'] ?? null) === '认证服务暂不可用', 'database outage must use fixed direct JSON503 without underlying message or trace');
        } finally { m4_start(); }
    });
}
m4_case('A21', 'real-self-password-change-current-credential', 'actual current-user password update rejects its old v2 token; new credential login succeeds; full guard/race scenarios remain Task6', static function (): void {
    m4_start(); $old = m4_login('admin_super'); $id = $GLOBALS['m4_accounts']['admin_super']['id'];
    $statement = audit_db()->prepare('SELECT pwd,real_name,head_pic FROM eb_system_admin WHERE id=?'); $statement->execute([$id]); $before = $statement->fetch();
    $newPassword = 'AuditV2' . bin2hex(random_bytes(8));
    try {
        $response = m4_http('PUT', '/adminapi/setting/update_admin', $old['token'], [
            'real_name' => $before['real_name'], 'head_pic' => $before['head_pic'], 'pwd' => $GLOBALS['m4_accounts']['admin_super']['password'],
            'new_pwd' => $newPassword, 'conf_pwd' => $newPassword,
        ]);
        m4_check(m4_status($response) === 200, 'real self-password update retains its normal success behavior');
        m4_http('GET', '/adminapi/login/info'); // warm business translation outside the strict rejection
        m4_negative(static fn(): array => m4_http('GET', '/adminapi/product/product/rule', $old['token']), 401);
        $fresh = m4_http('POST', '/adminapi/login', null, ['account' => $GLOBALS['m4_accounts']['admin_super']['account'], 'pwd' => $newPassword]);
        $token = $fresh['json']['data']['token'] ?? null;
        m4_check(m4_status($fresh) === 200 && is_string($token) && $token !== '', 'new credential obtains a fresh real token');
        if (is_string($token) && $token !== '') {
            $GLOBALS['m4_live_tokens'][] = $token;
            m4_binding(['token' => $token, 'response' => $fresh, 'fixture' => 'admin_super']);
            m4_check(m4_status(m4_http('GET', '/adminapi/product/product/rule', $token)) === 200, 'new credential session is actually usable');
        }
    } finally {
        audit_guard_assert();
        audit_db()->prepare('UPDATE eb_system_admin SET pwd=?,real_name=?,head_pic=? WHERE id=?')->execute([$before['pwd'], $before['real_name'], $before['head_pic'], $id]);
    }
});

// Cleanup happens only after every negative observation has been recorded.
// Prefixes were confirmed empty and generated for this exact fresh RUN.
audit_guard_assert();
foreach ($GLOBALS['m4_cache_prefixes'] as $prefix) {
    audit_expect(str_starts_with($prefix, 'audit:http-lang:' . getenv('AUDIT_RUN_ID') . ':'), 'cleanup cache scope must belong to this RUN');
    foreach (m4_scan($prefix . '*') as $key) { audit_redis()->del($key); }
}
foreach (m4_scan($GLOBALS['m4_v2_prefix'] . '*') as $key) { audit_redis()->del($key); }
audit_app_stop();
audit_finish();
