import { expect, test } from '@playwright/test';

test.describe('record detail drilldowns', () => {
  test.skip(({ isMobile }) => isMobile, 'Desktop management console scenario');

  test('opens every reused and lightweight detail surface', async ({ page }) => {
    await page.goto('/#/brand/dashboard');
    await page.getByRole('link', { name: '查看门店 TL1001' }).click();
    await expect(page).toHaveURL(/#\/brand\/stores\/TL1001$/);

    await page.goto('/#/brand/analytics');
    await page.getByRole('link', { name: '查看门店 TL1001' }).click();
    await expect(page).toHaveURL(/#\/brand\/stores\/TL1001$/);

    await page.goto('/#/brand/dashboard');
    await page.getByRole('link', { name: /查看分账 PS/ }).first().click();
    await expect(page).toHaveURL(/#\/brand\/sharing\/PS/);

    await page.goto('/#/store/dashboard');
    await page.getByRole('link', { name: /查看订单 ORD/ }).first().click();
    await expect(page).toHaveURL(/#\/store\/orders\?recordId=ORD/);
    await expect(page.getByRole('heading', { name: '订单详情' })).toBeVisible();

    await page.goto('/#/store/dashboard');
    await page.getByRole('link', { name: /查看预约 BK/ }).first().click();
    await expect(page).toHaveURL(/#\/store\/bookings\?recordId=BK/);
    await expect(page.getByRole('heading', { name: '预约详情' })).toBeVisible();

    await page.goto('/#/brand/stores/TL1001');
    await page.getByRole('link', { name: /查看订单 ORD/ }).first().click();
    await expect(page).toHaveURL(/#\/brand\/orders\?recordId=ORD/);
    await expect(page.getByRole('heading', { name: '订单详情' })).toBeVisible();

    await page.goto('/#/brand/distribution');
    await page.getByRole('button', { name: /查看分销员 DST/ }).click();
    await expect(page.getByRole('heading', { name: '分销员详情' })).toBeVisible();
    await page.getByRole('button', { name: '关闭' }).click();
    await page.getByRole('button', { name: /查看佣金 CM/ }).first().click();
    await expect(page.getByRole('heading', { name: '佣金详情' })).toBeVisible();
    await page.getByRole('button', { name: '关闭' }).click();
    await page.getByRole('button', { name: /查看分享/ }).first().click();
    await expect(page.getByRole('heading', { name: '分享详情' })).toBeVisible();

    await page.goto('/#/store/withdrawals');
    await page.getByRole('button', { name: /查看提现 WD/ }).first().click();
    await expect(page.getByRole('heading', { name: '提现详情' })).toBeVisible();

    await page.goto('/#/brand/withdrawals');
    await page.getByRole('button', { name: /查看提现 WD/ }).first().click();
    await expect(page.getByRole('heading', { name: '提现详情' })).toBeVisible();

    await page.goto('/#/store/live');
    await page.getByRole('button', { name: '查看直播 LR1002' }).click();
    await expect(page.getByRole('heading', { name: '直播详情' })).toBeVisible();
    await page.getByRole('button', { name: '开始直播' }).click();
    await expect(page.getByText('直播中', { exact: true }).last()).toBeVisible();
    await page.getByRole('button', { name: '结束直播' }).click();
    await expect(page.getByText('已结束', { exact: true }).last()).toBeVisible();

    await page.goto('/#/brand/live');
    await page.getByRole('button', { name: '查看直播 LR1002' }).click();
    await expect(page.getByRole('heading', { name: '直播详情' })).toBeVisible();
    await expect(page.getByText('已结束', { exact: true }).last()).toBeVisible();

    await page.goto('/#/brand/devices');
    await page.getByRole('button', { name: /查看设备/ }).first().click();
    await expect(page.getByRole('heading', { name: '设备详情' })).toBeVisible();
  });
});
