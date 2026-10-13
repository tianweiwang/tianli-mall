<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use app\model\system\config\SystemStorage;
use app\services\other\UploadService;
use app\services\system\config\SystemStorageServices;
use app\services\marketplace\context\MerchantContext;
use think\facade\Config;

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
        $file = app()->request->file('file');
        if (!$file) MerchantAttachmentInput::invalid();
        $original = $file->getOriginalName();
        \app\services\marketplace\MerchantManagementInput::text($original,255,true);
        $path = $file->getRealPath();
        $size = filesize($path);
        $maximum = Config::get('upload.filesize');
        if (!is_int($maximum) || $maximum<=0) MerchantAttachmentInput::unavailable();
        if ($size===false || $size<=0 || $size>$maximum) MerchantAttachmentInput::invalid();
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
        $configured = sys_config('upload_type',1);
        if (is_string($configured) && preg_match('/\A[1-7]\z/D',$configured)===1) $configured=(int)$configured;
        if (!is_int($configured) || $configured<1 || $configured>7) MerchantAttachmentInput::unavailable();
        $type=$configured;
        $storage = $type===1 ? null : $this->activeStorage($type);
        $directory = 'merchant/'.$ctx->merId.'/'.bin2hex(random_bytes(16));
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
        $object = $this->locate($row,$ctx) + ['directory'=>$directory,'local_root'=>$localRoot];
        if ($type===1) {
            // Local water/thumbnail processing can rewrite the original file after multipart validation.
            $originalPath=$object['files'][0]['path'];
            clearstatcache(true,$originalPath);
            $savedSize=filesize($originalPath);$savedMime=mime_content_type($originalPath);
            if ($savedSize===false || $savedSize<=0 || !is_string($savedMime) || !in_array($savedMime,$allowedMime,true)) MerchantAttachmentInput::unavailable();
            $row['att_size']=(string)$savedSize;$row['att_type']=$savedMime;
        }
        $receipt['physical_changed']=true;
        $receipt['physical_complete']=true;
        return $row;
    }
    public function locate(array $row, MerchantContext $ctx): array
    {
        $type = (int)$row['image_type'];
        if ($type===1) {
            $canonical = $row['att_dir'];
            if (!is_string($canonical) || !str_starts_with($canonical,'/uploads/') || basename($canonical)!==$row['name']) MerchantAttachmentInput::unavailable();
            $relative = substr($canonical,strlen('/uploads/'));
            self::key($relative);
            $family = [$canonical];
            foreach (['big','mid','small'] as $prefix) $family[] = substr($canonical,0,strrpos($canonical,'/')+1).$prefix.'_'.basename($canonical);
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
}
