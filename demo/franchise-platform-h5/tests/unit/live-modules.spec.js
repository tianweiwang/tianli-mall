import { shallowMount } from '@vue/test-utils';
import { createRouter } from '../../src/router';
import { createSeed } from '../../src/mock/seed';
import { demoStore } from '../../src/mock/store';
import * as selectors from '../../src/mock/selectors';
import { bumpLiveStats, createLiveRoom } from '../../src/mock/transitions';
import CustomerLive from '../../src/views/customer/Live.vue';
import CustomerLiveRoom from '../../src/views/customer/LiveRoom.vue';

describe('live seed and selectors', () => {
  it('seeds live rooms per store with joined products', () => {
    const state = createSeed();

    expect(state.liveRooms).toHaveLength(4);
    expect(state.sequence.live).toBe(2002);
    expect(selectors.liveRoomsForStore(state, 'TL1001')).toHaveLength(2);
    expect(selectors.liveRoomById(state, 'LR1001').products.map((item) => item.id)).toEqual([3, 1]);
    expect(selectors.liveRoomById(state, 'LR-NOT-FOUND')).toBeNull();
  });

  it('aggregates brand live overview', () => {
    const overview = selectors.liveBrandOverview(createSeed());

    expect(overview).toMatchObject({
      roomCount: 4,
      liveCount: 2,
      upcomingCount: 1,
      endedCount: 1,
      viewerCount: 1112,
      likeCount: 4690,
    });
  });
});

describe('live transitions', () => {
  it('bumps stats only for a live room', () => {
    const state = createSeed();
    const room = state.liveRooms.find((item) => item.id === 'LR1001');
    const beforeViewers = room.viewerCount;
    const beforeLikes = room.likeCount;

    bumpLiveStats(state, { roomId: 'LR1001', likes: 5, viewers: 20 });

    expect(room.viewerCount).toBe(beforeViewers + 20);
    expect(room.likeCount).toBe(beforeLikes + 5);

    expect(() => bumpLiveStats(state, { roomId: 'LR1002', likes: 1 }))
      .toThrow('直播间当前不在直播中');
  });

  it('creates an upcoming room for an active store only', () => {
    const state = createSeed();
    const before = state.liveRooms.length;

    const room = createLiveRoom(state, {
      storeId: 'TL1001',
      title: '测试直播场次',
      anchor: '演示主播',
      productIds: [3, 4],
    });

    expect(room).toMatchObject({
      id: 'LR2003',
      storeId: 'TL1001',
      title: '测试直播场次',
      status: 'upcoming',
      viewerCount: 0,
      likeCount: 0,
      productIds: [3, 4],
    });
    expect(state.liveRooms).toHaveLength(before + 1);

    expect(() => createLiveRoom(state, {
      storeId: 'TL1003',
      title: '停用门店',
      anchor: '演示主播',
      productIds: [],
    })).toThrow('当前门店不可用');
  });
});

describe('live pages', () => {
  beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    demoStore.reset();
  });

  it.each([
    ['/c/store/TL1001/live', 'CustomerLive'],
    ['/c/store/TL1001/live/LR1001', 'CustomerLiveRoom'],
    ['/store/live', 'StoreLive'],
    ['/brand/live', 'BrandLive'],
  ])('loads %s as a concrete page', async (path, componentName) => {
    const route = createRouter().match(path);
    const loader = route.matched.at(-1).components.default;
    const component = await loader();

    expect(component.default.name).toBe(componentName);
  });

  it('lists only the current store live rooms', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/live');
    const wrapper = shallowMount(CustomerLive, { router, stubs: { LiveRoomCard: true } });

    expect(wrapper.vm.liveRooms.map((item) => item.id)).toEqual(['LR1001']);
    expect(wrapper.vm.upcomingRooms.map((item) => item.id)).toEqual(['LR1002']);
  });

  it('renders the live player and product shelf for a live room', async () => {
    const router = createRouter();
    await router.push('/c/store/TL1001/live/LR1001');
    const wrapper = shallowMount(CustomerLiveRoom, {
      router,
      stubs: { ProductCard: true },
      mocks: { $message: { success: vi.fn(), error: vi.fn() } },
    });

    expect(wrapper.vm.room.title).toBe('天俪锦江店·健康好物专场');
    expect(wrapper.text()).toContain('直播中');
    expect(wrapper.vm.room.products).toHaveLength(2);
  });
});
