# ThemeModule 表迁移

此迁移补齐主题组件功能使用的 `<数据库前缀>theme_module` 表，仅操作这一张表。默认读取 `crmeb/.env` 的数据库连接与前缀；不启动应用、不依赖 Redis，也不修改其他历史模型对应的表。

## 结构来源

当前工作区未包含安装 SQL；已核实基线提交 `e9d6c4675`、备份前提交 `3e2a1a158149dbf35b4438f5d317b6ec2316e166` 的 `crmeb/public/install/crmeb.sql`，均未提供该表定义。此处结构是根据现有源码契约补建的本地最小结构，不声称恢复了官方完整建表语句：

- `crmeb/app/model/diy/ThemeModule.php`：主键 `id`，字段 `id/type/data`。
- `crmeb/app/dao/diy/ThemeModuleDao.php`：按 `type` 筛选，按 `id` 排序及统计。
- `crmeb/app/services/diy/ThemeModuleServices.php`：新增、读取、修改、删除；`data` 保存 JSON，也允许新建时仅提供 `type`。

| 字段 | 定义 |
| --- | --- |
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` |
| `type` | `VARCHAR(100) NOT NULL DEFAULT ''`，普通索引 |
| `data` | `LONGTEXT NULL DEFAULT NULL`；读取空值时服务返回空数组 |

使用 InnoDB、utf8mb4；新表默认排序规则为 `utf8mb4_unicode_ci`。表注释 `audit-theme-module-20261010` 用于识别此迁移创建的表。

## 执行

在仓库根目录运行，先检查目标数据库和当前结构：

```sh
php help/migrations/theme-module.php
```

默认与 `--check` 相同：只查元数据，不创建表。输出 JSON 中包含目标数据库、表名、结构状态与差异；兼容时退出码为 `0`，缺表或不兼容时为 `2`。参数错误、连接失败或拒绝执行时退出码为 `1`。输出不包含凭据。

确认目标并保存原表结构快照后，显式创建缺失表：

```sh
php help/migrations/theme-module.php --apply
```

已有表会先核对三字段、类型、默认值、可空性、字符集、主键、自增和引擎；兼容则保持不变，不兼容则拒绝，不会自动 `ALTER` 或覆盖数据。检查允许 utf8mb4 的现有排序规则和普通索引，但拒绝额外字段或额外唯一约束。

如果应用数据库账号缺少建表权限，可显式使用保存 root 密码的本地文件；密码内容不会进入命令行或日志。无需更改应用账号授权：

```sh
php help/migrations/theme-module.php --check --root-password-file=/absolute/path/to/private-password-file
php help/migrations/theme-module.php --apply --root-password-file=/absolute/path/to/private-password-file
```

`--database=NAME` 可显式选择同一服务器上的目标数据库；省略时使用 `.env`。不要将密码文件提交到仓库。

## 空表回滚

仅在需要撤销这次新增表，且确认没有主题组件数据时执行：

```sh
php help/migrations/theme-module.php --rollback-empty
```

该命令要求表结构兼容、迁移标记匹配，并在写锁内重新确认表为空后才删除。已有数据、结构差异或缺少本迁移标记均拒绝。需要 `LOCK TABLES` 和 `DROP` 权限；必要时使用同样的 `--root-password-file` 参数。对已有业务数据的表，先进行单独的数据恢复设计，不使用此回滚命令。

## 隔离回归

```sh
AUDIT_DB_PASSWORD_FILE=/absolute/path/to/private-password-file php help/audit-regressions/theme-module.php
```

测试只创建随机 `audit_schema_<16位十六进制>` 数据库，在其中执行真实 ThemeModule 模型、DAO、服务的 CRUD、迁移幂等、结构差异拒绝及空表回滚测试，最终删除该隔离库；不复制业务数据，不修改业务库。账号需要建库/删库权限，未提供密码文件则使用 `.env` 账号。

加上 `--before-migration` 可复现缺表故障：在未执行迁移的隔离库调用真实组件列表服务，预期以 `SQLSTATE[42S02]` 失败，退出码为 `1`，随后清理隔离库。
