# MS4 单店交易增量结构

执行依据是 `docs/multi-merchant/09-plan-ms4-ms6.md` Task 2。本文件说明实际 CLI 和隔离验证入口，不是新执行清单。历史归属、存量权益、支付账户和人工验收仍未完成；创建服务尚未开放。

## 使用

```bash
php help/migrations/marketplace-ms4.php check --config-file=/private/ms4-database.json
php help/migrations/marketplace-ms4.php apply-structure --config-file=/private/ms4-database.json
php help/migrations/marketplace-ms4.php check --config-file=/private/ms4-database.json
php help/migrations/marketplace-ms4.php apply-structure --config-file=/private/ms4-database.json
```

省略模式时执行只读 `check`。必须显式提供私有 JSON 文件，键为 `hostname/hostport/database/username/password/prefix`；端口默认 3306，其他键必填。不默认读取 `.env`，不加载框架，不打印配置值、数据库名、驱动异常或私有路径。表名来自明确的 prefix，连接后核对实际 `DATABASE()`，不猜表前缀。

真实环境执行仍需原授权边界及备份／隔离演练；这些命令本身不构成真实数据库操作授权。CLI 只执行批准的 CREATE TABLE、ADD COLUMN、ADD KEY，不更新业务行、历史归属或开关，不创建商家，不 DROP/TRUNCATE，不改变原列类型、主键、NULL 约束或金额单位。

## 实际结构合同

新表采用 InnoDB、utf8mb4_bin，表 COMMENT 为 `marketplace-ms4-structure-v1`。列有中文注释；业务键与 SKU unique 使用 ascii_bin，摘要为 BINARY(32)。不自动新增 CHECK 或 FK；真实业务关系、动作类型与数量条件由同一数据库事务的服务再次校验。

| marketplace_order_idempotency 列 | 类型 | 空值／默认 |
|---|---|---|
| uid, mer_id | INT UNSIGNED | NOT NULL，无默认 |
| idem_key | VARCHAR(128) ascii_bin | NOT NULL，无默认 |
| request_digest, confirmation_ref | BINARY(32) | NOT NULL，无默认 |
| source_kind | VARCHAR(16) ascii_bin | NOT NULL，无默认 |
| cart_refs | JSON | NOT NULL，无默认 |
| state, post_commit_done | TINYINT UNSIGNED | NOT NULL DEFAULT 0 |
| order_id | INT UNSIGNED | NULL |
| cancel_at, add_time | INT UNSIGNED | NOT NULL，无默认 |

主键 `(uid,idem_key)`；唯一 `uq_ms4_uid_confirmation(uid,confirmation_ref)`、`uq_ms4_created_order(order_id)`；扫描索引 `idx_ms4_finalize(post_commit_done,order_id)`、`idx_ms4_cancel(state,cancel_at,order_id)`。

`state=0` 仅允许存在于创建事务内部。创建订单、订单项、reserve 和 `state=1/order_id` 在同一事务提交；失败回滚，不提交独立占位或租约。重放先核真实 UID 与规范请求摘要，再读取原订单，不依赖已过期缓存、不重复库存动作或旧创建事件。确认引用为真实确认键原字节的 SHA256；原 key 保存在原创建订单 `unique`，读回须核摘要。source_kind 与规范字符串 cart_refs 持久保存供收尾使用，不按确认 TTL 删除幂等凭据。

| marketplace_stock_movement 列 | 类型 | 空值／默认 |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | NOT NULL 主键 |
| mer_id, order_id | INT UNSIGNED | NOT NULL，无默认 |
| product_id | MEDIUMINT（有符号） | NOT NULL，无默认 |
| sku_id | INT（有符号） | NOT NULL，无默认 |
| sku_type | TINYINT UNSIGNED | NOT NULL，无默认 |
| sku_unique | CHAR(8) ascii_bin | NOT NULL，无默认 |
| qty | INT UNSIGNED | NOT NULL，无默认 |
| kind | VARCHAR(16) ascii_bin | NOT NULL，无默认 |
| idem_key | VARCHAR(128) ascii_bin | NOT NULL，无默认 |
| parent_reserve_id | BIGINT UNSIGNED | NULL |
| order_item_id, refund_id | INT（有符号） | NULL |
| actor_type | VARCHAR(16) ascii_bin | NOT NULL，无默认 |
| actor_id | INT UNSIGNED | NULL |
| request_digest | BINARY(32) | NOT NULL，无默认 |
| sku_stock_before, sku_stock_after, sku_sales_before, sku_sales_after | INT UNSIGNED | NOT NULL，无默认 |
| root_stock_before, root_stock_after, root_sales_before, root_sales_after | MEDIUMINT UNSIGNED | NOT NULL，无默认 |
| add_time | INT UNSIGNED | NOT NULL，无默认 |

唯一 `uq_ms4_stock_action(idem_key)`；索引 `idx_ms4_order_sku(order_id,sku_id)`、`idx_ms4_parent_kind(parent_reserve_id,kind)`、`idx_ms4_refund_item_kind(refund_id,order_item_id,kind)`、`idx_ms4_product_sku(product_id,sku_id,id)`。

仅 reserve/release/return_in；实际 SKU 主键是权威，原 product_id/type/unique 用于交叉核验，不能假设 unique 全局唯一。同 SKU reserve 可覆盖多个真实订单项，因此 order_item_id 可为空；return_in 必须绑定实际退款、订单项和原 reserve。后台任务 actor_type=system、actor_id=NULL，不伪造顾客或员工编号。

单 SKU 的已提交 return_in PK 就是实物验收号，不新增 receipt、outbox 或独立发货表。物流、申请和退款状态不能代替收到实物。父 reserve 锁内核累计 release+return_in 不超过原预留量，同时核实际售后行可退件数；真实库存／销量前后值由条件更新所在事务取得。

| 原表 | 兼容追加列 | 新索引 |
|---|---|---|
| store_order | marketplace_trade_version TINYINT UNSIGNED NULL DEFAULT 0；merchant_name_snapshot VARCHAR(128) NULL；merchant_after_sale_contact_snapshot VARCHAR(255) NULL | idx_ms4_order_owner(mer_id,id) |
| store_order_cart_info | mer_id INT UNSIGNED NULL | idx_ms4_item_owner(mer_id,oid,id) |
| store_order_refund | mer_id INT UNSIGNED NULL；marketplace_refund_mode VARCHAR(16) ascii_bin NULL | idx_ms4_refund_owner(mer_id,store_order_id,id) |
| store_product_reply | mer_id INT UNSIGNED NULL | idx_ms4_reply_owner(mer_id,id) |

快照的 utf8mb4 排序规则继承原表，不变更整表排序规则。旧版本 0/NULL、原行和未映射归属保持。订单主键／UID 是 UINT，而现订单项、退款和 pid 是 signed INT；服务必须在写入时检查实际范围，迁移不偷偷改宽历史列。订单项 cart_info 复用现商品／价格 JSON，后续服务写入真实 SKU／reserve 血缘；旧 unique 是订单行 MD5，不是 SKU 身份。新退款模式 money_only/return_goods 在申请时固定，旧 refund_type 是可变流程状态。

## 核对、重跑与部分 DDL

CLI 要求 MySQL 8.0.16 以上、实际 SESSION/GLOBAL 严格模式，读取 MS1 已完成结构及当前消费所需 MS2 商品／归属／人工库存结构。先核原表 InnoDB、类型／signedness、NULL/default、字符集／排序规则、主键、索引及触发器，再列出缺失步骤。`apply-structure` 获得库／prefix／版本绑定的 GET_LOCK 后重新核对，再执行缺失步骤。

已存在新表必须匹配本版本全部列、索引、注释和引擎；已存在追加列必须匹配本版本。旧表已有完全等效的可见完整索引可以复用，但同名不同形状的索引报冲突。不同结构不会覆盖、自动修理或忽略。

回传包含 mode/state/native_exit/changed/applied_steps/pending_steps/issues/failure_phase 和 CLI 字节摘要，不含凭据。state 为 applicable/completed/incompatible；正常退出 0，结构不兼容 2，操作失败 1。changed 表示实际尝试过 DDL，不能用于推断所有步骤已成功。DDL 可能部分提交：保留回传，重新 `check`，核明剩余步骤后原命令重跑；不删除已建立对象，不声称事务回滚了 DDL。若失败后元数据读取也失败，空 pending_steps 不表示完成。

## 普通隔离验证入口

```bash
php help/audit-regressions/isolated/ms4-migration.php
```

此入口目前只有源码检查；专用 MS4 实例与实际迁移验证待主任务准备，不使用已经失败的 MS3 Run8。不能把源代码或 `--help` 成功写成迁移 PASS。

入口只接受原 ISO-FULL 保护的独立 RUN：角色为 audit-ms4-migration/audit-mysql/audit-redis；同一内部网络、随机私有配置和双哨兵，App 账号保持原精确 DML 权限，migrator 只具有本 audit_auth 库权限。不会启动应用、HTTP、支付或其他里程碑套件。

宿主准备方需先从实际 Docker create/inspect 记录以下文件，不能手造容器身份：

- `evidence/ms4-migration-container-created.json`：现有 native receipt 字段 run_id/source_sha/id/hostname/image/status/read_only/privileged/network_mode/labels/entrypoint/command/workdir/restart_count/mounts/tmpfs。status 为启动前实际 created，entrypoint 为 `["php"]`，command 为本入口路径，镜像为已有 PHP 8.2 的 `sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a`。
- `evidence/ms4-migration-source-binding.json`：schema=1、同 RUN 和当前 source_sha、entry_sha256/cli_sha256、requested_repo_source/requested_run_source，以及 `m4m_sourcePaths()` 的恰好八项实际 path/bytes/sha256。新文件不属于旧 source47 冻结包，须使用当前真实源绑定。
- 原 manifest.json：注册上述三角色真实 CID／地址／image_id、内部网络以及专用 volume identities。容器 `/workspace` 与 `/source` 都只读绑定当前源；`/run/audit` 为同 RUN 可写绑定；专用 bootstrap-secrets/app-secrets 分别只读挂到 `/run/audit-bootstrap`、`/run/audit-app`；`/tmp` 是容器内实际 tmpfs。没有 `.env`、install.lock 或 Docker socket。

初始化由宿主现有授权流程完成。验证脚本不导入安装 SQL、不伪造 UID／商家、不手动构造归属样本。必须先有真实 MS1/MS2 结构和哨兵，MS4 两张表与七列尚未添加。验证实质执行 check→apply-structure→check→apply-structure 四个原生 CLI 子进程，首轮新增恰好两表、七列、四索引；第二次 changed=false，全部结构与数据不变。

验证对原库每张表的原列逐行投影做排序摘要，核目标原列数据、原元数据、非目标表、原主键／计数和 Redis 值／绝对到期时间保持，记录实际原行数，不把零行样本冒称历史业务迁移覆盖。配置只在本容器 /tmp create-only 保存，结束只删除校验 dev/ino/hash 相同的本人文件。失败不删表或恢复数据库；原状态保留至整个 RUN 的受控清理。

证据为 `evidence/ms4-migration-normal.json`，只含结构、计数、摘要和原生结果。无效／拒绝／冲突／并发／故障／恢复／已付款案例保持 NOT RUN；本普通结构结果也不代表创建、库存生命周期、支付、上线或 MS4 完整验收。
