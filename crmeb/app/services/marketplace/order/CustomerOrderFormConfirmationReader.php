<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\BoundedRedisConnection;
use think\facade\Cache;
use think\facade\Config;

/** Sole original confirmation key, read-only. Returned raw/identity stay inside the trusted form service. */
final class CustomerOrderFormConfirmationReader
{
    private const FIELDS = ['payload','frame','raw','deadline_ms','expires_at','redis_time_ms','identity'];
    private const IDENTITY = ['actual_uid','confirmation_key','confirmation_ref','logical_key','physical_key',
        'store_name','driver_class','driver_options_sha256'];
    private const READ = <<<'LUA'
local value = redis.call('GET', KEYS[1])
local deadline = redis.call('PEXPIRETIME', KEYS[1])
local clock = redis.call('TIME')
if not value or deadline <= 0 or deadline > 9007199254740991 then
    return false
end
return {value, string.format('%.0f', deadline), clock[1], clock[2]}
LUA;

    /** No TTL/marker/tag writes, fallback decoder or cache producer changes. Redis8.6.1 is required. */
    public function readCurrent(int $actualUid, string $confirmationKey): array
    {return $this->read($actualUid,$confirmationKey,false);}

    public function readLegacyCurrent(int $actualUid,string $confirmationKey):array
    {return $this->read($actualUid,$confirmationKey,true);}

    private function read(int $actualUid,string $confirmationKey,bool $legacy):array
    {
        try {
            if (PHP_INT_SIZE < 8 || $actualUid < 1 || $actualUid > 4294967295) self::fail();
            if (MarketplaceOrderInput::confirmationKey($confirmationKey) !== $confirmationKey) self::fail();
            $logical = 'user_order_'.$actualUid.$confirmationKey;
            [$driver,$handler,$options,$name] = $this->store();
            $physical = $driver->getCacheKey($logical);
            if ($physical !== $options['prefix'].$logical || str_contains($physical,"\0")) self::fail();
            $identity = ['actual_uid'=>$actualUid,'confirmation_key'=>$confirmationKey,
                'confirmation_ref'=>hash('sha256',$confirmationKey,true),'logical_key'=>$logical,
                'physical_key'=>$physical,'store_name'=>$name,'driver_class'=>get_class($driver),
                'driver_options_sha256'=>hash('sha256',serialize($options))];
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            try {
                $this->connection($redis,$options);
                $before = $this->atomic($redis,$physical);
                // This is the actual default Think driver and its original PHP unserialize path.
                $payload = $driver->get($logical,null);
                $after = $this->atomic($redis,$physical);
                if ($before['raw'] !== $after['raw'] || $before['deadline_ms'] !== $after['deadline_ms']
                    || !is_array($payload) || !str_starts_with($before['raw'],'a:')) self::fail();
                $this->plain($payload);
                if (serialize($payload) !== $before['raw']) self::fail();
                foreach ($legacy?['cartInfo','priceGroup','other']:['cartInfo','priceGroup','other','store'] as $key)
                    if (!is_array($payload[$key]??null)) self::fail();
                if ($payload['cartInfo'] === []) self::fail();
                if($legacy){
                    if(array_keys($payload)!==['cartInfo','priceGroup','other']||!array_is_list($payload['cartInfo']))self::fail();
                    $frame=self::legacyDefinition($payload['cartInfo'][0],$actualUid);
                }else $frame = MarketplaceCartSelection::frame($payload,$actualUid);
                [$current,$currentHandler,$currentOptions,$currentName] = $this->store();
                if ($current !== $driver || $currentHandler !== $handler || $currentOptions !== $options
                    || $currentName !== $name || $current->getCacheKey($logical) !== $physical) self::fail();
                $this->connection($redis,$options);
                return ['payload'=>$payload,'frame'=>$frame,'raw'=>$after['raw'],
                    'deadline_ms'=>$after['deadline_ms'],'expires_at'=>intdiv($after['deadline_ms'],1000),
                    'redis_time_ms'=>$after['redis_time_ms'],'identity'=>$identity];
            } finally {
                if (@$redis->close() !== true) self::fail();
            }
        } catch (\Throwable $error) { self::fail(); }
    }

    /** Same immutable confirmation and real original deadline; normal Redis TIME observations may differ. */
    public function assertSame(int $actualUid, string $confirmationKey, array $observed): array
    {return $this->same($actualUid,$confirmationKey,$observed,false);}

    public function assertLegacySame(int $actualUid,string $confirmationKey,array $observed):array
    {return $this->same($actualUid,$confirmationKey,$observed,true);}

    private function same(int $actualUid,string $confirmationKey,array $observed,bool $legacy):array
    {
        if (array_keys($observed) !== self::FIELDS || !is_array($observed['identity'])
            || array_keys($observed['identity']) !== self::IDENTITY
            || !is_int($observed['deadline_ms']) || !is_int($observed['expires_at'])
            || !is_int($observed['redis_time_ms']) || $observed['deadline_ms'] <= $observed['redis_time_ms']
            || $observed['expires_at'] !== intdiv($observed['deadline_ms'],1000)) self::fail();
        $current = $legacy?$this->readLegacyCurrent($actualUid,$confirmationKey):$this->readCurrent($actualUid,$confirmationKey);
        foreach (['payload','frame','raw','deadline_ms','expires_at','identity'] as $key)
            if ($current[$key] !== $observed[$key]) self::fail();
        return $current;
    }

    /** Pure legacy first-cart definition; callers supply a real cached or saved cart, never a V1 frame. */
    public static function legacyDefinition(array $firstCart,int $uid):array
    {
        if($uid<1||!in_array($firstCart['uid']??null,[$uid,(string)$uid],true)
            ||!is_array($firstCart['productInfo']??null))self::fail();
        $ref=$firstCart['id']??null;$product=$firstCart['product_id']??null;
        if(is_int($ref))$ref=(string)$ref;
        if(!is_string($ref)||preg_match('/\A[1-9][0-9]{0,29}\z/D',$ref)!==1)self::fail();
        if(is_string($product)&&preg_match('/\A[1-9][0-9]{0,6}\z/D',$product)===1)$product=(int)$product;
        if(!is_int($product)||$product<1||$product>8388607)self::fail();
        $raw=$firstCart['productInfo']['custom_form']??null;
        if($raw===null||$raw==='')$raw=[];
        elseif(is_string($raw)){
            try{$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
        }
        if(!is_array($raw))self::fail();
        $fields=\app\services\marketplace\publiccatalog\PublicCatalogProjection::formConfig($raw);
        $encoded=json_encode(['product_id'=>$product,'fields'=>$fields],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return ['first_cart_ref'=>$ref,'product_id'=>$product,'definition_sha256'=>hash('sha256',$encoded),'fields'=>$fields];
    }

    /** Exact actual framework defaults+configured options, never a guessed c: prefix or replacement client. */
    private function store(): array
    {
        $name = Config::get('cache.default');
        $configured = is_string($name) && $name !== '' ? Config::get('cache.stores.'.$name) : null;
        if (!is_array($configured) || !is_string($configured['type']??null)
            || strcasecmp($configured['type'],'redis') !== 0 || Cache::getDefaultDriver() !== $name) self::fail();
        $driver = Cache::store();
        if (!is_object($driver) || get_class($driver) !== \think\cache\driver\Redis::class
            || $driver !== Cache::store($name)) self::fail();
        $property = new \ReflectionProperty(\think\cache\driver\Redis::class,'options');
        if (!$property->isProtected() || $property->isStatic()
            || $property->getDeclaringClass()->getName() !== \think\cache\driver\Redis::class) self::fail();
        $defaults = (new \ReflectionClass(\think\cache\driver\Redis::class))->getDefaultProperties()['options']??null;
        $options = $property->getValue($driver);
        if (!is_array($defaults) || !is_array($options) || $options !== array_merge($defaults,$configured)
            || ($options['serialize']??null) !== [] || !is_string($options['prefix']??null)
            || str_contains($options['prefix'],"\0") || !is_string($options['host']??null)
            || trim($options['host']) === '' || !is_string($options['tag_prefix']??null)) self::fail();
        $this->port($options['port']??null); $this->database($options['select']??null);
        $handler = $driver->handler();
        if (!$handler instanceof \Redis) self::fail();
        $this->connection($handler,$options);
        return [$driver,$handler,$options,$name];
    }

    /** Introspection only. Never set a shared handler option or issue an extra-key command. */
    private function connection(\Redis $redis, array $options): void
    {
        if ($redis->getHost() !== $options['host'] || $redis->getPort() !== $this->port($options['port'])
            || $redis->getDBNum() !== $this->database($options['select'])
            || $redis->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE
            || $redis->getOption(\Redis::OPT_PREFIX) !== null) self::fail();
    }

    private function atomic(\Redis $redis, string $physical): array
    {
        if (@$redis->clearLastError() !== true) self::fail();
        $row = @$redis->rawCommand('EVAL_RO',self::READ,1,$physical);
        if ($redis->getLastError() !== null || !is_array($row) || array_keys($row) !== [0,1,2,3]
            || !is_string($row[0])) self::fail();
        $deadline = $this->decimal($row[1],9007199254740991);
        $seconds = $this->decimal($row[2],intdiv(PHP_INT_MAX,1000));
        $micros = $this->decimal($row[3],999999);
        if ($seconds > intdiv(PHP_INT_MAX-999,1000)) self::fail();
        $now = $seconds*1000+intdiv($micros,1000);
        if ($deadline <= $now) self::fail();
        return ['raw'=>$row[0],'deadline_ms'=>$deadline,'redis_time_ms'=>$now];
    }

    private function decimal(mixed $value, int $maximum): int
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value) !== 1
            || strlen($value)>strlen((string)$maximum)
            || (strlen($value)===strlen((string)$maximum) && strcmp($value,(string)$maximum)>0)) self::fail();
        return (int)$value;
    }
    private function port(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int)$value<1 || (int)$value>65535) self::fail();
        return (int)$value;
    }
    private function database(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int)$value<0 || (int)$value>2147483647) self::fail();
        return (int)$value;
    }
    private function plain(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) $this->plain($item); return; }
        if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)
            && !(is_float($value) && is_finite($value))) self::fail();
    }
    private static function fail(): never { throw new AuthUnavailableException(); }
}
