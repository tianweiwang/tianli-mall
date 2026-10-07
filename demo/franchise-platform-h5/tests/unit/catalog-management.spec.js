import { createSeed, DEMO_VERSION } from '../../src/mock/seed';
import {
  createCatalogItem,
  setCatalogItemStatus,
  updateCatalogItem,
} from '../../src/mock/catalogTransitions';
import {
  productForStoreById,
  productsForStore,
  serviceForStoreById,
  servicesForStore,
  liveRoomById,
} from '../../src/mock/selectors';
import { addCartItem } from '../../src/mock/cart';
import { createLiveRoom, createPaidOrder } from '../../src/mock/transitions';

function validServiceInput(overrides = {}) {
  return {
    name: '肩颈舒缓方案',
    series: '经典系列',
    description: '用于门店到店调理演示。',
    durationMinutes: 45,
    priceModel: 'standard',
    unitPriceCents: 26800,
    packagePriceCents: 198000,
    packageCount: 10,
    packageUnit: '次',
    storeIds: ['TL1001'],
    ...overrides,
  };
}

function validProductInput(overrides = {}) {
  return {
    name: '门店护理组合装',
    series: '维养系列',
    category: '养护护理',
    unit: '套',
    specs: ['标准套装'],
    priceCents: 59800,
    originalPriceCents: 69800,
    stock: 30,
    deliveryType: 'express',
    image: '',
    storeIds: ['TL1001', 'TL1002'],
    ...overrides,
  };
}

describe('brand catalog domain', () => {
  it('seeds active catalog items for both operating stores', () => {
    const state = createSeed();

    expect(DEMO_VERSION).toBe(11);
    expect(state.products.every((item) => item.status === 'active')).toBe(true);
    expect(state.products.every((item) => item.storeIds.includes('TL1001'))).toBe(true);
    expect(state.services.every((item) => item.status === 'active')).toBe(true);
    expect(state.services.every((item) => item.storeIds.includes('TL1002'))).toBe(true);
    expect(state.sequence).toMatchObject({ product: 11, service: 31 });
  });

  it('creates and updates a service without changing its id', () => {
    const state = createSeed();

    const created = createCatalogItem(state, 'service', validServiceInput());
    const updated = updateCatalogItem(
      state,
      'service',
      created.id,
      validServiceInput({ name: '肩背经筋舒缓方案', storeIds: ['TL1002'] }),
    );

    expect(created.id).toBe('SV1032');
    expect(updated).toMatchObject({
      id: created.id,
      name: '肩背经筋舒缓方案',
      status: 'active',
      storeIds: ['TL1002'],
      createdAt: state.demoNow,
      updatedAt: state.demoNow,
    });
    expect(state.services).toHaveLength(32);
  });

  it('creates a product with defaults and preserves sales while editing and toggling status', () => {
    const state = createSeed();

    const created = createCatalogItem(state, 'product', validProductInput());
    created.sales = 8;
    const updated = updateCatalogItem(
      state,
      'product',
      created.id,
      validProductInput({ name: '门店护理家庭装', stock: 22 }),
    );
    const inactive = setCatalogItemStatus(state, 'product', created.id, 'inactive');

    expect(created.id).toBe(12);
    expect(updated).toMatchObject({
      id: 12,
      name: '门店护理家庭装',
      image: './products/igood-01.svg',
      sales: 8,
      stock: 22,
    });
    expect(inactive).toMatchObject({ id: 12, status: 'inactive', sales: 8 });
    expect(state.products).toHaveLength(12);
  });

  it.each([
    ['missing name', validServiceInput({ name: ' ' }), '名称不能为空'],
    ['missing store', validServiceInput({ storeIds: [] }), '至少选择一家营业门店'],
    ['disabled store', validServiceInput({ storeIds: ['TL1003'] }), '适用门店不可用'],
    ['invalid price', validServiceInput({ unitPriceCents: 0 }), '单次价格必须大于 0'],
    ['incomplete package', validServiceInput({ packageCount: 0 }), '疗程价格和数量必须同时有效'],
  ])('rejects invalid service input: %s', (_label, input, message) => {
    const state = createSeed();

    expect(() => createCatalogItem(state, 'service', input)).toThrow(message);
    expect(state.services).toHaveLength(31);
  });

  it.each([
    ['missing specs', validProductInput({ specs: [] }), '至少填写一个商品规格'],
    ['negative stock', validProductInput({ stock: -1 }), '库存不能小于 0'],
    ['invalid status', 'paused', '目录状态无效'],
  ])('rejects invalid product operation: %s', (_label, input, message) => {
    const state = createSeed();

    if (typeof input === 'string') {
      expect(() => setCatalogItemStatus(state, 'product', 1, input)).toThrow(message);
    } else {
      expect(() => createCatalogItem(state, 'product', input)).toThrow(message);
    }
  });
});

describe('store-visible catalog selectors', () => {
  it('filters products by active status and store scope', () => {
    const state = createSeed();
    state.products[0].storeIds = ['TL1001'];
    state.products[1].status = 'inactive';

    expect(productsForStore(state, 'TL1002')).not.toContainEqual(
      expect.objectContaining({ id: state.products[0].id }),
    );
    expect(productsForStore(state, 'TL1001')).not.toContainEqual(
      expect.objectContaining({ id: state.products[1].id }),
    );
    expect(productForStoreById(state, 'TL1001', state.products[0].id)?.id)
      .toBe(state.products[0].id);
    expect(productForStoreById(state, 'TL1002', state.products[0].id)).toBeUndefined();
  });

  it('filters services by active status and store scope', () => {
    const state = createSeed();
    state.services[0].storeIds = ['TL1002'];
    state.services[1].status = 'inactive';

    expect(servicesForStore(state, 'TL1001')).not.toContainEqual(
      expect.objectContaining({ id: state.services[0].id }),
    );
    expect(servicesForStore(state, 'TL1002')).not.toContainEqual(
      expect.objectContaining({ id: state.services[1].id }),
    );
    expect(serviceForStoreById(state, 'TL1002', state.services[0].id)?.id)
      .toBe(state.services[0].id);
    expect(serviceForStoreById(state, 'TL1001', state.services[0].id)).toBeUndefined();
  });

  it('removes unavailable products from consumer live-room details', () => {
    const state = createSeed();
    const room = state.liveRooms.find((item) => item.productIds?.length);
    const productId = room.productIds[0];
    state.products.find((item) => item.id === productId).status = 'inactive';

    expect(liveRoomById(state, room.id).products.map((item) => item.id)).not.toContain(productId);
  });
});

describe('catalog transaction guards', () => {
  it.each([
    ['inactive', (product) => { product.status = 'inactive'; }, '商品已下架或当前门店不可售'],
    ['wrong store', (product) => { product.storeIds = ['TL1002']; }, '商品已下架或当前门店不可售'],
    ['zero stock', (product) => { product.stock = 0; }, '商品库存不足'],
  ])('rejects add-to-cart for a %s product', (_label, makeUnavailable, message) => {
    const state = createSeed();
    makeUnavailable(state.products[0]);

    expect(() => addCartItem(state, {
      productId: state.products[0].id,
      spec: state.products[0].specs[0],
      quantity: 1,
    })).toThrow(message);
    expect(state.cart).toHaveLength(0);
  });

  it('rejects an order when a cart product becomes unavailable without mutating records', () => {
    const state = createSeed();
    addCartItem(state, { productId: 1, spec: '单瓶装', quantity: 1 });
    state.products.find((item) => item.id === 1).status = 'inactive';
    const orderCount = state.orders.length;
    const sharingCount = state.profitSharingRecords.length;

    expect(() => createPaidOrder(state, {
      addressId: 'ADDR1',
      delivery: 'express',
      paymentAttemptId: 'catalog-guard-001',
    })).toThrow('商品已下架或当前门店不可售');
    expect(state.orders).toHaveLength(orderCount);
    expect(state.profitSharingRecords).toHaveLength(sharingCount);
    expect(state.cart).toEqual([{ productId: 1, spec: '单瓶装', quantity: 1 }]);
  });

  it('rejects attaching an unavailable product to a new store live room', () => {
    const state = createSeed();
    state.products.find((item) => item.id === 1).storeIds = ['TL1002'];

    expect(() => createLiveRoom(state, {
      storeId: 'TL1001',
      title: '门店养护专场',
      anchor: '门店顾问',
      productIds: [1],
    })).toThrow('直播商品已下架或当前门店不可售');
  });
});
