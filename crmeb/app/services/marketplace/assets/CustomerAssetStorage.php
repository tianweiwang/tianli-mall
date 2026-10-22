<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\model\system\config\SystemStorage;
use app\services\other\UploadService;
use app\services\system\config\SystemStorageServices;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use crmeb\exceptions\AuthUnavailableException;
use think\facade\Config;
use think\facade\Filesystem;
use think\File;

/** Review-only physical leaf. Private object locators never enter an HTTP DTO. */
final class CustomerAssetStorage
{
    public function __construct(private MerchantAttachmentStorage $images){}

    public function qualify(string $bytes,string $originalName):array
    {
        \app\services\marketplace\MerchantManagementInput::text($originalName,255,true);
        if(str_contains($originalName,'/')||str_contains($originalName,'\\'))self::fail();
        $qualified=$this->images->qualifyImageBytes($bytes);$extension=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));
        $allowed=Config::get('upload.fileExt',[]);if(is_string($allowed))$allowed=explode(',',$allowed);
        if(!is_array($allowed)||!in_array($extension,$allowed,true))self::fail();
        $mime=match($extension){'jpg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',default=>self::fail()};
        if($mime!==$qualified['mime']&&!($mime==='image/jpeg'&&$qualified['mime']==='image/jpg'))self::fail();return $qualified;
    }

    private function storageRows(int $type): array
    {
        $rows = (new SystemStorage())->db()->where('type',$type)->where('is_delete',0)
            ->field(['id','type','name','region','domain','cdn','access_key','status'])->limit(1001)->select()->toArray();
        if (count($rows)>1000) self::fail();
        return $rows;
    }
    private function credentials(int $type): array
    {
        $config = app()->make(SystemStorageServices::class)->getStorageConfig($type);
        if (!is_array($config) || !is_string($config['accessKey']??null) || $config['accessKey']===''
            || !is_string($config['secretKey']??null) || $config['secretKey']==='') self::fail();
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
        if (count($rows)!==1) self::fail();
        self::storageShape($rows[0]);
        return $rows[0];
    }
    private function locateObject(array $row): array
    {
        $type = (int)$row['image_type'];
        if ($type===1) {
            $canonical = $row['att_dir'];
            if (!is_string($canonical) || !str_starts_with($canonical,'/uploads/') || basename($canonical)!==$row['name']) self::fail();
            $relative = substr($canonical,strlen('/uploads/'));
            self::key($relative);
            $family = [$canonical];
            if (($row['att_type']??null)!=='video/mp4') {
                foreach (['big','mid','small'] as $prefix) $family[] = substr($canonical,0,strrpos($canonical,'/')+1).$prefix.'_'.basename($canonical);
            }
            if (!in_array($row['satt_dir'],$family,true)) self::fail();
            $files=[];
            foreach ($family as $index=>$url) {
                $target=$this->localPath(substr($url,strlen('/uploads/')), $index===0);
                if ($target!==null) $files[]=['path'=>$target,'canonical'=>$url,'stat'=>lstat($target)];
            }
            return ['type'=>1,'files'=>$files,'canonical'=>$family,'storage'=>null,'key'=>$row['name']];
        }
        if ($type<2 || $type>7 || !is_string($row['name']) || !is_string($row['att_dir'])) self::fail();
        $key=$row['name'];self::key($key);
        $credential=$this->credentials($type);$matches=[];
        foreach ($this->storageRows($type) as $storage) {
            if ($storage['access_key']!==$credential['accessKey']) continue;
            foreach (array_unique([self::domain($storage['domain']),$storage['cdn']]) as $domain) {
                if ($domain!=='' && $row['att_dir']===$domain.'/'.$key) { $matches[(int)$storage['id']]=$storage;break; }
            }
        }
        if (count($matches)!==1) self::fail();
        $storage=array_values($matches)[0];self::storageShape($storage);
        if ($row['satt_dir']!==$row['att_dir']) self::fail();
        return ['type'=>$type,'files'=>[],'storage'=>$storage,'key'=>$key,
            'canonical'=>array_values(array_unique(array_filter([$row['att_dir'],self::domain($storage['domain']).'/'.$key,
                $storage['cdn']!=='' ? $storage['cdn'].'/'.$key : ''])))];
    }
    private static function storageShape(array $storage): void
    {
        foreach (['name','region','domain','cdn','access_key'] as $key) {
            if (!is_string($storage[$key]??null)) self::fail();
        }
        if ($storage['name']==='' || $storage['region']==='' || preg_match('/\A[A-Za-z0-9_.-]+\z/D',$storage['name'])!==1) self::fail();
        $domain=self::domain($storage['domain']);
        foreach (array_filter([$domain,$storage['cdn']]) as $url) {
            $parsed=parse_url($url);
            if (!is_array($parsed) || !in_array($parsed['scheme']??null,['http','https'],true)
                || !is_string($parsed['host']??null) || $parsed['host']===''
                || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) self::fail();
        }
        if ($domain==='') self::fail();
    }
    private static function key(string $key): void
    {
        if (strlen($key)>200 || preg_match('/\A[A-Za-z0-9_\-\/\.]+\z/D',$key)!==1
            || str_starts_with($key,'/') || str_contains($key,'//')) self::fail();
        foreach (explode('/',$key) as $part) if ($part==='' || $part==='.' || $part==='..') self::fail();
    }
    private function localPath(string $relative, bool $required): ?string
    {
        self::key($relative);
        $root = realpath(Config::get('filesystem.disks.public.root',''));
        if ($root===false || !is_dir($root) || Config::get('filesystem.disks.public.url')!=='/uploads') self::fail();
        $target=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $cursor=$root;
        foreach (explode('/',$relative) as $part) {
            $cursor.=DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor)) self::fail();
        }
        if (!file_exists($target)) {
            if ($required) self::fail();
            return null;
        }
        $actual=realpath($target);
        if ($actual===false || !is_file($actual) || !str_starts_with($actual,$root.DIRECTORY_SEPARATOR)) self::fail();
        return $actual;
    }
    public function upload(array $context,string $bytes,string $displayName,array &$object,array &$receipt): array
    {
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);$uid=$context['actual_uid'];
        if($object!==[])self::fail();
        $receiptKeys=array_keys($receipt);sort($receiptKeys,SORT_STRING);
        if($receiptKeys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||$receipt['complete']!==false||$receipt['phase']!=='preflight'||$receipt['physical_changed']!==false
            ||$receipt['physical_complete']!==false||$receipt['database_committed']!==false||$receipt['cleanup_complete']!==null)self::fail();
        $qualified=$this->qualify($bytes,$displayName);
        \app\services\marketplace\MerchantManagementInput::text($displayName,255,true);
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
        $configured=sys_config('upload_type',1);
        if(is_string($configured)&&preg_match('/\A[1-7]\z/D',$configured)===1)$configured=(int)$configured;
        if(!is_int($configured)||$configured<1||$configured>7)self::fail();
        $type=$configured;$storage=$type===1?null:$this->activeStorage($type);
        $directory='customer/'.$uid.'/'.bin2hex(random_bytes(16));$fileName=bin2hex(random_bytes(16)).'.'.$qualified['extension'];
        $key=($type===1?'':'uploads/').$directory.'/'.$fileName;self::key($key);
        $localRoot=$type===1?realpath(Config::get('filesystem.disks.public.root','')):null;
        if($type===1&&($localRoot===false||Config::get('filesystem.disks.public.type')!=='local'
            ||Config::get('filesystem.disks.public.url')!=='/uploads'))self::fail();
        $expectedPath=$type===1?'/uploads/'.$directory.'/'.$fileName:self::domain($storage['domain']).'/'.$key;
        if(strlen($type===1?$fileName:$key)>100||strlen($expectedPath)>200)self::fail();
        if($type!==1&&$storage['cdn']!==''&&strlen($storage['cdn'].'/'.$key)>200)self::fail();
        $object=['type'=>$type,'directory'=>$directory,'local_root'=>$localRoot,'storage'=>$storage,'files'=>[],'key'=>$type===1?$fileName:$key];
        if($type===1){
            $relative=$directory.'/'.$fileName;$destination=$localRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            $cursor=$localRoot;foreach(explode('/',$directory)as$part){$cursor.=DIRECTORY_SEPARATOR.$part;
                if(is_link($cursor)||(file_exists($cursor)&&!is_dir($cursor)))self::fail();}
            if(file_exists($cursor)||is_link($cursor)||$this->localPath($relative,false)!==null)self::fail();
            $disk=Filesystem::disk('public');
            if(realpath($disk->path(''))!==$localRoot)self::fail();
            $temporary=null;$temporaryPath=null;$failure=null;$row=null;
            try{
                $temporary=tmpfile();if(!is_resource($temporary))self::fail();
                $meta=stream_get_meta_data($temporary);$uri=$meta['uri']??null;
                $temporaryPath=is_string($uri)?realpath($uri):false;$temporaryRoot=realpath(sys_get_temp_dir());
                $publicRoot=realpath(app()->getRootPath().'public');$initial=fstat($temporary);
                if(!is_string($temporaryPath)||$temporaryRoot===false||$publicRoot===false||dirname($temporaryPath)!==$temporaryRoot
                    ||$temporaryPath===$publicRoot||str_starts_with($temporaryPath,$publicRoot.DIRECTORY_SEPARATOR)
                    ||$temporaryPath===$localRoot||str_starts_with($temporaryPath,$localRoot.DIRECTORY_SEPARATOR)
                    ||is_link($uri)||!is_array($initial)||($initial['mode']&0170000)!==0100000||($initial['mode']&0777)!==0600
                    ||$initial['size']!==0)self::fail();
                if(fwrite($temporary,$bytes)!==$qualified['size']||fflush($temporary)!==true)self::fail();
                clearstatcache(true,$temporaryPath);$written=fstat($temporary);$native=lstat($temporaryPath);
                if(!is_array($written)||!is_array($native)||is_link($temporaryPath)||$written['size']!==$qualified['size'])self::fail();
                foreach(['dev','ino','mode','nlink','uid','gid']as$field)if($written[$field]!==$initial[$field]||$native[$field]!==$written[$field])self::fail();
                if($native['size']!==$qualified['size']||hash_file('sha256',$temporaryPath)!==$qualified['sha256'])self::fail();
                clearstatcache(true,$temporaryPath);$checked=lstat($temporaryPath);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($checked)||$checked[$field]!==$native[$field])self::fail();
                MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
                $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
                $result=$disk->putFileAs($directory,new File($temporaryPath),$fileName);
                if($result!==$relative)self::fail();
                $actual=$this->localPath($relative,true);
                if($actual!==$destination||realpath($disk->path($relative))!==$actual)self::fail();
                $receipt['physical_changed']=true;clearstatcache(true,$actual);$savedBefore=lstat($actual);
                if(!is_array($savedBefore)||($savedBefore['mode']&0170000)!==0100000||is_link($actual)||$savedBefore['size']!==$qualified['size'])self::fail();
                $saved=file_get_contents($actual);
                if(!is_string($saved)||$saved!==$bytes||strlen($saved)!==$qualified['size']||hash('sha256',$saved)!==$qualified['sha256']
                    ||mime_content_type($actual)!==$qualified['mime'])self::fail();
                unset($saved);clearstatcache(true,$actual);$savedAfter=lstat($actual);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($savedAfter)||$savedAfter[$field]!==$savedBefore[$field])self::fail();
                clearstatcache(true,$temporaryPath);$intakeAfter=fstat($temporary);$intakePathAfter=lstat($temporaryPath);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($intakeAfter)||!is_array($intakePathAfter)
                    ||$intakeAfter[$field]!==$checked[$field]||$intakePathAfter[$field]!==$checked[$field])self::fail();
                $path='/uploads/'.$relative;
                $row=['name'=>$fileName,'real_name'=>$displayName,'att_dir'=>$path,'satt_dir'=>$path,
                    'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>1];
                $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>$localRoot];
                if(($object['files'][0]['path']??null)!==$actual)self::fail();
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
            if(!is_array($row))self::fail();
        }else{
            MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
            $upload=UploadService::fresh($type,$storage);$upload->setAuthThumb(false);
            $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
            $result=$upload->to('uploads/'.$directory)->stream($bytes,$fileName);$info=$upload->getFileInfo();
            if(!$info instanceof \StdClass||$result!==$info||!isset($info->fileName,$info->filePath)
                ||!is_string($info->fileName)||$info->fileName!==$key||!is_string($info->filePath))self::fail();
            if(in_array($type,[4,6,7],true)){
                $observed=$upload->observation();
                if(($observed['transport_complete']??null)!==true||!is_int($observed['http_status']??null)
                    ||$observed['http_status']<200||$observed['http_status']>=300)self::fail();
            }elseif($type===2){if(!isset($info->uploadInfo)||!is_array($info->uploadInfo)||$info->uploadInfo===[])self::fail();}
            elseif($type===3){if(!isset($info->uploadInfo['info']['url'])||!is_string($info->uploadInfo['info']['url'])||$info->uploadInfo['info']['url']==='')self::fail();}
            elseif($type===5){if(($info->uploadInfo['@metadata']['statusCode']??null)!==200)self::fail();}
            else self::fail();
            \app\services\marketplace\MerchantManagementInput::text($info->fileName,100,true);
            \app\services\marketplace\MerchantManagementInput::text($info->filePath,200,true);
            if(!in_array($info->filePath,array_values(array_unique(array_filter([self::domain($storage['domain']).'/'.$key,
                $storage['cdn']!==''?$storage['cdn'].'/'.$key:'']))),true)||strlen($info->filePath)>200)self::fail();
            $row=['name'=>$info->fileName,'real_name'=>$displayName,'att_dir'=>$info->filePath,'satt_dir'=>$info->filePath,
                'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>$type];
            $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>null];
            if($object['key']!==$key||$object['storage']!==$storage)self::fail();
            // A provider ACK is not an independent read of the stored cloud bytes: changed remains null.
        }
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
        $receipt['physical_complete']=true;return $row;
    }

    /** Read only: local full bytes; cloud current storage/credential binding plus the earlier observed ACK. */
    public function assertStored(array $context,array $row,array $qualified,string $bytes,array $object):void
    {
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);$this->ownedObject($context,$object);
        if($this->qualify($bytes,$row['real_name'])!==$qualified||$row['att_dir']!==$row['satt_dir']
            ||$row['att_type']!==$qualified['mime']||(string)$row['att_size']!==(string)$qualified['size'])self::fail();
        $observed=$this->locateObject($row)+['directory'=>$object['directory'],'local_root'=>$object['local_root']];
        $actualFiles=$observed['files'];$expectedFiles=$object['files'];unset($observed['files']);$held=$object;unset($held['files']);
        if($observed!==$held||count($actualFiles)!==count($expectedFiles))self::fail();
        foreach($actualFiles as$position=>$file){$expected=$expectedFiles[$position];
            if($file['path']!==$expected['path']||$file['canonical']!==$expected['canonical'])self::fail();
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if($file['stat'][$key]!==$expected['stat'][$key])self::fail();
        }
        if($object['type']===1){
            $path=$object['files'][0]['path']??null;if(!is_string($path))self::fail();clearstatcache(true,$path);$before=lstat($path);
            $actual=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);
            if(!is_array($before)||!is_array($after)||is_link($path)||($before['mode']&0170000)!==0100000||$actual!==$bytes
                ||$before['size']!==$qualified['size']||hash('sha256',$actual)!==$qualified['sha256']||mime_content_type($path)!==$qualified['mime'])self::fail();
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])self::fail();
        }elseif($this->activeStorage($object['type'])!==$object['storage'])self::fail();
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
    }

    /** Only the actual new generated namespace, while the original real frame is still active and no DML was attempted. */
    public function cleanup(array $context,array $object,array &$receipt):void
    {
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);$this->ownedObject($context,$object);
        if($receipt['database_committed']!==false)self::fail();$heldFalse=$receipt['cleanup_complete']===false;
        $cleanup=$receipt;$ok=$this->images->cleanupNew($object,$cleanup);
        // Preserve upload facts; cleanup cannot manufacture a known pre-upload state or replace a prior unknown.
        $receipt['cleanup_complete']=$heldFalse?false:($ok&&($object['type']===1||$cleanup['physical_complete']===true));
        MarketplaceOrderReviewServices::assertCustomerUploadContext($context);
    }
    private function ownedObject(array $context,array $object):void
    {
        $uid=$context['actual_uid'];
        if(!is_int($object['type']??null)||$object['type']<1||$object['type']>7||!is_string($object['directory']??null)
            ||preg_match('/\Acustomer\/'.$uid.'\/[a-f0-9]{32}\z/D',$object['directory'])!==1)self::fail();
        if($object['type']===1){if(!is_string($object['key']??null)||preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)\z/D',$object['key'])!==1)self::fail();}
        elseif(!is_string($object['key']??null)||preg_match('/\Auploads\/customer\/'.$uid.'\/[a-f0-9]{32}\/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)\z/D',$object['key'])!==1)self::fail();
    }
    private static function fail():never {throw new AuthUnavailableException();}

    /** Avatar uses a separate actual customer frame. Original review-only authority above is unchanged. */
    public function uploadForAvatar(array $context,string $bytes,string $displayName,array &$object,array &$receipt): array
    {
        CustomerAssetServices::assertAvatarContext($context,'upload');$uid=$context['actual_uid'];
        if($object!==[])self::fail();
        $receiptKeys=array_keys($receipt);sort($receiptKeys,SORT_STRING);
        if($receiptKeys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||$receipt['complete']!==false||$receipt['phase']!=='preflight'||$receipt['physical_changed']!==false
            ||$receipt['physical_complete']!==false||$receipt['database_committed']!==false||$receipt['cleanup_complete']!==null)self::fail();
        $qualified=$this->qualify($bytes,$displayName);
        \app\services\marketplace\MerchantManagementInput::text($displayName,255,true);
        CustomerAssetServices::assertAvatarContext($context,'upload');
        $configured=sys_config('upload_type',1);
        if(is_string($configured)&&preg_match('/\A[1-7]\z/D',$configured)===1)$configured=(int)$configured;
        if(!is_int($configured)||$configured<1||$configured>7)self::fail();
        $type=$configured;$storage=$type===1?null:$this->activeStorage($type);
        $directory='customer/a/'.$uid.'/'.bin2hex(random_bytes(16));$fileName=bin2hex(random_bytes(16)).'.'.$qualified['extension'];
        $key=($type===1?'':'uploads/').$directory.'/'.$fileName;self::key($key);
        $localRoot=$type===1?realpath(Config::get('filesystem.disks.public.root','')):null;
        if($type===1&&($localRoot===false||Config::get('filesystem.disks.public.type')!=='local'
            ||Config::get('filesystem.disks.public.url')!=='/uploads'))self::fail();
        $expectedPath=$type===1?'/uploads/'.$directory.'/'.$fileName:self::domain($storage['domain']).'/'.$key;
        if(strlen($type===1?$fileName:$key)>100||strlen($expectedPath)>200)self::fail();
        if($type!==1&&$storage['cdn']!==''&&strlen($storage['cdn'].'/'.$key)>200)self::fail();
        $object=['type'=>$type,'directory'=>$directory,'local_root'=>$localRoot,'storage'=>$storage,'files'=>[],'key'=>$type===1?$fileName:$key];
        if($type===1){
            $relative=$directory.'/'.$fileName;$destination=$localRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            $cursor=$localRoot;foreach(explode('/',$directory)as$part){$cursor.=DIRECTORY_SEPARATOR.$part;
                if(is_link($cursor)||(file_exists($cursor)&&!is_dir($cursor)))self::fail();}
            if(file_exists($cursor)||is_link($cursor)||$this->localPath($relative,false)!==null)self::fail();
            $disk=Filesystem::disk('public');
            if(realpath($disk->path(''))!==$localRoot)self::fail();
            $temporary=null;$temporaryPath=null;$failure=null;$row=null;
            try{
                $temporary=tmpfile();if(!is_resource($temporary))self::fail();
                $meta=stream_get_meta_data($temporary);$uri=$meta['uri']??null;
                $temporaryPath=is_string($uri)?realpath($uri):false;$temporaryRoot=realpath(sys_get_temp_dir());
                $publicRoot=realpath(app()->getRootPath().'public');$initial=fstat($temporary);
                if(!is_string($temporaryPath)||$temporaryRoot===false||$publicRoot===false||dirname($temporaryPath)!==$temporaryRoot
                    ||$temporaryPath===$publicRoot||str_starts_with($temporaryPath,$publicRoot.DIRECTORY_SEPARATOR)
                    ||$temporaryPath===$localRoot||str_starts_with($temporaryPath,$localRoot.DIRECTORY_SEPARATOR)
                    ||is_link($uri)||!is_array($initial)||($initial['mode']&0170000)!==0100000||($initial['mode']&0777)!==0600
                    ||$initial['size']!==0)self::fail();
                if(fwrite($temporary,$bytes)!==$qualified['size']||fflush($temporary)!==true)self::fail();
                clearstatcache(true,$temporaryPath);$written=fstat($temporary);$native=lstat($temporaryPath);
                if(!is_array($written)||!is_array($native)||is_link($temporaryPath)||$written['size']!==$qualified['size'])self::fail();
                foreach(['dev','ino','mode','nlink','uid','gid']as$field)if($written[$field]!==$initial[$field]||$native[$field]!==$written[$field])self::fail();
                if($native['size']!==$qualified['size']||hash_file('sha256',$temporaryPath)!==$qualified['sha256'])self::fail();
                clearstatcache(true,$temporaryPath);$checked=lstat($temporaryPath);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($checked)||$checked[$field]!==$native[$field])self::fail();
                CustomerAssetServices::assertAvatarContext($context,'upload');
                $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
                $result=$disk->putFileAs($directory,new File($temporaryPath),$fileName);
                if($result!==$relative)self::fail();
                $actual=$this->localPath($relative,true);
                if($actual!==$destination||realpath($disk->path($relative))!==$actual)self::fail();
                $receipt['physical_changed']=true;clearstatcache(true,$actual);$savedBefore=lstat($actual);
                if(!is_array($savedBefore)||($savedBefore['mode']&0170000)!==0100000||is_link($actual)||$savedBefore['size']!==$qualified['size'])self::fail();
                $saved=file_get_contents($actual);
                if(!is_string($saved)||$saved!==$bytes||strlen($saved)!==$qualified['size']||hash('sha256',$saved)!==$qualified['sha256']
                    ||mime_content_type($actual)!==$qualified['mime'])self::fail();
                unset($saved);clearstatcache(true,$actual);$savedAfter=lstat($actual);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($savedAfter)||$savedAfter[$field]!==$savedBefore[$field])self::fail();
                clearstatcache(true,$temporaryPath);$intakeAfter=fstat($temporary);$intakePathAfter=lstat($temporaryPath);
                foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field)if(!is_array($intakeAfter)||!is_array($intakePathAfter)
                    ||$intakeAfter[$field]!==$checked[$field]||$intakePathAfter[$field]!==$checked[$field])self::fail();
                $path='/uploads/'.$relative;
                $row=['name'=>$fileName,'real_name'=>$displayName,'att_dir'=>$path,'satt_dir'=>$path,
                    'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>1];
                $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>$localRoot];
                if(($object['files'][0]['path']??null)!==$actual)self::fail();
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
            if(!is_array($row))self::fail();
        }else{
            CustomerAssetServices::assertAvatarContext($context,'upload');
            $upload=UploadService::fresh($type,$storage);$upload->setAuthThumb(false);
            $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
            $result=$upload->to('uploads/'.$directory)->stream($bytes,$fileName);$info=$upload->getFileInfo();
            if(!$info instanceof \StdClass||$result!==$info||!isset($info->fileName,$info->filePath)
                ||!is_string($info->fileName)||$info->fileName!==$key||!is_string($info->filePath))self::fail();
            if(in_array($type,[4,6,7],true)){
                $observed=$upload->observation();
                if(($observed['transport_complete']??null)!==true||!is_int($observed['http_status']??null)
                    ||$observed['http_status']<200||$observed['http_status']>=300)self::fail();
            }elseif($type===2){if(!isset($info->uploadInfo)||!is_array($info->uploadInfo)||$info->uploadInfo===[])self::fail();}
            elseif($type===3){if(!isset($info->uploadInfo['info']['url'])||!is_string($info->uploadInfo['info']['url'])||$info->uploadInfo['info']['url']==='')self::fail();}
            elseif($type===5){if(($info->uploadInfo['@metadata']['statusCode']??null)!==200)self::fail();}
            else self::fail();
            \app\services\marketplace\MerchantManagementInput::text($info->fileName,100,true);
            \app\services\marketplace\MerchantManagementInput::text($info->filePath,200,true);
            if(!in_array($info->filePath,array_values(array_unique(array_filter([self::domain($storage['domain']).'/'.$key,
                $storage['cdn']!==''?$storage['cdn'].'/'.$key:'']))),true)||strlen($info->filePath)>200)self::fail();
            $row=['name'=>$info->fileName,'real_name'=>$displayName,'att_dir'=>$info->filePath,'satt_dir'=>$info->filePath,
                'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>$type];
            $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>null];
            if($object['key']!==$key||$object['storage']!==$storage)self::fail();
            // A provider ACK is not an independent read of the stored cloud bytes: changed remains null.
        }
        CustomerAssetServices::assertAvatarContext($context,'upload');
        $receipt['physical_complete']=true;return $row;
    }

    /** Same physical evidence and receipt semantics as upload; cloud ACK remains an ACK. */
    public function assertAvatarUploaded(array $context,array $physical7,array $qualified,string $bytes,array $object):void
    {
        $row=$physical7;
        CustomerAssetServices::assertAvatarContext($context,'upload');$this->ownedAvatarObject($context,$object);
        if($this->qualify($bytes,$row['real_name'])!==$qualified||$row['att_dir']!==$row['satt_dir']
            ||$row['att_type']!==$qualified['mime']||(string)$row['att_size']!==(string)$qualified['size'])self::fail();
        $observed=$this->locateObject($row)+['directory'=>$object['directory'],'local_root'=>$object['local_root']];
        $actualFiles=$observed['files'];$expectedFiles=$object['files'];unset($observed['files']);$held=$object;unset($held['files']);
        if($observed!==$held||count($actualFiles)!==count($expectedFiles))self::fail();
        foreach($actualFiles as$position=>$file){$expected=$expectedFiles[$position];
            if($file['path']!==$expected['path']||$file['canonical']!==$expected['canonical'])self::fail();
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if($file['stat'][$key]!==$expected['stat'][$key])self::fail();
        }
        if($object['type']===1){
            $path=$object['files'][0]['path']??null;if(!is_string($path))self::fail();clearstatcache(true,$path);$before=lstat($path);
            $actual=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);
            if(!is_array($before)||!is_array($after)||is_link($path)||($before['mode']&0170000)!==0100000||$actual!==$bytes
                ||$before['size']!==$qualified['size']||hash('sha256',$actual)!==$qualified['sha256']||mime_content_type($path)!==$qualified['mime'])self::fail();
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])self::fail();
        }elseif($this->activeStorage($object['type'])!==$object['storage'])self::fail();
        CustomerAssetServices::assertAvatarContext($context,'upload');
    }

    /** Only this upload's new avatar namespace, before any attempted metadata write. */
    public function cleanupAvatar(array $context,array $object,array &$receipt):void
    {
        CustomerAssetServices::assertAvatarContext($context,'upload');$this->ownedAvatarObject($context,$object);
        if($receipt['database_committed']!==false)self::fail();$heldFalse=$receipt['cleanup_complete']===false;
        $cleanup=$receipt;$ok=$this->images->cleanupNew($object,$cleanup);
        // Preserve upload facts; cleanup cannot manufacture a known pre-upload state or replace a prior unknown.
        $receipt['cleanup_complete']=$heldFalse?false:($ok&&($object['type']===1||$cleanup['physical_complete']===true));
        CustomerAssetServices::assertAvatarContext($context,'upload');
    }

    private function ownedAvatarObject(array $context,array $object):void
    {
        $uid=$context['actual_uid'];
        if(!is_int($object['type']??null)||$object['type']<1||$object['type']>7||!is_string($object['directory']??null)
            ||preg_match('/\Acustomer\/a\/'.$uid.'\/[a-f0-9]{32}\z/D',$object['directory'])!==1)self::fail();
        if($object['type']===1){if(!is_string($object['key']??null)||preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)\z/D',$object['key'])!==1)self::fail();}
        elseif(!is_string($object['key']??null)||preg_match('/\Auploads\/customer\/a\/'.$uid.'\/[a-f0-9]{32}\/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)\z/D',$object['key'])!==1)self::fail();
    }

    /** Read only. Complete native metadata is qualified by the DAO before this exact-byte check. */
    public function verifyAvatarSelection(array $context,array $asset34):array
    {
        CustomerAssetServices::assertAvatarContext($context,'profile');$uid=$context['actual_uid'];
        $fields=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
            'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
            'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
            'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id',
            'source_qrcode_id','created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
        if(array_keys($asset34)!==$fields||$asset34['purpose']!=='avatar_public'
            ||!in_array($asset34['state'],['STAGED','BOUND'],true)
            ||self::avatarInteger($asset34['uid'],4294967295)!==$uid
            ||self::avatarInteger($asset34['created_by_uid'],4294967295)!==$uid)self::fail();
        foreach(['expires_at','retired_at','root_order_id','order_item_id','refund_id','review_id','confirmation_ref',
            'form_product_id','form_definition_sha256','form_field_index','extract_id','agent_apply_id',
            'source_product_id','source_qrcode_id','created_by_admin_id','created_by_job','legacy_att_id']as$field)
            if($asset34[$field]!==null)self::fail();
        if($asset34['state']==='STAGED'){if($asset34['bound_at']!==null)self::fail();}
        else self::avatarInteger($asset34['bound_at'],4294967295);
        $type=self::avatarInteger($asset34['storage_type'],7);
        $maximum=Config::get('upload.filesize');if(!is_int($maximum)||$maximum<1||$maximum>=PHP_INT_MAX)self::fail();
        $maximum=min($maximum,52428800);
        $size=self::avatarInteger($asset34['byte_size'],$maximum);
        $width=self::avatarInteger($asset34['width'],4294967295);$height=self::avatarInteger($asset34['height'],4294967295);
        foreach(['object_key'=>200,'name'=>100,'real_name'=>255]as$field=>$limit){
            if(!is_string($asset34[$field]))self::fail();
            \app\services\marketplace\MerchantManagementInput::text($asset34[$field],$limit,true);
        }
        if($asset34['thumbnail_key']!==$asset34['object_key']
            ||!in_array($asset34['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true)
            ||!is_string($asset34['content_sha256'])||strlen($asset34['content_sha256'])!==32)self::fail();
        $leaf='[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp)';
        if($type===1){
            if(preg_match('/\A\/uploads\/customer\/a\/'.$uid.'\/[a-f0-9]{32}\/('.$leaf.')\z/D',$asset34['object_key'],$parts)!==1
                ||$asset34['name']!==$parts[1])self::fail();
        }elseif(preg_match('/\Auploads\/customer\/a\/'.$uid.'\/[a-f0-9]{32}\/'.$leaf.'\z/D',$asset34['name'])!==1)self::fail();
        $physical=['name'=>$asset34['name'],'real_name'=>$asset34['real_name'],'att_dir'=>$asset34['object_key'],
            'satt_dir'=>$asset34['thumbnail_key'],'att_size'=>(string)$size,'att_type'=>$asset34['media_type'],'image_type'=>$type];
        $object=$this->avatarSelectionObject($context,$physical);$proof=self::avatarObjectProof($object);
        CustomerAssetServices::assertAvatarContext($context,'profile');
        if($type===1)$bytes=$this->avatarLocalBytes($object,$maximum);
        else{
            // Only reuse the existing read-only bounded transport; no product writer or product authority is supplied.
            $fetched=app()->make(\app\services\marketplace\product\ProductMediaRemoteImages::class)->fetch([$asset34['object_key']]);
            if(!array_is_list($fetched)||count($fetched)!==1||!is_array($fetched[0])
                ||array_keys($fetched[0])!==['bytes','url_sha256']||!is_string($fetched[0]['bytes'])
                ||$fetched[0]['url_sha256']!==hash('sha256',$asset34['object_key']))self::fail();
            $bytes=$fetched[0]['bytes'];unset($fetched);
        }
        if(strlen($bytes)!==$size||hash('sha256',$bytes)!==bin2hex($asset34['content_sha256']))self::fail();
        $qualified=$this->qualify($bytes,$asset34['real_name']);unset($bytes);
        if($qualified['size']!==$size||$qualified['mime']!==$asset34['media_type']||$qualified['width']!==$width
            ||$qualified['height']!==$height||$qualified['sha256']!==bin2hex($asset34['content_sha256'])
            ||strtolower(pathinfo($asset34['name'],PATHINFO_EXTENSION))!==$qualified['extension'])self::fail();
        if(self::avatarObjectProof($this->avatarSelectionObject($context,$physical))!==$proof)self::fail();
        CustomerAssetServices::assertAvatarContext($context,'profile');
        $url=path_to_url($asset34['object_key']);if(!is_string($url)||$url==='')self::fail();
        if(strpos($url,'http')===false)$url=$context['request']->domain().$url;
        // Profile binding changes state/bound_at; every other native field remains in the comparison proof.
        $native=$asset34;unset($native['state'],$native['bound_at']);
        $proof['native']=$native;$proof['qualified']=$qualified;
        CustomerAssetServices::assertAvatarContext($context,'profile');
        return ['url'=>$url,'qualified'=>$qualified,'object'=>$proof];
    }

    private function avatarSelectionObject(array $context,array $row):array
    {
        CustomerAssetServices::assertAvatarContext($context,'profile');
        $type=$row['image_type'];$localRoot=null;
        if($type===1){
            if(Config::get('filesystem.disks.public.type')!=='local'||Config::get('filesystem.disks.public.url')!=='/uploads')self::fail();
            $localRoot=realpath(Config::get('filesystem.disks.public.root',''));if(!is_string($localRoot))self::fail();
            $directory=dirname(substr($row['att_dir'],strlen('/uploads/')));
        }else $directory=dirname(substr($row['name'],strlen('uploads/')));
        $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>$localRoot];
        $this->ownedAvatarObject($context,$object);
        if($type===1){if(count($object['files'])!==1||$object['files'][0]['canonical']!==$row['att_dir'])self::fail();}
        elseif($this->activeStorage($type)!==$object['storage'])self::fail();
        CustomerAssetServices::assertAvatarContext($context,'profile');return $object;
    }

    /** Finite read identity excludes access time, which an ordinary read may update. */
    private static function avatarObjectProof(array $object):array
    {
        if(!array_is_list($object['files'])||count($object['files'])>4)self::fail();
        foreach($object['files']as$position=>$file){
            if(!is_array($file)||!is_string($file['path']??null)||!is_string($file['canonical']??null)
                ||!is_array($file['stat']??null))self::fail();
            $stat=[];foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$field){
                if(!is_int($file['stat'][$field]??null))self::fail();$stat[$field]=$file['stat'][$field];
            }
            if(($stat['mode']&0170000)!==0100000||$stat['size']<1)self::fail();
            $object['files'][$position]=['path'=>$file['path'],'canonical'=>$file['canonical'],'stat'=>$stat];
        }
        return $object;
    }

    /** Exact ordinary local file, bounded complete bytes and matching path/handle identity around the read. */
    private function avatarLocalBytes(array $object,int $maximum):string
    {
        $proof=self::avatarObjectProof($object);$file=$proof['files'][0]??null;
        if(!is_array($file)||$file['stat']['size']>$maximum)self::fail();$path=$file['path'];
        clearstatcache(true,$path);$before=lstat($path);
        if(!is_array($before)||is_link($path))self::fail();
        foreach($file['stat']as$field=>$value)if($before[$field]!==$value)self::fail();
        $handle=fopen($path,'rb');if(!is_resource($handle))self::fail();$bytes=null;
        try{
            $opened=fstat($handle);if(!is_array($opened))self::fail();
            foreach($file['stat']as$field=>$value)if($opened[$field]!==$value)self::fail();
            $bytes=stream_get_contents($handle,$maximum+1);
            clearstatcache(true,$path);$after=lstat($path);$held=fstat($handle);
            if(!is_string($bytes)||strlen($bytes)!==$file['stat']['size']||strlen($bytes)>$maximum
                ||!is_array($after)||!is_array($held)||is_link($path))self::fail();
            foreach($file['stat']as$field=>$value)if($after[$field]!==$value||$held[$field]!==$value)self::fail();
        }finally{if(fclose($handle)!==true)self::fail();}
        if(!is_string($bytes))self::fail();return $bytes;
    }

    private static function avatarInteger(mixed $value,int $maximum):int
    {
        if(is_int($value)){if($value<1||$value>$maximum)self::fail();return $value;}
        if(!is_string($value)||preg_match('/\A[1-9][0-9]*\z/D',$value)!==1)self::fail();$limit=(string)$maximum;
        if(strlen($value)>strlen($limit)||(strlen($value)===strlen($limit)&&strcmp($value,$limit)>0))self::fail();
        return (int)$value;
    }
}
