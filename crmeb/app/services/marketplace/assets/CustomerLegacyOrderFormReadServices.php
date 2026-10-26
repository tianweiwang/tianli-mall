<?php
declare(strict_types=1);

namespace app\services\marketplace\assets;

use app\Request;
use app\api\middleware\CustomerMiddleware;
use app\dao\marketplace\assets\CustomerLegacyOrderFormAssetDao;
use app\services\marketplace\order\CustomerOrderFormConfirmationReader;
use app\services\marketplace\order\MarketplaceOrderFormPlatformAccessServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceTradePolicy;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Db;
use Throwable;

/** Four named legacy streams. No public URL, ticket, merchant context or V1 fallback. */
final class CustomerLegacyOrderFormReadServices
{
    public function __construct(private CustomerOrderFormConfirmationReader $confirmations,
        private CustomerLegacyOrderFormAssetDao $assets,private MarketplaceOrderFormPlatformAccessServices $platform){}

    public function forConfirmation(Request $request,int $uid,string $key,string $assetId):array
    {return $this->read($request,'confirmation',$uid,$key,$assetId);}
    public function forCustomerOrder(Request $request,int $uid,int $orderId,string $assetId):array
    {return $this->read($request,'customer',$uid,$orderId,$assetId);}
    public function forMobileOrder(Request $request,int $uid,int $orderId,string $assetId):array
    {return $this->read($request,'mobile',$uid,$orderId,$assetId);}
    public function forPlatformOrder(Request $request,int $orderId,string $assetId):array
    {return $this->read($request,'platform',null,$orderId,$assetId);}

    private function read(Request $request,string $kind,?int $uid,int|string $locator,string $assetId):array
    {
        $this->input($request,$kind,$locator,$assetId);
        $identity=$this->identity($request,$kind,$uid,$locator);
        [$connection,$pdo,$prefix]=$this->connection();$session=$this->session($pdo);
        $setAttempted=false;$began=false;
        try{
            $setAttempted=true;
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false)self::fail();
            if($pdo->beginTransaction()!==true)self::fail();$began=true;
            $this->gate($connection,$pdo,true);
            if($this->session($pdo)!==$session)self::fail();
            $this->sameSession($request,$identity);
            if($kind==='confirmation'){
                $observed=$this->confirmations->readLegacyCurrent($uid,$locator);
                $row=$this->assets->readForConfirmation($connection,$pdo,$uid,$locator,$observed,$assetId);
            }else{
                // Authorize the selected order before resolving its ancestor or private object.
                $order=$this->selectedOrder($pdo,$prefix,$kind,$identity['actor_id'],$locator);
                $bundle=$this->assets->readForSavedOrder($connection,$pdo,$locator,$assetId);
                if(array_keys($bundle)!==['order','root','family','asset']||$bundle['order']!==$order)self::fail();
                $row=$bundle['asset'];
            }
            $physical=self::physical($row);
            $storage=app()->make(CustomerOrderFormPrivateStorage::class);
            $bytes=$storage->readObject($physical);
            $this->sameSession($request,$identity);
            if($kind==='confirmation'){
                $this->confirmations->assertLegacySame($uid,$locator,$observed);
                if($this->assets->readForConfirmation($connection,$pdo,$uid,$locator,$observed,$assetId)!==$row)self::fail();
            }else{
                if($this->selectedOrder($pdo,$prefix,$kind,$identity['actor_id'],$locator)!==$order
                    ||$this->assets->readForSavedOrder($connection,$pdo,$locator,$assetId)!==$bundle)self::fail();
            }
            if($storage->readObject($physical)!==$bytes||$this->session($pdo)!==$session)self::fail();
            $this->sameSession($request,$identity);$this->gate($connection,$pdo,true);
            if($pdo->commit()!==true)self::fail();
            $this->gate($connection,$pdo,false);
        }catch(Throwable $error){
            if($setAttempted){
                try{
                    if($pdo->inTransaction()){
                        if($pdo->rollBack()!==true||$pdo->inTransaction())$connection->close();
                    }elseif(!$began)$connection->close();
                }catch(Throwable $ignored){$connection->close();}
            }
            self::fail();
        }
        // Fresh account/role checks after the read-only snapshot, not its earlier DB view.
        if($this->identity($request,$kind,$uid,$locator)!==$identity||$this->session($pdo)!==$session)self::fail();
        $this->gate($connection,$pdo,false);
        if($kind==='confirmation')$this->confirmations->assertLegacySame($uid,$locator,$observed);
        return ['bytes'=>$bytes,'media_type'=>$physical['media_type'],'byte_size'=>$physical['byte_size'],
            'file_name'=>'order-form-'.$assetId.'.'.pathinfo($physical['object_key'],PATHINFO_EXTENSION)];
    }

    private function input(Request $request,string $kind,int|string $locator,string $assetId):void
    {
        if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$assetId)!==1
            ||(strlen($assetId)===20&&strcmp($assetId,'18446744073709551615')>0))self::fail();
        $routes=[
            'confirmation'=>['api','order/form/legacy/confirm/<key>/files/<asset_id>/content','key','asset_id'],
            'customer'=>['api','order/form/legacy/orders/<order_id>/files/<asset_id>/content','order_id','asset_id'],
            'mobile'=>['api','admin/order/form/legacy/orders/<order_id>/files/<asset_id>/content','order_id','asset_id'],
            'platform'=>['adminapi','order/form/legacy/orders/<order_id>/files/<asset_id>/content','order_id','asset_id'],
        ];
        if(!isset($routes[$kind]))self::fail();[$app,$route,$locatorKey,$assetKey]=$routes[$kind];
        if($request!==app()->request||app()->http->getName()!==$app||$request->method(true)!=='GET'
            ||trim(strtolower((string)$request->rule()->getRule()))!==$route
            ||$request->rule()->getOption('legacy_order_form_reader')!==$kind
            ||$request->route($assetKey,null,null)!==$assetId
            ||(string)$request->route($locatorKey,null,null)!==(string)$locator
            ||$request->get(false)!==[]||$request->server('QUERY_STRING','')!==''||$request->getContent()!=='')self::fail();
        if($kind==='confirmation'){
            if(!is_string($locator)||MarketplaceOrderInput::confirmationKey($locator)!==$locator)self::fail();
        }else self::integer($locator,1,2147483647);
    }

    private function identity(Request $request,string $kind,?int $uid,int|string $locator):array
    {
        if($kind==='platform'){
            $actor=$this->platform->authorizeLegacyRequest($request,$locator);
            $app='adminapi';
        }else{
            if($uid===null)self::fail();
            CustomerLegacyRefundAssetServices::identity($request,$uid);
            $actor=$uid;$app='api';
            $user=Db::name('user')->where('uid',$uid)->field(['uid','status','is_del'])->find();
            if(!is_array($user)||(string)($user['uid']??'')!==(string)$uid
                ||(string)($user['status']??'')!=='1'||(string)($user['is_del']??'')!=='0')self::fail();
            if($kind==='mobile'&&!app()->make(CustomerMiddleware::class)->hasManagementAccess($request))self::fail();
        }
        $fingerprint=$request->verifiedTokenFingerprint();
        if(!is_string($fingerprint)||preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();
        return ['app'=>$app,'actor_id'=>$actor,'session_sha256'=>$fingerprint];
    }

    private function sameSession(Request $request,array $identity):void
    {
        if($request!==app()->request||app()->http->getName()!==$identity['app']
            ||$request->verifiedTokenFingerprint()!==$identity['session_sha256'])self::fail();
        if($identity['app']==='api'){
            if(CustomerLegacyRefundAssetServices::identity($request,$identity['actor_id'])!==$identity['session_sha256'])self::fail();
        }else{
            $verified=$request->verifiedAdminSession();
            if($request->isAdminLogin()!==true||(string)$request->adminId()!==(string)$identity['actor_id']
                ||$verified['id']!==(string)$identity['actor_id']||$verified['fingerprint']!==$identity['session_sha256'])self::fail();
        }
    }

    private function selectedOrder(PDO $pdo,string $prefix,string $kind,int $actor,int $orderId):array
    {
        $rows=$this->rows($pdo,'SELECT * FROM '.$this->table($prefix,'store_order').' WHERE id=? LIMIT 2',[$orderId]);
        if(count($rows)!==1)self::fail();$order=$rows[0];MarketplaceTradePolicy::assertLegacyOrder($order);
        if(self::integer($order['id']??null,1,2147483647)!==$orderId)self::fail();
        if(in_array($kind,['customer','mobile'],true)&&self::integer($order['is_del']??null,0,1)!==0)self::fail();
        if($kind==='customer'&&self::integer($order['uid']??null,1,2147483647)!==$actor
            &&self::integer($order['gift_uid']??null,0,2147483647)!==$actor)self::fail();
        if($kind==='platform'){
            $buyers=$this->rows($pdo,'SELECT uid FROM '.$this->table($prefix,'user').' WHERE uid=? LIMIT 2',
                [self::integer($order['uid']??null,1,2147483647)]);
            if(count($buyers)!==1)self::fail();
        }
        return $order;
    }

    private function connection():array
    {
        $connection=Db::connect();
        if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO)self::fail();$this->gate($connection,$pdo,false);
        $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::fail();
        return [$connection,$pdo,$prefix];
    }
    private function gate(ConnectionInterface $connection,PDO $pdo,bool $active):void
    {
        if(!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->transactionDepth()!==0||$pdo->inTransaction()!==$active
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }
    private function session(PDO $pdo):array
    {
        $rows=$this->rows($pdo,'SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit',[]);
        if(count($rows)!==1||!in_array($rows[0]['autocommit']??null,[1,'1'],true)
            ||$rows[0]['database_name']!==Db::connect()->getConfig('database'))self::fail();
        return $rows[0];
    }
    private function rows(PDO $pdo,string $sql,array $arguments):array
    {
        $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute($arguments)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($statement->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||!array_is_list($rows))self::fail();return $rows;
    }
    private function table(string $prefix,string $suffix):string
    {if(strlen($prefix.$suffix)>64)self::fail();return chr(96).$prefix.$suffix.chr(96);}
    private static function physical(array $row):array
    {
        if(!is_string($row['object_key']??null)||!is_string($row['media_type']??null)
            ||!is_string($row['content_sha256']??null)||strlen($row['content_sha256'])!==32)self::fail();
        return ['uid'=>self::integer($row['uid'],1,2147483647),'object_key'=>$row['object_key'],'media_type'=>$row['media_type'],
            'byte_size'=>self::integer($row['byte_size'],1,52428800),'width'=>self::integer($row['width'],1,4294967295),
            'height'=>self::integer($row['height'],1,4294967295),'content_sha256'=>$row['content_sha256']];
    }
    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<$minimum||$value>$maximum)self::fail();return $value;
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
