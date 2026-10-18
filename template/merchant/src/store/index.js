import Vue from 'vue';
import Vuex from 'vuex';
import { api } from '../api';
import { staleSessionError } from '../api/request';
import { captureSession, isCurrentSession, writeToken, clearToken } from '../api/session';

Vue.use(Vuex);
const initialSession = captureSession();
function current(state, session, intent) {
  return !!session && Number.isSafeInteger(intent) && intent === state.authIntent
    && state.token === session.token && state.sessionRevision === session.revision && isCurrentSession(session);
}
function requireCurrent(state, session, intent) {
  if (!current(state, session, intent)) throw staleSessionError();
}
function completion(state, intent) { return Object.freeze({ intent, sessionRevision: state.sessionRevision }); }
export default new Vuex.Store({
  state: { token: initialSession.token, profile: null, authIntent: 0, sessionRevision: initialSession.revision },
  getters: {
    can: (state) => (permission) => !!state.profile && state.profile.role.permissions.includes(permission),
  },
  mutations: {
    beginAuth(state) {
      if (!Number.isSafeInteger(state.authIntent) || state.authIntent >= Number.MAX_SAFE_INTEGER) throw new Error('登录操作代次不可用，请刷新页面');
      state.authIntent += 1;
    },
    install(state, { token, intent }) {
      if (intent !== state.authIntent) throw staleSessionError();
      const revision = writeToken(token);
      state.token = token; state.profile = null; state.sessionRevision = revision;
    },
    profile(state, { value, session, intent }) {
      requireCurrent(state, session, intent);
      state.profile = value;
    },
    clear(state, { session, intent }) {
      requireCurrent(state, session, intent);
      const revision = clearToken();
      state.token = ''; state.profile = null; state.sessionRevision = revision;
    },
  },
  actions: {
    async loadMe({ state, commit }) {
      const session = captureSession(); const intent = state.authIntent;
      requireCurrent(state, session, intent);
      let profile;
      try { profile = await api.me(); }
      catch (error) { requireCurrent(state, session, intent); throw error; }
      requireCurrent(state, session, intent);
      if (!profile || !profile.employee || !profile.merchant || !profile.role || !Array.isArray(profile.role.permissions)) throw new Error('未能读取本店员工资料');
      commit('profile', { value: profile, session, intent }); return profile;
    },
    async login({ state, commit, dispatch }, body) {
      commit('beginAuth'); const intent = state.authIntent;
      let installed = null;
      try {
        const session = await api.login(body);
        if (intent !== state.authIntent) throw staleSessionError();
        commit('install', { token: session && session.token, intent });
        installed = captureSession();
        await dispatch('loadMe');
        requireCurrent(state, installed, intent);
        return completion(state, intent);
      } catch (error) {
        if (installed && current(state, installed, intent)) commit('clear', { session: installed, intent });
        if (intent !== state.authIntent) throw staleSessionError();
        throw error;
      }
    },
    async logout({ state, commit }) {
      commit('beginAuth'); const intent = state.authIntent; const session = captureSession();
      requireCurrent(state, session, intent);
      try { await api.logout(); }
      catch (error) { requireCurrent(state, session, intent); throw error; }
      requireCurrent(state, session, intent);
      commit('clear', { session, intent });
      return completion(state, intent);
    },
  },
});
