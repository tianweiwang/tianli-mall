<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\PlatformAccessServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;
use Throwable;

final class FinanceTransactionsServices
{
    public const READ='marketplace-finance-transactions-read';
    public function __construct(private PlatformAccessServices $access,private AuditLogServices $audit,private MarketplacePaymentDao $payments) {}

    public function read(PlatformScope $scope,array $query=[]): array
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
                $fields=array_merge(MarketplacePaymentDao::merchantExitColumns()['root'],['pay_postage','total_price']);
                $columns=implode(',',array_map(static fn(string $field):string=>'o.`'.$field.'`',$fields));
                $sql='SELECT '.$columns.' FROM `'.$prefix.'store_order` o JOIN `'.$prefix.'marketplace_payment_registry` r ON r.order_id=o.id AND r.payment_contract_version=1'
                    .' WHERE o.pid=0 AND o.marketplace_trade_version=1 AND EXISTS (SELECT 1 FROM `'.$prefix.'marketplace_payment_attempt` a WHERE a.order_id=o.id)';
                $params=[];if($before!==null){$sql.=' AND o.id<?';$params[]=$before;}
                $headers=self::rows($pdo,$sql.' ORDER BY o.id DESC LIMIT '.($limit+1),$params);$hasMore=count($headers)>$limit;
                if($hasMore)array_pop($headers);
                $observations=$this->payments->readObservationsForRoots($pdo,$headers);$list=[];
                foreach($headers as $header){$id=self::number($header['id'],1);$list[]=FinanceTransactionProjection::row($header,$observations[$id],$draft);}
                $data=['schema'=>1,'scope'=>'testing_persisted_payment_v1','real_funds_enabled'=>false,
                    'available_revisions'=>$available,'selected_revision'=>$revision,'rule'=>$draft===null?null:FinanceTransactionProjection::rule($draft),
                    'list'=>$list,'next_before_id'=>$hasMore?$list[count($list)-1]['root_order_id']:null,
                    'channel_fee_amount'=>null,'merchant_receivable'=>null,'settled_amount'=>null];
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
