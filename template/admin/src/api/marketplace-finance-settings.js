import axios from 'axios';
import setting from '@/setting';
import { captureContext, currentContext, closeContext, currentPermissions } from '@/api/marketplace-aftersale';
import { platformCan } from '@/libs/marketplace-navigation';
import { financeView, saveIntent, sameDraft } from '@/libs/marketplace-finance-settings';
export { captureContext, currentContext, closeContext };
const client = axios.create({ baseURL: setting.apiBaseURL, timeout: 20000 });
const permissionFor = { read: 'marketplace-finance-settings-read', write: 'marketplace-finance-settings-write' };
export function can(store, action) { return !!permissionFor[action] && platformCan(store, permissionFor[action]); }
function requireCurrent(context) { if (!currentContext(context)) throw new Error('身份或页面已变化，请重新进入资金设置'); }
export async function refreshPermissions(context, stillCurrent = () => true) {
  const check = () => { requireCurrent(context); if (!stillCurrent()) throw new Error('页面导航已变化，请重新读取当前权限'); };
  check(); const value = await currentPermissions(context); check();
  context.store.commit('userInfo/uniqueAuth', value.unique); check(); context.store.commit('userInfo/access', value.unique);
  check(); await context.store.dispatch('routesList/setRoutesList', value.menus); check(); return value;
}
async function request(context, method, body) {
  requireCurrent(context); const source = axios.CancelToken.source(); context.life.cancels.add(source.cancel);
  const config = { method, url: 'marketplace/finance-settings', headers: { 'Authori-zation': 'Bearer ' + context.token }, cancelToken: source.token };
  if (method === 'PUT') { config.headers['Content-Type'] = 'application/json'; config.data = JSON.stringify(body); config.transformRequest = [value => value]; }
  try {
    const response = await client(config); requireCurrent(context); const envelope = response.data;
    if (response.status !== 200 || !envelope || envelope.status !== 200 || typeof envelope.msg !== 'string' || !Object.prototype.hasOwnProperty.call(envelope, 'data')) {
      const error = new Error('资金设置响应未确认'); error.financeStatus = envelope && envelope.status; throw error;
    }
    return financeView(envelope.data);
  } catch (error) {
    requireCurrent(context); const status = error.financeStatus || (error.response && error.response.status);
    throw new Error(status === 401 ? '登录已失效，请重新登录' : status === 403 ? '当前账号没有此项资金设置权限' : status === 409 ? '草稿版本已变化，请重新读取后核对' : method === 'PUT' ? '保存结果尚未确认，请重新读取当前草稿；不会自动重发' : '资金设置读取未完成，请重新读取');
  } finally { context.life.cancels.delete(source.cancel); }
}
export async function read(context) {
  requireCurrent(context); if (!can(context.store, 'read')) throw new Error('当前账号没有资金设置查看权限');
  const value = await request(context, 'GET'); requireCurrent(context);
  if (!can(context.store, 'read')) throw new Error('当前资金设置查看权限已变化'); return value;
}
export async function save(context, intent) {
  requireCurrent(context); if (!can(context.store, 'write')) throw new Error('当前账号没有资金设置修改权限');
  const checked = saveIntent(intent.expected_revision, intent.draft); const value = await request(context, 'PUT', checked); requireCurrent(context);
  if (!can(context.store, 'read') || !can(context.store, 'write') || !sameDraft(value.draft, checked.draft) || ![checked.expected_revision, checked.expected_revision + 1].includes(value.revision)) throw new Error('保存结果尚未确认，请重新读取当前草稿；不会自动重发');
  return value;
}
