import { createRouter } from '../../src/router';
import { createSeed } from '../../src/mock/seed';
import * as selectors from '../../src/mock/selectors';
import {
  acceptShareAttribution,
  createPaidOrder,
  recordShare,
} from '../../src/mock/transitions';

describe('brand console data scope', () => {
  it('summarizes revenue and appointments across every store', () => {
    expect(selectors.brandOverview).toBeTypeOf('function');

    const overview = selectors.brandOverview(createSeed());

    expect(overview).toMatchObject({
      activeStoreCount: 2,
      orderCount: 5,
      revenueOrderCount: 4,
      grossSalesCents: 685600,
      brandCommissionCents: 137120,
      storeIncomeCents: 548480,
      bookingCount: 15,
      pendingBookingCount: 8,
      verifiedBookingCount: 4,
      arrivalRate: 33,
      openSettlementCount: 4,
      reviewingStoreCount: 1,
    });
  });

  it('ranks stores and categories from revenue orders only', () => {
    expect(selectors.storeRanking).toBeTypeOf('function');
    expect(selectors.categorySalesForBrand).toBeTypeOf('function');

    const state = createSeed();
    const stores = selectors.storeRanking(state);
    const categories = selectors.categorySalesForBrand(state);

    expect(stores.map((item) => item.storeId)).toEqual(['TL1001', 'TL1002', 'TL1003']);
    expect(stores.map((item) => item.grossSalesCents)).toEqual([645800, 39800, 0]);
    expect(categories).toEqual([
      { category: '家居理疗', quantity: 2, salesCents: 596000 },
      { category: '营养补充', quantity: 1, salesCents: 49800 },
      { category: '养护护理', quantity: 1, salesCents: 39800 },
    ]);
  });

  it('builds the platform trend without refunded orders', () => {
    expect(selectors.salesTrendForBrand).toBeTypeOf('function');

    const trend = selectors.salesTrendForBrand(createSeed());

    expect(trend).toHaveLength(7);
    expect(trend.at(-1)).toEqual({ date: '2026-07-30', label: '07-30', salesCents: 685600 });
  });

  it('derives share binding, attributed order and settled commission totals', () => {
    const state = createSeed();
    const share = recordShare(state, {
      channel: '微信好友',
      content: '门店首页',
      contentType: 'store',
      contentId: 'TL1001',
      storeId: 'TL1001',
    });
    acceptShareAttribution(state, {
      token: share.token,
      customerName: '赵女士',
      phone: '138****6008',
    });
    state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'brand-overview-attribution-001',
    });

    expect(selectors.brandGrowthOverview(state)).toMatchObject({
      shareBindings: 1,
      attributedOrders: 1,
      settledCommissionCents: 21340,
    });
  });
});

describe('brand console routes', () => {
  it.each([
    ['/brand/dashboard', 'BrandDashboard'],
    ['/brand/stores', 'BrandStores'],
    ['/brand/stores/TL1001', 'BrandStoreDetail'],
    ['/brand/agents', 'BrandAgents'],
    ['/brand/agents/RA1001', 'BrandAgentDetail'],
    ['/brand/onboarding', 'BrandOnboarding'],
    ['/brand/products', 'BrandProducts'],
    ['/brand/orders', 'BrandOrders'],
    ['/brand/bookings', 'BrandBookings'],
    ['/brand/live', 'BrandLive'],
    ['/brand/sharing', 'BrandProfitSharing'],
    ['/brand/sharing/PS202607303001', 'BrandProfitSharingDetail'],
    ['/brand/distribution', 'BrandDistribution'],
    ['/brand/withdrawals', 'BrandWithdrawals'],
    ['/brand/analytics', 'BrandAnalytics'],
  ])('loads %s as a concrete page', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe(componentName);
  });
});
