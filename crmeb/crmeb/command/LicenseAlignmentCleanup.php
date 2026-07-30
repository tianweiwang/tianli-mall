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

namespace crmeb\command;

use app\services\system\log\SystemFileServices;
use crmeb\services\CacheService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

class LicenseAlignmentCleanup extends Command
{
    protected function configure()
    {
        $this->setName('license:cleanup')
            ->setDescription('清理历史商业授权元数据');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = Config::get('database.default');
        $prefix = Config::get('database.connections.' . $connection . '.prefix');
        $fileInfoPaths = [
            '/app/services/system/SystemAuthServices.php',
            '/app/adminapi/controller/v1/system/SystemUpgradeClient.php',
            '/crmeb/services/UpgradeService.php',
            '/crmeb/services/UpgradeOpenApiService.php',
        ];
        $fileMd5Paths = array_map(function ($path) {
            return ltrim($path, '/');
        }, $fileInfoPaths);

        $affected = Db::transaction(function () use ($fileInfoPaths, $fileMd5Paths) {
            return [
                'system_route' => Db::name('system_route')
                    ->whereIn('path', ['check_auth', 'auth_apply', 'auth'])
                    ->delete(),
                'system_config' => Db::name('system_config')
                    ->where('menu_name', 'cert_crmeb')
                    ->delete(),
                'lang_code' => Db::name('lang_code')
                    ->whereIn('code', ['400330', '400331', '400332', '400333', '400334', '400335'])
                    ->delete(),
                'system_menus' => Db::name('system_menus')
                    ->where('id', 605)
                    ->update([
                        'menu_name' => '系统信息',
                        'url' => '/system/maintain/info',
                        'unique_auth' => 'system-maintain-info',
                        'mark' => '系统信息',
                    ]),
                'system_file_info' => Db::name('system_file_info')
                    ->whereIn('full_path', $fileInfoPaths)
                    ->delete(),
                'system_file_md5' => Db::name('system_file_md5')
                    ->whereIn('filename', $fileMd5Paths)
                    ->delete(),
            ];
        });

        $cacheDeleted = 0;
        if (!(bool) Config::get('upgrade.remote_enabled', false)) {
            $cacheDeleted = CacheService::delete('upgrade_auth_token') ? 1 : 0;
        }

        app()->make(SystemFileServices::class)->writeMd5();

        $output->info('database: ' . $connection);
        $output->info('prefix: ' . $prefix);
        foreach ($affected as $table => $count) {
            $output->info($table . ': ' . $count);
        }
        $output->info('upgrade_auth_token: ' . $cacheDeleted);
        $output->info('system_file_md5_rebuilt: 1');

        return 0;
    }
}
