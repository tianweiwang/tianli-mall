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

namespace app\services\order;


use app\dao\order\StoreOrderStoreOrderCartInfoDao;
use app\services\BaseServices;

/**
 * Class StoreOrderStoreOrderCartInfoServices
 * @package app\services\order
 * @method getUserCartProductIds(array $where) 获取用户购买过的商品id
 */
class StoreOrderStoreOrderCartInfoServices extends BaseServices
{
    /**
     * StoreOrderStoreOrderCartInfoServices constructor.
     * @param StoreOrderStoreOrderCartInfoDao $dao
     */
    public function __construct(StoreOrderStoreOrderCartInfoDao $dao)
    {
        $this->dao = $dao;
    }

}
