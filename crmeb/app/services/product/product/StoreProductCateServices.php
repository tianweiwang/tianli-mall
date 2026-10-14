<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace app\services\product\product;


use app\dao\product\product\StoreProductCateDao;
use app\services\BaseServices;

/**
 * Class StoreProductCateService
 * @package app\services\product\product
 * @method productIdByCateId(array $productId) 根据商品id获取分类id
 * @method cateIdByProduct(array $cate_id) 根据分类获取商品id
 */
class StoreProductCateServices extends BaseServices
{
    public function __construct(StoreProductCateDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 商品添加修改商品分类关联
     * @param $id
     * @param $cateData
     */
    public function change($id, $cateData)
    {
        $guard=\app\services\marketplace\product\LegacyProductWriteServices::class;
        if($guard::active()){
            $id=$guard::id($id);$guard::assertParent($id);$query=\think\facade\Db::name('store_product_cate')->where('product_id',$id);$guard::query($query);
            $before=$query->order('id')->lock(true)->select()->toArray();$desired=[];
            foreach($cateData as$row){if(!is_array($row)||(string)$row['product_id']!==(string)$id)throw new \crmeb\exceptions\AuthUnavailableException();
                $category=$guard::id($row['cate_id'],2147483647);if(isset($desired[$category]))throw new \crmeb\exceptions\AuthUnavailableException();
                $row['status']=$guard::categoryStatus($id);$desired[$category]=$row;}
            $used=[];
            foreach($before as$row){$category=(int)$row['cate_id'];$item=$desired[$category]??null;
                $bound=\think\facade\Db::name('store_product_cate')->where('id',$row['id'])->where('product_id',$id);$guard::query($bound);
                if($item===null||isset($used[$category])){if($bound->delete()!==1)throw new \crmeb\exceptions\AuthUnavailableException();continue;}
                $patch=[];foreach(['cate_pid','status']as$field)if((string)$row[$field]!==(string)$item[$field])$patch[$field]=$item[$field];
                if($patch!==[]){$count=$bound->update($patch);if(!is_int($count)||$count<0||$count>1)throw new \crmeb\exceptions\AuthUnavailableException();}
                $actual=$bound->find();if(!is_array($actual))throw new \crmeb\exceptions\AuthUnavailableException();$expected=array_replace($row,$patch);
                foreach($expected as$field=>$value)if($value===null?$actual[$field]!==null:(string)$actual[$field]!==(string)$value)throw new \crmeb\exceptions\AuthUnavailableException();$used[$category]=true;
            }
            foreach($desired as$category=>$row)if(!isset($used[$category])){
                $newId=$guard::id(\think\facade\Db::name('store_product_cate')->insertGetId($row),2147483647);
                $actual=\think\facade\Db::name('store_product_cate')->where('id',$newId)->where('product_id',$id)->find();if(!is_array($actual))throw new \crmeb\exceptions\AuthUnavailableException();
                foreach($row as$field=>$value)if((string)$actual[$field]!==(string)$value)throw new \crmeb\exceptions\AuthUnavailableException();}
            $actual=$query->select()->toArray();if(count($actual)!==count($desired))throw new \crmeb\exceptions\AuthUnavailableException();
            foreach($actual as$row){$wanted=$desired[(int)$row['cate_id']]??null;if(!is_array($wanted))throw new \crmeb\exceptions\AuthUnavailableException();
                foreach(['product_id','cate_id','cate_pid','status']as$field)if((string)$row[$field]!==(string)$wanted[$field])throw new \crmeb\exceptions\AuthUnavailableException();}
            return;
        }
        $this->dao->delete(['product_id' => $id]);
        $this->dao->saveAll($cateData);
    }


}
