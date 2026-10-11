<?php
declare(strict_types=1);
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
require_once __DIR__ . '/lib/Ms1EmptyWorkerJob.php';

function m1w_files(string $root): array
{
    audit_expect(is_dir($root) && !is_link($root), 'required runtime mount missing'); $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        audit_expect(!$item->isLink(), 'runtime link rejected'); $name = substr($item->getPathname(), strlen(rtrim($root, '/') . '/'));
        if ($item->isFile()) $files[$name] = ['bytes' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())];
        elseif ($item->isDir()) $files[$name . '/'] = ['directory' => true];
    }
    ksort($files); return $files;
}

function m1w_snapshot(): array
{
    $spec = ['tables' => [], 'redis' => ['*']];
    foreach (audit_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'snapshot table rejected'); $spec['tables'][$table] = null;
    }
    audit_expect(count($spec['tables']) === 158, 'all installed tables required'); $raw = audit_snapshot($spec); $keys = array_keys($raw['redis']['*']); $deadlines = [];
    if ($keys) {
        $values = audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); if p>=0 then r[i]=n+p else r[i]=p end end; return r", $keys, count($keys));
        audit_expect(is_array($values) && count($values) === count($keys), 'actual cache deadlines unavailable');
        foreach ($keys as $i => $key) { audit_expect(is_int($values[$i]), 'actual cache deadline differs'); $deadlines[$key] = $values[$i]; }
    }
    $redis = []; foreach ($raw['redis']['*'] as $key => $hash) $redis[hash('sha256', $key)] = ['sha256' => $hash, 'deadline' => $deadlines[$key]];
    ksort($redis);
    return ['tables' => $raw['tables'], 'redis' => $redis, 'runtime' => [m1w_files('/run/audit-app-runtime'), m1w_files('/run/audit-worker-runtime'), m1w_files(audit_repo_dir() . '/crmeb/runtime')]];
}

function m1w_diff(array $before, array $after, array $allowed = []): array
{
    $diff = ['tables' => [], 'redis' => [], 'runtime' => []];
    foreach ($before['tables'] as $table => $value) if ($value !== ($after['tables'][$table] ?? null)) $diff['tables'][] = $table;
    foreach (array_unique(array_merge(array_keys($before['redis']), array_keys($after['redis']))) as $key) {
        if (in_array($key, $allowed, true) && !isset($before['redis'][$key])) continue;
        $a = $before['redis'][$key] ?? null; $b = $after['redis'][$key] ?? null;
        if ($a === null || $b === null || $a['sha256'] !== $b['sha256']) { $diff['redis'][] = $key . ':value'; continue; }
        if (($a['deadline'] < 0 || $b['deadline'] < 0) ? $a['deadline'] !== $b['deadline'] : abs($a['deadline'] - $b['deadline']) > 1) $diff['redis'][] = $key . ':expiry';
    }
    foreach ($before['runtime'] as $i => $files) if ($files !== ($after['runtime'][$i] ?? null)) $diff['runtime'][] = $i;
    return $diff;
}

function m1w_same(array $diff): bool { return $diff['tables'] === [] && $diff['redis'] === [] && $diff['runtime'] === []; }
function m1w_diagnostic(Throwable $error): array { return ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]; }

function m1w_payload(string $bytes, array $state): array
{
    $p = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); $sequence = $p['data']['sequence'] ?? null;
    audit_expect(is_array($p) && ($p['job'] ?? '') === \Audit\Ms1EmptyWorkerJob::class && ($p['maxTries'] ?? null) === null && ($p['timeout'] ?? null) === null
        && is_array($p['data'] ?? null) && array_keys($p['data']) === ['run_id', 'sequence'] && $p['data']['run_id'] === $state['run_id']
        && is_int($sequence) && in_array($sequence, [1, 2], true) && is_string($p['id'] ?? null)
        && hash('sha256', $p['id']) === ($state['job_id_hashes'][$sequence - 1] ?? null)
        && is_int($p['attempts'] ?? null) && in_array($p['attempts'], [0, 1], true), 'exact owned queue payload differs');
    return $p;
}

function m1w_cleanup(array $state): int
{
    $redis = audit_redis(); $owned = [];
    foreach (['queues:marketplace', 'queues:marketplace:delayed', 'queues:marketplace:reserved'] as $index => $key) {
        if ($redis->type($key) === Redis::REDIS_NOT_FOUND) continue;
        audit_expect($redis->type($key) === ($index === 0 ? Redis::REDIS_LIST : Redis::REDIS_ZSET), 'owned queue type differs');
        $items = $index === 0 ? $redis->lRange($key, 0, -1) : $redis->zRange($key, 0, -1);
        foreach ($items as $bytes) m1w_payload($bytes, $state);
        audit_expect(!isset($state['original']['redis'][hash('sha256', $key)]), 'queue existed before preparation'); $owned[] = $key;
    }
    return $owned ? $redis->del($owned) : 0;
}

try {
    $mode = $argv[1] ?? ''; audit_expect(count($argv) === 2 && in_array($mode, ['--prepare', '--observe'], true), 'ordinary worker mode required');
    $statePath = audit_run_dir() . '/ms1-worker-state.json'; $eventPath = audit_run_dir() . '/evidence/ms1-worker-events.jsonl';
    if ($mode === '--prepare') {
        audit_expect(!file_exists($statePath) && !file_exists($eventPath) && !file_exists(audit_run_dir() . '/ms1-worker-start.json'), 'ordinary worker preparation must be new');
        $redis = audit_redis();
        foreach (['queues:marketplace', 'queues:marketplace:delayed', 'queues:marketplace:reserved'] as $key) audit_expect($redis->type($key) === Redis::REDIS_NOT_FOUND, 'dedicated marketplace queue must be initially absent');
        $cursor = null; $prefix = 'audit:ms1-worker:' . getenv('AUDIT_RUN_ID') . ':';
        do { audit_expect(($redis->scan($cursor, $prefix . '*', 200) ?: []) === [], 'new worker cache namespace must be empty'); } while ($cursor !== 0);
        $state = ['run_id' => getenv('AUDIT_RUN_ID'), 'status' => 'preparing', 'job_id_hashes' => [], 'original' => m1w_snapshot(), 'started_at' => time()];
        audit_write_json($statePath, $state); $stream = fopen($eventPath, 'xb'); audit_expect(is_resource($stream), 'new worker event file unavailable'); fclose($stream); chmod($eventPath, 0600);
        \Audit\ms1_worker_environment(); $app = new \think\App(audit_repo_dir() . '/crmeb/'); $app->initialize();
        audit_expect($app->cache->get('think:queue:restart') === null, 'new ordinary restart marker must be absent');
        $queue = $app->make(\think\Queue::class)->connection('redis');
        audit_expect($queue instanceof \think\queue\connector\Redis, 'actual Redis queue connector required');
        foreach ([1, 2] as $sequence) {
            $id = $queue->push(\Audit\Ms1EmptyWorkerJob::class, ['run_id' => $state['run_id'], 'sequence' => $sequence], 'marketplace');
            audit_expect(is_string($id) && preg_match('/^[A-Za-z0-9]{32}$/D', $id) === 1, 'actual queue identifier differs');
            $state['job_id_hashes'][] = hash('sha256', $id); audit_write_json($statePath, $state);
        }
        $items = $redis->lRange('queues:marketplace', 0, -1); audit_expect(count($items) === 2, 'two real normal queue payloads required');
        foreach ($items as $i => $bytes) audit_expect(m1w_payload($bytes, $state)['data']['sequence'] === $i + 1, 'actual producer order differs');
        $after = m1w_snapshot(); $state['preparation_diff'] = m1w_diff($state['original'], $after, [hash('sha256', 'queues:marketplace')]);
        $state['prepared'] = $after; $state['preparation_ended_at'] = time(); audit_write_json($statePath, $state);
        audit_expect(m1w_same($state['preparation_diff']) && $redis->pTtl('queues:marketplace') === -1, 'ordinary preparation changed unrelated state');
        $state['status'] = 'prepared'; $state['prepared_at'] = time();
        audit_write_json($statePath, $state); echo "MS1 ordinary worker preparation completed\n"; exit(0);
    }
    $state = json_decode(file_get_contents($statePath), true, 128, JSON_THROW_ON_ERROR);
    audit_expect(($state['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && ($state['status'] ?? '') === 'prepared', 'ordinary worker prepared state unavailable');
    audit_case('T1W01', 'same_pid_two_empty_jobs', 'WORKER', static function () use ($state, $statePath, $eventPath): array {
        $evidence = ['run_id' => $state['run_id'], 'checks' => [], 'prepare_creates_pass' => false]; $failure = null;
        try {
            $receipt = audit_read_control_json(audit_run_dir() . '/evidence/ms1-worker-container.json'); $manifest = audit_manifest(); $worker = $manifest['services']['audit-worker'];
            $lines = file($eventPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); audit_expect(is_array($lines) && count($lines) === 2, 'two real worker events required');
            $events = array_map(static fn(string $line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR), $lines);
            foreach ($events as $i => $event) audit_expect(($event['run_id'] ?? '') === $state['run_id'] && ($event['sequence'] ?? null) === $i + 1
                && ($event['job_id_sha256'] ?? '') === $state['job_id_hashes'][$i] && ($event['delete_called'] ?? false) === true && ($event['deleted'] ?? false) === true
                && ($event['requested_quit'] ?? null) === ($i === 1) && !isset($event['diagnostic']) && is_int($event['pid'] ?? null) && $event['pid'] > 0
                && is_string($event['start_ticks'] ?? null) && preg_match('/^[1-9][0-9]*$/D', $event['start_ticks']) === 1
                && ($event['hostname'] ?? '') === substr($worker['id'], 0, 12), 'exact normal engine event differs');
            audit_expect($events[0]['pid'] === $events[1]['pid'] && $events[0]['start_ticks'] === $events[1]['start_ticks'], 'same real job worker process required');
            audit_expect(($receipt['run_id'] ?? '') === $state['run_id'] && ($receipt['container_id'] ?? '') === $worker['id']
                && ($receipt['image_id'] ?? '') === $worker['image_id'] && ($receipt['runtime_volume'] ?? '') === $worker['runtime_volume']
                && ($receipt['restart_count'] ?? null) === 0 && ($receipt['native_exit'] ?? null) === 0 && ($receipt['status'] ?? '') === 'exited', 'actual native worker lifecycle differs');
            $redis = audit_redis(); audit_expect($redis->lLen('queues:marketplace') === 0 && $redis->zCard('queues:marketplace:delayed') === 0
                && $redis->zCard('queues:marketplace:reserved') === 0, 'all three actual queues must already be empty before cleanup');
            $after = m1w_snapshot(); $evidence['worker_diff'] = m1w_diff($state['original'], $after);
            audit_expect(m1w_same($evidence['worker_diff']), 'actual worker changed tables/cache/deadlines/runtime');
            $evidence['events'] = $events; $evidence['container'] = $receipt; $evidence['checks'][] = ['passed' => true, 'description' => 'real engine processed and deleted two normal jobs in one PID/starttime and exited without restart'];
        } catch (Throwable $error) { $failure = m1w_diagnostic($error); $evidence['diagnostic'] = $failure; }
        try {
            $evidence['deleted_owned_keys'] = m1w_cleanup($state); audit_guard_assert();
            $evidence['cleanup_diff'] = m1w_diff($state['original'], m1w_snapshot());
            audit_expect(m1w_same($evidence['cleanup_diff']), 'exact ordinary cleanup did not preserve original state');
        } catch (Throwable $error) { $evidence['cleanup_diagnostic'] = m1w_diagnostic($error); $failure ??= $evidence['cleanup_diagnostic']; }
        $ref = 'evidence/ms1-worker-functional.json'; audit_write_json(audit_run_dir() . '/' . $ref, $evidence);
        $state['status'] = $failure === null ? 'observed' : 'observation_failed'; audit_write_json($statePath, $state);
        return ['status' => $failure === null ? 'PASS' : 'FAIL', 'actual' => $failure === null ? 'two real empty jobs share PID/starttime; native engine exit0; exact state preserved' : $failure['class'] . '@' . $failure['file'] . ':' . $failure['line'],
            'evidence_ref' => $ref, 'expected' => 'normal same-PID queue capability only', 'notes' => 'prepare is coordination; Docker receipt is host observation; no merchant context, faults or complete M05/M09/M10 acceptance'];
    });
    audit_finish();
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(m1w_diagnostic($error), JSON_THROW_ON_ERROR) . "\n"); exit(2);
}
