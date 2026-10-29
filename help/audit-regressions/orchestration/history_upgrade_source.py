"""Fixed initializer seams for the existing history source author; no I/O or CLI.

The new phase is deliberately blocked at CURRENT_STRUCTURE_UPGRADE_HANDOFF_UNBOUND.
Its producer/driver retirement qualification remains a separate composition seam.
The original initializer phases and child function are preserved byte for byte.
"""

import hashlib


INITIALIZER_SOURCE_BYTES = 63165
INITIALIZER_SOURCE_SHA256 = "95c0e205d3596e3701aeceb5dcce3cd046682a4843c924df2c12128bf17379a8"

# Filled from the fixed current m4m source, without changing any function bytes.
M4M_SOURCE_PIN = (24637, '7a9e26fdad809649634a40f549fd5429ce10452b74a242444c48e160831401f4')
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
        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);
        m4m_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'ACTUAL_SHOW_CREATE');
        $snapshot['tables'][$name] = ['metadata' => $metadata, 'ddl_sha256' => hash('sha256', $ddl[1]), 'projection' => m4m_projection($db, $name, $columns), 'projected_columns' => $columns];
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
UPGRADE_SOURCE_FILES = rb'''function m4iu_sourceFiles(): array
{
    return [
        ['path' => 'help/audit-regressions/isolated/ms4-migration.php', 'bytes' => 24637, 'sha256' => '7a9e26fdad809649634a40f549fd5429ce10452b74a242444c48e160831401f4'],
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
    // Composition must replace this rejection with the complete real public handoff join.
    m2f_assert(false, 'CURRENT_STRUCTURE_UPGRADE_HANDOFF_UNBOUND');
    $evidence['scope'] = 'CURRENT_READER_STRUCTURE_UPGRADE_ONLY'; $evidence['binding'] = m4iu_source();
    $evidence['current_structure_upgrade'] = ['schema' => 1, 'expected_before_table_count' => 166, 'expected_after_table_count' => 173,
        'source_files' => m4iu_sourceFiles(), 'snapshots' => [], 'original_four_cli_unchanged' => false];
    $driverPath = '/run/audit/evidence/ms4-migration-normal.json'; $driverRaw = file_get_contents($driverPath);
    $driver = json_decode($driverRaw, true, 256, JSON_THROW_ON_ERROR);
    m2f_assert($driver['status'] === 'VERIFIED_NORMAL_STRUCTURE_ONLY' && $driver['native_exit'] === 0
        && $driver['private_cleanup_verified'] === true && count($driver['children']) === 4, 'CURRENT_UPGRADE_ORIGINAL_FOUR_CLI_RESULT');
    $evidence['current_structure_upgrade']['original_four_cli'] = ['path' => $driverPath, 'bytes' => strlen($driverRaw), 'sha256' => hash('sha256', $driverRaw)];
    $before = m4iu_capture([]); $old = $before['snapshot']; $driverAfter = $driver['snapshots']['after']; $columns = [];
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
        m2f_assert(file_get_contents($driverPath) === $driverRaw && m2f_file($config) === $owned[$config]
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
}
'''


def _seams():
    return (
        (b"        'initializer' => ['initialize', 'audit-ms4-initialize']];",
         b"        'current-structure-upgrade' => ['current-structure-upgrade', 'audit-bootstrap'],\n"
         b"        'initializer' => ['initialize', 'audit-ms4-initialize']];"),
        (b"['prepare', 'register', 'authorize-cleanup'], true)), 'FINITE_PHASE_ONLY');",
         b"['prepare', 'register', 'authorize-cleanup', 'current-structure-upgrade'], true)), 'FINITE_PHASE_ONLY');"),
        (b"        else { m4i_initialize($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_MS4_PREREQUISITES_ONLY'; }",
         b"        elseif ($phase === 'current-structure-upgrade') { m4iu_upgrade($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_CURRENT_STRUCTURE_UPGRADE_ONLY'; }\n"
         b"        else { m4i_initialize($evidence, $primary, $cleanup); $evidence['status'] = 'VERIFIED_MS4_PREREQUISITES_ONLY'; }"),
        (b"function m4i_main(): int\n", M4M_OBSERVER_FUNCTIONS + UPGRADE_SOURCE_FILES + _UPGRADE_FUNCTIONS + b"\nfunction m4i_main(): int\n"),
    )


def _source(raw):
    if not isinstance(raw, bytes) or len(raw) != INITIALIZER_SOURCE_BYTES or hashlib.sha256(raw).hexdigest() != INITIALIZER_SOURCE_SHA256:
        raise ValueError("INITIALIZER_EXACT_ARCHIVED_SOURCE_REQUIRED")


def transform_initializer(raw: bytes) -> bytes:
    _source(raw)
    candidate = raw
    for before, after in _seams():
        if candidate.count(before) != 1 or after in candidate:
            raise ValueError("INITIALIZER_UNIQUE_FORWARD_SEAM_REQUIRED")
        candidate = candidate.replace(before, after, 1)
    if reverse_initializer(candidate) != raw:
        raise ValueError("INITIALIZER_COMPLETE_REVERSE_REQUIRED")
    return candidate


def reverse_initializer(candidate: bytes) -> bytes:
    if not isinstance(candidate, bytes):
        raise ValueError("INITIALIZER_CANDIDATE_BYTES_REQUIRED")
    raw = candidate
    for before, after in reversed(_seams()):
        if raw.count(after) != 1:
            raise ValueError("INITIALIZER_UNIQUE_REVERSE_SEAM_REQUIRED")
        raw = raw.replace(after, before, 1)
    _source(raw)
    return raw
