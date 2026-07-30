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

namespace app\services\shipping;


use app\dao\shipping\ShippingTemplatesRegionCityDao;
use app\services\BaseServices;

/**
 * 根据地区设置邮费
 * Class ShippingTemplatesRegionCityServices
 * @package app\services\shipping
 * @method getUniqidList(array $where,bool $group) 获取指定条件下的包邮列表
 */
class ShippingTemplatesRegionCityServices extends BaseServices
{

    /**
     * 构造方法
     * ShippingTemplatesRegionCityServices constructor.
     * @param ShippingTemplatesRegionCityDao $dao
     */
    public function __construct(ShippingTemplatesRegionCityDao $dao)
    {
        $this->dao = $dao;
    }
}
