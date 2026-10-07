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

namespace app\services\wechat;


use app\dao\wechat\WechatMediaDao;
use app\services\BaseServices;

/**
 * Class WechatMediaServices
 * @package app\services\wechat
 * @method save(array $data) 保存数据
 */
class WechatMediaServices extends BaseServices
{
    /**
     * WechatMediaServices constructor.
     * @param WechatMediaDao $dao
     */
    public function __construct(WechatMediaDao $dao)
    {
        $this->dao = $dao;
    }

}
