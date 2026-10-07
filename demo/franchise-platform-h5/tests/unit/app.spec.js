import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';
import App from '../../src/App.vue';

const entryHtml = readFileSync('index.html', 'utf8');

describe('App', () => {
  it('declares a mobile viewport and branded page title', () => {
    expect(entryHtml).toContain('<meta name="viewport" content="width=device-width, initial-scale=1">');
    expect(entryHtml).toContain('<title>天俪加盟门店平台</title>');
  });

  it('renders the active route without making a network request', () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const xhrOpenSpy = vi.spyOn(XMLHttpRequest.prototype, 'open');

    const wrapper = mount(App, {
      stubs: { 'router-view': { template: '<main data-testid="route-view" />' } },
    });

    expect(wrapper.find('[data-testid="route-view"]').exists()).toBe(true);
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(xhrOpenSpy).not.toHaveBeenCalled();
  });
});
