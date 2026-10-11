<?php
declare(strict_types=1);

namespace crmeb\services\auth;

use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\AuthConfigGuard;
use think\facade\Config;

/**
 * Redis is the authority for v2 sessions. No token or subject is retained
 * between calls; account and session predicates execute together in Lua.
 */
class AuthSessionStore
{
    private \Redis $redis;
    private string $epoch;

    public function __construct(\Redis $redis, string $epoch)
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $epoch)) {
            throw new AuthUnavailableException();
        }
        $this->redis = $redis;
        $this->epoch = $epoch;
        try {
            if ($redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0) !== true) {
                throw new \RuntimeException();
            }
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** A separate raw connection must not inherit shared Cache client options. */
    public static function fromConfig(): self
    {
        $auth = AuthConfigGuard::authConfig();
        $options = Config::get('cache.stores.' . Config::get('cache.default'));
        if (!is_array($options)) {
            throw new AuthUnavailableException();
        }
        $host = $options['host'] ?? null;
        $port = $options['port'] ?? 6379;
        $database = $options['select'] ?? 0;
        $password = $options['password'] ?? '';
        $validPassword = is_string($password) || (is_array($password)
            && array_keys($password) === [0, 1] && is_string($password[0]) && is_string($password[1]));
        if (!is_string($host) || trim($host) === ''
            || (!is_int($port) && !(is_string($port) && ctype_digit($port)))
            || (int)$port < 1 || (int)$port > 65535
            || (!is_int($database) && !(is_string($database) && ctype_digit($database)))
            || (int)$database < 0 || (int)$database > 2147483647
            || !$validPassword) {
            throw new AuthUnavailableException();
        }
        try {
            $redis = new \Redis();
            if (@$redis->connect($host, (int)$port, 2.0, null, 0, 1.0) !== true
                || ($password !== '' && @$redis->auth($password) !== true)
                || @$redis->select((int)$database) !== true) {
                throw new \RuntimeException();
            }
            return new self($redis, $auth['session_epoch']);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** Configuration only, for live epoch checks in long-running consumers. */
    public function epoch(): string
    {
        return $this->epoch;
    }

    /** Delete only one retired epoch using this store's bounded raw connection. */
    public function deleteOldEpoch(string $oldEpoch): int
    {
        try {
            $auth = AuthConfigGuard::authConfig();
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $oldEpoch) !== 1
                || $auth['session_epoch'] !== $this->epoch || $oldEpoch === $this->epoch) {
                throw new \RuntimeException();
            }
            $prefix = 'auth:v2:' . $oldEpoch . ':';
            $cursor = '0';
            $deleted = 0;
            do {
                if (@$this->redis->clearLastError() !== true) {
                    throw new \RuntimeException();
                }
                // Inspect the full native reply; scan()'s false result and local
                // error state cannot reliably prove a successful empty page.
                $page = @$this->redis->rawCommand('SCAN', $cursor, 'MATCH', $prefix . '*', 'COUNT', 200);
                $lastError = @$this->redis->getLastError();
                if ($lastError !== null || !is_array($page) || array_keys($page) !== [0, 1]
                    || !is_string($page[0]) || !is_array($page[1]) || !array_is_list($page[1])) {
                    throw new \RuntimeException();
                }
                $cursor = $page[0];
                if (preg_match('/^(0|[1-9][0-9]{0,19})$/D', $cursor) !== 1
                    || (strlen($cursor) === 20 && strcmp($cursor, '18446744073709551615') > 0)) {
                    throw new \RuntimeException();
                }
                // SCAN may return an empty page before its cursor reaches zero.
                $keys = $page[1];
                foreach ($keys as $key) {
                    if (!is_string($key) || !str_starts_with($key, $prefix)) {
                        throw new \RuntimeException();
                    }
                }
                // COUNT is a hint; validate the entire page before its first DEL.
                foreach (array_chunk($keys, 200) as $batch) {
                    $removed = @$this->redis->del($batch);
                    if (!is_int($removed) || $removed < 0 || $removed > count($batch)) {
                        throw new \RuntimeException();
                    }
                    $deleted += $removed;
                }
            } while ($cursor !== '0');
            return $deleted;
        } catch (\Throwable $error) {
            // A partially completed cleanup is not retried or reported as success.
            throw new AuthUnavailableException();
        }
    }

    /** Only credential-login callers may initialize an authorization version. */
    public function initAccount(string $app, string $id): string
    {
        return $this->accountCommand($app, $id, 'init', $this->nonce());
    }

    public function createSession(string $app, string $id, string $expectedVersion, array $session): void
    {
        $account = $this->accountKey($app, $id);
        $this->assertNonce($expectedVersion);
        $this->assertSession($session);
        if ($session['session_version'] !== $expectedVersion) {
            throw new AuthSessionException();
        }
        $this->execute('create_session', [$account, $this->sessionKey($app, $session['sid'])], [
            $id, $expectedVersion, $session['type'], $session['audience'],
            $session['token_digest'], $session['credential_stamp'], (string)$session['expires_at'],
        ]);
    }

    public function verify(string $app, string $sid, string $id, string $type, string $audience,
                           string $version, string $tokenDigest, string $credentialStamp): void
    {
        $account = $this->accountKey($app, $id);
        $session = $this->sessionKey($app, $sid);
        $this->assertText($type);
        $this->assertText($audience);
        $this->assertNonce($version);
        $this->assertDigest($tokenDigest);
        $this->assertDigest($credentialStamp);
        $this->execute('verify_session', [$account, $session], [
            $id, $type, $audience, $version, $tokenDigest, $credentialStamp,
        ]);
    }

    public function revokeSession(string $app, string $sid): void
    {
        $key = $this->sessionKey($app, $sid);
        try {
            $deleted = $this->redis->del($key);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        if (!is_int($deleted)) {
            throw new AuthUnavailableException();
        }
    }

    /** Logout requires the exact persisted binding, without a DB digest read. */
    public function revokeBoundSession(string $app, string $sid, string $id, string $type,
                                       string $audience, string $version, string $tokenDigest): void
    {
        $account = $this->accountKey($app, $id);
        $session = $this->sessionKey($app, $sid);
        $this->assertText($type);
        $this->assertText($audience);
        $this->assertNonce($version);
        $this->assertDigest($tokenDigest);
        $this->execute('revoke_session', [$account, $session], [$id, $type, $audience, $version, $tokenDigest]);
    }

    public function rotateAccount(string $app, string $id): string
    {
        return $this->accountCommand($app, $id, 'rotate', $this->nonce());
    }

    public function block(string $app, string $id): void
    {
        $this->accountCommand($app, $id, 'block');
    }

    public function unblock(string $app, string $id): void
    {
        $this->accountCommand($app, $id, 'unblock');
    }

    public function beginChange(string $app, string $id): string
    {
        return $this->accountCommand($app, $id, 'begin', $this->nonce(), $this->nonce());
    }

    public function completeChange(string $app, string $id, string $owner): void
    {
        $this->assertNonce($owner);
        $this->accountCommand($app, $id, 'complete', '', $owner);
    }

    public function redeem(string $app, string $oldSid, string $id, array $newSession): void
    {
        $account = $this->accountKey($app, $id);
        $old = $this->sessionKey($app, $oldSid);
        $this->assertSession($newSession);
        $previousDigest = $newSession['previous_token_digest'] ?? null;
        $this->assertDigest($previousDigest);
        $new = $this->sessionKey($app, $newSession['sid']);
        if ($old === $new) {
            throw new AuthSessionException();
        }
        $this->execute('redeem_session', [$account, $old, $new], [
            $id, $newSession['session_version'], $newSession['type'], $newSession['audience'],
            $newSession['token_digest'], $newSession['credential_stamp'],
            (string)$newSession['expires_at'], $previousDigest,
        ]);
    }

    private function accountCommand(string $app, string $id, string $mode, string $version = '', string $owner = ''): string
    {
        $result = $this->execute('rotate_account', [$this->accountKey($app, $id)], [$mode, $version, $owner]);
        return $result[1] ?? '';
    }

    private function accountKey(string $app, string $id): string
    {
        $this->assertApp($app);
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new AuthSessionException();
        }
        return 'auth:v2:' . $this->epoch . ':' . $app . ':account:' . $id;
    }

    private function sessionKey(string $app, string $sid): string
    {
        $this->assertApp($app);
        $this->assertNonce($sid);
        return 'auth:v2:' . $this->epoch . ':' . $app . ':session:' . $sid;
    }

    private function assertApp(string $app): void
    {
        if (!in_array($app, ['adminapi', 'outapi'], true)) {
            throw new AuthSessionException();
        }
    }

    private function assertSession(array $session): void
    {
        foreach (['sid', 'session_version', 'type', 'audience', 'token_digest', 'credential_stamp', 'expires_at'] as $field) {
            if (!array_key_exists($field, $session)) {
                throw new AuthSessionException();
            }
        }
        $this->assertNonce($session['sid']);
        $this->assertNonce($session['session_version']);
        $this->assertText($session['type']);
        $this->assertText($session['audience']);
        $this->assertDigest($session['token_digest']);
        $this->assertDigest($session['credential_stamp']);
        if (!is_int($session['expires_at']) || $session['expires_at'] <= 0 || $session['expires_at'] > 999999999999) {
            throw new AuthSessionException();
        }
    }

    private function assertNonce($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
            throw new AuthSessionException();
        }
    }

    private function assertDigest($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new AuthSessionException();
        }
    }

    private function assertText($value): void
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9._:\/-]{1,128}$/D', $value)) {
            throw new AuthSessionException();
        }
    }

    private function nonce(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private function execute(string $name, array $keys, array $arguments): array
    {
        try {
            $path = __DIR__ . '/lua/' . $name . '.lua';
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException();
            }
            $script = file_get_contents($path);
            if (!is_string($script) || $script === '') {
                throw new \RuntimeException();
            }
            $result = $this->redis->eval($script, array_merge($keys, $arguments), count($keys));
        } catch (\Throwable $error) {
            // Do not translate, log driver messages/parameters, or retain previous.
            // An executed write with lost acknowledgement must never be retried here.
            throw new AuthUnavailableException();
        }
        if ($result === ['AUTH']) {
            throw new AuthSessionException();
        }
        if (!is_array($result) || ($result[0] ?? null) !== 'OK'
            || (isset($result[1]) && !is_string($result[1]))) {
            throw new AuthUnavailableException();
        }
        return $result;
    }
}
