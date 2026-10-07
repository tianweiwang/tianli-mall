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

namespace app\outapi\validate;


use think\Validate;

class LoginValidate extends Validate
{
    protected $regex = ['account' => '/^[a-zA-Z0-9]{4,30}$/'];
    /**
     * @var string[]
     */
    protected $rule = [
        'appid' => 'require|account',
        'appsecret' => 'require',
    ];

    /**
     * @var string[]
     */
    protected $message = [
        'appid.require' => '请填写账号',
        'appid.account' => '账号或密码错误',
        'appsecret.regex' => '请填写密码',
    ];
}
