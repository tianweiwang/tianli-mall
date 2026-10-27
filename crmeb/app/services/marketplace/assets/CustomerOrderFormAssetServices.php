<?php
declare(strict_types=1);

namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerAssetDao;
use app\dao\marketplace\assets\CustomerOrderFormAssetDao;
use app\dao\marketplace\order\MarketplaceOrderDao;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\CustomerOrderFormConfirmationReader;
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderCustomForm;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\order\StoreOrderServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\facade\Db;
use think\file\UploadedFile;
use Throwable;

/** Only confirmation uploads and the existing first-create INSERT block; neither ticket nor saved-order reads. */
final class CustomerOrderFormAssetServices
{
    public function __construct(private CustomerOrderFormConfirmationReader $confirmations){}

    public function uploadForConfirmation(Request $request,int $actualUid,string $key,int $productId,int $fieldIndex):array
    {
        $receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];$object=[];$storage=null;$diagnosticStage='request_identity';
        try{
            $fingerprint=self::identity($request,$actualUid);$key=MarketplaceOrderInput::confirmationKey($key);
            $diagnosticStage='request_envelope';
            if($productId<1||$productId>8388607||$fieldIndex<0||$fieldIndex>9
                ||$request->rule()->getOption('customer_order_form_files')!==true
                ||strtolower($request->contentType())!=='multipart/form-data'||$request->get(false)!==[]
                ||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $diagnosticStage='request_file';$files=$request->file();if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            $diagnosticStage='primary_pdo';[$connection,$pdo]=$this->primary($actualUid);$session=self::session($connection,$pdo);$entered=false;
            $diagnosticStage='transaction_start';$out=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$actualUid,$key,
                $productId,$fieldIndex,$fingerprint,$session,$files,&$entered,&$receipt,&$object,&$storage,&$diagnosticStage):array{
                $diagnosticStage='transaction_entry';if($actual!==$connection||$entered)self::fail();$entered=true;
                $diagnosticStage='owner_before_lock';self::connection($connection,$pdo,1);self::sameIdentity($request,$actualUid,$fingerprint);
                if(self::session($connection,$pdo)!==$session)self::fail();
                $diagnosticStage='lock_user';$user=$this->customers()->lockUser($connection,$pdo,$actualUid);
                $diagnosticStage='lookup_confirmation';if($this->orderDao()->lookupConfirmation($pdo,$actualUid,$key)!==['state'=>'not_found','key'=>$key])self::fail();
                $diagnosticStage='read_confirmation';$observed=$this->confirmations->readCurrent($actualUid,$key);$frame=$observed['frame'];
                $diagnosticStage='lock_parents';$locked=$this->orderDao()->lockFormUploadParents($pdo,$actualUid,$frame['mer_id'],$frame);
                $diagnosticStage='cached_frame';$group=$this->orders()->getCacheOrderInfo($actualUid,$key);
                if(!is_array($group)||MarketplaceCartSelection::frame($group,$actualUid)!==$frame)self::fail();
                $diagnosticStage='locked_frame';self::lockedFrame($actualUid,$frame,$locked);
                $diagnosticStage='field_authorization';$definition=self::uploadField($frame['custom_form'],$productId,$fieldIndex);
                $diagnosticStage='owner_confirmation_before_quota';$this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertSame($actualUid,$key,$observed);
                $diagnosticStage='quota_read';$count=self::counter(CacheService::get('start_uploads_'.$actualUid,0));if($count>=100)self::fail();
                $diagnosticStage='uploaded_tempfile';[$bytes,$name]=$this->uploadedBytes($files['pics']);
                // Construction of private storage is intentionally after real confirmation/parents/field authorization.
                $diagnosticStage='storage_construct';$storage=$this->storage();
                $diagnosticStage='image_qualify';$qualified=$storage->inspectUpload($bytes,$name);
                $diagnosticStage='owner_confirmation_before_write';$this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertSame($actualUid,$key,$observed);
                $diagnosticStage='private_write_preflight';$physical=$storage->writeNew($actualUid,$observed['identity']['confirmation_ref'],$observed['deadline_ms'],
                    $bytes,$name,$object,$receipt);
                if($receipt['physical_complete']!==true||$receipt['physical_changed']!==true
                    ||$storage->readObject($physical)!==$bytes||$physical['content_sha256']!==$qualified['content_sha256'])self::fail();
                $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertSame($actualUid,$key,$observed);
                $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                $asset=$this->assets()->insertStaged($connection,$pdo,$actualUid,$key,$productId,$definition,$fieldIndex,
                    $physical,$name,self::integer(time(),1,4294967295),$observed['deadline_ms']);
                if(self::physical($asset,$actualUid)!==$physical||$this->assetRow($connection,$pdo,$asset,1)!==$asset
                    ||$storage->readObject($physical)!==$bytes)self::fail();
                $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertSame($actualUid,$key,$observed);
                $receipt['phase']='commit_outcome_unknown';
                return ['asset'=>$asset,'user'=>$user,'physical'=>$physical,'bytes'=>$bytes,'observed'=>$observed];
            });
            // No commit claim until the actual owning wrapper returned and the same outside PDO sees all persistent bytes.
            if(!$entered||!$storage instanceof CustomerOrderFormPrivateStorage)self::fail();
            $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$out['user'],0);
            $this->assets()->assertStagedCommitted($connection,$pdo,$actualUid,$out['asset']);
            if($storage->readObject($out['physical'])!==$out['bytes'])self::fail();
            $this->confirmations->assertSame($actualUid,$key,$out['observed']);
            $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$out['user'],0);
            $receipt['database_committed']=true;$receipt['phase']='quota_update';
            $quota='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($quota,0));if($count>=100)self::fail();$count++;
            if(CacheService::set($quota,$count,86400)!==true||self::counter(CacheService::get($quota,null))!==$count)self::fail();
            self::sameIdentity($request,$actualUid,$fingerprint);
            $id=self::assetId($out['asset']['id']);$domain=$request->domain();
            if(!is_string($domain)||preg_match('~\Ahttps?://[^/?#\s]+\z~D',$domain)!==1)self::fail();
            $dto=['name'=>$out['asset']['name'],'url'=>$domain.'/api/order/confirm/'.rawurlencode($key).'/form-files/'.$id.'/content',
                'attachment_ref'=>'mp-form:v1:'.$id];
            $receipt['complete']=true;$receipt['phase']='complete';return $dto;
        }catch(Throwable $error){
            if($receipt['phase']==='preflight')self::logPreflightFailure($error,$diagnosticStage);
            // Do not remove partial physical outcomes, old files, or anything after a possibly attempted INSERT/commit.
            if($receipt['database_committed']===false&&$object!==[]&&$storage instanceof CustomerOrderFormPrivateStorage){
                try{$storage->cleanupNew($object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** The original caller owns this transaction, its earlier claim/replay/quote, and all post-commit events. */
    public function withCreateAssets(ConnectionInterface $connection,PDO $pdo,int $actualUid,string $key,
        array $frame,array $locked,array $snapshot,callable $createWrites):array
    {
        foreach($snapshot as$group)foreach($group['fields'] as$field)
            if($field['label']==='img')foreach($field['value'] as$reference)CustomerAssetServices::assertNotPublicCompatMaterial($reference);
        // Preserve old no-asset creates before new Redis/schema/private-root/identity dependencies are touched.
        if(!self::hasPrivateReference($snapshot))return $createWrites();
        $request=app()->request;if(!$request instanceof Request)self::fail();
        $fingerprint=self::identity($request,$actualUid);$key=MarketplaceOrderInput::confirmationKey($key);
        self::connection($connection,$pdo,1);$session=self::session($connection,$pdo);
        $user=$this->customers()->lockUser($connection,$pdo,$actualUid);
        if(MarketplaceCartSelection::frame($frame,$actualUid)!==$frame)self::fail();self::lockedFrame($actualUid,$frame,$locked);
        $observed=$this->confirmations->readCurrent($actualUid,$key);if($observed['frame']!==$frame)self::fail();
        self::snapshot($frame,$locked,$snapshot);
        $wrappers=$this->assets()->lockSelected($connection,$pdo,$actualUid,$key,$snapshot,$observed['deadline_ms']);
        if($wrappers===[])self::fail();$storage=$this->storage();$objects=[];
        foreach($wrappers as $wrapper){
            $row=$wrapper['row'];$physical=self::physical($row,$actualUid);
            if(self::integer($row['expires_at'],1,4294967295)*1000<=$observed['redis_time_ms'])self::fail();
            $objects[]=['physical'=>$physical,'bytes'=>$storage->readObject($physical)];
        }
        $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
        $current=$this->confirmations->assertSame($actualUid,$key,$observed);self::liveSelected($wrappers,$current);
        foreach($wrappers as $index=>$wrapper)if($this->assetRow($connection,$pdo,$wrapper['row'],1)!==$wrapper['row']
            ||$storage->readObject($objects[$index]['physical'])!==$objects[$index]['bytes'])self::fail();
        // Exactly the existing INSERT/items/reserve/markCreated closure. Its exceptions remain original.
        $out=$createWrites();
        $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
        $root=$this->createdRoot($connection,$pdo,$actualUid,$key,$frame,$snapshot,$out);
        $boundAt=self::integer(time(),1,4294967295);
        $bound=$this->assets()->bindCreated($connection,$pdo,$actualUid,$key,$root,$wrappers,$boundAt);
        if(count($bound)!==count($wrappers))self::fail();
        foreach($bound as $index=>$row){
            $expected=$wrappers[$index]['row'];$expected['state']='BOUND';
            $expected['root_order_id']=self::nativeNumber($pdo,$root['id']);$expected['bound_at']=self::nativeNumber($pdo,$boundAt);
            if($row!==$expected||$row['state']!=='BOUND'||self::physical($row,$actualUid)!==$objects[$index]['physical']
                ||$this->assetRow($connection,$pdo,$row,1)!==$row
                ||$storage->readObject($objects[$index]['physical'])!==$objects[$index]['bytes'])self::fail();
        }
        if($this->createdRoot($connection,$pdo,$actualUid,$key,$frame,$snapshot,$out)!==$root)self::fail();
        $current=$this->confirmations->assertSame($actualUid,$key,$observed);self::liveSelected($wrappers,$current);
        $this->sameOwner($connection,$pdo,$request,$actualUid,$fingerprint,$session,$user,1);
        return $out;
    }

    private function storage():CustomerOrderFormPrivateStorage {return app()->make(CustomerOrderFormPrivateStorage::class);}
    private function assets():CustomerOrderFormAssetDao {return app()->make(CustomerOrderFormAssetDao::class);}
    private function customers():CustomerAssetDao {return app()->make(CustomerAssetDao::class);}
    private function orderDao():MarketplaceOrderDao {return app()->make(MarketplaceOrderDao::class);}
    private function orders():StoreOrderServices {return app()->make(StoreOrderServices::class);}

    /** Only image answers use the new namespace; an unknown version is strict, never a legacy URL. */
    private static function hasPrivateReference(array $snapshot):bool
    {
        foreach($snapshot as $group){
            if(!is_array($group)||!is_array($group['fields']??null))continue;
            foreach($group['fields'] as $field){
                if(!is_array($field)||($field['label']??null)!=='img')continue;
                $values=$field['value']??null;if(is_string($values))$values=[$values];
                if(!is_array($values))continue;
                foreach($values as $value)if(is_string($value)&&str_starts_with($value,'mp-form:'))return true;
            }
        }
        return false;
    }

    private static function lockedFrame(int $uid,array $frame,array $locked):void
    {
        if(($locked['uid']??null)!==$uid)self::fail();
        foreach(['mer_id','source_kind','cart_refs','selection'] as $key)if(($locked[$key]??null)!==$frame[$key])self::fail();
        if(!is_array($locked['products']??null)||MarketplaceOrderCustomForm::definitions($locked['products'],
            $locked['selection'],$locked['source_kind'])!==$frame['custom_form'])self::fail();
    }

    private static function uploadField(array $groups,int $productId,int $fieldIndex):string
    {
        $found=null;
        foreach($groups as $group)if($group['product_id']===$productId)foreach($group['fields'] as $field)
            if($field['field_index']===$fieldIndex){
                if($found!==null||$field['label']!=='img')self::fail();$found=$group['definition_sha256'];
            }
        if(!is_string($found)||preg_match('/\A[a-f0-9]{64}\z/D',$found)!==1)self::fail();return $found;
    }

    private static function snapshot(array $frame,array $locked,array $snapshot):void
    {
        $answers=[];
        foreach($snapshot as $group){
            if(!is_array($group)||!is_array($group['fields']??null))self::fail();$values=[];
            foreach($group['fields'] as $field){
                if(!is_array($field)||!array_key_exists('field_index',$field)||!array_key_exists('value',$field))self::fail();
                $values[]=['field_index'=>$field['field_index'],'value'=>$field['value']];
            }
            $answers[]=['product_id'=>$group['product_id']??null,'definition_sha256'=>$group['definition_sha256']??null,'values'=>$values];
        }
        if(MarketplaceOrderCustomForm::snapshot($frame['custom_form'],$answers,$locked['products'],
            $locked['selection'],$locked['source_kind'])!==$snapshot)self::fail();
    }

    private static function liveSelected(array $wrappers,array $current):void
    {
        foreach($wrappers as $wrapper)if(self::integer($wrapper['row']['expires_at'],1,4294967295)*1000
            <=$current['redis_time_ms'])self::fail();
    }

    private function primary(int $uid):array
    {
        $connection=Db::connect();if(!$connection instanceof Mysql||$connection->transactionDepth()!==0)self::fail();
        $rows=$connection->query('SELECT * FROM '.self::table($connection,'user').' WHERE uid=? LIMIT 2',[$uid],true);
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();self::connection($connection,$pdo,0);
        if(!is_array($rows)||count($rows)!==1)self::fail();self::user($rows[0],$uid);return [$connection,$pdo];
    }

    private function sameOwner(ConnectionInterface $connection,PDO $pdo,Request $request,int $uid,string $fingerprint,
        array $session,array $user,int $depth):void
    {
        self::connection($connection,$pdo,$depth);self::sameIdentity($request,$uid,$fingerprint);
        if(self::session($connection,$pdo)!==$session)self::fail();self::user($user,$uid);
        $rows=self::rows($connection,$pdo,'SELECT * FROM '.self::table($connection,'user').' WHERE uid=? LIMIT 2'
            .($depth===1?' FOR UPDATE':''),[$uid],$depth);
        if($rows!==[$user])self::fail();self::connection($connection,$pdo,$depth);
    }

    private static function user(array $user,int $uid):void
    {
        if(self::integer($user['uid']??null,1,2147483647)!==$uid||self::integer($user['status']??null,0,1)!==1
            ||self::integer($user['is_del']??null,0,1)!==0)self::fail();
    }

    private function assetRow(ConnectionInterface $connection,PDO $pdo,array $row,int $depth):array
    {
        $rows=self::rows($connection,$pdo,'SELECT * FROM '.self::table($connection,'marketplace_customer_asset')
            .' WHERE id=? LIMIT 2'.($depth===1?' FOR UPDATE':''),[self::assetId($row['id'])],$depth);
        if($rows!==[$row])self::fail();return $rows[0];
    }

    private function createdRoot(ConnectionInterface $connection,PDO $pdo,int $uid,string $key,array $frame,
        array $snapshot,array $out):array
    {
        if(array_keys($out)!==['order_id','key','order_pk']||$out['key']!==$key||!is_string($out['order_id'])
            ||$out['order_id']===''||str_contains($out['order_id'],"\0"))self::fail();
        $id=self::integer($out['order_pk'],1,2147483647);
        $rows=self::rows($connection,$pdo,'SELECT * FROM '.self::table($connection,'store_order').' WHERE id=? LIMIT 2 FOR UPDATE',[$id],1);
        if(count($rows)!==1)self::fail();$root=$rows[0];
        if(self::integer($root['id']??null,1,2147483647)!==$id||self::integer($root['uid']??null,1,2147483647)!==$uid
            ||self::integer($root['pid']??null,0,2147483647)!==0||self::integer($root['marketplace_trade_version']??null,1,1)!==1
            ||self::integer($root['mer_id']??null,1,4294967295)!==$frame['mer_id']||($root['unique']??null)!==$key
            ||($root['order_id']??null)!==$out['order_id']||($root['custom_form']??null)!==MarketplaceOrderCustomForm::encoded($snapshot))self::fail();
        return $root;
    }

    /** Normalize only physical DTO numbers; never rewrite any full native DAO row. */
    private static function physical(array $row,int $uid):array
    {
        if(self::integer($row['uid']??null,1,2147483647)!==$uid||!is_string($row['object_key']??null)
            ||!is_string($row['media_type']??null)||!is_string($row['content_sha256']??null)||strlen($row['content_sha256'])!==32)self::fail();
        return ['uid'=>$uid,'object_key'=>$row['object_key'],'media_type'=>$row['media_type'],
            'byte_size'=>self::integer($row['byte_size']??null,1,52428800),'width'=>self::integer($row['width']??null,1,4294967295),
            'height'=>self::integer($row['height']??null,1,4294967295),'content_sha256'=>$row['content_sha256']];
    }

    private static function identity(Request $request,int $uid):string
    {
        if(PHP_INT_SIZE<8||$uid<1||$uid>2147483647||app()->request!==$request||app()->http->getName()!=='api'
            ||$request->method(true)!=='POST'||$request->isLogin()!==true||!in_array($request->uid(),[$uid,(string)$uid],true))self::fail();
        $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();return $fingerprint;
    }
    private static function sameIdentity(Request $request,int $uid,string $fingerprint):void
    {if(self::identity($request,$uid)!==$fingerprint)self::fail();}

    private static function connection(ConnectionInterface $connection,PDO $pdo,int $depth):void
    {
        if(!in_array($depth,[0,1],true)||!$connection instanceof Mysql||Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==($depth===1)
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[true,false,0,1],true))self::fail();
    }

    private static function session(ConnectionInterface $connection,PDO $pdo):array
    {
        if(!$connection instanceof Mysql||Db::connect()!==$connection||$connection->getPdo()!==$pdo)self::fail();
        $statement=$pdo->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute([])!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($statement->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['database_name','authenticated_user','connection_id','autocommit'])self::fail();
        $row=$rows[0];foreach(['database_name','authenticated_user'] as $name)
            if(!is_string($row[$name])||$row[$name]===''||str_contains($row[$name],"\0"))self::fail();
        if($row['database_name']!==$connection->getConfig('database')||!in_array($row['autocommit'],[1,'1'],true))self::fail();
        self::assetId($row['connection_id']);return $row;
    }

    /** Only these three fixed private SELECT leaves exist here; no caller-supplied table or SQL. */
    private static function table(ConnectionInterface $connection,string $table):string
    {
        $prefix=$connection->getConfig('prefix');
        if(!in_array($table,['user','store_order','marketplace_customer_asset'],true)||!is_string($prefix)
            ||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.$table)>64)self::fail();return '`'.$prefix.$table.'`';
    }
    private static function rows(ConnectionInterface $connection,PDO $pdo,string $sql,array $arguments,int $depth):array
    {
        self::connection($connection,$pdo,$depth);$statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::fail();
        try{if($statement->execute($arguments)!==true)self::fail();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($statement->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||!array_is_list($rows))self::fail();self::connection($connection,$pdo,$depth);return $rows;
    }

    private static function assetId(mixed $id):string
    {
        if(is_int($id)&&$id>0)$id=(string)$id;
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
            ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))self::fail();return $id;
    }
    private static function nativeNumber(PDO $pdo,mixed $value):int|string
    {
        $text=self::assetId($value);$strings=$pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        if(!in_array($strings,[true,false,0,1],true))self::fail();
        return in_array($strings,[true,1],true)||strlen($text)>strlen((string)PHP_INT_MAX)
            ||(strlen($text)===strlen((string)PHP_INT_MAX)&&strcmp($text,(string)PHP_INT_MAX)>0)?$text:(int)$text;
    }
    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        if(is_int($value)&&$value>=$minimum&&$value<=$maximum)return $value;
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1||strlen($value)>strlen((string)$maximum)
            ||(strlen($value)===strlen((string)$maximum)&&strcmp($value,(string)$maximum)>0)||(int)$value<$minimum)self::fail();return (int)$value;
    }

    private function uploadedBytes(UploadedFile $file):array
    {
        $path=$file->getPathname();$maximum=Config::get('upload.filesize');
        if(!is_int($maximum)||$maximum<1||!$file->isValid()||!is_uploaded_file($path))self::fail();
        $maximum=min($maximum,52428800);clearstatcache(true,$path);$before=lstat($path);
        if(!is_array($before)||is_link($path)||($before['mode']&0170000)!==0100000||$before['nlink']!==1
            ||!is_int($before['size'])||$before['size']<1||$before['size']>$maximum)self::fail();
        $handle=fopen($path,'rb');if(!is_resource($handle))self::fail();
        try{
            $opened=fstat($handle);
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if(!is_array($opened)||$opened[$key]!==$before[$key])self::fail();
            $bytes='';while(strlen($bytes)<$before['size']){
                $part=fread($handle,min(65536,$before['size']-strlen($bytes)));if(!is_string($part)||$part==='')self::fail();$bytes.=$part;
            }
            if(fread($handle,1)!==''||!feof($handle))self::fail();$after=fstat($handle);clearstatcache(true,$path);$atPath=lstat($path);
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
                if(!is_array($after)||!is_array($atPath)||is_link($path)||$after[$key]!==$before[$key]||$atPath[$key]!==$before[$key])self::fail();
            $name=$file->getOriginalName();if(!is_string($name)||$name==='')self::fail();return [$bytes,$name];
        }finally{if(fclose($handle)!==true)self::fail();}
    }
    private static function counter(mixed $value):int
    {
        if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1&&strlen($value)<=3)$value=(int)$value;
        if(!is_int($value)||$value<0||$value>100)self::fail();return $value;
    }

    /** Internal finite metadata only; never changes the API receipt or the original failure. */
    private static function logPreflightFailure(Throwable $error,string $substage):void
    {
        try{
            if(!in_array($substage,['request_identity','request_envelope','request_file','primary_pdo','transaction_start','transaction_entry','owner_before_lock','lock_user','lookup_confirmation','read_confirmation','lock_parents','cached_frame','locked_frame','field_authorization','owner_confirmation_before_quota','quota_read','uploaded_tempfile','storage_construct','image_qualify','owner_confirmation_before_write','private_write_preflight'],true))return;
            $class=get_class($error);if(strlen($class)>192||preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,191}\z/D',str_replace('\\','_',$class))!==1)$class=null;
            $file=basename(str_replace('\\','/',$error->getFile()));
            if(strlen($file)>96||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\z/D',$file)!==1)$file=null;
            $line=$error->getLine();if($line<1||$line>10000000)$line=null;
            $record=['schema'=>1,'scope'=>'MALL_ORDER_FORM_PREFLIGHT','substage'=>$substage,'class'=>$class,'file'=>$file,'line'=>$line];
            $json=json_encode($record,JSON_UNESCAPED_SLASHES);
            if(is_string($json)&&strlen($json)<=1024)@error_log('MALL_ORDER_FORM_PREFLIGHT '.$json."\n",3,'php://stderr');
        }catch(Throwable $diagnosticError){}
    }

    private static function fail():never {throw new AuthUnavailableException();}
}
