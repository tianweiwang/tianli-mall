import Vue from 'vue';
import LayoutMain from '@/layout';
import setting from '@/setting';
import store from '@/store';
import { menusApi } from '@/api/account';
import { platformCan } from '@/libs/marketplace-navigation';
import { marketplaceError } from '@/api/marketplace';
import { captureContext, currentContext, closeContext, currentPermissions } from '@/api/marketplace-aftersale';
import { refreshPermissions as refreshReviewPermissions } from '@/api/marketplace-review';
import { refreshPermissions as refreshFinancePermissions } from '@/api/marketplace-finance-settings';
import { refreshPermissions as refreshFinanceTransactionPermissions } from '@/api/marketplace-finance-transactions';

function currentPermission(permission) {
  return async (to, from, next) => {
    try {
      const response = await menusApi(); const data = response.data;
      if (!data || !Array.isArray(data.menus) || !Array.isArray(data.unique)) throw new Error('当前平台权限返回不完整');
      store.commit('userInfo/uniqueAuth', data.unique); store.commit('userInfo/access', data.unique);
      await store.dispatch('routesList/setRoutesList', data.menus);
      if (platformCan(store, permission)) next(); else next({ name: '403' });
    } catch (error) { Vue.prototype.$message.error(marketplaceError(error)); next(false); }
  };
}
function aftersalePermission(permission) {
  return async (to, from, next) => {
    let context;
    try {
      context = captureContext(store); const data = await currentPermissions(context);
      if (!currentContext(context)) { next(false); return; }
      store.commit('userInfo/uniqueAuth', data.unique); store.commit('userInfo/access', data.unique);
      await store.dispatch('routesList/setRoutesList', data.menus);
      if (!currentContext(context)) { next(false); return; }
      if (platformCan(store, permission)) next(); else next({ name: '403' });
    } catch (error) { if (!context || currentContext(context)) Vue.prototype.$message.error(error.message || '当前售后权限读取失败'); next(false); }
    finally { closeContext(context); }
  };
}
function reviewPermission(permission) {
  return async (to, from, next) => {
    let context; let router;
    try {
      // Resolve the already initialized router inside the hook to avoid a module cycle.
      const module = await import('@/router'); router = module.default;
      if (!router || !router.history) throw new Error('页面导航尚未就绪');
      if (router.history.pending !== to) { next(); return; }
      context = captureContext(store);
      await refreshReviewPermissions(context, () => router.history.pending === to && currentContext(context));
      if (router.history.pending !== to) { next(); return; }
      if (!currentContext(context)) { next(false); return; }
      if (platformCan(store, permission)) next(); else next({ name: '403' });
    } catch (error) {
      if (router && router.history.pending !== to) { next(); return; }
      if (!context || currentContext(context)) Vue.prototype.$message.error(error.message || '当前评价权限读取失败'); next(false);
    } finally { closeContext(context); }
  };
}
function financePermission(permission = 'marketplace-finance-settings-read', refreshPermissions = refreshFinancePermissions, label = '资金设置') {
  return async (to, from, next) => {
    let context; let router;
    try {
      const module = await import('@/router'); router = module.default;
      if (!router || !router.history) throw new Error('页面导航尚未就绪');
      if (router.history.pending !== to) { next(); return; }
      context = captureContext(store);
      await refreshPermissions(context, () => router.history.pending === to && currentContext(context));
      if (router.history.pending !== to) { next(); return; }
      if (!currentContext(context)) { next(false); return; }
      if (platformCan(store, permission)) next(); else next({ name: '403' });
    } catch (error) {
      if (router && router.history.pending !== to) { next(); return; }
      if (!context || currentContext(context)) Vue.prototype.$message.error(error.message || '当前' + label + '权限读取失败'); next(false);
    } finally { closeContext(context); }
  };
}
export default {
  path: setting.routePre + '/marketplace', name: 'marketplace', header: 'marketplace', component: LayoutMain,
  meta: { title: '经营商家', auth: ['marketplace-merchants-list', 'marketplace-products-list', 'marketplace-aftersales-list', 'marketplace-aftersales-read', 'marketplace-aftersales-approve', 'marketplace-aftersales-reject', 'marketplace-aftersales-return-accept', 'marketplace-aftersale-files-ticket', 'marketplace-aftersale-files-read', 'marketplace-reviews-list', 'marketplace-reviews-read', 'marketplace-reviews-moderate', 'marketplace-finance-settings-read', 'marketplace-finance-settings-write', 'marketplace-finance-transactions-read'] },
  children: [
    { path: 'merchants', name: 'marketplace_merchants', meta: { title: '入驻商家', auth: ['marketplace-merchants-list'] }, beforeEnter: currentPermission('marketplace-merchants-list'), component: () => import('@/pages/marketplace/merchant/index.vue') },
    { path: 'products', name: 'marketplace_products', meta: { title: '商品审核', auth: ['marketplace-products-list'] }, beforeEnter: currentPermission('marketplace-products-list'), component: () => import('@/pages/marketplace/productReview/index.vue') },
    { path: 'aftersales', name: 'marketplace_aftersales', meta: { title: '多商户售后', auth: ['marketplace-aftersales-list'] }, beforeEnter: aftersalePermission('marketplace-aftersales-list'), component: () => import('@/pages/marketplace/aftersale/index.vue') },
    { path: 'aftersales/:id', name: 'marketplace_aftersale_detail', meta: { title: '售后详情', auth: ['marketplace-aftersales-read'] }, beforeEnter: aftersalePermission('marketplace-aftersales-read'), component: () => import('@/pages/marketplace/aftersale/index.vue') },
    { path: 'reviews', name: 'marketplace_reviews', meta: { title: '商品评价', auth: ['marketplace-reviews-list'] }, beforeEnter: reviewPermission('marketplace-reviews-list'), component: () => import('@/pages/marketplace/review/index.vue') },
    { path: 'reviews/:id', name: 'marketplace_review_detail', meta: { title: '评价详情', auth: ['marketplace-reviews-read'] }, beforeEnter: reviewPermission('marketplace-reviews-read'), component: () => import('@/pages/marketplace/review/index.vue') },
    { path: 'finance-settings', name: 'marketplace_finance_settings', meta: { title: '资金设置', auth: ['marketplace-finance-settings-read'] }, beforeEnter: financePermission(), component: () => import('@/pages/marketplace/financeSettings/index.vue') },
    { path: 'finance-transactions', name: 'marketplace_finance_transactions', meta: { title: '测试交易', auth: ['marketplace-finance-transactions-read'] }, beforeEnter: financePermission('marketplace-finance-transactions-read', refreshFinanceTransactionPermissions, '测试交易'), component: () => import('@/pages/marketplace/financeTransactions/index.vue') },
  ],
};
