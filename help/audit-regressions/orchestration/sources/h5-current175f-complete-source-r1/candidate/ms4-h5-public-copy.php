<?php
declare(strict_types=1);
/* Finite public snapshot only. Called by the gated HTTP bootstrap, never directly. */

function m6p_assert(bool $condition, string $code): void
{
    if (!$condition) throw new RuntimeException($code);
}
function m6p_keys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    m6p_assert($actual === $keys, 'HTTP_PUBLIC_CLOSED_KEYS');
}
function m6p_path(string $path): void
{
    m6p_assert($path !== '' && preg_match('~(^/|\\\\|[\x00-\x1f]|(^|/)\.\.?(/|$)|//|:)~', $path) !== 1,
        'HTTP_PUBLIC_RELATIVE_PATH');
}
function m6p_file(string $path, ?array $expected = null): string
{
    clearstatcache(true, $path); $before = lstat($path);
    m6p_assert(is_array($before) && ($before['mode'] & 0170000) === 0100000 && !is_link($path)
        && realpath($path) === $path, 'HTTP_PUBLIC_REGULAR_SOURCE');
    $handle = fopen($path, 'rb'); m6p_assert(is_resource($handle), 'HTTP_PUBLIC_SOURCE_OPEN');
    $keys = array_fill_keys(['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'], true);
    $identity = array_intersect_key($before, $keys);
    try {
        m6p_assert(array_intersect_key(fstat($handle), $keys) === $identity, 'HTTP_PUBLIC_OPEN_IDENTITY');
        $raw = stream_get_contents($handle);
        m6p_assert(is_string($raw) && strlen($raw) === $before['size']
            && array_intersect_key(fstat($handle), $keys) === $identity, 'HTTP_PUBLIC_FULL_SOURCE_READ');
    } finally { fclose($handle); }
    clearstatcache(true, $path);
    m6p_assert(array_intersect_key(lstat($path), $keys) === $identity, 'HTTP_PUBLIC_SOURCE_UNCHANGED');
    if ($expected !== null) m6p_assert(strlen($raw) === $expected['bytes']
        && hash('sha256', $raw) === $expected['sha256'], 'HTTP_PUBLIC_SOURCE_BYTES');
    return $raw;
}
function m6p_tree(string $root): array
{
    m6p_assert(is_dir($root) && !is_link($root) && realpath($root) === $root, 'HTTP_PUBLIC_REAL_ROOT');
    $rows = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        m6p_assert(!$file->isLink() && ($file->isDir() || $file->isFile()), 'HTTP_PUBLIC_NO_LINK_OR_SPECIAL');
        if ($file->isDir()) continue;
        $relative = substr($file->getPathname(), strlen($root) + 1); m6p_path($relative);
        $raw = m6p_file($root . '/' . $relative);
        $rows[$relative] = ['path' => $relative, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
    }
    ksort($rows, SORT_STRING); return $rows;
}
function m6p_rows(array $list): array
{
    m6p_assert(array_is_list($list) && $list !== [], 'HTTP_PUBLIC_NONEMPTY_SOURCE_SET'); $rows = [];
    foreach ($list as $item) {
        m6p_keys($item, ['path', 'bytes', 'sha256']); m6p_path($item['path']);
        m6p_assert(is_int($item['bytes']) && $item['bytes'] >= 0
            && preg_match('/\A[a-f0-9]{64}\z/D', $item['sha256']) === 1
            && !isset($rows[$item['path']]), 'HTTP_PUBLIC_DESCRIPTOR');
        $rows[$item['path']] = $item;
    }
    ksort($rows, SORT_STRING); return $rows;
}
function m6p_directories(string $root): array
{
    $rows = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST) as $file) {
        m6p_assert(!$file->isLink() && ($file->isFile() || $file->isDir()), 'HTTP_PUBLIC_DIRECTORY_CLOSURE');
        if ($file->isDir()) $rows[] = substr($file->getPathname(), strlen($root) + 1);
    }
    sort($rows, SORT_STRING); return $rows;
}
function m6p_write(string $root, string $relative, string $raw): void
{
    m6p_path($relative); $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) m6p_assert(mkdir(dirname($path), 0755, true), 'HTTP_PUBLIC_CREATE_PARENT');
    m6p_assert(!file_exists($path) && !is_link($path), 'HTTP_PUBLIC_CREATE_ONLY_FILE');
    $handle = fopen($path, 'xb'); m6p_assert(is_resource($handle), 'HTTP_PUBLIC_CREATE_OPEN');
    try {
        $offset = 0;
        while ($offset < strlen($raw)) {
            $written = fwrite($handle, substr($raw, $offset));
            m6p_assert(is_int($written) && $written > 0, 'HTTP_PUBLIC_FULL_WRITE'); $offset += $written;
        }
        m6p_assert(fflush($handle), 'HTTP_PUBLIC_FLUSH');
    } finally { fclose($handle); }
    m6p_assert(chmod($path, 0644) && m6p_file($path) === $raw, 'HTTP_PUBLIC_EXACT_TARGET');
}
function m6p_copy(array $profile, string $run, array $identity): array
{
    m6p_assert(PHP_SAPI === 'cli' && preg_match('/\A\d{14}[a-f0-9]{6}\z/D', $run) === 1,
        'HTTP_PUBLIC_FINITE_CALL');
    m6p_keys($identity, ['source_sha', 'freeze_sha256', 'source_identity']);
    m6p_assert($run === getenv('AUDIT_RUN_ID') && is_string($identity['source_sha'])
        && preg_match('/\A[a-f0-9]{40}\z/D', $identity['source_sha']) === 1
        && $identity['source_sha'] === getenv('AUDIT_SOURCE_SHA')
        && is_string($identity['freeze_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $identity['freeze_sha256']) === 1
        && is_array($identity['source_identity']), 'H5_PUBLIC_ACTUAL_IDENTITY_BINDING');
    $sourceIdentity = $identity['source_identity'];
    m6p_keys($sourceIdentity, ['proof_name', 'role', 'command', 'container_id', 'native_created_sha256']);
    m6p_assert($sourceIdentity['proof_name'] === 'customer-h5-public-copy'
        && $sourceIdentity['role'] === 'audit-ms4-customer-h5-public-copy'
        && $sourceIdentity['command'] === ['/run/ms4-prep/ms4-h5-public-copy.php', 'copy']
        && is_string($sourceIdentity['container_id']) && preg_match('/\A[a-f0-9]{64}\z/D', $sourceIdentity['container_id']) === 1
        && is_string($sourceIdentity['native_created_sha256'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $sourceIdentity['native_created_sha256']) === 1,
        'H5_PUBLIC_GATED_SOURCE_IDENTITY');
    $root = '/workspace/crmeb/public';
    m6p_assert(m6p_tree($root) === [] && m6p_directories($root) === [] && chmod($root, 0755), 'HTTP_PUBLIC_FRESH_VOLUME');
    $source = m6p_rows($profile['public_source_files']); $assets = m6p_rows($profile['h5_dist_files']);
    m6p_assert(m6p_tree('/source/crmeb/public') === $source && m6p_tree('/run/ms4-h5-dist') === $assets,
        'HTTP_PUBLIC_COMPLETE_FROZEN_INPUTS');
    m6p_assert(m6p_directories('/source/crmeb/public') === $profile['public_source_directories']
        && m6p_directories('/run/ms4-h5-dist') === $profile['h5_dist_directories'],
        'HTTP_PUBLIC_COMPLETE_FROZEN_DIRECTORIES');
    m6p_assert(!isset($source['install.lock']) && !file_exists('/source/crmeb/.env'), 'HTTP_PUBLIC_NO_HOST_CONFIG');
    $expected = $source;
    foreach ($profile['public_source_directories'] as $directory) {
        m6p_path($directory);
        if (!is_dir($root . '/' . $directory)) m6p_assert(mkdir($root . '/' . $directory, 0755, true), 'HTTP_PUBLIC_COPY_EMPTY_DIRECTORY');
    }
    foreach (array_merge([''], $profile['h5_dist_directories']) as $directory) {
        $target = '__ms4_assets/h5' . ($directory === '' ? '' : '/' . $directory); m6p_path($target);
        if (!is_dir($root . '/' . $target)) m6p_assert(mkdir($root . '/' . $target, 0755, true), 'H5_PUBLIC_COPY_EMPTY_DIRECTORY');
    }
    foreach ($source as $path => $row) m6p_write($root, $path, m6p_file('/source/crmeb/public/' . $path, $row));
    foreach ($assets as $path => $row) {
        $target = '__ms4_assets/h5/' . $path; m6p_assert(!isset($expected[$target]), 'HTTP_PUBLIC_NO_ASSET_COLLISION');
        m6p_write($root, $target, m6p_file('/run/ms4-h5-dist/' . $path, $row));
        $expected[$target] = ['path' => $target, 'bytes' => $row['bytes'], 'sha256' => $row['sha256']];
    }
    $png = m6p_file('/run/ms4-prep/ms4-order.png', $profile['synthetic_png']);
    $marker = '# AUDIT-RUN ' . $run . "\n";
    foreach (['install.lock' => $marker, '__ms4_fixture__/product.png' => $png] as $path => $raw) {
        m6p_assert(!isset($expected[$path]), 'HTTP_PUBLIC_NO_MARKER_IMAGE_COLLISION');
        m6p_write($root, $path, $raw);
        $expected[$path] = ['path' => $path, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)];
    }
    ksort($expected, SORT_STRING);
    $expectedDirectories = array_merge($profile['public_source_directories'], ['__ms4_assets', '__ms4_assets/h5']);
    foreach ($profile['h5_dist_directories'] as $directory) $expectedDirectories[] = '__ms4_assets/h5/' . $directory;
    foreach (array_keys($expected) as $path) {
        for ($directory = dirname($path); $directory !== '.'; $directory = dirname($directory)) {
            m6p_path($directory); $expectedDirectories[] = $directory;
        }
    }
    $expectedDirectories = array_values(array_unique($expectedDirectories)); sort($expectedDirectories, SORT_STRING);
    m6p_assert(m6p_tree($root) === $expected && m6p_tree('/source/crmeb/public') === $source
        && m6p_tree('/run/ms4-h5-dist') === $assets && m6p_directories($root) === $expectedDirectories
        && m6p_directories('/source/crmeb/public') === $profile['public_source_directories']
        && m6p_directories('/run/ms4-h5-dist') === $profile['h5_dist_directories'], 'HTTP_PUBLIC_FULL_CLOSED_AFTER_STATE');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        m6p_assert(!$file->isLink() && ($file->isDir() || $file->isFile())
            && chmod($file->getPathname(), $file->isDir() ? 0755 : 0644), 'HTTP_PUBLIC_SEAL_TREE');
    }
    $actualDirectories = m6p_directories($root);
    m6p_assert(chmod($root, 0755) && m6p_tree($root) === $expected && $actualDirectories === $expectedDirectories
        && m6p_tree('/source/crmeb/public') === $source && m6p_tree('/run/ms4-h5-dist') === $assets
        && m6p_directories('/source/crmeb/public') === $profile['public_source_directories']
        && m6p_directories('/run/ms4-h5-dist') === $profile['h5_dist_directories'], 'HTTP_PUBLIC_SEALED_CLOSED_SET');
    clearstatcache();
    $publisher = posix_geteuid();
    m6p_assert(fileowner($root) === $publisher && (fileperms($root) & 0777) === 0755, 'HTTP_PUBLIC_ROOT_PERMISSIONS');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST) as $file) m6p_assert($file->getOwner() === $publisher
            && ($file->getPerms() & 0777) === ($file->isDir() ? 0755 : 0644), 'HTTP_PUBLIC_ACTUAL_TREE_PERMISSIONS');
    return ['schema' => 1, 'scope' => 'MS4_CURRENT_H5_PUBLIC_SNAPSHOT_ONLY', 'run_id' => $run,
        'source_sha' => $identity['source_sha'], 'freeze_sha256' => $identity['freeze_sha256'],
        'source_identity' => $sourceIdentity, 'read_only_source' => true,
        'source_count' => count($source), 'h5_count' => count($assets), 'public_count' => count($expected),
        'files' => array_values($expected), 'directories' => $actualDirectories, 'inputs_unchanged' => true,
        'closed_set_verified' => true, 'marker_sha256' => hash('sha256', $marker), 'png_sha256' => hash('sha256', $png),
        'permissions' => ['publisher_uid' => $publisher, 'directory_mode' => '0755', 'file_mode' => '0644', 'verified' => true]];
}

/** The actual copy command is separate from importing the finite copy helpers. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1'); umask(0077);
    require_once '/run/ms4-prep/ms4-h5-bootstrap.php';
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) return false;
        $GLOBALS['m4c_warning'] = ['severity' => $severity, 'file' => basename($file), 'line' => $line];
        $GLOBALS['m4c_failed_assertion'] = 'M4C_PHP_WARNING'; throw new RuntimeException('M4C_PHP_WARNING');
    });
    try {
        m4cb_phase('source'); $source = m4ch_bootstrap_source('copy');
        $GLOBALS['m4ch_copy_source'] = $source;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void { $GLOBALS['m4ch_copy_term'] = true; });
        m4cb_phase('copy_volumes'); m4ch_bootstrap_volumes($source);
        m4cb_phase('copy_public');
        $identity = ['source_sha' => $source['source_sha'], 'freeze_sha256' => $source['freeze_sha256'],
            'source_identity' => ['proof_name' => $source['proof_name'], 'role' => $source['rule']['role'],
                'command' => $source['rule']['command'], 'container_id' => $source['container_id'],
                'native_created_sha256' => $source['native_created_sha256']]];
        $copied = m6p_copy($source['copy_profile'], $source['run_id'], $identity);
        m4cb_phase('final_source');
        m4c_expect(m4ch_bootstrap_source('copy') === $source, 'M4C_H5_COPY_ALL_FROZEN_SOURCES_UNCHANGED');
        m4c_entry_ms5_history($source);
        m4c_entry_early_history($source);
        m4c_expect(empty($GLOBALS['m4ch_copy_term']), 'M4C_H5_COPY_NOT_INTERRUPTED_BEFORE_RESULT');
        m4c_entry_publish('/run/audit/evidence/customer-h5-public-copy.json', $copied);
        m4c_expect(empty($GLOBALS['m4ch_copy_term']), 'M4C_H5_COPY_NOT_INTERRUPTED_AFTER_RESULT');
        echo json_encode(['status' => 'COPIED_CUSTOMER_H5_PUBLIC_BYTES_ONLY', 'native_exit' => 0,
            'public_files' => $copied['public_count'], 'public_directories' => count($copied['directories']),
            'App_HTTP_DOM' => 'NOT RUN', 'guard_SQL' => 'READ_ONLY_ISOLATION_ONLY'], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $error) {
        $source = $GLOBALS['m4ch_copy_source'] ?? null; $code = $GLOBALS['m4c_failed_assertion'] ?? '';
        $safe = ['schema' => 1, 'scope' => 'CUSTOMER_H5_PUBLIC_COPY_ONLY', 'status' => 'FAIL_PRESERVED',
            'phase' => $GLOBALS['m4c_phase'] ?? 'source', 'class' => get_class($error),
            'error_location' => ['file' => basename($error->getFile()), 'line' => $error->getLine()],
            'code' => is_string($code) && preg_match('/\AM4C_[A-Z0-9_]+\z/D', $code) === 1 ? $code : 'M4C_UNCLASSIFIED',
            'native_exit' => 1, 'App_HTTP_DOM' => 'NOT RUN'];
        if (is_array($source)) {
            foreach (['run_id', 'source_sha', 'container_id', 'freeze_sha256'] as $key) $safe[$key] = $source[$key];
            if (isset($GLOBALS['m4c_warning'])) $safe['warning'] = $GLOBALS['m4c_warning'];
            try { m4c_entry_publish('/run/audit/evidence/customer-h5-public-copy-failure.json', $safe); }
            catch (Throwable $ignored) {}
        }
        echo json_encode($safe, JSON_THROW_ON_ERROR) . "\n";
        exit(1);
    }
}
