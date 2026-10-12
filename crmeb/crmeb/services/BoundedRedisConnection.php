<?php
declare(strict_types=1);

namespace crmeb\services;

use crmeb\exceptions\AuthUnavailableException;
use think\facade\Config;

/** Independent native connection; shared Cache client options remain untouched. */
final class BoundedRedisConnection
{
    public static function fromDefaultCacheConfig(): \Redis
    {
        try {
            $store = Config::get('cache.default');
            $options = is_string($store) ? Config::get('cache.stores.' . $store) : null;
            if (!is_array($options) || !is_string($options['type'] ?? null)
                || strcasecmp($options['type'], 'redis') !== 0) {
                throw new \RuntimeException();
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
                throw new \RuntimeException();
            }
            $redis = new \Redis();
            if (@$redis->connect($host, (int)$port, 2.0, null, 0, 1.0) !== true
                || ($password !== '' && @$redis->auth($password) !== true)
                || @$redis->select((int)$database) !== true
                || @$redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0) !== true) {
                throw new \RuntimeException();
            }
            return $redis;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** Actual named Queue options, never the default Cache server or database. */
    public static function fromQueueConfig(array $options): \Redis
    {
        try {
            $host = $options['host'] ?? null;
            $port = $options['port'] ?? null;
            $database = $options['select'] ?? null;
            $password = $options['password'] ?? null;
            $validPassword = is_string($password) || (is_array($password)
                && array_keys($password) === [0, 1] && is_string($password[0]) && is_string($password[1]));
            if (($options['queue'] ?? null) !== 'marketplace' || ($options['persistent'] ?? null) !== false
                || !isset($options['timeout'], $options['read_timeout'])
                || !is_numeric($options['timeout']) || (float)$options['timeout'] !== 2.0
                || !is_numeric($options['read_timeout']) || (float)$options['read_timeout'] !== 1.0
                || !is_string($host) || trim($host) === ''
                || (!is_int($port) && !(is_string($port) && ctype_digit($port)))
                || (int)$port < 1 || (int)$port > 65535
                || (!is_int($database) && !(is_string($database) && ctype_digit($database)))
                || (int)$database < 0 || (int)$database > 2147483647 || !$validPassword) {
                throw new \RuntimeException();
            }
            $redis = new \Redis();
            if (@$redis->connect($host, (int)$port, 2.0, null, 0, 1.0) !== true
                || ($password !== '' && @$redis->auth($password) !== true)
                || @$redis->select((int)$database) !== true
                || @$redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0) !== true
                || @$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE) !== true
                || @$redis->setOption(\Redis::OPT_PREFIX, '') !== true
                || @$redis->getLastError() !== null) {
                throw new \RuntimeException();
            }
            return $redis;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }
}
