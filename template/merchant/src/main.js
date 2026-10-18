import { controlLabel } from './accessibility';
import Vue from 'vue';
import Router from 'vue-router';
import ElementUI from 'element-ui';
import 'element-ui/lib/theme-chalk/index.css';
import App from './App.vue';
import router from './router';
import store from './store';
import { onUnauthenticated } from './api/request';
import { isCurrentSession } from './api/session';
import './styles.css';

Vue.use(ElementUI);
Vue.directive('control-label', controlLabel);
Vue.config.productionTip = false;
onUnauthenticated((session) => {
  if (!isCurrentSession(session) || store.state.sessionRevision !== session.revision) return;
  const intent = store.state.authIntent;
  store.commit('clear', { session, intent });
  const revision = store.state.sessionRevision;
  if (router.currentRoute.name !== 'login') router.replace({ name: 'login' }).catch((error) => {
    if (store.state.authIntent !== intent || store.state.sessionRevision !== revision) return;
    if (!Router.isNavigationFailure(error)) throw error;
  });
});
new Vue({ router, store, render: (createElement) => createElement(App) }).$mount('#app');
