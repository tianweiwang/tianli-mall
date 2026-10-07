import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { demoStore } from '../../src/mock/store';
import Assessments from '../../src/views/store/Assessments.vue';
import AssessmentDetail from '../../src/views/store/AssessmentDetail.vue';

const elementStubs = {
  'el-button': { template: '<button @click="$emit(\'click\')"><slot /></button>' },
  'el-checkbox': { template: '<label><input type="checkbox"><slot /></label>' },
  'el-checkbox-group': { template: '<div><slot /></div>' },
  'el-dialog': { template: '<div><slot /><slot name="footer" /></div>' },
  'el-input': true,
  'el-option': true,
  'el-radio-button': true,
  'el-radio-group': true,
  'el-select': true,
  'el-table': { template: '<div><slot /></div>' },
  'el-table-column': true,
};

describe('store assessment pages', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it('shows only active-store tasks and links the ready task to detail', () => {
    demoStore.commit((draft) => {
      draft.assessmentTasks.push({
        id: 'AST-OTHER-STORE',
        customerId: 'TMP1001',
        storeId: 'TL1002',
        deviceId: 'DEV-TL-002',
        status: 'ready',
        createdAt: draft.demoNow,
        attempts: [{ deviceId: 'DEV-TL-002', status: 'ready', boundAt: draft.demoNow }],
      });
    });
    const wrapper = shallowMount(Assessments, {
      router: createRouter(),
      stubs: elementStubs,
    });

    expect(wrapper.vm.storeTasks.every((item) => item.storeId === 'TL1001')).toBe(true);
    expect(wrapper.text()).toContain('AST202607301003');
    expect(wrapper.vm.taskStatusLabel('ready')).toBe('待检测');
    expect(wrapper.text()).not.toContain('AST-OTHER-STORE');
    expect(wrapper.text()).not.toContain('待设备领取');
    expect(wrapper.text()).not.toContain('已领取');
    expect(wrapper.find('[data-testid="assessment-task-AST202607301003"]').attributes('to'))
      .toBe('/store/assessments/AST202607301003');
  });

  it('shows the reassessment customer and creates a walk-in task', async () => {
    const wrapper = shallowMount(Assessments, {
      router: createRouter(),
      stubs: elementStubs,
    });
    wrapper.setData({ activeTab: 'reassessments' });
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain('陈女士');

    const beforeTasks = demoStore.state.data.assessmentTasks.length;
    wrapper.setData({
      walkInForm: {
        name: '许女士',
        phone: '13748261234',
        scopes: ['assessment', 'image'],
      },
    });
    await wrapper.vm.createWalkIn();

    expect(demoStore.state.data.assessmentTasks).toHaveLength(beforeTasks + 1);
    expect(demoStore.state.data.customers[0]).toMatchObject({ type: 'temporary', name: '许女士' });
    expect(wrapper.find('[data-testid="created-assessment-task-id"]').exists()).toBe(true);

    const router = createRouter();
    await router.push(`/store/assessments/${wrapper.vm.lastCreatedTaskId}`);
    const detail = shallowMount(AssessmentDetail, {
      router,
      stubs: { ...elementStubs, AssessmentImageStrip: true, AssessmentMetricGrid: true },
    });
    expect(detail.text()).toContain('137****1234');
    expect(detail.text()).not.toContain('13748261234');
  });

  it('shows the unavailable-device reason when booking task creation fails', () => {
    const booking = {
      id: 'BK-OFFLINE-DEVICE',
      storeId: 'TL1002',
      serviceId: 'SV1001',
      customerId: 'CUS1002',
    };
    demoStore.commit((draft) => {
      draft.activeStoreId = 'TL1002';
      draft.bookings.push(booking);
    });
    const showError = vi.fn();
    const wrapper = shallowMount(Assessments, {
      router: createRouter(),
      stubs: elementStubs,
      mocks: { $message: { error: showError } },
    });

    expect(() => wrapper.vm.createFromBooking(booking)).not.toThrow();
    expect(showError).toHaveBeenCalledWith('本店检测设备当前不可用');
    expect(wrapper.vm.lastCreatedTaskId).toBe('');
  });

  it('renders the simulation controls for a store-owned task', async () => {
    const router = createRouter();
    await router.push('/store/assessments/AST202607301003');
    const wrapper = shallowMount(AssessmentDetail, {
      router,
      stubs: { ...elementStubs, AssessmentImageStrip: true, AssessmentMetricGrid: true },
    });

    expect(wrapper.text()).toContain('固定门店设备');
    expect(wrapper.text()).toContain('体态检测仪 A01');
    expect(wrapper.text()).toContain('开始检测');
    expect(wrapper.text()).toContain('正常回传');
    expect(wrapper.text()).toContain('设备离线');
    expect(wrapper.text()).toContain('数据不完整');
    expect(wrapper.text()).toContain('门店不匹配');
    expect(wrapper.text()).not.toContain('设备领取任务');
    expect(wrapper.text()).not.toContain('待设备领取');
    expect(wrapper.text()).not.toContain('已领取');
    expect(wrapper.find('qrcode-stub').exists()).toBe(false);
  });
});
