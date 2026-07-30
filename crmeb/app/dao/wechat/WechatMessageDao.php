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

namespace app\dao\wechat;


use app\dao\BaseDao;
use app\model\wechat\WechatMessage;

/**
 * Class WechatMessageDao
 * @package app\dao\wechat
 */
class WechatMessageDao extends BaseDao
{
    /**
     * @return string
     */
    protected function setModel(): string
    {
        return WechatMessage::class;
    }
}
