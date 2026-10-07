## ADDED Requirements

### Requirement: 双业态商品目录

系统 SHALL 在本地种子中提供服务项目与实物商品两类目录，实物商品包含系列、分类、单位、规格、售价与库存，服务项目包含系列、时长、单次价与疗程价。

#### Scenario: 展示实物商品目录

- **WHEN** 用户浏览商品分类或后台目录
- **THEN** 系统 SHALL 展示 IGOOD 维养系列商品及其单位、规格与统一售价
- **THEN** 商品 SHALL 支持加入购物车并进入下单链路

#### Scenario: 展示服务项目目录

- **WHEN** 用户浏览门店服务
- **THEN** 系统 SHALL 按评估/传统/特色/经典/精品/定制系列分组展示项目
- **THEN** 每个项目 SHALL 展示时长、单次价与疗程价
- **WHEN** 项目属于定制系列
- **THEN** 系统 SHALL 以"起价"报价并引导到店定制

### Requirement: 服务浏览与预约链路

系统 SHALL 提供消费者服务列表与服务详情页面，并从详情直达预约流程。

#### Scenario: 浏览门店服务

- **WHEN** 消费者打开服务列表
- **THEN** 页面 SHALL 按系列筛选并展示项目卡片
- **WHEN** 消费者打开服务详情
- **THEN** 页面 SHALL 展示介绍、时长、价格双卡片与可预约时段状态
- **WHEN** 消费者点击预约
- **THEN** 系统 SHALL 跳转预约页并预选该服务

#### Scenario: 分类页浏览服务与商品

- **WHEN** 消费者打开商品分类页
- **THEN** 页面 SHALL 提供"门店项目 / 健康好物"双 Tab
- **THEN** 服务 Tab SHALL 按系列筛选并展示服务卡片，商品 Tab SHALL 按分类筛选并展示商品卡片

### Requirement: 后台目录管理

系统 SHALL 为品牌端与门店端提供服务/商品双 Tab 目录管理。

#### Scenario: 品牌管理目录

- **WHEN** 品牌方打开目录管理
- **THEN** 页面 SHALL 提供"服务项目 / 实物商品"切换并展示集团统一目录
- **THEN** 服务项目 SHALL 支持按系列筛选并展示时长与价格

#### Scenario: 门店查看目录

- **WHEN** 门店打开服务与商品目录
- **THEN** 页面 SHALL 只读展示本店可提供的服务项目与统一商品

### Requirement: 本地 Demo 范围

系统 SHALL 将双业态目录限定为本地、确定性、可重置的假数据 Demo。

#### Scenario: 运行目录演示

- **WHEN** 用户浏览、预约或下单
- **THEN** 系统 SHALL NOT 调用真实支付、库存、物流、远程 API、数据库或正式 CRMEB 代码
- **THEN** 服务价格与疗程 SHALL 仅为演示数据
