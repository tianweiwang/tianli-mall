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

namespace app\listener\http;

use crmeb\services\RuntimeLog;
use think\Response;

/**
 * 请求结束事件
 * Class Create
 * @package app\listener\http
 */
class HttpEndListener
{
    public function handle(Response $response): void
    {
        RuntimeLog::response(app(), $response);
    }
}
