import { createSeed } from '../../src/mock/seed';
import { createDemoStore, STORAGE_KEY } from '../../src/mock/store';
import { ordersForStore } from '../../src/mock/selectors';

describe('demo store', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('initializes the deterministic seed on first open', () => {
    const store = createDemoStore();

    expect(store.snapshot()).toEqual(createSeed());
    expect(store.state.notice).toBe('');
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual(createSeed());
  });

  it.each([
    ['damaged JSON', '{broken'],
    ['an unsupported version', JSON.stringify({ version: 99, stores: [] })],
  ])('restores the seed for %s', (_caseName, storedValue) => {
    window.localStorage.setItem(STORAGE_KEY, storedValue);

    const store = createDemoStore();

    expect(store.state.notice).toBe('本地数据异常，演示数据已恢复。');
    expect(store.snapshot()).toEqual(createSeed());
  });

  it('persists committed state and returns defensive snapshots', () => {
    const store = createDemoStore();
    store.commit((draft) => {
      draft.activeStoreId = 'TL1002';
      draft.cart.push({ productId: 3, quantity: 2, spec: '盒装' });
    });

    const snapshot = store.snapshot();
    snapshot.cart[0].quantity = 99;

    expect(store.state.data.activeStoreId).toBe('TL1002');
    expect(store.state.data.cart[0].quantity).toBe(2);
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY)).cart[0].quantity).toBe(2);
  });

  it('repairs a persisted friend binding that still uses the distributor identity', () => {
    const storedValue = createSeed();
    storedValue.activeDistributionBindingId = 'DB202607301001';
    storedValue.distributionBindings = [{
      id: 'DB202607301001',
      distributorId: 'DST1001',
      referralId: 'RF202607301004',
      customerId: 'CUS1003',
      customerName: '赵女士',
      phone: '138****6008',
      status: 'active',
      acceptedAt: storedValue.demoNow,
      firstOrderId: null,
    }];
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(storedValue));

    const store = createDemoStore();

    expect(store.state.data.activeCustomerId).toBe('CUS1003');
    expect(store.state.data.activeCustomerName).toBe('赵女士');
    expect(store.state.data.customers.find((item) => item.id === 'CUS1003')).toMatchObject({
      name: '赵女士',
      phone: '138****6008',
    });
  });

  it('assigns legacy persisted orders to the distributor customer', () => {
    const storedValue = createSeed();
    storedValue.orders.forEach((order) => delete order.customerId);
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(storedValue));

    const store = createDemoStore();

    expect(store.state.data.orders.every((order) => order.customerId === 'CUS1001')).toBe(true);
  });

  it('resets to the exact seed and returns to the default store', () => {
    const store = createDemoStore();
    store.commit((draft) => {
      draft.activeStoreId = 'TL1002';
      draft.cart.push({ productId: 1, quantity: 1, spec: '单瓶装' });
    });

    store.reset();

    expect(store.snapshot()).toEqual(createSeed());
    expect(store.state.notice).toBe('演示数据已重置。');
    expect(store.state.data.activeStoreId).toBe('TL1001');
    expect(store.state.data.version).toBe(11);
    expect(store.state.data.assessmentTasks).toHaveLength(4);
  });

  it('never exposes another store order to a store selector', () => {
    const state = createSeed();

    expect(ordersForStore(state, 'TL1001')).toHaveLength(3);
    expect(ordersForStore(state, 'TL1001').every((order) => order.storeId === 'TL1001')).toBe(true);
    expect(ordersForStore(state, 'TL1002')).toHaveLength(2);
  });
});
