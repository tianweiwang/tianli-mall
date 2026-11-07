<?php
declare(strict_types=1);
namespace app\services\marketplace\finance;

use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\PlatformAccessServices;
use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;
use Throwable;

final class FinanceBillsServices
{
    public const READ='marketplace-finance-bills-read';
    public const IMPORT='marketplace-finance-bills-import';
    public const RECONCILE='marketplace-finance-bills-reconcile';
    public function __construct(private PlatformAccessServices $access,private AuditLogServices $audit,private FinanceBillSource $source) {}

    public function read(PlatformScope $scope,array $query):array
    {
        return $this->guard(function()use($scope,$query):array{
            $this->authorize($scope,self::READ);$view=$query['view']??'list';
            $data=$this->snapshot($scope,self::READ,function(PDO $pdo,string $prefix,int $at)use($query,$view):array{
                $store=new FinanceBillStore($pdo,$prefix);
                if($view==='list'){
                    Input::body($query,['view','before_id','limit']);
                    $before=isset($query['before_id'])?Facts::u64($query['before_id']):null;
                    return $store->listing($before,Input::integer($query['limit']??20,1,50,true));
                }
                if($view==='read'){
                    Input::body($query,['view','id','result_id'],['id']);
                    return ['record'=>$store->detail(Facts::u64($query['id']),isset($query['result_id'])?Facts::u64($query['result_id']):null)];
                }
                if($view==='accounts'){
                    Input::body($query,['view','mer_id'],['mer_id']);
                    return ['accounts'=>$this->source->accounts($pdo,Input::integer($query['mer_id'],1,4294967295,true))];
                }
                if($view==='movements'){
                    Input::body($query,['view','anchor_attempt_id','date_from','date_to'],['anchor_attempt_id','date_from','date_to']);
                    $period=FinanceBillInput::period($query);$account=$this->source->account($pdo,$period['anchor_attempt_id']);
                    return ['movements'=>$this->source->movements($pdo,$account,$period['period_start'],$period['period_end']),
                        'snapshot_at'=>$at,'period_open'=>$at<$period['period_end']];
                }
                throw new HttpException(422,'账单查询类型不正确');
            });
            $this->write($scope,self::READ,static fn(FinanceBillStore $store):array=>[]);
            return self::envelope($data);
        });
    }

    public function import(PlatformScope $scope,array $body):array
    {
        return $this->guard(function()use($scope,$body):array{
            $this->authorize($scope,self::IMPORT);$input=FinanceBillInput::import($body);
            $captured=$this->snapshot($scope,self::IMPORT,function(PDO $pdo,string $prefix,int $at)use($input):array{
                $account=$this->source->account($pdo,$input['anchor_attempt_id']);
                return ['account'=>$account,'source'=>$this->source->movements($pdo,$account,$input['period_start'],$input['period_end']),'at'=>$at];
            });
            $saved=$this->write($scope,self::IMPORT,function(FinanceBillStore $store)use($scope,$input,$captured):array{
                $receipt=$store->import($input,$captured['account'],$captured['source'],$captured['at'],$scope->adminId);
                return $receipt+['record'=>$store->detail($receipt['id'])];
            });
            return self::envelope($saved);
        });
    }

    public function reconcile(PlatformScope $scope,array $body):array
    {
        return $this->guard(function()use($scope,$body):array{
            $this->authorize($scope,self::RECONCILE);Input::body($body,['id','request_key'],['id','request_key']);
            $id=Facts::u64($body['id']);$key=$body['request_key'];
            if(!is_string($key)||preg_match('/\Areconcile:[a-f0-9]{32}\z/D',$key)!==1)throw new HttpException(422,'对账请求标识不正确');
            $captured=$this->snapshot($scope,self::RECONCILE,function(PDO $pdo,string $prefix,int $at)use($id):array{
                $bill=(new FinanceBillStore($pdo,$prefix))->load($id);$account=$this->source->account($pdo,$bill['anchor_attempt_id']);
                if(Facts::canonical($bill['account_snapshot'])!==Facts::canonical($account))throw new AuthUnavailableException();
                return ['bill'=>$bill,'source'=>$this->source->movements($pdo,$account,(int)$bill['period_start'],(int)$bill['period_end']),'at'=>$at];
            });
            $saved=$this->write($scope,self::RECONCILE,function(FinanceBillStore $store)use($scope,$captured,$key):array{
                $receipt=$store->append($captured['bill'],$captured['source'],$captured['at'],$scope->adminId,$key);
                return $receipt+['record'=>$store->detail($captured['bill']['id'],$receipt['result_id'])];
            });
            return self::envelope($saved);
        });
    }

    private function snapshot(PlatformScope $scope,string $permission,callable $read):array
    {
        [$connection,$pdo,$prefix]=$this->connection();$armed=false;
        try{
            $armed=true;$at=time();
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false||!$pdo->beginTransaction())throw new AuthUnavailableException();
            $this->check($connection,$pdo,$prefix,0,true);$this->authorize($scope,$permission);
            $result=$read($pdo,$prefix,$at);
            $this->authorize($scope,$permission);$this->check($connection,$pdo,$prefix,0,true);
            if(!$pdo->commit())throw new AuthUnavailableException();$armed=false;
            $this->check($connection,$pdo,$prefix,0,false);return $result;
        }catch(Throwable $error){
            if($armed){try{if(!$pdo->inTransaction()||!$pdo->rollBack())$connection->close();}catch(Throwable $ignored){$connection->close();}}
            throw $error;
        }
    }

    private function write(PlatformScope $scope,string $permission,callable $write):array
    {
        [$connection,$pdo,$prefix]=$this->connection();
        $result=$connection->transaction(function()use($connection,$pdo,$prefix,$scope,$permission,$write):array{
            $this->check($connection,$pdo,$prefix,1,true);$this->authorize($scope,$permission);
            $data=$write(new FinanceBillStore($pdo,$prefix));
            $this->authorize($scope,$permission);$this->audit->recordFinanceSettingsScope($scope,$pdo);
            $this->check($connection,$pdo,$prefix,1,true);return $data;
        });
        $this->check($connection,$pdo,$prefix,0,false);return $result;
    }
    private function connection():array
    {
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        $prefix=$connection->getConfig('prefix');
        if(!$connection instanceof Mysql||!$pdo instanceof PDO||!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||strlen($prefix.FinanceBillStore::RESULT)>64)throw new AuthUnavailableException();
        $this->check($connection,$pdo,$prefix,0,false);return [$connection,$pdo,$prefix];
    }
    private function check(Mysql $connection,PDO $pdo,string $prefix,int $depth,bool $transaction):void
    {
        if(PHP_INT_SIZE<8||Db::connect()!==$connection||$connection->getPdo()!==$pdo||$connection->getConfig('prefix')!==$prefix
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==$transaction
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))throw new AuthUnavailableException();
    }
    private function authorize(PlatformScope $scope,string $permission):void
    {
        if($scope->targetMerId!==null||$scope->adminId>4294967295)throw new AuthUnavailableException();
        $this->access->authorizeScope($scope,$permission);
        if($permission!==self::READ)$this->access->financeBillReadForMutation($scope);
    }
    private function guard(callable $run):array
    {try{return $run();}catch(AuthException|HttpException $error){throw $error;}catch(Throwable $error){throw new AuthUnavailableException();}}
    private static function envelope(array $data):array
    {return ['schema'=>1,'scope'=>'testing_bill_reconciliation_v1','real_funds_enabled'=>false]+$data;}
}
