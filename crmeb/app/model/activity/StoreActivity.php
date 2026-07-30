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
namespace app\model\activity;

use app\model\activity\seckill\StoreSeckill;
use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

class StoreActivity extends BaseModel
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
    protected $name = 'store_activity';

    public function seckill()
    {
        return $this->hasMany(StoreSeckill::class, 'activity_id', 'id')->where('is_show', 1)->where('is_del', 0);
    }
}
