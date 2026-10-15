<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\AuthUnavailableException;

/** Pure saved V1 customer DTOs. Authorization and saved-row queries belong to the caller. No current catalog lookup. */
final class MarketplaceOrderSnapshotProjection
{
    private const HEADER = ['id','order_id','store_order_id','store_order_sn','mer_id','pid','type','status','paid','pay_type',
        'shipping_type','delivery_type','delivery_id','delivery_name','delivery_code','real_name','user_phone','user_address',
        'mark','remark','gift_mark','trade_no','add_time','pay_time','_pay_time','_add_time','add_time_y','add_time_h',
        'is_cancel','is_del','is_gift','virtual_type','fictitious_content','verify_code','_verify_code','code','status_pic',
        'is_apply_refund','is_refund_available','order_shipping_open','refund_status','refund_type','refund_explain','refund_reason',
        'refuse_reason','refund_express','refund_express_name','refund_num','total_num','refund_total_num',
        'seckill_id','combination_id','bargain_id','pink_id','discount_id','advance_id','use_integral',
        'routine_contact_type','invoice_func','special_invoice','pay_weixin_open','ali_pay_status','pay_allin_open',
        'friend_pay_status','yue_pay_status','offlinePayStatus','nickname','avatar',
        'merchant_name_snapshot','merchant_after_sale_contact_snapshot'];
    private const MONEY = ['total_price','pay_price','pay_postage','total_postage','refund_price','refund_pay_price',
        'coupon_price','deduction_price','integral_price','gift_price','vip_true_price','levelPrice','memberPrice','postage_price','member_price'];
    private const CART = ['type','product_id','product_attr_unique','cart_num','unique','surplus_num','refund_num','surplus_refund_num',
        'is_reply','is_valid','price_type'];
    private const CART_MONEY = ['truePrice','vip_truePrice','vip_sum_truePrice','sum_price','sum_true_price','postage_price','origin_postage_price'];
    private const PRODUCT = ['id','mer_id','store_name','image','unit_name','is_vip','is_virtual','virtual_type','spec_type','store_mention'];
    private const SKU = ['id','product_id','type','unique','suk','image'];
    private const PRODUCT_MONEY = ['price','ot_price','vip_price'];
    private const STATUS = ['_type','_title','_msg','_class','_payType','refund_name','refund_phone','refund_address'];
    private const INVOICE = ['id','invoice_id','category','is_invoice','is_pay','header_type','type','name','duty_number',
        'drawer_phone','email','tell','address','bank','card_number','remark','invoice_number','invoice_time','invoice_image','add_time'];

    /** Legacy/null version is returned exactly as supplied. V1 is a fresh allowlisted array. */
    public static function customerOrder(array $saved):array
    {
        if (MarketplaceTradePolicy::savedOrderIsLegacy($saved)) return $saved;
        $out = self::scalars($saved,self::HEADER);
        foreach (self::MONEY as $key) if (array_key_exists($key,$saved)) $out[$key]=self::money($saved[$key]);
        $out['marketplace_trade_version']=1;
        foreach (['cartInfo','refund_cartInfo','cart_info'] as $key) if (array_key_exists($key,$saved)) $out[$key]=self::carts($saved[$key]);
        $out['custom_form']=self::customForms($saved['custom_form'] ?? []);
        if (isset($saved['_status'])) $out['_status']=self::scalars(self::row($saved['_status']),self::STATUS);
        if (array_key_exists('invoice',$saved)) {
            $invoice=$saved['invoice'];
            if ($invoice===null || $invoice===false || $invoice===[]) $out['invoice']=$invoice;
            else {
                $out['invoice']=self::scalars(self::row($invoice),self::INVOICE);
                foreach (['pay_price','invoice_price'] as $key) if (array_key_exists($key,$invoice)) $out['invoice'][$key]=self::money($invoice[$key]);
            }
        }
        if (isset($saved['help_info'])) $out['help_info']=self::scalars(self::row($saved['help_info']),['help_status','pay_nickname','pay_avatar']);
        if (array_key_exists('system_store',$saved)) $out['system_store']=$saved['system_store']===false ? false :
            self::scalars(self::row($saved['system_store']),['id','name','phone','address','detailed_address','image','latitude','longitude','day_time']);
        if (array_key_exists('refund_img',$saved)) $out['refund_img']=self::images($saved['refund_img']==='' ? [] : $saved['refund_img']);
        if (array_key_exists('express_list',$saved)) {
            $out['express_list']=[];foreach(self::list($saved['express_list']) as $company)
                $out['express_list'][]=self::scalars(self::row($company),['id','name','code','logo']);
        }
        if (array_key_exists('split',$saved)) {
            $out['split']=[];
            foreach(self::list($saved['split']) as $child){
                $child=self::row($child);
                if(MarketplaceTradePolicy::savedOrderIsLegacy($child))self::unavailable();
                $out['split'][]=self::customerOrder($child);
            }
        }
        return $out;
    }

    /** Parent order is already checked against the same actual customer as the refund. */
    public static function customerRefund(array $refund,array $parent):array
    {
        if (MarketplaceTradePolicy::savedOrderIsLegacy($parent)) return $refund;
        $cartInfo=self::list($refund['cart_info'] ?? []); $products=[];
        foreach($cartInfo as $cart){$cart=self::row($cart);$product=self::row($cart['productInfo'] ?? null);
            $id=self::productId($product['id'] ?? null);
            if(isset($cart['product_id']) && self::productId($cart['product_id'])!==$id)self::unavailable();
            $products[$id]=true;
        }
        $refund['marketplace_trade_version']=1;
        $refund['custom_form']=self::customForms($parent['custom_form'] ?? [],array_keys($products));
        return self::customerOrder($refund);
    }

    /** Display saved answers; hash is an opaque original-definition marker, NEVER rehashed over filled values. */
    public static function customForms(mixed $raw,?array $productIds=null):array
    {
        if(is_string($raw)){
            if(strlen($raw)>65535)self::unavailable();
            try{$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $error){self::unavailable();}
        }
        $groups=self::list($raw);if(count($groups)>500)self::unavailable();$out=[];$seen=[];
        foreach($groups as $group){
            $group=self::row($group);$id=self::productId($group['product_id'] ?? null);
            $hash=$group['definition_sha256'] ?? null;
            if(isset($seen[$id]) || !is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D',$hash)!==1)self::unavailable();
            $seen[$id]=true;$fields=self::list($group['fields'] ?? null);
            if(!$fields || count($fields)>10)self::unavailable();$values=[];
            foreach($fields as $index=>$field){
                $field=self::row($field);$label=$field['label'] ?? null;
                if(($field['field_index'] ?? null)!==$index || !is_bool($field['status'] ?? null)
                    || !in_array($label,['text','number','email','data','time','id','phone','img'],true))self::unavailable();
                $values[]=['field_index'=>$index,'title'=>self::text($field['title'] ?? null),'label'=>$label,'status'=>$field['status'],
                    'value'=>$label==='img' ? self::images($field['value'] ?? null,8) : self::text($field['value'] ?? null)];
            }
            $projected=['product_id'=>$id,'product_name'=>self::text($group['product_name'] ?? null),'definition_sha256'=>$hash,'fields'=>$values];
            if($productIds===null || in_array($id,$productIds,true))$out[]=$projected;
        }
        return $out;
    }

    public static function carts(mixed $raw):array
    {
        $out=[];
        foreach(self::list($raw) as $cart){
            $cart=self::row($cart);$product=self::row($cart['productInfo'] ?? null);
            $id=self::productId($product['id'] ?? null);
            if(isset($cart['product_id']) && self::productId($cart['product_id'])!==$id)self::unavailable();
            $row=self::scalars($cart,self::CART);$row['id']=self::text($cart['id'] ?? null);
            if($row['id']==='' || strlen($row['id'])>50)self::unavailable();
            foreach(self::CART_MONEY as $key)if(array_key_exists($key,$cart))$row[$key]=self::money($cart[$key]);
            $row['productInfo']=self::scalars($product,self::PRODUCT);
            foreach(self::PRODUCT_MONEY as $key)if(array_key_exists($key,$product))$row['productInfo'][$key]=self::money($product[$key]);
            if(isset($product['attrInfo'])){
                $sku=self::row($product['attrInfo']);
                if(self::productId($sku['product_id'] ?? null)!==$id)self::unavailable();
                $row['productInfo']['attrInfo']=self::scalars($sku,self::SKU);
                foreach(self::PRODUCT_MONEY as $key)if(array_key_exists($key,$sku))$row['productInfo']['attrInfo'][$key]=self::money($sku[$key]);
            }
            $out[]=$row;
        }
        return $out;
    }

    private static function scalars(array $raw,array $fields):array
    {
        $out=[];foreach($fields as $key)if(array_key_exists($key,$raw)){
            $value=$raw[$key];
            if(is_string($value))self::text($value);
            elseif(!is_int($value) && !is_bool($value) && $value!==null)self::unavailable();
            $out[$key]=$value;
        }
        return $out;
    }
    private static function money(mixed $value):string
    {
        $money=MarketplaceDecimal::value($value);
        if(bccomp($money,'9999999999.99',2)>0)self::unavailable();return $money;
    }
    private static function productId(mixed $id):int
    {
        return MarketplaceOrderInput::unsigned($id,'保存商品',8388607) ?: self::unavailable();
    }
    private static function text(mixed $value):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||str_contains($value,"\0"))self::unavailable();return $value;
    }
    private static function images(mixed $value,int $maximum=500):array
    {
        $value=self::list($value);if(count($value)>$maximum)self::unavailable();foreach($value as $image)self::text($image);return $value;
    }
    private static function row(mixed $value):array { if(!is_array($value))self::unavailable();return $value; }
    private static function list(mixed $value):array { if(!is_array($value)||!array_is_list($value))self::unavailable();return $value; }
    private static function unavailable():never { throw new AuthUnavailableException(); }
}
