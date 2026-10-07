import { createSeed } from '../../src/mock/seed';
import {
  accountForCustomer,
  calculateGoodsPricing,
  calculateServiceSettlement,
} from '../../src/mock/memberSelectors';
import {
  purchaseMembership,
  rechargeStoredValue,
} from '../../src/mock/memberTransitions';
import { createPaidOrder } from '../../src/mock/transitions';

describe('member pricing and goods payment', () => {
  it('applies goods discount only to 510 members and service discount to both plans', () => {
    const state = createSeed();

    expect(calculateGoodsPricing(state, { customerId: 'CUS1004', originalCents: 100000 }))
      .toMatchObject({ discountRate: 100, payableCents: 100000 });
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-PRICE-1001' });
    expect(calculateGoodsPricing(state, { customerId: 'CUS1004', originalCents: 100000 }))
      .toMatchObject({ discountRate: 100, payableCents: 100000 });
    expect(calculateServiceSettlement(state, { customerId: 'CUS1004', originalCents: 100000, paymentSource: 'principal' }))
      .toMatchObject({ discountRate: 66, principalCents: 66000, points: 0 });
    expect(calculateServiceSettlement(state, { customerId: 'CUS1004', originalCents: 100000, paymentSource: 'points' }))
      .toMatchObject({ discountRate: 100, principalCents: 0, points: 1000 });

    purchaseMembership(state, { customerId: 'CUS1004', paymentAttemptId: 'MEMBER-PRICE-1001' });
    expect(calculateGoodsPricing(state, { customerId: 'CUS1004', originalCents: 100000 }))
      .toMatchObject({ discountRate: 85, payableCents: 85000 });
  });

  it('pays a member goods order from principal and stores an immutable pricing snapshot', () => {
    const state = createSeed();
    state.activeCustomerId = 'CUS1004';
    state.activeCustomerName = '李四';
    purchaseMembership(state, { customerId: 'CUS1004', paymentAttemptId: 'MEMBER-ORDER-1001' });
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-ORDER-1001' });
    state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];

    const before = accountForCustomer(state, 'CUS1004').principalBalanceCents;
    const order = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentSource: 'principal',
      paymentAttemptId: 'ORDER-PRINCIPAL-1001',
    });
    const again = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentSource: 'principal',
      paymentAttemptId: 'ORDER-PRINCIPAL-1001',
    });

    expect(again.id).toBe(order.id);
    expect(order).toMatchObject({
      customerId: 'CUS1004',
      originalGoodsCents: 49800,
      discountRate: 85,
      paidCents: 42330,
      paymentSource: 'principal',
      assetDeduction: 42330,
      subsidyEligiblePrincipalCents: 42330,
    });
    expect(accountForCustomer(state, 'CUS1004')).toMatchObject({
      principalBalanceCents: before - 42330,
      eligiblePrincipalSpentCents: 42330,
    });
    expect(state.memberConsumptions.filter((item) => item.orderId === order.id)).toHaveLength(1);
  });

  it('creates only one subsidy plan when eligible principal reaches 5100 yuan', () => {
    const state = createSeed();
    state.activeCustomerId = 'CUS1004';
    state.activeCustomerName = '李四';
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-THRESHOLD-1001' });
    const account = accountForCustomer(state, 'CUS1004');
    account.eligiblePrincipalSpentCents = 505000;
    state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];

    createPaidOrder(state, {
      addressId: 'ADDR1', delivery: 'express', paymentSource: 'principal', paymentAttemptId: 'ORDER-THRESHOLD-1001',
    });

    expect(state.subsidyPlans.filter((item) => item.customerId === 'CUS1004')).toHaveLength(1);
    expect(state.subsidyPlans.find((item) => item.customerId === 'CUS1004')).toMatchObject({
      targetPrincipalCents: 510000,
      totalPeriods: 100,
      periodAmountCents: 5100,
      paidPeriods: 0,
    });
  });
});
