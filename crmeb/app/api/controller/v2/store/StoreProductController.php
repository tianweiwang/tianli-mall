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

namespace app\api\controller\v2\store;

use app\Request;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;

class StoreProductController
{
    protected $services;

    public function __construct(StoreProductServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取商品属性
     * @param Request $request
     * @return mixed
     */
    public function getProductAttr(Request $request)
    {
        list($id, $type) = $request->getMore([
            ['id', 0],
            ['type', 0]
        ], true);
        if (!$id) return app('json')->fail('参数错误');
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        list($data['productAttr'], $data['productValue']) = $storeProductAttrServices->getPublicProductAttrDetail($id, $request->uid(), $type);
        $catalog=app()->make(\app\services\marketplace\PublicCatalogServices::class);
        $definition=$catalog->ordinaryDefinition((int)$id,(int)$request->uid(),(int)$type);
        $projection=\app\services\marketplace\publiccatalog\PublicCatalogProjection::class;
        $storeInfo=$catalog->displayRoot($definition['root']);
        $storeInfo['default_sku']=$definition['default_sku'];
        if(!$this->services->vipIsOpen((bool)$storeInfo['is_vip']))$storeInfo['vip_price']=0;
        $data['storeInfo']=$projection::skuSummary($storeInfo,$definition['stock']);
        $data['productAttr']=$projection::attributes($data['productAttr']);
        $data['productValue']=$projection::values($data['productValue']);
        return app('json')->success($data);
    }
}
