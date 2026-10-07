# Share Distribution Attribution Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a deterministic local Demo flow where a distributor share link binds a simulated friend, attributed orders generate 8% commission and one first-order referral reward, completed profit sharing settles the commission, and the distributor can withdraw it.

**Architecture:** Keep URL construction in a small pure helper, and keep every state mutation in `src/mock/transitions.js`. Extend the versioned seed and selectors with share campaigns, bindings, attribution, and commission joins; Vue pages only invoke transitions and render derived state. Reuse the existing order, profit-sharing, distributor withdrawal, and brand approval workflows rather than creating parallel financial flows.

**Tech Stack:** Vue 2, Vue Router 3 hash routing, localStorage-backed observable demo store, Vitest, Vue Test Utils, Playwright, OpenSpec.

---

## File Structure

- Create `openspec/changes/share-distribution-attribution-demo/`: proposal, design, requirements delta, and tracked implementation tasks.
- Create `demo/franchise-platform-h5/src/mock/shareLinks.js`: pure deployment-safe share path and URL construction.
- Modify `demo/franchise-platform-h5/src/mock/seed.js`: version 8 schema, deterministic campaign/binding/commission samples, and new sequences.
- Modify `demo/franchise-platform-h5/src/mock/transitions.js`: share creation, visit recording, binding, attributed order, first-order reward, and commission settlement.
- Modify `demo/franchise-platform-h5/src/mock/selectors.js`: share/binding/commission lookup and joined summaries.
- Create `demo/franchise-platform-h5/src/views/customer/ShareEntry.vue`: simulated friend landing and acceptance page.
- Modify consumer pages `Share.vue`, `Checkout.vue`, and `PaymentSuccess.vue`: campaign generation and attribution feedback.
- Modify brand pages `Orders.vue`, `ProfitSharingDetail.vue`, and `Distribution.vue`: attribution and settlement visibility.
- Modify `demo/franchise-platform-h5/src/router/index.js`: share-entry route.
- Create `demo/franchise-platform-h5/tests/unit/share-distribution-attribution.spec.js`: core behavioral regression suite.
- Modify `demo/franchise-platform-h5/tests/unit/growth-pages.spec.js`, `router.spec.js`, version assertions, and affected financial assertions.
- Create `demo/franchise-platform-h5/tests/e2e/share-distribution-attribution.spec.js`: complete browser workflow.

### Task 1: Create And Validate The OpenSpec Change

**Files:**
- Create: `openspec/changes/share-distribution-attribution-demo/.openspec.yaml`
- Create: `openspec/changes/share-distribution-attribution-demo/proposal.md`
- Create: `openspec/changes/share-distribution-attribution-demo/design.md`
- Create: `openspec/changes/share-distribution-attribution-demo/specs/share-distribution-attribution-demo/spec.md`
- Create: `openspec/changes/share-distribution-attribution-demo/tasks.md`

- [ ] **Step 1: Create the change metadata**

```yaml
schema: spec-driven
created: 2026-08-02
```

- [ ] **Step 2: Write the proposal**

```markdown
# Proposal: 分享分销归因 Demo 闭环

## Why

当前分享、推荐、分销、订单分账和提现模块彼此独立，无法演示分享订单自动产生佣金并进入结算提现的因果关系。

## What Changes

- 分享中心生成唯一令牌、部署安全链接和真实二维码。
- 新增模拟好友落地页和持续推荐绑定。
- 归因订单按分销员档案 8% 生成待结算佣金，并在首单发放一次 8 元推荐奖励。
- 品牌完成订单分账时同步结算佣金，分销员随后可申请提现。
- 消费者和品牌页面展示分享来源、佣金和结算结果。

## Impact

仅修改 `demo/franchise-platform-h5` 的本地状态、页面和测试；不调用真实微信、支付、数据库、CRMEB API 或资金通道。
```

- [ ] **Step 3: Write the requirements delta**

```markdown
## ADDED Requirements

### Requirement: 分享令牌与推荐绑定

系统 SHALL 为新分享生成唯一令牌和二维码，并允许模拟好友通过落地页接受推荐。

#### Scenario: 接受有效分享

- **WHEN** 好友打开有效分享令牌并接受推荐
- **THEN** 系统 SHALL 创建一个持续有效的分销绑定和待首单推荐记录
- **THEN** 同一浏览器对同一令牌 SHALL 只记录一次访问
- **THEN** 重复接受同一令牌 SHALL NOT 创建重复绑定或推荐记录

#### Scenario: 打开无效分享

- **WHEN** 分享令牌不存在或缺少有效门店/分销员
- **THEN** 系统 SHALL 显示无效链接且 SHALL NOT 修改归因状态

### Requirement: 归因订单与推荐奖励

系统 SHALL 为有效绑定产生的订单记录分享来源，并按分销员档案比例生成佣金。

#### Scenario: 完成首笔归因订单

- **WHEN** 已绑定好友完成支付
- **THEN** 系统 SHALL 按实付金额的 8% 创建一条待结算佣金
- **THEN** 系统 SHALL 将分享订单数增加一次
- **THEN** 系统 SHALL 将推荐记录标记为已奖励并发放一次 8 元推荐奖励

#### Scenario: 完成后续归因订单

- **WHEN** 同一绑定好友再次完成支付
- **THEN** 系统 SHALL 继续创建 8% 待结算佣金
- **THEN** 系统 SHALL NOT 再次发放首单推荐奖励

#### Scenario: 普通订单与重复支付

- **WHEN** 订单没有有效绑定
- **THEN** 系统 SHALL NOT 生成分销佣金
- **WHEN** 同一支付请求重复提交
- **THEN** 系统 SHALL NOT 重复生成订单、积分、佣金或推荐奖励

### Requirement: 佣金结算与提现

系统 SHALL 在订单分账完成时同步结算该订单的分销佣金。

#### Scenario: 品牌完成分账

- **WHEN** 品牌把归因订单的分账推进为已完成
- **THEN** 对应佣金 SHALL 从待结算变为已结算
- **THEN** 分销员可提现余额 SHALL 增加相同金额

#### Scenario: 提现并审核

- **WHEN** 分销员提交不超过可提现余额的申请
- **THEN** 品牌 SHALL 能将申请推进为处理中、已到账或驳回
```

- [ ] **Step 4: Write design and task tracking files**

Use this exact `design.md` structure:

```markdown
# Design: 分享分销归因 Demo

## State ownership

`shareLinks.js` only builds deployment-safe paths and URLs. `transitions.js` owns campaign, visit, binding, order, reward, commission, settlement, and withdrawal mutations. `selectors.js` joins data for pages. Vue components never mutate financial fields directly.

## Attribution

A tokenized share creates at most one active local binding. The first active binding persists across future orders and cannot be silently replaced. Ordinary orders have no distribution attribution and no commission.

## Financial behavior

Attributed orders create one pending commission using the distributor profile's 8% rate. The first order also rewards the linked referral once with 800 cents. Completing profit sharing settles the matching commission; store income remains unchanged and the distribution cost reduces brand net commission only.

## Idempotency

Visited tokens are deduplicated per browser. Repeated acceptance returns the active binding. `paymentAttemptId` prevents duplicate orders, points, commissions, and rewards. Profit-sharing transition validation prevents duplicate settlement.

## UI

The consumer flow covers campaign generation, QR/link, friend landing, acceptance, checkout, and payment feedback. Brand order, sharing detail, distribution, and withdrawal pages expose the resulting attribution and financial state.
```

Use this exact `tasks.md`:

```markdown
## 1. State and attribution

- [ ] 1.1 Add version 8 attribution seed and deployment-safe share URL helpers.
- [ ] 1.2 Add share campaign, visit, and persistent binding transitions.

## 2. Order and finance

- [ ] 2.1 Generate 8% commission and one first-order referral reward from attributed orders.
- [ ] 2.2 Settle the matching commission when profit sharing completes.
- [ ] 2.3 Preserve withdrawal balance validation and brand approval behavior.

## 3. Consumer and brand UI

- [ ] 3.1 Add share campaign generation and simulated friend landing/acceptance.
- [ ] 3.2 Show attribution in checkout and payment success.
- [ ] 3.3 Show source, commission, and settlement in brand operations.

## 4. Verification

- [ ] 4.1 Add unit and page regression tests.
- [ ] 4.2 Add the complete Playwright journey.
- [ ] 4.3 Run full Vitest, build, Playwright, and OpenSpec validation.
```

- [ ] **Step 5: Validate the OpenSpec change**

Run: `npx openspec validate share-distribution-attribution-demo`

Expected: `Change 'share-distribution-attribution-demo' is valid`

- [ ] **Step 6: Commit the OpenSpec change**

```bash
git add openspec/changes/share-distribution-attribution-demo
git commit -m "docs: specify share distribution attribution demo"
```

### Task 2: Add Version 8 Attribution State And Share URL Helpers

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/shareLinks.js`
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`
- Create: `demo/franchise-platform-h5/tests/unit/share-distribution-attribution.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/assessment-seed.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/brand-bookings-overview.spec.js`
- Modify: `demo/franchise-platform-h5/tests/e2e/assessment-demo.spec.js`

- [ ] **Step 1: Write failing version and URL tests**

```js
import { createSeed, DEMO_VERSION } from '../../src/mock/seed';
import { buildShareEntryPath, buildShareEntryUrl } from '../../src/mock/shareLinks';

describe('share distribution attribution seed', () => {
  it('uses version 8 with attribution collections and sequences', () => {
    const state = createSeed();
    expect(DEMO_VERSION).toBe(8);
    expect(state.distributionBindings).toEqual([]);
    expect(state.visitedShareTokens).toEqual([]);
    expect(state.sequence).toMatchObject({ binding: 1000, commission: 1003, customer: 1002 });
  });

  it('builds deployment-safe hash URLs', () => {
    expect(buildShareEntryPath('TL1001', 'TL-SH-1004'))
      .toBe('/c/store/TL1001/share-entry/TL-SH-1004');
    expect(buildShareEntryUrl(
      { origin: 'https://demo.example.com', pathname: '/franchise-demo/' },
      'TL1001',
      'TL-SH-1004',
    )).toBe('https://demo.example.com/franchise-demo/#/c/store/TL1001/share-entry/TL-SH-1004');
  });
});
```

- [ ] **Step 2: Run the focused test and verify failure**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js`

Expected: FAIL because `shareLinks.js` does not exist and `DEMO_VERSION` is still 7.

- [ ] **Step 3: Implement the URL helper**

```js
export function buildShareEntryPath(storeId, token) {
  return `/c/store/${encodeURIComponent(storeId)}/share-entry/${encodeURIComponent(token)}`;
}

export function buildShareEntryUrl(locationLike, storeId, token) {
  const pathname = locationLike.pathname.endsWith('/')
    ? locationLike.pathname
    : `${locationLike.pathname}/`;
  return `${locationLike.origin}${pathname}#${buildShareEntryPath(storeId, token)}`;
}
```

- [ ] **Step 4: Extend the seed deterministically**

Set `DEMO_VERSION` to `8`. Add `distributionBindings: []`, `visitedShareTokens: []`, `activeDistributionBindingId: null`, and `sequence.binding = 1000`, `sequence.commission = 1003`. Extend seeded commission records with `distributorId: 'DST1001'`, `commissionRate: 8`, and `settledAt` for settled entries. Keep all existing sample services, products, assessments, bookings, and orders.

Update every explicit version assertion from `7` to `8`, including the E2E reset title.

- [ ] **Step 5: Run focused tests**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js tests/unit/assessment-seed.spec.js tests/unit/brand-bookings-overview.spec.js`

Expected: all selected tests PASS.

- [ ] **Step 6: Commit the state foundation**

```bash
git add demo/franchise-platform-h5/src/mock/shareLinks.js demo/franchise-platform-h5/src/mock/seed.js demo/franchise-platform-h5/tests
git commit -m "feat: add versioned share attribution state"
```

### Task 3: Implement Share Campaign, Visit, And Binding Transitions

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/selectors.js`
- Modify: `demo/franchise-platform-h5/tests/unit/share-distribution-attribution.spec.js`

- [ ] **Step 1: Write failing campaign and binding tests**

```js
import {
  acceptShareAttribution,
  recordShare,
  recordShareVisit,
} from '../../src/mock/transitions';
import {
  activeDistributionBinding,
  shareRecordByToken,
} from '../../src/mock/selectors';

it('creates a tokenized share campaign and records one browser visit', () => {
  const state = createSeed();
  const share = recordShare(state, {
    channel: '微信好友',
    content: '门店首页',
    contentType: 'store',
    contentId: 'TL1001',
    storeId: 'TL1001',
  });
  expect(share).toMatchObject({
    distributorId: 'DST1001',
    storeId: 'TL1001',
    visits: 0,
    bindings: 0,
    orders: 0,
  });
  expect(share.token).toMatch(/^TL-SH-/);
  expect(recordShareVisit(state, share.token).visits).toBe(1);
  expect(recordShareVisit(state, share.token).visits).toBe(1);
  expect(shareRecordByToken(state, share.token).id).toBe(share.id);
});

it('accepts a share once and keeps the first active binding', () => {
  const state = createSeed();
  const share = recordShare(state, {
    channel: '二维码', content: '精选商品', contentType: 'product', contentId: '3', storeId: 'TL1001',
  });
  const first = acceptShareAttribution(state, {
    token: share.token, customerName: '赵女士', phone: '138****6008',
  });
  const second = acceptShareAttribution(state, {
    token: share.token, customerName: '赵女士', phone: '138****6008',
  });
  expect(second.id).toBe(first.id);
  expect(state.distributionBindings).toHaveLength(1);
  expect(state.referrals.filter((item) => item.id === first.referralId)).toHaveLength(1);
  expect(share.bindings).toBe(1);
  expect(activeDistributionBinding(state).id).toBe(first.id);
});

it('rejects an unknown or structurally invalid share token', () => {
  const state = createSeed();
  expect(() => acceptShareAttribution(state, {
    token: 'missing', customerName: '赵女士', phone: '138****6008',
  })).toThrow('分享链接无效');
  expect(state.distributionBindings).toEqual([]);
});
```

- [ ] **Step 2: Run tests and verify behavioral failures**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js`

Expected: FAIL because the new transitions and selectors are not exported.

- [ ] **Step 3: Implement selectors**

```js
export function shareRecordByToken(state, token) {
  return (state.shareRecords || []).find((item) => item.token === token) || null;
}

export function activeDistributionBinding(state) {
  return (state.distributionBindings || []).find(
    (item) => item.id === state.activeDistributionBindingId && item.status === 'active',
  ) || null;
}

export function commissionForOrder(state, orderId) {
  return (state.commissionRecords || []).find((item) => item.orderId === orderId) || null;
}
```

- [ ] **Step 4: Replace numeric-only sharing with campaign transitions**

```js
export function recordShare(state, input) {
  const id = nextId(state, 'share', 'SH20260730');
  const record = {
    id,
    token: `TL-SH-${id.slice(-4)}`,
    distributorId: state.distributor.id,
    storeId: input.storeId,
    contentType: input.contentType,
    contentId: input.contentId,
    channel: input.channel,
    content: input.content,
    visits: 0,
    bindings: 0,
    orders: 0,
    at: state.demoNow,
  };
  state.shareRecords.unshift(record);
  appendActivity(state, { type: 'share-recorded', entityId: record.id, channel: record.channel });
  return record;
}

export function recordShareVisit(state, token) {
  const share = findRequired(
    state.shareRecords,
    (item) => item.token === token && item.distributorId && item.storeId,
    '分享链接无效',
  );
  state.visitedShareTokens = state.visitedShareTokens || [];
  if (!state.visitedShareTokens.includes(token)) {
    state.visitedShareTokens.push(token);
    share.visits += 1;
  }
  return share;
}
```

- [ ] **Step 5: Implement persistent binding**

```js
export function acceptShareAttribution(state, input) {
  const share = findRequired(
    state.shareRecords,
    (item) => item.token === input.token && item.distributorId && item.storeId,
    '分享链接无效',
  );
  const existing = (state.distributionBindings || []).find(
    (item) => item.status === 'active',
  );
  if (existing) {
    state.activeDistributionBindingId = existing.id;
    return existing;
  }
  const referral = addReferral(state, { name: input.customerName, phone: input.phone });
  const binding = {
    id: nextId(state, 'binding', 'DB20260730'),
    shareId: share.id,
    token: share.token,
    distributorId: share.distributorId,
    referralId: referral.id,
    customerId: nextId(state, 'customer', 'CUS'),
    customerName: input.customerName,
    phone: input.phone,
    storeId: share.storeId,
    status: 'active',
    acceptedAt: state.demoNow,
    firstOrderId: null,
  };
  state.distributionBindings.unshift(binding);
  state.activeDistributionBindingId = binding.id;
  share.bindings += 1;
  appendActivity(state, { type: 'distribution-bound', entityId: binding.id, shareId: share.id });
  return binding;
}
```

- [ ] **Step 6: Run focused tests and commit**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js tests/unit/growth-modules.spec.js`

Expected: PASS after updating the old `recordShare` test to use campaign fields and expect zero initial counters.

```bash
git add demo/franchise-platform-h5/src/mock demo/franchise-platform-h5/tests/unit
git commit -m "feat: add share campaign and referral binding transitions"
```

### Task 4: Generate Attributed Commission And First-Order Reward

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/selectors.js`
- Modify: `demo/franchise-platform-h5/tests/unit/share-distribution-attribution.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/transitions.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/order-flow.spec.js`

- [ ] **Step 1: Write failing order attribution tests**

```js
function bindFriend(state) {
  const share = recordShare(state, {
    channel: '微信好友', content: '门店首页', contentType: 'store', contentId: 'TL1001', storeId: 'TL1001',
  });
  return acceptShareAttribution(state, {
    token: share.token, customerName: '赵女士', phone: '138****6008',
  });
}

it('creates one 8 percent commission and one first-order reward', () => {
  const state = createSeed();
  const binding = bindFriend(state);
  state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
  const order = createPaidOrder(state, {
    addressId: 'ADDR1', delivery: 'express', paymentAttemptId: 'share-order-001',
  });
  expect(order.distributionAttribution).toMatchObject({
    bindingId: binding.id, distributorId: 'DST1001', commissionRate: 8,
  });
  expect(order.distributionCostCents).toBe(3984);
  expect(commissionForOrder(state, order.id)).toMatchObject({
    amountCents: 3984, status: 'pending', customerName: '赵女士',
  });
  expect(state.referrals.find((item) => item.id === binding.referralId))
    .toMatchObject({ status: 'rewarded', rewardCents: 800, firstOrderId: order.id });
  expect(binding.firstOrderId).toBe(order.id);
});

it('continues commission without repeating the referral reward', () => {
  const state = createSeed();
  const binding = bindFriend(state);
  state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];
  createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express', paymentAttemptId: 'share-order-002' });
  state.cart = [{ productId: 2, quantity: 1, spec: '单桶装' }];
  const second = createPaidOrder(state, { addressId: 'ADDR1', delivery: 'express', paymentAttemptId: 'share-order-003' });
  expect(commissionForOrder(state, second.id).amountCents).toBe(3184);
  expect(state.referrals.find((item) => item.id === binding.referralId).rewardCents).toBe(800);
});

it('keeps ordinary and repeated payments free from duplicate commission', () => {
  const state = createSeed();
  state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];
  const ordinary = createPaidOrder(state, {
    addressId: 'ADDR1', delivery: 'express', paymentAttemptId: 'ordinary-order-001',
  });
  expect(ordinary.distributionAttribution).toBeNull();
  expect(ordinary.distributionCostCents).toBe(0);
  const before = state.commissionRecords.length;
  state.cart = [{ productId: 1, quantity: 1, spec: '单瓶装' }];
  const repeated = createPaidOrder(state, {
    addressId: 'ADDR1', delivery: 'express', paymentAttemptId: 'ordinary-order-001',
  });
  expect(repeated.id).toBe(ordinary.id);
  expect(state.commissionRecords).toHaveLength(before);
});
```

- [ ] **Step 2: Run tests and verify attribution failures**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js`

Expected: FAIL because paid orders do not yet join the active binding or write commissions.

- [ ] **Step 3: Add attribution to `createPaidOrder`**

Before constructing the order, find the active binding, its share, and the distributor. Compute:

```js
const binding = (state.distributionBindings || []).find(
  (item) => item.id === state.activeDistributionBindingId && item.status === 'active',
);
const share = binding
  ? (state.shareRecords || []).find((item) => item.id === binding.shareId)
  : null;
const distributor = binding && state.distributor?.id === binding.distributorId
  ? state.distributor
  : null;
const commissionRate = distributor ? Number(distributor.commissionRate || 0) : 0;
const distributionCostCents = distributor
  ? Math.round((paidCents * commissionRate) / 100)
  : 0;
```

Set `distributionAttribution` to `null` without a valid join, otherwise store `shareId`, `bindingId`, `distributorId`, `referralId`, and `commissionRate`. Use the computed `distributionCostCents` in both order and profit-sharing records.

- [ ] **Step 4: Write commission and first-order reward atomically**

After adding the new order and profit-sharing record, execute this exact guarded behavior:

```js
if (binding && share && distributor) {
  const commission = {
    id: nextId(state, 'commission', 'CM20260730'),
    orderId: order.id,
    shareId: share.id,
    bindingId: binding.id,
    distributorId: distributor.id,
    customerName: binding.customerName,
    commissionRate,
    amountCents: distributionCostCents,
    status: 'pending',
    at: state.demoNow,
    settledAt: null,
  };
  state.commissionRecords.unshift(commission);
  distributor.totalCommissionCents += commission.amountCents;
  distributor.pendingCents += commission.amountCents;
  share.orders += 1;
  if (!binding.firstOrderId) {
    binding.firstOrderId = order.id;
    const referral = state.referrals.find((item) => item.id === binding.referralId);
    referral.status = 'rewarded';
    referral.rewardCents = Number(state.referral.rewardCents || 0);
    referral.firstOrderId = order.id;
    referral.rewardedAt = state.demoNow;
  }
}
```

The existing payment-attempt idempotency check must remain the first operation in `createPaidOrder`.

- [ ] **Step 5: Update affected existing expectations**

Existing tests that create ordinary new orders must expect `distributionCostCents: 0` rather than an unconditional 5%. Seeded historical order totals remain unchanged. Add a balance assertion that `totalCommissionCents === settledCents + pendingCents` after each attributed order.

- [ ] **Step 6: Run order and growth tests, then commit**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js tests/unit/transitions.spec.js tests/unit/order-flow.spec.js tests/unit/growth-modules.spec.js`

Expected: all selected tests PASS.

```bash
git add demo/franchise-platform-h5/src/mock demo/franchise-platform-h5/tests/unit
git commit -m "feat: generate commission from attributed orders"
```

### Task 5: Settle Commission With Profit Sharing

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Modify: `demo/franchise-platform-h5/tests/unit/share-distribution-attribution.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/transitions.spec.js`

- [ ] **Step 1: Write the failing settlement test**

```js
it('settles attributed commission when profit sharing completes', () => {
  const state = createSeed();
  bindFriend(state);
  state.cart = [{ productId: 3, quantity: 1, spec: '盒装' }];
  const order = createPaidOrder(state, {
    addressId: 'ADDR1', delivery: 'express', paymentAttemptId: 'share-settlement-001',
  });
  const sharing = state.profitSharingRecords.find((item) => item.orderId === order.id);
  const commission = commissionForOrder(state, order.id);
  const settledBefore = state.distributor.settledCents;
  advanceSharing(state, sharing.id, 'processing');
  advanceSharing(state, sharing.id, 'completed');
  expect(commission).toMatchObject({ status: 'settled', settledAt: state.demoNow });
  expect(state.distributor.settledCents).toBe(settledBefore + commission.amountCents);
  expect(state.distributor.pendingCents).toBeGreaterThanOrEqual(0);
  expect(() => advanceSharing(state, sharing.id, 'completed'))
    .toThrow('分账状态不可直接跳转');
});
```

- [ ] **Step 2: Run the focused test and verify failure**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js`

Expected: FAIL because `advanceSharing` does not update commission state.

- [ ] **Step 3: Settle the joined commission only on completion**

Add after the sharing status update:

```js
if (nextStatus === 'completed') {
  const commission = (state.commissionRecords || []).find(
    (item) => item.orderId === record.orderId && item.status === 'pending',
  );
  if (commission) {
    commission.status = 'settled';
    commission.settledAt = state.demoNow;
    const distributor = state.distributor?.id === commission.distributorId
      ? state.distributor
      : null;
    if (distributor) {
      distributor.pendingCents -= commission.amountCents;
      distributor.settledCents += commission.amountCents;
    }
  }
}
```

Do not settle on `processing`, `returning`, or `returned`. Existing transition validation prevents a completed record from being completed twice.

- [ ] **Step 4: Verify settlement and withdrawal balances**

Run: `npx vitest run tests/unit/share-distribution-attribution.spec.js tests/unit/transitions.spec.js tests/unit/growth-modules.spec.js`

Expected: PASS, including distributor withdrawal availability derived from the increased settled amount.

- [ ] **Step 5: Commit settlement integration**

```bash
git add demo/franchise-platform-h5/src/mock/transitions.js demo/franchise-platform-h5/tests/unit
git commit -m "feat: settle distributor commission with profit sharing"
```

### Task 6: Add Consumer Share Landing And Attribution Feedback

**Files:**
- Create: `demo/franchise-platform-h5/src/views/customer/ShareEntry.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Share.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Checkout.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/PaymentSuccess.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Modify: `demo/franchise-platform-h5/tests/unit/growth-pages.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js`

- [ ] **Step 1: Write failing route and page tests**

```js
import CustomerShareEntry from '../../src/views/customer/ShareEntry.vue';

it('loads the simulated friend share-entry route', async () => {
  const route = createRouter().match('/c/store/TL1001/share-entry/TL-SH-1004');
  const component = await route.matched.at(-1).components.default();
  expect(component.default.name).toBe('CustomerShareEntry');
});

it('accepts a valid recommendation from the landing page', async () => {
  const share = demoStore.commit((draft) => recordShare(draft, {
    channel: '二维码', content: '门店首页', contentType: 'store', contentId: 'TL1001', storeId: 'TL1001',
  }));
  const token = demoStore.state.data.shareRecords[0].token;
  const router = createRouter();
  await router.push(`/c/store/TL1001/share-entry/${token}`);
  const wrapper = shallowMount(CustomerShareEntry, {
    router,
    stubs: { ArrowRight: true, Gift: true, Store: true, UserRound: true },
  });
  wrapper.setData({ customerName: '赵女士', phone: '138****6008' });
  await wrapper.vm.accept();
  expect(demoStore.state.data.distributionBindings).toHaveLength(1);
  expect(router.currentRoute.path).toBe('/c/store/TL1001');
});
```

`demoStore.commit` returns no value, so the test intentionally reads the generated token from persisted state.

- [ ] **Step 2: Run page tests and verify failure**

Run: `npx vitest run tests/unit/growth-pages.spec.js tests/unit/router.spec.js`

Expected: FAIL because the route and page do not exist.

- [ ] **Step 3: Add the share-entry route**

```js
{ path: 'share-entry/:token', name: 'customer-share-entry', component: view('customer', 'ShareEntry'), meta: meta('customer', '好友推荐') },
```

- [ ] **Step 4: Implement `ShareEntry.vue` behavior**

The component must:

```js
computed: {
  state() { return demoStore.state.data; },
  share() { return shareRecordByToken(this.state, this.$route.params.token); },
  distributor() {
    return this.share && this.state.distributor?.id === this.share.distributorId
      ? this.state.distributor
      : null;
  },
  valid() {
    return Boolean(this.share && this.distributor
      && this.state.stores.some((item) => item.id === this.share.storeId && item.status === 'active'));
  },
},
created() {
  if (this.valid) {
    demoStore.commit((draft) => recordShareVisit(draft, this.$route.params.token));
  }
},
methods: {
  accept() {
    if (!this.valid) return;
    demoStore.commit((draft) => acceptShareAttribution(draft, {
      token: this.$route.params.token,
      customerName: this.customerName.trim(),
      phone: this.phone.trim(),
    }));
    return this.$router.push(`/c/store/${this.share.storeId}`);
  },
},
```

Render “由陈女士推荐”、shared content, name and phone inputs, “接受推荐并进入商城”, and an invalid-link state that routes back to `/c/store/TL1001`. Use existing customer page spacing, colors, and controls; do not add a simulated phone frame.

- [ ] **Step 5: Make `Share.vue` generate real campaigns and URLs**

Remove static `url`, `visits`, and `orders` from templates. On “模拟分享” call `recordShare` with the selected template and active store, store the returned token by reading `state.shareRecords[0]`, and derive:

```js
generatedUrl() {
  return this.generatedShare
    ? buildShareEntryUrl(window.location, this.generatedShare.storeId, this.generatedShare.token)
    : '';
},
generatedPath() {
  return this.generatedShare
    ? buildShareEntryPath(this.generatedShare.storeId, this.generatedShare.token)
    : '';
},
```

Feed `generatedUrl` into `QrCode`, provide copy-link and “模拟好友打开” actions, and display visits/bindings/orders in the share history.

- [ ] **Step 6: Add checkout and payment feedback**

In `Checkout.vue`, derive `activeDistributionBinding(state)` and render:

```html
<section v-if="binding" class="checkout-referral">
  <strong>推荐关系已绑定</strong>
  <span>由 {{ state.distributor.name }} 推荐，本次订单将计入分享归因</span>
</section>
```

In `PaymentSuccess.vue`, derive the current order and render only for attributed orders:

```html
<p v-if="order.distributionAttribution" data-testid="attribution-success">
  本单已计入分享归因，推荐奖励与分销佣金已生成
</p>
```

- [ ] **Step 7: Run page tests and commit**

Run: `npx vitest run tests/unit/growth-pages.spec.js tests/unit/router.spec.js tests/unit/order-flow.spec.js`

Expected: PASS with no Vue warnings or missing route components.

```bash
git add demo/franchise-platform-h5/src/views/customer demo/franchise-platform-h5/src/router demo/franchise-platform-h5/tests/unit
git commit -m "feat: add simulated friend share landing flow"
```

### Task 7: Show Attribution And Commission In Brand Operations

**Files:**
- Modify: `demo/franchise-platform-h5/src/views/brand/Orders.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/ProfitSharingDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Distribution.vue`
- Modify: `demo/franchise-platform-h5/tests/unit/growth-pages.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/brand-console.spec.js`

- [ ] **Step 1: Write failing brand display tests**

Create an attributed paid order in the test setup, then assert:

```js
const orders = shallowMount(BrandOrders, { stubs: elementStubs });
orders.vm.openDetail(order);
expect(orders.text()).toContain('分享归因');
expect(orders.text()).toContain('陈女士');

const sharing = state.profitSharingRecords.find((item) => item.orderId === order.id);
await router.push(`/brand/sharing/${sharing.id}`);
const detail = shallowMount(BrandProfitSharingDetail, { router, stubs: elementStubs });
expect(detail.text()).toContain('8%');
expect(detail.text()).toContain(formatMoney(order.distributionCostCents));

const distribution = shallowMount(BrandDistribution, { stubs: elementStubs });
expect(distribution.text()).toContain('分享绑定');
expect(distribution.text()).toContain('待结算佣金');
```

- [ ] **Step 2: Run the page tests and verify copy is absent**

Run: `npx vitest run tests/unit/growth-pages.spec.js tests/unit/brand-console.spec.js`

Expected: FAIL on the new attribution labels.

- [ ] **Step 3: Extend the brand order drawer**

For attributed orders, add a “分享归因” section containing distributor name, binding customer, share record ID, commission rate, and commission amount. Unattributed orders render “普通订单” in the table source column.

- [ ] **Step 4: Join commission into profit-sharing detail**

Add:

```js
commission() {
  return commissionForOrder(this.state, this.record.orderId);
},
```

Render distributor name, `commission.commissionRate + '%'`, amount, and pending/settled state. The existing brand net calculation continues to subtract `record.distributionCostCents`.

- [ ] **Step 5: Extend brand distribution metrics**

Update `brandGrowthOverview` to return `shareBindings`, `attributedOrders`, and `settledCommissionCents`. Display these alongside visits, pending commission, and existing withdrawal metrics. Derive all counts from records; do not hard-code page values.

- [ ] **Step 6: Run page tests and commit**

Run: `npx vitest run tests/unit/growth-pages.spec.js tests/unit/brand-console.spec.js tests/unit/share-distribution-attribution.spec.js`

Expected: PASS.

```bash
git add demo/franchise-platform-h5/src/views/brand demo/franchise-platform-h5/src/mock/selectors.js demo/franchise-platform-h5/tests/unit
git commit -m "feat: expose share commission in brand operations"
```

### Task 8: Complete Browser Flow, Regression, And OpenSpec Tracking

**Files:**
- Create: `demo/franchise-platform-h5/tests/e2e/share-distribution-attribution.spec.js`
- Modify: `openspec/changes/share-distribution-attribution-demo/tasks.md`

- [ ] **Step 1: Write the end-to-end browser flow**

```js
import { expect, test } from '@playwright/test';

test.describe('share distribution attribution demo', () => {
  test.skip(({ isMobile }) => isMobile, '完整跨角色链路在桌面项目执行');

  test('runs share, binding, order, settlement, withdrawal and approval', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001/share');
    await page.getByRole('button', { name: /模拟分享/ }).click();
    await page.getByRole('link', { name: /模拟好友打开/ }).click();
    await expect(page.getByText('由陈女士推荐')).toBeVisible();
    await page.getByLabel('好友姓名').fill('赵女士');
    await page.getByLabel('好友手机').fill('138****6008');
    await page.getByRole('button', { name: '接受推荐并进入商城' }).click();

    await page.goto('/#/c/store/TL1001/product/3');
    await page.getByRole('button', { name: /加入购物车/ }).click();
    await page.goto('/#/c/store/TL1001/checkout');
    await expect(page.getByText('推荐关系已绑定')).toBeVisible();
    await page.getByRole('link', { name: /提交订单/ }).click();
    await page.getByRole('button', { name: /确认支付/ }).click();
    await expect(page.getByTestId('attribution-success')).toBeVisible();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/brand/sharing');
    await page.getByRole('button', { name: '查看' }).first().click();
    await page.getByRole('button', { name: '开始分账' }).click();
    await page.getByRole('button', { name: '确认推进' }).click();
    await page.getByRole('button', { name: '确认完成' }).click();
    await page.getByRole('button', { name: '确认推进' }).click();
    await expect(page.getByText('已结算')).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/#/c/store/TL1001/distribution');
    await page.getByLabel('提现金额').fill('10');
    await page.getByLabel('收款账号').fill('138****6008');
    await page.getByRole('button', { name: '提交提现申请' }).click();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/#/brand/withdrawals');
    await page.getByRole('button', { name: '通过' }).first().click();
    await page.getByRole('button', { name: '通过' }).first().click();
    await expect(page.getByText('已到账').first()).toBeVisible();
  });
});
```

The implementation must expose the exact accessible names used by this test. Add `aria-label` to a control when its visible label does not produce that accessible name; do not replace these business-action locators with CSS selectors.

- [ ] **Step 2: Run the focused E2E test**

Run: `npx playwright test tests/e2e/share-distribution-attribution.spec.js`

Expected: desktop project PASS; mobile project skipped by the explicit test guard.

- [ ] **Step 3: Run the complete verification suite**

Run:

```text
npm run test
npm run build
npx playwright test
npx openspec validate share-distribution-attribution-demo
```

Expected:

- All Vitest files and tests PASS.
- Vite production build succeeds; the existing chunk-size warning is allowed.
- All configured desktop E2E tests PASS and intentional mobile skips remain skips.
- OpenSpec reports the change valid.

- [ ] **Step 4: Update OpenSpec tasks with verified status**

Mark each implementation checkbox complete only after its focused and full verification commands pass. Keep the OpenSpec change active; do not archive it until code, tests, and the requirements delta agree.

- [ ] **Step 5: Commit final browser flow and tracking**

```bash
git add demo/franchise-platform-h5/tests/e2e/share-distribution-attribution.spec.js openspec/changes/share-distribution-attribution-demo/tasks.md
git commit -m "test: cover share distribution attribution journey"
```

- [ ] **Step 6: Inspect the final worktree**

Run: `git status --short && git log --oneline -8`

Expected: only the pre-existing untracked `.superpowers/` directory remains; implementation and OpenSpec files are committed.
