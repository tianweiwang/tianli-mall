# MS4–MS6：交易、支付、发布 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。三个里程碑依次开工，各自验收。每个里程碑开工时，先完成其 Task 1 的盘点并在停止点回报，再按回报确认后的范围执行；本文件给出任务划分、接口和验收用例，具体文件路径以盘点结果为准，并在报告中列出。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §1.2、§6.3、§7.2、§8、§9、§10、§11；用例 O、P、R 系列，D19，K02–K05。

## Global Constraints

- 一笔订单只属于一个商家；订单及其子单、订单项、售后、发货记录的 `mer_id` 与订单一致，创建后不可修改。
- 首版不启用：跨店支付、充值余额、积分抵扣、优惠券、分销与活动叠加。这些入口在服务端由配置 `marketplace.trade.disabled_features` 控制并拒绝；不删除历史数据，不改历史余额与积分。
- 金额沿用现有以元计的 `decimal` 字段；到支付接口的分值转换只在支付适配层做，用整数运算，并单独测试。
- 不发起真实扣款或退款，除非用户为具体的验收流程单独授权（L4），并说明金额上限与对账人。
- 测试支付驱动只能在测试环境注册；生产环境检测到测试驱动或缺少真实驱动时，拒绝启动支付能力。

---

## MS4：单店交易

**Goal:** 顾客按店铺结算；订单、库存、发货、售后都限定在单一商家内；商家只能处理本店订单；评价按订单归属。

### Task 1: 交易链路盘点（停止点 SP-MS4-1）

- [ ] 列出购物车、确认订单、创建订单、支付成功、取消、超时关单、拆单、发货、售后、退款、评价的控制器 → 服务 → DAO 调用链，以及库存扣减与释放的时点（下单时还是支付时）。
- [ ] 列出现有余额、积分、优惠券、分销、活动在下单链路中的介入点，以及存量数据规模（只统计）。
- [ ] 提出：要关闭的新交易入口清单；存量余额、积分、优惠券、进行中活动的处理方式；`eb_store_order.mer_id` 是否可复用（F-21）。
- [ ] 停止并回报，等待用户确认存量权益的处理方式和关闭清单。

### Task 2: 迁移

**Interfaces:**
- Produces（以盘点结论为准）：`eb_marketplace_order_idempotency(uid, mer_id, idem_key, request_digest, order_id, add_time)`，唯一键 `(uid, idem_key)`；`eb_marketplace_stock_movement(id, mer_id, order_id, product_id, unique, qty, kind, idem_key, add_time)`，`kind` 取 `reserve`、`release`、`return_in`，唯一键 `idem_key`；订单商家快照字段（店铺名、售后联系方式）。
- 用例：G01、G06、G07 针对本次新增结构。

### Task 3: 购物车按店分组

- 接口：购物车列表按 `mer_id` 分组返回；结算请求必须带 `mer_id`，服务端根据商品与规格查出实际归属并比对。
- 用例：O01。

### Task 4: 单商家下单

**Interfaces:**
- Produces: `MarketplaceOrderCreateServices::create(int $uid, int $merId, array $cartIds, int $addressId, string $idemKey, array $options): array`；在一个数据库事务中完成：幂等记录占位 → 校验商品、规格、商家状态（`review_status = 1`、`accept_orders = 1`、未封禁、未关闭）、地址属于该顾客、运费模板属于该商家 → 条件扣减库存（`UPDATE ... SET stock = stock - ? WHERE unique = ? AND stock >= ?`，影响行数必须为 1）→ 写订单、订单项、商家快照 → 写 `reserve` 库存流水。
- 用例：O02（混合商家、商品与规格错配 → 拒绝，库存与订单表不变）、O03（同一幂等键重复提交返回同一订单；同一键换商品或商家 → 409）、O04（两个并发请求抢最后一件，恰好一个成功）、O05（下单后改价、改店铺名，订单快照不变）、O07（使用他人地址 → 拒绝）、R05 的下单子场景（带优惠券、积分、余额参数 → 拒绝）。

### Task 5: 取消与超时释放

- 接口：取消与超时关单都通过 `release` 流水释放库存，`idem_key = "release:<order_id>:<unique>"`，重复执行不重复释放。超时关单前先查询支付状态（MS5 之前用测试驱动），已支付的不关单，转入异常处理。
- 用例：O06。

### Task 6: 商家订单操作

**Interfaces:**
- Produces: merchantapi 订单列表、详情（只含履约所需的收货快照）、发货（数量不超过未发货数量）、售后处理；权限码新增 `order.read`、`order.ship`、`aftersale.handle`（加入 `MerchantPermissions`）。
- 用例：O08（A 查询、发货、取消、导出 B 的订单或售后 → 404，零副作用）、O09（拆单后子单 `mer_id` 与父单一致）、O10（`accept_orders = 0` 时禁止新单，存量订单仍可发货、售后，不被删除）。

### Task 7: 退款额度与库存行为

- 接口：申请退款时在事务中锁定订单行，校验"已退金额 + 处理中金额 + 本次金额 ≤ 实付"；三类库存行为分别写 `release`（未发货取消）、`return_in`（退货验收入库）、不写（仅退款）。
- 用例：O11（并发申请合计超过可退金额 → 只有不超额的部分成功）、O12。

### Task 8: 评价

- 接口：评价记录按订单商品取 `mer_id`；商家只能读和回复本店评价，不能修改顾客原文、伪造、删除；平台审核接口带权限与审计；公众接口只返回审核通过的脱敏内容。
- 用例：D19。

### Task 9: 客服（决策点）

- 默认：v0.1 不开放商家客服，保持 K01 的双端禁用并回归。
- 若用户决定在 MS4 开放：会话按"商家 + 顾客"划分；HTTP 与 WebSocket 的加入、发送、历史读取、附件下载每次都校验归属；用例 K02–K05 全部通过后才能开放。

### Task 10: 回归与交付

- 运行全部既有用例与 O 系列、D19、R05（下单子场景）、K01（或 K02–K05）；写 `records/ms4/`；停止，等待验收（11 文档 §5.7）。

---

## MS5：支付、退款与对账

**开工前提：** Q4（收款产品、经营主体、商户账户方案）与 Q5（平台费用规则）已由用户书面决定，并记录在 `records/ms5/decisions.md`。未决定时只能做 Task 2 的接口与测试驱动，不能接入真实渠道。

### Task 1: 盘点与方案确认（停止点 SP-MS5-1）

- 盘点现有 `PayServices`、各支付驱动、回调入口、退款入口、支付记录表；确认是扩展现有记录还是新增 `payment_attempt` / `refund_attempt`；回报并等待确认。

### Task 2: 支付适配契约与测试驱动

**Interfaces:**
- Produces: `crmeb\services\pay\marketplace\PaymentAdapter` 接口（方法见 00 文档 §9）；`TestPaymentDriver`（只在 `APP_ENV=testing` 且配置显式开启时可注册）；`PaymentAccountResolver::forOrder(array $order): PaymentAccount`（按订单 `mer_id` 取已验证且启用的账户，缺失则抛异常，不回退平台账户）。
- 用例：P02、P11。

### Task 3: 收款账户

- 表 `eb_marketplace_payment_account(id, mer_id, channel, account_ref, version, status, verified_at, add_time)`；`account_ref` 只保存配置引用，不保存密钥明文。订单发起支付时把账户 ID 与版本写入支付尝试记录，之后不可更改。
- 用例：P01、P07。

### Task 4: 回调与查询

- 回调先验签，再按机构交易号找到支付尝试，核对账户、金额、币种、订单；状态推进用条件更新保证只成功一次；晚到的成功（本地已关单、商家已停用）进入异常队列并留审计。
- 用例：P03、P04、P05、P06。

### Task 5: 退款

- 退款沿原支付尝试与原账户；幂等键防重复；并发退款受 MS4 Task 7 的额度约束；已结算订单的退款登记费用冲回或差异待处理。
- 用例：P07、P08、P09。

### Task 6: 对账与结算记录

- 导入机构账单，与本地支付、退款记录逐笔比对，差异进入处理清单；结算记录分别记销售、退款、通道费、平台费、商家应收（按 Q5 规则及其版本）；不开放提现。
- 用例：P10。

### Task 7: 外部验收

- 只在用户为具体流程授权（L4）后执行小额真实支付与退款；记录机构交易号与账单行；未授权时 P 系列中需要真实渠道的子场景写 BLOCKED。

### Task 8: 回归与交付

- 运行全部用例；写 `records/ms5/`；停止，等待验收（11 文档 §5.8）。

---

## MS6：发布、恢复与运行

**开工前提：** Q2、Q3 已决定。

### Task 1: 发布配置

- 更新部署编排：商家后台静态资源与 `/merchant/` 路由、`AUTH_SESSION_EPOCH`、`AUTH_STAMP_KEY`、`marketplace.*` 配置、队列与常驻进程；按 Q2 决定构建产物的跟踪方式；按 Q3 决定是否轮换历史凭据。

### Task 2: 持久化与恢复演练

- 用例 R01（重启、重新部署后商家、商品、订单、上传文件仍在，队列任务按规则恢复）、R02（备份恢复到隔离环境，数据与文件核对一致，恢复后的环境仍然隔离；认证会话**不随**业务备份恢复，恢复后按 MS0b 的 epoch 轮换）。

### Task 3: 防止不安全回滚

- 数据库增加 `eb_marketplace_schema(id, schema_version, min_app_version, updated_at)`；新版应用启动时校验自身版本不低于 `min_app_version`。旧的单商家代码本身不会做这个检查，所以发布脚本（`help/docker/` 中的部署入口）必须在部署前读取该表并拒绝低于要求的镜像标签。在 runbook 中写明：手工绕过发布脚本属于违规操作。
- 用例 R03。

### Task 4: 日志与脱敏

- 权限拒绝、支付错配、任务重试都带关联 ID；日志中不出现密码、完整令牌、密钥、无关个人信息（用规则扫描日志样本）。
- 用例 R04。

### Task 5: 旧入口与配额

- 用例 R05（新开关未启用时直接调用旧营销、余额入口 → 按约定拒绝）、R06（单商家高频请求、导出任务的限流与监控）。

### Task 6: 发布清单与全量回归

- 全部用例在 ISO-FULL 与预发布环境各运行一次；发布清单（含外部调用方通知状态、回退方案、负责人）由用户逐项确认；写 `records/ms6/`；停止，等待验收（11 文档 §5.9）。真实发布需要 L4。
