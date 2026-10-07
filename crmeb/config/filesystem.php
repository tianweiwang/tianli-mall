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
use think\facade\Env;

return [
    'default' => Env::get('filesystem.driver', 'public'),
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => app()->getRuntimePath() . 'storage',
        ],
        'public' => [
            'type'       => 'local',
            'root'       => app()->getRootPath() . 'public/uploads',
            'url'        => '/uploads',
            'visibility' => 'public',
        ],
        'pem' => [
            'type'       => 'local',
            'root'       => app()->getRootPath() . 'runtime/pem',
            'url'        => '',
        ],
        // 更多的磁盘配置信息
    ],
    //系统开发密码
    'password' => ''
];
