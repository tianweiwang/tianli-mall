# Demo Detail Drilldowns Design

## Goal

补齐消费者、门店和品牌端中“看得到记录但不能查看详情”的演示断点，让甲方能够从汇总、排行和列表自然进入已有详情或轻量详情抽屉，同时保持纯前端本地演示边界。

## Scope

本次包含以下交互：

- 品牌首页门店排行和经营分析门店排行进入现有门店详情页。
- 品牌首页待处理分账进入现有分账详情页。
- 门店工作台最近订单、待到店预约通过查询参数进入管理页并自动打开现有详情抽屉。
- 品牌门店详情的最近订单通过查询参数进入品牌订单页并自动打开现有详情抽屉。
- 品牌分销管理为分销员档案、佣金记录和分享回流提供轻量详情抽屉。
- 门店和品牌提现记录提供详情抽屉；品牌端保留现有通过、驳回操作。
- 门店直播场次提供详情抽屉和开始、结束演示动作；品牌直播提供只读详情抽屉。
- 品牌设备台账提供只读设备详情抽屉。

本次不包含真实接口、数据库、直播推流、设备远程控制、提现打款、分销规则编辑或独立详情路由扩张。

## Approaches Considered

### 1. Reuse routes and lightweight drawers

已有独立详情页的记录直接导航；已有列表抽屉的模块使用查询参数定位记录；没有详情页面的模块在当前页面增加轻量抽屉。该方案改动小，数据仍来自现有演示状态，并能形成完整演示路径。

### 2. Only connect existing detail routes

只修门店排行和分账入口。风险最低，但分销、提现、直播和设备仍然会表现为静态报表，不能解决本次盘点出的完整问题。

### 3. Add dedicated detail routes for every entity

为佣金、分享、提现、直播和设备都新增独立路由。扩展性较强，但会复制大量页面壳和返回逻辑，不符合纯前端 Demo 的最小实现目标。

采用方案 1。

## Architecture

### Direct route drilldowns

品牌门店排行使用行内“查看”入口导航到 `/brand/stores/:storeId`。待处理分账使用行内入口导航到 `/brand/sharing/:recordId`。不增加新路由或重复详情组件。

### Query-driven drawer drilldowns

门店订单、门店预约和品牌订单页面支持 `?recordId=<id>`。页面创建时读取查询参数，只有记录存在且属于当前页面数据范围时才打开已有抽屉。查询参数无效时保持列表页，不报错、不打开错误记录。

工作台和门店详情的行内入口只负责生成上述地址。关闭抽屉不强制改写路由，避免引入额外导航状态复杂度。

### Local read-only drawers

品牌分销、提现、品牌直播和设备台账在当前页面内维护 `selectedRecord` 与 `drawerOpen`。抽屉读取现有对象和现有选择器，不创建副本数据源。

详情内容边界：

- 分销员：编号、等级、佣金比例、累计/已结算/待结算/可提现。
- 佣金：编号、关联订单、成交顾客、金额、状态、发生时间。
- 分享回流：内容、渠道、时间、访问、绑定、归因订单。
- 提现：编号、主体、金额、渠道、账号、状态、申请时间。
- 品牌直播：场次、门店、主播、状态、观看、点赞、挂载商品。
- 设备：名称、型号、门店、状态、版本、最近校准、今日检测和是否逾期。

### Store live state actions

门店直播详情抽屉允许：

- `upcoming -> live`：开始直播。
- `live -> ended`：结束直播。
- `ended`：只读查看。

动作通过现有 `demoStore.commit` 修改本地场次状态，并显示演示提示。品牌端同步读取相同状态。状态转换函数放在 `mock/transitions.js`，拒绝不存在的场次和非法状态转换。

## Components And Files

- `views/brand/Dashboard.vue`：门店排行、待处理分账入口。
- `views/brand/Analytics.vue`：门店排行入口。
- `views/store/Dashboard.vue`：订单与预约深链。
- `views/brand/StoreDetail.vue`：品牌订单深链。
- `views/store/Orders.vue`、`views/store/Bookings.vue`、`views/brand/Orders.vue`：读取 `recordId` 并打开现有抽屉。
- `views/brand/Distribution.vue`：三类分销详情抽屉。
- `views/store/Withdrawals.vue`、`views/brand/Withdrawals.vue`：提现详情抽屉。
- `views/store/Live.vue`、`views/brand/Live.vue`：直播详情及门店状态动作。
- `views/brand/Devices.vue`：设备详情抽屉。
- `mock/transitions.js`：直播状态转换。

不新增通用详情抽屉组件。各模块字段和操作差异明显，强行抽象只会增加条件分支。

## Data Flow

1. 用户从汇总或列表点击查看入口。
2. 已有独立详情页直接通过路由参数读取记录。
3. 已有抽屉的目标页从 `recordId` 查询参数在其已授权/已筛选数据集合中定位记录。
4. 新增抽屉直接持有现有记录引用并渲染。
5. 门店直播状态动作调用 transition，在 `demoStore.commit` 内修改状态。
6. 所有角色立即从同一演示状态看到更新结果。

## Error And Empty States

- 无效门店、分账和现有独立详情路由保持各自已有的未找到状态。
- 无效 `recordId` 只显示正常列表，不自动选择第一条记录。
- 抽屉打开前必须存在目标对象。
- 非法直播状态转换抛出明确错误，由页面显示失败提示且不修改状态。
- 空列表沿用现有 Element UI 或模块空状态。

## Interaction And Accessibility

- 后台表格统一增加“操作 / 查看”列，不把整行变成隐式点击区域。
- 消费者卡片继续使用整卡链接；后台保持明确文本动作，符合现有管理台模式。
- 抽屉标题包含业务对象类型，关键动作使用文字按钮并保留确认提示。
- 不使用仅靠颜色表达直播或审核状态。

## Testing

### Unit tests

- 断言品牌排行和分账入口生成正确路由。
- 断言有效 `recordId` 打开正确订单/预约抽屉，无效编号不打开。
- 断言分销、提现、直播和设备列表的查看动作选择正确记录。
- 断言直播只允许 `upcoming -> live -> ended`，非法转换不改状态。

### Browser tests

- 从品牌首页进入门店详情和分账详情。
- 从门店工作台打开指定订单及预约详情。
- 从品牌门店详情打开指定订单详情。
- 打开分销、提现、直播和设备抽屉并核对记录标识。
- 门店开始/结束直播后，品牌直播列表显示最终状态。
- 检查桌面端无控制台错误、关键页面无水平溢出。

### Completion checks

- 全量 Vitest。
- 生产构建。
- 全量 Playwright。
- `openspec validate complete-demo-detail-drilldowns`。
- `git diff --check`。

## Acceptance Criteria

- 盘点出的每个记录型模块都有明确查看入口。
- 已有详情能力被复用，不出现两套同类详情逻辑。
- 深链只打开指定且有效的记录。
- 直播状态转换在门店和品牌端保持一致。
- 不增加真实网络请求、后端依赖或持久化模型。
