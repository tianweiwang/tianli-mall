import Vue from 'vue';
import ElementUI from 'element-ui';
import 'element-ui/lib/theme-chalk/index.css';
import App from './App.vue';
import router from './router';
import './styles/tokens.css';
import './styles/base.css';
import './styles/customer.css';
import './styles/admin.css';
import './styles/admin-pages.css';

Vue.use(ElementUI, { size: 'small' });
Vue.config.productionTip = false;

new Vue({ router, render: (h) => h(App) }).$mount('#app');
