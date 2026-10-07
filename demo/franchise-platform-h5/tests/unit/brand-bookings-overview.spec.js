import { brandBookingOperations } from '../../src/mock/selectors';
import { createSeed, DEMO_VERSION } from '../../src/mock/seed';

function createBookingState() {
  return {
    demoNow: '2026-08-15T09:30:00+08:00',
    stores: [
      { id: 'S1', name: '中心店', status: 'active', openHours: '09:00-21:00' },
      { id: 'S2', name: '滨河店', status: 'active', openHours: '10:00-20:00' },
      { id: 'S3', name: '北城店', status: 'disabled', openHours: '09:00-18:00' },
    ],
    services: [
      { id: 'A', name: '体态评估' },
      { id: 'B', name: '脊柱舒缓' },
    ],
    slotInventory: [
      { id: 'SL1', storeId: 'S1', serviceId: 'A', date: '2026-08-15', start: '10:00', end: '11:00', booked: 3, capacity: 4 },
      { id: 'SL2', storeId: 'S1', serviceId: 'B', date: '2026-08-15', start: '10:00', end: '11:00', booked: 1, capacity: 2 },
      { id: 'SL3', storeId: 'S1', serviceId: 'A', date: '2026-08-15', start: '12:00', end: '13:00', booked: 1, capacity: 2 },
      { id: 'SL4', storeId: 'S2', serviceId: 'A', date: '2026-08-15', start: '09:00', end: '10:00', booked: 2, capacity: 2 },
      { id: 'SL5', storeId: 'S2', serviceId: 'B', date: '2026-08-15', start: '11:00', end: '12:00', booked: 0, capacity: 2 },
    ],
    bookings: [
      { id: 'BK1', storeId: 'S1', serviceId: 'A', date: '2026-08-15', start: '10:00', end: '11:00', status: 'pending' },
      { id: 'BK2', storeId: 'S1', serviceId: 'A', date: '2026-08-15', start: '12:00', end: '13:00', status: 'verified' },
      { id: 'BK3', storeId: 'S2', serviceId: 'B', date: '2026-08-15', start: '11:00', end: '12:00', status: 'pending' },
      { id: 'BK4', storeId: 'S2', serviceId: 'B', date: '2026-08-15', start: '11:00', end: '12:00', status: 'expired' },
    ],
  };
}

describe('brand booking operations', () => {
  it('uses demoNow by default and aggregates sorted time columns across services', () => {
    const result = brandBookingOperations(createBookingState());

    expect(result.date).toBe('2026-08-15');
    expect(result.columns).toEqual([
      { key: '09:00-10:00', label: '09:00-10:00', start: '09:00', end: '10:00' },
      { key: '10:00-11:00', label: '10:00-11:00', start: '10:00', end: '11:00' },
      { key: '11:00-12:00', label: '11:00-12:00', start: '11:00', end: '12:00' },
      { key: '12:00-13:00', label: '12:00-13:00', start: '12:00', end: '13:00' },
    ]);
    expect(result.rows.map((row) => row.storeId)).toEqual(['S1', 'S2', 'S3']);
    expect(result.rows[2].cells).toEqual(expect.arrayContaining([
      expect.objectContaining({ tone: 'off', selectable: false }),
    ]));
    expect(result.rows[2].cells.every((cell) => cell.tone === 'off')).toBe(true);
    expect(result.rows[2].cells.every((cell) => !cell.selectable)).toBe(true);

    expect(result.rows[0].cells[1]).toMatchObject({
      key: 'S1:10:00-11:00',
      booked: 4,
      capacity: 6,
      serviceLabel: '2 个服务',
      tone: 'busy',
      selectable: true,
      testId: 'booking-heat-cell-S1-10:00-11:00',
    });
  });

  it('reports booking metrics and attention from the same filtered scope', () => {
    const result = brandBookingOperations(createBookingState());

    expect(result.metrics).toEqual({
      bookingCount: 4,
      activeStoreCount: 2,
      booked: 7,
      capacity: 12,
      occupancyRate: 58,
      pendingCount: 2,
      nextPendingTime: '10:00',
      fullSlotCount: 1,
      expiredCount: 1,
      attentionCount: 2,
    });
    expect(result.attention).toEqual([
      {
        type: 'full-slot',
        key: 'S2:09:00-10:00',
        label: '滨河店 09:00-10:00 已满',
        cell: expect.objectContaining({ key: 'S2:09:00-10:00' }),
      },
      { type: 'expired', key: 'expired', label: '1 条预约已过期', count: 1 },
    ]);
  });

  it('filters inventory and bookings by service and store without using booking status as input', () => {
    const result = brandBookingOperations(createBookingState(), { serviceId: 'A', storeId: 'S1' });

    expect(result.columns.map((column) => column.key)).toEqual(['10:00-11:00', '12:00-13:00']);
    expect(result.rows).toHaveLength(1);
    expect(result.rows[0]).toMatchObject({
      storeId: 'S1',
      bookingCount: 2,
      arrivalRate: 50,
    });
    expect(result.rows[0].cells[0]).toMatchObject({
      booked: 3,
      capacity: 4,
      serviceLabel: '体态评估',
      tone: 'busy',
    });
    expect(result.metrics).toMatchObject({ bookingCount: 2, capacity: 6 });
  });

  it('keeps in-scope store rows and booking status metrics when no slots match', () => {
    const result = brandBookingOperations(createBookingState(), { date: '2026-08-16' });
    const expiredOnly = {
      ...createBookingState(),
      slotInventory: [],
      bookings: [{ id: 'BK5', storeId: 'S1', serviceId: 'A', date: '2026-08-16', start: '10:00', end: '11:00', status: 'expired' }],
    };

    expect(result.columns).toEqual([]);
    expect(result.rows.map((row) => row.storeId)).toEqual(['S1', 'S2', 'S3']);
    expect(result.metrics).toMatchObject({ bookingCount: 0, capacity: 0, occupancyRate: 0 });
    expect(brandBookingOperations(expiredOnly, { date: '2026-08-16' })).toMatchObject({
      columns: [],
      rows: expect.arrayContaining([expect.objectContaining({ storeId: 'S1' })]),
      metrics: expect.objectContaining({ bookingCount: 1, expiredCount: 1, capacity: 0 }),
      attention: [{ type: 'expired', key: 'expired', label: '1 条预约已过期', count: 1 }],
    });
    expect(brandBookingOperations(createBookingState(), { storeId: 'S3' }).rows).toEqual([
      expect.objectContaining({ storeId: 'S3', cells: [] }),
    ]);
  });

  it('treats over-capacity cells as full and attention-worthy', () => {
    const state = createBookingState();
    state.slotInventory[4] = { ...state.slotInventory[4], booked: 3, capacity: 2 };

    const result = brandBookingOperations(state);
    const cell = result.rows[1].cells[2];

    expect(cell).toMatchObject({ tone: 'full', selectable: true });
    expect(result.metrics.fullSlotCount).toBe(2);
    expect(result.attention.filter((item) => item.type === 'full-slot')).toHaveLength(2);
  });

  it('seeds a repeatable current-day overview without deleting historical records', () => {
    const state = createSeed();
    const result = brandBookingOperations(state);

    expect(DEMO_VERSION).toBe(11);
    expect(state.bookings).toHaveLength(15);
    expect(state.bookings.some((item) => item.id === 'BK202607302001')).toBe(true);
    expect(result.date).toBe('2026-07-30');
    expect(result.metrics).toMatchObject({
      bookingCount: 10,
      booked: 9,
      capacity: 14,
      pendingCount: 6,
      fullSlotCount: 2,
      expiredCount: 1,
      attentionCount: 3,
    });
    state.slotInventory.filter((slot) => slot.date === result.date).forEach((slot) => {
      const occupied = state.bookings.filter((booking) => (
        booking.slotId === slot.id && !booking.released
      )).length;
      expect(slot.booked).toBe(occupied);
    });
    expect(state.bookings.find((item) => item.id === 'BK202607302010')).toMatchObject({
      status: 'expired',
      released: true,
    });
  });
});
