## MODIFIED Requirements

### Requirement: 检测任务与顾客归属

系统 SHALL 用不透明检测任务 ID 将本地固定设备结果关联到会员或临时顾客，并记录门店、自动绑定的设备和操作人。

#### Scenario: 从预约自动创建固定设备任务

- **WHEN** 门店为有效体态评估预约创建检测任务
- **THEN** 系统 SHALL 固化顾客、预约、门店、授权和有效期
- **THEN** 系统 SHALL 自动绑定该门店的一台在线固定设备，并以 `ready` 状态创建任务
- **THEN** 任务展示信息 SHALL NOT 包含完整手机号或内部用户 ID

#### Scenario: 临时顾客现场建档自动绑定设备

- **WHEN** 未注册顾客同意检测并由门店现场建档
- **THEN** 系统 SHALL 创建临时顾客、授权记录和已自动绑定设备的 `ready` 检测任务

#### Scenario: 门店没有在线固定设备

- **WHEN** 门店为有效预约或现场顾客创建检测任务，但本店没有在线固定设备
- **THEN** 系统 SHALL 使创建失败并提示设备当前不可用
- **THEN** 系统 SHALL NOT 追加未绑定设备或部分创建的检测任务

### Requirement: 本地设备状态机

系统 SHALL 用确定性本地载荷模拟固定设备检测、回传、复核和报告生成，正常生命周期为 `ready -> measuring -> awaiting-review -> completed`。

#### Scenario: 正常完成

- **WHEN** 门店从 `ready` 开始检测，自动绑定的同门店设备回传完整载荷
- **THEN** 任务 SHALL 依次进入 `measuring` 和 `awaiting-review`
- **THEN** 门店确认后任务 SHALL 进入 `completed` 并生成一份版本化已发布报告

#### Scenario: 同任务同设备离线重试

- **WHEN** `measuring` 任务的自动绑定设备离线
- **THEN** 任务 SHALL 进入可恢复的重试状态并保留原任务 ID、设备 ID 和尝试记录
- **WHEN** 门店恢复并重试
- **THEN** 任务 SHALL 回到 `measuring`，且系统 SHALL NOT 创建或重新绑定任务

#### Scenario: 数据不完整、重复或跨店结果

- **WHEN** 自动绑定设备回传不完整载荷
- **THEN** 任务 SHALL 进入需复核状态，且系统 SHALL NOT 生成正式报告
- **WHEN** 同一设备结果流水重复回传
- **THEN** 系统 SHALL 返回已接收结果，且 SHALL NOT 新增报告
- **WHEN** 非任务所属门店的设备回传结果
- **THEN** 原始提交 SHALL 被隔离，且 SHALL NOT 改变任务归属或生成正式报告

#### Scenario: 拒绝非法状态或非绑定设备回传

- **WHEN** `ready`、`retrying`、`awaiting-review` 或 `completed` 任务收到新的设备结果
- **THEN** 系统 SHALL 拒绝结果，且 SHALL NOT 改变任务、追加原始提交或生成报告
- **WHEN** 同门店内非任务绑定设备回传结果
- **THEN** 原始提交 SHALL 被隔离，且 SHALL NOT 改变任务归属或生成正式报告

#### Scenario: 门店处理自动绑定的检测任务

- **WHEN** 门店人员打开一份已自动绑定固定设备的检测任务
- **THEN** 页面 SHALL 显示绑定设备信息和“开始检测”操作
- **THEN** 页面 SHALL NOT 显示检测任务二维码、设备领取操作、`waiting-device` 或 `claimed` 状态
- **THEN** 页面 SHALL 仅显示脱敏手机号；预约创建失败时 SHALL 展示设备不可用原因

### Requirement: 三角色检测视图

系统 SHALL 让消费者、门店和品牌方从同一份本地检测状态读取各自授权范围内的数据。

#### Scenario: 消费者查看报告

- **WHEN** 当前消费者打开检测历史或报告
- **THEN** 页面 SHALL 只展示该消费者的记录、变化和复评入口
- **THEN** 报告 SHALL 明确为体态记录且不替代医疗诊断

#### Scenario: 门店处理检测

- **WHEN** 门店人员打开检测中心
- **THEN** 页面 SHALL 只展示本店任务、顾客、自动绑定设备和复评队列

#### Scenario: 品牌查看运营

- **WHEN** 品牌人员打开设备或检测运营页
- **THEN** 页面 SHALL 展示跨店汇总、设备状态、门店质量和已发布检测记录
- **THEN** 页面 SHALL NOT 展示完整手机号、检测影像或医疗结论

### Requirement: 可重复演示

系统 SHALL 在重置后恢复相同检测数据和可执行主流程。

#### Scenario: 恢复固定设备种子

- **WHEN** Demo 版本升级或用户确认重置演示数据
- **THEN** 系统 SHALL 保留 `ASR202607301001` 和 `ASR202607301002` 两份既有报告，并提供另一门店的已发布记录
- **THEN** 所有待处理种子任务 SHALL 已包含 `deviceId`，且 SHALL NOT 使用 `waiting-device` 或 `claimed` 状态

## ADDED Requirements

### Requirement: 品牌已发布检测记录

系统 SHALL 为品牌方提供只读的本地已发布检测记录表和详情抽屉，正式记录只来自 `reportStatus === 'published'` 的报告。

#### Scenario: 查看已发布记录和详情

- **WHEN** 品牌人员打开检测运营页面并查看一份已发布报告
- **THEN** 表格 SHALL 显示顾客姓名和脱敏手机号、门店、设备、检测时间、肩高差、骨盆倾斜、颈前倾角及不适评分
- **THEN** 详情抽屉 SHALL 显示上述信息、相对上次和首次的四项比较、设备型号和软件版本、报告版本及非医疗建议
- **THEN** 没有可比较报告时，详情 SHALL 显示“暂无对比”

#### Scenario: 保护品牌侧隐私与权限边界

- **WHEN** 品牌人员查看记录表或详情抽屉
- **THEN** 页面 SHALL NOT 输出完整手机号、检测影像、指标编辑能力、报告确认/重发/删除操作或医疗结论

#### Scenario: 待复核数据的聚合与正式记录边界

- **WHEN** 存在待确认或待复核检测任务
- **THEN** 任务 SHALL 继续参与现有运营聚合指标
- **THEN** 任务 SHALL NOT 出现在正式检测记录表

#### Scenario: 筛选已发布记录

- **WHEN** 品牌人员按门店、单个检测日期或报告编号/顾客姓名关键词筛选
- **THEN** 系统 SHALL 仅过滤检测记录表
- **THEN** 顶部运营指标、趋势、门店质量和指标均值 SHALL 保持原全局统计口径

### Requirement: 预约核销二维码兼容性

系统 SHALL 保持消费者预约核销二维码及其扫码流程不变。

#### Scenario: 执行预约核销

- **WHEN** 门店使用现有预约核销二维码完成预约验证
- **THEN** 原有二维码编解码和核销行为 SHALL 保持可用，且不依赖检测任务二维码或设备领取流程

### Requirement: 本地 Demo 范围

系统 SHALL 将本能力限定为本地、确定性、可重置的 H5 Demo。

#### Scenario: 运行固定设备检测演示

- **WHEN** 用户执行检测任务、结果回传或查看品牌记录
- **THEN** 系统 SHALL NOT 调用真实硬件、厂商 API、远程 Mock API、数据库或正式 CRMEB 代码
