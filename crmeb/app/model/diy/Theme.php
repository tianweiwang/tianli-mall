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
namespace app\model\diy;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

/**
 * 自定义主题
 * @author wuhaotian
 * @email 442384644@qq.com
 * @date 2025/12/18
 */
class Theme extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'theme';
}
