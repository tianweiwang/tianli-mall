import marketplace from '../../marketplace.config';
import Vue from 'vue';
import Router from 'vue-router';
import store from '../store';
import { captureSession, isCurrentSession } from '../api/session';
import Layout from '../layout/index.vue';

Vue.use(Router);
const router = new Router({
  mode: 'hash',
  routes: [
    { path: '/login', name: 'login', component: () => import('../pages/login/index.vue'), meta: { public: true, title: '员工登录' } },
    { path: '/password-setup', name: 'password-setup', component: () => import('../pages/password-setup/index.vue'), meta: { public: true, title: '设置密码' } },
    { path: '/', component: Layout, children: [
      { path: '', name: 'home', component: () => import('../pages/home/index.vue'), meta: { title: '本店信息' } },
      { path: 'orders', name: 'orders', component: () => import('../pages/order/list.vue'), meta: { title: '订单列表', permission: 'order.read' } },
      { path: 'orders/:id', name: 'order-detail', component: () => import('../pages/order/detail.vue'), meta: { title: '订单详情', permission: 'order.read' } },
      { path: 'aftersales', name: 'aftersales', component: () => import('../pages/aftersale/index.vue'), meta: { title: '多商户售后', permission: 'order.read' } },
      { path: 'aftersales/:id', name: 'aftersale-detail', component: () => import('../pages/aftersale/index.vue'), meta: { title: '售后详情', permission: 'order.read' } },
      { path: 'reviews', name: 'reviews', component: () => import('../pages/review/index.vue'), meta: { title: '本店商品评价', permission: 'product.read' } },
      { path: 'reviews/:id', name: 'review-detail', component: () => import('../pages/review/index.vue'), meta: { title: '评价详情', permission: 'product.read' } },
      { path: 'products', name: 'products', component: () => import('../pages/product/list.vue'), meta: { title: '商品列表', permission: 'product.read' } },
      { path: 'products/new', name: 'product-new', component: () => import('../pages/product/edit.vue'), meta: { title: '新建商品', permission: 'product.write' } },
      { path: 'products/:id/edit', name: 'product-edit', component: () => import('../pages/product/edit.vue'), meta: { title: '编辑商品', permission: 'product.read' } },
      { path: 'attachments', name: 'attachments', component: () => import('../pages/attachment/index.vue'), meta: { title: '素材库', permission: 'attachment.manage' } },
      { path: 'employees', name: 'employees', component: () => import('../pages/employee/index.vue'), meta: { title: '员工管理', permission: 'merchant.employee.manage' } },
      { path: 'roles', name: 'roles', component: () => import('../pages/role/index.vue'), meta: { title: '角色管理', permission: 'merchant.role.manage' } },
      { path: 'freight', name: 'freight', component: () => import('../pages/freight/list.vue'), meta: { title: '运费模板', permission: 'freight.manage' } },
      { path: 'freight/new', name: 'freight-new', component: () => import('../pages/freight/edit.vue'), meta: { title: '新建运费模板', permission: 'freight.manage' } },
      { path: 'freight/:id/edit', name: 'freight-edit', component: () => import('../pages/freight/edit.vue'), meta: { title: '编辑运费模板', permission: 'freight.manage' } },
      { path: 'unavailable', name: 'unavailable', component: () => import('../pages/unavailable.vue'), meta: { public: true, title: '页面不可用' } },
    ] },
    { path: '*', redirect: '/unavailable' },
  ],
});
router.beforeEach(async (to, from, next) => {
  if (to.meta.public) { next(); return; }
  if (!store.state.token) { next({ name: 'login', query: { redirect: to.fullPath } }); return; }
  let session;
  try { session = captureSession(); }
  catch (error) { next({ name: 'unavailable', query: { reason: 'profile' } }); return; }
  const intent = store.state.authIntent;
  let failed = false;
  try { await store.dispatch('loadMe'); }
  catch (error) { failed = true; }
  // Settle a superseded transition through Router3's original pending checks.
  if (router.history.pending !== to) { next(); return; }
  if (store.state.authIntent !== intent || store.state.sessionRevision !== session.revision) { next(false); return; }
  let current;
  try { current = isCurrentSession(session); }
  catch (error) { next({ name: 'unavailable', query: { reason: 'profile' } }); return; }
  if (!current) { next(false); return; }
  if (failed) {
    if (!store.state.token) next({ name: 'login', query: { redirect: to.fullPath } });
    else next({ name: 'unavailable', query: { reason: 'profile' } });
    return;
  }
  if (to.meta.permission && !store.getters.can(to.meta.permission)) next({ name: 'unavailable' });
  else next();
});
router.afterEach((to) => { document.title = (to.meta.title || '商家后台') + ' · ' + marketplace.name; });
export default router;
