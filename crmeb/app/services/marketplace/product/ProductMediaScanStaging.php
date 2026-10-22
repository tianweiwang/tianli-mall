<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\services\marketplace\attachment\MerchantAttachmentStorage;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\MerchantManagementInput as Input;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\BoundedRedisConnection;
use think\facade\Config;

/** Internal private staging only. No Request/SQL/writer identity is manufactured here. */
final class ProductMediaScanStaging
{
    private const PURPOSE='product-media-scan-v1';
    private const MAX_BYTES=67108864;
    private const MAX_SINGLE=52428800;
    private const MAX_PLAN=262144;
    private const NATIVE=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    private const FIELDS=['name','att_dir','satt_dir','att_size','att_type','pid','time','image_type','module_type','real_name','scan_token','type','mer_id'];
    public function __construct(private MerchantAttachmentStorage $images) {}
    private static function fail(): never { throw new AuthUnavailableException(); }
    private static function keys(array $value,array $keys): void
    { $actual=array_keys($value);sort($actual);sort($keys);if($actual!==$keys)self::fail(); }
    private static function hex(mixed $value,int $length=32): string
    { if(!is_string($value)||strlen($value)!==$length||preg_match('/\A[0-9a-f]+\z/D',$value)!==1)self::fail();return $value; }
    private static function number(mixed $value,int $min,int $max,bool $string=false): int
    {
        if($string&&is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1
            &&(strlen($value)<strlen((string)$max)||(strlen($value)===strlen((string)$max)&&strcmp($value,(string)$max)<=0)))$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::fail();return $value;
    }
    private static function json(array $value,int $maximum=self::MAX_PLAN): string
    { $text=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(strlen($text)>$maximum)self::fail();return $text; }
    private static function decode(string $text,int $maximum=self::MAX_PLAN): array
    { if(strlen($text)>$maximum)self::fail();$value=json_decode($text,true,64,JSON_THROW_ON_ERROR);if(!is_array($value))self::fail();return $value; }
    private static function receipt(string $phase='preflight'): array
    { return ['complete'=>false,'phase'=>$phase,'physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null]; }
    private static function native(string $path): array
    {
        clearstatcache(true,$path);$stat=@lstat($path);
        if(!is_array($stat)||is_link($path)||($stat['mode']&0170000)!==0100000)self::fail();
        $out=[];foreach(self::NATIVE as$key){if(!is_int($stat[$key]))self::fail();$out[$key]=(string)$stat[$key];}return $out;
    }
    private function context(): array
    {
        if(PHP_INT_SIZE<8)self::fail();$config=Config::get('marketplace.product_media_scan');if(!is_array($config))self::fail();
        $configured=$config['private_root']??null;
        if(!is_string($configured)||$configured===''||str_contains($configured,"\0")||is_link($configured))self::fail();
        $root=realpath($configured);$public=realpath(app()->getRootPath().'public');$uploads=realpath(Config::get('filesystem.disks.public.root',''));
        if($root===false||!is_dir($root)||!is_writable($root)||$public===false||$uploads===false)self::fail();
        foreach([$public,$uploads]as$forbidden)if($root===$forbidden||str_starts_with($root,$forbidden.DIRECTORY_SEPARATOR)||str_starts_with($forbidden,$root.DIRECTORY_SEPARATOR))self::fail();
        clearstatcache(true,$root);$stat=@lstat($root);if(!is_array($stat)||($stat['mode']&0170000)!==0040000)self::fail();
        $rootId=hash('sha256',self::json(['purpose'=>self::PURPOSE,'root'=>$root,'dev'=>(string)$stat['dev'],'ino'=>(string)$stat['ino']]));
        $store=Config::get('cache.default');$options=is_string($store)?Config::get('cache.stores.'.$store):null;
        if(!is_array($options)||!is_string($options['type']??null)||strcasecmp($options['type'],'redis')!==0||!is_string($options['prefix']??null)
            ||strlen($options['prefix'])>128||str_contains($options['prefix'],"\0"))self::fail();
        $limits=['open'=>self::number($config['max_open_leases']??null,1,2147483647,true),
            'issuer'=>self::number($config['per_issuer']??null,1,2147483647,true),'terminal'=>self::number($config['max_terminal_leases']??null,1,2147483647,true),
            'batch'=>self::number($config['gc_batch']??null,1,256,true)];
        self::number($config['gc_period_seconds']??null,1,86400,true);
        if($limits['issuer']>$limits['open'])self::fail();
        return ['root'=>$root,'root_id'=>$rootId,'base'=>$options['prefix'].self::PURPOSE.':','limits'=>$limits];
    }
    private function command(string $op,string $id,string $issuer,array $payload=[]): array
    {
        self::hex($id);if(preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$issuer)!==1||(strlen($issuer)===10&&strcmp($issuer,'4294967295')>0))self::fail();
        $c=$this->context();$base=$c['base'];$script=file_get_contents(__DIR__.'/lua/product-media-scan.lua');if(!is_string($script)||$script==='')self::fail();
        $redis=null;$error=null;$result=null;
        try{
            $redis=BoundedRedisConnection::fromDefaultCacheConfig();
            if($redis->setOption(\Redis::OPT_SERIALIZER,\Redis::SERIALIZER_NONE)!==true||$redis->setOption(\Redis::OPT_PREFIX,'')!==true||$redis->getLastError()!==null)self::fail();
            $keys=[$base.'live',$base.'terminal',$base.'lease:'.$id,$base.'items:'.$id,$base.'issuer:'.$issuer,$base.'gc-cursor'];
            $result=$redis->eval($script,array_merge($keys,[$op,$base,$id,$issuer,$c['root_id'],self::json($c['limits'],2048),self::json($payload,65536)]),6);
            if(!is_array($result)||!array_is_list($result)||!is_string($result[0]??null)||!in_array($result[0],['OK','START','PROCESSING','DONE','UNKNOWN','GONE','CAPACITY','FOREIGN','MISMATCH','CLOSED','PENDING_CLAIM','PENDING_UPLOAD'],true)
                ||!is_string($result[1]??null))self::fail();
            $result=['outcome'=>$result[0],'data'=>self::decode($result[1],self::MAX_PLAN)];
        }catch(\Throwable $caught){$error=$caught;}
        finally{if($redis!==null){try{if($redis->close()!==true)$error??=new AuthUnavailableException();}catch(\Throwable $ignored){$error??=new AuthUnavailableException();}}}
        if($error!==null)self::fail();return $result;
    }
    private static function binding(array $binding): array
    {
        self::keys($binding,['issuer','product_id','mer_id','category_id']);if(!is_array($binding['issuer']))self::fail();$i=$binding['issuer'];
        self::keys($i,['id','sid','version','fingerprint','epoch']);
        if(!is_string($i['id'])||preg_match('/\A[1-9][0-9]{0,9}\z/D',$i['id'])!==1||self::number($i['id'],1,4294967295,true)<1
            ||!is_string($i['epoch'])||preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D',$i['epoch'])!==1)self::fail();
        self::hex($i['sid']);self::hex($i['version']);self::hex($i['fingerprint'],64);
        return ['issuer'=>['id'=>$i['id'],'sid'=>$i['sid'],'version'=>$i['version'],'fingerprint'=>$i['fingerprint'],'epoch'=>$i['epoch']],
            'product_id'=>self::number($binding['product_id'],0,8388607),'mer_id'=>self::number($binding['mer_id'],1,4294967295),
            'category_id'=>self::number($binding['category_id'],0,2147483647)];
    }
    private function checked(array $lease): array
    {
        $id=self::hex($lease['lease_id']??null);$fresh=$this->lease($id);
        if(($fresh['state']??null)==='GONE'||($fresh['binding_digest']??null)!==($lease['binding_digest']??null)||($fresh['root_id']??null)!==($lease['root_id']??null))self::fail();return $fresh;
    }
    private function operation(array $lease,string $op,array $payload=[]): array
    { $b=self::binding($lease['binding']);return $this->command($op,self::hex($lease['lease_id']),$b['issuer']['id'],['binding_digest'=>self::hex($lease['binding_digest'],64)]+$payload); }
    private function directory(string $id,bool $create=false): string
    {
        $c=$this->context();$path=$c['root'].DIRECTORY_SEPARATOR.self::hex($id);
        if($create){if(file_exists($path)||is_link($path)||@mkdir($path,0700)!==true)self::fail();}
        clearstatcache(true,$path);$s=@lstat($path);
        if(!is_array($s)||is_link($path)||($s['mode']&0170000)!==0040000||($s['mode']&0777)!==0700||realpath($path)!==$path)self::fail();return $path;
    }
    private function readFile(string $path,?array $expected=null,?string $hash=null,int $maximum=self::MAX_PLAN): array
    {
        $before=self::native($path);$size=self::number($before['size'],1,$maximum,true);if($expected!==null){self::keys($expected,self::NATIVE);foreach(self::NATIVE as$key)if(($expected[$key]??null)!==$before[$key])self::fail();}
        $handle=null;$closed=false;$error=null;$bytes='';
        try{
            $handle=@fopen($path,'rb');if(!is_resource($handle))self::fail();$stat=fstat($handle);if(!is_array($stat))self::fail();
            foreach(self::NATIVE as$key)if((string)$stat[$key]!==$before[$key])self::fail();
            $bytes=stream_get_contents($handle,$size+1);if(!is_string($bytes)||strlen($bytes)!==$size||($hash!==null&&hash('sha256',$bytes)!==$hash))self::fail();
        }catch(\Throwable $caught){$error=$caught;}
        finally{if(is_resource($handle)){try{$closed=fclose($handle)===true;}catch(\Throwable $ignored){}}}
        if(!$closed){$r=self::receipt('staging_read_close');$r['cleanup_complete']=false;$this->holdPrivate($path,$r);throw new MerchantAttachmentOperationException($r);}
        if($error!==null||self::native($path)!==$before)self::fail();return ['bytes'=>$bytes,'native'=>$before,'sha256'=>hash('sha256',$bytes),'size'=>$size];
    }
    private function holdPrivate(string $path,array $receipt): void
    {
        try{$id=self::hex(basename(dirname($path)));$l=$this->lease($id);if(($l['state']??null)==='GONE'||($l['state']??null)==='CLOSED')return;
            if($l['state']==='CLAIMED')$receipt['database_committed']=true;
            $this->operation($l,'private_unknown',['receipt_json'=>self::json($receipt,2048)]);
        }catch(\Throwable $ignored){}
    }
    private function writeFile(string $id,string $name,string $bytes,bool $databaseCommitted=false): array
    {
        if(preg_match('/\A(?:[a-f0-9]{32}\.bin|lease\.json|prepared\.json|committed\.json)\z/D',$name)!==1)self::fail();
        $dir=$this->directory($id);$path=$dir.DIRECTORY_SEPARATOR.$name;$part=$path.'.part';$r=self::receipt('staging_write');$r['database_committed']=$databaseCommitted;$handle=null;$closed=true;$failure=null;
        try{
            if(file_exists($path)||is_link($path)||file_exists($part)||is_link($part))self::fail();
            $r['physical_changed']=null;$handle=@fopen($part,'x+b');if(!is_resource($handle))self::fail();$closed=false;$r['physical_changed']=true;
            if(@chmod($part,0600)!==true)self::fail();$initial=self::native($part);if($initial['size']!=='0'||((int)$initial['mode']&0777)!==0600)self::fail();
            $length=strlen($bytes);$offset=0;
            for($i=0;$i<64&&$offset<$length;$i++){$written=fwrite($handle,substr($bytes,$offset));if(!is_int($written)||$written<1)self::fail();$offset+=$written;}
            if($offset!==$length||fflush($handle)!==true)self::fail();
            $r['phase']='staging_close';$closed=fclose($handle)===true;$handle=null;if(!$closed)self::fail();
            $proof=$this->readFile($part,null,hash('sha256',$bytes),max(self::MAX_PLAN,self::MAX_SINGLE));if($proof['bytes']!==$bytes)self::fail();unset($proof['bytes']);
            if(@rename($part,$path)!==true)self::fail();clearstatcache(true,$part);if(file_exists($part)||is_link($part))self::fail();
            $proof=$this->readFile($path,null,hash('sha256',$bytes),max(self::MAX_PLAN,self::MAX_SINGLE));if($proof['bytes']!==$bytes)self::fail();unset($proof['bytes']);
            $r['physical_complete']=true;$r['phase']='staging_written';return $proof+['receipt'=>$r];
        }catch(\Throwable $caught){$failure=$caught;if($caught instanceof MerchantAttachmentOperationException&&($caught->receipt['cleanup_complete']??null)===false)$r['cleanup_complete']=false;}
        finally{if(is_resource($handle)){try{$closed=fclose($handle)===true;}catch(\Throwable $ignored){$closed=false;}}if(!$closed)$r['cleanup_complete']=false;}
        if($r['cleanup_complete']===false)$this->holdPrivate($path,$r);
        throw new MerchantAttachmentOperationException($r);
    }
    public function issue(array $trustedBinding): array
    {
        $b=self::binding($trustedBinding);$id=bin2hex(random_bytes(16));$cap=bin2hex(random_bytes(32));$digest=hash('sha256',self::json($b,4096));
        $res=$this->command('reserve_lease',$id,$b['issuer']['id'],['binding_json'=>self::json($b,4096),'binding_digest'=>$digest,'cap_hash'=>hash('sha256',$cap)]);
        if($res['outcome']==='CAPACITY')return ['outcome'=>'CAPACITY'];if($res['outcome']!=='OK')self::fail();$lease=$res['data'];
        $this->directory($id,true);$manifest=['schema'=>1,'purpose'=>self::PURPOSE,'lease_id'=>$id,'binding'=>$b,'binding_digest'=>$digest,
            'root_id'=>$lease['root_id'],'issued_at_ms'=>$lease['issued_at_ms'],'expires_at_ms'=>$lease['expires_at_ms']];
        $proof=$this->writeFile($id,'lease.json',self::json($manifest,8192));
        $done=$this->operation($lease,'publish_lease',['manifest_hash'=>$proof['sha256']]);if($done['outcome']!=='OK')self::fail();
        return ['outcome'=>'ISSUED','lease_id'=>$id,'cap'=>$cap,'expires_at_ms'=>self::number($done['data']['expires_at_ms'],1,9007199254740991,true),'internal_lease'=>$done['data']];
    }
    public function lease(string $leaseId): array
    {
        $res=$this->command('read',self::hex($leaseId),'0');if($res['outcome']==='GONE')return ['lease_id'=>$leaseId,'state'=>'GONE'];if($res['outcome']!=='OK')self::fail();
        $l=$res['data'];$b=self::binding($l['binding']??[]);if(hash('sha256',self::json($b,4096))!==($l['binding_digest']??null)||($l['lease_id']??null)!==$leaseId)self::fail();return $l;
    }
    public function assertPhoneCapability(array $lease,string $cap): void
    { $l=$this->checked($lease);self::hex($cap,64);if(!hash_equals(self::hex($l['cap_hash']??null,64),hash('sha256',$cap)))self::fail(); }
    private function qualified(array $q): array
    {
        self::keys($q,['extension','mime','size','width','height','sha256']);self::hex($q['sha256'],64);
        if(!is_string($q['extension'])||!in_array($q['extension'],['jpg','jpeg','png','gif','webp'],true)||!is_string($q['mime']))self::fail();
        $maximum=min(self::number(Config::get('upload.filesize'),1,PHP_INT_MAX),self::MAX_SINGLE);self::number($q['size'],1,$maximum);self::number($q['width'],1,PHP_INT_MAX);self::number($q['height'],1,PHP_INT_MAX);return ['extension'=>$q['extension'],'mime'=>$q['mime'],'size'=>$q['size'],'width'=>$q['width'],'height'=>$q['height'],'sha256'=>$q['sha256']];
    }
    public function reserveUpload(array $lease,array $qualified,string $originalName): array
    {
        $l=$this->checked($lease);$q=$this->qualified($qualified);$name=Input::text($originalName,255,true);
        $res=$this->operation($l,'reserve_upload',['stage_id'=>bin2hex(random_bytes(16)),'upload_op_id'=>bin2hex(random_bytes(16)),
            'size'=>(string)$q['size'],'sha256'=>$q['sha256'],'qualified'=>$q,'original_name'=>$name]);
        if($res['outcome']!=='OK')return ['outcome'=>$res['outcome']];return ['outcome'=>'RESERVED','lease'=>$res['data']['lease'],'item'=>$res['data']['item']];
    }
    private function reservation(array $reservation): array
    {
        if(($reservation['outcome']??null)!=='RESERVED'||!is_array($reservation['item']??null))self::fail();$l=$this->checked($reservation['lease']);$i=$reservation['item'];self::hex($i['stage_id']??null);self::hex($i['upload_op_id']??null);
        $matches=array_values(array_filter($l['items'],static fn(array $v):bool=>($v['stage_id']??null)===$i['stage_id']&&($v['upload_op_id']??null)===$i['upload_op_id']));
        if(count($matches)!==1||$matches[0]['state']!=='PENDING')self::fail();return [$l,$matches[0]];
    }
    public function writeReserved(array $reservation,string $bytes): array
    {
        [$l,$i]=$this->reservation($reservation);$q=$this->qualified($this->images->qualifyImageBytes($bytes));
        if($q!==$this->qualified($i['qualified'])||hash('sha256',$bytes)!==$i['sha256'])self::fail();
        $proof=$this->writeFile($l['lease_id'],$i['stage_id'].'.bin',$bytes);return ['lease_id'=>$l['lease_id'],'stage_id'=>$i['stage_id'],'upload_op_id'=>$i['upload_op_id'],'sha256'=>$proof['sha256'],'size'=>$proof['size'],'native'=>$proof['native'],'receipt'=>$proof['receipt']];
    }
    public function publishUpload(array $reservation,array $privateFileProof): array
    {
        [$l,$i]=$this->reservation($reservation);self::keys($privateFileProof,['lease_id','stage_id','upload_op_id','sha256','size','native','receipt']);
        if($privateFileProof['lease_id']!==$l['lease_id']||$privateFileProof['stage_id']!==$i['stage_id']||$privateFileProof['upload_op_id']!==$i['upload_op_id'])self::fail();
        $proof=$this->readFile($this->directory($l['lease_id']).DIRECTORY_SEPARATOR.$i['stage_id'].'.bin',$privateFileProof['native'],$i['sha256'],self::MAX_SINGLE);
        if($proof['size']!==$i['size']||$privateFileProof['receipt']['cleanup_complete']===false)self::fail();unset($proof['bytes']);
        $res=$this->operation($l,'publish_upload',['stage_id'=>$i['stage_id'],'upload_op_id'=>$i['upload_op_id'],'proof'=>$proof]);return ['outcome'=>$res['outcome']]+$res['data'];
    }
    private function removeKnown(string $path,?array $native=null,?string $hash=null): bool
    {
        clearstatcache(true,$path);if(!file_exists($path)&&!is_link($path))return true;
        $before=self::native($path);if($native!==null){self::keys($native,self::NATIVE);foreach(self::NATIVE as$key)if(($native[$key]??null)!==$before[$key])self::fail();}if($hash!==null&&@hash_file('sha256',$path)!==$hash)self::fail();if(self::native($path)!==$before)self::fail();
        if(@unlink($path)!==true)self::fail();clearstatcache(true,$path);return !file_exists($path)&&!is_link($path);
    }
    public function cancelUpload(array $reservation,array $priorReceipt): array
    {
        self::keys($priorReceipt,['complete','phase','physical_changed','physical_complete','database_committed','cleanup_complete']);
        if(($priorReceipt['cleanup_complete']??null)===false)return ['state'=>'unknown','cleanup_complete'=>false];
        if($priorReceipt['database_committed']!==false)return ['state'=>'unknown','cleanup_complete'=>null];
        $l=$this->checked($reservation['lease']);$i=$reservation['item'];$res=$this->operation($l,'cancel_check',['stage_id'=>self::hex($i['stage_id']),'upload_op_id'=>self::hex($i['upload_op_id'])]);
        if($res['outcome']!=='OK')return ['state'=>'unknown','cleanup_complete'=>null];
        try{$dir=$this->directory($l['lease_id']);foreach(['.bin.part','.bin']as$suffix)if(!$this->removeKnown($dir.DIRECTORY_SEPARATOR.$i['stage_id'].$suffix))self::fail();
            $ack=$this->operation($l,'cancel_confirmed',['stage_id'=>$i['stage_id'],'upload_op_id'=>$i['upload_op_id'],'cleanup_complete'=>true]);return ['state'=>$ack['outcome']==='OK'?'cancelled':'unknown','cleanup_complete'=>$ack['outcome']==='OK'?true:null];
        }catch(\Throwable $ignored){return ['state'=>'unknown','cleanup_complete'=>false];}
    }
    public function listReady(array $lease): array
    {
        $l=$this->checked($lease);$list=[];foreach($l['items']as$i)if($i['state']==='READY')$list[]=['stage_id'=>$i['stage_id'],'sequence'=>$i['sequence'],'name'=>$i['original_name'],'mime'=>$i['qualified']['mime'],'size'=>$i['size']];
        return ['lease_id'=>$l['lease_id'],'state'=>$l['state'],'expires_at_ms'=>self::number($l['expires_at_ms'],1,9007199254740991,true),'list'=>$list];
    }
    public function readReady(array $lease,string $stageId): array
    {
        $l=$this->checked($lease);self::hex($stageId);if(!in_array($l['state'],['OPEN','CLAIMING','CLAIMED'],true))self::fail();
        if($l['state']==='OPEN'&&self::number($l['now_ms'],1,9007199254740991,true)>=self::number($l['expires_at_ms'],1,9007199254740991,true))self::fail();
        $items=array_values(array_filter($l['items'],static fn(array $i):bool=>$i['stage_id']===$stageId&&$i['state']==='READY'));if(count($items)!==1)self::fail();$i=$items[0];
        $proof=$this->readFile($this->directory($l['lease_id']).DIRECTORY_SEPARATOR.$stageId.'.bin',$i['proof']['native'],$i['sha256'],self::MAX_SINGLE);
        $q=$this->qualified($this->images->qualifyImageBytes($proof['bytes']));if($q!==$this->qualified($i['qualified']))self::fail();
        return ['bytes'=>$proof['bytes'],'qualified'=>$q,'original_name'=>$i['original_name'],'proof'=>['native'=>$proof['native'],'sha256'=>$proof['sha256'],'size'=>$proof['size']]];
    }
    public function beginClaim(array $lease,array $orderedStageIds,int $destinationPid): array
    {
        $l=$this->checked($lease);self::number($destinationPid,0,2147483647);if(!array_is_list($orderedStageIds)||!$orderedStageIds||count($orderedStageIds)>50)self::fail();
        foreach($orderedStageIds as$id)self::hex($id);if(count(array_unique($orderedStageIds))!==count($orderedStageIds))self::fail();
        $digest=hash('sha256',self::json(['stage_ids'=>$orderedStageIds,'destination_pid'=>$destinationPid]));
        $res=$this->operation($l,'begin_claim',['claim_id'=>bin2hex(random_bytes(16)),'selection_digest'=>$digest,'ids'=>$orderedStageIds,'destination_pid'=>(string)$destinationPid]);
        $claim=['outcome'=>$res['outcome']];if(in_array($res['outcome'],['START','PROCESSING','DONE','UNKNOWN'],true)&&is_string($res['data']['claim_id']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$res['data']['claim_id'])===1){
            $claim+=['lease'=>$res['data'],'claim_id'=>$res['data']['claim_id'],'selection_digest'=>$digest,'destination_pid'=>$destinationPid,'stage_ids'=>$orderedStageIds];
            if($res['outcome']==='DONE'){$file=$this->readFile($this->directory($l['lease_id']).DIRECTORY_SEPARATOR.'committed.json',null,self::hex($res['data']['commit_hash'],64));$claim['committed']=self::decode($file['bytes']);}
        }return $claim;
    }
    private function claim(array $claim): array
    {
        if(($claim['outcome']??null)!=='START')self::fail();$l=$this->checked($claim['lease']);if(self::hex($claim['claim_id'])!==$l['claim_id']||self::hex($claim['selection_digest'],64)!==$l['selection_digest'])self::fail();return $l;
    }
    public function sealClaimPlan(array $claim,array $exactPlan): array
    {
        $l=$this->claim($claim);self::keys($exactPlan,['schema','purpose','lease_id','claim_id','binding_digest','root_id','selection_digest','destination_pid','items']);
        if($exactPlan['schema']!==1||$exactPlan['purpose']!==self::PURPOSE||$exactPlan['lease_id']!==$l['lease_id']||$exactPlan['claim_id']!==$l['claim_id']
            ||$exactPlan['binding_digest']!==$l['binding_digest']||$exactPlan['root_id']!==$l['root_id']||$exactPlan['selection_digest']!==$l['selection_digest']||$exactPlan['destination_pid']!==$claim['destination_pid']
            ||!is_array($exactPlan['items'])||!array_is_list($exactPlan['items'])||count($exactPlan['items'])!==count($claim['stage_ids']))self::fail();
        foreach($exactPlan['items']as$index=>$item){self::keys($item,['stage_id','sha256','object_identity','fields','audit']);
            if($item['stage_id']!==$claim['stage_ids'][$index])self::fail();self::hex($item['sha256'],64);Input::text($item['object_identity'],512,true);
            $found=array_values(array_filter($l['items'],static fn(array $i):bool=>$i['stage_id']===$item['stage_id']&&$i['state']==='READY'));if(count($found)!==1||$found[0]['sha256']!==$item['sha256'])self::fail();$stage=$found[0];
            self::keys($item['fields'],self::FIELDS);$f=$item['fields'];
            foreach(['name'=>100,'att_dir'=>200,'satt_dir'=>200,'att_size'=>30,'att_type'=>30,'real_name'=>255,'scan_token'=>32]as$k=>$max)Input::text($f[$k],$max,true);
            if($f['mer_id']!==$l['binding']['mer_id']||$f['pid']!==$claim['destination_pid']||$f['module_type']!==1||$f['type']!==0||$f['scan_token']!==$l['claim_id']
                ||$f['real_name']!==$stage['original_name']||$f['att_size']!==(string)$stage['size']||$f['att_type']!==$stage['qualified']['mime'])self::fail();
            self::number($f['time'],1,2147483647);self::number($f['image_type'],1,7);
            $a=$item['audit'];self::keys($a,['actor_type','actor_id','mer_id','action','resource_type','changed_fields']);self::number($a['actor_id'],1,4294967295);
            if($a['actor_type']!=='admin'||(string)$a['actor_id']!==$l['binding']['issuer']['id']||$a['mer_id']!==$f['mer_id']||$a['action']!=='legacy-product-media-scan-claim'
                ||$a['resource_type']!=='attachment'||$a['changed_fields']!==['name','att_dir','satt_dir','pid'])self::fail();
        }
        $proof=$this->writeFile($l['lease_id'],'prepared.json',self::json($exactPlan));
        $res=$this->operation($l,'seal_plan',['claim_id'=>$l['claim_id'],'selection_digest'=>$l['selection_digest'],'plan_hash'=>$proof['sha256']]);if($res['outcome']!=='OK')self::fail();
        return ['claim_id'=>$l['claim_id'],'plan_sha256'=>$proof['sha256'],'closed'=>true];
    }
    public function markSqlAttempted(array $claim,string $planHash): void
    {
        $l=$this->claim($claim);self::hex($planHash,64);$this->readFile($this->directory($l['lease_id']).DIRECTORY_SEPARATOR.'prepared.json',null,$planHash);
        $r=$this->operation($l,'sql_attempted',['claim_id'=>$l['claim_id'],'selection_digest'=>$l['selection_digest'],'plan_hash'=>$planHash]);if($r['outcome']!=='OK')self::fail();
    }
    public function markClaimed(array $claim,array $actualNativeRows): void
    {
        $l=$this->claim($claim);$p=$this->readFile($this->directory($l['lease_id']).DIRECTORY_SEPARATOR.'prepared.json',null,self::hex($l['plan_hash'],64));$plan=self::decode($p['bytes']);
        if(!array_is_list($actualNativeRows)||count($actualNativeRows)!==count($plan['items']))self::fail();$ids=[];
        foreach($actualNativeRows as$i=>$row){self::keys($row,array_merge(['att_id'],self::FIELDS));$id=self::number($row['att_id'],1,2147483647,true);if(in_array($id,$ids,true))self::fail();$ids[]=$id;
            foreach($plan['items'][$i]['fields']as$key=>$expected)if(is_int($expected)?(string)$row[$key]!== (string)$expected:$row[$key]!==$expected)self::fail();}
        $commit=['schema'=>1,'purpose'=>self::PURPOSE,'lease_id'=>$l['lease_id'],'claim_id'=>$l['claim_id'],'plan_sha256'=>$l['plan_hash'],'rows'=>$actualNativeRows];
        $proof=$this->writeFile($l['lease_id'],'committed.json',self::json($commit),true);
        $r=$this->operation($l,'claimed',['claim_id'=>$l['claim_id'],'selection_digest'=>$l['selection_digest'],'plan_hash'=>$l['plan_hash'],'commit_hash'=>$proof['sha256']]);if($r['outcome']!=='OK')self::fail();
    }
    public function markUnknown(array $claim,array $sixKeyReceipt): void
    {
        $l=$this->claim($claim);self::keys($sixKeyReceipt,['complete','phase','physical_changed','physical_complete','database_committed','cleanup_complete']);
        foreach(['physical_changed','database_committed','cleanup_complete']as$key)if(!in_array($sixKeyReceipt[$key],[true,false,null],true))self::fail();
        if(!is_bool($sixKeyReceipt['complete'])||!is_bool($sixKeyReceipt['physical_complete'])||!is_string($sixKeyReceipt['phase'])||preg_match('/\A[a-z0-9_]{1,64}\z/D',$sixKeyReceipt['phase'])!==1)self::fail();
        $r=$this->operation($l,'unknown',['claim_id'=>$l['claim_id'],'selection_digest'=>$l['selection_digest'],'receipt_json'=>self::json($sixKeyReceipt,2048)]);if($r['outcome']!=='OK')self::fail();
    }
    public function beginClose(array $lease): array
    { $l=$this->checked($lease);$r=$this->operation($l,'begin_close');return ['outcome'=>$r['outcome'],'lease'=>$r['data']]; }
    public function cleanClosed(array $closeTicket): array
    {
        $l=$this->checked($closeTicket['lease']);if($l['state']==='CLOSED')return ['cleanup_complete'=>true,'held'=>false,'lease'=>$l];
        if($l['state']!=='CLOSING')return ['cleanup_complete'=>null,'held'=>true,'lease'=>$l];
        foreach($l['items']as$i)if(in_array($i['state'],['PENDING','CANCELLING'],true))return ['cleanup_complete'=>null,'held'=>true,'lease'=>$l];
        try{
            $c=$this->context();$path=$c['root'].DIRECTORY_SEPARATOR.$l['lease_id'];clearstatcache(true,$path);
            if(!file_exists($path)&&!is_link($path))return ['cleanup_complete'=>true,'held'=>false,'lease'=>$l];$dir=$this->directory($l['lease_id']);$expected=[];
            foreach($l['items']as$i){$name=$i['stage_id'].'.bin';$expected[$name]=['native'=>$i['proof']['native']??null,'hash'=>$i['sha256']];}
            foreach(['lease.json'=>'manifest_hash','prepared.json'=>'plan_hash','committed.json'=>'commit_hash']as$name=>$field)if($l[$field]!=='')$expected[$name]=['native'=>null,'hash'=>$l[$field]];
            $names=@scandir($dir);if(!is_array($names)||count($names)>55)self::fail();foreach($names as$name)if($name!=='.'&&$name!=='..'&&!isset($expected[$name]))self::fail();
            foreach($expected as$name=>$proof)if(!$this->removeKnown($dir.DIRECTORY_SEPARATOR.$name,$proof['native'],$proof['hash']))self::fail();
            if(@rmdir($dir)!==true)self::fail();clearstatcache(true,$dir);if(file_exists($dir)||is_link($dir))self::fail();return ['cleanup_complete'=>true,'held'=>false,'lease'=>$l];
        }catch(\Throwable $ignored){return ['cleanup_complete'=>false,'held'=>true,'lease'=>$l];}
    }
    public function finishClose(array $closeTicket,array $proof): array
    {
        $l=$this->checked($closeTicket['lease']);if(($proof['cleanup_complete']??null)!==true||($proof['held']??null)!==false||($proof['lease']['lease_id']??null)!==$l['lease_id'])self::fail();
        $c=$this->context();$path=$c['root'].DIRECTORY_SEPARATOR.$l['lease_id'];clearstatcache(true,$path);if(file_exists($path)||is_link($path))self::fail();
        $r=$this->operation($l,'close_confirmed',['cleanup_complete'=>true]);if($r['outcome']!=='CLOSED')self::fail();return ['state'=>'closed','cleanup_complete'=>true];
    }
    public function gcBatch(int $limit): array
    {
        $c=$this->context();self::number($limit,1,$c['limits']['batch']);$r=$this->command('gc_due',str_repeat('0',32),'0',['limit'=>$limit]);if($r['outcome']!=='OK')self::fail();
        $summary=['scanned'=>0,'closed'=>0,'pending_upload'=>0,'held_claim'=>0,'foreign_root'=>0,'cleanup_unproved'=>0,'next_cursor'=>$r['data']['cursor']];
        $ids=$r['data']['ids'];if(!is_array($ids)||!array_is_list($ids)||count($ids)>$limit)self::fail();
        foreach($ids as$id){$summary['scanned']++;
            try{$raw=$this->command('read',self::hex($id),'0');if($raw['outcome']==='FOREIGN'){$summary['foreign_root']++;continue;}if($raw['outcome']==='GONE')continue;if($raw['outcome']!=='OK')self::fail();$l=$raw['data'];
                if(in_array($l['state'],['CLAIMING','UNKNOWN','ALLOCATING'],true)){$summary['held_claim']++;continue;}
                $ticket=$this->beginClose($l);if($ticket['outcome']==='PENDING_UPLOAD'){$summary['pending_upload']++;continue;}
                if($ticket['outcome']==='CLOSED')continue;if($ticket['outcome']!=='OK'){$summary['held_claim']++;continue;}
                $proof=$this->cleanClosed($ticket);if($proof['cleanup_complete']!==true){$summary['cleanup_unproved']++;continue;}$this->finishClose($ticket,$proof);$summary['closed']++;
            }catch(\Throwable $ignored){$summary['cleanup_unproved']++;}
        }return $summary;
    }
    /** Internal evidence only: a closed file never proves that SQL committed. */
    public function claimEvidence(array $claim): array
    {
        if(!in_array($claim['outcome']??null,['START','DONE','UNKNOWN','PROCESSING'],true)||!is_array($claim['lease']??null))self::fail();
        $l=$this->checked($claim['lease']);$id=self::hex($claim['claim_id']??null);$selection=self::hex($claim['selection_digest']??null,64);
        $destination=self::number($claim['destination_pid']??null,0,2147483647);$ids=$claim['stage_ids']??null;
        if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>50)self::fail();foreach($ids as$stageId)self::hex($stageId);
        if(count(array_unique($ids))!==count($ids)||$id!==($l['claim_id']??null)||$selection!==($l['selection_digest']??null)
            ||hash('sha256',self::json(['stage_ids'=>$ids,'destination_pid'=>$destination]))!==$selection
            ||($l['destination_pid']??null)!==(string)$destination||!is_string($l['selection_json']??null)
            ||self::decode($l['selection_json'],8192)!==$ids||!in_array($l['state'],['CLAIMING','CLAIMED','UNKNOWN'],true))self::fail();
        $result=['plan'=>null,'plan_sha256'=>null,'committed'=>null];$hash=$l['plan_hash']??null;
        if($hash==='')return $result;$result['plan_sha256']=self::hex($hash,64);
        $dir=$this->directory($l['lease_id']);$path=$dir.DIRECTORY_SEPARATOR.'prepared.json';clearstatcache(true,$path);
        if(!file_exists($path)&&!is_link($path))return $result;
        $proof=$this->readFile($path,null,$hash);$plan=self::decode($proof['bytes']);
        self::keys($plan,['schema','purpose','lease_id','claim_id','binding_digest','root_id','selection_digest','destination_pid','items']);
        if($plan['schema']!==1||$plan['purpose']!==self::PURPOSE||$plan['lease_id']!==$l['lease_id']||$plan['claim_id']!==$id
            ||$plan['binding_digest']!==$l['binding_digest']||$plan['root_id']!==$l['root_id']||$plan['selection_digest']!==$selection
            ||$plan['destination_pid']!==$destination||!is_array($plan['items'])||!array_is_list($plan['items'])||count($plan['items'])!==count($ids))self::fail();
        foreach($plan['items']as$index=>$item){if(!is_array($item))self::fail();self::keys($item,['stage_id','sha256','object_identity','fields','audit']);
            if($item['stage_id']!==$ids[$index]||!is_array($item['fields'])||!is_array($item['audit']))self::fail();self::keys($item['fields'],self::FIELDS);}
        $result['plan']=$plan;$path=$dir.DIRECTORY_SEPARATOR.'committed.json';clearstatcache(true,$path);$commitProof=null;
        if(file_exists($path)||is_link($path)){
            $published=$l['commit_hash']??null;if(!is_string($published))self::fail();
            $commitProof=$this->readFile($path,null,$published===''?null:self::hex($published,64));$commit=self::decode($commitProof['bytes']);
            self::keys($commit,['schema','purpose','lease_id','claim_id','plan_sha256','rows']);
            if($commit['schema']!==1||$commit['purpose']!==self::PURPOSE||$commit['lease_id']!==$l['lease_id']||$commit['claim_id']!==$id
                ||$commit['plan_sha256']!==$hash||!is_array($commit['rows'])||!array_is_list($commit['rows'])||count($commit['rows'])!==count($ids))self::fail();
            $seen=[];foreach($commit['rows']as$index=>$row){if(!is_array($row))self::fail();self::keys($row,array_merge(['att_id'],self::FIELDS));
                $attId=self::number($row['att_id'],1,2147483647,true);if(in_array($attId,$seen,true))self::fail();$seen[]=$attId;
                foreach($plan['items'][$index]['fields']as$key=>$expected)if(is_int($expected)?(string)$row[$key]!== (string)$expected:$row[$key]!==$expected)self::fail();}
            $result['committed']=$commit;
        }
        $after=$this->checked($l);
        foreach(['claim_id','selection_digest','destination_pid','plan_hash']as$key)if(($after[$key]??null)!==($l[$key]??null))self::fail();
        if(!in_array($after['state'],['CLAIMING','CLAIMED','UNKNOWN'],true))self::fail();
        if($commitProof!==null&&($after['commit_hash']??'')!==''&&self::hex($after['commit_hash'],64)!==$commitProof['sha256'])self::fail();
        return $result;
    }
}
