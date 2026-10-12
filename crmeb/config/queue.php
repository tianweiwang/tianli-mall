<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------
use think\facade\Env;

$redisQueue = [
    'driver'     => 'redis',
    'queue'      => 'CRMEB' . Env::get('queue.queue_name', ''),
    'host'       => Env::get('redis.redis_hostname', '127.0.0.1'),
    'port'       => Env::get('redis.port', 6379),
    'password'   => Env::get('redis.redis_password', ''),
    'select'     => Env::get('redis.select', 0),
    'timeout'    => 0,
    'persistent' => false,
];
$marketplaceDriver = \app\services\marketplace\queue\MarketplaceRedis::class;
$marketplaceQueue = array_replace($redisQueue, [
    'driver' => $marketplaceDriver, 'queue' => 'marketplace',
    'timeout' => 2.0, 'read_timeout' => 1.0, 'persistent' => false,
    'retry_after' => 60, 'block_for' => null,
]);

return [
    'default'     => 'redis',
    'prefix'      => 'crmeb_',
    'connections' => [
        'sync'     => [
            'driver' => 'sync',
        ],
        'database' => [
            'driver' => 'database',
            'queue'  => 'default',
            'table'  => 'jobs',
        ],
        'redis' => $redisQueue,
        'marketplace' => $marketplaceQueue,
        // The actual Queue factory indexes __make options by driver FQCN.
        $marketplaceDriver => $marketplaceQueue,
    ],
    'failed'      => [
        'type'  => 'database',
        'table' => 'failed_jobs',
    ],
];
