<?php
declare(strict_types=1);
namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\model\shipping\ShippingTemplates;
use app\model\shipping\SystemCity;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\freight\MerchantFreightInput as Input;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use PDO;
use think\db\Query;
use think\facade\Db;

final class MerchantFreightDao
{
    public const ROOT_FIELDS=['id','mer_id','name','type','appoint','no_delivery','sort','add_time'];
    private const CHILD_FIELDS=['region'=>['id','temp_id','province_id','city_id','first','first_price','continue','continue_price','type','uniqid'],
        'free'=>['id','temp_id','province_id','city_id','number','price','type','uniqid'],
        'no_delivery'=>['id','temp_id','province_id','city_id','uniqid']];
    private static function tx():void
    {
        $pdo=Db::connect()->getPdo();if (!$pdo instanceof PDO||!$pdo->inTransaction()) throw new AuthUnavailableException();
    }
    private function root(MerchantContext $ctx,?int $id=null):Query
    {
        $q=(new ShippingTemplates())->db();if ($id!==null) $q->where('id',$id);return MerchantScope::apply($q,$ctx);
    }
    public function find(MerchantContext $ctx,int $id,bool $lock=false):array
    {
        if ($lock) self::tx();$row=$this->root($ctx,$id)->field(self::ROOT_FIELDS)->lock($lock)->find();
        if ($row===null) throw new MerchantNotFoundException();$data=$row->getData();MerchantScope::assertOwned($data,$ctx);return $data;
    }
    public function listing(MerchantContext $ctx,string $name,int $page,int $limit):array
    {
        $q=(new ShippingTemplates())->db();if ($name!=='') $q->whereLike('name','%'.$name.'%');$q=MerchantScope::apply($q,$ctx);
        $count=(clone $q)->count();$list=[];
        foreach ((clone $q)->field(self::ROOT_FIELDS)->order('sort','desc')->order('id','desc')->page($page,$limit)->select() as $model) {
            $row=$model->getData();MerchantScope::assertOwned($row,$ctx);$list[]=$row;
        }
        return ['list'=>$list,'count'=>$count];
    }
    private function childRows(int $id,bool $lock):array
    {
        if ($lock) self::tx();$all=[];
        foreach (self::CHILD_FIELDS as $kind=>$fields) {
            $rows=Db::name('shipping_templates_'.$kind)->where('temp_id',$id)->field($fields)->order('id')->limit(Input::ROW_MAX+1)->lock($lock)->select()->toArray();
            if (count($rows)>Input::ROW_MAX) Input::unavailable();
            foreach ($rows as $row) if (Input::id($row['temp_id'])!==$id) Input::unavailable();$all[$kind]=$rows;
        }
        return $all;
    }
    public function rules(MerchantContext $ctx,int $id,bool $lock=false):array
    {
        $this->find($ctx,$id,$lock);return $this->childRows($id,$lock);
    }
    /** Internal public quote lookup: caller supplies owner from the current actual product, never a request filter. */
    public function forProduct(int $owner,int $id):array
    {
        if ($owner<1||$owner>4294967295) Input::unavailable();Input::id($id);
        $row=(new ShippingTemplates())->db()->where('id',$id)->where('mer_id',$owner)->field(self::ROOT_FIELDS)->find();
        if ($row===null) throw new \think\exception\HttpException(422,'商品运费模板不可用');
        $data=$row->getData();if ((string)$data['mer_id']!==(string)$owner) Input::unavailable();
        return ['root'=>$data,'rules'=>$this->childRows($id,false)];
    }
    public function create(MerchantContext $ctx,array $data):int
    {
        self::tx();$data['mer_id']=$ctx->merId;$data['add_time']=time();Input::id($data['add_time']);
        $id=Input::id((new ShippingTemplates())->db()->insertGetId($data));$this->find($ctx,$id,true);return $id;
    }
    public function update(MerchantContext $ctx,int $id,array $data):void
    {
        self::tx();$this->find($ctx,$id,true);
        if (array_diff(array_keys($data),['name','type','appoint','no_delivery','sort'])) Input::unavailable();
        $count=$this->root($ctx,$id)->update($data);
        if (!is_int($count)||$count<0||$count>1) Input::unavailable();$after=$this->find($ctx,$id,true);
        foreach ($data as $key=>$value) if ((string)$after[$key]!==(string)$value) Input::unavailable();
    }
    public function replaceRules(MerchantContext $ctx,int $id,array $old,array $new):void
    {
        self::tx();$this->find($ctx,$id,true);
        foreach (self::CHILD_FIELDS as $kind=>$fields) {
            $count=Db::name('shipping_templates_'.$kind)->where('temp_id',$id)->delete();if ($count!==count($old[$kind])) Input::unavailable();
            $groups=[];
            foreach ($new[$kind] as $row) {
                $group=$row['group'];unset($row['group']);$groups[$group]??=bin2hex(random_bytes(16));
                $row['temp_id']=$id;$row['uniqid']=$groups[$group];
                if (Db::name('shipping_templates_'.$kind)->insert($row)!==1) Input::unavailable();
            }
        }
        $actual=$this->rules($ctx,$id,true);foreach ($new as $kind=>$rows) if (count($actual[$kind])!==count($rows)) Input::unavailable();
    }
    public function delete(MerchantContext $ctx,int $id):void
    {
        self::tx();$this->find($ctx,$id,true);$old=$this->rules($ctx,$id,true);
        foreach (self::CHILD_FIELDS as $kind=>$fields) if (Db::name('shipping_templates_'.$kind)->where('temp_id',$id)->delete()!==count($old[$kind])) Input::unavailable();
        if ($this->root($ctx,$id)->delete()!==1) Input::unavailable();
    }
    /** Shared public dictionary, pure read; city_id is the business key, not the PK id. */
    public function cityMap():array
    {
        try {
        $rows=(new SystemCity())->db()->field(['city_id','parent_id','level','name','is_show'])->order('id')->limit(Input::ROW_MAX+1)->select()->toArray();
        if (count($rows)>Input::ROW_MAX) Input::unavailable();$map=[];
        foreach ($rows as $row) {
            $id=Input::id($row['city_id']);if (isset($map[$id])) Input::unavailable();
            $map[$id]=['city_id'=>$id,'parent_id'=>Input::id($row['parent_id'],true),'level'=>\app\services\marketplace\MerchantManagementInput::integer($row['level'],0,2,true),
                'name'=>\app\services\marketplace\MerchantManagementInput::text($row['name'],100,true),'enabled'=>\app\services\marketplace\MerchantManagementInput::integer($row['is_show'],0,1,true)===1];
        }
        foreach ($map as $id=>$row) {
            if ($row['level']===0) { if ($row['parent_id']!==0) Input::unavailable(); }
            elseif (!isset($map[$row['parent_id']])||$map[$row['parent_id']]['level']!==$row['level']-1) Input::unavailable();
        }
        foreach ($map as $id=>$row) for ($parent=$row['parent_id'];$parent!==0;$parent=$map[$parent]['parent_id']) if (!$map[$parent]['enabled']) $map[$id]['enabled']=false;
        return $map;
        } catch (\Throwable $error) { Input::unavailable(); }
    }
}
