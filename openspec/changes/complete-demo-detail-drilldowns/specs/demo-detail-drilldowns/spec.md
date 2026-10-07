## ADDED Requirements

### Requirement: 复用现有详情能力

系统 SHALL 从汇总和排行中的记录进入现有详情页或现有详情抽屉。

#### Scenario: 查看品牌排行门店

- **WHEN** 品牌用户在首页或经营分析点击某个门店的“查看”
- **THEN** 系统 SHALL 进入该门店的现有详情页

#### Scenario: 查看待处理分账

- **WHEN** 品牌用户在首页点击某笔待处理分账的“查看”
- **THEN** 系统 SHALL 进入该分账的现有详情页

#### Scenario: 从摘要打开指定记录

- **WHEN** 门店用户从工作台点击订单或预约记录
- **THEN** 系统 SHALL 进入对应管理页并打开该记录的现有详情抽屉
- **WHEN** 品牌用户从门店详情点击最近订单
- **THEN** 系统 SHALL 进入品牌订单页并打开该订单的现有详情抽屉

#### Scenario: 无效深链

- **WHEN** `recordId` 不存在或不属于当前门店数据范围
- **THEN** 系统 SHALL 保持正常列表页且 SHALL NOT 打开其他记录

### Requirement: 静态管理记录详情

系统 SHALL 为分销、提现、直播和设备记录提供明确查看入口和轻量详情抽屉。

#### Scenario: 查看运营记录

- **WHEN** 用户点击分销员、佣金、分享、提现、直播或设备记录的“查看”
- **THEN** 系统 SHALL 打开与该记录类型匹配的详情抽屉
- **THEN** 抽屉 SHALL 展示记录标识、状态和该模块已有的关键业务字段

### Requirement: 门店直播状态操作

系统 SHALL 在本地演示状态中按合法顺序推进门店直播场次。

#### Scenario: 开始并结束直播

- **GIVEN** 门店直播状态为 `upcoming`
- **WHEN** 门店用户开始直播
- **THEN** 状态 SHALL 变为 `live`
- **WHEN** 门店用户结束直播
- **THEN** 状态 SHALL 变为 `ended`
- **THEN** 品牌直播页面 SHALL 显示相同最终状态

#### Scenario: 拒绝非法转换

- **WHEN** 场次不存在或目标状态不符合 `upcoming -> live -> ended`
- **THEN** 系统 SHALL 拒绝操作且 SHALL NOT 修改原状态

