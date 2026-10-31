<?php
declare(strict_types=1);
namespace app\services\marketplace\payment;

use app\Request;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
use app\dao\marketplace\payment\MarketplaceRefundDao;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\PlatformAccessServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\order\MarketplaceOrderRefundInput;
use app\services\marketplace\order\MarketplaceOrderRefundReadServices;
use app\services\marketplace\order\MarketplaceOrderRefundWriter;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\pay\marketplace\PaymentAccount;
use crmeb\services\pay\marketplace\PaymentAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use PDO;
use think\App;
use think\db\ConnectionInterface;
use think\exception\HttpException;
use think\facade\Config;
use think\facade\Db;

/** Testing-only intent -> original same-instance driver -> saved observation. Never a real refund channel. */
final class MarketplaceRefundServices
{
    public const PERMISSION='marketplace-aftersales-test-refund';
    public const MERCHANT_PERMISSION='aftersale.test_refund';
    private readonly App $app;
    private ?array $createContext=null;
    private ?array $observationContext=null;
    public function __construct(private MarketplaceRefundDao $intents,private MarketplaceOrderRefundDao $refunds,
        private MarketplaceOrderRefundWriter $writer,private MarketplaceOrderRefundReadServices $reads,
        private EmployeeAuthServices $employees,private PlatformAccessServices $platform,private AuditLogServices $audit)
    {$this->app=app();}

    public function optionsForMerchant(Request $request,MerchantContext $context,mixed $id):array
    {return $this->options($this->reads->testRefundSnapshotForMerchant($request,$context,$id));}
    public function optionsForPlatform(Request $request,PlatformScope $scope,mixed $id):array
    {return $this->options($this->reads->testRefundSnapshotForPlatform($request,$scope,$id));}
    private function options(array $saved):array
    {
        $view=$saved['refund'];$row=$saved['attempt'];$state=$row===null?'not_started':MarketplaceRefundEvidence::state($row);
        if($row!==null)$this->savedBinding($row,$saved);
        $enabled=getenv('APP_ENV')==='testing'&&Config::get('marketplace.payment.test_enabled',false)===true;
        $reason=$state==='applied'?'ALREADY_APPLIED':($state!=='not_started'?'PENDING_CONFIRMATION':
            (!$enabled?'TESTING_DISABLED':(!$saved['structure']?'STRUCTURE_UNAVAILABLE':
                (!$this->eligible($saved)?'NOT_ELIGIBLE':'AVAILABLE'))));
        return ['schema'=>1,'scope'=>'testing_refund_availability_v1','refund_id'=>$view['id'],'available'=>$reason==='AVAILABLE',
            'reason'=>$reason,'attempt_id'=>$row['id']??null,'refund_idem'=>$row['idem_key']??null,'state'=>$state,
            'amount'=>$row['amount']??($reason==='AVAILABLE'?$view['held_amount']:null),'currency'=>'CNY',
            'confirmed_amount'=>$view['confirmed_amount'],'held_amount'=>$view['held_amount'],
            'is_test_payment'=>$view['is_test_payment'],'real_refund_enabled'=>false];
    }
    public function executeForMerchant(Request $request,MerchantContext $context,mixed $id):array
    {return $this->execute($request,$context,null,MarketplaceOrderRefundInput::id($id));}
    public function executeForPlatform(Request $request,PlatformScope $scope,mixed $id):array
    {return $this->execute($request,null,$scope,MarketplaceOrderRefundInput::id($id));}
    private function execute(Request $request,?MerchantContext $merchant,?PlatformScope $scope,int $refundId):array
    {
        PaymentAccount::assertTesting();$this->factory();$identity=$this->authorize($request,$merchant,$scope);
        $raw=$request->getContent();if(!is_string($raw)||strlen($raw)>512)throw new HttpException(422,'测试退款参数不正确');
        $body=MarketplaceOrderRefundInput::decode($raw);
        if(array_keys($body)!==['refund_idem']||!is_string($body['refund_idem'])
            ||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$body['refund_idem'])!==1)
            throw new HttpException(422,'测试退款参数不正确');
        $idem=MarketplaceRefundEvidence::key($body['refund_idem']);[$connection,$pdo]=$this->connection();
        if(!$this->intents->structure($pdo))throw new HttpException(503,'测试退款结构尚未就绪');
        $target=$this->refunds->locateRefund($connection,$pdo,$refundId);if($target===null)self::fail();
        $root=$this->refunds->locateRoot($connection,$pdo,MarketplaceRefundEvidence::id($target['marketplace_root_order_id']));
        if($root===null)self::fail();$candidate=self::candidate($root);
        if($merchant!==null&&$candidate['mer_id']!==$merchant->merId)self::fail();
        $admission=$this->transaction($connection,$pdo,function()use($request,$merchant,$scope,$identity,$connection,$pdo,$candidate,$refundId,$idem):array{
            $saved=$this->locked($connection,$pdo,$candidate,$refundId);
            if($this->authorize($request,$merchant,$scope,true)!==$identity)self::fail();
            $existing=$this->intents->find($pdo,$refundId,true);
            if($existing!==null){
                $this->savedBinding($existing,$saved);if($existing['idem_key']!==$idem)throw new HttpException(409,'该申请已有测试退款意图，请查看原记录');
                return ['new'=>false,'row'=>$existing,'saved'=>$saved];
            }
            if(!$this->eligible($saved))throw new HttpException(409,'该售后申请当前不可执行测试退款');
            $winner=$this->winner($saved);$approval=$this->approval($saved);$view=$saved['refund'];
            $binding=MarketplaceRefundEvidence::binding(['refund_contract_version'=>1,'refund_id'=>$refundId,
                'root_order_id'=>$candidate['root_order_id'],'order_no'=>$candidate['root_order_no'],'uid'=>$candidate['uid'],
                'mer_id'=>$candidate['mer_id'],'payment_attempt_id'=>$winner['id'],'payment_attempt_ref'=>$winner['attempt_ref'],
                'provider_trade_no'=>$winner['applied_trade_no'],'approval_action_id'=>$approval,
                'amount'=>$view['held_amount'],'confirmed_before'=>$view['confirmed_amount'],'requested_amount'=>$view['requested_amount'],
                'currency'=>'CNY','account_snapshot'=>$winner['account_snapshot']]);
            $now=self::now();$data=['contract_version'=>1,'refund_id'=>$refundId,'root_order_id'=>$candidate['root_order_id'],
                'uid'=>$candidate['uid'],'mer_id'=>$candidate['mer_id'],'payment_attempt_id'=>$winner['id'],'approval_action_id'=>$approval,
                'idem_key'=>$idem,'request_digest'=>hash('sha256',MarketplaceRefundEvidence::canonical(['binding'=>$binding,'idempotency_key'=>$idem]),true),
                'binding_digest'=>hash('sha256',MarketplaceRefundEvidence::canonical($binding),true),'account_domain_digest'=>$winner['account_domain_digest'],
                'account_snapshot'=>$winner['account_snapshot'],'amount'=>$binding['amount'],'currency'=>'CNY','attempt_ref'=>null,
                'applied_refund_no'=>null,'evidence'=>MarketplaceRefundEvidence::initial($binding),'created_at'=>$now,'updated_at'=>$now];
            $row=$this->intents->insert($pdo,$data);$this->savedBinding($row,$saved);
            return ['new'=>true,'row'=>$row,'saved'=>$saved];
        });
        $row=$admission['row'];
        if(!$admission['new']){
            if(MarketplaceRefundEvidence::state($row)==='applied')return $this->dto($row,$admission['saved']);
            throw new HttpException(409,'测试退款结果待确认，请保留原申请，不要另建请求');
        }
        $driver=$this->app->make(PaymentAdapter::class);if(!$driver instanceof TestPaymentDriver)self::fail();
        $this->outside($connection,$pdo);if($this->authorize($request,$merchant,$scope)!==$identity)self::fail();
        $original=$this->winner($admission['saved']);$actual=['binding'=>$row['evidence']['binding']];
        $this->createContext=['driver'=>$driver,'connection'=>$connection,'pdo'=>$pdo,'original'=>$original,'actual'=>$actual,'idem'=>$idem,'row'=>$row];
        try{$created=$driver->createRefund($original,$actual,$idem);}finally{$this->createContext=null;}
        $saved=$this->saveResult($connection,$pdo,$request,$merchant,$scope,$identity,$candidate,$refundId,$row,'createRefund',$created);
        $this->outside($connection,$pdo);$this->factory();
        if($this->app->make(PaymentAdapter::class)!==$driver)self::fail();
        $descriptor=MarketplaceRefundEvidence::descriptor($saved['row']);
        $completed=$driver->completeTestRefund($descriptor);
        if(($completed['state']??null)!=='succeeded')throw new HttpException(409,'测试退款结果待确认，请保留原申请');
        $actualResult=$driver->queryRefund($descriptor);
        $saved=$this->saveResult($connection,$pdo,$request,$merchant,$scope,$identity,$candidate,$refundId,$saved['row'],'queryRefund',$actualResult);
        return $this->dto($saved['row'],$saved['saved']);
    }
    private function saveResult(ConnectionInterface $connection,PDO $pdo,Request $request,?MerchantContext $merchant,?PlatformScope $scope,
        array $identity,array $candidate,int $refundId,array $original,string $op,array $result):array
    {
        return $this->transaction($connection,$pdo,function()use($connection,$pdo,$request,$merchant,$scope,$identity,$candidate,$refundId,$original,$op,$result):array{
            $saved=$this->locked($connection,$pdo,$candidate,$refundId);
            if($this->authorize($request,$merchant,$scope,true)!==$identity)self::fail();
            $before=$this->intents->find($pdo,$refundId,true);
            if($before===null||$before['id']!==$original['id']||!hash_equals($before['request_digest'],$original['request_digest']))self::fail();
            $this->savedBinding($before,$saved);$observed=MarketplaceRefundEvidence::observe($before,$op,$result,self::now());
            if(!$observed['new'])return ['row'=>$before,'saved'=>$saved];
            $row=$this->intents->save($pdo,$before,$observed['row']);$fact=$observed['fact'];$binding=$row['evidence']['binding'];
            $success=$fact['result']['state']==='succeeded';
            $confirmed=$success?$binding['requested_amount']:$binding['confirmed_before'];
            $state=$success?'SUCCEEDED':($fact['result']['state']==='unknown'?'UNKNOWN':'PROCESSING');
            $view=$saved['refund'];$applied=null;
            if($view['finance_state']!==$state||$view['confirmed_amount']!==$confirmed){
                if($view['confirmed_amount']!==$binding['confirmed_before'])self::fail();
                $body=['source_ref'=>'test-refund:'.$row['id'].':'.$fact['key'],'observed_state'=>$state,'currency'=>'CNY',
                    'confirmed_amount'=>$confirmed,'observed_at'=>$fact['at']];
                $this->observationContext=['stage'=>'writer','writer'=>$this->writer,'dao'=>$this->refunds,'connection'=>$connection,'pdo'=>$pdo,
                    'candidate'=>$candidate,'refund_id'=>$refundId,'payment_attempt_id'=>$row['payment_attempt_id'],'body'=>$body];
                try{$applied=$this->writer->observeTestRefund($connection,$pdo,$candidate,$refundId,$body);}
                finally{$this->observationContext=null;}
            }
            if($success){
                if($op!=='queryRefund'||$applied===null)self::fail();$after=$row;
                $after['applied_refund_no']=$fact['result']['provider_refund_no'];
                $after['evidence']['application']=['fact'=>$fact['key'],'action_id'=>$applied['action_id'],
                    'applied_at'=>$applied['applied_at'],'refund_no'=>$fact['result']['provider_refund_no']];
                $after['updated_at']=max($after['updated_at'],$applied['applied_at']);$row=$this->intents->save($pdo,$row,$after);
            }
            $final=$this->locked($connection,$pdo,$candidate,$refundId);$this->savedBinding($row,$final);
            $this->audit->record($merchant===null?'admin':'merchant',$identity['actor_id'],$candidate['mer_id'],
                $merchant===null?self::PERMISSION:self::MERCHANT_PERMISSION,'test_refund',(string)$refundId,'success');
            if($this->authorize($request,$merchant,$scope,true)!==$identity)self::fail();
            return ['row'=>$row,'saved'=>$final];
        });
    }
    /** Called only by the exact driver before it creates any private refund map entry. */
    public function consumeCreateContext(TestPaymentDriver $driver,array $original,array $actual,string $idem):void
    {
        PaymentAccount::assertTesting();$this->factory();$c=$this->createContext;$this->createContext=null;
        if($c===null||$c['driver']!==$driver||$this->app->make(PaymentAdapter::class)!==$driver||$c['idem']!==$idem
            ||$c['original']!==$original||$c['actual']!==$actual)self::fail();$this->outside($c['connection'],$c['pdo']);
        if(!hash_equals($c['row']['request_digest'],hash('sha256',MarketplaceRefundEvidence::canonical([
            'binding'=>$actual['binding'],'idempotency_key'=>$idem]),true)))self::fail();
    }
    public function consumeObservationContext(MarketplaceOrderRefundWriter $writer,ConnectionInterface $connection,PDO $pdo,
        array $candidate,int $refundId,array $body):void
    {
        $this->factory();$c=$this->observationContext;
        if($c===null||$c['stage']!=='writer'||$c['writer']!==$writer||$c['connection']!==$connection||$c['pdo']!==$pdo
            ||$c['candidate']!==$candidate||$c['refund_id']!==$refundId||$c['body']!==$body)self::fail();
        $this->inside($connection,$pdo);$this->observationContext['stage']='dao';
    }
    public function consumeDaoObservationContext(MarketplaceOrderRefundDao $dao,ConnectionInterface $connection,PDO $pdo,int $refundId,array $body):array
    {
        $this->factory();
        $c=$this->observationContext;
        if($c===null||$c['stage']!=='dao'||$c['dao']!==$dao||$c['connection']!==$connection||$c['pdo']!==$pdo
            ||$c['refund_id']!==$refundId||$c['body']!==$body)self::fail();$this->inside($connection,$pdo);$this->observationContext=null;
        return ['root_order_id'=>$c['candidate']['root_order_id'],'uid'=>$c['candidate']['uid'],'mer_id'=>$c['candidate']['mer_id'],
            'refund_id'=>$refundId,'payment_attempt_id'=>$c['payment_attempt_id'],'body'=>$body];
    }
    private function locked(ConnectionInterface $connection,PDO $pdo,array $candidate,int $refundId):array
    {
        $facts=$this->writer->lockTestRefundFacts($connection,$pdo,$candidate);
        $r=$facts['inspection']['history']['refunds'][$refundId]??null;if(!is_array($r))self::fail();
        $row=null;foreach($facts['family']['refunds'] as $native)if(MarketplaceRefundEvidence::id($native['id'])===$refundId)$row=$native;
        if($row===null)self::fail();$state=$r['after'];
        $view=['id'=>$refundId,'business_state'=>$state['business_state'],'physical_state'=>$state['physical_state'],
            'finance_state'=>$state['finance_state'],'requested_amount'=>$state['requested_amount'],
            'confirmed_amount'=>$state['confirmed_amount'],'held_amount'=>$state['held_amount'],'operation_kind'=>$r['kind'],
            'is_test_payment'=>$facts['root']['pay_type']==='marketplace_test'];
        return $facts+['refund'=>$view];
    }
    private function eligible(array $saved):bool
    {
        $v=$saved['refund'];$root=$saved['root'];$payment=$saved['payment'];
        if(!$v['is_test_payment']||$v['business_state']!=='APPROVED'||$v['confirmed_amount']!=='0.00'
            ||!in_array($v['finance_state'],['NOT_SUBMITTED','UNSUPPORTED'],true)
            ||bccomp($v['held_amount'],'0.00',2)<=0||$v['held_amount']!==$v['requested_amount']
            ||($v['operation_kind']==='return_goods'&&$v['physical_state']!=='RETURN_ACCEPTED')
            ||$payment['registry']['fulfillment_hold']!==0)return false;
        foreach(['is_del','is_system_del','is_cancel'] as $k)if((int)$root[$k]!==0)return false;
        $this->winner($saved);$this->approval($saved);return true;
    }
    private function winner(array $saved):array
    {
        $p=$saved['payment'];$id=MarketplaceRefundEvidence::u64($p['registry']['winner_attempt_id']??null);$found=null;
        if($saved['root']['pay_type']!=='marketplace_test'||(int)$saved['root']['paid']!==1)self::fail();
        foreach($p['attempts'] as $row)if($row['id']===$id){if($found!==null)self::fail();$found=$row;}
        if($found===null||$found['evidence']['application']===null||$found['applied_trade_no']!==$saved['root']['trade_no'])self::fail();
        MarketplaceRefundEvidence::account($found['account_snapshot']);return $found;
    }
    private function approval(array $saved):string
    {
        $found=null;foreach($saved['inspection']['actions'] as $action)
            if($action['refund_id']===$saved['refund']['id']&&$action['action']==='approve'){
                if($found!==null)self::fail();$found=$action['id'];
            }
        return MarketplaceRefundEvidence::u64($found);
    }
    private function savedBinding(array $row,array $saved):void
    {
        $row=MarketplaceRefundEvidence::row($row);$winner=$this->winner($saved);$root=$saved['root'];$b=$row['evidence']['binding'];
        if($row['refund_id']!==$saved['refund']['id']||$row['root_order_id']!==MarketplaceRefundEvidence::id($root['id'])
            ||$row['uid']!==MarketplaceRefundEvidence::id($root['uid'])||$row['mer_id']!==MarketplaceRefundEvidence::id($root['mer_id'],4294967295)
            ||$row['payment_attempt_id']!==$winner['id']||$row['approval_action_id']!==$this->approval($saved)
            ||$b['order_no']!==$root['order_id']||$b['payment_attempt_ref']!==$winner['attempt_ref']
            ||$b['provider_trade_no']!==$winner['applied_trade_no']||$b['requested_amount']!==$saved['refund']['requested_amount']
            ||MarketplaceRefundEvidence::canonical($row['account_snapshot'])!==MarketplaceRefundEvidence::canonical($winner['account_snapshot'])
            ||!hash_equals($row['account_domain_digest'],$winner['account_domain_digest']))self::fail();
        if($row['evidence']['application']!==null){
            $a=$row['evidence']['application'];$match=null;
            foreach($saved['inspection']['actions'] as $action)if($action['id']===$a['action_id'])$match=$action;
            if($match===null||$match['action']!=='finance_observe'||$match['refund_id']!==$row['refund_id']
                ||$match['request_payload']['public_body']['source_ref']!=='test-refund:'.$row['id'].':'.$a['fact']
                ||$saved['refund']['finance_state']!=='SUCCEEDED'||$saved['refund']['confirmed_amount']!==$saved['refund']['requested_amount']
                ||$saved['refund']['held_amount']!=='0.00')self::fail();
        }
    }
    private function dto(array $row,array $saved):array
    {
        $this->savedBinding($row,$saved);$v=$saved['refund'];
        return ['schema'=>1,'scope'=>'testing_refund_action_v1','attempt_id'=>$row['id'],'refund_idem'=>$row['idem_key'],
            'state'=>MarketplaceRefundEvidence::state($row),'refund_id'=>$row['refund_id'],'amount'=>$row['amount'],'currency'=>'CNY',
            'finance_state'=>$v['finance_state'],'confirmed_amount'=>$v['confirmed_amount'],'held_amount'=>$v['held_amount'],
            'is_test_payment'=>true,'real_refund_enabled'=>false];
    }
    private function authorize(Request $request,?MerchantContext $merchant,?PlatformScope $scope,bool $forWrite=false):array
    {
        if(app()!==$this->app||$this->app->request!==$request)self::fail();$fingerprint=$request->verifiedTokenFingerprint();
        if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();
        if($merchant!==null){
            if($scope!==null||$this->app->http->getName()!=='merchantapi'||$request->merchantContext()!==$merchant)self::fail();
            $this->employees->authorizeAction($merchant,self::MERCHANT_PERMISSION,$forWrite);
            return ['actor_id'=>$merchant->employeeId,'fingerprint'=>$fingerprint];
        }
        if($scope===null||$this->app->http->getName()!=='adminapi'||$request->platformScope()!==$scope
            ||$scope->permission!==self::PERMISSION||$scope->targetMerId!==null||$request->isAdminLogin()!==true
            ||(int)$request->adminId()!==$scope->adminId)self::fail();$this->platform->authorizeScope($scope,self::PERMISSION);
        return ['actor_id'=>$scope->adminId,'fingerprint'=>$fingerprint];
    }
    private function factory():void
    {
        if(app()!==$this->app||$this->app->make(self::class)!==$this||$this->app->make(MarketplaceRefundDao::class)!==$this->intents
            ||$this->app->make(MarketplaceOrderRefundDao::class)!==$this->refunds
            ||$this->app->make(MarketplaceOrderRefundWriter::class)!==$this->writer)self::fail();
    }
    private function connection():array
    {
        $c=Db::connect();$pdo=$c->getPdo();if(!$pdo instanceof PDO){$c->query('SELECT 1',[],true);$pdo=$c->getPdo();}
        if(!$pdo instanceof PDO)self::fail();$this->outside($c,$pdo);return [$c,$pdo];
    }
    private function native(ConnectionInterface $c,PDO $pdo):void
    {
        if(!$c instanceof Mysql||$c!==Db::connect()||$c->getPdo()!==$pdo||$c->getConfig('deploy')!==0||$c->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    private function outside(ConnectionInterface $c,PDO $pdo):void
    {$this->native($c,$pdo);if($c->transactionDepth()!==0||$pdo->inTransaction())self::fail();}
    private function inside(ConnectionInterface $c,PDO $pdo):void
    {$this->native($c,$pdo);if($c->transactionDepth()!==1||!$pdo->inTransaction())self::fail();}
    private function transaction(ConnectionInterface $c,PDO $pdo,\Closure $run):array
    {
        $this->outside($c,$pdo);$result=$c->transaction(function(ConnectionInterface $actual)use($c,$pdo,$run):array{
            if($actual!==$c)self::fail();$this->inside($c,$pdo);$result=$run();$this->inside($c,$pdo);return $result;
        });$this->outside($c,$pdo);return $result;
    }
    private static function candidate(array $r):array
    {
        if((int)$r['marketplace_trade_version']!==1||(int)$r['pid']!==0)self::fail();
        return ['root_order_id'=>MarketplaceRefundEvidence::id($r['id']),'root_order_no'=>$r['order_id'],
            'uid'=>MarketplaceRefundEvidence::id($r['uid']),'mer_id'=>MarketplaceRefundEvidence::id($r['mer_id'],4294967295)];
    }
    private static function now():int {return MarketplaceRefundEvidence::id(time(),4294967295);}
    private static function fail():never {throw new AuthUnavailableException();}
}
