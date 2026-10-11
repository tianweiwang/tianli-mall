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
declare (strict_types=1);

namespace app\services\user;

use app\services\BaseServices;
use app\dao\user\UserAuthDao;
use crmeb\exceptions\AuthException;
use crmeb\services\AuthConfigGuard;
use crmeb\services\SessionWhitelist;
use crmeb\utils\JwtAuth;

/**
 *
 * Class UserAuthServices
 * @package app\services\user
 */
class UserAuthServices extends BaseServices
{

    /**
     * UserAuthServices constructor.
     * @param UserAuthDao $dao
     */
    public function __construct(UserAuthDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取授权信息
     * @param $token
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function parseToken($token): array
    {
        if (!is_string($token) || !$token || $token === 'undefined') {
            throw new AuthException('请登录', [], 401);
        }
        AuthConfigGuard::assertRedisSessionStore();
        /** @var JwtAuth $jwtAuth */
        $jwtAuth = app()->make(JwtAuth::class);
        try {
            ['id' => $id, 'type' => $type] = $jwtAuth->decodeVerified($token);
        } catch (AuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthException('登录已过期,请重新登录', [], 401);
        }
        if ($type !== 'api') {
            throw new AuthException('请登录', [], 401);
        }
        $tokenData = SessionWhitelist::read($token);
        if (!SessionWhitelist::matches($tokenData, 'api', $id, $token)) {
            throw new AuthException('请登录', [], 401);
        }
        $user = $this->dao->get(['uid' => $id, 'is_del' => 0, 'status' => 1]);

        if (!$user || $user->uid != $tokenData['uid']) {
            if (!request()->isCli()) SessionWhitelist::forget($token);
            throw new AuthException('登录状态有误,请重新登录', [], 401);
        }

        $this->dao->update(['uid' => $id], ['last_time' => time()]);

        $tokenData['type'] = $type;
        return compact('user', 'tokenData');
    }

}
