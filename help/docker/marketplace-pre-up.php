<?php
declare(strict_types=1);

// This file runs only in an explicitly trusted checker image, never the candidate.
set_error_handler(static function (int $severity): bool { throw new RuntimeException('SCHEMA_UNAVAILABLE'); });
$pdo = null;
$temporary = null;
try {
    if (PHP_SAPI !== 'cli' || posix_geteuid() !== 33) throw new RuntimeException('RELEASE_IDENTITY_INVALID');
    require '/var/www/vendor/autoload.php';
    $allowed = ['APP_ENV'];
    foreach (['TYPE', 'DRIVER', 'HOSTNAME', 'DATABASE', 'USERNAME', 'PASSWORD', 'HOSTPORT', 'CHARSET', 'PREFIX', 'DEBUG'] as $field) {
        $allowed[] = 'DATABASE_' . $field;
        $allowed[] = 'PHP_DATABASE_' . $field;
    }
    $present = json_decode(getenv('MARKETPLACE_PRE_UP_ENV_KEYS') ?: '', true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($present) || !array_is_list($present) || count(array_unique($present)) !== count($present)
        || array_diff($present, $allowed) !== []) throw new RuntimeException('RELEASE_IDENTITY_INVALID');
    // Remove checker image defaults absent from the actual candidate/service environment.
    foreach ($allowed as $key) {
        if (!in_array($key, $present, true)) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
    }
    $raw = stream_get_contents(STDIN, 4097);
    if (!is_string($raw) || strlen($raw) < 1 || strlen($raw) > 4096 || !feof(STDIN)) {
        throw new RuntimeException('RELEASE_IDENTITY_INVALID');
    }
    $temporary = tempnam('/tmp', 'marketplace-manifest-');
    if (!is_string($temporary) || !chmod($temporary, 0600)
        || file_put_contents($temporary, $raw) !== strlen($raw)) throw new RuntimeException('SCHEMA_UNAVAILABLE');
    $manifest = \crmeb\services\marketplace\SchemaVersionGate::manifest($temporary);
    $configPath = '/run/application/.env';
    $config = lstat($configPath);
    if (!is_array($config) || ($config['mode'] & 0170000) !== 0100000 || !is_readable($configPath)
        || realpath('/var/www/.env') !== realpath($configPath)) throw new RuntimeException('RELEASE_IDENTITY_INVALID');
    $configHash = hash_file('sha256', $configPath);
    if (!is_string($configHash)) throw new RuntimeException('SCHEMA_UNAVAILABLE');
    [$pdo, $prefix] = \crmeb\services\marketplace\SchemaVersionRuntime::standalone('/var/www');
    if (!$pdo instanceof PDO || $pdo->inTransaction()) throw new RuntimeException('SCHEMA_UNAVAILABLE');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $schema = \crmeb\services\marketplace\SchemaVersionGate::assertCompatible($pdo, $prefix, $manifest);
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1) {
        throw new RuntimeException('SCHEMA_INCOMPATIBLE');
    }
    $pdo->rollBack();
    clearstatcache(true, $configPath);
    if (hash_file('sha256', $configPath) !== $configHash) throw new RuntimeException('RELEASE_IDENTITY_INVALID');
    $hashes = [];
    foreach (['SchemaVersionGate', 'SchemaVersionRuntime'] as $name) {
        $path = (new ReflectionClass('crmeb\\services\\marketplace\\' . $name))->getFileName();
        if ($path !== '/var/www/crmeb/services/marketplace/' . $name . '.php') {
            throw new RuntimeException('RELEASE_IDENTITY_INVALID');
        }
        $hashes[$name] = hash_file('sha256', $path);
    }
    echo json_encode(['status' => 'COMPATIBLE', 'manifest' => $manifest, 'schema' => $schema,
        'database' => $database, 'prefix' => $prefix, 'config_sha256' => $configHash,
        'source_sha256' => $hashes], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ignored) {} }
    $reason = $error->getMessage();
    if (!in_array($reason, ['SCHEMA_UNAVAILABLE', 'SCHEMA_INCOMPATIBLE', 'RELEASE_IDENTITY_INVALID'], true)) {
        $reason = 'SCHEMA_UNAVAILABLE';
    }
    fwrite(STDERR, $reason . "\n");
    exit(2);
} finally {
    if (is_string($temporary) && is_file($temporary)) { try { unlink($temporary); } catch (Throwable $ignored) {} }
    restore_error_handler();
}
