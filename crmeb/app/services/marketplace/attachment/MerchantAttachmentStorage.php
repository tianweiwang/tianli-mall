<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use app\model\system\config\SystemStorage;
use app\services\other\UploadService;
use app\services\system\config\SystemStorageServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\product\LegacyProductWriteServices;
use think\facade\Config;
use think\facade\Filesystem;
use think\File;

/** Private locators are returned only to the service, never the HTTP DTO or audit. */
final class MerchantAttachmentStorage
{
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
    /** Object is populated before move, so a local partial upload remains narrowly observable. */
    public function upload(MerchantContext $ctx, array &$object, array &$receipt): array
    {
        return $this->uploadOwnedMerchant($ctx->merId, $object, $receipt);
    }
    /** Caller enters its real finite product action and run() before any physical I/O. */
    public function uploadForProduct(int $productIdOrZero, array &$object, array &$receipt): array
    {
        $pdo = LegacyProductWriteServices::transaction();
        $merchantId = LegacyProductWriteServices::mediaOwner($productIdOrZero);
        $row = $this->uploadOwnedMerchant($merchantId, $object, $receipt);
        if (LegacyProductWriteServices::transaction() !== $pdo
            || LegacyProductWriteServices::mediaOwner($productIdOrZero) !== $merchantId) MerchantAttachmentInput::unavailable();
        return $row;
    }
    private function uploadOwnedMerchant(int $merchantId, array &$object, array &$receipt): array
    {
        \app\services\marketplace\MerchantManagementInput::integer($merchantId,1,4294967295);
        $file = app()->request->file('file');
        if (!$file) MerchantAttachmentInput::invalid();
        $original = $file->getOriginalName();
        \app\services\marketplace\MerchantManagementInput::text($original,255,true);
        $path = $file->getRealPath();
        $size = filesize($path);
        $maximum = Config::get('upload.filesize');
        if (!is_int($maximum) || $maximum<=0) MerchantAttachmentInput::unavailable();
        if ($size===false || $size<=0 || $size>$maximum) MerchantAttachmentInput::invalid();
        $video = strtolower(pathinfo($original,PATHINFO_EXTENSION))==='mp4';
        $videoHash=null;
        if ($video) {
            clearstatcache(true,$path);
            $videoStat=lstat($path);
            if (!is_array($videoStat) || ($videoStat['mode'] & 0170000)!==0100000
                || is_link($path) || $videoStat['size']!==$size) MerchantAttachmentInput::invalid();
            $extension='mp4';
            $mime=mime_content_type($path);
            $allowedExt=array_values(array_intersect(['mp4'],Config::get('upload.fileExt',[])));
            $allowedMime=array_values(array_intersect(['video/mp4'],Config::get('upload.fileMime',[])));
            if ($mime!=='video/mp4' || !in_array($extension,$allowedExt,true)
                || !in_array($mime,$allowedMime,true)) MerchantAttachmentInput::invalid();
            $videoHash=hash_file('sha256',$path);
            if (!is_string($videoHash) || strlen($videoHash)!==64) MerchantAttachmentInput::invalid();
            clearstatcache(true,$path);
            $videoAfter=lstat($path);
            foreach (['dev','ino','mode','uid','gid','size','mtime'] as $field) {
                if (!is_array($videoAfter) || $videoAfter[$field]!==$videoStat[$field]) MerchantAttachmentInput::invalid();
            }
        } else {
        $image = getimagesize($path);
        $types = [IMAGETYPE_JPEG=>['jpg','jpeg'],IMAGETYPE_PNG=>['png'],IMAGETYPE_GIF=>['gif'],IMAGETYPE_WEBP=>['webp']];
        $extension = strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if ($image===false || !isset($types[$image[2]]) || !in_array($extension,$types[$image[2]],true)) MerchantAttachmentInput::invalid();
        $mime = mime_content_type($path);
        $allowedExt = array_values(array_intersect(['jpg','jpeg','png','gif','webp'],Config::get('upload.fileExt',[])));
        $allowedMime = array_values(array_intersect(['image/jpg','image/jpeg','image/png','image/gif','image/webp'],Config::get('upload.fileMime',[])));
        if (!is_string($mime) || !in_array($mime,$allowedMime,true) || !in_array($extension,$allowedExt,true)) MerchantAttachmentInput::invalid();
        $bytes=file_get_contents($path);$decoded=null;
        try {
            if (!is_string($bytes) || strlen($bytes)!==$size) MerchantAttachmentInput::invalid();
            $decoded=@imagecreatefromstring($bytes);
            if (!($decoded instanceof \GdImage) || imagesx($decoded)!==$image[0] || imagesy($decoded)!==$image[1]) {
                MerchantAttachmentInput::invalid();
            }
        } finally {
            if ($decoded instanceof \GdImage) imagedestroy($decoded);
            unset($decoded,$bytes);
        }
        }
        $configured = sys_config('upload_type',1);
        if (is_string($configured) && preg_match('/\A[1-7]\z/D',$configured)===1) $configured=(int)$configured;
        if (!is_int($configured) || $configured<1 || $configured>7) MerchantAttachmentInput::unavailable();
        $type=$configured;
        if ($video && $type!==1) MerchantAttachmentInput::invalid();
        $storage = $type===1 ? null : $this->activeStorage($type);
        $directory = 'merchant/'.$merchantId.'/'.bin2hex(random_bytes(16));
        $localRoot = $type===1 ? realpath(Config::get('filesystem.disks.public.root','')) : null;
        if ($type===1 && ($localRoot===false || Config::get('filesystem.disks.public.url')!=='/uploads')) MerchantAttachmentInput::unavailable();
        if ($type===1) {
            $cursor=$localRoot;
            foreach (explode('/',$directory) as $part) {
                $cursor.=DIRECTORY_SEPARATOR.$part;
                if (is_link($cursor) || (file_exists($cursor) && !is_dir($cursor))) MerchantAttachmentInput::unavailable();
            }
        }
        $object = ['type'=>$type,'directory'=>$directory,'local_root'=>$localRoot,'storage'=>$storage,'files'=>[],'key'=>null];
        $upload = UploadService::fresh($type,$storage);
        if ($video) $upload->setAuthThumb(false);
        $receipt['phase']='physical_upload';
        $receipt['physical_changed']=null;
        $result = $upload->to($type===1 ? $directory : 'uploads/'.$directory)
            ->validate(['filesize'=>$maximum,'fileExt'=>$allowedExt,'fileMime'=>$allowedMime])->move('file');
        $info = $upload->getFileInfo();
        if (isset($info->fileName) && is_string($info->fileName)) $object['key']=$info->fileName;
        if ($result===false || !isset($info->filePath,$info->fileName) || !is_string($info->filePath)
            || !is_string($info->fileName)) MerchantAttachmentInput::unavailable();
        if (in_array($type,[4,6,7],true)) {
            $observed=$upload->observation();
            if (($observed['transport_complete']??null)!==true || !is_int($observed['http_status']??null)
                || $observed['http_status']<200 || $observed['http_status']>=300) MerchantAttachmentInput::unavailable();
        }
        \app\services\marketplace\MerchantManagementInput::text($info->fileName,100,true);
        \app\services\marketplace\MerchantManagementInput::text($info->filePath,200,true);
        $row = ['name'=>$info->fileName,'real_name'=>$original,'att_dir'=>$info->filePath,'satt_dir'=>$info->filePath,
            'att_size'=>(string)$size,'att_type'=>$mime,'image_type'=>$type];
        $object = $this->locateObject($row) + ['directory'=>$directory,'local_root'=>$localRoot];
        if ($type===1) {
            // Local water/thumbnail processing can rewrite the original file after multipart validation.
            $originalPath=$object['files'][0]['path'];
            clearstatcache(true,$originalPath);
            $savedSize=filesize($originalPath);$savedMime=mime_content_type($originalPath);
            if ($savedSize===false || $savedSize<=0 || !is_string($savedMime) || !in_array($savedMime,$allowedMime,true)) MerchantAttachmentInput::unavailable();
            if ($video) {
                $savedStat=lstat($originalPath);
                $savedHash=hash_file('sha256',$originalPath);
                clearstatcache(true,$originalPath);
                $savedAfter=lstat($originalPath);
                if (!is_array($savedStat) || ($savedStat['mode'] & 0170000)!==0100000
                    || is_link($originalPath) || $savedSize!==$size || $savedMime!==$mime
                    || $savedHash!==$videoHash) MerchantAttachmentInput::unavailable();
                foreach (['dev','ino','mode','uid','gid','size','mtime'] as $field) {
                    if (!is_array($savedAfter) || $savedAfter[$field]!==$savedStat[$field]) MerchantAttachmentInput::unavailable();
                }
            }
            $row['att_size']=(string)$savedSize;$row['att_type']=$savedMime;
        }
        $receipt['physical_changed']=true;
        $receipt['physical_complete']=true;
        return $row;
    }
    public function locate(array $row, MerchantContext $ctx): array
    {
        return $this->locateObject($row);
    }
    /** The caller must reselect the complete owned attachment on this writer PDO; never pass a body row. */
    public function locateForProduct(array $row, int $productIdOrZero): array
    {
        $pdo = LegacyProductWriteServices::transaction();
        $merchantId = LegacyProductWriteServices::mediaOwner($productIdOrZero);
        if (!in_array($row['mer_id'] ?? null, [$merchantId, (string)$merchantId], true)) MerchantAttachmentInput::unavailable();
        $object = $this->locateObject($row);
        if (LegacyProductWriteServices::transaction() !== $pdo
            || LegacyProductWriteServices::mediaOwner($productIdOrZero) !== $merchantId) MerchantAttachmentInput::unavailable();
        return $object;
    }
    /** Sealed claim replay only: current PC identity, no writer.finish audit and no writes. */
    public function locateForProductScanClaim(array $row,\app\Request $request,array $issuer,int $productIdOrZero,int $owner,int $initialCategory): array
    {
        $auth=app()->make(\app\services\marketplace\product\ProductMediaScanIssuer::class);
        $auth->forRequest($request,$issuer,'claim',$productIdOrZero,$owner,$initialCategory);
        $connection=\think\facade\Db::connect();$pdo=$connection->getPdo();
        if(LegacyProductWriteServices::active()||!$pdo instanceof \PDO||$pdo->inTransaction()
            ||!in_array($row['mer_id']??null,[$owner,(string)$owner],true)
            ||array_keys($row)!==\app\dao\marketplace\product\PlatformProductMediaDao::ATTACHMENT_FIELDS
            ||!in_array($row['module_type'],[1,'1'],true)||!in_array($row['type'],[0,'0'],true)
            ||!in_array($row['att_type'],\app\dao\marketplace\product\PlatformProductMediaDao::IMAGE_MIMES,true)
            ||preg_match('/\A[a-f0-9]{32}\z/D',$row['scan_token'])!==1)MerchantAttachmentInput::unavailable();
        $object=$this->locateObject($row);
        $auth->forRequest($request,$issuer,'claim',$productIdOrZero,$owner,$initialCategory);
        if(\think\facade\Db::connect()!==$connection||$connection->getPdo()!==$pdo||$pdo->inTransaction())MerchantAttachmentInput::unavailable();
        return $object;
    }
    private function locateObject(array $row): array
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
        if (strlen($key)>200 || preg_match('/\A[A-Za-z0-9_\-\/\.]+\z/D',$key)!==1
            || str_starts_with($key,'/') || str_contains($key,'//')) MerchantAttachmentInput::unavailable();
        foreach (explode('/',$key) as $part) if ($part==='' || $part==='.' || $part==='..') MerchantAttachmentInput::unavailable();
    }
    private function localPath(string $relative, bool $required): ?string
    {
        self::key($relative);
        $root = realpath(Config::get('filesystem.disks.public.root',''));
        if ($root===false || !is_dir($root) || Config::get('filesystem.disks.public.url')!=='/uploads') MerchantAttachmentInput::unavailable();
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
    /** Include source-produced display/JSON/HTML forms; never derive an authorization scope from them. */
    public function aliases(array $objects): array
    {
        $values=[];
        foreach ($objects as $object) foreach ($object['canonical'] as $value) {
            $variants=[$value,set_file_url($value)];
            foreach ($variants as $variant) if (str_starts_with($variant,'http:')) $variants[]='https:'.substr($variant,5);
            foreach (array_unique($variants) as $variant) {
                $values[]=$variant;
                $values[]=substr(json_encode($variant,JSON_THROW_ON_ERROR),1,-1);
                $values[]=htmlspecialchars($variant,ENT_QUOTES,'UTF-8');
            }
        }
        return array_values(array_unique($values));
    }
    public function remove(array $object, array &$receipt): void
    {
        $receipt['phase']='physical_delete';
        if ($object['type']===1) {
            foreach ($object['files'] as $file) {
                clearstatcache(true,$file['path']);
                $current=lstat($file['path']);
                foreach (['dev','ino','size','mtime'] as $field) if ($current===false || $current[$field]!==$file['stat'][$field]) MerchantAttachmentInput::unavailable();
                if (is_link($file['path'])) MerchantAttachmentInput::unavailable();
                $prior=$receipt['physical_changed'];
                if ($prior!==true) $receipt['physical_changed']=null;
                if (!unlink($file['path'])) MerchantAttachmentInput::unavailable();
                $receipt['physical_changed']=true;
                clearstatcache(true,$file['path']);
                if (file_exists($file['path']) || is_link($file['path'])) MerchantAttachmentInput::unavailable();
            }
            return;
        }
        $prior=$receipt['physical_changed'];
        if ($prior!==true) $receipt['physical_changed']=null;
        $type=$object['type'];$storage=$object['storage'];$key=$object['key'];
        if ($this->credentials($type)['accessKey']!==$storage['access_key']) MerchantAttachmentInput::unavailable();
        if ($type===4) {
            $credential=$this->credentials(4);
            $client=new MerchantObservedCosClient(['accessKey'=>$credential['accessKey'],'secretKey'=>$credential['secretKey'],
                'appid'=>$credential['appid'],'region'=>$storage['region'],'bucket'=>$storage['name'],'uploadUrl'=>self::domain($storage['domain'])]);
            $client->deleteObject($storage['name'],$key);
            $observed=$client->observation();
            if (!$observed['transport_complete'] || $observed['http_status']<200 || $observed['http_status']>=300) MerchantAttachmentInput::unavailable();
        } elseif ($type===6 || $type===7) {
            $credential=$this->credentials($type);
            $client=new MerchantObservedObsClient(['accessKey'=>$credential['accessKey'],'secretKey'=>$credential['secretKey'],
                'region'=>$storage['region'],'bucket'=>$storage['name'],'uploadUrl'=>self::domain($storage['domain']),'type'=>$type===6?'hw':'ty']);
            $client->deleteObject($key);$observed=$client->observation();
            if (!$observed['transport_complete'] || $observed['http_status']<200 || $observed['http_status']>=300) MerchantAttachmentInput::unavailable();
        } else {
            $result=UploadService::fresh($type,$storage)->delete($key);
            if ($type===2) { if ($result!==null) MerchantAttachmentInput::unavailable(); }
            elseif ($type===3) { if (!is_array($result) || !is_string($result['x-oss-request-id']??null) || $result['x-oss-request-id']==='') MerchantAttachmentInput::unavailable(); }
            elseif ($type===5) {
                if (!$result instanceof \Aws\Result) MerchantAttachmentInput::unavailable();
                $metadata=$result->get('@metadata');$code=$metadata['statusCode']??null;
                if (!is_int($code) || $code<200 || $code>=300) MerchantAttachmentInput::unavailable();
            } else MerchantAttachmentInput::unavailable();
        }
        // Provider acknowledgement is known; pre-existing physical bytes were not read.
    }
    /** Only this request's generated namespace; never guess or scan a historical cloud key. */
    public function cleanupNew(array $object,array &$receipt): bool
    {
        if (($object['type']??null)===1) return $this->cleanupNewLocal($object,$receipt);
        if (!is_int($object['type']??null) || !is_array($object['storage']??null)
            || !is_string($object['key']??null) || !is_string($object['directory']??null)
            || !str_starts_with($object['key'],'uploads/'.$object['directory'].'/')) return false;
        self::key($object['key']);
        $this->remove($object,$receipt);
        return true;
    }
    /** Cleanup is allowed only when no database write has been attempted. */
    public function cleanupNewLocal(array $object, array &$receipt): bool
    {
        if (($object['type']??null)!==1 || !is_string($object['directory']??null)) return false;
        self::key($object['directory']);
        $root=$object['local_root']??null;
        if (!is_string($root) || !is_dir($root) || realpath($root)!==$root) return false;
        $directory=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$object['directory']);
        if (!is_dir($directory)) return true;
        $cursor=$root;
        foreach (explode('/',$object['directory']) as $part) { $cursor.=DIRECTORY_SEPARATOR.$part;if(is_link($cursor))return false; }
        if (is_link($directory)) return false;
        $paths=[];
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (count($paths)>=10 || $entry->isLink() || !$entry->isFile()) return false;
            $actual=$entry->getRealPath();
            if (!is_string($actual) || !str_starts_with($actual,$directory.DIRECTORY_SEPARATOR)) return false;
            $paths[]=$actual;
        }
        foreach ($paths as $path) if (!unlink($path)) return false;
        foreach ($paths as $path) { clearstatcache(true,$path);if(file_exists($path))return false; }
        return true;
    }

    /** Qualify actual image bytes in memory only; no owner, Request, file or storage operation. */
    public function qualifyImageBytes(string $bytes): array
    {
        $size=strlen($bytes);$maximum=Config::get('upload.filesize');
        if(!is_int($maximum)||$maximum<=0)MerchantAttachmentInput::unavailable();
        if($size<1||$size>$maximum)MerchantAttachmentInput::invalid();
        $image=@getimagesizefromstring($bytes);
        $kinds=[IMAGETYPE_JPEG=>[['jpg','jpeg'],['image/jpg','image/jpeg']],
            IMAGETYPE_PNG=>[['png'],['image/png']],IMAGETYPE_GIF=>[['gif'],['image/gif']],IMAGETYPE_WEBP=>[['webp'],['image/webp']]];
        if(!is_array($image)||!is_int($image[0]??null)||!is_int($image[1]??null)
            ||$image[0]<1||$image[1]<1||!isset($kinds[$image[2]??0]))MerchantAttachmentInput::invalid();
        $extensions=Config::get('upload.fileExt',[]);$mimes=Config::get('upload.fileMime',[]);
        if(!is_array($extensions)||!is_array($mimes))MerchantAttachmentInput::unavailable();
        [$typeExtensions,$typeMimes]=$kinds[$image[2]];$extension=null;
        foreach($typeExtensions as$allowed)if(in_array($allowed,$extensions,true)){$extension=$allowed;break;}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if($extension===null||!is_string($mime)||!in_array($mime,$typeMimes,true)||!in_array($mime,$mimes,true))MerchantAttachmentInput::invalid();
        // A finite, overflow-safe predecode estimate; never treat compressed size as GD's memory cost.
        $setting=trim((string)ini_get('memory_limit'));
        if(preg_match('/\A([1-9][0-9]*)([KMG]?)\z/iD',$setting,$parts)!==1)MerchantAttachmentInput::unavailable();
        $unit=match(strtoupper($parts[2])){'K'=>1024,'M'=>1048576,'G'=>1073741824,default=>1};
        $ceiling=(string)intdiv(PHP_INT_MAX,$unit);
        if(strlen($parts[1])>strlen($ceiling)||(strlen($parts[1])===strlen($ceiling)&&strcmp($parts[1],$ceiling)>0))MerchantAttachmentInput::unavailable();
        $limit=(int)$parts[1]*$unit;$used=memory_get_usage(true);$reserve=16777216;
        if($size>intdiv(PHP_INT_MAX-$reserve,2)||$image[0]>intdiv(PHP_INT_MAX,$image[1]))MerchantAttachmentInput::invalid();
        $extra=$size*2+$reserve;$pixels=$image[0]*$image[1];
        if($used<0||$limit<=$used||$extra>$limit-$used||$pixels>intdiv($limit-$used-$extra,32))MerchantAttachmentInput::invalid();
        $decoded=null;
        try{
            $decoded=@imagecreatefromstring($bytes);
            if(!$decoded instanceof \GdImage||imagesx($decoded)!==$image[0]||imagesy($decoded)!==$image[1])MerchantAttachmentInput::invalid();
        }finally{if($decoded instanceof \GdImage)imagedestroy($decoded);}
        return ['extension'=>$extension,'mime'=>$mime,'size'=>$size,'width'=>$image[0],'height'=>$image[1],'sha256'=>hash('sha256',$bytes)];
    }

    /** Only a real product writer may turn qualified bytes into its own new commercial object. */
    public function uploadBytesForProduct(int $productIdOrZero,string $bytes,string $displayName,array &$object,array &$receipt): array
    {
        $pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
        if($object!==[])MerchantAttachmentInput::unavailable();
        $receiptKeys=array_keys($receipt);sort($receiptKeys,SORT_STRING);
        if($receiptKeys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||$receipt['complete']!==false||$receipt['phase']!=='preflight'||$receipt['physical_changed']!==false
            ||$receipt['physical_complete']!==false||$receipt['database_committed']!==false||$receipt['cleanup_complete']!==null)MerchantAttachmentInput::unavailable();
        $qualified=$this->qualifyImageBytes($bytes);
        \app\services\marketplace\MerchantManagementInput::text($displayName,255,true);
        if(preg_match('/\A[a-f0-9]{64}\.'.$qualified['extension'].'\z/D',$displayName)!==1)MerchantAttachmentInput::invalid();
        if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)MerchantAttachmentInput::unavailable();
        $configured=sys_config('upload_type',1);
        if(is_string($configured)&&preg_match('/\A[1-7]\z/D',$configured)===1)$configured=(int)$configured;
        if(!is_int($configured)||$configured<1||$configured>7)MerchantAttachmentInput::unavailable();
        $type=$configured;$storage=$type===1?null:$this->activeStorage($type);
        $directory='merchant/'.$owner.'/'.bin2hex(random_bytes(16));$fileName=bin2hex(random_bytes(16)).'.'.$qualified['extension'];
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
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)MerchantAttachmentInput::unavailable();
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
                $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>$localRoot];
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
            if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)MerchantAttachmentInput::unavailable();
            $upload=UploadService::fresh($type,$storage);$upload->setAuthThumb(false);
            $receipt['phase']='physical_upload';$receipt['physical_changed']=null;
            $result=$upload->to('uploads/'.$directory)->stream($bytes,$fileName);$info=$upload->getFileInfo();
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
            \app\services\marketplace\MerchantManagementInput::text($info->filePath,200,true);
            $row=['name'=>$info->fileName,'real_name'=>$displayName,'att_dir'=>$info->filePath,'satt_dir'=>$info->filePath,
                'att_size'=>(string)$qualified['size'],'att_type'=>$qualified['mime'],'image_type'=>$type];
            $object=$this->locateObject($row)+['directory'=>$directory,'local_root'=>null];
            if($object['key']!==$key||$object['storage']!==$storage)MerchantAttachmentInput::unavailable();
            // A provider ACK is not an independent read of the stored cloud bytes: changed remains null.
        }
        if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)MerchantAttachmentInput::unavailable();
        $receipt['physical_complete']=true;return $row;
    }
}
