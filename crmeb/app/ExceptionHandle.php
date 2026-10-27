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

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;
use crmeb\exceptions\MarketplaceSchemaException;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        MarketplaceSchemaException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // Keep CLI ignore rules while avoiding raw exception messages in the log sink.
        if ($this->app->runningInConsole()) {
            if (!$this->isIgnoreReport($exception)) {
                \crmeb\services\RuntimeLog::consoleException($this->app, $exception);
            }
            return;
        }
        $denied = ($exception instanceof \crmeb\exceptions\AuthException
                && in_array($exception->getCode(), [401, 403], true))
            || ($exception instanceof \think\exception\HttpException
                && in_array($exception->getStatusCode(), [401, 403], true));
        if ($denied || !$this->isIgnoreReport($exception)) {
            \crmeb\services\RuntimeLog::exception($this->app, $exception);
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof MarketplaceSchemaException) {
            return Response::create(['status' => 503, 'msg' => $e->getMessage(), 'data' => []], 'json', 503)
                ->header(['Cache-Control' => 'no-store']);
        }
        // 添加自定义异常处理机制

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }

    public function renderForConsole(\think\console\Output $output, Throwable $e): void
    {
        if ($e instanceof MarketplaceSchemaException) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(2);
        }
        parent::renderForConsole($output, $e);
    }
}
