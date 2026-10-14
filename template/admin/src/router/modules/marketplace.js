import Vue from 'vue';
import LayoutMain from '@/layout';
import setting from '@/setting';
import store from '@/store';
import { menusApi } from '@/api/account';
import { platformCan } from '@/libs/marketplace-navigation';
import { marketplaceError } from '@/api/marketplace';

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
export default {
  path: setting.routePre + '/marketplace', name: 'marketplace', header: 'marketplace', component: LayoutMain,
  meta: { title: '经营商家', auth: ['marketplace-merchants-list', 'marketplace-products-list'] },
  children: [
    { path: 'merchants', name: 'marketplace_merchants', meta: { title: '入驻商家', auth: ['marketplace-merchants-list'] }, beforeEnter: currentPermission('marketplace-merchants-list'), component: () => import('@/pages/marketplace/merchant/index.vue') },
    { path: 'products', name: 'marketplace_products', meta: { title: '商品审核', auth: ['marketplace-products-list'] }, beforeEnter: currentPermission('marketplace-products-list'), component: () => import('@/pages/marketplace/productReview/index.vue') },
  ],
};
