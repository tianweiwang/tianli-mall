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


use app\dao\order\OtherOrderStatusDao;
use app\services\BaseServices;

/**
 * Class OtherOrderStatusServices
 * @package app\services\order
 */
class OtherOrderStatusServices extends BaseServices
{

    /**
     * OtherOrderStatusServices constructor.
     * @param OtherOrderStatusDao $dao
     */
    public function __construct(OtherOrderStatusDao $dao)
    {
        $this->dao = $dao;
    }
}
