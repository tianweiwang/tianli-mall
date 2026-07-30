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
namespace app\model\product\sku;


use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

class StoreProductVirtual extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_product_virtual';

    /**
     * 卡号搜索器
     * @param $query
     * @param $value
     */
    public function searchCardNoAttr($query, $value)
    {
        $query->where('card_no', $value);
    }

    /**
     * 卡密搜索器
     * @param $query
     * @param $value
     */
    public function searchCardPwdAttr($query, $value)
    {
        $query->where('card_pwd', $value);
    }

    /**
     * 商品搜索器
     * @param $query
     * @param $value
     */
    public function searchProductIdAttr($query, $value)
    {
        $query->where('product_id', $value);
    }

    /**
     * 用户搜索器
     * @param $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        $query->where('uid', $value);
    }

    /**
     * 订单搜索器
     * @param $query
     * @param $value
     */
    public function searchOrderIdAttr($query, $value)
    {
        $query->where('order_id', $value);
    }
}
