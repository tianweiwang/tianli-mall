<?php
declare(strict_types=1);
namespace Audit;

/** Configure only this isolated invocation; no configuration is printed. */
function ms1_worker_environment(): void
{
    $c = \audit_config();
    $env = ['APP_DEBUG' => 'false', 'APP_APP_KEY' => $c['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => 'audit_app', 'DATABASE_PASSWORD' => $c['database']['password'], 'DATABASE_PREFIX' => 'eb_', 'DATABASE_DEBUG' => 'false',
        'CACHE_DRIVER' => 'redis', 'CACHE_CACHE_PREFIX' => 'audit:ms1-worker:' . getenv('AUDIT_RUN_ID') . ':',
        'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379', 'REDIS_SELECT' => '0', 'REDIS_REDIS_PASSWORD' => $c['redis_password'],
        'AUTH_SESSION_EPOCH' => $c['auth']['session_epoch'], 'AUTH_STAMP_KEY' => $c['auth']['stamp_key'], 'AUTH_ISSUER' => $c['auth']['issuer']];
    foreach ($env as $name => $value) { unset($_ENV[$name]); \audit_expect(putenv('PHP_' . $name . '=' . $value), 'isolated worker environment failed'); }
}

/** Empty normal job using the actual queue Worker and Redis Job implementations. */
final class Ms1EmptyWorkerJob
{
    public function __construct(private \think\queue\Worker $worker) {}

    public function fire(\think\queue\Job $job, mixed $data): void
    {
        $event = ['run_id' => getenv('AUDIT_RUN_ID'), 'delete_called' => false, 'deleted' => false];
        try {
            \audit_guard_assert();
            $state = json_decode(file_get_contents(\audit_run_dir() . '/ms1-worker-state.json'), true, 128, JSON_THROW_ON_ERROR);
            \audit_expect(($state['run_id'] ?? '') === getenv('AUDIT_RUN_ID') && ($state['status'] ?? '') === 'prepared', 'normal worker prepared state differs');
            \audit_expect(is_array($data) && array_keys($data) === ['run_id', 'sequence'] && $data['run_id'] === getenv('AUDIT_RUN_ID')
                && is_int($data['sequence']) && in_array($data['sequence'], [1, 2], true), 'normal worker payload differs');
            $sequence = $data['sequence']; $id = $job->getJobId();
            \audit_expect($job instanceof \think\queue\job\Redis && $job->getQueue() === 'marketplace' && $job->getConnection() === 'redis'
                && is_string($id) && hash('sha256', $id) === ($state['job_id_hashes'][$sequence - 1] ?? null), 'normal queued job identity differs');
            $path = \audit_run_dir() . '/evidence/ms1-worker-events.jsonl';
            $previous = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            \audit_expect(is_array($previous) && count($previous) === $sequence - 1, 'normal worker event order differs');
            $pid = getmypid(); $stat = \audit_app_proc_stat($pid);
            \audit_expect(is_array($stat) && $stat['pid'] === $pid && preg_match('/^[1-9][0-9]*$/D', $stat['starttime']) === 1, 'real worker process identity unavailable');
            $event += ['sequence' => $sequence, 'pid' => $pid, 'start_ticks' => $stat['starttime'], 'hostname' => gethostname(), 'job_id_sha256' => hash('sha256', $id)];
            $event['delete_called'] = true; $job->delete(); $event['deleted'] = $job->isDeleted();
            \audit_expect($event['deleted'] === true, 'real job was not marked deleted');
            if ($sequence === 2) $this->worker->shouldQuit = true;
            $event['requested_quit'] = $sequence === 2; $event['completed_at'] = time();
        } catch (\Throwable $error) {
            $event['diagnostic'] = ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
            $this->worker->shouldQuit = true;
        }
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents(\audit_run_dir() . '/evidence/ms1-worker-events.jsonl', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            fwrite(STDERR, json_encode(['class' => \RuntimeException::class, 'file' => basename(__FILE__), 'line' => __LINE__], JSON_THROW_ON_ERROR) . "\n");
            $this->worker->shouldQuit = true;
        }
    }
}
