# 天俪加盟门店平台 H5 Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 构建一套可独立静态部署、三角色本地联动、支持真实二维码生成与扫描的加盟门店平台 H5 Demo。

**Architecture:** 在 `demo/franchise-platform-h5/` 新建 Vue 2.7 + Vite 工程，消费者端使用全屏移动布局，门店端与品牌端共享 CRMEB 风格后台壳层。所有业务状态存入一个版本化 `localStorage` 文档，页面只通过本地仓库和集中状态机读写；二维码使用版本化 URL 载荷，扫描器支持摄像头、图片和备用编号三种入口。

**Tech Stack:** Vue 2.7.16、Vue Router 3.6.5、Element UI 2.15.14、Vite 7.3.6、ECharts 6.1.0、qrcode 1.5.4、html5-qrcode 2.3.8、Vitest 4.1.10、Playwright 1.62.0。

---

## 实施边界与文件结构

本计划只新增或修改以下路径：

```text
.gitignore
demo/franchise-platform-h5/
  index.html
  package.json
  package-lock.json
  vite.config.js
  playwright.config.js
  README.md
  scripts/
    fetch-public-demo-assets.ps1
    generate-qr-fixture.mjs
  public/products/
    ipad-mini.png
    jeep-watch.png
    xiaomi-civi.png
  src/
    main.js
    App.vue
    router/index.js
    styles/tokens.css
    styles/base.css
    styles/customer.css
    styles/admin.css
    mock/seed.js
    mock/store.js
    mock/selectors.js
    mock/transitions.js
    qr/codec.js
    qr/scanner.js
    components/demo/DemoNavigator.vue
    components/customer/CustomerHeader.vue
    components/customer/BottomNav.vue
    components/customer/ProductCard.vue
    components/customer/StatusPill.vue
    components/admin/AdminShell.vue
    components/admin/MetricStrip.vue
    components/admin/FilterBar.vue
    components/admin/DataTable.vue
    components/admin/TrendChart.vue
    components/qr/QrCode.vue
    components/qr/QrScanner.vue
    layouts/CustomerLayout.vue
    layouts/StoreLayout.vue
    layouts/BrandLayout.vue
    views/customer/*.vue
    views/store/*.vue
    views/brand/*.vue
    views/verify/VerifyPayload.vue
  tests/unit/*.spec.js
  tests/e2e/*.spec.js
  tests/fixtures/booking-qr.png
docs/demo/franchise-platform-h5-deployment.md
docs/demo/franchise-platform-h5-asset-sources.md
openspec/changes/build-franchise-platform-h5-demo/
```

不得修改 `crmeb/`、`template/admin/`、`template/uni-app/` 中的正式代码。不得读取或打包 `crmeb/.env`、`crmeb/.constant`、未知上传文件或仓库 `.codex/`。

### Task 1: 建立独立工程与测试基线

**Files:**
- Create: `demo/franchise-platform-h5/package.json`
- Create: `demo/franchise-platform-h5/index.html`
- Create: `demo/franchise-platform-h5/vite.config.js`
- Create: `demo/franchise-platform-h5/playwright.config.js`
- Create: `demo/franchise-platform-h5/src/main.js`
- Create: `demo/franchise-platform-h5/src/App.vue`
- Create: `demo/franchise-platform-h5/src/router/index.js`
- Create: `demo/franchise-platform-h5/tests/unit/app.spec.js`
- Modify: `.gitignore`

- [ ] **Step 1: 写工程烟雾测试**

```js
// tests/unit/app.spec.js
import { mount } from '@vue/test-utils';
import App from '../../src/App.vue';

describe('App', () => {
  it('renders the active route without making a network request', () => {
    const wrapper = mount(App, {
      stubs: { 'router-view': { template: '<main data-testid="route-view" />' } },
    });
    expect(wrapper.find('[data-testid="route-view"]').exists()).toBe(true);
  });
});
```

- [ ] **Step 2: 创建固定版本依赖和脚本**

```json
{
  "name": "tianli-franchise-platform-h5-demo",
  "version": "1.0.0",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite --host 0.0.0.0",
    "build": "vite build",
    "preview": "vite preview --host 0.0.0.0",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:e2e": "playwright test",
    "test:all": "npm run test && npm run build && npm run test:e2e"
  },
  "dependencies": {
    "echarts": "6.1.0",
    "element-ui": "2.15.14",
    "html5-qrcode": "2.3.8",
    "lucide-vue": "0.517.0",
    "qrcode": "1.5.4",
    "vue": "2.7.16",
    "vue-router": "3.6.5"
  },
  "devDependencies": {
    "@playwright/test": "1.62.0",
    "@vitejs/plugin-vue2": "2.3.4",
    "@vue/test-utils": "1.3.6",
    "jsdom": "30.0.1",
    "jsqr": "1.4.0",
    "pngjs": "7.0.0",
    "vite": "7.3.6",
    "vitest": "4.1.10",
    "vue-template-compiler": "2.7.16"
  }
}
```

- [ ] **Step 3: 创建相对路径构建和测试配置**

```js
// vite.config.js
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue2';

export default defineConfig({
  base: './',
  plugins: [vue()],
  server: { port: 4173 },
  preview: { port: 4174 },
  test: { environment: 'jsdom', globals: true, restoreMocks: true },
});
```

```js
// playwright.config.js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  use: { baseURL: 'http://127.0.0.1:4174', trace: 'retain-on-failure' },
  webServer: {
    command: 'npm run preview -- --port 4174',
    url: 'http://127.0.0.1:4174',
    reuseExistingServer: true,
  },
  projects: [
    { name: 'mobile-390', use: { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } } },
    { name: 'desktop-1440', use: { viewport: { width: 1440, height: 900 } } },
  ],
});
```

- [ ] **Step 4: 创建无接口启动入口**

```html
<!-- index.html -->
<div id="app"></div>
<script type="module" src="/src/main.js"></script>
```

```js
// src/main.js
import Vue from 'vue';
import ElementUI from 'element-ui';
import 'element-ui/lib/theme-chalk/index.css';
import App from './App.vue';
import router from './router';
import './styles/tokens.css';
import './styles/base.css';
import './styles/customer.css';
import './styles/admin.css';

Vue.use(ElementUI, { size: 'small' });
Vue.config.productionTip = false;

new Vue({ router, render: (h) => h(App) }).$mount('#app');
```

```vue
<!-- src/App.vue -->
<template><div id="app"><router-view /></div></template>
```

```js
// src/router/index.js（Task 5 再扩展为完整路由表）
import Vue from 'vue';
import Router from 'vue-router';

Vue.use(Router);
export default new Router({
  mode: 'hash',
  routes: [{ path: '/', component: { template: '<main data-testid="route-view">演示应用初始化完成</main>' } }],
});
```

- [ ] **Step 5: 忽略独立构建和测试产物**

在 `.gitignore` 末尾追加：

```gitignore
/demo/franchise-platform-h5/node_modules/
/demo/franchise-platform-h5/dist/
/demo/franchise-platform-h5/playwright-report/
/demo/franchise-platform-h5/test-results/
```

- [ ] **Step 6: 安装依赖并验证测试先失败、后通过**

Run: `cd demo/franchise-platform-h5 && npm install`

Run: `npm run test -- tests/unit/app.spec.js`

Expected: Step 4 前因 `src/App.vue` 尚未创建而 FAIL；完成 Step 4 后 PASS，1 test。

- [ ] **Step 7: 提交工程基线**

```powershell
git add -- .gitignore demo/franchise-platform-h5/package.json demo/franchise-platform-h5/package-lock.json demo/franchise-platform-h5/index.html demo/franchise-platform-h5/vite.config.js demo/franchise-platform-h5/playwright.config.js demo/franchise-platform-h5/src/main.js demo/franchise-platform-h5/src/App.vue demo/franchise-platform-h5/src/router/index.js demo/franchise-platform-h5/tests/unit/app.spec.js
git commit -m "chore: scaffold franchise H5 demo"
```

### Task 2: 建立可重复种子数据与本地仓库

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/seed.js`
- Create: `demo/franchise-platform-h5/src/mock/store.js`
- Create: `demo/franchise-platform-h5/src/mock/selectors.js`
- Create: `demo/franchise-platform-h5/tests/unit/store.spec.js`

- [ ] **Step 1: 写版本恢复、重置和门店隔离测试**

```js
// tests/unit/store.spec.js
import { createSeed } from '../../src/mock/seed';
import { createDemoStore, STORAGE_KEY } from '../../src/mock/store';
import { ordersForStore } from '../../src/mock/selectors';

describe('demo store', () => {
  beforeEach(() => localStorage.clear());

  it('restores the deterministic seed when stored JSON is damaged', () => {
    localStorage.setItem(STORAGE_KEY, '{broken');
    const store = createDemoStore();
    expect(store.state.notice).toContain('演示数据已恢复');
    expect(store.state.data.version).toBe(1);
    expect(store.state.data.stores).toHaveLength(3);
  });

  it('resets to the exact seed and returns to the default store', () => {
    const store = createDemoStore();
    store.commit((draft) => { draft.activeStoreId = 'TL1003'; draft.cart.push({ productId: 1, quantity: 2 }); });
    store.reset();
    expect(store.snapshot()).toEqual(createSeed());
  });

  it('never exposes another store order to a store selector', () => {
    const seed = createSeed();
    expect(ordersForStore(seed, 'TL1001').every((order) => order.storeId === 'TL1001')).toBe(true);
  });
});
```

- [ ] **Step 2: 定义确定性种子数据**

`createSeed()` 固定 `demoNow` 为 `2026-07-30T09:30:00+08:00`，金额全部使用整数分。种子至少包含下列固定业务记录：

```js
// src/mock/seed.js
export const DEMO_VERSION = 1;
export const DEMO_NOW = '2026-07-30T09:30:00+08:00';

export function createSeed() {
  return {
    version: DEMO_VERSION,
    demoNow: DEMO_NOW,
    activeRole: 'customer',
    activeStoreId: 'TL1001',
    stores: [
      { id: 'TL1001', name: '天俪锦江店', address: '锦江区春熙路 88 号', status: 'active', openHours: '09:00-21:00', commissionRate: 20, subMchId: '****3108', onboardingStatus: 'approved' },
      { id: 'TL1002', name: '天俪滨江店', address: '高新区天府三街 168 号', status: 'active', openHours: '10:00-20:00', commissionRate: 20, subMchId: '****5276', onboardingStatus: 'reviewing' },
      { id: 'TL1003', name: '天俪万象店', address: '成华区双庆路 8 号', status: 'disabled', openHours: '09:30-20:30', commissionRate: 18, subMchId: '****8421', onboardingStatus: 'rejected' },
    ],
    products: [
      { id: 4, name: 'Apple iPad mini 6 64G WLAN 版', category: '智能设备', priceCents: 399900, originalPriceCents: 479900, stock: 1600, sales: 10, image: './products/ipad-mini.png', specs: ['深空灰色', '64G WLAN'] },
      { id: 3, name: 'Jeep P07 智能蓝牙健康手表', category: '健康穿戴', priceCents: 26900, originalPriceCents: 35900, stock: 100, sales: 30, image: './products/jeep-watch.png', specs: ['曜石黑', '标准版'] },
      { id: 1, name: 'Xiaomi Civi 2 8GB+128GB', category: '智能设备', priceCents: 229900, originalPriceCents: 289900, stock: 400, sales: 18, image: './products/xiaomi-civi.png', specs: ['冰冰蓝', '8GB+128GB'] },
    ],
    services: [
      { id: 'SV1001', name: '体态健康评估', durationMinutes: 45, description: '到店完成基础体态检测与结果说明' },
      { id: 'SV1002', name: '脊柱舒缓体验', durationMinutes: 60, description: '按预约时段到店体验舒缓服务' },
      { id: 'SV1003', name: '家庭健康咨询', durationMinutes: 30, description: '面向家庭成员的日常健康咨询' },
    ],
    slotInventory: [
      { id: 'SL1001', storeId: 'TL1001', serviceId: 'SV1001', date: '2026-07-31', start: '10:00', end: '10:45', capacity: 4, booked: 1 },
      { id: 'SL1002', storeId: 'TL1001', serviceId: 'SV1002', date: '2026-07-31', start: '14:00', end: '15:00', capacity: 3, booked: 1 },
      { id: 'SL2001', storeId: 'TL1002', serviceId: 'SV1001', date: '2026-07-31', start: '11:00', end: '11:45', capacity: 2, booked: 1 },
    ],
    cart: [],
    orders: [
      { id: 'ORD202607301001', storeId: 'TL1001', status: 'completed', createdAt: DEMO_NOW, paidAt: DEMO_NOW, delivery: 'express', addressId: 'ADDR1', items: [{ productId: 3, name: 'Jeep P07 智能蓝牙健康手表', image: './products/jeep-watch.png', priceCents: 26900, quantity: 1, spec: '曜石黑' }], paidCents: 26900, brandCommissionCents: 5380, storeIncomeCents: 21520, distributionCostCents: 1345, paymentAttemptId: 'seed-pay-1001' },
      { id: 'ORD202607301002', storeId: 'TL1001', status: 'shipping', createdAt: DEMO_NOW, paidAt: DEMO_NOW, delivery: 'express', addressId: 'ADDR1', items: [{ productId: 4, name: 'Apple iPad mini 6 64G WLAN 版', image: './products/ipad-mini.png', priceCents: 399900, quantity: 1, spec: '深空灰色' }], paidCents: 399900, brandCommissionCents: 79980, storeIncomeCents: 319920, distributionCostCents: 19995, paymentAttemptId: 'seed-pay-1002' },
      { id: 'ORD202607301003', storeId: 'TL1002', status: 'paid', createdAt: DEMO_NOW, paidAt: DEMO_NOW, delivery: 'express', addressId: 'ADDR2', items: [{ productId: 1, name: 'Xiaomi Civi 2 8GB+128GB', image: './products/xiaomi-civi.png', priceCents: 229900, quantity: 1, spec: '冰冰蓝' }], paidCents: 229900, brandCommissionCents: 45980, storeIncomeCents: 183920, distributionCostCents: 11495, paymentAttemptId: 'seed-pay-1003' },
      { id: 'ORD202607301004', storeId: 'TL1002', status: 'refunded', createdAt: DEMO_NOW, paidAt: DEMO_NOW, delivery: 'express', addressId: 'ADDR2', items: [{ productId: 3, name: 'Jeep P07 智能蓝牙健康手表', image: './products/jeep-watch.png', priceCents: 26900, quantity: 2, spec: '曜石黑' }], paidCents: 53800, brandCommissionCents: 10760, storeIncomeCents: 43040, distributionCostCents: 2690, paymentAttemptId: 'seed-pay-1004' },
      { id: 'ORD202607301005', storeId: 'TL1001', status: 'paid', createdAt: DEMO_NOW, paidAt: DEMO_NOW, delivery: 'express', addressId: 'ADDR1', items: [{ productId: 1, name: 'Xiaomi Civi 2 8GB+128GB', image: './products/xiaomi-civi.png', priceCents: 229900, quantity: 1, spec: '冰冰蓝' }], paidCents: 229900, brandCommissionCents: 45980, storeIncomeCents: 183920, distributionCostCents: 11495, paymentAttemptId: 'seed-pay-1005' },
    ],
    bookings: [
      { id: 'BK202607302001', storeId: 'TL1001', serviceId: 'SV1001', slotId: 'SL1001', date: '2026-07-31', start: '10:00', end: '10:45', contactName: '陈女士', phone: '138****2046', status: 'pending', verifyCode: '826101', createdAt: DEMO_NOW, released: false },
      { id: 'BK202607302002', storeId: 'TL1001', serviceId: 'SV1002', slotId: 'SL1002', date: '2026-07-31', start: '14:00', end: '15:00', contactName: '周先生', phone: '136****5178', status: 'verified', verifyCode: '826102', createdAt: DEMO_NOW, verifiedAt: DEMO_NOW, released: false },
      { id: 'BK202607302003', storeId: 'TL1001', serviceId: 'SV1001', slotId: 'SL1001', date: '2026-07-29', start: '10:00', end: '10:45', contactName: '林女士', phone: '139****6284', status: 'cancelled', verifyCode: '826103', createdAt: DEMO_NOW, cancelledAt: DEMO_NOW, released: true },
      { id: 'BK202607302004', storeId: 'TL1002', serviceId: 'SV1001', slotId: 'SL2001', date: '2026-07-28', start: '11:00', end: '11:45', contactName: '赵先生', phone: '137****3165', status: 'expired', verifyCode: '826104', createdAt: DEMO_NOW, released: true },
      { id: 'BK202607302005', storeId: 'TL1002', serviceId: 'SV1001', slotId: 'SL2001', date: '2026-07-31', start: '11:00', end: '11:45', contactName: '吴女士', phone: '135****8092', status: 'pending', verifyCode: '826105', createdAt: DEMO_NOW, released: false },
    ],
    profitSharingRecords: [
      { id: 'PS202607303001', orderId: 'ORD202607301001', storeId: 'TL1001', status: 'completed', paidCents: 26900, brandCommissionCents: 5380, storeIncomeCents: 21520, distributionCostCents: 1345, timeline: [{ status: 'pending', at: DEMO_NOW }, { status: 'processing', at: DEMO_NOW }, { status: 'completed', at: DEMO_NOW }] },
      { id: 'PS202607303002', orderId: 'ORD202607301002', storeId: 'TL1001', status: 'processing', paidCents: 399900, brandCommissionCents: 79980, storeIncomeCents: 319920, distributionCostCents: 19995, timeline: [{ status: 'pending', at: DEMO_NOW }, { status: 'processing', at: DEMO_NOW }] },
      { id: 'PS202607303003', orderId: 'ORD202607301003', storeId: 'TL1002', status: 'pending', paidCents: 229900, brandCommissionCents: 45980, storeIncomeCents: 183920, distributionCostCents: 11495, timeline: [{ status: 'pending', at: DEMO_NOW }] },
      { id: 'PS202607303004', orderId: 'ORD202607301004', storeId: 'TL1002', status: 'returned', paidCents: 53800, brandCommissionCents: 10760, storeIncomeCents: 43040, distributionCostCents: 2690, timeline: [{ status: 'pending', at: DEMO_NOW }, { status: 'processing', at: DEMO_NOW }, { status: 'completed', at: DEMO_NOW }, { status: 'returning', at: DEMO_NOW }, { status: 'returned', at: DEMO_NOW }] },
      { id: 'PS202607303005', orderId: 'ORD202607301005', storeId: 'TL1001', status: 'returning', paidCents: 229900, brandCommissionCents: 45980, storeIncomeCents: 183920, distributionCostCents: 11495, timeline: [{ status: 'pending', at: DEMO_NOW }, { status: 'processing', at: DEMO_NOW }, { status: 'completed', at: DEMO_NOW }, { status: 'returning', at: DEMO_NOW }] },
    ],
    activityLog: [],
    sequence: { order: 1005, booking: 2005, sharing: 3005 },
  };
}
```

- [ ] **Step 3: 实现唯一的本地状态入口**

```js
// src/mock/store.js
import Vue from 'vue';
import { createSeed, DEMO_VERSION } from './seed';

export const STORAGE_KEY = 'tianli.franchise-demo.v1';
const clone = (value) => JSON.parse(JSON.stringify(value));

export function createDemoStore(storage = window.localStorage) {
  let data;
  let notice = '';
  try {
    data = JSON.parse(storage.getItem(STORAGE_KEY));
    if (!data || data.version !== DEMO_VERSION) throw new Error('version mismatch');
  } catch (error) {
    data = createSeed();
    notice = storage.getItem(STORAGE_KEY) ? '本地数据异常，演示数据已恢复。' : '';
    storage.setItem(STORAGE_KEY, JSON.stringify(data));
  }

  const state = Vue.observable({ data, notice });
  const persist = () => storage.setItem(STORAGE_KEY, JSON.stringify(state.data));
  return {
    state,
    snapshot: () => clone(state.data),
    clearNotice: () => { state.notice = ''; },
    commit(mutator) {
      const draft = clone(state.data);
      mutator(draft);
      state.data = draft;
      persist();
    },
    reset() {
      state.data = createSeed();
      state.notice = '演示数据已重置。';
      persist();
    },
  };
}

export const demoStore = createDemoStore();
```

- [ ] **Step 4: 实现派生查询并运行测试**

```js
// src/mock/selectors.js
export const activeStore = (state) => state.stores.find((item) => item.id === state.activeStoreId);
export const ordersForStore = (state, storeId) => state.orders.filter((item) => item.storeId === storeId);
export const bookingsForStore = (state, storeId) => state.bookings.filter((item) => item.storeId === storeId);
export const profitSharingForStore = (state, storeId) => state.profitSharingRecords.filter((item) => item.storeId === storeId);
export const formatMoney = (cents) => `¥${(cents / 100).toFixed(2)}`;
export const sumBy = (items, field) => items.reduce((total, item) => total + Number(item[field] || 0), 0);
```

Run: `npm run test -- tests/unit/store.spec.js`

Expected: PASS，3 tests。

- [ ] **Step 5: 提交状态基线**

```powershell
git add -- demo/franchise-platform-h5/src/mock demo/franchise-platform-h5/tests/unit/store.spec.js
git commit -m "feat: add deterministic demo state"
```

### Task 3: 用测试定义订单、预约、核销和分账状态机

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/transitions.js`
- Create: `demo/franchise-platform-h5/tests/unit/transitions.spec.js`

- [ ] **Step 1: 写金额、容量和异常核销测试**

```js
// tests/unit/transitions.spec.js
import { createSeed } from '../../src/mock/seed';
import { createPaidOrder, createBooking, cancelBooking, verifyBooking, advanceSharing } from '../../src/mock/transitions';

describe('demo transitions', () => {
  it('keeps paid amount equal to store income plus brand commission', () => {
    const state = createSeed();
    state.cart = [{ productId: 3, quantity: 2, spec: '曜石黑' }];
    const order = createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express' });
    expect(order.storeIncomeCents + order.brandCommissionCents).toBe(order.paidCents);
    expect(order.distributionCostCents).toBeLessThanOrEqual(order.brandCommissionCents);
  });

  it('holds and releases one slot exactly once', () => {
    const state = createSeed();
    const before = state.slotInventory[0].booked;
    const booking = createBooking(state, { slotId: 'SL1001', contactName: '陈女士', phone: '138****2046' });
    expect(state.slotInventory[0].booked).toBe(before + 1);
    cancelBooking(state, booking.id);
    cancelBooking(state, booking.id);
    expect(state.slotInventory[0].booked).toBe(before);
  });

  it.each([
    ['cross-store', 'TL1002', 'pending'],
    ['duplicate', 'TL1001', 'verified'],
    ['expired', 'TL1001', 'expired'],
  ])('rejects %s verification without changing the record', (kind, operatingStoreId, expectedStatus) => {
    const state = createSeed();
    state.bookings.push({ id: 'BKX', storeId: 'TL1001', status: kind === 'expired' ? 'expired' : kind === 'duplicate' ? 'verified' : 'pending', verifyCode: '826104' });
    const result = verifyBooking(state, { bookingId: 'BKX', operatingStoreId });
    expect(result.ok).toBe(false);
    expect(state.bookings.find((item) => item.id === 'BKX').status).toBe(expectedStatus);
  });

  it('advances profit sharing only along an allowed path', () => {
    const state = createSeed();
    state.profitSharingRecords.push({ id: 'PSX', status: 'pending', timeline: [] });
    expect(advanceSharing(state, 'PSX', 'processing').status).toBe('processing');
    expect(() => advanceSharing(state, 'PSX', 'returned')).toThrow('分账状态不可直接跳转');
  });
});
```

- [ ] **Step 2: 实现订单与预约创建**

```js
// src/mock/transitions.js
const nextId = (state, field, prefix) => `${prefix}${++state.sequence[field]}`;
const findRequired = (list, predicate, message) => {
  const value = list.find(predicate);
  if (!value) throw new Error(message);
  return value;
};

export function createPaidOrder(state, input) {
  if (!state.cart.length) throw new Error('购物车为空');
  const items = state.cart.map((cartItem) => {
    const product = findRequired(state.products, (item) => item.id === cartItem.productId, '商品不存在');
    return { productId: product.id, name: product.name, image: product.image, priceCents: product.priceCents, quantity: cartItem.quantity, spec: cartItem.spec };
  });
  const paidCents = items.reduce((sum, item) => sum + item.priceCents * item.quantity, 0);
  const store = findRequired(state.stores, (item) => item.id === state.activeStoreId && item.status === 'active', '当前门店不可用');
  const brandCommissionCents = Math.round(paidCents * store.commissionRate / 100);
  const order = {
    id: nextId(state, 'order', 'ORD20260730'),
    storeId: store.id,
    status: 'paid',
    createdAt: state.demoNow,
    paidAt: state.demoNow,
    delivery: input.delivery,
    addressId: input.addressId,
    items,
    paidCents,
    brandCommissionCents,
    storeIncomeCents: paidCents - brandCommissionCents,
    distributionCostCents: Math.round(paidCents * 0.05),
  };
  state.orders.unshift(order);
  state.profitSharingRecords.unshift({ id: nextId(state, 'sharing', 'PS20260730'), orderId: order.id, storeId: store.id, status: 'pending', paidCents, brandCommissionCents, storeIncomeCents: order.storeIncomeCents, distributionCostCents: order.distributionCostCents, timeline: [{ status: 'pending', at: state.demoNow }] });
  state.cart = [];
  state.activityLog.unshift({ type: 'order-paid', entityId: order.id, at: state.demoNow });
  return order;
}

export function createBooking(state, input) {
  const slot = findRequired(state.slotInventory, (item) => item.id === input.slotId, '预约时段不存在');
  if (slot.storeId !== state.activeStoreId) throw new Error('预约时段不属于当前门店');
  if (slot.booked >= slot.capacity) throw new Error('当前时段已满');
  slot.booked += 1;
  const booking = { id: nextId(state, 'booking', 'BK20260730'), storeId: slot.storeId, serviceId: slot.serviceId, slotId: slot.id, date: slot.date, start: slot.start, end: slot.end, contactName: input.contactName, phone: input.phone, status: 'pending', verifyCode: String(state.sequence.booking).padStart(6, '8'), createdAt: state.demoNow, released: false };
  state.bookings.unshift(booking);
  state.activityLog.unshift({ type: 'booking-created', entityId: booking.id, at: state.demoNow });
  return booking;
}
```

- [ ] **Step 3: 实现幂等取消、四类核销和分账推进**

```js
export function cancelBooking(state, bookingId) {
  const booking = findRequired(state.bookings, (item) => item.id === bookingId, '预约不存在');
  if (booking.status !== 'pending') return booking;
  booking.status = 'cancelled';
  booking.cancelledAt = state.demoNow;
  if (!booking.released) {
    const slot = findRequired(state.slotInventory, (item) => item.id === booking.slotId, '预约时段不存在');
    slot.booked = Math.max(0, slot.booked - 1);
    booking.released = true;
  }
  return booking;
}

export function verifyBooking(state, { bookingId, operatingStoreId }) {
  const booking = state.bookings.find((item) => item.id === bookingId);
  if (!booking) return { ok: false, code: 'not-found', message: '未找到预约记录' };
  if (booking.storeId !== operatingStoreId) return { ok: false, code: 'cross-store', message: '该预约属于其他门店' };
  if (booking.status === 'verified') return { ok: false, code: 'duplicate', message: '该预约已核销' };
  if (booking.status === 'expired') return { ok: false, code: 'expired', message: '该预约已过期' };
  if (booking.status !== 'pending') return { ok: false, code: booking.status, message: '当前状态不可核销' };
  booking.status = 'verified';
  booking.verifiedAt = state.demoNow;
  state.activityLog.unshift({ type: 'booking-verified', entityId: booking.id, storeId: operatingStoreId, at: state.demoNow });
  return { ok: true, code: 'verified', booking };
}

const sharingTransitions = {
  pending: ['processing'],
  processing: ['completed', 'returning'],
  completed: ['returning'],
  returning: ['returned'],
  returned: [],
};

export function advanceSharing(state, recordId, nextStatus) {
  const record = findRequired(state.profitSharingRecords, (item) => item.id === recordId, '分账记录不存在');
  if (!sharingTransitions[record.status].includes(nextStatus)) throw new Error('分账状态不可直接跳转');
  record.status = nextStatus;
  record.timeline.push({ status: nextStatus, at: state.demoNow });
  return record;
}
```

- [ ] **Step 4: 运行全套状态测试**

Run: `npm run test -- tests/unit/store.spec.js tests/unit/transitions.spec.js`

Expected: PASS，所有金额、容量、隔离和状态机测试通过。

- [ ] **Step 5: 提交业务状态机**

```powershell
git add -- demo/franchise-platform-h5/src/mock/transitions.js demo/franchise-platform-h5/tests/unit/transitions.spec.js
git commit -m "feat: add linked demo business transitions"
```

### Task 4: 实现可回读二维码与扫描适配层

**Files:**
- Create: `demo/franchise-platform-h5/src/qr/codec.js`
- Create: `demo/franchise-platform-h5/src/qr/scanner.js`
- Create: `demo/franchise-platform-h5/src/components/qr/QrCode.vue`
- Create: `demo/franchise-platform-h5/src/components/qr/QrScanner.vue`
- Create: `demo/franchise-platform-h5/tests/unit/qr.spec.js`

- [ ] **Step 1: 写载荷往返和 PNG 回读测试**

```js
// tests/unit/qr.spec.js
import QRCode from 'qrcode';
import jsQR from 'jsqr';
import { PNG } from 'pngjs';
import { buildBusinessUrl, parseBusinessUrl } from '../../src/qr/codec';

it('round-trips a versioned booking payload', () => {
  const url = buildBusinessUrl('https://demo.example.com/app/', { type: 'booking', id: 'BK2007', storeId: 'TL1001', issuedAt: 1785375000 });
  expect(parseBusinessUrl(url)).toMatchObject({ version: 1, type: 'booking', id: 'BK2007', storeId: 'TL1001' });
});

it('decodes the generated PNG back to the exact URL', async () => {
  const url = buildBusinessUrl('https://demo.example.com/app/', { type: 'booking', id: 'BK2007', storeId: 'TL1001', issuedAt: 1785375000 });
  const png = PNG.sync.read(await QRCode.toBuffer(url, { width: 320, margin: 2 }));
  const decoded = jsQR(new Uint8ClampedArray(png.data), png.width, png.height);
  expect(decoded.data).toBe(url);
});
```

- [ ] **Step 2: 实现带损坏检测的 Demo URL**

```js
// src/qr/codec.js
const checksum = (text) => {
  let hash = 5381;
  for (let index = 0; index < text.length; index += 1) hash = ((hash << 5) + hash) ^ text.charCodeAt(index);
  return (hash >>> 0).toString(36);
};

export function buildStoreUrl(baseUrl, storeId) {
  return `${baseUrl.replace(/#.*$/, '')}#/c/store/${encodeURIComponent(storeId)}`;
}

export function buildBusinessUrl(baseUrl, input) {
  const body = `1|${input.type}|${input.id}|${input.storeId}|${input.issuedAt}`;
  const payload = btoa(unescape(encodeURIComponent(body))).replaceAll('+', '-').replaceAll('/', '_').replaceAll('=', '');
  return `${baseUrl.replace(/#.*$/, '')}#/verify?p=${payload}&c=${checksum(body)}`;
}

export function parseBusinessUrl(value) {
  const parsed = new URL(value, window.location.href);
  const routeQuery = parsed.hash.split('?')[1] || '';
  const params = new URLSearchParams(routeQuery);
  const encoded = params.get('p');
  const normalized = encoded.replaceAll('-', '+').replaceAll('_', '/');
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
  const body = decodeURIComponent(escape(atob(padded)));
  if (checksum(body) !== params.get('c')) throw new Error('二维码内容已损坏');
  const [version, type, id, storeId, issuedAt] = body.split('|');
  if (version !== '1' || !['booking', 'order'].includes(type)) throw new Error('不支持的二维码版本');
  return { version: Number(version), type, id, storeId, issuedAt: Number(issuedAt) };
}
```

- [ ] **Step 3: 封装成熟扫描库，不让页面直接操作摄像头**

```js
// src/qr/scanner.js
import { Html5Qrcode } from 'html5-qrcode';

export function createScanner(elementId) {
  const instance = new Html5Qrcode(elementId, { verbose: false });
  return {
    start(onResult, onError) {
      return instance.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 250 } }, onResult, onError);
    },
    scanFile(file) { return instance.scanFile(file, true); },
    async stop() { if (instance.isScanning) await instance.stop(); await instance.clear(); },
  };
}
```

`QrCode.vue` 接收 `value` 和 `label`，调用 `QRCode.toDataURL(value, { width: 320, margin: 2, errorCorrectionLevel: 'M' })` 并渲染可下载图片；`QrScanner.vue` 提供“打开摄像头”“识别二维码图片”和“输入备用编号”三个明确入口，销毁组件时调用 `stop()`。

- [ ] **Step 4: 运行二维码测试并提交**

Run: `npm run test -- tests/unit/qr.spec.js`

Expected: PASS，生成 PNG 能回读出完全相同的 URL。

```powershell
git add -- demo/franchise-platform-h5/src/qr demo/franchise-platform-h5/src/components/qr demo/franchise-platform-h5/tests/unit/qr.spec.js
git commit -m "feat: add real demo QR generation and scanning"
```

### Task 5: 建立三角色路由、壳层和视觉系统

**Files:**
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Create: `demo/franchise-platform-h5/src/styles/*.css`
- Create: `demo/franchise-platform-h5/src/layouts/*.vue`
- Create: `demo/franchise-platform-h5/src/components/demo/DemoNavigator.vue`
- Create: `demo/franchise-platform-h5/src/components/customer/CustomerHeader.vue`
- Create: `demo/franchise-platform-h5/src/components/customer/BottomNav.vue`
- Create: `demo/franchise-platform-h5/src/components/admin/AdminShell.vue`
- Create: `demo/franchise-platform-h5/tests/unit/router.spec.js`

- [ ] **Step 1: 写默认入口和角色路由测试**

```js
// tests/unit/router.spec.js
import router from '../../src/router';

it('defaults to the consumer store home', async () => {
  await router.push('/');
  expect(router.currentRoute.fullPath).toBe('/c/store/TL1001');
});

it.each(['/c/store/TL1001', '/store/dashboard', '/brand/dashboard'])('resolves %s', (path) => {
  expect(router.match(path).matched.length).toBeGreaterThan(0);
});
```

- [ ] **Step 2: 创建 hash 路由与延迟加载页面表**

```js
// src/router/index.js
import Vue from 'vue';
import Router from 'vue-router';
import CustomerLayout from '../layouts/CustomerLayout.vue';
import StoreLayout from '../layouts/StoreLayout.vue';
import BrandLayout from '../layouts/BrandLayout.vue';

Vue.use(Router);
const pages = import.meta.glob('../views/**/*.vue');
const view = (role, name) => pages[`../views/${role}/${name}.vue`];

const router = new Router({
  mode: 'hash',
  routes: [
    { path: '/', redirect: '/c/store/TL1001' },
    { path: '/c/store/:storeId', component: CustomerLayout, children: [
      { path: '', name: 'customer-home', component: view('customer', 'Home') },
      { path: 'category', component: view('customer', 'Category') },
      { path: 'product/:productId', component: view('customer', 'ProductDetail') },
      { path: 'cart', component: view('customer', 'Cart') },
      { path: 'checkout', component: view('customer', 'Checkout') },
      { path: 'payment', component: view('customer', 'Payment') },
      { path: 'payment-success/:orderId', component: view('customer', 'PaymentSuccess') },
      { path: 'orders', component: view('customer', 'Orders') },
      { path: 'orders/:orderId', component: view('customer', 'OrderDetail') },
      { path: 'booking', component: view('customer', 'BookingCreate') },
      { path: 'booking-success/:bookingId', component: view('customer', 'BookingSuccess') },
      { path: 'bookings', component: view('customer', 'Bookings') },
      { path: 'bookings/:bookingId', component: view('customer', 'BookingDetail') },
      { path: 'profile', component: view('customer', 'Profile') },
    ] },
    { path: '/store', component: StoreLayout, redirect: '/store/dashboard', children: [
      { path: 'dashboard', component: view('store', 'Dashboard') },
      { path: 'orders', component: view('store', 'Orders') },
      { path: 'bookings', component: view('store', 'Bookings') },
      { path: 'verify', component: view('store', 'Verify') },
      { path: 'products', component: view('store', 'Products') },
      { path: 'performance', component: view('store', 'Performance') },
      { path: 'settlements', component: view('store', 'Settlements') },
      { path: 'profile', component: view('store', 'Profile') },
    ] },
    { path: '/brand', component: BrandLayout, redirect: '/brand/dashboard', children: [
      { path: 'dashboard', component: view('brand', 'Dashboard') },
      { path: 'stores', component: view('brand', 'Stores') },
      { path: 'stores/:storeId', component: view('brand', 'StoreDetail') },
      { path: 'onboarding', component: view('brand', 'Onboarding') },
      { path: 'products', component: view('brand', 'Products') },
      { path: 'orders', component: view('brand', 'Orders') },
      { path: 'bookings', component: view('brand', 'Bookings') },
      { path: 'sharing', component: view('brand', 'ProfitSharing') },
      { path: 'sharing/:recordId', component: view('brand', 'ProfitSharingDetail') },
      { path: 'analytics', component: view('brand', 'Analytics') },
    ] },
    { path: '/verify', component: () => import('../views/verify/VerifyPayload.vue') },
  ],
});

export default router;
```

- [ ] **Step 3: 创建 CRMEB 视觉变量和稳定布局约束**

`tokens.css` 固定主色 `#e93323`、页面背景 `#f5f5f5`、后台强调色 `#2a7efb`、成功色 `#19be6b`、警告色 `#f0ad4e`、危险色 `#dd524d`，卡片圆角不超过 `8px`。`customer.css` 将消费者内容宽度限制为 `430px` 但不绘制手机边框；`admin.css` 使用 `220px minmax(0, 1fr)` 侧栏网格，并在 `1280px` 以上保持表格区域不横向挤压。

- [ ] **Step 4: 实现消费者和后台壳层**

`CustomerLayout.vue` 在进入或门店参数变化时验证门店；有效门店写入 `activeStoreId`，停用门店展示选择页，跨店且购物车非空时使用确认对话框。`StoreLayout.vue` 和 `BrandLayout.vue` 向 `AdminShell.vue` 分别传入门店菜单与品牌菜单。三个壳层都挂载右上角 `DemoNavigator.vue`。

`DemoNavigator.vue` 使用 `MoreHorizontal` 图标按钮和菜单，不以长文字悬浮在业务内容上；菜单命令为“消费者端”“门店端”“品牌方后台”“重置演示数据”。重置必须二次确认并跳转 `/c/store/TL1001`。

- [ ] **Step 5: 运行路由测试和三视口壳层检查**

Run: `npm run test -- tests/unit/app.spec.js tests/unit/router.spec.js`

Run: `npm run dev -- --port 4173`

Expected: `/c/store/TL1001` 为无手机边框的全屏移动 H5；`/store/dashboard` 与 `/brand/dashboard` 为 CRMEB 风格桌面壳层，角色导航不遮挡主操作。

- [ ] **Step 6: 提交壳层**

```powershell
git add -- demo/franchise-platform-h5/src/router demo/franchise-platform-h5/src/styles demo/franchise-platform-h5/src/layouts demo/franchise-platform-h5/src/components/demo demo/franchise-platform-h5/src/components/customer/CustomerHeader.vue demo/franchise-platform-h5/src/components/customer/BottomNav.vue demo/franchise-platform-h5/src/components/admin/AdminShell.vue demo/franchise-platform-h5/tests/unit/router.spec.js
git commit -m "feat: add three-role demo shell"
```

### Task 6: 复制允许清单素材并实现消费者商品浏览

**Files:**
- Create: `demo/franchise-platform-h5/scripts/fetch-public-demo-assets.ps1`
- Create: `demo/franchise-platform-h5/public/products/*`
- Create: `docs/demo/franchise-platform-h5-asset-sources.md`
- Create: `demo/franchise-platform-h5/src/components/customer/ProductCard.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Home.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Category.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/ProductDetail.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Cart.vue`
- Create: `demo/franchise-platform-h5/tests/unit/cart.spec.js`

- [ ] **Step 1: 建立公开示例商品素材允许清单**

脚本只请求当前公开商品 API 中已确认的商品 ID 4、3、1，并把三张图片下载为固定文件名。它不得遍历 `crmeb/public/uploads/`。

```powershell
$ErrorActionPreference = 'Stop'
$assetDir = Join-Path $PSScriptRoot '..\public\products'
New-Item -ItemType Directory -Force -Path $assetDir | Out-Null
$products = @(
  @{ Id = 4; File = 'ipad-mini.png'; Url = 'http://127.0.0.1:8011/uploads/attach/2023/02/20230213/5b64e6584c06020347a5fa58af79d42a.png' },
  @{ Id = 3; File = 'jeep-watch.png'; Url = 'http://127.0.0.1:8011/uploads/attach/2023/02/20230213/5ae4f6a3f8bf153beb849e02e14b5c40.png' },
  @{ Id = 1; File = 'xiaomi-civi.png'; Url = 'http://127.0.0.1:8011/uploads/attach/2023/02/20230213/3b570808fc3593aa51b2a94ceaba3e6f.png' }
)
foreach ($product in $products) {
  Invoke-WebRequest -UseBasicParsing -Uri $product.Url -OutFile (Join-Path $assetDir $product.File)
}
```

`docs/demo/franchise-platform-h5-asset-sources.md` 记录商品 ID、原公开 URL、抓取日期 `2026-07-30` 和“仅用于本地演示”的用途。该来源记录不进入 `dist/`，运行时 seed 只引用 `./products/*.png`。

- [ ] **Step 2: 写购物车门店切换和数量测试**

测试覆盖同商品合并数量、数量最小为 1、删除商品、跨店确认后清空、取消切店时保留购物车和当前门店。

- [ ] **Step 3: 实现首页与商品组件**

`Home.vue` 首屏必须依次展示：当前门店名称/地址/营业状态、紧凑搜索入口、真实商品轮播或横向焦点图、分类入口、服务预约入口和三件推荐商品。`ProductCard.vue` 固定图片纵横比 `1 / 1`，显示商品名、价格、划线原价、销量和加入购物车图标按钮。

- [ ] **Step 4: 实现分类、详情和购物车**

`Category.vue` 使用左侧分类和右侧商品列表；`ProductDetail.vue` 展示大图、规格、价格、集团统一配送、当前门店归属和加入购物车；`Cart.vue` 提供数量步进器、删除、金额合计和去结算。四项底部导航在首页、分类、购物车、个人中心保持固定高度，不因角标变化跳动。

- [ ] **Step 5: 运行测试与移动视口检查并提交**

Run: `npm run test -- tests/unit/cart.spec.js`

Expected: PASS，跨店购物车规则与数量规则均被覆盖。

```powershell
git add -- demo/franchise-platform-h5/scripts/fetch-public-demo-assets.ps1 demo/franchise-platform-h5/public/products docs/demo/franchise-platform-h5-asset-sources.md demo/franchise-platform-h5/src/components/customer demo/franchise-platform-h5/src/views/customer/Home.vue demo/franchise-platform-h5/src/views/customer/Category.vue demo/franchise-platform-h5/src/views/customer/ProductDetail.vue demo/franchise-platform-h5/src/views/customer/Cart.vue demo/franchise-platform-h5/tests/unit/cart.spec.js
git commit -m "feat: add consumer catalog and cart"
```

### Task 7: 实现消费者下单与模拟支付链路

**Files:**
- Create: `demo/franchise-platform-h5/src/views/customer/Checkout.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Payment.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/PaymentSuccess.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Orders.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/OrderDetail.vue`
- Create: `demo/franchise-platform-h5/tests/unit/order-flow.spec.js`

- [ ] **Step 1: 写重复支付和跨角色同步测试**

测试从购物车提交一次模拟支付，断言只生成一条订单和一条分账记录；再次提交相同 `paymentAttemptId` 必须返回第一次的订单。随后用 `ordersForStore()` 和品牌全量选择器读取到同一订单 ID。

- [ ] **Step 2: 给状态机增加支付幂等键**

`createPaidOrder()` 接收 `paymentAttemptId`，创建前查找同键订单；存在时直接返回，创建时保存该字段。UI 点击支付后立即禁用按钮并显示“支付处理中”，完成后跳转成功页。

- [ ] **Step 3: 实现确认订单与模拟微信支付**

`Checkout.vue` 展示固定演示地址、商品、配送方式、门店归属和金额；`Payment.vue` 使用微信支付风格的白底确认面板，但页首固定显示“演示支付，不会产生真实扣款”。主按钮只调用本地状态机。

- [ ] **Step 4: 实现成功页、订单列表和详情**

订单列表提供全部、待支付、已支付、配送中、已完成筛选。详情展示商品、配送进度、门店归属、实付金额，并用可展开说明展示门店预计收入与品牌抽成；消费者页不显示品牌内部的分销成本明细。

- [ ] **Step 5: 运行测试和提交**

Run: `npm run test -- tests/unit/order-flow.spec.js tests/unit/transitions.spec.js`

Expected: PASS，重复点击不生成第二条订单，门店收入加品牌抽成等于实付金额。

```powershell
git add -- demo/franchise-platform-h5/src/mock/transitions.js demo/franchise-platform-h5/src/views/customer/Checkout.vue demo/franchise-platform-h5/src/views/customer/Payment.vue demo/franchise-platform-h5/src/views/customer/PaymentSuccess.vue demo/franchise-platform-h5/src/views/customer/Orders.vue demo/franchise-platform-h5/src/views/customer/OrderDetail.vue demo/franchise-platform-h5/tests/unit/order-flow.spec.js
git commit -m "feat: add consumer demo checkout flow"
```

### Task 8: 实现消费者预约、取消和核销码页面

**Files:**
- Create: `demo/franchise-platform-h5/src/views/customer/BookingCreate.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/BookingSuccess.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Bookings.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/BookingDetail.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Profile.vue`
- Create: `demo/franchise-platform-h5/src/components/customer/StatusPill.vue`
- Create: `demo/franchise-platform-h5/tests/unit/booking-flow.spec.js`

- [ ] **Step 1: 写容量占位、满额、取消和历史归属测试**

测试选择 `SL1001` 创建预约后 `booked + 1`，满额时抛出“当前时段已满”，取消两次只释放一次容量；切换 `activeStoreId` 后历史预约的 `storeId` 不变。

- [ ] **Step 2: 实现服务、日期和时段三段选择器**

`BookingCreate.vue` 只展示当前门店的时段，容量显示“剩余 N 位”；满额按钮禁用。联系人使用预填的明确模拟姓名和掩码手机号，提交时不创建订单或支付记录。

- [ ] **Step 3: 实现成功页和真实核销码**

`BookingSuccess.vue` 与 `BookingDetail.vue` 用 `buildBusinessUrl(window.location.href, ...)` 生成二维码。页面同时显示门店、日期、时段、状态、二维码和 6 位备用编号，并写明“二维码为演示数据”。

- [ ] **Step 4: 实现预约列表、取消和个人中心**

预约列表提供待到店、已核销、已取消、已过期筛选。只有待到店状态显示取消命令，二次确认后调用 `cancelBooking()`。个人中心展示订单、预约、地址入口和演示角色入口，不制作登录框。

- [ ] **Step 5: 运行测试和提交**

Run: `npm run test -- tests/unit/booking-flow.spec.js tests/unit/qr.spec.js`

Expected: PASS，预约不产生订单、取消幂等、二维码可回读。

```powershell
git add -- demo/franchise-platform-h5/src/views/customer/BookingCreate.vue demo/franchise-platform-h5/src/views/customer/BookingSuccess.vue demo/franchise-platform-h5/src/views/customer/Bookings.vue demo/franchise-platform-h5/src/views/customer/BookingDetail.vue demo/franchise-platform-h5/src/views/customer/Profile.vue demo/franchise-platform-h5/src/components/customer/StatusPill.vue demo/franchise-platform-h5/tests/unit/booking-flow.spec.js
git commit -m "feat: add consumer booking journey"
```

### Task 9: 实现门店工作台与经营页面

**Files:**
- Create: `demo/franchise-platform-h5/src/components/admin/MetricStrip.vue`
- Create: `demo/franchise-platform-h5/src/components/admin/FilterBar.vue`
- Create: `demo/franchise-platform-h5/src/components/admin/DataTable.vue`
- Create: `demo/franchise-platform-h5/src/components/admin/TrendChart.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Dashboard.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Orders.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Bookings.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Products.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Performance.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Settlements.vue`
- Create: `demo/franchise-platform-h5/src/views/store/Profile.vue`
- Create: `demo/franchise-platform-h5/tests/unit/store-views.spec.js`

- [ ] **Step 1: 写门店数据隔离和指标测试**

为种子状态分别计算 TL1001 和 TL1002 的订单额、订单数、预约到店率、预计收益和待分账金额，断言每个结果只使用对应 `storeId`。统一商品库断言没有新增、编辑、上下架或库存修改命令。

- [ ] **Step 2: 实现紧凑后台通用组件**

`MetricStrip.vue` 使用稳定四列网格，不嵌套卡片；`FilterBar.vue` 使用 Element 表单控件；`DataTable.vue` 统一空状态、金额、状态标签和分页；`TrendChart.vue` 在销毁时释放 ECharts 实例并响应容器尺寸变化。

- [ ] **Step 3: 实现门店工作台、订单和预约**

工作台展示今日订单、预约、销售额、预计收益、待处理事项和 7 日趋势。订单页只读查看本店订单，不显示发货。预约页按日期、时段、状态筛选，并提供跳转扫码核销的主命令。

- [ ] **Step 4: 实现商品、业绩、结算和资料**

统一商品库只读显示集团商品。业绩页展示成交额、订单数、预约到店率、趋势和本店在三店中的排名。结算页展示订单金额、品牌抽成、门店预计收入和分账状态。资料页显示地址、营业时间、联系人、进件状态和掩码子商户号。

- [ ] **Step 5: 运行测试和桌面检查并提交**

Run: `npm run test -- tests/unit/store-views.spec.js`

Expected: PASS，门店端任何列表都不含其他门店明细。

```powershell
git add -- demo/franchise-platform-h5/src/components/admin demo/franchise-platform-h5/src/views/store demo/franchise-platform-h5/tests/unit/store-views.spec.js
git commit -m "feat: add franchise store workspace"
```

### Task 10: 实现门店真实扫码核销与异常结果

**Files:**
- Create: `demo/franchise-platform-h5/src/views/store/Verify.vue`
- Create: `demo/franchise-platform-h5/src/views/verify/VerifyPayload.vue`
- Create: `demo/franchise-platform-h5/tests/unit/verify-flow.spec.js`

- [ ] **Step 1: 写扫描结果映射和跨设备导入测试**

测试本机记录正常核销、跨店拒绝、重复拒绝、过期拒绝、损坏载荷拒绝，以及有效载荷在本机无记录时导入 `source: 'qr-import'` 的模拟预约。导入后只更新扫描设备状态，并返回 `localOnly: true`。

- [ ] **Step 2: 实现统一载荷处理函数**

在 `transitions.js` 增加 `processScannedPayload(state, rawValue, operatingStoreId)`：先调用 `parseBusinessUrl()`，再查找已有预约；不存在时从二维码载荷构造最小演示记录；最后调用 `verifyBooking()`。载荷门店与当前门店不同不得导入。

- [ ] **Step 3: 实现扫码核销页面**

`Verify.vue` 使用 `QrScanner.vue`，扫描后先展示门店、预约人掩码、时段和当前状态，再显示“确认核销”。结果区域分别使用成功、跨店、重复、过期、损坏五种明确状态。摄像头权限失败时保留图片上传和备用编号输入。

- [ ] **Step 4: 实现二维码直接打开页面**

`VerifyPayload.vue` 解析当前 URL 后显示“请切换到门店端完成核销”，并提供进入门店核销页的按钮。它不得在消费者直接打开二维码时自动改变状态。

- [ ] **Step 5: 运行测试和提交**

Run: `npm run test -- tests/unit/verify-flow.spec.js tests/unit/transitions.spec.js tests/unit/qr.spec.js`

Expected: PASS，四类核销结果和跨设备本机导入均符合设计。

```powershell
git add -- demo/franchise-platform-h5/src/mock/transitions.js demo/franchise-platform-h5/src/views/store/Verify.vue demo/franchise-platform-h5/src/views/verify/VerifyPayload.vue demo/franchise-platform-h5/tests/unit/verify-flow.spec.js
git commit -m "feat: add store QR verification flow"
```

### Task 11: 实现品牌方门店、全局订单、分账和分析页面

**Files:**
- Create: `demo/franchise-platform-h5/src/views/brand/Dashboard.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/Stores.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/StoreDetail.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/Onboarding.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/Products.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/Orders.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/Bookings.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/ProfitSharing.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/ProfitSharingDetail.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/Analytics.vue`
- Create: `demo/franchise-platform-h5/tests/unit/brand-views.spec.js`

- [ ] **Step 1: 写品牌全局统计和分账公式测试**

测试品牌总览汇总全部门店；筛选 TL1001 时只返回该店记录；每条分账满足 `storeIncomeCents + brandCommissionCents === paidCents` 且 `distributionCostCents <= brandCommissionCents`；分账时间线只允许 Task 3 的状态路径。

- [ ] **Step 2: 实现总览、门店与进件页面**

平台总览展示有效门店数、订单额、品牌抽成、待分账、7 日趋势和门店排行。加盟门店页展示启停与进件状态。门店详情展示明确模拟的证照摘要、掩码子商户号、抽成比例和经营数据。进件审核页只推进模拟状态，不包含真实证件上传。

- [ ] **Step 3: 实现统一商品、全平台订单和预约**

统一商品页展示集团商品、分类、价格和销售状态；订单和预约页按门店、日期、时段、状态筛选。品牌端可跨店查看，门店端隔离逻辑不复用为品牌过滤条件。

- [ ] **Step 4: 实现分账与经营分析**

分账流水展示待分账、处理中、已完成、回退中、已回退。详情页展示订单金额、门店收入、品牌抽成、由品牌承担的分销成本和时间线，并用明确按钮推进允许的下一状态。经营分析展示门店排行、销售趋势、预约到店率和品类结构。

- [ ] **Step 5: 运行测试和提交**

Run: `npm run test -- tests/unit/brand-views.spec.js tests/unit/transitions.spec.js`

Expected: PASS，品牌汇总、门店筛选和资金恒等式全部通过。

```powershell
git add -- demo/franchise-platform-h5/src/views/brand demo/franchise-platform-h5/tests/unit/brand-views.spec.js
git commit -m "feat: add brand operations workspace"
```

### Task 12: 建立完整浏览器验收与视觉检查

**Files:**
- Create: `demo/franchise-platform-h5/scripts/generate-qr-fixture.mjs`
- Create: `demo/franchise-platform-h5/tests/e2e/demo-journey.spec.js`
- Create: `demo/franchise-platform-h5/tests/e2e/responsive.spec.js`
- Create: `demo/franchise-platform-h5/tests/fixtures/booking-qr.png`

- [ ] **Step 1: 生成可上传的二维码测试图片**

```js
// scripts/generate-qr-fixture.mjs
import QRCode from 'qrcode';
import { buildBusinessUrl } from '../src/qr/codec.js';

const url = buildBusinessUrl('http://127.0.0.1:4174/', { type: 'booking', id: 'BK2007', storeId: 'TL1001', issuedAt: 1785375000 });
await QRCode.toFile(new URL('../tests/fixtures/booking-qr.png', import.meta.url), url, { width: 480, margin: 2 });
```

- [ ] **Step 2: 写一条连续主演示链路**

```js
// tests/e2e/demo-journey.spec.js
import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';

test('store entry to order, booking, verification and sharing', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/#/c/store/TL1001');
  await expect(page.getByText('天俪锦江店')).toBeVisible();
  await page.getByTestId('product-3').getByRole('button', { name: '加入购物车' }).click();
  await page.getByRole('link', { name: '购物车' }).click();
  await page.getByRole('button', { name: '去结算' }).click();
  await page.getByRole('button', { name: '去支付' }).click();
  await page.getByRole('button', { name: '确认支付' }).click();
  const orderId = (await page.getByTestId('order-id').textContent()).trim();
  await page.goto('/#/c/store/TL1001/booking');
  await page.getByText('体态健康评估').click();
  await page.getByTestId('booking-date-2026-07-31').click();
  await page.getByText('10:00-10:45').click();
  await page.getByRole('button', { name: '确认预约' }).click();
  const bookingId = (await page.getByTestId('booking-id').textContent()).trim();
  const verifyCode = (await page.getByTestId('verify-code').textContent()).trim();
  await page.goto('/#/store/verify');
  await page.getByLabel('备用核销编号').fill(verifyCode);
  await page.getByRole('button', { name: '查询预约' }).click();
  await page.getByRole('button', { name: '确认核销' }).click();
  await page.goto('/#/store/bookings');
  await expect(page.getByTestId(`booking-row-${bookingId}`)).toContainText('已核销');
  await page.goto('/#/store/performance');
  await expect(page.getByText('预约到店率')).toBeVisible();
  await page.goto('/#/brand/sharing');
  await expect(page.getByText(orderId)).toBeVisible();
  expect(errors).toEqual([]);
});

test('imports a valid QR image on the scanning device only', async ({ page }) => {
  await page.goto('/#/store/verify');
  const fixture = fileURLToPath(new URL('../fixtures/booking-qr.png', import.meta.url));
  await page.getByLabel('识别二维码图片').setInputFiles(fixture);
  await expect(page.getByTestId('scan-preview')).toContainText('BK2007');
  await page.getByRole('button', { name: '确认核销' }).click();
  await expect(page.getByTestId('local-only-warning')).toContainText('仅更新当前扫描设备');
});
```

- [ ] **Step 3: 写重置和异常核销浏览器测试**

覆盖重置后二次确认、跨店核销、重复核销、过期核销、损坏图片、摄像头权限拒绝后仍可见图片上传和备用编号入口。

- [ ] **Step 4: 写五个目标视口的溢出与截图检查**

```js
// tests/e2e/responsive.spec.js
import { test, expect } from '@playwright/test';

const cases = [
  { path: '/#/c/store/TL1001', width: 375, height: 812, name: 'customer-375' },
  { path: '/#/c/store/TL1001/product/3', width: 390, height: 844, name: 'product-390' },
  { path: '/#/c/store/TL1001/bookings', width: 430, height: 932, name: 'bookings-430' },
  { path: '/#/store/dashboard', width: 1280, height: 800, name: 'store-1280' },
  { path: '/#/brand/dashboard', width: 1440, height: 900, name: 'brand-1440' },
];

for (const item of cases) {
  test(`${item.name} has no horizontal overflow`, async ({ page }) => {
    await page.setViewportSize({ width: item.width, height: item.height });
    await page.goto(item.path);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(overflow).toBe(false);
    await expect(page).toHaveScreenshot(`${item.name}.png`, { fullPage: true, animations: 'disabled' });
  });
}
```

- [ ] **Step 5: 运行构建和浏览器验收**

Run: `npm run build`

Expected: `dist/` 生成成功，产物中不存在 `127.0.0.1:8011`、`/api/` 或远程 Mock URL。

Run: `npm run test:e2e`

Expected: 主链路、异常核销、重置和五个视口全部 PASS；截图无空白主区、重叠或布局跳动。

- [ ] **Step 6: 提交浏览器验收**

```powershell
git add -- demo/franchise-platform-h5/scripts/generate-qr-fixture.mjs demo/franchise-platform-h5/tests/e2e demo/franchise-platform-h5/tests/fixtures
git commit -m "test: cover franchise demo journey"
```

### Task 13: 完成部署说明、最终验证和 OpenSpec 收口

**Files:**
- Create: `demo/franchise-platform-h5/README.md`
- Create: `docs/demo/franchise-platform-h5-deployment.md`
- Modify: `openspec/changes/build-franchise-platform-h5-demo/tasks.md`

- [ ] **Step 1: 写本地运行和演示脚本说明**

`README.md` 必须列出 Node 要求、`npm install`、`npm run dev`、`npm run test`、`npm run build`、`npm run preview`，以及固定主演示顺序：扫码进店 → 下单 → 模拟支付 → 预约 → 生成核销码 → 门店扫码 → 门店业绩 → 品牌分账 → 重置。

- [ ] **Step 2: 写 HTTPS Nginx 静态部署配置**

```nginx
location /franchise-demo/ {
    root /srv/tianli;
    index index.html;
    try_files $uri $uri/ /franchise-demo/index.html;
    add_header Cache-Control "public, max-age=300";
}

location ~* ^/franchise-demo/.*\.(js|css|png|jpg|svg|woff2)$ {
    root /srv/tianli;
    expires 7d;
    add_header Cache-Control "public, immutable";
}
```

文档说明摄像头要求 HTTPS，Demo 不需要 PHP/MySQL/Redis，部署目录不能覆盖当前 `8011` CRMEB 服务。入口 URL 示例为 `https://demo.example.com/franchise-demo/#/c/store/TL1001`。

- [ ] **Step 3: 运行最小到完整验证**

Run: `npm run test`

Expected: 所有 Vitest 测试 PASS。

Run: `npm run build`

Expected: Vite production build PASS，静态资源路径为相对路径。

Run: `npm run test:e2e`

Expected: 所有 Playwright 项目 PASS，控制台无未处理异常。

Run: `rg -n "127\.0\.0\.1:8011|/api/|axios|fetch\(" dist`

Expected: 无匹配。

Run: `openspec validate build-franchise-platform-h5-demo`

Expected: `Change 'build-franchise-platform-h5-demo' is valid`。

- [ ] **Step 4: 按实际验证结果勾选 OpenSpec 任务**

只有对应代码和检查完成后才把 `openspec/changes/build-franchise-platform-h5-demo/tasks.md` 中的项目改为 `[x]`。如果视觉、二维码图片识别或 HTTPS 摄像头未验证，保持未完成并在交付说明中明确列出。

- [ ] **Step 5: 最终提交**

```powershell
git add -- demo/franchise-platform-h5/README.md docs/demo/franchise-platform-h5-deployment.md openspec/changes/build-franchise-platform-h5-demo/tasks.md
git commit -m "docs: add franchise demo deployment guide"
```

- [ ] **Step 6: 启动最终本地预览并交付地址**

Run: `npm run preview -- --host 0.0.0.0 --port 4174`

Expected: 本机可访问 `http://127.0.0.1:4174/#/c/store/TL1001`；移动端同网段访问时使用电脑局域网 IP，真实摄像头验证改用 HTTPS 部署地址。

---

## 最终验收清单

- [ ] 三角色页面均使用同一份本地状态。
- [ ] 门店二维码能进入指定门店，历史业务归属不随切店改变。
- [ ] 商品下单、模拟支付、门店订单、品牌分账形成连续链路。
- [ ] 预约占用容量，取消/过期只释放一次且不产生订单。
- [ ] 二维码 PNG 可被独立解码器回读。
- [ ] 摄像头、图片识别和备用编号三条核销入口均存在。
- [ ] 正常、跨店、重复、过期四类核销结果正确。
- [ ] 门店端只见本店明细，品牌端可跨店筛选。
- [ ] 门店收入与品牌抽成之和等于实付金额，分销成本由品牌抽成承担。
- [ ] 五个目标视口无横向溢出、遮挡、空白主区或布局跳动。
- [ ] 静态构建不请求 CRMEB API、远程 Mock 或当前 `8011` 服务。
- [ ] 重置后回到相同种子业务状态和默认消费者门店首页。
