# MS0a：最小安全修复 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。按任务顺序执行，步骤用复选框（`- [ ]`）跟踪。需要授权级别 L2；Task 7 有一个强制停止点 SP-MS0a-1。

**Goal:** 堵住已定位的跨应用身份混用、认证失败时的业务写入、失效账号仍可访问、权限放行，以及认证配置可以静默降级的问题；复用现有令牌格式和会话白名单，不引入会话 v2。

**Architecture:** 新增两个小工具类：`AuthConfigGuard`（签名密钥与会话存储检查）、`SessionWhitelist`（读取并比对现有 `md5(token)` 白名单记录）。`JwtAuth` 增加无状态的验签解码方法。四个应用（adminapi、outapi、kefuapi、api）的令牌解析改为"先验签 → 固定 type → 白名单内容比对 → 当前账号状态"。授权改为"未登记即拒绝 + 显式白名单"，并补上菜单变更时的缓存清理。

**Tech Stack:** PHP 8.2、ThinkPHP 6 多应用、firebase/php-jwt v5.0.0、Redis、MySQL 8.0；测试在 `help/audit-regressions/isolated/`（[03-test-environment.md](03-test-environment.md)）。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §4.2、§4.3、§4.5；事实 [01-code-facts.md](01-code-facts.md) F-10–F-19、F-24–F-26、F-30–F-34；用例 [10-acceptance-matrix.md](10-acceptance-matrix.md) 中阶段为 MS0a 的项。

## Global Constraints

- 本次起点：用户确认通过的 `codex/audit-base@6dee65785b5655d7bac7c3838b95106c7c0549cc`，工作目录 `H:/天俪商城/tianli-mall--ms0a`，分支 `codex/ms0a`。历史 `AUDIT_BASE_SHA=9685731dcd116784d38e6bbdb902cec3c0c84975` 保持不变；两者业务源码相同，当前起点保留已批准的测试契约修订和 BASE 记录。
- 用户于 2026-10-11 在本会话确认 BASE 通过、授权 MS0a L2，并批准 Windows 使用 ISO-FULL（03 文档 §2.1）。本计划中的宿主 `php` 测试命令在专用 runner 中执行；Mac 绝对路径按上述 worktree 对应替换。执行清单仍以本文件为唯一记录。
- 2026-10-11 用户批准正常授权探针使用安装种子已有的 `GET product/product/rule`（商品规则列表）。原计划的商品列表接口未登记；保持该事实并纳入 Task 7 清单，不为通过测试而添加权限种子。
- 2026-10-11 用户批准 Task 1 补充文件校验引导初始化（03 §2.1）：在专用实例内由 audit_migrator 调用现有 writeMd5() 真实生成校验数据并记录当前版本标记，应用仍仅有 DML 权限，Z07 断言不变。实际触发原因和失败证据见 records/ms0a/report.md P10。
- 不改令牌载荷格式，不新增数据库表或列，不改 `eb_system_menus` 等种子数据。
- 不改客服、顾客令牌的**签发**；只改验证。
- 签名算法固定为 `HS256`，JWT 时钟容差保持 `60` 秒。
- 认证失败只允许两类副作用：`eb_system_log` 审计记录、限流计数键。
- 配置错误统一抛出 `crmeb\exceptions\AuthException('认证服务配置错误', [], 503)`；认证失败统一抛出业务码 `401`。
- 不放宽任何已有断言。`security.php` 的 BASE 原始 23 项保留基线记录；经用户批准，Task 7 的三项旁路放行断言改为拒绝（未登记路由、未登记方法、未授权 CRUD），其余 20 项保持不变。最终要求 20 项原有回归、3 项权限收紧契约及新增用例全部通过。
- 测试先于修复提交，并先在 `AUDIT_BASE_SHA` 上以 `AUDIT_PHASE=baseline` 运行一次。

## Review Focus

- **白名单记录删除**：错误应用里的认证失败，不能删除该令牌在正确应用中的白名单记录（A26）。只有当记录属于本应用、且账号已失效时才删除。Task 4、Task 6 的测试都要覆盖"错误应用请求之后，原应用仍可用"。
- **顾客端回归**：顾客端令牌目前只有 `api` 一种 type。如果基线运行中发现其他合法签发类型，停止并回报，不要扩大允许列表了事。Task 5 Step 1 先盘点签发点。
- **Redis 宕机**：`CacheService::has()` 吞掉异常并返回 `false`，所以 Redis 不可用时表现为"未认证"。MS0a 只要求拒绝且无业务副作用（A23）；区分 503 留给 MS0b。不要为此改 `CacheService`。
- **白名单的真实键名**：`CacheService` 实际写入 Redis 的键带有缓存前缀和标签结构。测试篡改白名单记录（A18）之前，先从 `config/cache.php` 和一次真实登录后的 Redis 键确认真实键名，不要猜。
- **菜单缓存**：`all_auth` 永久缓存，标签为 `crmeb`。菜单变更不清缓存会让"撤权后仍能操作"（A30）。

---

### Task 1: 隔离测试环境（Windows 使用经批准的 ISO-FULL）

**Files:**
- Create: `help/audit-regressions/isolated/README.md`、`compose.yml`、`bootstrap.sh`、`run.sh`、`teardown.sh`、`lib/guard.php`、`lib/harness.php`、`fixtures/ms0a.php`、`selftest.php`

**Interfaces:**
- Produces（供后续所有任务使用，签名见 03 文档 §3.4、§3.5、§4.1）：`audit_guard_assert()`、`audit_app_start()`、`audit_app_stop()`、`audit_case()`、`audit_http()`、`audit_db()`、`audit_redis()`、`audit_snapshot()`、`audit_assert_unchanged()`、`audit_finish()`、`ms0a_fixtures()`；环境变量 `AUDIT_ISOLATED`、`AUDIT_RUN_ID`、`AUDIT_RUN_DIR`、`AUDIT_PHASE`。

- [x] **Step 0: 建立工作目录**（2026-10-11：当前交付起点 6dee6578；新分支 codex/ms0a；工作树干净，文本为 LF）

```bash
git -C /Volumes/Kingston/cdx/projects/tianli-mall--audit-base worktree add -b codex/ms0a \
    /Volumes/Kingston/cdx/projects/tianli-mall--ms0a <AUDIT_BASE_SHA>
```

Expected: 新目录的 `git rev-parse HEAD` 等于 `AUDIT_BASE_SHA`，`git status --short` 为空。

- [x] **Step 1: 写自检用例 `selftest.php`**（先执行并确认 harness 未实现时 exit 1）

用例与断言：

| 用例 | 子场景 | 断言 |
|---|---|---|
| Z02 | `unknown-target` | 把 `AUDIT_RUN_ID` 改成随机值后调用 `audit_guard_assert()`，进程退出码为 `2`，且没有执行任何写语句 |
| Z02 | `cleanup-scope` | `teardown.sh` 只处理 manifest 中的 compose project：用 `docker ps -a --filter label=tianli.audit=1` 前后对比，其他 project 的容器不变 |
| Z06 | `app-account-no-global-priv` | 以 `audit_app` 执行 `CREATE DATABASE audit_guard_probe` 失败；`SHOW GRANTS` 中只有 `audit_auth`.* 的 DML |
| Z06 | `root-not-exposed` | 测试进程的环境变量和应用的配置里都不含 root 密码（按运行目录中的 root 密码文件比对） |
| Z07 | `clean-worktree-boot` | 在没有 `crmeb/.env` 的干净 worktree 中 `audit_app_start()` 成功；`GET /adminapi/login/info` 返回业务 `status = 200` |
| Z09 | `fixture-source` | 记录 `crmeb.sql`（去 CR 后）的 SHA-256；导入后表数量为 157；`audit_sentinel` 存在 |

- [x] **Step 2: 实现环境文件**（含批准的 Docker 完整隔离、现有权限探针、真实文件校验初始化）

按 03 文档 §3 与经批准的 §2.1 实现，包括真实的文件校验引导初始化。`bootstrap.sh` 结束时打印 `RUN_ID` 和运行目录，不打印任何凭据。

- [x] **Step 3: 运行自检**（2026-10-11，全新 RUN_ID=20261011065024d6dda7；bootstrap exit 0，自检 6 PASS / 0 FAIL，exit 0）

```bash
cd /Volumes/Kingston/cdx/projects/tianli-mall--ms0a
help/audit-regressions/isolated/bootstrap.sh          # 输出 RUN_ID
export AUDIT_ISOLATED=1 AUDIT_RUN_ID=<RUN_ID> AUDIT_RUN_DIR=$HOME/.tianli-audit/<RUN_ID>
php help/audit-regressions/isolated/selftest.php; echo "exit=$?"
```

Expected: 6 个子场景全部 PASS，`exit=0`。

- [x] **Step 4: 提交**（本地 ISO-FULL 环境提交；未推送）

```bash
git add help/audit-regressions/isolated
git commit -m "test: isolated MySQL/Redis harness for auth regressions (ISO-FULL)"
```

### Task 2: 编写 MS0a 回归用例并在基线上运行

**Files:**
- Create: `help/audit-regressions/isolated/ms0a-auth.php`、`help/audit-regressions/isolated/lib/token-forge.php`

**Interfaces:**
- Consumes: Task 1 的 harness
- Produces: `ms0a-auth.php`（之后各任务只运行它，不修改已有断言；新增子场景可以追加；支持参数 `--only=A10,A20` 只运行指定用例，不带参数时运行全部）；`audit_forge_jwt(array $payload, string $key, string $alg = 'HS256'): string`（测试签发器，只用于构造 A17/A20/A27 的令牌，使用运行目录中的测试密钥）

- [ ] **Step 1: 盘点各端登录入口**

在代码中确认并写进 `ms0a-auth.php` 顶部注释：admin 登录 `POST /adminapi/login`（`account`、`pwd`，不带验证码字段）；out 登录 `POST /outapi/access_token`、刷新 `POST /outapi/refresh_token`；客服登录入口（`kefuapi` 路由）；顾客账号密码登录入口（`api` 路由）；各端各选一个只读的受保护接口作为探针。

- [ ] **Step 2: 写用例**

全部为 `INTEGRATION` 层，每个负向子场景都做 `audit_snapshot()` 前后对比（表：`eb_cache`、`eb_store_product`、`eb_system_admin`、`eb_out_account`、`eb_user`；Redis：白名单键模式），只允许 03 文档 §4.3 列出的变化。

| 用例 | 子场景 | 期望 |
|---|---|---|
| A01 | 无令牌访问 admin 受保护接口 | 业务码 401，零副作用 |
| A09 | admin/out/kefu/api 四种令牌 × adminapi/outapi/kefuapi/api 四个探针（16 组） | 只有对角线 4 组成功，其余 12 组被拒绝；每组单独记一行 |
| A11 | out 登录令牌 → adminapi（与 `admin_super`、`admin_limited` 撞 ID 各一次） | 被拒绝；`finding_kind` 记为"既有拒绝回归"或"待复现风险"，以基线实际结果为准 |
| A12 | out 令牌经真实 `refresh_token` 续签后 → adminapi（撞 `admin_super`、撞 `admin_limited`） | 业务码 401，无受保护读取，无业务写入 |
| A13 | 客服真实登录令牌 → adminapi（撞 ID） | 被拒绝；客服令牌仍可访问客服探针 |
| A14 | 顾客真实登录令牌 → adminapi（撞 ID） | 被拒绝；顾客令牌仍可访问顾客探针 |
| A15 | `admin_limited`、`admin_super` 各自登录后被置 `status = 0`，再用旧令牌 | 下一次请求 401；恢复 `status = 1` 后需要重新登录 |
| A16 | 软删除（`is_del = 1`）、物理删除、记录不存在 | 均为 401 |
| A17 | 用测试签发器生成 type 为 `admin`、但缺少 `pwd`、`pwd` 为空、`pwd` 错误的令牌，并写入匹配的白名单记录 | 均为 401 |
| A18 | 合法 admin 令牌的白名单记录被改为 `type = out`、`uid` 不一致、`token` 不一致、记录被删除 | 均为 401 |
| A19 | `out_disabled` 登录；`out_101` 取得令牌后被置 `status = 0`，再访问、再刷新；MCP 用 `out_disabled` 凭据；`set_status` 传 `2` | 登录、访问、刷新、MCP 全部拒绝；`set_status` 返回参数错误且数据库值不变 |
| A20 | 过期、`nbf` 在未来、签名错误、`alg` 为 `none` 或 `HS512`、格式损坏 | 均为 401，不出现 HTTP 500 |
| A21 | admin 改密（直接更新 `pwd` 哈希）后用旧令牌；再用新密码登录 | 旧令牌 401，新登录成功 |
| A23 | 删除白名单键后访问；停止 `audit-redis` 后访问（之后重启） | 均不成功（业务码不为 200），零业务副作用 |
| A26 | admin 令牌先打 outapi/kefuapi/api 被拒，再打 adminapi；out 令牌先打 adminapi 被拒，再打 outapi | 第二次请求成功（错误应用的失败没有删除原会话） |
| A27 | 用错误密钥签发、未验签载荷 id = `admin_super` 的令牌，`POST /adminapi/product/product/0`，请求体含 `store_name = 'AUDIT-A27-<RUN_ID>'`；再用"签名正确但已过期"的令牌重复一次 | 业务码 401；`eb_cache` 中 `key = '101_product_data'` 的行数和内容不变；`eb_store_product` 不变 |
| A10 | 以 `APP_KEY` 为空、`APP_KEY = default`、缓存驱动为 `file` 三种配置分别重启应用；尝试 admin 登录、admin 探针、out 登录 | 业务码 503；不签发令牌；不进入业务控制器 |
| A28 | admin 登录→探针→退出→旧令牌 401；out 登录→探针→刷新→新令牌可用、旧令牌 401；客服登录→探针；顾客登录→探针 | 全部按描述成功或拒绝 |

A02–A06、A08、A30 的用例在 Task 7 中追加（需要先完成路由盘点）。

- [ ] **Step 3: 在基线上运行**

```bash
git status --short          # 期望只有 ms0a-auth.php 与 token-forge.php 两个新文件
AUDIT_PHASE=baseline php help/audit-regressions/isolated/ms0a-auth.php; echo "exit=$?"
```

Expected: 进程正常结束（`exit` 为 0 或 1，不能是 2 或崩溃）；基线结果写入运行目录。根据 01 文档推测，A12、A15、A16、A19、A27 和 A09 的部分组合在基线上应为 FAIL。**按实际结果记录**：没有复现的风险写明"基线未复现"及原因，不得调整夹具或代码去制造漏洞。

- [ ] **Step 4: 提交测试**

```bash
git add help/audit-regressions/isolated/ms0a-auth.php help/audit-regressions/isolated/lib/token-forge.php
git commit -m "test: MS0a auth regression cases (baseline recorded, fixes pending)"
```

### Task 3: 认证配置检查与无状态验签

**Files:**
- Create: `crmeb/crmeb/services/AuthConfigGuard.php`、`crmeb/crmeb/services/SessionWhitelist.php`
- Modify: `crmeb/crmeb/utils/JwtAuth.php`

**Interfaces:**
- Produces:
  - `crmeb\services\AuthConfigGuard::signingKey(): string`：返回 `Env::get('app.app_key')`；为空或等于 `default` 时抛出配置错误（503）。
  - `crmeb\services\AuthConfigGuard::assertRedisSessionStore(): void`：`config('cache.default')` 对应的 `config('cache.stores.<name>.type')` 不是 `redis`（不区分大小写）时抛出配置错误（503）。
  - `crmeb\services\SessionWhitelist::read(string $token): mixed`：返回 `CacheService::get(md5($token))`。
  - `crmeb\services\SessionWhitelist::matches(mixed $record, string $type, int|string $id, string $token): bool`：`$record` 是数组，`type` 严格相等，`(string)uid === (string)$id`，`token` 用 `hash_equals` 比较。
  - `crmeb\services\SessionWhitelist::forget(string $token): void`：删除 `md5($token)` 记录。
  - `crmeb\utils\JwtAuth::decodeVerified(string $jwt): array`：设置 `JWT::$leeway = 60`，调用 `JWT::decode($jwt, AuthConfigGuard::signingKey(), ['HS256'])`；返回 `['id' => jti.id, 'type' => (string) jti.type, 'pwd' => (string)($payload->pwd ?? '')]`；`jti.id` 或 `jti.type` 缺失时抛出 `\UnexpectedValueException`。**不读写** `$this->token`。
  - `JwtAuth::getToken()`、`JwtAuth::verifyToken()` 改用 `AuthConfigGuard::signingKey()`。`parseToken()` 保留不动，供尚未迁移的调用方使用。

- [ ] **Step 1: 运行与本任务相关的用例，确认当前失败**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A10,A20`
Expected: A10 的子场景 FAIL（基线会以 `default` 密钥或文件缓存继续工作）。

- [ ] **Step 2: 实现上述三个类的改动**

- [ ] **Step 3: 语法检查并重跑**

Run: `php -l crmeb/crmeb/services/AuthConfigGuard.php && php -l crmeb/crmeb/services/SessionWhitelist.php && php -l crmeb/crmeb/utils/JwtAuth.php && AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A10`
Expected: A10 中"`APP_KEY` 为空"和"`APP_KEY = default`"下的**登录**子场景 PASS（不签发令牌，业务码 503）。管理端探针子场景此时仍会被旧代码的 `catch (\Throwable)` 转成 401，要等 Task 4 改用 `decodeVerified()` 后才返回 503；缓存驱动子场景要等 Task 4、Task 6 接入检查。

- [ ] **Step 4: 提交**

```bash
git add crmeb/crmeb/services/AuthConfigGuard.php crmeb/crmeb/services/SessionWhitelist.php crmeb/crmeb/utils/JwtAuth.php
git commit -m "fix(auth): refuse missing/default signing key; add stateless verified JWT decode"
```

### Task 4: 管理端令牌验证

**Files:**
- Modify: `crmeb/app/services/system/admin/AdminAuthServices.php`（`parseToken()`；删除 `authFailAfter()`、`saveProduct()`）
- Modify: `crmeb/app/services/system/admin/SystemAdminServices.php`（`login()` 中签发前调用 `AuthConfigGuard::assertRedisSessionStore()`）

**Interfaces:**
- Consumes: Task 3 的 `AuthConfigGuard`、`SessionWhitelist`、`JwtAuth::decodeVerified()`
- Produces: `AdminAuthServices::parseToken(string $token, string $msg = '登录已过期,请重新登录'): array`，签名与返回结构不变（返回隐藏 `pwd`、`is_del`、`status` 后的管理员数组，并带 `type`）

- [ ] **Step 1: 确认相关用例在当前代码上失败**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A12,A15,A16,A17,A18,A27`
Expected: 与基线一致的 FAIL 子场景。

- [ ] **Step 2: 按以下顺序重写 `parseToken()`**

1. 令牌为空或为 `undefined` → 401。
2. `AuthConfigGuard::assertRedisSessionStore()`。
3. `decodeVerified()`；任何异常 → 401。不删除任何缓存。
4. `type !== 'admin'` → 401。不删除任何缓存。
5. `SessionWhitelist::matches(SessionWhitelist::read($token), 'admin', $id, $token)` 不成立 → 401。不删除任何缓存。
6. `pwd` 为空字符串 → 401。
7. 取管理员；不存在、`is_del != 0` 或 `status != 1` → 非 CLI 时 `SessionWhitelist::forget($token)`，然后 401。
8. `hash_equals(md5($admin->pwd), $pwd)` 不成立 → 非 CLI 时 `forget`，然后 401。
9. 返回值与原实现一致。

删除 `authFailAfter()`、`saveProduct()` 以及因此不再使用的 `use` 语句。正常的草稿保存仍在 `StoreProduct` 控制器中，不受影响。

- [ ] **Step 3: 重跑**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A01,A11,A12,A15,A16,A17,A18,A21,A23,A27,A10`
Expected: 全部 PASS（A10 中只有"out 登录"子场景要等到 Task 6）。A26 涉及客服与顾客端，到 Task 5 才能通过。

- [ ] **Step 4: 提交**

```bash
git add crmeb/app/services/system/admin/AdminAuthServices.php crmeb/app/services/system/admin/SystemAdminServices.php
git commit -m "fix(adminapi): verify JWT before use, require admin type, whitelist match, pwd and live account state; drop auth-failure draft write"
```

### Task 5: 客服端与顾客端的 type 检查

**Files:**
- Modify: `crmeb/app/services/kefu/LoginServices.php`（`parseToken()`）
- Modify: `crmeb/app/services/user/UserAuthServices.php`（`parseToken()`）

**Interfaces:**
- Consumes: Task 3
- Produces: 两个 `parseToken()` 的签名与返回结构不变

- [ ] **Step 1: 盘点签发点**

Run: `grep -rnE "createToken\(" crmeb/app crmeb/crmeb | grep -v "function createToken"`
Expected: 顾客端签发全部为 `'api'`，客服端为 `'kefu'`（与 F-26 一致）。出现其他 type 时**停止**并回报。

- [ ] **Step 2: 确认 A09 中相关组合当前失败**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A09`
Expected: admin/out 令牌访问 kefuapi、api 探针的组合 FAIL。

- [ ] **Step 3: 实现**

两个方法都改为：`decodeVerified()` → type 分别必须为 `kefu`、`api` → `SessionWhitelist::matches()` → 原有的账号查询与返回逻辑。错误 type 或白名单不匹配时直接拒绝，不删除缓存。

- [ ] **Step 4: 重跑**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A09,A13,A14,A26,A28`
Expected: A09 中除 out 相关组合外全部 PASS（out 在 Task 6）；A13、A14、A26 PASS；A28 中客服与顾客子场景 PASS。

- [ ] **Step 5: 提交**

```bash
git add crmeb/app/services/kefu/LoginServices.php crmeb/app/services/user/UserAuthServices.php
git commit -m "fix(kefuapi,api): accept only own token type and matching whitelist record"
```

### Task 6: 对外账号的状态统一与令牌验证

**Files:**
- Modify: `crmeb/app/services/out/OutAccountServices.php`（`authLogin()`、`verifyToken()`、`checkAuth()`、`refresh()`；新增 `isEnabled()`）
- Modify: `crmeb/app/outapi/controller/Mcp.php`（状态判断）
- Modify: `crmeb/app/adminapi/controller/v1/setting/SystemOutAccount.php`（`set_status()` 参数校验）
- Create: `docs/multi-merchant/records/ms0a/external-integrations.md`

**Interfaces:**
- Consumes: Task 3
- Produces: `app\services\out\OutAccountServices::isEnabled(array|\think\Model|null $account): bool`，当且仅当 `(int)status === 1 && (int)is_del === 0` 时返回 `true`

- [ ] **Step 1: 确认相关用例当前失败**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A19,A09,A10`
Expected: A19 中"关闭后继续访问 / 刷新""`set_status` 传 2"子场景 FAIL。

- [ ] **Step 2: 实现**

- `authLogin()`：先调用 `assertRedisSessionStore()`；用 `!self::isEnabled()` 替换 `status == 0` 判断。
- `verifyToken()`：先 `assertRedisSessionStore()`，再 `decodeVerified()`，`type !== 'out'` 拒绝，`SessionWhitelist::matches(..., 'out', ...)` 不成立拒绝；不再使用未验签的 `parseToken()`。错误 type 时不删除缓存。
- `checkAuth()`：用 `!self::isEnabled()` 替换 `status == 2`；保持原有"非 CLI 时删除本应用会话"的行为。
- `refresh()`：续签时 type 写死为 `'out'`，不使用令牌自报的 type。
- `Mcp.php`：用 `!OutAccountServices::isEnabled($accountInfo)` 替换 `status == 0 || status == 2`。
- `SystemOutAccount::set_status()`：`$status` 不是 `'0'` 或 `'1'` 时返回 `参数错误`，不写库。

- [ ] **Step 3: 写调用方影响说明**

在 `records/ms0a/external-integrations.md` 中写明（只写代码侧事实，不写"已通知"）：outapi JWT 入口清单；MCP 使用请求头凭据、不受 JWT 变化影响；本次行为变化——已关闭（`status = 0`）的对外账号，其现存令牌与刷新将被拒绝；实际在用的调用方仍待核实（Z08）。

- [ ] **Step 4: 重跑**

Run: `AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A09,A10,A19,A26,A28`
Expected: 全部 PASS。

- [ ] **Step 5: 提交**

```bash
git add crmeb/app/services/out/OutAccountServices.php crmeb/app/outapi/controller/Mcp.php \
        crmeb/app/adminapi/controller/v1/setting/SystemOutAccount.php docs/multi-merchant/records/ms0a/external-integrations.md
git commit -m "fix(outapi): single enabled-state rule for login/access/refresh/MCP; verify JWT and whitelist before use"
```

### Task 7: 授权旁路与权限缓存

**Files:**
- Create: `help/audit-regressions/isolated/route-inventory.php`、`docs/multi-merchant/records/ms0a/route-permission-matrix.md`、`crmeb/app/adminapi/config/auth_routes.php`
- Modify: `crmeb/app/services/system/admin/SystemRoleServices.php`（`verifyAuth()`）
- Modify: `crmeb/app/adminapi/controller/v1/setting/SystemMenus.php`（写操作成功后清缓存）
- Modify: `help/audit-regressions/security.php`（按批准的契约收紧 3 项旁路断言并追加 UNIT-STUB 用例）、`help/audit-regressions/isolated/ms0a-auth.php`（追加 A02–A06、A08、A30）

**Interfaces:**
- Produces: 配置 `config('auth_routes.authenticated')`，值为 `string[]`，每项格式为小写的 `"<method> <rule>"`，例如 `"get menuslist"`；`verifyAuth()` 签名不变

- [ ] **Step 1: 路由盘点**

`route-inventory.php` 在隔离环境中列出 adminapi 的全部路由（方法、规则、是否挂了 `AdminAuthTokenMiddleware` 与 `AdminCheckRoleMiddleware`），与 `eb_system_menus`（`auth_type = 2`）的 `api_url`、`methods` 对比，输出到 `route-permission-matrix.md`。表头：`方法 | 规则 | 有认证中间件 | 有授权中间件 | 已登记 | 建议策略（已登记授权 / 白名单 / 拒绝） | 理由`。`crud/` 生成路由单列一节。

- [ ] **Step 2: 停止点 SP-MS0a-1**

把"建议进入白名单"的路由（至少包含现有的 `setting/admin/logout`、`menuslist`）和"将被拒绝的未登记路由"清单，按 12 文档 §7.3 回传，**等待用户确认后再继续**。未确认前不得修改 `verifyAuth()`。

- [ ] **Step 3: 写测试**

`security.php` 先将原有三项旁路放行用例（`unregistered route proceeds`、`unregistered method proceeds without warning`、`existing CRUD exemption proceeds`）改为期望拒绝并相应改名，保持其他 20 项不变。新契约子场景不得把测试路由加入白名单来保留原放行结果。再追加 UNIT-STUB 用例：未登记且不在白名单 → 抛 `AuthException`；在白名单 → 放行；`crud/` 前缀未授权 → 抛异常；已登记已授权 → 放行。

`ms0a-auth.php` 追加 INTEGRATION 用例：

| 用例 | 子场景 | 期望 |
|---|---|---|
| A02 | `admin_limited` 调用已登记但未授权的写接口 | 被拒绝，业务表不变 |
| A03 | `admin_limited` 调用确认清单中"将被拒绝"的未登记路由 | 被拒绝 |
| A04 | `admin_limited` 调用 `crud/` 路由；若 `route/crud/` 为空，在测试 worktree 放一个首行带 `AUDIT-RUN` 哨兵的临时探针路由文件，指向已有的只读方法，teardown 删除 | 被拒绝 |
| A05 | `admin_limited` 调用已授权接口；`admin_super` 调用任意受保护接口 | 成功 |
| A06 | `admin_limited` 调用登录、退出、本人菜单、`login/info` | 按确认的策略成功 |
| A08 | `admin_super` 通过角色接口撤销 `R-limited` 的授权后，`admin_limited` 的下一次请求 | 被拒绝 |
| A30 | `admin_super` 通过菜单接口删除授予 `admin_limited` 访问权的那条接口登记 | `admin_limited` 的下一次请求被拒绝 |

- [ ] **Step 4: 确认新用例当前失败**

Run: `php help/audit-regressions/security.php; AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A03,A04,A30`
Expected: 三项收紧后的 UNIT-STUB 用例、新增的拒绝用例和 A03、A04、A30 FAIL；其余 20 个原有 UNIT-STUB 用例 PASS。基线原始 23 项结果保留在 AUDIT_BASE_SHA 的记录中，不覆盖历史结果。

- [ ] **Step 5: 实现**

- `auth_routes.php` 返回 `['authenticated' => [...用户确认的清单...]]`。先确认 ThinkPHP 多应用模式会加载 `app/adminapi/config/` 下的配置文件；如果不会，改放到 `crmeb/config/auth_routes.php` 并在报告中说明。
- `verifyAuth()`：`"$method $rule"` 在白名单中 → 放行；已登记且角色已授权 → 放行；其余一律抛 `AuthException('您暂时没有访问权限')`。删除"未登记放行"和 `crud/` 前缀放行两个分支。保留审计 A1 已有的严格比较。
- `SystemMenus` 控制器：新增、修改、删除、显示开关这几个写操作成功后调用 `CacheService::clear()`，与 `SystemRole` 控制器一致。

- [ ] **Step 6: 重跑**

Run: `php help/audit-regressions/security.php && AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php --only=A02,A03,A04,A05,A06,A08,A30`
Expected: 全部 PASS。

- [ ] **Step 7: 提交**

```bash
git add crmeb/app/adminapi/config/auth_routes.php crmeb/app/services/system/admin/SystemRoleServices.php \
        crmeb/app/adminapi/controller/v1/setting/SystemMenus.php help/audit-regressions/security.php \
        help/audit-regressions/isolated/ms0a-auth.php help/audit-regressions/isolated/route-inventory.php \
        docs/multi-merchant/records/ms0a/route-permission-matrix.md
git commit -m "fix(adminapi): deny unregistered and crud routes unless allowlisted; clear auth cache on menu changes"
```

### Task 8: 全量回归与交付

**Files:**
- Create: `docs/multi-merchant/records/ms0a/report.md`、`docs/multi-merchant/records/ms0a/results.jsonl`

- [ ] **Step 1: 在干净环境全量运行**

```bash
help/audit-regressions/isolated/teardown.sh <旧 RUN_ID>
help/audit-regressions/isolated/bootstrap.sh
php help/audit-regressions/security.php; echo "unit exit=$?"
php help/audit-regressions/isolated/selftest.php; echo "self exit=$?"
AUDIT_PHASE=after php help/audit-regressions/isolated/ms0a-auth.php; echo "ms0a exit=$?"
```

Expected: 三个 `exit=0`。

- [ ] **Step 2: 语法与范围检查**

Run: `git diff --name-only <AUDIT_BASE_SHA> HEAD`
Expected: 只包含本计划各任务 **Files** 中列出的路径。多出的路径要么撤回，要么在报告中逐项说明理由。

- [ ] **Step 3: 写报告**

按 [records/README.md](records/README.md) 和 12 文档 §7.2 编写：每个 MS0a 用例的 baseline 与 after 结果、`finding_kind`、零副作用证据、未运行项及原因；Z04 要求的分层统计。

- [ ] **Step 4: 提交并停止**

```bash
git add docs/multi-merchant/records/ms0a
git commit -m "docs(records): MS0a report"
```

停止，等待验收（11 文档 §5.2）。验收通过后，该提交即为 `SECURITY_BASE_SHA`。
