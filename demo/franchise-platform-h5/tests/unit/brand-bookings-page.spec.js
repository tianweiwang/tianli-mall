import { shallowMount } from '@vue/test-utils';
import { demoStore } from '../../src/mock/store';
import Bookings from '../../src/views/brand/Bookings.vue';

function mountPage() {
  return shallowMount(Bookings, {
    stubs: {
      AdminBookingHeatmap: true,
      AdminMetricStrip: true,
      AdminStatusTag: true,
      CalendarDays: true,
      Gauge: true,
      RotateCcw: true,
      TriangleAlert: true,
      UserRoundCheck: true,
      'el-button': true,
      'el-date-picker': true,
      'el-drawer': true,
      'el-input': true,
      'el-option': true,
      'el-select': true,
      'el-table': true,
      'el-table-column': true,
    },
  });
}

describe('brand bookings page', () => {
  beforeEach(() => demoStore.reset());

  it('defaults to demoNow and drills a heat cell into the detail rows', async () => {
    const wrapper = mountPage();
    expect(wrapper.vm.date).toBe('2026-07-30');
    expect(wrapper.vm.operations.metrics.bookingCount).toBe(10);

    const cell = wrapper.vm.operations.rows
      .find((row) => row.storeId === 'TL1002').cells
      .find((item) => item.label === '11:00-11:45');
    wrapper.vm.selectHeatCell(cell);
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.selectedCell.key).toBe('TL1002:11:00-11:45');
    expect(wrapper.vm.selectionSummary).toContain('2026-07-30');
    expect(wrapper.vm.visibleBookings).toHaveLength(2);
    expect(wrapper.vm.visibleBookings.every((item) => (
      item.storeId === 'TL1002' && item.start === '11:00'
    ))).toBe(true);
  });

  it('keeps status and keyword filters out of heatmap capacity', async () => {
    const wrapper = mountPage();
    const capacity = wrapper.vm.operations.metrics.capacity;
    await wrapper.setData({ status: 'verified', keyword: '周女士' });

    expect(wrapper.vm.operations.metrics.capacity).toBe(capacity);
    expect(wrapper.vm.visibleBookings).toHaveLength(1);
    expect(wrapper.vm.visibleBookings[0].contactName).toBe('周女士');
  });

  it('clears a heat selection when its global scope changes', async () => {
    const wrapper = mountPage();
    wrapper.vm.selectHeatCell(wrapper.vm.operations.rows[0].cells.find((item) => item.selectable));
    await wrapper.setData({ serviceId: 'SV1002' });

    expect(wrapper.vm.selectedCell).toBeNull();
  });

  it('uses attention items as explicit drill-down actions', async () => {
    const wrapper = mountPage();
    wrapper.vm.selectAttention(
      wrapper.vm.operations.attention.find((item) => item.type === 'full-slot'),
    );
    expect(wrapper.vm.selectedCell).not.toBeNull();

    wrapper.vm.selectAttention(
      wrapper.vm.operations.attention.find((item) => item.type === 'expired'),
    );
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.selectedCell).toBeNull();
    expect(wrapper.vm.status).toBe('expired');
  });

  it('keeps the current detail status when drilling into a full slot', async () => {
    const wrapper = mountPage();
    await wrapper.setData({ status: 'verified' });

    wrapper.vm.selectAttention(
      wrapper.vm.operations.attention.find((item) => item.type === 'full-slot'),
    );

    expect(wrapper.vm.selectedCell).not.toBeNull();
    expect(wrapper.vm.status).toBe('verified');
  });

  it('resets every filter to the deterministic default scope', async () => {
    const wrapper = mountPage();
    await wrapper.setData({
      date: '2026-07-31',
      keyword: '陈女士',
      serviceId: 'SV1001',
      status: 'pending',
      storeId: 'TL1001',
    });
    wrapper.vm.resetFilters();

    expect(wrapper.vm.$data).toMatchObject({
      date: '2026-07-30',
      keyword: '',
      selectedCell: null,
      serviceId: 'all',
      status: 'all',
      storeId: 'all',
    });
  });
});
