<?php
declare(strict_types=1);

// Only the isolation helpers are loaded. Twenty fixed native children run before files-bootstrap or App.
require __DIR__ . '/lib/harness.php';

const M4FM_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4FM_SCOPE = 'MS4_EARLY_AFTERSALE_STRUCTURE_AND_MENUS_ONLY';
const M4FM_VERIFIED = 'VERIFIED_MS4_EARLY_AFTERSALE_STRUCTURE_AND_MENUS_ONLY';
const M4FM_MARKER = 'marketplace-ms4-fulfillment-v1';
const M4FM_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-migration.php';
const M4FM_CLI = 'help/migrations/marketplace-ms4-fulfillment.php';
const M4FM_RESULT = 'evidence/ms4-aftersale-early-migration-normal.json';

function m4fm_expect(bool $condition, string $code): void { if (!$condition) throw new RuntimeException($code); }
function m4fm_json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
function m4fm_quote(string $name): string
{
    m4fm_expect(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) === 1, 'SQL_IDENTIFIER');
    return '`' . $name . '`';
}
function m4fm_keys(array $value, array $expected): bool
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
    return $actual === $expected;
}
function m4fm_rows(PDO $db, string $sql, array $args = []): array
{
    $statement = $db->prepare($sql);
    m4fm_expect($statement instanceof PDOStatement && $statement->execute($args), 'SQL_READ');
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function m4fm_read(string $path, ?string &$digest = null, int $maximum = 1048576): array
{
    m4fm_expect(in_array($maximum, [1048576, 16777216], true), 'PROOF_BOUND');
    clearstatcache(true, $path); $before = @lstat($path);
    m4fm_expect(is_array($before) && is_file($path) && !is_link($path) && $before['size'] <= $maximum, 'PROOF_FILE');
    $handle = @fopen($path, 'rb'); m4fm_expect(is_resource($handle), 'PROOF_OPEN');
    try {
        $opened = @fstat($handle);
        m4fm_expect(is_array($opened), 'PROOF_STAT');
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size'] as $key) m4fm_expect($before[$key] === $opened[$key], 'PROOF_HANDLE_IDENTITY');
        $raw = stream_get_contents($handle, $maximum + 1); $after = @fstat($handle);
        m4fm_expect(is_string($raw) && strlen($raw) <= $maximum && strlen($raw) === $opened['size'] && is_array($after), 'PROOF_STABLE_READ');
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) m4fm_expect($after[$key] === $opened[$key], 'PROOF_STABLE_READ');
    } finally { fclose($handle); }
    clearstatcache(true, $path); $named = @lstat($path);
    m4fm_expect(is_array($named) && !is_link($path), 'PROOF_NAMED_IDENTITY');
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size'] as $key) m4fm_expect($named[$key] === $opened[$key], 'PROOF_NAMED_IDENTITY');
    $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    m4fm_expect(is_array($value) && !array_is_list($value), 'PROOF_OBJECT');
    $digest = hash('sha256', $raw); return $value;
}
function m4fm_source_paths(): array
{
    $paths = [M4FM_ENTRY, 'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/lib/guard.php',
        'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php', 'help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms5-payment.php',
        'help/migrations/marketplace-ms5-payment.md', M4FM_CLI, 'crmeb/public/install/crmeb.sql',
        'help/audit-regressions/isolated/ms5-payment-migration.php', 'help/migrations/marketplace-ms4-aftersale.php',
        'help/migrations/marketplace-ms4-aftersale-menus.php', 'help/migrations/marketplace-ms4-review-menus.php',
        'help/migrations/marketplace-ms4-assets.php', 'crmeb/app/services/marketplace/assets/AssetSchema.php'];
    sort($paths, SORT_STRING); return $paths;
}

function m4fm_mount(array $facts, string $destination): array
{
    $matches = array_values(array_filter($facts['mounts'] ?? [], static fn(array $mount): bool => ($mount['Destination'] ?? '') === $destination));
    m4fm_expect(count($matches) === 1, 'EXACT_MOUNT'); return $matches[0];
}
function m4fm_source(): array
{
    m4fm_expect(PHP_SAPI === 'cli' && PHP_INT_SIZE >= 8 && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
        && count($_SERVER['argv']) === 1 && audit_repo_dir() === '/workspace' && audit_run_dir() === '/run/audit'
        && getenv('AUDIT_REPO_DIR') === '/workspace' && getenv('AUDIT_RUN_DIR') === '/run/audit', 'REAL_ENTRY');
    $run = getenv('AUDIT_RUN_ID'); $sha = getenv('AUDIT_SOURCE_SHA'); $manifest = audit_manifest();
    m4fm_expect(getenv('AUDIT_ISOLATED') === '1' && getenv('AUDIT_GUARD_MODE') === 'read-only'
        && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', (string) $run) === 1 && preg_match('/\A[a-f0-9]{40}\z/D', (string) $sha) === 1
        && getenv('AUDIT_PROJECT') === 'tianli-audit-' . $run && ($manifest['run_id'] ?? '') === $run
        && ($manifest['commit_sha'] ?? '') === $sha && ($manifest['project'] ?? '') === getenv('AUDIT_PROJECT')
        && ($manifest['guard_mode'] ?? '') === 'read-only' && ($manifest['isolation'] ?? '') === 'ISO-FULL'
        && ($manifest['network_internal'] ?? null) === true, 'CURRENT_ISO_SOURCE');
    $names = array_keys($manifest['services'] ?? []); sort($names, SORT_STRING);
    m4fm_expect($names === ['audit-ms4-fulfillment-migration', 'audit-ms5-paid-producer', 'audit-mysql', 'audit-redis'], 'DEDICATED_ROLES');
    $network = $manifest['network'] ?? [];
    m4fm_expect(preg_match('/\A[a-f0-9]{64}\z/D', (string) ($network['id'] ?? '')) === 1
        && is_string($network['name'] ?? null) && $network['name'] !== '', 'REGISTERED_NETWORK');
    $base = '/run/audit/evidence/'; $createdHash = null; $bindingHash = null;
    $facts = m4fm_read($base . 'ms4-fulfillment-migration-container-created.json', $createdHash);
    $binding = m4fm_read($base . 'ms4-fulfillment-migration-source-binding.json', $bindingHash);
    m4fm_expect(m4fm_keys($binding, ['schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source', 'entry_sha256', 'cli_sha256', 'source_files', 'initialization_predecessor_sha256', 'successor_cli_sha256'])
        && $binding['schema'] === 1 && $binding['run_id'] === $run && $binding['source_sha'] === $sha
        && is_string($binding['requested_repo_source']) && $binding['requested_repo_source'] !== ''
        && is_string($binding['requested_run_source']) && $binding['requested_run_source'] !== ''
        && ($facts['run_id'] ?? '') === $run && ($facts['source_sha'] ?? '') === $sha, 'EXACT_BINDING');
    $service = $manifest['services']['audit-ms4-fulfillment-migration'];
    m4fm_expect(preg_match('/\A[a-f0-9]{64}\z/D', (string) ($service['id'] ?? '')) === 1
        && ($facts['id'] ?? '') === $service['id'] && gethostname() === substr($service['id'], 0, 12)
        && ($facts['hostname'] ?? '') === gethostname() && ($facts['image'] ?? '') === M4FM_IMAGE && ($service['image_id'] ?? '') === M4FM_IMAGE
        && ($facts['status'] ?? '') === 'created' && ($facts['read_only'] ?? null) === true && ($facts['privileged'] ?? null) === false
        && ($facts['network_mode'] ?? '') === $network['name'] && is_array($facts['networks'] ?? null)
        && array_keys($facts['networks']) === [$network['name']] && is_array($facts['ports'] ?? null)
        && ($facts['labels']['tianli.audit'] ?? '') === '1' && ($facts['labels']['tianli.audit.run'] ?? '') === $run
        && ($facts['labels']['com.docker.compose.project'] ?? '') === $manifest['project']
        && ($facts['labels']['com.docker.compose.service'] ?? '') === 'audit-ms4-fulfillment-migration'
        && ($facts['entrypoint'] ?? null) === ['php'] && ($facts['command'] ?? null) === [M4FM_ENTRY]
        && ($facts['workdir'] ?? '') === '/workspace' && ($facts['restart_count'] ?? null) === 0
        && ($facts['user'] ?? null) === '0:0' && posix_geteuid() === 0 && posix_getegid() === 0
        && getenv('AUDIT_MILESTONE') === 'MS4' && getenv('AUDIT_PHASE') === 'fulfillment-migration', 'NATIVE_CREATED_CONTAINER');
    foreach ($facts['ports'] as $published) m4fm_expect($published === null, 'NO_PUBLISHED_PORT');
    foreach (['audit-mysql', 'audit-redis'] as $host) {
        $address = gethostbyname($host); $endpoint = $manifest['services'][$host];
        m4fm_expect(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $address === ($endpoint['address'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($endpoint['id'] ?? '')) === 1
            && preg_match('/\Asha256:[a-f0-9]{64}\z/D', (string) ($endpoint['image_id'] ?? '')) === 1, 'REGISTERED_ENDPOINT');
    }
    $volumes = [];
    foreach (['mysql-data', 'redis-data', 'bootstrap-secrets', 'redis-secrets', 'app-secrets', 'fixture-secrets', 'aftersale-evidence', 'customer-form-evidence', 'source-tree'] as $name) $volumes[] = $manifest['project'] . '_' . $name;
    sort($volumes, SORT_STRING); $actual = $manifest['volumes'] ?? []; sort($actual, SORT_STRING);
    m4fm_expect($actual === $volumes && is_array($manifest['volume_identities'] ?? null)
        && m4fm_keys($manifest['volume_identities'], $volumes), 'EXACT_NINE_OWN_VOLUMES');
    foreach ($volumes as $volume) m4fm_expect(is_string($manifest['volume_identities'][$volume]) && $manifest['volume_identities'][$volume] !== '', 'OWN_VOLUME_IDENTITY');
    $seen = [];
    foreach ($facts['mounts'] ?? [] as $mount) {
        $destination = $mount['Destination'] ?? '';
        m4fm_expect(in_array($destination, ['/workspace', '/source', '/run/audit', '/run/audit-bootstrap', '/run/audit-app', '/tmp'], true)
            && !isset($seen[$destination]) && !str_contains((string) ($mount['Source'] ?? ''), 'docker.sock'), 'ONLY_ENTRY_MOUNTS');
        $seen[$destination] = true;
        if ($destination === '/tmp') m4fm_expect(($mount['Type'] ?? '') === 'tmpfs', 'ONLY_PRIVATE_TMPFS');
    }
    foreach (['/workspace', '/source'] as $destination) {
        $mount = m4fm_mount($facts, $destination);
        m4fm_expect(($mount['Type'] ?? '') === 'volume' && ($mount['RW'] ?? null) === false
            && ($mount['Name'] ?? '') === $manifest['project'] . '_source-tree', 'CURRENT_SOURCE_RO');
    }
    $mount = m4fm_mount($facts, '/run/audit');
    m4fm_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === true
        && audit_canonical_host_bind_source((string) ($mount['Source'] ?? '')) === audit_canonical_host_bind_source($binding['requested_run_source']), 'CURRENT_RUN_BIND');
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'] as $name => $destination) {
        $mount = m4fm_mount($facts, $destination);
        m4fm_expect(($mount['Type'] ?? '') === 'volume' && ($mount['RW'] ?? null) === false
            && ($mount['Name'] ?? '') === $manifest['project'] . '_' . $name, 'OWN_PRIVATE_INPUT_RO');
    }
    m4fm_expect(is_array($facts['tmpfs'] ?? null) && array_keys($facts['tmpfs']) === ['/tmp'], 'PRIVATE_TMPFS_DECLARED');
    $tmp = false; $sourceRoots = ['/workspace' => 0, '/source' => 0];
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = isset($parts[1]) ? explode(' ', $parts[1]) : [];
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmp = true;
        $destination = $left[4] ?? '';
        if (array_key_exists($destination, $sourceRoots)) {
            $sourceRoots[$destination]++; $options = explode(',', $left[5] ?? '');
            m4fm_expect(in_array('ro', $options, true) && !in_array('rw', $options, true), 'CURRENT_SOURCE_KERNEL_RO');
        }
    }
    m4fm_expect($sourceRoots === ['/workspace' => 1, '/source' => 1], 'CURRENT_SOURCE_KERNEL_BOTH_ROOTS_ONCE');
    m4fm_expect($tmp && !file_exists('/workspace/crmeb/.env') && !is_link('/workspace/crmeb/.env')
        && !file_exists('/workspace/crmeb/public/install.lock') && !is_link('/workspace/crmeb/public/install.lock'), 'NO_HOST_CONFIG_PRIVATE_TMPFS');
    m4fm_expect($binding['entry_sha256'] === hash_file('sha256', __FILE__)
        && $binding['cli_sha256'] === hash_file('sha256', '/workspace/' . M4FM_CLI)
        && is_array($binding['source_files']) && array_is_list($binding['source_files']) && count($binding['source_files']) === 17, 'CURRENT_ENTRY_CLI_BINDING');
    $paths = [];
    foreach ($binding['source_files'] as $item) {
        m4fm_expect(is_array($item) && m4fm_keys($item, ['path', 'bytes', 'sha256']), 'EXACT_SOURCE_ITEM');
        $relative = $item['path'];
        m4fm_expect(is_string($relative) && in_array($relative, m4fm_source_paths(), true) && !isset($paths[$relative])
            && is_int($item['bytes']) && $item['bytes'] >= 0 && is_string($item['sha256'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $item['sha256']) === 1, 'EXACT_SOURCE_ITEM');
        $paths[$relative] = true;
        foreach (['/workspace', '/source'] as $root) m4fm_expect(is_file($root . '/' . $relative) && !is_link($root . '/' . $relative)
            && filesize($root . '/' . $relative) === $item['bytes'] && hash_file('sha256', $root . '/' . $relative) === $item['sha256'], 'ACTUAL_SOURCE_BYTES');
    }
    $paths = array_keys($paths); sort($paths, SORT_STRING); m4fm_expect($paths === m4fm_source_paths(), 'COMPLETE_DEPENDENCY_BINDING');
    $predecessor = m4fm_initialization_predecessor($manifest, $binding, $run, $sha);
    $fileHashes = array_column($binding['source_files'], 'sha256', 'path'); $prerequisites = [];
    foreach (['help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms4.php', 'help/migrations/marketplace-ms5-payment.php'] as $path)
        $prerequisites[$path] = $fileHashes[$path];
    m4fm_expect(is_array($binding['successor_cli_sha256']) && m4fm_keys($binding['successor_cli_sha256'], array_values(m4ae_scripts())), 'EARLY_EXACT_THREE_SUCCESSOR_SOURCE_KEYS');
    foreach (m4ae_scripts() as $script) m4fm_expect($binding['successor_cli_sha256'][$script] === $fileHashes[$script], 'EARLY_ACTUAL_SUCCESSOR_SOURCE_HASH');
    $freezeHash = null; m4fm_read('/run/audit/preparation-source-binding.json', $freezeHash, 16777216);
    return ['run_id' => $run, 'container_id' => $service['id'], 'entry_namespace_pid' => getmypid(), 'source_sha' => $sha,
        'entry_sha256' => $binding['entry_sha256'], 'cli_sha256' => $binding['cli_sha256'], 'source_files' => count($binding['source_files']),
        'binding_sha256' => $bindingHash, 'native_created_sha256' => $createdHash,
        'freeze_sha256' => $freezeHash, 'initialization_predecessor_sha256' => $predecessor,
        'prerequisite_source_sha256' => $prerequisites, 'successor_cli_sha256' => $binding['successor_cli_sha256']];
}

/** Actual initialization/MS5 public closure, before any files-bootstrap, App or paid producer. */
function m4ae_public_read(string $path):array { $hash=null;return m4fm_read($path,$hash,16777216); }
function m4ae_source_file(string $root,array $item):void
{
    m4fm_expect(in_array($root,['/workspace','/source'],true)&&in_array($item['path'],m4fm_source_paths(),true),
        'EARLY_CLOSED_PREDECESSOR_SOURCE');
    $path=$root.'/'.$item['path'];
    m4fm_expect(is_file($path)&&!is_link($path)&&filesize($path)===$item['bytes']
        &&hash_file('sha256',$path)===$item['sha256'],'ACTUAL_INITIALIZER_SOURCE_BYTES');
}
function m4ae_ms5_keys(array $value, array $expected): bool
{
    $keys = array_keys($value); sort($keys, SORT_STRING); sort($expected, SORT_STRING); return $keys === $expected;
}
function m4ae_ms5_same(mixed $actual, mixed $expected): void
{
    if (!is_array($expected)) { m4fm_expect($actual === $expected, 'CUSTOMER_MS5_NATIVE_VALUE_AND_TYPE'); return; }
    m4fm_expect(is_array($actual) && array_is_list($actual) === array_is_list($expected)
        && m4ae_ms5_keys($actual, array_keys($expected)), 'CUSTOMER_MS5_NATIVE_ARRAY_SHAPE');
    foreach ($expected as $key => $value) m4ae_ms5_same($actual[$key], $value);
}
function m4ae_ms5_sources(array $rows): array
{
    $expected = ['crmeb/public/install/crmeb.sql', 'help/audit-regressions/isolated/lib/guard.php',
        'help/audit-regressions/isolated/lib/harness.php', 'help/audit-regressions/isolated/ms5-payment-migration.php',
        'help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms2.php',
        'help/migrations/marketplace-ms4.md', 'help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms5-payment.md', 'help/migrations/marketplace-ms5-payment.php'];
    sort($expected, SORT_STRING); $hashes = [];
    m4fm_expect(array_is_list($rows) && count($rows) === 10, 'CUSTOMER_EXACT_TEN_MIGRATION_SOURCES');
    foreach ($rows as $row) {
        m4fm_expect(is_array($row) && m4ae_ms5_keys($row, ['path', 'bytes', 'sha256']) && is_string($row['path'])
            && in_array($row['path'], $expected, true) && !isset($hashes[$row['path']])
            && is_int($row['bytes']) && $row['bytes'] >= 0 && is_string($row['sha256'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1, 'CUSTOMER_EXACT_MIGRATION_SOURCE_DESCRIPTOR');
        foreach (['/workspace', '/source'] as $root) m4ae_source_file($root, $row);
        $hashes[$row['path']] = $row['sha256'];
    }
    m4fm_expect(array_keys($hashes) === $expected, 'CUSTOMER_SORTED_COMPLETE_DUAL_RO_MIGRATION_SOURCES');
    return $hashes;
}
function m4ae_ms5_manifest(array $context, array $manifest, string $role): void
{
    $roles = array_keys($manifest['services']); sort($roles, SORT_STRING);
    $expected = [$role, 'audit-mysql', 'audit-redis']; sort($expected, SORT_STRING);
    m4fm_expect($manifest['run_id'] === $context['run_id'] && $manifest['commit_sha'] === $context['source_sha']
        && $manifest['project'] === $context['project'] && $manifest['isolation'] === 'ISO-FULL'
        && $manifest['guard_mode'] === 'read-only' && $manifest['network_internal'] === true && $roles === $expected
        && $manifest['network'] === ['id' => $context['network_id'], 'name' => $context['project'] . '_isolation'],
        'CUSTOMER_EXACT_PHASE_MANIFEST');
    $names = []; $identities = [];
    foreach ($context['initial_volumes'] as $row) { $names[] = $row['name']; $identities[$row['name']] = $row['created_at']; }
    sort($names, SORT_STRING); $actual = $manifest['volumes']; sort($actual, SORT_STRING);
    $wanted = [];
    foreach (['mysql-data', 'redis-data', 'bootstrap-secrets', 'redis-secrets', 'app-secrets', 'fixture-secrets', 'aftersale-evidence', 'customer-form-evidence', 'source-tree'] as $suffix) $wanted[] = $context['project'] . '_' . $suffix;
    sort($wanted, SORT_STRING);
    m4fm_expect(count($names) === 9 && count(array_unique($names,SORT_STRING)) === 9
        && $names === $wanted && $actual === $names, 'EARLY_CURRENT_EXACT_NINE_VOLUMES');
    m4ae_ms5_same($manifest['volume_identities'], $identities);
    foreach (['audit-mysql', 'audit-redis'] as $host) m4fm_expect(
        $manifest['services'][$host]['id'] === $context['initial_service_ids'][$host]
        && $manifest['services'][$host]['image_id'] === $context['initial_service_created'][$host]['image']
        && filter_var($manifest['services'][$host]['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        'CUSTOMER_ORIGINAL_BASE_SERVICE_IDENTITIES');
}
function m4ae_ms5_terminal(array $context, array $created, array $terminal, string $proof, string $role, array $command): void
{
    $keys = array_values(array_diff(array_keys($created), ['run_id', 'source_sha', 'proof_name']));
    m4fm_expect(m4ae_ms5_keys($terminal, $keys), 'CUSTOMER_ORIGINAL_RAW_TERMINAL_KEYS');
    foreach (['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command',
        'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at', 'user', 'stop_signal'] as $key)
        m4fm_expect(array_key_exists($key, $created) && array_key_exists($key, $terminal)
            && $created[$key] === $terminal[$key], 'CUSTOMER_ALL_ORIGINAL_IMMUTABLE_NATIVE_FIELDS');
    $mounts = static function (array $rows): array {
        $out = []; foreach ($rows as $row) { ksort($row, SORT_STRING); $out[] = serialize($row); }
        sort($out, SORT_STRING); return $out;
    };
    m4fm_expect(is_array($created['mounts']) && is_array($terminal['mounts'])
        && $mounts($created['mounts']) === $mounts($terminal['mounts'])
        && array_keys($created['networks']) === array_keys($terminal['networks']), 'CUSTOMER_FULL_MOUNT_MULTISET_AND_NETWORKS');
    m4fm_expect($created['run_id'] === $context['run_id'] && $created['source_sha'] === $context['source_sha']
        && $created['proof_name'] === $proof && is_string($created['id'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $created['id']) === 1 && $created['hostname'] === substr($created['id'], 0, 12)
        && $created['image'] === M4FM_IMAGE && $created['status'] === 'created' && $created['pid'] === 0
        && $created['restart_count'] === 0 && $created['read_only'] === true && $created['privileged'] === false
        && $created['entrypoint'] === ['php'] && $created['command'] === $command && $created['workdir'] === '/workspace'
        && in_array($created['user'], ['', '0', '0:0', 'root'], true)
        && $created['labels']['tianli.audit'] === '1' && $created['labels']['tianli.audit.run'] === $context['run_id']
        && $created['labels']['com.docker.compose.project'] === $context['project']
        && $created['labels']['com.docker.compose.service'] === $role
        && $created['network_mode'] === $context['project'] . '_isolation'
        && array_keys($created['networks']) === [$context['project'] . '_isolation']
        && in_array($created['port_bindings'], [null, []], true) && $created['cap_drop'] === ['ALL']
        && in_array($created['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && $terminal['status'] === 'exited' && $terminal['pid'] === 0 && $terminal['exit_code'] === 0
        && $terminal['restart_count'] === 0, 'CUSTOMER_ACTUAL_PREDECESSOR_NATIVE_ZERO');
    foreach ($created['ports'] as $published) m4fm_expect($published === null, 'CUSTOMER_NO_PREDECESSOR_PUBLISHED_PORT');
    m4fm_expect(is_string($terminal['started_at']) && is_string($terminal['finished_at'])
        && strtotime($terminal['started_at']) !== false && strtotime($terminal['finished_at']) !== false
        && strtotime($terminal['started_at']) > 0 && strtotime($terminal['finished_at']) >= strtotime($terminal['started_at']),
        'CUSTOMER_REAL_PREDECESSOR_START_FINISH');
    $initializer = $proof === 'initializer' && $role === 'audit-ms4-initialize';
    m4fm_expect(($proof === 'initializer') === ($role === 'audit-ms4-initialize'), 'CUSTOMER_EXACT_INITIALIZER_PROOF_ROLE_PAIR');
    $expectedDestinations = ['/workspace', '/source', '/run/audit', '/run/audit-bootstrap', '/run/audit-app'];
    if ($initializer) $expectedDestinations = [...$expectedDestinations, '/run/ms4-prep', '/run/audit-customer-form', '/run/audit-source-tree'];
    $destinations = [];
    foreach ($created['mounts'] as $mount) {
        $destination = $mount['Destination'];
        m4fm_expect(is_string($destination) && !isset($destinations[$destination])
            && in_array($destination, [...$expectedDestinations, '/tmp'], true), 'CUSTOMER_CLOSED_PREDECESSOR_MOUNTS');
        $destinations[$destination] = true;
        if ($destination === '/tmp') m4fm_expect($mount['Type'] === 'tmpfs', 'CUSTOMER_ONLY_PRIVATE_TMPFS_MOUNT');
    }
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'], '/run/audit' => $context['run_dir']]
        + ($role === 'audit-ms4-initialize' ? ['/run/ms4-prep' => $context['prep_dir']] : []) as $destination => $source) {
        $mount = m4fm_mount($created, $destination);
        m4fm_expect($mount['Type'] === 'bind' && $mount['RW'] === ($destination === '/run/audit')
            && audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($source),
            'CUSTOMER_EXACT_PREDECESSOR_SOURCE_RUN_BINDS');
    }
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'app-secrets' => '/run/audit-app'] as $suffix => $destination) {
        $mount = m4fm_mount($created, $destination);
        m4fm_expect($mount['Type'] === 'volume' && $mount['Name'] === $context['project'] . '_' . $suffix && $mount['RW'] === false,
            'CUSTOMER_READ_ONLY_PREDECESSOR_CONFIG_VOLUMES');
    }
    if ($initializer) {
        $sourceTreeMount = m4fm_mount($created, '/run/audit-source-tree');
        m4fm_expect($sourceTreeMount['Type'] === 'volume' && $sourceTreeMount['Name'] === $context['project'] . '_source-tree'
            && $sourceTreeMount['RW'] === true, 'CUSTOMER_INITIALIZER_ONLY_SOURCE_TREE_RW_VOLUME');
        $mount = m4fm_mount($created, '/run/audit-customer-form');
        m4fm_expect($mount['Type'] === 'volume' && $mount['Name'] === $context['project'] . '_customer-form-evidence'
            && $mount['RW'] === true, 'CUSTOMER_INITIALIZER_OWN_CUSTOMER_FORM_RW_VOLUME');
    }
    m4fm_expect(array_key_exists('/tmp', $created['tmpfs']), 'CUSTOMER_ACTUAL_PREDECESSOR_TMPFS');
}
function m4ae_ms5_child(array $child): void
{
    m4fm_expect(is_int($child['pid']) && $child['pid'] > 0 && $child['native_exit'] === 0
        && $child['capture_error_class'] === null && is_int($child['stdout_bytes']) && $child['stdout_bytes'] > 0
        && $child['stdout_bytes'] <= 1048576 && $child['stderr_bytes'] === 0
        && $child['stderr_sha256'] === hash('sha256', '') && is_string($child['stdout_sha256'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $child['stdout_sha256']) === 1
        && in_array($child['proc_close_exit'], [0, -1], true)
        && ($child['proc_close_exit'] === 0 || $child['same_handle_terminal'] !== null), 'CUSTOMER_REAL_SAME_HANDLE_CHILD_ZERO');
    if ($child['same_handle_terminal'] !== null) m4ae_ms5_same($child['same_handle_terminal'],
        ['running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 0]);
}

function m4fm_initialization_predecessor(array $manifest,array $binding,string $run,string $sha):array
{
    $base='/run/audit/evidence/';$context=m4ae_public_read('/run/audit/actual-run-context.json');
    $freeze=m4ae_public_read('/run/audit/preparation-source-binding.json');
    m4fm_expect($context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']===$manifest['project']
        &&$context['freeze_sha256']===hash_file('sha256','/run/audit/preparation-source-binding.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir'],
        'EARLY_ACTUAL_CONTEXT_FREEZE_SOURCE');
    $hashes=m4ae_ms5_sources($freeze['driver_source_files']);
    $names = ['driver-terminal.json', 'initializer-completed-terminal.json', 'initializer-created.json', 'initializer-result.json',
        'ms5-payment-migration-container-created.json', 'ms5-payment-migration-normal.json',
        'ms5-payment-migration-formal-manifest.json', 'ms5-payment-migration-source-binding.json']; $before = [];
    foreach ($names as $name) { m4ae_public_read($base . $name); $before[$name] = hash_file('sha256', $base . $name); }
    $created = m4ae_public_read($base . 'initializer-created.json'); $terminal = m4ae_public_read($base . 'initializer-completed-terminal.json');
    $initializer = m4ae_public_read($base . 'initializer-result.json');
    m4ae_ms5_terminal($context, $created, $terminal, 'initializer', 'audit-ms4-initialize', ['/run/ms4-prep/ms4-initialize.php']);
    $initializerBinding = $initializer['binding'];
    m4fm_expect(m4ae_ms5_keys($initializerBinding, ['container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'])
        && $initializer['schema'] === 1 && $initializer['scope'] === 'MS5_PAYMENT_PREREQUISITES_ONLY'
        && $initializer['status'] === 'VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY' && $initializer['native_exit'] === 0
        && $initializer['run_id'] === $context['run_id'] && $initializer['source_sha'] === $context['source_sha']
        && $initializer['private_cleanup_verified'] === true && $initializer['MS4_migration'] === 'VERIFIED_STRUCTURE_ONLY'
        && $initializer['App_HTTP_backfill_enforce_payment'] === 'NOT RUN' && $initializer['acceptance'] === 'NOT RUN'
        && $initializerBinding['container_id'] === $created['id'] && is_int($initializerBinding['entry_namespace_pid']) && $initializerBinding['entry_namespace_pid'] > 0
        && $initializerBinding['source_sha'] === $context['source_sha'] && $initializerBinding['freeze_sha256'] === $context['freeze_sha256']
        && $initializerBinding['created_sha256'] === $before['initializer-created.json'], 'CUSTOMER_REAL_NEW_NINE_INITIALIZER_SOURCE');
    $treeSource=$freeze['source_tree']??null;$treeContext=$context['source_tree']??null;$treeCopy=$initializer['source_tree']??null;
    m4fm_expect(is_array($treeSource)&&is_array($treeContext)&&is_array($treeCopy),'CUSTOMER_SOURCE_TREE_OBJECTS');
    m4fm_expect(m4fm_keys($treeSource,['schema','scope','source_sha','requested_repo_source','volume_suffix','writer_destination','files','directories','file_count','directory_count','manifest_sha256']),'CUSTOMER_SOURCE_TREE_SOURCE_KEYS');
    m4fm_expect(m4fm_keys($treeContext,['volume_name','manifest_sha256','file_count','directory_count']),'CUSTOMER_SOURCE_TREE_CONTEXT_KEYS');
    m4fm_expect(m4fm_keys($treeCopy,['schema','status','source_sha','freeze_sha256','manifest_sha256','volume_name','writer_destination','file_count','directory_count','root_identity','empty_before','complete_namespace','all_regular_no_links','source_bytes_readback_matches','writer_role']),'CUSTOMER_SOURCE_TREE_COPY_KEYS');
    m4fm_expect(is_array($treeCopy['root_identity']),'CUSTOMER_SOURCE_TREE_ROOT_OBJECT');$treeRoot=$treeCopy['root_identity'];
    m4fm_expect(m4fm_keys($treeRoot,['dev','ino','mode','uid','gid']),'CUSTOMER_SOURCE_TREE_ROOT_KEYS');
    m4fm_expect($treeSource['schema']===1&&$treeSource['scope']==='COMPLETE_TRACKED_SOURCE_TREE'
        &&$treeSource['source_sha']===$context['source_sha']&&$treeSource['requested_repo_source']===$context['repo_dir']
        &&$treeSource['volume_suffix']==='source-tree'&&$treeSource['writer_destination']==='/run/audit-source-tree'
        &&is_array($treeSource['files'])&&array_is_list($treeSource['files'])&&is_int($treeSource['file_count'])
        &&$treeSource['file_count']>0&&$treeSource['file_count']===count($treeSource['files'])
        &&is_array($treeSource['directories'])&&array_is_list($treeSource['directories'])&&is_int($treeSource['directory_count'])
        &&$treeSource['directory_count']>=0&&$treeSource['directory_count']===count($treeSource['directories'])
        &&is_string($treeSource['manifest_sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$treeSource['manifest_sha256'])===1
        &&hash('sha256',json_encode(['files'=>$treeSource['files'],'directories'=>$treeSource['directories']],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR))===$treeSource['manifest_sha256']
        &&$treeContext===['volume_name'=>$context['project'].'_source-tree','manifest_sha256'=>$treeSource['manifest_sha256'],
            'file_count'=>$treeSource['file_count'],'directory_count'=>$treeSource['directory_count']]
        &&$treeCopy['schema']===1&&$treeCopy['status']==='COPIED_AND_VERIFIED_COMPLETE_TRACKED_SOURCE_TREE'
        &&$treeCopy['source_sha']===$context['source_sha']&&$treeCopy['freeze_sha256']===$context['freeze_sha256']
        &&$treeCopy['manifest_sha256']===$treeContext['manifest_sha256']&&$treeCopy['volume_name']===$treeContext['volume_name']
        &&$treeCopy['writer_destination']===$treeSource['writer_destination']&&$treeCopy['file_count']===$treeContext['file_count']
        &&$treeCopy['directory_count']===$treeContext['directory_count']&&$treeCopy['writer_role']==='audit-ms4-initialize'
        &&$treeCopy['empty_before']===true&&$treeCopy['complete_namespace']===true&&$treeCopy['all_regular_no_links']===true
        &&$treeCopy['source_bytes_readback_matches']===true&&is_int($treeRoot['dev'])&&$treeRoot['dev']>=0
        &&is_int($treeRoot['ino'])&&$treeRoot['ino']>0&&$treeRoot['mode']===16832&&$treeRoot['uid']===0&&$treeRoot['gid']===0,
        'CUSTOMER_COMPLETE_INITIALIZER_SOURCE_TREE_COPY_BOUND');
    m4fm_expect(hash_file('sha256',$base.'initializer-result.json')===$before['initializer-result.json'],'EARLY_SOURCE_TREE_INITIALIZER_FROZEN_BYTES');
    foreach(['/workspace','/source']as$root){clearstatcache(true,$root);$actualRoot=lstat($root);
        m4fm_expect(is_array($actualRoot)&&is_dir($root)&&!is_link($root)&&realpath($root)===$root,'EARLY_SOURCE_TREE_REAL_CURRENT_ROOT');
        foreach(['dev','ino','mode','uid','gid']as$key)
            m4fm_expect($actualRoot[$key]===$treeRoot[$key],'EARLY_SOURCE_TREE_CURRENT_ROOT_SAME_INITIALIZER');}
    m4fm_expect(hash_file('sha256',$base.'initializer-result.json')===$before['initializer-result.json'],'EARLY_SOURCE_TREE_INITIALIZER_BYTES_UNCHANGED');
    $sequence = [['marketplace-ms1.php', 'check'], ['marketplace-ms1.php', 'apply'], ['marketplace-ms1.php', 'check'],
        ['marketplace-ms2.php', 'check'], ['marketplace-ms2.php', 'apply-structure'], ['marketplace-ms2.php', 'check'],
        ['marketplace-ms4.php', 'check'], ['marketplace-ms4.php', 'apply-structure'], ['marketplace-ms4.php', 'check']];
    m4fm_expect(is_array($initializer['children']) && array_is_list($initializer['children']) && count($initializer['children']) === 9,
        'CUSTOMER_EXACT_NINE_NATIVE_CHILDREN'); $pids = [];
    foreach ($sequence as $index => [$script, $mode]) {
        $child = $initializer['children'][$index]; m4ae_ms5_child($child); $result = $child['result'];
        m4fm_expect($child['script'] === $script && $child['script_sha256'] === $hashes['help/migrations/' . $script]
            && $child['mode'] === $mode && !isset($pids[$child['pid']]) && is_array($result)
            && $result['mode'] === $mode && $result['state'] === ($index % 3 === 0 ? 'applicable' : 'completed')
            && $result['changed'] === ($index % 3 === 1) && $result['issues'] === [], 'CUSTOMER_NINE_SOURCE_BOUND_NATIVE_RESULTS');
        $pids[$child['pid']] = true;
        if ($index >= 6) m4fm_expect($result['native_exit'] === 0 && $result['source_sha256'] === $child['script_sha256']
            && $result['marker'] === 'marketplace-ms4-structure-v1' && $result['pending_steps_known'] === true
            && $result['failure_phase'] === null && $result['trade_enabled'] === false && $result['acceptance'] === 'NOT RUN',
            'CUSTOMER_ACTUAL_MS4_STRUCTURE_PREDECESSOR');
    }
    $children = $initializer['children'];
    m4fm_expect($children[6]['result']['applied_steps'] === [] && is_array($children[6]['result']['pending_steps'])
        && array_is_list($children[6]['result']['pending_steps']) && count($children[6]['result']['pending_steps']) > 0
        && $children[7]['result']['applied_steps'] === $children[6]['result']['pending_steps']
        && $children[7]['result']['pending_steps'] === []
        && $children[8]['result']['applied_steps'] === [] && $children[8]['result']['pending_steps'] === [],
        'CUSTOMER_REAL_MS4_SOURCE_STEP_CHAIN');
    $self = $initializer['actual_self_mer_id'];
    m4fm_expect(is_int($self) && $self > 0 && $self <= 4294967295, 'CUSTOMER_REAL_GENERATED_SELF_MERCHANT_ID');
    foreach ([4, 5] as $index) m4fm_expect($children[$index]['result']['self_mer_id'] === $self
        && $children[$index]['result']['ownership_phase'] === 'structure' && $children[$index]['result']['enabled'] === false,
        'CUSTOMER_ORIGINAL_MS2_SELF_AND_DISABLED_STAGE');
    $structure = $initializer['prerequisite_structure'];
    m4fm_expect(m4ae_ms5_keys($structure, ['pre_ms4_count', 'post_ms4_count', 'pre_ms4_names_sha256', 'post_ms4_names_sha256',
        'added_tables', 'ms4_completed', 'ms5_tables_absent', 'ms4_source_sha256'])
        && $structure['pre_ms4_count'] === 164 && $structure['post_ms4_count'] === 166
        && $structure['added_tables'] === ['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement']
        && $structure['ms4_completed'] === true && $structure['ms5_tables_absent'] === true
        && $structure['ms4_source_sha256'] === $hashes['help/migrations/marketplace-ms4.php']
        && is_string($structure['pre_ms4_names_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $structure['pre_ms4_names_sha256']) === 1
        && is_string($structure['post_ms4_names_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $structure['post_ms4_names_sha256']) === 1,
        'CUSTOMER_EXACT_166_TABLE_PREPAYMENT_STRUCTURE');
    $driver = m4ae_public_read($base . 'ms5-payment-migration-container-created.json');
    $driverTerminal = m4ae_public_read($base . 'driver-terminal.json');
    $migration = m4ae_public_read($base . 'ms5-payment-migration-normal.json');
    $formal = m4ae_public_read($base . 'ms5-payment-migration-formal-manifest.json');
    m4ae_ms5_manifest($context, $formal, 'audit-ms5-payment-migration');
    foreach (['network', 'volumes', 'volume_identities'] as $key) m4ae_ms5_same($formal[$key], $manifest[$key]);
    foreach (['audit-mysql', 'audit-redis'] as $host) m4ae_ms5_same($formal['services'][$host], $manifest['services'][$host]);
    m4ae_ms5_terminal($context, $driver, $driverTerminal, 'driver', 'audit-ms5-payment-migration',
        ['help/audit-regressions/isolated/ms5-payment-migration.php']);
    m4fm_expect($formal['services']['audit-ms5-payment-migration']['id'] === $driver['id']
        && count(array_unique([$created['id'], $driver['id'], $manifest['services']['audit-ms4-fulfillment-migration']['id']], SORT_STRING)) === 3,
        'CUSTOMER_DISTINCT_REAL_PREDECESSOR_AND_FILES_CIDS');
    $migrationBinding = m4ae_public_read($base . 'ms5-payment-migration-source-binding.json');
    m4fm_expect(m4ae_ms5_keys($migrationBinding, ['schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source',
        'entry_sha256', 'cli_sha256', 'source_files']) && $migrationBinding['schema'] === 1
        && $migrationBinding['run_id'] === $context['run_id'] && $migrationBinding['source_sha'] === $context['source_sha']
        && $migrationBinding['requested_repo_source'] === $context['repo_dir'] && $migrationBinding['requested_run_source'] === $context['run_dir']
        && $migrationBinding['entry_sha256'] === $hashes['help/audit-regressions/isolated/ms5-payment-migration.php']
        && $migrationBinding['cli_sha256'] === $hashes['help/migrations/marketplace-ms5-payment.php'],
        'CUSTOMER_EXACT_EIGHT_KEY_MS5_BINDING');
    m4ae_ms5_same($migrationBinding['source_files'], $freeze['driver_source_files']);
    m4ae_ms5_sources($migrationBinding['source_files']);
    $actual = $migration['binding'];
    m4fm_expect(m4ae_ms5_keys($actual, ['run_id', 'container_id', 'entry_namespace_pid', 'source_sha', 'entry_sha256',
        'cli_sha256', 'source_files', 'binding_sha256', 'native_created_sha256'])
        && $migration['schema'] === 1 && $migration['scope'] === 'MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
        && $migration['status'] === 'VERIFIED_MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
        && $migration['termination'] === 'NORMAL_RETURN' && $migration['entry_return_code'] === 0
        && $migration['native_exit'] === null && $migration['native_exit_observation'] === 'HOST_NATIVE_REQUIRED'
        && $migration['private_cleanup_verified'] === true && $migration['private_cleanup_state'] === 'OWN_FILE_REMOVED'
        && $migration['original_table_count'] === 166 && $migration['independent_new_metadata_verified'] === true
        && $migration['actual_command_counts'] === ['check' => 2, 'apply-structure' => 2]
        && $migration['history_mapping'] === 'NOT RUN' && $migration['payment_producer'] === 'NOT IMPLEMENTED'
        && $migration['business_acceptance'] === 'NOT RUN'
        && $actual['run_id'] === $context['run_id'] && $actual['source_sha'] === $context['source_sha']
        && $actual['container_id'] === $driver['id'] && is_int($actual['entry_namespace_pid']) && $actual['entry_namespace_pid'] > 0
        && $actual['entry_sha256'] === $migrationBinding['entry_sha256'] && $actual['cli_sha256'] === $migrationBinding['cli_sha256']
        && $actual['source_files'] === 10 && $actual['binding_sha256'] === $before['ms5-payment-migration-source-binding.json']
        && $actual['native_created_sha256'] === $before['ms5-payment-migration-container-created.json'],
        'CUSTOMER_REAL_MS5_NORMAL_RETURN_AND_HOST_NATIVE_ZERO');
    m4fm_expect(is_array($migration['children']) && array_is_list($migration['children']) && count($migration['children']) === 4,
        'CUSTOMER_EXACT_FOUR_MS5_CLI_CHILDREN'); $pids = []; $steps = ['marketplace_payment_registry', 'marketplace_payment_attempt'];
    foreach (['check', 'apply-structure', 'check', 'apply-structure'] as $index => $mode) {
        $child = $migration['children'][$index]; m4ae_ms5_child($child); $result = $child['result'];
        m4fm_expect($child['mode'] === $mode && !isset($pids[$child['pid']]) && is_array($result)
            && m4ae_ms5_keys($result, ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known',
                'issues', 'failure_phase', 'marker', 'source_sha256', 'history_mapping', 'payment_producer', 'acceptance'])
            && $result['mode'] === $mode && $result['native_exit'] === 0 && $result['state'] === ($index === 0 ? 'applicable' : 'completed')
            && $result['changed'] === ($index === 1) && $result['pending_steps_known'] === true && $result['issues'] === []
            && $result['failure_phase'] === null && $result['marker'] === 'marketplace-ms5-payment-structure-v1'
            && $result['source_sha256'] === $migrationBinding['cli_sha256'] && $result['history_mapping'] === 'NOT RUN'
            && $result['payment_producer'] === 'NOT IMPLEMENTED' && $result['acceptance'] === 'NOT RUN'
            && $result['pending_steps'] === ($index === 0 ? $steps : []) && $result['applied_steps'] === ($index === 1 ? $steps : []),
            'CUSTOMER_FOUR_EXACT_REAL_MS5_RESULTS'); $pids[$child['pid']] = true;
    }
    m4fm_expect(is_array($binding['initialization_predecessor_sha256'])
        &&m4fm_keys($binding['initialization_predecessor_sha256'],$names),'EARLY_EXACT_EIGHT_PREDECESSOR_HASHES');
    foreach($before as$name=>$digest)m4fm_expect(is_string($binding['initialization_predecessor_sha256'][$name])
        &&$binding['initialization_predecessor_sha256'][$name]===$digest
        &&hash_file('sha256',$base.$name)===$digest,'EARLY_ACTUAL_EIGHT_PREDECESSORS_UNCHANGED');
    return $before;
}

function m4fm_private_create(array &$private, string $bytes): void
{
    $path = '/tmp/ms4-fulfillment-migration-config-' . getenv('AUDIT_RUN_ID') . '.json';
    clearstatcache(true, $path); m4fm_expect(!file_exists($path) && !is_link($path), 'PRIVATE_CREATE_ONLY');
    $handle = @fopen($path, 'x+b'); m4fm_expect(is_resource($handle), 'PRIVATE_CREATE');
    $private['created'] = true; $private['path'] = $path; $private['handle'] = $handle;
    $stat = @fstat($handle); m4fm_expect(is_array($stat), 'PRIVATE_OPEN_STAT');
    $private['identity'] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'gid' => $stat['gid']];
    m4fm_private_assert($private);
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = @fwrite($handle, substr($bytes, $offset));
        m4fm_expect(is_int($written) && $written > 0, 'PRIVATE_WRITE'); $offset += $written;
    }
    m4fm_expect(@fflush($handle), 'PRIVATE_FLUSH');
    $private['complete'] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    m4fm_private_assert($private);
}
function m4fm_private_assert(array $private): void
{
    m4fm_expect(is_resource($private['handle']) && is_array($private['identity']), 'PRIVATE_HANDLE_OWNERSHIP');
    clearstatcache(true, $private['path']); $opened = @fstat($private['handle']); $named = @lstat($private['path']);
    m4fm_expect(is_array($opened) && is_array($named) && !is_link($private['path']), 'PRIVATE_NAMED_OWNERSHIP');
    foreach ([$opened, $named] as $stat) {
        foreach ($private['identity'] as $key => $value) m4fm_expect($stat[$key] === $value, 'PRIVATE_SAME_IDENTITY');
        m4fm_expect(($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 0777) === 0600
            && $stat['uid'] === posix_geteuid() && $stat['nlink'] === 1, 'PRIVATE_FILE_MODE_OWNER');
    }
    if ($private['complete'] !== null) {
        m4fm_expect($opened['size'] === $private['complete']['bytes'] && $named['size'] === $private['complete']['bytes']
            && @rewind($private['handle']), 'PRIVATE_COMPLETE_BYTES');
        $hash = hash_init('sha256'); $read = @hash_update_stream($hash, $private['handle']);
        m4fm_expect($read === $private['complete']['bytes'] && hash_final($hash) === $private['complete']['sha256'], 'PRIVATE_COMPLETE_HASH');
        clearstatcache(true, $private['path']); $after = @lstat($private['path']); $handleAfter = @fstat($private['handle']);
        m4fm_expect(is_array($after) && is_array($handleAfter) && !is_link($private['path']), 'PRIVATE_STABLE_IDENTITY');
        foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'size', 'nlink'] as $key) m4fm_expect($after[$key] === $named[$key] && $handleAfter[$key] === $opened[$key], 'PRIVATE_STABLE_IDENTITY');
    }
}
function m4fm_private_cleanup(array &$private, array &$evidence): void
{
    if ($private['cleanup_attempted']) return;
    $private['cleanup_attempted'] = true;
    if (!$private['created']) { $evidence['private_cleanup_state'] = 'NOT_CREATED'; return; }
    try {
        m4fm_private_assert($private);
        m4fm_expect(@unlink($private['path']), 'PRIVATE_OWN_UNLINK');
        clearstatcache(true, $private['path']); m4fm_expect(!file_exists($private['path']) && !is_link($private['path']), 'PRIVATE_ABSENT');
        m4fm_expect(@fclose($private['handle']), 'PRIVATE_HANDLE_CLOSE'); $private['handle'] = null;
        $evidence['private_cleanup_verified'] = true; $evidence['private_cleanup_state'] = 'OWN_FILE_REMOVED';
    } catch (Throwable $error) {
        $evidence['status'] = 'FAIL'; $evidence['private_cleanup_verified'] = false; $evidence['private_cleanup_state'] = 'FAILED';
        $evidence['cleanup_failure'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
        if (is_resource($private['handle'])) { @fclose($private['handle']); $private['handle'] = null; }
    }
}

function m4fm_metadata(PDO $db, string $table): array
{
    $args = [$table];
    $tables = m4fm_rows($db, 'SELECT TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $args);
    m4fm_expect(count($tables) === 1 && $tables[0]['TABLE_TYPE'] === 'BASE TABLE', 'COMPLETE_BASE_TABLE_METADATA');
    $columns = [];
    foreach (m4fm_rows($db, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $row) $columns[$row['COLUMN_NAME']] = $row;
    $indexes = [];
    foreach (m4fm_rows($db, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $row) $indexes[$row['INDEX_NAME']][] = $row;
    ksort($indexes, SORT_STRING);
    return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
        'constraints' => m4fm_rows($db, 'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY BINARY CONSTRAINT_NAME', $args),
        'checks' => m4fm_rows($db, "SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", $args),
        'foreign' => m4fm_rows($db, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.POSITION_IN_UNIQUE_CONSTRAINT,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UNIQUE_CONSTRAINT_SCHEMA,r.UNIQUE_CONSTRAINT_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', $args),
        'triggers' => m4fm_rows($db, 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
}
function m4fm_projection(PDO $db, string $table, array $columns): array
{
    m4fm_expect($columns !== [], 'COMPLETE_COLUMN_PROJECTION');
    $statement = $db->query('SELECT ' . implode(',', array_map('m4fm_quote', $columns)) . ' FROM ' . m4fm_quote($table));
    m4fm_expect($statement instanceof PDOStatement, 'ROW_PROJECTION'); $hashes = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) $hashes[] = hash('sha256', serialize($row));
    sort($hashes, SORT_STRING);
    return ['encoding' => 'php-serialize-row-sha256-sorted-multiset-v1', 'rows' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))];
}
function m4fm_observer_modes(PDO $db): array
{
    $rows = m4fm_rows($db, 'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb,@@SESSION.information_schema_stats_expiry AS session_stats_expiry,@@GLOBAL.information_schema_stats_expiry AS global_stats_expiry');
    m4fm_expect(count($rows) === 1 && array_keys($rows[0]) === ['session_mode', 'global_mode', 'session_innodb', 'global_innodb', 'session_stats_expiry', 'global_stats_expiry'], 'COMPLETE_SIX_NATIVE_METADATA_OBSERVER_MODES');
    foreach (['session_stats_expiry', 'global_stats_expiry'] as $key)
        m4fm_expect(is_int($rows[0][$key]) && $rows[0][$key] >= 0 && $rows[0][$key] <= 4294967295, 'NATIVE_METADATA_EXPIRY_RANGE');
    return $rows;
}
function m4fm_configure_observer(PDO $db): array
{
    m4fm_expect(!$db->inTransaction(), 'INDEPENDENT_METADATA_OBSERVER_AUTOCOMMIT');
    $before = m4fm_observer_modes($db);
    m4fm_expect($db->exec('SET SESSION information_schema_stats_expiry=0') === 0, 'ONLY_METADATA_OBSERVER_SESSION_EXPIRY_SET');
    $configured = m4fm_observer_modes($db); $expected = $before;
    $expected[0]['session_stats_expiry'] = 0;
    m4fm_expect($configured === $expected, 'ONLY_SESSION_EXPIRY_CHANGED_FIVE_OTHER_NATIVE_MODES_IDENTICAL');
    return $configured;
}
function m4fm_snapshot(): array
{
    $db = audit_db();
    m4fm_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false), 'SNAPSHOT_NATIVE_PREPARES');
    $observer = m4fm_configure_observer($db);
    $names = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    sort($names, SORT_STRING);
    $snapshot = ['names' => $names, 'tables' => [], 'redis' => [],
        'modes' => m4fm_rows($db, 'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb'),
        'metadata_observer_modes' => $observer];
    foreach ($names as $name) {
        m4fm_quote($name);
        $ddl = $db->query('SHOW CREATE TABLE ' . m4fm_quote($name))->fetch(PDO::FETCH_NUM);
        m4fm_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'ACTUAL_SHOW_CREATE');
        $metadata = m4fm_metadata($db, $name); $columns = array_keys($metadata['columns']);
        $snapshot['tables'][$name] = ['metadata' => $metadata, 'ddl_sha256' => hash('sha256', $ddl[1]),
            'projected_columns' => $columns, 'projection' => m4fm_projection($db, $name, $columns)];
    }
    $raw = audit_snapshot(['tables' => [], 'redis' => ['*']]); $redis = audit_redis();
    foreach ($raw['redis']['*'] as $key => $hash) {
        $expiry = $redis->rawCommand('PEXPIRETIME', $key); $keyHash = hash('sha256', (string) $key);
        m4fm_expect(is_int($expiry) && ($expiry === -1 || $expiry > 0) && !isset($snapshot['redis'][$keyHash]), 'REDIS_ABSOLUTE_EXPIRY');
        $snapshot['redis'][$keyHash] = ['sha256' => $hash, 'expires' => $expiry];
    }
    ksort($snapshot['redis'], SORT_STRING);
    m4fm_expect(m4fm_observer_modes($db) === $observer, 'SIX_METADATA_OBSERVER_MODES_STILL_IDENTICAL');
    return $snapshot;
}
/** Preserve all native metadata bytes and PHP types without placing binary values directly in JSON. */
function m4fm_snapshot_evidence(array $snapshot): array
{
    $digest = hash('sha256', serialize($snapshot));
    foreach ($snapshot['tables'] as &$table) {
        $bytes = serialize($table['metadata']);
        $table['metadata_sha256'] = hash('sha256', $bytes); $table['metadata_serialized_base64'] = base64_encode($bytes);
        unset($table['metadata']);
    }
    unset($table); $snapshot['native_snapshot_sha256'] = $digest; return $snapshot;
}
function m4fm_preserved(array $before, array $after): void
{
    m4fm_expect($before['modes'] === $after['modes'] && $before['redis'] === $after['redis'], 'SQL_MODES_REDIS_UNCHANGED');
    foreach ($before['tables'] as $name => $old) m4fm_expect(isset($after['tables'][$name]) && $old === $after['tables'][$name], 'ALL_ORIGINAL_METADATA_AND_ROWS_UNCHANGED');
}

/** Independent literal contract; no call/import of the CLI under verification. */
function m4fm_expected_tables(): array
{
    $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array =>
        ['type' => $type, 'comment' => '', 'nullable' => $null, 'default' => $default, 'charset' => $charset, 'auto' => $auto];
    return [
        'eb_marketplace_fulfillment_operation' => [
            'columns' => [
                'id' => $c('bigint unsigned', false, null, null, true),
                'contract_version' => $c('tinyint unsigned', false, '1'),
                'root_order_id' => $c('int unsigned'),
                'child_order_id' => $c('int unsigned'),
                'uid' => $c('int unsigned'),
                'mer_id' => $c('int unsigned'),
                'action' => $c('varchar(16)', false, null, 'ascii'),
                'idem_key' => $c('varchar(128)', false, null, 'ascii'),
                'request_digest' => $c('binary(32)'),
                'source_snapshot_digest' => $c('binary(32)'),
                'allocation_digest' => $c('binary(32)'),
                'request_payload' => $c('json'),
                'result_payload' => $c('json', true),
                'state' => $c('tinyint unsigned', false, '0'),
                'ship_child_order_id' => $c('int unsigned', true),
                'target_operation_id' => $c('bigint unsigned', true),
                'target_action' => $c('varchar(16)', true, null, 'ascii'),
                'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                'actor_id' => $c('int unsigned', true),
                'created_at' => $c('int unsigned'),
                'committed_at' => $c('int unsigned', true),
                'shipped_at' => $c('int unsigned', true),
                'receipt_due_at' => $c('int unsigned', true),
                'received_at' => $c('int unsigned', true),
            ],
            'indexes' => [
                'PRIMARY' => [true, ['id']],
                'uq_fop_request' => [true, ['root_order_id', 'action', 'idem_key']],
                'uq_fop_ship_child' => [true, ['ship_child_order_id']],
                'uq_fop_identity' => [true, ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                'idx_fop_root' => [false, ['root_order_id', 'id']],
                'idx_fop_child' => [false, ['child_order_id', 'action', 'id']],
                'idx_fop_target' => [false, ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                'idx_fop_owner' => [false, ['uid', 'root_order_id', 'id']],
                'idx_fop_merchant' => [false, ['mer_id', 'root_order_id', 'id']],
                'idx_fop_due' => [false, ['action', 'state', 'receipt_due_at', 'id']],
            ],
            'checks' => [
                'mm4f_op_ids' => '`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `root_order_id`<>`child_order_id`',
                'mm4f_op_state' => '(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)',
                'mm4f_op_json' => "JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR JSON_TYPE(`result_payload`)='OBJECT')",
                'mm4f_op_action' => "`created_at`>0 AND ((`action`='ship' AND `ship_child_order_id` IS NOT NULL AND `ship_child_order_id`=`child_order_id` AND `target_operation_id` IS NULL AND `target_action` IS NULL AND `shipped_at` IS NOT NULL AND `shipped_at`>0 AND `receipt_due_at` IS NOT NULL AND (`receipt_due_at`=0 OR `receipt_due_at`>=`shipped_at`) AND `received_at` IS NULL) OR (`action`='receive' AND `ship_child_order_id` IS NULL AND `target_operation_id` IS NOT NULL AND `target_operation_id`>0 AND `target_action` IS NOT NULL AND `target_action`='ship' AND `shipped_at` IS NULL AND `receipt_due_at` IS NULL AND `received_at` IS NOT NULL AND `received_at`>0))",
                'mm4f_op_actor' => "(`action`='ship' AND `actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0) OR (`action`='receive' AND ((`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid`) OR (`actor_type`='system' AND `actor_id` IS NULL)))",
            ],
            'foreign' => [
                'mm4f_op_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                'mm4f_op_child_fk' => ['store_order', ['child_order_id'], ['id'], 'PRIMARY'],
                'mm4f_op_user_fk' => ['user', ['uid'], ['uid'], 'PRIMARY'],
                'mm4f_op_mer_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id'], 'PRIMARY'],
                'mm4f_op_target_fk' => ['marketplace_fulfillment_operation', ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
            ],
        ],
        'eb_marketplace_fulfillment_range' => [
            'columns' => [
                'id' => $c('bigint unsigned', false, null, null, true),
                'operation_id' => $c('bigint unsigned'),
                'operation_action' => $c('varchar(16)', false, null, 'ascii'),
                'root_order_id' => $c('int unsigned'),
                'child_order_id' => $c('int unsigned'),
                'uid' => $c('int unsigned'),
                'mer_id' => $c('int unsigned'),
                'source_item_id' => $c('int'),
                'child_item_id' => $c('int'),
                'reserve_id' => $c('bigint unsigned'),
                'source_ship_range_id' => $c('bigint unsigned', true),
                'source_range_action' => $c('varchar(16)', true, null, 'ascii'),
                'product_id' => $c('mediumint'),
                'sku_id' => $c('int'),
                'sku_type' => $c('tinyint unsigned', false, '0'),
                'sku_unique' => $c('char(8)', false, null, 'ascii'),
                'cart_ref' => $c('varchar(50)', false, null, 'ascii'),
                'original_quantity' => $c('int unsigned'),
                'unit_start' => $c('int unsigned'),
                'unit_end' => $c('int unsigned'),
                'quantity' => $c('int unsigned'),
                'unit_price' => $c('decimal(12,2) unsigned'),
                'price_type' => $c('varchar(16)', false, null, 'ascii'),
                'goods' => $c('decimal(12,2) unsigned'),
                'postage' => $c('decimal(12,2) unsigned'),
                'origin_postage' => $c('decimal(12,2) unsigned'),
                'payable' => $c('decimal(12,2) unsigned'),
                'cost' => $c('decimal(12,2) unsigned'),
                'base_goods' => $c('decimal(22,2) unsigned'),
                'discount' => $c('decimal(22,2) unsigned'),
            ],
            'indexes' => [
                'PRIMARY' => [true, ['id']],
                'uq_frange_operation_start' => [true, ['operation_id', 'source_item_id', 'unit_start']],
                'uq_frange_identity' => [true, ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
                'idx_frange_operation' => [false, ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                'idx_frange_occupied' => [false, ['root_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                'idx_frange_child' => [false, ['child_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                'idx_frange_source_item' => [false, ['source_item_id', 'id']],
                'idx_frange_child_item' => [false, ['child_item_id', 'id']],
                'idx_frange_reserve' => [false, ['reserve_id', 'id']],
                'idx_frange_ship_parent' => [false, ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
            ],
            'checks' => [
                'mm4f_range_ids' => '`root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `source_item_id`>0 AND `child_item_id`>0 AND `reserve_id`>0 AND `product_id`>0 AND `sku_id`>0 AND `sku_type`=0',
                'mm4f_range_units' => '`original_quantity` BETWEEN 1 AND 2147483647 AND `unit_start`<`unit_end` AND `unit_end`<=`original_quantity` AND `quantity`=`unit_end`-`unit_start`',
                'mm4f_range_action' => "(`operation_action`='ship' AND `source_ship_range_id` IS NULL AND `source_range_action` IS NULL) OR (`operation_action`='receive' AND `source_ship_range_id` IS NOT NULL AND `source_ship_range_id`>0 AND `source_range_action` IS NOT NULL AND `source_range_action`='ship')",
                'mm4f_range_money' => "`price_type` IN ('level','member') AND `payable`=`goods`+`postage` AND `origin_postage`=`postage`",
            ],
            'foreign' => [
                'mm4f_range_operation_fk' => ['marketplace_fulfillment_operation', ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
                'mm4f_range_source_fk' => ['store_order_cart_info', ['source_item_id'], ['id'], 'PRIMARY'],
                'mm4f_range_child_fk' => ['store_order_cart_info', ['child_item_id'], ['id'], 'PRIMARY'],
                'mm4f_range_reserve_fk' => ['marketplace_stock_movement', ['reserve_id'], ['id'], 'PRIMARY'],
                'mm4f_range_parent_fk' => ['marketplace_fulfillment_range', ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], 'uq_frange_identity'],
            ],
        ],
    ];
}

function m4fm_check_metadata_expression(string $expression): ?array
{
    foreach (['OBJECT', 'ship', 'receive', 'employee', 'customer', 'system', 'level', 'member'] as $literal) {
        $expression = str_replace("_utf8mb4\\'" . $literal . "\\'", "_utf8mb4'" . $literal . "'", $expression);
    }
    return m4fm_check_expression($expression);
}

/** Finite MS1 grammar extended for the exact R3 checks, never evaluated as SQL/PHP. */
function m4fm_check_expression(string $expression): ?array
{
    if (strlen($expression) > 16384) return null;
    $tokens = [];
    $offset = 0;
    while ($offset < strlen($expression)) {
        if (!preg_match("/\\G(?:\\s+|`([A-Za-z0-9_]+)`|('(?:[^'\\\\]|'')*')|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+)|(<>|<=|>=|[(),+\\-=<>]))/A", $expression, $match, 0, $offset)) return null;
        $offset += strlen($match[0]);
        if (trim($match[0]) === '') continue;
        if (($match[1] ?? '') !== '') $tokens[] = ['id', strtolower($match[1])];
        elseif (($match[2] ?? '') !== '') $tokens[] = ['string', str_replace("''", "'", substr($match[2], 1, -1))];
        elseif (($match[3] ?? '') !== '') $tokens[] = ['word', strtolower($match[3])];
        elseif (($match[4] ?? '') !== '') $tokens[] = ['number', $match[4]];
        else $tokens[] = ['symbol', $match[5]];
        if (count($tokens) > 4096) return null;
    }
    $position = 0; $depth = 0;
    $peek = static function (string $value) use (&$tokens, &$position): bool {
        return isset($tokens[$position]) && $tokens[$position][1] === $value && in_array($tokens[$position][0], ['word', 'symbol'], true);
    };
    $take = static function (string $value) use (&$position, $peek): bool {
        if (!$peek($value)) return false;
        ++$position; return true;
    };
    $or = $and = $comparison = $addition = $primary = null;
    $primary = static function () use (&$tokens, &$position, &$depth, &$or, $take): array {
        if (++$depth > 128) throw new RuntimeException('Check grammar');
        try {
            if ($take('(')) {
                $node = $or();
                if (!$take(')')) throw new RuntimeException('Check grammar');
                return $node;
            }
            $token = $tokens[$position++] ?? null;
            if ($token === null) throw new RuntimeException('Check grammar');
            if ($token === ['word', '_utf8mb4']) {
                $token = $tokens[$position++] ?? null;
                if (($token[0] ?? '') !== 'string') throw new RuntimeException('Check grammar');
            }
            if ($token[0] === 'string') {
                if (!in_array($token[1], ['OBJECT', 'ship', 'receive', 'employee', 'customer', 'system', 'level', 'member'], true)) throw new RuntimeException('Check grammar');
                return $token;
            }
            if ($token[0] === 'number') {
                if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $token[1]) !== 1) throw new RuntimeException('Check grammar');
                return $token;
            }
            if ($token[0] !== 'id' && $token[0] !== 'word') throw new RuntimeException('Check grammar');
            if ($take('(')) {
                if ($token[1] !== 'json_type') throw new RuntimeException('Check grammar');
                $argument = $or();
                if (!$take(')')) throw new RuntimeException('Check grammar');
                return ['function', 'json_type', $argument];
            }
            return ['id', $token[1]];
        } finally { --$depth; }
    };
    $addition = static function () use (&$primary, $peek, $take): array {
        $node = $primary();
        while ($peek('+') || $peek('-')) {
            $operator = $take('+') ? '+' : '-';
            if ($operator === '-' && !$take('-')) throw new RuntimeException('Check grammar');
            $node = [$operator, $node, $primary()];
        }
        return $node;
    };
    $comparison = static function () use (&$addition, $take): array {
        $node = $addition();
        foreach (['=', '<>', '<=', '>=', '<', '>'] as $operator) if ($take($operator)) return [$operator, $node, $addition()];
        if ($take('is')) {
            $negative = $take('not');
            if (!$take('null')) throw new RuntimeException('Check grammar');
            return [$negative ? 'is_not_null' : 'is_null', $node];
        }
        if ($take('between')) {
            $lower = $addition();
            if (!$take('and')) throw new RuntimeException('Check grammar');
            return ['between', $node, $lower, $addition()];
        }
        if ($take('in')) {
            if (!$take('(')) throw new RuntimeException('Check grammar');
            $values = [$addition()];
            while ($take(',')) $values[] = $addition();
            if (!$take(')')) throw new RuntimeException('Check grammar');
            return ['in', $node, $values];
        }
        return $node;
    };
    $flatten = static function (string $operator, array $nodes): array {
        if (count($nodes) === 1) return $nodes[0];
        $flat = [];
        foreach ($nodes as $node) foreach ($node[0] === $operator ? $node[1] : [$node] as $part) $flat[] = $part;
        return [$operator, $flat];
    };
    $and = static function () use (&$comparison, $take, $flatten): array {
        $nodes = [$comparison()];
        while ($take('and')) $nodes[] = $comparison();
        return $flatten('and', $nodes);
    };
    $or = static function () use (&$and, $take, $flatten): array {
        $nodes = [$and()];
        while ($take('or')) $nodes[] = $and();
        return $flatten('or', $nodes);
    };
    try {
        $tree = $or();
        return $position === count($tokens) ? $tree : null;
    } catch (Throwable $error) { return null; }
}

function m4fm_new_tables(array $snapshot): void
{
    $database = audit_db()->query('SELECT DATABASE()')->fetchColumn();
    m4fm_expect($database === 'audit_auth', 'INDEPENDENT_CURRENT_SCHEMA');
    foreach (m4fm_expected_tables() as $name => $expected) {
        m4fm_expect(isset($snapshot['tables'][$name]), 'NEW_TABLE_PRESENT'); $table = $snapshot['tables'][$name]; $meta = $table['metadata'];
        m4fm_expect($table['projection']['rows'] === 0 && $meta['table']['ENGINE'] === 'InnoDB'
            && $meta['table']['TABLE_COLLATION'] === 'utf8mb4_bin' && $meta['table']['TABLE_COMMENT'] === M4FM_MARKER
            && array_keys($meta['columns']) === array_keys($expected['columns']) && $meta['triggers'] === [], 'EXACT_EMPTY_NEW_TABLE');
        foreach ($expected['columns'] as $column => $want) {
            $actual = $meta['columns'][$column];
            $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($actual['COLUMN_TYPE']));
            $default = $actual['COLUMN_DEFAULT'] === null ? null : (string) $actual['COLUMN_DEFAULT'];
            m4fm_expect($type === $want['type'] && $actual['IS_NULLABLE'] === ($want['nullable'] ? 'YES' : 'NO') && $default === $want['default']
                && $actual['CHARACTER_SET_NAME'] === $want['charset'] && $actual['COLLATION_NAME'] === ($want['charset'] === 'ascii' ? 'ascii_bin' : null)
                && $actual['EXTRA'] === ($want['auto'] ? 'auto_increment' : '') && (string) $actual['GENERATION_EXPRESSION'] === ''
                && $actual['COLUMN_COMMENT'] === $want['comment'], 'EXACT_NEW_COLUMN');
        }
        $indexes = $expected['indexes']; ksort($indexes, SORT_STRING);
        m4fm_expect(array_keys($meta['indexes']) === array_keys($indexes), 'EXACT_NEW_INDEX_NAMES'); $constraints = [];
        foreach ($indexes as $index => [$unique, $columns]) {
            $actual = $meta['indexes'][$index]; m4fm_expect(count($actual) === count($columns), 'EXACT_NEW_INDEX_WIDTH');
            foreach ($actual as $i => $row) m4fm_expect((string) $row['NON_UNIQUE'] === ($unique ? '0' : '1') && (int) $row['SEQ_IN_INDEX'] === $i + 1
                && $row['COLUMN_NAME'] === $columns[$i] && $row['SUB_PART'] === null && $row['INDEX_TYPE'] === 'BTREE'
                && $row['IS_VISIBLE'] === 'YES' && $row['EXPRESSION'] === null && $row['COLLATION'] === 'A' && $row['INDEX_COMMENT'] === '', 'EXACT_NEW_INDEX');
            if ($unique) $constraints[$index] = ['CONSTRAINT_NAME' => $index, 'CONSTRAINT_TYPE' => $index === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE', 'ENFORCED' => 'YES'];
        }
        $foreign = [];
        foreach ($meta['foreign'] as $row) $foreign[$row['CONSTRAINT_NAME']][] = $row;
        $wanted = [];
        foreach ($expected['foreign'] as $key => $contract) $wanted['eb_' . $key] = $contract;
        ksort($foreign, SORT_STRING); ksort($wanted, SORT_STRING);
        m4fm_expect(array_keys($foreign) === array_keys($wanted), 'EXACT_NEW_FOREIGN_NAMES');
        foreach ($wanted as $key => [$target, $columns, $references, $unique]) {
            m4fm_expect(count($foreign[$key]) === count($columns), 'EXACT_NEW_FOREIGN_WIDTH');
            foreach ($foreign[$key] as $i => $row) {
                m4fm_expect((string) $row['ORDINAL_POSITION'] === (string) ($i + 1)
                    && (string) $row['POSITION_IN_UNIQUE_CONSTRAINT'] === (string) ($i + 1)
                    && $row['COLUMN_NAME'] === $columns[$i] && $row['REFERENCED_COLUMN_NAME'] === $references[$i]
                    && $row['REFERENCED_TABLE_SCHEMA'] === $database && $row['REFERENCED_TABLE_NAME'] === 'eb_' . $target
                    && $row['UNIQUE_CONSTRAINT_SCHEMA'] === $database && $row['UNIQUE_CONSTRAINT_NAME'] === $unique
                    && $row['UPDATE_RULE'] === 'RESTRICT' && $row['DELETE_RULE'] === 'RESTRICT' && $row['MATCH_OPTION'] === 'NONE', 'EXACT_NEW_FOREIGN');
            }
            $constraints[$key] = ['CONSTRAINT_NAME' => $key, 'CONSTRAINT_TYPE' => 'FOREIGN KEY', 'ENFORCED' => 'YES'];
        }
        $checks = [];
        foreach ($meta['checks'] as $row) {
            $key = $row['CONSTRAINT_NAME']; $tree = m4fm_check_metadata_expression($row['CHECK_CLAUSE']);
            m4fm_expect(!isset($checks[$key]) && $row['ENFORCED'] === 'YES' && $tree !== null, 'REAL_ENFORCED_NEW_CHECK');
            $checks[$key] = $tree;
        }
        $wanted = [];
        foreach ($expected['checks'] as $key => $expression) {
            $tree = m4fm_check_expression($expression); m4fm_expect($tree !== null, 'DECLARED_CHECK_SPEC');
            $wanted['eb_' . $key] = $tree;
            $constraints['eb_' . $key] = ['CONSTRAINT_NAME' => 'eb_' . $key, 'CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES'];
        }
        ksort($checks, SORT_STRING); ksort($wanted, SORT_STRING);
        m4fm_expect($checks === $wanted, 'EXACT_NEW_CHECKS');
        ksort($constraints, SORT_STRING); m4fm_expect($meta['constraints'] === array_values($constraints), 'EXACT_NEW_CONSTRAINTS');
    }
}

function m4ae_scripts():array
{
    return ['aftersale'=>'help/migrations/marketplace-ms4-aftersale.php',
        'aftersale_menus'=>'help/migrations/marketplace-ms4-aftersale-menus.php',
        'review_menus'=>'help/migrations/marketplace-ms4-review-menus.php',
        'assets'=>'help/migrations/marketplace-ms4-assets.php'];
}
function m4ae_initial_database(array $before,array $binding):void
{
    $initializer=m4ae_public_read('/run/audit/evidence/initializer-result.json');
    $ms5=m4ae_public_read('/run/audit/evidence/ms5-payment-migration-normal.json');$prior=$ms5['snapshots']['after'];
    m4fm_expect(is_array($prior)&&$before['names']===$prior['names']
        &&count($before['names'])===$ms5['original_table_count']+2
        &&$before['modes']===$prior['modes']&&$before['redis']===$prior['redis'],'EARLY_REAL_MS5_NATIVE_BASELINE');
    foreach($before['tables']as$name=>$table){
        m4fm_expect(isset($prior['tables'][$name])&&$table['projected_columns']===$prior['tables'][$name]['projected_columns']
            &&$table['projection']===$prior['tables'][$name]['projection']
            &&$table['ddl_sha256']===$prior['tables'][$name]['ddl_sha256'],'EARLY_MS5_ORIGINAL_DDL_AND_NATIVE_ROW_MULTISET');
    }
    foreach(['eb_user','eb_store_order','eb_store_order_cart_info','eb_store_order_refund','eb_store_product','eb_store_product_attr',
        'eb_store_product_attr_value','eb_store_cart','eb_marketplace_order_idempotency','eb_marketplace_stock_movement',
        'eb_marketplace_payment_registry','eb_marketplace_payment_attempt','eb_system_file_md5']as$name)
        m4fm_expect(isset($before['tables'][$name])&&$before['tables'][$name]['projection']['rows']===0,
            'EARLY_BEFORE_FILES_APP_AND_BUSINESS_REAL_EMPTY');
    $self=$initializer['actual_self_mer_id'];$rows=m4fm_rows(audit_db(),'SELECT mer_id FROM eb_marketplace_merchant WHERE mer_id=?',[$self]);
    m4fm_expect(is_int($self)&&$self>0&&$self<=4294967295&&count($rows)===1&&(string)$rows[0]['mer_id']===(string)$self,
        'EARLY_ACTUAL_INITIALIZED_SELF_NATIVE_ID');
    foreach($binding['initialization_predecessor_sha256']as$name=>$hash)
        m4fm_expect(hash_file('sha256','/run/audit/evidence/'.$name)===$hash,'EARLY_PREDECESSOR_BYTES_STAY_ORIGINAL');
}
function m4ae_aftersale_steps():array
{
    return ['create.marketplace_aftersale_action','refund.column.marketplace_root_order_id','refund.column.marketplace_payment_attempt_id',
        'refund.column.marketplace_kind','refund.column.marketplace_state','refund.column.marketplace_physical_state',
        'refund.column.marketplace_finance_state','refund.column.marketplace_apply_action_id','refund.column.marketplace_last_action_id',
        'refund.index.idx_ms4a_refund_root','refund.index.uq_ms4a_refund_apply','refund.index.idx_ms4a_refund_last',
        'refund.foreign.mm4a_refund_apply_fk','refund.foreign.mm4a_refund_last_fk','refund.check.mm4a_refund_v1',
        'operation.column.aftersale_cut_id','operation.index.idx_fop_aftersale_cut','operation.foreign.mm4a_fop_cut_fk',
        'operation.checks.replace_known_ids_and_add_cut','create.marketplace_aftersale_file'];
}
/** Only the two observed operation CHECK rows admit the fixed escaped charset presentation. */
function m4ae_preserved_check(string $table,array $row,array $actualChecks):bool
{
    if($table!=='eb_marketplace_fulfillment_operation'
        ||!in_array($row['CONSTRAINT_NAME']??null,['eb_mm4f_op_action','eb_mm4f_op_actor'],true))
        return in_array($row,$actualChecks,true);
    $keys=['CONSTRAINT_NAME','ENFORCED','CHECK_CLAUSE'];
    if(array_keys($row)!==$keys)return false;
    foreach($keys as$key)if(!is_string($row[$key]))return false;
    if($row['ENFORCED']!=='YES')return false;
    $matches=[];
    foreach($actualChecks as$actual)if(is_array($actual)
        &&($actual['CONSTRAINT_NAME']??null)===$row['CONSTRAINT_NAME'])$matches[]=$actual;
    if(count($matches)!==1)return false;
    $actual=$matches[0];
    if(array_keys($actual)!==$keys)return false;
    foreach($keys as$key)if(!is_string($actual[$key]))return false;
    if($actual['CONSTRAINT_NAME']!==$row['CONSTRAINT_NAME']||$actual['ENFORCED']!==$row['ENFORCED'])return false;
    foreach(['ship','receive','employee','customer','system']as$literal){
        $from="_utf8mb4\\'".$literal."\\'";$to="_ascii\\'".$literal."\\'";
        $row['CHECK_CLAUSE']=str_replace($from,$to,$row['CHECK_CLAUSE']);
        $actual['CHECK_CLAUSE']=str_replace($from,$to,$actual['CHECK_CLAUSE']);
    }
    return $row===$actual;
}

function m4ae_structure_delta(array $before,array $after):void
{
    m4fm_expect($before['modes']===$after['modes']&&$before['metadata_observer_modes']===$after['metadata_observer_modes']
        &&$before['redis']===$after['redis'],'EARLY_ALL_SIX_MODES_AND_ABSOLUTE_REDIS_UNCHANGED');
    $new=['eb_marketplace_aftersale_action','eb_marketplace_aftersale_file'];$names=$before['names'];
    foreach($new as$name)m4fm_expect(!in_array($name,$names,true),'EARLY_FRESH_AFTERSALE_TARGET');
    $names=array_merge($names,$new);sort($names,SORT_STRING);
    m4fm_expect($after['names']===$names,'EARLY_ONLY_TWO_AFTERSALE_NEW_TABLES');
    foreach($before['tables']as$name=>$old){
        m4fm_expect(isset($after['tables'][$name]),'EARLY_NO_ORIGINAL_TABLE_REMOVED');
        if(!in_array($name,['eb_store_order_refund','eb_marketplace_fulfillment_operation'],true)){
            m4fm_expect($after['tables'][$name]===$old,'EARLY_ALL_NON_TARGET_FULL_METADATA_DDL_NATIVE_ROWS');continue;
        }
        $actual=$after['tables'][$name];$oldColumns=$old['projected_columns'];
        m4fm_expect($actual['metadata']['table']===$old['metadata']['table']&&$actual['metadata']['triggers']===$old['metadata']['triggers']
            &&array_slice($actual['metadata']['columns'],0,count($oldColumns),true)===$old['metadata']['columns']
            &&m4fm_projection(audit_db(),$name,$oldColumns)===$old['projection'],'EARLY_ALL_ORIGINAL_COLUMNS_METADATA_AND_TYPED_ROWS_PRESERVED');
        foreach($old['metadata']['indexes']as$key=>$value)m4fm_expect(($actual['metadata']['indexes'][$key]??null)===$value,
            'EARLY_ALL_ORIGINAL_INDEX_METADATA_PRESERVED');
        foreach($old['metadata']['foreign']as$row)m4fm_expect(in_array($row,$actual['metadata']['foreign'],true),'EARLY_ALL_ORIGINAL_FOREIGN_NATIVE_ROWS');
        foreach($old['metadata']['checks']as$row)if($name!=='eb_marketplace_fulfillment_operation'||$row['CONSTRAINT_NAME']!=='eb_mm4f_op_ids')
            m4fm_expect(m4ae_preserved_check($name,$row,$actual['metadata']['checks']),'EARLY_ONLY_KNOWN_IDS_CHECK_REPLACED');
        foreach($old['metadata']['constraints']as$row)m4fm_expect(in_array($row,$actual['metadata']['constraints'],true),
            'EARLY_ALL_ORIGINAL_CONSTRAINT_IDENTITIES_PRESERVED');
        $added=array_values(array_diff($actual['projected_columns'],$oldColumns));
        $expected=$name==='eb_store_order_refund'?['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind',
            'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id']:['aftersale_cut_id'];
        m4fm_expect($added===$expected,'EARLY_EXACT_ADDED_NULLABLE_COLUMNS');
        $predicate=implode(' OR ',array_map(static fn(string $column):string=>m4fm_quote($column).' IS NOT NULL',$added));
        m4fm_expect(m4fm_rows(audit_db(),'SELECT COUNT(*) AS n FROM '.m4fm_quote($name).' WHERE '.$predicate)[0]['n']===0,
            'EARLY_ALL_ORIGINAL_ROWS_NEW_SHADOW_COLUMNS_REAL_NULL');
    }
    m4ae_assert_stage20($after);
}
function m4ae_result(string $kind,string $mode,string $sha,array $result,array $binding):void
{
    $sources=[];foreach(m4fm_source_paths()as$path)$sources[$path]=hash_file('sha256','/workspace/'.$path);
    if($kind==='assets'){m4as_result($mode,$result,$sources);return;}
    if($kind==='aftersale'){
        $prerequisites=[];foreach(['help/migrations/marketplace-ms1.php','help/migrations/marketplace-ms4.php',
            'help/migrations/marketplace-ms5-payment.php','help/migrations/marketplace-ms4-fulfillment.php','crmeb/public/install/crmeb.sql']as$path)
            $prerequisites[$path]=$sources[$path];
        m4fm_expect(m4fm_keys($result,['mode','state','native_exit','changed','applied_steps','pending_steps','pending_steps_known',
            'structure_stage','structure_step_count','issues','failure_phase','marker','source_sha256','scope','prerequisite_source_sha256','writer','acceptance'])
            &&$result['mode']===$mode&&$result['native_exit']===0&&is_bool($result['changed'])
            &&$result['pending_steps_known']===true&&is_int($result['structure_stage'])&&$result['structure_stage']>=0&&$result['structure_stage']<=20
            &&$result['structure_step_count']===20&&$result['issues']===[]&&$result['failure_phase']===null
            &&$result['marker']==='marketplace-ms4-aftersale-v1'&&$result['source_sha256']===$sha
            &&$result['scope']==='MS4_AFTERSALE_STRUCTURE_ONLY'&&$result['prerequisite_source_sha256']===$prerequisites
            &&$result['writer']==='NOT RUN'&&$result['acceptance']==='NOT RUN','EARLY_EXACT_SEVENTEEN_AFTERSALE_CLI_KEYS');
    }else{
        $keys=['mode','state','changed','tables','menu_ids','issues','source_binding','authorization_cache_producer','roles_modified'];
        if($mode==='apply')$keys[]='created_menu_ids';
        m4fm_expect(m4fm_keys($result,$keys)&&$result['mode']===$mode&&is_bool($result['changed'])&&$result['tables']===[]
            &&is_array($result['menu_ids'])&&$result['issues']===[]
            &&$result['source_binding']===['entry_sha256'=>$sha,'ms1_sha256'=>$sources['help/migrations/marketplace-ms1.php']]
            &&$result['authorization_cache_producer']==='fresh_scope_for_request_exact_all_auth_and_current_roles'
            &&$result['roles_modified']===false,'EARLY_EXACT_MENU_NORMAL_RESULT_SOURCE_NO_GRANT');
        if($mode==='apply')m4fm_expect(is_array($result['created_menu_ids']),'EARLY_MENU_ACTUAL_CREATED_IDS');
    }
}
function m4ae_counts(array $evidence):array
{
    $out=['fulfillment'=>array_count_values(array_column($evidence['children'],'mode'))];
    foreach(m4ae_scripts()as$kind=>$script){$modes=[];foreach($evidence['successor_children']as$child)if($child['script']===$script)$modes[]=$child['mode'];
        $out[$kind]=array_count_values($modes);}
    return $out;
}

/** Finite independent menu literals. No inclusion or call of the migration being observed. */
function m4ae_menu_rows(string $kind): array
{
    m4fm_expect(in_array($kind, ['aftersale_menus', 'review_menus'], true), 'MENU_ORACLE_KIND');
    $common = ['pid'=>0,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
        'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
        'is_header'=>0,'is_del'=>0,'mark'=>$kind === 'aftersale_menus' ? 'marketplace-ms4-aftersale-menus-v1' : 'marketplace-ms4-review-menus-v1'];
    $leaves = $kind === 'aftersale_menus' ? [
        ['售后退款列表','GET','marketplace/aftersales','marketplace-aftersales-list'],
        ['售后退款详情','GET','marketplace/aftersales/<id>','marketplace-aftersales-read'],
        ['同意售后申请','POST','marketplace/aftersales/<id>/approve','marketplace-aftersales-approve'],
        ['拒绝售后申请','POST','marketplace/aftersales/<id>/reject','marketplace-aftersales-reject'],
        ['确认验收退件','POST','marketplace/aftersales/<id>/return-accept','marketplace-aftersales-return-accept'],
        ['申请售后凭证读票','POST','marketplace/aftersale-files/<file_id>/read-ticket','marketplace-aftersale-files-ticket'],
        ['读取售后私有凭证','GET','marketplace/aftersale-files/<file_id>/content','marketplace-aftersale-files-read'],
    ] : [
        ['订单评价列表','GET','marketplace/reviews','marketplace-reviews-list'],
        ['订单评价详情','GET','marketplace/reviews/<id>','marketplace-reviews-read'],
        ['审核订单评价','PUT','marketplace/reviews/<id>/review','marketplace-reviews-moderate'],
    ];
    return array_map(static fn(array $leaf): array => $common + ['menu_name'=>$leaf[0], 'methods'=>$leaf[1],
        'api_url'=>$leaf[2], 'unique_auth'=>$leaf[3]], $leaves);
}

function m4ae_menu_columns(): array
{
    return ['id','pid','icon','menu_name','module','controller','action','api_url','methods','params','sort','is_show',
        'is_show_path','access','menu_path','path','auth_type','header','is_header','unique_auth','is_del','mark'];
}

/** The original native serialize-row multiset encoding, recomputed from complete ordered SELECT * rows. */
function m4ae_menu_projection(array $rows): array
{
    m4fm_expect(array_is_list($rows), 'MENU_NATIVE_ROW_LIST');
    $columns = m4ae_menu_columns(); $integers = ['id','pid','sort','is_show','is_show_path','access','auth_type','is_header','is_del'];
    $hashes = []; $previous = 0;
    foreach ($rows as $row) {
        m4fm_expect(is_array($row) && array_keys($row) === $columns, 'MENU_COMPLETE_NATIVE_TWENTY_TWO_COLUMNS');
        foreach ($row as $column=>$value) m4fm_expect(in_array($column, $integers, true) ? is_int($value) : is_string($value), 'MENU_NATIVE_COLUMN_TYPES');
        m4fm_expect($row['id'] > $previous && $row['id'] <= 65535, 'MENU_NATIVE_ORDERED_PRIMARY_KEYS');
        $previous = $row['id']; $hashes[] = hash('sha256', serialize($row));
    }
    sort($hashes, SORT_STRING);
    return ['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
}

/** One fixed native read on the supplied original observer connection. No attributes, data or configuration are written. */
function m4ae_menu_native(PDO $db): array
{
    m4fm_expect($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        && $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
        && in_array($db->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false,0], true), 'MENU_NATIVE_READ_CONNECTION');
    $statement = $db->prepare('SELECT * FROM `eb_system_menus` ORDER BY `id` ASC');
    m4fm_expect($statement instanceof PDOStatement && $statement->execute(), 'MENU_NATIVE_FULL_SELECT');
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    m4fm_expect(is_array($rows) && $statement->closeCursor(), 'MENU_NATIVE_FULL_FETCH');
    m4ae_menu_projection($rows); return $rows;
}

/** First successful apply only. Every check/repeat is a strict complete snapshot/native-row equality at the caller. */
function m4ae_menu_delta(array $before, array $after, array $beforeRows, array $afterRows, string $kind, array $receipt): array
{
    $expectedRows = m4ae_menu_rows($kind); $menu = 'eb_system_menus';
    $snapshotKeys = ['names','tables','redis','modes','metadata_observer_modes'];
    m4fm_expect(m4fm_keys($before,$snapshotKeys) && m4fm_keys($after,$snapshotKeys), 'MENU_COMPLETE_FIVE_SNAPSHOT_KEYS');
    m4fm_expect(is_array($before['names']) && array_is_list($before['names']) && $before['names'] === $after['names']
        && is_array($before['tables']) && is_array($after['tables']) && array_keys($before['tables']) === $before['names']
        && array_keys($after['tables']) === $after['names'] && in_array($menu,$before['names'],true), 'MENU_TABLE_NAMES_UNCHANGED');
    m4fm_expect($before['redis'] === $after['redis'] && $before['modes'] === $after['modes']
        && $before['metadata_observer_modes'] === $after['metadata_observer_modes'], 'MENU_REDIS_ABSOLUTE_AND_BOTH_MODE_BLOCKS_UNCHANGED');
    m4fm_expect(is_array($before['redis']) && is_array($before['modes']) && array_is_list($before['modes']) && count($before['modes']) === 1
        && is_array($before['modes'][0]) && m4fm_keys($before['modes'][0],['session_mode','global_mode','session_innodb','global_innodb'])
        && is_array($before['metadata_observer_modes']) && array_is_list($before['metadata_observer_modes']) && count($before['metadata_observer_modes']) === 1
        && is_array($before['metadata_observer_modes'][0]) && m4fm_keys($before['metadata_observer_modes'][0],
            ['session_mode','global_mode','session_innodb','global_innodb','session_stats_expiry','global_stats_expiry']), 'MENU_ACTUAL_MODE_BLOCK_SHAPES');
    foreach ($before['redis'] as $key=>$entry) m4fm_expect(is_string($key) && preg_match('/\A[a-f0-9]{64}\z/D',$key) === 1
        && is_array($entry) && m4fm_keys($entry,['sha256','expires']) && is_string($entry['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256']) === 1
        && is_int($entry['expires']) && ($entry['expires'] === -1 || $entry['expires'] > 0), 'MENU_ACTUAL_ABSOLUTE_REDIS_EXPIRY');
    foreach ($before['tables'] as $name=>$table) if ($name !== $menu) m4fm_expect($table === $after['tables'][$name], 'MENU_ALL_OTHER_TABLES_METADATA_DDL_AND_ROWS_UNCHANGED');
    $old = $before['tables'][$menu]; $new = $after['tables'][$menu]; $tableKeys = ['metadata','ddl_sha256','projected_columns','projection'];
    m4fm_expect(is_array($old) && is_array($new) && m4fm_keys($old,$tableKeys) && m4fm_keys($new,$tableKeys)
        && is_array($old['metadata']) && is_array($new['metadata']) && is_array($old['metadata']['table'] ?? null) && is_array($new['metadata']['table'] ?? null), 'MENU_COMPLETE_NATIVE_TABLE_SNAPSHOTS');
    foreach ([$old,$new] as $table) m4fm_expect(is_string($table['ddl_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D',$table['ddl_sha256']) === 1, 'MENU_ACTUAL_DDL_HASH_RETAINED');
    $oldCounter = $old['metadata']['table']['AUTO_INCREMENT'] ?? null; $newCounter = $new['metadata']['table']['AUTO_INCREMENT'] ?? null;
    m4fm_expect(is_int($oldCounter) && $oldCounter >= 1 && $oldCounter <= 65536 && is_int($newCounter) && $newCounter >= 1 && $newCounter <= 65536, 'MENU_NATIVE_AUTO_INCREMENT_COUNTERS');
    $oldMetadata = $old['metadata']; $newMetadata = $new['metadata'];
    unset($oldMetadata['table']['AUTO_INCREMENT'],$newMetadata['table']['AUTO_INCREMENT']);
    m4fm_expect($oldMetadata === $newMetadata && $old['projected_columns'] === m4ae_menu_columns()
        && $new['projected_columns'] === m4ae_menu_columns() && isset($oldMetadata['columns'])
        && array_keys($oldMetadata['columns']) === m4ae_menu_columns(), 'MENU_ONLY_REAL_AUTO_INCREMENT_METADATA_CHANGE');
    m4fm_expect($old['projection'] === m4ae_menu_projection($beforeRows) && $new['projection'] === m4ae_menu_projection($afterRows), 'MENU_COMPLETE_NATIVE_PROJECTION_CLOSURE');
    $beforeById = []; $afterById = [];
    foreach ($beforeRows as $row) $beforeById[$row['id']] = $row;
    foreach ($afterRows as $row) $afterById[$row['id']] = $row;
    foreach ($beforeById as $id=>$row) m4fm_expect(isset($afterById[$id]) && $afterById[$id] === $row, 'MENU_ALL_ORIGINAL_ROWS_STRICTLY_UNCHANGED');
    $added = array_diff_key($afterById,$beforeById);
    m4fm_expect(count($added) === count($expectedRows) && count($afterRows) === count($beforeRows)+count($expectedRows), 'MENU_EXACT_SEVEN_OR_THREE_NEW_ROWS');
    $ids = array_keys($added);
    m4fm_expect($ids !== [] && min($ids) === $oldCounter && max(array_keys($afterById)) + 1 === $newCounter
        && $newCounter > $oldCounter && $old['ddl_sha256'] !== $new['ddl_sha256'], 'MENU_ACTUAL_NATURAL_PRIMARY_KEYS_AND_NEXT_COUNTER');
    foreach ($ids as $id) m4fm_expect(is_int($id) && $id >= $oldCounter && $id <= 65535, 'MENU_OBSERVED_NATURAL_NEW_PK');
    $map = []; $matched = [];
    foreach ($expectedRows as $expected) {
        m4fm_expect(count($expected) === 21 && !array_key_exists('id',$expected), 'MENU_LITERAL_TWENTY_ONE_WITHOUT_PK');
        foreach ($beforeRows as $row) m4fm_expect($row['unique_auth'] !== $expected['unique_auth'], 'MENU_KIND_HAS_NO_PREEXISTING_LEAF');
        $matches = [];
        foreach ($added as $id=>$row) if ($row['unique_auth'] === $expected['unique_auth']) $matches[$id] = $row;
        m4fm_expect(count($matches) === 1, 'MENU_EXACT_LITERAL_AUTH_LEAF'); $id = array_key_first($matches); $actual = $matches[$id];
        $full = [];
        foreach (m4ae_menu_columns() as $column) $full[$column] = $column === 'id' ? $id : $expected[$column];
        m4fm_expect($actual === $full && !isset($matched[$id]), 'MENU_ALL_TWENTY_TWO_NATIVE_FIELDS_EXACT');
        $map[$expected['unique_auth']] = $id; $matched[$id] = true;
    }
    m4fm_expect(count($matched) === count($added), 'MENU_NO_EXTRA_NEW_ROWS');
    m4fm_expect(m4fm_keys($receipt,['mode','state','changed','tables','menu_ids','issues','source_binding','authorization_cache_producer','roles_modified','created_menu_ids'])
        && $receipt['mode'] === 'apply' && $receipt['state'] === 'completed' && $receipt['changed'] === true && $receipt['tables'] === []
        && $receipt['issues'] === [] && $receipt['roles_modified'] === false
        && $receipt['authorization_cache_producer'] === 'fresh_scope_for_request_exact_all_auth_and_current_roles'
        && $receipt['menu_ids'] === $map && $receipt['created_menu_ids'] === $map, 'MENU_EXACT_NATIVE_FIRST_APPLY_RECEIPT_AND_PK_MAP');
    m4fm_expect(is_array($receipt['source_binding']) && m4fm_keys($receipt['source_binding'],['entry_sha256','ms1_sha256']), 'MENU_ACTUAL_CHILD_SOURCE_BINDING_SHAPE');
    foreach ($receipt['source_binding'] as $hash) m4fm_expect(is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D',$hash) === 1, 'MENU_ACTUAL_CHILD_SOURCE_HASH');
    return $map;
}

/** SOURCE fragment only: fixed stage20 oracle, no PDO/App/CLI import or runtime I/O. */
final class M4aeStage20Oracle
{
    private const MARKER = 'marketplace-ms4-aftersale-v1';
    private const FILE_MARKER = 'marketplace-ms4-aftersale-file-v1';
    private static function column(string $type, string $comment = '', bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    /** CHECK accepts TRUE only: both FALSE and SQL UNKNOWN are rejected. */
    private static function truth(string $predicate): string { return 'COALESCE((' . $predicate . '),0)=1'; }

    private static function newTables(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        $actor = "(`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid` AND `actor_ref`=CONCAT('customer:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0 AND `actor_ref`=CONCAT('employee:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='admin' AND `actor_id` IS NOT NULL AND `actor_id`>0 AND `actor_ref`=CONCAT('admin:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='payment' AND `actor_id` IS NULL AND `actor_ref`=CONCAT('payment:',`payment_attempt_id`) AND `action`='finance_observe')";
        $unbound = '`refund_id` IS NULL AND `image_position` IS NULL AND `claimed_at` IS NULL';
        return [
            'marketplace_aftersale_action' => [
                'table_collation' => 'utf8mb4_unicode_ci', 'table_comment' => self::MARKER,
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', false, '1'),
                    'root_order_id' => $c('int unsigned'), 'uid' => $c('int unsigned'), 'mer_id' => $c('int unsigned'),
                    'refund_id' => $c('int'), 'payment_attempt_id' => $c('bigint unsigned'),
                    'action' => $c('varchar(24)', false, null, 'ascii'), 'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                    'actor_id' => $c('int unsigned', true), 'actor_ref' => $c('varchar(64)', false, null, 'ascii'),
                    'idem_key' => $c('varchar(128)', false, null, 'ascii'), 'request_digest' => $c('binary(32)'),
                    'request_payload' => $c('json'), 'result_payload' => $c('json', true), 'state' => $c('tinyint unsigned', false, '0'),
                    'previous_refund_action_id' => $c('bigint unsigned', true), 'fulfillment_cut_id' => $c('bigint unsigned', true),
                    'apply_refund_id' => $c('int', true), 'created_at' => $c('int unsigned'), 'committed_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']], 'uq_ms4a_actor_key' => [true, ['actor_ref', 'idem_key']],
                    'uq_ms4a_apply_refund' => [true, ['apply_refund_id']], 'uq_ms4a_previous' => [true, ['previous_refund_action_id']],
                    'idx_ms4a_root' => [false, ['root_order_id', 'id']], 'idx_ms4a_refund' => [false, ['refund_id', 'id']],
                    'idx_ms4a_payment' => [false, ['payment_attempt_id']], 'idx_ms4a_fcut' => [false, ['fulfillment_cut_id']],
                ],
                'foreign' => [
                    'mm4a_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4a_refund_fk' => ['store_order_refund', ['refund_id'], ['id'], 'PRIMARY'],
                    'mm4a_payment_fk' => ['marketplace_payment_attempt', ['payment_attempt_id'], ['id'], 'PRIMARY'],
                    'mm4a_previous_fk' => ['marketplace_aftersale_action', ['previous_refund_action_id'], ['id'], 'PRIMARY'],
                    'mm4a_fcut_fk' => ['marketplace_fulfillment_operation', ['fulfillment_cut_id'], ['id'], 'PRIMARY'],
                ],
                'checks' => [
                    'mm4a_ids' => self::truth('`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `refund_id`>0 AND `payment_attempt_id`>0 AND `created_at`>0 AND (`previous_refund_action_id` IS NULL OR (`previous_refund_action_id` IS NOT NULL AND `previous_refund_action_id`>0)) AND (`fulfillment_cut_id` IS NULL OR (`fulfillment_cut_id` IS NOT NULL AND `fulfillment_cut_id`>0))'),
                    'mm4a_actor' => self::truth($actor),
                    'mm4a_action' => self::truth("`action` IN ('apply','withdraw','approve','reject','express','accept_return','finance_observe') AND ((`action`='apply' AND `apply_refund_id` IS NOT NULL AND `apply_refund_id`=`refund_id` AND `previous_refund_action_id` IS NULL) OR (`action`<>'apply' AND `apply_refund_id` IS NULL AND `previous_refund_action_id` IS NOT NULL))"),
                    'mm4a_state' => self::truth('(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)'),
                    'mm4a_json' => self::truth("JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR (JSON_TYPE(`result_payload`)='OBJECT'))"),
                ],
            ],
            'marketplace_aftersale_file' => [
                'table_collation' => 'utf8mb4_unicode_ci', 'table_comment' => self::FILE_MARKER,
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'uid' => $c('int unsigned'), 'root_order_id' => $c('int unsigned'), 'mer_id' => $c('int unsigned'),
                    'refund_id' => $c('int', true), 'image_position' => $c('tinyint unsigned', true),
                    'object_key' => $c('char(64)', false, null, 'ascii'), 'media_type' => $c('varchar(32)', false, null, 'ascii'),
                    'byte_size' => $c('int unsigned'), 'width' => $c('smallint unsigned'), 'height' => $c('smallint unsigned'),
                    'content_sha256' => $c('binary(32)'), 'upload_key' => $c('varchar(128)', false, null, 'ascii'),
                    'state' => $c('varchar(16)', false, null, 'ascii'), 'created_at' => $c('int unsigned'),
                    'claim_expires_at' => $c('int unsigned'), 'claimed_at' => $c('int unsigned', true), 'purged_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']], 'uq_ms4af_object_key' => [true, ['object_key']],
                    'uq_ms4af_upload' => [true, ['uid', 'upload_key']], 'uq_ms4af_refund_position' => [true, ['refund_id', 'image_position']],
                    'idx_ms4af_root' => [false, ['root_order_id', 'id']], 'idx_ms4af_expiry' => [false, ['state', 'claim_expires_at', 'id']],
                ],
                'foreign' => [
                    'mm4af_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4af_refund_fk' => ['store_order_refund', ['refund_id'], ['id'], 'PRIMARY'],
                ],
                'checks' => [
                    'mm4af_values' => self::truth("`uid` BETWEEN 1 AND 2147483647 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `byte_size` BETWEEN 1 AND 5242880 AND `width` BETWEEN 1 AND 4096 AND `height` BETWEEN 1 AND 4096 AND `media_type` IN ('image/jpeg','image/png','image/webp') AND OCTET_LENGTH(`content_sha256`)=32 AND OCTET_LENGTH(`object_key`)=64 AND REGEXP_LIKE(`object_key`,'[^0-9a-f]','c')=0 AND OCTET_LENGTH(`upload_key`) BETWEEN 1 AND 128 AND REGEXP_LIKE(`upload_key`,'^[!-~]([ -~]{0,126}[!-~])?$','c')=1 AND REGEXP_LIKE(`upload_key`,'[^ -~]','c')=0 AND `created_at` BETWEEN 1 AND 4294880895 AND `claim_expires_at`=`created_at`+86400"),
                    'mm4af_state' => self::truth("(`state`='STAGED' AND " . $unbound . " AND `purged_at` IS NULL) OR (`state`='CLAIMED' AND `refund_id` IS NOT NULL AND `refund_id`>0 AND `image_position` IS NOT NULL AND `image_position` BETWEEN 0 AND 8 AND `claimed_at` IS NOT NULL AND `claimed_at`>=`created_at` AND `claimed_at`<=`claim_expires_at` AND `purged_at` IS NULL) OR (`state`='DELETE_PENDING' AND " . $unbound . " AND `purged_at` IS NULL) OR (`state`='DELETED' AND " . $unbound . " AND `purged_at` IS NOT NULL AND `purged_at`>=`created_at`)"),
                ],
            ],
        ];
    }

    private static function refundAdditions(): array
    {
        $c = static fn(string $type, ?string $charset = null): array => self::column($type, '', true, null, $charset);
        $legacy = [];
        foreach (['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind','marketplace_state',
            'marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'] as $name) $legacy[] = self::quote($name) . ' IS NULL';
        $nonnull = [];
        foreach (['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind','marketplace_state',
            'marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id','mer_id','marketplace_refund_mode'] as $name) $nonnull[] = self::quote($name) . ' IS NOT NULL';
        $valid = implode(' AND ', $nonnull) . ' AND `marketplace_root_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `marketplace_payment_attempt_id`>0 AND `marketplace_apply_action_id`>0 AND `marketplace_last_action_id`>=`marketplace_apply_action_id` AND `mer_id`>0 AND `refund_price`>0 AND `refunded_price`>=0 AND `refunded_price`<=`refund_price`'
            . " AND ((`marketplace_kind`='money_only' AND `marketplace_refund_mode`='money_only') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_refund_mode`='money_only') OR (`marketplace_kind`='return_goods' AND `marketplace_refund_mode`='return_goods'))"
            . " AND `marketplace_finance_state` IN ('NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED')"
            . " AND ((`marketplace_state`='REQUESTED' AND `refunded_price`=0 AND `marketplace_finance_state`='NOT_SUBMITTED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCEL_PENDING') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state`='RETURN_PENDING')))"
            . " OR (`marketplace_state`='APPROVED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCELLED') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state` IN ('RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED'))))"
            . " OR (`marketplace_state` IN ('WITHDRAWN','REJECTED') AND `refunded_price`=0 AND `marketplace_physical_state`='NONE' AND `marketplace_finance_state` IN ('NOT_SUBMITTED','UNSUPPORTED'))"
            . " OR (`marketplace_state`='COMPLETED' AND `refunded_price`=`refund_price` AND `marketplace_finance_state`='SUCCEEDED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCELLED') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state`='RETURN_ACCEPTED'))))";
        return [
            'columns' => [
                'marketplace_root_order_id' => $c('int unsigned'), 'marketplace_payment_attempt_id' => $c('bigint unsigned'),
                'marketplace_kind' => $c('varchar(24)', 'ascii'), 'marketplace_state' => $c('varchar(16)', 'ascii'),
                'marketplace_physical_state' => $c('varchar(24)', 'ascii'), 'marketplace_finance_state' => $c('varchar(16)', 'ascii'),
                'marketplace_apply_action_id' => $c('bigint unsigned'), 'marketplace_last_action_id' => $c('bigint unsigned'),
            ],
            'indexes' => ['idx_ms4a_refund_root' => [false, ['marketplace_root_order_id','id']],
                'uq_ms4a_refund_apply' => [true, ['marketplace_apply_action_id']], 'idx_ms4a_refund_last' => [false, ['marketplace_last_action_id']]],
            'foreign' => ['mm4a_refund_apply_fk' => ['marketplace_aftersale_action', ['marketplace_apply_action_id'], ['id'], 'PRIMARY'],
                'mm4a_refund_last_fk' => ['marketplace_aftersale_action', ['marketplace_last_action_id'], ['id'], 'PRIMARY']],
            'checks' => ['mm4a_refund_v1' => self::truth('(' . implode(' AND ', $legacy) . ') OR (' . $valid . ')')],
        ];
    }

    /** Original installation's 24 columns plus the two exact original MS4 additions. */
    private static function refundBase(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        $old = ['columns' => [
            'mer_id' => self::column('int unsigned', '随原订单的经营商家编号', true),
            'marketplace_refund_mode' => self::column('varchar(16)', '申请时固定的money_only或return_goods模式', true, null, 'ascii'),
        ], 'indexes' => ['idx_ms4_refund_owner' => [false, ['mer_id', 'store_order_id', 'id']]]];
        return ['table_collation' => null, 'table_comment' => '退款订单表',
            'columns' => [
                'id' => $c('int', '自增ID', false, null, null, true), 'store_order_id' => $c('int', '订单表ID', false, '0'),
                'store_id' => $c('int', '门店ID', false, '0'), 'order_id' => $c('varchar(50)', '订单号', false, '', 'utf8mb4'),
                'uid' => $c('int', '用户UID', false, '0'), 'refund_type' => $c('tinyint', '退款申请类型', false, '0'),
                'refund_num' => $c('int', '退款件数', false, '0'), 'refund_price' => $c('decimal(12,2)', '退款金额', false, '0.00'),
                'refunded_price' => $c('decimal(12,2)', '已退款金额', false, '0.00'), 'refund_phone' => $c('varchar(32)', '退款电话', false, '', 'utf8mb4'),
                'refund_express' => $c('varchar(100)', '退货快递单号', false, '', 'utf8mb4'), 'refund_express_name' => $c('varchar(255)', '退货快递名称', false, '', 'utf8mb4'),
                'refund_explain' => $c('varchar(255)', '退款用户说明', false, '', 'utf8mb4'), 'refund_img' => $c('text', '退款图片', true, null, 'utf8mb4'),
                'refund_reason' => $c('varchar(255)', '不退款的理由', false, '', 'utf8mb4'), 'refuse_reason' => $c('varchar(255)', '拒绝原因', false, '', 'utf8mb4'),
                'remark' => $c('varchar(255)', '备注', false, '', 'utf8mb4'), 'refunded_time' => $c('int', '处理时间', false, '0'),
                'cart_info' => $c('longtext', '退款商品信息', true, null, 'utf8mb4'), 'is_cancel' => $c('tinyint', '用户是否取消', false, '0'),
                'is_pink_cancel' => $c('tinyint', '是否拼团自动取消', false, '0'), 'is_del' => $c('tinyint', '取消申请', true, '0'),
                'add_time' => $c('int', '申请退款时间', false, '0'), 'is_system_del' => $c('tinyint', '系统删除', true, '0'),
            ] + $old['columns'], 'indexes' => ['PRIMARY' => [true, ['id']]] + $old['indexes'], 'foreign' => [], 'checks' => []];
    }


private static function fulfillmentTables(): array
{
    $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array =>
        ['type' => $type, 'comment' => '', 'nullable' => $null, 'default' => $default, 'charset' => $charset, 'auto' => $auto];
    return [
        'eb_marketplace_fulfillment_operation' => [
            'columns' => [
                'id' => $c('bigint unsigned', false, null, null, true),
                'contract_version' => $c('tinyint unsigned', false, '1'),
                'root_order_id' => $c('int unsigned'),
                'child_order_id' => $c('int unsigned'),
                'uid' => $c('int unsigned'),
                'mer_id' => $c('int unsigned'),
                'action' => $c('varchar(16)', false, null, 'ascii'),
                'idem_key' => $c('varchar(128)', false, null, 'ascii'),
                'request_digest' => $c('binary(32)'),
                'source_snapshot_digest' => $c('binary(32)'),
                'allocation_digest' => $c('binary(32)'),
                'request_payload' => $c('json'),
                'result_payload' => $c('json', true),
                'state' => $c('tinyint unsigned', false, '0'),
                'ship_child_order_id' => $c('int unsigned', true),
                'target_operation_id' => $c('bigint unsigned', true),
                'target_action' => $c('varchar(16)', true, null, 'ascii'),
                'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                'actor_id' => $c('int unsigned', true),
                'created_at' => $c('int unsigned'),
                'committed_at' => $c('int unsigned', true),
                'shipped_at' => $c('int unsigned', true),
                'receipt_due_at' => $c('int unsigned', true),
                'received_at' => $c('int unsigned', true),
            ],
            'indexes' => [
                'PRIMARY' => [true, ['id']],
                'uq_fop_request' => [true, ['root_order_id', 'action', 'idem_key']],
                'uq_fop_ship_child' => [true, ['ship_child_order_id']],
                'uq_fop_identity' => [true, ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                'idx_fop_root' => [false, ['root_order_id', 'id']],
                'idx_fop_child' => [false, ['child_order_id', 'action', 'id']],
                'idx_fop_target' => [false, ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                'idx_fop_owner' => [false, ['uid', 'root_order_id', 'id']],
                'idx_fop_merchant' => [false, ['mer_id', 'root_order_id', 'id']],
                'idx_fop_due' => [false, ['action', 'state', 'receipt_due_at', 'id']],
            ],
            'checks' => [
                'mm4f_op_ids' => '`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `root_order_id`<>`child_order_id`',
                'mm4f_op_state' => '(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)',
                'mm4f_op_json' => "JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR JSON_TYPE(`result_payload`)='OBJECT')",
                'mm4f_op_action' => "`created_at`>0 AND ((`action`='ship' AND `ship_child_order_id` IS NOT NULL AND `ship_child_order_id`=`child_order_id` AND `target_operation_id` IS NULL AND `target_action` IS NULL AND `shipped_at` IS NOT NULL AND `shipped_at`>0 AND `receipt_due_at` IS NOT NULL AND (`receipt_due_at`=0 OR `receipt_due_at`>=`shipped_at`) AND `received_at` IS NULL) OR (`action`='receive' AND `ship_child_order_id` IS NULL AND `target_operation_id` IS NOT NULL AND `target_operation_id`>0 AND `target_action` IS NOT NULL AND `target_action`='ship' AND `shipped_at` IS NULL AND `receipt_due_at` IS NULL AND `received_at` IS NOT NULL AND `received_at`>0))",
                'mm4f_op_actor' => "(`action`='ship' AND `actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0) OR (`action`='receive' AND ((`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid`) OR (`actor_type`='system' AND `actor_id` IS NULL)))",
            ],
            'foreign' => [
                'mm4f_op_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                'mm4f_op_child_fk' => ['store_order', ['child_order_id'], ['id'], 'PRIMARY'],
                'mm4f_op_user_fk' => ['user', ['uid'], ['uid'], 'PRIMARY'],
                'mm4f_op_mer_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id'], 'PRIMARY'],
                'mm4f_op_target_fk' => ['marketplace_fulfillment_operation', ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
            ],
        ],
        'eb_marketplace_fulfillment_range' => [
            'columns' => [
                'id' => $c('bigint unsigned', false, null, null, true),
                'operation_id' => $c('bigint unsigned'),
                'operation_action' => $c('varchar(16)', false, null, 'ascii'),
                'root_order_id' => $c('int unsigned'),
                'child_order_id' => $c('int unsigned'),
                'uid' => $c('int unsigned'),
                'mer_id' => $c('int unsigned'),
                'source_item_id' => $c('int'),
                'child_item_id' => $c('int'),
                'reserve_id' => $c('bigint unsigned'),
                'source_ship_range_id' => $c('bigint unsigned', true),
                'source_range_action' => $c('varchar(16)', true, null, 'ascii'),
                'product_id' => $c('mediumint'),
                'sku_id' => $c('int'),
                'sku_type' => $c('tinyint unsigned', false, '0'),
                'sku_unique' => $c('char(8)', false, null, 'ascii'),
                'cart_ref' => $c('varchar(50)', false, null, 'ascii'),
                'original_quantity' => $c('int unsigned'),
                'unit_start' => $c('int unsigned'),
                'unit_end' => $c('int unsigned'),
                'quantity' => $c('int unsigned'),
                'unit_price' => $c('decimal(12,2) unsigned'),
                'price_type' => $c('varchar(16)', false, null, 'ascii'),
                'goods' => $c('decimal(12,2) unsigned'),
                'postage' => $c('decimal(12,2) unsigned'),
                'origin_postage' => $c('decimal(12,2) unsigned'),
                'payable' => $c('decimal(12,2) unsigned'),
                'cost' => $c('decimal(12,2) unsigned'),
                'base_goods' => $c('decimal(22,2) unsigned'),
                'discount' => $c('decimal(22,2) unsigned'),
            ],
            'indexes' => [
                'PRIMARY' => [true, ['id']],
                'uq_frange_operation_start' => [true, ['operation_id', 'source_item_id', 'unit_start']],
                'uq_frange_identity' => [true, ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
                'idx_frange_operation' => [false, ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                'idx_frange_occupied' => [false, ['root_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                'idx_frange_child' => [false, ['child_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                'idx_frange_source_item' => [false, ['source_item_id', 'id']],
                'idx_frange_child_item' => [false, ['child_item_id', 'id']],
                'idx_frange_reserve' => [false, ['reserve_id', 'id']],
                'idx_frange_ship_parent' => [false, ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
            ],
            'checks' => [
                'mm4f_range_ids' => '`root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `source_item_id`>0 AND `child_item_id`>0 AND `reserve_id`>0 AND `product_id`>0 AND `sku_id`>0 AND `sku_type`=0',
                'mm4f_range_units' => '`original_quantity` BETWEEN 1 AND 2147483647 AND `unit_start`<`unit_end` AND `unit_end`<=`original_quantity` AND `quantity`=`unit_end`-`unit_start`',
                'mm4f_range_action' => "(`operation_action`='ship' AND `source_ship_range_id` IS NULL AND `source_range_action` IS NULL) OR (`operation_action`='receive' AND `source_ship_range_id` IS NOT NULL AND `source_ship_range_id`>0 AND `source_range_action` IS NOT NULL AND `source_range_action`='ship')",
                'mm4f_range_money' => "`price_type` IN ('level','member') AND `payable`=`goods`+`postage` AND `origin_postage`=`postage`",
            ],
            'foreign' => [
                'mm4f_range_operation_fk' => ['marketplace_fulfillment_operation', ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
                'mm4f_range_source_fk' => ['store_order_cart_info', ['source_item_id'], ['id'], 'PRIMARY'],
                'mm4f_range_child_fk' => ['store_order_cart_info', ['child_item_id'], ['id'], 'PRIMARY'],
                'mm4f_range_reserve_fk' => ['marketplace_stock_movement', ['reserve_id'], ['id'], 'PRIMARY'],
                'mm4f_range_parent_fk' => ['marketplace_fulfillment_range', ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], 'uq_frange_identity'],
            ],
        ],
    ];
}


    private const CHECK_LITERALS = ['OBJECT','ship','receive','employee','customer','system','level','member',
        'admin','payment','customer:','employee:','admin:','payment:','finance_observe','apply','withdraw','approve','reject','express','accept_return',
        'money_only','cancel_unshipped','return_goods','NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED',
        'REQUESTED','APPROVED','WITHDRAWN','REJECTED','COMPLETED','NONE','CANCEL_PENDING','CANCELLED','RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED',
        'image/jpeg','image/png','image/webp','[^0-9a-f]','^[!-~]([ -~]{0,126}[!-~])?$','[^ -~]','c','STAGED','CLAIMED','DELETE_PENDING','DELETED'];

    /** Only the original observed escaped-literal presentation is decoded, for the finite literals above. */
    private static function checkMetadataExpression(string $expression): ?array
    {
        foreach (self::CHECK_LITERALS as $literal) foreach (['_utf8mb4','_ascii'] as $charset)
            $expression = str_replace($charset . "\\'" . $literal . "\\'", $charset . "'" . $literal . "'", $expression);
        return self::checkExpression($expression);
    }

    /** Original finite grammar with only the fixed Task7 functions/arity; never evaluated as SQL/PHP. */
    private static function checkExpression(string $expression): ?array
    {
        if (strlen($expression) > 16384) return null;
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (!preg_match("/\\G(?:\\s+|`([A-Za-z0-9_]+)`|('(?:[^'\\\\]|'')*')|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+)|(<>|<=|>=|[(),+\\-=<>]))/A", $expression, $match, 0, $offset)) return null;
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') continue;
            if (($match[1] ?? '') !== '') $tokens[] = ['id', strtolower($match[1])];
            elseif (($match[2] ?? '') !== '') $tokens[] = ['string', str_replace("''", "'", substr($match[2], 1, -1))];
            elseif (($match[3] ?? '') !== '') $tokens[] = ['word', strtolower($match[3])];
            elseif (($match[4] ?? '') !== '') $tokens[] = ['number', $match[4]];
            else $tokens[] = ['symbol', $match[5]];
            if (count($tokens) > 4096) return null;
        }
        $position = 0; $depth = 0;
        $peek = static function (string $value) use (&$tokens, &$position): bool {
            return isset($tokens[$position]) && $tokens[$position][1] === $value && in_array($tokens[$position][0], ['word', 'symbol'], true);
        };
        $take = static function (string $value) use (&$position, $peek): bool {
            if (!$peek($value)) return false;
            ++$position; return true;
        };
        $or = $and = $comparison = $addition = $primary = null;
        $primary = static function () use (&$tokens, &$position, &$depth, &$or, $take): array {
            if (++$depth > 128) throw new RuntimeException('Check grammar');
            try {
                if ($take('(')) {
                    $node = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    return $node;
                }
                $token = $tokens[$position++] ?? null;
                if ($token === null) throw new RuntimeException('Check grammar');
                if (in_array($token, [['word', '_utf8mb4'], ['word', '_ascii']], true)) {
                    $token = $tokens[$position++] ?? null;
                    if (($token[0] ?? '') !== 'string') throw new RuntimeException('Check grammar');
                }
                if ($token[0] === 'string') {
                    if (!in_array($token[1], self::CHECK_LITERALS, true)) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token[0] === 'number') {
                    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $token[1]) !== 1) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token === ['word','true'] || $token === ['word','false']) return ['number', $token[1] === 'true' ? '1' : '0'];
                if ($token[0] !== 'id' && $token[0] !== 'word') throw new RuntimeException('Check grammar');
                if ($take('(')) {
                    if ($token[1] === 'length') $token[1] = 'octet_length';
                    $arity = ['json_type'=>1,'coalesce'=>2,'concat'=>2,'octet_length'=>1,'regexp_like'=>3][$token[1]] ?? null;
                    if ($arity === null) throw new RuntimeException('Check grammar');
                    $arguments = [$or()];
                    while ($take(',')) $arguments[] = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    if (count($arguments) !== $arity) throw new RuntimeException('Check grammar');
                    // Keep the original single-argument JSON tree byte contract.
                    return $token[1] === 'json_type' ? ['function','json_type',$arguments[0]] : ['function',$token[1],$arguments];
                }
                return ['id', $token[1]];
            } finally { --$depth; }
        };
        $addition = static function () use (&$primary, $peek, $take): array {
            $node = $primary();
            while ($peek('+') || $peek('-')) {
                $operator = $take('+') ? '+' : '-';
                if ($operator === '-' && !$take('-')) throw new RuntimeException('Check grammar');
                $node = [$operator, $node, $primary()];
            }
            return $node;
        };
        $comparison = static function () use (&$addition, $take): array {
            $node = $addition();
            foreach (['=', '<>', '<=', '>=', '<', '>'] as $operator) if ($take($operator)) return [$operator, $node, $addition()];
            if ($take('is')) {
                $negative = $take('not');
                if (!$take('null')) throw new RuntimeException('Check grammar');
                return [$negative ? 'is_not_null' : 'is_null', $node];
            }
            if ($take('between')) {
                $lower = $addition();
                if (!$take('and')) throw new RuntimeException('Check grammar');
                return ['between', $node, $lower, $addition()];
            }
            if ($take('in')) {
                if (!$take('(')) throw new RuntimeException('Check grammar');
                $values = [$addition()];
                while ($take(',')) $values[] = $addition();
                if (!$take(')')) throw new RuntimeException('Check grammar');
                return ['in', $node, $values];
            }
            return $node;
        };
        $flatten = static function (string $operator, array $nodes): array {
            if (count($nodes) === 1) return $nodes[0];
            $flat = [];
            foreach ($nodes as $node) foreach ($node[0] === $operator ? $node[1] : [$node] as $part) $flat[] = $part;
            return [$operator, $flat];
        };
        $and = static function () use (&$comparison, $take, $flatten): array {
            $nodes = [$comparison()];
            while ($take('and')) $nodes[] = $comparison();
            return $flatten('and', $nodes);
        };
        $or = static function () use (&$and, $take, $flatten): array {
            $nodes = [$and()];
            while ($take('or')) $nodes[] = $and();
            return $flatten('or', $nodes);
        };
        try {
            $tree = $or();
            return $position === count($tokens) ? $tree : null;
        } catch (Throwable $error) { return null; }
    }


    private static function quote(string $name): string
    {
        m4fm_expect(preg_match('/\A[A-Za-z0-9_]+\z/D', $name) === 1, 'EARLY_FIXED_COLUMN_IDENTIFIER');
        return '`' . $name . '`';
    }

    private static function expectedTables(): array
    {
        // Copied independent original 24/30 literals, never the tested CLI's result.
        $tables = self::fulfillmentTables();
        foreach ($tables as &$table) {
            $table['table_collation'] = 'utf8mb4_bin';
            $table['table_comment'] = 'marketplace-ms4-fulfillment-v1';
        }
        unset($table);
        $refund = self::refundBase(); $add = self::refundAdditions();
        foreach (['columns', 'indexes', 'foreign', 'checks'] as $kind) $refund[$kind] += $add[$kind];
        $op = &$tables['eb_marketplace_fulfillment_operation'];
        $old = $op['checks']['mm4f_op_ids'];
        m4fm_expect($old === '`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `root_order_id`<>`child_order_id`', 'EARLY_EXACT_OLD_OPERATION_IDS_LITERAL');
        $op['columns']['aftersale_cut_id'] = self::column('bigint unsigned', '', true);
        $op['indexes']['idx_fop_aftersale_cut'] = [false, ['aftersale_cut_id']];
        $op['foreign']['mm4a_fop_cut_fk'] = ['marketplace_aftersale_action', ['aftersale_cut_id'], ['id'], 'PRIMARY'];
        $op['checks']['mm4f_op_ids'] = self::truth(str_replace('`contract_version`=1', '`contract_version` IN (1,2)', $old));
        $op['checks']['mm4a_op_cut'] = self::truth('(`contract_version`=1 AND `aftersale_cut_id` IS NULL) OR (`contract_version`=2 AND `aftersale_cut_id` IS NOT NULL AND `aftersale_cut_id`>0)');
        unset($op);
        $new = self::newTables();
        return ['eb_marketplace_aftersale_action' => $new['marketplace_aftersale_action'],
            'eb_marketplace_aftersale_file' => $new['marketplace_aftersale_file'],
            'eb_store_order_refund' => $refund] + $tables;
    }

    private static function shape(mixed $value, array $keys): void
    {
        m4fm_expect(is_array($value), 'EARLY_NATIVE_ARRAY');
        $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
        m4fm_expect($actual === $keys, 'EARLY_EXACT_FIELD_CLOSED_SET');
    }
    private static function nativeNumber(mixed $value, int $wanted): bool
    {
        return $value === $wanted || $value === (string) $wanted;
    }
    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
    private static function positiveCounter(mixed $value): bool
    {
        if (is_int($value)) return $value >= 1;
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }
    private static function snapshotShape(array $snapshot): void
    {
        self::shape($snapshot, ['names', 'tables', 'redis', 'modes', 'metadata_observer_modes']);
        m4fm_expect(is_array($snapshot['names']) && array_is_list($snapshot['names']) && is_array($snapshot['tables']), 'EARLY_NATIVE_TABLE_NAME_LIST');
        $sorted = $snapshot['names']; sort($sorted, SORT_STRING);
        m4fm_expect($sorted === $snapshot['names'] && count(array_unique($sorted, SORT_STRING)) === count($sorted)
            && array_keys($snapshot['tables']) === $sorted, 'EARLY_COMPLETE_SORTED_TABLE_NAMES');
        foreach ($sorted as $name) m4fm_expect(is_string($name) && preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) === 1, 'EARLY_NATIVE_TABLE_NAME');
        m4fm_expect(is_array($snapshot['redis']), 'EARLY_NATIVE_REDIS_MAP');
        $keys = array_keys($snapshot['redis']); $ordered = $keys; sort($ordered, SORT_STRING);
        m4fm_expect($keys === $ordered, 'EARLY_SORTED_REDIS_DIGEST_KEYS');
        foreach ($snapshot['redis'] as $key => $value) {
            self::shape($value, ['sha256', 'expires']);
            m4fm_expect(self::digest($key) && self::digest($value['sha256']) && is_int($value['expires'])
                && ($value['expires'] === -1 || $value['expires'] > 0), 'EARLY_NATIVE_REDIS_DIGEST_ABSOLUTE_EXPIRY');
        }
        foreach (['modes', 'metadata_observer_modes'] as $key) m4fm_expect(is_array($snapshot[$key])
            && array_is_list($snapshot[$key]) && count($snapshot[$key]) === 1, 'EARLY_SINGLE_NATIVE_MODE_ROW');
        $modes = $snapshot['modes'][0]; $observer = $snapshot['metadata_observer_modes'][0];
        self::shape($modes, ['session_mode', 'global_mode', 'session_innodb', 'global_innodb']);
        self::shape($observer, ['session_mode', 'global_mode', 'session_innodb', 'global_innodb', 'session_stats_expiry', 'global_stats_expiry']);
        foreach (['session_mode', 'global_mode'] as $key) {
            m4fm_expect(is_string($modes[$key]) && strlen($modes[$key]) <= 1024
                && preg_match('/\A[A-Z0-9_]+(?:,[A-Z0-9_]+)*\z/D', $modes[$key]) === 1, 'EARLY_NATIVE_SQL_MODE');
            $parts = explode(',', $modes[$key]);
            m4fm_expect(in_array('STRICT_TRANS_TABLES', $parts, true) || in_array('STRICT_ALL_TABLES', $parts, true), 'EARLY_REAL_STRICT_SQL_MODE');
        }
        foreach (['session_innodb', 'global_innodb'] as $key) m4fm_expect(self::nativeNumber($modes[$key], 1), 'EARLY_REAL_INNODB_STRICT_MODE');
        foreach ($modes as $key => $value) m4fm_expect($observer[$key] === $value, 'EARLY_OBSERVER_FOUR_MODES_IDENTICAL');
        m4fm_expect($observer['session_stats_expiry'] === 0 && is_int($observer['global_stats_expiry'])
            && $observer['global_stats_expiry'] >= 0 && $observer['global_stats_expiry'] <= 4294967295, 'EARLY_NATIVE_METADATA_EXPIRY_MODES');
    }

    public static function assertStage20(array $snapshot): void
    {
        self::snapshotShape($snapshot);
        $dimensions = ['eb_marketplace_aftersale_action' => [21, 8, 5, 5], 'eb_marketplace_aftersale_file' => [18, 6, 2, 2],
            'eb_store_order_refund' => [34, 5, 2, 1], 'eb_marketplace_fulfillment_operation' => [25, 11, 6, 6],
            'eb_marketplace_fulfillment_range' => [30, 10, 5, 4]];
        foreach (self::expectedTables() as $name => $expected) {
            m4fm_expect(isset($snapshot['tables'][$name]), 'EARLY_STAGE20_TARGET_PRESENT');
            self::shape($expected, ['columns', 'indexes', 'foreign', 'checks', 'table_collation', 'table_comment']);
            m4fm_expect([count($expected['columns']), count($expected['indexes']), count($expected['foreign']), count($expected['checks'])] === $dimensions[$name], 'EARLY_FIXED_STAGE20_DIMENSIONS');
            $table = $snapshot['tables'][$name]; self::shape($table, ['metadata', 'ddl_sha256', 'projected_columns', 'projection']);
            $meta = $table['metadata']; self::shape($meta, ['table', 'columns', 'indexes', 'constraints', 'checks', 'foreign', 'triggers']);
            self::shape($meta['table'], ['TABLE_TYPE', 'ENGINE', 'ROW_FORMAT', 'TABLE_COLLATION', 'TABLE_COMMENT', 'CREATE_OPTIONS', 'AUTO_INCREMENT']);
            $native = $meta['table'];
            m4fm_expect($native['TABLE_TYPE'] === 'BASE TABLE' && $native['ENGINE'] === 'InnoDB'
                && in_array($native['ROW_FORMAT'], ['Dynamic', 'Compact', 'Redundant', 'Compressed'], true)
                && $native['CREATE_OPTIONS'] === '' && $native['TABLE_COMMENT'] === $expected['table_comment'], 'EARLY_EXACT_NATIVE_BASE_TABLE');
            $collation = $native['TABLE_COLLATION'];
            m4fm_expect(is_string($collation) && preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', $collation) === 1
                && ($expected['table_collation'] === null || $collation === $expected['table_collation']), 'EARLY_STAGE20_TABLE_COLLATION');
            m4fm_expect(self::digest($table['ddl_sha256']) && is_array($meta['columns'])
                && array_keys($meta['columns']) === array_keys($expected['columns'])
                && $table['projected_columns'] === array_keys($expected['columns']) && $meta['triggers'] === [], 'EARLY_COMPLETE_TARGET_COLUMNS_DDL_AND_NO_TRIGGERS');
            self::shape($table['projection'], ['encoding', 'rows', 'sha256']);
            $projection = $table['projection'];
            m4fm_expect($projection['encoding'] === 'php-serialize-row-sha256-sorted-multiset-v1'
                && is_int($projection['rows']) && $projection['rows'] >= 0 && self::digest($projection['sha256']), 'EARLY_NATIVE_ROW_MULTISET');
            if ($name === 'eb_store_order_refund') {
                m4fm_expect(self::positiveCounter($native['AUTO_INCREMENT']), 'EARLY_ORIGINAL_REFUND_AUTO_INCREMENT_NATIVE_COUNTER');
            } else {
                m4fm_expect($projection['rows'] === 0 && $projection['sha256'] === hash('sha256', '')
                    && self::nativeNumber($native['AUTO_INCREMENT'], 1), 'EARLY_FOUR_EMPTY_TABLES_AND_REAL_AUTO_INCREMENT_ONE');
            }
            foreach ($expected['columns'] as $column => $want) {
                $actual = $meta['columns'][$column];
                self::shape($actual, ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'CHARACTER_SET_NAME',
                    'COLLATION_NAME', 'EXTRA', 'GENERATION_EXPRESSION', 'COLUMN_COMMENT']);
                m4fm_expect(is_string($actual['COLUMN_TYPE']), 'EARLY_NATIVE_COLUMN_TYPE');
                $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($actual['COLUMN_TYPE']));
                $columnCollation = $want['charset'] === 'ascii' ? 'ascii_bin' : ($want['charset'] === 'utf8mb4' ? $collation : null);
                m4fm_expect($actual['COLUMN_NAME'] === $column && $type === $want['type']
                    && $actual['IS_NULLABLE'] === ($want['nullable'] ? 'YES' : 'NO') && $actual['COLUMN_DEFAULT'] === $want['default']
                    && $actual['CHARACTER_SET_NAME'] === $want['charset'] && $actual['COLLATION_NAME'] === $columnCollation
                    && $actual['EXTRA'] === ($want['auto'] ? 'auto_increment' : '') && $actual['GENERATION_EXPRESSION'] === ''
                    && $actual['COLUMN_COMMENT'] === $want['comment'], 'EARLY_EXACT_COMPLETE_TARGET_COLUMN');
            }
            $indexes = $expected['indexes']; ksort($indexes, SORT_STRING);
            m4fm_expect(is_array($meta['indexes']) && array_keys($meta['indexes']) === array_keys($indexes), 'EARLY_EXACT_STAGE20_INDEX_NAMES');
            $constraints = [];
            foreach ($indexes as $index => [$unique, $columns]) {
                $actual = $meta['indexes'][$index];
                m4fm_expect(is_array($actual) && array_is_list($actual) && count($actual) === count($columns), 'EARLY_EXACT_STAGE20_INDEX_WIDTH');
                foreach ($actual as $offset => $row) {
                    self::shape($row, ['INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME', 'SUB_PART', 'INDEX_TYPE',
                        'IS_VISIBLE', 'EXPRESSION', 'COLLATION', 'INDEX_COMMENT']);
                    m4fm_expect($row['INDEX_NAME'] === $index && self::nativeNumber($row['NON_UNIQUE'], $unique ? 0 : 1)
                        && self::nativeNumber($row['SEQ_IN_INDEX'], $offset + 1) && $row['COLUMN_NAME'] === $columns[$offset]
                        && $row['SUB_PART'] === null && $row['INDEX_TYPE'] === 'BTREE' && $row['IS_VISIBLE'] === 'YES'
                        && $row['EXPRESSION'] === null && $row['COLLATION'] === 'A' && $row['INDEX_COMMENT'] === '', 'EARLY_EXACT_COMPLETE_STAGE20_INDEX');
                }
                if ($unique) $constraints[$index] = ['CONSTRAINT_NAME' => $index, 'CONSTRAINT_TYPE' => $index === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE', 'ENFORCED' => 'YES'];
            }
            $wantedForeign = [];
            foreach ($expected['foreign'] as $key => $value) $wantedForeign['eb_' . $key] = $value;
            ksort($wantedForeign, SORT_STRING);
            m4fm_expect(is_array($meta['foreign']) && array_is_list($meta['foreign']), 'EARLY_NATIVE_FOREIGN_ROWS');
            $foreignOffset = 0;
            foreach ($wantedForeign as $key => [$target, $columns, $references, $unique]) {
                foreach ($columns as $offset => $column) {
                    $row = $meta['foreign'][$foreignOffset++] ?? null;
                    self::shape($row, ['CONSTRAINT_NAME', 'ORDINAL_POSITION', 'POSITION_IN_UNIQUE_CONSTRAINT', 'COLUMN_NAME',
                        'REFERENCED_TABLE_SCHEMA', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME', 'UNIQUE_CONSTRAINT_SCHEMA',
                        'UNIQUE_CONSTRAINT_NAME', 'UPDATE_RULE', 'DELETE_RULE', 'MATCH_OPTION']);
                    m4fm_expect($row['CONSTRAINT_NAME'] === $key && self::nativeNumber($row['ORDINAL_POSITION'], $offset + 1)
                        && self::nativeNumber($row['POSITION_IN_UNIQUE_CONSTRAINT'], $offset + 1) && $row['COLUMN_NAME'] === $column
                        && $row['REFERENCED_COLUMN_NAME'] === $references[$offset] && $row['REFERENCED_TABLE_SCHEMA'] === 'audit_auth'
                        && $row['REFERENCED_TABLE_NAME'] === 'eb_' . $target && $row['UNIQUE_CONSTRAINT_SCHEMA'] === 'audit_auth'
                        && $row['UNIQUE_CONSTRAINT_NAME'] === $unique && $row['UPDATE_RULE'] === 'RESTRICT'
                        && $row['DELETE_RULE'] === 'RESTRICT' && $row['MATCH_OPTION'] === 'NONE', 'EARLY_EXACT_COMPLETE_FOREIGN_BINARY_NAME_ORDINAL');
                }
                $constraints[$key] = ['CONSTRAINT_NAME' => $key, 'CONSTRAINT_TYPE' => 'FOREIGN KEY', 'ENFORCED' => 'YES'];
            }
            m4fm_expect($foreignOffset === count($meta['foreign']), 'EARLY_NO_EXTRA_FOREIGN_ROWS');
            $wantedChecks = [];
            foreach ($expected['checks'] as $key => $expression) $wantedChecks['eb_' . $key] = $expression;
            ksort($wantedChecks, SORT_STRING);
            m4fm_expect(is_array($meta['checks']) && array_is_list($meta['checks'])
                && count($meta['checks']) === count($wantedChecks), 'EARLY_EXACT_CHECK_ROW_COUNT');
            $checkOffset = 0;
            foreach ($wantedChecks as $key => $expression) {
                $row = $meta['checks'][$checkOffset++]; self::shape($row, ['CONSTRAINT_NAME', 'ENFORCED', 'CHECK_CLAUSE']);
                m4fm_expect(is_string($row['CHECK_CLAUSE']), 'EARLY_NATIVE_CHECK_CLAUSE');
                $want = self::checkExpression($expression); $actual = self::checkMetadataExpression($row['CHECK_CLAUSE']);
                m4fm_expect($row['CONSTRAINT_NAME'] === $key && $row['ENFORCED'] === 'YES'
                    && $want !== null && $actual !== null && $actual === $want, 'EARLY_EXACT_ENFORCED_CHECK_BINARY_NAME_AND_FULL_TREE');
                $constraints[$key] = ['CONSTRAINT_NAME' => $key, 'CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES'];
            }
            ksort($constraints, SORT_STRING);
            m4fm_expect(is_array($meta['constraints']) && array_is_list($meta['constraints']), 'EARLY_NATIVE_CONSTRAINT_LIST');
            foreach ($meta['constraints'] as $row) self::shape($row, ['CONSTRAINT_NAME', 'CONSTRAINT_TYPE', 'ENFORCED']);
            m4fm_expect($meta['constraints'] === array_values($constraints), 'EARLY_EXACT_COMPLETE_ENFORCED_CONSTRAINT_BINARY_ORDER');
        }
    }
}

function m4as_definitions(): array
    {
        return [
            'marketplace_customer_asset' => [
                'columns' => [
                    'id' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'bigint unsigned', 'NO', null, null, null, 'auto_increment'],
                    'uid' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'purpose' => ['ENUM(\'avatar_public\',\'public_upload_compat\',\'review_public_image\',\'user_promotion_image\',\'user_promotion_qr\',\'legacy_refund_evidence\',\'refund_shipping_proof\',\'order_form_image\',\'withdrawal_qr\',\'agent_licence\',\'order_verify_code\',\'gift_receive_code\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'enum(\'avatar_public\',\'public_upload_compat\',\'review_public_image\',\'user_promotion_image\',\'user_promotion_qr\',\'legacy_refund_evidence\',\'refund_shipping_proof\',\'order_form_image\',\'withdrawal_qr\',\'agent_licence\',\'order_verify_code\',\'gift_receive_code\')', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'state' => ['ENUM(\'STAGED\',\'BOUND\',\'RETIRED\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'STAGED\'', 'enum(\'STAGED\',\'BOUND\',\'RETIRED\')', 'NO', 'STAGED', 'ascii', 'ascii_bin', ''],
                    'storage_type' => ['TINYINT UNSIGNED NOT NULL', 'tinyint unsigned', 'NO', null, null, null, ''],
                    'object_key' => ['VARCHAR(512) NOT NULL', 'varchar(512)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'thumbnail_key' => ['VARCHAR(512) NOT NULL DEFAULT \'\'', 'varchar(512)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'name' => ['VARCHAR(100) NOT NULL', 'varchar(100)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'real_name' => ['VARCHAR(255) NOT NULL', 'varchar(255)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'media_type' => ['VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'varchar(127)', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'byte_size' => ['BIGINT UNSIGNED NOT NULL', 'bigint unsigned', 'NO', null, null, null, ''],
                    'width' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'height' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'content_sha256' => ['BINARY(32) NOT NULL', 'binary(32)', 'NO', null, null, null, ''],
                    'created_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'expires_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'bound_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'retired_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'root_order_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'order_item_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                    'refund_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                    'review_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                    'confirmation_ref' => ['BINARY(32) NULL DEFAULT NULL', 'binary(32)', 'YES', null, null, null, ''],
                    'form_product_id' => ['MEDIUMINT NULL DEFAULT NULL', 'mediumint', 'YES', null, null, null, ''],
                    'form_definition_sha256' => ['BINARY(32) NULL DEFAULT NULL', 'binary(32)', 'YES', null, null, null, ''],
                    'form_field_index' => ['SMALLINT UNSIGNED NULL DEFAULT NULL', 'smallint unsigned', 'YES', null, null, null, ''],
                    'extract_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'agent_apply_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'source_product_id' => ['MEDIUMINT NULL DEFAULT NULL', 'mediumint', 'YES', null, null, null, ''],
                    'source_qrcode_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_uid' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_admin_id' => ['SMALLINT UNSIGNED NULL DEFAULT NULL', 'smallint unsigned', 'YES', null, null, null, ''],
                    'created_by_job' => ['VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'varchar(32)', 'YES', null, 'ascii', 'ascii_bin', ''],
                    'legacy_att_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_storage_object' => [true, ['storage_type', 'object_key']],
                    'uq_legacy_att' => [true, ['legacy_att_id']],
                    'ix_user_purpose' => [false, ['uid', 'purpose', 'state', 'id']],
                    'ix_order_item' => [false, ['root_order_id', 'order_item_id', 'id']],
                    'ix_form' => [false, ['confirmation_ref', 'form_product_id', 'form_field_index', 'id']],
                ],
            ],
            'marketplace_platform_asset_category' => [
                'columns' => [
                    'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null, null, 'auto_increment'],
                    'pid' => ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, null, ''],
                    'name' => ['VARCHAR(50) NOT NULL DEFAULT \'\'', 'varchar(50)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'enname' => ['VARCHAR(50) NOT NULL DEFAULT \'\'', 'varchar(50)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'type' => ['TINYINT NOT NULL DEFAULT 0', 'tinyint', 'NO', '0', null, null, ''],
                    'created_by_admin_id' => ['SMALLINT UNSIGNED NOT NULL', 'smallint unsigned', 'NO', null, null, null, ''],
                    'updated_by_admin_id' => ['SMALLINT UNSIGNED NOT NULL', 'smallint unsigned', 'NO', null, null, null, ''],
                    'created_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'updated_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'legacy_category_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_name' => [true, ['name']],
                    'uq_legacy_category' => [true, ['legacy_category_id']],
                    'ix_parent' => [false, ['pid', 'id']],
                ],
            ],
            'marketplace_platform_asset' => [
                'columns' => [
                    'id' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'bigint unsigned', 'NO', null, null, null, 'auto_increment'],
                    'purpose' => ['ENUM(\'site_image\',\'site_file\',\'channel_qr\',\'routine_download_qr\',\'platform_chat_image\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'enum(\'site_image\',\'site_file\',\'channel_qr\',\'routine_download_qr\',\'platform_chat_image\')', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'category_id' => ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, null, ''],
                    'state' => ['ENUM(\'STAGED\',\'BOUND\',\'RETIRED\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'STAGED\'', 'enum(\'STAGED\',\'BOUND\',\'RETIRED\')', 'NO', 'STAGED', 'ascii', 'ascii_bin', ''],
                    'storage_type' => ['TINYINT UNSIGNED NOT NULL', 'tinyint unsigned', 'NO', null, null, null, ''],
                    'object_key' => ['VARCHAR(512) NOT NULL', 'varchar(512)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'thumbnail_key' => ['VARCHAR(512) NOT NULL DEFAULT \'\'', 'varchar(512)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'name' => ['VARCHAR(100) NOT NULL', 'varchar(100)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'real_name' => ['VARCHAR(255) NOT NULL', 'varchar(255)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'media_type' => ['VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'varchar(127)', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'byte_size' => ['BIGINT UNSIGNED NOT NULL', 'bigint unsigned', 'NO', null, null, null, ''],
                    'width' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'height' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'content_sha256' => ['BINARY(32) NOT NULL', 'binary(32)', 'NO', null, null, null, ''],
                    'created_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'expires_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'bound_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'retired_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_admin_id' => ['SMALLINT UNSIGNED NULL DEFAULT NULL', 'smallint unsigned', 'YES', null, null, null, ''],
                    'created_by_service_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_uid' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_job' => ['VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'varchar(32)', 'YES', null, 'ascii', 'ascii_bin', ''],
                    'chat_customer_uid' => ['BIGINT NULL DEFAULT NULL', 'bigint', 'YES', null, null, null, ''],
                    'chat_is_tourist' => ['TINYINT UNSIGNED NULL DEFAULT NULL', 'tinyint unsigned', 'YES', null, null, null, ''],
                    'chat_service_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'chat_service_uid' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'channel_qrcode_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'routine_version' => ['VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'varchar(64)', 'YES', null, 'ascii', 'ascii_bin', ''],
                    'legacy_att_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_storage_object' => [true, ['storage_type', 'object_key']],
                    'uq_legacy_att' => [true, ['legacy_att_id']],
                    'ix_category' => [false, ['category_id', 'id']],
                    'ix_purpose' => [false, ['purpose', 'state', 'id']],
                    'ix_chat' => [false, ['chat_customer_uid', 'chat_service_uid', 'chat_is_tourist', 'id']],
                ],
            ],
        ];
    }



function m4as_contract(array $table,array $definition):void
{
 $m=$table['metadata'];$native=$m['table'];if($native['AUTO_INCREMENT']!==null)$native['AUTO_INCREMENT']=(string)$native['AUTO_INCREMENT'];
 m4fm_expect($native===['TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB','ROW_FORMAT'=>'Dynamic','TABLE_COLLATION'=>'utf8mb4_bin','TABLE_COMMENT'=>'','CREATE_OPTIONS'=>'','AUTO_INCREMENT'=>'1']
  &&$m['checks']===[]&&$m['foreign']===[]&&$m['triggers']===[],'ASSET_COMPLETE_NATIVE_TABLE_EMPTY_HIDDEN_OBJECTS');
 m4fm_expect(array_keys($m['columns'])===array_keys($definition['columns'])&&$table['projected_columns']===array_keys($definition['columns']),'ASSET_ALL_NATIVE_COLUMNS_IN_ORDER');
 foreach($definition['columns']as$name=>$expected){$actual=$m['columns'][$name];
  if(!str_starts_with($actual['COLUMN_TYPE'],'enum('))$actual['COLUMN_TYPE']=preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/','$1',strtolower($actual['COLUMN_TYPE']));
  if($actual['COLUMN_DEFAULT']!==null)$actual['COLUMN_DEFAULT']=(string)$actual['COLUMN_DEFAULT'];
  m4fm_expect($actual===['COLUMN_NAME'=>$name,'COLUMN_TYPE'=>$expected[1],'IS_NULLABLE'=>$expected[2],'COLUMN_DEFAULT'=>$expected[3],
   'CHARACTER_SET_NAME'=>$expected[4],'COLLATION_NAME'=>$expected[5],'EXTRA'=>$expected[6],'GENERATION_EXPRESSION'=>'','COLUMN_COMMENT'=>''],'ASSET_EVERY_NATIVE_COLUMN_VALUE');}
 $indexes=$definition['indexes'];ksort($indexes,SORT_STRING);m4fm_expect(array_keys($m['indexes'])===array_keys($indexes),'ASSET_ALL_NATIVE_INDEX_NAMES');$constraints=[];
 foreach($indexes as$name=>[$unique,$columns]){$wanted=[];foreach($columns as$i=>$column)$wanted[]=['INDEX_NAME'=>$name,'NON_UNIQUE'=>$unique?'0':'1','SEQ_IN_INDEX'=>(string)($i+1),
  'COLUMN_NAME'=>$column,'SUB_PART'=>null,'INDEX_TYPE'=>'BTREE','IS_VISIBLE'=>'YES','EXPRESSION'=>null,'COLLATION'=>'A','INDEX_COMMENT'=>''];
  $actual=$m['indexes'][$name];foreach($actual as&$row){$row['NON_UNIQUE']=(string)$row['NON_UNIQUE'];$row['SEQ_IN_INDEX']=(string)$row['SEQ_IN_INDEX'];}unset($row);
  m4fm_expect($actual===$wanted,'ASSET_COMPLETE_NATIVE_INDEX_VALUES');if($unique)$constraints[]=['CONSTRAINT_NAME'=>$name,'CONSTRAINT_TYPE'=>$name==='PRIMARY'?'PRIMARY KEY':'UNIQUE','ENFORCED'=>'YES'];}
 m4fm_expect($m['constraints']===$constraints,'ASSET_ONLY_EXACT_PRIMARY_AND_UNIQUE_CONSTRAINTS');
 m4fm_expect($table['projection']===['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>0,'sha256'=>hash('sha256','')],'ASSET_NEW_NATIVE_TABLE_EMPTY_FULL_PROJECTION');
}
function m4as_result(string $mode,array $answer,array $sources):void
{
 $keys=['mode','state','native_exit','changed','change_observation','attempted_steps','applied_steps','ddl_outcome_uncertain','pending_steps','pending_steps_known','observed_tables',
  'structure_step_count','issues','failure_phase','lock_release_failed','lock_scope','source_sha256','schema_source_sha256','sources_held','schema_contract','scope','history_mapping','writer','activation','acceptance'];
 m4fm_expect(m4fm_keys($answer,$keys),'ASSET_EXACT_TWENTY_FIVE_CLI_KEYS');
 $steps=array_map(static fn(string $name):string=>'create.'.$name,array_keys(m4as_definitions()));$complete=$answer['state']==='completed';$written=$answer['changed'];
 m4fm_expect(in_array($mode,['check','apply-structure'],true)&&$answer['mode']===$mode&&in_array($answer['state'],['applicable','completed'],true)&&$answer['native_exit']===0
  &&is_bool($written)&&(!$written||($mode==='apply-structure'&&$complete))&&$answer['change_observation']===($written?'DDL_MAY_HAVE_COMMITTED':'DDL_NOT_ATTEMPTED')
  &&$answer['attempted_steps']===($written?$steps:[])&&$answer['applied_steps']===($written?$steps:[])&&$answer['ddl_outcome_uncertain']===false
  &&$answer['pending_steps']===($complete?[]:$steps)&&$answer['pending_steps_known']===true&&$answer['observed_tables']===array_fill_keys(array_keys(m4as_definitions()),$complete?'compatible':'absent')
  &&$answer['structure_step_count']===3&&$answer['issues']===[]&&$answer['failure_phase']===null&&$answer['lock_release_failed']===false
  &&$answer['source_sha256']===$sources['help/migrations/marketplace-ms4-assets.php']
  &&$answer['schema_source_sha256']===$sources['crmeb/app/services/marketplace/assets/AssetSchema.php']&&$answer['sources_held']===true
  &&$answer['schema_contract']==='marketplace-asset-domains-v1'&&$answer['scope']==='MS4_ASSET_DOMAINS_STRUCTURE_ONLY'
  &&$answer['history_mapping']==='NOT RUN'&&$answer['writer']==='NOT RUN'&&$answer['activation']==='NOT RUN'&&$answer['acceptance']==='NOT RUN','ASSET_EXACT_TWENTY_FIVE_CLI_VALUES');
 $lock=['request_attempted'=>false,'execute_attempted'=>false,'preexisting_same_session'=>null,'acquisition'=>'NOT_ATTEMPTED','request_cursor_closed'=>null,
  'confirmation_failed'=>false,'release_attempted'=>false,'release_execute_attempted'=>false,'release_result'=>'NOT_ATTEMPTED','release_cursor_closed'=>null,'ownership'=>'NOT_CHECKED','resolved'=>null];
 if($mode==='apply-structure')$lock=['request_attempted'=>true,'execute_attempted'=>true,'preexisting_same_session'=>false,'acquisition'=>'ONE','request_cursor_closed'=>true,
  'confirmation_failed'=>false,'release_attempted'=>true,'release_execute_attempted'=>true,'release_result'=>'ONE','release_cursor_closed'=>true,'ownership'=>'NOT_OWNED','resolved'=>true];
 m4fm_expect($answer['lock_scope']===$lock,'ASSET_EXACT_NORMAL_LOCK_ACQUIRE_RELEASE_SCOPE');
}

/** Full SQL token comparison against the independent literal, preserving quoted values and identifiers. */
function m4as_ddl_tokens(string $sql):array
{
 $tokens=[];$length=strlen($sql);
 for($i=0;$i<$length;){
  $c=$sql[$i];if(ctype_space($c)){++$i;continue;}
  if($c==="'"||$c===chr(96)){$quote=$c;$start=$i++;$closed=false;
   while($i<$length){if($sql[$i]==='\\'){m4fm_expect($i+1<$length,'ASSET_DDL_COMPLETE_ESCAPE');$i+=2;continue;}
    if($sql[$i]===$quote){if($i+1<$length&&$sql[$i+1]===$quote){$i+=2;continue;}$i++;$closed=true;break;}++$i;}
   m4fm_expect($closed,'ASSET_DDL_COMPLETE_QUOTED_TOKEN');$tokens[]=substr($sql,$start,$i-$start);continue;}
  if(preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/A',$sql,$match,0,$i)===1){$tokens[]=strtoupper($match[0]);$i+=strlen($match[0]);continue;}
  if(preg_match('/\G[0-9]+/A',$sql,$match,0,$i)===1){$tokens[]=$match[0];$i+=strlen($match[0]);continue;}
  m4fm_expect(str_contains('(),=;',$c),'ASSET_DDL_NO_UNRECOGNIZED_TOKEN');$tokens[]=$c;++$i;
 }
 if(end($tokens)===';')array_pop($tokens);
 $normal=[];
 for($i=0;$i<count($tokens);++$i){$token=$tokens[$i];
  if(in_array($token,['TINYINT','SMALLINT','MEDIUMINT','INT','BIGINT'],true)&&($tokens[$i+1]??null)==='('
   &&ctype_digit($tokens[$i+2]??'')&&($tokens[$i+3]??null)===')'){$normal[]=$token;$i+=3;continue;}
  if($token==='CHARACTER'&&($tokens[$i+1]??null)==='SET'&&($tokens[$i+2]??null)==='UTF8MB4'){$i+=2;continue;}
  if($token==='COLLATE'&&($tokens[$i+1]??null)==='UTF8MB4_BIN'){++$i;continue;}
  if($token==='AUTO_INCREMENT'&&($tokens[$i+1]??null)==='='&&($tokens[$i+2]??null)==='1'){$i+=2;continue;}
  if($token==='NULL'&&!in_array(end($normal),['NOT','DEFAULT'],true))continue;
  if(end($normal)==='DEFAULT'&&preg_match("/^'[0-9]+'$/D",$token)===1)$token=substr($token,1,-1);
  $normal[]=$token;
 }
 return $normal;
}
function m4as_expected_ddl(string $name,array $definition):string
{
 $parts=[];foreach($definition['columns']as$column=>$expected)$parts[]=m4fm_quote($column).' '.$expected[0];
 foreach($definition['indexes']as$index=>[$unique,$columns])$parts[]=($index==='PRIMARY'?'PRIMARY KEY':($unique?'UNIQUE KEY ':'KEY ').m4fm_quote($index))
  .' ('.implode(',',array_map('m4fm_quote',$columns)).')';
 return 'CREATE TABLE '.m4fm_quote($name).' ('.implode(',',$parts).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
}
/** The real private migrator observes exact new structures; every preexisting full table stays identical. */
function m4as_delta(array $before,array $after,array $private):void
{
 m4fm_preserved($before,$after);
 m4fm_expect($before['metadata_observer_modes']===$after['metadata_observer_modes'],'ASSET_SIX_OBSERVER_MODES_UNCHANGED');
 $definitions=m4as_definitions();$expectedNames=array_merge($before['names'],array_map(static fn(string $name):string=>'eb_'.$name,array_keys($definitions)));
 sort($expectedNames,SORT_STRING);
 m4fm_expect(count($expectedNames)===count(array_unique($expectedNames,SORT_STRING))&&$after['names']===$expectedNames
  &&array_keys($after['tables'])===$expectedNames,'ASSET_EXACT_OLD_NAMESPACE_PLUS_THREE');
 m4fm_private_assert($private);$configuration=m4fm_read($private['path']);
 m4fm_expect($configuration['username']==='audit_migrator'&&$configuration['database']==='audit_auth'&&$configuration['prefix']==='eb_','ASSET_SAME_PRIVATE_MIGRATOR_CONFIGURATION');
 $db=audit_pdo($configuration);unset($configuration);m4fm_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false),'ASSET_NATIVE_PREPARES');
 $grants=$db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
 m4fm_expect(count($grants)===2&&count(array_filter($grants,static fn(string $grant):bool=>str_starts_with($grant,'GRANT USAGE ON *.* TO ')))===1
  &&count(array_filter($grants,static fn(string $grant):bool=>str_starts_with($grant,'GRANT ALL PRIVILEGES ON '.chr(96).'audit_auth'.chr(96).'.* TO ')&&!str_contains($grant,'GRANT OPTION')))===1
  &&$db->query('SELECT DATABASE()')->fetchColumn()==='audit_auth'
  &&$db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN)===[getenv('AUDIT_RUN_ID')],'ASSET_DIRECT_SCHEMA_METADATA_AUTHORITY');
 $observer=m4fm_configure_observer($db);
 foreach($definitions as$suffix=>$definition){$name='eb_'.$suffix;$table=$after['tables'][$name];
  m4as_contract($table,$definition);$native=m4fm_metadata($db,$name);
  m4fm_expect($native===$table['metadata'],'ASSET_FULL_PRIVILEGED_NATIVE_METADATA_EQUALS_SNAPSHOT');
  $ordinals=m4fm_rows($db,'SELECT COLUMN_NAME,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$name]);
  $wanted=[];foreach(array_keys($definition['columns'])as$i=>$column)$wanted[]=['COLUMN_NAME'=>$column,'ORDINAL_POSITION'=>(string)($i+1)];
  foreach($ordinals as&$row)$row['ORDINAL_POSITION']=(string)$row['ORDINAL_POSITION'];unset($row);
  m4fm_expect($ordinals===$wanted,'ASSET_ALL_EXACT_NATIVE_COLUMN_ORDINALS');
  $extended=m4fm_rows($db,'SELECT INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,PACKED,NULLABLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX',[$name]);
  $indexes=$definition['indexes'];ksort($indexes,SORT_STRING);$wanted=[];
  foreach($indexes as$index=>[$unique,$columns])foreach($columns as$i=>$column)$wanted[]=['INDEX_NAME'=>$index,'SEQ_IN_INDEX'=>(string)($i+1),'COLUMN_NAME'=>$column,'PACKED'=>null,'NULLABLE'=>$definition['columns'][$column][2]==='YES'?'YES':''];
  foreach($extended as&$row)$row['SEQ_IN_INDEX']=(string)$row['SEQ_IN_INDEX'];unset($row);
  m4fm_expect($extended===$wanted,'ASSET_ALL_EXACT_NATIVE_PACKED_AND_NULLABLE_INDEX_FIELDS');
  m4fm_expect(m4fm_rows($db,'SELECT * FROM '.m4fm_quote($name))===[],'ASSET_DIRECT_COMPLETE_ROWS_EMPTY');
  $ddl=$db->query('SHOW CREATE TABLE '.m4fm_quote($name))->fetch(PDO::FETCH_NUM);
  m4fm_expect(is_array($ddl)&&is_string($ddl[1]??null)&&hash('sha256',$ddl[1])===$table['ddl_sha256']
   &&m4as_ddl_tokens($ddl[1])===m4as_ddl_tokens(m4as_expected_ddl($name,$definition)),'ASSET_FULL_NATIVE_DDL_INDEPENDENT_LITERAL_AND_RAW_HASH');
 }
 m4fm_expect(m4fm_observer_modes($db)===$observer,'ASSET_PRIVILEGED_OBSERVER_SIX_MODES_REMAIN_IDENTICAL');
 m4fm_private_assert($private);unset($grants,$db);
}

function m4ae_assert_stage20(array $snapshot): void
{
    M4aeStage20Oracle::assertStage20($snapshot);
}

function m4ae_cli(string $kind,string $mode,array $private,array &$evidence):array
{
    $scripts=m4ae_scripts();m4fm_expect(isset($scripts[$kind])&&in_array($mode,in_array($kind,['aftersale','assets'],true)?['check','apply-structure']:['check','apply'],true),
        'EARLY_FIXED_SUCCESSOR_COMMAND');$script=$scripts[$kind];$scriptSha=$evidence['binding']['successor_cli_sha256'][$script];
    m4fm_expect(m4fm_source() === $evidence['binding'], 'CHILD_SOURCE_SAME'); audit_guard_assert(); m4fm_private_assert($private);
    $process = proc_open([PHP_BINARY, '/workspace/' . $script, $mode, '--config-file=' . $private['path']],
        [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace');
    m4fm_expect(is_resource($process), 'NATIVE_CHILD_STARTED');
    $first = null; $terminal = null; $captureError = null; $close = null;
    $buffers = [1 => '', 2 => '']; $counts = [1 => 0, 2 => 0]; $hashes = [1 => hash_init('sha256'), 2 => hash_init('sha256')];
    try {
        $first = proc_get_status($process); m4fm_expect(is_array($first) && is_int($first['pid']) && $first['pid'] > 0, 'NATIVE_CHILD_PID');
        $terminal = m4fm_terminal($first);
        foreach ($pipes as $pipe) m4fm_expect(stream_set_blocking($pipe, false), 'CHILD_PIPE_MODE');
        while (true) {
            $read = [];
            foreach ($pipes as $pipe) if (!feof($pipe)) $read[] = $pipe;
            if ($read === []) break;
            $write = null; $except = null; $ready = stream_select($read, $write, $except, 0, 200000);
            m4fm_expect($ready !== false, 'CHILD_PIPE_SELECT');
            foreach ($read as $pipe) {
                $part = fread($pipe, 8192); m4fm_expect(is_string($part), 'CHILD_PIPE_READ');
                $channel = $pipe === $pipes[1] ? 1 : 2; $counts[$channel] += strlen($part); hash_update($hashes[$channel], $part);
                $room = 1048576 - strlen($buffers[$channel]); if ($room > 0) $buffers[$channel] .= substr($part, 0, $room);
            }
            if ($terminal === null) { $status = proc_get_status($process); if (is_array($status)) $terminal = m4fm_terminal($status); }
        }
    } catch (Throwable $error) { $captureError = get_class($error); }
    finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if ($terminal === null) { $status = proc_get_status($process); if (is_array($status)) $terminal = m4fm_terminal($status); }
        $close = proc_close($process);
    }
    $native = is_int($close) && $close >= 0 ? $close : ($close === -1 && $terminal !== null ? $terminal['exitcode'] : null);
    $result = null;
    if ($counts[1] <= 1048576 && $counts[2] <= 1048576) {
        try { $result = json_decode($buffers[1], true, 64, JSON_THROW_ON_ERROR); } catch (Throwable $error) { $captureError ??= get_class($error); }
    }
    $evidence['successor_children'][] = ['script'=>$script,'script_sha256'=>$scriptSha,'mode' => $mode, 'pid' => $first['pid'] ?? null, 'native_exit' => $native,
        'proc_close_exit' => $close, 'same_handle_terminal' => $terminal, 'capture_error_class' => $captureError,
        'stdout_bytes' => $counts[1], 'stdout_sha256' => hash_final($hashes[1]),
        'stderr_bytes' => $counts[2], 'stderr_sha256' => hash_final($hashes[2]), 'result' => is_array($result) ? $result : null];
    m4fm_expect($captureError === null && $native === 0 && ($terminal === null || $native === $terminal['exitcode'])
        && $counts[2] === 0 && $counts[1] <= 1048576 && is_array($result) && !array_is_list($result), 'EARLY_ACTUAL_SAME_HANDLE_SUCCESSOR_CLI');
    m4ae_result($kind,$mode,$scriptSha,$result,$evidence['binding']);
    return $result;
}


function m4fm_terminal(array $status): ?array
{
    if (($status['running'] ?? null) !== false || ($status['signaled'] ?? null) !== false || ($status['stopped'] ?? null) !== false
        || !is_int($status['exitcode'] ?? null) || $status['exitcode'] < 0) return null;
    return ['running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => $status['exitcode']];
}
function m4fm_cli(string $mode, array $private, array &$evidence): array
{
    m4fm_expect(m4fm_source() === $evidence['binding'], 'CHILD_SOURCE_SAME'); audit_guard_assert(); m4fm_private_assert($private);
    $process = proc_open([PHP_BINARY, '/workspace/' . M4FM_CLI, $mode, '--config-file=' . $private['path']],
        [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, '/workspace');
    m4fm_expect(is_resource($process), 'NATIVE_CHILD_STARTED');
    $first = null; $terminal = null; $captureError = null; $close = null;
    $buffers = [1 => '', 2 => '']; $counts = [1 => 0, 2 => 0]; $hashes = [1 => hash_init('sha256'), 2 => hash_init('sha256')];
    try {
        $first = proc_get_status($process); m4fm_expect(is_array($first) && is_int($first['pid']) && $first['pid'] > 0, 'NATIVE_CHILD_PID');
        $terminal = m4fm_terminal($first);
        foreach ($pipes as $pipe) m4fm_expect(stream_set_blocking($pipe, false), 'CHILD_PIPE_MODE');
        while (true) {
            $read = [];
            foreach ($pipes as $pipe) if (!feof($pipe)) $read[] = $pipe;
            if ($read === []) break;
            $write = null; $except = null; $ready = stream_select($read, $write, $except, 0, 200000);
            m4fm_expect($ready !== false, 'CHILD_PIPE_SELECT');
            foreach ($read as $pipe) {
                $part = fread($pipe, 8192); m4fm_expect(is_string($part), 'CHILD_PIPE_READ');
                $channel = $pipe === $pipes[1] ? 1 : 2; $counts[$channel] += strlen($part); hash_update($hashes[$channel], $part);
                $room = 1048576 - strlen($buffers[$channel]); if ($room > 0) $buffers[$channel] .= substr($part, 0, $room);
            }
            if ($terminal === null) { $status = proc_get_status($process); if (is_array($status)) $terminal = m4fm_terminal($status); }
        }
    } catch (Throwable $error) { $captureError = get_class($error); }
    finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if ($terminal === null) { $status = proc_get_status($process); if (is_array($status)) $terminal = m4fm_terminal($status); }
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
    m4fm_expect($captureError === null && $native === 0 && ($terminal === null || $native === $terminal['exitcode'])
        && $counts[2] === 0 && $counts[1] <= 1048576 && is_array($result) && !array_is_list($result)
        && m4fm_keys($result, ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known', 'issues', 'failure_phase',
            'marker', 'source_sha256', 'scope', 'prerequisite_source_sha256', 'writer', 'acceptance'])
        && $result['mode'] === $mode && $result['native_exit'] === 0 && $result['pending_steps_known'] === true && $result['issues'] === []
        && $result['failure_phase'] === null && $result['marker'] === M4FM_MARKER && $result['source_sha256'] === $evidence['binding']['cli_sha256']
        && $result['scope'] === 'MS4_FULFILLMENT_STRUCTURE_ONLY'
        && $result['prerequisite_source_sha256'] === $evidence['binding']['prerequisite_source_sha256']
        && $result['writer'] === 'NOT RUN' && $result['acceptance'] === 'NOT RUN', 'ACTUAL_NORMAL_CLI');
    return $result;
}

/** A single create-only publication attempt. A partial or pre-existing result is never overwritten. */
function m4fm_publish(array &$evidence, bool &$attempted, bool $sourceBound): bool
{
    if ($attempted) return false;
    $attempted = true; $written = false;
    if ($sourceBound) {
        try {
            $path = '/run/audit/' . M4FM_RESULT;
            m4fm_expect(!file_exists($path) && !is_link($path), 'RESULT_CREATE_ONLY');
            $bytes = m4fm_json($evidence); m4fm_expect(strlen($bytes) <= 67108864, 'EARLY_COMPLETE_RESULT_BOUND');
            $handle = @fopen($path, 'xb'); m4fm_expect(is_resource($handle), 'RESULT_CREATE');
            try {
                m4fm_expect(@chmod($path, 0600), 'RESULT_MODE'); $offset = 0;
                while ($offset < strlen($bytes)) {
                    $count = @fwrite($handle, substr($bytes, $offset)); m4fm_expect(is_int($count) && $count > 0, 'RESULT_WRITE'); $offset += $count;
                }
                m4fm_expect(@fflush($handle), 'RESULT_FLUSH');
            } finally { m4fm_expect(@fclose($handle), 'RESULT_CLOSE'); }
            $written = true;
        } catch (Throwable $error) {
            $evidence['status'] = 'FAIL';
            $evidence['publication_failure'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
        }
    }
    if (!$written && $evidence['termination'] === 'NORMAL_RETURN') $evidence['entry_return_code'] = 1;
    echo m4fm_json(['status' => $written ? $evidence['status'] : 'FAIL', 'scope' => M4FM_SCOPE,
        'entry_return_code' => $evidence['entry_return_code'], 'native_exit' => null, 'native_exit_observation' => 'HOST_NATIVE_REQUIRED',
        'termination' => $evidence['termination'], 'phase' => $evidence['phase'], 'actual_command_counts' => $evidence['actual_command_counts'] ?? null,
        'private_cleanup_verified' => $evidence['private_cleanup_verified'], 'private_cleanup_state' => $evidence['private_cleanup_state'],
        'receipt_write_verified' => $written, 'evidence_ref' => $written ? M4FM_RESULT : null, 'business_acceptance' => 'NOT RUN']);
    return $written;
}

/** Keep diagnostics bounded while identifying the real assertion call site. */
function m4ae_diagnostic(Throwable $error, string $phase): array
{
    $file = $error->getFile(); $line = $error->getLine();
    if (get_class($error) === RuntimeException::class && $file === __FILE__) {
        $trace = $error->getTrace(); $frame = $trace[0] ?? null;
        if (is_array($frame) && ($frame['function'] ?? null) === 'm4fm_expect'
            && ($frame['file'] ?? null) === __FILE__ && is_int($frame['line'] ?? null) && $frame['line'] > 0) {
            $line = $frame['line'];
        }
        unset($trace, $frame);
    }
    return ['class' => get_class($error), 'file' => basename($file), 'line' => $line, 'phase' => $phase];
}

function m4fm_main(): int
{
    umask(0077); $phase = 'source'; $sourceBound = false; $publicationAttempted = false; $finished = false;
    $private = ['created' => false, 'path' => null, 'handle' => null, 'identity' => null, 'complete' => null, 'cleanup_attempted' => false];
    $evidence = ['schema' => 1, 'scope' => M4FM_SCOPE, 'status' => 'FAIL', 'binding' => null,
        'children' => [], 'successor_children' => [], 'snapshots' => [], 'comparisons' => [],
        'phase' => $phase, 'termination' => 'IN_PROGRESS', 'entry_return_code' => null,
        'native_exit' => null, 'native_exit_observation' => 'HOST_NATIVE_REQUIRED',
        'private_cleanup_verified' => false, 'private_cleanup_state' => 'NOT_CREATED', 'actual_command_counts' => [],
        'original_table_count' => null, 'original_rows' => [], 'independent_new_metadata_verified' => false,
        'menu_leaves' => [], 'full_source_unchanged' => false, 'prerequisites_verified' => false,
        'stopped_cases' => ['denial_conflict_concurrency_fault_restore' => 'NOT RUN'], 'business_acceptance' => 'NOT RUN',
        'diagnostic' => null, 'cleanup_failure' => null, 'publication_failure' => null];
    register_shutdown_function(static function () use (&$finished, &$phase, &$sourceBound, &$publicationAttempted, &$private, &$evidence): void {
        if ($finished) return;
        // exit(2) in the existing guard bypasses finally. Never guess or replace that native exit.
        $evidence['status'] = 'FAIL'; $evidence['phase'] = $phase; $evidence['termination'] = 'SHUTDOWN_WITHOUT_NORMAL_RETURN';
        $evidence['entry_return_code'] = null; $evidence['native_exit'] = null; $evidence['native_exit_observation'] = 'HOST_NATIVE_REQUIRED';
        $evidence['actual_command_counts'] = m4ae_counts($evidence);
        m4fm_private_cleanup($private, $evidence);
        try { m4fm_publish($evidence, $publicationAttempted, $sourceBound); }
        catch (Throwable $error) { fwrite(STDERR, "FAIL structure result publication unavailable; host native observation required\n"); }
    });
    try {
        $evidence['binding'] = m4fm_source(); $sourceBound = true; audit_guard_assert();
        $phase = 'private_migrator'; $migratorPath = '/run/audit-bootstrap/migrator.json';
        $migrator = m4fm_read($migratorPath); $stat = @lstat($migratorPath);
        m4fm_expect(is_array($stat) && ($stat['mode'] & 0777) === 0600 && $stat['uid'] === posix_geteuid()
            && m4fm_keys($migrator, ['username', 'password']) && $migrator['username'] === 'audit_migrator' && is_string($migrator['password']), 'DEDICATED_PRIVATE_MIGRATOR');
        $db = audit_pdo($migrator); $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        m4fm_expect(count($grants) === 2 && count(array_filter($grants, static fn(string $grant): bool => str_starts_with($grant, 'GRANT USAGE ON *.* TO '))) === 1
            && count(array_filter($grants, static fn(string $grant): bool => str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `audit_auth`.* TO ') && !str_contains($grant, 'GRANT OPTION'))) === 1
            && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')], 'MIGRATOR_SAME_SCHEMA_SENTINEL');
        m4fm_private_create($private, m4fm_json(['hostname' => 'audit-mysql', 'hostport' => '3306', 'database' => 'audit_auth',
            'username' => $migrator['username'], 'password' => $migrator['password'], 'prefix' => 'eb_']));
        unset($migrator, $grants, $db);
        $phase = 'baseline'; $before = m4fm_snapshot(); $evidence['snapshots']['before'] = m4fm_snapshot_evidence($before);
        m4ae_initial_database($before, $evidence['binding']); $evidence['prerequisites_verified'] = true;
        $evidence['original_table_count'] = count($before['names']);
        foreach ($before['tables'] as $name => $table) $evidence['original_rows'][$name] = $table['projection']['rows'];
        $newNames = array_keys(m4fm_expected_tables()); sort($newNames, SORT_STRING);
        foreach ($newNames as $name) m4fm_expect(!in_array($name, $before['names'], true), 'FRESH_FULFILLMENT_TARGETS_ABSENT');
        $steps = ['marketplace_fulfillment_operation', 'marketplace_fulfillment_range'];
        $phase = 'check_first'; $check = m4fm_cli('check', $private, $evidence);
        m4fm_expect($check['state'] === 'applicable' && $check['changed'] === false && $check['applied_steps'] === [] && $check['pending_steps'] === $steps, 'FIRST_CHECK_EXACT_TWO_STEPS');
        $checked = m4fm_snapshot(); m4fm_expect($checked === $before, 'FIRST_CHECK_FULL_UNCHANGED');
        $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'before', 'snapshot_sha256' => hash('sha256', serialize($checked))];
        $phase = 'apply_first'; $apply = m4fm_cli('apply-structure', $private, $evidence);
        m4fm_expect($apply['state'] === 'completed' && $apply['changed'] === true && $apply['applied_steps'] === $steps && $apply['pending_steps'] === [], 'FIRST_APPLY_EXACT_TWO_STEPS');
        $after = m4fm_snapshot(); $evidence['snapshots']['fulfillment'] = m4fm_snapshot_evidence($after);
        $added = array_values(array_diff($after['names'], $before['names'])); sort($added, SORT_STRING);
        m4fm_expect($added === $newNames && count($after['names']) === count($before['names']) + 2, 'ONLY_TWO_NEW_TABLES');
        m4fm_preserved($before, $after); m4fm_new_tables($after);
        m4fm_expect($after['metadata_observer_modes'] === $before['metadata_observer_modes'], 'EARLY_FULFILLMENT_SIX_NATIVE_MODES_PRESERVED');
        foreach (['check', 'apply-structure'] as $mode) {
            $phase = $mode . '_repeat'; $result = m4fm_cli($mode, $private, $evidence);
            m4fm_expect($result['state'] === 'completed' && $result['changed'] === false && $result['applied_steps'] === [] && $result['pending_steps'] === [], 'REPEAT_COMPLETED_UNCHANGED');
            $again = m4fm_snapshot(); m4fm_expect($again === $after, 'FULL_REPEAT_UNCHANGED');
            $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'fulfillment', 'snapshot_sha256' => hash('sha256', serialize($again))];
        }
        // The original four-command 24-column oracle ends here. It is never called after stage20.
        $steps = m4ae_aftersale_steps(); $phase = 'aftersale_check_first';
        $check = m4ae_cli('aftersale', 'check', $private, $evidence);
        m4fm_expect($check['state'] === 'applicable' && $check['changed'] === false && $check['structure_stage'] === 0
            && $check['applied_steps'] === [] && $check['pending_steps'] === $steps, 'EARLY_AFTERSALE_FRESH_CHECK_EXACT_TWENTY_STEPS');
        $checked = m4fm_snapshot(); m4fm_expect($checked === $after, 'EARLY_AFTERSALE_FIRST_CHECK_FULL_UNCHANGED');
        $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'fulfillment', 'snapshot_sha256' => hash('sha256', serialize($checked))];
        $phase = 'aftersale_apply_first'; $apply = m4ae_cli('aftersale', 'apply-structure', $private, $evidence);
        m4fm_expect($apply['state'] === 'completed' && $apply['changed'] === true && $apply['structure_stage'] === 20
            && $apply['applied_steps'] === $steps && $apply['pending_steps'] === [], 'EARLY_AFTERSALE_FIRST_APPLY_EXACT_TWENTY_STEPS');
        $aftersale = m4fm_snapshot(); $evidence['snapshots']['aftersale'] = m4fm_snapshot_evidence($aftersale);
        m4ae_structure_delta($after, $aftersale);
        foreach (['check', 'apply-structure'] as $mode) {
            $phase = 'aftersale_' . $mode . '_repeat'; $result = m4ae_cli('aftersale', $mode, $private, $evidence);
            m4fm_expect($result['state'] === 'completed' && $result['changed'] === false && $result['structure_stage'] === 20
                && $result['applied_steps'] === [] && $result['pending_steps'] === [], 'EARLY_AFTERSALE_STAGE20_REPEAT_UNCHANGED');
            $again = m4fm_snapshot(); m4fm_expect($again === $aftersale, 'EARLY_AFTERSALE_REPEAT_COMPLETE_SNAPSHOT_UNCHANGED');
            $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'aftersale', 'snapshot_sha256' => hash('sha256', serialize($again))];
        }
        $menuDb = audit_db(); m4fm_expect($menuDb->setAttribute(PDO::ATTR_EMULATE_PREPARES, false), 'EARLY_MENU_ACTUAL_NATIVE_PREPARES');
        $current = $aftersale;
        foreach (['aftersale_menus', 'review_menus'] as $kind) {
            $rows = m4ae_menu_native($menuDb);
            m4fm_expect($current['tables']['eb_system_menus']['projection'] === m4ae_menu_projection($rows), 'EARLY_MENU_NATIVE_BASELINE_IS_CURRENT_SNAPSHOT');
            $phase = $kind . '_check_first'; $check = m4ae_cli($kind, 'check', $private, $evidence);
            m4fm_expect($check['state'] === 'applicable' && $check['changed'] === false && $check['menu_ids'] === [], 'EARLY_MENU_FIRST_CHECK_ALL_LEAVES_ABSENT');
            $checked = m4fm_snapshot(); $checkedRows = m4ae_menu_native($menuDb);
            m4fm_expect($checked === $current && $checkedRows === $rows, 'EARLY_MENU_FIRST_CHECK_COMPLETE_UNCHANGED');
            $evidence['comparisons'][] = ['phase' => $phase, 'equals' => $kind === 'aftersale_menus' ? 'aftersale' : 'aftersale_menus',
                'snapshot_sha256' => hash('sha256', serialize($checked))];
            $phase = $kind . '_apply_first'; $apply = m4ae_cli($kind, 'apply', $private, $evidence);
            $next = m4fm_snapshot(); $nextRows = m4ae_menu_native($menuDb);
            $evidence['snapshots'][$kind] = m4fm_snapshot_evidence($next);
            $ids = m4ae_menu_delta($current, $next, $rows, $nextRows, $kind, $apply); m4ae_assert_stage20($next);
            $evidence['menu_leaves'][$kind] = $ids;
            foreach (['check', 'apply'] as $mode) {
                $phase = $kind . '_' . $mode . '_repeat'; $result = m4ae_cli($kind, $mode, $private, $evidence);
                m4fm_expect($result['state'] === 'completed' && $result['changed'] === false && $result['menu_ids'] === $ids
                    && ($mode !== 'apply' || $result['created_menu_ids'] === []), 'EARLY_MENU_REPEAT_SAME_ACTUAL_PK_MAP_NO_INSERT');
                $again = m4fm_snapshot(); $againRows = m4ae_menu_native($menuDb);
                m4fm_expect($again === $next && $againRows === $nextRows, 'EARLY_MENU_REPEAT_COMPLETE_METADATA_COUNTER_ROWS_UNCHANGED');
                $evidence['comparisons'][] = ['phase' => $phase, 'equals' => $kind, 'snapshot_sha256' => hash('sha256', serialize($again))];
            }
            $current = $next;
        }

        $assetBefore=$current;$assetNames=array_map(static fn(string $name):string=>'eb_'.$name,array_keys(m4as_definitions()));
        m4fm_expect(array_intersect($assetBefore['names'],$assetNames)===[],'ASSET_EXACT_THREE_FRESH_NAMES_ABSENT');
        $phase='assets_check_first';$check=m4ae_cli('assets','check',$private,$evidence);
        m4fm_expect($check['state']==='applicable'&&$check['changed']===false,'ASSET_FIRST_CHECK_REAL_APPLICABLE');
        $checked=m4fm_snapshot();m4fm_expect($checked===$assetBefore,'ASSET_FIRST_CHECK_COMPLETE_STATE_UNCHANGED');
        $evidence['comparisons'][]=['phase'=>$phase,'equals'=>'review_menus','snapshot_sha256'=>hash('sha256',serialize($checked))];
        $phase='assets_apply_first';$apply=m4ae_cli('assets','apply-structure',$private,$evidence);
        m4fm_expect($apply['state']==='completed'&&$apply['changed']===true,'ASSET_FIRST_APPLY_REAL_THREE_TABLES');
        $assets=m4fm_snapshot();m4as_delta($assetBefore,$assets,$private);$evidence['snapshots']['assets']=m4fm_snapshot_evidence($assets);
        foreach(['check','apply-structure']as$mode){$phase='assets_'.$mode.'_repeat';$answer=m4ae_cli('assets',$mode,$private,$evidence);
            m4fm_expect($answer['state']==='completed'&&$answer['changed']===false,'ASSET_REPEAT_READONLY_AND_NO_DDL');
            $again=m4fm_snapshot();m4fm_expect($again===$assets,'ASSET_REPEAT_COMPLETE_STATE_UNCHANGED');
            $evidence['comparisons'][]=['phase'=>$phase,'equals'=>'assets','snapshot_sha256'=>hash('sha256',serialize($again))];}
        $phase = 'final_binding'; m4fm_expect(m4fm_source() === $evidence['binding'], 'FINAL_SOURCE_SAME'); audit_guard_assert(); m4fm_private_assert($private);
        $counts = m4ae_counts($evidence);
        m4fm_expect(count($evidence['children']) === 4 && count($evidence['successor_children']) === 16
            && $counts === ['fulfillment' => ['check' => 2, 'apply-structure' => 2], 'aftersale' => ['check' => 2, 'apply-structure' => 2],
                'aftersale_menus' => ['check' => 2, 'apply' => 2], 'review_menus' => ['check' => 2, 'apply' => 2],
                'assets' => ['check' => 2, 'apply-structure' => 2]], 'EARLY_ACTUAL_TWENTY_SAME_HANDLE_NATIVE_COMMANDS');
        $evidence['independent_new_metadata_verified'] = true; $evidence['full_source_unchanged'] = true; $evidence['status'] = M4FM_VERIFIED;
    } catch (Throwable $error) {
        $evidence['status'] = 'FAIL'; $evidence['diagnostic'] = m4ae_diagnostic($error, $phase);
    } finally { m4fm_private_cleanup($private, $evidence); unset($migrator, $grants, $db, $menuDb); }
    $evidence['actual_command_counts'] = m4ae_counts($evidence);
    $evidence['phase'] = $phase; $evidence['termination'] = 'NORMAL_RETURN';
    $returnCode = $evidence['status'] === M4FM_VERIFIED ? 0 : 1; $evidence['entry_return_code'] = $returnCode;
    $published = m4fm_publish($evidence, $publicationAttempted, $sourceBound); $finished = true;
    return $published ? $returnCode : 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(m4fm_main());
