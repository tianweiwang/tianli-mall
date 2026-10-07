import { mount } from '@vue/test-utils';
import QrCode from '../../src/components/qr/QrCode.vue';
import QrScanner from '../../src/components/qr/QrScanner.vue';

describe('QR components', () => {
  it('renders a downloadable QR image for the supplied value', async () => {
    const wrapper = mount(QrCode, {
      propsData: {
        value: 'https://demo.example.com/#/c/store/TL1001',
        label: '天俪锦江店入口码',
      },
    });

    await vi.waitFor(() => {
      expect(wrapper.find('img').exists()).toBe(true);
    });

    expect(wrapper.find('img').attributes('src')).toMatch(/^data:image\/png;base64,/);
    expect(wrapper.find('img').attributes('alt')).toBe('天俪锦江店入口码');
    expect(wrapper.find('a').attributes('download')).toBe('demo-qr.png');
  });

  it('emits the fallback verification code without starting a camera', async () => {
    const wrapper = mount(QrScanner);

    await wrapper.find('#backup-verify-code').setValue('826106');
    await wrapper.find('form').trigger('submit');

    expect(wrapper.emitted('backup')).toEqual([['826106']]);
    expect(wrapper.find('input[aria-label="识别二维码图片"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('打开摄像头');
  });

  it('keeps image and backup-code fallbacks when camera startup fails', async () => {
    const wrapper = mount(QrScanner);
    wrapper.vm.scanner = {
      start: vi.fn().mockRejectedValue(new Error('permission denied')),
      stop: vi.fn().mockResolvedValue(),
    };

    await wrapper.find('button').trigger('click');
    await vi.waitFor(() => {
      expect(wrapper.emitted('error')).toEqual([[
        '无法使用摄像头，请检查浏览器权限，或改用二维码图片。',
      ]]);
    });

    expect(wrapper.find('input[aria-label="识别二维码图片"]').exists()).toBe(true);
    expect(wrapper.find('#backup-verify-code').exists()).toBe(true);
  });
});
