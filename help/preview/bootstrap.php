<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

function preview_private_json(string $path, array $value, int $uid = 0): void
{
    $stream = fopen($path, 'x');
    if ($stream === false) throw new RuntimeException('Preview file already exists');
    try {
        $raw = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($stream, $raw) !== strlen($raw)) throw new RuntimeException('Preview file write incomplete');
    } finally { fclose($stream); }
    chmod($path, 0600);
    if ($uid !== 0) chown($path, $uid);
}

function preview_runtime_permissions(): void
{
    // Bootstrap initializes framework caches as root; normal HTTP runs as uid 1000.
    $root = '/workspace/crmeb/runtime';
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isLink()) throw new RuntimeException('Unexpected runtime symlink');
        if (!chown($entry->getPathname(), 1000) || !chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600)) {
            throw new RuntimeException('Runtime ownership failed');
        }
    }
    chown($root, 1000); chmod($root, 0700);
    // Also support environments prepared before the offline Composer umask was corrected.
    foreach (glob('/workspace/crmeb/vendor/composer/autoload_*.php') as $path) chmod($path, 0644);
    chmod('/workspace/crmeb/vendor/autoload.php', 0644);
}

try {
    umask(0077);
    $operation = $argv[1] ?? '';
    if ($operation === 'prepare') {
        $project = (string)getenv('PREVIEW_PROJECT'); $run = (string)getenv('PREVIEW_RUN_ID');
        if (getenv('PREVIEW_ISOLATED') !== '1' || !preg_match('/\Amall-preview-[a-z0-9-]+\z/D', $project)
            || !preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $run)
            || is_file('/run/preview-app/config.json')) throw new RuntimeException('New preview identity required');
        foreach (['/run/preview-bootstrap', '/run/preview-redis', '/run/preview-app'] as $directory) {
            chmod($directory, 0700);
        }
        chown('/run/preview-app', 1000);
        $rootPassword = bin2hex(random_bytes(24));
        file_put_contents('/run/preview-bootstrap/root-password', $rootPassword);
        $legacyMode = getenv('PREVIEW_LEGACY_REVIEW_FIXTURE') ?: '0';
        if (!in_array($legacyMode, ['0', '1'], true)) throw new RuntimeException('Invalid new fixture mode');
        $fullRuntime = getenv('PREVIEW_FULL_RUNTIME') ?: '0';
        if (!in_array($fullRuntime, ['0', '1'], true) || ($fullRuntime === '1' && $legacyMode !== '0')) {
            throw new RuntimeException('Invalid new runtime mode');
        }
        $imageRuntime = getenv('PREVIEW_IMAGE_RUNTIME') ?: '0';
        if (!in_array($imageRuntime, ['0', '1'], true) || ($imageRuntime === '1' && $fullRuntime !== '1')) {
            throw new RuntimeException('Invalid new image runtime mode');
        }
        $config = ['project' => $project, 'run_id' => $run, 'legacy_review_fixture' => $legacyMode === '1',
            'full_runtime' => $fullRuntime === '1', 'image_runtime' => $imageRuntime === '1',
            'database' => ['name' => 'mall_preview', 'host' => 'preview-mysql',
                'app' => ['username' => 'preview_app', 'password' => bin2hex(random_bytes(24))]],
            'redis_password' => bin2hex(random_bytes(24)), 'signing_key' => bin2hex(random_bytes(32)),
            'auth' => ['session_epoch' => 'preview-' . $run, 'stamp_key' => bin2hex(random_bytes(32)), 'issuer' => 'marketplace-preview']];
        $credentials = [];
        foreach (['admin', 'merchant', 'customer'] as $role) {
            $credentials[$role] = ['account' => 'p' . bin2hex(random_bytes(4)), 'password' => bin2hex(random_bytes(8))];
        }
        preview_private_json('/run/preview-app/config.json', $config, 1000);
        preview_private_json('/run/preview-bootstrap/config.json', [
            'migrator' => ['username' => 'preview_migrator', 'password' => bin2hex(random_bytes(24))],
            'credentials' => $credentials]);
        file_put_contents('/run/preview-redis/redis.conf', "bind 0.0.0.0\nprotected-mode yes\nport 6379\nrequirepass "
            . $config['redis_password'] . "\nappendonly yes\nappendfsync everysec\nsave \"\"\n");
        chmod('/run/preview-redis/redis.conf', 0644); chmod('/run/preview-redis', 0755);
        foreach (['/workspace/crmeb/runtime', '/workspace/crmeb/public/uploads', '/private'] as $directory) {
            chmod($directory, 0750); chown($directory, 1000);
        }
        // Public product files are also read by the separate, unprivileged web container.
        chmod('/workspace/crmeb/public/uploads', 0755);
        foreach (['customer-form', 'aftersale', 'refund', 'licence', 'withdrawal', 'chat'] as $name) {
            mkdir('/private/' . $name, 0700); chown('/private/' . $name, 1000);
        }
        // Full runtime uses the image's locked production dependencies; old preview keeps its original source.
        $vendorSource = '/source/crmeb/vendor/.';
        $dependencyReceipt = null;
        if ($config['full_runtime']) {
            $sourceLock = file_get_contents('/source/crmeb/composer.lock');
            $imageLock = file_get_contents('/var/www/composer.lock');
            if (!is_string($sourceLock) || $sourceLock === '' || !is_string($imageLock) || $imageLock === '') {
                throw new RuntimeException('Preview runtime dependency lock unavailable');
            }
            // The image was built from CRLF input. No JSON field, whitespace or version is otherwise ignored.
            $normalizedSource = str_replace("\r\n", "\n", $sourceLock);
            $normalizedImage = str_replace("\r\n", "\n", $imageLock);
            if ($normalizedSource !== $normalizedImage) {
                throw new RuntimeException('Preview runtime dependency lock mismatch');
            }
            $lock = json_decode($sourceLock, true, 512, JSON_THROW_ON_ERROR);
            $installed = json_decode(file_get_contents('/var/www/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($lock) || !is_array($lock['packages'] ?? null) || $lock['packages'] === []
                || !is_array($installed) || !is_array($installed['packages'] ?? null)
                || ($installed['dev'] ?? null) !== false || ($installed['dev-package-names'] ?? null) !== []) {
                throw new RuntimeException('Preview runtime production dependencies unavailable');
            }
            $packageSet = static function (array $packages): array {
                if (!array_is_list($packages)) throw new RuntimeException('Preview runtime dependency package shape mismatch');
                $set = [];
                foreach ($packages as $package) {
                    if (!is_array($package) || !is_string($package['name'] ?? null) || $package['name'] === ''
                        || !is_string($package['version'] ?? null) || $package['version'] === ''
                        || !is_array($package['source'] ?? null)
                        || !is_string($package['source']['reference'] ?? null) || $package['source']['reference'] === ''
                        || isset($set[$package['name']])) {
                        throw new RuntimeException('Preview runtime dependency package shape mismatch');
                    }
                    $set[$package['name']] = ['version' => $package['version'], 'source_reference' => $package['source']['reference']];
                }
                ksort($set, SORT_STRING);
                return $set;
            };
            $packages = $packageSet($lock['packages']);
            if ($packageSet($installed['packages']) !== $packages) {
                throw new RuntimeException('Preview runtime installed dependencies mismatch');
            }
            $vendorSource = '/var/www/vendor/.';
            $dependencyReceipt = ['source' => 'image:/var/www/vendor',
                'source_lock_sha256' => hash('sha256', $sourceLock), 'image_lock_sha256' => hash('sha256', $imageLock),
                'normalized_lock_sha256' => hash('sha256', $normalizedSource), 'packages' => count($packages),
                'packages_sha256' => hash('sha256', json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
        }
        // Regenerate only autoload metadata with the current source composer.json, without network access.
        umask(0022);
        foreach ([['cp', '-a', $vendorSource, '/workspace/crmeb/vendor/'],
            ['composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction', '--no-plugins', '--no-scripts']] as $command) {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
                $pipes, '/workspace/crmeb', ['PATH' => getenv('PATH'), 'COMPOSER_ALLOW_SUPERUSER' => '1',
                    'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_HOME' => '/tmp/composer']);
            if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Offline autoload preparation failed');
        }
        if ($dependencyReceipt !== null) echo json_encode(['dependencies' => $dependencyReceipt], JSON_THROW_ON_ERROR) . "\n";
        echo "Prepared new isolated preview; credentials stay in private volumes\n";
    } elseif (in_array($operation, ['initialize', 'resume-initialize'], true)) {
        $config = preview_config();
        if (is_file('/run/preview-app/initialized.json')) throw new RuntimeException('Preview already initialized');
        $resume = $operation === 'resume-initialize';
        if (!$resume) {
        $password = file_get_contents('/run/preview-bootstrap/root-password');
        $root = new PDO('mysql:host=preview-mysql;port=3306;charset=utf8mb4', 'root', $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((int)$root->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='mall_preview'")->fetchColumn() !== 0) {
            throw new RuntimeException('Existing database refused; initialization is create-only');
        }
        $root->exec('CREATE DATABASE mall_preview CHARACTER SET utf8mb4');
        foreach (['app' => 'SELECT, INSERT, UPDATE, DELETE', 'migrator' => 'ALL PRIVILEGES'] as $role => $grants) {
            $account = $config['database'][$role];
            $root->exec('CREATE USER ' . $root->quote($account['username']) . "@'%' IDENTIFIED BY " . $root->quote($account['password']));
            $root->exec('GRANT ' . $grants . ' ON mall_preview.* TO ' . $root->quote($account['username']) . "@'%'");
        }
        $root = null; unset($password);
        $db = preview_pdo(true);
        $db->exec('CREATE TABLE audit_sentinel (run_id VARCHAR(64) PRIMARY KEY)');
        $db->prepare('INSERT INTO audit_sentinel VALUES (?)')->execute([$config['run_id']]);
        $redis = new Redis(); $redis->connect('preview-redis', 6379, 3); $redis->auth($config['redis_password']);
        if (!$redis->set('preview:sentinel', $config['run_id'], ['nx'])) throw new RuntimeException('Existing Redis preview refused');
        $redis->close();
        }
        preview_assert_isolated();
        chmod('/workspace/crmeb/public/uploads', 0755);
        require __DIR__ . '/seed.php';
        $result = preview_seed($resume);
        preview_runtime_permissions();
        preview_private_json('/run/preview-app/initialized.json', ['project' => $config['project'],
            'run_id' => $config['run_id'], 'result' => $result], 1000);
        echo json_encode(['status' => 'INITIALIZED', 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($operation === 'runtime') {
        require __DIR__ . '/runtime.php';
        echo json_encode(['status' => 'RUNTIME_READY', 'result' => preview_prepare_full_runtime()], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($operation === 'video') {
        $config = preview_config();
        if (($config['legacy_review_fixture'] ?? false) !== false
            || !is_file('/run/preview-app/initialized.json')) {
            throw new RuntimeException('Initialized standard preview required');
        }
        preview_assert_isolated();
        foreach (['/private/product-video', '/private/product-media-scan'] as $directory) {
            if (is_link($directory)) throw new RuntimeException('Unexpected video directory symlink');
            if (!file_exists($directory)) {
                if (!mkdir($directory, 0700) || !chown($directory, 1000)) {
                    throw new RuntimeException('Video directory preparation failed');
                }
            }
            clearstatcache(true, $directory);
            $stat = lstat($directory);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['mode'] & 0777) !== 0700 || $stat['uid'] !== 1000) {
                throw new RuntimeException('Video directory ownership mismatch');
            }
        }
        // The existing installer needs metadata authority; ordinary HTTP keeps its DML role.
        $app = preview_app(true);
        $result = $app->make(\app\services\marketplace\product\MarketplaceProductScanGcTimer::class)->register();
        preview_runtime_permissions();
        if (!preview_video_ready($config)) {
            preview_private_json('/run/preview-app/product-video-ready.json', [
                'project' => $config['project'], 'run_id' => $config['run_id'],
                'feature' => 'product-video-chunks-v1'], 1000);
        }
        echo json_encode(['status' => 'VIDEO_READY', 'timer' => $result], JSON_THROW_ON_ERROR) . "\n";
    } else {
        throw new RuntimeException('Unknown preview bootstrap operation');
    }
} catch (Throwable $error) { preview_error($error); exit(1); }
