import Vue from 'vue';
import Router from 'vue-router';
import CustomerLayout from '../layouts/CustomerLayout.vue';
import StoreLayout from '../layouts/StoreLayout.vue';
import BrandLayout from '../layouts/BrandLayout.vue';

Vue.use(Router);

const pages = import.meta.glob('../views/**/*.vue');
const placeholder = () => import('../views/PlaceholderView.vue');
const view = (role, name) => pages[`../views/${role}/${name}.vue`] || placeholder;
const meta = (role, title) => ({ role, title });

const routes = [
  {
    path: '/',
    redirect: '/c/store/TL1001',
  },
  {
    path: '/c/store/:storeId',
    component: CustomerLayout,
    children: [
      { path: '', name: 'customer-home', component: view('customer', 'Home'), meta: meta('customer', '门店首页') },
      { path: 'category', name: 'customer-category', component: view('customer', 'Category'), meta: meta('customer', '商品分类') },
      { path: 'services', name: 'customer-services', redirect: (to) => ({ path: `/c/store/${to.params.storeId}/category`, query: { tab: 'services' } }), meta: meta('customer', '门店项目') },
      { path: 'services/:serviceId', name: 'customer-service-detail', component: view('customer', 'ServiceDetail'), meta: meta('customer', '服务详情') },
      { path: 'product/:productId', name: 'customer-product', component: view('customer', 'ProductDetail'), meta: meta('customer', '商品详情') },
      { path: 'cart', name: 'customer-cart', component: view('customer', 'Cart'), meta: meta('customer', '购物车') },
      { path: 'checkout', name: 'customer-checkout', component: view('customer', 'Checkout'), meta: meta('customer', '确认订单') },
      { path: 'payment', name: 'customer-payment', component: view('customer', 'Payment'), meta: meta('customer', '模拟支付') },
      { path: 'payment-success/:orderId', name: 'customer-payment-success', component: view('customer', 'PaymentSuccess'), meta: meta('customer', '支付成功') },
      { path: 'orders', name: 'customer-orders', component: view('customer', 'Orders'), meta: meta('customer', '我的订单') },
      { path: 'orders/:orderId', name: 'customer-order-detail', component: view('customer', 'OrderDetail'), meta: meta('customer', '订单详情') },
      { path: 'booking', name: 'customer-booking-create', component: view('customer', 'BookingCreate'), meta: meta('customer', '服务预约') },
      { path: 'booking-success/:bookingId', name: 'customer-booking-success', component: view('customer', 'BookingSuccess'), meta: meta('customer', '预约成功') },
      { path: 'bookings', name: 'customer-bookings', component: view('customer', 'Bookings'), meta: meta('customer', '我的预约') },
      { path: 'bookings/:bookingId', name: 'customer-booking-detail', component: view('customer', 'BookingDetail'), meta: meta('customer', '预约详情') },
      { path: 'assessments', name: 'customer-assessments', component: view('customer', 'Assessments'), meta: meta('customer', '我的检测') },
      { path: 'assessments/:assessmentId', name: 'customer-assessment-detail', component: view('customer', 'AssessmentDetail'), meta: meta('customer', '检测报告') },
      { path: 'live', name: 'customer-live', component: view('customer', 'Live'), meta: meta('customer', '直播') },
      { path: 'live/:roomId', name: 'customer-live-room', component: view('customer', 'LiveRoom'), meta: meta('customer', '直播间') },
      { path: 'points', name: 'customer-points', component: view('customer', 'Points'), meta: meta('customer', '积分中心') },
      { path: 'membership', name: 'customer-membership', component: view('customer', 'Membership'), meta: meta('customer', '会员中心') },
      { path: 'assets', name: 'customer-assets', component: view('customer', 'Assets'), meta: meta('customer', '资产中心') },
      { path: 'referral', name: 'customer-referral', component: view('customer', 'Referral'), meta: meta('customer', '推荐有礼') },
      { path: 'share', name: 'customer-share', component: view('customer', 'Share'), meta: meta('customer', '分享中心') },
      { path: 'share-entry/:token', name: 'customer-share-entry', component: view('customer', 'ShareEntry'), meta: meta('customer', '好友推荐') },
      { path: 'distribution', name: 'customer-distribution', component: view('customer', 'Distribution'), meta: meta('customer', '分销中心') },
      { path: 'profile', name: 'customer-profile', component: view('customer', 'Profile'), meta: meta('customer', '个人中心') },
    ],
  },
  {
    path: '/store',
    component: StoreLayout,
    redirect: '/store/dashboard',
    children: [
      { path: 'dashboard', name: 'store-dashboard', component: view('store', 'Dashboard'), meta: meta('store', '门店工作台') },
      { path: 'orders', name: 'store-orders', component: view('store', 'Orders'), meta: meta('store', '订单管理') },
      { path: 'bookings', name: 'store-bookings', component: view('store', 'Bookings'), meta: meta('store', '预约管理') },
      { path: 'assessments', name: 'store-assessments', component: view('store', 'Assessments'), meta: meta('store', '检测中心') },
      { path: 'assessments/:taskId', name: 'store-assessment-detail', component: view('store', 'AssessmentDetail'), meta: meta('store', '检测任务') },
      { path: 'verify', name: 'store-verify', component: view('store', 'Verify'), meta: meta('store', '扫码核销') },
      { path: 'live', name: 'store-live', component: view('store', 'Live'), meta: meta('store', '直播管理') },
      { path: 'products', name: 'store-products', component: view('store', 'Products'), meta: meta('store', '服务与商品') },
      { path: 'performance', name: 'store-performance', component: view('store', 'Performance'), meta: meta('store', '门店业绩') },
      { path: 'settlements', name: 'store-settlements', component: view('store', 'Settlements'), meta: meta('store', '结算明细') },
      { path: 'withdrawals', name: 'store-withdrawals', component: view('store', 'Withdrawals'), meta: meta('store', '提现管理') },
      { path: 'profile', name: 'store-profile', component: view('store', 'Profile'), meta: meta('store', '门店资料') },
    ],
  },
  {
    path: '/brand',
    component: BrandLayout,
    redirect: '/brand/dashboard',
    children: [
      { path: 'dashboard', name: 'brand-dashboard', component: view('brand', 'Dashboard'), meta: meta('brand', '平台总览') },
      { path: 'stores', name: 'brand-stores', component: view('brand', 'Stores'), meta: meta('brand', '加盟门店') },
      { path: 'stores/:storeId', name: 'brand-store-detail', component: view('brand', 'StoreDetail'), meta: meta('brand', '门店详情') },
      { path: 'agents', name: 'brand-agents', component: view('brand', 'Agents'), meta: meta('brand', '区域代理') },
      { path: 'agents/:agentId', name: 'brand-agent-detail', component: view('brand', 'AgentDetail'), meta: meta('brand', '代理详情') },
      { path: 'onboarding', name: 'brand-onboarding', component: view('brand', 'Onboarding'), meta: meta('brand', '进件审核') },
      { path: 'products', name: 'brand-products', component: view('brand', 'Products'), meta: meta('brand', '服务与商品') },
      { path: 'orders', name: 'brand-orders', component: view('brand', 'Orders'), meta: meta('brand', '全平台订单') },
      { path: 'bookings', name: 'brand-bookings', component: view('brand', 'Bookings'), meta: meta('brand', '全平台预约') },
      { path: 'members', name: 'brand-members', component: view('brand', 'Members'), meta: meta('brand', '会员运营') },
      { path: 'assessments', name: 'brand-assessment-analytics', component: view('brand', 'AssessmentAnalytics'), meta: meta('brand', '检测运营') },
      { path: 'devices', name: 'brand-devices', component: view('brand', 'Devices'), meta: meta('brand', '设备管理') },
      { path: 'live', name: 'brand-live', component: view('brand', 'Live'), meta: meta('brand', '直播运营') },
      { path: 'sharing', name: 'brand-sharing', component: view('brand', 'ProfitSharing'), meta: meta('brand', '分账流水') },
      { path: 'sharing/:recordId', name: 'brand-sharing-detail', component: view('brand', 'ProfitSharingDetail'), meta: meta('brand', '分账详情') },
      { path: 'distribution', name: 'brand-distribution', component: view('brand', 'Distribution'), meta: meta('brand', '分销管理') },
      { path: 'withdrawals', name: 'brand-withdrawals', component: view('brand', 'Withdrawals'), meta: meta('brand', '提现审核') },
      { path: 'analytics', name: 'brand-analytics', component: view('brand', 'Analytics'), meta: meta('brand', '经营分析') },
    ],
  },
  {
    path: '/verify',
    name: 'verify-payload',
    component: view('verify', 'VerifyPayload'),
    meta: meta('verify', '核销码'),
  },
  {
    path: '*',
    redirect: '/c/store/TL1001',
  },
];

export function createRouter() {
  return new Router({
    mode: 'hash',
    routes,
    scrollBehavior: () => ({ x: 0, y: 0 }),
  });
}

export default createRouter();
