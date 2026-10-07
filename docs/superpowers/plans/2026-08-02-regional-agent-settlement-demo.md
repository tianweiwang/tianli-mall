# Regional Agent Settlement Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a brand-admin-only regional-agent demo that connects store attribution, eligible service settlement, simulated 50/35/15 brand sharing, and agent earnings without mixing personal distribution commission.

**Architecture:** Add one focused `regionalAgent.js` domain module for applicability, split calculation, attribution lookup, and derived summaries. Eligible service settlement remains owned by `memberTransitions.js`, which stores an immutable snapshot and creates the existing `profitSharingRecords` shape for principal payments; UI reads the same state through selectors and never maintains a second agent balance.

**Tech Stack:** Vue 2.7, Vue Router 3, Element UI, lucide-vue, versioned local JSON state, Vitest 4, Vue Test Utils, Playwright 1.62, Vite 7, OpenSpec.

---

## File Map

- Create `demo/franchise-platform-h5/src/mock/regionalAgent.js`: applicable-series policy, store-agent lookup, settlement snapshot calculation, and agent summaries.
- Modify `demo/franchise-platform-h5/src/mock/seed.js`: version bump, deterministic agents, store attribution, and one seeded eligible settlement record.
- Modify `demo/franchise-platform-h5/src/mock/memberTransitions.js`: attach eligible service snapshots and create cash sharing records exactly once.
- Modify `demo/franchise-platform-h5/src/mock/transitions.js`: add failed/retry sharing transitions without changing existing return behavior.
- Modify `demo/franchise-platform-h5/src/mock/selectors.js`: re-export or compose regional-agent lookups for existing pages.
- Create `demo/franchise-platform-h5/src/views/brand/Agents.vue`: agent list and summary metrics.
- Create `demo/franchise-platform-h5/src/views/brand/AgentDetail.vue`: agent profile, stores, and original sharing records.
- Modify `demo/franchise-platform-h5/src/router/index.js` and `src/layouts/BrandLayout.vue`: routes and one navigation item.
- Modify existing brand/store pages only to display attribution, three-party snapshots, cross-links, state labels, and simulation notices.
- Add focused unit/component tests and one desktop Playwright journey.

### Task 1: Define The Regional-Agent Policy With Failing Tests

**Files:**
- Create: `demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js`
- Create: `demo/franchise-platform-h5/src/mock/regionalAgent.js`

- [ ] **Step 1: Write failing formula and applicability tests**

Create `tests/unit/regional-agent-domain.spec.js` with the following initial contract:

```js
import { createSeed } from '../../src/mock/seed';
import {
  buildRegionalSettlementSnapshot,
  isRegionalAgentSeries,
  regionalAgentForStore,
} from '../../src/mock/regionalAgent';

function createPolicyState() {
  const state = createSeed();
  state.regionalAgents = [
    { id: 'RA1001', name: '川西区域运营中心', status: 'active', commissionRate: 15 },
  ];
  state.stores.find((item) => item.id === 'TL1001').regionalAgentId = 'RA1001';
  state.stores.find((item) => item.id === 'TL1002').regionalAgentId = null;
  return state;
}

describe('regional agent settlement policy', () => {
  it.each(['特色系列', '经典系列'])('applies to %s', (series) => {
    expect(isRegionalAgentSeries(series)).toBe(true);
  });

  it.each(['评估系列', '传统系列', '精品系列', '定制系列'])('does not apply to %s', (series) => {
    expect(isRegionalAgentSeries(series)).toBe(false);
  });

  it('splits agent-store principal settlement as 50/35/15 with no lost cents', () => {
    const state = createPolicyState();
    const snapshot = buildRegionalSettlementSnapshot(state, {
      storeId: 'TL1001',
      service: { id: 'SV-CLASSIC', name: '肩背经筋调理', series: '经典系列' },
      paymentSource: 'principal',
      settlementCents: 32869,
    });

    expect(snapshot).toMatchObject({
      applicable: true,
      cashSettlement: true,
      storeRate: 50,
      groupRate: 35,
      regionalAgentRate: 15,
      regionalAgentId: 'RA1001',
    });
    expect(snapshot.storeIncomeCents + snapshot.groupIncomeCents + snapshot.regionalAgentIncomeCents).toBe(32869);
  });

  it('uses 50/50/0 for a direct store and zero cash for points', () => {
    const state = createPolicyState();
    const direct = buildRegionalSettlementSnapshot(state, {
      storeId: 'TL1002',
      service: { id: 'SV-SPECIAL', name: '腰椎矫正', series: '特色系列' },
      paymentSource: 'principal',
      settlementCents: 13200,
    });
    const points = buildRegionalSettlementSnapshot(state, {
      storeId: 'TL1001',
      service: { id: 'SV-SPECIAL', name: '腰椎矫正', series: '特色系列' },
      paymentSource: 'points',
      settlementCents: 0,
    });

    expect(direct).toMatchObject({ groupRate: 50, regionalAgentRate: 0, regionalAgentIncomeCents: 0 });
    expect(points).toMatchObject({ cashSettlement: false, storeIncomeCents: 0, groupIncomeCents: 0, regionalAgentIncomeCents: 0 });
    expect(regionalAgentForStore(state, 'TL1002')).toBeNull();
  });
});
```

- [ ] **Step 2: Run the test and verify the missing module failure**

Run:

```powershell
cd demo/franchise-platform-h5
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-domain.spec.js
```

Expected: FAIL because `src/mock/regionalAgent.js` does not exist.

- [ ] **Step 3: Implement the pure policy module**

Create `src/mock/regionalAgent.js`:

```js
const ELIGIBLE_SERIES = new Set(['特色系列', '经典系列']);

export function isRegionalAgentSeries(series) {
  return ELIGIBLE_SERIES.has(series);
}

export function regionalAgentById(state, agentId) {
  return (state.regionalAgents || []).find((item) => item.id === agentId) || null;
}

export function regionalAgentForStore(state, storeId) {
  const store = (state.stores || []).find((item) => item.id === storeId);
  return store?.regionalAgentId ? regionalAgentById(state, store.regionalAgentId) : null;
}

export function buildRegionalSettlementSnapshot(state, input) {
  if (!isRegionalAgentSeries(input.service?.series)) return null;
  const agent = regionalAgentForStore(state, input.storeId);
  const cashSettlement = input.paymentSource === 'principal';
  const settlementCents = cashSettlement ? Math.max(0, Math.round(Number(input.settlementCents) || 0)) : 0;
  const storeIncomeCents = Math.round(settlementCents * 0.5);
  const regionalAgentIncomeCents = agent ? Math.round(settlementCents * 0.15) : 0;
  const groupIncomeCents = settlementCents - storeIncomeCents - regionalAgentIncomeCents;
  return {
    applicable: true,
    cashSettlement,
    serviceId: input.service.id,
    serviceName: input.service.name,
    serviceSeries: input.service.series,
    storeId: input.storeId,
    regionalAgentId: agent?.id || null,
    regionalAgentName: agent?.name || '集团直营',
    storeRate: 50,
    groupRate: agent ? 35 : 50,
    regionalAgentRate: agent ? 15 : 0,
    settlementCents,
    storeIncomeCents,
    groupIncomeCents,
    regionalAgentIncomeCents,
  };
}
```

- [ ] **Step 4: Run the focused test**

Run the Task 1 command again.

Expected: PASS. The test-local state isolates the pure policy; Task 2 separately proves production seed attribution.

- [ ] **Step 5: Commit only the new policy and test**

```powershell
git add -- demo/franchise-platform-h5/src/mock/regionalAgent.js demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js
git commit -m "test: define regional agent settlement policy"
```

### Task 2: Add Deterministic Agent And Attribution Fixtures

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`
- Modify: `demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/store.spec.js`

- [ ] **Step 1: Add a failing reset and attribution assertion**

Append to `regional-agent-domain.spec.js`:

```js
it('restores deterministic agents and store attribution', () => {
  const first = createSeed();
  const second = createSeed();
  expect(first.regionalAgents).toEqual(second.regionalAgents);
  expect(first.regionalAgents.map((item) => item.id)).toEqual(['RA1001', 'RA1002']);
  expect(first.stores.find((item) => item.id === 'TL1001').regionalAgentId).toBe('RA1001');
  expect(first.stores.find((item) => item.id === 'TL1002').regionalAgentId).toBeNull();
});
```

- [ ] **Step 2: Run the focused test to see missing fixtures**

Run the Task 1 Vitest command.

Expected: FAIL because `regionalAgents` and `regionalAgentId` are absent.

- [ ] **Step 3: Add versioned neutral business fixtures**

In `seed.js`:

1. Increment `DEMO_VERSION` by one so stale local state resets.
2. Add `regionalAgentId: 'RA1001'` to `TL1001`, `regionalAgentId: null` to active direct store `TL1002`, and `regionalAgentId: 'RA1002'` to historical disabled store `TL1003`.
3. Add this top-level seed collection before `products`:

```js
regionalAgents: [
  {
    id: 'RA1001',
    name: '川西区域运营中心',
    contactName: '张经理',
    contactPhone: '138****2716',
    status: 'active',
    commissionRate: 15,
    joinedAt: '2026-05-12T09:00:00+08:00',
  },
  {
    id: 'RA1002',
    name: '城东渠道服务中心',
    contactName: '周经理',
    contactPhone: '137****4825',
    status: 'inactive',
    commissionRate: 15,
    joinedAt: '2026-04-20T09:00:00+08:00',
  },
],
```

Do not add create/edit mutations; these are deterministic demo fixtures.

- [ ] **Step 4: Update existing seed-count expectations surgically**

Run `tests/unit/store.spec.js` and update only assertions invalidated by the version increment or explicit new fields. Do not rewrite unrelated store/order expectations.

Run:

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-domain.spec.js tests/unit/store.spec.js
```

Expected: PASS.

- [ ] **Step 5: Commit the seed boundary**

```powershell
git add -- demo/franchise-platform-h5/src/mock/seed.js demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js demo/franchise-platform-h5/tests/unit/store.spec.js
git commit -m "feat: seed regional agents and store attribution"
```

### Task 3: Connect Eligible Service Settlement To Sharing Records

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/memberTransitions.js`
- Modify: `demo/franchise-platform-h5/tests/unit/member-settlement.spec.js`
- Modify: `demo/franchise-platform-h5/src/components/store/MemberSettlementPanel.vue`
- Modify: `demo/franchise-platform-h5/tests/unit/member-management-pages.spec.js`

- [ ] **Step 1: Replace the generic settlement fixture with eligible and non-eligible helpers**

In `member-settlement.spec.js`, make the helper default to `SV1014` (经典系列), then add tests for principal, points, direct-store, non-eligible series, and idempotency:

```js
it('creates one 50/35/15 sharing record for eligible principal settlement', () => {
  const state = createSeed();
  rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-AGENT-1001' });
  const booking = createVerifiedBooking(state, { id: 'BK-AGENT-1001', serviceId: 'SV1014' });
  const before = state.profitSharingRecords.length;

  const first = settleVerifiedBooking(state, {
    bookingId: booking.id,
    operatingStoreId: 'TL1001',
    paymentSource: 'principal',
    settlementAttemptId: 'SETTLE-AGENT-1001',
  });
  const second = settleVerifiedBooking(state, {
    bookingId: booking.id,
    operatingStoreId: 'TL1001',
    paymentSource: 'principal',
    settlementAttemptId: 'SETTLE-AGENT-1001',
  });
  const sharing = state.profitSharingRecords.find((item) => item.id === first.profitSharingRecordId);

  expect(second.id).toBe(first.id);
  expect(state.profitSharingRecords).toHaveLength(before + 1);
  expect(first.regionalSettlement).toMatchObject({
    settlementCents: 32868,
    storeIncomeCents: 16434,
    groupIncomeCents: 11504,
    regionalAgentIncomeCents: 4930,
    regionalAgentId: 'RA1001',
  });
  expect(sharing).toMatchObject({
    businessType: 'service-settlement',
    businessId: first.id,
    bookingId: booking.id,
    regionalAgentId: 'RA1001',
    status: 'pending',
  });
});

it('keeps points cash values at zero and creates no cash sharing record', () => {
  const state = createSeed();
  rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-AGENT-POINTS' });
  const booking = createVerifiedBooking(state, { id: 'BK-AGENT-POINTS', serviceId: 'SV1007' });
  const before = state.profitSharingRecords.length;
  const result = settleVerifiedBooking(state, {
    bookingId: booking.id,
    operatingStoreId: 'TL1001',
    paymentSource: 'points',
    settlementAttemptId: 'SETTLE-AGENT-POINTS',
  });

  expect(result.regionalSettlement).toMatchObject({ cashSettlement: false, settlementCents: 0 });
  expect(state.profitSharingRecords).toHaveLength(before);
});

it('does not apply the template to a traditional-series settlement', () => {
  const state = createSeed();
  rechargeStoredValue(state, { customerId: 'CUS1004', paymentAttemptId: 'RECHARGE-NON-AGENT' });
  const booking = createVerifiedBooking(state, { id: 'BK-NON-AGENT', serviceId: 'SV1004' });
  const before = state.profitSharingRecords.length;
  const result = settleVerifiedBooking(state, {
    bookingId: booking.id,
    operatingStoreId: 'TL1001',
    paymentSource: 'principal',
    settlementAttemptId: 'SETTLE-NON-AGENT',
  });

  expect(result.regionalSettlement).toBeNull();
  expect(state.profitSharingRecords).toHaveLength(before);
});
```

- [ ] **Step 2: Run settlement tests and verify missing snapshot failures**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/member-settlement.spec.js
```

Expected: FAIL because `regionalSettlement` and `profitSharingRecordId` are missing.

- [ ] **Step 3: Add the minimal transactional integration**

Import `buildRegionalSettlementSnapshot` in `memberTransitions.js`. After pricing is calculated, build the snapshot with `pricing.principalCents` for principal and `0` for points. Add it to the settlement object as `regionalSettlement` (use `null` for non-eligible series).

After `state.memberConsumptions.unshift(settlement)`, create a sharing record only when `regionalSettlement?.cashSettlement`:

```js
if (regionalSettlement?.cashSettlement) {
  const sharingRecord = {
    id: nextId(state, 'sharing', 'PS20260730'),
    orderId: null,
    businessType: 'service-settlement',
    businessId: settlement.id,
    bookingId: booking.id,
    storeId: booking.storeId,
    regionalAgentId: regionalSettlement.regionalAgentId,
    regionalAgentName: regionalSettlement.regionalAgentName,
    paidCents: regionalSettlement.settlementCents,
    storeIncomeCents: regionalSettlement.storeIncomeCents,
    brandCommissionCents: regionalSettlement.groupIncomeCents,
    regionalAgentIncomeCents: regionalSettlement.regionalAgentIncomeCents,
    distributionCostCents: 0,
    settlementSnapshot: { ...regionalSettlement },
    status: 'pending',
    timeline: [{ status: 'pending', at: state.demoNow }],
  };
  state.profitSharingRecords.unshift(sharingRecord);
  settlement.profitSharingRecordId = sharingRecord.id;
}
```

Construct the snapshot before any state mutation but append the sharing record only after all validation and successful asset debit. Preserve the existing early return `if (booking.memberSettlement)` so repeat calls cannot create a second record.

- [ ] **Step 4: Show the resulting split in the existing store panel**

In `MemberSettlementPanel.vue`, after a completed eligible settlement, render:

```vue
<dl v-if="booking.memberSettlement.regionalSettlement" class="settlement-split">
  <div><dt>结算模板</dt><dd>{{ booking.memberSettlement.regionalSettlement.regionalAgentRate ? '门店 / 集团 / 区域代理' : '门店 / 集团直营' }}</dd></div>
  <div><dt>门店收入</dt><dd>{{ formatMoney(booking.memberSettlement.regionalSettlement.storeIncomeCents) }}</dd></div>
  <div><dt>集团资金</dt><dd>{{ formatMoney(booking.memberSettlement.regionalSettlement.groupIncomeCents) }}</dd></div>
  <div><dt>区域代理</dt><dd>{{ formatMoney(booking.memberSettlement.regionalSettlement.regionalAgentIncomeCents) }}</dd></div>
</dl>
```

Use the existing `formatMoney` helper and show “非现金结算” when `cashSettlement` is false.

- [ ] **Step 5: Run domain and component tests**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-domain.spec.js tests/unit/member-settlement.spec.js tests/unit/member-management-pages.spec.js
```

Expected: PASS.

- [ ] **Step 6: Commit the service-settlement integration**

```powershell
git add -- demo/franchise-platform-h5/src/mock/memberTransitions.js demo/franchise-platform-h5/tests/unit/member-settlement.spec.js demo/franchise-platform-h5/src/components/store/MemberSettlementPanel.vue demo/franchise-platform-h5/tests/unit/member-management-pages.spec.js
git commit -m "feat: split eligible service settlements by agent"
```

### Task 4: Derive Agent Earnings And Add Failure Retry

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/regionalAgent.js`
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Modify: `demo/franchise-platform-h5/src/views/brand/ProfitSharing.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/ProfitSharingDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Settlements.vue`
- Modify: `demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/transitions.spec.js`

- [ ] **Step 1: Add failing summary and state-transition tests**

Append tests that construct one pending and one completed agent sharing record and expect derived totals:

```js
import { regionalAgentOverview } from '../../src/mock/regionalAgent';

it('derives pending and settled income from original sharing status', () => {
  const state = createSeed();
  state.profitSharingRecords.unshift(
    { id: 'PS-RA-PENDING', regionalAgentId: 'RA1001', regionalAgentIncomeCents: 1500, status: 'pending' },
    { id: 'PS-RA-COMPLETED', regionalAgentId: 'RA1001', regionalAgentIncomeCents: 900, status: 'completed' },
  );
  expect(regionalAgentOverview(state, 'RA1001')).toMatchObject({ pendingCents: 1500, settledCents: 900 });
});
```

In `transitions.spec.js`, add:

```js
it('keeps one sharing record through failure and retry', () => {
  const state = createSeed();
  const record = state.profitSharingRecords.find((item) => item.status === 'processing');
  const count = state.profitSharingRecords.length;
  advanceSharing(state, record.id, 'failed');
  advanceSharing(state, record.id, 'processing');
  expect(record.timeline.map((item) => item.status).slice(-2)).toEqual(['failed', 'processing']);
  expect(state.profitSharingRecords).toHaveLength(count);
});
```

- [ ] **Step 2: Run tests and confirm unsupported-state failures**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-domain.spec.js tests/unit/transitions.spec.js
```

Expected: FAIL because the overview export and `failed` transition do not exist.

- [ ] **Step 3: Implement derived summaries and state paths**

Add to `regionalAgent.js`:

```js
const PENDING_STATUSES = new Set(['pending', 'processing', 'failed', 'returning']);

export function regionalAgentOverview(state, agentId) {
  const agent = regionalAgentById(state, agentId);
  const stores = (state.stores || []).filter((item) => item.regionalAgentId === agentId);
  const records = (state.profitSharingRecords || []).filter((item) => item.regionalAgentId === agentId);
  return {
    agent,
    stores,
    records,
    pendingCents: records.filter((item) => PENDING_STATUSES.has(item.status)).reduce((sum, item) => sum + Number(item.regionalAgentIncomeCents || 0), 0),
    settledCents: records.filter((item) => item.status === 'completed').reduce((sum, item) => sum + Number(item.regionalAgentIncomeCents || 0), 0),
  };
}

export function regionalAgentList(state) {
  return (state.regionalAgents || []).map((agent) => regionalAgentOverview(state, agent.id));
}
```

`returned` records contribute to neither pending nor settled totals; `returning` remains visible in pending funds until the simulated return completes.

Update `sharingTransitions` in `transitions.js` without removing the existing return path:

```js
const sharingTransitions = {
  pending: ['processing'],
  processing: ['completed', 'failed', 'returning'],
  failed: ['processing'],
  completed: ['returning'],
  returning: ['returned'],
  returned: [],
};
```

- [ ] **Step 4: Add consistent status labels and actions**

In brand/store sharing views, add `failed: '分账失败'`. In `ProfitSharingDetail.vue`, add actions:

```js
processing: [
  { status: 'completed', label: '确认完成', type: 'primary' },
  { status: 'failed', label: '模拟失败', type: 'danger' },
  { status: 'returning', label: '发起回退', type: 'danger' },
],
failed: [{ status: 'processing', label: '重新处理', type: 'primary' }],
```

Do not mutate a separate agent balance when completion occurs; the overview must change solely because the original record status changes.

- [ ] **Step 5: Run focused tests**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-domain.spec.js tests/unit/transitions.spec.js tests/unit/share-distribution-attribution.spec.js
```

Expected: PASS, including existing commission settlement and return behavior.

- [ ] **Step 6: Commit the state and summary change**

```powershell
git add -- demo/franchise-platform-h5/src/mock/regionalAgent.js demo/franchise-platform-h5/src/mock/transitions.js demo/franchise-platform-h5/src/views/brand/ProfitSharing.vue demo/franchise-platform-h5/src/views/brand/ProfitSharingDetail.vue demo/franchise-platform-h5/src/views/store/Settlements.vue demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js demo/franchise-platform-h5/tests/unit/transitions.spec.js
git commit -m "feat: track agent earnings through sharing states"
```

### Task 5: Add Brand Agent Routes, List, And Detail

**Files:**
- Create: `demo/franchise-platform-h5/src/views/brand/Agents.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/AgentDetail.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Modify: `demo/franchise-platform-h5/src/layouts/BrandLayout.vue`
- Create: `demo/franchise-platform-h5/tests/unit/regional-agent-pages.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/brand-console.spec.js`

- [ ] **Step 1: Write failing route and menu tests**

Add route expectations:

```js
it.each([
  ['/brand/agents', 'BrandAgents'],
  ['/brand/agents/RA1001', 'BrandAgentDetail'],
])('loads %s as a concrete page', async (path, componentName) => {
  const route = createRouter().match(path);
  const component = await route.matched.at(-1).components.default();
  expect(component.default.name).toBe(componentName);
});
```

Update the exact brand menu expectation to place `区域代理` immediately after `加盟门店`.

- [ ] **Step 2: Run route tests and verify placeholder/missing menu failures**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/router.spec.js tests/unit/brand-console.spec.js
```

Expected: FAIL because routes, pages, and menu item are absent.

- [ ] **Step 3: Add routes and navigation**

Add these child routes after brand stores:

```js
{ path: 'agents', name: 'brand-agents', component: view('brand', 'Agents'), meta: meta('brand', '区域代理') },
{ path: 'agents/:agentId', name: 'brand-agent-detail', component: view('brand', 'AgentDetail'), meta: meta('brand', '代理详情') },
```

In `BrandLayout.vue`, import `Network` from `lucide-vue` and add:

```js
{ label: '区域代理', path: '/brand/agents', icon: Network },
```

- [ ] **Step 4: Implement the list page from derived summaries**

`Agents.vue` must:

- use `regionalAgentList(this.state)`;
- show active count, covered-store count, pending amount, and settled amount in `AdminMetricStrip`;
- provide status and keyword filters;
- show agent, contact, status, fixed 15%, store count, pending, settled, and an explicit router-link to `/brand/agents/:id`;
- show the simulation notice from the OpenSpec requirement.

The computed boundary must stay this small:

```js
summaries() {
  return regionalAgentList(this.state);
},
visibleSummaries() {
  const keyword = this.keyword.toLowerCase();
  return this.summaries.filter(({ agent }) => (
    (this.status === 'all' || agent.status === this.status)
    && (!keyword || `${agent.name} ${agent.id} ${agent.contactName}`.toLowerCase().includes(keyword))
  ));
},
```

- [ ] **Step 5: Implement the detail page with original record links**

`AgentDetail.vue` must read `regionalAgentOverview(state, route.params.agentId)` and render:

- profile and status;
- store table linking to `/brand/stores/:storeId`;
- sharing-record table linking to `/brand/sharing/:recordId`;
- pending and settled metrics;
- “未找到区域代理” for an unknown ID;
- no create, edit, bind, payout, or login action.

- [ ] **Step 6: Add focused component assertions**

In `regional-agent-pages.spec.js`, shallow-mount both pages with a real router and assert exact cross-links:

```js
expect(wrapper.find('a[href="#/brand/stores/TL1001"]').exists()).toBe(true);
expect(wrapper.findAll('a[href^="#/brand/sharing/"]').length).toBeGreaterThan(0);
```

Use seeded records or create one agent record in test state before mount; do not introduce tool-related names in fixtures.

- [ ] **Step 7: Run focused tests and commit**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/router.spec.js tests/unit/brand-console.spec.js tests/unit/regional-agent-pages.spec.js
git add -- demo/franchise-platform-h5/src/views/brand/Agents.vue demo/franchise-platform-h5/src/views/brand/AgentDetail.vue demo/franchise-platform-h5/src/router/index.js demo/franchise-platform-h5/src/layouts/BrandLayout.vue demo/franchise-platform-h5/tests/unit/regional-agent-pages.spec.js demo/franchise-platform-h5/tests/unit/router.spec.js demo/franchise-platform-h5/tests/unit/brand-console.spec.js
git commit -m "feat: add brand regional agent workspace"
```

Expected: tests PASS before commit.

### Task 6: Add Bidirectional Attribution And Separate Financial Copy

**Files:**
- Modify: `demo/franchise-platform-h5/src/views/brand/Dashboard.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Stores.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/StoreDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Orders.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/ProfitSharing.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/ProfitSharingDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Distribution.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Settlements.vue`
- Create: `demo/franchise-platform-h5/tests/unit/regional-agent-drilldowns.spec.js`

- [ ] **Step 1: Write failing cross-link and financial-copy tests**

Mount the existing pages with a seeded `RA1001` sharing record and assert:

```js
expect(storeDetail.text()).toContain('川西区域运营中心');
expect(storeDetail.find('a[href="#/brand/agents/RA1001"]').exists()).toBe(true);
expect(sharingDetail.text()).toContain('区域代理 15%');
expect(sharingDetail.text()).toContain('集团公共补贴资金池');
expect(sharingDetail.text()).toContain('模拟品牌分账');
expect(distribution.text()).toContain('集团推广预算承担');
```

Also assert a direct store displays `集团直营` and no agent-detail link.

- [ ] **Step 2: Run the new component test and verify missing content**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-drilldowns.spec.js
```

Expected: FAIL on the absent labels and links.

- [ ] **Step 3: Extend store and dashboard views**

- `Stores.vue`: add an “代理归属” column that links to the agent or displays `集团直营`.
- `StoreDetail.vue`: replace the generic brand commission rows for eligible-service policy with a “结算关系” block showing agent, 50/35/15 or 50/50, and the agent link.
- `Dashboard.vue`: add agent pending amount with a clear link to `/brand/agents`; derive it from `regionalAgentList` rather than storing a total.

- [ ] **Step 4: Extend sharing and order detail views**

For a record with `settlementSnapshot`, `ProfitSharingDetail.vue` must display this equation:

```vue
<p class="brand-sharing-detail__equation">
  {{ formatMoney(record.storeIncomeCents) }} +
  {{ formatMoney(record.brandCommissionCents) }} +
  {{ formatMoney(record.regionalAgentIncomeCents) }} =
  {{ formatMoney(record.paidCents) }}
</p>
```

Show the agent link only when `regionalAgentId` is present, otherwise show `集团直营`. Label `brandCommissionCents` as “集团补贴资金池入账” for eligible service snapshots. For legacy product records, preserve their existing two-party display.

`ProfitSharing.vue` must use “业务单号” and render `record.orderId || record.businessId`. `Orders.vue` may show regional attribution only when an order already carries a `regionalSettlement`; do not invent a service order row in the product order table.

- [ ] **Step 5: Separate personal-distribution copy**

In `Distribution.vue` and the commission section of `ProfitSharingDetail.vue`, replace “从品牌抽成中列示” and “品牌抽成净额” language with:

```text
个人分享佣金由集团推广预算承担，不进入区域代理分账或客户补贴资金池。
```

Do not change the 8% amount calculation or its completion-triggered settlement behavior.

- [ ] **Step 6: Update the store settlement view**

Show the store's own income first, then read-only agent/group explanation. Do not add an agent management action. For points snapshots display `非现金结算`; for legacy product records preserve current columns.

- [ ] **Step 7: Run focused regressions and commit**

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-drilldowns.spec.js tests/unit/detail-drilldowns.spec.js tests/unit/share-distribution-attribution.spec.js tests/unit/brand-console.spec.js
git add -- demo/franchise-platform-h5/src/views/brand/Dashboard.vue demo/franchise-platform-h5/src/views/brand/Stores.vue demo/franchise-platform-h5/src/views/brand/StoreDetail.vue demo/franchise-platform-h5/src/views/brand/Orders.vue demo/franchise-platform-h5/src/views/brand/ProfitSharing.vue demo/franchise-platform-h5/src/views/brand/ProfitSharingDetail.vue demo/franchise-platform-h5/src/views/brand/Distribution.vue demo/franchise-platform-h5/src/views/store/Settlements.vue demo/franchise-platform-h5/tests/unit/regional-agent-drilldowns.spec.js
git commit -m "feat: link agent attribution across settlement views"
```

Expected: PASS, and the commit includes no unrelated dirty files.

### Task 7: Seed A Visible Example And Verify The Full Demo

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`
- Modify: `demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js`
- Create: `demo/franchise-platform-h5/tests/e2e/regional-agent-settlement.spec.js`
- Modify: `demo/franchise-platform-h5/README.md`
- Modify: `openspec/changes/add-regional-agent-settlement-demo/tasks.md`

- [ ] **Step 1: Add a failing seeded-example assertion**

Assert reset data contains one visible completed eligible service settlement for `RA1001` and one pending record, both referencing original member consumption IDs:

```js
it('includes deterministic pending and completed agent examples after reset', () => {
  const state = createSeed();
  const records = state.profitSharingRecords.filter((item) => item.regionalAgentId === 'RA1001');
  expect(records.map((item) => item.status).sort()).toEqual(['completed', 'pending']);
  expect(records.every((item) => item.businessType === 'service-settlement')).toBe(true);
  expect(records.every((item) => state.memberConsumptions.some((entry) => entry.id === item.businessId))).toBe(true);
});
```

- [ ] **Step 2: Add internally consistent seed records**

Replace the existing single 510000-cent aggregate seeded consumption with three records whose `principalCents` still sum to exactly 510000:

- one completed classic-service example for `SV1014` with 32868 cents principal;
- one pending special-service example for `SV1012` with 52800 cents principal;
- one remaining aggregate consumption with 424332 cents principal.

Create matching sharing records only for the first two service records, using snapshots returned by `buildRegionalSettlementSnapshot`. Both belong to `TL1001` and `RA1001`; one is `completed`, one is `pending`. Keep the existing aggregate principal debit transaction, account `principalSpentCents`, and `eligiblePrincipalSpentCents` at 510000 so seed assets and subsidy eligibility do not change. Add the new consumption and sharing IDs to their sequence floors.

Run:

```powershell
node_modules\.bin\vitest.cmd run tests/unit/regional-agent-domain.spec.js tests/unit/store.spec.js tests/unit/brand-console.spec.js
```

Expected: PASS after updating only totals intentionally changed by the new seeded sharing records.

- [ ] **Step 3: Write the desktop end-to-end journey**

Create `tests/e2e/regional-agent-settlement.spec.js` with one desktop-only test:

```js
import { expect, test } from '@playwright/test';

test.describe('regional agent settlement demo', () => {
  test.skip(({ isMobile }) => isMobile, '跨角色财务链路使用桌面后台并在过程内切换客户视口');

  test('links eligible service settlement to agent earnings without mixing personal commission', async ({ page }) => {
    const errors = [];
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
    await page.goto('/#/brand/agents');
    await page.evaluate(() => window.localStorage.clear());
    await page.reload();
    await expect(page.getByRole('heading', { name: '区域代理' })).toBeVisible();
    await page.getByRole('link', { name: /查看.*川西区域运营中心/ }).click();
    await expect(page.getByText('天俪锦江店', { exact: true })).toBeVisible();

    await page.goto('/#/brand/sharing');
    const eligible = page.getByRole('row').filter({ hasText: '经典系列' }).first();
    await eligible.getByRole('link', { name: '查看' }).click();
    await expect(page.getByText('区域代理 15%', { exact: false })).toBeVisible();
    await expect(page.getByText('模拟品牌分账', { exact: false })).toBeVisible();
    await page.getByRole('button', { name: '开始分账' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认推进' }).click();
    await page.getByRole('button', { name: '确认完成' }).click();
    await page.locator('.el-message-box').getByRole('button', { name: '确认推进' }).click();

    await page.goto('/#/brand/agents/RA1001');
    await expect(page.getByText('累计已结收益')).toBeVisible();
    await page.goto('/#/brand/distribution');
    await expect(page.getByText('集团推广预算承担', { exact: false })).toBeVisible();
    expect(errors).toEqual([]);
  });
});
```

The implementation must expose the exact accessible names used above: agent-row links start with `查看`, eligible sharing rows include the service series, and sharing actions retain `开始分账` and `确认完成`. If a component lacks those names, add `aria-label` at the component rather than weakening the test.

- [ ] **Step 4: Document the demo sequence**

Add a short README sequence:

```text
区域代理 -> 查看归属门店 -> 门店核销特色/经典项目 -> 会员本金结算 -> 品牌分账 -> 代理待结转已结 -> 分销管理查看独立推广预算。
```

Also state that all payment and sharing actions are local simulation and production must check the approved WeChat `max_ratio`.

- [ ] **Step 5: Run focused browser verification**

```powershell
node_modules\.bin\playwright.cmd test tests/e2e/regional-agent-settlement.spec.js --project=desktop-1440
```

Expected: 1 passed, no console errors, no overlap in captured failure artifacts.

- [ ] **Step 6: Run full verification**

```powershell
node_modules\.bin\vitest.cmd run
npm run build
node_modules\.bin\playwright.cmd test
cd ..\..
npx openspec validate add-regional-agent-settlement-demo
git diff --check
```

Expected:

- all Vitest tests pass;
- Vite production build exits 0;
- desktop Playwright projects pass and configured mobile skips remain explicit;
- OpenSpec reports the change valid;
- `git diff --check` has no whitespace errors.

- [ ] **Step 7: Run the required defect learning loop**

Invoke `$defect-learning-loop` and verify:

- root cause: regional agent was previously conflated with two-party brand commission or personal distribution;
- regression protection: formula, applicability, snapshot, idempotency, failure retry, cross-link, and E2E tests exist;
- sibling coverage: brand, store, service settlement, sharing, reset, and personal distribution pages agree;
- deduplicated rule: one regional settlement snapshot is the source of all displayed three-party amounts.

- [ ] **Step 8: Mark OpenSpec tasks complete only after evidence exists**

Update every completed checkbox in `openspec/changes/add-regional-agent-settlement-demo/tasks.md`. Leave any task unchecked if its verification did not run or failed.

- [ ] **Step 9: Commit the final examples and verification artifacts**

```powershell
git add -- demo/franchise-platform-h5/src/mock/seed.js demo/franchise-platform-h5/tests/unit/regional-agent-domain.spec.js demo/franchise-platform-h5/tests/e2e/regional-agent-settlement.spec.js demo/franchise-platform-h5/README.md openspec/changes/add-regional-agent-settlement-demo/tasks.md
git commit -m "test: verify regional agent settlement journey"
```

Do not archive the OpenSpec change until implementation, tests, and the written requirements agree and the user requests branch closeout.
