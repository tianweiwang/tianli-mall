<?php
declare(strict_types=1);

namespace crmeb\services;

use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use think\facade\Config;
use think\facade\Env;

class AuthConfigGuard
{
    public static function signingKey(): string
    {
        $key = Env::get('app.app_key');
        if (!is_string($key) || trim($key) === '' || $key === 'default') {
            throw new AuthException('认证服务配置错误', [], 503);
        }
        return $key;
    }

    public static function assertRedisSessionStore(): void
    {
        $store = Config::get('cache.default');
        $type = is_string($store) ? Config::get('cache.stores.' . $store . '.type') : null;
        if (!is_string($type) || strcasecmp($type, 'redis') !== 0) {
            throw new AuthException('认证服务配置错误', [], 503);
        }
    }

    /** Pure v2 validation: legacy error constructors can perform language IO. */
    public static function authConfig(): array
    {
        $key = Env::get('app.app_key');
        $store = Config::get('cache.default');
        $type = is_string($store) ? Config::get('cache.stores.' . $store . '.type') : null;
        $config = Config::get('auth');
        if (!is_string($key) || trim($key) === '' || $key === 'default'
            || !is_string($type) || strcasecmp($type, 'redis') !== 0
            || !is_array($config)) {
            throw new AuthUnavailableException();
        }
        $epoch = $config['session_epoch'] ?? null;
        $stampKey = $config['stamp_key'] ?? null;
        if (!is_string($epoch) || !preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $epoch)
            || !is_string($stampKey) || strlen($stampKey) < 32 || hash_equals($key, $stampKey)
            || !self::validBindingText($config['issuer'] ?? null)
            || !is_array($config['apps'] ?? null)) {
            throw new AuthUnavailableException();
        }
        foreach (['adminapi' => 'admin', 'outapi' => 'out', 'merchantapi' => 'merchant'] as $app => $expectedType) {
            $binding = $config['apps'][$app] ?? null;
            if (!is_array($binding) || ($binding['type'] ?? null) !== $expectedType
                || !self::validBindingText($binding['audience'] ?? null)
                || !is_int($binding['ttl'] ?? null) || $binding['ttl'] <= 0
                || $binding['ttl'] > 2147483587) {
                throw new AuthUnavailableException();
            }
        }
        if ($config['apps']['merchantapi']['audience'] !== 'tianli:merchantapi') {
            throw new AuthUnavailableException();
        }
        $config['signing_key'] = $key;
        return $config;
    }

    private static function validBindingText($value): bool
    {
        return is_string($value) && (bool)preg_match('/^[A-Za-z0-9._:\/-]{1,128}$/D', $value);
    }
}
