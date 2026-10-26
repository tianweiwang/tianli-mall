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
namespace app;

use crmeb\compat\think\Db as CompatDb;
use crmeb\compat\CarbonErrorCompatibility;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\SystemConfigService;
use crmeb\services\GroupDataService;
use crmeb\utils\Json;
use crmeb\services\pay\marketplace\PaymentAccount;
use crmeb\services\pay\marketplace\PaymentAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use think\Service;

class AppService extends Service
{

    public $bind = [
        \think\Db::class => CompatDb::class,
        'json' => Json::class,
        'sysConfig' => SystemConfigService::class,
        'sysGroupData' => GroupDataService::class
    ];

    public function register()
    {
        // Lazy: ordinary component boot registers services before loading marketplace configuration.
        $this->app->bind(PaymentAdapter::class, function () {
            if(app()!==$this->app)throw new AuthUnavailableException();
            PaymentAccount::assertTesting();
            return $this->app->make(TestPaymentDriver::class);
        });
    }

    public function boot()
    {
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);
        \crmeb\services\marketplace\SchemaVersionRuntime::assertApp($this->app);
        if (PHP_VERSION_ID >= 80000 && $this->app->runningInConsole()
            && in_array($_SERVER['argv'][1] ?? '', ['queue:work', 'queue:listen'], true)) {
            CarbonErrorCompatibility::install($this->app->getRootPath() . 'vendor/nesbot/carbon/src');
        }
    }
}
