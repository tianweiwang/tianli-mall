import { shallowMount } from '@vue/test-utils';
import AdminBookingHeatmap from '../../src/components/admin/AdminBookingHeatmap.vue';

const columns = [{ key: '10:00-10:45', label: '10:00-10:45' }];
const rows = [
  {
    storeId: 'S1',
    storeName: '中心店',
    storeStatus: 'active',
    openHours: '09:00-21:00',
    bookingCount: 2,
    arrivalRate: 50,
    cells: [{
      key: 'S1:10:00-10:45',
      label: '10:00-10:45',
      storeId: 'S1',
      storeName: '中心店',
      booked: 2,
      capacity: 3,
      serviceLabel: '体态评估',
      tone: 'busy',
      selectable: true,
      testId: 'booking-heat-cell-S1-10:00-10:45',
    }],
  },
  {
    storeId: 'S2',
    storeName: '北城店',
    storeStatus: 'disabled',
    openHours: '09:30-20:30',
    bookingCount: 0,
    arrivalRate: 0,
    cells: [{
      key: 'S2:10:00-10:45',
      label: '10:00-10:45',
      storeId: 'S2',
      storeName: '北城店',
      booked: 0,
      capacity: 0,
      serviceLabel: '',
      tone: 'off',
      selectable: false,
      testId: 'booking-heat-cell-S2-10:00-10:45',
    }],
  },
];

describe('AdminBookingHeatmap', () => {
  it('emits selectable cells and exposes native keyboard buttons', async () => {
    const wrapper = shallowMount(AdminBookingHeatmap, {
      propsData: { columns, rows, selectedKey: '' },
    });
    const cell = wrapper.find('[data-testid="booking-heat-cell-S1-10:00-10:45"]');

    expect(cell.element.tagName).toBe('BUTTON');
    expect(cell.attributes('aria-label')).toContain('中心店');
    await cell.trigger('click');
    expect(wrapper.emitted('select')[0][0]).toMatchObject({ key: 'S1:10:00-10:45' });
  });

  it('does not render unavailable cells as buttons', () => {
    const wrapper = shallowMount(AdminBookingHeatmap, {
      propsData: { columns, rows, selectedKey: '' },
    });

    expect(wrapper.find('[data-testid="booking-heat-cell-S2-10:00-10:45"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('门店停约');
  });
});
