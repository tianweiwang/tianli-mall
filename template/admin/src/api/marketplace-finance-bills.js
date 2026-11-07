import axios from 'axios';
import setting from '@/setting';
import { captureContext, currentContext, closeContext, currentPermissions } from '@/api/marketplace-aftersale';
import { platformCan } from '@/libs/marketplace-navigation';
export { captureContext, currentContext, closeContext };

const client = axios.create({ baseURL: setting.apiBaseURL, timeout: 60000 });
const permissions = { read: 'marketplace-finance-bills-read', import: 'marketplace-finance-bills-import', reconcile: 'marketplace-finance-bills-reconcile' };
export const columns = ['kind', 'local_ref', 'mer_id', 'channel', 'account_ref', 'account_version', 'currency', 'amount', 'channel_fee', 'provider_no', 'occurred_at'];
export const statusLabels = { MATCHED: '逐笔相符', DIFFERENCES: '存在账单差异', SOURCE_REVIEW_REQUIRED: '本地流水待核查',
  FIELD_DIFFERENCE: '字段不一致', MISSING_LOCAL: '缺少本地流水', MISSING_BILL: '缺少账单行',
  DUPLICATE_BILL_REFERENCE: '账单引用重复', LOCAL_PROVIDER_REFERENCE_PENDING: '原退款渠道流水号待确认' };
export const fieldLabels = { mer_id: '商家', channel: '渠道', account_ref: '原账户', account_version: '账户版本', currency: '币种',
  amount: '金额', provider_no: '渠道流水号', occurred_at: '发生时间' };
export function can(store, action) {
  return platformCan(store, permissions.read) && !!permissions[action] && platformCan(store, permissions[action]);
}
function active(context) { if (!currentContext(context)) throw new Error('身份或页面已变化，请重新进入账单对账'); }
export async function refreshPermissions(context, stillCurrent = () => true) {
  const check = () => { active(context); if (!stillCurrent()) throw new Error('页面已变化，请重新读取'); };
  check(); const value = await currentPermissions(context); check();
  context.store.commit('userInfo/uniqueAuth', value.unique); context.store.commit('userInfo/access', value.unique);
  check(); await context.store.dispatch('routesList/setRoutesList', value.menus); check(); return value;
}
function id(value) { return typeof value === 'string' && /^[1-9][0-9]{0,19}$/.test(value) && (value.length < 20 || value <= '18446744073709551615'); }
function validate(value) {
  if (!value || value.schema !== 1 || value.scope !== 'testing_bill_reconciliation_v1' || value.real_funds_enabled !== false)
    throw new Error('账单响应范围未确认');
  if (value.list && (!Array.isArray(value.list) || value.list.some(row => !id(row.id)))) throw new Error('账单列表未确认');
  if (value.record) {
    const row = value.record;
    if (!id(row.id) || !id(row.result_id) || !row.account || !Array.isArray(row.results) || !Array.isArray(row.source_issues)
      || !row.comparison || !Array.isArray(row.comparison.rows) || !row.comparison.counts || !statusLabels[row.status])
      throw new Error('保存的对账结果未确认');
  }
  if (value.accounts && (!Array.isArray(value.accounts) || value.accounts.some(row => !id(row.anchor_attempt_id))))
    throw new Error('原付款账户未确认');
  return value;
}
async function request(context, action, method, suffix, body) {
  active(context); if (!can(context.store, action)) throw new Error('当前账号缺少对应账单权限');
  const source = axios.CancelToken.source(); context.life.cancels.add(source.cancel);
  const config = { method, url: 'marketplace/finance-bills' + suffix, headers: { 'Authori-zation': 'Bearer ' + context.token }, cancelToken: source.token };
  if (method === 'GET') config.params = body;
  else { config.headers['Content-Type'] = 'application/json'; config.data = JSON.stringify(body); config.transformRequest = [value => value]; }
  try {
    const response = await client(config); active(context);
    if (response.status !== 200 || !response.data || response.data.status !== 200) {
      const error = new Error('账单响应未确认'); error.billStatus = response.data && response.data.status;
      error.billMessage = response.data && response.data.msg; throw error;
    }
    if (!can(context.store, action)) throw new Error('当前账单权限已变化');
    return validate(response.data.data);
  } catch (error) {
    active(context); const response = error.response; const status = error.billStatus || (response && response.status);
    const message = error.billMessage || (response && response.data && response.data.msg);
    throw new Error(status === 401 ? '登录已失效，请重新登录' : status === 403 ? '当前账号缺少对应账单权限'
      : [404, 409, 422].includes(status) && typeof message === 'string' ? message
      : method === 'POST' ? '提交结果尚未确认，请先刷新保存记录；不会自动重复发送。' : error.message || '账单读取失败');
  } finally { context.life.cancels.delete(source.cancel); }
}
export function list(context, before) { return request(context, 'read', 'GET', '', { view: 'list', limit: 20, ...(before ? { before_id: before } : {}) }); }
export function read(context, billId, resultId) { return request(context, 'read', 'GET', '', { view: 'read', id: billId, ...(resultId ? { result_id: resultId } : {}) }); }
export function accounts(context, merchant) { return request(context, 'read', 'GET', '', { view: 'accounts', mer_id: merchant }); }
export function movements(context, body) { return request(context, 'read', 'GET', '', { view: 'movements', ...body }); }
export function importBill(context, body) { return request(context, 'import', 'POST', '', body); }
export function reconcile(context, billId, key) { return request(context, 'reconcile', 'POST', '/reconcile', { id: billId, request_key: key }); }
export function requestKey() {
  const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
  return 'reconcile:' + Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
}
