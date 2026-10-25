<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerAssetDao;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use think\facade\Config;
use think\file\UploadedFile;
use Throwable;

/** Named customer-review and avatar producers. Default compatibility and private uses remain unpublished here. */
final class CustomerAssetServices
{
    public function __construct(private MarketplaceOrderReviewServices $reviews,private CustomerAssetDao $assets,private CustomerAssetStorage $storage){}

    /** Named private use; it never reuses a review/root/merchant context or the public upload path. */
    public function uploadAgentLicence(Request $request,int $actualUid,int $ownedApplyId):array
    {return app()->make(CustomerAgentLicenceAssetServices::class)->upload($request,$actualUid,$ownedApplyId);}

    /** Withdrawal QR has its own UID/purpose namespace; no merchant, licence or refund parent. */
    public function uploadWithdrawalQr(Request $request,int $actualUid,string $extractType):array
    {return app()->make(CustomerWithdrawalQrAssetServices::class)->upload($request,$actualUid,$extractType);}

    public function uploadForReview(Request $request,int $actualUid,int $rootId,int $itemId):array
    {
        $receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];$object=[];
        try{
            if($actualUid<1||$actualUid>2147483647||app()->request!==$request||app()->http->getName()!=='api'
                ||$request->isLogin()!==true||!in_array($request->uid(),[$actualUid,(string)$actualUid],true))self::fail();
            $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();
            $out=$this->reviews->withCustomerUpload($request,$rootId,$itemId,function(array $context)use($request,$actualUid,&$receipt,&$object):array{
                MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                if($context['request']!==$request||$context['actual_uid']!==$actualUid)self::fail();
                try{
                    // This runs only after actual paid/received item, full user and review eligibility have been locked.
                    $key='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($key,0));if($count>=100)self::fail();
                    $file=$request->file('file');if(!$file instanceof UploadedFile)self::fail();
                    [$bytes,$name]=$this->uploadedBytes($file);$qualified=$this->storage->qualify($bytes,$name);
                    MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                    $physical=$this->storage->upload($context,$bytes,$name,$object,$receipt);
                    if($receipt['physical_complete']!==true||!in_array($receipt['physical_changed'],[true,null],true))self::fail();
                    $this->storage->assertStored($context,$physical,$qualified,$bytes,$object);
                    $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                    $row=$this->assets->insertForReview($context,$physical,$qualified);
                    $this->storage->assertStored($context,$physical,$qualified,$bytes,$object);
                    $this->assets->assertUploaded($context,$row);MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                    $url=path_to_url($row['object_key']);if(!is_string($url)||$url==='')self::fail();
                    if(strpos($url,'http')===false)$url=$request->domain().$url;
                    $dto=['name'=>$row['name'],'url'=>$url,'attachment_ref'=>$row['object_key']];
                    $receipt['phase']='commit_outcome_unknown';return ['asset'=>$row,'dto'=>$dto];
                }catch(Throwable $error){
                    // Only an actual new physical object and positively no attempted DML permit cleanup.
                    if($receipt['database_committed']===false&&$object!==[]){
                        try{$this->storage->cleanup($context,$object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
                    }
                    throw $error;
                }
            });
            // withCustomerUpload has observed the actual outer transaction return, outside PDO and current identity.
            $receipt['database_committed']=true;$receipt['phase']='quota_update';
            if(app()->request!==$request||app()->http->getName()!=='api'||$request->isLogin()!==true
                ||!in_array($request->uid(),[$actualUid,(string)$actualUid],true))self::fail();
            $key='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($key,0));if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||self::counter(CacheService::get($key,null))!==$count)self::fail();
            if(app()->request!==$request||app()->http->getName()!=='api'||$request->isLogin()!==true
                ||!in_array($request->uid(),[$actualUid,(string)$actualUid],true)||$request->verifiedTokenFingerprint()!==$fingerprint)self::fail();
            $receipt['complete']=true;$receipt['phase']='complete';return $out['dto'];
        }catch(Throwable $error){throw new MerchantAttachmentOperationException($receipt);}
    }


    /** A real avatar frame is separate from the order-review upload frame. */
    private static ?array $avatarFrame=null;
    private static ?array $avatarDatabaseSession=null;

    public function uploadAvatar(Request $request,int $actualUid):array
    {
        $receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];$object=[];
        try{
            $fingerprint=self::avatarIdentity($request,$actualUid);
            if($request->method(true)!=='POST'||strtolower($request->contentType())!=='multipart/form-data'
                ||$request->rule()->getOption('customer_avatar_files')!==true||$request->get(false)!==[]
                ||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $files=$request->file();if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            [$connection,$pdo]=$this->assets->avatarConnection($actualUid);$databaseSession=self::avatarSession($connection,$pdo);$published=false;
            $out=$connection->transaction(function(\think\db\ConnectionInterface $actual)use($connection,$pdo,$request,$actualUid,$fingerprint,$databaseSession,&$published,&$receipt,&$object):array{
                if($actual!==$connection||$published||self::$avatarFrame!==null)self::fail();$published=true;
                self::avatarConnection($connection,$pdo,1);
                $user=$this->assets->lockUser($connection,$pdo,$actualUid);$this->assets->assertStructure($connection,$pdo);
                $context=['request'=>$request,'actual_uid'=>$actualUid,'user'=>$user,'connection'=>$connection,'pdo'=>$pdo,
                    'session_sha256'=>$fingerprint,'mode'=>'upload','asset_id'=>null];
                self::$avatarFrame=$context;self::$avatarDatabaseSession=$databaseSession;
                try{
                    self::assertAvatarContext($context,'upload');
                    $count=self::counter(CacheService::get('start_uploads_'.$actualUid,0));if($count>=100)self::fail();
                    [$bytes,$name]=$this->uploadedBytes($request->file('pics'));$qualified=$this->storage->qualify($bytes,$name);
                    $physical=$this->storage->uploadForAvatar($context,$bytes,$name,$object,$receipt);
                    if($receipt['physical_complete']!==true||!in_array($receipt['physical_changed'],[true,null],true))self::fail();
                    $this->storage->assertAvatarUploaded($context,$physical,$qualified,$bytes,$object);
                    $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                    $asset=$this->assets->insertAvatar($context,$physical,$qualified);
                    $this->storage->assertAvatarUploaded($context,$physical,$qualified,$bytes,$object);
                    $this->assets->assertAvatarCurrent($context,$asset);
                    if($this->assets->lockUser($connection,$pdo,$actualUid)!==$user)self::fail();
                    $url=path_to_url($asset['object_key']);if(!is_string($url)||$url==='')self::fail();
                    if(strpos($url,'http')===false)$url=$request->domain().$url;
                    $dto=['name'=>$asset['name'],'url'=>$url,'attachment_ref'=>$asset['object_key'],
                        'avatar_asset_id'=>self::avatarId((string)$asset['id'])];
                    self::assertAvatarContext($context,'upload');$receipt['phase']='commit_outcome_unknown';
                    return ['asset'=>$asset,'user'=>$user,'dto'=>$dto];
                }catch(Throwable $error){
                    if($receipt['database_committed']===false&&$object!==[]){
                        try{$this->storage->cleanupAvatar($context,$object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
                    }
                    throw $error;
                }finally{self::$avatarFrame=null;self::$avatarDatabaseSession=null;}
            });
            $receipt['database_committed']=true;self::avatarConnection($connection,$pdo,0);
            if(self::avatarSession($connection,$pdo)!==$databaseSession||self::avatarIdentity($request,$actualUid)!==$fingerprint)self::fail();
            $this->assets->assertAvatarCommitted($connection,$pdo,$out['asset'],$out['user']);
            $receipt['phase']='quota_update';$key='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($key,0));
            if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||self::counter(CacheService::get($key,null))!==$count)self::fail();
            if(self::avatarIdentity($request,$actualUid)!==$fingerprint)self::fail();
            $receipt['complete']=true;$receipt['phase']='complete';return $out['dto'];
        }catch(Throwable $error){self::$avatarFrame=null;self::$avatarDatabaseSession=null;throw new MerchantAttachmentOperationException($receipt);}
    }

    /** The callback is only the original user-profile ORM writer, not another transaction owner. */
    public function saveAvatarProfile(Request $request,int $actualUid,mixed $assetId,array $data,callable $profileWriter):bool
    {
        $receipt=['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];
        try{
            $fingerprint=self::avatarIdentity($request,$actualUid);$assetId=self::avatarId($assetId);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_avatar_profile')!==true
                ||array_keys($data)!==['avatar','nickname']||!is_string($data['avatar'])||$data['avatar']===''
                ||!is_string($data['nickname'])||str_contains($data['avatar'],"\0")||str_contains($data['nickname'],"\0")
                ||preg_match('//u',$data['avatar'])!==1||preg_match('//u',$data['nickname'])!==1
                ||mb_strlen($data['avatar'],'UTF-8')>255||mb_strlen($data['nickname'],'UTF-8')>64)self::fail();
            [$connection,$pdo]=$this->assets->avatarConnection($actualUid);$databaseSession=self::avatarSession($connection,$pdo);$published=false;
            $out=$connection->transaction(function(\think\db\ConnectionInterface $actual)use($connection,$pdo,$request,$actualUid,$fingerprint,$assetId,$data,$profileWriter,$databaseSession,&$published,&$receipt):array{
                if($actual!==$connection||$published||self::$avatarFrame!==null)self::fail();$published=true;
                self::avatarConnection($connection,$pdo,1);$user=$this->assets->lockUser($connection,$pdo,$actualUid);
                $this->assets->assertStructure($connection,$pdo);
                $context=['request'=>$request,'actual_uid'=>$actualUid,'user'=>$user,'connection'=>$connection,'pdo'=>$pdo,
                    'session_sha256'=>$fingerprint,'mode'=>'profile','asset_id'=>$assetId];
                self::$avatarFrame=$context;self::$avatarDatabaseSession=$databaseSession;
                try{
                    self::assertAvatarContext($context,'profile');$asset=$this->assets->lockAvatar($context,$assetId);
                    $physical=$this->storage->verifyAvatarSelection($context,$asset);
                    if($physical['url']!==$data['avatar']||($asset['state']==='BOUND'&&$user['avatar']!==$data['avatar']))self::fail();
                    $expectedUser=$user;$expectedUser['avatar']=$data['avatar'];$expectedUser['nickname']=$data['nickname'];
                    $receipt['phase']='profile_write';$receipt['database_committed']=null;
                    if($profileWriter($connection,$pdo,$actualUid,$data)!==true)self::fail();
                    self::assertAvatarContext($context,'profile');
                    if($this->assets->lockUser($connection,$pdo,$actualUid)!==$expectedUser)self::fail();
                    $bound=$this->assets->bindAvatar($context,$asset);
                    if($this->storage->verifyAvatarSelection($context,$bound)!==$physical)self::fail();
                    $this->assets->assertAvatarCurrent($context,$bound);self::assertAvatarContext($context,'profile');
                    $receipt['phase']='commit_outcome_unknown';return ['asset'=>$bound,'user'=>$expectedUser];
                }finally{self::$avatarFrame=null;self::$avatarDatabaseSession=null;}
            });
            $receipt['database_committed']=true;self::avatarConnection($connection,$pdo,0);
            if(self::avatarSession($connection,$pdo)!==$databaseSession||self::avatarIdentity($request,$actualUid)!==$fingerprint)self::fail();
            $this->assets->assertAvatarCommitted($connection,$pdo,$out['asset'],$out['user']);
            $receipt['complete']=true;$receipt['phase']='complete';return true;
        }catch(Throwable $error){self::$avatarFrame=null;self::$avatarDatabaseSession=null;throw new MerchantAttachmentOperationException($receipt);}
    }

    /** Only the private current frame created by these named operations can authorize an avatar leaf. */
    public static function assertAvatarContext(array $context,string $mode):void
    {
        if(self::$avatarFrame===null||$context!==self::$avatarFrame||!in_array($mode,['upload','profile'],true)
            ||$context['mode']!==$mode||self::$avatarDatabaseSession===null)self::fail();
        $request=$context['request'];$connection=$context['connection'];$pdo=$context['pdo'];
        if(!$request instanceof Request||!$connection instanceof \think\db\ConnectionInterface||!$pdo instanceof \PDO
            ||self::avatarIdentity($request,$context['actual_uid'])!==$context['session_sha256']
            ||!is_array($context['user'])||!in_array($context['user']['uid']??null,[$context['actual_uid'],(string)$context['actual_uid']],true)
            ||$request->rule()->getOption($mode==='upload'?'customer_avatar_files':'customer_avatar_profile')!==true
            ||$request->method(true)!=='POST')self::fail();
        if(($mode==='upload'&&$context['asset_id']!==null)||($mode==='profile'&&self::avatarId($context['asset_id'])!==$context['asset_id']))self::fail();
        self::avatarConnection($connection,$pdo,1);
        if(self::avatarSession($connection,$pdo)!==self::$avatarDatabaseSession)self::fail();
    }

    /** Finite seam for the actual original UserDao writer, never a caller-supplied generic owner. */
    public static function assertAvatarProfileWriter(\think\db\ConnectionInterface $connection,\PDO $pdo,int $actualUid):void
    {
        if(self::$avatarFrame===null)self::fail();self::assertAvatarContext(self::$avatarFrame,'profile');
        if(self::$avatarFrame['connection']!==$connection||self::$avatarFrame['pdo']!==$pdo
            ||self::$avatarFrame['actual_uid']!==$actualUid)self::fail();
    }

    private static function avatarIdentity(Request $request,int $actualUid):string
    {
        if(PHP_INT_SIZE<8||$actualUid<1||$actualUid>4294967295||app()->request!==$request||app()->http->getName()!=='api'
            ||$request->isLogin()!==true||!in_array($request->uid(),[$actualUid,(string)$actualUid],true))self::fail();
        $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();return $fingerprint;
    }

    private static function avatarId(mixed $id):string
    {
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
            ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))self::fail();return $id;
    }

    private static function avatarConnection(\think\db\ConnectionInterface $connection,\PDO $pdo,int $depth):void
    {
        if(!$connection instanceof \crmeb\compat\think\orm\db\connector\Mysql
            ||\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$connection->transactionDepth()!==$depth||$pdo->inTransaction()!==($depth===1)
            ||$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(\PDO::ATTR_ERRMODE)!==\PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES),[false,0],true))self::fail();
    }

    private static function avatarSession(\think\db\ConnectionInterface $connection,\PDO $pdo):array
    {
        if(!$connection instanceof \crmeb\compat\think\orm\db\connector\Mysql||\think\facade\Db::connect()!==$connection
            ||$connection->getPdo()!==$pdo)self::fail();
        $s=$pdo->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$s instanceof \PDOStatement||$s->execute([])!==true)self::fail();$rows=$s->fetchAll(\PDO::FETCH_ASSOC);
        if($s->closeCursor()!==true||!is_array($rows)||count($rows)!==1
            ||array_keys($rows[0])!==['database_name','authenticated_user','connection_id','autocommit'])self::fail();$row=$rows[0];
        foreach(['database_name','authenticated_user']as$key)if(!is_string($row[$key])||$row[$key]===''||str_contains($row[$key],"\0"))self::fail();
        if(!in_array($row['autocommit'],[1,'1'],true)||(!is_int($row['connection_id'])&&!is_string($row['connection_id']))
            ||preg_match('/\A[1-9][0-9]*\z/D',(string)$row['connection_id'])!==1)self::fail();return $row;
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
    private static function fail():never {throw new AuthUnavailableException();}
}
