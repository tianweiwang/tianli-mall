import { expect, test } from '@playwright/test';

async function resetAt(page, route = '/#/brand/products') {
  await page.goto(route);
  await page.evaluate(() => localStorage.removeItem('tianli.franchise-demo.v1'));
  await page.reload();
}

function formItem(dialog, label) {
  return dialog.locator('.el-form-item').filter({ hasText: label }).first();
}

test.describe('brand catalog management', () => {
  test.skip(({ isMobile }) => isMobile, '目录管理按桌面后台视口验收');

  test('creates a store-scoped service and synchronizes deactivation', async ({ page }) => {
    await resetAt(page);
    await page.getByTestId('create-service').click();
    const dialog = page.locator('.el-dialog:visible');
    await formItem(dialog, '名称').locator('input').fill('门店肩颈舒缓演示');
    await formItem(dialog, '服务系列').locator('input').fill('经典系列');
    await formItem(dialog, '单次价格').locator('input').fill('268');
    await formItem(dialog, '项目说明').locator('textarea').fill('用于总店目录投放联动演示。');
    await dialog.getByText('天俪锦江店', { exact: true }).click();
    await dialog.getByTestId('catalog-editor-submit').click();

    await expect(page.getByText('目录信息已保存')).toBeVisible();
    await expect(page.locator('.el-table__body').first()).toContainText('门店肩颈舒缓演示');

    await page.goto('/#/c/store/TL1001/category?tab=services');
    await expect(page.getByTestId('service-SV1032')).toContainText('门店肩颈舒缓演示');
    await page.goto('/#/c/store/TL1002/category?tab=services');
    await expect(page.getByText('门店肩颈舒缓演示', { exact: true })).toHaveCount(0);

    await page.goto('/#/brand/products');
    await page.getByPlaceholder('项目名称').fill('门店肩颈舒缓演示');
    await page.getByTestId('status-service-SV1032').last().click();
    await page.getByRole('button', { name: '确认下架', exact: true }).click();
    await expect(page.getByText('已下架').first()).toBeVisible();

    await page.goto('/#/c/store/TL1001/category?tab=services');
    await expect(page.getByText('门店肩颈舒缓演示', { exact: true })).toHaveCount(0);
    await page.goto('/#/store/products');
    await expect(page.getByText('门店肩颈舒缓演示', { exact: true })).toHaveCount(0);
  });

  test('limits a product to one store and blocks payment after deactivation', async ({ page }) => {
    await resetAt(page, '/#/c/store/TL1001/product/1');
    await page.getByRole('button', { name: '加入购物车' }).click();

    await page.goto('/#/brand/products');
    await page.getByRole('tab', { name: /实物商品/ }).click();
    await page.getByTestId('edit-product-1').last().click();
    const dialog = page.locator('.el-dialog:visible');
    await dialog.getByText('天俪滨江店', { exact: true }).click();
    await dialog.getByTestId('catalog-editor-submit').click();
    await expect(page.getByText('目录信息已保存')).toBeVisible();

    await page.goto('/#/c/store/TL1002/category');
    await expect(page.getByText('IGOOD修复液', { exact: true })).toHaveCount(0);
    await page.goto('/#/c/store/TL1001/category');
    await expect(page.getByText('IGOOD修复液', { exact: true })).toBeVisible();

    await page.goto('/#/brand/products');
    await page.getByRole('tab', { name: /实物商品/ }).click();
    await page.getByTestId('status-product-1').last().click();
    await page.getByRole('button', { name: '确认下架', exact: true }).click();

    await page.goto('/#/c/store/TL1001/cart');
    await page.getByRole('link', { name: '去结算' }).click();
    await page.getByRole('link', { name: '去支付' }).click();
    await page.getByRole('button', { name: '确认支付' }).click();
    await expect(page.getByText('商品已下架或当前门店不可售')).toBeVisible();
    await expect(page).toHaveURL(/#\/c\/store\/TL1001\/payment\?source=wechat$/);
  });
});
