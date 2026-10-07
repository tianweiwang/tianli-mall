import { expect, test } from '@playwright/test';

test.describe('membership wallet and subsidy demo', () => {
  test.skip(({ isMobile }) => isMobile, '完整跨角色链路在桌面项目中切换移动和管理视口执行');

  test('runs customer purchase, store settlement and brand subsidy as one flow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001/profile');
    await page.evaluate(() => window.localStorage.clear());
    await page.reload();
    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '李四' }).click();
    await expect(page.getByRole('heading', { name: '李四' })).toBeVisible();

    await page.goto('/#/c/store/TL1001/membership');
    await page.getByRole('button', { name: '模拟支付510元开通' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认支付' }).click();
    await expect(page.getByRole('button', { name: '已开通' })).toBeDisabled();
    await page.getByRole('button', { name: '模拟储值5100元' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认支付' }).click();
    await expect(page.getByText('再次储值5100元')).toBeVisible();
    await expect(page.locator('.el-message-box__wrapper')).toBeHidden();
    await expect(page.locator('.el-message:visible')).toHaveCount(0, { timeout: 10000 });

    await page.goto('/#/c/store/TL1001/assets');
    await expect(page.getByText('¥5100.00', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('10200分', { exact: true }).first()).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('li-membership-assets-mobile.png'), fullPage: true });

    await page.goto('/#/c/store/TL1001/booking?serviceId=SV1004');
    await expect(page.getByLabel('预约人姓名')).toHaveValue('李四');
    await page.getByRole('button', { name: '确认预约' }).click();
    await expect(page.getByRole('heading', { name: '预约成功' })).toBeVisible();
    const bookingId = (await page.getByTestId('booking-id').textContent()).trim();
    const verifyCode = (await page.getByTestId('verify-code').textContent()).trim();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/store/verify');
    await page.getByLabel('备用核销编号').fill(verifyCode);
    await page.getByRole('button', { name: '查询预约' }).click();
    await expect(page.getByText(bookingId, { exact: true })).toBeVisible();
    await page.getByRole('button', { name: '确认核销' }).click();
    await expect(page.getByRole('heading', { name: '核销成功' })).toBeVisible();
    await expect(page.getByRole('heading', { name: '项目结算' })).toBeVisible();
    await expect(page.getByText('¥64.68', { exact: true }).first()).toBeVisible();
    await page.getByTestId('confirm-settlement').click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认结算' }).click();
    await expect(page.getByText('结算完成', { exact: true })).toBeVisible();

    await page.goto('/#/brand/members');
    await expect(page.getByText('会员运营', { exact: true }).first()).toBeVisible();
    await expect(page.locator('.member-operations .el-table__body-wrapper:visible').getByText('李四', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: /会员消费/ }).click();
    await expect(page.getByText(bookingId, { exact: true })).toBeVisible();
    await page.getByRole('button', { name: '执行本周补贴' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认执行' }).click();
    await page.getByRole('button', { name: '执行本周补贴' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认执行' }).click();
    await expect(page.locator('.el-message').filter({ hasText: '本周补贴已执行' })).toBeVisible();
    await expect(page.locator('.el-message-box__wrapper')).toBeHidden();
    await expect(page.locator('.member-operations .el-table__body-wrapper:visible').getByText('¥51.00', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('¥NaN', { exact: true })).toHaveCount(0);
    await expect(page.locator('.el-message:visible')).toHaveCount(0, { timeout: 10000 });
    await page.screenshot({ path: testInfo.outputPath('brand-membership-operations.png'), fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '陈女士' }).click();
    await page.goto('/#/c/store/TL1001/assets');
    await expect(page.getByText('¥102.00', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '补贴', exact: true }).click();
    await expect(page.getByText('第2期集团补贴')).toBeVisible();

    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '重置演示数据' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认重置' }).click();
    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '李四' }).click();
    await page.goto('/#/c/store/TL1001/membership');
    await expect(page.getByRole('button', { name: '模拟支付510元开通' })).toBeEnabled();

    expect(consoleErrors).toEqual([]);
  });
});
