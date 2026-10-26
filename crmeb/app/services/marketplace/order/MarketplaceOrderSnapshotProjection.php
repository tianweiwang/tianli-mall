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
        return self::checkedCommercialRoot($saved,$items,$detail);
    }

    /**
     * @internal Complete saved commercial conservation, never public admission or current eligibility.
     * The real caller retains current deletion/payment consistency and authorization checks.
     */
    public static function refundCommercialOrder(array $saved,array $items):array
    {
        if(self::merchantInteger($saved['marketplace_trade_version']??null,1,1)!==1
            ||self::merchantInteger($saved['pid']??null,0,0)!==0)self::unavailable();
        return self::checkedCommercialRoot($saved,$items,false);
    }

    /** Only the authenticated sharing/history service may admit these saved facts. */
    public static function platformChatOrderCard(array $saved,array $items):array
    {
        if(self::merchantInteger($saved['marketplace_trade_version']??null,1,1)!==1
            ||self::merchantInteger($saved['pid']??null,0,0)!==0
            ||self::merchantInteger($saved['is_del']??null,0,0)!==0
            ||self::merchantInteger($saved['is_system_del']??null,0,0)!==0)self::unavailable();
        $commercial=self::checkedCommercialRoot($saved,$items,false);
        $out=['scope'=>'platform_chat_order_card_v1','marketplace_trade_version'=>1,
            'id'=>$commercial['id'],'order_id'=>$commercial['order_id'],'paid'=>$commercial['paid'],
            'pay_price'=>$commercial['pay_price'],'total_num'=>$commercial['total_num'],
            'add_time_y'=>date('Y-m-d',$commercial['add_time']),'add_time_h'=>date('H:i:s',$commercial['add_time']),
            'merchant_name_snapshot'=>self::text($saved['merchant_name_snapshot']??null),'cartInfo'=>[]];
        foreach($commercial['items'] as$item){
            $out['cartInfo'][]=['id'=>$item['id'],'cart_num'=>$item['quantity'],'sku_name'=>$item['sku_name'],
                'productInfo'=>['image'=>$item['image'],'store_name'=>$item['product_name'],'price'=>$item['unit_price']]];
        }
        return $out;
    }

    /** Shared complete saved-row conservation; caller-specific admission precedes this pure check. */
    private static function checkedCommercialRoot(array $saved,array $items,bool $detail=false,?array &$decoded=null):array
    {
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
            if($decoded!==null)$decoded[$itemId]=$cart;
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

    /** Checked current root extension only; no raw family, actor, payment or reserve facts leave this DTO. */
    public static function merchantOrderWithFulfillment(array $saved,array $items,?array $summary,array $actions,bool $detail=false):array
    {
        $out=self::merchantOrder($saved,$items,$detail);
        return self::rootWithFulfillment($out,$summary,$actions);
    }

    /** Append only to an already allowlisted saved root DTO; authorization and facts belong to the caller. */
    public static function rootWithFulfillment(array $out,?array $summary,array $actions):array
    {
        if(array_keys($actions)!==['ship','receive']||!is_bool($actions['ship'])||$actions['receive']!==false)self::unavailable();
        if($summary===null){if($actions['ship']!==false)self::unavailable();}
        else{
            if(array_keys($summary)!==['shipping_state','receipt_state','closed','original','shipped','received',
                'cancelled_unshipped','returned_accepted','to_ship','to_receive','child_count']
                ||!in_array($summary['shipping_state'],['unshipped','partial','shipped'],true)
                ||!in_array($summary['receipt_state'],['not_shipped','pending','partial','received'],true)
                ||!is_bool($summary['closed']))self::unavailable();
            foreach(['original','shipped','received','cancelled_unshipped','returned_accepted','to_ship','to_receive','child_count'] as$key)
                if(!is_int($summary[$key])||$summary[$key]<0||$summary[$key]>4294967295)self::unavailable();
            if($summary['original']!==self::merchantInteger($out['total_num']??null,1,4294967295)||$summary['cancelled_unshipped']!==0||$summary['returned_accepted']!==0)self::unavailable();
        }
        $out['fulfillment_summary']=$summary;$out['allowed_actions']=$actions;return $out;
    }

    /** Public subset of a checked pure plan; complete saved root/items must be supplied, never just the selected rows. */
    public static function fulfillmentPlan(array $savedRoot,array $savedItems,array $plan):array
    {
        return MarketplaceOrderFulfillmentPlan::savedProjection($savedRoot,$savedItems,$plan);
    }

    /** Fixed customer list display; complete authorized original items and same-snapshot display facts only. */
    public static function customerListFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension):array
    {
        return self::rootFromFacts($root,$fullItems,$facts,$options,$extension,'customer_list');
    }

    public static function platformListFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension):array
    {
        return self::rootFromFacts($root,$fullItems,$facts,$options,$extension,'platform_list');
    }

    public static function mobileListFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension):array
    {
        return self::rootFromFacts($root,$fullItems,$facts,$options,$extension,'mobile_list');
    }

    public static function customerRootFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension):array
    {
        return self::rootFromFacts($root,$fullItems,$facts,$options,$extension,'customer_detail');
    }

    public static function platformRootFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension):array
    {
        return self::rootFromFacts($root,$fullItems,$facts,$options,$extension,'platform_detail');
    }

    public static function mobileRootFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension):array
    {
        return self::rootFromFacts($root,$fullItems,$facts,$options,$extension,'mobile_detail');
    }

    /** A package owns saved goods and delivery, while its actual paid header belongs to the authorized parent. */
    public static function fulfillmentChildFromFacts(array $root,array $fullOriginalItems,array $child,array $fullChildItems,
        array $history,array $options,bool $detail=false):array
    {
        self::displayOptions($options,null);
        self::merchantInteger($root['marketplace_trade_version']??null,1,1);
        self::merchantInteger($root['pid']??null,0,0);
        $parent=self::checkedCommercialRoot($root,$fullOriginalItems,false);
        self::merchantInteger($root['paid']??null,1,1);
        $paidAt=self::merchantInteger($root['pay_time']??null,1,4294967295);
        $id=self::merchantInteger($child['id']??null,1,2147483647);
        if(self::merchantInteger($child['marketplace_trade_version']??null,1,1)!==1
            ||self::merchantInteger($child['pid']??null,1,2147483647)!==$parent['id']
            ||self::merchantInteger($child['uid']??null,1,2147483647)!==self::merchantInteger($root['uid'],1,2147483647)
            ||self::merchantInteger($child['mer_id']??null,1,4294967295)!==self::merchantInteger($root['mer_id'],1,4294967295))self::unavailable();
        self::displayKeys($history,['children','child_items','ships_by_child','operations','ranges','ship_ranges','summary','source_snapshot_sha256']);
        foreach(['children','child_items','ships_by_child','operations'] as$key)self::row($history[$key]);
        self::list($history['ranges']);self::list($history['ship_ranges']);
        if(!isset($history['children'][$id])||$history['children'][$id]!==$child)self::unavailable();
        $expectedItems=[];
        foreach($history['child_items'] as$item){
            $item=self::row($item);
            if(self::merchantInteger($item['oid']??null,1,2147483647)===$id)$expectedItems[]=$item;
        }
        if($expectedItems!==$fullChildItems)self::unavailable();
        $package=self::checkedCommercialRoot($child,$fullChildItems,$detail);
        $ship=self::row($history['ships_by_child'][$id]??null);
        if(($ship['action']??null)!=='ship'||($ship['state']??null)!==1||($ship['child_order_id']??null)!==$id
            ||($ship['root_order_id']??null)!==$parent['id'])self::unavailable();
        $shippedAt=self::merchantInteger($ship['shipped_at']??null,1,4294967295);
        $due=self::merchantInteger($ship['receipt_due_at']??null,0,4294967295);
        $quantity=$package['total_num'];$shipped=0;$received=0;$lastReceived=null;
        foreach($history['ranges'] as$range){
            $range=self::row($range);
            if(($range['child_order_id']??null)!==$id)continue;
            $q=self::merchantInteger($range['quantity']??null,1,2147483647);
            if(($range['operation_action']??null)==='ship')$shipped+=$q;
            elseif(($range['operation_action']??null)==='receive')$received+=$q;
            else self::unavailable();
        }
        foreach($history['operations'] as$operation){
            $operation=self::row($operation);
            if(($operation['child_order_id']??null)!==$id||($operation['action']??null)!=='receive')continue;
            if(($operation['target_operation_id']??null)!==($ship['id']??null))self::unavailable();
            $at=self::merchantInteger($operation['received_at']??null,1,4294967295);
            $lastReceived=$lastReceived===null?$at:max($lastReceived,$at);
        }
        if($shipped!==$quantity||$received>$quantity||($received===0)!==($lastReceived===null)
            ||self::merchantInteger($child['status']??null,1,2)!==($received===$quantity?2:1))self::unavailable();
        $out=['schema'=>1,'scope'=>'marketplace_fulfillment_child_v1','id'=>$id,'order_id'=>$package['order_id'],
            'root_order_id'=>$parent['id'],'root_order_no'=>$parent['order_id'],'marketplace_trade_version'=>1,
            'merchant_name_snapshot'=>self::text($child['merchant_name_snapshot']??null),'shipping_type'=>$package['shipping_type'],
            'carrier_code'=>self::text($child['delivery_code']??null),'carrier_name'=>self::text($child['delivery_name']??null),
            'tracking_no'=>self::text($child['delivery_id']??null),'shipped_at'=>$shippedAt,'receipt_due_at'=>$due,
            'last_received_at'=>$lastReceived,'received_at'=>$received===$quantity?$lastReceived:null,
            'shipping_state'=>'shipped','receipt_state'=>$received===0?'pending':($received===$quantity?'received':'partial'),
            'closed'=>$received===$quantity,'original'=>$quantity,'shipped'=>$quantity,'received'=>$received,
            'cancelled_unshipped'=>0,'returned_accepted'=>0,'to_ship'=>0,'to_receive'=>$quantity-$received,'items'=>$package['items'],
            'payment_owner_order_id'=>$parent['id'],'paid'=>1,'pay_type'=>$parent['pay_type'],'pay_time'=>$paidAt,
            'pay_type_name'=>self::listPayType($parent['pay_type'],true)];
        if($detail){$out['recipient']=$package['recipient'];$out['mark']=$package['mark'];$out['custom_form']=$package['custom_form'];}
        return $out;
    }

    /** S2 saved-only root formatter. The owner supplies one complete inspected native snapshot. */
    public static function aftersaleRootFromFacts(string $view,array $root,array $fullItems,array $facts,array $options,array $inspection,?array $children,array $actions):array
    {
        self::aftersaleInspection($root,$fullItems,$inspection);
        if(!in_array($view,['merchant_list','merchant_detail','customer_list','customer_detail','platform_list','platform_detail','mobile_list','mobile_detail'],true))self::unavailable();
        if(str_starts_with($view,'merchant_')){
            self::displayOptions($options,null);
            if($facts!==[]||$children!==null)self::unavailable();
            $out=self::merchantOrder($root,$fullItems,$view==='merchant_detail');
            return self::rootWithAftersale($out,$inspection['summary'],$actions,$inspection['aftersale']);
        }
        $ship=null;$receive=null;
        foreach($inspection['fulfillment']['operations'] as$op){
            if($op['action']==='ship')$ship=$ship===null?$op['shipped_at']:max($ship,$op['shipped_at']);
            elseif($op['action']==='receive')$receive=$receive===null?$op['received_at']:max($receive,$op['received_at']);
            else self::unavailable();
        }
        $extension=['summary'=>$inspection['summary'],'actions'=>$actions,
            'history_times'=>['last_shipped_at'=>$ship,'last_received_at'=>$receive,
                'received_completed_at'=>$inspection['summary']['received']===$inspection['summary']['original']?$receive:null],
            'children'=>$children];
        return self::savedRootFromFacts($root,$fullItems,$facts,$options,$extension,$view,$inspection);
    }

    /** S2 package uses actual child item IDs and its original saved commercial values. */
    public static function fulfillmentChildAftersaleFromFacts(array $root,array $fullOriginalItems,array $child,array $fullChildItems,
        array $inspection,array $options,bool $detail=false):array
    {
        self::displayOptions($options,null);self::aftersaleInspection($root,$fullOriginalItems,$inspection);
        $parent=self::checkedCommercialRoot($root,$fullOriginalItems,false);$id=self::merchantInteger($child['id']??null,1,2147483647);
        self::merchantInteger($root['paid']??null,1,1);$paidAt=self::merchantInteger($root['pay_time']??null,1,4294967295);
        $head=self::row($inspection['source']['child_orders'][$id]??null);
        foreach($head as$key=>$expected)if(!array_key_exists($key,$child)||(string)$child[$key]!==(string)$expected)self::unavailable();
        if(self::merchantInteger($child['pid']??null,1,2147483647)!==$parent['id']
            ||self::merchantInteger($child['uid']??null,1,2147483647)!==self::merchantInteger($root['uid'],1,2147483647)
            ||self::merchantInteger($child['mer_id']??null,1,4294967295)!==self::merchantInteger($root['mer_id'],1,4294967295))self::unavailable();
        $package=self::checkedCommercialRoot($child,$fullChildItems,$detail);
        $ship=self::row($inspection['fulfillment']['ships'][$id]??null);
        if($ship['action']!=='ship'||$ship['child_order_id']!==$id||$ship['root_order_id']!==$parent['id'])self::unavailable();
        $parents=[];$expectedItems=[];$received=[];$returned=[];$lastReceived=null;
        foreach($inspection['history']['ships'] as$range)if($range['child_order_id']===$id){
            $parents[]=$range;$expectedItems[$range['child_item_id']]=($expectedItems[$range['child_item_id']]??0)+$range['quantity'];
        }
        $actualItems=[];foreach($fullChildItems as$item){
            self::displayKeys($item,['id','oid','uid','mer_id','cart_id','product_id','cart_num','refund_num','surplus_num','split_status','cart_info','unique','old_cart_id']);
            $itemId=self::merchantInteger($item['id'],1,2147483647);
            if(isset($actualItems[$itemId])||!isset($expectedItems[$itemId])||self::merchantInteger($item['oid'],1,2147483647)!==$id
                ||self::merchantInteger($item['cart_num'],1,2147483647)!==$expectedItems[$itemId])self::unavailable();$actualItems[$itemId]=true;
        }
        if(count($actualItems)!==count($expectedItems)||$parents===[])self::unavailable();
        foreach($inspection['history']['received'] as$range)if($range['child_order_id']===$id)$received[]=$range;
        foreach($inspection['history']['returned'] as$range)if($range['child_order_id']===$id)$returned[]=$range;
        foreach($inspection['fulfillment']['operations'] as$op)if($op['child_order_id']===$id&&$op['action']==='receive')
            $lastReceived=$lastReceived===null?$op['received_at']:max($lastReceived,$op['received_at']);
        $q=self::coordinateQuantity($parents);$r=self::coordinateQuantity($received);$t=self::coordinateQuantity($returned);
        $overlap=self::coordinateIntersection($received,$returned);$left=$q-$r-$t+$overlap;
        if($q!==$package['total_num']||$left<0||($r===0)!==($lastReceived===null)
            ||self::merchantInteger($child['status']??null,1,2)!==($left===0?2:1))self::unavailable();
        $availability=MarketplaceOrderRefundFacts::receiptAvailabilityFromInspection($inspection,$id);
        if(self::coordinateQuantity($availability['remaining'])!==$left)self::unavailable();
        $as=$inspection['aftersale'];$as['pending_cancel_quantity']=0;$as['pending_return_quantity']=self::childPendingReturn($inspection,$parents);
        $as['returned_after_receipt']=$overlap;$as['fulfillment_completed_at']=self::childCompletedAt($inspection,$id,$parents);
        if(($left===0)!==($as['fulfillment_completed_at']!==null))self::unavailable();
        $out=['schema'=>2,'scope'=>'marketplace_fulfillment_child_aftersale_v1','id'=>$id,'order_id'=>$package['order_id'],
            'root_order_id'=>$parent['id'],'root_order_no'=>$parent['order_id'],'marketplace_trade_version'=>1,
            'merchant_name_snapshot'=>self::text($child['merchant_name_snapshot']??null),'shipping_type'=>$package['shipping_type'],
            'carrier_code'=>self::text($child['delivery_code']??null),'carrier_name'=>self::text($child['delivery_name']??null),
            'tracking_no'=>self::text($child['delivery_id']??null),'shipped_at'=>$ship['shipped_at'],'receipt_due_at'=>$ship['receipt_due_at'],
            'last_received_at'=>$lastReceived,'received_at'=>$r===$q?$lastReceived:null,
            'shipping_state'=>'shipped','receipt_state'=>$r===0?'pending':($r===$q?'received':'partial'),
            'closed'=>$left===0,'original'=>$q,'shipped'=>$q,'received'=>$r,'cancelled_unshipped'=>0,'returned_accepted'=>$t,
            'to_ship'=>0,'to_receive'=>$left,'items'=>$package['items'],'payment_owner_order_id'=>$parent['id'],
            'paid'=>1,'pay_type'=>$parent['pay_type'],'pay_time'=>$paidAt,'pay_type_name'=>self::listPayType($parent['pay_type'],true),'aftersale'=>$as];
        if($detail){$out['recipient']=$package['recipient'];$out['mark']=$package['mark'];$out['custom_form']=$package['custom_form'];}
        return $out;
    }

    private static function aftersaleInspection(array $root,array $fullItems,array $inspection):void
    {
        self::displayKeys($inspection,['source','winner','actions','fulfillment','history','summary','aftersale','credit_facts','last_action_id','last_operation_id']);
        if($inspection['last_action_id']===null||!is_string($inspection['last_action_id'])
            ||$inspection['source']['root']!==$root||array_values($inspection['source']['items'])!==$fullItems)self::unavailable();
        self::checkedCommercialRoot($root,$fullItems,false);
        if($inspection['aftersale']['revision']!==$inspection['last_action_id'])self::unavailable();
        self::rootWithAftersale(['total_num'=>$root['total_num']],$inspection['summary'],['ship'=>false,'receive'=>false],$inspection['aftersale']);
    }

    private static function rootWithAftersale(array $out,array $summary,array $actions,array $as):array
    {
        self::displayKeys($actions,['ship','receive']);if(!is_bool($actions['ship'])||$actions['receive']!==false)self::unavailable();
        self::displayKeys($summary,['shipping_state','receipt_state','closed','original','shipped','received','cancelled_unshipped','returned_accepted','to_ship','to_receive','child_count']);
        self::displayKeys($as,['schema','revision','confirmed_amount','held_amount','available_amount','pending_cancel_quantity','pending_return_quantity','returned_after_receipt','fulfillment_completed_at']);
        if($as['schema']!==1||!is_string($as['revision'])||preg_match('/\A[1-9][0-9]{0,19}\z/D',$as['revision'])!==1
            ||bccomp($as['revision'],'18446744073709551615',0)>0||!is_bool($summary['closed']))self::unavailable();
        foreach(['original','shipped','received','cancelled_unshipped','returned_accepted','to_ship','to_receive','child_count'] as$key)
            if(!is_int($summary[$key])||$summary[$key]<0||$summary[$key]>4294967295)self::unavailable();
        foreach(['pending_cancel_quantity','pending_return_quantity','returned_after_receipt'] as$key)
            if(!is_int($as[$key])||$as[$key]<0||$as[$key]>4294967295)self::unavailable();
        foreach(['confirmed_amount','held_amount','available_amount'] as$key)self::merchantMoney($as[$key]);
        $q=$summary['original'];$s=$summary['shipped'];$r=$summary['received'];$c=$summary['cancelled_unshipped'];$t=$summary['returned_accepted'];$i=$as['returned_after_receipt'];
        if($q!==self::merchantInteger($out['total_num']??null,1,4294967295)||$s+$c>$q||$r>$s||$t>$s||$i>min($r,$t)
            ||$i<max(0,$r+$t-$s)||$summary['to_ship']!==$q-$s-$c||$summary['to_receive']!==$s-$r-$t+$i
            ||$summary['closed']!==($summary['to_ship']===0&&$summary['to_receive']===0)
            ||$summary['shipping_state']!==($s===0?'unshipped':($summary['to_ship']>0?'partial':'shipped'))
            ||$summary['receipt_state']!==($s===0?'not_shipped':($r===$s?'received':($r===0?'pending':'partial')))
            ||($s===0)!==($summary['child_count']===0)||$summary['child_count']>$s
            ||$as['pending_cancel_quantity']>$summary['to_ship']||$as['pending_return_quantity']>$s-$t
            ||($summary['closed']!==($as['fulfillment_completed_at']!==null))||($actions['ship']&&$summary['to_ship']<=$as['pending_cancel_quantity']))self::unavailable();
        if($as['fulfillment_completed_at']!==null&&(!is_int($as['fulfillment_completed_at'])||$as['fulfillment_completed_at']<1||$as['fulfillment_completed_at']>4294967295))self::unavailable();
        $out['schema']=2;$out['scope']='marketplace_order_aftersale_v1';$out['fulfillment_summary']=$summary;$out['allowed_actions']=$actions;$out['aftersale']=$as;return $out;
    }

    /** Bounded interval arithmetic only, preserving the actual source item and S-parent identity. */
    private static function coordinateQuantity(array $rows):int
    {
        $q=0;foreach($rows as$row){$first=self::merchantInteger($row['unit_start']??null,0,2147483647);$last=self::merchantInteger($row['unit_end']??null,1,2147483647);
            if($last<=$first)self::unavailable();$q+=$last-$first;if($q>4294967295)self::unavailable();}return $q;
    }

    private static function coordinateIntersection(array $left,array $right):int
    {
        $q=0;foreach($left as$a)foreach($right as$b){
            if($a['source_item_id']!==$b['source_item_id'])continue;
            if(isset($a['source_ship_range_id'],$b['source_ship_range_id'])&&$a['source_ship_range_id']!==$b['source_ship_range_id'])continue;
            $q+=max(0,min($a['unit_end'],$b['unit_end'])-max($a['unit_start'],$b['unit_start']));
        }if($q>4294967295)self::unavailable();return $q;
    }

    private static function childPendingReturn(array $inspection,array $parents):int
    {
        $quantity=0;foreach($inspection['history']['refunds'] as$refund)
            if($refund['kind']==='return_goods'&&in_array($refund['after']['business_state'],['REQUESTED','APPROVED'],true))
                $quantity+=self::coordinateIntersection($refund['allocations'],$parents)-self::coordinateIntersection($refund['accepted'],$parents);
        if($quantity<0||$quantity>self::coordinateQuantity($parents))self::unavailable();return $quantity;
    }
    /** Only format the already validated committed cut merge; no business replay or future IDs. */
    private static function childCompletedAt(array $inspection,int $childId,array $parents):?int
    {
        $actions=$inspection['actions'];$operations=$inspection['fulfillment']['operations'];$a=0;$o=0;$lastA=null;$lastO=null;$covered=[];$done=null;
        while($a<count($actions)||$o<count($operations)){
            $action=$actions[$a]??null;$operation=$operations[$o]??null;
            $readyA=$action!==null&&$action['fulfillment_cut_id']===$lastO;$readyO=$operation!==null&&$operation['aftersale_cut_id']===$lastA;
            if($readyA===$readyO)self::unavailable();
            if($readyA){$row=$action;$lastA=$row['id'];++$a;
                foreach($row['result_payload']['movements'] as$part)if($part['kind']==='return_in')foreach($parents as$parent)
                    if($part['source_item_id']===$parent['source_item_id']){
                        $first=max($part['unit_start'],$parent['unit_start']);$last=min($part['unit_end'],$parent['unit_end']);
                        if($last>$first)$covered[]=['source_item_id'=>$parent['source_item_id'],'source_ship_range_id'=>$parent['id'],'unit_start'=>$first,'unit_end'=>$last];
                    }
            }else{$row=$operation;$lastO=$row['id'];++$o;
                if($row['action']==='receive'&&$row['child_order_id']===$childId)foreach($row['validated_ranges'] as$range)$covered[]=$range;
            }
            if($done===null){$q=0;foreach($parents as$parent){$segments=[];foreach($covered as$part)
                    if($part['source_item_id']===$parent['source_item_id']&&$part['source_ship_range_id']===$parent['id'])$segments[]=[$part['unit_start'],$part['unit_end']];
                usort($segments,static fn(array $x,array $y):int=>($x[0]<=>$y[0])?:($x[1]<=>$y[1]));$cursor=$parent['unit_start'];
                foreach($segments as$segment){if($segment[0]>$cursor)break;$cursor=max($cursor,$segment[1]);}$q+=min($cursor,$parent['unit_end'])-$parent['unit_start'];
            }if($q===self::coordinateQuantity($parents))$done=self::merchantInteger($row['created_at'],1,4294967295);}
        }return $done;
    }
    private static function detailAftersaleStatus(array $root,array $inspection,array $options):array
    {
        $summary=$inspection['summary'];$as=$inspection['aftersale'];
        if($summary['closed']){
            if($summary['shipped']===0)$out=['_type'=>4,'_title'=>'履约已取消','_msg'=>'未发货商品的履约已取消，退款处理请查看售后详情','_class'=>'state-ytk'];
            elseif($summary['received']===$summary['shipped'])$out=['_type'=>3,'_title'=>'已收货','_msg'=>'已发货商品已收货，售后处理请查看售后详情','_class'=>'state-ypj'];
            else $out=['_type'=>4,'_title'=>'履约已终结','_msg'=>'包裹已由真实收货或退货验收完成履约，退款状态请查看售后详情','_class'=>'state-ytk'];
        }elseif($summary['to_ship']>0){
            $paused=$summary['to_ship']===$as['pending_cancel_quantity'];
            $out=['_type'=>1,'_title'=>$paused?'发货已暂停':($summary['shipped']>0?'部分发货':'未发货'),
                '_msg'=>$paused?'待发商品有取消申请，等待售后处理':'请查看包裹及售后详情了解发货进度','_class'=>'state-nfh'];
        }else{
            $out=['_type'=>2,'_title'=>'待收货','_msg'=>'请查看各包裹可收货数量及售后处理进度','_class'=>'state-ysh'];
        }
        $out['_payType']=self::detailPayType(self::text($root['pay_type']));return $out;
    }

    private static function listAftersaleStatus(array $root,array $inspection):array
    {
        $summary=$inspection['summary'];$as=$inspection['aftersale'];
        if($summary['closed'])return [5,['status_name'=>$summary['shipped']===0?'履约已取消':($summary['received']===$summary['shipped']?'已收货':'履约已终结'),'pics'=>[]]];
        if($summary['to_ship']>0)return [$summary['shipped']>0?8:2,['status_name'=>$summary['to_ship']===$as['pending_cancel_quantity']?'发货已暂停':($summary['shipped']>0?'部分发货':'未发货'),'pics'=>[]]];
        return [4,['status_name'=>'待收货','pics'=>[]]];
    }

    private static function displayChildAftersaleListShape(array $child):void
    {
        self::displayKeys($child,['schema','scope','id','order_id','root_order_id','root_order_no','marketplace_trade_version',
            'merchant_name_snapshot','shipping_type','carrier_code','carrier_name','tracking_no','shipped_at','receipt_due_at',
            'last_received_at','received_at','shipping_state','receipt_state','closed','original','shipped','received',
            'cancelled_unshipped','returned_accepted','to_ship','to_receive','items','payment_owner_order_id','paid','pay_type','pay_time','pay_type_name','aftersale']);
        if($child['schema']!==2||$child['scope']!=='marketplace_fulfillment_child_aftersale_v1'||$child['marketplace_trade_version']!==1
            ||$child['shipping_state']!=='shipped'||!in_array($child['receipt_state'],['pending','partial','received'],true)
            ||!is_bool($child['closed'])||$child['paid']!==1||$child['payment_owner_order_id']!==$child['root_order_id'])self::unavailable();
        foreach(['id','root_order_id','shipping_type','original','shipped','pay_time','shipped_at'] as$key)
            if(!is_int($child[$key])||$child[$key]<1)self::unavailable();
        foreach(['receipt_due_at','received','cancelled_unshipped','returned_accepted','to_ship','to_receive'] as$key)
            if(!is_int($child[$key])||$child[$key]<0)self::unavailable();
        foreach(['last_received_at','received_at'] as$key)if($child[$key]!==null&&(!is_int($child[$key])||$child[$key]<1))self::unavailable();
        foreach(['order_id','root_order_no','merchant_name_snapshot','carrier_code','carrier_name','tracking_no','pay_type','pay_type_name'] as$key)self::text($child[$key]);
        $as=$child['aftersale'];self::displayKeys($as,['schema','revision','confirmed_amount','held_amount','available_amount','pending_cancel_quantity','pending_return_quantity','returned_after_receipt','fulfillment_completed_at']);
        $summary=[];foreach(['shipping_state','receipt_state','closed','original','shipped','received','cancelled_unshipped','returned_accepted','to_ship','to_receive'] as$key)$summary[$key]=$child[$key];$summary['child_count']=1;
        self::rootWithAftersale(['total_num'=>$child['original']],$summary,['ship'=>false,'receive'=>false],$as);
        if($child['original']!==$child['shipped']||$child['to_ship']!==0||$child['cancelled_unshipped']!==0
            ||($child['received']===0)!==($child['last_received_at']===null)
            ||$child['received_at']!==($child['received']===$child['shipped']?$child['last_received_at']:null))self::unavailable();
        foreach(self::list($child['items']) as$item){
            $item=self::row($item);self::displayKeys($item,['id','product_id','product_name','sku_name','image','quantity','unit_price','line_price','postage_price']);
            foreach(['id','product_id','quantity'] as$key)if(!is_int($item[$key])||$item[$key]<1)self::unavailable();
            foreach(['product_name','sku_name','image'] as$key)self::text($item[$key]);
            foreach(['unit_price','line_price','postage_price'] as$key)self::merchantMoney($item[$key]);
        }
    }
    private static function rootFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension,string $view):array
    {
        return self::savedRootFromFacts($root,$fullItems,$facts,$options,$extension,$view,null);
    }

    private static function savedRootFromFacts(array $root,array $fullItems,array $facts,array $options,array $extension,string $view,?array $inspection):array
    {
        self::displayOptions($options,$view);
        self::displayKeys($facts,['buyer','payer','invoice','economize','reply_counts','store','refunds','status_logs','payment_deadline']);
        self::displayKeys($extension,['summary','actions','history_times','children']);
        self::displayKeys(self::row($extension['history_times']),['last_shipped_at','last_received_at','received_completed_at']);
        foreach($extension['history_times'] as$at)if($at!==null){if(!is_int($at))self::unavailable();self::merchantInteger($at,1,4294967295);}
        if($extension['actions']!==['ship'=>false,'receive'=>false])self::unavailable();
        foreach($root as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
        self::merchantInteger($root['marketplace_trade_version']??null,1,1);self::merchantInteger($root['pid']??null,0,0);
        foreach(['combination_id','pink_id','seckill_id','bargain_id','advance_id','virtual_type','is_gift'] as$key)
            self::merchantInteger($root[$key]??null,0,0);
        $detail=str_ends_with($view,'_detail');$customerStyle=$view==='customer_list'||$detail;
        $customerDetail=in_array($view,['customer_detail','mobile_detail'],true);
        $decoded=[];$commercial=self::checkedCommercialRoot($root,$fullItems,$detail,$decoded);
        if($extension['summary']!==null&&!is_array($extension['summary']))self::unavailable();
        if($inspection===null)self::rootWithFulfillment(['total_num'=>$root['total_num']],$extension['summary'],$extension['actions']);
        else self::rootWithAftersale(['total_num'=>$root['total_num']],$extension['summary'],$extension['actions'],$inspection['aftersale']);
        foreach(['buyer','payer','invoice','economize','reply_counts','store'] as$key)if($facts[$key]!==null)self::row($facts[$key]);
        self::list($facts['refunds']);self::list($facts['status_logs']);
        if($facts['payment_deadline']!==null&&(!is_int($facts['payment_deadline'])||$facts['payment_deadline']<0
            ||$facts['payment_deadline']>4294967295))self::unavailable();
        $saved=$root;if($detail)$saved['custom_form']=$commercial['custom_form'];
        $config=$options['config'];$carts=[];$wrappers=[];$seenReplies=[];$quantity=0;
        $vip='0.00';$level='0.00';$member='0.00';
        foreach($fullItems as$item){
            $itemId=self::merchantInteger($item['id'],1,2147483647);$cart=$decoded[$itemId];
            if(self::text($cart['id']??null)!==self::text($item['cart_id']??null))self::unavailable();
            if($customerStyle){
                if($facts['reply_counts']===null)self::unavailable();$reply=self::row($facts['reply_counts'][$itemId]??null);
                self::displayKeys($reply,['item_id','root_order_id','uid','mer_id','unique','reply_count']);
                foreach(['item_id'=>$itemId,'root_order_id'=>self::merchantInteger($root['id'],1,2147483647),
                    'uid'=>self::merchantInteger($root['uid'],1,2147483647),'mer_id'=>self::merchantInteger($root['mer_id'],1,4294967295)] as$key=>$expected)
                    if(self::displayUnsigned($reply[$key],4294967295)!==$expected)self::unavailable();
                if(self::text($reply['unique'])!==self::text($item['unique']))self::unavailable();
                $seenReplies[$itemId]=true;$cart['cart_num']=$item['cart_num'];$cart['surplus_num']=$item['surplus_num'];
                $cart['refund_num']=$item['refund_num'];$cart['surplus_refund_num']=self::merchantInteger($item['surplus_num'],0,2147483647)
                    -self::merchantInteger($item['refund_num'],0,2147483647);
                $cart['unique']=$item['unique'];$cart['is_reply']=self::displayUnsigned($reply['reply_count'],PHP_INT_MAX);
                $cart['vip_sum_truePrice']=bcmul(self::money($cart['vip_truePrice']??null),(string)self::merchantInteger($item['cart_num'],1,2147483647),2);
                $cart['is_valid']=1;
                if($view==='customer_list')$cart['productInfo']['price']=$cart['truePrice'];
            }else $cart['refund_num']=self::merchantInteger($item['refund_num'],0,2147483647);
            $quantity+=self::merchantInteger($cart['cart_num']??null,1,2147483647);
            $discount=$customerStyle?$cart['vip_sum_truePrice']:bcmul(self::money($cart['vip_truePrice']??null),(string)$cart['cart_num'],2);
            $vip=bcadd($vip,$discount,2);
            if(($cart['price_type']??null)==='level')$level=bcadd($level,$discount,2);
            if(($cart['price_type']??null)==='member')$member=bcadd($member,$discount,2);
            $carts[]=$cart;$wrappers[]=['cart_info'=>$cart];
        }
        if($customerStyle&&count($facts['reply_counts'])!==count($seenReplies))self::unavailable();
        if(!$customerStyle&&$facts['reply_counts']!==null)self::unavailable();
        $saved['cart_id']=self::displayCartIds($root['cart_id']??null);
        $saved['refund']=$facts['refunds'];$refundQuantity=0;
        foreach($facts['refunds'] as$refund)$refundQuantity+=self::merchantInteger($refund['refund_num']??null,0,2147483647);
        $saved['is_all_refund']=$view==='customer_list'?($facts['refunds']!==[]&&$refundQuantity===$quantity):$refundQuantity===$quantity;
        if($customerStyle){
            $saved['cartInfo']=$carts;
            $saved['_status']=$inspection===null?self::detailStatus($root,$facts,$options,$extension,$customerDetail):self::detailAftersaleStatus($root,$inspection,$options);
            $saved['_pay_time']=self::displayTime($root['pay_time']??0,'Y-m-d H:i:s',$options);
            $saved['_add_time']=self::displayTime($root['add_time'],'Y-m-d H:i:s',$options);
            if($detail){
                $saved['status_pic']='';
                foreach($options['status_images'] as$image){
                    $image=self::row($image);
                    if(isset($image['order_status'])&&$image['order_status']==$saved['_status']['_type']){
                        $saved['status_pic']=self::text($image['pic']??null);break;
                    }
                }
                $saved['offlinePayStatus']=$config['offline_pay_status'];
                $saved['vip_true_price']=$vip;$saved['levelPrice']=$level;$saved['memberPrice']=$member;
                $saved['invoice']=self::displayInvoice($facts['invoice'],$options);
            }
        }else{
            $saved['_info']=$wrappers;$saved['add_time']=self::displayTime($root['add_time'],'Y-m-d H:i:s',$options);
            $saved['_pay_time']=self::displayTime($root['pay_time']??0,'Y-m-d H:i:s',$options);
            $saved['_refund_time']=self::displayTime($root['refund_reason_time']??0,'Y-m-d H:i:s',$options);
            [$saved['_status'],$saved['status_name']]=$inspection===null?self::listStatus($root,$extension['summary']):self::listAftersaleStatus($root,$inspection);
            $saved['pay_type_name']=self::listPayType(self::text($root['pay_type']),self::merchantInteger($root['paid'],0,1)===1);
            [$saved['pink_name'],$saved['color']]=self::activityLabel($root);
            if($view==='platform_list')$saved['nickname']=$facts['buyer']===null?null:self::text($facts['buyer']['nickname']??null);
        }
        if($customerDetail){
            if($config['store_self_mention']==0)$saved['shipping_type']=1;
            $saved['add_time_y']=self::displayTime($root['add_time'],'Y-m-d',$options);
            $saved['add_time_h']=self::displayTime($root['add_time'],'H:i:s',$options);
            $saved['code']='';
            if(($root['verify_code']??'')!==''){
                $verify=self::text($root['verify_code']);$saved['_verify_code']=substr($verify,0,4).' '.substr($verify,4,4).' '.substr($verify,8);
            }
            $saved['system_store']=$facts['store']===null?false:$facts['store'];
            $saved['yue_pay_status']=(int)$config['balance_func_status']&&(int)$config['yue_pay_status']===1?1:2;
            $saved['pay_weixin_open']=$config['pay_weixin_open']!='0';$saved['ali_pay_status']=$config['ali_pay_status']!='0';
            $saved['friend_pay_status']=(int)$config['friend_pay_status'];$saved['routine_contact_type']=$config['routine_contact_type'];
            $saved['invoice_func']=$config['invoice_func_status'];$saved['special_invoice']=$config['invoice_func_status']&&$config['special_invoice_status'];
            $saved['postage_price']=$facts['economize']===null?0:$facts['economize']['postage_price'];
            $saved['member_price']=$facts['economize']===null?0:$facts['economize']['member_price'];
            $saved['refund_cartInfo']=$carts;$saved['refund_total_num']=$root['total_num'];$saved['refund_pay_price']=$root['pay_price'];
            $saved['is_apply_refund']=true;$saved['help_info']=['help_status'=>0,'pay_nickname'=>'','pay_avatar'=>''];
            if(self::merchantInteger($root['uid'],1,2147483647)!==self::merchantInteger($root['pay_uid']??null,-2147483648,2147483647)){
                if($facts['payer']===null)self::unavailable();$saved['help_info']=['help_status'=>1,
                    'pay_nickname'=>self::text($facts['payer']['nickname']??null),'pay_avatar'=>self::text($facts['payer']['avatar']??null)];
            }
            $saved['nickname']=$facts['buyer']===null?null:self::text($facts['buyer']['nickname']??null);
            $saved['avatar']=$facts['buyer']===null?null:self::text($facts['buyer']['avatar']??null);
            $saved['order_shipping_open']=false;
            $days=(int)$config['refund_time_available'];$received=$extension['history_times']['received_completed_at'];
            $saved['is_refund_available']=$days===0||$received===null||$received+$days*86400>=$options['now'];
        }
        $out=$detail?($view==='platform_detail'?self::platformOrder($saved):self::customerOrder($saved)):
            self::orderListRow($saved,$view==='customer_list'?'customer':($view==='platform_list'?'platform':'mobile'));
        $out=$inspection===null?self::rootWithFulfillment($out,$extension['summary'],$extension['actions']):
            self::rootWithAftersale($out,$extension['summary'],$extension['actions'],$inspection['aftersale']);
        if($detail){
            $out['order_log']=self::displayOrderLog($root,$facts['status_logs'],$extension['history_times'],$options);
            $out['fulfillment_children']=self::displayChildren($root,$extension['children'],$extension['summary'],$inspection);
        }elseif($extension['children']!==null)self::unavailable();
        return $out;
    }

    private static function detailStatus(array $root,array $facts,array $options,array $extension,bool $customerDetail):array
    {
        $paid=self::merchantInteger($root['paid'],0,1);$cancel=self::merchantInteger($root['is_cancel'],0,1);
        $status=self::merchantInteger($root['status'],-128,127);$type=self::text($root['pay_type']);$summary=$extension['summary'];
        if($cancel)$out=['_type'=>4,'_title'=>'已取消','_msg'=>'订单已取消','_class'=>'nobuy'];
        elseif(!$paid&&$type==='offline'&&!$status>=2)$out=['_type'=>9,'_title'=>'线下付款,未支付','_msg'=>'等待商家处理,请耐心等待','_class'=>'nobuy'];
        elseif(!$paid){
            $config=$options['config'];$hours=$config['order_cancel_time'];
            if(($root['pink_id']??0)||($root['combination_id']??0))$hours=$config['order_pink_time']?:$config['order_activity_time'];
            elseif($root['seckill_id']??0)$hours=$config['order_seckill_time']?:$config['order_activity_time'];
            elseif($root['bargain_id']??0)$hours=$config['order_bargain_time']?:$config['order_activity_time'];
            $out=['_type'=>0,'_title'=>'未支付','_msg'=>'请在'.self::displayTimestamp((int)($root['add_time']+$hours*3600),'m-d H:i:s',$options).'前完成支付!','_class'=>'nobuy'];
            if($customerDetail&&$type!=='offline'){
                if($facts['payment_deadline']===null)self::unavailable();
                $out['_msg']=$facts['payment_deadline']===0?'订单尚未支付，请完成支付':
                    '请在'.self::displayTime($facts['payment_deadline'],'m-d H:i:s',$options).'前完成支付!';
            }
        }elseif($summary!==null&&$summary['shipping_state']==='partial'){
            $out=['_type'=>1,'_title'=>'部分发货','_msg'=>self::shippedMessage($extension['history_times']['last_shipped_at'],$options,'服务商已部分发货'),'_class'=>'state-ysh'];
        }elseif($status===0){
            if(self::merchantInteger($root['shipping_type'],1,2)===1)$out=['_type'=>1,'_title'=>'未发货','_msg'=>'商家未发货,请耐心等待','_class'=>'state-nfh'];
            else $out=['_type'=>1,'_title'=>'待核销','_msg'=>'待核销,请到核销点进行核销','_class'=>'state-nfh'];
        }elseif($status===1){
            $out=['_type'=>2,'_title'=>'待收货','_msg'=>self::shippedMessage($extension['history_times']['last_shipped_at'],$options,'服务商已发货'),'_class'=>'state-ysh'];
        }elseif($status===2)$out=['_type'=>3,'_title'=>'已收货','_msg'=>'已收货,感谢您的支持','_class'=>'state-ypj'];
        elseif($status===3)$out=['_type'=>4,'_title'=>'交易完成','_msg'=>'交易完成,感谢您的支持','_class'=>'state-ytk'];
        else self::unavailable();
        if(($out['_type']!==0||!$customerDetail||$type==='offline')&&$facts['payment_deadline']!==null)self::unavailable();
        $out['_payType']=$out['_type']===0?'':self::detailPayType($type);return $out;
    }

    private static function listStatus(array $root,?array $summary):array
    {
        $paid=self::merchantInteger($root['paid'],0,1);$status=self::merchantInteger($root['status'],-128,127);
        if(!$paid&&$status===0)return [1,['status_name'=>self::merchantInteger($root['is_cancel'],0,1)===0?'未支付':'已取消','pics'=>[]]];
        if($paid&&$summary!==null&&$summary['shipping_state']==='partial')return [8,['status_name'=>'部分发货','pics'=>[]]];
        if($paid&&$status===0)return [2,['status_name'=>self::merchantInteger($root['shipping_type'],1,2)===1?'未发货':'未核销','pics'=>[]]];
        if($paid&&$status===1)return [4,['status_name'=>self::merchantInteger($root['shipping_type'],1,2)===1?'待收货':'未核销','pics'=>[]]];
        if($paid&&$status===2)return [5,['status_name'=>'已收货','pics'=>[]]];
        if($paid&&$status===3)return [6,['status_name'=>'已完成','pics'=>[]]];
        self::unavailable();
    }

    private static function activityLabel(array $root):array
    {
        if($root['combination_id']??0)return ['[拼团订单]','#FF7D00'];
        if($root['seckill_id']??0)return ['[秒杀订单]','#3491FA'];
        if($root['bargain_id']??0)return ['[砍价订单]','#F7BA1E'];
        if($root['advance_id']??0)return ['[预售订单]','#B27FEB'];
        return self::merchantInteger($root['shipping_type'],1,2)===1?['[普通订单]','#333']:['[核销订单]','#8956E8'];
    }

    private static function listPayType(string $type,bool $paid):string
    {
        if(!$paid)return $type==='offline'?'线下支付':'';
        return ['weixin'=>'微信支付','yue'=>'余额支付','offline'=>'线下支付','alipay'=>'支付宝支付','allinpay'=>'通联支付',
            'marketplace_test'=>'测试支付'][$type]??'其他支付';
    }

    private static function detailPayType(string $type):string
    {
        return ['weixin'=>'微信支付','yue'=>'余额支付','offline'=>'线下支付','alipay'=>'支付宝','friend'=>'好友代付',
            'allinpay'=>'通联支付','bank'=>'银行转账','marketplace_test'=>'测试支付'][$type]??'其他方式';
    }

    private static function shippedMessage(?int $at,array $options,string $message):string
    {
        return ($at===null?'':self::displayTime($at,'m月d日H时i分',$options)).$message;
    }

    private static function displayOrderLog(array $root,array $logs,array $times,array $options):array
    {
        $column=[];
        foreach($logs as$log){
            $log=self::row($log);self::displayKeys($log,['oid','change_type','change_message','change_time']);
            if(self::merchantInteger($log['oid'],1,2147483647)!==self::merchantInteger($root['id'],1,2147483647))self::unavailable();
            $key=self::text($log['change_type']);self::text($log['change_message']);
            $column[$key]=self::merchantInteger($log['change_time'],0,4294967295);
        }
        return ['create'=>self::displayLogTime($column['cache_key_create_order']??null,$options),
            'pay'=>self::displayLogTime($column['pay_success']??null,$options),
            'delivery'=>self::displayTime($times['last_shipped_at']??0,'Y-m-d',$options),
            'take'=>self::displayTime($times['received_completed_at']??0,'Y-m-d',$options),
            'complete'=>self::displayLogTime($column['check_order_over']??null,$options)];
    }

    /** Missing old events differ from a genuinely saved zero timestamp. */
    private static function displayLogTime(?int $at,array $options):string
    {
        if($at===null)return '';
        return self::displayTimestamp($at,'Y-m-d',$options);
    }

    private static function displayInvoice(?array $invoice,array $options):?array
    {
        if($invoice===null)return null;
        $time=$invoice['add_time']??null;
        if($time===null)self::unavailable();
        if(!is_string($time)&&!is_int($time))self::unavailable();
        $invoice['add_time']=empty($time)?'':(is_string($time)?$time:self::displayTimestamp($time,'Y-m-d H:i:s',$options));
        return $invoice;
    }

    private static function displayChildren(array $root,?array $children,?array $summary,?array $inspection=null):?array
    {
        if($children===null)return null;
        if($summary===null)self::unavailable();self::displayKeys($children,['page','limit','count','list']);
        foreach(['page'=>1,'limit'=>1,'count'=>0] as$key=>$minimum)
            if(!is_int($children[$key])||$children[$key]<$minimum)self::unavailable();
        if($children['limit']>100||$children['count']!==$summary['child_count'])self::unavailable();
        $list=self::list($children['list']);$offset=$children['page']-1>intdiv(PHP_INT_MAX,$children['limit'])?PHP_INT_MAX:($children['page']-1)*$children['limit'];
        if(count($list)!==max(0,min($children['limit'],$children['count']-$offset)))self::unavailable();
        $seen=[];foreach($list as$child){
            $child=self::row($child);if($inspection===null)self::displayChildListShape($child);else{self::displayChildAftersaleListShape($child);if($child['aftersale']['revision']!==$inspection['last_action_id'])self::unavailable();}
            if(isset($seen[$child['id']]))self::unavailable();$seen[$child['id']]=true;
            if($child['root_order_id']!==self::merchantInteger($root['id'],1,2147483647)
                ||$child['root_order_no']!==self::text($root['order_id']))self::unavailable();
        }
        return $children;
    }

    /** Recheck the closed public child envelope rather than forwarding arbitrary nested arrays. */
    private static function displayChildListShape(array $child):void
    {
        self::displayKeys($child,['schema','scope','id','order_id','root_order_id','root_order_no','marketplace_trade_version',
            'merchant_name_snapshot','shipping_type','carrier_code','carrier_name','tracking_no','shipped_at','receipt_due_at',
            'last_received_at','received_at','shipping_state','receipt_state','closed','original','shipped','received',
            'cancelled_unshipped','returned_accepted','to_ship','to_receive','items','payment_owner_order_id','paid','pay_type','pay_time','pay_type_name']);
        if($child['schema']!==1||$child['scope']!=='marketplace_fulfillment_child_v1'||$child['marketplace_trade_version']!==1
            ||$child['shipping_state']!=='shipped'||!in_array($child['receipt_state'],['pending','partial','received'],true)
            ||!is_bool($child['closed'])||$child['paid']!==1||$child['payment_owner_order_id']!==$child['root_order_id'])self::unavailable();
        foreach(['id','root_order_id','shipping_type','original','shipped','pay_time','shipped_at'] as$key)
            if(!is_int($child[$key])||$child[$key]<1)self::unavailable();
        foreach(['receipt_due_at','received','cancelled_unshipped','returned_accepted','to_ship','to_receive'] as$key)
            if(!is_int($child[$key])||$child[$key]<0)self::unavailable();
        foreach(['last_received_at','received_at'] as$key)if($child[$key]!==null&&(!is_int($child[$key])||$child[$key]<1))self::unavailable();
        foreach(['order_id','root_order_no','merchant_name_snapshot','carrier_code','carrier_name','tracking_no','pay_type','pay_type_name'] as$key)self::text($child[$key]);
        if($child['original']!==$child['shipped']||$child['received']>$child['shipped']||$child['to_ship']!==0
            ||$child['cancelled_unshipped']!==0||$child['returned_accepted']!==0
            ||$child['to_receive']!==$child['shipped']-$child['received']
            ||$child['closed']!==($child['received']===$child['shipped'])
            ||$child['receipt_state']!==($child['received']===0?'pending':($child['received']===$child['shipped']?'received':'partial'))
            ||($child['received']===0)!==($child['last_received_at']===null)
            ||$child['received_at']!==($child['received']===$child['shipped']?$child['last_received_at']:null))self::unavailable();
        foreach(self::list($child['items']) as$item){
            $item=self::row($item);self::displayKeys($item,['id','product_id','product_name','sku_name','image','quantity','unit_price','line_price','postage_price']);
            foreach(['id','product_id','quantity'] as$key)if(!is_int($item[$key])||$item[$key]<1)self::unavailable();
            foreach(['product_name','sku_name','image'] as$key)self::text($item[$key]);
            foreach(['unit_price','line_price','postage_price'] as$key)self::merchantMoney($item[$key]);
        }
    }

    private static function displayOptions(array $options,?string $view):void
    {
        self::displayKeys($options,['now','timezone','config','status_images']);
        if(!is_int($options['now']))self::unavailable();self::merchantInteger($options['now'],1,4294967295);
        $timezone=self::text($options['timezone']);
        try{new \DateTimeZone($timezone);}catch(\Exception $error){self::unavailable();}
        $config=self::row($options['config']);self::list($options['status_images']);
        if($view===null)return;
        $keys=in_array($view,['platform_list','mobile_list'],true)?[]:
            ['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time'];
        if(str_ends_with($view,'_detail'))$keys[]='offline_pay_status';
        if(in_array($view,['customer_detail','mobile_detail'],true))$keys=array_merge($keys,
            ['store_self_mention','balance_func_status','yue_pay_status','pay_weixin_open','ali_pay_status','friend_pay_status',
                'routine_contact_type','invoice_func_status','special_invoice_status','refund_time_available']);
        self::displayKeys($config,$keys);
        foreach($config as$key=>$value){
            if(in_array($key,['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time'],true)){
                if((!is_int($value)&&!is_float($value))||(is_float($value)&&!is_finite($value)))self::unavailable();
            }elseif($value!==null&&!is_int($value)&&!is_string($value)&&!is_bool($value))self::unavailable();
        }
        if(array_key_exists('offline_pay_status',$config)&&!is_int($config['offline_pay_status']))self::unavailable();
        if(array_key_exists('invoice_func_status',$config)&&!is_bool($config['invoice_func_status']))self::unavailable();
    }

    private static function displayCartIds(mixed $raw):array
    {
        if(!is_string($raw)||strlen($raw)>65535)self::unavailable();
        try{$ids=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException $error){self::unavailable();}
        $ids=self::list($ids);foreach($ids as$id)self::text($id);return $ids;
    }

    private static function displayTime(mixed $value,string $format,array $options):string
    {
        $at=self::merchantInteger($value,0,4294967295);if($at===0)return '';
        return self::displayTimestamp($at,$format,$options);
    }

    /** Original display arithmetic and the signed invoice accessor are not saved UINT deadline validation. */
    private static function displayTimestamp(int $at,string $format,array $options):string
    {
        return (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone($options['timezone']))->format($format);
    }

    private static function displayUnsigned(mixed $value,int $maximum):int
    {
        if(!is_int($value)&&!is_string($value))self::unavailable();$text=(string)$value;
        if(preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)!==1||strlen($text)>strlen((string)$maximum)
            ||(strlen($text)===strlen((string)$maximum)&&strcmp($text,(string)$maximum)>0))self::unavailable();
        return (int)$text;
    }

    private static function displayKeys(array $value,array $keys):void
    {
        if(count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[]||array_diff($keys,array_keys($value))!==[])self::unavailable();
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
