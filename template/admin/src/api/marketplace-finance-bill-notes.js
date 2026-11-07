import axios from 'axios';
import setting from '@/setting';
import { currentContext } from '@/api/marketplace-finance-bills';
import { platformCan } from '@/libs/marketplace-navigation';

const client = axios.create({ baseURL: setting.apiBaseURL, timeout: 60000 });
const permissions = { read: 'marketplace-finance-bill-notes-read', append: 'marketplace-finance-bill-notes-append' };

export function can(store, action = 'read') {
  return platformCan(store, 'marketplace-finance-bills-read') && platformCan(store, permissions.read)
    && !!permissions[action] && platformCan(store, permissions[action]);
}
function id(value) {
  return typeof value === 'string' && /^[1-9]\d{0,19}$/.test(value)
    && (value.length < 20 || value <= '18446744073709551615');
}
function rowNumber(value) { return Number.isInteger(value) && value >= 0 && value <= 1000; }
export function validText(value) {
  return typeof value === 'string' && value.trim() !== '' && Array.from(value).length <= 1000
    && !/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(value);
}
function record(value, billId, resultId) {
  return value && id(value.id) && value.bill_id === billId && value.result_id === resultId
    && rowNumber(value.row_number) && validText(value.note)
    && Number.isInteger(value.created_by) && value.created_by > 0 && value.created_by <= 4294967295
    && Number.isSafeInteger(value.created_at) && value.created_at > 0;
}
function current(context, action) {
  if (!currentContext(context)) throw new Error('身份或页面已变化，请重新进入账单对账');
  if (!can(context.store, action)) throw new Error('当前账号缺少差异处理记录权限');
}
async function request(context, action, method, input) {
  current(context, action);
  const source = axios.CancelToken.source();
  context.life.cancels.add(source.cancel);
  const config = { method, url: 'marketplace/finance-bill-notes', cancelToken: source.token,
    headers: { 'Authori-zation': 'Bearer ' + context.token } };
  if (method === 'GET') config.params = input;
  else {
    config.headers['Content-Type'] = 'application/json';
    config.data = JSON.stringify(input);
    config.transformRequest = [value => value];
  }
  try {
    const response = await client(config);
    current(context, action);
    const envelope = response.data;
    if (response.status !== 200 || !envelope || envelope.status !== 200) {
      const error = new Error('处理记录响应未确认');
      error.noteStatus = envelope && envelope.status;
      error.noteMessage = envelope && envelope.msg;
      throw error;
    }
    const data = envelope.data;
    if (!data || data.schema !== 1 || data.scope !== 'testing_bill_notes_v1' || data.real_funds_enabled !== false)
      throw new Error('处理记录响应范围未确认');
    return data;
  } catch (error) {
    current(context, action);
    const response = error.response;
    const status = error.noteStatus || (response && response.status);
    const message = error.noteMessage || (response && response.data && response.data.msg);
    if (status === 401) throw new Error('登录已失效，请重新登录');
    if (status === 403) throw new Error('当前账号缺少差异处理记录权限');
    if ([404, 409, 422].includes(status) && typeof message === 'string') throw new Error(message);
    throw new Error(method === 'POST' ? '追加结果尚未确认，请保留原说明并确认重试。' : '处理记录读取失败，请重试。');
  } finally { context.life.cancels.delete(source.cancel); }
}
export async function list(context, billId, resultId, before) {
  if (!id(billId) || !id(resultId) || (before && !id(before))) throw new Error('请选择已保存的账单及对账结果');
  const value = await request(context, 'read', 'GET', { bill_id: billId, result_id: resultId, limit: 20,
    ...(before ? { before_id: before } : {}) });
  if (!Array.isArray(value.list) || !value.list.every(row => record(row, billId, resultId))
    || !(value.next_before_id === null || id(value.next_before_id))) throw new Error('处理记录列表未确认');
  return value;
}
export async function append(context, body) {
  if (!body || !id(body.bill_id) || !id(body.result_id) || !rowNumber(body.row_number) || !validText(body.note)
    || typeof body.request_key !== 'string' || !/^bill-note:[0-9a-f]{32}$/.test(body.request_key))
    throw new Error('请填写有效的核查说明并选择对应结果');
  const value = await request(context, 'append', 'POST', body);
  if (!record(value.record, body.bill_id, body.result_id) || value.record.row_number !== body.row_number
    || value.record.note !== body.note || typeof value.reused_note !== 'boolean') throw new Error('追加结果尚未确认，请确认重试原说明。');
  return value;
}
export function requestKey() {
  const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
  return 'bill-note:' + Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
}
