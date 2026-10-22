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

namespace app\adminapi\middleware;


use app\Request;
use app\services\system\admin\AdminAuthServices;
use crmeb\interfaces\MiddlewareInterface;
use think\facade\Config;
use crmeb\services\CacheService;

/**
 * 后台登陆验证中间件
 * Class AdminAuthTokenMiddleware
 * @package app\adminapi\middleware
 */
class AdminAuthTokenMiddleware implements MiddlewareInterface
{
    /**
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 吴汐
     * @email 442384644@qq.com
     * @date 2023/04/07
     */
    public function handle(Request $request, \Closure $next)
    {
        $request->clearVerifiedTokenFingerprint();
        $request->clearVerifiedAdminSession();
        try {
            $token = trim(ltrim((string)$request->header(Config::get('cookie.token_name', 'Authori-zation')), 'Bearer'));
            /** @var AdminAuthServices $service */
            $service = app()->make(AdminAuthServices::class);
            $verifiedSession = null;
            $adminInfo = $service->parseToken($token, '登录已过期,请重新登录', $verifiedSession);
            $request->setVerifiedTokenFingerprint(hash('sha256', $token));
            if (!is_array($verifiedSession)) throw new \crmeb\exceptions\AuthUnavailableException();
            $request->setVerifiedAdminSession($verifiedSession + ['fingerprint' => $request->verifiedTokenFingerprint()]);
            $request->macro('isAdminLogin', function () use (&$adminInfo) {
                return !is_null($adminInfo);
            });
            $request->macro('adminId', function () use (&$adminInfo) {
                return $adminInfo['id'];
            });

            $request->macro('adminInfo', function () use (&$adminInfo) {
                return $adminInfo;
            });

            return $next($request);
        } finally {
            $request->clearVerifiedTokenFingerprint();
            $request->clearVerifiedAdminSession();
        }
    }
}
