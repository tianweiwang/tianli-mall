import { expect, test } from '@playwright/test';

test.describe('service card responsive actions', () => {
  test('keeps every booking action on one line in the mobile service grid', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-390', 'Mobile service-grid regression');
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await page.goto('/#/c/store/TL1001/category?tab=services');
    await expect(page).toHaveTitle('天俪加盟门店平台');
    await expect(page.locator('vite-error-overlay')).toHaveCount(0);

    const bookingActions = page.locator('.service-card__book');
    await expect(bookingActions.first()).toBeVisible();

    const lineCounts = await bookingActions.evaluateAll((elements) => elements.map((element) => {
      const range = document.createRange();
      range.selectNodeContents(element);
      return range.getClientRects().length;
    }));

    expect(lineCounts.length).toBeGreaterThan(1);
    expect(lineCounts.every((count) => count === 1)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await bookingActions.first().click();
    await expect(page).toHaveURL(/\/booking\?serviceId=/);
    expect(consoleErrors).toEqual([]);
  });
});
