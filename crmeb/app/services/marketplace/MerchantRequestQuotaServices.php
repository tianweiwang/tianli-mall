<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\services\marketplace\context\MerchantContext;
use crmeb\services\BoundedRedisConnection;
use Redis;
use RuntimeException;
use think\facade\Config;
use Throwable;

/** One fixed window per trusted merchant; no request or connection survives admission. */
final class MerchantRequestQuotaServices
{
    private const KEY_PREFIX = 'marketplace:merchant_request_quota:v1:';

    public function admit(MerchantContext $context): array
    {
        $decision = ['outcome' => 'unavailable', 'policy_version' => null, 'window_seconds' => null,
            'max_requests' => null, 'used' => null, 'remaining' => null, 'retry_after_seconds' => null, 'log' => true];
        $redis = null;
        try {
            $config = Config::get('marketplace.request_quota');
            if (!is_array($config) || !is_bool($config['enabled'] ?? null)) throw new RuntimeException();
            if ($config['enabled'] === false) {
                $decision['outcome'] = 'disabled';
                $decision['log'] = false;
                return $decision;
            }

            $version = $config['policy_version'] ?? null;
            if (!is_string($version) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,31}\z/D', $version) !== 1) {
                throw new RuntimeException();
            }
            $decision['policy_version'] = $version;
            $window = self::positive($config['window_seconds'] ?? null, 86400);
            $decision['window_seconds'] = $window;
            $maximum = self::positive($config['max_requests'] ?? null, 1000000);
            $decision['max_requests'] = $maximum;

            $store = Config::get('cache.default');
            $options = is_string($store) && $store !== '' ? Config::get('cache.stores.' . $store) : null;
            if (!is_array($options) || !is_string($options['type'] ?? null)
                || strcasecmp($options['type'], 'redis') !== 0 || !is_string($options['prefix'] ?? null)
                || strlen($options['prefix']) > 128 || str_contains($options['prefix'], "\0")) {
                throw new RuntimeException();
            }
            $policy = json_encode([$version, $window, $maximum], JSON_THROW_ON_ERROR);
            $key = $options['prefix'] . self::KEY_PREFIX . hash('sha256', $policy) . ':' . $context->merId;
            $script = @file_get_contents(__DIR__ . '/lua/merchant_request_quota.lua');
            if (!is_string($script) || $script === '') throw new RuntimeException();

            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            if (@$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE) !== true
                || @$redis->setOption(Redis::OPT_PREFIX, '') !== true
                || $redis->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE
                || $redis->getOption(Redis::OPT_PREFIX) !== null || $redis->getLastError() !== null) {
                throw new RuntimeException();
            }
            // A lost acknowledgement may already have consumed capacity; never retry or compensate.
            $reply = $redis->eval($script, [$key, (string)$window, (string)$maximum], 1);
            if ($redis->getLastError() !== null || !is_array($reply) || array_keys($reply) !== [0, 1, 2, 3]
                || !is_int($reply[0]) || !in_array($reply[0], [1, 2], true)
                || !is_int($reply[1]) || $reply[1] < 1 || $reply[1] > $maximum
                || !is_int($reply[2]) || $reply[2] < 1 || $reply[2] > $window
                || !is_int($reply[3]) || !in_array($reply[3], [0, 1], true)
                || ($reply[0] === 1 && $reply[3] !== 1)
                || ($reply[0] === 2 && $reply[1] !== $maximum)) {
                throw new RuntimeException();
            }
            $decision['outcome'] = $reply[0] === 1 ? 'admitted' : 'limited';
            $decision['used'] = $reply[1];
            $decision['remaining'] = $maximum - $reply[1];
            $decision['retry_after_seconds'] = $reply[2];
            $decision['log'] = $reply[3] === 1;
            return $decision;
        } catch (Throwable $ignored) {
            // Keep only validated policy fields; unknown usage and expiry remain null.
            return $decision;
        } finally {
            if ($redis instanceof Redis) {
                try { $redis->close(); } catch (Throwable $ignored) {}
            }
        }
    }

    private static function positive(mixed $value, int $maximum): int
    {
        if (is_string($value)) {
            if (strlen($value) > strlen((string)$maximum) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
                throw new RuntimeException();
            }
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 1 || $value > $maximum) throw new RuntimeException();
        return $value;
    }
}
