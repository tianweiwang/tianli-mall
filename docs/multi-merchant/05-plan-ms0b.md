# MS0b：会话 v2 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。正常签收流程的起点为 MS0a 已验收。2026-10-11 用户后续明确授权按文档规划自主继续、问题由执行方选择最优方案：本阶段从已完整自动化及独立复核的 MS0a 实际交付提交开始 L2 隔离开发，V10 人工签收仍待，不自行生成 SECURITY_BASE_SHA。Q6/Q7 依该委托纳入，兼容路径及具体测试先记录后实现。需要授权级别 L2。本计划**不包含**真实发布、外部通知和生产会话切换，这些需要 L4 授权。

**Goal:** 为 adminapi 与 outapi 引入版本化服务端会话：可撤销、可单次兑换刷新、依赖故障时安全失败；旧格式令牌在切换后统一拒绝。

**Architecture:** 新增 `crmeb/crmeb/services/auth/` 模块：`AuthSessionStore`（Redis 键与 Lua 脚本）、`AuthTokenV2`（签发与验证 v2 令牌）、`CredentialStamp`（服务端凭据戳）、`AccountChangeGuard`（凭据或状态变更的"保护→改库→解除"流程）。AdminAuthServices、SystemAdminServices、OutAccountServices 改为调用该模块。客服与顾客端保持 MS0a 的实现。

**Tech Stack:** PHP 8.2、ThinkPHP 6、phpredis（`EVAL`/`EVALSHA`）、firebase/php-jwt v5.0.0、MySQL 8.0。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §4.4、§11.3；用例：[10-acceptance-matrix.md](10-acceptance-matrix.md) 中阶段为 MS0b 的项，以及全部 MS0a 用例的回归。

## Global Constraints

- 实际开发起点：`3e7c966ecfa084ff4f219b03c61ed0efbac4caac`（MS0a交付）；其运行源码/测试与已验证冻结点 `af3e3e0c48fab9cf5af18f45f6341e9c272b168f` 差异为空。MS0a 主/独立完整 HTTP106、UNIT43、selftest6均PASS，独立V4/V6/V7无必须修复项。分支 `codex/ms0b`，工作目录 `H:/天俪商城/tianli-mall--ms0b`；原 Mac 路径命令按此映射。正式 SECURITY_BASE_SHA 待验收人指定，实际开发起点不冒充签收。
- 不新增数据库表或列（会话状态只在 Redis）。
- 键结构固定为 `auth:v2:<session_epoch>:<app>:account:<id>` 与 `auth:v2:<session_epoch>:<app>:session:<sid>`；`<app>` 只能取 `adminapi`、`outapi`（MS1 加入 `merchantapi`）。
- `sid`、`session_version` 用 `bin2hex(random_bytes(16))` 生成（128 位）。
- 令牌新增声明：`auth_schema = 2`、`session_epoch`、`sid`、`session_version`、`iss`、`aud`；保留 `jti.id`、`jti.type`、`iat`、`nbf`、`exp`；不再包含 `pwd`。
- 新配置 `crmeb/config/auth.php`：`session_epoch`（Env `AUTH_SESSION_EPOCH`，必填）、`stamp_key`（Env `AUTH_STAMP_KEY`，必填，至少 32 字节）、`issuer`（Env `AUTH_ISSUER`，默认 `tianli-mall`）、`apps.adminapi` / `apps.outapi`（`type`、`audience`、`ttl`）。任何必填项缺失时，相关认证抛 503。
- 依赖故障（Redis、数据库连接）抛 `crmeb\exceptions\AuthUnavailableException`，业务码 503；会话不存在或无效抛 `AuthException`，业务码 401。
- 禁止 `FLUSHALL`、`FLUSHDB`、`KEYS *` 式全量删除；禁止回查旧的 `md5(token)` 键。
- MS0a 已经用户批准并实际实现 ISO-FULL（03 §2.1）：复用 PHP8.2、本地已存在镜像、只读源代码、专用内部网络/新卷/DML账号，不重复构建或拉镜像。每阶段仍新建 RUN，自检增加网络隔离与故障注入。

## Review Focus

- **部分失败**：Lua 脚本在原子执行中途出错时，Redis 不会回滚已执行的写入。每个脚本先检查全部前置条件，再执行写入；故障注入测试覆盖"脚本报错""连接在 EVAL 后断开""返回值丢失"。
- **丢键复活**：访问或刷新时，账号键或会话键缺失必须拒绝，不能重建。只有凭据登录可以初始化账号键。
- **时钟**：会话 TTL 不得超过令牌 `exp` 加 60 秒容差；账号键的寿命不得短于引用它的会话。
- **并发登录绕过撤销**：登录写会话时，以"账号版本未变且未 blocked"为条件；与改密并发时，改密一方胜出。
- **CLI 与常驻进程**：`AuthTokenV2`、`AuthSessionStore` 不得在实例属性中保存上一次请求的令牌或主体。

---

### Task 1: 复用 ISO-FULL 并增加故障验证

**Files:**
- Modify: `help/audit-regressions/isolated/compose.yml`、`README.md`、`selftest.php`、`lib/harness.php`（可选milestone元数据）、`lib/guard.php`（有界Redis读超时）、`run.sh`（pause/unpause控制、身份核对与退出恢复）
- Create: `help/audit-regressions/isolated/lib/fault.php`；不新建重复 runner/Dockerfile，本机经批准的镜像已有所需扩展与工具。bootstrap/teardown沿用已验证环境边界，仅确有恢复必要时以同一调用点调整并列证据。

**Interfaces:**
- Produces: 复用 compose 服务 audit-app、audit-runner 和实际名为 isolation 的内部网络（internal=true），源代码只读、runtime专用可写；audit_fault_redis(string $mode): void 的模式为 pause/unpause/stop/start。FaultyRedis继承PHP原生Redis，构造参数为真实Redis与故障计划；已反射确认扩展的方法签名，委托真实连接，可在指定命令前后抛错/断连，不用于生产。

- [x] **Step 1: 自检用例**（264fbcff测试先提交；新RUN=20261011092042df3a82，12项基线8PASS/4FAIL，exit1；4FAIL均因故障适配器未实现，无环境退出）：Z02、Z06、Z07 在 ISO-FULL 下重跑；新增子场景 `no-egress`（runner 内 `curl -m 5 https://example.com` 必须失败）、`no-host-ports`（核对实际Docker端口绑定为空，等价于docker port无输出），及真实Redis命令前失败/执行后响应丢失/执行后断连/专用服务pause-unpause-stop-start恢复；全部全键快照与双哨兵不变。先提交测试并保留适配器未实现时的正常FAIL，不制造已经具备的ISO属性失败。
- [x] **Step 2: 实现并运行自检**（12/12 PASS，exit0；真实pause/stop均有界失败、恢复双哨兵及全Redis不变。首次after10PASS/2FAIL及exit2保留：修复pause后错误健康检查；原生EVAL状态严格true/OK语义有真实只读诊断，不改变业务/副作用断言）
- [x] **Step 3: 提交**（本地Task1故障适配器/控制/文档；测试提交264fbcff先于实现；未推送）`test: add guarded Redis fault injection to existing ISO-FULL harness`。

### Task 2: AuthSessionStore

**Files:**
- Create: `crmeb/crmeb/services/auth/AuthSessionStore.php`、`crmeb/crmeb/services/auth/lua/create_session.lua`、`redeem_session.lua`、`rotate_account.lua`、`verify_session.lua`（只读原子核对账号与会话，避免两次读跨过撤销）
- Create: `crmeb/crmeb/exceptions/AuthUnavailableException.php`、`AuthSessionException.php`（两者保持AuthException子类兼容，构造直接用RuntimeException受控消息/代码，不触发getLang缓存/详细故障日志，不携带底层previous）
- Create: `help/audit-regressions/isolated/ms0b-session-store.php`

**Interfaces:**
- Produces（`crmeb\services\auth\AuthSessionStore`，构造参数 `\Redis $redis, string $epoch`）：

```php
public function initAccount(string $app, string $id): string                 // 仅供凭据登录；键已存在且未 blocked 时返回现有版本
public function epoch(): string                                            // 只返回构造配置的世代，不保存上次请求主体
public function createSession(string $app, string $id, string $expectedVersion, array $session): void
        // $session: sid, type, audience, session_version, token_digest, credential_stamp, expires_at
        // sid由调用方生成；session_version必须同时匹配expectedVersion和账号版本；blocked时抛AuthException
public function verify(string $app, string $sid, string $id, string $type, string $audience,
                       string $version, string $tokenDigest, string $credentialStamp): void
public function revokeSession(string $app, string $sid): void
public function rotateAccount(string $app, string $id): string               // 返回新版本
public function block(string $app, string $id): void
public function unblock(string $app, string $id): void
public function redeem(string $app, string $oldSid, string $id, array $newSession): void   // 单次兑换
public function beginChange(string $app, string $id): string               // 原子blocked并轮换已有版本；返回本次owner nonce
public function completeChange(string $app, string $id, string $owner): void // 仅CAS匹配的本次owner可解除保护
```

Redis 连接错误一律转换为 `AuthUnavailableException`。账号/会话用HASH，blocked为字符串0/1，initialized=1；未初始化保护标记initialized=0且无session_version。会话status为active，兑换后旧status为consumed、绝对到期点保留。账号键持久（TTL=-1），无早于会话过期风险。redeem的newSession还要求session_version匹配账号/旧会话，type/audience/credential_stamp匹配旧会话，sid不同，previous_token_digest匹配旧摘要且新的token_digest不同于旧摘要；previous_token_digest只校验、不存入新会话。所有摘要/戳为64位小写十六进制，expires_at为可安全计算Redis TTL的正整数；缺少、非整数、已超过exp+60、数值溢出均在写入前拒绝。

2026-10-11 必要设计补齐（用户委托执行方自主选择，安全规则保持）：

- 账号键缺失/从未登录时，账号变更只创建 blocked 的未初始化保护标记（initialized=0，无可授权版本）；成功后解除本次保护仍无版本，之后有效凭据登录才产生新的128位版本。verify/redeem始终拒绝并不重建。普通rotateAccount缺键拒绝；AccountChangeGuard使用beginChange/completeChange，不从失踪版本恢复旧会话。
- beginChange以Lua一次检查并设置blocked及轮换现有版本；另存本次随机owner。并发变更遇到既有blocked拒绝；completeChange仅匹配owner解除，普通unblock不得清除带owner的保护。身份/owner只在调用栈传递，实例不保存上次主体。
- Lua任何可能导致类型/结构/TTL错误的前置条件在第一项写入前检查；真实EVAL执行后断连/返回丢失不得恢复旧sid或返回新令牌。所有连接有界超时，错误仅包含受控分类，不记录底层异常原文和敏感值。
- Store负向及只读操作验证全部Redis值与绝对到期点：用只读Lua同次读取Redis TIME与每键PTTL，-1持久/-2缺失严格区分，正数绝对到期点只容许1ms取整误差；自然倒计时不是写入，EXPIRE/PERSIST续期或移除过期不可漏检。兑换保留旧sid绝对到期点，新sid仍不晚于exp+60。
- beginChange未执行前故障必须零写入；执行后丢应答必须仍blocked、保留本次owner和轮换版本、抛503且调用方不得开始改库。completeChange未执行故障仍blocked；已执行但丢应答可能已解除owner，仍抛503、不报告成功、不补偿恢复旧版本。后续Task6仅在数据库已提交后调用completeChange，回读状态只能用于脱敏审计，不能作为失败后返回成功的依据。
- 凭据登录先只读校验并保存原digest，再init取得账号版本，再重读当前digest/status作严格比较，最后createSession CAS。init之前的改密由digest重比对拒绝，之后的变更由blocked/version CAS拒绝。不得拿旧校验配新digest签发。verifyLogin统计从读验证分离，Redis依赖及会话写入成功前不写统计；相应必要调用点属于Task4/5。

- [ ] **Step 1: 写用例**（CLI 进程内直接调用，`INTEGRATION` 层，真实 Redis + `FaultyRedis`）：
  - A17-v2：会话或账号键缺少任一字段、版本为空或不一致 → 拒绝。
  - A18-v2：会话存在于其他 `<app>` 命名空间、或 `type`/`audience`/`token_digest` 不一致 → 拒绝；验证失败不删除其他应用的键。
  - A23-v2：会话键缺失 → `AuthException`；Redis `pause` / `stop` → `AuthUnavailableException`；`verify` 不会写入任何键。
  - A25：两个并发 `redeem` 同一个旧 `sid`，恰好一个成功；`redeem` 在写新会话前或后注入错误时，旧 `sid` 不恢复、调用方拿不到新令牌；重复 `redeem` 已消耗的 `sid` 失败。
  - A29：删除账号键后 `verify` 和 `redeem` 都失败，且不重建该键；更换 `epoch` 后旧会话全部无效。
  - 2026-10-11只读合同复核补齐：create输入版本缺少/不一致、所有账号/旧会话HASH字段损坏对create/redeem的拒绝、错类型目标、redeem摘要/到期/溢出、same-token-digest、旧到期点保留、账号持久、丢账号后变更标记不复活旧会话，以及begin/complete的EVAL前后故障；当前Task2为100项，新增用例需重新保留缺类基线，不能沿用此前82项结果冒充覆盖。
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
// crmeb\services\auth\AuthTokenV2（无上次请求身份状态，仅持有Store依赖）
public function __construct(AuthSessionStore $store)
        // 每次调用重新读取AuthConfigGuard::authConfig；配置epoch必须与store.epoch()一致，否则503
public function issue(string $app, string $id, string $version, string $credentialStamp): array
        // 返回 ['token' => string, 'exp' => int, 'sid' => string]；会话写入成功后才返回
public function verify(string $app, string $token, callable $currentCredentialDigest): array
        // 返回 ['id' => string, 'sid' => string]；顺序：验签与时间 → auth_schema=2 → iss/aud/type 与 $app 配置一致
        //   → AuthSessionStore::verify（用当前凭据摘要重新计算 credential_stamp）
        // callback(string $id): string，返回当前数据库凭据摘要；相关业务调用点负责当前状态检查
public function refresh(string $app, string $token, callable $currentCredentialDigest): array   // 单次兑换
public function revoke(string $app, string $token): void
```

- [ ] **Step 1: 写用例** A22：受控签发器生成签名有效、但 `auth_schema` 缺失或为 1、`iss`/`aud`/`type` 不匹配、缺少 `sid` 或 `session_version` 的令牌 → 全部 401。A10-v2：`AUTH_SESSION_EPOCH`、`AUTH_STAMP_KEY` 缺失或过短 → 503。
- [ ] **Step 2: 运行确认失败；Step 3: 实现；Step 4: 运行通过；Step 5: 提交** `feat(auth): v2 token issue/verify bound to app audience and server session`。

### Task 4: 管理端接入 v2

**Files:**
- Modify: `crmeb/app/services/system/admin/AdminAuthServices.php`（`parseToken()` 改用 `AuthTokenV2::verify()`）
- Modify: `crmeb/app/services/system/admin/SystemAdminServices.php`（`login()` 改用 `initAccount()` + `issue()`；退出改用 `revoke()`）
- Modify: `crmeb/app/adminapi/controller/v1/setting/SystemAdmin.php`（真实退出接口）；`crmeb/app/adminapi/AdminApiExceptionHandle.php`（受控v2异常直接JSON，避开Json::make再次翻译缓存写入和debug trace）

**Interfaces:**
- Consumes: Task 2、Task 3
- Produces: 管理员凭据摘要 = 数据库中的 `pwd` 哈希字符串

- [ ] **Step 1: 用例**：A24（旧格式管理员令牌，即使旧 `md5(token)` 键仍在，也被拒绝；Redis 中其他业务键不受影响）；A21-v2（改密后旧令牌失效，新登录可用；同一秒内"登录"与"改密"并发，改密胜出）；全部 MS0a 管理端用例的回归。
- [ ] **Step 2–5**：确认失败 → 实现 → 通过 → 提交 `feat(adminapi): switch to v2 sessions`。

### Task 5: 对外账号接入 v2

**Files:**
- Modify: `crmeb/app/services/out/OutAccountServices.php`（`authLogin()`、`parseToken()`、`refresh()`）；`crmeb/app/outapi/OutApiExceptionHandle.php`（同一受控异常必要返回调用点，直接JSON、不得记录请求参数/底层异常）

**Interfaces:**
- Produces: 对外账号凭据摘要 = 数据库中的 `appsecret` 哈希字符串；`refresh()` 使用 `AuthTokenV2::refresh()`（单次兑换）

- [ ] **Step 1: 用例**：A24（旧格式对外令牌被拒绝）；A25 的 HTTP 子场景（并发刷新只成功一个；客户端在收到响应前断开时，服务端不产生第二个有效会话）；A19、A28 中对外账号相关部分回归；MCP 回归（不受影响）。
- [ ] **Step 2–5**：确认失败 → 实现 → 通过 → 提交 `feat(outapi): switch to v2 sessions with single-use refresh`。

### Task 6: 凭据与状态变更的撤销流程

**Files:**
- Create: `crmeb/crmeb/services/auth/AccountChangeGuard.php`
- Modify: 所有修改管理员密码、状态、删除标记的入口（至少 `SystemAdminServices::save()`、`updateAdmin()`、删除方法），以及所有修改对外账号密钥、状态、删除标记的入口（至少 `OutAccountServices` 的更新方法、`SystemOutAccount::set_status()`、删除方法）。已定位必要调用点还含 `DivisionServices::divisionSave()` 直接模型写密码、`setDivisionStatus()` 批量更新、`delDivision()` 批量删除；批量变更须先保护全部确切目标，再单一事务改库，再逐owner解除，不能只包SystemAdmin保存方法。开工时先 `grep` 列出全部入口，写进报告。

**Interfaces:**
- Produces:

```php
// crmeb\services\auth\AccountChangeGuard
public static function run(string $app, string $id, callable $dbChange): mixed
public static function runMany(string $app, array $ids, callable $dbChange): mixed // 全部先保护，同一事务后按owner解除
        // 1) AuthSessionStore::beginChange（原子block+rotate）；失败 → 不执行 $dbChange
        // 2) 执行 $dbChange（在数据库事务中）；失败 → 保持 blocked，重新抛出
        // 3) 数据库提交后completeChange(owner CAS)；未执行故障仍保护，已执行丢应答可能已解除
        //    两者均记录脱敏审计、返回“需人工复核”503，不报告成功，不补偿恢复旧版本
```

- [ ] **Step 1: 用例**：A21-v2 中"改密、停用、删除各自让全部旧会话失效"；故障注入：步骤 1 失败时数据库不变（执行后丢应答已有blocked/新版本，仍不改库）；步骤 2 失败时账号保持 blocked、不能登录；步骤 3 失败时接口不返回成功，未执行仍blocked，已执行丢应答可能已解除但旧版本始终失效、数据库此前已提交。
- [ ] **Step 2–5**：确认失败 → 实现并接入全部入口 → 通过 → 提交 `feat(auth): block-rotate-change-unblock for credential and state changes`。

### Task 7: 会话状态丢失与世代号轮换

**Files:**
- Create: `crmeb/app/command/AuthEpochRotate.php`（命令 `php think auth:epoch-rotate --confirm`）、`docs/multi-merchant/records/ms0b/runbook-session-loss.md`
- Modify（显式命令注册的必要调用点）: `crmeb/config/console.php`

**Interfaces:**
- Produces: 命令要求显式 `--old-epoch=<旧值>` 和 `--confirm`；新旧epoch限安全字符，不允许冒号/通配符。校验新的 `AUTH_SESSION_EPOCH` 已在配置中生效且与旧值不同；按 `SCAN` + 前缀删除**旧 epoch** 下的 `auth:v2:<old>:*` 键（分批，打印数量，不打印键名）。不接触其他键。

- [ ] **Step 1: 用例** A29：模拟 Redis 从旧快照恢复（备份 RDB → 写入新会话 → 恢复 RDB）→ 隔离认证入口 → 轮换 epoch → 旧会话全部 401、重新登录可用；业务缓存键数量前后不变。
- [ ] **Step 2–5**：确认失败 → 实现命令与 runbook → 通过 → 提交 `feat(auth): epoch rotation command and session-loss runbook`。

### Task 8（按用户委托纳入 Q6/Q7）: 登录验证码与 URL 令牌

- Q6 本阶段纳入：失败计数改为按与DAO一致的账号规范化值 + 服务端可信IP计；保留60秒窗口，两次凭据失败后下一次尝试必须验证码。成功仅清当前组合，失败包装数字 login_captcha=1 沿用现有前端；超过阈值后，服务端**要求**验证码字段存在且校验通过。用例：连续失败后不带验证码登录被拒绝。
- Q7 本阶段纳入：`AdminAuthTokenMiddleware` 不再接受 `?token=`，现有两处裸window.open下载改用本站header+Blob消费。当前无必须URL直下的大文件需求，不引入新匿名凭证协议；以后确需直下时再实现短期、一次性、限定文件/主体/会话的下载凭证。开工前先列出前端所有使用 `?token=` 的位置并回报。
- 必要Files：`crmeb/app/adminapi/controller/Login.php`、`crmeb/app/adminapi/middleware/AdminAuthTokenMiddleware.php`、`crmeb/app/adminapi/controller/PublicController.php`；新增小下载helper `template/admin/src/libs/download.js`，修改 `template/admin/src/pages/system/codeGeneration/list.vue` 与 `template/admin/src/pages/system/maintain/systemDatabackup/index.vue` 两个真实消费点。仅对可信本站后端发header，拒绝外部URL/跳转，处理JSON错误与文件名；下载缓存成功消费采用原子比较删除，重复下载失败，保留现有admin+role边界。旧已注释npm/EventSource按钮后端路由不存在，移除该死代码的URL JWT，不凭空开发新后端。扫码上传/fileToken/客服/uni-app不是Q7 JWT，不批量改动。
- 代码调用证据见仓库外 ms0b-q7-caller-inventory.md；Task8须加入header正向/URL JWT负向、两下载消费/重复消费/错误响应与浏览器验证。两项各自单独提交。

### Task 9: 发布准备文档（不发布）

**Files:**
- Create: `docs/multi-merchant/records/ms0b/cutover-plan.md`、`docs/multi-merchant/records/ms0b/integration-inventory.md`

- [ ] 按 00 文档 §11.3 编写集成清单：代码侧入口逐项列出；"实际在用"一栏写"未知，待核实"，除非有可核实的证据。
- [ ] 切换计划：前置条件（集成清单已核实、通知已发出——由用户执行）、部署顺序、`AUTH_SESSION_EPOCH` 与 `AUTH_STAMP_KEY` 的生成和下发、旧会话失效对管理员和第三方的影响、回退方案（回退版本必须保留 MS0a 边界）。
- [ ] 文中不得出现"已通知""已部署"等未发生的事实。

### Task 10: 全量回归与交付

- [ ] 在新建的 ISO-FULL 环境运行：`security.php`、`selftest.php`、`ms0a-auth.php`、`ms0b-session-store.php` 以及 MS0b 的 HTTP 用例；全部 `exit=0`。v2回归保留旧业务拒绝/零副作用/原应用可用断言；旧pwd/md5白名单构造迁为当前v2会话输入，旧schema拒绝另由A24在旧键仍存在时真实覆盖，不拿旧schema拒绝伪装v2匹配测试。测试脚本适配输入形态前逐条记录对照，不放宽业务期望。
- [ ] `git diff --name-only 3e7c966ecfa084ff4f219b03c61ed0efbac4caac HEAD` 只包含本计划列出的路径（Task 6 中 grep 出的入口逐项说明）。
- [ ] 写 `records/ms0b/report.md`、`results.jsonl`；提交；停止，等待验收（11 文档 §5.3）。
