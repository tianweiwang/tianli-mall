import request from '@/libs/request';
import axios from 'axios';
import setting from '@/setting';
import { currentContext } from '@/api/marketplace-aftersale';
import { platformCan } from '@/libs/marketplace-navigation';

function identity(value, maximum) {
  if (!Number.isSafeInteger(value) || value < 1 || value > maximum) throw new Error('缺少有效的实际记录编号'); return value;
}
function merchantId(value) { return identity(value, 4294967295); }
function productId(value) { return identity(value, 8388607); }
export function marketplaceError(error) {
  const body = error && error.response && error.response.data;
  return body && typeof body.msg === 'string' ? body.msg : error && typeof error.msg === 'string' ? error.msg : error && error.message ? error.message : '操作未完成，请重试';
}
async function call(method, url, data) {
  const result = await request({ method, url, ...(method === 'get' ? { params: data } : { data }) });
  if (!result || result.status !== 200 || result.data === undefined) throw new Error('平台接口返回不完整'); return result.data;
}
const exitClient = axios.create({ baseURL: setting.apiBaseURL, timeout: 20000 });
const exitKeys = ['fulfillment', 'payment', 'refund', 'settlement'];
function exactExitObject(value, keys) {
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length !== keys.length || keys.some(key => !Object.prototype.hasOwnProperty.call(value, key))) throw new Error('商家退出响应不完整，请重新预检');
}
function exitTime(value, nullable = false) { return (nullable && value === null) || (Number.isSafeInteger(value) && value > 0); }
function exitPrecheckResult(value, id) {
  exactExitObject(value, ['schema', 'scope', 'mer_id', 'closed_at', 'checked_at', 'can_close', 'responsibilities']);
  if (value.schema !== 1 || value.scope !== 'MERCHANT_EXIT_PRECHECK' || value.mer_id !== merchantId(id) || !exitTime(value.closed_at, true) || !exitTime(value.checked_at) || typeof value.can_close !== 'boolean' || !Array.isArray(value.responsibilities) || value.responsibilities.length !== exitKeys.length) throw new Error('商家退出预检目标或状态未确认');
  const seen = new Set();
  value.responsibilities.forEach(row => {
    exactExitObject(row, ['key', 'state', 'count', 'reasons']);
    if (!exitKeys.includes(row.key) || seen.has(row.key) || !['CLEAR', 'OPEN', 'UNKNOWN'].includes(row.state) || !(row.count === null || (Number.isSafeInteger(row.count) && row.count >= 0)) || !Array.isArray(row.reasons)) throw new Error('商家退出责任记录不完整');
    seen.add(row.key);
    row.reasons.forEach(reason => { exactExitObject(reason, ['code', 'message']); if (typeof reason.code !== 'string' || !reason.code.trim() || typeof reason.message !== 'string' || !reason.message.trim()) throw new Error('商家退出责任说明不完整'); });
  });
  return { ...value, responsibilities: exitKeys.map(key => { const row = value.responsibilities.find(item => item.key === key); return { ...row, reasons: row.reasons.map(reason => ({ ...reason })) }; }) };
}
function merchantClosedResult(value, id) {
  exactExitObject(value, ['schema', 'scope', 'mer_id', 'closed_at', 'already_closed']);
  if (value.schema !== 1 || value.scope !== 'MERCHANT_CLOSED' || value.mer_id !== merchantId(id) || !exitTime(value.closed_at) || typeof value.already_closed !== 'boolean') throw new Error('商家关闭响应未确认，请重新读取状态');
  return value;
}
async function exitCall(context, method, url, permission, query) {
  const requireCurrent = () => { if (!currentContext(context) || !platformCan(context.store, permission)) throw new Error('身份或权限已变化，请重新进行退出预检'); };
  requireCurrent(); const source = axios.CancelToken.source(); context.life.cancels.add(source.cancel);
  try {
    const response = await exitClient({ method, url, headers: { 'Authori-zation': 'Bearer ' + context.token }, cancelToken: source.token, ...(method === 'put' ? { data: {} } : { params: query }) });
    requireCurrent(); const body = response.data;
    if (response.status !== 200 || !body || body.status !== 200 || body.data === undefined) throw new Error(body && typeof body.msg === 'string' ? body.msg : '商家退出响应未确认，请重新读取');
    return body.data;
  } catch (error) { requireCurrent(); throw new Error(marketplaceError(error)); }
  finally { context.life.cancels.delete(source.cancel); }
}
export const marketplaceApi = {
  merchants: (query) => call('get', '/marketplace/merchants', query),
  merchant: (id) => call('get', '/marketplace/merchants/' + merchantId(id)),
  merchantForExit: (id, context) => exitCall(context, 'get', '/marketplace/merchants/' + merchantId(id), 'marketplace-merchants-read'),
  merchantsForExit: (query, context) => exitCall(context, 'get', '/marketplace/merchants', 'marketplace-merchants-list', query),
  merchantExitPrecheck: async (id, context) => exitPrecheckResult(await exitCall(context, 'get', '/marketplace/merchants/' + merchantId(id) + '/exit-precheck', 'marketplace-merchants-exit-precheck'), id),
  closeMerchant: async (id, context) => merchantClosedResult(await exitCall(context, 'put', '/marketplace/merchants/' + merchantId(id) + '/close', 'marketplace-merchants-close'), id),
  saveMerchant: (id, body) => id ? call('put', '/marketplace/merchants/' + merchantId(id), body) : call('post', '/marketplace/merchants', body),
  approveMerchant: (id) => call('put', '/marketplace/merchants/' + merchantId(id) + '/review', { review_status: 1 }),
  reviewMerchant: (id, review_status) => call('put', '/marketplace/merchants/' + merchantId(id) + '/review', { review_status }),
  merchantFlags: (id, body) => call('put', '/marketplace/merchants/' + merchantId(id) + '/flags', body),
  createAdminEmployee: (id, body) => call('post', '/marketplace/merchants/' + merchantId(id) + '/admin-employee', body),
  reissuePasswordSetup: (id, account) => call('post', '/marketplace/merchants/' + merchantId(id) + '/password-setup-reissue', { account }),
  products: (query) => call('get', '/marketplace/products', query),
  approveProduct: (id) => call('put', '/marketplace/products/' + productId(id) + '/review', { action: 'approve', reason: '' }),
  rejectProduct: (id, reason) => call('put', '/marketplace/products/' + productId(id) + '/review', { action: 'reject', reason }),
  offShelf: (id) => call('put', '/marketplace/products/' + productId(id) + '/off-shelf', {}),
};
