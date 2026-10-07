import { expect, test } from '@playwright/test';

test.describe('growth modules demo', () => {
  test.skip(({ isMobile }) => isMobile, '增长模块冒烟链路在桌面视口执行');

  test('renders consumer, store, and brand growth pages', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/#/c/store/TL1001/points');
    await expect(page.getByTestId('points-balance')).toHaveText('1680');
    await expect(page.getByText('积分兑换（演示）')).toBeVisible();

    await page.goto('/#/c/store/TL1001/referral');
    await expect(page.getByTestId('referral-code')).toHaveText('TL88C6');
    await expect(page.getByRole('heading', { name: '邀请记录' })).toBeVisible();

    await page.goto('/#/c/store/TL1001/share');
    await expect(page.getByText('累计分享', { exact: true })).toBeVisible();
    await expect(page.getByText('分享记录')).toBeVisible();

    await page.goto('/#/c/store/TL1001/distribution');
    await expect(page.getByTestId('distributor-name')).toContainText('陈女士');
    await expect(page.getByTestId('distributor-withdrawable')).toHaveText('¥127.40');
    await page.getByRole('link', { name: '查看我的团队详情' }).click();
    await expect(page).toHaveURL(/#\/c\/store\/TL1001\/referral$/);
    await expect(page.getByRole('heading', { name: '邀请记录' })).toBeVisible();
    await expect(page.getByText(/张女士.*139\*\*\*\*2211/)).toBeVisible();
    await expect(page.getByText('待首单', { exact: true }).first()).toBeVisible();

    await page.goto('/#/store/withdrawals');
    await expect(page.getByText('申请提现（演示）')).toBeVisible();
    await expect(page.getByText('可提现余额', { exact: true })).toBeVisible();
    await expect(page.getByText('¥412.74').first()).toBeVisible();

    await page.goto('/#/brand/distribution');
    await expect(page.getByText('分销员档案')).toBeVisible();
    await expect(page.getByText('分享回流')).toBeVisible();

    await page.goto('/#/brand/withdrawals');
    await expect(page.getByText('提现审核').first()).toBeVisible();
    await expect(page.getByText('待审核', { exact: true }).first()).toBeVisible();

    await page.screenshot({ path: testInfo.outputPath('growth-modules-brand-withdrawals.png') });
    expect(consoleErrors).toEqual([]);
  });
});
