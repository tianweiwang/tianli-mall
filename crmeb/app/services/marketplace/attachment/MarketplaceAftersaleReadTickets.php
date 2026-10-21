<?php
declare(strict_types=1);

namespace app\services\marketplace\attachment;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\BoundedRedisConnection;
use Redis;
use stdClass;
use think\facade\Config;
use Throwable;

/** Server-only ticket storage. A binding is actual authenticated/authorized service data, never a credential. */
final class MarketplaceAftersaleReadTickets
{
    private const UINT_MAX=4294967295;
    private const BINDING=['app','actor_type','actor_id','session_sha256','uid','mer_id','root_order_id','refund_id','file_id','content_sha256'];
    private const PAYLOAD=['schema','app','actor_type','actor_id','session_sha256','uid','mer_id','root_order_id','refund_id',
        'file_id','content_sha256','issued_at','expires_at','purpose'];
    private const CREATE=<<<'LUA'
if redis.call('EXISTS',KEYS[1]) ~= 0 then return {0,'',-2} end
local created=redis.call('SET',KEYS[1],ARGV[1],'NX','EX',300)
if not created then return {0,'',-2} end
return {1,redis.call('GET',KEYS[1]),redis.call('TTL',KEYS[1])}
LUA;
    private const PEEK=<<<'LUA'
local raw=redis.call('GET',KEYS[1])
local ttl=redis.call('TTL',KEYS[1])
if not raw then return {0,'',ttl} end
return {1,raw,ttl}
LUA;
    private const CONSUME=<<<'LUA'
local raw=redis.call('GET',KEYS[1])
if raw == ARGV[1] and redis.call('TTL',KEYS[1]) > 0 then return redis.call('DEL',KEYS[1]) end
return 0
LUA;

    /** Only the opaque token and its two real times are returned, never a session hash/cache payload. */
    public function issue(array $serverBinding): array
    {
        self::binding($serverBinding); $now=self::now();
        if($now>self::UINT_MAX-300) self::unavailable();
        $ticket=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); self::ticket($ticket);
        $payload=['schema'=>1]+$serverBinding+['issued_at'=>$now,'expires_at'=>$now+300,'purpose'=>'aftersale_file_read'];
        self::payload($payload,$serverBinding); $raw=MarketplaceOrderInput::canonical($payload);
        [$redis,$prefix]=$this->connection();
        try{
            $reply=$redis->eval(self::CREATE,[$prefix.'mp_aftersale_read:'.hash('sha256',$ticket),$raw],1);
            if(!is_array($reply)||array_keys($reply)!==[0,1,2]||$reply[0]!==1||$reply[1]!==$raw||$reply[2]!==300
                ||$redis->getLastError()!==null) self::unavailable();
        }catch(Throwable $error){self::unavailable();}
        finally{if(@$redis->close()!==true) self::unavailable();}
        if(self::now()>=$payload['expires_at']) self::unavailable();
        return ['read_ticket'=>$ticket,'issued_at'=>$now,'expires_at'=>$now+300];
    }

    /** A mismatch does not delete or otherwise consume another request's ticket. */
    public function peek(string $ticket,array $serverBinding): array
    {
        self::ticket($ticket); self::binding($serverBinding); [$redis,$prefix]=$this->connection();
        try{
            $reply=$redis->eval(self::PEEK,[$prefix.'mp_aftersale_read:'.hash('sha256',$ticket)],1);
            if(!is_array($reply)||array_keys($reply)!==[0,1,2]||$reply[0]!==1||!is_string($reply[1])
                ||!is_int($reply[2])||$reply[2]<1||$reply[2]>300||$redis->getLastError()!==null) self::unavailable();
            $payload=self::decode($reply[1]); self::payload($payload,$serverBinding);
            return ['cache_key_sha256'=>hash('sha256',$ticket),'raw'=>$reply[1]];
        }catch(Throwable $error){self::unavailable();}
        finally{if(@$redis->close()!==true) self::unavailable();}
    }

    /** Call only after the real owned DB commit/last gate and full private-byte read have succeeded. */
    public function consume(string $ticket,array $serverBinding,array $rawReceipt): void
    {
        self::ticket($ticket); self::binding($serverBinding); self::keys($rawReceipt,['cache_key_sha256','raw']);
        $digest=hash('sha256',$ticket);
        if($rawReceipt['cache_key_sha256']!==$digest||!is_string($rawReceipt['raw'])) self::unavailable();
        $payload=self::decode($rawReceipt['raw']); self::payload($payload,$serverBinding);
        [$redis,$prefix]=$this->connection();
        try{
            if($redis->eval(self::CONSUME,[$prefix.'mp_aftersale_read:'.$digest,$rawReceipt['raw']],1)!==1
                ||$redis->getLastError()!==null) self::unavailable();
        }catch(Throwable $error){self::unavailable();}
        finally{if(@$redis->close()!==true) self::unavailable();}
        // A consumed or uncertain ticket is never restored, including a response lost at this boundary.
        if(self::now()>=$payload['expires_at']) self::unavailable();
    }

    private function connection(): array
    {
        $redis=BoundedRedisConnection::fromDefaultCacheConfig();
        try{
            $name=Config::get('cache.default'); $options=is_string($name)?Config::get('cache.stores.'.$name):null;
            if(!is_array($options)||!is_string($options['type']??null)||strcasecmp($options['type'],'redis')!==0
                ||!is_string($options['prefix']??null)||str_contains($options['prefix'],"\0")
                ||@$redis->setOption(Redis::OPT_SERIALIZER,Redis::SERIALIZER_NONE)!==true
                ||@$redis->setOption(Redis::OPT_PREFIX,'')!==true
                ||$redis->getOption(Redis::OPT_SERIALIZER)!==Redis::SERIALIZER_NONE
                ||$redis->getOption(Redis::OPT_PREFIX)!==null||$redis->getLastError()!==null) self::unavailable();
            return [$redis,$options['prefix']];
        }catch(Throwable $error){try{$redis->close();}catch(Throwable $ignored){} self::unavailable();}
    }

    private static function binding(array $value): void
    {
        self::keys($value,self::BINDING);
        $actors=['api'=>'customer','merchantapi'=>'employee','adminapi'=>'admin'];
        if(!is_string($value['app'])||!isset($actors[$value['app']])||$value['actor_type']!==$actors[$value['app']]) self::unavailable();
        foreach(['uid','root_order_id'] as $key) self::integer($value[$key],1,2147483647);
        foreach(['mer_id','actor_id'] as $key) self::integer($value[$key],1,self::UINT_MAX);
        if($value['actor_type']==='customer'&&$value['actor_id']!==$value['uid']) self::unavailable();
        if($value['refund_id']!==null) self::integer($value['refund_id'],1,2147483647);
        self::fileId($value['file_id']);
        foreach(['session_sha256','content_sha256'] as $key)
            if(!is_string($value[$key])||preg_match('/\A[a-f0-9]{64}\z/D',$value[$key])!==1) self::unavailable();
    }
    private static function payload(array $value,array $binding): void
    {
        self::keys($value,self::PAYLOAD); self::binding($binding);
        foreach(self::BINDING as $key) if($value[$key]!==$binding[$key]) self::unavailable();
        if($value['schema']!==1||$value['purpose']!=='aftersale_file_read') self::unavailable();
        $issued=self::integer($value['issued_at'],1,self::UINT_MAX); $expires=self::integer($value['expires_at'],1,self::UINT_MAX);
        $now=self::now();
        if($issued>self::UINT_MAX-300||$expires!==$issued+300||$issued>$now||$expires<=$now) self::unavailable();
    }
    private static function decode(string $raw): array
    {
        if(strlen($raw)>4096||preg_match('//u',$raw)!==1) self::unavailable();
        try{$value=json_decode($raw,false,8,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
        if(!$value instanceof stdClass) self::unavailable(); $value=get_object_vars($value);
        self::keys($value,self::PAYLOAD);
        if(MarketplaceOrderInput::canonical($value)!==$raw) self::unavailable(); return $value;
    }
    private static function ticket(string $value): void
    {
        if(preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$value)!==1) self::unavailable();
        $raw=base64_decode(strtr($value,'-_','+/').'=',true);
        if(!is_string($raw)||strlen($raw)!==32||rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$value) self::unavailable();
    }
    private static function fileId($value): void
    {
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||strlen($value)>20||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0)) self::unavailable();
    }
    private static function integer($value,int $minimum,int $maximum): int
    {
        if(!is_int($value)||$value<$minimum||$value>$maximum) self::unavailable(); return $value;
    }
    private static function now(): int {return self::integer(time(),1,self::UINT_MAX);}
    private static function keys(array $value,array $expected): void
    {
        $keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected) self::unavailable();
    }
    private static function unavailable(): never {throw new AuthUnavailableException();}
}
