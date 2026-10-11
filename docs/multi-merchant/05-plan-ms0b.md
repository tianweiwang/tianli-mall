# MS0b：会话 v2 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。正常签收流程的起点为 MS0a 已验收。2026-10-11 用户后续明确授权按文档规划自主继续、问题由执行方选择最优方案：本阶段从已完整自动化及独立复核的 MS0a 实际交付提交开始 L2 隔离开发，V10 人工签收仍待，不自行生成 SECURITY_BASE_SHA。Q6/Q7 依该委托纳入，兼容路径及具体测试先记录后实现。需要授权级别 L2。本计划**不包含**真实发布、外部通知和生产会话切换，这些需要 L4 授权。

**Goal:** 为 adminapi 与 outapi 引入版本化服务端会话：可撤销、可单次兑换刷新、依赖故障时安全失败；旧格式令牌在切换后统一拒绝。

**Architecture:** 新增 `crmeb/crmeb/services/auth/` 模块：`AuthSessionStore`（Redis 键与 Lua 脚本）、`AuthTokenV2`（签发与验证 v2 令牌）、`CredentialStamp`（服务端凭据戳）、`AccountChangeGuard`（凭据或状态变更的"保护→改库→解除"流程）。AdminAuthServices、SystemAdminServices、OutAccountServices 改为调用该模块。客服与顾客端保持 MS0a 的实现。

**Tech Stack:** PHP 8.2、ThinkPHP 6、phpredis（`EVAL`/`EVALSHA`）、firebase/php-jwt v5.0.0、MySQL 8.0。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §4.4、§11.3；用例：[10-acceptance-matrix.md](10-acceptance-matrix.md) 中阶段为 MS0b 的项，以及全部 MS0a 用例的回归。

## 2026-10-11 用户要求跳过的验证

用户明确要求“跳过行不行，再把我号封了”：从本次指令起，停止安全复现、故障注入，以及刚准备的 HTTP 并发刷新和响应前断连试验；不自动恢复、改名或换入口执行同类验证。继续普通功能开发、源码审查、语法/构建检查及正常业务流程检查，认证和权限功能的实现要求保留。

- Task5 两项 A25（`out-http-two-worker-single-refresh`、`out-http-response-before-read-disconnect`）未执行，记为“按用户要求跳过”，不记 PASS。
- Task4/6 的并发与部分失败验证、Task7 的 RDB 恢复试验、Task10 综合安全回归中的同类场景也按本指令跳过；原计划描述保留为覆盖缺口，不再作为当前执行指令。既有原始结果和历史失败不改写；跳过不构成完整安全验收或人工签收。
- `run.sh` 在读取 RUN、启动 Docker 或执行脚本之前拒绝现有综合安全/故障套件，退出码 2 表示未执行，不提供恢复开关。后续正常业务检查需单独选择不含上述场景的检查脚本。
- RUN `202610111416385a3cf0` 只完成安装引导，尚未运行新测试；专用 3 个容器、1 个隔离网络、8 个卷已通过 manifest 身份核对清理，`teardown.sh` exit 0，复查无残留。仓库外证据目录保留。

## Global Constraints

- 实际开发起点：`3e7c966ecfa084ff4f219b03c61ed0efbac4caac`（MS0a交付）；其运行源码/测试与已验证冻结点 `af3e3e0c48fab9cf5af18f45f6341e9c272b168f` 差异为空。MS0a 主/独立完整 HTTP106、UNIT43、selftest6均PASS，独立V4/V6/V7无必须修复项。分支 `codex/ms0b`，工作目录 `H:/天俪商城/tianli-mall--ms0b`；原 Mac 路径命令按此映射。正式 SECURITY_BASE_SHA 待验收人指定，实际开发起点不冒充签收。
- 不新增数据库表或列（会话状态只在 Redis）。
- 键结构固定为 `auth:v2:<session_epoch>:<app>:account:<id>` 与 `auth:v2:<session_epoch>:<app>:session:<sid>`；`<app>` 只能取 `adminapi`、`outapi`（MS1 加入 `merchantapi`）。
- `sid`、`session_version` 用 `bin2hex(random_bytes(16))` 生成（128 位）。
- 令牌新增声明：`auth_schema = 2`、`session_epoch`、`sid`、`session_version`、`iss`、`aud`；保留 `jti.id`、`jti.type`、`iat`、`nbf`、`exp`；不再包含 `pwd`。
- 新配置 `crmeb/config/auth.php`：`session_epoch`（Env `AUTH_SESSION_EPOCH`，必填）、`stamp_key`（Env `AUTH_STAMP_KEY`，必填，至少 32 字节）、`issuer`（Env `AUTH_ISSUER`，默认 `tianli-mall`）、`apps.adminapi` / `apps.outapi`（`type`、`audience`、`ttl`）。任何必填项缺失时，相关认证抛 503。
- 依赖故障（Redis、数据库连接）抛 `crmeb\exceptions\AuthUnavailableException`，业务码 503；会话不存在或无效抛 `AuthException`，业务码 401。
- 禁止 `FLUSHALL`、`FLUSHDB`、`KEYS *` 式全量删除；禁止回查旧的 `md5(token)` 键。
- MS0a 已经用户批准并实际实现 ISO-FULL（03 §2.1）：复用 PHP8.2、本地已存在镜像、只读源代码、专用内部网络/新卷/DML账号，不重复构建或拉镜像。此前阶段采用新建 RUN 并执行隔离/故障自检；当前不再自动执行含故障注入或安全复现的自检，遵循顶部用户跳过指令。

## Review Focus

- **部分失败**：Lua 脚本在原子执行中途出错时，Redis 不会回滚已执行的写入。每个脚本先检查全部前置条件，再执行写入；原计划故障注入覆盖"脚本报错""连接在 EVAL 后断开""返回值丢失"；当前保留为验证缺口，按用户要求停止后续执行。
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

- [x] **Step 1: 写用例**（14c4f9c4原82项；独立复核补18项后a651aa97为100项，均先于生产实现提交；CLI进程内真实Redis + `FaultyRedis`）：
  - A17-v2：会话或账号键缺少任一字段、版本为空或不一致 → 拒绝。
  - A18-v2：会话存在于其他 `<app>` 命名空间、或 `type`/`audience`/`token_digest` 不一致 → 拒绝；验证失败不删除其他应用的键。
  - A23-v2：会话键缺失 → `AuthException`；Redis `pause` / `stop` → `AuthUnavailableException`；`verify` 不会写入任何键。
  - A25：两个并发 `redeem` 同一个旧 `sid`，恰好一个成功；`redeem` 在写新会话前或后注入错误时，旧 `sid` 不恢复、调用方拿不到新令牌；重复 `redeem` 已消耗的 `sid` 失败。
  - A29：删除账号键后 `verify` 和 `redeem` 都失败，且不重建该键；更换 `epoch` 后旧会话全部无效。
  - 2026-10-11只读合同复核补齐：create输入版本缺少/不一致、所有账号/旧会话HASH字段损坏对create/redeem的拒绝、错类型目标、redeem摘要/到期/溢出、same-token-digest、旧到期点保留、账号持久、丢账号后变更标记不复活旧会话，以及begin/complete的EVAL前后故障；当前Task2为100项，新增用例需重新保留缺类基线，不能沿用此前82项结果冒充覆盖。
- [x] **Step 2: 运行，确认因类不存在而失败。** 14c4f9c4的82项、a651aa97的100项均全部正常FAIL/exit1，原因均为缺类，数据库/缓存值和补齐后的到期点无意外变化；原始两份基线保留。
- [x] **Step 3: 实现。** 四个Lua先校验前置条件/类型/字段/安全整数/期限，再写；读验证一次原子核对账号与会话，零写入。账号持久，会话EXPIREAT固定为expires_at+60，兑换旧键只改consumed并保留到期点；版本和owner使用128位随机数，丢键不复活，依赖失败受控503且无重试/补偿。
- [x] **Step 4: 运行，全部 PASS。** 100/100、exit0（ms0b-session-store-after-20261011100431.jsonl），含真实pause/stop有界503、两独立进程兑换恰好一成功、执行后丢应答/断连无复活、TIME/PTTL及全部158张表/缓存值检查。3个PHP lint通过，7个正式文件与独立审查草稿哈希一致。该轮为a651aa97加7个待提交生产文件，原始source元数据不改写；最终交付须Task10清洁冻结新RUN完整回归。
- [x] **Step 5: 本地提交** `feat(auth): versioned Redis session store with single-use redeem`；未推送。当前仅存储原语完成，管理端/对外端尚未接入v2，整个MS0b未完成。

### Task 3: AuthTokenV2 与 CredentialStamp

**Files:**
- Create: `crmeb/config/auth.php`、`crmeb/crmeb/services/auth/AuthTokenV2.php`、`crmeb/crmeb/services/auth/CredentialStamp.php`
- Modify: `crmeb/crmeb/services/AuthConfigGuard.php`（新增 `authConfig(): array`，校验 §Global Constraints 中的必填项）
- Modify（同一认证模块的必要构造调用点）: `crmeb/crmeb/services/auth/AuthSessionStore.php`，新增 `fromConfig(): self`；用已校验配置创建独立、无原生prefix/serializer的Redis连接，connect/read timeout为2/1秒，auth/select失败受控503。不得复用或改动业务Cache原生客户端选项。
- Create（退出绑定核对的必要调用点）: `crmeb/crmeb/services/auth/lua/revoke_session.lua`；Store增加 `revokeBoundSession(app,sid,id,type,audience,version,tokenDigest): void`，先原子核对持久、已初始化且未blocked的账号版本与active会话全部身份/摘要/期限，再只删对应sid。Token.revoke无数据库回调，核对服务器记录中的戳结构即可；实际退出接口仍先经过完整middleware当前凭据认证。不得把纯JWT验签当作直接DEL依据；缺账号/会话或已consumed均401零写入。Task2两参revokeSession幂等原语保持不变。
- Create: `help/audit-regressions/isolated/ms0b-token.php`（真实框架/Redis/JWT/PDO摘要读取；实际配置、声明、时间、冷语言缓存与文件缓存/log、到期点、签发/刷新故障和常驻实例边界）。
- 2026-10-11测试复核补齐：所有case清理前观察真实文件缓存/log哈希，覆盖签发/刷新执行后丢应答专属失败路径；stamp_key与signing_key完全相同须503（独立密钥约束）；null pwd也属凭据字段，超配置TTL声明401且不读账号；JWT调用者静态timestamp/leeway在成功/失败均恢复，认证仍用实际当前时钟。原123项缺类基线保留，新测试127项须另录完整基线，A10=35、A22=57，其余数量不变。

**Interfaces:**
- Produces:

```php
// crmeb\services\auth\CredentialStamp
public static function for(string $app, string $id, string $credentialDigest): string
        // hash_hmac('sha256', "$app|$id|$credentialDigest", stamp_key)
// crmeb\services\auth\AuthTokenV2（无上次请求身份状态，仅持有Store依赖）
public function __construct(AuthSessionStore $store)
        // 每次调用重新读取AuthConfigGuard::authConfig；配置epoch必须与store.epoch()一致，否则503
public static function fromConfig(): self
        // 生产调用者通过AuthSessionStore::fromConfig创建独立且有界的连接；测试可显式注入真实故障连接
public function issue(string $app, string $id, string $version, string $credentialStamp): array
        // 返回 ['token' => string, 'exp' => int, 'sid' => string]；会话写入成功后才返回
public function verify(string $app, string $token, callable $currentCredentialDigest): array
        // 返回 ['id' => string, 'sid' => string]；顺序：验签与时间 → auth_schema=2 → iss/aud/type 与 $app 配置一致
        //   → AuthSessionStore::verify（用当前凭据摘要重新计算 credential_stamp）
        // callback(string $id): string，返回当前数据库凭据摘要；相关业务调用点负责当前状态检查
public function refresh(string $app, string $token, callable $currentCredentialDigest): array   // 单次兑换
public function revoke(string $app, string $token): void
```

- [x] **Step 1: 写用例** A22：受控签发器生成签名有效、但 `auth_schema` 缺失或为 1、`iss`/`aud`/`type` 不匹配、缺少 `sid` 或 `session_version` 的令牌 → 全部 401。A10-v2：`AUTH_SESSION_EPOCH`、`AUTH_STAMP_KEY` 缺失或过短 → 503。补必要声明验证：id规范正整数；sid/version为32位小写hex；iat/nbf/exp必需严格整数且符合次序与60秒容差（即使nbf已存在也须独立拒绝未来iat）；拒绝v2中的pwd凭据字段。配置缺字段/错类型/无效TTL均503，默认type/admin-out和30天TTL兼容既有正常路径。回调仅在签名/声明全通过后调用；当前摘要为空/账号不存在401，实际数据库依赖故障503。签发持久成功后返回；刷新先验证、再原子兑换，真实执行后丢应答不返回token或补偿旧会话；revoke仅本人app/sid。配置世代更新后旧Store实例必须503，不能沿用旧namespace；新Store验证旧token401且零写入。
- [x] **Step 2: 确认失败。** c784f309原123项、406f2d54补齐127项均缺AuthTokenV2正常FAIL/exit1，DB/Redis/期限/文件日志无意外变化；两份原始基线保留。
- [x] **Step 3: 实现。** 配置纯校验503；类型、声明、实际时钟及签名严格核对401；当前凭据HMAC与会话一致，签发/兑换确认成功才返回token。Redis独立连接及owner/namespace不跨用途；revoke原子校验exact binding后删除。原Guard两个旧方法未改，客服/顾客路径未接入v2。
- [x] **Step 4: 运行通过。** 最终127PASS/exit0（ms0b-token-after-20261011103252.jsonl），5PHP lint、既有UNIT-STUB权限43/43/exit0、全158张表/全部Redis值及期限/真实file-cache/log检查通过。首次after125PASS/2FAIL保留：真实Env批量setter将错误类型数组展开，在Env.php:120先抛TypeError；隔离诊断确认单键setter能原样保留输入，9e3fd519仅修测试设置方式，原503/无IO断言及6生产文件完全未改。task3-tested-source.json保留运行时HEAD+当时未提交6文件的哈希，最终Task10仍须清洁冻结新RUN回归。
- [x] **Step 5: 本地提交** `feat(auth): v2 token issue/verify bound to app audience and server session`；未推送，整个MS0b仍未完成。

### Task 4: 管理端接入 v2

**Files:**
- Modify: `crmeb/app/services/system/admin/AdminAuthServices.php`（`parseToken()` 改用 `AuthTokenV2::verify()`）
- Modify: `crmeb/app/services/system/admin/SystemAdminServices.php`（`login()` 改用 `initAccount()` + `issue()`；退出改用 `revoke()`）
- Modify: `crmeb/app/adminapi/controller/v1/setting/SystemAdmin.php`（真实退出接口）；`crmeb/app/adminapi/AdminApiExceptionHandle.php`（受控v2异常直接JSON，避开Json::make再次翻译缓存写入和debug trace）
- Modify（真实登录入口的必要前置调用点）: `crmeb/app/adminapi/controller/Login.php`。Request::postMore在service.login前会经filter_str/sys_config写缓存，即使filter=false仍有more中的无条件过滤；入口须在原postMore之前验证独立有界Redis连接及真实PDO连接，失败固定503。实版本Db::connect()->getPdo()只返回已有句柄/false，不建立连接；使用接口实际公开的Db::connect()->connect()，成功后保留原输入过滤、验证码、校验和400失败计数。前置不能冒称消除执行中途依赖失效的竞态，Task6仍验证部分失败。
- Create（启动认证边界的必要兼容调用点）: `crmeb/crmeb/compat/think/Db.php`、`DbCache.php`；Modify: `crmeb/app/AppService.php`；Create: `help/audit-regressions/isolated/ms0b-bootstrap.php`。实际App::initialize→ModelService::boot→think.Db::__make先于请求/管理端handler，在cache.store时以timeout=0构造旧Redis客户端。暂停前启动真实App的诊断已证实：initialize在3秒看门狗恢复后才完成，旧READ_TIMEOUT=0且全快照不变。不能靠改AuthSessionStore或测试超时时间掩盖。兼容Db保留原setConfig/event/log/triggerSql，只注入标准think.Cache派生的延迟ORM缓存；DbCache仅在第一次缓存操作时委托原共享Cache manager的选中default/named store，保留tag、原client实例、backend、prefix、serialize和native选项，不修改vendor或换文件缓存。根AppService在ModelService boot前绑定think.Db，验证db/think.Db/DbManager/model/facade一致。新增测试先提交/记录旧实现失败，再实现；包含暂停下纯bootstrap完成及v2工厂503、默认/命名缓存/tag行为和共享选项不变。此变更影响启动连接时机，正常协议不变；不冒称覆盖运行中后续业务缓存故障。
- Create: `help/audit-regressions/isolated/ms0b-admin-http.php`（30项真实HTTP接线场景；不冒称覆盖尚未接齐Task6的永久版本撤销或生产HTTP竞态）。
- Modify（测试真实前置条件/观察的必要调用点）: `help/audit-regressions/isolated/lib/harness.php`、`compose.yml`、`run.sh`、`docs/multi-merchant/03-test-environment.md`。新RUN私有卷生成独立epoch/stamp并供给PHP_AUTH_*；runner只读观察实际app-runtime卷；增加仅本次RUN受控的缺配置/APP_DEBUG/DB闭合端口1/CACHE_PREFIX override；冷故障用TCP监听就绪，不用login/info预热。MySQL/Redis主机及账号/库仍固定，guard及安装初始化不变，不映射端口。新RUN首次自检11PASS/1FAIL保留：runner-runtime首次启动才创建，旧清单缺该卷导致服务控制严格拒绝；run.sh先运行原guard，再登记实际卷身份，之后才运行测试/故障控制，不放宽身份断言。

**Interfaces:**
- Consumes: Task 2、Task 3
- Produces: 管理员凭据摘要 = 数据库中的 `pwd` 哈希字符串
- 接线设计：主登录先完整配置/独立Redis连接校验，再只读校验密码并保留该次原digest；initAccount后重读当前账号存在/status=1/is_del=0及digest严格相同，再createSession CAS。统计仅在会话确认成功后写。可提取只读helper并让既有verifyLogin公共方法保留原统计行为，主登录用helper。实际全源检索确认verifyLogin当前仅由主login调用，verifyFileLogin无直接调用；文件编辑真实入口SystemFileServices::Login校验filesystem.password并独立签发旧file_edit协议，不改该路径或BaseServices::createToken，不为其新增AUTH配置前置。无效凭据保持既有400；正常失败仅允许精确login_captcha计数及CRMEB标签SET增加该计数键成员，其他成员和到期点不变，禁用账号仍零写。配置/依赖失败503不得转false或进入限流写入。已核对Login控制器对service.login没有catch，通常无需在Task4改控制器；只要后续必要实现确需改才增列同一调用点。
- Task4 30项通过只能证明接线和当前摘要失效；A21同秒登录/改密、永久版本撤销/状态重启复活及部分失败依赖Task6。接线可先本地提交并如实记录，Task4完整验收检查保持待Task6联合验证；不删原A21要求或提前标里程碑完成。
- 退出暂与现有middleware使用同一header优先、空header才GET token的提取规则，避免认证成功但revoke空串；Q7/Task8统一移除两处URL fallback。首次HTTP基线83dc4070为8PASS/22FAIL、exit1并保留。其中download测试用了含连字符的not-a-file，与真实默认变量pattern [\w\.]+不匹配；改成既有MS0a的AUDITmissingRUN，不改Role400/零副作用断言或路由。三项正常凭据失败窗口虽预热login/info，但该路由不建立输入过滤缓存，出现5个额外配置缓存变化；窗口明确为正常已初始化应用，先同前缀真实成功登录再观察，仍严格只允许既有限流counter/精确tag增量。独立冷配置/Redis/DB窗口不预热、不放宽全零断言，更新测试后重新保留完整旧服务基线。
- 首次after为27PASS/3FAIL、exit1保留。独立logout快照证实：仅当前sid删除，eb_system_log由23→24且恰好一条当前账号/path/ip/page/time的既有审计，旧行/其余157表/其他缓存和到期点不变（diagnostic-admin-logout.json，exit0）。既有AdminLogMiddleware在认证/角色通过后、controller之前记录GET退出，不能删除合法审计来通过测试；正向仅允许这一条精确记录，不整表忽略，负向仍全158表零写。两项Redis pause HTTP仍阻塞待修；独立真实factory诊断在1011ms受控503、全部快照不变，不支持猜修factory timeout，继续追实际HTTP链。诊断曾与logout并行干扰清单/DB，失败轮原样保留，独立重跑才作为证据。

- [ ] **Step 1: 用例**：A24（旧格式管理员令牌，即使旧 `md5(token)` 键仍在，也被拒绝；Redis 中其他业务键不受影响）；A21-v2（改密后旧令牌失效，新登录可用；同一秒内"登录"与"改密"并发，改密胜出）；全部 MS0a 管理端用例的回归。
- [ ] **Step 2–5**：确认失败 → 实现 → 通过 → 提交 `feat(adminapi): switch to v2 sessions`。

2026-10-11接线阶段进度（不替代上述完整A21）：30项HTTP测试先提交并保留旧服务12PASS/18FAIL新RUN基线；首轮after27PASS/3FAIL，精确正常退出审计及实际启动Redis阻塞按上文处理。62c201ba先提交8项bootstrap测试，旧实现6PASS/2FAIL，保留6项既有PSR16/tag/DB/ORM兼容；实现后8PASS/exit0，30项HTTP最终30PASS/exit0。8个生产文件本地提交7c26c85b，测试时HEAD+未提交文件哈希与提交后一致，原raw source元数据保留。记录见records/ms0b/report.md；Task4完整验收勾选仍待Task6同秒并发/永久撤销联合测试及Task10完整MS0a回归，不标整个MS0b完成。

### Task 5: 对外账号接入 v2

2026-10-11 后续普通功能开发：新增独立 `help/audit-regressions/isolated/ms0b-out-functional.php`，只检查合成账号正常HTTP登录及v2响应、受保护分类读取、一次正常刷新后新会话读取、同一服务实例A/B/A身份及正常MCP初始化/分类工具。用新建ISO-FULL实例及既有fixture，观察全部表和Redis实际变化，明确只允许本次正常登录/刷新目标统计与会话、正常输入缓存变化；令牌与密码不写证据。先保留正常流程在旧服务上的实际基线，再安装本Task已审查的三个业务文件并验证。综合安全套件保持停用，不重复负向凭据、失效令牌、配置/依赖故障、并发或断连试验；正常检查通过仅支持功能接线结论，原安全覆盖缺口保留。

- [x] 普通功能接线与本地提交：测试6d8c86ca先于业务3acfd5ae；新RUN=20261011143154925356旧服务正常基线3PASS/3FAIL（6条原始结果，原PowerShell包装未回传Bash退出码，不把宿主exit0记为通过）。实现后F01–F06全部PASS，原生Bash exit0，7个正式观察窗口完整核对158表、全部Redis值/期限及runtime；准备窗口单列、不造PASS。正常刷新只请求一次，用实际新token继续读取；MCP协议保持。3业务文件与受审草稿/测试时哈希相同。专用资源已teardown exit0并复查无残留；详情见report。原安全复现/故障/并发/断连验证继续跳过，完整Task5安全验收不标通过。

**Files:**
- Create（普通功能检查）: `help/audit-regressions/isolated/ms0b-out-functional.php`；仅复用现有harness、单worker和健康依赖，不引用已停用的综合套件、token-forge或fault。
- Modify: `crmeb/app/services/out/OutAccountServices.php`（`authLogin()`、`parseToken()`、`refresh()`）；`crmeb/app/outapi/OutApiExceptionHandle.php`（同一受控异常必要返回调用点，直接JSON、不得记录请求参数/底层异常）
- Modify（真实公开入口前置及测试并发的必要调用点）: `crmeb/app/outapi/controller/Login.php`、`help/audit-regressions/isolated/lib/harness.php`；Create: `help/audit-regressions/isolated/ms0b-out-http.php`。公开getToken/refreshToken在postMore前校验v2配置和真实有界Redis/PDO依赖；多worker仅供本RUN的隔离PHP服务，SERVER_WORKERS严格只允许未设置或2，记录实际PID/PGID、启动/停止及遗留进程清理，不将单worker排队冒称并发。不得修改生产HTTP传输或增加测试钩子来制造竞态。
- Create（真实多worker验收前置）: `help/audit-regressions/isolated/ms0b-http-workers.php`；03记录相同隔离边界。先提交/保留旧supervisor基线，再启用严格SERVER_WORKERS override与本组只读observe。观察只返回本generation PGID/SID/starttime、数字PID及其到专用audit-redis:6379的socket端口/inode，不返回cmdline/environ/请求内容；不停止服务或写Redis。start/stop ACK不能当作实际运行/清理完成，分别以真实PHP启动日志PID、TCP监听及本组成员证据核对；默认单worker正常行为保留，不要求既有正常行为失败。wrapper提前退出的清理另用隔离容器内核对本RUN/登记PID及starttime的故障诊断，保持原生传输和业务代码不变。

**Interfaces:**
- Produces: 对外账号凭据摘要 = 数据库中的 `appsecret` 哈希字符串；`refresh()` 使用 `AuthTokenV2::refresh()`（单次兑换）
- 实源预审补齐：authLogin不得以appsecret真假值决定是否验密，null/空串/字符串0均不能跳过password_verify。实版本Validate::require允许字符串0，旧service真假判断让该值通过HTTP入口跳过验密；空/null若被原HTTP validator以400提前拒绝，另用真实service调用覆盖其401边界，不改验证器或将既有拒绝冒称新修复。auth_info及parseToken结果隐藏存明文凭据的apppwd、push_password，保留其余DTO和rules可消费形态，以专用非空合成fixture且push_open=0证明字段不存在、原数据库值不变、不触发外部推送；不全局隐藏模型、改CRUD/历史库值或推送消费点。公开refresh_token自行验证body.access_token，MCP仍独立header直接凭据，不额外加角色中间件或JWT配置前置。Login公开入口与管理端相同有过滤缓存先行链，必要的早期配置/依赖前置纳同一调用点。HTTP并发需先在harness验证实际多worker/PID、两个请求同时处理的Redis阻塞/连接证据，不能仅以两个TCP accept/排队或CLI原语竞态冒称HTTP并发；固定短时CLIENT PAUSE WRITE屏障须在认证1秒read_timeout内并真实观测，旧token只有一个replacement。响应丢失用真实HTTP发送完整请求后、未读取响应字节时关闭socket，须先证明服务端已进入刷新处理、其后独立观察实际消费/仅一replacement和重试旧token401，不按客户端超时推断执行结果。保护完整DB/Redis值/期限/实际runtime日志窗口，释放屏障/停止worker后再次核对。
- 48项测试独立源审补齐：旧schema冷刷新即使独立配置/Redis/PDO前置成功，postMore仍会在服务验证前通过filter_str/sys_config写缓存。保留A24冷401全零断言；刷新入口改从真实Request::post('access_token', '', null)读取POST body原始签名字符串（框架null过滤器明确关闭过滤，纯input/getData不做业务IO），严格非字符串401，直接交给单次刷新服务。JWT必须逐字验签，不能经通用业务regex修改；正常JWT与access_token/exp_time/envelope协议不变，登录getToken仍保留原postMore/validator。不新增Token预验证API或重复凭据查询/验证，不改vendor或全局输入过滤器。此必要入口设计先记录、后更新仓库外草稿，旧服务完整基线保留。
- 原真HTTP扩展设计（2026-10-11 用户指令后停止执行，仅保留测试定义）：合并脚本默认为50项，`--only=refresh-races`选择两个确切A25场景。48项旧服务基线保留，新2项源码已提交95b1e0cb，但没有执行基线或修复后验证；不得拿worker前置通过或安装引导当作HTTP竞态通过。当前 `run.sh` 对两种选择均在执行前拒绝，后续不再默认运行此综合套件。

- [ ] **Step 1: 用例**：A24（旧格式对外令牌被拒绝）；A25 的 HTTP 子场景（并发刷新只成功一个；客户端在收到响应前断开时，服务端不产生第二个有效会话）；A19、A28 中对外账号相关部分回归；MCP 回归（不受影响）。
- [ ] **Step 2–5**：确认失败 → 实现 → 通过 → 提交 `feat(outapi): switch to v2 sessions with single-use refresh`。

2026-10-11测试运行前置进度：563f045d先提交5项真实worker生命周期测试；旧supervisor实际2PASS/3FAIL、exit1，默认单进程及非法override拒绝既有行为不制造失败。实现后5PASS/exit0、原12项selftest全PASS/exit0；harness本地提交7895776a，原raw元数据保留测试时HEAD+文件哈希。另在本RUN app容器内严格核对登记leader PID/starttime/PGID/SID及supervisor身份后，只终止该wrapper：4个实际子进程和8000仍存活；stop后独立/proc核对全组（含僵尸）空、监听关闭，全部158表/Redis值及期限不变。diagnostic源和状态保留仓库外，不加生产钩子。48项对外基础场景及真正并发/断开场景仍待旧服务基线、实现和完整通过，不能以worker前置通过标Task5完成。短屏障EVAL等待的原理核对了[Redis CLIENT PAUSE文档](https://redis.io/docs/latest/commands/client-pause/)及[8.6.1暂停处理源码](https://github.com/redis/redis/blob/8.6.1/src/server.c#L4290)、[阻塞标记源码](https://github.com/redis/redis/blob/8.6.1/src/blocked.c#L595)、[CLIENT LIST序列化源码](https://github.com/redis/redis/blob/8.6.1/src/networking.c#L3656)；仍须真实HTTP运行证明本次重叠。

### Task 6: 凭据与状态变更的撤销流程

2026-10-11 入口清单已完成源码核对：10个既有账号变更入口、2个新建持久化入口，包含事业部直接模型写入、批量状态更新和物理删除的最外层事务。清单见 `records/ms0b/credential-writer-inventory.md`；读取时源码与当前对应writer文件一致，只有静态证据，不代表流程已接入或通过运行检查。后续并发、依赖故障和部分失败复现保持跳过，普通修改/创建流程检查单独记录。

普通事务设计见 `records/ms0b/account-change-design.md`：实际框架没有afterCommit/公开事务深度接口，Guard持有已知最外层transaction，并用真实PDO身份及inTransaction核对提交；内层具名writer只复用同app、同连接、已冻结主体集合的本次上下文，不提前complete、不在业务写入后扩张保护集合。没有受管上下文但已有PDO事务时拒绝接线，不能把模型内层savepoint当成外层提交。上下文仅活在本次DB闭包并finally清空；新建路径保留自动id及原普通事务，不伪造旧subject；批量按真实管理员id冻结，不用事业部uid代替。已在8efff286本地实现Guard及三服务接线；下述普通运行证据不替代跳过场景。

普通接线细节：`save`/`updateAdmin` 的纯资料patch不写已确认未变的敏感字段，所以保留现有会话；显式 `update` 若含pwd/status/is_del或appsecret/status/is_del则保护全部冻结目标，即使同值更新的affected rows为0也视为合法操作并轮换版本，原Model返回形态保留。不带上述键的统计/filePwd/push配置更新沿原DAO。Division编辑不得写原代码未写的adminData.status；空管理员集合跳过管理员SQL，不能依赖ORM的空IN表达式。PDO/Store异常只记固定分类、阶段和数量，受控503不带底层previous；普通业务验证异常按原响应保留。

本次独立普通检查脚本为 `help/audit-regressions/isolated/ms0b-account-functional.php`，只通过真实CLI service/DAO及本RUN新建合成行验证正常W01–W10、N01/N02与非敏感更新，记录精确目标行/列、账号版本变化、其他所有表/缓存/期限。源审确认安装SQL的eb_system_admin无file_pwd列，而旧setFilePassword赋值该列；对应兼容检查记NOT RUN（原路径保持，不造DDL、不算通过），其余普通流程继续。并发、故障、旧/错误token请求仍不执行。

- [x] 普通功能接线与本地提交：测试d52ba7dd先于业务8efff286；e70aa915增加仅类名/文件位置诊断，32953a26修正检查脚本将业务昵称CF_USER误判为同名PHP函数的问题，字符串恢复为数据比较，时间谓词改为显式Closure，全字段断言保留。RUN=202610111523384ecf19最初两份基线各4PASS/11FAIL/1NOT RUN、Bash exit1，保留原始结果；修正后的旧writer基线5PASS/10FAIL/1NOT RUN、exit1，10项只缺会话版本轮换；实现后15PASS/1NOT RUN、exit0，22个正常观察窗口核对全部158表、所有Redis值/绝对期限及runtime。W01/W05另用正常新凭据成功登录，精确核对真实新会话及本次统计。filePwd仍NOT RUN；只有CLI-SERVICE证据，没有本Task HTTP验收、旧令牌拒绝或被跳过场景结论。四个业务文件与受审/测试内容哈希相同，3容器/1网络/9卷已teardown exit0并复查无残留。详细记录见report与task6-functional-summary.json。

**Files:**
- Create（独立普通检查）: `help/audit-regressions/isolated/ms0b-account-functional.php`
- Create（已核对的入口记录）: `docs/multi-merchant/records/ms0b/credential-writer-inventory.md`
- Create（普通事务设计记录）: `docs/multi-merchant/records/ms0b/account-change-design.md`
- Create（普通运行及源码摘要）: `docs/multi-merchant/records/ms0b/task6-functional-summary.json`
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

- [ ] **Step 1: 用例**（以下并发/故障场景按用户最新要求跳过，不记通过）：A21-v2 中"改密、停用、删除各自让全部旧会话失效"；原故障注入设计：步骤 1 失败时数据库不变（执行后丢应答已有blocked/新版本，仍不改库）；步骤 2 失败时账号保持 blocked、不能登录；步骤 3 失败时接口不返回成功，未执行仍blocked，已执行丢应答可能已解除但旧版本始终失效、数据库此前已提交。
- [ ] **Step 2–5**：确认失败 → 实现并接入全部入口 → 通过 → 提交 `feat(auth): block-rotate-change-unblock for credential and state changes`。

### Task 7: 会话状态丢失与世代号轮换

**Files:**
- Create: `crmeb/app/command/AuthEpochRotate.php`（命令 `php think auth:epoch-rotate --confirm`）、`docs/multi-merchant/records/ms0b/runbook-session-loss.md`
- Modify（显式命令注册的必要调用点）: `crmeb/config/console.php`
- Modify（既有私有原生连接的必要调用点）: `crmeb/crmeb/services/auth/AuthSessionStore.php`，只增加窄接口 `deleteOldEpoch(string $oldEpoch): int`
- Create（独立普通命令检查）: `help/audit-regressions/isolated/ms0b-epoch-functional.php`
- Create（普通运行及源码摘要）: `docs/multi-merchant/records/ms0b/task7-functional-summary.json`

**Interfaces:**
- Produces: 命令要求显式 `--old-epoch=<旧值>` 和 `--confirm`；新旧epoch限安全字符，不允许冒号/通配符。校验新的 `AUTH_SESSION_EPOCH` 已在配置中生效且与旧值不同；按 `SCAN` + 前缀删除**旧 epoch** 下的 `auth:v2:<old>:*` 键（分批，打印数量，不打印键名）。不接触其他键。

2026-10-11 实源设计补齐：沿现有Guard/Store使用 `/^[A-Za-z0-9._-]{1,128}$/D`，不trim或改写输入。新值读取实际已加载的 `AuthConfigGuard::authConfig()`，不能只看Env尚未加载的变量。`AuthSessionStore::fromConfig()` 已有独立有界连接（connect2秒/read1秒），但Redis为private且无公开SCAN方法；由新增deleteOldEpoch封装精确旧前缀，重复核对old与当前实例/live配置不同且一致，不暴露raw getter、不走共享Cache。SCAN COUNT=200是提示；每个实际返回页先逐键核完整旧前缀，再最多200键一批DEL，累计实际DEL整数，空页继续到cursor=0；不以扫描数代替删除数、不自动重试失败。命令configure只声明选项，execute先校验显式old/confirm与配置，再连接/删除；成功仅输出一行 `deleted_keys: <实际整数>` 并exit0，拒绝或异常只输出固定说明并exit1，不向Think默认异常渲染传递driver消息或私有值。命令不修改配置/数据库、不证明全部实例已加载新配置；人工隔离入口及配置下发属于未来L4操作，当前只写runbook。

2026-10-12 本机接口核对：现有PHPRedis的scan游标为 `string|int|null` 引用，返回 `array|false`；false不能作为成功空页的可靠证据。窄方法在同一有界私有连接上使用实际 `rawCommand('SCAN', <游标文本>, 'MATCH', <准确旧前缀*>, 'COUNT', '200')`，严格检查响应为uint64游标文本及键名list，保持大游标为文本；正常空list继续到文本'0'。false、错误响应或连接异常均受控失败，不把解析失败记为扫描结束。仍是同一SCAN/分批DEL设计，没有改变删除范围或增加恢复/故障试验。

普通检查范围为三个CLI场景：真实 `php think list --raw`/help证明注册与选项；本RUN新建正常旧空间大于单批上限的真实签发会话及两应用账号键，在已配置新epoch下正常执行一次精确清理；对已空旧空间再正常执行得到删除0。实际php进程仅使用私有RUN环境，全部158表/所有其他Redis值及TIME/PTTL绝对期限/runtime不变；自有当前epoch及相似边界epoch保持。输入缺失/异常配置、依赖故障、恢复、旧token拒绝及并发场景不运行，不提供完整A29验收结论。测试先提交并保留命令尚未注册的实际基线，再实现、验证普通流程和本地提交；原恢复要求继续记为跳过。

- [x] 普通命令实现、runbook与本地提交：设计ed08f94b、测试903e0dfb先于业务a9c3ce35。RUN=20261011160257a153b4旧源基线2FAIL/1NOT RUN、原生Bash exit1；缺注册使list/help及清理契约失败，真实未清空则不执行空空间重复。实现后3PASS、exit0：4个实际think子进程全部exit0，删除1026旧键的实际输出精确一致，独立INFO观察7次DEL；当前空间4键及相似前缀4键、158表、其他所有Redis值/绝对期限和runtime不变。真实Token.issue正常准备与精确finally清理单列，不造恢复/旧token验收。三PHP与runbook哈希匹配审查及测试内容；3容器/1内部网/9卷已teardown exit0并独立复查无残留。详情见report与task7-functional-summary.json，完整A29仍未验收。

- [ ] **Step 1: 用例**（恢复试验按用户最新要求跳过，不记通过） A29：原设计模拟 Redis 从旧快照恢复（备份 RDB → 写入新会话 → 恢复 RDB）→ 隔离认证入口 → 轮换 epoch → 旧会话全部 401、重新登录可用；业务缓存键数量前后不变。
- [ ] **Step 2–5**：确认失败 → 实现命令与 runbook → 通过 → 提交 `feat(auth): epoch rotation command and session-loss runbook`。

### Task 8（按用户委托纳入 Q6/Q7）: 登录验证码与 URL 令牌

- Q6 本阶段纳入：失败计数改为按与DAO一致的账号规范化值 + 服务端可信IP计；保留60秒窗口，两次凭据失败后下一次尝试必须验证码。成功仅清当前组合，失败包装数字 login_captcha=1 沿用现有前端；超过阈值后，服务端**要求**验证码字段存在且校验通过。用例：连续失败后不带验证码登录被拒绝。
- Q7 本阶段纳入：`AdminAuthTokenMiddleware` 不再接受 `?token=`，现有两处裸window.open下载改用本站header+Blob消费。当前无必须URL直下的大文件需求，不引入新匿名凭证协议；以后确需直下时再实现短期、一次性、限定文件/主体/会话的下载凭证。开工前先列出前端所有使用 `?token=` 的位置并回报。
- 必要Files：`crmeb/app/adminapi/controller/Login.php`、`crmeb/app/adminapi/middleware/AdminAuthTokenMiddleware.php`、`crmeb/app/adminapi/controller/PublicController.php`；新增小下载helper `template/admin/src/libs/download.js`，修改 `template/admin/src/pages/system/codeGeneration/list.vue` 与 `template/admin/src/pages/system/maintain/systemDatabackup/index.vue` 两个真实消费点。仅对可信本站后端发header，拒绝外部URL/跳转，处理JSON错误与文件名；下载缓存成功消费采用原子比较删除，重复下载失败，保留现有admin+role边界。旧已注释npm/EventSource按钮后端路由不存在，移除该死代码的URL JWT，不凭空开发新后端。扫码上传/fileToken/客服/uni-app不是Q7 JWT，不批量改动。
- 代码调用证据见仓库外 ms0b-q7-caller-inventory.md；Task8当前只执行正常登录、既有验证码正常展示、header下载及两处实际浏览器消费检查。连续错误凭据、缺失验证码拒绝、URL JWT拒绝、重复消费拒绝及同类负向/故障验证遵循顶部用户跳过指令，不执行、不改名转入普通检查，也不记PASS。验证码完整通过只接受正常人工操作证据，不从服务端答案生成通过材料。两项各自单独提交；普通检查与被跳过的完整验收缺口分别记录。

2026-10-12 实源设计补齐（继续现有L2委托）：

- Q6：现有坏账号/密码确实由service返回false，只在该分支计数；禁用、会话变化和依赖异常不计数。增加同DAO的只读身份helper，已命中账号用实际id与存储account，未命中ASCII输入用独立前缀的小写值，不猜数据库排序规则。修正合法账号`0`在模型搜索器遗漏account条件的必要调用点。身份与`Request.ip()`的打包IP经独立purpose的HMAC构成无明文键；使用Store私有有界连接和单键Lua读/增加/删除，首失败EX60，递增保持原到期点，第二次返回数字1，下一次提交前强制既有二次验证码。验证码通过不清计数，完整登录成功只清当前组合；登录页info保持数字0，不读取全局计数。
- Q7：两条生产链分别是SystemCrud.download及MysqlBackupService.downloadFile；备份query key先取标量。新增CacheService.consumeDownload，以实际默认Redis配置prefix（已核对Driver.getCacheKey只拼接prefix）读取原始PHP序列化字节、校验文件，再单键Lua严格比较该字节并删除；不重试、不改共享连接选项。提取Store现有独立有界连接工厂BoundedRedisConnection，供Store及下载消费复用；不新增认证配置要求。当前serialize=[]的下载记录沿PHP反序列化并禁止对象，非此配置受控失败。CRUD每次生成随机32hex key；两生产者只在缓存写入成功后返回链接/key。中间件与logout同时移除GET token fallback，前端清理失去入口的npm/EventSource死代码，其他token协议保持。
- 新增/必要调用点：`crmeb/crmeb/services/BoundedRedisConnection.php`、`crmeb/crmeb/services/auth/AuthSessionStore.php`、`crmeb/crmeb/services/auth/lua/login_failure.lua`、`crmeb/app/services/system/admin/SystemAdminServices.php`、`crmeb/app/model/system/admin/SystemAdmin.php`、`crmeb/crmeb/services/CacheService.php`、`crmeb/app/adminapi/controller/v1/setting/SystemAdmin.php`、`crmeb/app/adminapi/controller/v1/setting/SystemCrud.php`、`crmeb/crmeb/services/MysqlBackupService.php`，以及前述控制器/中间件/helper/两个Vue页面。无DDL或业务种子变更。
- 普通检查独立记录：新增`help/audit-regressions/isolated/ms0b-login-download-functional.php`，正常凭据登录/账号0查询、info数字协议、正常验证码创建、两生产者及一次正常header文件下载。不自动解答验证码。浏览器仅访问真实源码构建页面，若通过本RUN CLI传输桥接，只转发明确允许的路径并保留真实状态/响应/文件，不用API假数据；桥接证据不称生产网络验收。离线前端验证用现有Node22及已核对全部2698锁定包version/integrity相同的本地依赖，在仓库外目录构建，避免写现有工程依赖缓存。
- ISO必要写入点：为app/runner增加本RUN专用backup-output卷，供实际CRUD/备份生产者写其固定backup目录；source/vendor仍只读，内部网络无宿主端口，DML账号不扩权。仅调整compose、隔离README、03的同一ISO记录，teardown仍按manifest清理本RUN资源。原九项停用套件及运行入口不变。完整门槛、负向/故障/并发验收继续留缺口，不以普通检查替代。

### Task 9: 发布准备文档（不发布）

**Files:**
- Create: `docs/multi-merchant/records/ms0b/cutover-plan.md`、`docs/multi-merchant/records/ms0b/integration-inventory.md`

- [ ] 按 00 文档 §11.3 编写集成清单：代码侧入口逐项列出；"实际在用"一栏写"未知，待核实"，除非有可核实的证据。
- [ ] 切换计划：前置条件（集成清单已核实、通知已发出——由用户执行）、部署顺序、`AUTH_SESSION_EPOCH` 与 `AUTH_STAMP_KEY` 的生成和下发、旧会话失效对管理员和第三方的影响、回退方案（回退版本必须保留 MS0a 边界）。
- [ ] 文中不得出现"已通知""已部署"等未发生的事实。

### Task 10: 全量回归与交付

以下全量安全回归要求保留为未验收缺口，实际执行遵循本计划顶部用户最新跳过指令；只能报告已完成的普通检查和逐项跳过，不得把综合回归或里程碑改记为全 PASS。

- [ ] 原完整回归要求（按用户最新要求停止执行同类综合套件，保留覆盖缺口）：在新建的 ISO-FULL 环境运行：`security.php`、`selftest.php`、`ms0a-auth.php`、`ms0b-session-store.php` 以及 MS0b 的 HTTP 用例；全部 `exit=0`。v2回归保留旧业务拒绝/零副作用/原应用可用断言；旧pwd/md5白名单构造迁为当前v2会话输入，旧schema拒绝另由A24在旧键仍存在时真实覆盖，不拿旧schema拒绝伪装v2匹配测试。测试脚本适配输入形态前逐条记录对照，不放宽业务期望。
- [ ] `git diff --name-only 3e7c966ecfa084ff4f219b03c61ed0efbac4caac HEAD` 只包含本计划列出的路径（Task 6 中 grep 出的入口逐项说明）。
- [ ] 写 `records/ms0b/report.md`、`results.jsonl`；提交；停止，等待验收（11 文档 §5.3）。
