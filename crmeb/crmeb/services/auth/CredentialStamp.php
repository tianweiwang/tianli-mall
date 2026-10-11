<?php
declare(strict_types=1);

namespace crmeb\services\auth;

use crmeb\exceptions\AuthSessionException;
use crmeb\services\AuthConfigGuard;

/** Credential material remains server-side and is bound to a fixed purpose. */
class CredentialStamp
{
    public static function for(string $app, string $id, string $credentialDigest): string
    {
        $config = AuthConfigGuard::authConfig();
        if (!in_array($app, ['adminapi', 'outapi'], true)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', $id)
            || $credentialDigest === '') {
            throw new AuthSessionException();
        }
        return hash_hmac('sha256', $app . '|' . $id . '|' . $credentialDigest, $config['stamp_key']);
    }
}
