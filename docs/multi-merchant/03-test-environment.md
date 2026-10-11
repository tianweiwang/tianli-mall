# 测试环境、测试分层与证据格式

适用于 MS0a 及之后所有里程碑。测试脚本统一放在 `help/audit-regressions/`（决策 D6）；MS0a 起新增的隔离测试放在 `help/audit-regressions/isolated/`。

## 1. 测试分层

每条结果都要标明层级。不同层级的通过数不能相加，也不能用低层级的通过抵消高层级的失败。

| 层级 | 定义 | 能证明什么 | 不能证明什么 |
|---|---|---|---|
| `UNIT-STUB` | 加载真实类，依赖用内存替身（如现有 `security.php`） | 分支逻辑 | 真实 Redis、数据库、HTTP、框架装配 |
| `INTEGRATION` | 真实应用通过 HTTP 访问，连接隔离的 MySQL 与 Redis | 接口行为与数据库/缓存副作用 | 常驻进程复用、浏览器行为 |
| `WORKER` | 在实际使用的常驻进程（队列消费者、Workerman）中执行 | 上下文与对象跨任务不残留 | 其他进程类型 |
| `BROWSER` | 真实前端构建产物 + 真实 API + 测试库 | 页面与接口联动 | 真机、生产域名 |
| `EXTERNAL` | 真实支付机构、真实第三方调用方 | 外部契约 | —— 需要单独授权 |

## 2. 隔离等级

| 等级 | 适用 | 要求 |
|---|---|---|
| `ISO-MIN` | MS0a | 专用 MySQL、Redis 容器（新 compose project、新卷、随机凭据）；端口只绑定 `127.0.0.1` 的临时端口；应用用宿主机 PHP 8.2 CLI 内置服务器从**测试 worktree** 启动；测试账号无全局权限；哨兵校验 |
| `ISO-FULL` | MS0b 起 | 在 `ISO-MIN` 基础上：runner 容器与应用容器都在内部网络，不映射宿主端口；执行安全用例时禁止对外出站；常驻进程（队列、Workerman）按实际部署方式启动以支持 `WORKER` 层 |

两种等级都禁止：连接业务实例或共享实例；读取现有实例的 root 密码；从业务库复制数据；`DROP` 通配库、`FLUSHALL`、清业务卷；写备份检出（`$BACKUP`）的任何文件。

## 3. ISO-MIN 环境（MS0a 交付）

### 3.1 文件

| 路径 | 职责 |
|---|---|
| `help/audit-regressions/isolated/README.md` | 使用说明：启动、运行、清理、故障处理 |
| `help/audit-regressions/isolated/compose.yml` | 服务 `audit-mysql`（`ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45`，与部署一致）、`audit-redis`（`ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1`）；命名卷；标签 `tianli.audit=1`；端口 `127.0.0.1::3306`、`127.0.0.1::6379` |
| `help/audit-regressions/isolated/bootstrap.sh` | 生成 `RUN_ID`、运行目录、随机凭据；启动 compose；建库建账号；导入夹具；写哨兵；写 manifest |
| `help/audit-regressions/isolated/teardown.sh` | 只按 manifest 停止并删除本次 compose project 及其卷 |
| `help/audit-regressions/isolated/lib/guard.php` | 启动保护（§3.4） |
| `help/audit-regressions/isolated/lib/harness.php` | 用例记录、HTTP 客户端、数据库与 Redis 快照、应用启停 |
| `help/audit-regressions/isolated/fixtures/ms0a.php` | MS0a 合成账号 |
| `help/audit-regressions/isolated/selftest.php` | 环境自检用例（Z02、Z06、Z07、Z09） |

### 3.2 运行目录与凭据

- `RUN_ID`：`date +%Y%m%d%H%M%S` + 6 位随机十六进制。
- 运行目录：`AUDIT_RUN_DIR=${AUDIT_RUN_ROOT:-$HOME/.tianli-audit}/$RUN_ID`，权限 `0700`，位于仓库外。
- `bootstrap.sh` 用 `openssl rand -hex 24` 生成 MySQL root、`audit_migrator`、`audit_app`、Redis 密码以及各合成账号的密码，只写入运行目录（文件权限 `0600`）。凭据不进仓库、不进命令行参数、不进测试输出。
- `manifest.json`（运行目录内）：`run_id`、compose project 名 `tianli-audit-<RUN_ID>`、容器 ID、宿主端口、库名、夹具来源及其 SHA-256、创建时间。

### 3.3 数据库与账号

| 账号 | 权限 | 谁使用 |
|---|---|---|
| MySQL root（本实例新生成） | 全部 | 只有 `bootstrap.sh` 使用，用于建库和建账号；不传给测试脚本和应用 |
| `audit_migrator` | `audit_auth.*` 上的 DDL + DML | 夹具导入、迁移演练 |
| `audit_app` | `audit_auth.*` 上的 `SELECT, INSERT, UPDATE, DELETE` | 应用与测试脚本 |

- 库名固定为 `audit_auth`（实例本身是专用的）。
- 结构与种子数据来源：BASE 中跟踪的 `crmeb/public/install/crmeb.sql`（F-09）。导入前去掉 CR，记录 SHA-256。这是上游安装种子（菜单、配置等），不是业务数据。
- `eb_theme_module` 按需用 `help/migrations/theme-module.php` 在本库演练创建。
- 哨兵：表 `audit_sentinel(run_id varchar(64) primary key)`，写入 `RUN_ID`；Redis 键 `audit:sentinel` 写入 `RUN_ID`。

### 3.4 启动保护（`lib/guard.php`）

接口：`audit_guard_assert(): void`。任何写操作和应用启动之前必须调用；不满足以下任一条件就抛异常并以退出码 `2` 结束：

1. 环境变量 `AUDIT_ISOLATED=1`，且 `AUDIT_RUN_DIR/manifest.json` 存在，其中的 `run_id` 等于 `AUDIT_RUN_ID`。
2. 数据库主机为 `127.0.0.1`，端口等于 manifest 中的端口，库名为 `audit_auth`。
3. `SELECT run_id FROM audit_sentinel` 的结果等于 `AUDIT_RUN_ID`。
4. Redis 端口等于 manifest 中的端口，`GET audit:sentinel` 等于 `AUDIT_RUN_ID`。
5. 测试账号执行 `CREATE DATABASE audit_guard_probe` 必须失败（证明没有全局权限）。

### 3.5 应用启动

- 应用从**测试 worktree** 启动（例如 MS0a 的 `codex/ms0a` worktree），绝不从备份检出启动。
- 配置注入：先阅读 `crmeb/vendor/topthink/framework/src/think/Env.php`，确认 ThinkPHP 读取环境变量的方式。如果必须使用 `crmeb/.env` 文件，只能在该文件**不存在**时写入，并在首行写 `# AUDIT-RUN <RUN_ID>`；`teardown.sh` 只删除首行哨兵匹配的文件。对 `crmeb/public/install.lock` 采用同样规则。
- 启动命令：`php think run -H 127.0.0.1 -p <随机空闲端口>`（在 `crmeb/` 目录下执行），PID 写入运行目录。
- harness 接口 `audit_app_start(array $envOverrides = []): string`（返回 base URL）与 `audit_app_stop(): void`。A10 的配置错误用例通过 `$envOverrides` 用错误配置重新启动应用。

### 3.6 合成夹具（`fixtures/ms0a.php`）

接口：`ms0a_fixtures(PDO $migrator, string $runDir): array`，返回各账号的 id 与密码（密码同时写入运行目录）。**故意让不同账号表的数字 ID 重合**；最高权限由 `level = 0` 和角色决定，不依赖 `id = 1`。

| 键 | 表 | id | 关键属性 |
|---|---|---|---|
| `admin_super` | `eb_system_admin` | 101 | `level = 0`，`status = 1`，`is_del = 0` |
| `admin_limited` | `eb_system_admin` | 102 | `level = 1`，角色 `R-limited` 只授予"商品列表"读接口 |
| `admin_disabled` | `eb_system_admin` | 103 | `status = 0` |
| `admin_deleted` | `eb_system_admin` | 104 | `is_del = 1` |
| `out_101` | `eb_out_account` | 101 | `status = 1`，与 `admin_super` 撞 ID；授予一个只读对外接口 |
| `out_102` | `eb_out_account` | 102 | `status = 1`，与 `admin_limited` 撞 ID |
| `out_disabled` | `eb_out_account` | 105 | `status = 0` |
| `kefu_101` | `eb_store_service` | 101 | 启用的客服，与 `admin_super` 撞 ID |
| `user_101` | `eb_user` | 101 | 启用的顾客，与 `admin_super` 撞 ID |
| `user_102` | `eb_user` | 102 | 启用的顾客 |

若某张表的必填字段导致插入失败，按安装结构补齐中性值，并在报告中注明；不得改动表结构。

## 4. 用例脚本约定

### 4.1 接口（`lib/harness.php`）

```php
audit_case(string $caseId, string $subcase, string $layer, callable $fn): void
// $fn 返回 ['status' => 'PASS'|'FAIL'|'BLOCKED', 'expected' => string, 'actual' => string,
//           'evidence' => array]；抛异常记为 FAIL 并保存异常摘要。
audit_http(string $method, string $url, array $headers = [], mixed $body = null): array
// 返回 ['http' => int, 'json' => ?array, 'raw' => string]
audit_db(): PDO                       // audit_app 账号
audit_redis(): \Redis                 // 测试 Redis
audit_snapshot(array $spec): array    // $spec: ['tables' => [表名 => WHERE 子句或 null], 'redis' => [键模式]]
                                      // 返回每张表的行数与按主键排序的整行 SHA-256、每个 Redis 模式的键集合与值摘要
audit_assert_unchanged(array $before, array $after, array $allow = []): array  // 返回差异列表；$allow 为允许变化的表或键模式
audit_finish(): never                 // 汇总，写 JSONL，有 FAIL 时以退出码 1 结束，全部 PASS 以 0 结束
```

### 4.2 运行阶段

环境变量 `AUDIT_PHASE` 取 `baseline` 或 `after`：

- `baseline`：在修复前的提交（如 `AUDIT_BASE_SHA`）上运行，记录实际结果。用例的期望是"修复后的正确行为"，所以有漏洞时 baseline 显示 FAIL，这是预期的，不得为了 baseline 改期望。
- `after`：在修复后的提交上运行。

### 4.3 允许的副作用

负向用例前后做快照，只允许以下变化：

- 管理员操作日志表 `eb_system_log`（安全审计）。
- 登录失败计数等限流键（例如 Redis `login_captcha`）。

除此之外，业务表（商品、订单、用户、`eb_cache` 等）和业务缓存键都必须不变。

## 5. 证据格式

### 5.1 位置

- 原始输出（完整 HTTP 响应、日志、快照）写到仓库外：`$AUDIT_RUN_DIR/evidence/`。
- 仓库内只提交摘要：`docs/multi-merchant/records/<milestone>/results.jsonl` 和 `report.md`（见 [records/README.md](records/README.md)）。
- 令牌、密码、密钥一律不写入任何证据文件；需要时只写 SHA-256 前 12 位。

### 5.2 JSONL 记录（每个子场景一行）

```json
{
  "case_id": "A12",
  "subcase": "out-refresh-token-to-admin-super-id-collision",
  "milestone": "MS0a",
  "layer": "INTEGRATION",
  "phase": "after",
  "commit_sha": "<实际 SHA>",
  "run_id": "<RUN_ID>",
  "finding_kind": "待复现风险",
  "expected": "business status 401; no protected read; no business write",
  "actual": "business status 401",
  "status": "PASS",
  "db_diff": [],
  "cache_diff": [],
  "evidence_ref": "evidence/A12-out-refresh-super.json",
  "notes": ""
}
```

`finding_kind` 取值：`待复现风险`、`既有拒绝回归`、`既有修复回归`、`新增契约`。

## 6. 现有脚本的使用规则

| 脚本 | 层级 | 规则 |
|---|---|---|
| `security.php` | UNIT-STUB | 可以随时运行。BASE 保留原始 23 项；MS0a 先记录原始基线，Task 7 在 SP-MS0a-1 确认后，将 `unregistered route proceeds`、`unregistered method proceeds without warning`、`existing CRUD exemption proceeds` 三项收紧为拒绝并相应改名，其余 20 项保留；追加新用例，不删除覆盖、不放宽断言。此契约调整须用户批准。 |
| `commerce.php`、`wallet-admin.php`、`wallet-store.php`、`theme-module.php` | INTEGRATION（旧式） | 需要建库、删库权限，只能在本文件 §3 的专用实例里，由 `bootstrap.sh` 用该实例新生成的 root 凭据运行；MS0a 不要求运行 |
| `diy-http.py` | INTEGRATION（只读） | 运行前显式传入测试应用的 base URL |
| `frontend-*.cjs` | 前端 | 按 `frontend.md` |

## 7. 清理

- `teardown.sh <RUN_ID>`：读取 manifest，执行 `docker compose -p tianli-audit-<RUN_ID> down -v`，停止应用进程，删除带本次哨兵的 `crmeb/.env` 与 `install.lock`。
- 失败时保留运行目录作为证据，不自动删除。
- 不对 manifest 之外的任何容器、卷、数据库、键执行删除。
