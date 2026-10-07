import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import AdminShell from '../../src/components/admin/AdminShell.vue';
import DemoNavigator from '../../src/components/demo/DemoNavigator.vue';
import BrandLayout from '../../src/layouts/BrandLayout.vue';
import CustomerLayout from '../../src/layouts/CustomerLayout.vue';
import StoreLayout from '../../src/layouts/StoreLayout.vue';
import { createSeed } from '../../src/mock/seed';
import { demoStore } from '../../src/mock/store';
import { acceptShareAttribution, recordShare } from '../../src/mock/transitions';

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));

const PopoverStub = {
  template: '<div><slot /><slot name="reference" /></div>',
};

const TooltipStub = {
  template: '<span><slot /></span>',
};

async function mountNavigator(
  routePath = '/verify',
  confirm = vi.fn().mockResolvedValue(),
) {
  const router = createRouter();
  await router.push(routePath);
  const pushSpy = vi.spyOn(router, 'push');
  const message = {
    success: vi.fn(),
  };
  const wrapper = shallowMount(DemoNavigator, {
    router,
    mocks: {
      $confirm: confirm,
      $message: message,
    },
    stubs: {
      'el-popover': PopoverStub,
      'el-tooltip': TooltipStub,
    },
  });

  return { confirm, message, pushSpy, router, wrapper };
}

async function mountCustomer(routePath, confirm = vi.fn().mockResolvedValue()) {
  const router = createRouter();
  await router.push(routePath);
  const wrapper = shallowMount(CustomerLayout, {
    router,
    mocks: {
      $confirm: confirm,
    },
    stubs: {
      AdminShell: true,
      BottomNav: true,
      CustomerHeader: true,
      DemoNavigator: true,
      RouterView: true,
    },
  });

  return { confirm, router, wrapper };
}

describe('three-role router', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it('defaults to the consumer store home', async () => {
    const router = createRouter();

    await router.push('/');

    expect(router.currentRoute.fullPath).toBe('/c/store/TL1001');
    expect(router.currentRoute.meta.role).toBe('customer');
  });

  it('loads the QR payload landing route as a concrete page', async () => {
    const route = createRouter().match('/verify?p=payload&c=checksum');
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe('VerifyPayload');
  });

  it.each([
    ['/c/store/TL1001', 'customer'],
    ['/c/store/TL1001/category', 'customer'],
    ['/c/store/TL1001/services', 'customer'],
    ['/c/store/TL1001/services/SV1004', 'customer'],
    ['/c/store/TL1001/product/3', 'customer'],
    ['/c/store/TL1001/cart', 'customer'],
    ['/c/store/TL1001/checkout', 'customer'],
    ['/c/store/TL1001/payment', 'customer'],
    ['/c/store/TL1001/payment-success/ORD202607301001', 'customer'],
    ['/c/store/TL1001/orders', 'customer'],
    ['/c/store/TL1001/orders/ORD202607301001', 'customer'],
    ['/c/store/TL1001/booking', 'customer'],
    ['/c/store/TL1001/booking-success/BK202607302001', 'customer'],
    ['/c/store/TL1001/bookings', 'customer'],
    ['/c/store/TL1001/bookings/BK202607302001', 'customer'],
    ['/c/store/TL1001/assessments', 'customer'],
    ['/c/store/TL1001/assessments/ASR202607301002', 'customer'],
    ['/c/store/TL1001/live', 'customer'],
    ['/c/store/TL1001/live/LR1001', 'customer'],
    ['/c/store/TL1001/points', 'customer'],
    ['/c/store/TL1001/membership', 'customer'],
    ['/c/store/TL1001/assets', 'customer'],
    ['/c/store/TL1001/referral', 'customer'],
    ['/c/store/TL1001/share', 'customer'],
    ['/c/store/TL1001/distribution', 'customer'],
    ['/c/store/TL1001/profile', 'customer'],
    ['/store/dashboard', 'store'],
    ['/store/orders', 'store'],
    ['/store/bookings', 'store'],
    ['/store/assessments', 'store'],
    ['/store/assessments/AST202607301003', 'store'],
    ['/store/verify', 'store'],
    ['/store/live', 'store'],
    ['/store/products', 'store'],
    ['/store/performance', 'store'],
    ['/store/settlements', 'store'],
    ['/store/withdrawals', 'store'],
    ['/store/profile', 'store'],
    ['/brand/dashboard', 'brand'],
    ['/brand/stores', 'brand'],
    ['/brand/stores/TL1001', 'brand'],
    ['/brand/onboarding', 'brand'],
    ['/brand/products', 'brand'],
    ['/brand/orders', 'brand'],
    ['/brand/bookings', 'brand'],
    ['/brand/members', 'brand'],
    ['/brand/assessments', 'brand'],
    ['/brand/devices', 'brand'],
    ['/brand/live', 'brand'],
    ['/brand/sharing', 'brand'],
    ['/brand/sharing/PS202607303001', 'brand'],
    ['/brand/distribution', 'brand'],
    ['/brand/withdrawals', 'brand'],
    ['/brand/analytics', 'brand'],
    ['/verify?p=payload&c=checksum', 'verify'],
  ])('resolves %s for the %s surface', (path, role) => {
    const route = createRouter().match(path);

    expect(route.matched.length).toBeGreaterThan(0);
    expect(route.meta.role).toBe(role);
  });

  it('keeps the store and brand menus role-specific', () => {
    const storeWrapper = shallowMount(StoreLayout, {
      stubs: { AdminShell: true },
    });
    const brandWrapper = shallowMount(BrandLayout, {
      stubs: { AdminShell: true },
    });
    const storeMenu = storeWrapper.findComponent(AdminShell).props('menuItems');
    const brandMenu = brandWrapper.findComponent(AdminShell).props('menuItems');

    expect(storeMenu.map((item) => item.label)).toEqual([
      '门店工作台',
      '订单管理',
      '预约管理',
      '检测中心',
      '扫码核销',
      '直播管理',
      '服务与商品',
      '门店业绩',
      '结算明细',
      '提现管理',
      '门店资料',
    ]);
    expect(brandMenu.map((item) => item.label)).toEqual([
      '平台总览',
      '加盟门店',
      '区域代理',
      '进件审核',
      '服务与商品',
      '全平台订单',
      '全平台预约',
      '会员运营',
      '检测运营',
      '设备管理',
      '直播运营',
      '分账流水',
      '分销管理',
      '提现审核',
      '经营分析',
    ]);
    expect(storeMenu.some((item) => item.path.startsWith('/brand'))).toBe(false);
    expect(brandMenu.some((item) => item.path.startsWith('/store'))).toBe(false);
  });

  it('mounts demo navigation in the customer and shared admin shells', async () => {
    const { router, wrapper: customerWrapper } = await mountCustomer('/c/store/TL1001');
    const adminWrapper = shallowMount(AdminShell, {
      router,
      propsData: {
        homePath: '/store/dashboard',
        menuItems: [],
        roleLabel: '加盟门店',
        userLabel: '天俪锦江店',
      },
      stubs: {
        DemoNavigator: true,
        RouterLink: true,
        RouterView: true,
      },
    });

    expect(customerWrapper.findComponent(DemoNavigator).exists()).toBe(true);
    expect(adminWrapper.findComponent(DemoNavigator).exists()).toBe(true);
  });

  it.each([
    [0, 'customer', '/c/store/TL1001'],
    [1, 'store', '/store/dashboard'],
    [2, 'brand', '/brand/dashboard'],
  ])('switches from the navigator command at index %i to %s', async (index, role, path) => {
    const { pushSpy, wrapper } = await mountNavigator();

    await wrapper.findAll('.demo-role-menu button').at(index).trigger('click');
    await flushPromises();

    expect(demoStore.state.data.activeRole).toBe(role);
    expect(pushSpy).toHaveBeenCalledWith(path);
  });

  it('resets only after confirmation and returns to the default consumer store', async () => {
    demoStore.commit((draft) => {
      draft.activeRole = 'brand';
      draft.activeStoreId = 'TL1002';
      draft.cart.push({ productId: 3, quantity: 1, spec: '盒装' });
    });
    const { confirm, message, pushSpy, wrapper } = await mountNavigator();

    await wrapper.find('.demo-role-menu__reset').trigger('click');
    await flushPromises();

    expect(confirm).toHaveBeenCalledTimes(1);
    expect(demoStore.snapshot()).toEqual(createSeed());
    expect(pushSpy).toHaveBeenCalledWith('/c/store/TL1001');
    expect(message.success).toHaveBeenCalledWith('演示数据已重置');
  });

  it('switches between the distributor and the bound friend', async () => {
    let friendId;
    demoStore.commit((draft) => {
      const share = recordShare(draft, {
        channel: '微信好友',
        content: '门店首页',
        contentType: 'store',
        contentId: 'TL1001',
        storeId: 'TL1001',
      });
      friendId = acceptShareAttribution(draft, {
        token: share.token,
        customerName: '李四',
        phone: '138****6008',
      }).customerId;
    });
    const bindingId = demoStore.state.data.activeDistributionBindingId;
    const { wrapper } = await mountNavigator('/c/store/TL1001/profile');
    const customerButtons = wrapper.findAll('.demo-customer-switcher button');

    expect(customerButtons).toHaveLength(2);
    expect(wrapper.text()).toContain('陈女士');
    expect(wrapper.text()).toContain('李四');

    await customerButtons.wrappers.find((button) => button.text().includes('陈女士')).trigger('click');
    expect(demoStore.state.data.activeCustomerId).toBe('CUS1001');
    expect(demoStore.state.data.activeCustomerName).toBe('陈女士');
    expect(demoStore.state.data.activeDistributionBindingId).toBeNull();

    await customerButtons.wrappers.find((button) => button.text().includes('李四')).trigger('click');
    expect(demoStore.state.data.activeCustomerId).toBe(friendId);
    expect(demoStore.state.data.activeCustomerName).toBe('李四');
    expect(demoStore.state.data.activeDistributionBindingId).toBe(bindingId);
  });

  it('leaves demo data and route unchanged when reset is cancelled', async () => {
    const before = demoStore.snapshot();
    const confirm = vi.fn().mockRejectedValue(new Error('cancelled'));
    const { pushSpy, wrapper } = await mountNavigator('/store/dashboard', confirm);

    await wrapper.find('.demo-role-menu__reset').trigger('click');
    await flushPromises();

    expect(demoStore.snapshot()).toEqual(before);
    expect(pushSpy).not.toHaveBeenCalled();
  });

  it('records the customer role when entering the current active store', async () => {
    demoStore.commit((draft) => {
      draft.activeRole = 'brand';
    });
    await mountCustomer('/c/store/TL1001');
    await flushPromises();

    expect(demoStore.state.data.activeRole).toBe('customer');
    expect(demoStore.state.data.activeStoreId).toBe('TL1001');
  });

  it('confirms and clears a non-empty cart when the route changes stores', async () => {
    demoStore.commit((draft) => {
      draft.cart.push({ productId: 3, quantity: 1, spec: '盒装' });
    });
    const { confirm, router } = await mountCustomer('/c/store/TL1001');

    await router.push('/c/store/TL1002');
    await flushPromises();

    expect(confirm).toHaveBeenCalledTimes(1);
    expect(demoStore.state.data.activeStoreId).toBe('TL1002');
    expect(demoStore.state.data.cart).toEqual([]);
  });

  it('shows store selection without changing affiliation for a disabled store', async () => {
    const { wrapper } = await mountCustomer('/c/store/TL1003');

    expect(wrapper.find('.customer-store-unavailable').exists()).toBe(true);
    expect(wrapper.text()).toContain('请选择其他营业中的门店');
    expect(demoStore.state.data.activeStoreId).toBe('TL1001');
  });

  it('keeps the admin sidebar at 220px without a narrower breakpoint override', () => {
    const adminCss = readFileSync(resolve('src/styles/admin.css'), 'utf8');

    expect(adminCss.match(/grid-template-columns:/g)).toHaveLength(1);
    expect(adminCss).toContain('grid-template-columns: 220px minmax(0, 1fr);');
    expect(adminCss).toMatch(/\.admin-main\s*\{[^}]*overflow-x: auto;/s);
  });
});
