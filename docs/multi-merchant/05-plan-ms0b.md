# MS0b：会话 v2 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。开工前提：MS0a 已验收；开放问题 Q6、Q7 已由用户决定（决定"不纳入"时跳过 Task 8 对应部分）。需要授权级别 L2。本计划**不包含**真实发布、外部通知和生产会话切换，这些需要 L4 授权。

**Goal:** 为 adminapi 与 outapi 引入版本化服务端会话：可撤销、可单次兑换刷新、依赖故障时安全失败；旧格式令牌在切换后统一拒绝。

**Architecture:** 新增 `crmeb/crmeb/services/auth/` 模块：`AuthSessionStore`（Redis 键与 Lua 脚本）、`AuthTokenV2`（签发与验证 v2 令牌）、`CredentialStamp`（服务端凭据戳）、`AccountChangeGuard`（凭据或状态变更的"保护→改库→解除"流程）。AdminAuthServices、SystemAdminServices、OutAccountServices 改为调用该模块。客服与顾客端保持 MS0a 的实现。

**Tech Stack:** PHP 8.2、ThinkPHP 6、phpredis（`EVAL`/`EVALSHA`）、firebase/php-jwt v5.0.0、MySQL 8.0。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §4.4、§11.3；用例：[10-acceptance-matrix.md](10-acceptance-matrix.md) 中阶段为 MS0b 的项，以及全部 MS0a 用例的回归。

## Global Constraints

- 起点：`SECURITY_BASE_SHA`。分支 `codex/ms0b`，工作目录 `/Volumes/Kingston/cdx/projects/tianli-mall--ms0b`。
- 不新增数据库表或列（会话状态只在 Redis）。
- 键结构固定为 `auth:v2:<session_epoch>:<app>:account:<id>` 与 `auth:v2:<session_epoch>:<app>:session:<sid>`；`<app>` 只能取 `adminapi`、`outapi`（MS1 加入 `merchantapi`）。
- `sid`、`session_version` 用 `bin2hex(random_bytes(16))` 生成（128 位）。
- 令牌新增声明：`auth_schema = 2`、`session_epoch`、`sid`、`session_version`、`iss`、`aud`；保留 `jti.id`、`jti.type`、`iat`、`nbf`、`exp`；不再包含 `pwd`。
- 新配置 `crmeb/config/auth.php`：`session_epoch`（Env `AUTH_SESSION_EPOCH`，必填）、`stamp_key`（Env `AUTH_STAMP_KEY`，必填，至少 32 字节）、`issuer`（Env `AUTH_ISSUER`，默认 `tianli-mall`）、`apps.adminapi` / `apps.outapi`（`type`、`audience`、`ttl`）。任何必填项缺失时，相关认证抛 503。
- 依赖故障（Redis、数据库连接）抛 `crmeb\exceptions\AuthUnavailableException`，业务码 503；会话不存在或无效抛 `AuthException`，业务码 401。
- 禁止 `FLUSHALL`、`FLUSHDB`、`KEYS *` 式全量删除；禁止回查旧的 `md5(token)` 键。
- 测试环境升级到 `ISO-FULL`（03 文档 §2）。

## Review Focus

- **部分失败**：Lua 脚本在原子执行中途出错时，Redis 不会回滚已执行的写入。每个脚本先检查全部前置条件，再执行写入；故障注入测试覆盖"脚本报错""连接在 EVAL 后断开""返回值丢失"。
- **丢键复活**：访问或刷新时，账号键或会话键缺失必须拒绝，不能重建。只有凭据登录可以初始化账号键。
- **时钟**：会话 TTL 不得超过令牌 `exp` 加 60 秒容差；账号键的寿命不得短于引用它的会话。
- **并发登录绕过撤销**：登录写会话时，以"账号版本未变且未 blocked"为条件；与改密并发时，改密一方胜出。
- **CLI 与常驻进程**：`AuthTokenV2`、`AuthSessionStore` 不得在实例属性中保存上一次请求的令牌或主体。

---

### Task 1: 升级测试环境到 ISO-FULL

**Files:**
- Modify: `help/audit-regressions/isolated/compose.yml`、`bootstrap.sh`、`teardown.sh`、`README.md`
- Create: `help/audit-regressions/isolated/runner/Dockerfile`、`help/audit-regressions/isolated/lib/fault.php`

**Interfaces:**
- Produces: compose 服务 `audit-app`（项目 PHP 镜像，挂载测试 worktree，只读挂载源代码、可写挂载独立的 runtime）、`audit-runner`；内部网络 `audit-net`（`internal: true`）；`audit_fault_redis(string $mode): void`，`$mode` 取 `pause`、`unpause`、`stop`、`start`；`FaultyRedis` 包装类（构造参数为真实 `\Redis` 与故障计划，可在指定命令前或后抛错、断开连接）

- [ ] **Step 1: 自检用例**：Z02、Z06、Z07 在 ISO-FULL 下重跑；新增子场景 `no-egress`（runner 内 `curl -m 5 https://example.com` 必须失败）、`no-host-ports`（`docker port` 对 `audit-mysql`、`audit-redis` 无输出）。
- [ ] **Step 2: 实现并运行自检**，期望全部 PASS。
- [ ] **Step 3: 提交** `test: upgrade isolated harness to ISO-FULL with fault injection`。

### Task 2: AuthSessionStore

**Files:**
- Create: `crmeb/crmeb/services/auth/AuthSessionStore.php`、`crmeb/crmeb/services/auth/lua/create_session.lua`、`redeem_session.lua`、`rotate_account.lua`
- Create: `crmeb/crmeb/exceptions/AuthUnavailableException.php`
- Create: `help/audit-regressions/isolated/ms0b-session-store.php`

**Interfaces:**
- Produces（`crmeb\services\auth\AuthSessionStore`，构造参数 `\Redis $redis, string $epoch`）：

```php
public function initAccount(string $app, string $id): string                 // 仅供凭据登录；键已存在且未 blocked 时返回现有版本
public function createSession(string $app, string $id, string $expectedVersion, array $session): void
        // $session: type, audience, token_digest, credential_stamp, expires_at；sid 由调用方生成并放在 $session['sid']
        // 账号版本不等于 $expectedVersion 或 blocked 时抛 AuthException
public function verify(string $app, string $sid, string $id, string $type, string $audience,
                       string $version, string $tokenDigest, string $credentialStamp): void
public function revokeSession(string $app, string $sid): void
public function rotateAccount(string $app, string $id): string               // 返回新版本
public function block(string $app, string $id): void
public function unblock(string $app, string $id): void
public function redeem(string $app, string $oldSid, string $id, array $newSession): void   // 单次兑换
```

Redis 连接错误一律转换为 `AuthUnavailableException`。

- [ ] **Step 1: 写用例**（CLI 进程内直接调用，`INTEGRATION` 层，真实 Redis + `FaultyRedis`）：
  - A17-v2：会话或账号键缺少任一字段、版本为空或不一致 → 拒绝。
  - A18-v2：会话存在于其他 `<app>` 命名空间、或 `type`/`audience`/`token_digest` 不一致 → 拒绝；验证失败不删除其他应用的键。
  - A23-v2：会话键缺失 → `AuthException`；Redis `pause` / `stop` → `AuthUnavailableException`；`verify` 不会写入任何键。
  - A25：两个并发 `redeem` 同一个旧 `sid`，恰好一个成功；`redeem` 在写新会话前或后注入错误时，旧 `sid` 不恢复、调用方拿不到新令牌；重复 `redeem` 已消耗的 `sid` 失败。
  - A29：删除账号键后 `verify` 和 `redeem` 都失败，且不重建该键；更换 `epoch` 后旧会话全部无效。
- [ ] **Step 2: 运行，确认因类不存在而失败。**
- [ ] **Step 3: 实现。** 每个 Lua 脚本先校验所有前置条件，任一不满足就直接返回错误码，不做任何写入；全部满足后再写。会话键 TTL = `expires_at - now + 60`。
- [ ] **Step 4: 运行，期望全部 PASS。**
- [ ] **Step 5: 提交** `feat(auth): versioned Redis session store with single-use redeem`。

### Task 3: AuthTokenV2 与 CredentialStamp

**Files:**
- Create: `crmeb/config/auth.php`、`crmeb/crmeb/services/auth/AuthTokenV2.php`、`crmeb/crmeb/services/auth/CredentialStamp.php`
- Modify: `crmeb/crmeb/services/AuthConfigGuard.php`（新增 `authConfig(): array`，校验 §Global Constraints 中的必填项）

**Interfaces:**
- Produces:

```php
// crmeb\services\auth\CredentialStamp
public static function for(string $app, string $id, string $credentialDigest): string
        // hash_hmac('sha256', "$app|$id|$credentialDigest", stamp_key)
// crmeb\services\auth\AuthTokenV2（无实例状态）
public function issue(string $app, string $id, string $version, string $credentialStamp): array
        // 返回 ['token' => string, 'exp' => int, 'sid' => string]；会话写入成功后才返回
public function verify(string $app, string $token, callable $currentCredentialDigest): array
        // 返回 ['id' => string, 'sid' => string]；顺序：验签与时间 → auth_schema=2 → iss/aud/type 与 $app 配置一致
        //   → AuthSessionStore::verify（用当前凭据摘要重新计算 credential_stamp）
public function refresh(string $app, string $token, callable $currentCredentialDigest): array   // 单次兑换
public function revoke(string $app, string $token): void
```

- [ ] **Step 1: 写用例** A22：受控签发器生成签名有效、但 `auth_schema` 缺失或为 1、`iss`/`aud`/`type` 不匹配、缺少 `sid` 或 `session_version` 的令牌 → 全部 401。A10-v2：`AUTH_SESSION_EPOCH`、`AUTH_STAMP_KEY` 缺失或过短 → 503。
- [ ] **Step 2: 运行确认失败；Step 3: 实现；Step 4: 运行通过；Step 5: 提交** `feat(auth): v2 token issue/verify bound to app audience and server session`。

### Task 4: 管理端接入 v2

**Files:**
- Modify: `crmeb/app/services/system/admin/AdminAuthServices.php`（`parseToken()` 改用 `AuthTokenV2::verify()`）
- Modify: `crmeb/app/services/system/admin/SystemAdminServices.php`（`login()` 改用 `initAccount()` + `issue()`；退出改用 `revoke()`）
- Modify: 管理端退出接口所在控制器（以代码为准）

**Interfaces:**
- Consumes: Task 2、Task 3
- Produces: 管理员凭据摘要 = 数据库中的 `pwd` 哈希字符串

- [ ] **Step 1: 用例**：A24（旧格式管理员令牌，即使旧 `md5(token)` 键仍在，也被拒绝；Redis 中其他业务键不受影响）；A21-v2（改密后旧令牌失效，新登录可用；同一秒内"登录"与"改密"并发，改密胜出）；全部 MS0a 管理端用例的回归。
- [ ] **Step 2–5**：确认失败 → 实现 → 通过 → 提交 `feat(adminapi): switch to v2 sessions`。

### Task 5: 对外账号接入 v2

**Files:**
- Modify: `crmeb/app/services/out/OutAccountServices.php`（`authLogin()`、`parseToken()`、`refresh()`）

**Interfaces:**
- Produces: 对外账号凭据摘要 = 数据库中的 `appsecret` 哈希字符串；`refresh()` 使用 `AuthTokenV2::refresh()`（单次兑换）

- [ ] **Step 1: 用例**：A24（旧格式对外令牌被拒绝）；A25 的 HTTP 子场景（并发刷新只成功一个；客户端在收到响应前断开时，服务端不产生第二个有效会话）；A19、A28 中对外账号相关部分回归；MCP 回归（不受影响）。
- [ ] **Step 2–5**：确认失败 → 实现 → 通过 → 提交 `feat(outapi): switch to v2 sessions with single-use refresh`。

### Task 6: 凭据与状态变更的撤销流程

**Files:**
- Create: `crmeb/crmeb/services/auth/AccountChangeGuard.php`
- Modify: 所有修改管理员密码、状态、删除标记的入口（至少 `SystemAdminServices::save()`、`updateAdmin()`、删除方法），以及所有修改对外账号密钥、状态、删除标记的入口（至少 `OutAccountServices` 的更新方法、`SystemOutAccount::set_status()`、删除方法）。开工时先 `grep` 列出全部入口，写进报告。

**Interfaces:**
- Produces:

```php
// crmeb\services\auth\AccountChangeGuard
public static function run(string $app, string $id, callable $dbChange): mixed
        // 1) AuthSessionStore::block + rotateAccount；失败 → 抛 AuthUnavailableException，不执行 $dbChange
        // 2) 执行 $dbChange（在数据库事务中）；失败 → 保持 blocked，重新抛出
        // 3) unblock；失败 → 保持 blocked，记录审计，返回"需人工复核"错误，不报告成功
```

- [ ] **Step 1: 用例**：A21-v2 中"改密、停用、删除各自让全部旧会话失效"；故障注入：步骤 1 失败时数据库不变；步骤 2 失败时账号保持 blocked、不能登录；步骤 3 失败时接口不返回成功。
- [ ] **Step 2–5**：确认失败 → 实现并接入全部入口 → 通过 → 提交 `feat(auth): block-rotate-change-unblock for credential and state changes`。

### Task 7: 会话状态丢失与世代号轮换

**Files:**
- Create: `crmeb/app/command/AuthEpochRotate.php`（命令 `php think auth:epoch-rotate --confirm`）、`docs/multi-merchant/records/ms0b/runbook-session-loss.md`

**Interfaces:**
- Produces: 命令只做两件事：校验新的 `AUTH_SESSION_EPOCH` 已在配置中生效且与旧值不同；按 `SCAN` + 前缀删除**旧 epoch** 下的 `auth:v2:<old>:*` 键（分批，打印数量，不打印键名）。不接触其他键。

- [ ] **Step 1: 用例** A29：模拟 Redis 从旧快照恢复（备份 RDB → 写入新会话 → 恢复 RDB）→ 隔离认证入口 → 轮换 epoch → 旧会话全部 401、重新登录可用；业务缓存键数量前后不变。
- [ ] **Step 2–5**：确认失败 → 实现命令与 runbook → 通过 → 提交 `feat(auth): epoch rotation command and session-loss runbook`。

### Task 8（视 Q6、Q7 决定）: 登录验证码与 URL 令牌

- Q6 纳入时：失败计数改为按"账号 + IP"计；超过阈值后，服务端**要求**验证码字段存在且校验通过。用例：连续失败后不带验证码登录被拒绝。
- Q7 纳入时：`AdminAuthTokenMiddleware` 不再接受 `?token=`，确需 URL 令牌的下载接口改用短期一次性下载凭证。开工前先列出前端所有使用 `?token=` 的位置并回报。
- 两项各自单独提交。

### Task 9: 发布准备文档（不发布）

**Files:**
- Create: `docs/multi-merchant/records/ms0b/cutover-plan.md`、`docs/multi-merchant/records/ms0b/integration-inventory.md`

- [ ] 按 00 文档 §11.3 编写集成清单：代码侧入口逐项列出；"实际在用"一栏写"未知，待核实"，除非有可核实的证据。
- [ ] 切换计划：前置条件（集成清单已核实、通知已发出——由用户执行）、部署顺序、`AUTH_SESSION_EPOCH` 与 `AUTH_STAMP_KEY` 的生成和下发、旧会话失效对管理员和第三方的影响、回退方案（回退版本必须保留 MS0a 边界）。
- [ ] 文中不得出现"已通知""已部署"等未发生的事实。

### Task 10: 全量回归与交付

- [ ] 在新建的 ISO-FULL 环境运行：`security.php`、`selftest.php`、`ms0a-auth.php`、`ms0b-session-store.php` 以及 MS0b 的 HTTP 用例；全部 `exit=0`。
- [ ] `git diff --name-only <SECURITY_BASE_SHA> HEAD` 只包含本计划列出的路径（Task 6 中 grep 出的入口逐项说明）。
- [ ] 写 `records/ms0b/report.md`、`results.jsonl`；提交；停止，等待验收（11 文档 §5.3）。
