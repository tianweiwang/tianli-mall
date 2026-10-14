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

namespace app\jobs;


use app\services\product\product\CopyTaobaoServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use crmeb\basic\BaseJobs;
use crmeb\services\CacheService;
use crmeb\traits\QueueTrait;
use think\facade\Log;
use app\services\marketplace\product\LegacyProductWriteServices;
use think\facade\Db;
use crmeb\exceptions\AuthUnavailableException;

/**
 * 复制商品
 * Class ProductCopyJob
 * @package app\jobs
 */
class ProductCopyJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 下载商品详情图片
     * @param $id
     * @return bool
     */
    public function copyDescriptionImage($id, $description, $image, $count)
    {
        try {
            $id=LegacyProductWriteServices::id($id);
            return app()->make(LegacyProductWriteServices::class)->selfWorker([$id],function()use($id,$description,$image,$count):bool{
            /** @var CopyTaobaoServices $copyTaobao */
            $copyTaobao = app()->make(CopyTaobaoServices::class);
            /** @var StoreDescriptionServices $storeDescriptionServices */
            $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
            if (is_int(strpos($image, 'http'))) {
                $d_image = $image;
            } else {
                $d_image = 'http://' . ltrim($image, '\//');
            }
            $description_cache = CacheService::get('desc_images_' . $id);
            if ($description_cache === null || $description_cache === '') {
                $description_cache = $description;
                CacheService::set('desc_images_count' . $id, 0);
            }
            $res = $copyTaobao->downloadCopyImage($d_image);
            $description_cache = str_replace($image, $res, $description_cache);
            $desc_count = CacheService::get('desc_images_count' . $id) + 1;
            if ($desc_count == $count) {
                CacheService::delete('desc_images_' . $id);
                CacheService::delete('desc_images_count' . $id);
                $storeDescriptionServices->saveDescription((int)$id, $description_cache);
            } else {
                CacheService::set('desc_images_' . $id, $description_cache);
                CacheService::set('desc_images_count' . $id, $desc_count);
            }
            return true;
            });
        } catch (\Throwable $e) {
            self::unavailable('description');
        }
    }

    /**
     * 下载商品轮播图片
     * @param $id
     * @return bool
     */
    public function copySliderImage($id, $image, $count)
    {
        try {
            $id=LegacyProductWriteServices::id($id);
            return app()->make(LegacyProductWriteServices::class)->selfWorker([$id],function()use($id,$image,$count):bool{
            /** @var CopyTaobaoServices $copyTaobao */
            $copyTaobao = app()->make(CopyTaobaoServices::class);
            /** @var StoreProductServices $StoreProductServices */
            $StoreProductServices = app()->make(StoreProductServices::class);
            //下载图片
            $res = $copyTaobao->downloadCopyImage($image);
            //获取缓存中的轮播图
            $slider_images = CacheService::get('slider_images_' . $id) ?? [];
            //缓存为null则赋值[]
            if ($slider_images === null || $slider_images === '') $slider_images = [];
            //将下载的图片插入数组
            array_push($slider_images, $res);
            //如果$slider_images中图片数量和传入的$count相等，说明已经下载完成，写入商品表，如果不等则继续插入缓存
            if (count($slider_images) == $count) {
                CacheService::delete('slider_images_' . $id);
                $image = $slider_images[0];
                $slider_images = $slider_images ? json_encode($slider_images) : '';
                $StoreProductServices->update($id, ['slider_image' => $slider_images, 'image' => $image]);
            } else {
                CacheService::set('slider_images_' . $id, $slider_images);
            }
            return true;
            });
        } catch (\Throwable $e) {
            self::unavailable('slider');
        }
    }

    /**
     * 下载商品规格图片
     * @param $value_id
     * @param $value_image
     * @return bool
     */
    public function copyAttrImage($value_id, $value_image)
    {
        try {
            $value_id=LegacyProductWriteServices::id($value_id,2147483647);
            $row=Db::name('store_product_attr_value')->where('id',$value_id)->where('type',0)->field(['id','product_id','unique'])->find();
            if(!is_array($row))throw new AuthUnavailableException();$id=LegacyProductWriteServices::id($row['product_id']);
            return app()->make(LegacyProductWriteServices::class)->selfWorker([$id],function()use($id,$value_id,$value_image,$row):bool{
            $query=Db::name('store_product_attr_value')->where('id',$value_id)->where('product_id',$id)->where('type',0)->where('unique',$row['unique'])->lock(true);
            LegacyProductWriteServices::query($query);$current=$query->find();if(!is_array($current))throw new AuthUnavailableException();
            /** @var CopyTaobaoServices $copyTaobao */
            $copyTaobao = app()->make(CopyTaobaoServices::class);
            /** @var StoreProductAttrValueServices $StoreProductAttrValueServices */
            $StoreProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
            //下载图片
            $res = $copyTaobao->downloadCopyImage($value_image);
            $affected=$query->update(['image'=>$res]);if(!is_int($affected)||$affected<0||$affected>1||$query->value('image')!==$res)throw new AuthUnavailableException();
            return true;
            });
        } catch (\Throwable $e) {
            self::unavailable('attribute');
        }
    }
    private static function unavailable(string $phase): never
    {
        $record='{"event":"legacy_product_copy_unavailable","phase":"'.$phase.'"}';
        try{Log::error($record);}catch(\Throwable $ignored){error_log($record);}
        throw new AuthUnavailableException();
    }
}
