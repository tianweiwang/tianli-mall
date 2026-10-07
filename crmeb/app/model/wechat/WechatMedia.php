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
 * Class WechatMedia
 * @package app\model\wechat
 */
class WechatMedia extends BaseModel
{
    use ModelTrait;

    /**
     * 主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 表名
     * @var string
     */
    protected $name = 'wechat_media';

}
