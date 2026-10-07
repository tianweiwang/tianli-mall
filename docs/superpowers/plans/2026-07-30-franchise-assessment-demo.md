# Franchise Assessment Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在现有加盟平台 H5 Demo 中增加本地模拟的设备检测闭环，让消费者、门店和品牌方围绕同一份检测任务与报告完成可连续演示的业务流程。

**Architecture:** 新增独立的检测种子、状态机和纯选择器，并继续由 `demoStore` 统一持久化；设备场景使用确定性本地载荷，不接网络接口。门店端负责创建任务和模拟回传，消费者端读取本人报告并发起复评，品牌端读取脱敏汇总与设备状态。

**Tech Stack:** Vue 2.7、Vue Router 3、Element UI 2、Lucide Vue、Vitest 4、Vue Test Utils 1、Playwright 1.62、OpenSpec 1.4

---

## Baseline

- Branch: `feature/franchise-platform-h5-demo`
- Unit baseline: `14` files, `125` tests passing
- Build baseline: Vite production build passing; existing chunk-size warning is outside this feature
- Design: `docs/superpowers/specs/2026-07-30-device-data-driven-assessment-platform-design.md`
- Existing booking heatmap and its tests are complete; this plan does not rework them
- `.superpowers/` remains local visual-companion output and must not be committed

## File Map

- Create: `openspec/changes/extend-franchise-h5-assessment-demo/` - 本次 Demo 扩展的 proposal、design、spec delta 与 tasks。
- Create: `demo/franchise-platform-h5/src/mock/assessmentFixtures.js` - 顾客、授权、设备、历史报告和五类设备载荷。
- Create: `demo/franchise-platform-h5/src/mock/assessmentTransitions.js` - 检测任务、设备领取、回传、复核和复评状态机。
- Create: `demo/franchise-platform-h5/src/mock/assessmentSelectors.js` - 顾客、门店和品牌方的纯聚合口径。
- Modify: `demo/franchise-platform-h5/src/mock/seed.js` - 升级状态版本并合入检测领域数据。
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js` - 新预约绑定当前演示顾客和复评来源。
- Create: `demo/franchise-platform-h5/src/components/assessment/AssessmentMetricGrid.vue` - 可复用指标与差值展示。
- Create: `demo/franchise-platform-h5/src/components/assessment/AssessmentImageStrip.vue` - 三视图标准影像展示。
- Create: `demo/franchise-platform-h5/src/views/store/Assessments.vue` - 门店检测工作台与复评队列。
- Create: `demo/franchise-platform-h5/src/views/store/AssessmentDetail.vue` - 设备模拟、异常结果与报告确认。
- Create: `demo/franchise-platform-h5/src/views/customer/Assessments.vue` - 消费者检测历史。
- Create: `demo/franchise-platform-h5/src/views/customer/AssessmentDetail.vue` - 消费者报告、差值和复评入口。
- Create: `demo/franchise-platform-h5/src/views/brand/Devices.vue` - 品牌设备管理。
- Create: `demo/franchise-platform-h5/src/views/brand/AssessmentAnalytics.vue` - 检测运营、门店质量和效果概览。
- Modify: `demo/franchise-platform-h5/src/router/index.js` - 新增六条具体路由。
- Modify: `demo/franchise-platform-h5/src/layouts/StoreLayout.vue` - 增加“检测中心”菜单。
- Modify: `demo/franchise-platform-h5/src/layouts/BrandLayout.vue` - 增加“检测运营”和“设备管理”菜单。
- Modify: `demo/franchise-platform-h5/src/components/customer/BottomNav.vue` - 将检测页面归入“我的”激活范围。
- Modify: `demo/franchise-platform-h5/src/views/customer/Profile.vue` - 增加“我的检测”入口。
- Modify: `demo/franchise-platform-h5/src/views/customer/BookingCreate.vue` - 支持报告页预选复评服务。
- Modify: `demo/franchise-platform-h5/src/views/store/Dashboard.vue` - 增加待检测与待复评入口。
- Create: `demo/franchise-platform-h5/public/assessment/posture-front.png` - 中性人体模型正面示例图。
- Create: `demo/franchise-platform-h5/public/assessment/posture-side.png` - 中性人体模型侧面示例图。
- Create: `demo/franchise-platform-h5/public/assessment/posture-back.png` - 中性人体模型背面示例图。
- Create: `demo/franchise-platform-h5/tests/unit/assessment-seed.spec.js` - 种子与恢复合同。
- Create: `demo/franchise-platform-h5/tests/unit/assessment-transitions.spec.js` - 状态机与异常合同。
- Create: `demo/franchise-platform-h5/tests/unit/assessment-selectors.spec.js` - 三角色数据范围与聚合合同。
- Create: `demo/franchise-platform-h5/tests/unit/assessment-components.spec.js` - 指标和影像组件。
- Create: `demo/franchise-platform-h5/tests/unit/store-assessment-pages.spec.js` - 门店页面流程。
- Create: `demo/franchise-platform-h5/tests/unit/customer-assessment-pages.spec.js` - 消费者页面流程。
- Create: `demo/franchise-platform-h5/tests/unit/brand-assessment-pages.spec.js` - 品牌页面流程。
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js` - 路由和菜单合同。
- Modify: `demo/franchise-platform-h5/tests/unit/transitions.spec.js` - 新预约顾客归属与复评来源。
- Create: `demo/franchise-platform-h5/tests/e2e/assessment-demo.spec.js` - 三端连续演示与异常场景。
- Modify: `demo/franchise-platform-h5/README.md` - 新主演示链路和页面入口。

## Task 1: Establish the OpenSpec contract

**Files:**
- Create: `openspec/changes/extend-franchise-h5-assessment-demo/proposal.md`
- Create: `openspec/changes/extend-franchise-h5-assessment-demo/design.md`
- Create: `openspec/changes/extend-franchise-h5-assessment-demo/tasks.md`
- Create: `openspec/changes/extend-franchise-h5-assessment-demo/specs/franchise-assessment-demo/spec.md`

- [ ] **Step 1: Create the change proposal**

Write `proposal.md` with this scope:

```markdown
## Why

现有 Demo 已能完成预约和核销，但不能展示甲方提供的检测设备如何把结果回传并形成顾客报告、门店经营动作和品牌运营数据。

## What Changes

- 增加本地模拟的检测设备、任务、载荷、报告和复评数据。
- 增加门店检测工作台、设备模拟和复评召回。
- 增加消费者检测历史、报告、前后对比和复评入口。
- 增加品牌设备管理和检测运营总览。
- 覆盖正常、离线重试、重复回传、数据不完整和设备门店不匹配。

## Capabilities

### New Capabilities

- `franchise-assessment-demo`: 三角色共享本地状态的设备检测经营闭环 Demo。

### Modified Capabilities

None. 本变更不接入正式设备、接口、数据库或医疗分析能力。

## Impact

- 只修改 `demo/franchise-platform-h5/`、本变更 OpenSpec 和 Demo README。
- 演示状态版本递增，已有本地数据按现有恢复机制重置。
- 不修改正式 CRMEB 前后台、PHP、数据库或容器运行时。
```

- [ ] **Step 2: Create the spec delta**

Write `specs/franchise-assessment-demo/spec.md` with these requirements and scenarios:

```markdown
## ADDED Requirements

### Requirement: 检测任务与顾客归属
系统 SHALL 用不透明检测任务 ID 将设备模拟结果关联到会员或临时顾客，并记录门店、设备和操作人。

#### Scenario: 从预约创建检测任务
- **WHEN** 门店为体态评估预约创建检测任务
- **THEN** 任务 SHALL 固化顾客、预约、门店、授权和有效期
- **THEN** 设备展示信息 SHALL NOT 包含完整手机号或内部用户 ID

#### Scenario: 临时顾客现场建档
- **WHEN** 未注册顾客同意检测并由门店现场建档
- **THEN** 系统 SHALL 创建临时顾客、授权记录和待设备领取任务

### Requirement: 本地设备状态机
系统 SHALL 用确定性本地载荷模拟设备领取、检测、回传、复核和报告生成。

#### Scenario: 正常完成
- **WHEN** 同门店在线设备领取任务并回传完整载荷
- **THEN** 任务 SHALL 进入待门店确认
- **THEN** 门店确认后 SHALL 生成一份版本化报告和复评计划

#### Scenario: 异常设备结果
- **WHEN** 发生离线、重复、缺失数据或设备门店不匹配
- **THEN** 系统 SHALL 展示可恢复结果
- **THEN** 系统 SHALL NOT 生成重复或未经确认的正式报告

### Requirement: 三角色检测视图
系统 SHALL 让消费者、门店和品牌方从同一份本地检测状态读取各自授权范围内的数据。

#### Scenario: 消费者查看报告
- **WHEN** 当前消费者打开检测历史或报告
- **THEN** 页面 SHALL 只展示该消费者的记录、变化和复评入口
- **THEN** 报告 SHALL 明确为体态记录且不替代医疗诊断

#### Scenario: 门店处理检测
- **WHEN** 门店人员打开检测中心
- **THEN** 页面 SHALL 只展示本店任务、顾客和复评队列

#### Scenario: 品牌查看运营
- **WHEN** 品牌人员打开设备或检测运营页
- **THEN** 页面 SHALL 展示跨店汇总、设备状态、门店质量和演示效果数据
- **THEN** 页面 SHALL NOT 展示其他门店顾客的完整手机号

### Requirement: 可重复演示
系统 SHALL 在重置后恢复相同检测数据和可执行主流程。

#### Scenario: 重置检测演示
- **WHEN** 用户确认重置演示数据
- **THEN** 设备、任务、报告、复评计划和序列 SHALL 恢复到确定性种子
- **THEN** 完整三端流程 SHALL 可再次执行
```

- [ ] **Step 3: Write design and task tracking**

`design.md` must record four decisions: local-only payloads, assessment-domain files separated from commerce transitions, immutable seeded raw submissions with versioned reports, and role-scoped selectors.

Write `tasks.md` with this exact tracking structure:

```markdown
## 1. 演示数据与设备素材
- [ ] 1.1 增加会员、临时顾客、授权、设备、任务、历史报告和设备载荷种子。
- [ ] 1.2 增加三张本地标准体态演示影像并验证可加载。

## 2. 检测状态机
- [ ] 2.1 实现任务领取、检测、离线重试、回传、复核和报告生成。
- [ ] 2.2 验证重复、缺失和门店不匹配结果不生成无效报告。

## 3. 共享展示能力
- [ ] 3.1 实现顾客、门店和品牌的纯聚合选择器。
- [ ] 3.2 实现指标差值和三视图影像组件。

## 4. 三角色页面
- [ ] 4.1 实现门店检测工作台、设备模拟和复评召回。
- [ ] 4.2 实现消费者检测历史、报告和复评预约入口。
- [ ] 4.3 实现品牌设备管理和检测运营总览。

## 5. 验收
- [ ] 5.1 完成三端主流程和五类设备结果浏览器测试。
- [ ] 5.2 完成移动、桌面截图、完整单元测试和生产构建。
- [ ] 5.3 运行 OpenSpec 校验并确认文档、测试和实现一致。
```

- [ ] **Step 4: Validate the untouched change**

Run from repository root:

```powershell
openspec validate extend-franchise-h5-assessment-demo
```

Expected: `Change 'extend-franchise-h5-assessment-demo' is valid`.

- [ ] **Step 5: Commit the OpenSpec baseline**

```powershell
git add -- openspec/changes/extend-franchise-h5-assessment-demo
git commit -m "spec: define assessment demo extension"
```

## Task 2: Add deterministic assessment data and visual assets

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/assessmentFixtures.js`
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`
- Create: `demo/franchise-platform-h5/public/assessment/posture-front.png`
- Create: `demo/franchise-platform-h5/public/assessment/posture-side.png`
- Create: `demo/franchise-platform-h5/public/assessment/posture-back.png`
- Create: `demo/franchise-platform-h5/tests/unit/assessment-seed.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/store.spec.js`

- [ ] **Step 1: Write the failing seed contract**

```js
import { createSeed, DEMO_VERSION } from '../../src/mock/seed';

describe('assessment demo seed', () => {
  it('contains repeatable customers, devices, tasks, records and scenarios', () => {
    const state = createSeed();

    expect(DEMO_VERSION).toBe(3);
    expect(state.activeCustomerId).toBe('CUS1001');
    expect(state.customers.map((item) => item.id)).toEqual(['CUS1001', 'TMP1001']);
    expect(state.devices).toHaveLength(3);
    expect(state.assessmentTasks.some((item) => item.status === 'waiting-device')).toBe(true);
    expect(state.assessmentRecords.filter((item) => item.customerId === 'CUS1001')).toHaveLength(2);
    expect(state.assessmentPayloadFixtures.success.metrics).toMatchObject({
      shoulderHeightDiffMm: 6,
      pelvicTiltDeg: 2.4,
      neckForwardAngleDeg: 13,
      discomfortScore: 3,
    });
    expect(state.sequence).toMatchObject({ customer: 1002, consent: 1002, assessmentTask: 1003, assessmentRecord: 1002, reassessmentPlan: 1001 });
  });
});
```

- [ ] **Step 2: Run the seed test and verify it fails**

```powershell
npm run test -- tests/unit/assessment-seed.spec.js
```

Expected: FAIL because `DEMO_VERSION` is `2` and the assessment collections do not exist.

- [ ] **Step 3: Create the assessment fixture factory**

Export `createAssessmentFixtures()` from `assessmentFixtures.js`. The returned object must contain these top-level keys:

```js
export function createAssessmentFixtures() {
  const imageSet = {
    front: './assessment/posture-front.png',
    side: './assessment/posture-side.png',
    back: './assessment/posture-back.png',
  };

  return {
    activeCustomerId: 'CUS1001',
    customers: [
      { id: 'CUS1001', type: 'member', name: '陈女士', phone: '138****2046', joinedAt: '2026-05-30T09:00:00+08:00' },
      { id: 'TMP1001', type: 'temporary', name: '唐女士', phone: '139****8137', joinedAt: '2026-07-30T08:50:00+08:00' },
    ],
    consentRecords: [
      { id: 'CON1001', customerId: 'CUS1001', status: 'active', scopes: ['assessment', 'image', 'operations'], signedAt: '2026-05-30T09:05:00+08:00' },
      { id: 'CON1002', customerId: 'TMP1001', status: 'active', scopes: ['assessment', 'image'], signedAt: '2026-07-30T08:55:00+08:00' },
    ],
    devices: [
      { id: 'DEV-TL-001', name: '体态检测仪 A01', model: 'TL-PA100', storeId: 'TL1001', status: 'online', softwareVersion: '2.3.1', calibratedAt: '2026-07-20', todayCount: 6 },
      { id: 'DEV-TL-002', name: '体态检测仪 B01', model: 'TL-PA100', storeId: 'TL1002', status: 'offline', softwareVersion: '2.3.1', calibratedAt: '2026-07-18', todayCount: 3 },
      { id: 'DEV-TL-003', name: '体态检测仪备用机', model: 'TL-PA100', storeId: null, status: 'maintenance', softwareVersion: '2.2.8', calibratedAt: '2026-06-10', todayCount: 0 },
    ],
    assessmentTasks: [
      { id: 'AST202607301001', customerId: 'CUS1001', bookingId: null, storeId: 'TL1001', consentId: 'CON1001', status: 'completed', createdAt: '2026-05-30T09:10:00+08:00', completedAt: '2026-05-30T09:35:00+08:00', attempts: [] },
      { id: 'AST202607301002', customerId: 'CUS1001', bookingId: null, storeId: 'TL1001', consentId: 'CON1001', status: 'completed', createdAt: '2026-06-30T10:00:00+08:00', completedAt: '2026-06-30T10:25:00+08:00', attempts: [] },
      { id: 'AST202607301003', customerId: 'TMP1001', bookingId: null, storeId: 'TL1001', consentId: 'CON1002', status: 'waiting-device', createdAt: '2026-07-30T09:30:00+08:00', attempts: [] },
    ],
    assessmentRecords: [
      { id: 'ASR202607301001', taskId: 'AST202607301001', customerId: 'CUS1001', storeId: 'TL1001', deviceId: 'DEV-TL-001', measuredAt: '2026-05-30T09:30:00+08:00', version: 1, metrics: { shoulderHeightDiffMm: 12, pelvicTiltDeg: 3.8, neckForwardAngleDeg: 18, discomfortScore: 6 }, images: imageSet, reportStatus: 'published', recommendation: '建议保持规律使用并按计划复评。' },
      { id: 'ASR202607301002', taskId: 'AST202607301002', customerId: 'CUS1001', storeId: 'TL1001', deviceId: 'DEV-TL-001', measuredAt: '2026-06-30T10:20:00+08:00', version: 1, metrics: { shoulderHeightDiffMm: 8, pelvicTiltDeg: 2.9, neckForwardAngleDeg: 15, discomfortScore: 4 }, images: imageSet, reportStatus: 'published', recommendation: '本期记录较首次更稳定，建议继续执行当前计划并按期复评。' },
    ],
    reassessmentPlans: [
      { id: 'RAP202607301001', customerId: 'CUS1001', storeId: 'TL1001', sourceAssessmentId: 'ASR202607301002', dueDate: '2026-07-30', status: 'due' },
    ],
    assessmentPayloadFixtures: {
      success: { taskId: null, resultId: 'DEVRES-20260730-001', deviceId: 'DEV-TL-001', storeId: 'TL1001', operatorName: '李技师', measuredAt: '2026-07-30T09:42:00+08:00', softwareVersion: '2.3.1', metrics: { shoulderHeightDiffMm: 6, pelvicTiltDeg: 2.4, neckForwardAngleDeg: 13, discomfortScore: 3 }, images: imageSet },
      incomplete: { taskId: null, resultId: 'DEVRES-20260730-002', deviceId: 'DEV-TL-001', storeId: 'TL1001', operatorName: '李技师', measuredAt: '2026-07-30T09:42:00+08:00', softwareVersion: '2.3.1', metrics: { shoulderHeightDiffMm: 6 }, images: { front: imageSet.front } },
      mismatch: { taskId: null, resultId: 'DEVRES-20260730-003', deviceId: 'DEV-TL-002', storeId: 'TL1002', operatorName: '陈技师', measuredAt: '2026-07-30T09:42:00+08:00', softwareVersion: '2.3.1', metrics: { shoulderHeightDiffMm: 6, pelvicTiltDeg: 2.4, neckForwardAngleDeg: 13, discomfortScore: 3 }, images: imageSet },
    },
    rawAssessmentSubmissions: [],
  };
}
```

- [ ] **Step 4: Merge the assessment fixture into the seed**

Set `DEMO_VERSION = 3`, call `createAssessmentFixtures()` once inside `createSeed()`, spread its fields into the returned state, add `customerId: 'CUS1001'` to booking `BK202607302001`, and extend `sequence` exactly as asserted by the test.

- [ ] **Step 5: Generate and inspect the three bitmap assets**

Use the `imagegen` skill to create three consistent 720×960 PNG images of the same neutral, non-identifiable adult mannequin standing against a clinical measurement grid. Use front, side, and back views; no labels, text, logos, diagnosis marks, exposed anatomy, or real-person likeness. Save to the three exact `public/assessment/` paths and inspect each image before continuing.

- [ ] **Step 6: Run seed and store restoration tests**

```powershell
npm run test -- tests/unit/assessment-seed.spec.js tests/unit/store.spec.js
```

Expected: all selected tests pass and reset returns an exact version 3 seed.

- [ ] **Step 7: Commit the deterministic assessment baseline**

```powershell
git add -- demo/franchise-platform-h5/src/mock/assessmentFixtures.js demo/franchise-platform-h5/src/mock/seed.js demo/franchise-platform-h5/public/assessment demo/franchise-platform-h5/tests/unit/assessment-seed.spec.js demo/franchise-platform-h5/tests/unit/store.spec.js
git commit -m "feat: seed assessment device demo data"
```

## Task 3: Implement the assessment state machine

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/assessmentTransitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Create: `demo/franchise-platform-h5/tests/unit/assessment-transitions.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/transitions.spec.js`

- [ ] **Step 1: Write failing transition tests**

Cover these exact outcomes:

```js
import { createSeed } from '../../src/mock/seed';
import {
  claimAssessmentTask,
  confirmAssessmentResult,
  createAssessmentTask,
  createWalkInAssessmentTask,
  markAssessmentOffline,
  receiveAssessmentResult,
  retryAssessmentTask,
  startAssessment,
} from '../../src/mock/assessmentTransitions';

it('completes one result and creates one versioned report and reassessment plan', () => {
  const state = createSeed();
  const task = createAssessmentTask(state, { bookingId: 'BK202607302001', customerId: 'CUS1001', storeId: 'TL1001' });
  claimAssessmentTask(state, { taskId: task.id, deviceId: 'DEV-TL-001', operatingStoreId: 'TL1001' });
  startAssessment(state, task.id);
  expect(receiveAssessmentResult(state, { taskId: task.id, payload: { ...state.assessmentPayloadFixtures.success, taskId: task.id } })).toMatchObject({ ok: true, code: 'awaiting-review' });
  const report = confirmAssessmentResult(state, task.id);

  expect(report).toMatchObject({ customerId: 'CUS1001', version: 1, reportStatus: 'published' });
  expect(state.assessmentRecords.filter((item) => item.taskId === task.id)).toHaveLength(1);
  expect(state.reassessmentPlans.find((item) => item.sourceAssessmentId === report.id)).toMatchObject({ customerId: 'CUS1001', status: 'scheduled' });
});

it('keeps duplicate, incomplete, mismatch and offline outcomes non-destructive', () => {
  const state = createSeed();
  const taskId = 'AST202607301003';
  claimAssessmentTask(state, { taskId, deviceId: 'DEV-TL-001', operatingStoreId: 'TL1001' });
  startAssessment(state, taskId);
  markAssessmentOffline(state, taskId);
  expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('retrying');
  retryAssessmentTask(state, taskId);
  expect(state.assessmentTasks.find((item) => item.id === taskId).status).toBe('measuring');

  expect(receiveAssessmentResult(state, { taskId, payload: { ...state.assessmentPayloadFixtures.incomplete, taskId } }).code).toBe('review-required');
  expect(state.assessmentRecords).toHaveLength(2);
});
```

Add separate tests that `mismatch` produces `quarantined`, a repeated `resultId` returns `duplicate`, a device from another store cannot claim a task, a booking cannot receive two tasks, and walk-in creation creates exactly one temporary customer, consent and task. Device fixture templates must be cloned with the active `taskId` before calling `receiveAssessmentResult`.

- [ ] **Step 2: Run the transition tests and verify they fail**

```powershell
npm run test -- tests/unit/assessment-transitions.spec.js tests/unit/transitions.spec.js
```

Expected: FAIL because `assessmentTransitions.js` and booking customer linkage do not exist.

- [ ] **Step 3: Implement assessment transitions**

Use the following exported contract:

```js
export function createAssessmentTask(state, { bookingId = null, customerId, storeId })
export function createWalkInAssessmentTask(state, { storeId, name, phone, scopes })
export function claimAssessmentTask(state, { taskId, deviceId, operatingStoreId })
export function startAssessment(state, taskId)
export function markAssessmentOffline(state, taskId)
export function retryAssessmentTask(state, taskId)
export function receiveAssessmentResult(state, { taskId, payload })
export function confirmAssessmentResult(state, taskId)
```

Implementation rules:

```js
const REQUIRED_METRICS = ['shoulderHeightDiffMm', 'pelvicTiltDeg', 'neckForwardAngleDeg', 'discomfortScore'];
const REQUIRED_IMAGES = ['front', 'side', 'back'];

// receiveAssessmentResult order
// 1. Return { ok:false, code:'duplicate' } when resultId already exists.
// 2. Append an immutable clone to rawAssessmentSubmissions.
// 3. Quarantine when payload.taskId differs from taskId or payload.storeId/device.storeId/task.storeId differ.
// 4. Mark review-required when a required metric or image is absent.
// 5. Save the accepted payload on the current attempt and set awaiting-review.

// confirmAssessmentResult
// - require awaiting-review
// - create exactly one ASR record from the accepted payload
// - set reportStatus:'published', version:1, non-medical recommendation text
// - set task.status:'completed' and completedAt:state.demoNow
// - add a reassessment plan through sequence.reassessmentPlan, due 28 days after state.demoNow with status:'scheduled'
// - append activityLog entries for every visible state change
```

`createWalkInAssessmentTask` must reject an empty name, create IDs through the matching sequence fields, use `type: 'temporary'`, and require `assessment` in `scopes`.

- [ ] **Step 4: Link new bookings to the active customer**

In `createBooking`, add these fields without changing existing capacity behavior:

```js
customerId: state.activeCustomerId,
sourceAssessmentId: input.sourceAssessmentId || null,
bookingType: input.sourceAssessmentId ? 'reassessment' : 'service',
```

- [ ] **Step 5: Run transition tests**

```powershell
npm run test -- tests/unit/assessment-transitions.spec.js tests/unit/transitions.spec.js tests/unit/booking-flow.spec.js
```

Expected: all selected tests pass; existing booking capacity and verification behavior remain unchanged.

- [ ] **Step 6: Commit the state machine**

```powershell
git add -- demo/franchise-platform-h5/src/mock/assessmentTransitions.js demo/franchise-platform-h5/src/mock/transitions.js demo/franchise-platform-h5/tests/unit/assessment-transitions.spec.js demo/franchise-platform-h5/tests/unit/transitions.spec.js
git commit -m "feat: add assessment device state machine"
```

## Task 4: Add assessment selectors and shared report components

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/assessmentSelectors.js`
- Create: `demo/franchise-platform-h5/src/components/assessment/AssessmentMetricGrid.vue`
- Create: `demo/franchise-platform-h5/src/components/assessment/AssessmentImageStrip.vue`
- Create: `demo/franchise-platform-h5/tests/unit/assessment-selectors.spec.js`
- Create: `demo/franchise-platform-h5/tests/unit/assessment-components.spec.js`

- [ ] **Step 1: Write failing selector tests**

Tests must assert:

```js
import {
  assessmentHistoryForCustomer,
  assessmentOperationsForBrand,
  assessmentTasksForStore,
  buildAssessmentComparison,
  reassessmentQueueForStore,
} from '../../src/mock/assessmentSelectors';

const state = createSeed();
expect(assessmentTasksForStore(state, 'TL1001').every((item) => item.storeId === 'TL1001')).toBe(true);
expect(reassessmentQueueForStore(state, 'TL1001')[0].customerName).toBe('陈女士');
expect(assessmentHistoryForCustomer(state, 'CUS1001')).toHaveLength(2);
expect(buildAssessmentComparison(state, 'ASR202607301002').metrics.shoulderHeightDiffMm).toMatchObject({ current: 8, previousDelta: -4, firstDelta: -4, unit: 'mm' });
expect(assessmentOperationsForBrand(state)).toMatchObject({ deviceCount: 3, onlineDeviceCount: 1, completedTaskCount: 2, pairedCustomerCount: 1 });
```

- [ ] **Step 2: Run selector tests and verify they fail**

```powershell
npm run test -- tests/unit/assessment-selectors.spec.js
```

Expected: FAIL because the selector module does not exist.

- [ ] **Step 3: Implement pure selectors**

`buildAssessmentComparison` must expose these metric definitions and never infer a diagnosis:

```js
export const assessmentMetricDefinitions = {
  shoulderHeightDiffMm: { label: '双肩高度差', unit: 'mm', lowerIsBetter: true },
  pelvicTiltDeg: { label: '骨盆倾斜角', unit: '°', lowerIsBetter: true },
  neckForwardAngleDeg: { label: '颈部前倾角', unit: '°', lowerIsBetter: true },
  discomfortScore: { label: '自评不适程度', unit: '/10', lowerIsBetter: true },
};
```

Return newest-first customer history, store-scoped tasks and reassessment items, and brand aggregates grouped by store and device. Brand selectors may include customer counts but must expose only masked phone values in drill-down rows.

- [ ] **Step 4: Write and run failing component tests**

`AssessmentMetricGrid` must render label, value, unit, previous delta and first delta; improvement, stable and fluctuation states must include text as well as color. `AssessmentImageStrip` must render three `<img>` elements with alt text `正面体态记录`, `侧面体态记录`, `背面体态记录` and fixed aspect ratios.

```powershell
npm run test -- tests/unit/assessment-components.spec.js
```

Expected: FAIL before the components exist.

- [ ] **Step 5: Implement the shared components**

Use these public props:

```js
// AssessmentMetricGrid.vue
props: { metrics: { type: Array, required: true }, compact: { type: Boolean, default: false } }

// AssessmentImageStrip.vue
props: { images: { type: Object, required: true }, capturedAt: { type: String, default: '' }, storeName: { type: String, default: '' } }
```

Keep cards at existing radius values, use stable grid tracks, and avoid medical outcome labels. Images use `object-fit: cover`, `aspect-ratio: 3 / 4`, and a visible “演示影像” caption.

- [ ] **Step 6: Run selector and component tests**

```powershell
npm run test -- tests/unit/assessment-selectors.spec.js tests/unit/assessment-components.spec.js
```

Expected: all selected tests pass.

- [ ] **Step 7: Commit shared assessment presentation**

```powershell
git add -- demo/franchise-platform-h5/src/mock/assessmentSelectors.js demo/franchise-platform-h5/src/components/assessment demo/franchise-platform-h5/tests/unit/assessment-selectors.spec.js demo/franchise-platform-h5/tests/unit/assessment-components.spec.js
git commit -m "feat: add assessment report primitives"
```

## Task 5: Build the store assessment workflow

**Files:**
- Create: `demo/franchise-platform-h5/src/views/store/Assessments.vue`
- Create: `demo/franchise-platform-h5/src/views/store/AssessmentDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Dashboard.vue`
- Modify: `demo/franchise-platform-h5/src/layouts/StoreLayout.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Create: `demo/franchise-platform-h5/tests/unit/store-assessment-pages.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/store-console.spec.js`

- [ ] **Step 1: Write failing route and page tests**

Add route expectations:

```js
['/store/assessments', 'StoreAssessments'],
['/store/assessments/AST202607301003', 'StoreAssessmentDetail'],
```

Page tests must assert the store list contains only `activeStoreId` tasks, the waiting task links to its detail route, the reassessment tab shows `陈女士`, and the walk-in action creates a temporary task through `createWalkInAssessmentTask`.

- [ ] **Step 2: Run focused tests and verify they fail**

```powershell
npm run test -- tests/unit/store-assessment-pages.spec.js tests/unit/router.spec.js tests/unit/store-console.spec.js
```

Expected: FAIL because routes, pages and the “检测中心” menu do not exist.

- [ ] **Step 3: Add store routes and menu**

Add these children after store bookings:

```js
{ path: 'assessments', name: 'store-assessments', component: view('store', 'Assessments'), meta: meta('store', '检测中心') },
{ path: 'assessments/:taskId', name: 'store-assessment-detail', component: view('store', 'AssessmentDetail'), meta: meta('store', '检测任务') },
```

Add `{ label: '检测中心', path: '/store/assessments', icon: Activity }` after “预约管理”. Keep the menu role-specific.

- [ ] **Step 4: Implement the store workbench**

`Assessments.vue` must provide:

- Four metrics: 今日任务、检测中、待确认、待复评。
- Two tabs: 检测任务、复评召回。
- A “待建任务预约” section that lists this store's `SV1001` bookings without an assessment task and calls `createAssessmentTask`.
- Task filters: customer keyword and task status.
- A “现场建档” dialog with name, masked phone and required assessment/image consent checkboxes.
- A task row command that routes to `/store/assessments/:taskId`.

The booking action must use `data-testid="create-assessment-<bookingId>"`; after creation, render the new task ID with `data-testid="created-assessment-task-id"` so the browser journey can continue without assuming sequence values.

Use `AdminMetricStrip`, `AdminStatusTag` and Element UI controls. The page must never read another store's tasks.

- [ ] **Step 5: Implement the device simulation detail**

`AssessmentDetail.vue` must render stable sections in this order:

1. Customer, booking, consent and task summary.
2. Status timeline.
3. Assigned device and task QR-style token panel.
4. Segmented scenario selector: 正常回传、设备离线、重复回传、数据不完整、门店不匹配.
5. State-dependent commands: 设备领取、开始检测、模拟回传、恢复重试、确认并生成报告.
6. Accepted result preview using shared metrics and images.
7. Clear result panel with recovery guidance for every abnormal outcome.

All commands must call `demoStore.commit()` with functions from `assessmentTransitions.js`. Before simulating a result, clone the selected fixture and replace its `taskId` with the current route task ID. Do not mutate arrays directly in the component.

- [ ] **Step 6: Add dashboard entry and run store tests**

Add a compact “检测待办” section to `StoreDashboard` linking to the workbench. Then run:

```powershell
npm run test -- tests/unit/store-assessment-pages.spec.js tests/unit/router.spec.js tests/unit/store-console.spec.js tests/unit/assessment-transitions.spec.js
```

Expected: all selected tests pass and existing store data isolation remains intact.

- [ ] **Step 7: Commit the store workflow**

```powershell
git add -- demo/franchise-platform-h5/src/views/store/Assessments.vue demo/franchise-platform-h5/src/views/store/AssessmentDetail.vue demo/franchise-platform-h5/src/views/store/Dashboard.vue demo/franchise-platform-h5/src/layouts/StoreLayout.vue demo/franchise-platform-h5/src/router/index.js demo/franchise-platform-h5/tests/unit/store-assessment-pages.spec.js demo/franchise-platform-h5/tests/unit/router.spec.js demo/franchise-platform-h5/tests/unit/store-console.spec.js
git commit -m "feat: add store assessment workflow"
```

## Task 6: Build customer reports and reassessment entry

**Files:**
- Create: `demo/franchise-platform-h5/src/views/customer/Assessments.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/AssessmentDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Profile.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/BookingCreate.vue`
- Modify: `demo/franchise-platform-h5/src/components/customer/BottomNav.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Create: `demo/franchise-platform-h5/tests/unit/customer-assessment-pages.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/transitions.spec.js`

- [ ] **Step 1: Write failing customer page tests**

Add route expectations:

```js
['/c/store/TL1001/assessments', 'CustomerAssessments'],
['/c/store/TL1001/assessments/ASR202607301002', 'CustomerAssessmentDetail'],
```

Tests must assert only `activeCustomerId` records render, newest records appear first, the report shows previous and first deltas, the non-medical notice is visible, and the复评 button routes to:

```text
/c/store/TL1001/booking?serviceId=SV1001&sourceAssessmentId=ASR202607301002
```

- [ ] **Step 2: Run customer tests and verify they fail**

```powershell
npm run test -- tests/unit/customer-assessment-pages.spec.js tests/unit/router.spec.js
```

Expected: FAIL because the routes and pages do not exist.

- [ ] **Step 3: Implement customer history and report pages**

`Assessments.vue` uses a vertical timeline with date, store, sequence number, four metric values and “查看报告”. `AssessmentDetail.vue` uses the shared metric and image components, shows previous/first change, brand recommendation, report version, device model, store and capture time.

The report must include this visible notice:

```text
本报告用于记录体态变化和日常健康管理，不作为医疗诊断或治疗依据。
```

Do not show device result IDs, internal user IDs, raw payloads or audit fields to the consumer.

- [ ] **Step 4: Wire profile, navigation and reassessment preselection**

Add “我的检测” to `Profile.vue`; change `.profile-shortcuts` to a stable three-column grid, keep the four-item bottom navigation unchanged, and include assessment route names in the “我的” active list.

In `BookingCreate.vue`, initialize from query without trusting unsupported values:

```js
created() {
  const requestedService = this.$route.query.serviceId;
  if (this.services.some((item) => item.id === requestedService)) {
    this.selectedServiceId = requestedService;
  }
  this.resetSelection();
},
```

Pass `sourceAssessmentId: this.$route.query.sourceAssessmentId || null` to `createBooking`. Show a compact “复评预约” context when that query exists.

- [ ] **Step 5: Run customer and booking tests**

```powershell
npm run test -- tests/unit/customer-assessment-pages.spec.js tests/unit/transitions.spec.js tests/unit/booking-flow.spec.js tests/unit/router.spec.js
```

Expected: all selected tests pass; ordinary appointments still use `bookingType: 'service'`, report-originated appointments use `bookingType: 'reassessment'`.

- [ ] **Step 6: Commit customer reports**

```powershell
git add -- demo/franchise-platform-h5/src/views/customer/Assessments.vue demo/franchise-platform-h5/src/views/customer/AssessmentDetail.vue demo/franchise-platform-h5/src/views/customer/Profile.vue demo/franchise-platform-h5/src/views/customer/BookingCreate.vue demo/franchise-platform-h5/src/components/customer/BottomNav.vue demo/franchise-platform-h5/src/router/index.js demo/franchise-platform-h5/tests/unit/customer-assessment-pages.spec.js demo/franchise-platform-h5/tests/unit/router.spec.js demo/franchise-platform-h5/tests/unit/transitions.spec.js
git commit -m "feat: add customer assessment reports"
```

## Task 7: Build brand device and assessment operations

**Files:**
- Create: `demo/franchise-platform-h5/src/views/brand/Devices.vue`
- Create: `demo/franchise-platform-h5/src/views/brand/AssessmentAnalytics.vue`
- Modify: `demo/franchise-platform-h5/src/layouts/BrandLayout.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Create: `demo/franchise-platform-h5/tests/unit/brand-assessment-pages.spec.js`
- Modify: `demo/franchise-platform-h5/tests/unit/router.spec.js`
- Modify: `demo/franchise-platform-h5/tests/e2e/brand-console.spec.js`

- [ ] **Step 1: Write failing brand page and route tests**

Add route expectations:

```js
['/brand/devices', 'BrandDevices'],
['/brand/assessments', 'BrandAssessmentAnalytics'],
```

Tests must assert device filters, calibration warning, store-level completion and paired-customer metrics, and that any displayed phone is masked.

- [ ] **Step 2: Run brand tests and verify they fail**

```powershell
npm run test -- tests/unit/brand-assessment-pages.spec.js tests/unit/router.spec.js tests/unit/brand-console.spec.js
```

Expected: FAIL because routes, menu items and pages do not exist.

- [ ] **Step 3: Add brand routes and menu items**

Add these routes after full-platform bookings:

```js
{ path: 'assessments', name: 'brand-assessment-analytics', component: view('brand', 'AssessmentAnalytics'), meta: meta('brand', '检测运营') },
{ path: 'devices', name: 'brand-devices', component: view('brand', 'Devices'), meta: meta('brand', '设备管理') },
```

Add menu items using `Activity` and `MonitorCog` icons. Keep existing platform booking and business analysis pages unchanged.

- [ ] **Step 4: Implement device management**

`Devices.vue` must contain four metrics, store/status filters, and a table with device name, model, assigned store, status, software version, last calibration and today's count. Offline and overdue-calibration rows use semantic warning states; there are no real edit, firmware or calibration commands.

- [ ] **Step 5: Implement assessment operations**

`AssessmentAnalytics.vue` must contain:

- Metrics: 累计检测、有效配对、复评率、待复核。
- A compact completion trend using existing `AdminLineChart` only if the selector returns at least two dates.
- Store quality table: task count, completion rate, paired customers, due reassessments and data-quality flag.
- Four metric averages with explicit sample count and “演示统计” label.
- A visible explanation that values are fixed demo data and do not represent product efficacy.

Do not add another chart library or show customer-identifying detail.

- [ ] **Step 6: Run brand tests**

```powershell
npm run test -- tests/unit/brand-assessment-pages.spec.js tests/unit/router.spec.js tests/unit/brand-console.spec.js tests/unit/assessment-selectors.spec.js
```

Expected: all selected tests pass and previous brand routes remain available.

- [ ] **Step 7: Commit brand operations**

```powershell
git add -- demo/franchise-platform-h5/src/views/brand/Devices.vue demo/franchise-platform-h5/src/views/brand/AssessmentAnalytics.vue demo/franchise-platform-h5/src/layouts/BrandLayout.vue demo/franchise-platform-h5/src/router/index.js demo/franchise-platform-h5/tests/unit/brand-assessment-pages.spec.js demo/franchise-platform-h5/tests/unit/router.spec.js demo/franchise-platform-h5/tests/e2e/brand-console.spec.js
git commit -m "feat: add brand assessment operations"
```

## Task 8: Verify the full demo, document it, and close OpenSpec tasks

**Files:**
- Create: `demo/franchise-platform-h5/tests/e2e/assessment-demo.spec.js`
- Modify: `demo/franchise-platform-h5/tests/e2e/brand-console.spec.js`
- Modify: `demo/franchise-platform-h5/README.md`
- Modify: `openspec/changes/extend-franchise-h5-assessment-demo/tasks.md`

- [ ] **Step 1: Write the end-to-end assessment journey**

The Playwright test must reset local state and execute this exact journey:

```js
test('completes the shared assessment journey across three roles', async ({ page }) => {
  await page.goto('/#/c/store/TL1001');
  await page.evaluate(() => localStorage.removeItem('tianli.franchise-demo.v1'));
  await page.reload();

  await page.goto('/#/store/assessments');
  await page.getByTestId('create-assessment-BK202607302001').click();
  const taskId = (await page.getByTestId('created-assessment-task-id').textContent()).trim();
  await page.goto(`/#/store/assessments/${taskId}`);
  await page.getByRole('button', { name: '设备领取任务' }).click();
  await page.getByRole('button', { name: '开始检测' }).click();
  await page.getByRole('button', { name: '模拟回传' }).click();
  await expect(page.getByText('待门店确认')).toBeVisible();
  await page.getByRole('button', { name: '确认并生成报告' }).click();

  const reportId = await page.getByTestId('generated-assessment-id').textContent();
  await page.goto(`/#/c/store/TL1001/assessments/${reportId.trim()}`);
  await expect(page.getByText('本报告用于记录体态变化')).toBeVisible();
  await expect(page.getByText('双肩高度差')).toBeVisible();

  await page.goto('/#/brand/assessments');
  await expect(page.getByText('检测运营')).toBeVisible();
  await expect(page.getByText('演示统计')).toBeVisible();
});
```

- [ ] **Step 2: Add abnormal scenario E2E coverage**

Use a fresh reset for each scenario and verify:

- Offline: status becomes “等待重试”, then “恢复并重试” returns to detecting.
- Duplicate: second identical payload shows “已接收过该设备结果” and record count does not change.
- Incomplete: report generation button stays unavailable and missing items are named.
- Mismatch: result is isolated and no customer report is created.
- Reset: devices, task status, record count and sequence return to version 3 seed.

- [ ] **Step 3: Extend route and viewport acceptance**

Add the two brand routes to `brandRoutes`. In `assessment-demo.spec.js`, verify customer pages at 390×844 and store/brand pages at 1280×800 and 1440×900. For each target page assert:

```js
expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
```

Capture screenshots for the customer report, store simulation detail, brand devices and brand assessment operations. Inspect that images are nonblank, text does not overlap, controls stay within panels, and browser console has no errors.

- [ ] **Step 4: Update the Demo README**

Add the new main flow and explicit boundary:

```text
设备检测演示：消费者预约 -> 门店检测中心 -> 模拟设备回传 -> 生成报告 -> 消费者前后对比 -> 复评预约 -> 品牌检测运营。

本 Demo 不连接真实检测设备，不执行医疗诊断，设备结果、影像、指标变化和效果统计均为固定演示数据。
```

- [ ] **Step 5: Run focused and complete verification**

Run from `demo/franchise-platform-h5`:

```powershell
npm run test -- tests/unit/assessment-seed.spec.js tests/unit/assessment-transitions.spec.js tests/unit/assessment-selectors.spec.js tests/unit/assessment-components.spec.js tests/unit/store-assessment-pages.spec.js tests/unit/customer-assessment-pages.spec.js tests/unit/brand-assessment-pages.spec.js tests/unit/router.spec.js
npm run test
npm run build
npm run test:e2e -- tests/e2e/assessment-demo.spec.js tests/e2e/brand-console.spec.js
```

Expected: all focused and full Vitest suites pass, production build succeeds, and all selected Playwright projects pass without console errors.

- [ ] **Step 6: Validate OpenSpec and complete tasks**

From repository root:

```powershell
openspec validate extend-franchise-h5-assessment-demo
```

Expected: `Change 'extend-franchise-h5-assessment-demo' is valid`.

Only after code, tests, screenshots and spec agree, change all task checkboxes in `openspec/changes/extend-franchise-h5-assessment-demo/tasks.md` to `[x]`.

- [ ] **Step 7: Commit final acceptance**

```powershell
git add -- demo/franchise-platform-h5/tests/e2e/assessment-demo.spec.js demo/franchise-platform-h5/tests/e2e/brand-console.spec.js demo/franchise-platform-h5/README.md openspec/changes/extend-franchise-h5-assessment-demo/tasks.md
git commit -m "test: verify assessment demo journey"
```

- [ ] **Step 8: Start the final preview and report the URL**

```powershell
npm run preview -- --host 0.0.0.0 --port 4174
```

Expected: `http://127.0.0.1:4174/#/store/assessments` and all linked customer/brand routes are available. If `4174` is occupied by the current preview, stop or reuse it only after confirming it serves the newly built `dist/`.

---

## Final Acceptance Checklist

- [ ] Detection result is linked by task ID, not by name or phone matching.
- [ ] Membership and temporary-customer seed paths both exist.
- [ ] Device, task, raw submission, report and reassessment state share one local store.
- [ ] Duplicate, incomplete, mismatch and offline scenarios do not create invalid reports.
- [ ] Store pages expose only the active store's customers and tasks.
- [ ] Customer pages expose only the active customer's reports.
- [ ] Brand pages expose aggregate or masked data only.
- [ ] Reports use non-medical wording and identify all values and images as demo data.
- [ ] Reassessment booking retains `sourceAssessmentId` and does not alter ordinary booking behavior.
- [ ] Reset restores the exact version 3 seed and makes the journey repeatable.
- [ ] Unit suite, production build, selected E2E tests, target screenshots and OpenSpec validation pass.
