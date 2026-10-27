import request from '@/libs/request';

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
export const marketplaceApi = {
  merchants: (query) => call('get', '/marketplace/merchants', query),
  merchant: (id) => call('get', '/marketplace/merchants/' + merchantId(id)),
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
