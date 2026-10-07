import { createSeed } from '../../src/mock/seed';
import {
  advanceSharing,
  cancelBooking,
  createBooking,
  createPaidOrder,
  expireBooking,
  verifyBooking,
} from '../../src/mock/transitions';
import * as transitions from '../../src/mock/transitions';

describe('order transitions', () => {
  it('creates a paid order and matching profit-sharing record', () => {
    const state = createSeed();
    const orderCount = state.orders.length;
    const sharingCount = state.profitSharingRecords.length;
    state.cart = [{ productId: 3, quantity: 2, spec: '盒装' }];

    const order = createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express' });

    expect(order).toMatchObject({
      storeId: 'TL1001',
      status: 'paid',
      paidCents: 84660,
      brandCommissionCents: 16932,
      storeIncomeCents: 67728,
      distributionAttribution: null,
      distributionCostCents: 0,
    });
    expect(order.storeIncomeCents + order.brandCommissionCents).toBe(order.paidCents);
    expect(state.orders).toHaveLength(orderCount + 1);
    expect(state.profitSharingRecords).toHaveLength(sharingCount + 1);
    expect(state.profitSharingRecords[0]).toMatchObject({
      orderId: order.id,
      storeId: order.storeId,
      status: 'pending',
      paidCents: order.paidCents,
    });
    expect(state.cart).toEqual([]);
  });

  it('rejects an empty cart without creating financial records', () => {
    const state = createSeed();
    const before = JSON.stringify(state);

    expect(() => createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express' })).toThrow('购物车为空');
    expect(JSON.stringify(state)).toBe(before);
  });

  it('rejects a disabled active store', () => {
    const state = createSeed();
    state.activeStoreId = 'TL1003';
    state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];

    expect(() => createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express' })).toThrow('当前门店不可用');
    expect(state.orders).toHaveLength(5);
    expect(state.cart).toHaveLength(1);
  });
});

describe('booking transitions', () => {
  it('holds one slot and creates no order or payment record', () => {
    const state = createSeed();
    const slot = state.slotInventory.find((item) => item.id === 'SL1001');
    const beforeBooked = slot.booked;
    const expectedVerifyCode = String(824100 + state.sequence.booking + 1);
    const beforeOrders = state.orders.length;
    const beforeSharing = state.profitSharingRecords.length;

    const booking = createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    });

    expect(booking).toMatchObject({
      customerId: 'CUS1001',
      storeId: 'TL1001',
      slotId: 'SL1001',
      status: 'pending',
      sourceAssessmentId: null,
      bookingType: 'service',
      verifyCode: expectedVerifyCode,
      released: false,
    });
    expect(slot.booked).toBe(beforeBooked + 1);
    expect(state.orders).toHaveLength(beforeOrders);
    expect(state.profitSharingRecords).toHaveLength(beforeSharing);
  });

  it('marks an assessment-originated booking as a reassessment', () => {
    const state = createSeed();

    const booking = createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
      sourceAssessmentId: 'ASR202607301002',
    });

    expect(booking).toMatchObject({
      customerId: 'CUS1001',
      sourceAssessmentId: 'ASR202607301002',
      bookingType: 'reassessment',
    });
  });

  it('rejects a full slot without changing capacity', () => {
    const state = createSeed();
    const slot = state.slotInventory.find((item) => item.id === 'SL1001');
    slot.booked = slot.capacity;
    const beforeBookings = state.bookings.length;

    expect(() => createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    })).toThrow('当前时段已满');
    expect(slot.booked).toBe(slot.capacity);
    expect(state.bookings).toHaveLength(beforeBookings);
  });

  it('releases capacity once when cancelled repeatedly', () => {
    const state = createSeed();
    const booking = createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    });
    const slot = state.slotInventory.find((item) => item.id === 'SL1001');
    const held = slot.booked;

    cancelBooking(state, booking.id);
    cancelBooking(state, booking.id);

    expect(booking.status).toBe('cancelled');
    expect(booking.released).toBe(true);
    expect(slot.booked).toBe(held - 1);
  });

  it('releases capacity once when expired repeatedly', () => {
    const state = createSeed();
    const booking = createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    });
    const slot = state.slotInventory.find((item) => item.id === 'SL1001');
    const held = slot.booked;

    expireBooking(state, booking.id);
    expireBooking(state, booking.id);

    expect(booking.status).toBe('expired');
    expect(booking.released).toBe(true);
    expect(slot.booked).toBe(held - 1);
    expect(slot.booked).toBeGreaterThanOrEqual(0);
  });
});

describe('verification transitions', () => {
  it('imports one marked local booking from a valid cross-device payload', () => {
    expect(transitions.importBookingFromPayload).toBeTypeOf('function');
    const state = createSeed();
    const payload = {
      version: 1,
      type: 'booking',
      id: 'BK202607309901',
      storeId: 'TL1001',
      issuedAt: 1785375000,
    };

    const imported = transitions.importBookingFromPayload(state, payload);
    const repeated = transitions.importBookingFromPayload(state, payload);

    expect(imported).toMatchObject({
      id: payload.id,
      storeId: payload.storeId,
      status: 'pending',
      imported: true,
      importedAt: state.demoNow,
      contactName: '跨设备演示顾客',
      serviceId: 'SV1001',
      slotId: null,
    });
    expect(repeated).toBe(imported);
    expect(state.bookings.filter((item) => item.id === payload.id)).toHaveLength(1);
  });

  it('rejects cross-device payloads for unknown stores or non-booking types', () => {
    expect(transitions.importBookingFromPayload).toBeTypeOf('function');
    const state = createSeed();
    const payload = {
      version: 1,
      type: 'booking',
      id: 'BK202607309902',
      storeId: 'TL9999',
      issuedAt: 1785375000,
    };

    expect(() => transitions.importBookingFromPayload(state, payload)).toThrow('二维码门店不存在');
    expect(() => transitions.importBookingFromPayload(state, {
      ...payload,
      type: 'order',
      storeId: 'TL1001',
    })).toThrow('当前只支持预约核销');
  });

  it('verifies a pending booking at its own store', () => {
    const state = createSeed();

    const result = verifyBooking(state, {
      bookingId: 'BK202607302001',
      operatingStoreId: 'TL1001',
    });

    expect(result.ok).toBe(true);
    expect(result.booking.status).toBe('verified');
    expect(result.booking.verifiedAt).toBe(state.demoNow);
  });

  it.each([
    ['cross-store', 'BK202607302001', 'TL1002', 'pending'],
    ['duplicate', 'BK202607302002', 'TL1001', 'verified'],
    ['expired', 'BK202607302004', 'TL1002', 'expired'],
  ])('rejects %s verification without changing the record', (
    expectedCode,
    bookingId,
    operatingStoreId,
    expectedStatus,
  ) => {
    const state = createSeed();

    const result = verifyBooking(state, { bookingId, operatingStoreId });

    expect(result).toMatchObject({ ok: false, code: expectedCode });
    expect(state.bookings.find((item) => item.id === bookingId).status).toBe(expectedStatus);
  });

  it('returns a stable not-found result', () => {
    const result = verifyBooking(createSeed(), {
      bookingId: 'BK-NOT-FOUND',
      operatingStoreId: 'TL1001',
    });

    expect(result).toEqual({
      ok: false,
      code: 'not-found',
      message: '未找到预约记录',
    });
  });
});

describe('profit-sharing transitions', () => {
  it('advances only along an allowed path and records the timeline', () => {
    const state = createSeed();
    const record = state.profitSharingRecords.find((item) => item.status === 'pending');
    const timelineLength = record.timeline.length;

    advanceSharing(state, record.id, 'processing');

    expect(record.status).toBe('processing');
    expect(record.timeline).toHaveLength(timelineLength + 1);
    expect(record.timeline.at(-1)).toEqual({ status: 'processing', at: state.demoNow });
    expect(() => advanceSharing(state, record.id, 'returned')).toThrow('分账状态不可直接跳转');
    expect(record.status).toBe('processing');
  });

  it('keeps one sharing record through failure and retry', () => {
    const state = createSeed();
    const record = state.profitSharingRecords.find((item) => item.status === 'processing');
    const count = state.profitSharingRecords.length;

    advanceSharing(state, record.id, 'failed');
    advanceSharing(state, record.id, 'processing');

    expect(record.timeline.map((item) => item.status).slice(-2)).toEqual(['failed', 'processing']);
    expect(state.profitSharingRecords).toHaveLength(count);
  });
});
