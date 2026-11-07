<?php
declare(strict_types=1);

/** One planned local full-runtime service update. The caller stops FPM and affected persistent workers. */
const PRU_SERVICE_PATHS = [
    'app/services/marketplace/order/MarketplaceOrderReviewServices.php',
    'app/services/marketplace/order/MarketplaceOrderRefundFacts.php',
    'crmeb/services/workerman/chat/ChatHandle.php',
];
const PRU_ADDRESS_SCOPE = 'LOCAL_FULL_RUNTIME_USER_ADDRESS_UPDATE';
const PRU_ADDRESS_PATHS = [
    'app/api/controller/v1/user/UserAddressController.php',
    'app/services/user/UserAddressServices.php',
];
const PRU_STOREFRONT_PAUSE_SCOPE = 'LOCAL_FULL_RUNTIME_STOREFRONT_PAUSE_CONTROLS_UPDATE';
const PRU_STOREFRONT_PAUSE_PATHS = [
    'app/api/controller/v2/store/StoreCartController.php',
    'app/api/controller/v2/store/StoreProductController.php',
    'app/services/marketplace/PublicCatalogServices.php',
    'app/services/marketplace/publiccatalog/PublicCatalogProjection.php',
];
const PRU_PAUSED_CART_SCOPE = 'LOCAL_FULL_RUNTIME_PAUSED_CART_DISPLAY_UPDATE';
const PRU_PAUSED_CART_PATHS = [
    'app/api/controller/v2/store/StoreCartController.php',
    'app/services/marketplace/PublicCatalogServices.php',
    'app/services/order/StoreCartServices.php',
];
const PRU_FINANCE_SCOPE = 'LOCAL_FULL_RUNTIME_FINANCE_UPDATE';
const PRU_FINANCE_BASELINE = 'd10a84f2cc619474316a8b3ea64cc97b2ff20f37';
// True means a new file, absent from both the complete baseline and the running application.
const PRU_FINANCE_FILES = [
    'app/adminapi/controller/v1/marketplace/FinanceBillNotes.php' => true,
    'app/adminapi/controller/v1/marketplace/FinanceBills.php' => true,
    'app/adminapi/controller/v1/marketplace/FinanceStatements.php' => true,
    'app/adminapi/route/marketplace.php' => false,
    'app/merchantapi/controller/FinanceStatements.php' => true,
    'app/merchantapi/route/route.php' => false,
    'app/services/marketplace/AuditLogServices.php' => false,
    'app/services/marketplace/MerchantPermissions.php' => false,
    'app/services/marketplace/PlatformAccessServices.php' => false,
    'app/services/marketplace/finance/FinanceBillInput.php' => true,
    'app/services/marketplace/finance/FinanceBillNoteStore.php' => true,
    'app/services/marketplace/finance/FinanceBillReconciliation.php' => true,
    'app/services/marketplace/finance/FinanceBillSource.php' => true,
    'app/services/marketplace/finance/FinanceBillStore.php' => true,
    'app/services/marketplace/finance/FinanceBillsServices.php' => true,
    'app/services/marketplace/finance/FinanceSettlementCalculation.php' => true,
    'app/services/marketplace/finance/FinanceStatementProjection.php' => true,
    'app/services/marketplace/finance/FinanceStatementStore.php' => true,
    'app/services/marketplace/payment/MarketplaceRefundServices.php' => false,
];
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
    $files = $plan['files'];
    $old = pru_manifest('/baseline/crmeb'); $new = pru_manifest('/source/crmeb');
    $finance = $plan['scope'] === PRU_FINANCE_SCOPE;
    if ($finance) {
        pru_require(array_diff_key($old, $new) === [], 'NO_DELETED_SOURCE_PATH');
        $directories = static fn(array $manifest): array => array_keys(array_filter($manifest,
            static fn(array $row): bool => $row['type'] === 'directory'));
        pru_require($directories($old) === $directories($new), 'SAME_COMPLETE_SOURCE_DIRECTORIES');
    } else pru_require(array_keys($old) === array_keys($new), 'SAME_COMPLETE_SOURCE_NAMESPACE');
    $changes = [];
    foreach ($new as $path => $row) if (!isset($old[$path]) || $row !== $old[$path]) $changes[] = $path;
    pru_require($changes === array_keys($files), 'ONLY_APPROVED_SOURCE_PATH_CHANGED');
    $oldBytes = []; $newBytes = [];
    foreach ($files as $path => $pins) {
        $added = $pins['before'] === null;
        if ($added) {
            pru_require($finance && PRU_FINANCE_FILES[$path] && !isset($old[$path]), 'FIXED_NEW_SOURCE_ABSENT');
            pru_absent('/baseline/crmeb/' . $path, 'NEW_BASELINE_PATH_ABSENT');
        }
        foreach (['before' => $old, 'after' => $new] as $key => $manifest) {
            if ($key === 'before' && $added) continue;
            pru_require(($manifest[$path] ?? null) === ['type' => 'file', 'size' => $pins[$key]['bytes'],
                'sha256' => $pins[$key]['sha256']], 'EXACT_PLANNED_SOURCE_PIN');
        }
        $oldBytes[$path] = $added ? null : pru_read('/baseline/crmeb/' . $path);
        $newBytes[$path] = pru_read('/source/crmeb/' . $path);
        pru_require(($added || pru_pin($oldBytes[$path]) === $pins['before']) && pru_pin($newBytes[$path]) === $pins['after'],
            'PLANNED_COMPLETE_SERVICE_BYTES');
        $live = '/runtime-app/' . $path;
        if ($added) pru_absent($live, 'NEW_RUNTIME_PATH_ABSENT');
        else {
            pru_file_mode($live, 0644);
            pru_require(pru_read($live) === $oldBytes[$path], 'ACTUAL_OLD_SERVICE_AND_READY');
        }
    }
    $identity = ['project' => $plan['project'], 'run_id' => $plan['run_id'], 'feature' => 'full-runtime-v1'];
    $oldReceipt = pru_receipt($identity, $old); $newReceipt = pru_receipt($identity, $new);
    foreach ($plan['old_ready'] as $key => $value) pru_require($oldReceipt[$key] === $value, 'EXACT_OLD_READY_SOURCE');
    $ready = pru_read('/runtime-app/.preview-runtime-ready.json'); $ini = pru_read('/runtime-app/.env');
    pru_file_mode('/runtime-app/.preview-runtime-ready.json', 0600);
    pru_require($ready === json_encode($oldReceipt, JSON_THROW_ON_ERROR) . "\n", 'ACTUAL_OLD_SERVICE_AND_READY');
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
function pru_absent(string $path, string $code): void
{
    clearstatcache(true, $path);
    pru_require(!file_exists($path) && !is_link($path), $code);
    pru_require(realpath(dirname($path)) === dirname($path), 'EXISTING_REAL_PARENT');
}
function pru_stage(string $path, ?string $before, string $after, int $mode): string
{
    $temporary = $path . '.preview-review-update.tmp';
    pru_absent($temporary, 'NEW_ATOMIC_TEMPORARY_ONLY');
    if ($before === null) pru_absent($path, 'NEW_RUNTIME_PATH_ABSENT');
    else {
        pru_file_mode($path, $mode); pru_require(pru_read($path) === $before, 'UNCHANGED_ATOMIC_TARGET');
    }
    preview_full_runtime_write($temporary, $after, $mode);
    pru_file_mode($temporary, $mode); pru_require(pru_read($temporary) === $after, 'COMPLETE_ATOMIC_TEMPORARY');
    return $temporary;
}
function pru_promote(string $path, string $temporary, ?string $before, string $after, int $mode): void
{
    pru_file_mode($temporary, $mode); pru_require(pru_read($temporary) === $after, 'COMPLETE_ATOMIC_TEMPORARY');
    if ($before === null) {
        pru_absent($path, 'NEW_RUNTIME_PATH_ABSENT');
        // link publishes the complete staged inode atomically and never replaces an existing path.
        pru_require(link($temporary, $path), 'ATOMIC_NEW_FILE_LINK');
        pru_require(unlink($temporary), 'REMOVE_NEW_FILE_STAGING_LINK');
    } else {
        pru_require(pru_read($path) === $before, 'ATOMIC_TARGET_RECHECK'); pru_file_mode($path, $mode);
        pru_require(rename($temporary, $path), 'ATOMIC_RENAME');
    }
    pru_file_mode($path, $mode); pru_require(pru_read($path) === $after, 'ATOMIC_REPLACEMENT_READBACK');
}
function pru_file_pins(array $pins, bool $added = false): array
{
    pru_keys($pins, ['before','after']);
    pru_require(($pins['before'] === null) === $added, 'FIXED_NEW_FILE_BEFORE_PIN');
    foreach ($pins as $key => $pin) {
        if ($key === 'before' && $added) continue;
        pru_require(is_array($pin), 'PLAN_FILE_PIN'); pru_keys($pin, ['bytes','sha256']);
        pru_require(is_int($pin['bytes']) && $pin['bytes'] > 0 && $pin['bytes'] <= 1048576
            && is_string($pin['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $pin['sha256']) === 1, 'TYPED_PLAN_FILE_PIN');
        $pins[$key] = ['bytes' => $pin['bytes'], 'sha256' => $pin['sha256']];
    }
    pru_require($pins['before'] !== $pins['after'], 'CHANGED_SERVICE_AND_OLD_READY');
    return $pins;
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
    if (($plan['schema'] ?? null) === 2 && ($plan['scope'] ?? null) === PRU_FINANCE_SCOPE) $GLOBALS['pru_scope'] = PRU_FINANCE_SCOPE;
    $multiFilePaths = ($plan['schema'] ?? null) === 2 ? match ($plan['scope'] ?? null) {
        PRU_ADDRESS_SCOPE => PRU_ADDRESS_PATHS,
        PRU_STOREFRONT_PAUSE_SCOPE => PRU_STOREFRONT_PAUSE_PATHS,
        PRU_PAUSED_CART_SCOPE => PRU_PAUSED_CART_PATHS,
        PRU_FINANCE_SCOPE => array_keys(PRU_FINANCE_FILES),
        default => null,
    } : null;
    $planKeys = ['schema','scope','project','run_id','old_source_sha','baseline_source_sha','new_source_sha','old_ready'];
    if ($multiFilePaths !== null) {
        pru_keys($plan, [...$planKeys, 'files']);
        $pathError = match ($plan['scope']) {
            PRU_ADDRESS_SCOPE => 'FIXED_ADDRESS_PATHS',
            PRU_STOREFRONT_PAUSE_SCOPE => 'FIXED_STOREFRONT_PAUSE_PATHS',
            PRU_PAUSED_CART_SCOPE => 'FIXED_PAUSED_CART_PATHS',
            PRU_FINANCE_SCOPE => 'FIXED_FINANCE_PATHS',
        };
        pru_require(is_array($plan['files']) && array_keys($plan['files']) === $multiFilePaths, $pathError);
    } else {
        pru_keys($plan, [...$planKeys, 'relative_path','before','after']);
        pru_require($plan['schema'] === 1 && $plan['scope'] === 'LOCAL_FULL_RUNTIME_REVIEW_SERVICE_UPDATE'
            && in_array($plan['relative_path'], PRU_SERVICE_PATHS, true), 'FIXED_PLAN_INSTANCE_AND_PATH');
        $plan['files'] = [$plan['relative_path'] => ['before' => $plan['before'], 'after' => $plan['after']]];
    }
    pru_require(is_string($plan['run_id']) && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $plan['run_id']) === 1
        && $plan['project'] === 'mall-preview-' . $plan['run_id'], 'FIXED_PLAN_INSTANCE_AND_PATH');
    foreach (['old_source_sha','baseline_source_sha','new_source_sha'] as $key)
        pru_require(is_string($plan[$key]) && preg_match('/\A[a-f0-9]{40}\z/D', $plan[$key]) === 1, 'PLAN_COMMIT_ID');
    if ($plan['scope'] === PRU_FINANCE_SCOPE) {
        pru_require($plan['old_source_sha'] === PRU_FINANCE_BASELINE && $plan['baseline_source_sha'] === PRU_FINANCE_BASELINE,
            'FIXED_FINANCE_BASELINE');
    }
    pru_require($plan['new_source_sha'] !== $plan['old_source_sha'] && $plan['new_source_sha'] !== $plan['baseline_source_sha'], 'NEW_DECLARED_SOURCE');
    foreach ($plan['files'] as $path => $pins) {
        pru_require(is_array($pins), 'PLAN_FILE_PIN');
        $plan['files'][$path] = pru_file_pins($pins, $plan['scope'] === PRU_FINANCE_SCOPE && PRU_FINANCE_FILES[$path]);
    }
    pru_require(is_array($plan['old_ready']), 'CHANGED_SERVICE_AND_OLD_READY');
    $GLOBALS['pru_scope'] = $plan['scope'];
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
        foreach ($state['oldBytes'] as $path => $bytes) {
            if ($bytes === null) continue;
            $backup = $plan['scope'] === PRU_FINANCE_SCOPE ? rawurlencode($path) . '.before'
                : ($multiFilePaths !== null ? basename($path, '.php') . '-before.php' : 'service-before.php');
            preview_full_runtime_write('/out/' . $backup, $bytes);
        }
        preview_full_runtime_write('/out/ready-before.json', $state['ready'], 0600);
        preview_full_runtime_write('/out/plan-summary.json', $planRaw, 0600);
        $GLOBALS['pru_phase'] = 'stage_code_and_ready';
        $staged = [];
        foreach ($state['oldBytes'] as $path => $bytes)
            $staged[$path] = pru_stage('/runtime-app/' . $path, $bytes, $state['newBytes'][$path], 0644);
        $readyPath = '/runtime-app/.preview-runtime-ready.json';
        $newReady = json_encode($state['newReceipt'], JSON_THROW_ON_ERROR) . "\n";
        $readyStage = pru_stage($readyPath, $state['ready'], $newReady, 0600);
        $GLOBALS['pru_phase'] = 'atomic_service_replacement';
        foreach ($state['oldBytes'] as $path => $bytes)
            pru_promote('/runtime-app/' . $path, $staged[$path], $bytes, $state['newBytes'][$path], 0644);
        $GLOBALS['pru_phase'] = 'atomic_ready_replacement';
        pru_promote($readyPath, $readyStage, $state['ready'], $newReady, 0600);
    }
    $GLOBALS['pru_phase'] = 'final_complete_source_and_original_runtime_verify';
    pru_stable_sources($state, $planRaw);
    preview_full_runtime_verify($argv[1] === 'apply' ? $state['new'] : $state['old'], $state['identity'],
        $argv[1] === 'apply' ? $state['newReceipt'] : $state['oldReceipt'], $state['ini']);
    $checkStatus = match ($plan['scope']) {
        PRU_ADDRESS_SCOPE => 'VERIFIED_PLANNED_OLD_RUNTIME_AND_ADDRESS_CHANGES_ONLY',
        PRU_STOREFRONT_PAUSE_SCOPE => 'VERIFIED_PLANNED_OLD_RUNTIME_AND_STOREFRONT_PAUSE_CONTROLS_CHANGES_ONLY',
        PRU_PAUSED_CART_SCOPE => 'VERIFIED_PLANNED_OLD_RUNTIME_AND_PAUSED_CART_DISPLAY_CHANGES_ONLY',
        PRU_FINANCE_SCOPE => 'VERIFIED_PLANNED_OLD_RUNTIME_AND_FINANCE_ADDITIONS_ONLY',
        default => 'VERIFIED_PLANNED_OLD_RUNTIME_AND_SINGLE_SOURCE_CHANGE_ONLY',
    };
    $applyStatus = match ($plan['scope']) {
        PRU_STOREFRONT_PAUSE_SCOPE => 'VERIFIED_PLANNED_STOREFRONT_PAUSE_CONTROLS_CODE_AND_READY_UPDATE_ONLY',
        PRU_PAUSED_CART_SCOPE => 'VERIFIED_PLANNED_PAUSED_CART_DISPLAY_CODE_AND_READY_UPDATE_ONLY',
        PRU_FINANCE_SCOPE => 'VERIFIED_PLANNED_FINANCE_CODE_AND_READY_UPDATE_ONLY',
        default => 'VERIFIED_PLANNED_CODE_AND_READY_UPDATE_ONLY',
    };
    $fileResult = ['files' => $plan['files']];
    if ($multiFilePaths === null) {
        $path = $plan['relative_path'];
        $fileResult = ['relative_path' => $path] + $plan['files'][$path];
    }
    $result = ['schema' => $plan['schema'], 'scope' => $plan['scope'], 'mode' => $argv[1],
        'status' => $argv[1] === 'apply' ? $applyStatus : $checkStatus,
        'project' => $plan['project'], 'run_id' => $plan['run_id'], 'old_source_sha' => $plan['old_source_sha'],
        'baseline_source_sha' => $plan['baseline_source_sha'], 'new_source_sha' => $plan['new_source_sha']]
        + $fileResult + ['old_ready' => $plan['old_ready'], 'new_ready' => array_intersect_key($state['newReceipt'], array_flip(array_keys($plan['old_ready']))),
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
        $scope = $GLOBALS['pru_scope'] ?? 'LOCAL_FULL_RUNTIME_REVIEW_SERVICE_UPDATE';
        $failure = ['schema' => in_array($scope, [PRU_ADDRESS_SCOPE, PRU_STOREFRONT_PAUSE_SCOPE, PRU_PAUSED_CART_SCOPE, PRU_FINANCE_SCOPE], true) ? 2 : 1, 'scope' => $scope,
            'status' => 'FAILED_STATE_PRESERVED_NO_RETRY_OR_ROLLBACK', 'phase' => $GLOBALS['pru_phase'],
            'code' => preg_match('/\ARUNTIME_REVIEW_UPDATE_[A-Z_]+\z/D', $error->getMessage()) === 1 ? $error->getMessage() : 'ORIGINAL_VERIFY_OR_OPERATION_REJECTED'];
        $raw = json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if ($GLOBALS['pru_out_ready']) {
            try { $stream = fopen('/out/FAILURE.json', 'xb'); if (is_resource($stream)) { try { fwrite($stream, $raw); fflush($stream); } finally { fclose($stream); } } } catch (Throwable $ignored) {}
        }
        fwrite(STDERR, $raw); exit(1);
    }
}
