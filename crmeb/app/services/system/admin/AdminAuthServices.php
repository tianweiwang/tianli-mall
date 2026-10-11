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
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AuthTokenV2;

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
            throw new AuthSessionException();
        }

        // Only this call's validated identity is retained in the local scope.
        // AuthTokenV2 invokes the reader after signature/schema/app checks.
        $adminInfo = null;
        AuthTokenV2::fromConfig()->verify('adminapi', $token, function (string $id) use (&$adminInfo): string {
            try {
                $adminInfo = $this->dao->get($id);
            } catch (\Throwable $error) {
                throw new AuthUnavailableException();
            }
            if (!$adminInfo || !$adminInfo->id || (int)$adminInfo->is_del !== 0
                || (int)$adminInfo->status !== 1) {
                throw new AuthSessionException();
            }
            return (string)$adminInfo->pwd;
        });

        $adminInfo->type = 'admin';
        return $adminInfo->hidden(['pwd', 'is_del', 'status'])->toArray();
    }

}
