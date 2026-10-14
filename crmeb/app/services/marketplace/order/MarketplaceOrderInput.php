<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\ApiException;

/** Only wire normalization; identities and prices still come from actual server records. */
final class MarketplaceOrderInput
{
    public static function unsigned(mixed $value,string $name,int $max=4294967295):int
    {
        if(is_int($value)&&$value>=0&&$value<=$max)return $value;
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1){
            $bound=(string)$max;
            if(strlen($value)<strlen($bound)||(strlen($value)===strlen($bound)&&strcmp($value,$bound)<=0))return (int)$value;
        }
        throw new ApiException($name.'格式错误');
    }

    public static function boolean(mixed $value,string $name):bool
    {
        if($value===true||$value===1||$value==='1')return true;
        if($value===false||$value===0||$value==='0')return false;
        throw new ApiException($name.'格式错误');
    }

    public static function reference(mixed $value,string $sourceKind):string
    {
        if($sourceKind==='persistent'){
            if(is_int($value)&&$value>0)$value=(string)$value;
            if(!is_string($value)||preg_match('/\A[1-9][0-9]*\z/D',$value)!==1)throw new ApiException('购物车编号格式错误');
            $bound='18446744073709551615';
            if(strlen($value)>strlen($bound)||(strlen($value)===strlen($bound)&&strcmp($value,$bound)>0))throw new ApiException('购物车编号格式错误');
        }elseif($sourceKind==='temporary'){
            if(!is_string($value)||$value===''||strlen($value)>50||str_contains($value,',')||str_contains($value,"\0"))throw new ApiException('临时购物车引用格式错误');
        }else throw new ApiException('购物车来源错误');
        return $value;
    }

    public static function references(mixed $value,string $sourceKind):array
    {
        if(!is_string($value)||$value==='')throw new ApiException('请提交购买的商品');
        $refs=[];
        foreach(explode(',',$value)as$ref)$refs[]=self::reference($ref,$sourceKind);
        $refs=array_values(array_unique($refs,SORT_STRING));
        usort($refs,$sourceKind==='persistent'
            ?static fn(string $a,string $b):int=>strlen($a)<=>strlen($b)?:strcmp($a,$b)
            :static fn(string $a,string $b):int=>strcmp($a,$b));
        return $refs;
    }

    public static function confirm(array $raw):array
    {
        $newFlag=self::boolean($raw['new']??0,'new');$isNewFlag=self::boolean($raw['is_new']??0,'is_new');$new=$newFlag||$isNewFlag;
        $kind=$new?'temporary':'persistent';$refs=self::references($raw['cartId']??null,$kind);
        $owner=self::unsigned($raw['mer_id']??0,'mer_id');
        if($owner===0)throw new ApiException('请选择结算店铺');
        return ['mer_id'=>$owner,'source_kind'=>$kind,'cart_refs'=>$refs,'cartId'=>implode(',',$refs),'new'=>$new,
            'addressId'=>self::unsigned($raw['addressId']??0,'addressId'),
            'shipping_type'=>self::unsigned($raw['shipping_type']??1,'shipping_type',2),
            'is_gift'=>self::unsigned($raw['is_gift']??0,'is_gift',2)];
    }

    public static function computed(array $raw):array
    {
        $payType=$raw['payType']??'';
        if(!is_string($payType))throw new ApiException('payType格式错误');
        return ['addressId'=>self::unsigned($raw['addressId']??0,'addressId'),
            'shipping_type'=>self::unsigned($raw['shipping_type']??1,'shipping_type',2),
            'is_gift'=>self::unsigned($raw['is_gift']??0,'is_gift',2),'payType'=>strtolower($payType)];
    }
}
