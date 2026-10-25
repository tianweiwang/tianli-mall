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
/** One successful decoded public freeze; every call still reads all current bytes. */
function audit_read_immutable_source_freeze(?int $maximum,callable $expect): array
{
    static $cached=null;
    $path='/run/audit/preparation-source-binding.json';
    $fields=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    clearstatcache(true,$path);$before=lstat($path);
    $expect(is_array($before)&&is_file($path)&&!is_link($path)
        &&($before['mode']&0170000)===0100000&&is_int($before['size'])
        &&$before['size']>0&&($maximum===null||$before['size']<=$maximum),'REGULAR_BOUND_JSON');
    $first=$cached===null;$raw=$first?'':null;$count=0;$hasher=hash_init('sha256');
    $stream=fopen($path,'rb');$expect(is_resource($stream),'FREEZE_READ_RESOURCE_OPEN');
    try{
        $opened=fstat($stream);$expect(is_array($opened),'FREEZE_READ_HANDLE_STAT');
        foreach($fields as$key)$expect($opened[$key]===$before[$key],'FREEZE_CURRENT_PATH_HANDLE_IDENTITY');
        while(!feof($stream)){
            $chunk=fread($stream,65536);$expect(is_string($chunk),'FREEZE_COMPLETE_READ_CHUNK');
            if($chunk===''){$expect(feof($stream),'FREEZE_NO_INCOMPLETE_EMPTY_READ');break;}
            $count+=strlen($chunk);
            $expect($count<=$before['size']&&($maximum===null||$count<=$maximum),'FREEZE_COMPLETE_READ_BOUND');
            $expect(hash_update($hasher,$chunk),'FREEZE_COMPLETE_STREAM_HASH');
            if($first)$raw.=$chunk;
        }
        $expect(feof($stream)&&$count===$before['size'],'FREEZE_COMPLETE_READ_EOF_AND_BYTES');
        $handleAfter=fstat($stream);clearstatcache(true,$path);$after=lstat($path);
        $expect(is_array($handleAfter)&&is_array($after)&&is_file($path)&&!is_link($path)
            &&($after['mode']&0170000)===0100000,'FREEZE_CURRENT_REGULAR_PATH_AFTER_READ');
        foreach($fields as$key)$expect($handleAfter[$key]===$before[$key]&&$after[$key]===$before[$key],
            'FREEZE_CURRENT_PATH_HANDLE_UNCHANGED');
    }finally{$expect(fclose($stream),'FREEZE_READ_RESOURCE_CLOSED');}
    $digest=hash_final($hasher);
    if($first){
        $expect(is_string($raw)&&strlen($raw)===$count&&hash('sha256',$raw)===$digest,
            'FREEZE_DECODER_EXACT_COMPLETE_STREAM_BYTES');
        $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        $expect(is_array($value)&&!array_is_list($value),'BOUND_JSON_OBJECT');
        $cached=['value'=>$value,'sha256'=>$digest,'bytes'=>$count];unset($raw,$value,$chunk);
        return $cached['value'];
    }
    $expect($count===$cached['bytes']&&hash_equals($cached['sha256'],$digest),'FREEZE_ACCEPTED_FULL_BYTES_UNCHANGED');
    $expect(is_array($cached['value'])&&!array_is_list($cached['value']),'BOUND_JSON_OBJECT');
    unset($raw,$chunk);return $cached['value'];
}
function audit_pass(string $actual): array { return ['status' => 'PASS', 'actual' => $actual]; }
function audit_case(string $caseId, string $subcase, string $layer, callable $fn): void
{
    try { $result = $fn(); } catch (Throwable $error) {
        $result = ['status' => 'FAIL', 'actual' => get_class($error) . ($error instanceof RuntimeException && !$error instanceof PDOException ? ': ' . $error->getMessage() : '')];
    }
    $record = array_merge(['case_id' => $caseId, 'subcase' => $subcase, 'milestone' => getenv('AUDIT_MILESTONE') ?: 'MS0a', 'layer' => $layer, 'phase' => getenv('AUDIT_PHASE') ?: 'after', 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'run_id' => getenv('AUDIT_RUN_ID'), 'finding_kind' => '新增契约', 'expected' => 'documented case contract', 'db_diff' => [], 'cache_diff' => [], 'evidence_ref' => '', 'notes' => ''], $result);
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
function audit_app_request(string $action, array $overrides = [], string $channel = 'app'): void
{
    audit_expect($channel === 'app' || (getenv('AUDIT_MEDIA_NORMAL') === '1' && $channel === 'media-timer'), 'unapproved supervisor channel');
    $revision = bin2hex(random_bytes(8));
    audit_write_json(audit_run_dir() . '/' . $channel . '-control.json', ['run_id' => getenv('AUDIT_RUN_ID'), 'revision' => $revision, 'action' => $action, 'overrides' => $overrides]);
    for ($i = 0; $i < 200; $i++) {
        usleep(100000);
        $status = audit_read_control_json(audit_run_dir() . '/' . $channel . '-status.json');
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
    $guardMode = getenv('AUDIT_GUARD_MODE');
    if ($guardMode === false || $guardMode === '') { $guardMode = 'active-probe'; }
    audit_expect(in_array($guardMode, ['active-probe', 'read-only'], true), 'invalid bootstrap guard mode');
    audit_expect(getenv('AUDIT_ISOLATED') === '1' && (bool)preg_match('/^\d{14}[a-f0-9]{6}$/D', (string)$runId), 'invalid bootstrap run identity');
    audit_expect(!file_exists(audit_run_dir() . '/manifest.json'), 'run directory already initialized');
    foreach (['/run/audit-bootstrap', '/run/audit-redis', '/run/audit-app', '/run/audit-fixtures'] as $directory) { chmod($directory, 0700); }
    $rootPassword = bin2hex(random_bytes(24));
    file_put_contents('/run/audit-bootstrap/root-password', $rootPassword); chmod('/run/audit-bootstrap/root-password', 0600);
    $migrator = ['username' => 'audit_migrator', 'password' => bin2hex(random_bytes(24))];
    audit_write_json('/run/audit-bootstrap/migrator.json', $migrator);
    $config = ['run_id' => $runId, 'database' => ['username' => 'audit_app', 'password' => bin2hex(random_bytes(24))], 'redis_password' => bin2hex(random_bytes(24)), 'signing_key' => bin2hex(random_bytes(32)),
        'auth' => ['session_epoch' => 'audit-http-' . $runId, 'stamp_key' => bin2hex(random_bytes(32)), 'issuer' => 'tianli-mall']];
    audit_expect(!hash_equals($config['signing_key'], $config['auth']['stamp_key']), 'test signing/stamp keys must be independently generated');
    audit_write_json('/run/audit-app/config.json', $config);
    file_put_contents('/run/audit-redis/redis.conf', "bind 0.0.0.0\nprotected-mode yes\nport 6379\nrequirepass " . $config['redis_password'] . "\nappendonly yes\nappendfsync always\nsave \"\"\n");
    chmod('/run/audit-redis/redis.conf', 0600);
    if (!is_dir(audit_run_dir() . '/evidence')) { mkdir(audit_run_dir() . '/evidence', 0700); }
    $sql = str_replace("\r", '', file_get_contents(audit_repo_dir() . '/crmeb/public/install/crmeb.sql'));
    audit_write_json(audit_run_dir() . '/manifest.json', ['run_id' => $runId, 'project' => getenv('AUDIT_PROJECT'), 'created_at' => gmdate(DATE_ATOM), 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'isolation' => 'ISO-FULL', 'guard_mode' => $guardMode, 'network_internal' => false, 'fixture_sha256' => hash('sha256', $sql), 'services' => [], 'volumes' => []]);
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
function audit_canonical_host_bind_source(string $source): string
{
    // Docker Desktop may report one Windows bind as a drive path or its host mount.
    if (preg_match('~\A([A-Za-z]):(/.*)\z~', $source, $match) === 1) {
        return '/run/desktop/mnt/host/' . strtolower($match[1]) . $match[2];
    }
    return $source;
}
function audit_register_resources(): void
{
    $manifest = audit_manifest();
    $project = $manifest['project'];
    $network = json_decode(file_get_contents(audit_run_dir() . '/network.json'), true, 512, JSON_THROW_ON_ERROR);
    audit_expect($network['Internal'] === true && ($network['Labels']['com.docker.compose.project'] ?? '') === $project && ($network['Labels']['tianli.audit'] ?? '') === '1' && ($network['Labels']['tianli.audit.run'] ?? '') === $manifest['run_id'], 'network is not dedicated and internal');
    $manifest['network_internal'] = true;
    $manifest['network'] = ['id' => $network['Id'], 'name' => $network['Name']];
    $services = []; $browserMounts = null; $appRunSource = null;
    foreach (file(audit_run_dir() . '/containers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        audit_expect(($item['labels']['tianli.audit.run'] ?? '') === $manifest['run_id'] && ($item['labels']['com.docker.compose.project'] ?? '') === $project, 'container identity rejected');
        audit_expect(count($item['networks']) === 1 && isset($item['networks'][$network['Name']]), 'container attached to foreign network');
        foreach ($item['ports'] ?? [] as $binding) { audit_expect($binding === null, 'published host port rejected'); }
        $service = $item['labels']['com.docker.compose.service'];
        audit_expect(is_string($item['image_id'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/D', $item['image_id']) === 1, 'container image identity rejected');
        $services[$service] = ['id' => $item['id'], 'address' => $item['networks'][$network['Name']]['IPAddress'], 'image_id' => $item['image_id']];
        if ($service === 'audit-app') {
            $runMounts = array_values(array_filter($item['mounts'] ?? [], static fn(array $mount): bool => ($mount['Destination'] ?? '') === '/run/audit'));
            audit_expect(count($runMounts) === 1 && ($runMounts[0]['Type'] ?? '') === 'bind' && is_string($runMounts[0]['Source'] ?? null), 'application RUN mount identity rejected');
            $appRunSource = audit_canonical_host_bind_source($runMounts[0]['Source']);
        }
        if (in_array($service, ['audit-ui', 'audit-browser'], true)) {
            $expectedImage = $service === 'audit-ui'
                ? 'sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236'
                : 'sha256:12f8a18ee81e6496442101b53ed2e9b2f68eef56cbb63ed4393b5a81cf9e1dbe';
            audit_expect($item['image_id'] === $expectedImage && ($item['read_only'] ?? null) === true
                && ($item['privileged'] ?? null) === false && ($item['network_mode'] ?? '') === $network['Name'], 'ordinary browser container configuration rejected');
            $expectedMounts = $service === 'audit-ui'
                ? ['/srv/admin' => false, '/etc/nginx/nginx.conf' => false]
                : ['/opt/playwright-core' => false, '/opt/ms1-chat-browser.mjs' => false,
                   '/run/audit-browser-secret' => false, '/run/audit-browser-evidence' => true];
            $actualMounts = $item['mounts'] ?? [];
            audit_expect(count($actualMounts) === count($expectedMounts), 'ordinary browser mount count differs');
            $seen = [];
            foreach ($actualMounts as $mount) {
                $destination = $mount['Destination'] ?? '';
                audit_expect(is_string($destination) && array_key_exists($destination, $expectedMounts)
                    && !isset($seen[$destination]) && ($mount['Type'] ?? '') === 'bind'
                    && ($mount['RW'] ?? null) === $expectedMounts[$destination]
                    && is_string($mount['Source'] ?? null), 'ordinary browser mount identity rejected');
                $seen[$destination] = $mount;
            }
            if ($service === 'audit-browser') $browserMounts = $seen;
        }
        if (in_array($service, ['audit-worker', 'audit-chat'], true)) {
            $runtimeName = $service === 'audit-worker' ? 'worker-runtime' : 'chat-runtime';
            $runtimeMounts = array_values(array_filter($item['mounts'] ?? [], static fn(array $mount): bool => ($mount['Destination'] ?? '') === '/workspace/crmeb/runtime'));
            audit_expect(count($runtimeMounts) === 1 && ($runtimeMounts[0]['Type'] ?? '') === 'volume'
                && ($runtimeMounts[0]['RW'] ?? null) === true && ($runtimeMounts[0]['Name'] ?? '') === $project . '_' . $runtimeName, 'dedicated runtime mount identity rejected');
            $services[$service]['runtime_volume'] = $runtimeMounts[0]['Name'];
        }
    }
    audit_expect(isset($services['audit-mysql'], $services['audit-redis']), 'database and Redis identities required');
    if ($browserMounts !== null) {
        audit_expect(is_string($appRunSource) && $appRunSource !== '', 'browser RUN source anchor unavailable');
        foreach (['/run/audit-browser-secret' => 'browser-secret', '/run/audit-browser-evidence' => 'browser-evidence'] as $destination => $child) {
            $source = audit_canonical_host_bind_source($browserMounts[$destination]['Source']);
            audit_expect($source === $appRunSource . '/' . $child, 'browser belongs to a different RUN directory');
        }
    }
    $manifest['services'] = $services;
    $manifest['volumes'] = array_values(array_filter(array_map('trim', file(audit_run_dir() . '/volumes.txt'))));
    $manifest['volume_identities'] = audit_volume_identities($manifest);
    foreach (['audit-worker', 'audit-chat'] as $service) if (isset($services[$service])) {
        $runtimeVolume = $services[$service]['runtime_volume'];
        audit_expect(in_array($runtimeVolume, $manifest['volumes'], true) && isset($manifest['volume_identities'][$runtimeVolume]), 'dedicated runtime volume is not registered');
    }
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
function audit_authorize_redis_control(): void
{
    $manifest = audit_manifest();
    $runId = getenv('AUDIT_RUN_ID');
    audit_expect(getenv('AUDIT_ISOLATED') === '1' && ($manifest['run_id'] ?? '') === $runId
        && ($manifest['project'] ?? '') === 'tianli-audit-' . $runId, 'service control manifest rejected');
    $network = json_decode(file_get_contents(audit_run_dir() . '/network.json'), true, 512, JSON_THROW_ON_ERROR);
    audit_expect($network['Id'] === $manifest['network']['id'] && $network['Internal'] === true
        && ($network['Labels']['tianli.audit.run'] ?? '') === $runId
        && ($network['Labels']['com.docker.compose.project'] ?? '') === $manifest['project'], 'service control network rejected');
    $matches = [];
    foreach (file(audit_run_dir() . '/containers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($item['labels']['com.docker.compose.service'] ?? '') === 'audit-redis') { $matches[] = $item; }
    }
    audit_expect(count($matches) === 1, 'service control requires one actual Redis container');
    $redis = $matches[0];
    audit_expect($redis['id'] === $manifest['services']['audit-redis']['id']
        && ($redis['labels']['tianli.audit.run'] ?? '') === $runId
        && ($redis['labels']['com.docker.compose.project'] ?? '') === $manifest['project'], 'service control Redis identity rejected');
    foreach (array_keys($redis['networks']) as $name) {
        audit_expect($name === $network['Name'], 'service control foreign network rejected');
    }
    foreach ($redis['ports'] ?? [] as $binding) { audit_expect($binding === null, 'service control host port rejected'); }
    audit_expect(audit_volume_identities($manifest) === $manifest['volume_identities'], 'service control volume identities rejected');
    echo "Manifest-owned Redis service control authorized\n";
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
// Linux app-container observations only: never read environ or cmdline.
function audit_app_proc_stat(int $pid): ?array
{
    if ($pid < 1) { return null; }
    $raw = @file_get_contents('/proc/' . $pid . '/stat');
    if (!is_string($raw)) {
        audit_expect(!is_dir('/proc/' . $pid), 'numeric process identity unavailable');
        return null;
    }
    $end = strrpos($raw, ')');
    if ($end === false || !str_starts_with($raw, $pid . ' (')) { return null; }
    $fields = preg_split('/\s+/', trim(substr($raw, $end + 1)));
    if (count($fields) < 20 || preg_match('/^[A-Za-z]$/D', $fields[0]) !== 1) { return null; }
    foreach ([1, 2, 3, 19] as $index) {
        if (!ctype_digit($fields[$index])) { return null; }
    }
    return ['pid' => $pid, 'ppid' => (int)$fields[1], 'pgid' => (int)$fields[2],
        'sid' => (int)$fields[3], 'starttime' => $fields[19], 'state' => $fields[0]];
}
function audit_app_group_members(array $group, array $supervisor): array
{
    $self = audit_app_proc_stat(getmypid());
    foreach (['pid', 'pgid', 'sid', 'starttime'] as $key) {
        audit_expect($self !== null && $self[$key] === $supervisor[$key], 'supervisor process identity changed');
    }
    audit_expect($group['leader_pid'] > 1 && $group['pgid'] === $group['leader_pid']
        && $group['sid'] === $group['leader_pid'] && (int)$group['leader_starttime'] >= (int)$supervisor['starttime']
        && !in_array($group['pgid'], [$supervisor['pid'], $supervisor['pgid'], $supervisor['sid']], true),
        'unregistered or supervisor process group rejected');
    $leader = audit_app_proc_stat($group['leader_pid']);
    if ($leader !== null) {
        audit_expect($leader['starttime'] === $group['leader_starttime'] && $leader['pgid'] === $group['pgid']
            && $leader['sid'] === $group['sid'], 'registered group leader identity changed');
    }
    $members = [];
    // Numeric stat fields select the registered group; no other group is
    // exposed, inspected for descriptors, signalled, or waited for.
    $files = glob('/proc/[0-9]*/stat');
    audit_expect(is_array($files) && $files !== [], 'numeric process group observation unavailable');
    foreach ($files as $file) {
        $member = audit_app_proc_stat((int)basename(dirname($file)));
        if ($member === null || $member['pgid'] !== $group['pgid']) { continue; }
        audit_expect($member['sid'] === $group['sid'] && $member['pid'] !== $supervisor['pid']
            && (int)$member['starttime'] >= (int)$group['leader_starttime'], 'foreign process group member rejected');
        $members[] = $member;
    }
    usort($members, static fn(array $a, array $b): int => $a['pid'] <=> $b['pid']);
    return $members;
}
function audit_app_tcp_rows(): array
{
    $rows = [];
    foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
        $raw = @file_get_contents($file);
        audit_expect(is_string($raw), 'container TCP observation unavailable');
        foreach (array_slice(explode("\n", trim($raw)), 1) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (count($fields) < 10 || !ctype_digit($fields[9])) { continue; }
            $local = explode(':', $fields[1]); $remote = explode(':', $fields[2]);
            if (count($local) !== 2 || count($remote) !== 2) { continue; }
            $rows[] = ['local_port' => hexdec($local[1]), 'remote_port' => hexdec($remote[1]),
                'remote_hex' => $remote[0], 'state' => $fields[3], 'inode' => $fields[9]];
        }
    }
    return $rows;
}
function audit_app_listener_open(): bool
{
    foreach (audit_app_tcp_rows() as $row) {
        if ($row['local_port'] === 8000 && $row['state'] === '0A') { return true; }
    }
    return false;
}
function audit_app_process_observation(array $group, array $supervisor): array
{
    // Public observation contains live members. Internal stop always uses the
    // complete group, including zombies/dead members, before confirming empty.
    $members = array_values(array_filter(audit_app_group_members($group, $supervisor),
        static fn(array $member): bool => !in_array($member['state'], ['Z', 'X', 'x'], true)));
    $manifest = audit_manifest();
    audit_expect(($manifest['run_id'] ?? null) === getenv('AUDIT_RUN_ID'), 'process observation RUN rejected');
    $address = $manifest['services']['audit-redis']['address'] ?? '';
    audit_expect(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'process observation Redis endpoint rejected');
    $remoteHex = strtoupper(bin2hex(strrev(inet_pton($address))));
    $sockets = []; $listenerInodes = []; $listener = false;
    foreach (audit_app_tcp_rows() as $row) {
        if ($row['local_port'] === 8000 && $row['state'] === '0A') { $listenerInodes[$row['inode']] = true; }
        if ($row['remote_port'] === 6379 && $row['remote_hex'] === $remoteHex && $row['state'] === '01') {
            $sockets[$row['inode']] = ['local_port' => $row['local_port'], 'inode' => $row['inode'], 'remote_port' => 6379];
        }
    }
    $observed = [];
    foreach ($members as $member) {
        $member['redis_sockets'] = [];
        $ownsListener = false;
        $current = audit_app_proc_stat($member['pid']);
        if ($current === null || $current['starttime'] !== $member['starttime']
            || $current['pgid'] !== $group['pgid'] || $current['sid'] !== $group['sid']) { continue; }
        foreach (glob('/proc/' . $member['pid'] . '/fd/*') ?: [] as $file) {
            $target = @readlink($file);
            if (is_string($target) && preg_match('/^socket:\[(\d+)\]$/D', $target, $match) === 1) {
                if (isset($sockets[$match[1]])) { $member['redis_sockets'][] = $sockets[$match[1]]; }
                if (isset($listenerInodes[$match[1]])) { $ownsListener = true; }
            }
        }
        $current = audit_app_proc_stat($member['pid']);
        if ($current === null || $current['starttime'] !== $member['starttime']
            || $current['pgid'] !== $group['pgid'] || $current['sid'] !== $group['sid']
            || in_array($current['state'], ['Z', 'X', 'x'], true)) { continue; }
        $member['ppid'] = $current['ppid']; $member['state'] = $current['state'];
        usort($member['redis_sockets'], static fn(array $a, array $b): int => $a['local_port'] <=> $b['local_port']);
        $listener = $listener || $ownsListener;
        $observed[] = $member;
    }
    return ['group' => $group, 'supervisor' => array_intersect_key($supervisor, array_flip(['pid', 'pgid', 'sid', 'starttime'])),
        'members' => $observed, 'listener_open' => $listener];
}
function audit_app_register_group($process, array $supervisor): array
{
    $pid = proc_get_status($process)['pid']; $deadline = microtime(true) + 1.0;
    do {
        $leader = audit_app_proc_stat($pid);
        if ($leader !== null && $leader['ppid'] === $supervisor['pid'] && $leader['pgid'] === $pid && $leader['sid'] === $pid) {
            $group = ['leader_pid' => $pid, 'pgid' => $leader['pgid'], 'sid' => $leader['sid'], 'leader_starttime' => $leader['starttime']];
            audit_app_group_members($group, $supervisor);
            return $group;
        }
        audit_expect(proc_get_status($process)['running'], 'server exited before independent group registration');
        usleep(5000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('setsid independent process group was not observed');
}
function audit_app_stop_group(&$process, ?array &$group, array $supervisor, bool $nativeZero = false): array
{
    if ($group === null && is_resource($process) && proc_get_status($process)['running']) {
        // Do not guess a group from a PID. Registration still must prove the
        // actual independent SID/PGID before cleanup may signal that group.
        $group = audit_app_register_group($process, $supervisor);
    }
    $stopped = $group; $remaining = []; $nativeExit = null; $nativeOrigin = null;
    $recordExit = static function (int $code, string $origin) use (&$nativeExit, &$nativeOrigin): void {
        if ($code < 0) { return; }
        audit_expect($nativeExit === null || $nativeExit === $code, 'direct child native exit observations differ');
        $nativeExit = $code; $nativeOrigin ??= $origin;
    };
    if ($group !== null) {
        $steps = getenv('AUDIT_MEDIA_NORMAL') === '1' ? [[SIGTERM, 3.0]] : [[SIGTERM, 3.0], [SIGKILL, 2.0]];
        foreach ($steps as [$signal, $seconds]) {
            $remaining = audit_app_group_members($group, $supervisor);
            if ($remaining !== [] && !posix_kill(-$group['pgid'], $signal)) {
                // A group that exited between the read and signal is already
                // gone; every other failure remains a refused cleanup ACK.
                audit_expect(audit_app_group_members($group, $supervisor) === [], 'registered group signal failed');
            }
            $deadline = microtime(true) + $seconds;
            do {
                if (is_resource($process)) { $status = proc_get_status($process); if ($nativeZero && !$status['running']) { $recordExit($status['exitcode'], 'proc_get_status'); } }
                // Only direct/adopted children in the exact registered group.
                while (($waited = pcntl_waitpid(-$group['pgid'], $waitStatus, WNOHANG)) > 0) {
                    if ($nativeZero && $waited === $group['leader_pid']) {
                        audit_expect(pcntl_wifexited($waitStatus), 'original Timer direct child did not exit normally');
                        $recordExit(pcntl_wexitstatus($waitStatus), 'pcntl_waitpid');
                    }
                }
                $remaining = audit_app_group_members($group, $supervisor);
                if ($remaining === [] && !audit_app_listener_open()) { break 2; }
                usleep(20000);
            } while (microtime(true) < $deadline);
        }
    }
    if (is_resource($process)) {
        $status = proc_get_status($process);
        audit_expect(!$status['running'], 'registered direct child still running after bounded cleanup');
        if ($nativeZero) { $recordExit($status['exitcode'], 'proc_get_status'); }
        $closed = proc_close($process); if ($nativeZero) { $recordExit($closed, 'proc_close'); } $process = null;
    }
    audit_expect($remaining === [] && !audit_app_listener_open(), 'registered group or application listener remains after cleanup');
    $group = null;
    $result = ['stopped_group' => $stopped, 'remaining_members' => [], 'listener_closed' => true];
    if ($nativeZero && $stopped !== null) {
        audit_expect($nativeExit === 0 && is_string($nativeOrigin), 'original Timer direct child native zero unavailable');
        $result += ['direct_child_native_exit' => $nativeExit, 'native_exit_origin' => $nativeOrigin, 'stop_mode' => 'term-only', 'force_signal_sent' => false];
    }
    return $result;
}
function audit_serve(string $channel = 'app'): void
{
    audit_expect($channel === 'app' || (getenv('AUDIT_MEDIA_NORMAL') === '1' && $channel === 'media-timer'), 'unapproved supervisor channel');
    if (getenv('AUDIT_MEDIA_NORMAL') === '1') { require_once audit_repo_dir() . '/help/audit-regressions/isolated/lib/media-normal-profile.php'; }
    $timer = $channel === 'media-timer';
    audit_guard_assert();
    $supervisor = audit_app_proc_stat(getmypid());
    audit_expect($supervisor !== null, 'supervisor numeric process identity unavailable');
    $process = null; $group = null; $revision = ''; $generation = '';
    $stop = static function () use (&$process, &$group, &$generation, $supervisor, $timer): array {
        $result = audit_app_stop_group($process, $group, $supervisor, $timer); $generation = '';
        return $result;
    };
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($stop): void {
        try { $stop(); exit(0); } catch (Throwable $error) { exit(2); }
    });
    while (true) {
        $file = audit_run_dir() . '/' . $channel . '-control.json';
        $control = audit_read_control_json($file);
        if (($control['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && ($control['revision'] ?? '') !== $revision) {
            $revision = $control['revision']; $action = $control['action'] ?? '';
            try {
                audit_expect(is_string($revision) && preg_match('/^[a-f0-9]{16}$/D', $revision) === 1
                    && in_array($action, ['start', 'stop', 'observe'], true), 'invalid supervisor action');
                if ($action === 'observe') {
                    audit_expect($group !== null && $generation !== '' && ($control['overrides'] ?? []) === [], 'no registered generation or invalid observation options');
                    $facts = audit_app_process_observation($group, $supervisor);
                } else { $facts = $stop(); }
                if ($action === 'start') {
                    audit_guard_assert();
                    $config = audit_config();
                    $env = ['PATH' => getenv('PATH'), 'PHP_APP_DEBUG' => 'false', 'PHP_APP_APP_KEY' => $config['signing_key'], 'PHP_DATABASE_HOSTNAME' => 'audit-mysql', 'PHP_DATABASE_HOSTPORT' => '3306', 'PHP_DATABASE_DATABASE' => 'audit_auth', 'PHP_DATABASE_USERNAME' => 'audit_app', 'PHP_DATABASE_PASSWORD' => $config['database']['password'], 'PHP_DATABASE_PREFIX' => 'eb_', 'PHP_DATABASE_DEBUG' => 'false', 'PHP_CACHE_DRIVER' => 'redis', 'PHP_REDIS_REDIS_HOSTNAME' => 'audit-redis', 'PHP_REDIS_PORT' => '6379', 'PHP_REDIS_REDIS_PASSWORD' => $config['redis_password'], 'PHP_CACHE_CACHE_PREFIX' => 'c:', 'PHP_CACHE_CACHE_TAG_PREFIX' => 'CRMEB:'];
                    audit_expect(is_array($config['auth'] ?? null)
                        && ($config['auth']['session_epoch'] ?? '') === 'audit-http-' . getenv('AUDIT_RUN_ID')
                        && is_string($config['auth']['stamp_key'] ?? null) && strlen($config['auth']['stamp_key']) >= 32
                        && !hash_equals($config['signing_key'], $config['auth']['stamp_key']), 'private HTTP v2 configuration is not initialized');
                    $env['PHP_AUTH_SESSION_EPOCH'] = $config['auth']['session_epoch'];
                    $env['PHP_AUTH_STAMP_KEY'] = $config['auth']['stamp_key'];
                    $env['PHP_AUTH_ISSUER'] = $config['auth']['issuer'];
                    if (getenv('AUDIT_MEDIA_NORMAL') === '1') {
                        audit_expect(($control['overrides'] ?? []) === audit_media_normal_overrides($timer), 'normal media exact trusted profile required');
                    }
                    foreach ($control['overrides'] ?? [] as $key => $value) {
                        $mapping = ['APP_KEY' => 'PHP_APP_APP_KEY', 'CACHE_DRIVER' => 'PHP_CACHE_DRIVER',
                            'AUTH_SESSION_EPOCH' => 'PHP_AUTH_SESSION_EPOCH', 'AUTH_STAMP_KEY' => 'PHP_AUTH_STAMP_KEY',
                            'APP_DEBUG' => 'PHP_APP_DEBUG', 'DB_PORT' => 'PHP_DATABASE_HOSTPORT', 'CACHE_PREFIX' => 'PHP_CACHE_CACHE_PREFIX',
                            'SERVER_WORKERS' => 'PHP_CLI_SERVER_WORKERS'];
                        if (getenv('AUDIT_MEDIA_NORMAL') === '1') { $mapping += audit_media_normal_override_mapping(); }
                        $approved = is_string($value) && match ($key) {
                            'APP_KEY', 'CACHE_DRIVER' => in_array($value, ['', 'default', 'file', 'redis'], true),
                            'AUTH_SESSION_EPOCH' => $value === '',
                            'AUTH_STAMP_KEY' => in_array($value, ['', 'short'], true),
                            'APP_DEBUG' => in_array($value, ['true', 'false'], true),
                            'DB_PORT' => in_array($value, ['1', '3306'], true),
                            'CACHE_PREFIX' => (bool)preg_match('/^audit:http-lang:' . preg_quote((string)getenv('AUDIT_RUN_ID'), '/') . ':[0-9]+:$/D', $value),
                            'SERVER_WORKERS' => in_array($value, ['', '2'], true),
                            default => getenv('AUDIT_MEDIA_NORMAL') === '1' && audit_media_normal_override_approved($key, $value),
                        };
                        audit_expect(isset($mapping[$key]) && $approved, 'unapproved application configuration override');
                        if ($key === 'SERVER_WORKERS' && $value === '') { unset($env['PHP_CLI_SERVER_WORKERS']); }
                        else { $env[$mapping[$key]] = $value; }
                    }
                    $credentialCheck = json_decode(file_get_contents(audit_run_dir() . '/credential-check.json'), true, 512, JSON_THROW_ON_ERROR);
                    audit_expect(!audit_contains_secret_fingerprint($env, $credentialCheck['root_fingerprint']), 'root value rejected from application environment');
                    $credentialCheck['root_absent_from_app_environment'] = true;
                    audit_write_json(audit_run_dir() . '/credential-check.json', $credentialCheck);
                    $log = audit_run_dir() . '/evidence/' . ($timer ? 'media-timer-runtime.log' : 'app-runtime.log');
                    $command = $timer ? ['setsid', 'php', 'think', 'timer', 'start'] : ['setsid', 'php', 'think', 'run', '-H', '0.0.0.0', '-p', '8000'];
                    $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', $timer ? $log . '.stdout' : $log, 'a'], ['file', $timer ? $log . '.stderr' : $log, 'a']], $pipes, audit_repo_dir() . '/crmeb', $env);
                    audit_expect(is_resource($process), 'cannot start PHP server');
                    $group = audit_app_register_group($process, $supervisor); $generation = $revision;
                    $deadline = microtime(true) + 3.0;
                    do {
                        $facts = audit_app_process_observation($group, $supervisor);
                        if ($timer ? count($facts['members']) >= 2 && !$facts['listener_open'] : $facts['members'] !== [] && $facts['listener_open']) { break; }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    audit_expect($timer ? count($facts['members']) >= 2 && !$facts['listener_open'] : $facts['members'] !== [] && $facts['listener_open'], 'registered original server/Timer group was not ready');
                }
                audit_write_json(audit_run_dir() . '/' . $channel . '-status.json', ['revision' => $revision, 'status' => $action,
                    'run_id' => getenv('AUDIT_RUN_ID'), 'generation_revision' => $generation] + $facts);
            } catch (Throwable $error) {
                $cleanupError = null;
                if ($action !== 'observe') { try { $stop(); } catch (Throwable $cleanup) { $cleanupError = get_class($cleanup); } }
                audit_write_json(audit_run_dir() . '/' . $channel . '-status.json', ['revision' => $revision, 'status' => 'failed',
                    'error_class' => get_class($error), 'cleanup_error_class' => $cleanupError]);
            }
        }
        usleep(50000);
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        match ($argv[1] ?? '') { '__prepare' => audit_prepare(), '__prepare_probe' => audit_prepare(false), '__register' => audit_register_resources(), '__seed' => audit_seed(), '__initialize_files' => audit_initialize_files(), '__serve' => audit_serve(), '__check_credentials' => audit_check_credentials(), '__check_redis' => audit_check_redis(), '__authorize_cleanup' => audit_authorize_cleanup(), '__authorize_redis_control' => audit_authorize_redis_control(), default => throw new RuntimeException('unknown harness operation') };
    } catch (Throwable $error) {
        fwrite(STDERR, 'BLOCKED ' . get_class($error) . ': ' . ($error instanceof PDOException ? 'isolated database operation failed; SQLSTATE ' . $error->getCode() : $error->getMessage()) . "\n");
        exit(2);
    }
}
