import Vue from 'vue';
import Vuex from 'vuex';
import { api } from '../api';
import { readToken, writeToken, clearToken } from '../api/session';

Vue.use(Vuex);
export default new Vuex.Store({
  state: { token: readToken(), profile: null },
  getters: {
    can: (state) => (permission) => !!state.profile && state.profile.role.permissions.includes(permission),
  },
  mutations: {
    token(state, value) { state.token = value; },
    profile(state, value) { state.profile = value; },
    clear(state) { clearToken(); state.token = ''; state.profile = null; },
  },
  actions: {
    async loadMe({ commit }) {
      const profile = await api.me();
      if (!profile || !profile.employee || !profile.merchant || !profile.role || !Array.isArray(profile.role.permissions)) throw new Error('未能读取本店员工资料');
      commit('profile', profile); return profile;
    },
    async login({ commit, dispatch }, body) {
      const session = await api.login(body);
      writeToken(session && session.token); commit('token', session.token);
      try { await dispatch('loadMe'); } catch (error) { commit('clear'); throw error; }
    },
    async logout({ commit }) { await api.logout(); commit('clear'); },
  },
});
