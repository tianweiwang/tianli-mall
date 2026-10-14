<?php
declare(strict_types=1);
namespace app\services\marketplace\publiccatalog;

use crmeb\exceptions\AuthUnavailableException;
use think\facade\Db;

/** Ordinary type0 public presentation only; no writes, fake SKU or global raw-price change. */
final class PublicCatalogSku
{
    public function __construct(private PublicCatalogQuery $catalog) {}
    public function read(int $productId):?array
    {
        try{
            $root=$this->catalog->detail($productId);if($root===null)return null;
            $raw=Db::name('store_product_attr_value')->where('product_id',$productId)->where('type',0)->where('is_show',1)->order('id','asc')->select()->toArray();
            $attributes=Db::name('store_product_attr')->where('product_id',$productId)->where('type',0)->order('id','asc')->field(['id','product_id','type','attr_name','attr_values'])->select()->toArray();
            $resultRows=Db::name('store_product_attr_result')->where('product_id',$productId)->where('type',0)->field(['id','product_id','type','result'])->select()->toArray();
            $pictures=[];if(count($resultRows)===1){$result=json_decode($resultRows[0]['result'],true);if(is_array($result)&&is_array($result['attr']??null))foreach($result['attr'] as $axis){if(!is_array($axis)||!is_string($axis['value']??null)||!is_array($axis['detail']??null))continue;foreach($axis['detail'] as $option){if(is_array($option)&&is_string($option['value']??null)&&is_string($option['pic']??null))$pictures[$axis['value']][$option['value']]=$option['pic'];}}}
            $map=[];$seenIdentity=[];$stock=0;foreach($raw as $row){$id=PublicCatalogQuery::positive($row['id']??null);$unique=$row['unique']??null;$suk=$row['suk']??null;if((int)$row['product_id']!==$productId||(int)$row['type']!==0||(int)$row['is_show']!==1||!is_string($unique)||preg_match('/\A[a-zA-Z0-9]{8}\z/D',$unique)!==1||!is_string($suk)||$suk===''||isset($map[$suk])||isset($seenIdentity[$unique]))throw new AuthUnavailableException();$quantity=$row['stock']??null;if(is_string($quantity)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$quantity)===1)$quantity=(int)$quantity;if(!is_int($quantity)||$quantity<0||$quantity>16777215)throw new AuthUnavailableException();$stock+=$quantity;if($stock>16777215)throw new AuthUnavailableException();$seenIdentity[$unique]=$id;$map[$suk]=$row;}
            $nodes=[];$seenNames=[];$axisOptions=[];foreach($attributes as $axis){$name=$axis['attr_name']??null;$values=$axis['attr_values']??null;if((int)$axis['product_id']!==$productId||(int)$axis['type']!==0||!is_string($name)||$name===''||isset($seenNames[$name])||!is_string($values))return $this->unavailable($root);$seenNames[$name]=true;$options=explode(',',$values);if(in_array('',$options,true)||count(array_unique($options,SORT_STRING))!==count($options))return $this->unavailable($root);$axisOptions[]=$options;$nodes[]=['attr_name'=>$name,'attr_values'=>$options,'attr_value'=>[]];}
            if((int)$root['spec_type']===0){if(count($map)!==1)return $this->unavailable($root);$single=array_values($map)[0];return ['root'=>$root,'raw_values'=>$map,'attributes'=>[],'stock'=>$stock,'spec_unique'=>$single['unique'],'default_sku'=>$single['suk'],'definition_complete'=>true];}
            if(!$nodes||!$map)return $this->unavailable($root);
            $used=[];foreach($map as $suk=>$row){$parts=explode(',',$suk);if(count($parts)!==count($nodes))return $this->unavailable($root);foreach($parts as $index=>$option){if(!in_array($option,$axisOptions[$index],true))return $this->unavailable($root);$used[$index][$option]=true;}}
            foreach($nodes as $index=>&$node){$options=array_values(array_filter($node['attr_values'],static fn(string $option):bool=>isset($used[$index][$option])));$node['attr_values']=$options;foreach($options as $option)$node['attr_value'][]=['attr'=>$option,'check'=>false,'pic'=>$pictures[$node['attr_name']][$option]??''];}unset($node);
            $default=$root['default_sku']??'';if(!is_string($default)||!isset($map[$default]))$default='';return ['root'=>$root,'raw_values'=>$map,'attributes'=>$nodes,'stock'=>$stock,'spec_unique'=>'','default_sku'=>$default,'definition_complete'=>true];
        }catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    private function unavailable(array $root):array
    {
        return ['root'=>$root,'raw_values'=>[],'attributes'=>[],'stock'=>0,'spec_unique'=>'','default_sku'=>'','definition_complete'=>false];
    }
}
