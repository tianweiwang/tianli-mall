<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\services\marketplace\MerchantExitFacts;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use PDO;
use PDOStatement;
use think\facade\Db;
use Throwable;

/** One detached, native read-only snapshot. It is never installed in the default DB manager. */
final class MerchantExitDao
{
    public function precheck(int $merId,?array $barrier=null): array
    {
        if($merId<1||$merId>4294967295)throw new AuthUnavailableException();
        $default=Db::connect();$original=$default->connect();$inside=$original->inTransaction();
        if(!$default instanceof Mysql)throw new AuthUnavailableException();
        $config=$default->getConfig();$prefix=$config['prefix']??null;
        if(($config['deploy']??null)!==0||($config['rw_separate']??null)!==false
            ||!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)throw new AuthUnavailableException();
        $read=new Mysql($config);$read->setDb(Db::instance());$pdo=null;
        try{
            $pdo=$read->connect();self::native($original);self::native($pdo);
            if($pdo===$original||$pdo->inTransaction()||$read->transactionDepth()!==0)throw new AuthUnavailableException();
            $writer=self::identity($original);$reader=self::identity($pdo);
            if($writer['connection_id']===$reader['connection_id'])throw new AuthUnavailableException();
            foreach(['database_name','account_name','server_id','sql_mode'] as$key)
                if($writer[$key]!==$reader[$key])throw new AuthUnavailableException();
            self::sameDefault($default,$original,$inside);
            // The caller already holds the merchant barrier for close. No read view
            // from the management/auth transaction can leak into this new session.
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false
                ||!$pdo->beginTransaction())throw new AuthUnavailableException();
            $merchant=self::rows($pdo,'SELECT * FROM '.self::table($prefix,'marketplace_merchant').' WHERE mer_id=?',[$merId]);
            if($merchant===[])throw new MerchantNotFoundException();
            if(count($merchant)!==1)throw new AuthUnavailableException();
            if($barrier!==null){
                if(!$inside||(int)($barrier['mer_id']??0)!==$merId)throw new AuthUnavailableException();
                foreach($barrier as$key=>$value){
                    if(!array_key_exists($key,$merchant[0])||($value===null?$merchant[0][$key]!==null:
                        $merchant[0][$key]===null||(string)$merchant[0][$key]!== (string)$value))throw new AuthUnavailableException();
                }
            }
            $order=self::table($prefix,'store_order');
            $facts=[];
            // Include foreign linked rows and deleted history. A scoped inner join must
            // never hide an orphan, mixed family or inconsistent ownership.
            $facts['orders']=self::rows($pdo,'SELECT * FROM '.$order.' WHERE mer_id=? OR pid IN (SELECT id FROM '.$order.' WHERE mer_id=?) ORDER BY id',[$merId,$merId]);
            $facts['customers']=self::rows($pdo,'SELECT uid FROM '.self::table($prefix,'user').' WHERE uid IN (SELECT uid FROM '.$order.' WHERE mer_id=?) ORDER BY uid',[$merId]);
            foreach(['ledgers'=>['marketplace_order_idempotency','order_id','uid,idem_key'],
                'registries'=>['marketplace_payment_registry','order_id','order_id'],
                'attempts'=>['marketplace_payment_attempt','order_id','id'],
                'items'=>['store_order_cart_info','oid','id'],'movements'=>['marketplace_stock_movement','order_id','id']] as$key=>[$table,$link,$sort]){
                $facts[$key]=self::rows($pdo,'SELECT * FROM '.self::table($prefix,$table).' WHERE mer_id=? OR `'.$link.'` IN (SELECT id FROM '.$order.' WHERE mer_id=?) ORDER BY '.$sort,[$merId,$merId]);
            }
            $fields=MarketplaceOrderDao::merchantExitColumns()['statuses'];
            $facts['statuses']=self::rows($pdo,'SELECT '.implode(',',array_map(fn(string $field):string=>'`'.$field.'`',$fields)).' FROM '.self::table($prefix,'store_order_status').
                ' WHERE oid IN (SELECT id FROM '.$order.' WHERE mer_id=?) ORDER BY oid,change_time,change_type',[$merId]);
            $facts['refunds']=self::rows($pdo,'SELECT * FROM '.self::table($prefix,'store_order_refund').
                ' WHERE mer_id=? OR marketplace_root_order_id IN (SELECT id FROM '.$order.' WHERE mer_id=?) OR store_order_id IN (SELECT id FROM '.$order.' WHERE mer_id=?) ORDER BY id',[$merId,$merId,$merId]);
            foreach(['actions'=>'marketplace_aftersale_action','operations'=>'marketplace_fulfillment_operation','ranges'=>'marketplace_fulfillment_range'] as$key=>$table){
                $facts[$key]=self::rows($pdo,'SELECT * FROM '.self::table($prefix,$table).' WHERE mer_id=? OR root_order_id IN (SELECT id FROM '.$order.' WHERE mer_id=?) ORDER BY id',[$merId,$merId]);
            }
            $facts['integral_orders']=self::rows($pdo,'SELECT * FROM '.self::table($prefix,'store_integral_order').' WHERE mer_id=? ORDER BY id',[$merId]);
            // Old activities/benefits have independent writers and cannot be cleared
            // by the ordinary V1 order inventory. Retain deleted/expired rows too.
            $facts['legacy_combinations']=self::rows($pdo,'SELECT * FROM '.self::table($prefix,'store_combination').' WHERE mer_id=? ORDER BY id',[$merId]);
            $levels=self::table($prefix,'system_user_level');$userLevels=self::table($prefix,'user_level');
            $facts['legacy_level_definitions']=self::rows($pdo,'SELECT * FROM '.$levels.' WHERE mer_id=? OR id IN (SELECT level_id FROM '.$userLevels.' WHERE mer_id=?) ORDER BY id',[$merId,$merId]);
            $facts['legacy_user_levels']=self::rows($pdo,'SELECT * FROM '.$userLevels.' WHERE mer_id=? OR level_id IN (SELECT id FROM '.$levels.' WHERE mer_id=?) ORDER BY id',[$merId,$merId]);
            // Article/catalog and customer-service identities/messages are retained
            // information, not a payment or settlement ledger. Actual order facts
            // above (including soft-deleted history) still own their obligations.
            if(array_keys($facts)!==MerchantExitFacts::COLLECTIONS)throw new AuthUnavailableException();
            $result=MerchantExitFacts::assess($merchant[0],time(),$facts);
            if(!$pdo->commit()||$pdo->inTransaction())throw new AuthUnavailableException();
            if(self::identity($pdo)!==$reader)throw new AuthUnavailableException();
            self::sameDefault($default,$original,$inside);return $result;
        }catch(MerchantNotFoundException $error){throw $error;}
        catch(Throwable $error){throw new AuthUnavailableException();}
        finally{
            if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
            $read->close();
        }
    }

    private static function sameDefault(Mysql $connection,PDO $pdo,bool $inside): void
    {
        if(Db::connect()!==$connection||$connection->connect()!==$pdo||$pdo->inTransaction()!==$inside)throw new AuthUnavailableException();
    }

    private static function native(PDO $pdo): void
    {
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($pdo->getAttribute(PDO::ATTR_PERSISTENT),[false,0],true))throw new AuthUnavailableException();
    }

    private static function identity(PDO $pdo): array
    {
        $rows=self::rows($pdo,'SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,CONNECTION_ID() AS connection_id,@@server_uuid AS server_id,@@SESSION.sql_mode AS sql_mode',[]);
        if(count($rows)!==1||!is_string($rows[0]['database_name'])||$rows[0]['database_name']===''
            ||!is_string($rows[0]['account_name'])||!is_string($rows[0]['server_id'])||!is_string($rows[0]['sql_mode']))throw new AuthUnavailableException();
        return $rows[0];
    }

    private static function table(string $prefix,string $name): string
    {
        $name=$prefix.$name;if(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name)!==1)throw new AuthUnavailableException();return '`'.$name.'`';
    }

    private static function rows(PDO $pdo,string $sql,array $values): array
    {
        $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)throw new AuthUnavailableException();
        if(!$statement->execute($values))throw new AuthUnavailableException();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        if(!$statement->closeCursor()||!is_array($rows)||!array_is_list($rows))throw new AuthUnavailableException();return $rows;
    }
}
