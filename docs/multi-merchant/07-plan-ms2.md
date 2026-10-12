# MS2：商品与附属资源隔离 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。开工前提：MS1 已验收。需要授权级别 L2；Task 1 结束时有强制停止点 SP-MS2-1；使用业务数据副本需要 L3d 授权，真实库迁移需要 L4。

**Goal:** 商家通过 merchantapi 维护自己的商品、规格、库存、素材、运费模板，并送平台审核；平台审核、下架并可在授权和审计下管理任何商家的商品；顾客能跨店浏览已发布的商品，看到真实店铺，看不到后台字段；任何商家都无法读写他店的受保护数据。

**Architecture:** 商品根实体用 `eb_store_product.mer_id` 表示归属；规格、详情、分类绑定等子表通过 `product_id` 继承归属，访问时一律先校验父商品。素材、素材分类、运费模板、规格预设各自增加 `mer_id`。商家侧走新的 `MerchantProduct*Services`，内部复用现有 `StoreProductServices` 的计算与保存逻辑，但在入口强制归属、在出口做字段投影。公众侧修改现有 `api` 商品接口的筛选条件与返回字段。

**Tech Stack:** 同 MS1。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §5.2、§6、§7.1；用例 D01–D18、D20、D21、G01–G07。

## Global Constraints

- 归属字段只用 `mer_id`（D4）。新的受保护写入由服务端从 `MerchantContext` 写入 `mer_id`；请求中出现 `mer_id` 或 `merchant_id` 时返回 422。
- 平台在后台创建的商品，`mer_id` 写入自营商家 ID：配置 `config('marketplace.self_operated_mer_id')`，值由 MS2 迁移创建或查找后写入部署配置。不再依赖列默认值 `0`。
- 商品审核字段（`eb_store_product` 新增）：`review_status`（0 草稿、1 待审、2 通过、3 驳回，默认 0）、`review_reason`、`review_admin_id`、`review_time`。"已发布" = `review_status = 2` 且 `is_show = 1` 且 `is_del = 0`，且所属商家 `review_status = 1`、`security_locked = 0`、未关闭。
- 商家修改商品内容、规格定义或价格后，`review_status` 回到 1（待审），该商品从公众列表中下架直到再次通过；只改库存不触发重审。
- 批量操作：先查出全部目标行并逐一 `assertOwned`，任一失败则整批返回 404，零修改；全部通过后在一个事务中执行，`WHERE` 条件同时包含 `id IN (...)` 与 `mer_id = ?`，并核对受影响行数等于目标数，不等则回滚。
- 公众接口字段使用 `PublicCatalogScope::fields()` 白名单；**禁止**返回 `cost`、`mer_use`、审核字段、内部备注、其他顾客的信息。
- 现有数据中 `mer_id = 0` 的商品，只有在用户确认归属映射（SP-MS2-1）之后才迁到自营商家；未知归属的行写入隔离清单，并阻止 `marketplace.enabled` 开关打开。

## Review Focus

- **BaseDao 直查**：`get`、`getOne`、`value`、`getColumn`、`update`、`delete` 等不经过 `search()`（F-40）。商家侧代码不得直接调用这些方法访问受保护表，必须经过 `MerchantScope`。Task 1 的盘点要列出现有 `StoreProductServices` 中所有被复用的方法内部用到的直查。
- **子表绕过**：用他店的规格值 `unique`、`attr_value.id` 调整库存（D04），或用他店的规格预设、运费模板 ID 保存商品（D06、D18）。
- **模板回退**：现有代码在运费模板缺失时可能回退到 `id = 1`。商家商品必须引用本家模板，缺失时报错。
- **缓存串店**：现有商品列表、详情的缓存键不含商家；商家侧不得复用这些缓存；公众侧缓存只放公开投影（D07、D14）。
- **搜索器回退**：`searchMerIdAttr()` 的 `$value ?? 0` 与详情里写死的 `mer_id = 0`（F-22）都要改，但公众列表不传商家时仍应返回全部已发布商品。

---

### Task 1: 盘点与结构核对（只读）

**Files:**
- Create: `docs/multi-merchant/records/ms2/product-access-inventory.md`、`docs/multi-merchant/records/ms2/ddl-check.md`、`docs/multi-merchant/records/ms2/ownership-mapping-proposal.md`

- [ ] **Step 1: DDL 核对**：在获得 L3d 授权时，对真实库执行只读的 `SHOW CREATE TABLE`、`SELECT mer_id, COUNT(*) ... GROUP BY mer_id`（只统计，不导出行内容）；未获授权时只用安装结构，并在报告中标注"真实库未核对"。核对对象：F-21 列出的 9 张表、`eb_system_log.merchant_id`、商品子表、`eb_system_attachment`、`eb_system_attachment_category`、`eb_shipping_templates*`、`eb_store_product_rule`。结论按"同语义可复用 / 缺列需新增 / 语义冲突需映射"三类填写。
- [ ] **Step 2: 访问路径盘点**：列出 adminapi 与 api 中所有读写上述表的控制器方法 → 服务方法 → DAO 方法，标出不经过 `search()` 的调用、缓存键、运费模板回退、上传与删除附件的路径、商品统计与导出接口。
- [ ] **Step 3: 归属映射提案**：按 `mer_id` 现值分组统计商品、附件、运费模板数量；提出"哪些可确认为自营、哪些未知"的映射提案。只给统计和规则，不导出业务数据。
- [ ] **Step 4: 停止点 SP-MS2-1**：按 12 文档 §7.3 回传三个文件的摘要，等待用户确认：（a）`mer_id` 的复用/新增结论；（b）历史数据归属映射；（c）盘点中发现的、需要在本里程碑一并处理的额外入口。

### Task 2: 迁移

**Files:**
- Create: `help/migrations/marketplace-ms2.php`、`help/migrations/marketplace-ms2.md`、`help/audit-regressions/isolated/ms2-migration.php`

**Interfaces:**
- Produces: 命令 `check | apply-structure | backfill --mapping=<文件> | enforce`：
  - `apply-structure`：为 `eb_system_attachment`、`eb_system_attachment_category`、`eb_shipping_templates`、`eb_store_product_rule` 增加 `mer_id int unsigned NULL`（无默认值）与索引；为 `eb_store_product` 增加审核字段；创建自营商家（若不存在）并输出其 ID。
  - `backfill`：只按映射文件更新；输出前后行数、按 `mer_id` 分组的计数、孤儿子表行数（子表 `product_id` 指向不存在的商品）、跨商家引用数（商品引用他店运费模板等）。未知项写入 `marketplace_ownership_quarantine` 报告文件（仓库外）。
  - `enforce`：确认上述表中不存在 `NULL` 或 `0` 归属后，将新增列改为 `NOT NULL`，并去掉 `eb_store_product.mer_id` 的默认值；否则拒绝执行并列出原因。

- [ ] **Step 1: 写用例**：G01（每个子命令执行两次结果一致，不重复创建自营商家）；G02（夹具中放入未知 `mer_id`、孤儿规格、引用他店模板的商品 → `backfill` 报告并隔离，`enforce` 拒绝）；G04（映射只把确认项迁到自营商家，自营商家 ID 为正整数且不是 1 的假设值）；G05（夹具中加入同语义冲突列 → `check` 报告冲突并停止）；G06（迁移前后商品、规格、附件主键与文件路径不变）；G07（`enforce` 之后，漏写 `mer_id` 的插入失败）。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(migration): marketplace product ownership (MS2)`。

普通结构流程进度（2026-10-13，完整 Task 2 仍未完成）：`help/migrations/marketplace-ms2.php` 已接入已验证的 `check`、`apply-structure`，另两命令明确返回未实现。设计沿安装结构和现 MS1 迁移，四归属列保持 nullable、原商品 owner/default0 保留，历史数据不自动映射；新增审核字段、索引和库存流水，自营身份按真实 INSERT ID 与仓库外私有登记绑定，业务 enabled=false。原计划的四命令、显式 mapping、quarantine、enforce、测试和交付要求继续保留。

实际普通 ISO `2026101221160570c06b`（基线源码43cf、冻结 CLI SHA256 `2eb13dd870f409494f5c5f42ad96b4157081de4a73921dcec475fefdda930c97`）的 `check → apply-structure → check → apply-structure` 均 native0；实际8新列/6索引、库存表15列/4索引/2 FK/1生效CHECK通过独立元数据核对，自营真实ID1仅一行且重复不增加。原列全行/主键/路径/owner与旧schema保持，重复后164表、原缓存绝对期限/运行目录全等，私有文件清理和双标签12库存归零。首轮原FAIL `20261012210236788c24` 保留，辅助自增计数改取同一实际SHOW CREATE，未改业务或原严格断言。仅本普通结构范围通过，G01–G07/backfill/enforce/真实历史映射与完整MS2仍未验收，未操作真实库或推送部署；见 `records/ms2/structure-normal-summary.json`。

### Task 3: 商家商品服务与接口

**Files:**
- Create: `crmeb/app/services/marketplace/product/MerchantProductServices.php`、`MerchantStockServices.php`
- Create: `crmeb/app/merchantapi/controller/Product.php`、`Stock.php`、`route/product.php`
- Modify: `crmeb/app/model/product/product/StoreProduct.php`（`searchMerIdAttr()`：`$value` 为 `null` 时不加条件，为非正整数时抛异常；不再回退 `0`）

**Interfaces:**
- Produces:

```php
// app\services\marketplace\product\MerchantProductServices
public function list(MerchantContext $ctx, array $filter, int $page, int $limit): array     // 返回 ['list' => [...], 'count' => int]
public function detail(MerchantContext $ctx, int $productId): array
public function save(MerchantContext $ctx, int $productId, array $data): int                 // $productId = 0 新建；返回商品 ID；需要 product.write
public function submitReview(MerchantContext $ctx, int $productId): void                      // 需要 product.submit_review；草稿或驳回 → 待审
public function setShow(MerchantContext $ctx, int $productId, bool $show): void               // 只允许 review_status = 2 的商品上架
public function batchSetShow(MerchantContext $ctx, array $productIds, bool $show): void
public function delete(MerchantContext $ctx, int $productId): void                            // 软删除
// app\services\marketplace\product\MerchantStockServices
public function adjust(MerchantContext $ctx, int $productId, string $unique, int $delta, string $reason): void   // 需要 stock.adjust；写库存流水
```

`save()` 的处理顺序：剔除 `mer_id`、`merchant_id`、审核字段、`is_show`（出现则 422）→ 新建时由 `$ctx->merId` 写入归属，编辑时先 `assertOwned` → 校验 `temp_id` 属于本商家、`cate_id` 是平台启用分类、规格预设属于本商家 → 调用现有 `StoreProductServices` 的保存逻辑 → 保存后再次核对子表行的 `product_id` → 内容或价格变化时 `review_status` 改为 1。

- [ ] **Step 1: 写用例**：D01（A 新建商品、规格，归属正确）；D02（A 读取 B 的商品详情 → 404，响应中没有 B 的任何字段）；D03（A 用 B 的商品 ID 编辑、删除、上下架 → 404，B 的行内容不变）；D04（A 用 B 的规格 `unique` 调库存 → 404，B 的库存不变）；D05（A 批量操作混入一个 B 的 ID → 整批 404，A 自己的商品也没有改变）；D09（A 提交 `review_status = 2`、`mer_id = B` → 422 并写审计日志）；D21（同时传 `mer_id` 与 `merchant_id` → 422；保存后父子归属一致）；D15 的商家侧子场景（不传筛选也只返回本店）。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchantapi): scoped product and stock management`。

### Task 4: 素材

**Files:**
- Create: `crmeb/app/services/marketplace/MerchantAttachmentServices.php`、`crmeb/app/merchantapi/controller/Attachment.php`

**Interfaces:**
- Produces: `upload(MerchantContext, ...)`、`list(MerchantContext, int $categoryId, int $page, int $limit)`、`delete(MerchantContext, array $ids)`、`categories(MerchantContext)`、`saveCategory(MerchantContext, ...)`；存储路径前缀 `uploads/merchant/<merId>/`（前缀只是组织方式，不是授权依据）。

- [ ] **Step 1: 写用例**：D08（A 列出、覆盖、删除 B 的素材 → 404 且文件仍在；A 猜测 B 的素材 ID 用于商品图片 → 422）；D14（A 的员工失去 `attachment.manage` 后，下一次列表请求 403，即使之前有缓存）。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchantapi): scoped attachments`。

### Task 5: 运费模板

**Files:**
- Create: `crmeb/app/services/marketplace/MerchantFreightServices.php`、`crmeb/app/merchantapi/controller/Freight.php`
- Modify: 盘点中发现的运费模板回退逻辑（只对 `mer_id` 属于入驻商家的商品生效；平台自营商品行为不变）

- [ ] **Step 1: 写用例**：D06（A 引用 B 的运费模板或规格预设保存商品 → 422，无部分写入）；D18（A 的商品引用的模板被删除后，下单前的运费计算报错而不是回退 `id = 1`；省市区字典接口对商家只读可用）。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchantapi): merchant-owned freight templates without platform fallback`。

### Task 6: 分类绑定

**Files:**
- Create: `crmeb/app/merchantapi/controller/Category.php`（只读：平台启用分类树）

- [ ] **Step 1: 写用例** D17：商家读取分类树成功；尝试新增、修改分类 → 路由不存在或 403；使用停用分类保存商品 → 422；修改他店商品的分类绑定 → 404。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchantapi): read-only platform categories`。

### Task 7: 审核与平台管理

**Files:**
- Create: `crmeb/app/adminapi/controller/v1/marketplace/ProductReview.php`，路由加入 `crmeb/app/adminapi/route/marketplace.php`；迁移中登记对应菜单
- Modify: adminapi 现有商品写接口：目标商品 `mer_id` 不是自营商家时写审计日志

**Interfaces:**
- Produces: `GET /adminapi/marketplace/products?review_status=1`、`PUT /adminapi/marketplace/products/:id/review`（`approve` 或 `reject` + 原因）、`PUT /adminapi/marketplace/products/:id/off-shelf`

- [ ] **Step 1: 写用例**：D13（有权限的平台管理员审核、下架 A 和 B 的商品成功，审计日志含操作人、目标商家、资源）；没有该权限的受限管理员 → 拒绝；审核通过后商家再改价格 → 回到待审并从公众列表消失。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(adminapi): product review and audited cross-merchant management`。

### Task 8: 公众商品接口

**Files:**
- Create: `crmeb/app/services/marketplace/PublicCatalogServices.php`（或在现有 api 商品服务中接入，以盘点结论为准）
- Modify: `crmeb/app/services/product/product/StoreProductServices.php`（详情返回真实 `mer_id` 与店铺公开信息，去掉写死的 `0`）、api 商品列表与详情的筛选条件和字段

**Interfaces:**
- Produces: 公众商品详情增加 `store` 对象：`{mer_id, name, logo, after_sale_contact, shipping_note}`；列表支持可选参数 `mer_id`（只作筛选）。

- [ ] **Step 1: 写用例**：D10（A、B 的已发布商品都能在公众列表、详情中看到；未发布、待审、驳回的看不到；商家被封禁后其商品看不到）；D11（公众列表与详情的 JSON 中不出现 `cost`、`mer_use`、`review_*`、其他顾客信息；用字段白名单逐键比对）；D15 的公众子场景（不传 `mer_id` 返回全部已发布商品）；D16（详情顶层 `mer_id` 等于数据库值，`store` 信息与该商家一致）；G03（迁移后的自营历史商品、图片在顾客端仍可访问）。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(api): public catalog shows merchant store and only published products`。

### Task 9: 统计、列表与缓存

- [ ] **Step 1: 写用例** D07：A 的商品统计、分页总数只计本店；merchantapi 不提供导出接口（验证路由不存在），商家令牌访问 adminapi 导出接口被拒绝；公众列表缓存命中后，换成 B 的筛选条件不返回 A 的结果。
- [ ] **Step 2–5:** 确认失败 → 修复缓存键或去掉商家侧缓存 → 通过 → 提交 `fix: merchant-scoped stats and cache keys`。

### Task 10: 旁路检查、迁移演练与交付

- [ ] D12：在隔离环境建立 A、B 商家后，用商家令牌调用旧 adminapi 商品接口、outapi 与 MCP 商品接口、CRUD 生成路由、文件编辑接口 → 全部拒绝；平台合法集成（对外账号读取商品）回归通过。
- [ ] 在获准的数据副本上执行 Task 2 的四个子命令，记录前后摘要（G01–G07）；未获准时写 BLOCKED 及所缺授权。
- [ ] 运行 MS0a、MS0b、MS1、MS2 全部用例，`exit=0`。
- [ ] 写 `records/ms2/report.md`、`results.jsonl`；提交；停止，等待验收（11 文档 §5.5）。
