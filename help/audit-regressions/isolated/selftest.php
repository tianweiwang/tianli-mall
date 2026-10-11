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
if (getenv('AUDIT_MILESTONE') === 'MS0b') {
    audit_case('Z02', 'no-egress', 'INTEGRATION', function (): array {
        audit_guard_assert();
        $spec = ['tables' => ['audit_sentinel' => null], 'redis' => ['*']];
        $before = audit_snapshot($spec);
        audit_expect(extension_loaded('curl') && function_exists('curl_init'), 'real PHP curl extension is required');
        $curl = curl_init('https://example.com');
        audit_expect($curl !== false, 'HTTPS curl handle must be created');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        audit_expect($raw === false && $status === 0 && in_array($errno, [6, 7, 28], true), 'HTTPS must fail at DNS/connect/timeout, not TLS or missing-extension failure');
        audit_expect(audit_assert_unchanged($before, audit_snapshot($spec)) === [], 'egress probe must preserve sentinel and every Redis value');
        return audit_pass('real HTTPS request with proxy disabled failed, curl=' . $errno . '; sentinel and all Redis values unchanged');
    });

    audit_case('Z02', 'no-host-ports', 'INTEGRATION', function (): array {
        audit_guard_assert();
        $spec = ['tables' => ['audit_sentinel' => null], 'redis' => ['*']];
        $before = audit_snapshot($spec);
        $manifest = audit_manifest();
        $file = audit_run_dir() . '/containers.jsonl';
        audit_expect(is_file($file), 'actual Docker container inspection is required');
        $seen = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $container = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $service = $container['labels']['com.docker.compose.service'] ?? '';
            if (!in_array($service, ['audit-mysql', 'audit-redis'], true)) { continue; }
            audit_expect(!isset($seen[$service]), 'database/cache inspection must have unique service identities');
            audit_expect(($container['id'] ?? '') === ($manifest['services'][$service]['id'] ?? null)
                && ($container['labels']['tianli.audit.run'] ?? '') === getenv('AUDIT_RUN_ID')
                && ($container['labels']['com.docker.compose.project'] ?? '') === $manifest['project'], 'inspected database/cache must match current manifest');
            audit_expect(array_key_exists('ports', $container) && is_array($container['ports']), 'actual Docker port-binding map is required');
            foreach ($container['ports'] as $binding) { audit_expect($binding === null, 'database/cache must have no host port bindings'); }
            $seen[$service] = true;
        }
        audit_expect(count($seen) === 2, 'both actual MySQL and Redis containers must be inspected');
        audit_expect(audit_assert_unchanged($before, audit_snapshot($spec)) === [], 'port inspection must preserve sentinel and every Redis value');
        return audit_pass('actual manifest-owned MySQL/Redis port bindings are all null; sentinel and all Redis values unchanged');
    });

    foreach ([
        'fault-before-eval' => ['when' => 'before', 'action' => 'throw', 'written' => false],
        'fault-after-eval-response-loss' => ['when' => 'after', 'action' => 'throw', 'written' => true],
        'fault-after-eval-disconnect' => ['when' => 'after', 'action' => 'disconnect', 'written' => true],
    ] as $subcase => $fault) {
        audit_case('Z07', $subcase, 'INTEGRATION', function () use ($subcase, $fault): array {
            audit_guard_assert();
            $adapter = __DIR__ . '/lib/fault.php';
            audit_expect(is_file($adapter), 'real Redis fault adapter has not been implemented');
            require_once $adapter;
            audit_expect(class_exists('FaultyRedis', false), 'FaultyRedis adapter class is required');
            $spec = ['tables' => ['audit_sentinel' => null], 'redis' => ['*']];
            $before = audit_snapshot($spec);
            $key = 'audit:fault:' . getenv('AUDIT_RUN_ID') . ':' . $subcase;
            $observer = audit_redis();
            audit_expect($observer->exists($key) === 0, 'fault probe must not overwrite an existing key');
            $delegate = audit_redis();
            $faulty = new FaultyRedis($delegate, [['command' => 'eval', 'occurrence' => 1, 'when' => $fault['when'], 'action' => $fault['action']]]);
            $thrown = null;
            $writeObserved = false;
            try {
                try {
                    $faulty->eval("return redis.call('SET', KEYS[1], ARGV[1], 'EX', 120)", [$key, 'executed'], 1);
                } catch (Throwable $error) { $thrown = $error; }
                audit_expect($thrown instanceof RedisException, 'configured EVAL fault must throw a RedisException instead of returning success');
                $writeObserved = $observer->get($key) === 'executed';
                audit_expect($writeObserved === $fault['written'], 'independent real Redis read must distinguish failure before execution from response loss after execution');
                if ($fault['action'] === 'disconnect') {
                    audit_expect(!$delegate->isConnected(), 'disconnect fault must actually close the real Redis connection');
                } elseif ($fault['when'] === 'before') {
                    audit_expect($faulty->eval("return redis.call('SET', KEYS[1], ARGV[1], 'EX', 120)", [$key, 'next-command'], 1) === 'OK'
                        && $observer->get($key) === 'next-command', 'fault occurrence must affect only the first EVAL; next command must execute against real Redis');
                }
            } finally {
                // Remove only this run's exact disposable probe, never a prefix.
                $observer->del($key);
            }
            audit_expect(audit_assert_unchanged($before, audit_snapshot($spec)) === [], 'fault probe cleanup must restore sentinel and every Redis value');
            return audit_pass('EVAL exception=' . get_class($thrown) . '; write observed=' . ($writeObserved ? 'yes' : 'no') . '; exact probe removed, sentinel and all Redis values unchanged');
        });
    }

    audit_case('Z07', 'service-fault-cycle', 'INTEGRATION', function (): array {
        audit_guard_assert();
        $adapter = __DIR__ . '/lib/fault.php';
        audit_expect(is_file($adapter), 'real Redis fault adapter has not been implemented');
        require_once $adapter;
        audit_expect(function_exists('audit_fault_redis'), 'manifest-owned Redis service controller is required');
        $spec = ['tables' => ['audit_sentinel' => null], 'redis' => ['*']];
        $before = audit_snapshot($spec);
        $paused = false;
        $stopped = false;
        $failures = [];
        try {
            audit_fault_redis('pause'); $paused = true;
            try { audit_redis()->get('audit:sentinel'); } catch (RedisException $error) { $failures['pause'] = get_class($error); }
            audit_fault_redis('unpause'); $paused = false;
            audit_guard_assert();
            audit_expect(isset($failures['pause']), 'real paused Redis must fail a bounded read');
            audit_expect(audit_assert_unchanged($before, audit_snapshot($spec)) === [], 'unpause must preserve database sentinel and every Redis value');
            audit_fault_redis('stop'); $stopped = true;
            try { audit_redis()->get('audit:sentinel'); } catch (RedisException $error) { $failures['stop'] = get_class($error); }
            audit_fault_redis('start'); $stopped = false;
            audit_guard_assert();
            audit_expect(isset($failures['stop']), 'real stopped Redis must fail a bounded read');
            audit_expect(audit_assert_unchanged($before, audit_snapshot($spec)) === [], 'restart must preserve database sentinel and every Redis value');
        } finally {
            if ($paused) { audit_fault_redis('unpause'); }
            if ($stopped) { audit_fault_redis('start'); }
        }
        return audit_pass('real pause/unpause/stop/start completed; pause and stop caused RedisException; both sentinels and all Redis values preserved');
    });
}
audit_finish();
