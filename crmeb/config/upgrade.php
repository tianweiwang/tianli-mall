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

/**
 * 升级配置
 * 版本列表按从小到大排列，升级时会按顺序执行
 */
// 官方在线升级（远程授权）已移除：它会把本站域名、app_id/app_key、版本号
// 上报至 upgrade.crmeb.net 换取访问令牌，与 Apache-2.0 声明不符，且本项目
// 通过镜像构建与回滚标签发布，不需要在线自动升级。
// 保留的能力：本地版本脚本升级、备份、文件 MD5 二开比对。
return [
    // 最低版本要求 (只有达到此版本才能使用跨版本升级脚本)
    'min_version' => [
        'version' => 'CRMEB-BZ v6.0.0',
        'code' => 600,
        'message' => '跨版本升级功能需要 v6.0.0 及以上版本才能使用，请先手动升级到 v6.0.0 版本'
    ],

    // 版本列表 (按版本从小到大排列)
    // version: 版本名称
    // code: 版本代码 (数字，用于比较)
    // file: 升级脚本文件名 (相对于 upgrade/versions/ 目录)
    // description: 版本描述
    'versions' => [
        [
            'version' => 'CRMEB-BZ v6.0.0',
            'code' => 600,
            'file' => 'v6.0.0.php',
            'description' => '性能优化版本'
        ],
    ],

    // 升级脚本目录
    'upgrade_path' => app()->getRootPath() . 'upgrade' . DIRECTORY_SEPARATOR . 'versions' . DIRECTORY_SEPARATOR,

    // 平台信息
    'platform' => 'CRMEB',

    // 备份配置
    'backup' => [
        'database' => true,  // 是否备份数据库
        'project' => true,   // 是否备份项目文件
        'path' => app()->getRootPath() . 'backup' . DIRECTORY_SEPARATOR,
    ],

    // 忽略的目录 (备份时)
    'ignore_dirs' => ['.', '..', '.git', '.idea', 'runtime', 'backup', 'upgrade'],

    // 忽略的文件扩展名
    'ignore_extensions' => ['zip', 'gz', 'log'],
];
