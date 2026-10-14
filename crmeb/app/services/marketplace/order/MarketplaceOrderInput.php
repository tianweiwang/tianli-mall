<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\ApiException;

/** Only wire normalization; identities and prices still come from actual server records. */
final class MarketplaceOrderInput
{
    private const CREATE_FIELDS=['addressId','couponId','payType','useIntegral','mark','combinationId','pinkId',
        'seckill_id','bargainId','shipping_type','real_name','phone','store_id','new','invoice_id','advanceId',
        'custom_form','is_gift','gift_mark','cartId','mer_id','idem_key','is_new',
        'formId','discountId','from','quitUrl'];

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

    /** The caller supplies its verified UID and route key; this method performs no authorization. */
    public static function create(int $uid,string $routeKey,array $rawPost):array
    {
        foreach($rawPost as$field=>$value){
            if(!is_string($field)||!in_array($field,self::CREATE_FIELDS,true))throw new ApiException('订单参数未登记');
        }
        if(self::unsigned($uid,'uid')===0)throw new ApiException('顾客编号格式错误');
        $key=self::text($routeKey,'确认引用',32);
        if($key==='')throw new ApiException('确认引用格式错误');
        $idem=$rawPost['idem_key']??null;
        if(!is_string($idem)||preg_match('/\A[\x20-\x7e]{1,128}\z/D',$idem)!==1||$idem[0]===' '||substr($idem,-1)===' '){
            throw new ApiException('idem_key格式错误');
        }
        $choice=self::confirm($rawPost);
        $invoice=$rawPost['invoice_id']??0;
        if($invoice==='')$invoice=0;
        $options=['shipping_type'=>$choice['shipping_type'],'is_gift'=>$choice['is_gift'],
            'couponId'=>self::unsigned($rawPost['couponId']??0,'couponId'),
            'useIntegral'=>self::boolean($rawPost['useIntegral']??0,'useIntegral'),
            'payType'=>strtolower(self::text($rawPost['payType']??'','payType',32)),
            'mark'=>self::text($rawPost['mark']??'','备注',512),
            'real_name'=>self::text($rawPost['real_name']??'','联系人',32),
            'phone'=>self::text($rawPost['phone']??'','联系电话',18),
            'store_id'=>self::unsigned($rawPost['store_id']??0,'store_id',2147483647),
            'invoice_id'=>self::unsigned($invoice,'invoice_id',2147483647),
            'gift_mark'=>self::text($rawPost['gift_mark']??'','礼物留言',255),
            'custom_form'=>self::form($rawPost['custom_form']??[])];
        foreach(['combinationId','pinkId','seckill_id','bargainId','advanceId']as$field){
            $value=$rawPost[$field]??0;
            if($field==='bargainId'&&$value==='')$value=0;
            $options[$field]=self::unsigned($value,$field);
        }
        // formId/discountId/from/quitUrl never enter the request digest or business options.
        return ['idem_key'=>$idem,'request'=>['contract_version'=>1,'uid'=>$uid,'mer_id'=>$choice['mer_id'],
            'source_kind'=>$choice['source_kind'],'cart_refs'=>$choice['cart_refs'],
            'address_id'=>self::unsigned($rawPost['addressId']??0,'addressId',16777215),
            'confirm_key'=>$key,'options'=>$options]];
    }

    /** Stable bytes for a server-side SHA256; this is neither a price nor an identity check. */
    public static function canonical(array $request):string
    {
        return json_encode(self::canonicalValue($request,0),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR,32);
    }

    private static function text(mixed $value,string $name,?int $max=null):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||str_contains($value,"\0")
            ||($max!==null&&mb_strlen($value,'UTF-8')>$max))throw new ApiException($name.'格式错误');
        return $value;
    }

    private static function form(mixed $value):array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>10)throw new ApiException('自定义表单格式错误');
        $out=[];
        foreach($value as$row){
            if(!is_array($row)||count($row)!==4||array_diff(array_keys($row),['title','label','value','status'])
                ||!array_key_exists('status',$row)||!is_bool($row['status']))throw new ApiException('自定义表单格式错误');
            $title=self::text($row['title']??null,'表单标题');
            $label=self::text($row['label']??null,'表单类型');
            if(!in_array($label,['text','number','email','data','time','id','phone','img'],true))throw new ApiException('自定义表单类型错误');
            if($label==='img'){
                $images=$row['value'];
                if(!is_array($images)||!array_is_list($images))throw new ApiException('表单图片格式错误');
                $input=[];foreach($images as$image)$input[]=self::text($image,'表单图片');
            }else $input=self::text($row['value'],'表单内容');
            $out[]=['title'=>$title,'label'=>$label,'value'=>$input,'status'=>$row['status']];
        }
        return $out;
    }

    private static function canonicalValue(mixed $value,int $depth):mixed
    {
        if($depth>24)throw new ApiException('订单内容层级错误');
        if(is_array($value)){
            if(array_is_list($value)){
                $out=[];foreach($value as$item)$out[]=self::canonicalValue($item,$depth+1);return $out;
            }
            foreach(array_keys($value)as$key)if(!is_string($key))throw new ApiException('订单内容字段错误');
            ksort($value,SORT_STRING);
            foreach($value as$key=>$item){self::text($key,'订单内容字段');$value[$key]=self::canonicalValue($item,$depth+1);}
            return $value;
        }
        if(is_string($value))return self::text($value,'订单内容');
        if(is_int($value)||is_bool($value)||$value===null)return $value;
        throw new ApiException('订单内容类型错误');
    }
}
