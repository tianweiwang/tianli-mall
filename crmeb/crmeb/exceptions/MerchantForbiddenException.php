<?php
declare(strict_types=1);

namespace crmeb\exceptions;

use think\exception\HttpException;

final class MerchantForbiddenException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, '没有此操作权限', null, [], 403);
    }
}
