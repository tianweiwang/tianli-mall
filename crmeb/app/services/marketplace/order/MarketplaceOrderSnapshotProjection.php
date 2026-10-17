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

    /** Existing platform order-read authorization belongs to the endpoint, never to this pure projection. */
    public static function platformOrder(array $saved):array
    {
        if(MarketplaceTradePolicy::savedOrderIsLegacy($saved))return $saved;
        $out=self::customerOrder($saved);
        if(array_key_exists('is_all_refund',$saved))$out+=self::scalars($saved,['is_all_refund']);
        if(array_key_exists('refunded_price',$saved))$out['refunded_price']=self::money($saved['refunded_price']);
        if(array_key_exists('refund',$saved)){
            $out['refund']=[];
            foreach(self::list($saved['refund']) as $refund){
                $refund=self::row($refund);
                $summary=self::scalars($refund,['id','order_id','store_order_id','refund_type','refund_num','is_cancel','add_time']);
                if(array_key_exists('refund_price',$refund))$summary['refund_price']=self::money($refund['refund_price']);
                $out['refund'][]=$summary;
            }
        }
        // No _info, raw refund cart JSON, costs or unsupported brokerage fields leave this DTO.
        return $out;
    }

    /** Parent and refund are actual saved rows read by the platform-authorized caller. */
    public static function platformRefund(array $refund,array $parent):array
    {
        return self::customerRefund($refund,$parent);
    }

    /** Only controllers choose this internal output context; no wire role or UID is consumed. */
    public static function listViewer(string $scope,int $uid=0):?int
    {
        if($scope==='' || $scope==='platform')return null;
        if(!in_array($scope,['customer','mobile'],true))self::unavailable();
        return MarketplaceOrderInput::unsigned($uid,'列表顾客',2147483647) ?: self::unavailable();
    }

    /** List status and cart wrappers differ from the detail DTO; keep only their saved display fields. */
    public static function orderListRow(array $saved,string $scope):array
    {
        if(!array_key_exists('marketplace_trade_version',$saved))self::unavailable();
        if(MarketplaceTradePolicy::savedOrderIsLegacy($saved))return $saved;
        return self::listRow($saved,$scope,false);
    }

    /** The caller supplies the one batch-read real parent, not a client or current-config version. */
    public static function refundListRow(array $saved,array $parent,string $scope):array
    {
        if(!array_key_exists('marketplace_trade_version',$parent))self::unavailable();
        if(MarketplaceTradePolicy::savedOrderIsLegacy($parent))return $saved;
        $saved['marketplace_trade_version']=1;
        if(array_key_exists('mer_id',$parent))$saved['mer_id']=$parent['mer_id'];
        return self::listRow($saved,$scope,true);
    }

    private static function listRow(array $saved,string $scope,bool $refund):array
    {
        if(!in_array($scope,['customer','platform','mobile'],true))self::unavailable();
        $out=self::scalars($saved,['id','order_id','store_order_id','store_order_order_id','store_order_status','mer_id','pid',
            'type','status','paid','pay_type','shipping_type','delivery_type','is_cancel','is_del','is_gift','virtual_type',
            'refund_status','refund_type','refund_num','total_num','is_all_refund','add_time','_add_time','pay_time','_pay_time',
            '_refund_time','pay_type_name','pink_name','color','mark','remark','refuse_reason','refund_express','refund_reason',
            'merchant_name_snapshot','merchant_after_sale_contact_snapshot']);
        $out['marketplace_trade_version']=1;
        foreach(['total_price','pay_price','total_postage','pay_postage','refund_price','refunded_price'] as $key)
            if(array_key_exists($key,$saved))$out[$key]=self::money($saved[$key]);
        if($scope==='platform')$out+=self::scalars($saved,['uid','nickname']);
        if(isset($saved['_status'])){
            if($refund || $scope==='customer')$out['_status']=self::scalars(self::row($saved['_status']),self::STATUS);
            else {
                if(!is_int($saved['_status']))self::unavailable();
                $out['_status']=$saved['_status'];
            }
        }
        if(isset($saved['status_name'])){
            $status=self::row($saved['status_name']);$out['status_name']=self::scalars($status,['status_name']);
            if(array_key_exists('pics',$status))$out['status_name']['pics']=self::images($status['pics']);
        }
        foreach(['cartInfo','cart_info','refund_cartInfo'] as $key)
            if(array_key_exists($key,$saved))$out[$key]=self::carts($saved[$key]);
        if(array_key_exists('_info',$saved)){
            $out['_info']=[];
            foreach(self::row($saved['_info']) as $wrapper){
                $wrapper=self::row($wrapper);
                $out['_info'][]=['cart_info'=>self::carts([self::row($wrapper['cart_info'] ?? null)])[0]];
            }
        }
        if(array_key_exists('cart_id',$saved)){
            $out['cart_id']=[];foreach(self::list($saved['cart_id']) as $ref)$out['cart_id'][]=self::text($ref);
        }
        if(array_key_exists('refund',$saved)){
            $out['refund']=[];
            foreach(self::list($saved['refund']) as $row){
                $row=self::row($row);$summary=self::scalars($row,['id','order_id','store_order_id','refund_type','refund_num','is_cancel','add_time']);
                if(array_key_exists('refund_price',$row))$summary['refund_price']=self::money($row['refund_price']);
                $out['refund'][]=$summary;
            }
        }
        if(array_key_exists('split',$saved)){
            $out['split']=[];foreach(self::list($saved['split']) as $row)$out['split'][]=self::scalars(self::row($row),['id','pid']);
        }
        if(array_key_exists('refund_img',$saved))$out['refund_img']=self::images($saved['refund_img']===''?[]:$saved['refund_img']);
        return $out;
    }

    /** Independent merchant DTO; the caller has already queried and authorized this saved owner. */
    public static function merchantOrder(array $saved,array $items,bool $detail=false):array
    {
        if(self::merchantInteger($saved['marketplace_trade_version']??null,1,1)!==1
            ||self::merchantInteger($saved['pid']??null,0,0)!==0
            ||self::merchantInteger($saved['is_system_del']??null,0,0)!==0)self::unavailable();
        $id=self::merchantInteger($saved['id']??null,1,2147483647);
        $uid=self::merchantInteger($saved['uid']??null,1,2147483647);
        $mer=self::merchantInteger($saved['mer_id']??null,1,4294967295);
        $number=self::text($saved['order_id']??null);
        if($number===''||strlen($number)>32||trim($number)!==$number)self::unavailable();
        $out=['id'=>$id,'order_id'=>$number,'marketplace_trade_version'=>1,
            'add_time'=>self::merchantInteger($saved['add_time']??null,1,4294967295),
            'paid'=>self::merchantInteger($saved['paid']??null,0,1),
            'pay_type'=>self::text($saved['pay_type']??null),
            'status'=>self::merchantInteger($saved['status']??null,-128,127),
            'is_cancel'=>self::merchantInteger($saved['is_cancel']??null,0,1),
            'refund_status'=>self::merchantInteger($saved['refund_status']??null,0,255),
            'shipping_type'=>self::merchantInteger($saved['shipping_type']??null,1,2),
            'total_num'=>self::merchantInteger($saved['total_num']??null,1,4294967295),
            'total_price'=>self::merchantMoney($saved['total_price']??null),
            'pay_price'=>self::merchantMoney($saved['pay_price']??null),
            'pay_postage'=>self::merchantMoney($saved['pay_postage']??null),'items'=>[]];
        if(!array_is_list($items)||!$items||count($items)>500)self::unavailable();
        $seen=[];$products=[];$quantityTotal=0;$goods='0.00';$postage='0.00';
        foreach($items as $item){
            $item=self::row($item);$itemId=self::merchantInteger($item['id']??null,1,2147483647);
            $productId=self::productId($item['product_id']??null);
            $quantity=self::merchantInteger($item['cart_num']??null,1,2147483647);
            if(isset($seen[$itemId])||self::merchantInteger($item['oid']??null,1,2147483647)!==$id
                ||self::merchantInteger($item['uid']??null,1,2147483647)!==$uid
                ||self::merchantInteger($item['mer_id']??null,1,4294967295)!==$mer)self::unavailable();
            $raw=$item['cart_info']??null;
            if(!is_string($raw)||strlen($raw)>65535)self::unavailable();
            try{$cart=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
            catch(\JsonException $error){self::unavailable();}
            $cart=self::row($cart);$product=self::row($cart['productInfo']??null);$sku=self::row($product['attrInfo']??null);
            if(self::productId($cart['product_id']??null)!==$productId||self::productId($product['id']??null)!==$productId
                ||self::productId($sku['product_id']??null)!==$productId
                ||self::merchantInteger($cart['cart_num']??null,1,2147483647)!==$quantity
                ||self::merchantInteger($cart['uid']??null,1,2147483647)!==$uid
                ||self::merchantInteger($product['mer_id']??null,1,4294967295)!==$mer)self::unavailable();
            $unit=self::merchantMoney($cart['truePrice']??null);$line=self::merchantMoney($cart['sum_true_price']??null);
            $linePostage=self::merchantMoney($cart['postage_price']??null);
            if(bcmul($unit,(string)$quantity,2)!==$line)self::unavailable();
            $productImage=self::text($product['image']??null);$skuImage=self::text($sku['image']??null);
            $out['items'][]=['id'=>$itemId,'product_id'=>$productId,
                'product_name'=>self::text($product['store_name']??null),'sku_name'=>self::text($sku['suk']??null),
                'image'=>$skuImage!==''?$skuImage:$productImage,'quantity'=>$quantity,
                'unit_price'=>$unit,'line_price'=>$line,'postage_price'=>$linePostage];
            $seen[$itemId]=true;$products[$productId]=true;$quantityTotal+=$quantity;
            $goods=bcadd($goods,$line,2);$postage=bcadd($postage,$linePostage,2);
        }
        if($quantityTotal!==$out['total_num']||$goods!==$out['total_price']||$postage!==$out['pay_postage']
            ||bcadd($goods,$postage,2)!==$out['pay_price'])self::unavailable();
        if($detail){
            $out['recipient']=[];
            foreach(['real_name','user_phone','user_address'] as $key)$out['recipient'][$key]=self::text($saved[$key]??null);
            $out['mark']=self::text($saved['mark']??null);
            $out['custom_form']=self::customForms($saved['custom_form']??null);
            foreach($out['custom_form'] as $group)if(!isset($products[$group['product_id']]))self::unavailable();
        }
        return $out;
    }

    /** Public subset of a checked pure plan; complete saved root/items must be supplied, never just the selected rows. */
    public static function fulfillmentPlan(array $savedRoot,array $savedItems,array $plan):array
    {
        return MarketplaceOrderFulfillmentPlan::savedProjection($savedRoot,$savedItems,$plan);
    }

    private static function merchantInteger(mixed $value,int $minimum,int $maximum):int
    {
        if((!is_int($value)&&!is_string($value))||preg_match('/\A(?:0|-?[1-9][0-9]{0,9})\z/D',(string)$value)!==1
            ||(int)$value<$minimum||(int)$value>$maximum)self::unavailable();
        return (int)$value;
    }

    private static function merchantMoney(mixed $value):string
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1)self::unavailable();
        return $value;
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
