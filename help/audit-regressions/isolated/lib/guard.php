<?php
declare(strict_types=1);

function audit_run_dir(): string { return getenv('AUDIT_RUN_DIR') ?: ''; }
function audit_repo_dir(): string { return getenv('AUDIT_REPO_DIR') ?: dirname(__DIR__, 4); }
function audit_manifest(): array
{
    $file = audit_run_dir() . '/manifest.json';
    if (!is_file($file)) { throw new RuntimeException('isolation manifest missing'); }
    return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}
function audit_config(): array
{
    return json_decode(file_get_contents('/run/audit-app/config.json'), true, 512, JSON_THROW_ON_ERROR);
}
function audit_pdo(array $database): PDO
{
    return new PDO('mysql:host=audit-mysql;port=3306;dbname=audit_auth;charset=utf8mb4', $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
}
function audit_db(): PDO { return audit_pdo(audit_config()['database']); }
function audit_redis(): Redis
{
    $redis = new Redis();
    $redis->connect('audit-redis', 6379, 2.0, null, 0, 1.0);
    $redis->auth(audit_config()['redis_password']);
    $redis->select(0);
    return $redis;
}
function audit_grants_are_restricted(array $grants): bool
{
    $hasDml = false;
    foreach ($grants as $grant) {
        if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant)) { continue; }
        if (!preg_match('/^GRANT ([A-Z, ]+) ON `audit_auth`\.\* TO /', $grant, $match)) { return false; }
        $permissions = explode(', ', $match[1]); sort($permissions);
        if ($permissions !== ['DELETE', 'INSERT', 'SELECT', 'UPDATE'] || str_contains($grant, 'GRANT OPTION')) { return false; }
        $hasDml = true;
    }
    return $hasDml;
}
function audit_guard_assert(): void
{
    try {
        $manifest = audit_manifest();
        $runId = getenv('AUDIT_RUN_ID');
        if (getenv('AUDIT_ISOLATED') !== '1' || !preg_match('/^\d{14}[a-f0-9]{6}$/D', (string)$runId) || ($manifest['run_id'] ?? '') !== $runId || ($manifest['project'] ?? '') !== 'tianli-audit-' . $runId || ($manifest['network_internal'] ?? false) !== true) {
            throw new RuntimeException('run identity or network rejected');
        }
        foreach (['audit-mysql', 'audit-redis'] as $host) {
            $address = gethostbyname($host);
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $address !== ($manifest['services'][$host]['address'] ?? '') || empty($manifest['services'][$host]['id'])) { throw new RuntimeException('unrecognized database/cache endpoint'); }
        }
        $config = audit_config();
        if (($config['run_id'] ?? '') !== $runId || ($config['database']['username'] ?? '') !== 'audit_app') { throw new RuntimeException('application configuration identity rejected'); }
        $pdo = audit_db();
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'audit_auth' || $pdo->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) !== [$runId]) { throw new RuntimeException('database sentinel rejected'); }
        if (audit_redis()->get('audit:sentinel') !== $runId) { throw new RuntimeException('Redis sentinel rejected'); }
        if (!audit_grants_are_restricted($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN))) { throw new RuntimeException('application privileges rejected'); }
        try { $pdo->exec('CREATE DATABASE audit_guard_probe'); } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1044) { return; }
            throw new RuntimeException('unexpected privilege probe failure');
        }
        throw new RuntimeException('application account has global privilege');
    } catch (Throwable $error) {
        // Never echo driver exceptions: they can contain target/configuration data.
        fwrite(STDERR, "BLOCKED isolation startup protection failed (" . get_class($error) . ")\n");
        exit(2);
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { audit_guard_assert(); }
