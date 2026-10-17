<?php
declare(strict_types=1);

use app\services\marketplace\order\MarketplaceOrderFulfillmentPlan as FulfillmentNormalPlan;
use app\services\marketplace\order\MarketplaceOrderSnapshotProjection as FulfillmentNormalProjection;
use think\facade\Db as FulfillmentNormalDb;

/** Compare exact public/internal shapes as well as values; private extras cannot pass. */
function m4fp_same(mixed $actual,mixed $expected): void
{
    if(!is_array($expected)){m4o_expect($actual===$expected,'FULFILLMENT_EXACT_VALUE');return;}
    m4o_expect(is_array($actual)&&array_is_list($actual)===array_is_list($expected),'FULFILLMENT_EXACT_ARRAY_KIND');
    $keys=array_keys($actual);$wanted=array_keys($expected);sort($keys,SORT_STRING);sort($wanted,SORT_STRING);
    m4o_expect($keys===$wanted,'FULFILLMENT_EXACT_FIELD_SET');
    foreach($expected as $key=>$value)m4fp_same($actual[$key],$value);
}

/** Only the existing small saved fixtures enter this native-cent oracle, never arbitrary derived budgets. */
function m4fp_cents(mixed $value): int
{
    m4o_expect(PHP_INT_SIZE===8&&is_string($value)
        &&preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)===1,'FULFILLMENT_SAVED_DECIMAL_CENTS');
    // Saved DECIMAL(12,2) has at most 12 integer cents digits; Q is separately proved to be 1 or 2.
    return (int)str_replace('.','',$value);
}

function m4fp_money(int $cents): string
{
    m4o_expect($cents>=0&&$cents<=5*999999999999,'FULFILLMENT_BOUNDED_FIXTURE_CENTS');
    return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
}

/** Read complete original saved rows by the five existing proof PKs, not a fabricated business snapshot. */
function m4fp_saved(PDO $pdo,int $id): array
{
    $saved=m4pc_order($pdo,$id,1);
    $saved['reserves']=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? AND `kind` = ? ORDER BY `id`',[$id,'reserve']);
    m4o_expect(count($saved['items'])===3&&count($saved['reserves'])===2
        &&m4pc_integer($saved['order']['pid'],0,0)===0&&m4pc_integer($saved['order']['paid'],0,0)===0
        &&m4pc_integer($saved['order']['is_cancel'],0,0)===0,'FULFILLMENT_ORIGINAL_UNPAID_SAVED_FIXTURE');
    return $saved;
}

/** Independent per-unit enumeration is intentionally limited to the actual Q=2/1/2 fixtures. */
function m4fp_oracle(array $saved): array
{
    $root=$saved['order'];$lines=[];$groups=[];$quantities=[];
    foreach($saved['items'] as $item){
        $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        $id=m4pc_integer($item['id'],1);$q=m4pc_integer($item['cart_num'],1,2);$quantities[]=$q;
        $product=$cart['productInfo'];$sku=$product['attrInfo'];$lineage=$cart['marketplace'];
        $reserve=$lineage['reserve_id'];
        m4o_expect(is_string($reserve)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$reserve)===1
            &&bccomp($reserve,'18446744073709551615',0)<=0,'FULFILLMENT_SAVED_RESERVE_U64_TEXT');
        $productId=m4pc_integer($item['product_id'],1,8388607);$skuId=m4pc_integer($lineage['sku_id'],1);
        $priceType=$cart['price_type'];
        m4o_expect(in_array($priceType,['level','member'],true)&&is_string($lineage['sku_unique'])
            &&m4pc_integer($lineage['sku_type'],0,0)===0&&$lineage['sku_unique']===$sku['unique']
            &&m4pc_integer($sku['id'],1)===$skuId&&m4pc_integer($sku['product_id'],1,8388607)===$productId,
            'FULFILLMENT_SAVED_SKU_LINEAGE');
        $unit=m4fp_cents($cart['truePrice']);$base=m4fp_cents($cart['sum_price']);
        $cost=m4fp_cents($cart['costPrice']);$discount=m4fp_cents($cart['vip_truePrice']);
        $budgets=['goods'=>m4fp_cents($cart['sum_true_price']),'postage'=>m4fp_cents($cart['postage_price']),
            'origin_postage'=>m4fp_cents($cart['origin_postage_price']),'cost'=>$cost*$q,
            'base_goods'=>$base*$q,'discount'=>$discount*$q];
        m4o_expect($budgets['goods']===$unit*$q&&$base===$unit+$discount
            &&$budgets['origin_postage']===$budgets['postage']&&m4fp_cents($sku['price'])===$base
            &&m4fp_cents($sku['cost'])===$cost,'FULFILLMENT_SAVED_ORIGINAL_BUDGET_RELATIONS');
        $units=[];
        for($position=0;$position<$q;++$position){
            $amounts=[];
            foreach($budgets as $field=>$cents)$amounts[$field]=intdiv($cents,$q)+($position<$cents%$q?1:0);
            $units[]=$amounts;
        }
        foreach([$product['store_name'],$sku['suk'],$sku['image'],$product['image'],$item['cart_id']] as $text)
            m4o_expect(is_string($text),'FULFILLMENT_SAVED_DISPLAY_TEXT');
        $lines[$id]=['identity'=>['source_item_id'=>$id,'product_id'=>$productId,'sku_id'=>$skuId,'sku_type'=>0,
            'sku_unique'=>$lineage['sku_unique'],'cart_ref'=>$item['cart_id'],'reserve_id'=>$reserve,
            'original_quantity'=>$q,'unit_price'=>$cart['truePrice'],'price_type'=>$priceType],
            'public'=>['id'=>$id,'product_id'=>$productId,'product_name'=>$product['store_name'],'sku_name'=>$sku['suk'],
                'image'=>$sku['image']!==''?$sku['image']:$product['image']], 'budgets'=>$budgets,'units'=>$units];
        $key='r:'.$reserve;
        if(!isset($groups[$key]))$groups[$key]=['line'=>$lines[$id]['identity'],'quantity'=>0];
        foreach(['product_id','sku_id','sku_type','sku_unique'] as $field)
            m4o_expect($groups[$key]['line'][$field]===$lines[$id]['identity'][$field],'FULFILLMENT_SHARED_RESERVE_IDENTITY');
        $groups[$key]['quantity']+=$q;
    }
    sort($quantities,SORT_NUMERIC);
    m4o_expect($quantities===[1,2,2]&&count($lines)===3&&count($groups)===2,'FULFILLMENT_ACTUAL_SMALL_QUANTITY_ORACLE');
    $one=null;foreach($lines as $line)if($line['identity']['original_quantity']===1)$one=$line;
    m4o_expect(is_array($one),'FULFILLMENT_ACTUAL_SINGLE_QUANTITY_LINE');$shared=[];$other=[];
    foreach($lines as $line){
        if($line['identity']['reserve_id']===$one['identity']['reserve_id'])$shared[]=$line;else $other[]=$line;
    }
    m4o_expect(count($shared)===2&&count($other)===1
        &&$shared[0]['identity']['sku_id']===$shared[1]['identity']['sku_id']
        &&$shared[0]['identity']['cart_ref']!==$shared[1]['identity']['cart_ref']
        &&$shared[0]['budgets']['goods']!==$shared[1]['budgets']['goods'],
        'FULFILLMENT_REAL_SHARED_RESERVE_DISTINCT_ORIGINAL_LINES');
    $seen=[];
    foreach($saved['reserves'] as $reserve){
        m4o_expect(is_int($reserve['id'])||is_string($reserve['id']),'FULFILLMENT_NATIVE_RESERVE_ID_TYPE');
        $key='r:'.(string)$reserve['id'];m4o_expect(isset($groups[$key])&&!isset($seen[$key]),'FULFILLMENT_COMPLETE_NATIVE_RESERVES');
        $group=$groups[$key];$identity=$group['line'];
        foreach(['mer_id'=>$root['mer_id'],'order_id'=>$root['id'],'product_id'=>$identity['product_id'],
            'sku_id'=>$identity['sku_id'],'sku_type'=>0,'qty'=>$group['quantity'],'actor_id'=>$root['uid']] as $field=>$expected)
            m4o_expect((string)$reserve[$field]===(string)$expected,'FULFILLMENT_ORIGINAL_RESERVE_OWNER_QUANTITY');
        m4o_expect($reserve['sku_unique']===$identity['sku_unique']&&$reserve['kind']==='reserve'
            &&$reserve['actor_type']==='customer','FULFILLMENT_ORIGINAL_RESERVE_ACTOR');
        foreach(['parent_reserve_id','order_item_id','refund_id'] as $field)
            m4o_expect(array_key_exists($field,$reserve)&&$reserve[$field]===null,'FULFILLMENT_ORIGINAL_RESERVE_NULL_PARENTS');
        $seen[$key]=true;
    }
    m4o_expect(count($seen)===count($groups),'FULFILLMENT_COMPLETE_RESERVE_CLOSURE');
    return $lines;
}

/** Explicit original intervals are the independent expectation, not intervals recovered from planner output. */
function m4fp_expected(array $lines,array $ranges): array
{
    $allocations=[];$totals=['quantity'=>0,'goods'=>0,'postage'=>0,'origin_postage'=>0,'payable'=>0,
        'cost'=>0,'base_goods'=>0,'discount'=>0,'level_discount'=>0,'member_discount'=>0];
    foreach($ranges as $range){
        $line=$lines[$range['source_item_id']];$start=$range['unit_start'];$end=$range['unit_end'];
        $allocation=$line['identity']+['unit_start'=>$start,'unit_end'=>$end,'quantity'=>$end-$start];
        $cents=array_fill_keys(array_keys($line['budgets']),0);
        for($position=$start;$position<$end;++$position)
            foreach($line['units'][$position] as $field=>$value)$cents[$field]+=$value;
        foreach($cents as $field=>$value){$allocation[$field]=m4fp_money($value);$totals[$field]+=$value;}
        $allocation['payable']=m4fp_money($cents['goods']+$cents['postage']);
        $bucket=$line['identity']['price_type']==='level'?'level_discount':'member_discount';
        $totals[$bucket]+=$cents['discount'];$totals['quantity']+=$end-$start;$allocations[]=$allocation;
    }
    $totals['payable']=$totals['goods']+$totals['postage'];
    foreach($totals as $field=>$value)if($field!=='quantity')$totals[$field]=m4fp_money($value);
    return ['allocations'=>$allocations,'totals'=>$totals];
}

/** Original form bytes/indices/values are selected directly, never rehashed or read from current products. */
function m4fp_forms(array $root,array $productIds): array
{
    m4o_expect(is_string($root['custom_form']),'FULFILLMENT_SAVED_FORM_JSON');
    $groups=json_decode($root['custom_form'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    m4o_expect(is_array($groups)&&array_is_list($groups),'FULFILLMENT_ORIGINAL_FORM_GROUPS');$out=[];
    foreach($groups as $group){
        $product=m4pc_integer($group['product_id'],1,8388607);$fields=[];
        m4o_expect(is_string($group['product_name'])&&is_string($group['definition_sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$group['definition_sha256'])===1
            &&is_array($group['fields'])&&array_is_list($group['fields']),'FULFILLMENT_ORIGINAL_FORM_DEFINITION');
        foreach($group['fields'] as $index=>$field){
            m4o_expect($field['field_index']===$index&&is_string($field['title'])&&is_bool($field['status'])
                &&in_array($field['label'],['text','number','email','data','time','id','phone','img'],true),
                'FULFILLMENT_ORIGINAL_FORM_FIELD');
            if($field['label']==='img'){
                m4o_expect(is_array($field['value'])&&array_is_list($field['value']),'FULFILLMENT_ORIGINAL_FORM_IMAGES');
                foreach($field['value'] as $image)m4o_expect(is_string($image),'FULFILLMENT_ORIGINAL_FORM_IMAGE_STRING');
            }else m4o_expect(is_string($field['value']),'FULFILLMENT_ORIGINAL_FORM_VALUE_STRING');
            $fields[]=['field_index'=>$index,'title'=>$field['title'],'label'=>$field['label'],'status'=>$field['status'],'value'=>$field['value']];
        }
        if(in_array($product,$productIds,true))$out[]=['product_id'=>$product,'product_name'=>$group['product_name'],
            'definition_sha256'=>$group['definition_sha256'],'fields'=>$fields];
    }
    return $out;
}

function m4fp_check_plan(array $saved,array $lines,array $ranges,array $plan,?string $fingerprint=null): array
{
    $expected=m4fp_expected($lines,$ranges);$root=$saved['order'];$id=m4pc_integer($root['id'],1);
    m4o_expect(is_string($plan['source_snapshot_sha256']??null)
        &&preg_match('/\A[a-f0-9]{64}\z/D',$plan['source_snapshot_sha256'])===1,'FULFILLMENT_SOURCE_DIGEST_FORMAT');
    $fingerprint??=$plan['source_snapshot_sha256'];
    m4fp_same($plan,['plan_version'=>1,'root_order_id'=>$id,'source_snapshot_sha256'=>$fingerprint]+$expected);
    $items=[];
    foreach($expected['allocations'] as $allocation){
        $itemId=$allocation['source_item_id'];
        if(!isset($items[$itemId]))$items[$itemId]=$lines[$itemId]['public']+['quantity'=>0,
            'unit_price'=>$lines[$itemId]['identity']['unit_price'],'line_price'=>'0.00','postage_price'=>'0.00'];
        $items[$itemId]['quantity']+=$allocation['quantity'];
        $items[$itemId]['line_price']=m4fp_money(m4fp_cents($items[$itemId]['line_price'])+m4fp_cents($allocation['goods']));
        $items[$itemId]['postage_price']=m4fp_money(m4fp_cents($items[$itemId]['postage_price'])+m4fp_cents($allocation['postage']));
    }
    $totals=$expected['totals'];$products=array_values(array_unique(array_column($items,'product_id'),SORT_NUMERIC));
    $public=['plan_version'=>1,'root_order_id'=>$id,'order_id'=>$root['order_id'],'marketplace_trade_version'=>1,
        'total_num'=>$totals['quantity'],'total_price'=>$totals['goods'],'pay_postage'=>$totals['postage'],
        'pay_price'=>$totals['payable'],'items'=>array_values($items),'custom_form'=>m4fp_forms($root,$products)];
    m4fp_same(FulfillmentNormalProjection::fulfillmentPlan($root,$saved['items'],$plan),$public);
    return $expected;
}

function m4fp_case(array $saved,string $case): array
{
    $lines=m4fp_oracle($saved);$root=$saved['order'];$fullRanges=$firstRanges=$remainingRanges=[];
    $fullRequest=$firstRequest=$remainingRequest=[];
    foreach($lines as $id=>$line){
        $q=$line['identity']['original_quantity'];
        $fullRequest[]=['source_item_id'=>$id,'quantity'=>$q];$firstRequest[]=['source_item_id'=>$id,'quantity'=>1];
        $fullRanges[]=['source_item_id'=>$id,'unit_start'=>0,'unit_end'=>$q];
        $firstRanges[]=['source_item_id'=>$id,'unit_start'=>0,'unit_end'=>1];
        if($q>1){$remainingRequest[]=['source_item_id'=>$id,'quantity'=>$q-1];
            $remainingRanges[]=['source_item_id'=>$id,'unit_start'=>1,'unit_end'=>$q];}
    }
    $full=FulfillmentNormalPlan::plan($root,$saved['items'],$saved['reserves'],[],$fullRequest);
    $whole=m4fp_check_plan($saved,$lines,$fullRanges,$full);$fingerprint=$full['source_snapshot_sha256'];
    m4fp_same(FulfillmentNormalPlan::plan($root,$saved['items'],$saved['reserves'],[],$fullRequest),$full);
    $totals=$whole['totals'];
    foreach(['goods'=>'total_price','postage'=>'pay_postage','origin_postage'=>'total_postage','payable'=>'pay_price','cost'=>'cost'] as $field=>$column)
        m4o_expect($totals[$field]===$root[$column],'FULFILLMENT_FULL_SAVED_HEADER_CONSERVATION');
    m4o_expect($totals['quantity']===m4pc_integer($root['total_num'],1),'FULFILLMENT_FULL_SAVED_QUANTITY');
    $first=FulfillmentNormalPlan::plan($root,$saved['items'],$saved['reserves'],[],$firstRequest);
    $firstExpected=m4fp_check_plan($saved,$lines,$firstRanges,$first,$fingerprint);$occupied=[];
    foreach($first['allocations'] as $allocation)$occupied[]=['source_item_id'=>$allocation['source_item_id'],
        'unit_start'=>$allocation['unit_start'],'unit_end'=>$allocation['unit_end']];
    // These ranges only describe this in-memory sequence; no fulfillment/occupancy record is persisted.
    $remaining=FulfillmentNormalPlan::plan($root,$saved['items'],$saved['reserves'],$occupied,$remainingRequest);
    $remainingExpected=m4fp_check_plan($saved,$lines,$remainingRanges,$remaining,$fingerprint);
    foreach($lines as $id=>$line){
        foreach(array_keys($line['budgets']) as $field){
            $sum=0;
            foreach(array_merge($first['allocations'],$remaining['allocations']) as $allocation)
                if($allocation['source_item_id']===$id)$sum+=m4fp_cents($allocation[$field]);
            m4o_expect($sum===$line['budgets'][$field],'FULFILLMENT_SEQUENTIAL_EACH_ORIGINAL_BUDGET');
        }
    }
    foreach($totals as $field=>$value){
        $sum=$field==='quantity'?$firstExpected['totals'][$field]+$remainingExpected['totals'][$field]
            :m4fp_money(m4fp_cents($firstExpected['totals'][$field])+m4fp_cents($remainingExpected['totals'][$field]));
        m4o_expect($sum===$value,'FULFILLMENT_SEQUENTIAL_FULL_TOTALS');
    }
    $subsetRequest=[$firstRequest[0]];$subsetRanges=[$firstRanges[0]];
    $subset=FulfillmentNormalPlan::plan($root,$saved['items'],$saved['reserves'],[],$subsetRequest);
    m4fp_check_plan($saved,$lines,$subsetRanges,$subset,$fingerprint);
    if($case==='level')m4fp_same([$totals['goods'],$totals['postage'],$totals['payable'],$totals['level_discount'],$totals['member_discount']],
        ['108.83','13.35','122.18','12.12','0.00']);
    if($case==='paid'){
        m4fp_same([$totals['goods'],$totals['postage'],$totals['payable'],$totals['level_discount'],$totals['member_discount']],
            ['102.44','10.68','113.12','6.06','12.45']);
        $one=null;$two=null;
        foreach($lines as $line)if($line['identity']['original_quantity']===1)$one=$line;
        foreach($lines as $line)if($line['identity']['reserve_id']===$one['identity']['reserve_id']
            &&$line['identity']['original_quantity']===2)$two=$line;
        m4o_expect(is_array($two),'FULFILLMENT_PAID_ACTUAL_SHARED_TWO_QUANTITY_LINE');
        m4fp_same([$two['budgets']['postage'],$two['units'][0]['postage'],
            $two['units'][1]['postage'],$one['budgets']['postage'],$one['units'][0]['postage']],
            [427,214,213,214,214]);
    }
    return ['order_id'=>m4pc_integer($root['id'],1),'item_count'=>count($lines),'reserve_count'=>count($saved['reserves']),
        'full_quantity'=>$totals['quantity'],'first_quantity'=>$firstExpected['totals']['quantity'],
        'remainder_quantity'=>$remainingExpected['totals']['quantity'],'full_verified'=>true,'sequence_verified'=>true,
        'shared_reserve_verified'=>true,'public_projection_verified'=>true,'source_digest_stable'=>true];
}

/** No business/identity rows, current catalog reads, payment writes, HTTP or simulated paid state. */
function m4o_fulfillment_plan_normal(PDO $pdo,array $direct,array $service,array $membership): array
{
    $default=FulfillmentNormalDb::connect()->getPdo();
    m4o_expect($default instanceof PDO&&$pdo!==$default&&!$pdo->inTransaction()&&!$default->inTransaction()
        &&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&PHP_INT_SIZE===8,'FULFILLMENT_REAL_INDEPENDENT_PDO');
    m4o_expect(count(M4O_FACT_TABLES)===23+2&&in_array('eb_marketplace_payment_registry',M4O_FACT_TABLES,true)
        &&in_array('eb_marketplace_payment_attempt',M4O_FACT_TABLES,true),'FULFILLMENT_ORIGINAL_23_PLUS_TWO_FACT_TABLES');
    $ids=['direct'=>$direct['order_id'],'persistent'=>$service['persistent']['order_id'],'temporary'=>$service['temporary']['order_id'],
        'level'=>$membership['level']['order_id'],'paid'=>$membership['paid']['order_id']];
    foreach($ids as $id)m4o_expect(is_int($id)&&$id>0&&$id<=2147483647,'FULFILLMENT_EXISTING_NATIVE_PROOF_PK');
    m4o_expect(count(array_unique($ids,SORT_NUMERIC))===5,'FULFILLMENT_FIVE_DISTINCT_EXISTING_ORDER_PKS');
    $before=m4o_facts($pdo);$saved=[];$cases=[];
    foreach($ids as $case=>$id)$saved[$case]=m4fp_saved($pdo,$id);
    foreach($saved as $case=>$native)$cases[$case]=m4fp_case($native,$case);
    foreach($ids as $case=>$id)m4o_expect(m4fp_saved($pdo,$id)===$saved[$case],'FULFILLMENT_ALL_SAVED_ROWS_UNCHANGED');
    m4o_expect(m4o_facts($pdo)===$before,'FULFILLMENT_ORIGINAL_SQL_FACTS_UNCHANGED');
    return ['verified'=>true,'scope'=>'NORMAL_REAL_SAVED_FULFILLMENT_PLAN_ONLY','cases'=>$cases,
        'sql_facts_unchanged'=>true,'saved_rows_unchanged'=>true,'persistence_executed'=>false,'payment_shipping_executed'=>false];
}
