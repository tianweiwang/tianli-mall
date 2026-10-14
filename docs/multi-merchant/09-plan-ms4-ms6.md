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

**当前实现合同（通用商城）：** 先在现有 `StoreCartServices::getUserCartList` 和 `PublicCatalogServices` 接入本 UID 的分页购物车分组，再改顾客端购物车消费该合同。保留 `valid`、`invalid`、`deduction` 兼容字段，增加 `groups`，每组仅含真实正整数 `mer_id`、固定公众 `store` 投影、真实 `cart_refs` 和已脱敏 `rows`；顺序沿当前购物车页首次出现顺序，同一商户跨页由前端合并。分页仍按原购物车行，不冒称返回全店商品数或全店合计。

- 分组归属在脱敏前由本次实际 `cartIdentity` 的当前商品根和活动规格得到，不信任关系缓存、客户端 `mer_id` 或商品名称。复用公众店铺投影；组内不返回原商户行、成本、UID、内部配置或授权上下文。已失效行保留在原 `invalid`，不产生可结算店铺组；保留活动商品的当前兼容展示，不借分组修改存量权益或活动政策。
- 新购物车页的公众行 `id` 和 `cart_refs` 统一为规范正十进制字符串，包含 `valid` 与 `invalid`。实际数据库 BIGINT 编号保持其精确值，不做 32 位或 JavaScript Number 转换，避免 JSON 数字传输导致超大编号丢失精度；存储和其他历史投影不改。
- 顾客端按店显示名称和商品；正常结算只选择一家店，切换店铺后重新选择该店商品，管理删除可跨店。合计仅针对选中行，不把跨店总价标为可一次支付。向确认页传真实 `cartId` 和该组 `mer_id`；确认/创建服务后续独立重验归属，前端选择不构成授权，也不据此开启尚未完成的新交易入口。
- Files：修改 `crmeb/app/services/marketplace/PublicCatalogServices.php`、`crmeb/app/services/order/StoreCartServices.php`、`template/uni-app/pages/order_addcart/order_addcart.vue`；确认页及实际确认接口接线在读取其现有合同后补入本记录。继续复用原商品、SKU、价格和购物车，不新增表或另建商品体系。
- 验证责任：聚焦 PHP 语法、真实调用与公众字段复核；顾客端使用官方 uni-app 条件预处理/构建；之后用专用隔离普通账号及真实 A/B 店铺和购物车行核对同店合并、跨页保留、数量调整、单店选择与实际确认。实际 API、页面及 O01 尚未通过，不以源码检查记为完成；用户已停止的拒绝/冲突/故障等用例仍不运行，原完整验收责任保留。
- 2026-10-15 本地实现：上述两 PHP 的分页分组与公众编号字符串投影、购物车 Vue 的按店展示/一店勾选/管理跨店操作已实现。页面继续用全局行索引处理数量和规格；同页原两个同名数量回调已分开，两个响应来源的失效行按实际编号合并。两 PHP 隔离语法检查 exit 0；实际 Vue 经官方 H5 预处理、Vue 2.6.14 模板编译、Node 22 语法及 Dart Sass 均通过，41 方法无重名；17 条仅源码层的普通选择状态检查通过。该检查没有 DOM、应用、SQL 或 API，不代表视觉、真实订单或 O01 通过；确认页消费 `mer_id`、服务端单店确认/创建、实际页面验收仍待接线和验证。

### Task 4: 单商家下单

**当前本地实现依据（通用商城）：** 在独立 `feature/ms4-single-store` / `worktrees/ms4-generic-trade` 中推进新 version1 依赖；原 MS3 源码和隔离实例保持其既有来源。原 Task 1 的历史权益盘点、交易集成和全部验收责任仍保留，以下纯计算完成不表示 Task 4 或 MS4 完成。

- 分项运费沿用既有报价的主预算及非负原分摊权重，最后用归一化最大余数分币；金额和权重只接受十进制字符串，全程 BCMath 整数运算，不经 float。先分配 `floor(B*w/W)` 分，再按余数降序分配余分，同余数按真实 cart 引用及物理 SKU ID 稳定排序。预算零时全零；正预算无权重表示报价合同不完整。保证每行非负、合计严格等于主预算；历史订单和旧报价公式不改。
- Files：`crmeb/app/services/marketplace/order/MarketplacePostageAllocation.php`；`help/audit-regressions/isolated/ms4-postage.php`。内部纯计算接口接收总运费与真实引用、SKU ID、原权重行，返回同序引用与两位小数运费。实际报价/创建服务接线仍待实现，不新增路由、表、配置或权益行为。
- 聚焦验证：5.35 两项等权得到 2.68/2.67；0.03 五项等权不产生负行；零运费、权重不等、原 BC4/BC6 微差、超出 native-int 乘积范围的正常金额，以及输入重排行后的稳定分配。仅在既有 PHP 8.2 镜像、无网络且根文件系统只读的隔离容器运行纯计算，不加载框架、数据库或支付。用户已停止的无效/拒绝/权限/冲突/并发/故障/恢复案例不运行。
- 后续创建合同继续沿已盘点设计：真实 request UID/cart/确认引用与持久幂等摘要；merchant→商品→SKU 锁序；reserve/release/return_in 使用真实物理 SKU 及父 reserve。实物入库单 SKU 的已提交 return_in 流水 PK 就是验收号，绑定真实退款行/演员/数量；新订单持久登记收尾责任，复用定时扫描，新流水接入既有 SKU 硬删保护。相关迁移与服务尚未实现，不能以此段方案记为验收通过。
- 2026-10-15 本地检查：上述纯计算类已实现。隔离 PHP 普通样本实际通过 7 场景、9 次调用、28 条断言，Root 再运行同纯脚本 exit 0；金额/引用源代码复核及 `git diff --check` 通过。未加载应用、SQL 或支付；实际报价/创建接线、数据库订单项与原 Task 4 验收仍待完成。各里程碑复选状态保持原责任。

**当前确认与计算实现合同：** 实际写入口仅为现有 v1 的 `confirm`、`computedOrder`、`create`；v2/PC 没有独立下单控制器，不增造接口。继续使用真实 Request UID、已有顾客与购物车服务、原 opaque 确认 key 和 600 秒 UID 缓存。

- 服务端 `marketplace.enabled` 决定新商城确认合同，`new/is_new` 仅决定持久或临时购物车来源。新确认要求真实 `mer_id` 选店意图，从本人当前购物车、当前商品根及 active SKU 核出唯一实际商家，冻结 `marketplace_confirmation_version=1`、UID、`mer_id`、`source_kind`、规范 cart 引用及 `cart_ref/product_id/sku_id/sku_type/unique/qty` 选择；保留原 `cartInfo/priceGroup/other`，确认不占库存。持久引用按十进制字符串排序，临时引用保留实际字节，CSV 不 trim；读取原始解析参数，不让 `postMore/filter_str` 静默改写 opaque 引用或摘要材料。仅 version1 公众确认的顶层及嵌套 cartInfo.id 转为精确引用字符串，旧 quote/history 投影保持原合同。
- 同一个真实 key 允许更换本人的普通地址和配送，重新核当前店铺、商品、规格、数量和真实报价，不能换冻结商品选择；提供了地址时必须匹配本人有效地址，不把无匹配请求悄悄换成默认地址。缓存预览价格不作为创建权威。原 SKU/会员价格和运费资格保留，新可信合同的计算全程保持 decimal 字符串，原共享方法增加内部参数或分支，旧调用和历史结果保留。原未格式化运费权重保持私有，在最终实际主运费预算确定后接最大余数分币，公众投影继续过滤顶层及嵌套成本与内部权重。
- 精确接线：共享 `getUserProductCartListV1`、`handleCartList`、`setLevelPrice`、`getOrderPriceGroup`、`computedPayPostage`、`computedOrder` 末尾增加默认 false 的内部 `bool $marketplace`，仅真实 version1 服务传 true。会员 BC2 截断、相等选等级价、非零最低 0.01 及优惠额规则保留，不改为四舍五入；金额从真实 decimal 字段及配置的十进制原值取得，不能先 JSON/原生 float 运算再包装字符串。原 BC2 运费商的向上取整用字符串运算，保持原收费公式。固定费及所选模板的格式化前六位贡献放在仅内部的 sidecar，包含原有效会员折扣后的贡献（固定费 BC6、模板 BC4 后补六位）；仅 `0 < express_rule_number < 100` 是有效运费折扣，会员服务返回 false/0 表示该折扣未启用，新路径按与主预算相同的因子 1 保留固定费贡献，不能让正主预算失去全部权重。旧 false 路径保持原行为。最终会员/配送/线下包邮确定 `payPostage` 后分配一次并同步实际 cartInfo，不能以分项反算主预算或重复会员折扣。
- `marketplace.trade.enabled` 默认 false，仅在完整创建/库存/收尾/取消合同实现并验收后开启新创建。当前阶段新创建入口关闭，version1 缓存不能回落旧 `createOrder`；历史 version0/NULL 订单继续已有读取和处理，不按旧 `mer_id` 升级。后续持久幂等先于缓存读取，已提交同请求的重放不重新算价、扣库存或产生权益，原旧 `extend_order` 早返回不能绕过新合同。
- 新交易仅关闭 `['coupon','integral','balance','brokerage','activity']`；新确认跳过自动领券，新 computed 不消费这些权益，兼容缺省零值并保留原响应字段。会员价格、普通地址、发票、custom_form 等不是这五项开关；自提、礼物与存量权益的待定责任继续保留，不借本次实现删除历史数据或永久移除能力。首版普通快递的新路径先保持明确范围，未决定能力不开放新的入口。
- 顾客确认页及地址/发票往返保留实际 `mer_id`、cart 引用/来源及真实确认 key。商品选择未变时换地址/配送只 computed，不同时启动异步 confirm 导致旧/新 key 竞态；换商品才重新确认。后续新 create 补真实 cartId、mer_id、一次逻辑提交的 idem_key，普通重试复用，保留原 `result.orderId` 成功包装。
- Files：按以上合同增补 `crmeb/config/marketplace.php`、`crmeb/app/services/marketplace/order/` 内的最小策略/输入与真实选择服务，修改实际 v1 `StoreOrderController`、`StoreOrderServices` 确认缓存、`StoreOrderCreateServices` 旧入口隔离，以及共享 cart/product/price 的可信 decimal 参数；前端确认、地址与发票消费端随后接线。此段为执行依据，相关实现在未通过真实隔离验证前不标 Task 4 完成，不运行用户已停止的拒绝/冲突/并发/故障等用例。
- 2026-10-15 后端本地实现：可信单店确认、真实选择冻结及重读、UID 缓存版本、原始引用、普通地址验证和 decimal 计算已接到现有服务；公众新增可信店铺/版本元数据，顶层及嵌套引用为精确字符串，成本及内部权重不公开。新创建在控制器和旧服务入口保持关闭，尚无持久幂等、迁移或库存创建事务。Root 最终复核 13 个 PHP 文件（含普通纯计算脚本），在已有 PHP 8.2 镜像、无网络、只读源文件及根文件系统下 `-l` 全部 exit 0；12 条仅真实 decimal 原语的普通数学断言通过，聚焦差分检查通过。独立源复核已修正有效会员运费折扣后的权重取值；没有运行真实确认/计算 API、订单或支付，不能记为 Task 4 验收。
- 待接线的普通前端来源仍保留：`template/uni-app/pages/goods/order_confirm/index.vue`、`pages/users/user_address_list/index.vue`、`pages/users/user_address/index.vue`、`pages/users/user_invoice_form/index.vue`、`pages/goods_details/index.vue`（后四项均在 `template/uni-app/` 下）；确认页及地址/发票往返需要真实店铺和确认 key，直接购买须使用已返回的真实店铺意图。另有 `pages/goods_cate/goods_cate2.vue`、`pages/goods_cate/goods_cate3.vue` 的快捷购物车，及 `pages/goods/order_details/index.vue`、`pages/users/user_invoice_order/index.vue` 的再次购买来源尚未接线。快捷购物车不得直接跨店下单；再次购买须由新建真实购物车根决定店铺，不能猜历史商家归属。现有购物车主页面已改不代表这些普通来源已完成。

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
