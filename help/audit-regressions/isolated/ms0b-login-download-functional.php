<?php
declare(strict_types=1);

/* Independent ordinary HTTP/DAO checks. Draft only; require only harness and
 * autoload. No wrong credentials, captcha solution, old/URL token, retry,
 * repeated download, concurrent client, fault, or recovery operation exists.
 * Install beside lib/harness.php before execution in a new dedicated ISO RUN.
 */
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');
$only = $argv[1] ?? '--only=all';
audit_expect(count($argv) <= 2 && in_array($only, ['--only=all', '--only=q6', '--only=q7'], true), 'only this ordinary six-case script may be selected');
$GLOBALS['td_only'] = substr($only, 7);
$GLOBALS['td_config'] = audit_config();
$GLOBALS['td_auth'] = $GLOBALS['td_config']['auth'] ?? [];
audit_expect(($GLOBALS['td_auth']['session_epoch'] ?? '') === 'audit-http-' . getenv('AUDIT_RUN_ID'), 'dedicated normal v2 RUN configuration is required');
$GLOBALS['td_execution'] = gmdate('YmdHis') . random_int(100000, 999999);
$GLOBALS['td_prefix'] = 'audit:http-lang:' . getenv('AUDIT_RUN_ID') . ':' . $GLOBALS['td_execution'] . ':';
$GLOBALS['td_v2'] = 'auth:v2:' . $GLOBALS['td_auth']['session_epoch'] . ':adminapi:';
$GLOBALS['td_tag'] = $GLOBALS['td_prefix'] . 'CRMEB:' . md5('crmeb');
$GLOBALS['td_backup'] = audit_repo_dir() . '/crmeb/backup';
$GLOBALS['td_owned_keys'] = $GLOBALS['td_owned_rows'] = $GLOBALS['td_owned_files'] = $GLOBALS['td_owned_dirs'] = [];
$GLOBALS['td_secrets'] = [$GLOBALS['td_config']['signing_key'], $GLOBALS['td_auth']['stamp_key'], $GLOBALS['td_config']['redis_password'], $GLOBALS['td_config']['database']['password']];
$GLOBALS['td_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'actual table identity rejected');
    $GLOBALS['td_spec']['tables'][$table] = null;
}
audit_expect(count($GLOBALS['td_spec']['tables']) === 158 && is_dir('/run/audit-app-runtime')
    && is_dir($GLOBALS['td_backup']) && is_writable($GLOBALS['td_backup']), 'all installed tables and shared dedicated writable backup/read-only runtime mounts are required');
audit_expect(td_scan($GLOBALS['td_prefix'] . '*') === [], 'new ordinary cache namespace must be empty');
$GLOBALS['td_initial'] = td_snapshot();
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
$app = new \think\App(audit_repo_dir() . '/crmeb/');
$app->env->set(['APP_DEBUG' => false, 'APP_APP_KEY' => $GLOBALS['td_config']['signing_key'],
    'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
    'DATABASE_USERNAME' => 'audit_app', 'DATABASE_PASSWORD' => $GLOBALS['td_config']['database']['password'], 'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => false,
    'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379', 'REDIS_REDIS_PASSWORD' => $GLOBALS['td_config']['redis_password'],
    'CACHE_CACHE_PREFIX' => $GLOBALS['td_prefix'], 'AUTH_SESSION_EPOCH' => $GLOBALS['td_auth']['session_epoch'],
    'AUTH_STAMP_KEY' => $GLOBALS['td_auth']['stamp_key'], 'AUTH_ISSUER' => $GLOBALS['td_auth']['issuer'] ?? 'tianli-mall']);
$app->initialize(); $GLOBALS['td_app'] = $app;

function td_scan(string $pattern): array
{
    $redis = audit_redis(); $cursor = null; $keys = [];
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING); return array_values(array_unique($keys));
}
function td_rows(string $table): array
{
    $rows = [];
    foreach (audit_db()->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll() as $row) { $rows[(string)$row['id']] = $row; }
    return $rows;
}
function td_deadlines(array $keys): array
{
    if (!$keys) { return []; }
    $lua = "local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); if p>=0 then r[i]=n+p else r[i]=p end end; return r";
    $values = audit_redis()->eval($lua, $keys, count($keys));
    audit_expect(is_array($values) && count($values) === count($keys), 'every actual Redis absolute expiry must be observed');
    $result = []; foreach ($keys as $i => $key) { audit_expect(is_int($values[$i]), 'actual expiry must be integral'); $result[$key] = $values[$i]; }
    return $result;
}
function td_files(string $root, bool $directories = false): array
{
    $items = []; if (!is_dir($root)) { return $items; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        audit_expect(!$item->isLink(), 'observed dedicated file tree must contain no symbolic link');
        $relative = substr($item->getPathname(), strlen(rtrim($root, '/') . '/'));
        if ($item->isFile()) { $items[$relative] = ['bytes' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())]; }
        elseif ($directories && $item->isDir()) { $items[$relative . '/'] = ['directory' => true]; }
    }
    ksort($items); return $items;
}
function td_snapshot(): array
{
    $state = audit_snapshot($GLOBALS['td_spec']);
    $state['expires'] = td_deadlines(array_keys($state['redis']['*']));
    $state['tag_members'] = isset($state['redis']['*'][$GLOBALS['td_tag']]) ? audit_redis()->sMembers($GLOBALS['td_tag']) : [];
    $state['subject_account'] = null;
    if (isset($GLOBALS['td_subject'])) {
        $key = $GLOBALS['td_v2'] . 'account:' . $GLOBALS['td_subject']['id'];
        if (audit_redis()->type($key) === Redis::REDIS_HASH) { $state['subject_account'] = audit_redis()->hGetAll($key); }
    }
    $state['rows'] = []; foreach (['eb_system_admin', 'eb_system_crud', 'eb_system_crud_list', 'eb_system_log'] as $table) { $state['rows'][$table] = td_rows($table); }
    $state['runtime'] = [td_files('/run/audit-app-runtime'), td_files(audit_repo_dir() . '/crmeb/runtime')];
    $state['backup'] = td_files($GLOBALS['td_backup'], true);
    return $state;
}
function td_check(bool $pass, string $description): void { $GLOBALS['td_capture']['checks'][] = ['passed' => $pass, 'description' => $description]; }
function td_capture(): void { $GLOBALS['td_capture'] = ['checks' => [], 'requests' => [], 'observations' => [], 'db_diff' => [], 'cache_diff' => []]; }
function td_safe_error(Throwable $error): string { return get_class($error) . '@' . basename($error->getFile()) . ':' . $error->getLine(); }
function td_logs_private(): bool
{
    $paths = [audit_run_dir() . '/evidence/app-runtime.log'];
    foreach (['/run/audit-app-runtime', audit_repo_dir() . '/crmeb/runtime'] as $root) {
        if (!is_dir($root)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('~(^|/)log(/|$)~', $file->getPathname()) === 1) { $paths[] = $file->getPathname(); }
        }
    }
    foreach ($paths as $path) {
        if (!is_file($path)) { continue; }
        $bytes = file_get_contents($path);
        foreach (array_unique($GLOBALS['td_secrets']) as $secret) { if (is_string($secret) && $secret !== '' && str_contains($bytes, $secret)) { return false; } }
    }
    return true;
}
function td_http(string $method, string $path, ?string $token = null, ?array $body = null): array
{
    audit_guard_assert();
    // This local transport has fixed routes and an internal target. No external
    // producer URL, URL-token parameter, redirect, or arbitrary proxy is sent.
    $base = ['GET' => ['/adminapi/login/info', '/adminapi/ajcaptcha?captchaType=blockPuzzle'], 'POST' => ['/adminapi/login']];
    $allowed = in_array($path, $base[$method] ?? [], true);
    if ($method === 'GET' && isset($GLOBALS['td_crud_id'])) {
        $allowed = $allowed || $path === '/adminapi/system/crud/download/' . $GLOBALS['td_crud_id'];
    }
    if ($method === 'GET' && isset($GLOBALS['td_backup_time'])) {
        $allowed = $allowed || $path === '/adminapi/backup/download?time=' . $GLOBALS['td_backup_time'];
    }
    if ($method === 'GET' && isset($GLOBALS['td_route_key'])) { $allowed = $allowed || $path === '/adminapi/download/' . $GLOBALS['td_route_key']; }
    if ($method === 'GET' && isset($GLOBALS['td_query_key'])) { $allowed = $allowed || $path === '/adminapi/download?' . http_build_query(['key' => $GLOBALS['td_query_key']], '', '&', PHP_QUERY_RFC3986); }
    audit_expect($allowed, 'only the exact ordinary routes owned by this RUN may be requested');
    $headers = ['Cb-lang: zh-CN']; if ($token !== null) { $headers[] = 'Authori-zation: Bearer ' . $token; }
    $curl = curl_init('http://audit-app:8000' . $path); $received = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
            if (preg_match('/^HTTP\//', $line)) { $received = []; }
            elseif (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $received[strtolower(trim($name))] = trim($value); }
            return strlen($line);
        }]);
    if ($body !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $errno = curl_errno($curl); curl_close($curl);
    audit_expect(is_string($raw), 'ordinary isolated binary HTTP transport failed, curl=' . $errno);
    $json = json_decode($raw, true);
    $GLOBALS['td_capture']['requests'][] = ['method' => $method, 'route_hash' => hash('sha256', $path), 'http' => $status,
        'business_status' => is_array($json) && is_numeric($json['status'] ?? null) ? (int)$json['status'] : null,
        'authenticated_header' => $token !== null, 'body_sha256' => hash('sha256', $raw), 'body_bytes' => strlen($raw)];
    return ['http' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw, 'headers' => $received];
}
function td_same(mixed $expected, mixed $actual): bool
{
    if ($expected instanceof Closure) { return (bool)$expected($actual); }
    return $expected === null ? $actual === null : $actual !== null && (string)$expected === (string)$actual;
}
function td_observe(array $before, array $after, array $rules, int $start, int $end, string $label): void
{
    $dbDiff = []; $rowChanges = $rules['rows'] ?? [];
    foreach ($before['tables'] as $table => $state) {
        if ($state === ($after['tables'][$table] ?? null)) { continue; }
        if (!isset($rowChanges[$table], $before['rows'][$table], $after['rows'][$table])) { $dbDiff[] = $table; continue; }
        $a = $before['rows'][$table]; $b = $after['rows'][$table]; $valid = true;
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $id) {
            $changes = $rowChanges[$table][$id] ?? [];
            if (isset($changes['create'])) { $valid = $valid && !isset($a[$id]) && ($b[$id] ?? null) === $changes['create']; continue; }
            if (isset($changes['delete'])) { $valid = $valid && isset($a[$id]) && !isset($b[$id]); continue; }
            if (!isset($a[$id], $b[$id]) || array_keys($a[$id]) !== array_keys($b[$id])) { $valid = false; continue; }
            foreach ($a[$id] as $field => $value) {
                $valid = $valid && (array_key_exists($field, $changes) ? td_same($changes[$field], $b[$id][$field]) : $value === $b[$id][$field]);
            }
        }
        if (!$valid) { $dbDiff[] = $table; }
    }
    td_check($dbDiff === [], 'all158 actual tables and every unrelated target row/column remain unchanged');
    $allowed = $rules['keys'] ?? []; $cacheDiff = [];
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowed, true)) { continue; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $cacheDiff[] = hash('sha256', $key) . ':value'; }
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $cacheDiff[] = hash('sha256', $key) . ':expiry'; }
    }
    td_check($cacheDiff === [], 'every unrelated actual Redis value and TIME/PTTL absolute deadline remains unchanged');
    td_check($before['runtime'] === $after['runtime'] && td_logs_private(), 'both actual runtime trees preserve every file and logs contain no private credential/token');
    $allowedFiles = $rules['files'] ?? []; $fileDiff = [];
    foreach (array_unique(array_merge(array_keys($before['backup']), array_keys($after['backup']))) as $path) {
        if (($before['backup'][$path] ?? null) === ($after['backup'][$path] ?? null)) { continue; }
        if (!isset($allowedFiles[$path]) || isset($before['backup'][$path]) || ($after['backup'][$path] ?? null) !== $allowedFiles[$path]) { $fileDiff[] = hash('sha256', $path); }
    }
    td_check($fileDiff === [], 'shared backup output changes only its individually verified owned files/directories');
    $GLOBALS['td_capture']['db_diff'] = array_merge($GLOBALS['td_capture']['db_diff'], $dbDiff);
    $GLOBALS['td_capture']['cache_diff'] = array_merge($GLOBALS['td_capture']['cache_diff'], $cacheDiff);
    $GLOBALS['td_capture']['observations'][] = ['label' => $label, 'window_completed' => true, 'start' => $start, 'end' => $end,
        'tables' => count($before['tables']), 'redis_values_before' => count($before['redis']['*']), 'redis_values_after' => count($after['redis']['*']),
        'allowed_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $allowed), 'unexpected_backup_paths' => $fileDiff,
        'db_before_sha256' => hash('sha256', serialize($before['tables'])), 'db_after_sha256' => hash('sha256', serialize($after['tables'])),
        'runtime_before_sha256' => hash('sha256', serialize($before['runtime'])), 'runtime_after_sha256' => hash('sha256', serialize($after['runtime']))];
}
function td_window(string $label, Closure $operation, ?Closure $rules = null): mixed
{
    audit_guard_assert(); $before = td_snapshot(); $start = time(); $result = null;
    try { $result = $operation(); } catch (Throwable $error) { td_check(false, 'ordinary operation completes (' . td_safe_error($error) . ')'); }
    $end = time(); audit_guard_assert(); $after = td_snapshot(); $allowed = [];
    try { if ($rules !== null) { $allowed = $rules($before, $after, $result, $start, $end); } }
    catch (Throwable $error) { td_check(false, 'ordinary effect verification completes (' . td_safe_error($error) . ')'); }
    td_observe($before, $after, $allowed, $start, $end, $label); return $result;
}
function td_evidence(string $id): string
{
    $ref = 'evidence/task8-functional-' . $GLOBALS['td_execution'] . '-' . $id . '.json';
    audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'selection' => $GLOBALS['td_only']] + $GLOBALS['td_capture']);
    return $ref;
}
function td_case(string $id, string $name, Closure $operation): void
{
    audit_case($id, $name, 'INTEGRATION', static function () use ($id, $name, $operation): array {
        td_capture(); try { $operation(); } catch (Throwable $error) { td_check(false, 'ordinary case finishes (' . td_safe_error($error) . ')'); }
        $capture = $GLOBALS['td_capture']; $ref = td_evidence($id); $windows = count($capture['observations']);
        return ['status' => $capture['checks'] && !in_array(false, array_column($capture['checks'], 'passed'), true) && $windows ? 'PASS' : 'FAIL',
            'expected' => $name, 'actual' => 'normal checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks']) . '; completed windows ' . $windows,
            'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'], 'evidence_ref' => $ref,
            'notes' => 'Only ordinary current credentials, captcha display and first file use. Skipped security/failure/recovery/concurrency coverage is not implied.'];
    });
}
function td_insert(string $table, array $data): array
{
    $columns = array_keys($data); $pdo = audit_db();
    $statement = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    $statement->execute(array_values($data)); $id = (string)$pdo->lastInsertId();
    $GLOBALS['td_owned_rows'][$table][] = $id; $row = td_rows($table)[$id] ?? null;
    audit_expect(is_array($row), 'exact newly created ordinary row exists');
    foreach ($data as $field => $value) { audit_expect(td_same($value, $row[$field] ?? null), 'new fixture stores its explicitly provided column'); }
    if (isset($row['pwd'])) { $GLOBALS['td_secrets'][] = $row['pwd']; }
    return $row;
}
function td_claims(string $token): object
{
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try { \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60; return \Firebase\JWT\JWT::decode($token, $GLOBALS['td_config']['signing_key'], ['HS256']); }
    finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
}
function td_login(): array
{
    $subject = $GLOBALS['td_subject'];
    $response = td_http('POST', '/adminapi/login', null, ['account' => $subject['account'], 'pwd' => $subject['password'], 'key' => '']);
    $token = $response['json']['data']['token'] ?? null;
    audit_expect($response['http'] === 200 && ($response['json']['status'] ?? null) === 200 && is_string($token) && $token !== '', 'real ordinary credentials successfully log in over HTTP');
    $GLOBALS['td_secrets'][] = $token; $claims = td_claims($token);
    audit_expect(($claims->jti->id ?? null) === (string)$subject['id'] && ($claims->aud ?? '') === 'adminapi'
        && is_string($claims->sid ?? null) && preg_match('/^[a-f0-9]{32}$/D', $claims->sid) === 1, 'normal returned signed token identifies this newly created subject');
    $session = $GLOBALS['td_v2'] . 'session:' . $claims->sid; $account = $GLOBALS['td_v2'] . 'account:' . $subject['id'];
    foreach ([$session, $account] as $key) { if (!isset($GLOBALS['td_initial']['redis']['*'][$key])) { $GLOBALS['td_owned_keys'][] = $key; } }
    return ['token' => $token, 'claims' => $claims, 'response' => $response, 'session' => $session, 'account' => $account];
}
function td_login_effects(array $before, array $after, mixed $login, int $start, int $end): array
{
    audit_expect(is_array($login), 'normal login result is available');
    $subject = $GLOBALS['td_subject']; $id = (string)$subject['id']; $a = $before['rows']['eb_system_admin'][$id]; $b = $after['rows']['eb_system_admin'][$id];
    $claims = $login['claims']; $config = \crmeb\services\AuthConfigGuard::authConfig(); $binding = $config['apps']['adminapi']; $redis = audit_redis();
    $expected = ['subject_id' => $id, 'type' => $binding['type'], 'audience' => $binding['audience'], 'session_version' => $claims->session_version,
        'token_digest' => hash('sha256', $login['token']), 'credential_stamp' => hash_hmac('sha256', 'adminapi|' . $id . '|' . $b['pwd'], $GLOBALS['td_auth']['stamp_key']),
        'expires_at' => (string)$claims->exp, 'status' => 'active'];
    $record = $redis->hGetAll($login['session']); ksort($record); ksort($expected);
    $account = $redis->hGetAll($login['account']); ksort($account);
    $expectedAccount = ['blocked' => '0', 'initialized' => '1', 'session_version' => $claims->session_version];
    $accountWasPresent = isset($before['redis']['*'][$login['account']]); $accountPreserved = true;
    if ($accountWasPresent) {
        $priorAccount = $before['subject_account'];
        if (is_array($priorAccount)) { ksort($priorAccount); }
        $accountPreserved = is_array($priorAccount) && $account === $priorAccount
            && ($claims->session_version ?? null) === ($priorAccount['session_version'] ?? null)
            && ($before['redis']['*'][$login['account']] ?? null) === ($after['redis']['*'][$login['account']] ?? null)
            && ($before['expires'][$login['account']] ?? -2) === -1 && ($after['expires'][$login['account']] ?? -2) === -1;
    }
    td_check(($claims->auth_schema ?? null) === 2 && ($claims->session_epoch ?? '') === $GLOBALS['td_auth']['session_epoch']
        && ($claims->iss ?? '') === $config['issuer'] && ($claims->aud ?? '') === $binding['audience'] && ($claims->jti->type ?? '') === $binding['type']
        && is_int($claims->iat ?? null) && $claims->nbf === $claims->iat && $claims->exp - $claims->iat === $binding['ttl']
        && is_string($claims->session_version ?? null) && preg_match('/^[a-f0-9]{32}$/D', $claims->session_version) === 1,
        'normal issued claims retain exact application/schema/epoch/issuer/type/fixed lifetime');
    td_check($record === $expected && $account === $expectedAccount && ($after['expires'][$login['account']] ?? -2) === -1
        && abs(($after['expires'][$login['session']] ?? -2) - ($claims->exp + 60) * 1000) <= 1
        && ($login['response']['json']['data']['expires_time'] ?? null) === $claims->exp,
        'independent Redis observes exact8 session fields, credential HMAC, token digest, account version and original expiry binding');
    td_check($accountPreserved, 'a previously initialized account retains its exact original HASH/version and permanent expiry');
    td_check(!isset($before['redis']['*'][$login['session']]) && (int)$b['login_count'] === (int)$a['login_count'] + 1
        && (int)$b['last_time'] >= $start && (int)$b['last_time'] <= $end && $b['last_ip'] === gethostbyname(gethostname()),
        'exact successful HTTP subject statistics match this request and new native session');
    foreach (['pwd', 'appsecret', 'credential_stamp', 'token_digest', 'stamp_key'] as $field) {
        td_check(!property_exists($claims, $field) && !property_exists($claims->jti, $field)
            && !array_key_exists($field, $login['response']['json']['data']['user_info'] ?? []), 'ordinary token/identity DTO omits private field ' . $field);
    }
    return ['rows' => ['eb_system_admin' => [$id => ['login_count' => (int)$a['login_count'] + 1,
        'last_time' => static fn($value): bool => (int)$value >= $start && (int)$value <= $end, 'last_ip' => gethostbyname(gethostname())]]],
        'keys' => $accountWasPresent ? [$login['session']] : [$login['session'], $login['account']]];
}
function td_tag(array $beforeMembers, array $added): bool
{
    $expected = array_values(array_unique(array_merge($beforeMembers, $added))); sort($expected, SORT_STRING);
    $actual = audit_redis()->sMembers($GLOBALS['td_tag']); sort($actual, SORT_STRING);
    return $actual === $expected && audit_redis()->ttl($GLOBALS['td_tag']) === -1;
}
function td_warm_cache_effects(array $before, array $after, int $start, int $end): array
{
    $keys = array_values(array_diff(array_keys($after['redis']['*']), array_keys($before['redis']['*']))); $allowed = []; $tagged = [];
    $redis = audit_redis(); $configService = $GLOBALS['td_app']->make(\app\services\system\config\SystemConfigServices::class);
    foreach ($keys as $key) {
        if ($key === $GLOBALS['td_tag']) { continue; }
        if (!str_starts_with($key, $GLOBALS['td_prefix'])) { continue; }
        $name = substr($key, strlen($GLOBALS['td_prefix'])); $known = false; $ttlValid = false;
        // Exact settings read by info/login, Request::filter_str and set_file_url.
        // This permits no arbitrary system_config suffix or producer namespace.
        $settings = ['queue_open', 'site_logo_square', 'site_logo', 'login_logo', 'site_name', 'nncnL_crmeb_copyright',
            'site_url', 'param_filter_type', 'param_filter_data', 'new_order_audio_link', 'model_checkbox'];
        if (str_starts_with($name, 'system_config_') && in_array(substr($name, strlen('system_config_')), $settings, true)) {
            $setting = substr($name, strlen('system_config_'));
            $expected = $configService->getConfigValue($setting); $raw = $redis->get($key);
            $serialized = is_numeric($expected) ? (string)$expected : serialize($expected);
            $known = is_string($raw) && $raw === $serialized;
            $ttlValid = ($after['expires'][$key] ?? -2) === -1;
        } elseif ($name === 'data_admin_login_slide') {
            $expected = $GLOBALS['td_app']->make(\app\services\system\config\SystemGroupDataServices::class)->getConfigNameValue('admin_login_slide', 0);
            $known = $redis->get($key) === serialize($expected);
            $ttlValid = ($after['expires'][$key] ?? -2) === -1;
        } elseif (in_array($name, ['sys_lang_source_map', 'type_id_zh-CN', 'lang_type_data', 'lang_zh_CN'], true)) {
            // Use real readonly framework services to obtain the exact DB maps;
            // this is observation, not a cache write or API replacement.
            $expected = match ($name) {
                'sys_lang_source_map' => $GLOBALS['td_app']->make(\app\services\system\lang\LangCodeServices::class)->getColumn(['type_id' => 1], 'code', 'remarks'),
                'type_id_zh-CN' => $GLOBALS['td_app']->make(\app\services\system\lang\LangCountryServices::class)->value(['code' => 'zh-CN'], 'type_id') ?: 1,
                'lang_type_data' => $GLOBALS['td_app']->make(\app\services\system\lang\LangTypeServices::class)->getColumn(['status' => 1, 'is_del' => 0], 'file_name', 'id'),
                default => $GLOBALS['td_app']->make(\app\services\system\lang\LangCodeServices::class)->getColumn(['type_id' => 1], 'lang_explain', 'code'),
            };
            $known = $redis->get($key) === (is_numeric($expected) ? (string)$expected : serialize($expected));
            $deadline = $after['expires'][$key] ?? -2;
            $ttlValid = $deadline >= ($start + 3600) * 1000 && $deadline <= ($end + 3600) * 1000 + 999;
        }
        if ($known) { td_check($ttlValid, 'this exact DB-derived preparation cache retains its source-defined native expiry'); }
        if ($known && $ttlValid) { $allowed[] = $key; $tagged[] = $key; $GLOBALS['td_owned_keys'][] = $key; }
    }
    if ($tagged) {
        td_check(td_tag($before['tag_members'], $tagged), 'normal cold-cache preparation adds only its individually verified DB-derived tag members');
        $allowed[] = $GLOBALS['td_tag']; $GLOBALS['td_owned_keys'][] = $GLOBALS['td_tag'];
    }
    return ['keys' => $allowed];
}
function td_register_file(string $relative): void
{
    audit_expect($relative !== '' && !str_contains($relative, '..') && $relative[0] !== '/' && !isset($GLOBALS['td_initial']['backup'][$relative]), 'only initial-absent owned backup paths may be registered');
    if (str_ends_with($relative, '/')) { $GLOBALS['td_owned_dirs'][] = rtrim($relative, '/'); }
    else { $GLOBALS['td_owned_files'][] = $relative; }
}
function td_crud_url(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || preg_match('~\A/adminapi/download/[a-f0-9]{32}\z~D', $parts['path'] ?? '') !== 1
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { return false; }
    // Empty installed site_url legitimately produces this exact root-relative
    // link. Protocol-relative URLs or arbitrary absolute origins are excluded.
    if ($url === $parts['path']) { return true; }
    $site = sys_config('site_url'); $configured = is_string($site) ? parse_url($site) : false;
    if (!is_array($configured) || !isset($configured['scheme'], $configured['host'], $parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || !in_array(strtolower($configured['scheme']), ['http', 'https'], true)
        || isset($configured['user']) || isset($configured['pass']) || isset($configured['query']) || isset($configured['fragment'])) { return false; }
    $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
    $expectedPort = $configured['port'] ?? (strtolower($configured['scheme']) === 'https' ? 443 : 80);
    return strtolower($parts['scheme']) === strtolower($configured['scheme']) && strcasecmp($parts['host'], $configured['host']) === 0 && $port === $expectedPort;
}
function td_time_header_preview(string $preview, string $generated, int $start, int $end): ?string
{
    $expectedLines = explode("\n", $preview); $actualLines = explode("\n", $generated);
    $pattern = '~\A( \* @date )([0-9]{4}/[0-9]{2}/[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})(\r?)\z~D';
    // Only line17 in the four known native stubs has TIME. The adjacent fixed
    // author/header lines also remain identical; no other date is substituted.
    if (!in_array($expectedLines[15] ?? null, [' * @author crud自动生成代码', " * @author crud自动生成代码\r"], true)
        || ($actualLines[15] ?? null) !== $expectedLines[15]
        || preg_match($pattern, $expectedLines[16] ?? '', $old) !== 1
        || preg_match($pattern, $actualLines[16] ?? '', $actual) !== 1) { return null; }
    $timezone = new DateTimeZone(date_default_timezone_get()); // initialized real App timezone
    $time = DateTimeImmutable::createFromFormat('!Y/m/d H:i:s', $actual[2], $timezone); $errors = DateTimeImmutable::getLastErrors();
    if (!$time || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $time->format('Y/m/d H:i:s') !== $actual[2] || $time->getTimestamp() < $start || $time->getTimestamp() > $end) { return null; }
    $expectedLines[16] = $old[1] . $actual[2] . $old[3];
    return implode("\n", $expectedLines);
}
function td_created_output(array $before, array $after, array $expectedFiles, int $start, int $end): array
{
    $allowed = []; $zipExpected = [];
    foreach ($expectedFiles as $relative => $content) {
        $generated = is_file($GLOBALS['td_backup'] . '/' . $relative) ? file_get_contents($GLOBALS['td_backup'] . '/' . $relative) : null;
        if (in_array($relative, $GLOBALS['td_timed_preview_paths'], true)) {
            $content = is_string($generated) ? td_time_header_preview($content, $generated, $start, $end) : null;
            td_check(is_string($content), 'the single known native TIME header has a valid producer-window timestamp in the actual App timezone');
        }
        if (!is_string($content)) { continue; }
        $expected = ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        $matches = !isset($before['backup'][$relative]) && is_string($generated) && $generated === $content && ($after['backup'][$relative] ?? null) === $expected;
        td_check($matches, 'actual generated file matches every preview byte with only its independently validated known TIME header');
        if ($matches) { $allowed[$relative] = $expected; $zipExpected[$relative] = $content; td_register_file($relative); }
        $parent = dirname($relative);
        while ($parent !== '.') {
            if (!isset($before['backup'][$parent . '/']) && ($after['backup'][$parent . '/'] ?? null) === ['directory' => true]) { $allowed[$parent . '/'] = ['directory' => true]; td_register_file($parent . '/'); }
            $parent = dirname($parent);
        }
    }
    return ['files' => $allowed, 'zip_expected' => $zipExpected];
}
function td_download_record(string $key, string $path, string $filename, array $before, array $after, int $start, int $end): array
{
    $physical = $GLOBALS['td_prefix'] . $key; $redis = audit_redis(); $expected = ['path' => $path, 'fileName' => $filename];
    $tagBefore = $GLOBALS['td_tag_before'] ?? [];
    td_check(!isset($before['redis']['*'][$physical]) && $redis->type($physical) === Redis::REDIS_STRING
        && $redis->get($physical) === serialize($expected), 'actual producer acknowledged a new exact serialized file record');
    $deadline = $after['expires'][$physical] ?? -2;
    td_check($deadline >= ($start + 300) * 1000 && $deadline <= ($end + 300) * 1000 + 999
        && td_tag($tagBefore, [$physical]), 'producer has original300-second deadline and only this actual tag member was added');
    if (!isset($GLOBALS['td_initial']['redis']['*'][$physical])) { $GLOBALS['td_owned_keys'][] = $physical; }
    return [$physical, $GLOBALS['td_tag']];
}
function td_first_file(array $response, string $key, string $path, string $filename, array $before, array $after): array
{
    $physical = $GLOBALS['td_prefix'] . $key;
    td_check($response['http'] === 200 && $response['json'] === null && $response['raw'] === file_get_contents($path)
        && (int)($response['headers']['content-length'] ?? -1) === filesize($path)
        && ($response['headers']['content-disposition'] ?? '') === 'attachment; filename="' . $filename . '"'
        && is_string($response['headers']['content-type'] ?? null), 'first authenticated binary HTTP response preserves exact actual file bytes/length/filename');
    td_check(isset($before['redis']['*'][$physical]) && !isset($after['redis']['*'][$physical])
        && ($before['redis']['*'][$GLOBALS['td_tag']] ?? null) === ($after['redis']['*'][$GLOBALS['td_tag']] ?? null),
        'normal first consumption deletes only its exact native file record and preserves the tag');
    return ['keys' => [$physical]];
}
function td_admin_log_effects(array $before, array $after, string $rule, int $start, int $end): array
{
    $a = $before['rows']['eb_system_log']; $b = $after['rows']['eb_system_log']; $added = array_diff_key($b, $a);
    td_check(count($added) === 1, 'this normal producer creates exactly one actual admin audit-log row');
    if (count($added) !== 1) { return []; }
    $id = (string)array_key_first($added); $row = $added[$id];
    $page = $GLOBALS['td_app']->make(\app\dao\system\SystemMenusDao::class)->getVisitName($rule) ?: '未知';
    $expected = ['id' => $id, 'admin_id' => $GLOBALS['td_subject']['id'], 'admin_name' => $GLOBALS['td_subject']['account'],
        'path' => $rule, 'page' => $page, 'method' => 'adminapi', 'ip' => gethostbyname(gethostname()), 'type' => 'system', 'merchant_id' => 0,
        'add_time' => static fn($value): bool => (int)$value >= $start && (int)$value <= $end];
    $actualFields = array_keys($row); $expectedFields = array_keys($expected); sort($actualFields); sort($expectedFields);
    $matches = preg_match('/^[1-9][0-9]*$/D', $id) === 1 && $actualFields === $expectedFields;
    foreach ($expected as $field => $value) { $matches = $matches && td_same($value, $row[$field] ?? null); }
    td_check($matches, 'new audit row exactly matches current subject/module/type/real route/menu DAO page/trusted IP/time and merchant0');
    if (!$matches) { return []; }
    $GLOBALS['td_owned_rows']['eb_system_log'][] = $id;
    return ['eb_system_log' => [$id => ['create' => $row]]];
}
function td_finish(): never
{
    $results = $GLOBALS['audit_results'] ?? []; $counts = array_count_values(array_column($results, 'status'));
    $phase = getenv('AUDIT_PHASE') ?: 'after';
    // Fixed filename plus unique execution, independent of throwable text or
    // credentials. Original harness rows are written without rewriting status.
    $ref = 'evidence/ms0b-login-download-functional-' . $GLOBALS['td_execution'] . '.jsonl';
    $recordingFailed = false; $stream = null;
    $failed = ($GLOBALS['td_prepare_failed'] ?? false) || ($GLOBALS['td_cleanup_failed'] ?? false)
        || ($counts['FAIL'] ?? 0) || ($counts['BLOCKED'] ?? 0) || !$results;
    try {
        $stream = fopen(audit_run_dir() . '/' . $ref, 'xb');
        audit_expect(is_resource($stream), 'unique ordinary raw result stream opens'); chmod(audit_run_dir() . '/' . $ref, 0600);
        foreach ($results as $row) {
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            audit_expect(fwrite($stream, $line) === strlen($line), 'complete original ordinary result row is recorded');
        }
        fclose($stream); $stream = null;
        audit_write_json(audit_run_dir() . '/evidence/task8-functional-' . $GLOBALS['td_execution'] . '-summary.json', [
            'run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'phase' => $phase,
            'selection' => $GLOBALS['td_only'], 'execution' => $GLOBALS['td_execution'], 'raw_jsonl' => $ref, 'counts' => $counts,
            'prepare_failed' => $GLOBALS['td_prepare_failed'] ?? false, 'cleanup_failed' => $GLOBALS['td_cleanup_failed'] ?? false,
            'cleanup_error' => $GLOBALS['td_cleanup_error'] ?? null, 'execution_status' => $failed ? 'FAILED' : 'COMPLETED',
            'exit_code' => $failed ? 1 : 0, 'skipped_coverage' => 'security/fault/concurrency/recovery and captcha completion not executed']);
    } catch (Throwable $error) {
        $recordingFailed = true; if (is_resource($stream)) { fclose($stream); }
        fwrite(STDERR, 'FAIL ordinary result recording: ' . td_safe_error($error) . "\n");
    }
    echo 'Results: ' . json_encode($counts, JSON_THROW_ON_ERROR) . "\n";
    echo 'Execution: ' . json_encode(['prepare_failed' => $GLOBALS['td_prepare_failed'] ?? false,
        'cleanup_failed' => $GLOBALS['td_cleanup_failed'] ?? false, 'recording_failed' => $recordingFailed], JSON_THROW_ON_ERROR) . "\n";
    exit($failed || $recordingFailed ? 1 : 0);
}

try {
    td_capture();
    td_window('fixture-new-rows', static function (): array {
        audit_expect(!audit_db()->query("SELECT id FROM eb_system_admin WHERE account='0'")->fetchColumn(), 'new account0 fixture must not replace any existing row');
        $password = bin2hex(random_bytes(12)); $GLOBALS['td_secrets'][] = $password;
        $normal = td_insert('eb_system_admin', ['account' => 'td8_' . substr($GLOBALS['td_execution'], -12), 'pwd' => password_hash($password, PASSWORD_BCRYPT),
            'real_name' => 'TD8_NORMAL', 'roles' => '101', 'level' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => time()]);
        $zeroPassword = bin2hex(random_bytes(12)); $GLOBALS['td_secrets'][] = $zeroPassword;
        $zero = td_insert('eb_system_admin', ['account' => '0', 'pwd' => password_hash($zeroPassword, PASSWORD_BCRYPT), 'real_name' => 'TD8_ZERO', 'roles' => '101', 'level' => 1, 'status' => 1, 'is_del' => 0, 'add_time' => time()]);
        $GLOBALS['td_subject'] = ['id' => (int)$normal['id'], 'account' => $normal['account'], 'password' => $password]; $GLOBALS['td_zero'] = $zero;
        return ['eb_system_admin' => [$normal, $zero]];
    }, static function ($before, $after, $rows): array { $rules = []; foreach ($rows ?? [] as $table => $items) { foreach ($items as $row) { $rules[$table][(string)$row['id']] = ['create' => $row]; } } return ['rows' => $rules]; });
    td_evidence('prepare-rows'); audit_expect(!in_array(false, array_column($GLOBALS['td_capture']['checks'], 'passed'), true), 'ordinary fixture preparation preserves all unrelated state');

    audit_app_request('start', ['CACHE_PREFIX' => $GLOBALS['td_prefix']]); $listening = false;
    for ($i = 0; $i < 80; $i++) { $socket = @fsockopen('audit-app', 8000, $errno, $message, 0.2); if (is_resource($socket)) { fclose($socket); $listening = true; break; } usleep(50000); }
    audit_expect($listening, 'normal dedicated HTTP application listens');
    td_capture();
    td_window('normal-info-cache-preparation', static fn(): array => td_http('GET', '/adminapi/login/info'),
        static fn($before, $after, $response, $start, $end): array => td_warm_cache_effects($before, $after, $start, $end));
    td_window('normal-login-cache-preparation', static fn(): array => td_login(), static function ($before, $after, $login, $start, $end): array {
        $rules = td_login_effects($before, $after, $login, $start, $end); $warm = td_warm_cache_effects($before, $after, $start, $end);
        $rules['keys'] = array_values(array_unique(array_merge($rules['keys'], $warm['keys']))); $GLOBALS['td_login'] = $login; return $rules;
    });
    td_evidence('prepare-normal-http');
    audit_expect(!in_array(false, array_column($GLOBALS['td_capture']['checks'], 'passed'), true), 'normal cache/login preparation succeeds and is recorded separately from cases');

    if ($GLOBALS['td_only'] !== 'q7') {
        td_case('T8F01', 'normal login info has numeric captcha0', static function (): void {
            $response = td_window('info-numeric', static fn(): array => td_http('GET', '/adminapi/login/info'));
            td_check(($response['http'] ?? 0) === 200 && ($response['json']['status'] ?? null) === 200
                && ($response['json']['data']['login_captcha'] ?? null) === 0, 'real info JSON exposes numeric0 without a global failure counter');
        });
        td_case('T8F02', 'normal valid HTTP admin login', static function (): void {
            $login = td_window('valid-login', static fn(): array => td_login(), static fn($a, $b, $r, $s, $e): array => td_login_effects($a, $b, $r, $s, $e));
            if (is_array($login)) { $GLOBALS['td_login'] = $login; }
        });
        td_case('T8F03', 'normal account0 DAO identity query', static function (): void {
            $actual = td_window('account0-readonly', static function (): ?array {
                $model = $GLOBALS['td_app']->make(\app\dao\system\admin\SystemAdminDao::class)->accountByAdmin('0');
                return $model ? $model->getData() : null;
            });
            td_check(is_array($actual) && array_keys($actual) === array_keys($GLOBALS['td_zero'])
                && !array_diff_assoc($actual, $GLOBALS['td_zero']) && !array_diff_assoc($GLOBALS['td_zero'], $actual), 'actual DAO returns every column of exactly the synthetic account0 row');
        });
        td_case('T8F04', 'normal existing captcha creation and display data', static function (): void {
            $response = td_window('captcha-create-only', static fn(): array => td_http('GET', '/adminapi/ajcaptcha?captchaType=blockPuzzle'),
                static function ($before, $after, $response, $start, $end): array {
                    $data = $response['json']['data'] ?? []; $token = $data['token'] ?? null; $secret = $data['secretKey'] ?? null; $keys = [];
                    td_check($response['http'] === 200 && ($response['json']['status'] ?? null) === 200 && is_string($token) && is_string($secret) && strlen($secret) === 16,
                        'real normal captcha publishes its own new challenge and display encryption key');
                    foreach (['originalImageBase64', 'jigsawImageBase64'] as $field) {
                        $bytes = is_string($data[$field] ?? null) ? base64_decode($data[$field], true) : false;
                        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
                        td_check(is_array($info) && $info[0] > 0 && $info[1] > 0, 'real captcha image is decodable display data: ' . $field);
                    }
                    if (is_string($token) && is_string($secret)) {
                        $GLOBALS['td_secrets'][] = $token; $GLOBALS['td_secrets'][] = $secret; $key = $GLOBALS['td_prefix'] . $token;
                        // Only presence/type/expiry are observed. No server-side
                        // challenge payload or answer is read or used to solve.
                        td_check(!isset($before['redis']['*'][$key]) && audit_redis()->type($key) === Redis::REDIS_STRING
                            && ($after['expires'][$key] ?? -2) >= ($start + 7200) * 1000 && ($after['expires'][$key] ?? -2) <= ($end + 7200) * 1000 + 999,
                            'independent actual cache confirms only this new unsolved7200-second challenge');
                        $keys[] = $key; $GLOBALS['td_owned_keys'][] = $key;
                    }
                    foreach (array_diff(array_keys($after['redis']['*']), array_keys($before['redis']['*'])) as $key) {
                        if (!str_starts_with($key, $GLOBALS['td_prefix'] . 'image_pixel_map_')) { continue; }
                        $source = substr($key, strlen($GLOBALS['td_prefix'] . 'image_pixel_map_'));
                        $permittedRoots = [audit_repo_dir() . '/crmeb/public/statics/images/', audit_repo_dir() . '/crmeb/vendor/fastknife/ajcaptcha/'];
                        $inside = false; foreach ($permittedRoots as $root) { $inside = $inside || (str_starts_with($source, $root) && !str_contains($source, '..')); }
                        $matches = false;
                        if ($inside && is_file($source)) {
                            \Intervention\Image\ImageManagerStatic::configure(['driver' => 'gd']);
                            $image = str_starts_with($source, audit_repo_dir() . '/crmeb/public/statics/images/')
                                ? new \Fastknife\Domain\Vo\BackgroundVo($source)
                                : new \Fastknife\Domain\Vo\TemplateVo($source);
                            $image->preparePickMaps();
                            // Read only the original image asset pixels. The VO
                            // has no cache callback and no challenge/answer.
                            $matches = audit_redis()->get($key) === serialize($image->getPickMaps()); unset($image);
                        }
                        td_check($matches && ($after['expires'][$key] ?? -2) === -1,
                            'specific normal pixel cache exactly matches the real source asset pixels and persistent expiry');
                        if ($matches) { $keys[] = $key; $GLOBALS['td_owned_keys'][] = $key; }
                    }
                    return ['keys' => $keys];
                });
            td_check(is_array($response), 'captcha creation is observed without check, login verification, server-answer extraction or automatic solution');
        });
    }

    if ($GLOBALS['td_only'] !== 'q6') {
        td_capture();
        td_window('download-fixture-metadata', static function (): array {
            $list = td_insert('eb_system_crud_list', ['name' => 'TD8_' . $GLOBALS['td_execution'], 'mark' => 'td8_' . $GLOBALS['td_execution'], 'level' => 0, 'status' => 1, 'add_time' => time()]);
            $map = ['model' => 'app/model/crud/Task8.php', 'dao' => 'app/dao/crud/Task8Dao.php', 'service' => 'app/services/crud/Task8Services.php',
                'validate' => 'app/adminapi/validate/crud/Task8.php', 'controller' => 'app/adminapi/controller/crud/Task8.php', 'route' => 'app/adminapi/route/crud/task8.php',
                'api' => 'api/crud/task8.js', 'router' => 'router/modules/crud/task8.js', 'pages' => 'pages/crud/task8/index.vue'];
            $fields = ['tableField' => [], 'fromField' => [], 'columnField' => [], 'searchField' => [], 'hasOneField' => []];
            $crud = td_insert('eb_system_crud', ['name' => 'TD8_' . $GLOBALS['td_execution'], 'table_name' => 'system_crud_list', 'field' => json_encode($fields, JSON_THROW_ON_ERROR),
                'make_path' => json_encode($map, JSON_THROW_ON_ERROR), 'table_collation' => 'utf8mb4_general_ci', 'table_comment' => 'TD8 normal export', 'add_time' => time()]);
            $GLOBALS['td_crud_id'] = (int)$crud['id']; $GLOBALS['td_crud_map'] = $map; return ['eb_system_crud' => [$crud], 'eb_system_crud_list' => [$list]];
        }, static function ($before, $after, $rows): array { $rules = []; foreach ($rows ?? [] as $table => $items) { foreach ($items as $row) { $rules[$table][(string)$row['id']] = ['create' => $row]; } } return ['rows' => $rules]; });
        $base = $GLOBALS['td_backup'] . '/systemCrudList'; $map = [];
        foreach ($GLOBALS['td_crud_map'] as $key => $path) {
            $map[$key] = in_array($key, ['pages', 'router', 'api'], true) ? $base . '/template/admin/src/' . $path : $base . '/crmeb/' . $path;
        }
        $preview = $GLOBALS['td_app']->make(\app\services\system\SystemCrudServices::class)->makeFile('system_crud_list', 'crud/system_crud_list', false,
            ['menuName' => 'TD8_' . $GLOBALS['td_execution'], 'key' => 'id', 'softDelete' => false, 'fromField' => [], 'columnField' => [], 'tableField' => [], 'searchField' => [], 'hasOneField' => []], $map, $base);
        $GLOBALS['td_preview'] = []; $GLOBALS['td_timed_preview_paths'] = [];
        foreach ($preview as $key => $file) {
            $expectedPath = $map[$key];
            // Services.replace strips the app root and frontend-source root;
            // absolute path identity is proven using the actual mapped preview.
            $expectedRelative = str_replace([$GLOBALS['td_app']->getRootPath(), \crmeb\services\crud\Make::adminTemplatePath()], '', $expectedPath);
            audit_expect($file['path'] === $expectedRelative && str_starts_with($expectedPath, $base . '/') && !str_contains($expectedPath, '..'), 'all nine preview outputs must stay in this dedicated backup tree');
            $relative = substr($expectedPath, strlen($GLOBALS['td_backup'] . '/'));
            $GLOBALS['td_preview'][$relative] = $file['content'];
            if (in_array($key, ['model', 'dao', 'service', 'validate'], true)) { $GLOBALS['td_timed_preview_paths'][] = $relative; }
        }
        audit_expect(count($GLOBALS['td_preview']) === 9 && !file_exists($base) && !file_exists($base . '.zip'), 'native readonly preview creates nothing and all nine dedicated outputs are initially absent');
        td_evidence('prepare-download-metadata'); audit_expect(!in_array(false, array_column($GLOBALS['td_capture']['checks'], 'passed'), true), 'download DML metadata preparation is scoped');
        td_capture();
        td_window('normal-existing-backup-file-preparation', static function (): void {
            $GLOBALS['td_backup_time'] = time(); $name = date('Ymd-His', $GLOBALS['td_backup_time']); $relative = $name . '-1.sql.gz';
            audit_expect(!file_exists($GLOBALS['td_backup'] . '/' . $relative), 'new normal backup filename must be absent');
            $GLOBALS['td_backup_file'] = $GLOBALS['td_backup'] . '/' . $relative; td_register_file($relative);
            $backup = new \crmeb\services\MysqlBackupService(['compress' => 1, 'level' => 5]); $backup->setFile(['name' => $name, 'part' => 1]);
            audit_expect($backup->backup('eb_system_crud_list', 0) === 0, 'existing normal backup serializes its actual existing table'); unset($backup);
        }, static function ($before, $after): array {
            $relative = basename($GLOBALS['td_backup_file']); $bytes = gzdecode(file_get_contents($GLOBALS['td_backup_file']));
            td_check(is_string($bytes) && str_contains($bytes, 'CREATE TABLE `eb_system_crud_list`') && str_contains($bytes, 'TD8_' . $GLOBALS['td_execution']),
                'actual normal gz backup contains the real table schema and this exact synthetic row; SQL text is never executed');
            return ['files' => [$relative => $after['backup'][$relative] ?? []]];
        });
        td_evidence('prepare-backup-file'); audit_expect(!in_array(false, array_column($GLOBALS['td_capture']['checks'], 'passed'), true), 'normal backup-file preparation succeeds');

        td_case('T8F05', 'real CRUD producer and first authenticated route download', static function (): void {
            $token = $GLOBALS['td_login']['token']; $GLOBALS['td_tag_before'] = audit_redis()->sMembers($GLOBALS['td_tag']);
            $producer = td_window('crud-real-producer', static fn(): array => td_http('GET', '/adminapi/system/crud/download/' . $GLOBALS['td_crud_id'], $token),
                static function ($before, $after, $response, $start, $end): array {
                    $logRows = td_admin_log_effects($before, $after, 'system/crud/download/<id>', $start, $end);
                    $url = $response['json']['data']['download_url'] ?? null; $parts = is_string($url) ? parse_url($url) : false; $key = is_array($parts) ? basename($parts['path'] ?? '') : '';
                    td_check($response['http'] === 200 && ($response['json']['status'] ?? null) === 200 && is_string($url) && td_crud_url($url),
                        'actual CRUD returns an exact root-relative or configured same-origin file route with a32lowerhex key');
                    audit_expect(is_string($url) && preg_match('/^[a-f0-9]{32}$/D', $key) === 1, 'real CRUD file key is available for its first normal use');
                    $GLOBALS['td_route_key'] = $key; $zipPath = $GLOBALS['td_backup'] . '/systemCrudList.zip';
                    // Record new randomness contract failure without blocking the
                    // genuine old producer's ordinary first download baseline.
                    td_check($key !== md5($zipPath), 'normal producer file key no longer equals the original deterministic path digest');
                    $generated = td_created_output($before, $after, $GLOBALS['td_preview'], $start, $end); $files = $generated['files']; $zip = new ZipArchive();
                    audit_expect($zip->open($zipPath) === true, 'real producer archive is readable'); $seen = []; $fileEntries = 0; $duplicateEntry = false;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i); if (str_ends_with($name, '/')) { continue; }
                        $fileEntries++;
                        if (array_key_exists($name, $seen)) { $duplicateEntry = true; continue; }
                        $seen[$name] = $zip->getFromIndex($i);
                    }
                    $zip->close(); ksort($seen); $expected = $generated['zip_expected']; ksort($expected);
                    $exactZip = $fileEntries === 9 && !$duplicateEntry && count($expected) === 9 && $seen === $expected;
                    td_check($exactZip, 'real ZIP contains exactly nine non-directory entries with unique names and the complete bytes of the same nine verified generated files');
                    $relative = basename($zipPath); if ($exactZip && isset($after['backup'][$relative]) && !isset($before['backup'][$relative])) { $files[$relative] = $after['backup'][$relative]; td_register_file($relative); }
                    $keys = td_download_record($key, $zipPath, 'systemCrudList.zip', $before, $after, $start, $end);
                    return ['rows' => $logRows, 'keys' => $keys, 'files' => $files];
                });
            audit_expect(isset($GLOBALS['td_route_key']), 'successful genuine producer precedes first normal file use');
            td_window('crud-first-header-consumption', static fn(): array => td_http('GET', '/adminapi/download/' . $GLOBALS['td_route_key'], $token),
                static fn($before, $after, $response): array => td_first_file($response, $GLOBALS['td_route_key'], $GLOBALS['td_backup'] . '/systemCrudList.zip', 'systemCrudList.zip', $before, $after));
        });
        td_case('T8F06', 'real backup producer and first authenticated encoded query download', static function (): void {
            $token = $GLOBALS['td_login']['token']; $GLOBALS['td_tag_before'] = audit_redis()->sMembers($GLOBALS['td_tag']);
            td_window('backup-real-producer', static fn(): array => td_http('GET', '/adminapi/backup/download?time=' . $GLOBALS['td_backup_time'], $token),
                static function ($before, $after, $response, $start, $end): array {
                    $logRows = td_admin_log_effects($before, $after, 'backup/download', $start, $end);
                    $key = $response['json']['data']['key'] ?? null;
                    td_check($response['http'] === 200 && ($response['json']['status'] ?? null) === 200 && is_string($key)
                        && preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/D', $key) === 1, 'real backup producer returns its actual native bcrypt file key');
                    audit_expect(is_string($key) && $key !== '', 'successful real backup producer precedes ordinary first use'); $GLOBALS['td_query_key'] = $key;
                    return ['rows' => $logRows, 'keys' => td_download_record($key, $GLOBALS['td_backup_file'], basename($GLOBALS['td_backup_file']), $before, $after, $start, $end)];
                });
            audit_expect(isset($GLOBALS['td_query_key']), 'actual normal backup key is available');
            td_window('backup-first-encoded-query-consumption', static fn(): array => td_http('GET', '/adminapi/download?' . http_build_query(['key' => $GLOBALS['td_query_key']], '', '&', PHP_QUERY_RFC3986), $token),
                static fn($before, $after, $response): array => td_first_file($response, $GLOBALS['td_query_key'], $GLOBALS['td_backup_file'], basename($GLOBALS['td_backup_file']), $before, $after));
        });
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ordinary preparation: ' . td_safe_error($error) . "\n");
    $GLOBALS['td_prepare_failed'] = true;
} finally {
    try {
      try { audit_app_request('stop'); } finally {
        audit_guard_assert(); td_capture(); $before = td_snapshot();
        foreach ($GLOBALS['td_owned_rows'] as $table => $ids) {
            $statement = audit_db()->prepare('DELETE FROM `' . $table . '` WHERE id=?');
            foreach ($ids as $id) { $statement->execute([$id]); }
        }
        $keys = array_values(array_unique($GLOBALS['td_owned_keys'])); if ($keys) { audit_redis()->del($keys); }
        foreach (array_unique($GLOBALS['td_owned_files']) as $relative) {
            $path = $GLOBALS['td_backup'] . '/' . $relative;
            if (is_file($path)) { audit_expect(!$relative || (!str_contains($relative, '..') && !is_link($path) && str_starts_with(realpath($path), realpath($GLOBALS['td_backup']) . '/')), 'exact owned cleanup file must remain inside dedicated backup'); unlink($path); }
        }
        $directories = array_unique($GLOBALS['td_owned_dirs']); usort($directories, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($directories as $relative) { $path = $GLOBALS['td_backup'] . '/' . $relative; if (is_dir($path)) { audit_expect(!is_link($path) && str_starts_with(realpath($path), realpath($GLOBALS['td_backup']) . '/'), 'exact owned cleanup directory remains inside backup'); rmdir($path); } }
        $after = td_snapshot(); $initial = $GLOBALS['td_initial'];
        $restored = $after['tables'] === $initial['tables'] && $after['redis']['*'] === $initial['redis']['*'] && $after['runtime'] === $initial['runtime'] && $after['backup'] === $initial['backup'];
        foreach ($initial['expires'] as $key => $deadline) { $actual = $after['expires'][$key] ?? -2; $restored = $restored && (($actual < 0 || $deadline < 0) ? $actual === $deadline : abs($actual - $deadline) <= 1); }
        $cleanupDiff = ['tables' => [], 'redis' => [], 'runtime' => [], 'backup' => []];
        foreach ($initial['tables'] as $table => $value) {
            if ($value !== ($after['tables'][$table] ?? null)) { $cleanupDiff['tables'][] = $table; }
        }
        foreach (array_unique(array_merge(array_keys($initial['redis']['*']), array_keys($after['redis']['*']))) as $key) {
            $a = $initial['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
            if (($initial['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $cleanupDiff['redis'][] = hash('sha256', $key) . ':value'; }
            if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $cleanupDiff['redis'][] = hash('sha256', $key) . ':expiry'; }
        }
        foreach ($initial['runtime'] as $index => $files) {
            foreach (array_unique(array_merge(array_keys($files), array_keys($after['runtime'][$index])) ) as $path) {
                if (($files[$path] ?? null) !== ($after['runtime'][$index][$path] ?? null)) { $cleanupDiff['runtime'][] = hash('sha256', $index . ':' . $path); }
            }
        }
        foreach (array_unique(array_merge(array_keys($initial['backup']), array_keys($after['backup']))) as $path) {
            if (($initial['backup'][$path] ?? null) !== ($after['backup'][$path] ?? null)) { $cleanupDiff['backup'][] = hash('sha256', $path); }
        }
        audit_write_json(audit_run_dir() . '/evidence/task8-functional-' . $GLOBALS['td_execution'] . '-cleanup.json', ['run_id' => getenv('AUDIT_RUN_ID'),
            'exact_created_ids' => $GLOBALS['td_owned_rows'], 'exact_owned_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $keys),
            'owned_files' => count(array_unique($GLOBALS['td_owned_files'])), 'original_snapshot_restored' => $restored,
            'runtime_unchanged_during_cleanup' => $before['runtime'] === $after['runtime'], 'private_log_check' => td_logs_private(), 'actual_differences' => $cleanupDiff]);
        audit_expect($restored && $before['runtime'] === $after['runtime'] && td_logs_private(), 'exact cleanup restores original rows/cache/absolute deadlines/runtime and file tree');
      }
    } catch (Throwable $error) {
        $GLOBALS['td_cleanup_failed'] = true; $GLOBALS['td_cleanup_error'] = td_safe_error($error);
        fwrite(STDERR, 'FAIL ordinary cleanup: ' . $GLOBALS['td_cleanup_error'] . "\n");
    }
}
td_finish();
