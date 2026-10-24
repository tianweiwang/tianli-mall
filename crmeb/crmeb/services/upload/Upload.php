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

    /** Server-qualified typed media only; the legacy stream defaults stay unchanged. */
    public function streamTyped($bytes, string $key, string $qualifiedMime)
    {
        if (!in_array($qualifiedMime, [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            'image/x-icon', 'image/vnd.microsoft.icon',
            'video/mp4', 'application/mp4',
            'audio/wav', 'audio/wave', 'audio/x-wav', 'audio/vnd.wave',
            'audio/mpeg', 'audio/mp3', 'audio/x-mp3', 'audio/amr', 'audio/amr-wb',
            'audio/x-ms-wma', 'audio/x-ms-asf', 'video/x-ms-asf', 'application/vnd.ms-asf',
        ], true)) {
            throw new \InvalidArgumentException('Unsupported typed media MIME');
        }
        return $this->driver()->stream($bytes, $key, $qualifiedMime);
    }

    /**
     * 设置默认上传类型
     * @return mixed
     */
    protected function getDefaultDriver()
    {
        return Config::get('upload.default', 'local');
    }


}
