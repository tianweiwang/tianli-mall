import { createSeed } from '../../src/mock/seed';
import { accountForCustomer, assetLedgerForCustomer } from '../../src/mock/memberSelectors';
import { runWeeklySubsidy } from '../../src/mock/memberTransitions';

describe('weekly subsidy batch', () => {
  it('credits customer and brand records once for a period', () => {
    const state = createSeed();
    const before = accountForCustomer(state, 'CUS1001').subsidyBalanceCents;

    const first = runWeeklySubsidy(state, { periodKey: '2026-W31' });
    const second = runWeeklySubsidy(state, { periodKey: '2026-W31' });
    const plan = state.subsidyPlans.find((item) => item.customerId === 'CUS1001');

    expect(first).toMatchObject({ periodKey: '2026-W31', alreadyExecuted: false, totalAmountCents: 5100 });
    expect(first.records).toHaveLength(1);
    expect(second).toMatchObject({ periodKey: '2026-W31', alreadyExecuted: true, totalAmountCents: 5100 });
    expect(accountForCustomer(state, 'CUS1001').subsidyBalanceCents).toBe(before + 5100);
    expect(plan).toMatchObject({ paidPeriods: 2, paidCents: 10200, lastPeriodKey: '2026-W31', status: 'active' });
    expect(assetLedgerForCustomer(state, 'CUS1001', 'subsidy')[0]).toMatchObject({ amount: 5100, businessType: 'weekly-subsidy' });
    expect(state.subsidyRuns.filter((item) => item.periodKey === '2026-W31')).toHaveLength(1);
  });

  it('pays the final period and completes the plan', () => {
    const state = createSeed();
    const plan = state.subsidyPlans[0];
    plan.paidPeriods = 99;
    plan.paidCents = 504900;
    plan.lastPeriodKey = '2026-W29';

    runWeeklySubsidy(state, { periodKey: '2026-W31' });

    expect(plan).toMatchObject({ paidPeriods: 100, paidCents: 510000, status: 'completed' });
    const balance = accountForCustomer(state, 'CUS1001').subsidyBalanceCents;
    runWeeklySubsidy(state, { periodKey: '2026-W32' });
    expect(accountForCustomer(state, 'CUS1001').subsidyBalanceCents).toBe(balance);
  });
});
