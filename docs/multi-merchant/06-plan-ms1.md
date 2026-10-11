# MS1：商家身份与上下文 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。开工前提：MS0b 已验收（merchantapi 直接使用 v2 会话）。需要授权级别 L2。本里程碑有数据库迁移，只在隔离环境演练；真实库执行需要 L4。

**Goal:** 平台可以建立、审核、启停商家并为其创建员工；员工通过独立的 merchantapi 登录；服务端由已验证身份生成请求/任务局部的 `MerchantContext`；商家身份无法进入平台、对外、客服接口。商品接口不在本里程碑开放。

**Architecture:** 新增 `marketplace` 业务模块（model/dao/services）与 `merchantapi` 应用。商家员工的会话复用 MS0b 的 `AuthTokenV2`（app = `merchantapi`，type = `merchant`）。`MerchantContext` 为只读值对象，由中间件创建并只挂在当前 `Request` 实例上；服务方法显式接收它。三种查询范围类在本里程碑建好并测试，MS2 起用于商品。

**Tech Stack:** PHP 8.2（`readonly` 类）、ThinkPHP 6 多应用、think-queue（以 Task 1 盘点结果为准）、MySQL 8.0、Redis。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §3、§4.1、§5、§7（商家状态）、§10；用例 M01–M10、A07、K01、D20。

## Global Constraints

- 起点：`SECURITY_BASE_SHA` 加上已合入的 MS0b。分支 `feature/multi-merchant-v0.1`（MS1 起所有多商家功能在此分支上，以里程碑为单位合入），工作目录 `/Volumes/Kingston/cdx/projects/tianli-mall--mm`。
- 表名（实际前缀以环境为准，下文用 `eb_`）：`eb_marketplace_merchant`、`eb_marketplace_employee`、`eb_marketplace_role`、`eb_marketplace_audit_log`、`eb_marketplace_password_setup`。
- 所有新表的 `mer_id` 列为 `int unsigned NOT NULL`，**没有默认值**。
- 商家状态拆成四个独立字段：`review_status`（0 待审、1 通过、2 驳回）、`accept_orders`（0/1）、`fulfillment_enabled`（0/1）、`security_locked`（0/1）；外加 `closed_at`（可空）。不用一个字段表达多种含义。
- 新接口返回真实 HTTP 状态码：未认证 401、无动作权限 403、跨商家对象 404。响应体格式沿用 `{status, msg, data}`。
- 员工初始密码采用一次性设置链接（Q8 默认值）：平台创建员工时生成 32 字节随机令牌，只存 SHA-256，有效期 24 小时，只能使用一次。不生成、不发送明文口令。
- 迁移脚本放在 `help/migrations/`，沿用 `theme-module.php` 的 `check` / `apply` 模式：幂等、遇到不兼容的已有结构就拒绝、只新增空表和菜单登记。

## Review Focus

- **上下文残留**：在同一个常驻进程中，A 商家的任务抛异常后执行 B 商家的任务，B 读到的 `merId` 必须是 B（M05）。用真实 worker 测，不能用两个独立的 PHP 进程代替。
- **前端选择器**：请求体、Header、URL 中出现 `mer_id` 或 `merchant_id` 时，merchantapi 一律忽略或拒绝，不能影响 `MerchantContext`（M03）。
- **员工提权**：运营员工不能创建员工、修改角色、修改自己的角色（M02）。
- **门店命名混淆**：新菜单与权限标记一律以 `marketplace` 开头；旧 `merchant/` 门店路由的权限不能授予新商家管理能力（D20）。
- **旧客服入口**：商家令牌访问 kefuapi 的 HTTP 和 WebSocket 入口都要被拒绝（K01）。

---

### Task 1: 常驻进程盘点与 worker 测试能力

**Files:**
- Create: `docs/multi-merchant/records/ms1/runtime-process-inventory.md`
- Modify: `help/audit-regressions/isolated/compose.yml`（增加与生产相同方式启动的队列 worker 服务 `audit-worker`）

- [ ] **Step 1:** 从 `docker/`、`help/docker/`、Supervisor 配置、`crmeb/config/queue.php`、`crmeb/crmeb/services/workerman/` 列出声明的常驻进程（FPM、队列、Workerman 聊天、定时任务）：启动命令、容器、是否复用容器单例。表分两栏："配置中声明"与"实际观察到"（有运行环境权限时填 PID、镜像；没有就写"未观察"）。
- [ ] **Step 2:** 在 ISO-FULL 中启动 `audit-worker`，用一个空任务验证同一个 PID 连续处理多个任务（WORKER 层的前提）。
- [ ] **Step 3:** 提交 `docs(records): MS1 runtime process inventory; worker in isolated env`。

### Task 2: 数据库迁移

**Files:**
- Create: `help/migrations/marketplace-ms1.php`、`help/migrations/marketplace-ms1.md`
- Create: `help/audit-regressions/isolated/ms1-migration.php`

**Interfaces:**
- Produces: 五张表（§Global Constraints）；`eb_system_menus` 中新增平台菜单与接口登记（`auth_type = 2`），权限标记以 `marketplace-` 开头；命令 `php help/migrations/marketplace-ms1.php check|apply`

- [ ] **Step 1: 写用例**：`check` 在空库上报告"可应用"；`apply` 两次，第二次报告"已完成"且不重复插入菜单；预先放一张同名但列不同的表时 `apply` 拒绝且不改动；`apply` 前后原有表的 `SHOW CREATE TABLE` 与 `CHECKSUM TABLE` 不变。
- [ ] **Step 2–4:** 确认失败 → 实现 → 通过。
- [ ] **Step 5:** 提交 `feat(migration): marketplace identity tables (MS1)`。

### Task 3: 权限目录、MerchantContext 与查询范围

**Files:**
- Create: `crmeb/app/services/marketplace/MerchantPermissions.php`
- Create: `crmeb/app/services/marketplace/context/MerchantContext.php`
- Create: `crmeb/app/dao/marketplace/scope/MerchantScope.php`、`PlatformScope.php`、`PublicCatalogScope.php`
- Create: `help/audit-regressions/isolated/ms1-context.php`

**Interfaces:**
- Produces:

```php
// app\services\marketplace\MerchantPermissions
public const ALL = ['merchant.profile.read', 'merchant.employee.manage', 'merchant.role.manage',
                    'product.read', 'product.write', 'product.submit_review', 'stock.adjust',
                    'attachment.manage', 'freight.manage'];
public const BUILTIN_ADMIN = self::ALL;
public const BUILTIN_OPERATOR = ['merchant.profile.read', 'product.read', 'product.write',
                                 'product.submit_review', 'stock.adjust', 'attachment.manage', 'freight.manage'];

// app\services\marketplace\context\MerchantContext
final readonly class MerchantContext {
    public function __construct(public int $merId, public int $employeeId, public array $permissions,
                                public string $sessionVersion, public string $requestId) {}
    // 构造时：merId <= 0 或 employeeId <= 0 抛 \InvalidArgumentException
    public function can(string $permission): bool;
    public function require(string $permission): void;   // 不具备时抛 MerchantForbiddenException（HTTP 403）
}

// app\dao\marketplace\scope\MerchantScope
public static function apply(\think\db\Query|\think\Model $query, MerchantContext $ctx, string $column = 'mer_id'): \think\db\Query
public static function assertOwned(array|\think\Model|null $row, MerchantContext $ctx, string $column = 'mer_id'): void
        // 行不存在或 mer_id 不等于 $ctx->merId 时抛 MerchantNotFoundException（HTTP 404）
// app\dao\marketplace\scope\PlatformScope
public function __construct(int $adminId, string $permission, ?int $targetMerId)   // 由调用方在通过平台授权后构造
// app\dao\marketplace\scope\PublicCatalogScope
public static function fields(string $resource): array                                // 公开字段白名单
```

- [ ] **Step 1: 写用例**（M04、M08 的 UNIT 部分）：`merId` 为 0、负数、null（类型错误）时构造失败；`MerchantScope::apply` 生成的 SQL 含 `mer_id = <merId>`；`assertOwned` 对他店行抛 404 异常；没有 `MerchantContext` 时无法调用需要它的服务方法（类型约束）。
- [ ] **Step 2–4:** 确认失败 → 实现 → 通过。
- [ ] **Step 5:** 提交 `feat(marketplace): permission catalog, MerchantContext and query scopes`。

### Task 4: merchantapi 应用与员工认证

**Files:**
- Create: `crmeb/app/merchantapi/`：`route/route.php`、`middleware/MerchantAuthTokenMiddleware.php`、`middleware/MerchantPermissionMiddleware.php`、`controller/Login.php`、`controller/Me.php`、`MerchantApiExceptionHandle.php`、`provider.php`、`event.php`、`config/`（按 adminapi 的结构）
- Create: `crmeb/app/model/marketplace/`、`crmeb/app/dao/marketplace/` 下对应的 Merchant、Employee、Role、PasswordSetup 模型与 DAO
- Create: `crmeb/app/services/marketplace/EmployeeAuthServices.php`
- Modify: `crmeb/config/auth.php`（加入 `apps.merchantapi`：type `merchant`，audience `tianli:merchantapi`）
- Create: `help/audit-regressions/isolated/ms1-merchantapi.php`

**Interfaces:**
- Produces: 路由 `POST /merchantapi/login`、`POST /merchantapi/logout`、`POST /merchantapi/refresh`、`GET /merchantapi/me`、`POST /merchantapi/password/setup`；`EmployeeAuthServices::login(string $account, string $password): array`；中间件成功后 `$request->merchantContext()` 返回 `MerchantContext`。

员工能登录的条件：员工 `status = 1` 且 `is_del = 0`；所属商家 `review_status = 1` 且 `security_locked = 0`；所属商家未关闭。`accept_orders` 与 `fulfillment_enabled` 不影响登录，只影响 MS4 的具体动作。

- [ ] **Step 1: 写用例**：M03（请求体、Header、URL 中伪造 `mer_id`/`merchant_id`，`/me` 返回的商家仍是员工所属商家）；M04（绕过中间件直接调用服务时因缺少上下文而失败）；M06（`security_locked = 1` 后员工下一次请求 401；`accept_orders = 0` 时仍可登录）；M08（员工所属商家 `review_status != 1` 时不能登录）；一次性设置链接：过期、重复使用、令牌错误都失败。
- [ ] **Step 2–4:** 确认失败 → 实现 → 通过。
- [ ] **Step 5:** 提交 `feat(merchantapi): employee authentication with v2 sessions and MerchantContext`。

### Task 5: 商家内的员工与角色管理

**Files:**
- Create: `crmeb/app/merchantapi/controller/Employee.php`、`Role.php`；`crmeb/app/services/marketplace/EmployeeServices.php`、`RoleServices.php`

**Interfaces:**
- Produces: `GET|POST /merchantapi/employees`、`PUT /merchantapi/employees/:id`、`PUT /merchantapi/employees/:id/status`、`GET|POST /merchantapi/roles`、`PUT /merchantapi/roles/:id`；全部要求 `merchant.employee.manage` 或 `merchant.role.manage`；角色的权限只能取 `MerchantPermissions::ALL` 的子集。

- [ ] **Step 1: 写用例** M02：运营员工创建员工、修改角色、修改自己的角色、修改员工所属商家 → 403 且数据库不变；商家管理员操作他店员工 ID → 404；角色中写入不存在的权限码 → 422 且不保存。员工被停用或角色被修改后，其下一次请求按新状态处理。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(merchantapi): scoped employee and role management`。

### Task 6: 平台商家管理

**Files:**
- Create: `crmeb/app/adminapi/route/marketplace.php`、`crmeb/app/adminapi/controller/v1/marketplace/Merchant.php`、`crmeb/app/services/marketplace/MerchantAdminServices.php`、`crmeb/app/services/marketplace/AuditLogServices.php`

**Interfaces:**
- Produces: `GET|POST /adminapi/marketplace/merchants`、`GET|PUT /adminapi/marketplace/merchants/:id`、`PUT /adminapi/marketplace/merchants/:id/review`、`PUT /adminapi/marketplace/merchants/:id/flags`（`accept_orders`、`fulfillment_enabled`、`security_locked`）、`POST /adminapi/marketplace/merchants/:id/admin-employee`（创建商家管理员并返回一次性设置链接）；每个写操作都写 `eb_marketplace_audit_log`。
- `AuditLogServices::record(string $actorType, int $actorId, ?int $merId, string $action, string $resourceType, string $resourceId, string $result, array $detail = []): void`（`$detail` 中不得出现密码、令牌、设置链接）。

- [ ] **Step 1: 写用例**：M01（建立 A、B 商家并审核，分别创建管理员，归属正确）；M06（停止接新单与安全封禁两个开关效果不同；安全封禁后平台仍可查看该商家）；D20（只有旧 `merchant` 门店权限的受限管理员访问 `marketplace` 路由 → 拒绝）；审计日志字段完整，且不含敏感值。
- [ ] **Step 2–5:** 确认失败 → 实现（含菜单登记，见 Task 2）→ 通过 → 提交 `feat(adminapi): platform merchant management with audit log`。

### Task 7: 跨应用隔离

**Files:**
- Modify（视盘点结果）: `crmeb/crmeb/services/workerman/` 下客服长连接的鉴权入口
- Create: `help/audit-regressions/isolated/ms1-cross-app.php`

- [ ] **Step 1: 写用例**：A07（商家令牌访问平台配置、数据库备份、文件编辑、用户资料、CRUD 生成等 adminapi 接口 → 全部拒绝）；M07（商家令牌 × adminapi/outapi/kefuapi/api，以及 admin/out/kefu/api 令牌 × merchantapi，共 8 组 → 全部拒绝）；K01（商家令牌访问客服 HTTP 接口与 WebSocket 鉴权、房间订阅 → 全部拒绝；平台合法客服仍可用）。
- [ ] **Step 2–5:** 确认失败 → 修复（若 MS0a 的 type 检查已覆盖 HTTP 部分，只需补 WebSocket 入口）→ 通过 → 提交 `fix: reject merchant tokens outside merchantapi, including kefu websocket`。

### Task 8: 常驻进程中的上下文

**Files:**
- Create: `crmeb/app/jobs/marketplace/AuditLogJob.php`
- Create: `crmeb/app/services/marketplace/context/MerchantJobContext.php`
- Create: `help/audit-regressions/isolated/ms1-worker.php`

**Interfaces:**
- Produces: 任务载荷只包含 `employeeId`、`requestId`、动作、资源；消费者在每个任务开始时调用 `MerchantJobContext::rebuild(int $employeeId, string $requestId): MerchantContext`（从数据库重新取员工与商家，校验状态），在 `finally` 中丢弃；载荷中的 `mer_id` 字段（若有）只用于与重建结果比对，不一致时拒绝。

- [ ] **Step 1: 写用例**（WORKER 层，在 `audit-worker` 的同一个 PID 中）：M05（A→B、A 抛异常→B、A→无上下文的任务，审计日志中记录的 `mer_id` 与各自的员工一致，第三个任务因无上下文被拒绝）；M09（如果盘点确认 worker 能并发或交错处理，测交错；否则在报告中写明"该入口串行执行"，不伪造并发测试）；M10（载荷中的 `mer_id` 与员工实际商家不一致、员工已停用 → 拒绝，且下一个任务不受影响）。每条结果附 PID 与镜像标识。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(marketplace): job-scoped MerchantContext with worker regression`。

### Task 9: 回归与交付

- [ ] 在新的 ISO-FULL 环境运行全部 MS0a、MS0b 用例及 MS1 用例；全部 `exit=0`。
- [ ] 确认 merchantapi 没有任何商品相关路由（`php think route:list` 输出中不含 `product`）。
- [ ] 写 `records/ms1/report.md`、`results.jsonl`（含迁移演练的前后摘要）；提交；停止，等待验收（11 文档 §5.4）。
