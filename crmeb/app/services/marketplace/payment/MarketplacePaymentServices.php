<?php
declare(strict_types=1);

namespace app\services\marketplace\payment;

use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\dao\marketplace\order\MarketplaceOrderRefundDao;
use crmeb\compat\think\orm\db\connector\Mysql;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\pay\marketplace\PaymentAccount;
use crmeb\services\pay\marketplace\PaymentAccountResolver;
use crmeb\services\pay\marketplace\PaymentAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use PDO;
use think\App;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Testing-only durable admission. Saved intent can never manufacture another driver create opportunity. */
final class MarketplacePaymentServices
{
    private readonly App $app;
    private ?array $createContext=null;

    public function __construct(private MarketplacePaymentDao $dao,private PaymentAccountResolver $accounts,private MarketplaceOrderDao $orders,
        private MarketplaceOrderFulfillmentDao $fulfillments,private MarketplaceOrderRefundDao $refunds)
    {
        $this->app=app();
    }

    /** The authenticated caller supplies identity and a payment key, never an order/account/amount proposal. */
    public function createForCustomer(int $uid,string $orderNo,string $paymentIdem): array
    {
        $this->scope();
        if($this->createContext!==null||$uid<1||$uid>2147483647||$orderNo===''||strlen($orderNo)>32
            ||trim($orderNo)!==$orderNo||str_contains($orderNo,"\0")||preg_match('//u',$orderNo)!==1
            ||strlen($paymentIdem)>128||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D',$paymentIdem)!==1)self::unavailable();
        [$connection,$pdo]=$this->defaultConnection();
        $driver=$this->app->make(PaymentAdapter::class);
        if(!$driver instanceof TestPaymentDriver)self::unavailable();
        $candidate=$this->dao->customerPaymentCandidate($pdo,$uid,$orderNo);$orderId=$candidate['order_id'];
        $first=$this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo):array{
            return $this->dao->lockPayment($pdo,$uid,$orderId,$orderNo);
        });
        $existing=self::byKey($first,$paymentIdem);
        if($existing!==null)return self::dto($first,$existing['id']);

        // The current account is merely an outside-transaction candidate. A failed candidate must still recheck the same key.
        $account=null;$candidateError=null;
        try{$account=$this->accounts->forOrder($first['root']);}
        catch(\Throwable $error){$candidateError=$error;}
        $admission=$this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$paymentIdem,$account,$candidateError):array{
            $locked=$this->dao->lockPayment($pdo,$uid,$orderId,$orderNo);$existing=self::byKey($locked,$paymentIdem);
            if($existing!==null)return ['new'=>false,'locked'=>$locked,'attempt_id'=>$existing['id']];
            if($candidateError!==null)throw $candidateError;
            if(!$account instanceof PaymentAccount)self::unavailable();
            return $this->dao->admit($pdo,$uid,$orderId,$orderNo,$paymentIdem,$account->snapshot());
        });
        if($admission['new']!==true)return self::dto($admission['locked'],$admission['attempt_id']);

        // transaction() has confirmed this same default Connection/PDO really left its outer native transaction.
        // The capability checked before any intent must still be the exact original factory instance.
        $this->outside($connection,$pdo);
        if($this->app->make(PaymentAdapter::class)!==$driver||!$account instanceof PaymentAccount)self::unavailable();
        $locked=$admission['locked'];$row=self::byId($locked,$admission['attempt_id']);
        $request=self::request($locked['root'],$account,$paymentIdem);
        if(!hash_equals($row['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($request),true))
            ||MarketplaceOrderInput::canonical($row['account_snapshot'])!==MarketplaceOrderInput::canonical($account->snapshot()))self::unavailable();
        $this->createContext=['app'=>$this->app,'driver'=>$driver,'connection'=>$connection,'pdo'=>$pdo,'account'=>$account,
            'account_snapshot'=>$account->snapshot(),'request'=>$request,'locked'=>$locked,'attempt'=>$row];
        try{
            // The exact driver consumes and removes the private context before even its in-memory idem branch.
            $result=$driver->createPayment($locked['root'],$account,$paymentIdem);
        }finally{
            $this->createContext=null;
        }
        try{
            $saved=$this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$row,$result):array{
                return $this->dao->saveCreateResult($pdo,$uid,$orderId,$orderNo,$row['id'],$row,$result,self::now());
            });
        }catch(\Throwable $error){
            $reason=self::evidenceFailure($error);
            if($reason!==null){
                // The observation transaction has rolled back. This is a distinct bounded hold transaction, not a saved fact or ACK.
                $this->outside($connection,$pdo);
                $this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$row,$reason):array{
                    return $this->dao->holdEvidenceFailure($pdo,$uid,$orderId,$orderNo,$row['id'],$row,$reason,self::now());
                });
                self::unavailable();
            }
            throw $error;
        }
        return self::dto($saved,$row['id']);
    }

    /** Consume-only driver gate: no public grant, fresh flag, reconstructed intent or caller-provided context. */
    public function consumeCreateContext(PaymentAdapter $driver,array $actualOrder,PaymentAccount $account,string $idem): void
    {
        $context=$this->createContext;$this->createContext=null;
        $this->scope();
        if($context===null||$context['app']!==$this->app||$context['driver']!==$driver
            ||$this->app->make(PaymentAdapter::class)!==$driver||$context['account']!==$account
            ||$context['account_snapshot']!==$account->snapshot()||$actualOrder!==$context['locked']['root']
            ||$idem!==$context['attempt']['idem_key'])self::unavailable();
        $connection=$context['connection'];$pdo=$context['pdo'];$this->outside($connection,$pdo);
        $request=self::request($actualOrder,$account,$idem);
        if($request!==$context['request']
            ||!hash_equals($context['attempt']['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($request),true)))self::unavailable();
        $original=$context['locked'];
        // An actual post-commit lock/read verifies the original full intent. Reading it grants no new opportunity.
        $committed=$this->transaction($connection,$pdo,function()use($pdo,$actualOrder):array{
            return $this->dao->lockPayment($pdo,$actualOrder['uid'],$actualOrder['id'],$actualOrder['order_id']);
        });
        // Neither completion bookkeeping nor the fulfillment-only switch is an extra payment-admission prerequisite.
        // Their actual types/identities were checked by lockPayment; all intent, root and generation facts remain exact.
        $original['ledger']['post_commit_done']=$committed['ledger']['post_commit_done'];
        $original['merchant']['fulfillment_enabled']=$committed['merchant']['fulfillment_enabled'];
        if($committed!==$original||self::byId($committed,$context['attempt']['id'])!==$context['attempt'])self::unavailable();
        $this->outside($connection,$pdo);
    }

    /** A real owner-scoped query, never a retry of create or a lookup in a newly fabricated driver map. */
    public function queryForCustomer(int $uid,string $orderNo,string $attemptId): array
    {
        $this->scope();self::customerIdentity($uid,$orderNo);$attemptId=self::attemptId($attemptId);
        [$connection,$pdo]=$this->defaultConnection();$driver=$this->app->make(PaymentAdapter::class);
        if(!$driver instanceof TestPaymentDriver)self::unavailable();
        $candidate=$this->dao->customerPaymentCandidate($pdo,$uid,$orderNo);
        $observed=$this->observeCall($connection,$pdo,$driver,$uid,$candidate['order_id'],$orderNo,$attemptId,'queryPayment',null);
        if(!$observed['root_payment_consistent'])self::unavailable();
        return self::dto($observed['locked'],$attemptId);
    }

    /** Internal cancellation only. The original actual snapshot is kept throughout; this returns saved fact references or NULL. */
    public function closeForCancellation(int $uid,string $orderNo,array $initialPayment): ?array
    {
        $this->scope();self::customerIdentity($uid,$orderNo);[$connection,$pdo]=$this->defaultConnection();
        $candidate=$this->dao->customerPaymentCandidate($pdo,$uid,$orderNo);$orderId=$candidate['order_id'];
        $start=$this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$initialPayment):array{
            $proof=$this->dao->closedCancellationProof($pdo,$uid,$orderId,$orderNo,$initialPayment);
            return ['proof'=>$proof,'observation'=>$this->dao->lockObservation($pdo,$uid,$orderId,$orderNo)];
        });
        if($start['proof']!==null)return $start['proof']; // Includes actual born G0/empty collection, without a driver call.
        if($start['observation']['locked']['attempts']===[])return null;
        $driver=$this->app->make(PaymentAdapter::class);if(!$driver instanceof TestPaymentDriver)self::unavailable();
        foreach($initialPayment['attempts'] as $row){
            $attemptId=self::attemptId($row['id']);
            foreach(['queryPayment','closePayment','queryPayment'] as $op){
                $observed=$this->observeCall($connection,$pdo,$driver,$uid,$orderId,$orderNo,$attemptId,$op,$initialPayment);
                if(!$observed['root_payment_consistent'])self::unavailable();$locked=$observed['locked'];
                if($locked['registry']['winner_attempt_id']!==null||$locked['registry']['fulfillment_hold']!==0)return null;
                foreach($locked['attempts'] as $saved)
                    if($saved['evidence']['first_succeeded']!==null||$saved['evidence']['application']!==null)return null;
            }
        }
        return $this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$initialPayment):?array{
            return $this->dao->closedCancellationProof($pdo,$uid,$orderId,$orderNo,$initialPayment);
        });
    }

    /** One actual finite adapter operation, with a distinct pre-read, outside call, and saved/read-back transaction. */
    private function observeCall(ConnectionInterface $connection,PDO $pdo,PaymentAdapter $driver,int $uid,int $orderId,
        string $orderNo,string $attemptId,string $op,?array $initialPayment): array
    {
        if(!in_array($op,['queryPayment','closePayment'],true))self::unavailable();
        $observed=$this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$initialPayment):array{
            if($initialPayment!==null)$this->dao->closedCancellationProof($pdo,$uid,$orderId,$orderNo,$initialPayment);
            return $this->dao->lockObservation($pdo,$uid,$orderId,$orderNo);
        });
        $row=self::byId($observed['locked'],$attemptId);$descriptor=self::descriptor($row);
        if($descriptor===null){
            if(!$observed['root_payment_consistent'])self::unavailable();return $observed;
        }
        $this->outside($connection,$pdo);
        if($this->app->make(PaymentAdapter::class)!==$driver||!$driver instanceof TestPaymentDriver)self::unavailable();
        $result=$op==='queryPayment'?$driver->queryPayment($descriptor):$driver->closePayment($descriptor);
        $this->outside($connection,$pdo);if($this->app->make(PaymentAdapter::class)!==$driver)self::unavailable();
        try{
            $saved=$this->transaction($connection,$pdo,function()use($connection,$pdo,$uid,$orderId,$orderNo,$attemptId,$row,$op,$result,$initialPayment):array{
                if($initialPayment!==null)$this->dao->closedCancellationProof($pdo,$uid,$orderId,$orderNo,$initialPayment);
                $initial=$this->dao->lockObservation($pdo,$uid,$orderId,$orderNo);
                if(($result['state']??null)!=='succeeded')
                    return $this->dao->saveObservation($pdo,$uid,$orderId,$orderNo,$attemptId,$row,$op,$result,self::now(),null,null);
                $before=$this->captureApplicationContext($connection,$pdo,$uid,$orderId,$orderNo,$initial);
                $saved=$this->dao->saveObservation($pdo,$uid,$orderId,$orderNo,$attemptId,$row,$op,$result,self::now(),
                    $before['capture'],$before['context']);
                return $this->finalApplicationReadback($connection,$pdo,$uid,$orderId,$orderNo,$before,$saved);
            });
        }catch(\Throwable $error){
            if($error instanceof \DomainException&&$error->getMessage()==='APPLIED_TRADE_CONFLICT'){
                if($op!=='queryPayment')self::unavailable();
                // The failed application transaction has actually rolled back before the same PDO is reused.
                $this->outside($connection,$pdo);
                try{
                    $saved=$this->transaction($connection,$pdo,function()use($connection,$pdo,$uid,$orderId,$orderNo,$attemptId,$row,$result,$initialPayment):array{
                        if($initialPayment!==null)$this->dao->closedCancellationProof($pdo,$uid,$orderId,$orderNo,$initialPayment);
                        $initial=$this->dao->lockObservation($pdo,$uid,$orderId,$orderNo);
                        $before=$this->captureApplicationContext($connection,$pdo,$uid,$orderId,$orderNo,$initial);
                        $saved=$this->dao->saveAppliedTradeConflict($pdo,$uid,$orderId,$orderNo,$attemptId,$row,$result,self::now(),
                            $initialPayment,$before['capture'],$before['context']);
                        return $this->finalApplicationReadback($connection,$pdo,$uid,$orderId,$orderNo,$before,$saved);
                    });
                }catch(\Throwable $conflictError){
                    $this->holdUnsavedObservation($connection,$pdo,$uid,$orderId,$orderNo,$attemptId,$row,$initialPayment,$conflictError);
                    throw $conflictError;
                }
            }else{
                $this->holdUnsavedObservation($connection,$pdo,$uid,$orderId,$orderNo,$attemptId,$row,$initialPayment,$error);
                throw $error;
            }
        }
        // Inconsistent root values were not repaired or disguised. The actual observation/hold may be saved, but no stable DTO is claimed.
        if(!$saved['root_payment_consistent'])self::unavailable();return $saved;
    }

    /** Actual fixed-PDO source capture after an actual payment lock; no current paid/status admission is added here. */
    private function captureApplicationContext(ConnectionInterface $connection,PDO $pdo,int $uid,int $orderId,
        string $orderNo,array $observation):array
    {
        $this->connection($connection,$pdo);
        if($connection->transactionDepth()!==1||!$pdo->inTransaction()
            ||array_keys($observation)!==['locked','root_payment_consistent']||!is_bool($observation['root_payment_consistent'])
            ||!is_array($observation['locked']))self::unavailable();
        $payment=$observation['locked'];
        if(array_keys($payment)!==['ledger','root','merchant','registry','attempts'])self::unavailable();
        foreach($payment as$value)if(!is_array($value))self::unavailable();
        if(($payment['root']['id']??null)!==$orderId||($payment['root']['uid']??null)!==$uid
            ||($payment['root']['order_id']??null)!==$orderNo)self::unavailable();
        $candidate=['root_order_id'=>$orderId,'root_order_no'=>$orderNo,'uid'=>$uid,
            'mer_id'=>MarketplaceOrderFulfillmentDao::integer($payment['root']['mer_id']??null,1,4294967295)];
        $root=$this->fulfillments->lockRoot($pdo,$candidate);
        $operations=$this->refunds->lockOperationsForRoot($connection,$pdo,$root);
        $capture=$this->orders->capturePaymentApplicationFacts($pdo,$uid,$orderId);
        if(($capture['root']??null)!==$root)self::unavailable();
        // The Task7 S2 lockFamily producer includes both actual order ownership and stored root bindings,
        // retaining complete legacy eight-NULL rows instead of invoking the new-refund-only parser.
        $family=$this->fulfillments->lockFamily($pdo,$root);
        if(array_keys($family)!==['children','items','ranges','refunds'])self::unavailable();
        foreach($family as$rows)if(!is_array($rows)||!array_is_list($rows))self::unavailable();
        $logs=$this->refunds->lockStatusLogsForRoot($connection,$pdo,$root);
        $actions=$this->refunds->lockActionsForRoot($connection,$pdo,$root);
        $cut=null;
        if($actions!==[]){
            $cut=$actions[count($actions)-1]['id'];
            if(is_int($cut)&&$cut>0)$cut=(string)$cut;
            if(!is_string($cut))self::unavailable();$cut=self::attemptId($cut);
        }
        $context=['schema'=>2,'cut_id'=>$cut,'actions'=>$actions,'refunds'=>$family['refunds'],
            'operations'=>$operations,'ranges'=>$family['ranges'],'logs'=>$logs];
        $this->connection($connection,$pdo);
        if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::unavailable();
        return ['observation'=>$observation,'root'=>$root,'capture'=>$capture,'family'=>$family,'context'=>$context];
    }

    /** Both application consumers finish the complete final chain before their owned transaction may commit. */
    private function finalApplicationReadback(ConnectionInterface $connection,PDO $pdo,int $uid,int $orderId,
        string $orderNo,array $before,array $saved):array
    {
        // saved is the DAO's just-fetched native payment observation, not a service proposal.
        // Immediately follow that finalPayment with entirely new Root/Operations/Capture/Family/Logs/Actions.
        $final=$this->captureApplicationContext($connection,$pdo,$uid,$orderId,$orderNo,$saved);
        if(array_keys($before)!==['observation','root','capture','family','context']
            ||$before['context']!==$final['context']||$before['family']!==$final['family'])self::unavailable();
        foreach(['items','selection','reserves','credit_rows','child_orders'] as$key)
            if($before['capture'][$key]!==$final['capture'][$key])self::unavailable();
        $priorRoot=$before['root'];$finalRoot=$final['root'];
        if(array_keys($priorRoot)!==array_keys($finalRoot))self::unavailable();
        $priorPayment=$before['observation']['locked'];$finalPayment=$saved['locked'];
        foreach(['ledger','merchant'] as$key)if($priorPayment[$key]!==$finalPayment[$key])self::unavailable();
        $previousWinner=$priorPayment['registry']['winner_attempt_id'];$winner=$finalPayment['registry']['winner_attempt_id'];
        $firstApplication=$previousWinner===null&&$winner!==null;
        if(!$firstApplication){
            if($winner!==$previousWinner||$priorRoot!==$finalRoot)self::unavailable();
        }else{
            // Only the DAO's real first application can account for these four actual saved values.
            $attempt=self::byId($finalPayment,$winner);$application=$attempt['evidence']['application']??null;
            if(!is_array($application)||($application['attempt_id']??null)!==$winner
                ||($application['order_pk']??null)!==$orderId||!is_array($application['root_payment']??null))self::unavailable();
            $paymentValues=$application['root_payment'];
            $keys=array_keys($paymentValues);sort($keys,SORT_STRING);
            if($keys!==['paid','pay_time','pay_type','trade_no'])self::unavailable();
            foreach($priorRoot as$key=>$value){
                if(!in_array($key,['paid','pay_time','pay_type','trade_no'],true)&&$finalRoot[$key]!==$value)self::unavailable();
            }
            foreach($paymentValues as$key=>$value){
                if($finalPayment['root'][$key]!==$value)self::unavailable();
                $actual=$finalRoot[$key];
                if($key==='paid'||$key==='pay_time'){
                    if(MarketplaceOrderFulfillmentDao::integer($actual,0,$key==='paid'?1:4294967295)!==$value)self::unavailable();
                }elseif($actual!==$value)self::unavailable();
            }
        }
        $initialState=MarketplacePaymentDao::applicationStateV2($before['capture'],$priorPayment,$before['context']);
        $finalState=MarketplacePaymentDao::applicationStateV2($final['capture'],$finalPayment,$final['context']);
        if($finalState!==$initialState)self::unavailable();
        $this->connection($connection,$pdo);
        if($connection->transactionDepth()!==1||!$pdo->inTransaction())self::unavailable();
        return $saved;
    }

    /** Unsavable evidence never becomes an ACK; retain only the bounded first hold in a fresh actual transaction. */
    private function holdUnsavedObservation(ConnectionInterface $connection,PDO $pdo,int $uid,int $orderId,string $orderNo,
        string $attemptId,array $original,?array $initialPayment,\Throwable $error): void
    {
        $reason=self::evidenceFailure($error);if($reason===null)return;
        $this->outside($connection,$pdo);
        $this->transaction($connection,$pdo,function()use($pdo,$uid,$orderId,$orderNo,$attemptId,$original,$initialPayment,$reason):array{
            if($initialPayment!==null)$this->dao->closedCancellationProof($pdo,$uid,$orderId,$orderNo,$initialPayment);
            return $this->dao->holdObservationFailure($pdo,$uid,$orderId,$orderNo,$attemptId,$original,$reason,self::now());
        });
        self::unavailable();
    }

    private static function customerIdentity(int $uid,string $orderNo): void
    {
        if($uid<1||$uid>2147483647||$orderNo===''||strlen($orderNo)>32||trim($orderNo)!==$orderNo
            ||str_contains($orderNo,"\0")||preg_match('//u',$orderNo)!==1)self::unavailable();
    }

    private static function attemptId(string $value): string
    {
        if(preg_match('/\A[1-9][0-9]*\z/D',$value)!==1||strlen($value)>20||bccomp($value,'18446744073709551615',0)>0)self::unavailable();
        return $value;
    }

    /** Project only the verified original saved create descriptor, preserving the driver's strict binding array order. */
    private static function descriptor(array $row): ?array
    {
        $create=null;
        foreach($row['evidence']['observations'] as $observation)if($observation['fact']['op']==='createPayment'){
            if($create!==null)self::unavailable();$create=$observation['fact']['result'];
        }
        if($create===null||$create['state']==='unknown'||$row['attempt_ref']===null||$create['attempt_ref']!==$row['attempt_ref'])return null;
        $account=$row['account_snapshot'];
        $expected=['order_pk'=>$row['order_id'],'order_no'=>$row['order_no'],'uid'=>$row['uid'],'mer_id'=>$row['mer_id'],
            'amount'=>$row['amount'],'currency'=>$row['currency'],'channel'=>$account['channel'],'account_source'=>$account['source'],
            'account_id'=>$account['account_id'],'account_ref'=>$account['account_ref'],'account_version'=>$account['version']];
        if($create['binding_sha256']!==bin2hex($row['binding_digest'])
            ||MarketplaceOrderInput::canonical($create['binding'])!==MarketplaceOrderInput::canonical($expected))return null;
        $binding=[];foreach(array_keys($expected) as $field)$binding[$field]=$create['binding'][$field];
        return ['state'=>$create['state'],'scope'=>$create['scope'],'attempt_ref'=>$create['attempt_ref'],
            'binding_sha256'=>$create['binding_sha256'],'binding'=>$binding,'provider_trade_no'=>$create['provider_trade_no']];
    }

    private static function byKey(array $locked,string $idem): ?array
    {
        foreach($locked['attempts'] as $row)if($row['idem_key']===$idem)return $row;
        return null;
    }

    private static function byId(array $locked,string $attemptId): array
    {
        foreach($locked['attempts'] as $row)if($row['id']===$attemptId)return $row;
        self::unavailable();
    }

    private static function dto(array $locked,string $attemptId): array
    {
        $row=self::byId($locked,$attemptId);$evidence=$row['evidence'];$create=null;
        foreach($evidence['observations'] as $observation)if($observation['fact']['op']==='createPayment'){
            if($create!==null)self::unavailable();$create=$observation['fact']['result'];
        }
        if($create!==null){
            $binding=$create['binding'];
            if($binding!==null){
                $ordered=[];
                foreach(['order_pk','order_no','uid','mer_id','amount','currency','channel','account_source','account_id','account_ref','account_version'] as $field)
                    $ordered[$field]=$binding[$field];
                $binding=$ordered;
            }
            $create=['state'=>$create['state'],'scope'=>$create['scope'],'attempt_ref'=>$create['attempt_ref'],
                'binding_sha256'=>$create['binding_sha256'],'binding'=>$binding,'provider_trade_no'=>$create['provider_trade_no']];
        }
        $state=$evidence['application']!==null?'applied':($evidence['first_succeeded']!==null?'succeeded_observed':
            ($evidence['first_closed']!==null?'closed':(($create['state']??null)==='pending'&&self::descriptor($row)!==null?'pending':'unresolved')));
        return ['schema'=>1,'scope'=>'testing_persisted_payment_v1','order_pk'=>$locked['root']['id'],
            'order_no'=>$row['order_no'],'attempt_id'=>$row['id'],'admitted_generation'=>$row['admitted_generation'],
            'account_snapshot'=>$row['account_snapshot'],'state'=>$state,'create_result'=>$create];
    }

    private static function request(array $root,PaymentAccount $account,string $idem): array
    {
        return ['binding'=>['order_pk'=>$root['id'],'order_no'=>$root['order_id'],'uid'=>$root['uid'],'mer_id'=>$root['mer_id'],
            'amount'=>$root['pay_price'],'currency'=>$account->currency,'channel'=>$account->channel,'account_source'=>$account->source,
            'account_id'=>$account->account_id,'account_ref'=>$account->account_ref,'account_version'=>$account->version],
            'idempotency_key'=>$idem];
    }

    private function scope(): void
    {
        PaymentAccount::assertTesting();
        if(app()!==$this->app||$this->app->make(self::class)!==$this||$this->app->make(MarketplacePaymentDao::class)!==$this->dao
            ||$this->app->make(PaymentAccountResolver::class)!==$this->accounts||$this->app->make(MarketplaceOrderDao::class)!==$this->orders
            ||$this->app->make(MarketplaceOrderFulfillmentDao::class)!==$this->fulfillments
            ||$this->app->make(MarketplaceOrderRefundDao::class)!==$this->refunds)self::unavailable();
    }

    /** Establish the real default primary PDO once; there is no second connection or native transaction fallback. */
    private function defaultConnection(): array
    {
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO)self::unavailable();$this->outside($connection,$pdo);
        $this->fulfillments->assertAftersaleStructure($pdo);$this->outside($connection,$pdo);return [$connection,$pdo];
    }

    private function outside(ConnectionInterface $connection,PDO $pdo): void
    {
        $this->connection($connection,$pdo);if($connection->transactionDepth()!==0||$pdo->inTransaction())self::unavailable();
    }

    private function connection(ConnectionInterface $connection,PDO $pdo): void
    {
        $this->scope();
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::unavailable();
    }

    private function transaction(ConnectionInterface $connection,PDO $pdo,callable $action): mixed
    {
        $this->outside($connection,$pdo);
        $result=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$action):mixed{
            if($actual!==$connection)self::unavailable();$this->connection($actual,$pdo);
            if($actual->transactionDepth()!==1||!$pdo->inTransaction())self::unavailable();$result=$action();
            $this->connection($actual,$pdo);if($actual->transactionDepth()!==1||!$pdo->inTransaction())self::unavailable();return $result;
        });
        $this->outside($connection,$pdo);return $result;
    }

    private static function now(): int
    {
        $now=time();if($now<1||$now>4294967295)self::unavailable();return $now;
    }

    private static function evidenceFailure(\Throwable $error): ?string
    {
        if($error instanceof \LengthException&&$error->getMessage()==='EVIDENCE_CAPACITY')return 'EVIDENCE_CAPACITY';
        if($error instanceof \DomainException&&$error->getMessage()==='FACT_KEY_COLLISION')return 'FACT_KEY_COLLISION';
        return null;
    }

    private static function unavailable(): never
    {
        throw new AuthUnavailableException();
    }
}
