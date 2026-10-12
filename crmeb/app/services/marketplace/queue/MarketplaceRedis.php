<?php
declare(strict_types=1);

namespace app\services\marketplace\queue;

use app\jobs\marketplace\AuditLogJob;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\BoundedRedisConnection;
use think\queue\connector\Redis as RedisConnector;
use think\queue\job\Redis as RedisJob;
use Throwable;

/** Only the dedicated marketplace connection changes native write acknowledgements. */
final class MarketplaceRedis extends RedisConnector
{
    public static function __make($config)
    {
        try {
            if (!is_array($config) || ($config['driver'] ?? null) !== self::class
                || ($config['queue'] ?? null) !== 'marketplace'
                || ($config['retry_after'] ?? null) !== 60
                || !array_key_exists('block_for', $config) || $config['block_for'] !== null) {
                throw new AuthUnavailableException();
            }
            return new self(BoundedRedisConnection::fromQueueConfig($config), 'marketplace', 60, null);
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        try {
            if ($options !== []) throw new AuthUnavailableException();
            $decoded = self::payload($payload);
            if ($decoded['attempts'] !== 0) throw new AuthUnavailableException();
            $key = $this->getQueue($queue);
            $this->clearNativeError();
            $ack = @$this->redis->rPush($key, $payload);
            $this->assertNativeErrorAbsent();
            if (!is_int($ack) || $ack <= 0) throw new AuthUnavailableException();
            return $decoded['id'];
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** The inherited pop still constructs the actual framework RedisJob. */
    protected function retrieveNextJob($queue)
    {
        try {
            if ($queue !== 'queues:marketplace' || $this->blockFor !== null || $this->retryAfter !== 60) {
                throw new AuthUnavailableException();
            }
            $this->clearNativeError();
            $job = @$this->redis->lPop($queue);
            $this->assertNativeErrorAbsent();
            if ($job === false || $job === null) return [null, false];
            $decoded = self::payload($job);
            $decoded['attempts']++;
            // Same PHP JSON reservation representation as the parent connector.
            $reserved = json_encode($decoded, JSON_THROW_ON_ERROR);
            $score = $this->availableAt($this->retryAfter);
            $this->clearNativeError();
            $ack = @$this->redis->zAdd($queue . ':reserved', $score, $reserved);
            $this->assertNativeErrorAbsent();
            if (!is_int($ack) || $ack !== 1) throw new AuthUnavailableException();
            return [$job, $reserved];
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function deleteReserved($queue, $job)
    {
        try {
            if (!$job instanceof RedisJob || $queue !== 'marketplace'
                || $job->getQueue() !== 'marketplace' || $job->getConnection() !== 'marketplace') {
                throw new AuthUnavailableException();
            }
            $original = self::payload($job->getRawBody());
            $reserved = $job->getReservedJob();
            $reservation = self::payload($reserved);
            if ($original['id'] !== $job->getJobId() || $reservation['id'] !== $original['id']
                || $reservation['data'] !== $original['data']
                || $reservation['attempts'] !== $original['attempts'] + 1) {
                throw new AuthUnavailableException();
            }
            $this->clearNativeError();
            $ack = @$this->redis->zRem($this->getQueue($queue) . ':reserved', $reserved);
            $this->assertNativeErrorAbsent();
            if (!is_int($ack) || $ack !== 1) throw new AuthUnavailableException();
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    protected function getQueue($queue)
    {
        if ($queue !== null && $queue !== 'marketplace') throw new AuthUnavailableException();
        return 'queues:marketplace';
    }

    private static function payload(mixed $bytes): array
    {
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 8192) throw new AuthUnavailableException();
        $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new AuthUnavailableException();
        $keys = array_keys($data); sort($keys);
        if ($keys !== ['attempts', 'data', 'id', 'job', 'maxTries', 'timeout']
            || $data['job'] !== AuditLogJob::class || $data['maxTries'] !== null || $data['timeout'] !== null
            || !is_string($data['id']) || preg_match('/^[A-Za-z0-9]{32}$/D', $data['id']) !== 1
            || !is_int($data['attempts']) || $data['attempts'] < 0 || $data['attempts'] >= PHP_INT_MAX) {
            throw new AuthUnavailableException();
        }
        AuditLogJob::verifySubmission($data['data']);
        return $data;
    }

    private function clearNativeError(): void
    {
        if (@$this->redis->clearLastError() !== true) throw new AuthUnavailableException();
    }

    private function assertNativeErrorAbsent(): void
    {
        if (@$this->redis->getLastError() !== null) throw new AuthUnavailableException();
    }
}
