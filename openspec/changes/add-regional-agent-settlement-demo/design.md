# Design: 区域代理结算 Demo

## Domain Boundary

区域代理是门店级渠道主体，使用独立 `regionalAgents` 档案和门店 `regionalAgentId` 归属。个人分享继续使用 `distributionBindings`、`distributor` 和 `commissionRecords`。两个领域不共享主体、归属或收益记录。

新增 `regionalAgent.js` 负责适用品类判断、门店代理解析、三方金额计算和代理汇总。金额事实保存在项目结算快照与对应 `profitSharingRecords` 中，页面通过选择器派生待结和已结汇总，不维护第二份可漂移的代理余额。

## Service Settlement Integration

`settleVerifiedBooking` 完成现有余额校验和扣款后，根据服务系列构造结算快照：

- 特色系列、经典系列且使用会员本金：有代理门店按 50% / 35% / 15%，直营门店按 50% / 50% / 0%，并生成一条待分账记录。
- 特色系列、经典系列且使用赠送积分：三方现金金额均为 0，只保存非现金快照，不生成待处理现金分账。
- 其他系列：不应用区域代理模板，继续沿用现有项目结算行为。

项目分账记录使用 `businessType: service-settlement`、`businessId`、`bookingId` 和可空 `orderId` 区分商品订单分账。项目结算和分账记录互相保存引用，重复结算直接返回原结果，不重复创建记录。

## Settlement State

现有分账状态增加失败路径：`processing -> failed -> processing`。只有 `completed` 计入代理已结收益；`pending`、`processing` 和 `failed` 计入待结收益。状态推进继续由 `advanceSharing` 统一校验，重复完成不会重复改变任何汇总。

## UI

品牌侧栏新增“区域代理”。代理列表展示有效代理、覆盖门店、待结和已结数据；代理详情展示档案、归属门店和引用原分账记录的结算流水。门店、全平台订单或项目结算、分账详情补充代理快照及跳转入口。门店结算页展示本店收入和代理说明，但不提供代理管理动作。

集团直营、代理停用、赠送积分和模拟分账失败均使用明确状态文案。所有真实支付相关区域标注“模拟品牌分账，正式上线以微信获批最大分账比例为准”。

## Verification

Vitest 覆盖适用品类、两种结算模板、积分零现金、快照不追溯、状态汇总和幂等。组件测试覆盖路由、菜单、代理列表详情和双向入口。Playwright 完成代理门店本金结算、分账推进、直营门店、积分结算和个人分销独立展示的桌面链路。
