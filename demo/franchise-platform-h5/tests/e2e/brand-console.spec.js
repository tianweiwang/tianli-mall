import { expect, test } from '@playwright/test';

const brandRoutes = [
  ['/brand/dashboard', '平台总览'],
  ['/brand/stores', '加盟门店'],
  ['/brand/stores/TL1001', '门店详情'],
  ['/brand/onboarding', '进件审核'],
  ['/brand/products', '服务与商品'],
  ['/brand/orders', '全平台订单'],
  ['/brand/bookings', '全平台预约'],
  ['/brand/assessments', '检测运营'],
  ['/brand/devices', '设备管理'],
  ['/brand/sharing', '分账流水'],
  ['/brand/sharing/PS202607303001', '分账详情'],
  ['/brand/analytics', '经营分析'],
];

test.describe('brand operations console', () => {
  test.skip(({ isMobile }) => isMobile, '品牌后台按桌面端视口验收');

  test('renders every brand route without page overflow or console errors', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });

    for (const viewport of [{ width: 1280, height: 800 }, { width: 1440, height: 900 }]) {
      await page.setViewportSize(viewport);
      for (const [route, title] of brandRoutes) {
        await page.goto(`/#${route}`);
        await expect(page.locator('.admin-topbar')).toContainText(title);
        await expect(page.locator('.admin-page')).toBeVisible();
        await expect.poll(() => page.evaluate(
          () => document.documentElement.scrollWidth <= window.innerWidth,
        )).toBe(true);
      }
    }

    expect(consoleErrors).toEqual([]);
  });

  test('advances a pending sharing record and persists the new state', async ({ page }) => {
    await page.goto('/#/brand/sharing/PS202607303003');
    await expect(page.getByText('待分账', { exact: true }).first()).toBeVisible();

    await page.getByRole('button', { name: '开始分账' }).click();
    await expect(page.getByText('模拟分账确认')).toBeVisible();
    await page.getByRole('button', { name: '确认推进' }).click();

    await expect(page.getByText('处理中', { exact: true }).first()).toBeVisible();
    await expect.poll(() => page.evaluate(() => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return state.profitSharingRecords.find(
        (item) => item.id === 'PS202607303003',
      )?.status;
    })).toBe('processing');
  });

  test('drills from a heat slot into booking details and clears the selection', async ({ page }) => {
    await page.goto('/#/brand/bookings');
    await page.evaluate(() => localStorage.removeItem('tianli.franchise-demo.v1'));
    await page.reload();

    await expect(page.getByText('门店时段热力')).toBeVisible();
    await expect(page.getByText('9 / 14')).toBeVisible();
    const cell = page.getByTestId('booking-heat-cell-TL1002-11:00-11:45');
    await expect(cell).toContainText('2 / 2');
    await cell.click();

    await expect(page.getByText('天俪滨江店 · 11:00-11:45 · 2/2')).toBeVisible();
    await expect(page.locator('.admin-table-panel .el-table__body-wrapper').first().locator('tbody tr')).toHaveCount(2);
    await page.getByRole('button', { name: '清除时段筛选' }).click();
    await expect(page.getByText('天俪滨江店 · 11:00-11:45 · 2/2')).toHaveCount(0);

    await page.getByRole('button', { name: '查看' }).first().click();
    await expect(page.getByText('预约详情')).toBeVisible();
  });
});
