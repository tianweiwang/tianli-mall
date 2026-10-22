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
use crmeb\services\auth\AuthSessionStore;
use crmeb\services\auth\CredentialStamp;
use crmeb\services\AuthConfigGuard;

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
    public function parseToken(string $token, string $msg = '登录已过期,请重新登录', ?array &$verifiedSession = null): array
    {
        $verifiedSession = null;
        if (!$token || $token === 'undefined') {
            throw new AuthSessionException();
        }

        // Only this call's validated identity is retained in the local scope.
        // AuthTokenV2 invokes the reader after signature/schema/app checks.
        $adminInfo = null;
        $validatedSession = AuthTokenV2::fromConfig()->verify('adminapi', $token, function (string $id) use (&$adminInfo): string {
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
        $result = $adminInfo->hidden(['pwd', 'is_del', 'status'])->toArray();
        $verifiedSession = $validatedSession;
        return $result;
    }

    /** Read only the still-current binding captured from a verified PC scan issuer. */
    public function verifyProductMediaScanIssuer(array $binding): void
    {
        try {
            $keys = array_keys($binding);
            sort($keys, SORT_STRING);
            if ($keys !== ['epoch', 'fingerprint', 'id', 'sid', 'version']) throw new AuthUnavailableException();
            foreach (['id', 'sid', 'version', 'fingerprint', 'epoch'] as $field) {
                if (!is_string($binding[$field])) throw new AuthUnavailableException();
            }
            $id = $binding['id'];
            if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) !== 1
                || (strlen($id) === 10 && strcmp($id, '4294967295') > 0)
                || preg_match('/\A[a-f0-9]{32}\z/D', $binding['sid']) !== 1
                || preg_match('/\A[a-f0-9]{32}\z/D', $binding['version']) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $binding['fingerprint']) !== 1
                || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $binding['epoch']) !== 1) throw new AuthUnavailableException();
            $config = AuthConfigGuard::authConfig();
            if ($binding['epoch'] !== $config['session_epoch']) throw new AuthSessionException();
            $admin = $this->dao->get($id);
            if (!$admin || (string)$admin->id !== $id || (int)$admin->is_del !== 0
                || (int)$admin->status !== 1) throw new AuthSessionException();
            $digest = (string)$admin->pwd;
            if ($digest === '') throw new AuthSessionException();
            $stamp = CredentialStamp::for('adminapi', $id, $digest);
            $store = AuthSessionStore::fromConfig();
            if ($store->epoch() !== $config['session_epoch']) throw new AuthUnavailableException();
            $store->verify('adminapi', $binding['sid'], $id,
                $config['apps']['adminapi']['type'], $config['apps']['adminapi']['audience'],
                $binding['version'], $binding['fingerprint'], $stamp);
        } catch (AuthSessionException|AuthUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

}
