import { expect, test } from '@playwright/test';

test.describe('live module demo', () => {
  test.skip(({ isMobile }) => isMobile, '直播冒烟链路在桌面视口执行');

  test('renders consumer live room, store management, and brand operations', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001/live');
    await expect(page.getByText('天俪锦江店 直播')).toBeVisible();
    await expect(page.getByTestId('live-room-LR1001')).toBeVisible();
    await expect(page.getByText('天俪锦江店·健康好物专场').first()).toBeVisible();

    await page.goto('/#/c/store/TL1001/live/LR1001');
    await expect(page.getByText('模拟直播画面')).toBeVisible();
    await expect(page.getByTestId('live-like')).toContainText('1520');
    await page.getByTestId('live-like').click();
    await expect(page.getByTestId('live-like')).toContainText('1521');
    await page.getByTestId('live-enter').click();
    await expect(page.getByText('396 观看').first()).toBeVisible();
    await expect(page.getByText('直播商品')).toBeVisible();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/store/live');
    await expect(page.getByText('创建演示场次')).toBeVisible();
    await expect(page.getByText('本店直播场次')).toBeVisible();

    await page.goto('/#/brand/live');
    await expect(page.getByText('共 4 场')).toBeVisible();
    await expect(page.getByText('LR1001', { exact: true }).first()).toBeVisible();

    await page.screenshot({ path: testInfo.outputPath('live-module-brand.png') });
    expect(consoleErrors).toEqual([]);
  });
});
