<?php
declare(strict_types=1);

/* Independent normal-business draft; install beside lib/harness.php after review.
 * Six cases only. No stopped aggregate suite is required or invoked.
 * Only valid synthetic credentials/current real tokens are sent. No explicit
 * credential/status changes, negative request, old-token retry, fault, concurrent client or
 * response-disconnect scenario exists here. Not executed by the preparer.
 */
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');
$GLOBALS['nf_config'] = audit_config();
$GLOBALS['nf_auth'] = $GLOBALS['nf_config']['auth'] ?? [];
audit_expect(($GLOBALS['nf_auth']['session_epoch'] ?? '') === 'audit-http-' . getenv('AUDIT_RUN_ID')
    && is_string($GLOBALS['nf_auth']['stamp_key'] ?? null) && strlen($GLOBALS['nf_auth']['stamp_key']) >= 32,
    'normal functional RUN must already have valid private v2 configuration');
audit_expect(is_dir('/run/audit-app-runtime'), 'read-only actual app runtime observation is required');
$GLOBALS['nf_accounts'] = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['nf_secrets'] = [$GLOBALS['nf_config']['signing_key'], $GLOBALS['nf_auth']['stamp_key'],
    $GLOBALS['nf_config']['redis_password'], $GLOBALS['nf_config']['database']['password']];
foreach (['out_101', 'out_102'] as $name) {
    $fixture = $GLOBALS['nf_accounts'][$name] ?? [];
    audit_expect(is_int($fixture['id'] ?? null) && is_string($fixture['account'] ?? null)
        && is_string($fixture['password'] ?? null), 'existing enabled synthetic metadata is required');
    $GLOBALS['nf_secrets'][] = $fixture['password'];
}
$GLOBALS['nf_prefix'] = 'audit:http-lang:' . getenv('AUDIT_RUN_ID') . ':1:';
$GLOBALS['nf_v2_prefix'] = 'auth:v2:' . $GLOBALS['nf_auth']['session_epoch'] . ':outapi:';
$GLOBALS['nf_cli_prefix'] = 'audit:function-cli:' . getenv('AUDIT_RUN_ID') . ':';
$GLOBALS['nf_owned_keys'] = [];
$GLOBALS['nf_spec'] = ['tables' => [], 'redis' => ['*']];
$GLOBALS['nf_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'actual snapshot table identity is safe');
    $GLOBALS['nf_spec']['tables'][$table] = null;
}
foreach ([$GLOBALS['nf_prefix'], $GLOBALS['nf_v2_prefix'], $GLOBALS['nf_cli_prefix']] as $prefix) {
    audit_expect(nf_scan($prefix . '*') === [], 'functional namespace must be empty in this new RUN');
}
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
$app = new \think\App(audit_repo_dir() . '/crmeb/');
$app->env->set([
    'APP_DEBUG' => false, 'APP_APP_KEY' => $GLOBALS['nf_config']['signing_key'],
    'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
    'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
    'DATABASE_PASSWORD' => $GLOBALS['nf_config']['database']['password'],
    'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => false,
    'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
    'REDIS_REDIS_PASSWORD' => $GLOBALS['nf_config']['redis_password'],
    // Same normal deployment cache prefix as the HTTP producer. No alternate
    // whitelist or synthetic session is created for the CLI DTO consumer.
    'CACHE_CACHE_PREFIX' => $GLOBALS['nf_prefix'],
    'AUTH_SESSION_EPOCH' => $GLOBALS['nf_auth']['session_epoch'],
    'AUTH_STAMP_KEY' => $GLOBALS['nf_auth']['stamp_key'],
    'AUTH_ISSUER' => $GLOBALS['nf_auth']['issuer'] ?? 'tianli-mall',
]);
$app->initialize(); $GLOBALS['nf_app'] = $app;

function nf_scan(string $pattern): array
{
    $cursor = null; $keys = []; $redis = audit_redis();
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING); return array_values(array_unique($keys));
}
function nf_check(bool $pass, string $description): void
{
    $GLOBALS['nf_capture']['checks'][] = ['passed' => $pass, 'description' => $description];
}
function nf_rows(): array
{
    $rows = [];
    foreach (audit_db()->query('SELECT * FROM eb_out_account ORDER BY id')->fetchAll() as $row) { $rows[(string)$row['id']] = $row; }
    return $rows;
}
function nf_row(string $name): array
{
    $id = $GLOBALS['nf_accounts'][$name]['id']; $row = nf_rows()[$id] ?? null;
    audit_expect(is_array($row) && $row['appid'] === $GLOBALS['nf_accounts'][$name]['account']
        && (int)$row['status'] === 1 && (int)$row['is_del'] === 0, 'normal synthetic subject exists and is enabled');
    foreach (['appsecret', 'apppwd', 'push_password'] as $field) {
        if (is_string($row[$field] ?? null) && $row[$field] !== '') { $GLOBALS['nf_secrets'][] = $row[$field]; }
    }
    return $row;
}
function nf_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['nf_spec']); $snapshot['expires'] = [];
    $keys = array_keys($snapshot['redis']['*']);
    if ($keys === []) { return $snapshot; }
    $snapshot['expires'] = nf_deadlines($keys);
    return $snapshot;
}
function nf_deadlines(array $keys): array
{
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
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'observe every actual Redis absolute deadline');
    $result = [];
    foreach ($keys as $index => $key) { $result[$key] = $deadlines[$index]; }
    return $result;
}
function nf_runtime(): array
{
    $files = [];
    foreach (['/run/audit-app-runtime', $GLOBALS['nf_app']->getRuntimePath()] as $index => $root) {
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
function nf_logs_private(): bool
{
    $paths = [audit_run_dir() . '/evidence/app-runtime.log'];
    foreach (['/run/audit-app-runtime', $GLOBALS['nf_app']->getRuntimePath()] as $root) {
        if (!is_dir($root)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) { if ($file->isFile() && preg_match('~(^|/)log(/|$)~', $file->getPathname()) === 1) { $paths[] = $file->getPathname(); } }
    }
    foreach ($paths as $path) {
        if (!is_file($path)) { continue; }
        $bytes = file_get_contents($path);
        foreach (array_unique($GLOBALS['nf_secrets']) as $secret) { if ($secret !== '' && str_contains($bytes, $secret)) { return false; } }
    }
    return true;
}
function nf_http(string $method, string $path, ?string $token = null, ?array $body = null, array $headers = []): array
{
    if ($token !== null) { $headers['Authori-zation'] = 'Bearer ' . $token; }
    $response = audit_http($method, 'http://audit-app:8000' . $path, $headers, $body);
    $status = $response['json']['status'] ?? null; $numericStatus = is_numeric($status);
    $GLOBALS['nf_capture']['requests'][] = ['method' => $method, 'path' => $path, 'http' => $response['http'],
        'business_status' => $numericStatus ? (int)$status : null, 'invalid_business_status_type' => $status !== null && !$numericStatus,
        'rpc_result' => isset($response['json']['result'])];
    return $response;
}
function nf_claims(string $token): array
{
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token, $GLOBALS['nf_config']['signing_key'], ['HS256']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
}
function nf_binding(array $login): array
{
    $fixture = $GLOBALS['nf_accounts'][$login['name']]; $claims = nf_claims($login['token']); $row = nf_row($login['name']);
    $hasV2 = ($claims['auth_schema'] ?? null) === 2 && is_string($claims['sid'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/D', $claims['sid']) === 1;
    nf_check($hasV2, 'normal issued token has the v2 contract required before observing its actual session');
    // An unchanged normal service may issue its genuine prior protocol. Record
    // the new-contract failure but keep subsequent ordinary workflows real.
    // Never invent a sid or server record to make this condition pass.
    if (!$hasV2) { return ['session' => null, 'account' => null, 'record' => []]; }
    $key = $GLOBALS['nf_v2_prefix'] . 'session:' . $claims['sid']; $accountKey = $GLOBALS['nf_v2_prefix'] . 'account:' . $fixture['id'];
    $GLOBALS['nf_owned_keys'][] = $key; $GLOBALS['nf_owned_keys'][] = $accountKey;
    $session = audit_redis()->hGetAll($key); $account = audit_redis()->hGetAll($accountKey);
    $stamp = hash_hmac('sha256', 'outapi|' . $fixture['id'] . '|' . $row['appsecret'], $GLOBALS['nf_auth']['stamp_key']);
    nf_check(($claims['aud'] ?? '') === 'outapi' && ($claims['jti']['type'] ?? '') === 'out'
        && (string)($claims['jti']['id'] ?? '') === (string)$fixture['id'] && ($claims['session_epoch'] ?? '') === $GLOBALS['nf_auth']['session_epoch'], 'normal token represents exactly this external subject and application');
    nf_check(($session['subject_id'] ?? '') === (string)$fixture['id'] && ($session['status'] ?? '') === 'active'
        && ($session['token_digest'] ?? '') === hash('sha256', $login['token']) && ($session['credential_stamp'] ?? '') === $stamp
        && ($session['type'] ?? '') === 'out' && ($session['audience'] ?? '') === 'outapi'
        && ($session['session_version'] ?? '') === ($claims['session_version'] ?? null)
        && ($session['expires_at'] ?? '') === (string)($claims['exp'] ?? ''), 'independent Redis observes the actual active token/credential/identity binding');
    nf_check(($account['initialized'] ?? '') === '1' && ($account['blocked'] ?? '') === '0'
        && ($account['session_version'] ?? '') === ($claims['session_version'] ?? null) && audit_redis()->ttl($accountKey) === -1, 'normal account state is initialized, active and persistent');
    nf_check((int)($login['response']['json']['data']['exp_time'] ?? 0) === (int)($claims['exp'] ?? -1), 'normal response expiry agrees with the real signed token');
    $deadline = nf_deadlines([$key])[$key];
    nf_check(is_int($claims['exp'] ?? null) && abs($deadline - ((int)$claims['exp'] + 60) * 1000) <= 1,
        'actual session Redis absolute deadline equals signed expiry plus60 seconds within1ms');
    return ['session' => $key, 'account' => $accountKey, 'record' => $session];
}
function nf_dto(array $dto, string $name): void
{
    $row = nf_row($name);
    nf_check((string)($dto['id'] ?? '') === (string)$row['id'] && ($dto['appid'] ?? '') === $row['appid']
        && ($dto['rules'] ?? null) === $row['rules'] && ($dto['title'] ?? null) === $row['title'], 'normal auth_info identity and original visible rules/title belong to the current subject');
    foreach (['appsecret', 'apppwd', 'push_password'] as $field) { nf_check(!array_key_exists($field, $dto), 'normal returned DTO omits private field ' . $field); }
}
function nf_login(string $name, bool $assertContract = true): array
{
    $fixture = $GLOBALS['nf_accounts'][$name];
    $response = nf_http('POST', '/outapi/access_token', null, ['appid' => $fixture['account'], 'appsecret' => $fixture['password']]);
    $token = $response['json']['data']['access_token'] ?? null;
    audit_expect($response['http'] === 200 && (int)($response['json']['status'] ?? 0) === 200
        && is_string($token) && $token !== '', 'normal actual credentials return successful real HTTP login');
    $GLOBALS['nf_secrets'][] = $token;
    $login = ['token' => $token, 'response' => $response, 'name' => $name];
    // Track only actual claims from the real, successfully issued HTTP token.
    // Healthy old-service preparation may use its genuine existing protocol.
    $claims = nf_claims($token);
    if (($claims['auth_schema'] ?? null) === 2 && is_string($claims['sid'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $claims['sid']) === 1) {
        $GLOBALS['nf_owned_keys'][] = $GLOBALS['nf_v2_prefix'] . 'session:' . $claims['sid'];
        $GLOBALS['nf_owned_keys'][] = $GLOBALS['nf_v2_prefix'] . 'account:' . $fixture['id'];
    }
    if ($assertContract) { nf_binding($login); nf_dto($response['json']['data']['auth_info'] ?? [], $name); }
    return $login;
}
function nf_category(string $token): array
{
    $response = nf_http('GET', '/outapi/category/list', $token);
    nf_check($response['http'] === 200 && (int)($response['json']['status'] ?? 0) === 200
        && is_array($response['json']['data'] ?? null), 'normal current token reads the real originally granted category endpoint');
    $actual = $response['json']['data'] ?? []; $expected = [];
    foreach (audit_db()->query('SELECT id,cate_name,pid,pic,big_pic,sort,is_show,add_time FROM eb_store_category WHERE is_show=1')->fetchAll() as $row) {
        // Real OUT model getter formats this field; MCP's raw Db query does not.
        $row['add_time'] = date('Y-m-d H:i:s', (int)$row['add_time']);
        $expected[(string)$row['id']] = $row;
    }
    $seen = []; $same = is_array($actual) && count($actual) === count($expected); $lastSort = PHP_INT_MAX;
    foreach (is_array($actual) ? $actual : [] as $row) {
        if (!is_array($row)) { $same = false; continue; }
        $id = (string)($row['id'] ?? '');
        if (!isset($expected[$id]) || isset($seen[$id])) { $same = false; continue; }
        $keys = array_keys($row); $expectedKeys = array_keys($expected[$id]); sort($keys); sort($expectedKeys);
        if ($keys !== $expectedKeys) { $same = false; }
        foreach ($expected[$id] as $field => $value) {
            $actualValue = $row[$field] ?? null;
            if (($value === null ? $actualValue !== null : (string)$actualValue !== (string)$value)) { $same = false; }
        }
        if ((int)$row['sort'] > $lastSort) { $same = false; }
        $lastSort = (int)$row['sort'];
        $seen[$id] = true;
    }
    nf_check($same && count($seen) === count($expected), 'actual OUT category rows match all8 selected visible DB columns in descending sort order; equal-sort rows need no invented id ordering');
    return $response;
}
function nf_window(callable $operation, array $targets = [], ?string $oldSid = null, bool $preparation = false): mixed
{
    audit_guard_assert(); $before = nf_snapshot(); $runtimeBefore = nf_runtime(); $rowsBefore = nf_rows();
    $keysBefore = $GLOBALS['nf_owned_keys']; $start = time(); $result = null;
    try { $result = $operation(); } catch (Throwable $error) { nf_check(false, 'normal operation returns successfully (' . get_class($error) . ')'); }
    $end = time(); audit_guard_assert(); $after = nf_snapshot(); $runtimeAfter = nf_runtime(); $rowsAfter = nf_rows();
    $targetIds = array_map(static fn(string $name): string => (string)$GLOBALS['nf_accounts'][$name]['id'], $targets);
    $sameRows = count($rowsBefore) === count($rowsAfter);
    foreach ($rowsBefore as $id => $row) {
        foreach ($row as $field => $value) {
            if (in_array((string)$id, $targetIds, true) && in_array($field, ['last_time', 'ip'], true)) { continue; }
            if (($rowsAfter[$id][$field] ?? null) !== $value) { $sameRows = false; }
        }
    }
    foreach ($targetIds as $id) {
        nf_check((int)$rowsAfter[$id]['last_time'] >= $start && (int)$rowsAfter[$id]['last_time'] <= $end
            && $rowsAfter[$id]['ip'] === gethostbyname(gethostname()), 'normal target statistics match the actual request; same-second zero affected rows is allowed');
    }
    $dbDiff = [];
    foreach ($before['tables'] as $table => $value) {
        if ($value !== ($after['tables'][$table] ?? null) && !($table === 'eb_out_account' && $sameRows)) { $dbDiff[] = $table; }
    }
    nf_check($sameRows && $dbDiff === [], 'all other account columns/rows and all other installation/sentinel tables are preserved');
    $allowed = array_values(array_diff(array_unique($GLOBALS['nf_owned_keys']), $keysBefore));
    if ($oldSid !== null) { $allowed[] = $oldSid; }
    // Only normal preparation may initialize its already-confirmed-empty
    // private HTTP cache namespace. Exact added-key hashes are observed below.
    $newCache = $preparation ? nf_scan($GLOBALS['nf_prefix'] . '*') : [];
    $allowed = array_values(array_unique(array_merge($allowed, $newCache))); $cacheDiff = [];
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowed, true)) { continue; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $cacheDiff[] = hash('sha256', $key) . ':value'; }
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $cacheDiff[] = hash('sha256', $key) . ':expiry'; }
    }
    if ($oldSid !== null) {
        nf_check(isset($before['expires'][$oldSid], $after['expires'][$oldSid])
            && abs($before['expires'][$oldSid] - $after['expires'][$oldSid]) <= 1, 'normal refresh preserves its predecessor Redis absolute deadline');
    }
    nf_check($cacheDiff === [], 'every unrelated actual Redis value and absolute expiry remains unchanged');
    nf_check($runtimeBefore === $runtimeAfter && nf_logs_private(), 'normal HTTP/CLI file cache and business logs are unchanged; server logs contain no private values');
    $GLOBALS['nf_capture']['db_diff'] = array_merge($GLOBALS['nf_capture']['db_diff'], $dbDiff, $sameRows ? [] : ['eb_out_account']);
    $GLOBALS['nf_capture']['cache_diff'] = array_merge($GLOBALS['nf_capture']['cache_diff'], $cacheDiff);
    $GLOBALS['nf_capture']['observations'][] = ['window_completed' => true, 'tables' => count($before['tables']), 'redis_values_before' => count($before['redis']['*']),
        'redis_values_after' => count($after['redis']['*']), 'allowed_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $allowed),
        'normal_cache_initializations' => count($newCache),
        'db_before_sha256' => hash('sha256', json_encode($before['tables'], JSON_THROW_ON_ERROR)),
        'db_after_sha256' => hash('sha256', json_encode($after['tables'], JSON_THROW_ON_ERROR)),
        'runtime_before_sha256' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)),
        'runtime_after_sha256' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR))];
    return $result;
}
function nf_capture_start(): void { $GLOBALS['nf_capture'] = ['checks' => [], 'requests' => [], 'observations' => [], 'db_diff' => [], 'cache_diff' => []]; }
function nf_evidence(string $id, string $expected): string
{
    $ref = 'evidence/out-functional-' . $GLOBALS['nf_execution'] . '-' . $id . '.json';
    audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
        'case_id' => $id, 'expected' => $expected] + $GLOBALS['nf_capture']);
    return $ref;
}
function nf_case(string $id, string $name, callable $operation): void
{
    audit_case($id, $name, 'INTEGRATION', static function () use ($id, $name, $operation): array {
        nf_capture_start();
        try { $operation(); } catch (Throwable $error) { nf_check(false, 'normal case preparation/operation succeeds (' . get_class($error) . ')'); }
        $capture = $GLOBALS['nf_capture']; $ref = nf_evidence($id, $name);
        $windows = count(array_filter($capture['observations'], static fn(array $observation): bool => ($observation['window_completed'] ?? false) === true));
        return ['status' => $capture['checks'] !== [] && !in_array(false, array_column($capture['checks'], 'passed'), true) ? 'PASS' : 'FAIL',
            'expected' => $name, 'actual' => 'normal checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks'])
                . '; completed observation windows ' . $windows,
            'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'], 'evidence_ref' => $ref,
            'finding_kind' => '新增契约', 'notes' => 'Independent normal functional checks only; zero completed windows means its operation/contract precondition was not reached. Skipped security/fault/concurrency/disconnect coverage is not implied.'];
    });
}
function nf_mcp(string $method, array $params, int $id): array
{
    $fixture = $GLOBALS['nf_accounts']['out_101'];
    return nf_http('POST', '/outapi/mcp', null, ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params],
        ['account' => $fixture['account'], 'password' => $fixture['password']]);
}

try {
    audit_app_request('start', ['CACHE_PREFIX' => $GLOBALS['nf_prefix']]);
    $listening = false;
    for ($attempt = 0; $attempt < 80; ++$attempt) {
        $socket = @fsockopen('audit-app', 8000, $errno, $message, 0.2);
        if (is_resource($socket)) { fclose($socket); $listening = true; break; }
        usleep(50000);
    }
    audit_expect($listening, 'normal isolated HTTP service listens');
    // Ordinary successful initialization; observed separately, not a PASS case.
    nf_capture_start();
    nf_window(static function (): void {
        $GLOBALS['nf_a'] = nf_login('out_101', false); $GLOBALS['nf_b'] = nf_login('out_102', false);
        nf_category($GLOBALS['nf_a']['token']);
    }, ['out_101', 'out_102'], null, true);
    nf_evidence('normal-preparation', 'only normal successful login/category cache initialization');
    audit_expect(!in_array(false, array_column($GLOBALS['nf_capture']['checks'], 'passed'), true), 'normal preparation observation succeeds');

    nf_case('F01', 'normal HTTP external login and auth_info', static function (): void {
        $actual = nf_window(static fn(): array => nf_login('out_101'), ['out_101']);
        // A real normal preparation token remains available to later ordinary
        // cases if this new v2-contract check fails on unchanged business code.
        if (is_array($actual) && is_string($actual['token'] ?? null)) { $GLOBALS['nf_a'] = $actual; }
    });
    nf_case('F02', 'normal originally granted category read', static function (): void {
        nf_window(static fn(): array => nf_category($GLOBALS['nf_a']['token']));
    });
    nf_case('F03', 'one normal refresh followed by a read using its new token', static function (): void {
        $old = $GLOBALS['nf_a']; $binding = nf_binding($old); $oldRecord = $binding['record'];
        $oldSid = $binding['session'];
        $fresh = nf_window(static function () use ($old, $oldSid): array {
            $response = nf_http('POST', '/outapi/refresh_token', null, ['access_token' => $old['token']]);
            $token = $response['json']['data']['access_token'] ?? null;
            audit_expect($response['http'] === 200 && (int)($response['json']['status'] ?? 0) === 200 && is_string($token) && $token !== '', 'one normal refresh returns its real replacement');
            $GLOBALS['nf_secrets'][] = $token;
            $fresh = ['token' => $token, 'response' => $response, 'name' => 'out_101']; $newBinding = nf_binding($fresh);
            nf_check($token !== $old['token'], 'normal refresh returns its actual distinct replacement token');
            nf_check(is_string($oldSid) && is_string($newBinding['session']) && $newBinding['session'] !== $oldSid,
                'normal v2 replacement has a distinct actual native sid');
            return $fresh;
        }, ['out_101'], $oldSid);
        audit_expect(is_array($fresh), 'actual normal replacement was observed');
        if (is_string($oldSid)) {
            $expectedOld = $oldRecord; $expectedOld['status'] = 'consumed';
            nf_check(audit_redis()->hGetAll($oldSid) === $expectedOld, 'normal refresh only marks its actual native predecessor consumed');
        }
        $GLOBALS['nf_capture']['observations'][] = ['native_predecessor_observed' => is_string($oldSid), 'normal_refresh_requests' => 1];
        $GLOBALS['nf_a'] = $fresh;
        nf_window(static fn(): array => nf_category($fresh['token']));
        // The predecessor token is never sent to a route or parseToken again.
    });
    nf_case('F04', 'normal same-instance service A/B/A identity DTOs', static function (): void {
        $service = $GLOBALS['nf_app']->make(\app\services\out\OutAccountServices::class);
        $a = $GLOBALS['nf_a']['token']; $b = $GLOBALS['nf_b']['token'];
        $dtos = nf_window(static fn(): array => [$service->parseToken($a), $service->parseToken($b), $service->parseToken($a)]);
        audit_expect(is_array($dtos) && count($dtos) === 3, 'three real CLI service DTOs are observed');
        nf_dto($dtos[0], 'out_101'); nf_dto($dtos[1], 'out_102'); nf_dto($dtos[2], 'out_101');
        nf_check($dtos[0] === $dtos[2] && (string)$dtos[0]['id'] !== (string)$dtos[1]['id'], 'one real service instance returns A/B/A without retaining the preceding subject');
        nf_check($dtos[1] === ($GLOBALS['nf_b']['response']['json']['data']['auth_info'] ?? null), 'real B service DTO matches its normal HTTP login auth_info');
        $GLOBALS['nf_capture']['observations'][] = ['execution' => 'real-framework-cli', 'same_instance' => true, 'parse_calls' => 3];
    });
    nf_case('F05', 'normal MCP header initialize', static function (): void {
        $response = nf_window(static fn(): array => nf_mcp('initialize', ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'normal-functional', 'version' => '1']], 501));
        nf_check(($response['http'] ?? 0) === 200 && ($response['json']['jsonrpc'] ?? '') === '2.0' && ($response['json']['id'] ?? null) === 501
            && ($response['json']['result']['protocolVersion'] ?? '') === '2024-11-05'
            && ($response['json']['result']['serverInfo']['name'] ?? '') === 'crmeb-mcp-server', 'normal real MCP header credentials initialize the existing protocol');
    });
    nf_case('F06', 'normal MCP actual read-only category tool', static function (): void {
        $response = nf_window(static fn(): array => nf_mcp('tools/call', ['name' => 'crmeb_category_list', 'arguments' => ['page' => 1, 'limit' => 1, 'tree' => false]], 502));
        $content = $response['json']['result']['content'][0] ?? [];
        $data = is_string($content['text'] ?? null) ? json_decode($content['text'], true) : null;
        $count = (int)audit_db()->query('SELECT COUNT(*) FROM eb_store_category WHERE is_show=1')->fetchColumn();
        $firstId = audit_db()->query('SELECT id FROM eb_store_category WHERE is_show=1 ORDER BY sort DESC,id DESC LIMIT 1')->fetchColumn();
        nf_check(($response['http'] ?? 0) === 200 && ($response['json']['jsonrpc'] ?? '') === '2.0'
            && ($response['json']['id'] ?? null) === 502 && !isset($response['json']['error'])
            && ($response['json']['result']['isError'] ?? false) === false && ($content['type'] ?? '') === 'text'
            && is_array($data) && is_array($data['list'] ?? null) && (int)($data['count'] ?? -1) === $count
            && count($data['list'] ?? []) === min(1, $count) && ($count === 0 || (string)($data['list'][0]['id'] ?? '') === (string)$firstId),
            'normal MCP tool returns the actual ordered database category row/count');
    });
} finally {
    try { audit_app_request('stop'); }
    finally {
        audit_guard_assert();
        $keys = array_values(array_unique(array_merge($GLOBALS['nf_owned_keys'], nf_scan($GLOBALS['nf_prefix'] . '*'), nf_scan($GLOBALS['nf_cli_prefix'] . '*'))));
        if ($keys !== []) { audit_redis()->del($keys); }
    }
}
audit_finish();
