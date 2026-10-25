<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerWithdrawalQrAssetDao as Assets;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use PDOStatement;
use stdClass;
use think\db\ConnectionInterface;
use think\file\UploadedFile;
use Throwable;

/** Private materials only. The original cash callback remains the sole financial transaction owner. */
final class CustomerWithdrawalQrAssetServices
{
    public function __construct(private Assets $assets,private CustomerWithdrawalQrReadTickets $tickets,
        private CustomerWithdrawalQrPlatformAccessServices $platform){}

    public function upload(Request $request,int $uid,string $type):array
    {
        $receipt=self::receipt();$object=[];$storage=null;
        try{
            $identity=self::identity($request,$uid);self::extractType($type);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_withdrawal_qr_files')!==true
                ||strtolower(trim((string)$request->rule()->getRule()))!=='extract/files/<extracttype>'
                ||$request->route('extractType',null,'')!==$type||strtolower($request->contentType())!=='multipart/form-data'
                ||$request->get(false)!==[]||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $files=$request->file();if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            [$connection,$pdo]=$this->assets->primary($uid);$session=self::session($pdo);$entered=false;
            $out=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$uid,$type,$identity,$session,$files,
                &$entered,&$receipt,&$object,&$storage):array{
                if($actual!==$connection||$entered)self::fail();$entered=true;$this->assets->native($connection,$pdo,1);
                $user=$this->assets->user($connection,$pdo,$uid,1);$this->sameUpload($connection,$pdo,$request,$uid,$type,$identity,$session,$user,1);
                if(self::counter(CacheService::get('start_uploads_'.$uid,0))>=100)self::fail();
                [$bytes,$name]=self::uploadedBytes($files['pics']);$storage=$this->storage();$storage->inspectUpload($bytes,$name);
                $physical=$storage->writeNew($uid,$bytes,$name,$object,$receipt);
                if($receipt['physical_changed']!==true||$receipt['physical_complete']!==true||$storage->readObject($physical)!==$bytes)self::fail();
                $this->sameUpload($connection,$pdo,$request,$uid,$type,$identity,$session,$user,1);
                $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                $asset=$this->assets->insertStaged($connection,$pdo,$uid,$physical,$name);
                if($storage->readObject(self::physical($asset))!==$bytes)self::fail();
                $this->sameUpload($connection,$pdo,$request,$uid,$type,$identity,$session,$user,1);$receipt['phase']='commit_outcome_unknown';
                return ['asset'=>$asset,'bytes'=>$bytes,'user'=>$user];
            });
            if(!$entered||!$storage instanceof CustomerWithdrawalQrPrivateStorage)self::fail();
            $this->sameUpload($connection,$pdo,$request,$uid,$type,$identity,$session,$out['user'],0);
            $this->assets->committed($connection,$pdo,$uid,$out['asset'],null);
            if($storage->readObject(self::physical($out['asset']))!==$out['bytes'])self::fail();
            $receipt['database_committed']=true;$receipt['phase']='quota_update';
            $key='start_uploads_'.$uid;$count=self::counter(CacheService::get($key,0));if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||self::counter(CacheService::get($key,null))!==$count
                ||self::identity($request,$uid)!==$identity)self::fail();
            $id=Assets::assetId((string)$out['asset']['id']);$receipt['complete']=true;$receipt['phase']='complete';
            return ['name'=>$out['asset']['name'],'url'=>'/api/extract/files/'.$id.'/content','attachment_ref'=>Assets::reference($id)];
        }catch(Throwable $error){
            if($receipt['database_committed']===false&&$object!==[]&&$storage instanceof CustomerWithdrawalQrPrivateStorage)
                try{$storage->cleanupNew($object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Called inside the original cash transaction before its original financial DML. */
    public function prepareCash(ConnectionInterface $connection,Request $request,int $uid,string $reference):array
    {
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();$this->assets->native($connection,$pdo,1);
        $identity=self::identity($request,$uid);self::cashRoute($request,$reference);$session=self::session($pdo);
        $this->assets->user($connection,$pdo,$uid,1);$asset=$this->assets->selected($connection,$pdo,$uid,$reference);
        $storage=$this->storage();$bytes=$storage->readObject(self::physical($asset));
        $context=['connection'=>$connection,'pdo'=>$pdo,'request'=>$request,'uid'=>$uid,'identity'=>$identity,'session'=>$session,
            'reference'=>$reference,'asset'=>$asset,'bytes'=>$bytes,'storage'=>$storage];
        $this->cashIdentity($context,1);
        if($this->assets->asset($connection,$pdo,(string)$asset['id'],$uid,true)!==$asset)self::fail();return $context;
    }
    /** This never saves an extract, changes brokerage or starts/commits another transaction. */
    public function bindCash(array $context,int $actualId):array
    {
        $this->cashIdentity($context,1);$c=$context['connection'];$pdo=$context['pdo'];$uid=$context['uid'];
        $saved=$this->assets->extract($c,$pdo,$actualId,$uid,true);
        if($saved['qrcode_url']!==$context['reference'])self::fail();
        $bound=$this->assets->bind($c,$pdo,$uid,$saved,$context['asset']);
        if($context['storage']->readObject(self::physical($bound))!==$context['bytes'])self::fail();
        $this->assets->user($c,$pdo,$uid,1);$this->cashIdentity($context,1);
        return array_replace($context,['asset'=>$bound,'saved'=>$saved]);
    }
    /** After all original post-cash hooks; a new image observation must not truncate their responsibility. */
    public function committed(array $context):void
    {
        try{
            $this->cashIdentity($context,0);
            $this->assets->user($context['connection'],$context['pdo'],$context['uid'],0);
            $this->assets->committed($context['connection'],$context['pdo'],$context['uid'],$context['asset'],$context['saved']);
            if($context['storage']->readObject(self::physical($context['asset']))!==$context['bytes'])self::fail();
            $this->cashIdentity($context,0);
        }catch(Throwable $error){throw self::cashUnknown();}
    }
    public static function cashUnknown():MerchantAttachmentOperationException
    {
        $receipt=self::receipt();$receipt['phase']='commit_outcome_unknown';$receipt['database_committed']=null;
        return new MerchantAttachmentOperationException($receipt);
    }
    private function cashIdentity(array $context,int $depth):void
    {
        $this->assets->native($context['connection'],$context['pdo'],$depth);
        if(self::identity($context['request'],$context['uid'])!==$context['identity']||self::session($context['pdo'])!==$context['session'])self::fail();
        self::cashRoute($context['request'],$context['reference']);
    }
    private static function cashRoute(Request $request,string $reference):void
    {
        Assets::referenceId($reference);
        if($request->method(true)!=='POST'||trim(strtolower((string)$request->rule()->getRule()))!=='extract/cash'
            ||$request->post('qrcode_url',null)!==$reference)self::fail();
        self::extractType($request->post('extract_type',null));
    }

    public function customerRead(Request $request,int $uid,string $assetId):array
    {return $this->read($request,'customer_preview',$uid,null,$assetId);}
    public function platformRead(Request $request,int $extractId,string $assetId):array
    {return $this->read($request,'platform_list',null,$extractId,$assetId);}
    private function read(Request $request,string $kind,?int $uid,?int $extractId,string $assetId):array
    {
        try{
            $input=self::readInput($request,$kind,$extractId,$assetId);$identity=$this->reader($request,$kind,$uid,$extractId);
            [$connection,$pdo]=$kind==='customer_preview'?$this->assets->primary($identity['actor_id']):$this->assets->platformPrimary($extractId);
            $session=self::session($pdo);$frame=$this->frame($connection,$pdo,$identity,$extractId,$assetId);$asset=$frame['asset'];
            $binding=$identity+['uid'=>Assets::integer($asset['uid'],1,4294967295),'asset_id'=>$assetId,
                'content_sha256'=>bin2hex($asset['content_sha256']),'extract_id'=>$frame['extract']===null?null:Assets::integer($frame['extract']['id'],1,4294967295),
                'purpose'=>'withdrawal_qr'];
            $peek=$input['content']?$this->tickets->peek($input['ticket'],$binding):null;
            $bytes=$this->storage()->readObject(self::physical($asset));
            $same=function()use($request,$kind,$uid,$extractId,$assetId,$identity,$connection,$pdo,$session,$frame):void{
                $this->assets->native($connection,$pdo,0);
                if($this->reader($request,$kind,$uid,$extractId)!==$identity||self::session($pdo)!==$session
                    ||$this->frame($connection,$pdo,$identity,$extractId,$assetId)!==$frame)self::fail();
            };
            $same();
            if(!$input['content']){$out=$this->tickets->issue($binding);$same();return $out;}
            $this->tickets->consume($input['ticket'],$binding,$peek);$same();
            return ['bytes'=>$bytes,'media_type'=>$asset['media_type'],'byte_size'=>strlen($bytes),
                'file_name'=>'withdrawal-qr-'.$assetId.'.'.pathinfo($asset['object_key'],PATHINFO_EXTENSION)];
        }catch(Throwable $error){self::fail();}
    }
    private function frame(ConnectionInterface $connection,PDO $pdo,array $identity,?int $extractId,string $assetId):array
    {
        $extract=null;$user=null;
        if($identity['reader_kind']==='platform_list'){
            $extract=$this->assets->extract($connection,$pdo,$extractId,null,false);$uid=Assets::integer($extract['uid'],1,4294967295);
            if($extract['qrcode_url']!==Assets::reference($assetId))self::fail();
        }else{$uid=$identity['actor_id'];$user=$this->assets->user($connection,$pdo,$uid,0);}
        $asset=$this->assets->asset($connection,$pdo,$assetId,$uid,false);
        if($asset['state']==='BOUND'){
            $first=$this->assets->boundExtract($connection,$pdo,$asset,$uid,false);
            if($extract===null)$extract=$first;
        }elseif($identity['reader_kind']!=='customer_preview')self::fail();
        return ['asset'=>$asset,'extract'=>$extract,'user'=>$user];
    }
    private function reader(Request $request,string $kind,?int $uid,?int $extractId):array
    {
        if($kind==='platform_list'){
            $admin=$this->platform->authorizeRequest($request,$extractId);
            return ['reader_kind'=>$kind,'app'=>'adminapi','actor_type'=>'admin','actor_id'=>$admin['id'],'session_sha256'=>$request->verifiedTokenFingerprint()];
        }
        if($kind!=='customer_preview'||$uid===null)self::fail();
        return ['reader_kind'=>$kind,'app'=>'api','actor_type'=>'customer','actor_id'=>$uid,'session_sha256'=>self::identity($request,$uid)];
    }
    private static function readInput(Request $request,string $kind,?int $extractId,string $assetId):array
    {
        Assets::assetId($assetId);$method=$request->method(true);$platform=$kind==='platform_list';
        $base=$platform?'finance/extract/<extract_id>/qr/<asset_id>':'extract/files/<asset_id>';
        if($request!==app()->request||app()->http->getName()!==($platform?'adminapi':'api')||!in_array($method,['GET','POST'],true)
            ||trim(strtolower((string)$request->rule()->getRule()))!==$base.($method==='POST'?'/read-ticket':'/content')
            ||$request->rule()->getOption('withdrawal_qr_private_reader')!==($platform?'platform':'customer')
            ||$request->route('asset_id',null,'')!==$assetId||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='')self::fail();
        if($platform&&Assets::integer($request->route('extract_id',null,''),1,4294967295)!==$extractId)self::fail();
        $raw=$request->getContent();
        if($method==='POST'){
            if(strtolower($request->contentType())!=='application/json'||!is_string($raw)||strlen($raw)>65536)self::fail();
            $body=json_decode($raw,false,8,JSON_THROW_ON_ERROR);
            if(!$body instanceof stdClass||get_object_vars($body)!==['schema'=>1])self::fail();return ['content'=>false,'ticket'=>null];
        }
        $ticket=$request->header('X-Withdrawal-Qr-Read-Ticket','');
        if($raw!==''||!is_string($ticket)||preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket)!==1)self::fail();
        return ['content'=>true,'ticket'=>$ticket];
    }
    private function sameUpload(ConnectionInterface $connection,PDO $pdo,Request $request,int $uid,string $type,string $identity,array $session,array $user,int $depth):void
    {
        $this->assets->native($connection,$pdo,$depth);self::extractType($type);
        if(self::identity($request,$uid)!==$identity||self::session($pdo)!==$session||$this->assets->user($connection,$pdo,$uid,$depth)!==$user)self::fail();
    }
    public static function identity(Request $request,int $uid):string
    {
        Assets::integer($uid,1,4294967295);
        if(PHP_INT_SIZE!==8||app()->request!==$request||app()->http->getName()!=='api'||$request->isLogin()!==true
            ||!in_array($request->uid(),[$uid,(string)$uid],true))self::fail();
        $fingerprint=$request->verifiedTokenFingerprint();
        if(!is_string($fingerprint)||preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();return $fingerprint;
    }
    private static function extractType(mixed $type):void
    {
        $allowed=\think\facade\Config::get('pay.extractType',[]);
        if(!is_string($type)||!in_array($type,['weixin','alipay'],true)||!is_array($allowed)||!in_array($type,$allowed,true))self::fail();
    }
    private function storage():CustomerWithdrawalQrPrivateStorage {return app()->make(CustomerWithdrawalQrPrivateStorage::class);}
    private static function physical(array $asset):array
    {
        return ['uid'=>Assets::integer($asset['uid'],1,4294967295),'object_key'=>$asset['object_key'],'media_type'=>$asset['media_type'],
            'byte_size'=>Assets::integer($asset['byte_size'],1,52428800),'width'=>Assets::integer($asset['width'],1,4294967295),
            'height'=>Assets::integer($asset['height'],1,4294967295),'content_sha256'=>$asset['content_sha256']];
    }
    private static function session(PDO $pdo):array
    {
        $s=$pdo->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$s instanceof PDOStatement)self::fail();
        try{if($s->execute([])!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);}finally{if($s->closeCursor()!==true)self::fail();}
        if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['database_name','authenticated_user','connection_id','autocommit']
            ||!in_array($rows[0]['autocommit'],[1,'1'],true))self::fail();return $rows[0];
    }
    private static function uploadedBytes(UploadedFile $file):array
    {
        $path=$file->getPathname();$maximum=\think\facade\Config::get('upload.filesize');
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
    private static function counter(mixed $value):int {return Assets::integer($value,0,100);}
    private static function receipt():array {return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];}
    private static function fail():never {throw new AuthUnavailableException();}
}
