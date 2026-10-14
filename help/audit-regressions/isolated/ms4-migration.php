<?php
declare(strict_types=1);

// Reuse the existing isolation functions, without invoking another milestone's suite.
require __DIR__ . '/lib/harness.php';

const M4M_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
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

function m4m_expect(bool $condition, string $code): void { if (!$condition) throw new RuntimeException($code); }
function m4m_json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
function m4m_quote(string $name): string { m4m_expect(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) === 1, 'SQL_IDENTIFIER'); return '`' . $name . '`'; }
function m4m_rows(PDO $db, string $sql, array $args = []): array { $s = $db->prepare($sql); m4m_expect($s->execute($args), 'SQL_READ'); return $s->fetchAll(PDO::FETCH_ASSOC); }
function m4m_read(string $path): array
{
    m4m_expect(is_file($path) && !is_link($path) && filesize($path) <= 1048576, 'RECEIPT_FILE');
    $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    m4m_expect(is_array($value) && !array_is_list($value), 'RECEIPT_OBJECT');
    return $value;
}
function m4m_new(string $path, string $bytes): array
{
    m4m_expect(!file_exists($path) && !is_link($path), 'CREATE_ONLY_FILE');
    $stream = @fopen($path, 'xb'); m4m_expect(is_resource($stream), 'PRIVATE_CREATE');
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) { $n = fwrite($stream, substr($bytes, $offset)); m4m_expect(is_int($n) && $n > 0, 'FILE_WRITE'); $offset += $n; }
        m4m_expect(fflush($stream), 'FILE_FLUSH');
    } finally { fclose($stream); }
    m4m_expect(chmod($path, 0600), 'FILE_MODE');
    return m4m_file($path);
}
function m4m_file(string $path): array
{
    clearstatcache(true, $path); $s = lstat($path);
    m4m_expect(is_file($path) && !is_link($path) && is_array($s) && ($s['mode'] & 0777) === 0600 && $s['uid'] === posix_geteuid(), 'PRIVATE_FILE_IDENTITY');
    return ['dev' => $s['dev'], 'ino' => $s['ino'], 'bytes' => $s['size'], 'sha256' => hash_file('sha256', $path)];
}
function m4m_sourcePaths(): array
{
    $paths = ['help/audit-regressions/isolated/ms4-migration.php', 'help/audit-regressions/isolated/lib/harness.php',
        'help/audit-regressions/isolated/lib/guard.php', 'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
        'help/migrations/marketplace-ms4.php', 'help/migrations/marketplace-ms4.md', 'crmeb/public/install/crmeb.sql'];
    sort($paths, SORT_STRING); return $paths;
}
function m4m_mount(array $facts, string $destination): array
{
    $matches = array_values(array_filter($facts['mounts'] ?? [], static fn(array $m): bool => ($m['Destination'] ?? '') === $destination));
    m4m_expect(count($matches) === 1, 'EXACT_MOUNT'); return $matches[0];
}
function m4m_source(): array
{
    $manifest = audit_manifest(); $base = audit_run_dir() . '/evidence/';
    $facts = m4m_read($base . 'ms4-migration-container-created.json');
    $binding = m4m_read($base . 'ms4-migration-source-binding.json');
    $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA');
    m4m_expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
        && count($_SERVER['argv']) === 1 && audit_repo_dir() === '/workspace' && audit_run_dir() === '/run/audit', 'REAL_ENTRY');
    m4m_expect(getenv('AUDIT_ISOLATED') === '1' && getenv('AUDIT_GUARD_MODE') === 'read-only'
        && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', (string) $run) === 1 && preg_match('/\A[a-f0-9]{40}\z/D', (string) $sha) === 1
        && ($manifest['run_id'] ?? '') === $run && ($manifest['commit_sha'] ?? '') === $sha
        && ($manifest['project'] ?? '') === 'tianli-audit-' . $run && ($manifest['guard_mode'] ?? '') === 'read-only'
        && ($manifest['isolation'] ?? '') === 'ISO-FULL' && ($manifest['network_internal'] ?? null) === true, 'CURRENT_ISO_SOURCE');
    $names = array_keys($manifest['services'] ?? []); sort($names, SORT_STRING);
    m4m_expect($names === ['audit-ms4-migration', 'audit-mysql', 'audit-redis'], 'DEDICATED_MIGRATION_ROLES');
    $service = $manifest['services']['audit-ms4-migration'];
    m4m_expect(($facts['run_id'] ?? '') === $run && ($binding['run_id'] ?? '') === $run
        && ($facts['source_sha'] ?? '') === $sha && ($binding['source_sha'] ?? '') === $sha
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($service['id'] ?? '')) === 1
        && ($facts['id'] ?? '') === $service['id'] && gethostname() === substr($service['id'], 0, 12)
        && ($facts['hostname'] ?? '') === gethostname() && ($facts['image'] ?? '') === M4M_IMAGE
        && ($service['image_id'] ?? '') === M4M_IMAGE && ($facts['status'] ?? '') === 'created'
        && ($facts['read_only'] ?? null) === true && ($facts['privileged'] ?? null) === false
        && ($facts['network_mode'] ?? '') === ($manifest['network']['name'] ?? null)
        && ($facts['labels']['tianli.audit'] ?? '') === '1' && ($facts['labels']['tianli.audit.run'] ?? '') === $run
        && ($facts['labels']['com.docker.compose.project'] ?? '') === $manifest['project']
        && ($facts['labels']['com.docker.compose.service'] ?? '') === 'audit-ms4-migration'
        && ($facts['entrypoint'] ?? null) === ['php'] && ($facts['command'] ?? null) === ['help/audit-regressions/isolated/ms4-migration.php']
        && ($facts['workdir'] ?? '') === '/workspace' && ($facts['restart_count'] ?? null) === 0, 'NATIVE_CREATED_CONTAINER');
    foreach (['audit-mysql', 'audit-redis'] as $host) m4m_expect(filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && gethostbyname($host) === ($manifest['services'][$host]['address'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($manifest['services'][$host]['id'] ?? '')) === 1, 'REGISTERED_ENDPOINT');
    foreach (['/workspace', '/source'] as $destination) {
        $mount = m4m_mount($facts, $destination);
        m4m_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === false
            && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($binding['requested_repo_source'] ?? ''), 'CURRENT_SOURCE_RO');
    }
    $mount = m4m_mount($facts, '/run/audit');
    m4m_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === true
        && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($binding['requested_run_source'] ?? ''), 'CURRENT_RUN_BIND');
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'] as $name => $destination) {
        $mount = m4m_mount($facts, $destination); $volume = $manifest['project'] . '_' . $name;
        m4m_expect(($mount['Type'] ?? '') === 'volume' && ($mount['RW'] ?? null) === false && ($mount['Name'] ?? '') === $volume
            && in_array($volume, $manifest['volumes'] ?? [], true) && isset($manifest['volume_identities'][$volume]), 'OWN_PRIVATE_INPUT_RO');
    }
    foreach ($facts['mounts'] ?? [] as $mount) m4m_expect(!str_contains((string) ($mount['Source'] ?? ''), 'docker.sock')
        && !str_contains((string) ($mount['Destination'] ?? ''), 'docker.sock'), 'NO_DOCKER_SOCKET');
    m4m_expect(isset($facts['tmpfs']['/tmp']), 'PRIVATE_TMPFS_CONFIG');
    $tmp = false;
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = isset($parts[1]) ? explode(' ', $parts[1]) : [];
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmp = true;
    }
    m4m_expect($tmp && !file_exists('/workspace/crmeb/.env') && !file_exists('/workspace/crmeb/public/install.lock'), 'NO_HOST_CONFIG_PRIVATE_TMPFS');
    m4m_expect(($binding['schema'] ?? null) === 1 && ($binding['entry_sha256'] ?? '') === hash_file('sha256', __FILE__)
        && ($binding['cli_sha256'] ?? '') === hash_file('sha256', '/workspace/help/migrations/marketplace-ms4.php'), 'CURRENT_ENTRY_CLI_BINDING');
    $paths = [];
    foreach ($binding['source_files'] ?? [] as $item) {
        $relative = $item['path'] ?? '';
        m4m_expect(in_array($relative, m4m_sourcePaths(), true) && !isset($paths[$relative]) && is_int($item['bytes'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($item['sha256'] ?? '')) === 1, 'EXACT_SOURCE_ITEM');
        $paths[$relative] = true;
        foreach (['/workspace', '/source'] as $root) m4m_expect(is_file($root . '/' . $relative) && !is_link($root . '/' . $relative)
            && filesize($root . '/' . $relative) === $item['bytes'] && hash_file('sha256', $root . '/' . $relative) === $item['sha256'], 'ACTUAL_SOURCE_BYTES');
    }
    $paths = array_keys($paths); sort($paths, SORT_STRING); m4m_expect($paths === m4m_sourcePaths(), 'COMPLETE_DEPENDENCY_BINDING');
    return ['container_id' => $service['id'], 'entry_namespace_pid' => getmypid(), 'source_sha' => $sha,
        'entry_sha256' => $binding['entry_sha256'], 'cli_sha256' => $binding['cli_sha256'],
        'binding_sha256' => hash_file('sha256', $base . 'ms4-migration-source-binding.json'),
        'native_created_sha256' => hash_file('sha256', $base . 'ms4-migration-container-created.json')];
}

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

function m4m_oldAddedFields(): array
{
    $db = audit_db(); $counts = [];
    foreach (M4M_ADDITIONS as $table => $columns) foreach ($columns as $column) {
        $field = m4m_quote($column);
        $condition = $column === 'marketplace_trade_version' ? $field . ' IS NULL OR ' . $field . '<>0' : $field . ' IS NOT NULL';
        $count = (int) $db->query('SELECT COUNT(*) FROM ' . m4m_quote($table) . ' WHERE ' . $condition)->fetchColumn();
        m4m_expect($count === 0, 'NO_HISTORY_OWNER_VERSION_OR_SNAPSHOT_REWRITE');
        $counts[$table . '.' . $column] = $count;
    }
    return $counts;
}
function m4m_cli(string $mode, string $config, array &$evidence): array
{
    m4m_source(); audit_guard_assert();
    $process = proc_open([PHP_BINARY, '/workspace/help/migrations/marketplace-ms4.php', $mode, '--config-file=' . $config],
        [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace');
    m4m_expect(is_resource($process), 'NATIVE_CHILD_STARTED'); $first = proc_get_status($process);
    try { $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); }
    finally { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); $close = proc_close($process); }
    $native = $close;
    if ($close === -1 && $first['running'] === false && $first['signaled'] === false && is_int($first['exitcode']) && $first['exitcode'] >= 0) $native = $first['exitcode'];
    $result = is_string($stdout) && strlen($stdout) <= 1048576 ? json_decode($stdout, true) : null;
    $evidence['children'][] = ['mode' => $mode, 'pid' => $first['pid'], 'native_exit' => $native, 'proc_close_exit' => $close,
        'stdout_bytes' => strlen((string) $stdout), 'stdout_sha256' => hash('sha256', (string) $stdout),
        'stderr_bytes' => strlen((string) $stderr), 'stderr_sha256' => hash('sha256', (string) $stderr), 'result' => $result];
    m4m_expect(is_int($first['pid']) && $first['pid'] > 0 && $native === 0 && $stderr === '' && is_array($result)
        && ($result['mode'] ?? '') === $mode && ($result['native_exit'] ?? null) === 0
        && ($result['source_sha256'] ?? '') === hash_file('sha256', '/workspace/help/migrations/marketplace-ms4.php'), 'ACTUAL_NORMAL_CLI');
    return $result;
}

function m4m_main(): int
{
    umask(0077); $phase = 'source'; $private = null; $privateMeta = null; $failure = null;
    $evidence = ['schema' => 1, 'status' => 'FAIL', 'scope' => 'NORMAL_STRUCTURE_ONLY', 'children' => [], 'snapshots' => [],
        'stopped_cases' => ['denial_conflict_concurrency_fault_restore_paid' => 'NOT RUN'], 'business_acceptance' => 'NOT RUN'];
    try {
        $evidence['binding'] = m4m_source(); audit_guard_assert();
        $phase = 'private_migrator'; $migrator = m4m_read('/run/audit-bootstrap/migrator.json');
        m4m_expect(($migrator['username'] ?? '') === 'audit_migrator' && is_string($migrator['password'] ?? null), 'DEDICATED_MIGRATOR');
        $db = audit_pdo($migrator); $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        m4m_expect(count($grants) === 2 && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT USAGE ON *.* TO '))) === 1
            && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT ALL PRIVILEGES ON `audit_auth`.* TO ') && !str_contains($g, 'GRANT OPTION'))) === 1
            && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')], 'MIGRATOR_SAME_SCHEMA_SENTINEL');
        $private = '/tmp/ms4-migration-config-' . getenv('AUDIT_RUN_ID') . '.json';
        $privateMeta = m4m_new($private, m4m_json(['hostname' => 'audit-mysql', 'hostport' => '3306', 'database' => 'audit_auth', 'username' => $migrator['username'], 'password' => $migrator['password'], 'prefix' => 'eb_']));
        unset($migrator);
        $phase = 'baseline'; $before = m4m_snapshot();
        $newNames = ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'];
        foreach ($newNames as $name) m4m_expect(!in_array($name, $before['names'], true), 'FRESH_MS4_TARGET_ABSENT');
        foreach (M4M_ADDITIONS as $name => $columns) foreach ($columns as $column) m4m_expect(isset($before['tables'][$name]) && !isset($before['tables'][$name]['metadata']['columns'][$column]), 'FRESH_MS4_ADDITIONS_ABSENT');
        $original = []; foreach ($before['tables'] as $name => $snapshot) $original[$name] = $snapshot['projected_columns'];
        $evidence['snapshots']['before'] = $before;
        $phase = 'check_first'; $check = m4m_cli('check', $private, $evidence);
        m4m_expect($check['state'] === 'applicable' && $check['changed'] === false && count($check['pending_steps']) === 13, 'FIRST_CHECK_APPLICABLE');
        $checked = m4m_snapshot($original); m4m_expect($checked['names'] === $before['names'], 'CHECK_NO_TABLES'); m4m_preserved($before, $checked, false);
        $phase = 'apply_first'; $apply = m4m_cli('apply-structure', $private, $evidence);
        m4m_expect($apply['state'] === 'completed' && $apply['changed'] === true && count($apply['applied_steps']) === 13 && $apply['pending_steps'] === [], 'FIRST_APPLY_COMPLETE');
        $after = m4m_snapshot($original); $added = array_values(array_diff($after['names'], $before['names'])); sort($added, SORT_STRING);
        m4m_expect($added === $newNames && count($after['names']) === count($before['names']) + 2, 'ONLY_TWO_NEW_TABLES');
        m4m_preserved($before, $after, true);
        $evidence['legacy_added_field_anomalies'] = m4m_oldAddedFields();
        foreach ($newNames as $name) m4m_expect($after['tables'][$name]['projection']['rows'] === 0 && $after['tables'][$name]['metadata']['table']['TABLE_COMMENT'] === 'marketplace-ms4-structure-v1'
            && $after['tables'][$name]['metadata']['table']['ENGINE'] === 'InnoDB', 'EMPTY_OWN_NEW_TABLES');
        $evidence['snapshots']['after'] = $after;
        foreach (['check', 'apply-structure'] as $mode) {
            $phase = $mode . '_repeat'; $result = m4m_cli($mode, $private, $evidence);
            m4m_expect($result['state'] === 'completed' && $result['changed'] === false && $result['applied_steps'] === [] && $result['pending_steps'] === [], 'REPEAT_READ_ONLY_COMPLETE');
            $again = m4m_snapshot($original); m4m_expect($again === $after, 'FULL_REPEAT_UNCHANGED');
            m4m_expect(m4m_oldAddedFields() === $evidence['legacy_added_field_anomalies'], 'REPEAT_NO_HISTORY_CHANGE');
        }
        m4m_expect(m4m_source() === $evidence['binding'] && m4m_file($private) === $privateMeta, 'FINAL_SOURCE_PRIVATE_IDENTITY'); audit_guard_assert();
        $evidence['actual_command_counts'] = ['check' => 2, 'apply-structure' => 2];
        $evidence['original_table_count'] = count($before['names']);
        $evidence['target_original_rows'] = [];
        foreach (array_keys(M4M_ADDITIONS) as $name) $evidence['target_original_rows'][$name] = $before['tables'][$name]['projection']['rows'];
        $evidence['status'] = 'VERIFIED_NORMAL_STRUCTURE_ONLY';
    } catch (Throwable $error) {
        $failure = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'phase' => $phase];
        $evidence['diagnostic'] = $failure;
    } finally {
        if ($privateMeta !== null) {
            try { m4m_expect(m4m_file($private) === $privateMeta && unlink($private) && !file_exists($private), 'OWN_PRIVATE_CLEANUP'); $evidence['private_cleanup_verified'] = true; }
            catch (Throwable $error) { $evidence['status'] = 'FAIL'; $evidence['cleanup_failure'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]; }
        }
    }
    $evidence['phase'] = $phase; $evidence['native_exit'] = $evidence['status'] === 'VERIFIED_NORMAL_STRUCTURE_ONLY' ? 0 : 1;
    try { m4m_new(audit_run_dir() . '/evidence/ms4-migration-normal.json', m4m_json($evidence)); }
    catch (Throwable $error) { echo m4m_json(['status' => 'FAIL', 'native_exit' => 1, 'phase' => 'evidence_write', 'business_acceptance' => 'NOT RUN']); return 1; }
    echo m4m_json(['status' => $evidence['status'], 'native_exit' => $evidence['native_exit'], 'phase' => $phase,
        'actual_command_counts' => $evidence['actual_command_counts'] ?? null, 'private_cleanup_verified' => $evidence['private_cleanup_verified'] ?? false,
        'evidence_ref' => 'evidence/ms4-migration-normal.json', 'business_acceptance' => 'NOT RUN']);
    return $evidence['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(m4m_main());
