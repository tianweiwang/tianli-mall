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
        $query=Input::body($query,['pid','real_name','page','limit']);
        $pid=MerchantAttachmentInput::id($query['pid']??0,true);
        $name=Input::text($query['real_name']??'',255);
        $page=Input::integer($query['page']??1,1,2147483647,true);
        $limit=Input::integer($query['limit']??18,1,100,true);
        return $this->within($request,$productIdOrZero,function()use($productIdOrZero,$pid,$name,$page,$limit):array{
            $result=$this->attachments->listing($productIdOrZero,$pid,$name,$page,$limit);
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
                $this->request($request,'legacy-product-media-online-upload');
                $pdo=LegacyProductWriteServices::transaction();$owner=LegacyProductWriteServices::mediaOwner($productIdOrZero);
                if($pid)$this->attachments->category($productIdOrZero,$pid);$data=[];
                // No attachment INSERT is attempted until every requested physical object has completed.
                foreach($images as$index=>$image){
                    $this->request($request,'legacy-product-media-online-upload');$receipt['phase']='physical_upload';
                    $data[$index]=$this->storage->uploadBytesForProduct($productIdOrZero,$image['bytes'],$image['display_name'],$objects[$index],$observations[$index]);
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner
                        ||!in_array($observations[$index]['physical_changed'],[true,null],true)||$observations[$index]['physical_complete']!==true
                        ||$observations[$index]['cleanup_complete']===false)throw new AuthUnavailableException();
                }
                $receipt['physical_complete']=true;$list=[];
                foreach($data as$index=>$fields){
                    $this->request($request,'legacy-product-media-online-upload');
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                    $now=time();Input::integer($now,1,2147483647);
                    $receipt['phase']='database_insert';$receipt['database_committed']=null;
                    $row=$this->attachments->add($productIdOrZero,$fields+['pid'=>$pid,'time'=>$now,'module_type'=>1,'type'=>0,'scan_token'=>'']);
                    $located=$this->storage->locateForProduct($row,$productIdOrZero);
                    if($this->storage->identity($located)!==$this->storage->identity($objects[$index]))throw new AuthUnavailableException();
                    $receipt['phase']='audit';$this->record($request,$productIdOrZero,'attachment',(int)$row['att_id'],['name','att_dir','satt_dir','pid']);
                    if(LegacyProductWriteServices::transaction()!==$pdo||LegacyProductWriteServices::mediaOwner($productIdOrZero)!==$owner)throw new AuthUnavailableException();
                    $list[]=['att_id'=>(int)$row['att_id'],'src'=>$row['att_dir']];
                }
                $receipt['phase']='commit_observation';return $list;
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
        $all=Input::integer($query['all']??0,0,1,true)===1;Input::integer($query['type']??0,0,0,true);
        return $this->within($request,$productIdOrZero,function()use($productIdOrZero,$name,$pid,$all):array{
            $observed=$this->attachments->categoryListing($productIdOrZero,$pid,$name,$all);$rows=$observed['rows'];$children=[];
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
        $name=Input::text($body['name'],50,true);$pid=self::categoryPid($body['pid']??0);Input::integer($body['type']??0,0,0,true);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id,$name,$pid):void{
            $result=$this->attachments->saveCategory($productIdOrZero,$id,$name,$pid);
            if($result['changed'])$this->record($request,$productIdOrZero,'attachment-category',(int)$result['row']['id'],['name','pid']);
        });
    }
    public function deleteCategory(Request $request,int $productIdOrZero,int $id): void
    {
        $this->request($request,'legacy-product-media-categories-delete');MerchantAttachmentInput::id($id);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id):void{
            $result=$this->attachments->deleteCategory($productIdOrZero,$id);
            foreach($result['moved_ids']as$moved)$this->record($request,$productIdOrZero,'attachment',$moved,['pid']);
            $this->record($request,$productIdOrZero,'attachment-category',$id,['name','pid']);
        });
    }
    public function rename(Request $request,int $productIdOrZero,int $id,array $body): void
    {
        $this->request($request,'legacy-product-media-rename');MerchantAttachmentInput::id($id);
        $body=Input::body($body,['real_name'],['real_name']);$name=Input::text($body['real_name'],255,true);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$id,$name):void{
            $result=$this->attachments->rename($productIdOrZero,$id,$name);
            if($result['changed'])$this->record($request,$productIdOrZero,'attachment',$id,['real_name']);
        });
    }
    public function move(Request $request,int $productIdOrZero,array $body): void
    {
        $this->request($request,'legacy-product-media-move');$body=Input::body($body,['images','pid'],['images','pid']);
        $ids=self::selectedIds($body['images'],false);$pid=self::categoryPid($body['pid']);
        $this->within($request,$productIdOrZero,function()use($request,$productIdOrZero,$ids,$pid):void{
            $changed=$this->attachments->move($productIdOrZero,$ids,$pid);
            foreach($changed as$id)$this->record($request,$productIdOrZero,'attachment',$id,['pid']);
        });
    }
    public function delete(Request $request,int $productIdOrZero,array $body): void
    {
        $this->request($request,'legacy-product-media-delete');$body=Input::body($body,['ids'],['ids']);
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
}
