<?php
declare(strict_types=1);

// Real isolated PHP HTTP service lifecycle. No application route or credentials
// are used; TCP readiness does not warm translation/configuration caches.
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS0b');

$GLOBALS['hw_spec'] = ['tables' => [], 'redis' => ['*']];
foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'snapshot scope rejected');
    $GLOBALS['hw_spec']['tables'][$table] = null;
}
function hw_listening(): bool
{
    $socket = @fsockopen('audit-app', 8000, $errno, $message, 0.2);
    if (!is_resource($socket)) { return false; }
    fclose($socket); return true;
}
function hw_ready(): void
{
    for ($i = 0; $i < 100; ++$i) {
        if (hw_listening()) { return; }
        usleep(50000);
    }
    throw new RuntimeException('isolated server must actually listen');
}
function hw_snapshot(): array
{
    $snapshot = audit_snapshot($GLOBALS['hw_spec']); $snapshot['expires'] = [];
    $keys = array_keys($snapshot['redis']['*']);
    if ($keys !== []) {
        $script = "local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local x=redis.call('PTTL',k); if x>=0 then r[i]=n+x else r[i]=x end end; return r";
        $deadlines = audit_redis()->eval($script, $keys, count($keys));
        audit_expect(is_array($deadlines) && count($deadlines) === count($keys), 'all cache expiry points must be observed');
        foreach ($keys as $i => $key) { $snapshot['expires'][$key] = $deadlines[$i]; }
    }
    return $snapshot;
}
function hw_diff(array $a, array $b): array
{
    $db = []; $cache = [];
    foreach (array_unique(array_merge(array_keys($a['tables']), array_keys($b['tables']))) as $table) {
        if (($a['tables'][$table] ?? null) !== ($b['tables'][$table] ?? null)) { $db[] = $table; }
    }
    foreach (array_unique(array_merge(array_keys($a['redis']['*']), array_keys($b['redis']['*']))) as $key) {
        $left = $a['expires'][$key] ?? null; $right = $b['expires'][$key] ?? null;
        $sameExpiry = is_int($left) && is_int($right)
            && (($left >= 0 && $right >= 0 && abs($left - $right) <= 1) || $left === $right);
        if (($a['redis']['*'][$key] ?? null) !== ($b['redis']['*'][$key] ?? null) || !$sameExpiry) {
            $cache[] = hash('sha256', $key);
        }
    }
    return ['db' => $db, 'cache' => $cache];
}
function hw_runtime(): array
{
    $values = [];
    foreach (['/run/audit-app-runtime', audit_repo_dir() . '/crmeb/runtime'] as $root) {
        audit_expect(is_dir($root), 'actual runtime observation directory must exist');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) { continue; }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('~(^|/)(cache|log)(/|$)~', $relative) === 1) {
                $values[hash('sha256', $root . '/' . $relative)] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($values); return $values;
}
function hw_ack(): array
{
    return audit_read_control_json(audit_run_dir() . '/app-status.json');
}
function hw_group(array $ack): void
{
    $group = $ack['group'] ?? []; $supervisor = $ack['supervisor'] ?? [];
    audit_expect(($ack['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && is_array($ack['members'] ?? null)
        && is_string($ack['generation_revision'] ?? null) && preg_match('/^[a-f0-9]{16}$/D', $ack['generation_revision']) === 1,
        'process observation must belong to this real RUN and server generation');
    audit_expect(is_int($group['leader_pid'] ?? null) && $group['leader_pid'] > 1
        && $group['pgid'] === $group['leader_pid'] && $group['sid'] === $group['leader_pid']
        && is_string($group['leader_starttime'] ?? null) && ctype_digit($group['leader_starttime'])
        && is_int($supervisor['pid'] ?? null) && $group['pgid'] !== $supervisor['pgid'],
        'actual independent process group identity must exclude the supervisor');
    foreach ($ack['members'] as $member) {
        audit_expect(is_int($member['pid'] ?? null) && $member['pid'] > 1
            && $member['pgid'] === $group['pgid'] && $member['sid'] === $group['sid']
            && is_string($member['starttime'] ?? null) && ctype_digit($member['starttime'])
            && !in_array($member['state'] ?? '', ['Z', 'X'], true), 'only live members of the registered group may be observed');
        foreach ($member['redis_sockets'] ?? [] as $socket) {
            audit_expect(is_int($socket['local_port'] ?? null) && $socket['local_port'] > 0 && $socket['local_port'] <= 65535
                && ($socket['remote_port'] ?? null) === 6379 && is_string($socket['inode'] ?? null) && ctype_digit($socket['inode']),
                'only established sockets to the approved Redis port may be observed');
        }
        audit_expect(!isset($member['cmdline'], $member['environ'], $member['comm']), 'observation must contain no command or environment material');
    }
}
function hw_server_pids(int $offset): array
{
    $pids = []; $file = audit_run_dir() . '/evidence/app-runtime.log';
    $deadline = microtime(true) + 5.0;
    do {
        $log = is_file($file) ? substr((string)file_get_contents($file), $offset) : '';
        preg_match_all('/^\[([0-9]+)\] .*PHP ([0-9.]+) Development Server/m', $log, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            audit_expect($match[2] === PHP_VERSION, 'actual HTTP server and runner PHP versions must match');
            $pids[] = (int)$match[1];
        }
        $pids = array_values(array_unique($pids));
        if (count($pids) >= 3) { break; }
        usleep(50000);
    } while (microtime(true) < $deadline);
    sort($pids); return $pids;
}
function hw_stopped(bool $registered): array
{
    audit_app_request('stop'); $ack = hw_ack();
    audit_expect(!hw_listening(), 'stop must actually close the isolated HTTP listener');
    if ($registered) {
        audit_expect(($ack['remaining_members'] ?? null) === [] && ($ack['listener_closed'] ?? false) === true
            && is_array($ack['stopped_group'] ?? null), 'all registered fork workers must actually exit before stop acknowledgement');
    }
    return $ack;
}
function hw_run(string $mode): array
{
    audit_guard_assert(); audit_app_request('stop');
    audit_expect(!hw_listening(), 'case must start without an old HTTP listener');
    $before = hw_snapshot(); $runtime = hw_runtime(); $facts = []; $error = null; $stopError = null;
    try {
        $file = audit_run_dir() . '/evidence/app-runtime.log'; clearstatcache(true, $file);
        $offset = is_file($file) ? filesize($file) : 0;
        if ($mode === 'default') {
            audit_app_request('start'); hw_ready();
            $facts = ['default_tcp_listener_observed' => true, 'stopped' => hw_stopped(false)['status'] === 'stop'];
        } elseif ($mode === 'invalid') {
            foreach (['1', '0', '3', '-2', 'anything', 2, [], null] as $value) {
                $rejected = false;
                try { audit_app_request('start', ['SERVER_WORKERS' => $value]); }
                catch (RuntimeException $caught) { $rejected = true; }
                audit_expect($rejected && !hw_listening(), 'unsupported worker override must be rejected without a listener');
            }
            $facts = ['invalid_override_count' => 8, 'all_rejected_without_listener' => true];
        } else {
            audit_app_request('start', ['SERVER_WORKERS' => '2']); hw_ready();
            $serverPids = hw_server_pids($offset);
            audit_expect(count($serverPids) === 3, 'two fork workers and the master must appear in actual PHP startup logs');
            audit_app_request('observe'); $observed = hw_ack(); hw_group($observed);
            audit_expect(($observed['listener_open'] ?? false) === true
                && array_diff($serverPids, array_column($observed['members'], 'pid')) === [],
                'actual server log PIDs must be live members of the registered group');
            $facts = ['actual_server_pids' => $serverPids, 'registered_group' => $observed['group'],
                'server_generation' => $observed['generation_revision'], 'actual_member_pids' => array_column($observed['members'], 'pid')];
            if ($mode === 'observe') {
                audit_app_request('observe'); $again = hw_ack(); hw_group($again);
                audit_expect($again['group'] === $observed['group']
                    && $again['generation_revision'] === $observed['generation_revision']
                    && array_column($again['members'], 'pid') === array_column($observed['members'], 'pid') && hw_listening(),
                    'read-only observation must preserve the live generation and listener');
                $facts['repeated_observation_preserved_generation'] = true;
            }
            $stopped = hw_stopped(true);
            audit_expect($stopped['stopped_group'] === $observed['group'], 'stop must use the registered generation');
            $facts['remaining_members'] = $stopped['remaining_members'];
            if ($mode === 'restore') {
                clearstatcache(true, $file); $offset = filesize($file);
                audit_app_request('start', ['SERVER_WORKERS' => '']); hw_ready();
                audit_app_request('observe'); $default = hw_ack(); hw_group($default);
                audit_expect($default['group'] !== $observed['group'], 'a restored server must have a new registered identity');
                $lines = substr((string)file_get_contents($file), $offset);
                audit_expect(preg_match_all('/PHP [0-9.]+ Development Server/', $lines) === 1
                    && preg_match('/^\[[0-9]+\] .*PHP [0-9.]+ Development Server/m', $lines) !== 1,
                    'empty override must restore actual single-worker startup, not retain fork configuration');
                hw_stopped(true); $facts['restored_single_worker_startup_verified'] = true;
            }
        }
    } catch (Throwable $caught) {
        $error = ['class' => get_class($caught), 'assertion' => $caught instanceof RuntimeException && !$caught instanceof PDOException ? $caught->getMessage() : null];
    } finally {
        try { audit_app_request('stop'); audit_expect(!hw_listening(), 'final listener cleanup must succeed'); }
        catch (Throwable $caught) { $stopError = ['class' => get_class($caught)]; }
    }
    audit_guard_assert(); $diff = hw_diff($before, hw_snapshot()); $runtimeSame = $runtime === hw_runtime();
    $pass = $error === null && $stopError === null && $diff === ['db' => [], 'cache' => []] && $runtimeSame;
    $reference = 'evidence/http-workers-' . (getenv('AUDIT_PHASE') ?: 'after') . '-' . gmdate('YmdHis') . '-' . $mode . '.json';
    audit_write_json(audit_run_dir() . '/' . $reference, ['run_id' => getenv('AUDIT_RUN_ID'), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'),
        'mode' => $mode, 'passed' => $pass, 'observations' => $facts, 'failure' => $error, 'cleanup_failure' => $stopError,
        'tables_checked' => count($before['tables']), 'all_redis_values_and_expiries_checked' => true,
        'unexpected_changes' => $diff, 'runtime_cache_log_unchanged' => $runtimeSame, 'listener_closed' => !hw_listening()]);
    return ['status' => $pass ? 'PASS' : 'FAIL', 'expected' => 'real isolated HTTP workers and stop/restart lifecycle; all business state unchanged',
        'actual' => $pass ? 'real HTTP process lifecycle passed; listener closed and all business state preserved' : 'real HTTP process lifecycle failed; redacted evidence retained',
        'db_diff' => $diff['db'], 'cache_diff' => $diff['cache'], 'evidence_ref' => $reference,
        'notes' => 'Default TCP behavior and invalid overrides may already PASS on the old supervisor; only actual fork/PID/lifecycle behavior establishes worker readiness. No HTTP authentication route is invoked.'];
}
foreach (['default', 'multi', 'observe', 'invalid', 'restore'] as $mode) {
    audit_case('Z07', 'http-workers-' . $mode, 'INTEGRATION', static fn(): array => hw_run($mode));
}
audit_finish();
