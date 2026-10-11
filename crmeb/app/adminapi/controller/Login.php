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
namespace app\adminapi\controller;

use crmeb\services\CacheService;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AuthSessionStore;
use think\facade\App;
use think\facade\Db;
use crmeb\utils\Captcha;
use app\services\system\admin\SystemAdminServices;

/**
 * 后台登陆
 * Class Login
 * @package app\adminapi\controller
 */
class Login extends AuthController
{

    /**
     * @var SystemAdminServices
     */
    protected $services;

    /**
     * Login constructor.
     * @param App $app
     * @param SystemAdminServices $services
     */
    public function __construct(App $app, SystemAdminServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    protected function initialize()
    {
        // TODO: Implement initialize() method.
    }

    /**
     * 验证码
     * @return $this|\think\Response
     */
    public function captcha()
    {
        return app()->make(Captcha::class)->create();
    }

    /**
     * @return mixed
     */
    public function ajcaptcha()
    {
        $captchaType = $this->request->get('captchaType');
        return app('json')->success(aj_captcha_create($captchaType));
    }

    /**
     * 一次验证
     * @return mixed
     */
    public function ajcheck()
    {
        [$token, $pointJson, $captchaType] = $this->request->postMore([
            ['token', ''],
            ['pointJson', ''],
            ['captchaType', ''],
        ], true);
        try {
            aj_captcha_check_one($captchaType, $token, $pointJson);
            return app('json')->success();
        } catch (\Throwable $e) {
            return app('json')->fail('验证码错误');
        }
    }

    /**
     * 登陆
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function login()
    {
        // Request::postMore calls filter_str/sys_config even with filter=false.
        // Fail before that cache/DB-dependent input filtering when deployment
        // configuration or the bounded Redis connection is unavailable.
        AuthSessionStore::fromConfig();
        try {
            // getPdo() merely observes an existing handle; connect() is the
            // public PDOConnection method that actually establishes one.
            Db::connect()->connect();
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
        [$account, $password, $key, $captchaVerification, $captchaType] = $this->request->postMore([
            'account',
            'pwd',
            ['key', ''],
            ['captchaVerification', ''],
            ['captchaType', '']
        ], true);

        if ($captchaVerification != '') {
            try {
                aj_captcha_check_two($captchaType, $captchaVerification);
            } catch (\Throwable $e) {
                return app('json')->fail('验证码错误');
            }
        }

        if (strlen(trim($password)) < 6 || strlen(trim($password)) > 32) {
            return app('json')->fail('账号密码必须是在6到32位之间');
        }

        $this->validate(['account' => $account, 'pwd' => $password], \app\adminapi\validate\setting\SystemAdminValidata::class, 'get');
        $result = $this->services->login($account, $password, 'admin', $key);
        if (!$result) {
            $num = CacheService::get('login_captcha', 1);
            if ($num > 1) {
                return app('json')->fail('账号或密码错误', ['login_captcha' => 1]);
            }
            CacheService::set('login_captcha', $num + 1, 60);
            return app('json')->fail('账号或密码错误', ['login_captcha' => 0]);
        }
        CacheService::delete('login_captcha');
        return app('json')->success($result);
    }

    /**
     * 获取后台登录页轮播图以及LOGO
     * @return mixed
     */
    public function info()
    {
        return app('json')->success($this->services->getLoginInfo());
    }
}
