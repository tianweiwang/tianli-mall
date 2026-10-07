import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import Devices from '../../src/views/brand/Devices.vue';
import AssessmentAnalytics from '../../src/views/brand/AssessmentAnalytics.vue';
import { demoStore } from '../../src/mock/store';

describe('brand assessment operations pages', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it.each([
    ['/brand/devices', 'BrandDevices'],
    ['/brand/assessments', 'BrandAssessmentAnalytics'],
  ])('loads %s as %s', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe(componentName);
  });

  it('filters devices and exposes calibration and offline warnings', () => {
    const wrapper = shallowMount(Devices, {
      stubs: { AdminMetricStrip: true, AdminStatusTag: true, 'el-option': true, 'el-select': true, 'el-table': true, 'el-table-column': true },
    });

    expect(wrapper.vm.filteredDevices).toHaveLength(3);
    wrapper.setData({ statusFilter: 'offline' });
    expect(wrapper.vm.filteredDevices.map((item) => item.id)).toEqual(['DEV-TL-002']);
    expect(wrapper.text()).toContain('校准已超过 30 天');
    expect(wrapper.vm.rowClassName({ row: wrapper.vm.devices.find((item) => item.id === 'DEV-TL-002') })).toContain('warning');
  });

  it('shows store completion, paired customers and no identifying phone detail', () => {
    const wrapper = shallowMount(AssessmentAnalytics, {
      stubs: {
        AdminLineChart: true,
        AdminMetricStrip: true,
        AdminStatusTag: true,
        'el-button': { template: '<button @click="$emit(\'click\')"><slot /></button>' },
        'el-date-picker': true,
        'el-drawer': { template: '<aside><slot /></aside>' },
        'el-input': true,
        'el-option': true,
        'el-select': true,
        'el-table': { template: '<div><slot /></div>' },
        'el-table-column': true,
      },
    });
    const store = wrapper.vm.operations.stores.find((item) => item.storeId === 'TL1001');

    expect(store).toMatchObject({
      taskCount: 3,
      completedTaskCount: 2,
      completionRate: 67,
      pairedCustomerCount: 1,
    });
    expect(wrapper.text()).toContain('演示统计');
    expect(wrapper.text()).toContain('不代表产品效果');
    expect(wrapper.text()).not.toMatch(/1\d{10}/);
  });

  it('filters published records and opens a privacy-safe metric drawer', async () => {
    const wrapper = shallowMount(AssessmentAnalytics, {
      stubs: {
        AdminLineChart: true,
        AdminMetricStrip: true,
        AdminStatusTag: true,
        'el-button': { template: '<button @click="$emit(\'click\')"><slot /></button>' },
        'el-date-picker': true,
        'el-drawer': { template: '<aside><slot /></aside>' },
        'el-input': true,
        'el-option': true,
        'el-select': true,
        'el-table': { template: '<div><slot /></div>' },
        'el-table-column': true,
      },
    });

    expect(wrapper.vm.filteredRecords).toHaveLength(3);

    await wrapper.setData({ storeFilter: 'TL1002' });
    expect(wrapper.vm.filteredRecords.every((row) => row.storeId === 'TL1002')).toBe(true);

    await wrapper.setData({ storeFilter: 'all', dateFilter: '2026-06-30' });
    expect(wrapper.vm.filteredRecords.map((row) => row.reportId)).toEqual(['ASR202607301002']);

    await wrapper.setData({ dateFilter: '', keyword: '陈女士' });
    expect(wrapper.vm.filteredRecords).toHaveLength(2);

    wrapper.vm.openRecord(wrapper.vm.filteredRecords[0]);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.drawerOpen).toBe(true);
    expect(wrapper.vm.selectedRecord.metricList).toHaveLength(4);
    expect(wrapper.text()).toContain('双肩高度差');
    expect(wrapper.text()).toContain('报告版本');
    expect(wrapper.text()).not.toMatch(/1\d{10}/);
    expect(wrapper.text()).not.toContain('检测影像');
    expect(wrapper.text()).not.toContain('确认报告');
    expect(wrapper.text()).not.toContain('删除报告');
  });
});
