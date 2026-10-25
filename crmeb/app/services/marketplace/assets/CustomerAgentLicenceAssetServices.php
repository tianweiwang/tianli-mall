<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerAssetDao;
use app\dao\marketplace\assets\CustomerAgentLicenceAssetDao;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\file\UploadedFile;
use Throwable;

/** Only the actual agent-application writer may bind these private images. */
final class CustomerAgentLicenceAssetServices
{
    private static ?array $writer=null;
    public function __construct(private CustomerAgentLicenceAssetDao $assets,private CustomerAssetDao $customers){}

    public function upload(Request $request,int $uid,int $applyId):array
    {
        $receipt=self::receipt();$object=[];$storage=null;
        try{
            $identity=self::identity($request,$uid);self::applyId($applyId);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_agent_licence_files')!==true
                ||strtolower($request->contentType())!=='multipart/form-data'||$request->get(false)!==[]
                ||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $files=$request->file();if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            [$connection,$pdo]=$this->assets->primary($uid);$session=self::session($pdo);$entered=false;
            $out=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$uid,$applyId,$identity,$session,$files,
                &$entered,&$receipt,&$object,&$storage):array{
                if($actual!==$connection||$entered)self::fail();$entered=true;$this->assets->native($connection,$pdo,1);
                $user=$this->customers->lockUser($connection,$pdo,$uid);$this->customers->assertStructure($connection,$pdo);
                $application=$applyId===0?null:$this->assets->application($connection,$pdo,$applyId,$uid,true);
                $this->same($connection,$pdo,$request,$uid,$identity,$session,$user,$application,1);
                if(self::counter(CacheService::get('start_uploads_'.$uid,0))>=100)self::fail();
                [$bytes,$name]=self::uploadedBytes($files['pics']);$storage=$this->storage();$storage->inspectUpload($bytes,$name);
                $physical=$storage->writeNew($uid,$applyId,$bytes,$name,$object,$receipt);
                if($receipt['physical_changed']!==true||$receipt['physical_complete']!==true||$storage->readObject($physical)!==$bytes)self::fail();
                $this->same($connection,$pdo,$request,$uid,$identity,$session,$user,$application,1);
                $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                $asset=$this->assets->insertStaged($connection,$pdo,$uid,$physical,$name);
                if($storage->readObject(self::physical($asset))!==$bytes)self::fail();
                $this->same($connection,$pdo,$request,$uid,$identity,$session,$user,$application,1);$receipt['phase']='commit_outcome_unknown';
                return ['asset'=>$asset,'bytes'=>$bytes,'user'=>$user,'application'=>$application];
            });
            if(!$entered||!$storage instanceof CustomerAgentLicencePrivateStorage)self::fail();
            $this->same($connection,$pdo,$request,$uid,$identity,$session,$out['user'],$out['application'],0);
            $this->assets->committed($connection,$pdo,$uid,[$out['asset']],$out['application']);
            if($storage->readObject(self::physical($out['asset']))!==$out['bytes'])self::fail();
            $receipt['database_committed']=true;$receipt['phase']='quota_update';
            $key='start_uploads_'.$uid;$count=self::counter(CacheService::get($key,0));if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||self::counter(CacheService::get($key,null))!==$count||self::identity($request,$uid)!==$identity)self::fail();
            $id=CustomerAgentLicenceAssetDao::assetId((string)$out['asset']['id']);
            $receipt['complete']=true;$receipt['phase']='complete';
            return ['name'=>$out['asset']['name'],'url'=>'/api/agent/licence/'.$id.'/content','attachment_ref'=>'mp-licence:v1:'.$id];
        }catch(Throwable $error){
            if($receipt['database_committed']===false&&$object!==[]&&$storage instanceof CustomerAgentLicencePrivateStorage)
                try{$storage->cleanupNew($object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Original service/ORM writes run exactly once inside this actual primary transaction. */
    public function save(Request $request,int $uid,int $applyId,array $data,callable $applicationWriter):bool
    {
        $receipt=self::receipt();
        try{
            $identity=self::identity($request,$uid);self::applyId($applyId);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_agent_licence_profile')!==true
                ||!in_array($data['uid']??null,[$uid,(string)$uid],true)||!is_array($data['images']??null))self::fail();
            $references=CustomerAgentLicenceAssetDao::references($data['images']);
            $encoded=json_encode($references,JSON_THROW_ON_ERROR);
            if(strlen($encoded)>2000)self::fail();
            [$connection,$pdo]=$this->assets->primary($uid);$session=self::session($pdo);$entered=false;
            $out=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$uid,$applyId,$data,$references,$encoded,
                $applicationWriter,$identity,$session,&$entered,&$receipt):array{
                if($actual!==$connection||$entered||self::$writer!==null)self::fail();$entered=true;$this->assets->native($connection,$pdo,1);
                $user=$this->customers->lockUser($connection,$pdo,$uid);$prior=$applyId===0?null:$this->assets->application($connection,$pdo,$applyId,$uid,true);
                $selected=$this->assets->selected($connection,$pdo,$uid,$applyId,$references);$bytes=[];$storage=$selected===[]?null:$this->storage();
                foreach($selected as$id=>$asset)$bytes[$id]=$storage->readObject(self::physical($asset));
                $this->same($connection,$pdo,$request,$uid,$identity,$session,$user,$prior,1);
                self::$writer=['connection'=>$connection,'pdo'=>$pdo,'uid'=>$uid,'apply_id'=>$applyId,'request'=>$request,'identity'=>$identity];
                try{
                    $receipt['phase']='application_write';$receipt['database_committed']=null;
                    $id=$applicationWriter($connection,$pdo,$uid,$applyId,$encoded);
                    self::assertWriter($connection,$pdo,$uid,$applyId);
                    $id=CustomerAgentLicenceAssetDao::integer($id,1,2147483647);if($applyId!==0&&$id!==$applyId)self::fail();
                    $application=$this->assets->application($connection,$pdo,$id,$uid,true);
                    if($application['images']!==$encoded||CustomerAgentLicenceAssetDao::images($application['images'])!==$references)self::fail();
                    foreach(['agent_name','name','phone','division_id','division_invite','add_time']as$key)
                        if(!array_key_exists($key,$data)||(string)$application[$key]!== (string)$data[$key])self::fail();
                    if(CustomerAgentLicenceAssetDao::integer($application['status'],0,2)!==0)self::fail();
                    $bound=$this->assets->bind($connection,$pdo,$uid,$application,$selected);
                    foreach($bound as$key=>$asset)if($storage->readObject(self::physical($asset))!==$bytes[$key])self::fail();
                    self::assertWriter($connection,$pdo,$uid,$applyId);
                    if($this->customers->lockUser($connection,$pdo,$uid)!==$user)self::fail();$receipt['phase']='commit_outcome_unknown';
                    return ['application'=>$application,'assets'=>$bound,'bytes'=>$bytes,'user'=>$user,'storage'=>$storage];
                }finally{self::$writer=null;}
            });
            if(!$entered)self::fail();
            $this->same($connection,$pdo,$request,$uid,$identity,$session,$out['user'],$out['application'],0);
            $this->assets->committed($connection,$pdo,$uid,$out['assets'],$out['application']);
            foreach($out['assets']as$key=>$asset)if($out['storage']->readObject(self::physical($asset))!==$out['bytes'][$key])self::fail();
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';return true;
        }catch(Throwable $error){self::$writer=null;throw new MerchantAttachmentOperationException($receipt);}
    }

    public static function assertWriter(ConnectionInterface $connection,PDO $pdo,int $uid,int $applyId):void
    {
        $w=self::$writer;
        if($w===null||$w['connection']!==$connection||$w['pdo']!==$pdo||$w['uid']!==$uid||$w['apply_id']!==$applyId
            ||self::identity($w['request'],$uid)!==$w['identity']||$connection->getPdo()!==$pdo
            ||$connection->transactionDepth()!==1||!$pdo->inTransaction())self::fail();
    }

    public function customerContent(Request $request,int $uid,string $assetId):array
    {
        $identity=self::identity($request,$uid);self::assetRoute($request,$assetId,'customer_agent_licence_content');
        [$connection,$pdo]=$this->assets->primary($uid);$session=self::session($pdo);
        $asset=$this->assets->asset($connection,$pdo,$assetId,$uid,false);
        if($asset['state']==='BOUND')$this->assets->boundApplication($connection,$pdo,$asset,$uid,false);
        else{ $context=CustomerAgentLicenceAssetDao::objectContext($asset['object_key'],$uid);
            if($context!==0)$this->assets->application($connection,$pdo,$context,$uid,false); }
        $bytes=$this->storage()->readObject(self::physical($asset));
        if(self::identity($request,$uid)!==$identity||self::session($pdo)!==$session
            ||$this->assets->asset($connection,$pdo,$assetId,$uid,false)!==$asset)self::fail();
        if($asset['state']==='BOUND')$this->assets->boundApplication($connection,$pdo,$asset,$uid,false);
        return self::content($asset,$bytes);
    }

    public function platformContent(Request $request,int $applyId,string $assetId):array
    {
        $access=app()->make(CustomerAgentLicencePlatformAccessServices::class);$admin=$access->authorizeRequest($request,$applyId);
        [$connection,$pdo]=$this->assets->platformPrimary($applyId);$session=self::session($pdo);
        $application=$this->assets->platformApplication($connection,$pdo,$applyId,$admin['division_id']);
        $uid=CustomerAgentLicenceAssetDao::integer($application['uid'],1,2147483647);
        if(!in_array('mp-licence:v1:'.$assetId,CustomerAgentLicenceAssetDao::images($application['images']),true))self::fail();
        $asset=$this->assets->asset($connection,$pdo,$assetId,$uid,false);
        if($asset['state']!=='BOUND')self::fail();$this->assets->boundApplication($connection,$pdo,$asset,$uid,false);
        $bytes=$this->storage()->readObject(self::physical($asset));
        if($access->authorizeRequest($request,$applyId)!==$admin||self::session($pdo)!==$session
            ||$this->assets->platformApplication($connection,$pdo,$applyId,$admin['division_id'])!==$application
            ||$this->assets->asset($connection,$pdo,$assetId,$uid,false)!==$asset)self::fail();
        return self::content($asset,$bytes);
    }

    private function same(ConnectionInterface $connection,PDO $pdo,Request $request,int $uid,string $identity,array $session,
        array $user,?array $application,int $depth):void
    {
        $this->assets->native($connection,$pdo,$depth);
        if(self::identity($request,$uid)!==$identity||self::session($pdo)!==$session)self::fail();
        if($this->assets->user($connection,$pdo,$uid,$depth)!==$user)self::fail();
        if($application!==null&&$this->assets->application($connection,$pdo,CustomerAgentLicenceAssetDao::integer($application['id'],1,2147483647),$uid,$depth===1)!==$application)self::fail();
    }
    private function storage():CustomerAgentLicencePrivateStorage {return app()->make(CustomerAgentLicencePrivateStorage::class);}
    private static function physical(array $asset):array
    {
        return ['uid'=>CustomerAgentLicenceAssetDao::integer($asset['uid'],1,2147483647),'object_key'=>$asset['object_key'],
            'media_type'=>$asset['media_type'],'byte_size'=>CustomerAgentLicenceAssetDao::integer($asset['byte_size'],1,52428800),
            'width'=>CustomerAgentLicenceAssetDao::integer($asset['width'],1,4294967295),'height'=>CustomerAgentLicenceAssetDao::integer($asset['height'],1,4294967295),
            'content_sha256'=>$asset['content_sha256']];
    }
    private static function content(array $asset,string $bytes):array
    {return ['bytes'=>$bytes,'media_type'=>$asset['media_type'],'byte_size'=>strlen($bytes),'file_name'=>'agent-licence-'.$asset['id'].'.'.pathinfo($asset['object_key'],PATHINFO_EXTENSION)];}
    private static function identity(Request $request,int $uid):string
    {
        if(PHP_INT_SIZE<8||$uid<1||$uid>2147483647||app()->request!==$request||app()->http->getName()!=='api'
            ||$request->isLogin()!==true||!in_array($request->uid(),[$uid,(string)$uid],true))self::fail();
        $value=$request->verifiedTokenFingerprint();if(!is_string($value)||preg_match('/\A[a-f0-9]{64}\z/D',$value)!==1)self::fail();return $value;
    }
    private static function assetRoute(Request $request,string $id,string $option):void
    {
        CustomerAgentLicenceAssetDao::assetId($id);
        if($request->method(true)!=='GET'||$request->rule()->getOption($option)!==true
            ||$request->route('asset_id',null,null)!==$id||$request->get(false)!==[]||$request->server('QUERY_STRING','')!=='')self::fail();
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
    private static function counter(mixed $value):int {return CustomerAgentLicenceAssetDao::integer($value,0,100);}
    private static function applyId(int $id):void {CustomerAgentLicenceAssetDao::integer($id,0,2147483647);}
    private static function receipt():array {return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];}
    private static function fail():never {throw new AuthUnavailableException();}
}
