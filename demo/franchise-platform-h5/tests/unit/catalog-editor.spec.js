import { shallowMount } from '@vue/test-utils';
import CatalogEditorDialog from '../../src/components/brand/CatalogEditorDialog.vue';

const stubs = {
  'el-button': true,
  'el-checkbox': true,
  'el-checkbox-group': true,
  'el-dialog': { template: '<div><slot /><slot name="footer" /></div>' },
  'el-form': true,
  'el-form-item': true,
  'el-input': true,
  'el-input-number': true,
  'el-option': true,
  'el-radio': true,
  'el-radio-group': true,
  'el-select': true,
};

describe('CatalogEditorDialog', () => {
  it('emits normalized product input using cents and unique specs', async () => {
    const wrapper = shallowMount(CatalogEditorDialog, {
      propsData: {
        visible: true,
        type: 'product',
        value: null,
        stores: [
          { id: 'TL1001', name: '天俪锦江店', status: 'active' },
          { id: 'TL1002', name: '天俪滨江店', status: 'active' },
        ],
      },
      stubs,
    });
    await wrapper.setData({
      form: {
        ...wrapper.vm.form,
        name: '门店护理组合装',
        category: '养护护理',
        unit: '套',
        specsText: '标准套装, 家庭套装, 标准套装',
        priceYuan: 598,
        originalPriceYuan: 698,
        stock: 30,
        storeIds: ['TL1001'],
      },
    });

    wrapper.vm.submit();

    expect(wrapper.emitted('submit')[0][0]).toMatchObject({
      name: '门店护理组合装',
      specs: ['标准套装', '家庭套装'],
      priceCents: 59800,
      originalPriceCents: 69800,
      storeIds: ['TL1001'],
    });
  });

  it('hydrates service prices from cents when editing', async () => {
    const value = {
      id: 'SV1032',
      name: '肩颈舒缓方案',
      series: '经典系列',
      description: '门店演示项目',
      durationMinutes: 45,
      priceModel: 'standard',
      unitPriceCents: 26800,
      packagePriceCents: 198000,
      packageCount: 10,
      packageUnit: '次',
      storeIds: ['TL1001'],
    };
    const wrapper = shallowMount(CatalogEditorDialog, {
      propsData: { visible: true, type: 'service', value, stores: [] },
      stubs,
    });

    expect(wrapper.vm.form).toMatchObject({
      name: '肩颈舒缓方案',
      unitPriceYuan: 268,
      packagePriceYuan: 1980,
      storeIds: ['TL1001'],
    });
  });
});
