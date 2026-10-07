import { expect, test } from '@playwright/test';

const storageKey = 'tianli.franchise-demo.v1';

async function prepareFundedMember(page, storeId) {
  await page.goto(`/#/c/store/${storeId}/profile`);
  await page.evaluate(() => window.localStorage.clear());
  await page.reload();
  await page.getByRole('button', { name: '打开演示导航' }).click();
  await page.getByRole('button', { name: '李四' }).click();
  await page.goto(`/#/c/store/${storeId}/membership`);
  await page.getByRole('button', { name: '模拟支付510元开通' }).click();
  await page.locator('.el-message-box').getByRole('button', { name: '确认支付' }).click();
  await page.getByRole('button', { name: '模拟储值5100元' }).click();
  await page.locator('.el-message-box').getByRole('button', { name: '确认支付' }).click();
  await expect(page.locator('.el-message-box__wrapper')).toBeHidden();
  await expect(page.locator('.el-message:visible')).toHaveCount(0, { timeout: 10000 });
}

async function createAndVerifyBooking(page, storeId, serviceId) {
  await page.goto(`/#/c/store/${storeId}/booking?serviceId=${serviceId}`);
  await page.getByRole('button', { name: '确认预约' }).click();
  const bookingId = (await page.getByTestId('booking-id').textContent()).trim();
  const verifyCode = (await page.getByTestId('verify-code').textContent()).trim();

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/#/store/verify');
  await page.getByLabel('备用核销编号').fill(verifyCode);
  await page.getByRole('button', { name: '查询预约' }).click();
  await page.getByRole('button', { name: '确认核销' }).click();
  await expect(page.getByRole('heading', { name: '项目结算' })).toBeVisible();
  return bookingId;
}

test.describe('regional agent settlement demo', () => {
  test('moves an eligible service split from pending to settled agent earnings', async ({ page, isMobile }, testInfo) => {
    test.skip(isMobile, '区域代理运营链路使用桌面品牌后台演示');
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.goto('/#/brand/agents');
    await page.evaluate(() => window.localStorage.clear());
    await page.reload();
    await expect(page.getByRole('heading', { name: '区域代理' })).toBeVisible();
    await page.getByRole('link', { name: '查看代理 川西区域运营中心' }).click();
    await expect(page.getByRole('heading', { name: '川西区域运营中心' })).toBeVisible();
    await expect(page.getByText('天俪锦江店', { exact: true })).toBeVisible();

    await page.goto('/#/brand/sharing');
    const pendingRow = page.locator('.admin-table-panel .el-table__body-wrapper:visible tbody tr')
      .filter({ hasText: 'MCO202607301003' });
    await expect(pendingRow).toHaveCount(1);
    await expect(pendingRow).toContainText('川西区域运营中心');
    await page.locator('.admin-table-panel .el-table__fixed-right:visible a[href="#/brand/sharing/PS202607303007"]').click();
    await expect(page.getByText('区域代理 15%', { exact: true })).toBeVisible();
    await expect(page.getByText('模拟品牌分账', { exact: false }).first()).toBeVisible();

    await page.getByRole('button', { name: '开始分账' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认推进' }).click();
    await page.getByRole('button', { name: '确认完成' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认推进' }).click();
    await expect(page.locator('.el-message-box__wrapper')).toBeHidden();
    await expect(page.locator('.el-message:visible')).toHaveCount(0, { timeout: 10000 });
    await expect(page.getByText('已完成', { exact: true }).first()).toBeVisible();

    await page.goto('/#/brand/agents/RA1001');
    await expect(page.getByText('累计已结收益', { exact: true })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('regional-agent-detail.png'), fullPage: true });

    await page.goto('/#/brand/distribution');
    await expect(page.getByText('个人分享佣金由集团推广预算承担', { exact: false })).toBeVisible();
    expect(consoleErrors).toEqual([]);
  });

  test('settles an eligible direct-store service as 50/50/0', async ({ page, isMobile }) => {
    test.skip(isMobile, '区域代理运营链路使用桌面品牌后台演示');
    await prepareFundedMember(page, 'TL1002');
    const bookingId = await createAndVerifyBooking(page, 'TL1002', 'SV1015');

    await page.getByTestId('confirm-settlement').click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认结算' }).click();
    await expect(page.getByText('结算完成', { exact: true })).toBeVisible();
    const snapshot = await page.evaluate(({ key, id }) => {
      const state = JSON.parse(localStorage.getItem(key));
      return state.bookings.find((item) => item.id === id).memberSettlement.regionalSettlement;
    }, { key: storageKey, id: bookingId });

    expect(snapshot).toMatchObject({
      regionalAgentId: null,
      storeRate: 50,
      groupRate: 50,
      regionalAgentRate: 0,
      regionalAgentIncomeCents: 0,
    });
    expect(snapshot.storeIncomeCents + snapshot.groupIncomeCents).toBe(snapshot.settlementCents);
  });

  test('keeps an eligible points settlement out of cash sharing', async ({ page, isMobile }) => {
    test.skip(isMobile, '区域代理运营链路使用桌面品牌后台演示');
    await prepareFundedMember(page, 'TL1001');
    const bookingId = await createAndVerifyBooking(page, 'TL1001', 'SV1012');
    const sharingBefore = await page.evaluate((key) => JSON.parse(localStorage.getItem(key)).profitSharingRecords.length, storageKey);

    await page.getByRole('radio', { name: /赠送积分/ }).check();
    await page.getByTestId('confirm-settlement').click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认结算' }).click();
    await expect(page.getByText('非现金结算', { exact: true })).toBeVisible();
    const result = await page.evaluate(({ key, id }) => {
      const state = JSON.parse(localStorage.getItem(key));
      const settlement = state.bookings.find((item) => item.id === id).memberSettlement;
      return { snapshot: settlement.regionalSettlement, sharingCount: state.profitSharingRecords.length };
    }, { key: storageKey, id: bookingId });

    expect(result.snapshot).toMatchObject({
      cashSettlement: false,
      settlementCents: 0,
      storeIncomeCents: 0,
      groupIncomeCents: 0,
      regionalAgentIncomeCents: 0,
    });
    expect(result.sharingCount).toBe(sharingBefore);
  });

});
