# Demo Detail Drilldowns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every record-oriented Demo surface expose a concrete detail action, while reusing existing routes and drawers and adding only one local live-status transition.

**Architecture:** Direct drilldowns use existing route parameters. Dashboard records that already have list-page drawers use `recordId` query parameters and page-local lookup. Static operational tables add small page-local Element UI drawers; store live status changes remain in `mock/transitions.js`.

**Tech Stack:** Vue 2, Vue Router 3, Element UI, local observable Demo store, Vitest, Vue Test Utils, Playwright, OpenSpec.

---

## File Structure

- Create `openspec/changes/complete-demo-detail-drilldowns/`: proposal, design, requirement delta, and tasks.
- Modify brand `Dashboard.vue` and `Analytics.vue`: direct store and sharing routes.
- Modify store `Dashboard.vue` and brand `StoreDetail.vue`: query-driven links to existing drawers.
- Modify store `Orders.vue`, store `Bookings.vue`, and brand `Orders.vue`: open the requested record from `recordId`.
- Modify brand `Distribution.vue`, store/brand `Withdrawals.vue`, store/brand `Live.vue`, and brand `Devices.vue`: explicit view actions and local drawers.
- Modify `mock/transitions.js`: validated live status transition.
- Modify focused unit suites and add `tests/e2e/detail-drilldowns.spec.js`.

### Task 1: OpenSpec Contract

**Files:**
- Create: `openspec/changes/complete-demo-detail-drilldowns/.openspec.yaml`
- Create: `openspec/changes/complete-demo-detail-drilldowns/proposal.md`
- Create: `openspec/changes/complete-demo-detail-drilldowns/design.md`
- Create: `openspec/changes/complete-demo-detail-drilldowns/specs/demo-detail-drilldowns/spec.md`
- Create: `openspec/changes/complete-demo-detail-drilldowns/tasks.md`

- [ ] Create the five OpenSpec artifacts from the approved design.
- [ ] Run `openspec validate complete-demo-detail-drilldowns` and expect `Change 'complete-demo-detail-drilldowns' is valid`.

### Task 2: Existing Route Drilldowns

**Files:**
- Modify: `demo/franchise-platform-h5/src/views/brand/Dashboard.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Analytics.vue`
- Test: `demo/franchise-platform-h5/tests/unit/brand-console.spec.js`

- [ ] Add failing shallow-mount tests that assert ranking links resolve to `/brand/stores/TL1001` and pending sharing links resolve to `/brand/sharing/PS202607303001`.
- [ ] Run `vitest run tests/unit/brand-console.spec.js` and confirm the links are missing.
- [ ] Add fixed-right “查看” columns using `row.storeId` and `row.id`.
- [ ] Re-run the focused suite and expect all tests to pass.

### Task 3: Existing Drawer Deep Links

**Files:**
- Modify: `demo/franchise-platform-h5/src/views/store/Dashboard.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/StoreDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Orders.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Bookings.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Orders.vue`
- Test: `demo/franchise-platform-h5/tests/unit/detail-drilldowns.spec.js`

- [ ] Write failing tests for `/store/orders?recordId=ORD202607301001`, `/store/bookings?recordId=BK202607302001`, and `/brand/orders?recordId=ORD202607301001`.
- [ ] Assert a valid identifier selects the matching record and opens its drawer, while `recordId=UNKNOWN` leaves the drawer closed.
- [ ] Add dashboard/store-detail row links with the exact `recordId` query.
- [ ] Add a shared page-local method shape to each destination:

```js
openRequestedRecord() {
  const record = this.visibleRecords.find((item) => item.id === this.$route.query.recordId);
  if (record) this.openDetail(record);
}
```

- [ ] Call it after creation and watch `$route.query.recordId` so navigation within a mounted page remains correct.
- [ ] Run the focused suite and expect all deep-link cases to pass.

### Task 4: Distribution And Withdrawal Details

**Files:**
- Modify: `demo/franchise-platform-h5/src/views/brand/Distribution.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Withdrawals.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Withdrawals.vue`
- Test: `demo/franchise-platform-h5/tests/unit/growth-pages.spec.js`

- [ ] Add failing tests that call `openDistributor`, `openCommission`, `openShare`, and `openDetail`, then assert the selected object and drawer title/content.
- [ ] Add explicit “查看” actions to each record table/card.
- [ ] Add page-local `selectedRecord`, `detailType`, and `drawerOpen` state where multiple record types share one drawer.
- [ ] Render only fields listed in the approved design and retain existing approve/reject actions.
- [ ] Run `vitest run tests/unit/growth-pages.spec.js` and expect all tests to pass.

### Task 5: Live And Device Details

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Modify: `demo/franchise-platform-h5/src/views/store/Live.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Live.vue`
- Modify: `demo/franchise-platform-h5/src/views/brand/Devices.vue`
- Test: `demo/franchise-platform-h5/tests/unit/live-modules.spec.js`
- Test: `demo/franchise-platform-h5/tests/unit/brand-assessment-pages.spec.js`

- [ ] Add failing transition tests for `upcoming -> live -> ended`, missing room, and illegal restart of an ended room.
- [ ] Implement `transitionLiveRoom(state, { roomId, targetStatus })` with the allowed transition map.
- [ ] Add failing page tests for selecting store live, brand live, and device records.
- [ ] Add “查看” columns and lightweight drawers.
- [ ] In the store drawer, call the transition through `demoStore.commit`, close neither the drawer nor the selected record, and show success/error feedback.
- [ ] Run the two focused suites and expect all tests to pass.

### Task 6: Browser Regression

**Files:**
- Create: `demo/franchise-platform-h5/tests/e2e/detail-drilldowns.spec.js`

- [ ] Write the Playwright flow before implementation completion and confirm it fails at the first missing action.
- [ ] Cover brand dashboard store/sharing routes, store dashboard order/booking drawers, brand store order drawer, distribution/withdrawal/live/device drawers, and store live state synchronization.
- [ ] Assert URL plus drawer title/record identifier after every navigation; do not rely on generic page visibility.
- [ ] Run `playwright test tests/e2e/detail-drilldowns.spec.js --project=desktop-1440` and expect the scenario to pass.

### Task 7: Completion Verification

**Files:**
- Modify: `openspec/changes/complete-demo-detail-drilldowns/tasks.md`

- [ ] Run `vitest run` and require zero failures.
- [ ] Run `npm run build` and record any non-failing chunk-size warning.
- [ ] Run `npm run test:e2e` and require every enabled desktop test to pass.
- [ ] Run `openspec validate complete-demo-detail-drilldowns`.
- [ ] Run `git diff --check`.
- [ ] Execute the defect learning loop and report sibling-entry coverage and remaining risk.

