<?php
declare(strict_types=1);

// Real MySQL/Redis/HTTP checks; no framework, account, or transport substitutes.
$harness = __DIR__ . '/lib/harness.php';
if (!is_file($harness)) {
    fwrite(STDERR, "FAIL isolated harness has not been implemented\n");
    exit(1);
}
require $harness;
audit_guard_assert();

audit_case('Z02', 'unknown-target', 'INTEGRATION', function (): array {
    $before = audit_snapshot(['tables' => ['audit_sentinel' => null, 'eb_cache' => null], 'redis' => ['*']]);
    $env = array_merge(getenv(), ['AUDIT_RUN_ID' => 'unrecognized-test-target']);
    $process = proc_open([PHP_BINARY, __DIR__ . '/lib/guard.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $diff = audit_assert_unchanged($before, audit_snapshot(['tables' => ['audit_sentinel' => null, 'eb_cache' => null], 'redis' => ['*']]));
    audit_expect($exit === 2 && $diff === [], 'unknown run rejected before writes');
    return audit_pass('exit=2; database and Redis snapshots unchanged');
});

audit_case('Z02', 'cleanup-scope', 'INTEGRATION', function (): array {
    $file = audit_run_dir() . '/cleanup-check.json';
    audit_expect(is_file($file), 'host must execute the separate-project cleanup selfcheck');
    $check = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    audit_expect(($check['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && ($check['passed'] ?? false) === true, 'only disposable selfcheck resources were removed');
    return audit_pass('host verified before/after container IDs; other projects preserved');
});

audit_case('Z06', 'app-account-no-global-priv', 'INTEGRATION', function (): array {
    $pdo = audit_db();
    $denied = false;
    try { $pdo->exec('CREATE DATABASE audit_guard_probe'); } catch (PDOException $error) { $denied = (int)($error->errorInfo[1] ?? 0) === 1044; }
    $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    audit_expect($denied && audit_grants_are_restricted($grants), 'application user has audit_auth DML only');
    return audit_pass('CREATE DATABASE denied; USAGE and audit_auth SELECT/INSERT/UPDATE/DELETE only');
});

audit_case('Z06', 'root-not-exposed', 'INTEGRATION', function (): array {
    $check = json_decode(file_get_contents(audit_run_dir() . '/credential-check.json'), true, 512, JSON_THROW_ON_ERROR);
    audit_expect(($check['root_absent_from_app_config'] ?? false) === true, 'bootstrap confirmed root secret absent from app configuration');
    audit_expect(!is_dir('/run/audit-bootstrap'), 'bootstrap credential volume is not mounted in runner');
    $config = audit_config();
    audit_expect($config['database']['username'] === 'audit_app', 'runner uses restricted account');
    audit_expect(!audit_contains_secret_fingerprint(array_merge(getenv(), $config), $check['root_fingerprint']), 'actual runner environment and configuration do not contain root value');
    return audit_pass('root/migrator volume absent; root fingerprint not found in runner environment or configuration');
});

audit_case('Z07', 'clean-worktree-boot', 'INTEGRATION', function (): array {
    audit_expect(!file_exists(audit_repo_dir() . '/crmeb/.env'), 'no untracked .env dependency');
    for ($start = 0; $start < 16; $start++) {
        $url = audit_app_start();
        $response = audit_http('GET', $url . '/adminapi/login/info');
        audit_expect(($response['json']['status'] ?? null) === 200, 'real login information endpoint returns business status 200');
    }
    return audit_pass('16 consecutive controlled starts from clean worktree return login/info status=200');
});

audit_case('Z09', 'fixture-source', 'INTEGRATION', function (): array {
    $manifest = audit_manifest();
    $source = str_replace("\r", '', file_get_contents(audit_repo_dir() . '/crmeb/public/install/crmeb.sql'));
    $tables = audit_db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='audit_auth'")->fetchColumn();
    audit_expect(hash_equals($manifest['fixture_sha256'], hash('sha256', $source)), 'source digest matches imported installation seed');
    audit_expect((int)$manifest['fixture_table_count'] === 157 && (int)$tables === 158, '157 installation tables and one sentinel table');
    return audit_pass('157 installation tables + audit_sentinel; normalized SQL SHA-256 matches');
});
audit_finish();
