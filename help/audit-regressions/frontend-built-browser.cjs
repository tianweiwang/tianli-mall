'use strict';

// Exercise the compiled H5 route itself. No product source is read or injected.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const baseUrl = process.env.FRONTEND_BASE_URL || 'http://127.0.0.1:8011';
const origin = new URL(baseUrl).origin;
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(baseUrl).hostname), 'Only a local preview host is permitted');
assert.ok(process.env.H5_BUILD_DIR, 'Set H5_BUILD_DIR to the freshly compiled H5 output directory');
const buildDir = fs.realpathSync(process.env.H5_BUILD_DIR);
const indexPath = path.join(buildDir, 'index.html');
assert.ok(fs.statSync(indexPath).isFile(), 'The H5 build must include index.html');
const useServedAssets = process.env.FRONTEND_USE_SERVED_ASSETS === '1';
const sha256 = (content) => crypto.createHash('sha256').update(content).digest('hex');

const safeReadPaths = new Set([
  '/api/basic_config', '/api/version', '/api/lang_version', '/api/get_lang_json',
  '/api/copyright', '/api/theme_info/theme', '/api/v2/bind_status', '/api/wechat/get_logo',
  '/api/get_workerman_url',
  '/api/theme_info/home', '/api/share', '/api/products', '/api/theme/navigation',
  '/api/get_open_adv', '/api/v2/diy/sign', '/api/pink', '/api/combination/list',
  '/api/seckill/index', '/api/bargain/list',
]);
const mobile = {
  viewport: { width: 390, height: 844 }, isMobile: true,
  userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
};

async function installRoutes(context, observed, status) {
  const expectedResponses = new Map();
  const assetChecks = [];
  if (useServedAssets) {
    context.on('response', (response) => {
      const expected = expectedResponses.get(response.request());
      if (!expected) return;
      assetChecks.push((async () => {
        assert.equal(response.status(), 200, 'Served asset HTTP status: ' + expected.path);
        const actualSha256 = sha256(await response.body());
        observed.servedAssets.push({ path: expected.path, expectedSha256: expected.sha256, actualSha256 });
        assert.equal(actualSha256, expected.sha256, 'Served asset differs from the supplied build: ' + expected.path);
      })().catch((error) => observed.assetVerificationErrors.push(error.message)));
    });
  }
  await context.routeWebSocket('**/*', (socket) => socket.close());
  await context.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) {
      observed.blockedExternal.push(url.origin + url.pathname);
      return route.abort();
    }
    const fulfill = (data, responseStatus = 200) => route.fulfill({
      contentType: 'application/json', body: JSON.stringify({ status: responseStatus, msg: responseStatus === 402
        ? '站点升级中，请稍候访问' : responseStatus === 401 ? '登录失效，请重新登录' : 'ok', data }),
    });
    if (status && url.pathname === '/api/order/confirm' && request.method() === 'POST') {
      const body = request.postDataJSON();
      assert.equal(body.cartId, 'audit-built-browser-fixture');
      observed.mocked.push({ method: request.method(), path: url.pathname, status });
      // Keep the response in flight long enough to observe the actual loading UI.
      await new Promise((resolve) => setTimeout(resolve, 350));
      return fulfill({}, status);
    }
    if (status && url.pathname === '/api/order/check_shipping' && request.method() === 'POST') {
      observed.mocked.push({ method: request.method(), path: url.pathname, status: 200 });
      return fulfill({ type: 0 });
    }
    if (status && ['/api/address/default', '/api/address/list'].includes(url.pathname) && request.method() === 'GET') {
      observed.mocked.push({ method: request.method(), path: url.pathname, status: 200 });
      return fulfill([]);
    }
    if (status && url.pathname === '/api/user' && request.method() === 'GET') {
      observed.mocked.push({ method: request.method(), path: url.pathname, status: 200 });
      return fulfill({ uid: 1, nickname: '本地编译验证', now_money: '0.00', integral: 0, switchUserInfo: [] });
    }
    if (status && url.pathname === '/api/ajcaptcha' && request.method() === 'GET') {
      observed.mocked.push({ method: request.method(), path: url.pathname, status: 200 });
      return fulfill({ originalImageBase64: '', jigsawImageBase64: '', wordList: [], token: 'fixture', secretKey: '' });
    }
    if (url.pathname === '/api/get_script' && request.method() === 'GET') {
      return route.fulfill({ contentType: 'text/javascript', body: '' });
    }
    if (request.method() === 'GET' && (safeReadPaths.has(url.pathname) || /^\/api\/seckill\/list\/\d+$/.test(url.pathname))) {
      observed.upstreamReads.push(url.pathname);
      const headers = { ...request.headers() };
      delete headers['authori-zation'];
      delete headers.authorization;
      return route.continue({ headers });
    }
    if (url.pathname.startsWith('/api/') || !['GET', 'HEAD'].includes(request.method())) {
      observed.unexpected.push(request.method() + ' ' + url.pathname);
      return fulfill({}, 400);
    }
    if (/^\/(uploads|statics)\//.test(url.pathname) && /\.(png|jpe?g|gif|webp|svg|woff2?|ttf)$/i.test(url.pathname)) {
      return route.continue();
    }
    let file = path.resolve(buildDir, '.' + decodeURIComponent(url.pathname));
    if (!(file === buildDir || file.startsWith(buildDir + path.sep))) return route.abort();
    if (request.resourceType() === 'document') file = indexPath;
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
      observed.unexpected.push('Missing built asset ' + url.pathname);
      return route.fulfill({ status: 404, body: 'Missing compiled asset' });
    }
    const assetPath = path.relative(buildDir, file);
    observed.builtAssets.add(assetPath);
    if (useServedAssets) {
      expectedResponses.set(request, { path: assetPath, sha256: sha256(fs.readFileSync(file)) });
      // Use the HTTP server's response unchanged; response events verify its bytes.
      return route.continue();
    }
    return route.fulfill({ path: file });
  });
  return async () => {
    if (!useServedAssets) return;
    await Promise.all(assetChecks);
    assert.deepEqual(observed.assetVerificationErrors, [], 'Served assets must exactly match the specified build');
    assert.equal(observed.servedAssets.length, expectedResponses.size, 'Every served asset request must have a verified response');
    assert.ok(observed.servedAssets.some((asset) => asset.path === 'index.html'), 'The real HTTP index must be verified');
    assert.ok(observed.servedAssets.some((asset) => asset.path.endsWith('.js')), 'Real HTTP scripts must be verified');
  };
}

function createEvidence(details) {
  return { ...details, builtAssets: new Set(), servedAssets: [], assetVerificationErrors: [],
    mocked: [], upstreamReads: [], blockedExternal: [], unexpected: [], scriptErrors: [] };
}

async function checkResponse(browser, status) {
  const context = await browser.newContext(mobile);
  const page = await context.newPage();
  const observed = createEvidence({ status });
  page.on('pageerror', (error) => observed.scriptErrors.push(error.message));
  // An isolated synthetic client session allows the real route guard to run.
  // No real credentials are used; protected requests never reach the backend.
  await page.addInitScript(() => {
    localStorage.setItem('LOGIN_STATUS_TOKEN', 'audit-built-browser-fixture');
    localStorage.setItem('UID', JSON.stringify({ type: 'number', data: 1 }));
    localStorage.setItem('UNI-APP-CRMEB:TAG', JSON.stringify({ type: 'object', data: [
      { key: 'LOGIN_STATUS_TOKEN', expire: 0 }, { key: 'UID', expire: 0 },
    ] }));
  });
  const verifyServedAssets = await installRoutes(context, observed, status);
  try {
    const confirmation = page.waitForRequest((request) => new URL(request.url()).pathname === '/api/order/confirm');
    await page.goto(origin + '/pages/goods/order_confirm/index?cartId=audit-built-browser-fixture&new=1', { waitUntil: 'domcontentloaded' });
    await confirmation;
    await page.getByText('正在加载中', { exact: true }).waitFor({ state: 'visible' });
    await page.getByText('正在加载中', { exact: true }).waitFor({ state: 'hidden' });
    if (status === 402) {
      await page.getByText('我知道了', { exact: true }).waitFor({ state: 'visible' });
      assert.ok(await page.getByText('站点升级中，请稍候访问', { exact: true }).count());
      assert.match(page.url(), /\/pages\/goods\/order_confirm\/index/);
      if (process.env.FRONTEND_SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.FRONTEND_SCREENSHOT_DIR, { recursive: true });
        await page.screenshot({ path: path.join(process.env.FRONTEND_SCREENSHOT_DIR, 'built-h5-maintenance-402.png') });
      }
      await page.getByText('我知道了', { exact: true }).click();
      await page.getByText('我知道了', { exact: true }).waitFor({ state: 'hidden' });
    } else {
      await page.waitForURL(/\/pages\/users\/login\/index/);
      assert.equal(await page.evaluate(() => uni.getStorageSync('LOGIN_STATUS_TOKEN')), '');
      assert.equal(await page.getByText('我知道了', { exact: true }).count(), 0);
    }
    await page.waitForLoadState('networkidle');
    if (status === 401 && process.env.FRONTEND_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.FRONTEND_SCREENSHOT_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.FRONTEND_SCREENSHOT_DIR, 'built-h5-login-401.png') });
    }
    await verifyServedAssets();
    assert.deepEqual(observed.unexpected, [], 'Unrecognized API calls or missing compiled assets');
    assert.deepEqual(observed.scriptErrors, [], 'Compiled runtime script errors');
    assert.ok(observed.builtAssets.size > 1, 'The runtime must load scripts from the supplied compiled build');
    assert.equal(observed.mocked.filter((request) => request.path === '/api/order/confirm').length, 1);
    return { ...observed, builtAssets: [...observed.builtAssets], finalRoute: new URL(page.url()).pathname };
  } catch (error) {
    error.stack += '\nEvidence: ' + JSON.stringify({ ...observed, builtAssets: [...observed.builtAssets], finalRoute: page.url() });
    throw error;
  } finally {
    await context.close();
  }
}

async function checkHomepage(browser, mode) {
  const context = await browser.newContext(mode === 'mobile' ? mobile : { viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  // PHP serves an existing desktop iframe wrapper at '/'; use its H5 src here.
  // '/index.html' has the same bytes but is not a uni-app history page route.
  const entryPath = useServedAssets && mode === 'desktop' ? '/pages/index/index' : '/';
  const observed = createEvidence({ mode, entryPath });
  page.on('pageerror', (error) => observed.scriptErrors.push(error.message));
  const verifyServedAssets = await installRoutes(context, observed);
  try {
    await page.goto(origin + entryPath, { waitUntil: 'networkidle' });
    await page.waitForURL(/\/pages\/index\/index/);
    await page.getByText('请输入搜索词', { exact: true }).waitFor({ state: 'visible' });
    await page.getByText('商品分类', { exact: true }).waitFor({ state: 'visible' });
    assert.equal(await page.evaluate(() => typeof uni), 'object');
    await verifyServedAssets();
    assert.deepEqual(observed.unexpected, [], 'Homepage unrecognized API calls or missing compiled assets');
    assert.deepEqual(observed.scriptErrors, [], 'Homepage compiled runtime script errors');
    assert.ok(observed.builtAssets.size > 1, 'The homepage must load from the supplied compiled build');
    if (process.env.FRONTEND_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.FRONTEND_SCREENSHOT_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.FRONTEND_SCREENSHOT_DIR, 'built-h5-home-' + mode + '.png') });
    }
    return { ...observed, builtAssets: [...observed.builtAssets], finalRoute: new URL(page.url()).pathname };
  } catch (error) {
    error.stack += '\nEvidence: ' + JSON.stringify({ ...observed, builtAssets: [...observed.builtAssets], finalRoute: page.url() });
    throw error;
  } finally {
    await context.close();
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_EXECUTABLE || undefined });
  try {
    const results = [];
    for (const status of [402, 401]) results.push(await checkResponse(browser, status));
    if (process.env.FRONTEND_CHECK_HOME === '1') {
      for (const mode of ['mobile', 'desktop']) results.push(await checkHomepage(browser, mode));
    }
    console.log(JSON.stringify({ result: 'PASS', buildDir, assetSource: useServedAssets ? 'local-http' : 'build-directory',
      indexSha256: sha256(fs.readFileSync(indexPath)), results }, null, 2));
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
