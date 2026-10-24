<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\scope\PlatformScope;
use app\dao\marketplace\assets\PlatformAssetDao;
use app\model\system\config\SystemStorage;
use app\services\other\UploadService;
use app\services\system\config\SystemStorageServices;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use app\services\marketplace\attachment\MerchantAttachmentInput;
use app\services\marketplace\attachment\MerchantAttachmentReferenceBytes;
use app\services\marketplace\attachment\MerchantAttachmentReferenceFormat;
use PDO;
use think\facade\Config;
use think\facade\Filesystem;
use think\File;

/** Fixed site_image adapter over the same installed providers and complete-byte readers. */
final class PlatformAssetStorage
{
    public function __construct(private MerchantAttachmentStorage $physical) {}
    public function intake(Request $request): array { return $this->physical->readTypedUploadedFile($request); }
    public function qualify(string $bytes,string $name): array { return $this->physical->qualifyTypedUploadBytes($bytes,$name,'image'); }
    private function uploadContext(Request $request,PlatformScope $scope,PDO $pdo): array
    {
        if($scope->permission!==PlatformAssetServices::UPLOAD)MerchantAttachmentInput::unavailable();
        PlatformAssetServices::assertContext($request,$scope,$pdo);return [\think\facade\Db::connect(),$pdo,$scope->adminId];
    }
    private function storageRows(int $type): array
    {
        $rows = (new SystemStorage())->db()->where('type',$type)->where('is_delete',0)
            ->field(['id','type','name','region','domain','cdn','access_key','status'])->limit(1001)->select()->toArray();
        if (count($rows)>1000) MerchantAttachmentInput::unavailable();
        return $rows;
    }
    private function credentials(int $type): array
    {
        $config = app()->make(SystemStorageServices::class)->getStorageConfig($type);
        if (!is_array($config) || !is_string($config['accessKey']??null) || $config['accessKey']===''
            || !is_string($config['secretKey']??null) || $config['secretKey']==='') MerchantAttachmentInput::unavailable();
        return $config;
    }
    private static function domain(string $value): string
    {
        return $value !== '' && strpos($value,'http')===false ? 'http://'.$value : $value;
    }
    private function activeStorage(int $type): array
    {
        $credential = $this->credentials($type);
        $rows = array_values(array_filter($this->storageRows($type),static fn(array $row):bool =>
            (int)$row['status']===1 && $row['access_key']===$credential['accessKey']));
        if (count($rows)!==1) MerchantAttachmentInput::unavailable();
        self::storageShape($rows[0]);
        return $rows[0];
    }
    private function locatePhysical(array $row): array
    {
        $type = (int)$row['image_type'];
        if ($type===1) {
            $canonical = $row['att_dir'];
            if (!is_string($canonical) || !str_starts_with($canonical,'/uploads/') || basename($canonical)!==$row['name']) MerchantAttachmentInput::unavailable();
            $relative = substr($canonical,strlen('/uploads/'));
            self::key($relative);
            $family = [$canonical];
            if (($row['att_type']??null)!=='video/mp4') {
                foreach (['big','mid','small'] as $prefix) $family[] = substr($canonical,0,strrpos($canonical,'/')+1).$prefix.'_'.basename($canonical);
            }
            if (!in_array($row['satt_dir'],$family,true)) MerchantAttachmentInput::unavailable();
            $files=[];
            foreach ($family as $index=>$url) {
                $target=$this->localPath(substr($url,strlen('/uploads/')), $index===0);
                if ($target!==null) $files[]=['path'=>$target,'canonical'=>$url,'stat'=>lstat($target)];
            }
            return ['type'=>1,'files'=>$files,'canonical'=>$family,'storage'=>null,'key'=>$row['name']];
        }
        if ($type<2 || $type>7 || !is_string($row['name']) || !is_string($row['att_dir'])) MerchantAttachmentInput::unavailable();
        $key=$row['name'];self::key($key);
        $credential=$this->credentials($type);$matches=[];
        foreach ($this->storageRows($type) as $storage) {
            if ($storage['access_key']!==$credential['accessKey']) continue;
            foreach (array_unique([self::domain($storage['domain']),$storage['cdn']]) as $domain) {
                if ($domain!=='' && $row['att_dir']===$domain.'/'.$key) { $matches[(int)$storage['id']]=$storage;break; }
            }
        }
        if (count($matches)!==1) MerchantAttachmentInput::unavailable();
        $storage=array_values($matches)[0];self::storageShape($storage);
        if ($row['satt_dir']!==$row['att_dir']) MerchantAttachmentInput::unavailable();
        return ['type'=>$type,'files'=>[],'storage'=>$storage,'key'=>$key,
            'canonical'=>array_values(array_unique(array_filter([$row['att_dir'],self::domain($storage['domain']).'/'.$key,
                $storage['cdn']!=='' ? $storage['cdn'].'/'.$key : ''])))];
    }
    private static function storageShape(array $storage): void
    {
        foreach (['name','region','domain','cdn','access_key'] as $key) {
            if (!is_string($storage[$key]??null)) MerchantAttachmentInput::unavailable();
        }
        if ($storage['name']==='' || $storage['region']==='' || preg_match('/\A[A-Za-z0-9_.-]+\z/D',$storage['name'])!==1) MerchantAttachmentInput::unavailable();
        $domain=self::domain($storage['domain']);
        foreach (array_filter([$domain,$storage['cdn']]) as $url) {
            $parsed=parse_url($url);
            if (!is_array($parsed) || !in_array($parsed['scheme']??null,['http','https'],true)
                || !is_string($parsed['host']??null) || $parsed['host']===''
                || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) MerchantAttachmentInput::unavailable();
        }
        if ($domain==='') MerchantAttachmentInput::unavailable();
    }
    private static function key(string $key): void
    {
        if (strlen($key)>512 || preg_match('/\A[A-Za-z0-9_\-\/\.]+\z/D',$key)!==1
            || str_starts_with($key,'/') || str_contains($key,'//')) MerchantAttachmentInput::unavailable();
        foreach (explode('/',$key) as $part) if ($part==='' || $part==='.' || $part==='..') MerchantAttachmentInput::unavailable();
    }
    private function localPath(string $relative, bool $required): ?string
    {
        self::key($relative);
        $root = realpath(Config::get('filesystem.disks.public.root',''));
        if ($root===false || !is_dir($root) || Config::get('filesystem.disks.public.type')!=='local' || Config::get('filesystem.disks.public.url')!=='/uploads') MerchantAttachmentInput::unavailable();
        $target=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $cursor=$root;
        foreach (explode('/',$relative) as $part) {
            $cursor.=DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor)) MerchantAttachmentInput::unavailable();
        }
        if (!file_exists($target)) {
            if ($required) MerchantAttachmentInput::unavailable();
            return null;
        }
        $actual=realpath($target);
        if ($actual===false || !is_file($actual) || !str_starts_with($actual,$root.DIRECTORY_SEPARATOR)) MerchantAttachmentInput::unavailable();
        return $actual;
    }
    public function identity(array $object): string
    {
        return $object['type']===1 ? 'local:'.$object['files'][0]['path']
            : 'cloud:'.$object['type'].':'.$object['storage']['id'].':'.$object['key'];
    }
    /** Reading may advance atime; all other original locator/stat facts stay exact. */
    private static function referenceLocatorFacts(array $object): array
    {
        foreach($object['files'] as&$file)unset($file['stat'][8],$file['stat']['atime']);
        unset($file);return $object;
    }

    private function referenceBytes(array $object,?array $credential,string $canonical,int $size,?string &$servedMime=null): string
    {
        $servedMime=null;
        if($object['type']===1){
            $files=array_values(array_filter($object['files'],static fn(array $file):bool=>$file['canonical']===$canonical));
            if(count($files)!==1)MerchantAttachmentInput::unavailable();
            return MerchantAttachmentReferenceBytes::local($files[0],$size,$size);
        }
        if($credential===null)MerchantAttachmentInput::unavailable();
        return MerchantAttachmentReferenceBytes::cloud($object,$credential,$canonical,$size,$size,$servedMime);
    }
    public function upload(Request $request,PlatformScope $scope,PDO $pdo,string $bytes,string $displayName,array &$object,array &$receipt): array
    {
        $expectedKind='image';$state=$this->uploadContext($request,$scope,$pdo);
        if($object!==[])MerchantAttachmentInput::unavailable();
        $receiptKeys=array_keys($receipt);sort($receiptKeys,SORT_STRING);
        if($receiptKeys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||$receipt['complete']!==false||$receipt['phase']!=='preflight'||$receipt['physical_changed']!==false
            ||$receipt['physical_complete']!==false||$receipt['database_committed']!==false||$receipt['cleanup_complete']!==null)MerchantAttachmentInput::unavailable();
        $qualified=$this->qualify($bytes,$displayName);
        \app\services\marketplace\MerchantManagementInput::text($displayName,255,true);
        if($this->uploadContext($request,$scope,$pdo)!==$state)MerchantAttachmentInput::unavailable();
        $configured=sys_config('upload_type',1);
        if(is_string($configured)&&preg_match('/\A[1-7]\z/D',$configured)===1)$configured=(int)$configured;
        if(!is_int($configured)||$configured<1||$configured>7)MerchantAttachmentInput::unavailable();
        $type=$configured;$storage=$type===1?null:$this->activeStorage($type);
        $directory='platform/site_image/'.bin2hex(random_bytes(16));$fileName=bin2hex(random_bytes(16)).'.'.$qualified['extension'];
        $key=($type===1?'':'uploads/').$directory.'/'.$fileName;self::key($key);
        $localRoot=$type===1?realpath(Config::get('filesystem.disks.public.root','')):null;
        if($type===1&&($localRoot===false||Config::get('filesystem.disks.public.type')!=='local'
            ||Config::get('filesystem.disks.public.url')!=='/uploads'))MerchantAttachmentInput::unavailable();
        $object=['type'=>$type,'directory'=>$directory,'local_root'=>$localRoot,'storage'=>$storage,'files'=>[],'key'=>$type===1?$fileName:$key];
        if($type===1){
            $relative=$directory.'/'.$fileName;$destination=$localRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            $cursor=$localRoot;foreach(explode('/',$directory)as$part){$cursor.=DIRECTORY_SEPARATOR.$part;
                if(is_link($cursor)||(file_exists($cursor)&&!is_dir($cursor)))MerchantAttachmentInput::unavailable();}
            if(file_exists($cursor)||is_link($cursor)||$this->localPath($relative,false)!==null)MerchantAttachmentInput::unavailable();
            $disk=Filesystem::disk('public');
            if(realpath($disk->path(''))!==$localRoot)MerchantAttachmentInput::unavailable();
            $temporary=null;$temporaryPath=null;$failure=null;$row=null;
            try{
                $temporary=tmpfile();if(!is_resource($temporary))MerchantAttachmentInput::unavailable();
                $meta=stream_get_meta_data($temporary);$uri=$meta['uri']??null;
                $temporaryPath=is_string($uri)?realpath($uri):false;$temporaryRoot=realpath(sys_get_temp_dir());
                $publicRoot=realpath(app()->getRootPath().'public');$initial=fstat($temporary);
                if(!is_string($temporaryPath)||$temporaryRoot===false||$publicRoot===false||dirname($temporaryPath)!==$temporaryRoot
                    ||$temporaryPath===$publicRoot||str_starts_with($temporaryPath,$publicRoot.DIRECTORY_SEPARATOR)
                    ||$temporaryPath===$localRoot||str_starts_with($temporaryPath,$localRoot.DIRECTORY_SEPARATOR)
                    ||is_link($uri)||!is_array($initial)||($initial['mode']&0170000)!==0100000||($initial['mode']&0777)!==0600
                    ||$initial['size']!==0)MerchantAttachmentInput::unavailable();
                if(fwrite($temporary,$bytes)!==$qualified['size']||fflush($temporary)!==true)MerchantAttachmentInput::unavailable();
                clearstatcache(true,$temporaryPath);$written=fstat($temporary);$native=lstat($temporaryPath);
                if(!is_array($written)||!is_array($native)||is_link($temporaryPath)||$written['size']!==$qualified['size'])MerchantAttachmentInput::unavailable();
                foreach(['dev','ino','mode','nlink','uid','gid']as$field)if($written[$field]!==$initial[$field]||$native[$field]!==$written[$field])MerchantAttachmentInput::unavailable();
                if($native['size']!==$qualified['size']||hash_file('sha256',$temporaryPath)!==$qualified['sha256'])MerchantAttachmentInput::unavailable();
                clearstatcache(true,$temporaryPath);$checked=lstat($temporaryPath);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($checked)||$checked[$field]!==$native[$field])MerchantAttachmentInput::unavailable();
                if($this->uploadContext($request,$scope,$pdo)!==$state)MerchantAttachmentInput::unavailable();
                $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
                $result=$disk->putFileAs($directory,new File($temporaryPath),$fileName);
                if($result!==$relative)MerchantAttachmentInput::unavailable();
                $actual=$this->localPath($relative,true);
                if($actual!==$destination||realpath($disk->path($relative))!==$actual)MerchantAttachmentInput::unavailable();
                $receipt['physical_changed']=true;clearstatcache(true,$actual);$savedBefore=lstat($actual);
                if(!is_array($savedBefore)||($savedBefore['mode']&0170000)!==0100000||is_link($actual)||$savedBefore['size']!==$qualified['size'])MerchantAttachmentInput::unavailable();
                $saved=file_get_contents($actual);
                if(!is_string($saved)||$saved!==$bytes||strlen($saved)!==$qualified['size']||hash('sha256',$saved)!==$qualified['sha256']
                    ||mime_content_type($actual)!==$qualified['mime'])MerchantAttachmentInput::unavailable();
                unset($saved);clearstatcache(true,$actual);$savedAfter=lstat($actual);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($savedAfter)||$savedAfter[$field]!==$savedBefore[$field])MerchantAttachmentInput::unavailable();
                clearstatcache(true,$temporaryPath);$intakeAfter=fstat($temporary);$intakePathAfter=lstat($temporaryPath);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($intakeAfter)||!is_array($intakePathAfter)
                    ||$intakeAfter[$field]!==$checked[$field]||$intakePathAfter[$field]!==$checked[$field])MerchantAttachmentInput::unavailable();
                $path='/uploads/'.$relative;
                $row=['name'=>$fileName,'real_name'=>$displayName,'att_dir'=>$path,'satt_dir'=>$path,
                    'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>1];
                $object=$this->locatePhysical($row)+['directory'=>$directory,'local_root'=>$localRoot];
                if(($object['files'][0]['path']??null)!==$actual)MerchantAttachmentInput::unavailable();
            }catch(\Throwable $error){$failure=$error;}
            finally{
                if($temporary!==null){
                    $closed=false;
                    try{$closed=is_resource($temporary)&&fclose($temporary)===true;}catch(\Throwable $error){$failure??=$error;}
                    $temporary=null;
                    if(is_string($temporaryPath))clearstatcache(true,$temporaryPath);
                    if(!$closed||!is_string($temporaryPath)||file_exists($temporaryPath)||is_link($temporaryPath)){
                        $receipt['cleanup_complete']=false;$failure??=new \crmeb\exceptions\AuthUnavailableException();
                    }
                }
            }
            if($failure!==null)throw $failure;
            if(!is_array($row))MerchantAttachmentInput::unavailable();
        }else{
            if($this->uploadContext($request,$scope,$pdo)!==$state)MerchantAttachmentInput::unavailable();
            $upload=UploadService::fresh($type,$storage);$upload->setAuthThumb(false);
            $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
            $upload->to('uploads/'.$directory);
            $result=$upload->streamTyped($bytes,$fileName,$qualified['mime']);$info=$upload->getFileInfo();
            if(!$info instanceof \StdClass||$result!==$info||!isset($info->fileName,$info->filePath)
                ||!is_string($info->fileName)||$info->fileName!==$key||!is_string($info->filePath))MerchantAttachmentInput::unavailable();
            if(in_array($type,[4,6,7],true)){
                $observed=$upload->observation();
                if(($observed['transport_complete']??null)!==true||!is_int($observed['http_status']??null)
                    ||$observed['http_status']<200||$observed['http_status']>=300)MerchantAttachmentInput::unavailable();
            }elseif($type===2){if(!isset($info->uploadInfo)||!is_array($info->uploadInfo)||$info->uploadInfo===[])MerchantAttachmentInput::unavailable();}
            elseif($type===3){if(!isset($info->uploadInfo['info']['url'])||!is_string($info->uploadInfo['info']['url'])||$info->uploadInfo['info']['url']==='')MerchantAttachmentInput::unavailable();}
            elseif($type===5){if(($info->uploadInfo['@metadata']['statusCode']??null)!==200)MerchantAttachmentInput::unavailable();}
            else MerchantAttachmentInput::unavailable();
            \app\services\marketplace\MerchantManagementInput::text($info->fileName,100,true);
            \app\services\marketplace\MerchantManagementInput::text($info->filePath,2048,true);
            $row=['name'=>$info->fileName,'real_name'=>$displayName,'att_dir'=>$info->filePath,'satt_dir'=>$info->filePath,
                'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>$type];
            $object=$this->locatePhysical($row)+['directory'=>$directory,'local_root'=>null];
            if($object['key']!==$key||$object['storage']!==$storage)MerchantAttachmentInput::unavailable();
            // A provider ACK is not an independent read of the stored cloud bytes: changed remains null.
        }
        if($this->uploadContext($request,$scope,$pdo)!==$state)MerchantAttachmentInput::unavailable();
        $row['type']=$qualified['type'];
        $credential=$type===1?null:$this->credentials($type);
        $locatedBefore=$object;$receipt['phase']='physical_readback';
        // Local canonical includes possible thumbnail names; read only real
        // located files and require the actual original. Cloud canonical has
        // only the configured origin/CDN aliases of this same stored key.
        $readCanonicals=$type===1?array_column($object['files'],'canonical'):$object['canonical'];
        if($readCanonicals===[]||!in_array($row['att_dir'],$readCanonicals,true))MerchantAttachmentInput::unavailable();
        foreach(array_values(array_unique($readCanonicals))as$canonical){
            $servedMime=null;
            $stored=$this->referenceBytes($object,$credential,$canonical,$qualified['size'],$servedMime);
            if(strlen($stored)!==$qualified['size']||hash('sha256',$stored)!==$qualified['sha256'])MerchantAttachmentInput::unavailable();
            $observed=MerchantAttachmentReferenceFormat::qualify($stored);
            if($observed['resolved_kind']!==$qualified['resolved_kind']||$observed['bytes']!==$qualified['size']
                ||$observed['sha256']!==$qualified['sha256']||$observed['mime']!==$qualified['mime']
                ||$observed['format']!==$qualified['format'])MerchantAttachmentInput::unavailable();
            // A header alone is not content proof. Require the same full GET's
            // finite MIME family only for newly produced cloud objects.
            if($type!==1&&($servedMime===null||!in_array($servedMime,$observed['mime_aliases'],true)))MerchantAttachmentInput::unavailable();
            unset($stored,$observed,$servedMime);
            if($this->uploadContext($request,$scope,$pdo)!==$state)MerchantAttachmentInput::unavailable();
        }
        $objectAfter=$this->locatePhysical($row)+['directory'=>$directory,'local_root'=>$localRoot];
        if(self::referenceLocatorFacts($objectAfter)!==self::referenceLocatorFacts($locatedBefore))MerchantAttachmentInput::unavailable();
        $currentType=sys_config('upload_type',1);
        if(is_string($currentType)&&preg_match('/\A[1-7]\z/D',$currentType)===1)$currentType=(int)$currentType;
        if($currentType!==$type||($type!==1&&($this->activeStorage($type)!==$storage||$this->credentials($type)!==$credential)))MerchantAttachmentInput::unavailable();
        if($this->qualify($bytes,$displayName)!==$qualified
            ||$this->uploadContext($request,$scope,$pdo)!==$state)MerchantAttachmentInput::unavailable();
        $object=$objectAfter;
        // Cloud changed stays unknown: readback proves current contents, not
        // the pre-upload state. The publisher retains the original six keys.
        $receipt['physical_complete']=true;return $row;
    }

    /** Native29 authorizes its actual key; this physical projection is never a DB native14. */
    public function locate(array $row): array
    {
        PlatformAssetDao::asset($row);$type=PlatformAssetDao::integer($row['storage_type'],1,7);$key=$row['object_key'];self::key($key);
        if(basename($key)!==$row['name'])MerchantAttachmentInput::unavailable();
        if($type===1){
            $url='/uploads/'.$key;$name=$row['name'];
        }else{
            $storage=$this->activeStorage($type);$url=self::domain($storage['domain']).'/'.$key;$name=$key;
        }
        return $this->locatePhysical(['image_type'=>$type,'att_dir'=>$url,'satt_dir'=>$url,'name'=>$name,'att_type'=>$row['media_type']]);
    }
    public function objectKey(array $object,array $physical): string
    {
        $key=$object['type']===1?substr($physical['att_dir'],strlen('/uploads/')):$object['key'];self::key($key);return $key;
    }
    public static function proof(array $object): array
    {
        foreach($object['files']as&$file){unset($file['stat'][8],$file['stat']['atime']);}unset($file);return $object;
    }
    public function presentation(array $row): array
    {
        $object=$this->locate($row);$url=$object['type']===1?'/uploads/'.$row['object_key']:self::domain($object['storage']['domain']).'/'.$object['key'];
        return ['platform_asset_id'=>PlatformAssetDao::id($row['id']),'src'=>$url,'satt_dir'=>$url,'name'=>$row['name'],'real_name'=>$row['real_name'],
            'category_id'=>PlatformAssetDao::integer($row['category_id'],0,2147483647),'state'=>$row['state'],'byte_size'=>(string)$row['byte_size'],
            'width'=>PlatformAssetDao::integer($row['width'],1,4294967295),'height'=>PlatformAssetDao::integer($row['height'],1,4294967295),'media_type'=>$row['media_type']];
    }
    public function read(Request $request,PlatformScope $scope,PDO $pdo,array $row,bool $inside=true): array
    {
        PlatformAssetServices::assertContext($request,$scope,$pdo,$inside);PlatformAssetDao::asset($row);$before=$this->locate($row);
        $size=PlatformAssetDao::integer($row['byte_size'],1,PHP_INT_MAX);$credential=$before['type']===1?null:$this->credentials($before['type']);
        $canonical=$before['type']===1?'/uploads/'.$row['object_key']:self::domain($before['storage']['domain']).'/'.$before['key'];
        $servedMime=null;$bytes=$this->referenceBytes($before,$credential,$canonical,$size,$servedMime);
        try{
            $facts=MerchantAttachmentReferenceFormat::qualify($bytes);
            if($facts['resolved_kind']!=='image'||$facts['bytes']!==$size||$facts['sha256']!==bin2hex($row['content_sha256'])
                ||!in_array($row['media_type'],$facts['mime_aliases'],true)||(string)$facts['format']['width']!==(string)$row['width']
                ||(string)$facts['format']['height']!==(string)$row['height'])MerchantAttachmentInput::unavailable();
            if($before['type']!==1&&($servedMime===null||!in_array($servedMime,$facts['mime_aliases'],true)))MerchantAttachmentInput::unavailable();
            $after=$this->locate($row);
            if(self::proof($after)!==self::proof($before)||($before['type']!==1&&$this->credentials($before['type'])!==$credential))MerchantAttachmentInput::unavailable();
            PlatformAssetServices::assertContext($request,$scope,$pdo,$inside);
            return ['bytes'=>$bytes,'display_name'=>$row['real_name'],'qualified'=>$facts,'object'=>$after];
        }finally{unset($bytes);}
    }
    /** Only this request's new random directory, never a source imported from the library. */
    public function cleanup(Request $request,PlatformScope $scope,PDO $pdo,array $object,array &$receipt): void
    {
        if($scope->permission!==PlatformAssetServices::UPLOAD||$receipt['database_committed']!==false)MerchantAttachmentInput::unavailable();
        PlatformAssetServices::assertContext($request,$scope,$pdo,$pdo->inTransaction());
        $directory=$object['directory']??null;
        if(!is_string($directory)||preg_match('/\Aplatform\/site_image\/[a-f0-9]{32}\z/D',$directory)!==1)MerchantAttachmentInput::unavailable();
        $key=$object['key']??null;$pattern=$object['type']===1?'/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp|ico)\z/D':'/\Auploads\/platform\/site_image\/[a-f0-9]{32}\/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp|ico)\z/D';
        if(!is_string($key)||preg_match($pattern,$key)!==1)MerchantAttachmentInput::unavailable();
        $prior=$receipt['cleanup_complete'];$cleanup=$receipt;$ok=$this->physical->cleanupNew($object,$cleanup);
        // Cloud DELETE ACK is not independent absence proof. Keep it unknown.
        $receipt['cleanup_complete']=$prior===false?false:(!$ok?false:($object['type']===1?true:null));
    }
}
