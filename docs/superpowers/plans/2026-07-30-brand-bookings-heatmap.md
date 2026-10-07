# Brand Bookings Heatmap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将品牌方全平台预约页升级为按门店和实际开放时段展示的运营热力总览，并保留现有明细查询和详情能力。

**Architecture:** 在 `mock/selectors.js` 中用纯函数聚合指标、时间列、门店行和关注项；独立展示组件只渲染聚合结果并发出时段选择事件；`Bookings.vue` 维护全局筛选、当前热力格和明细联动。演示状态通过种子版本升级补充确定性的当天数据，不增加接口或业务状态。

**Tech Stack:** Vue 2.7、Element UI 2、Lucide Vue、Vitest 4、Vue Test Utils 1、Playwright 1.62、OpenSpec 1.4

---

## File Map

- Modify: `demo/franchise-platform-h5/src/mock/selectors.js` - 预约运营聚合的唯一数据口径。
- Modify: `demo/franchise-platform-h5/src/mock/seed.js` - 保留现有数据并补充演示当天时段、预约和版本号。
- Create: `demo/franchise-platform-h5/src/components/admin/AdminBookingHeatmap.vue` - 纯展示、可键盘操作的热力矩阵。
- Modify: `demo/franchise-platform-h5/src/views/brand/Bookings.vue` - 筛选、指标、关注项、热力选择和明细抽屉编排。
- Create: `demo/franchise-platform-h5/tests/unit/brand-bookings-overview.spec.js` - 聚合口径与种子总览测试。
- Create: `demo/franchise-platform-h5/tests/unit/brand-booking-heatmap.spec.js` - 热力组件交互测试。
- Create: `demo/franchise-platform-h5/tests/unit/brand-bookings-page.spec.js` - 页面筛选和明细联动测试。
- Modify: `demo/franchise-platform-h5/tests/unit/brand-console.spec.js` - 更新新增种子数据后的品牌汇总断言。
- Modify: `demo/franchise-platform-h5/tests/unit/store-console.spec.js` - 更新新增种子数据后的门店预约断言。
- Modify: `demo/franchise-platform-h5/tests/e2e/brand-console.spec.js` - 增加热力下钻和详情回归场景。
- Modify: `demo/franchise-platform-h5/tests/e2e/full-demo.spec.js` - 更新重置后的确定性预约数量。
- Modify: `openspec/changes/enhance-brand-bookings-heatmap/tasks.md` - 每项实现和验证通过后更新任务状态。

## Task 1: Lock the aggregation contract with tests

**Files:**
- Create: `demo/franchise-platform-h5/tests/unit/brand-bookings-overview.spec.js`
- Modify: `demo/franchise-platform-h5/src/mock/selectors.js`

- [ ] **Step 1: Write the failing selector tests**

Create `tests/unit/brand-bookings-overview.spec.js` with a compact synthetic state so the aggregation contract does not depend on seed totals:

```js
import { brandBookingOperations } from '../../src/mock/selectors';

function createOperationsState() {
  return {
    demoNow: '2026-07-30T09:30:00+08:00',
    stores: [
      { id: 'S1', name: '中心店', status: 'active', openHours: '09:00-21:00' },
      { id: 'S2', name: '滨河店', status: 'active', openHours: '10:00-20:00' },
      { id: 'S3', name: '北城店', status: 'disabled', openHours: '09:30-20:30' },
    ],
    services: [
      { id: 'A', name: '体态评估' },
      { id: 'B', name: '舒缓体验' },
    ],
    slotInventory: [
      { id: 'S1A10', storeId: 'S1', serviceId: 'A', date: '2026-07-30', start: '10:00', end: '10:45', capacity: 4, booked: 3 },
      { id: 'S1B10', storeId: 'S1', serviceId: 'B', date: '2026-07-30', start: '10:00', end: '10:45', capacity: 2, booked: 1 },
      { id: 'S1A14', storeId: 'S1', serviceId: 'A', date: '2026-07-30', start: '14:00', end: '15:00', capacity: 2, booked: 2 },
      { id: 'S2A11', storeId: 'S2', serviceId: 'A', date: '2026-07-30', start: '11:00', end: '11:45', capacity: 4, booked: 1 },
      { id: 'OTHER', storeId: 'S2', serviceId: 'A', date: '2026-07-31', start: '09:00', end: '09:45', capacity: 5, booked: 5 },
    ],
    bookings: [
      { id: 'B1', storeId: 'S1', serviceId: 'A', date: '2026-07-30', start: '10:00', end: '10:45', status: 'pending' },
      { id: 'B2', storeId: 'S1', serviceId: 'B', date: '2026-07-30', start: '10:00', end: '10:45', status: 'verified' },
      { id: 'B3', storeId: 'S1', serviceId: 'A', date: '2026-07-30', start: '14:00', end: '15:00', status: 'expired' },
      { id: 'B4', storeId: 'S2', serviceId: 'A', date: '2026-07-30', start: '11:00', end: '11:45', status: 'pending' },
      { id: 'B5', storeId: 'S2', serviceId: 'A', date: '2026-07-31', start: '09:00', end: '09:45', status: 'pending' },
    ],
  };
}

describe('brand booking operations', () => {
  it('defaults to demoNow and merges actual slots by start and end', () => {
    const result = brandBookingOperations(createOperationsState());

    expect(result.date).toBe('2026-07-30');
    expect(result.columns.map((item) => item.key)).toEqual([
      '10:00-10:45',
      '11:00-11:45',
      '14:00-15:00',
    ]);
    expect(result.metrics).toMatchObject({
      bookingCount: 4,
      activeStoreCount: 2,
      booked: 7,
      capacity: 12,
      occupancyRate: 58,
      pendingCount: 2,
      nextPendingTime: '10:00',
      fullSlotCount: 1,
      expiredCount: 1,
      attentionCount: 2,
    });

    const merged = result.rows[0].cells[0];
    expect(merged).toMatchObject({
      key: 'S1:10:00-10:45',
      booked: 4,
      capacity: 6,
      serviceLabel: '2 个服务',
      tone: 'busy',
      selectable: true,
    });
    expect(result.rows[2].cells.every((item) => item.tone === 'off')).toBe(true);
  });

  it('splits the same time by service filter without using booking status', () => {
    const state = createOperationsState();
    const result = brandBookingOperations(state, {
      date: '2026-07-30',
      storeId: 'S1',
      serviceId: 'A',
    });

    expect(result.rows).toHaveLength(1);
    expect(result.rows[0].cells[0]).toMatchObject({
      booked: 3,
      capacity: 4,
      serviceLabel: '体态评估',
      tone: 'busy',
    });
    expect(result.metrics.bookingCount).toBe(2);
    expect(result.metrics.capacity).toBe(6);
  });

  it('returns a usable empty state when no slot is open', () => {
    const result = brandBookingOperations(createOperationsState(), { date: '2026-08-01' });

    expect(result.columns).toEqual([]);
    expect(result.rows).toEqual([]);
    expect(result.attention).toEqual([]);
    expect(result.metrics).toMatchObject({ bookingCount: 0, booked: 0, capacity: 0 });
  });

  it('treats over-capacity inventory as full and needing attention', () => {
    const state = createOperationsState();
    state.slotInventory.find((item) => item.id === 'S2A11').booked = 5;
    const result = brandBookingOperations(state, { date: '2026-07-30', storeId: 'S2' });

    expect(result.rows[0].cells.find((item) => item.selectable)).toMatchObject({
      booked: 5,
      capacity: 4,
      tone: 'full',
    });
    expect(result.metrics.fullSlotCount).toBe(1);
  });
});
```

- [ ] **Step 2: Run the focused test and verify the expected failure**

Run from `demo/franchise-platform-h5`:

```powershell
npm run test -- tests/unit/brand-bookings-overview.spec.js
```

Expected: FAIL because `brandBookingOperations` is not exported.

- [ ] **Step 3: Implement the pure aggregation selector**

Append the following implementation to `src/mock/selectors.js`:

```js
const ALL_FILTER = 'all';

function slotColumn(slot) {
  return {
    key: `${slot.start}-${slot.end}`,
    start: slot.start,
    end: slot.end,
    label: `${slot.start}-${slot.end}`,
  };
}

function matchesBookingScope(booking, { date, storeId, serviceId }) {
  return booking.date === date
    && (storeId === ALL_FILTER || booking.storeId === storeId)
    && (serviceId === ALL_FILTER || booking.serviceId === serviceId);
}

function heatTone(booked, capacity) {
  if (capacity <= 0) return 'off';
  if (booked >= capacity) return 'full';
  if ((booked / capacity) >= 0.6) return 'busy';
  return 'available';
}

export function brandBookingOperations(state, filters = {}) {
  const scope = {
    date: filters.date || state.demoNow.slice(0, 10),
    storeId: filters.storeId || ALL_FILTER,
    serviceId: filters.serviceId || ALL_FILTER,
  };
  const stores = state.stores.filter(
    (store) => scope.storeId === ALL_FILTER || store.id === scope.storeId,
  );
  const slots = state.slotInventory.filter((slot) => (
    slot.date === scope.date
      && (scope.storeId === ALL_FILTER || slot.storeId === scope.storeId)
      && (scope.serviceId === ALL_FILTER || slot.serviceId === scope.serviceId)
  ));
  const bookings = state.bookings.filter((booking) => matchesBookingScope(booking, scope));
  const columnMap = new Map();
  slots.forEach((slot) => columnMap.set(slotColumn(slot).key, slotColumn(slot)));
  const columns = [...columnMap.values()].sort(
    (left, right) => left.start.localeCompare(right.start) || left.end.localeCompare(right.end),
  );
  const pendingBookings = bookings.filter((item) => item.status === 'pending');
  const expiredCount = bookings.filter((item) => item.status === 'expired').length;
  const activeStoreCount = new Set(bookings.filter((booking) => (
    state.stores.find((store) => store.id === booking.storeId)?.status === 'active'
  )).map((booking) => booking.storeId)).size;
  const expiredAttention = expiredCount
    ? [{ type: 'expired', key: 'expired', label: `${expiredCount} 条预约已过期`, count: expiredCount }]
    : [];

  if (!columns.length) {
    return {
      date: scope.date,
      metrics: {
        bookingCount: bookings.length,
        activeStoreCount,
        booked: 0,
        capacity: 0,
        occupancyRate: 0,
        pendingCount: pendingBookings.length,
        nextPendingTime: '',
        fullSlotCount: 0,
        expiredCount,
        attentionCount: expiredCount,
      },
      columns: [],
      rows: [],
      attention: expiredAttention,
    };
  }

  const rows = stores.map((store) => {
    const storeBookings = bookings.filter((booking) => booking.storeId === store.id);
    const pending = storeBookings.filter((booking) => booking.status === 'pending').length;
    const verified = storeBookings.filter((booking) => booking.status === 'verified').length;
    const cells = columns.map((column) => {
      const matching = slots.filter((slot) => (
        slot.storeId === store.id && `${slot.start}-${slot.end}` === column.key
      ));
      const booked = sumBy(matching, 'booked');
      const capacity = sumBy(matching, 'capacity');
      const serviceNames = [...new Set(matching.map((slot) => (
        state.services.find((service) => service.id === slot.serviceId)?.name || slot.serviceId
      )))];
      const selectable = store.status === 'active' && capacity > 0;
      return {
        ...column,
        key: `${store.id}:${column.key}`,
        storeId: store.id,
        storeName: store.name,
        booked,
        capacity,
        serviceLabel: serviceNames.length > 1 ? `${serviceNames.length} 个服务` : (serviceNames[0] || '未开放'),
        tone: selectable ? heatTone(booked, capacity) : 'off',
        selectable,
        testId: `booking-heat-cell-${store.id}-${column.key}`,
      };
    });
    return {
      storeId: store.id,
      storeName: store.name,
      storeStatus: store.status,
      openHours: store.openHours,
      bookingCount: storeBookings.length,
      arrivalRate: pending + verified ? Math.round((verified / (pending + verified)) * 100) : 0,
      cells,
    };
  });
  const booked = sumBy(slots, 'booked');
  const capacity = sumBy(slots.filter((slot) => slot.capacity > 0), 'capacity');
  const fullCells = rows.flatMap((row) => row.cells.filter((cell) => cell.tone === 'full'));
  const attention = fullCells.map((cell) => ({
    type: 'full-slot',
    key: cell.key,
    label: `${cell.storeName} ${cell.label} 已满`,
    cell,
  }));
  attention.push(...expiredAttention);

  return {
    date: scope.date,
    metrics: {
      bookingCount: bookings.length,
      activeStoreCount,
      booked,
      capacity,
      occupancyRate: capacity ? Math.round((booked / capacity) * 100) : 0,
      pendingCount: pendingBookings.length,
      nextPendingTime: [...pendingBookings].sort((left, right) => left.start.localeCompare(right.start))[0]?.start || '',
      fullSlotCount: fullCells.length,
      expiredCount,
      attentionCount: fullCells.length + expiredCount,
    },
    columns,
    rows,
    attention,
  };
}
```

- [ ] **Step 4: Run the focused test and verify it passes**

```powershell
npm run test -- tests/unit/brand-bookings-overview.spec.js
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit the aggregation contract**

```powershell
git add -- demo/franchise-platform-h5/src/mock/selectors.js demo/franchise-platform-h5/tests/unit/brand-bookings-overview.spec.js
git commit -m "feat: aggregate brand booking operations"
```

## Task 2: Add deterministic current-day demo data

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`
- Modify: `demo/franchise-platform-h5/tests/unit/brand-bookings-overview.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/brand-console.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/store-console.spec.js`
- Modify: `demo/franchise-platform-h5/tests/e2e/full-demo.spec.js`

- [ ] **Step 1: Add failing assertions for the refreshed seed**

Add this test to `brand-bookings-overview.spec.js`:

```js
import { createSeed, DEMO_VERSION } from '../../src/mock/seed';

it('seeds a repeatable current-day overview without deleting historical records', () => {
  const state = createSeed();
  const result = brandBookingOperations(state);

  expect(DEMO_VERSION).toBe(2);
  expect(state.bookings).toHaveLength(15);
  expect(state.bookings.some((item) => item.id === 'BK202607302001')).toBe(true);
  expect(result.date).toBe('2026-07-30');
  expect(result.metrics).toMatchObject({
    bookingCount: 10,
    booked: 9,
    capacity: 14,
    pendingCount: 6,
    fullSlotCount: 2,
    expiredCount: 1,
    attentionCount: 3,
  });
});
```

Update existing totals to the post-seed values:

```js
// tests/unit/brand-console.spec.js
bookingCount: 15,
pendingBookingCount: 8,
verifiedBookingCount: 4,
arrivalRate: 33,

// tests/unit/store-console.spec.js, TL1001 overview
pendingBookingCount: 4,
verifiedBookingCount: 3,
arrivalRate: 43,

// tests/e2e/full-demo.spec.js reset assertion
{ orders: 5, bookings: 15, sharing: 5, activeRole: 'customer' }
```

- [ ] **Step 2: Run the affected tests and verify they fail against version 1**

```powershell
npm run test -- tests/unit/brand-bookings-overview.spec.js tests/unit/brand-console.spec.js tests/unit/store-console.spec.js
```

Expected: FAIL on `DEMO_VERSION`, booking totals, and current-day overview.

- [ ] **Step 3: Add the exact current-day slots and bookings**

In `seed.js`, set `DEMO_VERSION = 2`, add this helper above `createSeed`, create the arrays inside `createSeed`, prepend them to the existing `slotInventory` and `bookings`, and set `sequence.booking` to `2015`:

```js
function createSeedBooking(id, slot, contactName, phone, status, verifyCode) {
  const booking = {
    id,
    storeId: slot.storeId,
    serviceId: slot.serviceId,
    slotId: slot.id,
    date: slot.date,
    start: slot.start,
    end: slot.end,
    contactName,
    phone,
    status,
    verifyCode,
    createdAt: DEMO_NOW,
    released: status === 'expired' || status === 'cancelled',
  };
  if (status === 'verified') booking.verifiedAt = DEMO_NOW;
  return booking;
}

const todaySlots = [
  { id: 'SLT1001', storeId: 'TL1001', serviceId: 'SV1001', date: '2026-07-30', start: '10:00', end: '10:45', capacity: 3, booked: 2 },
  { id: 'SLT1002', storeId: 'TL1001', serviceId: 'SV1002', date: '2026-07-30', start: '14:00', end: '15:00', capacity: 2, booked: 2 },
  { id: 'SLT1003', storeId: 'TL1001', serviceId: 'SV1001', date: '2026-07-30', start: '16:00', end: '16:45', capacity: 2, booked: 1 },
  { id: 'SLT2001', storeId: 'TL1002', serviceId: 'SV1001', date: '2026-07-30', start: '11:00', end: '11:45', capacity: 2, booked: 2 },
  { id: 'SLT2002', storeId: 'TL1002', serviceId: 'SV1003', date: '2026-07-30', start: '14:00', end: '14:30', capacity: 3, booked: 1 },
  { id: 'SLT2003', storeId: 'TL1002', serviceId: 'SV1001', date: '2026-07-30', start: '16:00', end: '16:45', capacity: 2, booked: 1 },
];

const todayBookings = [
  createSeedBooking('BK202607302006', todaySlots[0], '王女士', '138****3216', 'pending', '826106'),
  createSeedBooking('BK202607302007', todaySlots[0], '刘先生', '136****4821', 'verified', '826107'),
  createSeedBooking('BK202607302008', todaySlots[1], '何女士', '139****7052', 'pending', '826108'),
  createSeedBooking('BK202607302009', todaySlots[1], '郑先生', '137****1946', 'pending', '826109'),
  createSeedBooking('BK202607302010', todaySlots[1], '孙女士', '135****6384', 'expired', '826110'),
  createSeedBooking('BK202607302011', todaySlots[2], '罗女士', '138****9025', 'verified', '826111'),
  createSeedBooking('BK202607302012', todaySlots[3], '高先生', '136****2579', 'pending', '826112'),
  createSeedBooking('BK202607302013', todaySlots[3], '唐女士', '139****8137', 'pending', '826113'),
  createSeedBooking('BK202607302014', todaySlots[4], '周女士', '137****5468', 'verified', '826114'),
  createSeedBooking('BK202607302015', todaySlots[5], '赵女士', '135****1724', 'pending', '826115'),
];
```

Insert the two spread entries as the first element of the existing arrays. The following existing objects are anchors; leave all later existing objects directly after them without editing their fields:

```js
slotInventory: [
  ...todaySlots,
  {
    id: 'SL1001',
    storeId: 'TL1001',
    serviceId: 'SV1001',
    date: '2026-07-31',
    start: '10:00',
    end: '10:45',
    capacity: 4,
    booked: 1,
  },
],
bookings: [
  ...todayBookings,
  {
    id: 'BK202607302001',
    storeId: 'TL1001',
    serviceId: 'SV1001',
    slotId: 'SL1001',
    date: '2026-07-31',
    start: '10:00',
    end: '10:45',
    contactName: '陈女士',
    phone: '138****2046',
    status: 'pending',
    verifyCode: '826101',
    createdAt: DEMO_NOW,
    released: false,
  },
],
```

- [ ] **Step 4: Run affected unit tests**

```powershell
npm run test -- tests/unit/brand-bookings-overview.spec.js tests/unit/brand-console.spec.js tests/unit/store-console.spec.js tests/unit/store.spec.js
```

Expected: all selected tests pass; store restoration tests continue to compare against the exact version 2 seed.

- [ ] **Step 5: Commit the deterministic seed**

```powershell
git add -- demo/franchise-platform-h5/src/mock/seed.js demo/franchise-platform-h5/tests/unit/brand-bookings-overview.spec.js demo/franchise-platform-h5/tests/unit/brand-console.spec.js demo/franchise-platform-h5/tests/unit/store-console.spec.js demo/franchise-platform-h5/tests/e2e/full-demo.spec.js
git commit -m "feat: seed brand booking heatmap data"
```

## Task 3: Build the accessible heatmap component

**Files:**
- Create: `demo/franchise-platform-h5/tests/unit/brand-booking-heatmap.spec.js`
- Create: `demo/franchise-platform-h5/src/components/admin/AdminBookingHeatmap.vue`

- [ ] **Step 1: Write the failing component test**

```js
import { shallowMount } from '@vue/test-utils';
import AdminBookingHeatmap from '../../src/components/admin/AdminBookingHeatmap.vue';

const columns = [{ key: '10:00-10:45', label: '10:00-10:45' }];
const rows = [
  {
    storeId: 'S1', storeName: '中心店', storeStatus: 'active', openHours: '09:00-21:00', bookingCount: 2, arrivalRate: 50,
    cells: [{ key: 'S1:10:00-10:45', label: '10:00-10:45', storeId: 'S1', storeName: '中心店', booked: 2, capacity: 3, serviceLabel: '体态评估', tone: 'busy', selectable: true, testId: 'booking-heat-cell-S1-10:00-10:45' }],
  },
  {
    storeId: 'S2', storeName: '北城店', storeStatus: 'disabled', openHours: '09:30-20:30', bookingCount: 0, arrivalRate: 0,
    cells: [{ key: 'S2:10:00-10:45', label: '10:00-10:45', storeId: 'S2', storeName: '北城店', booked: 0, capacity: 0, serviceLabel: '未开放', tone: 'off', selectable: false, testId: 'booking-heat-cell-S2-10:00-10:45' }],
  },
];

describe('AdminBookingHeatmap', () => {
  it('emits selectable cells and exposes native keyboard buttons', async () => {
    const wrapper = shallowMount(AdminBookingHeatmap, { propsData: { columns, rows, selectedKey: '' } });
    const cell = wrapper.find('[data-testid="booking-heat-cell-S1-10:00-10:45"]');

    expect(cell.element.tagName).toBe('BUTTON');
    expect(cell.attributes('aria-label')).toContain('中心店');
    await cell.trigger('click');
    expect(wrapper.emitted('select')[0][0]).toMatchObject({ key: 'S1:10:00-10:45' });
  });

  it('does not render unavailable cells as buttons', () => {
    const wrapper = shallowMount(AdminBookingHeatmap, { propsData: { columns, rows, selectedKey: '' } });

    expect(wrapper.find('[data-testid="booking-heat-cell-S2-10:00-10:45"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('门店停约');
  });
});
```

- [ ] **Step 2: Run the component test and verify it fails**

```powershell
npm run test -- tests/unit/brand-booking-heatmap.spec.js
```

Expected: FAIL because `AdminBookingHeatmap.vue` does not exist.

- [ ] **Step 3: Implement the component with semantic table and native buttons**

Create `AdminBookingHeatmap.vue` with this contract:

```vue
<template>
  <section class="admin-panel admin-booking-heatmap" aria-label="门店预约时段热力">
    <header class="admin-panel__header">
      <div><h2>门店时段热力</h2><p>格内显示已预约 / 容量，横轴按实际开放时段合并</p></div>
      <div class="admin-booking-heatmap__legend" aria-label="热力状态图例">
        <span><i class="is-available" />充足</span>
        <span><i class="is-busy" />繁忙</span>
        <span><i class="is-full" />已满</span>
        <span><i class="is-off" />未开放</span>
      </div>
    </header>
    <div v-if="columns.length" class="admin-booking-heatmap__scroll">
      <table>
        <thead><tr><th scope="col">门店</th><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th><th scope="col">门店合计</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.storeId">
            <th scope="row"><strong>{{ row.storeName }}</strong><span>{{ row.storeStatus === 'active' ? `营业中 · ${row.openHours}` : '已停用' }}</span></th>
            <td v-for="cell in row.cells" :key="cell.key">
              <button
                v-if="cell.selectable"
                type="button"
                :class="['admin-booking-heatmap__cell', `is-${cell.tone}`, { 'is-selected': selectedKey === cell.key }]"
                :data-testid="cell.testId"
                :aria-pressed="String(selectedKey === cell.key)"
                :aria-label="`${cell.storeName} ${cell.label} ${cell.booked}/${cell.capacity} ${cell.serviceLabel}`"
                @click="$emit('select', cell)"
              ><strong>{{ cell.booked }} / {{ cell.capacity }}</strong><span>{{ cell.serviceLabel }}</span></button>
              <div v-else class="admin-booking-heatmap__cell is-off"><strong>—</strong><span>{{ row.storeStatus === 'active' ? '未开放' : '门店停约' }}</span></div>
            </td>
            <td class="admin-booking-heatmap__summary"><strong>{{ row.bookingCount }} 条</strong><span>到店率 {{ row.arrivalRate }}%</span></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else class="admin-empty">所选日期暂无开放时段</div>
  </section>
</template>

<script>
export default {
  name: 'AdminBookingHeatmap',
  props: {
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    selectedKey: { type: String, default: '' },
  },
};
</script>
```

Add scoped CSS with these exact layout constraints:

```css
.admin-booking-heatmap__legend { display:flex; align-items:center; gap:12px; color:var(--text-muted); font-size:11px; }
.admin-booking-heatmap__legend span { display:inline-flex; align-items:center; gap:5px; }
.admin-booking-heatmap__legend i { width:10px; height:10px; border:1px solid var(--border-strong); border-radius:2px; }
.admin-booking-heatmap__scroll { overflow-x:auto; }
table { width:100%; min-width:900px; border-collapse:separate; border-spacing:0; table-layout:fixed; }
th, td { padding:8px; border-right:1px solid var(--border); border-bottom:1px solid var(--border); text-align:center; }
thead th { height:44px; color:var(--text-regular); background:var(--surface-subtle); font-size:11px; font-weight:600; }
thead th:first-child, tbody th { position:sticky; z-index:2; left:0; width:160px; background:var(--surface); text-align:left; }
tbody th strong, tbody th span, .admin-booking-heatmap__cell strong, .admin-booking-heatmap__cell span, .admin-booking-heatmap__summary strong, .admin-booking-heatmap__summary span { display:block; }
tbody th strong { font-size:12px; }
tbody th span, .admin-booking-heatmap__summary span { margin-top:3px; color:var(--text-muted); font-size:10px; font-weight:400; }
.admin-booking-heatmap__cell { width:100%; min-height:58px; padding:7px; border:1px solid var(--border-strong); border-radius:var(--radius-sm); background:var(--surface-subtle); color:var(--text-regular); }
button.admin-booking-heatmap__cell { cursor:pointer; }
.admin-booking-heatmap__cell strong { font-size:14px; }
.admin-booking-heatmap__cell span { margin-top:4px; overflow:hidden; font-size:10px; text-overflow:ellipsis; white-space:nowrap; }
.admin-booking-heatmap__cell.is-available { color:#2367a7; background:#edf5ff; border-color:#bfdcff; }
.admin-booking-heatmap__cell.is-busy { color:#174f88; background:#cfe4ff; border-color:#8fc1ff; }
.admin-booking-heatmap__cell.is-full { color:#945d0c; background:#fff3df; border-color:#efc27f; }
.admin-booking-heatmap__cell.is-off { color:var(--text-muted); background:var(--surface-subtle); border-style:dashed; }
.admin-booking-heatmap__cell.is-selected { outline:2px solid var(--admin-primary); outline-offset:2px; }
.admin-booking-heatmap__summary { width:110px; }
tr:last-child th, tr:last-child td { border-bottom:0; }
th:last-child, td:last-child { border-right:0; }
```

- [ ] **Step 4: Run the component test**

```powershell
npm run test -- tests/unit/brand-booking-heatmap.spec.js
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit the component**

```powershell
git add -- demo/franchise-platform-h5/src/components/admin/AdminBookingHeatmap.vue demo/franchise-platform-h5/tests/unit/brand-booking-heatmap.spec.js
git commit -m "feat: add brand booking heatmap"
```

## Task 4: Integrate metrics, filters, attention, and detail drill-down

**Files:**
- Create: `demo/franchise-platform-h5/tests/unit/brand-bookings-page.spec.js`
- Modify: `demo/franchise-platform-h5/src/views/brand/Bookings.vue`

- [ ] **Step 1: Write failing page-state tests**

```js
import { shallowMount } from '@vue/test-utils';
import { demoStore } from '../../src/mock/store';
import Bookings from '../../src/views/brand/Bookings.vue';

function mountPage() {
  return shallowMount(Bookings, {
    stubs: {
      AdminBookingHeatmap: true,
      AdminMetricStrip: true,
      AdminStatusTag: true,
      CalendarDays: true,
      Gauge: true,
      RotateCcw: true,
      TriangleAlert: true,
      UserRoundCheck: true,
      'el-button': true,
      'el-date-picker': true,
      'el-drawer': true,
      'el-input': true,
      'el-option': true,
      'el-select': true,
      'el-table': true,
      'el-table-column': true,
    },
  });
}

describe('brand bookings page', () => {
  beforeEach(() => demoStore.reset());

  it('defaults to demoNow and drills a heat cell into the detail rows', async () => {
    const wrapper = mountPage();
    expect(wrapper.vm.date).toBe('2026-07-30');
    expect(wrapper.vm.operations.metrics.bookingCount).toBe(10);

    const cell = wrapper.vm.operations.rows
      .find((row) => row.storeId === 'TL1002').cells
      .find((item) => item.label === '11:00-11:45');
    wrapper.vm.selectHeatCell(cell);
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.selectedCell.key).toBe('TL1002:11:00-11:45');
    expect(wrapper.vm.visibleBookings).toHaveLength(2);
    expect(wrapper.vm.visibleBookings.every((item) => item.storeId === 'TL1002' && item.start === '11:00')).toBe(true);
  });

  it('keeps status and keyword filters out of heatmap capacity', async () => {
    const wrapper = mountPage();
    const capacity = wrapper.vm.operations.metrics.capacity;
    await wrapper.setData({ status: 'verified', keyword: '周女士' });

    expect(wrapper.vm.operations.metrics.capacity).toBe(capacity);
    expect(wrapper.vm.visibleBookings).toHaveLength(1);
    expect(wrapper.vm.visibleBookings[0].contactName).toBe('周女士');
  });

  it('clears a heat selection when its global scope changes', async () => {
    const wrapper = mountPage();
    wrapper.vm.selectHeatCell(wrapper.vm.operations.rows[0].cells.find((item) => item.selectable));
    await wrapper.setData({ serviceId: 'SV1002' });

    expect(wrapper.vm.selectedCell).toBeNull();
  });

  it('uses attention items as explicit drill-down actions', async () => {
    const wrapper = mountPage();
    wrapper.vm.selectAttention(wrapper.vm.operations.attention.find((item) => item.type === 'full-slot'));
    expect(wrapper.vm.selectedCell).not.toBeNull();

    wrapper.vm.selectAttention(wrapper.vm.operations.attention.find((item) => item.type === 'expired'));
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.selectedCell).toBeNull();
    expect(wrapper.vm.status).toBe('expired');
  });

  it('resets every filter to the deterministic default scope', async () => {
    const wrapper = mountPage();
    await wrapper.setData({
      date: '2026-07-31',
      keyword: '陈女士',
      serviceId: 'SV1001',
      status: 'pending',
      storeId: 'TL1001',
    });
    wrapper.vm.resetFilters();

    expect(wrapper.vm.$data).toMatchObject({
      date: '2026-07-30',
      keyword: '',
      selectedCell: null,
      serviceId: 'all',
      status: 'all',
      storeId: 'all',
    });
  });
});
```

- [ ] **Step 2: Run the page test and verify it fails**

```powershell
npm run test -- tests/unit/brand-bookings-page.spec.js
```

Expected: FAIL because the page has no operations selector, service filter, or heat selection.

- [ ] **Step 3: Wire the page state and computed data**

In `Bookings.vue`:

1. Import `AdminBookingHeatmap`, `AdminMetricStrip`, `brandBookingOperations`, and Lucide icons `CalendarDays`, `Gauge`, `RotateCcw`, `TriangleAlert`, `UserRoundCheck`.
2. Register both admin components and the icons used directly in the template.
3. Replace `data()` fields with the following superset:

```js
data() {
  return {
    date: demoStore.state.data.demoNow.slice(0, 10),
    drawerOpen: false,
    keyword: '',
    selectedBooking: null,
    selectedCell: null,
    serviceId: 'all',
    status: 'all',
    storeId: 'all',
    statusOptions: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
  };
},
watch: {
  date() { this.clearHeatSelection(); },
  serviceId() { this.clearHeatSelection(); },
  storeId() { this.clearHeatSelection(); },
},
```

4. Add these computed properties and replace the old `visibleBookings`:

```js
operations() {
  return brandBookingOperations(this.state, {
    date: this.date,
    storeId: this.storeId,
    serviceId: this.serviceId,
  });
},
metricItems() {
  const metrics = this.operations.metrics;
  return [
    { label: '当日预约', value: String(metrics.bookingCount), help: `覆盖 ${metrics.activeStoreCount} 家营业门店`, icon: CalendarDays, tone: 'primary' },
    { label: '预约容量', value: `${metrics.booked} / ${metrics.capacity}`, help: `整体占用率 ${metrics.occupancyRate}%`, icon: Gauge, tone: 'success' },
    { label: '待到店', value: String(metrics.pendingCount), help: metrics.nextPendingTime ? `最近一场 ${metrics.nextPendingTime}` : '当前无待到店预约', icon: UserRoundCheck, tone: 'warning' },
    { label: '需关注', value: String(metrics.attentionCount), help: `${metrics.fullSlotCount} 个已满时段 · ${metrics.expiredCount} 条已过期`, icon: TriangleAlert, tone: 'danger' },
  ];
},
visibleBookings() {
  const keyword = this.keyword.toLowerCase();
  return this.state.bookings.filter((booking) => {
    const matchesStore = this.storeId === 'all' || booking.storeId === this.storeId;
    const matchesService = this.serviceId === 'all' || booking.serviceId === this.serviceId;
    const matchesStatus = this.status === 'all' || booking.status === this.status;
    const matchesDate = booking.date === this.date;
    const matchesCell = !this.selectedCell || (
      booking.storeId === this.selectedCell.storeId
        && booking.start === this.selectedCell.start
        && booking.end === this.selectedCell.end
    );
    const searchable = `${booking.id} ${booking.contactName} ${booking.phone}`.toLowerCase();
    return matchesStore && matchesService && matchesStatus && matchesDate && matchesCell
      && (!keyword || searchable.includes(keyword));
  });
},
selectionSummary() {
  if (!this.selectedCell) return '';
  return `${this.selectedCell.storeName} · ${this.selectedCell.label} · ${this.selectedCell.booked}/${this.selectedCell.capacity}`;
},
```

5. Add these methods without changing the existing detail methods:

```js
clearHeatSelection() {
  this.selectedCell = null;
},
resetFilters() {
  this.date = this.state.demoNow.slice(0, 10);
  this.keyword = '';
  this.selectedCell = null;
  this.serviceId = 'all';
  this.status = 'all';
  this.storeId = 'all';
},
selectAttention(item) {
  if (item.type === 'full-slot') {
    this.selectHeatCell(item.cell);
  } else if (item.type === 'expired') {
    this.clearHeatSelection();
    this.status = 'expired';
  }
},
selectHeatCell(cell) {
  this.selectedCell = this.selectedCell?.key === cell.key ? null : cell;
},
```

- [ ] **Step 4: Replace the page's top-level layout while retaining the drawer**

Replace the current top filter section with the following block, then insert the metric, heatmap, attention, and detail-filter blocks immediately before the existing table section. The existing `el-table` and `el-drawer` blocks stay after these insertions.

```vue
<div class="admin-page brand-bookings">
  <section class="admin-filter-bar" aria-label="全平台预约筛选">
    <el-date-picker v-model="date" type="date" value-format="yyyy-MM-dd" :clearable="false" aria-label="预约日期" />
    <el-select v-model="storeId" aria-label="所属门店">
      <el-option label="全部门店" value="all" />
      <el-option v-for="store in state.stores" :key="store.id" :label="store.name" :value="store.id" />
    </el-select>
    <el-select v-model="serviceId" aria-label="服务项目">
      <el-option label="全部服务" value="all" />
      <el-option v-for="service in state.services" :key="service.id" :label="service.name" :value="service.id" />
    </el-select>
    <el-select v-model="status" aria-label="预约状态">
      <el-option label="全部状态" value="all" />
      <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
    </el-select>
    <el-button class="brand-bookings__reset" @click="resetFilters"><RotateCcw :size="14" />重置筛选</el-button>
  </section>

  <AdminMetricStrip :items="metricItems" />
  <AdminBookingHeatmap :columns="operations.columns" :rows="operations.rows" :selected-key="selectedCell ? selectedCell.key : ''" @select="selectHeatCell" />

  <section v-if="operations.attention.length" class="brand-bookings__attention" aria-label="预约关注事项">
    <strong><TriangleAlert :size="15" />需关注</strong>
    <button v-for="item in operations.attention" :key="item.key" type="button" @click="selectAttention(item)">{{ item.label }}</button>
  </section>

  <section class="admin-filter-bar brand-bookings__detail-filter" aria-label="预约明细筛选">
    <el-input v-model.trim="keyword" class="admin-filter-bar__search" clearable placeholder="预约编号、姓名或手机号" prefix-icon="el-icon-search" />
    <span v-if="selectionSummary" class="brand-bookings__selection">{{ selectionSummary }}<el-button type="text" @click="clearHeatSelection">清除时段筛选</el-button></span>
    <span class="admin-filter-bar__count">共 {{ visibleBookings.length }} 条</span>
  </section>
</div>
```

Add scoped styles for stable layout and avoid changing shared admin CSS:

```css
.brand-bookings__reset { display:inline-flex; align-items:center; gap:5px; margin-left:auto; }
.brand-bookings__attention { display:flex; min-height:44px; align-items:center; gap:9px; padding:9px 12px; color:#945d0c; background:#fff8ec; border-left:3px solid #e6a23c; }
.brand-bookings__attention strong, .brand-bookings__attention button { display:inline-flex; align-items:center; gap:5px; }
.brand-bookings__attention strong { flex:0 0 auto; font-size:12px; }
.brand-bookings__attention button { padding:5px 8px; color:#945d0c; background:#fff; border:1px solid #efcf9e; border-radius:var(--radius-sm); font-size:11px; cursor:pointer; }
.brand-bookings__detail-filter { min-height:56px; }
.brand-bookings__selection { display:inline-flex; align-items:center; gap:8px; color:var(--text-regular); font-size:11px; }
```

- [ ] **Step 5: Run focused page and component tests**

```powershell
npm run test -- tests/unit/brand-bookings-overview.spec.js tests/unit/brand-booking-heatmap.spec.js tests/unit/brand-bookings-page.spec.js
```

Expected: 12 focused tests pass.

- [ ] **Step 6: Commit the integrated page**

```powershell
git add -- demo/franchise-platform-h5/src/views/brand/Bookings.vue demo/franchise-platform-h5/tests/unit/brand-bookings-page.spec.js
git commit -m "feat: add brand booking operations overview"
```

## Task 5: Browser acceptance, full verification, and OpenSpec closure

**Files:**
- Modify: `demo/franchise-platform-h5/tests/e2e/brand-console.spec.js`
- Modify: `openspec/changes/enhance-brand-bookings-heatmap/tasks.md`

- [ ] **Step 1: Add the end-to-end drill-down test**

Append inside the existing desktop-only `brand operations console` describe block:

```js
test('drills from a heat slot into booking details and clears the selection', async ({ page }) => {
  await page.goto('/#/brand/bookings');
  await page.evaluate(() => localStorage.removeItem('tianli.franchise-demo.v1'));
  await page.reload();

  await expect(page.getByText('门店时段热力')).toBeVisible();
  await expect(page.getByText('9 / 14')).toBeVisible();
  const cell = page.getByTestId('booking-heat-cell-TL1002-11:00-11:45');
  await expect(cell).toContainText('2 / 2');
  await cell.click();

  await expect(page.getByText('天俪滨江店 · 11:00-11:45 · 2/2')).toBeVisible();
  await expect(page.locator('.admin-table-panel .el-table__body-wrapper').first().locator('tbody tr')).toHaveCount(2);
  await page.getByRole('button', { name: '清除时段筛选' }).click();
  await expect(page.getByText('天俪滨江店 · 11:00-11:45 · 2/2')).toHaveCount(0);

  await page.getByRole('button', { name: '查看' }).first().click();
  await expect(page.getByText('预约详情')).toBeVisible();
});
```

- [ ] **Step 2: Run focused E2E acceptance**

```powershell
npm run test:e2e -- tests/e2e/brand-console.spec.js --project=desktop-1440
```

Expected: all brand console desktop tests pass, including both 1280×800 and 1440×900 overflow checks performed by the existing route test.

- [ ] **Step 3: Run the complete unit suite and production build**

```powershell
npm run test
npm run build
```

Expected: all Vitest tests pass and Vite writes a successful `dist/` production build.

- [ ] **Step 4: Visually inspect both desktop target sizes**

Use Playwright against `http://127.0.0.1:4174/#/brand/bookings` at 1280×800 and 1440×900. Capture full-page screenshots and verify:

```text
- filter controls do not overlap or truncate
- four metrics stay aligned
- the heat matrix scrolls internally when needed
- store names remain readable while the matrix scrolls
- attention items and selected-slot summary do not overlap
- the page itself has no horizontal overflow
- the details drawer opens without covering broken layout
```

Expected: both screenshots show a populated, readable heatmap and the browser console has no errors.

- [ ] **Step 5: Validate OpenSpec and mark tasks complete**

```powershell
openspec validate enhance-brand-bookings-heatmap
```

Expected: `Change 'enhance-brand-bookings-heatmap' is valid`.

After all checks pass, change every checkbox in `openspec/changes/enhance-brand-bookings-heatmap/tasks.md` from `[ ]` to `[x]`. Do not archive the change until code, screenshots, tests, build, and spec agree.

- [ ] **Step 6: Commit acceptance artifacts**

```powershell
git add -- demo/franchise-platform-h5/tests/e2e/brand-console.spec.js openspec/changes/enhance-brand-bookings-heatmap/tasks.md
git commit -m "test: verify brand booking heatmap flow"
```

- [ ] **Step 7: Final repository check**

```powershell
git status --short
git log -6 --oneline
```

Expected: only the intentionally untracked `.superpowers/` visual-companion directory remains; implementation, tests, OpenSpec, and plan files are committed.
