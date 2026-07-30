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


use app\dao\order\StoreOrderStoreOrderStatusDao;
use app\services\BaseServices;

/**
 * Class StoreOrderStoreOrderStatusServices
 * @package app\services\order
 * @method getTakeOrderIds(array $where, ?int $limit = 0)
 */
class StoreOrderStoreOrderStatusServices extends BaseServices
{

    /**
     * StoreOrderStoreOrderStatusServices constructor.
     * @param StoreOrderStoreOrderStatusDao $dao
     */
    public function __construct(StoreOrderStoreOrderStatusDao $dao)
    {
        $this->dao = $dao;
    }
}
