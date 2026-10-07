import { shallowMount } from '@vue/test-utils';
import {
  addCartItem,
  removeCartItem,
  setCartItemQuantity,
} from '../../src/mock/cart';
import { createRouter } from '../../src/router';
import CustomerLayout from '../../src/layouts/CustomerLayout.vue';
import { createSeed } from '../../src/mock/seed';
import { demoStore } from '../../src/mock/store';

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mountCustomer(confirm) {
  const router = createRouter();
  await router.push('/c/store/TL1001');
  const wrapper = shallowMount(CustomerLayout, {
    router,
    mocks: { $confirm: confirm },
    stubs: {
      BottomNav: true,
      CustomerHeader: true,
      DemoNavigator: true,
      RouterView: true,
    },
  });
  await flushPromises();
  return { router, wrapper };
}

describe('consumer cart', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it('merges the same product and specification', () => {
    const state = createSeed();

    addCartItem(state, { productId: 3, spec: '盒装', quantity: 1 });
    addCartItem(state, { productId: 3, spec: '盒装', quantity: 2 });

    expect(state.cart).toEqual([
      { productId: 3, spec: '盒装', quantity: 3 },
    ]);
  });

  it('keeps quantity at one or above and removes an item explicitly', () => {
    const state = createSeed();
    addCartItem(state, { productId: 4, spec: '标准款', quantity: 1 });

    setCartItemQuantity(state, { productId: 4, spec: '标准款' }, 0);
    expect(state.cart[0].quantity).toBe(1);

    removeCartItem(state, { productId: 4, spec: '标准款' });
    expect(state.cart).toEqual([]);
  });

  it('clears the cart after confirming a store change', async () => {
    demoStore.commit((draft) => {
      draft.cart.push({ productId: 3, spec: '盒装', quantity: 1 });
    });
    const confirm = vi.fn().mockResolvedValue();
    const { router } = await mountCustomer(confirm);

    await router.push('/c/store/TL1002');
    await flushPromises();

    expect(confirm).toHaveBeenCalledTimes(1);
    expect(demoStore.state.data.activeStoreId).toBe('TL1002');
    expect(demoStore.state.data.cart).toEqual([]);
  });

  it('keeps the cart and current store when a store change is cancelled', async () => {
    demoStore.commit((draft) => {
      draft.cart.push({ productId: 3, spec: '盒装', quantity: 1 });
    });
    const confirm = vi.fn().mockRejectedValue(new Error('cancelled'));
    const { router } = await mountCustomer(confirm);

    await router.push('/c/store/TL1002');
    await flushPromises();

    expect(demoStore.state.data.activeStoreId).toBe('TL1001');
    expect(demoStore.state.data.cart).toEqual([
      { productId: 3, spec: '盒装', quantity: 1 },
    ]);
    expect(router.currentRoute.fullPath).toBe('/c/store/TL1001');
  });
});
