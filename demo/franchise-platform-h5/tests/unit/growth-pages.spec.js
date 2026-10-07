import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { demoStore } from '../../src/mock/store';
import Points from '../../src/views/customer/Points.vue';
import Profile from '../../src/views/customer/Profile.vue';
import Referral from '../../src/views/customer/Referral.vue';
import Share from '../../src/views/customer/Share.vue';
import ShareEntry from '../../src/views/customer/ShareEntry.vue';
import Distribution from '../../src/views/customer/Distribution.vue';
import StoreWithdrawals from '../../src/views/store/Withdrawals.vue';
import BrandDistribution from '../../src/views/brand/Distribution.vue';
import BrandWithdrawals from '../../src/views/brand/Withdrawals.vue';
import BrandOrders from '../../src/views/brand/Orders.vue';
import BrandProfitSharingDetail from '../../src/views/brand/ProfitSharingDetail.vue';
import {
  acceptShareAttribution,
  createPaidOrder,
  recordShare,
} from '../../src/mock/transitions';

const elementStubs = {
  AdminMetricStrip: true,
  AdminStatusTag: true,
  'el-button': true,
  'el-date-picker': true,
  'el-drawer': { template: '<div><slot /></div>' },
  'el-input': true,
  'el-option': true,
  'el-select': true,
  'el-table': true,
  'el-table-column': true,
};

function createAttributedOrder(draft) {
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
  draft.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
  return createPaidOrder(draft, {
    addressId: 'ADDR1',
    delivery: 'express',
    paymentAttemptId: 'brand-attribution-001',
  });
}

describe('growth module routes', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it.each([
    ['/c/store/TL1001/points', 'CustomerPoints'],
    ['/c/store/TL1001/referral', 'CustomerReferral'],
    ['/c/store/TL1001/share', 'CustomerShare'],
    ['/c/store/TL1001/share-entry/TL-SH-1004', 'CustomerShareEntry'],
    ['/c/store/TL1001/distribution', 'CustomerDistribution'],
    ['/store/withdrawals', 'StoreWithdrawals'],
    ['/brand/distribution', 'BrandDistribution'],
    ['/brand/withdrawals', 'BrandWithdrawals'],
  ])('loads %s as a concrete page', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe(componentName);
  });
});

describe('consumer growth pages', () => {
  beforeEach(() => demoStore.reset());

  it('shows the points balance, rules and redemption items', () => {
    const wrapper = shallowMount(Points, { mocks: { $message: { success: vi.fn(), error: vi.fn() } } });

    expect(wrapper.find('[data-testid="points-balance"]').text()).toBe('1680');
    expect(wrapper.text()).toContain('积分规则');
    expect(wrapper.text()).toContain('10 元无门槛券');
    expect(wrapper.text()).toContain('积分明细');
  });

  it('shows the referral code, reward rule and invite records', () => {
    const wrapper = shallowMount(Referral, {
      stubs: { QrCode: true },
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.find('[data-testid="referral-code"]').text()).toBe('TL88C6');
    expect(wrapper.text()).toContain('推荐有礼');
    expect(wrapper.text()).toContain('张女士');
    expect(wrapper.text()).toContain('已奖励');
  });

  it('shows share templates and the recorded share history', () => {
    const wrapper = shallowMount(Share, {
      stubs: { QrCode: true },
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.text()).toContain('累计分享');
    expect(wrapper.text()).toContain('门店首页');
    expect(wrapper.text()).toContain('微信好友');
    expect(wrapper.vm.overview.visits).toBe(48);
  });

  it('generates a tokenized campaign and a simulated friend link', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const router = createRouter();
    await router.push('/c/store/TL1001/share');
    const wrapper = shallowMount(Share, {
      router,
      stubs: { QrCode: true },
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });

    wrapper.vm.simulateShare();
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.generatedShare.token).toMatch(/^TL-SH-/);
    expect(wrapper.vm.generatedPath).toContain(`/share-entry/${wrapper.vm.generatedShare.token}`);
    expect(wrapper.findComponent({ name: 'RouterLink' }).props('to'))
      .toBe(wrapper.vm.generatedPath);
  });

  it('accepts a valid recommendation from the friend landing page', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.commit((draft) => recordShare(draft, {
      channel: '二维码',
      content: '门店首页',
      contentType: 'store',
      contentId: 'TL1001',
      storeId: 'TL1001',
    }));
    const token = demoStore.state.data.shareRecords[0].token;
    const router = createRouter();
    await router.push(`/c/store/TL1001/share-entry/${token}`);
    const wrapper = shallowMount(ShareEntry, {
      router,
      stubs: { ArrowRight: true, Gift: true, Store: true, UserRound: true },
    });

    await wrapper.setData({ customerName: '赵女士', phone: '138****6008' });
    await wrapper.vm.accept();

    expect(demoStore.state.data.distributionBindings).toHaveLength(1);
    expect(router.currentRoute.path).toBe('/c/store/TL1001');
  });

  it('shows the friend identity and recommendation relationship in profile', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/profile');
    demoStore.commit((draft) => {
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
    });
    const wrapper = shallowMount(Profile, { router });

    expect(wrapper.text()).toContain('赵女士');
    expect(wrapper.text()).toContain('推荐人');
    expect(wrapper.text()).toContain('陈女士');
    expect(wrapper.text()).toContain('待完成首单');
    expect(wrapper.text()).not.toContain('分享中心');
    expect(wrapper.text()).not.toContain('分销中心');
  });

  it('shows distributor profile, metrics and commission records', () => {
    const wrapper = shallowMount(Distribution, {
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.find('[data-testid="distributor-name"]').text()).toContain('陈女士');
    expect(wrapper.find('[data-testid="distributor-withdrawable"]').text()).toBe('¥127.40');
    expect(wrapper.text()).toContain('佣金明细');
    expect(wrapper.text()).toContain('ORD202607301001');
  });

  it('opens the existing referral details from the team summary', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const router = createRouter();
    await router.push('/c/store/TL1001/distribution');
    const wrapper = shallowMount(Distribution, {
      router,
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });
    const teamLink = wrapper.find('.team-card');

    expect(teamLink.exists()).toBe(true);
    expect(wrapper.findComponent({ name: 'RouterLink' }).props('to'))
      .toBe('/c/store/TL1001/referral');
  });
});

describe('store withdrawal page', () => {
  beforeEach(() => demoStore.reset());

  it('shows only the active store withdrawal data', () => {
    const wrapper = shallowMount(StoreWithdrawals, {
      stubs: elementStubs,
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.vm.overview).toMatchObject({
      availableCents: 41274,
      pendingCents: 10000,
      completedCents: 5000,
    });
    expect(wrapper.vm.overview.records.every((item) => item.storeId === 'TL1001')).toBe(true);
    expect(wrapper.text()).toContain('申请提现（演示）');
  });
});

describe('brand growth pages', () => {
  beforeEach(() => demoStore.reset());

  it('shows distributor and sharing overview', () => {
    const wrapper = shallowMount(BrandDistribution, { stubs: elementStubs });

    expect(wrapper.vm.overview).toMatchObject({
      distributorCount: 1,
      totalCommissionCents: 32835,
      pendingWithdrawalCount: 2,
      shareOrders: 3,
    });
    expect(wrapper.text()).toContain('分销员档案');
    expect(wrapper.text()).toContain('分享回流');
  });

  it('shows attributed order source, commission rate and brand growth metrics', async () => {
    demoStore.commit((draft) => createAttributedOrder(draft));
    const order = demoStore.state.data.orders[0];
    const orders = shallowMount(BrandOrders, { stubs: elementStubs });

    orders.vm.openDetail(order);
    await orders.vm.$nextTick();
    expect(orders.text()).toContain('分享归因');
    expect(orders.text()).toContain('陈女士');

    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const router = createRouter();
    const sharing = demoStore.state.data.profitSharingRecords.find(
      (item) => item.orderId === order.id,
    );
    await router.push(`/brand/sharing/${sharing.id}`);
    const detail = shallowMount(BrandProfitSharingDetail, {
      router,
      stubs: elementStubs,
    });
    expect(detail.text()).toContain('8%');
    expect(detail.text()).toContain('¥39.84');
    expect(detail.text()).toContain('待结算');

    const distribution = shallowMount(BrandDistribution, { stubs: elementStubs });
    expect(distribution.vm.metrics.map((item) => item.label)).toEqual([
      '分享绑定',
      '归因订单',
      '待结算佣金',
      '已结算佣金',
    ]);
    expect(distribution.text()).not.toContain('绑定  ·');
  });

  it('filters withdrawals by role and status', async () => {
    const wrapper = shallowMount(BrandWithdrawals, {
      stubs: elementStubs,
      mocks: { $message: { success: vi.fn(), error: vi.fn() }, $confirm: vi.fn().mockResolvedValue() },
    });

    expect(wrapper.vm.visibleRecords).toHaveLength(3);
    await wrapper.setData({ role: 'store' });
    expect(wrapper.vm.visibleRecords).toHaveLength(2);
    await wrapper.setData({ status: 'completed' });
    expect(wrapper.vm.visibleRecords).toHaveLength(1);
    expect(wrapper.vm.visibleRecords[0].id).toBe('WD202607301003');
  });
});
