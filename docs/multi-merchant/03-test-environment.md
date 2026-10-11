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
| `ISO-FULL` | MS0a 的 Windows 执行环境及 MS0b 起 | runner 容器与应用容器都在专用内部网络，不映射宿主端口；执行安全用例时禁止对外出站；常驻进程（队列、Workerman）按实际部署方式启动以支持 `WORKER` 层。MS0a 不由此获得 WORKER 层验收结果 |

两种等级都禁止：连接业务实例或共享实例；读取现有实例的 root 密码；从业务库复制数据；`DROP` 通配库、`FLUSHALL`、清业务卷；写备份检出（`$BACKUP`）的任何文件。

### 2.1 Windows 上的 MS0a 执行适配（2026-10-11 用户批准）

当前 Windows/WSL 无宿主 PHP 8.2，用户明确批准 MS0a 使用 ISO-FULL，并更新本文件、04 计划和启动脚本。业务契约、测试子场景和验收标准不变。

- 使用本机已有 `tianli/crmeb-php:acceptance`（运行时核对 PHP 8.2）；MySQL、Redis 镜像仍为 §3.1 的版本。所有服务 `pull_policy: never`，不依赖下载或业务容器。
- `audit-app`、`audit-runner`、`audit-bootstrap`、`audit-mysql`、`audit-redis` 仅连接本次 compose project 的 `internal: true` 网络，不映射端口，不挂载 Docker socket。源码来自当前测试 worktree；应用和 runner 只读挂载，运行缓存使用独立命名卷。
- 宿主运行目录只保存 manifest、控制记录和脱敏证据。随机密码、测试签名密钥和合成账号凭据保存在本次 project 的专用 Docker 卷中，目录/文件权限 0700/0600；root/migrator 卷只供引导使用，应用和 runner 只挂载受限账号所需的配置，runner 另挂合成账号凭据卷。不得在环境变量、命令行或日志中暴露密码。
- `bootstrap.sh` 在 Git Bash 或原生 Bash 执行，先检查所有镜像和版本、创建独立资源、导入安装种子，再记录容器 ID、卷名、内部网络与地址。`run.sh <RUN_ID> <脚本> [参数...]` 负责启动一次性 runner 和必要的服务故障场景；`teardown.sh` 按 manifest 与实际标签/ID 双重核对后仅清理本次资源。
- ISO-FULL 的启动保护沿用 §3.4 的 RUN_ID、双哨兵、库名、受限权限检查。主机固定为 `audit-mysql` / `audit-redis`、端口固定为 3306/6379，解析后的地址必须与 manifest 中经过 Docker 核验的本次容器地址一致。禁止接受调用方自选主机或业务实例地址。
- `audit_app_start()` / `audit_app_stop()` 通过本次运行目录中的受限控制记录请求专用应用容器启停 PHP 内置服务器；配置只从受限凭据卷注入 `PHP_*` 环境变量（ThinkPHP `Env::getEnv()` 的读取规则）。不创建 `crmeb/.env`。`install.lock` 仍按 §3.5 哨兵规则处理。
- 初始建库、导入种子、写入双哨兵属于引导阶段，只能在已核对的本次专用实例内完成；之后任何测试写入和应用启动都必须经过 guard。测试失败保留证据，不自动清理。
- 2026-10-11 用户批准安装后的文件校验初始化：安装种子没有当前版本的 `write_md5` 标记，真实 `login/info` 首次请求会通过 `SystemFileServices::writeMd5()` 重建 `eb_system_file_md5`，其 TRUNCATE 需要 DROP 权限。bootstrap 在双哨兵和受限账号 guard 已通过后，只用本次专用 `audit_migrator` 初始化真实 ThinkPHP，调用该现有方法实际生成当前源码的文件校验数据；成功后以现有 `CacheServices::setDbCache()` 写入当前版本标记，并检查数据非空。此步骤只在引导容器内执行，migrator 配置不传给应用或 runner；应用仍使用仅有 DML 权限的 `audit_app`。不新增表或权限种子，不伪造标记跳过校验生成，Z07 的业务码 200 断言保持不变。

- 2026-10-11 MS0b HTTP接线前置（依用户委托自主推进，05 Task4记录）：每个新RUN在私有app-secrets卷生成独立auth.session_epoch/stamp_key（与签名key不同），通过PHP_AUTH_*供给专用应用，不在宿主控制文件、命令行或输出传真实值。runner另只读挂载本次app-runtime卷，核对真实HTTP文件缓存/业务日志；访问日志正常增长单独做敏感值检查。冷错误场景仅TCP监听就绪，不调用login/info预热。supervisor配置override仅白名单中的缺AUTH值/short、debug true/false、同一audit-mysql的闭合端口1或3306、本次RUN安全cache prefix；guard连接仍固定真实3306/6379并核双哨兵/DML权限，不改变账号/库/网络主机。原MS0a配置override保留；安装writeMd5初始化仍只bootstrap专用migrator真实执行。
- 2026-10-11 MS0b Task5并发前置：仅本RUN Linux PHP服务允许SERVER_WORKERS为空（不设置PHP_CLI_SERVER_WORKERS）或2；不给宿主开放端口、不共享PID namespace/挂Docker socket或外网。supervisor在实际setsid后登记PID/PGID/SID/starttime，并验证独立组；有限observe只读本组实际成员及到audit-redis:6379的socket数字信息，屏障中不做Redis写入/EVAL观察。stop须验证整个登记组已退出/回收且8000无监听，wrapper先退出不能漏掉fork worker。测试先记录旧实现基线，实际运行PID/HTTP处理重叠/停止后状态分别验证；不按env值或ACK推断。CLIENT PAUSE WRITE固定短时仅对专用Redis，真实CLIENT LIST flags/command与app进程socket关联证明处理重叠；不加生产sleep/ignore_user_abort/test hook，不打印私有环境或请求内容。
- 同轮真实Redis暂停诊断定位框架启动先行连接：ModelService经Db::__make在请求/应用handler之前创建timeout=0的旧缓存客户端。修复使用根服务绑定的兼容Db及标准Cache manager延迟委托，保留default/named backend与共享client选项；测试必须从App::initialize之前暂停，不能先完成initialize再以工厂约1秒503替代HTTP启动覆盖。新bootstrap回归与真实HTTP仍在原专用网络/卷、原DML权限中执行，不修改vendor、镜像或网络边界。

- 2026-10-12 MS0b Task8普通下载检查：依据05计划同一调用点设计，app/runner共用本RUN新建的`backup-output`命名卷，挂载真实生产者固定的`/workspace/crmeb/backup`写入路径；不复制宿主或业务备份。源码/vendor继续只读，内部网络/无宿主端口/DML账号和全部启动保护保持。卷沿实际标签进入manifest，并由既有teardown精确清理；文件生产、内容和最终清理分别记录。此项只用于正常CRUD/备份下载，停止的复现、负向和故障验证不恢复。

§3 中的 127.0.0.1、宿主 PHP 与凭据文件位置适用于 ISO-MIN；本节是 ISO-FULL 的等效约束，不取消其他启动保护。新增 `run.sh` 属于本次批准的启动脚本范围。

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
| `admin_limited` | `eb_system_admin` | 102 | `level = 1`，角色 `R-limited` 只授予已有的"商品规则列表"读接口（`GET product/product/rule`，2026-10-11 用户批准；安装种子未登记 `GET product/product`，不补写种子） |
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
