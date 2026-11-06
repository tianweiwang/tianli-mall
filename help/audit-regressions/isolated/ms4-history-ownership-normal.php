<?php
declare(strict_types=1);

/**
 * One post-reader, synthetic-only continuation. Run only through the adjacent
 * history_ownership_normal.py after the original reader and its retirement.
 * Original Run/source/manifest are read-only. New receipts never enter that Run.
 * This entry does not migrate saved legacy orders to V1 or test old write paths.
 */
const NHECO_SCOPE = 'POST_READER_SYNTHETIC_OWNERSHIP_NORMAL_V1';
const NHECO_IMAGE = 'sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const NHECO_ENTRY = '/run/post-reader/ms4-history-ownership-normal.php';
const NHECO_ROOTS = ['store_product' => 'id', 'system_attachment' => 'att_id',
    'system_attachment_category' => 'id', 'shipping_templates' => 'id', 'store_product_rule' => 'id'];

function nheco_expect(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException($code);
}
function nheco_keys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    nheco_expect($actual === $keys, 'NHECO_EXACT_KEYS');
}
function nheco_pin(string $raw): array { return ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)]; }
function nheco_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
function nheco_stat(array $stat): array
{
    $out = []; foreach (['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'] as $key) {
        nheco_expect(isset($stat[$key]) && is_int($stat[$key]), 'NHECO_INTEGER_STAT9'); $out[$key] = $stat[$key];
    }
    return $out;
}
function nheco_read(string $path, ?array $pin = null, int $limit = 67108864): string
{
    nheco_expect($path !== '' && $path[0] === '/' && !str_contains($path, '..') && !str_contains($path, "\0"), 'NHECO_FIXED_ABSOLUTE_PATH');
    clearstatcache(true, $path); $before = lstat($path);
    nheco_expect(is_array($before) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && $before['nlink'] === 1
        && is_int($before['size']) && $before['size'] >= 0 && $before['size'] <= $limit, 'NHECO_BOUNDED_REGULAR_SINGLE_LINK_FILE');
    $before = nheco_stat($before); $handle = fopen($path, 'rb'); nheco_expect(is_resource($handle), 'NHECO_OPEN_FILE');
    try {
        nheco_expect(nheco_stat(fstat($handle)) === $before, 'NHECO_SAME_OPEN_FILE');
        // Read the real size plus EOF, not a 64 MiB reservation for every input.
        $raw = stream_get_contents($handle, $before['size'] + 1);
        nheco_expect(is_string($raw) && strlen($raw) === $before['size'] && fread($handle, 1) === ''
            && feof($handle) && nheco_stat(fstat($handle)) === $before, 'NHECO_FULL_FILE_EOF_STAT9');
    } finally { fclose($handle); }
    clearstatcache(true, $path); nheco_expect(nheco_stat(lstat($path)) === $before, 'NHECO_CLOSED_PATH_STAT9');
    if ($pin !== null) nheco_expect(nheco_pin($raw) === ['bytes' => $pin['bytes'], 'sha256' => $pin['sha256']], 'NHECO_COMPLETE_FILE_PIN');
    return $raw;
}
function nheco_pointer(array $pointer): string
{
    nheco_keys($pointer, ['host_path','path','bytes','sha256']);
    nheco_expect(is_string($pointer['host_path']) && is_string($pointer['path'])
        && is_int($pointer['bytes']) && $pointer['bytes'] >= 0
        && is_string($pointer['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $pointer['sha256']) === 1, 'NHECO_POINTER_SHAPE');
    return nheco_read($pointer['path'], $pointer);
}
function nheco_object(string $raw): array
{
    $value = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
    nheco_expect(is_array($value) && !array_is_list($value), 'NHECO_JSON_OBJECT'); return $value;
}
function nheco_new(string $path, string $raw): array
{
    nheco_expect(str_starts_with($path, '/run/post-reader-output/'), 'NHECO_NEW_EXTERNAL_RECEIPT_ONLY');
    $handle = fopen($path, 'xb'); nheco_expect(is_resource($handle), 'NHECO_CREATE_ONLY_RECEIPT');
    try { nheco_expect(fwrite($handle, $raw) === strlen($raw) && fflush($handle), 'NHECO_COMPLETE_RECEIPT_WRITE'); }
    finally { fclose($handle); }
    nheco_expect(nheco_read($path) === $raw, 'NHECO_RECEIPT_FULL_READBACK'); return nheco_pin($raw);
}
function nheco_memory(): void
{
    nheco_expect(ini_get('memory_limit') === '128M', 'NHECO_ORIGINAL_ROLE_MEMORY');
    $previous = ini_set('memory_limit', '512M');
    nheco_expect($previous === '128M' && ini_get('memory_limit') === '512M', 'NHECO_EXPLICIT_ROLE_MEMORY');
}

/** Independent fixed role; it never impersonates a Source3 registered reader. */
function nheco_qualify(array $wanted, bool $http = false): array
{
    $expectedSource = getenv('AUDIT_SOURCE_SHA');
    nheco_expect(is_string($expectedSource) && preg_match('/\A[a-f0-9]{40}\z/D', $expectedSource) === 1,
        'NHECO_EXPLICIT_EXPECTED_APPLICATION_SOURCE_SHA');
    if (!defined('NHECO_APP_SOURCE')) define('NHECO_APP_SOURCE', $expectedSource);
    nheco_expect(NHECO_APP_SOURCE === $expectedSource, 'NHECO_EXPECTED_APPLICATION_SOURCE_UNCHANGED');
    nheco_expect(__FILE__ === NHECO_ENTRY && realpath(__FILE__) === __FILE__ && PHP_INT_SIZE === 8
        && PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300 && PHP_SAPI === ($http ? 'cli-server' : 'cli')
        && getcwd() === '/workspace/crmeb' && getenv('AUDIT_ISOLATED') === '1'
        && getenv('AUDIT_GUARD_MODE') === 'read-only', 'NHECO_REAL_FIXED_ROLE_ENTRY');
    $handoffRaw = nheco_read('/run/post-reader/HANDOFF.json', $wanted); $h = nheco_object($handoffRaw);
    nheco_keys($h, ['schema','scope','run_id','app_source_sha','context','predecessors','resources','self_mer_id','actor','dependencies','history_artifacts','role']);
    nheco_keys($h['role'], ['service','proof','image','source_directory','output_directory']);
    nheco_expect($h['schema'] === 1 && $h['scope'] === NHECO_SCOPE && $h['app_source_sha'] === NHECO_APP_SOURCE
        && $h['role']['service'] === 'audit-post-reader-ownership' && $h['role']['proof'] === 'post-reader-ownership'
        && $h['role']['image'] === NHECO_IMAGE && is_string($h['role']['source_directory']) && is_string($h['role']['output_directory'])
        && basename($h['role']['source_directory']) === 'source' && basename($h['role']['output_directory']) === 'evidence'
        && dirname($h['role']['source_directory']) === dirname($h['role']['output_directory'])
        && is_int($h['self_mer_id']) && $h['self_mer_id'] > 0 && $h['self_mer_id'] <= 4294967295, 'NHECO_FIXED_SOURCE_SCOPE_SELF');
    $c = $h['context'];
    nheco_expect(is_array($c) && $c['run_id'] === $h['run_id'] && $c['source_sha'] === NHECO_APP_SOURCE
        && preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $h['run_id']) === 1 && $c['project'] === 'tianli-audit-' . $h['run_id']
        && getenv('AUDIT_RUN_ID') === $h['run_id'] && getenv('AUDIT_PROJECT') === $c['project']
        && getenv('AUDIT_SOURCE_SHA') === NHECO_APP_SOURCE && getenv('AUDIT_REPO_DIR') === '/workspace'
        && getenv('AUDIT_RUN_DIR') === '/run/audit', 'NHECO_ORIGINAL_RUN_ENV');
    nheco_keys($h['predecessors'], ['context','freeze','manifest','reader_created','reader_result','reader_terminal','reader_retired',
        'source_pins','source_publication','source_review','producer_result']);
    $held = []; $prior = [];
    foreach ($h['predecessors'] as $name => $pointer) {
        $raw = nheco_pointer($pointer); $held[$pointer['path']] = $raw; $prior[$name] = nheco_object($raw);
    }
    nheco_expect($prior['context'] === $c && $h['predecessors']['context']['path'] === '/run/audit/actual-run-context.json'
        && $h['predecessors']['freeze']['path'] === '/run/audit/preparation-source-binding.json'
        && $h['predecessors']['manifest']['path'] === '/run/audit/manifest.json'
        && $h['predecessors']['freeze']['sha256'] === $c['freeze_sha256']
        && $prior['freeze']['source_sha'] === NHECO_APP_SOURCE && $prior['freeze']['requested_repo_source'] === $c['repo_dir'], 'NHECO_UNCHANGED_CONTEXT_FREEZE');
    $reader = $prior['reader_result']; $made = $prior['reader_created']; $terminal = $prior['reader_terminal']; $retired = $prior['reader_retired'];
    nheco_expect($reader['schema'] === 1 && $reader['status'] === 'VERIFIED_CURRENT_LEGACY_HISTORY_READS_NORMAL_CLOSED'
        && $reader['scope'] === 'CURRENT_SOURCE_SINGLE_SELF_LEGACY_READS_ONLY'
        && $reader['run_id'] === $h['run_id'] && $reader['source_sha'] === NHECO_APP_SOURCE
        && $reader['freeze_sha256'] === $c['freeze_sha256'] && $reader['container_id'] === $made['id']
        && $reader['feature_profile'] === ['enabled' => false, 'self_operated_mer_id' => $h['self_mer_id'], 'scope' => 'STRUCTURE_ONLY_NOT_BACKFILLED']
        && count($reader['requests']) === 9 && $reader['normal_http_close']['stopped'] === true
        && $reader['normal_http_close']['remaining_members'] === [] && $reader['normal_http_close']['listener_open'] === false,
        'NHECO_REAL_ORIGINAL_READER_NORMAL_CLOSED');
    nheco_expect($made['status'] === 'created' && $made['pid'] === 0 && $made['proof_name'] === 'legacy-current-reader'
        && $terminal['id'] === $made['id'] && $terminal['status'] === 'exited' && $terminal['exit_code'] === 0
        && $terminal['pid'] === 0 && $terminal['restart_count'] === 0, 'NHECO_ORIGINAL_READER_NATIVE_ZERO');
    nheco_expect($retired['schema'] === 1 && $retired['scope'] === 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED'
        && $retired['run_id'] === $h['run_id'] && $retired['source_sha'] === NHECO_APP_SOURCE
        && $retired['freeze_sha256'] === $c['freeze_sha256'] && $retired['role'] === 'audit-legacy-current-reader'
        && $retired['proof_name'] === 'legacy-current-reader' && $retired['container_id'] === $made['id'], 'NHECO_READER_RETIRED_IDENTITY');
    foreach (['created' => 'reader_created', 'terminal' => 'reader_terminal', 'result' => 'reader_result'] as $key => $name)
        nheco_expect($retired[$key] === array_diff_key($h['predecessors'][$name], ['host_path' => true]), 'NHECO_RETIRED_ORIGINAL_FILE_LINK');
    foreach (['binding','normal_remove','resource_after'] as $key) {
        $p = $retired[$key]; nheco_keys($p, ['path','bytes','sha256']); $raw = nheco_read($p['path'], $p); $held[$p['path']] = $raw;
        if ($key === 'normal_remove') {
            $v = nheco_object($raw); nheco_expect($v['stage'] === 'legacy-current-reader-exact-nonforce-remove' && $v['native_exit'] === 0
                && $v['stdout_bytes'] === 65 && $v['stdout_sha256'] === hash('sha256', $made['id'] . "\n")
                && $v['stderr_bytes'] === 0 && $v['stderr_sha256'] === hash('sha256', ''), 'NHECO_READER_ACTUAL_NONFORCE_REMOVE');
        }
    }
    nheco_keys($h['resources'], ['services','volumes','network']);
    nheco_expect($h['resources']['services'] === $c['initial_service_created'] && count($h['resources']['services']) === 2
        && $h['resources']['volumes'] === $c['initial_volumes'] && count($h['resources']['volumes']) === 6
        && $h['resources']['network'] === $c['initial_network'], 'NHECO_ORIGINAL_TWO_SIX_ONE_RESOURCES');
    foreach ($h['dependencies'] as $row) {
        $raw = nheco_pointer($row); $held[$row['path']] = $raw;
        nheco_expect(str_starts_with($row['path'], '/workspace/'), 'NHECO_FROZEN_APP_DEPENDENCY');
        nheco_expect(nheco_read('/source/' . substr($row['path'], 11), $row) === $raw, 'NHECO_BOTH_APP_SOURCE_ALIASES');
    }
    foreach ($h['history_artifacts'] as $row) { $held[$row['path']] = nheco_pointer($row); }
    nheco_expect(count($h['history_artifacts']) === 14 && nheco_pointer($h['actor']) === nheco_read(__FILE__), 'NHECO_COMPLETE_OLD14_AND_NEW_ACTOR');
    require_once '/workspace/help/audit-regressions/isolated/ms4-migration.php';
    $createdRaw = nheco_read('/run/post-reader/created.json'); $created = nheco_object($createdRaw);
    $binding = nheco_object(nheco_read('/run/post-reader/source-binding.json'));
    nheco_keys($binding, ['schema','scope','run_id','app_source_sha','container_id','handoff','created','actor']);
    nheco_expect($binding === ['schema' => 1, 'scope' => 'POST_READER_SYNTHETIC_OWNERSHIP_SOURCE_BOUND_V1',
        'run_id' => $h['run_id'], 'app_source_sha' => NHECO_APP_SOURCE, 'container_id' => $created['id'],
        'handoff' => nheco_pin($handoffRaw), 'created' => nheco_pin($createdRaw), 'actor' => nheco_pin(nheco_read(__FILE__))], 'NHECO_OWN_SOURCE_BINDING');
    nheco_expect($created['hostname'] === gethostname() && gethostname() === substr($created['id'], 0, 12)
        && $created['image'] === NHECO_IMAGE && $created['status'] === 'created' && $created['pid'] === 0
        && $created['restart_count'] === 0 && $created['user'] === '0:0' && $created['read_only'] === true
        && $created['privileged'] === false && $created['cap_drop'] === ['ALL']
        && in_array($created['security_options'], [['no-new-privileges'],['no-new-privileges:true']], true)
        && $created['entrypoint'] === ['php'] && $created['workdir'] === '/workspace/crmeb'
        && $created['command'] === [NHECO_ENTRY, 'normal', '--handoff-bytes=' . strlen($handoffRaw), '--handoff-sha256=' . hash('sha256', $handoffRaw)]
        && $created['labels']['com.docker.compose.project'] === $c['project']
        && $created['labels']['com.docker.compose.service'] === $h['role']['service']
        && $created['labels']['tianli.audit'] === '1' && $created['labels']['tianli.audit.run'] === $h['run_id']
        && !$created['port_bindings'] && array_keys($created['networks']) === [$c['initial_network']['Name']], 'NHECO_OWN_REAL_FIXED_CONTAINER');
    nheco_expect($prior['source_pins']['source_sha'] === NHECO_APP_SOURCE
        && $prior['source_review']['schema'] === 1
        && $prior['source_review']['status'] === 'PASS_CURRENT_HISTORY_SOURCE_REVIEW'
        && $prior['source_review']['source_sha'] === NHECO_APP_SOURCE
        && $prior['source_publication']['schema'] === 1
        && $prior['source_publication']['scope'] === 'CURRENT_HISTORY_SOURCE_PUBLICATION'
        && $prior['source_publication']['source_sha'] === NHECO_APP_SOURCE, 'NHECO_ORIGINAL_REVIEWED_HISTORY_PUBLICATION');
    $expected = ['/workspace' => ['bind',$c['repo_dir'],false], '/source' => ['bind',$c['repo_dir'],false],
        '/run/nonempty' => ['bind',$c['prep_dir'],false], '/run/audit' => ['bind',$c['run_dir'],false],
        '/run/post-reader' => ['bind',$h['role']['source_directory'],false],
        '/run/post-reader-output' => ['bind',$h['role']['output_directory'],true],
        '/run/audit-app' => ['volume',$c['project'] . '_app-secrets',false],
        '/run/audit-bootstrap' => ['volume',$c['project'] . '_bootstrap-secrets',false],
        '/run/audit-fixtures' => ['volume',$c['project'] . '_fixture-secrets',false]];
    $mounts = [];
    foreach ($created['mounts'] as $mount) {
        if ($mount['Type'] === 'tmpfs') {
            nheco_expect(in_array($mount['Destination'], ['/tmp','/current-runtime'], true) && $mount['RW'] === true, 'NHECO_ONLY_OWN_TMPFS'); continue;
        }
        nheco_expect(isset($expected[$mount['Destination']]) && !isset($mounts[$mount['Destination']]), 'NHECO_EXACT_MOUNT_TARGET');
        [$kind,$source,$rw] = $expected[$mount['Destination']];
        nheco_expect($mount['Type'] === $kind && $mount['RW'] === $rw
            && ($kind === 'volume' ? $mount['Name'] === $source
                : audit_canonical_host_bind_source($mount['Source']) === audit_canonical_host_bind_source($source)), 'NHECO_EXACT_MOUNT_SOURCE_ACCESS');
        $mounts[$mount['Destination']] = true;
    }
    nheco_expect(count($mounts) === count($expected), 'NHECO_COMPLETE_CLOSED_MOUNTS');
    $tmp = array_keys($created['tmpfs']); sort($tmp, SORT_STRING);
    nheco_expect($tmp === ['/current-runtime','/tmp'], 'NHECO_ONLY_TWO_TMPFS');
    foreach ($created['tmpfs'] as $path => $value) {
        $parts = explode(',', $value); sort($parts, SORT_STRING);
        nheco_expect($parts === [$path === '/tmp' ? 'mode=1777' : 'mode=0700','nodev','nosuid','rw'], 'NHECO_PRIVATE_TMPFS_ACCESS');
    }
    audit_guard_assert();
    return ['handoff' => $h, 'handoff_pin' => nheco_pin($handoffRaw), 'prior' => $prior, 'held' => $held,
        'created' => $created, 'freeze' => $prior['freeze'], 'container_id' => $created['id']];
}

/** Only definitions are included in the parent; the old reader router is never included by HTTP. */
function nheco_libraries(bool $http): void
{
    require_once '/run/nonempty/legacy-app.php';
    require_once '/run/nonempty/legacy-actors.php';
    require_once '/run/nonempty/legacy-history-producer.php';
    if (!$http) require_once '/run/nonempty/legacy-current-reader.php';
}

function nheco_includes(array $state): void
{
    $pins = [];
    foreach ($state['freeze']['current_reader']['source_files'] as $row) {
        foreach (['/workspace','/source'] as $root) $pins[$root . '/' . $row['path']] = $row;
    }
    foreach ($state['handoff']['history_artifacts'] as $row) $pins[$row['path']] = $row;
    $pins[NHECO_ENTRY] = $state['handoff']['actor'];
    foreach (get_included_files() as $file) {
        nheco_expect(isset($pins[$file]), 'NHECO_NO_UNQUALIFIED_INCLUDED_SOURCE');
        nheco_read($file, $pins[$file]);
    }
}

/** HTTP uses the same native complete database view without loading the old router. */
function nheco_db(PDO $db, string $run): array
{
    if (function_exists('nhecr_db_capture')) return nhecr_db_capture($db, $run);
    m4lh_actor_identity($db, $run); $modes = nhep_database_modes($db);
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    nheco_expect(count($names) === 174 && count(array_unique($names)) === 174, 'NHECO_COMPLETE_174_TABLES');
    $database = ['names' => $names, 'modes' => $modes, 'tables' => []]; $allRows = [];
    foreach ($names as $name) {
        $metadata = m4m_metadata($db, $name); $columns = array_keys($metadata['columns']);
        $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($name));
        usort($rows, static fn(array $a,array $b): int => strcmp(serialize($a),serialize($b)));
        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);
        nheco_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'NHECO_COMPLETE_DDL');
        $database['tables'][$name] = ['metadata' => $metadata, 'ddl' => $ddl[1], 'projected_columns' => $columns,
            'projection' => m4lh_actor_projection($rows)]; $allRows[$name] = $rows;
    }
    nheco_expect(nhep_database_modes($db) === $modes, 'NHECO_OBSERVER_MODES_UNCHANGED');
    return ['database' => $database, 'rows' => $allRows];
}

function nheco_db_pin(array $view): array
{
    return nheco_pin(nheco_json($view));
}
function nheco_cache_same(array $before, array $after): void
{
    nheco_expect($after['snapshot'] === nhep_redis_expected($before['snapshot'], [], $after['snapshot']['time']), 'NHECO_ONLY_PASSIVE_REDIS_EXPIRY');
}
function nheco_files(): array
{
    return ['app' => nhecr_file_tree('/run/audit-app'), 'fixtures' => nhecr_file_tree('/run/audit-fixtures'),
        'public' => nhecr_file_tree('/workspace/crmeb/public')];
}

/** A declared fixture registration, separate from the later owner-only migration. */
function nheco_attachment(PDO $db, array $state, array $before): array
{
    nheco_expect($before['rows']['eb_system_attachment'] === [] && $before['rows']['eb_system_attachment_category'] === [],
        'NHECO_ORIGINAL_EMPTY_ATTACHMENT_TABLES');
    $path = '/statics/system_images/spread_level_1.png';
    $image = nheco_read('/workspace/crmeb/public' . $path, ['bytes' => 53659,
        'sha256' => '24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d']);
    nheco_expect(nheco_read('/source/crmeb/public' . $path) === $image, 'NHECO_ORIGINAL_IMAGE_BOTH_SOURCE_ALIASES');
    $decoded = imagecreatefromstring($image);
    nheco_expect($decoded instanceof GdImage, 'NHECO_ACTUAL_PNG_DECODE');
    try { nheco_expect(imagesx($decoded) === 690 && imagesy($decoded) === 290, 'NHECO_ACTUAL_PNG_DIMENSIONS'); }
    finally { imagedestroy($decoded); }
    $meta = $before['database']['tables']['eb_system_attachment']; $id = m4lh_actor_next($meta, 'att_id'); $now = time();
    $data = ['name' => 'spread_level_1.png', 'real_name' => 'spread_level_1.png', 'att_dir' => $path, 'satt_dir' => $path,
        'att_size' => '53659', 'att_type' => 'image/png', 'pid' => 0, 'time' => $now, 'image_type' => 1,
        'module_type' => 1, 'type' => 0, 'mer_id' => $state['handoff']['self_mer_id']];
    $row = m4lh_actor_expected_row($meta['metadata'], ['att_id' => $id] + $data);
    $expected = nhecr_db_expected($before, ['eb_system_attachment' => [$row]], ['eb_system_attachment' => 1]);
    nheco_expect(nheco_db($db, $state['handoff']['run_id']) === $before, 'NHECO_ATTACHMENT_ORIGINAL_FULL_STATE');
    nheco_expect($db->beginTransaction(), 'NHECO_ATTACHMENT_BEGIN'); $committed = false;
    try {
        nheco_expect(m4m_rows($db, 'SELECT * FROM eb_system_attachment ORDER BY att_id FOR UPDATE') === [],
            'NHECO_ATTACHMENT_LOCKED_ORIGINAL_EMPTY_ROWS');
        $keys = array_keys($data); $sql = 'INSERT INTO eb_system_attachment (' . implode(',', array_map('m4m_quote', $keys))
            . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        $statement = $db->prepare($sql); nheco_expect($statement->execute(array_values($data)) && $statement->rowCount() === 1
            && (string)$db->lastInsertId() === (string)$id, 'NHECO_ONE_ACTUAL_ATTACHMENT_AUTO_ID');
        nheco_expect(m4m_rows($db, 'SELECT * FROM eb_system_attachment ORDER BY att_id') === [$row],
            'NHECO_ONLY_DECLARED_TRANSACTION_ATTACHMENT_ROW');
        nheco_expect($db->commit(), 'NHECO_ATTACHMENT_COMMIT'); $committed = true;
        nheco_expect(nheco_db($db, $state['handoff']['run_id']) === $expected, 'NHECO_ATTACHMENT_POSTCOMMIT_FULL_READBACK');
    } finally { if (!$committed && $db->inTransaction()) $db->rollBack(); }
    return ['view' => $expected, 'receipt' => ['scope' => 'ONE_DECLARED_SYNTHETIC_ORIGINAL_PNG_ATTACHMENT_ONLY',
        'attachment_id' => $id, 'pid' => 0, 'self_mer_id' => $state['handoff']['self_mer_id'], 'image' => nheco_pin($image),
        'mime' => 'image/png', 'width' => 690, 'height' => 290, 'before' => nheco_db_pin($before), 'after' => nheco_db_pin($expected),
        'only_declared_row_and_counter_changed' => true, 'original_product_sku_transactions_and_image_bytes_unchanged' => true]];
}

function nheco_private_new(string $path, string $raw): void
{
    nheco_expect(str_starts_with($path, '/tmp/post-reader-private/'), 'NHECO_NEW_PRIVATE_INPUT_ONLY');
    $f = fopen($path, 'xb'); nheco_expect(is_resource($f), 'NHECO_CREATE_ONLY_PRIVATE_FILE');
    try { nheco_expect(fwrite($f, $raw) === strlen($raw) && fflush($f), 'NHECO_PRIVATE_FULL_WRITE'); }
    finally { fclose($f); }
    nheco_expect(chmod($path, 0600) && nheco_read($path) === $raw, 'NHECO_PRIVATE_FULL_READBACK');
}

/** Execute the real frozen maintenance CLI in a fresh native PHP process. */
function nheco_ms2(array $state, string $mode, bool $mapping, array &$children): array
{
    audit_guard_assert(); $number = count($children) + 1; $prefix = '/tmp/post-reader-private/cli-' . $number;
    $argv = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1',
        '/workspace/help/migrations/marketplace-ms2.php', $mode, '--repo-root=/workspace',
        '--config-file=/tmp/post-reader-private/config.json'];
    if ($mapping) $argv[] = '--mapping=/tmp/post-reader-private/mapping.json';
    if (in_array($mode, ['backfill','enforce'], true)) $argv[] = '--quarantine-dir=/tmp/post-reader-private/quarantine';
    if ($mode === 'enforce') $argv[] = '--maintenance-confirmed';
    foreach (['stdout','stderr'] as $stream) nheco_private_new($prefix . '.' . $stream, '');
    $process = proc_open($argv, [['file','/dev/null','r'], ['file',$prefix . '.stdout','w'], ['file',$prefix . '.stderr','w']], $pipes, '/workspace');
    nheco_expect(is_resource($process), 'NHECO_REAL_MAINTENANCE_CHILD'); $first = proc_get_status($process);
    nheco_expect(is_array($first) && is_int($first['pid']) && $first['pid'] > 0, 'NHECO_MAINTENANCE_NATIVE_PID');
    $close = proc_close($process); $exit = $close; $origin = 'proc_close';
    if ($close === -1 && $first['running'] === false && $first['signaled'] === false && $first['exitcode'] >= 0) {
        $exit = $first['exitcode']; $origin = 'first_actual_terminal';
    }
    $stdout = nheco_read($prefix . '.stdout', null, 33554432); $stderr = nheco_read($prefix . '.stderr', null, 65536);
    $firstPublic = array_intersect_key($first, array_flip(['pid','running','signaled','stopped','exitcode','termsig','stopsig']));
    $receipt = ['sequence' => $number, 'mode' => $mode, 'pid' => $first['pid'], 'first_native' => $firstPublic,
        'proc_close_exit' => $close, 'native_exit' => $exit, 'native_exit_origin' => $origin,
        'script' => nheco_pin(nheco_read('/workspace/help/migrations/marketplace-ms2.php')),
        'stdout' => nheco_pin($stdout), 'stderr' => nheco_pin($stderr), 'private_arguments_and_values_omitted' => true];
    $children[] = $receipt;
    nheco_new('/run/post-reader-output/ms2-' . $number . '-native.json', nheco_json($receipt));
    // The CLI's fixed public projection contains hashes/counts, never private mapping/config bytes.
    if ($exit === 0 && $stderr === '') {
        $value = nheco_object($stdout);
        nheco_expect($value['mode'] === $mode && $value['native_exit'] === 0 && $value['state'] === 'completed'
            && $value['enabled'] === false && $value['self_mer_id'] === $state['handoff']['self_mer_id'], 'NHECO_MAINTENANCE_SAME_REAL_SELF');
        nheco_new('/run/post-reader-output/ms2-' . $number . '-result.json', $stdout); audit_guard_assert(); return $value;
    }
    throw new RuntimeException('NHECO_MAINTENANCE_CHILD_FAILED_STATE_PRESERVED');
}

function nheco_groups(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $owner = $row['mer_id'] === null ? null : (int)$row['mer_id']; $key = $owner === null ? 'NULL' : 'N' . $owner;
        if (!isset($groups[$key])) $groups[$key] = ['mer_id' => $owner, 'count' => 0]; ++$groups[$key]['count'];
    }
    $groups = array_values($groups); usort($groups, static fn(array $a,array $b): int => $a['mer_id'] === null
        ? ($b['mer_id'] === null ? 0 : -1) : ($b['mer_id'] === null ? 1 : $a['mer_id'] <=> $b['mer_id'])); return $groups;
}
function nheco_mapping(array $state, array $check, array $view, int $attachmentId): array
{
    nheco_expect($check['changed'] === false && $check['ownership_phase'] === 'structure'
        && is_array($check['mapping_binding'] ?? null) && is_array($check['mapping_expectations'] ?? null), 'NHECO_ACTUAL_CHECK_MAPPING_BINDING');
    $self = $state['handoff']['self_mer_id']; $counts = ['store_product'=>1,'system_attachment'=>1,
        'system_attachment_category'=>0,'shipping_templates'=>1,'store_product_rule'=>0]; $tables = [];
    foreach (NHECO_ROOTS as $logical => $pk) {
        $rows = $view['rows']['eb_' . $logical]; usort($rows, static fn(array $a,array $b): int => $a[$pk] <=> $b[$pk]);
        nheco_expect(count($rows) === $counts[$logical], 'NHECO_ONLY_DECLARED_CURRENT_RUN_ROOT_ROWS');
        $hashes = []; $decisions = [];
        foreach ($rows as $row) {
            if ($logical === 'store_product') nheco_expect($row['store_name'] === '旧历史商品-' . $state['handoff']['run_id']
                && (int)$row['mer_id'] === $self, 'NHECO_ORIGINAL_DECLARED_PRODUCT_OWNER');
            if ($logical === 'shipping_templates') nheco_expect((int)$row['id'] === 1 && $row['name'] === '默认模版'
                && $row['mer_id'] === null, 'NHECO_EXPLICIT_SYNTHETIC_ORIGINAL_TEMPLATE_DECISION');
            if ($logical === 'system_attachment') nheco_expect((int)$row['att_id'] === $attachmentId
                && (int)$row['mer_id'] === $self && (int)$row['pid'] === 0, 'NHECO_ONE_NEW_REGISTERED_ATTACHMENT');
            $withoutOwner = $row; unset($withoutOwner['mer_id']);
            $hashes[] = hash('sha256', json_encode($withoutOwner, JSON_THROW_ON_ERROR));
            $decisions[] = ['pk' => (int)$row[$pk], 'from_mer_id' => $row['mer_id'] === null ? null : (int)$row['mer_id'], 'to_mer_id' => $self];
        }
        $hash = hash('sha256', implode("\n", $hashes)); $before = nheco_groups($rows);
        nheco_expect($check['mapping_expectations'][$logical] === ['count' => count($rows), 'non_owner_rows_sha256' => $hash, 'groups' => $before],
            'NHECO_NATIVE_ROWS_MATCH_ACTUAL_CLI_EXPECTATIONS');
        $tables[$logical] = ['count' => count($rows), 'non_owner_rows_sha256' => $hash,
            'before_groups' => $before, 'after_groups' => $rows === [] ? [] : [['mer_id' => $self, 'count' => count($rows)]], 'rows' => $decisions];
    }
    $map = ['schema' => 1, 'version' => 'post-reader-synthetic-' . $state['handoff']['run_id'],
        'source' => $check['mapping_binding']['source'], 'binding' => $check['mapping_binding']['binding'],
        'confirmation' => ['confirmed' => true, 'by' => 'EXPLICIT_POST_READER_SYNTHETIC_FIXTURE_DECLARATION',
            'at' => gmdate('Y-m-d\TH:i:s\Z'), 'reference' => $state['handoff_pin']['sha256']],
        'tables' => $tables, 'image_links' => [], 'attachment_category_links' => []];
    nheco_private_new('/tmp/post-reader-private/mapping.json', nheco_json($map)); return $map;
}

function nheco_owner_model(array $before, int $self): array
{
    $changed = [];
    foreach (NHECO_ROOTS as $logical => $pk) {
        $name = 'eb_' . $logical; $rows = $before['rows'][$name];
        foreach ($rows as &$row) $row['mer_id'] = m4lh_actor_native($before['database']['tables'][$name]['metadata']['columns']['mer_id'], $self);
        unset($row); $changed[$name] = $rows;
    }
    return nhecr_db_expected($before, $changed);
}
function nheco_enforced_ddl(string $ddl): string
{
    $count = 0; $lines = [];
    foreach (explode("\n", $ddl) as $line) {
        if (str_starts_with($line, '  `mer_id` ')) {
            $line = preg_replace('/\A(  `mer_id` int(?:\([0-9]+\))? unsigned)(?: NOT NULL)?(?: DEFAULT (?:NULL|\x270\x27|0))?(.*)\z/u',
                '$1 NOT NULL$2', $line, 1, $matched); nheco_expect($matched === 1, 'NHECO_OWNER_DDL_COLUMN'); ++$count;
        }
        $lines[] = $line;
    }
    nheco_expect($count === 1, 'NHECO_EXACT_ONE_OWNER_DDL_COLUMN'); return implode("\n", $lines);
}
function nheco_enforce_model(array $before): array
{
    $expected = $before;
    foreach (NHECO_ROOTS as $logical => $pk) {
        $name = 'eb_' . $logical; $expected['database']['tables'][$name]['metadata']['columns']['mer_id']['IS_NULLABLE'] = 'NO';
        $expected['database']['tables'][$name]['metadata']['columns']['mer_id']['COLUMN_DEFAULT'] = null;
        $expected['database']['tables'][$name]['ddl'] = nheco_enforced_ddl($before['database']['tables'][$name]['ddl']);
    }
    return $expected;
}
function nheco_clear(array $result): void
{
    $inventory = $result['ownership_after'] ?? $result['ownership'] ?? null;
    nheco_expect(is_array($inventory) && is_array($inventory['counts'] ?? null)
        && is_array($inventory['orphan_child_rows']['tables'] ?? null) && $inventory['orphan_child_rows']['total'] === 0, 'NHECO_FULL_OWNER_INVENTORY');
    foreach (array_merge(array_values($inventory['counts']), array_values($inventory['orphan_child_rows']['tables'])) as $count)
        nheco_expect(is_int($count) && $count === 0, 'NHECO_ALL_OWNER_REFERENCE_ISSUES_ZERO');
}

function nheco_maintenance(array $state, PDO $db, Redis $redis): array
{
    nheco_expect(!file_exists('/tmp/post-reader-private') && mkdir('/tmp/post-reader-private', 0700)
        && mkdir('/tmp/post-reader-private/quarantine', 0700), 'NHECO_NEW_PRIVATE_MAINTENANCE_INPUTS');
    $migrator = nheco_object(nheco_read('/run/audit-bootstrap/migrator.json', null, 65536));
    nheco_expect($migrator['username'] === 'audit_migrator' && is_string($migrator['password']), 'NHECO_ORIGINAL_MAINTENANCE_PRINCIPAL');
    $config = ['hostname'=>'audit-mysql','hostport'=>'3306','database'=>'audit_auth','username'=>$migrator['username'],
        'password'=>$migrator['password'],'prefix'=>'eb_','marketplace'=>['enabled'=>false,'self_operated_mer_id'=>$state['handoff']['self_mer_id']]];
    nheco_private_new('/tmp/post-reader-private/config.json', nheco_json($config)); unset($config,$migrator);
    $original = nheco_db($db, $state['handoff']['run_id']); $cache = nhep_redis_capture($redis, $state['handoff']['run_id']); $files = nheco_files();
    $seed = nheco_attachment($db, $state, $original); $accepted = $seed['view']; $children = []; $steps = [];
    $check = nheco_ms2($state, 'check', false, $children);
    nheco_expect(nheco_db($db, $state['handoff']['run_id']) === $accepted, 'NHECO_BINDING_CHECK_IS_READ_ONLY');
    $map = nheco_mapping($state, $check, $accepted, $seed['receipt']['attachment_id']);
    foreach ([['check',false],['backfill',false],['backfill',false],['enforce',true],['enforce',true],['check',true]] as $index => [$mode,$mustEnforced]) {
        $result = nheco_ms2($state, $mode, true, $children); $expected = $accepted;
        if ($index === 1) $expected = nheco_owner_model($accepted, $state['handoff']['self_mer_id']);
        if ($index === 3) $expected = nheco_enforce_model($accepted);
        $actual = nheco_db($db, $state['handoff']['run_id']);
        nheco_expect($actual === $expected, 'NHECO_FULL174_ONLY_DECLARED_OWNER_OR_CONSTRAINT_CHANGE');
        if ($mode === 'backfill' || $mode === 'enforce' || $mustEnforced) nheco_clear($result);
        if ($mustEnforced) nheco_expect($result['ownership_phase'] === 'enforced' && $result['migration_complete'] === true,
            'NHECO_ACTUAL_FINAL_OWNER_CONSTRAINTS_COMPLETE');
        nheco_expect($result['changed'] === in_array($index, [1,3], true), 'NHECO_ONLY_FIRST_BACKFILL_AND_ENFORCE_CHANGE');
        if ($mode === 'backfill') nheco_expect($result['updated_rows'] === ($index === 1 ? 1 : 0), 'NHECO_ONLY_DECLARED_TEMPLATE_OWNER_UPDATED');
        $steps[] = ['mode'=>$mode,'changed'=>$result['changed'],'view'=>nheco_db_pin($actual)]; $accepted = $actual;
    }
    nheco_cache_same($cache, nhep_redis_capture($redis, $state['handoff']['run_id']));
    nheco_expect(nheco_files() === $files, 'NHECO_ALL_ORIGINAL_PRIVATE_PUBLIC_FILES_UNCHANGED');
    return ['view'=>$accepted, 'receipt'=>['attachment'=>$seed['receipt'],'children'=>$children,'steps'=>$steps,
        'mapping_pin'=>nheco_pin(nheco_json($map)), 'complete_nonowner_and_legacy_transactions_preserved'=>true,
        'five_roots_enforced'=>true,'maintenance_feature_enabled'=>false]];
}

function nheco_private_actors(array $state): array
{
    $proofs = []; $receipt = nhecr_actor_prepare_receipt($state['handoff']['context'], $proofs);
    $proof = $receipt['prepare_proof']; $path = '/run/audit-fixtures/nonempty-history-actors.json';
    $raw = nheco_read($path, null, 4096); $facts = nheco_stat(lstat($path));
    nheco_expect($facts === nhecr_stat9($proof['stat9']) && ($facts['mode'] & 07777) === 0600
        && $facts['uid'] === posix_geteuid(), 'NHECO_ORIGINAL_PREPARE_PRIVATE_ACTORS_STAT9');
    $private = nheco_object($raw); m4lh_actor_private($private); unset($raw); return $private;
}
function nheco_fixture(array $state, array $view, #[SensitiveParameter] array $private): array
{
    $run = $state['handoff']['run_id']; $self = $state['handoff']['self_mer_id'];
    $user = nhepcd_one($view, 'eb_user', ['account' => $private['user_account']])['row'];
    nheco_expect($user['pwd'] === md5($private['user_password']), 'NHECO_ORIGINAL_NATIVE_CUSTOMER_CREDENTIAL');
    $product = nhepcd_one($view, 'eb_store_product', ['store_name' => '旧历史商品-' . $run])['row'];
    $sku = nhepcd_one($view, 'eb_store_product_attr_value', ['unique' => substr(hash('sha256', 'legacy-sku-' . $run), 0, 8), 'type' => 0])['row'];
    $attribute = nhepcd_one($view, 'eb_store_product_attr', ['product_id' => $product['id'], 'type' => 0])['row'];
    $admin = nhepcd_one($view, 'eb_system_admin', ['account' => $private['admin_account']])['row'];
    $address = nhepcd_one($view, 'eb_user_address', ['uid' => $user['uid'], 'is_default' => 1])['row'];
    $category = nhepcd_one($view, 'eb_category', ['name' => 'normal-label-category-' . $run])['row'];
    $label = nhepcd_one($view, 'eb_user_label', ['label_name' => 'normal-purchase-label-' . $run])['row'];
    $express = nhepcd_one($view, 'eb_express', ['code' => 'shunfeng'])['row'];
    nheco_expect($product['mer_id'] === $self && $sku['product_id'] === $product['id'] && $sku['suk'] === '普通'
        && $product['image'] === '/statics/system_images/spread_level_1.png', 'NHECO_ORIGINAL_NATIVE_PRODUCT_SKU_IMAGE');
    // This is the original pure oracle's input schema, not an old reader role/created receipt.
    $fixture = ['schema'=>1,'scope'=>'CURRENT_READER_VALIDATED174_PRIVATE_FIXTURE_CONTEXT','run_id'=>$run,
        'legacy_source_sha'=>'477732e3e03ed4ba1dbb6b44a94dbba75f7e1978','self_operated_mer_id'=>$self,
        'uid'=>$user['uid'],'admin_id'=>$admin['id'],'address_id'=>$address['id'],'product_id'=>$product['id'],
        'attr_id'=>$attribute['id'],'sku_id'=>$sku['id'],'shipping_template_id'=>1,'express_id'=>$express['id'],
        'purchase_label_id'=>$label['id'],'category_id'=>$category['id'],'sku_unique'=>$sku['unique'],'sku_suk'=>$sku['suk'],
        'express_code'=>$express['code'],'express_name'=>$express['name'],'fixture_time'=>$user['add_time'],
        'price'=>'20.15','cost'=>'7.45','initial_stock'=>10,'qty_per_order'=>1,
        'public_image_path'=>'/statics/system_images/spread_level_1.png',
        'old_public_image_file'=>'crmeb/public/statics/system_images/spread_level_1.png',
        'manual_delivery'=>['type'=>1,'express_record_type'=>1,'delivery_code'=>'shunfeng','delivery_name'=>'顺丰速运']];
    $identities = nhecr_identities($view, $fixture, $state['prior']['producer_result']['http']);
    nheco_expect($identities === $state['prior']['reader_result']['identities'], 'NHECO_ALL_SAVED_LEGACY_IDENTITIES_UNCHANGED');
    foreach ($view['rows']['eb_store_order'] as $row)
        nheco_expect(array_key_exists('marketplace_trade_version', $row)
            && in_array($row['marketplace_trade_version'], [null,0,'0'], true), 'NHECO_NO_OLD_ORDER_CONVERTED_TO_V1');
    return $fixture;
}

/** Actual enabled App initialization, using only the original restricted App principal. */
function nheco_build_app(array $state): object
{
    nheco_expect(PHP_SAPI === 'cli-server' && spl_autoload_functions() === [] && !function_exists('app'), 'NHECO_FRESH_ENABLED_HTTP_APP_ONLY');
    foreach (get_declared_classes() as $class)
        nheco_expect(preg_match('/\A(?:app|think|crmeb|Composer)\\\\/i', $class) !== 1, 'NHECO_NO_APP_PRELOAD');
    $config = audit_config(); $database = $config['database']; $db = audit_pdo($database); $redis = audit_redis();
    nheco_expect($config['run_id'] === $state['handoff']['run_id'] && $database['username'] === 'audit_app'
        && $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false), 'NHECO_ACTUAL_DML_ONLY_APP_CONFIGURATION');
    $before = nheco_db($db, $state['handoff']['run_id']); $cache = nhep_redis_capture($redis, $state['handoff']['run_id']);
    $private = '/run/audit-app/legacy.env'; $privateRaw = nheco_read($private, null, 65536); $privateStat = nheco_stat(lstat($private));
    nheco_expect(($privateStat['mode'] & 07777) === 0600 && !file_exists('/workspace/crmeb/.env')
        && !file_exists('/source/crmeb/.env'), 'NHECO_PRIVATE_ORIGINAL_ENV_NO_SOURCE_ENV');
    require_once '/workspace/crmeb/vendor/autoload.php';
    $app = new \think\App('/workspace/crmeb/'); $app->setRuntimePath('/current-runtime/'); $app->env->load($private);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql',
        'DATABASE_HOSTPORT'=>'3306','DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>$database['username'],
        'DATABASE_PASSWORD'=>$database['password'],'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,
        'CACHE_DRIVER'=>'redis','CACHE_CACHE_PREFIX'=>'c:','CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
        'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_SELECT'=>'0','REDIS_REDIS_PASSWORD'=>$config['redis_password'],
        'MARKETPLACE_ENABLED'=>true,'MARKETPLACE_SELF_OPERATED_MER_ID'=>(string)$state['handoff']['self_mer_id']]);
    unset($config,$database); $app->initialize(); $connection = $app->db->connect();
    nheco_expect($connection->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS principal_name,run_id FROM audit_sentinel', [], true)
        === [['database_name'=>'audit_auth','principal_name'=>'audit_app@%','run_id'=>$state['handoff']['run_id']]],
        'NHECO_ACTUAL_APP_CONNECTION_DATABASE_PRINCIPAL_SENTINEL');
    $writer = $connection->getPdo();
    nheco_expect($writer instanceof PDO && $writer->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_app@%'
        && audit_grants_are_restricted($writer->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))
        && $app->getRootPath() === '/workspace/crmeb/' && $app->getRuntimePath() === '/current-runtime/'
        && $app->config->get('marketplace.enabled') === true
        && $app->config->get('marketplace.self_operated_mer_id') === (string)$state['handoff']['self_mer_id']
        && \app\services\marketplace\order\MarketplaceTradePolicy::active() === true, 'NHECO_ACTUAL_APP_ENABLED_TRUE_AND_ORIGINAL_SELF');
    nheco_expect(nheco_db($db, $state['handoff']['run_id']) === $before, 'NHECO_APP_INITIALIZATION_FULL_DB_UNCHANGED');
    nheco_cache_same($cache, nhep_redis_capture($redis, $state['handoff']['run_id']));
    nheco_expect(nheco_read($private) === $privateRaw && nheco_stat(lstat($private)) === $privateStat, 'NHECO_ORIGINAL_ENV_UNCHANGED');
    $profile = ['enabled'=>true,'self_operated_mer_id'=>$state['handoff']['self_mer_id'], 'app_source_sha'=>NHECO_APP_SOURCE,
        'container_id'=>$state['container_id'],'handoff_sha256'=>$state['handoff_pin']['sha256']];
    $file = '/tmp/post-reader-private/app-profile.json'; $raw = nheco_json($profile);
    if (!file_exists($file)) nheco_private_new($file, $raw);
    else nheco_expect(nheco_read($file) === $raw, 'NHECO_SAME_ACTUAL_APP_PROFILE_EACH_REQUEST');
    nheco_includes($state); audit_guard_assert(); return $app;
}

function nheco_router(): bool
{
    ini_set('display_errors','0'); ini_set('log_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
    try {
        nheco_expect(PHP_SAPI === 'cli-server' && realpath($_SERVER['DOCUMENT_ROOT'] ?? '') === '/workspace/crmeb/public'
            && is_string(getenv('NHECO_PARENT_PID')) && (int)getenv('NHECO_PARENT_PID') === posix_getppid(), 'NHECO_OWN_HTTP_CHILD_PARENT_DOCROOT');
        nheco_memory(); $bytes = getenv('NHECO_HANDOFF_BYTES'); $sha = getenv('NHECO_HANDOFF_SHA256');
        nheco_expect(is_string($bytes) && preg_match('/\A[1-9][0-9]{0,9}\z/D',$bytes) === 1
            && is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/D',$sha) === 1, 'NHECO_HTTP_EXPLICIT_HANDOFF_ENV');
        $state = nheco_qualify(['bytes'=>(int)$bytes,'sha256'=>$sha], true); nheco_libraries(true);
        $uri = $_SERVER['REQUEST_URI'] ?? ''; $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $pid = $state['prior']['producer_result']['http']['product_id']; $ids = $state['prior']['reader_result']['identities'];
        $allowed = ['POST /api/login','GET /api/products?ids='.$pid.'&page=1&limit=20','GET /api/product/detail/'.$pid,
            'GET /api/reply/list/'.$pid.'?page=1&limit=20&type=0','GET /api/reply/config/'.$pid,
            'GET /api/order/detail/'.$ids['refund_family']['order_no'],'GET /api/order/detail/'.$ids['review_family']['order_no'],
            'GET /api/order/refund/detail/'.$ids['refund']['order_no'],'GET /statics/system_images/spread_level_1.png'];
        nheco_expect(in_array($method.' '.$uri, $allowed, true), 'NHECO_ONLY_ORIGINAL_NINE_NORMAL_HTTP_PATHS');
        if ($method.' '.$uri === 'GET /statics/system_images/spread_level_1.png') {
            nheco_read('/workspace/crmeb/public'.$uri, ['bytes'=>53659,'sha256'=>'24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d']);
            nheco_includes($state); return false;
        }
        defined('DS') || define('DS', DIRECTORY_SEPARATOR); $reference = nhep_http_time_reference();
        $app = nheco_build_app($state); $http = $app->http; $response = $http->run();
        nheco_includes($state); $response->send(); $http->end($response); nhep_http_time_reference($reference); nheco_includes($state); return true;
    } catch (Throwable $ignored) {
        if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
        echo "{\"status\":\"FAIL\",\"code\":\"NHECO_HTTP_UNAVAILABLE\"}\n"; return true;
    }
}

function nheco_child_start(array $state, array &$children): void
{
    nheco_expect($children === [] && !nhep_child_listener('http')['open'], 'NHECO_NEW_SINGLE_HTTP_CHILD');
    $supervisor = audit_app_proc_stat(getmypid()); nheco_expect(is_array($supervisor), 'NHECO_NATIVE_PARENT');
    $environment = getenv(); nheco_expect(is_array($environment), 'NHECO_REAL_PARENT_ENVIRONMENT');
    foreach (array_keys($environment) as $key)
        if (str_starts_with($key,'PHP_') || in_array($key,['PHPRC','PHP_INI_SCAN_DIR','TZ','TZDIR'],true)) unset($environment[$key]);
    $environment['NHECO_PARENT_PID'] = (string)getmypid();
    $environment['NHECO_HANDOFF_BYTES'] = (string)$state['handoff_pin']['bytes'];
    $environment['NHECO_HANDOFF_SHA256'] = $state['handoff_pin']['sha256'];
    $command = ['setsid',PHP_BINARY,'-S','127.0.0.1:8000','-t','/workspace/crmeb/public',NHECO_ENTRY];
    $children['http'] = ['kind'=>'http','process'=>null,'group'=>null,'supervisor'=>$supervisor,'command'=>$command,
        'streams'=>[],'first_native'=>null,'last_native'=>null,'first_terminal'=>null,'native_pid'=>null,'observation'=>null,
        'term_attempted'=>false,'term_sent'=>false,'waits'=>[],'closed'=>null,'subscriber'=>null,
        'run_id'=>$state['handoff']['run_id'],'http_time_reference'=>nhep_http_time_reference(),'phase'=>'private_streams'];
    $child =& $children['http'];
    foreach (['stdout','stderr'] as $name) {
        $path = '/current-runtime/current-http.' . $name; $handle = fopen($path,'x+b');
        nheco_expect(is_resource($handle) && chmod($path,0600), 'NHECO_OWN_PRIVATE_CREATE_ONLY_HTTP_STREAM');
        $created = fstat($handle); nheco_expect(nheco_stat($created) === nheco_stat(lstat($path)), 'NHECO_OWN_STREAM_PATH_HANDLE');
        $child['streams'][$name] = ['path'=>$path,'handle'=>$handle,'created'=>$created];
    }
    $child['phase']='native_start'; $process = proc_open($command,[['file','/dev/null','r'],
        $child['streams']['stdout']['handle'],$child['streams']['stderr']['handle']],$pipes,'/workspace/crmeb',$environment);
    $child['process']=$process; unset($environment); nheco_expect(is_resource($process), 'NHECO_ACTUAL_HTTP_PROC_OPEN');
    $until = hrtime(true) + 5000000000; $ready = false;
    do {
        $native = nhep_child_native($child); if (!$native['running']) break;
        $group = nhep_child_group($child,$native);
        if ($group !== null) {
            $child['group']=$group; $members=audit_app_group_members($group,$supervisor); $listener=nhep_child_listener('http');
            if (count($members)===1 && count($listener['rows'])===1) { nhep_child_observation($children,'http'); $ready=true; break; }
        }
        usleep(10000);
    } while (hrtime(true)<$until);
    nheco_expect($ready,'NHECO_SAME_HANDLE_SINGLE_HTTP_READINESS'); $child['phase']='native_ready';
}

function nheco_capture(array $state, PDO $db, Redis $redis, array &$children, array $fixture): array
{
    $start=time(); $first = nhep_child_observation($children,'http'); $database=nheco_db($db,$state['handoff']['run_id']);
    $cache=nhep_redis_capture($redis,$state['handoff']['run_id']);
    $snapshot=['database'=>$database['database'],'rows'=>$database['rows'],'redis'=>$cache['snapshot'],
        'private'=>['app'=>nhecr_file_tree('/run/audit-app'),'fixtures'=>nhecr_file_tree('/run/audit-fixtures')],
        'public'=>['current_public'=>nhecr_file_tree('/workspace/crmeb/public')],
        'logs'=>['runtime'=>nhecr_file_tree('/current-runtime'),'current_root_runtime'=>nhecr_file_tree('/workspace/crmeb/runtime')], 'native'=>[]];
    $streams=nhecr_stream_capture($children); $last=nhep_child_observation($children,'http');
    nheco_expect($first['native']===$last['native'] && $first['group']===$last['group'],'NHECO_SAME_HTTP_HANDLE_DURING_COMPLETE_CAPTURE');
    $snapshot['native']['http']=$last['native']; nhecr_files_streams(['native_streams'=>$streams],$snapshot['logs']['runtime']);
    nheco_includes($state);
    return ['snapshot'=>$snapshot,'private'=>['redis_native'=>$cache['native'],'fixture'=>$fixture,
        'self_operated_mer_id'=>$state['handoff']['self_mer_id'],'actual_interval'=>[$start,time()],
        'native_before'=>['http'=>$first],'native_after'=>['http'=>$last],'native_streams'=>$streams,
        'http_time_reference'=>nhep_http_time_reference($children['http']['http_time_reference'])]];
}
function nheco_child_close(array &$children): array
{
    $receipt=nhep_child_close($children,'http');
    nheco_expect($receipt['stopped']===true && $receipt['remaining_members']===[] && $receipt['listener_open']===false
        && $receipt['term_attempted']===true && $receipt['stop_signal']===SIGTERM
        && $receipt['first_terminal']['running']===false && $receipt['first_terminal']['signaled']===true
        && $receipt['first_terminal']['termsig']===SIGTERM && $children['http']['process']===null,'NHECO_HTTP_NORMAL_TERM_COMPLETE');
    foreach (glob('/proc/[0-9]*/stat') as $file) {
        $row=audit_app_proc_stat((int)basename(dirname($file)));
        if ($row!==null) nheco_expect($row['pid']===getmypid(),'NHECO_ONLY_PARENT_AFTER_HTTP_CLOSE');
    }
    nheco_expect(!nhep_child_listener('http')['open'],'NHECO_NO_HTTP_LISTENER_AFTER_CLOSE'); return $receipt;
}

function nheco_http(array $state, PDO $db, Redis $redis, array $baseline): array
{
    $private=nheco_private_actors($state); $fixture=nheco_fixture($state,$baseline,$private);
    $plan=nhecr_request_plan($baseline,$fixture,$state['prior']['producer_result']['http']);
    array_unshift($plan,['step'=>'customer_login','method'=>'POST','path'=>'/api/login','actor'=>'customer','actor_id'=>$fixture['uid'],
        'body'=>['account'=>$private['user_account'],'password'=>$private['user_password'],'spread'=>0,'agent_id'=>0],
        'requires_actual_same_actor_login'=>false]);
    nheco_expect(count($plan)===9,'NHECO_ORIGINAL_LOGIN_PLUS_EIGHT_GETS');
    $configuration=audit_config(); $children=[]; $closed=null; $failure=null; $completed=[]; $accepted=null;
    try {
        $cache=nhep_redis_capture($redis,$state['handoff']['run_id']); $originalFiles=nheco_files();
        nheco_expect(array_keys(nhecr_file_tree('/current-runtime'))===[''],'NHECO_NEW_EMPTY_PRIVATE_HTTP_RUNTIME');
        $startupStart=time(); nheco_child_start($state,$children); $startupEnd=time();
        $streams=nhecr_stream_capture($children);
        nheco_expect($streams['http']['stdout']['raw']===''
            && preg_match('~\A\[([^\r\n]{24})\] PHP '.preg_quote(PHP_VERSION,'~').' Development Server \(http://127[.]0[.]0[.]1:8000\) started\n\z~D',
                $streams['http']['stderr']['raw'],$stamp)===1,'NHECO_ONLY_ACTUAL_HTTP_START_LINE');
        nhep_http_asctime($stamp[1],[$startupStart,$startupEnd]);
        $tree=nhecr_file_tree('/current-runtime'); $leaves=array_keys($tree); sort($leaves,SORT_STRING);
        nheco_expect($leaves===['','current-http.stderr','current-http.stdout'],'NHECO_STARTUP_ONLY_OWN_TWO_STREAM_FILES');
        $cookies=[]; $token=null;
        foreach ($plan as $index=>$entry) {
            $intent=['run_id'=>$state['handoff']['run_id'],'sequence'=>$index+1,'step'=>$entry['step'],'origin'=>'http://127.0.0.1:8000',
                'method'=>$entry['method'],'path'=>$entry['path'],'actor'=>$entry['actor'],'actor_id'=>$entry['actor_id']];
            $request=['token'=>$index===0||$index===8?null:$token,'body'=>$entry['body'],
                'actor_identity'=>['id'=>$entry['actor_id'],'account'=>$index===8?null:$private['user_account']]];
            $before=nheco_capture($state,$db,$redis,$children,$fixture);
            $beforeDB=['database'=>$before['snapshot']['database'],'rows'=>$before['snapshot']['rows']];
            if ($accepted===null) {
                nheco_expect($index===0 && $beforeDB===$baseline,'NHECO_FIRST_REQUEST_USES_POST_MAINTENANCE_FULL_BASELINE');
                nheco_expect($originalFiles===['app'=>$before['snapshot']['private']['app'],'fixtures'=>$before['snapshot']['private']['fixtures'],
                    'public'=>$before['snapshot']['public']['current_public']],'NHECO_STARTUP_ORIGINAL_FILES_UNCHANGED');
                nheco_expect($before['snapshot']['redis']===nhep_redis_expected($cache['snapshot'],[],$before['snapshot']['redis']['time']),
                    'NHECO_STARTUP_ONLY_PASSIVE_CACHE_EXPIRY');
            } else {
                $continuous=$accepted; $continuous['redis']=nhep_redis_expected($accepted['redis'],[],$before['snapshot']['redis']['time']);
                nheco_expect($continuous===$before['snapshot'],'NHECO_COMPLETE_ACCEPTED_TO_NEXT_BEFORE'); unset($continuous);
            }
            if ($index===0) nhecr_login_context($beforeDB,$intent,$request,$fixture);
            elseif ($index<=4) nhecr_catalog_context($beforeDB,$intent,$request,$fixture,$fixture['self_operated_mer_id']);
            elseif ($index<=6) nhecr_order_value($beforeDB,$intent,$request);
            elseif ($index===7) nhecr_refund_context($beforeDB,$intent,$request);
            $extra=[]; $response=null; $problem=null; $closing=false;
            try { $response=nhecr_transport($intent,$request,$extra); $closing=nhecr_wait_closing($children,$response,$before['private']); }
            catch (Throwable $error) { $problem=$error; }
            $actual=nheco_capture($state,$db,$redis,$children,$fixture);
            if ($problem!==null) throw $problem;
            nheco_expect($closing && $response['http']===200 && $response['transport_errno']===0,'NHECO_SINGLE_REAL_HTTP_AND_COMPLETE_CLOSING');
            if ($index>=1 && $index<=4) $models=nhecr_catalog_verify($before,$actual,$intent,$request,$response,$configuration);
            elseif ($index!==8) $models=nhecr_account_detail_verify($before,$actual,$intent,$request,$response,$configuration);
            else {
                $image=nhecr_original_image($intent['origin'],$intent['origin'].$intent['path'],$response,$extra['redirect_count'],$extra['effective_url']);
                $models=['database'=>$before['snapshot']['database'],'rows'=>$before['snapshot']['rows'],
                    'redis'=>nhep_redis_expected($before['snapshot']['redis'],[],$actual['snapshot']['redis']['time'])];
            }
            $nextCookies=$index===8?$cookies:nhecr_headers_model($intent,$request,$response,$cookies)['seen_cookie_ids'];
            $files=nhecr_files_model($before['snapshot'],$actual['snapshot'],$intent,$response,$before['private'],$actual['private']);
            $expected=['database'=>$models['database'],'rows'=>$models['rows'],'redis'=>$models['redis'],
                'private'=>$files['private'],'public'=>$files['public'],'logs'=>$files['logs'],'native'=>$before['snapshot']['native']];
            nheco_expect($expected===$actual['snapshot'],'NHECO_ORIGINAL_COMPLETE_SEVEN_PART_ORACLE');
            if ($index===0) $token=$response['json']['data']['token'];
            $cookies=$nextCookies; $accepted=$expected;
            $completed[]=['sequence'=>$index+1,'step'=>$entry['step'],'http'=>200,'full_independent_oracle'=>true];
            unset($before,$actual,$request,$response,$models,$files,$expected,$beforeDB,$extra);
        }
        $closed=nheco_child_close($children); $final=nheco_db($db,$state['handoff']['run_id']);
        nheco_expect($final===['database'=>$accepted['database'],'rows'=>$accepted['rows']], 'NHECO_FINAL174_AFTER_HTTP_NORMAL_CLOSE');
        $redisFinal=nhep_redis_capture($redis,$state['handoff']['run_id']);
        nheco_expect($redisFinal['snapshot']===nhep_redis_expected($accepted['redis'],[],$redisFinal['snapshot']['time']), 'NHECO_FINAL_ONLY_PASSIVE_CACHE_EXPIRY');
        nheco_expect($accepted['private']===['app'=>nhecr_file_tree('/run/audit-app'),'fixtures'=>nhecr_file_tree('/run/audit-fixtures')]
            && $accepted['public']===['current_public'=>nhecr_file_tree('/workspace/crmeb/public')]
            && $accepted['logs']===['runtime'=>nhecr_file_tree('/current-runtime'),'current_root_runtime'=>nhecr_file_tree('/workspace/crmeb/runtime')],
            'NHECO_FINAL_COMPLETE_FILES_AFTER_NORMAL_CHILD_CLOSE');
        $profile=nheco_object(nheco_read('/tmp/post-reader-private/app-profile.json',null,4096));
        nheco_expect($profile===['enabled'=>true,'self_operated_mer_id'=>$state['handoff']['self_mer_id'],'app_source_sha'=>NHECO_APP_SOURCE,
            'container_id'=>$state['container_id'],'handoff_sha256'=>$state['handoff_pin']['sha256']], 'NHECO_ACTUAL_ENABLED_PROFILE_RECORDED_BY_HTTP_APP');
        foreach ($baseline['rows'] as $name=>$rows) {
            $afterRows=$final['rows'][$name];
            if ($name==='eb_user') {
                foreach ($rows as &$row) unset($row['last_time'],$row['last_ip']); unset($row);
                foreach ($afterRows as &$row) unset($row['last_time'],$row['last_ip']); unset($row);
            }
            nheco_expect($rows===$afterRows,'NHECO_ALL_LEGACY_ROWS_AND_ENTITLEMENTS_EXCEPT_PROVED_AUTH_TIME_IP_UNCHANGED');
        }
        nheco_includes($state); audit_guard_assert();
        return ['requests'=>$completed,'normal_http_close'=>$closed,'app'=>$profile,'image'=>$image,
            'final'=>nheco_db_pin($final),'complete_seven_parts_verified'=>true];
    } catch (Throwable $error) { $failure=$error; }
    finally {
        if (isset($children['http']) && $children['http']['closed']===null) {
            try { $closed=nheco_child_close($children); }
            catch (Throwable $closeError) { if ($failure===null) $failure=$closeError; }
        }
        unset($private,$configuration,$token,$cookies,$accepted);
    }
    throw $failure;
}

function nheco_private_cleanup(): void
{
    $root='/tmp/post-reader-private'; $paths=[];
    foreach ([$root.'/quarantine',$root] as $directory) {
        $stat=lstat($directory); nheco_expect(is_array($stat) && !is_link($directory) && realpath($directory)===$directory
            && ($stat['mode']&07777)===0700 && $stat['uid']===posix_geteuid(),'NHECO_OWN_PRIVATE_DIRECTORY');
        foreach (scandir($directory) as $leaf) {
            if ($leaf==='.' || $leaf==='..' || ($directory===$root && $leaf==='quarantine')) continue;
            $path=$directory.'/'.$leaf; $stat=lstat($path);
            nheco_expect(is_array($stat) && ($stat['mode']&0170000)===0100000 && ($stat['mode']&07777)===0600
                && $stat['uid']===posix_geteuid() && $stat['nlink']===1 && !is_link($path),'NHECO_ONLY_OWN_PRIVATE_REGULAR_FILES');
            $paths[$path]=nheco_pin(nheco_read($path));
        }
    }
    foreach ($paths as $path=>$pin) { nheco_read($path,$pin); nheco_expect(unlink($path) && !file_exists($path),'NHECO_OWN_PRIVATE_EXACT_REMOVE'); }
    nheco_expect(rmdir($root.'/quarantine') && rmdir($root),'NHECO_OWN_PRIVATE_DIRECTORIES_EMPTY_REMOVED');
}

function nheco_normal(array $argv): array
{
    nheco_expect(count($argv)===4 && $argv[0]===NHECO_ENTRY && $argv[1]==='normal'
        && preg_match('/\A--handoff-bytes=([1-9][0-9]{0,9})\z/D',$argv[2],$bytes)===1
        && (string)(int)$bytes[1]===$bytes[1] && preg_match('/\A--handoff-sha256=([a-f0-9]{64})\z/D',$argv[3],$sha)===1,
        'NHECO_ONLY_FIXED_NORMAL_ARGV');
    nheco_memory(); $state=nheco_qualify(['bytes'=>(int)$bytes[1],'sha256'=>$sha[1]]); nheco_libraries(false); nheco_includes($state);
    $configuration=audit_config(); $db=audit_pdo($configuration['database']); $redis=audit_redis();
    nheco_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false) && $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)===0,
        'NHECO_INDEPENDENT_NATIVE_DML_OBSERVER');
    unset($configuration); $maintenance=nheco_maintenance($state,$db,$redis);
    $http=nheco_http($state,$db,$redis,$maintenance['view']);
    foreach ($state['held'] as $path=>$raw) nheco_expect(nheco_read($path)===$raw,'NHECO_ALL_ORIGINAL_CONTROL_AND_PUBLIC_BYTES_HELD');
    nheco_expect(nheco_pin(nheco_read('/run/post-reader/HANDOFF.json'))===$state['handoff_pin']
        && nheco_pin(nheco_read(__FILE__))===['bytes'=>$state['handoff']['actor']['bytes'],'sha256'=>$state['handoff']['actor']['sha256']],
        'NHECO_NEW_REVIEWED_SOURCE_AND_HANDOFF_UNCHANGED');
    nheco_private_cleanup(); audit_guard_assert();
    $result=['schema'=>1,'scope'=>NHECO_SCOPE,'status'=>'VERIFIED_POST_READER_SYNTHETIC_OWNERSHIP_ENABLED_NORMAL_ONLY',
        'run_id'=>$state['handoff']['run_id'],'app_source_sha'=>NHECO_APP_SOURCE,'actor_pin'=>nheco_pin(nheco_read(__FILE__)),
        'handoff_pin'=>$state['handoff_pin'],'container_id'=>$state['container_id'],'ms2'=>$maintenance['receipt'],
        'ownership'=>['five_roots'=>array_keys(NHECO_ROOTS),'all_rows_at_actual_self'=>true,'no_unknown_orphans_cross_store'=>true,
            'full_nonowner_preserved'=>true,'old_saved_versions_preserved'=>true],
        'app'=>$http['app'],'requests'=>$http['requests'],'normal_http_close'=>$http['normal_http_close'],
        'preservation'=>['complete174_nonowner_and_old_transactions'=>true,
            'only_declared_attachment_then_owner_constraints'=>true,'original_public_private_source_files'=>true,
            'original_context_manifest_results'=>true,'redis_only_approved_auth_and_passive_expiry'=>true,
            'old_v0_null_rows_balance_points_coupons_unchanged'=>true,'private_inputs_cleaned'=>true,
            'original_image'=>$http['image'],'complete_seven_parts_verified'=>$http['complete_seven_parts_verified']],
        'old_writes_tested'=>'NOT RUN','full_acceptance'=>'INCOMPLETE'];
    nheco_new('/run/post-reader-output/RESULT.json',nheco_json($result)); return $result;
}

if (PHP_SAPI==='cli-server') {
    set_error_handler(static function (): never { throw new RuntimeException('NHECO_NATIVE_WARNING_STATE_PRESERVED'); });
    return nheco_router();
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
    if (array_slice($argv,1)===['--help']) {
        echo "Usage: history_ownership_normal.py --source APP_SOURCE_SHA --history-source SOURCE3 --context ORIGINAL_CONTEXT --output NEW_EXTERNAL_DIRECTORY\n";
        echo "Synthetic post-reader normal only; no retry, no automatic final-close, no old-order write acceptance.\n"; exit(0);
    }
    set_error_handler(static function (): never { throw new RuntimeException('NHECO_NATIVE_WARNING_STATE_PRESERVED'); });
    $finished=false;
    register_shutdown_function(static function () use (&$finished): void {
        if ($finished) return;
        $last=error_get_last();
        $value=['schema'=>1,'scope'=>NHECO_SCOPE,'status'=>'POST_READER_FAILED_STATE_PRESERVED','code'=>'NHECO_FATAL_OR_UNFINISHED',
            'fatal'=>$last===null?null:['type'=>$last['type'],'file'=>basename($last['file']),'line'=>$last['line']],
            'memory_limit'=>ini_get('memory_limit'),'peak_memory'=>memory_get_peak_usage(true),'full_acceptance'=>'INCOMPLETE'];
        $path='/run/post-reader-output/FAILURE.json';
        if (!file_exists($path)) { $f=@fopen($path,'xb'); if (is_resource($f)) { @fwrite($f,nheco_json($value)); @fclose($f); } }
        fwrite(STDERR,"NHECO_FAILED_STATE_PRESERVED\n");
    });
    try { $result=nheco_normal($argv); $finished=true; echo nheco_json($result); exit(0); }
    catch (Throwable $error) {
        $message=$error->getMessage(); $code=preg_match('/\A[A-Z0-9_]{1,180}\z/D',$message)===1?$message:'NHECO_UNCLASSIFIED_ERROR';
        $value=['schema'=>1,'scope'=>NHECO_SCOPE,'status'=>'POST_READER_FAILED_STATE_PRESERVED','code'=>$code,
            'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
            'native_exit'=>1,'automatic_retry'=>false,'failed_resource_cleanup'=>'NOT PERFORMED','full_acceptance'=>'INCOMPLETE'];
        try { nheco_new('/run/post-reader-output/FAILURE.json',nheco_json($value)); } catch (Throwable $ignored) {}
        $finished=true; echo nheco_json($value); exit(1);
    }
}
