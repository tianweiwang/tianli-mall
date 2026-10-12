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

namespace app\adminapi;


use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use think\db\exception\DbException;
use think\exception\Handle;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\facade\Env;
use think\facade\Log;
use think\Response;
use Throwable;

class AdminApiExceptionHandle extends Handle
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
        if (\app\services\marketplace\PlatformAccessServices::isMarketplace($this->app->request)) {
            if ($exception instanceof AuthSessionException
                || ($exception instanceof \think\exception\HttpException
                    && in_array($exception->getStatusCode(), [401, 403, 404, 422], true))) return;
            try {
                $this->app->log->record($exception instanceof AuthUnavailableException
                    ? 'marketplace.auth_unavailable' : 'marketplace.unhandled_exception', 'error');
            } catch (Throwable $loggingError) {}
            return;
        }
        if (!$this->isIgnoreReport($exception)) {
            try {
                $data = [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'message' => $this->getMessage($exception),
                    'code' => $this->getCode($exception),
                ];

                //日志内容
                $log = [
                    request()->adminId(),                                                                 //管理员ID
                    request()->ip(),                                                                      //客户ip
                    ceil(msectime() - (request()->time(true) * 1000)),                               //耗时（毫秒）
                    request()->rule()->getMethod(),                                                       //请求类型
                    str_replace("/", "", request()->rootUrl()),                             //应用
                    request()->baseUrl(),                                                                 //路由
                    json_encode(request()->param(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),//请求参数
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),             //报错数据

                ];
                Log::write(implode("|", $log), "error");
            } catch (\Throwable $e) {
                Log::write($e->getMessage(), "error");
            }
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
        if (\app\services\marketplace\PlatformAccessServices::isMarketplace($request)) {
            $status = 503;
            if ($e instanceof AuthSessionException) $status = 401;
            elseif ($e instanceof \think\exception\HttpException
                && in_array($e->getStatusCode(), [401, 403, 404, 422], true)) $status = $e->getStatusCode();
            $messages = [401=>'认证信息无效，请重新登录', 403=>'没有权限访问', 404=>'资源不存在',
                422=>'请求参数不正确', 503=>'服务暂不可用'];
            return Response::create(['status'=>$status,'msg'=>$messages[$status],'data'=>[]], 'json', $status);
        }
        // Controlled v2 errors bypass translation/cache IO and debug context.
        // Both subclasses stay under AuthException's existing report ignore.
        if ($e instanceof AuthUnavailableException) {
            return Response::create(['status' => 503, 'msg' => '认证服务暂不可用', 'data' => []], 'json', 200);
        }
        if ($e instanceof AuthSessionException) {
            return Response::create(['status' => 401, 'msg' => '认证信息无效，请重新登录', 'data' => []], 'json', 200);
        }
        if ($e instanceof HttpResponseException) {
            return parent::render($request, $e);
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
        if ($e instanceof AuthException || $e instanceof AdminException || $e instanceof ApiException || $e instanceof ValidateException) {
            return app('json')->make($e->getCode() ?: 400, $message, $massageData);
        } else {
            return app('json')->fail($message, $massageData);
        }
    }

}
