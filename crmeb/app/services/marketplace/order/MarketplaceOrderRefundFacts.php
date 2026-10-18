<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\AuthUnavailableException;
use stdClass;
use Throwable;

/** Pure complete-credit consumer. It is neither current authorization nor a replacement for owned history/readback. */
final class MarketplaceOrderRefundFacts
{
    private const INT_MAX = 2147483647;
    private const UINT_MAX = 4294967295;
    private const ROOT_MAX = 8388607;
    private const ROOT_STOCK_MAX = 16777215;
    private const MOVEMENT_FIELDS = ['id','mer_id','order_id','product_id','sku_id','sku_type','sku_unique','qty','kind',
        'idem_key','parent_reserve_id','order_item_id','refund_id','actor_type','actor_id','request_digest',
        'sku_stock_before','sku_stock_after','sku_sales_before','sku_sales_after','root_stock_before',
        'root_stock_after','root_sales_before','root_sales_after','add_time'];
    private const ITEM_FIELDS = ['id','oid','uid','mer_id','cart_id','product_id','cart_num','refund_num','surplus_num','split_status','cart_info','unique'];
    private const CHILD_FIELDS = ['id','uid','mer_id','pid','marketplace_trade_version','order_id','paid','status','refund_status','is_cancel'];
    private const REFUND_ADDITIONS = ['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind',
        'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'];
    private const ACTION_FIELDS = ['id','contract_version','root_order_id','uid','mer_id','refund_id','payment_attempt_id','action',
        'actor_type','actor_id','actor_ref','idem_key','request_digest','request_payload','result_payload','state',
        'previous_refund_action_id','fulfillment_cut_id','apply_refund_id','created_at','committed_at'];
    private const OP_FIELDS = ['id','contract_version','root_order_id','child_order_id','uid','mer_id','action','idem_key',
        'request_digest','source_snapshot_digest','allocation_digest','request_payload','result_payload','state',
        'ship_child_order_id','target_operation_id','target_action','actor_type','actor_id','created_at','committed_at',
        'shipped_at','receipt_due_at','received_at','aftersale_cut_id'];
    private const STATE_FIELDS = ['business_state','physical_state','finance_state','requested_amount','confirmed_amount',
        'held_amount','refund_type','is_cancel','return_delivery'];
    private const ALLOCATION_FIELDS = ['source_item_id','reserve_id','source_ship_range_id','child_order_id','child_item_id',
        'unit_start','unit_end','quantity','goods','postage','requested_amount','confirmed_amount'];

    /** @internal Actual complete family mirrors after inspectForAftersale; never current authorization. */
    public static function validateRefundMirrorsFromInspection(array $refundRows,array $inspection): void
    {
        if(PHP_INT_SIZE<8) self::unavailable();
        self::keys($inspection,['source','winner','actions','fulfillment','history','summary','aftersale','credit_facts','last_action_id','last_operation_id']);
        foreach(['source','fulfillment','history','summary','credit_facts'] as $key) if(!is_array($inspection[$key])) self::unavailable();
        self::list($inspection['actions']); self::list($refundRows);
        if($inspection['aftersale']!==null&&!is_array($inspection['aftersale'])) self::unavailable();
        if(!is_string($inspection['winner'])||self::u64($inspection['winner'])!==$inspection['winner']) self::unavailable();
        foreach(['last_action_id','last_operation_id'] as $key) if($inspection[$key]!==null
            &&(!is_string($inspection[$key])||self::u64($inspection[$key])!==$inspection[$key])) self::unavailable();
        $source=$inspection['source']; $history=$inspection['history'];
        self::keys($source,['root','identity','items','lines','groups','reserves','child_orders','capture','plan','commercial']);
        foreach($source as $value) if(!is_array($value)) self::unavailable();
        self::keys($source['identity'],['id','uid','mer_id','order_no']);
        self::integer($source['identity']['id'],1,self::INT_MAX); self::integer($source['identity']['uid'],1,self::INT_MAX);
        self::integer($source['identity']['mer_id'],1,self::UINT_MAX); self::text($source['identity']['order_no'],32);
        self::keys($source['capture'],['root','items','selection','reserves','credit_rows','child_orders']);
        if($source['capture']['root']!==$source['root']) self::unavailable();
        self::keys($history,['refunds','movements','ships','received','cancel','returned','expected_logs','fulfillment_completed_at']);
        foreach(['refunds','movements','ships','received','cancel','returned','expected_logs'] as $key)
            if(!is_array($history[$key])) self::unavailable();
        if($history['fulfillment_completed_at']!==null) self::integer($history['fulfillment_completed_at'],1,self::UINT_MAX);
        self::mirrors($refundRows,$source,$inspection['winner'],$history);
    }

    /** Internal replay for an owned paid-order snapshot, never current authorization or first-payment admission. */
    public static function inspectForAftersale(array $capture,array $context,array $lockedPayment): array
    {
        $credits=self::validateCreditFacts($capture,$context,$lockedPayment);
        $source=self::source($capture,$lockedPayment); $winner=self::winner($source,$lockedPayment);
        $actions=self::actions($context['actions'],$source,$winner);
        $fulfillment=self::fulfillment($context,$source,$actions);
        $history=self::replay(self::timeline($actions,$fulfillment['operations']),$source,$fulfillment);
        self::logs($context['logs'],$source,$history['expected_logs']);
        self::mirrors($context['refunds'],$source,$winner,$history);
        $lastAction=$actions===[]?null:$actions[count($actions)-1]['id'];
        $operations=$fulfillment['operations']; $lastOperation=$operations===[]?null:$operations[count($operations)-1]['id'];
        $summary=self::summary($source,$history['ships'],$history['received'],$history['cancel'],$history['returned']);
        $aftersale=$lastAction===null?null:self::aftersale($source,$history['refunds'],$history['received'],$history['returned'],
            $lastAction,$history['fulfillment_completed_at']);
        return ['source'=>$source,'winner'=>$winner,'actions'=>$actions,'fulfillment'=>$fulfillment,'history'=>$history,
            'summary'=>$summary,'aftersale'=>$aftersale,'credit_facts'=>$credits,'last_action_id'=>$lastAction,'last_operation_id'=>$lastOperation];
    }

    /** Pure S2 proposal only; createdAt is the owned writer's actual clock, never a committed operation. */
    public static function prepareFulfillment(array $capture,array $context,array $lockedPayment,string $action,array $normalizedRequest,int $createdAt):array
    {
        self::integer($createdAt,1,self::UINT_MAX);
        $inspection=self::inspectForAftersale($capture,$context,$lockedPayment);
        self::readInspectionShape($inspection);
        $cut=$inspection['last_action_id']; if($cut===null)self::unavailable();
        $source=$inspection['source'];$history=$inspection['history'];
        self::fulfillmentProposalRequest($normalizedRequest,$action,$source['identity']['id']);
        $pending=self::pending($history['refunds']);$selected=[];
        if($action==='ship'){
            $plan=self::quantityPlan($source,self::coordinates(array_merge($history['ships'],$history['cancel'],$pending['cancel'])),
                $normalizedRequest['quantities']);
            foreach($plan['allocations'] as$part)$selected[]=['source_item_id'=>$part['source_item_id'],
                'unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end'],'source_ship_range_id'=>null,'child_item_id'=>null];
        }else{
            $available=self::receiptAvailabilityFromInspection($inspection,$normalizedRequest['child_order_id']);
            $selected=self::selectReceive($available['eligible'],$normalizedRequest['selection']);
            $plan=MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($source['root'],array_values($source['items']),
                $source['reserves'],self::coordinates($selected));
        }
        if($selected===[]||count($selected)!==count($plan['allocations']))self::unavailable();
        $mapping=[];$quantity=0;
        foreach($plan['allocations'] as$index=>$allocation){
            $part=$selected[$index];
            foreach(['source_item_id','unit_start','unit_end'] as$key)if($part[$key]!==$allocation[$key])self::unavailable();
            $mapping[]=['source_item_id'=>$part['source_item_id'],'unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end'],
                'source_ship_range_id'=>$part['source_ship_range_id'],'child_item_id'=>$part['child_item_id']];
            $quantity+=$allocation['quantity'];self::integer($quantity,1,self::UINT_MAX);
        }
        if($plan['totals']['quantity']!==$quantity)self::unavailable();
        $summary=$inspection['summary'];
        if($action==='ship'){
            if($quantity>$summary['to_ship'])self::unavailable();
            $summary['shipped']+=$quantity;$summary['to_ship']-=$quantity;$summary['to_receive']+=$quantity;++$summary['child_count'];
        }else{
            if($quantity>$summary['to_receive'])self::unavailable();
            $summary['received']+=$quantity;$summary['to_receive']-=$quantity;
        }
        foreach(['shipped','received','to_ship','to_receive','child_count'] as$key)self::integer($summary[$key],0,self::UINT_MAX);
        $summary['shipping_state']=$summary['shipped']===0?'unshipped':($summary['to_ship']>0?'partial':'shipped');
        $summary['receipt_state']=$summary['shipped']===0?'not_shipped':
            ($summary['received']===$summary['shipped']?'received':($summary['received']===0?'pending':'partial'));
        $summary['closed']=$summary['to_ship']===0&&$summary['to_receive']===0;
        $completed=$summary['closed']?($history['fulfillment_completed_at']??$createdAt):null;
        // Planned receive coordinates retain actual parent identities; no future range/op/child PK is fabricated.
        $received=$action==='receive'?array_merge($history['received'],$selected):$history['received'];
        $aftersale=self::aftersale($source,$history['refunds'],$received,$history['returned'],$cut,$completed);
        return ['plan'=>$plan,'mapping'=>$mapping,'summary'=>$summary,'aftersale'=>$aftersale,'aftersale_cut_id'=>$cut];
    }

    /** Three real first-ship coordinate sets for one child; never a receive permission or a saved range row. */
    public static function receiptAvailabilityFromInspection(array $inspection,int $childId):array
    {
        self::readInspectionShape($inspection);self::integer($childId,1,self::INT_MAX);
        $source=$inspection['source'];$history=$inspection['history'];
        if(!isset($source['child_orders'][$childId])||!isset($inspection['fulfillment']['ships'][$childId]))self::unavailable();
        $parents=[];
        foreach($history['ships'] as$range)if($range['child_order_id']===$childId)$parents[]=self::withShipParent($range);
        if($parents===[])self::unavailable();
        $remaining=self::subtract($parents,array_merge($history['received'],$history['returned']));
        $pending=self::pending($history['refunds']);$eligible=self::subtract($remaining,$pending['return']);
        $paused=self::subtract($remaining,$eligible);
        return ['remaining'=>self::receiptCoordinates($remaining),'paused'=>self::receiptCoordinates($paused),
            'eligible'=>self::receiptCoordinates($eligible)];
    }

    /** Saved monetary/physical capacity only. Actual actor and new-apply policy remain the owned service's responsibility. */
    public static function quoteSelectionFromInspection(array $inspection):array
    {
        self::readInspectionShape($inspection);$source=$inspection['source'];$history=$inspection['history'];
        $refunds=$history['refunds'];$pending=self::pending($refunds);$used=self::activeClaims($refunds);
        $paid=self::money($source['root']['pay_price']);$confirmed='0.00';$held='0.00';
        foreach($refunds as$refund){
            $confirmed=bcadd($confirmed,self::money($refund['after']['confirmed_amount']),2);
            $held=bcadd($held,self::money($refund['after']['held_amount']),2);
        }
        $available=bcsub(bcsub($paid,$confirmed,2),$held,2);if(bccomp($available,'0.00',2)<0)self::unavailable();
        $rootItems=[];$moneyTotal='0.00';
        foreach($source['items'] as$id=>$native){
            $line=$source['lines'][$id];$quantity=$line['original_quantity'];
            $cancel=self::subtract([['source_item_id'=>$id,'unit_start'=>0,'unit_end'=>$quantity]],
                array_merge($history['ships'],$history['cancel'],$pending['cancel']));
            $windows=self::moneySubtract([['source_item_id'=>$id,'first'=>'0','last'=>self::moneyPosition($line,$quantity)]],$used);
            $cents='0';foreach($windows as$window)$cents=bcadd($cents,bcsub($window['last'],$window['first'],0),0);
            if(bccomp($cents,'0',0)<0)self::unavailable();$money=bcdiv($cents,'100',2);$moneyTotal=bcadd($moneyTotal,$money,2);
            $rootItems[]=['item'=>$source['commercial']['public_items'][$id],'available_cancel_quantity'=>self::quantity($cancel),
                'available_money_amount'=>$money];
        }
        if($moneyTotal!==$available)self::unavailable();$returns=[];
        foreach($source['child_orders'] as$childId=>$child){
            $groups=[];
            foreach($history['ships'] as$range)if($range['child_order_id']===$childId)$groups[$range['child_item_id']][]=self::withShipParent($range);
            if($groups===[])self::unavailable();ksort($groups,SORT_NUMERIC);$items=[];
            foreach($groups as$itemId=>$parents){
                usort($parents,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']);
                $free=self::subtract($parents,array_merge($history['returned'],$pending['return']));$quantity=0;$eligible=0;$segments=[];
                foreach($parents as$parent){
                    foreach($free as$part)if($part['source_ship_range_id']===$parent['id']){
                        if($part['child_item_id']!==$itemId||$part['child_order_id']!==$childId
                            ||$part['unit_start']<$parent['unit_start']||$part['unit_end']>$parent['unit_end'])self::unavailable();
                        $segments[]=['first'=>$quantity+$part['unit_start']-$parent['unit_start']+1,
                            'last'=>$quantity+$part['unit_end']-$parent['unit_start']];
                        $eligible+=$part['unit_end']-$part['unit_start'];
                    }
                    $quantity+=$parent['quantity'];self::integer($quantity,1,self::INT_MAX);
                }
                if($eligible<0||$eligible>$quantity)self::unavailable();
                $items[]=['item_id'=>$itemId,'quantity'=>$quantity,'eligible_quantity'=>$eligible,'eligible_segments'=>$segments];
            }
            $returns[$childId]=['schema'=>1,'coordinate'=>'child_item_ordinal','items'=>$items];
        }
        return ['capacity'=>['paid'=>$paid,'confirmed'=>$confirmed,'held'=>$held,'available'=>$available,'currency'=>'CNY'],
            'root_items'=>$rootItems,'returns_by_child'=>$returns];
    }

    /** The current R2 read view is a copy. No saved A2 or historical actor flags are rewritten or granted to this reader. */
    public static function refundReadViewFromInspection(array $inspection,int $refundId):array
    {
        self::readInspectionShape($inspection);self::integer($refundId,1,self::INT_MAX);
        $refund=$inspection['history']['refunds'][$refundId]??null;if(!is_array($refund))self::unavailable();
        self::actionPublic($refund['last'],$inspection['source'],$refund,$inspection['fulfillment']);
        $view=self::treeObject($refund['last']['result_payload']['public_result']['refund']);
        $view['return_selection']=self::returnSelection($inspection['source'],$refund,$inspection['fulfillment']);
        $view['allowed_actions']=['withdraw'=>false,'submit_return'=>false,'approve'=>false,'reject'=>false,'accept_return'=>false];
        return $view;
    }

    private static function receiptCoordinates(array $rows):array
    {
        $out=[];
        foreach($rows as$part){
            $out[]=['source_item_id'=>$part['source_item_id'],'unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end'],
                'source_ship_range_id'=>self::u64($part['source_ship_range_id']),'child_item_id'=>self::integer($part['child_item_id'],1,self::INT_MAX)];
        }
        return $out;
    }

    /** Recheck the fixed internal inspection shape and its aggregate correspondence, never current authority. */
    private static function readInspectionShape(array $inspection):void
    {
        self::keys($inspection,['source','winner','actions','fulfillment','history','summary','aftersale','credit_facts','last_action_id','last_operation_id']);
        foreach(['source','fulfillment','history','summary','credit_facts'] as$key)if(!is_array($inspection[$key]))self::unavailable();
        self::list($inspection['actions']);
        if(!is_string($inspection['winner'])||self::u64($inspection['winner'])!==$inspection['winner'])self::unavailable();
        foreach(['last_action_id','last_operation_id'] as$key)if($inspection[$key]!==null
            &&(!is_string($inspection[$key])||self::u64($inspection[$key])!==$inspection[$key]))self::unavailable();
        $source=$inspection['source'];$history=$inspection['history'];$fulfillment=$inspection['fulfillment'];
        self::keys($source,['root','identity','items','lines','groups','reserves','child_orders','capture','plan','commercial']);
        foreach($source as$value)if(!is_array($value))self::unavailable();
        self::keys($source['identity'],['id','uid','mer_id','order_no']);
        foreach(['id','uid'] as$key)self::integer($source['identity'][$key],1,self::INT_MAX);
        self::integer($source['identity']['mer_id'],1,self::UINT_MAX);self::text($source['identity']['order_no'],32);
        self::keys($source['capture'],['root','items','selection','reserves','credit_rows','child_orders']);
        if($source['capture']['root']!==$source['root'])self::unavailable();
        self::keys($history,['refunds','movements','ships','received','cancel','returned','expected_logs','fulfillment_completed_at']);
        foreach(['refunds','movements'] as$key)if(!is_array($history[$key]))self::unavailable();
        foreach(['ships','received','cancel','returned','expected_logs'] as$key)self::list($history[$key]);
        self::keys($fulfillment,['operations','ranges','ships']);self::list($fulfillment['operations']);
        if(!is_array($fulfillment['ranges'])||!is_array($fulfillment['ships']))self::unavailable();
        $last=$inspection['actions']===[]?null:$inspection['actions'][count($inspection['actions'])-1]['id'];
        if($last!==$inspection['last_action_id'])self::unavailable();
        $lastOperation=$fulfillment['operations']===[]?null:$fulfillment['operations'][count($fulfillment['operations'])-1]['id'];
        if($lastOperation!==$inspection['last_operation_id'])self::unavailable();
        foreach(['ships','received','cancel','returned'] as$key)foreach($history[$key] as$part){
            if(!is_array($part)||!is_int($part['source_item_id']??null)||!isset($source['items'][$part['source_item_id']])
                ||!is_int($part['unit_start']??null)||!is_int($part['unit_end']??null))self::unavailable();
            self::integer($part['unit_start'],0,self::INT_MAX-1);self::integer($part['unit_end'],$part['unit_start']+1,self::INT_MAX);
        }
        if(!self::same($inspection['summary'],self::summary($source,$history['ships'],$history['received'],$history['cancel'],$history['returned'])))self::unavailable();
        $completed=$history['fulfillment_completed_at'];if($completed!==null)self::integer($completed,1,self::UINT_MAX);
        $expected=$last===null?null:self::aftersale($source,$history['refunds'],$history['received'],$history['returned'],$last,$completed);
        if(!self::same($inspection['aftersale'],$expected))self::unavailable();
    }

    /** Exact already-normalized request only: no invented idempotency key is used to call the HTTP normalizer. */
    private static function fulfillmentProposalRequest(array $request,string $action,int $rootId):void
    {
        if(!in_array($action,['ship','receive'],true))self::unavailable();
        self::keys($request,$action==='ship'?['schema','action','root_order_id','quantities','delivery']:
            ['schema','action','root_order_id','child_order_id','selection']);
        if($request['schema']!==1||$request['action']!==$action||$request['root_order_id']!==$rootId
            ||strlen(self::canonical($request))>65536)self::unavailable();
        if($action==='ship'){
            self::list($request['quantities']);if($request['quantities']===[])self::unavailable();$last=0;
            foreach($request['quantities'] as$part){
                if(!is_array($part))self::unavailable();self::keys($part,['source_item_id','quantity']);
                if(!is_int($part['source_item_id'])||!is_int($part['quantity']))self::unavailable();
                $id=self::integer($part['source_item_id'],1,self::INT_MAX);self::integer($part['quantity'],1,self::INT_MAX);
                if($id<=$last)self::unavailable();$last=$id;
            }
            if(!is_array($request['delivery']))self::unavailable();self::keys($request['delivery'],['type','carrier_code','carrier_name','tracking_no']);
            if($request['delivery']['type']!=='express')self::unavailable();
            foreach(['carrier_code'=>50,'carrier_name'=>64,'tracking_no'=>64] as$key=>$max){
                self::text($request['delivery'][$key],$max);
                if(preg_match('/(*UCP)(?:\A\s|\s\z)/u',$request['delivery'][$key])!==0)self::unavailable();
            }
        }else{
            if(!is_int($request['child_order_id']))self::unavailable();self::integer($request['child_order_id'],1,self::INT_MAX);
            if(!is_array($request['selection']))self::unavailable();$selection=$request['selection'];
            if(($selection['mode']??null)==='all_eligible'){self::keys($selection,['mode']);return;}
            self::keys($selection,['mode','ranges']);if($selection['mode']!=='ranges')self::unavailable();self::list($selection['ranges']);
            if($selection['ranges']===[])self::unavailable();$lastId=0;$lastEnd=0;
            foreach($selection['ranges'] as$part){
                if(!is_array($part))self::unavailable();self::keys($part,['source_item_id','unit_start','unit_end']);
                foreach($part as$value)if(!is_int($value))self::unavailable();
                $id=self::integer($part['source_item_id'],1,self::INT_MAX);$start=self::integer($part['unit_start'],0,self::INT_MAX-1);
                $end=self::integer($part['unit_end'],$start+1,self::INT_MAX);
                if($id<$lastId||($id===$lastId&&$start<=$lastEnd))self::unavailable();$lastId=$id;$lastEnd=$end;
            }
        }
    }

    /** A proposal before any business INSERT: no invented refund/action/movement PK, actor, clock or financial observation. */
    public static function prepareAction(array $capture,array $context,array $lockedPayment,string $action,?int $refundId,array $raw): array
    {
        $body=match($action){
            'apply'=>MarketplaceOrderRefundInput::apply($raw),'withdraw'=>MarketplaceOrderRefundInput::withdraw($raw),
            'approve'=>MarketplaceOrderRefundInput::approve($raw),'reject'=>MarketplaceOrderRefundInput::reject($raw),
            'express'=>MarketplaceOrderRefundInput::express($raw),'accept_return'=>MarketplaceOrderRefundInput::acceptReturn($raw),
            default=>self::unavailable(),
        };
        $snapshot=self::inspectForAftersale($capture,$context,$lockedPayment);
        $source=$snapshot['source']; $history=$snapshot['history']; $refunds=$history['refunds']; $physical=[];
        if($action==='apply'){
            if($refundId!==null) self::unavailable(); $kind=$body['operation_kind']; $pending=self::pending($refunds);
            if($kind==='return_goods'){
                $child=self::childId($source,$body['selection']['child_order_no']);
                $selected=self::childCoordinates($body['selection']['items'],$child,$snapshot['fulfillment']);
                self::contained($selected,$history['ships']); self::disjoint($selected,array_merge($history['returned'],$pending['return']));
            }else{
                $requests=[]; foreach($body['selection']['items'] as $item) $requests[]=['source_item_id'=>$item['item_id'],'quantity'=>$item['quantity']];
                $plan=$kind==='money_only'?self::moneySelection($source,$requests,$refunds):
                    self::quantityPlan($source,self::coordinates(array_merge($history['ships'],$history['cancel'],$pending['cancel'])),$requests);
                $selected=$plan['allocations'];
            }
            $allocations=[];
            foreach($selected as $part){
                $line=$source['lines'][$part['source_item_id']]; $start=$part['unit_start']; $end=$part['unit_end'];
                $allocations[]=['source_item_id'=>$part['source_item_id'],'reserve_id'=>$line['reserve_id'],
                    'source_ship_range_id'=>$kind==='return_goods'?$part['source_ship_range_id']:null,
                    'child_order_id'=>$kind==='return_goods'?$part['child_order_id']:null,
                    'child_item_id'=>$kind==='return_goods'?$part['child_item_id']:null,'unit_start'=>$start,'unit_end'=>$end,'quantity'=>$end-$start,
                    'goods'=>MarketplaceOrderFulfillmentPlan::sliceMoney($line['goods'],$line['original_quantity'],$start,$end),
                    'postage'=>MarketplaceOrderFulfillmentPlan::sliceMoney($line['postage'],$line['original_quantity'],$start,$end),
                    'requested_amount'=>'0.00','confirmed_amount'=>'0.00'];
            }
            $occupied='0.00'; foreach($refunds as $refund) $occupied=bcadd($occupied,
                bcadd($refund['after']['confirmed_amount'],$refund['after']['held_amount'],2),2);
            if(bccomp(bcadd($occupied,$body['amount'],2),$source['root']['pay_price'],2)>0) self::unavailable();
            $claims=self::allocateMoney($source,$allocations,$body['amount'],$refunds);
            foreach($allocations as $i=>&$part) $part['requested_amount']=$claims['amounts'][$i]; unset($part);
            $before=null;
            $after=['business_state'=>'REQUESTED','physical_state'=>match($kind){'money_only'=>'NONE','cancel_unshipped'=>'CANCEL_PENDING','return_goods'=>'RETURN_PENDING'},
                'finance_state'=>'NOT_SUBMITTED','requested_amount'=>$body['amount'],'confirmed_amount'=>'0.00','held_amount'=>$body['amount'],
                'refund_type'=>$kind==='return_goods'?2:1,'is_cancel'=>0,'return_delivery'=>null];
        }else{
            if($refundId===null) self::unavailable(); self::integer($refundId,1,self::INT_MAX);
            $refund=$refunds[$refundId]??null; if($refund===null) self::unavailable();
            $kind=$refund['kind']; $before=$refund['after']; $after=$before; $allocations=$refund['allocations'];
            switch($action){
                case 'withdraw':
                case 'reject':
                    if($before['business_state']!=='REQUESTED'||$before['confirmed_amount']!=='0.00'
                        ||$before['finance_state']!=='NOT_SUBMITTED'||$refund['accepted']!==[]) self::unavailable();
                    $after['business_state']=$action==='withdraw'?'WITHDRAWN':'REJECTED'; $after['physical_state']='NONE';
                    $after['held_amount']='0.00'; $after['is_cancel']=$action==='withdraw'?1:0;
                    if($action==='reject') $after['refund_type']=3;
                    break;
                case 'approve':
                    if($before['business_state']!=='REQUESTED') self::unavailable();
                    $after['business_state']='APPROVED'; $after['finance_state']='UNSUPPORTED';
                    if($kind==='cancel_unshipped'){
                        self::disjoint($allocations,array_merge($history['ships'],$history['cancel']));
                        $physical=$allocations; $after['physical_state']='CANCELLED';
                    }elseif($kind==='return_goods') $after['refund_type']=4;
                    break;
                case 'express':
                    if($kind!=='return_goods'||$before['business_state']!=='APPROVED'
                        ||self::same($before['return_delivery'],$body['return_delivery'])) self::unavailable();
                    $storedRefund=null; foreach($context['refunds'] as $row) if(self::integer($row['id'],1,self::INT_MAX)===$refundId) $storedRefund=$row;
                    if($storedRefund===null||$body['refund_no']!==$storedRefund['order_id']) self::unavailable();
                    $after['return_delivery']=$body['return_delivery']; $after['refund_type']=5;
                    break;
                case 'accept_return':
                    if($kind!=='return_goods'||$before['business_state']!=='APPROVED') self::unavailable();
                    $child=self::childId($source,$refund['apply']['request_payload']['public_body']['selection']['child_order_no']);
                    $physical=self::splitSelected(self::childCoordinates($body['items'],$child,$snapshot['fulfillment']),$allocations);
                    self::contained($physical,$allocations); self::contained($physical,$history['ships']); self::disjoint($physical,$history['returned']);
                    $after['physical_state']=self::quantity($refund['accepted'])+self::quantity($physical)===self::quantity($allocations)?'RETURN_ACCEPTED':'RETURN_PARTIAL';
                    $after['refund_type']=5;
                    break;
                default: self::unavailable();
            }
        }
        if($after['finance_state']==='SUCCEEDED'&&$after['confirmed_amount']===$after['requested_amount']
            &&($kind!=='return_goods'||$after['physical_state']==='RETURN_ACCEPTED')){
            $after['business_state']='COMPLETED'; $after['refund_type']=6;
        }
        self::state($after); self::integer(self::quantity($allocations),1,self::INT_MAX);
        return ['snapshot'=>$snapshot,'body'=>$body,'operation_kind'=>$kind,'before'=>$before,'after'=>$after,
            'allocations'=>$allocations,'physical'=>$physical,'inventory_plan'=>self::creditInventoryPlan($source,$physical)];
    }

    private static function creditInventoryPlan(array $source,array $physical): array
    {
        $items=[];
        foreach($physical as $part){
            $id=$part['source_item_id']; $line=$source['lines'][$id];
            if(!isset($items[$id])) $items[$id]=['source_item_id'=>$id,'reserve_id'=>$line['reserve_id'],
                'product_id'=>$line['product_id'],'sku_id'=>$line['sku_id'],'sku_unique'=>$line['sku_unique'],
                'original_quantity'=>$line['original_quantity'],'quantity'=>0,'segments'=>[]];
            $items[$id]['quantity']+=$part['unit_end']-$part['unit_start'];
            $items[$id]['segments'][]=['unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end']];
        }
        $out=array_values($items);
        foreach($out as &$item) usort($item['segments'],static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']); unset($item);
        usort($out,static fn(array $a,array $b):int=>($a['sku_id']<=>$b['sku_id'])?:($a['source_item_id']<=>$b['source_item_id']));
        return $out;
    }


    public static function validateCreditFacts(array $capture, array $context, array $lockedPayment): array
    {
        if (PHP_INT_SIZE < 8) self::unavailable();
        $source = self::source($capture, $lockedPayment);
        self::keys($context, ['schema','cut_id','actions','refunds','operations','ranges','logs']);
        if ($context['schema'] !== 2) self::unavailable();
        foreach (['actions','refunds','operations','ranges','logs'] as $name) self::list($context[$name]);
        if ($context['actions'] === []) {
            if ($context['cut_id'] !== null) self::unavailable();
            foreach ($context['refunds'] as $refund) {
                if (!is_array($refund)) self::unavailable();
                foreach (self::REFUND_ADDITIONS as $key) if (!array_key_exists($key, $refund) || $refund[$key] !== null) self::unavailable();
            }
            foreach ($context['operations'] as $operation) {
                if (!is_array($operation) || self::integer($operation['contract_version'] ?? null, 1, 1) !== 1
                    || !array_key_exists('aftersale_cut_id', $operation) || $operation['aftersale_cut_id'] !== null) self::unavailable();
            }
            $children = self::legacyCredits($source['identity'], $source['groups'], $capture['credit_rows']);
            return self::output($capture, $source, $children);
        }
        $winner = self::winner($source, $lockedPayment);
        $actions = self::actions($context['actions'], $source, $winner);
        if (self::u64($context['cut_id']) !== $actions[count($actions) - 1]['id']) self::unavailable();
        $fulfillment = self::fulfillment($context, $source, $actions);
        $timeline = self::timeline($actions, $fulfillment['operations']);
        $history = self::replay($timeline, $source, $fulfillment);
        self::logs($context['logs'],$source,$history['expected_logs']);
        self::mirrors($context['refunds'], $source, $winner, $history);
        $children = self::newCredits($capture['credit_rows'], $source, $history['movements']);
        return self::output($capture, $source, $children);
    }

    private static function source(array $capture, array $payment): array
    {
        self::keys($capture, ['root','items','selection','reserves','credit_rows','child_orders']);
        if (!is_array($capture['root'])) self::unavailable(); self::nativeRow($capture['root']);
        $root = $capture['root'];
        $identity = ['id' => self::integer($root['id'] ?? null, 1, self::INT_MAX),
            'uid' => self::integer($root['uid'] ?? null, 1, self::INT_MAX),
            'mer_id' => self::integer($root['mer_id'] ?? null, 1, self::UINT_MAX),
            'order_no' => self::text($root['order_id'] ?? null, 32)];
        self::stored($root, ['marketplace_trade_version' => 1, 'pid' => 0]);
        foreach (['items','selection','reserves','credit_rows','child_orders'] as $key) self::list($capture[$key]);
        self::keys($payment, ['ledger','root','merchant','registry','attempts']);
        foreach (['ledger','root','merchant','registry'] as $key) if (!is_array($payment[$key])) self::unavailable();
        self::list($payment['attempts']);
        // Compare actual saved values; do not impose the current four-field payment display/eligibility gate here.
        foreach ($payment['root'] as $key => $value) self::stored($root, [$key => $value]);
        self::stored($payment['root'], ['id'=>$identity['id'],'uid'=>$identity['uid'],'mer_id'=>$identity['mer_id'],'order_id'=>$identity['order_no']]);
        self::stored($payment['ledger'], ['uid'=>$identity['uid'],'mer_id'=>$identity['mer_id'],'order_id'=>$identity['id'],'state'=>1]);
        self::stored($payment['registry'], ['uid'=>$identity['uid'],'mer_id'=>$identity['mer_id'],'order_id'=>$identity['id'],'payment_contract_version'=>1]);
        $refs = self::jsonList($payment['ledger']['cart_refs'] ?? null, 65536);
        $items = []; $last = 0;
        foreach ($capture['items'] as $row) {
            if (!is_array($row)) self::unavailable(); self::keys($row, self::ITEM_FIELDS); self::nativeRow($row);
            $id = self::integer($row['id'], 1, self::INT_MAX); $qty = self::integer($row['cart_num'], 1, self::INT_MAX);
            if ($id <= $last) self::unavailable(); $last = $id;
            self::stored($row, ['oid'=>$identity['id'],'uid'=>$identity['uid'],'mer_id'=>$identity['mer_id']]);
            self::integer($row['refund_num'], 0, $qty); self::integer($row['surplus_num'], 0, $qty);
            self::integer($row['split_status'], 0, 2); self::text($row['unique'], 32);
            $items[$id] = $row;
        }
        if ($items === [] || count($refs) !== count($items) || count($capture['selection']) !== count($items)) self::unavailable();
        $commercial=MarketplaceOrderFulfillmentPlan::refundSavedSource($root,$capture['items'],$capture['reserves']);
        $exact=[]; foreach($items as $id=>$item) $exact[]=['source_item_id'=>$id,'unit_start'=>0,'unit_end'=>self::integer($item['cart_num'],1,self::INT_MAX)];
        $plan=MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($root,$capture['items'],$capture['reserves'],$exact);
        $lines=$commercial['lines'];
        if($plan['source_snapshot_sha256']!==$commercial['fingerprint']) self::unavailable();
        $groups = []; $seen = [];
        foreach ($capture['selection'] as $position => $line) {
            if (!is_array($line)) self::unavailable();
            self::keys($line, ['item_id','cart_ref','product_id','sku_id','sku_type','unique','qty','cart_info']);
            $id = self::integer($line['item_id'], 1, self::INT_MAX); $item = $items[$id] ?? null; $saved = $lines[$id] ?? null;
            if ($item === null || $saved === null || isset($seen[$id]) || !is_array($line['cart_info'])
                || !is_string($refs[$position]) || $line['cart_ref'] !== $refs[$position] || $item['cart_id'] !== $line['cart_ref']) self::unavailable();
            $seen[$id] = true;
            self::stored($line, ['product_id'=>$saved['product_id'],'sku_id'=>$saved['sku_id'],'sku_type'=>0,
                'unique'=>$saved['sku_unique'],'qty'=>$saved['original_quantity']]);
            $actual = self::jsonObject($item['cart_info'], 65535);
            if (!self::same($actual, $line['cart_info'])) self::unavailable();
            $parentId = self::u64($saved['reserve_id']); $key = 'r:' . $parentId;
            if (!isset($groups[$key])) $groups[$key] = ['parent'=>null,'product_id'=>$saved['product_id'],
                'sku_id'=>$saved['sku_id'],'unique'=>$saved['sku_unique'],'qty'=>0,'cart_refs'=>[]];
            $group = &$groups[$key];
            self::stored($group, ['product_id'=>$saved['product_id'],'sku_id'=>$saved['sku_id'],'unique'=>$saved['sku_unique']]);
            $group['qty'] += $saved['original_quantity']; self::integer($group['qty'], 1, self::UINT_MAX);
            $group['cart_refs'][] = $line['cart_ref']; unset($group);
        }
        $reserves = []; $previous = '0';
        foreach ($capture['reserves'] as $raw) {
            if (!is_array($raw)) self::unavailable(); $row = self::movement($raw); $id = $row['id']; $key = 'r:' . $id;
            if (self::compareId($id, $previous) <= 0 || !isset($groups[$key]) || $groups[$key]['parent'] !== null) self::unavailable();
            $previous = $id; $group = $groups[$key];
            self::stored($row, ['mer_id'=>$identity['mer_id'],'order_id'=>$identity['id'],'product_id'=>$group['product_id'],
                'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],'kind'=>'reserve',
                'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>'customer','actor_id'=>$identity['uid'],
                'idem_key'=>'reserve:'.$identity['id'].':'.$group['sku_id']]);
            self::digest($row, ['contract_version'=>1,'uid'=>$identity['uid'],'mer_id'=>$identity['mer_id'],'order_id'=>$identity['id'],
                'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],
                'qty'=>$group['qty'],'kind'=>'reserve','cart_refs'=>$group['cart_refs']]);
            self::arithmetic($row, false); $groups[$key]['parent'] = $row; $reserves[] = $row;
        }
        if (count($reserves) !== count($groups)) self::unavailable();
        foreach ($groups as $group) if ($group['parent'] === null) self::unavailable();
        $children = []; $previous = 0;
        foreach ($capture['child_orders'] as $row) {
            if (!is_array($row)) self::unavailable(); self::keys($row, self::CHILD_FIELDS); self::nativeRow($row);
            $id = self::integer($row['id'], 1, self::INT_MAX);
            if ($id <= $previous) self::unavailable(); $previous = $id;
            self::stored($row, ['pid'=>$identity['id']]); self::integer($row['uid'], 0, self::INT_MAX);
            if ($row['mer_id'] !== null) self::integer($row['mer_id'], 0, self::UINT_MAX);
            if ($row['marketplace_trade_version'] !== null) self::integer($row['marketplace_trade_version'], 0, 255);
            self::text($row['order_id'], 32); self::integer($row['paid'], 0, 1); self::signedStatus($row['status']);
            self::integer($row['refund_status'], 0, 255); self::integer($row['is_cancel'], 0, 1); $children[$id] = $row;
        }
        return ['root'=>$root,'identity'=>$identity,'items'=>$items,'lines'=>$lines,'groups'=>$groups,'reserves'=>$reserves,
            'child_orders'=>$children,'capture'=>$capture,'plan'=>$plan,'commercial'=>$commercial];
    }

    private static function output(array $capture, array $source, array $children): array
    {
        return ['root'=>$source['identity'],'items'=>$capture['items'],'selection'=>$capture['selection'],
            'reserves'=>$source['reserves'],'children'=>$children,'child_orders'=>$capture['child_orders']];
    }

    private static function winner(array $source, array $payment): string
    {
        $winner = self::u64($payment['registry']['winner_attempt_id'] ?? null); $found = 0; $applications = 0; $previous = '0';
        $amount = self::money($source['root']['pay_price'] ?? null);
        foreach ($payment['attempts'] as $attempt) {
            if (!is_array($attempt)) self::unavailable(); $id = self::u64($attempt['id'] ?? null);
            if (self::compareId($id, $previous) <= 0) self::unavailable(); $previous = $id;
            self::stored($attempt, ['order_id'=>$source['identity']['id'],'uid'=>$source['identity']['uid'],
                'mer_id'=>$source['identity']['mer_id'],'order_no'=>$source['identity']['order_no'],'currency'=>'CNY','amount'=>$amount]);
            $evidence = $attempt['evidence'] ?? null;
            if (!is_array($evidence) || !array_key_exists('application', $evidence)) self::unavailable();
            $application = $evidence['application'];
            if ($application !== null) {
                if (!is_array($application) || $id !== $winner || ($application['attempt_id'] ?? null) !== $winner
                    || ($application['order_pk'] ?? null) !== $source['identity']['id'] || !is_array($application['root_payment'] ?? null)) self::unavailable();
                $originalPayment = $application['root_payment']; self::keys($originalPayment, ['paid','pay_time','pay_type','trade_no']);
                if ($originalPayment['paid'] !== 1 || !is_int($originalPayment['pay_time']) || $originalPayment['pay_time'] < 1
                    || $originalPayment['pay_time'] > self::UINT_MAX) self::unavailable();
                self::text($originalPayment['pay_type'], 32); self::text($originalPayment['trade_no'], 128); ++$applications;
            }
            if ($id === $winner) { if ($application === null) self::unavailable(); ++$found; }
        }
        if ($found !== 1 || $applications !== 1) self::unavailable();
        return $winner;
    }

    private static function actions(array $rows, array $source, string $winner): array
    {
        $out=[]; $previous='0'; $keys=[];
        foreach($rows as $row){
            if(!is_array($row)) self::unavailable(); self::keys($row,self::ACTION_FIELDS);
            foreach(['id','payment_attempt_id'] as $key) $row[$key]=self::u64($row[$key]);
            foreach(['previous_refund_action_id','fulfillment_cut_id'] as $key)
                $row[$key]=$row[$key]===null?null:self::u64($row[$key]);
            $row['apply_refund_id']=$row['apply_refund_id']===null?null:self::integer($row['apply_refund_id'],1,self::INT_MAX);
            foreach(['root_order_id','uid','refund_id'] as $key) $row[$key]=self::integer($row[$key],1,self::INT_MAX);
            $row['mer_id']=self::integer($row['mer_id'],1,self::UINT_MAX);
            self::stored($row,['root_order_id'=>$source['identity']['id'],'uid'=>$source['identity']['uid'],
                'mer_id'=>$source['identity']['mer_id'],'payment_attempt_id'=>$winner,'contract_version'=>1,'state'=>1]);
            if(self::compareId($row['id'],$previous)<=0) self::unavailable(); $previous=$row['id'];
            if(!in_array($row['action'],['apply','withdraw','approve','reject','express','accept_return','finance_observe'],true)) self::unavailable();
            $row['created_at']=self::integer($row['created_at'],1,self::UINT_MAX);
            $row['committed_at']=self::integer($row['committed_at'],$row['created_at'],self::UINT_MAX);
            if(in_array($row['action'],['apply','withdraw','express'],true)){
                $row['actor_id']=self::integer($row['actor_id'],1,self::INT_MAX);
                if($row['actor_type']!=='customer'||$row['actor_id']!==$row['uid']) self::unavailable();
            }elseif($row['action']==='finance_observe'){
                if($row['actor_type']!=='payment'||$row['actor_id']!==null) self::unavailable();
            }else{
                if(!in_array($row['actor_type'],['employee','admin'],true)) self::unavailable();
                $row['actor_id']=self::integer($row['actor_id'],1,self::UINT_MAX);
            }
            $actor=$row['actor_type'].':'.($row['actor_type']==='payment'?$winner:(string)$row['actor_id']);
            if($row['actor_ref']!==$actor) self::unavailable();
            self::asciiKey($row['idem_key']); $key=$actor."\0".$row['idem_key'];
            if(isset($keys[$key])) self::unavailable(); $keys[$key]=true;
            $request=self::jsonObject($row['request_payload'],65536);
            self::keys($request,['schema','action','root_order_id','refund_id','public_body']);
            self::exact($request,['schema'=>1,'action'=>$row['action'],'root_order_id'=>$row['root_order_id'],'refund_id'=>$row['refund_id']]);
            $body=self::object($request['public_body']);
            $normal=match($row['action']){
                'apply'=>MarketplaceOrderRefundInput::apply($body),
                'withdraw'=>MarketplaceOrderRefundInput::withdraw($body),
                'approve'=>MarketplaceOrderRefundInput::approve($body),
                'reject'=>MarketplaceOrderRefundInput::reject($body),
                'express'=>MarketplaceOrderRefundInput::express($body),
                'accept_return'=>MarketplaceOrderRefundInput::acceptReturn($body),
                'finance_observe'=>self::financeBody($body),
            };
            if(!self::same($body,$normal)||($row['action']!=='finance_observe'&&$normal['idem_key']!==$row['idem_key'])) self::unavailable();
            $request['public_body']=$normal;
            self::digest($row,['actor_ref'=>$actor,'request_payload'=>$request]);
            $result=self::jsonObject($row['result_payload'],1048576);
            self::keys($result,['schema','before','after','allocations','movements','public_result']);
            if($result['schema']!==1) self::unavailable();
            $result['before']=$result['before']===null?null:self::state(self::object($result['before']));
            $result['after']=self::state(self::object($result['after']));
            $result['allocations']=self::allocations($result['allocations'],$source);
            self::list($result['movements']); $movements=[];
            foreach($result['movements'] as $movement){
                $movement=self::object($movement);
                self::keys($movement,['movement_id','kind','source_item_id','reserve_id','unit_start','unit_end','quantity']);
                if(!is_string($movement['movement_id'])||!is_string($movement['reserve_id'])) self::unavailable();
                self::u64($movement['movement_id']); self::u64($movement['reserve_id']);
                foreach(['source_item_id','unit_start','unit_end','quantity'] as $field) if(!is_int($movement[$field])) self::unavailable();
                if(!in_array($movement['kind'],['release','return_in'],true)) self::unavailable();
                $movements[]=$movement;
            }
            $result['movements']=$movements; $result['public_result']=self::object($result['public_result']);
            $row['request_payload']=$request; $row['result_payload']=$result; $out[]=$row;
        }
        return $out;
    }

    private static function financeBody(array $body): array
    {
        self::keys($body,['source_ref','observed_state','currency','confirmed_amount','observed_at']);
        self::text($body['source_ref'],65536);
        if(!in_array($body['observed_state'],['PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED'],true)||$body['currency']!=='CNY'
            ||!is_int($body['observed_at'])) self::unavailable();
        self::integer($body['observed_at'],1,self::UINT_MAX); self::money($body['confirmed_amount']); return $body;
    }

    private static function state(array $state): array
    {
        self::keys($state,self::STATE_FIELDS);
        if(!in_array($state['business_state'],['REQUESTED','APPROVED','WITHDRAWN','REJECTED','COMPLETED'],true)
            ||!in_array($state['physical_state'],['NONE','CANCEL_PENDING','CANCELLED','RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED'],true)
            ||!in_array($state['finance_state'],['NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED'],true)
            ||!is_int($state['refund_type'])||!is_int($state['is_cancel'])) self::unavailable();
        self::integer($state['refund_type'],1,6); self::integer($state['is_cancel'],0,1);
        foreach(['requested_amount','confirmed_amount','held_amount'] as $key) self::money($state[$key]);
        if(bccomp($state['requested_amount'],'0.00',2)<=0||bccomp($state['confirmed_amount'],$state['requested_amount'],2)>0) self::unavailable();
        $terminal=in_array($state['business_state'],['WITHDRAWN','REJECTED'],true);
        if($state['held_amount']!==($terminal?'0.00':bcsub($state['requested_amount'],$state['confirmed_amount'],2))) self::unavailable();
        if($state['return_delivery']!==null){
            $state['return_delivery']=self::object($state['return_delivery']);
            self::keys($state['return_delivery'],['carrier_name','tracking_no']);
            self::characters($state['return_delivery']['carrier_name'],255); self::characters($state['return_delivery']['tracking_no'],100);
        }
        return $state;
    }

    private static function allocations(mixed $rows,array $source): array
    {
        self::list($rows); if($rows===[]) self::unavailable(); $out=[]; $last=0; $end=0;
        foreach($rows as $row){
            $row=self::object($row); self::keys($row,self::ALLOCATION_FIELDS);
            foreach(['source_item_id','unit_start','unit_end','quantity'] as $key) if(!is_int($row[$key])) self::unavailable();
            $id=self::integer($row['source_item_id'],1,self::INT_MAX); $line=$source['lines'][$id]??null;
            if($line===null||!is_string($row['reserve_id'])||$row['reserve_id']!==$line['reserve_id']) self::unavailable();
            self::integer($row['unit_start'],0,$line['original_quantity']-1);
            self::integer($row['unit_end'],$row['unit_start']+1,$line['original_quantity']);
            if($id<$last||($id===$last&&$row['unit_start']<$end)||$row['quantity']!==$row['unit_end']-$row['unit_start']) self::unavailable();
            $last=$id; $end=$row['unit_end'];
            foreach(['goods','postage'] as $key){
                self::money($row[$key]);
                if($row[$key]!==MarketplaceOrderFulfillmentPlan::sliceMoney($line[$key],$line['original_quantity'],$row['unit_start'],$row['unit_end'])) self::unavailable();
            }
            foreach(['requested_amount','confirmed_amount'] as $key) self::money($row[$key]);
            if(bccomp($row['confirmed_amount'],$row['requested_amount'],2)>0||bccomp($row['requested_amount'],bcadd($row['goods'],$row['postage'],2),2)>0) self::unavailable();
            if($row['source_ship_range_id']===null){
                if($row['child_order_id']!==null||$row['child_item_id']!==null) self::unavailable();
            }else{
                if(!is_string($row['source_ship_range_id'])||!is_int($row['child_order_id'])||!is_int($row['child_item_id'])) self::unavailable();
                self::u64($row['source_ship_range_id']); self::integer($row['child_order_id'],1,self::INT_MAX); self::integer($row['child_item_id'],1,self::INT_MAX);
            }
            $out[]=$row;
        }
        return $out;
    }

    private static function fulfillment(array $context,array $source,array $actions): array
    {
        $actionIds=[]; foreach($actions as $action) $actionIds['a:'.$action['id']]=true;
        $operations=[]; $map=[]; $last='0'; $lastTime=0; $usedKeys=[];
        foreach($context['operations'] as $row){
            if(!is_array($row)) self::unavailable(); self::keys($row,self::OP_FIELDS);
            foreach(['id','target_operation_id','aftersale_cut_id'] as $key) $row[$key]=$row[$key]===null?null:self::u64($row[$key]);
            foreach(['root_order_id','child_order_id','uid'] as $key) $row[$key]=self::integer($row[$key],1,self::INT_MAX);
            $row['mer_id']=self::integer($row['mer_id'],1,self::UINT_MAX);
            $row['contract_version']=self::integer($row['contract_version'],1,2); $row['state']=self::integer($row['state'],1,1);
            foreach(['ship_child_order_id','actor_id','created_at','committed_at','shipped_at','receipt_due_at','received_at'] as $key)
                $row[$key]=$row[$key]===null?null:self::integer($row[$key],0,$key==='ship_child_order_id'?self::INT_MAX:self::UINT_MAX);
            self::stored($row,['root_order_id'=>$source['identity']['id'],'uid'=>$source['identity']['uid'],'mer_id'=>$source['identity']['mer_id']]);
            if($row['id']===null||self::compareId($row['id'],$last)<=0||!isset($source['child_orders'][$row['child_order_id']])
                ||$row['created_at']<1||$row['committed_at']===null||$row['committed_at']<$row['created_at']||$row['committed_at']<$lastTime) self::unavailable();
            $last=$row['id']; $lastTime=$row['committed_at']; $child=$source['child_orders'][$row['child_order_id']];
            self::stored($child,['uid'=>$row['uid'],'mer_id'=>$row['mer_id'],'marketplace_trade_version'=>1]);
            if(($row['contract_version']===1&&$row['aftersale_cut_id']!==null)
                ||($row['contract_version']===2&&($row['aftersale_cut_id']===null||!isset($actionIds['a:'.$row['aftersale_cut_id']])))) self::unavailable();
            foreach(['request_digest','source_snapshot_digest','allocation_digest'] as $key) self::binary32($row[$key]);
            self::asciiKey($row['idem_key']); $unique=$row['action']."\0".$row['idem_key'];
            if(isset($usedKeys[$unique])) self::unavailable(); $usedKeys[$unique]=true;
            $request=self::treeObject(self::jsonObject($row['request_payload'],65536));
            if($row['action']==='ship'){
                self::keys($request,['schema','action','root_order_id','quantities','delivery']);
                $normal=MarketplaceOrderFulfillmentInput::ship($row['root_order_id'],['idem_key'=>$row['idem_key'],
                    'quantities'=>$request['quantities'],'delivery'=>$request['delivery']]);
                if($row['target_operation_id']!==null||$row['target_action']!==null||$row['ship_child_order_id']!==$row['child_order_id']
                    ||$row['actor_type']!=='employee'||$row['actor_id']===null||$row['actor_id']<1
                    ||$row['shipped_at']!==$row['created_at']||$row['received_at']!==null||$row['receipt_due_at']===null
                    ||($row['receipt_due_at']!==0&&$row['receipt_due_at']<$row['shipped_at'])) self::unavailable();
            }elseif($row['action']==='receive'){
                self::keys($request,['schema','action','root_order_id','child_order_id','selection']);
                $normal=MarketplaceOrderFulfillmentInput::receive($row['root_order_id'],$row['child_order_id'],
                    ['idem_key'=>$row['idem_key'],'selection'=>$request['selection']]);
                if($row['target_operation_id']===null||$row['target_action']!=='ship'||$row['ship_child_order_id']!==null
                    ||$row['shipped_at']!==null||$row['receipt_due_at']!==null||$row['received_at']!==$row['created_at']
                    ||!(($row['actor_type']==='customer'&&$row['actor_id']===$row['uid'])||($row['actor_type']==='system'&&$row['actor_id']===null))) self::unavailable();
            }else self::unavailable();
            if(!self::same($request,$normal['request'])||!hash_equals($row['request_digest'],hash('sha256',self::canonical($request),true))) self::unavailable();
            $result=self::jsonObject($row['result_payload'],1048576);
            $fields=['schema','operation_id','action','root_order_id','root_order_no','child_order_id','child_order_no','target_operation_id',
                'actor_type','actor_id','created_at','committed_at','shipped_at','receipt_due_at','received_at','plan','ranges','summary','public_result'];
            if($row['contract_version']===2) $fields=array_merge($fields,['aftersale_cut_id','aftersale']);
            self::keys($result,$fields);
            self::exact($result,['schema'=>$row['contract_version'],'operation_id'=>$row['id'],'action'=>$row['action'],
                'root_order_id'=>$row['root_order_id'],'root_order_no'=>$source['identity']['order_no'],
                'child_order_id'=>$row['child_order_id'],'child_order_no'=>$child['order_id'],'target_operation_id'=>$row['target_operation_id'],
                'actor_type'=>$row['actor_type'],'actor_id'=>$row['actor_id'],'created_at'=>$row['created_at'],'committed_at'=>$row['committed_at'],
                'shipped_at'=>$row['shipped_at'],'receipt_due_at'=>$row['receipt_due_at'],'received_at'=>$row['received_at']]);
            $result['plan']=self::treeObject($result['plan']);
            $expectedPlan=MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($source['root'],array_values($source['items']),$source['reserves'],
                self::coordinates($result['plan']['allocations']??[]));
            if(!self::same($result['plan'],$expectedPlan)) self::unavailable();
            if($result['plan']['source_snapshot_sha256']!==$source['plan']['source_snapshot_sha256']
                ||!hash_equals($row['source_snapshot_digest'],hex2bin($result['plan']['source_snapshot_sha256']))) self::unavailable();
            $result['ranges']=self::treeList($result['ranges']); $result['summary']=self::treeObject($result['summary']);
            $result['public_result']=self::treeObject($result['public_result']);
            if($row['contract_version']===2){
                if($result['aftersale_cut_id']!==$row['aftersale_cut_id']) self::unavailable();
                $result['aftersale']=self::treeObject($result['aftersale']);
            }
            $row['request_payload']=$normal['request']; $row['result_payload']=$result;
            $operations[]=$row; $map['o:'.$row['id']]=$row;
        }
        $rangeFields=['id','operation_id','operation_action','root_order_id','child_order_id','uid','mer_id','source_item_id','child_item_id',
            'reserve_id','source_ship_range_id','source_range_action','product_id','sku_id','sku_type','sku_unique','cart_ref',
            'original_quantity','unit_start','unit_end','quantity','unit_price','price_type','goods','postage','origin_postage','payable','cost','base_goods','discount'];
        $ranges=[]; $byOperation=[]; $last='0'; $childItems=[];
        foreach($context['ranges'] as $range){
            if(!is_array($range)) self::unavailable(); self::keys($range,$rangeFields);
            foreach(['id','operation_id','reserve_id','source_ship_range_id'] as $key) $range[$key]=$range[$key]===null?null:self::u64($range[$key]);
            foreach(['root_order_id','child_order_id','uid','source_item_id','child_item_id','product_id','sku_id','sku_type','original_quantity','unit_start','unit_end','quantity'] as $key)
                $range[$key]=self::integer($range[$key],0,self::INT_MAX);
            $range['mer_id']=self::integer($range['mer_id'],1,self::UINT_MAX);
            if($range['id']===null||self::compareId($range['id'],$last)<=0||!isset($map['o:'.$range['operation_id']])) self::unavailable();
            $last=$range['id']; $op=$map['o:'.$range['operation_id']];
            self::stored($range,['operation_action'=>$op['action'],'root_order_id'=>$op['root_order_id'],'child_order_id'=>$op['child_order_id'],
                'uid'=>$op['uid'],'mer_id'=>$op['mer_id']]);
            self::integer($range['source_item_id'],1,self::INT_MAX); self::integer($range['child_item_id'],1,self::INT_MAX);
            if(isset($childItems[$range['child_item_id']])&&$childItems[$range['child_item_id']]!==[$range['child_order_id'],$range['source_item_id']]) self::unavailable();
            $childItems[$range['child_item_id']]=[$range['child_order_id'],$range['source_item_id']];
            $ranges['r:'.$range['id']]=$range; $byOperation['o:'.$range['operation_id']][]=$range;
        }
        $ships=[];
        foreach($operations as &$op){
            $actual=$byOperation['o:'.$op['id']]??[]; $saved=$op['result_payload']; $plan=$saved['plan'];
            if(count($actual)!==count($plan['allocations'])||count($saved['ranges'])!==count($actual)) self::unavailable();
            $digestAllocations=[];
            foreach($plan['allocations'] as $i=>$allocation){
                $ref=self::object($saved['ranges'][$i]);
                self::keys($ref,['range_id','source_item_id','child_item_id','source_ship_range_id','unit_start','unit_end']);
                self::exact($ref,['range_id'=>$actual[$i]['id'],'source_item_id'=>$allocation['source_item_id'],
                    'child_item_id'=>$actual[$i]['child_item_id'],'source_ship_range_id'=>$actual[$i]['source_ship_range_id'],
                    'unit_start'=>$allocation['unit_start'],'unit_end'=>$allocation['unit_end']]);
                $expected=['id'=>$ref['range_id'],'operation_id'=>$op['id'],'operation_action'=>$op['action'],'root_order_id'=>$op['root_order_id'],
                    'child_order_id'=>$op['child_order_id'],'uid'=>$op['uid'],'mer_id'=>$op['mer_id'],'source_item_id'=>$allocation['source_item_id'],
                    'child_item_id'=>$ref['child_item_id'],'reserve_id'=>$allocation['reserve_id'],'source_ship_range_id'=>$ref['source_ship_range_id'],
                    'source_range_action'=>$op['action']==='ship'?null:'ship'];
                foreach(['product_id','sku_id','sku_type','sku_unique','cart_ref','original_quantity','unit_start','unit_end','quantity','unit_price',
                    'price_type','goods','postage','origin_postage','payable','cost','base_goods','discount'] as $key) $expected[$key]=$allocation[$key];
                if(!self::same($actual[$i],$expected)) self::unavailable();
                if($op['action']==='ship'){
                    if($ref['source_ship_range_id']!==null) self::unavailable();
                }else{
                    $parent=$ranges['r:'.$ref['source_ship_range_id']]??null;
                    if($parent===null||$parent['operation_action']!=='ship'||$parent['operation_id']!==$op['target_operation_id']
                        ||$parent['child_order_id']!==$op['child_order_id']||$parent['child_item_id']!==$ref['child_item_id']
                        ||$parent['source_item_id']!==$allocation['source_item_id']||$allocation['unit_start']<$parent['unit_start']
                        ||$allocation['unit_end']>$parent['unit_end']||self::compareId($parent['operation_id'],$op['id'])>=0) self::unavailable();
                }
                $digestAllocations[]=$allocation+['source_ship_range_id'=>$ref['source_ship_range_id']];
            }
            $base=['schema'=>1,'action'=>$op['action'],'root_order_id'=>$op['root_order_id'],'child_order_id'=>$op['child_order_id'],
                'source_snapshot_sha256'=>$plan['source_snapshot_sha256'],'target_operation_id'=>$op['target_operation_id'],'allocations'=>$digestAllocations];
            $digest=$op['contract_version']===1?$base:['schema'=>2,'base'=>$base,'aftersale_cut_id'=>$op['aftersale_cut_id'],'aftersale'=>$saved['aftersale']];
            if(!hash_equals($op['allocation_digest'],hash('sha256',self::canonical($digest),true))) self::unavailable();
            if($op['action']==='ship'){
                if(isset($ships[$op['child_order_id']])) self::unavailable(); $ships[$op['child_order_id']]=$op;
            }elseif(!isset($ships[$op['child_order_id']])||$ships[$op['child_order_id']]['id']!==$op['target_operation_id']) self::unavailable();
            $op['validated_ranges']=$actual;
        } unset($op);
        if(count($ships)!==count($source['child_orders'])||count($byOperation)!==count($operations)) self::unavailable();
        return ['operations'=>$operations,'ranges'=>$ranges,'ships'=>$ships];
    }

    /** Unique merge of two committed-prefix streams. Seconds never substitute for either saved cut. */
    private static function timeline(array $actions,array $operations): array
    {
        $a=0; $o=0; $lastA=null; $lastO=null; $out=[];
        while($a<count($actions)||$o<count($operations)){
            $action=$actions[$a]??null; $operation=$operations[$o]??null;
            $readyA=$action!==null&&$action['fulfillment_cut_id']===$lastO;
            $readyO=$operation!==null&&$operation['aftersale_cut_id']===$lastA;
            if($readyA===$readyO) self::unavailable();
            if($readyA){ $out[]=['type'=>'action','row'=>$action]; $lastA=$action['id']; ++$a; }
            else{ $out[]=['type'=>'operation','row'=>$operation]; $lastO=$operation['id']; ++$o; }
        }
        return $out;
    }

    private static function replay(array $timeline,array $source,array $fulfillment): array
    {
        $refunds=[]; $ships=[]; $received=[]; $cancel=[]; $returned=[]; $movements=[]; $latest=null; $completed=null; $priorStatus=0; $expectedLogs=[];
        foreach($timeline as $event){
            $row=$event['row'];
            if($event['type']==='action'){
                self::actionEvent($row,$source,$fulfillment,$refunds,$ships,$received,$cancel,$returned,$movements);
                $latest=$row['id'];
            }else{
                $ranges=$row['validated_ranges']; $pending=self::pending($refunds);
                if($row['action']==='ship'){
                    $occupied=self::coordinates(array_merge($ships,$cancel,$pending['cancel']));
                    $plan=self::quantityPlan($source,$occupied,$row['request_payload']['quantities']);
                    if(!self::same($plan,$row['result_payload']['plan'])) self::unavailable();
                    self::disjoint($ranges,array_merge($ships,$cancel,$pending['cancel'])); $ships=array_merge($ships,$ranges);
                }else{
                    $parents=[];
                    foreach($ships as $range) if($range['child_order_id']===$row['child_order_id']) $parents[]=self::withShipParent($range);
                    $eligible=self::subtract($parents,array_merge($received,$returned,$pending['return']));
                    $selected=self::selectReceive($eligible,$row['request_payload']['selection']);
                    self::samePhysical($ranges,$selected,true);
                    if($row['actor_type']==='system'){
                        $ship=$fulfillment['ships'][$row['child_order_id']];
                        if($ship['receipt_due_at']===0||$ship['receipt_due_at']>$row['received_at']
                            ||!self::same($row['request_payload']['selection'],['mode'=>'all_eligible'])) self::unavailable();
                        $key='auto:'.$ship['id'].':'.hash('sha256',self::canonical(self::coordinates($eligible,true)));
                        if($row['idem_key']!==$key) self::unavailable();
                    }
                    self::disjoint($ranges,array_merge($received,$returned,$pending['return'])); $received=array_merge($received,$ranges);
                }
            }
            $summary=self::summary($source,$ships,$received,$cancel,$returned);
            if($summary['closed']&&$completed===null) $completed=$row['created_at'];
            if(!$summary['closed']) $completed=null;
            $nextStatus=$summary['to_ship']>0?0:($summary['to_receive']>0?1:($summary['shipped']>0?2:0));
            if($event['type']==='operation'&&$nextStatus!==$priorStatus) $expectedLogs[]=['oid'=>$source['identity']['id'],
                'change_type'=>$row['action']==='ship'?'marketplace_fulfillment_ship':'marketplace_fulfillment_receive',
                'change_message'=>$row['action']==='ship'?'多商户履约发货状态更新':'多商户履约收货状态更新','change_time'=>$row['created_at']];
            $priorStatus=$nextStatus;
            if($event['type']==='operation') self::operationResult($row,$source,$summary,
                $latest===null?null:self::aftersale($source,$refunds,$received,$returned,$latest,$completed));
            self::financialBudget($source,$refunds);
        }
        return ['refunds'=>$refunds,'movements'=>$movements,'ships'=>$ships,'received'=>$received,'cancel'=>$cancel,'returned'=>$returned,'expected_logs'=>$expectedLogs,
            'fulfillment_completed_at'=>$completed];
    }

    private static function actionEvent(array $action,array $source,array $fulfillment,array &$refunds,array $ships,array $received,
        array &$cancel,array &$returned,array &$movements): void
    {
        $id=$action['refund_id']; $result=$action['result_payload']; $body=$action['request_payload']['public_body'];
        $prior=$refunds[$id]??null; $after=$result['after']; $allocations=$result['allocations']; $kind=null; $newPhysical=[];
        if($action['action']==='apply'){
            if($prior!==null||$result['before']!==null||$action['previous_refund_action_id']!==null||$action['apply_refund_id']!==$id) self::unavailable();
            $kind=$body['operation_kind']; $pending=self::pending($refunds);
            if($kind==='return_goods'){
                $child=self::childId($source,$body['selection']['child_order_no']);
                $selected=self::childCoordinates($body['selection']['items'],$child,$fulfillment);
                self::contained($selected,$ships); self::disjoint($selected,array_merge($returned,$pending['return']));
            }else{
                $requests=[]; foreach($body['selection']['items'] as $item) $requests[]=['source_item_id'=>$item['item_id'],'quantity'=>$item['quantity']];
                if($kind==='money_only') $plan=self::moneySelection($source,$requests,$refunds);
                else $plan=self::quantityPlan($source,self::coordinates(array_merge($ships,$cancel,$pending['cancel'])),$requests);
                $selected=$plan['allocations'];
                foreach($allocations as $allocation) if($allocation['source_ship_range_id']!==null) self::unavailable();
            }
            self::samePhysical($allocations,$selected,$kind==='return_goods');
            $claims=self::allocateMoney($source,$allocations,$body['amount'],$refunds);
            $remaining=$body['amount'];
            foreach($allocations as $index=>$allocation){
                if($allocation['requested_amount']!==$claims['amounts'][$index]||$allocation['confirmed_amount']!=='0.00') self::unavailable();
                $remaining=bcsub($remaining,$allocation['requested_amount'],2);
            }
            if($remaining!=='0.00') self::unavailable();
            $expected=['business_state'=>'REQUESTED','physical_state'=>match($kind){'money_only'=>'NONE','cancel_unshipped'=>'CANCEL_PENDING','return_goods'=>'RETURN_PENDING'},
                'finance_state'=>'NOT_SUBMITTED','requested_amount'=>$body['amount'],'confirmed_amount'=>'0.00','held_amount'=>$body['amount'],
                'refund_type'=>$kind==='return_goods'?2:1,'is_cancel'=>0,'return_delivery'=>null];
            if(!self::same($after,$expected)||$result['movements']!==[]) self::unavailable();
            $prior=['apply'=>$action,'last'=>$action,'kind'=>$kind,'allocations'=>$allocations,'accepted'=>[],
                'claims'=>$claims['claims'],'confirmed_claims'=>[],'after'=>$after,'actions'=>[]];
        }else{
            if($prior===null||$action['previous_refund_action_id']!==$prior['last']['id']||$action['apply_refund_id']!==null
                ||!self::same($result['before'],$prior['after'])) self::unavailable();
            $kind=$prior['kind']; $before=$prior['after']; $expected=$before;
            foreach($allocations as $index=>$allocation){
                $base=$prior['allocations'][$index]??null;
                if($base===null) self::unavailable(); $base['confirmed_amount']=$allocation['confirmed_amount'];
                if(!self::same($allocation,$base)) self::unavailable();
            }
            if(count($allocations)!==count($prior['allocations'])) self::unavailable();
            switch($action['action']){
                case 'withdraw':
                case 'reject':
                    if($before['business_state']!=='REQUESTED'||$before['confirmed_amount']!=='0.00'
                        ||$before['finance_state']!=='NOT_SUBMITTED'||$prior['accepted']!==[]) self::unavailable();
                    $expected['business_state']=$action['action']==='withdraw'?'WITHDRAWN':'REJECTED';
                    $expected['physical_state']='NONE'; $expected['held_amount']='0.00';
                    $expected['is_cancel']=$action['action']==='withdraw'?1:0;
                    if($action['action']==='reject') $expected['refund_type']=3;
                    break;
                case 'approve':
                    if($before['business_state']!=='REQUESTED') self::unavailable();
                    $expected['business_state']='APPROVED';
                    if(!in_array($after['finance_state'],['NOT_SUBMITTED','UNSUPPORTED'],true)) self::unavailable();
                    $expected['finance_state']=$after['finance_state'];
                    if($kind==='cancel_unshipped'){
                        self::disjoint($allocations,array_merge($ships,$cancel));
                        $cancel=array_merge($cancel,$allocations); $newPhysical=$allocations; $expected['physical_state']='CANCELLED';
                    }elseif($kind==='return_goods') $expected['refund_type']=4;
                    break;
                case 'express':
                    if($before['business_state']!=='APPROVED'||$kind!=='return_goods'
                        ||self::same($before['return_delivery'],$body['return_delivery'])) self::unavailable();
                    $expected['return_delivery']=$body['return_delivery']; $expected['refund_type']=5;
                    break;
                case 'accept_return':
                    if($before['business_state']!=='APPROVED'||$kind!=='return_goods') self::unavailable();
                    $child=self::childId($source,$prior['apply']['request_payload']['public_body']['selection']['child_order_no']);
                    $newPhysical=self::splitSelected(self::childCoordinates($body['items'],$child,$fulfillment),$allocations);
                    self::contained($newPhysical,$allocations); self::contained($newPhysical,$ships); self::disjoint($newPhysical,$returned);
                    $returned=array_merge($returned,$newPhysical); $prior['accepted']=array_merge($prior['accepted'],$newPhysical);
                    $expected['physical_state']=self::quantity($prior['accepted'])===self::quantity($allocations)?'RETURN_ACCEPTED':'RETURN_PARTIAL';
                    $expected['refund_type']=5;
                    break;
                case 'finance_observe':
                    if($before['business_state']!=='APPROVED'||bccomp($body['confirmed_amount'],$before['confirmed_amount'],2)<0
                        ||bccomp($body['confirmed_amount'],$before['requested_amount'],2)>0) self::unavailable();
                    $cap=$kind==='return_goods'?self::acceptedMoney($source,$prior):$before['requested_amount'];
                    if(bccomp($body['confirmed_amount'],$cap,2)>0) self::unavailable();
                    $prior['confirmed_claims']=self::confirmMoney($source,$prior,$body['confirmed_amount']);
                    $expected['confirmed_amount']=$body['confirmed_amount'];
                    $expected['held_amount']=bcsub($before['requested_amount'],$body['confirmed_amount'],2);
                    $expected['finance_state']=$body['observed_state'];
                    if($body['observed_state']==='SUCCEEDED'&&$body['confirmed_amount']!==$before['requested_amount']) self::unavailable();
                    if($body['observed_state']==='PARTIAL'&&(bccomp($body['confirmed_amount'],'0.00',2)<=0
                        ||bccomp($body['confirmed_amount'],$before['requested_amount'],2)>=0)) self::unavailable();
                    if($body['observed_state']==='SUCCEEDED'&&($kind!=='return_goods'||$before['physical_state']==='RETURN_ACCEPTED')){
                        $expected['business_state']='COMPLETED'; $expected['refund_type']=6;
                    }
                    if(self::same($before,$expected)) self::unavailable();
                    break;
                default: self::unavailable();
            }
            if($expected['finance_state']==='SUCCEEDED'&&$expected['confirmed_amount']===$expected['requested_amount']
                &&($kind!=='return_goods'||$expected['physical_state']==='RETURN_ACCEPTED')){
                $expected['business_state']='COMPLETED'; $expected['refund_type']=6;
            }
            if(!self::same($after,$expected)) self::unavailable();
            $confirm='0.00';
            foreach($allocations as $allocation){
                $part=self::claimAmount($source,$prior['confirmed_claims'],[$allocation]);
                if($allocation['confirmed_amount']!==$part) self::unavailable(); $confirm=bcadd($confirm,$part,2);
            }
            if($confirm!==$after['confirmed_amount']) self::unavailable();
            if($action['action']!=='finance_observe'&&$after['confirmed_amount']!==$before['confirmed_amount']) self::unavailable();
        }
        $kindOfMovement=$action['action']==='approve'&&$kind==='cancel_unshipped'?'release':($action['action']==='accept_return'?'return_in':null);
        self::movementReferences($action,$source,$newPhysical,$kindOfMovement,$movements);
        $prior['last']=$action; $prior['after']=$after; $prior['allocations']=$allocations; $prior['actions'][]=$action;
        $refunds[$id]=$prior;
        self::actionPublic($action,$source,$prior,$fulfillment);
    }

    private static function movementReferences(array $action,array $source,array $physical,?string $kind,array &$movements): void
    {
        $refs=$action['result_payload']['movements'];
        if($kind===null){ if($refs!==[]||$physical!==[]) self::unavailable(); return; }
        if($physical===[]||count($physical)!==count($refs)||!in_array($action['actor_type'],['employee','admin'],true)) self::unavailable();
        $byItem=[]; $byId=[];
        foreach($physical as $index=>$part){
            $ref=$refs[$index]; $sourceId=$part['source_item_id']; $line=$source['lines'][$sourceId]??null;
            if($line===null) self::unavailable();
            self::exact($ref,['kind'=>$kind,'source_item_id'=>$sourceId,'reserve_id'=>$line['reserve_id'],
                'unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end'],'quantity'=>$part['unit_end']-$part['unit_start']]);
            $key='m:'.$ref['movement_id'];
            if((isset($byItem[$sourceId])&&$byItem[$sourceId]!==$ref['movement_id'])
                ||(isset($byId[$key])&&$byId[$key]!==$sourceId)||isset($movements[$key])) self::unavailable();
            $byItem[$sourceId]=$ref['movement_id']; $byId[$key]=$sourceId;
        }
        foreach($byItem as $sourceId=>$movementId){
            $segments=[]; $quantity=0; $last=-1;
            foreach($physical as $part) if($part['source_item_id']===$sourceId){
                if($part['unit_start']<$last) self::unavailable(); $last=$part['unit_end'];
                $segments[]=['unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end']]; $quantity+=$part['unit_end']-$part['unit_start'];
            }
            self::integer($quantity,1,self::UINT_MAX); $line=$source['lines'][$sourceId];
            $contract=$kind==='release'?'release_v2':'return_in_v1';
            $digest=['schema'=>1,'credit_contract'=>$contract,'action_id'=>$action['id'],'refund_id'=>$action['refund_id'],
                'root_order_id'=>$source['identity']['id'],'uid'=>$source['identity']['uid'],'mer_id'=>$source['identity']['mer_id'],
                'source_item_id'=>$sourceId,'parent_reserve_id'=>$line['reserve_id'],'product_id'=>$line['product_id'],
                'sku_id'=>$line['sku_id'],'sku_type'=>0,'sku_unique'=>$line['sku_unique'],'kind'=>$kind,'quantity'=>$quantity,
                'segments'=>$segments,'actor_type'=>$action['actor_type'],'actor_id'=>$action['actor_id']];
            $movements['m:'.$movementId]=['id'=>$movementId,'action'=>$action,'source_item_id'=>$sourceId,'line'=>$line,
                'quantity'=>$quantity,'kind'=>$kind,'idem_key'=>$contract.':'.$action['id'].':'.$sourceId,'digest'=>$digest];
        }
    }

    private static function newCredits(array $rows,array $source,array $expected): array
    {
        $out=[]; $seen=[]; $credited=[]; $released=[]; $previous='0';
        foreach($source['groups'] as $key=>$group){$credited[$key]=0; $released[$key]=false;}
        foreach($rows as $raw){
            if(!is_array($raw)) self::unavailable(); $row=self::movement($raw); $id=$row['id']; $parent=$row['parent_reserve_id'];
            if(self::compareId($id,$previous)<=0||$parent===null||!isset($source['groups']['r:'.$parent])) self::unavailable(); $previous=$id;
            $key='r:'.$parent; $group=$source['groups'][$key]; $qty=self::integer($row['qty'],1,self::UINT_MAX);
            self::stored($row,['mer_id'=>$source['identity']['mer_id'],'order_id'=>$source['identity']['id'],
                'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique']]);
            if(!in_array($row['kind'],['release','return_in'],true)||$qty>$group['qty']-$credited[$key]) self::unavailable();
            $new=$expected['m:'.$id]??null;
            if($new!==null){
                $line=$new['line']; $action=$new['action'];
                self::stored($row,['id'=>$id,'parent_reserve_id'=>$line['reserve_id'],'order_item_id'=>$new['source_item_id'],
                    'refund_id'=>$action['refund_id'],'kind'=>$new['kind'],'qty'=>$new['quantity'],'idem_key'=>$new['idem_key'],
                    'actor_type'=>$action['actor_type'],'actor_id'=>$action['actor_id']]);
                if(isset($seen['m:'.$id])) self::unavailable();
                self::digest($row,$new['digest']); self::arithmetic($row,true); $seen['m:'.$id]=true;
            }else{
                // Known or unknown version prefixes cannot fall back to the original unversioned contract.
                if(str_starts_with($row['idem_key'],'release_v')||str_starts_with($row['idem_key'],'return_in_v')) self::unavailable();
                if($row['kind']==='release'){
                    if($released[$key]) self::unavailable();
                    self::stored($row,['idem_key'=>'release:'.$source['identity']['id'].':'.$parent,'qty'=>$group['qty'],
                        'order_item_id'=>null,'refund_id'=>null]);
                    if(!(($row['actor_type']==='customer'&&(string)$row['actor_id']===(string)$source['identity']['uid'])
                        ||($row['actor_type']==='system'&&$row['actor_id']===null))) self::unavailable();
                    self::digest($row,['contract_version'=>1,'uid'=>$source['identity']['uid'],'mer_id'=>$source['identity']['mer_id'],
                        'order_id'=>$source['identity']['id'],'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],
                        'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],'kind'=>'release','parent_reserve_id'=>$parent]);
                    self::arithmetic($row,true); $released[$key]=true;
                }
                // Original typed return_in keeps its original owner/parent/budget semantics; it is not a new physical action.
            }
            $credited[$key]+=$qty; $out[]=$row;
        }
        if(count($seen)!==count($expected)) self::unavailable();
        foreach($expected as $key=>$movement) if(!isset($seen[$key])) self::unavailable();
        return $out;
    }

    /** Integer-cent coordinates reserve actual saved budget without enumerating units or cents. */
    private static function allocateMoney(array $source,array $allocations,string $amount,array $refunds): array
    {
        $used=[];
        foreach($refunds as $refund) if(!in_array($refund['after']['business_state'],['WITHDRAWN','REJECTED'],true))
            foreach($refund['claims'] as $claim) $used[]=$claim;
        $remaining=bcmul($amount,'100',0); $claims=[]; $amounts=[];
        foreach($allocations as $allocation){
            $line=$source['lines'][$allocation['source_item_id']];
            $first=self::moneyPosition($line,$allocation['unit_start']); $last=self::moneyPosition($line,$allocation['unit_end']);
            $free=self::moneySubtract([['source_item_id'=>$allocation['source_item_id'],'first'=>$first,'last'=>$last]],$used);
            $taken='0';
            foreach($free as $part){
                if($remaining==='0') break;
                $length=bcsub($part['last'],$part['first'],0); $quantity=bccomp($remaining,$length,0)<0?$remaining:$length;
                if($quantity==='0') continue;
                $claim=['source_item_id'=>$part['source_item_id'],'first'=>$part['first'],'last'=>bcadd($part['first'],$quantity,0)];
                $claims[]=$claim; $used[]=$claim; $taken=bcadd($taken,$quantity,0); $remaining=bcsub($remaining,$quantity,0);
            }
            $amounts[]=bcdiv($taken,'100',2);
        }
        if($remaining!=='0') self::unavailable(); return ['claims'=>$claims,'amounts'=>$amounts];
    }

    private static function moneyPosition(array $line,int $position): string
    {
        $goods=MarketplaceOrderFulfillmentPlan::sliceMoney($line['goods'],$line['original_quantity'],0,$position);
        $postage=MarketplaceOrderFulfillmentPlan::sliceMoney($line['postage'],$line['original_quantity'],0,$position);
        return bcmul(bcadd($goods,$postage,2),'100',0);
    }

    private static function moneySubtract(array $parts,array $blocked): array
    {
        usort($blocked,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:bccomp($a['first'],$b['first'],0));
        $out=[];
        foreach($parts as $part){
            $cursor=$part['first'];
            foreach($blocked as $block){
                if($block['source_item_id']!==$part['source_item_id']||bccomp($block['last'],$cursor,0)<=0||bccomp($block['first'],$part['last'],0)>=0) continue;
                if(bccomp($block['first'],$cursor,0)>0) $out[]=['source_item_id'=>$part['source_item_id'],'first'=>$cursor,
                    'last'=>bccomp($block['first'],$part['last'],0)<0?$block['first']:$part['last']];
                if(bccomp($block['last'],$cursor,0)>0) $cursor=$block['last'];
                if(bccomp($cursor,$part['last'],0)>=0) break;
            }
            if(bccomp($cursor,$part['last'],0)<0) $out[]=['source_item_id'=>$part['source_item_id'],'first'=>$cursor,'last'=>$part['last']];
        }
        return $out;
    }

    private static function acceptedMoney(array $source,array $refund): string
    {
        $accepted=[];
        foreach($refund['accepted'] as $part){
            $line=$source['lines'][$part['source_item_id']]; $accepted[]=['source_item_id'=>$part['source_item_id'],
                'first'=>self::moneyPosition($line,$part['unit_start']),'last'=>self::moneyPosition($line,$part['unit_end'])];
        }
        $total='0';
        foreach($refund['claims'] as $claim){
            foreach($accepted as $part){
                if($part['source_item_id']!==$claim['source_item_id']) continue;
                $first=bccomp($part['first'],$claim['first'],0)>0?$part['first']:$claim['first'];
                $last=bccomp($part['last'],$claim['last'],0)<0?$part['last']:$claim['last'];
                if(bccomp($last,$first,0)>0) $total=bcadd($total,bcsub($last,$first,0),0);
            }
        }
        return bcdiv($total,'100',2);
    }

    private static function financialBudget(array $source,array $refunds): void
    {
        $confirmed='0.00'; $held='0.00'; $claims=[];
        foreach($refunds as $refund){
            $confirmed=bcadd($confirmed,$refund['after']['confirmed_amount'],2); $held=bcadd($held,$refund['after']['held_amount'],2);
            if(in_array($refund['after']['business_state'],['WITHDRAWN','REJECTED'],true)) continue;
            foreach($refund['claims'] as $claim){
                if(self::moneySubtract([$claim],$claims)!==[$claim]) self::unavailable(); $claims[]=$claim;
            }
        }
        if(bccomp(bcadd($confirmed,$held,2),$source['root']['pay_price'],2)>0) self::unavailable();
    }

    private static function summary(array $source,array $ships,array $received,array $cancel,array $returned): array
    {
        self::disjoint($ships,$cancel); self::contained($received,$ships); self::contained($returned,$ships);
        $original=self::integer($source['root']['total_num'],1,self::UINT_MAX);
        $s=self::quantity($ships); $r=self::quantity($received); $c=self::quantity($cancel); $t=self::quantity($returned);
        $intersection=self::intersectionQuantity($received,$returned); $toShip=$original-$s-$c; $toReceive=$s-$r-$t+$intersection;
        if($toShip<0||$toReceive<0) self::unavailable();
        $children=[]; foreach($ships as $ship) $children[$ship['child_order_id']]=true;
        return ['shipping_state'=>$s===0?'unshipped':($toShip>0?'partial':'shipped'),
            'receipt_state'=>$s===0?'not_shipped':($r===$s?'received':($r===0?'pending':'partial')),
            'closed'=>$toShip===0&&$toReceive===0,'original'=>$original,'shipped'=>$s,'received'=>$r,
            'cancelled_unshipped'=>$c,'returned_accepted'=>$t,'to_ship'=>$toShip,'to_receive'=>$toReceive,'child_count'=>count($children)];
    }

    private static function pending(array $refunds): array
    {
        $cancel=[]; $return=[];
        foreach($refunds as $refund){
            if($refund['kind']==='cancel_unshipped'&&$refund['after']['business_state']==='REQUESTED') $cancel=array_merge($cancel,$refund['allocations']);
            if($refund['kind']==='return_goods'&&in_array($refund['after']['business_state'],['REQUESTED','APPROVED'],true))
                $return=array_merge($return,self::subtract($refund['allocations'],$refund['accepted']));
        }
        return ['cancel'=>$cancel,'return'=>$return];
    }

    private static function aftersale(array $source,array $refunds,array $received,array $returned,string $revision,?int $completed): array
    {
        $confirmed='0.00'; $held='0.00';
        foreach($refunds as $refund){$confirmed=bcadd($confirmed,$refund['after']['confirmed_amount'],2); $held=bcadd($held,$refund['after']['held_amount'],2);}
        $pending=self::pending($refunds);
        return ['schema'=>1,'revision'=>$revision,'confirmed_amount'=>$confirmed,'held_amount'=>$held,
            'available_amount'=>bcsub(bcsub($source['root']['pay_price'],$confirmed,2),$held,2),
            'pending_cancel_quantity'=>self::quantity($pending['cancel']),
            'pending_return_quantity'=>self::quantity($pending['return']),
            'returned_after_receipt'=>self::intersectionQuantity($received,$returned),'fulfillment_completed_at'=>$completed];
    }

    private static function operationResult(array $operation,array $source,array $summary,?array $aftersale): void
    {
        $saved=$operation['result_payload']; $public=$saved['public_result'];
        if(!self::same($saved['summary'],$summary)) self::unavailable();
        $expected=['schema'=>$operation['contract_version'],'operation_id'=>$operation['id'],'action'=>$operation['action'],
            'root_order_id'=>$operation['root_order_id'],'root_order_no'=>$source['identity']['order_no'],
            'child_order_id'=>$operation['child_order_id'],'child_order_no'=>$source['child_orders'][$operation['child_order_id']]['order_id'],
            'quantity'=>$saved['plan']['totals']['quantity'],'created_at'=>$operation['created_at'],'committed_at'=>$operation['committed_at'],
            'shipped_at'=>$operation['shipped_at'],'receipt_due_at'=>$operation['receipt_due_at'],'received_at'=>$operation['received_at'],
            'fulfillment_summary'=>$summary];
        if($operation['contract_version']===2){
            if($aftersale===null||!self::same($saved['aftersale'],$aftersale)) self::unavailable(); $expected['aftersale']=$aftersale;
        }elseif($aftersale!==null) self::unavailable();
        if(!self::same($public,$expected)) self::unavailable();
    }

    private static function actionPublic(array $action,array $source,array $refund,array $fulfillment): void
    {
        $public=$action['result_payload']['public_result'];
        self::keys($public,['schema','scope','action_id','action','refund','committed_at','acceptance_ids']);
        self::exact($public,['schema'=>2,'scope'=>'marketplace_aftersale_action_v1','action_id'=>$action['id'],
            'action'=>$action['action'],'committed_at'=>$action['committed_at']]);
        self::list($public['acceptance_ids']); $ids=[];
        if($action['action']==='accept_return') foreach($action['result_payload']['movements'] as $movement)
            if(!in_array($movement['movement_id'],$ids,true)) $ids[]=$movement['movement_id'];
        $actualIds=[];
        foreach($public['acceptance_ids'] as $acceptance){
            if(!is_string($acceptance)||isset($actualIds['m:'.$acceptance])||!in_array($acceptance,$ids,true)) self::unavailable();
            self::u64($acceptance); $actualIds['m:'.$acceptance]=true;
        }
        if(count($actualIds)!==count($ids)) self::unavailable();
        $view=self::treeObject($public['refund']);
        self::keys($view,['schema','scope','id','refund_no','root_order_id','root_order_no','merchant_name_snapshot',
            'operation_kind','mode','business_state','physical_state','finance_state','requested_amount','confirmed_amount','held_amount',
            'created_at','updated_at','reason','images','return_delivery','items','allowed_actions','return_selection']);
        self::text($view['refund_no'],50); $body=$refund['apply']['request_payload']['public_body'];
        self::exact($view,['schema'=>2,'scope'=>'marketplace_aftersale_v1','id'=>$action['refund_id'],'root_order_id'=>$source['identity']['id'],
            'root_order_no'=>$source['identity']['order_no'],'merchant_name_snapshot'=>$source['root']['merchant_name_snapshot'],
            'operation_kind'=>$refund['kind'],'mode'=>$refund['kind']==='return_goods'?'return_goods':'money_only',
            'created_at'=>$refund['apply']['created_at'],'updated_at'=>$action['created_at'],'reason'=>$body['reason'],'images'=>$body['images']]);
        foreach(['business_state','physical_state','finance_state','requested_amount','confirmed_amount','held_amount','return_delivery'] as $key)
            if(!self::same($view[$key],$refund['after'][$key])) self::unavailable();
        if(!self::same($view['return_selection'],self::returnSelection($source,$refund,$fulfillment))) self::unavailable();
        self::keys($view['allowed_actions'],['withdraw','submit_return','approve','reject','accept_return']);
        foreach($view['allowed_actions'] as $value) if(!is_bool($value)) self::unavailable();
        $customer=$action['actor_type']==='customer';
        $handler=in_array($action['actor_type'],['employee','admin'],true);
        $requested=$refund['after']['business_state']==='REQUESTED';
        $approvedReturn=$refund['after']['business_state']==='APPROVED'&&$refund['kind']==='return_goods';
        $necessary=['withdraw'=>$customer&&$requested,'submit_return'=>$customer&&$approvedReturn,
            'approve'=>$handler&&$requested,'reject'=>$handler&&$requested,
            'accept_return'=>$handler&&$approvedReturn&&self::quantity($refund['accepted'])<self::quantity($refund['allocations'])];
        foreach($view['allowed_actions'] as $name=>$value) if($value&&!$necessary[$name]) self::unavailable();
        // Saved action flags are not credentials. Live actor/permission/current eligibility is still the real service's duty.
        self::list($view['items']);
        $totals=[];
        foreach($refund['allocations'] as $part){
            $id=$part['source_item_id'];
            if(!isset($totals[$id])) $totals[$id]=['quantity'=>0,'accepted'=>0,'confirmed'=>'0.00','ranges'=>[]];
            $totals[$id]['quantity']+=$part['quantity']; $totals[$id]['confirmed']=bcadd($totals[$id]['confirmed'],$part['confirmed_amount'],2);
            $totals[$id]['ranges'][]=['source_item_id'=>$id,'unit_start'=>$part['unit_start'],'unit_end'=>$part['unit_end']];
        }
        $plan=MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($source['root'],array_values($source['items']),$source['reserves'],self::coordinates($refund['allocations']));
        $projection=['items'=>[]];
        foreach($totals as $sourceId=>$total){
            $item=$source['commercial']['public_items'][$sourceId]; $item['quantity']=$total['quantity']; $item['line_price']='0.00'; $item['postage_price']='0.00';
            foreach($plan['allocations'] as $part) if($part['source_item_id']===$sourceId){$item['line_price']=bcadd($item['line_price'],$part['goods'],2); $item['postage_price']=bcadd($item['postage_price'],$part['postage'],2);}
            $projection['items'][]=$item;
        }
        if(count($view['items'])!==count($projection['items'])) self::unavailable();
        foreach($view['items'] as $index=>$item){
            self::keys($item,['item','selected_quantity','accepted_quantity','confirmed_amount']); $expected=$projection['items'][$index];
            if(!self::same($item['item'],$expected)) self::unavailable(); $id=$expected['id'];
            $accepted=[]; foreach($refund['accepted'] as $part) if($part['source_item_id']===$id) $accepted[]=$part;
            self::exact($item,['selected_quantity'=>$totals[$id]['quantity'],'accepted_quantity'=>self::quantity($accepted),
                'confirmed_amount'=>$totals[$id]['confirmed']]);
        }
        if($action['action']==='express'&&$body!==[]&&$action['request_payload']['public_body']['refund_no']!==$view['refund_no']) self::unavailable();
    }

    private static function mirrors(array $rows,array $source,string $winner,array $history): void
    {
        $fields=['id','store_order_id','store_id','order_id','uid','refund_type','refund_num','refund_price','refunded_price',
            'refund_phone','refund_express','refund_express_name','refund_explain','refund_img','refund_reason','refuse_reason',
            'remark','refunded_time','cart_info','is_cancel','is_pink_cancel','is_del','add_time','is_system_del',
            'mer_id','marketplace_refund_mode','marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind',
            'marketplace_state','marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'];
        $seen=[]; $last=0;
        foreach($rows as $row){
            if(!is_array($row)) self::unavailable(); self::keys($row,$fields); self::nativeRow($row);
            foreach(['marketplace_payment_attempt_id','marketplace_apply_action_id','marketplace_last_action_id'] as $key) $row[$key]=self::u64($row[$key]);
            $id=self::integer($row['id'],1,self::INT_MAX);
            if($id<=$last||isset($seen[$id])||!isset($history['refunds'][$id])) self::unavailable(); $last=$id; $seen[$id]=true;
            $refund=$history['refunds'][$id]; $after=$refund['after']; $apply=$refund['apply']; $latest=$refund['last'];
            $body=$apply['request_payload']['public_body'];
            $firstPublic=self::treeObject($apply['result_payload']['public_result']['refund']); $number=self::text($firstPublic['refund_no'],50);
            $initial=$apply['result_payload']['allocations']; $quantity=self::integer(self::quantity($initial),1,self::INT_MAX);
            $created=self::integer($apply['created_at'],1,self::INT_MAX);
            $processed=$latest['action']==='apply'?0:self::integer($latest['created_at'],1,self::INT_MAX);
            $refuse='';
            foreach($refund['actions'] as $action){
                $public=self::treeObject($action['result_payload']['public_result']['refund']);
                if($public['refund_no']!==$number) self::unavailable();
                if($action['action']==='reject') $refuse=$action['request_payload']['public_body']['reason'];
            }
            self::characters($body['reason'],255); if($refuse!=='') self::characters($refuse,255);
            $delivery=$after['return_delivery'];
            if($delivery!==null){self::characters($delivery['carrier_name'],255); self::characters($delivery['tracking_no'],100);}
            // Rebuild only from the immutable first selection and complete validated action prefix, never from this mirror.
            $expected=['id'=>$id,'store_order_id'=>$source['identity']['id'],'store_id'=>0,'order_id'=>$number,
                'uid'=>$source['identity']['uid'],'refund_type'=>$after['refund_type'],'refund_num'=>$quantity,
                'refund_price'=>$body['amount'],'refunded_price'=>$after['confirmed_amount'],'refund_phone'=>'',
                'refund_express'=>$delivery===null?'':$delivery['tracking_no'],'refund_express_name'=>$delivery===null?'':$delivery['carrier_name'],
                'refund_explain'=>'','refund_img'=>self::canonical($body['images']),'refund_reason'=>$body['reason'],
                'refuse_reason'=>$refuse,'remark'=>'','refunded_time'=>$processed,'cart_info'=>self::canonical(self::mirrorItems($source,$initial)),
                'is_cancel'=>$after['is_cancel'],'is_pink_cancel'=>0,'is_del'=>0,'add_time'=>$created,'is_system_del'=>0,
                'mer_id'=>$source['identity']['mer_id'],'marketplace_refund_mode'=>$refund['kind']==='return_goods'?'return_goods':'money_only',
                'marketplace_root_order_id'=>$source['identity']['id'],'marketplace_payment_attempt_id'=>$winner,
                'marketplace_kind'=>$refund['kind'],'marketplace_state'=>$after['business_state'],
                'marketplace_physical_state'=>$after['physical_state'],'marketplace_finance_state'=>$after['finance_state'],
                'marketplace_apply_action_id'=>$apply['id'],'marketplace_last_action_id'=>$latest['id']];
            self::keys($expected,$fields); self::stored($row,$expected);
        }
        if(count($seen)!==count($history['refunds'])) self::unavailable();
        foreach($history['refunds'] as $id=>$refund) if(!isset($seen[$id])) self::unavailable();
    }

    /** The original public nine-key Item list for this refund's immutable first allocation, not a legacy cart JSON. */
    private static function mirrorItems(array $source,array $initial): array
    {
        $plan=MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($source['root'],array_values($source['items']),$source['reserves'],self::coordinates($initial));
        $items=[];
        foreach($plan['allocations'] as $part){
            $id=$part['source_item_id'];
            if(!isset($items[$id])){
                $items[$id]=$source['commercial']['public_items'][$id];
                $items[$id]['quantity']=0; $items[$id]['line_price']='0.00'; $items[$id]['postage_price']='0.00';
            }
            $items[$id]['quantity']+=$part['quantity'];
            $items[$id]['line_price']=bcadd($items[$id]['line_price'],$part['goods'],2);
            $items[$id]['postage_price']=bcadd($items[$id]['postage_price'],$part['postage'],2);
        }
        ksort($items,SORT_NUMERIC); return array_values($items);
    }

    private static function childId(array $source,string $number): int
    {
        $found=null;
        foreach($source['child_orders'] as $id=>$row) if($row['order_id']===$number){if($found!==null) self::unavailable(); $found=$id;}
        if($found===null) self::unavailable(); return $found;
    }

    /** Child ordinals always start at the original complete ship mapping, never at the current eligible remainder. */
    private static function childCoordinates(array $items,int $child,array $fulfillment): array
    {
        $ship=$fulfillment['ships'][$child]??null; if($ship===null) self::unavailable(); $groups=[];
        foreach($ship['validated_ranges']??[] as $range) $groups[$range['child_item_id']][]=$range;
        // ships contains the pre-decoration immutable operation; its ranges are also the full native range set.
        if($groups===[]) foreach($fulfillment['ranges'] as $range) if($range['operation_action']==='ship'&&$range['operation_id']===$ship['id'])
            $groups[$range['child_item_id']][]=$range;
        $out=[];
        foreach($items as $item){
            $parents=$groups[$item['item_id']]??null; if($parents===null) self::unavailable();
            usort($parents,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']); $total=0; $blocks=[];
            foreach($parents as $parent){$blocks[]=['first'=>$total,'last'=>$total+$parent['quantity'],'range'=>$parent]; $total+=$parent['quantity'];}
            foreach($item['segments'] as $segment){
                $first=$segment['first']-1; $last=$segment['last']; if($first<0||$last>$total||$last<=$first) self::unavailable(); $cursor=$first;
                foreach($blocks as $block){
                    if($block['last']<=$cursor||$block['first']>=$last) continue;
                    if($block['first']>$cursor) self::unavailable(); $stop=min($last,$block['last']); $parent=$block['range'];
                    $out[]=['source_item_id'=>$parent['source_item_id'],'reserve_id'=>$parent['reserve_id'],
                        'source_ship_range_id'=>$parent['id'],'child_order_id'=>$child,'child_item_id'=>$parent['child_item_id'],
                        'unit_start'=>$parent['unit_start']+$cursor-$block['first'],'unit_end'=>$parent['unit_start']+$stop-$block['first']];
                    $cursor=$stop; if($cursor===$last) break;
                }
                if($cursor!==$last) self::unavailable();
            }
        }
        usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:($a['unit_start']<=>$b['unit_start']));
        self::disjoint($out,[]); return $out;
    }

    private static function withShipParent(array $range): array
    {
        $range['source_ship_range_id']=$range['id']; return $range;
    }

    private static function coordinates(array $rows,bool $parents=false): array
    {
        $out=[];
        foreach($rows as $row){
            $part=['source_item_id'=>$row['source_item_id'],'unit_start'=>$row['unit_start'],'unit_end'=>$row['unit_end']];
            if($parents) $part['source_ship_range_id']=$row['source_ship_range_id'];
            $out[]=$part;
        }
        usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:($a['unit_start']<=>$b['unit_start']));
        return $out;
    }

    private static function samePhysical(array $actual,array $expected,bool $parents): void
    {
        if(count($actual)!==count($expected)) self::unavailable();
        foreach($expected as $index=>$row){
            $fields=['source_item_id','unit_start','unit_end'];
            if($parents) $fields=array_merge($fields,['source_ship_range_id','child_item_id']);
            foreach($fields as $key) if(!array_key_exists($key,$actual[$index])||$actual[$index][$key]!==$row[$key]) self::unavailable();
            if($parents&&isset($row['child_order_id'])&&$actual[$index]['child_order_id']!==$row['child_order_id']) self::unavailable();
        }
    }

    private static function subtract(array $parts,array $blocked): array
    {
        $blocked=self::coordinates($blocked); $out=[];
        foreach($parts as $part){
            $cursor=$part['unit_start'];
            foreach($blocked as $block){
                if($block['source_item_id']!==$part['source_item_id']||$block['unit_end']<=$cursor||$block['unit_start']>=$part['unit_end']) continue;
                if($block['unit_start']>$cursor) $out[]=array_replace($part,['unit_start'=>$cursor,'unit_end'=>min($block['unit_start'],$part['unit_end'])]);
                $cursor=max($cursor,$block['unit_end']); if($cursor>=$part['unit_end']) break;
            }
            if($cursor<$part['unit_end']) $out[]=array_replace($part,['unit_start'=>$cursor,'unit_end'=>$part['unit_end']]);
        }
        usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:($a['unit_start']<=>$b['unit_start']));
        return $out;
    }

    private static function contained(array $parts,array $whole): void
    {
        if(self::subtract($parts,$whole)!==[]) self::unavailable();
    }

    private static function disjoint(array $parts,array $other): void
    {
        $seen=self::coordinates($other);
        foreach(self::coordinates($parts) as $part){
            foreach($seen as $old) if($old['source_item_id']===$part['source_item_id']&&$old['unit_start']<$part['unit_end']&&$part['unit_start']<$old['unit_end']) self::unavailable();
            $seen[]=$part;
        }
    }

    private static function intersectionQuantity(array $a,array $b): int
    {
        $sum=0;
        foreach($a as $left) foreach($b as $right) if($left['source_item_id']===$right['source_item_id'])
            $sum+=max(0,min($left['unit_end'],$right['unit_end'])-max($left['unit_start'],$right['unit_start']));
        return self::integer($sum,0,self::UINT_MAX);
    }
    private static function quantity(array $rows): int
    {
        $sum=0; foreach($rows as $row){$sum+=$row['unit_end']-$row['unit_start']; self::integer($sum,0,self::UINT_MAX);} return $sum;
    }

    private static function selectReceive(array $eligible,array $selection): array
    {
        if($selection===['mode'=>'all_eligible']){if($eligible===[]) self::unavailable(); return $eligible;}
        $out=[];
        foreach($selection['ranges'] as $requested){
            $cursor=$requested['unit_start'];
            foreach($eligible as $part){
                if($part['source_item_id']!==$requested['source_item_id']||$part['unit_end']<=$cursor||$part['unit_start']>=$requested['unit_end']) continue;
                if($part['unit_start']>$cursor) self::unavailable(); $stop=min($part['unit_end'],$requested['unit_end']);
                $out[]=array_replace($part,['unit_start'=>$cursor,'unit_end'=>$stop]); $cursor=$stop; if($cursor===$requested['unit_end']) break;
            }
            if($cursor!==$requested['unit_end']) self::unavailable();
        }
        if($out===[]) self::unavailable(); return $out;
    }

    private static function movement(array $row): array
    {
        self::keys($row,self::MOVEMENT_FIELDS); self::nativeRow($row);
        $row['id']=self::u64($row['id']); $row['parent_reserve_id']=$row['parent_reserve_id']===null?null:self::u64($row['parent_reserve_id']);
        foreach(['mer_id','order_id','product_id','sku_id','qty','sku_type','add_time'] as $key)
            self::integer($row[$key],$key==='sku_type'?0:1,match($key){'order_id','sku_id'=>self::INT_MAX,'product_id'=>self::ROOT_MAX,'sku_type'=>0,default=>self::UINT_MAX});
        foreach(['order_item_id','refund_id','actor_id'] as $key) if($row[$key]!==null) self::integer($row[$key],1,$key==='actor_id'?self::UINT_MAX:self::INT_MAX);
        foreach(['sku_stock_before','sku_stock_after','sku_sales_before','sku_sales_after'] as $key) self::integer($row[$key],0,self::UINT_MAX);
        foreach(['root_stock_before','root_stock_after','root_sales_before','root_sales_after'] as $key) self::integer($row[$key],0,self::ROOT_STOCK_MAX);
        if(!is_string($row['sku_unique'])||preg_match('/\A[A-Za-z0-9]{8}\z/D',$row['sku_unique'])!==1) self::unavailable();
        if(!in_array($row['kind'],['reserve','release','return_in'],true)) self::unavailable();
        self::characters($row['actor_type'],32); self::characters($row['idem_key'],128); self::binary32($row['request_digest']); return $row;
    }
    private static function arithmetic(array $row,bool $credit): void
    {
        $qty=self::integer($row['qty'],1,self::UINT_MAX);
        foreach(['sku'=>self::UINT_MAX,'root'=>self::ROOT_STOCK_MAX] as $part=>$max){
            $stock=self::integer($row[$part.'_stock_before'],0,$max); $sales=self::integer($row[$part.'_sales_before'],0,$max);
            if($credit){if($qty>$max-$stock||$qty>$sales) self::unavailable(); $stock+=$qty; $sales-=$qty;}
            else{if($qty>$stock||$qty>$max-$sales) self::unavailable(); $stock-=$qty; $sales+=$qty;}
            self::stored($row,[$part.'_stock_after'=>$stock,$part.'_sales_after'=>$sales]);
        }
    }

    private static function digest(array $row,array $object): void
    {
        self::binary32($row['request_digest']);
        if(!hash_equals($row['request_digest'],hash('sha256',self::canonical($object),true))) self::unavailable();
    }
    private static function binary32(mixed $value): void {if(!is_string($value)||strlen($value)!==32) self::unavailable();}
    private static function asciiKey(mixed $value): void
    {
        if(!is_string($value)||preg_match('/\A[\x20-\x7e]{1,128}\z/D',$value)!==1||$value[0]===' '||substr($value,-1)===' ') self::unavailable();
    }
    private static function nativeRow(array $row): void
    {
        foreach($row as $key=>$value) if(!is_string($key)||($value!==null&&!is_int($value)&&!is_string($value))) self::unavailable();
    }
    private static function keys(array $row,array $expected): void
    {
        $keys=array_keys($row); sort($keys,SORT_STRING); sort($expected,SORT_STRING); if($keys!==$expected) self::unavailable();
    }
    private static function stored(array $row,array $expected): void
    {
        foreach($expected as $key=>$value){
            if(!array_key_exists($key,$row)) self::unavailable();
            if(is_int($value)){
                $actual=$value<0?self::signedStatus($row[$key]):self::integer($row[$key],0,self::UINT_MAX);
                if($actual!==$value) self::unavailable();
            }
            elseif($row[$key]!==$value) self::unavailable();
        }
    }
    private static function exact(array $row,array $expected): void
    {
        foreach($expected as $key=>$value) if(!array_key_exists($key,$row)||$row[$key]!==$value) self::unavailable();
    }
    private static function integer(mixed $value,int $min,int $max): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&bccomp($value,(string)$max,0)<=0) $value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max) self::unavailable(); return $value;
    }
    private static function signedStatus(mixed $value): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=4) $value=(int)$value;
        if(!is_int($value)||$value< -128||$value>127) self::unavailable(); return $value;
    }
    private static function u64(mixed $value): string
    {
        if(is_int($value)&&$value>0) $value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1||bccomp($value,'18446744073709551615',0)>0) self::unavailable(); return $value;
    }
    private static function compareId(string $a,string $b): int {return bccomp($a,$b,0);}
    private static function money(mixed $value): string
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1) self::unavailable(); return $value;
    }
    private static function text(mixed $value,int $max): string
    {
        if(!is_string($value)||$value===''||strlen($value)>$max||str_contains($value,"\0")||preg_match('//u',$value)!==1) self::unavailable(); return $value;
    }
    private static function characters(mixed $value,int $max): string
    {
        if(!is_string($value)||$value===''||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max) self::unavailable(); return $value;
    }
    private static function list(mixed $value): void {if(!is_array($value)||!array_is_list($value)) self::unavailable();}
    private static function object(mixed $value): array
    {
        if($value instanceof stdClass) $value=get_object_vars($value);
        if(!is_array($value)||array_is_list($value)) self::unavailable();
        foreach(array_keys($value) as $key) if(!is_string($key)) self::unavailable(); return $value;
    }
    private static function tree(mixed $value): mixed
    {
        if($value instanceof stdClass) $value=self::object($value);
        if(is_array($value)){foreach($value as $key=>$part) $value[$key]=self::tree($part); return $value;}
        if($value===null||is_string($value)||is_int($value)||is_bool($value)) return $value; self::unavailable();
    }
    private static function treeObject(mixed $value): array {return self::tree(self::object($value));}
    private static function treeList(mixed $value): array {self::list($value); return self::tree($value);}
    private static function jsonValue(mixed $raw,int $max): mixed
    {
        if(is_array($raw)){if(strlen(self::canonical($raw))>$max) self::unavailable(); return $raw;}
        if(!is_string($raw)||$raw===''||strlen($raw)>$max||preg_match('//u',$raw)!==1) self::unavailable();
        try{return json_decode($raw,false,512,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
    }
    private static function jsonObject(mixed $raw,int $max): array {return self::object(self::jsonValue($raw,$max));}
    private static function jsonList(mixed $raw,int $max): array {return self::treeList(self::jsonValue($raw,$max));}
    private static function canonical(array $value): string {return MarketplaceOrderInput::canonical(self::tree($value));}
    private static function same(mixed $a,mixed $b): bool
    {
        return MarketplaceOrderInput::canonical([self::tree($a)])===MarketplaceOrderInput::canonical([self::tree($b)]);
    }
    private static function unavailable(): never {throw new AuthUnavailableException();}

    private static function activeClaims(array $refunds): array
    {
        $out=[]; foreach($refunds as $refund) if(!in_array($refund['after']['business_state'],['WITHDRAWN','REJECTED'],true))
            $out=array_merge($out,$refund['claims']); return $out;
    }

    private static function moneySelection(array $source,array $requests,array $refunds): array
    {
        // sliceMoney has F(k)=q*k+min(k,r) for each saved goods/postage budget.
        // Thus B(k) is nonincreasing: zero-budget units can only be a tail, never an internal hole.
        // Used positive budgets split free cent windows; lower bounds remove the zero tail without enumerating Q.
        $used=self::activeClaims($refunds); $ranges=[];
        foreach($requests as $request){
            $id=$request['source_item_id']; $line=$source['lines'][$id]??null; if($line===null) self::unavailable();
            $q=$line['original_quantity']; $free=self::moneySubtract([['source_item_id'=>$id,'first'=>'0','last'=>self::moneyPosition($line,$q)]],$used);
            $available=[];
            foreach($free as $part){
                if(bccomp($part['first'],$part['last'],0)>=0) continue;
                $first=self::prefixBoundary($line,$part['first'],true)-1;
                $last=self::prefixBoundary($line,$part['last'],false);
                if($first<0||$last<=$first) self::unavailable();
                $index=count($available)-1;
                if($index>=0&&$first<=$available[$index]['unit_end']) $available[$index]['unit_end']=max($last,$available[$index]['unit_end']);
                else $available[]=['source_item_id'=>$id,'unit_start'=>$first,'unit_end'=>$last];
            }
            $remaining=$request['quantity'];
            foreach($available as $part){
                if($remaining===0) break; $length=min($remaining,$part['unit_end']-$part['unit_start']);
                $ranges[]=array_replace($part,['unit_end'=>$part['unit_start']+$length]); $remaining-=$length;
            }
            if($remaining!==0) self::unavailable();
        }
        return MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($source['root'],array_values($source['items']),$source['reserves'],$ranges);
    }

    /** First position whose saved prefix is >target or >=target; bounded binary search, never a unit loop. */
    private static function prefixBoundary(array $line,string $target,bool $strict): int
    {
        $low=0; $high=$line['original_quantity'];
        while($low<$high){
            $middle=$low+intdiv($high-$low,2); $comparison=bccomp(self::moneyPosition($line,$middle),$target,0);
            if($comparison>0||(!$strict&&$comparison===0)) $high=$middle; else $low=$middle+1;
        }
        $comparison=bccomp(self::moneyPosition($line,$low),$target,0);
        if($comparison<0||($strict&&$comparison===0)) self::unavailable(); return $low;
    }

    private static function moneyWindows(array $source,array $ranges): array
    {
        $out=[];
        foreach($ranges as $part){$line=$source['lines'][$part['source_item_id']]; $out[]=['source_item_id'=>$part['source_item_id'],
            'first'=>self::moneyPosition($line,$part['unit_start']),'last'=>self::moneyPosition($line,$part['unit_end'])];} return $out;
    }
    private static function moneyIntersection(array $claims,array $windows): array
    {
        $out=[];
        foreach($claims as $claim) foreach($windows as $window){
            if($claim['source_item_id']!==$window['source_item_id']) continue;
            $first=bccomp($claim['first'],$window['first'],0)>0?$claim['first']:$window['first'];
            $last=bccomp($claim['last'],$window['last'],0)<0?$claim['last']:$window['last'];
            if(bccomp($last,$first,0)>0) $out[]=['source_item_id'=>$claim['source_item_id'],'first'=>$first,'last'=>$last];
        }
        usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:bccomp($a['first'],$b['first'],0)); return $out;
    }
    private static function claimAmount(array $source,array $claims,array $ranges): string
    {
        $sum='0'; foreach(self::moneyIntersection($claims,self::moneyWindows($source,$ranges)) as $part) $sum=bcadd($sum,bcsub($part['last'],$part['first'],0),0);
        return bcdiv($sum,'100',2);
    }
    private static function confirmMoney(array $source,array $refund,string $amount): array
    {
        $old=$refund['confirmed_claims']; $remaining=bcmul(bcsub($amount,$refund['after']['confirmed_amount'],2),'100',0);
        $eligible=$refund['claims'];
        if($refund['kind']==='return_goods') $eligible=self::moneyIntersection($eligible,self::moneyWindows($source,$refund['accepted']));
        $free=self::moneySubtract($eligible,$old);
        foreach($free as $part){
            if($remaining==='0') break; $length=bcsub($part['last'],$part['first'],0);
            $take=bccomp($remaining,$length,0)<0?$remaining:$length; if($take==='0') continue;
            $old[]=['source_item_id'=>$part['source_item_id'],'first'=>$part['first'],'last'=>bcadd($part['first'],$take,0)];
            $remaining=bcsub($remaining,$take,0);
        }
        if($remaining!=='0') self::unavailable(); return $old;
    }

    private static function logs(array $rows,array $source,array $expected): void
    {
        $actual=[];
        foreach($rows as $row){
            if(!is_array($row)) self::unavailable(); self::keys($row,['oid','change_type','change_message','change_time']); self::nativeRow($row);
            $oid=self::integer($row['oid'],1,self::INT_MAX); $time=self::integer($row['change_time'],0,self::UINT_MAX);
            if($oid!==$source['identity']['id']&&!isset($source['child_orders'][$oid])) self::unavailable();
            self::characters($row['change_type'],32);
            if(!is_string($row['change_message'])||preg_match('//u',$row['change_message'])!==1||str_contains($row['change_message'],"\0")
                ||mb_strlen($row['change_message'],'UTF-8')>256) self::unavailable();
            if(in_array($row['change_type'],['marketplace_fulfillment_ship','marketplace_fulfillment_receive'],true))
                $actual[]=self::canonical(['oid'=>$oid,'change_type'=>$row['change_type'],'change_message'=>$row['change_message'],'change_time'=>$time]);
        }
        $wanted=[]; foreach($expected as $row) $wanted[]=self::canonical($row);
        sort($actual,SORT_STRING); sort($wanted,SORT_STRING); if($actual!==$wanted) self::unavailable();
    }

    private static function quantityPlan(array $source,array $occupied,array $requests): array
    {
        self::disjoint($occupied,[]); $ranges=[];
        foreach($requests as $request){
            $id=$request['source_item_id']; $line=$source['lines'][$id]??null; if($line===null) self::unavailable();
            $remaining=$request['quantity'];
            $free=self::subtract([['source_item_id'=>$id,'unit_start'=>0,'unit_end'=>$line['original_quantity']]],$occupied);
            foreach($free as $part){
                if($remaining===0) break; $quantity=min($remaining,$part['unit_end']-$part['unit_start']);
                $ranges[]=array_replace($part,['unit_end'=>$part['unit_start']+$quantity]); $remaining-=$quantity;
            }
            if($remaining!==0) self::unavailable();
        }
        return MarketplaceOrderFulfillmentPlan::refundExactRangesPlan($source['root'],array_values($source['items']),$source['reserves'],$ranges);
    }

    private static function splitSelected(array $parts,array $selected): array
    {
        $out=[];
        foreach($parts as $part){
            $cursor=$part['unit_start'];
            foreach($selected as $selection){
                if($selection['source_item_id']!==$part['source_item_id']||$selection['unit_end']<=$cursor||$selection['unit_start']>=$part['unit_end']) continue;
                if($selection['unit_start']>$cursor||$selection['source_ship_range_id']!==$part['source_ship_range_id']
                    ||$selection['child_item_id']!==$part['child_item_id']||$selection['child_order_id']!==$part['child_order_id']) self::unavailable();
                $stop=min($selection['unit_end'],$part['unit_end']); $out[]=array_replace($part,['unit_start'=>$cursor,'unit_end'=>$stop]);
                $cursor=$stop; if($cursor===$part['unit_end']) break;
            }
            if($cursor!==$part['unit_end']) self::unavailable();
        }
        return $out;
    }

    private static function returnSelection(array $source,array $refund,array $fulfillment): ?array
    {
        if($refund['kind']!=='return_goods') return null;
        $selection=$refund['apply']['request_payload']['public_body']['selection'];
        $child=self::childId($source,$selection['child_order_no']); $ship=$fulfillment['ships'][$child]; $groups=[];
        foreach($fulfillment['ranges'] as $range) if($range['operation_action']==='ship'&&$range['operation_id']===$ship['id'])
            $groups[$range['child_item_id']][]=$range;
        $items=[];
        foreach($selection['items'] as $item){
            $parents=$groups[$item['item_id']]??null; if($parents===null) self::unavailable();
            usort($parents,static fn(array $a,array $b):int=>$a['unit_start']<=>$b['unit_start']); $quantity=0; $accepted=[];
            foreach($parents as $parent){
                foreach($refund['accepted'] as $part) if($part['child_item_id']===$item['item_id']&&$part['source_ship_range_id']===$parent['id']){
                    if($part['unit_start']<$parent['unit_start']||$part['unit_end']>$parent['unit_end']) self::unavailable();
                    $accepted[]=['first'=>$quantity+$part['unit_start']-$parent['unit_start']+1,
                        'last'=>$quantity+$part['unit_end']-$parent['unit_start']];
                }
                $quantity+=$parent['quantity'];
            }
            usort($accepted,static fn(array $a,array $b):int=>($a['first']<=>$b['first'])?:($a['last']<=>$b['last']));
            $eligible=[];
            if($refund['after']['business_state']==='APPROVED') foreach($item['segments'] as $segment){
                $cursor=$segment['first'];
                foreach($accepted as $done){
                    if($done['last']<$cursor||$done['first']>$segment['last']) continue;
                    if($done['first']>$cursor) $eligible[]=['first'=>$cursor,'last'=>min($segment['last'],$done['first']-1)];
                    $cursor=max($cursor,$done['last']+1); if($cursor>$segment['last']) break;
                }
                if($cursor<=$segment['last']) $eligible[]=['first'=>$cursor,'last'=>$segment['last']];
            }
            $count=static function(array $segments):int{$sum=0;foreach($segments as $segment)$sum+=$segment['last']-$segment['first']+1;return $sum;};
            $items[]=['item_id'=>$item['item_id'],'quantity'=>$quantity,'selected_quantity'=>$count($item['segments']),
                'selected_segments'=>$item['segments'],'accepted_quantity'=>$count($accepted),'accepted_segments'=>$accepted,
                'eligible_quantity'=>$count($eligible),'eligible_segments'=>$eligible];
        }
        return ['schema'=>1,'coordinate'=>'child_item_ordinal','child_order_no'=>$selection['child_order_no'],'items'=>$items];
    }

    /** Empty-action legacy branch only; historical return_in remains typed/budgeted, not action-proven. */
    private static function legacyCredits(array $root, array $groups, array $rows): array
    {
        if (!array_is_list($rows)) self::unavailable();
        foreach (['id', 'uid', 'mer_id'] as $field) {
            if (!array_key_exists($field, $root) || !is_int($root[$field])) self::unavailable();
        }
        $orderId = self::integer($root['id'], 1, self::INT_MAX);
        $uid = self::integer($root['uid'], 1, self::INT_MAX);
        $merId = self::integer($root['mer_id'], 1, self::UINT_MAX);
        $parents = []; $credited = []; $released = [];
        foreach ($groups as $key => $group) {
            if (!is_string($key) || !is_array($group)) self::unavailable();
            $fields = array_keys($group); sort($fields, SORT_STRING);
            $expected = ['parent', 'product_id', 'sku_id', 'unique', 'qty', 'cart_refs']; sort($expected, SORT_STRING);
            if ($fields !== $expected || !is_array($group['parent'])) self::unavailable();
            $parent = self::movement($group['parent']); $parentId = $parent['id'];
            if ($key !== 'r:' . $parentId || isset($parents[$key])) self::unavailable();
            $productId = self::integer($group['product_id'], 1, 8388607);
            $skuId = self::integer($group['sku_id'], 1, self::INT_MAX);
            $quantity = self::integer($group['qty'], 1, self::UINT_MAX);
            if (!is_string($group['unique']) || $group['unique'] !== $parent['sku_unique']
                || !is_array($group['cart_refs']) || !array_is_list($group['cart_refs'])) self::unavailable();
            self::stored($parent, ['mer_id' => $merId, 'order_id' => $orderId, 'product_id' => $productId,
                'sku_id' => $skuId, 'sku_type' => 0, 'sku_unique' => $group['unique'], 'qty' => $quantity,
                'kind' => 'reserve', 'parent_reserve_id' => null, 'order_item_id' => null, 'refund_id' => null,
                'actor_type' => 'customer', 'actor_id' => $uid, 'idem_key' => 'reserve:' . $orderId . ':' . $skuId]);
            self::digest($parent, ['contract_version' => 1, 'uid' => $uid, 'mer_id' => $merId, 'order_id' => $orderId,
                'product_id' => $productId, 'sku_id' => $skuId, 'sku_type' => 0, 'sku_unique' => $group['unique'],
                'qty' => $quantity, 'kind' => 'reserve', 'cart_refs' => $group['cart_refs']]);
            self::arithmetic($parent, false);
            $parents[$key] = ['parent' => $parent, 'product_id' => $productId, 'sku_id' => $skuId,
                'unique' => $group['unique'], 'qty' => $quantity, 'cart_refs' => $group['cart_refs']];
            $credited[$key] = 0; $released[$key] = false;
        }
        $children = []; $previous = null;
        foreach ($rows as $raw) {
            if (!is_array($raw)) self::unavailable();
            $row = self::movement($raw); $id = $row['id']; $parentId = $row['parent_reserve_id'];
            if (($previous !== null && bccomp($id, $previous, 0) <= 0) || $parentId === null
                || !isset($parents['r:' . $parentId]) || !in_array($row['kind'], ['release', 'return_in'], true)) self::unavailable();
            if (str_starts_with($row['idem_key'], 'release_v') || str_starts_with($row['idem_key'], 'return_in_v')) self::unavailable();
            $key = 'r:' . $parentId; $group = $parents[$key];
            if ($group['parent']['id'] !== $parentId) self::unavailable();
            self::stored($row, ['mer_id' => $merId, 'order_id' => $orderId, 'product_id' => $group['product_id'],
                'sku_id' => $group['sku_id'], 'sku_type' => 0, 'sku_unique' => $group['unique'], 'parent_reserve_id' => $parentId]);
            $quantity = self::integer($row['qty'], 1, self::UINT_MAX);
            if ($quantity > $group['qty'] - $credited[$key]) self::unavailable();
            if ($row['kind'] === 'release') {
                if ($released[$key]) self::unavailable();
                self::stored($row, ['idem_key' => 'release:' . $orderId . ':' . $parentId,
                    'qty' => $group['qty'], 'order_item_id' => null, 'refund_id' => null]);
                if (!(($row['actor_type'] === 'customer' && (string)$row['actor_id'] === (string)$uid)
                    || ($row['actor_type'] === 'system' && $row['actor_id'] === null))) self::unavailable();
                self::digest($row, ['contract_version' => 1, 'uid' => $uid, 'mer_id' => $merId, 'order_id' => $orderId,
                    'product_id' => $group['product_id'], 'sku_id' => $group['sku_id'], 'sku_type' => 0,
                    'sku_unique' => $group['unique'], 'qty' => $group['qty'], 'kind' => 'release', 'parent_reserve_id' => $parentId]);
                self::arithmetic($row, true); $released[$key] = true;
            }
            // Original unversioned return_in has no adopted legacy action/digest/actor/arithmetic proof.
            // Preserve its complete typed row and parent budget; never treat it as a Task7 return event.
            $credited[$key] += $quantity; $previous = $id; $children[] = $row;
        }
        return $children;
    }

}
