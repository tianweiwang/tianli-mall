<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use crmeb\exceptions\AuthUnavailableException;
use Throwable;

/** Exit classification only. Saved identities are data, never authorization or financial settlement. */
final class MerchantExitFacts
{
    public const COLLECTIONS=['orders','customers','ledgers','registries','attempts','items','movements','statuses',
        'refunds','actions','operations','ranges','integral_orders','legacy_combinations','legacy_level_definitions','legacy_user_levels'];

    public static function assess(array $merchant,int $checkedAt,array $facts): array
    {
        $merId=self::id($merchant['mer_id']??null,4294967295);
        $closedAt=($merchant['closed_at']??null)===null?null:self::id($merchant['closed_at'],4294967295);
        if($merId<1||$merId>4294967295||$checkedAt<1||($closedAt!==null&&$closedAt<1))throw new AuthUnavailableException();
        $out=[];
        foreach(['fulfillment','payment','refund','settlement'] as$key)$out[$key]=['key'=>$key,'state'=>'CLEAR','count'=>0,'reasons'=>[]];
        try{
            if(array_keys($facts)!==self::COLLECTIONS)throw new AuthUnavailableException();
            foreach($facts as$rows)if(!is_array($rows)||!array_is_list($rows))throw new AuthUnavailableException();
            $roots=[];$all=[];
            foreach($facts['orders'] as$row){
                $id=self::id($row['id']??null);$owner=self::id($row['mer_id']??null,4294967295);
                if($owner!==$merId||isset($all[$id]))throw new AuthUnavailableException();$all[$id]=$row;
                if(self::id($row['pid']??null,2147483647,true)===0)$roots[$id]=$row;
            }
            if(count($all)!==count($roots))self::unknownAll($out,'ORDER_FAMILY_REQUIRES_REVIEW','存在拆单或履约历史，退出责任尚未完整证明');
            if($facts['integral_orders']!==[])self::unknownAll($out,'LEGACY_RIGHTS_UNRESOLVED','存在旧积分或权益订单，责任尚未核清');
            if($facts['legacy_combinations']!==[])self::unknownAll($out,'LEGACY_COMBINATION_UNRESOLVED','存在旧拼团活动及其潜在成团或退款责任，尚未完整核清');
            if($facts['legacy_level_definitions']!==[]||$facts['legacy_user_levels']!==[])
                self::mark($out,'settlement','UNKNOWN',null,'LEGACY_LEVEL_RIGHTS_UNRESOLVED','存在旧会员等级或用户权益，费用及剩余责任尚未完整核清');
            $byRoot=[];
            foreach(['ledgers'=>'order_id','registries'=>'order_id','attempts'=>'order_id','items'=>'oid','movements'=>'order_id',
                'refunds'=>'marketplace_root_order_id','actions'=>'root_order_id','operations'=>'root_order_id','ranges'=>'root_order_id'] as$collection=>$field){
                foreach($facts[$collection] as$row){
                    if(self::id($row['mer_id']??null,4294967295)!==$merId)throw new AuthUnavailableException();
                    $id=self::id($row[$field]??null);
                    if(!isset($roots[$id]))throw new AuthUnavailableException();$byRoot[$id][$collection][]=$row;
                }
            }
            foreach($facts['statuses'] as$row){$id=self::id($row['oid']??null);
                if(!isset($all[$id]))throw new AuthUnavailableException();$byRoot[$id]['statuses'][]=$row;}
            $customers=[];
            foreach($facts['customers'] as$row){$uid=self::id($row['uid']??null);if(isset($customers[$uid]))throw new AuthUnavailableException();$customers[$uid]=$row;}
            foreach($roots as$id=>$root){
                if(!in_array($root['marketplace_trade_version']??null,[1,'1'],true)){
                    self::unknownAll($out,'LEGACY_ORDER_UNRESOLVED','存在旧版或混合订单，退出责任尚未核清');continue;
                }
                $rows=$byRoot[$id]??[];$uid=self::id($root['uid']??null);
                if(($rows['refunds']??[])!==[]||($rows['actions']??[])!==[]){
                    self::mark($out,'refund','UNKNOWN',null,'REFUND_FINANCE_UNPROVEN','售后动作不等于实际退款完成，需核清原退款责任');
                }
                if(($rows['operations']??[])!==[]||($rows['ranges']??[])!==[]){
                    self::mark($out,'fulfillment','UNKNOWN',null,'FULFILLMENT_HISTORY_UNPROVEN','存在发货或收货历史，需完整核清履约及退货责任');
                }
                if(self::id($root['paid']??null,1,true)===1){
                    self::mark($out,'fulfillment','UNKNOWN',null,'PAID_ORDER_EXIT_UNPROVEN','已付订单的履约及后续责任尚未证明全部结束');
                    self::mark($out,'payment','UNKNOWN',null,'PAID_FUNDS_UNPROVEN','已付或测试支付记录不代表实际资金已清偿');
                    self::mark($out,'settlement','UNKNOWN',null,'SETTLEMENT_CONTRACT_UNAVAILABLE','真实账户、费用和结算规则及完成记录尚未齐备');
                    continue;
                }
                $ledgers=$rows['ledgers']??[];$registries=$rows['registries']??[];
                if(count($ledgers)!==1||count($registries)!==1||!isset($customers[$uid]))throw new AuthUnavailableException();
                $ledger=$ledgers[0];$fulfillmentCounted=false;
                if(self::id($ledger['post_commit_done']??null,1,true)!==1){
                    self::mark($out,'fulfillment','OPEN',1,'ORDER_POST_COMMIT_PENDING','订单后置记录尚未完成');
                    $fulfillmentCounted=true;
                }
                $paymentColumns=MarketplacePaymentDao::merchantExitColumns();$paymentFacts=[];
                foreach($paymentColumns as$key=>$fields){
                    $source=match($key){'customer'=>$customers[$uid],'ledger'=>$ledger,'root'=>$root,
                        'merchant'=>$merchant,'registry'=>$registries[0],'attempts'=>$rows['attempts']??[]};
                    $paymentFacts[$key]=$key==='attempts'?array_map(fn(array $row):array=>self::project($row,$fields),$source):self::project($source,$fields);
                }
                $payment=MarketplacePaymentDao::merchantExitStateFromFacts($paymentFacts);
                if($payment!=='CLEAR')self::mark($out,'payment',$payment,$payment==='OPEN'?1:null,'PAYMENT_CLOSE_UNPROVEN','支付尝试尚未取得完整、有序的查询关闭查询证据');
                if($payment==='UNKNOWN')self::mark($out,'settlement','UNKNOWN',null,'FUNDS_EVIDENCE_UNRESOLVED','资金事实存在未解决或不一致状态');
                $columns=MarketplaceOrderDao::merchantExitColumns();
                $cancelled=MarketplaceOrderDao::merchantExitCancelledFromFacts(['customer'=>$customers[$uid],
                    'ledger'=>self::project($ledger,$columns['ledger']),'root'=>$root,
                    'items'=>array_map(fn(array $row):array=>self::project($row,$columns['items']),$rows['items']??[]),
                    'movements'=>array_map(fn(array $row):array=>self::project($row,$columns['movements']),$rows['movements']??[]),
                    'statuses'=>array_map(fn(array $row):array=>self::project($row,$columns['statuses']),$rows['statuses']??[])]);
                if(!$cancelled)self::mark($out,'fulfillment','OPEN',$fulfillmentCounted?0:1,'ORDER_CANCELLATION_PENDING','未付订单的取消及后置事实尚未完整核清');
            }
        }catch(Throwable $error){self::unknownAll($out,'EXIT_SOURCE_FACTS_UNAVAILABLE','退出责任来源不完整或关系不一致，需核实后再关闭');}
        return ['schema'=>1,'scope'=>'MERCHANT_EXIT_PRECHECK','mer_id'=>$merId,'closed_at'=>$closedAt,'checked_at'=>$checkedAt,
            'can_close'=>$closedAt===null&&array_reduce($out,fn(bool $clear,array $row):bool=>$clear&&$row['state']==='CLEAR',true),
            'responsibilities'=>array_values($out)];
    }

    private static function project(array $row,array $fields): array
    {
        $out=[];foreach($fields as$field){if(!array_key_exists($field,$row))throw new AuthUnavailableException();$out[$field]=$row[$field];}return $out;
    }

    private static function id(mixed $value,int $maximum=2147483647,bool $zero=false): int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<($zero?0:1)||$value>$maximum)throw new AuthUnavailableException();return $value;
    }

    private static function unknownAll(array &$out,string $code,string $message): void
    {foreach(array_keys($out) as$key)self::mark($out,$key,'UNKNOWN',null,$code,$message);}

    private static function mark(array &$out,string $key,string $state,?int $count,string $code,string $message): void
    {
        $row=&$out[$key];
        if($row['state']!=='UNKNOWN')$row['state']=$state;
        $row['count']=$row['count']===null||$count===null?null:$row['count']+$count;
        foreach($row['reasons'] as$reason)if($reason['code']===$code)return;
        $row['reasons'][]=['code'=>$code,'message'=>$message];
    }
}
