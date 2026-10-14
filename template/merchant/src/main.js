import { controlLabel } from './accessibility';
import Vue from 'vue';
import Router from 'vue-router';
import ElementUI from 'element-ui';
import 'element-ui/lib/theme-chalk/index.css';
import App from './App.vue';
import router from './router';
import store from './store';
import { onUnauthenticated } from './api/request';
import './styles.css';

Vue.use(ElementUI);
Vue.directive('control-label', controlLabel);
Vue.config.productionTip = false;
onUnauthenticated(() => {
  store.commit('clear');
  if (router.currentRoute.name !== 'login') router.replace({ name: 'login' }).catch((error) => { if (!Router.isNavigationFailure(error)) throw error; });
});
new Vue({ router, store, render: (createElement) => createElement(App) }).$mount('#app');
