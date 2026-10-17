<?php
declare(strict_types=1);

// Only the isolation helpers are loaded. The migration itself runs in four native children.
require __DIR__ . '/lib/harness.php';

const M5PM_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M5PM_SCOPE = 'MS5_PAYMENT_NORMAL_STRUCTURE_ONLY';
const M5PM_VERIFIED = 'VERIFIED_MS5_PAYMENT_NORMAL_STRUCTURE_ONLY';
const M5PM_MARKER = 'marketplace-ms5-payment-structure-v1';
const M5PM_ENTRY = 'help/audit-regressions/isolated/ms5-payment-migration.php';
const M5PM_CLI = 'help/migrations/marketplace-ms5-payment.php';
const M5PM_RESULT = 'evidence/ms5-payment-migration-normal.json';

function m5pm_expect(bool $condition, string $code): void { if (!$condition) throw new RuntimeException($code); }
function m5pm_json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
function m5pm_quote(string $name): string
{
    m5pm_expect(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) === 1, 'SQL_IDENTIFIER');
    return '`' . $name . '`';
}
function m5pm_keys(array $value, array $expected): bool
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
    return $actual === $expected;
}
function m5pm_rows(PDO $db, string $sql, array $args = []): array
{
    $statement = $db->prepare($sql);
    m5pm_expect($statement instanceof PDOStatement && $statement->execute($args), 'SQL_READ');
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function m5pm_read(string $path, ?string &$digest = null): array
{
    clearstatcache(true, $path); $before = @lstat($path);
    m5pm_expect(is_array($before) && is_file($path) && !is_link($path) && $before['size'] <= 1048576, 'PROOF_FILE');
    $handle = @fopen($path, 'rb'); m5pm_expect(is_resource($handle), 'PROOF_OPEN');
    try {
        $opened = @fstat($handle);
        m5pm_expect(is_array($opened), 'PROOF_STAT');
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size'] as $key) m5pm_expect($before[$key] === $opened[$key], 'PROOF_HANDLE_IDENTITY');
        $raw = stream_get_contents($handle, 1048577); $after = @fstat($handle);
        m5pm_expect(is_string($raw) && strlen($raw) <= 1048576 && strlen($raw) === $opened['size'] && is_array($after), 'PROOF_STABLE_READ');
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) m5pm_expect($after[$key] === $opened[$key], 'PROOF_STABLE_READ');
    } finally { fclose($handle); }
    clearstatcache(true, $path); $named = @lstat($path);
    m5pm_expect(is_array($named) && !is_link($path), 'PROOF_NAMED_IDENTITY');
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size'] as $key) m5pm_expect($named[$key] === $opened[$key], 'PROOF_NAMED_IDENTITY');
    $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    m5pm_expect(is_array($value) && !array_is_list($value), 'PROOF_OBJECT');
    $digest = hash('sha256', $raw); return $value;
}
function m5pm_source_paths(): array
{
    $paths = [M5PM_ENTRY, 'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/lib/guard.php',
        'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php', 'help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms4.md', M5PM_CLI, 'help/migrations/marketplace-ms5-payment.md', 'crmeb/public/install/crmeb.sql'];
    sort($paths, SORT_STRING); return $paths;
}
function m5pm_mount(array $facts, string $destination): array
{
    $matches = array_values(array_filter($facts['mounts'] ?? [], static fn(array $mount): bool => ($mount['Destination'] ?? '') === $destination));
    m5pm_expect(count($matches) === 1, 'EXACT_MOUNT'); return $matches[0];
}
function m5pm_source(): array
{
    m5pm_expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
        && count($_SERVER['argv']) === 1 && audit_repo_dir() === '/workspace' && audit_run_dir() === '/run/audit'
        && getenv('AUDIT_REPO_DIR') === '/workspace' && getenv('AUDIT_RUN_DIR') === '/run/audit', 'REAL_ENTRY');
    $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA'); $manifest = audit_manifest();
    m5pm_expect(getenv('AUDIT_ISOLATED') === '1' && getenv('AUDIT_GUARD_MODE') === 'read-only'
        && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', (string) $run) === 1 && preg_match('/\A[a-f0-9]{40}\z/D', (string) $sha) === 1
        && getenv('AUDIT_PROJECT') === 'tianli-audit-' . $run && ($manifest['run_id'] ?? '') === $run
        && ($manifest['commit_sha'] ?? '') === $sha && ($manifest['project'] ?? '') === getenv('AUDIT_PROJECT')
        && ($manifest['guard_mode'] ?? '') === 'read-only' && ($manifest['isolation'] ?? '') === 'ISO-FULL'
        && ($manifest['network_internal'] ?? null) === true, 'CURRENT_ISO_SOURCE');
    $names = array_keys($manifest['services'] ?? []); sort($names, SORT_STRING);
    m5pm_expect($names === ['audit-ms5-payment-migration', 'audit-mysql', 'audit-redis'], 'DEDICATED_ROLES');
    $network = $manifest['network'] ?? [];
    m5pm_expect(preg_match('/\A[a-f0-9]{64}\z/D', (string) ($network['id'] ?? '')) === 1
        && is_string($network['name'] ?? null) && $network['name'] !== '', 'REGISTERED_NETWORK');
    $base = '/run/audit/evidence/'; $createdHash = null; $bindingHash = null;
    $facts = m5pm_read($base . 'ms5-payment-migration-container-created.json', $createdHash);
    $binding = m5pm_read($base . 'ms5-payment-migration-source-binding.json', $bindingHash);
    m5pm_expect(m5pm_keys($binding, ['schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source', 'entry_sha256', 'cli_sha256', 'source_files'])
        && $binding['schema'] === 1 && $binding['run_id'] === $run && $binding['source_sha'] === $sha
        && is_string($binding['requested_repo_source']) && $binding['requested_repo_source'] !== ''
        && is_string($binding['requested_run_source']) && $binding['requested_run_source'] !== ''
        && ($facts['run_id'] ?? '') === $run && ($facts['source_sha'] ?? '') === $sha, 'EXACT_BINDING');
    $service = $manifest['services']['audit-ms5-payment-migration'];
    m5pm_expect(preg_match('/\A[a-f0-9]{64}\z/D', (string) ($service['id'] ?? '')) === 1
        && ($facts['id'] ?? '') === $service['id'] && gethostname() === substr($service['id'], 0, 12)
        && ($facts['hostname'] ?? '') === gethostname() && ($facts['image'] ?? '') === M5PM_IMAGE && ($service['image_id'] ?? '') === M5PM_IMAGE
        && ($facts['status'] ?? '') === 'created' && ($facts['read_only'] ?? null) === true && ($facts['privileged'] ?? null) === false
        && ($facts['network_mode'] ?? '') === $network['name'] && is_array($facts['networks'] ?? null)
        && array_keys($facts['networks']) === [$network['name']] && is_array($facts['ports'] ?? null)
        && ($facts['labels']['tianli.audit'] ?? '') === '1' && ($facts['labels']['tianli.audit.run'] ?? '') === $run
        && ($facts['labels']['com.docker.compose.project'] ?? '') === $manifest['project']
        && ($facts['labels']['com.docker.compose.service'] ?? '') === 'audit-ms5-payment-migration'
        && ($facts['entrypoint'] ?? null) === ['php'] && ($facts['command'] ?? null) === [M5PM_ENTRY]
        && ($facts['workdir'] ?? '') === '/workspace' && ($facts['restart_count'] ?? null) === 0, 'NATIVE_CREATED_CONTAINER');
    foreach ($facts['ports'] as $published) m5pm_expect($published === null, 'NO_PUBLISHED_PORT');
    foreach (['audit-mysql', 'audit-redis'] as $host) {
        $address = gethostbyname($host); $endpoint = $manifest['services'][$host];
        m5pm_expect(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $address === ($endpoint['address'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($endpoint['id'] ?? '')) === 1
            && preg_match('/\Asha256:[a-f0-9]{64}\z/D', (string) ($endpoint['image_id'] ?? '')) === 1, 'REGISTERED_ENDPOINT');
    }
    $volumes = [];
    foreach (['mysql-data', 'redis-data', 'bootstrap-secrets', 'redis-secrets', 'app-secrets', 'fixture-secrets'] as $name) $volumes[] = $manifest['project'] . '_' . $name;
    sort($volumes, SORT_STRING); $actual = $manifest['volumes'] ?? []; sort($actual, SORT_STRING);
    m5pm_expect($actual === $volumes && is_array($manifest['volume_identities'] ?? null)
        && m5pm_keys($manifest['volume_identities'], $volumes), 'EXACT_SIX_OWN_VOLUMES');
    foreach ($volumes as $volume) m5pm_expect(is_string($manifest['volume_identities'][$volume]) && $manifest['volume_identities'][$volume] !== '', 'OWN_VOLUME_IDENTITY');
    $seen = [];
    foreach ($facts['mounts'] ?? [] as $mount) {
        $destination = $mount['Destination'] ?? '';
        m5pm_expect(in_array($destination, ['/workspace', '/source', '/run/audit', '/run/audit-bootstrap', '/run/audit-app', '/tmp'], true)
            && !isset($seen[$destination]) && !str_contains((string) ($mount['Source'] ?? ''), 'docker.sock'), 'ONLY_ENTRY_MOUNTS');
        $seen[$destination] = true;
        if ($destination === '/tmp') m5pm_expect(($mount['Type'] ?? '') === 'tmpfs', 'ONLY_PRIVATE_TMPFS');
    }
    foreach (['/workspace', '/source'] as $destination) {
        $mount = m5pm_mount($facts, $destination);
        m5pm_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === false
            && audit_canonical_host_bind_source((string) ($mount['Source'] ?? '')) === audit_canonical_host_bind_source($binding['requested_repo_source']), 'CURRENT_SOURCE_RO');
    }
    $mount = m5pm_mount($facts, '/run/audit');
    m5pm_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === true
        && audit_canonical_host_bind_source((string) ($mount['Source'] ?? '')) === audit_canonical_host_bind_source($binding['requested_run_source']), 'CURRENT_RUN_BIND');
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'] as $name => $destination) {
        $mount = m5pm_mount($facts, $destination);
        m5pm_expect(($mount['Type'] ?? '') === 'volume' && ($mount['RW'] ?? null) === false
            && ($mount['Name'] ?? '') === $manifest['project'] . '_' . $name, 'OWN_PRIVATE_INPUT_RO');
    }
    m5pm_expect(is_array($facts['tmpfs'] ?? null) && array_keys($facts['tmpfs']) === ['/tmp'], 'PRIVATE_TMPFS_DECLARED');
    $tmp = false;
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = isset($parts[1]) ? explode(' ', $parts[1]) : [];
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmp = true;
    }
    m5pm_expect($tmp && !file_exists('/workspace/crmeb/.env') && !is_link('/workspace/crmeb/.env')
        && !file_exists('/workspace/crmeb/public/install.lock') && !is_link('/workspace/crmeb/public/install.lock'), 'NO_HOST_CONFIG_PRIVATE_TMPFS');
    m5pm_expect($binding['entry_sha256'] === hash_file('sha256', __FILE__)
        && $binding['cli_sha256'] === hash_file('sha256', '/workspace/' . M5PM_CLI)
        && is_array($binding['source_files']) && array_is_list($binding['source_files']) && count($binding['source_files']) === 10, 'CURRENT_ENTRY_CLI_BINDING');
    $paths = [];
    foreach ($binding['source_files'] as $item) {
        m5pm_expect(is_array($item) && m5pm_keys($item, ['path', 'bytes', 'sha256']), 'EXACT_SOURCE_ITEM');
        $relative = $item['path'];
        m5pm_expect(is_string($relative) && in_array($relative, m5pm_source_paths(), true) && !isset($paths[$relative])
            && is_int($item['bytes']) && $item['bytes'] >= 0 && is_string($item['sha256'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $item['sha256']) === 1, 'EXACT_SOURCE_ITEM');
        $paths[$relative] = true;
        foreach (['/workspace', '/source'] as $root) m5pm_expect(is_file($root . '/' . $relative) && !is_link($root . '/' . $relative)
            && filesize($root . '/' . $relative) === $item['bytes'] && hash_file('sha256', $root . '/' . $relative) === $item['sha256'], 'ACTUAL_SOURCE_BYTES');
    }
    $paths = array_keys($paths); sort($paths, SORT_STRING); m5pm_expect($paths === m5pm_source_paths(), 'COMPLETE_DEPENDENCY_BINDING');
    return ['run_id' => $run, 'container_id' => $service['id'], 'entry_namespace_pid' => getmypid(), 'source_sha' => $sha,
        'entry_sha256' => $binding['entry_sha256'], 'cli_sha256' => $binding['cli_sha256'], 'source_files' => 10,
        'binding_sha256' => $bindingHash, 'native_created_sha256' => $createdHash];
}

/** The open handle and ownership are recorded before any content write can fail. */
function m5pm_private_create(array &$private, string $bytes): void
{
    $path = '/tmp/ms5-payment-migration-config-' . getenv('AUDIT_RUN_ID') . '.json';
    clearstatcache(true, $path); m5pm_expect(!file_exists($path) && !is_link($path), 'PRIVATE_CREATE_ONLY');
    $handle = @fopen($path, 'x+b'); m5pm_expect(is_resource($handle), 'PRIVATE_CREATE');
    $private['created'] = true; $private['path'] = $path; $private['handle'] = $handle;
    $stat = @fstat($handle); m5pm_expect(is_array($stat), 'PRIVATE_OPEN_STAT');
    $private['identity'] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'gid' => $stat['gid']];
    m5pm_private_assert($private);
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = @fwrite($handle, substr($bytes, $offset));
        m5pm_expect(is_int($written) && $written > 0, 'PRIVATE_WRITE'); $offset += $written;
    }
    m5pm_expect(@fflush($handle), 'PRIVATE_FLUSH');
    $private['complete'] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    m5pm_private_assert($private);
}
function m5pm_private_assert(array $private): void
{
    m5pm_expect(is_resource($private['handle']) && is_array($private['identity']), 'PRIVATE_HANDLE_OWNERSHIP');
    clearstatcache(true, $private['path']); $opened = @fstat($private['handle']); $named = @lstat($private['path']);
    m5pm_expect(is_array($opened) && is_array($named) && !is_link($private['path']), 'PRIVATE_NAMED_OWNERSHIP');
    foreach ([$opened, $named] as $stat) {
        foreach ($private['identity'] as $key => $value) m5pm_expect($stat[$key] === $value, 'PRIVATE_SAME_IDENTITY');
        m5pm_expect(($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 0777) === 0600
            && $stat['uid'] === posix_geteuid() && $stat['nlink'] === 1, 'PRIVATE_FILE_MODE_OWNER');
    }
    if ($private['complete'] !== null) {
        m5pm_expect($opened['size'] === $private['complete']['bytes'] && $named['size'] === $private['complete']['bytes']
            && @rewind($private['handle']), 'PRIVATE_COMPLETE_BYTES');
        $hash = hash_init('sha256'); $read = @hash_update_stream($hash, $private['handle']);
        m5pm_expect($read === $private['complete']['bytes'] && hash_final($hash) === $private['complete']['sha256'], 'PRIVATE_COMPLETE_HASH');
        clearstatcache(true, $private['path']); $after = @lstat($private['path']); $handleAfter = @fstat($private['handle']);
        m5pm_expect(is_array($after) && is_array($handleAfter) && !is_link($private['path']), 'PRIVATE_STABLE_IDENTITY');
        foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'size', 'nlink'] as $key) m5pm_expect($after[$key] === $named[$key] && $handleAfter[$key] === $opened[$key], 'PRIVATE_STABLE_IDENTITY');
    }
}
function m5pm_private_cleanup(array &$private, array &$evidence): void
{
    if ($private['cleanup_attempted']) return;
    $private['cleanup_attempted'] = true;
    if (!$private['created']) { $evidence['private_cleanup_state'] = 'NOT_CREATED'; return; }
    try {
        m5pm_private_assert($private);
        m5pm_expect(@unlink($private['path']), 'PRIVATE_OWN_UNLINK');
        clearstatcache(true, $private['path']); m5pm_expect(!file_exists($private['path']) && !is_link($private['path']), 'PRIVATE_ABSENT');
        m5pm_expect(@fclose($private['handle']), 'PRIVATE_HANDLE_CLOSE'); $private['handle'] = null;
        $evidence['private_cleanup_verified'] = true; $evidence['private_cleanup_state'] = 'OWN_FILE_REMOVED';
    } catch (Throwable $error) {
        $evidence['status'] = 'FAIL'; $evidence['private_cleanup_verified'] = false; $evidence['private_cleanup_state'] = 'FAILED';
        $evidence['cleanup_failure'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
        if (is_resource($private['handle'])) { @fclose($private['handle']); $private['handle'] = null; }
    }
}

function m5pm_metadata(PDO $db, string $table): array
{
    $args = [$table];
    $tables = m5pm_rows($db, 'SELECT TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $args);
    m5pm_expect(count($tables) === 1 && $tables[0]['TABLE_TYPE'] === 'BASE TABLE', 'COMPLETE_BASE_TABLE_METADATA');
    $columns = [];
    foreach (m5pm_rows($db, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $row) $columns[$row['COLUMN_NAME']] = $row;
    $indexes = [];
    foreach (m5pm_rows($db, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $row) $indexes[$row['INDEX_NAME']][] = $row;
    ksort($indexes, SORT_STRING);
    return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
        'constraints' => m5pm_rows($db, 'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME', $args),
        'checks' => m5pm_rows($db, "SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", $args),
        'foreign' => m5pm_rows($db, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', $args),
        'triggers' => m5pm_rows($db, 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
}
function m5pm_projection(PDO $db, string $table, array $columns): array
{
    m5pm_expect($columns !== [], 'COMPLETE_COLUMN_PROJECTION');
    $statement = $db->query('SELECT ' . implode(',', array_map('m5pm_quote', $columns)) . ' FROM ' . m5pm_quote($table));
    m5pm_expect($statement instanceof PDOStatement, 'ROW_PROJECTION'); $hashes = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) $hashes[] = hash('sha256', serialize($row));
    sort($hashes, SORT_STRING);
    return ['encoding' => 'php-serialize-row-sha256-sorted-multiset-v1', 'rows' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))];
}
function m5pm_snapshot(): array
{
    $db = audit_db();
    m5pm_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false), 'SNAPSHOT_NATIVE_PREPARES');
    $names = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    sort($names, SORT_STRING);
    $snapshot = ['names' => $names, 'tables' => [], 'redis' => [],
        'modes' => m5pm_rows($db, 'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb')];
    foreach ($names as $name) {
        m5pm_quote($name); $metadata = m5pm_metadata($db, $name); $columns = array_keys($metadata['columns']);
        $ddl = $db->query('SHOW CREATE TABLE ' . m5pm_quote($name))->fetch(PDO::FETCH_NUM);
        m5pm_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'ACTUAL_SHOW_CREATE');
        $snapshot['tables'][$name] = ['metadata' => $metadata, 'ddl_sha256' => hash('sha256', $ddl[1]),
            'projected_columns' => $columns, 'projection' => m5pm_projection($db, $name, $columns)];
    }
    $raw = audit_snapshot(['tables' => [], 'redis' => ['*']]); $redis = audit_redis();
    foreach ($raw['redis']['*'] as $key => $hash) {
        $expiry = $redis->rawCommand('PEXPIRETIME', $key); $keyHash = hash('sha256', (string) $key);
        m5pm_expect(is_int($expiry) && ($expiry === -1 || $expiry > 0) && !isset($snapshot['redis'][$keyHash]), 'REDIS_ABSOLUTE_EXPIRY');
        $snapshot['redis'][$keyHash] = ['sha256' => $hash, 'expires' => $expiry];
    }
    ksort($snapshot['redis'], SORT_STRING); return $snapshot;
}
/** Preserve all native metadata bytes and PHP types without placing binary values directly in JSON. */
function m5pm_snapshot_evidence(array $snapshot): array
{
    $digest = hash('sha256', serialize($snapshot));
    foreach ($snapshot['tables'] as &$table) {
        $bytes = serialize($table['metadata']);
        $table['metadata_sha256'] = hash('sha256', $bytes); $table['metadata_serialized_base64'] = base64_encode($bytes);
        unset($table['metadata']);
    }
    unset($table); $snapshot['native_snapshot_sha256'] = $digest; return $snapshot;
}
function m5pm_preserved(array $before, array $after): void
{
    m5pm_expect($before['modes'] === $after['modes'] && $before['redis'] === $after['redis'], 'SQL_MODES_REDIS_UNCHANGED');
    foreach ($before['tables'] as $name => $old) m5pm_expect(isset($after['tables'][$name]) && $old === $after['tables'][$name], 'ALL_ORIGINAL_METADATA_AND_ROWS_UNCHANGED');
}

/** This independent expected map is not obtained from the CLI's own check or class. */
function m5pm_expected_tables(): array
{
    $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array =>
        ['type' => $type, 'comment' => $comment, 'nullable' => $null, 'default' => $default, 'charset' => $charset, 'auto' => $auto];
    return [
        'eb_marketplace_payment_registry' => ['columns' => [
            'order_id' => $c('int unsigned', '真实商业根订单主键'),
            'uid' => $c('int unsigned', '原订单顾客编号'),
            'mer_id' => $c('int unsigned', '原订单商家编号'),
            'payment_contract_version' => $c('tinyint unsigned', '独立支付出生合同版本'),
            'born_at' => $c('int unsigned', '商业根同事务支付登记秒'),
            'attempt_generation' => $c('bigint unsigned', '完整付款尝试集合代次', false, '0'),
            'winner_attempt_id' => $c('bigint unsigned', '唯一成功应用尝试主键', true),
            'fulfillment_hold' => $c('tinyint unsigned', '持久平台履约限制零或一', false, '0'),
            'hold_reason' => $c('varchar(32)', '首次固定履约限制原因', true, null, 'ascii'),
            'hold_attempt_id' => $c('bigint unsigned', '首次履约限制真实尝试引用', true),
            'hold_at' => $c('int unsigned', '首次履约限制本地登记秒', true),
        ], 'indexes' => [
            'PRIMARY' => [true, ['order_id']], 'uq_ms5_payment_winner' => [true, ['winner_attempt_id']],
        ]],
        'eb_marketplace_payment_attempt' => ['columns' => [
            'id' => $c('bigint unsigned', '原生付款尝试主键', false, null, null, true),
            'order_id' => $c('int unsigned', '原商业根订单主键'),
            'uid' => $c('int unsigned', '原订单顾客编号'),
            'mer_id' => $c('int unsigned', '原订单商家编号'),
            'payment_contract_version' => $c('tinyint unsigned', '独立支付出生合同版本'),
            'admitted_generation' => $c('bigint unsigned', '本次新尝试准入代次'),
            'order_no' => $c('varbinary(32)', '原订单编号完整字节'),
            'idem_key' => $c('varchar(128)', '原付款请求幂等键', false, null, 'ascii'),
            'amount' => $c('decimal(12,2) unsigned', '原精确付款金额元'),
            'currency' => $c('char(3)', '原付款币种快照', false, null, 'ascii'),
            'account_snapshot' => $c('json', '原只读测试账户完整快照'),
            'request_digest' => $c('binary(32)', '原规范付款请求SHA256原始摘要'),
            'binding_digest' => $c('binary(32)', '原驱动绑定SHA256原始摘要'),
            'account_domain_digest' => $c('binary(32)', '稳定测试账户域SHA256原始摘要'),
            'created_at' => $c('int unsigned', '原付款意图本地登记秒'),
            'attempt_ref' => $c('varchar(45)', '实际驱动返回的原尝试引用', true, null, 'ascii'),
            'applied_trade_no' => $c('varchar(45)', '仅已应用成功的原测试交易引用', true, null, 'ascii'),
            'evidence' => $c('json', '完整来源化观察首事实应用及异常'),
        ], 'indexes' => [
            'PRIMARY' => [true, ['id']],
            'uq_ms5_payment_order_idem' => [true, ['order_id', 'idem_key']],
            'uq_ms5_payment_order_generation' => [true, ['order_id', 'admitted_generation']],
            'idx_ms5_payment_order_id' => [false, ['order_id', 'id']],
            'uq_ms5_payment_account_attempt' => [true, ['account_domain_digest', 'attempt_ref']],
            'uq_ms5_payment_account_trade' => [true, ['account_domain_digest', 'applied_trade_no']],
        ]],
    ];
}
function m5pm_new_tables(array $snapshot): void
{
    foreach (m5pm_expected_tables() as $name => $expected) {
        m5pm_expect(isset($snapshot['tables'][$name]), 'NEW_TABLE_PRESENT'); $table = $snapshot['tables'][$name]; $meta = $table['metadata'];
        m5pm_expect($table['projection']['rows'] === 0 && $meta['table']['ENGINE'] === 'InnoDB'
            && $meta['table']['TABLE_COLLATION'] === 'utf8mb4_bin' && $meta['table']['TABLE_COMMENT'] === M5PM_MARKER
            && array_keys($meta['columns']) === array_keys($expected['columns']) && $meta['checks'] === [] && $meta['foreign'] === [] && $meta['triggers'] === [], 'EXACT_EMPTY_NEW_TABLE');
        foreach ($expected['columns'] as $column => $want) {
            $actual = $meta['columns'][$column];
            $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($actual['COLUMN_TYPE']));
            $default = $actual['COLUMN_DEFAULT'] === null ? null : (string) $actual['COLUMN_DEFAULT'];
            m5pm_expect($type === $want['type'] && $actual['IS_NULLABLE'] === ($want['nullable'] ? 'YES' : 'NO') && $default === $want['default']
                && $actual['CHARACTER_SET_NAME'] === $want['charset'] && $actual['COLLATION_NAME'] === ($want['charset'] === 'ascii' ? 'ascii_bin' : null)
                && $actual['EXTRA'] === ($want['auto'] ? 'auto_increment' : '') && (string) $actual['GENERATION_EXPRESSION'] === ''
                && $actual['COLUMN_COMMENT'] === $want['comment'], 'EXACT_NEW_COLUMN');
        }
        $indexes = $expected['indexes']; ksort($indexes, SORT_STRING);
        m5pm_expect(array_keys($meta['indexes']) === array_keys($indexes), 'EXACT_NEW_INDEX_NAMES'); $constraints = [];
        foreach ($indexes as $index => [$unique, $columns]) {
            $actual = $meta['indexes'][$index]; m5pm_expect(count($actual) === count($columns), 'EXACT_NEW_INDEX_WIDTH');
            foreach ($actual as $i => $row) m5pm_expect((string) $row['NON_UNIQUE'] === ($unique ? '0' : '1') && (int) $row['SEQ_IN_INDEX'] === $i + 1
                && $row['COLUMN_NAME'] === $columns[$i] && $row['SUB_PART'] === null && $row['INDEX_TYPE'] === 'BTREE'
                && $row['IS_VISIBLE'] === 'YES' && $row['EXPRESSION'] === null && $row['COLLATION'] === 'A' && $row['INDEX_COMMENT'] === '', 'EXACT_NEW_INDEX');
            if ($unique) $constraints[$index] = ['CONSTRAINT_NAME' => $index, 'CONSTRAINT_TYPE' => $index === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE', 'ENFORCED' => 'YES'];
        }
        ksort($constraints, SORT_STRING); m5pm_expect($meta['constraints'] === array_values($constraints), 'EXACT_NEW_CONSTRAINTS');
    }
}

function m5pm_terminal(array $status): ?array
{
    if (($status['running'] ?? null) !== false || ($status['signaled'] ?? null) !== false || ($status['stopped'] ?? null) !== false
        || !is_int($status['exitcode'] ?? null) || $status['exitcode'] < 0) return null;
    return ['running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => $status['exitcode']];
}
function m5pm_cli(string $mode, array $private, array &$evidence): array
{
    m5pm_expect(m5pm_source() === $evidence['binding'], 'CHILD_SOURCE_SAME'); audit_guard_assert(); m5pm_private_assert($private);
    $process = proc_open([PHP_BINARY, '/workspace/' . M5PM_CLI, $mode, '--config-file=' . $private['path']],
        [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace');
    m5pm_expect(is_resource($process), 'NATIVE_CHILD_STARTED');
    $first = null; $terminal = null; $captureError = null; $close = null;
    $buffers = [1 => '', 2 => '']; $counts = [1 => 0, 2 => 0]; $hashes = [1 => hash_init('sha256'), 2 => hash_init('sha256')];
    try {
        $first = proc_get_status($process); m5pm_expect(is_array($first) && is_int($first['pid']) && $first['pid'] > 0, 'NATIVE_CHILD_PID');
        $terminal = m5pm_terminal($first);
        foreach ($pipes as $pipe) m5pm_expect(stream_set_blocking($pipe, false), 'CHILD_PIPE_MODE');
        while (true) {
            $read = [];
            foreach ($pipes as $pipe) if (!feof($pipe)) $read[] = $pipe;
            if ($read === []) break;
            $write = null; $except = null; $ready = stream_select($read, $write, $except, 0, 200000);
            m5pm_expect($ready !== false, 'CHILD_PIPE_SELECT');
            foreach ($read as $pipe) {
                $part = fread($pipe, 8192); m5pm_expect(is_string($part), 'CHILD_PIPE_READ');
                $channel = $pipe === $pipes[1] ? 1 : 2; $counts[$channel] += strlen($part); hash_update($hashes[$channel], $part);
                $room = 1048576 - strlen($buffers[$channel]); if ($room > 0) $buffers[$channel] .= substr($part, 0, $room);
            }
            if ($terminal === null) { $status = proc_get_status($process); if (is_array($status)) $terminal = m5pm_terminal($status); }
        }
    } catch (Throwable $error) { $captureError = get_class($error); }
    finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if ($terminal === null) { $status = proc_get_status($process); if (is_array($status)) $terminal = m5pm_terminal($status); }
        $close = proc_close($process);
    }
    $native = is_int($close) && $close >= 0 ? $close : ($close === -1 && $terminal !== null ? $terminal['exitcode'] : null);
    $result = null;
    if ($counts[1] <= 1048576 && $counts[2] <= 1048576) {
        try { $result = json_decode($buffers[1], true, 64, JSON_THROW_ON_ERROR); } catch (Throwable $error) { $captureError ??= get_class($error); }
    }
    $evidence['children'][] = ['mode' => $mode, 'pid' => $first['pid'] ?? null, 'native_exit' => $native,
        'proc_close_exit' => $close, 'same_handle_terminal' => $terminal, 'capture_error_class' => $captureError,
        'stdout_bytes' => $counts[1], 'stdout_sha256' => hash_final($hashes[1]),
        'stderr_bytes' => $counts[2], 'stderr_sha256' => hash_final($hashes[2]), 'result' => is_array($result) ? $result : null];
    m5pm_expect($captureError === null && $native === 0 && ($terminal === null || $native === $terminal['exitcode'])
        && $counts[2] === 0 && $counts[1] <= 1048576 && is_array($result) && !array_is_list($result)
        && m5pm_keys($result, ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known', 'issues', 'failure_phase', 'marker', 'source_sha256', 'history_mapping', 'payment_producer', 'acceptance'])
        && $result['mode'] === $mode && $result['native_exit'] === 0 && $result['pending_steps_known'] === true && $result['issues'] === []
        && $result['failure_phase'] === null && $result['marker'] === M5PM_MARKER && $result['source_sha256'] === $evidence['binding']['cli_sha256']
        && $result['history_mapping'] === 'NOT RUN' && $result['payment_producer'] === 'NOT IMPLEMENTED' && $result['acceptance'] === 'NOT RUN', 'ACTUAL_NORMAL_CLI');
    return $result;
}

/** A single create-only publication attempt. A partial or pre-existing result is never overwritten. */
function m5pm_publish(array &$evidence, bool &$attempted, bool $sourceBound): bool
{
    if ($attempted) return false;
    $attempted = true; $written = false;
    if ($sourceBound) {
        try {
            $path = '/run/audit/' . M5PM_RESULT;
            m5pm_expect(!file_exists($path) && !is_link($path), 'RESULT_CREATE_ONLY');
            $bytes = m5pm_json($evidence); $handle = @fopen($path, 'xb'); m5pm_expect(is_resource($handle), 'RESULT_CREATE');
            try {
                m5pm_expect(@chmod($path, 0600), 'RESULT_MODE'); $offset = 0;
                while ($offset < strlen($bytes)) {
                    $count = @fwrite($handle, substr($bytes, $offset)); m5pm_expect(is_int($count) && $count > 0, 'RESULT_WRITE'); $offset += $count;
                }
                m5pm_expect(@fflush($handle), 'RESULT_FLUSH');
            } finally { m5pm_expect(@fclose($handle), 'RESULT_CLOSE'); }
            $written = true;
        } catch (Throwable $error) {
            $evidence['status'] = 'FAIL';
            $evidence['publication_failure'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
        }
    }
    if (!$written && $evidence['termination'] === 'NORMAL_RETURN') $evidence['entry_return_code'] = 1;
    echo m5pm_json(['status' => $written ? $evidence['status'] : 'FAIL', 'scope' => M5PM_SCOPE,
        'entry_return_code' => $evidence['entry_return_code'], 'native_exit' => null, 'native_exit_observation' => 'HOST_NATIVE_REQUIRED',
        'termination' => $evidence['termination'], 'phase' => $evidence['phase'], 'actual_command_counts' => $evidence['actual_command_counts'] ?? null,
        'private_cleanup_verified' => $evidence['private_cleanup_verified'], 'private_cleanup_state' => $evidence['private_cleanup_state'],
        'receipt_write_verified' => $written, 'evidence_ref' => $written ? M5PM_RESULT : null, 'business_acceptance' => 'NOT RUN']);
    return $written;
}

function m5pm_main(): int
{
    umask(0077); $phase = 'source'; $sourceBound = false; $publicationAttempted = false; $finished = false;
    $private = ['created' => false, 'path' => null, 'handle' => null, 'identity' => null, 'complete' => null, 'cleanup_attempted' => false];
    $evidence = ['schema' => 1, 'status' => 'FAIL', 'scope' => M5PM_SCOPE, 'binding' => null, 'children' => [], 'snapshots' => [], 'comparisons' => [],
        'phase' => $phase, 'termination' => 'IN_PROGRESS', 'entry_return_code' => null, 'native_exit' => null, 'native_exit_observation' => 'HOST_NATIVE_REQUIRED',
        'private_cleanup_verified' => false, 'private_cleanup_state' => 'NOT_CREATED',
        'stopped_cases' => ['denial_conflict_concurrency_fault_restore_paid' => 'NOT RUN'], 'history_mapping' => 'NOT RUN',
        'payment_producer' => 'NOT IMPLEMENTED', 'business_acceptance' => 'NOT RUN'];
    register_shutdown_function(static function () use (&$finished, &$phase, &$sourceBound, &$publicationAttempted, &$private, &$evidence): void {
        if ($finished) return;
        // exit(2) in the existing guard bypasses finally. Never guess or replace that native exit.
        $evidence['status'] = 'FAIL'; $evidence['phase'] = $phase; $evidence['termination'] = 'SHUTDOWN_WITHOUT_NORMAL_RETURN';
        $evidence['entry_return_code'] = null; $evidence['native_exit'] = null; $evidence['native_exit_observation'] = 'HOST_NATIVE_REQUIRED';
        m5pm_private_cleanup($private, $evidence);
        try { m5pm_publish($evidence, $publicationAttempted, $sourceBound); }
        catch (Throwable $error) { fwrite(STDERR, "FAIL structure result publication unavailable; host native observation required\n"); }
    });
    try {
        $evidence['binding'] = m5pm_source(); $sourceBound = true; audit_guard_assert();
        $phase = 'private_migrator'; $migratorPath = '/run/audit-bootstrap/migrator.json';
        $migrator = m5pm_read($migratorPath); $stat = @lstat($migratorPath);
        m5pm_expect(is_array($stat) && ($stat['mode'] & 0777) === 0600 && $stat['uid'] === posix_geteuid()
            && m5pm_keys($migrator, ['username', 'password']) && $migrator['username'] === 'audit_migrator' && is_string($migrator['password']), 'DEDICATED_PRIVATE_MIGRATOR');
        $db = audit_pdo($migrator); $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        m5pm_expect(count($grants) === 2 && count(array_filter($grants, static fn(string $grant): bool => str_starts_with($grant, 'GRANT USAGE ON *.* TO '))) === 1
            && count(array_filter($grants, static fn(string $grant): bool => str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `audit_auth`.* TO ') && !str_contains($grant, 'GRANT OPTION'))) === 1
            && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')], 'MIGRATOR_SAME_SCHEMA_SENTINEL');
        m5pm_private_create($private, m5pm_json(['hostname' => 'audit-mysql', 'hostport' => '3306', 'database' => 'audit_auth',
            'username' => $migrator['username'], 'password' => $migrator['password'], 'prefix' => 'eb_']));
        unset($migrator, $grants, $db);
        $phase = 'baseline'; $before = m5pm_snapshot(); $evidence['snapshots']['before'] = m5pm_snapshot_evidence($before);
        $newNames = array_keys(m5pm_expected_tables()); sort($newNames, SORT_STRING);
        foreach ($newNames as $name) m5pm_expect(!in_array($name, $before['names'], true), 'FRESH_PAYMENT_TARGETS_ABSENT');
        $steps = ['marketplace_payment_registry', 'marketplace_payment_attempt'];
        $phase = 'check_first'; $check = m5pm_cli('check', $private, $evidence);
        m5pm_expect($check['state'] === 'applicable' && $check['changed'] === false && $check['applied_steps'] === [] && $check['pending_steps'] === $steps, 'FIRST_CHECK_EXACT_TWO_STEPS');
        $checked = m5pm_snapshot(); m5pm_expect($checked === $before, 'FIRST_CHECK_FULL_UNCHANGED');
        $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'before', 'snapshot_sha256' => hash('sha256', serialize($checked))];
        $phase = 'apply_first'; $apply = m5pm_cli('apply-structure', $private, $evidence);
        m5pm_expect($apply['state'] === 'completed' && $apply['changed'] === true && $apply['applied_steps'] === $steps && $apply['pending_steps'] === [], 'FIRST_APPLY_EXACT_TWO_STEPS');
        $after = m5pm_snapshot(); $evidence['snapshots']['after'] = m5pm_snapshot_evidence($after);
        $added = array_values(array_diff($after['names'], $before['names'])); sort($added, SORT_STRING);
        m5pm_expect($added === $newNames && count($after['names']) === count($before['names']) + 2, 'ONLY_TWO_NEW_TABLES');
        m5pm_preserved($before, $after); m5pm_new_tables($after);
        foreach (['check', 'apply-structure'] as $mode) {
            $phase = $mode . '_repeat'; $result = m5pm_cli($mode, $private, $evidence);
            m5pm_expect($result['state'] === 'completed' && $result['changed'] === false && $result['applied_steps'] === [] && $result['pending_steps'] === [], 'REPEAT_COMPLETED_UNCHANGED');
            $again = m5pm_snapshot(); m5pm_expect($again === $after, 'FULL_REPEAT_UNCHANGED');
            $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'after', 'snapshot_sha256' => hash('sha256', serialize($again))];
        }
        $phase = 'final_binding'; m5pm_expect(m5pm_source() === $evidence['binding'], 'FINAL_SOURCE_SAME'); audit_guard_assert(); m5pm_private_assert($private);
        $counts = array_count_values(array_column($evidence['children'], 'mode'));
        m5pm_expect(count($evidence['children']) === 4 && $counts === ['check' => 2, 'apply-structure' => 2], 'ACTUAL_FOUR_NATIVE_COMMANDS');
        $evidence['actual_command_counts'] = $counts; $evidence['original_table_count'] = count($before['names']);
        $evidence['original_rows'] = [];
        foreach ($before['tables'] as $name => $table) $evidence['original_rows'][$name] = $table['projection']['rows'];
        $evidence['independent_new_metadata_verified'] = true; $evidence['status'] = M5PM_VERIFIED;
    } catch (Throwable $error) {
        $evidence['status'] = 'FAIL'; $evidence['diagnostic'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'phase' => $phase];
    } finally { m5pm_private_cleanup($private, $evidence); unset($migrator, $grants, $db); }
    $evidence['phase'] = $phase; $evidence['termination'] = 'NORMAL_RETURN';
    $returnCode = $evidence['status'] === M5PM_VERIFIED ? 0 : 1; $evidence['entry_return_code'] = $returnCode;
    $published = m5pm_publish($evidence, $publicationAttempted, $sourceBound); $finished = true;
    return $published ? $returnCode : 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(m5pm_main());
