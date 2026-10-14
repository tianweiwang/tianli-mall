// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

/**
 * 布局菜单配置
 * */
import { menusApi } from '@/api/account';
function getMenusName() {
  // Current menus come from the authenticated API, never a legacy origin-wide snapshot.
  return [];
}
export default {
  namespaced: true,
  state: {
    menusName: getMenusName(),
    openMenus: [],
    childMenuList: [],
    oneLvMenus: [],
    oneLvRoutes: [],
  },
  mutations: {
    getmenusNav(state, menuList) {
      state.menusName = menuList;
    },
    // getopenMenus (state, openList) {
    //   state.openMenus = openList
    // }
    setopenMenus(state, openList) {
      state.openMenus = openList;
    },
    setOneLvMenus(state, oneLvMenus) {
      state.oneLvMenus = oneLvMenus;
    },
    setOneLvRoute(state, oneLvMenus) {
      state.oneLvRoutes = oneLvMenus;
    },
    childMenuList(state, list) {
      state.childMenuList = list;
    },
  },
  actions: {
    getMenusNavList({ commit }) {
      return new Promise((resolve, reject) => {
        menusApi()
          .then(async (res) => {
            resolve(res);
            commit('getmenusNav', res.data.menus);
          })
          .catch((res) => {
            reject(res);
          });
      });
    },
  },
};
