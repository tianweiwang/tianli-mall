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

**当前增量迁移合同（安装源及实际普通结构迁移已核，非空历史订单保留仍待核）：** 沿现有 `help/migrations/` CLI，新增 `marketplace-ms4.php` 及同名 `.md`，默认 `check`、显式 `apply-structure`、只读取指定私有 config-file。不默认读取 `.env`，不更新历史归属，不改宽现有有符号列，不新增发货表或独立实物验收表。安装 SQL 的实际订单主键/UID/mer_id 为 UINT；订单项、退款和 pid 等现有引用是 signed INT，新服务须检查写入范围。商品根主键 signed MEDIUMINT，根 stock/sales 为 UNSIGNED MEDIUMINT；SKU 主键 signed INT、stock/sales 为 UINT。安装源本身不是实际库验证证据；本次实际边界见下面普通运行记录。

- `marketplace_order_idempotency`：`uid/mer_id` UINT 必填；`idem_key` VARCHAR(128) ascii_bin；`request_digest/confirmation_ref` BINARY(32)；`source_kind` VARCHAR(16) ascii_bin；`cart_refs` JSON；`state` TINYINT UNSIGNED 默认 0，`order_id` UINT NULL；`post_commit_done` TINYINT UNSIGNED 默认 0，`cancel_at/add_time` UINT 必填。主键 `(uid,idem_key)`、唯一 `(uid,confirmation_ref)` 和 `(order_id)`；扫描索引 `(post_commit_done,order_id)`、`(state,cancel_at,order_id)`。source/refs 持久保存；原确认 key 复用订单 `unique`，读回须核其 SHA256 与 confirmation_ref，不额外冗余 raw key 列。
- `marketplace_stock_movement`：`id` BIGINT UNSIGNED 自增主键；`mer_id/order_id` UINT；`product_id` signed MEDIUMINT；`sku_id` signed INT；`sku_type` TINYINT UNSIGNED；`sku_unique` CHAR(8) ascii_bin；`qty` UINT；`kind` VARCHAR(16) ascii_bin；`idem_key` VARCHAR(128) ascii_bin；`parent_reserve_id` BIGINT UNSIGNED NULL；`order_item_id/refund_id` signed INT NULL；`actor_type` VARCHAR(16) ascii_bin；`actor_id` UINT NULL；`request_digest` BINARY(32)，`add_time` UINT。SKU stock/sales 前后值为 UINT，商品根 stock/sales 前后值为 UNSIGNED MEDIUMINT，全部记录同事务实际值。唯一 idem_key；索引 `(order_id,sku_id)`、`(parent_reserve_id,kind)`、`(refund_id,order_item_id,kind)`、`(product_id,sku_id,id)`。reserve 聚合同 SKU，可覆盖多个订单项，因此 order_item_id 允许 NULL；return_in 必须派生真实退款/订单项及父 reserve，其已提交 PK 就是单 SKU 实物验收号。后台真实任务使用 system/NULL 演员，不伪造顾客或员工编号。
- 原表只兼容增加：订单 `marketplace_trade_version` TINYINT UNSIGNED NULL DEFAULT 0、`merchant_name_snapshot` VARCHAR(128) NULL、`merchant_after_sale_contact_snapshot` VARCHAR(255) NULL，索引 `(mer_id,id)`；订单项 `mer_id` UINT NULL 与 `(mer_id,oid,id)`；退款 `mer_id` UINT NULL、`marketplace_refund_mode` VARCHAR(16) ascii_bin NULL 与 `(mer_id,store_order_id,id)`；评价 `mer_id` UINT NULL 与 `(mer_id,id)`。原订单项 JSON 复用商品与价格快照，增加实际 SKU id/type/unique、原 cart 引用和 reserve 血缘；原订单项 unique 是 md5 行标识，不用作 SKU 身份。新退款模式 money_only/return_goods 在申请时由服务端写入后不改；旧 refund_type 是可变流程状态，不能替代该模式。旧 version0/NULL 和未映射 mer_id 保持历史责任。
- 迁移先按实际元数据核类型/signedness/NULL/default/字符集/排序规则、引擎、主键/索引/触发器及已有 MS1/MS2 结构；GET_LOCK 后重核。只执行明确缺失的 CREATE/ADD COLUMN/ADD KEY，已存在对象须严格匹配，不覆盖不同结构，不 DROP、不猜前缀或自动加 FK。DDL 部分提交时回传真实 applied_steps/pending_steps，重跑只补剩余精确步骤，不能声称事务回滚了 DDL。普通隔离验证沿既有目录新增 `help/audit-regressions/isolated/ms4-migration.php`，责任为 check → apply → check → apply，第二次 changed=false，以及原列/行投影、非目标表及历史数据保持；源码检查不等于实际迁移验收。
- 2026-10-15 迁移源码检查点：上述 CLI、说明和专用普通验证入口三文件已实现，独立源复核通过；Root 修正了 MS1 类的实际命名空间导入，两个 PHP 的语法与 CLI help 在现有 PHP 8.2 镜像中通过。验证入口只允许已登记的迁移/MySQL/Redis 三角色及实际八文件来源绑定，不加载应用、不造演员。新建文件权限另用实际非敏感 H: bind 与 tmpfs 普通文件核过：chmod 成功、0644→0600、真实 UID、完整内容和同 dev/ino，精确自建文件随后删除；既有只读源文件的 777 不能代替新 producer 观察。该源码检查点当时未执行专用库四命令、历史投影或隔离哨兵；后续实际普通结果见下，仍不能记 Task 2 或 G 系列完整通过。
- 普通迁移运行准备沿原 `audit_prepare(false)`、原生资源登记和 MS2 的 157 CREATE-only 提取；使用六个新专用卷及内部网络。一次独立有限 initializer 只安装原表、建立双哨兵、执行原 MS1 apply 和 MS2 apply-structure，其真实前置商家 PK 由数据库生成；不运行旧 __seed、MS2 整套夹具、应用初始化、backfill 或 enforce。每个阶段都有真实 created/source/native 证明；initializer 实际 exit0 后按原 ID 精确移除并重新登记正式 MS4/MySQL/Redis 三角色，才运行当前八文件闭集驱动。最小 compose、initializer 和宿主阶段入口在仓库外 `artifacts/ms4-migration-normal-preparation/` 准备；新 clean HEAD 后重新生成绑定，不沿用旧 MS2 或 dirty HEAD 来证明本次源。代码准备、实际四命令和最终零残留分别记录，不据准备文件签收。

普通结构迁移实测（2026-10-15）：冻结的实际 clean 来源为 `249b049f33d4f7e39a0323aa4376d2dd629ec266`；仓库外 `candidate-r5` 的冻结摘要为 `d0680cd16ddbb4c26ee998591934ac2dd22de100ef7a339da65eadfceb25a83e`。首次 r3 普通 prepare 在启动前因 Docker 短/完整 CID 表示不一致失败；后续只读观察另查明缺失 Tmpfs key 和 Mount 数组顺序表示差异。原源、freeze、失败与两次未完成清理收据均保留。派生清理先核原 freeze、三个原 lookup 摘要、实际从未启动的原 3 CID、6 新卷及空端点内网，再非 force 精确删除；Root 另执行双标签三类的六个原生查询全0。本次修正读取 full CID、可选 Tmpfs 实际 null、完整 Mount 条目多重集合比较；不改 raw fact、真实 tmpfs/只读要求或其他身份字段。

修正后的实际 RUN `20261014221645eb7c11`：private prepare、DB/Redis ready、有限 initializer 均真实正常；initializer 执行原 MS1 check/apply/check 和 MS2 check/apply-structure/check 六子进程，全部 native0/stderr0、临时配置清理通过。重新登记正式三角色后，当前 MS4 驱动执行四个真实子进程 check → apply-structure → check → apply-structure，全部 native0/stderr0；第一次检查 13 pending、第一次 apply 完成 13 steps，重复 check/apply 均 changed=false、无 applied/pending。原 164 表只增加两个新表；七列和四个兼容索引按原合同增加，原列/行投影、非目标完整结构、约束/触发器、SQL mode、Redis 绝对过期及隔离哨兵保持，私有配置清理通过。原订单、订单项、退款和评价四表行数均0，因此这次只记 `VERIFIED_NORMAL_STRUCTURE_ONLY`，不能据空表证明非空历史订单保留、G 全系列或 Task 2 完整验收；未 backfill/enforce、未启动 App/HTTP、未建业务演员/订单/支付，用户已停止的样本仍 NOT RUN。

同 RUN 正常关闭：原 cleanup helper 真正正常退出后，Root 按独立源审通过的 `normal-close-r5.py`（SHA256 `fea9607a5b312db31dbbbee2b083e8289b80b2b8dea9db36c9398645372f2936`）核原四命令与授权收据、fixed created/原身份和全局消费者；MySQL/Redis 分别用同原生进程正常 stop（time=-1，无超时升级），实际 terminal0/PID0，再非 force 精确删除原三容器、空端点内网及六卷。`normal-close-result.json` 实际 native0、双标签三类计数全0；Root 另执行六个 Docker 原生查询也全0（同 RUN `evidence/root-independent-zero.json`）。原业务/历史验收仍未完成，不能将后继 UI 源码整合或本次清理当下单通过。

**Interfaces:**
- Produces：上文严格定义的两个新表与七个兼容列、四个兼容索引；不增加独立发货、验收或历史映射表。库存流水使用实际 `sku_id/sku_type/sku_unique` 与真实父 reserve，`kind` 为 `reserve`、`release`、`return_in`。
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
- 后续创建合同继续沿已盘点设计：真实 request UID/cart/确认引用与持久幂等摘要；merchant→商品→SKU 锁序；reserve/release/return_in 使用真实物理 SKU 及父 reserve。实物入库单 SKU 的已提交 return_in 流水 PK 就是验收号，绑定真实退款行/演员/数量；新订单持久登记收尾责任，复用定时扫描，新流水接入既有 SKU 硬删保护。相关创建/释放服务尚未实现，后续结构迁移结果见 Task 2；不能以此段方案记为业务验收通过。
- 2026-10-15 本地检查：上述纯计算类已实现。隔离 PHP 普通样本实际通过 7 场景、9 次调用、28 条断言，Root 再运行同纯脚本 exit 0；金额/引用源代码复核及 `git diff --check` 通过。未加载应用、SQL 或支付；实际报价/创建接线、数据库订单项与原 Task 4 验收仍待完成。各里程碑复选状态保持原责任。

**当前确认与计算实现合同：** 实际写入口仅为现有 v1 的 `confirm`、`computedOrder`、`create`；v2/PC 没有独立下单控制器，不增造接口。继续使用真实 Request UID、已有顾客与购物车服务、原 opaque 确认 key 和 600 秒 UID 缓存。

- 服务端 `marketplace.enabled` 决定新商城确认合同，`new/is_new` 仅决定持久或临时购物车来源。新确认要求真实 `mer_id` 选店意图，从本人当前购物车、当前商品根及 active SKU 核出唯一实际商家，冻结 `marketplace_confirmation_version=1`、UID、`mer_id`、`source_kind`、规范 cart 引用及 `cart_ref/product_id/sku_id/sku_type/unique/qty` 选择；保留原 `cartInfo/priceGroup/other`，确认不占库存。持久引用按十进制字符串排序，临时引用保留实际字节，CSV 不 trim；读取原始解析参数，不让 `postMore/filter_str` 静默改写 opaque 引用或摘要材料。仅 version1 公众确认的顶层及嵌套 cartInfo.id 转为精确引用字符串，旧 quote/history 投影保持原合同。
- 同一个真实 key 允许更换本人的普通地址和配送，重新核当前店铺、商品、规格、数量和真实报价，不能换冻结商品选择；提供了地址时必须匹配本人有效地址，不把无匹配请求悄悄换成默认地址。缓存预览价格不作为创建权威。原 SKU/会员价格和运费资格保留，新可信合同的计算全程保持 decimal 字符串，原共享方法增加内部参数或分支，旧调用和历史结果保留。原未格式化运费权重保持私有，在最终实际主运费预算确定后接最大余数分币，公众投影继续过滤顶层及嵌套成本与内部权重。
- 精确接线：共享 `getUserProductCartListV1`、`handleCartList`、`setLevelPrice`、`getOrderPriceGroup`、`computedPayPostage`、`computedOrder` 末尾增加默认 false 的内部 `bool $marketplace`，仅真实 version1 服务传 true。会员 BC2 截断、相等选等级价、非零最低 0.01 及优惠额规则保留，不改为四舍五入；金额从真实 decimal 字段及配置的十进制原值取得，不能先 JSON/原生 float 运算再包装字符串。原 BC2 运费商的向上取整用字符串运算，保持原收费公式。固定费及所选模板的格式化前六位贡献放在仅内部的 sidecar，包含原有效会员折扣后的贡献（固定费 BC6、模板 BC4 后补六位）；仅 `0 < express_rule_number < 100` 是有效运费折扣，会员服务返回 false/0 表示该折扣未启用，新路径按与主预算相同的因子 1 保留固定费贡献，不能让正主预算失去全部权重。旧 false 路径保持原行为。最终会员/配送/线下包邮确定 `payPostage` 后分配一次并同步实际 cartInfo，不能以分项反算主预算或重复会员折扣。
- `marketplace.trade.enabled` 默认 false，仅在完整创建/库存/收尾/取消合同实现并验收后开启新创建。当前阶段新创建入口关闭，version1 缓存不能回落旧 `createOrder`；历史 version0/NULL 订单继续已有读取和处理，不按旧 `mer_id` 升级。后续持久幂等先于缓存读取，已提交同请求的重放不重新算价、扣库存或产生权益，原旧 `extend_order` 早返回不能绕过新合同。
- 新交易仅关闭 `['coupon','integral','balance','brokerage','activity']`；新确认跳过自动领券，新 computed 不消费这些权益，兼容缺省零值并保留原响应字段。会员价格、普通地址、发票、custom_form 等不是这五项开关；自提、礼物与存量权益的待定责任继续保留，不借本次实现删除历史数据或永久移除能力。首版普通快递的新路径先保持明确范围，未决定能力不开放新的入口。
- 顾客确认页及地址/发票往返保留实际 `mer_id`、cart 引用/来源及真实确认 key。商品选择未变时换地址/配送只 computed，不同时启动异步 confirm 导致旧/新 key 竞态；换商品才重新确认。后续新 create 补真实 cartId、mer_id、一次逻辑提交的 idem_key，普通重试复用，保留原 `result.orderId` 成功包装。
- Files：按以上合同增补 `crmeb/config/marketplace.php`、`crmeb/app/services/marketplace/order/` 内的最小策略/输入与真实选择服务，修改实际 v1 `StoreOrderController`、`StoreOrderServices` 确认缓存、`StoreOrderCreateServices` 旧入口隔离，以及共享 cart/product/price 的可信 decimal 参数；前端确认、地址与发票消费端随后接线。此段为执行依据，相关实现在未通过真实隔离验证前不标 Task 4 完成，不运行用户已停止的拒绝/冲突/并发/故障等用例。
- 2026-10-15 后端本地实现：可信单店确认、真实选择冻结及重读、UID 缓存版本、原始引用、普通地址验证和 decimal 计算已接到现有服务；公众新增可信店铺/版本元数据，顶层及嵌套引用为精确字符串，成本及内部权重不公开。新创建在控制器和旧服务入口保持关闭；该后端源码检查点当时未实现持久幂等或库存创建事务，后续结构迁移结果见 Task 2。Root 最终复核 13 个 PHP 文件（含普通纯计算脚本），在已有 PHP 8.2 镜像、无网络、只读源文件及根文件系统下 `-l` 全部 exit 0；12 条仅真实 decimal 原语的普通数学断言通过，聚焦差分检查通过。独立源复核已修正有效会员运费折扣后的权重取值；没有运行真实确认/计算 API、订单或支付，不能记为 Task 4 验收。
- 待接线的普通前端来源仍保留：`template/uni-app/pages/goods/order_confirm/index.vue`、`pages/users/user_address_list/index.vue`、`pages/users/user_address/index.vue`、`pages/users/user_invoice_form/index.vue`、`pages/goods_details/index.vue`（后四项均在 `template/uni-app/` 下）；确认页及地址/发票往返需要真实店铺和确认 key，直接购买须使用已返回的真实店铺意图。另有 `pages/goods_cate/goods_cate2.vue`、`pages/goods_cate/goods_cate3.vue` 的快捷购物车，及 `pages/goods/order_details/index.vue`、`pages/users/user_invoice_order/index.vue` 的再次购买来源尚未接线。快捷购物车不得直接跨店下单；再次购买须由新建真实购物车根决定店铺，不能猜历史商家归属。现有购物车主页面已改不代表这些普通来源已完成。
- 当前普通前端接线设计：先改上述五页，连同 `template/uni-app/components/addressWindow/index.vue` 与 `template/uni-app/pages/goods/components/invoicePicker/index.vue` 共七个既有文件。仅服务端返回的 version1 进入新 UI 分支；直接购买传实际临时引用和当前公众店铺的真实 `mer_id`。地址/发票组件把新分支的管理页导航交给确认父页，由父页沿现有 `uni.navigateTo` 的 eventChannel 接回选中数据；地址列表到编辑页明确转发同一结算上下文。子页仅凭同一实际 key/mer 上下文回传真实保存返回的地址/发票 ID，并正常返回原确认页实例；不另建前端缓存、不造 key/UID，也不靠 URL 里的 key 重建空确认页。原页保留 custom_form、备注和发票，商品未变时仅 computed。新券/积分/余额/营销入口关闭；服务端 `marketplace_create_enabled=false` 时，独立状态说明与禁用的真实提交控件分开，提交/支付方法也守住该状态。旧合同、礼物和历史开票仍保留原导航。硬刷新或原父页已关闭时，恢复完整 UI 的责任单独保留，不能拿只有价格结果的 computed 响应假装完整确认 DTO。按现有视觉样式接线，编译和源码检查不等于普通页面实际验收。
- 局部视觉方向：沿用当前确认页字体、白色背景、深灰正文及原主题动作色，不新增字体/图标/组件。真实店铺名称与“当前可确认金额和收货信息，暂未开放提交订单”作为左对齐的独立静态段落，使用辅助说明层级，不赋予按钮语义。新提交控件使用真实 disabled button，沿现 footer 的 240×70rpx 外框，border-box、清除原生额外内边距并将完整文字组在双轴居中；旧分支控件保持原样。实际运行仍须核父级内边界、移动尺寸及禁用状态，不以编译或源码检查记视觉验收。
- 2026-10-15 七页源码检查点：上述五页与地址/发票两个组件已接线；确认后的来源只取服务端 persistent/temporary，后续计算须保留 version1 元数据及规范 decimal 金额；旧地址响应不能覆盖当前已选地址。真实 key/mer 的 URL 仅标记等待原父页接管，完整上下文必须来自 eventChannel；缺少通道时显示未恢复说明，不用 URL 造 DTO 或退回丢失结算信息的旧导航。回退只针对实际路由、原父页对象及 key/mer 均匹配的本次页面。新提交控件仍禁用。七文件均 LF，官方 H5/MP-Weixin/App-plus 预处理与 Vue 2.6.14 模板检查 errors/tips 均 0、42 次前后 Node 22 语法检查及 Sass 通过；两轮完整字节逆变换、来源指纹和独立通用范围源复核通过。未运行真实 API、页面往返、DOM/移动布局或 600 秒生命周期；硬刷新完整确认恢复、快捷购物车与再次购买仍保留未完成，不能记 Task 4 或 MS4 完成。

**创建输入与幂等摘要实现合同：** 先在既有 `MarketplaceOrderInput` 增补纯 `create(int $uid, string $routeKey, array $rawPost)` 与 `canonical(array $request)`，不接通控制器或开放创建。后续适配器传真实 Request UID、独立实际路由 key 和 `post(false)` 原解析 POST；不使用会合并 query/route 的 `param(false)`，不从 body 覆盖路由 key。JSON 已在框架解析，BIGINT/opaque 引用必须为字符串，不能恢复已转 float 的原字节。

Files：`crmeb/app/services/marketplace/order/MarketplaceOrderInput.php`；普通纯编码验证 `help/audit-regressions/isolated/ms4-order-input.php`。后者只核整数/引用精度、正常缺省、表单及稳定字节，不建立认证演员、不加载应用/SQL/缓存/支付，不作为业务身份或下单证据。

- 明确输入闭集：旧 create 实际 19 项（addressId/couponId/payType/useIntegral/mark/combinationId/pinkId/seckill_id/bargainId/shipping_type/real_name/phone/store_id/new/invoice_id/advanceId/custom_form/is_gift/gift_mark），新 cartId/mer_id/idem_key，及已存在确认合同的 is_new 别名。formId/discountId/from/quitUrl 为既有未消费元数据，剔除且不参与摘要、不访问 URL；其余键拒绝。new/is_new 沿现确认的布尔 OR 归一来源，不决定交易版本。引用沿现有规范、原字节不 trim；持久引用去重并按十进制顺序排序，临时引用去重并按字节排序。
- 返回独立 idem_key 与 request；request 固定包含 contract_version=1、真实 uid、规范 mer_id/source_kind/cart_refs/address_id、原路由 confirm_key 及规范 options。idem_key 大小写敏感，1–128 字节可打印 ASCII、首尾无空格，原样保存且不进入摘要。原确认 key 校验 UTF-8、非空、无 NUL 及原 CHAR(32) 容量，不转大小写。canonical 只按字节排序对象键，保留列表顺序、文本原字节、bool/null/int；拒 float、对象或资源，以 JSON_UNESCAPED_UNICODE/SLASHES 与 THROW_ON_ERROR 编码。服务另用其原字节生成 BINARY(32) SHA256 请求摘要，confirmation_ref 对实际 key 单独生成。
- 规范 options 保存所有已消费字段，缺省 payType='' 并沿旧规则小写；invoice_id/bargainId 的空串明确归 0，布尔归 bool，其余整数核实际列容量。地址最大 16777215，invoice_id/store_id 最大 2147483647，uid/mer_id 及活动引用为 UINT；文本核 UTF-8、无 NUL 与原列字符容量（mark512/real_name32/phone18/gift_mark255/payType32），不做 HTML 转义或 Unicode 归一。普通快递页面可能发送非空联系人和 gift_mark；纯 helper 不把这些字段当启用自提/礼物。真正收货快照仍从本人的有效地址取得，mark 写入时另核 HTML 转义后的列容量。
- custom_form 只核最多十行的闭集 title/label/value/status，status 为 bool；label 为 text/number/email/data/time/id/phone/img，日期名称为 data；普通值为 UTF-8 string，图片值为 UTF-8 string[]。顺序及内容进入摘要，不拿客户端 title/status 当定义授权。首次创建另绑定真实商品定义与必填条件；多商品表单关联、实际库存事务、已提交重放、收尾及创建接线仍待设计/实现。纯 helper 不访问配置/缓存/SQL/身份/支付，不能代表真实下单验收；普通纯输入检查不运行用户已停止的拒绝、冲突或故障样本。
- 2026-10-15 纯输入检查点：上述两个纯方法与普通脚本已实现，原 confirm/computed 等方法的内容未改；独立代码后源复核有限 SOURCE PASS。Root 在既有 PHP 8.2 镜像、network none、只读源和根文件系统下，两 PHP `-l` 均 exit0，十九条普通编码断言 exit0：包含 UINT64 字符串精度、正常空发票/联系人、对象键与引用归一、列表顺序、Unicode/原文本、二进制摘要长度及临时来源。样例编号只验证编码，不建立认证演员。实际控制器、持久幂等、写单/库存事务、商品表单定义校验和写入容量仍未接线；创建 gate 保持关闭，不记 Task 4、O03 或真实下单通过。

**创建持久化执行设计（下一步，尚未实现）：** 新增单一 `crmeb/app/dao/marketplace/order/MarketplaceOrderDao.php`，由服务独占一个真实 `Db::transaction` 并传同一 PDO；每次读写核该 PDO 在事务中且就是默认连接当前 PDO。仅使用已校验的实际表前缀和固定表/列；新 DAO 用原生 PDO，金额、BIGINT 引用及 BINARY 摘要明确按字符串/二进制绑定，避免当前 ORM 按 schema 将 decimal 绑定为 float、BIGINT 绑定为 PARAM_INT。原生 INSERT 后立即取同 PDO 的 lastInsertId、核真实列范围并读回；movement/cart 引用保持 UINT64 十进制 string，不用 Number、float 或无条件 int。

- DAO 六个内部边界：`claim`（真实 UID/idem/摘要/confirmation_ref 的持久占位和已提交重放）、`lockInputs`（当前商家、真实商品/物理 SKU、本人地址/发票及持久 cart）、`insertOrder`（原订单与原发票快照）、`reserve`（实际库存与 reserve）、`insertItems`（原订单项及实际 reserve 血缘）、`markCreated`（本事务登记已提交订单和收尾责任）。不伪造 MerchantContext；控制器、真实价格服务、收尾/取消、公开详情和开票接线仍须后续实现，gate 不开放。
- 持久重放先于确认缓存：只返回真实同 UID、mer、request_digest、版本1订单且其原 unique 的 SHA256 匹配 confirmation_ref；重放不重算价、扣库存或发权益。首次占位 state0 与新订单、库存、订单项、invoice、state1/order_id/post_commit_done=0/cancel_at 同事务提交，不能单独提交占位。旧 version0/NULL 不当作新订单。
- 固定锁序为商家 → 商品根升序 → 物理 SKU 升序 → 运费模板及子行升序 → 本人地址/发票/持久 cart。商家 review_status=1/accept_orders=1/security_locked=0/closed_at=NULL；商品 review_status=2/is_show=1/is_del=0，active SKU 必须实际同 product/type=0/unique。运费沿锁定商品中的模板归属，锁同商家真实 template 和 region/free/no_delivery 子行，不能把未加锁的公众 lookup 或员工授权上下文作为顾客下单授权。
- UID 和新 order PK 受现有 item/invoice/pay_uid 的 signed INT 共同上限 2147483647 约束；商品 PK 最大8388607，SKU PK 最大2147483647；root stock/sales 最大16777215，SKU stock/sales 最大4294967295。金额按非负 DECIMAL(12,2) 最大9999999999.99 核，地址快照100字符、HTML 转义后 mark512字符，cart_info/custom_form UTF-8 JSON 各最多65535字节。纯输入 UINT32 校验和 Decimal 格式化不替代写入范围。
- 同一真实 SKU 的多个 cart 项聚合一条 reserve（order_item_id=NULL）；SKU UPDATE 同时带实际 id/product_id/type/unique 和前值/数量条件；商品根 UPDATE 使用实际 id/mer_id、stock/sales 前值及数量/容量条件，两者实际影响行数均必须1。逐 SKU 更新根与 SKU stock/sales，并记录本次实际前后值；不能只按 unique 更新、先统一改根后编造中间值。每个订单项保存其实际 cart 引用/SKU/reserve，内部成本取真实私有报价，公众报价不能当成本来源。
- 原发票表可复用，选中抬头应在同事务锁本人有效记录并复制原十项字段；旧事务外 makeUp 会派发任务且不具备此幂等合同，不能直接当创建步骤。custom_form 仍须绑定锁定商品的真实定义和必填条件，多商品定义关联待补；不得用客户端 title/status 当授权。V1 行运费是整行金额，旧开票将其当单价的地方须适配；原 orderCreateAfter 会重写整份 cart_info，新收尾须持久登记并自行保留血缘。V1 订单详情及 refund_cartInfo 须按保存快照脱敏成本/UID/内部选择/流水，不调用会重读当前商品的目录投影。这些后续消费者完成前不开放创建。

**Interfaces:**
- Produces（待实现）：`MarketplaceOrderCreateServices::create(int $uid, string $routeKey, array $rawPost): array` 消费真实 Request 和上述纯输入，完成持久幂等 → 锁定与真实报价 → 条件库存/原订单/订单项/发票/reserve 原子提交 → 持久收尾，保持原 `result.orderId` 成功包装。相关取消、库存硬删保护、定时收尾、拆单、售后和支付版本适配仍保留原任务。
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
