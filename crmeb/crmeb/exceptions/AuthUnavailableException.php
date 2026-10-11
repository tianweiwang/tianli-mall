<?php
declare(strict_types=1);

namespace crmeb\exceptions;

/** Do not preserve driver messages or previous exceptions in auth failures. */
class AuthUnavailableException extends AuthException
{
    public function __construct()
    {
        \RuntimeException::__construct('认证服务暂不可用', 503);
    }
}
