<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use app\services\marketplace\attachment\MerchantAttachmentInput as Input;
use crmeb\exceptions\AuthUnavailableException;
use think\exception\HttpException;
use think\facade\Config;

/** One finite cloud-video manifest purpose. Commercial production remains the original product writer. */
final class ProductVideoCloudUploadSessions
{
    private const PURPOSE='product-video-cloud-upload-v1';
    private const STAT=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    public function __construct(private MerchantAttachmentStorage $storage) {}
    public static function enabled(): bool
    {
        $c=Config::get('marketplace.product_video_cloud_upload');
        if(!is_array($c)||($c['enabled']??null)!==true||!is_string($c['private_root']??null)||$c['private_root']==='')return false;
        $type=sys_config('upload_type',1);
        return true
            &&preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)($c['staging_storage_id']??''))===1
            &&in_array($type,[2,3,4,5,6,7,'2','3','4','5','6','7'],true)
            &&((string)$type!=='2'||(is_string($c['qiniu_upload_url']??null)&&$c['qiniu_upload_url']!==''
                &&is_string($c['qiniu_rs_url']??null)&&$c['qiniu_rs_url']!==''));
    }
    public static function needsCollection(): bool
    {
        return Config::get('marketplace.product_video_cloud_upload.private_root','')!=='';
    }
    private static function fail(): never { throw new AuthUnavailableException(); }
    private static function reference(mixed $v): string
    {
        if(!is_string($v)||preg_match('/\A[a-f0-9]{32}\z/D',$v)!==1)throw new HttpException(422,'视频暂存引用不正确');return $v;
    }
    private static function stat(string $path): array
    {
        clearstatcache(true,$path);$s=lstat($path);
        if(!is_array($s)||is_link($path)||($s['mode']&0170000)!==0100000||($s['mode']&0777)!==0600
            ||$s['nlink']!==1||!function_exists('posix_geteuid')||$s['uid']!==posix_geteuid())self::fail();
        $stable=[];foreach(self::STAT as$key){if(!array_key_exists($key,$s))self::fail();$stable[$key]=$s[$key];}return $stable;
    }
    private static function same($fd,array $s): void
    {
        $actual=fstat($fd);foreach(self::STAT as$k)if(!is_array($actual)||$actual[$k]!==$s[$k])self::fail();
    }
    private static function read(string $path): string
    {
        $s=self::stat($path);if($s['size']<1||$s['size']>65536)self::fail();$fd=fopen($path,'rb');if(!is_resource($fd))self::fail();
        try{
            self::same($fd,$s);$bytes='';
            while(strlen($bytes)<$s['size']){$b=fread($fd,$s['size']-strlen($bytes));if(!is_string($b)||$b==='')self::fail();$bytes.=$b;}
            if(fread($fd,1)!==''||!feof($fd))self::fail();self::same($fd,$s);if(self::stat($path)!==$s)self::fail();return $bytes;
        }finally{if(fclose($fd)!==true)self::fail();}
    }
    private static function save(string $root,string $ref,array $state): void
    {
        $path=$root.DIRECTORY_SEPARATOR.self::reference($ref).'.json';$tmp=$path.'.new';
        $bytes=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($bytes)>65536||file_exists($tmp)||is_link($tmp))self::fail();
        if(file_exists($path))self::stat($path);elseif(is_link($path))self::fail();
        $fd=fopen($tmp,'x+b');if(!is_resource($fd))self::fail();$identity=null;$renamed=false;
        try{
            if(!chmod($tmp,0600))self::fail();$identity=self::stat($tmp);self::same($fd,$identity);
            $written=0;while($written<strlen($bytes)){$n=fwrite($fd,substr($bytes,$written));if(!is_int($n)||$n<1)self::fail();$written+=$n;}
            if(fflush($fd)!==true)self::fail();$writtenNative=self::stat($tmp);self::same($fd,$writtenNative);
            if(self::read($tmp)!==$bytes||self::stat($tmp)!==$writtenNative)self::fail();self::same($fd,$writtenNative);
            if(!fclose($fd))self::fail();$fd=null;if(is_link($path)||!rename($tmp,$path))self::fail();$renamed=true;
            if(self::read($path)!==$bytes)self::fail();
        }finally{
            if(is_resource($fd)&&!fclose($fd))self::fail();
            if(!$renamed&&is_array($identity)){
                clearstatcache(true,$tmp);$actual=lstat($tmp);
                if(!is_array($actual)||is_link($tmp)||$actual['dev']!==$identity['dev']||$actual['ino']!==$identity['ino']||!unlink($tmp))self::fail();
            }
        }
    }
    private static function entries(string $root): array
    {
        $rows=scandir($root);if(!is_array($rows))self::fail();$refs=[];
        foreach($rows as$name){if(in_array($name,['.','..','.lock'],true))continue;
            if(preg_match('/\A([a-f0-9]{32})\.json\z/D',$name,$m)!==1)self::fail();$refs[]=$m[1];}
        sort($refs,SORT_STRING);if(count($refs)>1024)self::fail();return $refs;
    }
    private static function state(string $root,string $ref): array
    {
        $path=$root.DIRECTORY_SEPARATOR.self::reference($ref).'.json';if(!file_exists($path))throw new HttpException(410,'视频暂存已失效');
        $s=json_decode(self::read($path),true,32,JSON_THROW_ON_ERROR);
        $keys=['purpose','binding','actor','filename','total_size','sha256','created_at','expires_at','configuration','key','state','result'];
        if(!is_array($s)||array_keys($s)!==$keys||$s['purpose']!==self::PURPOSE
            ||!is_string($s['binding'])||preg_match('/\A[a-f0-9]{64}\z/D',$s['binding'])!==1
            ||!is_string($s['actor'])||preg_match('/\A[a-f0-9]{64}\z/D',$s['actor'])!==1
            ||!is_string($s['sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$s['sha256'])!==1
            ||!is_array($s['configuration'])||!is_string($s['configuration']['fingerprint']??null)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$s['configuration']['fingerprint'])!==1
            ||!is_int($s['configuration']['owner']??null)||$s['configuration']['owner']<1
            ||!is_string($s['key'])||!str_contains($s['key'],'/video-staging/'.$ref.'/')
            ||!in_array($s['state'],['issued','cancelled','processing','complete'],true)
            ||!is_int($s['created_at'])||!is_int($s['expires_at'])||$s['created_at']<1||$s['expires_at']<=$s['created_at']
            ||($s['state']==='complete'?(!is_string($s['result'])||$s['result']===''):$s['result']!==null))self::fail();
        \app\services\marketplace\MerchantManagementInput::text($s['filename'],255,true);
        Input::id($s['total_size']);return $s;
    }
    private function locked(callable $operation): mixed
    {
        $configured=Config::get('marketplace.product_video_cloud_upload.private_root');
        if(!is_string($configured)||$configured===''||str_contains($configured,"\0")||is_link($configured))throw new HttpException(422,'云视频暂存未配置');
        $root=realpath($configured);$public=realpath(app()->getRootPath().'public');$uploads=realpath(Config::get('filesystem.disks.public.root',''));
        if($root===false||$root!==rtrim($configured,'/\\')||$public===false||$uploads===false||!is_writable($root))self::fail();
        $forbidden=[$public,$uploads,rtrim(app()->getRuntimePath(),'/\\'),rtrim(app()->getRootPath(),'/\\').DIRECTORY_SEPARATOR.'runtime'];
        foreach(['marketplace.product_media_scan.private_root','marketplace.product_video_upload.private_root','marketplace.aftersale.private_root','marketplace.customer_form.private_root',
            'marketplace.customer_licence.private_root','marketplace.assets.customer_refund_private_root','marketplace.customer_withdrawal_qr.private_root','marketplace.platform_chat.private_root']as$setting){
            $v=Config::get($setting,'');if(is_string($v)&&$v!==''){$v=realpath($v);if($v===false)self::fail();$forbidden[]=$v;}}
        foreach($forbidden as$v)if($root===$v||str_starts_with($root,$v.DIRECTORY_SEPARATOR)||str_starts_with($v,$root.DIRECTORY_SEPARATOR))self::fail();
        clearstatcache(true,$root);$native=lstat($root);
        if(!is_array($native)||($native['mode']&0170000)!==0040000||($native['mode']&0777)!==0700
            ||!function_exists('posix_geteuid')||$native['uid']!==posix_geteuid())self::fail();
        $lock=$root.DIRECTORY_SEPARATOR.'.lock';if(is_link($lock))self::fail();$fd=fopen($lock,'c+b');if(!is_resource($fd))self::fail();
        try{
            if(!chmod($lock,0600))self::fail();self::same($fd,self::stat($lock));
            if(!flock($fd,LOCK_EX|LOCK_NB))throw new HttpException(422,'视频暂存正在处理，请稍后核对');
            clearstatcache(true,$root);$current=lstat($root);
            if(!is_array($current)||$current['dev']!==$native['dev']||$current['ino']!==$native['ino']||realpath($root)!==$root)self::fail();
            return $operation($root);
        }finally{$unlocked=flock($fd,LOCK_UN);$closed=fclose($fd);if(!$unlocked||!$closed)self::fail();}
    }
    /** within executes one operation in the actual current authorized product writer; it is never client-supplied. */
    public function command(Request $request,string $binding,string $actor,array $body,callable $within,callable $publish,callable $observe): array
    {
        if(app()->request!==$request||preg_match('/\A[a-f0-9]{64}\z/D',$binding)!==1||preg_match('/\A[a-f0-9]{64}\z/D',$actor)!==1)self::fail();
        return $this->locked(function(string $root)use($request,$binding,$actor,$body,$within,$publish,$observe):array{
            $operation=$body['upload_operation'];
            if($operation==='cloud_issue'){
                if(!self::enabled())throw new HttpException(422,'云视频直传暂未启用');
                $c=Config::get('marketplace.product_video_cloud_upload');$max=Input::id($c['max_open_sessions']);
                if($max>1024)self::fail();$per=Input::id($c['per_actor']);if($per>$max)self::fail();
                $ttl=Input::id($c['ttl_seconds']);if($ttl>86400)self::fail();
                $size=Input::id($body['total_size']);$maximum=Config::get('upload.filesize');
                if(!is_int($maximum)||$maximum<1)self::fail();if($size>$maximum)throw new HttpException(422,'视频文件过大');
                $filename=\app\services\marketplace\MerchantManagementInput::text($body['filename'],255,true);$hash=$body['sha256'];
                if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))!=='mp4'||!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1)throw new HttpException(422,'视频参数不正确');
                $refs=self::entries($root);$count=0;foreach($refs as$ref)if(self::state($root,$ref)['actor']===$actor)++$count;
                if(count($refs)>=$max||$count>=$per)throw new HttpException(422,'视频暂存容量已满，请等待已有材料核对和回收');
                $ref=bin2hex(random_bytes(16));if(file_exists($root.DIRECTORY_SEPARATOR.$ref.'.json')||is_link($root.DIRECTORY_SEPARATOR.$ref.'.json'))self::fail();
                $now=time();$expires=$now+$ttl;
                $issued=$within(function(array $configuration)use($ref,$size,$expires):array{return ['configuration'=>$configuration]+$this->storage->issueCloudVideo($configuration,$ref,$size,$expires);});
                $state=['purpose'=>self::PURPOSE,'binding'=>$binding,'actor'=>$actor,'filename'=>$filename,'total_size'=>$size,'sha256'=>$hash,
                    'created_at'=>$now,'expires_at'=>$expires,'configuration'=>$issued['configuration'],'key'=>$issued['key'],'state'=>'issued','result'=>null];
                self::save($root,$ref,$state);
                return ['upload_reference'=>$ref,'upload_plan'=>$issued['plan']];
            }
            $ref=self::reference($body['upload_reference']??null);$s=self::state($root,$ref);
            if(!hash_equals($s['binding'],$binding)||!hash_equals($s['actor'],$actor))throw new HttpException(403,'没有权限访问');
            $configuration=$within(static fn(array $configuration):array=>$configuration);
            if($configuration!==$s['configuration'])self::fail();
            if($s['state']==='processing')self::fail();
            if($operation==='cloud_cancel'){
                if($s['state']==='complete')throw new HttpException(422,'已保存的视频请从素材库管理');
                $s['state']='cancelled';self::save($root,$ref,$s);return ['state'=>'cancelled'];
            }
            if($operation!=='cloud_finalize')throw new HttpException(422,'视频操作不正确');
            if($s['state']==='complete'){$observe($s['result'],$s['configuration']);return ['src'=>$s['result']];}
            if($s['expires_at']<=time())throw new HttpException(410,'视频暂存已失效');
            if($s['state']!=='issued')throw new HttpException(422,'本次视频暂存已关闭');
            $maximum=Config::get('upload.filesize');if(!is_int($maximum)||$maximum<1)self::fail();
            if($s['total_size']>$maximum)throw new HttpException(422,'视频文件过大');
            $bytes=$within(function(array $configuration)use($s):string{
                if($configuration!==$s['configuration'])self::fail();
                $bytes=$this->storage->readCloudVideo($configuration,$s['key'],$s['total_size']);
                if(strlen($bytes)!==$s['total_size']||!hash_equals($s['sha256'],hash('sha256',$bytes)))self::fail();
                $this->storage->qualifyTypedUploadBytes($bytes,$s['filename'],'video');return $bytes;
            });
            // No automatic retry or cleanup after the original commercial publisher might have begun.
            $s['state']='processing';self::save($root,$ref,$s);
            $src=$publish(['bytes'=>$bytes,'display_name'=>$s['filename']],$s['configuration']);unset($bytes);
            if(!is_string($src)||$src==='')self::fail();$s['state']='complete';$s['result']=$src;self::save($root,$ref,$s);
            return ['src'=>$src];
        });
    }
    public function gcBatch(int $limit): array
    {
        if($limit!==1)self::fail();if(!self::needsCollection())return ['state'=>'disabled'];
        return $this->locked(function(string $root):array{
            $held=0;$removed=0;$observed=0;
            foreach(self::entries($root)as$ref){$s=self::state($root,$ref);$bound=$s['configuration']['completion_bound_seconds']??0;
                if($s['state']==='processing'||!is_int($bound)||$bound<1||$s['expires_at']+$bound>=time()||$observed>=1){++$held;continue;}
                try{$this->storage->assertCloudVideoCollectionReady($s['configuration'],$s['key']);}
                catch(\Throwable $error){++$held;continue;}
                ++$observed;
                try{$absent=$this->storage->collectCloudVideo($s['configuration'],$s['key']);}catch(\Throwable $error){$absent=false;}
                if(!$absent){++$held;continue;}
                $path=$root.DIRECTORY_SEPARATOR.$ref.'.json';if(self::state($root,$ref)!==$s)self::fail();self::stat($path);
                if(!unlink($path))self::fail();clearstatcache(true,$path);if(file_exists($path)||is_link($path))self::fail();++$removed;
            }
            return ['state'=>'complete','removed'=>$removed,'held'=>$held];
        });
    }
}
