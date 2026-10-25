<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\dao\marketplace\product\PlatformProductMediaDao;
use app\dao\marketplace\MerchantAttachmentDao;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\attachment\MerchantAttachmentInput;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use think\exception\HttpException;

/** Ten finite platform product-media callers; the existing writer owns the real transaction. */
final class PlatformProductMediaServices
{
    private const LIST_ACTION='legacy-product-media-list';
    private const UPLOAD_ACTION='legacy-product-media-upload';
    private const MULTIPART_MODES=[
        'multipart-video'=>['kind'=>'video','action'=>'legacy-product-media-video-upload'],
        'multipart-audio'=>['kind'=>'audio','action'=>'legacy-product-media-audio-upload'],
    ];

    public function __construct(private LegacyProductAccessServices $access,private LegacyProductWriteServices $writer,
        private PlatformProductMediaDao $attachments,private MerchantAttachmentStorage $storage,private AuditLogServices $audit,private MerchantAttachmentDao $references) {}

    private function request(Request $request,?string $expected=null): string
    {
        if(app()->request!==$request||!LegacyProductAccessServices::isWriter($request))throw new AuthUnavailableException();
        $action=$request->rule()->getOption('legacy_product_write');
        if(!isset(LegacyProductAccessServices::MEDIA_GRANTS[$action])||($expected!==null&&$expected!==$action))throw new AuthUnavailableException();
        $this->access->authorizeScope($request->platformScope());
        return $action;
    }

    public function forCreate(Request $request,callable $operation): mixed
    {
        $action=$this->request($request);
        return $this->writer->run([],function()use($request,$operation,$action):mixed{
            $this->request($request,$action);LegacyProductWriteServices::mediaOwner(0);
            return $operation();
        },true,false);
    }

    public function forProduct(Request $request,int $productId,callable $operation): mixed
    {
        Input::integer($productId,1,8388607);$action=$this->request($request);
        return $this->writer->run([$productId],function()use($request,$productId,$operation,$action):mixed{
            $this->request($request,$action);LegacyProductWriteServices::mediaOwner($productId);
            return $operation();
        },false,false);
    }

    private function within(Request $request,int $productIdOrZero,callable $operation): mixed
    {
        Input::integer($productIdOrZero,0,8388607);
        return $productIdOrZero===0?$this->forCreate($request,$operation):$this->forProduct($request,$productIdOrZero,$operation);
    }

    public function listing(Request $request,int $productIdOrZero,array $query): array
    {
        $this->request($request,self::LIST_ACTION);
        $query=Input::body($query,['pid','real_name','page','limit','type']);$type=Input::integer($query['type']??0,0,1,true);
        $pid=MerchantAttachmentInput::id($query['pid']??0,true);
        $name=Input::text($query['real_name']??'',255);
        $page=Input::integer($query['page']??1,1,2147483647,true);
        $limit=Input::integer($query['limit']??18,1,100,true);
        return $this->within($request,$productIdOrZero,function()use($productIdOrZero,$pid,$name,$page,$limit,$type):array{
            $result=$this->attachments->listing($productIdOrZero,$pid,$name,$page,$limit,$type);
            $siteUrl=sys_config('site_url');
            $result['list']=array_map(static function(array $row)use($siteUrl):array{
                $item=[];
                foreach(PlatformProductMediaDao::ATTACHMENT_FIELDS as$field)if($field!=='scan_token')$item[$field]=$row[$field];
                // Preserve the actual old platform DTO branches, including its thumbnail selection.
                if($siteUrl){
                    $item['satt_dir']=(strpos($item['satt_dir'],$siteUrl)!==false||strstr($item['satt_dir'],'http')!==false)?$item['satt_dir']:$siteUrl.$item['satt_dir'];
                    $item['att_dir']=(strpos($item['att_dir'],$siteUrl)!==false||strstr($item['att_dir'],'http')!==false)?$item['satt_dir']:$siteUrl.$item['att_dir'];
                    $item['time']=date('Y-m-d H:i:s',(int)$item['time']);
                }
                return $item;
            },$result['list']);
            if($type===1){$result['video_chunk_upload']=ProductVideoUploadSessions::enabled();$result['video_cloud_upload']=ProductVideoCloudUploadSessions::enabled();}
            return $result;
        });
    }

    private static function receipt(): array
    {
        return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];
    }

    public function upload(Request $request,int $productIdOrZero,array $body): string
    {
        $this->request($request,self::UPLOAD_ACTION);$body=Input::body($body,['pid']);
        $pid=MerchantAttachmentInput::id($body['pid']??0,true);$receipt=self::receipt();$object=[];
        try{
            $src=$this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$pid,&$receipt,&$object):string{
                $pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
                if($pid)$this->attachments->category($productIdOrZero,$pid);
                $file=$request->file('file');
                if(!$file||!in_array(strtolower(pathinfo($file->getOriginalName(),PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp'],true))MerchantAttachmentInput::invalid();
                $data=$this->storage->uploadForProduct($productIdOrZero,$object,$receipt);
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                $now=time();Input::integer($now,1,2147483647);
                $receipt['phase']='database_insert';$receipt['database_committed']=null;
                $row=$this->attachments->add($productIdOrZero,$data+['pid'=>$pid,'time'=>$now,'module_type'=>1,'type'=>0,'scan_token'=>'']);
                $located=$this->storage->locateForProduct($row,$productIdOrZero);
                if($this->storage->identity($located)!==$this->storage->identity($object))throw new AuthUnavailableException();
                $receipt['phase']='audit';$scope=$request->platformScope();$this->access->authorizeScope($scope);
                $this->audit->record('admin',$scope->adminId,$owner,self::UPLOAD_ACTION,'attachment',(string)$row['att_id'],'success',
                    ['changed_fields'=>['name','att_dir','satt_dir','pid']]);
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                $receipt['phase']='commit_observation';return $row['att_dir'];
            });
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';return $src;
        }catch(\Throwable $error){
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false
                &&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))
                    ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            if($receipt['phase']==='preflight')$receipt['phase']='preflight_unknown';
            if($receipt['database_committed']===false&&$object){
                try{$receipt['cleanup_complete']=$this->storage->cleanupNew($object,$receipt);}
                catch(\Throwable $ignored){$receipt['cleanup_complete']=false;}
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Fixed real mobile API product image producer; no platform scope is fabricated. */
    public function uploadMobileImage(Request $request,int $productIdOrZero,array $body): array
    {
        Input::integer($productIdOrZero,0,8388607);
        $uid=$this->mobileRequest($request,$productIdOrZero);$body=Input::body($body,['filename']);
        if(($body['filename']??'file')!=='file')MerchantAttachmentInput::invalid();
        $receipt=self::receipt();$objects=[];$observations=[self::receipt()];$snapshot=null;$audit=null;
        try {
            $receipt['phase']='quota_preflight';$this->mobileQuotaAllowed($uid);
            $operation=function()use($request,$productIdOrZero,$uid,&$receipt,&$objects,&$observations,&$snapshot,&$audit):array{
                [$pdo,$owner]=$this->publishContext($productIdOrZero,'mobile-image',$request);
                if($this->mobileRequest($request,$productIdOrZero)!==$uid)throw new AuthUnavailableException();
                $uploaded=$this->storage->readTypedUploadedFile($request);
                $images=[['bytes'=>$uploaded['bytes'],'display_name'=>$uploaded['display_name'],'resolved_kind'=>'image']];unset($uploaded);
                try{$rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$images,0,$receipt,$objects,$observations,'mobile-image',$request);}
                finally{unset($images);}
                self::onlinePhysical($receipt,$observations,1);
                if(count($rows)!==1)throw new AuthUnavailableException();$row=$rows[0];
                $site=sys_config('site_url');if(!is_string($site))throw new AuthUnavailableException();
                $url=set_file_url($row['att_dir'],$site);
                if(!is_string($url)||preg_match('~\Ahttps?://~i',$url)!==1)throw new AuthUnavailableException();
                app()->make(ProductMediaReferenceServices::class)->assertForProduct($productIdOrZero,[['kind'=>'image','path'=>$url]]);
                $receipt['phase']='audit';$audit=$this->audit->recordMobileProductImage($request,$productIdOrZero,$row);
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner
                    ||$this->mobileRequest($request,$productIdOrZero)!==$uid)throw new AuthUnavailableException();
                $snapshot=$this->copyCommitSnapshot($rows);$receipt['phase']='commit_observation';
                return ['name'=>$row['name'],'url'=>$url,'attachment_ref'=>$row['att_dir']];
            };
            $result=$this->writer->run($productIdOrZero===0?[]:[$productIdOrZero],$operation,$productIdOrZero===0,false);
            if(!is_array($snapshot)||!is_array($audit))throw new AuthUnavailableException();
            $this->observeCopyCommit($snapshot);$this->observeMobileAudit($snapshot,$audit);
            $receipt['database_committed']=true;
            if($this->mobileRequest($request,$productIdOrZero)!==$uid)throw new AuthUnavailableException();
            $receipt['phase']='quota_write';$this->advanceMobileQuota($uid,$receipt);
            if($this->mobileRequest($request,$productIdOrZero)!==$uid)throw new AuthUnavailableException();
            $receipt['complete']=true;$receipt['phase']='complete';return $result;
        }catch(\Throwable $error){
            self::onlinePhysical($receipt,$observations,1);
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false
                &&$error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))throw $error;
            $this->copyFailure($receipt,$objects,$observations,1);
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    private function mobileRequest(Request $request,int $productIdOrZero): int
    {
        if(LegacyProductWriteServices::routeId($request->route('product_id',null,null),true)!==$productIdOrZero)throw new AuthUnavailableException();
        return app()->make(\app\api\middleware\CustomerMiddleware::class)->assertMobileProductImage($request);
    }

    /** Read the exact original numeric cache wire without changing shared client options. */
    private function mobileQuota(int $uid): array
    {
        $store=\think\facade\Config::get('cache.default');
        $options=is_string($store)?\think\facade\Config::get('cache.stores.'.$store):null;
        if(!is_array($options)||!is_string($options['prefix']??null)||!is_string($options['type']??null)
            ||strcasecmp($options['type'],'redis')!==0)throw new AuthUnavailableException();
        $key=$options['prefix'].'start_uploads_'.$uid;$redis=\crmeb\services\BoundedRedisConnection::fromDefaultCacheConfig();
        try{
            if(@$redis->setOption(\Redis::OPT_SERIALIZER,\Redis::SERIALIZER_NONE)!==true||@$redis->setOption(\Redis::OPT_PREFIX,'')!==true
                ||@$redis->clearLastError()!==true)throw new AuthUnavailableException();
            $start=time();$raw=@$redis->rawCommand('GET',$key);$ttl=@$redis->rawCommand('TTL',$key);$end=time();
            if(@$redis->getLastError()!==null||!is_int($ttl)||$end<$start)throw new AuthUnavailableException();
            if($raw===false||$raw===null){if($ttl!==-2)throw new AuthUnavailableException();$count=0;}
            else{
                if(!is_string($raw)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$raw)!==1
                    ||strlen($raw)>10||($ttl!==-1&&$ttl<0))throw new AuthUnavailableException();
                $count=(int)$raw;if($count>4294967295)throw new AuthUnavailableException();
            }
            return ['count'=>$count,'ttl'=>$ttl,'start'=>$start,'end'=>$end,'store'=>$store,'prefix'=>$options['prefix']];
        }finally{if(@$redis->close()!==true)throw new AuthUnavailableException();}
    }

    private function mobileQuotaAllowed(int $uid): void
    { if($this->mobileQuota($uid)['count']>=100)throw new HttpException(422,'非法操作'); }

    private function advanceMobileQuota(int $uid,array &$receipt): void
    {
        $before=$this->mobileQuota($uid);
        if($before['count']>=100)throw new AuthUnavailableException();$next=$before['count']+1;
        $start=time();$receipt['phase']='quota_write_unknown';
        if(\crmeb\services\CacheService::set('start_uploads_'.$uid,$next,86400)!==true)throw new AuthUnavailableException();
        $end=time();$receipt['phase']='quota_observation';$after=$this->mobileQuota($uid);
        if($after['count']!==$next||$after['store']!==$before['store']||$after['prefix']!==$before['prefix']
            ||$after['ttl']<1||$after['ttl']>86400||$after['ttl']+$after['end']<$start+86400-1
            ||$after['ttl']+$after['start']>$end+86400)throw new AuthUnavailableException();
    }

    /** Attachment and this exact native audit row must both survive the real outer commit. */
    private function observeMobileAudit(array $snapshot,array $audit): void
    {
        $connection=$snapshot['connection'];$pdo=$snapshot['pdo'];$prefix=$snapshot['prefix'];
        if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction()
            ||array_keys($audit)!==['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time']
            ||$audit['actor_type']!=='api_user'||$audit['action']!=='mobile-product-media-image-upload'
            ||$audit['resource_type']!=='attachment'||$audit['result']!=='success'
            ||$audit['mer_id']!==$snapshot['rows'][0]['mer_id']||$audit['resource_id']!== (string)$snapshot['rows'][0]['att_id']
            ||strlen($prefix.'marketplace_audit_log')>64)throw new AuthUnavailableException();
        $read=$pdo->prepare('SELECT * FROM `'.$prefix.'marketplace_audit_log` WHERE id=? AND mer_id=? LIMIT 2');
        if($read===false)throw new AuthUnavailableException();
        try{if($read->execute([$audit['id'],$audit['mer_id']])!==true||$read->fetchAll(\PDO::FETCH_ASSOC)!==[$audit])throw new AuthUnavailableException();}
        finally{if($read->closeCursor()!==true)throw new AuthUnavailableException();}
        if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction())throw new AuthUnavailableException();
    }

    public function uploadVideo(Request $request,int $productIdOrZero,array $body): string|array
    {
        if(in_array($body['upload_operation']??null,['cloud_issue','cloud_finalize','cloud_cancel'],true))return $this->uploadCloudVideo($request,$productIdOrZero,$body);
        if(array_key_exists('upload_operation',$body))return $this->uploadVideoSession($request,$productIdOrZero,$body);
        if(array_key_exists('url',$body))return $this->importRemoteVideo($request,$productIdOrZero,$body);
        return $this->uploadTyped($request,$productIdOrZero,$body,'video');
    }

    private function videoSessionBinding(Request $request,int $id,int $pid): array
    {
        [, $owner]=$this->publishContext($id,'multipart-video',$request);
        if($pid)$this->attachments->categoryForMedia($id,$pid,1);
        $session=$request->verifiedAdminSession();$scope=LegacyProductWriteServices::rawScope();
        $epoch=\crmeb\services\AuthConfigGuard::authConfig()['session_epoch'];
        if($request->isAdminLogin()!==true||(string)$scope->adminId!==$session['id']
            ||(string)$request->adminId()!==$session['id']||!hash_equals($request->verifiedTokenFingerprint(),$session['fingerprint'])
            ||!is_string($epoch)||$epoch==='')throw new AuthUnavailableException();
        return ['binding'=>hash('sha256',json_encode(['purpose'=>'platform-product-video','session'=>$session,'epoch'=>$epoch,
            'product_id'=>$id,'mer_id'=>$owner,'category_id'=>$pid],JSON_THROW_ON_ERROR)),
            'actor'=>hash('sha256','admin:'.$session['id'])];
    }

    /** Same fixed route and grants as whole-file video; no client path/owner or new role permission. */
    private function uploadVideoSession(Request $request,int $id,array $body): array
    {
        $this->request($request,'legacy-product-media-video-upload');
        $operation=$body['upload_operation']??null;
        $fields=match($operation){
            'issue'=>['upload_operation','pid','filename','total_size'],
            'chunk'=>['upload_operation','pid','upload_reference','chunkNumber'],
            'finalize','cancel'=>['upload_operation','pid','upload_reference'],
            default=>throw new HttpException(422,'请求参数不正确'),
        };
        $body=Input::body($body,$fields,$fields);$pid=MerchantAttachmentInput::id($body['pid'],true);
        $identity=$this->within($request,$id,fn():array=>$this->videoSessionBinding($request,$id,$pid));
        $same=function()use($request,$id,$pid,$identity):void{
            if($this->videoSessionBinding($request,$id,$pid)!==$identity)throw new AuthUnavailableException();
        };
        $publish=function(array $source)use($request,$id,$pid,$same):string{
            return $this->uploadTyped($request,$id,['pid'=>$pid],'video',static function()use($source,$same):array{
                $same();return $source;
            });
        };
        $observe=function(string $src)use($request,$id,$same):void{
            $this->within($request,$id,function()use($id,$src,$same):void{
                $same();app()->make(ProductMediaReferenceServices::class)->assertForProduct($id,[['kind'=>'video','path'=>$src]]);
            });
        };
        return app()->make(ProductVideoUploadSessions::class)->command($request,$identity['binding'],$identity['actor'],$body,$publish,$observe);
    }

    /** Exact cloud reference operations keep the original route, grants and product writer. */
    private function uploadCloudVideo(Request $request,int $id,array $body): array
    {
        $this->request($request,'legacy-product-media-video-upload');
        $fields=match($body['upload_operation']??null){
            'cloud_issue'=>['upload_operation','pid','filename','total_size','sha256'],
            'cloud_finalize','cloud_cancel'=>['upload_operation','pid','upload_reference'],
            default=>throw new HttpException(422,'请求参数不正确'),
        };
        $body=Input::body($body,$fields,$fields);$pid=MerchantAttachmentInput::id($body['pid'],true);
        $identity=$this->within($request,$id,fn():array=>$this->videoSessionBinding($request,$id,$pid));
        $same=function()use($request,$id,$pid,$identity):void{
            if($this->videoSessionBinding($request,$id,$pid)!==$identity)throw new AuthUnavailableException();
        };
        $within=function(callable $operation)use($request,$id,$same):mixed{
            return $this->within($request,$id,function()use($id,$same,$operation):mixed{
                $same();$configuration=$this->storage->cloudVideoForProduct($id);$result=$operation($configuration);$same();
                if($this->storage->cloudVideoForProduct($id)!==$configuration)throw new AuthUnavailableException();return $result;
            });
        };
        $publish=function(array $source,array $configuration)use($request,$id,$pid,$same):string{
            return $this->uploadTyped($request,$id,['pid'=>$pid],'video',function()use($id,$source,$configuration,$same):array{
                $same();if($this->storage->cloudVideoForProduct($id)!==$configuration)throw new AuthUnavailableException();return $source;
            });
        };
        $observe=function(string $src,array $configuration)use($within,$id):void{
            $within(function(array $current)use($configuration,$id,$src):void{
                if($configuration!==$current)throw new AuthUnavailableException();
                app()->make(ProductMediaReferenceServices::class)->assertForProduct($id,[['kind'=>'video','path'=>$src]]);
            });
        };
        return app()->make(ProductVideoCloudUploadSessions::class)->command($request,$identity['binding'],$identity['actor'],$body,$within,$publish,$observe);
    }

    public function uploadAudio(Request $request,int $productIdOrZero,array $body): string
    { return $this->uploadTyped($request,$productIdOrZero,$body,'audio'); }

    /** Single current Request upload; owner and commit remain the original product writer's. */
    private function uploadTyped(Request $request,int $productIdOrZero,array $body,string $kind,?callable $sessionSource=null): string
    {
        $mode='multipart-'.$kind;
        if(!isset(self::MULTIPART_MODES[$mode])||self::MULTIPART_MODES[$mode]['kind']!==$kind)throw new AuthUnavailableException();
        $this->request($request,self::MULTIPART_MODES[$mode]['action']);
        $body=Input::body($body,['pid']);$pid=MerchantAttachmentInput::id($body['pid']??0,true);
        $receipt=self::receipt();$objects=[];$observations=[self::receipt()];$snapshot=null;
        try {
            $src=$this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$pid,$kind,$mode,$sessionSource,&$receipt,&$objects,&$observations,&$snapshot):string{
                [$pdo,$owner]=$this->publishContext($productIdOrZero,$mode,$request);
                if($pid)$this->attachments->categoryForMedia($productIdOrZero,$pid,$kind==='video'?1:0);
                if($sessionSource!==null&&$kind!=='video')throw new AuthUnavailableException();
                $uploaded=$sessionSource===null?$this->storage->readTypedUploadedFile($request):$sessionSource();
                if(array_keys($uploaded)!==['bytes','display_name']||!is_string($uploaded['bytes'])||!is_string($uploaded['display_name']))throw new AuthUnavailableException();
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                $images=[['bytes'=>$uploaded['bytes'],'display_name'=>$uploaded['display_name'],'resolved_kind'=>$kind]];
                unset($uploaded);
                try {
                    $rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$images,$pid,$receipt,$objects,$observations,$mode,$request);
                } finally { unset($images); }
                self::onlinePhysical($receipt,$observations,1);
                if(count($rows)!==1||LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                $snapshot=$this->copyCommitSnapshot($rows);
                return $rows[0]['att_dir'];
            });
            if(!is_array($snapshot))throw new AuthUnavailableException();
            $this->observeCopyCommit($snapshot);
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';
            return $src;
        } catch(\Throwable $error) {
            self::onlinePhysical($receipt,$observations,1);
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false
                &&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))
                    ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            $this->copyFailure($receipt,$objects,$observations,1);
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Download/qualify the complete ordered batch before acquiring any product/merchant lock. */
    public function onlineUpload(Request $request,int $productIdOrZero,array $body): array
    {
        $this->request($request,'legacy-product-media-online-upload');Input::integer($productIdOrZero,0,8388607);
        $body=Input::body($body,['pid','images'],['images']);$pid=MerchantAttachmentInput::id($body['pid']??0,true);
        if(!is_array($body['images'])||!array_is_list($body['images'])||count($body['images'])<1||count($body['images'])>50)MerchantAttachmentInput::invalid();
        $receipt=self::receipt();$objects=[];$observations=[];$expected=count($body['images']);
        try{
            $images=(new ProductMediaRemoteImages())->fetch($body['images']);
            foreach($images as&$image){
                $facts=$this->storage->qualifyImageBytes($image['bytes']);$keys=array_keys($facts);sort($keys);
                if($keys!==['extension','height','mime','sha256','size','width']
                    ||!in_array($facts['extension'],['jpg','jpeg','png','gif','webp'],true)
                    ||!is_int($facts['size'])||$facts['size']!==strlen($image['bytes'])
                    ||$facts['sha256']!==hash('sha256',$image['bytes'])
                    ||preg_match('/\A[a-f0-9]{64}\z/D',$image['url_sha256'])!==1)throw new AuthUnavailableException();
                $image['display_name']=$image['url_sha256'].'.'.$facts['extension'];
            }
            unset($image);
            foreach($images as$index=>$image){$objects[$index]=[];$observations[$index]=self::receipt();}
            $list=$this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$pid,$images,&$receipt,&$objects,&$observations):array{
                $rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$images,$pid,$receipt,$objects,$observations,'online',$request);
                return array_map(static fn(array $row):array=>['att_id'=>(int)$row['att_id'],'src'=>$row['att_dir']],$rows);
            });
            self::onlinePhysical($receipt,$observations,$expected);
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';return $list;
        }catch(\Throwable $error){
            self::onlinePhysical($receipt,$observations,$expected);
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false&&$receipt['cleanup_complete']!==false
                &&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))
                    ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            if($receipt['phase']==='preflight')$receipt['phase']='preflight_unknown';
            if($receipt['database_committed']===false){
                $cleanup=[];
                foreach($objects as$index=>$object){
                    $prior=$observations[$index]['cleanup_complete'];
                    if($object!==[]){
                        try{$done=$this->storage->cleanupNew($object,$observations[$index]);$cleanup[]=$prior===false?false:($done===true);}
                        catch(\Throwable $ignored){$cleanup[]=false;}
                    }elseif($prior===false||$observations[$index]['physical_changed']!==false){$cleanup[]=$prior===false?false:null;}
                }
                if($cleanup!==[])$receipt['cleanup_complete']=in_array(false,$cleanup,true)?false:(in_array(null,$cleanup,true)?null:true);
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }
    /** Internal product copy publisher; it never impersonates the public online Request. */
    public function publishCopyImageInCurrentWriter(int $productIdOrZero,string $bytes,string $displayName,int $pid,array &$receipt): array
    {
        return $this->publishCopyMediaInCurrentWriter($productIdOrZero,$bytes,$displayName,'image',$pid,$receipt);
    }

    /** Typed copy uses the same real product writer and receipt, never a new actor or transaction. */
    public function publishCopyMediaInCurrentWriter(int $productIdOrZero,string $bytes,string $displayName,string $resolvedKind,int $pid,array &$receipt): array
    {
        $objects=[];$observations=[self::receipt()];$receipt=self::receipt();
        $images=[['bytes'=>$bytes,'display_name'=>$displayName,'resolved_kind'=>$resolvedKind]];
        try {
            if(!in_array($resolvedKind,['image','video','audio'],true)
                ||(LegacyProductWriteServices::isSelfWorker()&&$resolvedKind!=='image'))throw new AuthUnavailableException();
            $request=LegacyProductWriteServices::isSelfWorker()?null:app()->request;
            $rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$images,$pid,$receipt,$objects,$observations,'copy',$request);
            self::onlinePhysical($receipt,$observations,1);
            return $rows[0];
        } catch(\Throwable $error) {
            $this->copyFailure($receipt,$objects,$observations,1);
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** All spooled bytes are consumed/closed before the first metadata INSERT. */
    public function publishCopyPlanInCurrentWriter(int $productIdOrZero,array &$plan): array
    {
        if(!isset($plan['images'],$plan['request'])||!is_array($plan['images'])||!array_is_list($plan['images'])
            ||$plan['images']===[]||$plan['request']!==app()->request||!array_key_exists('scope',$plan))throw new AuthUnavailableException();
        $expectedScope=$this->writer->authorizeCurrentRequest();
        if($expectedScope!==$plan['scope']||($expectedScope!==null&&LegacyProductWriteServices::rawScope()!==$expectedScope)
            ||($expectedScope===null&&LegacyProductWriteServices::hasPlatformScope()))throw new AuthUnavailableException();
        $plan['receipt']=self::receipt();$plan['objects']=[];$plan['observations']=[];
        foreach($plan['images']as$ignored)$plan['observations'][]=self::receipt();
        try {
            $rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$plan['images'],0,$plan['receipt'],$plan['objects'],$plan['observations'],'copy',$plan['request']);
            self::onlinePhysical($plan['receipt'],$plan['observations'],count($plan['images']));
            $plan['commit_snapshot']=$this->copyCommitSnapshot($rows);return $rows;
        } catch(\Throwable $error) {
            $this->copyFailure($plan['receipt'],$plan['objects'],$plan['observations'],count($plan['images']));$plan['failure_finalized']=true;
            throw new MerchantAttachmentOperationException($plan['receipt']);
        }
    }

    /** Called only after the original writer returned or threw; unknown SQL is never cleaned. */
    public function finishCopyPlan(array &$plan,bool $completed): void
    {
        if(!isset($plan['receipt'])||($plan['failure_finalized']??false)===true)return;
        if($completed){$this->observeCopyCommit($plan['commit_snapshot']);$plan['receipt']['database_committed']=true;$plan['receipt']['complete']=true;$plan['receipt']['phase']='complete';return;}
        $this->copyFailure($plan['receipt'],$plan['objects'],$plan['observations'],count($plan['images']));
    }

    /** Original native rows are captured inside the actual writer, never reconstructed after commit. */
    public function copyCommitSnapshot(array $rows): array
    {
        $pdo=LegacyProductWriteServices::transaction();$connection=\think\facade\Db::connect();
        if($rows===[]||!array_is_list($rows)||$connection->getPdo()!==$pdo)throw new AuthUnavailableException();
        $prefix=$connection->getConfig('prefix');
        if(!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.'system_attachment')>64)throw new AuthUnavailableException();
        foreach($rows as$row)if(!is_array($row)||array_keys($row)!==PlatformProductMediaDao::ATTACHMENT_FIELDS)throw new AuthUnavailableException();
        return ['connection'=>$connection,'pdo'=>$pdo,'prefix'=>$prefix,'rows'=>$rows];
    }

    /** Observed persisted commit, not a fabricated PDO::commit boolean ACK. */
    public function observeCopyCommit(array $snapshot): void
    {
        if(array_keys($snapshot)!==['connection','pdo','prefix','rows']||!is_object($snapshot['connection'])
            ||!is_string($snapshot['prefix'])||preg_match('/\A[A-Za-z0-9_]*\z/D',$snapshot['prefix'])!==1
            ||strlen($snapshot['prefix'].'system_attachment')>64||!is_array($snapshot['rows'])
            ||!array_is_list($snapshot['rows'])||$snapshot['rows']===[])throw new AuthUnavailableException();
        $connection=$snapshot['connection'];$pdo=$snapshot['pdo'];
        if(!$pdo instanceof \PDO||\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo
            ||$pdo->inTransaction()||$connection->getConfig('prefix')!==$snapshot['prefix'])throw new AuthUnavailableException();
        $state=$pdo->query('SELECT @@session.autocommit AS actual_autocommit');if($state===false)throw new AuthUnavailableException();
        try{$rows=$state->fetchAll(\PDO::FETCH_ASSOC);if(!is_array($rows)||count($rows)!==1||array_keys($rows[0])!==['actual_autocommit']
            ||!in_array($rows[0]['actual_autocommit'],[1,'1'],true))throw new AuthUnavailableException();}
        finally{if($state->closeCursor()!==true)throw new AuthUnavailableException();}
        $table='`'.$snapshot['prefix'].'system_attachment`';
        foreach($snapshot['rows']as$expected){
            if(!is_array($expected)||array_keys($expected)!==PlatformProductMediaDao::ATTACHMENT_FIELDS)throw new AuthUnavailableException();
            $statement=$pdo->prepare('SELECT * FROM '.$table.' WHERE att_id=? AND mer_id=? LIMIT 2');if($statement===false)throw new AuthUnavailableException();
            try{if($statement->execute([$expected['att_id'],$expected['mer_id']])!==true||$statement->fetchAll(\PDO::FETCH_ASSOC)!==[$expected])throw new AuthUnavailableException();}
            finally{if($statement->closeCursor()!==true)throw new AuthUnavailableException();}
        }
        if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction())throw new AuthUnavailableException();
    }

    private function copyFailure(array &$receipt,array $objects,array $observations,int $expected): void
    {
        $priorCleanup=$receipt['cleanup_complete'];self::onlinePhysical($receipt,$observations,$expected);
        if($receipt['phase']==='preflight')$receipt['phase']='preflight_unknown';
        if($receipt['database_committed']===false){
            $cleanup=[];
            foreach($objects as$index=>$object){
                $prior=$observations[$index]['cleanup_complete'];
                if($object!==[]){
                    try{$done=$this->storage->cleanupNew($object,$observations[$index]);$cleanup[]=$prior===false?false:($done===true);}
                    catch(\Throwable $ignored){$cleanup[]=false;}
                }elseif($prior===false||$observations[$index]['physical_changed']!==false){$cleanup[]=$prior===false?false:null;}
            }
            if($cleanup!==[])$receipt['cleanup_complete']=in_array(false,$cleanup,true)?false:(in_array(null,$cleanup,true)?null:true);
        }
        if($priorCleanup===false)$receipt['cleanup_complete']=false;
    }

    /** Copy an authorized native platform image into a distinct current product-owned object. */
    public function importPlatformImage(Request $request,int $productIdOrZero,array $body): array
    {
        $action=\app\services\marketplace\assets\PlatformAssetServices::IMPORT;
        $this->request($request,$action);Input::integer($productIdOrZero,0,8388607);
        if(LegacyProductWriteServices::routeId($request->route('id',null,null),true)!==$productIdOrZero)throw new AuthUnavailableException();
        $body=Input::body($body,['platform_asset_id','pid'],['platform_asset_id']);
        if(!is_string($body['platform_asset_id'])||preg_match('/\A[1-9][0-9]{0,19}\z/D',$body['platform_asset_id'])!==1
            ||strlen($body['platform_asset_id'])===20&&strcmp($body['platform_asset_id'],'18446744073709551615')>0)throw new HttpException(422,'平台素材编号不正确');
        $sourceId=\app\dao\marketplace\assets\PlatformAssetDao::id($body['platform_asset_id']);$pid=MerchantAttachmentInput::id($body['pid']??0,true);
        $platform=app()->make(\app\dao\marketplace\assets\PlatformAssetDao::class);$sourceStorage=app()->make(\app\services\marketplace\assets\PlatformAssetStorage::class);
        $receipt=self::receipt();$objects=[];$observations=[self::receipt()];$snapshot=null;$sourceSnapshot=null;
        try{
            $list=$this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$sourceId,$pid,$platform,$sourceStorage,&$receipt,&$objects,&$observations,&$snapshot,&$sourceSnapshot):array{
                [$pdo,$owner]=$this->publishContext($productIdOrZero,'platform-image',$request);$scope=LegacyProductWriteServices::rawScope();
                $source=$platform->find($request,$scope,$pdo,$sourceId);$sourceRead=$sourceStorage->read($request,$scope,$pdo,$source);
                try{
                    $images=[['bytes'=>$sourceRead['bytes'],'display_name'=>$sourceRead['display_name'],'resolved_kind'=>'image']];
                    $importAudit=null;
                    try{$rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$images,$pid,$receipt,$objects,$observations,'platform-image',$request);$importAudit=$images[0]['platform_import_audit']??null;}
                    finally{unset($images);}
                    if(!is_array($importAudit))throw new AuthUnavailableException();
                    self::onlinePhysical($receipt,$observations,1);if(count($rows)!==1)throw new AuthUnavailableException();
                    $after=$platform->find($request,$scope,$pdo,$sourceId);if($after!==$source)throw new AuthUnavailableException();
                    $afterRead=$sourceStorage->read($request,$scope,$pdo,$after);
                    try{
                        if($afterRead['bytes']!==$sourceRead['bytes']||\app\services\marketplace\assets\PlatformAssetStorage::proof($afterRead['object'])
                            !==\app\services\marketplace\assets\PlatformAssetStorage::proof($sourceRead['object']))throw new AuthUnavailableException();
                        $target=$this->storage->locateForProduct($rows[0],$productIdOrZero);
                        if($this->storage->identity($target)===$sourceStorage->identity($sourceRead['object']))throw new AuthUnavailableException();
                    }finally{unset($afterRead['bytes']);}
                    [$samePdo,$sameOwner]=$this->publishContext($productIdOrZero,'platform-image',$request);
                    if($samePdo!==$pdo||$sameOwner!==$owner)throw new AuthUnavailableException();
                    $snapshot=$this->copyCommitSnapshot($rows);$sourceSnapshot=['row'=>$source,'proof'=>\app\services\marketplace\assets\PlatformAssetStorage::proof($sourceRead['object']),
                        'sha256'=>$sourceRead['qualified']['sha256'],'scope'=>$scope,'audit'=>$importAudit];
                    $siteUrl=sys_config('site_url');$src=$rows[0]['att_dir'];
                    if($siteUrl)$src=(strpos($src,$siteUrl)!==false||strstr($src,'http')!==false)?$rows[0]['satt_dir']:$siteUrl.$src;
                    return [['att_id'=>(int)$rows[0]['att_id'],'src'=>$src]];
                }finally{unset($sourceRead['bytes']);}
            });
            // The original writer returned after its actual commit. Later readback cannot erase that fact.
            $receipt['database_committed']=true;
            if(!is_array($snapshot)||!is_array($sourceSnapshot))throw new AuthUnavailableException();$this->observeCopyCommit($snapshot);
            $this->audit->observePlatformProductImageImport($request,$productIdOrZero,$snapshot['rows'][0],$sourceSnapshot['audit']);
            $pdo=$snapshot['pdo'];$scope=$sourceSnapshot['scope'];
            \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo,false);
            $source=$platform->find($request,$scope,$pdo,$sourceId,false);if($source!==$sourceSnapshot['row'])throw new AuthUnavailableException();
            $read=$sourceStorage->read($request,$scope,$pdo,$source,false);
            try{if($read['qualified']['sha256']!==$sourceSnapshot['sha256']||\app\services\marketplace\assets\PlatformAssetStorage::proof($read['object'])!==$sourceSnapshot['proof'])throw new AuthUnavailableException();}
            finally{unset($read['bytes']);}
            \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$scope,$pdo,false);
            $receipt['complete']=true;$receipt['phase']='complete';return $list;
        }catch(\Throwable $error){
            self::onlinePhysical($receipt,$observations,1);
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false&&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,410,422],true))
                ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            $this->copyFailure($receipt,$objects,$observations,1);
            // Only this new import: a cloud DELETE acknowledgement does not prove object absence.
            if($receipt['database_committed']===false&&$receipt['cleanup_complete']===true){
                foreach($objects as$object){
                    $type=$object['type']??null;
                    if(is_int($type)&&$type>=2&&$type<=7){$receipt['cleanup_complete']=null;break;}
                }
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Two finite callers share the original bytes -> native14 body, not authorization. */
    private function publishQualifiedBytesInCurrentWriter(int $productIdOrZero,array &$images,int $pid,array &$receipt,array &$objects,array &$observations,string $mode,?Request $request): array
    {
        [$pdo,$owner]=$this->publishContext($productIdOrZero,$mode,$request);$resolvedKinds=[];
        if($mode==='online'){
            if($pid)$this->attachments->category($productIdOrZero,$pid);
        }else{
            foreach($images as$index=>$image){
                $kind=$image['resolved_kind']??null;
                if(!in_array($kind,['image','video','audio'],true)
                    ||(LegacyProductWriteServices::isSelfWorker()&&$kind!=='image')
                    ||(isset(self::MULTIPART_MODES[$mode])&&$kind!==self::MULTIPART_MODES[$mode]['kind'])
                    ||($mode==='remote-video'&&$kind!=='video')||($mode==='mobile-image'&&$kind!=='image')||($mode==='platform-image'&&$kind!=='image'))throw new AuthUnavailableException();
                $resolvedKinds[$index]=$kind;
                if($pid)$this->attachments->categoryForMedia($productIdOrZero,$pid,$kind==='video'?1:0);
            }
            unset($image);
        }
        $data=[];
        // No attachment INSERT is attempted until every requested physical object has completed.
        foreach($images as$index=>&$image){
            if(!isset($objects[$index]))$objects[$index]=[];
            if(!isset($observations[$index]))$observations[$index]=self::receipt();
            $this->publishContext($productIdOrZero,$mode,$request);$receipt['phase']='physical_upload';
            try{$bytes=array_key_exists('spool',$image)?\app\services\product\product\CopyTaobaoServices::readCopySpool($image):$image['bytes'];}
            catch(\Throwable $error){if(($image['cleanup_complete']??null)===false)$receipt['cleanup_complete']=false;throw $error;}
            try{
                if($mode==='online')$data[$index]=$this->storage->uploadBytesForProduct($productIdOrZero,$bytes,$image['display_name'],$objects[$index],$observations[$index]);
                else{
                    $data[$index]=$this->storage->uploadTypedBytesForProduct($productIdOrZero,$bytes,$image['display_name'],$resolvedKinds[$index],$objects[$index],$observations[$index]);
                    $keys=array_keys($data[$index]);sort($keys,SORT_STRING);
                    if($keys!==['att_dir','att_size','att_type','image_type','name','real_name','satt_dir','type']
                        ||$data[$index]['type']!==($resolvedKinds[$index]==='video'?1:0))throw new AuthUnavailableException();
                }
            }finally{unset($bytes);}
            if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner
                ||!in_array($observations[$index]['physical_changed'],[true,null],true)||$observations[$index]['physical_complete']!==true
                ||$observations[$index]['cleanup_complete']===false)throw new AuthUnavailableException();
        }
        unset($image);$receipt['physical_complete']=true;$rows=[];
        foreach($data as$index=>$fields){
            $this->publishContext($productIdOrZero,$mode,$request);
            if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
            $now=time();Input::integer($now,1,2147483647);
            $receipt['phase']='database_insert';$receipt['database_committed']=null;
            if($mode==='online')$row=$this->attachments->add($productIdOrZero,$fields+['pid'=>$pid,'time'=>$now,'module_type'=>1,'type'=>0,'scan_token'=>'']);
            else $row=$this->attachments->addTyped($productIdOrZero,$fields+['pid'=>$pid,'time'=>$now,'module_type'=>1,'scan_token'=>''],$resolvedKinds[$index]);
            $located=$this->storage->locateForProduct($row,$productIdOrZero);
            if($this->storage->identity($located)!==$this->storage->identity($objects[$index]))throw new AuthUnavailableException();
            $receipt['phase']='audit';
            if($mode==='online')$this->record($request,$productIdOrZero,'attachment',(int)$row['att_id'],['name','att_dir','satt_dir','pid']);
            elseif($mode==='platform-image')$images[$index]['platform_import_audit']=$this->audit->recordPlatformProductImageImport($request,$productIdOrZero,$row);
            elseif($request!==null&&LegacyProductWriteServices::hasPlatformScope()){
                $scope=LegacyProductWriteServices::rawScope();$this->access->authorizeScope($scope);
                $this->audit->record('admin',$scope->adminId,$owner,$scope->permission,'attachment',(string)$row['att_id'],'success',['changed_fields'=>['name','att_dir','satt_dir','pid']]);
            }
            if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
            $rows[]=$row;
        }
        $receipt['phase']='commit_observation';return $rows;
    }

    private function publishContext(int $productIdOrZero,string $mode,?Request $request): array
    {
        Input::integer($productIdOrZero,0,8388607);
        $pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
        if($mode==='online'){
            if($request===null)throw new AuthUnavailableException();
            $this->request($request,'legacy-product-media-online-upload');
        }elseif(isset(self::MULTIPART_MODES[$mode])){
            if($request===null||app()->request!==$request||LegacyProductWriteServices::isSelfWorker())throw new AuthUnavailableException();
            $this->request($request,self::MULTIPART_MODES[$mode]['action']);
            if(LegacyProductWriteServices::rawScope()!==$request->platformScope())throw new AuthUnavailableException();
        }elseif($mode==='remote-video'){
            if($request===null||app()->request!==$request||LegacyProductWriteServices::isSelfWorker())throw new AuthUnavailableException();
            $this->request($request,'legacy-product-media-video-upload');
            if(LegacyProductWriteServices::rawScope()!==$request->platformScope())throw new AuthUnavailableException();
        }elseif($mode==='mobile-image'){
            if($request===null||LegacyProductWriteServices::hasPlatformScope()||LegacyProductWriteServices::isSelfWorker()
                ||$owner!==LegacyProductWriteServices::selfId())throw new AuthUnavailableException();
            $uid=$this->mobileRequest($request,$productIdOrZero);$this->mobileQuotaAllowed($uid);
            if($productIdOrZero>0){$root=LegacyProductWriteServices::assertParent($productIdOrZero);
                if((string)$root['is_del']!=='0'||(string)$root['virtual_type']!=='0')throw new AuthUnavailableException();}
        }elseif($mode==='platform-image'){
            if($request===null||app()->request!==$request||LegacyProductWriteServices::isSelfWorker())throw new AuthUnavailableException();
            $this->request($request,\app\services\marketplace\assets\PlatformAssetServices::IMPORT);
            if(LegacyProductWriteServices::routeId($request->route('id',null,null),true)!==$productIdOrZero
                ||LegacyProductWriteServices::rawScope()!==$request->platformScope())throw new AuthUnavailableException();
            \app\services\marketplace\assets\PlatformAssetServices::assertContext($request,$request->platformScope(),$pdo);
        }elseif($mode==='copy'){
            if(!LegacyProductWriteServices::enabled())throw new AuthUnavailableException();
            if(LegacyProductWriteServices::isSelfWorker()){
                if($request!==null||$productIdOrZero<1||$owner!==LegacyProductWriteServices::selfId())throw new AuthUnavailableException();
            }else{
                if($request===null||app()->request!==$request)throw new AuthUnavailableException();
                $scope=$this->writer->authorizeCurrentRequest();
                if($scope!==null){
                    if(!in_array($scope->permission,['legacy-product-save','legacy-product-copy-save'],true)
                        ||LegacyProductWriteServices::rawScope()!==$scope)throw new AuthUnavailableException();
                    $this->access->authorizeScope($scope);
                }elseif(LegacyProductWriteServices::hasPlatformScope()||$owner!==LegacyProductWriteServices::selfId())throw new AuthUnavailableException();
            }
        }else throw new AuthUnavailableException();
        if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
        return [$pdo,$owner];
    }

    /** Any observed physical change survives a later unknown item; private cleanup failure also survives. */
    private static function onlinePhysical(array &$receipt,array $observations,int $expected): void
    {
        $changed=false;$unknown=false;$complete=count($observations)===$expected;
        foreach($observations as$item){
            if($item['physical_changed']===true)$changed=true;elseif($item['physical_changed']===null)$unknown=true;
            if($item['physical_complete']!==true)$complete=false;
            if($item['cleanup_complete']===false)$receipt['cleanup_complete']=false;
        }
        $receipt['physical_changed']=$changed?true:($unknown?null:false);$receipt['physical_complete']=$complete;
    }

    private static function categoryPid(mixed $value): int
    {
        if(is_array($value)){
            if(!array_is_list($value)||$value===[]||count($value)>64)MerchantAttachmentInput::invalid();
            $parsed=[];foreach($value as$part)$parsed[]=MerchantAttachmentInput::id($part,true);
            foreach($parsed as$position=>$part)if(($part===0&&$position!==0)||in_array($part,array_slice($parsed,0,$position),true))MerchantAttachmentInput::invalid();
            return $parsed[count($parsed)-1];
        }
        return MerchantAttachmentInput::id($value,true);
    }
    /** Legacy picker deletion permits a single numeric ID; move always sends a comma string. */
    private static function selectedIds(mixed $value,bool $singleInteger): array
    {
        if($singleInteger&&is_int($value))return [MerchantAttachmentInput::id($value)];
        if(!is_string($value)||$value===''||strlen($value)>549)MerchantAttachmentInput::invalid();
        $parts=explode(',',$value);if(count($parts)>50)MerchantAttachmentInput::invalid();$ids=[];
        foreach($parts as$part)$ids[]=MerchantAttachmentInput::id($part);
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return $ids;
    }
    private function record(Request $request,int $productIdOrZero,string $resource,int $id,array $fields): void
    {
        $action=$this->request($request);$pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
        $scope=$request->platformScope();
        $this->audit->record('admin',$scope->adminId,$owner,$action,$resource,(string)$id,'success',['changed_fields'=>$fields]);
        if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
    }
    public function categories(Request $request,int $productIdOrZero,array $query): array
    {
        $this->request($request,'legacy-product-media-categories-list');$query=Input::body($query,['name','pid','all','type']);
        $name=Input::text($query['name']??'',50);$pid=self::categoryPid($query['pid']??0);
        $all=Input::integer($query['all']??0,0,1,true)===1;$type=Input::integer($query['type']??0,0,1,true);
        return $this->within($request,$productIdOrZero,function()use($productIdOrZero,$name,$pid,$all,$type):array{
            $observed=$this->attachments->categoryListing($productIdOrZero,$pid,$name,$all,$type);$rows=$observed['rows'];$children=[];
            foreach($observed['complete']as$row)$children[(int)$row['pid']][]=(int)$row['id'];
            if($all){
                $selected=[];foreach($rows as$row)$selected[(int)$row['pid']][]=$row;
                $build=function(int $parent)use(&$build,$selected):array{
                    $out=[];foreach($selected[$parent]??[]as$row){$row['title']=$row['name'];$nested=$build((int)$row['id']);
                        if($nested!==[]){$row['children']=$nested;$row['expand']=true;}$out[]=$row;}
                    return $out;
                };
                return ['list'=>$build(0)];
            }
            foreach($rows as&$row){$row['title']=$row['name'];
                if($name===''&&isset($children[(int)$row['id']])){$row['loading']=false;$row['children']=[];}}
            unset($row);return ['list'=>$rows];
        });
    }
    public function saveCategory(Request $request,int $productIdOrZero,int $id,array $body): void
    {
        $this->request($request,$id===0?'legacy-product-media-categories-create':'legacy-product-media-categories-update');
        MerchantAttachmentInput::id($id,true);$body=Input::body($body,['name','pid','type'],['name']);
        $name=Input::text($body['name'],50,true);$pid=self::categoryPid($body['pid']??0);$type=array_key_exists('type',$body)?Input::integer($body['type'],0,1,true):null;
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id,$name,$pid,$type):void{
            $result=$this->attachments->saveCategory($productIdOrZero,$id,$name,$pid,$type);
            if($result['changed'])$this->record($request,$productIdOrZero,'attachment-category',(int)$result['row']['id'],['name','pid']);
        });
    }
    public function deleteCategory(Request $request,int $productIdOrZero,int $id,array $selector=[]): void
    {
        $this->request($request,'legacy-product-media-categories-delete');MerchantAttachmentInput::id($id);$selector=Input::body($selector,['type']);$type=array_key_exists('type',$selector)?Input::integer($selector['type'],0,1,true):null;
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id,$type):void{
            $result=$this->attachments->deleteCategory($productIdOrZero,$id,$type);
            foreach($result['moved_ids']as$moved)$this->record($request,$productIdOrZero,'attachment',$moved,['pid']);
            $this->record($request,$productIdOrZero,'attachment-category',$id,['name','pid']);
        });
    }
    public function rename(Request $request,int $productIdOrZero,int $id,array $body): void
    {
        $this->request($request,'legacy-product-media-rename');
        if(self::videoMutation($body)){$this->renameVideo($request,$productIdOrZero,$id,$body);return;}
        MerchantAttachmentInput::id($id);
        $body=Input::body($body,['real_name'],['real_name']);$name=Input::text($body['real_name'],255,true);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id,$name):void{
            $result=$this->attachments->rename($productIdOrZero,$id,$name);
            if($result['changed'])$this->record($request,$productIdOrZero,'attachment',$id,['real_name']);
        });
    }
    public function move(Request $request,int $productIdOrZero,array $body): void
    {
        $this->request($request,'legacy-product-media-move');
        if(self::videoMutation($body)){$this->moveVideos($request,$productIdOrZero,$body);return;}
        $body=Input::body($body,['images','pid'],['images','pid']);
        $ids=self::selectedIds($body['images'],false);$pid=self::categoryPid($body['pid']);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$ids,$pid):void{
            $changed=$this->attachments->move($productIdOrZero,$ids,$pid);
            foreach($changed as$id)$this->record($request,$productIdOrZero,'attachment',$id,['pid']);
        });
    }
    public function delete(Request $request,int $productIdOrZero,array $body): void
    {
        $this->request($request,'legacy-product-media-delete');
        if(self::videoMutation($body)){$this->deleteVideos($request,$productIdOrZero,$body);return;}
        $body=Input::body($body,['ids'],['ids']);
        $ids=self::selectedIds($body['ids'],true);$receipt=self::receipt();
        try{
            $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$ids,&$receipt):void{
                $pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
                $rows=$this->attachments->ownedImages($productIdOrZero,$ids);
                // All three known tables/columns and actual legacy-ID matches must be observed before locator I/O.
                $this->attachments->assertAttachmentMappingsAbsent($productIdOrZero,$ids);$objects=[];
                foreach($rows as$row){$object=$this->storage->locateForProduct($row,$productIdOrZero);$objects[$this->storage->identity($object)]=$object;}
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                // Check every complete per-object alias family before removing any object; keep the full selected-ID exclusion.
                foreach($objects as$object){
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                    $this->references->assertUnreferenced($ids,$this->storage->aliases([$object]));
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                }
                foreach($objects as$object)$this->storage->remove($object,$receipt);
                $receipt['physical_complete']=true;$receipt['phase']='database_delete';$receipt['database_committed']=null;
                $this->attachments->removeImages($productIdOrZero,$ids);$receipt['phase']='audit';
                foreach($ids as$id)$this->record($request,$productIdOrZero,'attachment',$id,['att_dir','satt_dir']);
                $receipt['phase']='commit_observation';
            });
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';
        }catch(\Throwable $error){
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false
                &&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))
                    ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            if($receipt['phase']==='preflight')$receipt['phase']='preflight_unknown';
            // Partial removal is not reversible by a database rollback. Never cleanupNew or recreate old objects.
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Selector is operation intent only; native type is locked and remains unchanged. */
    private static function videoMutation(array &$body): bool
    {
        if(!array_key_exists('type',$body))return false;
        $type=Input::integer($body['type'],0,1,true);unset($body['type']);return $type===1;
    }

    private function renameVideo(Request $request,int $productIdOrZero,int $id,array $body): void
    {
        MerchantAttachmentInput::id($id);
        $body=Input::body($body,['real_name'],['real_name']);$name=Input::text($body['real_name'],255,true);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id,$name):void{
            $result=$this->attachments->renameVideo($productIdOrZero,$id,$name);
            if($result['changed'])$this->record($request,$productIdOrZero,'attachment',$id,['real_name']);
        });
    }

    private function deleteVideos(Request $request,int $productIdOrZero,array $body): void
    {
        $body=Input::body($body,['ids'],['ids']);
        $ids=self::selectedIds($body['ids'],true);$receipt=self::receipt();
        try{
            $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$ids,&$receipt):void{
                $pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
                $rows=$this->attachments->ownedVideos($productIdOrZero,$ids);
                // All three known tables/columns and actual legacy-ID matches must be observed before locator I/O.
                $this->attachments->assertAttachmentMappingsAbsent($productIdOrZero,$ids);$objects=[];
                foreach($rows as$row){$object=$this->storage->locateForProduct($row,$productIdOrZero);$objects[$this->storage->identity($object)]=$object;}
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                // Check every complete per-object alias family before removing any object; keep the full selected-ID exclusion.
                foreach($objects as$object){
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                    $this->references->assertUnreferenced($ids,$this->storage->aliases([$object]));
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                }
                foreach($objects as$object)$this->storage->remove($object,$receipt);
                $receipt['physical_complete']=true;$receipt['phase']='database_delete';$receipt['database_committed']=null;
                $this->attachments->removeVideos($productIdOrZero,$ids);$receipt['phase']='audit';
                foreach($ids as$id)$this->record($request,$productIdOrZero,'attachment',$id,['att_dir','satt_dir']);
                $receipt['phase']='commit_observation';
            });
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';
        }catch(\Throwable $error){
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false
                &&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))
                    ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            if($receipt['phase']==='preflight')$receipt['phase']='preflight_unknown';
            // Partial removal is not reversible by a database rollback. Never cleanupNew or recreate old objects.
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    private function moveVideos(Request $request,int $productIdOrZero,array $body): void
    {
        $body=Input::body($body,['images','pid'],['images','pid']);
        $ids=self::selectedIds($body['images'],false);$pid=self::categoryPid($body['pid']);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$ids,$pid):void{
            $changed=$this->attachments->moveVideos($productIdOrZero,$ids,$pid);
            foreach($changed as$id)$this->record($request,$productIdOrZero,'attachment',$id,['pid']);
        });
    }

    /** One real JSON URL source; downloaded and qualified before acquiring the actual product writer. */
    private function importRemoteVideo(Request $request,int $productIdOrZero,array $body): string
    {
        $this->request($request,'legacy-product-media-video-upload');Input::integer($productIdOrZero,0,8388607);
        $body=Input::body($body,['url','pid'],['url']);$url=Input::text($body['url'],2048,true);$pid=MerchantAttachmentInput::id($body['pid']??0,true);
        $files=$request->file();
        if(strtolower($request->contentType())!=='application/json'||($files!==null&&$files!==[]))MerchantAttachmentInput::invalid();
        $mode='remote-video';$receipt=self::receipt();$objects=[];$observations=[self::receipt()];$snapshot=null;
        try{
            $download=(new ProductMediaRemoteImages())->fetchOneForTypedProductCopy($url);
            if(array_keys($download)!==['bytes','url_sha256']||!is_string($download['bytes'])||!is_string($download['url_sha256'])
                ||$download['url_sha256']!==hash('sha256',$url))throw new AuthUnavailableException();
            $displayName=$download['url_sha256'].'.mp4';
            $qualified=$this->storage->qualifyTypedUploadBytes($download['bytes'],$displayName,'video');
            if($qualified['resolved_kind']!=='video'||$qualified['extension']!=='mp4'||$qualified['type']!==1
                ||$qualified['size']!==strlen($download['bytes'])||$qualified['sha256']!==hash('sha256',$download['bytes']))throw new AuthUnavailableException();
            $images=[['bytes'=>$download['bytes'],'display_name'=>$displayName,'resolved_kind'=>'video']];unset($download,$qualified);
            $src=$this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$pid,$images,$mode,&$receipt,&$objects,&$observations,&$snapshot):string{
                [$pdo,$owner]=$this->publishContext($productIdOrZero,$mode,$request);
                if($pid)$this->attachments->categoryForMedia($productIdOrZero,$pid,1);
                try{$rows=$this->publishQualifiedBytesInCurrentWriter($productIdOrZero,$images,$pid,$receipt,$objects,$observations,$mode,$request);}
                finally{unset($images);}
                self::onlinePhysical($receipt,$observations,1);
                if(count($rows)!==1||LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                $snapshot=$this->copyCommitSnapshot($rows);return $rows[0]['att_dir'];
            });
            if(!is_array($snapshot))throw new AuthUnavailableException();$this->observeCopyCommit($snapshot);
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';return $src;
        }catch(\Throwable $error){
            self::onlinePhysical($receipt,$observations,1);
            if($receipt['physical_changed']===false&&$receipt['database_committed']===false
                &&(($error instanceof HttpException&&in_array($error->getStatusCode(),[401,403,404,422],true))
                    ||($error instanceof AuthException&&!$error instanceof AuthUnavailableException)))throw $error;
            $this->copyFailure($receipt,$objects,$observations,1);throw new MerchantAttachmentOperationException($receipt);
        }finally{unset($images,$download,$qualified);}
    }
}
