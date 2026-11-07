<?php
declare(strict_types=1);
namespace app\services\marketplace\finance;

use app\dao\marketplace\scope\PlatformScope;
use app\Request;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\EmployeeAuthServices;
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
    public const STATEMENTS_READ = 'marketplace-finance-statements-read';
    public const STATEMENTS_CREATE = 'marketplace-finance-statements-create';
    public const MERCHANT_STATEMENTS_READ = 'finance.statement.read';

    public function __construct(private PlatformAccessServices $access, private AuditLogServices $audit,
        private FinanceBillSource $source, private EmployeeAuthServices $employees) {}

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
            $this->write($scope,self::READ,static fn():array=>[]);
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
            $saved=$this->write($scope,self::IMPORT,function(PDO $pdo,string $prefix)use($scope,$input,$captured):array{
                $store = new FinanceBillStore($pdo, $prefix);
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
            $saved=$this->write($scope,self::RECONCILE,function(PDO $pdo,string $prefix)use($scope,$captured,$key):array{
                $store = new FinanceBillStore($pdo, $prefix);
                $receipt=$store->append($captured['bill'],$captured['source'],$captured['at'],$scope->adminId,$key);
                return $receipt+['record'=>$store->detail($captured['bill']['id'],$receipt['result_id'])];
            });
            return self::envelope($saved);
        });
    }

    public function readStatements(PlatformScope $scope, array $query): array
    {
        return $this->guard(function () use ($scope, $query): array {
            $this->authorize($scope, self::STATEMENTS_READ);
            $result = $this->snapshot($scope, self::STATEMENTS_READ, function (PDO $pdo, string $prefix) use ($query): array {
                return $this->statementQuery(new FinanceStatementStore($pdo, $prefix), $query, null);
            });
            $this->write($scope, self::STATEMENTS_READ, static fn(): array => []);
            return self::statementEnvelope($result);
        });
    }

    public function createStatement(PlatformScope $scope, array $body): array
    {
        return $this->guard(function () use ($scope, $body): array {
            $this->authorize($scope, self::STATEMENTS_CREATE);
            Input::body($body, ['bill_id','result_id','rule_revision'], ['bill_id','result_id','rule_revision']);
            $billId = Facts::u64($body['bill_id']);
            $resultId = Facts::u64($body['result_id']);
            $revision = Input::integer($body['rule_revision'], 0, 4294967295);
            $captured = $this->snapshot($scope, self::STATEMENTS_CREATE,
                function (PDO $pdo, string $prefix, int $at) use ($billId, $resultId, $revision): array {
                    $statements = new FinanceStatementStore($pdo, $prefix);
                    $existing = $statements->find($billId, $resultId, $revision);
                    if ($existing !== null) return ['record' => $statements->detail($existing, null), 'reused_statement' => true];
                    $bills = new FinanceBillStore($pdo, $prefix);
                    $bill = $bills->load($billId);
                    $detail = $bills->detail($billId, $resultId);
                    $account = $this->source->account($pdo, $bill['anchor_attempt_id']);
                    if (Facts::canonical($account) !== Facts::canonical($bill['account_snapshot'])) throw new AuthUnavailableException();
                    $draft = $statements->draft($revision);
                    $evidence = $this->source->statementEvidence($pdo, $account, (int)$bill['period_start'], (int)$bill['period_end']);
                    $projection = FinanceStatementProjection::build($detail, $evidence, $draft);
                    return [
                        'header' => ['bill_id' => $billId, 'result_id' => $resultId, 'rule_revision' => $revision,
                            'mer_id' => $account['mer_id'], 'date_from' => $bill['date_from'], 'date_to' => $bill['date_to'],
                            'status' => $projection['status'], 'created_at' => $at],
                        'report' => ['fee_rule' => FinanceStatementStore::feeRule($draft), 'rule_sha256' => hash('sha256', Facts::canonical($draft)),
                            'bill_file_sha256' => $detail['file_sha256'],
                            'source_sha256' => $detail['source_sha256'], 'observed_source_sha256' => $evidence['source_sha256'],
                            'order_basis' => $projection['orders'] === [] ? [] : $evidence['orders'], 'projection' => $projection],
                    ];
                });
            $saved = $this->write($scope, self::STATEMENTS_CREATE, function (PDO $pdo, string $prefix) use ($scope, $captured): array {
                if (isset($captured['record'])) return $captured;
                return (new FinanceStatementStore($pdo, $prefix))->save($captured['header'], $captured['report'], $scope->adminId);
            });
            return self::statementEnvelope($saved);
        });
    }

    public function readMerchantStatements(MerchantContext $context, array $query): array
    {
        return $this->guard(function () use ($context, $query): array {
            $request = app()->request;
            if (!$request instanceof Request || $request->merchantContext() !== $context || app('http')->getName() !== 'merchantapi'
                || $request->method() !== 'GET' || $request->rule()?->getOption('merchant_permission') !== self::MERCHANT_STATEMENTS_READ) {
                throw new AuthUnavailableException();
            }
            $authorize = fn() => $this->employees->authorizeAction($context, self::MERCHANT_STATEMENTS_READ);
            $result = $this->readSnapshot($authorize, function (PDO $pdo, string $prefix) use ($context, $query): array {
                return $this->statementQuery(new FinanceStatementStore($pdo, $prefix), $query, $context->merId);
            });
            $authorize();
            return self::statementEnvelope($result);
        });
    }

    private function statementQuery(FinanceStatementStore $store, array $query, ?int $merchant): array
    {
        $view = $query['view'] ?? 'list';
        if ($view === 'list') {
            Input::body($query, $merchant === null ? ['view','bill_id','before_id','limit'] : ['view','before_id','limit']);
            return $store->listing($merchant, isset($query['bill_id']) ? Facts::u64($query['bill_id']) : null,
                isset($query['before_id']) ? Facts::u64($query['before_id']) : null, Input::integer($query['limit'] ?? 20, 1, 50, true));
        }
        if ($view === 'read') {
            Input::body($query, ['view','id'], ['id']);
            return ['record' => $store->detail(Facts::u64($query['id']), $merchant)];
        }
        if ($view === 'rules' && $merchant === null) {
            Input::body($query, ['view']);
            return ['versions' => $store->versions()];
        }
        throw new HttpException(422, '结算试算单查询类型不正确');
    }

    private function snapshot(PlatformScope $scope,string $permission,callable $read):array
    {
        return $this->readSnapshot(fn() => $this->authorize($scope, $permission), $read);
    }

    private function readSnapshot(callable $authorize, callable $read): array
    {
        [$connection,$pdo,$prefix]=$this->connection();$armed=false;
        try{
            $armed=true;$at=time();
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false||!$pdo->beginTransaction())throw new AuthUnavailableException();
            $this->check($connection,$pdo,$prefix,0,true);$authorize();
            $result=$read($pdo,$prefix,$at);
            $authorize();$this->check($connection,$pdo,$prefix,0,true);
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
            $data=$write($pdo,$prefix);
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
        if (in_array($permission, [self::IMPORT, self::RECONCILE], true)) $this->access->financeBillReadForMutation($scope);
        if ($permission === self::STATEMENTS_CREATE) $this->access->financeStatementReadForMutation($scope);
    }
    private function guard(callable $run):array
    {try{return $run();}catch(AuthException|HttpException $error){throw $error;}catch(Throwable $error){throw new AuthUnavailableException();}}
    private static function envelope(array $data):array
    {return ['schema'=>1,'scope'=>'testing_bill_reconciliation_v1','real_funds_enabled'=>false]+$data;}
    private static function statementEnvelope(array $data): array
    {return ['schema'=>1,'scope'=>'testing_settlement_statement_v1','real_funds_enabled'=>false]+$data;}
}
