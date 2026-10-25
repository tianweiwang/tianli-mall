<?php
declare(strict_types=1);
namespace app\services\marketplace;

use app\dao\marketplace\MerchantAttachmentDao;
use app\services\marketplace\attachment\MerchantAttachmentInput as Input;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\attachment\MerchantAttachmentStorage;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\AuthException;
use think\exception\HttpException;
use crmeb\services\auth\AccountChangeGuard;

final class MerchantAttachmentServices
{
    private const PERMISSION='attachment.manage';
    public function __construct(private EmployeeAuthServices $authentication,
        private MerchantAttachmentDao $attachments, private MerchantAttachmentStorage $storage,
        private AuditLogServices $audit) {}
    private function authorize(MerchantContext $ctx): void
    {
        $ctx->require(self::PERMISSION);
        $this->authentication->authorizeAction($ctx,self::PERMISSION);
    }
    private function write(MerchantContext $ctx, callable $operation): mixed
    {
        return AccountChangeGuard::runMany('merchantapi',[],function()use($ctx,$operation):mixed {
            $this->authentication->authorizeAction($ctx,self::PERMISSION,true);
            return MerchantManagementInput::write($operation);
        });
    }
    private static function receipt(): array
    {
        return ['complete'=>false,'phase'=>'preflight','physical_changed'=>false,'physical_complete'=>false,
            'database_committed'=>false,'cleanup_complete'=>null];
    }
    private function record(MerchantContext $ctx,string $action,string $resource,int $id,array $fields): void
    {
        $this->audit->record('merchant',$ctx->employeeId,$ctx->merId,$action,$resource,(string)$id,
            'success',['changed_fields'=>$fields,'employee_id'=>$ctx->employeeId]);
    }
    private static function dto(array $row): array
    {
        $data=[];
        foreach (['att_id','pid','real_name','att_dir','satt_dir','att_size','att_type','time','type'] as $key) $data[$key]=$row[$key];
        foreach (['att_id','pid','time','type'] as $key) $data[$key]=(int)$data[$key];
        $data['url']=set_file_url($data['att_dir']);
        $data['thumbnail_url']=set_file_url($data['satt_dir']);
        return $data;
    }
    public function list(MerchantContext $ctx,int $categoryId,int $page,int $limit): array
    {
        $this->authorize($ctx);
        Input::id($categoryId,true);MerchantManagementInput::integer($page,1,2147483647);
        MerchantManagementInput::integer($limit,1,100);
        $result=$this->attachments->listing($ctx,$categoryId,$page,$limit);
        $result['list']=array_map([self::class,'dto'],$result['list']);
        return $result;
    }
    public function categories(MerchantContext $ctx,int $type=0): array
    {
        $this->authorize($ctx);MerchantManagementInput::integer($type,0,1);
        $rows=$this->attachments->categories($ctx,false,$type);$map=[];$children=[];
        MerchantAttachmentDao::assertCategoryTree($rows);
        foreach ($rows as $row) {
            $id=Input::id($row['id']);$pid=Input::id($row['pid'],true);
            $map[$id]=['id'=>$id,'pid'=>$pid,'name'=>$row['name'],'type'=>$type];
            $children[$pid][]=$id;
        }
        $build=function(int $parent)use(&$build,$map,$children):array {
            $data=[];
            foreach ($children[$parent]??[] as $id) { $row=$map[$id];$row['children']=$build($id);$data[]=$row; }
            return $data;
        };
        return ['list'=>$build(0)];
    }
    public function saveCategory(MerchantContext $ctx,int $categoryId,array $body): array
    {
        $this->authorize($ctx);Input::id($categoryId,true);
        $body=Input::body($body,['name','pid','type'],['name']);$type=array_key_exists('type',$body)?MerchantManagementInput::integer($body['type'],0,1,true):null;
        $name=Input::name($body['name']);$pid=Input::id($body['pid']??0,true);
        return $this->write($ctx,function()use($ctx,$categoryId,$name,$pid,$type):array {
            $result=$this->attachments->saveCategory($ctx,$categoryId,$name,$pid,$type);
            $row=$result['row'];
            if ($result['changed']) $this->record($ctx,$categoryId===0?'attachment.category.create':'attachment.category.update',
                'attachment-category',(int)$row['id'],['name','pid']);
            return ['id'=>(int)$row['id'],'pid'=>(int)$row['pid'],'name'=>$row['name'],'type'=>(int)$row['type']];
        });
    }
    public function upload(MerchantContext $ctx,array $body): array
    {
        $this->authorize($ctx);$body=Input::body($body,['category_id']);$categoryId=Input::id($body['category_id']??0,true);
        $receipt=self::receipt();$object=[];
        try {
            $data=$this->write($ctx,function()use($ctx,$categoryId,&$receipt,&$object):array {
                if ($categoryId) $this->attachments->category($ctx,$categoryId,true);
                $data=$this->storage->upload($ctx,$object,$receipt);
                $now=time();MerchantManagementInput::integer($now,1,2147483647);
                $receipt['phase']='database_insert';$receipt['database_committed']=null;
                $row=$this->attachments->add($ctx,$data+['pid'=>$categoryId,'time'=>$now,'module_type'=>1,'type'=>0,'scan_token'=>'']);
                $receipt['phase']='audit';
                $this->record($ctx,'attachment.upload','attachment',(int)$row['att_id'],['name','att_dir','satt_dir','pid']);
                $result=self::dto($row);$receipt['phase']='commit_observation';
                return $result;
            });
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';
            return $data;
        } catch (\Throwable $error) {
            if ($receipt['physical_changed']===false && $receipt['database_committed']===false
                && (($error instanceof HttpException && in_array($error->getStatusCode(),[401,403,404,422],true))
                    || ($error instanceof AuthException && !$error instanceof AuthUnavailableException))) throw $error;
            if ($receipt['phase']==='preflight') $receipt['phase']='preflight_unknown';
            if ($receipt['database_committed']===false && $object) {
                try { $receipt['cleanup_complete']=$this->storage->cleanupNew($object,$receipt); }
                catch (\Throwable $ignored) { $receipt['cleanup_complete']=false; }
            }
            throw new MerchantAttachmentOperationException($receipt);
        }
    }
    public function delete(MerchantContext $ctx,array $ids): array
    {
        $this->authorize($ctx);$ids=Input::ids($ids);$receipt=self::receipt();
        try {
            $this->write($ctx,function()use($ctx,$ids,&$receipt):void {
                $state=$this->attachments->removalContext($ctx);$batch=$this->attachments->mediaForRemoval($ctx,$ids);$objects=[];
                $this->attachments->assertRemovalMappingsAbsent($ctx,$ids);
                // Every selected native row and every real variant/configured alias is fully qualified
                // before the first physical change. A shared object is removed only once.
                foreach($batch as$entry){$row=$entry['row'];$kind=$entry['kind'];
                    if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                    $object=$this->storage->locate($row,$ctx);$identity=$this->storage->identity($object);
                    $canonical=$object['type']===1?array_column($object['files'],'canonical'):$object['canonical'];
                    if(!$canonical||!in_array($row['att_dir'],$canonical,true))throw new AuthUnavailableException();
                    foreach($canonical as$selected){
                        $proof=$this->storage->assertTypedReference($row,$object,$kind,$selected);
                        if(($proof['resolved_kind']??null)!==$kind||($proof['identity']??null)!==$identity
                            ||($proof['native14_sha256']??null)!==hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))
                            ||!is_int($proof['original']['bytes']??null)||(string)$proof['original']['bytes']!==$row['att_size']
                            ||!is_int($proof['selected']['bytes']??null)||$proof['selected']['bytes']<1
                            ||($proof['selected']['canonical']??null)!==$selected
                            ||preg_match('/\A[a-f0-9]{64}\z/D',$proof['original']['sha256']??'')!==1
                            ||preg_match('/\A[a-f0-9]{64}\z/D',$proof['selected']['sha256']??'')!==1)throw new AuthUnavailableException();
                        if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                    }
                    if(isset($objects[$identity])){
                        $merged=$objects[$identity];$leftIdentity=$merged;$rightIdentity=$object;
                        unset($leftIdentity['files'],$leftIdentity['canonical'],$rightIdentity['files'],$rightIdentity['canonical']);
                        if($leftIdentity!==$rightIdentity)throw new AuthUnavailableException();
                        $files=[];$pathIndexes=[];$canonicalPaths=[];
                        foreach(array_merge($merged['files'],$object['files']) as$file){
                            if(!is_array($file)||!is_string($file['path']??null)||!is_string($file['canonical']??null)
                                ||!is_array($file['stat']??null))throw new AuthUnavailableException();
                            $path=$file['path'];$canonical=$file['canonical'];
                            if(isset($canonicalPaths[$canonical])&&$canonicalPaths[$canonical]!==$path)throw new AuthUnavailableException();
                            $canonicalPaths[$canonical]=$path;
                            if(isset($pathIndexes[$path])){
                                $left=$files[$pathIndexes[$path]];$right=$file;
                                // Full shared-path facts must agree; only content reads may advance atime.
                                unset($left['stat'][8],$left['stat']['atime'],$right['stat'][8],$right['stat']['atime']);
                                if($left!==$right)throw new AuthUnavailableException();
                            }else{$pathIndexes[$path]=count($files);$files[]=$file;}
                        }
                        $merged['files']=$files;
                        $merged['canonical']=array_values(array_unique(array_merge($merged['canonical'],$object['canonical']),SORT_STRING));
                        $objects[$identity]=$merged;
                    }else{$objects[$identity]=$object;}
                }
                if($this->attachments->mediaForRemoval($ctx,$ids)!==$batch)throw new AuthUnavailableException();
                $this->attachments->assertUnreferenced($ids,$this->storage->aliases($objects));
                $this->authentication->authorizeAction($ctx,self::PERMISSION,true);
                if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                foreach($objects as$object){
                    if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                    $this->storage->remove($object,$receipt);
                    if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                }
                $receipt['physical_complete']=true;
                $receipt['phase']='database_delete';$receipt['database_committed']=null;
                $this->authentication->authorizeAction($ctx,self::PERMISSION,true);
                if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                $this->attachments->removeMedia($ctx,$ids,$batch);
                $receipt['phase']='audit';
                foreach($ids as$id)$this->record($ctx,'attachment.delete','attachment',$id,['att_dir','satt_dir']);
                if($this->attachments->removalContext($ctx)!==$state)throw new AuthUnavailableException();
                $receipt['phase']='commit_observation';
            });
            $receipt['database_committed']=true;$receipt['complete']=true;$receipt['phase']='complete';
            return ['deleted_ids'=>$ids];
        } catch (\Throwable $error) {
            if ($receipt['physical_changed']===false && $receipt['database_committed']===false
                && (($error instanceof HttpException && in_array($error->getStatusCode(),[401,403,404,422],true))
                    || ($error instanceof AuthException && !$error instanceof AuthUnavailableException))) throw $error;
            if ($receipt['phase']==='preflight') $receipt['phase']='preflight_unknown';
            throw new MerchantAttachmentOperationException($receipt);
        }
    }

    public function listMedia(MerchantContext $ctx,string $kind,int $categoryId,int $page,int $limit): array
    {
        $this->authorize($ctx);Input::id($categoryId,true);
        MerchantManagementInput::integer($page,1,2147483647);MerchantManagementInput::integer($limit,1,100);
        if(!in_array($kind,['video','audio'],true))Input::invalid();
        $result=$this->attachments->listingMedia($ctx,$kind,$categoryId,$page,$limit);
        $result['list']=array_map([self::class,'dto'],$result['list']);
        if($kind==='video')$result['video_chunk_upload']=\app\services\marketplace\product\ProductVideoUploadSessions::enabled();
        return $result;
    }

    public function deleteCategory(MerchantContext $ctx,int $id,array $selector=[]): array
    {
        $this->authorize($ctx);Input::id($id);$selector=Input::body($selector,['type']);
        $type=array_key_exists('type',$selector)?MerchantManagementInput::integer($selector['type'],0,1,true):null;
        return $this->write($ctx,function()use($ctx,$id,$type):array{
            $result=$this->attachments->deleteVideoCategory($ctx,$id,$type);
            foreach($result['moved_ids']as$moved)$this->record($ctx,'attachment.move','attachment',$moved,['pid']);
            $this->record($ctx,'attachment.category.delete','attachment-category',$id,['name','pid']);return [];
        });
    }
}
