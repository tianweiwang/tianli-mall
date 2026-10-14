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

namespace app\dao\product\product;

use app\dao\BaseDao;
use app\model\product\product\StoreProductRelation;

/**
 * Class StoreProductRelationDao
 * @package app\dao\product\product
 */
class StoreProductRelationDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreProductRelation::class;
    }

    /**
     * 获取收藏列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, string $field, int $page, int $limit)
    {
        return $this->search($where)->field($field)->with(['product'])->page($page, $limit)->select()->toArray();
    }
    /** Same qualified relation builder for list and count; no product/SKU join multiplicity. */
    public function publicCollectionQuery(int $uid,array $context):\think\db\Query
    {
        $catalog=app()->make(\app\services\marketplace\PublicCatalogServices::class);
        $query=$this->getModel()->db()->alias('catalog_relation')->where('catalog_relation.uid',$uid)->where('catalog_relation.type','collect');
        $query->where(function(\think\db\Query $kind)use($catalog,$context):void{
            $kind->where(function(\think\db\Query $product)use($catalog,$context):void{
                $product->where('catalog_relation.category','product')->whereExists(function(\think\db\Query $base)use($catalog,$context):void{
                    $base->name('store_product')->alias('collection_base')->field('id')->whereColumn('collection_base.id','catalog_relation.product_id');
                    $catalog->constrainBookmarkBase($base,'collection_base',$context);
                });
            });
            if($context['seckill_slots'])$kind->whereOr(function(\think\db\Query $native)use($context):void{
                $native->where('catalog_relation.category','product_seckill')->whereIn('catalog_relation.product_id',array_keys($context['seckill_slots']));
            });
        });return $query;
    }
    public function getPublicCollection(int $uid,array $context,int $page,int $limit):array
    {
        $query=$this->publicCollectionQuery($uid,$context);$count=(clone $query)->count();
        $list=$query->field(['product_id','category'])->order('catalog_relation.add_time DESC,catalog_relation.product_id DESC,catalog_relation.category ASC')->page($page,$limit)->select()->toArray();
        return compact('list','count');
    }

}
