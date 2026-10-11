<?php
declare(strict_types=1);

namespace crmeb\exceptions;

use think\exception\HttpException;

final class MerchantUnauthenticatedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(401, '请先登录商家账号', null, [], 401);
    }
}
