// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

import request from '@/libs/request';

/**
 * @description 上传
 * @param {Object} param data {Object} 传值参数
 */
export function upload(data, config) {
  return request({
    url: 'file/video_upload',
    method: 'post',
    file: true,
    data,
  });
}
/**
 * @description 云存储 url 上传
 * @param {Object} param data {Object} 传值参数
 */
export function ossUpload(url, data) {
  return request({
    url,
    method: 'post',
    file: true,
    data,
  });
}
