# MS5 testing-only 支付两表结构 CLI

2026-10-17 Main 整合版本。当前版本状态：SOURCE / PHP PARSE / CLI HELP ONLY；含下述两项补充旧列合同的迁移执行仍 NOT RUN。

本文件说明同名 PHP 中的独立 `MarketplaceMs5PaymentMigration`。Main 整合依据见 [持久测试付款核心整合到 Main](../../docs/multi-merchant/records/ms5/payment-core-integration.md)，原设计来自 AUX 的 payment-registry-physical-design §14–17 及既有03/09执行清单。保留两表增量结构合同，不修改MS1/MS2/MS4迁移；当前补充实际 DAO 必读的 cart_id/pay_uid 只读前置检查，不增加 DDL 或业务写入。源码与 help 不能代替本版本真实迁移或付款验收。

## 1. 固定范围与 CLI 合同

仅允许创建受控prefix加 `marketplace_payment_registry`、`marketplace_payment_attempt`。两表的 `TABLE_COMMENT` 精确为 `marketplace-ms5-payment-structure-v1`。没有ALTER、历史映射、DML/backfill、DROP/TRUNCATE、账户或演员创建、开关更新、业务事件、付款或履约操作。

L2 专用隔离实例内使用以下语法；本说明不记录实际迁移结果：

```text
php help/migrations/marketplace-ms5-payment.php [check|apply-structure] --config-file=PRIVATE_JSON
php help/migrations/marketplace-ms5-payment.php --help
```

省略模式默认为只读 `check`。只接受一个模式和一份显式配置文件；重复或未知参数受控失败。配置固定为 `hostname`、可选 `hostport`、`database`、`username`、`password`、`prefix`，其余键拒绝。端口默认3306，其余五项必须显式为字符串。配置最多65536字节，严格JSON解码深度16；不读取 `.env`、App、Redis或业务配置。异常投影不输出配置值、数据库名、连接字符串、私有路径或驱动错误。

**配置parser只负责有界读取和字段校验，不代表tmpfs、0600、文件owner或no-link门禁。** 后继隔离入口必须沿原 `ms4-migration.php::m4m_new/m4m_file/m4m_source` 的私有文件身份、tmpfs、实际权限、来源绑定及自身清理合同，不能把这份parser称作完整私有文件保护。

构造和检查核真实 `SELECT DATABASE()`、受控prefix及所有完整表名长度≤64、PHP64位、mysql PDO、异常模式、原生预处理、无native事务；保留MySQL8原最低版本8.0.16并拒绝MariaDB，SESSION/GLOBAL至少一种严格SQL mode。CLI不修改SQL mode。`require_once marketplace-ms4.php` 仅取得原类：原直接执行guard不会因require而启动旧CLI。

## 2. 原结构只读前置及实际producer依赖

每次inspect都要求同一PDO上的原 `MarketplaceMs4Migration::check()` 为 `completed`；缺原结构不是新CLI补建MS1/MS2/MS4的授权。原前置检测自身失败按检查失败处理，不冒称结构完成。在这个前置之外，`producerContracts()` 还显式核下面的依赖列、原生类型/signedness、NULL/default、自增及必要索引。

下文类型已按既有metadata规则消除整数显示宽度，例如 `int(11) unsigned` 规范为 `int unsigned`；不消除signedness、精度或实际字节长度。`无`表示无显式DEFAULT，不是0或空串；`NULL`表示nullable DEFAULT NULL。

### 2.1 user及store_order

来源：真实安装SQL `crmeb/public/install/crmeb.sql:51615–51677`、`:28363–28463`；保存版本列及商家归属索引另来自原 `marketplace-ms4.php::additions()`，不从不存在的旧安装列猜测。

| 原表.列 | 规范原生类型 | NULL | DEFAULT / 自增 |
| --- | --- | --- | --- |
| user.uid | int unsigned | 否 | 无；AUTO_INCREMENT |
| store_order.id | int unsigned | 否 | 无；AUTO_INCREMENT |
| store_order.uid、mer_id | int unsigned | 否 | 0 |
| store_order.pid | int | 否 | 0 |
| store_order.order_id | varchar(32) | 否 | 字符串0 |
| store_order.unique | char(32) | 否 | 空串 |
| store_order.cart_id | text，utf8mb4 | 是 | NULL |
| store_order.pay_uid | int（有符号） | 否 | 0 |
| store_order.pay_price | decimal(12,2) unsigned | 否 | 0.00 |
| store_order.paid | tinyint unsigned | 否 | 0 |
| store_order.pay_time、add_time | int unsigned | 否 | 0 |
| store_order.pay_type | varchar(32) | 否 | 空串 |
| store_order.trade_no | varchar(100) | 否 | 空串 |
| store_order.status、is_system_del | tinyint | 否 | 0 |
| store_order.is_cancel、is_del、refund_status | tinyint unsigned | 否 | 0 |
| store_order.marketplace_trade_version | tinyint unsigned | 是 | 0；原MS4追加合同 |

`user`必需原 `PRIMARY(uid)`。`store_order`必需 `PRIMARY(id)`、唯一`(unique)`、唯一`(order_id,uid)`及非唯一`(mer_id,id)`。非主键以完整列序和索引属性匹配，不把本说明中的角色名称当作新增索引名；不重命名或补建。文本字段必须保持utf8mb4，保留该原表实际utf8mb4排序规则，不转换整表或字段为新表ascii_bin。

这些只读检查覆盖实际UID共同锁、真实商业根/pid、原金额和付款字段，以及正常/取消/历史状态的结构前提。它们不验证某条业务行，不扩大原服务的root/UID有效边界，也不将原版本NULL/0/'0'改成新V1。

### 2.2 原创建ledger

来源：原 `marketplace-ms4.php::newTables()['marketplace_order_idempotency']`。新CLI直接复用该已选原结构描述，不另抄一套可能漂移的原ledger类型；原MS4 completed检查仍要求其完整合同。

| 原ledger列 | 规范原生类型 | NULL | DEFAULT |
| --- | --- | --- | --- |
| uid、mer_id | int unsigned | 否 | 无 |
| idem_key | varchar(128)，ascii_bin | 否 | 无 |
| request_digest、confirmation_ref | binary(32) | 否 | 无 |
| source_kind | varchar(16)，ascii_bin | 否 | 无 |
| cart_refs | json | 否 | 无 |
| state | tinyint unsigned | 否 | 0 |
| order_id | int unsigned | 是 | NULL |
| post_commit_done | tinyint unsigned | 否 | 0 |
| cancel_at、add_time | int unsigned | 否 | 无 |

原索引为 `PRIMARY(uid,idem_key)`、`uq_ms4_uid_confirmation(uid,confirmation_ref)`唯一、`uq_ms4_created_order(order_id)`唯一、`idx_ms4_finalize(post_commit_done,order_id)`非唯一、`idx_ms4_cancel(state,cancel_at,order_id)`非唯一。这是原共同锁、完整确认绑定、保存期限和原收尾的读取前提，不将原ledger当支付attempt，也不改变其值。

### 2.3 原商家

来源：`marketplace-ms1.php::definitions()` 的真实商家合同（366–375行），原MS4前置还会只读核MS1结构。

| 原商家列 | 规范原生类型 | NULL | DEFAULT / 自增 |
| --- | --- | --- | --- |
| mer_id | int unsigned | 否 | 无；AUTO_INCREMENT |
| review_status、accept_orders、security_locked | tinyint unsigned | 否 | 0 |
| fulfillment_enabled | tinyint unsigned | 否 | 1 |
| closed_at | int unsigned | 是 | NULL |

必需原 `PRIMARY(mer_id)`。这些是新准入和经营异常/履约限制分流的结构前提；迁移不据这些标志应用付款，不写商家字段，也不解除hold。原商家已有的MS1 CHECK约束不被本次删除。

## 3. 新两表的精确结构

两表均为InnoDB、默认utf8mb4_bin；所有本轮字符串列显式CHARACTER SET ascii、COLLATE ascii_bin。无FK、CHECK、生成列、触发器或其他索引。完整列序、每列COMMENT和表marker均逐项核对；COMMENT字面值严格取物理设计§17.1，真实声明集中在 `newTables()`，不依赖当次Run或时间。

registry恰11列、顺序如下：

| 列 | 原生类型 | NULL | DEFAULT / 自增 |
| --- | --- | --- | --- |
| order_id | int unsigned | 否 | 无；不自增 |
| uid | int unsigned | 否 | 无 |
| mer_id | int unsigned | 否 | 无 |
| payment_contract_version | tinyint unsigned | 否 | 无 |
| born_at | int unsigned | 否 | 无 |
| attempt_generation | bigint unsigned | 否 | 0 |
| winner_attempt_id | bigint unsigned | 是 | NULL |
| fulfillment_hold | tinyint unsigned | 否 | 0 |
| hold_reason | varchar(32)，ascii_bin | 是 | NULL |
| hold_attempt_id | bigint unsigned | 是 | NULL |
| hold_at | int unsigned | 是 | NULL |

仅 `PRIMARY(order_id)`、`uq_ms5_payment_winner(winner_attempt_id)`唯一。**registry没有id列或自增主键**，真正商业根PK直接是一对一身份；结构存在不能证明某根已经同事务出生。

attempt恰18列、顺序如下：

| 列 | 原生类型 | NULL | DEFAULT / 自增 |
| --- | --- | --- | --- |
| id | bigint unsigned | 否 | 无；AUTO_INCREMENT |
| order_id | int unsigned | 否 | 无 |
| uid | int unsigned | 否 | 无 |
| mer_id | int unsigned | 否 | 无 |
| payment_contract_version | tinyint unsigned | 否 | 无 |
| admitted_generation | bigint unsigned | 否 | 无 |
| order_no | varbinary(32) | 否 | 无 |
| idem_key | varchar(128)，ascii_bin | 否 | 无 |
| amount | decimal(12,2) unsigned | 否 | 无 |
| currency | char(3)，ascii_bin | 否 | 无 |
| account_snapshot | json | 否 | 无；无JSON DEFAULT |
| request_digest | binary(32) | 否 | 无 |
| binding_digest | binary(32) | 否 | 无 |
| account_domain_digest | binary(32) | 否 | 无 |
| created_at | int unsigned | 否 | 无 |
| attempt_ref | varchar(45)，ascii_bin | 是 | NULL |
| applied_trade_no | varchar(45)，ascii_bin | 是 | NULL |
| evidence | json | 否 | 无；无JSON DEFAULT |

索引只有以下6项，均完整、升序、可见BTREE，无前缀或表达式：

| 名称 | 唯一 | 完整列序 |
| --- | --- | --- |
| PRIMARY | 是 | id |
| uq_ms5_payment_order_idem | 是 | order_id,idem_key |
| uq_ms5_payment_order_generation | 是 | order_id,admitted_generation |
| idx_ms5_payment_order_id | 否 | order_id,id |
| uq_ms5_payment_account_attempt | 是 | account_domain_digest,attempt_ref |
| uq_ms5_payment_account_trade | 是 | account_domain_digest,applied_trade_no |

BINARY(32)是SHA256原始32字节，不能替换为64位hex文本；VARBINARY保留订单号完整字节，不trim或折叠大小写。JSON列是原生JSON，不用LONGTEXT替代；无JSON默认值，完整8键账户及6顶层evidence须由后继真实producer显式给出并校验，结构CLI不制造这些行。

attempt.id/G的原生范围是U64，但该CLI只检查结构，不创建或强转这些业务值；后继PDO/wire仍须使用规范十进制字符串、精确加一及上限门禁，不能经PHP int/float或JS Number。可空UNIQUE的NULL也不证明未发送或已关闭。

## 4. 执行、部分DDL及收据

`check`只查询原生元数据和只读前置：缺表为 `applicable`，精确完成为 `completed`，两者native_exit=0；结构不兼容为2。配置、连接、检查、锁、DDL、后检或释放锁失败为1。没有对既存不兼容结构进行修理的路径。

`apply-structure`沿预检→GET_LOCK→锁内重检→规定CREATE→完整后检→finally RELEASE_LOCK。锁名精确为 `ms5-payment:` 加 SHA256(database、单NUL、prefix、单NUL、MARKER)的前48位小写hex，等待10秒。释放锁必须实际返回1。真实数据库名和锁名不进入公开收据。

只创建缺失的整表，顺序registry→attempt；不用IF NOT EXISTS吞掉同名结构。已有表必须完整相同，额外列/索引、不同类型、NULL/default/字符集/注释等均拒绝。两表均缺时pending恰两项；已有一张精确表时只补另一张；精确完成后重复check/apply的applied/pending都为空、changed=false。已保存业务行不会成为DROP、重建、自增重置或数据清理的理由。

收据保留 `mode/state/native_exit/changed/applied_steps/pending_steps/pending_steps_known/issues/failure_phase/marker/source_sha256`；另外明确历史映射NOT RUN、付款producer NOT IMPLEMENTED、验收NOT RUN。`source_sha256`取实际CLI文件字节。

`changed`只表示已经实际尝试执行DDL，不证明执行成功或整体完成；`applied_steps`仅在该原生exec返回后记录。DDL可能部分提交，不能称可事务回滚。失败会重新inspect当前已做/待做；重检亦失败时 `pending_steps_known=false`，空pending不能当作完成。保留真实失败phase和此前applied，不自动重试或补造成功。

## 5. 本轮验证边界与后继责任

初次两文件交付仅为SOURCE，当时没有PHP解析、CLI help/check/apply、DDL、测试、Git、App或实例操作；后继Root有限检查见§6。仍不能报告结构已在MySQL生效。

后继普通结构入口须另按物理§17.3落地10项完整来源闭集，实际check→apply-structure→check→apply-structure四个原生子进程及独立元数据核验。全库原行保护必须binary-safe：不能把含原始BINARY摘要的PDO行直接json_encode；须使用原生serialize或同等明确逐类型/NULL/字节编码，对完整行摘要排序且保留重复项。原表/列/行/索引/约束/触发器、SQL mode、Redis值及绝对到期时间都保持；本CLI自身不读取或证明这些业务行/Redis事实。

本源码没有实现registry真实出生、admission最外层提交后的一次context、driver门禁、完整evidence/原query成功/winner/root原子应用、取消两次锁、消费者或正常paid helper。25/27/34完整事实与registry按order_id排序等后继责任仍在PP清单。不得直接插付款行、伪造响应或paid夹具来补覆盖；真实渠道/Q4/Q5、退款、清结算、历史V1认领/回滚、真实持久履约及用户停止样本均未开通。

## 6. Root有限源码与PHP解析检查点

2026-10-17，Root完整读回两文件及§14/17合同，独立SOURCE复核通过。最终PHP为26886字节，SHA256为0ec8b1694cd250d92c4aa0638243363bc0f3bbe4ed3f970f54a5039aadf1c67b。Root实际执行仓库外有限checker，在既有PHP镜像的无网络、只读挂载、无业务配置/卷的容器内，仅由原生PHP8.2.33执行该文件的`php -l`；子进程及外层均exit0，stderr为空，PHP和初交付说明的前后字节保持。没有require该迁移、调用CLI main、创建App或执行SQL/HTTP/队列。

实际收据为H:/天俪商城/artifacts/ms5-payment-source/20261017-migration-r1/php-syntax-result.json，SHA256为e27550edbe6a191de23bc3a798cc8deb329a7151ae6f38fb766efbfd8ce4cb33；checker AST实际通过，其源摘要b048d748e738a238bdf97909101cbd7ea83b74bb34ab6c286e7a2095e81b676a。收据中的说明摘要绑定追加本节前的真实初交付版本，不把文档变更冒称重新解析或迁移运行；PHP源码保持上述同一字节。新隔离入口、真实check/apply/check/apply、元数据/非目标行保持及正常关闭/核零均未完成，PP1继续未勾选，PP2–PP6和已停止样本的状态保持。
