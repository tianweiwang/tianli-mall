<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use app\Request;
use app\dao\marketplace\assets\CustomerLegacyRefundAssetDao as Assets;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\file\UploadedFile;
use Throwable;

/** Named upload and original-transaction hooks; no request identity is cached between calls. */
final class CustomerLegacyRefundAssetServices
{
    public function __construct(private Assets $assets){}

    public function upload(Request $request,int $uid,string $purpose,int $contextId):array
    {
        $receipt=self::receipt();$object=[];$storage=null;
        try{
            $identity=self::identity($request,$uid);Assets::purpose($purpose);Assets::integer($contextId,1,2147483647);
            if($request->method(true)!=='POST'||$request->rule()->getOption('customer_legacy_refund_upload')!==$purpose
                ||strtolower($request->contentType())!=='multipart/form-data'||$request->get(false)!==[]
                ||$request->server('QUERY_STRING','')!==''||$request->post(false)!==['filename'=>'pics'])self::fail();
            $routeKey=$purpose==='legacy_refund_evidence'?'order_id':'refund_id';
            if(Assets::integer($request->route($routeKey,null,''),1,2147483647)!==$contextId)self::fail();
            $files=$request->file();if(!is_array($files)||array_keys($files)!==['pics']||!$files['pics'] instanceof UploadedFile)self::fail();
            [$connection,$pdo]=$this->assets->primary();$session=self::session($pdo);$entered=false;
            $out=$connection->transaction(function(ConnectionInterface $actual)use($connection,$pdo,$request,$uid,$purpose,$contextId,$identity,$session,$files,
                &$entered,&$receipt,&$object,&$storage):array{
                if($actual!==$connection||$entered)self::fail();$entered=true;$this->assets->native($connection,$pdo,1);
                $user=$this->assets->user($connection,$pdo,$uid,1);$this->assets->assertStructure($connection,$pdo);
                $context=$this->uploadContext($connection,$pdo,$uid,$purpose,$contextId,1);
                $this->sameUpload($connection,$pdo,$request,$uid,$identity,$session,$user,$purpose,$contextId,$context,1);
                if(Assets::integer(CacheService::get('start_uploads_'.$uid,0),0,100)>=100)self::fail();
                // All real identity/parent/stage gates above precede reading PHP's temporary upload bytes.
                [$bytes,$name]=self::uploadedBytes($files['pics']);$storage=$this->storage();
                $rootId=Assets::integer($context['family']['root']['id'],1,2147483647);
                $refundId=$purpose==='legacy_refund_evidence'?null:$contextId;
                $physical=$storage->writeNew($uid,$purpose,$rootId,$refundId,$bytes,$name,$object,$receipt);
                if($receipt['physical_changed']!==true||$receipt['physical_complete']!==true||$storage->readObject($physical)!==$bytes)self::fail();
                $this->sameUpload($connection,$pdo,$request,$uid,$identity,$session,$user,$purpose,$contextId,$context,1);
                $receipt['phase']='metadata_insert';$receipt['database_committed']=null;
                $asset=$this->assets->insert($connection,$pdo,$uid,$purpose,$rootId,$refundId,$physical,$name);
                if($storage->readObject(self::physical($asset))!==$bytes)self::fail();
                $this->sameUpload($connection,$pdo,$request,$uid,$identity,$session,$user,$purpose,$contextId,$context,1);
                $receipt['phase']='commit_outcome_unknown';return ['asset'=>$asset,'bytes'=>$bytes,'user'=>$user,'context'=>$context];
            });
            if(!$entered||!$storage instanceof CustomerLegacyRefundPrivateStorage)self::fail();
            $this->sameUpload($connection,$pdo,$request,$uid,$identity,$session,$out['user'],$purpose,$contextId,$out['context'],0);
            if($this->assets->asset($connection,$pdo,(string)$out['asset']['id'],$uid,0)!==$out['asset']
                ||$storage->readObject(self::physical($out['asset']))!==$out['bytes'])self::fail();
            $receipt['database_committed']=true;$receipt['phase']='quota_update';
            $key='start_uploads_'.$uid;$count=Assets::integer(CacheService::get($key,0),0,100);if($count>=100)self::fail();$count++;
            if(CacheService::set($key,$count,86400)!==true||Assets::integer(CacheService::get($key,null),0,100)!==$count
                ||self::identity($request,$uid)!==$identity)self::fail();
            $id=Assets::assetId((string)$out['asset']['id']);$receipt['complete']=true;$receipt['phase']='complete';
            return ['name'=>$out['asset']['name'],'attachment_ref'=>Assets::ref($purpose,$id),
                'asset_id'=>$id,'media_type'=>$out['asset']['media_type'],'byte_size'=>strlen($out['bytes']),
                'width'=>Assets::integer($out['asset']['width'],1,4294967295),'height'=>Assets::integer($out['asset']['height'],1,4294967295)];
        }catch(Throwable $error){
            if($receipt['database_committed']===false&&$object!==[]&&$storage instanceof CustomerLegacyRefundPrivateStorage)
                try{$storage->cleanupNew($object,$receipt);}catch(Throwable $ignored){$receipt['cleanup_complete']=false;}
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    /** Called before the original status/save on the original transaction, only for selected private refs. */
    public function prepareApplication(ConnectionInterface $connection,int $uid,int $orderId,array $refundData,array $originalCart):?array
    {
        $refs=Assets::images($refundData['refund_img']??null);
        if(!array_filter($refs,fn(string $ref):bool=>Assets::isPrivate($ref)))return null;
        $request=app()->request;$identity=self::identity($request,$uid);
        if($request->method(true)!=='POST'||$request->rule()->getOption('customer_legacy_refund_application')!==true
            ||Assets::integer($request->route('id',null,''),1,2147483647)!==$orderId)self::fail();
        $pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();$this->assets->native($connection,$pdo,1);
        $user=$this->assets->user($connection,$pdo,$uid,1);$eligible=$this->assets->application($connection,$pdo,$orderId,$uid,1);
        $lockedCart=array_column($eligible['cart'],null,'id');
        if(count($lockedCart)!==count($originalCart))self::fail();
        foreach($originalCart as$row){
            $actual=$lockedCart[$row['id']??'']??null;if(!is_array($actual))self::fail();
            foreach(['id','cart_id','cart_num','refund_num']as$key)if((string)$actual[$key]!== (string)$row[$key])self::fail();
            try{$cartInfo=json_decode($actual['cart_info'],true,512,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
            $original=$row['cart_info'];
            if(is_string($original))try{$original=json_decode($original,true,512,JSON_THROW_ON_ERROR);}catch(Throwable $error){self::fail();}
            if($cartInfo!==$original)self::fail();
        }
        $this->assets->assertStructure($connection,$pdo);$rootId=Assets::integer($eligible['family']['root']['id'],1,2147483647);
        $selected=$this->assets->selections($connection,$pdo,$uid,$rootId,null,'legacy_refund_evidence',$refs);
        $storage=$this->storage();$bytes=[];foreach($selected as$id=>$row)$bytes[$id]=$storage->readObject(self::physical($row));
        return ['connection'=>$connection,'pdo'=>$pdo,'request'=>$request,'uid'=>$uid,'identity'=>$identity,'session'=>self::session($pdo),
            'user'=>$user,'family'=>$eligible['family'],'refs'=>$refs,'selected'=>$selected,'bytes'=>$bytes,'storage'=>$storage];
    }

    public function bindApplication(array $context,int $actualRefundId):array
    {
        $this->writerIdentity($context,1);$connection=$context['connection'];$pdo=$context['pdo'];$uid=$context['uid'];
        $saved=$this->assets->refund($connection,$pdo,$actualRefundId,$uid,1);
        if($saved['family']!==$context['family']||Assets::images($saved['refund']['refund_img'])!==$context['refs'])self::fail();
        $bound=$this->assets->bind($connection,$pdo,$uid,$saved['refund'],$context['selected']);
        foreach($bound as$id=>$row)if($context['storage']->readObject(self::physical($row))!==$context['bytes'][$id])self::fail();
        $this->writerIdentity($context,1);return $context+['saved'=>$saved['refund'],'bound'=>$bound];
    }

    /** Actual post-commit readback; unknown completion is never converted into a safe replay. */
    public function committed(array $context):void
    {
        $receipt=self::receipt();$receipt['phase']='commit_outcome_unknown';$receipt['database_committed']=null;
        try{
            $this->writerIdentity($context,0);$saved=$this->assets->refund($context['connection'],$context['pdo'],
                Assets::integer($context['saved']['id'],1,2147483647),$context['uid'],0);
            if($saved['refund']!==$context['saved'])self::fail();
            foreach($context['bound']as$id=>$row)if($this->assets->asset($context['connection'],$context['pdo'],(string)$id,$context['uid'],0)!==$row
                ||$context['storage']->readObject(self::physical($row))!==$context['bytes'][$id])self::fail();
            if(isset($context['note'])&&$this->assets->note($context['connection'],$context['pdo'],$saved['refund'],0)!==$context['note'])self::fail();
        }catch(Throwable $error){throw new MerchantAttachmentOperationException($receipt);}
    }

    /** Always required for the registered legacy express writer, including old URL/zero-picture clients. */
    public function prepareShipping(ConnectionInterface $connection,int $uid,array $data):array
    {
        $request=app()->request;$identity=self::identity($request,$uid);
        if($request->method(true)!=='POST'||$request->rule()->getOption('customer_legacy_refund_shipping')!==true)self::fail();
        $id=Assets::integer($data['id']??null,1,2147483647);$pdo=$connection->getPdo();if(!$pdo instanceof PDO)self::fail();
        $this->assets->native($connection,$pdo,1);$user=$this->assets->user($connection,$pdo,$uid,1);
        $saved=$this->assets->refund($connection,$pdo,$id,$uid,1);$refund=$saved['refund'];$stage=Assets::integer($refund['refund_type'],0,6);
        if(!in_array($stage,[4,5],true))self::fail();
        foreach(['refund_express'=>100,'refund_phone'=>32,'refund_express_name'=>255]as$key=>$max){
            $value=$data[$key]??null;if(!is_string($value)||str_contains($value,"\0")||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>$max)self::fail();
        }
        $note=Assets::shippingNote(array_key_exists('refund_shipping_note',$data)?$data['refund_shipping_note']:($data['refund_explain']??''));
        if($note!=='')Assets::noteMessage($id,$note);
        $before=Assets::images($refund['refund_img']);$submitted=Assets::csv($data['refund_img']??'');$merge=Assets::merge($before,$submitted);
        $rootId=Assets::integer($saved['family']['root']['id'],1,2147483647);$bound=[];$bytes=[];$storage=null;
        foreach($before as$ref){
            if(!Assets::isPrivate($ref))continue;[$purpose,$assetId]=Assets::reference($ref);
            $row=$this->assets->asset($connection,$pdo,$assetId,$uid,1);
            if($row['purpose']!==$purpose||$row['state']!=='BOUND'||Assets::integer($row['root_order_id'],1,2147483647)!==$rootId
                ||Assets::integer($row['refund_id'],1,2147483647)!==$id)self::fail();$bound[$assetId]=$row;
        }
        $shipping=[];
        foreach($submitted as$ref){
            if(!Assets::isPrivate($ref))continue;[$purpose,$assetId]=Assets::reference($ref);
            if($purpose==='legacy_refund_evidence'){
                if(!in_array($ref,$before,true)||!isset($bound[$assetId]))self::fail();
            }else $shipping[]=$ref;
        }
        if($bound!==[]||$shipping!==[]){$this->assets->assertStructure($connection,$pdo);$storage=$this->storage();}
        $selected=$this->assets->selections($connection,$pdo,$uid,$rootId,$id,'refund_shipping_proof',$shipping,$stage===5);
        foreach($bound+$selected as$assetId=>$row)$bytes[$assetId]=$storage->readObject(self::physical($row));
        $encoded=json_encode($merge,JSON_THROW_ON_ERROR);$confirmation=$stage===5;
        if($confirmation){
            if($merge!==$before||$this->assets->note($connection,$pdo,$refund,1)!==$note)self::fail();
            foreach(['refund_express','refund_phone','refund_express_name']as$key)if($refund[$key]!==$data[$key])self::fail();
        }elseif($this->assets->note($connection,$pdo,$refund,1)!=='')self::fail();
        $write=['refund_express'=>$data['refund_express'],'refund_phone'=>$data['refund_phone'],'refund_express_name'=>$data['refund_express_name'],
            'refund_img'=>$encoded,'refund_type'=>is_string($refund['refund_type'])?'5':5];
        return ['connection'=>$connection,'pdo'=>$pdo,'request'=>$request,'uid'=>$uid,'identity'=>$identity,'session'=>self::session($pdo),
            'user'=>$user,'family'=>$saved['family'],'prior'=>$refund,'note'=>$note,'write'=>$write,'confirmation'=>$confirmation,
            'selected'=>$selected,'bound'=>$bound,'bytes'=>$bytes,'storage'=>$storage];
    }

    public function finishShipping(array $context):array
    {
        $this->writerIdentity($context,1);$connection=$context['connection'];$pdo=$context['pdo'];$uid=$context['uid'];
        $saved=$this->assets->refund($connection,$pdo,Assets::integer($context['prior']['id'],1,2147483647),$uid,1);
        $expected=$context['confirmation']?$context['prior']:array_replace($context['prior'],$context['write']);
        if($saved['refund']!==$expected||$saved['family']!==$context['family']
            ||$this->assets->note($connection,$pdo,$saved['refund'],1)!==$context['note'])self::fail();
        $bound=$context['bound'];
        if(!$context['confirmation'])$bound+=$this->assets->bind($connection,$pdo,$uid,$saved['refund'],$context['selected']);
        foreach($bound as$id=>$row)if($context['storage']->readObject(self::physical($row))!==$context['bytes'][$id])self::fail();
        $context['bound']=$bound;$context['saved']=$saved['refund'];$this->writerIdentity($context,1);return $context;
    }

    /** Only call after the original consumer has authorized this saved refund. No filesystem locator in DTO. */
    public function project(array $data):array
    {
        $id=Assets::integer($data['id']??null,1,2147483647);[$connection,$pdo]=$this->assets->primary();
        $saved=$this->assets->refund($connection,$pdo,$id,null,0,false);$refund=$saved['refund'];$uid=Assets::integer($refund['uid'],1,2147483647);
        foreach(['id','uid','store_order_id','order_id']as$key)if(!array_key_exists($key,$data)||(string)$data[$key]!== (string)$refund[$key])self::fail();
        $rootId=Assets::integer($saved['family']['root']['id'],1,2147483647);$legacy=[];$groups=['schema'=>1,'application'=>[],'shipping'=>[]];
        foreach(Assets::images($refund['refund_img'])as$ref){
            if(!Assets::isPrivate($ref)){$legacy[]=$ref;continue;}[$purpose,$assetId]=Assets::reference($ref);
            $row=$this->assets->asset($connection,$pdo,$assetId,$uid,0);
            if($row['purpose']!==$purpose||$row['state']!=='BOUND'||Assets::integer($row['root_order_id'],1,2147483647)!==$rootId
                ||Assets::integer($row['refund_id'],1,2147483647)!==$id)self::fail();
            $group=$purpose==='legacy_refund_evidence'?'application':'shipping';
            $groups[$group][]=['attachment_ref'=>$ref,'asset_id'=>$assetId,'media_type'=>$row['media_type'],
                'byte_size'=>Assets::integer($row['byte_size'],1,52428800),'width'=>Assets::integer($row['width'],1,4294967295),'height'=>Assets::integer($row['height'],1,4294967295)];
        }
        $data['refund_img']=$legacy;$data['refund_materials']=$groups;$data['refund_shipping_note']=$this->assets->note($connection,$pdo,$refund,0);return $data;
    }

    private function uploadContext(ConnectionInterface $connection,PDO $pdo,int $uid,string $purpose,int $id,int $depth):array
    {
        if($purpose==='legacy_refund_evidence')return $this->assets->application($connection,$pdo,$id,$uid,$depth);
        $context=$this->assets->refund($connection,$pdo,$id,$uid,$depth);
        if(Assets::integer($context['refund']['refund_type'],0,6)!==4)self::fail();return $context;
    }
    private function sameUpload(ConnectionInterface $connection,PDO $pdo,Request $request,int $uid,string $identity,array $session,array $user,
        string $purpose,int $id,array $context,int $depth):void
    {
        $this->assets->native($connection,$pdo,$depth);
        if(self::identity($request,$uid)!==$identity||self::session($pdo)!==$session||$this->assets->user($connection,$pdo,$uid,$depth)!==$user
            ||$this->uploadContext($connection,$pdo,$uid,$purpose,$id,$depth)!==$context)self::fail();
    }
    private function writerIdentity(array $context,int $depth):void
    {
        $this->assets->native($context['connection'],$context['pdo'],$depth);
        if(self::identity($context['request'],$context['uid'])!==$context['identity']||self::session($context['pdo'])!==$context['session']
            ||$this->assets->user($context['connection'],$context['pdo'],$context['uid'],$depth)!==$context['user'])self::fail();
    }
    public static function identity(Request $request,int $uid):string
    {
        if(PHP_INT_SIZE<8||$uid<1||$uid>2147483647||app()->request!==$request||app()->http->getName()!=='api'
            ||$request->isLogin()!==true||!in_array($request->uid(),[$uid,(string)$uid],true))self::fail();
        $fingerprint=$request->verifiedTokenFingerprint();if(!is_string($fingerprint)||preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)!==1)self::fail();return $fingerprint;
    }
    public static function physical(array $asset):array
    {
        return ['uid'=>Assets::integer($asset['uid'],1,2147483647),'object_key'=>$asset['object_key'],'media_type'=>$asset['media_type'],
            'byte_size'=>Assets::integer($asset['byte_size'],1,52428800),'width'=>Assets::integer($asset['width'],1,4294967295),
            'height'=>Assets::integer($asset['height'],1,4294967295),'content_sha256'=>$asset['content_sha256']];
    }
    private function storage():CustomerLegacyRefundPrivateStorage {return app()->make(CustomerLegacyRefundPrivateStorage::class);}
    private static function session(PDO $pdo):array
    {
        $s=$pdo->prepare('SELECT DATABASE() AS database_name,CURRENT_USER() AS authenticated_user,CONNECTION_ID() AS connection_id,@@session.autocommit AS autocommit');
        if(!$s instanceof PDOStatement)self::fail();try{if($s->execute([])!==true)self::fail();$rows=$s->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($s->closeCursor()!==true)self::fail();}
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
            $opened=fstat($handle);foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)if(!is_array($opened)||$opened[$key]!==$before[$key])self::fail();
            $bytes='';while(strlen($bytes)<$before['size']){$part=fread($handle,min(65536,$before['size']-strlen($bytes)));if(!is_string($part)||$part==='')self::fail();$bytes.=$part;}
            if(fread($handle,1)!==''||!feof($handle))self::fail();$after=fstat($handle);clearstatcache(true,$path);$atPath=lstat($path);
            foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
                if(!is_array($after)||!is_array($atPath)||is_link($path)||$after[$key]!==$before[$key]||$atPath[$key]!==$before[$key])self::fail();
            $name=$file->getOriginalName();if(!is_string($name)||$name==='')self::fail();return [$bytes,$name];
        }finally{if(fclose($handle)!==true)self::fail();}
    }
    private static function receipt():array {return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,'database_committed'=>false,'cleanup_complete'=>null];}
    private static function fail():never {throw new AuthUnavailableException();}
}
