# Membership Wallet And Subsidy Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在现有纯前端 H5 Demo 中实现按客户隔离的会员、储值本金、积分、补贴与权益，并打通客户购买、门店结算、品牌发放的完整演示链路。

**Architecture:** `memberSelectors.js` 负责会员资格、资产汇总、定价和运营视图，`memberTransitions.js` 是所有会员资产变化的唯一写入口。现有订单、积分和预约 transitions 只调用这些领域函数，三端页面通过同一份 `demoStore` 状态同步展示，继续使用 `localStorage` 持久化与版本重置。

**Tech Stack:** Vue 2.7、Vue Router 3、Element UI、Vitest、Vue Test Utils、Playwright、OpenSpec。

---

### Task 1: 建立客户级会员资产种子与只读选择器

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/memberSelectors.js`
- Modify: `demo/franchise-platform-h5/src/mock/seed.js`
- Modify: `demo/franchise-platform-h5/src/mock/store.js`
- Test: `demo/franchise-platform-h5/tests/unit/member-domain.spec.js`
- Test: `demo/franchise-platform-h5/tests/unit/store.spec.js`

- [ ] **Step 1: 写客户隔离和种子场景的失败测试**

测试分别读取 `CUS1001`、`CUS1002`，断言账户不是同一对象；断言陈女士拥有有效会员、储值和一份可发补贴计划，李四没有开卡或储值。使用以下公共接口：

```js
import { accountForCustomer, memberProfileForCustomer } from '../../src/mock/memberSelectors';

expect(accountForCustomer(state, 'CUS1001')).toMatchObject({ principalBalanceCents: expect.any(Number) });
expect(memberProfileForCustomer(state, 'CUS1002').plans).toHaveLength(0);
```

- [ ] **Step 2: 运行测试并确认因模块不存在而失败**

Run: `npm test -- --run tests/unit/member-domain.spec.js tests/unit/store.spec.js`
Expected: FAIL，提示无法解析 `memberSelectors.js` 或缺少会员种子字段。

- [ ] **Step 3: 增加版本化领域状态和选择器**

在 `createSeed()` 中增加 `memberAccounts`、`memberships`、`benefitGrants`、`memberTransactions`、`memberConsumptions`、`subsidyPlans`、`subsidyRuns`；把原全局积分迁入对应客户账户与 `memberTransactions`。实现并导出：

```js
accountForCustomer(state, customerId)
membershipsForCustomer(state, customerId)
effectiveBenefitsForCustomer(state, customerId)
assetLedgerForCustomer(state, customerId, assetType)
memberProfileForCustomer(state, customerId)
memberOperationsOverview(state)
```

将 `DEMO_VERSION` 升级到 `9`，更新重置版本断言，让旧浏览器状态按既有机制恢复新种子。

- [ ] **Step 4: 运行领域种子测试**

Run: `npm test -- --run tests/unit/member-domain.spec.js tests/unit/store.spec.js`
Expected: PASS，且两个客户的账户、权益和流水互不串联。

### Task 2: 实现开卡、储值和客户级积分迁移

**Files:**
- Create: `demo/franchise-platform-h5/src/mock/memberTransitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/selectors.js`
- Modify: `demo/franchise-platform-h5/src/views/customer/Points.vue`
- Test: `demo/franchise-platform-h5/tests/unit/member-domain.spec.js`
- Test: `demo/franchise-platform-h5/tests/unit/growth-modules.spec.js`

- [ ] **Step 1: 写开卡、储值和幂等失败测试**

覆盖 `purchaseMembership(state, { customerId, paymentAttemptId })` 生成 1+1+2+3 七条权益、`rechargeStoredValue` 增加 510000 分本金和 10200 积分、相同支付编号重复提交返回原记录且不重复入账。

- [ ] **Step 2: 运行聚焦测试确认失败**

Run: `npm test -- --run tests/unit/member-domain.spec.js tests/unit/growth-modules.spec.js`
Expected: FAIL，提示 transitions 尚未导出或仍读取全局积分。

- [ ] **Step 3: 实现唯一资产写入口**

在 `memberTransitions.js` 中提供：

```js
purchaseMembership(state, { customerId, paymentAttemptId })
rechargeStoredValue(state, { customerId, paymentAttemptId })
creditAsset(state, { customerId, assetType, amount, businessType, businessId, title })
debitAsset(state, { customerId, assetType, amount, businessType, businessId, title })
```

所有函数先验证业务编号幂等，再同时更新账户汇总与流水。将 `awardPoints`、`redeemPoints` 改为使用当前客户账户，`pointsOverview` 接收当前客户，`Points.vue` 改读选择器，不再访问 `state.pointsAccount` 或 `state.pointsLedger`。

- [ ] **Step 4: 运行积分与会员领域回归**

Run: `npm test -- --run tests/unit/member-domain.spec.js tests/unit/growth-modules.spec.js`
Expected: PASS，现有积分兑换仍工作且切换客户后余额独立。

### Task 3: 实现会员定价、商品本金支付与补贴计划生成

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/memberSelectors.js`
- Modify: `demo/franchise-platform-h5/src/mock/memberTransitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Test: `demo/franchise-platform-h5/tests/unit/member-pricing.spec.js`
- Test: `demo/franchise-platform-h5/tests/unit/order-flow.spec.js`

- [ ] **Step 1: 写价格快照、扣款和补贴阈值失败测试**

断言会员商品价为原价 85%，仅储值客户商品仍为原价；本金服务价为 66%，积分服务价为原价积分。使用储值本金创建商品订单后断言订单保存 `originalGoodsCents`、`discountRate`、`paymentSource`、`assetDeduction`、`subsidyEligiblePrincipalCents`，余额和流水同步变化；累计首次到 510000 分时只创建一份 100 期计划。

- [ ] **Step 2: 运行价格与订单测试确认失败**

Run: `npm test -- --run tests/unit/member-pricing.spec.js tests/unit/order-flow.spec.js`
Expected: FAIL，提示缺少价格或本金支付字段。

- [ ] **Step 3: 实现确定性价格计算与订单接入**

增加：

```js
calculateGoodsPricing(state, { customerId, originalCents })
calculateServiceSettlement(state, { customerId, originalCents, paymentSource })
recordEligiblePrincipalConsumption(state, { customerId, amountCents, businessId })
```

扩展 `createPaidOrder` 接收 `paymentSource: 'wechat' | 'principal'`；本金支付在创建任何订单、分账或归因记录前校验余额，成功后保存完整快照、扣款、写消费记录并累加补贴资格。本金累计达到 5100 元时创建唯一补贴计划。

- [ ] **Step 4: 运行价格和订单回归**

Run: `npm test -- --run tests/unit/member-pricing.spec.js tests/unit/order-flow.spec.js`
Expected: PASS，重复订单支付不重复扣款或累计补贴消费。

### Task 4: 实现核销后门店项目结算

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/memberTransitions.js`
- Modify: `demo/franchise-platform-h5/src/mock/transitions.js`
- Test: `demo/franchise-platform-h5/tests/unit/member-settlement.spec.js`

- [ ] **Step 1: 写本金、积分及失败原子性测试**

使用已核销的 1000 元预约，断言本金扣 66000 分、积分扣 1000 分；未核销、门店不匹配、余额不足和重复结算均抛出中文业务错误，并保持账户、预约、消费和流水快照完全不变。

- [ ] **Step 2: 运行结算测试确认失败**

Run: `npm test -- --run tests/unit/member-settlement.spec.js`
Expected: FAIL，提示 `settleVerifiedBooking` 不存在。

- [ ] **Step 3: 实现一次性原子结算**

实现：

```js
settleVerifiedBooking(state, { bookingId, operatingStoreId, paymentSource, settlementAttemptId })
```

先完成预约、门店、状态、重复提交与余额的全部校验，再扣款并一次性写入 `booking.memberSettlement`、资产流水和会员消费记录；本金实扣计入补贴，积分不计入。

- [ ] **Step 4: 运行结算测试**

Run: `npm test -- --run tests/unit/member-settlement.spec.js`
Expected: PASS，失败分支无部分写入。

### Task 5: 实现品牌每周补贴批次

**Files:**
- Modify: `demo/franchise-platform-h5/src/mock/memberTransitions.js`
- Test: `demo/franchise-platform-h5/tests/unit/member-subsidy.spec.js`

- [ ] **Step 1: 写发放、周期幂等和完结测试**

断言 `runWeeklySubsidy(state, { periodKey: '2026-W31' })` 给每份有效计划发 5100 分，客户补贴余额、计划期数、流水和品牌发放记录同时增加；相同周期二次执行不再增加；99 期计划发放后变为 `completed`。

- [ ] **Step 2: 运行补贴测试确认失败**

Run: `npm test -- --run tests/unit/member-subsidy.spec.js`
Expected: FAIL，提示 `runWeeklySubsidy` 不存在。

- [ ] **Step 3: 实现批次发放**

先按 `periodKey` 返回既有批次，再筛选未完成且该周期未发的计划。每个计划通过资产写入口增加补贴余额并记录 `subsidyRuns`，最后推进 `paidPeriods`、`paidCents`、`status` 和 `nextPeriodKey`，返回本批次汇总。

- [ ] **Step 4: 运行补贴回归**

Run: `npm test -- --run tests/unit/member-subsidy.spec.js`
Expected: PASS，余额、计划和两侧记录完全一致。

### Task 6: 接通消费者端会员、资产和支付界面

**Files:**
- Create: `demo/franchise-platform-h5/src/views/customer/Membership.vue`
- Create: `demo/franchise-platform-h5/src/views/customer/Assets.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Modify: `demo/franchise-platform-h5/src/views/customer/Profile.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/ServiceDetail.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Checkout.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/Payment.vue`
- Modify: `demo/franchise-platform-h5/src/views/customer/BookingDetail.vue`
- Test: `demo/franchise-platform-h5/tests/unit/member-pages.spec.js`

- [ ] **Step 1: 写消费者页面失败测试**

挂载会员中心、资产中心、个人中心、服务详情、结算和支付页，断言两种方案、六项权益、四项资产、会员价、支付来源单选、余额不足禁用及到店结算说明可见；点击开卡和储值后状态立即刷新。

- [ ] **Step 2: 运行页面测试确认失败**

Run: `npm test -- --run tests/unit/member-pages.spec.js tests/unit/router.spec.js`
Expected: FAIL，提示页面或路由不存在。

- [ ] **Step 3: 实现移动端完整入口**

新增 `/c/store/:storeId/membership` 与 `/assets`；个人中心展示会员状态、本金、积分、补贴与权益入口；会员中心调用购买 transitions；资产中心按四类资产与权益展示；服务详情显示本金会员价；商品结算和支付使用单选控件选择微信或本金，并展示原价、优惠、实扣和余额；预约详情明确“到店后由门店结算”。

- [ ] **Step 4: 运行消费者页面回归**

Run: `npm test -- --run tests/unit/member-pages.spec.js tests/unit/router.spec.js tests/unit/order-flow.spec.js`
Expected: PASS，移动页面无跨客户数据。

### Task 7: 接通门店结算与品牌会员运营

**Files:**
- Create: `demo/franchise-platform-h5/src/views/brand/Members.vue`
- Modify: `demo/franchise-platform-h5/src/layouts/BrandLayout.vue`
- Modify: `demo/franchise-platform-h5/src/router/index.js`
- Modify: `demo/franchise-platform-h5/src/views/store/Bookings.vue`
- Modify: `demo/franchise-platform-h5/src/views/store/Verify.vue`
- Test: `demo/franchise-platform-h5/tests/unit/member-management-pages.spec.js`

- [ ] **Step 1: 写三端同源管理页面失败测试**

断言已核销预约可打开结算区，未核销预约不能结算；本金/积分预览金额正确，提交后显示结算快照。品牌“会员运营”含会员、开卡储值、消费、补贴计划、发放记录五个页签，客户详情可钻取资产与权益，执行批次后显示发放结果。

- [ ] **Step 2: 运行管理页面测试确认失败**

Run: `npm test -- --run tests/unit/member-management-pages.spec.js tests/unit/router.spec.js`
Expected: FAIL，提示品牌路由、页面或结算控件不存在。

- [ ] **Step 3: 实现门店和品牌操作面**

在预约详情抽屉及核销结果中复用同一结算状态与 transition，单选本金或积分并明确实扣；新增 `/brand/members` 和侧栏入口，页面用 Element UI 页签、表格和详情抽屉承载五类记录，只提供“执行本周补贴”一个资金动作。

- [ ] **Step 4: 运行管理页面回归**

Run: `npm test -- --run tests/unit/member-management-pages.spec.js tests/unit/router.spec.js`
Expected: PASS，门店结算后品牌消费记录立即出现，品牌发放后客户资产同步。

### Task 8: 完成跨角色浏览器验收与规范收口

**Files:**
- Create: `demo/franchise-platform-h5/tests/e2e/membership-wallet-subsidy.spec.js`
- Modify: `openspec/changes/add-membership-wallet-subsidy-demo/tasks.md`

- [ ] **Step 1: 写真实浏览器闭环**

在 390x844 视口用李四完成开卡与储值，验证本金 5100 元、积分 10200 分和权益边界；切换桌面视口，让门店核销预约并用本金完成 6.6 折结算，品牌查看客户与消费记录；品牌对陈女士执行本周补贴，切回客户后验证补贴余额增加 51 元及到账流水，重复执行不重复到账，最后重置并验证固定种子。

- [ ] **Step 2: 运行聚焦浏览器测试**

Run: `npm run test:e2e -- tests/e2e/membership-wallet-subsidy.spec.js`
Expected: PASS，URL、页面标题、活动导航和关键金额均对应当前角色。

- [ ] **Step 3: 运行完整验证矩阵**

Run: `npm test`
Expected: 所有 Vitest 测试通过。

Run: `npm run build`
Expected: Vite 构建成功，仅允许保留已有的大包体提示。

Run: `npm run test:e2e`
Expected: 所有 Playwright 用例通过。

Run: `openspec validate add-membership-wallet-subsidy-demo`
Expected: change is valid。

Run: `git diff --check`
Expected: 无输出。

- [ ] **Step 4: 完成任务与缺陷学习复盘**

逐项勾选 `tasks.md`，调用 `$defect-learning-loop` 检查根因、回归保护、同类入口和规则去重；只有代码、测试、浏览器证据与 OpenSpec 一致后才提交实现。
