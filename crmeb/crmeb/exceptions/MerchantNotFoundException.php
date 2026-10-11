<?php
declare(strict_types=1);

namespace crmeb\exceptions;

use think\exception\HttpException;

final class MerchantNotFoundException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, '资源不存在', null, [], 404);
    }
}
