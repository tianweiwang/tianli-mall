import { createSeed } from '../../src/mock/seed';
import { accountForCustomer } from '../../src/mock/memberSelectors';
import { rechargeStoredValue, settleVerifiedBooking } from '../../src/mock/memberTransitions';

function createVerifiedBooking(state, overrides = {}) {
  const booking = {
    id: 'BK-MEMBER-1001',
    customerId: 'CUS1004',
    storeId: 'TL1001',
    serviceId: 'SV1030',
    status: 'verified',
    verifiedAt: state.demoNow,
    ...overrides,
  };
  state.bookings.unshift(booking);
  return booking;
}

describe('verified booking settlement', () => {
  it('creates one 50/35/15 sharing record for eligible principal settlement', () => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-AGENT-1001' });
    const booking = createVerifiedBooking(state, { id: 'BK-AGENT-1001', serviceId: 'SV1014' });
    const before = state.profitSharingRecords.length;

    const first = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-AGENT-1001',
    });
    const second = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-AGENT-1001',
    });
    const sharing = state.profitSharingRecords.find((item) => item.id === first.profitSharingRecordId);

    expect(second.id).toBe(first.id);
    expect(state.profitSharingRecords).toHaveLength(before + 1);
    expect(first.regionalSettlement).toMatchObject({
      settlementCents: 32868,
      storeIncomeCents: 16434,
      groupIncomeCents: 11504,
      regionalAgentIncomeCents: 4930,
      regionalAgentId: 'RA1001',
    });
    expect(sharing).toMatchObject({
      businessType: 'service-settlement',
      businessId: first.id,
      bookingId: booking.id,
      regionalAgentId: 'RA1001',
      status: 'pending',
    });
  });

  it('uses 50/50/0 for an eligible principal settlement at a direct store', () => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-DIRECT-1001' });
    const booking = createVerifiedBooking(state, { id: 'BK-DIRECT-1001', storeId: 'TL1002', serviceId: 'SV1007' });
    const result = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1002',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-DIRECT-1001',
    });

    expect(result.regionalSettlement).toMatchObject({
      groupRate: 50,
      regionalAgentRate: 0,
      regionalAgentId: null,
      storeIncomeCents: 6600,
      groupIncomeCents: 6600,
      regionalAgentIncomeCents: 0,
    });
  });

  it('keeps eligible points settlement cash values at zero without a sharing record', () => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-AGENT-POINTS' });
    const booking = createVerifiedBooking(state, { id: 'BK-AGENT-POINTS', serviceId: 'SV1007' });
    const before = state.profitSharingRecords.length;
    const result = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'points',
      settlementAttemptId: 'SETTLE-AGENT-POINTS',
    });

    expect(result.regionalSettlement).toMatchObject({ cashSettlement: false, settlementCents: 0 });
    expect(state.profitSharingRecords).toHaveLength(before);
  });

  it('does not apply the agent template to a traditional-series settlement', () => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-NON-AGENT' });
    const booking = createVerifiedBooking(state, { id: 'BK-NON-AGENT', serviceId: 'SV1004' });
    const before = state.profitSharingRecords.length;
    const result = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-NON-AGENT',
    });

    expect(result.regionalSettlement).toBeNull();
    expect(state.profitSharingRecords).toHaveLength(before);
  });

  it('deducts 66 percent from principal exactly once', () => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-SETTLE-1001' });
    const booking = createVerifiedBooking(state);

    const first = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-1001',
    });
    const second = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-1001',
    });

    expect(second.id).toBe(first.id);
    expect(first).toMatchObject({ originalCents: 98000, discountRate: 66, principalCents: 64680, points: 0 });
    expect(accountForCustomer(state, 'CUS1004')).toMatchObject({
      principalBalanceCents: 445320,
      eligiblePrincipalSpentCents: 64680,
    });
    expect(state.memberConsumptions.filter((item) => item.bookingId === booking.id)).toHaveLength(1);
  });

  it('deducts points at original yuan price without adding eligible principal', () => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-SETTLE-1002' });
    const booking = createVerifiedBooking(state, { id: 'BK-MEMBER-1002' });

    const result = settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'points',
      settlementAttemptId: 'SETTLE-1002',
    });

    expect(result).toMatchObject({ discountRate: 100, principalCents: 0, points: 980, subsidyEligiblePrincipalCents: 0 });
    expect(accountForCustomer(state, 'CUS1004')).toMatchObject({ pointsBalance: 9220, eligiblePrincipalSpentCents: 0 });
  });

  it.each([
    ['预约核销后才能结算', { status: 'pending' }, 'TL1001'],
    ['该预约属于其他门店', {}, 'TL1002'],
  ])('rejects invalid settlement with %s and leaves all domain data unchanged', (message, overrides, storeId) => {
    const state = createSeed();
    rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: `RECHARGE-${message}` });
    const booking = createVerifiedBooking(state, { id: `BK-${message}`, ...overrides });
    const before = JSON.stringify({ account: accountForCustomer(state, 'CUS1004'), consumptions: state.memberConsumptions, transactions: state.memberTransactions });

    expect(() => settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: storeId,
      paymentSource: 'principal',
      settlementAttemptId: `SETTLE-${message}`,
    })).toThrow(message);
    expect(JSON.stringify({ account: accountForCustomer(state, 'CUS1004'), consumptions: state.memberConsumptions, transactions: state.memberTransactions })).toBe(before);
    expect(booking.memberSettlement).toBeUndefined();
  });

  it('rejects insufficient balance without partial changes', () => {
    const state = createSeed();
    const booking = createVerifiedBooking(state, { id: 'BK-MEMBER-LOW-BALANCE' });
    const before = JSON.stringify(state);

    expect(() => settleVerifiedBooking(state, {
      bookingId: booking.id,
      operatingStoreId: 'TL1001',
      paymentSource: 'principal',
      settlementAttemptId: 'SETTLE-LOW-BALANCE',
    })).toThrow('储值本金余额不足');
    expect(JSON.stringify(state)).toBe(before);
  });
});
