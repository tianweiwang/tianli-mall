'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const validateApiOrigin = require('../../template/uni-app/config/api-origin');

function source(relative) {
  return fs.readFileSync(path.join(root, relative), 'utf8');
}

function requestModuleSource() {
  return source('template/uni-app/utils/request.js')
    .replace(/import[\s\S]*?from\s*['"][^'"]+['"];?/g, '')
    .replace('export default request;', 'globalThis.request = request;');
}

function orderConfirmConsumerSource() {
  const match = source('template/uni-app/pages/goods/order_confirm/index.vue')
    .match(/getConfirm:\s*(function\(\)\s*\{[\s\S]*?\n\t{3}\}),/);
  assert.ok(match, '订单确认真实消费者方法应可提取');
  return match[1];
}

async function run() {
  const checkPath = path.join(__dirname, 'frontend-source-check.cjs');
  assert.equal(spawnSync(process.execPath, [checkPath]).status, 0);
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'tianli-frontend-source-'));
  try {
    fs.mkdirSync(path.join(fixture, 'help/audit-regressions'), { recursive: true });
    fs.copyFileSync(checkPath, path.join(fixture, 'help/audit-regressions/frontend-source-check.cjs'));
    const result = spawnSync(process.execPath, [path.join(fixture, 'help/audit-regressions/frontend-source-check.cjs')], { encoding: 'utf8' });
    assert.equal(result.status, 1, '备份缺失源码时必须中断构建');
    assert.match(result.stderr, /Export2Excel\.js/);
  } finally {
    fs.rmSync(fixture, { recursive: true, force: true });
  }

  assert.equal(validateApiOrigin('http://127.0.0.1:8011', 'development'), 'http://127.0.0.1:8011');
  assert.equal(validateApiOrigin('http://192.168.1.8:8011/', 'development'), 'http://192.168.1.8:8011');
  assert.equal(validateApiOrigin('https://mall.example.test', 'production'), 'https://mall.example.test');
  for (const invalid of ['', 'https://demo.crmeb.com', 'https://user:password@mall.example.test',
    'https://mall.example.test/api', 'https://mall.example.test?x=1', 'javascript:alert(1)',
    'http://mall.example.test', 'https://mall.example.test:99999']) {
    assert.throws(() => validateApiOrigin(invalid, 'development'));
  }
  assert.throws(() => validateApiOrigin('http://127.0.0.1:8011', 'production'));
  const configPath = path.join(root, 'template/uni-app/vue.config.js');
  function loadConfig(platform, origin, mode = 'production') {
    return spawnSync(process.execPath, ['-e', 'require(process.argv[1])', configPath], {
      env: { ...process.env, UNI_PLATFORM: platform, VUE_APP_API_URL: origin, NODE_ENV: mode }, encoding: 'utf8',
    });
  }
  assert.equal(loadConfig('h5', '').status, 0, 'H5 同域构建不需要移动端地址');
  assert.equal(loadConfig('mp-weixin', '').status, 1, '小程序发布缺配置必须失败');
  assert.equal(loadConfig('app-plus', 'http://127.0.0.1:8011').status, 1, 'App 生产包不可连接开发 HTTP');
  assert.equal(loadConfig('mp-weixin', 'http://127.0.0.1:8011', 'development').status, 0);
  assert.equal(loadConfig('app-plus', 'https://mall.example.test').status, 0);
  assert.match(source('template/uni-app/.env.development'), /^VUE_APP_API_URL=http:\/\/127\.0\.0\.1:8011$/m);

  // 使用已装编译器的 dotenv；普通纯源码回归不要求安装 HBuilderX。
  if (process.env.UNI_CLI_CONTEXT) {
    const loadApiEnv = require('../../template/uni-app/config/load-api-env');
    const projectDir = fs.mkdtempSync(path.join(os.tmpdir(), 'tianli-api-env-'));
    try {
      fs.writeFileSync(path.join(projectDir, '.env.development'), 'VUE_APP_API_URL=http://127.0.0.1:8011\nUNRELATED=do-not-load\n');
      const dev = { NODE_ENV: 'development', UNI_CLI_CONTEXT: process.env.UNI_CLI_CONTEXT };
      loadApiEnv(projectDir, dev);
      assert.equal(dev.VUE_APP_API_URL, 'http://127.0.0.1:8011', '配置从项目目录加载，不依赖编译器 cwd');
      assert.equal(dev.UNRELATED, undefined, '仅导入接口地址');
      fs.writeFileSync(path.join(projectDir, '.env.development.local'), 'VUE_APP_API_URL="http://192.168.1.8:8011"\n');
      const local = { NODE_ENV: 'development', UNI_CLI_CONTEXT: process.env.UNI_CLI_CONTEXT };
      loadApiEnv(projectDir, local);
      assert.equal(local.VUE_APP_API_URL, 'http://192.168.1.8:8011');
      for (const value of ['', 'https://explicit.example.test']) {
        const explicit = { ...local, VUE_APP_API_URL: value };
        loadApiEnv(projectDir, explicit);
        assert.equal(explicit.VUE_APP_API_URL, value, '显式变量含空值优先，不静默回退');
      }
      const prod = { NODE_ENV: 'production', UNI_CLI_CONTEXT: process.env.UNI_CLI_CONTEXT };
      loadApiEnv(projectDir, prod);
      assert.equal(prod.VUE_APP_API_URL, undefined, '生产不读取开发配置');
      const inherited = { ...process.env, UNI_PLATFORM: 'mp-weixin', NODE_ENV: 'development' };
      delete inherited.VUE_APP_API_URL;
      const actual = spawnSync(process.execPath, ['-e', 'require(process.argv[1]); console.log(process.env.VUE_APP_API_URL)', configPath], {
        cwd: process.env.UNI_CLI_CONTEXT, env: inherited, encoding: 'utf8',
      });
      assert.equal(actual.status, 0, actual.stderr);
      assert.equal(actual.stdout.trim(), 'http://127.0.0.1:8011');
      console.log('PASS: HBuilderX 编译器目录下的项目环境文件加载、local/外部变量优先与生产隔离。');
    } finally {
      fs.rmSync(projectDir, { recursive: true, force: true });
    }
  }

  let response = { status: 200, data: { ok: true } };
  const state = { modals: [], loginCalls: 0, loading: false, tips: [], requests: [] };
  const uni = {
    getStorageSync: () => '',
    request(options) { state.requests.push(options); options.success({ data: response }); },
    showModal(options) { state.modals.push(options); },
    showLoading() { state.loading = true; },
    hideLoading() { state.loading = false; },
  };
  const context = vm.createContext({ uni, HTTP_REQUEST_URL: 'http://127.0.0.1:8011', HEADER: {},
    TOKENNAME: 'Authori-zation', TIMEOUT: 1000, store: { state: { app: { token: 'fixture' } } },
    i18n: { t: (text) => text }, toLogin: () => { state.loginCalls++; }, checkLogin: () => true });
  vm.runInContext(requestModuleSource(), context);
  // 原来的 402 Promise 永不结束：先给予微任务执行机会，再检查实际消费者状态。
  assert.equal((await context.request.get('fixture')).status, 200);
  response = { status: 401, msg: '登录失效' };
  await assert.rejects(context.request.get('fixture'), (error) => error.status === 401);
  assert.equal(state.loginCalls, 1);
  response = { status: 400, msg: '普通业务错误' };
  await assert.rejects(context.request.get('fixture'), (error) => error === '普通业务错误');
  response = { status: 402, msg: '站点升级中，请稍候访问' };
  let rejected = false;
  let finalized = false;
  context.request.get('fixture').catch((error) => {
    rejected = true;
    assert.equal(error, response.msg);
  }).finally(() => { finalized = true; });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(rejected, true, '402 必须 reject，不能永久 pending');
  assert.equal(finalized, true);
  assert.equal(state.modals[0].content, response.msg);
  context.orderConfirm = (data) => context.request.post('order/confirm', data);
  const getConfirm = vm.runInContext(`(${orderConfirmConsumerSource()})`, context);
  getConfirm.call({ $t: (text) => text, cartId: 'fixture', news: 1, addressId: 0, shippingType: 0,
    $util: { Tips: (options) => state.tips.push(options) } });
  assert.equal(state.loading, true);
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(state.loading, false, '真实订单确认消费者必须清理 loading');
  assert.equal(state.tips[0].title, response.msg);
  assert.equal(state.requests.at(-1).url, 'http://127.0.0.1:8011/api/order/confirm');
  console.log('PASS: vendor 完整性、移动端配置/构建拒绝、200/401/400/402 和真实订单确认 loading 回归。');
}

async function checkFinanceTransactions() {
  const plain = value => JSON.parse(JSON.stringify(value));
  const stripImports = value => value.replace(/import[\s\S]*?from\s*['"][^'"]+['"];?/g, '').replace(/^\s*import\s+['"][^'"]+['"];?\s*$/gm, '');
  const domainContext = vm.createContext({});
  vm.runInContext(source('template/admin/src/libs/marketplace-finance-transactions.js').replace(/\bexport\s+/g, '') + '\nglobalThis.domain = { transactionQuery, transactionsView, moneyText, payerText, attemptStateText, estimateText };', domainContext);
  const domain = domainContext.domain;
  const empty = { schema: 1, scope: 'testing_persisted_payment_v1', real_funds_enabled: false,
    available_revisions: [{ revision: 2, created_at: 1700000000 }, { revision: 1, created_at: 1699999999 }],
    selected_revision: null, rule: null, list: [], next_before_id: null,
    channel_fee_amount: null, merchant_receivable: null, settled_amount: null };
  assert.deepEqual(plain(domain.transactionQuery()), { limit: 20 });
  assert.deepEqual(plain(domain.transactionQuery({ before_id: 12, revision: 1, limit: 50 })), { limit: 50, before_id: 12, revision: 1 });
  assert.equal(domain.transactionsView(empty).selected_revision, null, '有保存版本也不自动选择最新');
  assert.equal(domain.moneyText(null), '尚未确认');
  const row = { root_order_id: 12, order_no: 'TEST-ROOT-12', mer_id: 3, currency: 'CNY',
    order_amount: '12.50', goods_paid: '10.00', shipping_paid: '2.50', fulfillment_hold: false,
    attempts: [{ attempt_id: '9007199254740993', admitted_generation: '1', created_at: 1700000000, state: 'applied', applied_at: 1700000001 }],
    estimate_status: 'ESTIMATED', fee_estimate: { basis_amount: '10.00', platform_fee_amount: '0.25', payer: 'merchant' } };
  const view = { ...empty, selected_revision: 1, rule: { platform_fee_enabled: true, platform_fee_basis: 'goods_paid', platform_fee_rate: '2.50', platform_fee_payer: 'merchant' }, list: [row], next_before_id: 12 };
  const parsed = domain.transactionsView(view, { revision: 1 });
  assert.equal(parsed.list[0].attempts[0].attempt_id, '9007199254740993', '尝试编号保持字符串');
  assert.equal(parsed.list[0].fee_estimate.platform_fee_amount, '0.25');
  assert.equal(parsed.next_before_id, 12);
  const zero = { ...view, rule: { ...view.rule, platform_fee_enabled: false }, list: [{ ...row, fee_estimate: { basis_amount: null, platform_fee_amount: '0.00', payer: null } }] };
  assert.equal(domain.transactionsView(zero, { revision: 1 }).list[0].fee_estimate.platform_fee_amount, '0.00');
  const maximum = { ...empty, list: [{ ...row, order_amount: '9999999999.99', goods_paid: '9999999999.99', shipping_paid: '0.00', estimate_status: 'NO_REVISION', fee_estimate: null }] };
  assert.equal(domain.transactionsView(maximum).list[0].order_amount, '9999999999.99');
  assert.equal(domain.estimateText('NO_REVISION'), '未选择保存版本');
  assert.equal(domain.attemptStateText('succeeded_observed'), '已观察到成功，尚未应用');

  let active = true; let release; const requests = [];
  const store = { state: { userInfo: { uniqueAuth: ['marketplace-finance-transactions-read'] } } };
  const client = config => { requests.push(config); return release ? new Promise(resolve => { release.resolve = resolve; }) : Promise.resolve({ status: 200, data: { status: 200, msg: 'ok', data: empty } }); };
  const apiContext = vm.createContext({ axios: { create: () => client, CancelToken: { source: () => ({ cancel() {}, token: 'synthetic-cancel' }) } },
    setting: { apiBaseURL: '/adminapi/' }, captureContext: () => ({}), currentContext: context => active && context.life.open,
    closeContext: context => { if (context) context.life.open = false; }, currentPermissions: async () => ({ unique: store.state.userInfo.uniqueAuth, menus: [] }),
    platformCan: (value, grant) => value.state.userInfo.uniqueAuth.includes(grant), ...domain });
  const apiSource = stripImports(source('template/admin/src/api/marketplace-finance-transactions.js')).replace(/export\s*\{[\s\S]*?\};?/g, '').replace(/\bexport\s+/g, '');
  vm.runInContext(apiSource + '\nglobalThis.api = { read, can };', apiContext);
  const context = { store, token: 'synthetic-fixture', life: { open: true, cancels: new Set() } };
  assert.equal(apiContext.api.can(store, 'read'), true, '独立交易权限无需资金设置权限');
  await apiContext.api.read(context);
  assert.equal(requests[0].method, 'GET'); assert.equal(requests[0].url, 'marketplace/finance-transactions');
  assert.deepEqual(plain(requests[0].params), { limit: 20 });
  release = {}; const oldRead = apiContext.api.read(context); active = false;
  release.resolve({ status: 200, data: { status: 200, msg: 'ok', data: empty } });
  await assert.rejects(oldRead, /身份或页面已变化/); active = true; release = null;

  const pageContext = vm.createContext({ ...domain, can: apiContext.api.can, currentContext: value => active && value.life.open,
    closeContext: value => { if (value) value.life.open = false; }, captureContext() {}, refreshPermissions: async () => {},
    read: async () => empty, subscribePlatformAuthEpoch() {} });
  const pageSource = stripImports(source('template/admin/src/pages/marketplace/financeTransactions/index.vue').match(/<script>([\s\S]*?)<\/script>/)[1]).replace('export default', 'globalThis.component =');
  vm.runInContext(pageSource, pageContext);
  const component = pageContext.component; const page = { ...component.data(), $store: store, $route: { name: 'marketplace_finance_transactions', fullPath: '/admin/marketplace/finance-transactions' }, $router: { history: { pending: null } } };
  Object.entries(component.methods).forEach(([name, method]) => { page[name] = method.bind(page); });
  page.context = context; page.contextRoute = page.$route.fullPath; page.view = parsed; page.selectedRevision = 1;
  page.clear(); assert.equal(page.view, null); assert.equal(page.selectedRevision, null); assert.equal(context.life.open, false);
  let finishPageRead;
  pageContext.read = () => new Promise(resolve => { finishPageRead = resolve; });
  page.context = { store, life: { open: true, cancels: new Set() } }; page.contextRoute = page.$route.fullPath;
  const oldPageRead = page.load(); await new Promise(resolve => setImmediate(resolve));
  page.clear(); const newerContext = { store, life: { open: true, cancels: new Set() } }; const newerView = { ...parsed, list: [] };
  page.context = newerContext; page.contextRoute = page.$route.fullPath; page.view = newerView; page.selectedRevision = 2;
  finishPageRead(parsed); await oldPageRead;
  assert.equal(page.view, newerView, '旧任务的晚到结果不得写回或清空新身份页面'); assert.equal(page.context, newerContext); assert.equal(page.selectedRevision, 2);
  page.clear(); page.context = { store, life: { open: true, cancels: new Set() } }; page.contextRoute = page.$route.fullPath; page.selectedRevision = 1;
  const currentPageRead = page.load(); await new Promise(resolve => setImmediate(resolve));
  active = false; finishPageRead(parsed); await currentPageRead; active = true;
  assert.equal(page.loading, false, '同一当前任务在身份不可用时必须结束loading');
  assert.equal(page.context, null); assert.equal(page.view, null); assert.equal(page.selectedRevision, null); assert.deepEqual(plain(page.revisions), []);
  const navigationContext = vm.createContext({ setting: { routePre: '/admin' } });
  vm.runInContext(source('template/admin/src/libs/marketplace-navigation.js').replace(/^import .*;\s*$/gm, '').replace(/\bexport\s+/g, '') + '\nglobalThis.project = withMarketplaceMenus;', navigationContext);
  const menus = navigationContext.project([], ['marketplace-finance-transactions-read']);
  assert.deepEqual(plain(menus[0].children.map(item => item.path)), ['/admin/marketplace/finance-transactions']);
  let transactionRefreshes = 0; let settingRefreshes = 0; const target = {};
  const routeContext = vm.createContext({ Vue: { prototype: { $message: { error() {} } } }, LayoutMain: {}, setting: { routePre: '/admin' }, store,
    platformCan: (value, grant) => value.state.userInfo.uniqueAuth.includes(grant), captureContext: () => ({ life: { open: true } }),
    currentContext: value => value.life.open, closeContext: value => { value.life.open = false; },
    refreshFinancePermissions: async () => { settingRefreshes++; }, refreshFinanceTransactionPermissions: async (value, current) => { assert.equal(current(), true); transactionRefreshes++; },
    fakeRouter: { history: { pending: target } } });
  const routeSource = source('template/admin/src/router/modules/marketplace.js').replace(/^import .*;\s*$/gm, '')
    .replace(/await import\('@\/router'\)/g, 'await Promise.resolve({ default: fakeRouter })').replace('export default', 'globalThis.routes =');
  vm.runInContext(routeSource, routeContext);
  const transactionRoute = routeContext.routes.children.find(item => item.name === 'marketplace_finance_transactions');
  let entered = 0; await transactionRoute.beforeEnter(target, {}, value => { assert.equal(value, undefined); entered++; });
  assert.equal(entered, 1); assert.equal(transactionRefreshes, 1); assert.equal(settingRefreshes, 0);
  console.log('PASS: 测试交易只读DTO/金额字符串/明确版本/游标/独立菜单权限/身份切换与清空（纯前端，无HTTP或业务）。');
}

module.exports = { requestModuleSource, orderConfirmConsumerSource, checkFinanceTransactions };
if (require.main === module) (process.argv.includes('--finance-transactions-only') ? checkFinanceTransactions() : run()).catch((error) => { console.error(error); process.exitCode = 1; });
