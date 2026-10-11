<?php
declare(strict_types=1);

namespace crmeb\services\auth;

use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\AuthConfigGuard;
use Firebase\JWT\JWT;

/** A token becomes usable only after its exact server-side binding is saved. */
class AuthTokenV2
{
    private AuthSessionStore $store;

    public function __construct(AuthSessionStore $store)
    {
        $this->store = $store;
    }

    public static function fromConfig(): self
    {
        return new self(AuthSessionStore::fromConfig());
    }

    public function issue(string $app, string $id, string $version, string $credentialStamp): array
    {
        $config = $this->configuration();
        $this->assertApp($app);
        $this->assertId($id);
        $this->assertNonce($version);
        $this->assertStamp($credentialStamp);
        $issued = $this->generate($app, $id, $version, $config);
        $this->store->createSession($app, $id, $version,
            $this->session($app, $version, $credentialStamp, $issued, $config));
        return $issued;
    }

    public function verify(string $app, string $token, callable $currentCredentialDigest): array
    {
        $config = $this->configuration();
        $this->assertApp($app);
        $claims = $this->decode($app, $token, $config);
        $this->verifyCurrent($app, $token, $claims, $currentCredentialDigest, $config);
        return ['id' => $claims['id'], 'sid' => $claims['sid'], 'version' => $claims['version']];
    }

    public function refresh(string $app, string $token, callable $currentCredentialDigest): array
    {
        $config = $this->configuration();
        $this->assertApp($app);
        $claims = $this->decode($app, $token, $config);
        $stamp = $this->verifyCurrent($app, $token, $claims, $currentCredentialDigest, $config);
        $issued = $this->generate($app, $claims['id'], $claims['version'], $config);
        $session = $this->session($app, $claims['version'], $stamp, $issued, $config);
        $session['previous_token_digest'] = hash('sha256', $token);
        // A lost redemption reply is a failure. Do not retry, compensate,
        // restore the consumed sid, or return the unacknowledged replacement.
        $this->store->redeem($app, $claims['sid'], $claims['id'], $session);
        return $issued;
    }

    public function revoke(string $app, string $token): void
    {
        $config = $this->configuration();
        $this->assertApp($app);
        $claims = $this->decode($app, $token, $config);
        $binding = $config['apps'][$app];
        $this->store->revokeBoundSession($app, $claims['sid'], $claims['id'],
            $binding['type'], $binding['audience'], $claims['version'], hash('sha256', $token));
    }

    private function configuration(): array
    {
        $config = AuthConfigGuard::authConfig();
        if ($this->store->epoch() !== $config['session_epoch']) {
            throw new AuthUnavailableException();
        }
        return $config;
    }

    private function assertApp(string $app): void
    {
        if (!in_array($app, ['adminapi', 'outapi', 'merchantapi'], true)) {
            throw new AuthSessionException();
        }
    }

    private function assertId($id): string
    {
        if (is_int($id) && $id > 0) {
            $id = (string)$id;
        }
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new AuthSessionException();
        }
        return $id;
    }

    private function assertNonce($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
            throw new AuthSessionException();
        }
    }

    private function assertStamp($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new AuthSessionException();
        }
    }

    private function decode(string $app, string $token, array $config): array
    {
        $now = time();
        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        try {
            JWT::$leeway = 60;
            JWT::$timestamp = $now;
            $payload = JWT::decode($token, $config['signing_key'], ['HS256']);
        } catch (\Throwable $error) {
            throw new AuthSessionException();
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }
        $binding = $config['apps'][$app];
        if (!is_object($payload)
            || ($payload->auth_schema ?? null) !== 2
            || ($payload->session_epoch ?? null) !== $config['session_epoch']
            || ($payload->iss ?? null) !== $config['issuer']
            || ($payload->aud ?? null) !== $binding['audience']
            || !isset($payload->jti) || !is_object($payload->jti)
            || ($payload->jti->type ?? null) !== $binding['type']) {
            throw new AuthSessionException();
        }
        foreach (['pwd', 'appsecret', 'credential_stamp', 'token_digest', 'stamp_key'] as $field) {
            if (property_exists($payload, $field) || property_exists($payload->jti, $field)) {
                throw new AuthSessionException();
            }
        }
        $id = $this->assertId($payload->jti->id ?? null);
        $this->assertNonce($payload->sid ?? null);
        $this->assertNonce($payload->session_version ?? null);
        foreach (['iat', 'nbf', 'exp'] as $field) {
            $value = $payload->$field ?? null;
            if (!is_int($value) || $value <= 0 || $value > 999999999999) {
                throw new AuthSessionException();
            }
        }
        if ($payload->nbf > $payload->exp || $payload->exp <= $payload->iat
            || $payload->exp - $payload->iat > $binding['ttl']
            || $payload->iat > $now + 60 || $payload->nbf > $now + 60
            || $payload->exp <= $now - 60) {
            throw new AuthSessionException();
        }
        return ['id' => $id, 'sid' => $payload->sid, 'version' => $payload->session_version];
    }

    private function verifyCurrent(string $app, string $token, array $claims,
                                   callable $currentCredentialDigest, array $config): string
    {
        try {
            $digest = $currentCredentialDigest($claims['id']);
        } catch (AuthException $error) {
            if ($error->getCode() === 401) {
                throw new AuthSessionException();
            }
            throw new AuthUnavailableException();
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        if (!is_string($digest) || $digest === '') {
            throw new AuthSessionException();
        }
        $stamp = CredentialStamp::for($app, $claims['id'], $digest);
        $binding = $config['apps'][$app];
        $this->store->verify($app, $claims['sid'], $claims['id'], $binding['type'],
            $binding['audience'], $claims['version'], hash('sha256', $token), $stamp);
        return $stamp;
    }

    private function generate(string $app, string $id, string $version, array $config): array
    {
        $now = time();
        $binding = $config['apps'][$app];
        $exp = $now + $binding['ttl'];
        if (!is_int($exp) || $now <= 0 || $exp <= $now || $exp > 999999999999) {
            throw new AuthUnavailableException();
        }
        try {
            $sid = bin2hex(random_bytes(16));
            $token = JWT::encode([
                'auth_schema' => 2, 'session_epoch' => $config['session_epoch'],
                'sid' => $sid, 'session_version' => $version,
                'iss' => $config['issuer'], 'aud' => $binding['audience'],
                'jti' => ['id' => $id, 'type' => $binding['type']],
                'iat' => $now, 'nbf' => $now, 'exp' => $exp,
            ], $config['signing_key'], 'HS256');
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        return ['token' => $token, 'exp' => $exp, 'sid' => $sid];
    }

    private function session(string $app, string $version, string $stamp, array $issued, array $config): array
    {
        $binding = $config['apps'][$app];
        return [
            'sid' => $issued['sid'], 'session_version' => $version,
            'type' => $binding['type'], 'audience' => $binding['audience'],
            'token_digest' => hash('sha256', $issued['token']),
            'credential_stamp' => $stamp, 'expires_at' => $issued['exp'],
        ];
    }
}
