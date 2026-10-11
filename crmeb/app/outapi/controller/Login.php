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
namespace app\outapi\controller;

use app\outapi\validate\LoginValidate;
use app\Request;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AuthSessionStore;
use think\facade\Db;
use think\facade\App;
use app\services\out\OutAccountServices;

/**
 * Class Login
 * @package app\out\controller
 */
class Login extends AuthController
{
    /**
     * OutAccount constructor.
     * @param App $app
     * @param OutAccountServices $services
     */
    public function __construct(App $app, OutAccountServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    protected function initialize()
    {
        // TODO: Implement initialize() method.
    }

    /** Check the actual authentication dependencies before public input handling. */
    private function assertAuthenticationDependencies(): void
    {
        AuthSessionStore::fromConfig();
        try {
            // This actual public method creates PDO; getPdo only observes it.
            Db::connect()->connect();
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /**
     * 客服登录
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getToken(Request $request)
    {
        $this->assertAuthenticationDependencies();
        [$appid, $appsecret] = $request->postMore([
            ['appid', ''],
            ['appsecret', ''],
        ], true);
        $this->validate(['appid' => $appid, 'appsecret' => $appsecret], LoginValidate::class);

        $token = $this->services->authLogin($appid, $appsecret);

        return app('json')->success('操作成功', $token);
    }

    /**
     * 刷新token
     * @return void
     */
    public function refreshToken(Request $request)
    {
        $this->assertAuthenticationDependencies();
        // Preserve signed bytes; postMore eagerly writes filtering caches even
        // for a schema-invalid token. Explicit null disables Think input filters.
        $token = $request->post('access_token', '', null);
        if (!is_string($token)) {
            throw new AuthSessionException();
        }
        $token = $this->services->refresh($token);
        return app('json')->success('操作成功', $token);
    }

}
