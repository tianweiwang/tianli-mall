# MS4–MS6：交易、支付、发布 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。三个里程碑依次开工，各自验收。每个里程碑开工时，先完成其 Task 1 的盘点并在停止点回报，再按回报确认后的范围执行；本文件给出任务划分、接口和验收用例，具体文件路径以盘点结果为准，并在报告中列出。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §1.2、§6.3、§7.2、§8、§9、§10、§11；用例 O、P、R 系列，D19，K02–K05。

## Global Constraints

- 目标为通用多商户商城，商品、规格、价格和公共计算沿用现有通用能力；不新增天俪专属预约、检测、区域代理、定制会员或补贴业务。旧仓库名称和平台品牌配置不作为交易需求。
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
- custom_form 现采用后文V1按商品分组答案合同：product_id/definition_sha256/values（field_index/value），每商品最多10字段，客户端不提交title/label/status。商品与字段按规范顺序进入摘要；普通值为UTF-8 string，图片值为UTF-8 string[]。首次创建必须调用锁内实际商品定义匹配和必填校验；生产创建/保存接线仍待完成。纯helper不访问配置/缓存/SQL/身份/支付，不能代表真实下单验收；普通纯输入检查不运行用户已停止的拒绝、冲突或故障样本。
- 2026-10-15 纯输入检查点：上述两个纯方法与普通脚本已实现，原 confirm/computed 等方法的内容未改；独立代码后源复核有限 SOURCE PASS。Root 在既有 PHP 8.2 镜像、network none、只读源和根文件系统下，两 PHP `-l` 均 exit0，十九条普通编码断言 exit0：包含 UINT64 字符串精度、正常空发票/联系人、对象键与引用归一、列表顺序、Unicode/原文本、二进制摘要长度及临时来源。样例编号只验证编码，不建立认证演员。实际控制器、持久幂等、写单/库存事务、商品表单定义校验和写入容量仍未接线；创建 gate 保持关闭，不记 Task 4、O03 或真实下单通过。

**创建持久化执行合同（DAO 普通持久 cart SQL 已核，完整生产服务/API 待验证）：** 新增单一 `crmeb/app/dao/marketplace/order/MarketplaceOrderDao.php`，由服务独占一个真实 `Db::transaction` 并传同一 PDO；每次读写核该 PDO 在事务中且就是默认连接当前 PDO。仅使用已校验的实际表前缀和固定表/列；新 DAO 用原生 PDO，金额、BIGINT 引用及 BINARY 摘要明确按字符串/二进制绑定，避免当前 ORM 按 schema 将 decimal 绑定为 float、BIGINT 绑定为 PARAM_INT。原生 INSERT 后立即取同 PDO 的 lastInsertId、核真实列范围并读回；movement/cart 引用保持 UINT64 十进制 string，不用 Number、float 或无条件 int。

- DAO 六个内部边界：`claim`（真实 UID/idem/摘要/confirmation_ref 的持久占位和已提交重放）、`lockInputs`（当前商家、真实商品/物理 SKU、本人地址/发票及持久 cart）、`insertOrder`（原订单与原发票快照）、`reserve`（实际库存与 reserve）、`insertItems`（原订单项及实际 reserve 血缘）、`markCreated`（本事务登记已提交订单和收尾责任）。不伪造 MerchantContext；控制器、真实价格服务、收尾/取消、公开详情和开票接线仍须后续实现，gate 不开放。
- 持久重放先于确认缓存：只返回真实同 UID、mer、request_digest、版本1订单且其原 unique 的 SHA256 匹配 confirmation_ref；重放不重算价、扣库存或发权益。同 UID/confirmation_ref 使用不同 idem_key 而规范 request_digest 完全相同时，正常复用原已提交订单及原持久 idem，不新增 alias 表；不同请求返回409，已存在但未完成的 state0 不能冒称已提交。首次占位 state0 与新订单、库存、订单项、invoice、state1/order_id/post_commit_done=0/cancel_at 同事务提交，不能单独提交占位。旧 version0/NULL 不当作新订单。
- 固定锁序为商家 → 商品根升序 → 物理 SKU 升序 → 运费模板及子行升序 → 本人地址/发票/持久 cart。商家 review_status=1/accept_orders=1/security_locked=0/closed_at=NULL；商品 review_status=2/is_show=1/is_del=0，active SKU 必须实际同 product/type=0/unique。运费沿锁定商品中的模板归属，锁同商家真实 template 和 region/free/no_delivery 子行，不能把未加锁的公众 lookup 或员工授权上下文作为顾客下单授权。
- UID 和新 order PK 受现有 item/invoice/pay_uid 的 signed INT 共同上限 2147483647 约束；商品 PK 最大8388607，SKU PK 最大2147483647；root stock/sales 最大16777215，SKU stock/sales 最大4294967295。金额按非负 DECIMAL(12,2) 最大9999999999.99 核，地址快照100字符、HTML 转义后 mark512字符，cart_info/custom_form UTF-8 JSON 各最多65535字节。普通页面也发送 gift_mark，沿旧创建语义把纯输入中已规范的原文本写入原 VARCHAR(255)，不截断或额外转义；is_gift 仍固定0，不开放礼物流程。纯输入 UINT32 校验和 Decimal 格式化不替代写入范围。
- 同一真实 SKU 的多个 cart 项聚合一条 reserve（order_item_id=NULL）；SKU UPDATE 同时带实际 id/product_id/type/unique 和前值/数量条件；商品根 UPDATE 使用实际 id/mer_id、stock/sales 前值及数量/容量条件，两者实际影响行数均必须1。逐 SKU 更新根与 SKU stock/sales，并记录本次实际前后值；不能只按 unique 更新、先统一改根后编造中间值。每个订单项保存其实际 cart 引用/SKU/reserve，内部成本取真实私有报价，公众报价不能当成本来源。 V1 最终运费分配生产端同时明确写 postage_price 与 origin_postage_price，两者在创建时都是实际收取的整行运费；origin 是该行首次收费快照（最终主预算为零时亦为0.00），不是内部权重或会员折扣前费用。后续 V1 退款/拆单沿此实际收费与整行单位处理，不能沿旧单价公式扩大金额；旧 version0/NULL 数据与计算不改，DAO不把缺失字段猜成零或邮费。
- 原发票表可复用，选中抬头应在同事务锁本人有效记录并复制原十项字段；旧事务外 makeUp 会派发任务且不具备此幂等合同，不能直接当创建步骤。custom_form 的分组协作者和确认绑定见后文；生产创建仍须调用其snapshot绑定锁定商品真实定义和必填条件，不得用客户端title/status当授权。V1 行运费是整行金额，旧开票将其当单价的地方须适配；原 orderCreateAfter 会重写整份 cart_info，新收尾须持久登记并自行保留血缘。V1 订单详情及 refund_cartInfo 须按保存快照脱敏成本/UID/内部选择/流水，不调用会重读当前商品的目录投影。这些后续消费者完成前不开放创建。

2026-10-15 DAO 源码检查点：六个方法已实现；持久 cart 沿已有查询兼容真实 status=NULL 或1，其他本人、商品、规格、数量与未消费状态约束保持。独立源复核通过，当前 DAO 在既有 PHP 8.2 镜像中语法 exit0。真实 vendor/Think App/默认 Db 配置和 File cache 下的有限构造探针已通过；首次缺少 cache 配置的实际失败保留。探针未 initialize 应用、建立 PDO、执行 SQL 或认证，不能记实际创建成功。V1 计算生产端已明确生成两项整行运费快照，独立源复核和原普通纯分摊脚本 28 条断言通过；旧版本计算保持原样。

下一步普通 SQL 验证新增有限入口 `help/audit-regressions/isolated/ms4-order-normal.php`：在新专用实例实际完成原有限初始化和 MS4 结构迁移后，以真实默认 `Db::transaction` 的 PDO 调用 DAO。原 `audit_db()` 仅做事务外只读隔离保护和提交后独立查询，不充当业务 PDO；保护使用已确认的 read-only 模式，不运行已停止的拒绝、冲突、并发、故障或恢复样本。夹具的用户、商品、SKU、购物车、地址与发票均由专用库原生自增并读回，真实确认服务 capture/frame 和共享重算提供内部价格，不手工拼期望价或伪造认证。先核普通固定运费、两个 SKU/三行购物车的聚合 reserve、真实库存前后值、订单/发票/项与血缘、金额和两运费快照，再正常重放同请求并核订单及库存不重复变化。该入口须独立记录本次实际角色和源码来源，不能借旧迁移八文件证明；结果只代表内部 DAO 数据事务，HTTP 认证、创建 API、模板运费、非空表单、取消、售后与支付各自保留待验证。

该验证初始化使用真实 AppService 注册/boot、ModelService boot 和原 common，File cache/log/runtime 只写进程自己的 tmpfs，不触发完整 App 初始化或 HTTP 身份。测试配置的 self_operated_mer_id 只取本次原初始化收据中的实际 MS2 自增结果，核同来源子进程及真实商家行，不把新夹具商家擅当已确认部署自营身份。宿主沿 r5 在仓库外派生有限准备：原四次迁移成功并保留其正式 manifest 后，按原 created CID 正常移除迁移角色，重新创建/登记 audit-ms4-order-normal、MySQL、Redis 三角色；首次实际 DB/Redis、六卷与内部网络身份继续固定。业务角色只挂只读 audit_app 私有配置，不读取 migrator。新来源绑定冻结完整 app/crmeb/vendor/config 常规文件根及入口/harness/guard/composer 文件，核真实已加载文件处于闭集；新的 created/source/观察和普通清理证据各自记录。准备与语法检查尚不代表实际 SQL 通过。

持久 cart 的必要调用点补齐：实际 StoreCartDao 的 id/IN 查询仍按 schema 将 BIGINT 绑定为 PARAM_INT，仅在请求编码和新 DAO 保留字符串不能保证共享确认/修改路径精度。Files 补 `crmeb/app/model/order/StoreCart.php`、`crmeb/app/dao/order/StoreCartDao.php`、`crmeb/app/services/order/StoreCartServices.php`：Model 查询工厂沿 parent 的真实默认连接与 scope，先取得完整字段映射，再仅将 id 设为 string；不能用部分 schema 覆盖全部列。普通库存调整、修改规格和 resetCart 使用已有纯 reference(..., persistent) 核完整 UINT64 字符串，去掉 cart PK 的 int 转换及下游 typed-int 边界，UID/商品/数量的各自整数范围保持。该映射只改 SQL 绑定，不强制改旧小 ID 的 Model JSON 类型。普通 DAO 验证额外核实际 cart Query 的完整映射和 id 的 PARAM_STR；不造大编号演员，不以普通小主键实测声称 UINT64 极值 SQL 或全部购物车 API 已验收。

2026-10-15 普通入口源码检查点：上述三 cart 必要调用点和普通 CLI 已实现并经独立有限 SOURCE PASS，四 PHP 在既有 PHP 8.2 镜像下语法 exit0。当时入口 SHA256 为 `4366884cfc8399da229fb6ed3ffbc7e263e42f6572f4a31edf468fc4a6df609b`；独立读回还核保存项的真实 SKU 单价/私有成本/整行金额、零权益佣金及累计商品额/成本/运费到订单。宿主派生 v3 的完整逆变换恢复 r5、原 seed 函数不变，YAML/Python AST/initializer PHP 语法与独立 preparation/close 消费字段复核通过。新完整业务树仅在新业务登记/授权阶段及入口自身前后核对，原 initializer 不为每个 CREATE 重复扫描无关业务树。该检查点当时未执行新实例、SQL 或 HTTP，不记 Task 4/O03 通过。

2026-10-15 首次普通 DAO 实测：clean 来源 `fce1ba1af890e3512507c2b9a4e133507fe4c749`、RUN `20261014233233fd0f6e` 的真实有限 initializer 六子命令和 MS4 四结构子命令全部 native0/stderr0。业务入口实际退出1；原收据为 `FAIL/NORMAL_DAO_ONLY`，phase=`app_services`，Error 指向 `config/app.php:53`，尚未进入业务夹具或写单事务。实际原因是 Composer autoload 未加载 framework 的全局 `app()`，该原配置确实调用它；正常框架在 App::load 中加载 `vendor/topthink/framework/src/helper.php`。本次保留原 FAIL 和全部来源收据，原 metadata cleanup helper 实际正常0后精确关闭原3容器、内网和6卷；关闭结果及 Root 另执行的双标签三类六个 Docker 原生查询均0，失败业务收据哈希保持。没有将失败当通过、修改业务断言或启动原容器重试。

修复普通入口的组件引导：真实 Think App 构造后显式 require 原 framework helper，核全局 app() 就是该真实实例且 App 未 initialize；继续原 AppService/ModelService/common、私有 DML 账户和 tmpfs File cache/log/runtime。单独真实组件引导在现有 PHP 8.2 镜像、只读源、无网络下实际 native0；没有 PDO/SQL/HTTP/认证，实际 DAO 写单须另建新 Run。补齐普通 setCart 已有行合并路径残留的 cart PK int 转换，使用同一 persistent reference 字符串规则；不据小主键样本声称极值或全部购物车 API 通过。

2026-10-15 二次普通 DAO 实测：clean 来源 `f285954bb70a44b58e3dd0ce71ba873c5eb686c8`、RUN `202610150007134dfa97` 的六初始化和四结构迁移命令全部 native0/stderr0、私有配置清理通过；真实 framework helper 引导已通过（app_services四条断言）。业务入口仍实际退出1，phase=`actual_fixtures`，首个 `ACTUAL_DEFAULT_TRANSACTION_PDO` 断言失败，尚未进入业务夹具 INSERT、确认或写单。原 FAIL、来源及原生终态保持；原 metadata cleanup helper 实际正常0，原3容器/内网/6卷正常精确关闭，Root 独立六个原生查询全0并核原业务收据哈希不变。

镜像只读核实实际 PHP 为8.2.33、pdo_mysql已加载。[该版本官方 MySQL getter](https://github.com/php/php-src/blob/php-8.2.33/ext/pdo_mysql/mysql_driver.c#L509-L512) 使用 ZVAL_LONG 返回模拟预处理标志，关闭值为整数0，因此入口原先的 `=== false` 与其返回表示不兼容。修复仅严格接受 false 或整数0；真实事务 callback 的 Connection 必须与默认 Db::connect() 同一实例，返回 PDO 也须同一对象、仍在事务中、driver=mysql、ERRMODE_EXCEPTION，金额/库存/重放及原 DAO 条件保持。每个阶段在断言前记录最后一次只含布尔值与类型的连接观察，不输出配置、账户、DSN、PDO对象或驱动错误参数。原 f285 入口没有保存该属性现场值，其他现场连接条件仍须新 Run 重新核；本次源修复不计实际 DAO 写单或完整下单通过。

2026-10-15 三次普通 DAO 实测：clean 来源 `9e0177605153b6c1a9503d8d58a12626ca185060`、RUN `20261015003409e15efd` 的真实连接观察确认事务 callback、默认 Connection 和 PDO 均为同一对象，仍在 MySQL 事务内，异常模式开启、模拟预处理关闭值为整数0。真实夹具、确认缓存及锁内重算已执行；业务容器自然 native1，phase=`atomic_dao_creation`，`ACTUAL_LOCKED_QUOTE_ADDRESS` 失败。实际地址 Model 隐藏 `add_time/is_del`，原入口却将其原始锁行字段全部与 Model 的公开数组比较；尚无 committed/replay 证据，不能记订单写入通过。原 FAIL 和来源证据保留，不重启原容器。

同 RUN 关闭实测：原 metadata cleanup helper 正常 native0/PID0 后按原 ID 移除；独立源审通过的 case3 派生关闭脚本固定原来源与实际失败，正常停止原 MySQL/Redis（同原生进程 native0、容器 native0/PID0），再非 force 精确移除原3容器、空内网和6卷。关闭脚本 native0 后，Root 另执行双标签三类六个 Docker 原生查询，exit0/stderr空且计数全0；`root-independent-zero.json` 还核原 FAIL 收据哈希保持。此次清理和后续源码修复均不改变失败的业务结果。

2026-10-15 四次普通 DAO 实测通过：clean 来源 `418aa1c103c786dce2bb54c84d7cd4674cf410ad`、RUN `20261015010448c518e9`，原有限初始化六子命令及 MS4 四结构命令全部正常；普通业务容器 native0/PID0，收据为 `VERIFIED_NORMAL_DAO_ONLY`。真实夹具、确认和共享锁内重算后，一个订单及原发票、3订单项、2聚合reserve实际提交；独立默认外连接核库存实际前后值、数量、金额、整行运费和血缘。选中3条persistent cart已删除，唯一未选行全部原列值保持；同请求正常重放返回原单，订单/项/库存/流水/cart事实不再变化。事务callback/defaultConnection/PDO同一对象，MySQL原生预处理关闭，完整13列公开地址比较missing/extra/changed均空。前后源码闭集和隔离保护通过，下单gate仍false。该Run不含本轮随后新接的CreateServices/LockedQuote/保存投影源码；不证明HTTP、临时引用消费、非空表单落库、模板运费、取消、售后或支付。

同418 Run正常关闭：原metadata helper实际native0/PID0后按原ID移除；未改的 `normal-close-order-v2.py`（SHA256 `a97d76b7cebc5b86610e0924b816d8be0ffd889d726d12f87d14e51415adc285`）核PASS收据和原身份，正常停止原MySQL/Redis，容器及同一原生stop进程均0，再非force移除原3容器、空内网和6卷。关闭native0后，Root独立验证真实attempt/before/close与本轮Run/source/CID及关闭源hash，核原业务收据字节hash不变，另执行六个Docker原生查询均exit0/stderr空/计数0，产出本Run `evidence/root-independent-zero.json`。清理不扩大普通DAO通过的业务范围。

**锁内地址报价合同：** 原 UserAddress Model 的 hidden 仅屏蔽 add_time、is_del；共享 computedOrder 使用其 toArray。DAO 固定读列与实际安装表的15列一致；原始锁行继续核本人 uid、地址 id 和有效状态，实际报价地址须具有原行扣除上述两个隐藏字段后的完整13列键集并逐值一致，包括 city_id、post_code、longitude、latitude、is_default。诊断只记录缺失、多出或变化的字段名，不记录地址值。新订单仍从原始锁行保存地址快照，不更改 Model 隐藏规则或公开 DTO。两 PHP 语法与独立窄增量源审通过，普通418 Run已核该地址合同；完整服务仍须独立验证。

**创建/消费执行合同（普通持久 cart 已实测，临时消费与完整服务待验证）：** 所有 V1 创建和 ledger/ref 消费入口统一在同默认 PDO 事务最先锁真实 user.uid，再 claim/正常 replay。新 claim 在 merchant 锁之前按 UID 主键前缀完整分页读取已提交 temporary ledger，使用已存 source_kind/cart_refs 对原字节引用做严格消费检查；不按商家、待支付、取消时点或缓存过期缩小历史，不额外新增引用表或伪造锁用演员。后续锁序为 merchant→root→SKU→运费父子→本人地址/发票/cart；真实 order/reserve/items 核实后、state1 更新前，对 persistent refs 做本人UID、精确PK、商品/SKU unique、数量、普通类型、未消费状态的逐行条件物理 DELETE（真实 status=NULL 或1仍兼容），每行影响1并读回所选 refs不存在。temporary 没有对应 SQL cart，state1 的现有 ledger 就是持久消费事实，取消与提交后缓存清理不能抹去它。生产服务和未来内部调用必须消费同一协议，不能只在验证 CLI 内加锁。普通418 Run已核真实persistent创建/仅所选行删除/未选行保持/同请求重放；真实setCart(new=true)临时引用创建/ledger留存/无SQL cart变化/正常重放仍待独立运行，用户已停止的拒绝、冲突、并发、故障样本继续不运行。

**Interfaces:**
- Produces（创建源码已接，完整调用与持久收尾待验）：`MarketplaceOrderCreateServices::create(int $uid, string $routeKey, array $rawPost): array` 消费真实 Request 和上述纯输入，完成持久幂等 → 锁定与真实报价 → 条件库存/原订单/订单项/发票/reserve 原子提交 → 持久收尾，保持原 `result.orderId` 成功包装。相关取消、库存硬删保护、定时收尾、拆单、售后和支付版本适配仍保留原任务。
- 用例：O02（混合商家、商品与规格错配 → 拒绝，库存与订单表不变）、O03（同一幂等键重复提交返回同一订单；同一键换商品或商家 → 409）、O04（两个并发请求抢最后一件，恰好一个成功）、O05（下单后改价、改店铺名，订单快照不变）、O07（使用他人地址 → 拒绝）、R05 的下单子场景（带优惠券、积分、余额参数 → 拒绝）。

**V1 商品分组表单执行合同（2026-10-15，独立 forms 工作树）：** 复用现有商品 `custom_form` 定义与原订单 TEXT 列，不做 DDL；旧 version0 平面表单保持。每个真实选中商品最多10字段，同一商品多SKU/cart只有一个组，组按商品PK升序，字段按定义序号；引用数量沿现有MerchantFreightPolicy::revalidate普通合同最多500行，组数不超过真实选中商品数；重名标题不是身份键。确认DTO `custom_form` 为 `{product_id,product_name,cart_refs,definition_sha256,fields:[{field_index,title,label,status,value}]}` 组列表，无字段商品不产生空UI组。仅服务端当前商品定义提供标题/类型/必填，保留原 `data` 类型及 text/number/email/data/time/id/phone/img；img初始空值统一为数组。哈希绑定真实商品PK与完整原定义，冻结到真实确认frame/cache；重读当前定义必须相同，创建还须比较锁内实际root定义，不能以客户端title/status为授权。

V1 create 输入 `custom_form` 改为 `{product_id,definition_sha256,values:[{field_index,value}]}`，规范商品与字段顺序后进入请求摘要；只传答案，不传标题/类型/必填，每商品<=10字段，图片最多8项。最终保存快照由服务端定义与匹配答案组成，包含商品与cart归属；fields.value保存答案，definition_sha256仍绑定原定义，因此历史详情绝不能用configuration重新计算已填写值的定义hash，必须另做version-aware保存快照投影（本次pending）；UTF-8 JSON编码后<=65535字节，不截断。status表示必填：text/data/time/id要求trim后非空，number为正十进制文本，email/phone沿原页面正则，img至少一项；optional仍只核类型/容量，不额外强制格式。换地址/发票只重算报价，保留原定义和当前答案，不重新初始化字段；重确认改变商品时重新绑定。旧客户端未接分组DTO前及生产create未调用锁内bind前都不视为完成，gate继续关闭。

Files：新增 `crmeb/app/services/marketplace/order/MarketplaceOrderCustomForm.php`、`help/audit-regressions/isolated/ms4-custom-form.php`；修改 `MarketplaceOrderInput.php`、`MarketplaceCartSelection.php`、`StoreOrderServices.php`、`PublicCatalogServices.php` 与普通输入脚本 `ms4-order-input.php` 为必要调用点。任务/验收：先写普通纯案例（单/多商品、重复title、同商品多SKU、正常非空必填和optional、地址/发票报价往返保留答案、稳定摘要及最终JSON容量）→实现纯协作者和真实确认/capture/cache/quote输出→真实vendor下PHP82 network-none只读语法/纯脚本→独立源审。纯数据编号不建立UID或认证演员；不启动应用/SQL/HTTP或已停止的拒绝、冲突、并发、故障、恢复样本。顾客UI、商家定义编辑、生产create/公开历史详情接线和真实非空表单数据库验收保留pending。

2026-10-15 forms 本地源码检查点：分组定义/答案/锁内snapshot纯协作者及Input、真实capture/frame/recheck、确认cache和公众quote输出已实现；旧version0仍走原flat分支。真实创建服务必须以 `snapshot(frame.custom_form, request.options.custom_form, locked.products, locked.selection, locked.source_kind)` 生成保存值；该生产调用与顾客分组UI、旧详情版本适配尚未接线。V1新frame要求冻结组定义，旧未含该字段的V1短期确认缓存须重新确认，不能当flat定义接续。已有PHP8.2镜像 `sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a`，network none、只读源/根、cap-drop ALL/no-new-privileges及独立/tmp tmpfs：七PHP语法exit0，真实vendor普通表单脚本27断言与普通输入脚本19断言均exit0，git diff --check通过。普通样本包含单/多商品、同商品多SKU、重复title、每商品10字段、原八label、optional空与数字零、答案规范顺序、地址/发票重算纯接口保留答案及最终JSON恰好65535字节（retainAnswers尚无实际UI调用，不代表真实地址/发票往返已验收），不建立UID/认证演员、不启动App/SQL/cache/HTTP。首次两个纯容器在PHP脚本执行前因只读/tmp无法创建锁文件退出1；补独立tmpfs后正常，未放宽任何业务断言。此记录不签收真实非空表单订单、UI或Task4，下单gate保持关闭。

2026-10-15 通用创建补充源码检查点（在独立 `feature/ms4-generic-forms` 实现）：DAO 的 claim 已先锁真实 user.uid；temporary 已提交引用沿 UID 主键完整分页核对；markCreated 现消费第五参数 lockedInputs，逐项核对已保存事实后仅删除本人的选中 persistent cart。普通 CLI 已增加真实未选中行的完整读回保持，以及正常重放时购物车不再写入的断言。两文件语法及独立源审通过，实际 SQL 尚未执行本增量，temporary 创建/分页实际验证与生产 create 服务仍待接线。

同一源码检查点已完成顾客确认页的 V1 分组表单接线：新增 `template/uni-app/utils/marketplace-order-form.js` 与普通检查 `help/audit-regressions/isolated/ms4-form-ui.cjs`，修改既有 `pages/goods/order_confirm/index.vue`。商品ID、定义hash、字段序号共同定位，数字保持字符串、图片保持数组；重算保留当前答案，提交只含 product_id/definition_sha256/values，不以标题识别字段。旧 flat 分支保留，create gate 仍关闭。27条普通源码/真实组件状态断言、官方 H5/MP-Weixin/App-plus 条件预处理、Vue 2.6.14 模板、Node 22 语法和 Sass 检查通过，独立源审未发现天俪专属业务。上述检查没有创建 DOM、运行 HTTP/SQL、真实地址/发票往返或订单落库；生产 snapshot 调用、保存快照的版本化详情消费者及实际 UI 验收仍待完成，不记 Task 4/MS4 通过。

**生产创建接线设计（2026-10-15，沿同一 MS4 执行清单）：** 在独立 `feature/ms4-create-services` 接线新 `MarketplaceOrderCreateServices::create(uid,routeKey,rawPost)`，v1 Controller 在旧 extend_order/postMore/缓存锁之前进入 V1 分支；只用真实 Request UID 和未过滤的请求数组。创建 gate 仍由现有 hard guard 阻断，本轮不开放 HTTP 创建。新服务规范输入及普通特性合同后，以真实默认 `Db::transaction` callback/PDO 调用 DAO；claim 的首个业务SQL锁真实 user.uid，正常 committed replay 直接返回原 orderId/key，不读确认缓存、重算或要求当前配置。

新 claim 的 cancel_at=0 只是同事务尚未提交的占位。取真实原确认缓存并以 frame 做纯校验后，先 lockInputs 锁定商家、商品、SKU、运费、地址/发票/cart，再执行真实当前用户、购物车和共享价格读取，避免锁前普通 SELECT 建立旧一致性读视图。锁后重读缓存和实际 frame，并比较冻结选择；锁内报价使用从普通 CLI 提取的共享 `MarketplaceOrderLockedQuote` 核完整商品/SKU/数量/价格/成本/整行运费与地址可见投影，不能手工拼报价。非空 custom_form 必须调用已实现 snapshot，订单/发票/库存/reserve/items/所选购物车消费同一事务提交。原 orderCreateAfter 不接入新链路，post_commit_done=0 继续登记待实现的持久收尾责任。

取消配置沿真实 SystemConfigDao 原始 JSON 读取 order_cancel_time（小时），用现有十进制 JSON 解析和 BCMath 转整秒，检查 UINT 范围；原值0表示禁用，保存 cancel_at=0，后续超时扫描必须跳过0。正值按原整秒精度得到 add_time+seconds，不能用 CLI 的固定一小时，也不能把极小正值截成的0秒误作禁用。markCreated 增加可选最终 cancelAt 参数，在同一次 state1 UPDATE 保存；旧调用未提供时沿 claim 已核值，claim/replay 均允许有明确定义的0。V1 保留空 payType 延后选择；非空按已有 PAY_TYPE 与真实支付开关校验，余额仍禁用，不发起支付。共享缓存 other.offlinePostage 必须刷新为锁后当前配置，不把600秒旧配置当创建权威，会员价格继续真实共享服务。

Files：新增 `crmeb/app/services/marketplace/order/MarketplaceOrderCreateServices.php`、`MarketplaceOrderLockedQuote.php`；修改实际 v1 `StoreOrderController.php`、`MarketplaceOrderDao.php` 与普通 `ms4-order-normal.php` 为必要调用点。额外必要调用点 `StoreOrderComputedServices::computedPayPostage`：V1的other已显式包含offlinePostage时，该值为权威，0不能再经旧OR表达式回落到缓存1；没有显式值及旧版本保留原兼容行为，也保留非offline/自提的短路读取。聚焦语法、真实依赖/载荷/锁序/门禁与独立源审；普通 CLI 后续复用同一锁内报价协作者。完整服务/API、非空表单实际保存、持久收尾/取消及各历史消费者验收仍保留，不能据源码接线开放 gate。

离线运费聚焦普通检查另增 `help/audit-regressions/isolated/ms4-offline-postage.php`：真实 vendor/AppService/ModelService 和私有 tmpfs File cache 下调用实际 computedPayPostage，不 initialize App、不建立数据库/HTTP/认证演员；普通算费形状样例核V1当前0与旧缓存1时仍收原运费、当前1与旧缓存0时包邮、旧分支与未提供值的兼容、普通线上金额及自提短路。该检查只代表共享方法收费分支，不证明真实店铺/地址/价格权限或完整交易。

顾客请求接线合同：从服务端真实确认 DTO 全体 cartInfo[].id 取字符串 refs、从已核元数据取 mer_id/source_kind，body 只补 cartId/mer_id/new/idem_key 和规范 grouped answers；不提交客户端 UID/source_kind/orderKey。稳定 idem_key 使用 `confirm:` 加本次原样服务端 orderKey，同确认计算、地址/发票往返与普通重试不得重生；原确认由 server Snowflake 字符串产生，不做 Number 转换、不生成替代确认key。首次提交须保存原请求内容和真实 key，未确认结果的重试原样发送，不静默用新编辑内容替换旧请求；具体编辑/重试状态与可见反馈接线后再检查。快捷购物车两页转入现有按店购物车选择，不能对平面跨店行直接结算。再次购买须从新真实临时 refs 核当前根/SKU归属再返回实际店铺，硬刷新须真实完整 DTO 或明确重新确认；这些后续入口责任仍保留。

**保存快照顾客出口设计：** 新增纯 `MarketplaceOrderSnapshotProjection`，只消费已按真实顾客 UID 查询的保存订单/项/退款快照，不重读目录、缓存或当前表单定义；不全局修改 tidyOrder。V1 表单输出 product_id/product_name/definition_sha256/fields，字段固定 field_index/title/label/status/value，核类型、UTF-8、序号/hash格式并原样保留已存hash，不重新校验当前必填或重算定义，不输出组内cart_refs。保存 cart/productInfo/attrInfo 按既有顾客展示所需的固定字段投影，保留精确ref、数量、金额、整行运费、商品名称/图片/规格；排除成本、UID/分销、库存内部规则、整个marketplace血缘/reserve/ledger/selection/postage权重。顾客订单顶层也不得返回私有cost、原始_info/user等重复容器；逐一核对实际页面消费者需要的展示字段，不能只过滤一份cartInfo后让refund_cartInfo仍透传原包。

Files：新增 `crmeb/app/services/marketplace/order/MarketplaceOrderSnapshotProjection.php` 与普通纯 `help/audit-regressions/isolated/ms4-saved-snapshot.php`；必要调用点为 `StoreOrderServices::getUserOrderByKey` 的最终顾客返回、`StoreOrderRefundServices` 新明确 `getUserRefundDetail(uni,uid)`、顾客 `StoreOrderRefundController::refundDetail` 与 `StoreOrderController::express`。新顾客退款出口先核真实退款及父订单都属于真实 UID，再按父单保存版本处理cart_info/cartInfo别名和从父单保存表单选取退款商品组；平台、商家和移动admin内部授权调用保持各自路径，本次不全局替换内部refundDetail。v0/NULL仍走原输出。顾客/平台/移动admin只读V1分组展示、平台_info重复快照、实际详情/退款API及历史兼容各自保留验收责任，不能据一个纯投影记全消费者通过。只做普通有效保存快照与纯检查，不运行已停止的拒绝/冲突等样本。

顾客保存表单UI必要调用点：修改 `template/uni-app/pages/goods/order_details/index.vue`，新增普通 `help/audit-regressions/isolated/ms4-saved-form-ui.cjs`。按保存 marketplace_trade_version 显式区分V1只读组与旧flat；V1按商品名称和原字段序号展示，保留重复标题、原数字字符串、图片数组及原八种label，复制内容也须有真实商品与字段，不能拼undefined或调用编辑态initialize/configuration重hash。仅消费服务端已脱敏组，不要求cart_refs、不重读当前商品；旧详情原表单和复制路径保留。官方多端预处理/模板/语法和普通组件状态检查之后，真实DOM、移动布局、详情API及退款/平台/移动admin各消费者仍须独立验收。

2026-10-15 生产接线源码检查点：新CreateServices/LockedQuote及DAO最终期限、普通CLI共享核对、v1 Controller真实调用已实现；hasMarketplaceConfirmation也进入同一新分支，不为未来门禁开放留下旧create回落。hard guard仍never，生产创建尚不可用。当前配置读原JSON，取消0/正小数精确时点，显式false/空串不误开默认支付渠道；V1明确要求所有非空渠道有当前实际开启值，缺失/null不默认开放好友代付，不沿用旧sys_config外层的默认1回退，空payType仍允许延期选择。相关PHP语法、正常纯时间/选项/配置检查及独立源审通过。Root的离线运费窄分支与普通检查已通过6个收费/兼容分支、15条总断言；首次预置严格int1与真实File数值字符串返回不符的失败保留，核vendor后改为严格'1'/'0'并加真实写入确认，金额断言未改。该检查真实组件/File cache运行，不initialize App或执行SQL/HTTP/身份。

同检查点顾客保存投影、订单最终出口及退款wrapper已实现；V1显式核保存owner等于真实Request UID，不沿旧礼物的uid|gift_uid路径扩大新范围，v0原输出保持。顾客详情/退款及物流退款调用均接真实UID，3种cart别名及invoice/status/help/split按固定字段投影，保存hash不重算；普通纯快照24条与相关PHP语法/独立源审通过。顾客详情V1只读分组显示与复制已接，37条普通真实组件方法状态检查、H5/MP/App官方预处理/模板/JS/Sass及独立源审通过。当前均无真实API/DOM/剪贴板或非空表单订单证据；平台/移动admin、退款页面、发票、拆单/售后/支付消费者及MP付款方判断的服务端布尔合同继续pending。普通418 DAO通过只属于上一个源码闭集，不能替代本轮源码的完整服务验收。

**普通内部创建服务验证设计（2026-10-15，feature/ms4-internal-create）：** 继续扩现有 `help/audit-regressions/isolated/ms4-order-normal.php`，保留原direct六DAO及独立读回/重放的全部断言；其后新增真实persistent和temporary两个CreateServices场景。原角色、入口、收据文件和完整源闭集不变，但收据scope改为 `NORMAL_ORDER_INTERNAL_ONLY`、成功状态改为 `VERIFIED_NORMAL_ORDER_INTERNAL`，原迁移的STRUCTURE状态不动，不把旧DAO运行当本次新服务证据。仓库外在原candidate-v1上派生新candidate及正常关闭源，只升级准确状态/新增场景proof要求和新host来源绑定；完整逆变换须恢复原五文件/原关闭脚本，资源身份、DML权限、隔离及关闭条件不放宽。

原dao fixture空表/原配置/真实PK断言保留；新两个场景仅复用真实插入协作者，分别生成新的原生UID、商家、两商品/真实SKU、地址、发票。商品A两个同SKU引用数量2+1，商品B一个引用数量2，3items/2reserve；每商品真实写现有custom_form定义，重复标题、非空必填和optional字符串0从真实确认DTO组装答案，不手写hash。persistent另保留一条未选SQL cart；temporary实际调用setCart(...,new=true)获得opaque refs并核真实uid/version/cartData缓存，创建和重放前后本人SQL cart完整保持。首次安装配置后只更新本Run order_cancel_time行并读回，persistent用原JSON小时0.5核deadline=add_time+1800，temporary用0核禁用；不能重复插同配置或改gate制造样本。

服务场景顺序为真实fixture提交→真实确认/原key→CreateServices::create（无测试外层事务）→独立PDO读回→原请求正常重放。独立逐项核真实root/SKU/数量/原单价成本/保存表单原hash与答案/订单金额和整行运费守恒/库存前后值/发票/ledger摘要与post_commit_done=0；不得把服务输出重新拼成期望金额。顶层原committed/replay证据继续保留，新增 `service_creations.persistent/temporary`：每项仅在实际读回断言成功后置verified/replay_unchanged，3items/2reserve/2formgroups；persistent核cart_consumed/unselected_unchanged，temporary核sql_cart_unchanged/ledger_retained，期限模式分别configured_hours/disabled。HTTP/auth/DOM、会员优惠、模板运费、取消/收尾/售后/支付仍NOT RUN；已停止的拒绝/冲突/并发/故障等样本不运行。

**其他端保存详情设计：** 平台 `adminapi` 的订单/退款详情沿已有管理员认证及角色中间件，在实际出口固定投影保存cart、三种别名、表单及退款摘要，不读当前目录；订单详情不再外发页面未用的raw `_info`，退款详情的userInfo只保留实际需要的real_name。V1不输出成本、库存规则/血缘或未支持的返佣；平台详情只读分组显示并隐藏旧返佣和未接V1的管理写操作，v0保留。旧移动 `api/v1/admin` 不等于入驻商家权限，部分详情仅保证登录；V1必须按真实Request UID走本人顾客详情/退款wrapper，不能传0或订单ownerUID冒充本人，不扩展跨店读取。移动V1跳过旧跨UID用户资料请求，隐藏旧会员/ID和管理写操作，仅展示保存DTO；真实商家履约留Task6。

Files为现 `MarketplaceOrderSnapshotProjection.php`、平台 `adminapi/controller/v1/order/StoreOrder.php` 与 `RefundOrder.php`、`StoreOrderRefundServices.php` 的明确平台保存读取方法、旧移动 `api/controller/v1/admin/StoreOrderController.php`；前端为 `template/admin/src/pages/order/orderList/handle/orderDetails.vue`（平台退款复用同组件）、`template/uni-app/pages/admin/orderDetail/index.vue`、`template/uni-app/pages/admin/refund_order_detail/index.vue` 与 `template/uni-app/pages/admin/components/customForm/index.vue`。扩现有 `help/audit-regressions/isolated/ms4-saved-snapshot.php` 普通纯样例，并新增聚焦普通组件检查 `help/audit-regressions/isolated/ms4-staff-form-ui.cjs`；不另建平台退款组件。验证责任为固定投影普通纯样例、相关PHP语法、实际组件方法/官方编译和独立源审；真实API/DOM及角色验收另记。平台/移动及顾客列表仍有raw `_info/cart_info/cartInfo` 与旧float金额生产点，其V1列表出口和原父单版本批量映射保持下一必要任务，未完成前不能声明全部消费者脱敏或开放创建。

**提交结果查询与明确调整合同：** 新增同顾客认证组的 `POST order/confirmation/:key` 只读查询。实际Request UID与user一致，key沿与create相同的原UTF-8/长度合同；`MarketplaceOrderCreateServices::lookupConfirmation` 使用同默认事务/PDO先锁真实user.uid，再按UID和真实确认hash读取原ledger并核保存V1订单。只返回created（实际orderId/key）、processing或not_found；不接收客户端UID/mer/digest，不创建claim、不写订单/库存/ledger，不删cache，不新增表或状态。not_found仅表示该次串行读取未找到订单，不代表旧网络请求已终止；即使功能关闭，已有本人结果仍可查询。

客户端四态为editing/submitting/uncertain/done。首次提交补真实cart refs/mer/new和 `confirm:`+原serverKey，冻结完整payload；普通“重试本次提交”始终原样快照，任何create失败后查询实际结果，不按被包装成字符串的错误文案推断是否创建。uncertain时锁定所有交易编辑。用户明确“调整信息”先查结果：created显示真实已存单；not_found说明上次仍可能随后完成、调整不会覆盖已生成订单，用户确认后进入同key/idem的新编辑代次。新编辑提交前再查询并冻结新payload；若旧请求先提交，原确认唯一/摘要合同保证不会第二次创建，再查询并转真实订单。迟到的成功响应不能因前端revision被丢掉，也不能把旧订单当调整后的新信息。保留原确认/幂等键，不制造关闭证明或新key。

Files：修改 `MarketplaceOrderInput.php` 提取create共用confirmationKey边界、`MarketplaceOrderDao.php` 增只读UID-first确认查询并复用原已提交订单校验、`MarketplaceOrderCreateServices.php`、顾客v1 `StoreOrderController.php` 和 `api/route/v1.php`；前端 `template/uni-app/pages/goods/order_confirm/index.vue`、`utils/marketplace-order-submit.js`（新）、`api/order.js`、两快捷购cate2/cate3（转现按店购物车）、普通 `help/audit-regressions/isolated/ms4-order-submit-ui.cjs`（新）。上传必要producer为 `template/uni-app/utils/util.js::uploadImageChange`：增加可选第5个completion callback，原4参调用行为兼容，原选择/压缩/上传每个终止路径至多完成一次，不能只因部分异常不回调使新pending永久锁定，也不能用超时假定上传结束。普通内部服务两个场景增加创建前not_found/创建后created查询及事实不变的真实检查；不运行已停的冲突/故障样本。UI状态/官方编译与source review不等于网络故障或真实浏览器验收；硬刷新、再次购及生命周期恢复仍在原任务中。

2026-10-15 内部创建验证源码检查点：五个lookup生产PHP和扩展普通CLI语法通过，既有普通wire输入19条仍通过；独立源审核同一实际默认事务/PDO、UID-first锁序、只读查询、原replay身份检查和仍关闭的create gate。Root独立复核CLI保留原direct执行块及全部验证，新增真实persistent/temporary服务场景、非空表单保存、原请求正常重放、结果查询无写入及独立PDO全列读回；仅跨PDO行的scalar表示沿原direct逐列null/string比较，未放宽业务值。仓库外 `candidate-internal-v1` 和 `normal-close-order-internal-v1.py` 完整逆变换原五文件及原正常关闭源、AST/help和独立源审通过；只升级准确scope/status、强制双服务证据及新host字节绑定。该检查点尚未freeze或运行新实例，不据源码标记实际内部创建、HTTP或UI通过；其他端详情及提交UI各自继续实施和验证。

同轮提交UI源码检查点：上述七个前端/普通检查文件已实现稳定真实key和idem、完整冻结body、原快照重试、四态编辑约束及lookup后的明确调整；上传沿原producer增加可选completion，地址/发票/报价/确认及eventChannel保留实际key、代次与pending责任。普通提交状态29条、既有表单27条通过，确认页及两个快捷分类页各经官方H5/MP/App条件预处理、模板、JS与Sass检查；独立源审通过，Root核实际产物的文件hash与结果。产物在仓库外 `artifacts/ms4-submit-ui-source/20261015-v1/`。初次harness缺只读uni平台适配及raw跨平台util解析问题按实际官方预处理修正，未改业务断言；没有运行HTTP/App/SQL/DOM、未知结果调整或迟到/失败/冲突/恢复样本。源码中的未知结果处理仍需相应实际验收，硬刷新、再次购、真实地址/发票往返和视觉验收继续pending，gate不变。

后续仅补一行代次不同的uncertain说明，明确“重试原提交，不包含尚未提交的调整内容”，未改快照/方法/状态/key/idem。确认页新hash与实际更新产物 `artifacts/ms4-submit-ui-source/20261015-v2-copy/` 核对一致，普通29/27及原三端编译仍通过，独立窄差分源审通过；v1产物保留。未知结果、迟到、故障、DOM等未运行项不变。

其他端详情源码检查点：以上五个生产PHP、四个Vue、既有纯快照和新增普通staff UI检查共11文件已实现。平台沿真实既有认证/角色出口返回固定保存DTO；旧移动V1只调用真实Request UID的本人wrapper，v0原路径。V1只读分组保留保存hash/字段序号/重名/字符串0/图片顺序，隐藏未接管理动作和返佣，不发raw `_info`、成本、库存血缘或完整用户资料。源码复核发现的旧赠品缺字段.length问题以隐藏widget本地空状态修正，不造订单字段；商品金额直接展示保存total_price字符串，旧浮点加会员优惠和会员优惠区仅保留v0。六PHP语法、普通纯投影32条、真实组件方法状态65条及平台1/移动3×三端共10组模板/JS/Sass通过，后端与前端分别独立源审通过；Root核实际11文件hash和产物。证据在仓库外 `artifacts/ms4-staff-detail-source/20261015-task4-v1/`。以上不含App/SQL/HTTP、真实API/角色/DOM或已停止样本；列表和其他订单消费者仍未完成，不标Task4/MS4验收，不开放gate。

**六列表出口保存DTO设计：** 下一步覆盖顾客订单/退款、平台订单/退款、旧移动订单/退款六个实际出口，显式开启V1列表合同；共享服务默认内部/旧调用不变，不全局替换tidyOrderList，以免更改outapi/kefu/PC/export/split等尚未盘点调用。实际保存版本决定分支，NULL/0保留原行为；未知版本或缺失退款父单不得猜作旧版。退款扩现有一次父单批量查询，当前页真实去重parent IDs映射保存order_id/status/version/uid/mer_id，不N+1、不取当前商品目录或客户端版本。顾客V1核真实Request UID与保存订单/退款及父单本人；旧移动V1同样仅本人，平台沿现有真实管理员/角色路径，不能把旧移动身份当入驻商家授权。

旧移动混合分页必须在SQL分页前按“原v0范围，V1仅真实本人订单；退款另要求真实父单本人”构造可见范围，列表、count及各原计数桶应用同一可见范围；保留原桶的状态筛选含义，不分页后过滤、不代传owner UID。必要DAO条件由内部可信参数/明确wrapper构造，不接受请求透传角色或UID。顾客既有gift匹配在V1不扩本人边界。列表只在选定public scope避开getOrderList的float pay_price和refundList的floatval运费，保留保存decimal字符串；整行postage_price/origin_postage_price只用BCMath加一次，不再乘数量。其他旧/内部调用不改，此项不据此宣称所有消费者已适配，gate仍关闭。

列表DTO按实际场景闭集保留合法形状：顾客订单cartInfo、顾客退款cart_info；平台及旧移动保留 `_info=[{cart_info:脱敏保存项}]`，其他cart别名若输出也必须同一投影，不留raw重复包。保留实际页面所需的订单/退款标识、商家保存名、状态/时间、数量、精确金额、顾客_status对象或平台/mobile既有状态文案、退款原订单编号和原因；平台仅保留确需的uid/nickname标识，不保留user容器。split只保留合法真实id/pid摘要，不误用要求完整保存版本的详情递归。列表无需完整表单答案和发票，剥成本、库存规则/权重/selection、reserve/ledger、完整user/spread/division、raw退款或invoice包。引用及金额不经Number/int/float重建。六页V1旧支付/取消/改价/审核等写入口和方法保持关闭，移动V1不用会Number().toFixed的BaseMoney重算金额；v0显示与行为保留。

Files：`crmeb/app/services/marketplace/order/MarketplaceOrderSnapshotProjection.php`、`crmeb/app/services/order/StoreOrderServices.php`、`StoreOrderRefundServices.php`、`StoreOrderWapServices.php`、`crmeb/app/dao/order/StoreOrderDao.php`、`StoreOrderRefundDao.php`；五控制器为顾客 `api/controller/v1/order/StoreOrderController.php` 与 `StoreOrderRefundController.php`、平台 `adminapi/controller/v1/order/StoreOrder.php` 与 `RefundOrder.php`、旧移动 `api/controller/v1/admin/StoreOrderController.php`。六Vue为 `template/uni-app/pages/goods/order_list/index.vue`、`pages/users/user_return_list/index.vue`、`pages/admin/orderList/index.vue`、`pages/admin/refund_order_list/index.vue`、`template/admin/src/pages/order/orderList/components/tableList.vue`、`template/admin/src/pages/order/refund/index.vue`。扩既有普通 `ms4-saved-snapshot.php`，新增聚焦 `help/audit-regressions/isolated/ms4-saved-list-ui.cjs`；不新增表、路由、全局模型类型或变更未盘点导出。先实现/纯投影、PHP语法、真实组件有效状态/官方编译与独立源审；实际分页/API/角色/DOM与尚未盘点消费者仍分别pending，不运行已停止的拒绝、权限、冲突、并发、故障或恢复样本。

**列表全局必要调用点修复：** 实际源审确认四条无row链未核保存交易版本：空ids订单导出逐页匹配全部筛选、待发货导出、Excel逐行派发旧发货Job、扫码预览/确认及批删。它们已有管理员角色认证，但当前页无V1或前端selection只v0都不能证明跨页/Excel/扫码目标是历史单。上述“未盘点导出”现在仅对这四条已盘点链补必要门禁，不改其默认DTO或导出格式：以实际完整筛选/实际全部IDs、无page/limit的同DAO查询检查保存版本；命中V1或未知非零版本则明确停止整项旧操作，不静默跳单或把V1当v0。批删在任何batchUpdate前核全部实际目标；Excel先核整批实际订单再dispatch，Job及旧splitDelivery/doDelivery在实际订单读取后、任何拆单/写操作前再守；扫码在真实load之后、confirm0原包预览或confirm1写入之前守。原NULL/0历史单沿旧行为，不新增V1发货/核销/删除功能，真实商家履约仍留Task6。

该增量必要Files为既有 `MarketplaceTradePolicy.php`（实际保存行的纯legacy断言）、`StoreOrderServices.php` 与 `StoreOrderDao.php`（完整筛选的只读legacy预检，复用同search语义）、平台 `adminapi/controller/v1/order/StoreOrder.php`（批删/Excel生产端）、`crmeb/app/adminapi/controller/v1/export/ExportExcel.php`、`crmeb/app/services/other/export/ExportServices.php`、`crmeb/app/services/order/StoreOrderWriteOffServices.php`、`StoreOrderDeliveryServices.php`、`crmeb/app/jobs/OrderExpressJob.php`；只修改实际需要的调用点，不改权限种子/路由/表或放宽既有检查。覆盖实际保存版本读取、完整筛选和操作先后顺序的源审、PHP语法及正常legacy纯边界；不运行V1拒绝、故障、权限或其他已停止样本，不据源审宣称这些操作实际验收通过。

**会员价格显示源核更正：** 共享会员价格仍属于复用的通用价格能力，并未列入首版关闭清单；上面详情检查点暂隐藏V1会员优惠区不能作为最终行为。V1商品金额直接展示保存total_price，会员优惠用保存vip_true_price只读显示，标明已计入商品金额，不经parseFloat或再次相加；v0原公式保留。顾客两列表V1单价取保存truePrice实际成交单价，不能优先回基础attrInfo.price；旧移动订单页退款tab按实际_info商品项而不是不存在的cart_id计数，不造DTO补丁字段。必要Files沿已记录的详情 `pages/admin/orderDetail/index.vue` 与普通 `ms4-staff-form-ui.cjs`、六列表内相关Vue；后续普通组件/三端编译与独立源审单独更新证据，实际会员折扣SQL/API仍pending，不将此前无优惠场景当会员价格验收。

实际生产链还需同一保存金额修复：LockedQuote核真实sum(truePrice×qty)等于priceGroup.totalPrice，CreateServices原样存为total_price；因此显式public V1列表的 `StoreOrderServices::getOrderList` 与平台 `StoreOrder::order_info` 也必须跳过旧vipTruePrice加回，仅legacy及共享服务默认内部scope保留旧公式。不能只改显示却让V1 API把保存成交额改成基础额。路径均在本轮已记录Files内；聚焦语法与独立源审，真实会员SQL/API仍独立pending。

六列表/旧操作及会员源码检查点：上述列表后端11生产PHP、既有普通快照、六列表Vue/新增普通cjs和六个实际旧操作必要文件已接线；ExportExcel原完整filter已传真实producer，因此未修改该控制器或重复查验。导出既有完整筛选预检，也在实际分页读回后、格式化前逐行核保存版本；批删、整批Excel、worker、旧delivery和扫码沿实际读取前后顺序守住V1。独立后端、前端及必要调用点源审通过。Root初次11PHP语法0，最后新增/变动七PHP再次语法0（合计17个不同PHP文件），普通保存投影54条和正常legacy版本3条实际通过；没有执行拒绝、权限、并发、故障或恢复样本。纯结果和最后七文件hash/语法在仓库外 `artifacts/ms4-saved-list-source/20261015-backend-v1/`。

六列表普通真实组件/VNodes45条、14组官方模板/JS/Sass通过，Vue warnings为空，Root核实际六Vue与检查脚本hash；证据 `artifacts/ms4-list-ui-source/20261015-v2-native-dto/`。源码检查发现的空重复searchSubmit、数组.has、顾客基础SKU价优先及移动退款tab不存在cart_id均按实际producer修正，不造DTO字段或弱化断言。会员详情保留原65条再加8条正常两行VNode金额检查，共73条及原10组官方编译通过；证据 `artifacts/ms4-staff-detail-source/20261015-task4-membership-v2/`，v1产物保留。V1详情及显式public列表保存成交额不加回优惠，会员优惠只读标已计入，v0及共享内部默认原式；两处PHP和两处UI检查均获独立窄源审。当前这些结果仅源码/纯组件证据，不含实际分页/API/角色/DOM、会员折扣SQL、导出/队列/核销实际操作，不标Task4完成或开放gate。

**普通内部创建真实结果（2026-10-15）：** 固定clean源码 `eb08b77a670d1920e5ceb33080b054b2001d1be6`、新Run `2026101501573624b007`、freeze摘要 `3ad413965f886dff438cda908e9fa0328f26c174d245d50c00e1347596d21966`；有限initializer及check/apply-structure/check/apply-structure四个不同原生子进程均实际exit0、stderr0。唯一登记的订单CLI实际exited/exit0/PID0，phase=final_source，状态 `VERIFIED_NORMAL_ORDER_INTERNAL`、scope `NORMAL_ORDER_INTERNAL_ONLY`；业务收据摘要 `8c62ccfbf6be7c053421dce817d1de579a9a8c40bf34ca4e45f87233c8d057f0`。保留原direct3items/2reserve/选3余1完整不变与普通重放；两个真实CreateServices persistent/temporary场景各3items/2reserve/2非空表单组，独立PDO核保存价格/成本/金额/库存/地址/发票/表单hash与字符串0。两场景创建前not_found/创建后created只读查询及原请求重放均无额外业务写；persistent只消费选中cart且未选全列保持，原JSON0.5小时保存deadline=add_time+1800；temporary实际setCart生产三引用、本人SQL cart完整保持、ledger保留、原JSON0保存deadline0。post_commit_done仍0，不能据此称持久收尾完成。

同Run原cleanup helper实际exit0并按原ID移除；独立审过的 `normal-close-order-internal-v1.py`（摘要 `64eca95270b070956442d2c066bf76954eec85357e06d21975ac2bd5b3705931`）实际执行正常stop MySQL/Redis，两次原native进程及容器均0；原三个CID、内部网络、六卷按原身份非force移除。Root固定来源的独立verifier（摘要 `dd8d40e0ffd811cfb338abd06d745eb136a12ea8ea3fc3259ad5173f42dde9fc`）实际六条双标签原生查询均exit0/stderr0/count0，业务字节摘要仍相同，Run已关闭。完整上下文及证据保留在仓库外该Run目录。这仅证明eb08内部创建与正常重复提交，不覆盖本检查点之后列表/门禁/会员显示的新源码，也不证明HTTP认证/创建API、真实页面、会员折扣或模板运费、取消/超时/持久收尾/售后/支付及任何已停止样本；这些和其他尚未盘点消费者继续pending。

**下一普通内部验证合同（源码准备，尚未运行）：** 保留已有 direct DAO 与 persistent/temporary 两个 CreateServices 场景、精确原收据键和全部原断言，追加两个独立真实用户/商家/商品/SKU的会员价格场景；不新建支付、开通或退款记录。沿安装既有 `system_user_level`、`user_level`、`member_right` 三表原生自增ID，user.level与真实level_id一致且在该列1..255范围；等级90.00，实际SKU A20.15/VIP16.00/成本7.45、B30.25/VIP28.00/成本9.35，A两行数量2/1、B一行2，固定每件运费2.67。等级场景独立期望商品原额120.95、成交108.83、等级优惠12.12、会员优惠0.00、成本41.05、运费13.35、应付122.18；有效付费资格加等级场景成交102.44、等级优惠6.06、会员优惠12.45、成本41.05、实收运费10.68、应付113.12，三行实收运费4.27/2.14/4.27。金额按原BC2截位和既有整分分摊，不从待测输出反推。

原无优惠场景先完成，再按真实配置ID及旧JSON0条件只更新 member_func_status/member_card_status/member_price_status 为1，独立读回，仅失效三个对应system_config缓存键，由真实sys_config重读；不清整个缓存、不写伪缓存标志。付费资格沿真实 UserServices::setMemberOverdueTime(30, actualUid, 1, 'month') 保存并以调用前后时间和独立PDO核对；只证明已有有效资格下复用价格，不声称购买/支付/到期工作流通过。两个新场景各核确认、完整创建、保存项实际价格类型/优惠/成本、整行运费、表单/发票/库存/cart、正常重放和只读lookup，另输出 membership_creations.level/paid，原 service_creations 两键不变。

追加 saved_reads 阶段复用原两张实际创建订单，从独立PDO保存事实建立期望，调用真实顾客/平台/旧移动订单列表、本人详情及三端真实空退款列表；核保存版本、商家、引用字符串、成交金额/整行运费、实际别名和表单，不注入身份或构造Request替身。生产列表使用真实默认分页；真实DAO以显式page1/2和limit1核两张订单分页与相同筛选count。读前后事实摘要保持不变。空退款只证明空查询，不证明非空父单批取；不在未付款订单插合成退款或改paid，非空退款与真实申请链继续pending。必要Files为既有 `help/audit-regressions/isolated/ms4-order-normal.php`（唯一入口、源码/fixture/facts闭集及调用接线）和新 `lib/ms4-saved-order-normal.php`（普通实际读取检查）；新helper必须列入完整源指纹。仓库外 preparation 从现candidate-internal-v1可逆派生v2，保留完整原业务/隔离/原生退出/关闭条件，再要求新增会员与读取证明；旧Run不重开、不改证据。

另已盘点顾客订单列表 onLoad 的 wx.getEnterOptionsSync 仅属于原小程序支付返回逻辑，H5不能注入fake wx掩盖；必要修复仅在 `template/uni-app/pages/goods/order_list/index.vue` 用官方MP-WEIXIN条件包围该原块，保留小程序语义并核官方H5/MP/App编译。此处修复及普通内部SQL验证不等于HTTP/DOM验收；真实浏览器仍须fresh构建、正常真实账号及原ISO-FULL内网，本轮未具备前不声称通过。

本合同的H5必要修复已完成源码验证：订单列表只增加两条官方MP-WEIXIN条件指令，H5/App正常onLoad不再访问wx，小程序预处理后原支付返回块逐非空行保持；六列表普通组件45条、14组官方模板/JS/Sass均通过且Vue warnings为空。证据在仓库外 `artifacts/ms4-list-ui-source/20261015-v3-mp-api-guard/`，Root实核六Vue产物hash一致；这仍不是支付返回流程或真实DOM验收。外部 `candidate-internal-v2-final` 和 `normal-close-order-internal-v2-final.py` 完整逆变换原v1五文件/关闭源、AST/help及独立源审通过，host摘要 `cc18ca9458de79ca5f8af26f6a301b31ff87e4d74a620ab000e5228369472978`、close摘要 `9b532551247110c558cf840781998a83cb44d9e4532c19aaaad80fd8682becbf`；仅增加新helper源指纹和会员/保存读取必备证明，原隔离/原生退出/关闭条件不变。未补helper闭集的早期v2只作未运行来源保留，不用它freeze或启动。

普通保存读取源审发现必要producer修复：`StoreOrderCartInfoServices::getOrderCartInfo` 原先按cart_id跨订单SUM退款数量，真实ThinkORM sum固定返回float，V1列表的严格保存投影不能消费该类型。新增必要Files仅 `crmeb/app/services/order/StoreOrderCartInfoServices.php`：在缓存之前读取真实父订单保存版本，V1按同oid读取每条真实订单项的cart_info/cart_id/refund_num，用范围内整数数量及原字节引用，不读取其他订单相同cart引用、不放宽公共投影接受float。V1先沿实际保存行读取而不复用旧聚合缓存；旧NULL/0行为及缓存保持。未知保存版本沿既有策略处理，不从客户端或当前开关推断版本。保持原普通helper断言，以后续实际列表/详情证明修复；此处源审发现不是已运行失败，未执行任何拒绝样本。

本合同源码检查点：会员普通CLI固定摘要 `d2085d6abf24096c209206e43ffccd2777f8b7f7b5f1553b8e4ba813601223b4`，新增保存读取helper摘要 `522e05e05e48a4d164884a699fac6dc920275329d69ad076f9a664b56765297b`，CartInfo必要producer摘要 `80cb7815cad77cfdfc57c7904642ac5ba69ad314f5ee01869fb074f99f33a0b4`；三PHP隔离语法均exit0。财务/fixture、非财务原业务保留与接线、CartInfo源和helper分别获得独立SOURCE PASS。Root另核原20个函数中17个完整字节不变，三个必要变化仅可选会员验证、真实上下文和主接线；原direct执行、原价格/库存/cart/表单/发票断言保留。初始新fixture对资格writer返回值的行数假设，已在执行前按真实User Model修正，独立PDO时间窗/flags/getter未放宽。此检查点尚未执行新SQL，不把SOURCE PASS当作列表/会员实测。

本合同首次真实运行（失败证据保留，已关闭）：clean `feb1d0fd3f255929515166e5881067ddb8e36dc5`、Run `20261015025911deb022`、freeze摘要 `0b03bc585842ad4b4c5d89f35fa41cf54ee85d7db5ce1618c8428c5f8a879d68`；initializer实际exit0/6children，check/apply/check/apply四个迁移子进程均exit0/stderr0。订单CLI原direct与persistent/temporary两个完整创建、保存事实、只读lookup和正常replay已执行到各自末尾，但整轮实际exit1/PID0，停于 membership_configuration 的 `MEMBERSHIP_ACTUAL_CONFIGURATION_GETTER`，会员两个场景与saved_reads均尚未开始，不记整体通过。初始化已正常结束后误发一次只接受created状态的initializer-resume，主机阶段按原保护返回1且没有重启；随后driver-create按同一实际exit0 initializer继续，原实例未重开。

失败源因已确证为新增CLI类型假设：`common.php:145–150` 将sys_config非数组结果明确转字符串；当前普通CLI的File缓存按Think cache Driver数字序列化/反序列化规则读回数字文本；真实 `SystemConfigServices::getConfigValue` 则JSON解码为int。不是已证明的缓存失效缺陷，三个精确键和delete正确。下一修正只在既有CLI严格核 sys_config==='1'、File CacheService::get==='1'，同时增加真实getConfigValue===1及保留独立PDO原JSON值'1'读回；不改生产配置/价格、放宽为loose比较或写伪缓存。先保留原失败收据并按精确Run/source/CID的失败关闭协议正常停止/非force移除，独立核零残留，再固定新源码启动全新Run；旧Run及候选不修改、不重启。

同Run失败关闭已实际完成：原metadata cleanup helper实际exit0后移除；固定失败关闭源摘要 `c40888874949d1438accd8248ad382c1af4fbcd17e60675fa031afe2407a4296` 保持业务FAIL_PRESERVED，原MySQL/Redis正常stop的原生进程及容器均exit0，原三CID、唯一内部网与六卷按原身份非force移除。Root独立关闭核对源摘要 `328a8100661c6a90d5c9759b31ea5933ca964a6482a981e3c93369f949a4fe37` 实际六条原生双标签查询均exit0/stderr空/count0；原业务收据摘要仍为 `236dce2d063dbe1b94cee1be25f8a23e60f20f5135dc169256cfe2b72ccc98c8`。旧Run不重开。CLI实际类型窄修摘要 `d7ea8bf2f10fe685b2719ddbeb42303eaee3c5bd5503004c91dd7be4f99e51b4`，仅增加原始JSON getter int1并将两个包装/缓存读回严格核string1；Root PHP82语法exit0、diffcheck0，原金额/资格/direct/service断言不变，真实会员与saved_reads重试仍待全新Run。fresh前端离线构建另固定main的feb1d0fd3来源，构建期间主树保持该clean SHA；不把构建或旧Run原两场景结果写成会员/HTTP/DOM通过。

**会员与保存读取普通实测结果（2026-10-15）：** 全新clean来源 `d5386f27537d8e0905af622943e2d7d24327e410`、Run `202610150324398d7db0`、freeze摘要 `2e4e18b482a0872874d4b9c9028caccedf6c175e5827f4b792240f66c59ff9d7`；有限初始化、四个独立迁移子进程及订单CLI均实际exit0，phase=final_source，状态 `VERIFIED_NORMAL_ORDER_INTERNAL`。原direct及persistent/temporary创建断言保留；新增level/paid真实资格、确认、创建、独立保存价格/优惠/成本/运费/库存/表单/发票/cart与重放均通过，精确金额分别为上面合同122.18/113.12，不改paid或创建支付记录。真实saved_reads三端订单各2、本人详情2、DAO两页/count2及三端空退款通过，保存金额/别名/表单/发票与读前后事实一致。业务收据摘要 `0452681216a9e023b8178fade303b3ae8f2a2d6795bcd6ceb85824349e168e50`。同Run cleanup helper实际0后按原ID移除，正常关闭源9b532551实际停止并按原身份非force移除原三CID/内部网络/六卷；Root独立源d0e84515实际六条双标签原生查询exit0/stderr空/count0。原失败Run保留、不重启。本结果限内部普通链，不覆盖HTTP/真实认证/DOM、购买会员、非空退款、持久收尾、取消或真实支付。

fresh前端另固定clean `feb1d0fd3`，后台和H5真实原生构建与最终静态资源检查均exit0；后台600文件、H5 334文件与各自dist逐字节一致。完整报告 `artifacts/ms4-browser-preparation/feb1d0fd3-source-v1/BUILD-REPORT.json` 摘要 `b2003251c13f74a7fe98a19ed67403671aae3fc4ecb2eb8e291e70cd5b82b26b`。首轮checker错误拒绝合法相对manifest路径的失败保留；仅标准URL相对解析窄修后重验同一原生产物，原前缀/无越界/实际资源要求不变。沿已有本地依赖，无安装下载；原CSS顺序/包体积告警完整保留。此处不是实际登录页、品牌配置或DOM验收，中性通用品牌仍待真实新Run配置及页面核对。

**Task4持久收尾实施合同（开始编码前记录）：** 新 `MarketplaceOrderPostCommitServices::finish(actualOrderPK)` 只在原创建事务物理提交后运行。先在事务外只读真实ledger定位UID，再用真实默认PDO事务按UID→ledger→order→保存items锁定，复用committedOrder核已提交身份/版本/确认hash及refs；已有外层事务时不提前收尾。done1只读返回；done0以保存order.add_time写一条cache_key_create_order/订单生成状态及保存items实际UID/product/qty的order统计，最后条件写done1；状态、统计、完成标志同事务。两个旧表没有足够唯一键，幂等以锁定ledger及同一事务保证；存在无法解释的创建状态不得猜作完成。不调用旧listener、重新报价/佣金/默认地址或第二次删除SQL cart，不改金额/库存/支付/取消期限。

确认缓存只清真实UID+原key；present时核真实frame与保存mer/source/refs。temporary只清持久ledger已消费原字节refs，present时逐项核保存UID/版本/product/SKU/qty；永久ledger不删，不动未选SQL cart。真实缺席或删除后缺席才成功，不要求不存在键的delete返回true；异常或仍存在保持done0。CreateServices事务内保留真实orderPK，fresh/replay在事务结束后都尝试同finish，公开order_id/key响应形状不变；只捕获已提交后的收尾异常，记录固定脱敏码与内部订单关联，返回已创建结果，不能返回创建失败。state1/done0是持久责任，scanner按原索引/真实orderPK每页最多100，各单独立finish、失败留pending且继续后项，游标到尾回绕，不按当前商家/商品状态、开关、TTL或cancel_at过滤。真实handler把下一游标保存在专用缓存hint以跨tick推进；hint仅影响扫描起点，缺失/无效时从0开始，不能证明任何订单完成；重叠tick可重复扫描但仍由ledger锁幂等，不新增业务状态表。

复用既有system_timer/CrontabRunServices调度体系，新增mark及真实handler；独立 `MarketplaceOrderPostCommitTimer` 以默认连接GET_LOCK串行、事务重核保存注册、原生PK和既有getTimerStr生成每分钟任务（type2/minute1/open1/notdeleted/customCode为空），刷新真实crontabCache。重复安装严格核原记录、不覆盖异形或人为停用记录；单独Think命令 `marketplace:post-commit-install` 提供明确DML安装入口，不改变已有structure-only迁移。当前无需新队列连接/Job；旧marketplace连接专属AuditLogJob且queue0延时不执行，不能假称可靠补办。Files为既有 `MarketplaceOrderDao.php`、`MarketplaceOrderCreateServices.php`、新 `MarketplaceOrderPostCommitServices.php`/`MarketplaceOrderPostCommitTimer.php`、既有 `system/crontab/CrontabRunServices.php`、新 `app/command/MarketplaceOrderPostCommitInstall.php` 及 `config/console.php`，沿当前唯一普通CLI和新增 `help/audit-regressions/isolated/lib/ms4-post-commit-normal.php` 独立PDO普通helper、仓库外准备协议补验证，不新增业务表。

普通验证保留全部原价格/库存/cart/表单/发票/会员/保存读取断言，生产CreateServices现应done1并精确核真实状态、统计、缓存缺席；重复finish/replay没有额外写。原direct DAO自然留下done0，由真实注册mark的同一handler补办，再核done1和不重复；不把已完成flag改回0制造恢复样本。注册真实原生ID/字段及第二次无变化、真实handler调用独立读回；不运行全局所有timer，daemon与wallclock调度仍pending。此合同先源码/语法/独立审，再新固定来源的普通隔离Run；不运行已停止的权限、拒绝、冲突、并发、故障或恢复样本，不开放创建gate。

Task4持久收尾源码检查点：上述7个生产/注册文件、唯一普通CLI与新增helper均已接线，独立生产/定时注册/helper及Root CLI增量源审通过；Root真实PHP82对9个文件语法均exit0。源审补了两个正常producer必要修正：helper按实际Snowflake confirmationKey核原值与ledger SHA而非误定固定32长度；新finisher在默认连接尚无PDO时先用同一主连接真实SELECT 1初始化，已连接路径不额外查询。后者修改的唯一PHP再次语法exit0；证据与最终9文件hash在仓库外 `artifacts/ms4-post-commit-source/20261015-v1/`，最终PostCommitServices摘要 `f7aa681e25fe0b36579e8747f2eb61f6c384268d235f146664c547e063c25ae5`、helper摘要 `fa63fd6495bdee2cdef6a7a0ebec7eb70aa97778ec851df89cee219ccb16b16c`。源码及语法不代表实际收尾通过。

仓库外新 `candidate-internal-v3-post-commit` 五文件与 `normal-close-order-internal-v3-post-commit.py` 从原v2-final完整可逆派生，原compose/initializer/seed字节不变；host仅新增helper源闭集及更强收尾证明，原完整v2业务/隔离/native/关闭条件保留。host摘要 `c05d728d7d20c5f65eb7e0491e0c854cc33b2c43d7a70fbb9a6a76c5112456ff`、close摘要 `fdd4e3713284d4bac90f1382d160066410ff95861d6eb23a9fd1635f56c32ad6`，三Python AST/完整逆变换及独立SOURCE PASS；尚未freeze/启动新Run，不改旧Run。真实收尾/注册/handler普通结果仍待新实例，wallclock daemon与HTTP/DOM等原未验收项保持pending。

持久收尾普通实测结果（2026-10-15）：clean源码 `583ef7541de80f1929c9b9432b14fea8df675b8c`、新Run `202610150359426fc7a7`、freeze摘要 `e19775bab6c28819add5bfc97066cd05ad235cc0e7b6ed758bdab4f3dc7ef04d`；有限初始化、四个迁移子进程及订单CLI实际exit0，phase=final_source、VERIFIED_NORMAL_ORDER_INTERNAL。原direct/双来源创建/两类会员计价/保存读取断言均保留通过。四张实际CreateServices单各done1/唯一创建状态/三条逐保存项统计/准确缓存缺席，真实重复finish和原请求重放均无额外业务写；自然direct done0由真实注册mark handler处理为done1，同样独立保存事实核过。原生timer ID10，完整注册两次第二次字段/facts不变，实际handler processed1/completed1，真实hint回绕0。

同Run业务收据摘要 `91d783ab15734b8b57b60109fb572eb4d8a3fa17a20d84bf3e6dd07804358414`。原cleanup helper实际0后按原ID移除，固定fdd4e371关闭源实际正常stop MySQL/Redis且原生进程/容器均0，原三CID/唯一内部网/六卷精确非force移除；Root独立核对源 `0db5680f185f7d163c932407f7741fe8049545094b9a0b9c91cb28856c95ee49` 实际六条双标签原生查询exit0/stderr空/count0，保留业务字节不变。该Run已关闭，不重开；只证明583内部持久收尾及有限同handler，wallclock daemon、HTTP/DOM、取消、真实支付等仍pending。当前主树之后的旧支付边界/Task5新源码不包含在此Run结果中，不据此标Task4/MS4整体完成或打开gate。

### Task 5: 取消与超时释放

- 接口：整笔未支付且未发货的取消/超时按真实父reserve聚合release，`idem_key = "release:<store_order.id>:<parent_reserve_id>"`；两项为真实已提交PK，尾段是规范U64字符串，不能用非全局唯一的SKU八字节unique。动作摘要绑定保存顾客/商家/订单/父reserve/SKU/数量，顾客取消与系统超时共用动作，演员仅记首次真实执行者；重复不重复释放。未来Task7部分退款用独立真实退款动作键，共同核累计release+return_in不超过父reserve.qty。关单前走可信支付query→close→query closed；unknown或无可信attempt不释放，已支付转待处理，不制造paid或回退平台账户。MS5决定前仅显式testing驱动可做普通未付关单。
- 用例：O06。

**创建开放前的旧支付入口版本边界（源实施合同）：** 现下单gate仍关闭，新真实支付尚未接入；仍必须阻止保存V1落入会改订单编号/pay_uid/pay_type、保存金额、余额、paid、发票或资金流水的旧链。复用既有 `MarketplaceTradePolicy::assertLegacyOrder`，只传真实保存行（显式select含marketplace_trade_version），不相信请求版本或先更新后判断。NULL/0原行为及会员OtherOrder/充值分支保留；不接真实渠道或冒称MS5完成。

必要Files/位置为顾客 `api/controller/v1/order/StoreOrderController.php::pay` 在旧缓存锁和编号/pay_uid/is_channel更新之前；`services/order/StoreOrderServices.php::getCashierInfo` 在旧收银台/当前取消配置逻辑之前、`setOrderTypePayOffline` 在运费/订单金额改写之前；`services/pay/OrderPayServices.php::beforePay` 商品订单分支在pay_type更新/SDK前；`services/pay/YuePayServices.php::yueOrderPay` 商品订单分支在余额锁/扣款/流水前；`services/order/StoreOrderSuccessServices.php::paySuccess` 实际重读后、paid短路/写入和事件之前；同文件公开 `zeroYuanPayment` 在其原caller paid判断之前也重读实际保存版本，保持原零元语义；`services/pay/PayNotifyServices.php::wechatProduct` 实际版本分流先于旧paid短路/确认成功；`services/pay/OrderOfflineServices.php::orderOffline` 在实际订单读取后、paid分支及任何状态/发票/资本流水/任务之前。按实际源定位修正命名路径，不复制不一致的客户端订单payload作版本证据。

此处只补已盘点旧入口对V1的必要边界，不增加V1支付/取消能力、不改路由/权限种子或全局旧业务公式。先只读盘点真实查询和producer/consumer字段，再窄实现、聚焦PHP语法及独立源码复核；不运行V1拒绝、权限、冲突、并发、故障/恢复或支付样本。当前583ef7541普通Run固定在另一clean侧树，本段后续源码在主树独立推进，不改正在运行的源或证据。取消/旧库存回退的分流与硬删血缘保护继续由Task5服务合同处理。

**Task5与MS5 Task2实施设计（开始编码前记录）：** 新 `MarketplaceOrderCancelServices::cancelForCustomer(actualUid,orderNo)` / `cancelExpired(actualOrderPK)` 只处理真实保存V1整笔未支付未发货订单；提示读取在事务外，默认PDO顶层事务内按UID→ledger→order→原商家→roots升序→SKUs升序→items→父reserve及children重核保存版本/确认hash/引用/SKU/数量及提示集合。顾客UID沿现AuthToken Request传入，不新增模拟上下文；商家/商品/SKU仅锁身份，停用、下架、退役不阻止存量释放，不用创建准入方法。

首次释放前必须有可信非空完整attempt集合且逐项query→close→query closed，原orderPK/订单号/UID/mer/精确金额/币种/原测试账户ref及version全部相同；unknown、空集合、新进程或缺真实渠道能力保持pending，不把paid0当机构未付，不写paid。生产取消返回确认中并保留订单，现HTTP wrapper不得忽略pending却报已取消。完整真实取消和canonical release证明齐全时可正常重放，不重查当前账户/重复释放；paid或无法解释的既有状态只记录脱敏待处理，不制造财务成功或重写历史。

库存逐父reserve使用上面已明确的release键；摘要只含保存业务身份/数量，不含当次演员或原因。原生锁定并逐行核全部release/return_in子流水，累计不超过父qty，不用float SUM；保存items聚合与真实父reserve匹配，首次整单取消不得猜未定义的部分退款状态。按当前锁定值逐SKU对根/规格条件更新stock+qty、sales−qty，真实影响行数/前后值、release、订单is_cancel及一条真实取消状态同事务；根上限16777215、SKU上限4294967295。首次演员如实customer/实际UID或system/NULL，原金额/发票/表单/cart与ledger永久refs保留，不能恢复成reserve历史before值。取消服务返回固定state=cancelled/already_cancelled/not_due/pending及原保存order_id；HTTP仅前两态报成功，pending固定“支付状态待确认，请稍后重试”。真实取消状态为cancel_order/订单取消/服务器now，首次演员沿release保存，不在重放改写。

超时按真实ledger state1/cancel_at>0/due的(deadline,orderPK)有界游标，每单独立处理，pending不阻塞后项，不按post_commit_done/商家当前状态筛选；0只关闭自动超时，顾客正常取消仍沿同服务。新scanExpired显式(deadline,orderPK,batch≤100)，跨tick缓存hint仅影响起点，缺失/无效从0、到尾回绕，不当完成证据。旧StoreOrderServices::cancelOrder在退权益/旧库存前分V1，orderUnpaidCancel先跑新扫描再进入旧配置0早返；旧DAO getOrderUnPaidList只选NULL/0，旧UnpaidOrderCancelJob实际读取后分流V1，regressionStock在实际保存V1前阻断旧恢复。LegacyProductWriteServices::hasPersistentStock补真实marketplace_stock_movement血缘，存在任何历史流水均不硬删根/规格，不按净量0猜可删。

MS5 Task2只增加 `crmeb/services/pay/marketplace/` 下PaymentAdapter/PaymentAccount/PaymentAccountResolver/TestPaymentDriver四类、`config/marketplace.php` 显式开关和AppService惰性绑定。六方法按00原合同保留：createPayment/queryPayment/closePayment/createRefund/queryRefund/verifyAndParseNotification；当前driver只正常pending→closed，后三方法明确unsupported，本阶段记Task2部分。订单表当前没有currency列，测试Value明确只接受配置CNY，不伪造订单币种列或替用户决定真实渠道币种。实际启用同时要求getenv('APP_ENV')==='testing'及config显式bool true；下一专用Run真实声明APP_ENV=testing与PHP_MARKETPLACE_PAYMENT_TEST_ENABLED=true并纳入原生绑定，helper不临时伪改Env/开关，当前583Run环境不变。

测试账户仅source=test_config/account_id=NULL/test命名空间ref/不可变版本/真实mer/币种，不冒充机构验证或不存在的数据库账户PK。Resolver注册测试配置时核真实商家，forOrder重读实际保存订单及金额/归属；不回退平台账户。TestDriver私有记录只在同一App实例有效，额外内部attemptsForOrder返回显式known/unknown且known必须包含该实例非空全量尝试；query/close沿原快照，不重选当前账户。独立进程、真实账户/attempt持久化、回调、退款、支付竞态和真实渠道继续pending，不能把内部driver结果当真实支付关单。

普通验证沿既有完整创建/会员/保存读取断言之后新增独立原生订单及真实driver pending→closed、两父reserve释放/三项血缘/库存独立读回、正常重放不变；自动到期用真实正小数小时截秒0的新创建，不回改deadline/伪造时钟；另核保存0自动跳过与创建后商家停用仍释放。新增 `help/audit-regressions/isolated/lib/ms4-cancel-normal.php` 普通helper及下一版外部隔离准备须先闭集真实环境/来源并独立审，当前不执行。普通helper可在原完整创建/保存读取之后用另一个原生顾客、本人地址、真实确认/表单和CreateServices顺序购买相同SKU，再取消前一单，以独立当前库存+本次真实reserve数量证明不是历史before绝对回填；不用模拟并发。立即到期场景沿既有service_case增加可选新fixture配置小时参数和明确期望整秒数，原默认两个场景及原1800/0断言保留，新订单真实正值截秒0，不修改旧订单期限。必要库存Files为新MarketplaceOrderCancelServices、既有MarketplaceOrderDao/StoreOrderServices/StoreOrderDao/UnpaidOrderCancelJob/LegacyProductWriteServices，以及真实regressionStock所在 `crmeb/app/services/order/StoreOrderRefundServices.php`（仅按实际保存父订单版本保护旧恢复，不改退款计算/额度）；支付六源文件由上述独立范围负责。无新业务表、支付账户或attempt表，不运行已停止的拒绝/权限/冲突/并发/故障/恢复样本，不开放创建gate。

2026-10-15 本地源码检查点：七个取消/库存必要文件、七个旧支付入口文件（StoreOrderServices为共同必要调用点）及六个测试支付契约/配置文件已实现；独立源码复核通过。新硬删历史检查曾沿普通一致性读，源码复核发现可能沿用事务旧快照，已窄修为同一默认PDO当前锁读，保留原库存日志保护。PHP8.2首次检查20个文件实际exit0，逐文件源码前后摘要不变，证据 `artifacts/ms4-cancel-source/20261015-v1/lint-result.json` 摘要 `16f8890090aacd3c506dd103133ce7a4982f0cab90ec99da67b24d2bc61a485c`。此后普通CLI增加原生测试环境收据绑定，待与新增取消helper一并再次语法检查及独立复核；尚未执行取消普通场景，不记Task5或MS5完成，不开放创建gate。

取消普通验证源码已固定：新helper摘要 `835c6dd77e4ce5556258436ebe6f9fa8ffd8ad44cf1465d0939a764500130c77`，真实第二顾客顺序购买同物理SKU后，按独立当前库存加回本次reserve数量，完整核对五类可变业务表及其余观察事实、真实父U64、动作摘要、首次演员、状态、原保存项/金额/表单/发票/ledger与正常重放；另核保存0期限跳过、真实正小数小时新单自然到期及商家停用后原handler释放。两次实际attempt保持同App测试范围，无paid或财务写。普通CLI摘要 `d54c327b3372db3155b3d61952108cebf9932ebb424d953754098605253ed11a`，真实环境收据/进程开关闭集核对通过独立源码审，tiny仅新增独立phase名称，原PDO观察不被同名覆盖。最终CLI/helper两文件PHP8.2语法实际exit0，前后源码不变，收据摘要 `18c504e79545319c5f4a6f19442c0b5cd7c3bf5f982d726ad90eda272ac7b1d5`；加上前19个未变生产源，共21文件。外部final准备只增严格取消proof并保留全部原创建/会员/读取/持久收尾断言，完整逆变换、AST/help为源码检查，不表示已运行普通取消。

**取消普通内部实测结果（2026-10-15）：** clean来源 `1a1de7daf2adf10077b6d9b9da0810aad2730251`、新Run `202610150441573d0e33`、freeze摘要 `dd6535a0a13013b6bb2e59a2eb65d8a9ed16b14de68d2f2d758f7473d36eb3bc`；有限初始化六子步骤、四次迁移检查及订单CLI均实际exit0。订单CLI phase=final_source、VERIFIED_NORMAL_ORDER_INTERNAL，业务收据摘要 `b253756f5c569dfea19f41d5bcdecf4c6101697a99467e32bef3d68dbcfbdb4c`。原direct/两来源创建/两类既有会员计价/保存读取/持久收尾证明全保留通过；两新实际订单分别为另一原生顾客同SKU购买1件与正小数小时自然到期新单。真实顾客取消与既有orderCancel handler系统取消均各生成2条父reserve release、3保存项血缘完整、当前库存按本单数量加回、金额/表单/发票/cart/ledger与其余观察事实保持，正常重放无额外写；另一单1件预留始终保留，不能历史before绝对回填。保存0期限真实返回not_due且无写；创建后商家停用不阻止到期释放。两个同App测试attempt真实pending→closed、保存paid保持0，无成功支付或财务记录。

同Run关闭已实际完成：原cleanup helper实际exit0后按原ID移除；固定final关闭工具摘要 `e50c518c91b4c06d6852f273ecac5b40f16d59f8f8905ce4590c1661be0afc6b` 严格核完整普通证明及环境绑定，原MySQL/Redis正常stop，原3容器/唯一内部网络/6卷按原身份非force移除。Root独立复核源摘要 `954aa1dd725566e9658dfbe4ec188210bd111f17decd403a21724620916bc707` 实际6条双标签原生查询exit0/stderr空/count0，业务收据摘要未变。环境公开两值均由原生created容器核对并绑定同Run/source/CID/freeze。此结果限NORMAL_CANCEL_INTERNAL_TEST_DRIVER_ONLY及原普通内部链，不能据此完成Task5全部、MS4或MS5；HTTP/真实认证/DOM、真实支付/退款、跨进程attempt、wallclock daemon及用户停止的样本仍NOT RUN，创建gate仍关闭。两实现提交为 `cf285465c`（03环境说明）与上述 `1a1de7daf`（21PHP/09），均仅本地。

### Task 6: 商家订单操作

2026-10-15开工时只读盘点（当时尚未实施，后续实现及验证见下）：merchantapi与商家前端均无订单路由、页面或上述三项订单权限。后续读取须沿真实MerchantContext与EmployeeAuthServices重新授权，DAO强制同店保存V1订单和其保存项；新增独立商家投影，不能直接返回顾客/平台DTO。列表不含收货联系方式、表单或自由文本备注；详情只增履约必要保存收货快照、顾客留言和原定义表单，排除成本、支付标识、发票银行资料及内部ledger/reserve。成交单价、商品行金额和整行运费分别沿保存truePrice/sum_true_price/postage_price，不从当前SKU重新计价。商家端现有默认品牌已为“多商户商城”。

原delivery/splitDelivery/doDelivery已保护保存V1，旧equalSplit含float金额与旧pid/split语义，不能松开门禁直接套用。部分发货仍需先闭合稳定付款根、履约子单、同店归属、精确分币、真实原reserve及退款血缘、持久幂等与三端list/count不重复统计合同；当前仅盘点设计，未选择或实现该拆单结构。现无独立包裹表，是否需要额外结构须先以完整合同评估。真实paid写链尚未实现，不能伪改paid来验收发货；发货、拆单、售后与完整Task6责任均保留。

**Task6纯分配实现合同（实施前，通用多商户）：** 在独立 `feature/ms4-fulfillment-plan` / `worktrees/ms4-fulfillment-plan` 从clean `5fc4232d1a97c7cddc4841dd4a95be72cbba3bf2` 继续必要的金额前置能力；本轮前端构建所在主树保持其来源，不由此开启任何发货入口。Files仅新 `crmeb/app/services/marketplace/order/MarketplaceOrderFulfillmentPlan.php`、现有 `MarketplaceOrderSnapshotProjection.php` 的新方法、新普通纯计算入口 `help/audit-regressions/isolated/ms4-fulfillment-plan.php` 及本记录。

接口为 `MarketplaceOrderFulfillmentPlan::plan(savedRoot,savedItems,savedReserves,occupiedRanges,requestedQuantities):array` 与 `MarketplaceOrderSnapshotProjection::fulfillmentPlan(savedRoot,savedItems,plan):array`；另公开纯数值 `sliceMoney(savedLineAmount,originalQuantity,unitStart,unitEnd):string` 供相同算法复用和无身份数学检查。前两接口只消费调用者已经取得的真实保存V1/pid0根和完整原items/reserves，不读取当前商品/会员/商家配置，不建立认证上下文或写数据库。沿现有完整merchantOrder投影先核根/全部保存行守恒，再读取真实cart_info（JSON_BIGINT_AS_STRING）及marketplace血缘。原root/item PK与数量沿现INT/UINT界限，reserve规范U64字符串、不转PHP整数；同reserve的原item数量之和必须等于原reserve.qty，逐项核原order/mer/product/SKU/type/unique/原customer actor及父空值，不把同SKU两原行的金额合并。

`occupiedRanges=[{source_item_id,unit_start,unit_end}]` 使用原Q内0-based绝对半开区间；`requestedQuantities=[{source_item_id,quantity}]` 按原item排序，确定性选最低未占用单位，可跨多个剩余区间，工作量跟输入区间数相关、不逐件展开。原Q永远是分母；对整行整数分预算M使用 `F(k)=floor(M/Q)*k+min(k,M%Q)`，区间值为F(end)-F(start)。商品额沿保存truePrice×Q/sum_true_price，运费及origin沿原整行保存收费预算，成本沿costPrice×Q，基础额与优惠沿sum_price/vip_truePrice×Q；优惠按保存price_type分level/member桶，净商品额不再加回优惠，应付只为商品额+邮费。原保存金额核DECIMAL(12,2)，派生基础/优惠乘积用BC字符串可超过该列和PHP整数范围，不复用窄化的DAO money方法。

内部plan含plan_version、真实root_order_id、规范化保存来源摘要、按原行的reserve/绝对区间/数量与各金额、合计数量/净商品额/邮费/应付/成本/基础额/优惠及两优惠桶；来源摘要不冒充持久幂等键，真实writer仍须在原锁内重算并处理已占用区间。公开projection只暴露真实来源订单、原item ID、保存商品名称/规格/图片、计划数量/成交价/商品行金额/邮费和所选产品原保存表单，排除成本、reserve、区间与摘要；先核完整保存集，不把子集交给只接受完整订单的merchantOrder。NULL/0旧方法字节和当前V1旧发货门禁保持；本次不新增表/列或真实paid/发货/库存写，不把纯能力当履约子单已经持久化。

执行与验收跟踪：

- [x] 实现纯plan、严格保存来源/区间计算和允许字段projection，完成独立源码复核。
- [x] 实际PHP8.2语法和仅sliceMoney普通数值检查；包含1/2分钱分配、顺序部分分配守恒、同SKU两行各自预算及大整数派生预算。数学样本没有UID/JWT/演员/paid/PDO/App，不能构造保存业务行冒充真实实例。
- [ ] 后续用真实隔离保存根/items/reserves补完整plan/projection的普通验证；本阶段若尚未执行则如实保留。
- [ ] 后续真实发货/拆单writer、持久区间/幂等、三端根单统计、售后与支付前提继续按原Task6义务完成，不因纯计算勾选而签收Task6或MS4。

纯分配本地检查点（2026-10-15）：三个PHP文件已实现，独立源码复核无必须修正项；原Projection仅新增公开方法，移除该方法可逐字节回到原HEAD。Root使用既有PHP8.2镜像、network none、只读根与AUX源码、独立临时/tmp，实际三文件-l及普通数值入口全通过，原生exit0/stderr空，30条数学断言包含24固定值、5份原预算守恒及1次区间重组。检查前后全部三源bytes/SHA保持，精确临时容器名查询native0/空结果；收据 `artifacts/ms4-fulfillment-plan-source/20261015-v1/lint-math-result.json` 摘要 `519b9b5f7da5a866836ee61e84329dbaa6dafd650bb27066f4ff13c9e3b6de40`。这是纯数值计算及源码检查，未加载App、SQL、身份、支付或发货；完整真实保存plan/projection、writer、持久区间/幂等和Task6/MS4仍未完成。主交易工作树及其当前Run保持冻结，尚未合入此AUX变更。

后继真实保存分配普通验证合同（实施前）：在同AUX新增 `help/audit-regressions/isolated/lib/ms4-fulfillment-plan-normal.php`，现 `ms4-order-normal.php` 仅新增require/原源码闭集路径及在saved_reads之后、cancellations之前调用 `m4o_fulfillment_plan_normal(PDO,array direct,array service,array membership):array`；完整旧proof和断言保持。五个PK只取原committed、service persistent/temporary、membership level/paid已经成功保存的proof，独立PDO读取完整实际store_order、cart_info、原kind=reserve流水及ledger，不新增业务行、身份或paid。每根先核全量计划的真实血缘、逐原item预算和根合计；再按每原行1件后其余数量顺序计算，把前次实际已核区间仅放内存作为occupied，逐行与全量守恒、相同输入确定性重算。共用reserve的同SKU两原行仍分别计价，不能合池分币。

独立oracle只用于本批实际Q=2/1/2的小额保存订单，先严格核该数量及可安全整分界限，再按原预算商余逐单位枚举，核每个原绝对区间金额，不调用sliceMoney或从plan结果反推期望。paid会员资格场景真实保存邮费4.27/Q2必须2.14+2.13，另一原行2.14/Q1仍2.14；原level/paid总价122.18/113.12保留。每份公开projection均传完整root/items，按独立保存JSON逐层核exact字段及所选原表单，不公开成本、reserve、区间、摘要或支付信息。前后原23表事实及五根/items/reserves/ledger完整保持；proof为 `fulfillment_plans`，固定scope `NORMAL_REAL_SAVED_FULFILLMENT_PLAN_ONLY`，严格回绑原五PK，persistence_executed/payment_shipping_executed均false。该helper不执行发货、占用持久化、当前目录重计价或HTTP授权；真实Q≥5多空隙及实际writer仍待后续真实创建验证。未来外部新host来源闭集/wrapper须加此helper与严格proof且保留原全部门禁，不能用当前冻结host或当前Main Run执行未绑定代码；源码检查不代表该合同已经实际运行。

必要普通覆盖补充（实施前）：增加“真实首item取1件”的plan/projection子集调用，仍传完整保存root/items，只从实际选中product核返回原表单，因全量与前后两阶段均含两商品，不能据此声称已验证未选产品表单过滤。CLI被browser-prepare全局require，新增helper也会被传递加载；故同一来源闭集必要Files另含 `ms4-merchant-browser-prepare.php` 的source_contract extra与 `ms4-merchant-http.php` 的M4H_EXTRA，以及后继新候选host的ORDER_EXTRA/HTTP_EXTRA、interface-v1 source_extra_minimum与对应close/inverse。按真实新文件从15项到16项，不复用旧descriptor硬计数，不改原权限/业务/34步/25capture/9图/23表/3600秒或正常关闭要求。Main冻结运行和所有旧候选保持原字节；完成源码、语法、独立审查及新clean freeze后才可在新Run执行。

真实保存验证源码检查点（2026-10-15，尚未执行保存行样本）：新helper270行，CLI仅require/源码闭集/新增proof调用三处，两HTTP来源声明各加唯一helper路径；独立完整源码复核无必须修正项，移除这些接线可逐字节恢复原版本。每根设计5次纯plan、4次公开projection，共五真实PK，但本检查点未运行这些调用。Root实际使用既有PHP8.2无网络只读容器仅对四变化PHP执行-l，原生exit0/stderr空、各标准语法输出准确，四源检查前后bytes/SHA及LF保持，精确临时容器查询0；收据 `artifacts/ms4-fulfillment-plan-source/20261015-v2-real-saved-preparation/syntax-result.json` 摘要 `c6383f0f1bf7b222f194a23a2fa1a7574cae6625f7258f71a8b0d8166cb1b07d`。helper摘要 `0bdb4daa989d8db942e8277bfbe57e1520852b4c0fe993f7d98cefe190ee9dd8`，CLI `7e9933d28c028b94ccb956c3aa19dde061f2dbd19b58e69f1867e458d31ecff8`。未来host新增strict wrapper必须先完整调用原merchant普通wrapper，再严格核五case真实PK和有限字段，并同步order-authorize、http_original_complete及close三个消费者；逆变换记录这些必要调用差异，不能声称新stage/close函数直接字节未变。当前Main Run未读取此AUX改动；新候选、clean整合后的真实运行、Q≥5多空隙及实际履约仍待完成。

**Interfaces:**
- Produces: merchantapi 订单列表、详情（只含履约所需的收货快照）、发货（数量不超过未发货数量）、售后处理；权限码新增 `order.read`、`order.ship`、`aftersale.handle`（加入 `MerchantPermissions`）。
- 用例：O08（A 查询、发货、取消、导出 B 的订单或售后 → 404，零副作用）、O09（拆单后子单 `mer_id` 与父单一致）、O10（`accept_orders = 0` 时禁止新单，存量订单仍可发货、售后，不被删除）。

**本店保存订单读取实施合同（编码前记录）：** 新GET `merchantapi/orders`、GET `merchantapi/orders/<id>` 共用 `order.read`，保留真实AuthToken/Permission中间件及服务层ctx.require＋EmployeeAuthServices当前重授权，不接收客户端mer_id或UID。权限目录登记原三项码；BUILTIN_ADMIN随ALL供新建角色，既有角色不自动补权，BUILTIN_OPERATOR不默认扩权。读取不检查accept_orders，店铺停接新单不隐藏存量订单。

列表query闭集为page（默认1）、limit（默认20/最多100）、order_id（可选完整订单号）、paid/is_cancel（可选0或1）；顺序id DESC，返回 `{list,count}`。列表/count只读本ctx.merId的保存V1、is_system_del=0、pid=0根订单；不因顾客is_del隐藏商家的履约历史。详情按实际原生订单PK查询同一范围。根单与未来包裹消费者的完整扩展仍须在发货实施前闭合，当前没有制造子单或改旧V0查询。DAO在所有业务条件完成后应用MerchantScope，商品项批量按已验证oid及同mer_id读取，逐项再核保存归属；count与分页同过滤，不调用旧平台/顾客服务，不查当前商品/价格。

两接口共用独立merchant保存DTO：头字段闭集 `id/order_id/marketplace_trade_version/add_time/paid/status/is_cancel/refund_status/shipping_type/total_num/total_price/pay_price/pay_postage/items`。金额均保存两位十进制字符串。items闭集为原生 `id/product_id`、保存 `product_name/sku_name/image`、`quantity/unit_price/line_price/postage_price`；单价/行价/整行运费分别从保存truePrice/sum_true_price/postage_price取得，规格和图片沿保存productInfo/attrInfo，严禁重报价。详情仅额外提供 `recipient:{real_name,user_phone,user_address}`、保存 `mark` 与 `custom_form`，沿既有customForms投影保留原definition_sha256；不返回raw cart、UID、mer_id、成本、内部备注remark、支付/确认标识、invoice、ledger/reserve或当前商品资料。现阶段不展示有配送人电话复用歧义的delivery_*原包；后续按真实配送类型提供明确物流DTO。

Files：新增 `crmeb/app/services/marketplace/order/MerchantOrderInput.php`、`MerchantOrderServices.php`、`crmeb/app/dao/marketplace/order/MerchantOrderDao.php`、`crmeb/app/merchantapi/controller/Order.php`、`crmeb/app/merchantapi/route/order.php`；必要修改既有merchantapi路由入口、MerchantPermissions及MarketplaceOrderSnapshotProjection（保留所有旧方法）。前端修改 `template/merchant/src/api/index.js`、`router/index.js`、`layout/index.vue`、`domain/people.js`，新增 `domain/order.js`、`pages/order/list.vue`、`pages/order/detail.vue`。沿现Vue2/Element UI/真实商家session与hash路由，不新增依赖/认证旁路/全局store副本。页面路径orders与orders/:id均order.read，详情链接使用实际PK，顾客/平台旧页面与所有发货门禁不改。

DESIGN SPECIFICATION：用途为本店查单与保存履约资料只读查看；沿现有工具型后台的左侧208px导航/右侧内容、#1f2d3d/#2d8cf0/#ffffff/#f5f7f9配色和Microsoft YaHei/PingFang SC字体，不作视觉重设计。桌面订单摘要表格＋详情分区，小屏沿现760px断点保持父容器边界；自由文本和表单用文本节点/只读列表，不用v-html或可编辑输入冒充展示；图片仅真实保存URL。状态/空结果/错误均用既有notice/alert语义，实际操作仅查询、分页、查看/返回；按钮组双轴居中、平衡内边距，长文本可换行，不能靠裁切隐藏溢出。沿既有设计系统替代ui-design对新概念稿的不对称/字体现设要求，保留其可读性与质量检查。

验证责任：先独立源审、PHP8.2语法及现有商家source-check/真实构建；再新固定来源隔离Run，用实际原生员工/角色、真实credential login→authenticate得到MerchantContext（不new伪上下文或自签JWT），沿原普通下单夹具核本店分页/count/精确搜索/详情、金额表单原值与只读无副作用，另用真实空店普通空结果。HTTP与真实浏览器从正常登录入口检查新路由、查询分页和详情、必要320/390/1440布局及相邻导航；不注入localStorage/JWT或使用假响应。原普通取消证明保留，用户停止的样本不恢复。源码/构建/内部服务/HTTP/DOM分别记录，不能以某一层代替其余；未验证前不勾Task6，也不遗漏部分发货、拆单和售后责任。

真实商家普通身份的环境补充（实现前）：AuthSessionStore的BoundedRedisConnection明确要求默认cache为Redis，原m4o_app为File且未load auth，不能临时切facade/缓存或伪造ctx。下一专用普通订单角色额外真实声明公开 `PHP_CACHE_DRIVER=redis`，沿同一真实App从启动即load原cache/auth配置，Redis连接和auth.session_epoch/stamp_key/issuer只来自本Run既有audit_prepare私有App配置；原signing key及DML连接保持既有来源，不输出私有值。新增独立原生created容器的cache环境收据及source binding SHA，保留原两个测试支付环境值及其收据，不改旧Run/旧配置/旧证明。原direct、创建、会员、保存读取、收尾与取消全套普通断言在Redis配置下重新实际运行，不能直接沿用File Run为新环境通过。

普通helper新增 `help/audit-regressions/isolated/lib/ms4-merchant-read-normal.php`，与现 `ms4-order-normal.php` 的require/源码闭集/真实App初始化/事实表和evidence接线同属本合同必要文件。使用原订单真实mer_id为有单店与停接店插入原生role/employee/password_setup，真实setupPassword→login→authenticate；另建真实空店，均经独立PDO核原生PK与保存归属。权限仅merchant.profile.read/order.read，不改历史角色、不伪paid。读取预期以本店全部真实根订单为准（原persistent与keeper两单），至少核两页/count、完整号查询、paid/is_cancel普通过滤、真实详情与保存价格/表单、停接店仍读及空店0结果；比较读前后SQL事实，不输出口令/capability/JWT。必要追加原生夹具/事实表为已有marketplace_role/employee/password_setup，无新表。host下一版本仅做上述新增源闭集/真实环境绑定/严格结果wrapper和正常关闭扩展，原v4全部证明保留。

2026-10-15本地源码检查点：上述8个后端、7个商家前端和普通CLI/helper已实现并经独立源码复核。已有Node22执行商家source-check通过（31脚本语法、73本地import），不是构建或DOM验收。PHP8.2对后端8文件与CLI语法全exit0；源审发现CLI误从不存在的app配置键核签名键，已窄修沿实际AuthConfigGuard使用的Env getter，最终CLI与helper两文件再次语法exit0且前后源码不变；最终收据 `artifacts/ms4-merchant-read-source/20261015-v1/lint-final-wiring-result.json` 摘要 `fa934d33a8ae18b2b5f57c4b5dccc698fe7ef2d0c55c92b90d37e38f299a9571`。helper最终摘要 `26e151cd4991a7238ff1317daa81f7c8709f1409f44d6f10e3fa3250e43b5e48`，paid=1在实际未付夹具上只核正常空结果，不伪造已付单。此检查点尚未freeze/运行新Run，真实商家认证、SQL读取、HTTP/浏览器与完整Task6均未验收；后续真实构建和新Run结果另记，不以源码检查勾完成。

实际普通读取结果：03环境说明独立本地提交 `49dcb6af2`，18文件实现检查点为clean `38a9df05a4473ada0a8b4abc9c6f44ce803e7324`；新v5 freeze摘要 `08a5ac0deb1228ba367db3999c36ccfef26f50ac542d6a2eea489453a0cc9d3a`，实际RUN `20261015052122973093`。原prepare、有限initializer、四命令结构迁移、原生登记均正常通过；实际普通订单容器native0/exited/PID0，phase=final_source，业务收据 `evidence/ms4-order-normal.json` 摘要 `aaa7e0e0b1b45ff1df99dfc819dbfa025ae61b7538ef27f7aa27bb1af0e9207d`。原direct DAO、两来源真实创建、会员计价、保存读取、持久收尾及普通取消的完整v4证明在启动即默认Redis的新环境中均再次通过，原两个测试支付ENV与新增单公开cache ENV的native/receipt/source binding均闭合。新增三套原生商家角色/员工/setup经真实setupPassword→login→authenticate；本店实际2单、停接店1单、空店0单，两个分页与同过滤count、完整订单号、paid/is_cancel普通筛选（paid=1正常空结果）、三个实际详情及保存金额/收货快照/原定义表单均核一致，读取前后SQL事实保持。认证本身真实写入隔离Redis会话，不能将SQL不变误写成Redis不变。Root独立消费完整v5严格wrapper通过；范围仅 `NORMAL_MERCHANT_ORDER_INTERNAL_AUTHENTICATED_READ_ONLY` 加原普通内部链，不是HTTP或DOM验收。

同源商家前端实际离线构建：仓库外 `artifacts/ms4-merchant-console-build/source38a9df05-v1/` 使用已有Node22.23.3/npm10.9.9及复制的新缓存，npm ci --offline与npm run build（含prebuild的31脚本/73import检查）均native0，无联网安装或换包。43个源码文件与冻结源逐文件bytes/SHA一致，25个dist文件；HTML资源与CSS相对字体解析均保持/merchant/，标题为“多商户商城 · 商家后台”。原builder尾部因两份清单JSON键顺序不同而误报exit1，其FAIL收据、源码和原生日志完整保留；独立postcheck对同一批产物按精确路径闭集及逐文件bytes/SHA复核native0，未重跑ci/build或放宽实际文件/资源要求。新收据 `build-postcheck-verified.json` 摘要 `7b670d296d4b9b7113c862392d1773a63402f153ad821bdd9646ddfd29cb4b8f`，Root另核全部dist及实际HTML资源。旧依赖的EBADENGINE/deprecated及两条包体积warning保留；未把构建当成真实页面或登录验收。

同RUN关闭已真实完成：原authorize-order-cleanup helper正常exit0并按原ID移除，固定v5关闭源摘要 `9e18ff39012675e78c31949ac3889bb7a7f805586c35eec9865ffc3284727924` 核完整新旧证明及同Run/source/CID/freeze后正常stop原MySQL/Redis，原生进程和容器均exit0/PID0，再非force精确移除原3容器/唯一内部网/6卷。Root独立检查源摘要 `7f4689f65430edb9f8720777b06a130a435353de351e70badd5bbf582cdb815d` 实际6条双标签原生查询exit0/stderr空/count0，保留业务收据字节不变。本RUN已关闭，不重开；HTTP/浏览器320/390/1440布局及相邻导航、发货/部分拆单/售后、真实支付退款、跨进程attempt及wallclock daemon仍未验收，用户停止的样本仍NOT RUN，创建gate保持关闭，不勾完整Task6或MS4。后续发货只读设计已比较store_order履约子单与独立shipment模型；00的pid树及10的O09既有拆单要求仍是原约束，尚未选择或实施新表示及迁移，不能以没有商业子单真空通过O09。

**下一普通HTTP/DOM实施依据（编码前）：** 复用已核新merchant构建和当前CUA的CDP/viewport能力，保持原正常CLI、商家只读helper及公开2/1/0 proof不变。全新profile先原三角色跑完整v5并正常退出，再有限bootstrap/独立DML子进程按原订单真实mer_id生成三套HTTP-only角色/员工/setup及私有账号密码，正常设密后交付，不复用已关闭Run或已消费capability。新建普通非会员顾客、真实地址及同owned商家单SKU商品，初始stock20/sales0，每单qty1，逐笔真实setCart(false)→确认→CreateServices::create→持久收尾，共20单；不直接插store_order、不造paid/库存回填。原取消配置tiny deadline须按实际行条件更新为JSON字符串0并精确清缓存/getter核回；只影响新订单保存cancel_at0，不改旧期限或已通过proof。原owned两单及停接店/空店保持，新分页为22单的20＋2；独立PDO核20根/20项/20reserve/done1、20→0库存及0→20销量、保存金额与原单事实。新增商品首次保存时绑定冻结的真实同源合成PNG，原空图片快照不修改。

新库前置和表示合同沿03专用profile：原6卷加4个HTTP专用卷；App/UI只在原普通CLI native0及proof通过后创建，最终5身份。原普通源码与守卫不改为多角色；新HTTP准备/源绑定/角色矩阵/ready及关闭单独校验。public快照来自当前冻结完整目录，仅增加Run安装标记、合成PNG及已核merchant产物，主树不写运行文件。引导md5使用专用migrator，DML演员/订单另起真实子进程，HTTP App始终仅DML。私有桥从实际已正常退出的bootstrap原CID读取有限私有actor文件（原生文件管道须实证），先取内存再精确移除helper，不exec已退出CLI，不给App挂浏览器密码卷。

浏览器正常链：真实员工账号/密码登录→实际me→点击订单菜单→page1/limit20/20行/count22→实际btn-next及page2/2行→btn-prev→完整号/paid/is_cancel普通筛选及paid1空态→按实际订单号所属行查看nativePK详情→返回/刷新/相邻本店信息。真实退出后分别登录停接店与空店，核1单可读和正常0结果；不加拒绝或跨店样本。通过实际CDP requestId/loadingFinished/getResponseBody绑定同origin/method/path/query、HTTP200/business200及独立SQL DTO预期，不能拦截改响应或注入token/storage。响应体入私有通道，公开仅必要状态/字节/摘要；认证响应不输出完整凭据或完整秘密摘要。订单列表、详情和空态在320/390/1440实际尺寸核外界、表格内部滚动、控件双轴居中和保存表单/新图片，截图只在无密码/token URL的页面，真实字节/SHA绑定步骤并人工看图。只关闭owned tab、reset viewport；SQL事实与正常HTTP组停止/监听消失、五身份/网络/十卷零残留闭合后才记录本轮结果，不能据准备源码/构建标HTTP或DOM通过。

Files：新增 `help/audit-regressions/isolated/ms4-merchant-browser-prepare.php`、`lib/ms4-merchant-browser-normal.php`（含有限私有文件与原生DML准备函数）、`ms4-merchant-http.php`（HTTP supervisor/正常control/有限private ingress和最终SQL审计）。必要时按明确用途拆分同目录有限辅助文件，先在本合同补路径再实施；不修改生产API、正常CLI或前端以迁就夹具。仓库外新增v6 HTTP profile的compose/host/initialize/public-copy/nginx/browser步骤/私有桥/close及完整inverse，旧v5和旧MS3原文件不变。准备、语法/独立源审、真实新Run的HTTP、DOM、正常关闭逐项留证；本合同未代表上述步骤已执行。

HTTP控制补充执行合同（仍为准备、未运行）：有限private ingress增加只读`supervisor` getter，按当前请求原nonce返回实际响应或not_ready/null，不把getter native0当ready。stop正常终止原HTTP组、核无成员/8000监听并写响应后，原supervisor保持同PID空闲且禁止再次start；桥读回真实stop后才按原CID正常SIGTERM结束supervisor。运行窗口固定实际time()起3600秒，不能重启或改时钟续期。实际CUA截图返回JPEG，整条保存和接收链用.jpg/image/jpeg并核原字节/SHA/像素，CSS viewport/DPR另核；合成商品PNG不变。桥仅在本Run原bootstrap实际exit0/PID0后原生docker cp→内存tar单文件读取成功才发布独立私有交付公开收据，明确HTTP_DOM_acceptance=NOT RUN，不打印actor值或保存宿主凭据文件。浏览器最终覆盖计划、私有command/capture/DOM完整核对及公开终态合同尚待补齐，v6候选运行/close总门禁保持false；仅语法或源审不能消除此缺口。

HTTP准备源码检查点（2026-10-15，未freeze/未运行新Run）：上述三个新PHP已写入工作树；Root用固定PHP8.2镜像仅-l，三个原生退出均0/stderr0，检查前后源码字节相同。正常CLI和merchant-read helper与HEAD字节仍相同。首次syntax容器未声明可写/tmp，原生254及失败收据保留；后继仅给语法容器加私有tmpfs，未加载App或业务，完整五项收据为仓库外`artifacts/ms4-merchant-http-preparation/source-syntax-root-v2.json`（含浏览器步骤和私有桥的Node22 --check各0）。夹具helper获得独立财务/native段源审；私有桥完整binding/public冻结合同与原字节JPEG保存源审通过。HTTP完整源审及最终浏览器覆盖核对仍待补，不能据语法签收。

仓库外`candidate-internal-v6-merchant-http`首版仅为有门禁的来源候选：原五文件/关闭源可完整逆变换回固定v5，原38函数AST、六Python AST、两个help原生退出0与合成PNG CRC检查已记录；HTTP_PROFILE_SOURCE_READY仍false。该版后继必须另建，补入interface-v2、私有桥、实际HTTP/DOM/SQL和正常停止终态核对，并修正镜像固有未发布80/tcp:null表示及有限DML子进程真实退出码保存；不得覆盖首版或打开半成品运行入口。本轮仅语法容器运行，App/SQL/HTTP/DOM/新业务样本均NOT RUN；owned空白浏览器页已关闭，无新HTTP进程或业务Run。原MS4发货/拆单/售后及MS5/MS6责任继续保留。

后继普通停止源审：新HTTP入口的无条件warning handler会把原harness以@读取已退出PID的正常ENOENT路径误判为失败。窄修只尊重error_reporting中已抑制的severity，其余仍抛固定脱敏错误；原harness/PID/组检查不改。实际新HTTP摘要`6383c2d3e4250d9a4d24261fd458b167553c24722d0b3fee6664cc28c7246c53`获完整独立SOURCE PASS，Root仅重-l该变化文件native0/stderr0/字节保持，收据`source-syntax-root-v3-http-handler.json`。三新PHP可作本地准备代码检查点；全计划HTTP/DOM和最终关闭仍未执行，不打开运行门禁。

最终普通浏览器覆盖合同（实现前）：以仓库外`interface-v3-coverage.json`（设计摘要`29c48a1c431366ed40b699fb2651149d369c468979011feb9dfafadd428be4a5`）固定34步、25主接口响应和9张320/390/1440×900原始JPEG。owned沿正常登录→列表20/22→三尺寸→next两行→prev→真实完整号→未支付且未取消→取消筛选→已支付正常空结果→清筛选→真实新单详情/刷新→三尺寸→返回/刷新列表→本店信息→退出；stopped沿正常登录/唯一保存单列表详情/返回/退出，empty沿正常登录/0列表/三尺寸/刷新/本店信息/退出。不加跨店、拒绝、冲突或其他停测样本。N/P必须来自真实新20单且位于默认第一页，S/Q来自停接店唯一原保存单；不猜ID。

HTTP supervisor在实际source/oracle后、ready前一次生成私有plan及随机唯一步骤nonce；新增只读plan getter。write command必须与计划下一完整消息精确相同，前步已有实际ACK才允许推进；COMMAND/PLAN的capture.query明确编码JSON对象（空{}），内部严格数组及浏览器精确比较不放宽。完整34份结构化DOM ACK核实际列表单元格、分页active/disabled、详情金额/收货/留言与逐组字段、重复标题和原字符串0；definition hash/product_id/status仍由HTTP+SQL核，不伪称HTML可见。新商品真实同源PNG须加载32×32；原空/外域快照不改，不声称八种表单或表单图片已视觉验收。详情刷新等真实loading消失和保存订单号出现，截图使用fullPage原JPEG，CSS viewport/DPR和编码像素分别记录。人工实看九张原图后才写绑定原nonce/scene/摘要的私有review；手机表格实际横向可达接口未定前仍保留缺口，不以DOM scrollWidth假代操作。

最终audit核原计划/25真实capture与独立保存oracle/34 DOM/9实际JPEG与9人工review，另保留独立23表SQL收据和最终全source/public重核，owned tab确已关闭且viewport reset后才输出有限公开HTTP_DOM_AUDIT。原始鉴权响应及其完整摘要继续私有，公开仅计数、覆盖结论、来源和截图工件；本轮不代表发货/售后/支付/全库验收。宿主桥核返回公开闭集/身份/原截图文件后create-only写`evidence/ms4-merchant-http-browser.json`，原App不能写宿主Run。真实start/stop仅从同nonce supervisor响应发布独立收据，pending不算stop。Files沿三个新PHP和仓库外browser/bridge/host/close；不增角色、表、控制目录或生产API。最终候选另建，不覆盖source1；所有源/工具/实际终态缺口补齐前HTTP运行门禁保持false。

v3来源澄清：当前正式CUA文档中的cua/dom_cua wheel属性在新owned空白页实际均未开放，不能借内部接口绕过。横向验证选已文档化Locator.press普通方向键：列表聚焦真实“查看详情”按钮，详情聚焦实际表格scroll container，逐次真实ArrowRight/read-only DOM读回，只有实际到最右且末格横向落在wrapper内才算可达；同目标ArrowLeft读回到左端后取原全页JPEG。每向最多64个正常按键，若实际无效保留未完成而不造scrollLeft。空列表/实际无overflow只记实测不需要滚动；普通焦点引起纵向滚动允许，分别记录scrollX/Y。新只读timezone offset支持保存日期与浏览器本地格式比较。source能调用普通键盘只满足允许开始真实验证，不代表可达已通过。公开终态闭集另增horizontal_table_reach_verified；截图条目仅scene/path/bytes/SHA/viewport/pixels。固定scope_limit为NORMAL_MERCHANT_ORDER_HTTP_DOM_ONLY_OBSERVED_23_SQL_TABLES，两未运行字段固定NOT RUN；详细有限边界见同目录interface-v3-clarifications.json，仍无真实HTTP/DOM结果。

正常启动观察澄清（依赖实现前）：HTTP serve需先真实核完整来源/生成计划才发布source-ready。宿主每次仅作一次原AppCID的固定文件cp；文件尚未取到时，只有原创建/source身份仍完整、该同CID实际running/PID>0/restart0才记录PENDING_OBSERVATION及真实native结果，后续仍观察同一实例，不能因观察未就绪重启或换CID。只有实际cp0/真实source-ready内容/绑定与原supervisor身份核过才记READY，不以容器running代替。最终native子进程被正常SIGTERM终止可有真实143/signal15，proc_close可能-1；原值保留且拒KILL，不能伪称child自然exit0。原supervisor/App容器最终正常exit0/PID0另外原生核验。

v3源码检查点：新HTTP完整实现摘要`c0c808abea55c30537fb34593420f84ee72c4e13094c5cff972e9ea3b56deec8`、浏览器producer`6e22ab01211e1454f3eae4f937c96c7b50ed82cf6434edf346410c4600672347`、私有桥`b9fd27519c0b91f5a91fbc557679b0ce908a71d8fc01d06c16484200818c1c68`均已获独立SOURCE PASS；Root分别只运行PHP8.2 -l和Node22 --check，3项native0/stderr0/检查前后字节保持，实际收据`source-syntax-root-v4-v3.json`。源审发现的空列表pager应active1已按冻结ElementUI真实行为窄修，未改业务API或停止样本。此前普通业务Run状态保持已关闭；本轮没有新App/SQL/HTTP/DOM运行或视觉通过，外部final候选的实际stage/五身份十卷关闭源仍在独立核对，不据准备完成勾Task6。

普通验证来源准入（2026-10-15，尚无运行验收）：外部final三草稿builder`8a1b572ec2e63bd937e004cfd5059fddb6ed296e2b760c2d1b336dc48ea38166`、阶段扩展`3aee1981e607902c03da73a101acc4246f7ef1a09f90ced748e9aacf5685a264`、独立关闭`00f1d4a2392f68186f4b5fc0b4da70c487b4ce26aff2a84a845312b0c6b9ce72`已双独立SOURCE PASS。Root实际执行默认纯内存派生native0：完整inverse至source1/v5、38原函数AST及原业务verifier保持，原候选/所有旧Run均未修改。依已固定4份设计合同、2个浏览器模块和HTTP源码检查，允许在未生成final的builder中仅将HTTP_PROFILE_SOURCE_READY改为true并记录完整inverse；README与source记录明确这只是可开始普通验证的来源准入，不是HTTP/DOM或任何业务PASS。所有原身份、私有交付、全normal proof、34/25/9真实核对和正常五容器十卷关闭门禁保持。正式candidate生成后还须实际静态核对及新initializer/public-copy语法，再freeze clean HEAD并启动新Run；不得把此准入用于旧Run或真实环境。

普通HTTP来源实际准备结果（2026-10-15，未进入业务）：final候选15文件已生成，host摘要`04afca0ea72ad10bb433295ea58c3752c03acb0a02a39df3f3beb759aa266ed7`。Root实际静态核对native0，完整source1/v5逆变换、38原函数AST、两help通过，收据`v6-final-source-check.json`摘要`8327e7ddcddf7c5b5bc44138f3ab73c7cd050207fd345dee714d45a2eb8e79a7`；initializer和public-copy两PHP仅语法检查均native0/stderr0，收据`v6-final-php-syntax.json`摘要`4b41afa9545e2cf1e7edb5fa64f4610a28d5f81ebe173bd0576754cd13f2bfb0`。冻结clean来源`0e04a933eedb9631ce8489b7745b4005e778b96c`，freeze摘要`0f9ef95cf2fe39ba1b131a3e3ce3146e24d85dd0bbfd79ae3cca4554eee57058`；新Run`20261015071206ff281c`的prepare实际失败，bootstrap正常终态native1/PID0，精确诊断`HTTP_EMPTY_PRIVATE_VOLUME_MODE`，MySQL/Redis仍为created且从未启动，未进入SQL/App/HTTP/DOM。原CID原生cp空http-runtime的TAR元数据实为0700/UID0；同PHP8.2最小普通chmod观察native0，修改后立即读为缓存755，清该路径stat缓存后真实700/owner等于euid，收据`v6-first-prepare-mode-diagnosis.json`摘要`2974381e86cfd752285ba5095100a2fc8edd29e546c80f7023c687d682a1d199`。这属于初始化producer的文件状态缓存读回错误，不是业务权限或数据库错误。

该失败Run已按固定来源及原context/四失败收据摘要逐项核验，原bootstrap退出1、原MySQL/Redis未启动、原3CID/10卷/空端点内网/无外部消费者后非force精确移除，没有start/stop/kill或SQL。有限关闭源`normal-close-failed-prepare-20261015071206ff281c.py`摘要`e34cedbb5de6513d1afa2a1df4d070dd0cb34092bd5086009e9c9a8125edc45a`获独立SOURCE PASS，实际native0；关闭收据摘要`5789315039f03267eca6c39b976e4d248ec0cc5da559b29f57b4c7791a3f930c`，六双标签原生查询全0，Root另独立六查询全0。原FAIL、shutdown、created、terminal、context和freeze字节保持，不重开此Run。

后继最小修复依据（实施前）：另建-r2来源，不覆盖原final/失败Run；只在initializer的四卷空目录chmod成功和六control子目录create-only mkdir成功之后调用`clearstatcache(true, $path)`，再执行原0700及publisher UID严格读回。空目录、真实mount/Run归属、chmod/mkdir成功、原mode/owner标准全部保留；不将void调用塞入布尔表达式，不使用全局缓存清除。builder、candidate、close、inverse和静态收据均新路径；close只更新实际候选位置，完整逆变换仍回source1/v5，额外核两hunk可逆回旧final initializer。其余host业务、四设计文档、两浏览器模块、public复制、34/25/9及正常关闭合同不变。实际静态/语法核对后重新freeze clean来源并用新Run验证；本次失败和修复准备不记HTTP/DOM、完整Task6或MS4通过。

后继普通验证实际结果（2026-10-15）：r2 最小目录状态缓存修复已通过独立源审、完整逆变换及 PHP8.2 语法检查。冻结 clean 来源 `dc07eb9ef3c0d9ba83478f5fc80daa1234b91f26`，新 Run `20261015072823be1023` 的初始化、正常结构迁移及完整内部订单链实际通过；普通订单收据摘要 `d31e35ddc506d0b893c73bf8ed26d87f7b077a993c5365ec8637de17474a3024`，native0/final_source，保留真实下单、会员价格、保存资料读取、持久收尾、取消释放及三个原生商家账号的正常认证读取。首次 HTTP 创建命令遗漏既有清理授权前置步骤，尚未创建 HTTP 资源即停止；随后实际执行原授权 helper、核其正常退出并移除，观察记录保留，未改业务源码或断言。

随后 HTTP 准备的安装校验及 DML 应用账号检查均成功，但实际分页订单准备在 `browser_create_1` 出现运行警告，子收据为 FAIL/native1，摘要 `d32860b8480dec4c91e294e941bedf52d2448f3473e9b831184fdf269a372a19`；父 FAIL 摘要 `ab3f7f861e1c458b480dc0743188b360b7bd31e90c9d3ecd36c6d76b8e4ddc09`。当前入口 handler 覆盖了原始警告文件和行号，故具体触发原因未知；该 phase 覆盖购物车、确认、创建及保存核对，不能据此断言订单尚未提交。父 children 为空源于失败子调用未正常返回，独立子 FAIL 已真实落盘。App/UI 始终 created、未启动，HTTP/DOM 未运行；不归因于 Redis、图片或某个被抑制调用，不将安装成功当成准备通过。

该失败 Run 已实际关闭：有限关闭源摘要 `bb69ba08a78cc971570badef4d0ecb51f61fbfc4db27bf7aa88b92d57de65925` 经独立源码复核；仅原 MySQL/Redis 各一次正常 stop，原六容器、原内网和十卷按原身份非 force 移除。关闭收据摘要 `ad04799da7091158ad0ccb4a58809de1992d99134811045a735ec16f3298041b`；Root 另实际执行六条 project/run 标签查询，均 native0/stderr空/count0，独立收据摘要 `fab3b1b54d02d2eea82e3cf368987d492317bb9163695b38a3d34886e3ead50a`。原普通 PASS、父子 FAIL、上下文和 freeze 的 25 份原字节保持；可能存在的部分 SQL 写入随专用卷销毁，不冒称事务已回滚，不重开该 Run。

后继诊断实施依据（实施前）：仅修改现有 `ms4-merchant-browser-prepare.php` 和 `lib/ms4-merchant-browser-normal.php`。入口仍让所有原警告失败，只记录真实 severity、文件 basename、行号及当前 error_reporting mask 是否包含该级别的布尔值；不保存原消息、参数、trace、完整路径或身份信息。用本地异常对象身份关联来源，仅当前捕获的同一警告异常附来源并分类为 `PHP_RUNTIME_WARNING`，避免旧警告污染业务断言。正常购物车、确认、创建、独立读回和保存核对前细化 phase；成功终态仍为 final_source。所有金额、库存、20 笔真实持久购物车下单、正常认证、HTTP/DOM 和关闭断言不变；不跳过被 mask 排除的警告来取得通过。先实际语法检查及独立源审，再以新 clean 来源和新 Run 获取真实诊断；本次仍不勾 Task6 或 MS4 完成。

诊断源码检查点：入口摘要 `83de4db4d8586fcb66c29cf7338c2883af54e637f719ced7ce2a623b2f99bdc1`、helper 摘要 `bb373733d294fbeb50ba4568fa5c5a6770f9b5f6a47178f85d0e0584bceb36ab` 已完成上述最小补充并通过独立源码复核。helper 完整逆回原版本，入口成功业务段和收据写入尾部原字节保持；Root 对这两文件实际执行 PHP8.2 语法检查，均 native0/stderr空/前后字节保持，收据 `artifacts/ms4-merchant-warning-source/20261015-origin-v1/lint-result.json` 摘要 `2954f518e759a16ad6bf9bf90cb6a99446b0954ca3657d6222537c3bf9a15476`。reported=false 仅表示当前 mask 不包含该级别，不能单独证明使用了 `@`。这只是诊断修复，尚未证明原运行警告的原因或业务已修复；新 Run 的正常验证仍待执行。

带来源诊断的实际验证（2026-10-15）：冻结 clean 来源 `2f8cbaee6935b56d6b88989e584bf9da5e10c462`，freeze 摘要 `6913d83d7ce4961b3c68948ccd85c2656e85602975dce81bcf392f8673e44c4d`。新 Run `20261015082622fd1758` 的初始化六个原生子命令、正常结构迁移四个子命令及完整内部订单链实际 native0。普通业务收据摘要 `e42a29cd21e257faf268555416839af814935edcc7c83af75510220d1547dc2a`，已独立核对真实创建、会员价格、持久收尾、保存读取、取消和三商家正常认证读取；10,806 个源文件、两项测试环境、Redis 环境、原 created 与 terminal 均完整绑定。随后原清理授权 helper 实际正常结束并移除，才创建 HTTP/UI；这两角色始终 created，未启动。

本轮实际子 FAIL/native1 摘要 `831dcccce2dedff34c7a32e559920d27558be8f12e117353850a45935a356f8e`，phase 为 `browser_create_1_verify_order`，分类 `PHP_RUNTIME_WARNING`，真实来源为 `ms4-merchant-browser-normal.php:214`、severity2、reported=true。定点核实新 helper 的库存流水期望首项误读不存在的 `uid` 列：MS4 迁移、生产 DAO 字段闭集及实际 reserve 写入均使用 `actor_type=customer` 与 `actor_id`；顾客 UID 另在保存订单、幂等记录及规范动作摘要中绑定。原正常校验也使用 actor_id。本次首单 create 已正常返回，保存订单、ledger state1/post_commit_done1 的读取先于错误通过；失败属于新独立验证器的字段假设，不是该单创建失败。其他流水字段与迁移、生产写入及旧正常检查一致，后续20单与HTTP义务仍待实际验证。父 FAIL 摘要 `6aa1dd0da30ce31392e4624194b78f56f8d97f46c779fd428d081f51db9f7a5b`，安装六项仍成功，不用其替代准备通过。

该失败 Run 已实际正常关闭。有限关闭源摘要 `292707a9e9b90b73601ff33c9d91dbeb4539976c948f0f7d798963c06d908b72` 经独立增量源审，其余七个函数 AST 与已审关闭源一致；仅原 DB/Redis 各一次正常 stop 后，原六容器、同网、十卷按原身份非 force 移除。关闭收据摘要 `894e0efcee528c671152423d3272187aa762dc62d47fa2e1b73724ab7da7a0b9`，Root 独立六条标签查询 native0/stderr空/count0，收据摘要 `c49a3728993099ce63d1f33596eaf7477ca4ab2586f8f81368baf71a113c8cee`。25份原 PASS/FAIL/上下文/freeze 字节保持，不重开该 Run；HTTP/DOM 仍未运行。

库存核对修复依据（实施前）：只修改现有 `lib/ms4-merchant-browser-normal.php`，移除对不存在 movement.uid 的列期望，改为显式核对保存 order.uid、ledger.uid、customer actor_id 均等于实际夹具顾客 UID。保留已有 actor_id、actor_type、商品/规格/数量/库存销量前后值、原动作摘要中 uid 和所有金额、20单、分页、只读事实断言；警告仍严格失败。生产业务、数据库结构和初始化权限保持既定合同。实际语法检查及独立源审后重新 freeze clean 来源，以新 Run 验证整批准备及后续真实 HTTP/DOM；不把本次定位或源码修复标为 Task6/MS4 完成。

库存核对源码检查点：helper 摘要 `26cb691f4f9fa510fef7ee49a0b63b84a536e821032f802eeab91877d5434afe` 已按上述依据修正，独立源审通过；仅三行显式身份链和错误列期望的替换，其余业务断言可逐字节逆回修复前 helper。Root 实际执行该文件 PHP8.2 语法检查 native0/stderr空/前后字节保持，收据 `artifacts/ms4-merchant-warning-source/20261015-stock-customer-v1/lint-result.json` 摘要 `623869fc76de93aef43320aa1f3fc7d5bc3d4adc95584d83fe1ad35e83755930`。这是验证器修复的源码检查点；修复后的整批准备、HTTP 与真实浏览器结果尚待新 Run，不以本地提交替代运行验收。

修复后的实际普通结果（2026-10-15）：冻结 clean 来源 `5d3a2f8a0e210140f29b6a7501640e46813d501e`，freeze 摘要 `11ab5549103449c2308d228a62e5cf1d5eec79c8ccb6f873a810e48c6744c264`，新 Run `202610150910096935b1`。初始化、四次结构迁移及原完整内部订单链均实际 native0；订单收据摘要 `6021062172a7634f5d1e652a4d134e11615d29028936878e8fc3da2961a00b86`，scope `NORMAL_ORDER_INTERNAL_ONLY`、phase `final_source`。原真实创建、会员价格、保存读取、持久收尾、取消释放及三原生商家正常认证读取完整保留。随后真实正常清理授权 helper 结束并移除，才进入 HTTP 准备。

本轮 HTTP 准备首次完整通过：有限 bootstrap 实际 native0/PID0/restart0，父收据摘要 `2b8638efc2f9b5eb6e095aba455a2610f39dc9193c6e52596467a47d66e5a10b`，真实 audit_migrator 文件校验生成1218项并核当前源码版本，随后切回 audit_app DML-only。业务子收据摘要 `7454938d2a10709e139ac28f6e7fd1818e8fc914df67ff42cee2c649f0402bfa`，`VERIFIED_MERCHANT_BROWSER_PREPARATION_ONLY`、native0、phase `final_source`；20笔新订单全部真实创建并收尾，保存 order.uid、ledger.uid、customer actor_id 的身份链均通过，库存20→0、销量20，三店订单22/1/0、分页20+2、全部未支付且取消期限0。原普通事实保持，源码与 public 闭集绑定核过：2536原 public、25 merchant dist、marker和PNG共2563文件。Root 从原正常终态 bootstrap 经真实 Docker 文件管道只交接至内存，再按原ID移除；未把凭据写入宿主文件，后续已清空演员内存。该准备结果不代表真实 HTTP/DOM 通过。

HTTP 启动实际未通过：原 App 和 UI 各一次 start 均 native0，两个同CID真实 running/PID>0/restart0；其后严格 collector 因 UI `NetworkSettings.Ports={"8080/tcp":[]}` 拒绝。虽然 HostConfig 声明精确 `127.0.0.1:18974→8080`，真实 docker port 返回1/空stdout，Windows该IPv4回环监听数0，普通回环TCP连接10061拒绝；Root 观察收据摘要 `39b1f7e41721e8fa8601c1d9048c80bad0a0e98c986d63d06db7cf81bc1b47f6`。这是本机该内部网络配置下页面端口未实际发布，不是订单 API 的失败响应。Root 沿原 AppCID 获取真实 source-ready，摘要 `e087239650fb5ad28f4177072c72a7ba197f9b8a6db9a3719b715a485d49d0c3`；原 runtime getter、控制目录和 /proc 观察证明同一个 PHP supervisor、无 active请求、请求/响应文件各0、8000监听0，收据摘要 `8c979416f40ad43e8e888da393348c94db6a66fbcad5df9f24c5f9b2c0d307a5`。该 getter 沿既有只读来源/身份门禁读取 SQL 哨兵、权限和 Redis；不能称整个观察“完全无SQL”或“不读取应用私有配置”。无业务写、HTTP业务子进程或浏览器操作，34步及视觉验收仍 NOT RUN。

本轮正常关闭另遇实际默认信号差异：固定关闭源 `normal-close-failed-http-start-202610150910096935b1.py`（摘要 `5653f3543e4f71c60c3447ae6341f1fa7c45859b50790d11e7b6f043f109c59c`）通过 Root 和独立源审，绑定30份原公开收据、五个原CID、同内部网和十卷。首个原 docker stop time=-1 进程37532持续等待；原生核实 PHP-FPM 镜像继承 Config.StopSignal=SIGQUIT，而 CLI supervisor 仅注册 SIGTERM。未改正在运行的关闭源或重发 stop。另建有限 `normal-term-ready-supervisor-202610150910096935b1.py`（摘要 `d6bd3ef93e736b524e1df24b35c542257e2a9fa53078b32f02a88e2f1c50f538`），经独立源审、Python AST 和固定 PHP8.2 仅语法检查后，以 create-only 收据锁定单次执行；核原停止进程完整argv/创建时间、App原身份/PID/starttime及实际TERM handler、无child/控制请求/8000监听，再仅向原PID1发送一次正常 SIGTERM。sender真实 native0/原进程信号接受，收据摘要 `16e99057ee6933ae6f0f5135a3d37bcb5ff66ae3608ca017611cb9530313ca0f`；随后原停止句柄37532真实 native0，原App真实 exit0/PID0，才继续 UI/MySQL/Redis 各原停止句柄的正常退出。不能声称原关闭源在没有这次修复时已自行成功，也不把发送成功当作退出证据。

关闭实际完成：原五容器、同空端点内网、原十卷均非 force 精确移除，关闭收据摘要 `7bd53e86687ef4dface2fe97fd208ae140bd5cff34452bf71a9963a9f41022fd`；Root 另执行独立六条 project/run 标签原生查询，全部 native0/stderr空/count0，收据摘要 `58ec95b46a2cb33e3647dec429f230ef709df1386e2c473dceb2194345a61029`，30份原 PASS/启动失败/上下文/freeze 字节保持。独立零残留记录另绑定实际TERM修复、原停止句柄及原App终态，不把本次操作写成无修复关闭。追加获取 signal-stop 文件的只读观察在原生cp正常门禁失败，包装进程exit1且未保留原生cp细节；该文件读回仍 `UNVERIFIED`，缺口摘要 `92a6ff166c0a6a6dec8ae01c1473c50bfb490160f5e93d92c9c8cd0578087f95`，不据此猜文件不存在。实际正常退出与资源清理有各自独立证明。本Run已关闭，不重建原运行；HTTP/DOM、发货/拆单/售后、真实支付退款及原未验收范围保持未完成。

后继 r3 实施依据：沿03新增的“仅UI双网”合同修正测试入口。原v5三角色阶段仍精确单内网；完整普通链通过后，HTTP阶段才创建本Run frontend bridge，UI双网，App/MySQL/Redis/CLI/bootstrap仍各自仅原backend内网，UI只持有只读公开快照与配置、只发布固定IPv4回环端口。frontend关闭 masquerade 不等于已证明无外连；不为修复测试入口更改宿主全局网络或连接业务实例。HTTP App显式 stop_signal:SIGTERM，并用实际原生观察核其有效值。新builder/candidate/inverse/关闭源使用新路径；HTTP专用 collector、两网 ownership/global consumer、manifest拓扑、原生端口及关闭证明同步设计，原单内网迁移/CLI分支和业务断言不放宽。沿[Docker frontend bridge/backend internal说明](https://docs.docker.com/engine/network/#connecting-to-multiple-networks)；实现前记录此必要配置修正，不把新设计记作验证通过。

本子阶段继续使用本节唯一执行清单，原 Task6 发货/拆单/售后义务不取消：

完整新Run之前可先行做独立普通页面网络正向检查：仅原固定Nginx镜像、同user101/只读配置与公开PNG/cap_drop/no-new-privileges/tmpfs、全新两个项目网络和一个UI容器，不建立App、数据库、员工或命名卷。核真实双网、固定IPv4回环发布、TCP及原PNG HTTP200/完整字节，随后按原身份正常停止UI、非force精确删除三资源并独立核标签零；失败保留实际状态。它只证明本机该页面网络拓扑可行，不代替商城新Run的私有交接、应用、34步或DOM验收，也不测试外连、拒绝或故障样本。

独立页面检查的首次实际准备在创建第一个空backend后停止：Run `20261015104119eb13e1`、source `fc9c1a36e9a2925208d5053b129a849220a6a24c9417e83bf87aca6d1424183f`，固定码 `NEW_NETWORK_NATIVE_IDENTITY`。本机Docker给新bridge的Options自动加入 `com.docker.network.enable_ipv4=true`，与检查器假设的空Options不符；原网络真实Internal=true/EnableIPv6=false/无端点，尚无frontend、容器或卷，不据此声称网络方案失败。保留原源与FAIL，按创建stdout摘要和实际完整身份关闭这一个空原网，独立核标签零；后继新来源须精确核本机该IPv4真值选项，并保留frontend原masquerade=false、IPv6=false和其余完整身份，不删除实际字段或改写旧证据。此必要原生表示适配同时进入尚未生成的r3来源，原v5/backend合同及业务验收条件不改。

首个空网已按原创建摘要和完整身份非force删除，关闭native0、六查询0。普通检查v2 `20261015104334fa7de4` 真实两网创建、单次UI启动native0；随后仅因直接Docker CLI返回Windows反斜杠bind Source，而检查器只接纳POSIX/WSL表示，固定码 `EXACT_PUBLIC_CONFIG_READONLY_BIND` 停止，尚未发HTTP。原同CID仍可只读核实，无需重启：后继独立观察只对同一绝对文件增加Windows规范字符串等价表示，严格保留两精确只读bind、同容器及双网原身份，再继续尚未执行的普通TCP/PNG与正常关闭。保留原v2失败，不把观察后继结果改写成原脚本通过；这项宿主路径表示不影响使用Compose的商城r3路径合同。

独立网络检查后继实际通过：同v2原UI未重启。首两个观察入口在HTTP/intent前分别因导入后漏重绑本Run标签、误要求镜像80/tcp:null必须出现而停止；原源保留。第三来源 `observe-close-smoke-v2-20261015104334fa7de4-r3.py` 摘要 `c184917d5d18b31291c1b83c7e533f427d367890ac9432aef79d3ea864ca6958`，严格核同真实CID、两原完整网络、两个精确只读路径、仅实际8080回环发布（未发布80可缺省或null），随后普通TCP及唯一PNG GET真实200、117字节、摘要与原公开PNG一致、未跟随重定向；原UI一次正常SIGQUIT stop后真实exit0/PID0/restart0，非force删除原UI及两个空端点原网络。实际后继收据 `8f3fc1208f7afcfd8516bdaf4ae9cb2a0a823d08537dfb427268730f1dec490e`；Root独立六个标签原生查询均0，摘要 `e3ecf84a5c09a2c906d75edbb2a772bf7bf71f6e6c46b1da56c190cc12ab932d`。首空网Run的独立六查询也0，摘要 `416c2547ef59b0afb617c5707ddb11639c96f966ef8516519513ce9edc8880cb`。这证明本机此双网回环静态页面入口可行，不是商城HTTP/DOM、业务或原v2脚本通过。

r3源码检查点：builder `2de1f9ef807fabeb8ae2511eb7985a02786fdaf19cfbdb187cfddbe6810c6ba6` 经独立G1–G8源审和Root实际create-only派生，生成15份来源、完整inverse及独立正常关闭源。host摘要 `6ee269d29567ba374d6e83c1ea5556f1504566f3d79933e09feba0f2a6cbc45a`、close `0112a9bba3f617bbb2b3dad9184bbff570ab38ca252c3d6f1f451a175f27ad61`、inverse `3da7a3e2cfccd0c207a6fde6a84deb17041a72318d910fe57f0d518521f68b12`。完整逆回r2/v5、原38 legacy函数字节保持；实际原生docker port观察、TCP/PNG、App有效SIGTERM及双网正常关闭证明均已接线，仍须新Run实际核。Root核生成文件完整字节/AST，并用公开解析输入执行Compose config native0/stderr空，确认仅UI双网/固定回环端口、其余角色单backend、App SIGTERM及十卷；第一次纯解析缺少必需dist路径输入时失败，没有创建资源，补齐公开解析路径后通过。源码/配置检查收据摘要 `d15c4ade8bb3e02e08a139f89c61dd3007fa26b7e2afdeafa6966a92199cdb66`；PHP业务、四接口文档及两个browser模块未改，源码通过不替代新完整Run或页面验收。

r3新Run实际结果（2026-10-15）：clean来源 `483a43f99c1c2df733a2683de8fb4b36c4350fc7`，Run `20261015105237da81d9`，freeze `f9f41f8acd8357b3bfe308a3c13e3c58460a6e470fc6a97e13c569e62af6f894`。专用初始化、四命令结构迁移、原普通订单内部链和20笔真实创建准备均正常；原内部结果摘要 `25f05067bfb8eb73515a31dfc0a5969847478a32acb21d4291ee426a4a89aa43`、准备子结果 `66e972dacbb1940e562bcc396ae61b34fb9498011a95739f5bd8d4e01f97e3b9`，断言计数包含源码检查，不能当作业务案例数。实际仅UI双网，App有效SIGTERM、真实端口映射、native docker port、Windows回环TCP及同源PNG HTTP200/完整117字节均通过；页面入口收据摘要 `e270066079811ebcb08ac90c59a65b957e6be910a7e89080cdf595823367bf60`。同原App source-ready完成完整来源检查，摘要 `db98dbf736e33e5c5f1a1650c2e0619c84e065bbaaddbc90d3be375af4ddd4d5`；三名真实演员已私有交接后正常移除原bootstrap，未输出口令或令牌。

本轮未开始HTTP子进程或DOM步骤：真实runtime与plan读取分别约34.3和33.4秒，按既定串行调用量估算会挤占原3600秒窗口。Root先保留窗口与全部校验条件，独立无网络只读PHP普通测量核10812条路径：严格成员检查约92.6毫秒，双根21624次真实realpath约33613.3毫秒；纯测量结果 `artifacts/ms4-merchant-http-preparation/performance-source-membership-v1/result.json` 摘要 `085608d0b8e65188cf4e71a7fd9cda720d8f16eb91ef5bbcce38cbb510e4e6ac`。已证根因是每次入口解析全部未加载文件的物理路径，不能把改set成员查找当作主要修复，也不能据估算宣布完整窗口通过。

修改源码前已关闭该Run。原入口getter和原生元数据/top/inspect实核同一个监督进程、8000监听0、supervisor请求/响应0、commands/captures/acks/screenshots各0且无active，观察摘要 `fb7e4835fb459b8b311b5e15c5b588c4f3b2e8d3c321e5e814b66fa8e093e462`；getter保留既有私有配置、SQL/Redis只读门禁。临时演员内存已释放，未建浏览器tab。有限正常关闭源 `normal-close-ready-no-child-20261015105237da81d9.py` 摘要 `6f52ee23dd98e6198140e49bc5bb2727aeaad30767c49b5f0a0c39c27b1e1543` 经独立源审和Root读回后执行，四个运行服务均单次正常stop并真实exit0/PID0/restart0；原五CID、两原空网络、十原卷非force精确移除。关闭结果摘要 `4a1ecf1b8026fbfec563d1110ac6b5c5cb1a249baa00faab79bfedafa34e0dda`，Root独立六原生标签查询全0，摘要 `20d73da50dbb8f66d5c89e0cb020693df2f7456f4bfb8a4bbf9475eceb5aec58`，41份原公开来源/业务/准备/页面入口证据字节保持。HTTP/DOM仍NOT RUN，不重开原Run。

性能修复依据（实施前）：仅改现有 `help/audit-regressions/isolated/ms4-merchant-http.php` 的非full来源遍历；继续严格核全部descriptor的成员、唯一、类型、大小/SHA格式及排序闭集。非full先用原绑定建立双根绝对路径表，再只对实际get_included_files逐个realpath、精确命中并核原is_file/非link/大小/SHA；fresh includes还须全部已完成本轮hash。原full的完整inventory和双根逐文件物理校验保持，最终audit仍full；返回included保留完整集合，但不把该词法集合当作本轮全文件hash证明。这是对已经完整启动核过、canonical且无link并保持冻结来源的有限集合换序，不宣称任意可变/带alias树都等价，RO挂载本身不证明宿主永不改动。实施前在已关闭Run的原10812行来源上，用真实require的harness/guard两文件做纯正常对照，核原/新所有descriptor、21624返回路径、实际hash文件集合和前后字节严格相同，随后实施同一候选、语法与独立源审，并以新clean来源Run核真实入口耗时；两文件纯测量不替代实际三文件入口、34步骤或完整验收。业务逻辑、金额、演员、隔离拓扑、23表只读事实、34/25/9及3600秒保持原合同，用户停止样本继续NOT RUN。

性能修复源码检查点：纯正常对照已实际native0/stderr空，原循环39783.053毫秒、候选117.482毫秒；10812描述符及排序闭集、21624双根included键、两个真实已加载文件的大小/SHA集合严格全等，两次均clearstatcache，前后完整10812绑定字节保持。结果 `artifacts/ms4-merchant-http-preparation/performance-source-bound-inverse-v2/result.json` 摘要 `a62595e061fff0c49aa6cdd58996f6745860bd10f07f39a50990ef616b9a7337`，范围仅实际PHP-r加载的harness/guard；未调用App/SQL/Redis/演员/HTTP。已将同一候选块完整字节落入现有HTTP入口，源码摘要 `9aca3b43d181e5b052293208f7daf6439f669157bd0edb85d3125d81bac4a69d`；独立源审通过，替回原块即可逐字节还原整份原入口，原full物理循环和其余内容保持。Root实际PHP8.2隔离语法检查native0/stderr空，原normal/read/prepare/browser-normal四份PHP字节未变，检查收据摘要 `33107b64bef01ca76c9f41094765ea3b72311c2317e9052aac757d0d342105b8`。真实三文件入口、新完整Run及34步仍待实际验证，不由纯测量宣布通过。

- [x] 修复后的完整内部普通链、20笔真实准备及原事实保持；记录本轮页面入口失败与单次正常TERM修复，独立核资源零残留。
- [x] 派生 r3 新来源，接入 HTTP 阶段专用双网、仅UI入口及App有效SIGTERM；保留完整可逆来源与原v5行为，并完成独立源审和必要静态/语法检查（运行结果待新Run）。
- [x] 重新冻结 clean HEAD，以r3新Run执行初始化、结构迁移、原普通链及20单准备，核全体真实来源/权限/资源身份；该Run已正常关闭，HTTP/DOM未执行。
- [x] r3实核UI发布映射、Windows回环TCP、固定同源PNG真实HTTP/字节及原source-ready；本项只证明本轮页面入口，不代替业务页面。
- [x] 完成来源遍历纯正常对照、相同候选实施和聚焦检查；本项只证明上述纯计算/源码范围。
- [x] 以性能修复后的新clean来源重新冻结并执行原完整准备，记录真实入口耗时，保留原窗口；实际有限结果与本轮自然失败见下。
- [ ] 完成原34步骤/25主capture/9原字节JPEG/9人工复核、实际手机横向可达、23表SQL及最终完整来源核对；原3600秒窗口不延长。
- [ ] 后继完整Run实际关闭owned tab/重置viewport、正常停止真实HTTP子进程和原App，再精确移除原五容器、两个空端点原网络及十卷，独立六查询核零；本轮partial Run已按此资源标准关闭。
- [ ] 按实际有限范围记录HTTP/DOM结果；完整Task6/MS4、生产创建门禁和用户停止样本的原状态保持，未验收项继续保留。

性能修复后的实际普通Run（2026-10-15）：clean来源 `87311e7494f7d4261358a06c25216b7faa17181d`，Run `20261015115830de6671`，freeze `5a7cba61afe20a51e3c9fee4a220fd4e15538e96691b57773257cb936314f383`。原专用初始化、四命令结构迁移、内部正常订单链、20笔真实创建准备及UI入口均再次通过；内部结果摘要 `8bd5f3b6c621fa31720e5721badbc2daefd4b81858720989d65a1cf626ccf2d7`，准备子结果摘要 `e7c5032803efab6d66257917af3015d920147a71a5d18a06fe0e4f4c7b8e2ed9`。实际原runtime/plan getter为521/531毫秒，保持34步、9人工复核和原3600秒窗口，公开测量为同Run `evidence/root-http-ingress-performance.json`。仅真实字节的跨REPL类型适配先自然失败，改用 `Uint8Array.from(actualBytes)` 保留原字节后继续；没有修改冻结模块、帧或认证数据，也不将原类型失败记成通过。

本轮首次实际HTTP/DOM只完成前两步：真实商家登录、订单列表均HTTP200/business200，原capture和ACK已写入。第3步 `owned-list-320` 在 `MS4_ACTUAL_TABLE_RETURNED_TO_LEFT_EDGE` 自然失败，未写该步capture、ACK、计划截图或人工复核，后续步骤及最终23表SQL审计未运行。失败摘要 `b664bc3590187b253b614c629a1545df42cf5a3953e79ad4d17a2f7e5eb871ca`。后续普通键盘诊断读回：真实滚动区宽239/内容1170、tabIndex=-1；焦点在最右列首个“查看详情”按钮，按一次ArrowLeft从211.333反而移至920.667，再按一次仍为920.667。原始诊断JPEG独立保存在同Run `evidence/root-scroll-diagnostic-320.jpg`，50021字节、摘要 `73a6ec137c3e334d6ee36991a6f8e56222273638758db75b65048c65bdff9d4b`；它不是原计划第3步截图。源码也确认列表选择末列按钮，列表/详情真实body-wrapper均没有显式键盘焦点。这证明当前检查目标不能完成该次正常横向往返，不宣称已验证工具内部实现。

该partial Run已完整正常关闭：owned tab9关闭、viewport复位、临时演员内存释放；原bridge正常停止同一HTTP子进程，保留真实TERM15、空group、监听关闭以及原child exitcode/proc_close=-1，不改写为0。只读观察实核原监督进程、3个计划命令/2个ACK/2个capture文件名/0计划截图及第3步缺证，观察摘要 `1448a46dfd99cdaf8da1c5efc8f3bd57caae84065d6b34b70127e07022a582d9`。有限关闭源 `normal-close-browser-partial-20261015115830de6671.py` 经独立源码复核和Root读回后，四个原服务分别单次正常stop并真实exit0/PID0/restart0，随后非force精确移除原五容器、两个空端点原网络及十卷。关闭摘要 `c00cc36c4a9a7f96d2077086fc13c4aa07d154460da219f3fa4a71690d368675`；Root另执行六条原生标签查询全0，摘要 `2e47fe8df4b3861697f8243709479fb5b8d539abb5f9da171d0448ef47974b95`，46份原公开PASS/FAIL/来源证据字节保持。不重开该Run，完整浏览器验收、Task6/MS4仍未完成。

后继键盘修复实施依据（实施前，通用商城）：只在商家前端 `src/accessibility.js` 复用现有Vue指令模式，为列表/详情各自真实 `.el-table__body-wrapper` 设置tabindex=0、具名region及专用class；两页局部注册和声明，`src/styles.css` 仅加内部可见焦点环。不给表格根冒充滚动区，不增加键盘事件处理器、强制focus或scrollLeft赋值，不改表格布局/金额/接口/权限。新建仓库外r4浏览器来源，统一用真实滚动区发送原ArrowRight/ArrowLeft，逐次只读核焦点确为该具名region，保留两端/末格几何、64按键上限和原证明对象闭集；仅更新新来源的focus说明，旧r3与失败证据保持。使用已有依赖在新目录离线构建，先完成聚焦源码检查和独立源审；实际键盘、320/390/1440视觉及完整HTTP/DOM仍须后继新clean来源Run，不能据源码修复宣称通过。

键盘修复源码检查点：上述四个产品文件已按此最小方案实现，`main.js`、业务/API和原验证断言均未修改。已有Node22实际执行原 `template/merchant/scripts/source-check.cjs`，31脚本语法、74本地导入通过；Root读回四文件差异并核 `git diff --check` 通过，四文件保持LF。这只证明源码范围；离线构建、新r4来源及实际浏览器验证仍待完成。

键盘修复离线构建（2026-10-15）：前端来源为clean `5fc4232d1a97c7cddc4841dd4a95be72cbba3bf2`。首次新目录复制依赖时自然FileNotFoundError，原FAIL摘要 `15fa2ee2e4af7e28148247d64c662a680209202d815f7294b93174ae1aa7081c`；当时尚未运行npm build。只读核实原文件路径258字符、新路径267字符、本机LongPathsEnabled=0。沿同一冻结builder和全部26540项依赖，仅将已有output参数改为新的短目录 `artifacts/ms4-merchant-console-build/5fc4232d-k2`，完整清单最长路径252字符，不改源码/依赖/检查条件或系统设置；诊断摘要 `52cb8b5af2c420195cab60df2ebc62e7a486dcee7a750bc8d04a2dcc1092566e`。新构建实际terminal0，8个原生阶段全0，原prebuild31脚本/74导入通过；原依赖前后完整字节保持、新副本仅缓存可变，43份源码及副本保持，实际25份dist与通用标题/26个本地资源引用核对通过。成功构建摘要 `d8db1a4d53524dec4483a3c9402ec5987cf9d55a120f8757f27d0aa76f7614e3`；Root独立只读postcheck摘要 `a448f5ba268abefd68b5acb75f7c08df4cc7603e8ea289dfaff17ec9dceee80d`，实际重核源码/副本、dist、原生日志和进程收据，依赖全树部分明确消费原builder证据，不冒称再次全树复验。未安装或重跑build，原FAIL保留；构建不等于HTTP/DOM验收。

r4键盘来源已实际create-only生成，builder摘要 `e3dc21bd9e819a46d622f8c597734cf67aa8dc8ac4ef2d631d0bbf662c6347e3`；Root读源和纯逆变换/原38函数保护通过，新browser的Node语法检查exit0。原host字节保持，因此仍指向旧38a9前端构建；本轮尚未尝试用它freeze新前端，不能写成冻结失败或运行就绪。后继build绑定实施依据：新建 `candidate-internal-v6-merchant-http-final-r4-build1` 及对应close/inverse，host只换实际新build目录、上述真实postcheck SHA及其真实前端source SHA三值，完整原43源码/25产物、逐文件hash、普通业务及34/25/9/23/3600门禁保留；close只改新HOST路径和真实host SHA，其函数保持。旧r4/r3与构建FAIL不覆盖。新后继须完整逆回既有来源并独立源审后，才用后续clean主树重新freeze；主树后续仅文档/后端变动仍需逐文件证明其43前端源码等于该实际构建，不能借用不匹配产物。

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
