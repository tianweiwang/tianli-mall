<?php
declare(strict_types=1);

/* Task7 ordinary command draft; install beside lib/harness.php after review.
 * Actual think entry point in a fresh ISO-FULL RUN, actual Store/Token/Stamp.
 * No stopped aggregate suite, negative options, failure injection, concurrent
 * actor, database mutation, recovery simulation, or old-token replay/verify.
 * Signed tokens are retained only in private process memory for log checking.
 * Unexecuted by its preparer. Missing registration is an ordinary baseline FAIL;
 * a genuinely uncleared old namespace makes the empty-repeat case NOT RUN.
 */
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');
$GLOBALS['ef_config'] = audit_config();
$GLOBALS['ef_auth'] = $GLOBALS['ef_config']['auth'] ?? [];
audit_expect(($GLOBALS['ef_auth']['session_epoch'] ?? '') === 'audit-http-' . getenv('AUDIT_RUN_ID')
    && is_string($GLOBALS['ef_auth']['stamp_key'] ?? null) && strlen($GLOBALS['ef_auth']['stamp_key']) >= 32,
    'ordinary command RUN has its actual valid private current epoch');
audit_expect(is_dir('/run/audit-app-runtime'), 'actual read-only HTTP runtime observation mount is available');
audit_expect(is_file(audit_repo_dir() . '/crmeb/think') && !is_file(audit_repo_dir() . '/crmeb/.env'),
    'real think entry exists and repository dotenv cannot override the explicit isolated child environment');
$GLOBALS['ef_current_epoch'] = $GLOBALS['ef_auth']['session_epoch'];
$GLOBALS['ef_old_epoch'] = 'audit-epoch-old-' . getenv('AUDIT_RUN_ID');
$GLOBALS['ef_sibling_epoch'] = $GLOBALS['ef_old_epoch'] . '.sibling';
$GLOBALS['ef_cli_prefix'] = 'audit:epoch-functional:' . getenv('AUDIT_RUN_ID') . ':';
$GLOBALS['ef_tag_prefix'] = 'epoch-functional-tag:' . getenv('AUDIT_RUN_ID') . ':';
$GLOBALS['ef_execution'] = gmdate('YmdHis') . bin2hex(random_bytes(3));
$GLOBALS['ef_owned_keys'] = [];
$GLOBALS['ef_expected_deadlines'] = [];
$GLOBALS['ef_namespaces'] = [];
$GLOBALS['ef_namespace_cleared'] = false;
$GLOBALS['ef_secrets'] = [$GLOBALS['ef_config']['signing_key'], $GLOBALS['ef_auth']['stamp_key'],
    $GLOBALS['ef_config']['redis_password'], $GLOBALS['ef_config']['database']['password']];
$GLOBALS['ef_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'actual full observation table name is safe');
    $GLOBALS['ef_spec']['tables'][$table] = null;
}
foreach ([$GLOBALS['ef_old_epoch'], $GLOBALS['ef_current_epoch'], $GLOBALS['ef_sibling_epoch']] as $epoch) {
    audit_expect(preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $epoch) === 1 && ef_scan('auth:v2:' . $epoch . ':*') === [],
        'each exact test-owned normal namespace is valid and initially empty');
}
audit_expect($GLOBALS['ef_old_epoch'] !== $GLOBALS['ef_current_epoch'] && ef_scan($GLOBALS['ef_cli_prefix'] . '*') === [],
    'private current configuration differs from old and CLI cache namespace is initially empty');
$GLOBALS['ef_initial'] = ef_snapshot();
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
$app = new \think\App(audit_repo_dir() . '/crmeb/');
$app->env->set([
    'APP_DEBUG' => false, 'APP_APP_KEY' => $GLOBALS['ef_config']['signing_key'],
    'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306',
    'DATABASE_DATABASE' => 'audit_auth', 'DATABASE_USERNAME' => 'audit_app',
    'DATABASE_PASSWORD' => $GLOBALS['ef_config']['database']['password'],
    'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => false,
    'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
    'REDIS_REDIS_PASSWORD' => $GLOBALS['ef_config']['redis_password'], 'REDIS_SELECT' => '0',
    'CACHE_CACHE_PREFIX' => $GLOBALS['ef_cli_prefix'], 'CACHE_CACHE_TAG_PREFIX' => $GLOBALS['ef_tag_prefix'],
    'AUTH_SESSION_EPOCH' => $GLOBALS['ef_current_epoch'], 'AUTH_STAMP_KEY' => $GLOBALS['ef_auth']['stamp_key'],
    'AUTH_ISSUER' => $GLOBALS['ef_auth']['issuer'] ?? 'tianli-mall',
]);
$app->initialize(); $GLOBALS['ef_app'] = $app;
$GLOBALS['ef_saved_auth'] = $app->config->get('auth');
$GLOBALS['ef_initial_runtime'] = ef_runtime();
$metadata = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['ef_subjects'] = [];
foreach (['adminapi' => ['admin_super', 'eb_system_admin', 'pwd'], 'outapi' => ['out_101', 'eb_out_account', 'appsecret']] as $appName => [$fixtureName, $table, $field]) {
    $fixture = $metadata[$fixtureName] ?? [];
    audit_expect(is_int($fixture['id'] ?? null) && $fixture['id'] > 0, 'explicit existing synthetic fixture subject is present');
    $statement = audit_db()->prepare('SELECT id,`' . $field . '`,status,is_del FROM `' . $table . '` WHERE id=?');
    $statement->execute([$fixture['id']]); $row = $statement->fetch();
    audit_expect(is_array($row) && (int)$row['status'] === 1 && (int)$row['is_del'] === 0 && is_string($row[$field]) && $row[$field] !== '',
        'existing synthetic fixture has normal enabled state and its actual stored credential digest');
    $GLOBALS['ef_subjects'][$appName] = ['app' => $appName, 'id' => (string)$fixture['id'], 'digest' => $row[$field]];
    $GLOBALS['ef_secrets'][] = $row[$field];
    if (is_string($fixture['password'] ?? null)) { $GLOBALS['ef_secrets'][] = $fixture['password']; }
}
unset($metadata, $row, $fixture);

function ef_scan(string $pattern): array
{
    $redis = audit_redis(); $cursor = null; $keys = [];
    do { $keys = array_merge($keys, $redis->scan($cursor, $pattern, 200) ?: []); } while ($cursor !== 0);
    sort($keys, SORT_STRING); return array_values(array_unique($keys));
}
function ef_check(bool $passed, string $description): void
{
    $GLOBALS['ef_capture']['checks'][] = ['passed' => $passed, 'description' => $description];
}
function ef_diagnostic(Throwable $error): array
{
    return ['class' => get_class($error), 'location' => basename($error->getFile()) . ':' . $error->getLine()];
}
function ef_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['ef_spec']); $snapshot['expires'] = []; $keys = array_keys($snapshot['redis']['*']);
    if ($keys === []) { return $snapshot; }
    $script = "local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local x=redis.call('PTTL',k); if x>=0 then r[i]=n+x else r[i]=x end end; return r";
    $deadlines = audit_redis()->eval($script, $keys, count($keys));
    audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'all native Redis absolute deadlines are observed');
    foreach ($keys as $index => $key) { audit_expect(is_int($deadlines[$index]), 'native absolute deadline is an integer'); $snapshot['expires'][$key] = $deadlines[$index]; }
    return $snapshot;
}
function ef_runtime(): array
{
    $files = []; $roots = ['/run/audit-app-runtime'];
    if (isset($GLOBALS['ef_app'])) { $roots[] = $GLOBALS['ef_app']->getRuntimePath(); }
    foreach ($roots as $index => $root) {
        if (!is_dir($root)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) { continue; } $relative = substr($file->getPathname(), strlen(rtrim($root, '/') . '/'));
            if (preg_match('~(^|/)(cache|log)(/|$)~', $relative) === 1) { $files[hash('sha256', $index . ':' . $relative)] = hash_file('sha256', $file->getPathname()); }
        }
    }
    ksort($files); return $files;
}
function ef_private_text(string $bytes): bool
{
    foreach (array_unique($GLOBALS['ef_secrets']) as $value) { if ($value !== '' && str_contains($bytes, $value)) { return false; } }
    foreach ($GLOBALS['ef_owned_keys'] as $key) { if (str_contains($bytes, $key)) { return false; } }
    return preg_match('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}\b/', $bytes) === 0;
}
function ef_private_logs(): bool
{
    $paths = [audit_run_dir() . '/evidence/app-runtime.log'];
    foreach (['/run/audit-app-runtime', $GLOBALS['ef_app']->getRuntimePath()] as $root) {
        if (!is_dir($root)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('~(^|/)log(/|$)~', $file->getPathname()) === 1) { $paths[] = $file->getPathname(); }
        }
    }
    foreach ($paths as $path) { if (is_file($path) && !ef_private_text((string)file_get_contents($path))) { return false; } }
    return true;
}
function ef_diff(array $before, array $after, array $deleted = []): array
{
    $diff = ['db' => [], 'cache' => []];
    foreach (array_unique(array_merge(array_keys($before['tables']), array_keys($after['tables']))) as $table) {
        if (($before['tables'][$table] ?? null) !== ($after['tables'][$table] ?? null)) { $diff['db'][] = $table; }
    }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']), array_keys($after['redis']['*']))) as $key) {
        if (in_array($key, $deleted, true) && array_key_exists($key, $before['redis']['*']) && !array_key_exists($key, $after['redis']['*'])) { continue; }
        $a = $before['expires'][$key] ?? -2; $b = $after['expires'][$key] ?? -2;
        if (($before['redis']['*'][$key] ?? null) !== ($after['redis']['*'][$key] ?? null)) { $diff['cache'][] = hash('sha256', $key) . ':value'; }
        if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1) { $diff['cache'][] = hash('sha256', $key) . ':expiry'; }
    }
    return $diff;
}
function ef_observe(string $label, array $before, array $after, array $runtimeBefore, array $runtimeAfter, array $deleted = []): void
{
    $diff = ef_diff($before, $after, $deleted);
    ef_check($diff['db'] === [], 'all158 installation and sentinel tables remain unchanged');
    ef_check($diff['cache'] === [], 'all other Redis values and absolute expiry points remain unchanged');
    ef_check($runtimeBefore === $runtimeAfter && ef_private_logs(), 'normal app/runner file cache and logs remain unchanged and contain no private values');
    $GLOBALS['ef_capture']['db_diff'] = array_merge($GLOBALS['ef_capture']['db_diff'], $diff['db']);
    $GLOBALS['ef_capture']['cache_diff'] = array_merge($GLOBALS['ef_capture']['cache_diff'], $diff['cache']);
    $GLOBALS['ef_capture']['observations'][] = ['window_completed' => true, 'label' => $label, 'table_count' => count($before['tables']),
        'redis_values_before' => count($before['redis']['*']), 'redis_values_after' => count($after['redis']['*']),
        'exact_expected_removed_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $deleted),
        'db_before_sha256' => hash('sha256', json_encode($before['tables'], JSON_THROW_ON_ERROR)), 'db_after_sha256' => hash('sha256', json_encode($after['tables'], JSON_THROW_ON_ERROR)),
        'redis_before_sha256' => hash('sha256', json_encode($before['redis']['*'], JSON_THROW_ON_ERROR)), 'redis_after_sha256' => hash('sha256', json_encode($after['redis']['*'], JSON_THROW_ON_ERROR)),
        'runtime_before_sha256' => hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR)), 'runtime_after_sha256' => hash('sha256', json_encode($runtimeAfter, JSON_THROW_ON_ERROR))];
}
function ef_del_calls(): int
{
    $info = audit_redis()->info('commandstats'); audit_expect(is_array($info), 'native Redis INFO commandstats is available');
    $stat = $info['cmdstat_del'] ?? null;
    if ($stat === null) { return 0; }
    if (is_array($stat) && isset($stat['calls']) && is_numeric($stat['calls'])) { return (int)$stat['calls']; }
    audit_expect(is_string($stat) && preg_match('/(?:^|,)calls=([0-9]+)(?:,|$)/', $stat, $match) === 1, 'native DEL command calls have the documented numeric format');
    return (int)$match[1];
}
function ef_child_environment(): array
{
    $config = $GLOBALS['ef_config'];
    return ['PATH' => getenv('PATH') ?: '',
        'PHP_APP_DEBUG' => 'false', 'PHP_APP_APP_KEY' => $config['signing_key'],
        'PHP_DATABASE_HOSTNAME' => 'audit-mysql', 'PHP_DATABASE_HOSTPORT' => '3306', 'PHP_DATABASE_DATABASE' => 'audit_auth',
        'PHP_DATABASE_USERNAME' => 'audit_app', 'PHP_DATABASE_PASSWORD' => $config['database']['password'], 'PHP_DATABASE_PREFIX' => 'eb_', 'PHP_DATABASE_DEBUG' => 'false',
        'PHP_CACHE_DRIVER' => 'redis', 'PHP_CACHE_CACHE_PREFIX' => $GLOBALS['ef_cli_prefix'], 'PHP_CACHE_CACHE_TAG_PREFIX' => $GLOBALS['ef_tag_prefix'],
        'PHP_REDIS_REDIS_HOSTNAME' => 'audit-redis', 'PHP_REDIS_PORT' => '6379', 'PHP_REDIS_REDIS_PASSWORD' => $config['redis_password'], 'PHP_REDIS_SELECT' => '0',
        'PHP_AUTH_SESSION_EPOCH' => $GLOBALS['ef_current_epoch'], 'PHP_AUTH_STAMP_KEY' => $GLOBALS['ef_auth']['stamp_key'],
        'PHP_AUTH_ISSUER' => $GLOBALS['ef_auth']['issuer'] ?? 'tianli-mall'];
}
function ef_think(array $arguments): array
{
    audit_guard_assert(); $process = null; $pipes = []; $out = ''; $err = ''; $nativeExit = null; $closedExit = null; $timedOut = false; $diagnostic = null; $pid = null;
    try {
        $process = proc_open(array_merge([PHP_BINARY, 'think'], $arguments, ['--no-ansi', '--no-interaction']),
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, audit_repo_dir() . '/crmeb', ef_child_environment());
        audit_expect(is_resource($process), 'real think child starts at its actual application cwd');
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $deadline = hrtime(true) + 15000000000;
        do {
            $state = proc_get_status($process); $pid ??= $state['pid'];
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
            audit_expect(strlen($out) + strlen($err) <= 262144, 'actual think output stays inside its bounded private envelope');
            if (!$state['running']) { if ($state['exitcode'] >= 0) { $nativeExit = $state['exitcode']; } break; }
            if (hrtime(true) >= $deadline) { $timedOut = true; break; }
            usleep(10000);
        } while (true);
    } catch (Throwable $error) { $diagnostic = ef_diagnostic($error); }
    finally {
        if (is_resource($process)) {
            $state = proc_get_status($process);
            if ($state['running']) {
                $timedOut = true; proc_terminate($process); $until = hrtime(true) + 1000000000;
                do { usleep(10000); $state = proc_get_status($process); } while ($state['running'] && hrtime(true) < $until);
                if ($state['running']) { proc_terminate($process, 9); }
            }
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    $bytes = stream_get_contents($pipes[$index]); if (is_string($bytes)) { if ($index === 1) { $out .= $bytes; } else { $err .= $bytes; } }
                    fclose($pipes[$index]);
                }
            }
            $closedExit = proc_close($process); if ($nativeExit === null && $closedExit >= 0) { $nativeExit = $closedExit; }
        }
    }
    // Linux command/runbook contract: exactly one complete colon-space line.
    $exactDeletionOutput = preg_match('/\Adeleted_keys: (0|[1-9][0-9]*)\n\z/', $out, $matches) === 1;
    $deleted = $exactDeletionOutput ? (int)$matches[1] : null;
    $private = ef_private_text($out) && ef_private_text($err);
    $summary = ['native_pid' => $pid, 'native_exit' => $nativeExit, 'proc_close_exit' => $closedExit,
        'timed_out' => $timedOut, 'diagnostic' => $diagnostic, 'private_environment_injected' => true,
        'stdout_sha256' => hash('sha256', $out), 'stderr_sha256' => hash('sha256', $err), 'stdout_bytes' => strlen($out), 'stderr_bytes' => strlen($err),
        'stdout_command_registered' => preg_match('/^auth:epoch-rotate(?:\s|$)/m', $out) === 1,
        'help_old_epoch' => str_contains($out, '--old-epoch'), 'help_confirm' => str_contains($out, '--confirm'),
        'deleted_keys' => $deleted, 'deleted_keys_format_exact' => $exactDeletionOutput,
        'private_output_verified' => $private, 'deletion_output_has_no_auth_key_literal' => !str_contains($out, 'auth:v2:')];
    $GLOBALS['ef_capture']['commands'][] = $summary;
    // Never return raw child output or the environment to evidence or stdout.
    unset($out, $err); return $summary;
}
function ef_prepare_namespace(string $epoch, int $sessionsPerApp): array
{
    audit_guard_assert(); $prefix = 'auth:v2:' . $epoch . ':'; $keys = [];
    audit_expect(ef_scan($prefix . '*') === [], 'normal signature preparation does not overwrite any existing namespace');
    $saved = $GLOBALS['ef_app']->config->get('auth');
    try {
        $config = array_replace($saved, ['session_epoch' => $epoch]); $GLOBALS['ef_app']->config->set(['auth' => $config]);
        $checked = \crmeb\services\AuthConfigGuard::authConfig(); audit_expect($checked['session_epoch'] === $epoch, 'normal preparation uses its genuinely loaded configured epoch');
        $store = new \crmeb\services\auth\AuthSessionStore(audit_redis(), $epoch); $tokens = new \crmeb\services\auth\AuthTokenV2($store);
        foreach ($GLOBALS['ef_subjects'] as $appName => $subject) {
            $version = $store->initAccount($appName, $subject['id']); $accountKey = $prefix . $appName . ':account:' . $subject['id'];
            $keys[] = $accountKey; $GLOBALS['ef_owned_keys'][] = $accountKey;
            $GLOBALS['ef_expected_deadlines'][$accountKey] = -1;
            $stamp = \crmeb\services\auth\CredentialStamp::for($appName, $subject['id'], $subject['digest']); $GLOBALS['ef_secrets'][] = $stamp;
            $expectedStamp = hash_hmac('sha256', $appName . '|' . $subject['id'] . '|' . $subject['digest'], $GLOBALS['ef_auth']['stamp_key']);
            audit_expect(hash_equals($expectedStamp, $stamp), 'normal credential stamp binds the actual fixture digest and independent private key');
            for ($index = 0; $index < $sessionsPerApp; ++$index) {
                $issued = $tokens->issue($appName, $subject['id'], $version, $stamp);
                audit_expect(is_string($issued['token'] ?? null) && $issued['token'] !== '' && is_string($issued['sid'] ?? null)
                    && preg_match('/^[a-f0-9]{32}$/D', $issued['sid']) === 1 && is_int($issued['exp'] ?? null), 'actual production issuer returns its normal persisted signed envelope');
                $GLOBALS['ef_secrets'][] = $issued['token'];
                $key = $prefix . $appName . ':session:' . $issued['sid']; $keys[] = $key; $GLOBALS['ef_owned_keys'][] = $key;
                $GLOBALS['ef_expected_deadlines'][$key] = ($issued['exp'] + 60) * 1000;
                $record = audit_redis()->hGetAll($key); $expected = ['subject_id' => $subject['id'], 'type' => $checked['apps'][$appName]['type'],
                    'audience' => $checked['apps'][$appName]['audience'], 'session_version' => $version, 'token_digest' => hash('sha256', $issued['token']),
                    'credential_stamp' => $expectedStamp, 'expires_at' => (string)$issued['exp'], 'status' => 'active'];
                ksort($record); ksort($expected); audit_expect($record === $expected && audit_redis()->type($key) === Redis::REDIS_HASH,
                    'native observer sees only the complete actual signed-token binding fields');
            }
            $account = audit_redis()->hGetAll($accountKey);
            audit_expect(($account['session_version'] ?? '') === $version && ($account['initialized'] ?? '') === '1'
                && ($account['blocked'] ?? '') === '0' && audit_redis()->ttl($accountKey) === -1, 'normal existing fixture account is initialized, unblocked and persistent');
        }
    } finally { $GLOBALS['ef_app']->config->set(['auth' => $saved]); }
    $keys = array_values(array_unique($keys)); sort($keys, SORT_STRING);
    audit_expect(count($keys) === 2 + 2 * $sessionsPerApp && ef_scan($prefix . '*') === $keys, 'normal preparation creates exactly its declared two-app account/session keys');
    return $keys;
}
function ef_case(string $subcase, callable $operation): void
{
    audit_case('A29', $subcase, 'INTEGRATION', static function () use ($subcase, $operation): array {
        $GLOBALS['ef_capture'] = ['checks' => [], 'commands' => [], 'observations' => [], 'db_diff' => [], 'cache_diff' => [], 'diagnostics' => []];
        $notRun = null;
        try { $notRun = $operation(); } catch (Throwable $error) { $GLOBALS['ef_capture']['diagnostics'][] = ef_diagnostic($error); ef_check(false, 'ordinary command case setup/observation completes'); }
        $capture = $GLOBALS['ef_capture']; $ref = 'evidence/epoch-functional-' . $GLOBALS['ef_execution'] . '-' . $subcase . '.json';
        audit_write_json(audit_run_dir() . '/' . $ref, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
            'case_id' => 'A29', 'subcase' => $subcase, 'phase' => getenv('AUDIT_PHASE') ?: 'after', 'execution_layer' => 'REAL-THINK-CLI', 'not_run_reason' => $notRun] + $capture);
        $status = is_string($notRun) ? 'NOT RUN' : ($capture['checks'] !== [] && !in_array(false, array_column($capture['checks'], 'passed'), true) ? 'PASS' : 'FAIL');
        return ['status' => $status, 'expected' => $subcase, 'actual' => $notRun ?? ('ordinary checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks'])
                . '; complete windows ' . count($capture['observations'])), 'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'], 'evidence_ref' => $ref,
            'notes' => 'Actual think command and normal synthetic signed namespaces only; no full A29 recovery, fault, old-token refusal, concurrent or negative-option coverage is implied.'];
    });
}

try {
    $prepareBefore = ef_snapshot(); $prepareRuntime = ef_runtime();
    $GLOBALS['ef_namespaces']['old'] = ef_prepare_namespace($GLOBALS['ef_old_epoch'], 512);
    $GLOBALS['ef_namespaces']['current'] = ef_prepare_namespace($GLOBALS['ef_current_epoch'], 1);
    $GLOBALS['ef_namespaces']['sibling'] = ef_prepare_namespace($GLOBALS['ef_sibling_epoch'], 1);
    audit_expect($GLOBALS['ef_app']->config->get('auth') === $GLOBALS['ef_saved_auth']
        && \crmeb\services\AuthConfigGuard::authConfig()['session_epoch'] === $GLOBALS['ef_current_epoch'], 'parent configuration is fully restored to its actual current epoch before any command');
    $prepareAfter = ef_snapshot(); $owned = array_values(array_unique($GLOBALS['ef_owned_keys']));
    $expectedPrepared = array_merge(array_keys($prepareBefore['redis']['*']), $owned); sort($expectedPrepared, SORT_STRING);
    $actualPrepared = array_keys($prepareAfter['redis']['*']); sort($actualPrepared, SORT_STRING);
    $deadlinesVerified = true;
    foreach ($GLOBALS['ef_expected_deadlines'] as $key => $expected) {
        $actual = $prepareAfter['expires'][$key] ?? -2;
        if (($expected < 0 || $actual < 0) ? $expected !== $actual : abs($expected - $actual) > 1) { $deadlinesVerified = false; }
    }
    $oldPrefix = 'auth:v2:' . $GLOBALS['ef_old_epoch'] . ':';
    $currentPrefix = 'auth:v2:' . $GLOBALS['ef_current_epoch'] . ':';
    $siblingPrefix = 'auth:v2:' . $GLOBALS['ef_sibling_epoch'] . ':';
    $prepareDiff = ef_diff($prepareBefore, $prepareAfter);
    $unrelatedPrepare = [];
    foreach (array_keys($prepareBefore['redis']['*']) as $key) {
        $a = $prepareBefore['expires'][$key]; $b = $prepareAfter['expires'][$key] ?? -2;
        if (($prepareBefore['redis']['*'][$key] ?? null) !== ($prepareAfter['redis']['*'][$key] ?? null)
            || (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1)) { $unrelatedPrepare[] = hash('sha256', $key); }
    }
    $preparationVerified = $prepareDiff['db'] === [] && $expectedPrepared === $actualPrepared && $unrelatedPrepare === []
        && $deadlinesVerified && $prepareRuntime === ef_runtime() && ef_private_logs();
    $prepRef = 'evidence/epoch-functional-' . $GLOBALS['ef_execution'] . '-preparation.json';
    audit_write_json(audit_run_dir() . '/' . $prepRef, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
        'record_type' => 'normal preparation only, not a PASS case', 'preparation_verified' => $preparationVerified,
        'normal_issued_session_counts' => ['old' => 1024, 'current' => 2, 'sibling' => 2], 'account_counts' => ['old' => 2, 'current' => 2, 'sibling' => 2],
        'old_epoch_sha256' => hash('sha256', $GLOBALS['ef_old_epoch']), 'current_epoch_sha256' => hash('sha256', $GLOBALS['ef_current_epoch']),
        'sibling_epoch_sha256' => hash('sha256', $GLOBALS['ef_sibling_epoch']), 'table_count' => count($prepareBefore['tables']),
        'database_unchanged' => $prepareDiff['db'] === [], 'unrelated_key_differences' => $unrelatedPrepare,
        'all_real_account_and_issued_session_deadlines_verified' => $deadlinesVerified,
        'parent_configuration_restored' => true, 'runtime_unchanged' => $prepareRuntime === ef_runtime(), 'old_token_replayed_or_verified' => false]);
    audit_expect($preparationVerified, 'normal namespace preparation changes only its exact declared real session/account HASH keys');

    ef_case('epoch-command-registration-help', static function (): void {
        foreach ([['list', '--raw'], ['help', 'auth:epoch-rotate']] as $arguments) {
            audit_guard_assert(); $before = ef_snapshot(); $runtimeBefore = ef_runtime(); $result = ef_think($arguments);
            $after = ef_snapshot(); ef_observe($arguments[0], $before, $after, $runtimeBefore, ef_runtime());
            ef_check($result['native_exit'] === 0 && !$result['timed_out'] && $result['diagnostic'] === null && $result['private_output_verified'], 'real native think entry succeeds with private output');
            if ($arguments[0] === 'list') { ef_check($result['stdout_command_registered'], 'actual raw command list registers auth:epoch-rotate'); }
            else { ef_check($result['help_old_epoch'] && $result['help_confirm'], 'actual command help declares old-epoch and confirm options'); }
        }
    });
    ef_case('epoch-command-normal-batched-delete', static function () use ($oldPrefix, $currentPrefix, $siblingPrefix): void {
        audit_guard_assert(); $before = ef_snapshot(); $runtimeBefore = ef_runtime(); $callsBefore = ef_del_calls();
        $result = ef_think(['auth:epoch-rotate', '--old-epoch=' . $GLOBALS['ef_old_epoch'], '--confirm']);
        $callsAfter = ef_del_calls(); $after = ef_snapshot(); $oldKeys = $GLOBALS['ef_namespaces']['old'];
        $oldAbsent = ef_scan($oldPrefix . '*') === [] && array_intersect($oldKeys, array_keys($after['redis']['*'])) === [];
        ef_check(count($oldKeys) === 1026 && $oldAbsent, 'only all1026 exact normal old-epoch session/account keys are physically absent');
        ef_check($result['native_exit'] === 0 && !$result['timed_out'] && $result['diagnostic'] === null
            && $result['deleted_keys'] === count($oldKeys) && $result['private_output_verified'] && $result['deletion_output_has_no_auth_key_literal'], 'successful actual command reports the exact numeric deleted_keys count with no raw keys/private values');
        ef_check($callsAfter >= $callsBefore && $callsAfter - $callsBefore >= 6, 'independent INFO proves at least six native DEL calls for1026 keys under the hard200-key batch contract');
        ef_check(ef_scan($currentPrefix . '*') === $GLOBALS['ef_namespaces']['current'] && ef_scan($siblingPrefix . '*') === $GLOBALS['ef_namespaces']['sibling'], 'current and similar-prefix normal signed namespaces retain all their exact original keys');
        ef_observe('normal batch deletion', $before, $after, $runtimeBefore, ef_runtime(), $oldKeys);
        $GLOBALS['ef_capture']['batch_commandstats'] = ['cmdstat_del_before' => $callsBefore, 'cmdstat_del_after' => $callsAfter, 'native_del_calls' => $callsAfter - $callsBefore];
        $GLOBALS['ef_namespace_cleared'] = $oldAbsent && $result['native_exit'] === 0 && $result['deleted_keys'] === count($oldKeys);
    });
    ef_case('epoch-command-normal-empty-repeat', static function () use ($oldPrefix): ?string {
        if (!$GLOBALS['ef_namespace_cleared'] || ef_scan($oldPrefix . '*') !== []) {
            return 'NOT RUN: the preceding ordinary command did not acknowledge and establish an actually empty old namespace; no extra cleanup/repeat was executed';
        }
        audit_guard_assert(); $before = ef_snapshot(); $runtimeBefore = ef_runtime(); $callsBefore = ef_del_calls();
        $result = ef_think(['auth:epoch-rotate', '--old-epoch=' . $GLOBALS['ef_old_epoch'], '--confirm']);
        $callsAfter = ef_del_calls(); $after = ef_snapshot();
        ef_check($result['native_exit'] === 0 && !$result['timed_out'] && $result['diagnostic'] === null
            && $result['deleted_keys'] === 0 && $result['private_output_verified'] && $result['deletion_output_has_no_auth_key_literal'], 'same actually empty old namespace normal command succeeds and reports exactly deleted_keys: 0 followed by its newline');
        ef_check($callsAfter === $callsBefore && ef_scan($oldPrefix . '*') === [], 'normal empty repetition performs no DEL and does not create a namespace');
        ef_observe('normal empty repetition', $before, $after, $runtimeBefore, ef_runtime()); return null;
    });
} finally {
    $GLOBALS['ef_app']->config->set(['auth' => $GLOBALS['ef_saved_auth']]);
    audit_guard_assert(); $beforeCleanup = ef_snapshot(); $runtimeBeforeCleanup = ef_runtime();
    $remaining = [];
    foreach (array_values(array_unique($GLOBALS['ef_owned_keys'])) as $key) { if (array_key_exists($key, $beforeCleanup['redis']['*'])) { $remaining[] = $key; } }
    $redis = audit_redis(); foreach (array_chunk($remaining, 200) as $batch) { $redis->del($batch); }
    audit_guard_assert(); $afterCleanup = ef_snapshot(); $diff = ef_diff($GLOBALS['ef_initial'], $afterCleanup);
    $cleanupRef = 'evidence/epoch-functional-' . $GLOBALS['ef_execution'] . '-cleanup.json';
    audit_write_json(audit_run_dir() . '/' . $cleanupRef, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
        'exact_removed_key_count' => count($remaining), 'exact_removed_key_hashes' => array_map(static fn(string $key): string => hash('sha256', $key), $remaining),
        'initial_all_table_snapshot_restored' => $diff['db'] === [], 'initial_all_redis_values_and_deadlines_restored' => $diff['cache'] === [],
        'unrelated_db_differences' => $diff['db'], 'unrelated_cache_differences' => $diff['cache'], 'runtime_unchanged_during_cleanup' => ef_runtime() === $runtimeBeforeCleanup,
        'initial_runtime_cache_and_logs_restored' => ef_runtime() === $GLOBALS['ef_initial_runtime'],
        'parent_configuration_restored' => $GLOBALS['ef_app']->config->get('auth') === $GLOBALS['ef_saved_auth']]);
    audit_expect($diff['db'] === [] && $diff['cache'] === [] && ef_runtime() === $runtimeBeforeCleanup
        && ef_runtime() === $GLOBALS['ef_initial_runtime'], 'only exact owned preparation keys were cleaned and initial all-table/Redis/runtime state is restored');
}
audit_finish();
