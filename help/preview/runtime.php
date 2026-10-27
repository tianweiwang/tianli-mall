<?php
declare(strict_types=1);

/** Preparation of a newly created full-runtime preview; completed preparations are verified, never repaired. */
function preview_full_runtime_require(bool $condition): void
{
    if (!$condition) throw new RuntimeException('Preview runtime preparation rejected; preserve this instance');
}

function preview_full_runtime_write(string $path, string $bytes, int $mode = 0644, int $gid = 0): void
{
    $stream = fopen($path, 'x+b');
    preview_full_runtime_require(is_resource($stream));
    try {
        preview_full_runtime_require(fwrite($stream, $bytes) === strlen($bytes) && fflush($stream)
            && chown($path, 0) && chgrp($path, $gid) && chmod($path, $mode)
            && rewind($stream) && stream_get_contents($stream) === $bytes);
    } finally { fclose($stream); }
}

/** Same finite environment keys as preview_app, represented for the original INI readers. */
function preview_full_runtime_ini(): string
{
    $environment = preview_environment_values();
    preview_full_runtime_require(($environment['APP_ENV'] ?? null) === 'testing');
    $expected = ['APP_ENV' => 'testing'];
    foreach ($environment as $name => $value) {
        if ($name === 'APP_ENV') continue;
        preview_full_runtime_require(preg_match('/\APHP_(APP|DATABASE|CACHE|REDIS|AUTH|MARKETPLACE|UPGRADE)_([A-Z0-9_]+)\z/D', $name, $parts) === 1
            && is_string($value) && preg_match('/\A[A-Za-z0-9_.:\/-]*\z/D', $value) === 1);
        $expected[$parts[1]][$parts[2]] = $value;
    }
    $ini = "APP_ENV = \"testing\"\n";
    foreach ($expected as $section => $values) {
        if ($section === 'APP_ENV') continue;
        $ini .= "\n[" . $section . "]\n";
        foreach ($values as $key => $value) $ini .= $key . ' = "' . $value . '"' . "\n";
    }
    preview_full_runtime_require(parse_ini_string($ini, true, INI_SCANNER_RAW) === $expected);
    return $ini;
}

/** Release services consume the actual application template; bootstrap itself remains in testing. */
function preview_release_runtime_ini(array $config): string
{
    $template = '/source/help/docker/.env.application.example';
    preview_full_runtime_require(is_file($template) && !is_link($template));
    $expected = parse_ini_file($template, true, INI_SCANNER_RAW);
    preview_full_runtime_require(is_array($expected) && is_int($config['self_operated_mer_id'] ?? null)
        && $config['self_operated_mer_id'] > 0);
    $replacements = [
        'APP' => ['APP_KEY' => $config['signing_key']],
        'AUTH' => ['SESSION_EPOCH' => $config['auth']['session_epoch'], 'STAMP_KEY' => $config['auth']['stamp_key'],
            'ISSUER' => $config['auth']['issuer']],
        'DATABASE' => ['USERNAME' => $config['database']['app']['username'], 'PASSWORD' => $config['database']['app']['password'],
            'DATABASE' => $config['database']['name']],
        'REDIS' => ['REDIS_PASSWORD' => $config['redis_password']],
        'MARKETPLACE' => ['SELF_OPERATED_MER_ID' => (string)$config['self_operated_mer_id']],
    ];
    foreach ($replacements as $section => $values) {
        preview_full_runtime_require(is_array($expected[$section] ?? null));
        foreach ($values as $key => $value) {
            preview_full_runtime_require(array_key_exists($key, $expected[$section]) && is_string($value) && $value !== '');
            $expected[$section][$key] = $value;
        }
    }
    preview_full_runtime_require(($expected['DATABASE']['HOSTNAME'] ?? null) === 'mysql'
        && ($expected['REDIS']['REDIS_HOSTNAME'] ?? null) === 'redis');
    foreach (['ENABLED', 'TRADE_ENABLED', 'AFTERSALE_ENABLED', 'PLATFORM_CHAT_PRIVATE_WRITES_ENABLED',
        'PRODUCT_VIDEO_UPLOAD_ENABLED', 'PRODUCT_VIDEO_CLOUD_UPLOAD_ENABLED', 'PAYMENT_TEST_ENABLED'] as $key) {
        preview_full_runtime_require(($expected['MARKETPLACE'][$key] ?? null) === 'false');
    }
    foreach (['CUSTOMER_FORM_PRIVATE_ROOT', 'AFTERSALE_PRIVATE_ROOT', 'CUSTOMER_REFUND_PRIVATE_ROOT',
        'CUSTOMER_LICENCE_PRIVATE_ROOT', 'CUSTOMER_WITHDRAWAL_QR_PRIVATE_ROOT', 'PLATFORM_CHAT_PRIVATE_ROOT',
        'PRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT', 'PRODUCT_MEDIA_SCAN_PRIVATE_ROOT', 'PRODUCT_VIDEO_CLOUD_UPLOAD_PRIVATE_ROOT'] as $key) {
        $value = $expected['MARKETPLACE'][$key] ?? null;
        preview_full_runtime_require(is_string($value) && preg_match('/\A\/private\/[a-z-]+\z/D', $value) === 1);
    }
    $expected = ['APP_ENV' => 'production'] + $expected;
    $scalars = '';
    $sections = '';
    foreach ($expected as $section => $values) {
        preview_full_runtime_require(is_string($section) && preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $section) === 1);
        if (!is_array($values)) {
            preview_full_runtime_require(is_string($values) && preg_match('/\A[A-Za-z0-9_.:\/-]*\z/D', $values) === 1);
            $scalars .= $section . ' = "' . $values . '"' . "\n";
            continue;
        }
        $sections .= "\n[" . $section . "]\n";
        foreach ($values as $key => $value) {
            preview_full_runtime_require(is_string($key) && preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $key) === 1
                && is_string($value) && preg_match('/\A[A-Za-z0-9_.:\/-]*\z/D', $value) === 1);
            $sections .= $key . ' = "' . $value . '"' . "\n";
        }
    }
    $ini = $scalars . $sections;
    preview_full_runtime_require(parse_ini_string($ini, true, INI_SCANNER_RAW) === $expected);
    return $ini;
}

function preview_full_runtime_excluded(string $relative): bool
{
    return in_array($relative, ['vendor', 'runtime', 'public/uploads', 'public/install', 'public/install.lock', '.env', '.constant'], true)
        || str_starts_with($relative, '.env.') || str_starts_with($relative, '.constant.');
}

/** Only regular source files and directories enter the independent application volume. */
function preview_full_runtime_source(string $directory, string $relative, array &$manifest, ?string $destination = null, bool $excludeSource = true): void
{
    $entries = scandir($directory);
    preview_full_runtime_require(is_array($entries));
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $relative === '' ? $name : $relative . '/' . $name;
        if ($excludeSource && preview_full_runtime_excluded($path)) continue;
        $source = $directory . '/' . $name;
        $before = lstat($source);
        preview_full_runtime_require(is_array($before));
        $type = $before['mode'] & 0170000;
        preview_full_runtime_require($type === 0040000 || $type === 0100000);
        $target = $destination === null ? null : $destination . '/' . $name;
        if ($type === 0040000) {
            if ($target !== null) preview_full_runtime_require(mkdir($target, 0755) && chown($target, 0)
                && chgrp($target, 0) && chmod($target, 0755));
            $manifest[$path] = ['type' => 'directory'];
            preview_full_runtime_source($source, $path, $manifest, $target, $excludeSource);
        } else {
            $hash = hash_file('sha256', $source);
            preview_full_runtime_require(is_string($hash));
            if ($target !== null) {
                $input = fopen($source, 'rb');
                $output = fopen($target, 'x+b');
                preview_full_runtime_require(is_resource($input) && is_resource($output));
                try {
                    preview_full_runtime_require(stream_copy_to_stream($input, $output) === $before['size'] && fflush($output)
                        && chown($target, 0) && chgrp($target, 0) && chmod($target, 0644));
                } finally { fclose($input); fclose($output); }
                preview_full_runtime_require(hash_file('sha256', $target) === $hash);
            }
            clearstatcache(true, $source);
            $after = lstat($source);
            foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
                preview_full_runtime_require(is_array($after) && $before[$field] === $after[$field]);
            }
            $manifest[$path] = ['type' => 'file', 'size' => $before['size'], 'sha256' => $hash];
        }
    }
}

/** This function is called only for the three fixed writable volumes of the new instance. */
function preview_full_runtime_permissions(string $root, bool $public, bool $apply): void
{
    $paths = [$root];
    $rootStat = lstat($root);
    preview_full_runtime_require(is_array($rootStat) && ($rootStat['mode'] & 0170000) === 0040000);
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($entries as $entry) {
        preview_full_runtime_require(!$entry->isLink() && ($entry->isDir() || $entry->isFile()));
        $paths[] = $entry->getPathname();
    }
    foreach ($paths as $path) {
        $directory = is_dir($path);
        $mode = $public ? ($directory ? 0755 : 0644) : ($directory ? 0700 : 0600);
        if ($apply) preview_full_runtime_require(chown($path, 33) && chgrp($path, 33) && chmod($path, $mode));
        clearstatcache(true, $path);
        $stat = lstat($path);
        // Accept normal runtime directory modes; private and upload rules stay separate.
        $modes = !$apply && $root === '/workspace/crmeb/runtime'
            ? ($directory ? [0700, 0750, 0755] : [0600, 0640, 0644]) : [$mode];
        // Workerman itself creates only these two configured logs with mode 0622.
        if (!$apply && !$directory && in_array($path, ['/workspace/crmeb/runtime/timer.log',
            '/workspace/crmeb/runtime/workerman.log'], true)) $modes[] = 0622;
        preview_full_runtime_require(is_array($stat) && $stat['uid'] === 33 && $stat['gid'] === 33
            && in_array($stat['mode'] & 0777, $modes, true));
    }
}

/** Compare every copied/generated path and its ownership without exposing the private INI. */
function preview_full_runtime_verify(array $manifest, array $identity, array $receipt, string $ini): void
{
    $expected = $manifest;
    foreach (['vendor', 'runtime', 'public/uploads'] as $path) $expected[$path] = ['type' => 'directory'];
    $generated = ['public/install.lock' => '', '.env' => $ini,
        '.preview-runtime-preparing.json' => json_encode($identity, JSON_THROW_ON_ERROR) . "\n",
        '.preview-runtime-ready.json' => json_encode($receipt, JSON_THROW_ON_ERROR) . "\n"];
    foreach ($generated as $path => $bytes) {
        preview_full_runtime_require(!isset($expected[$path]));
        $expected[$path] = ['type' => 'file', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
    $actual = [];
    preview_full_runtime_source('/runtime-app', '', $actual, null, false);
    ksort($actual); ksort($expected);
    preview_full_runtime_require($actual === $expected);
    $expected[''] = ['type' => 'directory'];
    foreach ($expected as $path => $entry) {
        $mode = $entry['type'] === 'directory' ? 0755 : 0644;
        $gid = 0;
        if ($path === '.env') { $mode = 0640; $gid = 33; }
        if (in_array($path, ['.preview-runtime-preparing.json', '.preview-runtime-ready.json'], true)) $mode = 0600;
        clearstatcache(true, '/runtime-app/' . $path);
        $stat = lstat('/runtime-app/' . $path);
        preview_full_runtime_require(is_array($stat) && $stat['uid'] === 0 && $stat['gid'] === $gid
            && ($stat['mode'] & 0777) === $mode);
    }
    preview_full_runtime_permissions('/workspace/crmeb/runtime', false, false);
    preview_full_runtime_permissions('/workspace/crmeb/public/uploads', true, false);
    preview_full_runtime_permissions('/private', false, false);
}

/** The image supplies code and dependencies; this volume contains only private configuration. */
function preview_image_runtime_verify(array $identity, string $ini): void
{
    $target = '/run/preview-runtime-config';
    $marker = json_encode($identity, JSON_THROW_ON_ERROR) . "\n";
    $files = ['.env' => $ini, '.preview-runtime-preparing.json' => $marker, '.preview-runtime-ready.json' => $marker];
    $names = array_merge(['.', '..'], array_keys($files));
    sort($names, SORT_STRING);
    clearstatcache(true, $target);
    $directory = lstat($target);
    preview_full_runtime_require(is_array($directory) && ($directory['mode'] & 0170000) === 0040000
        && ($directory['mode'] & 0777) === 0755 && $directory['uid'] === 0 && $directory['gid'] === 0
        && scandir($target) === $names);
    foreach ($files as $name => $bytes) {
        $path = $target . '/' . $name;
        clearstatcache(true, $path);
        $stat = lstat($path);
        preview_full_runtime_require(is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && $stat['nlink'] === 1 && $stat['uid'] === 0 && $stat['gid'] === ($name === '.env' ? 33 : 0)
            && ($stat['mode'] & 0777) === ($name === '.env' ? 0640 : 0600)
            && $stat['size'] === strlen($bytes) && file_get_contents($path) === $bytes);
    }
    preview_full_runtime_permissions('/workspace/crmeb/runtime', false, false);
    preview_full_runtime_permissions('/workspace/crmeb/public/uploads', true, false);
    preview_full_runtime_permissions('/private', false, false);
}

function preview_prepare_image_runtime(array $config): array
{
    $target = '/run/preview-runtime-config';
    $stat = lstat($target);
    preview_full_runtime_require(is_array($stat) && ($stat['mode'] & 0170000) === 0040000);
    $releaseRuntime = ($config['release_runtime'] ?? false) === true;
    $identity = ['project' => $config['project'], 'run_id' => $config['run_id'],
        'feature' => $releaseRuntime ? 'release-runtime-v1' : 'image-runtime-v1'];
    $ini = $releaseRuntime ? preview_release_runtime_ini($config) : preview_full_runtime_ini();
    if (file_exists($target . '/.preview-runtime-ready.json') || is_link($target . '/.preview-runtime-ready.json')) {
        preview_image_runtime_verify($identity, $ini);
        return $identity + ['changed' => false];
    }
    // Never complete or overwrite a partial preparation from an earlier attempt.
    preview_full_runtime_require(scandir($target) === ['.', '..'] && chown($target, 0) && chgrp($target, 0)
        && chmod($target, 0755));
    preview_full_runtime_write($target . '/.preview-runtime-preparing.json', json_encode($identity, JSON_THROW_ON_ERROR) . "\n", 0600);
    preview_full_runtime_write($target . '/.env', $ini, 0640, 33);
    preview_full_runtime_permissions('/workspace/crmeb/runtime', false, true);
    preview_full_runtime_permissions('/workspace/crmeb/public/uploads', true, true);
    preview_full_runtime_permissions('/private', false, true);
    preview_full_runtime_write($target . '/.preview-runtime-ready.json', json_encode($identity, JSON_THROW_ON_ERROR) . "\n", 0600);
    preview_image_runtime_verify($identity, $ini);
    return $identity + ['changed' => true];
}

/** Enable video only in a stopped, already prepared testing image runtime. */
function preview_enable_image_video(bool $apply): array
{
    $config = preview_config();
    preview_full_runtime_require(PHP_SAPI === 'cli' && posix_geteuid() === 0
        && getenv('PREVIEW_FULL_RUNTIME') === '1' && getenv('PREVIEW_IMAGE_RUNTIME') === '1'
        && (getenv('PREVIEW_RELEASE_RUNTIME') ?: '0') === '0'
        && ($config['full_runtime'] ?? false) === true && ($config['image_runtime'] ?? false) === true
        && ($config['release_runtime'] ?? false) === false && ($config['legacy_review_fixture'] ?? false) === false);
    $target = '/run/preview-runtime-config';
    $archive = '/run/preview-bootstrap/image-video-upgrade';
    $marker = '/run/preview-app/product-video-ready.json';
    $roots = ['/private/product-video', '/private/product-media-scan'];
    foreach (array_merge([$archive, $marker], $roots) as $path) {
        preview_full_runtime_require(!file_exists($path) && !is_link($path));
    }
    preview_full_runtime_require(is_file($target . '/.preview-runtime-ready.json')
        && !is_link($target . '/.preview-runtime-ready.json'));
    // This existing-ready path verifies identity, exact canonical INI and all writable permissions without repairing them.
    $ready = preview_prepare_full_runtime();
    preview_full_runtime_require(($ready['changed'] ?? null) === false
        && ($ready['feature'] ?? null) === 'image-runtime-v1');
    $identity = ['project' => $config['project'], 'run_id' => $config['run_id'], 'feature' => 'image-runtime-v1'];
    $oldIni = file_get_contents($target . '/.env');
    preview_full_runtime_require(is_string($oldIni) && $oldIni === preview_full_runtime_ini());
    $oldLines = ["\nPRODUCT_VIDEO_UPLOAD_ENABLED = \"false\"\n",
        "\nPRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT = \"\"\n", "\nPRODUCT_MEDIA_SCAN_PRIVATE_ROOT = \"\"\n"];
    $newLines = ["\nPRODUCT_VIDEO_UPLOAD_ENABLED = \"true\"\n",
        "\nPRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT = \"/private/product-video\"\n",
        "\nPRODUCT_MEDIA_SCAN_PRIVATE_ROOT = \"/private/product-media-scan\"\n"];
    foreach ($oldLines as $line) preview_full_runtime_require(substr_count($oldIni, $line) === 1);
    $expectedIni = str_replace($oldLines, $newLines, $oldIni, $replacements);
    preview_full_runtime_require($replacements === 3);
    $result = ['project' => $config['project'], 'run_id' => $config['run_id'], 'changed' => false,
        'old_ini_sha256' => hash('sha256', $oldIni), 'new_ini_sha256' => hash('sha256', $expectedIni),
        'changed_scope' => ['PRODUCT_VIDEO_UPLOAD_ENABLED', 'PRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT',
            'PRODUCT_MEDIA_SCAN_PRIVATE_ROOT'], 'timer' => null];
    if (!$apply) return $result;

    // An exclusive archive is also the partial-operation marker. Preserve every partial result on failure.
    preview_full_runtime_require(mkdir($archive, 0700) && chown($archive, 0) && chgrp($archive, 0)
        && chmod($archive, 0700));
    preview_full_runtime_write($archive . '/original.env', $oldIni, 0600);
    preview_full_runtime_write($archive . '/starting.json', json_encode($result + ['state' => 'starting'],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0600);

    // The installer keeps its existing metadata authority, with all transient runtime writes confined to this container.
    preview_environment(true);
    require_once '/workspace/crmeb/vendor/autoload.php';
    $scratch = '/tmp/preview-image-video-upgrade';
    preview_full_runtime_require(!file_exists($scratch) && !is_link($scratch) && mkdir($scratch, 0700));
    $app = new \think\App('/workspace/crmeb/');
    $app->setRuntimePath($scratch . '/');
    $app->initialize();
    $timer = $app->make(\app\services\marketplace\product\MarketplaceProductScanGcTimer::class)->register();
    foreach ($roots as $root) {
        preview_full_runtime_require(mkdir($root, 0700) && chown($root, 33) && chgrp($root, 33)
            && chmod($root, 0700));
        preview_full_runtime_permissions($root, false, false);
    }
    preview_private_json($marker, ['project' => $config['project'], 'run_id' => $config['run_id'],
        'feature' => 'product-video-chunks-v1'], 1000);
    $newIni = preview_full_runtime_ini();
    preview_full_runtime_require($newIni === $expectedIni);
    $temporary = $target . '/.preview-image-video-upgrade.env';
    preview_full_runtime_write($temporary, $newIni, 0640, 33);
    preview_full_runtime_require(rename($temporary, $target . '/.env'));
    preview_image_runtime_verify($identity, $newIni);
    $result['changed'] = true;
    $result['timer'] = $timer;
    preview_full_runtime_write($archive . '/completed.json', json_encode($result + ['state' => 'completed'],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0600);
    return $result;
}

function preview_prepare_full_runtime(): array
{
    $config = preview_config();
    $imageRuntime = $config['image_runtime'] ?? false;
    $imageMode = getenv('PREVIEW_IMAGE_RUNTIME') ?: '0';
    preview_full_runtime_require(is_bool($imageRuntime) && in_array($imageMode, ['0', '1'], true)
        && $imageRuntime === ($imageMode === '1'));
    $releaseRuntime = $config['release_runtime'] ?? false;
    $releaseMode = getenv('PREVIEW_RELEASE_RUNTIME') ?: '0';
    preview_full_runtime_require(is_bool($releaseRuntime) && in_array($releaseMode, ['0', '1'], true)
        && $releaseRuntime === ($releaseMode === '1') && (!$releaseRuntime || $imageRuntime));
    preview_full_runtime_require(PHP_SAPI === 'cli' && getenv('PREVIEW_FULL_RUNTIME') === '1'
        && ($config['full_runtime'] ?? false) === true && ($config['legacy_review_fixture'] ?? false) === false
        && is_file('/run/preview-bootstrap/config.json') && !is_link('/run/preview-app/initialized.json')
        && is_file('/run/preview-app/initialized.json'));
    $initialized = json_decode(file_get_contents('/run/preview-app/initialized.json'), true, 512, JSON_THROW_ON_ERROR);
    preview_full_runtime_require(is_array($initialized) && ($initialized['project'] ?? null) === $config['project']
        && ($initialized['run_id'] ?? null) === $config['run_id']);
    preview_assert_isolated();
    if ($imageRuntime) return preview_prepare_image_runtime($config);
    $source = '/source/crmeb';
    $target = '/runtime-app';
    foreach ([$source, $target] as $directory) {
        $stat = lstat($directory);
        preview_full_runtime_require(is_array($stat) && ($stat['mode'] & 0170000) === 0040000);
    }
    $identity = ['project' => $config['project'], 'run_id' => $config['run_id'], 'feature' => 'full-runtime-v1'];
    $ini = preview_full_runtime_ini();
    $manifest = [];
    preview_full_runtime_source($source, '', $manifest);
    preview_full_runtime_require(isset($manifest['public/index.php'], $manifest['marketplace-release.json']));
    $receipt = $identity + ['source_sha256' => hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'source_files' => count(array_filter($manifest, static fn(array $entry): bool => $entry['type'] === 'file')),
        'source_directories' => count(array_filter($manifest, static fn(array $entry): bool => $entry['type'] === 'directory'))];
    if (file_exists($target . '/.preview-runtime-ready.json') || is_link($target . '/.preview-runtime-ready.json')) {
        preview_full_runtime_verify($manifest, $identity, $receipt, $ini);
        return $receipt + ['changed' => false];
    }
    // A partial preparation has no valid ready marker. Preserve it without copying or repairing.
    preview_full_runtime_require(scandir($target) === ['.', '..'] && chown($target, 0) && chgrp($target, 0)
        && chmod($target, 0755));
    preview_full_runtime_write($target . '/.preview-runtime-preparing.json',
        json_encode($identity, JSON_THROW_ON_ERROR) . "\n", 0600);
    $copied = [];
    preview_full_runtime_source($source, '', $copied, $target);
    $current = [];
    preview_full_runtime_source($source, '', $current);
    preview_full_runtime_require($copied === $manifest && $current === $manifest);
    foreach (['vendor', 'runtime', 'public/uploads'] as $directory) {
        preview_full_runtime_require(mkdir($target . '/' . $directory, 0755) && chown($target . '/' . $directory, 0)
            && chgrp($target . '/' . $directory, 0) && chmod($target . '/' . $directory, 0755));
    }
    preview_full_runtime_write($target . '/public/install.lock', '');
    preview_full_runtime_write($target . '/.env', $ini, 0640, 33);
    preview_full_runtime_permissions('/workspace/crmeb/runtime', false, true);
    preview_full_runtime_permissions('/workspace/crmeb/public/uploads', true, true);
    preview_full_runtime_permissions('/private', false, true);
    preview_full_runtime_write($target . '/.preview-runtime-ready.json', json_encode($receipt, JSON_THROW_ON_ERROR) . "\n", 0600);
    preview_full_runtime_verify($manifest, $identity, $receipt, $ini);
    return $receipt + ['changed' => true];
}
