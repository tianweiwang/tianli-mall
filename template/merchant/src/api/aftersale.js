import axios from 'axios';
import { captureSession, isCurrentSession } from './session';
import { refundView, refundPage, actionResult, intentWire, readTicket, recordId, u64 } from '../domain/aftersale';
const apiBase = process.env.VUE_APP_API_BASE || '/merchantapi';
if (apiBase !== '/merchantapi') throw new Error('商家接口基础路径必须为 /merchantapi');
const prefix = ''; const authHeader = 'Authorization';
const permissionFor = { list: 'order.read', read: 'order.read', approve: 'aftersale.handle', reject: 'aftersale.handle', accept_return: 'aftersale.handle', ticket: 'order.read', content: 'order.read' };
function hasPermission(store, permission) { return store.getters.can(permission); }
function actor(value) {
  if (typeof value === 'number') return Number.isSafeInteger(value) && value > 0 && value <= 4294967295 ? String(value) : '';
  return typeof value === 'string' && /^[1-9][0-9]{0,9}(?![\s\S])/.test(value) && (value.length < 10 || value <= '4294967295') ? value : '';
}
export function captureContext(store) {
  const state = store.state; const profile = state.profile; const session = captureSession();
  if (!session.token || !profile || !profile.merchant || !profile.employee || !profile.role || !Array.isArray(profile.role.permissions)) throw stale();
  const merchant = actor(profile.merchant.mer_id); const employee = actor(profile.employee.id);
  if (!merchant || !employee || actor(profile.employee.mer_id) !== merchant || state.token !== session.token || state.sessionRevision !== session.revision || !Number.isSafeInteger(state.authIntent) || state.authIntent < 0) throw stale();
  return Object.freeze({ store, state, profile, session, token: session.token, merchant, employee, authIntent: state.authIntent, life: { open: true, cancels: new Set() } });
}
export function currentContext(context) {
  try {
  if (!context || !context.life.open) return false; const state = context.store.state; const profile = state.profile;
  return state === context.state && profile === context.profile && state.authIntent === context.authIntent && state.sessionRevision === context.session.revision && state.token === context.token
    && isCurrentSession(context.session) && profile && profile.merchant && profile.employee && actor(profile.merchant.mer_id) === context.merchant && actor(profile.employee.id) === context.employee && actor(profile.employee.mer_id) === context.merchant;
  } catch (error) { return false; }
}
const client = axios.create({ baseURL: apiBase, timeout: 20000 });
const flagFor = { approve: 'approve', reject: 'reject', accept_return: 'accept_return' };
const suffixFor = { approve: 'approve', reject: 'reject', accept_return: 'return-accept' };
function stale() { return new Error('身份或页面已变化，请重新读取售后资料'); }
function requireCurrent(context) { if (!currentContext(context)) throw stale(); }
export function closeContext(context) {
  if (!context) return; context.life.open = false;
  context.life.cancels.forEach(cancel => cancel('页面资料已失效')); context.life.cancels.clear();
}
async function transfer(context, config) {
  requireCurrent(context); const source = axios.CancelToken.source(); context.life.cancels.add(source.cancel);
  try {
    const response = await client({ ...config, cancelToken: source.token }); requireCurrent(context); return response;
  } catch (error) {
    requireCurrent(context);
    const status = error.response && error.response.status;
    throw new Error(status === 401 ? '登录已失效，请重新登录' : status === 403 ? '当前账号没有此项权限' : status === 404 ? '售后资料不存在或无权访问' : '请求未完成，提交结果可能尚未确认');
  } finally { context.life.cancels.delete(source.cancel); }
}
function headers(context) { return { [authHeader]: 'Bearer ' + context.token }; }
async function json(context, method, path, params, wire) {
  const config = { method, url: path, headers: headers(context) };
  if (method === 'GET') config.params = params;
  else { config.headers['Content-Type'] = 'application/json'; config.data = wire; config.transformRequest = [value => value]; }
  const response = await transfer(context, config); const envelope = response.data;
  if (response.status !== 200 || !envelope || envelope.status !== 200 || typeof envelope.msg !== 'string' || !Object.prototype.hasOwnProperty.call(envelope, 'data')) {
    throw new Error(envelope && typeof envelope.msg === 'string' ? envelope.msg : '售后响应未确认，请重新读取');
  }
  return envelope.data;
}
export function can(store, action) { const permission = permissionFor[action]; return !!permission && hasPermission(store, permission); }
export async function page(context, query) {
  requireCurrent(context); if (!can(context.store, 'list')) throw new Error('当前账号没有售后列表权限');
  return refundPage(await json(context, 'GET', prefix + 'aftersales', query), query.page, query.limit);
}
export async function detail(context, id) {
  requireCurrent(context); if (!can(context.store, 'read')) throw new Error('当前账号没有售后详情权限'); recordId(id);
  const view = refundView(await json(context, 'GET', prefix + 'aftersales/' + id)); if (view.id !== id) throw new Error('售后详情目标未确认'); return view;
}
export async function command(context, intent) {
  requireCurrent(context); if (!flagFor[intent.action] || !can(context.store, intent.action)) throw new Error('当前账号没有此项处理权限');
  const wire = intentWire(intent); recordId(intent.refundId);
  return actionResult(await json(context, 'POST', prefix + 'aftersales/' + intent.refundId + '/' + suffixFor[intent.action], undefined, wire), intent);
}
export async function privateImage(context, view, fileId) {
  requireCurrent(context); if (!can(context.store, 'ticket') || !can(context.store, 'content')) throw new Error('当前账号没有私有凭证读取权限');
  u64(fileId); if (!view.images.includes(fileId)) throw new Error('凭证不属于当前售后');
  const path = prefix + 'aftersale-files/' + fileId;
  const ticket = readTicket(await json(context, 'POST', path + '/read-ticket', undefined, '{"schema":1}'), view.root_order_id, fileId, view.id);
  requireCurrent(context);
  const blob = await contentBytes(context, path, ticket);
  requireCurrent(context); if (!can(context.store, 'ticket') || !can(context.store, 'content')) throw new Error('当前凭证读取权限已变化'); const url = window.URL.createObjectURL(blob);
  if (!currentContext(context)) { window.URL.revokeObjectURL(url); throw stale(); }
  return url;
}
async function contentBytes(context, path, ticket) {
  requireCurrent(context); const base = new URL(apiBase, window.location.origin);
  if (!/^https?:$/.test(base.protocol) || base.origin !== window.location.origin || base.username || base.password || base.search || base.hash) throw new Error('私有凭证地址不可用');
  base.pathname = base.pathname.replace(/\/+$/, '') + '/'; const target = new URL(path + '/content', base);
  if (target.origin !== base.origin || target.pathname !== base.pathname + path + '/content' || target.search || target.hash || target.username || target.password) throw new Error('私有凭证地址不可用');
  const controller = new AbortController(); const cancel = () => controller.abort(); context.life.cancels.add(cancel);
  const timer = window.setTimeout(cancel, 20000);
  try {
    const response = await fetch(target.href, { method: 'GET', headers: { ...headers(context), 'X-Aftersale-Read-Ticket': ticket.read_ticket }, mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
    requireCurrent(context);
    if (response.status !== 200 || response.redirected || response.headers.get('Content-Type') !== ticket.file.media_type) throw new Error('私有凭证内容未确认，请重新读取');
    const blob = await response.blob(); requireCurrent(context);
    if (!(blob instanceof Blob) || blob.size !== ticket.file.byte_size || blob.type !== ticket.file.media_type || !can(context.store, 'ticket') || !can(context.store, 'content')) throw new Error('私有凭证内容或当前权限未确认');
    return blob;
  } catch (error) { requireCurrent(context); throw new Error('私有凭证读取未完成，请重新读取'); }
  finally { window.clearTimeout(timer); context.life.cancels.delete(cancel); }
}