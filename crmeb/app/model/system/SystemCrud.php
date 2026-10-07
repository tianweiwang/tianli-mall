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

namespace app\model\system;


use crmeb\basic\BaseModel;

/**
 * Class SystemCrud
 * @author 等风来
 * @email 136327134@qq.com
 * @date 2023/4/6
 * @package app\model\system
 */
class SystemCrud extends BaseModel
{

    /**
     * @var string
     */
    protected $name = 'system_crud';

    /**
     * @var string
     */
    protected $pk = 'id';

    public function getAddTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }

    public function getFieldAttr($value)
    {
        return json_decode($value, true);
    }

    public function getMenuIdsAttr($value)
    {
        return json_decode($value, true);
    }

    public function getMakePathAttr($value)
    {
        return json_decode($value, true);
    }

    public function getRouteIdsAttr($value)
    {
        return json_decode($value, true);
    }
}
