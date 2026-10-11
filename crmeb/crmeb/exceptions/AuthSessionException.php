<?php
declare(strict_types=1);

namespace crmeb\exceptions;

/** Controlled v2 rejection without translation/cache IO or sensitive context. */
class AuthSessionException extends AuthException
{
    public function __construct()
    {
        \RuntimeException::__construct('认证信息无效，请重新登录', 401);
    }
}
