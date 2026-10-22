<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\dao\marketplace\product\PlatformProductMediaDao;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\attachment\MerchantAttachmentInput;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Config;
use think\file\UploadedFile;

/** Phones only stage; the current original PC issuer alone owns a commercial claim. */
final class ProductMediaScanServices
{
    private const PURPOSE='product-media-scan-v1';
    private const CLAIM='legacy-product-media-scan-claim';
    private const AUDIT_FIELDS=['name','att_dir','satt_dir','pid'];
    public function __construct(private ProductMediaScanIssuer $issuer,private ProductMediaScanStaging $staging,
        private PlatformProductMediaServices $media,private PlatformProductMediaDao $attachments,
        private MerchantAttachmentStorage $storage,private AuditLogServices $audit) {}
    private static function fail(): never { throw new AuthUnavailableException(); }
    private static function id(mixed $value,int $length=32): string
    { if(!is_string($value)||preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)!==1)MerchantAttachmentInput::invalid();return $value; }
    private static function receipt(string $phase='preflight'): array
    { return ['complete'=>false,'phase'=>$phase,'physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null]; }
    private function binding(array $lease): array
    {
        if(($lease['state']??null)==='GONE')throw new HttpException(404,'扫码任务不存在或已结束');
        $b=$lease['binding']??null;if(!is_array($b)||!is_array($b['issuer']??null))self::fail();return $b;
    }
    private function current(Request $request,int $productId,string $leaseId,string $operation): array
    {
        $l=$this->staging->lease(self::id($leaseId));$b=$this->binding($l);
        if($b['product_id']!==$productId)throw new HttpException(404,'资源不存在');
        $this->issuer->forRequest($request,$b['issuer'],$operation,$productId,$b['mer_id'],$b['category_id']);return $l;
    }
    private function again(Request $request,array $lease,string $operation): void
    { $b=$this->binding($lease);$this->issuer->forRequest($request,$b['issuer'],$operation,$b['product_id'],$b['mer_id'],$b['category_id']); }
    private function owner(int $productId): int
    {
        Input::integer($productId,0,8388607);
        if($productId===0)return LegacyProductWriteServices::selfId();
        $connection=Db::connect();$row=Db::name('store_product')->where('id',$productId)->field(['id','mer_id'])->find();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO||!is_array($row)||(string)($row['id']??'')!==(string)$productId)throw new HttpException(404,'资源不存在');
        $owner=Input::integer($row['mer_id'],1,4294967295,true);
        if(Db::connect()!==$connection||$connection->getPdo()!==$pdo)self::fail();return $owner;
    }
    public function issue(Request $request,int $productId,array $body): array
    {
        $i=$this->issuer->capture($request);$body=Input::body($body,['pid']);$pid=MerchantAttachmentInput::id($body['pid']??0,true);
        $owner=$this->owner($productId);$this->issuer->forRequest($request,$i,'issue',$productId,$owner,$pid);
        $r=$this->staging->issue(['issuer'=>$i,'product_id'=>$productId,'mer_id'=>$owner,'category_id'=>$pid]);
        if(($r['outcome']??null)==='CAPACITY')throw new HttpException(429,'当前扫码任务已满，请先关闭已结束任务');
        if(($r['outcome']??null)!=='ISSUED')self::fail();
        return ['lease_id'=>$r['lease_id'],'url'=>'/app/upload?scene=product_media&lease_id='.$r['lease_id'].'#scan_cap='.$r['cap'],'expires_at_ms'=>$r['expires_at_ms']];
    }
    /** Read only the actual multipart temporary file, before any durable private reservation. */
    private function uploaded(Request $request): array
    {
        if(app()->request!==$request)self::fail();$file=$request->file('file');
        if(!$file instanceof UploadedFile||!$file->isValid())MerchantAttachmentInput::invalid();
        $name=Input::text($file->getOriginalName(),255,true);$path=$file->getPathname();
        clearstatcache(true,$path);$before=@lstat($path);$max=min(Input::integer(Config::get('upload.filesize'),1,PHP_INT_MAX),52428800);
        if(!is_array($before)||is_link($path)||!is_file($path)||!is_int($before['size'])||$before['size']<1||$before['size']>$max)MerchantAttachmentInput::invalid();
        $handle=null;$closed=false;$failure=null;$bytes='';
        try{$handle=@fopen($path,'rb');if(!is_resource($handle))self::fail();$stat=fstat($handle);
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if(($stat[$key]??null)!==$before[$key])self::fail();
            $bytes=stream_get_contents($handle,$before['size']+1);if(!is_string($bytes)||strlen($bytes)!==$before['size'])self::fail();}
        catch(\Throwable $error){$failure=$error;}
        finally{if(is_resource($handle)){try{$closed=fclose($handle)===true;}catch(\Throwable $ignored){}}}
        if(!$closed){$r=self::receipt('multipart_read_close');$r['cleanup_complete']=false;throw new MerchantAttachmentOperationException($r);}
        clearstatcache(true,$path);$after=@lstat($path);
        foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if(($after[$key]??null)!==$before[$key])self::fail();
        if($failure!==null)throw $failure;
        return ['bytes'=>$bytes,'name'=>$name,'qualified'=>$this->storage->qualifyImageBytes($bytes)];
    }
    public function stage(Request $request,array $body): array
    {
        if(app()->request!==$request)self::fail();$body=Input::body($body,['lease_id','scan_cap'],['lease_id','scan_cap']);
        $l=$this->staging->lease(self::id($body['lease_id']));$b=$this->binding($l);$cap=self::id($body['scan_cap'],64);
        $this->staging->assertPhoneCapability($l,$cap);$this->issuer->verify($b['issuer'],$b['product_id'],$b['mer_id'],$b['category_id']);
        $file=$this->uploaded($request);$reservation=null;$r=self::receipt('private_staging');
        try{
            $this->issuer->verify($b['issuer'],$b['product_id'],$b['mer_id'],$b['category_id']);$this->staging->assertPhoneCapability($l,$cap);
            $reservation=$this->staging->reserveUpload($l,$file['qualified'],$file['name']);
            if(($reservation['outcome']??null)==='CAPACITY')throw new HttpException(422,'本次扫码图片数量或总大小已达上限');
            if(($reservation['outcome']??null)!=='RESERVED')throw new HttpException(410,'扫码任务已结束');
            $proof=$this->staging->writeReserved($reservation,$file['bytes']);$r=$proof['receipt'];
            $this->issuer->verify($b['issuer'],$b['product_id'],$b['mer_id'],$b['category_id']);$this->staging->assertPhoneCapability($reservation['lease'],$cap);
            $published=$this->staging->publishUpload($reservation,$proof);
            if(($published['outcome']??null)!=='OK')self::fail();
            return ['stage_id'=>$published['stage_id'],'sequence'=>$published['sequence'],'state'=>'ready'];
        }catch(\Throwable $error){
            if($error instanceof MerchantAttachmentOperationException)$r=$error->receipt;
            if(is_array($reservation)&&($reservation['outcome']??null)==='RESERVED'){
                try{$clean=$this->staging->cancelUpload($reservation,$r);if($r['cleanup_complete']!==false)$r['cleanup_complete']=$clean['cleanup_complete'];}
                catch(\Throwable $ignored){$r['cleanup_complete']=false;}
                throw new MerchantAttachmentOperationException($r);
            }
            throw $error;
        }
    }
    public function poll(Request $request,int $productId,string $leaseId): array
    { $l=$this->current($request,$productId,$leaseId,'poll');$result=$this->staging->listReady($l);$this->again($request,$l,'poll');return $result; }
    public function preview(Request $request,int $productId,string $leaseId,string $stageId): array
    {
        $l=$this->current($request,$productId,$leaseId,'preview');$image=$this->staging->readReady($l,self::id($stageId));$this->again($request,$l,'preview');
        return ['bytes'=>$image['bytes'],'mime'=>$image['qualified']['mime']];
    }
    private function within(Request $request,int $productId,callable $operation): mixed
    { return $productId===0?$this->media->forCreate($request,$operation):$this->media->forProduct($request,$productId,$operation); }
    private static function physical(array &$r,array $observations,int $expected): void
    {
        $r['physical_changed']=false;$all=count($observations)===$expected&&$expected>0;$unknown=false;
        foreach($observations as$o){if($o['physical_changed']===true)$r['physical_changed']=true;elseif($o['physical_changed']===null)$unknown=true;
            if($o['physical_complete']!==true)$all=false;if($o['cleanup_complete']===false)$r['cleanup_complete']=false;}
        if($r['physical_changed']!==true&&$unknown)$r['physical_changed']=null;$r['physical_complete']=$all;
    }
    private function storedReceipt(array $lease): array
    {
        $raw=$lease['receipt_json']??null;if(!is_string($raw)||$raw==='')self::fail();
        try{$r=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $error){self::fail();}
        if(!is_array($r))self::fail();$keys=array_keys($r);sort($keys);
        if($keys!==['cleanup_complete','complete','database_committed','phase','physical_changed','physical_complete']
            ||!is_bool($r['complete'])||!is_bool($r['physical_complete'])||!is_string($r['phase'])||preg_match('/\A[a-z0-9_]{1,64}\z/D',$r['phase'])!==1)self::fail();
        foreach(['physical_changed','database_committed','cleanup_complete']as$key)if(!in_array($r[$key],[true,false,null],true))self::fail();return $r;
    }
    /** Null is an unproved outcome, never permission to repeat physical or SQL work. */
    private function committed(Request $request,array $claim,array $evidence): ?array
    {
        $plan=$evidence['plan']??null;if(!is_array($plan))return null;$l=$claim['lease'];$b=$this->binding($l);
        if(($plan['schema']??null)!==1||($plan['purpose']??null)!==self::PURPOSE||($plan['lease_id']??null)!==$l['lease_id']
            ||($plan['claim_id']??null)!==$claim['claim_id']||($plan['binding_digest']??null)!==$l['binding_digest']
            ||($plan['root_id']??null)!==$l['root_id']||($plan['selection_digest']??null)!==$claim['selection_digest']
            ||($plan['destination_pid']??null)!==$claim['destination_pid']||!is_array($plan['items']??null)
            ||!array_is_list($plan['items'])||count($plan['items'])!==count($claim['stage_ids']))self::fail();
        $snapshot=$this->attachments->scanClaimState($request,$b['issuer'],$b['product_id'],$b['mer_id'],$b['category_id'],$claim['claim_id']);
        if(count($snapshot['rows'])!==count($plan['items'])||count($snapshot['audits'])!==count($plan['items']))return null;
        $saved=$evidence['committed']??null;$ids=[];$rows=[];
        foreach($plan['items']as$index=>$item){
            if(($item['stage_id']??null)!==$claim['stage_ids'][$index]||!is_array($item['fields']??null))self::fail();
            $row=$snapshot['rows'][$index];
            foreach($item['fields']as$key=>$value)if(is_int($value)?(string)$row[$key]!== (string)$value:$row[$key]!==$value)return null;
            if($row['scan_token']!==$claim['claim_id']||isset($ids[(string)$row['att_id']]))return null;$ids[(string)$row['att_id']]=true;
            if(is_array($saved)&&(($saved['rows'][$index]??null)!==$row))return null;
            $object=$this->storage->locateForProductScanClaim($row,$request,$b['issuer'],$b['product_id'],$b['mer_id'],$b['category_id']);
            if($this->storage->identity($object)!==$item['object_identity'])return null;
            $auditRows=array_values(array_filter($snapshot['audits'],static fn(array $a):bool=>$a['resource_id']===(string)$row['att_id']));
            if(count($auditRows)!==1)return null;$audit=$auditRows[0];$expected=$item['audit'];
            foreach(['actor_type','actor_id','mer_id','action','resource_type']as$key)if(is_int($expected[$key])?(string)$audit[$key]!== (string)$expected[$key]:$audit[$key]!==$expected[$key])return null;
            if($expected['changed_fields']!==self::AUDIT_FIELDS||(int)$audit['add_time']<(int)$item['fields']['time'])return null;$rows[]=$row;
        }
        $again=$this->attachments->scanClaimState($request,$b['issuer'],$b['product_id'],$b['mer_id'],$b['category_id'],$claim['claim_id']);
        if($again!==$snapshot)return null;$this->again($request,$l,'claim');return $rows;
    }
    private static function view(array $rows): array
    { return ['state'=>'claimed','list'=>array_map(static fn(array $row):array=>['att_id'=>(int)$row['att_id'],'src'=>$row['att_dir']],$rows)]; }
    public function claim(Request $request,int $productId,string $leaseId,array $body): array
    {
        $l=$this->current($request,$productId,$leaseId,'claim');$b=$this->binding($l);
        $body=Input::body($body,['stage_ids','destination_pid'],['stage_ids','destination_pid']);
        if(!is_array($body['stage_ids'])||!array_is_list($body['stage_ids'])||count($body['stage_ids'])<1||count($body['stage_ids'])>50)MerchantAttachmentInput::invalid();
        $ids=[];foreach($body['stage_ids']as$id){$id=self::id($id);if(!in_array($id,$ids,true))$ids[]=$id;}
        $destination=MerchantAttachmentInput::id($body['destination_pid'],true);$this->again($request,$l,'claim');
        $claim=$this->staging->beginClaim($l,$ids,$destination);$outcome=$claim['outcome']??null;
        if($outcome==='PROCESSING')return ['state'=>'processing','list'=>[]];
        if($outcome==='GONE')throw new HttpException(410,'扫码任务已结束');
        if($outcome==='MISMATCH')throw new HttpException(422,'本次认领图片或目标分类与原请求不同');
        if(in_array($outcome,['DONE','UNKNOWN'],true)){
            if(isset($claim['claim_id'])){$evidence=$this->staging->claimEvidence($claim);$rows=$this->committed($request,$claim,$evidence);if($rows!==null)return self::view($rows);}
            $fresh=$this->staging->lease($leaseId);throw new MerchantAttachmentOperationException($this->storedReceipt($fresh));
        }
        if($outcome!=='START')self::fail();$r=self::receipt();$objects=[];$observations=[];$images=[];$count=count($ids);
        try{
            foreach($ids as$id){$this->again($request,$l,'claim');$images[]=$this->staging->readReady($l,$id);}
            $rows=$this->within($request,$productId,function()use($request,$productId,$l,$b,$claim,$destination,$images,&$r,&$objects,&$observations):array{
                $this->again($request,$l,'claim');$pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productId);
                if($owner!==$b['mer_id'])self::fail();if($destination)$this->attachments->category($productId,$destination);$items=[];
                foreach($images as$index=>$image){
                    $this->again($request,$l,'claim');$objects[$index]=[];$observations[$index]=self::receipt();$r['phase']='physical_upload';
                    $q=$image['qualified'];$data=$this->storage->uploadBytesForProduct($productId,$image['bytes'],$q['sha256'].'.'.$q['extension'],$objects[$index],$observations[$index]);
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productId)!==$owner
                        ||$observations[$index]['physical_complete']!==true||!in_array($observations[$index]['physical_changed'],[true,null],true)
                        ||$observations[$index]['cleanup_complete']===false)self::fail();
                    $data['real_name']=$image['original_name'];$data+=['pid'=>$destination,'time'=>time(),'module_type'=>1,'scan_token'=>$claim['claim_id'],'type'=>0,'mer_id'=>$owner];
                    $items[]=['stage_id'=>$claim['stage_ids'][$index],'sha256'=>$q['sha256'],'object_identity'=>$this->storage->identity($objects[$index]),'fields'=>$data,
                        'audit'=>['actor_type'=>'admin','actor_id'=>$request->platformScope()->adminId,'mer_id'=>$owner,'action'=>self::CLAIM,'resource_type'=>'attachment','changed_fields'=>self::AUDIT_FIELDS]];
                }
                $r['phase']='private_plan';$plan=['schema'=>1,'purpose'=>self::PURPOSE,'lease_id'=>$l['lease_id'],'claim_id'=>$claim['claim_id'],
                    'binding_digest'=>$l['binding_digest'],'root_id'=>$l['root_id'],'selection_digest'=>$claim['selection_digest'],'destination_pid'=>$destination,'items'=>$items];
                $sealed=$this->staging->sealClaimPlan($claim,$plan);$this->again($request,$l,'claim');
                if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productId)!==$owner)self::fail();
                $this->staging->markSqlAttempted($claim,$sealed['plan_sha256']);$rows=[];
                foreach($items as$index=>$item){
                    $this->again($request,$l,'claim');$r['phase']='database_insert';$r['database_committed']=null;
                    $row=$this->attachments->addScanClaim($productId,$item['fields'],$claim['claim_id']);
                    $located=$this->storage->locateForProduct($row,$productId);if($this->storage->identity($located)!==$item['object_identity'])self::fail();
                    $r['phase']='audit';$this->audit->record('admin',$item['audit']['actor_id'],$owner,self::CLAIM,'attachment',(string)$row['att_id'],'success',['changed_fields'=>self::AUDIT_FIELDS]);
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productId)!==$owner)self::fail();$rows[]=$row;
                }
                $r['phase']='commit_observation';return $rows;
            });
            $r['database_committed']=true;self::physical($r,$observations,$count);$r['phase']='committed_read';
            $evidence=$this->staging->claimEvidence($claim);$verified=$this->committed($request,$claim,$evidence);
            if($verified!==$rows)self::fail();$this->staging->markClaimed($claim,$rows);$r['complete']=true;$r['phase']='complete';return self::view($rows);
        }catch(\Throwable $error){
            self::physical($r,$observations,$count);
            if($error instanceof MerchantAttachmentOperationException&&($error->receipt['cleanup_complete']??null)===false)$r['cleanup_complete']=false;
            if($r['database_committed']===false){$clean=[];
                foreach($objects as$index=>$object){if($object!==[]){try{$clean[]=$this->storage->cleanupNew($object,$observations[$index])===true;}catch(\Throwable $ignored){$clean[]=false;}}}
                if($r['cleanup_complete']!==false&&$clean!==[])$r['cleanup_complete']=!in_array(false,$clean,true);
            }
            if($r['phase']==='preflight')$r['phase']='claim_preflight_unknown';
            try{$this->staging->markUnknown($claim,$r);}catch(\Throwable $ignored){}
            throw new MerchantAttachmentOperationException($r);
        }
    }
    public function close(Request $request,int $productId,string $leaseId): array
    {
        $l=$this->current($request,$productId,$leaseId,'close');$ticket=$this->staging->beginClose($l);$this->again($request,$l,'close');
        $proof=$this->staging->cleanClosed($ticket);
        if($proof['cleanup_complete']===true&&$proof['held']===false){$this->again($request,$l,'close');return $this->staging->finishClose($ticket,$proof);}
        if($proof['cleanup_complete']===false){$r=self::receipt('private_close');$r['cleanup_complete']=false;throw new MerchantAttachmentOperationException($r);}
        if(($proof['lease']['state']??null)==='UNKNOWN')throw new MerchantAttachmentOperationException($this->storedReceipt($proof['lease']));
        return ['state'=>'pending','cleanup_complete'=>null];
    }
}
