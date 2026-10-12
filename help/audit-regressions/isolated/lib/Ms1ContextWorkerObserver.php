<?php
declare(strict_types=1);
namespace Audit;

use app\jobs\marketplace\AuditLogJob;
use PDO;
use think\App;
use think\db\connector\Mysql;
use think\facade\Db;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use think\queue\job\Redis as RedisJob;
use think\queue\Worker;

/** Observe the real serial Worker; never resolve a context or invoke business work. */
final class Ms1ContextWorkerObserver
{
    private function __construct(private readonly App $app) {}

    public static function register(App $app): void
    {
        \audit_expect(getenv('AUDIT_ISOLATED') === '1'
            && (\audit_manifest()['guard_mode'] ?? '') === 'read-only', 'context observer isolation mode differs');
        $observer = new self($app);
        $observer->state();
        \audit_expect($observer->events() === [], 'context observer must start with an empty event file');
        $app->event->listen(JobProcessing::class, static function (JobProcessing $event) use ($observer): void {
            $observer->processing($event);
        });
        $app->event->listen(JobProcessed::class, static function (JobProcessed $event) use ($observer): void {
            $observer->processed($event);
        });
    }

    private function processing(JobProcessing $event): void
    {
        [$state, $expected, $job] = $this->identify($event->connection, $event->job);
        \audit_expect(!$job->isDeleted() && !$job->isReleased() && !$job->hasFailed()
            && $job->attempts() === 1, 'normal processing job state differs');
        $record = $this->record('processing', $event->connection, $job, $expected);
        $record += $this->transactionObservation();
        $this->reservation($job, true);
        $record['reservation_present'] = true;
        $this->append($record, $state, ($expected['sequence'] - 1) * 2);
    }

    private function processed(JobProcessed $event): void
    {
        [$state, $expected, $job] = $this->identify($event->connection, $event->job);
        \audit_expect($job->isDeleted() && !$job->isReleased() && !$job->hasFailed(), 'normal processed job state differs');
        // Job::resolve used App::make, which caches this exact FQCN instance.
        \audit_expect($this->app->exists(AuditLogJob::class), 'actual production handler instance unavailable');
        $handler = $this->app->make(AuditLogJob::class);
        \audit_expect($handler instanceof AuditLogJob, 'actual production handler class differs');
        $record = $this->record('processed', $event->connection, $job, $expected);
        $record['handler_object_id'] = spl_object_id($handler);
        $record += $this->transactionObservation();
        $record += $this->auditObservation($expected, $state['prepared_at']);
        $this->reservation($job, false);
        $record += ['deleted' => true, 'released' => false, 'failed' => false,
            'reservation_absent' => true, 'requested_quit' => $expected['sequence'] === 2];

        $worker = null;
        if ($record['requested_quit']) {
            \audit_expect($this->app->exists(Worker::class), 'actual worker instance unavailable');
            $worker = $this->app->make(Worker::class);
            \audit_expect($worker instanceof Worker && $worker->shouldQuit === false, 'normal worker quit state differs');
        }
        $this->append($record, $state, $expected['sequence'] * 2 - 1);
        // Only the second successfully observed normal completion requests daemon exit.
        if ($worker !== null) $worker->shouldQuit = true;
    }

    /** Payload values remain local and never enter observer evidence. */
    private function identify(mixed $connection, mixed $job): array
    {
        $state = $this->state();
        \audit_expect($connection === 'marketplace' && $job instanceof RedisJob
            && $job->getConnection() === 'marketplace' && $job->getQueue() === 'marketplace'
            && $job->getName() === AuditLogJob::class, 'real context queue job identity differs');
        $id = $job->getJobId();
        \audit_expect(is_string($id) && preg_match('/^[A-Za-z0-9]{32}$/D', $id) === 1, 'real context job ID differs');
        $hash = hash('sha256', $id);
        $matches = array_values(array_filter($state['jobs'], static fn(array $item): bool => $item['job_id_sha256'] === $hash));
        \audit_expect(count($matches) === 1, 'context job is not the unique current RUN submission');
        $expected = $matches[0];
        $bytes = $job->getRawBody();
        \audit_expect(is_string($bytes) && $bytes !== '' && strlen($bytes) <= 8192, 'real context payload bytes differ');
        $payload = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        \audit_expect(is_array($payload), 'real context payload shape differs');
        $keys = array_keys($payload); sort($keys);
        \audit_expect($keys === ['attempts', 'data', 'id', 'job', 'maxTries', 'timeout']
            && $payload['id'] === $id && $payload['job'] === AuditLogJob::class
            && $payload['attempts'] === 0 && $payload['maxTries'] === null && $payload['timeout'] === null
            && is_array($payload['data']), 'real context serialized job fields differ');
        $data = $payload['data']; $keys = array_keys($data); sort($keys);
        \audit_expect($keys === ['action', 'employeeId', 'requestId', 'resource', 'sessionVersion', 'sourceSignature']
            && $data['employeeId'] === $expected['employee_id'] && $data['requestId'] === $expected['request_id']
            && $data['action'] === 'merchant.profile.read' && is_array($data['resource'])
            && is_string($data['sessionVersion']) && preg_match('/^[a-f0-9]{32}$/D', $data['sessionVersion']) === 1
            && is_string($data['sourceSignature']) && preg_match('/^[a-f0-9]{64}$/D', $data['sourceSignature']) === 1,
            'real context submission fields differ');
        $resourceKeys = array_keys($data['resource']); sort($resourceKeys);
        \audit_expect($resourceKeys === ['id', 'type'] && $data['resource']['type'] === 'merchant'
            && $data['resource']['id'] === $expected['mer_id'], 'real context submission resource differs');
        $reservedBytes = $job->getReservedJob();
        \audit_expect(is_string($reservedBytes) && $reservedBytes !== '' && strlen($reservedBytes) <= 8192,
            'real context reservation bytes differ');
        $reserved = json_decode($reservedBytes, true, 32, JSON_THROW_ON_ERROR);
        $payload['attempts'] = 1;
        \audit_expect($reserved === $payload, 'real context reservation representation differs');
        return [$state, $expected, $job];
    }

    private function state(): array
    {
        $run = (string)getenv('AUDIT_RUN_ID');
        \audit_expect(preg_match('/^[0-9]{14}[a-f0-9]{6}$/D', $run) === 1, 'context observer RUN differs');
        $path = \audit_run_dir() . '/ms1-context-worker-state.json';
        \audit_expect(is_file($path) && !is_link($path), 'context prepared state unavailable');
        $state = \audit_read_control_json($path);
        \audit_expect(($state['run_id'] ?? '') === $run && ($state['status'] ?? '') === 'prepared'
            && is_int($state['prepared_at'] ?? null) && $state['prepared_at'] > 0
            && is_array($state['jobs'] ?? null) && array_is_list($state['jobs']) && count($state['jobs']) === 2,
            'context prepared state differs');
        foreach ($state['jobs'] as $index => $item) {
            \audit_expect(is_array($item), 'context prepared job metadata differs');
            $keys = array_keys($item); sort($keys);
            \audit_expect($keys === ['employee_id', 'job_id_sha256', 'mer_id', 'request_id', 'sequence']
                && ($item['sequence'] ?? null) === $index + 1
                && is_int($item['employee_id']) && $item['employee_id'] > 0 && $item['employee_id'] <= 4294967295
                && is_int($item['mer_id']) && $item['mer_id'] > 0 && $item['mer_id'] <= 4294967295
                && is_string($item['request_id']) && preg_match('/^[a-f0-9]{32}$/D', $item['request_id']) === 1
                && is_string($item['job_id_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $item['job_id_sha256']) === 1,
                'context prepared job metadata differs');
        }
        foreach (['employee_id', 'mer_id', 'request_id', 'job_id_sha256'] as $field) {
            \audit_expect($state['jobs'][0][$field] !== $state['jobs'][1][$field], 'two independent normal submissions required');
        }
        return $state;
    }

    private function record(string $kind, string $connection, RedisJob $job, array $expected): array
    {
        $pid = getmypid(); $stat = \audit_app_proc_stat($pid); $hostname = gethostname();
        \audit_expect(is_int($pid) && $pid > 0 && is_array($stat) && $stat['pid'] === $pid
            && is_string($stat['starttime']) && preg_match('/^[1-9][0-9]*$/D', $stat['starttime']) === 1
            && is_string($hostname) && $hostname !== '', 'real context worker process identity unavailable');
        return ['run_id' => (string)getenv('AUDIT_RUN_ID'), 'event' => $kind, 'sequence' => $expected['sequence'],
            'pid' => $pid, 'start_ticks' => $stat['starttime'], 'hostname' => $hostname,
            'job_id_sha256' => hash('sha256', $job->getJobId()), 'name' => $job->getName(), 'actual_redis_job' => true,
            'connection' => $connection, 'queue' => $job->getQueue(), 'observed_at' => time()];
    }

    private function transactionObservation(): array
    {
        // getPdo returns false for the fresh lazy default restored by the real job.
        $connection = Db::connect();
        \audit_expect($connection instanceof Mysql, 'context default database connection differs');
        $pdo = $connection->getPdo();
        \audit_expect($pdo === false || ($pdo instanceof PDO && !$pdo->inTransaction()), 'context job left an unfinished transaction');
        return ['default_connection_has_pdo' => $pdo instanceof PDO, 'no_transaction' => true];
    }

    private function auditObservation(array $expected, int $preparedAt): array
    {
        // The actual audit schema has no request_id: the two fixture employees/merchants are unique.
        $db = \audit_db();
        \audit_expect(!$db->inTransaction(), 'independent audit reader transaction differs');
        $query = $db->prepare('SELECT id,mer_id,actor_type,actor_id,action,resource_type,resource_id,result,'
            . 'JSON_TYPE(detail) AS detail_type,JSON_LENGTH(detail) AS detail_length,add_time '
            . 'FROM eb_marketplace_audit_log WHERE actor_id=? AND mer_id=? ORDER BY id LIMIT 2');
        \audit_expect($query->execute([$expected['employee_id'], $expected['mer_id']]), 'actual normal audit query failed');
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        \audit_expect(count($rows) === 1 && !$db->inTransaction(), 'one actual committed normal audit required');
        $row = $rows[0];
        $id = self::uint($row['id'] ?? null); $employeeId = self::uint($row['actor_id'] ?? null);
        $merId = self::uint($row['mer_id'] ?? null); $addedAt = self::uint($row['add_time'] ?? null);
        \audit_expect($employeeId === $expected['employee_id'] && $merId === $expected['mer_id']
            && $row['actor_type'] === 'merchant' && $row['action'] === 'merchant.profile.read'
            && $row['resource_type'] === 'merchant' && $row['resource_id'] === (string)$expected['mer_id']
            && $row['result'] === 'success' && $row['detail_type'] === 'OBJECT'
            && ($row['detail_length'] === 0 || $row['detail_length'] === '0') && $addedAt >= $preparedAt,
            'actual normal audit ownership or fields differ');
        return ['audit_id' => $id, 'audit_employee_id' => $employeeId, 'audit_mer_id' => $merId,
            'audit_actor_type' => $row['actor_type'], 'audit_action' => $row['action'],
            'audit_resource_type' => $row['resource_type'], 'audit_resource_id' => $row['resource_id'],
            'audit_result' => $row['result'], 'audit_detail_type' => $row['detail_type'],
            'audit_detail_length' => (int)$row['detail_length'], 'audit_add_time' => $addedAt];
    }

    private function reservation(RedisJob $job, bool $present): void
    {
        $redis = \audit_redis();
        $score = $redis->zScore('queues:marketplace:reserved', $job->getReservedJob());
        \audit_expect($redis->getLastError() === null
            && ($present ? (is_int($score) || is_float($score)) : $score === false), 'actual context reservation state differs');
    }

    private static function uint(mixed $value): int
    {
        \audit_expect((is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) === 1
            && (int)$value <= 4294967295, 'actual audit integer field differs');
        return (int)$value;
    }

    private function eventPath(): string
    {
        return \audit_run_dir() . '/evidence/ms1-context-worker-events.jsonl';
    }

    private function events(): array
    {
        $path = $this->eventPath();
        \audit_expect(is_file($path) && !is_link($path) && filesize($path) <= 16384, 'context observer event file unavailable');
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        \audit_expect(is_array($lines) && count($lines) <= 4, 'normal context event count differs');
        return array_map(static function (string $line): array {
            $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            \audit_expect(is_array($row), 'normal context event shape differs');
            return $row;
        }, $lines);
    }

    private function append(array $record, array $state, int $expectedCount): void
    {
        $previous = $this->events();
        \audit_expect(count($previous) === $expectedCount, 'serial context event order differs');
        foreach ($previous as $index => $row) {
            $sequence = intdiv($index, 2) + 1;
            \audit_expect(($row['run_id'] ?? '') === $state['run_id'] && ($row['sequence'] ?? null) === $sequence
                && ($row['event'] ?? '') === ($index % 2 === 0 ? 'processing' : 'processed')
                && ($row['job_id_sha256'] ?? '') === $state['jobs'][$sequence - 1]['job_id_sha256']
                && ($row['name'] ?? '') === AuditLogJob::class && ($row['connection'] ?? '') === 'marketplace'
                && ($row['queue'] ?? '') === 'marketplace' && ($row['pid'] ?? null) === $record['pid']
                && ($row['start_ticks'] ?? '') === $record['start_ticks'] && ($row['hostname'] ?? '') === $record['hostname'],
                'same real serial worker event identity differs');
        }
        if ($record['event'] === 'processed' && $record['sequence'] === 2) {
            \audit_expect(($previous[1]['handler_object_id'] ?? null) === $record['handler_object_id']
                && ($previous[1]['audit_id'] ?? null) !== $record['audit_id'], 'same actual handler and distinct normal audits required');
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        \audit_expect(@file_put_contents($this->eventPath(), $line, FILE_APPEND | LOCK_EX) === strlen($line),
            'normal context event append failed');
    }
}
