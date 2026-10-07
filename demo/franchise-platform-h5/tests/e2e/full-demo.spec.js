import { expect, test } from '@playwright/test';
import { PNG } from 'pngjs';

function expectMeaningfulPixels(buffer) {
  const png = PNG.sync.read(buffer);
  const [red, green, blue] = png.data;
  let changed = 0;
  for (let index = 0; index < png.data.length; index += 16) {
    const delta = Math.abs(png.data[index] - red)
      + Math.abs(png.data[index + 1] - green)
      + Math.abs(png.data[index + 2] - blue);
    if (delta > 24) changed += 1;
  }
  expect(changed).toBeGreaterThan(1000);
}

test.describe('complete franchise demo', () => {
  test.skip(({ isMobile }) => isMobile, '完整链路由测试内显式切换全部目标视口');

  test('runs the consumer, store, and brand journey against one local state', async ({ page }, testInfo) => {
    const consoleErrors = [];
    const unexpectedRequests = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.origin !== 'http://127.0.0.1:4174' || url.pathname.startsWith('/api')) {
        unexpectedRequests.push(request.url());
      }
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001');
    await expect(page.getByRole('heading', { name: '天俪锦江店' })).toBeVisible();

    await page.getByTestId('product-3').getByRole('link').click();
    await expect(page).toHaveURL(/\/product\/3$/);
    await expect(page.locator('.product-detail').getByRole('heading', { name: /IGOOD益生菌/ })).toBeVisible();
    await page.locator('.product-detail').getByRole('button', { name: '加入购物车' }).click();
    await page.getByRole('navigation', { name: '商城底部导航' }).getByText('购物车', { exact: true }).click();
    await page.getByRole('link', { name: '去结算' }).click();
    await page.getByRole('link', { name: '去支付' }).click();
    await page.getByRole('button', { name: '确认支付' }).click();
    await expect(page.getByRole('heading', { name: '支付成功' })).toBeVisible();
    const orderId = (await page.getByTestId('order-id').textContent()).trim();

    await page.getByRole('link', { name: '返回首页' }).click();
    await page.getByRole('link', { name: '预约到店' }).click();
    await page.getByRole('button', { name: '确认预约' }).click();
    await expect(page.getByRole('heading', { name: '预约成功' })).toBeVisible();
    const bookingId = (await page.getByTestId('booking-id').textContent()).trim();
    const verifyCode = (await page.getByTestId('verify-code').textContent()).trim();

    await page.setViewportSize({ width: 1280, height: 800 });
    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '门店端' }).click();
    await expect(page.getByText('门店工作台', { exact: true }).first()).toBeVisible();
    await page.getByRole('link', { name: '扫码核销' }).click();
    await page.getByLabel('备用核销编号').fill(verifyCode);
    await page.getByRole('button', { name: '查询预约' }).click();
    await expect(page.getByText(bookingId, { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '确认核销' }).click();
    await expect(page.getByRole('heading', { name: '核销成功' })).toBeVisible();

    await page.getByRole('link', { name: '门店工作台' }).click();
    const bookingMetric = page.locator('.admin-metric-strip__item').filter({ hasText: '待到店预约' });
    await expect(bookingMetric).toContainText('4 人');
    await expect(bookingMetric).toContainText('到店率 50%');

    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '品牌方后台' }).click();
    await page.getByRole('link', { name: '全平台订单' }).click();
    await expect(page).toHaveURL(/\/brand\/orders$/);
    await page.getByPlaceholder('订单号或商品名称').fill(orderId);
    await expect(page.getByText('共 1 笔')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('full-flow-brand-order.png') });
    await expect(page.locator('.admin-table-panel .el-table').first()).toContainText(orderId);

    const sharingId = await page.evaluate((createdOrderId) => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return state.profitSharingRecords.find((item) => item.orderId === createdOrderId).id;
    }, orderId);
    await page.goto(`/#/brand/sharing/${sharingId}`);
    await page.getByRole('button', { name: '开始分账' }).click();
    await page.getByRole('button', { name: '确认推进' }).click();
    await expect(page.getByText('处理中', { exact: true }).first()).toBeVisible();

    const linkedState = await page.evaluate(({ createdOrderId, createdBookingId, createdSharingId }) => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return {
        order: state.orders.find((item) => item.id === createdOrderId),
        booking: state.bookings.find((item) => item.id === createdBookingId),
        sharing: state.profitSharingRecords.find((item) => item.id === createdSharingId),
      };
    }, { createdOrderId: orderId, createdBookingId: bookingId, createdSharingId: sharingId });
    expect(linkedState.order.storeId).toBe('TL1001');
    expect(linkedState.booking).toMatchObject({ storeId: 'TL1001', status: 'verified' });
    expect(linkedState.sharing).toMatchObject({ orderId, status: 'processing' });

    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '重置演示数据' }).click();
    await page.getByRole('button', { name: '确认重置' }).click();
    await expect(page).toHaveURL(/#\/c\/store\/TL1001$/);
    await expect.poll(() => page.evaluate(() => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return {
        orders: state.orders.length,
        bookings: state.bookings.length,
        sharing: state.profitSharingRecords.length,
        activeRole: state.activeRole,
      };
    })).toEqual({ orders: 5, bookings: 15, sharing: 7, activeRole: 'customer' });

    expect(consoleErrors).toEqual([]);
    expect(unexpectedRequests).toEqual([]);
  });

  test('renders meaningful, overflow-free first viewports at every target size', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    const targets = [
      [{ width: 375, height: 812 }, '/#/c/store/TL1001', '.customer-home'],
      [{ width: 390, height: 844 }, '/#/c/store/TL1001/booking', '.booking-create'],
      [{ width: 430, height: 932 }, '/#/c/store/TL1001/bookings/BK202607302001', '.booking-detail'],
      [{ width: 1280, height: 800 }, '/#/store/dashboard', '.store-dashboard'],
      [{ width: 1440, height: 900 }, '/#/brand/dashboard', '.brand-dashboard'],
    ];

    for (const [viewport, route, selector] of targets) {
      await page.setViewportSize(viewport);
      await page.goto(route);
      await expect(page.locator(selector)).toBeVisible();
      await expect.poll(() => page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      )).toBe(true);
      expectMeaningfulPixels(await page.screenshot());
    }

    expect(consoleErrors).toEqual([]);
  });
});
