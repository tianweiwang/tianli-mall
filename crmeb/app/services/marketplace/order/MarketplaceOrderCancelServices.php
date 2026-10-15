<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\pay\marketplace\PaymentAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Db;
use think\facade\Log;

/** Whole unpaid, unshipped V1 cancellation. Unknown payment evidence never releases inventory. */
final class MarketplaceOrderCancelServices
{
    public function __construct(private MarketplaceOrderDao $dao) {}

    /** @return array{state:'cancelled'|'already_cancelled'|'not_due'|'pending',order_id:string} */
    public function cancelForCustomer(int $uid,string $orderNo):array
    {
        if(MarketplaceOrderInput::unsigned($uid,'顾客编号',2147483647)===0||$orderNo===''||strlen($orderNo)>32
            ||str_contains($orderNo,"\0")||preg_match('//u',$orderNo)!==1)throw new ApiException('订单参数错误');
        $pdo=self::defaultPdo();if($pdo->inTransaction())throw new AuthUnavailableException();
        $candidate=$this->dao->customerCancellationCandidate($pdo,$uid,$orderNo);
        if($candidate===null)throw new ApiException('订单不存在');
        return $this->cancel($pdo,$candidate,false,$uid);
    }

    /** System actor has no fabricated UID; saved order ownership is read from the actual database. */
    public function cancelExpired(int $orderId):array
    {
        if(MarketplaceOrderInput::unsigned($orderId,'订单编号',2147483647)===0)throw new AuthUnavailableException();
        $pdo=self::defaultPdo();if($pdo->inTransaction())throw new AuthUnavailableException();
        $candidate=$this->dao->expiryCancellationCandidate($pdo,$orderId);
        if($candidate===null)throw new AuthUnavailableException();
        return $this->cancel($pdo,$candidate,true,null);
    }

    /** Explicit saved deadline/order cursor; the caller owns its non-authoritative progress hint. */
    public function scanExpired(int $deadlineCursor=0,int $orderCursor=0,int $batch=100):array
    {
        MarketplaceOrderInput::unsigned($deadlineCursor,'到期游标');MarketplaceOrderInput::unsigned($orderCursor,'订单游标',2147483647);
        if($batch<1||$batch>100)throw new AuthUnavailableException();
        $pdo=self::defaultPdo();if($pdo->inTransaction())throw new AuthUnavailableException();
        $rows=$this->dao->expiredCancellationCandidates($pdo,$deadlineCursor,$orderCursor,time(),$batch);
        $out=['processed'=>0,'cancelled'=>0,'already_cancelled'=>0,'not_due'=>0,'pending'=>0,
            'next_deadline'=>0,'next_order_id'=>0,'wrapped'=>count($rows)<$batch];
        foreach($rows as$row){
            ++$out['processed'];
            try{$result=$this->cancelExpired($row['order_id']);++$out[$result['state']];}
            catch(\Throwable $error){++$out['pending'];self::recordPending($row['order_id']);}
            $out['next_deadline']=$row['deadline'];$out['next_order_id']=$row['order_id'];
        }
        if($out['wrapped']){$out['next_deadline']=0;$out['next_order_id']=0;}
        return $out;
    }

    private function cancel(PDO $pdo,array $candidate,bool $expired,?int $actorId):array
    {
        $orderId=$candidate['order_id'];
        try{
            $result=Db::transaction(function(ConnectionInterface $connection)use($pdo,$candidate,$expired,$actorId):array{
                self::transactionPdo($connection,$pdo);$now=time();
                $locked=$this->dao->lockCancellation($pdo,$candidate,$now,$expired);
                $state=$locked['state'];
                if($state==='ready'){
                    if(!self::closeKnownAttempts($locked['order']))$state='pending';
                    else{
                        self::transactionPdo($connection,$pdo);
                        $this->dao->releaseCancellation($pdo,$locked,$now,$expired?'system':'customer',$actorId);
                        $state='cancelled';
                    }
                }
                self::transactionPdo($connection,$pdo);
                return ['state'=>$state,'order_id'=>$locked['order']['order_id']];
            });
        }catch(\Throwable $error){$result=['state'=>'pending','order_id'=>$candidate['order_no']];}
        if($result['state']==='pending')self::recordPending($orderId);
        return $result;
    }

    /** Same-process testing evidence only. No production account fallback or empty-set success. */
    private static function closeKnownAttempts(array $order):bool
    {
        try{
            $driver=app()->make(PaymentAdapter::class);
            if(!$driver instanceof TestPaymentDriver)return false;
            $located=$driver->attemptsForOrder($order);$attempts=$located['attempts']??null;
            if(($located['state']??null)!=='known'||($located['scope']??null)!=='testing_process_only'
                ||!is_array($attempts)||!array_is_list($attempts)||!$attempts)return false;
            $seen=[];
            foreach($attempts as$attempt){
                if(!is_array($attempt)||!self::receipt($attempt,$order))return false;
                $ref=$attempt['attempt_ref'];if(isset($seen[$ref]))return false;$seen[$ref]=true;
                if(!self::receipt($driver->queryPayment($attempt),$order,$attempt))return false;
                $closed=$driver->closePayment($attempt);
                if(!self::receipt($closed,$order,$attempt)||$closed['state']!=='closed')return false;
                $queried=$driver->queryPayment($attempt);
                if(!self::receipt($queried,$order,$attempt)||$queried['state']!=='closed')return false;
            }
            return true;
        }catch(\Throwable $error){return false;}
    }

    private static function receipt(array $receipt,array $order,?array $original=null):bool
    {
        $binding=$receipt['binding']??null;$hash=$receipt['binding_sha256']??null;$ref=$receipt['attempt_ref']??null;
        $keys=['order_pk','order_no','uid','mer_id','amount','currency','channel','account_source','account_id','account_ref','account_version'];
        if(($receipt['scope']??null)!=='testing_process_only'||!in_array($receipt['state']??null,['pending','closed'],true)
            ||($receipt['provider_trade_no']??null)!==null||!is_string($ref)||preg_match('/\Atest-attempt:[a-f0-9]{32}\z/D',$ref)!==1
            ||!is_array($binding)||count($binding)!==count($keys)||array_diff(array_keys($binding),$keys)
            ||!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1
            ||!hash_equals($hash,hash('sha256',MarketplaceOrderInput::canonical($binding))))return false;
        if($binding['order_pk']!==MarketplaceOrderInput::unsigned($order['id'],'订单编号',2147483647)
            ||$binding['uid']!==MarketplaceOrderInput::unsigned($order['uid'],'顾客编号',2147483647)
            ||$binding['mer_id']!==MarketplaceOrderInput::unsigned($order['mer_id'],'商家编号')
            ||$binding['order_no']!==$order['order_id']||$binding['amount']!==$order['pay_price']
            ||$binding['currency']!=='CNY'||$binding['channel']!=='test'||$binding['account_source']!=='test_config'
            ||$binding['account_id']!==null||!is_string($binding['account_ref'])||!str_starts_with($binding['account_ref'],'test:')
            ||!is_int($binding['account_version'])||$binding['account_version']<1)return false;
        return $original===null||($ref===$original['attempt_ref']&&$binding===$original['binding']
            &&hash_equals($hash,$original['binding_sha256']));
    }

    public static function recordPending(int $orderId):void
    {
        try{Log::error('MARKETPLACE_CANCEL_PENDING',['order_pk'=>$orderId]);}catch(\Throwable $ignored){}
    }

    private static function defaultPdo():PDO
    {
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new AuthUnavailableException();
        $emulate=$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if($emulate!==false&&$emulate!==0)throw new AuthUnavailableException();return $pdo;
    }

    private static function transactionPdo(ConnectionInterface $connection,PDO $pdo):void
    {
        if($connection!==Db::connect()||$connection->getPdo()!==$pdo||self::defaultPdo()!==$pdo||!$pdo->inTransaction())throw new AuthUnavailableException();
    }
}
