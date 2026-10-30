import axios from 'axios';
import setting from '@/setting';
import { captureContext, currentContext, closeContext, currentPermissions } from '@/api/marketplace-aftersale';
import { platformCan } from '@/libs/marketplace-navigation';
import { transactionQuery, transactionsView } from '@/libs/marketplace-finance-transactions';
export { captureContext, currentContext, closeContext };
const client = axios.create({ baseURL: setting.apiBaseURL, timeout: 20000 });
export function can(store, action) {
  return action === 'read' && platformCan(store, 'marketplace-finance-transactions-read');
}
function requireCurrent(context) {
  if (!currentContext(context)) throw new Error('身份或页面已变化，请重新进入测试交易');
}
export async function refreshPermissions(context, stillCurrent = () => true) {
  const check = () => {
    requireCurrent(context);
    if (!stillCurrent()) throw new Error('页面导航已变化，请重新读取当前权限');
  };
  check();
  const value = await currentPermissions(context);
  check();
  context.store.commit('userInfo/uniqueAuth', value.unique);
  check();
  context.store.commit('userInfo/access', value.unique);
  check();
  await context.store.dispatch('routesList/setRoutesList', value.menus);
  check();
  return value;
}
export async function read(context, query = {}) {
  requireCurrent(context);
  if (!can(context.store, 'read')) throw new Error('当前账号没有测试交易查看权限');
  const params = transactionQuery(query);
  const source = axios.CancelToken.source();
  context.life.cancels.add(source.cancel);
  try {
    const response = await client({
      method: 'GET',
      url: 'marketplace/finance-transactions',
      params,
      headers: { 'Authori-zation': 'Bearer ' + context.token },
      cancelToken: source.token,
    });
    requireCurrent(context);
    if (!can(context.store, 'read')) throw new Error('当前测试交易查看权限已变化');
    const envelope = response.data;
    if (
      response.status !== 200 ||
      !envelope ||
      envelope.status !== 200 ||
      typeof envelope.msg !== 'string' ||
      !Object.prototype.hasOwnProperty.call(envelope, 'data')
    ) {
      const error = new Error('测试交易响应未确认');
      error.financeStatus = envelope && envelope.status;
      throw error;
    }
    return transactionsView(envelope.data, params);
  } catch (error) {
    requireCurrent(context);
    const status = error.financeStatus || (error.response && error.response.status);
    throw new Error(
      status === 401
        ? '登录已失效，请重新登录'
        : status === 403
        ? '当前账号没有测试交易查看权限'
        : status === 422
        ? '所选保存版本不可用，请重新读取后选择'
        : '测试交易读取未完成，请重新读取',
    );
  } finally {
    context.life.cancels.delete(source.cancel);
  }
}
