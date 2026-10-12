<?php
declare(strict_types=1);

namespace crmeb\services\workerman\chat;

use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\AuthConfigGuard;
use crmeb\services\BoundedRedisConnection;
use PDO;
use Redis;
use think\db\connector\Mysql;
use think\facade\Db;
use think\Request;
use Throwable;

/** Independent guest capability; no JWT, User creation, login event or fallback. */
final class GuestChatSession
{
    public const COUNTER_KEY = 'kefu_tourist_uid_seq';
    public const PURPOSE = 'tianli:kefu-chat:guest';
    public const TTL = 3600;
    private const MAX_UID = 2147483647;

    private const WRITE = <<<'LUA'
local capType = redis.call('TYPE', KEYS[1]).ok
local uidType = redis.call('TYPE', KEYS[2]).ok
if capType ~= 'none' or uidType ~= 'none' then return {0} end
if redis.call('SETNX', KEYS[2], ARGV[2]) ~= 1 then return {0} end
if redis.call('SETNX', KEYS[1], ARGV[1]) ~= 1 then return {0} end
if redis.call('PEXPIREAT', KEYS[1], ARGV[3]) ~= 1 or redis.call('PEXPIREAT', KEYS[2], ARGV[3]) ~= 1 then return {-1} end
local capTtl = redis.call('PTTL', KEYS[1])
local uidTtl = redis.call('PTTL', KEYS[2])
local now = redis.call('TIME')
return {1, capTtl, uidTtl, tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)}
LUA;

    private const READ = <<<'LUA'
local kind = redis.call('TYPE', KEYS[1]).ok
if kind == 'none' then return {0} end
if kind ~= 'string' then return {-1} end
local value = redis.call('GET', KEYS[1])
if not value then return {0} end
local ttl = redis.call('PTTL', KEYS[1])
local now = redis.call('TIME')
return {1, value, ttl, tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)}
LUA;

    private const READ_PAIR = <<<'LUA'
local capType = redis.call('TYPE', KEYS[1]).ok
local uidType = redis.call('TYPE', KEYS[2]).ok
if capType == 'none' or uidType == 'none' then return {0} end
if capType ~= 'string' or uidType ~= 'string' then return {-1} end
local record = redis.call('GET', KEYS[1])
local digest = redis.call('GET', KEYS[2])
if not record or not digest then return {0} end
local capTtl = redis.call('PTTL', KEYS[1])
local uidTtl = redis.call('PTTL', KEYS[2])
local now = redis.call('TIME')
return {1, record, digest, capTtl, uidTtl, tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)}
LUA;

    /** Route metadata only; never inspect parameters, response bodies or the DB. */
    public static function isIssuerRequest(Request $request): bool
    {
        try {
            return app()->http->getName() === 'kefuapi'
                && $request->rule() !== null && $request->rule()->getOption('chat_guest') === true;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function issue(string $avatar): array
    {
        try {
            self::assertAvatar($avatar);
            $prefix = self::prefix();
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            $uid = self::allocateUid();
            $now = time();
            if ($now <= 0 || $now > 4294967295 - self::TTL) throw new AuthUnavailableException();
            $expiresAt = $now + self::TTL;
            $bytes = random_bytes(32);
            $token = self::encode($bytes);
            $digest = hash('sha256', $bytes);
            $record = ['token_digest' => $digest, 'uid' => $uid, 'avatar' => $avatar,
                'purpose' => self::PURPOSE, 'expires_at' => $expiresAt];
            $written = self::command($redis, 'EVAL', self::WRITE, 2, $prefix . $digest,
                $prefix . 'uid:' . $uid, json_encode($record, JSON_THROW_ON_ERROR), $digest, $expiresAt * 1000);
            if (!is_array($written) || !array_is_list($written) || count($written) !== 4 || $written[0] !== 1
                || !self::nativeExpiry($written[1], $written[2], $written[3], $expiresAt)) {
                throw new AuthUnavailableException();
            }
            return ['uid' => $uid, 'token' => $token, 'expires_at' => $expiresAt];
        } catch (Throwable $error) {
            // Do not retry an unacknowledged write, reset the counter or reveal data.
            throw new AuthUnavailableException();
        }
    }

    public function verify(string $token): array
    {
        try {
            $bytes = self::decode($token);
            $digest = hash('sha256', $bytes);
            $prefix = self::prefix();
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            $first = self::single($redis, $prefix . $digest);
            if ($first === null) throw new AuthSessionException();
            $record = self::record($first[1], $digest);
            return self::pair($redis, $prefix, $digest, $record['uid']);
        } catch (AuthSessionException|AuthUnavailableException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** Metadata only for a separately authenticated staff consumer, across workers. */
    public function findCurrent(int $uid): ?array
    {
        try {
            self::assertGuestUid($uid);
            $prefix = self::prefix();
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            $index = self::single($redis, $prefix . 'uid:' . $uid);
            if ($index === null) return null;
            $digest = $index[1];
            if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) throw new AuthSessionException();
            return self::pair($redis, $prefix, $digest, $uid);
        } catch (AuthSessionException $error) {
            return null;
        } catch (AuthUnavailableException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function assertCurrent(ChatIdentity $identity): void
    {
        if ($identity->kind !== 'guest') throw new AuthSessionException();
        $current = $this->verify($identity->credential());
        if ($current['uid'] !== $identity->uid || $current['avatar'] !== $identity->avatar
            || $current['expires_at'] !== $identity->expiresAt) throw new AuthSessionException();
    }

    private static function prefix(): string
    {
        $auth = AuthConfigGuard::authConfig();
        // Physical keys deliberately do not inherit the business Cache prefix/tag.
        return 'chat:guest:v1:' . $auth['session_epoch'] . ':';
    }

    private static function command(Redis $redis, string $name, mixed ...$args): mixed
    {
        try {
            if (@$redis->clearLastError() !== true) throw new AuthUnavailableException();
            $result = @$redis->rawCommand($name, ...$args);
            if (@$redis->getLastError() !== null || $result === false || $result === null) {
                throw new AuthUnavailableException();
            }
            return $result;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private static function single(Redis $redis, string $key): ?array
    {
        $reply = self::command($redis, 'EVAL', self::READ, 1, $key);
        if (is_array($reply) && $reply === [0]) return null;
        if (!is_array($reply) || !array_is_list($reply) || count($reply) !== 4 || $reply[0] !== 1
            || !is_string($reply[1]) || !is_int($reply[2]) || $reply[2] <= 0
            || $reply[2] > self::TTL * 1000 || !is_int($reply[3]) || $reply[3] <= 0) {
            throw new AuthSessionException();
        }
        return $reply;
    }

    private static function pair(Redis $redis, string $prefix, string $digest, int $uid): array
    {
        $reply = self::command($redis, 'EVAL', self::READ_PAIR, 2,
            $prefix . $digest, $prefix . 'uid:' . $uid);
        if (!is_array($reply) || !array_is_list($reply) || count($reply) !== 6 || $reply[0] !== 1
            || !is_string($reply[1]) || !is_string($reply[2]) || !hash_equals($digest, $reply[2])) {
            throw new AuthSessionException();
        }
        $record = self::record($reply[1], $digest);
        if ($record['uid'] !== $uid || !self::nativeExpiry($reply[3], $reply[4], $reply[5], $record['expires_at'])) {
            throw new AuthSessionException();
        }
        return ['uid' => $record['uid'], 'avatar' => $record['avatar'], 'expires_at' => $record['expires_at']];
    }

    private static function record(string $raw, string $digest): array
    {
        try {
            $record = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new AuthSessionException();
        }
        if (!is_array($record)) throw new AuthSessionException();
        $keys = array_keys($record); sort($keys, SORT_STRING);
        if ($keys !== ['avatar', 'expires_at', 'purpose', 'token_digest', 'uid']
            || ($record['purpose'] ?? null) !== self::PURPOSE
            || !is_string($record['token_digest']) || !hash_equals($digest, $record['token_digest'])
            || !is_int($record['uid']) || !is_string($record['avatar'])
            || !is_int($record['expires_at']) || $record['expires_at'] <= time()
            || $record['expires_at'] > time() + self::TTL) throw new AuthSessionException();
        self::assertGuestUid($record['uid']);
        self::assertAvatar($record['avatar']);
        return $record;
    }

    private static function nativeExpiry(mixed $capTtl, mixed $uidTtl, mixed $nowMs, int $expiresAt): bool
    {
        if (!is_int($capTtl) || !is_int($uidTtl) || !is_int($nowMs)
            || $capTtl <= 0 || $uidTtl <= 0 || $capTtl > self::TTL * 1000
            || $uidTtl > self::TTL * 1000 || $nowMs <= 0 || $expiresAt <= time()) return false;
        // Redis TIME and PTTL observations can straddle a millisecond boundary.
        $deadline = $expiresAt * 1000;
        return abs($nowMs + $capTtl - $deadline) <= 2 && abs($nowMs + $uidTtl - $deadline) <= 2;
    }

    private static function assertGuestUid(int $uid): void
    {
        if ($uid >= 0 || $uid < -self::MAX_UID) throw new AuthSessionException();
    }

    private static function assertAvatar(string $avatar): void
    {
        if (str_contains($avatar, "\0") || preg_match('//u', $avatar) !== 1) throw new AuthSessionException();
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $token): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) throw new AuthSessionException();
        $bytes = base64_decode(strtr($token, '-_', '+/') . '=', true);
        if (!is_string($bytes) || strlen($bytes) !== 32 || !hash_equals(self::encode($bytes), $token)) {
            throw new AuthSessionException();
        }
        return $bytes;
    }

    private static function table(Mysql $connection, string $name): string
    {
        $table = $connection->name($name)->getTable();
        if (!is_string($table) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
            throw new AuthUnavailableException();
        }
        return '`' . $table . '`';
    }

    private static function counter(PDO $pdo, string $table, bool $lock): ?array
    {
        $stmt = $pdo->prepare('SELECT `result`,`expire_time`,`add_time` FROM ' . $table
            . ' WHERE `key`=?' . ($lock ? ' FOR UPDATE' : ''));
        if (!$stmt->execute([self::COUNTER_KEY])) throw new AuthUnavailableException();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function negativeStateExists(PDO $pdo, Mysql $connection): bool
    {
        foreach ([['store_service_log', 'uid'], ['store_service_record', 'user_id']] as [$name, $column]) {
            $stmt = $pdo->query('SELECT 1 FROM ' . self::table($connection, $name)
                . ' WHERE `' . $column . '`<0 OR `to_uid`<0 LIMIT 1');
            if ($stmt === false) throw new AuthUnavailableException();
            if ($stmt->fetchColumn() !== false) return true;
        }
        return false;
    }

    /** A confirmed default-DB commit precedes every returned capability. */
    private static function allocateUid(): int
    {
        $connection = Db::connect();
        if (!$connection instanceof Mysql) throw new AuthUnavailableException();
        $pdo = $connection->connect();
        if (!$pdo instanceof PDO || $pdo->inTransaction()) throw new AuthUnavailableException();
        $table = self::table($connection, 'cache');
        $locked = false; $lockName = '';
        try {
            if (self::counter($pdo, $table, false) === null) {
                $database = $connection->getConfig('database');
                if (!is_string($database) || $database === '') throw new AuthUnavailableException();
                $lockName = 'guest_uid_init:' . substr(hash('sha256', $database . '|' . $table), 0, 48);
                $stmt = $pdo->prepare('SELECT GET_LOCK(?, 2)');
                if (!$stmt->execute([$lockName]) || (string)$stmt->fetchColumn() !== '1') throw new AuthUnavailableException();
                $locked = true;
            }
            $uid = $connection->transaction(static function () use ($pdo, $connection, $table, $locked): int {
                if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || !$pdo->inTransaction()) {
                    throw new AuthUnavailableException();
                }
                $row = self::counter($pdo, $table, true);
                if ($row === null) {
                    if (!$locked || self::negativeStateExists($pdo, $connection)) throw new AuthUnavailableException();
                    $now = time();
                    if ($now <= 0 || $now > 2147483647) throw new AuthUnavailableException();
                    $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (`key`,`result`,`expire_time`,`add_time`) VALUES (?,?,0,?)');
                    if (!$stmt->execute([self::COUNTER_KEY, '0', $now]) || $stmt->rowCount() !== 1) throw new AuthUnavailableException();
                    $row = self::counter($pdo, $table, true);
                }
                if (!is_array($row) || !is_string($row['result'] ?? null)
                    || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $row['result']) !== 1
                    || (string)($row['expire_time'] ?? '') !== '0'
                    || (int)$row['result'] >= self::MAX_UID) throw new AuthUnavailableException();
                $next = (int)$row['result'] + 1;
                $stmt = $pdo->prepare('UPDATE ' . $table . ' SET `result`=? WHERE `key`=? AND `result`=? AND `expire_time`=0');
                if (!$stmt->execute([(string)$next, self::COUNTER_KEY, $row['result']]) || $stmt->rowCount() !== 1) {
                    throw new AuthUnavailableException();
                }
                return -$next;
            });
            if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()) {
                throw new AuthUnavailableException();
            }
            return $uid;
        } finally {
            if ($locked) {
                try {
                    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    if (!$stmt->execute([$lockName]) || (string)$stmt->fetchColumn() !== '1') throw new AuthUnavailableException();
                } catch (Throwable $error) {
                    throw new AuthUnavailableException();
                }
            }
        }
    }
}
