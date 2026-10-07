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
