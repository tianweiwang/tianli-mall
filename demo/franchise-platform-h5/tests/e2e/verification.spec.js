import { expect, test } from '@playwright/test';
import QRCode from 'qrcode';
import { buildBusinessUrl } from '../../src/qr/codec';

test.describe('store booking verification', () => {
  test.skip(({ isMobile }) => isMobile, '门店核销台按桌面端视口验收');

  test('verifies a pending local booking by backup code', async ({ page }) => {
    await page.goto('/#/store/verify');
    await page.getByLabel('备用核销编号').fill('826101');
    await page.getByRole('button', { name: '查询预约' }).click();
    await expect(page.getByText('BK202607302001', { exact: true }).first()).toBeVisible();

    await page.getByRole('button', { name: '确认核销' }).click();

    await expect(page.getByRole('heading', { name: '核销成功' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return state.bookings.find((item) => item.id === 'BK202607302001')?.status;
    })).toBe('verified');
  });

  for (const [code, resultTitle] of [
    ['826105', '非本店预约'],
    ['826102', '请勿重复核销'],
  ]) {
    test(`rejects backup code ${code} with ${resultTitle}`, async ({ page }) => {
      await page.goto('/#/store/verify');
      await page.getByLabel('备用核销编号').fill(code);
      await page.getByRole('button', { name: '查询预约' }).click();

      await expect(page.getByRole('heading', { name: resultTitle })).toBeVisible();
      await expect(page.getByRole('button', { name: '确认核销' })).toHaveCount(0);
    });
  }

  test('rejects an expired booking at its own store', async ({ page }) => {
    await page.goto('/#/c/store/TL1002');
    await expect.poll(() => page.evaluate(() => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return state.activeStoreId;
    })).toBe('TL1002');
    await page.goto('/#/store/verify');
    await page.getByLabel('备用核销编号').fill('826104');
    await page.getByRole('button', { name: '查询预约' }).click();

    await expect(page.getByRole('heading', { name: '预约已过期' })).toBeVisible();
    await expect(page.getByRole('button', { name: '确认核销' })).toHaveCount(0);
  });

  test('decodes an uploaded QR image and imports only the scanning-device record', async ({ page }) => {
    const bookingId = 'BK202607309901';
    const value = buildBusinessUrl('http://127.0.0.1:4174/', {
      type: 'booking',
      id: bookingId,
      storeId: 'TL1001',
      issuedAt: 1785375000,
    });
    const buffer = await QRCode.toBuffer(value, { width: 320, margin: 2 });

    await page.goto('/#/store/verify');
    await page.getByLabel('识别二维码图片').setInputFiles({
      name: 'booking-code.png',
      mimeType: 'image/png',
      buffer,
    });

    await expect(page.getByText('此二维码来自其他设备')).toBeVisible();
    await page.getByRole('button', { name: '确认核销' }).click();
    await expect(page.getByRole('heading', { name: '核销成功' })).toBeVisible();
    await expect.poll(() => page.evaluate((id) => {
      const state = JSON.parse(localStorage.getItem('tianli.franchise-demo.v1'));
      return state.bookings.find((item) => item.id === id);
    }, bookingId)).toMatchObject({
      id: bookingId,
      storeId: 'TL1001',
      status: 'verified',
      imported: true,
    });
  });
});
