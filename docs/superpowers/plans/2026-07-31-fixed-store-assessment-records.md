# Fixed Store Assessment Records Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the assessment task-claim flow with automatic fixed-store-device binding and add privacy-safe concrete assessment records to the brand console.

**Architecture:** Keep the existing local assessment domain split: deterministic fixtures provide stores, devices, tasks, and reports; pure transitions own task state changes; pure selectors provide role-scoped presentation rows; Vue pages only manage filters and interaction state. Reuse the existing Element UI table/drawer patterns and preserve all booking QR behavior.

**Tech Stack:** Vue 2.7, Vue Router 3, Element UI 2, Vitest 4, Vue Test Utils 1, Playwright 1.62, Vite 7, OpenSpec.

---

## File Map

- Create `openspec/changes/refine-fixed-device-assessment-demo/proposal.md`: scope and impact.
- Create `openspec/changes/refine-fixed-device-assessment-demo/design.md`: implementation decisions and boundaries.
- Create `openspec/changes/refine-fixed-device-assessment-demo/tasks.md`: tracked implementation checklist.
- Create `openspec/changes/refine-fixed-device-assessment-demo/specs/franchise-assessment-demo/spec.md`: behavior deltas and acceptance scenarios.
- Modify `demo/franchise-platform-h5/src/mock/assessmentFixtures.js`: version-4 fixed-device tasks and cross-store published records.
- Modify `demo/franchise-platform-h5/src/mock/assessmentTransitions.js`: automatic device binding and simplified task state machine.
- Modify `demo/franchise-platform-h5/src/mock/assessmentSelectors.js`: published brand record rows, masking, device metadata, and comparison flags.
- Modify `demo/franchise-platform-h5/src/mock/seed.js`: increment deterministic state version and sequences.
- Modify `demo/franchise-platform-h5/src/views/store/Assessments.vue`: new status filters, labels, and progress counts.
- Modify `demo/franchise-platform-h5/src/views/store/AssessmentDetail.vue`: remove task QR/claim UI and start from the bound device.
- Modify `demo/franchise-platform-h5/src/views/store/Dashboard.vue`: remove the device-claim wording.
- Modify `demo/franchise-platform-h5/src/views/brand/AssessmentAnalytics.vue`: record filters, table, and right-side drawer.
- Modify focused unit and E2E specs under `demo/franchise-platform-h5/tests/`.

### Task 1: Establish the OpenSpec Contract

**Files:**
- Create: `openspec/changes/refine-fixed-device-assessment-demo/proposal.md`
- Create: `openspec/changes/refine-fixed-device-assessment-demo/design.md`
- Create: `openspec/changes/refine-fixed-device-assessment-demo/tasks.md`
- Create: `openspec/changes/refine-fixed-device-assessment-demo/specs/franchise-assessment-demo/spec.md`

- [ ] **Step 1: Write the proposal and scoped design**

Use the approved design document as the source of truth. The proposal must state:

```markdown
## Why

The assessment Demo currently models fixed store equipment as a device task-claim flow, and the brand console cannot inspect published metric records.

## What Changes

- Automatically bind an online fixed device when a store creates an assessment task.
- Replace waiting/claimed states with ready/measuring/awaiting-review/completed.
- Add privacy-safe published assessment rows and a detail drawer to the brand console.
- Preserve offline retry, incomplete payload, duplicate payload, and cross-store quarantine.

## Impact

- Only the local H5 Demo, its tests, and this OpenSpec change are modified.
- Booking verification QR behavior and formal CRMEB code remain unchanged.
```

- [ ] **Step 2: Write requirement deltas with explicit scenarios**

The delta spec must include these requirements and scenarios:

```markdown
## MODIFIED Requirements

### Requirement: Fixed-store assessment task
The Demo SHALL bind an online device owned by the task store during task creation.

#### Scenario: Create a ready task
- **WHEN** a valid booking or walk-in assessment is created for a store with an online device
- **THEN** the task SHALL contain that device ID and status `ready`

#### Scenario: Store device unavailable
- **WHEN** the store has no online fixed device
- **THEN** task creation SHALL fail without appending an assessment task

### Requirement: Published brand assessment records
The brand assessment page SHALL show concrete published records without exposing restricted customer data.

#### Scenario: Inspect a published record
- **WHEN** a brand user opens a published record
- **THEN** the drawer SHALL show four metrics, previous/first comparisons, device metadata, report version, and recommendation
- **THEN** it SHALL NOT show a full phone number, assessment images, metric editing, or medical conclusions
```

Also preserve explicit scenarios for offline retry on the same task, incomplete payload, duplicate payload, cross-store quarantine, deterministic reset, and booking QR non-regression.

- [ ] **Step 3: Validate the change before coding**

Run:

```powershell
openspec validate refine-fixed-device-assessment-demo
```

Expected: the change is valid with no missing delta headers or malformed scenarios.

- [ ] **Step 4: Commit the OpenSpec contract**

```powershell
git add -- openspec/changes/refine-fixed-device-assessment-demo
git commit -m "docs: specify fixed-device assessment demo"
```

### Task 2: Define the Fixed-Device State Machine with Failing Tests

**Files:**
- Modify: `demo/franchise-platform-h5/tests/unit/assessment-transitions.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/assessment-seed.spec.js`
- Modify: `demo/franchise-platform-h5/src/mock/assessmentTransitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/assessmentFixtures.js`
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`

- [ ] **Step 1: Replace claim-flow expectations with automatic binding tests**

Remove `claimAssessmentTask` imports/calls and add assertions equivalent to:

```js
it('automatically binds the store online device and creates a ready task', () => {
  const state = createSeed();
  const task = createAssessmentTask(state, {
    bookingId: 'BK202607302001',
    customerId: 'CUS1001',
    storeId: 'TL1001',
  });

  expect(task).toMatchObject({
    storeId: 'TL1001',
    deviceId: 'DEV-TL-001',
    status: 'ready',
  });
  expect(task.attempts).toEqual([{
    deviceId: 'DEV-TL-001',
    status: 'ready',
    boundAt: state.demoNow,
  }]);
});

it('does not append a task when the store has no online device', () => {
  const state = createSeed();
  const before = state.assessmentTasks.length;

  expect(() => createAssessmentTask(state, {
    customerId: 'TMP1001',
    storeId: 'TL1002',
  })).toThrow('本店检测设备当前不可用');
  expect(state.assessmentTasks).toHaveLength(before);
});
```

For the walk-in failure test, snapshot customer, consent, and task counts and assert all three remain unchanged when `TL1002` has no online device.

- [ ] **Step 2: Update normal and exceptional flow tests**

Start the seed task directly and assert the same binding survives retry:

```js
const task = state.assessmentTasks.find((item) => item.id === 'AST202607301003');
expect(task).toMatchObject({ deviceId: 'DEV-TL-001', status: 'ready' });
startAssessment(state, task.id);
markAssessmentOffline(state, task.id);
retryAssessmentTask(state, task.id);
expect(task).toMatchObject({ deviceId: 'DEV-TL-001', status: 'measuring' });
```

Keep the existing incomplete, duplicate, detached-payload, quarantine, report, and reassessment assertions. Expected report counts must reflect the new cross-store seed report.

- [ ] **Step 3: Update deterministic seed expectations**

Assert:

```js
expect(DEMO_VERSION).toBe(4);
expect(state.assessmentTasks.some((item) => ['waiting-device', 'claimed'].includes(item.status))).toBe(false);
expect(state.assessmentTasks.find((item) => item.id === 'AST202607301003')).toMatchObject({
  status: 'ready',
  deviceId: 'DEV-TL-001',
});
expect(state.assessmentRecords).toHaveLength(3);
expect(new Set(state.assessmentRecords.map((item) => item.storeId))).toEqual(new Set(['TL1001', 'TL1002']));
```

- [ ] **Step 4: Run focused tests and verify the red phase**

Run from `demo/franchise-platform-h5`:

```powershell
.\node_modules\.bin\vitest.cmd run tests/unit/assessment-transitions.spec.js tests/unit/assessment-seed.spec.js
```

Expected: FAIL because tasks still use `waiting-device`, require `claimAssessmentTask`, and the seed is version 3 with two reports.

- [ ] **Step 5: Implement minimal automatic binding**

Add one local resolver and use it before any mutation:

```js
function onlineDeviceForStore(state, storeId) {
  return findRequired(
    state.devices,
    (item) => item.storeId === storeId && item.status === 'online',
    '本店检测设备当前不可用',
  );
}

function readyAttempt(deviceId, boundAt) {
  return { deviceId, status: 'ready', boundAt };
}
```

Both creation functions must set `deviceId`, `status: 'ready'`, and one ready attempt. Remove the exported `claimAssessmentTask`. Change `startAssessment` to accept only `ready` and mutate the existing final attempt to `measuring`.

- [ ] **Step 6: Update fixtures and version**

Preserve the two existing reports exactly. Add a neutral TL1002 customer, consent, completed task, and published record using `DEV-TL-002`; update sequence counters to the highest seeded suffix. Change `DEMO_VERSION` from `3` to `4`, and make `AST202607301003` ready and bound to `DEV-TL-001`.

- [ ] **Step 7: Run focused tests and verify the green phase**

Run the same focused Vitest command.

Expected: both spec files pass and no claim-flow import remains.

- [ ] **Step 8: Commit the domain change**

```powershell
git add -- demo/franchise-platform-h5/src/mock/assessmentFixtures.js demo/franchise-platform-h5/src/mock/assessmentTransitions.js demo/franchise-platform-h5/src/mock/seed.js demo/franchise-platform-h5/tests/unit/assessment-transitions.spec.js demo/franchise-platform-h5/tests/unit/assessment-seed.spec.js
git commit -m "feat: bind assessment tasks to store devices"
```

### Task 3: Expose Privacy-Safe Brand Record Rows

**Files:**
- Modify: `demo/franchise-platform-h5/tests/unit/assessment-selectors.spec.js`
- Modify: `demo/franchise-platform-h5/src/mock/assessmentSelectors.js`

- [ ] **Step 1: Write failing selector tests**

Extend the brand aggregate test with:

```js
expect(operations.recordRows).toHaveLength(3);
expect(operations.recordRows.every((row) => row.reportStatus === 'published')).toBe(true);
expect(operations.recordRows.every((row) => !/1\d{10}/.test(row.customerPhone))).toBe(true);
expect(operations.recordRows[0]).toMatchObject({
  reportId: expect.stringMatching(/^ASR/),
  storeName: expect.any(String),
  deviceName: expect.any(String),
  deviceModel: 'TL-PA100',
  reportVersion: 1,
  metrics: {
    shoulderHeightDiffMm: expect.any(Number),
    pelvicTiltDeg: expect.any(Number),
    neckForwardAngleDeg: expect.any(Number),
    discomfortScore: expect.any(Number),
  },
});
```

Add a pending synthetic record and confirm it is excluded. Assert the first customer report has `hasPreviousComparison: false`, while the second has true comparison flags and `previousDelta: -4` for shoulder height.

- [ ] **Step 2: Run the selector spec and verify it fails**

```powershell
.\node_modules\.bin\vitest.cmd run tests/unit/assessment-selectors.spec.js
```

Expected: FAIL because `recordRows` is not returned.

- [ ] **Step 3: Build record rows from published reports**

Inside `assessmentOperationsForBrand`, map only published reports. Each row must contain report/customer/store/device metadata, the raw four-metric object, recommendation, and a `metricList` copied from `buildAssessmentComparison`.

Use explicit comparison-presence flags without changing the existing customer comparison API:

```js
const comparison = buildAssessmentComparison(state, record.id);
return {
  reportId: record.id,
  reportStatus: record.reportStatus,
  customerName: customer?.name || '未知顾客',
  customerPhone: maskPhone(customer?.phone),
  storeId: record.storeId,
  storeName: store?.name || record.storeId,
  deviceId: record.deviceId,
  deviceName: device?.name || record.deviceId,
  deviceModel: device?.model || '-',
  softwareVersion: record.softwareVersion || device?.softwareVersion || '-',
  measuredAt: record.measuredAt,
  reportVersion: record.version,
  recommendation: record.recommendation,
  metrics: { ...record.metrics },
  metricList: comparison.metricList,
  hasPreviousComparison: comparison.previousRecord.id !== record.id,
  hasFirstComparison: comparison.firstRecord.id !== record.id,
};
```

Sort rows newest first and return them as `recordRows` without removing existing aggregates.

- [ ] **Step 4: Run the selector spec and verify it passes**

Expected: the selector spec passes with published-only, masked cross-store rows.

- [ ] **Step 5: Commit the selector change**

```powershell
git add -- demo/franchise-platform-h5/src/mock/assessmentSelectors.js demo/franchise-platform-h5/tests/unit/assessment-selectors.spec.js
git commit -m "feat: expose brand assessment record rows"
```

### Task 4: Simplify the Store Assessment UI

**Files:**
- Modify: `demo/franchise-platform-h5/tests/unit/store-assessment-pages.spec.js`
- Modify: `demo/franchise-platform-h5/src/views/store/Assessments.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/AssessmentDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Dashboard.vue`

- [ ] **Step 1: Write failing page assertions**

Update the task fixture status to `ready` with `deviceId`. For detail, assert:

```js
expect(wrapper.text()).toContain('体态检测仪 A01');
expect(wrapper.text()).toContain('开始检测');
expect(wrapper.text()).toContain('正常回传');
expect(wrapper.text()).not.toContain('设备领取任务');
expect(wrapper.findComponent({ name: 'QrCode' }).exists()).toBe(false);
```

Add list assertions for “待检测” and ensure the rendered text does not include “待设备领取” or “已领取”.

- [ ] **Step 2: Run the page spec and verify it fails**

```powershell
.\node_modules\.bin\vitest.cmd run tests/unit/store-assessment-pages.spec.js
```

Expected: FAIL because the QR, claim button, and old status labels remain.

- [ ] **Step 3: Remove claim UI and update state actions**

In `AssessmentDetail.vue`:

- remove `QrCode`, `createTaskToken`, and `claimAssessmentTask` imports;
- remove `taskToken` and `claimTask`;
- replace the QR panel with fixed device metadata;
- show “开始检测” for `ready`;
- keep scenarios available after the task reaches `measuring`;
- remove claim-related activity/status labels.

The start handler remains minimal:

```js
startTask() {
  try {
    demoStore.commit((draft) => startAssessment(draft, this.task.id));
  } catch (error) {
    this.showError(error);
  }
}
```

In `Assessments.vue`, replace the status option/mapping with `ready: '待检测'`, and count `ready`, `measuring`, and `retrying` using the appropriate metric labels. In `Dashboard.vue`, change the task help text to “开始检测、确认结果与跟进复评”.

- [ ] **Step 4: Run store page and domain specs**

```powershell
.\node_modules\.bin\vitest.cmd run tests/unit/store-assessment-pages.spec.js tests/unit/assessment-transitions.spec.js
```

Expected: both files pass.

- [ ] **Step 5: Commit the store UI change**

```powershell
git add -- demo/franchise-platform-h5/src/views/store/Assessments.vue demo/franchise-platform-h5/src/views/store/AssessmentDetail.vue demo/franchise-platform-h5/src/views/store/Dashboard.vue demo/franchise-platform-h5/tests/unit/store-assessment-pages.spec.js
git commit -m "feat: simplify fixed-device store workflow"
```

### Task 5: Add the Brand Record Table and Detail Drawer

**Files:**
- Modify: `demo/franchise-platform-h5/tests/unit/brand-assessment-pages.spec.js`
- Modify: `demo/franchise-platform-h5/src/views/brand/AssessmentAnalytics.vue`

- [ ] **Step 1: Write failing filter and drawer tests**

Mount with stubs for `el-input`, `el-select`, `el-date-picker`, `el-table`, `el-table-column`, `el-button`, and `el-drawer`. Assert:

```js
expect(wrapper.vm.filteredRecords).toHaveLength(3);
wrapper.setData({ storeFilter: 'TL1002' });
expect(wrapper.vm.filteredRecords.every((row) => row.storeId === 'TL1002')).toBe(true);
wrapper.setData({ storeFilter: 'all', dateFilter: '2026-06-30' });
expect(wrapper.vm.filteredRecords.map((row) => row.reportId)).toEqual(['ASR202607301002']);
wrapper.setData({ dateFilter: '', keyword: '陈女士' });
expect(wrapper.vm.filteredRecords).toHaveLength(2);

wrapper.vm.openRecord(wrapper.vm.filteredRecords[0]);
expect(wrapper.vm.drawerOpen).toBe(true);
expect(wrapper.vm.selectedRecord.metricList).toHaveLength(4);
expect(wrapper.text()).not.toMatch(/1\d{10}/);
```

- [ ] **Step 2: Run the brand page spec and verify it fails**

```powershell
.\node_modules\.bin\vitest.cmd run tests/unit/brand-assessment-pages.spec.js
```

Expected: FAIL because filters, `filteredRecords`, and `openRecord` do not exist.

- [ ] **Step 3: Implement table state and filtering**

Add local state:

```js
data() {
  return {
    dateFilter: '',
    drawerOpen: false,
    keyword: '',
    selectedRecord: null,
    storeFilter: 'all',
  };
},
```

Add `filteredRecords` that matches store, `measuredAt.slice(0, 10)`, report ID, or customer name. Do not include phone in the searchable string.

- [ ] **Step 4: Implement the table and drawer**

Place the record section below the completion trend. Reuse `.admin-filter-bar`, `.admin-table-panel`, `.admin-detail-body`, and `.admin-detail-list` patterns from `BrandBookings`.

The drawer must render comparison values through a method that distinguishes unavailable history:

```js
formatDelta(metric, field, available) {
  if (!available) return '暂无对比';
  const value = metric[field];
  return `${value > 0 ? '+' : ''}${value} ${metric.unit}`;
}
```

Render only customer/store/device/report metadata, four metric values, deltas, and recommendation. Do not render `images` or mutation buttons.

- [ ] **Step 5: Run brand selector and page specs**

```powershell
.\node_modules\.bin\vitest.cmd run tests/unit/assessment-selectors.spec.js tests/unit/brand-assessment-pages.spec.js
```

Expected: both files pass.

- [ ] **Step 6: Commit the brand UI change**

```powershell
git add -- demo/franchise-platform-h5/src/views/brand/AssessmentAnalytics.vue demo/franchise-platform-h5/tests/unit/brand-assessment-pages.spec.js
git commit -m "feat: add brand assessment record details"
```

### Task 6: Prove the Complete Demo Journey and Non-Regressions

**Files:**
- Modify: `demo/franchise-platform-h5/tests/e2e/assessment-demo.spec.js`
- Modify if assertions changed: `demo/franchise-platform-h5/tests/e2e/brand-console.spec.js`
- Modify: `openspec/changes/refine-fixed-device-assessment-demo/tasks.md`

- [ ] **Step 1: Update the Playwright helper and reset assertion**

`startSeedTask` must click only “开始检测”. The reset test must expect version 4, seed task status `ready`, bound device `DEV-TL-001`, and three published records.

- [ ] **Step 2: Extend the three-role journey**

After task creation, assert the fixed device and absence of claim UI before starting:

```js
await expect(page.getByText('体态检测仪 A01')).toBeVisible();
await expect(page.getByRole('button', { name: '设备领取任务' })).toHaveCount(0);
await page.getByRole('button', { name: '开始检测' }).click();
```

After report confirmation, navigate to `/#/brand/assessments`, search the generated report ID, open its row, and assert all four metric labels, fixed device metadata, report version, and masked customer identity. Assert no full 11-digit phone and no assessment image strip in the drawer.

- [ ] **Step 3: Run focused E2E**

```powershell
.\node_modules\.bin\playwright.cmd test tests/e2e/assessment-demo.spec.js --project=desktop-chromium
```

Expected: the complete journey, four exception scenarios, deterministic reset, and viewport capture pass.

- [ ] **Step 4: Run the full unit suite**

```powershell
npm test
```

Expected: all Vitest tests pass with zero failures.

- [ ] **Step 5: Run the production build**

```powershell
npm run build
```

Expected: Vite exits 0 and produces `dist/` without compile errors.

- [ ] **Step 6: Run the relevant browser suite**

```powershell
.\node_modules\.bin\playwright.cmd test tests/e2e/assessment-demo.spec.js tests/e2e/brand-console.spec.js
```

Expected: desktop projects pass and configured mobile skips remain expected. Route checks must assert URL/title/active navigation before screenshots, and the assessment trend canvas must settle before capture.

- [ ] **Step 7: Perform visual inspection**

Inspect the generated 1440x900 brand assessment screenshot and 1280x800 store assessment screenshot. Confirm no task QR or claim text, no page-level horizontal overflow, readable table columns, and a non-overlapping 450-520px detail drawer.

- [ ] **Step 8: Validate OpenSpec and finish tracked tasks**

```powershell
openspec validate refine-fixed-device-assessment-demo
```

Mark each `tasks.md` item complete only after its implementation and checks pass.

- [ ] **Step 9: Commit verification evidence**

```powershell
git add -- demo/franchise-platform-h5/tests/e2e/assessment-demo.spec.js demo/franchise-platform-h5/tests/e2e/brand-console.spec.js openspec/changes/refine-fixed-device-assessment-demo/tasks.md
git commit -m "test: verify fixed-device assessment journey"
```

- [ ] **Step 10: Review final scope**

Run:

```powershell
git status --short
git diff 9dfe8bac..HEAD --check
git log --oneline 9dfe8bac..HEAD
```

Expected: only the approved design, plan, OpenSpec, Demo source, and tests are changed; `.superpowers/` remains untracked and is not committed.
