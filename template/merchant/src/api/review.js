import axios from 'axios';
import { captureContext, currentContext, closeContext } from './aftersale';
import { reviewView, reviewPage, pageQuery, routeId, intentWire, commandWrite } from '../domain/review';
export { captureContext, currentContext, closeContext };
const apiBase = process.env.VUE_APP_API_BASE || '/merchantapi';
if (apiBase !== '/merchantapi') throw new Error('商家接口基础路径必须为 /merchantapi');
const client = axios.create({ baseURL: apiBase, timeout: 20000 });
const permissionFor = { list: 'product.read', read: 'product.read', reply: 'product.write' };
export function can(store, action) { return !!permissionFor[action] && store.getters.can(permissionFor[action]); }
function requireCurrent(context) { if (!currentContext(context)) throw new Error('身份或页面已变化，请重新进入评价页面'); }
async function json(context, method, path, query, wire) {
  requireCurrent(context); const source = axios.CancelToken.source(); context.life.cancels.add(source.cancel);
  const config = { method, url: path, headers: { Authorization: 'Bearer ' + context.token }, cancelToken: source.token };
  if (method === 'GET') config.params = query;
  else { config.headers['Content-Type'] = 'application/json'; config.data = wire; config.transformRequest = [value => value]; }
  try {
    const response = await client(config); requireCurrent(context); const envelope = response.data;
    if (response.status !== 200 || !envelope || envelope.status !== 200 || typeof envelope.msg !== 'string' || !Object.prototype.hasOwnProperty.call(envelope, 'data')) throw new Error('评价响应未确认，请重新读取');
    return envelope.data;
  } catch (error) {
    requireCurrent(context); const status = error.response && error.response.status;
    throw new Error(status === 401 ? '登录已失效，请重新登录' : status === 403 ? '当前账号没有此项权限' : status === 404 ? '评价不存在或无权访问' : '请求未完成，请读取当前评价确认结果');
  } finally { context.life.cancels.delete(source.cancel); }
}
export async function page(context, query) {
  requireCurrent(context); if (!can(context.store, 'list')) throw new Error('当前账号没有评价列表权限'); const checked = pageQuery(query);
  const value = reviewPage(await json(context, 'GET', 'reviews', checked), checked.page, checked.limit);
  requireCurrent(context); if (!can(context.store, 'list')) throw new Error('当前评价读取权限已变化'); return value;
}
export async function detail(context, id) {
  requireCurrent(context); if (!can(context.store, 'read')) throw new Error('当前账号没有评价详情权限'); const target = routeId(id);
  const value = reviewView(await json(context, 'GET', 'reviews/' + target));
  requireCurrent(context); if (!can(context.store, 'read') || value.id !== target) throw new Error('评价详情目标或当前权限未确认'); return value;
}
export async function command(context, intent) {
  requireCurrent(context); if (intent.action !== 'reply' || !can(context.store, 'reply')) throw new Error('当前账号没有商家回复权限'); const wire = intentWire(intent);
  const value = commandWrite(await json(context, 'PUT', 'reviews/' + routeId(intent.reviewId) + '/reply', undefined, wire), intent);
  requireCurrent(context); if (!can(context.store, 'reply')) throw new Error('当前回复权限已变化，请读取评价确认结果'); return value;
}
