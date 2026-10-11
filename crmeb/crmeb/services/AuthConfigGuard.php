<?php
declare(strict_types=1);

namespace crmeb\services;

use crmeb\exceptions\AuthException;
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
}
