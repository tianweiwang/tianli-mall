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
import Setting from '@/setting';
import { guardedPlatformChatTransfer } from '@/api/platform-chat-materials';

/** Real caller supplies its current socket-authenticated staff/room; never retries a dispatched transfer. */
export function serviceTransferGuarded(data, currentContext) {
  return guardedPlatformChatTransfer(currentContext, data, (body, frame) => request({
    url: 'service/transfer',
    method: 'post',
    data: body,
    kefu: true,
    headers: { 'Content-Type': 'application/json', 'Authori-zation': 'Bearer ' + frame.token },
    transformRequest: [(value, headers) => {
      const now = currentContext();
      const authorization = headers && typeof headers.get === 'function' ? headers.get('Authori-zation')
        : headers && headers[Object.keys(headers).find(key => key.toLowerCase() === 'authori-zation')];
      if (!now || now.kind !== frame.kind || Number(now.uid) !== frame.uid || Number(now.peer) !== frame.peer
        || Number(now.serviceId) !== frame.serviceId || Number(now.tourist) !== frame.tourist || now.token !== frame.token
        || String(now.route || '') !== frame.route || now.ready !== true || now.active === false || authorization !== 'Bearer ' + frame.token) {
        throw new Error('转接派发前身份或房间已变化，请查看原聊天记录');
      }
      if (!value || Object.keys(value).sort().join(',') !== 'kefuToUid,uid' || value.uid !== body.uid || value.kefuToUid !== body.kefuToUid) {
        throw new Error('转接正文已变化，操作尚未派发');
      }
      return JSON.stringify(body);
    }],
  }));
}

/*
 * 登录
 * */
export function AccountLogin(data) {
  return request({
    url: '/login',
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 获取左侧客服聊天用户列表
 * @constructor
 */
export function record(params) {
  return request({
    url: '/user/record',
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 获取左侧用户详情
 * @constructor
 */
export function userInfo(id) {
  return request({
    url: '/user/info/' + id,
    method: 'get',
    kefu: true,
  });
}

/**
 * 获取左侧用户订单列表
 * @constructor
 */
export function getorderList(id, params) {
  return request({
    url: '/order/list/' + id,
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 客服订单发货
 * @constructor
 */
export function orderDelivery(id, data) {
  return request({
    url: '/order/delivery/' + id,
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 一键改价
 */
export function editPriceApi(id, data) {
  return request({
    url: `/order/update/${id}`,
    method: 'put',
    data,
    kefu: true,
  });
}

/**
 * 客服订单改价
 * @constructor
 */
export function orderEdit(id) {
  return request({
    url: 'order/edit/' + id,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客服订单退款表单
 * @constructor
 */
export function orderRecord(id) {
  return request({
    url: 'order/refund_form/' + id,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客服订单退款
 * @constructor
 */
export function orderRefundApi(data) {
  return request({
    url: 'order/refund',
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 商品购买记录
 * @constructor
 */
export function productCart(uid, params) {
  return request({
    url: 'product/cart/' + uid,
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 商品足记
 * @constructor
 */
export function productVisit(uid, params) {
  return request({
    url: 'product/visit/' + uid,
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 热销商品
 * @constructor
 */
export function productHot(uid, params) {
  return request({
    url: 'product/hot/' + uid,
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 客服话术
 * @constructor
 */
export function speeChcraft(params) {
  return request({
    url: 'service/speechcraft',
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 客服转接列表
 * @constructor
 */
export function transferList(params) {
  return request({
    url: 'service/transfer_list',
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 客服转接列表
 * @constructor
 */
export function serviceTransfer(params) {
  return request({
    url: 'service/transfer',
    method: 'post',
    params,
    kefu: true,
  });
}

/**
 * 客服用户标签
 * @constructor
 */
export function userLabel(id) {
  return request({
    url: `user/label/${id}`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客服用户标签更新
 * @constructor
 */
export function userLabelPut(id, data) {
  return request({
    url: `user/label/${id}`,
    method: 'put',
    data,
    kefu: true,
  });
}

/**
 * 客服用户聊天列表
 * @constructor
 */
export function serviceList(params) {
  return request({
    url: `service/list`,
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 退出登录
 * @constructor
 */
export function AccountLogoutKefu() {
  return request({
    url: `user/logout`,
    method: 'post',
    kefu: true,
  });
}

/**
 * 获取扫码登录凭证
 * @constructor
 */
export function getSanCodeKey() {
  return request({
    url: `/key`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 商品详情
 * @constructor
 */
export function productInfo(id) {
  return request({
    url: `product/info/${id}`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 获取轮播图和logo
 */
export function loginInfoApi() {
  return request({
    url: '/login/info',
    method: 'get',
    kefu: true,
  });
}

/**
 * 订单备注
 */
export function orderRemark(data) {
  return request({
    url: '/order/remark',
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 订单详情
 */
export function orderInfo(id) {
  return request({
    url: '/order/info/' + id,
    method: 'get',
    kefu: true,
  });
}

/**
 * 物流公司
 */
export function orderExport() {
  return request({
    url: '/order/export',
    method: 'get',
    kefu: true,
  });
}

/**
 * 快递公司模板
 */
export function orderTemp(params) {
  return request({
    url: '/order/temp',
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 获取配送员列表
 */
export function orderDeliveryAll() {
  return request({
    url: '/order/delivery_all',
    method: 'get',
    kefu: true,
  });
}

/**
 * 获取发货人员
 */
export function getSender() {
  return request({
    url: '/order/delivery_info',
    method: 'get',
    kefu: true,
  });
}

/**
 * 获取话术分类
 */
export function serviceCate(params) {
  return request({
    url: '/service/cate',
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 修改话术
 */
export function serviceCateUpdate(id, params) {
  return request({
    url: 'service/speechcraft/' + id,
    method: 'PUT',
    params,
    kefu: true,
  });
}

/**
 * 添加话术
 */
export function addSpeeChcraft(data) {
  return request({
    url: 'service/speechcraft',
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 添加分类
 */
export function addServiceCate(data) {
  return request({
    url: 'service/cate',
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 修改分类
 */
export function editServiceCate(id, params) {
  return request({
    url: 'service/cate/' + id,
    method: 'PUT',
    params,
    kefu: true,
  });
}

/**
 * 扫码登录情况
 */
export function scanStatus(key, params) {
  return request({
    url: 'scan/' + key,
    method: 'get',
    params,
    kefu: true,
  });
}

/**
 * 扫码核销
 */
export function orderVerificApi(id) {
  return request({
    url: `/order/verific/${id}`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客服用户分组
 * @constructor
 */
export function userGroupApi() {
  return request({
    url: `user/group`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客服设置用户分组
 * @constructor
 */
export function putGroupApi(uid, id) {
  return request({
    url: `user/group/${uid}/${id}`,
    method: 'put',
    kefu: true,
  });
}

/**
 * 客服配置
 * @constructor
 */
export function kefuConfig() {
  return request({
    url: `config`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客户端 随机客服
 * @constructor
 */
export function serviceListApi(params = {}) {
  const { token = '', ...query } = params;
  return request({
    url: `tourist/user`,
    method: 'get',
    params: query,
    headers: { 'Authori-zation': token ? 'Bearer ' + token : '' },
    chatUserAuthorization: true,
    kefu: true,
  });
}

/**
 * 客户端 广告位
 * @constructor
 */
export function getAdvApi() {
  return request({
    url: `tourist/adv`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客户端 聊天记录
 * @constructor
 */
export function chatListApi(params = {}) {
  const { token = '', ...query } = params;
  return request({
    url: `tourist/chat`,
    method: 'get',
    params: query,
    headers: { 'Authori-zation': token ? 'Bearer ' + token : '' },
    chatUserAuthorization: true,
    kefu: true,
  });
}

/**
 * 客户端 客服反馈
 * @constructor
 */
export function feedbackDataApi() {
  return request({
    url: `tourist/feedback`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客户端 反馈提示语
 * @constructor
 */
export function feedbackFromApi(data) {
  return request({
    url: `tourist/feedback`,
    method: 'post',
    data,
    kefu: true,
  });
}

/**
 * 已登录顾客读取自己的订单预览，沿用商城详情的真实归属校验。
 * @constructor
 */
export async function getOrderApi(order_id, token) {
  if (typeof order_id !== 'string' || !order_id || typeof token !== 'string' || !token
    || window.localStorage.getItem('LOGIN_STATUS_TOKEN') !== token) throw new Error('请先登录商城再读取订单');
  const base = new URL(Setting.apiBaseURL, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password
    || base.search || base.hash || !/\/adminapi\/?$/.test(base.pathname)) throw new Error('订单详情需使用同源商城接口');
  const path = base.pathname.replace(/\/adminapi\/?$/, '/api/order/detail/') + encodeURIComponent(order_id);
  const target = new URL(path, base.origin);
  if (target.origin !== base.origin || target.pathname !== path || target.search || target.hash) throw new Error('订单地址无效');
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), 100000);
  try {
    const response = await fetch(target.href, {
      method: 'GET', headers: { 'Authori-zation': 'Bearer ' + token },
      credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
    });
    const result = await response.json();
    if (window.localStorage.getItem('LOGIN_STATUS_TOKEN') !== token) throw new Error('登录状态已更新，请重新进入聊天');
    if (!response.ok || !result || result.status !== 200 || !result.data || typeof result.data !== 'object') {
      throw new Error('订单信息读取失败，请稍后重试');
    }
    return result;
  } finally {
    window.clearTimeout(timer);
  }
}

/**
 * 客户端 商品详情
 * @constructor
 */
export function productApi(id) {
  return request({
    url: `tourist/product/${id}`,
    method: 'get',
    kefu: true,
  });
}

/**
 * 客服链接获取
 * @constructor
 */
export function getWorkermanUrl() {
  return request({
    url: `get_workerman_url`,
    method: 'get',
  });
}

/**
 * 复制粘贴上传图片
 */
export function uploadImg(data) {
  return request({
    url: `upload`,
    method: 'post',
    data,
    kefu: true,
  });
}
