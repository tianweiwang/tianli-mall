<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace crmeb\services;

use crmeb\exceptions\AuthUnavailableException;
use think\facade\Cache;
use think\facade\Config;
use think\cache\TagSet;

/**
 * CRMEB 缓存类
 * Class CacheService
 * @package crmeb\services
 */
class CacheService
{
    /**
     * 过期时间
     * @var int
     */
    protected static $expire;

    /**
     * 写入缓存
     * @param string $name 缓存名称
     * @param mixed $value 缓存值
     * @param int|null $expire 缓存时间，为0读取系统缓存时间
     */
    public static function set(string $name, $value, int $expire = 0, string $tag = 'crmeb')
    {
        try {
            return Cache::tag($tag)->set($name, $value, $expire);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 如果不存在则写入缓存
     * @param string $name
     * @param mixed $default
     * @param int|null $expire
     * @param string $tag
     * @return mixed|string|null
     */
    public static function remember(string $name, $default = '', int $expire = 0, string $tag = 'crmeb')
    {
        try {
            return Cache::tag($tag)->remember($name, $default, $expire);
        } catch (\Throwable $e) {
            try {
                if (is_callable($default)) {
                    return $default();
                } else {
                    return $default;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }
    }

    /**
     * 读取缓存
     * @param string $name
     * @param mixed $default
     * @return mixed|string
     */
    public static function get(string $name, $default = '')
    {
        return Cache::get($name) ?? $default;
    }

    /** Write a download record and its original tag with native acknowledgement. */
    public static function setDownload(string $key, array $file): void
    {
        try {
            if ($key === '' || !is_string($file['path'] ?? null)
                || !is_string($file['fileName'] ?? null) || $file['path'] === '' || $file['fileName'] === '') {
                throw new AuthUnavailableException();
            }
            $options = self::downloadCacheOptions();
            $physicalKey = $options['prefix'] . $key;
            $tagKey = $options['prefix'] . $options['tag_prefix'] . md5('crmeb');
            if ($physicalKey === $tagKey) {
                throw new AuthUnavailableException();
            }
            $raw = serialize($file);
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            // Validate both key types before the first write, preserving the
            // Think Redis string record and set-based crmeb tag format.
            $script = <<<'LUA'
local fileType = redis.call('TYPE', KEYS[1]).ok
local tagType = redis.call('TYPE', KEYS[2]).ok
if (fileType ~= 'none' and fileType ~= 'string') or (tagType ~= 'none' and tagType ~= 'set') then
    return 0
end
redis.call('SETEX', KEYS[1], 300, ARGV[1])
redis.call('SADD', KEYS[2], KEYS[1])
return 1
LUA;
            if (@$redis->clearLastError() !== true) {
                throw new AuthUnavailableException();
            }
            $written = @$redis->rawCommand('EVAL', $script, 2, $physicalKey, $tagKey, $raw);
            if (@$redis->getLastError() !== null || $written !== 1) {
                throw new AuthUnavailableException();
            }
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** Consume exactly one validated download record without a shared client. */
    public static function consumeDownload(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        try {
            $options = self::downloadCacheOptions();
            // Think Driver::getCacheKey uses precisely this configured prefix.
            $physicalKey = $options['prefix'] . $key;
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            if (@$redis->clearLastError() !== true) {
                throw new AuthUnavailableException();
            }
            $raw = @$redis->rawCommand('GET', $physicalKey);
            if (@$redis->getLastError() !== null) {
                throw new AuthUnavailableException();
            }
            if ($raw === false || $raw === null) {
                return null;
            }
            if (!is_string($raw)) {
                throw new AuthUnavailableException();
            }
            $record = @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($record) || !is_string($record['path'] ?? null)
                || !is_string($record['fileName'] ?? null) || $record['path'] === ''
                || $record['fileName'] === '' || !is_file($record['path']) || !is_readable($record['path'])) {
                return null;
            }
            // Preserve the original tag membership; only the exact file key is
            // consumed. Expiry or replacement never removes a different value.
            $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current or current ~= ARGV[1] then
    return 0
end
return redis.call('DEL', KEYS[1])
LUA;
            if (@$redis->clearLastError() !== true) {
                throw new AuthUnavailableException();
            }
            $removed = @$redis->rawCommand('EVAL', $script, 1, $physicalKey, $raw);
            if (@$redis->getLastError() !== null) {
                throw new AuthUnavailableException();
            }
            if ($removed === 1) {
                return $record;
            }
            if ($removed === 0) {
                return null;
            }
            throw new AuthUnavailableException();
        } catch (\Throwable $error) {
            // Never expose private driver details, retry, or restore a record
            // after an unacknowledged consume.
            throw new AuthUnavailableException();
        }
    }

    /** Only the existing default Redis download representation is supported. */
    private static function downloadCacheOptions(): array
    {
        $store = Config::get('cache.default');
        $options = is_string($store) ? Config::get('cache.stores.' . $store) : null;
        if (!is_array($options) || !is_string($options['type'] ?? null)
            || strcasecmp($options['type'], 'redis') !== 0) {
            throw new AuthUnavailableException();
        }
        $prefix = array_key_exists('prefix', $options) ? $options['prefix'] : '';
        $tagPrefix = array_key_exists('tag_prefix', $options) ? $options['tag_prefix'] : 'tag:';
        $serialize = array_key_exists('serialize', $options) ? $options['serialize'] : [];
        if (!is_string($prefix) || !is_string($tagPrefix) || $serialize !== []) {
            throw new AuthUnavailableException();
        }
        return ['prefix' => $prefix, 'tag_prefix' => $tagPrefix];
    }

    /**
     * 删除缓存
     * @param string $name
     * @return bool
     */
    public static function delete(string $name)
    {
        return Cache::delete($name);
    }

    /**
     * 清空缓存池
     * @return bool
     */
    public static function clear(string $tag = 'crmeb')
    {
        return Cache::tag($tag)->clear();
    }

    /**
     * 清空全部缓存
     * @return bool
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/12/19
     */
    public static function clearAll()
    {
        return Cache::clear();
    }

    /**
     * 检查缓存是否存在
     * @param string $key
     * @return bool
     */
    public static function has(string $key)
    {
        try {
            return Cache::has($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 指定缓存类型
     * @param string $type
     * @param string $tag
     * @return TagSet
     */
    public static function store(string $type = 'file', string $tag = 'crmeb')
    {
        return Cache::store($type)->tag($tag);
    }

    /**
     * 检查锁
     * @param string $key
     * @param int $timeout
     * @return bool
     */
    public static function setMutex(string $key, int $timeout = 10): bool
    {
        $curTime = time();
        $readMutexKey = "redis:mutex:{$key}";
        $mutexRes = Cache::store('redis')->handler()->setnx($readMutexKey, $curTime + $timeout);
        if ($mutexRes) {
            return true;
        }
        //就算意外退出，下次进来也会检查key，防止死锁
        $time = Cache::store('redis')->handler()->get($readMutexKey);
        if ($curTime > $time) {
            Cache::store('redis')->handler()->del($readMutexKey);
            return Cache::store('redis')->handler()->setnx($readMutexKey, $curTime + $timeout);
        }
        return false;
    }

    /**
     * 删除锁
     * @param string $key
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/11/22
     */
    public static function delMutex(string $key)
    {
        $readMutexKey = "redis:mutex:{$key}";
        Cache::store('redis')->handler()->del($readMutexKey);
    }


    /**
     * 数据库锁
     * @param $key
     * @param $fn
     * @param int $ex
     * @return mixed
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public static function lock($key, $fn, int $ex = 6)
    {
        if (Config::get('cache.default') == 'file') {
            return $fn();
        }
        return app()->make(LockService::class)->exec($key, $fn, $ex);
    }
}
