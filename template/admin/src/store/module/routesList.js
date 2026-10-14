import Vue from 'vue';
import { withMarketplaceMenus } from '@/libs/marketplace-navigation';

const routesListModule = {
  namespaced: true,
  state: {
    routesList: [],
  },
  mutations: {
    // 设置路由，菜单中使用到
    getRoutesList(state, data) {
      state.routesList = data;
    },
  },
  actions: {
    // 设置路由，菜单中使用到
    async setRoutesList({ commit, rootState }, data) {
      const menus = withMarketplaceMenus(data, rootState.userInfo.uniqueAuth);
      commit('getRoutesList', menus);
      commit('menus/getmenusNav', menus, { root: true });
      if (Vue.prototype.bus) Vue.prototype.bus.$emit('routesListChange');
    },
  },
};

export default routesListModule;
