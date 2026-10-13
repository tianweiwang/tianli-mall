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

namespace app\services\other;

use app\services\system\config\SystemStorageServices;
use crmeb\exceptions\UploadException;
use crmeb\services\CacheService;
use crmeb\services\SystemConfigService;
use crmeb\services\upload\Upload;
use crmeb\utils\DownloadImage;

/**
 * Class UploadService
 * @package crmeb\services
 */
class UploadService
{

    /**
     * @var array
     */
    protected static $upload = [];

    /**
     * @param null $type
     * @return Upload|mixed
     */
    public static function init($type = null)
    {
        if (is_null($type)) {
            $type = (int)sys_config('upload_type', 1);
        }
        if (isset(self::$upload['upload_' . $type])) {
            return self::$upload['upload_' . $type];
        }
        $type = (int)$type;
        return self::$upload['upload_' . $type] = new Upload($type, self::configuration($type));
    }

    /** New request instance. Explicit storage is an internal, actual SystemStorage record. */
    public static function fresh(?int $type = null, ?array $storage = null): Upload
    {
        if ($type === null) $type = (int)sys_config('upload_type', 1);
        $driver=$type;
        if ($storage!==null) {
            $driver=match($type) {
                4=>\app\services\marketplace\attachment\MerchantObservedCosUpload::class,
                6=>\app\services\marketplace\attachment\MerchantObservedObsUpload::class,
                7=>\app\services\marketplace\attachment\MerchantObservedTyossUpload::class,
                default=>$type,
            };
        }
        return new Upload($driver, self::configuration($type, $storage));
    }

    private static function configuration(int $type, ?array $storage = null): array
    {
        $config = [];
        switch ($type) {
            case 2://七牛
                $config = [
                    'accessKey' => sys_config('qiniu_accessKey'),
                    'secretKey' => sys_config('qiniu_secretKey'),
                ];
                break;
            case 3:// oss 阿里云
                $config = [
                    'accessKey' => sys_config('accessKey'),
                    'secretKey' => sys_config('secretKey'),
                ];
                break;
            case 4:// cos 腾讯云
                $config = [
                    'accessKey' => sys_config('tengxun_accessKey'),
                    'secretKey' => sys_config('tengxun_secretKey'),
                    'appid' => sys_config('tengxun_appid'),
                ];
                break;
            case 5://京东云
                $config = [
                    'accessKey' => sys_config('jd_accessKey'),
                    'secretKey' => sys_config('jd_secretKey'),
                    'storageRegion' => sys_config('jd_storageRegion'),
                ];
                break;
            case 6://华为云
                $config = [
                    'accessKey' => sys_config('hw_accessKey'),
                    'secretKey' => sys_config('hw_secretKey'),
                ];
                break;
            case 7://天翼云
                $config = [
                    'accessKey' => sys_config('ty_accessKey'),
                    'secretKey' => sys_config('ty_secretKey'),
                ];
                break;
            case 1:
                break;
            default:
                throw new UploadException('您已关闭上传功能');
        }

        //除了本地存储其他都去获取配置信息
        if (1 !== $type) {
            /** @var SystemStorageServices $make */
            $make = app()->make(SystemStorageServices::class);
            $res = $storage ?? $make->getConfig($type);
            if ($storage !== null) {
                foreach (['name','region','domain','cdn'] as $key) {
                    if (!is_string($storage[$key] ?? null)) throw new UploadException('存储状态暂不可用');
                }
            }
            $config['uploadUrl'] = $res['domain'];
            $config['storageName'] = $res['name'];
            if (5 !== $type || $storage !== null) $config['storageRegion'] = $res['region'];
            $config['cdn'] = $res['cdn'];
        }

        $thumb = SystemConfigService::more([
            'image_thumb_status',
            'thumb_big_height',
            'thumb_big_width',
            'thumb_mid_height',
            'thumb_mid_width',
            'thumb_small_height',
            'thumb_small_width'
        ]);
        $water = SystemConfigService::more([
            'image_watermark_status',
            'watermark_type',
            'watermark_image',
            'watermark_opacity',
            'watermark_position',
            'watermark_rotate',
            'watermark_text',
            'watermark_text_angle',
            'watermark_text_color',
            'watermark_text_size',
            'watermark_x',
            'watermark_y'
        ]);
        $config = array_merge($config, ['thumb' => $thumb], ['water' => $water]);
        return $config;
    }

    /**
     * 生辰缩略图水印实例化
     * @param string $filePath
     * @param bool $is_remote_down
     * @return Upload
     */
    public static function getOssInit(string $filePath, bool $is_remote_down = false)
    {
        //本地
        $uploadUrl = sys_config('site_url');
        if ($uploadUrl && strpos($filePath, $uploadUrl) !== false) {
            $filePath = explode($uploadUrl, $filePath)[1] ?? '';
            return self::init(1)->setFilepath($filePath);
        }
        $fileArr = parse_url($filePath);
        $fileHost = $fileArr['scheme'] . '://' . $fileArr['host'];
        /** @var SystemStorageServices $storageServices */
        $storageServices = app()->make(SystemStorageServices::class);
        $storageArr = CacheService::remember('storage_list', function () use ($storageServices) {
            return $storageServices->selectList([], 'domain,type')->toArray();
        });
        foreach ($storageArr as $item) {
            if ($fileHost == $item['domain']) {
                return self::init($item['type'])->setFilepath($filePath);
            }
        }
        //远程图片 下载到本地处理
        if ($is_remote_down) {
            try {
                /** @var DownloadImage $down */
                $down = app()->make(DownloadImage::class);
                $data = $down->path('thumb_water')->downloadImage($filePath);
                $filePath = $data['path'] ?? '';
            } catch (\Throwable $e) {
                //下载失败 传入原地址
            }
        }
        return self::init(1)->setFilepath($filePath);
    }
}
