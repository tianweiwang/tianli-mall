<?php
declare(strict_types=1);

namespace app\jobs\marketplace;

use app\dao\marketplace\MerchantDao;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\context\MerchantJobContext;
use app\services\marketplace\queue\MarketplaceRedis;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use crmeb\services\AuthConfigGuard;
use PDO;
use think\db\connector\Mysql;
use think\exception\HttpException;
use think\facade\Db;
use think\Queue;
use think\queue\Job;
use think\queue\job\Redis as RedisJob;
use Throwable;

/** Reusable dependencies only; every queued context remains a local value. */
final class AuditLogJob
{
    private const ACTION = 'merchant.profile.read';
    private const QUEUE = 'marketplace';
    private const KEY_PURPOSE = 'tianli:marketplace-task-source:key:v1';
    private const SOURCE_PURPOSE = 'tianli:marketplace-task-source:v1';

    public function __construct(
        private readonly MerchantJobContext $contexts,
        private readonly MerchantDao $merchants,
        private readonly AuditLogServices $audit
    ) {
    }

    /** Submit only a currently verified merchant's own profile audit. */
    public static function dispatch(MerchantContext $ctx, string $action, array $resource): string
    {
        try {
            if ($action !== self::ACTION || preg_match('/^[a-f0-9]{32}$/D', $ctx->sessionVersion) !== 1) {
                throw new AuthSessionException();
            }
            $ctx->require(self::ACTION);
            $resource = self::resource($resource);
            $requestId = bin2hex(random_bytes(16));
            $submission = self::withDatabase(static function () use ($ctx, $action, $resource, $requestId): array {
                $current = null;
                try {
                    $current = app()->make(MerchantJobContext::class)->rebuild($ctx->employeeId, $requestId);
                    if ($current->merId !== $ctx->merId || !hash_equals($current->sessionVersion, $ctx->sessionVersion)) {
                        throw new AuthSessionException();
                    }
                    $current->require(self::ACTION);
                    self::assertResource($current, $resource, app()->make(MerchantDao::class));
                    $payload = ['employeeId' => $current->employeeId, 'requestId' => $requestId,
                        'action' => $action, 'resource' => $resource, 'sessionVersion' => $current->sessionVersion];
                    $payload['sourceSignature'] = self::signature($payload);
                    return $payload;
                } finally {
                    unset($current);
                }
            });
            $queue = app()->make(Queue::class)->connection(self::QUEUE);
            if (!$queue instanceof MarketplaceRedis || $queue->getConnection() !== self::QUEUE) {
                throw new AuthUnavailableException();
            }
            $id = $queue->push(self::class, $submission, self::QUEUE);
            // MarketplaceRedis returns this only after a positive native RPUSH acknowledgement.
            if (!is_string($id) || preg_match('/^[A-Za-z0-9]{32}$/D', $id) !== 1) throw new AuthUnavailableException();
            return $id;
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** Fixed source schema shared with the dedicated connector, without DB authorization. */
    public static function verifySubmission(mixed $data): array
    {
        try {
            if (!is_array($data)) throw new AuthSessionException();
            $keys = array_keys($data); sort($keys);
            if ($keys !== ['action', 'employeeId', 'requestId', 'resource', 'sessionVersion', 'sourceSignature']
                || !is_int($data['employeeId']) || $data['employeeId'] <= 0 || $data['employeeId'] > 4294967295
                || !is_string($data['requestId']) || preg_match('/^[a-f0-9]{32}$/D', $data['requestId']) !== 1
                || $data['action'] !== self::ACTION
                || !is_string($data['sessionVersion']) || preg_match('/^[a-f0-9]{32}$/D', $data['sessionVersion']) !== 1
                || !is_string($data['sourceSignature']) || preg_match('/^[a-f0-9]{64}$/D', $data['sourceSignature']) !== 1) {
                throw new AuthSessionException();
            }
            $resource = self::resource($data['resource']);
            $normalized = ['employeeId' => $data['employeeId'], 'requestId' => $data['requestId'],
                'action' => self::ACTION, 'resource' => $resource, 'sessionVersion' => $data['sessionVersion']];
            if (!hash_equals(self::signature($normalized), $data['sourceSignature'])) throw new AuthSessionException();
            $normalized['sourceSignature'] = $data['sourceSignature'];
            return $normalized;
        } catch (AuthException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function fire(Job $job, mixed $data): void
    {
        $ctx = null;
        try {
            if (!$job instanceof RedisJob || $job->getConnection() !== self::QUEUE || $job->getQueue() !== self::QUEUE
                || $job->isDeleted() || $job->isReleased()) throw new AuthSessionException();
            $raw = json_decode($job->getRawBody(), true, 32, JSON_THROW_ON_ERROR);
            $id = $job->getJobId();
            if (!is_array($raw) || ($raw['job'] ?? null) !== self::class || ($raw['data'] ?? null) !== $data
                || !is_string($id) || preg_match('/^[A-Za-z0-9]{32}$/D', $id) !== 1 || ($raw['id'] ?? null) !== $id) {
                throw new AuthSessionException();
            }
            $submission = self::verifySubmission($data);
            self::withDatabase(function (Mysql $connection, PDO $pdo) use (&$ctx, $submission): void {
                $connection->transaction(function () use (&$ctx, $submission, $connection, $pdo): void {
                    if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || !$pdo->inTransaction()) {
                        throw new AuthUnavailableException();
                    }
                    $ctx = $this->contexts->rebuild($submission['employeeId'], $submission['requestId']);
                    if (!hash_equals($ctx->sessionVersion, $submission['sessionVersion'])) throw new AuthSessionException();
                    $ctx->require(self::ACTION);
                    self::assertResource($ctx, $submission['resource'], $this->merchants);
                    $this->audit->record('merchant', $ctx->employeeId, $ctx->merId, self::ACTION,
                        'merchant', (string)$ctx->merId, 'success', []);
                });
                if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()) {
                    throw new AuthUnavailableException();
                }
            });
            // The actual RedisJob delegates to the strict named connector's exact ZREM.
            $job->delete();
            if (!$job->isDeleted()) throw new AuthUnavailableException();
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        } finally {
            unset($ctx);
        }
    }

    private static function resource(mixed $resource): array
    {
        if (!is_array($resource)) throw new AuthSessionException();
        $keys = array_keys($resource); sort($keys);
        if ($keys !== ['id', 'type'] || $resource['type'] !== 'merchant'
            || !is_int($resource['id']) || $resource['id'] <= 0 || $resource['id'] > 4294967295) {
            throw new AuthSessionException();
        }
        return ['type' => 'merchant', 'id' => $resource['id']];
    }

    private static function assertResource(MerchantContext $ctx, array $resource, MerchantDao $merchants): void
    {
        if ($resource['id'] !== $ctx->merId) throw new MerchantNotFoundException();
        $merchant = $merchants->findForContext($ctx);
        if ($merchant === null || (string)($merchant['mer_id'] ?? '') !== (string)$ctx->merId) {
            throw new MerchantNotFoundException();
        }
    }

    private static function signature(array $payload): string
    {
        $cfg = AuthConfigGuard::authConfig();
        $canonical = json_encode(['employeeId' => $payload['employeeId'], 'requestId' => $payload['requestId'],
            'action' => $payload['action'], 'resource' => ['type' => $payload['resource']['type'], 'id' => $payload['resource']['id']],
            'sessionVersion' => $payload['sessionVersion']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $key = hash_hmac('sha256', self::KEY_PURPOSE, $cfg['signing_key'], true);
        $message = implode("\0", [self::SOURCE_PURPOSE, $cfg['session_epoch'], $cfg['issuer'],
            'merchantapi', 'tianli:merchantapi', self::class, self::QUEUE, $canonical]);
        return hash_hmac('sha256', $message, $key);
    }

    /** Use the actual Config object/fresh default API, and restore after each invocation. */
    private static function withDatabase(callable $work): mixed
    {
        $original = null; $configured = null; $configurationChanged = false;
        try {
            $original = app()->config->get('database');
            if (!is_array($original) || ($original['default'] ?? '') !== 'mysql'
                || !is_array($original['connections']['mysql'] ?? null)) throw new AuthUnavailableException();
            $existing = Db::connect();
            if (!$existing instanceof Mysql) throw new AuthUnavailableException();
            $existingPdo = $existing->getPdo();
            if ($existingPdo instanceof PDO && $existingPdo->inTransaction()) throw new AuthUnavailableException();
            $database = $original;
            $database['connections']['mysql']['debug'] = false;
            $database['connections']['mysql']['trigger_sql'] = false;
            $database['connections']['mysql']['charset'] = 'utf8mb4';
            $database['connections']['mysql']['break_reconnect'] = false;
            app()->config->set($database, 'database');
            $configurationChanged = true;
            $configured = Db::connect(null, true);
            if (!$configured instanceof Mysql || $configured->getConfig('debug') !== false
                || $configured->getConfig('trigger_sql') !== false || $configured->getConfig('charset') !== 'utf8mb4'
                || $configured->getConfig('break_reconnect') !== false) throw new AuthUnavailableException();
            $pdo = $configured->connect();
            if (!$pdo instanceof PDO || $pdo->inTransaction()) throw new AuthUnavailableException();
            return $work($configured, $pdo);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        } finally {
            if ($configurationChanged && is_array($original)) {
                try {
                    app()->config->set($original, 'database');
                    $pdo = $configured?->getPdo();
                    if ($pdo instanceof PDO && $pdo->inTransaction()) throw new AuthUnavailableException();
                    Db::connect(null, true);
                } catch (Throwable $cleanupError) {
                    throw new AuthUnavailableException();
                }
            }
        }
    }
}
