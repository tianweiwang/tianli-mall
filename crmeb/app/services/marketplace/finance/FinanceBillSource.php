<?php
declare(strict_types=1);
namespace app\services\marketplace\finance;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\marketplace\payment\MarketplaceRefundDao;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\marketplace\order\MarketplaceOrderRefundFacts;
use app\services\marketplace\payment\MarketplaceRefundEvidence as RefundFacts;
use app\services\marketplace\payment\MarketplaceRefundServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;

/** Full account-scoped saved facts. The authorized caller owns one native RR/READ ONLY transaction. */
final class FinanceBillSource
{
    public function __construct(private MarketplacePaymentDao $payments,private MarketplaceOrderDao $orders,
        private MarketplaceOrderFulfillmentDao $families,private MarketplaceOrderRefundDao $refunds,
        private MarketplaceOrderFulfillmentServices $fulfillment,private MarketplaceRefundDao $intents,
        private MarketplaceRefundServices $refundServices) {}

    public function accounts(PDO $pdo,int $merchant):array
    {
        [, $prefix]=$this->native($pdo);
        $anchors=self::rows($pdo,'SELECT MIN(id) AS id FROM `'.$prefix.'marketplace_payment_attempt` WHERE mer_id=?'
            .' GROUP BY CAST(account_snapshot AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin ORDER BY MIN(id) LIMIT 101',[$merchant]);
        if(count($anchors)>100)throw new HttpException(422,'账户版本超过单次读取范围，请联系管理员分段整理');
        $saved=$this->anchors($pdo,array_column($anchors,'id'));$out=[];
        foreach($saved as $id=>$attempt){
            $account=RefundFacts::account($attempt['account_snapshot']);
            if($account['mer_id']!==$merchant)throw new AuthUnavailableException();
            $out[]=['anchor_attempt_id'=>(string)$id,'mer_id'=>$account['mer_id'],'channel'=>$account['channel'],
                'account_ref'=>$account['account_ref'],'account_version'=>$account['version'],'currency'=>$account['currency']];
        }
        return $out;
    }

    public function account(PDO $pdo,string $anchor):array
    {
        $attempts=$this->anchors($pdo,[$anchor]);
        if(!isset($attempts[$anchor]))throw new HttpException(422,'原付款账户不存在，请重新选择');
        return RefundFacts::account($attempts[$anchor]['account_snapshot']);
    }

    public function movements(PDO $pdo,array $account,int $start,int $end):array
    {
        [$connection,$prefix]=$this->native($pdo);$account=RefundFacts::account($account);
        if(!$this->intents->structure($pdo)||$this->families->readAftersaleStructureReadiness($connection,$pdo)!==['schema'=>2,'state'=>'READY'])
            throw new AuthUnavailableException();
        // Include every financial action in the period, including history without a modern refund intent.
        $headers=self::rows($pdo,'SELECT DISTINCT o.* FROM `'.$prefix.'store_order` o'
            .' JOIN `'.$prefix.'marketplace_payment_attempt` p ON p.order_id=o.id'
            .' WHERE o.pid=0 AND o.marketplace_trade_version=1 AND p.mer_id=? AND p.account_domain_digest=?'
            ." AND JSON_UNQUOTE(JSON_EXTRACT(p.account_snapshot,'$.version'))=?"
            .' AND ((o.pay_time>=? AND o.pay_time<?) OR EXISTS (SELECT 1 FROM `'.$prefix.'marketplace_aftersale_action` a'
            ." WHERE a.root_order_id=o.id AND a.action='finance_observe' AND a.committed_at>=? AND a.committed_at<?))"
            .' ORDER BY o.id LIMIT 1001',[$account['mer_id'],RefundFacts::domain($account),(string)$account['version'],$start,$end,$start,$end]);
        if(count($headers)>1000)throw new HttpException(422,'期间超过1000根订单，请缩短账单期间');
        $rows=[];$issues=[];
        foreach(array_chunk($headers,100) as $chunk){
            $observations=$this->payments->readObservationsForRoots($pdo,$chunk);
            $facts=$this->orders->readPaymentApplicationCaptureFactsBatch($connection,$pdo,$chunk);
            $families=$this->families->readFulfillmentFamiliesForRoots($connection,$pdo,$chunk);
            $refunds=$this->refunds->readRefundsForRoots($connection,$pdo,$chunk);
            $actions=$this->refunds->readActionsForRoots($connection,$pdo,$chunk);
            $logs=$this->refunds->readStatusLogsForRoots($connection,$pdo,$chunk);
            foreach($chunk as $root){
                $id=RefundFacts::id($root['id']);$observed=$observations[$id];
                if($observed['root_payment_consistent']!==true)throw new AuthUnavailableException();
                $locked=$observed['locked'];$winner=null;
                foreach($locked['attempts'] as $attempt)if($attempt['id']===$locked['registry']['winner_attempt_id'])$winner=$attempt;
                if($winner===null||$winner['evidence']['application']===null)throw new AuthUnavailableException();
                if(RefundFacts::canonical($winner['account_snapshot'])!==RefundFacts::canonical($account))continue;
                $capture=$this->orders->capturePaymentApplicationFactsFromFacts(RefundFacts::id($root['uid']),$id,$facts[$id]);
                if($capture['root']!==$root)throw new AuthUnavailableException();
                $raw=$families[$id];$family=['children'=>$raw['children'],'items'=>$raw['child_items'],'ranges'=>$raw['ranges'],'refunds'=>$refunds[$id]];
                $cut=$actions[$id]===[]?null:MarketplaceOrderFulfillmentDao::u64($actions[$id][count($actions[$id])-1]['id']);
                $context=['schema'=>2,'cut_id'=>$cut,'actions'=>$actions[$id],'refunds'=>$refunds[$id],
                    'operations'=>$raw['operations'],'ranges'=>$raw['ranges'],'logs'=>$logs[$id]];
                $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$locked);
                $this->fulfillment->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);
                if($locked['registry']['fulfillment_hold']!==0)$issues[]=['root_order_id'=>$id,'reason'=>'PAYMENT_ON_HOLD'];
                $at=$winner['evidence']['application']['at'];
                if($at>=$start&&$at<$end)$rows[]=self::movement($account,'payment','payment:'.$winner['id'],
                    $winner['amount'],$winner['applied_trade_no'],$at);
                foreach($inspection['history']['refunds'] as $refundId=>$history){
                    $refundId=RefundFacts::id($refundId);$state=$history['after'];$intent=$this->intents->find($pdo,$refundId);
                    $providerByAction=[];
                    if($intent!==null){
                        $view=['id'=>$refundId,'business_state'=>$state['business_state'],'physical_state'=>$state['physical_state'],
                            'finance_state'=>$state['finance_state'],'requested_amount'=>$state['requested_amount'],
                            'confirmed_amount'=>$state['confirmed_amount'],'held_amount'=>$state['held_amount'],
                            'operation_kind'=>$history['kind'],'is_test_payment'=>$root['pay_type']==='marketplace_test'];
                        $this->refundServices->assertSavedBindingForRead($intent,
                            ['root'=>$root,'payment'=>$locked,'inspection'=>$inspection,'refund'=>$view]);
                        $applications=$intent['contract_version']===2?$intent['evidence']['applications']:
                            ($intent['evidence']['application']===null?[]:[$intent['evidence']['application']]);
                        foreach($applications as $application)$providerByAction[$application['action_id']]=$application['refund_no'];
                    }
                    $sequence=[];
                    foreach($inspection['actions'] as $action)if($action['refund_id']===$refundId&&$action['action']==='finance_observe')
                        $sequence[]=['action_id'=>$action['id'],'applied_at'=>RefundFacts::id($action['committed_at'],4294967295),
                            'confirmed_amount'=>$action['result_payload']['after']['confirmed_amount']];
                    foreach(FinanceBillReconciliation::refundIncrements($sequence) as $increment){
                        $at=$increment['applied_at'];if($at<$start||$at>=$end)continue;
                        $action=$increment['action_id'];$linked=array_key_exists($action,$providerByAction);
                        $ref=$linked?'refund:'.$intent['id'].':'.$action:'legacy-refund:'.$refundId.':'.$action;
                        $provider=$providerByAction[$action]??null;
                        $rows[]=self::movement($account,'refund',$ref,$increment['amount'],$provider,$at);
                        if($provider===null)$issues[]=['root_order_id'=>$id,'local_ref'=>$ref,'reason'=>'REFUND_PROVIDER_REFERENCE_PENDING'];
                    }
                }
            }
            $this->native($pdo);
        }
        if(count($rows)>FinanceBillReconciliation::MAX_ROWS)throw new HttpException(422,'期间超过1000条资金流水，请缩短账单期间');
        usort($rows,static fn(array $a,array $b):int=>[$a['occurred_at'],$a['kind'],$a['local_ref']]<=>[$b['occurred_at'],$b['kind'],$b['local_ref']]);
        return ['rows'=>$rows,'issues'=>$issues,'source_sha256'=>hash('sha256',RefundFacts::canonical(['rows'=>$rows,'issues'=>$issues]))];
    }

    private function anchors(PDO $pdo,array $ids):array
    {
        [, $prefix]=$this->native($pdo);if($ids===[])return [];
        foreach($ids as $id)RefundFacts::u64($id);
        if(count($ids)>100)throw new AuthUnavailableException();
        $refs=self::rows($pdo,'SELECT id,order_id FROM `'.$prefix.'marketplace_payment_attempt` WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
        if(count($refs)!==count($ids))throw new HttpException(422,'原付款账户不存在，请重新选择');
        $roots=array_values(array_unique(array_column($refs,'order_id')));
        $headers=self::rows($pdo,'SELECT * FROM `'.$prefix.'store_order` WHERE id IN ('.implode(',',array_fill(0,count($roots),'?')).') ORDER BY id',$roots);
        $observations=$this->payments->readObservationsForRoots($pdo,$headers);$out=[];
        foreach($refs as $ref){
            foreach($observations[(int)$ref['order_id']]['locked']['attempts']??[] as $attempt)
                if($attempt['id']===(string)$ref['id'])$out[(string)$ref['id']]=$attempt;
            if(!isset($out[(string)$ref['id']]))throw new AuthUnavailableException();
        }
        return $out;
    }
    private static function movement(array $account,string $kind,string $ref,string $amount,?string $provider,int $at):array
    {return ['kind'=>$kind,'local_ref'=>$ref,'mer_id'=>$account['mer_id'],'channel'=>$account['channel'],
        'account_ref'=>$account['account_ref'],'account_version'=>$account['version'],'currency'=>$account['currency'],
        'amount'=>$amount,'channel_fee'=>null,'provider_no'=>$provider,'occurred_at'=>$at];}
    private function native(PDO $pdo):array
    {
        $connection=Db::connect();$prefix=$connection->getConfig('prefix');
        if(!$connection instanceof Mysql||$connection->getPdo()!==$pdo||$connection->transactionDepth()!==0||!$pdo->inTransaction()
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'marketplace_finance_reconciliation')>64)
            throw new AuthUnavailableException();
        return [$connection,$prefix];
    }
    private static function rows(PDO $pdo,string $sql,array $args=[]):array
    {$s=$pdo->prepare($sql);try{if(!$s||!$s->execute($args))throw new AuthUnavailableException();return $s->fetchAll(PDO::FETCH_ASSOC);}finally{if($s)$s->closeCursor();}}
}
