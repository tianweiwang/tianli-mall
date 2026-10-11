<?php
declare(strict_types=1);

/* Task 5 draft: basic real HTTP / real framework CLI / independent MCP.
 * Copy beside lib/ only after review. Not executed or linted by its preparer.
 * Uses an already provisioned fresh RUN; never supplies missing auth config.
 * Concurrent HTTP and response-before-read disconnect are explicitly pending.
 * Evidence contains booleans/counts/diff hashes, never a token, password or DTO.
 */
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/token-forge.php';
require __DIR__ . '/lib/fault.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');
$GLOBALS['m5_config'] = audit_config();
$auth = $GLOBALS['m5_config']['auth'] ?? [];
audit_expect(($auth['session_epoch'] ?? '') === 'audit-http-' . getenv('AUDIT_RUN_ID')
    && is_string($auth['stamp_key'] ?? null) && strlen($auth['stamp_key']) >= 32
    && !hash_equals($GLOBALS['m5_config']['signing_key'], $auth['stamp_key']),
    'fresh private HTTP auth configuration must already be provisioned');
audit_expect(is_dir('/run/audit-app-runtime'), 'real HTTP runtime observation mount is required');
$GLOBALS['m5_auth'] = $auth;
$GLOBALS['m5_accounts'] = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['out_101', 'out_102', 'out_disabled'] as $name) {
    $fixture = $GLOBALS['m5_accounts'][$name] ?? [];
    audit_expect(is_int($fixture['id'] ?? null) && $fixture['id'] > 0
        && is_string($fixture['account'] ?? null) && is_string($fixture['password'] ?? null),
        'existing private synthetic external account metadata is required');
}
$GLOBALS['m5_counter'] = 0;
$GLOBALS['m5_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
$GLOBALS['m5_cache_prefixes'] = [];
$GLOBALS['m5_live_tokens'] = [];
$GLOBALS['m5_request_secrets'] = [];
$GLOBALS['m5_owned_sessions'] = [];
$GLOBALS['m5_owned_accounts'] = [];
$GLOBALS['m5_snapshot_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'snapshot table identity must be safe');
    $GLOBALS['m5_snapshot_spec']['tables'][$table] = null;
}
$GLOBALS['m5_v2_prefix'] = 'auth:v2:' . $auth['session_epoch'] . ':outapi:';
audit_expect(m5_scan($GLOBALS['m5_v2_prefix'] . '*') === [], 'fresh outapi HTTP namespace must be empty');
$GLOBALS['m5_cli_prefix'] = 'audit:http-cli:' . getenv('AUDIT_RUN_ID') . ':';
$GLOBALS['m5_cli_keys_before'] = m5_scan($GLOBALS['m5_cli_prefix'] . '*');
audit_expect($GLOBALS['m5_cli_keys_before'] === [], 'fresh RUN CLI cache namespace must be cold before framework initialization');

require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
$app = new \think\App(audit_repo_dir() . '/crmeb/');
$app->env->set([
    'APP_DEBUG' => false, 'APP_APP_KEY' => $GLOBALS['m5_config']['signing_key'],
    'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
    'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
    'DATABASE_PASSWORD' => $GLOBALS['m5_config']['database']['password'],
    'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => false,
    'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis',
    'REDIS_PORT' => '6379', 'REDIS_REDIS_PASSWORD' => $GLOBALS['m5_config']['redis_password'],
    'CACHE_CACHE_PREFIX' => $GLOBALS['m5_cli_prefix'],
    'AUTH_SESSION_EPOCH' => $auth['session_epoch'], 'AUTH_STAMP_KEY' => $auth['stamp_key'],
    'AUTH_ISSUER' => $auth['issuer'] ?? 'tianli-mall',
]);
$app->initialize();
$GLOBALS['m5_app'] = $app;

function m5_scan(string $pattern): array
{
    $cursor = null; $keys = []; $redis = audit_redis();
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING); return array_values(array_unique($keys));
}
function m5_check(bool $condition, string $description): void
{
    $GLOBALS['m5_case']['checks'][] = ['passed' => $condition, 'description' => $description];
}
function m5_status(array $response): ?int
{
    return is_numeric($response['json']['status'] ?? null) ? (int)$response['json']['status'] : null;
}
function m5_http(string $method, string $path, ?string $token = null, mixed $body = null, array $headers = []): array
{
    if ($token !== null) { $headers['Authori-zation'] = 'Bearer ' . $token; }
    foreach (['appsecret', 'access_token'] as $field) {
        // This exact public attack input is not a private credential. Searching
        // whole logs/JWTs for one digit would mislabel timestamps/PIDs as leaks.
        // Real fixture/config secrets, random wrong credentials and all tokens
        // retain exact value matching; there is no generic length exemption.
        if ($field === 'appsecret' && is_array($body) && ($body[$field] ?? null) === '0') { continue; }
        if (is_array($body) && is_string($body[$field] ?? null) && $body[$field] !== '') {
            $GLOBALS['m5_request_secrets'][] = $body[$field];
        }
    }
    if (is_string($headers['password'] ?? null)) { $GLOBALS['m5_request_secrets'][] = $headers['password']; }
    $response = audit_http($method, 'http://audit-app:8000' . $path, $headers, $body);
    if (isset($GLOBALS['m5_case'])) {
        $GLOBALS['m5_case']['requests'][] = ['method' => $method, 'path' => $path,
            'http' => $response['http'], 'business_status' => m5_status($response),
            'issued_token' => !empty($response['json']['data']['access_token']),
            'rpc_result' => array_key_exists('result', $response['json'] ?? []),
            'rpc_error_code' => $response['json']['error']['code'] ?? null];
    }
    return $response;
}
function m5_start(array $overrides = []): string
{
    audit_guard_assert();
    $prefix = 'audit:http-lang:' . getenv('AUDIT_RUN_ID') . ':' . ++$GLOBALS['m5_counter'] . ':';
    audit_expect(m5_scan($prefix . '*') === [], 'new HTTP cache namespace must be cold');
    $GLOBALS['m5_cache_prefixes'][] = $prefix;
    audit_app_request('start', $overrides + ['CACHE_PREFIX' => $prefix]);
    // Do not use audit_app_start(): its login/info request warms getLang.
    for ($attempt = 0; $attempt < 80; ++$attempt) {
        $socket = @fsockopen('audit-app', 8000, $errno, $message, 0.2);
        if (is_resource($socket)) { fclose($socket); $GLOBALS['m5_current_prefix'] = $prefix; return $prefix; }
        usleep(50000);
    }
    throw new RuntimeException('isolated HTTP application did not listen');
}
function m5_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['m5_snapshot_spec']);
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
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'all Redis expiry points must be observed');
    foreach ($keys as $index => $key) { $snapshot['expires'][$key] = $deadlines[$index]; }
    return $snapshot;
}
function m5_runtime_roots(): array
{
    return array_values(array_unique(['/run/audit-app-runtime', $GLOBALS['m5_app']->getRuntimePath()]));
}
function m5_runtime_snapshot(): array
{
    $files = [];
    foreach (m5_runtime_roots() as $index => $root) {
        if (!is_dir($root)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) { continue; }
            $relative = substr($file->getPathname(), strlen(rtrim($root, '/') . '/'));
            if (preg_match('~(^|/)(cache|log)(/|$)~', $relative) !== 1) { continue; }
            $files[hash('sha256', $index . ':' . $relative)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files); return $files;
}
function m5_secrets(): array
{
    $secrets = [$GLOBALS['m5_config']['signing_key'], $GLOBALS['m5_auth']['stamp_key'],
        $GLOBALS['m5_config']['redis_password'], $GLOBALS['m5_config']['database']['password']];
    foreach ($GLOBALS['m5_accounts'] as $fixture) {
        if (is_string($fixture['password'] ?? null)) { $secrets[] = $fixture['password']; }
    }
    return array_values(array_unique(array_merge($secrets, $GLOBALS['m5_live_tokens'], $GLOBALS['m5_request_secrets'])));
}
function m5_sensitive_log_free(): bool
{
    $paths = [audit_run_dir() . '/evidence/app-runtime.log'];
    foreach (m5_runtime_roots() as $root) {
        if (!is_dir($root)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('~(^|/)log(/|$)~', $file->getPathname()) === 1) { $paths[] = $file->getPathname(); }
        }
    }
    foreach ($paths as $path) {
        if (!is_file($path)) { continue; }
        $bytes = file_get_contents($path);
        foreach (m5_secrets() as $secret) { if ($secret !== '' && str_contains($bytes, $secret)) { return false; } }
    }
    return true;
}
function m5_differences(array $before, array $after, array $allowKeys = []): array
{
    $diff = ['db' => [], 'cache' => []];
    foreach ($before['tables'] as $table => $value) { if ($value !== ($after['tables'][$table] ?? null)) { $diff['db'][] = $table; } }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowKeys, true)) { continue; }
        $label = 'key-sha256:' . hash('sha256', $key);
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $diff['cache'][] = $label . ':value'; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $diff['cache'][] = $label . ':expiry'; }
    }
    return $diff;
}
function m5_observe(array $before, array $after, array $runtimeBefore, array $runtimeAfter, array $diff): void
{
    $GLOBALS['m5_case']['db_diff'] = array_merge($GLOBALS['m5_case']['db_diff'], $diff['db']);
    $GLOBALS['m5_case']['cache_diff'] = array_merge($GLOBALS['m5_case']['cache_diff'], $diff['cache']);
    $GLOBALS['m5_case']['observations'][] = ['database_tables' => count($before['tables']),
        'redis_values_before' => count($before['redis']['*']), 'redis_values_after' => count($after['redis']['*']),
        'db_snapshot_sha256_before' => hash('sha256', json_encode($before['tables'], JSON_THROW_ON_ERROR)),
        'db_snapshot_sha256_after' => hash('sha256', json_encode($after['tables'], JSON_THROW_ON_ERROR)),
        'runtime_file_count_before' => count($runtimeBefore), 'runtime_file_count_after' => count($runtimeAfter),
        'runtime_snapshot_sha256_before' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)),
        'runtime_snapshot_sha256_after' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR))];
}
function m5_readonly(callable $operation, ?callable $recover = null): mixed
{
    audit_guard_assert(); $before = m5_snapshot(); $runtimeBefore = m5_runtime_snapshot(); $result = null;
    try { $result = $operation(); }
    catch (Throwable $error) { m5_check(false, 'observed operation returns without transport/internal failure (' . get_class($error) . ')'); }
    finally { if ($recover !== null) { $recover(); } }
    audit_guard_assert(); $after = m5_snapshot(); $runtimeAfter = m5_runtime_snapshot();
    $diff = m5_differences($before, $after); m5_observe($before, $after, $runtimeBefore, $runtimeAfter, $diff);
    m5_check($diff['db'] === [], 'all installation and sentinel tables remain unchanged');
    m5_check($diff['cache'] === [], 'all Redis values and expiry points remain unchanged');
    m5_check($runtimeBefore === $runtimeAfter, 'real HTTP and CLI file cache/business logs remain unchanged');
    m5_check(m5_sensitive_log_free(), 'actual application and server logs contain no private credential or token');
    return $result;
}

function m5_negative(callable $operation, int $expected, ?callable $recover = null): array
{
    $response = m5_readonly($operation, $recover);
    m5_check(is_array($response), 'HTTP failure returns a controlled JSON response');
    if (!is_array($response)) { return []; }
    m5_check(($response['http'] ?? 0) === 200 && m5_status($response) === $expected, 'exact legacy HTTP 200 / business failure status is preserved');
    m5_check(empty($response['json']['data']['access_token']), 'failure issues no token');
    if (in_array($expected, [401, 503], true)) {
        m5_check(($response['json']['data'] ?? null) === [], 'controlled authentication failure has exact empty data and no debug trace');
        if ($expected === 503) { m5_check(($response['json']['msg'] ?? null) === '认证服务暂不可用', '503 message is fixed and contains no driver details'); }
    }
    return $response;
}
function m5_case(string $id, string $subcase, string $expected, callable $fn, string $layer = 'INTEGRATION'): void
{
    audit_case($id, $subcase, $layer, static function () use ($id, $subcase, $expected, $fn): array {
        $GLOBALS['m5_case'] = ['checks' => [], 'requests' => [], 'observations' => [], 'db_diff' => [], 'cache_diff' => []];
        try { $fn(); } catch (Throwable $error) { m5_check(false, 'case preparation/operation/recovery succeeds (' . get_class($error) . ')'); }
        $capture = $GLOBALS['m5_case']; unset($GLOBALS['m5_case']);
        $pass = $capture['checks'] !== [] && !in_array(false, array_column($capture['checks'], 'passed'), true);
        $ref = 'evidence/out-http-' . $GLOBALS['m5_execution'] . '-' . $id . '-' . $subcase . '.json';
        audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'),
            'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'phase' => getenv('AUDIT_PHASE') ?: 'after',
            'case_id' => $id, 'subcase' => $subcase, 'expected' => $expected] + $capture);
        return ['status' => $pass ? 'PASS' : 'FAIL', 'expected' => $expected,
            'actual' => 'checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks'])
                . '; DB/cache changes ' . count($capture['db_diff']) . '/' . count($capture['cache_diff']),
            'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'], 'evidence_ref' => $ref,
            'notes' => 'Real HTTP and real framework CLI. True HTTP concurrency, response disconnect, Task6 guards/midflight failures remain pending.'];
    });
}

function m5_row(string $fixtureName): array
{
    $statement = audit_db()->prepare('SELECT * FROM eb_out_account WHERE id=?');
    $statement->execute([$GLOBALS['m5_accounts'][$fixtureName]['id']]); $row = $statement->fetch();
    audit_expect(is_array($row) && $row['appid'] === $GLOBALS['m5_accounts'][$fixtureName]['account'], 'synthetic row identity matches private metadata');
    return $row;
}
function m5_rows(): array
{
    $rows = [];
    foreach (audit_db()->query('SELECT * FROM eb_out_account ORDER BY id')->fetchAll() as $row) { $rows[(string)$row['id']] = $row; }
    return $rows;
}
function m5_login(string $fixtureName): array
{
    $fixture = $GLOBALS['m5_accounts'][$fixtureName];
    $response = m5_http('POST', '/outapi/access_token', null, ['appid' => $fixture['account'], 'appsecret' => $fixture['password']]);
    $token = $response['json']['data']['access_token'] ?? null;
    audit_expect(m5_status($response) === 200 && is_string($token) && $token !== '', 'valid synthetic credentials issue a real HTTP external token');
    $GLOBALS['m5_live_tokens'][] = $token;
    m5_track($token, $fixtureName);
    return ['token' => $token, 'response' => $response, 'fixture' => $fixtureName];
}
function m5_claims(string $token): array
{
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token, $GLOBALS['m5_config']['signing_key'], ['HS256']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
}
function m5_track(string $token, string $fixtureName): void
{
    $claims = m5_claims($token); $sid = $claims['sid'] ?? null;
    if (($claims['auth_schema'] ?? null) !== 2 || !is_string($sid) || preg_match('/^[a-f0-9]{32}$/D', $sid) !== 1) { return; }
    $GLOBALS['m5_owned_sessions'][] = $GLOBALS['m5_v2_prefix'] . 'session:' . $sid;
    $GLOBALS['m5_owned_accounts'][] = $GLOBALS['m5_v2_prefix'] . 'account:' . $GLOBALS['m5_accounts'][$fixtureName]['id'];
}
function m5_dto(array $dto, string $fixtureName): void
{
    $row = m5_row($fixtureName);
    $hidden = ['appsecret', 'apppwd', 'push_password', 'ip', 'is_del', 'add_time', 'status', 'last_time'];
    foreach ($hidden as $field) { m5_check(!array_key_exists($field, $dto), 'external DTO hides field ' . $field); }
    foreach (['appsecret', 'apppwd', 'push_password'] as $field) {
        m5_check(!is_string($row[$field]) || $row[$field] === '' || !str_contains(json_encode($dto, JSON_THROW_ON_ERROR), $row[$field]), 'external DTO contains no stored credential value for ' . $field);
    }
    $expected = array_diff_key($row, array_flip($hidden)); $keys = array_keys($dto); $expectedKeys = array_keys($expected);
    sort($keys, SORT_STRING); sort($expectedKeys, SORT_STRING);
    m5_check($keys === $expectedKeys, 'DTO preserves exactly the original visible fields after hiding both plaintext credentials');
    $same = true;
    foreach ($expected as $field => $value) {
        if (!array_key_exists($field, $dto) || ($value === null ? $dto[$field] !== null : (string)$dto[$field] !== (string)$value)) { $same = false; }
    }
    m5_check($same, 'DTO visible values still correspond to the current real account');
    m5_check(($dto['rules'] ?? null) === $row['rules'], 'DTO rules keep the original stored string/null contract');
    m5_check((int)$row['push_open'] === 0 && $row['push_token_url'] === '' && $row['push_account'] === '', 'synthetic push remains off with empty targets');
}
function m5_binding(array $login): array
{
    $claims = m5_claims($login['token']); $fixture = $GLOBALS['m5_accounts'][$login['fixture']];
    m5_check(($claims['auth_schema'] ?? null) === 2 && ($claims['session_epoch'] ?? null) === $GLOBALS['m5_auth']['session_epoch'], 'real external token has schema2 and the provisioned epoch');
    foreach (['pwd', 'appsecret', 'apppwd', 'push_password', 'credential_stamp', 'token_digest', 'stamp_key'] as $field) {
        m5_check(!array_key_exists($field, $claims) && !array_key_exists($field, $claims['jti'] ?? []), 'JWT contains no credential field ' . $field);
    }
    m5_check(($claims['iss'] ?? null) === ($GLOBALS['m5_auth']['issuer'] ?? 'tianli-mall') && ($claims['aud'] ?? null) === 'outapi'
        && ($claims['jti']['type'] ?? null) === 'out' && ($claims['jti']['id'] ?? null) === (string)$fixture['id'], 'JWT has exact issuer/audience/out type/canonical subject');
    foreach (['iat', 'nbf', 'exp'] as $field) { m5_check(is_int($claims[$field] ?? null), 'JWT time ' . $field . ' is an integer'); }
    m5_check(($claims['nbf'] ?? null) === ($claims['iat'] ?? null) && (int)($claims['exp'] ?? 0) - (int)($claims['iat'] ?? 0) === 30 * 86400, 'external TTL remains30 days with matching iat/nbf');
    $sid = $claims['sid'] ?? ''; $version = $claims['session_version'] ?? '';
    m5_check(is_string($sid) && preg_match('/^[a-f0-9]{32}$/D', $sid) === 1
        && is_string($version) && preg_match('/^[a-f0-9]{32}$/D', $version) === 1, 'sid/version have independent128-bit nonce format');
    if (!is_string($sid) || preg_match('/^[a-f0-9]{32}$/D', $sid) !== 1) { return []; }
    $sessionKey = $GLOBALS['m5_v2_prefix'] . 'session:' . $sid; $accountKey = $GLOBALS['m5_v2_prefix'] . 'account:' . $fixture['id'];
    $redis = audit_redis();
    m5_check($redis->type($sessionKey) === Redis::REDIS_HASH && $redis->type($accountKey) === Redis::REDIS_HASH, 'independent native Redis observes real account/session HASH records');
    $session = $redis->hGetAll($sessionKey); $account = $redis->hGetAll($accountKey); $row = m5_row($login['fixture']);
    $sessionFields = array_keys($session); sort($sessionFields, SORT_STRING);
    $expectedSessionFields = ['subject_id', 'type', 'audience', 'session_version', 'token_digest', 'credential_stamp', 'expires_at', 'status'];
    sort($expectedSessionFields, SORT_STRING);
    m5_check($sessionFields === $expectedSessionFields, 'real session HASH contains exactly binding fields, with no raw JWT, credential or predecessor digest');
    $stamp = hash_hmac('sha256', 'outapi|' . $fixture['id'] . '|' . $row['appsecret'], $GLOBALS['m5_auth']['stamp_key']);
    m5_check(($session['subject_id'] ?? '') === (string)$fixture['id'] && ($session['type'] ?? '') === 'out'
        && ($session['audience'] ?? '') === 'outapi' && ($session['session_version'] ?? '') === $version
        && ($session['status'] ?? '') === 'active' && ($session['token_digest'] ?? '') === hash('sha256', $login['token'])
        && ($session['credential_stamp'] ?? '') === $stamp && ($session['expires_at'] ?? '') === (string)($claims['exp'] ?? ''),
        'session binds exact token SHA256/current credential HMAC/identity/version/expiry');
    m5_check(($account['initialized'] ?? '') === '1' && ($account['blocked'] ?? '') === '0'
        && ($account['session_version'] ?? '') === $version && $redis->ttl($accountKey) === -1, 'account record is initialized, unblocked and persistent');
    $clock = $redis->time(); $ttl = $redis->ttl($sessionKey);
    m5_check($ttl > 0 && $ttl <= (int)($claims['exp'] ?? 0) + 60 - (int)$clock[0], 'session cannot outlive JWT expiry plus documented tolerance');
    m5_check((int)($login['response']['json']['data']['exp_time'] ?? 0) === (int)($claims['exp'] ?? -1), 'legacy exp_time agrees with JWT expiry');
    m5_check(m5_scan($GLOBALS['m5_current_prefix'] . '*' . md5($login['token'])) === [], 'v2 login creates no legacy md5 whitelist');
    foreach (m5_secrets() as $secret) {
        if ($secret !== '' && $secret !== $login['token']) { m5_check(!str_contains(json_encode($claims, JSON_THROW_ON_ERROR), $secret), 'claims contain no known private secret value'); }
    }
    return ['claims' => $claims, 'session_key' => $sessionKey, 'account_key' => $accountKey];
}
function m5_success_effects(callable $operation, string $fixtureName, array $existingAllowedKeys = []): mixed
{
    audit_guard_assert(); $before = m5_snapshot(); $runtimeBefore = m5_runtime_snapshot(); $rowsBefore = m5_rows();
    $start = time(); $result = $operation(); $end = time(); $after = m5_snapshot(); $runtimeAfter = m5_runtime_snapshot(); $rowsAfter = m5_rows();
    $id = (string)$GLOBALS['m5_accounts'][$fixtureName]['id']; $same = count($rowsBefore) === count($rowsAfter);
    foreach ($rowsBefore as $rowId => $row) {
        if ((string)$rowId !== $id) { if (($rowsAfter[$rowId] ?? null) !== $row) { $same = false; } continue; }
        foreach ($row as $field => $value) { if (!in_array($field, ['last_time', 'ip'], true) && ($rowsAfter[$id][$field] ?? null) !== $value) { $same = false; } }
    }
    m5_check($same, 'successful issue/refresh preserves every other row and every non-statistic target field including both plaintext DB columns');
    m5_check((int)($rowsAfter[$id]['last_time'] ?? 0) >= $start && (int)($rowsAfter[$id]['last_time'] ?? 0) <= $end
        && ($rowsAfter[$id]['ip'] ?? '') === gethostbyname(gethostname()), 'only current target last_time/IP are updated with the real request values');
    $newToken = is_array($result) ? ($result['token'] ?? null) : null;
    $newKeys = [];
    if (is_string($newToken)) {
        $claims = m5_claims($newToken);
        if (is_string($claims['sid'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $claims['sid']) === 1) { $newKeys[] = $GLOBALS['m5_v2_prefix'] . 'session:' . $claims['sid']; }
    }
    $diff = m5_differences($before, $after, array_merge($existingAllowedKeys, $newKeys));
    foreach ($existingAllowedKeys as $key) {
        $deadlineBefore = $before['expires'][$key] ?? -2; $deadlineAfter = $after['expires'][$key] ?? -2;
        $sameDeadline = ($deadlineBefore < 0 || $deadlineAfter < 0)
            ? $deadlineBefore === $deadlineAfter : abs($deadlineBefore - $deadlineAfter) <= 1;
        m5_check($sameDeadline, 'approved existing sid keeps its exact absolute Redis expiry point within1ms');
        if (!$sameDeadline) { $diff['cache'][] = 'key-sha256:' . hash('sha256', $key) . ':expiry'; }
    }
    $unexpectedTables = array_values(array_diff($diff['db'], ['eb_out_account']));
    $diff['db'] = array_merge($unexpectedTables, $same ? [] : ['eb_out_account']);
    m5_observe($before, $after, $runtimeBefore, $runtimeAfter, $diff);
    m5_check($diff['db'] === [] && $diff['cache'] === [], 'after ordinary initialization only the exact real session and verified target statistics may change');
    m5_check($runtimeBefore === $runtimeAfter && m5_sensitive_log_free(), 'successful authentication adds no file cache/business log/private-secret log');
    return $result;
}
function m5_prepare(): array
{
    m5_start(); $valid = m5_login('out_101');
    audit_expect(m5_status(m5_http('GET', '/outapi/category/list', $valid['token'])) === 200, 'ordinary initialized external grant must be usable before negative observation');
    return $valid;
}
function m5_request_operation(string $operation, array $valid): array
{
    if ($operation === 'protected') { return m5_http('GET', '/outapi/category/list', $valid['token']); }
    if ($operation === 'refresh') { return m5_http('POST', '/outapi/refresh_token', null, ['access_token' => $valid['token']]); }
    $fixture = $GLOBALS['m5_accounts']['out_101'];
    return m5_http('POST', '/outapi/access_token', null, ['appid' => $fixture['account'], 'appsecret' => $fixture['password']]);
}
function m5_mcp(string $method, array $params, int $id, ?string $fixtureName = 'out_101', ?string $wrongPassword = null): array
{
    $headers = [];
    if ($fixtureName !== null) { $fixture = $GLOBALS['m5_accounts'][$fixtureName]; $headers = ['account' => $fixture['account'], 'password' => $wrongPassword ?? $fixture['password']]; }
    return m5_http('POST', '/outapi/mcp', null, ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers);
}
function m5_mcp_success(array $response, int $id, string $method): void
{
    $json = $response['json'] ?? [];
    m5_check(($response['http'] ?? 0) === 200 && ($json['jsonrpc'] ?? '') === '2.0' && ($json['id'] ?? null) === $id
        && array_key_exists('result', $json) && !array_key_exists('error', $json), 'independent header-auth MCP returns the actual matching JSON-RPC result');
    if ($method === 'initialize') {
        m5_check(($json['result']['protocolVersion'] ?? '') === '2024-11-05'
            && is_array($json['result']['capabilities']['tools'] ?? null)
            && ($json['result']['serverInfo']['name'] ?? '') === 'crmeb-mcp-server'
            && ($json['result']['serverInfo']['version'] ?? '') === '1.0.0', 'real MCP initialize preserves protocol/capabilities/server identity');
    } else {
        $content = $json['result']['content'][0] ?? [];
        $data = is_string($content['text'] ?? null) ? json_decode($content['text'], true) : null;
        $count = (int)audit_db()->query('SELECT COUNT(*) FROM eb_store_category WHERE is_show=1')->fetchColumn();
        $firstId = audit_db()->query('SELECT id FROM eb_store_category WHERE is_show=1 ORDER BY sort DESC,id DESC LIMIT 1')->fetchColumn();
        m5_check(($content['type'] ?? '') === 'text' && is_array($data) && is_array($data['list'] ?? null)
            && (int)($data['count'] ?? -1) === $count && count($data['list'] ?? []) === min(1, $count)
            && ($count === 0 || (string)($data['list'][0]['id'] ?? '') === (string)$firstId), 'real read-only category tool returns the actual ordered first database row and count');
    }
    foreach (m5_secrets() as $secret) { if ($secret !== '') { m5_check(!str_contains($response['raw'] ?? '', $secret), 'MCP result contains no known private secret'); } }
}

// Make the two disclosure assertions non-vacuous using only synthetic rows.
// No push target or notification API is invoked. Restore only these exact columns.
$GLOBALS['m5_fixture_restore'] = [];
foreach (['out_101', 'out_102', 'out_disabled'] as $name) { $GLOBALS['m5_request_secrets'][] = m5_row($name)['appsecret']; }
try {
foreach (['out_101', 'out_102'] as $fixtureName) {
    $row = m5_row($fixtureName);
    audit_expect((int)$row['push_open'] === 0 && $row['push_account'] === '' && $row['push_token_url'] === '', 'synthetic fixture has no live push target');
    $GLOBALS['m5_fixture_restore'][$fixtureName] = ['apppwd' => $row['apppwd'], 'push_password' => $row['push_password']];
    $marker = 'AuditPush' . bin2hex(random_bytes(12)); $GLOBALS['m5_request_secrets'][] = $marker;
    audit_db()->prepare('UPDATE eb_out_account SET apppwd=?,push_password=? WHERE id=?')->execute([
        $GLOBALS['m5_accounts'][$fixtureName]['password'], $marker, $GLOBALS['m5_accounts'][$fixtureName]['id']]);
}

    m5_case('A28', 'out-real-login-v2-binding-dto', 'real credential login issues credential-free schema2 JWT, exact native server HASH/HMAC, preserved auth_info contract', static function (): void {
        m5_prepare();
        $login = m5_success_effects(static fn(): array => m5_login('out_101'), 'out_101');
        m5_binding($login); m5_dto($login['response']['json']['data']['auth_info'] ?? [], 'out_101');
        m5_check(m5_status(m5_http('GET', '/outapi/category/list', $login['token'])) === 200, 'original rules grant is consumed successfully by real middleware/controller');
    });
    m5_case('A28', 'out-real-cli-parser-a-b-a', 'same real framework OutAccountServices instance parses HTTP-issued A/B/A with identical hidden DTOs and current identity; no HTTP probe substitutes for DTO inspection', static function (): void {
        m5_prepare(); $a = m5_login('out_101'); $b = m5_login('out_102');
        $service = $GLOBALS['m5_app']->make(\app\services\out\OutAccountServices::class);
        $GLOBALS['m5_case']['observations'][] = ['execution' => 'real-framework-cli', 'same_service_instance' => true, 'parse_count' => 3];
        $dtos = m5_readonly(static fn(): array => [$service->parseToken($a['token']), $service->parseToken($b['token']), $service->parseToken($a['token'])]);
        audit_expect(is_array($dtos) && count($dtos) === 3, 'three actual parser DTOs are observed');
        foreach ([[0, 'out_101', $a], [1, 'out_102', $b], [2, 'out_101', $a]] as [$index, $name, $login]) {
            m5_dto($dtos[$index], $name);
            m5_check($dtos[$index] === ($login['response']['json']['data']['auth_info'] ?? null), 'real CLI parser DTO matches corresponding real HTTP login auth_info');
        }
        m5_check($dtos[0] === $dtos[2] && (string)$dtos[0]['id'] !== (string)$dtos[1]['id'], 'long-lived same-instance A/B/A neither retains nor leaks the preceding identity');
    });
    foreach (['default-null', 'explicit-null', 'empty', 'string-zero'] as $input) {
        m5_case('A28', 'out-real-service-secret-' . $input, 'real service does not skip password_verify for null/empty/string-zero; wrong input is401 with zero effects', static function () use ($input): void {
            m5_prepare(); $fixture = $GLOBALS['m5_accounts']['out_101']; $row = m5_row('out_101');
            audit_expect(!password_verify('0', $row['appsecret']), 'strong synthetic digest does not match string-zero; no general zero-password ban is asserted');
            $service = $GLOBALS['m5_app']->make(\app\services\out\OutAccountServices::class);
            $GLOBALS['m5_case']['observations'][] = ['execution' => 'real-framework-cli', 'input_kind' => $input];
            m5_readonly(static function () use ($service, $fixture, $input): void {
                $error = null;
                try {
                    match ($input) { 'default-null' => $service->authLogin($fixture['account']),
                        'explicit-null' => $service->authLogin($fixture['account'], null),
                        'empty' => $service->authLogin($fixture['account'], ''), default => $service->authLogin($fixture['account'], '0') };
                } catch (Throwable $caught) { $error = $caught; }
                m5_check($error instanceof \crmeb\exceptions\AuthException && $error->getCode() === 401, 'real service rejects the exact input with controlled authentication401');
            });
        });
    }
    foreach (['missing' => [], 'null' => ['appsecret' => null], 'empty' => ['appsecret' => ''], 'string-zero' => ['appsecret' => '0']] as $input => $body) {
        m5_case('A28', 'out-http-validator-' . $input, 'real HTTP validator retains missing/null/empty400; string-zero passes require and is rejected by credential service401', static function () use ($input, $body): void {
            m5_prepare(); $fixture = $GLOBALS['m5_accounts']['out_101'];
            m5_negative(static fn(): array => m5_http('POST', '/outapi/access_token', null, ['appid' => $fixture['account']] + $body), $input === 'string-zero' ? 401 : 400);
        });
    }
    foreach (['wrong-secret', 'disabled-account', 'deleted-account'] as $mode) {
        m5_case($mode === 'wrong-secret' ? 'A28' : 'A19', 'out-credential-refusal-' . $mode, 'invalid/inactive/deleted actual credential login is401 with no token, statistics, cache or business writes', static function () use ($mode): void {
            m5_prepare(); $name = $mode === 'disabled-account' ? 'out_disabled' : 'out_101'; $fixture = $GLOBALS['m5_accounts'][$name]; $row = m5_row($name);
            if ($mode === 'deleted-account') { audit_db()->prepare('UPDATE eb_out_account SET is_del=1 WHERE id=?')->execute([$fixture['id']]); }
            try {
                m5_negative(static fn(): array => m5_http('POST', '/outapi/access_token', null,
                    ['appid' => $fixture['account'], 'appsecret' => $mode === 'wrong-secret' ? bin2hex(random_bytes(12)) : $fixture['password']]), 401);
            } finally { if ($mode === 'deleted-account') { audit_db()->prepare('UPDATE eb_out_account SET is_del=? WHERE id=?')->execute([$row['is_del'], $fixture['id']]); } }
        });
    }
    foreach (['disabled' => 'status', 'deleted' => 'is_del'] as $state => $column) {
        foreach (['protected', 'refresh'] as $operation) {
            m5_case('A19', 'out-current-' . $state . '-' . $operation, 'current database disabled/deleted state refuses an already issued actual token before protected work/redemption; zero effects', static function () use ($state, $column, $operation): void {
                $valid = m5_prepare(); $row = m5_row('out_101'); $id = $GLOBALS['m5_accounts']['out_101']['id']; $other = m5_login('out_102');
                // Exact synthetic DML deliberately bypasses future Task6 writers:
                // this observes current-reader rejection, not permanent rotation.
                audit_db()->prepare('UPDATE eb_out_account SET ' . $column . '=? WHERE id=?')->execute([$state === 'disabled' ? 0 : 1, $id]);
                try {
                    m5_negative(static fn(): array => m5_request_operation($operation, $valid), 401);
                    m5_check(m5_status(m5_http('GET', '/outapi/category/list', $other['token'])) === 200, 'other external subject retains its original authorization');
                } finally { audit_db()->prepare('UPDATE eb_out_account SET ' . $column . '=? WHERE id=?')->execute([$row[$column], $id]); }
            });
        }
    }
    m5_case('A25', 'out-real-refresh-once-other-sids', 'real refresh consumes exactly the presented old sid and creates one new active bound token; independent existing sessions stay usable', static function (): void {
        m5_prepare(); $old = m5_login('out_101'); $oldBinding = m5_binding($old);
        $same = m5_login('out_101'); $other = m5_login('out_102');
        audit_expect(isset($oldBinding['session_key']), 'actual old v2 sid exists before redemption');
        $keysBefore = m5_scan($GLOBALS['m5_v2_prefix'] . 'session:*'); $oldRecord = audit_redis()->hGetAll($oldBinding['session_key']);
        $fresh = m5_success_effects(static function () use ($old): array {
            $response = m5_http('POST', '/outapi/refresh_token', null, ['access_token' => $old['token']]);
            $token = $response['json']['data']['access_token'] ?? null;
            audit_expect(m5_status($response) === 200 && is_string($token) && $token !== '', 'normal refresh returns a real replacement');
            $GLOBALS['m5_live_tokens'][] = $token; m5_track($token, 'out_101');
            return ['token' => $token, 'response' => $response, 'fixture' => 'out_101'];
        }, 'out_101', [$oldBinding['session_key']]);
        $newBinding = m5_binding($fresh); $afterRecord = audit_redis()->hGetAll($oldBinding['session_key']); $expectedOld = $oldRecord; $expectedOld['status'] = 'consumed';
        m5_check($afterRecord === $expectedOld, 'old HASH is retained with exactly status changed to consumed');
        $newKeys = array_values(array_diff(m5_scan($GLOBALS['m5_v2_prefix'] . 'session:*'), $keysBefore));
        m5_check(count($newKeys) === 1 && $newKeys[0] === ($newBinding['session_key'] ?? null) && $fresh['token'] !== $old['token'], 'one refresh creates exactly one distinct active sid and token');
        m5_negative(static fn(): array => m5_http('GET', '/outapi/category/list', $old['token']), 401);
        m5_negative(static fn(): array => m5_http('POST', '/outapi/refresh_token', null, ['access_token' => $old['token']]), 401);
        foreach ([$fresh, $same, $other] as $login) { m5_check(m5_status(m5_http('GET', '/outapi/category/list', $login['token'])) === 200, 'new/same-account/other-account real session keeps its original grant'); }
        $expectedKeys = array_values(array_unique(array_merge($keysBefore, $newKeys))); sort($expectedKeys, SORT_STRING);
        m5_check(m5_scan($GLOBALS['m5_v2_prefix'] . 'session:*') === $expectedKeys, 'old-token retries produce no second replacement and preserve every existing sid');
    });
    foreach (['protected', 'refresh'] as $operation) {
        m5_case('A24', 'out-old-schema-existing-md5-' . $operation, 'signed legacy out token is401 although its exact real serialized legacy whitelist remains; refusal has zero effects', static function () use ($operation): void {
            m5_start(['APP_DEBUG' => 'true']); $fixture = $GLOBALS['m5_accounts']['out_101']; $now = time();
            $legacy = audit_forge_jwt(['iss' => 'audit-app', 'aud' => 'audit-app', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600,
                'jti' => ['id' => $fixture['id'], 'type' => 'out'], 'pwd' => md5('')], $GLOBALS['m5_config']['signing_key']);
            $GLOBALS['m5_live_tokens'][] = $legacy; $key = $GLOBALS['m5_current_prefix'] . md5($legacy);
            audit_redis()->setex($key, 3660, serialize(['uid' => $fixture['id'], 'type' => 'out', 'token' => $legacy, 'exp' => 3660]));
            try { m5_negative(static fn(): array => m5_request_operation($operation, ['token' => $legacy]), 401); m5_check(audit_redis()->exists($key) === 1, 'refusal neither reads through nor removes the exact legacy whitelist'); }
            finally { audit_redis()->del($key); }
        });
        m5_case('A23', 'out-real-v2-session-missing-' . $operation, 'signature-valid actual external token whose sid is absent is401 and is never rebuilt', static function () use ($operation): void {
            $valid = m5_prepare(); $binding = m5_binding($valid); $other = m5_login('out_102');
            audit_expect(isset($binding['session_key']), 'exact v2 sid exists before scoped removal'); audit_redis()->del($binding['session_key']);
            m5_negative(static fn(): array => m5_request_operation($operation, $valid), 401);
            m5_check(audit_redis()->exists($binding['session_key']) === 0, 'missing real sid is not rebuilt');
            m5_check(m5_status(m5_http('GET', '/outapi/category/list', $other['token'])) === 200, 'other external account remains usable');
        });
    }
    foreach (['missing-epoch' => ['AUTH_SESSION_EPOCH' => ''], 'missing-stamp' => ['AUTH_STAMP_KEY' => ''],
        'short-stamp' => ['AUTH_STAMP_KEY' => 'short'], 'file-driver' => ['CACHE_DRIVER' => 'file']] as $name => $override) {
        foreach (['login', 'protected', 'refresh'] as $operation) {
            m5_case('A10', $name . '-out-' . $operation, 'cold real HTTP configuration failure is direct503 before input filtering, translation, statistics or cache/log writes', static function () use ($override, $operation): void {
                m5_start(); $valid = m5_login('out_101'); $prefix = m5_start($override + ['APP_DEBUG' => 'true']);
                audit_expect(m5_scan($prefix . '*') === [], 'negative config cache is genuinely cold');
                try { m5_negative(static fn(): array => m5_request_operation($operation, $valid), 503); m5_check(m5_scan($prefix . '*') === [], '503 neither warms translation nor input-filter caches'); }
                finally { m5_start(); }
            });
        }
    }
    foreach (['login', 'protected', 'refresh'] as $operation) {
        m5_case('A23', 'redis-pause-out-' . $operation, 'real manifest-owned Redis pause returns bounded controlled503 before stats/session/cache/log writes', static function () use ($operation): void {
            m5_start(); $valid = m5_login('out_101'); $prefix = m5_start(['APP_DEBUG' => 'true']);
            m5_negative(static function () use ($operation, $valid): array { audit_fault_redis('pause'); return m5_request_operation($operation, $valid); }, 503,
                static function (): void { audit_fault_redis('unpause'); });
            m5_check(m5_scan($prefix . '*') === [], 'real pause refusal does not initialize cold input/translation cache');
        });
        m5_case('A23', 'database-closed-port-out-' . $operation, 'real application connection at closed isolated MySQL port is direct503 before session redemption/stats/cache/log writes', static function () use ($operation): void {
            m5_start(); $valid = m5_login('out_101'); $prefix = m5_start(['DB_PORT' => '1', 'APP_DEBUG' => 'true']);
            try { m5_negative(static fn(): array => m5_request_operation($operation, $valid), 503); m5_check(m5_scan($prefix . '*') === [], 'DB connection refusal does not warm cold input/translation cache'); }
            finally { m5_start(); }
        });
    }
    foreach (['initialize', 'category-tool'] as $method) {
        foreach (['healthy', 'missing-jwt-epoch'] as $configMode) {
            m5_case('A28', 'mcp-' . $method . '-' . $configMode, 'real MCP account/password header protocol remains independent of JWT session epoch; initialize and real read-only category tool succeed without effects', static function () use ($method, $configMode): void {
                m5_start($configMode === 'missing-jwt-epoch' ? ['AUTH_SESSION_EPOCH' => ''] : []);
                $response = m5_readonly(static fn(): array => $method === 'initialize'
                    ? m5_mcp('initialize', ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'audit', 'version' => '1']], 701)
                    : m5_mcp('tools/call', ['name' => 'crmeb_category_list', 'arguments' => ['page' => 1, 'limit' => 1, 'tree' => false]], 702));
                audit_expect(is_array($response), 'real MCP response is observed'); m5_mcp_success($response, $method === 'initialize' ? 701 : 702, $method);
            });
        }
    }
    foreach (['wrong-secret', 'disabled-account', 'deleted-account', 'missing-headers'] as $mode) {
        m5_case(in_array($mode, ['disabled-account', 'deleted-account'], true) ? 'A19' : 'A28', 'mcp-credential-refusal-' . $mode, 'existing MCP invalid/inactive/deleted/missing header response stays JSON-RPC error-32600 with no data or effects', static function () use ($mode): void {
            m5_start(); $row = m5_row('out_101'); $id = $GLOBALS['m5_accounts']['out_101']['id'];
            if ($mode === 'deleted-account') { audit_db()->prepare('UPDATE eb_out_account SET is_del=1 WHERE id=?')->execute([$id]); }
            try {
                $response = m5_readonly(static fn(): array => m5_mcp('initialize', ['protocolVersion' => '2024-11-05'], 703,
                    $mode === 'missing-headers' ? null : ($mode === 'disabled-account' ? 'out_disabled' : 'out_101'),
                    $mode === 'wrong-secret' ? bin2hex(random_bytes(12)) : null));
                m5_check(($response['http'] ?? 0) === 200 && ($response['json']['jsonrpc'] ?? '') === '2.0'
                    && ($response['json']['id'] ?? null) === 703 && ($response['json']['error']['code'] ?? null) === -32600
                    && !array_key_exists('result', $response['json'] ?? []), 'independent MCP refusal retains its actual error protocol, not a JWT401/503 envelope');
            } finally { if ($mode === 'deleted-account') { audit_db()->prepare('UPDATE eb_out_account SET is_del=? WHERE id=?')->execute([$row['is_del'], $id]); } }
        });
    }
    // Root will append real dual-worker refresh and disconnect transport here.
    // No PASS/BLOCKED pseudo-case is emitted for either unfinished acceptance.
} finally {
    try { audit_app_request('stop'); }
    finally {
        foreach ($GLOBALS['m5_fixture_restore'] as $name => $columns) {
            audit_db()->prepare('UPDATE eb_out_account SET apppwd=?,push_password=? WHERE id=?')->execute([
                $columns['apppwd'], $columns['push_password'], $GLOBALS['m5_accounts'][$name]['id']]);
        }
        audit_guard_assert();
        $keys = array_values(array_unique(array_merge($GLOBALS['m5_owned_sessions'], $GLOBALS['m5_owned_accounts'])));
        foreach ($GLOBALS['m5_cache_prefixes'] as $prefix) { $keys = array_merge($keys, m5_scan($prefix . '*')); }
        $keys = array_merge($keys, array_values(array_diff(m5_scan($GLOBALS['m5_cli_prefix'] . '*'), $GLOBALS['m5_cli_keys_before'])));
        if ($keys !== []) { audit_redis()->del(array_values(array_unique($keys))); }
    }
}
audit_finish();
