import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { demoStore } from '../../src/mock/store';
import Assessments from '../../src/views/customer/Assessments.vue';
import AssessmentDetail from '../../src/views/customer/AssessmentDetail.vue';

describe('customer assessment pages', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it.each([
    ['/c/store/TL1001/assessments', 'CustomerAssessments'],
    ['/c/store/TL1001/assessments/ASR202607301002', 'CustomerAssessmentDetail'],
  ])('loads %s as %s', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe(componentName);
  });

  it('shows only the active customer records in newest-first order', async () => {
    demoStore.commit((draft) => {
      draft.assessmentRecords.push({
        ...draft.assessmentRecords[0],
        id: 'ASR-OTHER-CUSTOMER',
        customerId: 'TMP1001',
        measuredAt: '2026-07-30T12:00:00+08:00',
      });
    });
    const router = createRouter();
    await router.push('/c/store/TL1001/assessments');
    const wrapper = shallowMount(Assessments, { router });

    expect(wrapper.vm.records.map((item) => item.id)).toEqual([
      'ASR202607301002',
      'ASR202607301001',
    ]);
    expect(wrapper.text()).not.toContain('ASR-OTHER-CUSTOMER');
    expect(wrapper.text().indexOf('第 2 次记录')).toBeLessThan(wrapper.text().indexOf('第 1 次记录'));
  });

  it('shows both comparisons, the non-medical notice and reassessment route', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/assessments/ASR202607301002');
    const wrapper = shallowMount(AssessmentDetail, {
      router,
      stubs: { AssessmentImageStrip: true, AssessmentMetricGrid: true },
    });

    expect(wrapper.vm.comparison.metrics.shoulderHeightDiffMm).toMatchObject({
      previousDelta: -4,
      firstDelta: -4,
    });
    expect(wrapper.text()).toContain('较上次');
    expect(wrapper.text()).toContain('较首次');
    expect(wrapper.text()).toContain('本报告用于记录体态变化和日常健康管理，不作为医疗诊断或治疗依据。');
    expect(wrapper.find('[data-testid="reassessment-booking"]').attributes('to'))
      .toBe('/c/store/TL1001/booking?serviceId=SV1001&sourceAssessmentId=ASR202607301002');
    expect(wrapper.text()).not.toContain('DEVRES');
    expect(wrapper.text()).not.toContain('CUS1001');
  });
});
