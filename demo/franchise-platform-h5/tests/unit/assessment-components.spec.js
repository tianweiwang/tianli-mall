import { shallowMount } from '@vue/test-utils';
import AssessmentImageStrip from '../../src/components/assessment/AssessmentImageStrip.vue';
import AssessmentMetricGrid from '../../src/components/assessment/AssessmentMetricGrid.vue';

describe('assessment report components', () => {
  it('renders metric values, both deltas and text status cues', () => {
    const wrapper = shallowMount(AssessmentMetricGrid, {
      propsData: {
        metrics: [
          { key: 'a', label: '双肩高度差', current: 8, unit: 'mm', previousDelta: -4, firstDelta: -4, status: 'improvement' },
          { key: 'b', label: '骨盆倾斜角', current: 2.9, unit: '°', previousDelta: 0, firstDelta: -0.9, status: 'stable' },
          { key: 'c', label: '颈部前倾角', current: 15, unit: '°', previousDelta: 2, firstDelta: 2, status: 'fluctuation' },
        ],
      },
    });

    expect(wrapper.text()).toContain('双肩高度差');
    expect(wrapper.text()).toContain('8 mm');
    expect(wrapper.text()).toContain('较上次');
    expect(wrapper.text()).toContain('较首次');
    expect(wrapper.text()).toContain('改善');
    expect(wrapper.text()).toContain('持平');
    expect(wrapper.text()).toContain('波动');
  });

  it('renders three fixed-ratio demo images with accessible alt text', () => {
    const wrapper = shallowMount(AssessmentImageStrip, {
      propsData: {
        images: {
          front: './assessment/posture-front.png',
          side: './assessment/posture-side.png',
          back: './assessment/posture-back.png',
        },
        capturedAt: '2026-06-30 10:20',
        storeName: '天俪锦江店',
      },
    });

    const images = wrapper.findAll('img');
    expect(images).toHaveLength(3);
    expect(images.wrappers.map((item) => item.attributes('alt'))).toEqual([
      '正面体态记录',
      '侧面体态记录',
      '背面体态记录',
    ]);
    expect(wrapper.text()).toContain('演示影像');
    expect(wrapper.text()).toContain('天俪锦江店');
  });
});
