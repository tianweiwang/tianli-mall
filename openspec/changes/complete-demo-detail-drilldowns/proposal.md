## Why

多个演示页面已经展示业务编号、状态和汇总数据，但缺少详情入口或管理动作。甲方操作时会遇到“看得到记录却点不进去”的断点，降低 Demo 的可信度。

## What Changes

- 将品牌门店排行和待处理分账接入现有详情路由。
- 让工作台订单、预约和门店订单摘要打开现有列表抽屉中的指定记录。
- 为分销、提现、直播和设备台账补充轻量详情抽屉。
- 为门店直播补充开始和结束演示状态转换。
- 用单元测试和真实浏览器流程覆盖每个入口及详情结果。

## Capabilities

### New Capabilities

- `demo-detail-drilldowns`: 三端记录详情入口、深链抽屉和本地直播状态操作。

### Modified Capabilities

None. 本变更只补齐独立 H5 Demo 的展示交互，不修改正式 CRMEB 能力。

## Impact

- 修改 `demo/franchise-platform-h5` 的管理页面、本地直播 transition 和测试。
- 不新增 API、数据库、真实支付、直播推流或设备控制。

