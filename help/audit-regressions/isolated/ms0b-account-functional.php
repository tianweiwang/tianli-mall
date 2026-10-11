<?php
declare(strict_types=1);

/* Task6 normal functional draft. Install beside lib/harness.php after review.
 * CLI-SERVICE only: actual application, services, DAO, PDO, and Redis.
 * Only new, explicitly tracked synthetic rows in a fresh ISO-FULL RUN.
 * No HTTP request, wrong/old token, negative credential, mock, fault, concurrent
 * actor, disconnect, restore experiment, or stopped aggregate suite is used.
 * Unexecuted by its preparer; a genuine old-writer baseline may normally FAIL
 * the new version-rotation contract while retaining successful business checks.
 */
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');
$GLOBALS['cf_config'] = audit_config();
$GLOBALS['cf_auth'] = $GLOBALS['cf_config']['auth'] ?? [];
audit_expect(($GLOBALS['cf_auth']['session_epoch'] ?? '') === 'audit-http-' . getenv('AUDIT_RUN_ID')
    && is_string($GLOBALS['cf_auth']['stamp_key'] ?? null) && strlen($GLOBALS['cf_auth']['stamp_key']) >= 32,
    'new normal functional RUN has its actual private v2 configuration');
audit_expect(is_dir('/run/audit-app-runtime'), 'actual read-only app-runtime observation mount is required');
$metadata = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
audit_expect(is_int($metadata['_permissions']['role'] ?? null) && is_int($metadata['_permissions']['out_route'] ?? null),
    'existing bootstrap role and external route identities are available');
$GLOBALS['cf_role'] = $metadata['_permissions']['role'];
$GLOBALS['cf_out_route'] = $metadata['_permissions']['out_route'];
unset($metadata);
$GLOBALS['cf_slug'] = 'caf_' . substr(hash('sha256', (string)getenv('AUDIT_RUN_ID')), 0, 8) . '_';
$GLOBALS['cf_counter'] = 0;
$GLOBALS['cf_created'] = ['eb_system_admin' => [], 'eb_out_account' => [], 'eb_user' => []];
$GLOBALS['cf_owned_keys'] = [];
$GLOBALS['cf_secrets'] = [$GLOBALS['cf_config']['signing_key'], $GLOBALS['cf_auth']['stamp_key'],
    $GLOBALS['cf_config']['redis_password'], $GLOBALS['cf_config']['database']['password']];
$GLOBALS['cf_prefix'] = 'audit:account-functional:' . getenv('AUDIT_RUN_ID') . ':';
$GLOBALS['cf_tag_prefix'] = 'account-functional-tag:' . getenv('AUDIT_RUN_ID') . ':';
$GLOBALS['cf_v2_prefix'] = 'auth:v2:' . $GLOBALS['cf_auth']['session_epoch'] . ':';
$GLOBALS['cf_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
$GLOBALS['cf_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'full observation table name is safe');
    $GLOBALS['cf_spec']['tables'][$table] = null;
}
foreach ([$GLOBALS['cf_prefix'], $GLOBALS['cf_v2_prefix']] as $prefix) {
    audit_expect(cf_scan($prefix . '*') === [], 'fresh RUN functional namespaces are empty before any preparation');
}
$GLOBALS['cf_initial_snapshot'] = cf_snapshot();
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
$app = new \think\App(audit_repo_dir() . '/crmeb/');
$app->env->set([
    'APP_DEBUG' => false, 'APP_APP_KEY' => $GLOBALS['cf_config']['signing_key'],
    'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
    'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
    'DATABASE_PASSWORD' => $GLOBALS['cf_config']['database']['password'],
    'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => false,
    'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
    'REDIS_REDIS_PASSWORD' => $GLOBALS['cf_config']['redis_password'],
    'CACHE_CACHE_PREFIX' => $GLOBALS['cf_prefix'], 'CACHE_CACHE_TAG_PREFIX' => $GLOBALS['cf_tag_prefix'],
    'AUTH_SESSION_EPOCH' => $GLOBALS['cf_auth']['session_epoch'],
    'AUTH_STAMP_KEY' => $GLOBALS['cf_auth']['stamp_key'], 'AUTH_ISSUER' => $GLOBALS['cf_auth']['issuer'] ?? 'tianli-mall',
]);
$app->initialize();
$GLOBALS['cf_app'] = $app;
$GLOBALS['cf_initial_runtime'] = cf_runtime();
$GLOBALS['cf_services'] = [
    'adminapi' => $app->make(\app\services\system\admin\SystemAdminServices::class),
    'outapi' => $app->make(\app\services\out\OutAccountServices::class),
    'division' => $app->make(\app\services\agent\DivisionServices::class),
];

function cf_scan(string $pattern): array
{
    $redis = audit_redis(); $cursor = null; $keys = [];
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING); return array_values(array_unique($keys));
}
function cf_check(bool $passed, string $description): void
{
    $GLOBALS['cf_capture']['checks'][] = ['passed' => $passed, 'description' => $description];
}
function cf_password(): string
{
    $value = 'A1' . bin2hex(random_bytes(12)); $GLOBALS['cf_secrets'][] = $value; return $value;
}
function cf_name(): string { return $GLOBALS['cf_slug'] . ++$GLOBALS['cf_counter']; }
function cf_pk(string $table): string
{
    audit_expect(in_array($table, ['eb_system_admin', 'eb_out_account', 'eb_user'], true), 'only three exact fixture tables are accepted');
    return $table === 'eb_user' ? 'uid' : 'id';
}
function cf_schema(string $table): array
{
    cf_pk($table); $columns = [];
    foreach (audit_db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() as $row) { $columns[$row['Field']] = $row; }
    return $columns;
}
function cf_defaults(string $table): array
{
    $defaults = [];
    foreach (cf_schema($table) as $column => $row) {
        if ($row['Default'] !== null) { $defaults[$column] = $row['Default']; }
        elseif ($row['Null'] === 'YES') { $defaults[$column] = null; }
    }
    return $defaults;
}
function cf_register(string $table, int $id): array
{
    $pk = cf_pk($table); $statement = audit_db()->prepare('SELECT * FROM `' . $table . '` WHERE `' . $pk . '`=?');
    $statement->execute([$id]); $row = $statement->fetch(); $nameField = $table === 'eb_out_account' ? 'appid' : 'account';
    audit_expect($id > 0 && is_array($row) && str_starts_with((string)$row[$nameField], $GLOBALS['cf_slug']),
        'created identity belongs to this exact synthetic RUN namespace');
    $GLOBALS['cf_created'][$table][(string)$id] = $nameField;
    foreach (['pwd', 'appsecret', 'apppwd', 'push_password', 'file_pwd'] as $field) {
        if (is_string($row[$field] ?? null) && $row[$field] !== '') { $GLOBALS['cf_secrets'][] = $row[$field]; }
    }
    return $row;
}
function cf_insert(string $table, array $data): int
{
    audit_guard_assert(); $schema = cf_schema($table); $pk = cf_pk($table);
    audit_expect(!array_key_exists($pk, $data) && array_diff(array_keys($data), array_keys($schema)) === [],
        'fixture DML uses actual columns and leaves identity generation to this isolated database');
    foreach ($schema as $column => $definition) {
        if ($column === $pk || array_key_exists($column, $data) || $definition['Default'] !== null || $definition['Null'] === 'YES') { continue; }
        $data[$column] = preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/', $definition['Type']) === 1 ? 0 : '';
    }
    $columns = array_keys($data); $pdo = audit_db();
    $statement = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    $statement->execute(array_values($data)); $id = (int)$pdo->lastInsertId(); cf_register($table, $id); return $id;
}
function cf_subject(string $app, array $extra = []): array
{
    audit_expect(in_array($app, ['adminapi', 'outapi'], true), 'synthetic subject application is fixed');
    $password = cf_password(); $name = cf_name(); $hash = password_hash($password, PASSWORD_BCRYPT); $GLOBALS['cf_secrets'][] = $hash;
    $table = $app === 'adminapi' ? 'eb_system_admin' : 'eb_out_account';
    $data = $app === 'adminapi'
        ? ['account' => $name, 'pwd' => $hash, 'roles' => (string)$GLOBALS['cf_role'], 'level' => 1, 'status' => 1, 'is_del' => 0, 'real_name' => 'CF_ADMIN']
        : ['appid' => $name, 'appsecret' => $hash, 'apppwd' => $password, 'rules' => (string)$GLOBALS['cf_out_route'], 'status' => 1, 'is_del' => 0,
            'title' => 'CF_OUT', 'push_open' => 0, 'push_account' => '', 'push_password' => '', 'push_token_url' => '', 'user_update_push' => ''];
    $id = cf_insert($table, array_replace($data, $extra));
    $subject = ['app' => $app, 'id' => $id, 'table' => $table, 'account' => $name, 'password' => $password];
    cf_login($subject); cf_login($subject); // Two genuine sessions; never synthesize an authorization HASH.
    return $subject;
}
function cf_user(array $extra = []): int
{
    $password = cf_password(); $hash = md5($password); $GLOBALS['cf_secrets'][] = $hash;
    return cf_insert('eb_user', array_replace(['account' => cf_name(), 'pwd' => $hash, 'nickname' => 'CF_USER', 'status' => 1,
        'is_del' => 0, 'user_type' => 'h5', 'add_time' => time(), 'last_time' => time(), 'division_invite' => 13572468], $extra));
}
function cf_login(array $subject): array
{
    $service = $GLOBALS['cf_services'][$subject['app']];
    $issued = $subject['app'] === 'adminapi' ? $service->login($subject['account'], $subject['password'], 'admin')
        : $service->authLogin($subject['account'], $subject['password']);
    $token = is_array($issued) ? ($issued[$subject['app'] === 'adminapi' ? 'token' : 'access_token'] ?? null) : null;
    audit_expect(is_string($token) && $token !== '', 'valid new synthetic credentials issue a genuine current v2 session');
    $GLOBALS['cf_secrets'][] = $token;
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60;
        $claims = \Firebase\JWT\JWT::decode($token, $GLOBALS['cf_config']['signing_key'], ['HS256']);
    } finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
    audit_expect(($claims->auth_schema ?? null) === 2 && ($claims->session_epoch ?? '') === $GLOBALS['cf_auth']['session_epoch']
        && (string)($claims->jti->id ?? '') === (string)$subject['id'] && is_string($claims->sid ?? null)
        && preg_match('/^[a-f0-9]{32}$/D', $claims->sid) === 1, 'preparation uses the actual current issued subject, epoch and sid');
    $account = cf_account_key($subject); $session = $GLOBALS['cf_v2_prefix'] . $subject['app'] . ':session:' . $claims->sid;
    $GLOBALS['cf_owned_keys'][] = $account; $GLOBALS['cf_owned_keys'][] = $session;
    $issue = ['token' => $token, 'response' => $issued, 'app' => $subject['app'], 'id' => $subject['id'],
        'account_key' => $account, 'session_key' => $session];
    audit_expect(cf_issue_binding($issue, $subject), 'independent Redis confirms the complete current signature/claims/credential/version/expiry binding');
    return $issue;
}
function cf_issue_binding(array $issue, array $subject): bool
{
    if (($issue['app'] ?? null) !== $subject['app'] || ($issue['id'] ?? null) !== $subject['id']
        || !is_string($issue['token'] ?? null) || !is_array($issue['response'] ?? null)) { return false; }
    $timestamp = \Firebase\JWT\JWT::$timestamp; $leeway = \Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp = null; \Firebase\JWT\JWT::$leeway = 60;
        $claims = \Firebase\JWT\JWT::decode($issue['token'], $GLOBALS['cf_config']['signing_key'], ['HS256']);
    } finally { \Firebase\JWT\JWT::$timestamp = $timestamp; \Firebase\JWT\JWT::$leeway = $leeway; }
    $config = \crmeb\services\AuthConfigGuard::authConfig(); $binding = $config['apps'][$subject['app']];
    if (($claims->auth_schema ?? null) !== 2 || ($claims->session_epoch ?? '') !== $GLOBALS['cf_auth']['session_epoch']
        || ($claims->iss ?? '') !== $config['issuer'] || ($claims->aud ?? '') !== $binding['audience']
        || ($claims->jti->type ?? '') !== $binding['type'] || ($claims->jti->id ?? null) !== (string)$subject['id']
        || !is_string($claims->sid ?? null) || preg_match('/^[a-f0-9]{32}$/D', $claims->sid) !== 1
        || !is_string($claims->session_version ?? null) || preg_match('/^[a-f0-9]{32}$/D', $claims->session_version) !== 1) { return false; }
    foreach (['iat', 'nbf', 'exp'] as $field) { if (!is_int($claims->$field ?? null) || $claims->$field <= 0) { return false; } }
    if ($claims->nbf !== $claims->iat || $claims->exp - $claims->iat !== $binding['ttl']) { return false; }
    foreach (['pwd', 'appsecret', 'apppwd', 'push_password', 'credential_stamp', 'token_digest', 'stamp_key'] as $field) {
        if (property_exists($claims, $field) || property_exists($claims->jti, $field)) { return false; }
    }
    $key = $GLOBALS['cf_v2_prefix'] . $subject['app'] . ':session:' . $claims->sid; $accountKey = cf_account_key($subject);
    if (($issue['session_key'] ?? null) !== $key || ($issue['account_key'] ?? null) !== $accountKey) { return false; }
    $row = cf_rows($subject['table'])[(string)$subject['id']] ?? null;
    if (!is_array($row)) { return false; }
    $digest = $row[$subject['app'] === 'adminapi' ? 'pwd' : 'appsecret'];
    $redis = audit_redis();
    if ($redis->type($key) !== Redis::REDIS_HASH || $redis->type($accountKey) !== Redis::REDIS_HASH) { return false; }
    $record = $redis->hGetAll($key); $account = $redis->hGetAll($accountKey);
    $expected = ['subject_id' => (string)$subject['id'], 'type' => $binding['type'], 'audience' => $binding['audience'],
        'session_version' => $claims->session_version, 'token_digest' => hash('sha256', $issue['token']),
        'credential_stamp' => hash_hmac('sha256', $subject['app'] . '|' . $subject['id'] . '|' . $digest, $GLOBALS['cf_auth']['stamp_key']),
        'expires_at' => (string)$claims->exp, 'status' => 'active'];
    ksort($record); ksort($expected);
    $expiryField = $subject['app'] === 'adminapi' ? 'expires_time' : 'exp_time';
    $deadline = cf_deadlines([$key])[$key];
    return $record === $expected && ($account['session_version'] ?? '') === $claims->session_version
        && ($account['initialized'] ?? '') === '1' && ($account['blocked'] ?? '') === '0' && $redis->ttl($accountKey) === -1
        && ($issue['response'][$expiryField] ?? null) === $claims->exp && $deadline > 0
        && abs($deadline - ($claims->exp + 60) * 1000) <= 1;
}
function cf_account_key(array $subject): string { return $GLOBALS['cf_v2_prefix'] . $subject['app'] . ':account:' . $subject['id']; }
function cf_rows(string $table): array
{
    $rows = []; $pk = cf_pk($table);
    foreach (audit_db()->query('SELECT * FROM `' . $table . '` ORDER BY `' . $pk . '`')->fetchAll() as $row) { $rows[(string)$row[$pk]] = $row; }
    return $rows;
}
function cf_target_rows(): array
{
    $rows = []; foreach (['eb_system_admin', 'eb_out_account', 'eb_user'] as $table) { $rows[$table] = cf_rows($table); } return $rows;
}
function cf_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['cf_spec']); $snapshot['expires'] = []; $keys = array_keys($snapshot['redis']['*']);
    if ($keys === []) { return $snapshot; }
    $snapshot['expires'] = cf_deadlines($keys);
    return $snapshot;
}
function cf_deadlines(array $keys): array
{
    audit_expect($keys !== [], 'native deadline observation has explicit keys');
    $script = "local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local x=redis.call('PTTL',k); if x>=0 then r[i]=n+x else r[i]=x end end; return r";
    $deadlines = audit_redis()->eval($script, $keys, count($keys));
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'all actual Redis values have independently observed absolute deadlines');
    $result = [];
    foreach ($keys as $index => $key) { audit_expect(is_int($deadlines[$index]), 'native Redis deadline is integral'); $result[$key] = $deadlines[$index]; }
    return $result;
}
function cf_runtime(): array
{
    $files = []; $roots = ['/run/audit-app-runtime'];
    if (isset($GLOBALS['cf_app'])) { $roots[] = $GLOBALS['cf_app']->getRuntimePath(); }
    foreach ($roots as $index => $root) {
        if (!is_dir($root)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) { continue; }
            $relative = substr($file->getPathname(), strlen(rtrim($root, '/') . '/'));
            if (preg_match('~(^|/)(cache|log)(/|$)~', $relative) === 1) { $files[hash('sha256', $index . ':' . $relative)] = hash_file('sha256', $file->getPathname()); }
        }
    }
    ksort($files); return $files;
}
function cf_logs_private(): bool
{
    $paths = [audit_run_dir() . '/evidence/app-runtime.log'];
    foreach (['/run/audit-app-runtime', $GLOBALS['cf_app']->getRuntimePath()] as $root) {
        if (!is_dir($root)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('~(^|/)log(/|$)~', $file->getPathname()) === 1) { $paths[] = $file->getPathname(); }
        }
    }
    foreach ($paths as $path) {
        if (!is_file($path)) { continue; } $bytes = file_get_contents($path);
        foreach (array_unique($GLOBALS['cf_secrets']) as $secret) { if ($secret !== '' && str_contains($bytes, $secret)) { return false; } }
    }
    return true;
}
function cf_same($actual, $expected): bool
{
    if (is_callable($expected)) { return $expected($actual); }
    return $actual === null || $expected === null ? $actual === $expected : (string)$actual === (string)$expected;
}
function cf_update_rule(array $patch): array { return ['mode' => 'update', 'patch' => $patch]; }
function cf_create_rule(string $table, int $id, array $patch): array
{
    return ['mode' => 'create', 'patch' => array_replace(cf_defaults($table), [cf_pk($table) => $id], $patch)];
}
function cf_time_value($value): bool
{
    return is_numeric($value) && (int)$value >= $GLOBALS['cf_window_start'] && (int)$value <= $GLOBALS['cf_window_end'];
}
function cf_pwd_rule(string $password): Closure { return static fn($value): bool => is_string($value) && password_verify($password, $value); }
function cf_rows_match(array $before, array $after, array $rules, string $table): bool
{
    $same = true;
    foreach (array_unique(array_merge(array_keys($before), array_keys($after), array_keys($rules))) as $id) {
        $rule = $rules[$id] ?? null; $left = $before[$id] ?? null; $right = $after[$id] ?? null;
        if ($rule === null) { if ($left !== $right) { $same = false; } continue; }
        if ($rule['mode'] === 'delete') { if (!is_array($left) || $right !== null) { $same = false; } continue; }
        if (!is_array($right) || ($rule['mode'] === 'create' ? $left !== null : !is_array($left))) { $same = false; continue; }
        $expected = $rule['mode'] === 'create' ? $rule['patch'] : array_replace($left, $rule['patch']);
        $a = array_keys($right); $b = array_keys($expected); sort($a); sort($b); if ($a !== $b) { $same = false; }
        foreach ($expected as $field => $value) { if (!array_key_exists($field, $right) || !cf_same($right[$field], $value)) { $same = false; } }
        foreach (['pwd', 'appsecret', 'apppwd', 'push_password', 'file_pwd'] as $field) {
            if (is_string($right[$field] ?? null) && $right[$field] !== '') { $GLOBALS['cf_secrets'][] = $right[$field]; }
        }
    }
    cf_check($same, 'all ' . $table . ' rows and columns match only the explicitly described synthetic changes'); return $same;
}
function cf_tag_keys(): array
{
    $driver = $GLOBALS['cf_app']->cache->store('redis');
    $tagKey = $driver->getCacheKey($driver->getTagKey('crmeb')); $members = $driver->getTagItems('crmeb');
    foreach (array_merge([$tagKey], $members) as $key) { audit_expect(is_string($key) && str_starts_with($key, $GLOBALS['cf_prefix']), 'real configured tag members belong to the test-owned cache namespace'); }
    return array_values(array_unique(array_merge([$tagKey], $members)));
}
function cf_window(string $call, callable $operation, array|callable $rules, array $rotated = [], array $stable = [], bool $clearTag = false, ?array $normalIssue = null): mixed
{
    audit_guard_assert(); $before = cf_snapshot(); $beforeRows = cf_target_rows(); $runtimeBefore = cf_runtime(); $accountsBefore = [];
    $ownedKeysBefore = array_values(array_unique($GLOBALS['cf_owned_keys']));
    foreach (array_merge($rotated, $stable) as $subject) {
        $key = cf_account_key($subject); $account = audit_redis()->hGetAll($key);
        audit_expect(($account['initialized'] ?? '') === '1' && ($account['blocked'] ?? '') === '0'
            && preg_match('/^[a-f0-9]{32}$/D', $account['session_version'] ?? '') === 1 && audit_redis()->ttl($key) === -1,
            'normal prepared subject has its real initialized unblocked persistent account');
        $accountsBefore[$key] = $account;
    }
    $tagKeys = $clearTag ? cf_tag_keys() : [];
    $GLOBALS['cf_window_start'] = time(); $result = null; $errorClass = null;
    try { $result = $operation(); } catch (Throwable $error) { $errorClass = get_class($error); cf_check(false, 'normal real service call succeeds (' . $errorClass . ')'); }
    $GLOBALS['cf_window_end'] = time(); audit_guard_assert(); $after = cf_snapshot(); $afterRows = cf_target_rows(); $runtimeAfter = cf_runtime();
    $resolved = is_callable($rules) ? $rules($result, $beforeRows, $afterRows) : $rules;
    $validTables = []; $dbDiff = [];
    foreach (['eb_system_admin', 'eb_out_account', 'eb_user'] as $table) { $validTables[$table] = cf_rows_match($beforeRows[$table], $afterRows[$table], $resolved[$table] ?? [], $table); }
    foreach ($before['tables'] as $table => $value) {
        if ($value !== ($after['tables'][$table] ?? null) && !($validTables[$table] ?? false)) { $dbDiff[] = $table; }
    }
    $allowedKeys = []; $accountEvidence = [];
    foreach (array_merge($rotated, $stable) as $subject) {
        $key = cf_account_key($subject); $old = $accountsBefore[$key]; $current = audit_redis()->hGetAll($key);
        $changing = in_array($subject, $rotated, true); $expected = $old;
        $validVersion = preg_match('/^[a-f0-9]{32}$/D', $current['session_version'] ?? '') === 1;
        if ($changing) { $expected['session_version'] = $current['session_version'] ?? ''; }
        ksort($expected); ksort($current);
        $valid = $current === $expected && $validVersion && ($changing ? $current['session_version'] !== $old['session_version'] : $current['session_version'] === $old['session_version'])
            && audit_redis()->type($key) === Redis::REDIS_HASH && audit_redis()->ttl($key) === -1 && ($current['blocked'] ?? '') === '0';
        cf_check($valid, $changing ? 'normal sensitive writer changes this exact account version and completes unblocked after its SQL transaction' : 'normal non-sensitive writer preserves this exact account version and unblocked state');
        if ($changing && $valid) { $allowedKeys[] = $key; }
        $accountEvidence[] = ['app' => $subject['app'], 'subject_id' => $subject['id'], 'expected_rotation' => $changing,
            'contract_passed' => $valid, 'before_record_sha256' => hash('sha256', json_encode($old, JSON_THROW_ON_ERROR)),
            'after_record_sha256' => hash('sha256', json_encode($current, JSON_THROW_ON_ERROR))];
    }
    foreach ($tagKeys as $key) {
        $removed = !array_key_exists($key, $after['redis']['*']); cf_check($removed, 'normal profile invalidation removes this exact pre-observed tag member or tag key');
        if ($removed) { $allowedKeys[] = $key; }
    }
    $issueEvidence = null;
    if ($normalIssue !== null) {
        $newOwned = array_values(array_diff(array_unique($GLOBALS['cf_owned_keys']), $ownedKeysBefore));
        $expectedPrefix = $GLOBALS['cf_v2_prefix'] . $normalIssue['app'] . ':session:';
        $key = is_array($result) ? ($result['session_key'] ?? null) : null; $issueValid = false;
        try {
            $issueValid = is_string($key) && count($newOwned) === 1 && $newOwned[0] === $key
                && preg_match('/^' . preg_quote($expectedPrefix, '/') . '[a-f0-9]{32}$/D', $key) === 1
                && !array_key_exists($key, $before['redis']['*']) && array_key_exists($key, $after['redis']['*'])
                && isset($accountsBefore[cf_account_key($normalIssue)]) && in_array($normalIssue, $stable, true)
                && is_array($result) && cf_issue_binding($result, $normalIssue);
        } catch (Throwable $error) { cf_check(false, 'normal returned issuance binding remains independently readable (' . get_class($error) . ')'); }
        cf_check($issueValid, 'normal new-credential login returns exactly one new signed active session for this subject with exact current digest/HMAC/version/expiry binding');
        if ($issueValid) { $allowedKeys[] = $key; }
        $issueEvidence = ['app' => $normalIssue['app'], 'subject_id' => $normalIssue['id'], 'created_session_count' => count($newOwned),
            'binding_verified' => $issueValid, 'session_key_sha256' => is_string($key) ? hash('sha256', $key) : null];
    }
    $cacheDiff = [];
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $allowedKeys, true)) { continue; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $cacheDiff[] = hash('sha256', $key) . ':value'; }
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $cacheDiff[] = hash('sha256', $key) . ':expiry'; }
    }
    cf_check($dbDiff === [], 'all installation and sentinel tables preserve every unrelated row and field');
    cf_check($cacheDiff === [], 'all original session HASH values, accounts and unrelated Redis values/absolute deadlines are preserved');
    cf_check($runtimeBefore === $runtimeAfter && cf_logs_private(), 'normal CLI and HTTP runtime file cache/business logs preserve contents and contain no private values');
    $GLOBALS['cf_capture']['db_diff'] = array_merge($GLOBALS['cf_capture']['db_diff'], $dbDiff);
    $GLOBALS['cf_capture']['cache_diff'] = array_merge($GLOBALS['cf_capture']['cache_diff'], $cacheDiff);
    $ruleEvidence = [];
    foreach ($resolved as $table => $tableRules) { foreach ($tableRules as $id => $rule) { $ruleEvidence[] = ['table' => $table, 'id' => (int)$id, 'operation' => $rule['mode'], 'columns' => array_keys($rule['patch'] ?? [])]; } }
    $GLOBALS['cf_capture']['observations'][] = ['window_completed' => true, 'call' => $call, 'layer' => 'CLI-SERVICE', 'operation_error_class' => $errorClass,
        'table_count' => count($before['tables']), 'allowed_row_changes' => $ruleEvidence, 'account_contracts' => $accountEvidence,
        'normal_issuance' => $issueEvidence,
        'exact_allowed_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $allowedKeys),
        'tag_members_checked' => count($tagKeys), 'redis_values_before' => count($before['redis']['*']), 'redis_values_after' => count($after['redis']['*']),
        'db_before_sha256' => hash('sha256', json_encode($before['tables'], JSON_THROW_ON_ERROR)), 'db_after_sha256' => hash('sha256', json_encode($after['tables'], JSON_THROW_ON_ERROR)),
        'runtime_before_sha256' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)), 'runtime_after_sha256' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR))];
    return $result;
}
function cf_case(string $id, string $name, callable $operation): void
{
    audit_case($id, $name, 'INTEGRATION', static function () use ($id, $name, $operation): array {
        $GLOBALS['cf_capture'] = ['checks' => [], 'observations' => [], 'db_diff' => [], 'cache_diff' => []];
        try { $operation(); } catch (Throwable $error) { cf_check(false, 'normal case preparation and observation complete (' . get_class($error) . ')'); }
        $capture = $GLOBALS['cf_capture']; $ref = 'evidence/account-functional-' . $GLOBALS['cf_execution'] . '-' . $name . '.json';
        $created = []; foreach ($GLOBALS['cf_created'] as $table => $ids) { $created[$table] = array_map('intval', array_keys($ids)); }
        audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'phase' => getenv('AUDIT_PHASE') ?: 'after',
            'case_id' => $id, 'subcase' => $name, 'execution_layer' => 'CLI-SERVICE', 'http_verified' => false, 'synthetic_created_ids' => $created] + $capture);
        $windows = count($capture['observations']);
        return ['status' => $capture['checks'] !== [] && !in_array(false, array_column($capture['checks'], 'passed'), true) ? 'PASS' : 'FAIL',
            'expected' => $name, 'actual' => 'normal real-service checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks']) . '; complete windows ' . $windows,
            'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'], 'evidence_ref' => $ref,
            'notes' => 'Normal legal CLI services and exact new synthetic rows only; no HTTP acceptance or skipped security/fault/concurrency/disconnect/restore claim.'];
    });
}
function cf_admin_data(array $subject, array $extra = []): array
{
    $row = cf_rows('eb_system_admin')[(string)$subject['id']];
    return array_replace(['account' => $row['account'], 'pwd' => '', 'conf_pwd' => '', 'real_name' => $row['real_name'],
        'roles' => [$GLOBALS['cf_role']], 'status' => (int)$row['status']], $extra);
}
function cf_division_data(int $uid, int $aid, string $account, string $password = ''): array
{
    return ['uid' => $uid, 'aid' => $aid, 'account' => $account, 'pwd' => $password, 'conf_pwd' => $password,
        'division_name' => 'CF_DIVISION', 'division_percent' => 8, 'division_end_time' => date('Y-m-d', time() + 14 * 86400),
        'division_status' => 1, 'roles' => [$GLOBALS['cf_role']], 'image' => []];
}
function cf_division_patch(int $uid, array $data): array
{
    return ['division_percent' => $data['division_percent'], 'division_end_time' => strtotime($data['division_end_time']),
        'division_change_time' => 'cf_time_value', 'is_division' => 1, 'is_agent' => 0, 'is_staff' => 0, 'division_id' => $uid,
        'agent_id' => 0, 'staff_id' => 0, 'division_type' => 1, 'division_status' => $data['division_status'], 'spread_uid' => 0,
        'spread_time' => 0, 'division_name' => $data['division_name'], 'is_promoter' => 1, 'division_invite' => 13572468];
}
function cf_division_reset(): array
{
    return ['division_name' => '', 'division_type' => 0, 'division_status' => 0, 'is_division' => 0, 'is_agent' => 0, 'is_staff' => 0,
        'division_id' => 0, 'agent_id' => 0, 'staff_id' => 0, 'division_percent' => 0, 'division_end_time' => 0,
        'division_change_time' => 'cf_time_value', 'division_invite' => 0];
}

try {
    cf_case('A21', 'W01-admin-save-normal-password', static function (): void {
        $subject = cf_subject('adminapi'); $password = cf_password(); $data = cf_admin_data($subject, ['pwd' => $password, 'conf_pwd' => $password, 'real_name' => 'CF_SAVE']);
        $result = cf_window('SystemAdminServices::save', static fn() => $GLOBALS['cf_services']['adminapi']->save($subject['id'], $data),
            ['eb_system_admin' => [$subject['id'] => cf_update_rule(['pwd' => cf_pwd_rule($password), 'real_name' => 'CF_SAVE'])]], [$subject]);
        cf_check($result === true, 'valid admin save preserves its original boolean success contract');
        $subject['password'] = $password;
        $row = cf_rows('eb_system_admin')[(string)$subject['id']];
        cf_window('SystemAdminServices::login with the new valid password', static fn() => cf_login($subject),
            ['eb_system_admin' => [$subject['id'] => cf_update_rule(['login_count' => (int)$row['login_count'] + 1,
                'last_time' => 'cf_time_value', 'last_ip' => request()->ip()])]], [], [$subject], false, $subject);
    });
    cf_case('A21', 'W02-own-updateAdmin-normal-password', static function (): void {
        $subject = cf_subject('adminapi'); $password = cf_password();
        $data = ['pwd' => $subject['password'], 'new_pwd' => $password, 'conf_pwd' => $password, 'real_name' => 'CF_OWN', 'head_pic' => '/statics/cf-own.png'];
        $result = cf_window('SystemAdminServices::updateAdmin', static fn() => $GLOBALS['cf_services']['adminapi']->updateAdmin($subject['id'], $data),
            ['eb_system_admin' => [$subject['id'] => cf_update_rule(['pwd' => cf_pwd_rule($password), 'real_name' => 'CF_OWN', 'head_pic' => $data['head_pic']])]], [$subject], [], true);
        cf_check($result === true, 'valid own password/profile save preserves its original boolean success contract');
    });
    cf_case('A21', 'W03-admin-normal-soft-delete', static function (): void {
        $subject = cf_subject('adminapi');
        $result = cf_window('SystemAdminServices::update (controller delete mapping)', static fn() => $GLOBALS['cf_services']['adminapi']->update($subject['id'], ['is_del' => 1, 'status' => 0]),
            ['eb_system_admin' => [$subject['id'] => cf_update_rule(['is_del' => 1, 'status' => 0])]], [$subject]);
        cf_check($result instanceof \think\Model, 'sensitive inherited-update replacement preserves the original Model return');
    });
    cf_case('A21', 'W04-admin-normal-disable-enable', static function (): void {
        $subject = cf_subject('adminapi');
        foreach ([0, 1] as $status) {
            $result = cf_window('SystemAdminServices::update status', static fn() => $GLOBALS['cf_services']['adminapi']->update($subject['id'], ['status' => $status]),
                ['eb_system_admin' => [$subject['id'] => cf_update_rule(['status' => $status])]], [$subject]);
            cf_check($result instanceof \think\Model, 'normal status operation preserves the Model result');
        }
    });
    cf_case('A21', 'W05-out-normal-secret-and-plaintext', static function (): void {
        $subject = cf_subject('outapi'); $password = cf_password(); $hash = password_hash($password, PASSWORD_DEFAULT); $GLOBALS['cf_secrets'][] = $hash;
        $result = cf_window('OutAccountServices::update (controller secret mapping)', static fn() => $GLOBALS['cf_services']['outapi']->update($subject['id'], ['appsecret' => $hash, 'apppwd' => $password, 'title' => 'CF_CHANGED']),
            ['eb_out_account' => [$subject['id'] => cf_update_rule(['appsecret' => $hash, 'apppwd' => $password, 'title' => 'CF_CHANGED'])]], [$subject]);
        cf_check($result instanceof \think\Model && password_verify($password, $hash), 'normal out secret hash/plaintext synchronization preserves the Model result');
        $subject['password'] = $password;
        cf_window('OutAccountServices::authLogin with the new valid secret', static fn() => cf_login($subject),
            ['eb_out_account' => [$subject['id'] => cf_update_rule(['last_time' => 'cf_time_value', 'ip' => request()->ip()])]], [], [$subject], false, $subject);
    });
    cf_case('A21', 'W06-out-normal-disable-enable', static function (): void {
        $subject = cf_subject('outapi');
        foreach ([0, 1] as $status) {
            $result = cf_window('OutAccountServices::update status', static fn() => $GLOBALS['cf_services']['outapi']->update($subject['id'], ['status' => $status]),
                ['eb_out_account' => [$subject['id'] => cf_update_rule(['status' => $status])]], [$subject]);
            cf_check($result instanceof \think\Model, 'normal out status operation preserves the Model result');
        }
    });
    cf_case('A21', 'W07-out-normal-soft-delete', static function (): void {
        $subject = cf_subject('outapi');
        $result = cf_window('OutAccountServices::update (controller soft-delete mapping)', static fn() => $GLOBALS['cf_services']['outapi']->update($subject['id'], ['is_del' => 1]),
            ['eb_out_account' => [$subject['id'] => cf_update_rule(['is_del' => 1])]], [$subject]);
        cf_check($result instanceof \think\Model, 'normal out soft deletion preserves status and the original Model result');
    });
    cf_case('A21', 'W08-division-normal-edit-password', static function (): void {
        $uid = cf_user(['is_division' => 1, 'division_type' => 1, 'division_status' => 1]);
        $subject = cf_subject('adminapi', ['division_id' => $uid]);
        // A legal ordinary status change in preparation, never a disabled login.
        $GLOBALS['cf_services']['adminapi']->update($subject['id'], ['status' => 0]);
        $password = cf_password(); $data = cf_division_data($uid, $subject['id'], $subject['account'], $password);
        $result = cf_window('DivisionServices::divisionSave existing aid', static fn() => $GLOBALS['cf_services']['division']->divisionSave($data),
            ['eb_user' => [$uid => cf_update_rule(cf_division_patch($uid, $data))],
                'eb_system_admin' => [$subject['id'] => cf_update_rule(['pwd' => cf_pwd_rule($password), 'real_name' => $data['division_name'], 'roles' => (string)$GLOBALS['cf_role']])]], [$subject]);
        cf_check($result === true, 'normal Division edit preserves boolean success and does not assign its creation-only status=1 to an existing disabled admin');
    });
    cf_case('A21', 'W09-division-normal-bulk-status', static function (): void {
        $uid = cf_user(['is_division' => 1, 'division_type' => 1, 'division_status' => 1]);
        $a = cf_subject('adminapi', ['division_id' => $uid]); $b = cf_subject('adminapi', ['division_id' => $uid]); $control = cf_subject('adminapi');
        foreach ([0, 1] as $status) {
            $result = cf_window('DivisionServices::setDivisionStatus', static fn() => $GLOBALS['cf_services']['division']->setDivisionStatus($status, $uid),
                ['eb_user' => [$uid => cf_update_rule(['division_status' => $status])], 'eb_system_admin' => [
                    $a['id'] => cf_update_rule(['status' => $status]), $b['id'] => cf_update_rule(['status' => $status])]], [$a, $b], [$control]);
            cf_check($result === true, 'normal bulk status preserves the boolean result and the exact user/admin relation');
        }
    });
    cf_case('A21', 'W10-division-normal-physical-delete', static function (): void {
        $uid = cf_user(['is_division' => 1, 'division_type' => 1, 'division_status' => 1]);
        // Only precisely created related users are eligible for this Division.
        audit_db()->prepare('UPDATE eb_user SET division_id=? WHERE uid=?')->execute([$uid, $uid]);
        $child = cf_user(['division_id' => $uid, 'is_agent' => 1, 'division_type' => 2, 'division_status' => 1, 'division_name' => 'CF_CHILD']);
        $a = cf_subject('adminapi', ['division_id' => $uid]); $b = cf_subject('adminapi', ['division_id' => $uid]); $control = cf_subject('adminapi');
        $result = cf_window('DivisionServices::delDivision type1', static fn() => $GLOBALS['cf_services']['division']->delDivision(1, $uid),
            ['eb_user' => [$uid => cf_update_rule(cf_division_reset()), $child => cf_update_rule(cf_division_reset())],
                'eb_system_admin' => [$a['id'] => ['mode' => 'delete'], $b['id'] => ['mode' => 'delete']]], [$a, $b], [$control]);
        cf_check($result === null, 'normal Division deletion preserves the original void/null result and physical deletion semantics');
    });
    cf_case('A21', 'N01-admin-and-division-normal-create', static function (): void {
        $name = cf_name(); $password = cf_password(); $data = ['account' => $name, 'pwd' => $password, 'conf_pwd' => $password,
            'real_name' => 'CF_NEW_ADMIN', 'roles' => [$GLOBALS['cf_role']], 'status' => 1, 'level' => 1, 'division_id' => 0];
        $result = cf_window('SystemAdminServices::create', static fn() => $GLOBALS['cf_services']['adminapi']->create($data),
            static function ($result, array $before, array $after) use ($name, $password, $data): array {
                $rows = array_filter($after['eb_system_admin'], static fn(array $row): bool => $row['account'] === $name);
                audit_expect(count($rows) === 1, 'normal service create yields one exact new account'); $row = reset($rows); $id = (int)$row['id']; cf_register('eb_system_admin', $id);
                return ['eb_system_admin' => [$id => cf_create_rule('eb_system_admin', $id, ['account' => $name, 'pwd' => cf_pwd_rule($password), 'real_name' => $data['real_name'],
                    'roles' => (string)$GLOBALS['cf_role'], 'status' => 1, 'level' => 1, 'division_id' => 0, 'add_time' => 'cf_time_value', 'head_pic' => '/statics/system_images/admin_head_pic.png'])]];
            });
        cf_check($result === true, 'new admin create preserves its boolean success and automatic identity');
        $uid = cf_user(); $name = cf_name(); $password = cf_password(); $division = cf_division_data($uid, 0, $name, $password);
        $result = cf_window('DivisionServices::divisionSave new aid', static fn() => $GLOBALS['cf_services']['division']->divisionSave($division),
            static function ($result, array $before, array $after) use ($uid, $name, $password, $division): array {
                $rows = array_filter($after['eb_system_admin'], static fn(array $row): bool => $row['account'] === $name);
                audit_expect(count($rows) === 1, 'normal new Division yields one exact generated admin identity'); $row = reset($rows); $id = (int)$row['id']; cf_register('eb_system_admin', $id);
                return ['eb_user' => [$uid => cf_update_rule(cf_division_patch($uid, $division))], 'eb_system_admin' => [$id => cf_create_rule('eb_system_admin', $id,
                    ['account' => $name, 'pwd' => cf_pwd_rule($password), 'real_name' => $division['division_name'], 'roles' => (string)$GLOBALS['cf_role'], 'status' => 1,
                        'level' => 1, 'division_id' => $uid, 'add_time' => 'cf_time_value', 'head_pic' => '/statics/system_images/admin_head_pic.png'])]];
            });
        cf_check($result === true, 'new Division commits its actual user/admin relation and preserves original boolean success');
    });
    cf_case('A21', 'N02-out-normal-create', static function (): void {
        $name = cf_name(); $password = cf_password(); $hash = password_hash($password, PASSWORD_DEFAULT); $GLOBALS['cf_secrets'][] = $hash;
        $data = ['appid' => $name, 'appsecret' => $hash, 'apppwd' => $password, 'title' => 'CF_NEW_OUT', 'rules' => (string)$GLOBALS['cf_out_route'],
            'add_time' => time(), 'push_account' => '', 'push_password' => '', 'push_token_url' => '', 'user_update_push' => ''];
        $result = cf_window('OutAccountServices::save (controller new-account mapping)', static fn() => $GLOBALS['cf_services']['outapi']->save($data),
            static function ($result, array $before, array $after) use ($name, $data): array {
                $rows = array_filter($after['eb_out_account'], static fn(array $row): bool => $row['appid'] === $name);
                audit_expect(count($rows) === 1, 'normal out create yields one exact automatic identity'); $row = reset($rows); $id = (int)$row['id']; cf_register('eb_out_account', $id);
                return ['eb_out_account' => [$id => cf_create_rule('eb_out_account', $id, $data)]];
            });
        cf_check($result instanceof \think\Model, 'new out save preserves the original created Model return and default enabled status');
    });
    cf_case('A28', 'normal-admin-profile-preserves-version', static function (): void {
        $subject = cf_subject('adminapi'); $data = cf_admin_data($subject, ['real_name' => 'CF_PROFILE']);
        $result = cf_window('SystemAdminServices::save profile only', static fn() => $GLOBALS['cf_services']['adminapi']->save($subject['id'], $data),
            ['eb_system_admin' => [$subject['id'] => cf_update_rule(['real_name' => 'CF_PROFILE'])]], [], [$subject]);
        cf_check($result === true, 'normal admin edit with unchanged status and absent new pwd succeeds');
        $data = ['pwd' => '', 'new_pwd' => '', 'conf_pwd' => '', 'real_name' => 'CF_SELF_PROFILE', 'head_pic' => '/statics/cf-profile.png'];
        $result = cf_window('SystemAdminServices::updateAdmin profile only', static fn() => $GLOBALS['cf_services']['adminapi']->updateAdmin($subject['id'], $data),
            ['eb_system_admin' => [$subject['id'] => cf_update_rule(['real_name' => $data['real_name'], 'head_pic' => $data['head_pic']])]], [], [$subject], true);
        cf_check($result === true, 'normal own profile succeeds without version rotation');
    });
    cf_case('A28', 'normal-account-statistics-preserve-version', static function (): void {
        $admin = cf_subject('adminapi'); $out = cf_subject('outapi'); $row = cf_rows('eb_system_admin')[(string)$admin['id']]; $time = time();
        $adminData = ['last_time' => $time, 'last_ip' => '127.0.0.1', 'login_count' => (int)$row['login_count'] + 1]; $outData = ['last_time' => $time, 'ip' => '127.0.0.1'];
        $result = cf_window('SystemAdminServices::update and OutAccountServices::update statistics only', static function () use ($admin, $out, $adminData, $outData): array {
            return [$GLOBALS['cf_services']['adminapi']->update($admin['id'], $adminData), $GLOBALS['cf_services']['outapi']->update($out['id'], $outData)];
        }, ['eb_system_admin' => [$admin['id'] => cf_update_rule($adminData)], 'eb_out_account' => [$out['id'] => cf_update_rule($outData)]], [], [$admin, $out]);
        cf_check(is_array($result) && count($result) === 2 && $result[0] instanceof \think\Model && $result[1] instanceof \think\Model, 'ordinary statistics preserve both Model results and versions');
    });
    audit_case('A28', 'normal-filePwd-existing-schema-boundary', 'INTEGRATION', static function (): array {
        $exists = array_key_exists('file_pwd', cf_schema('eb_system_admin'));
        audit_expect(!$exists, 'installation schema boundary must be reviewed if file_pwd is later added');
        $ref = 'evidence/account-functional-' . $GLOBALS['cf_execution'] . '-filePwd-schema.json';
        audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
            'table' => 'eb_system_admin', 'column_present' => false, 'method_called' => false, 'schema_changed' => false, 'http_verified' => false]);
        return ['status' => 'NOT RUN', 'expected' => 'normal file password persistence and unchanged v2 version',
            'actual' => 'installer has no file_pwd column; existing setFilePassword cannot be accepted as persistent; no method or DDL was invoked',
            'evidence_ref' => $ref, 'notes' => 'Explicit approved ordinary compatibility limitation; original method retained; no passing or security claim.'];
    });
    cf_case('A28', 'normal-push-settings-preserve-version', static function (): void {
        $subject = cf_subject('outapi'); $password = cf_password();
        $data = ['push_open' => 0, 'push_account' => 'CF_PUSH_ACCOUNT', 'push_password' => $password, 'push_token_url' => '',
            'user_update_push' => '', 'order_create_push' => '', 'order_pay_push' => '', 'refund_create_push' => '', 'refund_cancel_push' => ''];
        $result = cf_window('OutAccountServices::outSetUpSave push disabled', static fn() => $GLOBALS['cf_services']['outapi']->outSetUpSave($subject['id'], $data),
            ['eb_out_account' => [$subject['id'] => cf_update_rule($data)]], [], [$subject]);
        cf_check($result instanceof \think\Model, 'normal push configuration persists with push disabled and no external request or version rotation');
    });
} finally {
    // Capture all business windows before removing only exact owned identities.
    audit_guard_assert(); $beforeCleanup = cf_snapshot(); $runtimeBeforeCleanup = cf_runtime(); $cleanedIds = [];
    foreach (['eb_system_admin', 'eb_out_account', 'eb_user'] as $table) {
        $pk = cf_pk($table); $rows = cf_rows($table); $ids = [];
        foreach ($GLOBALS['cf_created'][$table] as $id => $nameField) {
            if (!isset($rows[$id])) { continue; }
            audit_expect(str_starts_with((string)$rows[$id][$nameField], $GLOBALS['cf_slug']), 'cleanup row still belongs to this RUN synthetic namespace');
            audit_db()->prepare('DELETE FROM `' . $table . '` WHERE `' . $pk . '`=? AND `' . $nameField . '`=?')->execute([$id, $rows[$id][$nameField]]); $ids[] = (int)$id;
        }
        $cleanedIds[$table] = $ids;
    }
    $keys = array_values(array_unique(array_merge($GLOBALS['cf_owned_keys'], cf_scan($GLOBALS['cf_prefix'] . '*'))));
    if ($keys !== []) { audit_redis()->del($keys); }
    audit_guard_assert(); $afterCleanup = cf_snapshot();
    $dbRestored = $afterCleanup['tables'] === $GLOBALS['cf_initial_snapshot']['tables']; $cacheDiff = [];
    foreach (array_unique(array_merge(array_keys($GLOBALS['cf_initial_snapshot']['redis']['*']), array_keys($afterCleanup['redis']['*']))) as $key) {
        $a = $GLOBALS['cf_initial_snapshot']['expires'][$key] ?? -2; $b = $afterCleanup['expires'][$key] ?? -2;
        if (($GLOBALS['cf_initial_snapshot']['redis']['*'][$key] ?? null) !== ($afterCleanup['redis']['*'][$key] ?? null)
            || (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1)) { $cacheDiff[] = hash('sha256', $key); }
    }
    $cleanupRef = 'evidence/account-functional-' . $GLOBALS['cf_execution'] . '-cleanup.json';
    audit_write_json(audit_run_dir() . '/' . $cleanupRef, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
        'exact_removed_ids' => $cleanedIds, 'exact_removed_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $keys),
        'database_rows_restored_to_pre_fixture_snapshot' => $dbRestored, 'unrelated_cache_differences' => $cacheDiff,
        'runtime_unchanged_during_cleanup' => cf_runtime() === $runtimeBeforeCleanup]);
    audit_expect($dbRestored && $cacheDiff === [] && cf_runtime() === $runtimeBeforeCleanup, 'exact fixture cleanup restores pre-fixture rows/cache without changing runtime evidence');
}
audit_finish();
