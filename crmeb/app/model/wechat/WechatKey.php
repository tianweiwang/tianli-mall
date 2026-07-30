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

namespace app\model\wechat;


use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

/**
 * 关键词model
 * Class WechatKey
 * @package app\model\wechat
 */
class WechatKey extends BaseModel
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
    protected $name = 'wechat_key';

    public function searchKeysAttr($query, $value)
    {
        if ($value) $query->where('keys', $value);
    }

    public function searchKeysLikeAttr($query, $value)
    {
        if ($value) $query->where('keys', 'like', '%' . $value . '%');
    }
}
