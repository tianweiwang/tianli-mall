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

namespace app\model\user;


use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

class MemberCardBatch extends BaseModel
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
    protected $name = 'member_card_batch';

    protected $insert = ['add_time'];

    protected $hidden = ['update_time'];

    protected $updateTime = false;

    /**
     * 卡批次名称搜索器
     * @param Model $query
     * @param $value
     */
    public function searchTitleAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('title', 'like', '%' . $value . '%');
        }
    }

    /**
     * 格式化数据
     * @param $value
     * @return array|mixed
     */
    public function getQrcodeAttr($value)
    {
        $value = $value ? json_decode($value, true) : [];
        return is_array($value) ? $value : [];
    }
}
