
/** Original full37 finish -> independent identities -> real normal close -> the adopted safe exact13. */
function nhep_normal_history_seal(#[SensitiveParameter] array $qualified, PDO $observer, Redis $redis,
    array &$children, array $http, #[SensitiveParameter] array $fixture, #[SensitiveParameter] Closure $finish): array
{
    $oldIgnoreArgs = ini_set('zend.exception_ignore_args', '1');
    nhep_expect($oldIgnoreArgs !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHE_NORMAL_SEAL_REAL_PRIVATE_ARGUMENT_BOUNDARY');
    nhep_observation_parent($qualified);
    $reflection = new ReflectionFunction($finish);
    nhep_expect(!$reflection->isInternal() && $reflection->getFileName() === __FILE__
        && $reflection->getNumberOfRequiredParameters() === 0 && $reflection->getNumberOfParameters() === 0,
        'NHE_NORMAL_SEAL_ORIGINAL_PRIVATE_FINISH_SAME_FROZEN_PRODUCER');
    $accepted37 = $finish(); nheh_snapshot($accepted37);
    $identities = nhep_history_identities(['database' => $accepted37['database'], 'rows' => $accepted37['rows']],
        $fixture, $http);
    nhep_expect($fixture['run_id'] === $qualified['context']['run_id'], 'NHE_NORMAL_SEAL_ACTUAL_ORIGINAL_RUN');
    $close = nhep_normal_close_writers($qualified, $observer, $redis, $children, $accepted37);
    $normalClose = nhep_normal_close_public_view($qualified, $children, $close);
    nhep_expect($close['expected_m4m'] === nhep_migration_snapshot_expected($close['database'], $close['redis']),
        'NHE_NORMAL_SEAL_SAME_ORIGINAL_INDEPENDENT_COMPLETE_BASELINE_BRIDGE');
    $createdPath = '/run/audit/evidence/audit-legacy-history-producer-container-created.json';
    $bindingPath = '/run/audit/evidence/audit-legacy-history-producer-source-binding.json';
    $created = ['path' => $createdPath, 'bytes' => strlen($qualified['created_raw']),
        'sha256' => hash('sha256', $qualified['created_raw'])];
    $binding = ['path' => $bindingPath, 'bytes' => strlen($qualified['binding_raw']),
        'sha256' => hash('sha256', $qualified['binding_raw'])];
    nhep_expect(nhe_file($createdPath, $created) === $qualified['created_raw']
        && nhe_file($bindingPath, $binding) === $qualified['binding_raw'],
        'NHE_NORMAL_SEAL_SAME_ORIGINAL_PUBLIC_CREATED_AND_SOURCE_BINDING');
    $parent = [];
    foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $key) $parent[$key] = $qualified['process'][$key];
    $frames = [];
    // These four facts follow only the original whole37 finish and original full
    // native/frame/whole7 accept path above; there is no caller-provided ack/count.
    foreach ([13 => 'refund_family_pay', 19 => 'review_family_pay', 22 => 'unshipped_refund_apply',
        32 => 'customer_purchased_item_comment'] as $sequence => $step) {
        nhep_expect($http['http_steps'][$sequence - 1] === ['step' => $step, 'http' => 200],
            'NHE_NORMAL_SEAL_SAME_FOUR_ORIGINAL_COMPLETE_FRAME_ACCEPTANCE_SITES');
        $frames[] = ['sequence' => $sequence, 'step' => $step, 'complete_original_frame_verified' => true];
    }
    $seal = ['schema' => 1, 'status' => 'LEGACY_NORMAL_HISTORY_COMPLETE_ONLY',
        'run_id' => $qualified['context']['run_id'], 'source_sha' => $qualified['context']['source_sha'],
        'legacy_source_sha' => $qualified['authority']['legacy_source_sha'], 'freeze' => $qualified['freeze_pin'],
        'producer' => ['container_id' => $qualified['container_id'], 'created' => $created, 'source_binding' => $binding,
            'control_files' => $qualified['authority']['control_files'], 'native_parent' => $parent],
        'http' => $http, 'identities' => $identities, 'business_frames' => $frames, 'normal_close' => $normalClose,
        'expected_m4m' => $close['expected_m4m'], 'seal_redis_time' => $close['redis']['time']];
    nhe_keys($seal, ['schema', 'status', 'run_id', 'source_sha', 'legacy_source_sha', 'freeze', 'producer',
        'http', 'identities', 'business_frames', 'normal_close', 'expected_m4m', 'seal_redis_time'],
        'NHE_NORMAL_SEAL_ADOPTED_UNIQUE_EXACT_THIRTEEN_FIELDS');
    nhep_children_closed($children); nhep_parent_qualified($qualified); nhep_observation_parent($qualified);
    // All original accepted rows, values, private file/body hashes and original
    // receipts remain RAM; only the separately verified fixed safe view escapes.
    unset($accepted37, $close, $fixture); return $seal;
}

/** Create only the one fixed safe result; native same-handle full write/readback, no private inputs. */
function nhep_normal_history_publish(array $qualified, array $seal): array
{
    nhep_observation_parent($qualified);
    nhe_keys($seal, ['schema', 'status', 'run_id', 'source_sha', 'legacy_source_sha', 'freeze', 'producer',
        'http', 'identities', 'business_frames', 'normal_close', 'expected_m4m', 'seal_redis_time'],
        'NHE_NORMAL_PUBLISH_ONLY_ADOPTED_EXACT_THIRTEEN_FIELDS');
    nhep_expect($seal['schema'] === 1 && $seal['status'] === 'LEGACY_NORMAL_HISTORY_COMPLETE_ONLY'
        && $seal['run_id'] === $qualified['context']['run_id'] && $seal['source_sha'] === $qualified['context']['source_sha']
        && $seal['legacy_source_sha'] === $qualified['authority']['legacy_source_sha']
        && $seal['freeze'] === $qualified['freeze_pin'], 'NHE_NORMAL_PUBLISH_SAME_ACTUAL_FROZEN_SOURCE_RUN');
    $root = '/run/audit/evidence'; $path = $root . '/legacy-history-producer-result.json';
    clearstatcache(true, $root); $directory = lstat($root);
    nhep_expect(is_array($directory) && is_dir($root) && !is_link($root) && realpath($root) === $root
        && ($directory['mode'] & 07777) === 0700 && $directory['uid'] === posix_geteuid()
        && !file_exists($path) && !is_link($path), 'NHE_NORMAL_PUBLISH_CREATE_ONLY_ORIGINAL_PRIVATE_EVIDENCE_DIRECTORY');
    $raw = nhe_json($seal); $mask = umask(0077);
    try { $stream = fopen($path, 'x+b'); } finally { umask($mask); }
    nhep_expect(is_resource($stream), 'NHE_NORMAL_PUBLISH_ACTUAL_CREATE_ONLY_RESULT_HANDLE');
    $failure = null;
    try {
        $opened = fstat($stream); clearstatcache(true, $path); $named = lstat($path);
        nhep_expect(is_array($opened) && is_array($named) && !is_link($path) && realpath($path) === $path
            && ($opened['mode'] & 0170000) === 0100000 && ($opened['mode'] & 07777) === 0600
            && $opened['uid'] === posix_geteuid() && $opened['nlink'] === 1 && $opened['size'] === 0
            && $opened['dev'] === $directory['dev'], 'NHE_NORMAL_PUBLISH_ORIGINAL_OWN_REGULAR_SINGLE_LINK_RESULT');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key)
            nhep_expect($opened[$key] === $named[$key], 'NHE_NORMAL_PUBLISH_SAME_NAMED_AND_OPENED_NATIVE_STAT9');
        $offset = 0;
        while ($offset < strlen($raw)) {
            $written = fwrite($stream, substr($raw, $offset));
            nhep_expect(is_int($written) && $written > 0, 'NHE_NORMAL_PUBLISH_FULL_ACTUAL_SAFE_BYTES_WRITE');
            $offset += $written;
        }
        nhep_expect(fflush($stream) && fseek($stream, 0) === 0, 'NHE_NORMAL_PUBLISH_FLUSH_AND_SAME_HANDLE_REWIND');
        $readback = stream_get_contents($stream);
        nhep_expect(is_string($readback) && $readback === $raw && feof($stream),
            'NHE_NORMAL_PUBLISH_COMPLETE_SAFE_RESULT_SAME_HANDLE_READBACK_AND_EOF');
        $sealed = fstat($stream); clearstatcache(true, $path); $after = lstat($path);
        nhep_expect(is_array($sealed) && is_array($after) && $sealed['size'] === strlen($raw)
            && !is_link($path) && realpath($path) === $path, 'NHE_NORMAL_PUBLISH_COMPLETE_NATIVE_RESULT_SIZE');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
            nhep_expect($sealed[$key] === $opened[$key], 'NHE_NORMAL_PUBLISH_ORIGINAL_HANDLE_IDENTITY_PRESERVED');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key)
            nhep_expect($sealed[$key] === $after[$key], 'NHE_NORMAL_PUBLISH_FINAL_SAME_NATIVE_PATH_HANDLE_STAT9');
    } catch (Throwable $error) { $failure = $error; throw $error;
    } finally {
        try { nhep_expect(fclose($stream), 'NHE_NORMAL_PUBLISH_REAL_ORIGINAL_HANDLE_CLOSED');
        } catch (Throwable $closeError) { if ($failure === null) throw $closeError; }
    }
    $pin = ['path' => $path, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
    nhep_expect(nhe_file($path, $pin) === $raw, 'NHE_NORMAL_PUBLISH_COMPLETE_CLOSED_NAMED_RESULT_READBACK');
    return $pin;
}

/** Full fixed parent algorithm. Direct admission remains refused until all original Source gaps close. */
function nhep_normal_history_main(): int
{
    $children = []; $channel = null; $failure = null; $cleanup = [];
    try {
        ini_set('display_errors', '0'); ini_set('log_errors', '0');
        $oldIgnoreArgs = ini_set('zend.exception_ignore_args', '1'); umask(0077);
        nhep_expect($oldIgnoreArgs !== false && ini_get('zend.exception_ignore_args') === '1',
            'NHE_NORMAL_MAIN_REAL_PRIVATE_ARGUMENT_BOUNDARY');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new RuntimeException('NHE_NORMAL_MAIN_PHP_RUNTIME_WARNING');
        });
        nhep_expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300
            && __FILE__ === '/run/nonempty/legacy-history-producer.php' && realpath(__FILE__) === __FILE__
            && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && getcwd() === '/legacy-workspace/crmeb',
            'NHE_NORMAL_MAIN_ORIGINAL_REAL_DIRECT_ENTRY');
        global $argv; $arguments = $_SERVER['argv'] ?? null;
        nhep_expect(is_array($arguments) && $arguments === $argv && array_is_list($arguments) && count($arguments) === 4
            && $arguments[0] === __FILE__ && $arguments[1] === 'normal'
            && preg_match('/\A--freeze-bytes=([1-9][0-9]{0,9})\z/D', $arguments[2], $bytes) === 1
            && preg_match('/\A--freeze-sha256=([a-f0-9]{64})\z/D', $arguments[3], $sha) === 1,
            'NHE_NORMAL_MAIN_EXACT_ORIGINAL_NORMAL_AND_FREEZE_ARGUMENTS');
        // Named real Source gaps, before loading App, writing parent or connecting
        // resources. They are intentionally absent in this not-yet-admitted Source.
        foreach (['nhep_normal_startup_plan', 'nhep_normal_startup_accept'] as $dependency) {
            nhep_expect(function_exists($dependency), 'NHE_NORMAL_MAIN_COMPLETE_STARTUP_EFFECTS_SOURCE_UNBOUND');
            $reflection = new ReflectionFunction($dependency);
            nhep_expect(!$reflection->isInternal() && $reflection->getFileName() === __FILE__,
                'NHE_NORMAL_MAIN_STARTUP_ORACLE_SAME_FROZEN_PRODUCER_REQUIRED');
        }
        nhep_expect(spl_autoload_functions() === [] && !function_exists('app'), 'NHE_NORMAL_MAIN_NO_PRELOADED_VENDOR');
        foreach (get_declared_classes() as $class)
            nhep_expect(preg_match('/\A(?:app|think|crmeb|Channel|Workerman|Composer)\\\\/i', $class) !== 1
                && !str_starts_with($class, 'ComposerAutoloaderInit'), 'NHE_NORMAL_MAIN_NO_PRELOADED_APP_CLASS');
        require_once __DIR__ . '/legacy-app.php';
        $freeze = ['path' => '/run/audit/preparation-source-binding.json', 'bytes' => (int)$bytes[1], 'sha256' => $sha[1]];
        $qualified = nhe_qualify($freeze, 'audit-legacy-history-producer');
        nhep_parent_qualified($qualified); nhep_parent_write($qualified);
        $observer = audit_db(); $redis = audit_redis();
        $prepared = nhep_pre37_fixed_preparation($qualified, $observer, $redis);
        $startupPlan = nhep_normal_startup_plan($qualified, $prepared);
        nhep_child_start($qualified, $children, 'channel');
        $channel = nhep_channel_open($qualified, $children['channel']['observe']);
        nhep_child_subscriber($children, $channel);
        nhep_child_start($qualified, $children, 'http');
        $startupPrivate = []; $startupActual = nhep_observation($qualified, $observer, $redis, $children, $startupPrivate);
        $startupExpected = nhep_normal_startup_accept($qualified, $observer, $redis, $children, $prepared,
            $startupPlan, $startupActual, $startupPrivate);
        nheh_snapshot($startupExpected);
        nhep_expect($startupActual === $startupExpected, 'NHE_NORMAL_MAIN_COMPLETE_INDEPENDENT_STARTUP_SEVEN_PARTS');
        $oracles = nhep_auth_observers($qualified, $observer, $redis, $children);
        nhe_keys($oracles, ['before', 'after', 'finish'], 'NHE_NORMAL_MAIN_ORIGINAL_WHOLE37_THREE_CLOSURES');
        $http = nhe_history_http_run($qualified, $prepared['origin'], $qualified['context']['run_id'],
            $prepared['actors'], $prepared['fixture_receipt'], $oracles['before'], $oracles['after']);
        $seal = nhep_normal_history_seal($qualified, $observer, $redis, $children, $http,
            $prepared['fixture_receipt'], $oracles['finish']);
        nhep_normal_history_publish($qualified, $seal);
        unset($prepared, $startupPlan, $startupPrivate, $startupActual, $startupExpected, $oracles, $channel);
    } catch (Throwable $error) { $failure = $error;
    } finally {
        // A cleanup failure never replaces the first business failure. These are
        // the same original normal calls/handles, with no restart/force/escalation.
        if ($failure !== null) {
            if (isset($children['http'])) {
                try { $receipt = nhep_child_close($children, 'http'); $cleanup['http_stopped'] = $receipt['stopped'] === true;
                } catch (Throwable $error) { $cleanup['http_stopped'] = false; }
            }
            if (is_array($channel) && ($channel['closed'] ?? null) === false) {
                try { nhep_channel_close($channel); $cleanup['same_subscriber_closed'] = true;
                } catch (Throwable $error) { $cleanup['same_subscriber_closed'] = false; }
            }
            if (isset($children['channel'])) {
                try { $receipt = nhep_child_close($children, 'channel'); $cleanup['channel_stopped'] = $receipt['stopped'] === true;
                } catch (Throwable $error) { $cleanup['channel_stopped'] = false; }
            }
        }
    }
    if ($failure !== null) {
        $code = $failure instanceof RuntimeException ? $failure->getMessage() : '';
        if (preg_match('/\A(?:NHE|NHEH)_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'NHE_NORMAL_MAIN_ORIGINAL_HISTORY_FAILED';
        $class = get_class($failure);
        if (preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*\z/D', $class) !== 1) $class = 'Throwable';
        fwrite(STDERR, json_encode(['status' => 'FAIL', 'code' => $code, 'class' => $class,
            'normal_cleanup' => $cleanup], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        return 1;
    }
    return 0;
}
