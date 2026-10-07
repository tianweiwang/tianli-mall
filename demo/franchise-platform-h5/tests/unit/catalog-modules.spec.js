import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { createSeed } from '../../src/mock/seed';
import { demoStore } from '../../src/mock/store';
import * as selectors from '../../src/mock/selectors';
import CustomerCategory from '../../src/views/customer/Category.vue';
import CustomerHome from '../../src/views/customer/Home.vue';
import CustomerProductDetail from '../../src/views/customer/ProductDetail.vue';
import CustomerServiceDetail from '../../src/views/customer/ServiceDetail.vue';
import BrandProducts from '../../src/views/brand/Products.vue';
import StoreProducts from '../../src/views/store/Products.vue';
import StoreLive from '../../src/views/store/Live.vue';

const elementStubs = {
  AdminStatusTag: true,
  Activity: true,
  'el-button': true,
  'el-drawer': true,
  'el-input': true,
  'el-option': true,
  'el-select': true,
  'el-table': true,
  'el-table-column': true,
};

describe('dual catalog seed', () => {
  it('seeds IGOOD products with series, unit and delivery type', () => {
    const state = createSeed();

    expect(state.products).toHaveLength(11);
    expect(state.products.every((item) => item.series === '维养系列')).toBe(true);
    expect(state.products.find((item) => item.id === 1)).toMatchObject({
      name: 'IGOOD修复液',
      unit: '瓶',
      priceCents: 39800,
      deliveryType: 'express',
    });
    expect(state.products.find((item) => item.id === 8)).toMatchObject({
      name: 'IGOOD平衡床垫',
      unit: '张',
      priceCents: 6980000,
      deliveryType: 'large',
    });
  });

  it('seeds services across six series with unit and package pricing', () => {
    const state = createSeed();

    expect(state.services).toHaveLength(31);
    expect(selectors.serviceSeriesList(state)).toEqual([
      '评估系列',
      '传统系列',
      '特色系列',
      '经典系列',
      '精品系列',
      '定制系列',
    ]);
    expect(selectors.servicesForSeries(state, '特色系列')).toHaveLength(7);
    expect(selectors.serviceById(state, 'SV1004')).toMatchObject({
      series: '传统系列',
      name: '古法刮痧',
      durationMinutes: 30,
      unitPriceCents: 9800,
      packagePriceCents: 68000,
      packageCount: 10,
      packageUnit: '次',
    });
    expect(selectors.serviceById(state, 'SV1018')).toMatchObject({
      series: '精品系列',
      packageCount: 1,
      packageUnit: '套',
    });
    const quote = selectors.serviceById(state, 'SV1029');
    expect(quote.priceModel).toBe('quote');
    expect(quote.unitPriceCents).toBe(38000);
    expect(quote.packagePriceCents).toBeUndefined();
  });

  it('reports slot availability and catalog overview', () => {
    const state = createSeed();

    expect(selectors.serviceAvailability(state, 'TL1001', 'SV1004')).toMatchObject({
      slotCount: 1,
      remainingTotal: 2,
      hasOnlineBooking: true,
    });
    expect(selectors.serviceAvailability(state, 'TL1002', 'SV1012')).toMatchObject({
      slotCount: 0,
      remainingTotal: 0,
      hasOnlineBooking: false,
    });
    expect(selectors.catalogOverview(state)).toMatchObject({
      serviceCount: 31,
      productCount: 11,
      seriesCount: 6,
      activeStoreCount: 2,
    });
  });
});

describe('catalog pages', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it('loads consumer service routes as concrete pages', async () => {
    const routes = [
      ['/c/store/TL1001/services/SV1004', 'CustomerServiceDetail'],
    ];

    for (const [path, componentName] of routes) {
      const route = createRouter().match(path);
      const loader = route.matched.at(-1).components.default;
      const component = await loader();
      expect(component.default.name).toBe(componentName);
    }
  });


  it('renders service detail price cards and availability', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/services/SV1004');
    const wrapper = shallowMount(CustomerServiceDetail, {
      router,
      stubs: { CalendarDays: true, Check: true, Clock3: true, MapPin: true, Tag: true },
    });

    expect(wrapper.vm.service.name).toBe('古法刮痧');
    expect(wrapper.text()).toContain('¥98.00');
    expect(wrapper.text()).toContain('¥680.00');
    expect(wrapper.text()).toContain('当前可在线预约');
  });

  it('shows a quote price for custom series services', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/services/SV1029');
    const wrapper = shallowMount(CustomerServiceDetail, {
      router,
      stubs: { CalendarDays: true, Check: true, Clock3: true, MapPin: true, Tag: true },
    });

    expect(wrapper.text()).toContain('¥380.00 起');
    expect(wrapper.text()).toContain('到店评估后按方案报价');
  });

  it('switches the customer category page between services and products', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/category');
    const wrapper = shallowMount(CustomerCategory, {
      router,
      stubs: { ProductCard: true, ServiceCard: true },
    });

    expect(wrapper.vm.tab).toBe('products');
    expect(wrapper.vm.visibleProducts).toHaveLength(11);
    wrapper.setData({ tab: 'services' });
    expect(wrapper.vm.visibleServices).toHaveLength(31);
    wrapper.setData({ selectedSeries: '传统系列' });
    expect(wrapper.vm.visibleServices.map((item) => item.id)).toEqual(['SV1004', 'SV1005', 'SV1006']);
  });

  it('opens the customer category page on the services tab from query', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/category?tab=services&series=精品系列');
    const wrapper = shallowMount(CustomerCategory, {
      router,
      stubs: { ProductCard: true, ServiceCard: true },
    });

    expect(wrapper.vm.tab).toBe('services');
    expect(wrapper.vm.selectedSeries).toBe('精品系列');
    expect(wrapper.vm.visibleServices).toHaveLength(11);
  });

  it('defaults the brand catalog to services and switches to products', () => {
    const wrapper = shallowMount(BrandProducts, { stubs: elementStubs });

    expect(wrapper.vm.tab).toBe('services');
    expect(wrapper.vm.visibleServices).toHaveLength(31);
    wrapper.setData({ tab: 'products' });
    expect(wrapper.vm.visibleProducts).toHaveLength(11);
    expect(wrapper.text()).toContain('服务项目');
  });

  it('creates and toggles catalog items from the brand page', async () => {
    const confirm = vi.fn().mockResolvedValue();
    const success = vi.fn();
    const wrapper = shallowMount(BrandProducts, {
      stubs: { ...elementStubs, CatalogEditorDialog: true },
      mocks: { $confirm: confirm, $message: { success, error: vi.fn() } },
    });

    wrapper.vm.openCreate();
    expect(wrapper.vm.editorOpen).toBe(true);
    expect(wrapper.vm.editingItem).toBeNull();

    wrapper.setData({ tab: 'products' });
    wrapper.vm.saveCatalogItem({
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
      storeIds: ['TL1001'],
    });
    const created = demoStore.state.data.products.find((item) => item.name === '门店护理组合装');
    expect(created).toMatchObject({ status: 'active', storeIds: ['TL1001'] });

    await wrapper.vm.toggleStatus(created);
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(demoStore.state.data.products.find((item) => item.id === created.id).status)
      .toBe('inactive');
    expect(success).toHaveBeenCalled();
  });

  it('shows store availability in the store catalog', () => {
    const wrapper = shallowMount(StoreProducts, { stubs: elementStubs });
    const service = selectors.serviceById(demoStore.state.data, 'SV1004');

    expect(wrapper.vm.tab).toBe('services');
    expect(wrapper.vm.availabilityText(service)).toContain('剩余 2 个名额');
  });

  it('filters customer and store catalog pages by status and store scope', async () => {
    demoStore.commit((draft) => {
      draft.products[0].storeIds = ['TL1002'];
      draft.services[0].status = 'inactive';
    });
    const router = createRouter();
    await router.push('/c/store/TL1001/category');
    const category = shallowMount(CustomerCategory, {
      router,
      stubs: { ProductCard: true, ServiceCard: true },
    });
    const home = shallowMount(CustomerHome, {
      router,
      stubs: {
        Activity: true,
        CalendarDays: true,
        ChevronRight: true,
        Clock3: true,
        MapPin: true,
        ProductCard: true,
        Search: true,
        ServiceCard: true,
        Sparkles: true,
        Video: true,
        Watch: true,
      },
    });
    const store = shallowMount(StoreProducts, { stubs: elementStubs });
    const storeLive = shallowMount(StoreLive, {
      stubs: {
        AdminMetricStrip: true,
        AdminStatusTag: true,
        'el-button': true,
        'el-drawer': true,
        'el-input': true,
        'el-option': true,
        'el-select': true,
        'el-table': true,
        'el-table-column': true,
      },
    });

    expect(category.vm.products).toHaveLength(10);
    expect(category.vm.services).toHaveLength(30);
    expect(home.vm.products).toHaveLength(10);
    expect(store.vm.products).toHaveLength(10);
    expect(store.vm.services).toHaveLength(30);
    expect(storeLive.vm.products).toHaveLength(10);
  });

  it('hides direct detail routes when an item is not available at that store', async () => {
    demoStore.commit((draft) => {
      draft.products[0].storeIds = ['TL1001'];
      draft.services[0].storeIds = ['TL1001'];
    });
    const productRouter = createRouter();
    await productRouter.push('/c/store/TL1002/product/1');
    const product = shallowMount(CustomerProductDetail, {
      router: productRouter,
      stubs: { Minus: true, PackageCheck: true, Plus: true, Truck: true },
    });
    const serviceRouter = createRouter();
    await serviceRouter.push('/c/store/TL1002/services/SV1001');
    const service = shallowMount(CustomerServiceDetail, {
      router: serviceRouter,
      stubs: { CalendarDays: true, Check: true, Clock3: true, MapPin: true, Tag: true },
    });

    expect(product.vm.product).toBeUndefined();
    expect(product.text()).toContain('商品已下架或当前门店不可用');
    expect(service.vm.service).toBeUndefined();
    expect(service.text()).toContain('服务项目不存在或已下架');
  });
});
