import { cloneDeep } from 'lodash';
import { PLATFORM_VUEX_KEY, getPlatformToken, bindPlatformStateReset } from '@/utils/storage';
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
import Vuex from 'vuex';
import VuexPersistence from 'vuex-persist';

import user from './module/user';
import app from './module/app';
import menus from './module/menus';
import menu from './module/menu';
import userInfo from './module/userInfo';
import userLevel from './module/userLevel';
import order from './module/order';
import media from './module/media';
import goodSelect from './module/goodSelect';
import moren from './module/moren';
import shopping from './module/shopping';
import fresh from './module/fresh';
import kefu from './module/kefu';
import integralOrder from './module/integralOrder';
import mobildConfig from './module/mobildConfig';
import upgrade from './module/upgrade';
import layout from './module/layout';
import themeConfig from './module/themeConfig';
import routesList from './module/routesList';
import tagsViewRoutes from './module/tagsViewRoutes';
import userInfos from './module/userInfos';
import keepAliveNames from './module/keepAliveNames';

Vue.use(Vuex);
// 持久化储存
// const vuexLocal = new VuexPersistence({
//     storage: window.localStorage,
//
// })

const platformModules = { user, app, menus, menu, userInfo, userLevel, order, media, goodSelect, moren, shopping, fresh, kefu, mobildConfig, integralOrder, upgrade, layout, themeConfig, routesList, tagsViewRoutes, userInfos, keepAliveNames };
const initialPlatformState = {};
Object.keys(platformModules).forEach((name) => {
  const state = platformModules[name].state;
  initialPlatformState[name] = cloneDeep(typeof state === 'function' ? state() : state);
});
const platformAuthModules = ['user', 'app', 'userInfo', 'menus', 'menu', 'routesList', 'keepAliveNames', 'tagsViewRoutes', 'userInfos'];
const persistedPlatformModules = ['user', 'app', 'menus', 'menu', 'userInfo', 'userLevel', 'order', 'media', 'kefu', 'integralOrder', 'mobildConfig', 'upgrade', 'layout', 'themeConfig', 'routesList', 'keepAliveNames'];
function withoutPlatformAuth(saved) {
  const snapshot = {};
  persistedPlatformModules.forEach((name) => {
    if (Object.prototype.hasOwnProperty.call(saved, name)) {
      snapshot[name] = cloneDeep(platformAuthModules.includes(name) ? initialPlatformState[name] : saved[name]);
      // Keep declared presentation strings only; actor, permissions and menus stay reset.
      if (name === 'userInfo' && saved[name] && typeof saved[name] === 'object' && !Array.isArray(saved[name])) {
        ['version', 'logo', 'logoSmall', 'newOrderAudioLink'].forEach((key) => {
          if (Object.prototype.hasOwnProperty.call(saved[name], key) && typeof saved[name][key] === 'string') {
            snapshot[name][key] = saved[name][key];
          }
        });
      }
    }
  });
  return snapshot;
}

const store = new Vuex.Store({
  state: {
    //
  },
  mutations: {
    //
  },
  actions: {
    //
  },
  plugins: [
    new VuexPersistence({
      key: PLATFORM_VUEX_KEY,
      restoreState(key, storage) {
        if (!getPlatformToken()) return {};
        const raw = storage.getItem(key);
        if (raw === null) return {};
        let saved;
        try {
          saved = JSON.parse(raw);
        } catch (error) {
          if (!(error instanceof SyntaxError)) throw error;
          storage.removeItem(key);
          return {};
        }
        if (saved === null || typeof saved !== 'object' || Array.isArray(saved)) return {};
        return withoutPlatformAuth(saved);
      },
      saveState(key, state, storage) {
        if (!getPlatformToken()) {
          storage.removeItem(key);
          return;
        }
        storage.setItem(key, JSON.stringify(withoutPlatformAuth(state)));
      },
      reducer: (state) => ({
        user: state.user, //这个就是存入localStorage的值
        app: state.app,
        menus: state.menus,
        menu: state.menu,
        userInfo: state.userInfo,
        userLevel: state.userLevel,
        order: state.order,
        media: state.media,
        kefu: state.kefu,
        integralOrder: state.integralOrder,
        mobildConfig: state.mobildConfig,
        upgrade: state.upgrade,
        layout: state.layout,
        themeConfig: state.themeConfig,
        routesList: state.routesList,
        keepAliveNames: state.keepAliveNames,
      }),
      storage: window.localStorage,
    }).plugin,
  ],
  modules: {
    user,
    app,
    menus,
    menu,
    userInfo,
    userLevel,
    order,
    media,
    goodSelect,
    moren,
    shopping,
    fresh,
    kefu,
    mobildConfig,
    integralOrder,
    upgrade,
    layout,
    themeConfig,
    routesList,
    tagsViewRoutes,
    userInfos,
    keepAliveNames,
  },
});


bindPlatformStateReset(() => {
  const state = { ...store.state };
  platformAuthModules.forEach((name) => { state[name] = cloneDeep(initialPlatformState[name]); });
  store.replaceState(state);
});

export default store;
