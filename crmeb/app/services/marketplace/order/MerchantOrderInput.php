<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use think\exception\HttpException;

/** Closed read-only query input; merchant ownership is never a wire parameter. */
final class MerchantOrderInput
{
    public static function invalid(): never { throw new HttpException(422, '订单参数不正确'); }

    public static function body(array $input,array $allowed): array
    {
        foreach(array_keys($input) as $key)
            if(!is_string($key)||!in_array($key,$allowed,true))self::invalid();
        return $input;
    }

    public static function integer(mixed $value,int $minimum,int $maximum): int
    {
        if(is_string($value)&&strlen($value)<=10&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::invalid();
        return $value;
    }

    public static function id(mixed $value): int { return self::integer($value,1,2147483647); }

    /** @return array{filter:array,page:int,limit:int} */
    public static function listing(array $query): array
    {
        self::body($query,['page','limit','order_id','paid','is_cancel']);
        $page=self::integer($query['page']??1,1,2147483647);
        $limit=self::integer($query['limit']??20,1,100);$filter=[];
        if(array_key_exists('order_id',$query)){
            $number=$query['order_id'];
            if(!is_string($number)||strlen($number)>32||preg_match('//u',$number)!==1
                ||str_contains($number,"\0")||trim($number)!==$number)self::invalid();
            if($number!=='')$filter['order_id']=$number;
        }
        foreach(['paid','is_cancel'] as $key)
            if(array_key_exists($key,$query))$filter[$key]=self::integer($query[$key],0,1);
        return compact('filter','page','limit');
    }
}
