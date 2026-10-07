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
use app\model\system\SystemCrudData;

/**
 * Class SystemCrudDataDao
 * @author 等风来
 * @email 136327134@qq.com
 * @date 2023/7/28
 * @package app\dao\system
 */
class SystemCrudDataDao extends BaseDao
{

    /**
     * @return string
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2023/7/28
     */
    protected function setModel(): string
    {
        return SystemCrudData::class;
    }
}
