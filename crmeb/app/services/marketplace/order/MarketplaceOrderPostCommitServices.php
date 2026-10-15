<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\services\marketplace\PublicCatalogServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/** Durable internal V1 creation effects only. No old listener, external delivery or inventory write. */
final class MarketplaceOrderPostCommitServices
{
    private const INT_MAX=2147483647;

    public function __construct(private MarketplaceOrderDao $dao) {}

    /**
     * Actual saved order PK only; its real UID is read before the new top-level transaction.
     * An existing outer transaction remains pending, never an early completion acknowledgement.
     * @return array{post_commit_done:0|1,completed_now:bool}
     */
    public function finish(int $orderId):array
    {
        if(MarketplaceOrderInput::unsigned($orderId,'订单编号',self::INT_MAX)===0)throw new AuthUnavailableException();
        $pdo=self::defaultPdo();
        if($pdo->inTransaction())return ['post_commit_done'=>0,'completed_now'=>false];
        $candidate=$this->dao->postCommitCandidate($pdo,$orderId);
        if($candidate===null||$candidate['order_id']!==$orderId)throw new AuthUnavailableException();
        $uid=$candidate['uid'];
        return Db::transaction(function(ConnectionInterface $connection)use($pdo,$uid,$orderId):array{
            self::transactionPdo($connection,$pdo);
            $locked=$this->dao->lockPostCommit($pdo,$uid,$orderId);
            if($locked['post_commit_done']===1)return ['post_commit_done'=>1,'completed_now'=>false];
            self::clearConfirmation($uid,$locked);
            if($locked['ledger']['source_kind']==='temporary')foreach($locked['selection'] as$line){
                self::clearTemporary($uid,$line);
            }
            self::transactionPdo($connection,$pdo);
            $result=$this->dao->completePostCommit($pdo,$uid,$locked['ledger']['idem_key'],$orderId);
            self::transactionPdo($connection,$pdo);
            return $result;
        });
    }

    /**
     * A deterministic bounded page. The timer owns its non-authoritative cross-tick cursor hint.
     * Each candidate has its own finish transaction; one pending order never blocks later IDs.
     * @return array{processed:int,completed:int,already_done:int,pending:int,next_cursor:int,wrapped:bool}
     */
    public function scanPending(int $cursor=0,int $batch=100):array
    {
        MarketplaceOrderInput::unsigned($cursor,'扫描游标',self::INT_MAX);
        if($batch<1||$batch>100)throw new AuthUnavailableException();
        $pdo=self::defaultPdo();if($pdo->inTransaction())throw new AuthUnavailableException();
        $ids=$this->dao->pendingPostCommits($pdo,$cursor,$batch);
        $result=['processed'=>0,'completed'=>0,'already_done'=>0,'pending'=>0,'next_cursor'=>0,'wrapped'=>count($ids)<$batch];
        foreach($ids as$orderId){
            ++$result['processed'];
            try{
                $finished=$this->finish($orderId);
                if($finished['post_commit_done']!==1){++$result['pending'];self::recordPending($orderId);}
                elseif($finished['completed_now'])++$result['completed'];
                else ++$result['already_done'];
            }catch(\Throwable $error){++$result['pending'];self::recordPending($orderId);}
            $result['next_cursor']=$orderId;
        }
        if($result['wrapped'])$result['next_cursor']=0;
        return $result;
    }

    /** Never let the log backend change the already-committed creation response. No raw exception data. */
    public static function recordPending(int $orderId):void
    {
        try{Log::error('MARKETPLACE_POST_COMMIT_PENDING',['order_pk'=>$orderId]);}
        catch(\Throwable $ignored){}
    }

    private static function clearConfirmation(int $uid,array $locked):void
    {
        $ledger=$locked['ledger'];$order=$locked['order'];
        $key='user_order_'.$uid.$order['unique'];
        self::clearOwnedCache($key,static function(mixed $payload)use($uid,$ledger,$locked):void{
            if(!is_array($payload))throw new AuthUnavailableException();
            $frame=MarketplaceCartSelection::frame($payload,$uid);
            $refs=array_column($locked['selection'],'cart_ref');
            if($frame['mer_id']!==(int)$ledger['mer_id']||$frame['source_kind']!==$ledger['source_kind']
                ||$frame['cart_refs']!==$refs)throw new AuthUnavailableException();
            $expected=[];
            foreach($locked['selection'] as$line)$expected[]=['cart_ref'=>$line['cart_ref'],'product_id'=>$line['product_id'],
                'sku_id'=>$line['sku_id'],'sku_type'=>0,'unique'=>$line['unique'],'qty'=>$line['qty']];
            if($frame['selection']!==$expected)throw new AuthUnavailableException();
        });
    }

    private static function clearTemporary(int $uid,array $line):void
    {
        self::clearOwnedCache($line['cart_ref'],static function(mixed $payload)use($uid,$line):void{
            if(!is_array($payload)||($payload['catalog_cart_version']??null)!==PublicCatalogServices::TEMP_CART_VERSION
                ||($payload['uid']??null)!==$uid||!is_array($payload['cartData']??null))throw new AuthUnavailableException();
            $cart=$payload['cartData'];$sku=$cart['attrInfo']??null;
            if(($cart['id']??null)!==$line['cart_ref']||($cart['uid']??null)!==$uid
                ||MarketplaceOrderInput::unsigned($cart['product_id']??null,'商品编号',8388607)!==$line['product_id']
                ||MarketplaceOrderInput::unsigned($cart['cart_num']??null,'商品数量',self::INT_MAX)!==$line['qty']
                ||($cart['product_attr_unique']??null)!==$line['unique']||!is_array($sku)
                ||MarketplaceOrderInput::unsigned($cart['type']??null,'商品类型',0)!==0
                ||MarketplaceOrderInput::unsigned($sku['id']??null,'规格编号',self::INT_MAX)!==$line['sku_id']
                ||MarketplaceOrderInput::unsigned($sku['product_id']??null,'商品编号',8388607)!==$line['product_id']
                ||MarketplaceOrderInput::unsigned($sku['type']??null,'规格类型',0)!==0
                ||($sku['unique']??null)!==$line['unique'])throw new AuthUnavailableException();
        });
    }

    /** Missing/expired is already complete. A false delete alone is neither failure nor proof. */
    private static function clearOwnedCache(string $key,callable $validate):void
    {
        if(!Cache::has($key))return;
        $missing=new \stdClass();$payload=CacheService::get($key,$missing);
        if($payload===$missing){
            if(!Cache::has($key))return;
            throw new AuthUnavailableException();
        }
        $validate($payload);
        CacheService::delete($key);
        if(Cache::has($key))throw new AuthUnavailableException();
    }

    private static function defaultPdo():PDO
    {
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)throw new AuthUnavailableException();
        $emulate=$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if($emulate!==false&&$emulate!==0)throw new AuthUnavailableException();
        return $pdo;
    }

    private static function transactionPdo(ConnectionInterface $connection,PDO $pdo):void
    {
        if($connection!==Db::connect()||$connection->getPdo()!==$pdo||self::defaultPdo()!==$pdo
            ||!$pdo->inTransaction())throw new AuthUnavailableException();
    }
}
