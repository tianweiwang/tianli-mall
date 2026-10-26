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

namespace app\api;


use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\ApiStatusException;
use crmeb\exceptions\AuthException;
use think\db\exception\DbException;
use think\exception\Handle;
use think\facade\Env;
use think\Response;
use Throwable;
use think\exception\ValidateException;

class ApiExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        ValidateException::class,
        AuthException::class,
        AdminException::class,
        ApiException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     * @access public
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        $denied = ($exception instanceof \crmeb\exceptions\AuthException
                && in_array($exception->getCode(), [401, 403], true))
            || ($exception instanceof \think\exception\HttpException
                && in_array($exception->getStatusCode(), [401, 403], true));
        if ($denied) {
            \crmeb\services\RuntimeLog::exception($this->app, $exception);
            return;
        }
        if (\app\services\marketplace\assets\PlatformChatAssetServices::isPrivateHttpRequest($this->app->request)) {
            \crmeb\services\RuntimeLog::exception($this->app, $exception);
            return;
        }
        if ($exception instanceof \app\services\marketplace\freight\MerchantFreightException) return;
        if (!$this->isIgnoreReport($exception)) {
            \crmeb\services\RuntimeLog::exception($this->app, $exception);
        }
    }

    /**
     * Render an exception into an HTTP response.
     * @access public
     * @param \think\Request $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        if (\app\services\marketplace\assets\PlatformChatAssetServices::isPrivateHttpRequest($request)) {
            $status = $e instanceof AuthException && in_array($e->getCode(), [401, 403], true) ? $e->getCode() : 503;
            $messages = [401 => '认证信息无效，请重新登录', 403 => '没有权限访问', 503 => '聊天图片服务暂不可用'];
            return Response::create(['status' => $status, 'msg' => $messages[$status], 'data' => []], 'json', $status)
                ->allowCache(false)->header(['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
        }
        if ($e instanceof \app\services\marketplace\freight\MerchantFreightException) {
            return Response::create(['status'=>$e->getCode(),'msg'=>$e->getMessage(),'data'=>[]], 'json', $e->getCode());
        }
        $massageData = Env::get('app_debug', false) ? [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace(),
            'previous' => $e->getPrevious(),
        ] : [];
        $message = $e->getMessage();
        // 添加自定义异常处理机制
        if ($e instanceof ApiStatusException) {
            return app('json')->status($e->getApiStatus(), $message, $e->getApiData());
        }
        if ($e instanceof AuthException || $e instanceof AdminException || $e instanceof ApiException || $e instanceof ValidateException) {
            return app('json')->make($e->getCode() ?: 400, $message, $massageData);
        }
        return app('json')->fail($message, $massageData);
    }

}
