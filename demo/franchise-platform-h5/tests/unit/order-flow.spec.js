import { shallowMount } from '@vue/test-utils';
import { createSeed } from '../../src/mock/seed';
import { allOrders, ordersForStore } from '../../src/mock/selectors';
import { createRouter } from '../../src/router';
import Payment from '../../src/views/customer/Payment.vue';
import Checkout from '../../src/views/customer/Checkout.vue';
import PaymentSuccess from '../../src/views/customer/PaymentSuccess.vue';
import OrderDetail from '../../src/views/customer/OrderDetail.vue';
import Orders from '../../src/views/customer/Orders.vue';
import { demoStore } from '../../src/mock/store';
import {
  acceptShareAttribution,
  createPaidOrder,
  recordShare,
} from '../../src/mock/transitions';

function bindRecommendation(draft) {
  const share = recordShare(draft, {
    channel: '微信好友',
    content: '门店首页',
    contentType: 'store',
    contentId: 'TL1001',
    storeId: 'TL1001',
  });
  acceptShareAttribution(draft, {
    token: share.token,
    customerName: '赵女士',
    phone: '138****6008',
  });
}

describe('consumer order flow', () => {
  it('returns the first order for a repeated payment attempt', () => {
    const state = createSeed();
    state.cart = [{ productId: 3, quantity: 2, spec: '盒装' }];
    const input = {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'demo-payment-001',
    };

    const first = createPaidOrder(state, input);
    const orderCount = state.orders.length;
    const sharingCount = state.profitSharingRecords.length;
    const second = createPaidOrder(state, input);

    expect(second.id).toBe(first.id);
    expect(state.orders).toHaveLength(orderCount);
    expect(state.profitSharingRecords).toHaveLength(sharingCount);
    expect(first.paymentAttemptId).toBe(input.paymentAttemptId);
  });

  it('exposes the same paid order to its store and the brand view', () => {
    const state = createSeed();
    state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];

    const order = createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'demo-payment-002',
    });

    expect(ordersForStore(state, 'TL1001').map((item) => item.id)).toContain(order.id);
    expect(allOrders(state).map((item) => item.id)).toContain(order.id);
    expect(order.storeIncomeCents + order.brandCommissionCents).toBe(order.paidCents);
  });

  it('keeps the payment action locked while navigation completes', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
    demoStore.commit((draft) => {
      draft.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    });
    const router = createRouter();
    await router.push('/c/store/TL1001/payment');
    const wrapper = shallowMount(Payment, {
      router,
      stubs: {
        CheckCircle2: true,
        LoaderCircle: true,
        MessageCircleMore: true,
        ShieldAlert: true,
      },
    });

    wrapper.vm.isPaying = true;
    demoStore.commit((draft) => {
      draft.cart = [];
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    expect(wrapper.find('button').text()).toContain('支付处理中');
    expect(wrapper.find('a').exists()).toBe(false);
  });

  it('shows the bound recommendation during checkout and after payment', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
    demoStore.commit((draft) => {
      bindRecommendation(draft);
      draft.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
    });
    const router = createRouter();
    await router.push('/c/store/TL1001/checkout');
    const checkout = shallowMount(Checkout, { router });

    expect(checkout.text()).toContain('推荐关系已绑定');
    expect(checkout.text()).toContain('由 陈女士 推荐');

    demoStore.commit((draft) => createPaidOrder(draft, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'share-payment-feedback-001',
    }));
    const order = demoStore.state.data.orders[0];
    await router.push(`/c/store/TL1001/payment-success/${order.id}`);
    const success = shallowMount(PaymentSuccess, { router, stubs: { Check: true } });

    expect(success.find('[data-testid="attribution-success"]').text())
      .toContain('本单已计入分享归因');
  });

  it('keeps seed orders hidden until the bound friend places an order', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
    demoStore.commit((draft) => bindRecommendation(draft));
    const seedOrderId = demoStore.state.data.orders[0].id;
    const router = createRouter();
    await router.push('/c/store/TL1001/orders');
    const orders = shallowMount(Orders, { router });

    expect(orders.find('.orders-empty').exists()).toBe(true);
    expect(orders.text()).not.toContain(seedOrderId);

    demoStore.commit((draft) => {
      draft.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
      createPaidOrder(draft, {
        addressId: 'ADDR1',
        delivery: 'express',
        paymentAttemptId: 'friend-order-isolation-001',
      });
    });
    await orders.vm.$nextTick();
    const friendOrder = demoStore.state.data.orders[0];

    expect(orders.text()).toContain(friendOrder.id);
    expect(orders.text()).not.toContain(seedOrderId);
  });

  it('does not expose another customer order detail or payment result', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
    demoStore.commit((draft) => bindRecommendation(draft));
    const seedOrderId = demoStore.state.data.orders.find((item) => item.customerId === 'CUS1001')?.id
      || demoStore.state.data.orders[0].id;
    const router = createRouter();
    await router.push(`/c/store/TL1001/orders/${seedOrderId}`);
    const detail = shallowMount(OrderDetail, { router });

    expect(detail.find('.order-detail').exists()).toBe(false);

    await router.push(`/c/store/TL1001/payment-success/${seedOrderId}`);
    const success = shallowMount(PaymentSuccess, { router, stubs: { Check: true } });

    expect(success.find('.payment-success').exists()).toBe(false);
  });
});
