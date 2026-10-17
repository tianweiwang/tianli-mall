<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\services\marketplace\payment\MarketplacePaymentServices;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Db;
use think\facade\Log;

/** Whole unpaid, unshipped V1 cancellation. Unknown payment evidence never releases inventory. */
final class MarketplaceOrderCancelServices
{
    public function __construct(private MarketplaceOrderDao $dao, private MarketplacePaymentServices $payments) {}

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
            // The original complete payment snapshot is captured before stock
            // locks, and is never replaced by the collection observed after close.
            $initial=Db::transaction(function(ConnectionInterface $connection)use($pdo,$candidate,$expired):array{
                self::transactionPdo($connection,$pdo);
                $locked=$this->dao->lockCancellation($pdo,$candidate,time(),$expired);
                self::transactionPdo($connection,$pdo);return $locked;
            });
            if(self::defaultPdo()!==$pdo||$pdo->inTransaction())throw new AuthUnavailableException();
            $locked=$initial;
            if($initial['payment_stage']==='close'){
                $proof=$this->payments->closeForCancellation($initial['candidate']['uid'],
                    $initial['order']['order_id'],$initial['payment']);
                if(self::defaultPdo()!==$pdo||$pdo->inTransaction())throw new AuthUnavailableException();
                if($proof!==null){
                    $locked=Db::transaction(function(ConnectionInterface $connection)use($pdo,$candidate,$expired,$actorId,$proof):array{
                        self::transactionPdo($connection,$pdo);$now=time();
                        // First write lock validates all proof facts before stock.
                        $current=$this->dao->lockCancellation($pdo,$candidate,$now,$expired,$proof);
                        if($current['state']==='ready'){
                            self::transactionPdo($connection,$pdo);
                            // The DAO independently takes the second proof lock.
                            $current=$this->dao->releaseCancellation($pdo,$current,$now,
                                $expired?'system':'customer',$actorId,$proof);
                        }
                        self::transactionPdo($connection,$pdo);return $current;
                    });
                }
            }
            if(self::defaultPdo()!==$pdo||$pdo->inTransaction())throw new AuthUnavailableException();
            if(!in_array($locked['state'],['cancelled','already_cancelled','not_due','pending'],true))throw new AuthUnavailableException();
            $result=['state'=>$locked['state'],'order_id'=>$locked['order']['order_id']];
        }catch(\Throwable $error){$result=['state'=>'pending','order_id'=>$candidate['order_no']];}
        if($result['state']==='pending')self::recordPending($orderId);
        return $result;
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
