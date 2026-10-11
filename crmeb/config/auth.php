<?php
declare(strict_types=1);

use think\facade\Env;

return [
    // Deployment must provide independent values; no fallback authorizes v2.
    'session_epoch' => Env::get('auth.session_epoch', ''),
    'stamp_key' => Env::get('auth.stamp_key', ''),
    'issuer' => Env::get('auth.issuer', 'tianli-mall'),
    'apps' => [
        'adminapi' => ['type' => 'admin', 'audience' => 'adminapi', 'ttl' => 30 * 86400],
        'outapi' => ['type' => 'out', 'audience' => 'outapi', 'ttl' => 30 * 86400],
    ],
];
