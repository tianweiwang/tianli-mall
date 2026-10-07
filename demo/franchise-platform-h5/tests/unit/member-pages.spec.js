import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { demoStore } from '../../src/mock/store';
import { purchaseMembership, rechargeStoredValue } from '../../src/mock/memberTransitions';
import { switchCustomer } from '../../src/mock/transitions';
import Membership from '../../src/views/customer/Membership.vue';
import Assets from '../../src/views/customer/Assets.vue';
import Profile from '../../src/views/customer/Profile.vue';
import ServiceDetail from '../../src/views/customer/ServiceDetail.vue';
import Checkout from '../../src/views/customer/Checkout.vue';
import Payment from '../../src/views/customer/Payment.vue';
import BookingDetail from '../../src/views/customer/BookingDetail.vue';
import BookingCreate from '../../src/views/customer/BookingCreate.vue';
import Points from '../../src/views/customer/Points.vue';
import { awardPoints, redeemPoints } from '../../src/mock/transitions';

async function switchToLi() {
  demoStore.commit((draft) => switchCustomer(draft, 'CUS1004'));
  const router = createRouter();
  await router.push('/c/store/TL1001/profile');
  return router;
}

describe('customer membership pages', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it.each([
    ['/c/store/TL1001/membership', 'CustomerMembership'],
    ['/c/store/TL1001/assets', 'CustomerAssets'],
  ])('loads %s as a concrete page', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();
    expect(component.default.name).toBe(componentName);
  });

  it('lets Li open the member card and recharge with immediate shared balances', async () => {
    const router = await switchToLi();
    await router.push('/c/store/TL1001/membership');
    const wrapper = shallowMount(Membership, {
      router,
      mocks: { $confirm: vi.fn().mockResolvedValue(), $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.text()).toContain('510元会员卡');
    expect(wrapper.text()).toContain('5100元储值方案');
    expect(wrapper.text()).toContain('入会礼品');
    await wrapper.vm.purchase('membership-510');
    await wrapper.vm.purchase('stored-value-5100');
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.profile.account).toMatchObject({ principalBalanceCents: 510000, pointsBalance: 10200 });
    expect(wrapper.vm.profile.benefits).toHaveLength(7);
    expect(wrapper.text()).toContain('已开通');
  });

  it('shows four isolated asset summaries and benefit records', async () => {
    const router = await switchToLi();
    demoStore.commit((draft) => {
      purchaseMembership(draft, { customerId: 'CUS1004', paymentAttemptId: 'PAGE-MEMBER-1001' });
      rechargeStoredValue(draft, { customerId: 'CUS1004', paymentAttemptId: 'PAGE-RECHARGE-1001' });
    });
    await router.push('/c/store/TL1001/assets');
    const wrapper = shallowMount(Assets, { router });

    expect(wrapper.text()).toContain('储值本金');
    expect(wrapper.text()).toContain('赠送积分');
    expect(wrapper.text()).toContain('补贴余额');
    expect(wrapper.text()).toContain('会员权益');
  });

  it('links profile to membership and assets and shows the current customer summary', async () => {
    const router = await switchToLi();
    const wrapper = shallowMount(Profile, { router });
    const links = wrapper.findAllComponents({ name: 'RouterLink' }).wrappers.map((item) => item.props('to'));

    expect(wrapper.text()).toContain('普通客户');
    expect(wrapper.text()).toContain('储值本金');
    expect(links).toContain('/c/store/TL1001/membership');
    expect(links).toContain('/c/store/TL1001/assets');
  });

  it('shows member service pricing and goods pricing before payment', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/services/SV1012');
    const service = shallowMount(ServiceDetail, { router });
    expect(service.text()).toContain('会员本金价');
    expect(service.text()).toContain('¥528.00');

    demoStore.commit((draft) => {
      draft.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    });
    await router.push('/c/store/TL1001/checkout');
    const checkout = shallowMount(Checkout, { router });
    expect(checkout.text()).toContain('会员商品优惠');
    expect(checkout.text()).toContain('¥423.30');
    expect(checkout.text()).toContain('储值本金');
  });

  it('passes the selected principal source into payment', async () => {
    const router = createRouter();
    demoStore.commit((draft) => {
      draft.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    });
    await router.push('/c/store/TL1001/payment?source=principal');
    const wrapper = shallowMount(Payment, {
      router,
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
      stubs: { CheckCircle2: true, LoaderCircle: true, MessageCircleMore: true, ShieldAlert: true },
    });

    expect(wrapper.vm.paymentSource).toBe('principal');
    expect(wrapper.text()).toContain('储值本金支付');
    expect(wrapper.text()).toContain('¥423.30');
  });

  it('explains store settlement and shows its completed snapshot', async () => {
    const router = createRouter();
    demoStore.commit((draft) => {
      draft.bookings.unshift({
        id: 'BK-CUSTOMER-SETTLEMENT', customerId: 'CUS1001', storeId: 'TL1001', serviceId: 'SV1004', date: '2026-07-30', start: '10:00', end: '10:30', contactName: '陈女士', phone: '138****2046', status: 'verified', verifyCode: '810008', verifiedAt: draft.demoNow,
      });
    });
    await router.push('/c/store/TL1001/bookings/BK-CUSTOMER-SETTLEMENT');
    const wrapper = shallowMount(BookingDetail, { router, stubs: { QrCode: true, StatusPill: true } });

    expect(wrapper.text()).toContain('到店结算');
    expect(wrapper.text()).toContain('储值本金享6.6折');
  });

  it('prefills a booking with the active customer identity', async () => {
    const router = await switchToLi();
    await router.push('/c/store/TL1001/booking?serviceId=SV1004');
    const wrapper = shallowMount(BookingCreate, { router });

    expect(wrapper.vm.contactName).toBe('李四');
    expect(wrapper.vm.phone).toBe('138****6008');
  });

  it('styles customer-scoped point credits and debits by signed amount', async () => {
    await switchToLi();
    demoStore.commit((draft) => {
      awardPoints(draft, { title: '到店奖励', points: 100 });
      redeemPoints(draft, { title: '兑换服务权益', points: 40 });
    });
    const wrapper = shallowMount(Points, { mocks: { $message: { success: vi.fn(), error: vi.fn() } } });
    const ledgerAmounts = wrapper.findAll('.points-ledger-item em');

    expect(ledgerAmounts.at(0).classes()).toContain('is-spend');
    expect(ledgerAmounts.at(1).classes()).toContain('is-earn');
  });
});
