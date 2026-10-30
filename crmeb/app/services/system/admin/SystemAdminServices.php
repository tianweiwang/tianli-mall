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

use app\jobs\CheckQueueJob;
use app\services\BaseServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\system\log\SystemFileServices;
use app\services\user\UserExtractServices;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use app\dao\system\admin\SystemAdminDao;
use app\services\system\SystemMenusServices;
use app\services\other\CacheServices;
use crmeb\services\CacheService;
use crmeb\services\auth\AuthSessionStore;
use crmeb\services\auth\AuthTokenV2;
use crmeb\services\auth\CredentialStamp;
use crmeb\services\auth\AccountChangeGuard;
use crmeb\services\FormBuilder;
use crmeb\services\workerman\ChannelService;
use think\facade\Config;
use think\facade\Db;
use think\facade\Event;
use think\Model;

/**
 * 管理员service
 * Class SystemAdminServices
 * @package app\services\system\admin
 * @method getAdminIds(int $level) 根据管理员等级获取管理员id
 * @method getOrdAdmin(string $field, int $level) 获取低于等级的管理员名称和id
 */
class SystemAdminServices extends BaseServices
{

    /**
     * form表单创建
     * @var FormBuilder
     */
    protected $builder;

    /**
     * SystemAdminServices constructor.
     * @param SystemAdminDao $dao
     */
    public function __construct(SystemAdminDao $dao, FormBuilder $builder)
    {
        $this->dao = $dao;
        $this->builder = $builder;
    }

    /**
     * 管理员登陆
     * @param string $account
     * @param string $password
     * @return array|bool|Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function verifyLogin(string $account, string $password)
    {
        $adminInfo = $this->dao->accountByAdmin($account);
        if (!$adminInfo || !password_verify($password, $adminInfo->pwd)) return false;
        if (!$adminInfo->status) {
            throw new AdminException('您已被禁止登录');
        }
        $adminInfo->last_time = time();
        $adminInfo->last_ip = app('request')->ip();
        $adminInfo->login_count++;
        $adminInfo->save();

        return $adminInfo;
    }

    /**
     * Read credential state without the legacy verifyLogin statistics write.
     * Only the main v2 login uses this helper; public verifyLogin is unchanged.
     */
    private function readLoginCredentials(string $account, string $password)
    {
        try {
            $adminInfo = $this->dao->accountByAdmin($account);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        if (!$adminInfo || !password_verify($password, (string)$adminInfo->pwd)) {
            return false;
        }

        return $adminInfo;
    }

    /** Use the actual DAO match instead of guessing its database collation. */
    public function loginFailureIdentity(string $account): string
    {
        try {
            $adminInfo = $this->dao->accountByAdmin($account);
            if ($adminInfo) {
                $id = (string)$adminInfo->getData('id');
                $storedAccount = $adminInfo->getData('account');
                if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1 || !is_string($storedAccount)) {
                    throw new \RuntimeException();
                }
                $identity = ['kind' => 'known', 'id' => $id, 'account' => $storedAccount];
            } else {
                // Login's existing alphaDash scene has already accepted input.
                $identity = ['kind' => 'missing', 'account' => strtolower($account)];
            }
            return json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /**
     * 文件管理员登陆
     * @param string $account
     * @param string $password
     * @return array|Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function verifyFileLogin(string $account, string $password)
    {
        $adminInfo = $this->dao->accountByAdmin($account);
        if (!$adminInfo) {
            throw new AdminException('管理员不存在');
        }
        if (!$adminInfo->status) {
            throw new AdminException('您已被禁止登录');
        }
        if (!password_verify($password, $adminInfo->file_pwd)) {
            throw new AdminException('账号或密码错误');
        }
        $adminInfo->last_time = time();
        $adminInfo->last_ip = app('request')->ip();
        $adminInfo->login_count++;
        $adminInfo->save();

        return $adminInfo;
    }

    /**
     * 后台登陆获取菜单获取token
     * @param string $account
     * @param string $password
     * @param string $type
     * @return array|bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function login(string $account, string $password, string $type, string $key = '')
    {
        // Validate deployment configuration and the bounded, independent Redis
        // connection before credential queries, statistics, menus or caches.
        $store = AuthSessionStore::fromConfig();
        $tokens = new AuthTokenV2($store);
        if ($type !== 'admin') {
            throw new AuthSessionException();
        }
        $adminInfo = $this->readLoginCredentials($account, $password);
        if (!$adminInfo) return false;
        if ((int)$adminInfo->status !== 1) {
            // Retain the existing disabled-credential login response.
            throw new AdminException('您已被禁止登录');
        }
        if ((int)$adminInfo->is_del !== 0) {
            return false;
        }
        $id = (string)$adminInfo->id;
        $validatedDigest = (string)$adminInfo->pwd;
        $version = $store->initAccount('adminapi', $id);

        // Never pair an earlier password_verify result with a newer digest.
        // A change after this read is rejected by createSession's version CAS
        // once all credential/state writers use the Task 6 change guard.
        try {
            $current = $this->dao->get($id);
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        if (!$current || !$current->id || (int)$current->status !== 1
            || (int)$current->is_del !== 0
            || !hash_equals($validatedDigest, (string)$current->pwd)) {
            throw new AuthSessionException();
        }
        $adminInfo = $current;
        $tokenInfo = $tokens->issue('adminapi', $id, $version,
            CredentialStamp::for('adminapi', $id, $validatedDigest));

        // Persist only statistics after the session write has been confirmed.
        // Do not save the earlier model and overwrite concurrent account data.
        try {
            $updated = Db::name('system_admin')->where('id', $id)->update([
                'last_time' => time(),
                'last_ip' => app('request')->ip(),
                'login_count' => Db::raw('login_count + 1'),
            ]);
        } catch (\Throwable $error) {
            // An acknowledged session may remain, but an unacknowledged login
            // never returns its token or retries/compensates the dependency.
            throw new AuthUnavailableException();
        }
        if ($updated !== 1) {
            throw new AuthSessionException();
        }
        /** @var SystemMenusServices $services */
        $services = app()->make(SystemMenusServices::class);
        [$menus, $uniqueAuth] = $services->getMenusList($adminInfo->roles, (int)$adminInfo['level']);
        $remind = Config::get('app.console_remind', false);
        if ($remind) {
            [$queue, $timer] = Event::until('AdminLoginListener', [$key]);
        }

        //自定义事件-管理员登录
        event('CustomEventListener', ['admin_login', [
            'id' => $adminInfo->getData('id'),
            'account' => $adminInfo->getData('account'),
            'head_pic' => get_file_link($adminInfo->getData('head_pic')),
            'level' => $adminInfo->getData('level'),
            'real_name' => $adminInfo->getData('real_name'),
            'login_time' => date('Y-m-d H:i:s'),
        ]]);

        return [
            'token' => $tokenInfo['token'],
            'expires_time' => $tokenInfo['exp'],
            'menus' => $menus,
            'unique_auth' => $uniqueAuth,
            'user_info' => [
                'id' => $adminInfo->getData('id'),
                'account' => $adminInfo->getData('account'),
                'head_pic' => get_file_link($adminInfo->getData('head_pic')),
                'level' => $adminInfo->getData('level'),
                'real_name' => $adminInfo->getData('real_name'),
            ],
            'logo' => sys_config('site_logo'),
            'logo_square' => sys_config('site_logo_square'),
            'version' => get_crmeb_version(),
            'newOrderAudioLink' => get_file_link(sys_config('new_order_audio_link', '')),
            'queue' => $queue ?? true,
            'timer' => $timer ?? true,
            'site_name' => sys_config('site_name'),
            'site_func' => sys_config('model_checkbox', ['seckill', 'bargain', 'combination']),
        ];
    }

    /**
     * 获取登陆前的login等信息
     * @return array
     */
    public function getLoginInfo()
    {
        $key = uniqid();
        CheckQueueJob::dispatchSecs(1, [$key]);
        $data = [
            'slide' => sys_data('admin_login_slide') ?? [],
            'logo_square' => sys_config('site_logo_square'), //透明
            'logo_rectangle' => sys_config('site_logo'), //方形
            'login_logo' => sys_config('login_logo'), //登陆
            'site_name' => sys_config('site_name'),
            'copyright' => sys_config('nncnL_crmeb_copyright', ''),
            'version' => get_crmeb_version(),
            'key' => $key,
            'login_captcha' => 0
        ];

        try {
            $cacheServices = app()->make(CacheServices::class);
            if (!$cacheServices->checkDbCache('write_md5', get_crmeb_version_vode())) {
                // 执行写入数据
                app()->make(SystemFileServices::class)->writeMd5();
                $cacheServices->setDbCache('write_md5', get_crmeb_version_vode());
            }
        } catch (\ReflectionException $e) {
        }

        return $data;
    }

    /**
     * 管理员列表
     * @param array $where
     * @return array
     */
    public function getAdminList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);

        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $allRole = $service->getRoleArray();
        foreach ($list as &$item) {
            unset($item['pwd']);
            if ($item['roles']) {
                $roles = [];
                foreach ($item['roles'] as $id) {
                    if (isset($allRole[$id])) $roles[] = $allRole[$id];
                }
                if ($roles) {
                    $item['roles'] = implode(',', $roles);
                } else {
                    $item['roles'] = '';
                }
            }
            $item['_add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['_last_time'] = $item['last_time'] ? date('Y-m-d H:i:s', $item['last_time']) : '';
        }
        return compact('list', 'count');
    }

    /**
     * 创建管理员表单
     * @param int $level
     * @param array $formData
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function createAdminForm(int $level, array $formData = [])
    {
        $f[] = $this->builder->input('account', '管理员账号', $formData['account'] ?? '')->required('请填写管理员账号');
        if (empty($formData)) {
            $f[] = $this->builder->input('pwd', '管理员密码')->type('password')->required('请填写管理员密码');
            $f[] = $this->builder->input('conf_pwd', '确认密码')->type('password')->required('请输入确认密码');
        } else {
            $f[] = $this->builder->input('pwd', '管理员密码')->type('password');
            $f[] = $this->builder->input('conf_pwd', '确认密码')->type('password');
        }
        $f[] = $this->builder->input('real_name', '管理员姓名', $formData['real_name'] ?? '')->required('请输入管理员姓名');

        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $options = $service->getRoleFormSelect($level);
        if (isset($formData['roles'])) {
            foreach ($formData['roles'] as &$item) {
                $item = intval($item);
            }
        }
        $f[] = $this->builder->select('roles', '管理员角色', $formData['roles'] ?? [])->setOptions(FormBuilder::setOptions($options))->multiple(true)->required('请选择管理员角色');
        $f[] = $this->builder->radio('status', '状态', $formData['status'] ?? 1)->options([['label' => '开启', 'value' => 1], ['label' => '关闭', 'value' => 0]]);
        return $f;
    }

    /**
     * 添加管理员form表单获取
     * @param int $level
     * @return array
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function createForm(int $level)
    {
        return create_form('管理员添加', $this->createAdminForm($level), $this->url('/setting/admin'));
    }

    /**
     * 创建管理员
     * @param array $data
     * @return bool
     */
    public function create(array $data)
    {
        if ($data['conf_pwd'] != $data['pwd']) {
            throw new AdminException('两次输入的密码不一致');
        }
        unset($data['conf_pwd']);

        if (strlen(trim($data['pwd'])) < 6 || strlen(trim($data['pwd'])) > 32) {
            throw new AdminException('账号密码必须是在6到32位之间');
        }

        if ($this->dao->count(['account' => $data['account'], 'is_del' => 0])) {
            throw new AdminException('管理员账号已存在');
        }

        $data['pwd'] = $this->passwordHash($data['pwd']);
        $data['add_time'] = time();
        $data['roles'] = implode(',', $data['roles']);
        $data['head_pic'] = '/statics/system_images/admin_head_pic.png';

        return $this->transaction(function () use ($data) {
            if ($this->dao->save($data)) {
                return true;
            } else {
                throw new AdminException('添加失败');
            }
        });
    }

    /**
     * 修改管理员表单
     * @param int $level
     * @param int $id
     * @return array
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function updateForm(int $level, int $id)
    {
        $adminInfo = $this->dao->get($id);
        if (!$adminInfo) {
            throw new AdminException('管理员不存在');
        }
        if ($adminInfo->is_del) {
            throw new AdminException('管理员已经删除');
        }
        return create_form('管理员修改', $this->createAdminForm($level, $adminInfo->toArray()), $this->url('/setting/admin/' . $id), 'PUT');
    }

    /**
     * 修改管理员
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function save(int $id, array $data)
    {
        $adminInfo = $this->adminForWrite($id);
        if (!array_key_exists('status', $data) || !in_array($data['status'], [0, 1, '0', '1'], true)) {
            throw new AdminException('参数错误');
        }
        $changes = [];
        if (!empty($data['pwd'])) {
            if (empty($data['conf_pwd'])) {
                throw new AdminException('请输入确认密码');
            }
            if ($data['conf_pwd'] != $data['pwd']) {
                throw new AdminException('两次输入的密码不一致');
            }
            if (strlen(trim($data['pwd'])) < 6 || strlen(trim($data['pwd'])) > 32) {
                throw new AdminException('账号密码必须是在6到32位之间');
            }
            $digest = $this->passwordHash($data['pwd']);
            if (!is_string($digest) || $digest === '') {
                throw new AdminException('密码处理失败');
            }
            if (!hash_equals((string)$adminInfo['pwd'], $digest)) {
                $changes['pwd'] = $digest;
            }
        }
        if (isset($data['account']) && $data['account'] != $adminInfo->account
            && $this->dao->isAccountUsable($data['account'], $id)) {
            throw new AdminException('管理员账号已存在');
        }
        foreach (['account', 'real_name'] as $field) {
            if (isset($data[$field])) { $changes[$field] = $data[$field]; }
        }
        if (isset($data['roles'])) { $changes['roles'] = implode(',', $data['roles']); }
        if ((int)$adminInfo['status'] !== (int)$data['status']) {
            $changes['status'] = (int)$data['status'];
        }
        // Unchanged sensitive fields are omitted from a profile-only save.
        $change = function () use ($id, $changes) {
            $current = $this->adminForWrite($id);
            if (isset($changes['account']) && $changes['account'] != $current->account
                && $this->dao->isAccountUsable($changes['account'], $id)) {
                throw new AdminException('管理员账号已存在');
            }
            foreach ($changes as $field => $value) { $current->$field = $value; }
            if ($current->save() === false) { throw new AdminException('修改失败'); }
            return true;
        };
        return isset($changes['pwd']) || isset($changes['status'])
            ? AccountChangeGuard::run('adminapi', (string)$id, $change) : $change();
    }

    /**
     * 修改当前管理员信息
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function updateAdmin(int $id, array $data)
    {
        $adminInfo = $this->adminForWrite($id, '管理员信息未查到');
        if (!$data['real_name']) { throw new AdminException('管理员姓名不能为空'); }
        $changes = ['real_name' => $data['real_name'], 'head_pic' => $data['head_pic']];
        $oldDigest = (string)$adminInfo['pwd'];
        if (!empty($data['pwd'])) {
            if (!password_verify($data['pwd'], $oldDigest)) { throw new AdminException('原始密码错误'); }
            if (empty($data['new_pwd'])) { throw new AdminException('请输入新密码'); }
            if (empty($data['conf_pwd'])) { throw new AdminException('请输入确认密码'); }
            if ($data['new_pwd'] != $data['conf_pwd']) { throw new AdminException('两次输入的密码不一致'); }
            $digest = $this->passwordHash($data['new_pwd']);
            if (!is_string($digest) || $digest === '') { throw new AdminException('密码处理失败'); }
            if (!hash_equals($oldDigest, $digest)) { $changes['pwd'] = $digest; }
        }
        $change = function () use ($id, $data, $changes, $oldDigest) {
            $current = $this->adminForWrite($id, '管理员信息未查到');
            if (isset($changes['pwd']) && (!hash_equals($oldDigest, (string)$current['pwd'])
                || !password_verify($data['pwd'], (string)$current['pwd']))) {
                throw new AdminException('原始密码错误');
            }
            foreach ($changes as $field => $value) { $current->$field = $value; }
            if ($current->save() === false) { throw new AdminException('修改失败'); }
            return true;
        };
        $result = isset($changes['pwd']) ? AccountChangeGuard::run('adminapi', (string)$id, $change) : $change();
        // Keep the existing cache clear after the actual business commit and
        // successful Guard completion. It is not evidence of an SQL commit.
        CacheService::clear();
        return $result;
    }

    /** Get a fresh model for a named writer; never save the validation model. */
    private function adminForWrite(int $id, string $missing = '管理员不存在')
    {
        $adminInfo = $this->dao->get($id);
        if (!$adminInfo) { throw new AdminException($missing); }
        if ($adminInfo->is_del) { throw new AdminException('管理员已经删除'); }
        return $adminInfo;
    }

    /** Preserve inherited DAO semantics while owning existing-subject writes. */
    public function update($id, array $data, ?string $key = null)
    {
        if (array_intersect(['pwd', 'status', 'is_del'], array_keys($data)) === []) {
            return $this->dao->update($id, $data, $key);
        }
        $this->assertAdminChangeData($data);
        $where = is_array($id) ? $id : [is_null($key) ? 'id' : $key => $id];
        if ($where === []) { throw new AdminException('参数错误'); }
        $ids = array_values(array_unique(array_map('strval', $this->dao->getColumn($where, 'id'))));
        if ($ids === []) {
            if (!is_array($id)) { throw new AdminException('管理员不存在'); }
            // Model::update returns a Model even for no matching rows. Integer
            // empty IN becomes IN(''), so use the impossible NOT NULL pk test.
            return $this->dao->update([['id', '=', null]], $data);
        }
        return AccountChangeGuard::runMany('adminapi', $ids, function () use ($ids, $data) {
            $result = $this->dao->update([['id', 'in', $ids]], $data);
            if ($result === false) { throw new AdminException('修改失败'); }
            // The ORM returns a Model and ignores save's bool; verify the exact
            // submitted fields instead of interpreting its truthiness/row count.
            foreach ($ids as $subjectId) {
                $row = $this->dao->get($subjectId);
                if (!$row) { throw new AdminException('修改失败'); }
                $stored = $row->getData();
                foreach ($data as $field => $value) {
                    if (!array_key_exists($field, $stored)
                        || ($value === null ? $stored[$field] !== null : (string)$stored[$field] !== (string)$value)) {
                        throw new AdminException('修改失败');
                    }
                }
            }
            return $result;
        });
    }

    /** Physical deletion remains scoped to the original frozen DAO predicate. */
    public function delete($id, ?string $key = null)
    {
        $where = is_array($id) ? $id : [is_null($key) ? 'id' : $key => $id];
        if ($where === []) { throw new AdminException('参数错误'); }
        $ids = array_values(array_unique(array_map('strval', $this->dao->getColumn($where, 'id'))));
        if ($ids === []) { return 0; }
        return AccountChangeGuard::runMany('adminapi', $ids, function () use ($ids) {
            $result = $this->dao->delete([['id', 'in', $ids]]);
            if ($result === false || $this->dao->getColumn([['id', 'in', $ids]], 'id') !== []) {
                throw new AdminException('删除失败');
            }
            return $result;
        });
    }

    /** Validate the existing writer's literal sensitive values before begin. */
    private function assertAdminChangeData(array $data): void
    {
        foreach (['status', 'is_del'] as $field) {
            if (array_key_exists($field, $data) && !in_array($data[$field], [0, 1, '0', '1'], true)) {
                throw new AdminException('参数错误');
            }
        }
        if (array_key_exists('pwd', $data) && (!is_string($data['pwd']) || $data['pwd'] === '')) {
            throw new AdminException('参数错误');
        }
    }

    /**
     * 设置当前管理员文件管理密码
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function setFilePassword(int $id, array $data)
    {
        $adminInfo = $this->dao->get($id);
        if (!$adminInfo)
            throw new AdminException('管理员信息未查到');
        if ($adminInfo->is_del) {
            throw new AdminException('管理员已经删除');
        }
        if ($data['file_pwd']) {
            if ($adminInfo->level != 0) throw new AdminException('没有权限');
            if (!$data['conf_file_pwd'])
                throw new AdminException('请输入确认密码');
            if ($data['file_pwd'] != $data['conf_file_pwd'])
                throw new AdminException('两次输入的密码不一致');
            $adminInfo->file_pwd = $this->passwordHash($data['file_pwd']);
        }
        if ($adminInfo->save())
            return true;
        else
            return false;
    }


    /** 后台订单下单，评论，支付成功，后台消息提醒
     * @param $event
     */
    public function adminNewPush()
    {
        try {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $data['ordernum'] = $orderServices->count(['is_del' => 0, 'status' => 1, 'shipping_type' => 1]);
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $data['inventory'] = $productServices->count(['type' => 5]);
            /** @var StoreProductReplyServices $replyServices */
            $replyServices = app()->make(StoreProductReplyServices::class);
            $data['commentnum'] = $replyServices->count(['is_reply' => 0]);
            /** @var UserExtractServices $extractServices */
            $extractServices = app()->make(UserExtractServices::class);
            $data['reflectnum'] = $extractServices->getCount(['status' => 0]); //提现
            $data['msgcount'] = intval($data['ordernum']) + intval($data['inventory']) + intval($data['commentnum']) + intval($data['reflectnum']);
            ChannelService::instance()->send('ADMIN_NEW_PUSH', $data);
        } catch (\Exception $e) {
        }
    }
}
