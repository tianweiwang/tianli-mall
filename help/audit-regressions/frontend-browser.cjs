'use strict';

const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { requestModuleSource, orderConfirmConsumerSource } = require('./frontend-regression.cjs');

(async () => {
  const baseUrl = process.env.FRONTEND_BASE_URL || 'http://127.0.0.1:8011';
  const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_EXECUTABLE || undefined });
  try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true,
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1' });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    // 仅访问本地目标站点，不加载 CDN/统计脚本，也不发出真实订单请求。
    await page.route('**/*', (route) => new URL(route.request().url()).origin === new URL(baseUrl).origin
      ? route.continue() : route.abort());
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    assert.equal(await page.evaluate(() => typeof uni), 'object', '必须使用实际 H5 uni runtime');
    await page.evaluate(({ requestSource, consumerSource }) => {
      const adapter = Object.create(uni);
      adapter.request = (options) => options.success({ data: { status: 402, msg: '站点升级中，请稍候访问' } });
      const request = new Function('uni', 'HTTP_REQUEST_URL', 'HEADER', 'TOKENNAME', 'TIMEOUT',
        'toLogin', 'checkLogin', 'store', 'i18n', requestSource.replace('globalThis.request = request;', 'return request;'))(
          adapter, location.origin, {}, 'Authori-zation', 1000, () => {}, () => true,
          { state: { app: { token: 'fixture' } } }, { t: (text) => text });
      const getConfirm = new Function('uni', 'orderConfirm', `return (${consumerSource});`)(
        uni, (data) => request.post('order/confirm', data));
      getConfirm.call({ $t: (text) => text, cartId: 'fixture', news: 1, addressId: 0, shippingType: 0,
        $util: { Tips: (options) => uni.showToast({ title: options.title, icon: 'none' }) } });
    }, { requestSource: requestModuleSource(), consumerSource: orderConfirmConsumerSource() });
    await page.waitForTimeout(2000);
    assert.equal(await page.getByText('正在加载中', { exact: true }).count(), 0);
    assert.equal(await page.getByText('站点升级中，请稍候访问', { exact: true }).count(), 1);
    if (process.env.FRONTEND_SCREENSHOT) await page.screenshot({ path: process.env.FRONTEND_SCREENSHOT });
    await page.getByText('我知道了', { exact: true }).click();
    await page.getByText('站点升级中，请稍候访问', { exact: true }).waitFor({ state: 'hidden' });
    assert.deepEqual(errors, []);
    console.log('PASS: 本地 H5 390×844，真实 uni 提示 + 真实订单确认方法，402 后 loading 消失，弹窗可关闭，无新增脚本错误。');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
