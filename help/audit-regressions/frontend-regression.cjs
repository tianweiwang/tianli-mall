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

module.exports = { requestModuleSource, orderConfirmConsumerSource };
if (require.main === module) run().catch((error) => { console.error(error); process.exitCode = 1; });
