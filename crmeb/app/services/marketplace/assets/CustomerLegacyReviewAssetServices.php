<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerLegacyReviewAssetDao as Assets;
use app\model\product\product\StoreProductReply;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\file\UploadedFile;
use think\Model;
use Throwable;

/** Named old-review upload and the original review writer, with one actual primary transaction. */
final class CustomerLegacyReviewAssetServices
{
    private static ?array $frame=null;
    private static ?array $databaseSession=null;
    public function __construct(private Assets $assets,private CustomerAssetStorage $storage){}

    public function upload(Request $request,int $actualUid,string $unique):array
    {
        $receipt=self::receipt();$object=[];
        try{
            self::identity($request,$actualUid);Assets::unique($unique);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_legacy_review_files')!==true
                ||strtolower($request->contentType())!=='multipart/form-data'||$request->get(false)!==[]
                ||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $files=$request->file();if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            $out=$this->withFrame($request,$actualUid,$unique,'upload',function(array $context)use(&$receipt,&$object,$request,$actualUid):array{
                try{
                    $count=self::counter(CacheService::get('start_uploads_'.$actualUid,0));if($count>=100)self::fail();
                    [$bytes,$name]=$this->uploadedBytes($request->file('pics'));$qualified=$this->storage->qualify($bytes,$name);
                    $physical=$this->storage->uploadForLegacyReview($context,$bytes,$name,$object,$receipt);
                    if($receipt['physical_complete']!==true||!in_array($receipt['physical_changed'],[true,null],true))self::fail();
                    $this->storage->assertLegacyReviewStored($context,$physical,$qualified,$bytes,$object);
                    $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                    $asset=$this->assets->insert($context,$physical,$qualified);
                    $this->storage->assertLegacyReviewStored($context,$physical,$qualified,$bytes,$object);
                    $this->assets->assertAsset($context,$asset);$this->assets->assertTarget($context);
                    $url=path_to_url($asset['object_key']);if(!is_string($url)||$url==='')self::fail();
                    if(strpos($url,'http')===false)$url=$request->domain().$url;
                    $receipt['phase']='commit_outcome_unknown';
                    return ['assets'=>[$asset],'review'=>null,'dto'=>['name'=>$asset['name'],'url'=>$url,
                        'attachment_ref'=>$asset['object_key'],'review_asset_id'=>Assets::assetId((string)$asset['id'])]];
                }catch(Throwable $error){
                    if($receipt['database_committed']===false&&$object!==[]){
                        try{$this->storage->cleanupLegacyReview($context,$object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
                    }
                    throw $error;
                }
            },$receipt);
            $receipt['phase']='quota_update';$key='start_uploads_'.$actualUid;$count=self::counter(CacheService::get($key,0));
            if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||self::counter(CacheService::get($key,null))!==$count)self::fail();
            if(self::identity($request,$actualUid)!==$out['fingerprint'])self::fail();
            $receipt['complete']=true;$receipt['phase']='complete';return $out['dto'];
        }catch(Throwable $error){throw new MerchantAttachmentOperationException($receipt);}
    }

    /** Called only for a nonempty new asset list; original no-image/URL writes stay outside this service. */
    public function save(Request $request,int $uid,string $unique,array $preparedOriginalGroup,array $assetIds,callable $originalWriter):Model
    {
        $receipt=self::receipt();
        try{
            self::identity($request,$uid);Assets::unique($unique);$assetIds=Assets::assetIds($assetIds);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_legacy_review_save')!==true)self::fail();
            $out=$this->withFrame($request,$uid,$unique,'save',function(array $context)use($preparedOriginalGroup,$assetIds,$originalWriter,&$receipt):array{
                $this->assets->prepared($context,$preparedOriginalGroup);$assets=$this->assets->select($context,$assetIds);$proof=[];
                try{$pictures=json_decode($preparedOriginalGroup['pics'],true,16,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
                if(!is_array($pictures)||!array_is_list($pictures)||count($pictures)!==count($assets))self::fail();
                foreach($assets as$position=>$asset){
                    $proof[$position]=$this->storage->verifyLegacyReviewSelection($context,$asset);
                    if(!is_string($pictures[$position])||!in_array($pictures[$position],[$asset['object_key'],$proof[$position]['url']],true))self::fail();
                }
                $connection=$context['connection'];$pdo=$context['pdo'];
                if((new StoreProductReply())->db()->getConnection()!==$connection)self::fail();
                $this->assets->assertTarget($context);self::assertContext($context,'save');
                $receipt['phase']='review_write';$receipt['database_committed']=null;
                $model=$originalWriter($connection,$pdo,$preparedOriginalGroup);
                if(!$model instanceof StoreProductReply||$model->db()->getConnection()!==$connection)self::fail();
                self::assertContext($context,'save');
                $bound=$this->assets->bind($context,$assets,$preparedOriginalGroup,$model);
                foreach($bound['assets']as$position=>$asset)
                    if($this->storage->verifyLegacyReviewSelection($context,$asset)!==$proof[$position])self::fail();
                $this->assets->assertTarget($context);$receipt['phase']='commit_outcome_unknown';
                return $bound+['model'=>$model];
            },$receipt);
            $receipt['complete']=true;$receipt['phase']='complete';return $out['model'];
        }catch(Throwable $error){throw new MerchantAttachmentOperationException($receipt);}
    }

    private function withFrame(Request $request,int $uid,string $unique,string $mode,callable $work,array &$receipt):array
    {
        $fingerprint=self::identity($request,$uid);[$connection,$pdo]=$this->assets->connection($uid);
        $session=self::session($connection,$pdo);$published=false;
        $out=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$uid,$unique,$mode,$work,$fingerprint,$session,&$published):array{
            if($actual!==$connection||$published||self::$frame!==null)self::fail();$published=true;
            $this->assets->native($connection,$pdo,1);$target=$this->assets->target($connection,$pdo,$uid,$unique);
            $context=['request'=>$request,'actual_uid'=>$uid,'unique'=>$unique,'target'=>$target,'connection'=>$connection,
                'pdo'=>$pdo,'session_sha256'=>$fingerprint,'mode'=>$mode];
            self::$frame=$context;self::$databaseSession=$session;
            try{self::assertContext($context,$mode);$out=$work($context);self::assertContext($context,$mode);return $out;}
            finally{self::$frame=null;self::$databaseSession=null;}
        });
        $receipt['database_committed']=true;$this->assets->native($connection,$pdo,0);
        if(self::session($connection,$pdo)!==$session||self::identity($request,$uid)!==$fingerprint)self::fail();
        $this->assets->committed($connection,$pdo,$out['assets'],$out['review']);return $out+['fingerprint'=>$fingerprint];
    }

    /** Only the private frame created above can authorize a physical or metadata legacy-review leaf. */
    public static function assertContext(array $context,string $mode):void
    {
        if(self::$frame===null||$context!==self::$frame||self::$databaseSession===null
            ||!in_array($mode,['upload','save'],true)||$context['mode']!==$mode)self::fail();
        $request=$context['request'];$connection=$context['connection'];$pdo=$context['pdo'];
        if(!$request instanceof Request||!$connection instanceof ConnectionInterface||!$pdo instanceof PDO
            ||self::identity($request,$context['actual_uid'])!==$context['session_sha256']||$request->method(true)!=='POST'
            ||$request->rule()->getOption($mode==='upload'?'customer_legacy_review_files':'customer_legacy_review_save')!==true)self::fail();
        app()->make(Assets::class)->native($connection,$pdo,1);
        if(self::session($connection,$pdo)!==self::$databaseSession)self::fail();
    }

    private static function identity(Request $request,int $uid):string
    {
        if(PHP_INT_SIZE<8||$uid<1||$uid>4294967295||app()->request!==$request||app()->http->getName()!=='api'
            ||$request->isLogin()!==true||!in_array($request->uid(),[$uid,(string)$uid],true))self::fail();
        $fingerprint=$request->verifiedTokenFingerprint();if(preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();return $fingerprint;
    }
    private static function session(ConnectionInterface $connection,PDO $pdo):array
    {
        if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo)self::fail();
        $s=$pdo->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$s instanceof \PDOStatement)self::fail();
        try{if($s->execute([])!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($s->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['database_name','authenticated_user','connection_id','autocommit'])self::fail();
        foreach(['database_name','authenticated_user']as$key)if(!is_string($rows[0][$key])||$rows[0][$key]===''||str_contains($rows[0][$key],"\0"))self::fail();
        Assets::integer($rows[0]['connection_id'],1,PHP_INT_MAX);
        if(!in_array($rows[0]['autocommit'],[1,'1'],true))self::fail();return $rows[0];
    }
    private function uploadedBytes(UploadedFile $file):array
    {
        $path=$file->getPathname();$maximum=Config::get('upload.filesize');
        if(!is_int($maximum)||$maximum<1||!$file->isValid()||!is_uploaded_file($path))self::fail();$maximum=min($maximum,52428800);
        clearstatcache(true,$path);$before=lstat($path);
        if(!is_array($before)||is_link($path)||($before['mode']&0170000)!==0100000||$before['nlink']!==1
            ||!is_int($before['size'])||$before['size']<1||$before['size']>$maximum)self::fail();
        $handle=fopen($path,'rb');if(!is_resource($handle))self::fail();
        try{
            $opened=fstat($handle);foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
                if(!is_array($opened)||$opened[$key]!==$before[$key])self::fail();
            $bytes=stream_get_contents($handle,$maximum+1);
            clearstatcache(true,$path);$after=fstat($handle);$atPath=lstat($path);
            if(!is_string($bytes)||strlen($bytes)!==$before['size']||strlen($bytes)>$maximum||!feof($handle))self::fail();
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
                if(!is_array($after)||!is_array($atPath)||is_link($path)||$after[$key]!==$before[$key]||$atPath[$key]!==$before[$key])self::fail();
            $name=$file->getOriginalName();if(!is_string($name)||$name==='')self::fail();return [$bytes,$name];
        }finally{if(fclose($handle)!==true)self::fail();}
    }
    private static function receipt():array
    {return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];}
    private static function counter(mixed $value):int
    {return Assets::integer($value,0,100);}
    private static function fail():never {throw new AuthUnavailableException();}
}
