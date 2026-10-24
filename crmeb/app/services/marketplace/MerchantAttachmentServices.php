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
    public function categories(MerchantContext $ctx): array
    {
        $this->authorize($ctx);
        $rows=$this->attachments->categories($ctx);$map=[];$children=[];
        MerchantAttachmentDao::assertCategoryTree($rows);
        foreach ($rows as $row) {
            $id=Input::id($row['id']);$pid=Input::id($row['pid'],true);
            $map[$id]=['id'=>$id,'pid'=>$pid,'name'=>$row['name'],'type'=>0];
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
        $body=Input::body($body,['name','pid'],['name']);
        $name=Input::name($body['name']);$pid=Input::id($body['pid']??0,true);
        return $this->write($ctx,function()use($ctx,$categoryId,$name,$pid):array {
            $result=$this->attachments->saveCategory($ctx,$categoryId,$name,$pid);
            $row=$result['row'];
            if ($result['changed']) $this->record($ctx,$categoryId===0?'attachment.category.create':'attachment.category.update',
                'attachment-category',(int)$row['id'],['name','pid']);
            return ['id'=>(int)$row['id'],'pid'=>(int)$row['pid'],'name'=>$row['name'],'type'=>0];
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
                $rows=$this->attachments->ownedBatch($ctx,$ids);$objects=[];
                foreach ($rows as $row) {
                    $object=$this->storage->locate($row,$ctx);
                    $objects[$this->storage->identity($object)]=$object;
                }
                $this->attachments->assertUnreferenced($ids,$this->storage->aliases($objects));
                foreach ($objects as $object) $this->storage->remove($object,$receipt);
                $receipt['physical_complete']=true;
                $receipt['phase']='database_delete';$receipt['database_committed']=null;
                $this->attachments->remove($ctx,$ids);
                $receipt['phase']='audit';
                foreach ($ids as $id) $this->record($ctx,'attachment.delete','attachment',$id,['att_dir','satt_dir']);
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
        $result['list']=array_map([self::class,'dto'],$result['list']);return $result;
    }
}
