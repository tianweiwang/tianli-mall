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


use app\dao\product\product\StoreProductRelationDao;
use app\services\BaseServices;
use app\jobs\ProductLogJob;
use crmeb\exceptions\ApiException;

/**
 * Class StoreProductRelationService
 * @package app\services\product\product
 */
class StoreProductRelationServices extends BaseServices
{
    /**
     * StoreProductRelationServices constructor.
     * @param StoreProductRelationDao $dao
     */
    public function __construct(StoreProductRelationDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 用户是否点赞或收藏商品
     * @param array $where
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function isProductRelation(array $where)
    {
        $res = $this->dao->getOne($where);
        if ($res) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取用户收藏数量
     * @param int $uid
     * @return int
     */
    public function getUserCollectCount(int $uid)
    {
        return $this->dao->count(['uid' => $uid, 'type' => 'collect']);
    }

    /**
     * 收藏
     * @param int $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/03/01
     */
    public function getUserCollectProduct(int $uid,string $thumbnail='')
    {
        $catalog=app()->make(\app\services\marketplace\PublicCatalogServices::class);$context=$catalog->collectionContext($uid);
        [$page,$limit]=$this->getPageValue();$data=$this->dao->getPublicCollection($uid,$context,$page,$limit);
        foreach($data['list']as&$row){
            $channel=$catalog->requireCollectionChannel($row['category'],(int)$row['product_id'],$context);$root=$channel['root'];
            $row['pid']=$root['id'];$row['store_name']=$root['store_name'];$row['price']=$root['price'];$row['ot_price']=$root['ot_price'];$row['sales']=$root['sales'];
            $row['image']=$thumbnail===''?get_thumb_water($root['image']):get_thumb_water($root['image'],$thumbnail);
            $row['is_del']=$root['is_del'];$row['is_show']=$root['is_show'];$row['is_fail']=false;
            $row['collection_type']=$channel['collection_type'];$row['collection_id']=$channel['collection_id'];
            if(isset($channel['collection_time_id']))$row['collection_time_id']=$channel['collection_time_id'];
            $row=\app\services\marketplace\publiccatalog\PublicCatalogProjection::collection($row);
        }unset($row);return $data;
    }

    /**
     * 添加点赞 收藏
     * @param int $productId
     * @param int $uid
     * @param string $relationType
     * @param string $category
     * @return bool
     */
    public function productRelation(int $productId, int $uid, string $relationType, string $category = 'product')
    {
        $relationType = strtolower($relationType);
        $category = strtolower($category);
        $catalog=app()->make(\app\services\marketplace\PublicCatalogServices::class);$context=$catalog->collectionContext($uid,$category,[$productId]);
        $catalog->requireCollectionChannel($category,$productId,$context);
        $data = ['uid' => $uid, 'product_id' => $productId, 'type' => $relationType, 'category' => $category];
        if ($this->dao->getOne($data)) {
            return true;
        }
        $data['add_time'] = time();
        if (!$this->dao->save($data)) {
            throw new ApiException('保存失败');
        }
        //收藏记录
        ProductLogJob::dispatch(['collect', ['uid' => $uid, 'product_id' => $productId]]);

        //自定义事件-用户商品收藏
        event('CustomEventListener', ['user_product_collect', [
            'product_id' => $productId,
            'uid' => $uid,
            'collect_time' => date('Y-m-d H:i:s'),
        ]]);

        return true;
    }

    /**
     * 取消 点赞 收藏
     * @param array $productId
     * @param int $uid
     * @param string $relationType
     * @param string $category
     * @return bool
     * @throws \Exception
     */
    public function unProductRelation(array $productId, int $uid, string $relationType, string $category = 'product')
    {
        $relationType = strtolower($relationType);
        $category = strtolower($category);
        $storeProductRelation = $this->dao->delete([
            ['uid', '=', $uid],
            ['product_id', 'in', $productId],
            ['type', '=', $relationType],
            ['category', '=', $category]
        ]);
        if (!$storeProductRelation) throw new ApiException('取消失败');
        return true;
    }

    /**
     * 批量 添加点赞 收藏
     * @param array $productIdS
     * @param int $uid
     * @param string $relationType
     * @param string $category
     * @return bool
     */
    public function productRelationAll(array $productIdS, int $uid, string $relationType, string $category = 'product')
    {
        $relationType = strtolower($relationType);
        $category = strtolower($category);
        $catalog=app()->make(\app\services\marketplace\PublicCatalogServices::class);$context=$catalog->collectionContext($uid,$category,$productIdS);
        foreach($productIdS as$productId)$catalog->requireCollectionChannel($category,(int)$productId,$context);
        $relationData = [];
        $productIdS = array_unique($productIdS);
        $relationProductIdS = $this->dao->getColumn(['uid' => $uid, 'type' => $relationType, 'category' => $category, 'product_id' => $productIdS], 'product_id');
        foreach ($productIdS as $productId) {
            if (!in_array($productId, $relationProductIdS)) {
                $relationData[] = ['uid' => $uid, 'product_id' => $productId, 'type' => $relationType, 'category' => $category];
            }
        }
        if ($relationData) {
            if (!$this->dao->saveAll($relationData)) {
                throw new ApiException('添加失败');
            }
        }
        return true;
    }
}
