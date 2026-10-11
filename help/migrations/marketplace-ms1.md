# MS1 身份迁移设计

本文件是 `06-plan-ms1.md` Task 2 的实施依据，冻结五张身份表与七条平台接口登记；2026-10-12经实源与独立只读审查，修正MySQL自增列CHECK限制后作为编码依据，尚无应用或验收结论。依据：`00-implementation-spec.md` §3/5/6/7、06 Task 2–6、07 Task 8 的公众店铺字段；实源为 `help/migrations/theme-module.php`、`crmeb/public/install/crmeb.sql:48660` 及 `app/services/system/admin/SystemRoleServices::verifyAuth()`。真实库结构和在用情况未核对。

## 表与写入合同

- 以下 SQL 用示例前缀 `eb_`。实现只从受控数据库配置读取前缀，验证拼接后的表/约束标识符后替换；不接受请求中的前缀、商家 ID 或数据库连接信息。MySQL 8.0、InnoDB、utf8mb4；精确检查列、默认值、排序规则、主键/索引、外键和生效的 CHECK，不靠 `CREATE TABLE IF NOT EXISTS` 掩盖异构结构。
- 商家只有 `mer_id` 主键，不另设 `id`/`merchant_id`。另外四表均有自增 `id` 和无默认值的 `mer_id INT UNSIGNED NOT NULL`。所有 ID 为正数；新表内部外键均 `ON UPDATE RESTRICT ON DELETE RESTRICT`，不引用或改造旧表。商家关闭、员工停用/软删除保留历史。
- MySQL 8不允许CHECK引用AUTO_INCREMENT列：自增主键由服务省略该列、数据库从1正常生成，服务/上下文仍要求真实正整数；不为零或NULL分配业务含义。四关联表的非自增mer_id及相关role/employee/actor引用保留正数CHECK。RESTRICT与这些CHECK兼容，禁止会改写检查列的参照动作；不新增trigger或第六表。[自增规则](https://dev.mysql.com/doc/refman/8.0/en/numeric-type-attributes.html)、[MySQL 8.0外键检查源码](https://github.com/mysql/mysql-server/blob/8.0/sql/sql_table.cc#L6153-L6172)。这是尚未执行草稿的DDL合法性修正，不运行非法DDL探针。
- 时间为 UTC Unix 秒 `INT UNSIGNED`，写方明确提供，不使用数据库时区或隐式当前时间。`add_time` 是创建时刻，`update_time` 是最后正常修改时刻；`closed_at`/`consumed_at` 未发生时为 `NULL`。所有未在 SQL 中声明 DEFAULT 的列都没有默认值。
- 商家公开资料只含已规划的 `name`、`logo`、`after_sale_contact`、`shipping_note`。名称必填且不要求全局唯一；后三项缺省空串。`review_status` 默认待审，`accept_orders=0`、`fulfillment_enabled=1`、`security_locked=0`。审核与接单独立，只有后续显式设置才能接单；MS1 不开放商品/订单。关闭前责任检查属于后续生命周期服务，不能只写 `closed_at` 代替该检查。
- 员工账号先验证有效 UTF-8，再执行 PHP `trim()` 的 ASCII 首尾空白集合去除和 `strtr('A…Z', 'a…z')` 式 ASCII 折叠；不对非 ASCII 字符大小写或组合形式做归一化。结果非空、至多 191 个字符，以 `utf8mb4_bin` 保存且全局唯一，软删除后也不复用。员工只能绑定本商家一个 `role_id`。新员工 `pwd=NULL`，尚不能登录；正常设置后只保存 `password_hash()` 结果，长度上限 255，不保存明文。
- 角色 `name` 为本商家内唯一的去首尾空白非空名称。`permissions` 是无重复字符串组成的 JSON 数组，写入前逐项验证为 `MerchantPermissions::ALL` 的子集；JSON 列和 ARRAY CHECK 不替代权限码验证。每次认证请求重新读当前员工、同商家角色及商家状态，不从令牌复用旧权限。管理员/运营预置权限由服务按 06 Task 3 创建本商家角色；迁移不插入商家、角色或员工种子。
- 设置能力由 `random_bytes(32)` 编码为无填充 base64url（43 字符）；数据库只存其 SHA-256 原始 32 字节，`expires_at=add_time+86400`。正常消费在一个数据库事务中锁定哈希对应行，确认未消费且在期限内，取得持久化员工及商家归属，写密码哈希并设置 `consumed_at`；不能由输入的 `mer_id` 决定归属。原能力/完整入口链接只向本次获授权创建者返回，不落库、日志或审计。
- 审计记录在取得目标真实正 `mer_id` 后写入。`actor_type`/`actor_id` 为已验证平台或商家执行者；任务使用重建上下文中的员工身份。`action`、`resource_type`、`resource_id`、`result` 由服务生成，不直接保存任意请求内容；`detail` 是服务白名单生成的 JSON 对象，空详情编码为 `{}`，不含密码、密码哈希、令牌、设置能力/链接、连接信息或未经筛选的请求/响应。全局只读平台列表沿现有平台请求审计，不伪造新商家 ID。

## 完整 DDL

创建顺序按以下排列。表 COMMENT 是本版本迁移标记；所有字符串默认 `utf8mb4_bin`，避免账号或角色名称被不相同的非 ASCII 文本折叠。内部组合外键所需的 `(mer_id,id)` 唯一键有意保留，保证角色/员工所属商家一致。

```sql
CREATE TABLE `eb_marketplace_merchant` (
  `mer_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `logo` VARCHAR(512) NOT NULL DEFAULT '',
  `after_sale_contact` VARCHAR(255) NOT NULL DEFAULT '',
  `shipping_note` VARCHAR(1000) NOT NULL DEFAULT '',
  `review_status` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `accept_orders` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `fulfillment_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `security_locked` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `closed_at` INT UNSIGNED NULL DEFAULT NULL,
  `add_time` INT UNSIGNED NOT NULL,
  `update_time` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`mer_id`),
  KEY `idx_review_mer` (`review_status`, `mer_id`),
  CONSTRAINT `eb_mm_mer_review` CHECK (`review_status` IN (0, 1, 2)),
  CONSTRAINT `eb_mm_mer_flags` CHECK (`accept_orders` IN (0, 1) AND `fulfillment_enabled` IN (0, 1) AND `security_locked` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='marketplace-ms1-v1';

CREATE TABLE `eb_marketplace_role` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mer_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `permissions` JSON NOT NULL,
  `add_time` INT UNSIGNED NOT NULL,
  `update_time` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mer_role_id` (`mer_id`, `id`),
  UNIQUE KEY `uq_mer_role_name` (`mer_id`, `name`),
  CONSTRAINT `eb_mm_role_positive` CHECK (`mer_id` > 0),
  CONSTRAINT `eb_mm_role_permissions` CHECK (JSON_TYPE(`permissions`) = 'ARRAY'),
  CONSTRAINT `eb_mm_role_merchant_fk` FOREIGN KEY (`mer_id`) REFERENCES `eb_marketplace_merchant` (`mer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='marketplace-ms1-v1';

CREATE TABLE `eb_marketplace_employee` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mer_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `account` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `pwd` VARCHAR(255) NULL DEFAULT NULL,
  `name` VARCHAR(64) NOT NULL DEFAULT '',
  `avatar` VARCHAR(512) NOT NULL DEFAULT '',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_del` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `add_time` INT UNSIGNED NOT NULL,
  `update_time` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_account` (`account`),
  UNIQUE KEY `uq_mer_employee_id` (`mer_id`, `id`),
  KEY `idx_mer_employee_role` (`mer_id`, `role_id`),
  CONSTRAINT `eb_mm_emp_positive` CHECK (`mer_id` > 0 AND `role_id` > 0),
  CONSTRAINT `eb_mm_emp_flags` CHECK (`status` IN (0, 1) AND `is_del` IN (0, 1)),
  CONSTRAINT `eb_mm_emp_role_fk` FOREIGN KEY (`mer_id`, `role_id`) REFERENCES `eb_marketplace_role` (`mer_id`, `id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='marketplace-ms1-v1';

CREATE TABLE `eb_marketplace_audit_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mer_id` INT UNSIGNED NOT NULL,
  `actor_type` VARCHAR(16) NOT NULL,
  `actor_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `resource_type` VARCHAR(64) NOT NULL,
  `resource_id` VARCHAR(64) NOT NULL,
  `result` VARCHAR(16) NOT NULL,
  `detail` JSON NOT NULL,
  `add_time` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mer_audit_id` (`mer_id`, `id`),
  CONSTRAINT `eb_mm_audit_positive` CHECK (`mer_id` > 0 AND `actor_id` > 0),
  CONSTRAINT `eb_mm_audit_detail` CHECK (JSON_TYPE(`detail`) = 'OBJECT'),
  CONSTRAINT `eb_mm_audit_merchant_fk` FOREIGN KEY (`mer_id`) REFERENCES `eb_marketplace_merchant` (`mer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='marketplace-ms1-v1';

CREATE TABLE `eb_marketplace_password_setup` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mer_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `token_hash` BINARY(32) NOT NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  `consumed_at` INT UNSIGNED NULL DEFAULT NULL,
  `add_time` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setup_hash` (`token_hash`),
  KEY `idx_mer_setup_employee` (`mer_id`, `employee_id`),
  CONSTRAINT `eb_mm_setup_positive` CHECK (`mer_id` > 0 AND `employee_id` > 0),
  CONSTRAINT `eb_mm_setup_expiry` CHECK (`expires_at` = `add_time` + 86400),
  CONSTRAINT `eb_mm_setup_employee_fk` FOREIGN KEY (`mer_id`, `employee_id`) REFERENCES `eb_marketplace_employee` (`mer_id`, `id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='marketplace-ms1-v1';
```

索引只覆盖必需访问：商家审核列表、商家内角色/员工列表与同店关系、全局账号登录、商家审计分页、能力哈希查找。无需冗余的单列 `mer_id` 索引；相关组合键均以它开头。不添加资质、支付、商品、订单或通用扩展字段，第六张表与旧表列变更不属于 MS1。

## 七条平台接口登记

`system_menus` 的安装结构有 22 列，`id` 是 `SMALLINT UNSIGNED AUTO_INCREMENT`，没有接口或 `unique_auth` 唯一索引。不得指定或猜测 ID，也不得为幂等性改旧表索引。07/08 后续页面与商品审核登记不提前插入。

每行固定公共值：`pid=0, icon='', module='', controller='', action='', params='[]', sort=1, is_show=1, is_show_path=1, access=1, menu_path='', path='', auth_type=2, header='', is_header=0, is_del=0, mark='marketplace-ms1-v1'`。以下给出剩余 `menu_name/api_url/methods/unique_auth`；使用框架规范化路由的 `<id>`，不含 `/adminapi/` 前缀。这些是可授予的接口叶节点，不生成指向未完成页面的可见导航。

| menu_name | methods | api_url | unique_auth |
|---|---|---|---|
| 商家列表 | GET | marketplace/merchants | marketplace-merchants-list |
| 新建商家 | POST | marketplace/merchants | marketplace-merchants-create |
| 商家详情 | GET | marketplace/merchants/<id> | marketplace-merchants-read |
| 修改商家 | PUT | marketplace/merchants/<id> | marketplace-merchants-update |
| 审核商家 | PUT | marketplace/merchants/<id>/review | marketplace-merchants-review |
| 商家开关 | PUT | marketplace/merchants/<id>/flags | marketplace-merchants-flags |
| 商家管理员 | POST | marketplace/merchants/<id>/admin-employee | marketplace-merchants-admin-employee |

预检对每个权限标记和 `verifyAuth()` 使用的规范化 `(methods,api_url)` 分别查重；同一权限标记绑定别的接口、已有接口绑定别的标记或多行匹配均不覆盖。完成状态逐行比对全部 21 个非 ID 列及本版标记，同时保存实际新增 ID 清单；不以 `LIKE 'marketplace-%'` 概括认领其他版本数据。受限平台角色不会被迁移自动授予这些 ID，旧 `merchant-*` 权限保持原含义。应用登记后的 `all_auth` 及实际受影响权限缓存需由 Task 6 的正常应用路径精确清理，不能通过全库/全 Redis 清空实现。

## CLI 与幂等检查

计划入口为 `php help/migrations/marketplace-ms1.php check|apply`，兼容 `--check/--apply`；默认 `check`，纯 PDO 检查/应用，不启动 Installer 或业务服务。检查以下三类状态后才决定是否执行：

默认连接/前缀只在内存解析本地crmeb/.env。可由CLI操作者显式提供 `--config-file=PATH` 私有JSON文件，包含hostname/hostport/database/username/password/prefix；不接受连接串、命令行密码或HTTP输入，输出不回显配置。ISO普通核对在既有audit-bootstrap服务中使用专用audit_migrator（仅本RUN audit_auth库的既有DDL权限），不把migrator/root私有配置挂入runner或应用，不修改原DML guard或账号权限。核对脚本先用audit_app通过原隔离保护，再于bootstrap专属secret挂载中创建本RUN唯一0600 CLI配置；共享RUN目录也挂入app/worker，不能放DDL配置。使用proc_open参数数组执行真实check/apply命令并记录各原生退出码，finally只移除本次成功创建的配置，拒绝时不删除既存文件。

check只读取完整元数据/菜单。apply在当前数据库取得本迁移命名锁，再重新全量预检、依次创建、事务登记、完整复检，finally释放命名锁。没有表恢复/删除命令；DDL中断后列出当前实际存在的本版目标，不伪造全事务回滚。结构核对覆盖精确有序列、类型/长度/默认/可空/字符集排序规则、主键/索引、内部组合FK的顺序/目标/RESTRICT，以及实际ENFORCED CHECK表达式；不把不相关结构或已有业务行归为可改写资源。七行菜单全部非ID列精确比对，不改原角色授权或权限缓存。

1. `applicable`：已安装且兼容的 `system_menus` 基表存在，五目标表均不存在，七个权限标记及规范化接口键均不存在。本版可创建五张空表和七行接口登记；不向新身份表写种子。
2. `completed`：五目标表与本版完整结构/标记一致、七行登记精确一致，无重复。只读返回 `changed=false`；后来合法产生的商家、员工等业务数据不是再次应用的改写对象。
3. `incompatible`：缺少安装基表、部分目标/登记存在、结构/标记不符或登记冲突。不开始应用，不自动 ALTER、补写、覆盖或删除。MySQL DDL 隐式提交：创建期间若真实失败，报告当前实际存在的部分资源并失败退出，不能声称整体事务回滚，也不自动删表恢复。

`apply` 先做全量预检，再按 DDL 顺序创建五表，最后在普通数据库事务内插入七行并保存实际 ID。完成后重新完整检查；二次 `apply` 只报告 `completed`，所有行与身份表保持。输出只含状态、目标名称、行数/ID、变更摘要和问题类别，连接信息、凭据、真实行内容不输出。首版不提供删除或恢复子命令。

## 普通隔离核对

在新的 ISO-FULL、安装种子基表已存在的专用库，由专用 DDL 账号执行；应用仍使用原 DML 账号。本设计没有运行结论。Task 2 后续核对：

- 原始 `check` 报 `applicable`；首次 `apply` 创建恰好五张空表和七个接口行；再次 `check/apply` 报 `completed`，新增菜单 ID/内容不重复、五表仍为空。
- 前后所有旧表的列、索引、外键及旧行保持。除 `system_menus` 外，旧表 `SHOW CREATE TABLE`/可用 `CHECKSUM TABLE` 保持；菜单只允许上述七个实际新 ID/精确内容和自然 AUTO_INCREMENT 前进，其余 DDL 与按原 ID 逐行内容保持。不能要求菜单新增后整体 checksum 不变，不能回拨计数器。
- 用真实元数据确认五表无同义商家键、`mer_id` 无默认值、索引/FK/CHECK 与本设计一致。记录原生退出码及证据摘要；新表/登记留在本 RUN 供后续正常功能使用，最终随已确认的整个专用实例销毁。
- 按用户持续指示，异构预置、越权/无效能力、错误输入、重复消费、故障、并发/断连及恢复场景不运行，保留原用例 NOT RUN；普通两次迁移通过不能代替这些验收，也不能宣称完整 G/M 系列通过。
