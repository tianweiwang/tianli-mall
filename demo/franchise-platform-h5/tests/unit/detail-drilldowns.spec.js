import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { demoStore } from '../../src/mock/store';
import * as transitions from '../../src/mock/transitions';
import BrandAnalytics from '../../src/views/brand/Analytics.vue';
import BrandDashboard from '../../src/views/brand/Dashboard.vue';
import BrandDevices from '../../src/views/brand/Devices.vue';
import BrandDistribution from '../../src/views/brand/Distribution.vue';
import BrandLive from '../../src/views/brand/Live.vue';
import BrandOrders from '../../src/views/brand/Orders.vue';
import BrandStoreDetail from '../../src/views/brand/StoreDetail.vue';
import BrandWithdrawals from '../../src/views/brand/Withdrawals.vue';
import StoreBookings from '../../src/views/store/Bookings.vue';
import StoreDashboard from '../../src/views/store/Dashboard.vue';
import StoreLive from '../../src/views/store/Live.vue';
import StoreOrders from '../../src/views/store/Orders.vue';
import StoreWithdrawals from '../../src/views/store/Withdrawals.vue';

const stubs = {
  AdminLineChart: true,
  AdminMetricStrip: true,
  AdminStatusTag: true,
  'el-button': true,
  'el-date-picker': true,
  'el-drawer': true,
  'el-input': true,
  'el-option': true,
  'el-select': true,
  'el-table': true,
  'el-table-column': true,
};

function mountPage(component, options = {}) {
  return shallowMount(component, {
    stubs,
    mocks: {
      $confirm: vi.fn().mockResolvedValue(),
      $message: { error: vi.fn(), success: vi.fn() },
    },
    ...options,
  });
}

describe('existing detail route drilldowns', () => {
  beforeEach(() => demoStore.reset());

  it('builds existing store and sharing detail paths from brand summaries', () => {
    const dashboard = mountPage(BrandDashboard);
    const analytics = mountPage(BrandAnalytics);

    expect(dashboard.vm.storeDetailPath({ storeId: 'TL1001' })).toBe('/brand/stores/TL1001');
    expect(analytics.vm.storeDetailPath({ storeId: 'TL1002' })).toBe('/brand/stores/TL1002');
    expect(dashboard.vm.sharingDetailPath({ id: 'PS202607303001' }))
      .toBe('/brand/sharing/PS202607303001');
  });
});

describe('query-driven existing drawers', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it.each([
    [StoreOrders, '/store/orders?recordId=ORD202607301001', 'selectedOrder', 'ORD202607301001'],
    [StoreBookings, '/store/bookings?recordId=BK202607302001', 'selectedBooking', 'BK202607302001'],
    [BrandOrders, '/brand/orders?recordId=ORD202607301001', 'selectedOrder', 'ORD202607301001'],
  ])('opens the requested record in %s', async (component, path, selectedKey, expectedId) => {
    const router = createRouter();
    await router.push(path);
    const wrapper = mountPage(component, { router });

    expect(wrapper.vm.drawerOpen).toBe(true);
    expect(wrapper.vm[selectedKey]?.id).toBe(expectedId);
  });

  it.each([
    [StoreOrders, '/store/orders?recordId=UNKNOWN', 'selectedOrder'],
    [StoreBookings, '/store/bookings?recordId=UNKNOWN', 'selectedBooking'],
    [BrandOrders, '/brand/orders?recordId=UNKNOWN', 'selectedOrder'],
  ])('ignores an invalid requested record in %s', async (component, path, selectedKey) => {
    const router = createRouter();
    await router.push(path);
    const wrapper = mountPage(component, { router });

    expect(wrapper.vm.drawerOpen).toBe(false);
    expect(wrapper.vm[selectedKey]).toBeNull();
  });

  it('builds record query links from dashboard summaries', async () => {
    const router = createRouter();
    await router.push('/store/dashboard');
    const storeDashboard = mountPage(StoreDashboard, { router });
    await router.push('/brand/stores/TL1001');
    const storeDetail = mountPage(BrandStoreDetail, { router });

    expect(storeDashboard.vm.orderDetailPath({ id: 'ORD202607301001' }))
      .toBe('/store/orders?recordId=ORD202607301001');
    expect(storeDashboard.vm.bookingDetailPath({ id: 'BK202607302001' }))
      .toBe('/store/bookings?recordId=BK202607302001');
    expect(storeDetail.vm.orderDetailPath({ id: 'ORD202607301001' }))
      .toBe('/brand/orders?recordId=ORD202607301001');
  });
});

describe('lightweight operation details', () => {
  beforeEach(() => demoStore.reset());

  it('selects distributor, commission and sharing records', () => {
    const wrapper = mountPage(BrandDistribution);

    wrapper.vm.openDistributor(wrapper.vm.distributorRows[0]);
    expect(wrapper.vm.detailType).toBe('distributor');
    expect(wrapper.vm.selectedRecord.id).toBe('DST1001');

    wrapper.vm.openCommission(wrapper.vm.state.commissionRecords[0]);
    expect(wrapper.vm.detailType).toBe('commission');
    expect(wrapper.vm.selectedRecord.id).toBe('CM202607301001');

    wrapper.vm.openShare(wrapper.vm.state.shareRecords[0]);
    expect(wrapper.vm.detailType).toBe('share');
    expect(wrapper.vm.selectedRecord.id).toBeTruthy();
    expect(wrapper.vm.drawerOpen).toBe(true);
  });

  it.each([
    [StoreWithdrawals, 'overview', 'WD202607301001'],
    [BrandWithdrawals, 'visibleRecords', 'WD202607301001'],
  ])('opens the selected withdrawal in %s', (component, source, expectedId) => {
    const wrapper = mountPage(component);
    const rows = source === 'overview' ? wrapper.vm.overview.records : wrapper.vm.visibleRecords;

    wrapper.vm.openDetail(rows.find((item) => item.id === expectedId));

    expect(wrapper.vm.selectedRecord.id).toBe(expectedId);
    expect(wrapper.vm.drawerOpen).toBe(true);
  });

  it.each([
    [StoreLive, 'rooms'],
    [BrandLive, 'visibleRooms'],
    [BrandDevices, 'filteredDevices'],
  ])('opens a record detail in %s', (component, source) => {
    const wrapper = mountPage(component);
    const record = wrapper.vm[source][0];

    wrapper.vm.openDetail(record);

    expect(wrapper.vm.selectedRecord.id).toBe(record.id);
    expect(wrapper.vm.drawerOpen).toBe(true);
  });
});

describe('store live status transitions', () => {
  it('allows upcoming to live to ended and rejects every other transition', () => {
    demoStore.reset();
    const state = demoStore.state.data;
    expect(transitions.transitionLiveRoom).toBeTypeOf('function');

    transitions.transitionLiveRoom(state, { roomId: 'LR1002', targetStatus: 'live' });
    expect(state.liveRooms.find((item) => item.id === 'LR1002').status).toBe('live');

    transitions.transitionLiveRoom(state, { roomId: 'LR1002', targetStatus: 'ended' });
    expect(state.liveRooms.find((item) => item.id === 'LR1002').status).toBe('ended');

    expect(() => transitions.transitionLiveRoom(state, { roomId: 'LR1002', targetStatus: 'live' }))
      .toThrow('直播状态不可这样变更');
    expect(() => transitions.transitionLiveRoom(state, { roomId: 'UNKNOWN', targetStatus: 'live' }))
      .toThrow('直播场次不存在');
  });
});
