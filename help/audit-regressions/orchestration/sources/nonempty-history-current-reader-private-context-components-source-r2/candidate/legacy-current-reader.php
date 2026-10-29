<?php
declare(strict_types=1);

/** Source-only components. No role qualification, transport or application is run here. */
function nhecr_expect(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException($code);
}
function nhecr_keys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    nhecr_expect($actual === $keys, 'NHECR_EXACT_COMPONENT_KEYS');
}

/** Pure join of the actual existing driver schema. This does not authorize a new reader. */
function nhecr_four_cli_join(#[SensitiveParameter] array $independentBaseline, array $context,
    string $freezeRaw, string $createdRaw, string $terminalRaw, string $bindingRaw, string $resultRaw): array
{
    nhecr_expect(function_exists('m4m_preserved') && defined('M4M_IMAGE') && defined('M4M_ADDITIONS'),
        'NHECR_REAL_CURRENT_M4M_PURE_DEFINITIONS_REQUIRED');
    $objects = [];
    foreach (['freeze' => $freezeRaw, 'created' => $createdRaw, 'terminal' => $terminalRaw,
        'binding' => $bindingRaw, 'result' => $resultRaw] as $key => $raw) {
        nhecr_expect($raw !== '' && strlen($raw) <= 67108864, 'NHECR_COMPLETE_BOUNDED_PUBLIC_RAW');
        $object = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
        nhecr_expect(is_array($object) && !array_is_list($object), 'NHECR_PUBLIC_OBJECT'); $objects[$key] = $object;
    }
    ['freeze' => $freeze, 'created' => $made, 'terminal' => $terminal, 'binding' => $binding, 'result' => $result] = $objects;
    nhecr_expect(is_string($context['run_id'] ?? null) && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $context['run_id']) === 1
        && is_string($context['source_sha'] ?? null) && preg_match('/\A[a-f0-9]{40}\z/D', $context['source_sha']) === 1
        && ($context['project'] ?? null) === 'tianli-audit-' . $context['run_id']
        && ($context['freeze_bytes'] ?? null) === strlen($freezeRaw)
        && ($context['freeze_sha256'] ?? null) === hash('sha256', $freezeRaw)
        && ($freeze['status'] ?? null) === 'CLEAN_SOURCE_FROZEN_ONLY'
        && ($freeze['source_sha'] ?? null) === $context['source_sha'], 'NHECR_REAL_CONTEXT_AND_FREEZE');
    nhecr_keys($binding, ['schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source',
        'source_files', 'entry_sha256', 'cli_sha256']);
    nhecr_expect($binding['schema'] === 1 && $binding['run_id'] === $context['run_id']
        && $binding['source_sha'] === $context['source_sha']
        && $binding['requested_repo_source'] === $context['repo_dir'] && $binding['requested_run_source'] === $context['run_dir']
        && is_array($freeze['driver_source_files'] ?? null) && $binding['source_files'] === $freeze['driver_source_files'],
        'NHECR_REAL_ORIGINAL_HOST_DRIVER_BINDING');
    $sources = [];
    foreach ($binding['source_files'] as $row) {
        nhecr_keys($row, ['path', 'bytes', 'sha256']);
        nhecr_expect(is_string($row['path']) && !isset($sources[$row['path']]) && is_int($row['bytes']) && $row['bytes'] > 0
            && is_string($row['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1, 'NHECR_FROZEN_SOURCE_DESCRIPTOR');
        $sources[$row['path']] = $row;
    }
    $paths = array_keys($sources); sort($paths, SORT_STRING);
    $wanted = ['crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
        'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms4-migration.php',
        'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
        'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms4.php'];
    sort($wanted, SORT_STRING);
    nhecr_expect($paths === $wanted
        && $binding['entry_sha256'] === $sources['help/audit-regressions/isolated/ms4-migration.php']['sha256']
        && $binding['cli_sha256'] === $sources['help/migrations/marketplace-ms4.php']['sha256'], 'NHECR_ORIGINAL_EIGHT_SOURCE_CLOSURE');
    $cid = $made['id'] ?? null;
    nhecr_expect(is_string($cid) && preg_match('/\A[a-f0-9]{64}\z/D', $cid) === 1
        && $made['run_id'] === $context['run_id'] && $made['source_sha'] === $context['source_sha']
        && $made['proof_name'] === 'driver' && $made['image'] === M4M_IMAGE
        && $made['read_only'] === true && $made['privileged'] === false && $made['cap_drop'] === ['ALL']
        && in_array($made['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && $made['network_mode'] === $context['project'] . '_isolation'
        && $made['labels']['tianli.audit'] === '1' && $made['labels']['tianli.audit.run'] === $context['run_id']
        && $made['labels']['com.docker.compose.project'] === $context['project']
        && $made['labels']['com.docker.compose.service'] === 'audit-ms4-migration'
        && $made['status'] === 'created' && $made['pid'] === 0 && $made['restart_count'] === 0
        && $made['entrypoint'] === ['php'] && $made['command'] === ['help/audit-regressions/isolated/ms4-migration.php']
        && $made['workdir'] === '/workspace', 'NHECR_ORIGINAL_DRIVER_CREATED_IDENTITY');
    foreach (['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode',
        'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $key)
        nhecr_expect($made[$key] === $terminal[$key], 'NHECR_ORIGINAL_IMMUTABLE_DRIVER_FIELDS');
    $mounts = static function (array $rows): array {
        $values = []; foreach ($rows as $row) { ksort($row, SORT_STRING); $values[] = json_encode($row, JSON_THROW_ON_ERROR); }
        sort($values, SORT_STRING); return $values;
    };
    nhecr_expect($mounts($made['mounts']) === $mounts($terminal['mounts'])
        && $terminal['status'] === 'exited'
        && $terminal['pid'] === 0 && $terminal['exit_code'] === 0 && $terminal['restart_count'] === 0,
        'NHECR_SAME_DRIVER_REAL_NATIVE_ZERO');
    nhecr_keys($result, ['schema', 'status', 'scope', 'children', 'snapshots', 'stopped_cases', 'business_acceptance',
        'binding', 'legacy_added_field_anomalies', 'actual_command_counts', 'original_table_count',
        'target_original_rows', 'private_cleanup_verified', 'phase', 'native_exit']);
    nhecr_keys($result['binding'], ['container_id', 'entry_namespace_pid', 'source_sha', 'entry_sha256',
        'cli_sha256', 'binding_sha256', 'native_created_sha256']);
    nhecr_expect($result['schema'] === 1 && $result['status'] === 'VERIFIED_NORMAL_STRUCTURE_ONLY'
        && $result['scope'] === 'NORMAL_STRUCTURE_ONLY' && $result['native_exit'] === 0
        && $result['private_cleanup_verified'] === true && $result['business_acceptance'] === 'NOT RUN'
        && $result['stopped_cases'] === ['denial_conflict_concurrency_fault_restore_paid' => 'NOT RUN']
        && $result['actual_command_counts'] === ['check' => 2, 'apply-structure' => 2]
        && $result['phase'] === 'apply-structure_repeat'
        && $result['binding'] === ['container_id' => $cid, 'entry_namespace_pid' => $result['binding']['entry_namespace_pid'],
            'source_sha' => $context['source_sha'], 'entry_sha256' => $binding['entry_sha256'],
            'cli_sha256' => $binding['cli_sha256'], 'binding_sha256' => hash('sha256', $bindingRaw),
            'native_created_sha256' => hash('sha256', $createdRaw)]
        && is_int($result['binding']['entry_namespace_pid']) && $result['binding']['entry_namespace_pid'] > 0,
        'NHECR_REAL_DRIVER_RESULT_FULL_RAW_BINDING');
    nhecr_expect(is_array($result['children']) && array_is_list($result['children']) && count($result['children']) === 4,
        'NHECR_FOUR_NATIVE_CLI_RECORDS');
    $modes = ['check', 'apply-structure', 'check', 'apply-structure']; $pids = []; $pending = null; $marker = null;
    foreach ($result['children'] as $index => $child) {
        nhecr_keys($child, ['mode', 'pid', 'native_exit', 'proc_close_exit', 'stdout_bytes', 'stdout_sha256',
            'stderr_bytes', 'stderr_sha256', 'result']);
        $cli = $child['result']; nhecr_expect(is_array($cli) && !array_is_list($cli), 'NHECR_FULL_CLI_OBJECT');
        nhecr_keys($cli, ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps',
            'pending_steps_known', 'issues', 'failure_phase', 'marker', 'source_sha256', 'history_mapping', 'trade_enabled', 'acceptance']);
        $stdout = json_encode($cli, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        nhecr_expect($child['mode'] === $modes[$index] && is_int($child['pid']) && $child['pid'] > 0
            && !in_array($child['pid'], $pids, true) && $child['native_exit'] === 0
            && in_array($child['proc_close_exit'], [0, -1], true)
            && $child['stdout_bytes'] === strlen($stdout) && $child['stdout_sha256'] === hash('sha256', $stdout)
            && $child['stderr_bytes'] === 0 && $child['stderr_sha256'] === hash('sha256', '')
            && $cli['mode'] === $modes[$index] && $cli['native_exit'] === 0
            && $cli['source_sha256'] === $binding['cli_sha256'] && $cli['pending_steps_known'] === true
            && $cli['issues'] === [] && $cli['failure_phase'] === null
            && $cli['history_mapping'] === 'NOT RUN' && $cli['trade_enabled'] === false && $cli['acceptance'] === 'NOT RUN',
            'NHECR_EXACT_CLI_ORDER_NATIVE_AND_CANONICAL_FULL_STREAM_DIGESTS');
        $pids[] = $child['pid'];
        if ($index === 0) {
            nhecr_expect($cli['state'] === 'applicable' && $cli['changed'] === false && $cli['applied_steps'] === []
                && is_array($cli['pending_steps']) && array_is_list($cli['pending_steps']) && count($cli['pending_steps']) === 13
                && count(array_unique($cli['pending_steps'], SORT_STRING)) === 13, 'NHECR_FIRST_CHECK_THIRTEEN_STEPS');
            $pending = $cli['pending_steps']; $marker = $cli['marker'];
        } elseif ($index === 1) nhecr_expect($cli['state'] === 'completed' && $cli['changed'] === true
            && $cli['applied_steps'] === $pending && $cli['pending_steps'] === [], 'NHECR_FIRST_APPLY_ORIGINAL_PLAN');
        else nhecr_expect($cli['state'] === 'completed' && $cli['changed'] === false
            && $cli['applied_steps'] === [] && $cli['pending_steps'] === [], 'NHECR_BOTH_REPEAT_READ_ONLY');
        nhecr_expect($marker === 'marketplace-ms4-structure-v1' && $cli['marker'] === $marker, 'NHECR_SAME_ORIGINAL_MARKER');
    }
    nhecr_keys($independentBaseline, ['names', 'modes', 'tables', 'redis']);
    nhecr_keys($result['snapshots'], ['before', 'after']);
    $before = $result['snapshots']['before']; $after = $result['snapshots']['after'];
    nhecr_expect($before === $independentBaseline && count($before['names']) === 164
        && $result['original_table_count'] === 164, 'NHECR_INDEPENDENT_SEAL_IS_ACTUAL_MIGRATION_BEFORE');
    $added = array_values(array_diff($after['names'], $before['names'])); sort($added, SORT_STRING);
    nhecr_expect($added === ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement']
        && count($after['names']) === 166, 'NHECR_ONLY_TWO_NEW_STRUCTURE_TABLES');
    m4m_preserved($before, $after, true);
    $counts = []; $anomalies = [];
    foreach (M4M_ADDITIONS as $table => $columns) {
        $counts[$table] = $before['tables'][$table]['projection']['rows'];
        nhecr_expect(is_int($counts[$table]) && $counts[$table] > 0, 'NHECR_NONEMPTY_FOUR_OLD_BUSINESS_TABLES');
        foreach ($columns as $column) $anomalies[$table . '.' . $column] = 0;
    }
    nhecr_expect($result['target_original_rows'] === $counts && $result['legacy_added_field_anomalies'] === $anomalies,
        'NHECR_FULL_NONEMPTY_HISTORY_AND_ZERO_NEW_OWNER_VALUES');
    foreach ($added as $table) nhecr_expect($after['tables'][$table]['projection']['rows'] === 0
        && $after['tables'][$table]['metadata']['table']['TABLE_COMMENT'] === 'marketplace-ms4-structure-v1'
        && $after['tables'][$table]['metadata']['table']['ENGINE'] === 'InnoDB', 'NHECR_EMPTY_OWN_NEW_TABLES');
    return ['schema' => 1, 'scope' => 'CURRENT_READER_MIGRATION_PREREQUISITES_ONLY',
        'run_id' => $context['run_id'], 'source_sha' => $context['source_sha'], 'freeze_sha256' => $context['freeze_sha256'],
        'driver_container_id' => $cid, 'current_reader_authorized' => false,
        'original_parent_and_normal_close_source_join' => 'CALLER_REQUIRED'];
}

/** PRIVATE plan only; identities are selected independently from accepted pre-MS4 expected rows. */
function nhecr_request_plan(#[SensitiveParameter] array $acceptedExpectedDB2, array $fixture, array $httpSummary): array
{
    nhecr_expect(function_exists('nhecr_identities'), 'NHECR_REVIEWED_REAL_IDENTITIES_REQUIRED');
    $ids = nhecr_identities($acceptedExpectedDB2, $fixture, $httpSummary);
    $pid = $fixture['product_id']; $uid = $fixture['uid'];
    nhecr_expect(is_int($pid) && $pid > 0 && is_int($uid) && $uid > 0, 'NHECR_NATIVE_INDEPENDENT_PRODUCT_BUYER');
    $paths = ['current_products' => '/api/products?ids=' . $pid . '&page=1&limit=20',
        'current_product_detail' => '/api/product/detail/' . $pid,
        'current_reply_list' => '/api/reply/list/' . $pid . '?page=1&limit=20&type=0',
        'current_reply_config' => '/api/reply/config/' . $pid,
        'current_refunded_root_detail' => '/api/order/detail/' . $ids['refund_family']['order_no'],
        'current_completed_root_detail' => '/api/order/detail/' . $ids['review_family']['order_no'],
        'current_refund_detail' => '/api/order/refund/detail/' . $ids['refund']['order_no']];
    $requests = [];
    foreach ($paths as $step => $path) $requests[] = ['step' => $step, 'method' => 'GET', 'path' => $path,
        'actor' => 'customer', 'actor_id' => $uid, 'body' => null, 'requires_actual_same_actor_login' => true];
    $requests[] = ['step' => 'current_original_public_image', 'method' => 'GET',
        'path' => '/statics/system_images/spread_level_1.png', 'actor' => 'public', 'actor_id' => null,
        'body' => null, 'requires_actual_same_actor_login' => false];
    return $requests;
}

/** Verify already captured binary bytes. No HTTP is issued; the complete observer is still required. */
function nhecr_original_image(string $origin, string $requestedUrl, #[SensitiveParameter] array $response,
    int $actualRedirectCount, string $actualEffectiveUrl): array
{
    nhecr_expect(preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{3,4})\z~D', $origin, $match) === 1
        && (int)$match[1] >= 1024 && (int)$match[1] <= 65535, 'NHECR_EXPLICIT_OWN_LOOPBACK_ORIGIN');
    $path = '/statics/system_images/spread_level_1.png';
    nhecr_expect($requestedUrl === $origin . $path && $actualEffectiveUrl === $requestedUrl && $actualRedirectCount === 0
        && ($response['http'] ?? null) === 200 && ($response['transport_errno'] ?? null) === 0
        && array_key_exists('transport_failure_class', $response) && $response['transport_failure_class'] === null
        && ($response['primary_ip'] ?? null) === '127.0.0.1' && ($response['primary_port'] ?? null) === (int)$match[1]
        && ($response['local_ip'] ?? null) === '127.0.0.1' && is_int($response['local_port'] ?? null)
        && $response['local_port'] > 0 && $response['local_port'] <= 65535, 'NHECR_ACTUAL_DIRECT_IMAGE_TRANSPORT');
    $headers = $response['headers'] ?? null;
    nhecr_expect(is_array($headers), 'NHECR_REAL_COMPLETE_HEADER_CAPTURE');
    nhecr_keys($headers, ['lines', 'blocks', 'bytes', 'complete', 'limit_exceeded']);
    nhecr_expect($headers['complete'] === true && $headers['limit_exceeded'] === false
        && is_array($headers['lines']) && array_is_list($headers['lines'])
        && is_array($headers['blocks']) && array_is_list($headers['blocks']) && count($headers['blocks']) === 1
        && $headers['lines'] === $headers['blocks'][0] && count($headers['lines']) >= 3, 'NHECR_COMPLETE_SINGLE_IMAGE_HEADER_BLOCK');
    $bytes = 0; $types = []; $lines = $headers['lines'];
    nhecr_expect(is_string($lines[0]) && preg_match('~\AHTTP/1\.[01] 200(?: [^\r\n]*)?\r?\n\z~D', $lines[0]) === 1
        && in_array($lines[count($lines) - 1], ["\r\n", "\n"], true), 'NHECR_ORIGINAL_HEADER_STATUS_AND_TERMINATOR');
    foreach ($lines as $index => $line) {
        nhecr_expect(is_string($line) && !str_contains($line, "\0"), 'NHECR_ORIGINAL_HEADER_LINE_BYTES'); $bytes += strlen($line);
        if ($index === 0 || $index === count($lines) - 1) continue;
        nhecr_expect(preg_match('/\A([A-Za-z0-9!#$%&\'*+.^_\x60|~-]+):[ \t]*([^\r\n]*)\r?\n\z/D', $line, $parts) === 1,
            'NHECR_COMPLETE_NATIVE_HEADER_FIELD');
        $name = strtolower($parts[1]); $value = trim($parts[2]);
        nhecr_expect($name !== 'location', 'NHECR_NO_REDIRECT_HEADER');
        if ($name === 'content-type') $types[] = strtolower($value);
    }
    nhecr_expect($bytes === $headers['bytes'] && $bytes <= 65536 && $types === ['image/png'],
        'NHECR_EXACT_PNG_CONTENT_TYPE_AND_FULL_HEADER_BYTES');
    $body = $response['raw'] ?? null;
    nhecr_expect(is_string($body) && strlen($body) === 53659
        && hash('sha256', $body) === '24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d',
        'NHECR_FULL_FROZEN_ORIGINAL_IMAGE_BODY');
    foreach (['imagecreatefromstring', 'imagesx', 'imagesy', 'imagedestroy'] as $function)
        nhecr_expect(function_exists($function), 'NHECR_REAL_FULL_IMAGE_DECODER_REQUIRED');
    $image = imagecreatefromstring($body); nhecr_expect($image instanceof GdImage, 'NHECR_COMPLETE_PNG_DECODING');
    try { nhecr_expect(imagesx($image) === 690 && imagesy($image) === 290, 'NHECR_ORIGINAL_DECODED_DIMENSIONS'); }
    finally { imagedestroy($image); }
    return ['schema' => 1, 'scope' => 'CURRENT_ORIGINAL_PUBLIC_IMAGE_BYTES_ONLY', 'bytes' => 53659,
        'sha256' => '24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d',
        'content_type' => 'image/png', 'width' => 690, 'height' => 290, 'full_reader_oracle_complete' => false];
}

/** Consume real public originals only. A registered reader and complete per-request oracles remain mandatory. */
/** Public-only exact13 consumer. Original private receipts remain in the producer RAM. */
function nhecr_sealed_producer(array $context, array $raw, array $objects): array
{
    $keys = static function ($value, array $wanted): void {
        nhecr_expect(is_array($value), 'NHECR_SEAL_PUBLIC_ARRAY'); nhecr_keys($value, $wanted);
    };
    $integer = static fn($value, int $minimum = 0): bool => is_int($value) && $value >= $minimum;
    $hex = static fn($value, int $length = 64): bool => is_string($value)
        && preg_match('/\A[a-f0-9]{' . $length . '}\z/D', $value) === 1;
    $decimal = static fn($value): bool => is_string($value)
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1
        && bccomp($value, '9223372036854775807', 0) <= 0;
    $descriptor = static function ($value, string $name, string $path) use ($keys, $raw): void {
        $keys($value, ['path', 'bytes', 'sha256']);
        nhecr_expect($value === ['path' => $path, 'bytes' => strlen($raw[$name]), 'sha256' => hash('sha256', $raw[$name])],
            'NHECR_SEAL_ACTUAL_COMPLETE_ORIGINAL_PUBLIC_PIN');
    };
    $group = static function ($value) use ($keys, $integer): void {
        $keys($value, ['leader_pid', 'pgid', 'sid', 'leader_starttime']);
        nhecr_expect($integer($value['leader_pid'], 2) && $value['leader_pid'] === $value['pgid']
            && $value['pgid'] === $value['sid'] && is_string($value['leader_starttime'])
            && preg_match('/\A[0-9]+\z/D', $value['leader_starttime']) === 1, 'NHECR_SEAL_NATIVE_SETSID_GROUP');
    };
    $native = static function ($value, int $pid, bool $live) use ($keys, $integer): void {
        $keys($value, ['pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig']);
        nhecr_expect($integer($value['pid'], 2) && $value['pid'] === $pid && $value['running'] === $live
            && is_bool($value['signaled']) && is_bool($value['stopped']) && is_int($value['exitcode'])
            && is_int($value['termsig']) && is_int($value['stopsig']), 'NHECR_SEAL_REAL_NATIVE_SEVEN_UNKNOWN_RETAINED');
        if ($live) nhecr_expect($value['signaled'] === false && $value['stopped'] === false
            && $value['exitcode'] === -1 && $value['termsig'] === 0 && $value['stopsig'] === 0,
            'NHECR_SEAL_ORIGINAL_LIVE_CHANNEL_NATIVE');
    };
    $closed = static function ($value) use ($keys, $integer, $group, $native): void {
        $keys($value, ['stopped', 'group', 'remaining_members', 'listener_open', 'first_terminal', 'term_attempted',
            'stop_signal', 'proc_close_exit', 'streams', 'waits']);
        $group($value['group']); $native($value['first_terminal'], $value['group']['leader_pid'], false);
        nhecr_expect($value['stopped'] === true && $value['remaining_members'] === [] && $value['listener_open'] === false
            && is_bool($value['term_attempted']) && is_int($value['proc_close_exit'])
            && ($value['stop_signal'] === null || ($value['term_attempted'] === true && $value['stop_signal'] === 15)),
            'NHECR_SEAL_ACTUAL_TERM_ATTEMPT_SENT_OR_ALREADY_EMPTY');
        $keys($value['streams'], ['stdout', 'stderr']);
        foreach ($value['streams'] as $view) {
            $keys($view, ['bytes', 'complete_verified']);
            nhecr_expect($integer($view['bytes']) && $view['complete_verified'] === true,
                'NHECR_SEAL_PRIVATE_STREAM_ONLY_COMPLETE_BYTE_COUNT_NO_BODY_HASH');
        }
        nhecr_expect(is_array($value['waits']) && array_is_list($value['waits']), 'NHECR_SEAL_REAL_WAIT_LIST');
        foreach ($value['waits'] as $wait) {
            $keys($wait, ['pid', 'status']);
            nhecr_expect($integer($wait['pid'], 2) && $integer($wait['status']), 'NHECR_SEAL_ORIGINAL_NATIVE_WAIT');
        }
    };
    $channel = static function ($value) use ($keys, $integer, $group, $native): void {
        $keys($value, ['native', 'group', 'members', 'listener']);
        $group($value['group']); $native($value['native'], $value['group']['leader_pid'], true);
        nhecr_expect(is_array($value['members']) && array_is_list($value['members']) && count($value['members']) === 2,
            'NHECR_SEAL_ORIGINAL_CHANNEL_MASTER_WORKER');
        $pids = [];
        foreach ($value['members'] as $member) {
            $keys($member, ['pid', 'ppid', 'pgid', 'sid', 'starttime']);
            nhecr_expect($integer($member['pid'], 2) && $integer($member['ppid'], 1)
                && $member['pgid'] === $value['group']['leader_pid'] && $member['sid'] === $member['pgid']
                && is_string($member['starttime']) && preg_match('/\A[0-9]+\z/D', $member['starttime']) === 1,
                'NHECR_SEAL_ORIGINAL_CHANNEL_NATIVE_MEMBER');
            $pids[] = $member['pid'];
        }
        nhecr_expect(count(array_unique($pids, SORT_NUMERIC)) === 2
            && in_array($value['group']['leader_pid'], $pids, true), 'NHECR_SEAL_TWO_DISTINCT_CHANNEL_PIDS');
        $listener = $value['listener']; $keys($listener, ['address', 'port', 'inode', 'owner_pids']);
        nhecr_expect($listener['address'] === '127.0.0.1' && $listener['port'] === 40003
            && is_string($listener['inode']) && preg_match('/\A[1-9][0-9]*\z/D', $listener['inode']) === 1
            && is_array($listener['owner_pids']) && array_is_list($listener['owner_pids']) && $listener['owner_pids'] !== [],
            'NHECR_SEAL_REAL_ORIGINAL_LOOPBACK_LISTENER');
        $owners = $listener['owner_pids']; sort($owners, SORT_NUMERIC);
        nhecr_expect($owners === $listener['owner_pids'] && count(array_unique($owners, SORT_NUMERIC)) === count($owners),
            'NHECR_SEAL_CANONICAL_LISTENER_OWNERS');
        foreach ($owners as $pid) nhecr_expect($integer($pid, 2) && in_array($pid, $pids, true),
            'NHECR_SEAL_LISTENER_ORIGINAL_PID_OWNERS');
    };
    $seal = $objects['producer_result']; $freeze = $objects['freeze'];
    $keys($seal, ['schema', 'status', 'run_id', 'source_sha', 'legacy_source_sha', 'freeze', 'producer', 'http',
        'identities', 'business_frames', 'normal_close', 'expected_m4m', 'seal_redis_time']);
    nhecr_expect($seal['schema'] === 1 && $seal['status'] === 'LEGACY_NORMAL_HISTORY_COMPLETE_ONLY'
        && $seal['run_id'] === $context['run_id'] && $seal['source_sha'] === $context['source_sha']
        && $seal['legacy_source_sha'] === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978', 'NHECR_SEAL_EXACT13_DUAL_SOURCE_RUN');
    $descriptor($seal['freeze'], 'freeze', '/run/audit/preparation-source-binding.json');
    $producer = $seal['producer'];
    $keys($producer, ['container_id', 'created', 'source_binding', 'control_files', 'native_parent']);
    nhecr_expect($producer['container_id'] === $objects['producer_created']['id'], 'NHECR_SEAL_ACTUAL_PRODUCER_CID');
    $descriptor($producer['created'], 'producer_original_created', '/run/audit/evidence/audit-legacy-history-producer-container-created.json');
    $descriptor($producer['source_binding'], 'producer_binding', '/run/audit/evidence/audit-legacy-history-producer-source-binding.json');
    $controls = $producer['control_files'];
    nhecr_expect(is_array($controls) && array_is_list($controls) && count($controls) === 11
        && $controls === $freeze['nonempty_history']['control_files'], 'NHECR_SEAL_ORIGINAL_AUTHORITY11_EXACT');
    $wantedControls = ['/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-files-bootstrap.php',
        '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php', '/run/nonempty/legacy-history-producer.php',
        '/run/nonempty/legacy-history-router.php', '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'];
    nhecr_expect(array_column($controls, 'path') === $wantedControls, 'NHECR_SEAL_COMPLETE_ORIGINAL_CONTROL_PATHS');
    foreach ($controls as $pin) {
        $keys($pin, ['path', 'bytes', 'sha256']);
        nhecr_expect($integer($pin['bytes'], 1) && $hex($pin['sha256']), 'NHECR_SEAL_FULL_CONTROL_SOURCE_DESCRIPTOR');
    }
    $parent = $producer['native_parent']; $keys($parent, ['pid', 'ppid', 'pgid', 'sid', 'starttime']);
    nhecr_expect($integer($parent['pid'], 1) && $integer($parent['ppid']) && $integer($parent['pgid'], 1)
        && $integer($parent['sid'], 1) && is_string($parent['starttime'])
        && preg_match('/\A[0-9]+\z/D', $parent['starttime']) === 1, 'NHECR_SEAL_ORIGINAL_SAFE_NATIVE_PARENT');
    $http = $seal['http'];
    $keys($http, ['schema', 'scope', 'run_id', 'legacy_source_sha', 'buyer_uid', 'admin_id', 'product_id', 'sku_id',
        'refund_family', 'review_family', 'refund', 'review', 'http_steps', 'business_acceptance', 'native_children_close_and_independent_zero']);
    nhecr_expect($http['schema'] === 1 && $http['scope'] === 'legacy_http_ids_and_observed_states_only'
        && $http['run_id'] === $context['run_id'] && $http['legacy_source_sha'] === $seal['legacy_source_sha']
        && $http['business_acceptance'] === 'NOT SIGNED'
        && $http['native_children_close_and_independent_zero'] === 'REQUIRED_BY_OUTER_PRODUCER', 'NHECR_SEAL_REAL_HTTP_SCOPE');
    foreach (['buyer_uid', 'admin_id', 'product_id', 'sku_id'] as $key)
        nhecr_expect($integer($http[$key], 1), 'NHECR_SEAL_HTTP_NATIVE_ACTOR_CATALOG_IDS');
    $steps = ['admin_login', 'admin_identity', 'platform_self_merchant_review', 'platform_self_merchant_accept_orders',
        'customer_login', 'customer_identity', 'platform_system_balance_add', 'funded_balance_read',
        'refund_family_cart', 'refund_family_confirm', 'refund_family_create', 'refund_family_unpaid_read',
        'refund_family_pay', 'refund_family_paid_read', 'review_family_cart', 'review_family_confirm', 'review_family_create',
        'review_family_unpaid_read', 'review_family_pay', 'review_family_paid_read', 'two_paid_balance_read',
        'unshipped_refund_apply', 'owned_refund_identity', 'platform_refund_approve', 'owned_refund_completed_read',
        'owned_refunded_root_read', 'refunded_balance_read', 'platform_manual_courier', 'owned_shipped_read',
        'customer_receipt', 'owned_received_read', 'customer_purchased_item_comment', 'platform_pending_review_identity',
        'platform_review_approve', 'platform_review_reply', 'platform_approved_replied_read', 'owned_commented_completed_read'];
    nhecr_expect($http['http_steps'] === array_map(static fn(string $step): array => ['step' => $step, 'http' => 200], $steps),
        'NHECR_SEAL_COMPLETE37_ORIGINAL_SAFE_HTTP_ORDER');
    $identities = $seal['identities']; $keys($identities, ['refund_family', 'review_family', 'refund', 'review']);
    foreach ($identities as $name => $identity) nhecr_expect($identity === $http[$name],
        'NHECR_SEAL_INDEPENDENT_IDENTITIES_JOIN_HTTP_COUNTERPART_ONLY');
    foreach (['refund_family' => [-2, 2], 'review_family' => [3, 0]] as $name => $statuses) {
        $row = $identities[$name];
        $keys($row, ['id', 'order_no', 'order_unique', 'item_unique', 'cart_id', 'paid', 'status', 'refund_status']);
        nhecr_expect($integer($row['id'], 1) && $integer($row['cart_id'], 1)
            && is_string($row['order_no']) && preg_match('/\Acp([1-9][0-9]{0,18})\z/D', $row['order_no'], $number) === 1
            && $decimal($number[1]) && $decimal($row['order_unique']) && $hex($row['item_unique'], 32)
            && $row['paid'] === 1 && $row['status'] === $statuses[0] && $row['refund_status'] === $statuses[1],
            'NHECR_SEAL_ORIGINAL_DECIMAL_CONFIRM_KEY_CP_NUMBER_AND_MD5_ITEM');
    }
    foreach (['id', 'order_no', 'order_unique', 'item_unique', 'cart_id'] as $key)
        nhecr_expect($identities['refund_family'][$key] !== $identities['review_family'][$key], 'NHECR_SEAL_DISTINCT_ORIGINAL_TWO_FAMILIES');
    $refund = $identities['refund']; $review = $identities['review'];
    $keys($refund, ['id', 'order_no', 'root_id', 'refund_type']); $keys($review, ['id', 'root_id', 'status', 'is_reply']);
    nhecr_expect($integer($refund['id'], 1) && $decimal($refund['order_no'])
        && $refund['root_id'] === $identities['refund_family']['id'] && $refund['refund_type'] === 6
        && $integer($review['id'], 1) && $review['root_id'] === $identities['review_family']['id']
        && $review['status'] === 1 && $review['is_reply'] === 1, 'NHECR_SEAL_REAL_COMPLETED_REFUND_APPROVED_REPLIED_REVIEW');
    $frames = [];
    foreach ([13, 19, 22, 32] as $sequence) $frames[] = ['sequence' => $sequence, 'step' => $steps[$sequence - 1],
        'complete_original_frame_verified' => true];
    nhecr_expect($seal['business_frames'] === $frames, 'NHECR_SEAL_FOUR_REAL_ORIGINAL_ACCEPTANCE_SITES');
    $close = $seal['normal_close'];
    $keys($close, ['private_native_receipt_complete_verified', 'children_views', 'no_extra_view', 'files_complete_verified', 'only_parent_and_no_listeners_verified']);
    nhecr_expect($close['private_native_receipt_complete_verified'] === true && $close['files_complete_verified'] === true
        && $close['only_parent_and_no_listeners_verified'] === true, 'NHECR_SEAL_QUALIFIED_PRIVATE_FULL_CLOSE_PROOF');
    $keys($close['children_views'], ['http', 'channel']);
    foreach ($close['children_views'] as $view) $closed($view);
    nhecr_expect(count(array_unique([$parent['pid'], $close['children_views']['http']['group']['leader_pid'],
        $close['children_views']['channel']['group']['leader_pid']], SORT_NUMERIC)) === 3, 'NHECR_SEAL_THREE_DISTINCT_NATIVE_LEADERS');
    $quiet = $close['no_extra_view'];
    $keys($quiet, ['schema', 'scope', 'run_id', 'quiet_ns', 'started_ns', 'ended_ns', 'zero_returns',
        'http_closed', 'channel_before', 'channel_after', 'subscriber']);
    nhecr_expect($quiet['schema'] === 1 && $quiet['scope'] === 'nonempty_history_original_channel_finite_no_extra'
        && $quiet['run_id'] === $context['run_id'] && $quiet['quiet_ns'] === 10000000000
        && $integer($quiet['started_ns'], 1) && $integer($quiet['ended_ns'], $quiet['started_ns'] + $quiet['quiet_ns'])
        && $integer($quiet['zero_returns'], 1), 'NHECR_SEAL_ACTUAL_SAME_SOCKET_PASSIVE_NO_EXTRA_WINDOW');
    $keys($quiet['http_closed'], ['group', 'first_terminal', 'proc_close_exit', 'remaining_members', 'listener_open']);
    foreach ($quiet['http_closed'] as $key => $value) nhecr_expect($value === $close['children_views']['http'][$key],
        'NHECR_SEAL_NO_EXTRA_SAME_ALREADY_CLOSED_HTTP');
    $channel($quiet['channel_before']); $channel($quiet['channel_after']);
    nhecr_expect($quiet['channel_before'] === $quiet['channel_after']
        && $quiet['channel_before']['group'] === $close['children_views']['channel']['group'], 'NHECR_SEAL_NO_CHANNEL_RESTART');
    $subscriber = $quiet['subscriber'];
    $keys($subscriber, ['socket_id', 'local_endpoint', 'remote_endpoint', 'metadata_before', 'metadata_after']);
    nhecr_expect($integer($subscriber['socket_id'], 1) && is_string($subscriber['local_endpoint'])
        && preg_match('/\A127\.0\.0\.1:[1-9][0-9]{0,4}\z/D', $subscriber['local_endpoint']) === 1
        && $subscriber['remote_endpoint'] === '127.0.0.1:40003', 'NHECR_SEAL_REAL_ORIGINAL_SUBSCRIBER');
    foreach (['metadata_before', 'metadata_after'] as $key) {
        $value = $subscriber[$key]; $keys($value, ['unread_bytes', 'eof', 'blocked', 'timed_out']);
        nhecr_expect($value['unread_bytes'] === 0 && $value['eof'] === false && $value['blocked'] === false
            && $value['timed_out'] === false, 'NHECR_SEAL_NO_PRIVATE_STREAM_OR_BUFFERED_EXTRA');
    }
    $snapshot = $seal['expected_m4m']; $keys($snapshot, ['names', 'tables', 'redis', 'modes']);
    $names = $snapshot['names'];
    nhecr_expect(is_array($names) && array_is_list($names) && count($names) === 164 && is_array($snapshot['tables']),
        'NHECR_SEAL_FULL164_NAMES_AND_TABLES');
    $sorted = $names; sort($sorted, SORT_STRING);
    nhecr_expect($sorted === $names && count(array_unique($names, SORT_STRING)) === 164
        && array_keys($snapshot['tables']) === $names, 'NHECR_SEAL_ALL164_CANONICAL_COMPLETE_TABLES');
    foreach ($names as $name) nhecr_expect(is_string($name) && preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D', $name) === 1,
        'NHECR_SEAL_CANONICAL_ORIGINAL_TABLE_NAME');
    foreach ($snapshot['tables'] as $table) {
        $keys($table, ['metadata', 'ddl_sha256', 'projection', 'projected_columns']);
        $keys($table['metadata'], ['table', 'columns', 'indexes', 'constraints', 'checks', 'foreign', 'triggers']);
        $keys($table['projection'], ['rows', 'sha256']);
        nhecr_expect($hex($table['ddl_sha256']) && $integer($table['projection']['rows']) && $hex($table['projection']['sha256'])
            && is_array($table['projected_columns']) && array_is_list($table['projected_columns']) && $table['projected_columns'] !== []
            && is_array($table['metadata']['columns']) && array_keys($table['metadata']['columns']) === $table['projected_columns'],
            'NHECR_SEAL_COMPLETE_METADATA_DDL_NATIVE_COLUMN_PROJECTION');
    }
    nhecr_expect(is_array($snapshot['redis']), 'NHECR_SEAL_COMPLETE_HASHED_REDIS_NAMESPACE');
    $redisKeys = array_keys($snapshot['redis']); $sorted = $redisKeys; sort($sorted, SORT_STRING);
    nhecr_expect($sorted === $redisKeys, 'NHECR_SEAL_COMPLETE_REDIS_CANONICAL_ORDER');
    foreach ($snapshot['redis'] as $key => $value) {
        $keys($value, ['sha256', 'expires']);
        nhecr_expect($hex($key) && $hex($value['sha256']) && is_int($value['expires'])
            && ($value['expires'] === -1 || $value['expires'] > 0), 'NHECR_SEAL_HASHED_KEY_VALUE_AND_ABSOLUTE_DEADLINE');
    }
    nhecr_expect(is_array($snapshot['modes']) && array_is_list($snapshot['modes']) && count($snapshot['modes']) === 1,
        'NHECR_SEAL_ORIGINAL_UNIQUE_SQL_MODE_ROW');
    $keys($snapshot['modes'][0], ['session_mode', 'global_mode', 'session_innodb', 'global_innodb']);
    $clock = $seal['seal_redis_time'];
    nhecr_expect(is_array($clock) && array_is_list($clock) && count($clock) === 2 && is_string($clock[0])
        && preg_match('/\A[1-9][0-9]*\z/D', $clock[0]) === 1 && is_string($clock[1])
        && preg_match('/\A[0-9]+\z/D', $clock[1]) === 1 && bccomp($clock[1], '1000000', 0) < 0,
        'NHECR_SEAL_REAL_ATOMIC_REDIS_TIME_NO_NEW_EXPIRY');
    return $seal;
}

function nhecr_history_handoff(array $context, array $publicRaw): array
{
    nhecr_keys($publicRaw, ['freeze', 'producer_created', 'producer_original_created', 'producer_terminal', 'producer_binding',
        'producer_result', 'driver_created', 'driver_original_created', 'driver_terminal', 'driver_binding', 'driver_result']);
    $objects = [];
    foreach ($publicRaw as $name => $raw) {
        nhecr_expect(is_string($raw) && $raw !== '' && strlen($raw) <= 67108864, 'NHECR_HANDOFF_COMPLETE_BOUNDED_PUBLIC_BYTES');
        $value = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
        nhecr_expect(is_array($value) && !array_is_list($value), 'NHECR_HANDOFF_PUBLIC_OBJECT'); $objects[$name] = $value;
    }
    $freeze = $objects['freeze']; $created = $objects['producer_created']; $terminal = $objects['producer_terminal'];
    $binding = $objects['producer_binding']; $cid = $created['id'] ?? null;
    nhecr_expect(is_string($context['run_id'] ?? null) && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $context['run_id']) === 1
        && is_string($context['source_sha'] ?? null) && preg_match('/\A[a-f0-9]{40}\z/D', $context['source_sha']) === 1
        && ($context['project'] ?? null) === 'tianli-audit-' . $context['run_id']
        && ($context['freeze_bytes'] ?? null) === strlen($publicRaw['freeze'])
        && ($context['freeze_sha256'] ?? null) === hash('sha256', $publicRaw['freeze'])
        && ($freeze['source_sha'] ?? null) === $context['source_sha'] && ($freeze['status'] ?? null) === 'CLEAN_SOURCE_FROZEN_ONLY'
        && ($freeze['requested_repo_source'] ?? null) === $context['repo_dir'], 'NHECR_HANDOFF_SAME_REAL_CONTEXT_COMPLETE_FREEZE');
    $sameNative = static function ($left, $right) use (&$sameNative): bool {
        if (gettype($left) !== gettype($right)) return false;
        if (!is_array($left)) return $left === $right;
        if (array_is_list($left) !== array_is_list($right)) return false;
        $leftKeys = array_keys($left); $rightKeys = array_keys($right);
        if (!array_is_list($left)) { sort($leftKeys, SORT_STRING); sort($rightKeys, SORT_STRING); }
        if ($leftKeys !== $rightKeys) return false;
        foreach ($leftKeys as $key) if (!$sameNative($left[$key], $right[$key])) return false;
        return true;
    };
    nhecr_expect($sameNative($created, $objects['producer_original_created'])
        && $sameNative($objects['driver_created'], $objects['driver_original_created']),
        'NHECR_HANDOFF_ORIGINAL_ROLE_PROOF_ALIASES_FULL_NATIVE_TYPES_VALUES');
    nhecr_expect(defined('M4M_IMAGE') && is_string($cid) && preg_match('/\A[a-f0-9]{64}\z/D', $cid) === 1
        && $created['run_id'] === $context['run_id'] && $created['source_sha'] === $context['source_sha']
        && $created['proof_name'] === 'legacy-history-producer' && $created['status'] === 'created' && $created['pid'] === 0
        && $created['image'] === M4M_IMAGE && $created['hostname'] === substr($cid, 0, 12)
        && $created['read_only'] === true && $created['privileged'] === false && $created['cap_drop'] === ['ALL']
        && in_array($created['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && $created['network_mode'] === $context['project'] . '_isolation' && ($created['port_bindings'] ?? []) === []
        && $created['entrypoint'] === ['php'] && $created['workdir'] === '/legacy-workspace/crmeb'
        && $created['command'] === ['/run/nonempty/legacy-history-producer.php', 'normal',
            '--freeze-bytes=' . $context['freeze_bytes'], '--freeze-sha256=' . $context['freeze_sha256']]
        && $created['labels']['tianli.audit'] === '1' && $created['labels']['tianli.audit.run'] === $context['run_id']
        && $created['labels']['com.docker.compose.project'] === $context['project']
        && $created['labels']['com.docker.compose.service'] === 'audit-legacy-history-producer', 'NHECR_HANDOFF_ORIGINAL_CREATED_PRODUCER');
    foreach (['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command', 'entrypoint',
        'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $key)
        nhecr_expect($created[$key] === $terminal[$key], 'NHECR_HANDOFF_ORIGINAL_PRODUCER_IMMUTABLE_TERMINAL');
    nhecr_expect(function_exists('nhe_mount_rows'), 'NHECR_HANDOFF_PINNED_ORIGINAL_MOUNT_CALLEE_REQUIRED');
    $nativeMounts = static function (array $rows): array {
        $out = []; foreach ($rows as $row) { ksort($row, SORT_STRING); $out[] = json_encode($row, JSON_THROW_ON_ERROR); }
        sort($out, SORT_STRING); return $out;
    };
    $mounts = nhe_mount_rows($created['mounts']); $wanted = [];
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'],
        '/legacy-source' => $freeze['nonempty_history']['requested_legacy_repo_source'],
        '/legacy-workspace' => $freeze['nonempty_history']['requested_legacy_repo_source'],
        '/run/nonempty' => $context['prep_dir'], '/run/audit' => $context['run_dir']] as $destination => $source)
        $wanted[] = ['Type' => 'bind', 'Source' => $source, 'Destination' => $destination, 'RW' => $destination === '/run/audit', 'Name' => ''];
    foreach (['/run/audit-app' => 'app-secrets', '/run/audit-fixtures' => 'fixture-secrets'] as $destination => $leaf) {
        $selected = array_values(array_filter($mounts, static fn(array $row): bool => $row['Destination'] === $destination));
        nhecr_expect(count($selected) === 1 && $selected[0]['Type'] === 'volume' && $selected[0]['RW'] === false
            && $selected[0]['Name'] === $context['project'] . '_' . $leaf
            && is_string($selected[0]['Source']) && $selected[0]['Source'] !== '', 'NHECR_HANDOFF_ORIGINAL_TWO_PRIVATE_RO_VOLUMES');
        $wanted[] = $selected[0];
    }
    nhecr_expect(count($mounts) === 8 && $mounts === nhe_mount_rows($wanted)
        && $nativeMounts($created['mounts']) === $nativeMounts($terminal['mounts'])
        && $terminal['status'] === 'exited' && $terminal['pid'] === 0 && $terminal['exit_code'] === 0
        && $terminal['restart_count'] === 0 && $created['restart_count'] === 0, 'NHECR_HANDOFF_COMPLETE_EIGHT_MOUNTS_ACTUAL_NATIVE_ZERO');
    nhecr_keys($created['tmpfs'], ['/tmp', '/legacy-runtime']);
    $options = explode(',', $created['tmpfs']['/legacy-runtime']);
    $modes = array_values(array_filter($options, static fn(string $value): bool => str_starts_with($value, 'mode=')));
    nhecr_expect(count($modes) === 1 && preg_match('/\Amode=0?700\z/D', $modes[0]) === 1,
        'NHECR_HANDOFF_ORIGINAL_PRIVATE_RUNTIME0700');
    nhecr_keys($binding, ['schema', 'run_id', 'source_sha', 'legacy_source_sha', 'role', 'container_id', 'authority_sha256', 'mounts']);
    $authority = $freeze['nonempty_history'];
    $authorityRaw = json_encode($authority, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    nhecr_expect($binding === ['schema' => 1, 'run_id' => $context['run_id'], 'source_sha' => $context['source_sha'],
        'legacy_source_sha' => '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978', 'role' => 'audit-legacy-history-producer',
        'container_id' => $cid, 'authority_sha256' => hash('sha256', $authorityRaw), 'mounts' => $mounts],
        'NHECR_HANDOFF_ORIGINAL_FULL_SOURCE_BINDING_AND_MOUNTS');
    $seal = nhecr_sealed_producer($context, $publicRaw, $objects);
    $migration = nhecr_four_cli_join($seal['expected_m4m'], $context, $publicRaw['freeze'], $publicRaw['driver_original_created'],
        $publicRaw['driver_terminal'], $publicRaw['driver_binding'], $publicRaw['driver_result']);
    return ['schema' => 1, 'scope' => 'CURRENT_READER_SEALED_BASELINE_PREREQUISITES_ONLY', 'run_id' => $context['run_id'],
        'source_sha' => $context['source_sha'], 'freeze_sha256' => $context['freeze_sha256'],
        'legacy_parent' => $seal['producer']['native_parent'], 'identities' => $seal['identities'], 'seal' => $seal,
        'migration' => $migration, 'driver_after' => $objects['driver_result']['snapshots']['after'],
        'current_reader_authorized' => false,
        'remaining' => 'CURRENT_ROLE_RETIREMENT_ROOT_SOURCE_AND_PRIVATE_FIXTURE_CONTEXT_UNBOUND'];
}

/** Fixed public control read: no private directory or credential body is accepted here. */
function nhecr_public_read(string $path): array
{
    nhecr_expect(str_starts_with($path, '/run/audit/') && !str_contains($path, '..') && !str_contains($path, "\0")
        && (preg_match('~\A/run/audit/(?:actual-run-context|preparation-source-binding|manifest)\.json\z|\A/run/audit/evidence/[A-Za-z0-9_.-]+\.json\z~D', $path) === 1
            || in_array($path, [
                '/run/audit/evidence/nonempty-history-phases/before-legacy-files/INDEX.json',
                '/run/audit/evidence/nonempty-history-phases/before-legacy-files/evidence/initializer-created.json',
                '/run/audit/evidence/nonempty-history-phases/before-legacy-files/evidence/initializer-terminal.json',
                '/run/audit/evidence/nonempty-history-phases/before-legacy-files/evidence/initializer-result.json'], true)),
        'NHECR_FIXED_PUBLIC_CONTROL_PATH');
    clearstatcache(true, $path); $before = @lstat($path);
    nhecr_expect(is_array($before) && is_file($path) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && $before['nlink'] === 1
        && $before['size'] > 0 && $before['size'] <= 67108864, 'NHECR_PUBLIC_REGULAR_BOUNDED_INPUT');
    $handle = fopen($path, 'rb'); nhecr_expect(is_resource($handle), 'NHECR_PUBLIC_READ_HANDLE');
    try {
        $opened = fstat($handle); $raw = stream_get_contents($handle, 67108865); $afterHandle = fstat($handle);
        nhecr_expect(is_array($opened) && is_array($afterHandle) && is_string($raw)
            && strlen($raw) === $before['size'] && fgetc($handle) === false && feof($handle), 'NHECR_PUBLIC_COMPLETE_BYTES');
    } finally { fclose($handle); }
    clearstatcache(true, $path); $after = lstat($path); $stat = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        nhecr_expect($before[$key] === $opened[$key] && $before[$key] === $afterHandle[$key]
            && $before[$key] === $after[$key], 'NHECR_PUBLIC_OPEN_PATH_COMPLETE_STAT9'); $stat[$key] = $before[$key];
    }
    return ['raw' => $raw, 'stat9' => $stat, 'pin' => ['path' => $path, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)]];
}

function nhecr_public_handoff(array $context, array &$proofs): array
{
    $leaves = ['freeze' => '/run/audit/preparation-source-binding.json',
        'producer_created' => '/run/audit/evidence/legacy-history-producer-created.json',
        'producer_original_created' => '/run/audit/evidence/audit-legacy-history-producer-container-created.json',
        'producer_terminal' => '/run/audit/evidence/legacy-history-producer-terminal.json',
        'producer_binding' => '/run/audit/evidence/audit-legacy-history-producer-source-binding.json',
        'producer_result' => '/run/audit/evidence/legacy-history-producer-result.json',
        'driver_created' => '/run/audit/evidence/driver-created.json',
        'driver_original_created' => '/run/audit/evidence/ms4-migration-container-created.json',
        'driver_terminal' => '/run/audit/evidence/driver-terminal.json',
        'driver_binding' => '/run/audit/evidence/ms4-migration-source-binding.json',
        'driver_result' => '/run/audit/evidence/ms4-migration-normal.json'];
    $raw = []; $proofs = [];
    foreach ($leaves as $name => $path) { $proofs[$path] = nhecr_public_read($path); $raw[$name] = $proofs[$path]['raw']; }
    $joined = nhecr_history_handoff($context, $raw);
    foreach ($proofs as $path => $value) nhecr_expect(nhecr_public_read($path) === $value,
        'NHECR_ORIGINAL_ALL_EXACT13_FOURCLI_PUBLIC_BYTES_STAT9_HELD');
    return $joined;
}

/** Pure native-created validator shared by actual bootstrap registration and the new reader qualifier. */
function nhecr_created_facts(array $context, array $freeze, array $facts, array $binding, array $manifest, array $actorReceipt): array
{
    $role = 'audit-legacy-current-reader'; $cid = $facts['id'] ?? '';
    nhecr_expect(is_string($cid) && preg_match('/\A[a-f0-9]{64}\z/D', $cid) === 1
        && $facts['run_id'] === $context['run_id'] && $facts['source_sha'] === $context['source_sha']
        && $facts['proof_name'] === 'legacy-current-reader' && $facts['hostname'] === substr($cid, 0, 12)
        && $facts['image'] === M4M_IMAGE && $facts['status'] === 'created' && $facts['pid'] === 0 && $facts['restart_count'] === 0
        && $facts['read_only'] === true && $facts['privileged'] === false && $facts['entrypoint'] === ['php']
        && $facts['workdir'] === '/workspace/crmeb' && $facts['command'] === ['/run/nonempty/legacy-current-reader.php', 'normal',
            '--freeze-bytes=' . $context['freeze_bytes'], '--freeze-sha256=' . $context['freeze_sha256']]
        && $facts['cap_drop'] === ['ALL'] && in_array($facts['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && in_array($facts['port_bindings'], [null, []], true) && count($facts['networks']) === 1
        && array_keys($facts['networks']) === [$context['project'] . '_isolation']
        && $facts['network_mode'] === $context['project'] . '_isolation'
        && $facts['labels']['tianli.audit'] === '1' && $facts['labels']['tianli.audit.run'] === $context['run_id']
        && $facts['labels']['com.docker.compose.project'] === $context['project']
        && $facts['labels']['com.docker.compose.service'] === $role, 'NHECR_ACTUAL_READER_CREATED_BOUNDARY');
    foreach ($facts['ports'] ?? [] as $ports) nhecr_expect($ports === null, 'NHECR_NO_PUBLISHED_CURRENT_HTTP_PORT');
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    nhecr_expect($names === [$role, 'audit-mysql', 'audit-redis'] && $manifest['services'][$role]['id'] === $cid
        && $manifest['services'][$role]['image_id'] === M4M_IMAGE && $manifest['run_id'] === $context['run_id']
        && $manifest['commit_sha'] === $context['source_sha'] && $manifest['project'] === $context['project']
        && $manifest['network_internal'] === true && $manifest['network']['name'] === $context['project'] . '_isolation'
        && $manifest['network']['id'] === $context['network_id'], 'NHECR_REGISTERED_ONLY_READER_AND_TWO_ORIGINAL_BASE_ROLES');
    foreach (['audit-mysql', 'audit-redis'] as $base)
        nhecr_expect($manifest['services'][$base]['id'] === $context['initial_service_ids'][$base], 'NHECR_ORIGINAL_DATABASE_REDIS_CIDS');
    $expectedVolumes = [];
    foreach (['app-secrets', 'bootstrap-secrets', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets'] as $leaf)
        $expectedVolumes[] = $context['project'] . '_' . $leaf;
    sort($expectedVolumes, SORT_STRING); $volumes = $manifest['volumes']; sort($volumes, SORT_STRING);
    nhecr_expect($volumes === $expectedVolumes, 'NHECR_SAME_SIX_ORIGINAL_VOLUMES');
    $wanted = [];
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'],
        '/run/nonempty' => $context['prep_dir'], '/run/audit' => $context['run_dir']] as $destination => $host)
        $wanted[] = ['Type' => 'bind', 'Source' => audit_canonical_host_bind_source($host), 'Destination' => $destination,
            'RW' => $destination === '/run/audit', 'Name' => ''];
    foreach (['app-secrets' => '/run/audit-app', 'fixture-secrets' => '/run/audit-fixtures'] as $leaf => $destination) {
        $mount = m4m_mount($facts, $destination); $name = $context['project'] . '_' . $leaf;
        nhecr_expect($mount['Type'] === 'volume' && $mount['Name'] === $name && $mount['RW'] === false
            && isset($manifest['volume_identities'][$name]), 'NHECR_ONLY_SAME_RUN_PRIVATE_VOLUMES_RO');
        if ($leaf === 'fixture-secrets') nhecr_expect($mount['Source'] === $actorReceipt['fixture_mount']['Source']
            && $mount['Name'] === $actorReceipt['fixture_mount']['Name'], 'NHECR_ORIGINAL_PREPARE_FIXTURE_PHYSICAL_SOURCE');
        $wanted[] = ['Type' => 'volume', 'Source' => $mount['Source'], 'Destination' => $destination, 'RW' => false, 'Name' => $name];
    }
    $actual = []; $seen = [];
    foreach ($facts['mounts'] as $mount) {
        nhecr_expect(!isset($seen[$mount['Destination']]), 'NHECR_UNIQUE_FULL_MOUNT_DESTINATIONS'); $seen[$mount['Destination']] = true;
        if ($mount['Type'] === 'tmpfs') {
            nhecr_expect(in_array($mount['Destination'], ['/tmp', '/current-runtime'], true) && $mount['RW'] === true, 'NHECR_ONLY_TWO_OWN_TMPFS'); continue;
        }
        $actual[] = ['Type' => $mount['Type'], 'Source' => audit_canonical_host_bind_source($mount['Source']),
            'Destination' => $mount['Destination'], 'RW' => $mount['RW'], 'Name' => $mount['Name'] ?? ''];
    }
    $sort = static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    usort($wanted, $sort); usort($actual, $sort);
    $tmpfs = array_keys($facts['tmpfs']); sort($tmpfs, SORT_STRING);
    $modes = array_values(array_filter(explode(',', $facts['tmpfs']['/current-runtime'] ?? ''), static fn(string $option): bool => str_starts_with($option, 'mode=')));
    nhecr_expect($actual === $wanted && $tmpfs === ['/current-runtime', '/tmp'] && count($modes) === 1
        && preg_match('/\Amode=0?700\z/D', $modes[0]) === 1, 'NHECR_COMPLETE_FOUR_BINDS_TWO_RO_TWO_TMPFS');
    nhecr_keys($binding, ['schema', 'run_id', 'source_sha', 'role', 'container_id', 'current_authority_sha256',
        'legacy_authority_sha256', 'mounts', 'prerequisites']);
    nhecr_expect($binding['schema'] === 1 && $binding['run_id'] === $context['run_id'] && $binding['source_sha'] === $context['source_sha']
        && $binding['role'] === $role && $binding['container_id'] === $cid && $binding['mounts'] === $wanted
        && $binding['current_authority_sha256'] === hash('sha256', nhe_json($freeze['current_reader']))
        && $binding['legacy_authority_sha256'] === hash('sha256', nhe_json($freeze['nonempty_history'])), 'NHECR_REAL_CURRENT_AND_LEGACY_SOURCE_BINDING');
    return $wanted;
}

function nhecr_includes(array $state): array
{
    $controls = array_column($state['authority']['control_files'], null, 'path');
    $current = array_column($state['current_authority']['source_files'], null, 'path'); $loaded = [];
    foreach (get_included_files() as $file) {
        nhecr_expect(!is_link($file) && realpath($file) === $file, 'NHECR_CANONICAL_LOADED_SOURCE');
        if (isset($controls[$file])) nhe_file($file, $controls[$file]);
        else {
            nhecr_expect(str_starts_with($file, '/workspace/'), 'NHECR_NO_OLD_OR_UNKNOWN_COMPOSER_APPLICATION');
            $relative = substr($file, strlen('/workspace/'));
            nhecr_expect(isset($current[$relative]), 'NHECR_LOADED_FILE_IN_COMPLETE_CURRENT_FROZEN_TREE');
            nhe_file($file, array_replace($current[$relative], ['path' => $file]));
        }
        $loaded[] = $file;
    }
    sort($loaded, SORT_STRING); return $loaded;
}

function nhecr_qualify(array $freezePin, bool $httpChild = false): array
{
    nhecr_expect(false, 'NHECR_EXACT13_READER_FINAL_SOURCE_UNBOUND');
    $ignore = ini_set('zend.exception_ignore_args', '1');
    nhecr_expect($ignore !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHECR_ENTRY_REAL_PRIVATE_ARGUMENT_BOUNDARY_BEFORE_ANY_IO');
    nhecr_expect(__FILE__ === '/run/nonempty/legacy-current-reader.php' && realpath(__FILE__) === __FILE__
        && PHP_INT_SIZE === 8 && PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300
        && PHP_SAPI === ($httpChild ? 'cli-server' : 'cli') && getcwd() === '/workspace/crmeb'
        && getenv('AUDIT_ISOLATED') === '1' && getenv('AUDIT_GUARD_MODE') === 'read-only', 'NHECR_REAL_FIXED_CURRENT_READER_ENTRY');
    require_once '/run/nonempty/legacy-app.php';
    $freezeRaw = nhe_file('/run/audit/preparation-source-binding.json', $freezePin);
    $freeze = json_decode($freezeRaw, true, 256, JSON_THROW_ON_ERROR);
    $contextFile = nhecr_public_read('/run/audit/actual-run-context.json'); $manifestFile = nhecr_public_read('/run/audit/manifest.json');
    $context = json_decode($contextFile['raw'], true, 128, JSON_THROW_ON_ERROR); $manifest = json_decode($manifestFile['raw'], true, 128, JSON_THROW_ON_ERROR);
    nhecr_expect($context['run_id'] === getenv('AUDIT_RUN_ID') && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $context['run_id']) === 1
        && $context['source_sha'] === getenv('AUDIT_SOURCE_SHA') && preg_match('/\A[a-f0-9]{40}\z/D', $context['source_sha']) === 1
        && $context['project'] === 'tianli-audit-' . $context['run_id'] && $context['freeze_bytes'] === $freezePin['bytes']
        && $context['freeze_sha256'] === $freezePin['sha256'] && $freeze['source_sha'] === $context['source_sha']
        && $freeze['status'] === 'CLEAN_SOURCE_FROZEN_ONLY' && $context['repo_dir'] === $freeze['requested_repo_source'],
        'NHECR_REAL_RUN_ENV_CONTEXT_FREEZE_JOIN');
    $authority = $freeze['nonempty_history']; $current = $freeze['current_reader'];
    nhecr_keys($current, ['schema', 'source_sha', 'source_files', 'source_directories']);
    nhecr_expect($current['schema'] === 1 && $current['source_sha'] === $context['source_sha']
        && $authority['legacy_source_sha'] === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978', 'NHECR_CURRENT_COMPLETE_TREE_AND_ORIGINAL_LEGACY_SOURCE');
    $controlPaths = array_column($authority['control_files'], 'path');
    nhecr_expect(count($controlPaths) === 12 && $controlPaths === [
        '/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-current-reader.php',
        '/run/nonempty/legacy-files-bootstrap.php', '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php',
        '/run/nonempty/legacy-history-producer.php', '/run/nonempty/legacy-history-router.php', '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'], 'NHECR_EXACT_COMPLETE_CONTROL_TWELVE');
    foreach ($authority['control_files'] as $pin) nhe_file($pin['path'], $pin);
    $artifacts = array_column($freeze['artifact_files'], null, 'path');
    nhecr_expect(count($artifacts) === 14 && count($freeze['artifact_files']) === 14 && isset($artifacts['legacy-current-reader.php'])
        && array_replace($artifacts['legacy-current-reader.php'], ['path' => __FILE__])
            === array_column($authority['control_files'], null, 'path')[__FILE__], 'NHECR_TRUE_FOURTEEN_ARTIFACT_AND_CONTROL_JOIN');
    require_once '/workspace/help/audit-regressions/isolated/ms4-migration.php';
    require_once '/run/nonempty/legacy-actors.php';
    require_once '/run/nonempty/legacy-history-producer.php';
    foreach ($freeze['driver_source_files'] as $pin) foreach (['/workspace', '/source'] as $root)
        nhe_file($root . '/' . $pin['path'], array_replace($pin, ['path' => $root . '/' . $pin['path']]));
    $stage = $manifest['nonempty_history']['stages']['audit-legacy-current-reader'] ?? null;
    nhecr_expect(is_array($stage), 'NHECR_REAL_READER_STAGE_REGISTERED'); nhecr_keys($stage, ['created', 'binding']);
    $base = '/run/audit/evidence/audit-legacy-current-reader';
    nhecr_expect($stage['created']['path'] === $base . '-container-created.json' && $stage['binding']['path'] === $base . '-source-binding.json', 'NHECR_FIXED_READER_STAGE_ORIGINAL_LEAVES');
    $createdRaw = nhe_file($stage['created']['path'], $stage['created']); $bindingRaw = nhe_file($stage['binding']['path'], $stage['binding']);
    $created = json_decode($createdRaw, true, 128, JSON_THROW_ON_ERROR); $binding = json_decode($bindingRaw, true, 128, JSON_THROW_ON_ERROR);
    $proofs = []; $handoff = nhecr_public_handoff($context, $proofs); $actorProofs = [];
    $actorReceipt = nhecr_actor_prepare_receipt($context, $actorProofs);
    $mounts = nhecr_created_facts($context, $freeze, $created, $binding, $manifest, $actorReceipt);
    nhecr_expect(gethostname() === substr($created['id'], 0, 12), 'NHECR_THIS_ACTUAL_READER_CONTAINER');
    $wantedPrerequisites = [];
    foreach (['producer_retired' => 'legacy-history-producer-retired.json', 'driver_retired' => 'driver-retired.json'] as $key => $leaf) {
        $path = '/run/audit/evidence/' . $leaf; $wantedPrerequisites[$key] = $proofs[$path]['pin'];
    }
    nhecr_expect($binding['prerequisites'] === $wantedPrerequisites, 'NHECR_BINDING_ORIGINAL_TWO_NORMAL_RETIRES');
    $mountinfo = [];
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = explode(' ', $parts[1] ?? '');
        $mountinfo[$left[4] ?? ''] = ['options' => explode(',', $left[5] ?? ''), 'type' => $right[0] ?? ''];
    }
    foreach ($mounts as $row) nhecr_expect(isset($mountinfo[$row['Destination']])
        && in_array($row['RW'] ? 'rw' : 'ro', $mountinfo[$row['Destination']]['options'], true), 'NHECR_NATIVE_MOUNTINFO_ACCESS');
    foreach (['/tmp', '/current-runtime'] as $path) nhecr_expect(($mountinfo[$path]['type'] ?? '') === 'tmpfs'
        && in_array('rw', $mountinfo[$path]['options'], true), 'NHECR_NATIVE_PRIVATE_TMPFS');
    clearstatcache(true, '/current-runtime'); $runtime = lstat('/current-runtime');
    nhecr_expect(is_array($runtime) && !is_link('/current-runtime') && ($runtime['mode'] & 07777) === 0700
        && $runtime['uid'] === posix_geteuid(), 'NHECR_NATIVE_PRIVATE_RUNTIME_0700');
    $trees = []; foreach (['/workspace', '/source'] as $root)
        $trees[$root] = nhe_tree($root, $current['source_files'], $current['source_directories']);
    $self = audit_app_proc_stat(getmypid()); nhecr_expect(is_array($self) && !in_array($self['state'], ['Z', 'X', 'x'], true), 'NHECR_REAL_CURRENT_PROCESS');
    $command = $httpChild ? [PHP_BINARY, '-S', '127.0.0.1:8000', '-t', '/workspace/crmeb/public', __FILE__]
        : array_merge(['php'], $created['command']);
    nhecr_expect(nhe_proc_command($self['pid']) === $command, 'NHECR_ACTUAL_FIXED_READER_OR_HTTP_COMMAND');
    $state = ['freeze_pin' => $freezePin, 'authority' => $authority, 'current_authority' => $current, 'context' => $context,
        'context_raw' => $contextFile['raw'], 'manifest_raw' => $manifestFile['raw'], 'created_raw' => $createdRaw,
        'binding_raw' => $bindingRaw, 'container_id' => $created['id'], 'source_stats' => $trees, 'process' => $self,
        'handoff' => $handoff, 'history_actors_receipt' => $actorReceipt, 'public_inputs' => $proofs, 'actor_public_inputs' => $actorProofs];
    $initializerProofs = []; $state['initializer_self'] = nhecr_initializer_self($state, $initializerProofs);
    $state['initializer_public_inputs'] = $initializerProofs;
    if ($httpChild) {
        $parentFile = nhecr_parent_file($state); $parent = $parentFile['native'];
        nhecr_expect($self['ppid'] === $parent['pid'] && $self['pid'] > 1 && $self['pgid'] === $self['pid'] && $self['sid'] === $self['pid']
            && !in_array($self['pid'], [$parent['pid'], $parent['pgid'], $parent['sid']], true), 'NHECR_HTTP_DIRECT_OWN_SETSID_CHILD');
        $state['parent_process'] = $parentFile;
    } else nhecr_expect(($_SERVER['argv'] ?? null) === $created['command'], 'NHECR_REAL_NORMAL_PARENT_ARGUMENTS');
    nhecr_includes($state); audit_guard_assert(); return $state;
}


function nhecr_db_capture(PDO $db, string $runId): array
{
    m4lh_actor_identity($db, $runId); $modes = nhep_database_modes($db);
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    nhep_expect(count($names) === 166 && count(array_unique($names, SORT_STRING)) === 166,
        'NHE_DATABASE_COMPLETE_CURRENT_166_TABLES');
    $database = ['names' => $names, 'modes' => $modes, 'tables' => []]; $allRows = [];
    foreach ($names as $name) {
        nhep_expect(is_string($name) && preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D', $name) === 1,
            'NHE_DATABASE_ONLY_ACTUAL_OWN_TABLE_NAMESPACE');
        $metadata = m4m_metadata($db, $name); $columns = array_keys($metadata['columns']);
        nhep_expect($columns !== [], 'NHE_DATABASE_COMPLETE_NONEMPTY_COLUMN_ORDER');
        $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($name));
        foreach ($rows as $row) nhep_expect(array_keys($row) === $columns, 'NHE_DATABASE_ALL_NATIVE_COLUMNS_IN_ORIGINAL_ORDER');
        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));
        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);
        nhep_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'NHE_DATABASE_COMPLETE_ORIGINAL_DDL');
        $database['tables'][$name] = ['metadata' => $metadata, 'ddl' => $ddl[1],
            'projected_columns' => $columns, 'projection' => m4lh_actor_projection($rows)];
        $allRows[$name] = $rows;
    }
    nhep_expect(nhep_database_modes($db) === $modes, 'NHE_DATABASE_OBSERVER_MODES_PRESERVED_DURING_FULL_SCAN');
    return ['database' => $database, 'rows' => $allRows];
}

function nhecr_db_expected(array $before, array $expectedRows, array $insertCounts = []): array
{
    nhe_keys($before, ['database', 'rows'], 'NHE_DATABASE_COMPLETE_BEFORE_MODEL_KEYS');
    nhe_keys($before['database'], ['names', 'modes', 'tables'], 'NHE_DATABASE_ORIGINAL_DATABASE_MODEL_KEYS');
    $names = $before['database']['names']; $sorted = $names; sort($sorted, SORT_STRING);
    nhep_expect(array_is_list($names) && count($names) === 166 && $names === $sorted
        && count(array_unique($names, SORT_STRING)) === 166
        && array_keys($before['database']['tables']) === $names && array_keys($before['rows']) === $names
        && array_diff(array_keys($expectedRows), $names) === []
        && array_diff(array_keys($insertCounts), array_keys($expectedRows)) === [], 'NHE_DATABASE_COMPLETE_BEFORE_AND_EXPLICIT_MODEL_TABLES');
    $expected = $before;
    foreach ($expectedRows as $name => $rows) {
        $old = $before['database']['tables'][$name];
        nhep_expect(is_array($rows) && array_is_list($rows)
            && $old['projected_columns'] === array_keys($old['metadata']['columns']), 'NHE_DATABASE_ORIGINAL_FULL_EXPECTED_ROW_COLUMNS');
        foreach ($rows as $row) nhep_expect(is_array($row) && array_keys($row) === $old['projected_columns'],
            'NHE_DATABASE_EACH_INDEPENDENT_EXPECTED_FULL_ROW');
        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));
        $expected['rows'][$name] = $rows;
        $expected['database']['tables'][$name]['projection'] = m4lh_actor_projection($rows);
        $count = $insertCounts[$name] ?? 0;
        nhep_expect(is_int($count) && $count >= 0 && $count <= 4294967294, 'NHE_DATABASE_INDEPENDENT_NATURAL_INSERT_COUNT');
        if ($count === 0) continue;
        $auto = [];
        foreach ($old['metadata']['columns'] as $column => $definition)
            if ($definition['EXTRA'] === 'auto_increment') $auto[] = $column;
        nhep_expect(count($auto) === 1, 'NHE_DATABASE_COUNTER_ONLY_ON_ORIGINAL_AUTO_INCREMENT_COLUMN');
        $id = m4lh_actor_next($old, $auto[0]); $next = $id + $count;
        nhep_expect($next <= 4294967295, 'NHE_DATABASE_ORIGINAL_NORMAL_BUSINESS_COUNTER_RANGE');
        $counter = $old['metadata']['table']['AUTO_INCREMENT'];
        $expected['database']['tables'][$name]['metadata']['table']['AUTO_INCREMENT'] = is_int($counter) ? $next : (string)$next;
        $expected['database']['tables'][$name]['ddl'] = m4lh_actor_counter_ddl($old['ddl'], $id, $next);
    }
    return $expected;
}

function nhecr_db_validate(array $view): void
{
    nhe_keys($view, ['database', 'rows'], 'NHE_AUTH_DB_FULL_VIEW');
    nhe_keys($view['database'], ['names', 'modes', 'tables'], 'NHE_AUTH_DB_FULL_DATABASE');
    $names = $view['database']['names']; $sorted = $names; sort($sorted, SORT_STRING);
    nhep_expect(array_is_list($names) && count($names) === 166 && $names === $sorted
        && count(array_unique($names, SORT_STRING)) === 166 && is_array($view['database']['modes'])
        && array_keys($view['database']['tables']) === $names && array_keys($view['rows']) === $names,
        'NHE_AUTH_DB_ORIGINAL_ALL_166_TABLES');
    foreach ($names as $name) {
        $table = $view['database']['tables'][$name];
        nhe_keys($table, ['metadata', 'ddl', 'projected_columns', 'projection'], 'NHE_AUTH_DB_FULL_TABLE');
        nhep_expect(is_array($table['metadata']) && is_array($table['metadata']['columns'] ?? null)
            && is_string($table['ddl']) && $table['ddl'] !== ''
            && $table['projected_columns'] === array_keys($table['metadata']['columns'])
            && is_array($view['rows'][$name]) && array_is_list($view['rows'][$name]),
            'NHE_AUTH_DB_ORIGINAL_COLUMNS_DDL_AND_ROWS');
        foreach ($view['rows'][$name] as $row) nhep_expect(is_array($row)
            && array_keys($row) === $table['projected_columns'], 'NHE_AUTH_DB_EACH_FULL_ORIGINAL_ROW');
        $rows = $view['rows'][$name];
        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));
        nhep_expect($rows === $view['rows'][$name]
            && m4lh_actor_projection($rows) === $table['projection'], 'NHE_AUTH_DB_FULL_NATIVE_PROJECTION');
    }
    foreach (['eb_system_admin', 'eb_user', 'eb_system_log', 'eb_system_menus',
        'eb_marketplace_merchant', 'eb_marketplace_employee', 'eb_marketplace_audit_log',
        'eb_system_event', 'eb_system_notification', 'eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'] as $name)
        nhep_expect(in_array($name, $names, true), 'NHE_AUTH_DB_REQUIRED_REAL_TABLE');
}

function nhecr_actors_read(#[SensitiveParameter] array $qualified, #[SensitiveParameter] array $receipt): array
{
    $oldIgnoreArgs = ini_set('zend.exception_ignore_args', '1');
    nhecr_expect($oldIgnoreArgs !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHECR_ACTORS_REAL_PRIVATE_EXCEPTION_ARGUMENT_BOUNDARY');
    nhecr_expect(PHP_SAPI === 'cli', 'NHECR_PRIVATE_ACTORS_ONLY_REAL_READER_PARENT');
    $stream = null; $failure = null; $private = null;
    try {
        $actual = nhecr_fresh($qualified);
        nhecr_expect($receipt === $actual['history_actors_receipt'], 'NHECR_ACTORS_SAME_FRESH_CURRENT_PREPARE_RECEIPT');
        nhecr_keys($receipt, ['prepare_proof', 'fixture_mount', 'prepare_remove']); $proof = $receipt['prepare_proof'];
        $facts = json_decode($actual['created_raw'], true, 128, JSON_THROW_ON_ERROR);
        $manifest = json_decode($actual['manifest_raw'], true, 128, JSON_THROW_ON_ERROR);
        nhecr_expect(is_array($facts) && is_array($manifest) && ($facts['id'] ?? null) === $actual['container_id']
            && ($facts['labels']['com.docker.compose.service'] ?? null) === 'audit-legacy-current-reader'
            && ($actual['process']['pid'] ?? null) === getmypid(), 'NHECR_ACTORS_ONLY_FRESH_QUALIFIED_CURRENT_READER');
        $mount = nhecr_fixture_mount($facts, $actual['context']); $creatorMount = $mount; $creatorMount['RW'] = true;
        $volumeName = $mount['Name'];
        nhecr_expect($receipt['fixture_mount'] === $creatorMount && in_array($volumeName, $manifest['volumes'], true)
            && ($manifest['volume_identities'][$volumeName] ?? null) === $proof['volume']['created_at'],
            'NHECR_ACTORS_SAME_REGISTERED_PHYSICAL_CREATOR_RW_TO_READER_RO');
        $createdRaw = nhe_file('/run/audit/evidence/prepare-created.json', $proof['prepare_created']);
        nhecr_expect($actual['actor_public_inputs']['/run/audit/evidence/prepare-created.json']['raw'] === $createdRaw,
            'NHECR_ACTORS_SAME_ORIGINAL_PREPARE_CREATED_BYTES');
        $beforeTree = nhecr_file_tree('/run/audit-fixtures');
        nhecr_expect(is_array($beforeTree) && isset($beforeTree[''], $beforeTree['nonempty-history-actors.json'])
            && $beforeTree['']['kind'] === 'directory' && $beforeTree['nonempty-history-actors.json']['kind'] === 'file',
            'NHECR_ACTORS_COMPLETE_ORIGINAL_PRIVATE_TREE');
        $directory = $beforeTree['']['stat']; $originalFile = nhecr_stat9($proof['stat9']);
        nhecr_expect(($directory['mode'] & 0170000) === 0040000 && ($directory['mode'] & 07777) === 0700
            && $directory['uid'] === posix_geteuid() && $beforeTree['nonempty-history-actors.json']['stat'] === $originalFile,
            'NHECR_ACTORS_PRIVATE_TREE_OWNER_AND_ORIGINAL_FILE_STAT9');
        $path = '/run/audit-fixtures/nonempty-history-actors.json'; clearstatcache(true, $path); $before = lstat($path);
        nhecr_expect(is_array($before) && is_file($path) && !is_link($path) && realpath($path) === $path,
            'NHECR_ACTORS_ORIGINAL_NAMED_PRIVATE_FILE');
        $before = nhecr_stat9($before);
        nhecr_expect($before === $originalFile && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 07777) === 0600
            && $before['uid'] === posix_geteuid() && $before['nlink'] === 1
            && $before['dev'] === $directory['dev'] && $before['size'] > 0 && $before['size'] <= 4096,
            'NHECR_ACTORS_ORIGINAL_SINGLE_LINK_0600_FILE_IDENTITY');
        $stream = fopen($path, 'rb'); nhecr_expect(is_resource($stream), 'NHECR_ACTORS_ORIGINAL_PRIVATE_READONLY_HANDLE');
        $opened = fstat($stream); clearstatcache(true, $path); $named = lstat($path);
        nhecr_expect(is_array($opened) && is_array($named) && nhecr_stat9($opened) === $before && nhecr_stat9($named) === $before
            && !is_link($path) && realpath($path) === $path, 'NHECR_ACTORS_ORIGINAL_HANDLE_AND_PATH_STAT9');
        $raw = stream_get_contents($stream, 4097);
        nhecr_expect(is_string($raw) && strlen($raw) === $before['size'] && fgetc($stream) === false && feof($stream)
            && $beforeTree['nonempty-history-actors.json']['bytes'] === strlen($raw)
            && $beforeTree['nonempty-history-actors.json']['sha256'] === hash('sha256', $raw),
            'NHECR_ACTORS_COMPLETE_BOUNDED_ORIGINAL_PRIVATE_BYTES');
        $after = fstat($stream); clearstatcache(true, $path); $named = lstat($path);
        nhecr_expect(is_array($after) && is_array($named) && nhecr_stat9($after) === $before && nhecr_stat9($named) === $before
            && !is_link($path) && realpath($path) === $path, 'NHECR_ACTORS_FULL_PRIVATE_FD_AND_NAMED_STAT9_STABLE');
        $private = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); nhecr_expect(is_array($private), 'NHECR_ACTORS_PRIVATE_OBJECT');
        m4lh_actor_private($private);
        foreach ($private as $value) nhecr_expect(is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1,
            'NHECR_ACTORS_CURRENT_ORIGINAL_FOUR_RANDOM_VALUES');
        nhecr_expect(json_encode($private, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" === $raw,
            'NHECR_ACTORS_EXACT_FOUR_CANONICAL_PRIVATE_JSON');
        nhecr_expect(fclose($stream), 'NHECR_ACTORS_ORIGINAL_PRIVATE_READ_HANDLE_CLOSED'); $stream = null;
        clearstatcache(true, $path); $closed = lstat($path);
        nhecr_expect(is_array($closed) && nhecr_stat9($closed) === $before && nhecr_file_tree('/run/audit-fixtures') === $beforeTree,
            'NHECR_ACTORS_COMPLETE_ORIGINAL_PRIVATE_TREE_AFTER_CLOSE');
        $again = nhecr_fresh($qualified);
        foreach (['freeze_pin', 'context', 'container_id', 'created_raw', 'manifest_raw', 'history_actors_receipt', 'actor_public_inputs'] as $key)
            nhecr_expect($again[$key] === $actual[$key], 'NHECR_ACTORS_SAME_FRESH_SOURCE_ROLE_PUBLIC_INPUTS_AFTER_READ');
        nhecr_expect(nhecr_fixture_mount($facts, $actual['context']) === $mount
            && nhe_file('/run/audit/evidence/prepare-created.json', $proof['prepare_created']) === $createdRaw,
            'NHECR_ACTORS_SAME_RO_MOUNT_AND_ORIGINAL_PREPARE_PROVENANCE');
        unset($raw, $value, $beforeTree);
    } catch (Throwable $ignored) {
        unset($private); $private = null; $failure = new RuntimeException('NHECR_PRIVATE_ACTORS_READ_FAILED_STATE_PRESERVED');
    } finally {
        if (is_resource($stream)) {
            try { if (!fclose($stream) && $failure === null) $failure = new RuntimeException('NHECR_PRIVATE_READ_CLOSE_FAILED_STATE_PRESERVED'); }
            catch (Throwable $ignored) { if ($failure === null) $failure = new RuntimeException('NHECR_PRIVATE_READ_CLOSE_FAILED_STATE_PRESERVED'); }
        }
        unset($raw, $value, $beforeTree);
    }
    if ($failure !== null) throw $failure;
    nhecr_expect(is_array($private), 'NHECR_PRIVATE_FOUR_VALUES_REQUIRED_IN_RAM_ONLY'); return $private;
}

/** Fresh real fixed-role qualification, never a caller supplied closure/ACK. */
function nhecr_fresh(array $qualified): array
{
    $actual = nhecr_qualify($qualified['freeze_pin']);
    foreach (['freeze_pin', 'authority', 'current_authority', 'context', 'context_raw', 'manifest_raw', 'created_raw',
        'binding_raw', 'container_id', 'source_stats', 'handoff', 'history_actors_receipt', 'public_inputs', 'actor_public_inputs',
        'initializer_self', 'initializer_public_inputs'] as $key)
        nhecr_expect($qualified[$key] === $actual[$key], 'NHECR_FRESH_COMPLETE_ORIGINAL_INPUTS_UNCHANGED');
    foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $key)
        nhecr_expect($qualified['process'][$key] === $actual['process'][$key], 'NHECR_REAL_SAME_PARENT_NATIVE_FIVE');
    return $actual;
}

function nhecr_parent_file(array $state): array
{
    $path = '/current-runtime/native-parent.json'; clearstatcache(true, $path); $before = lstat($path);
    nhecr_expect(is_array($before) && is_file($path) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 07777) === 0600 && $before['uid'] === posix_geteuid()
        && $before['nlink'] === 1 && $before['size'] > 0 && $before['size'] <= 4096,
        'NHECR_ORIGINAL_PRIVATE_NATIVE_PARENT_SINGLE_LINK_0600');
    $raw = nhe_file($path, ['path' => $path, 'bytes' => $before['size'], 'sha256' => hash_file('sha256', $path)]);
    $receipt = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    nhecr_keys($receipt, ['schema', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'pid', 'starttime']);
    nhecr_expect($receipt['schema'] === 1 && $receipt['run_id'] === $state['context']['run_id']
        && $receipt['source_sha'] === $state['context']['source_sha'] && $receipt['freeze_sha256'] === $state['freeze_pin']['sha256']
        && $receipt['container_id'] === $state['container_id'] && is_int($receipt['pid']) && $receipt['pid'] > 0
        && is_string($receipt['starttime']) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $receipt['starttime']) === 1,
        'NHECR_SAME_REAL_ROLE_RUN_SOURCE_PRIVATE_PARENT_RECEIPT');
    $native = audit_app_proc_stat($receipt['pid']);
    nhecr_expect(is_array($native) && !in_array($native['state'], ['Z', 'X', 'x'], true)
        && $native['starttime'] === $receipt['starttime']
        && nhe_proc_command($native['pid']) === array_merge(['php'], json_decode($state['created_raw'], true, 128, JSON_THROW_ON_ERROR)['command']),
        'NHECR_ORIGINAL_LIVE_PARENT_NATIVE_COMMAND_STARTTIME');
    clearstatcache(true, $path); $after = lstat($path);
    nhecr_expect(is_array($after) && nhecr_stat9($before) === nhecr_stat9($after), 'NHECR_PARENT_FILE_STAT9_HELD');
    return ['receipt' => $receipt, 'native' => $native, 'stat9' => nhecr_stat9($after)];
}

function nhecr_parent_write(array $state): array
{
    $state = nhecr_fresh($state); $path = '/current-runtime/native-parent.json';
    nhecr_expect(!file_exists($path) && !is_link($path), 'NHECR_PRIVATE_PARENT_CREATE_ONLY');
    $receipt = ['schema' => 1, 'run_id' => $state['context']['run_id'], 'source_sha' => $state['context']['source_sha'],
        'freeze_sha256' => $state['freeze_pin']['sha256'], 'container_id' => $state['container_id'],
        'pid' => getmypid(), 'starttime' => $state['process']['starttime']];
    $mask = umask(0077); try { $handle = fopen($path, 'xb'); } finally { umask($mask); }
    nhecr_expect(is_resource($handle), 'NHECR_REAL_PARENT_CREATE_ONLY_HANDLE'); $failure = null;
    try {
        $bytes = nhe_json($receipt); $offset = 0;
        while ($offset < strlen($bytes)) { $written = fwrite($handle, substr($bytes, $offset));
            nhecr_expect(is_int($written) && $written > 0, 'NHECR_COMPLETE_PARENT_PRIVATE_WRITE'); $offset += $written; }
        nhecr_expect(fflush($handle), 'NHECR_PARENT_PRIVATE_FLUSH');
        $stat = fstat($handle); nhecr_expect(is_array($stat) && ($stat['mode'] & 07777) === 0600
            && $stat['nlink'] === 1 && $stat['uid'] === posix_geteuid() && $stat['size'] === strlen($bytes), 'NHECR_PARENT_CREATED_HANDLE_REAL_0600');
    } catch (Throwable $error) { $failure = $error; throw $error; }
    finally { try { nhecr_expect(fclose($handle), 'NHECR_PARENT_HANDLE_CLOSED'); } catch (Throwable $close) { if ($failure === null) throw $close; } }
    $proof = nhecr_parent_file($state); nhecr_expect($proof['receipt'] === $receipt, 'NHECR_PARENT_CREATE_FULL_READBACK'); return $proof;
}

/** Full166 capture must join the original completed migration snapshot without discarding new columns. */
function nhecr_baseline(array $state, array $database, array $cache): array
{
    nhecr_db_validate($database); nhepar_capture($cache);
    $handoff = $state['handoff'];
    nhecr_keys($handoff, ['schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'legacy_parent', 'identities',
        'seal', 'migration', 'driver_after', 'current_reader_authorized', 'remaining']);
    nhecr_expect($handoff['schema'] === 1 && $handoff['scope'] === 'CURRENT_READER_SEALED_BASELINE_PREREQUISITES_ONLY'
        && $handoff['run_id'] === $state['context']['run_id'] && $handoff['source_sha'] === $state['context']['source_sha']
        && $handoff['freeze_sha256'] === $state['context']['freeze_sha256'] && $handoff['current_reader_authorized'] === false
        && $handoff['remaining'] === 'CURRENT_ROLE_RETIREMENT_ROOT_SOURCE_AND_PRIVATE_FIXTURE_CONTEXT_UNBOUND',
        'NHECR_BASELINE_ONLY_REAL_EXACT13_FOURCLI_PREREQUISITES');
    $raw = $state['public_inputs']['/run/audit/evidence/ms4-migration-normal.json']['raw'];
    $driver = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
    $seal = json_decode($state['public_inputs']['/run/audit/evidence/legacy-history-producer-result.json']['raw'], true, 256, JSON_THROW_ON_ERROR);
    nhecr_expect($seal === $handoff['seal'] && $driver['snapshots']['before'] === $seal['expected_m4m']
        && $driver['snapshots']['after'] === $handoff['driver_after'] && $handoff['identities'] === $seal['identities']
        && $handoff['legacy_parent'] === $seal['producer']['native_parent'], 'NHECR_BASELINE_SAME_ORIGINAL_VALIDATED_PUBLIC_OBJECTS');
    $after = $handoff['driver_after']; nhecr_keys($after, ['names', 'tables', 'redis', 'modes']);
    nhecr_expect(count($after['names']) === 166 && array_keys($after['tables']) === $after['names'],
        'NHECR_BASELINE_COMPLETE166_TABLE_MAP_NO_SKIPPED_OR_EXTRA_TABLE');
    nhecr_expect($database['database']['names'] === $after['names'], 'NHECR_REAL_166_NAMES_AFTER_ORIGINAL_FOUR_CLI');
    $modes = $database['database']['modes'][0]; $selected = [];
    foreach (['session_mode', 'global_mode', 'session_innodb', 'global_innodb'] as $key) $selected[$key] = $modes[$key];
    nhecr_expect([$selected] === $after['modes'], 'NHECR_ORIGINAL_FOUR_OBSERVER_MODES');
    foreach ($after['tables'] as $name => $expected) {
        $table = $database['database']['tables'][$name];
        nhecr_expect($table['metadata'] === $expected['metadata'] && hash('sha256', $table['ddl']) === $expected['ddl_sha256'],
            'NHECR_COMPLETE_POST_MIGRATION_NATIVE_METADATA_DDL_AI');
        $hashes = [];
        foreach ($database['rows'][$name] as $row) {
            $projected = []; foreach ($expected['projected_columns'] as $column) $projected[$column] = $row[$column];
            $hashes[] = hash('sha256', json_encode($projected, JSON_THROW_ON_ERROR));
        }
        sort($hashes, SORT_STRING);
        nhecr_expect(['rows' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))] === $expected['projection'],
            'NHECR_INDEPENDENT_FULL_CURRENT_ROWS_ORIGINAL_COLUMN_PROJECTION');
    }
    foreach (['eb_store_order' => ['marketplace_trade_version' => 0, 'merchant_name_snapshot' => null, 'merchant_after_sale_contact_snapshot' => null],
        'eb_store_order_cart_info' => ['mer_id' => null], 'eb_store_order_refund' => ['mer_id' => null, 'marketplace_refund_mode' => null],
        'eb_store_product_reply' => ['mer_id' => null]] as $table => $values)
        foreach ($database['rows'][$table] as $row) foreach ($values as $key => $value)
            nhecr_expect($row[$key] === $value, 'NHECR_SEVEN_ORIGINAL_ADDED_NATIVE_DEFAULTS_NOT_BACKFILL');
    $expectedRedis = $after['redis'];
    foreach ($expectedRedis as $key => $descriptor) if ($descriptor['expires'] > 0
        && bccomp($cache['snapshot']['time_ms'], (string)$descriptor['expires'], 0) > 0) unset($expectedRedis[$key]);
    $currentRedis = [];
    foreach ($cache['snapshot']['keys'] as $key => $descriptor) $currentRedis[$key] = ['sha256' => $descriptor['sha256'], 'expires' => $descriptor['expires']];
    nhecr_expect($currentRedis === $expectedRedis, 'NHECR_ONLY_REAL_TIME_PASSIVE_EXPIRY_AFTER_WRITERS_STOPPED');
    return $state['handoff']['identities'];
}


function nhecr_child_environment(array $qualified): array
{
    $actual = nhecr_fresh($qualified); $parent = nhecr_parent_file($actual);
    nhep_expect($parent['receipt']['pid'] === getmypid()
        && $parent['receipt']['starttime'] === $actual['process']['starttime'], 'NHE_ENVIRONMENT_ONLY_SAME_RECEIPTED_PARENT');
    $original = getenv();
    nhep_expect(is_array($original), 'NHE_ACTUAL_PARENT_ENVIRONMENT_REQUIRED');
    foreach ($original as $key => $value) nhep_expect(is_string($key) && $key !== '' && !str_contains($key, '=')
        && !str_contains($key, "\0") && is_string($value) && !str_contains($value, "\0"), 'NHE_NATIVE_PARENT_ENVIRONMENT_FIELDS');
    $required = ['AUDIT_ISOLATED' => '1', 'AUDIT_RUN_ID' => $actual['context']['run_id'], 'AUDIT_RUN_DIR' => '/run/audit',
        'AUDIT_REPO_DIR' => '/workspace', 'AUDIT_PROJECT' => $actual['context']['project'],
        'AUDIT_SOURCE_SHA' => $actual['context']['source_sha'], 'AUDIT_GUARD_MODE' => 'read-only'];
    foreach ($required as $key => $value)
        nhep_expect(($original[$key] ?? null) === $value, 'NHE_CHILD_PRESERVES_REAL_QUALIFIED_AUDIT_ENVIRONMENT');
    $environment = $original;
    foreach (array_keys($environment) as $name) if (str_starts_with($name, 'PHP_') || in_array($name, ['PHPRC', 'PHP_INI_SCAN_DIR', 'TZ', 'TZDIR'], true)) unset($environment[$name]);
    foreach (['NHE_CHILD_FREEZE_BYTES' => (string)$actual['freeze_pin']['bytes'],
        'NHE_CHILD_FREEZE_SHA256' => $actual['freeze_pin']['sha256']] as $key => $value) {
        nhep_expect(!array_key_exists($key, $original) || $original[$key] === $value, 'NHE_NO_CONFLICTING_PARENT_FREEZE_ENVIRONMENT');
        $environment[$key] = $value;
    }
    nhep_expect(getenv() === $original, 'NHE_ACTUAL_PARENT_ENVIRONMENT_UNCHANGED');
    return $environment;
}

function nhecr_child_start(array $qualified, array &$children, string $kind): void
{
    nhep_expect($kind === 'http' && !array_key_exists($kind, $children)
        && array_diff(array_keys($children), ['http', 'channel']) === [], 'NHE_CHILD_NEW_FIXED_ROLE_ONLY');
    foreach (['proc_open', 'proc_get_status', 'proc_close', 'posix_kill', 'pcntl_waitpid', 'audit_app_proc_stat',
        'audit_app_group_members', 'audit_app_tcp_rows'] as $function)
        nhep_expect(function_exists($function), 'NHE_CHILD_NATIVE_LIFECYCLE_FUNCTIONS_REQUIRED');
    $actual = nhecr_fresh($qualified); $environment = nhecr_child_environment($qualified);
    nhep_expect($kind !== 'http' || !array_key_exists('PHP_CLI_SERVER_WORKERS', $environment), 'NHE_HTTP_NO_ADDITIONAL_SERVER_WORKERS');
    $httpTimeReference = null;
    if ($kind === 'http') {
        nhep_expect(!array_key_exists('TZ', $environment) && !array_key_exists('TZDIR', $environment),
            'NHE_HTTP_ORIGINAL_FRESH_EXEC_ENVIRONMENT_WITHOUT_SYSTEM_TIME_OVERRIDES');
        $httpTimeReference = nhep_http_time_reference();
    }
    $parent = nhecr_parent_file($actual);
    nhep_expect($parent['receipt']['pid'] === getmypid() && $parent['receipt']['starttime'] === $actual['process']['starttime'],
        'NHE_CHILD_REAL_PRIVATE_PARENT_RECEIPT_BEFORE_START');
    nhep_expect(!nhep_child_listener($kind)['open'], 'NHE_CHILD_NO_PREEXISTING_FIXED_LISTENER');
    $command = $kind === 'http'
        ? ['setsid', PHP_BINARY, '-S', '127.0.0.1:8000', '-t', '/workspace/crmeb/public', '/run/nonempty/legacy-current-reader.php']
        : ['setsid', PHP_BINARY, '/run/nonempty/legacy-history-channel.php', 'start',
            '--freeze-bytes=' . $actual['freeze_pin']['bytes'], '--freeze-sha256=' . $actual['freeze_pin']['sha256']];
    $directory = '/current-runtime'; clearstatcache(true, $directory); $runtime = lstat($directory);
    nhep_expect(is_array($runtime) && is_dir($directory) && !is_link($directory) && realpath($directory) === $directory
        && ($runtime['mode'] & 0777) === 0700 && $runtime['uid'] === posix_geteuid(), 'NHE_CHILD_PRIVATE_0700_RUNTIME');
    $children[$kind] = ['kind' => $kind, 'process' => null, 'group' => null, 'supervisor' => $actual['process'],
        'command' => $command, 'streams' => [], 'first_native' => null, 'last_native' => null, 'first_terminal' => null,
        'native_pid' => null, 'observation' => null, 'term_attempted' => false, 'term_sent' => false,
        'waits' => [], 'closed' => null, 'subscriber' => null, 'run_id' => $actual['context']['run_id'],
        'http_time_reference' => $httpTimeReference, 'phase' => 'private_streams'];
    $child =& $children[$kind];
    $child['observe'] = static function () use (&$children, $kind): array {
        return ['native' => nhep_child_native($children[$kind]), 'group' => $children[$kind]['group']];
    };
    try {
        foreach (['stdout', 'stderr'] as $stream) {
            $path = $directory . '/current-' . $kind . '.' . $stream;
            nhep_expect(!file_exists($path) && !is_link($path), 'NHE_CHILD_CREATE_ONLY_PRIVATE_NATIVE_STREAM');
            $mask = umask(0077);
            try { $handle = fopen($path, 'x+b'); } finally { umask($mask); }
            $child['streams'][$stream] = ['path' => $path, 'handle' => $handle, 'created' => is_resource($handle) ? fstat($handle) : false];
            $stat = $child['streams'][$stream]['created']; clearstatcache(true, $path); $named = lstat($path);
            nhep_expect(is_resource($handle) && is_array($stat) && is_array($named) && is_file($path) && !is_link($path)
                && ($stat['mode'] & 0777) === 0600 && $stat['uid'] === posix_geteuid() && $stat['nlink'] === 1
                && $stat['dev'] === $runtime['dev'] && $stat['size'] === 0 && $named === $stat, 'NHE_CHILD_NATIVE_PRIVATE_STREAM_IDENTITY');
        }
        $child['phase'] = 'native_start';
        if ($kind === 'http') nhep_http_time_reference($child['http_time_reference']);
        $process = proc_open($command, [['file', '/dev/null', 'r'], $child['streams']['stdout']['handle'],
            $child['streams']['stderr']['handle']], $pipes, '/workspace/crmeb', $environment);
        $child['process'] = $process; unset($environment);
        nhep_expect(is_resource($process), 'NHE_CHILD_ACTUAL_PROC_OPEN_RETURN');
        $until = hrtime(true) + 1000000000;
        do {
            $status = nhep_child_native($child); $group = nhep_child_group($child, $status);
            if ($group !== null) { $child['group'] = $group; break; }
            if (!$status['running']) break;
            usleep(5000);
        } while (hrtime(true) < $until);
        nhep_expect(is_array($child['group']), 'NHE_CHILD_REAL_GROUP_OBSERVED_BEFORE_TERMINAL');
        $child['phase'] = 'native_readiness'; $until = hrtime(true) + 5000000000; $ready = false;
        do {
            $status = nhep_child_native($child);
            if (!$status['running']) break;
            $members = audit_app_group_members($child['group'], $child['supervisor']); $listener = nhep_child_listener($kind);
            if (count($members) === ($kind === 'http' ? 1 : 2) && count($listener['rows']) === 1) {
                nhep_child_observation($children, $kind); $ready = true; break;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        nhep_expect($ready, 'NHE_CHILD_SAME_HANDLE_NATIVE_READINESS_NOT_OBSERVED'); $child['phase'] = 'native_ready';
    } catch (Throwable $failure) {
        $child['failure_class'] = get_class($failure); unset($environment); throw $failure;
    }
}

function nhecr_headers_model(array $intent, #[SensitiveParameter] array $privateRequest,
    #[SensitiveParameter] array $privateResponse, #[SensitiveParameter] array $beforeCookieIds): array
{
    foreach (['nhep_expect', 'nhe_keys'] as $dependency)
        if (!function_exists($dependency)) throw new RuntimeException('NHE_AUTH_HEADER_PURE_DEPENDENCY_REQUIRED');
    nhe_keys($intent, ['run_id', 'sequence', 'step', 'origin', 'method', 'path', 'actor', 'actor_id'], 'NHE_AUTH_HEADER_ORIGINAL_INTENT');
    nhecr_intent($intent); $step = $intent['step']; $sequence = $intent['sequence']; $method = $intent['method'];
    nhe_keys($privateRequest, ['token', 'body', 'actor_identity'], 'NHE_AUTH_HEADER_ORIGINAL_PRIVATE_REQUEST');
    nhep_expect(is_array($privateRequest['actor_identity']), 'NHE_AUTH_HEADER_PRIVATE_ACTOR_OBJECT');
    nhe_keys($privateRequest['actor_identity'], ['id', 'account'], 'NHE_AUTH_HEADER_PRIVATE_ACTOR_KEYS');
    nhep_expect($privateRequest['actor_identity']['id'] === $intent['actor_id']
        && is_string($privateRequest['actor_identity']['account'])
        && preg_match('/\A[a-z0-9_]{10,32}\z/D', $privateRequest['actor_identity']['account']) === 1
        && (($method === 'GET') ? $privateRequest['body'] === null : is_array($privateRequest['body'])),
        'NHE_AUTH_HEADER_ORIGINAL_ACTOR_AND_BODY_BINDING');
    $login = $step === 'admin_login' || $step === 'customer_login';
    nhep_expect($login ? $privateRequest['token'] === null : (is_string($privateRequest['token'])
        && preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/D', $privateRequest['token']) === 1),
        'NHE_AUTH_HEADER_ORIGINAL_LOGIN_OR_AUTHENTICATED_CALL');

    nhep_expect(array_is_list($beforeCookieIds) && count($beforeCookieIds) === $sequence - 1,
        'NHE_AUTH_HEADER_ALL_PREVIOUS_PRIVATE_COOKIE_OBSERVATIONS');
    $seen = [];
    foreach ($beforeCookieIds as $id) {
        nhep_expect(is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1
            && !isset($seen[$id]), 'NHE_AUTH_HEADER_PREVIOUS_UNIQUE_NATIVE_MD5_IDS');
        $seen[$id] = true;
    }
    $interval = $privateResponse['actual_interval'] ?? null;
    nhep_expect(is_array($interval) && array_is_list($interval) && count($interval) === 2
        && is_int($interval[0]) && is_int($interval[1]) && $interval[0] > 0
        && $interval[0] <= $interval[1] && $interval[1] <= 4294967295
        && ($privateResponse['http'] ?? null) === 200 && ($privateResponse['transport_errno'] ?? null) === 0
        && array_key_exists('transport_failure_class', $privateResponse) && $privateResponse['transport_failure_class'] === null
        && ($privateResponse['primary_ip'] ?? null) === '127.0.0.1' && ($privateResponse['primary_port'] ?? null) === 8000
        && ($privateResponse['local_ip'] ?? null) === '127.0.0.1' && is_int($privateResponse['local_port'] ?? null)
        && $privateResponse['local_port'] > 0 && $privateResponse['local_port'] <= 65535
        && is_string($privateResponse['raw'] ?? null) && strlen($privateResponse['raw']) <= 6291456
        && is_array($privateResponse['json'] ?? null), 'NHE_AUTH_HEADER_ONE_COMPLETE_NATIVE_SUCCESS_RESPONSE');
    $decoded = json_decode($privateResponse['raw'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && $decoded === $privateResponse['json']
        && !array_is_list($decoded) && ($decoded['status'] ?? null) === 200,
        'NHE_AUTH_HEADER_REAL_ORIGINAL_SUCCESS_JSON');

    $headers = $privateResponse['headers'] ?? null;
    nhep_expect(is_array($headers), 'NHE_AUTH_HEADER_ORIGINAL_CALLBACK_ENVELOPE');
    nhe_keys($headers, ['lines', 'blocks', 'bytes', 'complete', 'limit_exceeded'], 'NHE_AUTH_HEADER_EXACT_FIVE_CALLBACK_FIELDS');
    nhep_expect($headers['complete'] === true && $headers['limit_exceeded'] === false
        && is_int($headers['bytes']) && $headers['bytes'] > 0 && $headers['bytes'] <= 65536
        && is_array($headers['lines']) && array_is_list($headers['lines']) && count($headers['lines']) >= 3
        && is_array($headers['blocks']) && array_is_list($headers['blocks']) && count($headers['blocks']) === 1
        && $headers['blocks'][0] === $headers['lines'], 'NHE_AUTH_HEADER_SINGLE_COMPLETE_BLOCK_WITHOUT_TRUNCATION');
    $lines = $headers['lines']; $last = count($lines) - 1;
    nhep_expect(in_array($lines[0], ["HTTP/1.0 200 OK\r\n", "HTTP/1.1 200 OK\r\n"], true)
        && $lines[$last] === "\r\n", 'NHE_AUTH_HEADER_ORIGINAL_CLI_SUCCESS_STATUS_AND_FINAL_CRLF');
    $bytes = 0; $cookieIds = [];
    foreach ($lines as $index => $line) {
        nhep_expect(is_string($line) && strlen($line) >= 2 && str_ends_with($line, "\r\n"),
            'NHE_AUTH_HEADER_EACH_COMPLETE_ORIGINAL_CRLF_LINE');
        $bytes += strlen($line);
        nhep_expect($bytes <= 65536, 'NHE_AUTH_HEADER_ORIGINAL_TOTAL_BYTE_LIMIT');
        if ($index === 0 || $index === $last) continue;
        $body = substr($line, 0, -2); $colon = strpos($body, ':');
        nhep_expect(is_int($colon) && $colon > 0, 'NHE_AUTH_HEADER_NO_EXTRA_STATUS_EMPTY_OR_FOLDED_LINE');
        $name = substr($body, 0, $colon); $value = substr($body, $colon + 1);
        nhep_expect(preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) === 1
            && preg_match('/\A[\x09\x20-\x7e\x80-\xff]*\z/D', $value) === 1,
            'NHE_AUTH_HEADER_COMPLETE_VALID_FIELD_WITHOUT_EMBEDDED_CONTROL_OR_CRLF');
        nhep_expect(strcasecmp($name, 'Set-Cookie2') !== 0, 'NHE_AUTH_HEADER_NO_OTHER_COOKIE_MECHANISM');
        if (strcasecmp($name, 'Set-Cookie') === 0) {
            // php-8.2.33 head.c/head.h, then CLI send_headers appends CRLF.
            nhep_expect(preg_match('~\ASet-Cookie: PHPSESSID=([a-f0-9]{32}); path=/\r\n\z~D', $line, $cookie) === 1,
                'NHE_AUTH_HEADER_EXACT_NATIVE_SESSION_COOKIE_AND_ONLY_SOURCE_ATTRIBUTES');
            $cookieIds[] = $cookie[1];
        }
    }
    nhep_expect($bytes === $headers['bytes'] && count($cookieIds) === 1,
        'NHE_AUTH_HEADER_COMPLETE_BYTE_SUM_AND_UNIQUE_SOURCE_COOKIE');
    $id = $cookieIds[0];
    nhep_expect(!isset($seen[$id]), 'NHE_AUTH_HEADER_NEW_OBSERVED_ID_WITHOUT_COOKIE_JAR_REUSE');
    $ids = $beforeCookieIds; $ids[] = $id;
    // The full raw envelope and ID are PRIVATE observations, not seven-part output.
    return ['cookie_id' => $id, 'seen_cookie_ids' => $ids, 'headers' => $headers];
}

function nhecr_file_tree(string $root): ?array
{
    nhep_expect(in_array($root, ['/run/audit-app', '/run/audit-fixtures', '/current-runtime',
        '/workspace/crmeb/public', '/workspace/crmeb/runtime'], true), 'NHECR_FILE_FIXED_FIVE_CURRENT_OBSERVATION_ROOTS');
    clearstatcache(true, $root);
    if (!file_exists($root)) {
        nhep_expect(!is_link($root), 'NHE_FILE_ABSENT_ROOT_NOT_DANGLING_LINK'); return null;
    }
    nhep_expect(is_dir($root) && !is_link($root) && realpath($root) === $root, 'NHE_FILE_ORIGINAL_REAL_DIRECTORY_ROOT');
    $result = [];
    $walk = static function (string $path, string $relative) use (&$walk, &$result): void {
        clearstatcache(true, $path); $before = lstat($path);
        nhep_expect(is_array($before) && !is_link($path) && realpath($path) === $path, 'NHE_FILE_REAL_ORIGINAL_TREE_ENTRY');
        $stat = nhep_file_stat($before); $kind = $stat['mode'] & 0170000;
        nhep_expect(in_array($kind, [0040000, 0100000], true), 'NHE_FILE_ONLY_DIRECTORY_OR_REGULAR_ENTRY');
        $row = ['kind' => $kind === 0040000 ? 'directory' : 'file', 'stat' => $stat];
        if ($kind === 0100000) {
            nhep_expect($stat['nlink'] === 1 && $stat['size'] >= 0 && $stat['size'] <= 67108864,
                'NHE_FILE_COMPLETE_SINGLE_LINK_FILE_WITHIN_ORIGINAL_LIMIT');
            $raw = file_get_contents($path); clearstatcache(true, $path); $after = lstat($path);
            nhep_expect(is_string($raw) && strlen($raw) === $stat['size'] && is_array($after)
                && !is_link($path) && realpath($path) === $path && nhep_file_stat($after) === $stat,
                'NHE_FILE_COMPLETE_BYTES_AND_ORIGINAL_STAT_STABLE');
            $result[$relative] = $row + ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
            unset($raw); return;
        }
        $result[$relative] = $row; $names = scandir($path);
        nhep_expect(is_array($names), 'NHE_FILE_COMPLETE_ORIGINAL_DIRECTORY_NAMESPACE');
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            nhep_expect(is_string($name) && $name !== '' && !str_contains($name, "\0") && !str_contains($name, '/'),
                'NHE_FILE_REAL_DIRECTORY_LEAF_NAME');
            $walk($path . '/' . $name, $relative === '' ? $name : $relative . '/' . $name);
        }
        clearstatcache(true, $path); $after = lstat($path);
        nhep_expect(is_array($after) && !is_link($path) && realpath($path) === $path
            && nhep_file_stat($after) === $stat && scandir($path) === $names,
            'NHE_FILE_COMPLETE_DIRECTORY_STAT_AND_NAMESPACE_STABLE');
    };
    $walk($root, ''); ksort($result, SORT_STRING); return $result;
}

function nhecr_files_streams(#[SensitiveParameter] array $private, array $tree): void
{
    nhep_expect(is_array($private['native_streams'] ?? null), 'NHE_AUTH_FILES_REAL_PRIVATE_NATIVE_STREAMS');
    nhe_keys($private['native_streams'], ['http'], 'NHE_AUTH_FILES_BOTH_ORIGINAL_STREAM_OWNERS');
    foreach (['http'] as $kind) {
        $streams = $private['native_streams'][$kind];
        nhep_expect(is_array($streams), 'NHE_AUTH_FILES_COMPLETE_PRIVATE_CHILD_STREAMS');
        nhe_keys($streams, ['stdout', 'stderr'], 'NHE_AUTH_FILES_COMPLETE_PRIVATE_DUAL_STREAMS');
        foreach (['stdout', 'stderr'] as $name) {
            $stream = $streams[$name]; $leaf = 'current-' . $kind . '.' . $name;
            nhep_expect(is_array($stream), 'NHE_AUTH_FILES_PRIVATE_NATIVE_STREAM_ROW');
            nhe_keys($stream, ['path', 'stat', 'bytes', 'sha256', 'raw'], 'NHE_AUTH_FILES_COMPLETE_PRIVATE_STREAM_KEYS');
            nhep_expect($stream['path'] === '/current-runtime/' . $leaf && is_array($stream['stat'])
                && is_string($stream['raw']) && strlen($stream['raw']) <= 16777216
                && is_int($stream['bytes']) && $stream['bytes'] === strlen($stream['raw'])
                && $stream['sha256'] === hash('sha256', $stream['raw']), 'NHE_AUTH_FILES_FULL_PRIVATE_STREAM_RAW_BYTES');
            nhep_auth_files_stat($stream['stat']); $stat = $stream['stat'];
            nhep_expect($stat['mode'] === (0100000 | 0600) && $stat['nlink'] === 1
                && $stat['size'] === $stream['bytes'] && $stat['dev'] === $tree['']['stat']['dev']
                && $stat['uid'] === $tree['']['stat']['uid']
                && ($tree[$leaf] ?? null) === ['kind' => 'file', 'stat' => $stat,
                    'bytes' => $stream['bytes'], 'sha256' => $stream['sha256']],
                'NHE_AUTH_FILES_PRIVATE_FULL_STREAM_BOUND_TO_SAME_RUNTIME_TREE');
        }
    }
}

function nhecr_files_model(#[SensitiveParameter] array $before7, #[SensitiveParameter] array $actual7,
    #[SensitiveParameter] array $intent, #[SensitiveParameter] array $privateResponse,
    #[SensitiveParameter] array $beforePrivate, #[SensitiveParameter] array $afterPrivate): array
{
    nhep_expect(function_exists('nhecr_db_validate') && function_exists('nhecr_stream_expected'),
        'NHECR_FILES_COMPLETE_CURRENT_OBSERVER_AND_HTTP_STREAM_MODEL_REQUIRED');
    foreach ([$before7, $actual7] as $view) {
        nhe_keys($view, ['database', 'rows', 'redis', 'private', 'public', 'logs', 'native'],
            'NHECR_FILES_COMPLETE_CURRENT_SEVEN_PART_OBSERVATION');
        foreach ($view as $part) nhep_expect(is_array($part), 'NHECR_FILES_NO_BOOLEAN_OBSERVER_ACK');
        nhecr_db_validate(['database' => $view['database'], 'rows' => $view['rows']]);
        nhe_keys($view['native'], ['http'], 'NHECR_FILES_ONLY_REAL_CURRENT_HTTP_CHILD');
        $native = $view['native']['http'];
        nhep_expect(is_array($native) && is_int($native['pid'] ?? null) && $native['pid'] > 0
            && ($native['running'] ?? null) === true && ($native['signaled'] ?? null) === false
            && ($native['stopped'] ?? null) === false && ($native['exitcode'] ?? null) === -1,
            'NHECR_FILES_ACTUAL_OWN_HTTP_CHILD_STILL_RUNNING');
    }
    nhe_keys($intent, ['run_id', 'sequence', 'step', 'origin', 'method', 'path', 'actor', 'actor_id'],
        'NHE_AUTH_FILES_COMPLETE_ORIGINAL_INTENT');
    nhecr_intent($intent);
    $window = static function (mixed $value): array {
        nhep_expect(is_array($value) && array_is_list($value) && count($value) === 2,
            'NHE_AUTH_FILES_REAL_PRIVATE_WALL_CLOCK_PAIR');
        foreach ($value as $time) nhep_expect(is_int($time) && $time > 0 && $time <= 4294967295,
            'NHE_AUTH_FILES_REAL_PRIVATE_NATIVE_WALL_CLOCK');
        nhep_expect($value[0] <= $value[1], 'NHE_AUTH_FILES_MONOTONIC_PRIVATE_OBSERVATION_WINDOW');
        return $value;
    };
    $beforeWindow = $window($beforePrivate['actual_interval'] ?? null);
    $httpWindow = $window($privateResponse['actual_interval'] ?? null);
    $afterWindow = $window($afterPrivate['actual_interval'] ?? null);
    nhep_expect($beforeWindow[1] <= $httpWindow[0] && $httpWindow[1] <= $afterWindow[0],
        'NHE_AUTH_FILES_ORIGINAL_BEFORE_HTTP_AFTER_OBSERVATION_ORDER');
    $changedTime = static function (int $value) use ($httpWindow, $afterWindow): void {
        nhep_expect($value >= $httpWindow[0] && $value <= $afterWindow[1],
            'NHE_AUTH_FILES_CHANGED_NATIVE_TIME_WITHIN_REAL_REQUEST_AND_END_OBSERVATION');
    };
    foreach ([$before7, $actual7] as $view) {
        nhe_keys($view['private'], ['app', 'fixtures'], 'NHE_AUTH_FILES_ORIGINAL_APP_PRIVATE_TREE');
        nhe_keys($view['public'], ['current_public'], 'NHE_AUTH_FILES_COMPLETE_OLD_PUBLIC_TREE');
        nhe_keys($view['logs'], ['runtime', 'current_root_runtime'], 'NHE_AUTH_FILES_BOTH_ORIGINAL_RUNTIME_TREES');
        nhep_expect(is_array($view['logs']['runtime']), 'NHE_AUTH_FILES_ORIGINAL_NATIVE_RUNTIME_PRESENT');
        nhep_auth_files_tree($view['logs']['runtime']);
    }
    $before = $before7['logs']['runtime']; $actual = $actual7['logs']['runtime'];
    nhecr_files_streams($beforePrivate, $before); nhecr_files_streams($afterPrivate, $actual);
    $expected = ['private' => $before7['private'], 'public' => $before7['public'], 'logs' => $before7['logs']];
    nhep_expect($actual7['private'] === $expected['private'] && $actual7['public'] === $expected['public']
        && $actual7['logs']['current_root_runtime'] === $expected['logs']['current_root_runtime'],
        'NHE_AUTH_FILES_COMPLETE_PRIVATE_PUBLIC_AND_OLD_ROOT_RUNTIME_PRESERVED');
    $root = $before['']['stat'];
    nhep_expect($root['mode'] === (0040000 | 0700), 'NHE_AUTH_FILES_ORIGINAL_PRIVATE_RUNTIME_ROOT_MODE');
    $added = [];
    foreach (['session', 'log'] as $leaf) {
        if (array_key_exists($leaf, $before)) nhep_expect($before[$leaf]['kind'] === 'directory',
            'NHE_AUTH_FILES_EXISTING_SESSION_AND_LOG_MUST_BE_DIRECTORIES');
        else $added[] = $leaf;
        foreach (array_keys($before) as $path) nhep_expect(!str_starts_with($path, $leaf . '/'),
            'NHE_AUTH_FILES_EXISTING_SESSION_OR_LOG_CONTENT_BRANCH_NOT_IMPLEMENTED');
    }
    $wanted = array_merge(array_keys($before), $added); sort($wanted, SORT_STRING);
    nhep_expect(array_keys($actual) === $wanted, 'NHE_AUTH_FILES_COMPLETE_BEFORE_PLUS_ONLY_SOURCE_REQUIRED_EMPTY_DIRECTORIES');
    foreach (['session', 'log'] as $leaf) {
        nhep_expect($actual[$leaf]['kind'] === 'directory', 'NHE_AUTH_FILES_SOURCE_REQUIRED_EMPTY_DIRECTORY');
        if (array_key_exists($leaf, $before)) {
            nhep_expect($actual[$leaf] === $before[$leaf], 'NHE_AUTH_FILES_EXISTING_EMPTY_DIRECTORY_ALL_STAT_NINE_PRESERVED');
            continue;
        }
        $observed = $actual[$leaf]['stat'];
        nhep_expect($observed['dev'] === $root['dev'] && $observed['uid'] === $root['uid'] && $observed['gid'] === $root['gid']
            && $observed['mode'] === (0040000 | 0700) && $observed['nlink'] === 2,
            'NHE_AUTH_FILES_NEW_EMPTY_DIRECTORY_SOURCE_MODE_DEVICE_OWNER_AND_LINKS');
        $changedTime($observed['mtime']); $changedTime($observed['ctime']);
        $expected['logs']['runtime'][$leaf] = ['kind' => 'directory', 'stat' => [
            'dev' => $root['dev'], 'ino' => $observed['ino'], 'mode' => 0040000 | 0700, 'nlink' => 2,
            'uid' => $root['uid'], 'gid' => $root['gid'], 'size' => $observed['size'],
            'mtime' => $observed['mtime'], 'ctime' => $observed['ctime'],
        ]];
    }
    if ($added !== []) {
        $observed = $actual['']['stat'];
        foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $key)
            nhep_expect($observed[$key] === $root[$key], 'NHE_AUTH_FILES_SAME_ORIGINAL_PARENT_DIRECTORY_IDENTITY');
        nhep_expect($observed['nlink'] === $root['nlink'] + count($added) && $observed['size'] >= $root['size']
            && $observed['mtime'] >= $root['mtime'] && $observed['ctime'] >= $root['ctime'],
            'NHE_AUTH_FILES_PARENT_ONLY_SOURCE_REQUIRED_NEW_DIRECTORY_COUNT');
        $changedTime($observed['mtime']); $changedTime($observed['ctime']);
        $expected['logs']['runtime']['']['stat']['nlink'] = $root['nlink'] + count($added);
        foreach (['size', 'mtime', 'ctime'] as $key) $expected['logs']['runtime']['']['stat'][$key] = $observed[$key];
    }
    foreach (['http' => ['stdout']] as $kind => $names)
        foreach ($names as $name) nhep_expect($afterPrivate['native_streams'][$kind][$name]
            === $beforePrivate['native_streams'][$kind][$name], 'NHE_AUTH_FILES_ALL_NON_HTTP_STDERR_NATIVE_OUTPUT_PRESERVED');
    $streamExpected = nhecr_stream_expected($intent, $privateResponse, $beforePrivate, $afterPrivate);
    nhep_expect(is_array($streamExpected), 'NHE_AUTH_FILES_INDEPENDENT_HTTP_STREAM_MODEL_NOT_ACK');
    nhe_keys($streamExpected, ['raw', 'bytes', 'sha256'], 'NHE_AUTH_FILES_INDEPENDENT_COMPLETE_HTTP_STREAM_MODEL_KEYS');
    $oldStream = $beforePrivate['native_streams']['http']['stderr'];
    $newStream = $afterPrivate['native_streams']['http']['stderr'];
    nhep_expect(is_string($streamExpected['raw']) && strlen($streamExpected['raw']) <= 16777216
        && strlen($streamExpected['raw']) > strlen($oldStream['raw'])
        && str_starts_with($streamExpected['raw'], $oldStream['raw'])
        && is_int($streamExpected['bytes']) && $streamExpected['bytes'] === strlen($streamExpected['raw'])
        && $streamExpected['sha256'] === hash('sha256', $streamExpected['raw'])
        && $newStream['raw'] === $streamExpected['raw'], 'NHE_AUTH_FILES_ONLY_INDEPENDENT_PROVED_APPEND_TO_COMPLETE_BEFORE_STREAM');
    $oldStat = $oldStream['stat']; $newStat = $newStream['stat'];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
        nhep_expect($newStat[$key] === $oldStat[$key], 'NHE_AUTH_FILES_HTTP_STDERR_ORIGINAL_SIX_CREATED_FIELDS');
    nhep_expect($newStat['size'] === $streamExpected['bytes'] && $newStat['mtime'] >= $oldStat['mtime']
        && $newStat['ctime'] >= $oldStat['ctime'], 'NHE_AUTH_FILES_HTTP_STDERR_INDEPENDENT_LENGTH_AND_MONOTONIC_NATIVE_TIME');
    $changedTime($newStat['mtime']); $changedTime($newStat['ctime']);
    $expected['logs']['runtime']['current-http.stderr']['stat']['size'] = strlen($streamExpected['raw']);
    $expected['logs']['runtime']['current-http.stderr']['stat']['mtime'] = $newStat['mtime'];
    $expected['logs']['runtime']['current-http.stderr']['stat']['ctime'] = $newStat['ctime'];
    $expected['logs']['runtime']['current-http.stderr']['bytes'] = strlen($streamExpected['raw']);
    $expected['logs']['runtime']['current-http.stderr']['sha256'] = hash('sha256', $streamExpected['raw']);
    ksort($expected['logs']['runtime'], SORT_STRING);
    nhep_expect($expected === ['private' => $actual7['private'], 'public' => $actual7['public'], 'logs' => $actual7['logs']],
        'NHE_AUTH_FILES_COMPLETE_INDEPENDENT_EXPECTED_THREE_PARTS');
    unset($streamExpected, $oldStream, $newStream, $before, $actual, $beforePrivate, $afterPrivate);
    return $expected;
}

/** Exact current routes; identities are independently selected before this request. */
function nhecr_intent(array $intent): void
{
    nhecr_keys($intent, ['run_id', 'sequence', 'step', 'origin', 'method', 'path', 'actor', 'actor_id']);
    $steps = ['customer_login' => [1, 'POST', '~\A/api/login\z~D'],
        'current_products' => [2, 'GET', '~\A/api/products\?ids=[1-9][0-9]{0,9}&page=1&limit=20\z~D'],
        'current_product_detail' => [3, 'GET', '~\A/api/product/detail/[1-9][0-9]{0,9}\z~D'],
        'current_reply_list' => [4, 'GET', '~\A/api/reply/list/[1-9][0-9]{0,9}\?page=1&limit=20&type=0\z~D'],
        'current_reply_config' => [5, 'GET', '~\A/api/reply/config/[1-9][0-9]{0,9}\z~D'],
        'current_refunded_root_detail' => [6, 'GET', '~\A/api/order/detail/cp[1-9][0-9]{0,18}\z~D'],
        'current_completed_root_detail' => [7, 'GET', '~\A/api/order/detail/cp[1-9][0-9]{0,18}\z~D'],
        'current_refund_detail' => [8, 'GET', '~\A/api/order/refund/detail/[1-9][0-9]{0,18}\z~D'],
        'current_original_public_image' => [9, 'GET', '~\A/statics/system_images/spread_level_1\.png\z~D']];
    nhecr_expect(is_string($intent['step']) && isset($steps[$intent['step']]), 'NHECR_CURRENT_STEP_NOT_IMPLEMENTED');
    $spec = $steps[$intent['step']];
    nhecr_expect($intent['sequence'] === $spec[0] && $intent['method'] === $spec[1]
        && $intent['origin'] === 'http://127.0.0.1:8000' && is_string($intent['path']) && preg_match($spec[2], $intent['path']) === 1
        && $intent['actor'] === ($spec[0] === 9 ? 'public' : 'customer')
        && ($spec[0] === 9 ? $intent['actor_id'] === null : (is_int($intent['actor_id']) && $intent['actor_id'] > 0 && $intent['actor_id'] <= 2147483647))
        && is_string($intent['run_id']) && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $intent['run_id']) === 1,
        'NHECR_EXACT_REAL_CURRENT_INTENT');
}

/** Rebuild only native normal server lines from independent endpoint and bounded clock scalars. */
function nhecr_stream_expected(array $intent, array $response, array $beforePrivate, array $afterPrivate): array
{
    nhecr_intent($intent); $bw = $beforePrivate['actual_interval']; $hw = $response['actual_interval']; $aw = $afterPrivate['actual_interval'];
    foreach ([$bw, $hw, $aw] as $window) nhecr_expect(array_is_list($window) && count($window) === 2
        && is_int($window[0]) && is_int($window[1]) && $window[0] > 0 && $window[0] <= $window[1], 'NHECR_REAL_STREAM_CLOCKS');
    nhecr_expect($bw[1] <= $hw[0] && $hw[1] <= $aw[0] && $response['http'] === 200 && $response['transport_errno'] === 0
        && $response['primary_ip'] === '127.0.0.1' && $response['primary_port'] === 8000
        && $response['local_ip'] === '127.0.0.1' && is_int($response['local_port']) && $response['local_port'] > 0
        && $beforePrivate['http_time_reference'] === $afterPrivate['http_time_reference'], 'NHECR_REAL_OWN_TRANSPORT_AND_STREAM_TIME_ORDER');
    nhep_http_time_reference($beforePrivate['http_time_reference']);
    $old = $beforePrivate['native_streams']['http']['stderr']; $new = $afterPrivate['native_streams']['http']['stderr'];
    nhecr_expect(str_starts_with($new['raw'], $old['raw']), 'NHECR_COMPLETE_NATIVE_STDERR_PREFIX');
    $append = substr($new['raw'], strlen($old['raw'])); $endpoint = preg_quote('127.0.0.1:' . $response['local_port'], '~');
    $static = $intent['sequence'] === 9;
    $pattern = '~\A\[([^\r\n]{24})\] ' . $endpoint . ' Accepted\n';
    if ($static) $pattern .= '\[([^\r\n]{24})\] ' . $endpoint . ' \[200\]: GET /statics/system_images/spread_level_1[.]png\n';
    $pattern .= '\[([^\r\n]{24})\] ' . $endpoint . ' Closing\n\z~D';
    nhecr_expect(preg_match($pattern, $append, $match) === 1, 'NHECR_ONLY_CURRENT_NORMAL_ACCEPT_STATIC_IF_NEEDED_CLOSE_NATIVE_LINES');
    $times = [];
    foreach (array_slice($match, 1) as $stamp) $times[] = nhep_http_asctime($stamp, [$hw[0], $aw[1]]);
    $sorted = $times; sort($sorted, SORT_NUMERIC); nhecr_expect($times === $sorted, 'NHECR_LOG_TIMES_IN_ORIGINAL_ORDER');
    $stamp = static fn(int $t): string => gmdate('D M ', $t) . str_pad(gmdate('j', $t), 2, ' ', STR_PAD_LEFT) . gmdate(' H:i:s Y', $t);
    $raw = $old['raw'] . '[' . $stamp($times[0]) . '] 127.0.0.1:' . $response['local_port'] . " Accepted\n";
    if ($static) $raw .= '[' . $stamp($times[1]) . '] 127.0.0.1:' . $response['local_port'] . " [200]: GET /statics/system_images/spread_level_1.png\n";
    $raw .= '[' . $stamp($times[count($times)-1]) . '] 127.0.0.1:' . $response['local_port'] . " Closing\n";
    nhecr_expect($raw === $new['raw'], 'NHECR_REBUILT_COMPLETE_NORMAL_CURRENT_NATIVE_APPEND');
    return ['raw' => $raw, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
}

/** Current Source App, same original restricted principal; parent observer never loads it. */
function nhecr_build_app(array $state): object
{
    nhecr_expect(PHP_SAPI === 'cli-server' && isset($state['parent_process']) && spl_autoload_functions() === []
        && !function_exists('app'), 'NHECR_CURRENT_APP_ONLY_ORIGINAL_QUALIFIED_HTTP_CHILD');
    foreach (get_declared_classes() as $class) nhecr_expect(preg_match('/\A(?:app|think|crmeb|Composer)\\\\/i', $class) !== 1
        && !str_starts_with($class, 'ComposerAutoloaderInit'), 'NHECR_CURRENT_APP_NO_PRELOAD');
    $config = audit_config(); $database = $config['database'];
    nhecr_expect($config['run_id'] === $state['context']['run_id'] && $database['username'] === 'audit_app'
        && preg_match('/\A[a-f0-9]{48}\z/D', $database['password']) === 1, 'NHECR_ORIGINAL_PRIVATE_DML_PRINCIPAL');
    $db = audit_pdo($database); $redis = audit_redis();
    nhecr_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)
        && $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0, 'NHECR_CURRENT_INDEPENDENT_NATIVE_PREPARES');
    nhecr_expect($db->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_app@%'
        && audit_grants_are_restricted($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), 'NHECR_CURRENT_WRITER_DML_ONLY');
    $before = nhecr_db_capture($db, $state['context']['run_id']); $cacheBefore = nhep_redis_capture($redis, $state['context']['run_id']);
    $filesBefore = ['app' => nhecr_file_tree('/run/audit-app'), 'fixtures' => nhecr_file_tree('/run/audit-fixtures'),
        'runtime' => nhecr_file_tree('/current-runtime'), 'public' => nhecr_file_tree('/workspace/crmeb/public')];
    $private = '/run/audit-app/legacy.env'; clearstatcache(true, $private); $stat = lstat($private);
    nhecr_expect(is_array($stat) && !is_link($private) && realpath($private) === $private
        && ($stat['mode'] & 07777) === 0600 && $stat['nlink'] === 1 && $stat['uid'] === posix_geteuid()
        && !file_exists('/workspace/crmeb/.env') && !file_exists('/source/crmeb/.env'), 'NHECR_PRIVATE_ENV_ORIGINAL_IDENTITY_NO_HOST_ENV');
    $self = $state['initializer_self'];
    nhecr_expect(is_int($self) && $self > 0 && $self === nhe_business_self_id($state),
        'NHECR_REAL_QUALIFIED_ORIGINAL_INITIALIZER_SELF_BEFORE_CURRENT_APP');
    $owner = nhepcd_one($before, 'eb_marketplace_merchant', ['mer_id' => $self]);
    nhecr_expect($owner['row']['review_status'] === 1 && $owner['row']['accept_orders'] === 1
        && $owner['row']['closed_at'] === null && $owner['row']['security_locked'] === 0, 'NHECR_SAME_ORIGINAL_SELF_NATIVE_ROW');
    require_once '/workspace/crmeb/vendor/autoload.php';
    $app = new \think\App('/workspace/crmeb/'); $app->setRuntimePath('/current-runtime/'); $app->env->load($private);
    $app->env->set(['APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => $database['username'], 'DATABASE_PASSWORD' => $database['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'CACHE_CACHE_PREFIX' => 'c:', 'CACHE_CACHE_TAG_PREFIX' => 'CRMEB:',
        'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379', 'REDIS_SELECT' => '0',
        'REDIS_REDIS_PASSWORD' => $config['redis_password'], 'MARKETPLACE_ENABLED' => false,
        'MARKETPLACE_SELF_OPERATED_MER_ID' => (string)$self]);
    nhecr_expect($app->env->get('config_ext', '.php') === '.php' && $app->env->get('log.channel', 'file') === 'file'
        && $app->env->get('lang.default_lang', 'zh-cn') === 'zh-cn' && !file_exists('/current-runtime/route.php'),
        'NHECR_EXPLICIT_CURRENT_NORMAL_INITIALIZATION_BRANCH');
    unset($config, $database); $app->initialize();
    $connection = $app->db->connect();
    nhecr_expect($connection->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS principal_name,run_id FROM audit_sentinel', [], true)
        === [['database_name' => 'audit_auth', 'principal_name' => 'audit_app@%', 'run_id' => $state['context']['run_id']]],
        'NHECR_REAL_CURRENT_APP_WRITER_CONNECTION_AND_SENTINEL');
    $writer = $connection->getPdo();
    nhecr_expect($writer instanceof PDO && audit_grants_are_restricted($writer->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))
        && $app->getRootPath() === '/workspace/crmeb/' && $app->getRuntimePath() === '/current-runtime/' && app() === $app
        && $app->config->get('database.connections.mysql.username') === 'audit_app'
        && $app->config->get('marketplace.enabled') === false
        && $app->config->get('marketplace.self_operated_mer_id') === (string)$self, 'NHECR_CURRENT_SINGLE_SELF_APP_DML_PROFILE');
    $after = nhecr_db_capture($db, $state['context']['run_id']); $cacheAfter = nhep_redis_capture($redis, $state['context']['run_id']);
    nhecr_expect($after === $before && $cacheAfter['snapshot'] === nhep_redis_expected($cacheBefore['snapshot'], [], $cacheAfter['snapshot']['time'])
        && $filesBefore === ['app' => nhecr_file_tree('/run/audit-app'), 'fixtures' => nhecr_file_tree('/run/audit-fixtures'),
            'runtime' => nhecr_file_tree('/current-runtime'), 'public' => nhecr_file_tree('/workspace/crmeb/public')],
        'NHECR_CURRENT_APP_INITIALIZATION_INDEPENDENT_166_CACHE_FILES_ONLY_NATURAL_EXPIRY');
    clearstatcache(true, $private); nhecr_expect(nhecr_stat9(lstat($private)) === nhecr_stat9($stat), 'NHECR_CURRENT_PRIVATE_ENV_STAT9_UNCHANGED');
    foreach (['think\\App', 'app\\AppService', 'app\\Request'] as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        nhecr_expect(is_string($file) && str_starts_with($file, '/workspace/'), 'NHECR_CURRENT_CLASS_REAL_FROZEN_SOURCE');
    }
    nhecr_includes($state); audit_guard_assert(); return $app;
}

/** A fixed current HTTP router; static image uses the original built-in server path. */
function nhecr_router(): bool
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1'); umask(0077);
    $handled = false;
    $unavailable = static function (): void {
        if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store'); echo "{\"status\":\"FAIL\",\"code\":\"NHECR_HTTP_UNAVAILABLE\"}\n"; }
        else file_put_contents('php://stderr', "NHECR_HTTP_UNAVAILABLE\n");
    };
    register_shutdown_function(static function () use (&$handled, $unavailable): void { if (!$handled) $unavailable(); });
    try {
        nhecr_expect(PHP_SAPI === 'cli-server' && realpath($_SERVER['DOCUMENT_ROOT'] ?? '') === '/workspace/crmeb/public', 'NHECR_ACTUAL_CURRENT_SERVER_ENTRY');
        $bytes = getenv('NHE_CHILD_FREEZE_BYTES'); $sha = getenv('NHE_CHILD_FREEZE_SHA256');
        nhecr_expect(is_string($bytes) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $bytes) === 1 && (string)(int)$bytes === $bytes
            && is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/D', $sha) === 1, 'NHECR_ORIGINAL_FRESH_EXEC_FREEZE_ENV');
        $state = nhecr_qualify(['path' => '/run/audit/preparation-source-binding.json', 'bytes' => (int)$bytes, 'sha256' => $sha], true);
        $uri = $_SERVER['REQUEST_URI'] ?? null; $method = $_SERVER['REQUEST_METHOD'] ?? null;
        nhecr_expect(is_string($uri) && !str_contains($uri, '%') && !str_contains($uri, '..') && !str_contains($uri, '\\')
            && !str_contains($uri, '#') && preg_match('/[\x00-\x20\x7f]/', $uri) !== 1, 'NHECR_ONLY_ORIGINAL_FIXED_NORMAL_REQUEST_URI');
        // Public safe IDs constrain transport paths only; expected native rows are selected independently by the parent.
        $summary = $state['handoff']['seal']['http']; $ids = $state['handoff']['identities']; $pid = $summary['product_id'];
        $allowed = ['POST /api/login', 'GET /api/products?ids=' . $pid . '&page=1&limit=20',
            'GET /api/product/detail/' . $pid, 'GET /api/reply/list/' . $pid . '?page=1&limit=20&type=0',
            'GET /api/reply/config/' . $pid, 'GET /api/order/detail/' . $ids['refund_family']['order_no'],
            'GET /api/order/detail/' . $ids['review_family']['order_no'], 'GET /api/order/refund/detail/' . $ids['refund']['order_no'],
            'GET /statics/system_images/spread_level_1.png'];
        nhecr_expect(in_array($method . ' ' . $uri, $allowed, true), 'NHECR_CURRENT_ONLY_NINE_REAL_NORMAL_SOURCE_ROUTES');
        if ($method . ' ' . $uri === 'GET /statics/system_images/spread_level_1.png') {
            $file = '/workspace/crmeb/public' . $uri;
            nhecr_expect(!is_link($file) && realpath($file) === $file
                && $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SCRIPT_NAME'] === $file, 'NHECR_ORIGINAL_STATIC_DOCUMENT_TARGET');
            nhe_file($file, ['path' => $file, 'bytes' => 53659, 'sha256' => '24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d']);
            nhecr_includes($state); $handled = true; return false;
        }
        defined('DS') || define('DS', DIRECTORY_SEPARATOR); nhecr_expect(DS === DIRECTORY_SEPARATOR, 'NHECR_NATIVE_DS');
        $reference = nhep_http_time_reference(); $app = nhecr_build_app($state); $http = $app->http;
        $response = $http->run(); nhecr_includes($state); $response->send(); $http->end($response);
        nhep_http_time_reference($reference); nhecr_includes($state); $handled = true; return true;
    } catch (Throwable $ignored) { $handled = true; $unavailable(); return true; }
}

function nhecr_stream_capture(array &$children): array
{
    $result = ['http' => []];
    foreach (['stdout', 'stderr'] as $name) {
        $stream = $children['http']['streams'][$name]; $path = $stream['path']; clearstatcache(true, $path); $before = lstat($path);
        nhecr_expect(is_array($before) && !is_link($path) && realpath($path) === $path
            && ($before['mode'] & 07777) === 0600 && $before['nlink'] === 1 && $before['size'] <= 16777216,
            'NHECR_OWN_COMPLETE_PRIVATE_STREAM');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
            nhecr_expect($before[$key] === $stream['created'][$key], 'NHECR_ORIGINAL_STREAM_SIX_NATIVE_IDENTITIES');
        nhecr_expect(is_resource($stream['handle']), 'NHECR_ORIGINAL_OPEN_STREAM_HANDLE');
        $opened = fstat($stream['handle']); $raw = file_get_contents($path); clearstatcache(true, $path); $after = lstat($path);
        nhecr_expect(is_string($raw) && strlen($raw) === $before['size'] && nhecr_stat9($before) === nhecr_stat9($after)
            && nhecr_stat9($opened) === nhecr_stat9($after), 'NHECR_FULL_STREAM_HANDLE_PATH_STAT9');
        $result['http'][$name] = ['path' => $path, 'stat' => nhecr_stat9($after), 'bytes' => strlen($raw),
            'sha256' => hash('sha256', $raw), 'raw' => $raw];
    }
    return $result;
}

/** Exact seven current parts; full rows/wires/stream bodies remain only in private RAM. */
function nhecr_capture(array $state, PDO $db, Redis $redis, array &$children): array
{
    $start = time(); nhecr_fresh($state);
    nhecr_expect(($state['private_fixture_context']['scope'] ?? null) === 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT'
        && $state['private_fixture_context']['run_id'] === $state['context']['run_id']
        && $state['private_fixture_context']['self_operated_mer_id'] === $state['initializer_self'],
        'NHECR_CAPTURE_SAME_VALIDATED_PRIVATE_RAM_CONTEXT_ONLY');
    $first = nhep_child_observation($children, 'http');
    $database = nhecr_db_capture($db, $state['context']['run_id']); $cache = nhep_redis_capture($redis, $state['context']['run_id']);
    $snapshot = ['database' => $database['database'], 'rows' => $database['rows'], 'redis' => $cache['snapshot'],
        'private' => ['app' => nhecr_file_tree('/run/audit-app'), 'fixtures' => nhecr_file_tree('/run/audit-fixtures')],
        'public' => ['current_public' => nhecr_file_tree('/workspace/crmeb/public')],
        'logs' => ['runtime' => nhecr_file_tree('/current-runtime'), 'current_root_runtime' => nhecr_file_tree('/workspace/crmeb/runtime')],
        'native' => []];
    $streams = nhecr_stream_capture($children); $last = nhep_child_observation($children, 'http');
    nhecr_expect($first['native'] === $last['native'] && $first['group'] === $last['group'], 'NHECR_SAME_REAL_HTTP_HANDLE_DURING_FULL_CAPTURE');
    $snapshot['native']['http'] = $last['native']; nhecr_db_validate(['database' => $snapshot['database'], 'rows' => $snapshot['rows']]);
    nhecr_files_streams(['native_streams' => $streams], $snapshot['logs']['runtime']); nhecr_includes($state);
    $reference = nhep_http_time_reference($children['http']['http_time_reference']);
    $private = ['redis_native' => $cache['native'], 'fixture' => $state['private_fixture_context'],
        'self_operated_mer_id' => $state['private_fixture_context']['self_operated_mer_id'], 'actual_interval' => [$start, time()],
        'native_before' => ['http' => $first], 'native_after' => ['http' => $last], 'native_streams' => $streams,
        'http_time_reference' => $reference];
    return ['snapshot' => $snapshot, 'private' => $private];
}

/** The original Closing line is awaited before the sole AFTER snapshot, including failed transport. */
function nhecr_wait_closing(array &$children, array $response, array $beforePrivate): bool
{
    if (!is_int($response['local_port']) || $response['local_port'] <= 0) return false;
    $prefix = $beforePrivate['native_streams']['http']['stderr']['raw']; $until = hrtime(true) + 3000000000;
    do {
        $status = nhep_child_native($children['http']); if (!$status['running']) return false;
        $raw = nhecr_stream_capture($children)['http']['stderr']['raw'];
        if (!str_starts_with($raw, $prefix)) return false;
        if (str_ends_with($raw, '] 127.0.0.1:' . $response['local_port'] . " Closing\n")) return true;
        usleep(5000);
    } while (hrtime(true) < $until);
    return false;
}

/** Single direct loopback transport; no retry, redirect, cookie jar or foreign destination. */
function nhecr_transport(array $intent, #[SensitiveParameter] array $request, array &$transportExtra): array
{
    nhecr_intent($intent); nhecr_keys($request, ['token', 'body', 'actor_identity']);
    nhecr_expect(($intent['method'] === 'GET') === ($request['body'] === null), 'NHECR_ACTUAL_METHOD_BODY');
    $headers = ['Accept: application/json', 'Cb-lang: zh-CN', 'Form-type: h5', 'Connection: close'];
    if ($request['token'] !== null) {
        nhecr_expect(is_string($request['token']) && preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/D', $request['token']) === 1,
            'NHECR_ACTUAL_PRIVATE_CUSTOMER_TOKEN'); $headers[] = 'Authori-zation: Bearer ' . $request['token'];
    }
    $wire = $request['body'] === null ? null : json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($wire !== null) $headers[] = 'Content-Type: application/json';
    $lines = []; $blocks = []; $block = []; $bytes = 0; $limited = false;
    $callback = static function ($handle, string $line) use (&$lines, &$blocks, &$block, &$bytes, &$limited): int {
        $bytes += strlen($line); if ($bytes > 65536) { $limited = true; return 0; }
        $lines[] = $line; $block[] = $line;
        if ($line === "\r\n" || $line === "\n") { $blocks[] = $block; $block = []; }
        return strlen($line);
    };
    $curl = curl_init($intent['origin'] . $intent['path']); nhecr_expect($curl !== false, 'NHECR_ORIGINAL_CURL_HANDLE');
    $raw = null; $failure = null; $start = time();
    try {
        nhecr_expect(curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => $callback, CURLOPT_CUSTOMREQUEST => $intent['method'], CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 30, CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]), 'NHECR_FIXED_NATIVE_CURL_OPTIONS');
        if ($wire !== null) nhecr_expect(curl_setopt($curl, CURLOPT_POSTFIELDS, $wire), 'NHECR_REAL_REQUEST_BODY_BYTES');
        $raw = curl_exec($curl); $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $errno = curl_errno($curl);
        $ip = curl_getinfo($curl, CURLINFO_PRIMARY_IP); $port = (int)curl_getinfo($curl, CURLINFO_PRIMARY_PORT);
        $localIp = curl_getinfo($curl, CURLINFO_LOCAL_IP); $localPort = (int)curl_getinfo($curl, CURLINFO_LOCAL_PORT);
        $transportExtra = ['redirect_count' => (int)curl_getinfo($curl, CURLINFO_REDIRECT_COUNT),
            'effective_url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL)];
    } catch (Throwable $error) { $failure = get_class($error); }
    finally { curl_close($curl); }
    $end = time(); if ($block !== []) $blocks[] = $block;
    $response = ['http' => $http ?? 0, 'raw' => is_string($raw) ? $raw : null, 'json' => null,
        'actual_interval' => [$start, $end], 'transport_errno' => $errno ?? -1, 'primary_ip' => $ip ?? null,
        'primary_port' => $port ?? 0, 'transport_failure_class' => $failure,
        'headers' => ['lines' => $lines, 'blocks' => $blocks, 'bytes' => $bytes,
            'complete' => !$limited && ($errno ?? -1) === 0 && is_string($raw), 'limit_exceeded' => $limited],
        'local_ip' => $localIp ?? null, 'local_port' => $localPort ?? null];
    if ($intent['sequence'] !== 9 && is_string($raw) && strlen($raw) <= 6291456) $response['json'] = json_decode($raw, true);
    unset($wire, $headers); return $response;
}

function nhecr_http_closed(array $state, array &$children): array
{
    nhecr_fresh($state); nhecr_expect(array_keys($children) === ['http'], 'NHECR_ONLY_ORIGINAL_HTTP_CHILD');
    $receipt = nhep_child_close($children, 'http');
    nhecr_expect($receipt['stopped'] === true && $receipt['remaining_members'] === [] && $receipt['listener_open'] === false
        && $receipt['term_attempted'] === true && $receipt['stop_signal'] === SIGTERM && is_int($receipt['proc_close_exit'])
        && $receipt['first_terminal']['running'] === false && $receipt['first_terminal']['signaled'] === true
        && $receipt['first_terminal']['termsig'] === SIGTERM && $children['http']['process'] === null,
        'NHECR_ORIGINAL_HTTP_ONE_NORMAL_TERM_NATIVE_GROUP_EMPTY_NO_LISTENER');
    $files = glob('/proc/[0-9]*/stat'); nhecr_expect(is_array($files) && $files !== [], 'NHECR_FINAL_ACTUAL_PID_NAMESPACE'); $seen = false;
    foreach ($files as $file) { $row = audit_app_proc_stat((int)basename(dirname($file))); if ($row === null) continue;
        nhecr_expect($row['pid'] === getmypid(), 'NHECR_FINAL_NO_OTHER_PROCESS'); $seen = true; }
    nhecr_expect($seen && !nhep_child_listener('http')['open'], 'NHECR_FINAL_ONLY_ORIGINAL_PARENT'); return $receipt;
}

function nhecr_order_context(#[SensitiveParameter] array $beforeDB2, array $intent,
    #[SensitiveParameter] array $privateRequest): array
{
    foreach (['nhep_expect', 'nhe_keys', 'nhecr_db_validate', 'nhepcd_fixture', 'nhepcd_one',
        'nhepcd_id', 'nhepcv_native_row', 'nhepcv_config', 'm4lh_actor_native',
        'm4lh_actor_expected_row', 'bccomp', 'json_decode', 'json_encode'] as $dependency)
        if (!function_exists($dependency)) throw new RuntimeException('NHE_ORDER_DETAIL_CONTEXT_PURE_DEPENDENCY_REQUIRED');
    nhecr_db_validate($beforeDB2);
    nhe_keys($intent, ['run_id', 'sequence', 'step', 'origin', 'method', 'path', 'actor', 'actor_id'],
        'NHE_ORDER_DETAIL_ORIGINAL_COMPLETE_INTENT');
    nhe_keys($privateRequest, ['token', 'body', 'actor_identity'], 'NHE_ORDER_DETAIL_ORIGINAL_PRIVATE_GET_REQUEST');
    nhep_expect(is_array($privateRequest['actor_identity']), 'NHE_ORDER_DETAIL_PRIVATE_NATIVE_ACTOR_REQUIRED');
    nhe_keys($privateRequest['actor_identity'], ['id', 'account'], 'NHE_ORDER_DETAIL_ORIGINAL_ACTOR_IDENTITY');
    $steps = ['current_refunded_root_detail' => [6, 'refund_family', 1],
        'current_completed_root_detail' => [7, 'review_family', 1]];
    nhep_expect(is_string($intent['step']) && isset($steps[$intent['step']]), 'NHE_ORDER_DETAIL_STEP_NOT_IMPLEMENTED');
    [$sequence, $family, $paid] = $steps[$intent['step']];
    $refunded = $intent['step'] === 'current_refunded_root_detail';
    $shipped = $intent['step'] === 'owned_shipped_read';
    $received = $intent['step'] === 'owned_received_read';
    $completed = $intent['step'] === 'current_completed_root_detail';
    nhep_expect($intent['sequence'] === $sequence && $intent['method'] === 'GET' && $intent['actor'] === 'customer'
        && $intent['origin'] === 'http://127.0.0.1:8000' && is_string($intent['run_id'])
        && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $intent['run_id']) === 1
        && is_string($intent['path'])
        && preg_match('~\A/api/order/detail/(cp([1-9][0-9]{0,18}))\z~D', $intent['path'], $path) === 1
        && bccomp($path[2], '9223372036854775807', 0) <= 0
        && $privateRequest['body'] === null && is_string($privateRequest['token'])
        && preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/D', $privateRequest['token']) === 1,
        'NHE_ORDER_DETAIL_FIXED_REAL_GET_SEQUENCE_ORIGIN_CP_AND_NULL_BODY');
    // This original fixture selector accepts the real GET and never reads or fabricates a POST body.
    $fixture = nhepcd_fixture($beforeDB2, $intent, $privateRequest);
    $uid = $fixture['uid']; $number = $path[1];
    if ($refunded || $shipped || $received || $completed) nhep_expect($fixture['user']['row']['now_money'] === '79.85'
        && $fixture['user']['row']['pay_count'] === 2,
        'NHE_ORDER_DETAIL_REFUNDED_BEFORE_ORIGINAL_OWNER_BALANCE_AND_TWO_PAYMENTS');
    $order = nhepcd_one($beforeDB2, 'eb_store_order', ['mark' => $intent['run_id'] . '-' . $family]);
    $row = nhepcv_native_row($beforeDB2, 'eb_store_order', $order['index']);
    $oid = nhepcd_id($row['id'], 2147483647);
    if ($shipped || $received || $completed) {
        // Original legacy-actors fixture, independently selected before the root's courier fields.
        $expressTable = $beforeDB2['database']['tables']['eb_express'];
        $expressMetadata = $expressTable['metadata'];
        nhep_expect(array_keys($expressMetadata['columns']) === ['id', 'code', 'name', 'partner_id', 'partner_key',
            'net', 'check_man', 'partner_name', 'is_code', 'courier_name', 'customer_name', 'code_name',
            'account', 'key', 'net_name', 'sort', 'is_show', 'status'],
            'NHE_ORDER_DETAIL_SHIPPED_ORIGINAL_COMPLETE_EXPRESS_COLUMNS');
        $codeColumn = $expressMetadata['columns']['code'];
        nhep_expect(strtolower($codeColumn['COLUMN_TYPE']) === 'varchar(50)' && $codeColumn['IS_NULLABLE'] === 'NO'
            && $codeColumn['CHARACTER_SET_NAME'] === 'utf8mb4'
            && in_array($codeColumn['COLLATION_NAME'], ['utf8mb4_0900_ai_ci', 'utf8mb4_general_ci',
                'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_bin'], true),
            'NHE_ORDER_DETAIL_SHIPPED_ORIGINAL_EXPRESS_SQL_SELECTOR_METADATA');
        foreach ($beforeDB2['rows']['eb_express'] as $index => $unused) {
            $candidate = nhepcv_native_row($beforeDB2, 'eb_express', $index);
            nhep_expect(is_string($candidate['code']) && preg_match('/\A[\x20-\x7e]*\z/D', $candidate['code']) === 1
                && (strtolower(rtrim($candidate['code'], ' ')) !== 'shunfeng' || $candidate['code'] === 'shunfeng'),
                'NHE_ORDER_DETAIL_SHIPPED_NO_SQL_EQUIVALENT_EXPRESS_CODE_ALIAS');
        }
        $express = nhepcd_one($beforeDB2, 'eb_express', ['code' => 'shunfeng']);
        $expressRow = nhepcv_native_row($beforeDB2, 'eb_express', $express['index']);
        $expressId = nhepcd_id($expressRow['id']);
        nhep_expect($expressRow === m4lh_actor_expected_row($expressMetadata, ['id' => $expressId,
            'code' => 'shunfeng', 'name' => '顺丰速运', 'partner_id' => 1, 'is_show' => 1, 'status' => 0]),
            'NHE_ORDER_DETAIL_SHIPPED_COMPLETE_ORIGINAL_EXPRESS_FIXTURE_AND_DEFAULTS');
    }
    nhep_expect($row['order_id'] === $number && $row['uid'] === $uid && $row['pay_uid'] === $uid
        && $row['paid'] === $paid && $row['pay_type'] === 'yue' && $row['channel_type'] === 'h5'
        && is_string($row['unique']) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $row['unique']) === 1
        && bccomp($row['unique'], '9223372036854775807', 0) <= 0,
        'NHE_ORDER_DETAIL_BEFORE_SAME_FAMILY_NATIVE_ROOT_OWNER_PAY_OWNER_AND_KEYS');
    foreach (['marketplace_trade_version' => 0, 'merchant_name_snapshot' => null, 'merchant_after_sale_contact_snapshot' => null, 'pid' => 0, 'status' => ($completed ? 3 : ($refunded ? -2 : ($received ? 2 : ($shipped ? 1 : 0)))), 'refund_status' => ($refunded ? 2 : 0),
        'refund_type' => ($refunded ? 6 : 0),
        'is_del' => 0, 'is_system_del' => 0, 'is_cancel' => 0, 'is_stock_up' => 0,
        'shipping_type' => 1, 'total_num' => 1, 'store_id' => 0, 'delivery_uid' => 0, 'clerk_id' => 0,
        'is_channel' => ($paid === 1 ? 2 : 0), 'virtual_type' => 0, 'is_gift' => 0, 'gift_uid' => 0,
        'combination_id' => 0, 'pink_id' => 0, 'seckill_id' => 0, 'bargain_id' => 0, 'advance_id' => 0,
        'coupon_id' => 0, 'verify_code' => '', 'delivery_type' => (($shipped || $received || $completed) ? 'express' : ''),
        'delivery_id' => (($shipped || $received || $completed) ? 'NHE' . $intent['run_id'] : ''),
        'delivery_name' => (($shipped || $received || $completed) ? '顺丰速运' : ''), 'delivery_code' => (($shipped || $received || $completed) ? 'shunfeng' : ''),
        'gift_mark' => '', 'custom_form' => '[]',
        'total_price' => '20.15', 'pay_price' => '20.15', 'cost' => '7.45',
        'freight_price' => '0.00', 'total_postage' => '0.00', 'pay_postage' => '0.00',
        'deduction_price' => '0.00', 'coupon_price' => '0.00', 'gift_price' => '0.00',
        'refund_price' => ($refunded ? '20.15' : '0.00'), 'use_integral' => '0.00', 'gain_integral' => '0.00',
        'back_integral' => '0.00'] as $column => $value)
        nhep_expect(array_key_exists($column, $row) && $row[$column] === $value,
            'NHE_ORDER_DETAIL_ONLY_ORIGINAL_NORMAL_UNSHIPPED_UNREFUNDED_ROOT');
    if ($refunded || $shipped || $received || $completed) nhep_expect(count($order['metadata']['columns']) === 90
        && $row['refund_reason_time'] === 0 && $row['refund_reason_wap_img'] === '',
        'NHE_ORDER_DETAIL_REFUNDED_FULL_ORIGINAL_ROOT_COLUMNS_AND_PRESERVED_REASON_FIELDS');
    nhep_expect(is_int($row['add_time']) && $row['add_time'] > 0
        && ($paid === 0 ? $row['pay_time'] === 0
            : (is_int($row['pay_time']) && $row['pay_time'] >= $row['add_time'])),
        'NHE_ORDER_DETAIL_ORIGINAL_BEFORE_CREATE_AND_PAY_TIMES');

    // MySQL's actual DAO uses BOTH ORs. Prove canonical ASCII lookup values rather than assume PHP === is SQL collation.
    $visible = []; $uniqueMatches = [];
    $orderColumns = $order['metadata']['columns'];
    foreach ($beforeDB2['rows']['eb_store_order'] as $index => $candidate) {
        foreach (['order_id', 'unique', 'uid', 'gift_uid', 'is_del', 'pid'] as $column)
            nhep_expect(m4lh_actor_native($orderColumns[$column], $candidate[$column]) === $candidate[$column],
                'NHE_ORDER_DETAIL_ORIGINAL_NATIVE_DAO_SELECTOR_FIELDS');
        foreach (['order_id', 'unique'] as $column)
            nhep_expect(is_string($candidate[$column]) && preg_match('/\A[A-Za-z0-9]*\z/D', $candidate[$column]) === 1,
                'NHE_ORDER_DETAIL_NONCANONICAL_LOOKUP_COLLATION_BRANCH_NOT_IMPLEMENTED');
        if (strtolower($candidate['unique']) === $row['unique']) $uniqueMatches[] = $index;
        if ($candidate['is_del'] === 0 && ($candidate['uid'] === $uid || $candidate['gift_uid'] === $uid)
            && (strtolower($candidate['order_id']) === $number || strtolower($candidate['unique']) === $number))
            $visible[] = $index;
        nhep_expect($candidate['pid'] !== $oid, 'NHE_ORDER_DETAIL_SPLIT_RELATION_BRANCH_NOT_IMPLEMENTED');
    }
    nhep_expect($visible === [$order['index']] && $uniqueMatches === [$order['index']]
        && nhepcd_one($beforeDB2, 'eb_store_order', ['id' => $oid]) === $order,
        'NHE_ORDER_DETAIL_ACTUAL_DAO_OR_LOOKUP_UNIQUE_AND_SAME_INDEPENDENT_ROOT');
    foreach (['store_self_mention', 'image_thumb_status', 'order_shipping_open',
        'invoice_func_status', 'special_invoice_status'] as $name)
        nhepcv_config($beforeDB2, $intent, $name, 0);
    // No invoice relation, gift/verify QR, delivery-person or MiniOrder side effect is admitted.
    foreach ($beforeDB2['rows']['eb_store_order_invoice'] as $invoice)
        nhep_expect(m4lh_actor_native($beforeDB2['database']['tables']['eb_store_order_invoice']['metadata']['columns']['order_id'],
            $invoice['order_id']) !== $oid, 'NHE_ORDER_DETAIL_INVOICE_RELATION_BRANCH_NOT_IMPLEMENTED');

    $item = nhepcd_one($beforeDB2, 'eb_store_order_cart_info', ['oid' => $oid]);
    $saved = nhepcv_native_row($beforeDB2, 'eb_store_order_cart_info', $item['index']);
    $itemId = nhepcd_id($saved['id'], 2147483647); $cartId = nhepcd_id($saved['cart_id']);
    if ($refunded || $shipped || $received || $completed) nhep_expect(array_keys($item['metadata']['columns']) === ['id', 'oid', 'uid', 'cart_id',
        'product_id', 'old_cart_id', 'cart_num', 'refund_num', 'surplus_num', 'split_status', 'cart_info', 'unique', 'mer_id'],
        'NHE_ORDER_DETAIL_REFUNDED_ORIGINAL_COMPLETE_TWELVE_ITEM_COLUMNS');
    nhep_expect($saved === m4lh_actor_expected_row($item['metadata'], ['id' => $itemId, 'oid' => $oid,
        'uid' => $uid, 'cart_id' => (string)$cartId, 'product_id' => $fixture['product_id'],
        'cart_num' => 1, 'surplus_num' => 1, 'cart_info' => $saved['cart_info'], 'unique' => md5($cartId . '' . $oid)]
        + ($refunded ? ['refund_num' => 1] : []))
        && is_string($saved['cart_info']) && $saved['cart_info'] !== ''
        && $row['cart_id'] === json_encode([$cartId]), 'NHE_ORDER_DETAIL_ONE_ORIGINAL_FULL_UNSPLIT_SAVED_ITEM_AND_CART_ID');
    $itemUniqueMatches = [];
    foreach ($beforeDB2['rows']['eb_store_order_cart_info'] as $index => $candidate) {
        nhep_expect(is_string($candidate['unique']) && preg_match('/\A[A-Za-z0-9]*\z/D', $candidate['unique']) === 1,
            'NHE_ORDER_DETAIL_NONCANONICAL_ITEM_UNIQUE_COLLATION_NOT_IMPLEMENTED');
        if (strtolower($candidate['unique']) === $saved['unique']) $itemUniqueMatches[] = $index;
    }
    nhep_expect($itemUniqueMatches === [$item['index']], 'NHE_ORDER_DETAIL_GLOBAL_SAVED_ITEM_UNIQUE_IS_UNAMBIGUOUS');
    foreach ($beforeDB2['rows']['eb_store_cart'] as $candidate)
        nhep_expect(m4lh_actor_native($beforeDB2['database']['tables']['eb_store_cart']['metadata']['columns']['id'],
            $candidate['id']) !== $cartId, 'NHE_ORDER_DETAIL_ORIGINAL_PERSISTENT_CART_ALREADY_CONSUMED');

    $cart = json_decode($saved['cart_info'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && is_array($cart) && !array_is_list($cart)
        && json_encode($cart) === $saved['cart_info'], 'NHE_ORDER_DETAIL_ORIGINAL_COMPLETE_DEFAULT_JSON_SAVED_CART');
    $cartMetadata = $beforeDB2['database']['tables']['eb_store_cart']['metadata'];
    $cartColumns = array_keys($cartMetadata['columns']); $baseCart = [];
    nhep_expect(count($cartColumns) === 15, 'NHE_ORDER_DETAIL_ORIGINAL_FIFTEEN_NATIVE_CART_COLUMNS');
    foreach ($cartColumns as $column) {
        nhep_expect(array_key_exists($column, $cart), 'NHE_ORDER_DETAIL_COMPLETE_ORIGINAL_SAVED_CART_BASE');
        $baseCart[$column] = m4lh_actor_native($cartMetadata['columns'][$column], $cart[$column]);
        nhep_expect($baseCart[$column] === $cart[$column], 'NHE_ORDER_DETAIL_NATIVE_SAVED_CART_BASE_TYPE');
    }
    nhep_expect(is_int($baseCart['add_time']) && $baseCart['add_time'] > 0 && $baseCart['add_time'] <= $row['add_time']
        && $baseCart === m4lh_actor_expected_row($cartMetadata, ['id' => $cartId, 'uid' => $uid,
            'type' => '0', 'product_id' => $fixture['product_id'], 'product_attr_unique' => $fixture['sku_unique'],
            'cart_num' => 1, 'add_time' => $baseCart['add_time']]), 'NHE_ORDER_DETAIL_ORIGINAL_OWNED_CART_AND_SKU_BASE');
    nhep_expect(is_array($cart['productInfo'] ?? null) && is_array($cart['attrInfo'] ?? null)
        && is_array($cart['productInfo']['attrInfo'] ?? null)
        && $cart['attrInfo'] === $cart['productInfo']['attrInfo'], 'NHE_ORDER_DETAIL_BOTH_ORIGINAL_SAVED_SKU_RELATIONS');
    $productSelection = nhepcd_one($beforeDB2, 'eb_store_product', ['id' => $fixture['product_id']]);
    $skuSelection = nhepcd_one($beforeDB2, 'eb_store_product_attr_value', ['unique' => $fixture['sku_unique']]);
    $product = nhepcv_native_row($beforeDB2, 'eb_store_product', $productSelection['index']);
    $sku = nhepcv_native_row($beforeDB2, 'eb_store_product_attr_value', $skuSelection['index']);
    $historicalProduct = $cart['productInfo']; $historicalSku = $cart['productInfo']['attrInfo'];
    // Inventory is persisted history. Validate it natively and retain it; never replace it with today's stock/sales.
    foreach ([['eb_store_product', $historicalProduct], ['eb_store_product_attr_value', $historicalSku]] as [$table, $history]) {
        foreach (['stock', 'sales'] as $column) {
            nhep_expect(array_key_exists($column, $history)
                && m4lh_actor_native($beforeDB2['database']['tables'][$table]['metadata']['columns'][$column], $history[$column]) === $history[$column]
                && is_int($history[$column]) && $history[$column] >= ($column === 'stock' ? 1 : 0),
                'NHE_ORDER_DETAIL_ORIGINAL_NATIVE_HISTORICAL_INVENTORY');
        }
    }
    $sku['stock'] = $historicalSku['stock']; $sku['sales'] = $historicalSku['sales'];
    nhep_expect($sku === $historicalSku && $sku['id'] === $fixture['sku_id']
        && $sku['product_id'] === $fixture['product_id'] && $sku['unique'] === $fixture['sku_unique']
        && $sku['suk'] === '普通' && $sku['type'] === 0 && $sku['price'] === '20.15' && $sku['cost'] === '7.45',
        'NHE_ORDER_DETAIL_ORIGINAL_COMPLETE_SAVED_SKU_WITH_HISTORICAL_INVENTORY');
    $slides = json_decode($product['slider_image'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && $slides === ['/statics/system_images/spread_level_1.png'],
        'NHE_ORDER_DETAIL_ORIGINAL_PRODUCT_SLIDER_GETTER');
    $product['stock'] = $historicalProduct['stock']; $product['sales'] = $historicalProduct['sales'];
    $product['slider_image'] = $slides; $product['express_delivery'] = true;
    $product['store_mention'] = false; $product['attrInfo'] = $sku;
    nhep_expect($product === $historicalProduct, 'NHE_ORDER_DETAIL_COMPLETE_ORIGINAL_SAVED_PRODUCT_WITH_HISTORICAL_INVENTORY');
    $expectedCart = $baseCart;
    $expectedCart['productInfo'] = $product; $expectedCart['attrInfo'] = $sku;
    $expectedCart['min_qty'] = 1; $expectedCart['attrStatus'] = true; $expectedCart['vip_truePrice'] = 0;
    $expectedCart['costPrice'] = '7.45'; $expectedCart['trueStock'] = $historicalSku['stock'];
    $expectedCart['truePrice'] = 20.15; $expectedCart['sum_price'] = '20.15';
    $expectedCart['price_type'] = 'level'; $expectedCart['is_valid'] = 1; $expectedCart['postage_price'] = 0;
    $expectedCart['use_integral'] = 0; $expectedCart['integral_price'] = 0; $expectedCart['coupon_price'] = 0;
    $expectedCart['sum_true_price'] = '20.15'; $expectedCart['one_brokerage'] = '0'; $expectedCart['two_brokerage'] = '0';
    $expectedCart['staff_brokerage'] = '0.00'; $expectedCart['agent_brokerage'] = '0.00'; $expectedCart['division_brokerage'] = '0.00';
    nhep_expect($expectedCart === $cart, 'NHE_ORDER_DETAIL_COMPLETE_ORIGINAL_SAVED_CART_AND_FIXED_NORMAL_PRICE_FIELDS');
    if ($completed) {
        // tidyOrder counts the entire reply table by item.unique alone, with no other SQL predicate.
        $replyMetadata = $beforeDB2['database']['tables']['eb_store_product_reply']['metadata'];
        nhep_expect(array_keys($replyMetadata['columns']) === ['id', 'uid', 'oid', 'unique', 'product_id',
            'reply_type', 'product_score', 'service_score', 'comment', 'pics', 'add_time',
            'merchant_reply_content', 'merchant_reply_time', 'is_del', 'is_reply', 'nickname', 'avatar', 'suk', 'status', 'mer_id'],
            'NHE_ORDER_DETAIL_COMPLETED_ORIGINAL_NINETEEN_REPLY_COLUMNS_WITHOUT_CART_ID');
        $uniqueColumn = $replyMetadata['columns']['unique'];
        nhep_expect(strtolower($uniqueColumn['COLUMN_TYPE']) === 'char(32)' && $uniqueColumn['IS_NULLABLE'] === 'NO'
            && $uniqueColumn['CHARACTER_SET_NAME'] === 'utf8mb4'
            && in_array($uniqueColumn['COLLATION_NAME'], ['utf8mb4_0900_ai_ci', 'utf8mb4_general_ci',
                'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_bin'], true),
            'NHE_ORDER_DETAIL_COMPLETED_REAL_GLOBAL_UNIQUE_SQL_METADATA');
        $matches = [];
        foreach ($beforeDB2['rows']['eb_store_product_reply'] as $index => $unused) {
            $candidate = nhepcv_native_row($beforeDB2, 'eb_store_product_reply', $index);
            nhep_expect(is_string($candidate['unique']) && preg_match('/\A[A-Za-z0-9]*\z/D', $candidate['unique']) === 1,
                'NHE_ORDER_DETAIL_COMPLETED_NO_NONCANONICAL_GLOBAL_UNIQUE_COLLATION_ALIAS');
            if (strtolower($candidate['unique']) === $saved['unique']) $matches[] = $candidate;
        }
        nhep_expect(count($matches) === 1, 'NHE_ORDER_DETAIL_COMPLETED_GLOBAL_UNIQUE_COUNT_EXACTLY_ONE');
        $reply = $matches[0]; $replyId = nhepcd_id($reply['id'], 2147483647);
        $replyTime = $reply['add_time']; $merchantTime = $reply['merchant_reply_time'];
        $merchantTimeColumn = $replyMetadata['columns']['merchant_reply_time'];
        nhep_expect(preg_match('/\Aint(?:\([0-9]+\))?\z/D', strtolower($merchantTimeColumn['COLUMN_TYPE'])) === 1
            && $merchantTimeColumn['IS_NULLABLE'] === 'NO'
            && is_int($replyTime) && $replyTime > 0 && $replyTime <= 2147483647
            && is_int($merchantTime) && $merchantTime > 0 && $merchantTime <= 2147483647
            && $reply === m4lh_actor_expected_row($replyMetadata, [
                'id' => $replyId, 'uid' => $uid, 'oid' => $oid, 'unique' => $saved['unique'],
                'product_id' => $fixture['product_id'], 'reply_type' => 'product',
                'product_score' => 5, 'service_score' => 5, 'comment' => 'normal-history-' . $intent['run_id'],
                'pics' => '', 'add_time' => $replyTime, 'merchant_reply_content' => 'normal-reply-' . $intent['run_id'],
                'merchant_reply_time' => $merchantTime, 'is_del' => 0, 'is_reply' => 1,
                'nickname' => $fixture['user']['row']['nickname'], 'avatar' => $fixture['user']['row']['avatar'],
                'suk' => $cart['productInfo']['attrInfo']['suk'], 'status' => 1]),
            'NHE_ORDER_DETAIL_COMPLETED_FULL_APPROVED_REPLIED_BEFORE_ROW_SAME_ROOT_BUYER_PRODUCT_AND_ITEM');
    }
    return ['family' => $family, 'paid' => $paid, 'fixture' => $fixture, 'order' => $order, 'item' => $item, 'cart' => $cart];
}

function nhecr_order_value(#[SensitiveParameter] array $beforeDB2, array $intent,
    #[SensitiveParameter] array $privateRequest): array
{
    foreach (['nhep_expect', 'nhe_keys', 'nhecr_order_context', 'nhepcv_native_row', 'nhepcv_config',
        'm4lh_actor_expected_row', 'bcadd', 'bcmul', 'json_encode', 'json_decode'] as $dependency)
        if (!function_exists($dependency)) throw new RuntimeException('NHE_DETAIL_VALUE_PURE_DEPENDENCY_REQUIRED');
    nhep_expect(PHP_INT_SIZE === 8 && class_exists(DateTimeImmutable::class, false)
        && class_exists(DateTimeZone::class, false), 'NHE_DETAIL_VALUE_NATIVE_64_BIT_AND_BUILTIN_DATES');
    // This is the original three-argument context, not a fabricated intent, POST body, tuple or ACK.
    $context = nhecr_order_context($beforeDB2, $intent, $privateRequest);
    nhe_keys($context, ['family', 'paid', 'fixture', 'order', 'item', 'cart'],
        'NHE_DETAIL_VALUE_EXACT_SHARED_SIX_FIELD_CONTEXT');
    nhep_expect(in_array($context['family'], ['refund_family', 'review_family'], true)
        && in_array($context['paid'], [0, 1], true) && is_array($context['fixture'])
        && is_array($context['order']) && is_array($context['item']) && is_array($context['cart'])
        && is_int($context['order']['index'] ?? null) && is_int($context['item']['index'] ?? null),
        'NHE_DETAIL_VALUE_COMPLETE_ORIGINAL_CONTEXT_TYPES');
    $fixture = $context['fixture']; $paid = $context['paid'];
    $refunded = $intent['step'] === 'current_refunded_root_detail';
    $shipped = $intent['step'] === 'owned_shipped_read';
    $received = $intent['step'] === 'owned_received_read';
    $completed = $intent['step'] === 'current_completed_root_detail';
    $order = nhepcv_native_row($beforeDB2, 'eb_store_order', $context['order']['index']);
    $item = nhepcv_native_row($beforeDB2, 'eb_store_order_cart_info', $context['item']['index']);
    nhep_expect($order === $context['order']['row'] && $item === $context['item']['row']
        && is_int($fixture['user']['index'] ?? null), 'NHE_DETAIL_VALUE_ORIGINAL_FULL_ORDER_AND_ITEM');
    $user = nhepcv_native_row($beforeDB2, 'eb_user', $fixture['user']['index']);
    nhep_expect($user === $fixture['user']['row'] && $user['uid'] === $fixture['uid']
        && $order['uid'] === $user['uid'] && $order['pay_uid'] === $user['uid'],
        'NHE_DETAIL_VALUE_BEFORE_ORIGINAL_USER_BIND_AND_SELF_PAYMENT');

    foreach (['station_open' => 1, 'store_self_mention' => 0, 'image_thumb_status' => 0,
        'balance_func_status' => 1, 'yue_pay_status' => 1, 'pay_weixin_open' => 0,
        'ali_pay_status' => 0, 'offline_pay_status' => 2, 'invoice_func_status' => 0,
        'special_invoice_status' => 0, 'order_shipping_open' => 0] as $name => $wanted)
        nhepcv_config($beforeDB2, $intent, $name, $wanted);
    $siteUrl = nhepcv_config($beforeDB2, $intent, 'site_url', $intent['origin']);
    nhep_expect(is_string($siteUrl) && $siteUrl === $intent['origin'],
        'NHE_DETAIL_VALUE_BEFORE_SITE_URL_EQUALS_REAL_REQUEST_ORIGIN');
    foreach (['tengxun_map_key', 'friend_pay_status', 'routine_contact_type', 'refund_time_available'] as $name)
        nhepodv_missing_config($beforeDB2, $name);
    $cancel = [];
    foreach (['order_cancel_time', 'order_activity_time', 'order_bargain_time',
        'order_seckill_time', 'order_pink_time'] as $name)
        $cancel[$name] = nhepcv_config($beforeDB2, $intent, $name, '0');
    // CREATE-only initialization and all admitted preparation/business writes exclude these tables.
    // Their actual complete BEFORE emptiness is still mandatory; a cache value is never evidence.
    nhep_expect($beforeDB2['rows']['eb_system_group'] === [] && $beforeDB2['rows']['eb_system_group_data'] === [],
        'NHE_DETAIL_VALUE_ORIGINAL_GROUP_AND_GROUP_DATA_BOTH_REALLY_EMPTY');

    // Original status column query has no order. Reject ambiguous duplicate keys instead of choosing a row.
    $logs = [];
    $refundMessages = $refunded ? ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功',
        'integral_back' => '商品退积分', 'refund_price' => '退款给用户：20.15元'] : [];
    if ($shipped) $refundMessages = ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功',
        'delivery_goods' => '已发货 快递公司：顺丰速运 快递单号：NHE' . $intent['run_id']];
    if ($received) $refundMessages = ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功',
        'delivery_goods' => '已发货 快递公司：顺丰速运 快递单号：NHE' . $intent['run_id'],
        'take_delivery' => '已收货'];
    if ($completed) $refundMessages = ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功',
        'delivery_goods' => '已发货 快递公司：顺丰速运 快递单号：NHE' . $intent['run_id'],
        'take_delivery' => '已收货', 'check_order_over' => '用户评价'];
    if ($refunded || $shipped || $received || $completed) {
        $statusTable = $beforeDB2['database']['tables']['eb_store_order_status'];
        $statusMetadata = $statusTable['metadata'];
        nhep_expect(array_keys($statusMetadata['columns']) === ['oid', 'change_type', 'change_message', 'change_time']
            && $statusMetadata['table']['AUTO_INCREMENT'] === null
            && !array_key_exists('PRIMARY', $statusMetadata['indexes']) && $statusMetadata['constraints'] === []
            && stripos($statusTable['ddl'], 'PRIMARY KEY') === false,
            'NHE_DETAIL_VALUE_REFUNDED_ORIGINAL_FOUR_STATUS_COLUMNS_WITHOUT_ID_PRIMARY_OR_COUNTER');
        foreach ($statusMetadata['columns'] as $column)
            nhep_expect($column['EXTRA'] !== 'auto_increment',
                'NHE_DETAIL_VALUE_REFUNDED_STATUS_HAS_NO_AUTO_INCREMENT_COLUMN');
    }
    foreach ($beforeDB2['rows']['eb_store_order_status'] as $index => $unused) {
        $status = nhepcv_native_row($beforeDB2, 'eb_store_order_status', $index);
        if ($status['oid'] !== $order['id']) continue;
        nhep_expect(is_string($status['change_type'])
            && in_array($status['change_type'], ($refunded || $shipped || $received || $completed) ? array_keys($refundMessages)
                : ($paid === 1 ? ['cache_key_create_order', 'pay_success'] : ['cache_key_create_order']), true)
            && !array_key_exists($status['change_type'], $logs)
            && is_int($status['change_time']) && $status['change_time'] > 0
            && $status['change_time'] <= 2147483647,
            'NHE_DETAIL_VALUE_ONLY_UNAMBIGUOUS_ORIGINAL_BEFORE_CREATE_PAY_LOGS');
        if ($refunded || $shipped || $received || $completed) nhep_expect($status === m4lh_actor_expected_row($statusMetadata, [
            'oid' => $order['id'], 'change_type' => $status['change_type'],
            'change_message' => $refundMessages[$status['change_type']], 'change_time' => $status['change_time']]),
            'NHE_DETAIL_VALUE_REFUNDED_COMPLETE_BEFORE_STATUS_ROW_AND_INDEPENDENT_SAVED_TIME');
        $logs[$status['change_type']] = $status['change_time'];
    }
    nhe_keys($logs, ($refunded || $shipped || $received || $completed) ? array_keys($refundMessages)
        : ($paid === 1 ? ['cache_key_create_order', 'pay_success'] : ['cache_key_create_order']),
        'NHE_DETAIL_VALUE_COMPLETE_ORIGINAL_NORMAL_STATUS_LOG_SET');
    if ($shipped) nhep_expect($logs['delivery_goods'] >= $logs['pay_success']
        && $logs['delivery_goods'] >= $order['pay_time'],
        'NHE_DETAIL_VALUE_SHIPPED_INDEPENDENT_HISTORICAL_DELIVERY_FOLLOWS_PAYMENT');
    if ($received) nhep_expect($logs['cache_key_create_order'] <= $logs['pay_success']
        && $logs['pay_success'] <= $logs['delivery_goods'] && $logs['delivery_goods'] <= $logs['take_delivery']
        && $logs['delivery_goods'] >= $order['pay_time'],
        'NHE_DETAIL_VALUE_RECEIVED_INDEPENDENT_COMPLETE_HISTORICAL_LOG_ORDER');

    if ($completed) {
        $replyCount = 0; $completedReply = null;
        foreach ($beforeDB2['rows']['eb_store_product_reply'] as $index => $unused) {
            $reply = nhepcv_native_row($beforeDB2, 'eb_store_product_reply', $index);
            nhep_expect(is_string($reply['unique']) && preg_match('/\A[A-Za-z0-9]*\z/D', $reply['unique']) === 1,
                'NHE_DETAIL_VALUE_COMPLETED_CANONICAL_FULL_GLOBAL_REPLY_COUNT');
            if (strtolower($reply['unique']) === $item['unique']) { $replyCount++; $completedReply = $reply; }
        }
        nhep_expect($replyCount === 1 && is_array($completedReply)
            && $completedReply['unique'] === $item['unique'] && $completedReply['oid'] === $order['id']
            && $completedReply['uid'] === $user['uid'] && $completedReply['product_id'] === $fixture['product_id']
            && $logs['cache_key_create_order'] <= $logs['pay_success']
            && $logs['pay_success'] <= $logs['delivery_goods'] && $logs['delivery_goods'] <= $logs['take_delivery']
            && $logs['delivery_goods'] >= $order['pay_time']
            && $logs['take_delivery'] <= $completedReply['add_time']
            && $completedReply['add_time'] <= $logs['check_order_over'],
            'NHE_DETAIL_VALUE_COMPLETED_GLOBAL_COUNT_ONE_AND_FIVE_INDEPENDENT_BEFORE_LOG_TIMES');
    } else {
    // count(unique) is global, not restricted to this OID; canonical keys avoid SQL collation guesses.
    foreach ($beforeDB2['rows']['eb_store_product_reply'] as $index => $unused) {
        $reply = nhepcv_native_row($beforeDB2, 'eb_store_product_reply', $index);
        nhep_expect(is_string($reply['unique']) && preg_match('/\A[A-Za-z0-9]*\z/D', $reply['unique']) === 1
            && strtolower($reply['unique']) !== $item['unique'],
            'NHE_DETAIL_VALUE_ORIGINAL_GLOBAL_ITEM_REPLY_COUNT_ZERO');
    }
    }

    // get(order_id, postage_price/member_price) admits the original no-row or single-row branch only.
    $economize = [];
    foreach ($beforeDB2['rows']['eb_store_order_economize'] as $index => $unused) {
        $saved = nhepcv_native_row($beforeDB2, 'eb_store_order_economize', $index);
        nhep_expect(is_string($saved['order_id']) && preg_match('/\A[A-Za-z0-9]*\z/D', $saved['order_id']) === 1,
            'NHE_DETAIL_VALUE_ORIGINAL_ECONOMIZE_CANONICAL_ORDER_NUMBER');
        if (strtolower($saved['order_id']) === $order['order_id']) {
            nhep_expect($saved['order_id'] === $order['order_id'], 'NHE_DETAIL_VALUE_ECONOMIZE_CASE_COLLISION_NOT_IMPLEMENTED');
            $economize[] = $saved;
        }
    }
    nhep_expect(count($economize) <= 1, 'NHE_DETAIL_VALUE_ORIGINAL_ECONOMIZE_QUERY_UNAMBIGUOUS');
    if ($economize !== []) nhep_expect(array_key_exists('postage_price', $economize[0])
        && array_key_exists('member_price', $economize[0]), 'NHE_DETAIL_VALUE_BOTH_ORIGINAL_NATIVE_ECONOMIZE_FIELDS');

    // ORM SELECT * order, native values and both getters; binding happens before relation toArray merge.
    $baseKeys = array_keys($order);
    $custom = json_decode($order['custom_form'], true);
    $cartIds = json_decode($order['cart_id'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && $custom === []
        && $cartIds === [(int)$item['cart_id']], 'NHE_DETAIL_VALUE_ORIGINAL_CUSTOM_FORM_AND_CART_ID_GETTERS');
    $order['custom_form'] = $custom; $order['cart_id'] = $cartIds;
    foreach (['nickname', 'phone', 'avatar'] as $name) {
        nhep_expect(!array_key_exists($name, $order) && is_string($user[$name]),
            'NHE_DETAIL_VALUE_ORIGINAL_USER_BIND_FIELDS_NO_COLLISION');
        $order[$name] = $user[$name];
    }
    nhep_expect(!array_key_exists('split', $order) && !array_key_exists('invoice', $order),
        'NHE_DETAIL_VALUE_ORIGINAL_RELATION_NAMES_NO_COLLISION');
    $order['split'] = []; $order['invoice'] = null; // nhecr_order_context proved both complete empty relations.
    $order['add_time_y'] = nhepodv_date('Y-m-d', $order['add_time']);
    $order['add_time_h'] = nhepodv_date('H:i:s', $order['add_time']);
    $order['system_store'] = false; $order['code'] = '';
    $order['mapKey'] = ''; $order['yue_pay_status'] = 1; $order['pay_weixin_open'] = false;
    $order['ali_pay_status'] = false; $order['friend_pay_status'] = 0;

    $cart = $context['cart'];
    nhep_expect($cart['price_type'] === 'level' && $cart['vip_truePrice'] === 0
        && $item['cart_num'] === 1 && $item['surplus_num'] === 1
        && $item['refund_num'] === ($refunded ? 1 : 0),
        'NHE_DETAIL_VALUE_ORIGINAL_LEVEL_PRICE_AND_NATIVE_ITEM_QUANTITIES');
    $cart['cart_num'] = $item['cart_num']; $cart['surplus_num'] = $item['surplus_num'];
    $cart['refund_num'] = $item['refund_num']; $cart['surplus_refund_num'] = $item['surplus_num'] - $item['refund_num'];
    $cart['unique'] = $item['unique']; $cart['is_reply'] = 0;
    if ($completed) $cart['is_reply'] = $replyCount;
    // image_thumb_status=0 leaves both historical product/SKU images and their complete field order unchanged.
    $cart['vip_sum_truePrice'] = bcmul((string)$cart['vip_truePrice'], (string)($cart['cart_num'] ?: 1), 2);
    $cart['is_valid'] = 1;
    $order['cartInfo'] = [$cart];
    $order['_status'] = $refunded
        ? ['_type' => -2, '_title' => '已退款', '_msg' => '已为您退款,感谢您的支持',
            '_class' => 'state-sqtk', '_payType' => '余额支付', '_deliveryType' => '其他方式']
        : ($received
        ? ['_type' => 3, '_title' => '待评价', '_msg' => '已收货,快去评价一下吧',
            '_class' => 'state-ypj', '_payType' => '余额支付', '_deliveryType' => '快递配送']
        : ($shipped
        ? ['_type' => 2, '_title' => '待收货',
            '_msg' => nhepodv_date('m月d日H时i分', $logs['delivery_goods']) . '服务商已发货',
            '_class' => 'state-ysh', '_payType' => '余额支付', '_deliveryType' => '快递配送']
        : ($paid === 0
        ? ['_type' => 0, '_title' => '未支付',
            '_msg' => '请在' . nhepodv_date('m-d H:i:s', $order['add_time']) . '前完成支付!',
            '_class' => 'nobuy', '_payType' => '', '_deliveryType' => '其他方式']
        : ['_type' => 1, '_title' => '未发货', '_msg' => '商家未发货,请耐心等待',
            '_class' => 'state-nfh', '_payType' => '余额支付', '_deliveryType' => '其他方式'])));
    if ($completed) $order['_status'] = ['_type' => 4, '_title' => '交易完成',
        '_msg' => '交易完成,感谢您的支持', '_class' => 'state-ytk',
        '_payType' => '余额支付', '_deliveryType' => '快递配送'];
    $order['_pay_time'] = $paid === 1 ? nhepodv_date('Y-m-d H:i:s', $order['pay_time']) : '';
    $order['_add_time'] = nhepodv_date('Y-m-d H:i:s', $order['add_time']);
    // Original Arr converts the five string values to float before local arithmetic.
    $order['stop_time'] = (float)$cancel['order_cancel_time'] * 3600 + $order['add_time'];
    $order['status_pic'] = ''; // original data_order_details_images derives [] from both BEFORE tables.
    $order['offlinePayStatus'] = 2;
    $order['order_log'] = ['create' => nhepodv_date('Y-m-d', $logs['cache_key_create_order']),
        'pay' => $paid === 1 ? nhepodv_date('Y-m-d', $logs['pay_success']) : '',
        'delivery' => ($shipped || $received) ? nhepodv_date('Y-m-d', $logs['delivery_goods']) : '',
        'take' => $received ? nhepodv_date('Y-m-d', $logs['take_delivery']) : '', 'complete' => ''];
    if ($completed) {
        $order['order_log']['delivery'] = nhepodv_date('Y-m-d', $logs['delivery_goods']);
        $order['order_log']['take'] = nhepodv_date('Y-m-d', $logs['take_delivery']);
        $order['order_log']['complete'] = nhepodv_date('Y-m-d', $logs['check_order_over']);
    }
    $order['gift_user_info'] = ['gift_uid' => $order['gift_uid'], 'gift_nickname' => '', 'gift_avatar' => ''];
    $order['vip_true_price'] = bcadd('0', $cart['vip_sum_truePrice'], 2);
    $order['levelPrice'] = bcadd('0', $cart['vip_sum_truePrice'], 2);
    $order['memberPrice'] = 0;
    $order['postage_price'] = $economize === [] ? 0 : $economize[0]['postage_price'];
    $order['member_price'] = $economize === [] ? 0 : $economize[0]['member_price'];
    $order['routine_contact_type'] = 0; $order['invoice_func'] = false; $order['special_invoice'] = false;
    $order['refund_cartInfo'] = $order['cartInfo']; $order['refund_total_num'] = $order['total_num'];
    $order['refund_pay_price'] = $order['pay_price']; $order['is_apply_refund'] = true;
    $order['help_info'] = ['pay_uid' => $order['pay_uid'], 'pay_nickname' => '', 'pay_avatar' => '', 'help_status' => 0];
    $order['gift_user_info'] = ['gift_uid' => $order['gift_uid'], 'gift_nickname' => '', 'gift_avatar' => ''];
    $order['order_shipping_open'] = false;
    $order['is_refund_available'] = true; // missing refund_time_available becomes int0; no clock branch.
    $order['gift_key'] = $order['gift_code'] = ''; // right-associative: gift_code is inserted first.
    if ($order['avatar'] && substr($order['avatar'], 0, 4) !== 'http' && substr($order['avatar'], 0, 2) !== '//')
        $order['avatar'] = $siteUrl . str_replace('\\', '/', $order['avatar']);

    $added = ['add_time_y', 'add_time_h', 'system_store', 'code', 'mapKey', 'yue_pay_status',
        'pay_weixin_open', 'ali_pay_status', 'friend_pay_status', 'cartInfo', '_status', '_pay_time',
        '_add_time', 'stop_time', 'status_pic', 'offlinePayStatus', 'order_log', 'gift_user_info',
        'vip_true_price', 'levelPrice', 'memberPrice', 'postage_price', 'member_price', 'routine_contact_type',
        'invoice_func', 'special_invoice', 'refund_cartInfo', 'refund_total_num', 'refund_pay_price',
        'is_apply_refund', 'help_info', 'order_shipping_open', 'is_refund_available', 'gift_code', 'gift_key'];
    nhep_expect(count(array_unique(array_merge($baseKeys,
        ['nickname', 'phone', 'avatar', 'split', 'invoice'], $added), SORT_STRING)) === count($baseKeys) + 40
        && array_keys($order) === array_merge($baseKeys, ['nickname', 'phone', 'avatar', 'split', 'invoice'], $added),
        'NHE_DETAIL_VALUE_ALL_ORIGINAL_COLUMNS_BIND_RELATIONS_AND_ADDED_KEY_ORDER');
    // The real response uses JSON_UNESCAPED_UNICODE without PRESERVE_ZERO_FRACTION.
    // Normalize only this independently built DTO, never a cache wire or the stored cart JSON.
    $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $view = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    nhep_expect(is_array($view) && array_keys($view) === array_keys($order),
        'NHE_DETAIL_VALUE_COMPLETE_INDEPENDENT_JSON_DATA_VIEW');
    return $view;
}

function nhecr_refund_context(#[SensitiveParameter] array $beforeDB2, array $intent,
    #[SensitiveParameter] array $privateRequest): array
{
    foreach (['nhep_expect', 'nhe_keys', 'nhecr_db_validate', 'nhepcd_one', 'nhepcd_id',
        'nhepcv_native_row', 'nhepcv_config', 'nhepcr_config_wire', 'm4lh_actor_native',
        'm4lh_actor_expected_row', 'bccomp', 'bcadd', 'bcmul', 'json_decode', 'json_encode'] as $dependency)
        if (!function_exists($dependency)) throw new RuntimeException('NHEPRFD_CONTEXT_PURE_DEPENDENCY_REQUIRED');
    nhep_expect(PHP_INT_SIZE === 8 && class_exists(DateTimeImmutable::class, false)
        && class_exists(DateTimeZone::class, false), 'NHEPRFD_CONTEXT_NATIVE_64_BIT_AND_EXPLICIT_DATE_CLASSES');
    nhecr_db_validate($beforeDB2);
    nhe_keys($intent, ['run_id', 'sequence', 'step', 'origin', 'method', 'path', 'actor', 'actor_id'],
        'NHEPRFD_CONTEXT_ORIGINAL_COMPLETE_INTENT');
    nhep_expect($intent['sequence'] === 8 && $intent['step'] === 'current_refund_detail'
        && $intent['method'] === 'GET' && $intent['actor'] === 'customer'
        && $intent['origin'] === 'http://127.0.0.1:8000' && is_string($intent['run_id'])
        && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $intent['run_id']) === 1
        && is_string($intent['path'])
        && preg_match('~\A/api/order/refund/detail/([1-9][0-9]{0,18})\z~D', $intent['path'], $path) === 1
        && bccomp($path[1], '9223372036854775807', 0) <= 0,
        'NHEPRFD_CONTEXT_TRUE_25_CUSTOMER_ORIGIN_AND_CANONICAL_BARE_REFUND_NUMBER_PATH');
    nhe_keys($privateRequest, ['token', 'body', 'actor_identity'], 'NHEPRFD_CONTEXT_PRIVATE_REQUEST_EXACT_THREE');
    nhep_expect($privateRequest['body'] === null && is_string($privateRequest['token'])
        && preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/D', $privateRequest['token']) === 1
        && is_array($privateRequest['actor_identity']), 'NHEPRFD_CONTEXT_REAL_GET_NULL_BODY_AND_EXISTING_TOKEN');
    nhe_keys($privateRequest['actor_identity'], ['id', 'account'], 'NHEPRFD_CONTEXT_ORIGINAL_IDENTITY_EXACT_TWO');
    $uid = nhepcd_id($intent['actor_id'], 2147483647);
    nhep_expect(is_int($intent['actor_id']) && $privateRequest['actor_identity']['id'] === $uid
        && is_string($privateRequest['actor_identity']['account'])
        && preg_match('/\A[a-z0-9_]{10,32}\z/D', $privateRequest['actor_identity']['account']) === 1,
        'NHEPRFD_CONTEXT_REAL_NATIVE_CUSTOMER_ID_AND_ACCOUNT');
    $sentinels = $beforeDB2['rows']['audit_sentinel'];
    nhep_expect(count($sentinels) === 1 && ($sentinels[0]['run_id'] ?? null) === $intent['run_id'],
        'NHEPRFD_CONTEXT_COMPLETE_BEFORE_SAME_ORIGINAL_RUN');
    $user = nhepcd_one($beforeDB2, 'eb_user', ['uid' => $uid]);
    $userRow = nhepcv_native_row($beforeDB2, 'eb_user', $user['index']);
    nhep_expect($userRow === $user['row'] && $userRow['account'] === $privateRequest['actor_identity']['account']
        && $userRow['status'] === 1 && $userRow['is_del'] === 0 && $userRow['user_type'] === 'h5'
        && $userRow['nickname'] === '旧历史普通顾客' && $userRow['now_money'] === '79.85'
        && $userRow['pay_count'] === 2 && $userRow['integral'] === 0 && $userRow['spread_uid'] === 0
        && $userRow['is_money_level'] === 0 && $userRow['level'] === 0 && $userRow['brokerage_price'] === '0.00'
        && is_int($userRow['add_time']) && $userRow['add_time'] > 0
        && is_int($userRow['last_time']) && $userRow['last_time'] >= $userRow['add_time'],
        'NHEPRFD_CONTEXT_COMPLETE_BEFORE_NORMAL_OWNER_AFTER_BALANCE_REFUND');
    $family = 'refund_family';
    $order = nhepcd_one($beforeDB2, 'eb_store_order', ['mark' => $intent['run_id'] . '-' . $family]);
    $root = nhepcv_native_row($beforeDB2, 'eb_store_order', $order['index']);
    $oid = nhepcd_id($root['id'], 2147483647);
    nhep_expect($root === $order['row'] && nhepcd_one($beforeDB2, 'eb_store_order', ['id' => $oid]) === $order
        && $root['uid'] === $uid && $root['pay_uid'] === $uid && $root['paid'] === 1
        && $root['pay_type'] === 'yue' && $root['channel_type'] === 'h5'
        && is_string($root['order_id']) && preg_match('/\Acp([1-9][0-9]{0,18})\z/D', $root['order_id'], $cp) === 1
        && bccomp($cp[1], '9223372036854775807', 0) <= 0
        && is_string($root['unique']) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $root['unique']) === 1
        && bccomp($root['unique'], '9223372036854775807', 0) <= 0,
        'NHEPRFD_CONTEXT_INDEPENDENT_BEFORE_MARK_PK_OWNER_PAY_OWNER_AND_SAVED_ROOT_KEYS');
    foreach (['marketplace_trade_version' => 0, 'merchant_name_snapshot' => null, 'merchant_after_sale_contact_snapshot' => null, 'pid' => 0, 'mer_id' => 0, 'status' => -2, 'refund_status' => 2, 'refund_type' => 6,
        'is_del' => 0, 'is_system_del' => 0, 'is_cancel' => 0, 'is_stock_up' => 0,
        'shipping_type' => 1, 'total_num' => 1, 'store_id' => 0, 'delivery_uid' => 0, 'clerk_id' => 0,
        'is_channel' => 2, 'virtual_type' => 0, 'is_gift' => 0, 'gift_uid' => 0,
        'combination_id' => 0, 'pink_id' => 0, 'seckill_id' => 0, 'bargain_id' => 0, 'advance_id' => 0,
        'coupon_id' => 0, 'spread_uid' => 0, 'spread_two_uid' => 0, 'staff_id' => 0, 'agent_id' => 0, 'division_id' => 0,
        'verify_code' => '', 'delivery_type' => '', 'delivery_id' => '', 'delivery_name' => '', 'delivery_code' => '',
        'gift_mark' => '', 'custom_form' => '[]', 'refund_express' => '', 'refund_express_name' => '',
        'refund_reason_wap_img' => '', 'refund_reason_wap_explain' => '', 'refund_reason_time' => 0,
        'refund_reason_wap' => 'normal-refund-' . $intent['run_id'],
        'total_price' => '20.15', 'pay_price' => '20.15', 'cost' => '7.45', 'freight_price' => '0.00',
        'total_postage' => '0.00', 'pay_postage' => '0.00', 'deduction_price' => '0.00', 'coupon_price' => '0.00',
        'gift_price' => '0.00', 'refund_price' => '20.15', 'use_integral' => '0.00', 'gain_integral' => '0.00',
        'back_integral' => '0.00', 'one_brokerage' => '0.00', 'two_brokerage' => '0.00',
        'staff_brokerage' => '0.00', 'agent_brokerage' => '0.00', 'division_brokerage' => '0.00'] as $column => $value)
        nhep_expect(array_key_exists($column, $root) && $root[$column] === $value,
            'NHEPRFD_CONTEXT_ONLY_ORIGINAL_NORMAL_COMPLETED_FULL_BALANCE_REFUND_ROOT');
    nhep_expect(is_int($root['add_time']) && $root['add_time'] >= $userRow['add_time']
        && is_int($root['pay_time']) && $root['pay_time'] >= $root['add_time']
        && $root['pay_time'] <= 2147483647, 'NHEPRFD_CONTEXT_ORIGINAL_CREATE_AND_PAY_NATIVE_TIMES');

    $item = nhepcd_one($beforeDB2, 'eb_store_order_cart_info', ['oid' => $oid]);
    $saved = nhepcv_native_row($beforeDB2, 'eb_store_order_cart_info', $item['index']);
    nhep_expect(array_keys($item['metadata']['columns']) === ['id', 'oid', 'uid', 'cart_id', 'product_id',
        'old_cart_id', 'cart_num', 'refund_num', 'surplus_num', 'split_status', 'cart_info', 'unique', 'mer_id'],
        'NHEPRFD_CONTEXT_ORIGINAL_COMPLETE_TWELVE_ITEM_COLUMNS');
    $itemId = nhepcd_id($saved['id'], 2147483647); $cartId = nhepcd_id($saved['cart_id']);
    $productId = nhepcd_id($saved['product_id'], 2147483647);
    nhep_expect($saved === $item['row'] && $saved === m4lh_actor_expected_row($item['metadata'], [
        'id' => $itemId, 'oid' => $oid, 'uid' => $uid, 'cart_id' => (string)$cartId,
        'product_id' => $productId, 'old_cart_id' => '', 'cart_num' => 1, 'refund_num' => 1,
        'surplus_num' => 1, 'split_status' => 0, 'cart_info' => $saved['cart_info'], 'unique' => md5($cartId . '' . $oid)])
        && is_string($saved['cart_info']) && $saved['cart_info'] !== '' && $root['cart_id'] === json_encode([$cartId]),
        'NHEPRFD_CONTEXT_COMPLETE_ORIGINAL_OWN_UNSPLIT_ITEM_AND_ROOT_CART_RELATION');
    $cart = json_decode($saved['cart_info'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && is_array($cart) && !array_is_list($cart)
        && json_encode($cart) === $saved['cart_info'], 'NHEPRFD_CONTEXT_COMPLETE_BEFORE_SAVED_DEFAULT_JSON_CART');
    $cartMetadata = $beforeDB2['database']['tables']['eb_store_cart']['metadata']; $baseCart = [];
    nhep_expect(count($cartMetadata['columns']) === 15
        && array_slice(array_keys($cart), 0, 15) === array_keys($cartMetadata['columns']),
        'NHEPRFD_CONTEXT_ORIGINAL_FIFTEEN_NATIVE_SAVED_CART_BASE_COLUMNS');
    foreach ($cartMetadata['columns'] as $column => $definition) {
        $baseCart[$column] = m4lh_actor_native($definition, $cart[$column]);
        nhep_expect($baseCart[$column] === $cart[$column], 'NHEPRFD_CONTEXT_EVERY_NATIVE_SAVED_CART_BASE_FIELD');
    }
    $skuUnique = substr(hash('sha256', 'legacy-sku-' . $intent['run_id']), 0, 8);
    nhep_expect(is_int($baseCart['add_time']) && $baseCart['add_time'] > 0 && $baseCart['add_time'] <= $root['add_time']
        && $baseCart === m4lh_actor_expected_row($cartMetadata, ['id' => $cartId, 'uid' => $uid, 'type' => '0',
            'product_id' => $productId, 'product_attr_unique' => $skuUnique, 'cart_num' => 1, 'add_time' => $baseCart['add_time']])
        && is_array($cart['productInfo'] ?? null) && is_array($cart['attrInfo'] ?? null)
        && ($cart['productInfo']['attrInfo'] ?? null) === $cart['attrInfo']
        && ($cart['productInfo']['id'] ?? null) === $productId
        && ($cart['productInfo']['store_name'] ?? null) === '旧历史商品-' . $intent['run_id']
        && ($cart['attrInfo']['product_id'] ?? null) === $productId
        && ($cart['attrInfo']['unique'] ?? null) === $skuUnique && ($cart['attrInfo']['type'] ?? null) === 0
        && ($cart['attrInfo']['suk'] ?? null) === '普通' && ($cart['truePrice'] ?? null) === 20.15
        && ($cart['sum_price'] ?? null) === '20.15' && ($cart['postage_price'] ?? null) === 0
        && ($cart['origin_postage_price'] ?? 0) === 0 && ($cart['price_type'] ?? null) === 'level'
        && ($cart['vip_truePrice'] ?? null) === 0 && ($cart['use_integral'] ?? null) === 0
        && ($cart['integral_price'] ?? null) === 0 && ($cart['coupon_price'] ?? null) === 0
        && ($cart['sum_true_price'] ?? null) === '20.15'
        && !array_key_exists('refund_num', $cart) && !array_key_exists('vip_sum_truePrice', $cart),
        'NHEPRFD_CONTEXT_REAL_SAVED_CART_IDENTITIES_NORMAL_BC_BRANCH_AND_UNCHANGED_ORIGINAL_FIELDS');
    nhepcd_id($cart['attrInfo']['id'] ?? null);
    // Only the saved history is inspected. No current catalog row, stock, merchant or address is selected.
    foreach ([[$cart['productInfo'], 'eb_store_product'], [$cart['attrInfo'], 'eb_store_product_attr_value']] as [$history, $table]) {
        nhep_expect(is_string($history['image'] ?? null) && $history['image'] !== '',
            'NHEPRFD_CONTEXT_ORIGINAL_NONEMPTY_HISTORICAL_PRODUCT_AND_SKU_IMAGES');
        foreach (['stock', 'sales'] as $column)
            nhep_expect(is_int($history[$column] ?? null) && $history[$column] >= ($column === 'stock' ? 1 : 0)
                && m4lh_actor_native($beforeDB2['database']['tables'][$table]['metadata']['columns'][$column], $history[$column]) === $history[$column],
                'NHEPRFD_CONTEXT_COMPLETE_ORIGINAL_NATIVE_HISTORICAL_INVENTORY_IS_RETAINED');
    }

    $refund = nhepcd_one($beforeDB2, 'eb_store_order_refund', ['store_order_id' => $oid, 'uid' => $uid]);
    $row = nhepcv_native_row($beforeDB2, 'eb_store_order_refund', $refund['index']);
    $metadata = $refund['metadata'];
    $defaults = ['id' => null, 'store_order_id' => 0, 'store_id' => 0, 'order_id' => '', 'uid' => 0,
        'refund_type' => 0, 'refund_num' => 0, 'refund_price' => '0.00', 'refunded_price' => '0.00',
        'refund_phone' => '', 'refund_express' => '', 'refund_express_name' => '', 'refund_explain' => '',
        'refund_img' => null, 'refund_reason' => '', 'refuse_reason' => '', 'remark' => '', 'refunded_time' => 0,
        'cart_info' => null, 'is_cancel' => 0, 'is_pink_cancel' => 0, 'is_del' => 0, 'add_time' => 0, 'is_system_del' => 0];
    nhep_expect(count($defaults) === 24 && array_keys($metadata['columns']) === array_merge(array_keys($defaults), ['mer_id', 'marketplace_refund_mode']) && $row['mer_id'] === null && $row['marketplace_refund_mode'] === null,
        'NHEPRFD_CONTEXT_ORIGINAL_COMPLETE_TWENTY_FOUR_REFUND_COLUMNS');
    foreach ($defaults as $column => $default) {
        $definition = $metadata['columns'][$column]; $type = strtolower($definition['COLUMN_TYPE']);
        if (in_array($column, ['refund_price', 'refunded_price'], true)) $rightType = $type === 'decimal(12,2)';
        elseif (in_array($column, ['refund_type', 'is_cancel', 'is_pink_cancel', 'is_del', 'is_system_del'], true))
            $rightType = preg_match('/\Atinyint(?:\([0-9]+\))?\z/D', $type) === 1;
        elseif (in_array($column, ['id', 'store_order_id', 'store_id', 'uid', 'refund_num', 'refunded_time', 'add_time'], true))
            $rightType = preg_match('/\Aint(?:\([0-9]+\))?\z/D', $type) === 1;
        elseif ($column === 'refund_img') $rightType = $type === 'text';
        elseif ($column === 'cart_info') $rightType = $type === 'longtext';
        else {
            $length = ['order_id' => 50, 'refund_phone' => 32, 'refund_express' => 100,
                'refund_express_name' => 255, 'refund_explain' => 255, 'refund_reason' => 255,
                'refuse_reason' => 255, 'remark' => 255][$column];
            $rightType = $type === 'varchar(' . $length . ')';
        }
        nhep_expect($rightType && $definition['COLUMN_NAME'] === $column && $definition['GENERATION_EXPRESSION'] === ''
            && $definition['EXTRA'] === ($column === 'id' ? 'auto_increment' : '')
            && $definition['IS_NULLABLE'] === (in_array($column, ['refund_img', 'cart_info', 'is_del', 'is_system_del'], true) ? 'YES' : 'NO'),
            'NHEPRFD_CONTEXT_ORIGINAL_REFUND_NATIVE_TYPE_NULLABILITY_AND_NO_GENERATED_COLUMN');
        if ($column === 'id') nhep_expect($definition['COLUMN_DEFAULT'] === null, 'NHEPRFD_CONTEXT_ORIGINAL_AUTO_ID_DEFAULT');
        else nhep_expect(m4lh_actor_native($definition, $definition['COLUMN_DEFAULT']) === $default,
            'NHEPRFD_CONTEXT_ORIGINAL_REFUND_NATIVE_DEFAULTS');
    }
    $rid = nhepcd_id($row['id'], 2147483647); $number = $row['order_id']; $refundTime = $row['add_time'];
    nhep_expect($row === $refund['row'] && is_string($number)
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $number) === 1 && bccomp($number, '9223372036854775807', 0) <= 0
        && $path[1] === $number && is_int($refundTime) && $refundTime >= $root['pay_time']
        && $refundTime <= 2147483647 && is_int($row['refunded_time'])
        && $row['refunded_time'] >= $refundTime && $row['refunded_time'] <= 2147483647,
        'NHEPRFD_CONTEXT_URL_EQUALS_BEFORE_EXISTING_BARE_N_AND_NATIVE_APPLICATION_COMPLETION_TIMES');
    $cartJSON = json_encode([$cart]);
    nhep_expect(is_string($cartJSON) && $row === m4lh_actor_expected_row($metadata, [
        'id' => $rid, 'store_order_id' => $oid, 'store_id' => $root['store_id'], 'order_id' => $number,
        'uid' => $uid, 'refund_type' => 6, 'refund_num' => 1, 'refund_price' => '20.15', 'refunded_price' => '20.15',
        'refund_explain' => '', 'refund_img' => '[]', 'refund_reason' => 'normal-refund-' . $intent['run_id'],
        'add_time' => $refundTime, 'refunded_time' => $row['refunded_time'], 'cart_info' => $cartJSON, 'is_pink_cancel' => 0]),
        'NHEPRFD_CONTEXT_COMPLETE_SAVED_COMPLETED_REFUND_FROM_BEFORE_ROOT_ITEM_AND_ORIGINAL_DEFAULTS');
    $numberColumn = $metadata['columns']['order_id'];
    nhep_expect($numberColumn['CHARACTER_SET_NAME'] === 'utf8mb4'
        && in_array($numberColumn['COLLATION_NAME'], ['utf8mb4_0900_ai_ci', 'utf8mb4_general_ci',
            'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_bin'], true),
        'NHEPRFD_CONTEXT_ORIGINAL_BARE_NUMBER_COLUMN_FINITE_SQL_STRING_COLLATIONS');
    $selected = [];
    foreach ($beforeDB2['rows']['eb_store_order_refund'] as $index => $unused) {
        $candidate = nhepcv_native_row($beforeDB2, 'eb_store_order_refund', $index); $key = $candidate['order_id'];
        nhep_expect(is_string($key) && preg_match('/\A[\x20-\x7e]*\z/D', $key) === 1
            && (strtolower(rtrim($key, ' ')) !== $number || $key === $number),
            'NHEPRFD_CONTEXT_FULL_REFUND_TABLE_CANONICAL_SQL_NUMBER_SELECTION_WITHOUT_EQUIVALENT_ALIAS');
        if ($key === $number) $selected[] = ['index' => $index, 'row' => $candidate, 'metadata' => $metadata];
    }
    nhep_expect($selected === [$refund],
        'NHEPRFD_CONTEXT_REAL_DIRECT_WHERE_BARE_NUMBER_SELECTS_ONE_ORIGINAL_ROW_WITH_OTHER_HISTORY_RETAINED');
    $configuration = [];
    foreach (['station_open' => 1, 'balance_func_status' => 1, 'yue_pay_status' => 1,
        'pay_weixin_open' => 0, 'ali_pay_status' => 0] as $name => $wanted)
        $configuration[$name] = nhepcv_config($beforeDB2, $intent, $name, $wanted);
    foreach (['tengxun_map_key', 'routine_contact_type', 'refund_name', 'refund_phone', 'refund_address'] as $name)
        nhep_expect(nhepcr_config_wire($beforeDB2, $name, null) === 'N;',
            'NHEPRFD_CONTEXT_FIVE_TRULY_ABSENT_CONFIG_ROWS_WITH_ORIGINAL_DTO_DEFAULTS');
    foreach (['eb_lang_code', 'eb_lang_country', 'eb_lang_type'] as $table)
        nhep_expect($beforeDB2['rows'][$table] === [], 'NHEPRFD_CONTEXT_THREE_LANGUAGE_TABLES_EMPTY');

    // Original Model getters decode only saved JSON; no bound user relation is requested by this DAO get.
    $dto = $row; $dto['cart_info'] = json_decode($row['cart_info'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && $dto['cart_info'] === [$cart],
        'NHEPRFD_VALUE_COMPLETE_BEFORE_REFUND_CART_GETTER');
    $dto['refund_img'] = json_decode($row['refund_img'], true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && $dto['refund_img'] === [], 'NHEPRFD_VALUE_EMPTY_IMAGE_GETTER');
    $dto['mapKey'] = '';
    $dto['yue_pay_status'] = (int)$configuration['balance_func_status'] && (int)$configuration['yue_pay_status'] === 1 ? 1 : 2;
    $dto['pay_weixin_open'] = (int)$configuration['pay_weixin_open'];
    $dto['ali_pay_status'] = (bool)$configuration['ali_pay_status'];
    $dto['store_order_sn'] = $root['order_id']; $dto['cartInfo'] = $dto['cart_info'];
    $zone = new DateTimeZone('Asia/Shanghai');
    $paidDate = (new DateTimeImmutable('@' . (string)$root['pay_time']))->setTimezone($zone);
    $applicationDate = (new DateTimeImmutable('@' . (string)$refundTime))->setTimezone($zone);
    $dto['_pay_time'] = $paidDate->format('Y-m-d H:i:s'); $dto['type'] = 0;
    $vipTruePrice = '0'; $totalPrice = '0'; $payPostage = '0';
    foreach ($dto['cartInfo'] as &$detailCart) {
        if (!isset($detailCart['sum_true_price']))
            $detailCart['sum_true_price'] = bcmul((string)$detailCart['truePrice'], (string)$detailCart['cart_num'], 2);
        $detailCart['vip_sum_truePrice'] = bcmul((string)$detailCart['vip_truePrice'], (string)($detailCart['cart_num'] ?: 1), 2);
        $vipTruePrice = bcadd($vipTruePrice, $detailCart['vip_sum_truePrice'], 2);
        $totalPrice = bcadd($totalPrice, $detailCart['sum_true_price'], 2);
        $payPostage = bcadd((string)$detailCart['postage_price'], $payPostage, 2);
    }
    unset($detailCart);
    foreach (['use_integral' => 'use_integral', 'integral_price' => 'integral_price',
        'coupon_price' => 'coupon_price', 'deduction_price' => 'integral_price'] as $field => $source) {
        $sum = '0';
        foreach ($dto['cartInfo'] as $savedCart) $sum = bcadd($sum, (string)($savedCart[$source] ?? 0), 2);
        $dto[$field] = $sum;
    }
    $totalPrice = bcadd($totalPrice, bcadd($dto['deduction_price'], $dto['coupon_price'], 2), 2);
    $dto['vip_true_price'] = $vipTruePrice; $dto['postage_price'] = 0;
    $originPostage = '0';
    foreach ($dto['cart_info'] as $savedCart)
        $originPostage = bcadd($originPostage, (string)($savedCart['origin_postage_price'] ?? 0), 2);
    $dto['pay_postage'] = $originPostage; $dto['member_price'] = 0; $dto['routine_contact_type'] = 0;
    $levelPrice = 0; $memberPrice = 0;
    foreach ($dto['cart_info'] as $savedCart) {
        if ($savedCart['price_type'] === 'level')
            $levelPrice = bcadd((string)$levelPrice, bcmul((string)($savedCart['cart_num'] ?? 1), (string)$savedCart['vip_truePrice'], 2), 2);
        if ($savedCart['price_type'] === 'member')
            $memberPrice = bcadd((string)$memberPrice, bcmul((string)($savedCart['cart_num'] ?? 1), (string)$savedCart['vip_truePrice'], 2), 2);
    }
    $dto['levelPrice'] = $levelPrice; $dto['memberPrice'] = $memberPrice; $dto['pay_type'] = $root['pay_type'];
    $dto['_add_time'] = $applicationDate->format('Y-m-d H:i:s');
    $dto['add_time_y'] = $applicationDate->format('Y-m-d'); $dto['add_time_h'] = $applicationDate->format('H:i:s');
    $dto['_status'] = ['_type' => -2, '_title' => '已退款', '_msg' => '已为您退款,感谢您的支持',
        '_payType' => '余额支付', 'refund_name' => '', 'refund_phone' => '', 'refund_address' => ''];
    $dto['real_name'] = $root['real_name']; $dto['user_phone'] = $root['user_phone'];
    $dto['user_address'] = $root['user_address']; $dto['nickname'] = $userRow['nickname'];
    $dto['total_num'] = $dto['refund_num']; $dto['pay_price'] = $dto['refund_price'];
    $dto['refund_status'] = 2; $dto['total_price'] = $totalPrice; $dto['paid'] = 1;
    $dto['mark'] = $dto['refund_explain']; $dto['express_list'] = []; $dto['custom_form'] = [];
    $dto['help_info'] = ['pay_uid' => $root['pay_uid'], 'pay_nickname' => '', 'pay_avatar' => '', 'help_status' => 0];
    // This original final overwrite keeps the earlier key position and the BC string, not a float/int zero.
    $dto['pay_postage'] = $payPostage;
    $added = ['mapKey', 'yue_pay_status', 'pay_weixin_open', 'ali_pay_status', 'store_order_sn', 'cartInfo',
        '_pay_time', 'type', 'use_integral', 'integral_price', 'coupon_price', 'deduction_price', 'vip_true_price',
        'postage_price', 'pay_postage', 'member_price', 'routine_contact_type', 'levelPrice', 'memberPrice', 'pay_type',
        '_add_time', 'add_time_y', 'add_time_h', '_status', 'real_name', 'user_phone', 'user_address', 'nickname',
        'total_num', 'pay_price', 'refund_status', 'total_price', 'paid', 'mark', 'express_list', 'custom_form', 'help_info'];
    nhep_expect(count($added) === 37 && count($dto) === 63
        && array_keys($dto) === array_merge(array_keys($metadata['columns']), $added)
        && $dto['cart_info'] === [$cart] && $dto['cartInfo'][0]['sum_true_price'] === $cart['sum_true_price']
        && array_keys($dto['cartInfo'][0]) === array_merge(array_keys($cart), ['vip_sum_truePrice'])
        && $dto['cartInfo'][0]['vip_sum_truePrice'] === '0.00'
        && $dto['levelPrice'] === '0.00' && $dto['memberPrice'] === 0 && $dto['pay_postage'] === '0.00'
        && $dto['total_price'] === '20.15' && $dto['pay_price'] === '20.15' && $dto['add_time'] === $refundTime,
        'NHEPRFD_VALUE_EXACT_SIXTY_ONE_KEY_ORDER_AND_INDEPENDENT_CART_COPIES_BC_TYPES_AND_NATIVE_ADD_TIME');
    $wire = json_encode($dto, JSON_UNESCAPED_UNICODE);
    nhep_expect(is_string($wire) && json_last_error() === JSON_ERROR_NONE, 'NHEPRFD_VALUE_INDEPENDENT_COMPLETE_RESPONSE_JSON');
    $value = json_decode($wire, true);
    nhep_expect(json_last_error() === JSON_ERROR_NONE && is_array($value) && !array_is_list($value)
        && array_keys($value) === array_keys($dto), 'NHEPRFD_VALUE_ORIGINAL_JSON_NORMALIZED_COMPLETE_DATA');
    return ['family' => $family, 'user' => $user, 'order' => $order, 'item' => $item,
        'refund' => $refund, 'cart' => $cart, 'value' => $value];
}

/** Hold original public provenance while reusing the complete six-child initializer qualification. */
function nhecr_initializer_self(array $state, array &$proofs): int
{
    nhecr_expect(function_exists('nhe_business_self_id'), 'NHECR_ORIGINAL_INITIALIZER_QUALIFIER_REQUIRED');
    $root = '/run/audit/evidence/nonempty-history-phases/before-legacy-files';
    $paths = [$root . '/INDEX.json'];
    foreach (['initializer-created.json', 'initializer-terminal.json', 'initializer-result.json'] as $leaf) {
        $paths[] = '/run/audit/evidence/' . $leaf; $paths[] = $root . '/evidence/' . $leaf;
    }
    $proofs = [];
    foreach ($paths as $path) $proofs[$path] = nhecr_public_read($path);
    $self = nhe_business_self_id($state);
    nhecr_expect(is_int($self) && $self > 0 && $self <= 4294967295,
        'NHECR_REAL_SIX_CHILD_ORIGINAL_INITIALIZER_SELF_NOT_A_MERCHANT_GUESS');
    foreach ($proofs as $path => $proof) nhecr_expect(nhecr_public_read($path) === $proof,
        'NHECR_ORIGINAL_INITIALIZER_ARCHIVE_INDEX_AND_SIX_RAW_STAT9_HELD');
    return $self;
}

/** A new private RAM context, never an original preparation receipt or a public seal field. */
function nhecr_private_fixture_context(array $state, #[SensitiveParameter] array $database,
    #[SensitiveParameter] array $cache, #[SensitiveParameter] array $private): array
{
    $ignore = ini_set('zend.exception_ignore_args', '1');
    nhecr_expect($ignore !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHECR_CONTEXT_REAL_PRIVATE_ARGUMENT_BOUNDARY_BEFORE_ORIGINAL_CALLEES');
    nhecr_fresh($state); m4lh_actor_private($private);
    $expectedIdentities = nhecr_baseline($state, $database, $cache);
    $run = $state['context']['run_id']; $self = $state['initializer_self'];
    nhecr_expect(is_int($self) && $self > 0 && $self <= 4294967295, 'NHECR_CONTEXT_QUALIFIED_ORIGINAL_SELF');
    // Select native rows before consulting any HTTP ID or expected identity.
    $buyer = nhepcd_one($database, 'eb_user', ['account' => $private['user_account']]);
    $user = nhepcv_native_row($database, 'eb_user', $buyer['index']);
    $adminSelection = nhepcd_one($database, 'eb_system_admin', ['account' => $private['admin_account']]);
    $admin = nhepcv_native_row($database, 'eb_system_admin', $adminSelection['index']);
    $uid = nhepcd_id($user['uid'], 2147483647); $adminId = nhepcd_id($admin['id'], 2147483647);
    nhecr_expect(is_int($user['uid']) && $user['uid'] === $uid && is_int($admin['id']) && $admin['id'] === $adminId
        && $user['account'] === $private['user_account'] && $user['pwd'] === md5($private['user_password'])
        && $user['pwd'] !== md5('123456') && $admin['account'] === $private['admin_account']
        && is_string($admin['pwd']) && password_get_info($admin['pwd'])['algoName'] === 'bcrypt'
        && password_verify($private['admin_password'], $admin['pwd']), 'NHECR_CONTEXT_ORIGINAL_PRIVATE_ACCOUNTS_RESET_AND_PLATFORM_BCRYPT');
    $productSelection = nhepcd_one($database, 'eb_store_product', ['store_name' => '旧历史商品-' . $run]);
    $product = nhepcv_native_row($database, 'eb_store_product', $productSelection['index']);
    $productId = nhepcd_id($product['id'], 2147483647);
    $unique = substr(hash('sha256', 'legacy-sku-' . $run), 0, 8);
    $skuSelection = nhepcd_one($database, 'eb_store_product_attr_value', ['unique' => $unique, 'type' => 0]);
    $sku = nhepcv_native_row($database, 'eb_store_product_attr_value', $skuSelection['index']);
    $attributeSelection = nhepcd_one($database, 'eb_store_product_attr', ['product_id' => $productId, 'type' => 0]);
    $attribute = nhepcv_native_row($database, 'eb_store_product_attr', $attributeSelection['index']);
    $addressSelection = nhepcd_one($database, 'eb_user_address', ['uid' => $uid, 'is_default' => 1]);
    $address = nhepcv_native_row($database, 'eb_user_address', $addressSelection['index']);
    $categorySelection = nhepcd_one($database, 'eb_category', ['name' => 'normal-label-category-' . $run]);
    $category = nhepcv_native_row($database, 'eb_category', $categorySelection['index']);
    $labelSelection = nhepcd_one($database, 'eb_user_label', ['label_name' => 'normal-purchase-label-' . $run]);
    $label = nhepcv_native_row($database, 'eb_user_label', $labelSelection['index']);
    $expressSelection = nhepcd_one($database, 'eb_express', ['code' => 'shunfeng']);
    $express = nhepcv_native_row($database, 'eb_express', $expressSelection['index']);
    $merchantSelection = nhepcd_one($database, 'eb_marketplace_merchant', ['mer_id' => $self]);
    $merchant = nhepcv_native_row($database, 'eb_marketplace_merchant', $merchantSelection['index']);
    $time = $user['add_time'];
    nhecr_expect(is_int($time) && $time > 0 && $time <= 2147483647 && $admin['add_time'] === $time
        && $address['add_time'] === $time && $product['add_time'] === $time,
        'NHECR_CONTEXT_REAL_SHARED_ORIGINAL_FIXTURE_TIME_FROM_COMPLETE_NATIVE_ROWS');
    nhecr_expect($product['id'] === $productId && $product['mer_id'] === $self
        && $sku['product_id'] === $productId && $sku['type'] === 0 && $sku['unique'] === $unique && $sku['suk'] === '普通'
        && $attribute['product_id'] === $productId && $attribute['type'] === 0
        && $attribute['attr_name'] === '规格' && $attribute['attr_values'] === '普通',
        'NHECR_CONTEXT_RUN_PRODUCT_SKU_ATTRIBUTE_AND_ACTUAL_SELF_NATIVE_RELATIONS');
    nhecr_expect($category['type'] === 0 && $category['owner_id'] === 0 && $category['pid'] === 0
        && $label['label_cate'] === $category['id'] && $product['label_id'] === (string)$label['id'],
        'NHECR_CONTEXT_ORIGINAL_RUN_CATEGORY_PURCHASE_LABEL_AND_PRODUCT_RELATION');
    nhecr_expect($address['uid'] === $uid && $address['real_name'] === '隔离普通顾客' && $address['phone'] === '00000000000'
        && $address['province'] === '验证省' && $address['city'] === '验证市' && $address['district'] === '验证区'
        && $address['detail'] === '专用旧历史地址' && $address['is_default'] === 1
        && $admin['real_name'] === '隔离平台管理员', 'NHECR_CONTEXT_ORIGINAL_NATIVE_NORMAL_ADDRESS_AND_ADMIN');
    nhecr_expect($express['code'] === 'shunfeng' && $express['name'] === '顺丰速运'
        && $merchant['mer_id'] === $self && $merchant['review_status'] === 1 && $merchant['accept_orders'] === 1
        && $merchant['closed_at'] === null && $merchant['security_locked'] === 0,
        'NHECR_CONTEXT_REAL_ORIGINAL_COURIER_AND_CURRENT_ACCEPTED_SELF_STATE');
    $template = m4lh_actor_expected_row($database['database']['tables']['eb_shipping_templates']['metadata'],
        ['id' => 1, 'name' => '默认模版', 'type' => 1, 'appoint' => 0, 'no_delivery' => 0, 'sort' => 0, 'add_time' => 1658394592]);
    nhecr_expect($database['rows']['eb_shipping_templates'] === [$template]
        && in_array($database['database']['tables']['eb_shipping_templates']['metadata']['table']['AUTO_INCREMENT'], [2, '2'], true)
        && $product['temp_id'] === 1, 'NHECR_CONTEXT_COMPLETE_UNCHANGED_ORIGINAL_INSTALL_SHIPPING_TEMPLATE_AND_COUNTER');
    $fixture = ['schema' => 1, 'scope' => 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT', 'run_id' => $run,
        'legacy_source_sha' => $state['authority']['legacy_source_sha'], 'self_operated_mer_id' => $self,
        'uid' => $uid, 'admin_id' => $adminId, 'address_id' => $address['id'], 'product_id' => $productId,
        'attr_id' => $attribute['id'], 'sku_id' => $sku['id'], 'shipping_template_id' => 1, 'express_id' => $express['id'],
        'purchase_label_id' => $label['id'], 'category_id' => $category['id'], 'sku_unique' => $unique,
        'sku_suk' => $sku['suk'], 'express_code' => $express['code'], 'express_name' => $express['name'], 'fixture_time' => $time,
        'price' => '20.15', 'cost' => '7.45', 'initial_stock' => 10, 'qty_per_order' => 1,
        'public_image_path' => '/statics/system_images/spread_level_1.png',
        'old_public_image_file' => 'crmeb/public/statics/system_images/spread_level_1.png',
        'manual_delivery' => ['type' => 1, 'express_record_type' => 1, 'delivery_code' => 'shunfeng', 'delivery_name' => '顺丰速运']];
    // The complete native oracle independently selects orders/items/refund/review;
    // only its final counterpart join reads the already-qualified exact13 HTTP IDs.
    $identities = nhecr_identities($database, $fixture, $state['handoff']['seal']['http']);
    nhecr_expect($identities === $expectedIdentities, 'NHECR_CONTEXT_INDEPENDENT_COMPLETE_NATIVE_IDENTITIES_EQUAL_ORIGINAL_SEAL');
    nhecr_fresh($state); unset($private, $user, $admin, $database, $cache); return $fixture;
}

function nhecr_identities(#[SensitiveParameter] array $expectedDB2, #[SensitiveParameter] array $fixture, array $httpSummary): array
{
    foreach (['nhep_expect', 'nhe_keys', 'nhecr_db_validate', 'nhepcd_one', 'nhepcd_id',
        'nhepcv_native_row', 'm4lh_actor_native', 'm4lh_actor_expected_row', 'bccomp',
        'json_decode', 'json_encode', 'hash', 'md5'] as $dependency)
        if (!function_exists($dependency)) throw new RuntimeException('NHE_HISTORY_IDENTITIES_PURE_DEPENDENCY_REQUIRED');
    nhecr_db_validate($expectedDB2);
    nhe_keys($fixture, ['schema', 'scope', 'run_id', 'legacy_source_sha', 'self_operated_mer_id',
        'uid', 'admin_id', 'address_id', 'product_id', 'attr_id', 'sku_id', 'shipping_template_id',
        'express_id', 'purchase_label_id', 'category_id', 'sku_unique', 'sku_suk', 'express_code', 'express_name',
        'fixture_time', 'price', 'cost', 'initial_stock', 'qty_per_order', 'public_image_path', 'old_public_image_file', 'manual_delivery'],
        'NHE_HISTORY_IDENTITIES_DISTINCT_PRIVATE166_CONTEXT_NOT_AN_ORIGINAL_RECEIPT');
    $run = $fixture['run_id'];
    nhep_expect($fixture['schema'] === 1 && $fixture['scope'] === 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT'
        && is_string($run) && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $run) === 1
        && $fixture['legacy_source_sha'] === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
        && $fixture['shipping_template_id'] === 1 && $fixture['price'] === '20.15' && $fixture['cost'] === '7.45'
        && $fixture['initial_stock'] === 10 && $fixture['qty_per_order'] === 1
        && $fixture['public_image_path'] === '/statics/system_images/spread_level_1.png'
        && $fixture['old_public_image_file'] === 'crmeb/public/statics/system_images/spread_level_1.png',
        'NHE_HISTORY_IDENTITIES_SAME_VALIDATED_PRIVATE166_NATIVE_CONTEXT_SCOPE');
    foreach (['self_operated_mer_id', 'uid', 'admin_id', 'address_id', 'product_id', 'attr_id', 'sku_id',
        'shipping_template_id', 'express_id', 'purchase_label_id', 'category_id'] as $key)
        nhep_expect(is_int($fixture[$key]) && nhepcd_id($fixture[$key]) === $fixture[$key],
            'NHE_HISTORY_IDENTITIES_NATIVE_ORIGINAL_FIXTURE_IDS');
    $uid = nhepcd_id($fixture['uid'], 2147483647);
    $pid = nhepcd_id($fixture['product_id'], 2147483647);
    nhep_expect(is_int($fixture['fixture_time']) && $fixture['fixture_time'] > 0
        && $fixture['fixture_time'] <= 2147483647
        && $fixture['sku_unique'] === substr(hash('sha256', 'legacy-sku-' . $run), 0, 8)
        && $fixture['sku_suk'] === '普通' && $fixture['express_code'] === 'shunfeng'
        && $fixture['express_name'] === '顺丰速运'
        && $fixture['manual_delivery'] === ['type' => 1, 'express_record_type' => 1,
            'delivery_code' => 'shunfeng', 'delivery_name' => '顺丰速运'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_RUN_SKU_EXPRESS_AND_FIXTURE_TIME');
    // Historical insert/reset/no-login flags are not recreated from this current snapshot.
    nhep_expect(count($expectedDB2['rows']['audit_sentinel']) === 1
        && ($expectedDB2['rows']['audit_sentinel'][0]['run_id'] ?? null) === $run,
        'NHE_HISTORY_IDENTITIES_SAME_ORIGINAL_RUN_SENTINEL');

    $buyer = nhepcd_one($expectedDB2, 'eb_user', ['uid' => $uid]);
    $user = nhepcv_native_row($expectedDB2, 'eb_user', $buyer['index']);
    nhep_expect(count($buyer['metadata']['columns']) === 55 && $user === $buyer['row']
        && $user['nickname'] === '旧历史普通顾客' && $user['user_type'] === 'h5'
        && $user['status'] === 1 && $user['is_del'] === 0 && $user['is_money_level'] === 0
        && $user['spread_uid'] === 0 && $user['level'] === 0 && $user['integral'] === 0
        && $user['brokerage_price'] === '0.00' && $user['now_money'] === '79.85' && $user['pay_count'] === 2
        && $user['add_time'] === $fixture['fixture_time'] && is_int($user['last_time'])
        && $user['last_time'] >= $user['add_time'] && is_string($user['avatar']),
        'NHE_HISTORY_IDENTITIES_FULL_NATIVE_ORIGINAL_BUYER_AND_ACCEPTED_FINAL_BALANCE');
    $admin = nhepcd_one($expectedDB2, 'eb_system_admin', ['id' => $fixture['admin_id']]);
    $adminRow = nhepcv_native_row($expectedDB2, 'eb_system_admin', $admin['index']);
    nhep_expect($adminRow['level'] === 0 && $adminRow['roles'] === '' && $adminRow['status'] === 1
        && $adminRow['is_del'] === 0 && $adminRow['add_time'] === $fixture['fixture_time'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_PLATFORM_ADMIN');
    $address = nhepcd_one($expectedDB2, 'eb_user_address', ['id' => $fixture['address_id'], 'uid' => $uid]);
    $addressRow = nhepcv_native_row($expectedDB2, 'eb_user_address', $address['index']);
    nhep_expect($addressRow['is_default'] === 1 && $addressRow['add_time'] === $fixture['fixture_time'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_FIXTURE_ADDRESS');
    $productSelection = nhepcd_one($expectedDB2, 'eb_store_product', ['store_name' => '旧历史商品-' . $run]);
    $product = nhepcv_native_row($expectedDB2, 'eb_store_product', $productSelection['index']);
    nhep_expect($product['id'] === $pid && $product['mer_id'] === $fixture['self_operated_mer_id']
        && $product['price'] === '20.15' && $product['cost'] === '7.45' && $product['postage'] === '0.00'
        && $product['is_del'] === 0 && $product['is_show'] === 1 && $product['review_status'] === 2
        && $product['spec_type'] === 1 && $product['temp_id'] === 1 && $product['is_postage'] === 1
        && $product['add_time'] === $fixture['fixture_time'] && $product['image'] === $fixture['public_image_path'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_RUN_CATALOG_FIXTURE');
    $skuSelection = nhepcd_one($expectedDB2, 'eb_store_product_attr_value', ['unique' => $fixture['sku_unique'], 'type' => 0]);
    $sku = nhepcv_native_row($expectedDB2, 'eb_store_product_attr_value', $skuSelection['index']);
    nhep_expect($sku['id'] === $fixture['sku_id'] && $sku['product_id'] === $pid
        && $sku['suk'] === $fixture['sku_suk'] && $sku['price'] === '20.15' && $sku['cost'] === '7.45'
        && $sku['image'] === $fixture['public_image_path'], 'NHE_HISTORY_IDENTITIES_ORIGINAL_GLOBAL_SKU');
    $attribute = nhepcd_one($expectedDB2, 'eb_store_product_attr', ['product_id' => $pid, 'type' => 0]);
    $attributeRow = nhepcv_native_row($expectedDB2, 'eb_store_product_attr', $attribute['index']);
    nhep_expect($attributeRow['id'] === $fixture['attr_id'] && $attributeRow['attr_name'] === '规格'
        && $attributeRow['attr_values'] === '普通', 'NHE_HISTORY_IDENTITIES_ORIGINAL_ATTRIBUTE');
    $express = nhepcd_one($expectedDB2, 'eb_express', ['code' => $fixture['express_code']]);
    $expressRow = nhepcv_native_row($expectedDB2, 'eb_express', $express['index']);
    nhep_expect($expressRow['id'] === $fixture['express_id'] && $expressRow['name'] === $fixture['express_name'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_EXPRESS_IDENTITY');

    $roots = []; $items = []; $savedCarts = []; $rootIdentities = []; $historyTimes = [];
    foreach (['refund_family', 'review_family'] as $family) {
        $refunded = $family === 'refund_family';
        $selection = nhepcd_one($expectedDB2, 'eb_store_order', ['mark' => $run . '-' . $family]);
        $root = nhepcv_native_row($expectedDB2, 'eb_store_order', $selection['index']);
        $oid = nhepcd_id($root['id'], 2147483647);
        nhep_expect(count($selection['metadata']['columns']) === 90 && $root === $selection['row']
            && is_string($root['order_id']) && preg_match('/\Acp([1-9][0-9]{0,18})\z/D', $root['order_id'], $cp) === 1
            && bccomp($cp[1], '9223372036854775807', 0) <= 0
            && is_string($root['unique']) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $root['unique']) === 1
            && bccomp($root['unique'], '9223372036854775807', 0) <= 0
            && is_int($root['add_time']) && $root['add_time'] >= $fixture['fixture_time']
            && is_int($root['pay_time']) && $root['pay_time'] >= $root['add_time'] && $root['pay_time'] <= 2147483647,
            'NHE_HISTORY_IDENTITIES_FULL_ORIGINAL_ROOT_CP_CONFIRM_KEY_AND_NATIVE_TIMES');
        $itemSelection = nhepcd_one($expectedDB2, 'eb_store_order_cart_info', ['oid' => $oid]);
        $item = nhepcv_native_row($expectedDB2, 'eb_store_order_cart_info', $itemSelection['index']);
        $itemId = nhepcd_id($item['id'], 2147483647); $cartId = nhepcd_id($item['cart_id']);
        nhep_expect(array_keys($itemSelection['metadata']['columns']) === ['id', 'oid', 'uid', 'cart_id',
            'product_id', 'old_cart_id', 'cart_num', 'refund_num', 'surplus_num', 'split_status', 'cart_info', 'unique', 'mer_id']
            && $item === m4lh_actor_expected_row($itemSelection['metadata'], ['id' => $itemId, 'oid' => $oid,
                'uid' => $uid, 'cart_id' => (string)$cartId, 'product_id' => $pid, 'cart_num' => 1,
                'refund_num' => ($refunded ? 1 : 0), 'surplus_num' => 1, 'cart_info' => $item['cart_info'],
                'unique' => md5($cartId . '' . $oid)])
            && is_string($item['cart_info']) && $root['cart_id'] === json_encode([$cartId]),
            'NHE_HISTORY_IDENTITIES_COMPLETE_TWELVE_FIELD_ITEM_AND_SAVED_CART_ID_NOT_ITEM_PK');
        $rootValues = ['id' => $oid, 'uid' => $uid, 'order_id' => $root['order_id'],
            'real_name' => $addressRow['real_name'], 'user_phone' => $addressRow['phone'],
            'user_address' => $addressRow['province'] . ' ' . $addressRow['city'] . ' '
                . $addressRow['district'] . ' ' . $addressRow['detail'],
            'cart_id' => json_encode([$cartId]), 'total_num' => 1, 'total_price' => '20.15',
            'total_postage' => '0.00', 'coupon_id' => 0, 'coupon_price' => '0.00', 'pay_price' => '20.15',
            'pay_postage' => '0.00', 'deduction_price' => '0.00', 'gift_price' => '0.00',
            'paid' => 1, 'pay_type' => 'yue', 'pay_time' => $root['pay_time'], 'is_channel' => 2,
            'use_integral' => '0.00', 'gain_integral' => '0.00', 'mark' => $run . '-' . $family,
            'combination_id' => 0, 'pink_id' => 0, 'seckill_id' => 0, 'bargain_id' => 0, 'advance_id' => 0,
            'cost' => '7.45', 'unique' => $root['unique'], 'shipping_type' => 1, 'channel_type' => 'h5',
            'province' => '', 'spread_uid' => 0, 'spread_two_uid' => 0, 'virtual_type' => 0,
            'is_gift' => 0, 'gift_mark' => '', 'pay_uid' => $uid, 'custom_form' => '[]',
            'division_id' => 0, 'agent_id' => 0, 'staff_id' => 0, 'staff_brokerage' => '0.00',
            'agent_brokerage' => '0.00', 'division_brokerage' => '0.00', 'add_time' => $root['add_time'],
            'status' => ($refunded ? -2 : 3), 'refund_status' => ($refunded ? 2 : 0),
            'refund_type' => ($refunded ? 6 : 0), 'refund_price' => ($refunded ? '20.15' : '0.00')];
        if ($refunded) $rootValues['refund_reason_wap'] = 'normal-refund-' . $run;
        else $rootValues += ['delivery_type' => 'express', 'delivery_name' => $fixture['express_name'],
            'delivery_code' => $fixture['express_code'], 'delivery_id' => 'NHE' . $run, 'fictitious_content' => ''];
        nhep_expect($root === m4lh_actor_expected_row($selection['metadata'], $rootValues),
            'NHE_HISTORY_IDENTITIES_COMPLETE_EIGHTY_SEVEN_NATIVE_ROOT_FIELDS_AND_NORMAL_FLAGS');
        $visible = []; $keyMatches = [];
        foreach ($expectedDB2['rows']['eb_store_order'] as $index => $unused) {
            $candidate = nhepcv_native_row($expectedDB2, 'eb_store_order', $index);
            foreach (['order_id', 'unique'] as $column) nhep_expect(is_string($candidate[$column])
                && preg_match('/\A[A-Za-z0-9]*\z/D', $candidate[$column]) === 1,
                'NHE_HISTORY_IDENTITIES_CANONICAL_ORIGINAL_SQL_OR_LOOKUP_KEYS');
            if (strtolower($candidate['unique']) === $root['unique']) $keyMatches[] = $index;
            if ($candidate['is_del'] === 0 && ($candidate['uid'] === $uid || $candidate['gift_uid'] === $uid)
                && (strtolower($candidate['order_id']) === $root['order_id']
                    || strtolower($candidate['unique']) === $root['order_id'])) $visible[] = $index;
            nhep_expect($candidate['pid'] !== $oid, 'NHE_HISTORY_IDENTITIES_NO_SPLIT_CHILD_OF_EITHER_ORIGINAL_ROOT');
        }
        nhep_expect($visible === [$selection['index']] && $keyMatches === [$selection['index']]
            && nhepcd_one($expectedDB2, 'eb_store_order', ['id' => $oid]) === $selection,
            'NHE_HISTORY_IDENTITIES_ORIGINAL_DAO_OR_LOOKUP_AND_ROOT_PK_ARE_UNAMBIGUOUS');
        $uniqueMatches = []; $cartMatches = [];
        foreach ($expectedDB2['rows']['eb_store_order_cart_info'] as $index => $unused) {
            $candidate = nhepcv_native_row($expectedDB2, 'eb_store_order_cart_info', $index);
            nhep_expect(is_string($candidate['unique']) && preg_match('/\A[A-Za-z0-9]*\z/D', $candidate['unique']) === 1,
                'NHE_HISTORY_IDENTITIES_CANONICAL_GLOBAL_ITEM_UNIQUE');
            if (strtolower($candidate['unique']) === $item['unique']) $uniqueMatches[] = $index;
            if ($candidate['cart_id'] === $item['cart_id']) $cartMatches[] = $index;
        }
        nhep_expect($uniqueMatches === [$itemSelection['index']] && $cartMatches === [$itemSelection['index']],
            'NHE_HISTORY_IDENTITIES_GLOBAL_ITEM_UNIQUE_AND_ORIGINAL_CART_USED_ONCE');
        foreach ($expectedDB2['rows']['eb_store_cart'] as $index => $unused) {
            $candidate = nhepcv_native_row($expectedDB2, 'eb_store_cart', $index);
            nhep_expect($candidate['id'] !== $cartId, 'NHE_HISTORY_IDENTITIES_ORIGINAL_PERSISTENT_CART_CONSUMED');
        }
        $cart = json_decode($item['cart_info'], true);
        nhep_expect(json_last_error() === JSON_ERROR_NONE && is_array($cart) && !array_is_list($cart)
            && json_encode($cart) === $item['cart_info'], 'NHE_HISTORY_IDENTITIES_COMPLETE_ORIGINAL_DEFAULT_JSON_CART');
        $cartMetadata = $expectedDB2['database']['tables']['eb_store_cart']['metadata']; $baseCart = [];
        nhep_expect(count($cartMetadata['columns']) === 15
            && array_slice(array_keys($cart), 0, 15) === array_keys($cartMetadata['columns']),
            'NHE_HISTORY_IDENTITIES_FIFTEEN_ORIGINAL_CART_BASE_COLUMNS');
        foreach ($cartMetadata['columns'] as $column => $definition) {
            $baseCart[$column] = m4lh_actor_native($definition, $cart[$column]);
            nhep_expect($baseCart[$column] === $cart[$column], 'NHE_HISTORY_IDENTITIES_EVERY_SAVED_CART_NATIVE_TYPE');
        }
        nhep_expect(is_int($baseCart['add_time']) && $baseCart['add_time'] >= $fixture['fixture_time']
            && $baseCart['add_time'] <= $root['add_time']
            && $baseCart === m4lh_actor_expected_row($cartMetadata, ['id' => $cartId, 'uid' => $uid,
                'type' => '0', 'product_id' => $pid, 'product_attr_unique' => $fixture['sku_unique'],
                'cart_num' => 1, 'add_time' => $baseCart['add_time']])
            && is_array($cart['productInfo'] ?? null) && is_array($cart['attrInfo'] ?? null)
            && ($cart['productInfo']['attrInfo'] ?? null) === $cart['attrInfo'],
            'NHE_HISTORY_IDENTITIES_ORIGINAL_OWNED_CART_AND_TWO_SAVED_SKU_RELATIONS');
        $historicalSku = $sku; $historicalProduct = $product;
        $historicalSku['stock'] = $refunded ? 10 : 9; $historicalSku['sales'] = $refunded ? 0 : 1;
        $historicalProduct['stock'] = $historicalSku['stock']; $historicalProduct['sales'] = $historicalSku['sales'];
        $slides = json_decode($product['slider_image'], true);
        nhep_expect(json_last_error() === JSON_ERROR_NONE && $slides === [$fixture['public_image_path']],
            'NHE_HISTORY_IDENTITIES_ORIGINAL_PRODUCT_SLIDER_GETTER');
        $historicalProduct['slider_image'] = $slides; $historicalProduct['express_delivery'] = true;
        $historicalProduct['store_mention'] = false; $historicalProduct['attrInfo'] = $historicalSku;
        nhep_expect($cart['attrInfo'] === $historicalSku && $cart['productInfo'] === $historicalProduct,
            'NHE_HISTORY_IDENTITIES_COMPLETE_SAVED_PRODUCT_SKU_WITH_CREATE_TIME_INVENTORY_NOT_CURRENT_STOCK');
        $expectedCart = $baseCart;
        $expectedCart['productInfo'] = $historicalProduct; $expectedCart['attrInfo'] = $historicalSku;
        $expectedCart['min_qty'] = 1; $expectedCart['attrStatus'] = true; $expectedCart['vip_truePrice'] = 0;
        $expectedCart['costPrice'] = '7.45'; $expectedCart['trueStock'] = $historicalSku['stock'];
        $expectedCart['truePrice'] = 20.15; $expectedCart['sum_price'] = '20.15';
        $expectedCart['price_type'] = 'level'; $expectedCart['is_valid'] = 1; $expectedCart['postage_price'] = 0;
        $expectedCart['use_integral'] = 0; $expectedCart['integral_price'] = 0; $expectedCart['coupon_price'] = 0;
        $expectedCart['sum_true_price'] = '20.15'; $expectedCart['one_brokerage'] = '0'; $expectedCart['two_brokerage'] = '0';
        $expectedCart['staff_brokerage'] = '0.00'; $expectedCart['agent_brokerage'] = '0.00';
        $expectedCart['division_brokerage'] = '0.00';
        nhep_expect($expectedCart === $cart, 'NHE_HISTORY_IDENTITIES_ALL_SAVED_CART_KEYS_TYPES_PRICES_AND_ORDER');

        $messages = ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功'];
        $messages += $refunded ? ['integral_back' => '商品退积分', 'refund_price' => '退款给用户：20.15元']
            : ['delivery_goods' => '已发货 快递公司：顺丰速运 快递单号：NHE' . $run,
                'take_delivery' => '已收货', 'check_order_over' => '用户评价'];
        $statusTable = $expectedDB2['database']['tables']['eb_store_order_status'];
        nhep_expect(array_keys($statusTable['metadata']['columns']) === ['oid', 'change_type', 'change_message', 'change_time']
            && $statusTable['metadata']['table']['AUTO_INCREMENT'] === null,
            'NHE_HISTORY_IDENTITIES_ORIGINAL_FOUR_FIELD_STATUS_WITHOUT_AI');
        $logs = [];
        foreach ($expectedDB2['rows']['eb_store_order_status'] as $index => $unused) {
            $status = nhepcv_native_row($expectedDB2, 'eb_store_order_status', $index);
            if ($status['oid'] !== $oid) continue;
            nhep_expect(is_string($status['change_type']) && isset($messages[$status['change_type']])
                && !array_key_exists($status['change_type'], $logs) && is_int($status['change_time'])
                && $status['change_time'] >= $root['add_time'] && $status['change_time'] <= 2147483647
                && $status === m4lh_actor_expected_row($statusTable['metadata'], ['oid' => $oid,
                    'change_type' => $status['change_type'], 'change_message' => $messages[$status['change_type']],
                    'change_time' => $status['change_time']]), 'NHE_HISTORY_IDENTITIES_EACH_COMPLETE_ORIGINAL_STATUS_ROW');
            $logs[$status['change_type']] = $status['change_time'];
        }
        nhe_keys($logs, array_keys($messages), 'NHE_HISTORY_IDENTITIES_EXACT_REFUND_FOUR_OR_COMPLETED_FIVE_HISTORY_LOGS');
        nhep_expect($logs['cache_key_create_order'] <= $logs['pay_success']
            && $logs['pay_success'] >= $root['pay_time'], 'NHE_HISTORY_IDENTITIES_NATIVE_CREATE_PAY_LOG_ORDER');
        $roots[$family] = $root; $items[$family] = $item; $savedCarts[$family] = $cart; $historyTimes[$family] = $logs;
        $rootIdentities[$family] = ['id' => $oid, 'order_no' => $root['order_id'],
            'order_unique' => $root['unique'], 'item_unique' => $item['unique'], 'cart_id' => $cartId,
            'paid' => $root['paid'], 'status' => $root['status'], 'refund_status' => $root['refund_status']];
    }
    foreach (['id', 'order_no', 'order_unique', 'item_unique', 'cart_id'] as $key)
        nhep_expect($rootIdentities['refund_family'][$key] !== $rootIdentities['review_family'][$key],
            'NHE_HISTORY_IDENTITIES_TWO_DISTINCT_ORIGINAL_FAMILIES');
    $refundRoot = $roots['refund_family']; $reviewRoot = $roots['review_family'];
    nhep_expect($refundRoot['pay_time'] <= $roots['review_family']['add_time'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_REFUND_FAMILY_PAID_BEFORE_SECOND_CREATE');

    $refund = nhepcd_one($expectedDB2, 'eb_store_order_refund', ['store_order_id' => $refundRoot['id'], 'uid' => $uid]);
    $refundRow = nhepcv_native_row($expectedDB2, 'eb_store_order_refund', $refund['index']);
    nhep_expect(array_keys($refund['metadata']['columns']) === ['id', 'store_order_id', 'store_id', 'order_id',
        'uid', 'refund_type', 'refund_num', 'refund_price', 'refunded_price', 'refund_phone', 'refund_express',
        'refund_express_name', 'refund_explain', 'refund_img', 'refund_reason', 'refuse_reason', 'remark',
        'refunded_time', 'cart_info', 'is_cancel', 'is_pink_cancel', 'is_del', 'add_time', 'is_system_del', 'mer_id', 'marketplace_refund_mode'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_COMPLETE_TWENTY_FOUR_REFUND_COLUMNS');
    $rid = nhepcd_id($refundRow['id'], 2147483647); $refundNumber = $refundRow['order_id'];
    nhep_expect(is_string($refundNumber) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $refundNumber) === 1
        && bccomp($refundNumber, '9223372036854775807', 0) <= 0
        && is_int($refundRow['add_time']) && $refundRow['add_time'] >= $reviewRoot['pay_time']
        && is_int($refundRow['refunded_time']) && $refundRow['refunded_time'] >= $refundRow['add_time']
        && $refundRow['refunded_time'] <= 2147483647
        && $refundRow === m4lh_actor_expected_row($refund['metadata'], ['id' => $rid,
            'store_order_id' => $refundRoot['id'], 'store_id' => 0, 'order_id' => $refundNumber, 'uid' => $uid,
            'refund_type' => 6, 'refund_num' => 1, 'refund_price' => '20.15', 'refunded_price' => '20.15',
            'refund_img' => '[]', 'refund_reason' => 'normal-refund-' . $run,
            'refunded_time' => $refundRow['refunded_time'], 'cart_info' => json_encode([$savedCarts['refund_family']]),
            'add_time' => $refundRow['add_time'], 'is_pink_cancel' => 0]),
        'NHE_HISTORY_IDENTITIES_COMPLETE_ORIGINAL_COMPLETED_REFUND_FROM_EXPECTED_ROOT_AND_SAVED_CART');
    $refundNumbers = [];
    foreach ($expectedDB2['rows']['eb_store_order_refund'] as $index => $unused) {
        $candidate = nhepcv_native_row($expectedDB2, 'eb_store_order_refund', $index); $number = $candidate['order_id'];
        nhep_expect(is_string($number) && preg_match('/\A[\x20-\x7e]*\z/D', $number) === 1
            && (strtolower(rtrim($number, ' ')) !== $refundNumber || $number === $refundNumber),
            'NHE_HISTORY_IDENTITIES_NO_SQL_EQUIVALENT_REFUND_NUMBER_ALIAS');
        if ($number === $refundNumber) $refundNumbers[] = $index;
    }
    nhep_expect($refundNumbers === [$refund['index']], 'NHE_HISTORY_IDENTITIES_GLOBAL_ORIGINAL_REFUND_NUMBER_UNIQUE');

    $replyMatches = [];
    foreach ($expectedDB2['rows']['eb_store_product_reply'] as $index => $unused) {
        $candidate = nhepcv_native_row($expectedDB2, 'eb_store_product_reply', $index);
        nhep_expect(is_string($candidate['unique']) && preg_match('/\A[A-Za-z0-9]*\z/D', $candidate['unique']) === 1,
            'NHE_HISTORY_IDENTITIES_CANONICAL_GLOBAL_REPLY_UNIQUE_SELECTOR');
        if (strtolower($candidate['unique']) === $items['review_family']['unique'])
            $replyMatches[] = ['index' => $index, 'row' => $candidate];
    }
    nhep_expect(count($replyMatches) === 1, 'NHE_HISTORY_IDENTITIES_SELECT_REPLY_BY_GLOBAL_ITEM_UNIQUE_BEFORE_ANY_OWNER_FILTER');
    $reply = $replyMatches[0]['row']; $replyMetadata = $expectedDB2['database']['tables']['eb_store_product_reply']['metadata'];
    $replyId = nhepcd_id($reply['id'], 2147483647);
    nhep_expect(array_keys($replyMetadata['columns']) === ['id', 'uid', 'oid', 'unique', 'product_id',
        'reply_type', 'product_score', 'service_score', 'comment', 'pics', 'add_time',
        'merchant_reply_content', 'merchant_reply_time', 'is_del', 'is_reply', 'nickname', 'avatar', 'suk', 'status', 'mer_id']
        && is_int($reply['add_time']) && $reply['add_time'] > 0 && $reply['add_time'] <= 2147483647
        && is_int($reply['merchant_reply_time']) && $reply['merchant_reply_time'] >= $reply['add_time']
        && $reply['merchant_reply_time'] <= 2147483647
        && $reply === m4lh_actor_expected_row($replyMetadata, ['id' => $replyId, 'uid' => $uid,
            'oid' => $reviewRoot['id'], 'unique' => $items['review_family']['unique'], 'product_id' => $pid,
            'reply_type' => 'product', 'product_score' => 5, 'service_score' => 5,
            'comment' => 'normal-history-' . $run, 'pics' => '', 'add_time' => $reply['add_time'],
            'merchant_reply_content' => 'normal-reply-' . $run, 'merchant_reply_time' => $reply['merchant_reply_time'],
            'is_del' => 0, 'is_reply' => 1, 'nickname' => $user['nickname'], 'avatar' => $user['avatar'],
            'suk' => $fixture['sku_suk'], 'status' => 1]),
        'NHE_HISTORY_IDENTITIES_COMPLETE_NINETEEN_NATIVE_APPROVED_REPLIED_ORIGINAL_PURCHASE');
    $logs = $historyTimes['review_family'];
    nhep_expect($logs['pay_success'] <= $logs['delivery_goods']
        && $logs['delivery_goods'] >= $reviewRoot['pay_time']
        && $logs['delivery_goods'] <= $logs['take_delivery']
        && $logs['take_delivery'] <= $reply['add_time'] && $reply['add_time'] <= $logs['check_order_over']
        && $user['last_time'] >= $reply['merchant_reply_time'] && $user['last_time'] >= $logs['check_order_over']
        && $user['last_time'] >= $refundRow['refunded_time'],
        'NHE_HISTORY_IDENTITIES_FIVE_INDEPENDENT_REVIEW_HISTORY_TIMES_AND_FINAL_CUSTOMER_READ');

    $identities = ['refund_family' => $rootIdentities['refund_family'], 'review_family' => $rootIdentities['review_family'],
        'refund' => ['id' => $rid, 'order_no' => $refundNumber, 'root_id' => $refundRoot['id'], 'refund_type' => $refundRow['refund_type']],
        'review' => ['id' => $replyId, 'root_id' => $reply['oid'], 'status' => $reply['status'], 'is_reply' => $reply['is_reply']]];
    // HTTP IDs never select the expected rows above. This is the final counterpart-only join.
    nhe_keys($httpSummary, ['schema', 'scope', 'run_id', 'legacy_source_sha', 'buyer_uid', 'admin_id',
        'product_id', 'sku_id', 'refund_family', 'review_family', 'refund', 'review', 'http_steps',
        'business_acceptance', 'native_children_close_and_independent_zero'],
        'NHE_HISTORY_IDENTITIES_ORIGINAL_COMPLETE_HTTP_SUMMARY');
    nhep_expect($httpSummary['schema'] === 1 && $httpSummary['scope'] === 'legacy_http_ids_and_observed_states_only'
        && $httpSummary['run_id'] === $run && $httpSummary['legacy_source_sha'] === $fixture['legacy_source_sha']
        && $httpSummary['buyer_uid'] === $uid && $httpSummary['admin_id'] === $fixture['admin_id']
        && $httpSummary['product_id'] === $pid && $httpSummary['sku_id'] === $fixture['sku_id']
        && $httpSummary['business_acceptance'] === 'NOT SIGNED'
        && $httpSummary['native_children_close_and_independent_zero'] === 'REQUIRED_BY_OUTER_PRODUCER',
        'NHE_HISTORY_IDENTITIES_HTTP_SAME_RUN_FIXTURE_AND_ORIGINAL_SCOPE_ONLY');
    foreach ($identities as $key => $identity)
        nhep_expect($httpSummary[$key] === $identity, 'NHE_HISTORY_IDENTITIES_INDEPENDENT_EXPECTED_IDENTITY_EQUALS_HTTP_COUNTERPART');
    $steps = ['admin_login', 'admin_identity', 'platform_self_merchant_review', 'platform_self_merchant_accept_orders',
        'customer_login', 'customer_identity', 'platform_system_balance_add', 'funded_balance_read',
        'refund_family_cart', 'refund_family_confirm', 'refund_family_create', 'refund_family_unpaid_read',
        'refund_family_pay', 'refund_family_paid_read', 'review_family_cart', 'review_family_confirm',
        'review_family_create', 'review_family_unpaid_read', 'review_family_pay', 'review_family_paid_read',
        'two_paid_balance_read', 'unshipped_refund_apply', 'owned_refund_identity', 'platform_refund_approve',
        'owned_refund_completed_read', 'owned_refunded_root_read', 'refunded_balance_read',
        'platform_manual_courier', 'owned_shipped_read', 'customer_receipt', 'owned_received_read',
        'customer_purchased_item_comment', 'platform_pending_review_identity', 'platform_review_approve',
        'platform_review_reply', 'platform_approved_replied_read', 'owned_commented_completed_read'];
    nhep_expect(is_array($httpSummary['http_steps']) && array_is_list($httpSummary['http_steps'])
        && count($httpSummary['http_steps']) === 37, 'NHE_HISTORY_IDENTITIES_COMPLETE_THIRTY_SEVEN_HTTP_RECEIPTS');
    foreach ($steps as $index => $step)
        nhep_expect($httpSummary['http_steps'][$index] === ['step' => $step, 'http' => 200],
            'NHE_HISTORY_IDENTITIES_EACH_REAL_ORIGINAL_STEP_IN_EXACT_ORDER');
    return $identities;
}

/** Real current LoginServices normal branch, selected by BEFORE uid/account, never response IDs. */
function nhecr_login_context(array $before, array $intent, #[SensitiveParameter] array $request, array $fixture): array
{
    nhecr_db_validate($before); nhecr_intent($intent); nhecr_keys($request, ['token', 'body', 'actor_identity']);
    nhecr_expect($intent['sequence'] === 1 && $request['token'] === null && is_array($request['body'])
        && $intent['actor_id'] === $fixture['uid'] && $request['actor_identity']['id'] === $fixture['uid'], 'NHECR_TRUE_NEW_SAME_ACTOR_LOGIN');
    nhecr_keys($request['body'], ['account', 'password', 'spread', 'agent_id']);
    $owner = nhepcd_one($before, 'eb_user', ['uid' => $fixture['uid']]); $row = nhepcv_native_row($before, 'eb_user', $owner['index']);
    nhecr_expect($row['account'] === $request['body']['account'] && $row['account'] === $request['actor_identity']['account']
        && $row['pwd'] === md5($request['body']['password']) && $row['pwd'] !== md5('123456')
        && $row['status'] === 1 && $row['is_del'] === 0 && $row['spread_uid'] === 0
        && $row['user_type'] === 'h5' && $row['now_money'] === '79.85' && $row['pay_count'] === 2
        && $request['body']['spread'] === 0 && $request['body']['agent_id'] === 0
        && is_string($row['phone']) && $row['phone'] !== '', 'NHECR_REAL_ORIGINAL_RESET_CREDENTIALS_NORMAL_LOGIN_BRANCH');
    $matches = [];
    foreach ($before['rows']['eb_user'] as $index => $unused) {
        $candidate = nhepcv_native_row($before, 'eb_user', $index);
        foreach (['account', 'phone'] as $key) nhecr_expect(is_string($candidate[$key])
            && preg_match('/\A[\x20-\x7e]*\z/D', $candidate[$key]) === 1
            && (strtolower(rtrim($candidate[$key], ' ')) !== $row['account'] || $candidate[$key] === $row['account']),
            'NHECR_REAL_LOGIN_ACCOUNT_OR_PHONE_SQL_COLLATION_NO_ALIAS');
        if ($candidate['is_del'] === 0 && ($candidate['account'] === $row['account'] || $candidate['phone'] === $row['account'])) $matches[] = $index;
    }
    nhecr_expect($matches === [$owner['index']], 'NHECR_BEFORE_ACCOUNT_OR_PHONE_ONE_ORIGINAL_LOGIN_OWNER');
    nhep_auth_db_login_events($before, 'user_login', true);
    foreach ($before['rows']['eb_system_notification'] as $notification)
        foreach (['is_system', 'is_sms', 'is_wechat', 'is_routine', 'is_ent_wechat', 'is_app'] as $channel)
            nhecr_expect($notification[$channel] === 0, 'NHECR_ALL_REAL_LOGIN_NOTICE_CHANNELS_DISABLED_BEFORE');
    foreach (['eb_lang_code', 'eb_lang_country', 'eb_lang_type'] as $table) nhecr_expect($before['rows'][$table] === [], 'NHECR_LOGIN_EMPTY_LANGUAGE_TABLES');
    nhecr_expect(nhepcr_config_wire($before, 'station_open', 1) === '1', 'NHECR_REAL_CURRENT_LOGIN_STATION_OPEN');
    foreach (['param_filter_type', 'param_filter_data', 'store_brokerage_binding_status', 'brokerage_bindind', 'store_brokerage_binding_time'] as $name)
        nhecr_expect(nhepcr_config_wire($before, $name, null) === 'N;', 'NHECR_REAL_LOGIN_MISSING_CONFIG_NULL_CACHE_WIRE');
    return $owner;
}

/** Static User::update uses the complete original 27 options GUID, including setter-final data order. */
function nhecr_user_update_key(array $before, int $uid, array $data): string
{
    nhecr_db_validate($before); $columns = $before['database']['tables']['eb_user']['metadata']['columns'];
    nhecr_expect(array_keys($data) === ['last_time'] || array_keys($data) === ['phone', 'last_time', 'last_ip'],
        'NHECR_FINITE_REAL_STATIC_USER_UPDATE_DATA');
    foreach ($data as $key => $value) nhecr_expect(m4lh_actor_native($columns[$key], $value) === $value, 'NHECR_UPDATE_DATA_ORIGINAL_NATIVE_TYPES');
    $options = ['json' => [], 'json_assoc' => false, 'field_type' => [], 'where' => ['AND' => [['uid', '=', $uid]]],
        'strict' => false, 'cache' => [true, null, null], 'key' => null, 'field' => array_keys($columns), 'data' => $data,
        'table' => 'eb_user', 'order' => [], 'join' => [], 'union' => [], 'master' => true, 'lock' => false,
        'fetch_sql' => false, 'array' => false, 'distinct' => false, 'procedure' => false, 'group' => '', 'having' => '',
        'limit' => '', 'force' => '', 'comment' => '', 'partition' => '', 'duplicate' => '', 'extra' => ''];
    nhecr_expect(count($options) === 27, 'NHECR_ORIGINAL_USER_UPDATE_ALL_27_OPTIONS');
    return 'c:' . md5('audit_auth' . serialize(var_export($options, true)) . serialize([]));
}

/** Complete independent current auth/getter DB + Redis model for login and the three legacy detail GETs. */
function nhecr_account_detail_verify(array $beforeCapture, array $actualCapture, array $intent,
    #[SensitiveParameter] array $request, #[SensitiveParameter] array $response, #[SensitiveParameter] array $configuration): array
{
    nhecr_intent($intent); $before7 = $beforeCapture['snapshot']; $actual7 = $actualCapture['snapshot'];
    $before = ['database' => $before7['database'], 'rows' => $before7['rows']];
    $actual = ['database' => $actual7['database'], 'rows' => $actual7['rows']]; nhecr_db_validate($before); nhecr_db_validate($actual);
    $login = $intent['sequence'] === 1; $fixture = $beforeCapture['private']['fixture'];
    nhecr_expect(in_array($intent['sequence'], [1, 6, 7, 8], true), 'NHECR_ONLY_REAL_LOGIN_AND_THREE_CURRENT_DETAIL_STEPS');
    if ($login) $selection = nhecr_login_context($before, $intent, $request, $fixture);
    else {
        if ($intent['sequence'] === 8) { $context = nhecr_refund_context($before, $intent, $request); $value = $context['value']; }
        else { $context = nhecr_order_context($before, $intent, $request); $value = nhecr_order_value($before, $intent, $request); }
        $selection = nhepcd_one($before, 'eb_user', ['uid' => $fixture['uid']]);
    }
    $owner = $selection['row']; $index = $selection['index'];
    $actualUser = nhepcd_one($actual, 'eb_user', ['uid' => $owner['uid']]);
    $time = nhep_auth_db_time($selection['metadata'], 'last_time', $actualUser['row']['last_time'], $response['actual_interval'], $owner['last_time']);
    $data = $login ? ['phone' => $owner['phone'], 'last_time' => $time, 'last_ip' => '127.0.0.1'] : ['last_time' => $time];
    $expectedDB = nhecr_db_expected($before, ['eb_user' => nhep_auth_db_update($before, 'eb_user', $index, $data)]);
    nhecr_expect($expectedDB === $actual, 'NHECR_COMPLETE166_CURRENT_LOGIN_OR_AUTH_ONLY_NATIVE_USER_TIME_IP');
    nhecr_expect($response['http'] === 200 && $response['transport_errno'] === 0 && $response['transport_failure_class'] === null
        && is_string($response['raw']) && strlen($response['raw']) <= 6291456
        && json_decode($response['raw'], true, 128, JSON_THROW_ON_ERROR) === $response['json'], 'NHECR_COMPLETE_REAL_CURRENT_RESPONSE_RAW_JSON');
    $beforeRedis = ['snapshot' => $before7['redis'], 'native' => $beforeCapture['private']['redis_native']];
    $actualRedis = ['snapshot' => $actual7['redis'], 'native' => $actualCapture['private']['redis_native']];
    nhepar_capture($beforeRedis); nhepar_capture($actualRedis);
    nhecr_expect($configuration['run_id'] === $intent['run_id'] && $configuration['auth']['session_epoch'] === 'audit-http-' . $intent['run_id']
        && $configuration['auth']['issuer'] === 'tianli-mall', 'NHECR_SAME_ORIGINAL_PRIVATE_AUTH_CONFIGURATION');
    $changes = []; $members = [];
    nhepar_customer($beforeRedis, $actualRedis, $owner, $intent, $request, $response, $configuration, $changes);
    $key = nhecr_user_update_key($before, $owner['uid'], $data);
    nhecr_expect(nhepar_entry($actualRedis, $key) === null, 'NHECR_ACTUAL_STATIC_USER_CONDITION_GUID_REMOVED_NO_PK_SUBSTITUTION');
    $changes[$key] = null;
    if ($login) {
        $token = $response['json']['data']['token'] ?? null; $expiry = $response['json']['data']['expires_time'] ?? null;
        nhecr_expect(is_string($token) && is_int($expiry)
            && $response['json'] === ['status' => 200, 'msg' => '登录成功', 'data' => ['token' => $token, 'expires_time' => $expiry]],
            'NHECR_CURRENT_LOGIN_FULL_DTO_AFTER_INDEPENDENT_JWT_PROOF');
        $settings = ['station_open' => 1, 'param_filter_type' => null, 'param_filter_data' => null,
            'store_brokerage_binding_status' => null, 'brokerage_bindind' => null, 'store_brokerage_binding_time' => null];
    } elseif ($intent['sequence'] === 8) {
        nhecr_expect($response['json'] === ['status' => 200, 'msg' => 'success', 'data' => $value], 'NHECR_CURRENT_REFUND_COMPLETE_INDEPENDENT63_DTO');
        $settings = ['station_open' => 1, 'balance_func_status' => 1, 'yue_pay_status' => 1, 'pay_weixin_open' => 0, 'ali_pay_status' => 0,
            'tengxun_map_key' => null, 'routine_contact_type' => null, 'refund_name' => null, 'refund_phone' => null, 'refund_address' => null];
    } else {
        nhecr_expect($response['json'] === ['status' => 200, 'msg' => 'success', 'data' => $value], 'NHECR_CURRENT_ROOT_COMPLETE_INDEPENDENT130_DTO');
        $settings = ['station_open' => 1, 'store_self_mention' => 0, 'tengxun_map_key' => null, 'balance_func_status' => 1, 'yue_pay_status' => 1,
            'pay_weixin_open' => 0, 'ali_pay_status' => 0, 'friend_pay_status' => null, 'image_thumb_status' => 0, 'offline_pay_status' => 2,
            'routine_contact_type' => null, 'invoice_func_status' => 0, 'special_invoice_status' => 0,
            'order_shipping_open' => 0, 'refund_time_available' => null, 'site_url' => $intent['origin']];
    }
    $wires = [];
    foreach ($settings as $name => $wanted) $wires['system_config_' . $name] = ['wire' => nhepcr_config_wire($before, $name, $wanted), 'ttl' => 0];
    if (!$login && $intent['sequence'] !== 8) {
        $cancel = ['order_cancel_time' => '0', 'order_activity_time' => '0', 'order_bargain_time' => '0', 'order_seckill_time' => '0', 'order_pink_time' => '0'];
        foreach ($cancel as $name => $wanted) nhecr_expect(nhepcr_config_wire($before, $name, $wanted) === '0', 'NHECR_ORIGINAL_CANCEL_MAP_REAL_JSON_STRING_ZERO');
        $wires['system_config_' . md5(implode(',', array_keys($cancel)))] = ['wire' => serialize($cancel), 'ttl' => 0];
        $wires['data_order_details_images'] = ['wire' => serialize([]), 'ttl' => 0];
    }
    foreach (['sys_lang_source_map' => 'a:0:{}', 'type_id_zh-CN' => '1', 'lang_type_data' => 'a:0:{}'] as $name => $wire)
        $wires[$name] = ['wire' => $wire, 'ttl' => 3600];
    nhecr_remember_sequence($beforeRedis, $actualRedis, $wires, $response['actual_interval'], $changes, $members);
    nhepar_tag($beforeRedis, $actualRedis, 'crmeb', $members, $changes);
    $expectedRedis = nhep_redis_expected($before7['redis'], $changes, $actual7['redis']['time']);
    nhecr_expect($expectedRedis === $actual7['redis'], 'NHECR_CURRENT_WHOLE_NAMESPACE_ONLY_TRUE_AUTH_AND_GETTERS');
    return ['database' => $expectedDB['database'], 'rows' => $expectedDB['rows'], 'redis' => $expectedRedis];
}

/** Preserve JSON object/list/number distinctions after independent full DTO construction. */
function nhecr_json_exact(array $response, array $expected): void
{
    $actual = json_decode($response['raw'], false, 128, JSON_THROW_ON_ERROR);
    $flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    nhecr_expect($response['json'] === $expected
        && json_encode($actual, $flags) === json_encode($expected, $flags), 'NHECR_COMPLETE_JSON_NATIVE_STRUCTURE_KEY_ORDER_AND_NUMERIC_TYPES');
}

function nhecr_main(array $argv): array
{
    nhecr_expect(count($argv) === 4 && $argv[0] === __FILE__ && $argv[1] === 'normal'
        && preg_match('/\A--freeze-bytes=([1-9][0-9]{0,9})\z/D', $argv[2], $bytes) === 1
        && (string)(int)$bytes[1] === $bytes[1] && preg_match('/\A--freeze-sha256=([a-f0-9]{64})\z/D', $argv[3], $sha) === 1,
        'NHECR_ONLY_REAL_FIXED_NORMAL_ENTRY');
    $pin = ['path' => '/run/audit/preparation-source-binding.json', 'bytes' => (int)$bytes[1], 'sha256' => $sha[1]];
    $state = nhecr_qualify($pin); $parent = nhecr_parent_write($state); $children = []; $failure = null; $result = null;
    try {
        $private = nhecr_actors_read($state, $state['history_actors_receipt']); $configuration = audit_config();
        $db = audit_pdo($configuration['database']); $redis = audit_redis();
        nhecr_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)
            && $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0, 'NHECR_READER_INDEPENDENT_NATIVE_PREPARES');
        nhecr_expect($configuration['run_id'] === $state['context']['run_id'] && $configuration['database']['username'] === 'audit_app'
            && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_app@%'
            && audit_grants_are_restricted($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), 'NHECR_ORIGINAL_INDEPENDENT_OBSERVER_DML_ONLY');
        $baseline = nhecr_db_capture($db, $state['context']['run_id']); $cache = nhep_redis_capture($redis, $state['context']['run_id']);
        $fixture = nhecr_private_fixture_context($state, $baseline, $cache, $private);
        $state['private_fixture_context'] = $fixture; $ids = $state['handoff']['identities'];
        $plan = nhecr_request_plan($baseline, $fixture, $state['handoff']['seal']['http']);
        $owner = nhepcd_one($baseline, 'eb_user', ['uid' => $fixture['uid']]);
        nhecr_expect($owner['row']['account'] === $private['user_account'] && $owner['row']['pwd'] === md5($private['user_password']),
            'NHECR_ORIGINAL_PRIVATE_ACTOR_AND_NATIVE_BEFORE_MATCH');
        $login = ['step' => 'customer_login', 'method' => 'POST', 'path' => '/api/login', 'actor' => 'customer',
            'actor_id' => $fixture['uid'], 'body' => ['account' => $private['user_account'], 'password' => $private['user_password'], 'spread' => 0, 'agent_id' => 0],
            'requires_actual_same_actor_login' => false];
        array_unshift($plan, $login); nhecr_expect(count($plan) === 9, 'NHECR_COMPLETE_LOGIN_AND_EIGHT_CURRENT_GETS');
        $filesView = static fn(): array => [
            'private' => ['app' => nhecr_file_tree('/run/audit-app'), 'fixtures' => nhecr_file_tree('/run/audit-fixtures')],
            'public' => ['current_public' => nhecr_file_tree('/workspace/crmeb/public')],
            'logs' => ['runtime' => nhecr_file_tree('/current-runtime'),
                'current_root_runtime' => nhecr_file_tree('/workspace/crmeb/runtime')],
        ];
        $startupFilesBefore = $filesView();
        $startupStart = time(); nhecr_child_start($state, $children, 'http'); $startup = nhecr_stream_capture($children); $startupEnd = time();
        nhecr_expect($startup['http']['stdout']['raw'] === '', 'NHECR_NATIVE_HTTP_STARTUP_STDOUT_EMPTY');
        $stampPattern = '~\A\[([^\r\n]{24})\] PHP ' . preg_quote(PHP_VERSION, '~')
            . ' Development Server \(http://127[.]0[.]0[.]1:8000\) started\n\z~D';
        nhecr_expect(preg_match($stampPattern, $startup['http']['stderr']['raw'], $startupMatch) === 1, 'NHECR_ONLY_REAL_NORMAL_HTTP_STARTUP_LINE');
        nhep_http_asctime($startupMatch[1], [$startupStart, $startupEnd]);
        $startupFilesAfter = $filesView(); $startupFilesExpected = $startupFilesBefore;
        $oldRuntime = $startupFilesBefore['logs']['runtime']; $newRuntime = $startupFilesAfter['logs']['runtime'];
        nhecr_expect(is_array($oldRuntime) && is_array($newRuntime) && ($oldRuntime['']['kind'] ?? null) === 'directory'
            && $oldRuntime['']['stat']['mode'] === (0040000 | 0700), 'NHECR_STARTUP_ORIGINAL_PRIVATE_RUNTIME_ROOT');
        nhecr_files_streams(['native_streams' => $startup], $newRuntime);
        foreach (['stdout', 'stderr'] as $name) {
            $leaf = 'current-http.' . $name; $stream = $startup['http'][$name];
            nhecr_expect(!array_key_exists($leaf, $oldRuntime), 'NHECR_STARTUP_ONLY_TWO_CREATE_ONLY_NATIVE_STREAMS');
            $created = nhep_file_stat($children['http']['streams'][$name]['created']); $stat = $stream['stat'];
            $raw = $name === 'stdout' ? '' : '[' . $startupMatch[1] . '] PHP ' . PHP_VERSION
                . ' Development Server (http://127.0.0.1:8000) started' . "\n";
            foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
                nhecr_expect($stat[$key] === $created[$key], 'NHECR_STARTUP_STREAM_ORIGINAL_SIX_CREATED_FIELDS');
            nhecr_expect($created['size'] === 0 && $created['mode'] === (0100000 | 0600) && $created['nlink'] === 1
                && $stream['raw'] === $raw && $stat['size'] === strlen($raw)
                && $stat['mtime'] >= $created['mtime'] && $stat['ctime'] >= $created['ctime']
                && $stat['mtime'] >= $startupStart && $stat['mtime'] <= $startupEnd
                && $stat['ctime'] >= $startupStart && $stat['ctime'] <= $startupEnd,
                'NHECR_STARTUP_ONLY_PROVEN_START_LINE_AND_BOUNDED_NATIVE_STREAM_TIMES');
            $expectedStat = $created; $expectedStat['size'] = strlen($raw);
            $expectedStat['mtime'] = $stat['mtime']; $expectedStat['ctime'] = $stat['ctime'];
            $startupFilesExpected['logs']['runtime'][$leaf] = ['kind' => 'file', 'stat' => $expectedStat,
                'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
        }
        $oldRoot = $oldRuntime['']['stat']; $newRoot = $newRuntime['']['stat'];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
            nhecr_expect($newRoot[$key] === $oldRoot[$key], 'NHECR_STARTUP_SAME_PARENT_TWO_REGULAR_FILES_NO_NEW_DIRECTORY');
        nhecr_expect($newRoot['size'] >= $oldRoot['size'] && $newRoot['mtime'] >= $oldRoot['mtime']
            && $newRoot['ctime'] >= $oldRoot['ctime'] && $newRoot['mtime'] >= $startupStart && $newRoot['mtime'] <= $startupEnd
            && $newRoot['ctime'] >= $startupStart && $newRoot['ctime'] <= $startupEnd,
            'NHECR_STARTUP_PARENT_ONLY_BOUNDED_NATIVE_CREATION_METADATA');
        foreach (['size', 'mtime', 'ctime'] as $key) $startupFilesExpected['logs']['runtime']['']['stat'][$key] = $newRoot[$key];
        ksort($startupFilesExpected['logs']['runtime'], SORT_STRING);
        nhecr_expect($startupFilesExpected === $startupFilesAfter, 'NHECR_STARTUP_INDEPENDENT_COMPLETE_FILES_DELTA_ONLY_TWO_STREAMS');
        $startupNative = nhep_child_observation($children, 'http')['native'];
        $cookies = []; $token = null; $completed = []; $accepted = null;
        foreach ($plan as $index => $entry) {
            $intent = ['run_id' => $state['context']['run_id'], 'sequence' => $index + 1, 'step' => $entry['step'],
                'origin' => 'http://127.0.0.1:8000', 'method' => $entry['method'], 'path' => $entry['path'],
                'actor' => $entry['actor'], 'actor_id' => $entry['actor_id']];
            nhecr_intent($intent);
            $request = ['token' => $index === 0 || $index === 8 ? null : $token, 'body' => $entry['body'],
                'actor_identity' => ['id' => $entry['actor_id'], 'account' => $index === 8 ? null : $private['user_account']]];
            $before = nhecr_capture($state, $db, $redis, $children); $beforeDB = ['database' => $before['snapshot']['database'], 'rows' => $before['snapshot']['rows']];
            if ($accepted === null) {
                nhecr_expect($index === 0 && $beforeDB === $baseline, 'NHECR_FIRST_BEFORE_IS_REAL_SEALED_CURRENT_166_BASELINE');
                $continuous = ['database' => $baseline['database'], 'rows' => $baseline['rows'],
                    'redis' => nhep_redis_expected($cache['snapshot'], [], $before['snapshot']['redis']['time']),
                    'private' => $startupFilesExpected['private'], 'public' => $startupFilesExpected['public'],
                    'logs' => $startupFilesExpected['logs'], 'native' => ['http' => $startupNative]];
            } else {
                $continuous = $accepted;
                $continuous['redis'] = nhep_redis_expected($accepted['redis'], [], $before['snapshot']['redis']['time']);
            }
            nhecr_expect($continuous === $before['snapshot'], 'NHECR_COMPLETE_ACCEPTED_TO_NEXT_BEFORE_ONLY_PASSIVE_REDIS_EXPIRY');
            unset($continuous);
            // Every supported normal branch is proved before this one actual request.
            if ($index === 0) nhecr_login_context($beforeDB, $intent, $request, $fixture);
            elseif ($index <= 4) nhecr_catalog_context($beforeDB, $intent, $request, $fixture, $fixture['self_operated_mer_id']);
            elseif ($index <= 6) nhecr_order_value($beforeDB, $intent, $request);
            elseif ($index === 7) nhecr_refund_context($beforeDB, $intent, $request);
            $response = null; $primary = null; $extra = []; $closing = false;
            try { $response = nhecr_transport($intent, $request, $extra); $closing = nhecr_wait_closing($children, $response, $before['private']); }
            catch (Throwable $error) { $primary = $error; }
            // Exactly one real AFTER is captured even if transport or Closing failed.
            $actual = nhecr_capture($state, $db, $redis, $children);
            if ($primary !== null) throw $primary;
            nhecr_expect(is_array($response) && $closing && $response['http'] === 200 && $response['transport_errno'] === 0,
                'NHECR_ONE_REAL_TRANSPORT_AND_COMPLETE_CLOSING_BEFORE_ACTUAL_AFTER');
            $nextCookies = $cookies;
            if ($index >= 1 && $index <= 4) $models = nhecr_catalog_verify($before, $actual, $intent, $request, $response, $configuration);
            elseif ($index !== 8) {
                $models = nhecr_account_detail_verify($before, $actual, $intent, $request, $response, $configuration);
                if ($index === 0) { $data = $response['json']['data']; $json = ['status' => 200, 'msg' => '登录成功', 'data' => ['token' => $data['token'], 'expires_time' => $data['expires_time']]]; }
                elseif ($index === 7) $json = ['status' => 200, 'msg' => 'success', 'data' => nhecr_refund_context($beforeDB, $intent, $request)['value']];
                else $json = ['status' => 200, 'msg' => 'success', 'data' => nhecr_order_value($beforeDB, $intent, $request)];
                nhecr_json_exact($response, $json);
            } else {
                nhecr_original_image($intent['origin'], $intent['origin'] . $intent['path'], $response, $extra['redirect_count'], $extra['effective_url']);
                $models = ['database' => $before['snapshot']['database'], 'rows' => $before['snapshot']['rows'],
                    'redis' => nhep_redis_expected($before['snapshot']['redis'], [], $actual['snapshot']['redis']['time'])];
            }
            if ($index !== 8) $nextCookies = nhecr_headers_model($intent, $request, $response, $cookies)['seen_cookie_ids'];
            $files = nhecr_files_model($before['snapshot'], $actual['snapshot'], $intent, $response, $before['private'], $actual['private']);
            $expected = ['database' => $models['database'], 'rows' => $models['rows'], 'redis' => $models['redis'],
                'private' => $files['private'], 'public' => $files['public'], 'logs' => $files['logs'], 'native' => $before['snapshot']['native']];
            nhecr_expect($expected === $actual['snapshot'], 'NHECR_CURRENT_COMPLETE_INDEPENDENT_EXPECTED_SEVEN_EQUALS_ACTUAL');
            // No token/cookie/progress publication is committed before all seven parts pass.
            if ($index === 0) $token = $response['json']['data']['token'];
            $cookies = $nextCookies; $accepted = $expected;
            $completed[] = ['sequence' => $index + 1, 'step' => $entry['step'], 'http' => 200, 'seven_parts_equal' => true];
            unset($before, $actual, $request, $response, $extra, $json, $data, $beforeDB, $models, $expected, $files);
        }
        nhecr_expect(count($completed) === 9 && count($cookies) === 8 && is_array($accepted), 'NHECR_CURRENT_ALL_NINE_ACCEPTED_NO_PENDING');
        $close = nhecr_http_closed($state, $children);
        $finalDB = nhecr_db_capture($db, $state['context']['run_id']); $finalRedis = nhep_redis_capture($redis, $state['context']['run_id']);
        nhecr_expect($finalDB === ['database' => $accepted['database'], 'rows' => $accepted['rows']]
            && $finalRedis['snapshot'] === nhep_redis_expected($accepted['redis'], [], $finalRedis['snapshot']['time'])
            && $accepted['private'] === ['app' => nhecr_file_tree('/run/audit-app'), 'fixtures' => nhecr_file_tree('/run/audit-fixtures')]
            && $accepted['public'] === ['current_public' => nhecr_file_tree('/workspace/crmeb/public')]
            && $accepted['logs'] === ['runtime' => nhecr_file_tree('/current-runtime'), 'current_root_runtime' => nhecr_file_tree('/workspace/crmeb/runtime')],
            'NHECR_ALL_WRITERS_NORMAL_STOPPED_FINAL166_FILES_AND_ONLY_PASSIVE_REDIS_EXPIRY');
        nhecr_fresh($state); nhecr_includes($state); audit_guard_assert();
        $result = ['schema' => 1, 'scope' => 'CURRENT_SOURCE_SINGLE_SELF_LEGACY_READS_ONLY',
            'status' => 'VERIFIED_CURRENT_LEGACY_HISTORY_READS_NORMAL_CLOSED', 'run_id' => $state['context']['run_id'],
            'source_sha' => $state['context']['source_sha'], 'freeze_sha256' => $state['freeze_pin']['sha256'], 'container_id' => $state['container_id'],
            'entry_namespace_pid' => getmypid(), 'legacy_parent' => $state['handoff']['legacy_parent'], 'identities' => $ids,
            'requests' => $completed, 'normal_http_close' => $close,
            'feature_profile' => ['enabled' => false, 'self_operated_mer_id' => $fixture['self_operated_mer_id'], 'scope' => 'STRUCTURE_ONLY_NOT_BACKFILLED'],
            'files' => 'VERIFIED_CURRENT_SOURCE_NORMAL_CLOSE', 'reader_terminal' => 'REQUIRED_FROM_ORIGINAL_NATIVE_TERMINAL', 'full_acceptance' => 'INCOMPLETE'];
        $path = '/run/audit/evidence/legacy-current-reader-result.json'; $meta = m4m_new($path, m4m_json($result));
        nhecr_expect(m4m_read($path) === $result, 'NHECR_SAFE16_RESULT_CREATE_ONLY_FULL_READBACK');
        unset($private, $configuration, $token, $cookies, $accepted, $baseline, $cache, $finalDB, $finalRedis, $db, $redis, $fixture, $meta);
    } catch (Throwable $error) { $failure = $error; }
    finally {
        if (isset($children['http']) && $children['http']['closed'] === null) {
            try { nhecr_http_closed($state, $children); } catch (Throwable $closeError) { if ($failure === null) $failure = $closeError; }
        }
        unset($private, $configuration, $token, $cookies, $accepted);
    }
    if ($failure !== null) throw $failure;
    nhecr_expect(is_array($result), 'NHECR_ACTUAL_NORMAL_RESULT_REQUIRED'); return $result;
}

/** Source-only, fixed original normal-history fixture. All arguments remain parent-private. */
function nhecr_catalog_context(#[SensitiveParameter] array $beforeDB2, array $intent,
    #[SensitiveParameter] array $privateRequest, array $fixture, int $selfMerId): array
{
    $request = $privateRequest; $self = $selfMerId;
    nhecr_db_validate($beforeDB2);
    nhe_keys($intent, ['run_id','sequence','step','origin','method','path','actor','actor_id'], 'NHECR_CAT_INTENT');
    nhe_keys($request, ['token','body','actor_identity'], 'NHECR_CAT_REQUEST');
    nhe_keys($request['actor_identity'], ['id','account'], 'NHECR_CAT_ACTOR');
    $steps = ['current_products'=>2,'current_product_detail'=>3,'current_reply_list'=>4,'current_reply_config'=>5];
    nhep_expect(isset($steps[$intent['step']]) && $intent['sequence'] === $steps[$intent['step']]
        && $intent['method'] === 'GET' && $intent['origin'] === 'http://127.0.0.1:8000'
        && $intent['actor'] === 'customer' && is_string($intent['run_id'])
        && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $intent['run_id']) === 1
        && $request['body'] === null && is_string($request['token'])
        && preg_match('/\\A[A-Za-z0-9._~-]{16,4096}\\z/D',$request['token']) === 1, 'NHECR_CAT_FOUR_REAL_GETS');
    nhep_expect(($fixture['schema'] ?? null) === 1
        && ($fixture['scope'] ?? null) === 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT'
        && ($fixture['run_id'] ?? null) === $intent['run_id']
        && ($fixture['legacy_source_sha'] ?? null) === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
        && is_int($self) && $self > 0 && ($fixture['self_operated_mer_id'] ?? null) === $self
        && ($fixture['price'] ?? null) === '20.15' && ($fixture['cost'] ?? null) === '7.45'
        && ($fixture['initial_stock'] ?? null) === 10 && ($fixture['qty_per_order'] ?? null) === 1
        && ($fixture['public_image_path'] ?? null) === '/statics/system_images/spread_level_1.png',
        'NHECR_CAT_ORIGINAL_SAFE_FIXTURE_EXTERNAL_SELF_BINDING');
    nhep_expect(count($beforeDB2['rows']['audit_sentinel']) === 1
        && $beforeDB2['rows']['audit_sentinel'][0]['run_id'] === $intent['run_id'], 'NHECR_CAT_SAME_ORIGINAL_DATABASE_RUN');
    foreach (['uid','product_id','sku_id','attr_id'] as $key)
        nhep_expect(is_int($fixture[$key] ?? null) && $fixture[$key] > 0, 'NHECR_CAT_FIXTURE_NATIVE_IDS');
    $uid = $fixture['uid']; $pid = $fixture['product_id'];
    $paths = ['current_products'=>'/api/products?ids='.$pid.'&page=1&limit=20',
        'current_product_detail'=>'/api/product/detail/'.$pid,
        'current_reply_list'=>'/api/reply/list/'.$pid.'?page=1&limit=20&type=0',
        'current_reply_config'=>'/api/reply/config/'.$pid];
    nhep_expect($intent['path'] === $paths[$intent['step']] && $intent['actor_id'] === $uid
        && $request['actor_identity']['id'] === $uid, 'NHECR_CAT_INDEPENDENT_FIXED_PATH_AND_ACTOR');
    $selections = [
        'user'=>nhepcd_one($beforeDB2,'eb_user',['uid'=>$uid]),
        'product'=>nhepcd_one($beforeDB2,'eb_store_product',['id'=>$pid]),
        'sku'=>nhepcd_one($beforeDB2,'eb_store_product_attr_value',['id'=>$fixture['sku_id']]),
        'attribute'=>nhepcd_one($beforeDB2,'eb_store_product_attr',['id'=>$fixture['attr_id']]),
        'merchant'=>nhepcd_one($beforeDB2,'eb_marketplace_merchant',['mer_id'=>$self]),
        'order'=>nhepcd_one($beforeDB2,'eb_store_order',['mark'=>$intent['run_id'].'-review_family'])];
    $tables = ['user'=>'eb_user','product'=>'eb_store_product','sku'=>'eb_store_product_attr_value',
        'attribute'=>'eb_store_product_attr','merchant'=>'eb_marketplace_merchant','order'=>'eb_store_order'];
    foreach ($selections as $name=>&$selection)
        nhep_expect($selection['row'] === nhepcv_native_row($beforeDB2,$tables[$name],$selection['index']),
            'NHECR_CAT_ALL_SELECTED_NATIVE_COLUMNS');
    unset($selection);
    $user = $selections['user']['row']; $product = $selections['product']['row'];
    $sku = $selections['sku']['row']; $attribute = $selections['attribute']['row'];
    $merchant = $selections['merchant']['row']; $order = $selections['order']['row'];
    nhep_expect($user['account'] === $request['actor_identity']['account'] && $user['status'] === 1
        && $user['is_del'] === 0 && $user['nickname'] === '旧历史普通顾客' && $user['is_money_level'] === 0
        && is_int($user['last_time']) && $user['last_time'] > 0, 'NHECR_CAT_REAL_BUYER');
    nhep_expect(count($beforeDB2['rows']['eb_store_product']) === 1
        && $product['mer_id'] === $self && $product['review_status'] === 2 && $product['is_show'] === 1
        && $product['is_del'] === 0 && $product['spec_type'] === 1 && $product['price'] === '20.15'
        && $product['cost'] === '7.45' && $product['image'] === $fixture['public_image_path']
        && $product['store_name'] === '旧历史商品-'.$intent['run_id']
        && json_decode($product['slider_image'],true,512,JSON_THROW_ON_ERROR) === [$fixture['public_image_path']],
        'NHECR_CAT_CONTROLLED_PUBLISHED_PRODUCT');
    foreach (['is_virtual'=>0,'virtual_type'=>0,'presale'=>0,'is_vip'=>0,'is_good'=>0,'ficti'=>0,
        'custom_form'=>'[]','default_sku'=>'','params_list'=>'','label_list'=>'','protection_list'=>'',
        'recommend_list'=>'','video_link'=>'','command_word'=>''] as $field=>$wanted)
        nhep_expect($product[$field] === $wanted, 'NHECR_CAT_ORIGINAL_NORMAL_DISPLAY_BRANCH');
    nhep_expect($merchant['mer_id'] === $self && $merchant['review_status'] === 1
        && $merchant['security_locked'] === 0 && $merchant['closed_at'] === null,
        'NHECR_CAT_REAL_PUBLISHED_MERCHANT');
    foreach (['name','logo','after_sale_contact','shipping_note'] as $field)
        nhep_expect(is_string($merchant[$field]), 'NHECR_CAT_NATIVE_STORE_DISPLAY');
    nhep_expect($sku['product_id'] === $pid && $sku['type'] === 0 && $sku['is_show'] === 1
        && $sku['unique'] === $fixture['sku_unique'] && $sku['suk'] === '普通'
        && $sku['price'] === '20.15' && $sku['cost'] === '7.45' && $sku['image'] === $fixture['public_image_path']
        && is_int($sku['stock']) && $sku['stock'] >= 0 && $sku['stock'] <= 16777215
        && $attribute['product_id'] === $pid && $attribute['type'] === 0
        && $attribute['attr_name'] === '规格' && $attribute['attr_values'] === '普通',
        'NHECR_CAT_REAL_SINGLE_ACTIVE_MULTI_SPEC_SKU');
    foreach (['eb_store_product_attr_value'=>$fixture['sku_id'],'eb_store_product_attr'=>$fixture['attr_id']] as $table=>$id) {
        $active = [];
        foreach ($beforeDB2['rows'][$table] as $row)
            if ($row['product_id'] === $pid && $row['type'] === 0
                && ($table !== 'eb_store_product_attr_value' || $row['is_show'] === 1)) $active[] = $row['id'];
        nhep_expect($active === [$id], 'NHECR_CAT_COMPLETE_RELEVANT_SKU_AXIS_COLLECTION');
    }
    foreach (['eb_store_product_attr_result','eb_store_product_description'] as $table)
        foreach ($beforeDB2['rows'][$table] as $row)
            nhep_expect(!($row['product_id'] === $pid && $row['type'] === 0), 'NHECR_CAT_NO_UNCREATED_DESCRIPTION_OR_AXIS_PICTURE');
    // These are original fixture facts, not a claimed general catalog oracle.
    foreach (['eb_store_advance','eb_store_seckill','eb_store_combination','eb_store_bargain',
        'eb_store_coupon_issue','eb_store_product_relation','eb_lang_code','eb_lang_country','eb_lang_type'] as $table)
        nhep_expect($beforeDB2['rows'][$table] === [], 'NHECR_CAT_ORIGINAL_EMPTY_NORMAL_SIDE_COLLECTIONS');
    nhep_expect($order['uid'] === $uid && $order['paid'] === 1 && $order['status'] === 3
        && $order['refund_status'] === 0, 'NHECR_CAT_ORIGINAL_COMPLETED_REVIEW_FAMILY');
    $item = nhepcd_one($beforeDB2,'eb_store_order_cart_info',['oid'=>$order['id']]);
    nhep_expect($item['row'] === nhepcv_native_row($beforeDB2,'eb_store_order_cart_info',$item['index'])
        && $item['row']['uid'] === $uid && $item['row']['product_id'] === $pid
        && preg_match('/\A[a-f0-9]{32}\z/D',$item['row']['unique']) === 1,
        'NHECR_CAT_ORIGINAL_SAVED_REVIEW_ITEM');
    nhep_expect(count($beforeDB2['rows']['eb_store_product_reply']) === 1, 'NHECR_CAT_ORIGINAL_UNIQUE_GLOBAL_REPLY');
    $reply = nhepcv_native_row($beforeDB2,'eb_store_product_reply',0);
    nhep_expect($reply['oid'] === $order['id'] && $reply['unique'] === $item['row']['unique']
        && $reply['uid'] === $uid && $reply['product_id'] === $pid && $reply['reply_type'] === 'product'
        && $reply['status'] === 1 && $reply['is_del'] === 0 && $reply['is_reply'] === 1
        && $reply['product_score'] === 5 && $reply['service_score'] === 5 && $reply['pics'] === ''
        && $reply['comment'] === 'normal-history-'.$intent['run_id']
        && $reply['merchant_reply_content'] === 'normal-reply-'.$intent['run_id']
        && $reply['nickname'] === $user['nickname'] && $reply['avatar'] === $user['avatar']
        && $reply['suk'] === $sku['suk'] && is_int($reply['add_time']) && $reply['add_time'] > 0
        && is_int($reply['merchant_reply_time']) && $reply['merchant_reply_time'] >= $reply['add_time'],
        'NHECR_CAT_COMPLETE_ORIGINAL_APPROVED_REPLIED_NINETEEN_COLUMN_RELATION');
    foreach ($beforeDB2['rows']['eb_system_event'] as $row)
        nhep_expect(!($row['is_del'] === 0 && $row['is_open'] === 1), 'NHECR_CAT_NO_ENABLED_CUSTOM_EVENT_CODE');
    foreach ($beforeDB2['rows']['eb_system_notification'] as $row)
        foreach (['is_system','is_sms','is_wechat','is_routine','is_ent_wechat','is_app'] as $field)
            nhep_expect($row[$field] === 0, 'NHECR_CAT_NO_ENABLED_NOTIFICATION_CHANNEL');
    $settings = ['station_open'=>1,'member_card_status'=>0,'image_thumb_status'=>0,
        'member_price_status'=>0,'brokerage_window_switch'=>0,'site_url'=>$intent['origin'],
        'share_qrcode'=>null,'tengxun_map_key'=>null,'store_self_mention'=>0,'routine_contact_type'=>null,
        'queue_open'=>0,'param_filter_type'=>null,'param_filter_data'=>null];
    foreach ($settings as $name=>$wanted) nhepcr_config_wire($beforeDB2,$name,$wanted);
    return ['user'=>$selections['user'],'product'=>$product,'sku'=>$sku,'attribute'=>$attribute,
        'merchant'=>$merchant,'reply'=>$reply,'settings'=>$settings];
}

/** All and only legitimate time_tran outputs for the independently bounded saved reply. */
function nhecr_catalog_reply_envelopes(#[SensitiveParameter] array $context, array $interval): array
{
    nhep_expect(array_is_list($interval) && count($interval) === 2 && is_int($interval[0]) && is_int($interval[1])
        && $interval[0] > 0 && $interval[0] <= $interval[1] && $interval[1] <= 2147483647,
        'NHECR_CAT_NATIVE_HTTP_WINDOW');
    $reply = $context['reply']; $d0 = $interval[0] - $reply['add_time']; $d1 = $interval[1] - $reply['add_time'];
    nhep_expect($d0 >= 0 && $reply['merchant_reply_time'] <= $interval[0], 'NHECR_CAT_SAVED_TIMES_PRECEDE_HTTP');
    $ages = []; if ($d0 === 0) $ages[] = null;
    $previous = null;
    foreach ([[31536000,'年'],[2592000,'个月'],[604800,'星期'],[86400,'天'],[3600,'小时'],[60,'分钟'],[1,'秒']] as [$unit,$label]) {
        $left = max($d0,$unit); $right = min($d1,$previous === null ? $d1 : $previous-1);
        if ($left <= $right) for ($q=intdiv($left,$unit),$last=intdiv($right,$unit);$q<=$last;++$q) $ages[] = (string)$q.$label.'前';
        $previous = $unit;
    }
    nhep_expect($ages !== [], 'NHECR_CAT_ALL_RELATIVE_TIME_BUCKETS');
    $outputs = [];
    foreach ($ages as $age) {
        $row = array_intersect_key($reply,array_fill_keys(['id','product_id','nickname','avatar','is_money_level',
            'star','add_time','suk','comment','pics','merchant_reply_content','merchant_reply_time'],true));
        $row['nickname'] = '旧**客'; $row['pics'] = null; $row['add_time'] = $age;
        $row['merchant_reply_time'] = substr(nhepodv_date('Y-m-d H:i:s',$reply['merchant_reply_time']),0,16);
        $row['is_money_level'] = $context['user']['row']['is_money_level']; $row['star'] = '5';
        nhep_expect(array_keys($row) === ['id','product_id','comment','pics','add_time','merchant_reply_content',
            'merchant_reply_time','nickname','avatar','suk','is_money_level','star'], 'NHECR_CAT_PUBLIC_REPLY_TWELVE_ORDER');
        $outputs[] = $row;
    }
    return $outputs;
}

/** Complete final JSON envelopes, independently constructed without a response parameter. */
function nhecr_catalog_envelopes(#[SensitiveParameter] array $context, array $intent, array $interval): array
{
    $product = $context['product']; $sku = $context['sku']; $merchant = $context['merchant'];
    $step = $intent['step']; $data = null; $envelopes = [];
    if ($step === 'current_products') {
        $row = ['id'=>$product['id'],'store_name'=>$product['store_name'],'image'=>$product['image'],
            'sales'=>$product['sales']+$product['ficti'],'price'=>$product['price'],'stock'=>$sku['stock'],
            'activity'=>[],'ot_price'=>$product['ot_price'],'spec_type'=>$product['spec_type'],'unit_name'=>$product['unit_name'],
            'is_vip'=>$product['is_vip'],'vip_price'=>0,'is_virtual'=>$product['is_virtual'],'presale'=>$product['presale'],
            'virtual_type'=>$product['virtual_type'],'min_qty'=>$product['min_qty'],'label_list'=>[],
            'mer_id'=>$product['mer_id'],'star'=>'5.0','cart_button'=>1,'checkCoupon'=>false];
        return [['status'=>200,'msg'=>'success','data'=>[$row]]];
    }
    if ($step === 'current_reply_config')
        return [['status'=>200,'msg'=>'success','data'=>['sum_count'=>1,'good_count'=>1,'in_count'=>0,
            'poor_count'=>0,'reply_chance'=>'100','reply_star'=>'5']]];
    foreach (nhecr_catalog_reply_envelopes($context,$interval) as $reply) {
        if ($step === 'current_reply_list') $data = [$reply];
        else {
            nhep_expect($step === 'current_product_detail', 'NHECR_CAT_ONLY_CLOSED_DTO_OPERATION');
            $storeInfo = $product;
            $storeInfo['slider_image'] = [$intent['origin'].$product['image']];
            $storeInfo['description'] = ''; $storeInfo['stock'] = $sku['stock']; $storeInfo['default_sku'] = '';
            $storeInfo['image'] = $intent['origin'].$product['image']; $storeInfo['image_base'] = $storeInfo['image'];
            $storeInfo['video_link'] = ''; $storeInfo['fsales'] = $product['sales']+$product['ficti'];
            $storeInfo['custom_form'] = []; $storeInfo['params_list'] = []; $storeInfo['label_list'] = [];
            $storeInfo['protection_list'] = []; $storeInfo['wechat_code'] = '';
            $storeInfo['userCollect'] = false; $storeInfo['userLike'] = false; $storeInfo['small_image'] = $storeInfo['image'];
            $storeInfo['presale_pay_status'] = 0;
            foreach (['presale_start_time','presale_end_time'] as $name)
                $storeInfo[$name] = substr(nhepodv_date('Y-m-d H:i:s',$product[$name]),0,16);
            $storeInfo['cart_button'] = 1; $storeInfo['attrPics'] = [];
            $storeInfo['svip_economize_price'] = bcsub($product['price'],$product['vip_price'],2);
            $storeInfo['vip_price'] = 0;
            $detailKeys = ['id','mer_id','store_name','store_info','image','slider_image','small_image','image_base',
                'price','ot_price','vip_price','unit_name','sales','fsales','stock','spec_type','is_postage','postage',
                'is_vip','video_link','min_qty','limit_num','is_virtual','virtual_type','presale','presale_start_time',
                'presale_end_time','presale_day','presale_pay_status','cart_button','cart_num','is_att','labels','label_list',
                'checkCoupon','cate_name','description','default_sku','unique','params_list','protection_list',
                'userCollect','userLike','command_word','wechat_code','attrPics','svip_economize_price','is_gift'];
            $storeInfo = array_intersect_key($storeInfo,array_fill_keys($detailKeys,true));
            $value = array_intersect_key($sku,array_fill_keys(['id','product_id','type','unique','suk','image',
                'price','ot_price','vip_price','stock','cart_num'],true)); $value['vip_price'] = 0;
            $store = ['mer_id'=>$merchant['mer_id'],'name'=>$merchant['name'],'logo'=>$merchant['logo'],
                'after_sale_contact'=>$merchant['after_sale_contact'],'shipping_note'=>$merchant['shipping_note']];
            $data = ['spec_unique'=>'','productAttr'=>[['attr_name'=>'规格','attr_values'=>['普通'],
                'attr_value'=>[['attr'=>'普通','check'=>false,'pic'=>'']]]],
                'productValue'=>['普通'=>$value],'storeInfo'=>$storeInfo,'svip_open'=>false,'svip_price_open'=>0,
                'priceName'=>0,'reply'=>$reply,'replyChance'=>'100','replyCount'=>1,'mer_id'=>$product['mer_id'],
                'good_list'=>[],'mapKey'=>'','store_self_mention'=>0,'activity'=>[],'coupons'=>[],
                'routine_contact_type'=>0,'store'=>$store];
        }
        $envelopes[] = ['status'=>200,'msg'=>'success','data'=>$data];
    }
    return $envelopes;
}

/** Exact static-Model user update GUID. The sole variable data field is the checked setter clock. */
function nhecr_catalog_auth_guid(#[SensitiveParameter] array $beforeDB2, int $uid, int $writtenTime): string
{
    $options = ['json'=>[],'json_assoc'=>false,'field_type'=>[],
        'where'=>['AND'=>[['uid','=',$uid]]],'strict'=>false,'cache'=>[true,null,null],'key'=>null,
        'field'=>array_keys($beforeDB2['database']['tables']['eb_user']['metadata']['columns']),
        'data'=>['last_time'=>$writtenTime],'table'=>'eb_user','order'=>[],'join'=>[],'union'=>[],'master'=>true,
        'lock'=>false,'fetch_sql'=>false,'array'=>false,'distinct'=>false,'procedure'=>false,
        'group'=>'','having'=>'','limit'=>'','force'=>'','comment'=>'','partition'=>'','duplicate'=>'','extra'=>''];
    return 'c:'.md5('audit_auth'.serialize(var_export($options,true)).serialize([]));
}

/** Ordered known-wire remembers; caller checks the one complete crmeb tag after all lookups. */
function nhecr_remember_sequence(#[SensitiveParameter] array $beforeRedisCapture,
    #[SensitiveParameter] array $actualRedisCapture, #[SensitiveParameter] array $logicalWires,
    array $responseInterval, array &$changes, array &$tagMembers): void
{
    $before = $beforeRedisCapture; $actual = $actualRedisCapture; $interval = $responseInterval;
    nhepar_capture($before); nhepar_capture($actual);
    nhep_expect(array_is_list($interval) && count($interval) === 2 && is_int($interval[0]) && is_int($interval[1])
        && $interval[0] > 0 && $interval[0] <= $interval[1] && $interval[1] <= 2147483647
        && bccomp($before['snapshot']['time'][0],(string)$interval[0],0) <= 0
        && bccomp((string)$interval[1],$actual['snapshot']['time'][0],0) <= 0,
        'NHECR_REMEMBER_REAL_HTTP_INSIDE_ATOMIC_REDIS_CLOCKS');
    $members =& $tagMembers;
    $lower = bcmul((string)$interval[0],'1000',0); $ttlMs = '3600000';
    foreach ($logicalWires as $name=>$definition) {
        nhep_expect(is_string($name) && preg_match('/\A[A-Za-z0-9_.:-]{1,256}\z/D',$name) === 1,
            'NHECR_REMEMBER_REAL_LOGICAL_KEY');
        nhe_keys($definition,['wire','ttl'],'NHECR_REMEMBER_KNOWN_WIRE_AND_SOURCE_TTL');
        nhep_expect(is_string($definition['wire']) && in_array($definition['ttl'],[0,3600],true),
            'NHECR_REMEMBER_FINITE_ORIGINAL_TTLS');
        $wire = $definition['wire'];
        if ($definition['ttl'] === 0) {
            nhepar_remember($before,$actual,$name,$wire,0,$changes,$members); continue;
        }
        $key = 'c:'.$name; $old = nhepar_entry($before,$key);
        if ($old === null || bccomp((string)$old['expires'],$actual['snapshot']['time_ms'],0) > 0) {
            nhepar_remember($before,$actual,$name,$wire,3600,$changes,$members);
            if ($old === null) {
                $now = nhepar_entry($actual,$key); $write = bcsub((string)$now['expires'],$ttlMs,0);
                nhep_expect(bccomp($write,bcmul((string)$interval[0],'1000',0),0) >= 0
                    && bccomp($write,bcmul((string)($interval[1]+1),'1000',0),0) < 0
                    && bccomp($write,$lower,0) >= 0, 'NHECR_CAT_SERIAL_COLD_LANGUAGE_CLOCK');
                $lower = $write;
            }
        } elseif (bccomp((string)$old['expires'],$actual['snapshot']['time_ms'],0) === 0
            && nhepar_entry($actual,$key) !== null) {
            nhep_expect($old['type'] === 'string' && $old['value'] === $wire && is_int($old['expires'])
                && $old['expires'] > 0 && bccomp((string)$old['expires'],$lower,0) >= 0,
                'NHECR_CAT_EQUAL_DEADLINE_REAL_WARM_LANGUAGE');
            $bound = nhepar_bind($actual,$key,'string',$wire,$old['expires'],$before['snapshot']);
            nhep_expect($bound === $before['snapshot']['keys'][hash('sha256',$key)], 'NHECR_CAT_WARM_DESCRIPTOR_NOT_RENEWED');
            $members[] = $key;
        } else {
            nhep_expect($old['type'] === 'string' && $old['value'] === $wire && is_int($old['expires'])
                && $old['expires'] > 0 && bccomp((string)$old['expires'],$before['snapshot']['time_ms'],0) >= 0,
                'NHECR_CAT_BEFORE_LANGUAGE_NATIVE_DEADLINE');
            $now = nhepar_entry($actual,$key);
            if ($now === null) {
                nhep_expect(bccomp((string)$old['expires'],bcmul((string)$interval[0],'1000',0),0) >= 0
                    && bccomp((string)$old['expires'],$lower,0) >= 0, 'NHECR_CAT_SERIAL_GET_THEN_NATURAL_EXPIRY');
            } else {
                $changes[$key] = nhepar_bind($actual,$key,'string',$wire,['ttl'=>3600],$before['snapshot']);
                $write = bcsub((string)$now['expires'],$ttlMs,0);
                nhep_expect(bccomp($write,(string)$old['expires'],0) > 0
                    && bccomp($write,bcmul((string)$interval[0],'1000',0),0) >= 0
                    && bccomp($write,bcmul((string)($interval[1]+1),'1000',0),0) < 0
                    && bccomp($write,$lower,0) >= 0, 'NHECR_CAT_SERIAL_NATURAL_LANGUAGE_REBUILD');
                $lower = $write;
            }
            $members[] = $key;
        }
    }
}

/** Full original Redis namespace: actual native times bound only known values and original TTLs. */
function nhecr_catalog_redis(#[SensitiveParameter] array $beforeCapture, #[SensitiveParameter] array $actualCapture,
    #[SensitiveParameter] array $beforeDB2, #[SensitiveParameter] array $context, array $intent,
    #[SensitiveParameter] array $request, #[SensitiveParameter] array $response,
    #[SensitiveParameter] array $auth, int $writtenTime): array
{
    $before = ['snapshot'=>$beforeCapture['snapshot']['redis'],'native'=>$beforeCapture['private']['redis_native']];
    $actual = ['snapshot'=>$actualCapture['snapshot']['redis'],'native'=>$actualCapture['private']['redis_native']];
    nhepar_capture($before); nhepar_capture($actual); $interval = $response['actual_interval'];
    foreach ([$before,$actual] as $capture) {
        $sentinel = nhepar_entry($capture,'audit:sentinel');
        nhep_expect($sentinel !== null && $sentinel['type'] === 'string' && $sentinel['expires'] === -1
            && $sentinel['value'] === $intent['run_id'], 'NHECR_CAT_REDIS_ORIGINAL_SENTINEL');
    }
    nhep_expect(bccomp($before['snapshot']['time'][0],(string)$interval[0],0) <= 0
        && bccomp((string)$interval[1],$actual['snapshot']['time'][0],0) <= 0
        && ($auth['run_id'] ?? null) === $intent['run_id'], 'NHECR_CAT_HTTP_INSIDE_REAL_REDIS_CLOCKS');
    $changes = []; $members = [];
    nhepar_customer($before,$actual,$context['user']['row'],$intent,$request,$response,$auth,$changes);
    $key = nhecr_catalog_auth_guid($beforeDB2,$context['user']['row']['uid'],$writtenTime);
    if (nhepar_entry($before,$key) !== null) $changes[$key] = null;
    nhep_expect(nhepar_entry($actual,$key) === null, 'NHECR_CAT_EXACT_AUTH_GUID_CONDITIONAL_DELETE');
    $names = match ($intent['step']) {
        'current_products'=>['station_open','param_filter_type','param_filter_data','member_card_status','image_thumb_status'],
        'current_product_detail'=>['station_open','member_card_status','site_url','share_qrcode','image_thumb_status',
            'member_price_status','brokerage_window_switch','tengxun_map_key','store_self_mention','routine_contact_type','queue_open'],
        'current_reply_list'=>['station_open','param_filter_type','param_filter_data','site_url','image_thumb_status'],
        'current_reply_config'=>['station_open'],
    };
    $logicalWires = [];
    foreach ($names as $name) $logicalWires['system_config_'.$name] = [
        'wire'=>nhepcr_config_wire($beforeDB2,$name,$context['settings'][$name]),'ttl'=>0];
    foreach (['sys_lang_source_map'=>'a:0:{}','type_id_zh-CN'=>'1','lang_type_data'=>'a:0:{}'] as $name=>$wire)
        $logicalWires[$name] = ['wire'=>$wire,'ttl'=>3600];
    nhecr_remember_sequence($before,$actual,$logicalWires,$interval,$changes,$members);
    nhepar_tag($before,$actual,'crmeb',$members,$changes);
    $expected = nhep_redis_expected($before['snapshot'],$changes,$actual['snapshot']['time']);
    nhep_expect($expected === $actual['snapshot'], 'NHECR_CAT_COMPLETE_REDIS_NAMESPACE_EXPECTED');
    return $expected;
}

/** Main's real four-step hook. No capture, App, IO, executable body, or claimed reader readiness. */
function nhecr_catalog_verify(#[SensitiveParameter] array $beforeCapture, #[SensitiveParameter] array $actualCapture,
    array $intent, #[SensitiveParameter] array $privateRequest, #[SensitiveParameter] array $privateResponse,
    #[SensitiveParameter] array $privateAuthConfiguration): array
{
    foreach (['nhep_expect','nhe_keys','nhecr_db_validate','nhecr_db_expected','nhepcd_one','nhepcv_native_row','nhep_auth_db_index',
        'nhep_auth_db_time','nhep_auth_db_update','m4lh_actor_native','m4lh_actor_expected_row','m4lh_actor_next',
        'nhepcr_config_wire','nhepar_customer','nhepar_capture','nhepar_entry','nhepar_remember','nhepar_bind',
        'nhepar_tag','nhep_redis_expected','nhepodv_date'] as $dependency)
        if (!function_exists($dependency)) throw new RuntimeException('NHECR_CAT_REAL_PURE_DEPENDENCY_REQUIRED');
    nhep_expect(PHP_INT_SIZE === 8, 'NHECR_CAT_NATIVE_64_BIT_REQUIRED');
    foreach ([$beforeCapture,$actualCapture] as $capture) {
        nhe_keys($capture,['snapshot','private'],'NHECR_CAT_PRIVATE_CAPTURE_PAIR');
        nhe_keys($capture['snapshot'],['database','rows','redis','private','public','logs','native'],'NHECR_CAT_COMPLETE_SEVEN_VIEW');
        foreach (['redis_native','fixture','self_operated_mer_id'] as $field)
            nhep_expect(array_key_exists($field,$capture['private']), 'NHECR_CAT_REQUIRED_CALLER_PRIVATE_FIELD');
    }
    nhep_expect($beforeCapture['private']['fixture'] === $actualCapture['private']['fixture']
        && $beforeCapture['private']['self_operated_mer_id'] === $actualCapture['private']['self_operated_mer_id'],
        'NHECR_CAT_ORIGINAL_FIXTURE_NOT_AFTER_SELECTED');
    $beforeDB2 = ['database'=>$beforeCapture['snapshot']['database'],'rows'=>$beforeCapture['snapshot']['rows']];
    $actualDB2 = ['database'=>$actualCapture['snapshot']['database'],'rows'=>$actualCapture['snapshot']['rows']];
    nhecr_db_validate($beforeDB2); nhecr_db_validate($actualDB2);
    $context = nhecr_catalog_context($beforeDB2,$intent,$privateRequest,$beforeCapture['private']['fixture'],
        $beforeCapture['private']['self_operated_mer_id']);
    nhe_keys($privateResponse,['http','raw','json','actual_interval','transport_errno','primary_ip','primary_port',
        'transport_failure_class','headers','local_ip','local_port'],'NHECR_CAT_ORIGINAL_ELEVEN_TRANSPORT_FIELDS');
    $headers = $privateResponse['headers'];
    nhe_keys($headers,['lines','blocks','bytes','complete','limit_exceeded'],'NHECR_CAT_COMPLETE_HEADER_CAPTURE');
    nhep_expect($headers['complete'] === true && $headers['limit_exceeded'] === false
        && is_array($headers['lines']) && array_is_list($headers['lines'])
        && is_array($headers['blocks']) && array_is_list($headers['blocks']) && count($headers['blocks']) === 1
        && $headers['blocks'][0] === $headers['lines'] && count($headers['lines']) >= 3,
        'NHECR_CAT_REAL_SINGLE_COMPLETE_HEADER_BLOCK');
    $headerBytes = 0; $types = []; $lines = $headers['lines'];
    foreach ($lines as $i=>$line) {
        nhep_expect(is_string($line) && !str_contains($line,"\0"), 'NHECR_CAT_HEADER_LINE_BYTES');
        $headerBytes += strlen($line);
        if ($i === 0) {
            nhep_expect(preg_match('~\AHTTP/1\.[01] 200(?: [^\r\n]*)?\r?\n\z~D',$line) === 1,
                'NHECR_CAT_NATIVE_HTTP_SUCCESS_STATUS_LINE');
        } elseif ($i === count($lines)-1) {
            nhep_expect(in_array($line,["\r\n","\n"],true), 'NHECR_CAT_HEADER_TERMINATOR');
        } else {
            nhep_expect(preg_match('/\A([^:\x00-\x20\x7f]+):[ \t]*([^\r\n]*)\r?\n\z/D',$line,$match) === 1,
                'NHECR_CAT_COMPLETE_HEADER_FIELD');
            $name = strtolower($match[1]); nhep_expect($name !== 'location', 'NHECR_CAT_NO_LOCATION_HEADER');
            if ($name === 'content-type') $types[] = strtolower(trim($match[2]));
        }
    }
    nhep_expect(is_int($headers['bytes']) && $headers['bytes'] === $headerBytes && $headerBytes <= 65536
        && count($types) === 1 && preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/D',$types[0]) === 1,
        'NHECR_CAT_REAL_JSON_CONTENT_TYPE');
    $interval = $privateResponse['actual_interval'] ?? null;
    nhep_expect(is_array($interval) && array_is_list($interval) && count($interval) === 2
        && is_int($interval[0]) && is_int($interval[1]) && $interval[0] > 0
        && $interval[0] <= $interval[1] && $interval[1] <= 2147483647
        && ($privateResponse['http'] ?? null) === 200 && ($privateResponse['transport_errno'] ?? null) === 0
        && $privateResponse['transport_failure_class'] === null
        && ($privateResponse['primary_ip'] ?? null) === '127.0.0.1'
        && ($privateResponse['primary_port'] ?? null) === 8000
        && ($privateResponse['local_ip'] ?? null) === '127.0.0.1'
        && is_int($privateResponse['local_port'] ?? null) && $privateResponse['local_port'] > 0
        && $privateResponse['local_port'] <= 65535 && is_string($privateResponse['raw'] ?? null)
        && strlen($privateResponse['raw']) <= 6291456 && is_array($privateResponse['json'] ?? null),
        'NHECR_CAT_ACTUAL_SUCCESSFUL_NORMAL_TRANSPORT');
    $uid = $context['user']['row']['uid']; $meta = $context['user']['metadata'];
    $index = nhep_auth_db_index($actualDB2['rows']['eb_user'],$meta,'uid',$uid);
    $written = nhep_auth_db_time($meta,'last_time',$actualDB2['rows']['eb_user'][$index]['last_time'],
        $interval,$context['user']['row']['last_time']);
    $rows = ['eb_user'=>nhep_auth_db_update($beforeDB2,'eb_user',$context['user']['index'],['last_time'=>$written])];
    $counts = [];
    if ($intent['step'] === 'current_product_detail') {
        $table = 'eb_store_product_log'; $definition = $beforeDB2['database']['tables'][$table];
        nhep_expect(array_keys($definition['metadata']['columns']) === ['id','type','product_id','uid','visit_num',
            'cart_num','order_num','pay_num','pay_price','cost_price','pay_uid','refund_num','refund_price','collect_num','add_time'],
            'NHECR_CAT_COMPLETE_ORIGINAL_FIFTEEN_LOG_COLUMNS');
        $id = m4lh_actor_next($definition,'id');
        foreach ($beforeDB2['rows'][$table] as $row) nhep_expect($row['id'] !== $id, 'NHECR_CAT_NEXT_LOG_ID_ABSENT');
        $index = nhep_auth_db_index($actualDB2['rows'][$table],$definition['metadata'],'id',$id);
        $clock = $actualDB2['rows'][$table][$index]['add_time']; $column = $definition['metadata']['columns']['add_time'];
        nhep_expect(preg_match('/\\Aint(?:\\([0-9]+\\))?\\z/D',strtolower($column['COLUMN_TYPE'])) === 1 && $column['IS_NULLABLE'] === 'NO'
            && is_int($clock) && m4lh_actor_native($column,$clock) === $clock
            && $clock >= $interval[0] && $clock <= $interval[1] && $clock >= $written,
            'NHECR_CAT_INDEPENDENT_SIGNED_MODEL_VISIT_CLOCK_AFTER_AUTH');
        $new = m4lh_actor_expected_row($definition['metadata'],['id'=>$id,'type'=>'visit',
            'product_id'=>$context['product']['id'],'uid'=>$uid,'visit_num'=>1,'add_time'=>$clock]);
        $rows[$table] = $beforeDB2['rows'][$table]; $rows[$table][] = $new; $counts[$table] = 1;
    }
    $expectedDB2 = nhecr_db_expected($beforeDB2,$rows,$counts);
    nhep_expect($expectedDB2 === $actualDB2, 'NHECR_CAT_FULL_166_TABLE_INDEPENDENT_EXPECTED');
    $actualJSON = json_decode($privateResponse['raw'],true,512,JSON_THROW_ON_ERROR);
    nhep_expect($actualJSON === $privateResponse['json'], 'NHECR_CAT_RAW_JSON_AND_PRIVATE_PARSE_JOIN');
    $actualObject = serialize(json_decode($privateResponse['raw'],false,512,JSON_THROW_ON_ERROR)); $matched = false;
    foreach (nhecr_catalog_envelopes($context,$intent,$interval) as $expectedJSON) {
        $wire = json_encode($expectedJSON,JSON_THROW_ON_ERROR);
        if ($actualJSON === $expectedJSON
            && $actualObject === serialize(json_decode($wire,false,512,JSON_THROW_ON_ERROR))) $matched = true;
    }
    nhep_expect($matched, 'NHECR_CAT_COMPLETE_ORDERED_JSON_NATIVE_DTO_ENVELOPE');
    $expectedRedis = nhecr_catalog_redis($beforeCapture,$actualCapture,$beforeDB2,$context,$intent,
        $privateRequest,$privateResponse,$privateAuthConfiguration,$written);
    return ['database'=>$expectedDB2['database'],'rows'=>$expectedDB2['rows'],'redis'=>$expectedRedis];
}

function nhecr_stat9(array $native): array
{
    $result = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        nhecr_expect(array_key_exists($key, $native) && is_int($native[$key]), 'NHECR_ACTORS_NATIVE_INTEGER_STAT9');
        $result[$key] = $native[$key];
    }
    nhecr_expect($result['ino'] > 0 && $result['nlink'] > 0 && $result['size'] >= 0,
        'NHECR_ACTORS_NATIVE_FILE_IDENTITY');
    return $result;
}

function nhecr_fixture_mount(array $facts, array $context): array
{
    nhecr_expect(is_string($context['run_id'] ?? null)
        && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $context['run_id']) === 1
        && ($context['project'] ?? null) === 'tianli-audit-' . $context['run_id']
        && ($facts['labels']['com.docker.compose.service'] ?? null) === 'audit-legacy-current-reader'
        && is_array($facts['mounts'] ?? null), 'NHECR_ACTORS_SAME_REAL_READER_RUN_MOUNT');
    $matches = [];
    foreach ($facts['mounts'] as $mount)
        if (($mount['Destination'] ?? null) === '/run/audit-fixtures') $matches[] = $mount;
    nhecr_expect(count($matches) === 1, 'NHECR_ACTORS_SINGLE_REGISTERED_FIXTURE_MOUNT');
    $mount = $matches[0]; $name = $context['project'] . '_fixture-secrets';
    nhecr_expect(($mount['Type'] ?? null) === 'volume' && ($mount['Name'] ?? null) === $name
        && ($mount['RW'] ?? null) === false && is_string($mount['Source'] ?? null)
        && $mount['Source'] !== '' && $mount['Source'][0] === '/' && !str_contains($mount['Source'], "\0"),
        'NHECR_ACTORS_ORIGINAL_FIXTURE_VOLUME_RO_ONLY');
    $lines = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
    nhecr_expect(is_array($lines) && $lines !== [], 'NHECR_ACTORS_REAL_NATIVE_MOUNTINFO'); $actual = [];
    foreach ($lines as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]);
        if (($left[4] ?? null) === '/run/audit-fixtures') {
            nhecr_expect(count($parts) === 2 && isset($left[5]), 'NHECR_ACTORS_COMPLETE_NATIVE_FIXTURE_MOUNT');
            $actual[] = explode(',', $left[5]);
        }
    }
    nhecr_expect(count($actual) === 1 && in_array('ro', $actual[0], true) && !in_array('rw', $actual[0], true),
        'NHECR_ACTORS_ACTUAL_SAME_FIXTURE_MOUNT_RO');
    return ['Type' => 'volume', 'Name' => $name, 'Source' => $mount['Source'],
        'Destination' => '/run/audit-fixtures', 'RW' => false];
}

function nhecr_actor_prepare_receipt(array $context, array &$proofs): array
{
    nhecr_expect(is_string($context['run_id'] ?? null)
        && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $context['run_id']) === 1
        && is_string($context['source_sha'] ?? null) && preg_match('/\A[a-f0-9]{40}\z/D', $context['source_sha']) === 1
        && is_string($context['freeze_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $context['freeze_sha256']) === 1
        && ($context['project'] ?? null) === 'tianli-audit-' . $context['run_id'], 'NHECR_ACTORS_REAL_PREPARE_CONTEXT');
    $proofs = []; $values = [];
    foreach (['prepare-created.json', 'prepare-result.json', 'prepare-terminal.json'] as $leaf) {
        $path = '/run/audit/evidence/' . $leaf; $proofs[$path] = nhecr_public_read($path);
        nhecr_expect($proofs[$path]['pin']['bytes'] <= 131072, 'NHECR_ACTORS_FINITE_ORIGINAL_PREPARE_PUBLIC_BYTES');
        $value = json_decode($proofs[$path]['raw'], true, 128, JSON_THROW_ON_ERROR);
        nhecr_expect(is_array($value) && !array_is_list($value), 'NHECR_ACTORS_ORIGINAL_PUBLIC_OBJECT'); $values[$leaf] = $value;
    }
    $made = $values['prepare-created.json']; $result = $values['prepare-result.json']; $terminal = $values['prepare-terminal.json'];
    $proof = $result['nonempty_history_actors_private'] ?? null;
    nhecr_expect(is_array($proof), 'NHECR_ACTORS_CURRENT_PREPARE_PRIVATE_METADATA_REQUIRED');
    nhecr_keys($proof, ['schema', 'run_id', 'source_sha', 'freeze_sha256', 'prepare_container_id', 'prepare_created',
        'volume', 'path', 'access', 'stat9', 'exact_four_format', 'complete_readback']);
    nhecr_expect($proof['schema'] === 1 && $proof['run_id'] === $context['run_id'] && $proof['source_sha'] === $context['source_sha']
        && $proof['freeze_sha256'] === $context['freeze_sha256']
        && $proof['path'] === '/run/audit-fixtures/nonempty-history-actors.json'
        && $proof['access'] === 'PREPARE_RW_CONSUMER_RO' && $proof['exact_four_format'] === true && $proof['complete_readback'] === true
        && $proof['prepare_created'] === $proofs['/run/audit/evidence/prepare-created.json']['pin'],
        'NHECR_ACTORS_EXACT_CURRENT_TWELVE_KEY_PROVENANCE');
    $cid = $proof['prepare_container_id'];
    nhecr_expect(is_string($cid) && preg_match('/\A[a-f0-9]{64}\z/D', $cid) === 1
        && ($made['id'] ?? null) === $cid && ($made['run_id'] ?? null) === $context['run_id']
        && ($made['source_sha'] ?? null) === $context['source_sha'] && ($made['proof_name'] ?? null) === 'prepare'
        && ($made['image'] ?? null) === M4M_IMAGE && ($made['status'] ?? null) === 'created' && ($made['pid'] ?? null) === 0
        && ($made['restart_count'] ?? null) === 0 && ($made['read_only'] ?? null) === true && ($made['privileged'] ?? null) === false
        && ($made['entrypoint'] ?? null) === ['php'] && ($made['command'] ?? null) === ['/run/ms4-prep/ms4-initialize.php', 'prepare']
        && ($made['workdir'] ?? null) === '/workspace'
        && ($made['labels']['tianli.audit'] ?? null) === '1' && ($made['labels']['tianli.audit.run'] ?? null) === $context['run_id']
        && ($made['labels']['com.docker.compose.project'] ?? null) === $context['project']
        && ($made['labels']['com.docker.compose.service'] ?? null) === 'audit-bootstrap', 'NHECR_ACTORS_ORIGINAL_PREPARE_CREATED');
    nhecr_expect(($terminal['id'] ?? null) === $cid && ($terminal['status'] ?? null) === 'exited' && ($terminal['pid'] ?? null) === 0
        && ($terminal['exit_code'] ?? null) === 0 && ($terminal['restart_count'] ?? null) === 0, 'NHECR_ACTORS_ORIGINAL_PREPARE_NATIVE_ZERO');
    foreach (['image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command',
        'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $key)
        nhecr_expect($made[$key] === $terminal[$key], 'NHECR_ACTORS_ALL_PREPARE_IMMUTABLE_FIELDS');
    $mountMap = static function (array $mounts): array {
        $map = [];
        foreach ($mounts as $mount) {
            nhecr_expect(is_array($mount) && is_string($mount['Destination'] ?? null)
                && !isset($map[$mount['Destination']]), 'NHECR_ACTORS_COMPLETE_UNIQUE_PREPARE_MOUNTS');
            $map[$mount['Destination']] = $mount;
        }
        ksort($map, SORT_STRING); return $map;
    };
    nhecr_expect($mountMap($made['mounts']) === $mountMap($terminal['mounts']), 'NHECR_ACTORS_COMPLETE_PREPARE_MOUNTS_UNCHANGED');
    $binding = $result['binding'] ?? null; nhecr_expect(is_array($binding), 'NHECR_ACTORS_ORIGINAL_PREPARE_BINDING');
    nhecr_keys($binding, ['container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256']);
    nhecr_expect(($result['schema'] ?? null) === 1 && ($result['scope'] ?? null) === 'MS4_PREREQUISITES_ONLY'
        && ($result['status'] ?? null) === 'PREPARED_PRIVATE_CONFIG_ONLY' && ($result['native_exit'] ?? null) === 0
        && ($result['run_id'] ?? null) === $context['run_id'] && ($result['source_sha'] ?? null) === $context['source_sha']
        && ($result['children'] ?? null) === [] && ($result['legacy_private_environment_created'] ?? null) === true
        && $binding['container_id'] === $cid && is_int($binding['entry_namespace_pid']) && $binding['entry_namespace_pid'] > 0
        && $binding['source_sha'] === $context['source_sha'] && $binding['freeze_sha256'] === $context['freeze_sha256']
        && $binding['created_sha256'] === $proofs['/run/audit/evidence/prepare-created.json']['pin']['sha256'],
        'NHECR_ACTORS_ORIGINAL_PREPARE_COMPLETE_RESULT_BINDING');
    $volumeName = $context['project'] . '_fixture-secrets';
    $volumes = array_values(array_filter($context['initial_volumes'], static fn(array $row): bool => ($row['name'] ?? null) === $volumeName));
    nhecr_expect(count($volumes) === 1 && $proof['volume'] === $volumes[0]
        && ($volumes[0]['labels']['tianli.audit'] ?? null) === '1'
        && ($volumes[0]['labels']['tianli.audit.run'] ?? null) === $context['run_id']
        && ($volumes[0]['labels']['com.docker.compose.project'] ?? null) === $context['project'],
        'NHECR_ACTORS_SAME_COMPLETE_ORIGINAL_FIXTURE_VOLUME');
    $mounts = $mountMap($made['mounts']); $mount = $mounts['/run/audit-fixtures'] ?? null;
    nhecr_expect(is_array($mount) && ($mount['Type'] ?? null) === 'volume' && ($mount['Name'] ?? null) === $volumeName
        && ($mount['RW'] ?? null) === true && is_string($mount['Source'] ?? null) && $mount['Source'] !== ''
        && $mount['Source'][0] === '/' && !str_contains($mount['Source'], "\0"), 'NHECR_ACTORS_ORIGINAL_PREPARE_RW_VOLUME');
    $mountView = ['Type' => 'volume', 'Name' => $volumeName, 'Source' => $mount['Source'], 'Destination' => '/run/audit-fixtures', 'RW' => true];
    nhecr_expect(is_array($proof['stat9']), 'NHECR_ACTORS_ORIGINAL_FILE_STAT9');
    nhecr_keys($proof['stat9'], ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']);
    $file = nhecr_stat9($proof['stat9']);
    nhecr_expect(($file['mode'] & 0170000) === 0100000 && ($file['mode'] & 07777) === 0600
        && $file['nlink'] === 1 && $file['size'] > 0 && $file['size'] <= 4096, 'NHECR_ACTORS_ORIGINAL_PRIVATE_FILE_METADATA_ONLY');
    // Original Host emits this native summary, not the old actor-closed schema.
    $paths = glob('/run/audit/evidence/native-*.json');
    nhecr_expect(is_array($paths) && count($paths) > 0 && count($paths) <= 4096, 'NHECR_ACTORS_BOUNDED_ORIGINAL_NATIVE_LEDGER');
    sort($paths, SORT_STRING); $remove = null;
    foreach ($paths as $path) {
        nhecr_expect(preg_match('~\A/run/audit/evidence/native-[0-9]{4,}\.json\z~D', $path) === 1,
            'NHECR_ACTORS_ONLY_ORIGINAL_NUMERIC_NATIVE_LEDGER');
        $row = nhecr_public_read($path); nhecr_expect($row['pin']['bytes'] <= 16384, 'NHECR_ACTORS_FINITE_NATIVE_PUBLIC_SUMMARY');
        $value = json_decode($row['raw'], true, 32, JSON_THROW_ON_ERROR);
        nhecr_expect(is_array($value) && !array_is_list($value), 'NHECR_ACTORS_ORIGINAL_NATIVE_PUBLIC_OBJECT');
        if (($value['stage'] ?? null) !== 'prepare-exact-nonforce-remove') continue;
        nhecr_expect($remove === null, 'NHECR_ACTORS_ONLY_ONE_ORIGINAL_PREPARE_REMOVE');
        nhecr_keys($value, ['stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted']);
        nhecr_expect($value['native_exit'] === 0 && $value['stdout_bytes'] === 65 && $value['stdout_sha256'] === hash('sha256', $cid . "\n")
            && $value['stderr_bytes'] === 0 && $value['stderr_sha256'] === hash('sha256', '')
            && $value['private_environment_logs_omitted'] === true, 'NHECR_ACTORS_ACTUAL_ORIGINAL_ORDINARY_REMOVE_SUMMARY');
        $proofs[$path] = $row; $remove = $row['pin'];
    }
    nhecr_expect(is_array($remove), 'NHECR_ACTORS_ORIGINAL_PREPARE_NORMAL_RETIRE_REQUIRED');
    foreach ($proofs as $path => $value) nhecr_expect(nhecr_public_read($path) === $value, 'NHECR_ACTORS_ALL_ORIGINAL_PUBLIC_BYTES_STAT9_REJOIN');
    return ['prepare_proof' => $proof, 'fixture_mount' => $mountView, 'prepare_remove' => $remove];
}

if (PHP_SAPI === 'cli-server') return nhecr_router();
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1'); umask(0077);
    set_error_handler(static function (): never { throw new RuntimeException('NHECR_NATIVE_WARNING_STATE_PRESERVED'); });
    try { $result = nhecr_main($_SERVER['argv']); echo m4m_json($result); exit(0); }
    catch (Throwable $ignored) { fwrite(STDERR, "NHECR_CURRENT_READER_FAILED_STATE_PRESERVED\n"); exit(1); }
}

