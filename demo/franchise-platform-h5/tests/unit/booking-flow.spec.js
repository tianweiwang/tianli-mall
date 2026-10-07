import { shallowMount } from '@vue/test-utils';
import { createSeed } from '../../src/mock/seed';
import { createRouter } from '../../src/router';
import BookingCreate from '../../src/views/customer/BookingCreate.vue';
import { demoStore } from '../../src/mock/store';
import { cancelBooking, createBooking } from '../../src/mock/transitions';

describe('consumer booking flow', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it('holds capacity without creating an order and releases it once', () => {
    const state = createSeed();
    const slot = state.slotInventory.find((item) => item.id === 'SL1001');
    const beforeBooked = slot.booked;
    const beforeOrders = state.orders.length;
    const booking = createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    });

    expect(slot.booked).toBe(beforeBooked + 1);
    expect(state.orders).toHaveLength(beforeOrders);
    cancelBooking(state, booking.id);
    cancelBooking(state, booking.id);
    expect(slot.booked).toBe(beforeBooked);
  });

  it('rejects a full slot and keeps historical store ownership', () => {
    const state = createSeed();
    const slot = state.slotInventory.find((item) => item.id === 'SL1001');
    slot.booked = slot.capacity;
    expect(() => createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    })).toThrow('当前时段已满');

    slot.booked = 1;
    const booking = createBooking(state, {
      slotId: 'SL1001',
      contactName: '陈女士',
      phone: '138****2046',
    });
    state.activeStoreId = 'TL1002';
    expect(booking.storeId).toBe('TL1001');
  });

  it('only renders slots owned by the active store', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/booking');
    const wrapper = shallowMount(BookingCreate, {
      router,
      stubs: {
        CalendarDays: true,
        Check: true,
        Clock3: true,
      },
    });

    expect(wrapper.find('[data-testid="booking-slot-SLT1001"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="booking-slot-SLT2001"]').exists()).toBe(false);
  });

  it('preselects a supported reassessment service and retains the source report', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/booking?serviceId=SV1001&sourceAssessmentId=ASR202607301002');
    const wrapper = shallowMount(BookingCreate, {
      router,
      mocks: { $message: { error: vi.fn() } },
      stubs: { Activity: true, CalendarDays: true, Check: true, Clock3: true, RefreshCw: true, UserRound: true },
    });

    expect(wrapper.vm.selectedServiceId).toBe('SV1001');
    expect(wrapper.text()).toContain('复评预约');
    wrapper.vm.submitBooking();

    expect(demoStore.state.data.bookings[0]).toMatchObject({
      sourceAssessmentId: 'ASR202607301002',
      bookingType: 'reassessment',
    });
  });

  it('ignores an unsupported service query', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/booking?serviceId=SV-NOT-FOUND');
    const wrapper = shallowMount(BookingCreate, { router });

    expect(wrapper.vm.selectedServiceId).toBe('SV1001');
  });

  it('only offers active services assigned to the current store', async () => {
    demoStore.commit((draft) => {
      draft.services.find((item) => item.id === 'SV1001').status = 'inactive';
      draft.services.find((item) => item.id === 'SV1002').storeIds = ['TL1002'];
    });
    const router = createRouter();
    await router.push('/c/store/TL1001/booking?serviceId=SV1001');
    const wrapper = shallowMount(BookingCreate, { router });

    expect(wrapper.vm.services.map((item) => item.id)).not.toContain('SV1001');
    expect(wrapper.vm.services.map((item) => item.id)).not.toContain('SV1002');
    expect(wrapper.vm.selectedServiceId).toBe(wrapper.vm.services[0].id);
  });
});
