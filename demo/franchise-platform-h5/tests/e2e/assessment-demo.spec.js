import { expect, test } from '@playwright/test';

const storageKey = 'tianli.franchise-demo.v1';

async function resetDemo(page) {
  await page.goto('/#/c/store/TL1001');
  await page.evaluate((key) => localStorage.removeItem(key), storageKey);
  await page.reload();
  await expect(page.locator('.customer-main')).toBeVisible();
}

async function startSeedTask(page) {
  await page.goto('/#/store/assessments/AST202607301003');
  await expect(page).toHaveURL(/#\/store\/assessments\/AST202607301003$/);
  await expect(page.locator('.admin-topbar strong')).toHaveText('检测任务');
  await expect(page.locator('.admin-menu a.router-link-active')).toHaveText('检测中心');
  await page.getByRole('button', { name: '开始检测' }).click();
}

test.describe('device assessment demo', () => {
  test.skip(({ isMobile }) => isMobile, '三端检测链路由测试内显式切换消费者移动视口和后台桌面视口');

  test.beforeEach(async ({ page }) => {
    await resetDemo(page);
  });

  test('completes the shared assessment journey across three roles', async ({ page }) => {
    await page.goto('/#/store/assessments');
    await page.getByTestId('create-assessment-BK202607302001').click();
    const taskId = (await page.getByTestId('created-assessment-task-id').textContent()).trim();
    await page.goto(`/#/store/assessments/${taskId}`);
    await expect(page.getByText('固定门店设备', { exact: true })).toBeVisible();
    await expect(page.getByText('体态检测仪 A01', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '设备领取任务' })).toHaveCount(0);
    await page.getByRole('button', { name: '开始检测' }).click();
    await page.getByRole('button', { name: '模拟回传' }).click();
    await expect(page.getByText('待门店确认', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '确认并生成报告' }).click();

    const reportId = await page.getByTestId('generated-assessment-id').textContent();
    await page.goto(`/#/c/store/TL1001/assessments/${reportId.trim()}`);
    await expect(page.getByText('本报告用于记录体态变化')).toBeVisible();
    await expect(page.getByText('双肩高度差')).toBeVisible();

    await page.goto('/#/brand/assessments');
    await expect(page.getByText('检测运营', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('演示统计', { exact: true }).first()).toBeVisible();
    await page.getByPlaceholder('报告编号或顾客姓名').fill(reportId.trim());
    const recordPanel = page.locator('.assessment-record-panel');
    await expect(page.getByText('共 1 条', { exact: true })).toBeVisible();
    await expect(recordPanel.getByText(reportId.trim(), { exact: true }).first()).toBeVisible();
    await recordPanel.getByRole('button', { name: '查看' }).click();
    const drawer = page.locator('.el-drawer');
    await expect(drawer.getByText('检测报告详情')).toBeVisible();
    await expect(drawer.getByText('双肩高度差')).toBeVisible();
    await expect(drawer.getByText('骨盆倾斜角')).toBeVisible();
    await expect(drawer.getByText('颈部前倾角')).toBeVisible();
    await expect(drawer.getByText('自评不适程度')).toBeVisible();
    await expect(drawer.getByText('体态检测仪 A01')).toBeVisible();
    await expect(drawer.getByText('V1')).toBeVisible();
    await expect(drawer).toContainText('138****2046');
    await expect(drawer).not.toContainText(/1\d{10}/);
    await expect(drawer.locator('.assessment-image-strip')).toHaveCount(0);
  });

  test('recovers from an offline device without creating a report', async ({ page }) => {
    await startSeedTask(page);
    await page.getByRole('button', { name: '设备离线' }).click();
    await page.getByRole('button', { name: '模拟回传' }).click();
    await expect(page.getByText('等待重试', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '恢复并重试' }).click();
    await expect(page.getByText('当前状态：检测中', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '确认并生成报告' })).toHaveCount(0);
  });

  test('deduplicates repeated device results', async ({ page }) => {
    await startSeedTask(page);
    const beforeCount = await page.evaluate((key) => JSON.parse(localStorage.getItem(key)).assessmentRecords.length, storageKey);
    await page.getByRole('button', { name: '重复回传' }).click();
    await page.getByRole('button', { name: '模拟回传' }).click();

    await expect(page.getByText('已接收过该设备结果')).toBeVisible();
    await expect.poll(() => page.evaluate((key) => JSON.parse(localStorage.getItem(key)).assessmentRecords.length, storageKey)).toBe(beforeCount);
  });

  test('keeps incomplete results out of customer reports', async ({ page }) => {
    await startSeedTask(page);
    await page.getByRole('button', { name: '数据不完整' }).click();
    await page.getByRole('button', { name: '模拟回传' }).click();

    await expect(page.getByText('骨盆倾斜角')).toBeVisible();
    await expect(page.getByText('侧面影像')).toBeVisible();
    await expect(page.getByText('背面影像')).toBeVisible();
    await expect(page.getByRole('button', { name: '确认并生成报告' })).toHaveCount(0);
  });

  test('quarantines a store mismatch without creating a customer report', async ({ page }) => {
    await startSeedTask(page);
    const beforeCount = await page.evaluate((key) => JSON.parse(localStorage.getItem(key)).assessmentRecords.length, storageKey);
    await page.getByRole('button', { name: '门店不匹配' }).click();
    await page.getByRole('button', { name: '模拟回传' }).click();

    await expect(page.locator('.assessment-result').getByText('跨店结果已隔离')).toBeVisible();
    await expect.poll(() => page.evaluate((key) => JSON.parse(localStorage.getItem(key)).assessmentRecords.length, storageKey)).toBe(beforeCount);
    await expect(page.getByRole('button', { name: '确认并生成报告' })).toHaveCount(0);
  });

  test('restores the exact version 11 assessment, membership, and catalog seed', async ({ page }) => {
    await startSeedTask(page);
    await page.getByRole('button', { name: '设备离线' }).click();
    await page.getByRole('button', { name: '模拟回传' }).click();
    await resetDemo(page);

    const restored = await page.evaluate((key) => {
      const state = JSON.parse(localStorage.getItem(key));
      return {
        version: state.version,
        deviceCount: state.devices.length,
        taskStatus: state.assessmentTasks.find((item) => item.id === 'AST202607301003').status,
        taskDeviceId: state.assessmentTasks.find((item) => item.id === 'AST202607301003').deviceId,
        recordCount: state.assessmentRecords.length,
        sequence: state.sequence,
      };
    }, storageKey);
    expect(restored).toEqual({
      version: 11,
      deviceCount: 3,
      taskStatus: 'ready',
      taskDeviceId: 'DEV-TL-001',
      recordCount: 3,
      sequence: {
        order: 1005,
        booking: 2015,
        sharing: 3007,
        customer: 1002,
        live: 2002,
        points: 1003,
        referral: 1003,
        withdrawal: 1003,
        share: 1003,
        binding: 1000,
        commission: 1003,
        consent: 1003,
        assessmentTask: 1004,
        assessmentRecord: 1003,
        reassessmentPlan: 1001,
        membership: 1002,
        benefit: 1007,
        memberTransaction: 1007,
        memberConsumption: 1003,
        subsidyPlan: 1001,
        subsidyRun: 1001,
        subsidyBatch: 1001,
        product: 11,
        service: 31,
      },
    });
  });

  test('fits target viewports and captures assessment surfaces', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    const targets = [
      { route: '/#/c/store/TL1001/assessments/ASR202607301002', viewport: { width: 390, height: 844 }, name: 'customer-report' },
      { route: '/#/store/assessments/AST202607301003', viewport: { width: 1280, height: 800 }, name: 'store-assessment', title: '检测任务', activeMenu: '检测中心' },
      { route: '/#/brand/devices', viewport: { width: 1440, height: 900 }, name: 'brand-devices', title: '设备管理', activeMenu: '设备管理' },
      { route: '/#/brand/assessments', viewport: { width: 1440, height: 900 }, name: 'brand-assessments', title: '检测运营', activeMenu: '检测运营', settleCanvas: true, captureDrawer: true },
    ];

    for (const target of targets) {
      await page.setViewportSize(target.viewport);
      await page.goto(target.route);
      await expect(page.locator(target.route.includes('/c/store/') ? '.customer-main' : '.admin-page')).toBeVisible();
      if (target.title) {
        await expect(page.locator('.admin-topbar strong')).toHaveText(target.title);
        await expect(page.locator('.admin-menu a.router-link-active')).toHaveText(target.activeMenu);
      }
      if (target.settleCanvas) await page.waitForTimeout(1000);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      await page.screenshot({ path: testInfo.outputPath(`${target.name}.png`), fullPage: true });
      if (target.captureDrawer) {
        await page.locator('.assessment-record-panel').getByRole('button', { name: '查看' }).first().click();
        const drawer = page.locator('.el-drawer');
        await expect(drawer).toBeVisible();
        await expect.poll(async () => {
          const box = await drawer.boundingBox();
          return box ? Math.ceil(box.x + box.width) : Number.POSITIVE_INFINITY;
        }).toBeLessThanOrEqual(target.viewport.width);
        const drawerBox = await drawer.boundingBox();
        expect(drawerBox).not.toBeNull();
        expect(drawerBox.width).toBeGreaterThanOrEqual(500);
        await page.screenshot({ path: testInfo.outputPath(`${target.name}-detail.png`), fullPage: true });
      }
    }
    expect(consoleErrors).toEqual([]);
  });
});
