<?php
declare(strict_types=1);

namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerAssetDao;
use app\dao\marketplace\assets\CustomerOrderFormAssetDao;
use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\order\CustomerOrderFormConfirmationReader;
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderCustomForm;
use app\services\marketplace\order\MarketplaceOrderFormPlatformAccessServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\order\StoreOrderServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use stdClass;
use think\db\ConnectionInterface;
use think\facade\Db;
use Throwable;

/** Five fixed authenticated readers; no public row, binding, private path or generic owner context. */
final class CustomerOrderFormReadServices
{
    public function __construct(private CustomerOrderFormConfirmationReader $confirmations,
        private CustomerOrderFormReadTickets $tickets,private CustomerOrderFormAssetDao $assets,
        private CustomerAssetDao $customers,private MarketplaceOrderDao $orders,
        private MarketplaceOrderFulfillmentDao $facts,private EmployeeAuthServices $employees,
        private MarketplaceOrderFormPlatformAccessServices $platform){}

    public function forConfirmation(Request $request,int $actualUid,string $originalKey,string $assetId):array
    {
        try{
            $key=MarketplaceOrderInput::confirmationKey($originalKey);if($key!==$originalKey)self::unavailable();
            $input=$this->input($request,'confirmation',null,$key,$assetId);
            $identity=$this->customer($request,$actualUid,'confirmation');
            $read=$this->confirmation($request,$identity,$key,$assetId,$input);
            return $this->publish($request,$identity,null,null,$input,$read);
        }catch(Throwable $error){self::unavailable();}
    }

    public function forCustomerOrder(Request $request,int $actualUid,int $actualRootId,string $assetId):array
    {
        try{
            $input=$this->input($request,'customer_order',$actualRootId,null,$assetId);
            $identity=$this->customer($request,$actualUid,'customer_order');
            $read=$this->saved($request,$identity,null,$actualRootId,$assetId,$input);
            return $this->publish($request,$identity,null,$actualRootId,$input,$read);
        }catch(Throwable $error){self::unavailable();}
    }

    public function forMerchantOrder(Request $request,MerchantContext $actualContext,int $actualRootId,string $assetId):array
    {
        try{
            $input=$this->input($request,'merchant_order',$actualRootId,null,$assetId);
            $identity=$this->merchant($request,$actualContext,true);
            $read=$this->saved($request,$identity,$actualContext,$actualRootId,$assetId,$input);
            return $this->publish($request,$identity,$actualContext,$actualRootId,$input,$read);
        }catch(Throwable $error){self::unavailable();}
    }

    public function forPlatformOrder(Request $request,int $actualRootId,string $assetId):array
    {
        try{
            $input=$this->input($request,'platform_order',$actualRootId,null,$assetId);
            $adminId=$this->platform->authorizeRequest($request,$actualRootId);
            $identity=$this->administrator($request);
            if($identity['actor_id']!==$adminId)self::unavailable();
            $read=$this->saved($request,$identity,null,$actualRootId,$assetId,$input);
            return $this->publish($request,$identity,null,$actualRootId,$input,$read);
        }catch(Throwable $error){self::unavailable();}
    }

    public function forMobileOrder(Request $request,int $actualUid,int $actualRootId,string $assetId):array
    {
        try{
            $input=$this->input($request,'mobile_order',$actualRootId,null,$assetId);
            $identity=$this->customer($request,$actualUid,'mobile_order');
            $read=$this->saved($request,$identity,null,$actualRootId,$assetId,$input);
            return $this->publish($request,$identity,null,$actualRootId,$input,$read);
        }catch(Throwable $error){self::unavailable();}
    }

    /** Same actual user/idempotency/merchant/product/SKU/cart/asset order as confirmation upload/create. */
    private function confirmation(Request $request,array $identity,string $key,string $assetId,array $input):array
    {
        [$connection,$pdo,$prefix]=$this->defaultConnection();$uid=$identity['actor_id'];
        $user=$this->userRows($connection,$pdo,$prefix,$uid,0,false);
        $session=$this->session($connection,$pdo);$entered=false;$attempted=false;$storage=null;
        try{
            $attempted=true;
            $read=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$prefix,$request,
                $identity,$uid,$key,$assetId,$input,$session,$user,&$entered,&$storage):array{
                if($actual!==$connection||$entered)self::unavailable();$entered=true;
                $this->gate($connection,$pdo,$prefix,1,true);$this->sameIdentity($request,$identity,null,null,false);
                if($this->session($connection,$pdo)!==$session||$this->customers->lockUser($connection,$pdo,$uid)!==$user)self::unavailable();
                if($this->orders->lookupConfirmation($pdo,$uid,$key)!==['state'=>'not_found','key'=>$key])self::unavailable();
                $observed=$this->confirmations->readCurrent($uid,$key);$frame=$observed['frame'];
                $locked=$this->orders->lockFormUploadParents($pdo,$uid,$frame['mer_id'],$frame);
                $group=app()->make(StoreOrderServices::class)->getCacheOrderInfo($uid,$key);
                if(!is_array($group)||MarketplaceCartSelection::frame($group,$uid)!==$frame)self::unavailable();
                self::lockedFrame($uid,$frame,$locked);
                $row=$this->assets->lockForConfirmation($connection,$pdo,$uid,$key,$assetId,$frame,$observed['deadline_ms']);
                $current=$this->confirmations->assertSame($uid,$key,$observed);self::liveStaged($row,$current);
                $binding=self::binding($identity,$row,null,$frame['mer_id']);
                $receipt=$input['content']?$this->tickets->peek($input['ticket'],$binding,$observed['deadline_ms']):null;
                $this->sameIdentity($request,$identity,null,null,false);
                // No private-root construction or file open until actual data authorization and, for GET, a live ticket.
                $physical=self::physical($row);$storage=$this->storage();
                $current=$this->confirmations->assertSame($uid,$key,$observed);self::liveStaged($row,$current);
                $bytes=$storage->readObject($physical);
                if($this->assets->lockForConfirmation($connection,$pdo,$uid,$key,$assetId,$frame,$observed['deadline_ms'])!==$row)self::unavailable();
                $current=$this->confirmations->assertSame($uid,$key,$observed);self::liveStaged($row,$current);
                if($storage->readObject($physical)!==$bytes)self::unavailable();
                $this->sameIdentity($request,$identity,null,null,false);
                if($this->customers->lockUser($connection,$pdo,$uid)!==$user||$this->session($connection,$pdo)!==$session)self::unavailable();
                $current=$this->confirmations->assertSame($uid,$key,$observed);self::liveStaged($row,$current);
                $this->gate($connection,$pdo,$prefix,1,true);
                return ['row'=>$row,'physical'=>$physical,'bytes'=>$bytes,'binding'=>$binding,'receipt'=>$receipt,
                    'deadline_ms'=>$observed['deadline_ms'],'observed'=>$observed];
            });
            // The ORM wrapper does not expose its native commit ACK. Observe its actual ended connection and unchanged facts.
            $this->gate($connection,$pdo,$prefix,0,false);$this->sameIdentity($request,$identity,null,null,false);
            if(!$entered||!$storage instanceof CustomerOrderFormPrivateStorage||$this->session($connection,$pdo)!==$session
                ||$this->userRows($connection,$pdo,$prefix,$uid,0,false)!==$user)self::unavailable();
            $rows=$this->rows($connection,$pdo,$prefix,'SELECT * FROM '.$this->table($prefix,'marketplace_customer_asset').' WHERE id=? LIMIT 2',[$assetId],0,false);
            if($rows!==[$read['row']]||self::physical($rows[0])!==$read['physical'])self::unavailable();
            $current=$this->confirmations->assertSame($uid,$key,$read['observed']);self::liveStaged($read['row'],$current);
            if($storage->readObject($read['physical'])!==$read['bytes'])self::unavailable();
            $current=$this->confirmations->assertSame($uid,$key,$read['observed']);self::liveStaged($read['row'],$current);
            $this->sameIdentity($request,$identity,null,null,false);$this->gate($connection,$pdo,$prefix,0,false);
            if($this->session($connection,$pdo)!==$session)self::unavailable();return $read;
        }catch(Throwable $error){
            // No DML, ticket compensation, physical deletion or retry; discard an uncertain owned wrapper connection.
            if($attempted){try{$connection->close();}catch(Throwable $ignored){}}
            self::unavailable();
        }
    }

    /** Fixed saved-root branches in this service's own native RR READ ONLY; no external callback enters the old plan. */
    private function saved(Request $request,array $identity,?MerchantContext $merchant,int $rootId,string $assetId,array $input):array
    {
        [$connection,$pdo,$prefix]=$this->defaultConnection();$session=$this->session($connection,$pdo);
        $this->facts->assertAftersaleStructure($pdo);$setAttempted=false;$began=false;$commitAttempted=false;
        try{
            $this->gate($connection,$pdo,$prefix,0,false);$setAttempted=true;
            if($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')===false)self::unavailable();
            $this->gate($connection,$pdo,$prefix,0,false);
            if($pdo->beginTransaction()!==true)self::unavailable();$began=true;
            $this->gate($connection,$pdo,$prefix,0,true);
            if($this->session($connection,$pdo)!==$session)self::unavailable();
            $readiness=$this->facts->readAftersaleStructureReadiness($connection,$pdo);
            // Fresh role/grant DB reads ran before begin; here only this request's real accepted identity is compared.
            $this->sameIdentity($request,$identity,$merchant,$rootId,false);
            $root=$this->savedRoot($connection,$pdo,$prefix,$identity,$merchant,$rootId);
            $row=$this->assets->readForSavedRoot($connection,$pdo,$root,$assetId);
            $binding=self::binding($identity,$row,$rootId,self::integer($root['mer_id'],1,4294967295));
            $receipt=$input['content']?$this->tickets->peek($input['ticket'],$binding):null;
            $this->sameIdentity($request,$identity,$merchant,$rootId,false);
            $physical=self::physical($row);$storage=$this->storage();$bytes=$storage->readObject($physical);
            $this->sameIdentity($request,$identity,$merchant,$rootId,false);
            if($this->savedRoot($connection,$pdo,$prefix,$identity,$merchant,$rootId)!==$root
                ||$this->assets->readForSavedRoot($connection,$pdo,$root,$assetId)!==$row
                ||$storage->readObject($physical)!==$bytes)self::unavailable();
            $this->sameIdentity($request,$identity,$merchant,$rootId,false);
            if($this->facts->readAftersaleStructureReadiness($connection,$pdo)!==$readiness
                ||$this->session($connection,$pdo)!==$session)self::unavailable();
            $this->gate($connection,$pdo,$prefix,0,true);$commitAttempted=true;
            if($pdo->commit()!==true)self::unavailable();
            $this->gate($connection,$pdo,$prefix,0,false);
            if($this->session($connection,$pdo)!==$session)self::unavailable();
        }catch(Throwable $error){
            if($setAttempted){
                $clean=false;$rolled=false;
                try{
                    if($pdo->inTransaction()){$rolled=$pdo->rollBack()===true;if(!$rolled||$pdo->inTransaction())self::unavailable();}
                    if($began&&!$commitAttempted&&$rolled){$this->gate($connection,$pdo,$prefix,0,false);$clean=true;}
                }catch(Throwable $cleanupError){}
                if(!$clean){try{$connection->close();}catch(Throwable $ignored){}}
            }
            self::unavailable();
        }
        // Outside the ended snapshot, current employee/admin state and original grants are queried afresh.
        $this->sameIdentity($request,$identity,$merchant,$rootId,true);
        return ['row'=>$row,'physical'=>$physical,'bytes'=>$bytes,'binding'=>$binding,'receipt'=>$receipt,'deadline_ms'=>null];
    }

    private function savedRoot(ConnectionInterface $connection,PDO $pdo,string $prefix,array $identity,?MerchantContext $merchant,int $rootId):array
    {
        $kind=$identity['reader_kind'];$actor=$identity['actor_id'];
        if($kind==='merchant_order'){
            if($merchant===null)self::unavailable();
            $root=$this->facts->readMerchantRoot($connection,$pdo,$merchant->merId,$rootId);
        }elseif($kind==='platform_order'){
            $root=$this->facts->readPlatformRoot($connection,$pdo,$rootId);
        }elseif(in_array($kind,['customer_order','mobile_order'],true)){
            // PK is a locator only. Original customer/mobile authorization still uses the saved business order number.
            $rows=$this->rows($connection,$pdo,$prefix,'SELECT * FROM '.$this->table($prefix,'store_order').' WHERE id=? LIMIT 2',[$rootId],0,true);
            if(count($rows)!==1)self::unavailable();$located=$rows[0];self::root($located,$rootId);
            if(self::integer($located['uid'],1,2147483647)!==$actor)self::unavailable();
            $orderNo=$located['order_id']??null;
            if(!is_string($orderNo)||$orderNo===''||strlen($orderNo)>32||str_contains($orderNo,"\0"))self::unavailable();
            if($kind==='customer_order')$root=$this->facts->readCustomerRoot($connection,$pdo,$actor,$orderNo);
            else{
                $lookup=$this->facts->readMobileRoot($connection,$pdo,$actor,$orderNo);
                if(array_keys($lookup)!==['probe','lookup_uid','root']||$lookup['lookup_uid']!==$actor
                    ||!is_array($lookup['probe'])||self::integer($lookup['probe']['id']??null,1,2147483647)!==$rootId
                    ||self::integer($lookup['probe']['marketplace_trade_version']??null,1,1)!==1)self::unavailable();
                $root=$lookup['root'];
            }
            if($root!==$located)self::unavailable();
        }else self::unavailable();
        if(!is_array($root))self::unavailable();self::root($root,$rootId);
        if(in_array($kind,['customer_order','mobile_order'],true)&&self::integer($root['uid'],1,2147483647)!==$actor)self::unavailable();
        if($kind==='merchant_order'&&self::integer($root['mer_id'],1,4294967295)!==$merchant->merId)self::unavailable();
        if($kind==='platform_order'&&$this->facts->readPlatformDetailBuyerForHeader($connection,$pdo,$root)===null)self::unavailable();
        return $root;
    }

    /** Issue/consume only after all bytes and the owned SQL boundary; a peek receipt never leaves this class. */
    private function publish(Request $request,array $identity,?MerchantContext $merchant,?int $rootId,array $input,array $read):array
    {
        $this->sameIdentity($request,$identity,$merchant,$rootId,true);
        if(!$input['content']){
            $issued=$this->tickets->issue($read['binding'],$read['deadline_ms']);
            $this->sameIdentity($request,$identity,$merchant,$rootId,true);return $issued;
        }
        if(!is_array($read['receipt']))self::unavailable();
        $this->tickets->consume($input['ticket'],$read['binding'],$read['receipt'],$read['deadline_ms']);
        $this->sameIdentity($request,$identity,$merchant,$rootId,true);
        $extensions=['image/jpg'=>'jpg','image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $row=$read['row'];$mime=$row['media_type'];$size=self::integer($row['byte_size'],1,52428800);
        if(!isset($extensions[$mime])||!is_string($read['bytes'])||strlen($read['bytes'])!==$size)self::unavailable();
        return ['bytes'=>$read['bytes'],'media_type'=>$mime,'byte_size'=>$size,
            'file_name'=>'order-form-'.self::assetId($row['id']).'.'.$extensions[$mime]];
    }

    /** Exact five route families; input never chooses an actor, permission, owner or alternate rule. */
    private function input(Request $request,string $kind,?int $rootId,?string $key,string $assetId):array
    {
        self::wireAssetId($assetId);$method=$request->method(true);
        switch($kind){
            case 'confirmation':$app='api';$base='order/confirm/<key>/form-files/<asset_id>';break;
            case 'customer_order':$app='api';$base='order/<root_id>/form-files/<asset_id>';break;
            case 'merchant_order':$app='merchantapi';$base='orders/<root_id>/form-files/<asset_id>';break;
            case 'platform_order':$app='adminapi';$base='order/info/<root_id>/form-files/<asset_id>';break;
            case 'mobile_order':$app='api';$base='admin/order/detail/<root_id>/form-files/<asset_id>';break;
            default:self::unavailable();
        }
        if(app()->request!==$request||app()->http->getName()!==$app||!in_array($method,['POST','GET'],true)
            ||strtolower(trim((string)$request->rule()->getRule()))!==$base.($method==='POST'?'/read-ticket':'/content')
            ||$request->route('asset_id',null,'')!==$assetId||$request->get(false)!==[]
            ||$request->server('QUERY_STRING','')!=='')self::unavailable();
        if($kind==='confirmation'){
            if($rootId!==null||$key===null||$request->route('key',null,'')!==$key)self::unavailable();
        }else if($key!==null||$rootId===null||self::integer($rootId,1,2147483647)!==self::integer($request->route('root_id',null,''),1,2147483647))self::unavailable();
        $raw=$request->getContent();
        if($method==='POST'){
            if(strtolower($request->contentType())!=='application/json'||!is_string($raw)||$raw===''||strlen($raw)>65536||preg_match('//u',$raw)!==1)self::unavailable();
            try{$body=json_decode($raw,false,8,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::unavailable();}
            if(!$body instanceof stdClass)self::unavailable();$body=get_object_vars($body);
            if(array_keys($body)!==['schema']||$body['schema']!==1)self::unavailable();
            return ['content'=>false,'ticket'=>null];
        }
        $ticket=$request->header('X-Order-Form-Read-Ticket','');
        if($raw!==''||!is_string($ticket)||preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket)!==1)self::unavailable();
        return ['content'=>true,'ticket'=>$ticket];
    }

    private function customer(Request $request,int $actualUid,string $kind):array
    {
        $fingerprint=self::fingerprint($request,'api');self::integer($actualUid,1,2147483647);
        if(!in_array($kind,['confirmation','customer_order','mobile_order'],true)||$request->isLogin()!==true
            ||!in_array($request->uid(),[$actualUid,(string)$actualUid],true))self::unavailable();
        return ['reader_kind'=>$kind,'app'=>'api','actor_type'=>'customer','actor_id'=>$actualUid,'session_sha256'=>$fingerprint];
    }
    private function merchant(Request $request,MerchantContext $context,bool $fresh):array
    {
        $fingerprint=self::fingerprint($request,'merchantapi');
        if($request->merchantContext()!==$context)self::unavailable();
        self::integer($context->employeeId,1,4294967295);self::integer($context->merId,1,4294967295);
        $context->require('order.read');if($fresh)$this->employees->authorizeAction($context,'order.read',false);
        return ['reader_kind'=>'merchant_order','app'=>'merchantapi','actor_type'=>'employee','actor_id'=>$context->employeeId,'session_sha256'=>$fingerprint];
    }
    private function administrator(Request $request):array
    {
        $fingerprint=self::fingerprint($request,'adminapi');$adminId=self::integer($request->adminId(),1,4294967295);
        $info=$request->adminInfo();$verified=$request->verifiedAdminSession();
        if($request->isAdminLogin()!==true||!is_array($info)||(string)($info['id']??'')!==(string)$adminId
            ||$verified['id']!==(string)$adminId||!hash_equals($fingerprint,$verified['fingerprint']))self::unavailable();
        return ['reader_kind'=>'platform_order','app'=>'adminapi','actor_type'=>'admin','actor_id'=>$adminId,'session_sha256'=>$fingerprint];
    }
    private function sameIdentity(Request $request,array $identity,?MerchantContext $merchant,?int $rootId,bool $fresh):void
    {
        switch($identity['reader_kind']){
            case 'confirmation':case 'customer_order':case 'mobile_order':
                $current=$this->customer($request,$identity['actor_id'],$identity['reader_kind']);break;
            case 'merchant_order':
                if($merchant===null)self::unavailable();$current=$this->merchant($request,$merchant,$fresh);break;
            case 'platform_order':
                if($rootId===null)self::unavailable();
                if($fresh&&$this->platform->authorizeRequest($request,$rootId)!==$identity['actor_id'])self::unavailable();
                $current=$this->administrator($request);break;
            default:self::unavailable();
        }
        if($current!==$identity)self::unavailable();
    }
    private static function fingerprint(Request $request,string $app):string
    {
        if(app()->request!==$request||app()->http->getName()!==$app)self::unavailable();
        $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::unavailable();return $fingerprint;
    }

    private static function binding(array $identity,array $row,?int $rootId,int $merId):array
    {
        $uid=self::integer($row['uid'],1,2147483647);self::integer($merId,1,4294967295);
        foreach(['content_sha256','confirmation_ref','form_definition_sha256'] as $name)
            if(!is_string($row[$name])||strlen($row[$name])!==32)self::unavailable();
        if(in_array($identity['reader_kind'],['confirmation','customer_order','mobile_order'],true)&&$identity['actor_id']!==$uid)self::unavailable();
        return ['reader_kind'=>$identity['reader_kind'],'app'=>$identity['app'],'actor_type'=>$identity['actor_type'],
            'actor_id'=>$identity['actor_id'],'session_sha256'=>$identity['session_sha256'],'uid'=>$uid,
            'asset_id'=>self::assetId($row['id']),'content_sha256'=>bin2hex($row['content_sha256']),
            'confirmation_ref'=>bin2hex($row['confirmation_ref']),'form_product_id'=>self::integer($row['form_product_id'],1,8388607),
            'form_definition_sha256'=>bin2hex($row['form_definition_sha256']),'form_field_index'=>self::integer($row['form_field_index'],0,9),
            'root_order_id'=>$rootId,'mer_id'=>$merId];
    }
    private static function physical(array $row):array
    {
        if(!is_string($row['object_key'])||!is_string($row['media_type'])||!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32)self::unavailable();
        return ['uid'=>self::integer($row['uid'],1,2147483647),'object_key'=>$row['object_key'],'media_type'=>$row['media_type'],
            'byte_size'=>self::integer($row['byte_size'],1,52428800),'width'=>self::integer($row['width'],1,4294967295),
            'height'=>self::integer($row['height'],1,4294967295),'content_sha256'=>$row['content_sha256']];
    }
    private function storage():CustomerOrderFormPrivateStorage {return app()->make(CustomerOrderFormPrivateStorage::class);}

    private function defaultConnection():array
    {
        $connection=Db::connect();if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::unavailable();
        $prefix=$connection->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1)self::unavailable();
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        if(!$pdo instanceof PDO)self::unavailable();$this->gate($connection,$pdo,$prefix,0,false);return [$connection,$pdo,$prefix];
    }
    private function gate(ConnectionInterface $connection,PDO $pdo,string $prefix,int $depth,bool $active):void
    {
        if(PHP_INT_SIZE<8||!$connection instanceof Mysql||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false||$connection->getConfig('prefix')!==$prefix
            ||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==$active
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,false,0,1],true))self::unavailable();
    }
    private function session(ConnectionInterface $connection,PDO $pdo):array
    {
        if($connection!==Db::connect()||$connection->getPdo()!==$pdo)self::unavailable();
        $statement=$pdo->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$statement instanceof PDOStatement)self::unavailable();
        try{if($statement->execute([])!==true)self::unavailable();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($statement->closeCursor()!==true)self::unavailable();}
        if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['database_name','authenticated_user','connection_id','autocommit'])self::unavailable();
        $row=$rows[0];foreach(['database_name','authenticated_user'] as $name)
            if(!is_string($row[$name])||$row[$name]===''||str_contains($row[$name],"\0"))self::unavailable();
        if($row['database_name']!==$connection->getConfig('database')||!in_array($row['autocommit'],[1,'1'],true))self::unavailable();
        self::assetId($row['connection_id']);return $row;
    }
    /** Only the two fixed SELECTs above and this actual-user read use this private native helper. */
    private function rows(ConnectionInterface $connection,PDO $pdo,string $prefix,string $sql,array $parameters,int $depth,bool $active):array
    {
        $this->gate($connection,$pdo,$prefix,$depth,$active);$statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::unavailable();
        try{if($statement->execute($parameters)!==true)self::unavailable();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($statement->closeCursor()!==true)self::unavailable();}
        if(!is_array($rows)||!array_is_list($rows))self::unavailable();$this->gate($connection,$pdo,$prefix,$depth,$active);return $rows;
    }
    private function userRows(ConnectionInterface $connection,PDO $pdo,string $prefix,int $uid,int $depth,bool $active):array
    {
        $rows=$this->rows($connection,$pdo,$prefix,'SELECT * FROM '.$this->table($prefix,'user').' WHERE uid=? LIMIT 2',[$uid],$depth,$active);
        if(count($rows)!==1||self::integer($rows[0]['uid']??null,1,2147483647)!==$uid
            ||self::integer($rows[0]['status']??null,1,1)!==1||self::integer($rows[0]['is_del']??null,0,0)!==0)self::unavailable();return $rows[0];
    }
    private function table(string $prefix,string $name):string
    {
        if(!in_array($name,['user','store_order','marketplace_customer_asset'],true)||strlen($prefix.$name)>64)self::unavailable();return '`'.$prefix.$name.'`';
    }
    private static function root(array $root,int $rootId):void
    {
        if(self::integer($root['id']??null,1,2147483647)!==$rootId||self::integer($root['pid']??null,0,0)!==0
            ||self::integer($root['marketplace_trade_version']??null,1,1)!==1)self::unavailable();
        self::integer($root['uid']??null,1,2147483647);self::integer($root['mer_id']??null,1,4294967295);
        if(!is_string($root['unique']??null)||MarketplaceOrderInput::confirmationKey($root['unique'])!==$root['unique']
            ||!is_string($root['custom_form']??null))self::unavailable();
    }
    private static function lockedFrame(int $uid,array $frame,array $locked):void
    {
        if(($locked['uid']??null)!==$uid)self::unavailable();
        foreach(['mer_id','source_kind','cart_refs','selection'] as $key)if(($locked[$key]??null)!==$frame[$key])self::unavailable();
        if(!is_array($locked['products']??null)||MarketplaceOrderCustomForm::definitions($locked['products'],$locked['selection'],$locked['source_kind'])!==$frame['custom_form'])self::unavailable();
    }
    private static function liveStaged(array $row,array $current):void
    {
        if(self::integer($row['expires_at'],1,4294967295)*1000<=$current['redis_time_ms'])self::unavailable();
    }
    private static function wireAssetId(string $id):void {if(self::assetId($id)!==$id)self::unavailable();}
    private static function assetId(mixed $id):string
    {
        if(is_int($id)&&$id>0)$id=(string)$id;
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
            ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))self::unavailable();return $id;
    }
    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        if(is_int($value)&&$value>=$minimum&&$value<=$maximum)return $value;
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1||strlen($value)>strlen((string)$maximum)
            ||(strlen($value)===strlen((string)$maximum)&&strcmp($value,(string)$maximum)>0)||(int)$value<$minimum)self::unavailable();return (int)$value;
    }
    private static function unavailable():never {throw new AuthUnavailableException();}
}
