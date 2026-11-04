"""Fixed initializer, file-read, file-observer and producer-entry seams; no I/O or CLI.

The emitted source candidate requires a real external source review, the original
producer seal/four CLI and both real normal retirements before registration.
It does not grant runtime qualification. The original seed, initialize and
child functions are preserved byte for byte. The default-shipping oracle alone
includes the nullable owner column already declared by MS2. The separate file
bootstrap receives a bounded CLI budget; shared and HTTP configuration is unchanged.
The producer adopts only its existing complete main through the fixed direct entry.
"""

import hashlib


INITIALIZER_SOURCE_BYTES = 63165
INITIALIZER_SOURCE_SHA256 = "95c0e205d3596e3701aeceb5dcce3cd046682a4843c924df2c12128bf17379a8"

# Filled from the fixed current m4m source, without changing any function bytes.
M4M_SOURCE_PIN = (24718, '82df4155f3a95b6a325fa4cae4807fff1f1af365e541d3d4c4e65cd339b17ac7')
M4M_OBSERVER_FUNCTIONS = rb'''function m4m_expect(bool $condition, string $code): void { if (!$condition) throw new RuntimeException($code); }
function m4m_quote(string $name): string { m4m_expect(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) === 1, 'SQL_IDENTIFIER'); return '`' . $name . '`'; }
function m4m_rows(PDO $db, string $sql, array $args = []): array { $s = $db->prepare($sql); m4m_expect($s->execute($args), 'SQL_READ'); return $s->fetchAll(PDO::FETCH_ASSOC); }
function m4m_metadata(PDO $db, string $table): array
{
    $args = [$table];
    $tables = m4m_rows($db, 'SELECT TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $args);
    m4m_expect(count($tables) === 1, 'TABLE_METADATA');
    $columns = [];
    foreach (m4m_rows($db, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $row) $columns[$row['COLUMN_NAME']] = $row;
    $indexes = [];
    foreach (m4m_rows($db, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $row) $indexes[$row['INDEX_NAME']][] = $row;
    return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
        'constraints' => m4m_rows($db, 'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME', $args),
        'checks' => m4m_rows($db, "SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", $args),
        'foreign' => m4m_rows($db, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', $args),
        'triggers' => m4m_rows($db, 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
}
function m4m_projection(PDO $db, string $table, array $columns): array
{
    $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($table));
    $hashes = array_map(static fn(array $row): string => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $rows);
    sort($hashes, SORT_STRING);
    return ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $hashes))];
}
function m4m_snapshot(array $originalColumns = []): array
{
    $db = audit_db(); $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $snapshot = ['names' => $names, 'tables' => [], 'redis' => [], 'modes' => m4m_rows($db, 'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb')];
    foreach ($names as $name) {
        m4m_expect(preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D', $name) === 1, 'OWN_SCHEMA_TABLE');
        $metadata = m4m_metadata($db, $name); $columns = $originalColumns[$name] ?? array_keys($metadata['columns']);
        $projection = m4m_projection($db, $name, $columns);
        $metadata = m4m_metadata($db, $name);
        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);
        m4m_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'ACTUAL_SHOW_CREATE');
        $snapshot['tables'][$name] = ['metadata' => $metadata, 'ddl_sha256' => hash('sha256', $ddl[1]), 'projection' => $projection, 'projected_columns' => $columns];
    }
    $raw = audit_snapshot(['tables' => [], 'redis' => ['*']]); $redis = audit_redis();
    foreach ($raw['redis']['*'] as $key => $hash) {
        $expiry = $redis->rawCommand('PEXPIRETIME', $key);
        m4m_expect(is_int($expiry) && ($expiry === -1 || $expiry > 0), 'REDIS_ABSOLUTE_EXPIRY');
        $snapshot['redis'][hash('sha256', $key)] = ['sha256' => $hash, 'expires' => $expiry];
    }
    ksort($snapshot['redis'], SORT_STRING); return $snapshot;
}
'''
# Original donor registration is adapted below; it is never included wholesale.
READER_REGISTRATION_DONOR_PIN = (62966, '9753365c2ff96690b82411d25aeecd0c28c1bf1d751aa1ec44ce272f8a610070')
READER_REGISTRATION_DONOR_FUNCTION_PIN = (5223, '1262f4fbfa0be3501478c7c28b162e2726b46c2683109841552d4c7532aeb7e0')
# Pure definitions copied byte-for-byte from the same fixed M4M_SOURCE_PIN.
M4M_REGISTRATION_FUNCTIONS = rb'''const M4M_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4M_ADDITIONS = [
    'eb_store_order' => ['marketplace_trade_version', 'merchant_name_snapshot', 'merchant_after_sale_contact_snapshot'],
    'eb_store_order_cart_info' => ['mer_id'],
    'eb_store_order_refund' => ['mer_id', 'marketplace_refund_mode'],
    'eb_store_product_reply' => ['mer_id'],
];
const M4M_INDEXES = [
    'eb_store_order' => ['mer_id', 'id'], 'eb_store_order_cart_info' => ['mer_id', 'oid', 'id'],
    'eb_store_order_refund' => ['mer_id', 'store_order_id', 'id'], 'eb_store_product_reply' => ['mer_id', 'id'],
];
function m4m_mount(array $facts, string $destination): array
{
    $matches = array_values(array_filter($facts['mounts'] ?? [], static fn(array $m): bool => ($m['Destination'] ?? '') === $destination));
    m4m_expect(count($matches) === 1, 'EXACT_MOUNT'); return $matches[0];
}
function m4m_preserved(array $before, array $after, bool $additions): void
{
    m4m_expect($before['modes'] === $after['modes'] && $before['redis'] === $after['redis'], 'SQL_MODES_AND_CACHE_UNCHANGED');
    foreach ($before['tables'] as $name => $old) {
        m4m_expect(isset($after['tables'][$name]) && $old['projection'] === $after['tables'][$name]['projection'], 'ORIGINAL_COLUMN_ROWS_UNCHANGED');
        $actual = $after['tables'][$name]['metadata'];
        if ($additions && isset(M4M_ADDITIONS[$name])) {
            $wanted = M4M_ADDITIONS[$name];
            m4m_expect(array_keys($actual['columns']) === array_merge(array_keys($old['metadata']['columns']), $wanted), 'ONLY_APPEND_COLUMNS');
            foreach ($wanted as $column) unset($actual['columns'][$column]);
            $originalIndexes = $old['metadata']['indexes'];
            foreach ($actual['indexes'] as $index => $rows) if (!isset($originalIndexes[$index])) {
                m4m_expect(array_column($rows, 'COLUMN_NAME') === M4M_INDEXES[$name] && count(array_unique(array_column($rows, 'NON_UNIQUE'))) === 1 && (string) $rows[0]['NON_UNIQUE'] === '1', 'ONLY_APPROVED_OWNER_INDEX');
                unset($actual['indexes'][$index]);
                $actual['constraints'] = array_values(array_filter($actual['constraints'], static fn(array $row): bool => $row['CONSTRAINT_NAME'] !== $index));
            }
        }
        else m4m_expect($old['ddl_sha256'] === $after['tables'][$name]['ddl_sha256'], 'NONTARGET_FULL_DDL_UNCHANGED');
        m4m_expect($actual === $old['metadata'], 'ORIGINAL_METADATA_AND_NONTARGET_UNCHANGED');
    }
}
'''

UPGRADE_SOURCE_FILES = rb'''function m4iu_sourceFiles(): array
{
    return [
        ['path' => 'help/audit-regressions/isolated/ms4-migration.php', 'bytes' => 24718, 'sha256' => '82df4155f3a95b6a325fa4cae4807fff1f1af365e541d3d4c4e65cd339b17ac7'],
        ['path' => 'help/migrations/marketplace-ms1.php', 'bytes' => 38810, 'sha256' => '9d73229f911b5712ca9c7051a18468c98837ae44f48bf77d20b2c165ac02b17a'],
        ['path' => 'help/migrations/marketplace-ms4.php', 'bytes' => 37120, 'sha256' => '8954a47a9233b119a666267fc4a0f6b33b2ce8da41925b702e0f3a9ff77e95b4'],
        ['path' => 'help/migrations/marketplace-ms5-payment.php', 'bytes' => 27010, 'sha256' => '2e58f44b8d2140514d9b45a25d199820c80b90a635e10bd356cfda9a8eb0ccca'],
        ['path' => 'help/migrations/marketplace-ms4-fulfillment.php', 'bytes' => 45363, 'sha256' => '01a976fb1d980c83d04b280c1c6df4eece7070d1e1eda39ead5f83a1e6f6da3f'],
        ['path' => 'help/migrations/marketplace-ms4-aftersale.php', 'bytes' => 59273, 'sha256' => '18aae0d1dc9293f5e6a2032f56421bd452b96f0cbb0b8d6f25ec81426d53a23f'],
        ['path' => 'help/migrations/marketplace-ms6-schema.php', 'bytes' => 11129, 'sha256' => 'f42353122c658e4a3d1cceae7ab341b5ea904409c1068ddca6c369bea7bc03c5'],
        ['path' => 'crmeb/crmeb/exceptions/MarketplaceSchemaException.php', 'bytes' => 638, 'sha256' => '87f3cff9efa3198b8b2a1f072d2d0563f5ac01810400a22f086349862d84222b'],
        ['path' => 'crmeb/crmeb/services/marketplace/SchemaVersionGate.php', 'bytes' => 12267, 'sha256' => '25bf37fa585d76110d74fc8f8aa7615aad2915d99c04c83e3cab850ab2cbba31'],
        ['path' => 'crmeb/marketplace-release.json', 'bytes' => 114, 'sha256' => 'a81a548d6265653de876c80e2dbe1bac293ac2365de65d4cafb44b9829d963db'],
        ['path' => 'crmeb/public/install/crmeb.sql', 'bytes' => 10237459, 'sha256' => '1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828'],
    ];
}
'''

_UPGRADE_FUNCTIONS = rb'''
/** Fixed post-four-CLI phase. The original initialize/seed/child remain untouched. */
function m4iu_source(): array
{
    $binding = m4i_gate('current-structure-upgrade'); $manifest = audit_manifest();
    $context = m4i_read('/run/audit/actual-run-context.json');
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    m2f_assert($names === ['audit-bootstrap', 'audit-mysql', 'audit-redis']
        && $manifest['run_id'] === $context['run_id'] && $manifest['commit_sha'] === $context['source_sha']
        && $manifest['guard_mode'] === 'read-only' && $manifest['network_internal'] === true
        && $manifest['network']['id'] === $context['network_id']
        && $manifest['services']['audit-bootstrap']['id'] === $binding['container_id'], 'CURRENT_UPGRADE_THREE_ROLES');
    foreach (['audit-mysql', 'audit-redis'] as $host)
        m2f_assert(gethostbyname($host) === $manifest['services'][$host]['address']
            && filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'CURRENT_UPGRADE_NATIVE_ENDPOINT');
    foreach (m4iu_sourceFiles() as $item) foreach (['/workspace', '/source'] as $root) m4i_sourceFile($root, $item);
    audit_guard_assert(); return $binding;
}
function m4iu_keys(array $value, array $wanted): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($wanted, SORT_STRING);
    m2f_assert($actual === $wanted, 'CURRENT_UPGRADE_EXACT_KEYS');
}
function m4iu_plan(): array
{
    $plan = [];
    foreach (['marketplace-ms5-payment.php', 'marketplace-ms4-fulfillment.php', 'marketplace-ms4-aftersale.php'] as $script)
        foreach (['check', 'apply-structure', 'check', 'apply-structure'] as $mode) $plan[] = [$script, $mode];
    foreach (['apply', 'check', 'apply', 'check'] as $mode) $plan[] = ['marketplace-ms6-schema.php', $mode];
    return $plan;
}
/** Same native proc_open/status/proc_close primitives as m4i_child; no shell. */
function m4iu_child(string $config, array &$evidence): array
{
    m4iu_source(); $index = count($evidence['children']); $plan = m4iu_plan();
    m2f_assert($index < 16, 'CURRENT_UPGRADE_FINITE_SIXTEEN_CHILDREN');
    [$script, $mode] = $plan[$index];
    $argv = [PHP_BINARY, '/workspace/help/migrations/' . $script, $mode];
    if ($script === 'marketplace-ms6-schema.php') $argv[] = '--repo-root=/workspace';
    $argv[] = '--config-file=' . $config;
    $stem = '/run/audit/evidence/current-structure-upgrade-cli-' . sprintf('%02d', $index + 1);
    foreach (['created.json', 'stdout', 'stderr', 'terminal.json'] as $suffix)
        m2f_assert(!file_exists($stem . '.' . $suffix) && !is_link($stem . '.' . $suffix), 'CURRENT_UPGRADE_NEW_NATIVE_FILES_ONLY');
    $started = time(); $process = proc_open($argv, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace');
    m2f_assert(is_resource($process), 'CURRENT_UPGRADE_REAL_CHILD'); $first = proc_get_status($process);
    $made = ['argv' => $argv, 'pid' => $first['pid'], 'started_at' => $started];
    try {
        m2f_new($stem . '.created.json', m2f_json($made));
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    } finally { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); $close = proc_close($process); }
    $finished = time(); $native = $close;
    if ($close === -1 && $first['running'] === false && $first['signaled'] === false
        && is_int($first['exitcode']) && $first['exitcode'] >= 0) $native = $first['exitcode'];
    $outPin = m2f_new($stem . '.stdout', (string)$stdout); $errPin = m2f_new($stem . '.stderr', (string)$stderr);
    $result = is_string($stdout) && strlen($stdout) <= 1048576 ? json_decode($stdout, true) : null;
    $terminal = $made + ['finished_at' => $finished, 'proc_close' => $close, 'native_exit' => $native,
        'initial_status' => ['running' => $first['running'], 'signaled' => $first['signaled'], 'exitcode' => $first['exitcode']],
        'stdout' => ['path' => $stem . '.stdout'] + $outPin, 'stderr' => ['path' => $stem . '.stderr'] + $errPin];
    $terminalPin = m2f_new($stem . '.terminal.json', m2f_json($terminal));
    $evidence['children'][] = $terminal + ['script' => $script, 'mode' => $mode,
        'terminal' => ['path' => $stem . '.terminal.json'] + $terminalPin, 'result' => $result];
    m2f_assert($native === 0 && $stderr === '' && is_int($first['pid']) && $first['pid'] > 0
        && $finished >= $started && is_array($result), 'CURRENT_UPGRADE_NATIVE_ZERO_AND_COMPLETE_RECEIPT');
    return $result;
}
function m4iu_steps(int $group): array
{
    if ($group === 0) return ['marketplace_payment_registry', 'marketplace_payment_attempt'];
    if ($group === 1) return ['marketplace_fulfillment_operation', 'marketplace_fulfillment_range'];
    m2f_assert($group === 2, 'CURRENT_UPGRADE_FINITE_STRUCTURE_GROUP');
    $steps = ['create.marketplace_aftersale_action'];
    foreach (array_keys(m4iu_refundColumns()) as $name) $steps[] = 'refund.column.' . $name;
    foreach (['idx_ms4a_refund_root', 'uq_ms4a_refund_apply', 'idx_ms4a_refund_last'] as $name) $steps[] = 'refund.index.' . $name;
    foreach (['mm4a_refund_apply_fk', 'mm4a_refund_last_fk'] as $name) $steps[] = 'refund.foreign.' . $name;
    return array_merge($steps, ['refund.check.mm4a_refund_v1', 'operation.column.aftersale_cut_id',
        'operation.index.idx_fop_aftersale_cut', 'operation.foreign.mm4a_fop_cut_fk',
        'operation.checks.replace_known_ids_and_add_cut', 'create.marketplace_aftersale_file']);
}
function m4iu_structureReceipt(array $result, int $group, int $step): void
{
    $scripts = ['marketplace-ms5-payment.php', 'marketplace-ms4-fulfillment.php', 'marketplace-ms4-aftersale.php'];
    $keys = ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known',
        'issues', 'failure_phase', 'marker', 'source_sha256', 'acceptance'];
    $extra = $group === 0 ? ['history_mapping', 'payment_producer'] : ['scope', 'prerequisite_source_sha256', 'writer'];
    if ($group === 2) $extra = array_merge($extra, ['structure_stage', 'structure_step_count']);
    m4iu_keys($result, array_merge($keys, $extra)); $steps = m4iu_steps($group);
    $mode = ['check', 'apply-structure', 'check', 'apply-structure'][$step];
    m2f_assert($result['mode'] === $mode && $result['state'] === ($step === 0 ? 'applicable' : 'completed')
        && $result['native_exit'] === 0 && $result['changed'] === ($step === 1)
        && $result['applied_steps'] === ($step === 1 ? $steps : [])
        && $result['pending_steps'] === ($step === 0 ? $steps : []) && $result['pending_steps_known'] === true
        && $result['issues'] === [] && $result['failure_phase'] === null && $result['acceptance'] === 'NOT RUN'
        && $result['source_sha256'] === hash_file('sha256', '/workspace/help/migrations/' . $scripts[$group]),
        'CURRENT_UPGRADE_EXACT_NORMAL_STRUCTURE_RECEIPT');
    if ($group === 0) m2f_assert($result['marker'] === 'marketplace-ms5-payment-structure-v1'
        && $result['history_mapping'] === 'NOT RUN' && $result['payment_producer'] === 'NOT IMPLEMENTED', 'CURRENT_UPGRADE_PAYMENT_RECEIPT');
    else {
        $prerequisites = [];
        foreach (['marketplace-ms1.php', 'marketplace-ms4.php', 'marketplace-ms5-payment.php'] as $file)
            $prerequisites['help/migrations/' . $file] = hash_file('sha256', '/workspace/help/migrations/' . $file);
        if ($group === 2) {
            $prerequisites['help/migrations/marketplace-ms4-fulfillment.php'] = hash_file('sha256', '/workspace/help/migrations/marketplace-ms4-fulfillment.php');
            $prerequisites['crmeb/public/install/crmeb.sql'] = hash_file('sha256', '/workspace/crmeb/public/install/crmeb.sql');
        }
        m2f_assert($result['marker'] === ($group === 1 ? 'marketplace-ms4-fulfillment-v1' : 'marketplace-ms4-aftersale-v1')
            && $result['scope'] === ($group === 1 ? 'MS4_FULFILLMENT_STRUCTURE_ONLY' : 'MS4_AFTERSALE_STRUCTURE_ONLY')
            && $result['prerequisite_source_sha256'] === $prerequisites && $result['writer'] === 'NOT RUN', 'CURRENT_UPGRADE_PREREQUISITE_SOURCE_JOIN');
        if ($group === 2) m2f_assert($result['structure_step_count'] === 20
            && $result['structure_stage'] === ($step === 0 ? 0 : 20), 'CURRENT_UPGRADE_FINAL_AFTERSALE_STAGE_TWENTY');
    }
}
function m4iu_redisTime(): int
{
    $time = audit_redis()->rawCommand('TIME');
    m2f_assert(is_array($time) && count($time) === 2 && preg_match('/\A[0-9]+\z/D', (string)$time[0]) === 1
        && preg_match('/\A[0-9]{1,6}\z/D', (string)$time[1]) === 1, 'CURRENT_UPGRADE_NATIVE_REDIS_TIME');
    return (int)$time[0] * 1000 + intdiv((int)$time[1], 1000);
}
function m4iu_capture(array $columns): array
{
    $start = m4iu_redisTime(); $snapshot = m4m_snapshot(); $projections = []; $db = audit_db();
    foreach ($columns as $name => $list) $projections[$name] = m4m_projection($db, $name, $list);
    $end = m4iu_redisTime(); m2f_assert($end >= $start, 'CURRENT_UPGRADE_REAL_OBSERVER_WINDOW');
    return ['snapshot' => $snapshot, 'original_projection' => $projections, 'redis_time_before' => $start, 'redis_time_after' => $end];
}
function m4iu_cachePreserved(array $before, array $after): void
{
    m2f_assert(is_int($after['redis_time_before']) && is_int($after['redis_time_after'])
        && $after['redis_time_after'] >= $after['redis_time_before'], 'CURRENT_UPGRADE_NATIVE_REDIS_OBSERVATION_INTERVAL');
    $actual = $after['snapshot']['redis'];
    // SQL precedes Redis inside the original m4m snapshot. The native interval
    // bounds that observation; no claim is made that it occurs at its start.
    foreach ($actual as $key => $row) m2f_assert(array_key_exists($key, $before) && $row === $before[$key],
        'CURRENT_UPGRADE_NO_NEW_KEYS_OR_CHANGED_DUMPS_OR_DEADLINES');
    foreach ($before as $key => $row) if (!array_key_exists($key, $actual))
        m2f_assert(is_int($row['expires']) && $row['expires'] > 0 && $row['expires'] <= $after['redis_time_after'],
            'CURRENT_UPGRADE_MISSING_KEY_ONLY_REAL_TIME_PASSIVE_EXPIRY');
}
function m4iu_unchanged(array $before, array $after): void
{
    foreach (['names', 'tables', 'modes'] as $key) m2f_assert($before['snapshot'][$key] === $after['snapshot'][$key], 'CURRENT_UPGRADE_READ_ONLY_FULL_DATABASE');
    m2f_assert($before['original_projection'] === $after['original_projection'], 'CURRENT_UPGRADE_READ_ONLY_ORIGINAL_PROJECTION');
    m4iu_cachePreserved($before['snapshot']['redis'], $after);
}
function m4iu_existingTables(array $before, array $after, array $changed): void
{
    foreach ($changed as $name) m2f_assert(isset($before['snapshot']['tables'][$name]), 'CURRENT_UPGRADE_DECLARED_EXISTING_TARGET');
    foreach ($before['snapshot']['tables'] as $name => $row) if (!in_array($name, $changed, true))
        m2f_assert(($after['snapshot']['tables'][$name] ?? null) === $row, 'CURRENT_UPGRADE_PREVIOUSLY_CREATED_AND_ALL_OTHER_TABLES_PRESERVED');
}
function m4iu_refundColumns(): array
{
    return ['marketplace_root_order_id' => ['int unsigned', null], 'marketplace_payment_attempt_id' => ['bigint unsigned', null],
        'marketplace_kind' => ['varchar(24)', 'ascii'], 'marketplace_state' => ['varchar(16)', 'ascii'],
        'marketplace_physical_state' => ['varchar(24)', 'ascii'], 'marketplace_finance_state' => ['varchar(16)', 'ascii'],
        'marketplace_apply_action_id' => ['bigint unsigned', null], 'marketplace_last_action_id' => ['bigint unsigned', null]];
}
/** Exact new metadata is checked before removing only these declared additions. */
function m4iu_refundPreserved(array $before, array $actual, PDO $db): array
{
    $columns = m4iu_refundColumns(); $nulls = [];
    m2f_assert(count($before['columns']) === 26 && array_keys($actual['columns']) === array_merge(array_keys($before['columns']), array_keys($columns)), 'CURRENT_UPGRADE_ONLY_EIGHT_REFUND_COLUMNS');
    foreach ($columns as $name => [$type, $charset]) {
        $column = $actual['columns'][$name]; $nativeType = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
        m2f_assert($column === ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $column['COLUMN_TYPE'], 'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null, 'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $charset === null ? null : 'ascii_bin',
            'EXTRA' => '', 'GENERATION_EXPRESSION' => '', 'COLUMN_COMMENT' => ''] && $nativeType === $type, 'CURRENT_UPGRADE_EXACT_NULLABLE_REFUND_COLUMN');
        $nulls[$name] = (int)$db->query('SELECT COUNT(*) FROM eb_store_order_refund WHERE ' . m4m_quote($name) . ' IS NOT NULL')->fetchColumn();
        m2f_assert($nulls[$name] === 0, 'CURRENT_UPGRADE_ALL_OLD_REFUND_ROWS_NEW_COLUMNS_NULL'); unset($actual['columns'][$name]);
    }
    foreach (['idx_ms4a_refund_root' => [1, ['marketplace_root_order_id', 'id']],
        'uq_ms4a_refund_apply' => [0, ['marketplace_apply_action_id']], 'idx_ms4a_refund_last' => [1, ['marketplace_last_action_id']]] as $name => [$nonUnique, $names]) {
        $rows = $actual['indexes'][$name] ?? []; m2f_assert(count($rows) === count($names), 'CURRENT_UPGRADE_EXACT_NEW_REFUND_INDEX_LENGTH');
        foreach ($names as $position => $column) {
            $row = $rows[$position];
            m2f_assert($row['INDEX_NAME'] === $name && (string)$row['NON_UNIQUE'] === (string)$nonUnique
                && (string)$row['SEQ_IN_INDEX'] === (string)($position + 1) && $row['COLUMN_NAME'] === $column
                && $row['SUB_PART'] === null && $row['INDEX_TYPE'] === 'BTREE' && $row['IS_VISIBLE'] === 'YES'
                && $row['EXPRESSION'] === null && $row['COLLATION'] === 'A' && $row['INDEX_COMMENT'] === '', 'CURRENT_UPGRADE_EXACT_NEW_REFUND_INDEX');
        }
        unset($actual['indexes'][$name]);
    }
    $wanted = ['uq_ms4a_refund_apply' => 'UNIQUE', 'eb_mm4a_refund_apply_fk' => 'FOREIGN KEY',
        'eb_mm4a_refund_last_fk' => 'FOREIGN KEY', 'eb_mm4a_refund_v1' => 'CHECK']; $remaining = []; $seen = [];
    foreach ($actual['constraints'] as $row) {
        $name = $row['CONSTRAINT_NAME'];
        if (!isset($wanted[$name])) { $remaining[] = $row; continue; }
        m2f_assert(!isset($seen[$name]) && $row === ['CONSTRAINT_NAME' => $name, 'CONSTRAINT_TYPE' => $wanted[$name], 'ENFORCED' => 'YES'], 'CURRENT_UPGRADE_EXACT_NEW_REFUND_CONSTRAINT'); $seen[$name] = true;
    }
    m2f_assert(count($seen) === 4, 'CURRENT_UPGRADE_FOUR_NEW_REFUND_CONSTRAINTS'); $actual['constraints'] = $remaining;
    $remaining = []; $seen = [];
    foreach ($actual['foreign'] as $row) {
        $name = $row['CONSTRAINT_NAME']; $columns = ['eb_mm4a_refund_apply_fk' => 'marketplace_apply_action_id', 'eb_mm4a_refund_last_fk' => 'marketplace_last_action_id'];
        if (!isset($columns[$name])) { $remaining[] = $row; continue; }
        m2f_assert(!isset($seen[$name]) && (string)$row['ORDINAL_POSITION'] === '1' && $row['COLUMN_NAME'] === $columns[$name]
            && $row['REFERENCED_TABLE_SCHEMA'] === 'audit_auth' && $row['REFERENCED_TABLE_NAME'] === 'eb_marketplace_aftersale_action'
            && $row['REFERENCED_COLUMN_NAME'] === 'id' && $row['UPDATE_RULE'] === 'RESTRICT' && $row['DELETE_RULE'] === 'RESTRICT'
            && $row['MATCH_OPTION'] === 'NONE', 'CURRENT_UPGRADE_TWO_EXACT_REFUND_FOREIGN_KEYS'); $seen[$name] = true;
    }
    m2f_assert(count($seen) === 2, 'CURRENT_UPGRADE_TWO_REFUND_FOREIGN_KEYS'); $actual['foreign'] = $remaining;
    $remaining = []; $count = 0;
    foreach ($actual['checks'] as $row) {
        if ($row['CONSTRAINT_NAME'] !== 'eb_mm4a_refund_v1') { $remaining[] = $row; continue; }
        // The original aftersale stage20 CLI checks this expression with its finite AST parser.
        m2f_assert($row['ENFORCED'] === 'YES' && is_string($row['CHECK_CLAUSE']) && $row['CHECK_CLAUSE'] !== '', 'CURRENT_UPGRADE_REFUND_ENFORCED_CHECK'); ++$count;
    }
    m2f_assert($count === 1, 'CURRENT_UPGRADE_ONE_NEW_REFUND_CHECK'); $actual['checks'] = $remaining;
    m2f_assert($actual === $before, 'CURRENT_UPGRADE_ALL_ORIGINAL_REFUND_METADATA_PRESERVED'); return $nulls;
}
function m4iu_preserved(array $before, array $after, array $newNames, bool $aftersale): array
{
    $old = $before['snapshot']; $actual = $after['snapshot']; $wanted = array_merge($old['names'], $newNames); sort($wanted, SORT_STRING);
    m2f_assert($actual['names'] === $wanted && array_keys($actual['tables']) === $wanted && $actual['modes'] === $old['modes'], 'CURRENT_UPGRADE_EXACT_NAMESPACE_AND_SQL_MODES');
    m4iu_cachePreserved($old['redis'], $after); $nulls = []; $db = audit_db();
    foreach ($old['tables'] as $name => $row) {
        m2f_assert($after['original_projection'][$name] === $row['projection'], 'CURRENT_UPGRADE_FULL_ORIGINAL_COLUMN_ROWS');
        if ($name === 'eb_store_order_refund' && $aftersale) $nulls = m4iu_refundPreserved($row['metadata'], $actual['tables'][$name]['metadata'], $db);
        else m2f_assert($actual['tables'][$name] === $row, 'CURRENT_UPGRADE_ALL_NONTARGET_DDL_METADATA_ROWS_AI');
    }
    foreach ($newNames as $name) if ($name !== 'eb_marketplace_schema')
        m2f_assert($actual['tables'][$name]['projection']['rows'] === 0, 'CURRENT_UPGRADE_SIX_NEW_BUSINESS_TABLES_EMPTY');
    return $nulls;
}
function m4iu_schema(PDO $db, array $snapshot): array
{
    $manifest = \crmeb\services\marketplace\SchemaVersionGate::manifest('/workspace/crmeb/marketplace-release.json');
    m2f_assert($manifest === ['product' => 'generic-multi-merchant', 'app_version' => '0.1.0', 'schema_min' => 1, 'schema_max' => 1], 'CURRENT_UPGRADE_FIXED_RELEASE_DESCRIPTOR');
    $row = \crmeb\services\marketplace\SchemaVersionGate::assertCompatible($db, 'eb_', $manifest);
    m2f_assert($row['id'] === 1 && $row['schema_version'] === 1 && $row['min_app_version'] === '0.1.0'
        && is_int($row['updated_at']) && $row['updated_at'] > 0 && $row['updated_at'] <= 4294967295, 'CURRENT_UPGRADE_EXACT_SCHEMA_SINGLETON');
    $table = $snapshot['tables']['eb_marketplace_schema']; $meta = $table['metadata'];
    m2f_assert($table['projection']['rows'] === 1 && $meta['table']['ROW_FORMAT'] === 'Dynamic'
        && $meta['table']['CREATE_OPTIONS'] === '' && $meta['triggers'] === [], 'CURRENT_UPGRADE_COMPLETE_SCHEMA_METADATA');
    foreach ($meta['columns'] as $column) m2f_assert($column['COLUMN_COMMENT'] === '', 'CURRENT_UPGRADE_SCHEMA_COLUMN_COMMENT');
    m2f_assert($meta['indexes']['PRIMARY'][0]['INDEX_COMMENT'] === '' && $meta['indexes']['PRIMARY'][0]['COLLATION'] === 'A', 'CURRENT_UPGRADE_SCHEMA_INDEX_METADATA');
    m2f_assert($meta['constraints'] === [
        ['CONSTRAINT_NAME' => 'eb_mm_schema_id', 'CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES'],
        ['CONSTRAINT_NAME' => 'eb_mm_schema_positive', 'CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES'],
        ['CONSTRAINT_NAME' => 'PRIMARY', 'CONSTRAINT_TYPE' => 'PRIMARY KEY', 'ENFORCED' => 'YES']], 'CURRENT_UPGRADE_SCHEMA_EXACT_CONSTRAINT_NAMESPACE');
    return $row;
}
function m4iu_upgrade(array &$evidence, ?array &$primary, ?array &$cleanup): void
{
    m4i_gate('current-structure-upgrade');
    // Definitions only: this gate precedes registration, the real166 observation and any new CLI.
    require_once '/run/ms4-prep/legacy-app.php';
    require_once '/run/ms4-prep/legacy-current-reader.php';
    $contextFile = nhecr_public_read('/run/audit/actual-run-context.json');
    $freezeFile = nhecr_public_read('/run/audit/preparation-source-binding.json');
    $context = json_decode($contextFile['raw'], true, 128, JSON_THROW_ON_ERROR);
    $freeze = json_decode($freezeFile['raw'], true, 256, JSON_THROW_ON_ERROR);
    $sourceProofs = []; nhecr_source_handoff($context, $freeze, '/run/ms4-prep', $sourceProofs);
    $trees = m4ir_controls($context, $freeze);
    $proofs = []; $handoff = nhecr_public_handoff($context, $proofs); $proofs += $sourceProofs;
    $proofs['/run/audit/actual-run-context.json'] = $contextFile;
    m2f_assert($proofs['/run/audit/preparation-source-binding.json'] === $freezeFile, 'CURRENT_UPGRADE_ORIGINAL_COMPLETE_CONTEXT_FREEZE_HELD');
    $base = '/run/audit/evidence/'; nhecu_retired($context, 'legacy-history-producer', [], $proofs);
    nhecu_retired($context, 'driver', ['producer_retired' => $proofs[$base . 'legacy-history-producer-retired.json']['pin']], $proofs);
    $oldFile = nhecr_public_read('/run/audit/manifest.json');
    $oldManifest = json_decode($oldFile['raw'], true, 128, JSON_THROW_ON_ERROR);
    $oldNames = array_keys($oldManifest['services']); sort($oldNames, SORT_STRING);
    m2f_assert($oldNames === ['audit-ms4-migration', 'audit-mysql', 'audit-redis']
        && $oldManifest['run_id'] === $context['run_id'] && $oldManifest['project'] === $context['project']
        && $oldManifest['commit_sha'] === $context['source_sha'] && $oldManifest['guard_mode'] === 'read-only'
        && $oldManifest['network_internal'] === true && $oldManifest['network']['id'] === $context['network_id']
        && $oldManifest['services']['audit-ms4-migration']['id'] === $handoff['migration']['driver_container_id'],
        'CURRENT_UPGRADE_ONLY_REAL_COMPLETED_DRIVER_MANIFEST');
    foreach (['audit-mysql', 'audit-redis'] as $role)
        m2f_assert($oldManifest['services'][$role]['id'] === $context['initial_service_ids'][$role], 'CURRENT_UPGRADE_ORIGINAL_BASE_CIDS_BEFORE_REGISTER');
    nhecr_manifest_stages($context, $oldManifest, $proofs, false); nhecu_held($proofs);
    m2f_assert(nhecr_public_read('/run/audit/manifest.json') === $oldFile, 'CURRENT_UPGRADE_ORIGINAL_MANIFEST_HELD_UNTIL_REGISTER');
    $registrationLevel = ob_get_level(); $registration = null;
    ob_start();
    try { audit_register_resources(); $registration = ob_get_contents(); }
    finally { while (ob_get_level() > $registrationLevel) ob_end_clean(); }
    m2f_assert($registration === "Registered dedicated internal network and container identities\n",
        'CURRENT_UPGRADE_ACTUAL_RESOURCE_REGISTRATION');
    $registered = audit_manifest(); $expectedManifest = $oldManifest; $expectedManifest['services'] = $registered['services'];
    m2f_assert($registered === $expectedManifest, 'CURRENT_UPGRADE_REGISTER_ONLY_SERVICES_ALL_OTHER_FIELDS_HELD');
    foreach (['audit-mysql', 'audit-redis'] as $role)
        m2f_assert($registered['services'][$role]['id'] === $oldManifest['services'][$role]['id'], 'CURRENT_UPGRADE_REGISTER_SAME_ORIGINAL_BASE_CIDS');
    $evidence['scope'] = 'CURRENT_READER_STRUCTURE_UPGRADE_ONLY'; $evidence['binding'] = m4iu_source();
    $evidence['current_structure_upgrade'] = ['schema' => 1, 'expected_before_table_count' => 166, 'expected_after_table_count' => 173,
        'source_files' => m4iu_sourceFiles(), 'snapshots' => [], 'original_four_cli_unchanged' => false];
    $driverPath = '/run/audit/evidence/ms4-migration-normal.json'; $driverRaw = $proofs[$driverPath]['raw'];
    $driver = json_decode($driverRaw, true, 256, JSON_THROW_ON_ERROR);
    m2f_assert($driver['status'] === 'VERIFIED_NORMAL_STRUCTURE_ONLY' && $driver['native_exit'] === 0
        && $driver['private_cleanup_verified'] === true && count($driver['children']) === 4, 'CURRENT_UPGRADE_ORIGINAL_FOUR_CLI_RESULT');
    $evidence['current_structure_upgrade']['original_four_cli'] = ['path' => $driverPath, 'bytes' => strlen($driverRaw), 'sha256' => hash('sha256', $driverRaw)];
    $before = m4iu_capture([]); $old = $before['snapshot']; $driverAfter = $handoff['driver_after']; $columns = [];
    m2f_assert(count($old['names']) === 166 && $old['names'] === $driverAfter['names'] && $old['modes'] === $driverAfter['modes'], 'CURRENT_UPGRADE_REAL_FULL166_BASELINE');
    foreach ($old['tables'] as $name => $row) {
        $expected = $driverAfter['tables'][$name]; $columns[$name] = $row['projected_columns'];
        m2f_assert($row['metadata'] === $expected['metadata'] && $row['ddl_sha256'] === $expected['ddl_sha256']
            && m4m_projection(audit_db(), $name, $expected['projected_columns']) === $expected['projection'], 'CURRENT_UPGRADE_ORIGINAL_DRIVER_OLD_COLUMN_PROJECTION_JOIN');
        $before['original_projection'][$name] = $row['projection'];
    }
    m4iu_cachePreserved($driverAfter['redis'], $before);
    $evidence['current_structure_upgrade']['snapshots']['before'] = $before;
    $evidence['current_structure_upgrade']['before_table_count'] = count($old['names']);
    $directory = '/tmp/current-structure-upgrade-' . getenv('AUDIT_RUN_ID'); $directories = []; $owned = []; $failure = null;
    try {
        $directories[$directory] = m2f_dir($directory, true); m2f_file('/run/audit-bootstrap/migrator.json');
        $migrator = json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'), true, 16, JSON_THROW_ON_ERROR);
        m2f_assert(($migrator['username'] ?? null) === 'audit_migrator' && is_string($migrator['password'] ?? null), 'CURRENT_UPGRADE_ORIGINAL_DEDICATED_MIGRATOR');
        $db = audit_pdo($migrator); $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        m2f_assert($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && !$db->getAttribute(PDO::ATTR_PERSISTENT)
            && !$db->inTransaction() && count($grants) === 2
            && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT USAGE ON *.* TO '))) === 1
            && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT ALL PRIVILEGES ON `audit_auth`.* TO ') && !str_contains($g, 'GRANT OPTION'))) === 1
            && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')], 'CURRENT_UPGRADE_ORIGINAL_MIGRATOR_GRANTS_AND_SENTINEL');
        $config = $directory . '/config.json';
        m2f_new($config, m2f_json(['hostname' => 'audit-mysql', 'hostport' => '3306', 'database' => 'audit_auth',
            'username' => $migrator['username'], 'password' => $migrator['password'], 'prefix' => 'eb_']));
        $owned[$config] = m2f_file($config); unset($migrator);
        $stat = lstat($config); m2f_assert($stat['nlink'] === 1 && realpath($config) === $config && realpath($directory) === $directory, 'CURRENT_UPGRADE_SINGLE_LINK_PRIVATE_CONFIG');
        require_once '/workspace/crmeb/crmeb/exceptions/MarketplaceSchemaException.php';
        require_once '/workspace/crmeb/crmeb/services/marketplace/SchemaVersionGate.php';
        $newNames = []; $previous = $before;
        foreach ([['eb_marketplace_payment_registry', 'eb_marketplace_payment_attempt'],
            ['eb_marketplace_fulfillment_operation', 'eb_marketplace_fulfillment_range'],
            ['eb_marketplace_aftersale_action', 'eb_marketplace_aftersale_file']] as $group => $new) {
            foreach (range(0, 3) as $step) {
                $result = m4iu_child($config, $evidence); m4iu_structureReceipt($result, $group, $step);
                $current = m4iu_capture($columns);
                if ($step === 1) {
                    m4iu_existingTables($previous, $current, $group === 2 ? ['eb_store_order_refund', 'eb_marketplace_fulfillment_operation'] : []);
                    $newNames = array_merge($newNames, $new);
                    $nulls = m4iu_preserved($before, $current, $newNames, $group === 2);
                    if ($group === 2) $evidence['current_structure_upgrade']['refund_null_counts'] = $nulls;
                } else m4iu_unchanged($previous, $current);
                $evidence['current_structure_upgrade']['snapshots']['cli_' . count($evidence['children'])] = $current; $previous = $current;
                m2f_assert(m2f_file($config) === $owned[$config], 'CURRENT_UPGRADE_PRIVATE_CONFIG_IDENTITY_HELD');
            }
        }
        $schemaRow = null;
        foreach (['apply', 'check', 'apply', 'check'] as $step => $mode) {
            $result = m4iu_child($config, $evidence); m4iu_keys($result, ['mode', 'state', 'changed', 'code', 'schema']);
            m2f_assert($result['mode'] === $mode && $result['state'] === ['created', 'compatible', 'unchanged', 'compatible'][$step]
                && $result['changed'] === ($step === 0) && $result['code'] === null, 'CURRENT_UPGRADE_EXACT_MS6_NORMAL_RECEIPT');
            $current = m4iu_capture($columns); $row = m4iu_schema($db, $current['snapshot']);
            if ($step === 0) {
                m4iu_existingTables($previous, $current, []);
                $newNames[] = 'eb_marketplace_schema'; m4iu_preserved($before, $current, $newNames, true);
                $child = $evidence['children'][12]; m2f_assert($row['updated_at'] >= $child['started_at']
                    && $row['updated_at'] <= $child['finished_at'], 'CURRENT_UPGRADE_SCHEMA_REAL_CREATE_TIME_WINDOW');
                $schemaRow = $row; $evidence['current_structure_upgrade']['schema_create_window'] = [$child['started_at'], $child['finished_at']];
            } else { m4iu_unchanged($previous, $current); m2f_assert($row === $schemaRow, 'CURRENT_UPGRADE_SCHEMA_SINGLETON_UNCHANGED'); }
            m2f_assert($result['schema'] === $row, 'CURRENT_UPGRADE_SCHEMA_RECEIPT_INDEPENDENT_PDO_READBACK');
            $evidence['current_structure_upgrade']['snapshots']['cli_' . count($evidence['children'])] = $current; $previous = $current;
        }
        m2f_assert(count($evidence['children']) === 16 && count(array_unique(array_column($evidence['children'], 'pid'))) === 16
            && count($previous['snapshot']['names']) === 173, 'CURRENT_UPGRADE_SIXTEEN_NATIVE_CHILDREN_EXACT173');
        $evidence['current_structure_upgrade']['schema_row'] = $schemaRow;
        $evidence['current_structure_upgrade']['snapshots']['after'] = $previous;
        $evidence['current_structure_upgrade']['after_table_count'] = count($previous['snapshot']['names']);
        m2f_assert(nhecr_public_read($driverPath) === $proofs[$driverPath] && m2f_file($config) === $owned[$config]
            && m4iu_source() === $evidence['binding'], 'CURRENT_UPGRADE_ORIGINAL_DRIVER_SOURCE_CONFIG_HELD');
        $evidence['current_structure_upgrade']['original_four_cli_unchanged'] = true;
    } catch (Throwable $error) {
        $failure = $error; $primary = m2f_diagnostic($error, 'current-structure-upgrade');
        // No rollback/drop/compensating DML: retain real partial DDL and its native evidence.
        try { $evidence['current_structure_upgrade']['failure_snapshot'] = m4iu_capture($columns); }
        catch (Throwable $observation) { $evidence['current_structure_upgrade']['failure_snapshot_diagnostic'] = m2f_diagnostic($observation, 'partial_structure_observation'); }
    }
    finally {
        try { m2f_cleanup($directory, $directories, $owned, $evidence); }
        catch (Throwable $error) { $cleanup = m2f_diagnostic($error, 'private_cleanup'); $evidence['cleanup_failure'] = $cleanup; if ($failure === null) $failure = $error; }
    }
    if ($failure !== null) throw $failure;
    nhecu_held($proofs); nhecr_source_handoff($context, $freeze, '/run/ms4-prep', $proofs);
    m2f_assert(m4ir_controls($context, $freeze) === $trees, 'CURRENT_UPGRADE_ALL_CURRENT_SOURCE_AND_CONTROLS_HELD');
}
'''


_READER_REGISTRATION_FUNCTIONS = rb'''
/** Read the same fixed twelve controls through the registration helper's actual RO mount. */
function m4ir_controls(array $context, array $freeze): array
{
    $current = $freeze['current_reader']; $authority = $freeze['nonempty_history'];
    nhecr_keys($current, ['schema', 'source_sha', 'source_files', 'source_directories', 'control_files']);
    m2f_assert($current['schema'] === 1 && $current['source_sha'] === $context['source_sha']
        && $authority['legacy_source_sha'] === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
        && is_array($authority['control_files']) && array_is_list($authority['control_files']) && count($authority['control_files']) === 11
        && is_array($current['control_files']) && array_is_list($current['control_files']), 'CURRENT_READER_SEPARATE_LEGACY_AND_CURRENT_AUTHORITY');
    $paths = ['/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-current-reader.php',
        '/run/nonempty/legacy-files-bootstrap.php', '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php',
        '/run/nonempty/legacy-history-producer.php', '/run/nonempty/legacy-history-router.php', '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'];
    m2f_assert(array_column($current['control_files'], 'path') === $paths, 'CURRENT_READER_EXACT_CURRENT_TWELVE_CONTROL_PATHS');
    $artifacts = array_column($freeze['artifact_files'], null, 'path'); $producer = [];
    m2f_assert(count($artifacts) === 14 && count($freeze['artifact_files']) === 14, 'CURRENT_READER_REAL_FOURTEEN_ARTIFACTS');
    foreach ($current['control_files'] as $pin) {
        nhecr_keys($pin, ['path', 'bytes', 'sha256']); $path = $pin['path'];
        if ($path !== '/run/nonempty/legacy-current-reader.php') $producer[] = $pin;
        if (str_starts_with($path, '/run/nonempty/')) {
            $leaf = substr($path, strlen('/run/nonempty/'));
            m2f_assert(isset($artifacts[$leaf]) && array_replace($artifacts[$leaf], ['path' => $path]) === $pin,
                'CURRENT_READER_EACH_ORIGINAL_CONTROL_ARTIFACT_JOIN');
            $path = '/run/ms4-prep/' . $leaf;
        }
        nhe_file($path, array_replace($pin, ['path' => $path]));
    }
    m2f_assert($producer === $authority['control_files'], 'CURRENT_READER_ALL_ORIGINAL_ELEVEN_CONTROL_PINS_HELD');
    $trees = []; foreach (['/workspace', '/source'] as $root)
        $trees[$root] = nhe_tree($root, $current['source_files'], $current['source_directories']);
    return $trees;
}
/** Exact current Host archive: only the pre-create containers collection differs from the later live collection. */
function m4ir_archive(array $context, array $freeze, string $oldManifest, array $proofs): array
{
    $root = '/run/audit/evidence/nonempty-history-phases/before-current-reader';
    $indexPath = $root . '/INDEX.json'; $stat = lstat($indexPath);
    m2f_assert(is_array($stat) && is_file($indexPath) && !is_link($indexPath) && realpath($indexPath) === $indexPath
        && $stat['nlink'] === 1 && $stat['size'] > 0 && $stat['size'] <= 1048576, 'CURRENT_READER_REGULAR_COMPLETE_ARCHIVE_INDEX');
    $indexPin = ['path' => $indexPath, 'bytes' => $stat['size'], 'sha256' => hash_file('sha256', $indexPath)];
    $indexRaw = nhe_file($indexPath, $indexPin); $index = json_decode($indexRaw, true, 128, JSON_THROW_ON_ERROR);
    nhecr_keys($index, ['schema', 'phase', 'run_id', 'source_sha', 'legacy_source_sha', 'files', 'business_acceptance']);
    m2f_assert($index['schema'] === 1 && $index['phase'] === 'before-current-reader'
        && $index['run_id'] === $context['run_id'] && $index['source_sha'] === $context['source_sha']
        && $index['legacy_source_sha'] === $freeze['nonempty_history']['legacy_source_sha']
        && $index['business_acceptance'] === 'NOT RUN', 'CURRENT_READER_REAL_BEFORE_REGISTER_ARCHIVE');
    $leaves = ['manifest.json', 'network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl',
        'actual-run-context.json', 'preparation-source-binding.json',
        'evidence/legacy-history-producer-created.json', 'evidence/legacy-history-producer-terminal.json',
        'evidence/audit-legacy-history-producer-container-created.json',
        'evidence/audit-legacy-history-producer-source-binding.json', 'evidence/legacy-history-producer-result.json',
        'evidence/legacy-history-producer-retired.json', 'evidence/legacy-history-producer-retired-resources.json',
        'evidence/register-legacy-history-producer-created.json', 'evidence/register-legacy-history-producer-terminal.json',
        'evidence/register-legacy-history-producer-result.json',
        'evidence/driver-created.json', 'evidence/driver-terminal.json', 'evidence/ms4-migration-container-created.json',
        'evidence/ms4-migration-source-binding.json', 'evidence/ms4-migration-normal.json',
        'evidence/driver-retired.json', 'evidence/driver-retired-resources.json',
        'evidence/register-driver-created.json', 'evidence/register-driver-terminal.json', 'evidence/register-driver-result.json'];
    foreach (['created.json', 'terminal.json', 'result.json', 'shutdown.json', 'retired.json', 'retired-resources.json'] as $suffix)
        $leaves[] = 'evidence/current-structure-upgrade-' . $suffix;
    foreach (range(1, 16) as $number) foreach (['created.json', 'terminal.json', 'stdout', 'stderr'] as $suffix)
        $leaves[] = sprintf('evidence/current-structure-upgrade-cli-%02d.%s', $number, $suffix);
    foreach (['legacy-history-producer', 'driver', 'current-structure-upgrade'] as $proof) {
        $path = '/run/audit/evidence/' . $proof . '-retired.json';
        $receipt = json_decode($proofs[$path]['raw'], true, 128, JSON_THROW_ON_ERROR);
        m2f_assert(preg_match('~\A/run/audit/evidence/native-[0-9]{4,}\.json\z~D', $receipt['normal_remove']['path']) === 1,
            'CURRENT_READER_ARCHIVE_ONLY_REAL_REMOVE_NATIVE_POINTER');
        $leaves[] = substr($receipt['normal_remove']['path'], strlen('/run/audit/'));
    }
    $leaves[] = 'history-source-review.json'; $leaves[] = 'history-source-publication.json';
    m2f_assert(is_array($index['files']) && array_is_list($index['files'])
        && count($leaves) === 102 && count($leaves) === count(array_unique($leaves, SORT_STRING))
        && array_column($index['files'], 'path') === $leaves, 'CURRENT_READER_COMPLETE_EXACT_HOST_ARCHIVE_CLOSED_SET');
    $retired = json_decode($proofs['/run/audit/evidence/current-structure-upgrade-retired-resources.json']['raw'], true, 128, JSON_THROW_ON_ERROR);
    $held = [];
    foreach ($index['files'] as $row) {
        nhecr_keys($row, ['path', 'bytes', 'sha256', 'original_stat']);
        nhecr_keys($row['original_stat'], ['st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns']);
        foreach ($row['original_stat'] as $value) m2f_assert(is_int($value), 'CURRENT_READER_ORIGINAL_HOST_STAT_INTEGER');
        $relative = $row['path']; $pin = ['path' => $root . '/' . $relative, 'bytes' => $row['bytes'], 'sha256' => $row['sha256']];
        m2f_assert(is_int($row['bytes']) && $row['bytes'] >= 0 && $row['bytes'] <= 67108864
            && ($row['bytes'] > 0 || preg_match('/\.(stdout|stderr)\z/D', $relative) === 1)
            && $row['original_stat']['st_size'] === $row['bytes'] && $row['original_stat']['st_nlink'] === 1,
            'CURRENT_READER_ORIGINAL_ARCHIVE_NATIVE_SIZE_AND_SINGLE_LINK');
        $native = lstat($pin['path']);
        m2f_assert(is_array($native) && $native['nlink'] === 1 && realpath($pin['path']) === $pin['path'], 'CURRENT_READER_REGULAR_ARCHIVE_MEMBER');
        $raw = nhe_file($pin['path'], $pin); $held[$relative] = ['pin' => $pin, 'raw' => $raw];
        if ($relative === 'containers.jsonl') {
            m2f_assert($raw === $retired['public_raw']['containers.jsonl'], 'CURRENT_READER_ARCHIVED_PRECREATE_TWO_BASES_MATCH_REAL_UPGRADE_RETIREMENT');
        } else {
            m2f_assert(nhe_file('/run/audit/' . $relative, array_replace($pin, ['path' => '/run/audit/' . $relative])) === $raw,
                'CURRENT_READER_ARCHIVED_ORIGINAL_FULL_BYTES');
        }
        if (in_array($relative, ['network.json', 'volumes.txt', 'volumes.jsonl'], true))
            m2f_assert($raw === $retired['public_raw'][$relative], 'CURRENT_READER_ARCHIVED_ORIGINAL_NETWORK_AND_SIX_VOLUMES');
    }
    m2f_assert($held['manifest.json']['raw'] === $oldManifest, 'CURRENT_READER_OLD_MANIFEST_SAVED_BEFORE_REGISTER');
    return ['index_pin' => $indexPin, 'index_raw' => $indexRaw, 'files' => $held];
}
/** Donor's real registration, with the current twelve controls and completed structure upgrade. */
function m4i_currentReaderRegistration(): void
{
    m4i_gate('register');
    $context = m4i_read('/run/audit/actual-run-context.json'); $freeze = m4i_read('/run/audit/preparation-source-binding.json');
    // Both files contain definitions only on this CLI filename. Do not invoke reader qualification here.
    require_once '/run/ms4-prep/legacy-app.php';
    require_once '/run/ms4-prep/legacy-current-reader.php';
    $sourceProofs = []; nhecr_source_handoff($context, $freeze, '/run/ms4-prep', $sourceProofs);
    $trees = m4ir_controls($context, $freeze);
    $oldRaw = file_get_contents('/run/audit/manifest.json'); $old = json_decode($oldRaw, true, 128, JSON_THROW_ON_ERROR);
    m2f_assert($old['run_id'] === $context['run_id'] && $old['project'] === $context['project']
        && $old['commit_sha'] === $context['source_sha'] && $old['guard_mode'] === 'read-only'
        && $old['network_internal'] === true && $old['network']['id'] === $context['network_id'], 'CURRENT_READER_REGISTER_ORIGINAL_READ_ONLY_MANIFEST');
    $oldHistory = $old['nonempty_history']; $oldStages = array_keys($oldHistory['stages']); sort($oldStages, SORT_STRING);
    m2f_assert($oldStages === ['audit-legacy-files-bootstrap', 'audit-legacy-history-producer'], 'CURRENT_READER_ONLY_TWO_COMPLETED_OLD_STAGES');
    $proofs = []; $handoff = nhecr_public_handoff($context, $proofs); $actorProofs = [];
    $proofs += $sourceProofs; nhecr_manifest_stages($context, $old, $proofs, false);
    $upgrade = nhecu_upgradePublic($context, $freeze, $handoff, $proofs, '/run/ms4-prep');
    $actorReceipt = nhecr_actor_prepare_receipt($context, $actorProofs);
    $archive = m4ir_archive($context, $freeze, $oldRaw, $proofs);
    audit_guard_assert(); $before = m4iu_capture([]); $expected = $upgrade['after']['snapshot'];
    foreach (['names', 'tables', 'modes'] as $key)
        m2f_assert($before['snapshot'][$key] === $expected[$key], 'CURRENT_READER_REGISTER_ACTUAL_FULL173_UPGRADE_BASELINE');
    m2f_assert(count($expected['names']) === 173, 'CURRENT_READER_REGISTER_EXACT_UPGRADED_TABLE_COUNT');
    m4iu_cachePreserved($expected['redis'], $before);
    foreach (array_slice(nhecu_newTables(), 0, 6) as $name)
        m2f_assert($before['snapshot']['tables'][$name]['projection']['rows'] === 0, 'CURRENT_READER_REGISTER_SIX_NEW_EMPTY_BUSINESS_TABLES');
    foreach (array_keys(m4iu_refundColumns()) as $column)
        m2f_assert((int)audit_db()->query('SELECT COUNT(*) FROM eb_store_order_refund WHERE ' . m4m_quote($column) . ' IS NOT NULL')->fetchColumn() === 0,
            'CURRENT_READER_REGISTER_ORIGINAL_REFUNDS_EIGHT_NULL_COLUMNS');
    require_once '/workspace/crmeb/crmeb/exceptions/MarketplaceSchemaException.php';
    require_once '/workspace/crmeb/crmeb/services/marketplace/SchemaVersionGate.php';
    m2f_assert(m4iu_schema(audit_db(), $before['snapshot']) === $upgrade['schema_row'], 'CURRENT_READER_REGISTER_REAL_UNCHANGED_SCHEMA_SINGLETON');
    audit_register_resources(); $manifest = audit_manifest(); $role = 'audit-legacy-current-reader';
    $names = array_keys($manifest['services']); sort($names, SORT_STRING);
    m2f_assert($names === [$role, 'audit-mysql', 'audit-redis'] && $manifest['nonempty_history'] === $oldHistory,
        'CURRENT_READER_REGISTER_ONLY_CURRENT_THREE_ROLES_OLD_STAGES_HELD');
    $expectedManifest = $old; $expectedManifest['services'] = $manifest['services'];
    m2f_assert($manifest === $expectedManifest, 'CURRENT_READER_REGISTER_ONLY_SERVICES_VIEW_CHANGED');
    foreach (['audit-mysql', 'audit-redis'] as $base)
        m2f_assert($manifest['services'][$base]['id'] === $old['services'][$base]['id'], 'CURRENT_READER_ORIGINAL_BASE_CIDS');
    $base = '/run/audit/evidence/' . $role;
    $madeFile = nhecr_public_read($base . '-container-created.json'); $bindingFile = nhecr_public_read($base . '-source-binding.json');
    $madeRaw = $madeFile['raw']; $bindingRaw = $bindingFile['raw'];
    $made = json_decode($madeRaw, true, 128, JSON_THROW_ON_ERROR); $binding = json_decode($bindingRaw, true, 128, JSON_THROW_ON_ERROR);
    m2f_assert(nhecu_same($made, m4i_read('/run/audit/evidence/legacy-current-reader-created.json')), 'CURRENT_READER_REAL_ORIGINAL_CREATED_ALIAS');
    nhecr_created_facts($context, $freeze, $made, $binding, $manifest, $actorReceipt);
    m2f_assert($binding['prerequisites'] === [
        'producer_retired' => $proofs['/run/audit/evidence/legacy-history-producer-retired.json']['pin'],
        'driver_retired' => $proofs['/run/audit/evidence/driver-retired.json']['pin'],
        'upgrade_retired' => $proofs['/run/audit/evidence/current-structure-upgrade-retired.json']['pin']], 'CURRENT_READER_ORIGINAL_THREE_NORMAL_RETIRES_BOUND');
    $stagePins = ['created' => $madeFile['pin'], 'binding' => $bindingFile['pin']];
    foreach ($stagePins as $pin) nhe_file($pin['path'], $pin);
    $manifest['nonempty_history']['stages'][$role] = $stagePins;
    audit_write_json('/run/audit/manifest.json', $manifest);
    m2f_assert(audit_manifest() === $manifest
        && array_diff_key($manifest['nonempty_history']['stages'], [$role => true]) === $oldHistory['stages'],
        'CURRENT_READER_NEW_STAGE_ONLY_OLD_STAGES_COMPLETE_READBACK');
    audit_guard_assert(); $after = m4iu_capture([]); m4iu_unchanged($before, $after);
    m2f_assert(nhecr_public_read($base . '-container-created.json') === $madeFile
        && nhecr_public_read($base . '-source-binding.json') === $bindingFile, 'CURRENT_READER_REGISTER_ORIGINAL_CREATED_BINDING_HELD');
    nhecu_held($proofs);
    foreach ($actorProofs as $path => $proof) m2f_assert(nhecr_public_read($path) === $proof, 'CURRENT_READER_ALL_ACTOR_PREPARE_PUBLIC_BYTES_HELD');
    foreach ($archive['files'] as $relative => $row) {
        m2f_assert(nhe_file($row['pin']['path'], $row['pin']) === $row['raw'], 'CURRENT_READER_COMPLETE_ORIGINAL_ARCHIVE_HELD');
        if (!in_array($relative, ['manifest.json', 'containers.jsonl'], true))
            m2f_assert(nhe_file('/run/audit/' . $relative, array_replace($row['pin'], ['path' => '/run/audit/' . $relative])) === $row['raw'],
                'CURRENT_READER_ALL_OTHER_ORIGINAL_PUBLIC_INPUTS_HELD');
    }
    m2f_assert(nhe_file($archive['index_pin']['path'], $archive['index_pin']) === $archive['index_raw']
        && m4ir_controls($context, $freeze) === $trees, 'CURRENT_READER_ARCHIVE_INDEX_AND_COMPLETE_CURRENT_SOURCE_HELD');
}
'''


# The original seed stays canonical; its full snapshot follows the completed MS2 structure.
DEFAULT_SHIPPING_SOURCE_PIN = (2723, '0155d6e6661fdb2899fe9f4ba51b88ab487aeef29d46ddc6eb40afa612f0c5f9')
_DEFAULT_SHIPPING_ORIGINAL = r'''function m4i_defaultShipping(array &$evidence): void
{
    audit_guard_assert(); $db = audit_db();
    $source = str_replace("\r", '', file_get_contents('/source/crmeb/public/install/crmeb.sql'));
    $seed = "INSERT INTO `eb_shipping_templates` (`id`, `name`, `type`, `appoint`, `no_delivery`, `sort`, `add_time`) VALUES\n"
        . "(1, '默认模版', 1, 0, 0, 0, 1658394592);";
    m2f_assert(substr_count($source, $seed) === 1, 'REAL_CANONICAL_DEFAULT_SHIPPING_SEED');
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $tables = array_fill_keys($names, null); $spec = ['tables' => $tables, 'redis' => ['*']];
    $metadataQuery = 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT'
        . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME';
    $before = audit_snapshot($spec); $metadata = $db->query($metadataQuery)->fetchAll(PDO::FETCH_ASSOC);
    $ddl = $db->query('SHOW CREATE TABLE eb_shipping_templates')->fetch(PDO::FETCH_NUM);
    m2f_assert($before['tables']['eb_shipping_templates']['count'] === 0
        && $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_shipping_templates'")->fetchColumn() === 2
        && is_array($ddl) && is_string($ddl[1] ?? null), 'FRESH_ORIGINAL_DEFAULT_SHIPPING_TABLE');
    $row = ['id' => 1, 'name' => '默认模版', 'type' => 1, 'appoint' => 0, 'no_delivery' => 0, 'sort' => 0, 'add_time' => 1658394592];
    $expected = $before;
    $expected['tables']['eb_shipping_templates'] = ['count' => 1,
        'sha256' => hash('sha256', hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))];
    m2f_assert($db->beginTransaction(), 'DEFAULT_SHIPPING_SEED_TRANSACTION');
    try {
        m2f_assert($db->exec($seed) === 1
            && $db->query('SELECT id,name,type,appoint,no_delivery,sort,add_time FROM eb_shipping_templates')->fetchAll(PDO::FETCH_ASSOC) === [$row],
            'COMPLETE_REAL_INSTALL_DEFAULT_SHIPPING_ROW');
        m2f_assert($db->commit(), 'DEFAULT_SHIPPING_SEED_COMMIT');
        m2f_assert(audit_snapshot($spec) === $expected && $db->query($metadataQuery)->fetchAll(PDO::FETCH_ASSOC) === $metadata
            && $db->query('SHOW CREATE TABLE eb_shipping_templates')->fetch(PDO::FETCH_NUM) === $ddl,
            'DEFAULT_SHIPPING_ONLY_ORIGINAL_SEED_DELTA');
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    $evidence['canonical_default_shipping_seed'] = ['rows' => 1, 'original_id' => 1,
        'counter_unchanged' => true, 'other_database_and_redis_unchanged' => true];
}

'''.encode('utf-8')


def _default_shipping_seam():
    before = _DEFAULT_SHIPPING_ORIGINAL
    if (len(before), hashlib.sha256(before).hexdigest()) != DEFAULT_SHIPPING_SOURCE_PIN:
        raise ValueError('DEFAULT_SHIPPING_EXACT_ORIGINAL_FUNCTION_REQUIRED')
    after = before
    changes = (
        (b"    $expected = $before;\n",
         b"    // MS2 has appended its declared nullable owner column before this canonical seed.\n"
         b"    $snapshotRow = $row + ['mer_id' => null];\n"
         b"    $expected = $before;\n"),
        (b"'sha256' => hash('sha256', hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))",
         b"'sha256' => hash('sha256', hash('sha256', json_encode($snapshotRow, JSON_THROW_ON_ERROR)))"),
        (b"            'COMPLETE_REAL_INSTALL_DEFAULT_SHIPPING_ROW');\n",
         b"            'COMPLETE_REAL_INSTALL_DEFAULT_SHIPPING_ROW');\n"
         b"        m2f_assert($db->query('SELECT * FROM eb_shipping_templates')->fetchAll(PDO::FETCH_ASSOC) === [$snapshotRow],\n"
         b"            'COMPLETE_REAL_INSTALL_DEFAULT_SHIPPING_MS2_ROW');\n"),
    )
    for old, new in changes:
        if after.count(old) != 1 or new in after:
            raise ValueError('DEFAULT_SHIPPING_UNIQUE_FORWARD_SEAM_REQUIRED')
        after = after.replace(old, new, 1)
    restored = after
    for old, new in reversed(changes):
        if restored.count(new) != 1:
            raise ValueError('DEFAULT_SHIPPING_UNIQUE_REVERSE_SEAM_REQUIRED')
        restored = restored.replace(new, old, 1)
    if restored != before:
        raise ValueError('DEFAULT_SHIPPING_COMPLETE_FUNCTION_REVERSE_REQUIRED')
    return before, after



_REFUND_CONTRACTS = rb'''function m4iu_testRefundReceipt(array $result,int $step,int $id,int $parent,string $migrationHash,string $ms1Hash): void
{
    m2f_assert($step>=0&&$step<=5&&$id>=1&&$id<=65534&&$parent>=1&&$parent<=65535
        &&preg_match('/\A[a-f0-9]{64}\z/D',$migrationHash)===1&&preg_match('/\A[a-f0-9]{64}\z/D',$ms1Hash)===1,
        'CURRENT_UPGRADE_REFUND_SIX_FIXED_STEPS_AND_IDENTITIES');
    if($step<3){
        $expected=['mode'=>['check','apply-structure','verify'][$step],'state'=>$step===0?'applicable':'completed',
            'native_exit'=>0,'changed'=>$step===1,'applied_steps'=>$step===1?['marketplace_refund_attempt']:[],
            'pending_steps'=>$step===0?['marketplace_refund_attempt']:[],'pending_steps_known'=>true,'issues'=>[],
            'failure_phase'=>null,'marker'=>'marketplace-ms5-refund-structure-v1','source_sha256'=>$migrationHash,
            'history_mapping'=>'NOT RUN','refund_producer'=>'NOT RUN','acceptance'=>'NOT RUN'];
    }else{
        $expected=['mode'=>$step===4?'apply-menus':'check-menus','state'=>$step===3?'applicable':'completed','changed'=>$step===4,
            'tables'=>[],'menu_ids'=>$step===3?[]:['marketplace-aftersales-test-refund'=>$id],'issues'=>[],
            'source_binding'=>['entry_sha256'=>$migrationHash,'ms1_sha256'=>$ms1Hash],
            'authorization_cache_producer'=>'fresh_scope_for_request_exact_all_auth_and_current_roles','roles_modified'=>false,
            'parent_id'=>$parent,'navigation_changed'=>false,
            'permission_scope'=>'EXISTING_REFUND_PAGE_OPERATION_LEAF_ONLY_STATIC_MARKETPLACE_ROUTE_UNCHANGED'];
        if($step===4)$expected['created_menu_ids']=['marketplace-aftersales-test-refund'=>$id];
        $expected['native_exit']=0;
    }
    m4iu_keys($result,array_keys($expected));
    m2f_assert($result===$expected,'CURRENT_UPGRADE_EXACT_REFUND_NORMAL_RECEIPT_BEFORE_NEXT_CLI');
}
function m4iu_refundMenuPreserved(array $before,array $current,array $proof,int $expectedId,int $expectedParent): void
{
    m4iu_keys($proof,['id','row','ddl_raw','old_projection']);
    m2f_assert($proof['id']===$expectedId&&is_int($expectedId)&&$expectedId>=1&&$expectedId<=65534
        &&is_int($expectedParent)&&$expectedParent>=1&&$expectedParent<=65535,
        'CURRENT_UPGRADE_SINGLE_EXPECTED_NATURAL_REFUND_MENU_ID');
    $counter=$before['metadata']['table']['AUTO_INCREMENT'];
    m2f_assert((is_int($counter)||is_string($counter))&&preg_match('/\A[1-9][0-9]*\z/D',(string)$counter)===1
        &&(int)$counter===$expectedId,'CURRENT_UPGRADE_ORIGINAL_MENU_COUNTER_IS_NEW_ID');
    $metadata=$current['metadata'];
    m2f_assert((string)$metadata['table']['AUTO_INCREMENT']===(string)($expectedId+1),'CURRENT_UPGRADE_ONLY_ONE_MENU_COUNTER_ADVANCE');
    $metadata['table']['AUTO_INCREMENT']=$before['metadata']['table']['AUTO_INCREMENT'];
    m2f_assert($metadata===$before['metadata']&&$current['projected_columns']===$before['projected_columns'],
        'CURRENT_UPGRADE_ALL_ORIGINAL_MENU_METADATA_TYPES_AND_COLUMN_ORDER');
    m2f_assert(is_string($proof['ddl_raw'])&&strlen($proof['ddl_raw'])>0&&strlen($proof['ddl_raw'])<=1048576
        &&hash('sha256',$proof['ddl_raw'])===$current['ddl_sha256'],'CURRENT_UPGRADE_FULL_NATIVE_MENU_DDL_BYTES');
    $old=str_replace(' AUTO_INCREMENT='.($expectedId+1).' ',' AUTO_INCREMENT='.$expectedId.' ',$proof['ddl_raw'],$count);
    m2f_assert($count===1&&hash('sha256',$old)===$before['ddl_sha256'],'CURRENT_UPGRADE_ONLY_EXACT_MENU_DDL_COUNTER_INVERSE');
    $row=$proof['row'];
    m2f_assert(is_array($row)&&count($row)===22&&array_keys($row)===$before['projected_columns'],
        'CURRENT_UPGRADE_COMPLETE_NATIVE_REFUND_LEAF_ROW');
    $wanted=['id'=>$expectedId]+\crmeb\services\marketplace\RefundSchemaReadiness::menuRows($expectedParent)[0];
    m4iu_keys($row,array_keys($wanted));
    foreach($wanted as$key=>$value)m2f_assert($row[$key]!==null&&(string)$row[$key]===(string)$value,
        'CURRENT_UPGRADE_EVERY_REFUND_MENU_FIELD_AND_ORIGINAL_PARENT');
    m4iu_keys($proof['old_projection'],['rows','sha256']);
    m2f_assert($proof['old_projection']===$before['projection']&&$current['projection']['rows']===$before['projection']['rows']+1
        &&is_string($current['projection']['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$current['projection']['sha256'])===1,
        'CURRENT_UPGRADE_ALL_ORIGINAL_MENU_VALUES_TYPES_AND_MULTIPLICITY');
}
'''

_REFUND_CAPTURE = rb'''function m4iu_capture(array $columns,?int $refundMenuId=null): array
{
    $start=m4iu_redisTime();$snapshot=m4m_snapshot();$projections=[];$db=audit_db();$proof=null;
    foreach($columns as$name=>$list){
        if($name==='eb_system_menus'&&$refundMenuId!==null){
            $rows=m4m_rows($db,'SELECT '.implode(',',array_map('m4m_quote',$list)).' FROM eb_system_menus WHERE id<>?',[$refundMenuId]);
            $hashes=array_map(static fn(array $row):string=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),$rows);
            sort($hashes,SORT_STRING);$projections[$name]=['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$hashes))];
        }else $projections[$name]=m4m_projection($db,$name,$list);
    }
    if($refundMenuId!==null){
        $rows=m4m_rows($db,'SELECT * FROM eb_system_menus WHERE id=?',[$refundMenuId]);
        m2f_assert(count($rows)<=1,'CURRENT_UPGRADE_ONLY_EXPECTED_SINGLE_REFUND_LEAF');
        if($rows!==[]){
            $ddl=$db->query('SHOW CREATE TABLE eb_system_menus')->fetch(PDO::FETCH_NUM);
            m2f_assert(isset($projections['eb_system_menus'])&&is_array($ddl)&&is_string($ddl[1]??null),'CURRENT_UPGRADE_COMPLETE_REFUND_MENU_CAPTURE');
            $proof=['id'=>$refundMenuId,'row'=>$rows[0],'ddl_raw'=>$ddl[1],'old_projection'=>$projections['eb_system_menus']];
        }
    }
    $end=m4iu_redisTime();m2f_assert($end>=$start,'CURRENT_UPGRADE_REAL_OBSERVER_WINDOW');
    return ['snapshot'=>$snapshot,'original_projection'=>$projections,'redis_time_before'=>$start,'redis_time_after'=>$end,'refund_menu'=>$proof];
}
'''

def upgrade_source_files(refund_readiness=False):
    if type(refund_readiness) is not bool:
        raise ValueError("EXACT_HISTORY_REFUND_VARIANT_REQUIRED")
    if not refund_readiness:
        return UPGRADE_SOURCE_FILES
    previous=b"['path' => 'crmeb/crmeb/services/marketplace/SchemaVersionGate.php', 'bytes' => 12267, 'sha256' => '25bf37fa585d76110d74fc8f8aa7615aad2915d99c04c83e3cab850ab2cbba31']"
    current=b"['path' => 'crmeb/crmeb/services/marketplace/SchemaVersionGate.php', 'bytes' => 12380, 'sha256' => 'f4cac59718aad23680b095445be072943bf0b171d4f6a96c71173605b38dd4ce']"
    additions=(b"        ['path' => 'crmeb/crmeb/services/marketplace/RefundSchemaReadiness.php', 'bytes' => 21017, 'sha256' => '6d1ed4f17379356c4a6cebbd50ae0be4d1298eeb25c2f437781792405915d133'],\n"
               b"        ['path' => 'help/migrations/marketplace-ms5-refund.php', 'bytes' => 39251, 'sha256' => '24b1a48f0b35378dcf66d7e5bb5462cb8fd06179597022c7b99419486de0d120'],\n")
    return _refund_once(_refund_once(UPGRADE_SOURCE_FILES,previous,current),b"    ];\n",additions+b"    ];\n")

def _refund_once(raw,previous,current):
    if raw.count(previous)!=1:
        raise ValueError("HISTORY_REFUND_EXACT_SINGLE_SOURCE_SEAM_REQUIRED")
    return raw.replace(previous,current,1)

def _refund_php_function(raw,name):
    start=raw.index(b"function "+name+b"(")
    stop=raw.find(b"\nfunction ",start+1)
    return raw[start:] if stop<0 else raw[start:stop+1]

def _refund_initializer_seams():
    current=_UPGRADE_FUNCTIONS
    old_plan=_refund_php_function(current,b"m4iu_plan")
    new_plan=_refund_once(old_plan,
        b"    foreach (['apply', 'check', 'apply', 'check'] as $mode) $plan[] = ['marketplace-ms6-schema.php', $mode];",
        b"    foreach (['check','apply-structure','verify','check-menus','apply-menus','check-menus'] as $mode) $plan[] = ['marketplace-ms5-refund.php',$mode];\n"
        b"    foreach (['apply', 'check', 'apply', 'check'] as $mode) $plan[] = ['marketplace-ms6-schema.php', $mode];")
    current=_refund_once(current,old_plan,new_plan)
    current=_refund_once(current,b"$index < 16, 'CURRENT_UPGRADE_FINITE_SIXTEEN_CHILDREN'",b"$index < 22, 'CURRENT_UPGRADE_FINITE_TWENTYTWO_CHILDREN'")
    current=_refund_once(current,_refund_php_function(current,b"m4iu_capture"),_REFUND_CAPTURE)
    unchanged=_refund_php_function(current,b"m4iu_unchanged")
    current=_refund_once(current,unchanged,_refund_once(unchanged,
        b"    m4iu_cachePreserved($before['snapshot']['redis'], $after);",
        b"    m2f_assert($before['refund_menu']===$after['refund_menu'],'CURRENT_UPGRADE_READ_ONLY_COMPLETE_REFUND_MENU_PROOF');\n"
        b"    m4iu_cachePreserved($before['snapshot']['redis'], $after);"))
    preserved=_refund_php_function(current,b"m4iu_preserved")
    current=_refund_once(current,preserved,_refund_once(preserved,
        b"        else m2f_assert($actual['tables'][$name] === $row, 'CURRENT_UPGRADE_ALL_NONTARGET_DDL_METADATA_ROWS_AI');",
        b"        elseif($name==='eb_system_menus'&&$after['refund_menu']!==null)\n"
        b"            m4iu_refundMenuPreserved($row,$actual['tables'][$name],$after['refund_menu'],(int)$row['metadata']['table']['AUTO_INCREMENT'],(int)$after['refund_menu']['row']['pid']);\n"
        b"        else m2f_assert($actual['tables'][$name] === $row, 'CURRENT_UPGRADE_ALL_NONTARGET_DDL_METADATA_ROWS_AI');"))
    original=_refund_php_function(current,b"m4iu_upgrade")
    upgrade=original
    upgrade=_refund_once(upgrade,b"'expected_after_table_count' => 173",b"'expected_after_table_count' => 174")
    anchor=b"    $evidence['current_structure_upgrade']['snapshots']['before'] = $before;"
    setup=rb'''    $counter=$old['tables']['eb_system_menus']['metadata']['table']['AUTO_INCREMENT'];
    m2f_assert((is_int($counter)||is_string($counter))&&preg_match('/\A[1-9][0-9]*\z/D',(string)$counter)===1
        &&(int)$counter<=65534,'CURRENT_UPGRADE_REFUND_NATURAL_UNUSED_MENU_ID');$refundMenuId=(int)$counter;
    m2f_assert(m4m_rows(audit_db(),'SELECT id FROM eb_system_menus WHERE id=?',[$refundMenuId])===[],'CURRENT_UPGRADE_REFUND_ID_ABSENT_BEFORE_WRITES');
    $parents=m4m_rows(audit_db(),'SELECT * FROM eb_system_menus WHERE unique_auth=?',['admin-order-refund']);
    m2f_assert(count($parents)===1&&preg_match('/\A[1-9][0-9]*\z/D',(string)$parents[0]['id'])===1
        &&(int)$parents[0]['id']<=65535,'CURRENT_UPGRADE_ORIGINAL_REFUND_PAGE_ANCHOR');$refundParent=(int)$parents[0]['id'];
    $evidence['current_structure_upgrade']['refund_readiness']=['menu_id'=>$refundMenuId,'parent_id'=>$refundParent,'gate_ready'=>false,'empty_attempt_table'=>false];
'''
    upgrade=_refund_once(upgrade,anchor,setup+anchor)
    # The original capture before any mutation remains complete; all later captures carry the exact unused/new id.
    if upgrade.count(b"m4iu_capture($columns)")!=3:
        raise ValueError("HISTORY_REFUND_THREE_POSTBASELINE_CAPTURE_SITES_REQUIRED")
    upgrade=upgrade.replace(b"m4iu_capture($columns)",b"m4iu_capture($columns,$refundMenuId)")
    insert=rb'''        foreach(range(0,5)as$step){
            $result=m4iu_child($config,$evidence);
            m4iu_testRefundReceipt($result,$step,$refundMenuId,$refundParent,
                hash_file('sha256','/workspace/help/migrations/marketplace-ms5-refund.php'),
                hash_file('sha256','/workspace/help/migrations/marketplace-ms1.php'));
            $current=m4iu_capture($columns,$refundMenuId);
            if($step===1){
                m4iu_existingTables($previous,$current,[]);$newNames[]='eb_marketplace_refund_attempt';
                m4iu_preserved($before,$current,$newNames,true);
            }elseif($step===4){
                m2f_assert(is_array($current['refund_menu']),'CURRENT_UPGRADE_REFUND_MENU_ACTUALLY_CAPTURED');
                m4iu_refundMenuPreserved($before['snapshot']['tables']['eb_system_menus'],
                    $current['snapshot']['tables']['eb_system_menus'],$current['refund_menu'],$refundMenuId,$refundParent);
                m4iu_existingTables($previous,$current,['eb_system_menus']);m4iu_preserved($before,$current,$newNames,true);
            }else m4iu_unchanged($previous,$current);
            if($step>=4){
                \crmeb\services\marketplace\RefundSchemaReadiness::assertReady(audit_db(),'eb_');
                m2f_assert($current['snapshot']['tables']['eb_marketplace_refund_attempt']['projection']['rows']===0,'CURRENT_UPGRADE_NEW_REFUND_TABLE_EMPTY');
            }
            $evidence['current_structure_upgrade']['snapshots']['cli_'.count($evidence['children'])]=$current;$previous=$current;
            m2f_assert(m2f_file($config)===$owned[$config],'CURRENT_UPGRADE_REFUND_PRIVATE_CONFIG_IDENTITY_HELD');
        }
        $evidence['current_structure_upgrade']['refund_readiness']=['menu_id'=>$refundMenuId,'parent_id'=>$refundParent,'gate_ready'=>true,'empty_attempt_table'=>true];
'''
    upgrade=_refund_once(upgrade,b"        $schemaRow = null;",insert+b"        $schemaRow = null;")
    upgrade=_refund_once(upgrade,b"$evidence['children'][12]",b"$evidence['children'][18]")
    upgrade=_refund_once(upgrade,b"count($evidence['children']) === 16 && count(array_unique(array_column($evidence['children'], 'pid'))) === 16",
        b"count($evidence['children']) === 22 && count(array_unique(array_column($evidence['children'], 'pid'))) === 22")
    upgrade=_refund_once(upgrade,b"count($previous['snapshot']['names']) === 173",b"count($previous['snapshot']['names']) === 174")
    upgrade=_refund_once(upgrade,b"CURRENT_UPGRADE_SIXTEEN_NATIVE_CHILDREN_EXACT173",b"CURRENT_UPGRADE_TWENTYTWO_NATIVE_CHILDREN_EXACT174")
    current=_refund_once(current,original,upgrade)
    current=_REFUND_CONTRACTS+current
    registration=_READER_REGISTRATION_FUNCTIONS
    registration=_refund_once(registration,b"foreach (range(1, 16) as $number)",b"foreach (range(1, 22) as $number)")
    registration=_refund_once(registration,b"count($leaves) === 102",b"count($leaves) === 126")
    return ((UPGRADE_SOURCE_FILES,upgrade_source_files(True)),(_UPGRADE_FUNCTIONS,current),(_READER_REGISTRATION_FUNCTIONS,registration))


def _seams():
    return (
        _default_shipping_seam(),
        (b"        'initializer' => ['initialize', 'audit-ms4-initialize']];",
         b"        'current-structure-upgrade' => ['current-structure-upgrade', 'audit-bootstrap'],\n"
         b"        'register-legacy-current-reader' => ['register', 'audit-bootstrap'],\n"
         b"        'initializer' => ['initialize', 'audit-ms4-initialize']];"),
        (b"            if (getenv('MS4_PROOF_NAME') === 'register-legacy-history-producer') m4i_legacyProducerRegistration();",
         b"            if (getenv('MS4_PROOF_NAME') === 'register-legacy-current-reader') m4i_currentReaderRegistration();\n"
         b"            elseif (getenv('MS4_PROOF_NAME') === 'register-legacy-history-producer') m4i_legacyProducerRegistration();"),
        (b"['prepare', 'register', 'authorize-cleanup'], true)), 'FINITE_PHASE_ONLY');",
         b"['prepare', 'register', 'authorize-cleanup', 'current-structure-upgrade'], true)), 'FINITE_PHASE_ONLY');"),
        (b"        else { m4i_initialize($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_MS4_PREREQUISITES_ONLY'; }",
         b"        elseif ($phase === 'current-structure-upgrade') { m4iu_upgrade($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_CURRENT_STRUCTURE_UPGRADE_ONLY'; }\n"
         b"        else { m4i_initialize($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_MS4_PREREQUISITES_ONLY'; }"),
        (b"function m4i_main(): int\n", M4M_OBSERVER_FUNCTIONS + M4M_REGISTRATION_FUNCTIONS + UPGRADE_SOURCE_FILES + _UPGRADE_FUNCTIONS
         + _READER_REGISTRATION_FUNCTIONS + b"\nfunction m4i_main(): int\n"),
    )


def _source(raw):
    if not isinstance(raw, bytes) or len(raw) != INITIALIZER_SOURCE_BYTES or hashlib.sha256(raw).hexdigest() != INITIALIZER_SOURCE_SHA256:
        raise ValueError("INITIALIZER_EXACT_ARCHIVED_SOURCE_REQUIRED")


def transform_initializer(raw: bytes, *, refund_readiness=False, legacy_refund_anchors=False) -> bytes:
    if type(refund_readiness) is not bool:
        raise ValueError("EXACT_HISTORY_REFUND_VARIANT_REQUIRED")
    _legacy_anchor_mode(refund_readiness, legacy_refund_anchors)
    _source(raw)
    candidate = raw
    for before, after in _seams():
        if candidate.count(before) != 1 or after in candidate:
            raise ValueError("INITIALIZER_UNIQUE_FORWARD_SEAM_REQUIRED")
        candidate = candidate.replace(before, after, 1)
    if refund_readiness:
        for previous,current in _refund_initializer_seams():
            candidate=_refund_once(candidate,previous,current)
    if legacy_refund_anchors:
        for previous, current in _legacy_anchor_initializer_seams():
            candidate = _refund_once(candidate, previous, current)
    for previous, current in _driver_prestart_initializer_seams():
        candidate = _refund_once(candidate, previous, current)
    if reverse_initializer(candidate, refund_readiness=refund_readiness, legacy_refund_anchors=legacy_refund_anchors) != raw:
        raise ValueError("INITIALIZER_COMPLETE_REVERSE_REQUIRED")
    return candidate


def reverse_initializer(candidate: bytes, *, refund_readiness=False, legacy_refund_anchors=False) -> bytes:
    if type(refund_readiness) is not bool:
        raise ValueError("EXACT_HISTORY_REFUND_VARIANT_REQUIRED")
    _legacy_anchor_mode(refund_readiness, legacy_refund_anchors)
    if not isinstance(candidate, bytes):
        raise ValueError("INITIALIZER_CANDIDATE_BYTES_REQUIRED")
    raw = candidate
    for previous, current in reversed(_driver_prestart_initializer_seams()):
        raw = _refund_once(raw, current, previous)
    if legacy_refund_anchors:
        for previous, current in reversed(_legacy_anchor_initializer_seams()):
            raw = _refund_once(raw, current, previous)
    if refund_readiness:
        for previous,current in reversed(_refund_initializer_seams()):
            raw=_refund_once(raw,current,previous)
    for before, after in reversed(_seams()):
        if raw.count(after) != 1:
            raise ValueError("INITIALIZER_UNIQUE_REVERSE_SEAM_REQUIRED")
        raw = raw.replace(after, before, 1)
    _source(raw)
    return raw


# A future Run may qualify only passive expiry across the producer/driver gap.
# This existing helper samples the Run's Redis before the driver is started;
# the original migration's before/after/repeat equality is unchanged.
_DRIVER_PRESTART_INITIALIZER = rb'''function m4i_driverPrestartRedis(array &$evidence): void
{
    m2f_assert(getenv('MS4_PROOF_NAME') === 'register-driver', 'DRIVER_CLOCK_ONLY_ORIGINAL_REGISTER_HELPER');
    $context = m4i_read('/run/audit/actual-run-context.json');
    $driver = m4i_read('/run/audit/evidence/driver-created.json');
    $helper = m4i_read('/run/audit/evidence/register-driver-created.json');
    $manifest = audit_manifest();
    m2f_assert(($manifest['guard_mode'] ?? null) === 'read-only'
        && $manifest['run_id'] === $context['run_id'] && $manifest['commit_sha'] === $context['source_sha']
        && $manifest['project'] === $context['project']
        && $manifest['services']['audit-ms4-migration']['id'] === $driver['id']
        && $helper['id'] === $evidence['binding']['container_id']
        && $helper['run_id'] === $context['run_id'] && $helper['source_sha'] === $context['source_sha']
        && $helper['proof_name'] === 'register-driver'
        && hash_file('sha256', '/run/audit/evidence/register-driver-created.json') === $evidence['binding']['created_sha256']
        && $manifest['services']['audit-redis']['id'] === $context['initial_service_ids']['audit-redis']
        && $manifest['services']['audit-mysql']['id'] === $context['initial_service_ids']['audit-mysql']
        && $driver['run_id'] === $context['run_id'] && $driver['source_sha'] === $context['source_sha']
        && $driver['proof_name'] === 'driver' && $driver['status'] === 'created'
        && $driver['pid'] === 0 && $driver['restart_count'] === 0
        && is_string($driver['id']) && preg_match('/\A[a-f0-9]{64}\z/D', $driver['id']) === 1
        && $driver['id'] !== $evidence['binding']['container_id']
        && $evidence['binding']['source_sha'] === $context['source_sha']
        && $evidence['binding']['freeze_sha256'] === $context['freeze_sha256'],
        'DRIVER_CLOCK_SAME_ORIGINAL_CREATED_DRIVER_RUN_SOURCE_FREEZE_AND_BASE_RESOURCES');
    // In this explicitly read-only guard mode this checks DNS, config, both
    // sentinels and the exact existing DML grants without a DDL denial probe.
    audit_guard_assert();
    $redisMs = m4iu_redisTime();
    m2f_assert($redisMs > 0, 'DRIVER_CLOCK_REAL_REDIS_POSITIVE_INTEGER_MILLISECONDS');
    $evidence['driver_prestart_redis'] = ['container_id' => $driver['id'], 'redis_ms' => $redisMs];
}

'''


def _driver_prestart_initializer_seams():
    anchor = b'function m4i_main(): int\n'
    registration = b"                audit_register_resources();\n"
    return (
        (anchor, _DRIVER_PRESTART_INITIALIZER + anchor),
        (registration, registration
         + b"                if (getenv('MS4_PROOF_NAME') === 'register-driver') m4i_driverPrestartRedis($evidence);\n"),
    )


FILES_BOOTSTRAP_SOURCE_PIN = (14449, "dbe38371567d495d5567189599a025903b91a22ffa6c8b001aab2509baf1375b")
_FILES_BOOTSTRAP_BEFORE = b"""        $freezePin = ['path' => '/run/audit/preparation-source-binding.json', 'bytes' => (int) $bytes[1], 'sha256' => $sha[1]];
        $state = nhe_qualify($freezePin, 'audit-legacy-files-bootstrap');"""
_FILES_BOOTSTRAP_AFTER = b"""        $freezePin = ['path' => '/run/audit/preparation-source-binding.json', 'bytes' => (int) $bytes[1], 'sha256' => $sha[1]];
        // This short-lived CLI retains full source proofs and performs the existing old-App bootstrap.
        // Its local budget does not alter shared configuration or another PHP/HTTP process.
        nhe_expect(ini_get('memory_limit') === '128M', 'NHE_FILES_ORIGINAL_CLI_MEMORY_BUDGET');
        $previousMemoryLimit = ini_set('memory_limit', '512M');
        nhe_expect($previousMemoryLimit === '128M' && ini_get('memory_limit') === '512M',
            'NHE_FILES_BOUNDED_CLI_MEMORY_BUDGET');
        $state = nhe_qualify($freezePin, 'audit-legacy-files-bootstrap');"""


def _files_bootstrap_source(raw):
    if not isinstance(raw, bytes) or (len(raw), hashlib.sha256(raw).hexdigest()) != FILES_BOOTSTRAP_SOURCE_PIN:
        raise ValueError("FILES_BOOTSTRAP_EXACT_ARCHIVED_SOURCE_REQUIRED")


def transform_files_bootstrap(raw: bytes) -> bytes:
    _files_bootstrap_source(raw)
    if raw.count(_FILES_BOOTSTRAP_BEFORE) != 1 or _FILES_BOOTSTRAP_AFTER in raw:
        raise ValueError("FILES_BOOTSTRAP_UNIQUE_FORWARD_SEAM_REQUIRED")
    candidate = raw.replace(_FILES_BOOTSTRAP_BEFORE, _FILES_BOOTSTRAP_AFTER, 1)
    if reverse_files_bootstrap(candidate) != raw:
        raise ValueError("FILES_BOOTSTRAP_COMPLETE_REVERSE_REQUIRED")
    return candidate


def reverse_files_bootstrap(candidate: bytes) -> bytes:
    if not isinstance(candidate, bytes) or candidate.count(_FILES_BOOTSTRAP_AFTER) != 1:
        raise ValueError("FILES_BOOTSTRAP_UNIQUE_REVERSE_SEAM_REQUIRED")
    raw = candidate.replace(_FILES_BOOTSTRAP_AFTER, _FILES_BOOTSTRAP_BEFORE, 1)
    _files_bootstrap_source(raw)
    return raw


LEGACY_APP_SOURCE_PIN = (52325, "36ee80b6e425aa5623f814bfb9efd8b8d0b2aede94a2762c03d4d696aab734c5")
_LEGACY_APP_READ_BEFORE = b"""    nhe_expect(is_array($before) && is_file($path) && !is_link($path)
        && ($before['mode'] & 0170000) === 0100000, 'NHE_REGULAR_SOURCE_FILE');
    $raw = file_get_contents($path);
"""
_LEGACY_APP_READ_AFTER = br"""    nhe_expect(is_array($before) && is_file($path) && !is_link($path)
        && ($before['mode'] & 0170000) === 0100000, 'NHE_REGULAR_SOURCE_FILE');
    // App initialization installs its own handler; this read must retain a safe first failure.
    set_error_handler(static function (int $severity, #[SensitiveParameter] string $message): never {
        if ($severity !== E_WARNING) throw new \RuntimeException('NHE_FILE_READ_OTHER_PHP_DIAGNOSTIC');
        $reason = 'OTHER';
        foreach (['No such file or directory' => 'NOT_FOUND', 'Permission denied' => 'PERMISSION',
            'Too many open files' => 'OPEN_FILE_LIMIT', 'Cannot allocate memory' => 'ALLOCATION',
            'Input/output error' => 'IO'] as $suffix => $code) {
            if (strlen($message) <= 8192 && preg_match('~\Afile_get_contents\(.*\): '
                . '(?:Failed to open stream: |Read of [0-9]+ bytes failed with errno=[0-9]+ )'
                . preg_quote($suffix, '~') . '\z~Ds', $message) === 1) {
                $reason = $code; break;
            }
        }
        throw new \RuntimeException('NHE_FILE_READ_WARNING_' . $reason);
    });
    try {
        $raw = file_get_contents($path);
    } finally {
        restore_error_handler();
    }
"""


def _legacy_app_source(raw):
    if not isinstance(raw, bytes) or (len(raw), hashlib.sha256(raw).hexdigest()) != LEGACY_APP_SOURCE_PIN:
        raise ValueError("LEGACY_APP_EXACT_ARCHIVED_SOURCE_REQUIRED")


def transform_legacy_app(raw: bytes) -> bytes:
    _legacy_app_source(raw)
    if raw.count(_LEGACY_APP_READ_BEFORE) != 1 or _LEGACY_APP_READ_AFTER in raw:
        raise ValueError("LEGACY_APP_UNIQUE_READ_FORWARD_SEAM_REQUIRED")
    candidate = raw.replace(_LEGACY_APP_READ_BEFORE, _LEGACY_APP_READ_AFTER, 1)
    if reverse_legacy_app(candidate) != raw:
        raise ValueError("LEGACY_APP_COMPLETE_READ_REVERSE_REQUIRED")
    return candidate


def reverse_legacy_app(candidate: bytes) -> bytes:
    if not isinstance(candidate, bytes) or candidate.count(_LEGACY_APP_READ_AFTER) != 1:
        raise ValueError("LEGACY_APP_UNIQUE_READ_REVERSE_SEAM_REQUIRED")
    raw = candidate.replace(_LEGACY_APP_READ_AFTER, _LEGACY_APP_READ_BEFORE, 1)
    _legacy_app_source(raw)
    return raw


PRODUCER_ENTRY_SOURCE_PIN = (1081876, "8e3156f29e1aedb87fbbce359da41025992f3ebe88a67299fba6487e540f20b1")
_PRODUCER_ENTRY_BEFORE = br"""if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    fwrite(STDERR, "{\"status\":\"FAIL\",\"code\":\"NHE_PRODUCER_NOT_IMPLEMENTED\"}\n");
    exit(1);
}
"""
_PRODUCER_ENTRY_AFTER = br"""if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI === 'cli') {
        $nhepFatalReserve = str_repeat("\0", 32768);
        register_shutdown_function(static function () use (&$nhepFatalReserve): void {
            $nhepFatalReserve = null;
            $fatal = error_get_last();
            if (!is_array($fatal) || !in_array($fatal['type'] ?? null,
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
            try {
                $file = $fatal['file'] ?? null;
                $limit = ini_get('memory_limit');
                $allowed = null; $attempted = null;
                $message = $fatal['message'] ?? null;
                if (is_string($message) && strlen($message) <= 256
                    && preg_match('/\AAllowed memory size of ([1-9][0-9]{0,18}) bytes exhausted \(tried to allocate ([1-9][0-9]{0,18}) bytes\)\z/D',
                        $message, $memory) === 1) {
                    $allowed = $memory[1]; $attempted = $memory[2];
                }
                $diagnostic = [
                    'schema' => 1, 'status' => 'FAIL', 'code' => 'NHE_PRODUCER_NATIVE_FATAL',
                    'scope' => 'LEGACY_HISTORY_PRODUCER_DIRECT_CLI_FATAL_ONLY',
                    'fatal_type' => $fatal['type'],
                    'file_tag' => $file === __FILE__ ? 'producer' : 'other',
                    'file_path_sha256' => is_string($file) ? hash('sha256', $file) : null,
                    'line' => is_int($fatal['line'] ?? null) && $fatal['line'] > 0 ? $fatal['line'] : null,
                    'memory_limit' => is_string($limit)
                        && preg_match('/\A(?:-1|0|[1-9][0-9]{0,9}[KMG]?)\z/D', $limit) === 1 ? $limit : null,
                    'heap_bytes_after_reserve_release' => memory_get_usage(true),
                    'heap_peak_bytes' => memory_get_peak_usage(true),
                    'allowed_bytes' => $allowed, 'attempted_bytes' => $attempted,
                ];
                $json = json_encode($diagnostic, JSON_UNESCAPED_SLASHES);
                if (is_string($json) && strlen($json) <= 2048) @fwrite(STDERR, $json . "\n");
            } catch (Throwable $ignored) {
                // Diagnostics never replace the original process result.
            }
        });
        nhep_expect(ini_get('memory_limit') === '128M', 'NHE_PRODUCER_ORIGINAL_CLI_MEMORY_BUDGET');
        $nhepPreviousMemoryLimit = ini_set('memory_limit', '512M');
        nhep_expect($nhepPreviousMemoryLimit === '128M' && ini_get('memory_limit') === '512M',
            'NHE_PRODUCER_CLI_MEMORY_BUDGET_APPLIED');
    }
    exit(nhep_normal_history_main());
}
"""
_PRODUCER_FAILURE_BEFORE = br"""        fwrite(STDERR, json_encode(['status' => 'FAIL', 'code' => $code, 'class' => $class,
            'normal_cleanup' => $cleanup], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");""".replace(b"\n", b"\r\n")
_PRODUCER_FAILURE_AFTER = br"""        $diagnostic = ['source_tag' => null, 'line' => null, 'callsite' => null];
        try {
            $sourceTags = [__FILE__ => 'producer', '/run/nonempty/legacy-app.php' => 'legacy_app',
                '/workspace/help/audit-regressions/isolated/lib/guard.php' => 'guard',
                '/workspace/help/audit-regressions/isolated/lib/harness.php' => 'harness',
                '/workspace/help/audit-regressions/isolated/ms4-migration.php' => 'migration'];
            $sourceFile = $failure->getFile(); $sourceLine = $failure->getLine();
            $diagnostic['source_tag'] = $sourceTags[$sourceFile] ?? 'other';
            $diagnostic['line'] = is_int($sourceLine) && $sourceLine > 0 ? $sourceLine : null;
            foreach (array_slice($failure->getTrace(), 0, 16) as $frame) {
                if (!is_array($frame) || !is_string($frame['file'] ?? null)
                    || !isset($sourceTags[$frame['file']])) continue;
                $frameLine = $frame['line'] ?? null;
                $diagnostic['callsite'] = ['source_tag' => $sourceTags[$frame['file']],
                    'line' => is_int($frameLine) && $frameLine > 0 ? $frameLine : null];
                break;
            }
        } catch (Throwable $ignored) {
            $diagnostic = ['source_tag' => null, 'line' => null, 'callsite' => null];
        }
        fwrite(STDERR, json_encode(['status' => 'FAIL', 'code' => $code, 'class' => $class,
            'normal_cleanup' => $cleanup, 'diagnostic' => $diagnostic], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");""".replace(b"\n", b"\r\n")


def _producer_entry_source(raw):
    if not isinstance(raw, bytes) or (len(raw), hashlib.sha256(raw).hexdigest()) != PRODUCER_ENTRY_SOURCE_PIN:
        raise ValueError("HISTORY_PRODUCER_EXACT_ARCHIVED_SOURCE_REQUIRED")


def _producer_cookie_seams():
    """The two enabled frozen middlewares each save one native zero-expiry cookie."""
    return (
        (b"    $bytes = 0; $cookieIds = [];\n",
         b"    $bytes = 0; $cookieIds = []; $languageCookies = [];\n"),
        (rb'''            // php-8.2.33 head.c/head.h, then CLI send_headers appends CRLF.
            nhep_expect(preg_match('~\ASet-Cookie: PHPSESSID=([a-f0-9]{32}); path=/\r\n\z~D', $line, $cookie) === 1,
                'NHE_AUTH_HEADER_EXACT_NATIVE_SESSION_COOKIE_AND_ONLY_SOURCE_ATTRIBUTES');
            $cookieIds[] = $cookie[1];''',
         rb'''            // Frozen LoadLangPack and SessionInit each save one zero-expiry cookie.
            if (str_starts_with($line, 'Set-Cookie: cb_lang=')) {
                nhep_expect(preg_match('~\ASet-Cookie: cb_lang=(zh-cn); path=/\r\n\z~D', $line, $language) === 1,
                    'NHE_AUTH_HEADER_EXACT_NATIVE_LANGUAGE_COOKIE_AND_ONLY_SOURCE_ATTRIBUTES');
                $languageCookies[] = $language[1];
            } else {
                nhep_expect(preg_match('~\ASet-Cookie: PHPSESSID=([a-f0-9]{32}); path=/\r\n\z~D', $line, $cookie) === 1,
                    'NHE_AUTH_HEADER_EXACT_NATIVE_SESSION_COOKIE_AND_ONLY_SOURCE_ATTRIBUTES');
                $cookieIds[] = $cookie[1];
            }'''),
        (b"    nhep_expect($bytes === $headers['bytes'] && count($cookieIds) === 1,\n",
         b"    nhep_expect($bytes === $headers['bytes'] && count($cookieIds) === 1 && count($languageCookies) === 1,\n"),
    )


def _producer_publication_seams():
    """Keep private native publication, then export only its fixed public bytes.

    The host evidence bind may expose Windows permission bits. It is a public
    output, not a substitute for the existing Linux 0700/0600 private boundary.
    The fixed helper has no caller-selected paths or business qualification.
    """
    previous_root = b"    $root = '/run/audit/evidence'; $path = $root . '/legacy-history-producer-result.json';\r\n"
    current_root = br'''    return nhep_normal_history_publish_safe_bytes(nhe_json($seal));
}

/** Fixed publication I/O only; the caller retains all original qualified seal gates. */
function nhep_normal_history_publish_safe_bytes(string $raw): array
{
    $root = '/legacy-runtime'; $path = $root . '/legacy-history-producer-result.json';
'''.replace(b"\n", b"\r\n")
    previous_tail = br'''    nhep_expect(nhe_file($path, $pin) === $raw, 'NHE_NORMAL_PUBLISH_COMPLETE_CLOSED_NAMED_RESULT_READBACK');
    return $pin;
}
'''.replace(b"\n", b"\r\n")
    current_tail = br'''    nhep_expect(nhe_file($path, $pin) === $raw, 'NHE_NORMAL_PUBLISH_COMPLETE_CLOSED_NAMED_RESULT_READBACK');
    // Only the already serialized safe public view crosses the host bind. The
    // Linux stage above retains every original private 0700/0600 assertion.
    $publicRoot = '/run/audit/evidence';
    $publicPath = $publicRoot . '/legacy-history-producer-result.json';
    clearstatcache(true, $publicRoot); $publicDirectory = lstat($publicRoot);
    nhep_expect(is_array($publicDirectory) && is_dir($publicRoot) && !is_link($publicRoot)
        && realpath($publicRoot) === $publicRoot && ($publicDirectory['mode'] & 0170000) === 0040000
        && ($publicDirectory['mode'] & 06000) === 0 && $publicDirectory['uid'] === posix_geteuid()
        && !file_exists($publicPath) && !is_link($publicPath), 'NHE_NORMAL_PUBLIC_EXPORT_FIXED_OWN_CREATE_ONLY_DIRECTORY');
    $mask = umask(0077);
    try { $publicStream = fopen($publicPath, 'x+b'); } finally { umask($mask); }
    nhep_expect(is_resource($publicStream), 'NHE_NORMAL_PUBLIC_EXPORT_ACTUAL_CREATE_ONLY_HANDLE');
    $failure = null;
    try {
        $opened = fstat($publicStream); clearstatcache(true, $publicPath); $named = lstat($publicPath);
        nhep_expect(is_array($opened) && is_array($named) && !is_link($publicPath)
            && realpath($publicPath) === $publicPath && ($opened['mode'] & 0170000) === 0100000
            && ($opened['mode'] & 06000) === 0 && $opened['uid'] === posix_geteuid()
            && $opened['nlink'] === 1 && $opened['size'] === 0 && $opened['dev'] === $publicDirectory['dev'],
            'NHE_NORMAL_PUBLIC_EXPORT_OWN_REGULAR_SINGLE_LINK_EMPTY_RESULT');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key)
            nhep_expect($opened[$key] === $named[$key], 'NHE_NORMAL_PUBLIC_EXPORT_SAME_NAMED_OPENED_STAT9');
        $offset = 0;
        while ($offset < strlen($raw)) {
            $written = fwrite($publicStream, substr($raw, $offset));
            nhep_expect(is_int($written) && $written > 0, 'NHE_NORMAL_PUBLIC_EXPORT_FULL_SAFE_BYTES_WRITE');
            $offset += $written;
        }
        nhep_expect(fflush($publicStream) && fseek($publicStream, 0) === 0,
            'NHE_NORMAL_PUBLIC_EXPORT_FLUSH_AND_SAME_HANDLE_REWIND');
        $readback = stream_get_contents($publicStream);
        nhep_expect(is_string($readback) && $readback === $raw && feof($publicStream),
            'NHE_NORMAL_PUBLIC_EXPORT_COMPLETE_SAME_HANDLE_READBACK_EOF');
        $sealed = fstat($publicStream); clearstatcache(true, $publicPath); $after = lstat($publicPath);
        nhep_expect(is_array($sealed) && is_array($after) && $sealed['size'] === strlen($raw)
            && !is_link($publicPath) && realpath($publicPath) === $publicPath,
            'NHE_NORMAL_PUBLIC_EXPORT_COMPLETE_NATIVE_RESULT_SIZE');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
            nhep_expect($sealed[$key] === $opened[$key], 'NHE_NORMAL_PUBLIC_EXPORT_ORIGINAL_HANDLE_IDENTITY');
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key)
            nhep_expect($sealed[$key] === $after[$key], 'NHE_NORMAL_PUBLIC_EXPORT_FINAL_PATH_HANDLE_STAT9');
    } catch (Throwable $error) { $failure = $error; throw $error;
    } finally {
        try { nhep_expect(fclose($publicStream), 'NHE_NORMAL_PUBLIC_EXPORT_REAL_ORIGINAL_HANDLE_CLOSED');
        } catch (Throwable $closeError) { if ($failure === null) throw $closeError; }
    }
    $publicPin = ['path' => $publicPath, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
    nhep_expect(nhe_file($publicPath, $publicPin) === $raw, 'NHE_NORMAL_PUBLIC_EXPORT_CLOSED_COMPLETE_NAMED_READBACK');
    clearstatcache(true, $publicRoot); $publicDirectoryAfter = lstat($publicRoot);
    nhep_expect(is_array($publicDirectoryAfter) && is_dir($publicRoot) && !is_link($publicRoot)
        && realpath($publicRoot) === $publicRoot, 'NHE_NORMAL_PUBLIC_EXPORT_ORIGINAL_DIRECTORY_STILL_CANONICAL');
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid'] as $key)
        nhep_expect($publicDirectoryAfter[$key] === $publicDirectory[$key],
            'NHE_NORMAL_PUBLIC_EXPORT_ORIGINAL_DIRECTORY_NATIVE_IDENTITY');
    return $publicPin;
}
'''.replace(b"\n", b"\r\n")
    return ((previous_root, current_root),
            (b"    $raw = nhe_json($seal); $mask = umask(0077);\r\n    try { $stream = fopen($path, 'x+b'); }",
             b"    $mask = umask(0077);\r\n    try { $stream = fopen($path, 'x+b'); }"),
            (previous_tail, current_tail))


def transform_producer_entry(raw: bytes, *, legacy_refund_anchors=False) -> bytes:
    if type(legacy_refund_anchors) is not bool:
        raise ValueError("EXACT_HISTORY_LEGACY_ANCHOR_VARIANT_REQUIRED")
    _producer_entry_source(raw)
    observer_before = b"        $observer = audit_db(); $redis = audit_redis();\r\n"
    observer_after = (b"        $observer = audit_db();\r\n"
                      b"        nhep_expect($observer->setAttribute(PDO::ATTR_EMULATE_PREPARES, false) === true\r\n"
                      b"            && $observer->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0,\r\n"
                      b"            'NHE_NORMAL_MAIN_OBSERVER_NATIVE_PREPARES');\r\n"
                      b"        $redis = audit_redis();\r\n")
    if raw.count(_PRODUCER_ENTRY_BEFORE) != 1 or not raw.endswith(_PRODUCER_ENTRY_BEFORE):
        raise ValueError("HISTORY_PRODUCER_UNIQUE_FINAL_ENTRY_REQUIRED")
    if raw.count(_PRODUCER_FAILURE_BEFORE) != 1 or _PRODUCER_FAILURE_AFTER in raw:
        raise ValueError("HISTORY_PRODUCER_UNIQUE_FAILURE_DIAGNOSTIC_REQUIRED")
    if raw.count(observer_before) != 1 or observer_after in raw:
        raise ValueError("HISTORY_PRODUCER_UNIQUE_OBSERVER_NATIVE_PREPARES_REQUIRED")
    candidate = raw[:-len(_PRODUCER_ENTRY_BEFORE)] + _PRODUCER_ENTRY_AFTER
    candidate = candidate.replace(_PRODUCER_FAILURE_BEFORE, _PRODUCER_FAILURE_AFTER, 1)
    candidate = candidate.replace(observer_before, observer_after, 1)
    for previous, current in _producer_cookie_seams():
        if candidate.count(previous) != 1 or current in candidate:
            raise ValueError("HISTORY_PRODUCER_EXACT_COOKIE_FORWARD_SEAM_REQUIRED")
        candidate = candidate.replace(previous, current, 1)
    if legacy_refund_anchors:
        for previous, current in _legacy_anchor_producer_seams():
            candidate = _refund_once(candidate, previous, current)
    for previous, current in _producer_publication_seams():
        candidate = _refund_once(candidate, previous, current)
    if reverse_producer_entry(candidate, legacy_refund_anchors=legacy_refund_anchors) != raw:
        raise ValueError("HISTORY_PRODUCER_COMPLETE_ENTRY_REVERSE_REQUIRED")
    return candidate


def reverse_producer_entry(candidate: bytes, *, legacy_refund_anchors=False) -> bytes:
    if type(legacy_refund_anchors) is not bool:
        raise ValueError("EXACT_HISTORY_LEGACY_ANCHOR_VARIANT_REQUIRED")
    for previous, current in reversed(_producer_publication_seams()):
        candidate = _refund_once(candidate, current, previous)
    if legacy_refund_anchors:
        for previous, current in reversed(_legacy_anchor_producer_seams()):
            candidate = _refund_once(candidate, current, previous)
    observer_before = b"        $observer = audit_db(); $redis = audit_redis();\r\n"
    observer_after = (b"        $observer = audit_db();\r\n"
                      b"        nhep_expect($observer->setAttribute(PDO::ATTR_EMULATE_PREPARES, false) === true\r\n"
                      b"            && $observer->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0,\r\n"
                      b"            'NHE_NORMAL_MAIN_OBSERVER_NATIVE_PREPARES');\r\n"
                      b"        $redis = audit_redis();\r\n")
    if not isinstance(candidate, bytes) or candidate.count(_PRODUCER_ENTRY_AFTER) != 1 or not candidate.endswith(_PRODUCER_ENTRY_AFTER):
        raise ValueError("HISTORY_PRODUCER_UNIQUE_FINAL_REVERSE_ENTRY_REQUIRED")
    if candidate.count(_PRODUCER_FAILURE_AFTER) != 1:
        raise ValueError("HISTORY_PRODUCER_UNIQUE_FAILURE_DIAGNOSTIC_REVERSE_REQUIRED")
    if candidate.count(observer_after) != 1 or observer_before in candidate:
        raise ValueError("HISTORY_PRODUCER_UNIQUE_OBSERVER_NATIVE_PREPARES_REVERSE_REQUIRED")
    raw = candidate
    for previous, current in reversed(_producer_cookie_seams()):
        if raw.count(current) != 1:
            raise ValueError("HISTORY_PRODUCER_EXACT_COOKIE_REVERSE_SEAM_REQUIRED")
        raw = raw.replace(current, previous, 1)
    raw = raw.replace(observer_after, observer_before, 1)
    raw = raw.replace(_PRODUCER_FAILURE_AFTER, _PRODUCER_FAILURE_BEFORE, 1)
    raw = raw[:-len(_PRODUCER_ENTRY_AFTER)] + _PRODUCER_ENTRY_BEFORE
    _producer_entry_source(raw)
    return raw


HISTORY_HTTP_SOURCE_PIN = (36764, "2747c8227349c62e4c5f928561ffbb307a6b9c34cf0d24420a0bf15f5aa0a3f2")
_HISTORY_HTTP_FAILURE_BEFORE = br"""    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\ANHEH_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'NHEH_NORMAL_HTTP_OR_OBSERVER_FAILED';
        throw new RuntimeException($code);
"""
_HISTORY_HTTP_FAILURE_AFTER = br"""    } catch (Throwable $error) {
        try {
            $formatFailure = static function (Throwable $failure, mixed $entries): ?string {
                $code = $failure instanceof RuntimeException ? $failure->getMessage() : '';
                if (strlen($code) > 192 || preg_match('/\A(?:NHE|NHEH)_[A-Z0-9_]+\z/D', $code) !== 1)
                    $code = 'NHEH_HTTP_NON_CONSTANT_FAILURE';
                $class = get_class($failure);
                if (strlen($class) > 192
                    || preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*\z/D', $class) !== 1)
                    $class = 'Throwable';
                $sourceTags = [__FILE__ => 'history_http', '/run/nonempty/legacy-history-producer.php' => 'producer',
                    '/run/nonempty/legacy-app.php' => 'legacy_app', '/run/nonempty/legacy-actors.php' => 'legacy_actors',
                    '/run/nonempty/legacy-normal-config.php' => 'legacy_normal_config',
                    '/workspace/help/audit-regressions/isolated/lib/guard.php' => 'guard',
                    '/workspace/help/audit-regressions/isolated/lib/harness.php' => 'harness',
                    '/workspace/help/audit-regressions/isolated/ms4-migration.php' => 'migration'];
                $sourceTag = $sourceTags[$failure->getFile()] ?? 'other';
                $line = $failure->getLine(); $line = is_int($line) && $line > 0 ? $line : null;
                $callsite = null;
                foreach (array_slice($failure->getTrace(), 0, 16) as $frame) {
                    if (!is_array($frame) || !is_string($frame['file'] ?? null)
                        || !isset($sourceTags[$frame['file']])) continue;
                    $frameLine = $frame['line'] ?? null;
                    $callsite = ['source_tag' => $sourceTags[$frame['file']],
                        'line' => is_int($frameLine) && $frameLine > 0 ? $frameLine : null];
                    break;
                }
                $entryCount = is_array($entries) && array_is_list($entries) && count($entries) <= 37 ? count($entries) : null;
                // Entries are appended after nheh_step checks; they do not prove subsequent caller checks.
                $summary = ['schema' => 1, 'status' => 'FAIL', 'scope' => 'LEGACY_HISTORY_HTTP_CATCH_DIAGNOSTIC_ONLY',
                    'class' => $class, 'code' => $code, 'source_tag' => $sourceTag, 'line' => $line, 'callsite' => $callsite,
                    'step_entries_count' => $entryCount, 'last_entry_sequence' => $entryCount > 0 ? $entryCount : null];
                $json = json_encode($summary, JSON_UNESCAPED_SLASHES);
                return is_string($json) && strlen($json) < 2048 ? $json : null;
            };
            if (PHP_SAPI === 'cli' && defined('STDERR')) {
                $diagnostic = $formatFailure($error, $state['steps'] ?? null);
                if (is_string($diagnostic)) @fwrite(STDERR, $diagnostic . "\n");
            }
        } catch (Throwable $ignored) {
            // Diagnostics never replace the original failure or retain an exception chain.
        }
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\ANHEH_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'NHEH_NORMAL_HTTP_OR_OBSERVER_FAILED';
        throw new RuntimeException($code);
"""


def _history_http_source(raw):
    if not isinstance(raw, bytes) or (len(raw), hashlib.sha256(raw).hexdigest()) != HISTORY_HTTP_SOURCE_PIN:
        raise ValueError("HISTORY_HTTP_EXACT_ARCHIVED_SOURCE_REQUIRED")


def transform_history_http(raw: bytes, *, legacy_refund_anchors=False) -> bytes:
    if type(legacy_refund_anchors) is not bool:
        raise ValueError("EXACT_HISTORY_LEGACY_ANCHOR_VARIANT_REQUIRED")
    _history_http_source(raw)
    if raw.count(_HISTORY_HTTP_FAILURE_BEFORE) != 1 or _HISTORY_HTTP_FAILURE_AFTER in raw:
        raise ValueError("HISTORY_HTTP_UNIQUE_FAILURE_CATCH_REQUIRED")
    candidate = raw.replace(_HISTORY_HTTP_FAILURE_BEFORE, _HISTORY_HTTP_FAILURE_AFTER, 1)
    if legacy_refund_anchors:
        for previous, current in _PAYMENT_DURING_HTTP_SEAMS:
            candidate = _refund_once(candidate, previous, current)
    if reverse_history_http(candidate, legacy_refund_anchors=legacy_refund_anchors) != raw:
        raise ValueError("HISTORY_HTTP_COMPLETE_FAILURE_CATCH_REVERSE_REQUIRED")
    return candidate


def reverse_history_http(candidate: bytes, *, legacy_refund_anchors=False) -> bytes:
    if type(legacy_refund_anchors) is not bool:
        raise ValueError("EXACT_HISTORY_LEGACY_ANCHOR_VARIANT_REQUIRED")
    if legacy_refund_anchors:
        for previous, current in reversed(_PAYMENT_DURING_HTTP_SEAMS):
            candidate = _refund_once(candidate, current, previous)
    if not isinstance(candidate, bytes) or candidate.count(_HISTORY_HTTP_FAILURE_AFTER) != 1:
        raise ValueError("HISTORY_HTTP_UNIQUE_FAILURE_CATCH_REVERSE_REQUIRED")
    raw = candidate.replace(_HISTORY_HTTP_FAILURE_AFTER, _HISTORY_HTTP_FAILURE_BEFORE, 1)
    _history_http_source(raw)
    return raw


# Explicit current fixture-pair branch only. False preserves every f609 historical output.
def _legacy_anchor_mode(refund_readiness, legacy_refund_anchors):
    if type(legacy_refund_anchors) is not bool or legacy_refund_anchors and refund_readiness is not True:
        raise ValueError("EXACT_NEW_HISTORY_REFUND_ANCHOR_SOURCE_COMBINATION_REQUIRED")

_LEGACY_ANCHOR_PURE = r'''const M4IHA_SOURCE_META = <<<'M4IHA_SOURCE_JSON'
{"columns":["id","pid","icon","menu_name","module","controller","action","api_url","methods","params","sort","is_show","is_show_path","access","menu_path","path","auth_type","header","is_header","unique_auth","is_del","mark"],"rows":{"order_root":[4,0,"s-order","订单","admin","order","index","","","[]",120,1,1,1,"/order","",1,"home",1,"admin-order",0,"订单"],"refund_page":[897,4,"","售后订单","admin","","","","","[]",9,1,1,1,"/order/refund","4",1,"",0,"admin-order-refund",0,"售后订单"]}}
M4IHA_SOURCE_JSON;

function m4iha_literal(string $sql, int &$position): mixed
{
    while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
    m2f_assert(isset($sql[$position]), 'ORDER_MENU_SQL_LITERAL_PRESENT');
    if ($sql[$position] === "'") {
        ++$position; $value = ''; $closed = false;
        while (isset($sql[$position])) {
            $character = $sql[$position++];
            if ($character === '\\') {
                m2f_assert(isset($sql[$position]), 'ORDER_MENU_SQL_STRING_ESCAPE_COMPLETE');
                $escaped = $sql[$position++];
                $value .= match ($escaped) {
                    '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                    default => $escaped,
                };
            } elseif ($character === "'") {
                if (($sql[$position] ?? null) === "'") { $value .= "'"; ++$position; }
                else { $closed = true; break; }
            } else { $value .= $character; }
        }
        m2f_assert($closed, 'ORDER_MENU_SQL_STRING_CLOSED');
        return $value;
    }
    $start = $position;
    while (isset($sql[$position]) && $sql[$position] !== ',' && $sql[$position] !== ')') ++$position;
    $literal = trim(substr($sql, $start, $position - $start));
    if (strcasecmp($literal, 'NULL') === 0) return null;
    m2f_assert(preg_match('/\A(?:0|[1-9][0-9]*|-[1-9][0-9]*)\z/D', $literal) === 1
        && (string)(int)$literal === $literal, 'ORDER_MENU_SQL_CANONICAL_INTEGER_LITERAL');
    return (int)$literal;
}

function m4iha_source(string $sql): array
{
    m2f_assert(strlen($sql) === 10237459 && hash('sha256', $sql) === '1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828', 'HISTORY_ANCHOR_EXACT_SHARED_LEGACY_SQL');
    $meta = json_decode(M4IHA_SOURCE_META, true, 16, JSON_THROW_ON_ERROR);
    $anchorNames = ['order_root', 'refund_page'];
    m2f_assert(array_keys($meta) === ['columns', 'rows'] && count($meta['columns']) === 22
        && count(array_unique($meta['columns'])) === 22
        && array_keys($meta['rows']) === $anchorNames,
        'ORDER_MENU_FIXED_DECLARATIVE_META');
    $matched = preg_match_all('/^INSERT INTO `eb_system_menus` \(([^\r\n]+)\) VALUES\r?\n/m', $sql, $headers, PREG_OFFSET_CAPTURE);
    m2f_assert($matched === 1 && strlen($sql) <= 16777216, 'ORDER_MENU_ONE_ORIGINAL_SQL_STATEMENT');
    $header = $headers[1][0][0];
    m2f_assert(preg_match('/\A`[a-z_]+`(?:,\s*`[a-z_]+`)*\z/D', $header) === 1,
        'ORDER_MENU_SQL_ONLY_DECLARED_COLUMN_IDENTIFIERS');
    preg_match_all('/`([a-z_]+)`/', $header, $matches); $columns = $matches[1];
    m2f_assert($columns === $meta['columns'], 'ORDER_MENU_ORIGINAL_EXACT_22_COLUMNS');
    $selected = []; $names = [];
    foreach ($meta['rows'] as $name => $cells) {
        m2f_assert(array_is_list($cells) && count($cells) === 22, 'ORDER_MENU_COMPLETE_SOURCE_ROW');
        $names[$cells[array_search('unique_auth', $columns, true)]] = $name;
    }
    m2f_assert(count($names) === count($anchorNames), 'ORDER_MENU_DECLARED_UNIQUE_SOURCE_IDENTITIES');
    $position = $headers[0][0][1] + strlen($headers[0][0][0]); $tupleCount = 0;
    while (true) {
        while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
        m2f_assert(($sql[$position] ?? null) === '(', 'ORDER_MENU_SQL_TUPLE_OPEN'); ++$position;
        $cells = [];
        for ($index = 0; $index < 22; ++$index) {
            $cells[] = m4iha_literal($sql, $position);
            while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
            m2f_assert(($sql[$position] ?? null) === ($index === 21 ? ')' : ','), 'ORDER_MENU_SQL_EXACT_TUPLE_SEPARATOR');
            ++$position;
        }
        ++$tupleCount; $unique = $cells[array_search('unique_auth', $columns, true)];
        if (is_string($unique) && isset($names[$unique])) {
            $name = $names[$unique]; m2f_assert(!isset($selected[$name]), 'ORDER_MENU_SOURCE_IDENTITY_NOT_DUPLICATED');
            $selected[$name] = $cells;
        }
        while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
        $delimiter = $sql[$position] ?? null;
        m2f_assert($delimiter === ',' || $delimiter === ';', 'ORDER_MENU_SQL_STATEMENT_CLOSED'); ++$position;
        if ($delimiter === ';') break;
    }
    $ordered = [];
    foreach ($meta['rows'] as $name => $cells) {
        m2f_assert(isset($selected[$name]) && $selected[$name] === $cells, 'ORDER_MENU_RUNTIME_SQL_EQUALS_FULL_FIXED_SOURCE_META');
        $ordered[$name] = $selected[$name];
    }
    m2f_assert(count($ordered) === count($anchorNames) && $tupleCount >= count($anchorNames), 'ORDER_MENU_COMPLETE_DECLARED_ORIGINAL_ANCHORS');
    return ['columns' => $columns, 'rows' => $ordered];
}

function m4iha_project(array $rows, array $columns): array
{
    $hashes=[];
    foreach($rows as$row){
        m2f_assert(is_array($row)&&array_keys($row)===$columns,'HISTORY_ANCHOR_EXACT_TYPED_COLUMNS');
        $hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
    }
    sort($hashes,SORT_STRING);
    return ['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$hashes))];
}
function m4iha_receipt(array $receipt,string $sql): void
{
    $keys=array_keys($receipt);sort($keys,SORT_STRING);
    $wanted=['schema','scope','sql_source','columns','inserted_rows','original_ms1_rows','rows_after','before','after','initialized_before_legacy37'];sort($wanted,SORT_STRING);
    m2f_assert($keys===$wanted&&$receipt['schema']===1
        &&$receipt['scope']==='CURRENT_HISTORY_ORIGINAL_REFUND_PARENT_PAGES_ONLY'
        &&$receipt['initialized_before_legacy37']===true,'HISTORY_ANCHOR_EXACT_INITIALIZATION_RECEIPT');
    $meta=m4iha_source($sql);$columns=$meta['columns'];$rows=[];
    foreach($meta['rows']as$cells)$rows[]=array_combine($columns,$cells);
    m2f_assert($receipt['sql_source']===['path'=>'crmeb/public/install/crmeb.sql','bytes'=>strlen($sql),'sha256'=>hash('sha256',$sql)]
        &&$receipt['columns']===$columns&&$receipt['inserted_rows']===$rows
        &&array_column($rows,'id')===[4,897]&&$rows[1]['pid']===4,'HISTORY_ANCHOR_COMPLETE_ORIGINAL_SQL_ROWS_AND_CHAIN');
    $old=$receipt['original_ms1_rows'];$auth=['marketplace-merchants-list','marketplace-merchants-create','marketplace-merchants-read',
        'marketplace-merchants-update','marketplace-merchants-review','marketplace-merchants-flags','marketplace-merchants-admin-employee'];
    m2f_assert(is_array($old)&&array_is_list($old)&&count($old)===7
        &&array_column($old,'id')===range(2473,2479)&&array_column($old,'unique_auth')===$auth,'HISTORY_ANCHOR_ORIGINAL_SEVEN_MS1_ROWS');
    foreach($old as$row)m2f_assert(array_keys($row)===$columns&&is_int($row['id'])&&$row['auth_type']===2,'HISTORY_ANCHOR_ORIGINAL_NATIVE_MS1_ROW_TYPES');
    $tables=['eb_system_menus','eb_system_role','eb_marketplace_role'];
    m2f_assert(array_keys($receipt['before'])===$tables&&array_keys($receipt['after'])===$tables,'HISTORY_ANCHOR_EXACT_THREE_TABLE_OBSERVATIONS');
    foreach($tables as$table){
        foreach(['before','after']as$phase){
            $view=$receipt[$phase][$table];
            m2f_assert(array_keys($view)===['metadata','ddl','columns','projection']&&is_array($view['metadata'])
                &&is_string($view['ddl'])&&$view['ddl']!==''&&$view['columns']===array_keys($view['metadata']['columns'])
                &&array_keys($view['projection'])===['rows','sha256']&&is_int($view['projection']['rows'])&&$view['projection']['rows']>=0
                &&is_string($view['projection']['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$view['projection']['sha256'])===1,
                'HISTORY_ANCHOR_COMPLETE_METADATA_DDL_AND_TYPED_PROJECTION');
        }
        if($table!=='eb_system_menus')m2f_assert($receipt['before'][$table]===$receipt['after'][$table],'HISTORY_ANCHOR_NO_ROLE_DATA_METADATA_OR_COUNTER_CHANGE');
    }
    $before=$receipt['before']['eb_system_menus'];$after=$receipt['after']['eb_system_menus'];
    $counter=$before['metadata']['table']['AUTO_INCREMENT'];
    m2f_assert((is_int($counter)||is_string($counter))&&(string)$counter==='2480'
        &&$before['metadata']===$after['metadata']&&$before['ddl']===$after['ddl']
        &&$before['columns']===$columns&&$after['columns']===$columns,'HISTORY_ANCHOR_ONLY_EXPLICIT_OLD_IDS_NO_COUNTER_OR_DDL_CHANGE');
    $all=array_merge($rows,$old);
    m2f_assert($receipt['rows_after']===$all&&$before['projection']===m4iha_project($old,$columns)
        &&$after['projection']===m4iha_project($all,$columns),'HISTORY_ANCHOR_EXACT_ONLY_TWO_ROWS_ALL_SEVEN_ORIGINAL_TYPED_VALUES');
}
'''.encode('utf-8')

_LEGACY_ANCHOR_CAPTURE = rb'''function m4iha_snapshot(PDO $db): array
{
    $native=m4m_rows($db,'SELECT CURRENT_USER() AS actor,DATABASE() AS db,@@SESSION.information_schema_stats_expiry AS stats_expiry,@@SESSION.auto_increment_increment AS increment_value,@@SESSION.auto_increment_offset AS offset_value');
    m2f_assert(count($native)===1&&$native[0]['actor']==='audit_app@%'&&$native[0]['db']==='audit_auth'
        &&(string)$native[0]['stats_expiry']==='0'&&(string)$native[0]['increment_value']==='1'&&(string)$native[0]['offset_value']==='1',
        'HISTORY_ANCHOR_ORIGINAL_DML_AND_FRESH_UNCACHED_NATIVE_METADATA');
    $result=[];
    foreach(['eb_system_menus','eb_system_role','eb_marketplace_role']as$table){
        $metadata=m4m_metadata($db,$table);$columns=array_keys($metadata['columns']);
        $ddl=$db->query('SHOW CREATE TABLE '.m4m_quote($table))->fetch(PDO::FETCH_NUM);
        m2f_assert(is_array($ddl)&&is_string($ddl[1]??null),'HISTORY_ANCHOR_REAL_COMPLETE_DDL');
        $result[$table]=['metadata'=>$metadata,'ddl'=>$ddl[1],'columns'=>$columns,'projection'=>m4m_projection($db,$table,$columns)];
    }
    return $result;
}
'''

_LEGACY_ANCHOR_INSTALL = rb'''function m4iha_install(PDO $db,string $sql,array $ms1MenuIds): array
{
    $meta=m4iha_source($sql);$columns=$meta['columns'];$rows=[];
    foreach($meta['rows']as$cells)$rows[]=array_combine($columns,$cells);
    m2f_assert($ms1MenuIds===range(2473,2479)&&!$db->inTransaction()
        &&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        &&$db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION,'HISTORY_ANCHOR_ORIGINAL_COMPLETED_MS1_AND_OWN_NATIVE_TRANSACTION');
    m2f_assert($db->beginTransaction(),'HISTORY_ANCHOR_BEGIN');
    try{
        $before=m4iha_snapshot($db);$old=m4m_rows($db,'SELECT * FROM eb_system_menus ORDER BY id');
        m2f_assert(count($old)===7&&array_column($old,'id')===$ms1MenuIds,'HISTORY_ANCHOR_ONLY_SEVEN_ORIGINAL_MENU_ROWS_BEFORE');
        $collision=m4m_rows($db,'SELECT id FROM eb_system_menus WHERE id IN (?,?) OR unique_auth IN (?,?) OR (auth_type=1 AND menu_path IN (?,?))',
            [4,897,'admin-order','admin-order-refund','/order','/order/refund']);
        m2f_assert($collision===[],'HISTORY_ANCHOR_NO_OLD_ID_PERMISSION_OR_ROUTE_COLLISION');
        $statement=$db->prepare('INSERT INTO eb_system_menus ('.implode(',',array_map('m4m_quote',$columns)).') VALUES ('.implode(',',array_fill(0,22,'?')).')');
        foreach($rows as$row)m2f_assert($statement->execute(array_values($row))&&$statement->rowCount()===1,'HISTORY_ANCHOR_EXACTLY_ONE_ORIGINAL_SQL_ROW_INSERT');
        $after=m4iha_snapshot($db);$all=m4m_rows($db,'SELECT * FROM eb_system_menus ORDER BY id');
        $receipt=['schema'=>1,'scope'=>'CURRENT_HISTORY_ORIGINAL_REFUND_PARENT_PAGES_ONLY',
            'sql_source'=>['path'=>'crmeb/public/install/crmeb.sql','bytes'=>strlen($sql),'sha256'=>hash('sha256',$sql)],
            'columns'=>$columns,'inserted_rows'=>$rows,'original_ms1_rows'=>$old,'rows_after'=>$all,
            'before'=>$before,'after'=>$after,'initialized_before_legacy37'=>true];
        m4iha_receipt($receipt,$sql);
        m2f_assert($db->commit()&&!$db->inTransaction(),'HISTORY_ANCHOR_COMMIT');
        m2f_assert(m4iha_snapshot($db)===$after&&m4m_rows($db,'SELECT * FROM eb_system_menus ORDER BY id')===$all,
            'HISTORY_ANCHOR_REAL_POSTCOMMIT_ROWS_METADATA_AND_ROLES');
        return $receipt;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw$error;}
}
'''

_LEGACY_ANCHOR_PRODUCER_CONTEXT = rb'''function nhepa_anchor_context(array $qualified,PDO $db): void
{
    nhe_business_self_id($qualified);
    $path='/run/audit/evidence/initializer-result.json';$archive='/run/audit/evidence/nonempty-history-phases/before-legacy-files/evidence/initializer-result.json';
    $raw=file_get_contents($path);$saved=file_get_contents($archive);
    nhep_expect(is_string($raw)&&$raw===$saved,'HISTORY_ANCHOR_PRODUCER_ORIGINAL_AND_ARCHIVED_INITIALIZER_BYTES');
    $result=json_decode($raw,true,128,JSON_THROW_ON_ERROR);$receipt=$result['legacy_refund_page_anchors'];
    $sql=file_get_contents('/workspace/crmeb/public/install/crmeb.sql');
    nhep_expect(is_string($sql),'HISTORY_ANCHOR_PRODUCER_ORIGINAL_SQL_BYTES');
    nhepa_receipt($receipt,$sql);
    nhep_expect(nhepa_snapshot($db)===$receipt['after']&&m4m_rows($db,'SELECT * FROM eb_system_menus ORDER BY id')===$receipt['rows_after'],
        'HISTORY_ANCHOR_PRODUCER_REAL_NINE_ROWS_AND_BOTH_ROLES_BEFORE_PRE37');
}
'''

def _legacy_anchor_initializer_seams():
    helper = _LEGACY_ANCHOR_PURE + b'\n' + _LEGACY_ANCHOR_CAPTURE + b'\n' + _LEGACY_ANCHOR_INSTALL + b'\n'
    anchor = b'function m4i_initialize(array &$evidence, ?array &$primary, ?array &$cleanup): void\n'
    old_call = b'        m4i_defaultShipping($evidence);\n'
    new_call = old_call + (b"        m4i_source(); audit_guard_assert();\n"
        b"        $sql=file_get_contents('/source/crmeb/public/install/crmeb.sql');\n"
        b"        m2f_assert(is_string($sql),'HISTORY_ANCHOR_REAL_BOUND_INSTALL_SQL');\n"
        b"        $evidence['legacy_refund_page_anchors']=m4iha_install(audit_db(),$sql,$evidence['children'][2]['result']['menu_ids']);\n"
        b"        m4i_source(); audit_guard_assert();\n")
    schema_before = b"function m4iu_schema(PDO $db, array $snapshot): array\n{\n"
    schema_after = schema_before + b"    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);\n"
    refund_before = b"                \\crmeb\\services\\marketplace\\RefundSchemaReadiness::assertReady(audit_db(),'eb_');"
    refund_after = (b"                $refundObserver=audit_db();$refundObserver->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);\n"
                    b"                \\crmeb\\services\\marketplace\\RefundSchemaReadiness::assertReady($refundObserver,'eb_');")
    return ((anchor, helper + anchor), (old_call, new_call),
            (schema_before, schema_after), (refund_before, refund_after),
            (b"count($expected['names']) === 173", b"count($expected['names']) === 174"),
            (b"CURRENT_READER_REGISTER_ACTUAL_FULL173_UPGRADE_BASELINE", b"CURRENT_READER_REGISTER_ACTUAL_FULL174_UPGRADE_BASELINE"))


_REFUND_CART_TEMPORARY_PRODUCER_SEAMS = (
    (b"    foreach ($dto['cartInfo'] as &$detailCart) {\n",
     b"    foreach ($dto['cartInfo'] ?? [] as &$detailCart) {\n"),
    (b"        && $dto['cart_info'] === [$cart] && $dto['cartInfo'][0]['sum_true_price'] === $cart['sum_true_price']\n"
     b"        && array_keys($dto['cartInfo'][0]) === array_merge(array_keys($cart), ['vip_sum_truePrice'])\n"
     b"        && $dto['cartInfo'][0]['vip_sum_truePrice'] === '0.00'\n",
     b"        && $dto['cart_info'] === [$cart] && $dto['cartInfo'] === [$cart]\n"
     b"        && $dto['cartInfo'][0]['sum_true_price'] === $cart['sum_true_price']\n"),
)


_REFUND_APPLY_LOG_PRODUCER_SEAMS = (
    (b"    foreach (['nhep_expect', 'nhe_keys', 'nhepod_context', 'nhepcv_native_row', 'nhepcv_config',\n",
     b"    foreach (['nhep_expect', 'nhe_keys', 'nhepod_context', 'nhepcv_native_row', 'nhepcv_config', 'nhepcd_one',\n"),
    ("""    // Original status column query has no order. Reject ambiguous duplicate keys instead of choosing a row.
    $logs = [];
    $refundMessages = $refunded ? ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功',
        'integral_back' => '商品退积分', 'refund_price' => '退款给用户：20.15元'] : [];
""".encode('utf-8'),
     """    // Original status column query has no order. Reject ambiguous duplicate keys instead of choosing a row.
    if ($refunded) {
        $savedRefundSelection = nhepcd_one($beforeDB2, 'eb_store_order_refund',
            ['store_order_id' => $order['id'], 'uid' => $fixture['uid']]);
        $savedRefundReasonRow = nhepcv_native_row($beforeDB2, 'eb_store_order_refund', $savedRefundSelection['index']);
        nhep_expect($savedRefundReasonRow === $savedRefundSelection['row']
            && $savedRefundReasonRow['refund_reason'] === 'normal-refund-' . $intent['run_id']
            && $order['refund_reason_wap'] === $savedRefundReasonRow['refund_reason'],
            'NHE_DETAIL_VALUE_ORIGINAL_SAVED_APPLY_REASON_AND_ROOT_MATCH_FIXED_ACTOR');
    }
    $logs = [];
    $refundMessages = $refunded ? ['cache_key_create_order' => '订单生成', 'pay_success' => '用户付款成功',
        'apply_refund' => '用户申请退款，原因：' . $savedRefundReasonRow['refund_reason'],
        'integral_back' => '商品退积分', 'refund_price' => '退款给用户：20.15元'] : [];
""".encode('utf-8')),
    (b"    if ($shipped) nhep_expect($logs['delivery_goods'] >= $logs['pay_success']\n",
     b"    if ($refunded) nhep_expect($logs['pay_success'] <= $logs['apply_refund']\n"
     b"        && $logs['apply_refund'] <= $logs['integral_back'] && $logs['integral_back'] <= $logs['refund_price'],\n"
     b"        'NHE_DETAIL_VALUE_REFUNDED_ORIGINAL_APPLY_AND_REFUND_LOG_ORDER');\n"
     b"    if ($shipped) nhep_expect($logs['delivery_goods'] >= $logs['pay_success']\n"),
    ("""        $messages += $refunded ? ['integral_back' => '商品退积分', 'refund_price' => '退款给用户：20.15元']
""".encode('utf-8'),
     """        $messages += $refunded ? ['apply_refund' => '用户申请退款，原因：' . $root['refund_reason_wap'],
            'integral_back' => '商品退积分', 'refund_price' => '退款给用户：20.15元']
""".encode('utf-8')),
    (b'NHE_HISTORY_IDENTITIES_EXACT_REFUND_FOUR_OR_COMPLETED_FIVE_HISTORY_LOGS',
     b'NHE_HISTORY_IDENTITIES_EXACT_REFUND_FIVE_OR_COMPLETED_FIVE_HISTORY_LOGS'),
    (b"            && $logs['pay_success'] >= $root['pay_time'], 'NHE_HISTORY_IDENTITIES_NATIVE_CREATE_PAY_LOG_ORDER');\n",
     b"            && $logs['pay_success'] >= $root['pay_time']\n"
     b"            && (!$refunded || ($logs['pay_success'] <= $logs['apply_refund']\n"
     b"                && $logs['apply_refund'] <= $logs['integral_back'] && $logs['integral_back'] <= $logs['refund_price'])),\n"
     b"            'NHE_HISTORY_IDENTITIES_NATIVE_CREATE_PAY_LOG_ORDER');\n"),
)


def _legacy_anchor_producer_seams():
    pure = (_LEGACY_ANCHOR_PURE + b'\n' + _LEGACY_ANCHOR_CAPTURE).replace(b'm4iha_',b'nhepa_').replace(b'M4IHA_',b'NHEPA_').replace(b'm2f_assert',b'nhep_expect')
    anchor=b'function nhep_normal_history_main(): int\r\n'
    call=b'        $prepared = nhep_pre37_fixed_preparation($qualified, $observer, $redis);\r\n'
    return ((anchor,pure+b'\n'+_LEGACY_ANCHOR_PRODUCER_CONTEXT+b'\n'+anchor),
            (call,b'        nhepa_anchor_context($qualified,$observer);\r\n'+call),
            (b"$root['give_integral'] === '0.00'", b"$root['give_integral'] === 0")) + _PAYMENT_DURING_PRODUCER_SEAMS + _REFUND_CART_TEMPORARY_PRODUCER_SEAMS + _REFUND_APPLY_LOG_PRODUCER_SEAMS


# Explicit current two-anchor private observation only; old default outputs stay byte-identical.
_PAYMENT_DURING_PRODUCER_SEAMS = (
    (b'function nhep_auth_observers(array $qualified, PDO $db, Redis $redis, array &$children): array', b"/** Private during evidence for only the two original balance-payment requests. */\nfunction nheppd_safe_evidence(#[SensitiveParameter] array $safe): array\n{\n    nhe_keys($safe, ['key_family', 'ttl_seconds', 'http_first_s', 'http_last_s', 'before_redis_ms',\n        'witness_redis_ms', 'after_redis_ms', 'expiry_bound_ms', 'witness_seen', 'bound_kind'], 'NHEPOPR_SAFE_EVIDENCE_KEYS');\n    nhep_expect(($safe['key_family'] === 'PAY_LOCK' && $safe['ttl_seconds'] === 2)\n        || ($safe['key_family'] === 'PRODUCT_COUPON' && $safe['ttl_seconds'] === 7200), 'NHEPOPR_SAFE_FIXED_KEY_FAMILY_TTL');\n    nhep_expect(is_int($safe['http_first_s']) && is_int($safe['http_last_s']) && $safe['http_first_s'] > 0\n        && $safe['http_first_s'] <= $safe['http_last_s'] && $safe['http_last_s'] <= 2147483647\n        && is_bool($safe['witness_seen']) && in_array($safe['bound_kind'], ['LIVE_WITNESS', 'LATEST_BOUND'], true)\n        && $safe['witness_seen'] === ($safe['bound_kind'] === 'LIVE_WITNESS'), 'NHEPOPR_SAFE_FIXED_CLOCK_FIELDS');\n    foreach (['before_redis_ms', 'after_redis_ms', 'expiry_bound_ms'] as $name)\n        nhep_expect(is_string($safe[$name]) && preg_match('/\\A(?:0|[1-9][0-9]{0,18})\\z/D', $safe[$name]) === 1,\n            'NHEPOPR_SAFE_DECIMAL_CLOCK_ONLY');\n    nhep_expect($safe['witness_redis_ms'] === null ? !$safe['witness_seen']\n        : ($safe['witness_seen'] && is_string($safe['witness_redis_ms'])\n            && preg_match('/\\A(?:0|[1-9][0-9]{0,18})\\z/D', $safe['witness_redis_ms']) === 1),\n        'NHEPOPR_SAFE_OPTIONAL_WITNESS_CLOCK_ONLY');\n    return $safe;\n}\nfinal class NhepPayRedisEvidenceFailure extends RuntimeException\n{\n    private array $safe;\n    public function __construct(string $code, #[SensitiveParameter] array $safe)\n    {\n        parent::__construct($code); $this->safe = nheppd_safe_evidence($safe);\n    }\n    public function evidence(): array { return $this->safe; }\n}\nfunction nheppd_bound(#[SensitiveParameter] array $state, #[SensitiveParameter] array $qualified,\n    #[SensitiveParameter] PDO $db, #[SensitiveParameter] Redis $redis, #[SensitiveParameter] array $intent, #[SensitiveParameter] array $request,\n    string $beforeHash): void\n{\n    nhe_keys($state, ['intent', 'request', 'before_sha256', 'run_id', 'redis_client', 'redis_db', 'db_client',\n        'specs', 'witnesses', 'error', 'next_due_ns'], 'NHEPOPR_DURING_EXACT_PRIVATE_STATE');\n    nhep_expect($state['intent'] === $intent && $state['request'] === $request\n        && $state['before_sha256'] === $beforeHash && $state['run_id'] === $qualified['context']['run_id']\n        && $intent['run_id'] === $state['run_id']\n        && (($intent['step'] === 'refund_family_pay' && $intent['sequence'] === 13)\n            || ($intent['step'] === 'review_family_pay' && $intent['sequence'] === 19))\n        && $state['redis_client'] === spl_object_id($redis) && $state['db_client'] === spl_object_id($db)\n        && $state['redis_db'] === 0 && $redis->isConnected() && $redis->getHost() === 'audit-redis'\n        && $redis->getPort() === 6379 && $redis->getDBNum() === 0\n        && array_keys($state['specs']) === ['PAY_LOCK', 'PRODUCT_COUPON']\n        && is_array($state['witnesses']) && array_diff(array_keys($state['witnesses']), array_keys($state['specs'])) === []\n        && in_array($state['error'], [null, 'NHEPOPR_DURING_SAMPLE_FAILED'], true)\n        && is_int($state['next_due_ns']) && $state['next_due_ns'] >= 0, 'NHEPOPR_DURING_SAME_PENDING_RUN_CLIENT_AND_DB');\n}\nfunction nheppd_begin(#[SensitiveParameter] array $qualified, #[SensitiveParameter] PDO $db, #[SensitiveParameter] Redis $redis,\n    #[SensitiveParameter] array $intent, #[SensitiveParameter] array $request,\n    #[SensitiveParameter] array $snapshot, #[SensitiveParameter] array $context): array\n{\n    nhep_expect($context === nhepop_context(['database' => $snapshot['database'], 'rows' => $snapshot['rows']], $intent, $request),\n        'NHEPOPR_DURING_ORIGINAL_BEFORE_CONTEXT');\n    $state = ['intent' => $intent, 'request' => $request, 'before_sha256' => hash('sha256', serialize($snapshot)),\n        'run_id' => $qualified['context']['run_id'], 'redis_client' => spl_object_id($redis), 'redis_db' => 0,\n        'db_client' => spl_object_id($db), 'specs' => [\n            'PAY_LOCK' => ['key' => 'c:PAY_LOCK_' . $context['order']['row']['order_id'], 'wire' => serialize('PAY_LOCK'), 'ttl' => 2],\n            'PRODUCT_COUPON' => ['key' => 'c:order_product_coupon_' . $context['fixture']['uid'] . '_' . $context['order']['row']['id'],\n                'wire' => serialize([]), 'ttl' => 7200]],\n        'witnesses' => [], 'error' => null, 'next_due_ns' => 0];\n    nheppd_bound($state, $qualified, $db, $redis, $intent, $request, $state['before_sha256']);\n    foreach ($state['specs'] as $spec)\n        nhep_expect(!array_key_exists(hash('sha256', $spec['key']), $snapshot['redis']['keys']),\n            'NHEPOPR_DURING_BOTH_KEYS_ABSENT_BEFORE');\n    return $state;\n}\nfunction nheppd_sample(#[SensitiveParameter] array $qualified, #[SensitiveParameter] PDO $db, #[SensitiveParameter] Redis $redis,\n    #[SensitiveParameter] array $intent, #[SensitiveParameter] array $request,\n    #[SensitiveParameter] ?array $pending, #[SensitiveParameter] ?array &$state): void\n{\n    $capture = null; $attempted = false;\n    try {\n        nhep_expect(is_array($state) && is_array($pending), 'NHEPOPR_DURING_REAL_PENDING_STATE');\n        nheppd_bound($state, $qualified, $db, $redis, $intent, $request, $pending['before_sha256']);\n        nhep_expect($pending['intent'] === $intent && $pending['request'] === $request,\n            'NHEPOPR_DURING_CURRENT_PENDING_INTENT_REQUEST');\n        if ($state['error'] !== null || count($state['witnesses']) === 2 || hrtime(true) < $state['next_due_ns']) return;\n        $attempted = true; $capture = nhep_redis_capture($redis, $state['run_id']);\n        // Presence alone selects the first witness. Wrong type/value/TTL is retained, never filtered away.\n        foreach ($state['specs'] as $family => $spec)\n            if (!isset($state['witnesses'][$family]) && nhepar_entry($capture, $spec['key']) !== null)\n                $state['witnesses'][$family] = $capture;\n    } catch (Throwable) {\n        if (!is_array($state)) $state = [];\n        $state['error'] = 'NHEPOPR_DURING_SAMPLE_FAILED';\n    } finally {\n        unset($capture);\n        if ($attempted) {\n            try { $state['next_due_ns'] = hrtime(true) + 100000000; }\n            catch (Throwable) { $state['error'] = 'NHEPOPR_DURING_SAMPLE_FAILED'; }\n        }\n    }\n}\nfunction nheppd_microseconds(#[SensitiveParameter] array $capture): string\n{\n    return bcadd(bcmul($capture['snapshot']['time'][0], '1000000', 0), $capture['snapshot']['time'][1], 0);\n}\nfunction nheppd_clock_window(#[SensitiveParameter] array $before, #[SensitiveParameter] array $witness,\n    #[SensitiveParameter] array $actual, int $expires, int $ttl, array $interval): void\n{\n    $writeMs = bcsub((string)$expires, bcmul((string)$ttl, '1000', 0), 0);\n    $firstMs = bcmul((string)$interval[0], '1000', 0);\n    $lastMs = bcsub(bcmul(bcadd((string)$interval[1], '1', 0), '1000', 0), '1', 0);\n    nhep_expect(bccomp(nheppd_microseconds($before), nheppd_microseconds($witness), 0) <= 0\n        && bccomp(nheppd_microseconds($witness), nheppd_microseconds($actual), 0) <= 0\n        && bccomp($writeMs, $before['snapshot']['time_ms'], 0) >= 0\n        && bccomp($writeMs, $witness['snapshot']['time_ms'], 0) <= 0\n        && bccomp($writeMs, $firstMs, 0) >= 0 && bccomp($writeMs, $lastMs, 0) <= 0\n        && bccomp($witness['snapshot']['time_ms'], $firstMs, 0) >= 0\n        && bccomp($witness['snapshot']['time_ms'], $lastMs, 0) <= 0\n        && bccomp($witness['snapshot']['time_ms'], (string)$expires, 0) <= 0,\n        'NHEPOPR_DURING_NATIVE_MICROSECOND_ORDER_SOURCE_TTL_AND_HTTP_BOUNDS');\n}\nfunction nheppd_witness(#[SensitiveParameter] ?array $witness, #[SensitiveParameter] array $before,\n    #[SensitiveParameter] array $actual, string $runId, #[SensitiveParameter] string $key,\n    #[SensitiveParameter] string $wire, int $ttl, array $interval): ?array\n{\n    if ($witness === null) return null;\n    nhepar_capture($witness); $row = nhepar_entry($witness, $key); $sentinel = nhepar_entry($witness, 'audit:sentinel');\n    nhep_expect(is_array($sentinel) && $sentinel['type'] === 'string' && $sentinel['value'] === $runId\n        && $sentinel['expires'] === -1 && is_array($row), 'NHEPOPR_DURING_SAME_RUN_AND_FIRST_LIVE_KEY');\n    nhep_expect($row['type'] === 'string' && $row['value'] === $wire && is_int($row['expires']) && $row['expires'] > 0,\n        'NHEPOPR_DURING_FIRST_LIVE_INDEPENDENT_WIRE_TYPE_AND_DEADLINE');\n    nheppd_clock_window($before, $witness, $actual, $row['expires'], $ttl, $interval);\n    return $row;\n}\n\nfunction nhep_auth_observers(array $qualified, #[SensitiveParameter] PDO $db, #[SensitiveParameter] Redis $redis, array &$children): array"),
    (b'    $accepted7 = null;', b'    $accepted7 = null; $payDuring = null;'),
    (b'    $before = static function (array $intent, #[SensitiveParameter] array $privateRequest) use (', b'    $during = static function (#[SensitiveParameter] array $intent, #[SensitiveParameter] array $privateRequest) use (\n        $qualified, $db, $redis, &$pending, &$payDuring): void {\n        nheppd_sample($qualified, $db, $redis, $intent, $privateRequest, $pending, $payDuring);\n    };\n    $before = static function (array $intent, #[SensitiveParameter] array $privateRequest) use ('),
    (b'        $qualified, $db, $redis, &$children, $gate, $commerceSteps, $createSteps, $detailSteps, $paySteps, &$commerce, &$pending, &$failed): array {', b'        $qualified, $db, $redis, &$children, $gate, $commerceSteps, $createSteps, $detailSteps, $paySteps, &$commerce, &$pending, &$failed, &$payDuring): array {'),
    (b'        $gate($intent); $failed = true; $private = [];', b'        $gate($intent); $payDuring = null; $failed = true; $private = [];'),
    (b'                $eventContext = nhepop_context($eventBeforeDB2, $intent, $privateRequest);\n                nhepop_before_guards($eventBeforeDB2, $intent, $eventContext);', b'                $eventContext = nhepop_context($eventBeforeDB2, $intent, $privateRequest);\n                nhepop_before_guards($eventBeforeDB2, $intent, $eventContext);\n                $payDuring = nheppd_begin($qualified, $db, $redis, $intent, $privateRequest, $snapshot, $eventContext);'),
    (b'        #[SensitiveParameter] array $privateRequest, #[SensitiveParameter] array $privateResponse) use (', b'        #[SensitiveParameter] array $privateRequest, #[SensitiveParameter] array $privateResponse, bool $duringBridgeFailed = false) use ('),
    (b'        $commerceSteps, $createSteps, $detailSteps, $paySteps, &$commerce, &$pending, &$completed, &$failed, &$cookieIds, &$accepted7): array {', b'        $commerceSteps, $createSteps, $detailSteps, $paySteps, &$commerce, &$pending, &$completed, &$failed, &$cookieIds, &$accepted7, &$payDuring): array {'),
    (b"        $beforeHash = $pending['before_sha256']; $beforePrivate = $pending['private'];", b"        $beforeHash = $pending['before_sha256']; $beforePrivate = $pending['private'];\n        $payDuringState = $payDuring; $payDuring = null;"),
    (b'            $database = nhep_order_pay_database_model($beforeDB2, $actualDB2, $intent, $privateRequest, $privateResponse);\n            $cache = nhep_order_pay_redis_model(', b"            $database = nhep_order_pay_database_model($beforeDB2, $actualDB2, $intent, $privateRequest, $privateResponse);\n            nhep_expect(!$duringBridgeFailed, 'NHEPOPR_DURING_CALLBACK_BRIDGE_FAILED');\n            nhep_expect(is_array($payDuringState), 'NHEPOPR_DURING_PAYMENT_STATE_REQUIRED');\n            nheppd_bound($payDuringState, $qualified, $db, $redis, $intent, $privateRequest, $beforeHash);\n            nhep_expect($payDuringState['error'] === null, 'NHEPOPR_DURING_STICKY_SAMPLE_FAILURE');\n            $payContext = nhepop_context($beforeDB2, $intent, $privateRequest);\n            $payIndex = nhep_auth_db_index($database['rows']['eb_store_order'], $payContext['order']['metadata'], 'id', $payContext['order']['row']['id']);\n            $verifiedPayTime = $database['rows']['eb_store_order'][$payIndex]['pay_time'];\n            $cache = nhep_order_pay_redis_model("),
    (b"                $beforeDB2, $intent, $privateRequest, $privateResponse, $privateAuthConfiguration);\n        } elseif ($intent['step'] === 'unshipped_refund_apply')", b"                $beforeDB2, $intent, $privateRequest, $privateResponse, $privateAuthConfiguration, $payDuringState, $verifiedPayTime);\n        } elseif ($intent['step'] === 'unshipped_refund_apply')"),
    (b'    $finish = static function () use (&$completed, &$failed, &$pending, &$commerce, &$accepted7): array {', b'    $finish = static function () use (&$completed, &$failed, &$pending, &$commerce, &$accepted7, &$payDuring): array {'),
    (b'        nhep_expect($completed === 37 && $pending === null && $failed === false', b'        nhep_expect($completed === 37 && $pending === null && $failed === false && $payDuring === null'),
    (b"    nheh_observers($qualified, $qualified['context']['run_id'], $before, $after);\n    return ['before' => $before, 'after' => $after, 'finish' => $finish];", b"    nheh_observers($qualified, $qualified['context']['run_id'], $before, $after, $during);\n    return ['before' => $before, 'after' => $after, 'finish' => $finish, 'during' => $during];"),
    (b"        nhe_keys($oracles, ['before', 'after', 'finish'], 'NHE_NORMAL_MAIN_ORIGINAL_WHOLE37_THREE_CLOSURES');", b"        nhe_keys($oracles, ['before', 'after', 'finish', 'during'], 'NHEPOPR_ORIGINAL_WHOLE37_WITH_PRIVATE_DURING_CLOSURE');"),
    (b"            $prepared['actors'], $prepared['fixture_receipt'], $oracles['before'], $oracles['after']);", b"            $prepared['actors'], $prepared['fixture_receipt'], $oracles['before'], $oracles['after'], $oracles['during']);"),
    (b'function nhep_order_pay_redis_model(#[SensitiveParameter] array $beforeCapture,\n    #[SensitiveParameter] array $actualCapture, #[SensitiveParameter] array $beforeDB2, array $intent,\n    #[SensitiveParameter] array $privateRequest, #[SensitiveParameter] array $privateResponse,\n    #[SensitiveParameter] array $privateAuthConfiguration): array', b'function nhep_order_pay_redis_model(#[SensitiveParameter] array $beforeCapture,\n    #[SensitiveParameter] array $actualCapture, #[SensitiveParameter] array $beforeDB2, array $intent,\n    #[SensitiveParameter] array $privateRequest, #[SensitiveParameter] array $privateResponse,\n    #[SensitiveParameter] array $privateAuthConfiguration, #[SensitiveParameter] array $duringState, int $verifiedPayTime): array'),
    (b"    foreach ($sets as $key => $set) {\n        nhep_expect(nhepar_entry($beforeCapture, $key) === null, 'NHEPOPR_BOTH_ORIGINAL_PAYMENT_SET_KEYS_NEW_AND_ABSENT_BEFORE');", b"    nhep_expect(is_int($verifiedPayTime) && $verifiedPayTime >= $interval[0] && $verifiedPayTime <= $interval[1], 'NHEPOPR_ALREADY_DB_VALIDATED_PAYMENT_TIME');\n    $expectedSpecs = [];\n    foreach ($sets as $key => $set) $expectedSpecs[$set['ttl'] === 2 ? 'PAY_LOCK' : 'PRODUCT_COUPON'] = ['key' => $key] + $set;\n    nhep_expect($duringState['intent'] === $intent && $duringState['request'] === $privateRequest\n        && $duringState['specs'] === $expectedSpecs && $duringState['error'] === null, 'NHEPOPR_DURING_SAME_INDEPENDENT_PAYMENT_KEYS');\n    foreach ($sets as $key => $set) {\n        $keyFamily = $set['ttl'] === 2 ? 'PAY_LOCK' : 'PRODUCT_COUPON';\n        $witness = $duringState['witnesses'][$keyFamily] ?? null;\n        $witnessRow = nheppd_witness($witness, $beforeCapture, $actualCapture, $intent['run_id'], $key, $set['wire'], $set['ttl'], $interval);\n        nhep_expect(nhepar_entry($beforeCapture, $key) === null, 'NHEPOPR_BOTH_ORIGINAL_PAYMENT_SET_KEYS_NEW_AND_ABSENT_BEFORE');"),
    (b"            $changes[$key] = nhepar_bind($actualCapture, $key, 'string', $set['wire'], ['ttl' => $set['ttl']], $beforeCapture['snapshot']);\n        } else {\n            // Only this final absence is proved. No expired payload/DUMP, exact SET time or historic deadline is invented.\n            nhep_expect(bccomp($actualMs, $lastDeadline, 0) > 0,\n                'NHEPOPR_MISSING_SET_ONLY_AFTER_STRICT_LATEST_POSSIBLE_NATIVE_EXPIRY_NO_EQUALITY_OR_WAIT');\n            $changes[$key] = null;", b"            if ($witnessRow !== null) nhep_expect($present === $witnessRow, 'NHEPOPR_PRESENT_KEY_COMPLETE_FIRST_WITNESS_WIRE_DUMP_AND_DEADLINE_UNCHANGED');\n            $changes[$key] = nhepar_bind($actualCapture, $key, 'string', $set['wire'], ['ttl' => $set['ttl']], $beforeCapture['snapshot']);\n        } else {\n            // A real live witness proves its own observed expiry; otherwise retain a strict latest bound.\n            $expiryBound = $lastDeadline;\n            if ($keyFamily === 'PAY_LOCK') {\n                $payLastMs = bcsub(bcmul(bcadd((string)$verifiedPayTime, '1', 0), '1000', 0), '1', 0);\n                $payLastDeadline = bcadd($payLastMs, $ttlMs, 0);\n                if (bccomp($payLastDeadline, $expiryBound, 0) < 0) $expiryBound = $payLastDeadline;\n            }\n            if ($witnessRow !== null) $expiryBound = (string)$witnessRow['expires'];\n            if (bccomp($actualMs, $expiryBound, 0) <= 0) throw new NhepPayRedisEvidenceFailure(\n                'NHEPOPR_MISSING_SET_REQUIRES_STRICT_OBSERVED_OR_LATEST_EXPIRY',\n                ['key_family' => $keyFamily, 'ttl_seconds' => $set['ttl'], 'http_first_s' => $interval[0],\n                 'http_last_s' => $interval[1], 'before_redis_ms' => $beforeMs,\n                 'witness_redis_ms' => $witness === null ? null : $witness['snapshot']['time_ms'],\n                 'after_redis_ms' => $actualMs, 'expiry_bound_ms' => $expiryBound,\n                 'witness_seen' => $witness !== null, 'bound_kind' => $witness === null ? 'LATEST_BOUND' : 'LIVE_WITNESS']);\n            $changes[$key] = null;"),
    (b'        $headers = nhep_auth_headers_model($intent, $privateRequest, $privateResponse, $cookieIds);', b"        if (!isset($paySteps[$intent['step']])) nhep_expect($payDuringState === null && !$duringBridgeFailed, 'NHEPOPR_DURING_ONLY_TWO_PAYMENT_STEPS');\n        $headers = nhep_auth_headers_model($intent, $privateRequest, $privateResponse, $cookieIds);"),
)

_PAYMENT_DURING_HTTP_SEAMS = (
    (b'function nheh_observers(array $qualified, string $runId, Closure $before, Closure $after): void', b'function nheh_observers(array $qualified, string $runId, Closure $before, Closure $after, #[SensitiveParameter] Closure $during): void'),
    (b'    $a = new ReflectionFunction($before); $b = new ReflectionFunction($after);', b'    $a = new ReflectionFunction($before); $b = new ReflectionFunction($after); $c = new ReflectionFunction($during);'),
    (b"        && str_starts_with($path, '/run/nonempty/') && $b->getFileName() === $path,", b"        && str_starts_with($path, '/run/nonempty/') && $b->getFileName() === $path\n        && !$c->isInternal() && $c->getFileName() === $path,"),
    (b'    #[SensitiveParameter] array $actors, array $fixture, Closure $beforeStep, Closure $afterStep): array', b'    #[SensitiveParameter] array $actors, array $fixture, Closure $beforeStep, Closure $afterStep, #[SensitiveParameter] Closure $duringStep): array'),
    (b'    $port = nheh_origin($origin); nheh_observers($qualified, $runId, $beforeStep, $afterStep);', b'    $port = nheh_origin($origin); nheh_observers($qualified, $runId, $beforeStep, $afterStep, $duringStep);'),
    (b"'before' => $beforeStep, 'after' => $afterStep,", b"'before' => $beforeStep, 'after' => $afterStep, 'during' => $duringStep,"),
    (b'    $start = time(); $raw = false; $transportFailure = null;', b'    $start = time(); $raw = false; $transportFailure = null; $duringBridgeFailed = false;'),
    (b'        $raw = curl_exec($curl);', b"        if (in_array($step, ['refund_family_pay', 'review_family_pay'], true)) {\n            try {\n                $progress = static function (#[SensitiveParameter] CurlHandle $handle, float $downloadTotal,\n                    float $downloaded, float $uploadTotal, float $uploaded) use (&$state, $intent, $request, &$duringBridgeFailed): int {\n                    try { ($state['during'])($intent, $request); }\n                    catch (Throwable) { $duringBridgeFailed = true; }\n                    return 0;\n                };\n                if (!curl_setopt_array($curl, [CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => $progress]))\n                    $duringBridgeFailed = true;\n            } catch (Throwable) { $duringBridgeFailed = true; }\n        }\n        $raw = curl_exec($curl);"),
    (b"    $proof = ($state['after'])($intent, $before, $request, $response);", b"    $proof = ($state['after'])($intent, $before, $request, $response, $duringBridgeFailed);"),
    (b"preg_match('/\\A(?:NHE|NHEH)_[A-Z0-9_]+\\z/D', $code)", b"preg_match('/\\A(?:NHE|NHEH|NHEPOPR)_[A-Z0-9_]+\\z/D', $code)"),
    (b'                $json = json_encode($summary, JSON_UNESCAPED_SLASHES);', b"                if (get_class($failure) === 'NhepPayRedisEvidenceFailure' && function_exists('nheppd_safe_evidence')\n                    && (new ReflectionClass($failure))->getFileName() === '/run/nonempty/legacy-history-producer.php'\n                    && (new ReflectionFunction('nheppd_safe_evidence'))->getFileName() === '/run/nonempty/legacy-history-producer.php')\n                    $summary['payment_redis'] = nheppd_safe_evidence($failure->evidence());\n                $json = json_encode($summary, JSON_UNESCAPED_SLASHES);"),
)
