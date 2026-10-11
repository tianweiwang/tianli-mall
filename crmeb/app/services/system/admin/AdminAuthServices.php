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

namespace app\services\system\admin;


use app\dao\system\admin\AdminAuthDao;
use app\services\BaseServices;
use crmeb\exceptions\AuthException;
use crmeb\services\AuthConfigGuard;
use crmeb\services\SessionWhitelist;
use crmeb\utils\JwtAuth;

/**
 * admin授权service
 * Class AdminAuthServices
 * @package app\services\system\admin
 */
class AdminAuthServices extends BaseServices
{
    /**
     * 构造方法
     * AdminAuthServices constructor.
     * @param AdminAuthDao $dao
     */
    public function __construct(AdminAuthDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取Admin授权信息
     * @param string $token
     * @param string $msg
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function parseToken(string $token, string $msg = '登录已过期,请重新登录'): array
    {
        if (!$token || $token === 'undefined') {
            throw new AuthException($msg, [], 401);
        }
        AuthConfigGuard::assertRedisSessionStore();
        /** @var JwtAuth $jwtAuth */
        $jwtAuth = app()->make(JwtAuth::class);
        try {
            ['id' => $id, 'type' => $type, 'pwd' => $pwd] = $jwtAuth->decodeVerified($token);
        } catch (AuthException $e) {
            // Configuration failures retain their 503 contract.
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthException($msg, [], 401);
        }
        if ($type !== 'admin'
            || !SessionWhitelist::matches(SessionWhitelist::read($token), 'admin', $id, $token)
            || $pwd === '') {
            throw new AuthException($msg, [], 401);
        }
        $adminInfo = $this->dao->get($id);
        if (!$adminInfo || !$adminInfo->id || (int)$adminInfo->is_del !== 0 || (int)$adminInfo->status !== 1) {
            if (!request()->isCli()) {
                SessionWhitelist::forget($token);
            }
            throw new AuthException($msg, [], 401);
        }
        if (!hash_equals(md5((string)$adminInfo->pwd), $pwd)) {
            if (!request()->isCli()) {
                SessionWhitelist::forget($token);
            }
            throw new AuthException($msg, [], 401);
        }

        $adminInfo->type = $type;
        return $adminInfo->hidden(['pwd', 'is_del', 'status'])->toArray();
    }

}
