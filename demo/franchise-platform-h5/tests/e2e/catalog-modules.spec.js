import { expect, test } from '@playwright/test';

test.describe('dual catalog demo', () => {
  test.skip(({ isMobile }) => isMobile, '目录冒烟链路在桌面视口执行');

  test('browses services, books from detail, and switches admin catalog tabs', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001/services');
    await expect(page).toHaveURL(/\/category\?tab=services$/);
    await expect(page.getByRole('heading', { name: '全部项目' })).toBeVisible();
    await expect(page.getByTestId('service-SV1004')).toBeVisible();

    await page.goto('/#/c/store/TL1001/services/SV1004');
    await expect(page.getByRole('heading', { name: '古法刮痧' })).toBeVisible();
    await expect(page.locator('.service-detail').getByText('¥98.00')).toBeVisible();
    await expect(page.getByText('当前可在线预约')).toBeVisible();
    await page.getByRole('link', { name: '立即预约' }).click();
    await expect(page).toHaveURL(/\/booking\?serviceId=SV1004$/);
    await expect(page.getByRole('heading', { name: '选择服务' })).toBeVisible();

    await page.goto('/#/c/store/TL1001/category');
    await expect(page.getByRole('tab', { name: /健康好物/ })).toBeVisible();
    await expect(page.getByText('IGOOD修复液').first()).toBeVisible();
    await page.getByRole('tab', { name: /门店项目/ }).click();
    await expect(page.getByRole('heading', { name: '全部项目' })).toBeVisible();
    await expect(page.getByTestId('service-SV1004')).toBeVisible();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/brand/products');
    await expect(page.getByRole('tab', { name: /服务项目/ })).toBeVisible();
    await expect(page.getByText('集团服务项目')).toBeVisible();
    await page.getByRole('tab', { name: /实物商品/ }).click();
    await expect(page.getByText('集团统一商品')).toBeVisible();
    await expect(page.getByText('IGOOD修复液').first()).toBeVisible();

    await page.goto('/#/store/products');
    await expect(page.getByText('本店服务项目')).toBeVisible();
    await page.getByRole('tab', { name: /实物商品/ }).click();
    await expect(page.getByText('本店商品')).toBeVisible();

    await page.screenshot({ path: testInfo.outputPath('dual-catalog-brand.png') });
    expect(consoleErrors).toEqual([]);
  });
});
