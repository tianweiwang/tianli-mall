<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\AuthUnavailableException;
use stdClass;
use Throwable;

/** Request-only values. Ownership, payment, receipt and public attachment existence are not input claims. */
final class MarketplaceOrderReviewInput
{
    public static function decode(string $raw):array
    {
        if($raw===''||strlen($raw)>65536||preg_match('//u',$raw)!==1)self::fail();
        try{$value=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
        if(!$value instanceof stdClass)self::fail();$out=get_object_vars($value);
        // Keep the original JSON type of nested pics. A JSON object is never a list.
        return $out;
    }
    public static function id(mixed $value):int {return self::number($value,1,2147483647);}
    public static function create(array $raw):array
    {
        self::keys($raw,['schema','product_score','service_score','comment','pics']);
        if($raw['schema']!==1||!is_int($raw['product_score'])||!is_int($raw['service_score']))self::fail();
        if($raw['product_score']<1||$raw['product_score']>5||$raw['service_score']<1||$raw['service_score']>5)self::fail();
        return ['schema'=>1,'product_score'=>$raw['product_score'],'service_score'=>$raw['service_score'],
            'comment'=>self::text($raw['comment'],0,512),'pics'=>self::pictures($raw['pics'])];
    }
    public static function reply(array $raw):array
    {
        self::keys($raw,['schema','content']);if($raw['schema']!==1)self::fail();
        return ['schema'=>1,'content'=>self::text($raw['content'],1,300)];
    }
    public static function moderate(array $raw):array
    {
        self::keys($raw,['schema','status']);if($raw['schema']!==1||!in_array($raw['status'],[1,2],true))self::fail();
        return ['schema'=>1,'status'=>$raw['status']];
    }
    public static function merchantPage(array $query):array {return self::page($query,false);}
    public static function platformPage(array $query):array {return self::page($query,true);}
    public static function pictures(mixed $value):array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>8)self::fail();$out=[];
        foreach($value as$reference){
            $reference=self::text($reference,1,200);
            if(preg_match('/[\p{Cc}\p{Z}\s\\\\]/u',$reference)===1)self::fail();
            $parts=parse_url($reference);if(!is_array($parts))self::fail();
            if(str_starts_with($reference,'/uploads/')){
                if(array_keys($parts)!==['path'])self::fail();
            }else{
                if(!in_array($parts['scheme']??null,['http','https'],true)||!is_string($parts['host']??null)||$parts['host']===''
                    ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))self::fail();
            }
            $path=$parts['path']??'';
            for($i=0;$i<3;$i++){
                if(preg_match('/[\p{Cc}\p{Z}\s\\\\]/u',$path)===1)self::fail();
                foreach(explode('/',$path)as$segment)if($segment==='.'||$segment==='..')self::fail();
                $decoded=rawurldecode($path);if($decoded===$path)break;$path=$decoded;
            }
            $out[]=$reference;
        }
        try{$encoded=json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
        if(strlen($encoded)>65535)self::fail();return $out;
    }
    private static function page(array $query,bool $platform):array
    {
        $allowed=['page','limit','status','is_reply','product_id'];if($platform)$allowed[]='mer_id';
        foreach(array_keys($query)as$key)if(!is_string($key)||!in_array($key,$allowed,true))self::fail();
        $out=['page'=>self::number($query['page']??1,1,2147483647),'limit'=>self::number($query['limit']??20,1,100)];
        foreach(['status'=>[0,2],'is_reply'=>[0,1],'product_id'=>[1,2147483647],'mer_id'=>[1,4294967295]]as$key=>$bounds){
            if(array_key_exists($key,$query))$out[$key]=self::number($query[$key],$bounds[0],$bounds[1]);
        }
        if($out['page']-1>intdiv(PHP_INT_MAX,$out['limit']))self::fail();return $out;
    }
    private static function number(mixed $value,int $min,int $max):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=10
            &&(strlen($value)<strlen((string)$max)||strcmp($value,(string)$max)<=0))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    private static function text(mixed $value,int $min,int $max):string
    {
        if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1
            ||mb_strlen($value,'UTF-8')<$min||mb_strlen($value,'UTF-8')>$max)self::fail();return $value;
    }
    private static function keys(array $value,array $expected):void
    {
        $keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected)self::fail();
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
