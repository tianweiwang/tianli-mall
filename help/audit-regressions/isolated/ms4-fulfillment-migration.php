<?php
declare(strict_types=1);

// Only the isolation helpers are loaded. The migration itself runs in four native children.
require __DIR__ . '/lib/harness.php';

const M4FM_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4FM_SCOPE = 'MS4_FULFILLMENT_NORMAL_STRUCTURE_ONLY';
const M4FM_VERIFIED = 'VERIFIED_MS4_FULFILLMENT_NORMAL_STRUCTURE_ONLY';
const M4FM_MARKER = 'marketplace-ms4-fulfillment-v1';
const M4FM_ENTRY = 'help/audit-regressions/isolated/ms4-fulfillment-migration.php';
const M4FM_CLI = 'help/migrations/marketplace-ms4-fulfillment.php';
const M4FM_RESULT = 'evidence/ms4-fulfillment-migration-normal.json';

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
        'help/migrations/marketplace-ms5-payment.md', M4FM_CLI, 'crmeb/public/install/crmeb.sql'];
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
    m4fm_expect(m4fm_keys($binding, ['schema', 'run_id', 'source_sha', 'requested_repo_source', 'requested_run_source', 'entry_sha256', 'cli_sha256', 'source_files', 'paid_predecessor_sha256'])
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
    foreach (['mysql-data', 'redis-data', 'bootstrap-secrets', 'redis-secrets', 'app-secrets', 'fixture-secrets'] as $name) $volumes[] = $manifest['project'] . '_' . $name;
    sort($volumes, SORT_STRING); $actual = $manifest['volumes'] ?? []; sort($actual, SORT_STRING);
    m4fm_expect($actual === $volumes && is_array($manifest['volume_identities'] ?? null)
        && m4fm_keys($manifest['volume_identities'], $volumes), 'EXACT_SIX_OWN_VOLUMES');
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
        m4fm_expect(($mount['Type'] ?? '') === 'bind' && ($mount['RW'] ?? null) === false
            && audit_canonical_host_bind_source((string) ($mount['Source'] ?? '')) === audit_canonical_host_bind_source($binding['requested_repo_source']), 'CURRENT_SOURCE_RO');
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
    $tmp = false;
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = isset($parts[1]) ? explode(' ', $parts[1]) : [];
        if (($left[4] ?? '') === '/tmp' && ($right[0] ?? '') === 'tmpfs') $tmp = true;
    }
    m4fm_expect($tmp && !file_exists('/workspace/crmeb/.env') && !is_link('/workspace/crmeb/.env')
        && !file_exists('/workspace/crmeb/public/install.lock') && !is_link('/workspace/crmeb/public/install.lock'), 'NO_HOST_CONFIG_PRIVATE_TMPFS');
    m4fm_expect($binding['entry_sha256'] === hash_file('sha256', __FILE__)
        && $binding['cli_sha256'] === hash_file('sha256', '/workspace/' . M4FM_CLI)
        && is_array($binding['source_files']) && array_is_list($binding['source_files']) && count($binding['source_files']) === 11, 'CURRENT_ENTRY_CLI_BINDING');
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
    $paid = m4fm_paid_predecessor($manifest, $binding, $run, $sha);
    $fileHashes = array_column($binding['source_files'], 'sha256', 'path'); $prerequisites = [];
    foreach (['help/migrations/marketplace-ms1.php', 'help/migrations/marketplace-ms4.php', 'help/migrations/marketplace-ms5-payment.php'] as $path)
        $prerequisites[$path] = $fileHashes[$path];
    return ['run_id' => $run, 'container_id' => $service['id'], 'entry_namespace_pid' => getmypid(), 'source_sha' => $sha,
        'entry_sha256' => $binding['entry_sha256'], 'cli_sha256' => $binding['cli_sha256'], 'source_files' => 11,
        'binding_sha256' => $bindingHash, 'native_created_sha256' => $createdHash,
        'paid_predecessor_sha256' => $paid, 'prerequisite_source_sha256' => $prerequisites];
}

/** Public predecessor only; the new profile host independently verifies its full original paid closure. */
function m4fm_paid_predecessor(array $manifest, array $binding, string $run, string $sha): array
{
    $names = ['paid-producer-created.json', 'paid-producer-terminal.json', 'paid-producer-result.json',
        'paid-producer-source-binding.json', 'paid-producer-environment.json', 'paid-producer-environment-native.json'];
    m4fm_expect(is_array($binding['paid_predecessor_sha256']) && m4fm_keys($binding['paid_predecessor_sha256'], $names), 'EXACT_PAID_PREDECESSOR_BINDING');
    $values = []; $hashes = [];
    foreach ($names as $name) {
        $hash = null; $values[$name] = m4fm_read('/run/audit/evidence/' . $name, $hash,
            $name === 'paid-producer-source-binding.json' ? 16777216 : 1048576);
        m4fm_expect(is_string($binding['paid_predecessor_sha256'][$name])
            && preg_match('/\A[a-f0-9]{64}\z/D', $binding['paid_predecessor_sha256'][$name]) === 1
            && $hash === $binding['paid_predecessor_sha256'][$name], 'ACTUAL_PAID_PREDECESSOR_BYTES');
        $hashes[$name] = $hash;
    }
    $created = $values['paid-producer-created.json']; $terminal = $values['paid-producer-terminal.json'];
    $result = $values['paid-producer-result.json']; $paidBinding = $values['paid-producer-source-binding.json'];
    $service = $manifest['services']['audit-ms5-paid-producer'];
    m4fm_expect(($created['run_id'] ?? null) === $run && ($created['source_sha'] ?? null) === $sha
        && ($created['id'] ?? null) === ($service['id'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($created['id'] ?? '')) === 1
        && ($created['image'] ?? null) === M4FM_IMAGE && ($service['image_id'] ?? null) === M4FM_IMAGE
        && ($created['status'] ?? null) === 'created' && ($created['restart_count'] ?? null) === 0
        && ($created['labels']['com.docker.compose.project'] ?? null) === $manifest['project']
        && ($created['labels']['com.docker.compose.service'] ?? null) === 'audit-ms5-paid-producer'
        && ($created['command'] ?? null) === ['help/audit-regressions/isolated/ms5-paid-consumer-normal.php', 'produce']
        && ($terminal['id'] ?? null) === $created['id'] && ($terminal['image'] ?? null) === $created['image']
        && ($terminal['labels'] ?? null) === $created['labels']
        && ($terminal['status'] ?? null) === 'exited' && ($terminal['pid'] ?? null) === 0
        && ($terminal['restart_count'] ?? null) === 0 && ($terminal['exit_code'] ?? null) === 0, 'REAL_ORIGINAL_PAID_TERMINAL_ZERO');
    m4fm_expect(m4fm_keys($result, ['schema', 'scope', 'status', 'run_id', 'source_sha', 'container_id', 'freeze_sha256',
        'native_created_sha256', 'source_binding_sha256', 'environment_sha256', 'environment_native_sha256', 'phase', 'entry_return_code',
        'termination', 'native_exit', 'native_exit_observation', 'full_source_unchanged', 'child', 'metadata_observer', 'business',
        'customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'full_acceptance', 'stopped_samples', 'assertions'])
        && $result['schema'] === 1 && $result['scope'] === 'MS5_PAID_PRODUCER_INTERNAL_ONLY'
        && $result['status'] === 'VERIFIED_MS5_PAID_PRODUCER_INTERNAL_ONLY'
        && $result['run_id'] === $run && $result['source_sha'] === $sha && $result['container_id'] === $created['id']
        && $result['native_created_sha256'] === $hashes['paid-producer-created.json']
        && $result['source_binding_sha256'] === $hashes['paid-producer-source-binding.json']
        && $result['environment_sha256'] === $hashes['paid-producer-environment.json']
        && $result['environment_native_sha256'] === $hashes['paid-producer-environment-native.json']
        && $result['phase'] === 'final_source' && $result['entry_return_code'] === 0 && $result['termination'] === 'NORMAL_RETURN'
        && $result['native_exit'] === null && $result['native_exit_observation'] === 'HOST_NATIVE_REQUIRED'
        && $result['full_source_unchanged'] === true && is_int($result['assertions']) && $result['assertions'] > 0
        && $result['full_acceptance'] === 'INCOMPLETE', 'ORIGINAL_PAID_RESULT_PRESERVED');
    foreach (['customer_paid_API_DOM', 'merchant_paid_API_DOM', 'platform_paid_API_DOM', 'stopped_samples'] as $key)
        m4fm_expect($result[$key] === 'NOT RUN', 'PAID_PREDECESSOR_SCOPE_RETAINED');
    $freeze = hash_file('sha256', '/run/audit/preparation-source-binding.json');
    m4fm_expect(is_string($freeze) && $result['freeze_sha256'] === $freeze
        && ($paidBinding['schema'] ?? null) === 1 && ($paidBinding['scope'] ?? null) === 'MS5_PAID_PRODUCER_SOURCE'
        && ($paidBinding['run_id'] ?? null) === $run && ($paidBinding['source_sha'] ?? null) === $sha
        && ($paidBinding['container_id'] ?? null) === $created['id'] && ($paidBinding['freeze_sha256'] ?? null) === $freeze
        && ($paidBinding['native_created_sha256'] ?? null) === $hashes['paid-producer-created.json'], 'PAID_SAME_PROFILE_FREEZE');
    $environment = $values['paid-producer-environment.json']; $environmentNative = $values['paid-producer-environment-native.json'];
    m4fm_expect(($environment['run_id'] ?? null) === $run && ($environment['source_sha'] ?? null) === $sha
        && ($environment['container_id'] ?? null) === $created['id'] && ($environment['freeze_sha256'] ?? null) === $freeze
        && ($environment['native_projection_sha256'] ?? null) === ($environmentNative['stdout_sha256'] ?? null)
        && is_int($environmentNative['native_exit'] ?? null) && $environmentNative['native_exit'] === 0, 'PAID_REAL_ENVIRONMENT_PREDECESSOR');
    $child = $result['child'];
    m4fm_expect(is_array($child) && m4fm_keys($child, ['stopped', 'group', 'remaining_members', 'listener_open', 'stop_signal',
        'term_attempted', 'native_child', 'native_proc_close', 'normal_only'])
        && $child['stopped'] === true && $child['remaining_members'] === [] && $child['listener_open'] === false
        && $child['stop_signal'] === 15 && $child['term_attempted'] === true && $child['normal_only'] === true
        && is_array($child['native_child']) && $child['native_child']['running'] === false
        && is_int($child['native_child']['termsig'] ?? null) && $child['native_child']['termsig'] !== 9
        && is_int($child['native_proc_close']), 'PAID_IDENTITY_CHILD_NORMAL_CLOSED');
    return $hashes;
}

/** The open handle and ownership are recorded before any content write can fail. */
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
        'modes' => m4fm_rows($db, 'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb')];
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
            $bytes = m4fm_json($evidence); $handle = @fopen($path, 'xb'); m4fm_expect(is_resource($handle), 'RESULT_CREATE');
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

function m4fm_main(): int
{
    umask(0077); $phase = 'source'; $sourceBound = false; $publicationAttempted = false; $finished = false;
    $private = ['created' => false, 'path' => null, 'handle' => null, 'identity' => null, 'complete' => null, 'cleanup_attempted' => false];
    $evidence = ['schema' => 1, 'status' => 'FAIL', 'scope' => M4FM_SCOPE, 'binding' => null, 'children' => [], 'snapshots' => [], 'comparisons' => [],
        'phase' => $phase, 'termination' => 'IN_PROGRESS', 'entry_return_code' => null, 'native_exit' => null, 'native_exit_observation' => 'HOST_NATIVE_REQUIRED',
        'private_cleanup_verified' => false, 'private_cleanup_state' => 'NOT_CREATED',
        'stopped_cases' => ['denial_conflict_concurrency_fault_restore' => 'NOT RUN'],
        'paid_producer' => 'PREDECESSOR_ONLY', 'fulfillment_writer' => 'NOT RUN', 'business_acceptance' => 'NOT RUN'];
    register_shutdown_function(static function () use (&$finished, &$phase, &$sourceBound, &$publicationAttempted, &$private, &$evidence): void {
        if ($finished) return;
        // exit(2) in the existing guard bypasses finally. Never guess or replace that native exit.
        $evidence['status'] = 'FAIL'; $evidence['phase'] = $phase; $evidence['termination'] = 'SHUTDOWN_WITHOUT_NORMAL_RETURN';
        $evidence['entry_return_code'] = null; $evidence['native_exit'] = null; $evidence['native_exit_observation'] = 'HOST_NATIVE_REQUIRED';
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
        $newNames = array_keys(m4fm_expected_tables()); sort($newNames, SORT_STRING);
        foreach ($newNames as $name) m4fm_expect(!in_array($name, $before['names'], true), 'FRESH_FULFILLMENT_TARGETS_ABSENT');
        $steps = ['marketplace_fulfillment_operation', 'marketplace_fulfillment_range'];
        $phase = 'check_first'; $check = m4fm_cli('check', $private, $evidence);
        m4fm_expect($check['state'] === 'applicable' && $check['changed'] === false && $check['applied_steps'] === [] && $check['pending_steps'] === $steps, 'FIRST_CHECK_EXACT_TWO_STEPS');
        $checked = m4fm_snapshot(); m4fm_expect($checked === $before, 'FIRST_CHECK_FULL_UNCHANGED');
        $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'before', 'snapshot_sha256' => hash('sha256', serialize($checked))];
        $phase = 'apply_first'; $apply = m4fm_cli('apply-structure', $private, $evidence);
        m4fm_expect($apply['state'] === 'completed' && $apply['changed'] === true && $apply['applied_steps'] === $steps && $apply['pending_steps'] === [], 'FIRST_APPLY_EXACT_TWO_STEPS');
        $after = m4fm_snapshot(); $evidence['snapshots']['after'] = m4fm_snapshot_evidence($after);
        $added = array_values(array_diff($after['names'], $before['names'])); sort($added, SORT_STRING);
        m4fm_expect($added === $newNames && count($after['names']) === count($before['names']) + 2, 'ONLY_TWO_NEW_TABLES');
        m4fm_preserved($before, $after); m4fm_new_tables($after);
        foreach (['check', 'apply-structure'] as $mode) {
            $phase = $mode . '_repeat'; $result = m4fm_cli($mode, $private, $evidence);
            m4fm_expect($result['state'] === 'completed' && $result['changed'] === false && $result['applied_steps'] === [] && $result['pending_steps'] === [], 'REPEAT_COMPLETED_UNCHANGED');
            $again = m4fm_snapshot(); m4fm_expect($again === $after, 'FULL_REPEAT_UNCHANGED');
            $evidence['comparisons'][] = ['phase' => $phase, 'equals' => 'after', 'snapshot_sha256' => hash('sha256', serialize($again))];
        }
        $phase = 'final_binding'; m4fm_expect(m4fm_source() === $evidence['binding'], 'FINAL_SOURCE_SAME'); audit_guard_assert(); m4fm_private_assert($private);
        $counts = array_count_values(array_column($evidence['children'], 'mode'));
        m4fm_expect(count($evidence['children']) === 4 && $counts === ['check' => 2, 'apply-structure' => 2], 'ACTUAL_FOUR_NATIVE_COMMANDS');
        $evidence['actual_command_counts'] = $counts; $evidence['original_table_count'] = count($before['names']);
        $evidence['original_rows'] = [];
        foreach ($before['tables'] as $name => $table) $evidence['original_rows'][$name] = $table['projection']['rows'];
        $evidence['independent_new_metadata_verified'] = true; $evidence['status'] = M4FM_VERIFIED;
    } catch (Throwable $error) {
        $evidence['status'] = 'FAIL'; $evidence['diagnostic'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'phase' => $phase];
    } finally { m4fm_private_cleanup($private, $evidence); unset($migrator, $grants, $db); }
    $evidence['phase'] = $phase; $evidence['termination'] = 'NORMAL_RETURN';
    $returnCode = $evidence['status'] === M4FM_VERIFIED ? 0 : 1; $evidence['entry_return_code'] = $returnCode;
    $published = m4fm_publish($evidence, $publicationAttempted, $sourceBound); $finished = true;
    return $published ? $returnCode : 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(m4fm_main());
