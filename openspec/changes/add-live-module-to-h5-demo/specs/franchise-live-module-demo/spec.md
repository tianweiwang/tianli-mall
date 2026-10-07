## ADDED Requirements

### Requirement: 本地直播种子

系统 SHALL 在本地种子中提供至少四个直播场次，覆盖 `live`、`upcoming`、`ended` 三种状态，包含门店、标题、主播、观看数、点赞数与挂载商品，并在重置后恢复相同演示数据。

#### Scenario: 恢复直播种子

- **WHEN** Demo 版本升级或用户确认重置演示数据
- **THEN** 系统 SHALL 恢复相同直播场次、观看数与点赞数
- **THEN** 场次 SHALL 归属对应演示门店并挂载可加购商品

#### Scenario: 直播数据累加

- **WHEN** 消费者在直播中场次执行点赞或模拟进入
- **THEN** 系统 SHALL 累加该场次点赞数与观看数并写入本地活动流水
- **WHEN** 对非直播中场次执行累加
- **THEN** 系统 SHALL 拒绝操作且不改变场次数据

### Requirement: 三角色直播页面

系统 SHALL 为消费者、门店和品牌方提供各自授权范围内的直播页面。

#### Scenario: 消费者查看直播

- **WHEN** 消费者打开门店直播列表
- **THEN** 页面 SHALL 展示当前门店直播、预告与回放场次及状态
- **WHEN** 消费者进入直播间
- **THEN** 页面 SHALL 展示模拟直播画面、观看/点赞数据与直播商品
- **THEN** 直播商品 SHALL 支持直接加购

#### Scenario: 门店管理直播

- **WHEN** 门店打开直播管理
- **THEN** 页面 SHALL 只展示本店场次与观看/点赞指标
- **WHEN** 门店创建演示场次并挂载商品
- **THEN** 系统 SHALL 创建 `upcoming` 场次并加入本店场次列表

#### Scenario: 品牌运营直播

- **WHEN** 品牌方打开直播运营
- **THEN** 页面 SHALL 展示跨门店场次汇总、状态筛选与总场次表
- **THEN** 总场次表 SHALL 展示观看数、点赞数与所属门店

### Requirement: 本地 Demo 范围

系统 SHALL 将直播模块限定为本地、确定性、可重置的假数据 Demo。

#### Scenario: 运行直播演示

- **WHEN** 用户浏览或操作直播场次
- **THEN** 系统 SHALL NOT 调用真实推流、播放、弹幕、礼物、支付、远程 API、数据库或正式 CRMEB 代码
- **THEN** 直播间画面 SHALL 为本地模拟 UI
