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
namespace crmeb\services\upload;

use crmeb\basic\BaseManager;
use think\facade\Config;

/**
 * Class Upload
 * @package crmeb\services\upload
 * @mixin \crmeb\services\upload\storage\Local
 * @mixin \crmeb\services\upload\storage\OSS
 * @mixin \crmeb\services\upload\storage\COS
 * @mixin \crmeb\services\upload\storage\Qiniu
 * @mixin \crmeb\services\upload\storage\Jdoss
 * @mixin \crmeb\services\upload\storage\Tyoss
 */
class Upload extends BaseManager
{
    /**
     * 空间名
     * @var string
     */
    protected $namespace = '\\crmeb\\services\\upload\\storage\\';

    /**
     * 设置默认上传类型
     * @return mixed
     */
    protected function getDefaultDriver()
    {
        return Config::get('upload.default', 'local');
    }


}
