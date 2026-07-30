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

namespace crmeb\services\oauth;

/**
 * 第三方登录
 * Interface OAuthInterface
 * @package crmeb\services\oauth
 */
interface OAuthInterface
{

    /**
     * 获取用户信息
     * @param string $openid
     * @return mixed
     */
    public function getUserInfo(string $openid);

    /**
     * 授权
     * @param string|null $code
     * @param array $options
     * @return mixed
     */
    public function oauth(string $code = null, array $options = []);

}
