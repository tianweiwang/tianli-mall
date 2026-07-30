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

namespace app\kefuapi\controller;


use crmeb\basic\BaseController;

/**
 * Class AuthController
 * @package app\kefuapi\controller
 */
abstract class AuthController extends BaseController
{

    /**
     * @var int
     */
    protected $kefuId;

    /**
     * @var array
     */
    protected $kefuInfo;

    /**
     * 初始化
     */
    protected function initialize()
    {
        $this->kefuId = $this->request->kefuId();
        $this->kefuInfo = $this->request->kefuInfo();
    }
}
