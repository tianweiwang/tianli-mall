<?php
declare(strict_types=1);

/** One planned local full-runtime service update. The caller stops FPM; this tool never claims to observe it. */
const PRU_SERVICE = 'app/services/marketplace/order/MarketplaceOrderReviewServices.php';
function pru_require(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException('RUNTIME_REVIEW_UPDATE_' . $code);
}
function pru_keys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    pru_require($actual === $keys, 'EXACT_KEYS');
}
function pru_read(string $path, int $maximum = 1048576): string
{
    clearstatcache(true, $path); $before = lstat($path);
    pru_require(is_array($before) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && $before['nlink'] === 1
        && $before['size'] >= 0 && $before['size'] <= $maximum, 'REGULAR_BOUNDED_FILE');
    $fields = ['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    $stream = fopen($path, 'rb');
    try {
        pru_require(is_resource($stream), 'OPEN_FILE'); $opened = fstat($stream);
        foreach ($fields as $key) pru_require($opened[$key] === $before[$key], 'SAME_OPEN_FILE');
        $raw = stream_get_contents($stream, $before['size'] + 1);
        pru_require(is_string($raw) && strlen($raw) === $before['size'] && feof($stream), 'COMPLETE_FILE');
        $after = fstat($stream);
        foreach ($fields as $key) pru_require($after[$key] === $opened[$key], 'OPEN_FILE_STABLE');
    } finally { if (is_resource($stream)) fclose($stream); }
    clearstatcache(true, $path); $after = lstat($path);
    foreach ($fields as $key) pru_require($after[$key] === $before[$key], 'PATH_STABLE');
    return $raw;
}
function pru_pin(string $raw): array
{
    return ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
}
function pru_access(string $path, bool $writable, bool $exact = false): void
{
    $selected = null; $length = -1;
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]);
        $at = strtr($left[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
        if (($path === $at || (!$exact && str_starts_with($path, rtrim($at, '/') . '/')))
            && strlen($at) > $length) { $selected = explode(',', $left[5]); $length = strlen($at); }
    }
    pru_require(is_array($selected) && in_array($writable ? 'rw' : 'ro', $selected, true), 'EXPLICIT_MOUNT_ACCESS');
}
function pru_manifest(string $root): array
{
    pru_require(is_dir($root) && !is_link($root) && realpath($root) === $root, 'REAL_SOURCE_ROOT');
    $manifest = []; preview_full_runtime_source($root, '', $manifest, null, true); return $manifest;
}
function pru_receipt(array $identity, array $manifest): array
{
    return $identity + ['source_sha256' => hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'source_files' => count(array_filter($manifest, static fn(array $row): bool => $row['type'] === 'file')),
        'source_directories' => count(array_filter($manifest, static fn(array $row): bool => $row['type'] === 'directory'))];
}
function pru_file_mode(string $path, int $mode): void
{
    clearstatcache(true, $path); $stat = lstat($path);
    pru_require(is_array($stat) && !is_link($path) && ($stat['mode'] & 0170000) === 0100000
        && $stat['nlink'] === 1 && $stat['uid'] === 0 && $stat['gid'] === 0
        && ($stat['mode'] & 07777) === $mode, 'EXACT_RUNTIME_FILE_OWNER_MODE');
}
function pru_precheck(array $plan): array
{
    $GLOBALS['pru_phase'] = 'complete_old_and_new_deployment_source';
    $old = pru_manifest('/baseline/crmeb'); $new = pru_manifest('/source/crmeb');
    pru_require(array_keys($old) === array_keys($new), 'SAME_COMPLETE_SOURCE_NAMESPACE'); $changes = [];
    foreach ($old as $path => $row) if ($row !== $new[$path]) $changes[] = $path;
    pru_require($changes === [PRU_SERVICE], 'ONLY_APPROVED_SOURCE_PATH_CHANGED');
    foreach (['before' => $old, 'after' => $new] as $key => $manifest)
        pru_require($manifest[PRU_SERVICE] === ['type' => 'file', 'size' => $plan[$key]['bytes'],
            'sha256' => $plan[$key]['sha256']], 'EXACT_PLANNED_SOURCE_PIN');
    $identity = ['project' => $plan['project'], 'run_id' => $plan['run_id'], 'feature' => 'full-runtime-v1'];
    $oldReceipt = pru_receipt($identity, $old); $newReceipt = pru_receipt($identity, $new);
    foreach ($plan['old_ready'] as $key => $value) pru_require($oldReceipt[$key] === $value, 'EXACT_OLD_READY_SOURCE');
    $oldBytes = pru_read('/baseline/crmeb/' . PRU_SERVICE); $newBytes = pru_read('/source/crmeb/' . PRU_SERVICE);
    pru_require(pru_pin($oldBytes) === $plan['before'] && pru_pin($newBytes) === $plan['after'], 'PLANNED_COMPLETE_SERVICE_BYTES');
    $ready = pru_read('/runtime-app/.preview-runtime-ready.json'); $ini = pru_read('/runtime-app/.env');
    pru_file_mode('/runtime-app/' . PRU_SERVICE, 0644); pru_file_mode('/runtime-app/.preview-runtime-ready.json', 0600);
    pru_require(pru_read('/runtime-app/' . PRU_SERVICE) === $oldBytes
        && $ready === json_encode($oldReceipt, JSON_THROW_ON_ERROR) . "\n", 'ACTUAL_OLD_SERVICE_AND_READY');
    $GLOBALS['pru_phase'] = 'original_complete_old_runtime_verify';
    preview_full_runtime_verify($old, $identity, $oldReceipt, $ini);
    return compact('old', 'new', 'identity', 'oldReceipt', 'newReceipt', 'oldBytes', 'newBytes', 'ready', 'ini');
}
function pru_stable_sources(array $state, string $planRaw): void
{
    pru_require(pru_read('/plan.json') === $planRaw
        && pru_manifest('/baseline/crmeb') === $state['old']
        && pru_manifest('/source/crmeb') === $state['new'], 'COMPLETE_SOURCES_AND_PLAN_RECHECK');
}
function pru_replace(string $path, string $before, string $after, int $mode): void
{
    $temporary = $path . '.preview-review-update.tmp';
    pru_require(!file_exists($temporary) && !is_link($temporary), 'NEW_ATOMIC_TEMPORARY_ONLY');
    pru_file_mode($path, $mode); pru_require(pru_read($path) === $before, 'UNCHANGED_ATOMIC_TARGET');
    preview_full_runtime_write($temporary, $after, $mode);
    pru_file_mode($temporary, $mode); pru_require(pru_read($temporary) === $after, 'COMPLETE_ATOMIC_TEMPORARY');
    pru_require(pru_read($path) === $before, 'ATOMIC_TARGET_RECHECK'); pru_file_mode($path, $mode);
    pru_require(rename($temporary, $path), 'ATOMIC_RENAME');
    pru_file_mode($path, $mode); pru_require(pru_read($path) === $after, 'ATOMIC_REPLACEMENT_READBACK');
}
function pru_main(array $argv): int
{
    pru_require(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300 && PHP_INT_SIZE === 8
        && posix_geteuid() === 0 && posix_getegid() === 0 && count($argv) === 2
        && $argv[0] === __FILE__ && __FILE__ === '/source/help/preview/runtime-review-update.php'
        && in_array($argv[1], ['check', 'apply'], true), 'FIXED_PHP82_ENTRY');
    foreach (['/baseline/crmeb', '/source/crmeb'] as $path) pru_access($path, false);
    foreach (['/plan.json', '/workspace/crmeb/runtime', '/workspace/crmeb/public/uploads', '/private'] as $path) pru_access($path, false, true);
    pru_access('/runtime-app', $argv[1] === 'apply', true); pru_access('/out', true, true);
    pru_require(is_dir('/out') && !is_link('/out') && realpath('/out') === '/out'
        && scandir('/out') === ['.', '..'], 'NEW_EMPTY_OUTPUT'); $GLOBALS['pru_out_ready'] = true;
    $planRaw = pru_read('/plan.json'); $plan = json_decode($planRaw, true, 32, JSON_THROW_ON_ERROR);
    pru_require(is_array($plan), 'PLAN_OBJECT');
    pru_keys($plan, ['schema','scope','project','run_id','old_source_sha','baseline_source_sha','new_source_sha','relative_path','before','after','old_ready']);
    pru_require($plan['schema'] === 1 && $plan['scope'] === 'LOCAL_FULL_RUNTIME_REVIEW_SERVICE_UPDATE'
        && is_string($plan['run_id']) && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $plan['run_id']) === 1
        && $plan['project'] === 'mall-preview-' . $plan['run_id'] && $plan['relative_path'] === PRU_SERVICE, 'FIXED_PLAN_INSTANCE_AND_PATH');
    foreach (['old_source_sha','baseline_source_sha','new_source_sha'] as $key)
        pru_require(is_string($plan[$key]) && preg_match('/\A[a-f0-9]{40}\z/D', $plan[$key]) === 1, 'PLAN_COMMIT_ID');
    pru_require($plan['new_source_sha'] !== $plan['old_source_sha'] && $plan['new_source_sha'] !== $plan['baseline_source_sha'], 'NEW_DECLARED_SOURCE');
    foreach (['before','after'] as $key) {
        pru_require(is_array($plan[$key]), 'PLAN_FILE_PIN'); pru_keys($plan[$key], ['bytes','sha256']);
        pru_require(is_int($plan[$key]['bytes']) && $plan[$key]['bytes'] > 0 && $plan[$key]['bytes'] <= 1048576
            && is_string($plan[$key]['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $plan[$key]['sha256']) === 1, 'TYPED_PLAN_FILE_PIN');
        $plan[$key] = ['bytes' => $plan[$key]['bytes'], 'sha256' => $plan[$key]['sha256']];
    }
    pru_require($plan['before'] !== $plan['after'] && is_array($plan['old_ready']), 'CHANGED_SERVICE_AND_OLD_READY');
    pru_keys($plan['old_ready'], ['source_sha256','source_files','source_directories']);
    pru_require(is_string($plan['old_ready']['source_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $plan['old_ready']['source_sha256']) === 1
        && is_int($plan['old_ready']['source_files']) && $plan['old_ready']['source_files'] > 0
        && is_int($plan['old_ready']['source_directories']) && $plan['old_ready']['source_directories'] > 0, 'TYPED_OLD_READY');
    require_once '/source/help/preview/runtime.php';
    $state = pru_precheck($plan);
    if ($argv[1] === 'apply') {
        $GLOBALS['pru_phase'] = 'prewrite_complete_source_recheck';
        pru_stable_sources($state, $planRaw);
        $GLOBALS['pru_phase'] = 'create_only_public_backups';
        preview_full_runtime_write('/out/service-before.php', $state['oldBytes']);
        preview_full_runtime_write('/out/ready-before.json', $state['ready'], 0600);
        preview_full_runtime_write('/out/plan-summary.json', $planRaw, 0600);
        $GLOBALS['pru_phase'] = 'atomic_service_replacement';
        pru_replace('/runtime-app/' . PRU_SERVICE, $state['oldBytes'], $state['newBytes'], 0644);
        $GLOBALS['pru_phase'] = 'atomic_ready_replacement';
        pru_replace('/runtime-app/.preview-runtime-ready.json', $state['ready'], json_encode($state['newReceipt'], JSON_THROW_ON_ERROR) . "\n", 0600);
    }
    $GLOBALS['pru_phase'] = 'final_complete_source_and_original_runtime_verify';
    pru_stable_sources($state, $planRaw);
    preview_full_runtime_verify($argv[1] === 'apply' ? $state['new'] : $state['old'], $state['identity'],
        $argv[1] === 'apply' ? $state['newReceipt'] : $state['oldReceipt'], $state['ini']);
    $result = ['schema' => 1, 'scope' => 'LOCAL_FULL_RUNTIME_REVIEW_SERVICE_UPDATE', 'mode' => $argv[1],
        'status' => $argv[1] === 'apply' ? 'VERIFIED_PLANNED_CODE_AND_READY_UPDATE_ONLY' : 'VERIFIED_PLANNED_OLD_RUNTIME_AND_SINGLE_SOURCE_CHANGE_ONLY',
        'project' => $plan['project'], 'run_id' => $plan['run_id'], 'old_source_sha' => $plan['old_source_sha'],
        'baseline_source_sha' => $plan['baseline_source_sha'], 'new_source_sha' => $plan['new_source_sha'],
        'relative_path' => PRU_SERVICE, 'before' => $plan['before'], 'after' => $plan['after'],
        'old_ready' => $plan['old_ready'], 'new_ready' => array_intersect_key($state['newReceipt'], array_flip(array_keys($plan['old_ready']))),
        'FPM_stopped' => 'NOT_OBSERVED_CALLER_RESPONSIBILITY', 'INI_or_credentials_published' => false,
        'business_DB_network_or_business_volume_write' => 'NOT_PERFORMED', 'application_or_release_acceptance' => 'NOT_RUN'];
    preview_full_runtime_write('/out/RESULT.json', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0600);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; return 0;
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077); ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1');
    $GLOBALS['pru_phase'] = 'entry'; $GLOBALS['pru_out_ready'] = false;
    set_error_handler(static function (int $severity): bool { if ((error_reporting() & $severity) === 0) return false; throw new RuntimeException('RUNTIME_REVIEW_UPDATE_PHP_WARNING'); });
    try { exit(pru_main($_SERVER['argv'])); }
    catch (Throwable $error) {
        $failure = ['schema' => 1, 'scope' => 'LOCAL_FULL_RUNTIME_REVIEW_SERVICE_UPDATE',
            'status' => 'FAILED_STATE_PRESERVED_NO_RETRY_OR_ROLLBACK', 'phase' => $GLOBALS['pru_phase'],
            'code' => preg_match('/\ARUNTIME_REVIEW_UPDATE_[A-Z_]+\z/D', $error->getMessage()) === 1 ? $error->getMessage() : 'ORIGINAL_VERIFY_OR_OPERATION_REJECTED'];
        $raw = json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if ($GLOBALS['pru_out_ready']) {
            try { $stream = fopen('/out/FAILURE.json', 'xb'); if (is_resource($stream)) { try { fwrite($stream, $raw); fflush($stream); } finally { fclose($stream); } } } catch (Throwable $ignored) {}
        }
        fwrite(STDERR, $raw); exit(1);
    }
}
