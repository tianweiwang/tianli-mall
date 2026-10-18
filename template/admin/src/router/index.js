import { getPlatformToken } from '@/utils/storage';
import { platformAuthEpoch } from '@/libs/platform-auth-epoch';
import { formatFlatteningRoutes } from '@/libs/system';
import { clearPlatformAuth } from '@/utils/storage';
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

import Vue from 'vue';
import Router from 'vue-router';
import routes from './routers';
import Setting from '@/setting';
import store from '@/store';
import { getCookies, setTitle } from '@/libs/util';
import { includeArray } from '@/libs/auth';
import { PrevLoading } from '@/utils/loading.js';

Vue.use(Router);
// 解决 `element ui` 导航栏重复点菜单报错问题
const originalPush = Router.prototype.push;
Router.prototype.push = function push(location) {
  return originalPush.call(this, location).catch((err) => err);
};

const originalReplace = Router.prototype.replace;
Router.prototype.replace = function replace(location) {
  return originalReplace.call(this, location).catch((err) => err);
};

const router = new Router({
  routes,
  mode: Setting.routerMode,
});

// 判断路由 meta.roles 中是否包含当前登录用户权限字段
export function hasAuth(roles, route) {
  if (route.meta && route.meta.auth) return roles.some((role) => route.meta.auth.includes(role));
  else return true;
}

// 递归过滤有权限的路由
export function setFilterMenuFun(routes, role) {
  const menu = [];
  routes.forEach((route) => {
    const item = { ...route };
    if (hasAuth(role, item)) {
      if (item.children) item.children = setFilterMenuFun(item.children, role);
      menu.push(item);
    }
  });
  return menu;
}

// 递归处理多余的 layout : <router-view>，让需要访问的组件保持在第一层 layout 层。
// 因为 `keep-alive` 只能缓存二级路由
// 默认初始化时就执行
export function keepAliveSplice(to, session = null) {
  if (session && !currentPlatformNavigation(to, session)) return;
  if (to.matched && to.matched.length > 2) {
    to.matched.map((v, k) => {
      if (v.components.default instanceof Function) {
        v.components.default().then((components) => {
          if (session && !currentPlatformNavigation(to, session)) return;
          if (components.default.name === 'parent') {
            to.matched.splice(k, 1);
            if (session && !currentPlatformNavigation(to, session)) return;
            router.push({ path: to.path, query: to.query });
            keepAliveSplice(to, session);
          }
        });
      } else {
        if (v.components.default.name === 'parent') {
          to.matched.splice(k, 1);
          keepAliveSplice(to, session);
        }
      }
    });
  }
}

// 编辑模块
export function editRouterFun(to, from) {
  const onRoutes = to.meta.activeMenu ? to.meta.activeMenu : to.meta.path;
  store.commit('menu/setActivePath', onRoutes);
  if (to.name == 'crud_crud') {
    store.state.menus.oneLvRoutes.map((e) => {
      if (e.path === to.path) {
        to.meta.title = e.title;
      }
    });
  }
  if (
    [
      'product_productAdd',
      'marketing_bargainCreate',
      'marketing_storeSeckillCreate',
      'marketing_storeIntegralCreate',
      'marketing_storeCouponCreate',
    ].includes(to.name)
  ) {
    let route = to.matched[1].path.split(':')[0];
    store.state.menus.oneLvRoutes.map((e) => {
      if (route.indexOf(e.path) != -1) {
        to.meta.title = `${to.params.id ? e.title + 'ID: ' + to.params.id : '添加' + e.title}`;
      }
    });
  }
}

// 延迟关闭进度条
export function delayNProgressDone(time = 300) {
  setTimeout(() => {
    NProgress.done();
  }, time);
}

/**
 * 路由拦截
 * 权限验证
 */

function capturePlatformSession() {
  return Object.freeze({ token: getPlatformToken(), epoch: platformAuthEpoch.value });
}
function samePlatformSession(left, right) {
  return !!left && !!right && left.token === right.token && left.epoch === right.epoch;
}
function currentPlatformSession(session) {
  return samePlatformSession(session, capturePlatformSession());
}
function requirePlatformSession(session) {
  if (!currentPlatformSession(session)) throw new Error('平台会话已改变，请重新进入');
}
function currentPlatformNavigation(to, session) {
  return currentPlatformSession(session) && (router.history.pending ? router.history.pending === to : router.currentRoute === to);
}
let platformSessionReady = null;
let platformSessionPending = null;
async function refreshPlatformSession(session) {
  requirePlatformSession(session);
  if (samePlatformSession(platformSessionReady, session) && store.state.userInfo.userInfo) return;
  let record = platformSessionPending;
  if (!record || !samePlatformSession(record.session, session)) {
    record = { session, promise: null };
    platformSessionPending = record;
    record.promise = (async () => {
      const { AccountInfo, menusApi } = await import('@/api/account');
      requirePlatformSession(session);
      const [info, menu] = await Promise.all([AccountInfo(), menusApi()]);
      requirePlatformSession(session);
      const current = info.data;
      const data = menu.data;
      if (!current || !current.id || !data || !Array.isArray(data.menus) || !Array.isArray(data.unique)) {
        throw new Error('平台会话数据不完整');
      }
      const actor = { id: current.id, account: current.account, head_pic: current.head_pic, level: current.level, real_name: current.real_name };
      requirePlatformSession(session);
      store.commit('userInfo/userInfo', actor);
      store.commit('userInfo/uniqueAuth', data.unique);
      store.commit('userInfo/access', data.unique);
      store.commit('userInfo/name', actor.account);
      store.commit('userInfo/avatar', actor.head_pic);
      store.commit('menus/setopenMenus', []);
      store.commit('menus/getmenusNav', data.menus);
      await store.dispatch('routesList/setRoutesList', data.menus);
      requirePlatformSession(session);
      store.commit('menus/setOneLvMenus', formatFlatteningRoutes(router.options.routes));
      store.commit('menus/setOneLvRoute', formatFlatteningRoutes(data.menus));
      requirePlatformSession(session);
      platformSessionReady = session;
    })();
  }
  try {
    await record.promise;
    requirePlatformSession(session);
  } finally {
    if (platformSessionPending === record) platformSessionPending = null;
  }
}

router.beforeEach(async (to, from, next) => {
  // PrevLoading.start();
  const platformRoute = to.fullPath.indexOf('kefu') === -1 && to.name !== 'mobile_upload';
  const session = platformRoute ? capturePlatformSession() : null;
  keepAliveSplice(to, session);
  editRouterFun(to, from);
  if (!platformRoute) return next();
  const stopIfChanged = () => {
    // Let Router3 settle a superseded hook through its own pending identity checks.
    if (router.history.pending !== to) { next(); return true; }
    if (!currentPlatformSession(session)) { next(false); return true; }
    return false;
  };
  const login = () => {
    if (router.history.pending !== to) return next();
    const token = getPlatformToken();
    if (platformAuthEpoch.value !== session.epoch || (token && token !== session.token)) return next(false);
    clearPlatformAuth();
    if (router.history.pending !== to) return next();
    if (platformAuthEpoch.value !== session.epoch + 1 || getPlatformToken()) return next(false);
    return next({ name: 'login', query: { redirect: to.fullPath } });
  };
  // 判断是否需要登录才可以进入
  if (to.matched.some((_) => _.meta.auth)) {
    if (session.token) {
      try {
        await refreshPlatformSession(session);
      } catch (error) {
        if (router.history.pending === to && platformAuthEpoch.value === session.epoch && !getPlatformToken()) return login();
        if (stopIfChanged()) return;
        if (!getPlatformToken()) return login();
        return next(error);
      }
      if (stopIfChanged()) return;
      const access = store.state.userInfo.uniqueAuth;
      const isPermission = includeArray(to.meta.auth, access); //  判断是否有权限  TODO
      if (access.length) {
        if (!stopIfChanged()) next();
      } else {
        if (access.length == 0) {
          return login();
        } else {
          if (!stopIfChanged()) next({ name: '403' });
        }
      }
    } else {
      return login();
    }
  } else {
    // 不需要身份校验 直接通过
    if (!stopIfChanged()) next();
  }
});
router.afterEach((to) => {
  // 更改标题
  setTitle(to, router.app);
  // 返回页面顶端
  window.scrollTo(0, 0);
  PrevLoading.done();
});
export default router;
