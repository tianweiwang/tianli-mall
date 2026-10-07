# Brand Catalog Management Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the brand catalog from a read-only list into a local service/product management Demo whose status and store scope immediately drive the store and consumer views.

**Architecture:** Add a focused catalog transition module for validation and mutations, and shared selectors for store-visible catalog queries. Brand UI writes through `demoStore.commit()`, while store and consumer pages remain read-only consumers of selectors; cart and order creation independently enforce the same sellability rules.

**Tech Stack:** Vue 2.7, Vue Router 3, Element UI 2, Vitest 4, Vue Test Utils 1, Playwright 1.62, OpenSpec.

**Working-tree rule:** Several shared files already contain intentional uncommitted membership, distribution, assessment, and regional-agent work. Never reset them and never commit unrelated hunks. Only commit isolated new planning files; leave overlapping implementation changes uncommitted unless a later explicit commit can be proven to contain only this feature.

---

## File Map

- Create `demo/franchise-platform-h5/src/mock/catalogTransitions.js`: catalog input normalization, validation, ID generation, create/update/status transitions.
- Modify `demo/franchise-platform-h5/src/mock/seed.js`: version 11, catalog metadata, active-store scope, catalog sequences.
- Modify `demo/franchise-platform-h5/src/mock/selectors.js`: store-visible services/products and by-ID selectors.
- Modify `demo/franchise-platform-h5/src/mock/cart.js`: add-to-cart sellability gate.
- Modify `demo/franchise-platform-h5/src/mock/transitions.js`: order-time sellability gate.
- Create `demo/franchise-platform-h5/src/components/brand/CatalogEditorDialog.vue`: dynamic service/product editor UI only.
- Modify `demo/franchise-platform-h5/src/views/brand/Products.vue`: management actions and store scope display.
- Modify `demo/franchise-platform-h5/src/layouts/BrandLayout.vue` and `src/router/index.js`: “服务与商品” naming.
- Modify consumer `Home.vue`, `Category.vue`, `ServiceDetail.vue`, `ProductDetail.vue`, `BookingCreate.vue`: store-visible catalog reads.
- Modify `demo/franchise-platform-h5/src/views/store/Products.vue`: store-visible read-only lists.
- Create `demo/franchise-platform-h5/tests/unit/catalog-management.spec.js`: domain and transaction regressions.
- Modify `demo/franchise-platform-h5/tests/unit/catalog-modules.spec.js`, `router.spec.js`, and affected existing assertions.
- Create `demo/franchise-platform-h5/tests/e2e/catalog-management.spec.js`: brand-to-consumer/store browser journey.

### Task 1: Seed Schema And Catalog Domain

**Files:**
- Create: `demo/franchise-platform-h5/tests/unit/catalog-management.spec.js`
- Create: `demo/franchise-platform-h5/src/mock/catalogTransitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`

- [ ] **Step 1: Write failing schema and mutation tests**

Add tests that require version 11 metadata and wished-for transition APIs:

```js
import { createSeed, DEMO_VERSION } from '../../src/mock/seed';
import {
  createCatalogItem,
  updateCatalogItem,
  setCatalogItemStatus,
} from '../../src/mock/catalogTransitions';

it('seeds active catalog items for both operating stores', () => {
  const state = createSeed();
  expect(DEMO_VERSION).toBe(11);
  expect(state.products.every((item) => item.status === 'active')).toBe(true);
  expect(state.services.every((item) => item.storeIds.includes('TL1001'))).toBe(true);
});

it('creates and updates a service without changing its id', () => {
  const state = createSeed();
  const item = createCatalogItem(state, 'service', validServiceInput());
  const updated = updateCatalogItem(state, 'service', item.id, { ...validServiceInput(), name: '肩颈舒缓方案' });
  expect(updated).toMatchObject({ id: item.id, name: '肩颈舒缓方案', status: 'active' });
});

it('toggles product status without changing historical fields', () => {
  const state = createSeed();
  const sales = state.products[0].sales;
  setCatalogItemStatus(state, 'product', state.products[0].id, 'inactive');
  expect(state.products[0]).toMatchObject({ status: 'inactive', sales });
});
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
npm test -- tests/unit/catalog-management.spec.js
```

Expected: FAIL because `catalogTransitions.js` does not exist and `DEMO_VERSION` is 10.

- [ ] **Step 3: Extend the seed with explicit catalog metadata**

Change constructors so all initial items include deterministic values:

```js
const DEFAULT_CATALOG_STORE_IDS = ['TL1001', 'TL1002'];

status: 'active',
storeIds: [...DEFAULT_CATALOG_STORE_IDS],
createdAt: DEMO_NOW,
updatedAt: DEMO_NOW,
```

Add `product: 11` and `service: 31` to `sequence`, and set `DEMO_VERSION = 11`.

- [ ] **Step 4: Implement minimal catalog transitions**

Expose exactly these APIs:

```js
export function createCatalogItem(state, type, input) {}
export function updateCatalogItem(state, type, id, input) {}
export function setCatalogItemStatus(state, type, id, status) {}
```

Normalize text, money-in-cents, integer duration/stock, unique trimmed specs, active store IDs, pricing mode, and default image. Reject invalid type, missing required values, non-positive prices, negative stock, incomplete package pricing, non-active stores, unknown IDs, and status values outside `active`/`inactive`. Preserve ID and product sales on update.

- [ ] **Step 5: Run focused tests and verify GREEN**

Run the same Vitest command. Expected: all tests in `catalog-management.spec.js` pass.

### Task 2: Store-Visible Catalog Selectors

**Files:**
- Modify: `demo/franchise-platform-h5/tests/unit/catalog-management.spec.js`
- Modify: `demo/franchise-platform-h5/src/mock/selectors.js`

- [ ] **Step 1: Write failing visibility tests**

```js
import {
  productsForStore,
  servicesForStore,
  productForStoreById,
  serviceForStoreById,
} from '../../src/mock/selectors';

it('filters by active status and store scope', () => {
  const state = createSeed();
  state.products[0].storeIds = ['TL1001'];
  state.products[1].status = 'inactive';
  expect(productsForStore(state, 'TL1002')).not.toContainEqual(expect.objectContaining({ id: 1 }));
  expect(productForStoreById(state, 'TL1001', 1)?.id).toBe(1);
  expect(productForStoreById(state, 'TL1002', 1)).toBeUndefined();
});
```

- [ ] **Step 2: Run and verify RED**

Expected: FAIL because the four selector exports do not exist.

- [ ] **Step 3: Implement shared visibility selectors**

Use one internal predicate:

```js
function isCatalogItemVisibleAtStore(item, storeId) {
  return item?.status === 'active' && item.storeIds?.includes(storeId);
}
```

Return filtered arrays and by-ID values without mutating state. Keep `serviceById()` for historical/admin consumers.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run `npm test -- tests/unit/catalog-management.spec.js` and expect all tests to pass.

### Task 3: Cart And Order Sellability Guards

**Files:**
- Modify: `demo/franchise-platform-h5/tests/unit/catalog-management.spec.js`
- Modify: `demo/franchise-platform-h5/src/mock/cart.js`
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`

- [ ] **Step 1: Write failing add-to-cart and order tests**

Cover inactive, wrong-store, and zero-stock products. For order-time invalidation, add the item while active, then change its state before calling `createPaidOrder()`. Assert the thrown message and assert order count, sharing count, and cart contents remain unchanged.

```js
expect(() => addCartItem(state, { productId: 1, spec: '单瓶装', quantity: 1 }))
  .toThrow('商品已下架或当前门店不可售');
expect(() => createPaidOrder(state, validPaymentInput()))
  .toThrow('商品已下架或当前门店不可售');
expect(state.cart).toHaveLength(1);
```

- [ ] **Step 2: Run and verify RED**

Expected: existing functions accept unavailable products.

- [ ] **Step 3: Add identical boundary checks**

Resolve the product through `productForStoreById(state, state.activeStoreId, id)`, then reject missing visibility with `商品已下架或当前门店不可售` and `stock <= 0` with `商品库存不足`. Complete all checks before constructing order items or mutating any arrays.

- [ ] **Step 4: Run and verify GREEN**

Run catalog management plus existing cart/order suites:

```powershell
npm test -- tests/unit/catalog-management.spec.js tests/unit/cart.spec.js tests/unit/order-flow.spec.js tests/unit/transitions.spec.js
```

Expected: all selected tests pass.

### Task 4: Brand Management UI

**Files:**
- Create: `demo/franchise-platform-h5/src/components/brand/CatalogEditorDialog.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Products.vue`
- Modify: `demo/franchise-platform-h5/src/layouts/BrandLayout.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Modify: `demo/franchise-platform-h5/tests/unit/catalog-modules.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js`

- [ ] **Step 1: Write failing component and page tests**

Require the brand page to expose `openCreate`, `openEdit`, `saveCatalogItem`, and `toggleStatus`; require the dialog to emit normalized form input; require menu/meta text `服务与商品`; ensure store page has no create/edit buttons.

- [ ] **Step 2: Run and verify RED**

Run:

```powershell
npm test -- tests/unit/catalog-modules.spec.js tests/unit/router.spec.js
```

Expected: failures for missing editor and old menu label.

- [ ] **Step 3: Build the focused editor dialog**

Use `el-dialog`, `el-form`, `el-input`, `el-input-number`, `el-select`, `el-checkbox-group`, and `el-radio-group`. Props are `visible`, `type`, `value`, and `stores`; emits are `update:visible` and `submit`. Service fields and product fields render conditionally. The component does not import `demoStore`.

- [ ] **Step 4: Wire the brand page to the domain**

Add the contextual primary button, status/store columns, edit and toggle actions. Submit through:

```js
demoStore.commit((draft) => {
  saved = this.editingId
    ? updateCatalogItem(draft, this.tabType, this.editingId, payload)
    : createCatalogItem(draft, this.tabType, payload);
});
```

Confirm status changes with `$confirm`, then call `setCatalogItemStatus`. Show success/error messages and keep all brand items visible regardless of status.

- [ ] **Step 5: Run and verify GREEN**

Run the two focused suites and expect all tests to pass.

### Task 5: Store And Consumer Synchronization

**Files:**
- Modify: `demo/franchise-platform-h5/src/views/store/Products.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Home.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Category.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/ServiceDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/ProductDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/BookingCreate.vue`
- Modify: `demo/franchise-platform-h5/tests/unit/catalog-modules.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/catalog-management.spec.js`

- [ ] **Step 1: Write failing page integration tests**

Set one item inactive and one item to `['TL1001']`. Mount pages under both store routes and assert counts/details. Assert a new service with no slots renders `暂未开放线上预约`. Assert direct product detail under the wrong store shows the unavailable state and has no add button.

- [ ] **Step 2: Run and verify RED**

Expected: pages still read full `state.services` and `state.products`.

- [ ] **Step 3: Replace direct catalog reads with selectors**

Use `servicesForStore()` and `productsForStore()` in Home, Category, BookingCreate, and StoreProducts. Use store-aware by-ID selectors in details. Preserve historical order/booking selectors and brand page reads.

- [ ] **Step 4: Add explicit unavailable states**

Product detail gains an `else` empty state matching service detail. New service availability continues using existing `serviceAvailability()` and disables online booking when there are no slots.

- [ ] **Step 5: Run and verify GREEN**

Run catalog management, catalog modules, booking flow, cart, router, and store console suites. Expected: all selected tests pass.

### Task 6: Browser Journey, Full Verification, And Release

**Files:**
- Create: `demo/franchise-platform-h5/tests/e2e/catalog-management.spec.js`
- Modify: `openspec/changes/add-brand-catalog-management-demo/tasks.md`

- [ ] **Step 1: Write the Playwright management journey**

At desktop viewport: reset data, open `/brand/products`, create a service for TL1001, verify it appears at `/c/store/TL1001/category?tab=services` and not TL1002, return to brand and deactivate it, then verify it disappears from store and consumer lists. Add a product edit/store-scope assertion and a cart-before-deactivation payment rejection.

- [ ] **Step 2: Run the E2E test and fix only observed failures**

Run:

```powershell
npm run test:e2e -- tests/e2e/catalog-management.spec.js --project=chromium-desktop
```

Expected: the new journey passes with no page errors.

- [ ] **Step 3: Run full local verification**

```powershell
npm run test
npm run build
npm run test:e2e
openspec validate add-brand-catalog-management-demo --strict
git diff --check
```

Record exact test counts, explicit skips, build warnings, and any unrelated pre-existing failures. Mark OpenSpec tasks complete only after their evidence exists.

- [ ] **Step 4: Run the defect learning loop**

Confirm the root cause was the read-only scope and direct array consumers; prove failing-before/passing-after regressions; scan Home, Category, details, BookingCreate, StoreProducts, cart, checkout/payment, live products, and historical order/booking consumers; classify the rule outcome without changing global rules.

- [ ] **Step 5: Build and deploy a fresh atomic release**

Build to a new explicit staging directory, archive and hash it, authenticate with a protected temporary key copy, upload to `/tmp`, extract to `/srv/tianli/releases/franchise-demo-<timestamp>`, run `nginx -t`, atomically switch `/srv/tianli/franchise-demo`, reload, and retain the previous release.

- [ ] **Step 6: Verify public deployment**

Require external HTTP 200 for index, emitted JS/CSS, `/franchise-demo/#/brand/products`, and the new catalog management chunk/text. Report public URL, active release, previous rollback release, local verification counts, and limitations of the static localStorage Demo.
