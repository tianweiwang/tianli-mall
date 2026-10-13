<?php
declare(strict_types=1);
namespace app\services\marketplace;

use app\dao\marketplace\MerchantFreightDao;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\freight\MerchantFreightInput as Input;
use crmeb\services\auth\AccountChangeGuard;

final class MerchantFreightServices
{
    private const PERMISSION='freight.manage';
    public function __construct(private EmployeeAuthServices $authentication,private MerchantFreightDao $freight,private AuditLogServices $audit) {}
    private function authorize(MerchantContext $ctx):void { $ctx->require(self::PERMISSION);$this->authentication->authorizeAction($ctx,self::PERMISSION); }
    private function write(MerchantContext $ctx,callable $operation):mixed
    {
        return AccountChangeGuard::runMany('merchantapi',[],function()use($ctx,$operation):mixed {
            $this->authentication->authorizeAction($ctx,self::PERMISSION,true);return MerchantManagementInput::write($operation);
        });
    }
    private function audit(MerchantContext $ctx,int $id,string $action,array $fields):void
    {
        $this->audit->record('merchant',$ctx->employeeId,$ctx->merId,$action,'freight-template',(string)$id,'success',['changed_fields'=>$fields,'employee_id'=>$ctx->employeeId]);
    }
    private static function rootDto(array $row):array
    {
        $out=['id'=>Input::id($row['id']),'name'=>$row['name']];
        foreach (['type','appoint','no_delivery','sort','add_time'] as $key) $out[$key]=(int)$row[$key];return $out;
    }
    private function detailOwned(MerchantContext $ctx,int $id,bool $lock=false):array
    {
        $root=$this->freight->find($ctx,$id,$lock);$rules=$this->freight->rules($ctx,$id,$lock);
        $defined=Input::fromRows($root,$rules,$this->freight->cityMap());return self::rootDto($root)+$defined['body'];
    }
    public function list(MerchantContext $ctx,array $query):array
    {
        $this->authorize($ctx);Input::body($query,['name','page','limit']);
        $name=MerchantManagementInput::text($query['name']??'',255,false,true);
        $page=MerchantManagementInput::integer($query['page']??1,1,2147483647,true);$limit=MerchantManagementInput::integer($query['limit']??20,1,100,true);
        $data=$this->freight->listing($ctx,$name,$page,$limit);$data['list']=array_map([self::class,'rootDto'],$data['list']);return $data;
    }
    public function detail(MerchantContext $ctx,int $id):array { $this->authorize($ctx);Input::id($id);return $this->detailOwned($ctx,$id); }
    public function save(MerchantContext $ctx,int $id,array $body):array
    {
        $this->authorize($ctx);Input::id($id,true);
        return $this->write($ctx,function()use($ctx,$id,$body):array {
            $oldRoot=$id?$this->freight->find($ctx,$id,true):null;
            $old=$id?$this->freight->rules($ctx,$id,true):['region'=>[],'free'=>[],'no_delivery'=>[]];
            $cities=$this->freight->cityMap();$defined=Input::definition($body,$cities);
            if ($id) {
                $prior=null;
                try { $prior=Input::fromRows($oldRoot,$old,$cities); } catch (\think\exception\HttpException $error) { if ($error->getStatusCode()!==503) throw $error; }
                $latent=((int)$oldRoot['appoint']===0&&$old['free'])||((int)$oldRoot['no_delivery']===0&&$old['no_delivery']);
                if (!$latent&&$prior!==null&&Input::semantic($prior['root'],$prior['rules'])===Input::semantic($defined['root'],$defined['rules'])) return $this->detailOwned($ctx,$id,true);
                $this->freight->update($ctx,$id,$defined['root']);
            } else $id=$this->freight->create($ctx,$defined['root']);
            $this->freight->replaceRules($ctx,$id,$old,$defined['rules']);
            $actualRoot=$this->freight->find($ctx,$id,true);$actualRules=$this->freight->rules($ctx,$id,true);$actual=Input::fromRows($actualRoot,$actualRules,$cities);
            if (Input::semantic($actual['root'],$actual['rules'])!==Input::semantic($defined['root'],$defined['rules'])) Input::unavailable();
            $this->audit($ctx,$id,$oldRoot===null?'freight.create':'freight.update',['name','type','appoint','no_delivery','sort','region_info','appoint_info','no_delivery_info']);
            return self::rootDto($actualRoot)+$actual['body'];
        });
    }
    public function delete(MerchantContext $ctx,int $id):array
    {
        $this->authorize($ctx);Input::id($id);
        return $this->write($ctx,function()use($ctx,$id):array {
            $this->freight->delete($ctx,$id);$this->audit($ctx,$id,'freight.delete',['region_info','appoint_info','no_delivery_info']);return ['deleted_id'=>$id];
        });
    }
    public function cities(MerchantContext $ctx):array
    {
        $this->authorize($ctx);$map=$this->freight->cityMap();$children=[];
        foreach ($map as $id=>$row) if ($row['enabled']) $children[$row['parent_id']][]=$id;
        $build=function(int $parent)use(&$build,$map,$children):array {
            $out=[];foreach ($children[$parent]??[] as $id) { $row=$map[$id];unset($row['enabled']);$row['children']=$build($id);$out[]=$row; }return $out;
        };
        return ['list'=>$build(0)];
    }
}
