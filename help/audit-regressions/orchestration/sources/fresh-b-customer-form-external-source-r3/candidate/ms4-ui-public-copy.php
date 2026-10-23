<?php
declare(strict_types=1);

/**
 * Finite three-build public copier. Functions only; the genuine Root-gated
 * /run/ms4-prep/ms4-ui-copy.php caller proves native/source/volume preconditions.
 * Supplied identity is a binding to that gate, never a substitute for its proof.
 */
function m4uic_expect(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException($code);
}
function m4uic_keys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    m4uic_expect($actual === $keys, 'UI_COPY_EXACT_KEYS');
}
function m4uic_path(string $path, bool $installerFile = false): void
{
    m4uic_expect($path !== '' && preg_match('//u', $path) === 1
        && preg_match('~(^/|/$|\\\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:)~', $path) !== 1
        && (!in_array('.env', explode('/', $path), true) || ($installerFile && $path === 'install/.env')), 'UI_COPY_CLOSED_RELATIVE_PATH');
}
function m4uic_installer_template(string $path, int $bytes, string $sha256): void
{
    m4uic_expect(in_array($path, ['install/.env','/source/crmeb/public/install/.env',
        '/workspace/crmeb/public/install/.env'], true) && $bytes === 518
        && $sha256 === 'cc2cd4add8cd6534d433101a2f0d095f3ea5b58d7703201ae779972b7463df91', 'UI_COPY_EXACT_ORIGINAL_INSTALLER_TEMPLATE');
}
function m4uic_state(array $stat): array
{
    $value = [];
    foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $key) {
        m4uic_expect(isset($stat[$key]) && is_int($stat[$key]), 'UI_COPY_NATIVE_STAT');
        $value[$key] = $stat[$key];
    }
    return $value;
}
function m4uic_directory(string $path, bool $public = false): array
{
    clearstatcache(true, $path); $stat = @lstat($path);
    m4uic_expect(is_array($stat) && ($stat['mode'] & 0170000) === 0040000
        && !is_link($path) && realpath($path) === $path, 'UI_COPY_REAL_DIRECTORY');
    if ($public) m4uic_expect(($stat['mode'] & 07777) === 0755
        && $stat['uid'] === posix_geteuid() && $stat['gid'] === posix_getegid(), 'UI_COPY_OWN_PUBLIC_DIRECTORY_MODE');
    return m4uic_state($stat);
}
/** Full stable native read; only the three closed-shape JSON manifests collect raw bytes. */
function m4uic_file(string $path, ?array $expected = null, bool $capture = false): array
{
    clearstatcache(true, $path); $stat = @lstat($path);
    m4uic_expect(is_array($stat) && ($stat['mode'] & 0170000) === 0100000
        && $stat['nlink'] === 1 && !is_link($path) && realpath($path) === $path, 'UI_COPY_REAL_SINGLE_LINK_FILE');
    $state = m4uic_state($stat); $handle = @fopen($path, 'rb');
    m4uic_expect(is_resource($handle), 'UI_COPY_SOURCE_OPEN');
    $bytes = 0; $hash = hash_init('sha256'); $raw = ''; $prefix = '';
    try {
        $opened = fstat($handle);
        m4uic_expect(is_array($opened) && m4uic_state($opened) === $state, 'UI_COPY_OPEN_SAME_NATIVE_FILE');
        while (!feof($handle)) {
            $chunk = fread($handle, 262144);
            m4uic_expect(is_string($chunk) && ($chunk !== '' || feof($handle)), 'UI_COPY_COMPLETE_NATIVE_READ');
            $bytes += strlen($chunk); hash_update($hash, $chunk);
            if (strlen($prefix) < 8) $prefix .= substr($chunk, 0, 8 - strlen($prefix));
            if ($capture) $raw .= $chunk;
        }
        $finished = fstat($handle);
        m4uic_expect(is_array($finished) && m4uic_state($finished) === $state
            && $bytes === $state['size'], 'UI_COPY_NATIVE_READ_STABLE');
    } finally {
        m4uic_expect(fclose($handle), 'UI_COPY_SOURCE_CLOSE');
    }
    clearstatcache(true, $path); $again = @lstat($path);
    m4uic_expect(is_array($again) && m4uic_state($again) === $state
        && !is_link($path) && realpath($path) === $path, 'UI_COPY_SOURCE_PATH_STABLE');
    $digest = hash_final($hash);
    if (in_array($path, ['/source/crmeb/public/install/.env','/workspace/crmeb/public/install/.env'], true))
        m4uic_installer_template($path, $bytes, $digest);
    if ($expected !== null) m4uic_expect($bytes === $expected['bytes'] && $digest === $expected['sha256'], 'UI_COPY_EXACT_SOURCE_BYTES');
    return ['bytes'=>$bytes,'sha256'=>$digest,'stat'=>$state,'prefix'=>$prefix,'raw'=>$capture ? $raw : null];
}
/** Complete files, directories (including empty ones) and immutable input metadata. */
function m4uic_tree(string $root, bool $public = false): array
{
    $originalPublic = in_array($root, ['/source/crmeb/public','/workspace/crmeb/public'], true);
    $nodes = [''=>m4uic_directory($root, $public)]; $files = []; $directories = []; $todo = [''];
    while ($todo !== []) {
        $relative = array_pop($todo); $path = $root . ($relative === '' ? '' : '/' . $relative);
        $before = m4uic_directory($path, $public); $names = scandir($path, SCANDIR_SORT_ASCENDING);
        m4uic_expect(is_array($names), 'UI_COPY_COMPLETE_DIRECTORY_READ');
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $relative === '' ? $name : $relative . '/' . $name; m4uic_path($child, $originalPublic);
            $full = $root . '/' . $child; clearstatcache(true, $full); $stat = @lstat($full);
            m4uic_expect(is_array($stat) && !is_link($full) && realpath($full) === $full, 'UI_COPY_NO_LINK_OR_ALIAS');
            $kind = $stat['mode'] & 0170000;
            if ($kind === 0040000) {
                m4uic_path($child);
                $nodes[$child] = m4uic_directory($full, $public); $directories[] = $child; $todo[] = $child;
            } else {
                m4uic_expect($kind === 0100000, 'UI_COPY_ONLY_REGULAR_FILES_AND_DIRECTORIES');
                $read = m4uic_file($full);
                if ($public) m4uic_expect(($read['stat']['mode'] & 07777) === 0644
                    && $read['stat']['uid'] === posix_geteuid() && $read['stat']['gid'] === posix_getegid(), 'UI_COPY_OWN_PUBLIC_FILE_MODE');
                $nodes[$child] = $read['stat'];
                $files[$child] = ['path'=>$child,'bytes'=>$read['bytes'],'sha256'=>$read['sha256']];
            }
        }
        m4uic_expect(m4uic_directory($path, $public) === $before, 'UI_COPY_DIRECTORY_READ_STABLE');
    }
    ksort($files, SORT_STRING); ksort($nodes, SORT_STRING); sort($directories, SORT_STRING);
    return ['files'=>$files,'directories'=>$directories,'nodes'=>$nodes];
}
function m4uic_rows(array $list, bool $originalPublic = false): array
{
    m4uic_expect(array_is_list($list) && $list !== [], 'UI_COPY_COMPLETE_NONEMPTY_FILE_LIST'); $rows = []; $names = [];
    foreach ($list as $row) {
        m4uic_expect(is_array($row), 'UI_COPY_FILE_ROW'); m4uic_keys($row, ['path','bytes','sha256']);
        m4uic_expect(is_string($row['path']) && is_int($row['bytes']) && $row['bytes'] >= 0
            && is_string($row['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1, 'UI_COPY_FILE_DESCRIPTOR');
        $path = $row['path']; m4uic_path($path, $originalPublic);
        if ($path === 'install/.env') {
            m4uic_expect($originalPublic, 'UI_COPY_ONLY_ORIGINAL_PUBLIC_INSTALLER_ROW');
            m4uic_installer_template($path, $row['bytes'], $row['sha256']);
        }
        m4uic_expect(!isset($rows[$path]), 'UI_COPY_UNIQUE_FILE');
        $rows[$path] = ['path'=>$path,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']]; $names[] = $path;
    }
    $sorted = $names; sort($sorted, SORT_STRING); m4uic_expect($names === $sorted, 'UI_COPY_FROZEN_FILE_ORDER');
    ksort($rows, SORT_STRING); return $rows;
}
function m4uic_directories(array $list): array
{
    m4uic_expect(array_is_list($list), 'UI_COPY_COMPLETE_DIRECTORY_LIST');
    foreach ($list as $path) { m4uic_expect(is_string($path), 'UI_COPY_DIRECTORY_STRING'); m4uic_path($path); }
    $sorted = $list; sort($sorted, SORT_STRING);
    m4uic_expect($list === $sorted && count(array_unique($list, SORT_STRING)) === count($list), 'UI_COPY_FROZEN_DIRECTORY_ORDER');
    return $list;
}
function m4uic_descriptor(array $value, string $path): array
{
    m4uic_keys($value, ['path','bytes','sha256']);
    m4uic_expect($value['path'] === $path && is_int($value['bytes']) && $value['bytes'] > 0
        && is_string($value['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $value['sha256']) === 1, 'UI_COPY_FIXED_DESCRIPTOR');
    return ['path'=>$path,'bytes'=>$value['bytes'],'sha256'=>$value['sha256']];
}
function m4uic_root_identity(array $stat): array
{
    $identity = [];
    foreach (['dev','ino','mode','uid','gid'] as $key) $identity[$key] = $stat[$key];
    return $identity;
}
/** Only a just-created object is normalized; existing mount roots are never chmod'ed. */
function m4uic_mkdir(string $path): void
{
    m4uic_directory(dirname($path), true); clearstatcache(true, $path);
    m4uic_expect(@lstat($path) === false && !is_link($path) && mkdir($path, 0755), 'UI_COPY_CREATE_ONLY_DIRECTORY');
    $created = m4uic_directory($path);
    m4uic_expect($created['uid'] === posix_geteuid() && $created['gid'] === posix_getegid()
        && chmod($path, 0755), 'UI_COPY_NEW_DIRECTORY_MODE');
    $after = m4uic_directory($path, true);
    m4uic_expect($after['dev'] === $created['dev'] && $after['ino'] === $created['ino'], 'UI_COPY_SAME_NEW_DIRECTORY');
}
/** Stream one exact frozen input into one exclusive new target; never overwrite or unlink. */
function m4uic_transfer(string $source, string $target, array $expected, array $sourceState): void
{
    if ($source === '/source/crmeb/public/install/.env' || $target === '/workspace/crmeb/public/install/.env') {
        m4uic_expect($source === '/source/crmeb/public/install/.env' && $target === '/workspace/crmeb/public/install/.env'
            && $expected['path'] === 'install/.env', 'UI_COPY_EXACT_INSTALLER_TRANSFER_PAIR');
        m4uic_installer_template($expected['path'], $expected['bytes'], $expected['sha256']);
    }
    m4uic_directory(dirname($target), true); clearstatcache(true, $target);
    m4uic_expect(@lstat($target) === false && !is_link($target), 'UI_COPY_EXCLUSIVE_TARGET');
    clearstatcache(true, $source); $stat = @lstat($source);
    m4uic_expect(is_array($stat) && m4uic_state($stat) === $sourceState
        && !is_link($source) && realpath($source) === $source, 'UI_COPY_FROZEN_TRANSFER_SOURCE');
    $input = @fopen($source, 'rb'); m4uic_expect(is_resource($input), 'UI_COPY_TRANSFER_SOURCE_OPEN');
    $output = null; $created = null; $bytes = 0; $hash = hash_init('sha256');
    try {
        $opened = fstat($input); m4uic_expect(is_array($opened) && m4uic_state($opened) === $sourceState, 'UI_COPY_TRANSFER_OPEN_SOURCE');
        $output = @fopen($target, 'x+b'); m4uic_expect(is_resource($output), 'UI_COPY_CREATE_ONLY_FILE');
        $created = fstat($output);
        m4uic_expect(is_array($created) && ($created['mode'] & 0170000) === 0100000
            && $created['nlink'] === 1 && $created['uid'] === posix_geteuid() && $created['gid'] === posix_getegid(), 'UI_COPY_NEW_OWN_SINGLE_LINK_FILE');
        while (!feof($input)) {
            $chunk = fread($input, 262144);
            m4uic_expect(is_string($chunk) && ($chunk !== '' || feof($input)), 'UI_COPY_FULL_TRANSFER_READ');
            $bytes += strlen($chunk); hash_update($hash, $chunk); $offset = 0;
            while ($offset < strlen($chunk)) {
                $written = fwrite($output, substr($chunk, $offset));
                m4uic_expect(is_int($written) && $written > 0, 'UI_COPY_FULL_TRANSFER_WRITE'); $offset += $written;
            }
        }
        $done = fstat($input);
        m4uic_expect(is_array($done) && m4uic_state($done) === $sourceState && $bytes === $expected['bytes']
            && hash_final($hash) === $expected['sha256'] && fflush($output), 'UI_COPY_EXACT_TRANSFER_AND_FLUSH');
        clearstatcache(true, $target); $named = @lstat($target); $open = fstat($output);
        m4uic_expect(is_array($named) && is_array($open) && m4uic_state($named) === m4uic_state($open)
            && $open['dev'] === $created['dev'] && $open['ino'] === $created['ino']
            && !is_link($target) && realpath($target) === $target && chmod($target, 0644), 'UI_COPY_SAME_NEW_FILE_MODE');
        $sealed = fstat($output);
        m4uic_expect(is_array($sealed) && ($sealed['mode'] & 07777) === 0644 && $sealed['nlink'] === 1
            && $sealed['dev'] === $created['dev'] && $sealed['ino'] === $created['ino'], 'UI_COPY_SEALED_FILE_IDENTITY');
    } finally {
        if (is_resource($output)) m4uic_expect(fclose($output), 'UI_COPY_TARGET_CLOSE');
        m4uic_expect(fclose($input), 'UI_COPY_TRANSFER_SOURCE_CLOSE');
    }
    clearstatcache(true, $source); $after = @lstat($source);
    m4uic_expect(is_array($after) && m4uic_state($after) === $sourceState, 'UI_COPY_TRANSFER_SOURCE_UNCHANGED');
    $readback = m4uic_file($target, $expected);
    m4uic_expect(($readback['stat']['mode'] & 07777) === 0644 && $readback['stat']['dev'] === $created['dev']
        && $readback['stat']['ino'] === $created['ino'] && $readback['stat']['uid'] === posix_geteuid()
        && $readback['stat']['gid'] === posix_getegid(), 'UI_COPY_FULL_TRANSFER_READBACK');
}
function m4uic_marker(string $path, string $raw): void
{
    m4uic_directory(dirname($path), true); clearstatcache(true, $path);
    m4uic_expect(@lstat($path) === false && !is_link($path), 'UI_COPY_CREATE_ONLY_MARKER');
    $handle = @fopen($path, 'x+b'); m4uic_expect(is_resource($handle), 'UI_COPY_MARKER_OPEN');
    try {
        $created = fstat($handle);
        m4uic_expect(is_array($created) && ($created['mode'] & 0170000) === 0100000
            && $created['nlink'] === 1 && $created['uid'] === posix_geteuid() && $created['gid'] === posix_getegid(), 'UI_COPY_OWN_MARKER');
        $offset = 0;
        while ($offset < strlen($raw)) {
            $written = fwrite($handle, substr($raw, $offset));
            m4uic_expect(is_int($written) && $written > 0, 'UI_COPY_MARKER_COMPLETE_WRITE'); $offset += $written;
        }
        m4uic_expect(fflush($handle), 'UI_COPY_MARKER_FLUSH'); clearstatcache(true, $path);
        $named = @lstat($path); $open = fstat($handle);
        m4uic_expect(is_array($named) && is_array($open) && m4uic_state($named) === m4uic_state($open)
            && $open['dev'] === $created['dev'] && $open['ino'] === $created['ino']
            && !is_link($path) && realpath($path) === $path && chmod($path, 0644), 'UI_COPY_MARKER_SAME_FILE_MODE');
    } finally {
        m4uic_expect(fclose($handle), 'UI_COPY_MARKER_CLOSE');
    }
    $read = m4uic_file($path, ['bytes'=>strlen($raw),'sha256'=>hash('sha256', $raw)]);
    m4uic_expect(($read['stat']['mode'] & 07777) === 0644 && $read['stat']['dev'] === $created['dev']
        && $read['stat']['ino'] === $created['ino'], 'UI_COPY_MARKER_FULL_READBACK');
}
function m4uic_copy(array $profile, string $run, array $identity): array
{
    m4uic_keys($profile, ['public_source_files','public_source_directories','h5_dist_files','h5_dist_directories',
        'merchant_dist_files','merchant_dist_directories','admin_dist_files','admin_dist_directories','synthetic_png','builds']);
    m4uic_keys($identity, ['source_sha','freeze_sha256','source_identity']);
    m4uic_expect(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300 && PHP_INT_SIZE >= 8
        && getmypid() === 1 && posix_geteuid() === 0 && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', $run) === 1
        && $run === getenv('AUDIT_RUN_ID') && is_string($identity['source_sha'])
        && preg_match('/\A[a-f0-9]{40}\z/D', $identity['source_sha']) === 1
        && $identity['source_sha'] === getenv('AUDIT_SOURCE_SHA') && is_string($identity['freeze_sha256'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $identity['freeze_sha256']) === 1
        && is_array($identity['source_identity']), 'UI_COPY_ACTUAL_LOCAL_BINDING');
    $own = $identity['source_identity'];
    m4uic_keys($own, ['proof_name','role','command','container_id','native_created_sha256']);
    m4uic_expect($own['proof_name'] === 'ui-public-copy' && $own['role'] === 'audit-ms4-ui-public-copy'
        && $own['command'] === ['/run/ms4-prep/ms4-ui-copy.php','copy']
        && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === $own['command'][0] && ($_SERVER['argv'] ?? null) === $own['command']
        && is_string($own['container_id']) && preg_match('/\A[a-f0-9]{64}\z/D', $own['container_id']) === 1
        && is_string($own['native_created_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $own['native_created_sha256']) === 1,
        'UI_COPY_GATED_CALLER_IDENTITY');
    m4uic_expect(@lstat('/source/crmeb/.env') === false, 'UI_COPY_NO_HOST_ENVIRONMENT');
    $root = '/workspace/crmeb/public'; $comment = $root . '/uploads/store/comment'; $customer = $root . '/uploads/customer';
    $initial = m4uic_tree($root, true); $commentInitial = m4uic_tree($comment, true); $customerInitial = m4uic_tree($customer, true);
    $mountDirs = ['uploads','uploads/customer','uploads/store','uploads/store/comment'];
    m4uic_expect($initial['files'] === [] && $initial['directories'] === $mountDirs
        && $commentInitial['files'] === [] && $commentInitial['directories'] === []
        && $customerInitial['files'] === [] && $customerInitial['directories'] === []
        && [$initial['nodes']['']['dev'],$initial['nodes']['']['ino']]
            !== [$commentInitial['nodes']['']['dev'],$commentInitial['nodes']['']['ino']]
        && [$initial['nodes']['']['dev'],$initial['nodes']['']['ino']]
            !== [$customerInitial['nodes']['']['dev'],$customerInitial['nodes']['']['ino']]
        && [$commentInitial['nodes']['']['dev'],$commentInitial['nodes']['']['ino']]
            !== [$customerInitial['nodes']['']['dev'],$customerInitial['nodes']['']['ino']], 'UI_COPY_THREE_FRESH_GATED_VOLUME_ROOTS');
    $sourceFiles = m4uic_rows($profile['public_source_files'], true);
    $sourceDirs = m4uic_directories($profile['public_source_directories']);
    $source = m4uic_tree('/source/crmeb/public');
    m4uic_expect($source['files'] === $sourceFiles && $source['directories'] === $sourceDirs
        && !isset($sourceFiles['install.lock']) && !in_array('install.lock', $sourceDirs, true), 'UI_COPY_WHOLE_ORIGINAL_PUBLIC_INPUT');
    foreach (array_merge(array_column(array_values($sourceFiles), 'path'), $sourceDirs) as $path)
        m4uic_expect($path !== '__ms4_assets' && !str_starts_with($path, '__ms4_assets/'), 'UI_COPY_NO_RESERVED_SOURCE_ASSETS');
    foreach (['index.php','router.php'] as $path) m4uic_expect(isset($sourceFiles[$path]) && $sourceFiles[$path]['bytes'] > 0, 'UI_COPY_REAL_ORIGINAL_PUBLIC_ENTRIES');
    m4uic_expect(is_array($profile['builds']) && is_array($profile['synthetic_png']), 'UI_COPY_MATERIAL_DESCRIPTORS');
    m4uic_keys($profile['builds'], ['h5','merchant','admin']);
    $inputs = []; $manifestReads = []; $builds = [];
    foreach (['h5','merchant','admin'] as $name) {
        $files = m4uic_rows($profile[$name . '_dist_files']); $dirs = m4uic_directories($profile[$name . '_dist_directories']);
        m4uic_expect(isset($files['index.html']) && $files['index.html']['bytes'] > 0, 'UI_COPY_TRUE_BUILD_INDEX');
        $descriptor = m4uic_descriptor($profile['builds'][$name], '/run/ms4-prep/manifests/ui-' . $name . '-dist.json');
        $read = m4uic_file($descriptor['path'], $descriptor, true);
        $value = json_decode($read['raw'], true, 512, JSON_THROW_ON_ERROR);
        m4uic_expect(is_array($value), 'UI_COPY_REAL_BUILD_MANIFEST'); m4uic_keys($value, ['files','directories']);
        m4uic_expect(is_array($value['files']) && is_array($value['directories'])
            && m4uic_rows($value['files']) === $files && m4uic_directories($value['directories']) === $dirs, 'UI_COPY_FROZEN_BUILD_MANIFEST');
        $tree = m4uic_tree('/run/ms4-ui-dist/' . $name);
        m4uic_expect($tree['files'] === $files && $tree['directories'] === $dirs, 'UI_COPY_COMPLETE_REAL_BUILD_TREE');
        $inputs[$name] = $tree; $manifestReads[$name] = $read; $builds[$name] = $descriptor;
    }
    $pngDescriptor = m4uic_descriptor($profile['synthetic_png'], '/run/ms4-prep/ms4-order.png');
    $png = m4uic_file($pngDescriptor['path'], $pngDescriptor);
    m4uic_expect($png['prefix'] === "\x89PNG\r\n\x1a\n", 'UI_COPY_REAL_FROZEN_PNG');
    $expected = $sourceFiles; $directories = array_merge($sourceDirs, $mountDirs);
    $transfers = [];
    foreach ($sourceFiles as $row) $transfers[] = ['/source/crmeb/public/' . $row['path'], $row['path'], $row, $source['nodes'][$row['path']]];
    foreach (['h5','merchant','admin'] as $name) {
        $prefix = '__ms4_assets/' . $name; $directories[] = $prefix;
        foreach ($inputs[$name]['directories'] as $path) $directories[] = $prefix . '/' . $path;
        foreach ($inputs[$name]['files'] as $row) {
            $target = $prefix . '/' . $row['path'];
            m4uic_expect(!isset($expected[$target]), 'UI_COPY_NO_BUILD_COLLISION');
            $expected[$target] = ['path'=>$target,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']];
            $transfers[] = ['/run/ms4-ui-dist/' . $name . '/' . $row['path'], $target, $row, $inputs[$name]['nodes'][$row['path']]];
        }
    }
    $fixture = '__ms4_fixture__/product.png'; $marker = '# AUDIT-RUN ' . $run . "\n";
    m4uic_expect(!isset($expected[$fixture]) && !isset($expected['install.lock']), 'UI_COPY_NO_FIXTURE_OR_MARKER_COLLISION');
    $expected[$fixture] = ['path'=>$fixture,'bytes'=>$png['bytes'],'sha256'=>$png['sha256']];
    $expected['install.lock'] = ['path'=>'install.lock','bytes'=>strlen($marker),'sha256'=>hash('sha256', $marker)];
    $transfers[] = [$pngDescriptor['path'], $fixture, $pngDescriptor, $png['stat']];
    foreach (array_merge(array_column(array_values($expected), 'path'), $directories) as $path)
        for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) $directories[] = $parent;
    $directories = array_values(array_unique($directories, SORT_STRING)); sort($directories, SORT_STRING); ksort($expected, SORT_STRING);
    foreach ($directories as $path) { m4uic_path($path); m4uic_expect(!isset($expected[$path]), 'UI_COPY_NO_FILE_DIRECTORY_COLLISION'); }
    // All input trees, JSON descriptors, PNG, target paths and collisions are known before the first write.
    m4uic_expect(m4uic_tree($root, true) === $initial && m4uic_tree($comment, true) === $commentInitial
        && m4uic_tree($customer, true) === $customerInitial, 'UI_COPY_FRESH_TARGETS_STILL_UNTOUCHED');
    foreach ($directories as $path) {
        if (in_array($path, $mountDirs, true)) m4uic_directory($root . '/' . $path, true);
        else m4uic_mkdir($root . '/' . $path);
    }
    foreach ($transfers as [$input,$relative,$row,$state]) m4uic_transfer($input, $root . '/' . $relative, $row, $state);
    m4uic_marker($root . '/install.lock', $marker);
    $actual = m4uic_tree($root, true);
    m4uic_expect($actual['files'] === $expected && $actual['directories'] === $directories, 'UI_COPY_COMPLETE_PUBLIC_UNION_READBACK');
    $commentFiles = []; $commentDirs = []; $prefix = 'uploads/store/comment/';
    foreach ($sourceFiles as $row) if (str_starts_with($row['path'], $prefix)) {
        $relative = substr($row['path'], strlen($prefix)); $commentFiles[$relative] = ['path'=>$relative,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']];
    }
    foreach ($sourceDirs as $path) if (str_starts_with($path, $prefix)) $commentDirs[] = substr($path, strlen($prefix));
    ksort($commentFiles, SORT_STRING); sort($commentDirs, SORT_STRING); $commentAfter = m4uic_tree($comment, true);
    m4uic_expect($commentAfter['files'] === $commentFiles && $commentAfter['directories'] === $commentDirs, 'UI_COPY_ENTIRE_REAL_COMMENT_OVERLAY');
    $customerFiles = []; $customerDirs = []; $prefix = 'uploads/customer/';
    foreach ($sourceFiles as $row) if (str_starts_with($row['path'], $prefix)) {
        $relative = substr($row['path'], strlen($prefix)); $customerFiles[$relative] = ['path'=>$relative,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']];
    }
    foreach ($sourceDirs as $path) if (str_starts_with($path, $prefix)) $customerDirs[] = substr($path, strlen($prefix));
    ksort($customerFiles, SORT_STRING); sort($customerDirs, SORT_STRING); $customerAfter = m4uic_tree($customer, true);
    m4uic_expect($customerAfter['files'] === $customerFiles && $customerAfter['directories'] === $customerDirs, 'UI_COPY_ENTIRE_REAL_CUSTOMER_OVERLAY');
    m4uic_expect(m4uic_root_identity($actual['nodes']['']) === m4uic_root_identity($initial['nodes'][''])
        && m4uic_root_identity($commentAfter['nodes']['']) === m4uic_root_identity($commentInitial['nodes'][''])
        && m4uic_root_identity($customerAfter['nodes']['']) === m4uic_root_identity($customerInitial['nodes']['']), 'UI_COPY_ORIGINAL_TARGET_ROOT_IDENTITIES');
    m4uic_expect(m4uic_tree('/source/crmeb/public') === $source, 'UI_COPY_ALL_SOURCE_BYTES_DIRECTORIES_MODES_UNCHANGED');
    foreach (['h5','merchant','admin'] as $name) {
        m4uic_expect(m4uic_tree('/run/ms4-ui-dist/' . $name) === $inputs[$name]
            && m4uic_file($builds[$name]['path'], $builds[$name], true) === $manifestReads[$name], 'UI_COPY_ALL_BUILD_INPUTS_DESCRIPTORS_UNCHANGED');
    }
    m4uic_expect(m4uic_file($pngDescriptor['path'], $pngDescriptor) === $png
        && @lstat('/source/crmeb/.env') === false, 'UI_COPY_PNG_AND_NO_ENV_UNCHANGED');
    m4uic_expect(m4uic_tree($root, true) === $actual && m4uic_tree($comment, true) === $commentAfter
        && m4uic_tree($customer, true) === $customerAfter, 'UI_COPY_FINAL_OUTPUTS_FULL_STABLE');
    return ['schema'=>1,'scope'=>'MS4_UI_PUBLIC_MATERIALS_COMPLETE','run_id'=>$run,
        'source_sha'=>$identity['source_sha'],'freeze_sha256'=>$identity['freeze_sha256'],
        'files'=>array_values($actual['files']),'directories'=>$actual['directories'],'builds'=>$builds];
}
