import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { demoStore } from '../../src/mock/store';
import { rechargeStoredValue } from '../../src/mock/memberTransitions';
import MemberSettlementPanel from '../../src/components/store/MemberSettlementPanel.vue';
import BrandMembers from '../../src/views/brand/Members.vue';
import BrandLayout from '../../src/layouts/BrandLayout.vue';
import StoreBookings from '../../src/views/store/Bookings.vue';
import StoreVerify from '../../src/views/store/Verify.vue';

const elementStubs = {
  AdminStatusTag: true,
  'el-button': { template: '<button @click="$emit(\'click\')"><slot /></button>' },
  'el-drawer': { template: '<div><slot /></div>' },
  'el-table': { template: '<div><slot /></div>' },
  'el-table-column': true,
};

function seedVerifiedLiBooking() {
  let booking;
  demoStore.commit((draft) => {
    rechargeStoredValue(draft, { customerId: 'CUS1004', paymentAttemptId: 'STORE-PAGE-RECHARGE-1001' });
    booking = {
      id: 'BK-STORE-MEMBER-1001',
      customerId: 'CUS1004',
      storeId: 'TL1001',
      serviceId: 'SV1030',
      contactName: '李四',
      phone: '138****4812',
      status: 'verified',
      verifiedAt: draft.demoNow,
    };
    draft.bookings.unshift(booking);
  });
  return booking;
}

describe('store member settlement panel', () => {
  beforeEach(() => demoStore.reset());

  it('previews and completes principal settlement for a verified booking', async () => {
    const booking = seedVerifiedLiBooking();
    const wrapper = shallowMount(MemberSettlementPanel, {
      propsData: { bookingId: booking.id, storeId: 'TL1001' },
      stubs: elementStubs,
      mocks: { $confirm: vi.fn().mockResolvedValue(), $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.text()).toContain('项目结算');
    expect(wrapper.text()).toContain('储值本金');
    expect(wrapper.text()).toContain('¥646.80');
    await wrapper.vm.confirmSettlement();
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.booking.memberSettlement).toMatchObject({ paymentSource: 'principal', principalCents: 64680 });
    expect(wrapper.text()).toContain('结算完成');
  });

  it('does not offer settlement before verification', () => {
    const booking = seedVerifiedLiBooking();
    demoStore.commit((draft) => {
      draft.bookings.find((item) => item.id === booking.id).status = 'pending';
    });
    const wrapper = shallowMount(MemberSettlementPanel, {
      propsData: { bookingId: booking.id, storeId: 'TL1001' },
      stubs: elementStubs,
    });

    expect(wrapper.text()).toContain('核销后才能结算');
    expect(wrapper.find('[data-testid="confirm-settlement"]').exists()).toBe(false);
  });

  it('opens the same settlement component from booking details and verification result', async () => {
    const booking = seedVerifiedLiBooking();
    const router = createRouter();
    await router.push('/store/bookings');
    const bookings = shallowMount(StoreBookings, {
      router,
      stubs: { ...elementStubs, MemberSettlementPanel: true },
    });
    bookings.vm.openDetail(booking);
    await bookings.vm.$nextTick();
    expect(bookings.findComponent(MemberSettlementPanel).props('bookingId')).toBe(booking.id);

    await router.push('/store/verify');
    const verify = shallowMount(StoreVerify, {
      router,
      stubs: { ...elementStubs, MemberSettlementPanel: true, QrScanner: true },
    });
    await verify.setData({ result: { ok: true, code: 'verified', message: '预约状态已更新为已核销', booking } });
    expect(verify.findComponent(MemberSettlementPanel).props('bookingId')).toBe(booking.id);
  });
});

describe('brand membership operations', () => {
  beforeEach(() => demoStore.reset());

  it('registers the membership operations route and navigation entry', () => {
    const route = createRouter().match('/brand/members');
    const layout = shallowMount(BrandLayout, { stubs: { AdminShell: true } });
    const menu = layout.findComponent({ name: 'AdminShell' }).props('menuItems');

    expect(route.meta.role).toBe('brand');
    expect(menu.map((item) => item.label)).toContain('会员运营');
  });

  it('shows five operational views and customer drilldown data', async () => {
    const wrapper = shallowMount(BrandMembers, {
      stubs: elementStubs,
      mocks: { $confirm: vi.fn().mockResolvedValue(), $message: { success: vi.fn(), info: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.vm.tabs.map((item) => item.label)).toEqual(['会员客户', '开卡储值', '会员消费', '补贴计划', '发放记录']);
    wrapper.vm.openCustomer('CUS1001');
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.selectedProfile.customer.name).toBe('陈女士');
    expect(wrapper.vm.selectedProfile.benefits).toHaveLength(7);
    expect(wrapper.text()).toContain('执行本周补贴');
  });

  it('executes one subsidy batch and exposes the same customer balance', async () => {
    const wrapper = shallowMount(BrandMembers, {
      stubs: elementStubs,
      mocks: { $confirm: vi.fn().mockResolvedValue(), $message: { success: vi.fn(), info: vi.fn(), error: vi.fn() } },
    });
    const before = wrapper.vm.overview.customers.find((item) => item.customer.id === 'CUS1001').account.subsidyBalanceCents;

    await wrapper.vm.executeWeeklySubsidy();
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.overview.customers.find((item) => item.customer.id === 'CUS1001').account.subsidyBalanceCents).toBe(before + 5100);
    expect(wrapper.vm.overview.subsidyRuns[0].periodKey).toBe('2026-W31');
  });
});
