<?php
declare(strict_types=1);

namespace app\services\marketplace\assets;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\BoundedRedisConnection;
use Redis;
use stdClass;
use think\facade\Config;
use Throwable;

/** Server-only storage. Binding shape is not authenticated order/asset permission. */
final class CustomerLegacyRefundReadTickets
{
    private const UINT_MAX = 4294967295;
    private const MAX_MS = 4294967295999;
    private const LIFETIME_MS = 300000;
    private const KEY_PREFIX = 'mp_legacy_refund_read:';
    private const BINDING=['reader_kind','app','actor_type','actor_id','session_sha256','uid','asset_id',
        'content_sha256','root_order_id','order_id','refund_id','purpose'];
    private const PAYLOAD=['schema','reader_kind','app','actor_type','actor_id','session_sha256','uid','asset_id',
        'content_sha256','root_order_id','order_id','refund_id','purpose','issued_at_ms','expires_at_ms','scope'];

    private const CLOCK = <<<'LUA'
local t=redis.call('TIME')
return tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
LUA;
    private const CREATE = <<<'LUA'
local t=redis.call('TIME')
local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
local issued=tonumber(ARGV[2])
local expires=tonumber(ARGV[3])
if not issued or not expires or issued > now or expires <= now or expires > issued+300000 then
    return {0,'',-2,now}
end
local created=redis.call('SET',KEYS[1],ARGV[1],'NX','PXAT',ARGV[3])
if type(created) ~= 'table' or created.ok ~= 'OK' then return {0,'',-2,now} end
return {1,redis.call('GET',KEYS[1]),redis.call('PEXPIRETIME',KEYS[1]),now}
LUA;
    private const PEEK = <<<'LUA'
local raw=redis.call('GET',KEYS[1])
local expires=redis.call('PEXPIRETIME',KEYS[1])
local t=redis.call('TIME')
local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
if not raw then return {0,'',expires,now} end
return {1,raw,expires,now}
LUA;
    private const CONSUME = <<<'LUA'
local raw=redis.call('GET',KEYS[1])
local expires=redis.call('PEXPIRETIME',KEYS[1])
local t=redis.call('TIME')
local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
local issued=tonumber(ARGV[2])
local expected=tonumber(ARGV[3])
if raw == ARGV[1] and issued and expected and expires == expected
    and issued <= now and now < expires and expires <= issued+300000 then
    return redis.call('DEL',KEYS[1])
end
return 0
LUA;

    /** All returned times are Redis Unix milliseconds, in this fixed legacy-refund scope. */
    public function issue(array $serverBinding): array
    {
        try {
            $binding = self::binding($serverBinding);
            $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            self::ticket($ticket);
            [$redis, $prefix] = $this->connection();
            try {
                $issued = self::integer($redis->eval(self::CLOCK, [], 0), 1, self::MAX_MS);
                if ($redis->getLastError() !== null || $issued > self::MAX_MS - self::LIFETIME_MS) self::unavailable();
                $expires = $issued + self::LIFETIME_MS;
                $payload = ['schema' => 1];
                foreach (self::BINDING as $key) $payload[$key] = $binding[$key];
                $payload += ['issued_at_ms' => $issued, 'expires_at_ms' => $expires,
                    'scope' => 'legacy_refund_material_read'];
                self::payload($payload, $binding);
                self::atTime($payload, $issued);
                $raw = MarketplaceOrderInput::canonical($payload);
                $reply = $redis->eval(self::CREATE, [$prefix . self::KEY_PREFIX . hash('sha256', $ticket),
                    $raw, (string)$issued, (string)$expires], 1);
                [$stored, $deadline, $now] = self::observation($reply);
                if ($stored !== $raw || $deadline !== $expires || $redis->getLastError() !== null) self::unavailable();
                self::atTime($payload, $now);
            } finally {
                if (@$redis->close() !== true) self::unavailable();
            }
            return ['read_ticket' => $ticket, 'issued_at_ms' => $issued, 'expires_at_ms' => $expires];
        } catch (Throwable $error) {
            self::unavailable();
        }
    }

    /** Internal receipt only; do not expose its raw payload to HTTP or logs. */
    public function peek(string $ticket, array $serverBinding): array
    {
        try {
            self::ticket($ticket);
            $binding = self::binding($serverBinding);
            [$redis, $prefix] = $this->connection();
            try {
                $reply = $redis->eval(self::PEEK, [$prefix . self::KEY_PREFIX . hash('sha256', $ticket)], 1);
                [$raw, $deadline, $now] = self::observation($reply);
                if ($redis->getLastError() !== null) self::unavailable();
                $payload = self::decode($raw);
                self::payload($payload, $binding);
                if ($deadline !== $payload['expires_at_ms']) self::unavailable();
                self::atTime($payload, $now);
                return ['cache_key_sha256' => hash('sha256', $ticket), 'raw' => $raw];
            } finally {
                if (@$redis->close() !== true) self::unavailable();
            }
        } catch (Throwable $error) {
            self::unavailable();
        }
    }

    /** Only after current authorization, the real saved relationship and full content are verified. */
    public function consume(string $ticket, array $serverBinding, array $rawReceipt): void
    {
        try {
            self::ticket($ticket);
            $binding = self::binding($serverBinding);
            self::keys($rawReceipt, ['cache_key_sha256', 'raw']);
            $digest = hash('sha256', $ticket);
            if ($rawReceipt['cache_key_sha256'] !== $digest || !is_string($rawReceipt['raw'])) self::unavailable();
            $payload = self::decode($rawReceipt['raw']);
            self::payload($payload, $binding);
            [$redis, $prefix] = $this->connection();
            try {
                if ($redis->eval(self::CONSUME, [$prefix . self::KEY_PREFIX . $digest, $rawReceipt['raw'],
                    (string)$payload['issued_at_ms'], (string)$payload['expires_at_ms']], 1) !== 1
                    || $redis->getLastError() !== null) self::unavailable();
            } finally {
                if (@$redis->close() !== true) self::unavailable();
            }
            // A consumed or uncertain ticket is never restored or retried.
        } catch (Throwable $error) {
            self::unavailable();
        }
    }

    private function connection(): array
    {
        $redis = BoundedRedisConnection::fromDefaultCacheConfig();
        try {
            $name = Config::get('cache.default');
            $options = is_string($name) ? Config::get('cache.stores.' . $name) : null;
            if (!is_array($options) || !is_string($options['type'] ?? null) || strcasecmp($options['type'], 'redis') !== 0
                || !is_string($options['prefix'] ?? null) || str_contains($options['prefix'], "\0")
                || @$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE) !== true
                || @$redis->setOption(Redis::OPT_PREFIX, '') !== true
                || $redis->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE
                || $redis->getOption(Redis::OPT_PREFIX) !== null || $redis->getLastError() !== null) self::unavailable();
            return [$redis, $options['prefix']];
        } catch (Throwable $error) {
            try { $redis->close(); } catch (Throwable $ignored) {}
            self::unavailable();
        }
    }

    private static function binding(array $value): array
    {
        if(PHP_INT_SIZE!==8)self::unavailable();self::keys($value,self::BINDING);
        $readers=['application_preview'=>['api','customer'],'customer_refund'=>['api','customer'],
            'platform_list'=>['adminapi','admin'],'platform_detail'=>['adminapi','admin'],'mobile_refund'=>['api','customer']];
        if(!is_string($value['reader_kind'])||!isset($readers[$value['reader_kind']])
            ||[$value['app'],$value['actor_type']]!==$readers[$value['reader_kind']])self::unavailable();
        self::integer($value['uid'],1,2147483647);self::integer($value['actor_id'],1,self::UINT_MAX);
        foreach(['root_order_id','order_id']as$key)self::integer($value[$key],1,2147483647);
        if(in_array($value['reader_kind'],['application_preview','customer_refund'],true)&&$value['actor_id']!==$value['uid'])self::unavailable();
        if($value['reader_kind']==='application_preview'){
            if($value['refund_id']!==null||$value['purpose']!=='legacy_refund_evidence')self::unavailable();
        }else self::integer($value['refund_id'],1,2147483647);
        if(!in_array($value['purpose'],['legacy_refund_evidence','refund_shipping_proof'],true))self::unavailable();
        self::assetId($value['asset_id']);
        foreach(['session_sha256','content_sha256']as$key)if(!is_string($value[$key])||preg_match('/\A[a-f0-9]{64}\z/D',$value[$key])!==1)self::unavailable();
        $ordered=[];foreach(self::BINDING as$key)$ordered[$key]=$value[$key];return $ordered;
    }

    private static function payload(array $value,array $binding):void
    {
        self::keys($value,self::PAYLOAD);self::binding($binding);
        foreach(self::BINDING as$key)if($value[$key]!==$binding[$key])self::unavailable();
        if($value['schema']!==1||$value['scope']!=='legacy_refund_material_read')self::unavailable();
        $issued=self::integer($value['issued_at_ms'],1,self::MAX_MS);$expires=self::integer($value['expires_at_ms'],1,self::MAX_MS);
        if($issued>self::MAX_MS-self::LIFETIME_MS||$expires!==$issued+self::LIFETIME_MS)self::unavailable();
    }

    private static function observation(mixed $reply): array
    {
        if (!is_array($reply) || array_keys($reply) !== [0, 1, 2, 3] || $reply[0] !== 1 || !is_string($reply[1])) self::unavailable();
        return [$reply[1], self::integer($reply[2], 1, self::MAX_MS), self::integer($reply[3], 1, self::MAX_MS)];
    }

    private static function atTime(array $payload, int $nowMs): void
    {
        self::integer($nowMs, 1, self::MAX_MS);
        if ($payload['issued_at_ms'] > $nowMs || $payload['expires_at_ms'] <= $nowMs) self::unavailable();
    }

    private static function decode(string $raw): array
    {
        if (strlen($raw) > 4096 || preg_match('//u', $raw) !== 1) self::unavailable();
        try { $value = json_decode($raw, false, 8, JSON_THROW_ON_ERROR); } catch (Throwable $error) { self::unavailable(); }
        if (!$value instanceof stdClass) self::unavailable();
        $value = get_object_vars($value);
        self::keys($value, self::PAYLOAD);
        if (MarketplaceOrderInput::canonical($value) !== $raw) self::unavailable();
        return $value;
    }

    private static function ticket(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $value) !== 1) self::unavailable();
        $raw = base64_decode(strtr($value, '-_', '+/') . '=', true);
        if (!is_string($raw) || strlen($raw) !== 32 || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $value) self::unavailable();
    }

    private static function assetId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) !== 1
            || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) self::unavailable();
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) self::unavailable();
        return $value;
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) self::unavailable();
    }

    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
