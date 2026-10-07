import { createRouter } from '../../src/router';
import { createSeed } from '../../src/mock/seed';
import * as selectors from '../../src/mock/selectors';

describe('store console data scope', () => {
  it('calculates operating metrics from revenue orders owned by one store', () => {
    expect(selectors.storeOverview).toBeTypeOf('function');

    const state = createSeed();
    const overview = selectors.storeOverview(state, 'TL1001');
    const otherStore = selectors.storeOverview(state, 'TL1002');

    expect(overview).toMatchObject({
      orderCount: 3,
      revenueOrderCount: 3,
      grossSalesCents: 645800,
      expectedIncomeCents: 516640,
      pendingBookingCount: 4,
      verifiedBookingCount: 3,
      arrivalRate: 43,
      openSettlementCount: 3,
    });
    expect(otherStore).toMatchObject({
      orderCount: 2,
      revenueOrderCount: 1,
      grossSalesCents: 39800,
      expectedIncomeCents: 31840,
    });
  });

  it('builds a seven-day trend and product ranking from the same store orders', () => {
    expect(selectors.salesTrendForStore).toBeTypeOf('function');
    expect(selectors.productRankingForStore).toBeTypeOf('function');

    const state = createSeed();
    const trend = selectors.salesTrendForStore(state, 'TL1001');
    const ranking = selectors.productRankingForStore(state, 'TL1001');

    expect(trend).toHaveLength(7);
    expect(trend.at(-1)).toEqual({ date: '2026-07-30', label: '07-30', salesCents: 645800 });
    expect(ranking.map((item) => item.productId)).toEqual([5, 4, 3]);
    expect(ranking.map((item) => item.quantity)).toEqual([1, 1, 1]);
  });

  it('keeps every settlement amount inside the operating store', () => {
    const state = createSeed();
    const rows = selectors.profitSharingForStore(state, 'TL1001');

    expect(rows).toHaveLength(5);
    expect(rows.every((item) => item.storeId === 'TL1001')).toBe(true);
    expect(rows.every(
      (item) => item.storeIncomeCents + item.brandCommissionCents
        + Number(item.regionalAgentIncomeCents || 0) === item.paidCents,
    )).toBe(true);
  });
});

describe('store console routes', () => {
  it.each([
    ['/store/dashboard', 'StoreDashboard'],
    ['/store/orders', 'StoreOrders'],
    ['/store/bookings', 'StoreBookings'],
    ['/store/assessments', 'StoreAssessments'],
    ['/store/assessments/AST202607301003', 'StoreAssessmentDetail'],
    ['/store/verify', 'StoreVerify'],
    ['/store/live', 'StoreLive'],
    ['/store/products', 'StoreProducts'],
    ['/store/performance', 'StorePerformance'],
    ['/store/settlements', 'StoreSettlements'],
    ['/store/withdrawals', 'StoreWithdrawals'],
    ['/store/profile', 'StoreProfile'],
  ])('loads %s as a concrete page', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe(componentName);
  });
});
