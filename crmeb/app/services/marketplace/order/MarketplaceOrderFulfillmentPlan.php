<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\AuthUnavailableException;

/** Pure allocation of saved V1 amounts. No authorization, current catalog, payment or persistence. */
final class MarketplaceOrderFulfillmentPlan
{
    private const INT_MAX = 2147483647;
    private const UINT_MAX = 4294967295;
    private const U64_MAX = '18446744073709551615';
    private const AMOUNTS = ['goods','postage','origin_postage','cost','base_goods','discount'];

    /**
     * All rows are the caller's complete, actual saved root/items/original reserves.
     * Occupied ranges refer to original item quantities, never to a remainder's quantity.
     * The caller must authorize these rows and replan under its real write locks before persistence.
     *
     * @param array $occupiedRanges list<{source_item_id:int|string,unit_start:int|string,unit_end:int|string}>
     * @param array $requestedQuantities list<{source_item_id:int|string,quantity:int|string}>
     * @return array{plan_version:int,root_order_id:int,source_snapshot_sha256:string,allocations:array,totals:array}
     */
    public static function plan(array $savedRoot,array $savedItems,array $savedReserves,array $occupiedRanges,array $requestedQuantities):array
    {
        $source=self::source($savedRoot,$savedItems);
        self::reserves($source,$savedReserves);
        $occupied=self::ranges($source['lines'],$occupiedRanges);
        if(!array_is_list($requestedQuantities)||!$requestedQuantities||count($requestedQuantities)>count($source['lines']))self::unavailable();
        $requests=[];
        foreach($requestedQuantities as $request){
            self::keys($request,['source_item_id','quantity']);
            $id=self::integer($request['source_item_id'],1,self::INT_MAX);
            if(!isset($source['lines'][$id])||isset($requests[$id]))self::unavailable();
            $requests[$id]=self::integer($request['quantity'],1,$source['lines'][$id]['original_quantity']);
        }
        ksort($requests,SORT_NUMERIC);$allocations=[];
        foreach($requests as $id=>$remaining){
            $line=$source['lines'][$id];$cursor=0;
            foreach($occupied[$id]??[] as $range){
                if($remaining===0)break;
                if($cursor<$range['unit_start']){
                    $end=$cursor+min($remaining,$range['unit_start']-$cursor);
                    $allocations[]=self::allocation($line,$cursor,$end);$remaining-=$end-$cursor;
                }
                $cursor=$range['unit_end'];
            }
            if($remaining>0&&$cursor<$line['original_quantity']){
                $end=$cursor+min($remaining,$line['original_quantity']-$cursor);
                $allocations[]=self::allocation($line,$cursor,$end);$remaining-=$end-$cursor;
            }
            if($remaining!==0)self::unavailable();
        }
        return ['plan_version'=>1,'root_order_id'=>$source['root']['id'],
            'source_snapshot_sha256'=>$source['fingerprint'],'allocations'=>$allocations,'totals'=>self::totals($allocations)];
    }

    /**
     * Exact integer-cent prefix slice F(end)-F(start), with the ORIGINAL quantity as denominator.
     * Monetary budgets may be derived and exceed saved DECIMAL(12,2) and native integer capacity.
     * mixed parameters deliberately prevent weak callers silently coercing floats/bools before validation.
     */
    public static function sliceMoney(mixed $savedLineAmount,mixed $originalQuantity,mixed $unitStart,mixed $unitEnd):string
    {
        $amount=self::amount($savedLineAmount,false);
        $quantity=self::integer($originalQuantity,1,self::INT_MAX);
        $start=self::integer($unitStart,0,$quantity);$end=self::integer($unitEnd,$start,$quantity);
        $cents=bcmul($amount,'100',0);$quotient=bcdiv($cents,(string)$quantity,0);$remainder=bcmod($cents,(string)$quantity,0);
        $prefix=static function(int $position)use($quotient,$remainder):string{
            $extra=bccomp((string)$position,$remainder,0)<=0?(string)$position:$remainder;
            return bcadd(bcmul($quotient,(string)$position,0),$extra,0);
        };
        return bcdiv(bcsub($prefix($end),$prefix($start),0),'100',2);
    }

    /**
     * @internal Shared pure consumer for SnapshotProjection, already restricted to public fields.
     * Rechecks the COMPLETE saved source, every interval/amount and totals; a matching hash alone is insufficient.
     * This cannot prove live occupancy, reserve state or authorization, and is not a persistent idempotency check.
     */
    public static function savedProjection(array $savedRoot,array $savedItems,array $plan):array
    {
        $source=self::source($savedRoot,$savedItems);
        self::keys($plan,['plan_version','root_order_id','source_snapshot_sha256','allocations','totals']);
        if($plan['plan_version']!==1||$plan['root_order_id']!==$source['root']['id']
            ||$plan['source_snapshot_sha256']!==$source['fingerprint']
            ||!is_array($plan['allocations'])||!array_is_list($plan['allocations'])||!$plan['allocations'])self::unavailable();
        $ranges=[];$lastId=0;$lastEnd=0;$selected=[];
        foreach($plan['allocations'] as $allocation){
            if(!is_array($allocation))self::unavailable();
            $id=self::integer($allocation['source_item_id']??null,1,self::INT_MAX);
            if(!isset($source['lines'][$id]))self::unavailable();$line=$source['lines'][$id];
            $start=self::integer($allocation['unit_start']??null,0,$line['original_quantity']-1);
            $end=self::integer($allocation['unit_end']??null,$start+1,$line['original_quantity']);
            if($id<$lastId||($id===$lastId&&$start<$lastEnd))self::unavailable();
            $expected=self::allocation($line,$start,$end);
            self::keys($allocation,array_keys($expected));
            foreach($expected as $key=>$value)if($allocation[$key]!==$value)self::unavailable();
            $ranges[]=$expected;$lastId=$id;$lastEnd=$end;
            if(!isset($selected[$id])){
                $item=$source['public_items'][$id];$item['quantity']=0;$item['line_price']='0.00';$item['postage_price']='0.00';
                $selected[$id]=$item;
            }
            $selected[$id]['quantity']+=$expected['quantity'];
            $selected[$id]['line_price']=bcadd($selected[$id]['line_price'],$expected['goods'],2);
            $selected[$id]['postage_price']=bcadd($selected[$id]['postage_price'],$expected['postage'],2);
        }
        $totals=self::totals($ranges);self::keys($plan['totals'],array_keys($totals));
        foreach($totals as $key=>$value)if($plan['totals'][$key]!==$value)self::unavailable();
        $products=array_values(array_unique(array_column($selected,'product_id'),SORT_NUMERIC));
        $forms=[];foreach($source['forms'] as $group)if(in_array($group['product_id'],$products,true))$forms[]=$group;
        return ['plan_version'=>1,'root_order_id'=>$source['root']['id'],'order_id'=>$source['root']['order_id'],
            'marketplace_trade_version'=>1,'total_num'=>$totals['quantity'],'total_price'=>$totals['goods'],
            'pay_postage'=>$totals['postage'],'pay_price'=>$totals['payable'],'items'=>array_values($selected),'custom_form'=>$forms];
    }

    private static function source(array $root,array $items):array
    {
        // Never pass a requested subset to this full-order conservation check.
        $public=MarketplaceOrderSnapshotProjection::merchantOrder($root,$items);
        $identity=['id'=>$public['id'],'order_id'=>$public['order_id'],
            'uid'=>self::integer($root['uid']??null,1,self::INT_MAX),'mer_id'=>self::integer($root['mer_id']??null,1,self::UINT_MAX),
            'total_num'=>$public['total_num'],'total_price'=>$public['total_price'],'pay_price'=>$public['pay_price'],
            'pay_postage'=>$public['pay_postage'],'total_postage'=>self::amount($root['total_postage']??null),
            'cost'=>self::amount($root['cost']??null)];
        if($identity['total_postage']!==$identity['pay_postage'])self::unavailable();
        $publicItems=[];foreach($public['items'] as $item)$publicItems[$item['id']]=$item;
        $lines=[];$references=[];$products=[];$cost='0.00';
        foreach($items as $item){
            $id=self::integer($item['id']??null,1,self::INT_MAX);$display=$publicItems[$id];
            try{$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
            catch(\JsonException $error){self::unavailable();}
            $sku=$cart['productInfo']['attrInfo'];$lineage=$cart['marketplace']??null;
            self::keys($lineage,['cart_ref','sku_id','sku_type','sku_unique','reserve_id']);
            $ref=self::text($item['cart_id']??null,50,true);
            if(($cart['id']??null)!==$ref||$lineage['cart_ref']!==$ref||isset($references[$ref]))self::unavailable();
            $skuId=self::integer($sku['id']??null,1,self::INT_MAX);$unique=self::skuUnique($sku['unique']??null);
            if(self::integer($sku['type']??null,0,0)!==0||self::integer($lineage['sku_id'],1,self::INT_MAX)!==$skuId
                ||self::integer($lineage['sku_type'],0,0)!==0||$lineage['sku_unique']!==$unique)self::unavailable();
            if(!is_string($lineage['reserve_id']))self::unavailable();
            $reserve=self::reserveId($lineage['reserve_id']);$quantity=$display['quantity'];
            $unitBase=self::amount($cart['sum_price']??null);$unitCost=self::amount($cart['costPrice']??null);
            $unitDiscount=self::amount($cart['vip_truePrice']??null);$origin=self::amount($cart['origin_postage_price']??null);
            if($origin!==$display['postage_price']||bcadd($display['unit_price'],$unitDiscount,2)!==$unitBase
                ||self::amount($sku['price']??null)!==$unitBase
                ||self::amount($sku['cost']??null)!==$unitCost)self::unavailable();
            $priceType=$cart['price_type']??null;if(!in_array($priceType,['level','member'],true))self::unavailable();
            $line=['source_item_id'=>$id,'product_id'=>$display['product_id'],'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$unique,
                'cart_ref'=>$ref,'reserve_id'=>$reserve,'original_quantity'=>$quantity,'unit_price'=>$display['unit_price'],
                'price_type'=>$priceType,'goods'=>$display['line_price'],'postage'=>$display['postage_price'],'origin_postage'=>$origin,
                'cost'=>bcmul($unitCost,(string)$quantity,2),'base_goods'=>bcmul($unitBase,(string)$quantity,2),
                'discount'=>bcmul($unitDiscount,(string)$quantity,2)];
            $lines[$id]=$line;$references[$ref]=true;$products[$display['product_id']]=true;$cost=bcadd($cost,$line['cost'],2);
        }
        if($cost!==$identity['cost'])self::unavailable();
        ksort($lines,SORT_NUMERIC);ksort($publicItems,SORT_NUMERIC);
        $forms=MarketplaceOrderSnapshotProjection::customForms($root['custom_form']??null);
        foreach($forms as $group)if(!isset($products[$group['product_id']]))self::unavailable();
        // Only immutable saved source; mutable paid/status/refund/surplus flags are not reinterpreted here.
        $fingerprint=hash('sha256',MarketplaceOrderInput::canonical(['root'=>$identity,'items'=>array_values($lines),
            'display'=>array_values($publicItems),'custom_form'=>$forms]));
        return ['root'=>$identity,'lines'=>$lines,'public_items'=>$publicItems,'forms'=>$forms,'fingerprint'=>$fingerprint];
    }

    private static function reserves(array $source,array $reserves):void
    {
        if(!array_is_list($reserves)||!$reserves||count($reserves)>count($source['lines']))self::unavailable();
        $groups=[];$skuReserves=[];
        foreach($source['lines'] as $line){
            $key='r:'.$line['reserve_id'];$skuKey='s:'.$line['sku_id'];
            if(isset($skuReserves[$skuKey])&&$skuReserves[$skuKey]!==$line['reserve_id'])self::unavailable();
            $skuReserves[$skuKey]=$line['reserve_id'];
            if(isset($groups[$key])){
                foreach(['product_id','sku_id','sku_type','sku_unique'] as $field)if($groups[$key]['line'][$field]!==$line[$field])self::unavailable();
                $groups[$key]['quantity']+=$line['original_quantity'];
                self::integer($groups[$key]['quantity'],1,self::UINT_MAX);
            }else $groups[$key]=['line'=>$line,'quantity'=>$line['original_quantity']];
        }
        $seen=[];$root=$source['root'];
        foreach($reserves as $reserve){
            if(!is_array($reserve))self::unavailable();$id=self::reserveId($reserve['id']??null);$key='r:'.$id;
            if(isset($seen[$key])||!isset($groups[$key]))self::unavailable();$group=$groups[$key];$line=$group['line'];
            foreach(['mer_id','order_id','product_id','sku_id','sku_type','qty','actor_id'] as $field){
                $expected=match($field){'mer_id'=>$root['mer_id'],'order_id'=>$root['id'],'qty'=>$group['quantity'],
                    'actor_id'=>$root['uid'],default=>$line[$field]};
                $maximum=match($field){'mer_id','qty'=>self::UINT_MAX,'product_id'=>8388607,default=>self::INT_MAX};
                if(self::integer($reserve[$field]??null,$field==='sku_type'?0:1,$maximum)!==$expected)self::unavailable();
            }
            if(($reserve['kind']??null)!=='reserve'||($reserve['actor_type']??null)!=='customer'
                ||($reserve['sku_unique']??null)!==$line['sku_unique'])self::unavailable();
            foreach(['parent_reserve_id','order_item_id','refund_id'] as $field)
                if(!array_key_exists($field,$reserve)||$reserve[$field]!==null)self::unavailable();
            $seen[$key]=true;
        }
        if(count($seen)!==count($groups))self::unavailable();
    }

    private static function ranges(array $lines,array $ranges):array
    {
        if(!array_is_list($ranges))self::unavailable();$out=[];
        foreach($ranges as $range){
            self::keys($range,['source_item_id','unit_start','unit_end']);
            $id=self::integer($range['source_item_id'],1,self::INT_MAX);
            if(!isset($lines[$id]))self::unavailable();$quantity=$lines[$id]['original_quantity'];
            $start=self::integer($range['unit_start'],0,$quantity-1);$end=self::integer($range['unit_end'],$start+1,$quantity);
            $out[$id][]=['unit_start'=>$start,'unit_end'=>$end];
        }
        foreach($out as &$itemRanges){
            usort($itemRanges,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);$end=0;
            foreach($itemRanges as $range){if($range['unit_start']<$end)self::unavailable();$end=$range['unit_end'];}
        }unset($itemRanges);
        return $out;
    }

    private static function allocation(array $line,int $start,int $end):array
    {
        $out=[];foreach(['source_item_id','product_id','sku_id','sku_type','sku_unique','cart_ref','reserve_id','original_quantity','unit_price','price_type'] as $field)$out[$field]=$line[$field];
        $out['unit_start']=$start;$out['unit_end']=$end;$out['quantity']=$end-$start;
        foreach(self::AMOUNTS as $field)$out[$field]=self::sliceMoney($line[$field],$line['original_quantity'],$start,$end);
        $out['payable']=bcadd($out['goods'],$out['postage'],2);
        return $out;
    }

    private static function totals(array $allocations):array
    {
        $totals=['quantity'=>0,'goods'=>'0.00','postage'=>'0.00','origin_postage'=>'0.00','payable'=>'0.00',
            'cost'=>'0.00','base_goods'=>'0.00','discount'=>'0.00','level_discount'=>'0.00','member_discount'=>'0.00'];
        foreach($allocations as $allocation){
            $totals['quantity']+=$allocation['quantity'];self::integer($totals['quantity'],1,self::UINT_MAX);
            foreach(self::AMOUNTS as $field)$totals[$field]=bcadd($totals[$field],$allocation[$field],2);
            $bucket=$allocation['price_type']==='level'?'level_discount':'member_discount';
            $totals[$bucket]=bcadd($totals[$bucket],$allocation['discount'],2);
        }
        $totals['payable']=bcadd($totals['goods'],$totals['postage'],2);
        return $totals;
    }

    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        if((!is_int($value)&&!is_string($value))||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)!==1
            ||bccomp((string)$value,(string)$minimum,0)<0||bccomp((string)$value,(string)$maximum,0)>0)self::unavailable();
        return (int)$value;
    }

    private static function amount(mixed $value,bool $saved=true):string
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D',$value)!==1
            ||($saved&&bccomp($value,'9999999999.99',2)>0))self::unavailable();
        return $value;
    }

    private static function reserveId(mixed $value):string
    {
        // Native PDO may return a small BIGINT as int; normalize it without ever narrowing a U64 string.
        if(is_int($value)&&$value>0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||bccomp($value,self::U64_MAX,0)>0)self::unavailable();
        return $value;
    }

    private static function skuUnique(mixed $value):string
    {
        if(!is_string($value)||preg_match('/\A[A-Za-z0-9]{8}\z/D',$value)!==1)self::unavailable();return $value;
    }

    private static function text(mixed $value,int $maximum,bool $nonempty=false):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||str_contains($value,"\0")||strlen($value)>$maximum
            ||($nonempty&&$value===''))self::unavailable();return $value;
    }

    private static function keys(mixed $value,array $keys):void
    {
        if(!is_array($value))self::unavailable();$actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
        if($actual!==$keys)self::unavailable();
    }

    private static function unavailable():never { throw new AuthUnavailableException(); }
}
