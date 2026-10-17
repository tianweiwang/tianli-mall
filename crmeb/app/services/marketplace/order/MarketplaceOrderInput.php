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

    /** Explicit preview hints only; no client identity, selection or default-record fallback. */
    public static function restore(array $raw):array
    {
        $fields=array_keys($raw);sort($fields,SORT_STRING);
        if($fields!==['addressId','invoice_id','payType'])throw new ApiException('恢复确认参数不完整或未登记');
        return ['addressId'=>self::unsigned($raw['addressId'],'addressId',16777215),
            'invoice_id'=>self::unsigned($raw['invoice_id'],'invoice_id',2147483647),
            'payType'=>strtolower(self::text($raw['payType'],'payType',32))];
    }

    /** The caller supplies its verified UID and route key; this method performs no authorization. */
    public static function create(int $uid,string $routeKey,array $rawPost):array
    {
        foreach($rawPost as$field=>$value){
            if(!is_string($field)||!in_array($field,self::CREATE_FIELDS,true))throw new ApiException('订单参数未登记');
        }
        if(self::unsigned($uid,'uid')===0)throw new ApiException('顾客编号格式错误');
        $key=self::confirmationKey($routeKey);
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
            'custom_form'=>MarketplaceOrderCustomForm::answers($rawPost['custom_form']??[])];
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

    /** Same actual server-key wire boundary for create and the owner-scoped result lookup. */
    public static function confirmationKey(string $key):string
    {
        $key=self::text($key,'确认引用',32);
        if($key==='')throw new ApiException('确认引用格式错误');
        return $key;
    }


    /** Fixed customer GET-list values after the existing controller's getMore aliases. */
    public static function customerRead(array $query):array
    {
        self::readKeys($query,['status','real_name','refundTypes']);
        return self::readFilter($query,'customer');
    }

    public static function platformRead(array $query):array
    {
        self::readKeys($query,['status','real_name','is_del','time','type','pay_type','order','field_key']);
        return self::readFilter($query,'platform');
    }

    public static function mobileRead(array $query):array
    {
        self::readKeys($query,['status','is_del','time','type','field_key','field_value','real_name','pay_type']);
        return self::readFilter($query,'mobile');
    }

    /** Callers retain getPageValue's real configuration cap; zero in either slot means the old whole selection. */
    public static function readPage(int $page,int $limit):array
    {
        if(PHP_INT_SIZE<8)self::readFailure();$limit=min($limit,100);
        if($page===0||$limit===0)return ['mode'=>'all','offset'=>0,'limit'=>100];
        if($limit<0)throw new ApiException('订单分页格式错误');
        $page=max(1,$page);
        if($page-1>intdiv(PHP_INT_MAX,$limit))throw new ApiException('订单分页格式错误');
        return ['mode'=>'page','offset'=>($page-1)*$limit,'limit'=>$limit];
    }

    /** This is only the eight-key internal value contract, never authorization or arbitrary predicates. */
    public static function assertReadFilter(array $value):void
    {
        self::readKeys($value,['status_case','legacy_pid','deleted','time','activity','payment','keyword','refund_types']);
        $status=$value['status_case'];self::readObject($status,['code','shipping_only']);
        if(!in_array($status['code'],['none',0,1,2,3,4,5,6,7,9,-1,-2,-3,-4],true)
            ||!is_bool($status['shipping_only'])||($status['shipping_only']&&$status['code']!==1))self::readFailure();
        if(!in_array($value['legacy_pid'],['none','nonnegative','not_minus_one'],true))self::readFailure();
        $deleted=$value['deleted'];self::readObject($deleted,['enabled','value']);
        if(!is_bool($deleted['enabled']))self::readFailure();self::readScalar($deleted['value']);
        if(!$deleted['enabled']&&$deleted['value']!==null)self::readFailure();
        $time=$value['time'];self::readObject($time,['kind','start','end']);
        if(!in_array($time['kind'],['none','between','before','from'],true))self::readFailure();
        foreach(['start','end']as$key)if($time[$key]!==null&&!is_int($time[$key]))self::readFailure();
        if(($time['kind']==='none'&&($time['start']!==null||$time['end']!==null))
            ||($time['kind']==='between'&&(!is_int($time['start'])||!is_int($time['end'])))
            ||($time['kind']==='before'&&($time['start']!==null||!is_int($time['end'])))
            ||($time['kind']==='from'&&(!is_int($time['start'])||$time['end']!==null)))self::readFailure();
        if(!in_array($value['activity'],['none',1,2,3,4,5,6],true)
            ||!in_array($value['refund_types'],['none',1,2,3,4],true))self::readFailure();
        $payment=$value['payment'];self::readObject($payment,['equalities','in_values']);
        if(!is_array($payment['equalities'])||!array_is_list($payment['equalities'])||count($payment['equalities'])>2)self::readFailure();
        foreach($payment['equalities']as$item){if($item!==null&&!is_string($item))self::readFailure();if(is_string($item))self::text($item,'支付筛选');}
        if($payment['in_values']!==null){
            if(!is_array($payment['in_values'])||!array_is_list($payment['in_values'])||$payment['equalities']!==[])self::readFailure();
            foreach($payment['in_values']as$item){if($item!==null&&!is_string($item))self::readFailure();if(is_string($item))self::text($item,'支付筛选');}
        }
        $keyword=$value['keyword'];self::readObject($keyword,['mode','value']);
        if(!in_array($keyword['mode'],['none','fuzzy','title','uid','order_id','real_name','user_phone'],true)
            ||!is_string($keyword['value']))self::readFailure();
        self::text($keyword['value'],'订单搜索');
        if($keyword['mode']==='none'&&$keyword['value']!=='')self::readFailure();
    }

    private static function readFilter(array $query,string $kind):array
    {
        if(PHP_INT_SIZE<8)self::readFailure();foreach($query as$key=>$value)if(!in_array($key,['time','pay_type'],true))self::readScalar($value);
        $original=$query['status'];$status=$original??'';$code='none';
        if($status!==''){
            $cast=(int)$status;
            if(in_array($cast,[0,1,2,3,4,5,6,7,9,-1,-2,-3,-4],true))$code=$cast;
        }
        $pid='none';
        if($kind==='platform'||($kind==='mobile'&&!in_array($original,[-1,-2,-3])))$pid='nonnegative';
        elseif($kind==='customer'){
            if(in_array($original,[-1,-2,-3]))$pid='not_minus_one';
            elseif(in_array($original,[0,1,2,3,4,9]))$pid='nonnegative';
        }
        $deleted=$kind==='customer'?0:$query['is_del'];$enabled=false;
        if($deleted!=''||(isset($deleted)&&$deleted!==''&&$deleted!=-1))$enabled=true;
        $type=$query['type']??'';$activity='none';
        switch($type){case 1:$activity=1;break;case 2:$activity=2;break;case 3:$activity=3;break;
            case 4:$activity=4;break;case 5:$activity=5;break;case 6:$activity=6;break;}
        $payment=['equalities'=>[],'in_values'=>null];
        if($kind!=='customer'){
            $pay=$query['pay_type'];
            if(is_array($pay)){
                if(!array_is_list($pay))self::readFailure();
                foreach($pay as&$item){self::readScalar($item);if($item!==null)$item=(string)$item;}unset($item);
                $payment['in_values']=$pay;
            }else{
                self::readScalar($pay);$map=[1=>'weixin',2=>'yue',3=>'offline',4=>'alipay'];
                if($pay!==''){
                    $mapped=in_array($pay,array_keys($map))?($map[$pay]??''):'';
                    $payment['equalities'][]=$mapped!==''?$mapped:($pay===null?null:(string)$pay);
                }
                if(isset($pay)){
                    $own=null;
                    switch($pay){case 1:$own='weixin';break;case 2:$own='yue';break;
                        case 3:$own='offline';break;case 4:$own='alipay';break;}
                    if($own!==null&&!in_array($own,$payment['equalities'],true))$payment['equalities'][]=$own;
                }
            }
        }
        $search=$query['real_name'];$field=$query['field_key']??'';$field=$field=='all'?'':$field;
        $keyword=['mode'=>'none','value'=>''];
        if($search){
            if(!$field)$keyword=['mode'=>'fuzzy','value'=>(string)$search];
            elseif(in_array($field,['uid','order_id','real_name','user_phone','title'])){
                $mode=(string)$field;$keyword=['mode'=>$mode,'value'=>$mode==='title'?(string)$search:trim((string)$search)];
            }
        }
        $refund='none';
        if($kind==='customer'&&isset($query['refundTypes'])&&$query['refundTypes']!=''){
            $cast=(int)$query['refundTypes'];if(in_array($cast,[1,2,3,4],true))$refund=$cast;
        }
        $out=['status_case'=>['code'=>$code,'shipping_only'=>$kind==='platform'&&$original==1],
            'legacy_pid'=>$pid,'deleted'=>['enabled'=>$enabled,'value'=>$enabled?$deleted:null],
            'time'=>self::readTime($query['time']??''),'activity'=>$activity,'payment'=>$payment,'keyword'=>$keyword,
            'refund_types'=>$refund];
        self::assertReadFilter($out);return $out;
    }

    /** ModelTrait's finite add_time cases, evaluated once before the caller owns its native snapshot. */
    private static function readTime(mixed $value):array
    {
        $none=['kind'=>'none','start'=>null,'end'=>null];if(!$value)return $none;$now=time();
        if(is_array($value)){
            if(!array_is_list($value)||count($value)>2)self::readFailure();
            $start=self::readEpoch($value[0]??0);$end=self::readEpoch($value[1]??0);
            if(!$start&&!$end)return $none;
            if($start===$end||$end===strtotime(date('Y-m-d',$end)))$end=self::readTimeAdd($end,86399);
            return ['kind'=>'between','start'=>$start,'end'=>$end];
        }
        self::readScalar($value);if(!is_string($value))return $none;
        $rules=['today'=>['today','tomorrow -1second'],'yesterday'=>['yesterday','today -1second'],
            'week'=>['this week 00:00:00','next week 00:00:00 -1second'],
            'last week'=>['last week 00:00:00','this week 00:00:00 -1second'],
            'month'=>['first Day of this month 00:00:00','first Day of next month 00:00:00 -1second'],
            'last month'=>['first Day of last month 00:00:00','first Day of this month 00:00:00 -1second'],
            'year'=>['this year 1/1','next year 1/1 -1second'],'last year'=>['last year 1/1','this year 1/1 -1second']];
        if(isset($rules[$value])){
            $start=strtotime($rules[$value][0],$now);$end=strtotime($rules[$value][1],$now);
            if(!is_int($start)||!is_int($end))self::readFailure();
            return ['kind'=>'between','start'=>$start,'end'=>$end];
        }
        if($value==='quarter'){
            $season=(int)ceil((int)date('n',$now)/3);$year=(int)date('Y',$now);
            $first=date('Y-m-01',mktime(0,0,0,($season-1)*3+1,1,$year));
            $last=date('Y-m-t',mktime(0,0,0,$season*3,1,$year));
            $start=strtotime($first,$now);$end=strtotime($last,$now);
            if(!is_int($start)||!is_int($end))self::readFailure();
            return ['kind'=>'between','start'=>$start,'end'=>$end];
        }
        if($value==='lately7'||$value==='lately30'){
            $start=strtotime($value==='lately7'?'-7 day':'-30 day',$now);
            if(!is_int($start))self::readFailure();return ['kind'=>'between','start'=>$start,'end'=>$now];
        }
        if(!str_contains($value,'-'))return $none;
        $parts=explode('-',$value);$left=trim($parts[0]);$right=trim($parts[1]);
        $start=$left!==''?(strtotime($left,$now)?:0):0;$end=$right!==''?(strtotime($right,$now)?:0):0;
        if($start&&$end){
            if($start===$end||$end===strtotime(date('Y-m-d',$end)))$end=self::readTimeAdd($end,86399);
            return ['kind'=>'between','start'=>$start,'end'=>$end];
        }
        if(!$start&&$end)return ['kind'=>'before','start'=>null,'end'=>self::readTimeAdd($end,86400)];
        if($start&&!$end)return ['kind'=>'from','start'=>$start,'end'=>null];
        return $none;
    }

    private static function readEpoch(mixed $value):int
    {
        if(is_int($value))return $value;
        if(!is_string($value)||preg_match('/\A-?[0-9]+\z/D',$value)!==1)self::readFailure();
        $negative=str_starts_with($value,'-');$digits=ltrim($negative?substr($value,1):$value,'0');if($digits==='')return 0;
        $bound=$negative?'9223372036854775808':(string)PHP_INT_MAX;
        if(strlen($digits)>strlen($bound)||(strlen($digits)===strlen($bound)&&strcmp($digits,$bound)>0))self::readFailure();
        return (int)(($negative?'-':'').$digits);
    }

    private static function readTimeAdd(int $value,int $seconds):int
    {
        if($value>PHP_INT_MAX-$seconds)self::readFailure();return $value+$seconds;
    }

    private static function readScalar(mixed $value):void
    {
        if(is_string($value)){self::text($value,'订单筛选');return;}
        if(!is_int($value)&&$value!==null)self::readFailure();
    }

    private static function readKeys(array $value,array $expected):void
    {
        $actual=array_keys($value);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)self::readFailure();
    }

    private static function readObject(mixed $value,array $expected):void
    {
        if(!is_array($value))self::readFailure();self::readKeys($value,$expected);
    }

    private static function readFailure():never { throw new ApiException('订单筛选格式错误'); }

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
