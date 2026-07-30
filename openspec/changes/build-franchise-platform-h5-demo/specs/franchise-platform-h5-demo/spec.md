## ADDED Requirements

### Requirement: 独立静态演示应用

系统 SHALL 提供一套与 CRMEB 正式业务代码隔离的静态 H5 Demo，并在不连接 CRMEB API 的情况下运行。

#### Scenario: 部署到 Nginx 子目录
- **WHEN** 构建产物部署在任意 HTTPS Nginx 子目录
- **THEN** 应用 SHALL 通过相对静态资源路径和 hash 路由正常加载
- **THEN** 页面 SHALL NOT 请求 CRMEB API、远程 Mock 服务或正式环境脚本

#### Scenario: 切换演示角色
- **WHEN** 演示人员在消费者、门店和品牌方之间切换
- **THEN** 应用 SHALL 保留当前演示状态
- **THEN** 不同角色 SHALL 使用各自的页面布局和数据范围

### Requirement: 门店入口与归属

系统 SHALL 通过可真实解码的门店二维码打开对应门店商城，并将新业务归属到该门店。

#### Scenario: 扫描有效门店码
- **WHEN** 用户打开包含有效门店编码的入口 URL
- **THEN** 首页 SHALL 明确显示该门店的名称、地址和营业状态
- **THEN** 后续新订单和新预约 SHALL 固化该门店归属

#### Scenario: 切换门店且购物车非空
- **WHEN** 用户从另一门店入口进入且购物车已有商品
- **THEN** 应用 SHALL 要求用户确认清空购物车
- **THEN** 历史订单和预约的门店归属 SHALL 保持不变

#### Scenario: 门店无效或停用
- **WHEN** 入口 URL 的门店不存在或已停用
- **THEN** 应用 SHALL 展示不可用状态并允许选择其他有效门店
- **THEN** 应用 SHALL NOT 为无效门店创建订单或预约

### Requirement: 版本化演示状态

系统 SHALL 用确定性种子数据和版本化本地状态串联三个角色。

#### Scenario: 首次打开
- **WHEN** 浏览器中不存在演示状态
- **THEN** 应用 SHALL 初始化相同的门店、商品、服务、订单、预约和分账数据

#### Scenario: 状态损坏或版本不匹配
- **WHEN** 本地状态无法解析或版本与当前应用不一致
- **THEN** 应用 SHALL 恢复种子数据并给出恢复提示

#### Scenario: 重置演示数据
- **WHEN** 用户二次确认重置操作
- **THEN** 应用 SHALL 恢复完全相同的种子状态
- **THEN** 应用 SHALL 返回默认消费者门店首页

### Requirement: 消费者商品订单流程

消费者 SHALL 能在所属门店完成商品选择、确认订单和模拟支付，且结果在门店端和品牌方同步可见。

#### Scenario: 完成模拟支付
- **WHEN** 用户确认商品、地址、配送方式和门店后执行模拟支付
- **THEN** 应用 SHALL 只创建一条已支付订单
- **THEN** 订单 SHALL 固化门店、实付金额、品牌抽成和门店预计收入
- **THEN** 门店收入与品牌抽成之和 SHALL 等于订单实付金额

#### Scenario: 重复提交支付
- **WHEN** 用户在处理状态下重复触发支付
- **THEN** 应用 SHALL 阻止创建重复订单

#### Scenario: 门店查看订单
- **WHEN** 门店人员打开订单管理
- **THEN** 只 SHALL 看见归属本店的订单
- **THEN** 页面 SHALL NOT 提供门店发货操作

### Requirement: 服务预约与容量

消费者 SHALL 能在所属门店预约有余量的服务时段，且预约不创建订单或支付。

#### Scenario: 创建预约
- **WHEN** 用户选择有余量的日期和时段并提交
- **THEN** 应用 SHALL 占用一个本地容量并创建待到店预约
- **THEN** 应用 SHALL 生成核销二维码和备用编号
- **THEN** 应用 SHALL NOT 创建订单或发起支付

#### Scenario: 时段已满
- **WHEN** 用户选择的时段已经满额
- **THEN** 应用 SHALL 禁止提交并引导选择其他时段

#### Scenario: 取消或过期
- **WHEN** 待到店预约被取消或推进为过期
- **THEN** 应用 SHALL 只释放一次容量
- **THEN** 已预约数 SHALL 永不小于零

### Requirement: 真实二维码核销

门店端 SHALL 能通过真实二维码扫描流程处理预约核销，并正确拒绝异常场景。

#### Scenario: 本店正常核销
- **WHEN** 当前门店扫描本店待到店预约的有效二维码并确认
- **THEN** 预约 SHALL 变为已核销
- **THEN** 门店工作台、预约列表和品牌方统计 SHALL 同步更新

#### Scenario: 跨店核销
- **WHEN** 当前门店扫描其他门店的预约码
- **THEN** 应用 SHALL 展示预约所属门店并拒绝核销
- **THEN** 预约状态 SHALL 保持不变

#### Scenario: 重复或过期核销
- **WHEN** 门店扫描已核销或已过期的预约码
- **THEN** 应用 SHALL 展示原状态和时间并拒绝重复变更
- **THEN** 门店业绩 SHALL NOT 重复增加

#### Scenario: 摄像头不可用
- **WHEN** 摄像头权限被拒绝或设备不支持摄像头
- **THEN** 页面 SHALL 提供二维码图片识别和备用编号入口

#### Scenario: 跨设备扫描
- **WHEN** 扫描设备本地不存在二维码对应记录但载荷有效
- **THEN** 应用 SHALL 在扫描设备导入一条明确标记的演示记录后完成本机核销
- **THEN** 页面 SHALL 明确说明消费者设备不会实时同步

### Requirement: 门店与品牌经营视图

系统 SHALL 根据本地业务状态展示门店业绩、品牌抽成和分账流程。

#### Scenario: 门店查看经营数据
- **WHEN** 门店人员打开工作台、业绩或结算页面
- **THEN** 指标 SHALL 只由本店订单和预约计算
- **THEN** 统一商品库 SHALL 为只读

#### Scenario: 品牌查看跨店数据
- **WHEN** 品牌人员查看订单、预约、门店排行和经营分析
- **THEN** 页面 SHALL 展示全部门店数据并支持按门店和状态筛选

#### Scenario: 推进模拟分账
- **WHEN** 品牌人员推进一条分账记录
- **THEN** 状态 SHALL 按待分账、处理中、已完成或回退路径流转
- **THEN** 分销佣金 SHALL 从品牌抽成中列示，不得从门店收入重复扣除

### Requirement: 演示质量验收

应用 SHALL 在目标视口和完整演示链路中保持可用、稳定和可重复。

#### Scenario: 移动和桌面视口
- **WHEN** 分别在 375x812、390x844、430x932、1280x800 和 1440x900 打开对应页面
- **THEN** 页面 SHALL 无横向溢出、文字遮挡、空白主区域或布局跳动

#### Scenario: 完整主演示链路
- **WHEN** 执行扫码进店、商品下单、模拟支付、服务预约、真实扫码核销、门店业绩和品牌分账
- **THEN** 每一步 SHALL 使用前一步产生的同一份状态
- **THEN** 浏览器控制台 SHALL 无未处理异常

#### Scenario: 二维码回读
- **WHEN** 使用独立二维码解码器读取应用生成的门店码和核销码
- **THEN** 解码结果 SHALL 与生成时的 URL 和业务载荷一致
