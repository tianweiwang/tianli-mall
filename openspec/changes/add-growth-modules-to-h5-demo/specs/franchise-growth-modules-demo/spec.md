## ADDED Requirements

### Requirement: 本地增长模块种子

系统 SHALL 在本地种子中提供积分账户与明细、推荐码与邀请记录、分销档案与佣金记录、统一提现申请记录和分享回流记录，并在重置后恢复相同演示数据。

#### Scenario: 恢复增长模块种子

- **WHEN** Demo 版本升级或用户确认重置演示数据
- **THEN** 系统 SHALL 恢复相同积分余额、邀请记录、佣金记录、提现申请和分享回流
- **THEN** 新增记录 SHALL 归属于同一演示顾客或当前门店

#### Scenario: 积分奖励与扣减

- **WHEN** 消费者完成模拟支付
- **THEN** 系统 SHALL 按实付金额每 100 分奖励 1 积分并追加明细
- **WHEN** 消费者兑换积分商品
- **THEN** 系统 SHALL 扣减积分并追加明细
- **THEN** 余额不足时 SHALL 拒绝兑换且不改变账户

#### Scenario: 推荐与分享回流

- **WHEN** 消费者执行模拟邀请
- **THEN** 系统 SHALL 追加一条待首单邀请记录
- **WHEN** 消费者执行模拟分享
- **THEN** 系统 SHALL 追加分享记录并计入访问与转化汇总

#### Scenario: 提现申请与审核

- **WHEN** 门店或分销员提交提现申请且金额不超过可提现余额
- **THEN** 系统 SHALL 创建 `pending` 提现记录
- **WHEN** 金额超过可提现余额
- **THEN** 系统 SHALL 拒绝申请且不创建记录
- **WHEN** 品牌方审核提现
- **THEN** 系统 SHALL 支持推进 `pending -> processing -> completed` 或直接 `rejected`

### Requirement: 三角色增长页面

系统 SHALL 为消费者、门店和品牌方提供各自授权范围内的增长模块页面。

#### Scenario: 消费者查看增长中心

- **WHEN** 消费者打开积分中心、推荐有礼、分享中心或分销中心
- **THEN** 页面 SHALL 展示余额/汇总、规则或记录明细，并支持对应的模拟操作

#### Scenario: 门店申请提现

- **WHEN** 门店打开提现管理
- **THEN** 页面 SHALL 只展示本店已完成结算收入和本店提现记录
- **THEN** 页面 SHALL 提供金额、渠道和账号的申请表单

#### Scenario: 品牌管理分销与提现

- **WHEN** 品牌方打开分销管理
- **THEN** 页面 SHALL 展示分销档案、佣金汇总、佣金明细和分享回流汇总
- **WHEN** 品牌方打开提现审核
- **THEN** 页面 SHALL 展示门店与分销员提现记录并支持通过/驳回

### Requirement: 本地 Demo 范围

系统 SHALL 将增长模块限定为本地、确定性、可重置的假数据 Demo。

#### Scenario: 运行增长模块演示

- **WHEN** 用户执行积分兑换、推荐、分享或提现审核
- **THEN** 系统 SHALL NOT 调用真实支付、实名、风控、短信、远程 API、数据库或正式 CRMEB 代码
