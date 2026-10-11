<?php
declare(strict_types=1);
require_once __DIR__ . '/guard.php';

function audit_write_json(string $file, array $value): void
{
    $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($temporary, 0600);
    rename($temporary, $file);
}
function audit_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function audit_pass(string $actual): array { return ['status' => 'PASS', 'actual' => $actual]; }
function audit_case(string $caseId, string $subcase, string $layer, callable $fn): void
{
    try { $result = $fn(); } catch (Throwable $error) {
        $result = ['status' => 'FAIL', 'actual' => get_class($error) . ($error instanceof RuntimeException && !$error instanceof PDOException ? ': ' . $error->getMessage() : '')];
    }
    $record = array_merge(['case_id' => $caseId, 'subcase' => $subcase, 'milestone' => 'MS0a', 'layer' => $layer, 'phase' => getenv('AUDIT_PHASE') ?: 'after', 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'run_id' => getenv('AUDIT_RUN_ID'), 'finding_kind' => '新增契约', 'expected' => 'documented case contract', 'db_diff' => [], 'cache_diff' => [], 'evidence_ref' => '', 'notes' => ''], $result);
    $GLOBALS['audit_results'][] = $record;
    echo $record['status'] . ' ' . $caseId . '/' . $subcase . ': ' . $record['actual'] . "\n";
}
function audit_finish(): never
{
    $results = $GLOBALS['audit_results'] ?? [];
    $path = audit_run_dir() . '/evidence/' . basename($_SERVER['SCRIPT_FILENAME'], '.php') . '-' . (getenv('AUDIT_PHASE') ?: 'after') . '-' . gmdate('YmdHis') . '.jsonl';
    $stream = fopen($path, 'xb');
    foreach ($results as $record) { fwrite($stream, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"); }
    fclose($stream);
    $counts = array_count_values(array_column($results, 'status'));
    echo 'Results: ' . json_encode($counts) . "\n";
    exit(($counts['FAIL'] ?? 0) || ($counts['BLOCKED'] ?? 0) || !$results ? 1 : 0);
}
function audit_http(string $method, string $url, array $headers = [], mixed $body = null): array
{
    $parts = parse_url($url);
    audit_expect(($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === 'audit-app' && ($parts['port'] ?? 80) === 8000, 'HTTP target must be the isolated application');
    $curl = curl_init($url);
    $headerLines = [];
    foreach ($headers as $key => $value) { $headerLines[] = is_int($key) ? $value : "$key: $value"; }
    if (is_array($body)) { $body = json_encode($body, JSON_THROW_ON_ERROR); $headerLines[] = 'Content-Type: application/json'; }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headerLines, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($curl);
    curl_close($curl);
    if ($raw === false) { throw new RuntimeException('isolated HTTP transport failed, curl=' . $errno); }
    $json = json_decode($raw, true);
    return ['http' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
}
function audit_snapshot(array $spec): array
{
    $snapshot = ['tables' => [], 'redis' => []];
    $pdo = audit_db();
    foreach ($spec['tables'] ?? [] as $table => $where) {
        audit_expect((bool)preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table), 'snapshot table name rejected');
        $primary = $pdo->query('SHOW KEYS FROM `' . $table . '` WHERE Key_name=\'PRIMARY\'')->fetchAll();
        usort($primary, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $order = array_map(static fn(array $key): string => '`' . str_replace('`', '``', $key['Column_name']) . '`', $primary);
        $rows = $pdo->query('SELECT * FROM `' . $table . '`' . ($where === null ? '' : ' WHERE ' . $where) . ($order ? ' ORDER BY ' . implode(',', $order) : ''))->fetchAll();
        $hashes = array_map(static fn(array $row): string => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $rows);
        if (!$order) { sort($hashes); }
        $snapshot['tables'][$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $hashes))];
    }
    $redis = audit_redis();
    foreach ($spec['redis'] ?? [] as $pattern) {
        $values = []; $cursor = null;
        do {
            $keys = $redis->scan($cursor, $pattern, 500);
            foreach ($keys ?: [] as $key) {
                if ($redis->type($key) === Redis::REDIS_SET) {
                    // SET iteration / DUMP order can change after AOF recovery.
                    // Compare every member, preserving the unordered value contract.
                    $members = $redis->sMembers($key);
                    sort($members, SORT_STRING);
                    $values[$key] = hash('sha256', "set\0" . serialize($members));
                } else {
                    $values[$key] = hash('sha256', (string)$redis->dump($key));
                }
            }
        } while ($cursor !== 0);
        ksort($values); $snapshot['redis'][$pattern] = $values;
    }
    return $snapshot;
}
function audit_assert_unchanged(array $before, array $after, array $allow = []): array
{
    $differences = [];
    foreach (['tables', 'redis'] as $kind) {
        foreach (array_unique(array_merge(array_keys($before[$kind]), array_keys($after[$kind]))) as $key) {
            if (in_array($key, $allow, true)) { continue; }
            if (($before[$kind][$key] ?? null) !== ($after[$kind][$key] ?? null)) { $differences[] = $kind . ':' . $key; }
        }
    }
    return $differences;
}
function audit_app_request(string $action, array $overrides = []): void
{
    $revision = bin2hex(random_bytes(8));
    audit_write_json(audit_run_dir() . '/app-control.json', ['run_id' => getenv('AUDIT_RUN_ID'), 'revision' => $revision, 'action' => $action, 'overrides' => $overrides]);
    for ($i = 0; $i < 200; $i++) {
        usleep(100000);
        $status = audit_read_control_json(audit_run_dir() . '/app-status.json');
        if (($status['revision'] ?? '') === $revision) {
            audit_expect(($status['status'] ?? '') === $action, 'isolated app supervisor failed');
            return;
        }
    }
    throw new RuntimeException('isolated app supervisor timed out');
}
function audit_read_control_json(string $file): array
{
    // A Windows bind mount can briefly hide the destination during rename.
    // Only ephemeral command/ack files are retried; manifests and secrets keep
    // their strict failure checks. Never accept a partial JSON command.
    $contents = @file_get_contents($file);
    $value = is_string($contents) ? json_decode($contents, true) : null;
    return is_array($value) ? $value : [];
}
function audit_app_start(array $envOverrides = []): string
{
    audit_guard_assert();
    audit_app_request('start', $envOverrides);
    for ($i = 0; $i < 60; $i++) {
        try { $response = audit_http('GET', 'http://audit-app:8000/adminapi/login/info'); if ($response['http'] > 0) { return 'http://audit-app:8000'; } } catch (RuntimeException $error) {}
        usleep(100000);
    }
    throw new RuntimeException('isolated application did not listen');
}
function audit_app_stop(): void { audit_app_request('stop'); }

function audit_contains_secret_fingerprint(array $values, string $fingerprint): bool
{
    foreach ($values as $value) {
        if (is_array($value)) { if (audit_contains_secret_fingerprint($value, $fingerprint)) { return true; } continue; }
        if (!is_scalar($value)) { continue; }
        $value = (string)$value;
        for ($offset = 0; $offset <= strlen($value) - 48; $offset++) {
            if (hash_equals($fingerprint, substr(hash('sha256', substr($value, $offset, 48)), 0, 12))) { return true; }
        }
    }
    return false;
}
function audit_prepare(bool $buildVendor = true): void
{
    umask(0077);
    $runId = getenv('AUDIT_RUN_ID');
    audit_expect(getenv('AUDIT_ISOLATED') === '1' && (bool)preg_match('/^\d{14}[a-f0-9]{6}$/D', (string)$runId), 'invalid bootstrap run identity');
    audit_expect(!file_exists(audit_run_dir() . '/manifest.json'), 'run directory already initialized');
    foreach (['/run/audit-bootstrap', '/run/audit-redis', '/run/audit-app', '/run/audit-fixtures'] as $directory) { chmod($directory, 0700); }
    $rootPassword = bin2hex(random_bytes(24));
    file_put_contents('/run/audit-bootstrap/root-password', $rootPassword); chmod('/run/audit-bootstrap/root-password', 0600);
    $migrator = ['username' => 'audit_migrator', 'password' => bin2hex(random_bytes(24))];
    audit_write_json('/run/audit-bootstrap/migrator.json', $migrator);
    $config = ['run_id' => $runId, 'database' => ['username' => 'audit_app', 'password' => bin2hex(random_bytes(24))], 'redis_password' => bin2hex(random_bytes(24)), 'signing_key' => bin2hex(random_bytes(32))];
    audit_write_json('/run/audit-app/config.json', $config);
    file_put_contents('/run/audit-redis/redis.conf', "bind 0.0.0.0\nprotected-mode yes\nport 6379\nrequirepass " . $config['redis_password'] . "\nappendonly yes\nappendfsync always\nsave \"\"\n");
    chmod('/run/audit-redis/redis.conf', 0600);
    if (!is_dir(audit_run_dir() . '/evidence')) { mkdir(audit_run_dir() . '/evidence', 0700); }
    $sql = str_replace("\r", '', file_get_contents(audit_repo_dir() . '/crmeb/public/install/crmeb.sql'));
    audit_write_json(audit_run_dir() . '/manifest.json', ['run_id' => $runId, 'project' => getenv('AUDIT_PROJECT'), 'created_at' => gmdate(DATE_ATOM), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'isolation' => 'ISO-FULL', 'network_internal' => false, 'fixture_sha256' => hash('sha256', $sql), 'services' => [], 'volumes' => []]);
    audit_write_json(audit_run_dir() . '/credential-check.json', ['root_fingerprint' => substr(hash('sha256', $rootPassword), 0, 12), 'root_absent_from_app_config' => !str_contains(json_encode($config), $rootPassword), 'run_id' => $runId]);
    // Reproduce the existing Dockerfile's Composer build step, offline in a new
    // volume. The tracked vendor autoload files predate the PHP 8.2 classmaps.
    $log = audit_run_dir() . '/evidence/autoload-build.log';
    foreach ($buildVendor ? [['cp', '-a', '/source/crmeb/vendor/.', '/workspace/crmeb/vendor/'], ['composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction', '--no-plugins', '--no-scripts']] : [] as $command) {
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, audit_repo_dir() . '/crmeb', ['PATH' => getenv('PATH'), 'COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_HOME' => '/tmp/composer']);
        audit_expect(is_resource($process) && proc_close($process) === 0, 'offline Composer autoload generation failed');
    }
    if ($buildVendor) { echo "Built tracked dependencies and current compatibility classmaps in the dedicated vendor volume\n"; }
    echo "Prepared isolated credentials and manifest; no credential values emitted\n";
}
function audit_register_resources(): void
{
    $manifest = audit_manifest();
    $project = $manifest['project'];
    $network = json_decode(file_get_contents(audit_run_dir() . '/network.json'), true, 512, JSON_THROW_ON_ERROR);
    audit_expect($network['Internal'] === true && ($network['Labels']['com.docker.compose.project'] ?? '') === $project && ($network['Labels']['tianli.audit'] ?? '') === '1' && ($network['Labels']['tianli.audit.run'] ?? '') === $manifest['run_id'], 'network is not dedicated and internal');
    $manifest['network_internal'] = true;
    $manifest['network'] = ['id' => $network['Id'], 'name' => $network['Name']];
    $services = [];
    foreach (file(audit_run_dir() . '/containers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        audit_expect(($item['labels']['tianli.audit.run'] ?? '') === $manifest['run_id'] && ($item['labels']['com.docker.compose.project'] ?? '') === $project, 'container identity rejected');
        audit_expect(count($item['networks']) === 1 && isset($item['networks'][$network['Name']]), 'container attached to foreign network');
        foreach ($item['ports'] ?? [] as $binding) { audit_expect($binding === null, 'published host port rejected'); }
        $service = $item['labels']['com.docker.compose.service'];
        $services[$service] = ['id' => $item['id'], 'address' => $item['networks'][$network['Name']]['IPAddress']];
    }
    audit_expect(isset($services['audit-mysql'], $services['audit-redis']), 'database and Redis identities required');
    $manifest['services'] = $services;
    $manifest['volumes'] = array_values(array_filter(array_map('trim', file(audit_run_dir() . '/volumes.txt'))));
    $manifest['volume_identities'] = audit_volume_identities($manifest);
    audit_write_json(audit_run_dir() . '/manifest.json', $manifest);
    echo "Registered dedicated internal network and container identities\n";
}
function audit_volume_identities(array $manifest): array
{
    $identities = [];
    foreach (file(audit_run_dir() . '/volumes.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        audit_expect(($item['labels']['tianli.audit'] ?? '') === '1' && ($item['labels']['tianli.audit.run'] ?? '') === $manifest['run_id'] && ($item['labels']['com.docker.compose.project'] ?? '') === $manifest['project'], 'volume labels rejected');
        $identities[$item['name']] = $item['created_at'];
    }
    ksort($identities);
    return $identities;
}
function audit_seed(): void
{
    $manifest = audit_manifest();
    audit_expect($manifest['network_internal'] === true && $manifest['run_id'] === getenv('AUDIT_RUN_ID'), 'bootstrap requires registered isolated resources');
    foreach (['audit-mysql', 'audit-redis'] as $host) { audit_expect(gethostbyname($host) === $manifest['services'][$host]['address'], 'bootstrap endpoint differs from manifest'); }
    $rootPassword = file_get_contents('/run/audit-bootstrap/root-password');
    $root = new PDO('mysql:host=audit-mysql;port=3306;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $root->exec('CREATE DATABASE audit_auth CHARACTER SET utf8mb4');
    $migrator = json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'), true, 512, JSON_THROW_ON_ERROR);
    $config = audit_config();
    foreach ([$migrator, $config['database']] as $account) {
        $root->exec('CREATE USER ' . $root->quote($account['username']) . "@'%' IDENTIFIED BY " . $root->quote($account['password']));
        $permissions = $account['username'] === 'audit_migrator' ? 'ALL PRIVILEGES' : 'SELECT, INSERT, UPDATE, DELETE';
        $root->exec('GRANT ' . $permissions . ' ON audit_auth.* TO ' . $root->quote($account['username']) . "@'%'");
    }
    $root = null; unset($rootPassword);
    $pdo = audit_pdo($migrator);
    $sql = str_replace("\r", '', file_get_contents(audit_repo_dir() . '/crmeb/public/install/crmeb.sql'));
    audit_expect(hash_equals($manifest['fixture_sha256'], hash('sha256', $sql)), 'fixture changed after prepare');
    $pdo->exec($sql);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='audit_auth'")->fetchColumn();
    audit_expect($count === 157, 'installation table count differs from plan');
    $pdo->exec('CREATE TABLE audit_sentinel (run_id varchar(64) PRIMARY KEY)');
    $pdo->prepare('INSERT INTO audit_sentinel VALUES (?)')->execute([$manifest['run_id']]);
    $redis = audit_redis();
    $redis->config('SET', 'appendonly', 'yes');
    $redis->config('SET', 'appendfsync', 'always');
    $redis->set('audit:sentinel', $manifest['run_id']);
    require_once dirname(__DIR__) . '/fixtures/ms0a.php';
    ms0a_fixtures($pdo, '/run/audit-fixtures');
    $manifest['fixture_table_count'] = $count;
    audit_write_json(audit_run_dir() . '/manifest.json', $manifest);
    audit_guard_assert();
    audit_initialize_files();
    echo "Imported 157 installation tables, synthetic accounts and matching sentinels\n";
}
function audit_initialize_files(): void
{
    audit_guard_assert();
    audit_expect(is_file('/run/audit-bootstrap/migrator.json'), 'file initialization is bootstrap-only');
    $migrator = json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'), true, 512, JSON_THROW_ON_ERROR);
    audit_expect($migrator['username'] === 'audit_migrator', 'unexpected initializer account');
    require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
    $app = new \think\App(audit_repo_dir() . '/crmeb/');
    $config = audit_config();
    $app->env->set([
        'APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => $migrator['username'], 'DATABASE_PASSWORD' => $migrator['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
        'REDIS_REDIS_PASSWORD' => $config['redis_password'],
    ]);
    $app->initialize();
    audit_expect($app->make(\app\services\system\log\SystemFileServices::class)->writeMd5() === true, 'real file checksum initialization failed');
    $count = (int)audit_db()->query('SELECT COUNT(*) FROM eb_system_file_md5')->fetchColumn();
    audit_expect($count > 0 && $app->make(\app\services\system\log\SystemFileMd5Services::class)->checkFile() === [], 'generated file checksums do not match current source');
    $cache = $app->make(\app\services\other\CacheServices::class);
    $cache->setDbCache('write_md5', get_crmeb_version_vode());
    audit_expect($cache->checkDbCache('write_md5', get_crmeb_version_vode()), 'version marker not persisted after real initialization');
    audit_write_json(audit_run_dir() . '/evidence/installation-initialization.json', ['run_id' => getenv('AUDIT_RUN_ID'), 'account' => 'audit_migrator', 'checksum_rows' => $count, 'source_matches' => true, 'version_marker_matches' => true, 'application_account' => 'audit_app', 'application_dml_only' => true]);
    echo "Initialized real source file checksums and version marker with dedicated migrator\n";
}
function audit_authorize_cleanup(): void
{
    $manifest = audit_manifest();
    audit_expect(($manifest['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && ($manifest['project'] ?? '') === 'tianli-audit-' . getenv('AUDIT_RUN_ID'), 'cleanup manifest identity rejected');
    $network = json_decode(file_get_contents(audit_run_dir() . '/network.json'), true, 512, JSON_THROW_ON_ERROR);
    audit_expect($network['Id'] === $manifest['network']['id'] && $network['Internal'] === true && ($network['Labels']['tianli.audit.run'] ?? '') === getenv('AUDIT_RUN_ID'), 'cleanup network identity rejected');
    $expected = array_column($manifest['services'], 'id'); sort($expected);
    $actual = [];
    foreach (file(audit_run_dir() . '/containers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        audit_expect(($item['labels']['tianli.audit.run'] ?? '') === getenv('AUDIT_RUN_ID') && ($item['labels']['com.docker.compose.project'] ?? '') === $manifest['project'], 'cleanup container labels rejected');
        $actual[] = $item['id'];
    }
    sort($actual); audit_expect($actual === $expected, 'cleanup container IDs differ from manifest');
    $volumes = array_values(array_filter(array_map('trim', file(audit_run_dir() . '/volumes.txt')))); sort($volumes);
    $expectedVolumes = $manifest['volumes']; sort($expectedVolumes);
    audit_expect($volumes === $expectedVolumes, 'cleanup volume list differs from manifest');
    audit_expect(audit_volume_identities($manifest) === $manifest['volume_identities'], 'cleanup volume identities differ from manifest');
    foreach ($volumes as $volume) { audit_expect(str_starts_with($volume, $manifest['project'] . '_'), 'foreign volume rejected'); }
    echo "Cleanup identities verified against manifest\n";
}
function audit_check_credentials(): void
{
    audit_expect(audit_manifest()['run_id'] === getenv('AUDIT_RUN_ID'), 'credential check identity rejected');
    $root = file_get_contents('/run/audit-bootstrap/root-password');
    audit_write_json(audit_run_dir() . '/credential-check.json', ['root_fingerprint' => substr(hash('sha256', $root), 0, 12), 'root_absent_from_app_config' => !str_contains(json_encode(audit_config()), $root), 'run_id' => getenv('AUDIT_RUN_ID')]);
    echo "Root value fingerprint checked without exposing the credential\n";
}
function audit_check_redis(): void
{
    for ($i = 0; $i < 50; $i++) {
        try { if (audit_redis()->get('audit:sentinel') === getenv('AUDIT_RUN_ID')) { echo "Redis ready; run sentinel preserved\n"; return; } } catch (RedisException $error) {}
        usleep(100000);
    }
    throw new RuntimeException('Redis did not restore the run sentinel');
}
function audit_serve(): void
{
    audit_guard_assert();
    $process = null; $revision = '';
    $stop = static function () use (&$process): void {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) { posix_kill(-$status['pid'], SIGTERM); proc_terminate($process); }
            proc_close($process); $process = null;
        }
    };
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($stop): void { $stop(); exit(0); });
    while (true) {
        $file = audit_run_dir() . '/app-control.json';
        $control = audit_read_control_json($file);
        if (($control['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && ($control['revision'] ?? '') !== $revision) {
            $revision = $control['revision']; $stop();
            try {
                audit_expect(in_array($control['action'], ['start', 'stop'], true), 'invalid supervisor action');
                if ($control['action'] === 'start') {
                    audit_guard_assert();
                    $config = audit_config();
                    $env = ['PATH' => getenv('PATH'), 'PHP_APP_DEBUG' => 'false', 'PHP_APP_APP_KEY' => $config['signing_key'], 'PHP_DATABASE_HOSTNAME' => 'audit-mysql', 'PHP_DATABASE_HOSTPORT' => '3306', 'PHP_DATABASE_DATABASE' => 'audit_auth', 'PHP_DATABASE_USERNAME' => 'audit_app', 'PHP_DATABASE_PASSWORD' => $config['database']['password'], 'PHP_DATABASE_PREFIX' => 'eb_', 'PHP_DATABASE_DEBUG' => 'false', 'PHP_CACHE_DRIVER' => 'redis', 'PHP_REDIS_REDIS_HOSTNAME' => 'audit-redis', 'PHP_REDIS_PORT' => '6379', 'PHP_REDIS_REDIS_PASSWORD' => $config['redis_password'], 'PHP_CACHE_CACHE_PREFIX' => 'c:', 'PHP_CACHE_CACHE_TAG_PREFIX' => 'CRMEB:'];
                    foreach ($control['overrides'] ?? [] as $key => $value) {
                        $mapping = ['APP_KEY' => 'PHP_APP_APP_KEY', 'CACHE_DRIVER' => 'PHP_CACHE_DRIVER'];
                        audit_expect(isset($mapping[$key]) && in_array($value, ['', 'default', 'file', 'redis'], true), 'unapproved application configuration override');
                        $env[$mapping[$key]] = $value;
                    }
                    $credentialCheck = json_decode(file_get_contents(audit_run_dir() . '/credential-check.json'), true, 512, JSON_THROW_ON_ERROR);
                    audit_expect(!audit_contains_secret_fingerprint($env, $credentialCheck['root_fingerprint']), 'root value rejected from application environment');
                    $credentialCheck['root_absent_from_app_environment'] = true;
                    audit_write_json(audit_run_dir() . '/credential-check.json', $credentialCheck);
                    $log = audit_run_dir() . '/evidence/app-runtime.log';
                    $process = proc_open(['setsid', 'php', 'think', 'run', '-H', '0.0.0.0', '-p', '8000'], [['file', '/dev/null', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, audit_repo_dir() . '/crmeb', $env);
                    audit_expect(is_resource($process), 'cannot start PHP server');
                }
                audit_write_json(audit_run_dir() . '/app-status.json', ['revision' => $revision, 'status' => $control['action']]);
            } catch (Throwable $error) { audit_write_json(audit_run_dir() . '/app-status.json', ['revision' => $revision, 'status' => 'failed', 'error_class' => get_class($error)]); }
        }
        usleep(50000);
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        match ($argv[1] ?? '') { '__prepare' => audit_prepare(), '__prepare_probe' => audit_prepare(false), '__register' => audit_register_resources(), '__seed' => audit_seed(), '__initialize_files' => audit_initialize_files(), '__serve' => audit_serve(), '__check_credentials' => audit_check_credentials(), '__check_redis' => audit_check_redis(), '__authorize_cleanup' => audit_authorize_cleanup(), default => throw new RuntimeException('unknown harness operation') };
    } catch (Throwable $error) {
        fwrite(STDERR, 'BLOCKED ' . get_class($error) . ': ' . ($error instanceof PDOException ? 'isolated database operation failed; SQLSTATE ' . $error->getCode() : $error->getMessage()) . "\n");
        exit(2);
    }
}
