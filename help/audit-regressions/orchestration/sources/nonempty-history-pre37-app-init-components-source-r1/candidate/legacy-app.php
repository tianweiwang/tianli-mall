<?php
declare(strict_types=1);

// Definitions only. The future dedicated Host must produce the frozen authority
// and stage receipts below; this library does not register or create resources.
function nhe_expect(bool $value, string $code): void
{
    if (!$value) throw new RuntimeException($code);
}
function nhe_keys(array $value, array $keys, string $code): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    nhe_expect($actual === $keys, $code);
}
function nhe_relative(string $path): void
{
    nhe_expect($path !== '' && !str_contains($path, '\\') && $path[0] !== '/'
        && !preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~D', $path)
        && !str_contains($path, '//') && !str_contains($path, "\0")
        && !str_ends_with($path, '/'), 'NHE_RELATIVE_SOURCE_PATH');
}
function nhe_file(string $path, array $pin): string
{
    nhe_keys($pin, ['path', 'bytes', 'sha256'], 'NHE_FILE_PIN_KEYS');
    nhe_expect($pin['path'] === $path && is_int($pin['bytes']) && $pin['bytes'] >= 0
        && is_string($pin['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $pin['sha256']) === 1,
        'NHE_FILE_PIN');
    clearstatcache(true, $path); $before = @lstat($path);
    nhe_expect(is_array($before) && is_file($path) && !is_link($path)
        && ($before['mode'] & 0170000) === 0100000, 'NHE_REGULAR_SOURCE_FILE');
    $raw = file_get_contents($path);
    clearstatcache(true, $path); $after = @lstat($path);
    nhe_expect(is_string($raw) && strlen($raw) === $pin['bytes']
        && hash('sha256', $raw) === $pin['sha256'] && is_array($after), 'NHE_FULL_SOURCE_BYTES');
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key)
        nhe_expect($before[$key] === $after[$key], 'NHE_FILE_STAT_UNCHANGED');
    return $raw;
}
function nhe_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
function nhe_tree(string $root, array $rows, array $directories): array
{
    $sourceTemplates = [
        'crmeb/public/install/.env' => [518, 'cc2cd4add8cd6534d433101a2f0d095f3ea5b58d7703201ae779972b7463df91'],
        'template/admin/.env' => [199, 'a17eca0b39f6e17d343221138a0ba59bd2a75c64e7ecc1f594b829e620921562'],
    ];
    nhe_expect(is_dir($root) && !is_link($root) && array_is_list($rows) && $rows !== []
        && array_is_list($directories), 'NHE_COMPLETE_SOURCE_TREE');
    $files = []; $wantedDirectories = [];
    foreach ($rows as $row) {
        nhe_expect(is_array($row) && is_string($row['path'] ?? null), 'NHE_SOURCE_ROW');
        nhe_relative($row['path']); $relative = $row['path'];
        nhe_expect(!isset($files[$relative]) && $relative !== '.git'
            && !str_starts_with($relative, '.git/') && !str_starts_with($relative, 'crmeb/runtime/')
            && (!in_array(basename($relative), ['.env', 'install.lock', 'private.env', 'legacy.env'], true)
                || (isset($sourceTemplates[$relative]) && ($row['bytes'] ?? null) === $sourceTemplates[$relative][0]
                    && ($row['sha256'] ?? null) === $sourceTemplates[$relative][1])),
            'NHE_NO_PRIVATE_OR_DUPLICATE_SOURCE');
        nhe_keys($row, ['path', 'bytes', 'sha256'], 'NHE_SOURCE_ROW_KEYS');
        $files[$relative] = $row;
    }
    $ordered = array_keys($files); $sorted = $ordered; sort($sorted, SORT_STRING);
    nhe_expect($ordered === $sorted, 'NHE_SORTED_SOURCE_ROWS');
    foreach ($directories as $relative) {
        nhe_expect(is_string($relative), 'NHE_SOURCE_DIRECTORY'); nhe_relative($relative);
        nhe_expect(!isset($wantedDirectories[$relative]) && $relative !== '.git'
            && !str_starts_with($relative, '.git/') && $relative !== 'crmeb/runtime'
            && !str_starts_with($relative, 'crmeb/runtime/'), 'NHE_SOURCE_DIRECTORY_SET');
        $wantedDirectories[$relative] = true;
    }
    $ordered = array_keys($wantedDirectories); $sorted = $ordered; sort($sorted, SORT_STRING);
    nhe_expect($ordered === $sorted, 'NHE_SORTED_SOURCE_DIRECTORIES');
    $foundFiles = []; $foundDirectories = [];
    $walk = function (string $relative) use (&$walk, $root, &$foundFiles, &$foundDirectories): void {
        $names = scandir($root . ($relative === '' ? '' : '/' . $relative));
        nhe_expect(is_array($names), 'NHE_SOURCE_NAMESPACE_READ');
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $relative === '' ? $name : $relative . '/' . $name;
            if ($child === '.git' || $child === 'crmeb/runtime') {
                nhe_expect(!is_link($root . '/' . $child), 'NHE_NO_EXCLUDED_SOURCE_LINK');
                continue;
            }
            $path = $root . '/' . $child; $stat = @lstat($path);
            nhe_expect(is_array($stat) && !is_link($path), 'NHE_NO_SOURCE_LINK');
            if (is_dir($path)) { $foundDirectories[] = $child; $walk($child); }
            else { nhe_expect(is_file($path) && ($stat['mode'] & 0170000) === 0100000,
                    'NHE_NO_SPECIAL_SOURCE'); $foundFiles[] = $child; }
        }
    };
    $walk(''); sort($foundFiles, SORT_STRING); sort($foundDirectories, SORT_STRING);
    nhe_expect($foundFiles === array_keys($files) && $foundDirectories === array_keys($wantedDirectories),
        'NHE_COMPLETE_SOURCE_NAMESPACE');
    $stats = [];
    foreach ($files as $relative => $row) {
        $path = $root . '/' . $relative; nhe_file($path, array_replace($row, ['path' => $path]));
        clearstatcache(true, $path); $stat = lstat($path);
        $stats[$relative] = array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']));
    }
    $directoryStats = [];
    foreach (array_merge([''], $foundDirectories) as $relative) {
        $path = $root . ($relative === '' ? '' : '/' . $relative); clearstatcache(true, $path); $stat = lstat($path);
        $directoryStats[$relative] = array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']));
    }
    return ['files' => $stats, 'directories' => $directoryStats];
}
function nhe_mount_rows(array $mounts): array
{
    $rows = []; $seen = [];
    foreach ($mounts as $mount) {
        nhe_expect(is_array($mount) && is_string($mount['Type'] ?? null)
            && is_string($mount['Source'] ?? null) && is_string($mount['Destination'] ?? null)
            && is_bool($mount['RW'] ?? null), 'NHE_NATIVE_MOUNT_FIELDS');
        nhe_expect(!isset($seen[$mount['Destination']]), 'NHE_UNIQUE_MOUNT_DESTINATION');
        $seen[$mount['Destination']] = true;
        // HostConfig.Tmpfs is independently checked against actual mountinfo.
        if ($mount['Type'] === 'tmpfs') {
            nhe_expect(in_array($mount['Destination'], ['/tmp', '/legacy-runtime'], true)
                && $mount['RW'] === true, 'NHE_ONLY_OWN_TMPFS_MOUNT');
            continue;
        }
        $rows[] = ['Type' => $mount['Type'], 'Source' => audit_canonical_host_bind_source($mount['Source']),
            'Destination' => $mount['Destination'], 'RW' => $mount['RW'], 'Name' => $mount['Name'] ?? ''];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp(nhe_json($a), nhe_json($b)));
    return $rows;
}
function nhe_includes(array $state): array
{
    $allowed = array_fill_keys(array_column($state['authority']['control_files'], 'path'), true);
    $old = array_column($state['authority']['legacy_source_files'], null, 'path'); $actual = [];
    foreach (get_included_files() as $file) {
        nhe_expect(realpath($file) === $file && !is_link($file), 'NHE_INCLUDED_CANONICAL_FILE');
        if (isset($allowed[$file])) $actual[] = $file;
        else {
            nhe_expect(str_starts_with($file, '/legacy-workspace/'), 'NHE_NO_CURRENT_COMPOSER_OR_BUSINESS');
            $relative = substr($file, strlen('/legacy-workspace/'));
            nhe_expect(isset($old[$relative]), 'NHE_INCLUDED_COMPLETE_OLD_SOURCE');
            nhe_file($file, array_replace($old[$relative], ['path' => $file])); $actual[] = $file;
        }
    }
    sort($actual, SORT_STRING); return $actual;
}
function nhe_role_command(array $freezePin, string $role): array
{
    nhe_expect(in_array($role, ['audit-legacy-files-bootstrap', 'audit-legacy-history-producer'], true)
        && is_int($freezePin['bytes'] ?? null) && $freezePin['bytes'] > 0
        && is_string($freezePin['sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $freezePin['sha256']) === 1,
        'NHE_FIXED_ROLE_AND_FREEZE_COMMAND');
    $command = $role === 'audit-legacy-files-bootstrap'
        ? ['/run/nonempty/legacy-files-bootstrap.php'] : ['/run/nonempty/legacy-history-producer.php', 'normal'];
    return array_merge($command, ['--freeze-bytes=' . $freezePin['bytes'], '--freeze-sha256=' . $freezePin['sha256']]);
}
function nhe_proc_command(int $pid): array
{
    nhe_expect($pid > 0, 'NHE_ACTUAL_PHP_PROCESS');
    $binary = realpath(PHP_BINARY); $executable = @readlink('/proc/' . $pid . '/exe');
    nhe_expect(PHP_BINARY === '/usr/local/bin/php' && $binary === PHP_BINARY && is_string($executable)
        && realpath($executable) === $binary, 'NHE_REAL_FIXED_PHP_EXECUTABLE');
    $raw = @file_get_contents('/proc/' . $pid . '/cmdline');
    nhe_expect(is_string($raw) && $raw !== '' && strlen($raw) <= 16384 && str_ends_with($raw, "\0"),
        'NHE_COMPLETE_NATIVE_PHP_COMMAND');
    $command = explode("\0", substr($raw, 0, -1));
    foreach ($command as $argument) nhe_expect($argument !== '', 'NHE_NO_EMPTY_NATIVE_COMMAND_ARGUMENT');
    return $command;
}
function nhe_qualify_source(array $freezePin, string $role, ?string $child = null): array
{
    nhe_expect(in_array($role, ['audit-legacy-files-bootstrap', 'audit-legacy-history-producer'], true)
        && ($child === null || ($role === 'audit-legacy-history-producer' && in_array($child, ['http', 'channel'], true))),
        'NHE_FIXED_REGISTERED_PARENT_OR_CHILD_ROLE');
    $freezePath = '/run/audit/preparation-source-binding.json';
    $raw = nhe_file($freezePath, $freezePin); $freeze = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    nhe_expect(is_array($freeze) && ($freeze['schema'] ?? null) === 1
        && ($freeze['status'] ?? null) === 'CLEAN_SOURCE_FROZEN_ONLY', 'NHE_EXTERNAL_FROZEN_SOURCE');
    $authority = $freeze['nonempty_history'] ?? null;
    nhe_expect(is_array($authority), 'NHE_FROZEN_AUTHORITY_REQUIRED');
    nhe_keys($authority, ['schema', 'legacy_source_sha', 'requested_legacy_repo_source', 'legacy_source_files',
        'legacy_source_directories', 'control_files'], 'NHE_AUTHORITY_KEYS');
    nhe_expect($authority['schema'] === 1 && $authority['legacy_source_sha'] === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
        && is_string($authority['requested_legacy_repo_source']) && $authority['requested_legacy_repo_source'] !== ''
        && array_is_list($authority['control_files']), 'NHE_COMPLETE_477_AUTHORITY');
    $controls = array_column($authority['control_files'], null, 'path');
    $names = array_keys($controls); sort($names, SORT_STRING);
    nhe_expect(count($authority['control_files']) === 11 && array_keys($controls) === $names && $names === [
        '/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-files-bootstrap.php',
        '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php',
        '/run/nonempty/legacy-history-producer.php', '/run/nonempty/legacy-history-router.php',
        '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php',
        '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'], 'NHE_EXACT_COMPLETE_CONTROL_ELEVEN');
    foreach ($controls as $path => $pin) nhe_file($path, $pin);
    nhe_expect(realpath(__FILE__) === '/run/nonempty/legacy-app.php', 'NHE_FIXED_LIBRARY');
    require_once '/workspace/help/audit-regressions/isolated/ms4-migration.php';
    $driverRows = array_column($freeze['driver_source_files'] ?? [], null, 'path');
    foreach ($controls as $path => $pin) if (str_starts_with($path, '/workspace/')) {
        $relative = substr($path, strlen('/workspace/'));
        nhe_expect(isset($driverRows[$relative])
            && array_replace($driverRows[$relative], ['path' => $path]) === $pin, 'NHE_CURRENT_CONTROL_DRIVER_JOIN');
        $other = '/source/' . substr($path, strlen('/workspace/'));
        nhe_file($other, array_replace($pin, ['path' => $other]));
    }
    foreach (['/run/audit/actual-run-context.json', '/run/audit/manifest.json'] as $path)
        nhe_expect(is_file($path) && !is_link($path), 'NHE_REGULAR_RUN_PUBLIC_INPUT');
    $contextRaw = file_get_contents('/run/audit/actual-run-context.json');
    $manifestRaw = file_get_contents('/run/audit/manifest.json');
    $context = json_decode($contextRaw, true, 64, JSON_THROW_ON_ERROR); $manifest = json_decode($manifestRaw, true, 64, JSON_THROW_ON_ERROR);
    $run = getenv('AUDIT_RUN_ID'); $source = getenv('AUDIT_SOURCE_SHA');
    nhe_expect(PHP_SAPI === ($child === 'http' ? 'cli-server' : 'cli') && PHP_INT_SIZE >= 8
        && audit_run_dir() === '/run/audit' && audit_repo_dir() === '/workspace'
        && getenv('AUDIT_ISOLATED') === '1' && getenv('AUDIT_GUARD_MODE') === 'read-only'
        && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', (string) $run) === 1
        && preg_match('/\A[a-f0-9]{40}\z/D', (string) $source) === 1
        && ($freeze['source_sha'] ?? '') === $source && ($context['source_sha'] ?? '') === $source
        && ($context['run_id'] ?? '') === $run && ($context['freeze_sha256'] ?? '') === $freezePin['sha256']
        && ($context['repo_dir'] ?? '') === ($freeze['requested_repo_source'] ?? null)
        && ($context['project'] ?? '') === 'tianli-audit-' . $run
        && ($manifest['run_id'] ?? '') === $run && ($manifest['commit_sha'] ?? '') === $source
        && ($manifest['project'] ?? '') === $context['project'] && ($manifest['network_internal'] ?? null) === true
        && ($manifest['isolation'] ?? '') === 'ISO-FULL' && ($manifest['guard_mode'] ?? '') === 'read-only',
        'NHE_CURRENT_CONTROL_AND_RUN_IDENTITY');
    $stageAuthority = $manifest['nonempty_history'] ?? null;
    nhe_expect(is_array($stageAuthority), 'NHE_STAGE_REGISTRATION_REQUIRED');
    nhe_keys($stageAuthority, ['schema', 'stages'], 'NHE_STAGE_REGISTRATION_KEYS');
    nhe_expect($stageAuthority['schema'] === 1 && isset($stageAuthority['stages'][$role]), 'NHE_REGISTERED_STAGE');
    $stage = $stageAuthority['stages'][$role]; nhe_keys($stage, ['created', 'binding'], 'NHE_STAGE_PINS');
    $base = '/run/audit/evidence/' . $role;
    nhe_expect(($stage['created']['path'] ?? '') === $base . '-container-created.json'
        && ($stage['binding']['path'] ?? '') === $base . '-source-binding.json', 'NHE_FIXED_STAGE_LEAVES');
    $createdRaw = nhe_file($stage['created']['path'], $stage['created']); $bindingRaw = nhe_file($stage['binding']['path'], $stage['binding']);
    $facts = json_decode($createdRaw, true, 64, JSON_THROW_ON_ERROR); $binding = json_decode($bindingRaw, true, 64, JSON_THROW_ON_ERROR);
    nhe_keys($binding, ['schema', 'run_id', 'source_sha', 'legacy_source_sha', 'role', 'container_id', 'authority_sha256', 'mounts'], 'NHE_STAGE_BINDING_KEYS');
    $serviceNames = array_keys($manifest['services'] ?? []); sort($serviceNames, SORT_STRING);
    $expectedServices = [$role, 'audit-mysql', 'audit-redis']; sort($expectedServices, SORT_STRING);
    nhe_expect($serviceNames === $expectedServices, 'NHE_ONLY_REGISTERED_STAGE_AND_TWO_BASE_ROLES');
    $service = $manifest['services'][$role]; $cid = $service['id'] ?? '';
    $command = nhe_role_command($freezePin, $role);
    nhe_expect($binding['schema'] === 1 && $binding['run_id'] === $run && $binding['source_sha'] === $source
        && $binding['legacy_source_sha'] === $authority['legacy_source_sha'] && $binding['role'] === $role
        && $binding['container_id'] === $cid && $binding['authority_sha256'] === hash('sha256', nhe_json($authority))
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) $cid) === 1 && ($facts['id'] ?? '') === $cid
        && ($facts['run_id'] ?? '') === $run && ($facts['source_sha'] ?? '') === $source
        && gethostname() === substr($cid, 0, 12) && ($facts['hostname'] ?? '') === gethostname()
        && ($facts['image'] ?? '') === M4M_IMAGE && ($service['image_id'] ?? '') === M4M_IMAGE
        && ($facts['status'] ?? '') === 'created' && ($facts['pid'] ?? null) === 0 && ($facts['restart_count'] ?? null) === 0
        && ($facts['read_only'] ?? null) === true && ($facts['privileged'] ?? null) === false
        && ($facts['workdir'] ?? '') === '/legacy-workspace/crmeb' && ($facts['entrypoint'] ?? null) === ['php']
        && ($facts['command'] ?? null) === $command
        && ($facts['network_mode'] ?? '') === ($manifest['network']['name'] ?? null)
        && ($facts['labels']['tianli.audit'] ?? '') === '1' && ($facts['labels']['tianli.audit.run'] ?? '') === $run
        && ($facts['labels']['com.docker.compose.project'] ?? '') === $context['project']
        && ($facts['labels']['com.docker.compose.service'] ?? '') === $role, 'NHE_REAL_CREATED_REGISTERED_STAGE');
    nhe_expect(($facts['cap_drop'] ?? null) === ['ALL'] && count($facts['security_options'] ?? []) === 1
        && in_array($facts['security_options'][0], ['no-new-privileges', 'no-new-privileges:true'], true)
        && ($facts['port_bindings'] ?? []) === [] && count($facts['networks'] ?? []) === 1
        && isset($facts['networks'][$manifest['network']['name']]), 'NHE_PRIVATE_CONTAINER_SAFETY');
    foreach ($facts['ports'] ?? [] as $ports) nhe_expect($ports === null, 'NHE_NO_PUBLISHED_PORT');
    $wanted = [];
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'],
        '/legacy-source' => $authority['requested_legacy_repo_source'], '/legacy-workspace' => $authority['requested_legacy_repo_source'],
        '/run/nonempty' => $context['prep_dir'], '/run/audit' => $context['run_dir']] as $destination => $host) {
        nhe_expect(is_string($host) && $host !== '', 'NHE_ACTUAL_HOST_PATH');
        $wanted[] = ['Type' => 'bind', 'Source' => audit_canonical_host_bind_source($host),
            'Destination' => $destination, 'RW' => $destination === '/run/audit', 'Name' => ''];
    }
    $privateVolumes = ['app-secrets' => '/run/audit-app'];
    if ($role === 'audit-legacy-files-bootstrap') $privateVolumes['bootstrap-secrets'] = '/run/audit-bootstrap';
    if ($role === 'audit-legacy-history-producer') $privateVolumes['fixture-secrets'] = '/run/audit-fixtures';
    foreach ($privateVolumes as $leaf => $destination) {
        $name = $context['project'] . '_' . $leaf;
        nhe_expect(in_array($name, $manifest['volumes'] ?? [], true) && isset($manifest['volume_identities'][$name]), 'NHE_OWN_PRIVATE_VOLUME');
        $mount = m4m_mount($facts, $destination);
        nhe_expect(($mount['Name'] ?? '') === $name && ($mount['Type'] ?? '') === 'volume' && ($mount['RW'] ?? null) === false,
            'NHE_PRIVATE_VOLUME_RO');
        $wanted[] = ['Type' => 'volume', 'Source' => $mount['Source'], 'Destination' => $destination, 'RW' => false, 'Name' => $name];
    }
    usort($wanted, static fn(array $a, array $b): int => strcmp(nhe_json($a), nhe_json($b)));
    nhe_expect(nhe_mount_rows($facts['mounts']) === $wanted && $binding['mounts'] === $wanted, 'NHE_EXACT_DERIVED_MOUNTS');
    $tmpfs = array_keys($facts['tmpfs'] ?? []); sort($tmpfs, SORT_STRING);
    nhe_expect($tmpfs === ['/legacy-runtime', '/tmp'], 'NHE_ONLY_TWO_PRIVATE_TMPFS');
    $actualMounts = [];
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode(' - ', $line, 2); $left = explode(' ', $parts[0]); $right = explode(' ', $parts[1] ?? '');
        $actualMounts[$left[4] ?? ''] = ['options' => explode(',', $left[5] ?? ''), 'type' => $right[0] ?? ''];
    }
    foreach ($wanted as $mount) nhe_expect(isset($actualMounts[$mount['Destination']])
        && in_array($mount['RW'] ? 'rw' : 'ro', $actualMounts[$mount['Destination']]['options'], true), 'NHE_ACTUAL_MOUNT_ACCESS');
    foreach (['/tmp', '/legacy-runtime'] as $path) nhe_expect(($actualMounts[$path]['type'] ?? '') === 'tmpfs'
        && in_array('rw', $actualMounts[$path]['options'], true), 'NHE_ACTUAL_PRIVATE_TMPFS');
    $stats = [];
    // The parent checks both complete trees; children verify their loaded frozen closure.
    if ($child === null) foreach (['/legacy-source', '/legacy-workspace'] as $root)
        $stats[$root] = nhe_tree($root, $authority['legacy_source_files'], $authority['legacy_source_directories']);
    $inputStats = [];
    foreach (array_merge(array_keys($controls), [$freezePath, '/run/audit/actual-run-context.json',
        '/run/audit/manifest.json', $stage['created']['path'], $stage['binding']['path']]) as $path) {
        clearstatcache(true, $path); $stat = lstat($path);
        $inputStats[$path] = array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']));
    }
    $state = ['freeze_pin' => $freezePin, 'authority' => $authority, 'context' => $context,
        'manifest_raw' => $manifestRaw, 'context_raw' => $contextRaw, 'created_raw' => $createdRaw,
        'binding_raw' => $bindingRaw, 'container_id' => $cid, 'source_stats' => $stats, 'input_stats' => $inputStats,
        'source_stats_scope' => $child === null ? 'FULL_FROZEN_DUAL_TREE_SCAN' : 'FROZEN_LOADED_FILES_ONLY'];
    nhe_includes($state);
    if ($child === null) {
        nhe_expect(($_SERVER['argv'] ?? null) === $command, 'NHE_REAL_DIRECT_PARENT_ARGUMENTS');
        $process = audit_app_proc_stat(getmypid());
        nhe_expect(is_array($process) && $process['pid'] === getmypid()
            && !in_array($process['state'], ['Z', 'X', 'x'], true)
            && nhe_proc_command($process['pid']) === array_merge($facts['entrypoint'], $command),
            'NHE_CURRENT_PARENT_NATIVE_PROCESS');
        $afterProcess = audit_app_proc_stat($process['pid']);
        nhe_expect(is_array($afterProcess) && !in_array($afterProcess['state'], ['Z', 'X', 'x'], true),
            'NHE_CURRENT_PARENT_STILL_LIVE');
        foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $key)
            nhe_expect($afterProcess[$key] === $process[$key], 'NHE_CURRENT_PARENT_IDENTITY_UNCHANGED');
        $state['process'] = $process;
    }
    return $state;
}
function nhe_qualify(array $freezePin, string $role): array
{
    $state = nhe_qualify_source($freezePin, $role);
    audit_guard_assert();
    return $state;
}
/** Return the original four private values in RAM only, after full Source/role qualification. */
function nhe_actor_private(#[SensitiveParameter] array $qualified): array
{
    $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '1');
    nhe_expect($previousIgnoreArgs !== false && ini_get('zend.exception_ignore_args') === '1',
        'NHE_ACTORS_REAL_PRIVATE_EXCEPTION_ARGUMENT_BOUNDARY');
    $context = $qualified['context']; $run = $context['run_id']; $source = $context['source_sha'];
    nhe_expect(($qualified['source_stats_scope'] ?? null) === 'FULL_FROZEN_DUAL_TREE_SCAN'
        && ($qualified['process']['pid'] ?? null) === getmypid()
        && ($qualified['container_id'] ?? null) === json_decode($qualified['created_raw'], true, 64, JSON_THROW_ON_ERROR)['id'],
        'NHE_ACTORS_QUALIFIED_ORIGINAL_PARENT_REQUIRED');
    $parent = json_decode($qualified['created_raw'], true, 64, JSON_THROW_ON_ERROR);
    nhe_expect(($parent['labels']['com.docker.compose.service'] ?? null) === 'audit-legacy-history-producer',
        'NHE_ACTORS_PRODUCER_PARENT_ONLY');
    nhe_includes($qualified);
    $nine = static function (array $stat): array {
        $result = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            nhe_expect(isset($stat[$key]) && is_int($stat[$key]), 'NHE_ACTORS_NATIVE_STAT9'); $result[$key] = $stat[$key];
        }
        return $result;
    };
    $public = static function (string $path) use ($nine): string {
        clearstatcache(true, $path); $before = lstat($path);
        nhe_expect(is_array($before) && is_file($path) && !is_link($path)
            && ($before['mode'] & 0170000) === 0100000, 'NHE_ACTORS_ORIGINAL_PUBLIC_FILE');
        $handle = fopen($path, 'rb'); nhe_expect(is_resource($handle), 'NHE_ACTORS_PUBLIC_OPEN');
        try {
            $fdBefore = $nine(fstat($handle)); $raw = stream_get_contents($handle); $fdAfter = $nine(fstat($handle));
            nhe_expect(is_string($raw) && feof($handle) && strlen($raw) === $before['size']
                && $fdBefore === $fdAfter && $fdBefore === $nine($before), 'NHE_ACTORS_COMPLETE_PUBLIC_FD_READ');
        } finally { fclose($handle); }
        clearstatcache(true, $path);
        nhe_expect($nine(lstat($path)) === $fdBefore, 'NHE_ACTORS_PUBLIC_NAMED_STAT_STABLE'); return $raw;
    };
    $resultRaw = $public('/run/audit/evidence/prepare-result.json');
    $result = json_decode($resultRaw, true, 128, JSON_THROW_ON_ERROR);
    nhe_expect(is_array($result) && ($result['schema'] ?? null) === 1
        && ($result['scope'] ?? null) === 'MS4_PREREQUISITES_ONLY'
        && ($result['status'] ?? null) === 'PREPARED_PRIVATE_CONFIG_ONLY' && ($result['native_exit'] ?? null) === 0
        && ($result['run_id'] ?? null) === $run && ($result['source_sha'] ?? null) === $source,
        'NHE_ACTORS_SUCCESSFUL_ORIGINAL_PREPARE_RESULT');
    $proof = $result['nonempty_history_actors_private'] ?? null;
    nhe_expect(is_array($proof), 'NHE_ACTORS_PREPARE_PRIVATE_METADATA_REQUIRED');
    nhe_keys($proof, ['schema', 'run_id', 'source_sha', 'freeze_sha256', 'prepare_container_id', 'prepare_created',
        'volume', 'path', 'access', 'stat9', 'exact_four_format', 'complete_readback'], 'NHE_ACTORS_PREPARE_PROOF_KEYS');
    nhe_expect($proof['schema'] === 1 && $proof['run_id'] === $run && $proof['source_sha'] === $source
        && $proof['freeze_sha256'] === $context['freeze_sha256']
        && $proof['path'] === '/run/audit-fixtures/nonempty-history-actors.json'
        && $proof['access'] === 'PREPARE_RW_CONSUMER_RO' && $proof['exact_four_format'] === true
        && $proof['complete_readback'] === true, 'NHE_ACTORS_SAME_RUN_SOURCE_AND_PRIVATE_PATH');
    $createdPath = '/run/audit/evidence/prepare-created.json';
    nhe_expect(($proof['prepare_created']['path'] ?? null) === $createdPath, 'NHE_ACTORS_FIXED_PREPARE_CREATED_PATH');
    $createdRaw = nhe_file($createdPath, $proof['prepare_created']);
    $created = json_decode($createdRaw, true, 128, JSON_THROW_ON_ERROR);
    $terminal = json_decode($public('/run/audit/evidence/prepare-terminal.json'), true, 128, JSON_THROW_ON_ERROR);
    $binding = $result['binding'] ?? null;
    nhe_expect(is_array($binding), 'NHE_ACTORS_ORIGINAL_PREPARE_BINDING');
    nhe_keys($binding, ['container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256'],
        'NHE_ACTORS_ORIGINAL_PREPARE_BINDING_KEYS');
    nhe_expect($proof['prepare_container_id'] === ($created['id'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/D', $proof['prepare_container_id']) === 1
        && $binding['container_id'] === $created['id'] && $binding['created_sha256'] === hash('sha256', $createdRaw)
        && $binding['source_sha'] === $source && $binding['freeze_sha256'] === $context['freeze_sha256']
        && is_int($binding['entry_namespace_pid']) && $binding['entry_namespace_pid'] > 0
        && ($created['run_id'] ?? null) === $run && ($created['source_sha'] ?? null) === $source
        && ($created['proof_name'] ?? null) === 'prepare' && ($created['image'] ?? null) === M4M_IMAGE
        && ($created['status'] ?? null) === 'created' && ($created['pid'] ?? null) === 0
        && ($created['restart_count'] ?? null) === 0 && ($created['read_only'] ?? null) === true
        && ($created['privileged'] ?? null) === false && ($created['entrypoint'] ?? null) === ['php']
        && ($created['command'] ?? null) === ['/run/ms4-prep/ms4-initialize.php', 'prepare']
        && ($created['labels']['tianli.audit'] ?? null) === '1'
        && ($created['labels']['tianli.audit.run'] ?? null) === $run
        && ($created['labels']['com.docker.compose.project'] ?? null) === $context['project']
        && ($created['labels']['com.docker.compose.service'] ?? null) === 'audit-bootstrap'
        && ($terminal['id'] ?? null) === $created['id'] && ($terminal['status'] ?? null) === 'exited'
        && ($terminal['pid'] ?? null) === 0 && ($terminal['exit_code'] ?? null) === 0
        && ($terminal['restart_count'] ?? null) === 0, 'NHE_ACTORS_ORIGINAL_PREPARE_NATIVE_ZERO_IDENTITY');
    foreach (['image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command',
        'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $key)
        nhe_expect($created[$key] === $terminal[$key], 'NHE_ACTORS_PREPARE_CREATED_TERMINAL_SAME_IDENTITY');
    $mountMap = static function (array $mounts): array {
        $map = [];
        foreach ($mounts as $mount) {
            nhe_expect(is_array($mount) && is_string($mount['Destination'] ?? null)
                && !isset($map[$mount['Destination']]), 'NHE_ACTORS_COMPLETE_UNIQUE_NATIVE_MOUNT');
            $map[$mount['Destination']] = $mount;
        }
        ksort($map, SORT_STRING); return $map;
    };
    nhe_expect($mountMap($created['mounts']) === $mountMap($terminal['mounts']),
        'NHE_ACTORS_COMPLETE_PREPARE_MOUNTS_PRESERVED');
    $volumeName = $context['project'] . '_fixture-secrets';
    $volumes = array_values(array_filter($context['initial_volumes'],
        static fn(array $row): bool => ($row['name'] ?? null) === $volumeName));
    $manifest = json_decode($qualified['manifest_raw'], true, 128, JSON_THROW_ON_ERROR);
    nhe_expect(count($volumes) === 1 && $proof['volume'] === $volumes[0]
        && ($volumes[0]['labels']['tianli.audit'] ?? null) === '1'
        && ($volumes[0]['labels']['tianli.audit.run'] ?? null) === $run
        && ($volumes[0]['labels']['com.docker.compose.project'] ?? null) === $context['project']
        && ($manifest['volume_identities'][$volumeName] ?? null) === $volumes[0]['created_at'],
        'NHE_ACTORS_ORIGINAL_SAME_COMPLETE_FIXTURE_VOLUME');
    $prepareMount = m4m_mount($created, '/run/audit-fixtures');
    $consumerMount = m4m_mount($parent, '/run/audit-fixtures');
    nhe_expect($prepareMount['Type'] === 'volume' && $prepareMount['Name'] === $volumeName && $prepareMount['RW'] === true
        && $consumerMount['Type'] === 'volume' && $consumerMount['Name'] === $volumeName && $consumerMount['RW'] === false
        && $consumerMount['Source'] === $prepareMount['Source'], 'NHE_ACTORS_SAME_PRIVATE_VOLUME_RW_TO_RO_HANDOFF');
    $root = '/run/audit-fixtures'; $path = $proof['path']; clearstatcache(true, $root); $directory = lstat($root);
    nhe_expect(is_array($directory) && is_dir($root) && !is_link($root) && realpath($root) === $root
        && ($directory['mode'] & 07777) === 0700 && $directory['uid'] === posix_geteuid(),
        'NHE_ACTORS_REAL_PRIVATE_DIRECTORY_OWNER');
    clearstatcache(true, $path); $before = lstat($path);
    nhe_expect(is_array($before) && is_file($path) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 07777) === 0600
        && $before['nlink'] === 1 && $before['uid'] === posix_geteuid()
        && $nine($before) === $proof['stat9'], 'NHE_ACTORS_ORIGINAL_PRIVATE_FILE_METADATA');
    $handle = fopen($path, 'rb'); nhe_expect(is_resource($handle), 'NHE_ACTORS_PRIVATE_READ_OPEN');
    try {
        $fdBefore = $nine(fstat($handle)); $raw = stream_get_contents($handle); $fdAfter = $nine(fstat($handle));
        nhe_expect(is_string($raw) && feof($handle) && strlen($raw) === $before['size']
            && $fdBefore === $fdAfter && $fdBefore === $nine($before), 'NHE_ACTORS_COMPLETE_PRIVATE_FD_READ');
    } finally { fclose($handle); }
    clearstatcache(true, $path);
    nhe_expect($nine(lstat($path)) === $fdBefore, 'NHE_ACTORS_PRIVATE_NAMED_STAT_STABLE');
    $private = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    nhe_expect(is_array($private), 'NHE_ACTORS_PRIVATE_OBJECT');
    require_once '/run/nonempty/legacy-actors.php'; m4lh_actor_private($private);
    foreach ($private as $value) nhe_expect(preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1,
        'NHE_ACTORS_ORIGINAL_FOUR_RANDOM_VALUE_FORMAT');
    nhe_expect(nhe_json($private) === $raw, 'NHE_ACTORS_ORIGINAL_COMPLETE_PRIVATE_SERIALIZATION');
    nhe_includes($qualified); unset($raw, $value); return $private;
}

function nhe_parent_process(array $state): array
{
    $root = '/legacy-runtime'; $path = $root . '/native-parent.json';
    clearstatcache(true, $root); clearstatcache(true, $path);
    $directory = @lstat($root); $before = @lstat($path);
    nhe_expect(is_array($directory) && is_dir($root) && !is_link($root) && realpath($root) === $root
        && ($directory['mode'] & 07777) === 0700 && $directory['uid'] === posix_geteuid()
        && is_array($before) && is_file($path) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 07777) === 0600
        && $before['uid'] === posix_geteuid() && $before['nlink'] === 1
        && $before['dev'] === $directory['dev'] && $before['size'] > 0 && $before['size'] <= 16384,
        'NHE_REAL_PRIVATE_PARENT_RECEIPT_REQUIRED');
    $raw = file_get_contents($path);
    nhe_expect(is_string($raw) && strlen($raw) === $before['size'], 'NHE_COMPLETE_PARENT_RECEIPT_BYTES');
    $receipt = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    nhe_expect(is_array($receipt), 'NHE_PARENT_RECEIPT_OBJECT');
    nhe_keys($receipt, ['schema', 'run_id', 'source_sha', 'freeze_sha256', 'container_id', 'pid', 'starttime'],
        'NHE_PARENT_RECEIPT_EXACT_KEYS');
    nhe_expect($receipt['schema'] === 1 && $receipt['run_id'] === $state['context']['run_id']
        && $receipt['source_sha'] === $state['context']['source_sha']
        && $receipt['freeze_sha256'] === $state['freeze_pin']['sha256']
        && $receipt['container_id'] === $state['container_id']
        && is_int($receipt['pid']) && $receipt['pid'] > 0 && is_string($receipt['starttime'])
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $receipt['starttime']) === 1,
        'NHE_ACTUAL_REGISTERED_PARENT_RECEIPT_IDENTITY');
    $parent = audit_app_proc_stat($receipt['pid']);
    nhe_expect(is_array($parent) && $parent['pid'] === $receipt['pid']
        && $parent['starttime'] === $receipt['starttime'] && !in_array($parent['state'], ['Z', 'X', 'x'], true),
        'NHE_PARENT_RECEIPT_MATCHES_LIVE_PROCESS');
    $created = json_decode($state['created_raw'], true, 64, JSON_THROW_ON_ERROR);
    nhe_expect(is_array($created) && ($created['id'] ?? null) === $receipt['container_id']
        && ($created['entrypoint'] ?? null) === ['php'], 'NHE_PARENT_ORIGINAL_CREATED_EXECUTABLE');
    $command = nhe_proc_command($parent['pid']);
    nhe_expect($command === array_merge($created['entrypoint'],
        nhe_role_command($state['freeze_pin'], 'audit-legacy-history-producer')), 'NHE_ORIGINAL_PRODUCER_PARENT_COMMAND');
    $afterParent = audit_app_proc_stat($parent['pid']);
    nhe_expect(is_array($afterParent) && !in_array($afterParent['state'], ['Z', 'X', 'x'], true),
        'NHE_ORIGINAL_PARENT_STILL_LIVE');
    foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $key)
        nhe_expect($afterParent[$key] === $parent[$key], 'NHE_ORIGINAL_PARENT_IDENTITY_UNCHANGED');
    $again = file_get_contents($path);
    clearstatcache(true, $root); clearstatcache(true, $path);
    $afterDirectory = lstat($root); $after = lstat($path);
    nhe_expect(is_array($afterDirectory) && is_array($after) && !is_link($root) && !is_link($path)
        && $again === $raw, 'NHE_PARENT_RECEIPT_FULL_BYTES_UNCHANGED');
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        nhe_expect($directory[$key] === $afterDirectory[$key] && $before[$key] === $after[$key],
            'NHE_PARENT_RECEIPT_AND_PRIVATE_DIRECTORY_STAT_UNCHANGED');
    }
    return ['receipt' => $receipt, 'native' => $parent, 'command' => $command];
}
function nhe_child_qualify(array $freezePin, string $kind): array
{
    nhe_expect(in_array($kind, ['http', 'channel'], true), 'NHE_ONLY_FIXED_HTTP_OR_CHANNEL_CHILD');
    $state = nhe_qualify_source($freezePin, 'audit-legacy-history-producer', $kind);
    $parent = nhe_parent_process($state); $self = audit_app_proc_stat(getmypid());
    nhe_expect(is_array($self) && $self['pid'] === getmypid() && $self['pid'] > 1
        && $self['ppid'] === $parent['native']['pid'] && $self['pid'] !== $parent['native']['pid']
        && $self['pgid'] === $self['pid'] && $self['sid'] === $self['pid']
        && !in_array($self['pgid'], [$parent['native']['pid'], $parent['native']['pgid'], $parent['native']['sid']], true)
        && !in_array($self['state'], ['Z', 'X', 'x'], true) && is_string($self['starttime'])
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $self['starttime']) === 1
        && (strlen($self['starttime']) > strlen($parent['native']['starttime'])
            || (strlen($self['starttime']) === strlen($parent['native']['starttime'])
                && strcmp($self['starttime'], $parent['native']['starttime']) >= 0)),
        'NHE_REAL_DIRECT_CHILD_AND_OWN_SETSID_GROUP');
    $command = $kind === 'http'
        ? [PHP_BINARY, '-S', '127.0.0.1:8000', '-t', '/legacy-workspace/crmeb/public', '/run/nonempty/legacy-history-router.php']
        : [PHP_BINARY, '/run/nonempty/legacy-history-channel.php', 'start',
            '--freeze-bytes=' . $freezePin['bytes'], '--freeze-sha256=' . $freezePin['sha256']];
    nhe_expect(nhe_proc_command($self['pid']) === $command, 'NHE_ACTUAL_FIXED_CHILD_PHP_COMMAND');
    if ($kind === 'channel') nhe_expect(($_SERVER['argv'] ?? null) === array_slice($command, 1),
        'NHE_ORIGINAL_CHANNEL_START_AND_FREEZE_ARGUMENTS');
    foreach ([$self, $parent['native']] as $original) {
        $actual = audit_app_proc_stat($original['pid']);
        nhe_expect(is_array($actual) && !in_array($actual['state'], ['Z', 'X', 'x'], true), 'NHE_PARENT_CHILD_STILL_LIVE');
        foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $key)
            nhe_expect($actual[$key] === $original[$key], 'NHE_PARENT_CHILD_NATIVE_IDENTITY_UNCHANGED');
    }
    nhe_expect(nhe_proc_command($self['pid']) === $command
        && nhe_proc_command($parent['native']['pid']) === $parent['command'], 'NHE_PARENT_CHILD_COMMANDS_UNCHANGED');
    $state['parent_process'] = $parent;
    $state['child_process'] = ['kind' => $kind, 'native' => $self, 'command' => $command];
    nhe_includes($state);
    if ($kind === 'http') audit_guard_assert();
    return $state;
}
function nhe_app(array $state, array $migrator): object
{
    return nhe_build_app($state, $migrator, 'audit_migrator');
}
function nhe_business_app(array $state): object
{
    $config = audit_config();
    nhe_expect(($config['run_id'] ?? null) === getenv('AUDIT_RUN_ID')
        && ($config['database']['username'] ?? null) === 'audit_app', 'NHE_REAL_PRIVATE_DML_CONFIG');
    $database = $config['database']; unset($config);
    return nhe_build_app($state, $database, 'audit_app', nhe_business_self_id($state));
}
function nhe_business_self_id(array $state): int
{
    $root = '/run/audit/evidence/nonempty-history-phases/before-legacy-files';
    $archive = m4m_read($root . '/INDEX.json');
    nhe_expect(($archive['schema'] ?? null) === 1 && ($archive['phase'] ?? null) === 'before-legacy-files'
        && ($archive['run_id'] ?? null) === getenv('AUDIT_RUN_ID')
        && ($archive['source_sha'] ?? null) === getenv('AUDIT_SOURCE_SHA')
        && ($archive['legacy_source_sha'] ?? null) === $state['authority']['legacy_source_sha'], 'NHE_ORIGINAL_INITIALIZER_PHASE_IDENTITY');
    $pins = array_column($archive['files'] ?? [], null, 'path'); $proofs = [];
    foreach (['initializer-created.json', 'initializer-terminal.json', 'initializer-result.json'] as $leaf) {
        $relative = 'evidence/' . $leaf; $pin = $pins[$relative] ?? null;
        nhe_expect(is_array($pin), 'NHE_ARCHIVED_INITIALIZER_ORIGINAL_PIN');
        $expected = ['path' => '/run/audit/' . $relative, 'bytes' => $pin['bytes'], 'sha256' => $pin['sha256']];
        $raw = nhe_file($expected['path'], $expected);
        nhe_expect($raw === nhe_file($root . '/' . $relative, array_replace($expected, ['path' => $root . '/' . $relative])),
            'NHE_INITIALIZER_ORIGINAL_AND_ARCHIVE_IDENTICAL');
        $proofs[$leaf] = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    }
    $created = $proofs['initializer-created.json']; $terminal = $proofs['initializer-terminal.json'];
    $result = $proofs['initializer-result.json']; $id = $result['actual_self_mer_id'] ?? null;
    nhe_expect(($created['run_id'] ?? null) === getenv('AUDIT_RUN_ID')
        && ($created['source_sha'] ?? null) === getenv('AUDIT_SOURCE_SHA')
        && ($created['proof_name'] ?? null) === 'initializer'
        && is_string($created['id'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $created['id']) === 1
        && ($created['hostname'] ?? null) === substr($created['id'], 0, 12)
        && ($created['image'] ?? null) === M4M_IMAGE
        && ($created['labels']['tianli.audit'] ?? null) === '1'
        && ($created['labels']['tianli.audit.run'] ?? null) === getenv('AUDIT_RUN_ID')
        && ($created['labels']['com.docker.compose.project'] ?? null) === $state['context']['project']
        && ($created['labels']['com.docker.compose.service'] ?? null) === 'audit-ms4-initialize'
        && ($created['read_only'] ?? null) === true && ($created['privileged'] ?? null) === false
        && ($created['entrypoint'] ?? null) === ['php'] && ($created['workdir'] ?? null) === '/workspace'
        && ($created['command'] ?? null) === ['/run/ms4-prep/ms4-initialize.php']
        && ($created['status'] ?? null) === 'created' && ($created['pid'] ?? null) === 0
        && ($terminal['id'] ?? null) === ($created['id'] ?? null)
        && ($terminal['status'] ?? null) === 'exited' && ($terminal['pid'] ?? null) === 0
        && ($terminal['exit_code'] ?? null) === 0 && ($terminal['restart_count'] ?? null) === 0
        && ($result['schema'] ?? null) === 1 && ($result['scope'] ?? null) === 'MS4_PREREQUISITES_ONLY'
        && ($result['run_id'] ?? null) === getenv('AUDIT_RUN_ID') && ($result['source_sha'] ?? null) === getenv('AUDIT_SOURCE_SHA')
        && ($result['status'] ?? null) === 'VERIFIED_MS4_PREREQUISITES_ONLY' && ($result['native_exit'] ?? null) === 0
        && ($result['private_cleanup_verified'] ?? null) === true
        && ($result['binding']['container_id'] ?? null) === $created['id']
        && ($result['binding']['source_sha'] ?? null) === getenv('AUDIT_SOURCE_SHA')
        && ($result['binding']['created_sha256'] ?? null) === $pins['evidence/initializer-created.json']['sha256']
        && ($result['binding']['freeze_sha256'] ?? null) === $state['freeze_pin']['sha256']
        && is_int($id) && $id > 0 && $id <= 4294967295
        && is_array($result['children'] ?? null) && array_is_list($result['children']) && count($result['children']) === 6,
        'NHE_REAL_NORMAL_MS2_SELF_ID_PRODUCER');
    foreach (['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode',
        'command', 'entrypoint', 'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $field)
        nhe_expect(array_key_exists($field, $created) && array_key_exists($field, $terminal)
            && $created[$field] === $terminal[$field], 'NHE_INITIALIZER_COMPLETE_NATIVE_IMMUTABLE_IDENTITY');
    nhe_expect(is_array($created['mounts'] ?? null) && is_array($terminal['mounts'] ?? null)
        && array_is_list($created['mounts']) && array_is_list($terminal['mounts']) && $created['mounts'] !== []
        && ($created['restart_count'] ?? null) === 0
        && array_keys($created['networks'] ?? []) === array_keys($terminal['networks'] ?? []),
        'NHE_INITIALIZER_NATIVE_MOUNT_LIST_AND_SAME_NETWORK_RESTART');
    $mountSets = [];
    foreach ([$created['mounts'], $terminal['mounts']] as $mounts) {
        $encoded = [];
        foreach ($mounts as $mount) {
            nhe_expect(is_array($mount) && !array_is_list($mount), 'NHE_INITIALIZER_FULL_NATIVE_MOUNT_OBJECT');
            ksort($mount, SORT_STRING); $encoded[] = nhe_json($mount);
        }
        sort($encoded, SORT_STRING); $mountSets[] = $encoded;
    }
    nhe_expect($mountSets[0] === $mountSets[1], 'NHE_INITIALIZER_ALL_NATIVE_MOUNT_FIELDS_MULTISET_UNCHANGED');
    $expected = [['marketplace-ms1.php', 'check', 'applicable', false], ['marketplace-ms1.php', 'apply', 'completed', true],
        ['marketplace-ms1.php', 'check', 'completed', false], ['marketplace-ms2.php', 'check', 'applicable', false],
        ['marketplace-ms2.php', 'apply-structure', 'completed', true], ['marketplace-ms2.php', 'check', 'completed', false]];
    $pids = [];
    foreach ($result['children'] as $index => $child) {
        nhe_expect([$child['script'] ?? null, $child['mode'] ?? null] === array_slice($expected[$index], 0, 2)
            && ($child['native_exit'] ?? null) === 0 && ($child['stderr_bytes'] ?? null) === 0
            && ($child['result']['mode'] ?? null) === $expected[$index][1]
            && ($child['result']['state'] ?? null) === $expected[$index][2]
            && ($child['result']['changed'] ?? null) === $expected[$index][3]
            && is_int($child['pid'] ?? null) && $child['pid'] > 0 && !isset($pids[$child['pid']]),
            'NHE_SIX_ORIGINAL_NORMAL_PREREQUISITE_CHILDREN');
        $pids[$child['pid']] = true;
        if ($index >= 4) nhe_expect(($child['result']['self_mer_id'] ?? null) === $id
            && ($child['result']['enabled'] ?? null) === false && ($child['result']['ownership_phase'] ?? null) === 'structure',
            'NHE_ORIGINAL_APPLY_AND_CHECK_SAME_ACTUAL_SELF_ID');
    }
    nhe_expect(($result['children'][5]['result']['strict_sql_mode']['session'] ?? null) === true
        && ($result['children'][5]['result']['strict_sql_mode']['global'] ?? null) === true,
        'NHE_ORIGINAL_FINAL_MS2_STRICT_SESSION_AND_GLOBAL');
    return $id;
}
function nhe_build_app(array $state, array $database, string $principal, ?int $selfMerId = null): object
{
    $binding = json_decode($state['binding_raw'] ?? '', true, 64, JSON_THROW_ON_ERROR);
    $expectedRole = $principal === 'audit_migrator' ? 'audit-legacy-files-bootstrap' : 'audit-legacy-history-producer';
    nhe_expect(in_array($principal, ['audit_migrator', 'audit_app'], true) && is_array($binding)
        && ($binding['role'] ?? null) === $expectedRole && ($binding['container_id'] ?? null) === ($state['container_id'] ?? null)
        && ($binding['run_id'] ?? null) === getenv('AUDIT_RUN_ID')
        && ($binding['source_sha'] ?? null) === getenv('AUDIT_SOURCE_SHA')
        && gethostname() === substr((string) $state['container_id'], 0, 12), 'NHE_REGISTERED_ROLE_BOUND_APP_PRINCIPAL');
    nhe_keys($database, ['username', 'password'], 'NHE_EXACT_PRIVATE_DATABASE_FIELDS');
    nhe_expect(($database['username'] ?? null) === $principal && is_string($database['password'] ?? null)
        && preg_match('/\A[a-f0-9]{48}\z/D', $database['password']) === 1, 'NHE_ACTUAL_PRIVATE_DATABASE_PRINCIPAL');
    $expectedDatabase = $principal === 'audit_migrator'
        ? m4m_read('/run/audit-bootstrap/migrator.json') : audit_config()['database'];
    nhe_expect($database === $expectedDatabase, 'NHE_SAME_RUN_PRIVATE_DATABASE_INPUT'); unset($expectedDatabase, $binding);
    nhe_expect(!class_exists('ComposerAutoloaderInitf16474ac994ccc25392f403933800b79', false)
        && !class_exists('Composer\\Autoload\\ClassLoader', false) && !class_exists('think\\App', false)
        && !function_exists('app') && spl_autoload_functions() === [], 'NHE_NO_PRELOADED_APPLICATION');
    foreach (get_declared_classes() as $class) nhe_expect(!preg_match('/\A(?:app|think|crmeb)\\\\/i', $class), 'NHE_NO_CURRENT_CLASSES');
    $private = '/run/audit-app/legacy.env'; clearstatcache(true, $private); $privateStat = @lstat($private);
    nhe_expect(is_array($privateStat) && is_file($private) && !is_link($private)
        && ($privateStat['mode'] & 0777) === 0600 && $privateStat['uid'] === posix_geteuid(), 'NHE_PRIVATE_ENV_IDENTITY');
    nhe_expect(!file_exists('/legacy-workspace/crmeb/.env') && !file_exists('/legacy-source/crmeb/.env'), 'NHE_NO_HOST_ENV');
    require_once '/legacy-workspace/crmeb/vendor/autoload.php';
    $app = new \think\App('/legacy-workspace/crmeb/');
    $app->setRuntimePath('/legacy-runtime/'); $app->env->load($private); $config = audit_config();
    $app->env->set(['APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => $database['username'], 'DATABASE_PASSWORD' => $database['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
        'REDIS_REDIS_PASSWORD' => $config['redis_password']]);
    if ($principal === 'audit_app') {
        nhe_expect(is_int($selfMerId) && $selfMerId > 0 && $selfMerId <= 4294967295, 'NHE_ACTUAL_SELF_ID_BEFORE_INITIALIZE');
        $app->env->set(['MARKETPLACE_ENABLED' => false, 'MARKETPLACE_SELF_OPERATED_MER_ID' => (string) $selfMerId]);
    }
    unset($config, $database); $app->initialize();
    clearstatcache(true, $private); $afterPrivate = lstat($private);
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key)
        nhe_expect($privateStat[$key] === $afterPrivate[$key], 'NHE_PRIVATE_ENV_STAT_UNCHANGED');
    nhe_expect($app->getRootPath() === '/legacy-workspace/crmeb/' && $app->getRuntimePath() === '/legacy-runtime/'
        && app() === $app, 'NHE_UNIQUE_OLD_APP_ROOT');
    $connection = $app->db->connect();
    $probe = $connection->query('SELECT DATABASE() AS db,CURRENT_USER() AS actor');
    nhe_expect($probe === [['db' => 'audit_auth', 'actor' => $principal . '@%']],
        'NHE_SAME_REAL_APP_CONNECTION_READ_ONLY_NATIVE_PROBE');
    $writer = $connection->getPdo();
    nhe_expect($writer instanceof PDO && $writer->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
        && $writer->query('SELECT CURRENT_USER()')->fetchColumn() === $principal . '@%'
        && $writer->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')],
        'NHE_REAL_OLD_APP_WRITER_PRINCIPAL_AND_SENTINEL');
    if ($principal === 'audit_app') {
        nhe_expect(audit_grants_are_restricted($writer->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))
            && audit_redis()->get('audit:sentinel') === getenv('AUDIT_RUN_ID')
            && $app->config->get('database.connections.mysql.username') === 'audit_app'
            && $app->config->get('marketplace.enabled') === false
            && $app->config->get('marketplace.self_operated_mer_id') === (string) $selfMerId,
            'NHE_OLD_BUSINESS_DML_ONLY');
        nhe_expect(m4m_rows($writer, 'SELECT mer_id FROM eb_marketplace_merchant WHERE mer_id=?', [$selfMerId])
            === [['mer_id' => $selfMerId]], 'NHE_SAME_NATIVE_ORIGINAL_SELF_MERCHANT');
    }
    foreach (['think\\App', 'app\\AppService', 'app\\services\\system\\log\\SystemFileServices',
        'app\\services\\system\\log\\SystemFileMd5Services', 'app\\services\\other\\CacheServices'] as $class) {
        $reflection = new ReflectionClass($class); $file = $reflection->getFileName();
        nhe_expect(is_string($file) && str_starts_with($file, '/legacy-workspace/'), 'NHE_OLD_CLASS_SOURCE');
    }
    nhe_includes($state); return $app;
}
