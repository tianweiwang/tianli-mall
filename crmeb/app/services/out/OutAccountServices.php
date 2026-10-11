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

namespace app\services\out;

use app\dao\out\OutAccountDao;
use app\services\BaseServices;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\FormBuilder as Form;
use crmeb\services\HttpService;
use crmeb\services\auth\AuthSessionStore;
use crmeb\services\auth\AuthTokenV2;
use crmeb\services\auth\CredentialStamp;
use crmeb\services\auth\AccountChangeGuard;
use think\facade\Db;

/**
 * 获取token
 * Class LoginServices
 * @package app\services\kefu
 * @method get($id, ?array $field = [], ?array $with = []) 获取一条数据
 * @method update($id, array $data, ?string $key = null)
 * @method save(array $data)保存
 */
class OutAccountServices extends BaseServices
{
    /**
     * LoginServices constructor.
     * @param OutAccountDao $dao
     */
    public function __construct(OutAccountDao $dao)
    {
        $this->dao = $dao;
    }

    public static function isEnabled(array|\think\Model|null $account): bool
    {
        return $account !== null && (int)($account['status'] ?? 0) === 1
            && (int)($account['is_del'] ?? 0) === 0;
    }

    /** Preserve inherited CRUD output and freeze every sensitive-write target. */
    public function update($id, array $data, ?string $key = null)
    {
        if (array_intersect(['appsecret', 'status', 'is_del'], array_keys($data)) === []) {
            return $this->dao->update($id, $data, $key);
        }
        foreach (['status', 'is_del'] as $field) {
            if (array_key_exists($field, $data) && !in_array($data[$field], [0, 1, '0', '1'], true)) {
                throw new AdminException('参数错误');
            }
        }
        if (array_key_exists('appsecret', $data) && (!is_string($data['appsecret']) || $data['appsecret'] === '')) {
            throw new AdminException('参数错误');
        }
        $where = is_array($id) ? $id : [is_null($key) ? 'id' : $key => $id];
        if ($where === []) { throw new AdminException('参数错误'); }
        $ids = array_values(array_unique(array_map('strval', $this->dao->getColumn($where, 'id'))));
        if ($ids === []) {
            if (!is_array($id)) { throw new AdminException('没有此账号'); }
            // Keep Model::update's normal empty-match return without open SQL.
            return $this->dao->update([['id', '=', null]], $data);
        }
        return AccountChangeGuard::runMany('outapi', $ids, function () use ($ids, $data) {
            $result = $this->dao->update([['id', 'in', $ids]], $data);
            if ($result === false) { throw new AdminException('保存失败'); }
            foreach ($ids as $subjectId) {
                $row = $this->dao->get($subjectId);
                if (!$row) { throw new AdminException('保存失败'); }
                $stored = $row->getData();
                foreach ($data as $field => $value) {
                    if (!array_key_exists($field, $stored)
                        || ($value === null ? $stored[$field] !== null : (string)$stored[$field] !== (string)$value)) {
                        throw new AdminException('保存失败');
                    }
                }
            }
            return $result;
        });
    }

    /**
     * 账号密码登录
     * @param string $appid
     * @param string $appsecret
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function authLogin(string $appid, ?string $appsecret = null): array
    {
        // Dependency/configuration failure precedes credential enumeration.
        $store = AuthSessionStore::fromConfig();
        $tokens = new AuthTokenV2($store);
        if ($appsecret === null || $appsecret === '') {
            throw new AuthSessionException();
        }
        try {
            $authInfo = $this->dao->get(['appid' => $appid, 'is_del' => 0]);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        if (!$authInfo) {
            throw new AuthSessionException();
        }
        $validatedDigest = (string)$authInfo['appsecret'];
        // In particular, string "0" must be checked, not treated as absent.
        if ($validatedDigest === '' || !password_verify($appsecret, $validatedDigest)
            || !self::isEnabled($authInfo)) {
            throw new AuthSessionException();
        }
        $id = (string)$authInfo['id'];
        $version = $store->initAccount('outapi', $id);
        $current = $this->currentCredentialAccount($id);
        if (!hash_equals($validatedDigest, (string)$current['appsecret'])) {
            throw new AuthSessionException();
        }
        // Task 6 protects subsequent credential/state writers; issue's CAS
        // rejects a changed version or blocked account after the fresh read.
        $tokenInfo = $tokens->issue('outapi', $id, $version,
            CredentialStamp::for('outapi', $id, $validatedDigest));
        $this->updateAuthenticationStats($id);
        return [
            'access_token' => $tokenInfo['token'],
            'exp_time' => $tokenInfo['exp'],
            'auth_info' => $current->hidden(['appsecret', 'apppwd', 'push_password',
                'ip', 'is_del', 'add_time', 'status', 'last_time'])->toArray()
        ];
    }

    /**
     * 解析token
     * @param string $token
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function parseToken(string $token): array
    {
        // The current subject lives only in this invocation's callback scope.
        $authInfo = null;
        AuthTokenV2::fromConfig()->verify('outapi', $token, function (string $id) use (&$authInfo): string {
            $authInfo = $this->currentCredentialAccount($id);
            return (string)$authInfo['appsecret'];
        });
        return $authInfo->hidden(['appsecret', 'apppwd', 'push_password',
            'ip', 'is_del', 'add_time', 'status', 'last_time'])->toArray();
    }

    /**
     * 获取一条
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOne($where = [])
    {
        $info = $this->dao->getOne($where);
        return $info ? $info->toArray() : [];
    }

    /**
     * 获取列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where = [])
    {
        [$page, $limit] = $this->getPageValue();
        $where['is_del'] = 0;
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        if ($list) {
            foreach ($list as &$item) {
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '暂无';
                $item['last_time'] = $item['last_time'] ? date('Y-m-d H:i:s', $item['last_time']) : '暂无';
                $item['rules'] = is_null($item['rules']) ? [] : explode(',', $item['rules']);
            }
        }
        return compact('count', 'list');
    }

    /**
     * 刷新token
     * @param string $token
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refresh(string $token): array
    {
        $authInfo = null;
        $tokenInfo = AuthTokenV2::fromConfig()->refresh('outapi', $token, function (string $id) use (&$authInfo): string {
            $authInfo = $this->currentCredentialAccount($id);
            return (string)$authInfo['appsecret'];
        });
        // A refresh reply is returned only after the single-use exchange and
        // statistics are acknowledged. No retry, old-sid revival or compensation.
        $this->updateAuthenticationStats((string)$authInfo['id']);
        return [
            'access_token' => $tokenInfo['token'],
            'exp_time' => $tokenInfo['exp'],
        ];
    }

    /** Read current credentials/status without mutating account or caches. */
    private function currentCredentialAccount(string $id)
    {
        try {
            $authInfo = $this->dao->getOne(['id' => $id, 'is_del' => 0]);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        if (!self::isEnabled($authInfo) || (string)$authInfo['appsecret'] === '') {
            throw new AuthSessionException();
        }
        return $authInfo;
    }

    /** Write only success statistics, never save a stale credential model. */
    private function updateAuthenticationStats(string $id): void
    {
        try {
            Db::name('out_account')->where('id', $id)->update([
                'last_time' => time(),
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $error) {
            // The acknowledged replacement may remain when DB write fails.
            // Do not return its token, log private context, retry or compensate.
            throw new AuthUnavailableException();
        }
        // An unchanged last_time/ip in the same second is a legitimate zero
        // affected-row result, not a dependency failure or another token issue.
    }

    /**
     * 设置账号推送接口
     * @param $id
     * @param $data
     * @return \crmeb\basic\BaseModel
     */
    public function outSetUpSave($id, $data)
    {
        return $this->dao->update($id, $data);
    }

    /**
     * 测试获取token接口
     * @param $data
     * @return int[]|mixed
     */
    public function textOutUrl($data)
    {
        if (!$data['push_account'] || !$data['push_password'] || !$data['push_token_url']) throw new AdminException('参数错误');
        $param = ['push_account' => $data['push_account'], 'push_password' => $data['push_password']];
        $res = HttpService::getRequest($data['push_token_url'], $param);
        $res = $res ? json_decode($res, true) : ['status' => 400];
        if (!isset($res['status']) && $res['status'] != 200) {
            throw new AdminException('设置失败');
        } else {
            return $res['data'];
        }
    }
}
