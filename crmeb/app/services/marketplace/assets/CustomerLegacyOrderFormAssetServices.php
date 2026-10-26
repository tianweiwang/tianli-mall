<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerAssetDao;
use app\dao\marketplace\assets\CustomerLegacyOrderFormAssetDao;
use app\dao\marketplace\assets\CustomerOrderFormAssetDao;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\CustomerOrderFormConfirmationReader;
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
use think\Model;
use Throwable;

/** Named legacy first-cart form upload and same-writer binding; no V1 caller or merchant context. */
final class CustomerLegacyOrderFormAssetServices
{
    public function __construct(private CustomerOrderFormConfirmationReader $confirmations,
        private CustomerLegacyOrderFormAssetDao $legacy,private CustomerAssetDao $customers,
        private CustomerOrderFormAssetDao $metadata){}

    public function uploadForConfirmation(Request $request,int $actualUid,string $key,int $productId,int $fieldIndex):array
    {
        $receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];$storage=null;$object=[];
        try{
            $fingerprint=self::identity($request,$actualUid);$key=MarketplaceOrderInput::confirmationKey($key);
            if($request->rule()->getOption('legacy_order_form_files')!==true||$productId<1||$productId>8388607||$fieldIndex<0||$fieldIndex>9
                ||strtolower($request->contentType())!=='multipart/form-data'||$request->get(false)!==[]
                ||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $files=$request->file();
            if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            [$c,$p]=$this->customers->avatarConnection($actualUid);$session=self::session($c,$p);$entered=false;
            $out=$c->transaction(function(ConnectionInterface $actual)use($c,$p,$request,$actualUid,$key,$productId,$fieldIndex,
                $fingerprint,$session,$files,&$entered,&$receipt,&$storage,&$object):array{
                if($actual!==$c||$entered)self::fail();$entered=true;
                $user=$this->customers->lockUser($c,$p,$actualUid);$this->owner($c,$p,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->legacy->assertUncreated($c,$p,$actualUid,$key);
                $observed=$this->confirmations->readLegacyCurrent($actualUid,$key);$frame=$observed['frame'];
                $this->currentCart($actualUid,$key,$frame);
                if($frame['product_id']!==$productId||($frame['fields'][$fieldIndex]['label']??null)!=='img')self::fail();
                if(self::counter(CacheService::get('start_uploads_'.$actualUid,0))>=100)self::fail();
                [$bytes,$name]=$this->uploadedBytes($files['pics']);
                $storage=app()->make(CustomerOrderFormPrivateStorage::class);$qualified=$storage->inspectUpload($bytes,$name);
                $this->owner($c,$p,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertLegacySame($actualUid,$key,$observed);
                $physical=$storage->writeNew($actualUid,$observed['identity']['confirmation_ref'],$observed['deadline_ms'],$bytes,$name,$object,$receipt);
                if($receipt['physical_changed']!==true||$receipt['physical_complete']!==true
                    ||$storage->readObject($physical)!==$bytes||$physical['content_sha256']!==$qualified['content_sha256'])self::fail();
                $this->owner($c,$p,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertLegacySame($actualUid,$key,$observed);
                $this->legacy->assertUncreated($c,$p,$actualUid,$key);
                $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                $asset=$this->metadata->insertStaged($c,$p,$actualUid,$key,$productId,$frame['definition_sha256'],$fieldIndex,
                    $physical,$name,time(),$observed['deadline_ms']);
                if(self::physical($asset,$actualUid)!==$physical||$storage->readObject($physical)!==$bytes)self::fail();
                $this->legacy->assertRows($c,$p,[$asset]);$this->owner($c,$p,$request,$actualUid,$fingerprint,$session,$user,1);
                $this->confirmations->assertLegacySame($actualUid,$key,$observed);$receipt['phase']='commit_outcome_unknown';
                return compact('asset','user','physical','bytes','observed');
            });
            if(!$entered||!$storage instanceof CustomerOrderFormPrivateStorage)self::fail();
            $this->owner($c,$p,$request,$actualUid,$fingerprint,$session,$out['user'],0);
            $this->metadata->assertStagedCommitted($c,$p,$actualUid,$out['asset']);
            if($storage->readObject($out['physical'])!==$out['bytes'])self::fail();
            $this->confirmations->assertLegacySame($actualUid,$key,$out['observed']);$receipt['database_committed']=true;$receipt['phase']='quota_update';
            $quota='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($quota,0));if($count>=100)self::fail();$count++;
            if(CacheService::set($quota,$count,86400)!==true||self::counter(CacheService::get($quota,null))!==$count)self::fail();
            if(self::identity($request,$actualUid)!==$fingerprint)self::fail();
            $id=CustomerLegacyOrderFormAssetDao::assetId($out['asset']['id']);$domain=$request->domain();
            if(!is_string($domain)||preg_match('~\Ahttps?://[^/?#\s]+\z~D',$domain)!==1)self::fail();
            $dto=['name'=>$out['asset']['name'],'url'=>$domain.'/api/order/form/legacy/confirm/'.rawurlencode($key).'/files/'.$id.'/content',
                'attachment_ref'=>'legacy-form:v1:'.$id];
            $receipt['complete']=true;$receipt['phase']='complete';return $dto;
        }catch(Throwable $error){
            if($receipt['database_committed']===false&&$object!==[]&&$storage instanceof CustomerOrderFormPrivateStorage){
                try{$storage->cleanupNew($object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Owns no transaction: exactly the original order/coupon/integral/stock/cart writer remains the callback. */
    public function withCreateAssets(ConnectionInterface $c,PDO $p,int $uid,string $key,array $cartInfo,array $form,
        callable $originalWriter,?array &$outcome):Model
    {
        if(!CustomerLegacyOrderFormAssetDao::hasReferences($form))return $originalWriter();
        $stage='identity';
        try{
            $request=app()->request;if(!$request instanceof Request)self::fail();$fingerprint=self::identity($request,$uid);
            $key=MarketplaceOrderInput::confirmationKey($key);self::connection($c,$p,1);$session=self::session($c,$p);
            $stage='owner_confirmation';
            $user=$this->customers->lockUser($c,$p,$uid);$this->legacy->assertUncreated($c,$p,$uid,$key);
            $observed=$this->confirmations->readLegacyCurrent($uid,$key);
            $stage='cached_definition';
            if(!array_is_list($cartInfo)||$cartInfo===[]||CustomerOrderFormConfirmationReader::legacyDefinition($cartInfo[0],$uid)!==$observed['frame'])self::fail();
            $stage='selected_assets';
            $selected=$this->legacy->lockSelected($c,$p,$uid,$key,$observed,$form);if($selected===[])self::fail();
            $stage='private_bytes';
            $storage=app()->make(CustomerOrderFormPrivateStorage::class);$objects=[];
            foreach($selected as$wrapper){$physical=self::physical($wrapper['row'],$uid);$objects[]=['physical'=>$physical,'bytes'=>$storage->readObject($physical)];}
            $this->owner($c,$p,$request,$uid,$fingerprint,$session,$user,1);
            if($this->legacy->lockSelected($c,$p,$uid,$key,$observed,$form)!==$selected)self::fail();
            $stage='original_writer';
            $out=$originalWriter();
            if(!$out instanceof Model)self::fail();$orderId=self::integer($out->getData('id'),1,4294967295);
            // The legacy writer legitimately updates real_name, record_phone and integral; reread actual active identity.
            $afterUser=$this->customers->lockUser($c,$p,$uid);$this->owner($c,$p,$request,$uid,$fingerprint,$session,$afterUser,1);
            $stage='bind_created';
            $bound=$this->legacy->bindCreated($c,$p,$uid,$key,$orderId,$observed,$form,$selected,time());
            $stage='bound_observation';
            if(count($bound)!==count($objects))self::fail();
            foreach($bound as$index=>$row)if(self::physical($row,$uid)!==$objects[$index]['physical']
                ||$storage->readObject($objects[$index]['physical'])!==$objects[$index]['bytes'])self::fail();
            $this->confirmations->assertLegacySame($uid,$key,$observed);
            $this->owner($c,$p,$request,$uid,$fingerprint,$session,$afterUser,1);
            $outcome=['connection'=>$c,'pdo'=>$p,'request'=>$request,'uid'=>$uid,'fingerprint'=>$fingerprint,
                'session'=>$session,'user'=>$afterUser,'bound'=>$bound,'storage'=>$storage,'objects'=>$objects];
            return $out;
        }catch(Throwable $error){self::logCreateFailure($stage,$error);throw $error;}
    }

    /** Called after the original owning transaction returns and before the unchanged post-create events. */
    public function assertCreateCommitted(array $outcome):void
    {
        $c=$outcome['connection'];$p=$outcome['pdo'];$uid=$outcome['uid'];
        $this->owner($c,$p,$outcome['request'],$uid,$outcome['fingerprint'],$outcome['session'],$outcome['user'],0);
        $this->legacy->assertRows($c,$p,$outcome['bound'],true);
        foreach($outcome['objects']as$object)if($outcome['storage']->readObject($object['physical'])!==$object['bytes'])self::fail();
    }

    /** Current original eligibility is only checked; it cannot replace the frozen definition returned to the customer. */
    private function currentCart(int $uid,string $key,array $frame):void
    {
        $current=app()->make(StoreOrderServices::class)->getCacheOrderInfo($uid,$key);
        if(!is_array($current)||!is_array($current['cartInfo']??null)||$current['cartInfo']===[]
            ||CustomerOrderFormConfirmationReader::legacyDefinition($current['cartInfo'][0],$uid)!==$frame)self::fail();
    }
    private function owner(ConnectionInterface $c,PDO $p,Request $request,int $uid,string $fingerprint,array $session,array $user,int $depth):void
    {
        self::connection($c,$p,$depth);
        if(self::identity($request,$uid)!==$fingerprint||self::session($c,$p)!==$session)self::fail();
        $prefix=$c->getConfig('prefix');if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'user')>64)self::fail();
        $s=$p->prepare('SELECT * FROM `'.$prefix.'user` WHERE uid=? LIMIT 2'.($depth===1?' FOR UPDATE':''));
        if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute([$uid])!==true||$s->fetchAll(PDO::FETCH_ASSOC)!==[$user])self::fail();}
        finally{if($s->closeCursor()!==true)self::fail();}
        if(self::integer($user['uid'],1,2147483647)!==$uid||self::integer($user['status'],0,1)!==1||self::integer($user['is_del'],0,1)!==0)self::fail();
        self::connection($c,$p,$depth);
    }
    private static function physical(array $row,int $uid):array
    {
        if(self::integer($row['uid'],1,2147483647)!==$uid||!is_string($row['object_key'])||!is_string($row['media_type'])
            ||!is_string($row['content_sha256'])||strlen($row['content_sha256'])!==32)self::fail();
        return ['uid'=>$uid,'object_key'=>$row['object_key'],'media_type'=>$row['media_type'],
            'byte_size'=>self::integer($row['byte_size'],1,52428800),'width'=>self::integer($row['width'],1,4294967295),
            'height'=>self::integer($row['height'],1,4294967295),'content_sha256'=>$row['content_sha256']];
    }
    private static function identity(Request $request,int $uid):string
    {
        if(PHP_INT_SIZE<8||$uid<1||$uid>2147483647||app()->request!==$request||app()->http->getName()!=='api'
            ||$request->method(true)!=='POST'||$request->isLogin()!==true||!in_array($request->uid(),[$uid,(string)$uid],true))self::fail();
        $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();return $fingerprint;
    }
    private static function connection(ConnectionInterface $c,PDO $p,int $depth):void
    {
        if(!$c instanceof Mysql||Db::connect()!==$c||$c->getPdo()!==$p||$c->getConfig('deploy')!==0||$c->getConfig('rw_separate')!==false
            ||!in_array($depth,[0,1],true)||$c->transactionDepth()!==$depth||$p->inTransaction()!==($depth===1)
            ||$p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$p->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($p->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!in_array($p->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),[false,true,0,1],true))self::fail();
    }
    private static function session(ConnectionInterface $c,PDO $p):array
    {
        if(!$c instanceof Mysql||Db::connect()!==$c||$c->getPdo()!==$p)self::fail();
        $s=$p->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute([])!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);}finally{if($s->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['database_name','authenticated_user','connection_id','autocommit'])self::fail();
        $row=$rows[0];if($row['database_name']!==$c->getConfig('database')||!is_string($row['authenticated_user'])
            ||$row['authenticated_user']===''||!in_array($row['autocommit'],[1,'1'],true))self::fail();
        CustomerLegacyOrderFormAssetDao::assetId($row['connection_id']);return $row;
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
    private static function integer(mixed $value,int $minimum,int $maximum):int
    {
        if(is_int($value)&&$value>=$minimum&&$value<=$maximum)return $value;
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1||strlen($value)>strlen((string)$maximum)
            ||(strlen($value)===strlen((string)$maximum)&&strcmp($value,(string)$maximum)>0)||(int)$value<$minimum)self::fail();return (int)$value;
    }
    /** Finite source metadata only; no request values, token, cache keys, file contents or SQL parameters. */
    private static function logCreateFailure(string $stage,Throwable $error):void
    {
        try{
            if(!in_array($stage,['identity','owner_confirmation','cached_definition','selected_assets','private_bytes',
                'original_writer','bind_created','bound_observation'],true))return;
            $file=basename(str_replace('\\','/',$error->getFile()));$line=$error->getLine();
            $origin=$error->getTrace()[0]??[];$originFile=basename(str_replace('\\','/',(string)($origin['file']??'')));
            $allowed=['CustomerLegacyOrderFormAssetServices.php','CustomerLegacyOrderFormAssetDao.php',
                'CustomerOrderFormConfirmationReader.php','CustomerAssetDao.php','CustomerOrderFormPrivateStorage.php','StoreOrderCreateServices.php'];
            if(!in_array($file,$allowed,true))$file=null;
            if(!in_array($originFile,$allowed,true)){$originFile=null;$originLine=null;}else $originLine=$origin['line']??null;
            $record=['scope'=>'MALL_LEGACY_FORM_CREATE','stage'=>$stage,'file'=>$file,'line'=>$file===null?null:$line,
                'origin_file'=>$originFile,'origin_line'=>is_int($originLine)?$originLine:null];
            $json=json_encode($record,JSON_UNESCAPED_SLASHES);
            if(is_string($json)&&strlen($json)<=1024)@error_log('MALL_LEGACY_FORM_CREATE '.$json."\n",3,'php://stderr');
        }catch(Throwable $ignored){}
    }
    private static function fail():never{throw new AuthUnavailableException();}
}
