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
use app\model\product\product\StoreCategory;

/**
 * Class StoreCategoryDao
 * @package app\dao\product\product
 */
class StoreCategoryDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StoreCategory::class;
    }

    /**
     * 获取分类列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where)
    {
        return $this->search($where)->with('children')->order('sort desc,id desc')->select()->toArray();
    }

    /**
     *
     * @param array $where
     * @param array $field
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTierList(array $where = [], array $field = ['*'])
    {
        return $this->search($where)->field($field)->order('sort desc,id desc')->select()->toArray();
    }

    /**
     * 添加修改选择上级分类列表
     * @param array $where
     * @return array
     * @throws \ReflectionException
     */
    public function getMenus(array $where)
    {
        return $this->search($where)->order('sort desc,id desc')->column('cate_name,id');
    }

    /**
     * 根据id获取分类
     * @param string $cateIds
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCateArray(string $cateIds)
    {
        return $this->search(['id' => $cateIds])->field('cate_name,id')->select()->toArray();
    }

    /**
     * 前端分类页面分离列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCategory()
    {
        return $this->getModel()->with('children')->where('is_show', 1)->where('pid', 0)->order('sort desc,id desc')->hidden(['add_time', 'is_show', 'sort', 'children.sort', 'children.add_time', 'children.pid', 'children.is_show'])->select()->toArray();
    }

    /**
     * 根据分类id获取上级id
     * @param array $cateId
     * @return array
     */
    public function cateIdByPid(array $cateId)
    {
        return $this->getModel()->whereIn('id', $cateId)->column('pid');
    }

    /**
     * 获取首页展示的二级分类  排序默认降序
     * @param int $limit
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function byIndexList($limit = 4, $field = 'id,cate_name,pid,pic')
    {
        return $this->getModel()->where('pid', '>', 0)->where('is_show', 1)->field($field)->order('sort DESC')->limit($limit)->select()->toArray();
    }

    /**
     * 获取一级分类和二级分类组成的集合
     * @param $cateId
     * @return mixed
     */
    public function getCateParentAndChildName(string $cateId)
    {
        return $this->getModel()->alias('c')->leftJoin('StoreCategory b', 'b.id = c.pid')
            ->where('c.id', 'IN', $cateId)->field('c.cate_name as two,b.cate_name as one,c.id')
            ->select()->toArray();
    }

    /**
     * 按照个数获取一级分类下有商品的分类ID
     * @param $page
     * @param $limit
     * @return array
     */
    public function getCid($page, $limit)
    {
        return $this->getModel()
            ->where('is_show', 1)
            ->where('pid', 0)
            ->where('id', 'in', function ($query) {
                $query->name('store_product_cate')->where('status', 1)->group('cate_pid')->field('cate_pid')->select()->toArray();
            })
            ->page($page, $limit)
            ->order('sort DESC,id DESC')
            ->select()->toArray();
    }

    /**
     * 按照个数获取一级分类下有商品的分类个数
     * @param $page
     * @param $limit
     * @return int
     */
    public function getCidCount()
    {
        return $this->getModel()
            ->where('is_show', 1)
            ->where('pid', 0)
            ->where('id', 'in', function ($query) {
                $query->name('store_product_cate')->where('status', 1)->group('cate_pid')->field('cate_pid')->select()->toArray();
            })
            ->count();
    }

    /**
     * 通过分类id 获取（自己以及下级）的所有分类
     * @param $id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAllById($id, string $field = 'id')
    {
        if (is_array($id)) {
            return $this->getModel()->where(function ($query) use ($id) {
                $query->whereIn('id', $id)->whereOr('pid', 'in', $id);
            })->where('is_show', 1)->field($field)->select()->toArray();
        } else {
            return $this->getModel()->where(function ($query) use ($id) {
                $query->where('id', $id)->whereOr('pid', $id);
            })->where('is_show', 1)->field($field)->select()->toArray();
        }
    }

    /**
     * 可以搜索的获取所有二级分类
     * @param array $where
     * @param string $field
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getALlByIndex(array $where, string $field = 'id,cate_name,pid,pic', $limit = 0)
    {
        $pid = $where['pid'] ?? -1;
        return $this->getModel()->where('is_show', 1)->field($field)
            ->when(in_array($pid, [0, -1]), function ($query) use ($pid) {
                switch ($pid) {
                    case -1://所有一级
                        $query->where('pid', 0);
                        break;
                    case 0://所有二级
                        $query->where('pid', '>', 0);
                }
            })->when((int)$pid > 0, function ($query) use ($pid) {
                $query->where('pid', $pid);
            })->when(isset($where['name']) && $where['name'], function ($query) use ($where) {
                $query->whereLike('id|cate_name', '%' . $where['name'] . '%');
            })->when($limit > 0, function ($query) use ($limit) {
                $query->limit($limit);
            })->order('sort DESC')->select()->toArray();
    }
    /** Qualification precedes category count/page and does not multiply roots/SKUs. */
    public function publicCategoryQuery(int $vipUser):\think\db\Query
    {
        $query=$this->getModel()->db()->alias('catalog_category')->where('catalog_category.is_show',1)->where('catalog_category.pid',0);
        $query->whereExists(function(\think\db\Query $map)use($vipUser):void{
            $map->name('store_product_cate')->alias('catalog_map')->field('product_id')->where('catalog_map.status',1)->whereColumn('catalog_map.cate_pid','catalog_category.id');
            $map->whereExists(function(\think\db\Query $product)use($vipUser):void{
                $product->name('store_product')->alias('catalog_product')->field('id')->whereColumn('catalog_product.id','catalog_map.product_id');
                $product->whereIn('catalog_map.cate_id',function($category):void{
                    $category->name('store_category')->alias('catalog_selected_category')->field('id')->where(function($selected):void{
                        $selected->whereColumn('catalog_selected_category.id','catalog_category.id')->whereOr(function($child):void{$child->whereColumn('catalog_selected_category.pid','catalog_category.id');});
                    });
                });
                if($vipUser===0)$product->where(function($vip):void{$vip->where('vip_product',0)->whereOr(function($alternative):void{$alternative->where('vip_product',1)->where('vip_product_type',1);});});
                app()->make(\app\services\marketplace\publiccatalog\PublicCatalogQuery::class)->constrain($product,'catalog_product');
            });
        });return $query;
    }
    public function getPublicCid(int $page,int $limit,int $vipUser):array
    {
        return $this->publicCategoryQuery($vipUser)->page($page,$limit)->order('sort DESC,id DESC')->select()->toArray();
    }
    public function getPublicCidCount(int $vipUser):int
    {
        return $this->publicCategoryQuery($vipUser)->count();
    }

}
