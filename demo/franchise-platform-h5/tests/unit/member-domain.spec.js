import { createSeed } from '../../src/mock/seed';
import {
  accountForCustomer,
  assetLedgerForCustomer,
  memberProfileForCustomer,
} from '../../src/mock/memberSelectors';
import {
  purchaseMembership,
  rechargeStoredValue,
} from '../../src/mock/memberTransitions';
import { awardPoints, redeemPoints } from '../../src/mock/transitions';
import { pointsOverview, switchableCustomerAccounts } from '../../src/mock/selectors';

describe('member accounts', () => {
  it('seeds separate scenarios for Chen and Li', () => {
    const state = createSeed();
    const chen = memberProfileForCustomer(state, 'CUS1001');
    const li = memberProfileForCustomer(state, 'CUS1004');

    expect(chen.customer.name).toBe('陈女士');
    expect(chen.plans.map((item) => item.planType)).toEqual(['membership-510', 'stored-value-5100']);
    expect(chen.subsidyPlan).toMatchObject({ totalPeriods: 100, periodAmountCents: 5100, status: 'active' });
    expect(li.customer.name).toBe('李四');
    expect(li.plans).toHaveLength(0);
    expect(li.account).toMatchObject({ principalBalanceCents: 0, pointsBalance: 0, subsidyBalanceCents: 0 });
    expect(accountForCustomer(state, 'CUS1001')).not.toBe(accountForCustomer(state, 'CUS1004'));
    expect(switchableCustomerAccounts(state).map((item) => item.name)).toEqual(expect.arrayContaining(['陈女士', '李四']));
  });

  it('opens a 510 membership once and grants seven traceable benefits', () => {
    const state = createSeed();
    const input = { customerId: 'CUS1004', paymentAttemptId: 'MEM-PAY-1001' };

    const first = purchaseMembership(state, input);
    const second = purchaseMembership(state, input);
    const profile = memberProfileForCustomer(state, 'CUS1004');

    expect(second.id).toBe(first.id);
    expect(profile.plans.filter((item) => item.planType === 'membership-510')).toHaveLength(1);
    expect(profile.benefits).toHaveLength(7);
    expect(profile.benefits.filter((item) => item.type === 'service-voucher')).toHaveLength(2);
    expect(profile.benefits.filter((item) => item.type === 'goods-voucher')).toHaveLength(3);
    expect(profile.effectiveBenefits).toMatchObject({ serviceDiscountRate: 66, goodsDiscountRate: 85 });
  });

  it('recharges principal and gift points once without granting card-only benefits', () => {
    const state = createSeed();
    const input = { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-PAY-1001' };

    const first = rechargeStoredValue(state, input);
    const second = rechargeStoredValue(state, input);
    const profile = memberProfileForCustomer(state, 'CUS1004');

    expect(second.id).toBe(first.id);
    expect(profile.account).toMatchObject({
      principalBalanceCents: 510000,
      principalRechargedCents: 510000,
      pointsBalance: 10200,
      pointsEarned: 10200,
    });
    expect(profile.benefits).toHaveLength(0);
    expect(profile.effectiveBenefits).toMatchObject({ serviceDiscountRate: 66, goodsDiscountRate: 100 });
    expect(assetLedgerForCustomer(state, 'CUS1004', 'principal')).toHaveLength(1);
    expect(assetLedgerForCustomer(state, 'CUS1004', 'points')).toHaveLength(1);
  });

  it('keeps daily point rewards and redemption scoped to the active customer', () => {
    const state = createSeed();
    const chenBefore = accountForCustomer(state, 'CUS1001').pointsBalance;
    state.activeCustomerId = 'CUS1004';
    state.activeCustomerName = '李四';

    awardPoints(state, { title: '预约奖励 BK1001', points: 100 });
    redeemPoints(state, { title: '兑换服务券', points: 40 });

    expect(pointsOverview(state)).toMatchObject({ balance: 60, totalEarned: 100, totalSpent: 40 });
    expect(accountForCustomer(state, 'CUS1001').pointsBalance).toBe(chenBefore);
    expect(assetLedgerForCustomer(state, 'CUS1004', 'points').map((item) => item.amount)).toEqual([-40, 100]);
  });
});
