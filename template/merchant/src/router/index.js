import marketplace from '../../marketplace.config';
import Vue from 'vue';
import Router from 'vue-router';
import store from '../store';
import Layout from '../layout/index.vue';

Vue.use(Router);
const router = new Router({
  mode: 'hash',
  routes: [
    { path: '/login', name: 'login', component: () => import('../pages/login/index.vue'), meta: { public: true, title: '员工登录' } },
    { path: '/password-setup', name: 'password-setup', component: () => import('../pages/password-setup/index.vue'), meta: { public: true, title: '设置密码' } },
    { path: '/', component: Layout, children: [
      { path: '', name: 'home', component: () => import('../pages/home/index.vue'), meta: { title: '本店信息' } },
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
  document.title = (to.meta.title || '商家后台') + ' · ' + marketplace.name;
  if (to.meta.public) { next(); return; }
  if (!store.state.token) { next({ name: 'login', query: { redirect: to.fullPath } }); return; }
  try {
    await store.dispatch('loadMe');
    if (to.meta.permission && !store.getters.can(to.meta.permission)) next({ name: 'unavailable' });
    else next();
  } catch (error) {
    if (!store.state.token) next({ name: 'login', query: { redirect: to.fullPath } });
    else next({ name: 'unavailable', query: { reason: 'profile' } });
  }
});
export default router;
