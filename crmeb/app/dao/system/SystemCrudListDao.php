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
namespace app\dao\system;

use app\dao\BaseDao;
use app\model\system\SystemCrudList;

/**
 * @author wuhaotian
 * @email 442384644@qq.com
 * @date 2024/5/20
 */
class SystemCrudListDao extends BaseDao
{
    /**
     * @return string
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2024/5/20
     */
    protected function setModel(): string
    {
        return SystemCrudList::class;
    }
}