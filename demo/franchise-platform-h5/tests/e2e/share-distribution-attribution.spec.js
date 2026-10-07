import { expect, test } from '@playwright/test';

test.describe('share distribution attribution demo', () => {
  test.skip(({ isMobile }) => isMobile, '完整跨角色链路在桌面项目执行');

  test('runs sharing, attribution, settlement, withdrawal and approval', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001/share');
    await page.getByRole('button', { name: /模拟分享/ }).click();
    await page.getByRole('link', { name: '模拟好友打开' }).click();
    await expect(page.getByRole('heading', { name: '由 陈女士 推荐' })).toBeVisible();
    await page.getByLabel('好友姓名').fill('赵女士');
    await page.getByLabel('好友手机').fill('138****6008');
    await page.getByRole('button', { name: '接受推荐并进入商城' }).click();
    await expect(page).toHaveURL(/#\/c\/store\/TL1001$/);

    await page.goto('/#/c/store/TL1001/profile');
    await expect(page.getByRole('heading', { name: '赵女士' })).toBeVisible();
    await expect(page.getByTestId('recommendation-relationship')).toContainText('推荐人');
    await expect(page.getByTestId('recommendation-relationship')).toContainText('陈女士');
    await expect(page.getByTestId('recommendation-relationship')).toContainText('待完成首单');
    await expect(page.getByRole('link', { name: '分享中心' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: '分销中心' })).toHaveCount(0);

    await page.goto('/#/c/store/TL1001/orders');
    await expect(page.getByText('暂无该状态订单')).toBeVisible();

    await page.goto('/#/c/store/TL1001/product/3');
    await page.locator('.product-detail').getByRole('button', { name: '加入购物车' }).click();
    await page.goto('/#/c/store/TL1001/checkout');
    await expect(page.getByText('推荐关系已绑定')).toBeVisible();
    await page.getByRole('link', { name: '去支付' }).click();
    await page.getByRole('button', { name: '确认支付' }).click();
    await expect(page.getByTestId('attribution-success')).toBeVisible();
    const friendOrderId = await page.getByTestId('order-id').textContent();
    await page.goto('/#/c/store/TL1001/orders');
    await expect(page.getByText(friendOrderId, { exact: true })).toBeVisible();
    await expect(page.getByText('ORD202607301001', { exact: true })).toHaveCount(0);
    await expect(page.locator('.el-message')).toHaveCount(0, { timeout: 5000 });
    await page.screenshot({
      path: testInfo.outputPath('customer-attribution-success.png'),
      fullPage: true,
    });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/brand/sharing');
    await page.getByRole('link', { name: '查看详情' }).first().click();
    await page.getByRole('button', { name: '开始分账' }).click();
    await page.getByRole('button', { name: '确认推进' }).click();
    await page.getByRole('button', { name: '确认完成' }).click();
    await page.getByRole('button', { name: '确认推进' }).click();
    await expect(page.getByText('已结算', { exact: true })).toBeVisible();
    await expect(page.locator('.el-message-box__wrapper')).toBeHidden();
    await expect(page.locator('.el-message')).toHaveCount(0, { timeout: 5000 });
    await page.screenshot({
      path: testInfo.outputPath('brand-commission-settled.png'),
      fullPage: true,
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: '打开演示导航' }).click();
    await page.getByRole('button', { name: '陈女士' }).click();
    await expect(page.getByRole('heading', { name: '陈女士' })).toBeVisible();
    await page.goto('/#/c/store/TL1001/orders');
    await expect(page.getByText('ORD202607301001', { exact: true })).toBeVisible();
    await expect(page.getByText(friendOrderId, { exact: true })).toHaveCount(0);
    await page.goto('/#/c/store/TL1001/distribution');
    await page.getByLabel('提现金额').fill('10');
    await page.getByLabel('收款账号').fill('138****6008');
    await page.getByRole('button', { name: '提交提现申请' }).click();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/brand/withdrawals');
    await page.getByRole('button', { name: '通过' }).first().click();
    await expect(page.getByText('处理中', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '通过' }).first().click();
    await expect(page.getByText('已到账', { exact: true }).first()).toBeVisible();

    expect(consoleErrors).toEqual([]);
  });
});
