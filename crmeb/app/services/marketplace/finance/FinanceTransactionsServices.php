<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\PlatformAccessServices;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\marketplace\order\MarketplaceOrderRefundFacts;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Config;
use think\facade\Db;
use think\exception\HttpException;
use Throwable;

final class FinanceTransactionsServices
{
    public const READ='marketplace-finance-transactions-read';
    public function __construct(private PlatformAccessServices $access,private AuditLogServices $audit,private MarketplacePaymentDao $payments,
        private MarketplaceOrderDao $orders,private MarketplaceOrderFulfillmentDao $families,
        private MarketplaceOrderRefundDao $refunds,private MarketplaceOrderFulfillmentServices $fulfillment) {}

    public function read(PlatformScope $scope,array $query=[],bool $includeCombined=false): array
    {
        try{
            $this->authorize($scope);MerchantManagementInput::body($query,['before_id','limit','revision']);
            $limit=MerchantManagementInput::integer($query['limit']??20,1,50,true);
            $before=isset($query['before_id'])?MerchantManagementInput::integer($query['before_id'],1,2147483647,true):null;
            $revision=isset($query['revision'])?MerchantManagementInput::integer($query['revision'],1,4294967295,true):null;
            $connection=Db::connect();
            if(!$connection instanceof Mysql)throw new AuthUnavailableException();
            $pdo=$connection->getPdo();if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
            $prefix=$connection->getConfig('prefix');
            if(!$pdo instanceof PDO||!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
                ||strlen($prefix.'marketplace_finance_settings_history')>64)throw new AuthUnavailableException();
            $this->connection($connection,$pdo,$prefix,false);$set=false;
            try{
                $set=true;if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false||!$pdo->beginTransaction())throw new AuthUnavailableException();
                $this->connection($connection,$pdo,$prefix,true);$this->authorize($scope);
                $versions=self::rows($pdo,'SELECT revision,created_at FROM `'.$prefix.'marketplace_finance_settings_history` ORDER BY revision DESC LIMIT 20');
                $available=[];foreach($versions as $version)$available[]=['revision'=>self::number($version['revision'],1),'created_at'=>self::number($version['created_at'],1)];
                $draft=null;
                if($revision!==null){
                    $saved=self::rows($pdo,'SELECT payload FROM `'.$prefix.'marketplace_finance_settings_history` WHERE revision=?',[$revision]);
                    if(count($saved)!==1)throw new HttpException(422,'请选择已保存的资金规则版本');
                    $decoded=json_decode($saved[0]['payload'],true,32,JSON_THROW_ON_ERROR);$draft=FinanceSettingsInput::draft($decoded);
                    foreach($draft as $key=>$value)if($decoded[$key]!==$value)throw new AuthUnavailableException();
                }
                // Keep the original page; the internal complete rows are needed by the existing refund capture verifier.
                $sql='SELECT o.* FROM `'.$prefix.'store_order` o JOIN `'.$prefix.'marketplace_payment_registry` r ON r.order_id=o.id AND r.payment_contract_version=1'
                    .' WHERE o.pid=0 AND o.marketplace_trade_version=1 AND EXISTS (SELECT 1 FROM `'.$prefix.'marketplace_payment_attempt` a WHERE a.order_id=o.id)';
                $params=[];if($before!==null){$sql.=' AND o.id<?';$params[]=$before;}
                $headers=self::rows($pdo,$sql.' ORDER BY o.id DESC LIMIT '.($limit+1),$params);$hasMore=count($headers)>$limit;
                if($hasMore)array_pop($headers);
                $observations=$this->payments->readObservationsForRoots($pdo,$headers);
                $summaries=$this->refundSummaries($connection,$pdo,$prefix,$headers,$observations);$list=[];
                foreach($headers as $header){
                    $id=self::number($header['id'],1);$list[]=FinanceTransactionProjection::row($header,$observations[$id],$draft,$summaries[$id]);
                }
                $combinedPayments = $includeCombined && Config::get('marketplace.combined_checkout.enabled', false) === true
                    ? self::projectCombinedPayments($pdo, $prefix, $list) : null;
                $data=['schema'=>1,'scope'=>'testing_persisted_payment_v1','real_funds_enabled'=>false,
                    'available_revisions'=>$available,'selected_revision'=>$revision,'rule'=>$draft===null?null:FinanceTransactionProjection::rule($draft),
                    'list'=>$list,'next_before_id'=>$hasMore?$list[count($list)-1]['root_order_id']:null,
                    'channel_fee_amount'=>null,'merchant_receivable'=>null,'settled_amount'=>null];
                if ($combinedPayments !== null) {
                    $data['combined_payments'] = $combinedPayments;
                }
                $this->authorize($scope);$this->connection($connection,$pdo,$prefix,true);
                if(!$pdo->commit())throw new AuthUnavailableException();$set=false;$this->connection($connection,$pdo,$prefix,false);
            }catch(Throwable $error){
                if($set){try{if(!$pdo->inTransaction()||!$pdo->rollBack())$connection->close();}catch(Throwable $ignored){$connection->close();}}
                throw $error;
            }
            // Normal platform audit only, after the native READ ONLY snapshot has ended.
            $connection->transaction(function()use($scope,$pdo):void{$this->authorize($scope);$this->audit->recordFinanceSettingsScope($scope,$pdo);});
            $this->connection($connection,$pdo,$prefix,false);return $data;
        }catch(AuthException|HttpException $error){throw $error;}
        catch(Throwable $error){throw new AuthUnavailableException();}
    }

    /** Join only the authorized page's root orders to their saved combined payment. */
    private static function projectCombinedPayments(PDO $pdo, string $prefix, array &$list): array
    {
        if ($list === []) {
            return [];
        }
        $ids = array_column($list, 'root_order_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::rows($pdo,
            'SELECT m.`root_order_id`, m.`mer_id`, m.`amount`, c.`order_no`, c.`state`, c.`test_trade_no`, c.`total_amount`, c.`paid_at`'
            . ' FROM `' . $prefix . 'marketplace_combined_checkout_order` m'
            . ' JOIN `' . $prefix . 'marketplace_combined_checkout` c ON c.`id` = m.`checkout_id`'
            . ' WHERE m.`root_order_id` IN (' . $placeholders . ')', $ids);
        $allocations = [];
        foreach ($rows as $row) {
            $id = self::number($row['root_order_id'], 1);
            if (isset($allocations[$id]) || !in_array($id, $ids, true)) {
                throw new AuthUnavailableException();
            }
            $allocations[$id] = $row;
        }
        $groups = [];
        foreach ($list as &$payment) {
            $allocation = $allocations[$payment['root_order_id']] ?? null;
            if ($allocation === null) {
                continue;
            }
            $orderNo = $allocation['order_no'];
            $tradeNo = $allocation['test_trade_no'];
            $total = $allocation['total_amount'];
            if ($allocation['state'] !== 'paid' || self::number($allocation['mer_id'], 1) !== $payment['mer_id']
                || $allocation['amount'] !== $payment['order_amount'] || array_column($payment['attempts'], 'state') !== ['applied']
                || !is_string($orderNo) || preg_match('/\Acc[0-9a-f]{24}\z/D', $orderNo) !== 1
                || $tradeNo !== 'ct' . substr($orderNo, 2)
                || !is_string($total) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $total) !== 1
                || bccomp($total, $allocation['amount'], 2) <= 0) {
                throw new AuthUnavailableException();
            }
            $group = ['order_no' => $orderNo, 'test_trade_no' => $tradeNo,
                'total_amount' => $total, 'paid_at' => self::number($allocation['paid_at'], 1)];
            if (isset($groups[$orderNo]) && $groups[$orderNo] !== $group) {
                throw new AuthUnavailableException();
            }
            $groups[$orderNo] = $group;
            $payment['combined_checkout'] = ['payment_role' => 'allocation', 'order_no' => $orderNo, 'total_amount' => $total];
        }
        unset($payment);
        return array_values($groups);
    }

    /** Existing same-PDO facts only. Unapplied payment roots never enter the aftersale reader. */
    private function refundSummaries(Mysql $connection,PDO $pdo,string $prefix,array $headers,array $observations): array
    {
        $out=[];$roots=[];$allIds=[];
        foreach($headers as $root){
            $id=self::number($root['id'],1);
            if(array_key_exists($id,$out)||!isset($observations[$id])||!is_array($observations[$id]))throw new AuthUnavailableException();
            $out[$id]=null;$allIds[]=$id;
            if(FinanceTransactionProjection::hasAppliedPayment($observations[$id]))$roots[]=$root;
        }
        if(array_keys($observations)!==$allIds)throw new AuthUnavailableException();
        if($roots===[])return $out;
        $this->connection($connection,$pdo,$prefix,true);
        $ready=$this->families->readAftersaleStructureReadiness($connection,$pdo);
        if($ready!==['schema'=>2,'state'=>'READY'])throw new AuthUnavailableException();
        $this->connection($connection,$pdo,$prefix,true);
        $facts8=$this->orders->readPaymentApplicationCaptureFactsBatch($connection,$pdo,$roots);
        $this->connection($connection,$pdo,$prefix,true);
        $families=$this->families->readFulfillmentFamiliesForRoots($connection,$pdo,$roots);
        $this->connection($connection,$pdo,$prefix,true);
        $refunds=$this->refunds->readRefundsForRoots($connection,$pdo,$roots);
        $this->connection($connection,$pdo,$prefix,true);
        $actions=$this->refunds->readActionsForRoots($connection,$pdo,$roots);
        $this->connection($connection,$pdo,$prefix,true);
        $logs=$this->refunds->readStatusLogsForRoots($connection,$pdo,$roots);
        $this->connection($connection,$pdo,$prefix,true);
        $ids=array_map(static fn(array $root):int=>self::number($root['id'],1),$roots);
        foreach([$facts8,$families,$refunds,$actions,$logs] as $map)
            if(array_keys($map)!==$ids)throw new AuthUnavailableException();
        foreach($roots as $root){
            $id=self::number($root['id'],1);$observation=$observations[$id];
            $capture=$this->orders->capturePaymentApplicationFactsFromFacts(self::number($root['uid'],1),$id,$facts8[$id]);
            if($capture['root']!==$root)throw new AuthUnavailableException();
            $raw=$families[$id];MarketplaceOrderFulfillmentDao::keys($raw,['children','child_items','operations','ranges']);
            $family=['children'=>$raw['children'],'items'=>$raw['child_items'],'ranges'=>$raw['ranges'],'refunds'=>$refunds[$id]];
            $cut=$actions[$id]===[]?null:MarketplaceOrderFulfillmentDao::u64($actions[$id][count($actions[$id])-1]['id']);
            $context=['schema'=>2,'cut_id'=>$cut,'actions'=>$actions[$id],'refunds'=>$refunds[$id],
                'operations'=>$raw['operations'],'ranges'=>$raw['ranges'],'logs'=>$logs[$id]];
            $inspection=MarketplaceOrderRefundFacts::inspectForAftersale($capture,$context,$observation['locked']);
            $this->fulfillment->validateAftersaleFamilyFromFacts($root,$capture,$family,$inspection);
            $capacity=MarketplaceOrderRefundFacts::quoteSelectionFromInspection($inspection)['capacity'];
            if($capacity['currency']!=='CNY'||$capacity['paid']!==$root['pay_price'])throw new AuthUnavailableException();
            // Capacity already uses each refund's final saved state; never sum cumulative observations or applications here.
            $out[$id]=['confirmed_amount'=>$capacity['confirmed'],'held_amount'=>$capacity['held']];
        }
        if($this->families->readAftersaleStructureReadiness($connection,$pdo)!==$ready)throw new AuthUnavailableException();
        $this->connection($connection,$pdo,$prefix,true);return $out;
    }

    private function authorize(PlatformScope $scope): void
    {
        if($scope->targetMerId!==null)throw new AuthUnavailableException();
        $this->access->authorizeScope($scope,self::READ);
    }
    private function connection(Mysql $connection,PDO $pdo,string $prefix,bool $inside): void
    {
        if(PHP_INT_SIZE<8||$connection!==Db::connect()||$connection->getPdo()!==$pdo||$connection->getConfig('prefix')!==$prefix
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false||$connection->transactionDepth()!==0
            ||$pdo->inTransaction()!==$inside||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))throw new AuthUnavailableException();
    }
    private static function rows(PDO $pdo,string $sql,array $params=[]): array
    {
        $statement=$pdo->prepare($sql);if($statement===false)throw new AuthUnavailableException();
        try{if(!$statement->execute($params))throw new AuthUnavailableException();return $statement->fetchAll(PDO::FETCH_ASSOC);}
        finally{$statement->closeCursor();}
    }
    private static function number(mixed $value,int $minimum=0): int
    {
        if(!is_int($value)&&(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)!==1))throw new AuthUnavailableException();
        $number=(int)$value;if($number<$minimum||$number>4294967295)throw new AuthUnavailableException();return $number;
    }
}
