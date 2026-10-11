# MS1：商家身份与上下文 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。开工前提：MS0b 已验收（merchantapi 直接使用 v2 会话）。需要授权级别 L2。本里程碑有数据库迁移，只在隔离环境演练；真实库执行需要 L4。

> **2026-10-12 本次执行依据：** 用户持续委托“按文档规划目标推进，遇到问题，按你认为最优的处理方式继续进行就行，不用问我”，并明确跳过安全复现、异常请求、故障、并发/断连及恢复等验证。本次按该委托继续正常L2开发；MS0b普通源码交付为 `fe9ab18f2555d2cbc9f21a1dcd1a61a9c704a39f`，四正常脚本30PASS/1NOT RUN、两下载BROWSER保存已补证，完整安全验收及人工签收未发生。原文前置要求保留为验收缺口，不宣称已经签收，不填写SECURITY_BASE_SHA；后续只运行正常流程，逐项保留原负向用例的NOT RUN边界。真实数据库、推送、发布、通知不在本次授权内。

**Goal:** 平台可以建立、审核、启停商家并为其创建员工；员工通过独立的 merchantapi 登录；服务端由已验证身份生成请求/任务局部的 `MerchantContext`；商家身份无法进入平台、对外、客服接口。商品接口不在本里程碑开放。

**Architecture:** 新增 `marketplace` 业务模块（model/dao/services）与 `merchantapi` 应用。商家员工的会话复用 MS0b 的 `AuthTokenV2`（app = `merchantapi`，type = `merchant`）。`MerchantContext` 为只读值对象，由中间件创建并只挂在当前 `Request` 实例上；服务方法显式接收它。三种查询范围类在本里程碑建好并测试，MS2 起用于商品。

**Tech Stack:** PHP 8.2（`readonly` 类）、ThinkPHP 6 多应用、think-queue（以 Task 1 盘点结果为准）、MySQL 8.0、Redis。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §3、§4.1、§5、§7（商家状态）、§10；用例 M01–M10、A07、K01、D20。

## Global Constraints

- 本次Windows工作目录：`H:/天俪商城/tianli-mall--mm`，分支 `feature/multi-merchant-v0.1`，实际起点 `fe9ab18f2555d2cbc9f21a1dcd1a61a9c704a39f`。原计划的签收后 `SECURITY_BASE_SHA` 起点未取得签收记录，不能虚构；本地源码继承MS0a/MS0b已实施边界。后续里程碑沿此功能分支继续，当前不合入稳定分支或推送。
- 表名（实际前缀以环境为准，下文用 `eb_`）：`eb_marketplace_merchant`、`eb_marketplace_employee`、`eb_marketplace_role`、`eb_marketplace_audit_log`、`eb_marketplace_password_setup`。
- 所有新表的 `mer_id` 列为 `int unsigned NOT NULL`，**没有默认值**。
- 商家状态拆成四个独立字段：`review_status`（0 待审、1 通过、2 驳回）、`accept_orders`（0/1）、`fulfillment_enabled`（0/1）、`security_locked`（0/1）；外加 `closed_at`（可空）。不用一个字段表达多种含义。
- 新接口返回真实 HTTP 状态码：未认证 401、无动作权限 403、跨商家对象 404。响应体格式沿用 `{status, msg, data}`。
- 员工初始密码采用一次性设置链接（Q8 默认值）：平台创建员工时生成 32 字节随机令牌，只存 SHA-256，有效期 24 小时，只能使用一次。不生成、不发送明文口令。
- 迁移脚本放在 `help/migrations/`，沿用 `theme-module.php` 的 `check` / `apply` 模式：幂等、遇到不兼容的已有结构就拒绝、只新增空表和菜单登记。

## 实源接线与本次设计决策

以下依据新分支起点的实际源码补齐，先于依赖它们的实现；不修改00/10/11/12规格或验收签收。

- `Request` 使用的Macroable注册表是static；商户上下文采用当前Request实例的私有属性和明确getter/setter，不注册捕获身份的macro。服务及任务仍显式传递不可变上下文。
- merchantapi的v2接线除config.auth外，必须同时扩展AuthConfigGuard、AuthTokenV2、AuthSessionStore、CredentialStamp和AccountChangeGuard的服务端应用枚举/主体映射；这是同一会话扩展的必要调用点。不得从请求输入选择app/type，也不替换其他应用的协议。
- 新平台marketplace路由必须取得真实HTTP状态；旧AdminApiExceptionHandle仍把部分认证错误包装HTTP200，不能直接据旧路由组假定新接口满足401/403/404。采用限定marketplace路径的处理接线，具体来源与映射在Task6实现前记录，旧API合同保持。
- 商家主体以 `mer_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` 为唯一经营身份；其他四新表各有自增id及无默认值的正整数mer_id。角色为商家内角色，不创建mer_id=0的全局内建角色。五表完整DDL、索引和列默认/可空规则在Task2的 `marketplace-ms1.md` 中冻结后才编码。
- 员工登录只接收account/password，因此account采用全局唯一的规范化值：去首尾空白、仅ASCII大小写折叠；非ASCII文本保持，数据库用区分大小写的utf8mb4排序规则保存该规范化值。员工绑定一个同商家的role_id；权限以已验证权限码JSON数组存储，每次请求从当前员工、角色与商家重建，不用令牌中的旧权限缓存。
- 初始员工未设置密码（pwd可空）；设置能力使用32随机字节的base64url无填充令牌，数据库只存SHA-256与24小时到期/消费记录。一次正常设置在事务内写密码哈希并消费该记录；只向本次获授权创建者返回merchantapi设置入口和能力，正文不入审计/日志。不能由已登录商户传入mer_id决定设置归属。
- `marketplace_audit_log.mer_id` 非空：本阶段有目标的商家写操作都在取得真实正mer_id后记日志；可空参数签名不等于允许落库null/0。平台全局只读列表的请求由既有平台审计链记录，不伪造新商家ID；若以后新增无目标写动作，须先补其审计合同，不能静默丢弃。
- 迁移的“空”指CRMEB已安装且五目标表不存在；零表库缺少system_menus，不能创建第六张表或调用Installer。theme-module实际使用 `--check/--apply`，新MS1 CLI明确支持文档要求的 `check|apply`（兼容同义选项）。只新增五表及精确marketplace菜单/接口登记；原表列/index/旧行保持，system_menus正常新增行与其AUTO_INCREMENT变化单列核对，不能要求新增后checksum仍相同，也不回拨计数器。其他原表结构/数据保持。
- 现Supervisor声明queue:listen，实版Listener每轮启动queue:work --once，job不复用同一PHP进程。Task1为新marketplace队列声明独立 `queue:work --queue=marketplace --sleep=1 --tries=1` 常驻模式，并用相同命令建立ISO audit-worker；原队列保持。生产声明默认不自动启动，实际发布另行处理。正常空任务的同一PID证据到位后，Task8再运行正常A→B上下文重建；异常/无上下文/伪造载荷/并发场景按用户要求跳过，不冒称M05/M09/M10完整通过。

执行顺序仍以Task1–9为单一清单；Task1源码盘点与普通worker能力已完成，真实T1W01为1PASS，原首RUN的1FAIL保留。Task2–9的迁移、接口和上下文仍待实施，普通成功用例与原跳过项分别记录。

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
- Modify（同一worker能力的必要接线）: `docker/php/supervisord.conf`（独立marketplace队列声明，autostart=false）；`help/audit-regressions/isolated/lib/guard.php`、`lib/harness.php`、`bootstrap.sh`（按实际需要将新增服务身份、私有配置和普通启动纳入现有manifest保护）
- Create（仅隔离检查）: `help/audit-regressions/isolated/ms1-worker-functional.php` 及必要的 `lib/` 普通空任务/启动适配；不得导入或运行停用套件。
- Modify/Create（实际PHP8队列启动的必要调用点）: `crmeb/app/AppService.php`、`crmeb/crmeb/compat/CarbonErrorCompatibility.php`；仅CLI queue:work/queue:listen 的旧Carbon E_DEPRECATED兼容；`crmeb/vendor/nesbot/carbon/src/Carbon/Traits/Creator.php` 两处原生日历错误状态的PHP8.2兼容、`Traits/Date.php` 与 `Traits/Modifiers.php` 五个原生签名转接，见下述实际失败与修复设计。

- [x] **Step 1:** 声明盘点见 `records/ms1/runtime-process-inventory.md`，列出FPM、队列、Workerman和Timer的源码命令/容器/对象边界；没有生产运行证据的项目均为“未观察”。原queue:listen不具备相同job-worker PID前提，新marketplace常驻模式及普通ISO核对方式见上述决策。
- [x] **Step 2:** ISO-FULL普通RUN `20261011202953d2f1c8`：同一真实worker连续处理两个空任务，PID=1/start_ticks=38334384且同一容器，实际native exit0/restart0；T1W01 PASS，原断言未改。见 `records/ms1/task1-worker-summary.json`，只证明后续任务的运行前提。
- [x] **Step 3:** 提交 `docs(records): MS1 runtime process inventory; worker in isolated env`；实现提交9a00c108/626b4399，兼容修复d71af53c/bcd04b12，普通记录保留原1FAIL并追加1PASS。完整MS1与人工验收尚未完成。

Task1普通运行契约（先于接线）：audit-worker沿既有x-php隔离设置，仅挂只读源/vendor/本RUN私有DML配置、RUN证据及专用worker-runtime；runner只读观察worker-runtime。bootstrap仍只启动原服务，普通检查先用真实Queue推送同一测试handler的两个自有空载荷，再由宿主显式启动worker并登记其真实ID/IP/image/卷身份。宿主在登记后原子写本RUN启动许可，薄CLI适配校验许可、manifest和自身容器身份后，在同一PHP进程加载原think入口及上述queue:work参数。ISO增加原Console标准 `--quiet` 仅抑制原Work状态行的明文任务ID；该启动适配不改queue组件、参数或Job语义，生产Carbon兼容回移见下文，不用PHP输出缓冲冒称能过滤直接fwrite。handler每次明确delete、记录序号/PID/start_ticks/任务ID哈希，第二个完成后只设置同一真实Worker.shouldQuit，由引擎正常exit0；不注入业务异常，不直接exit，不发HTTP断连或服务故障控制。

`ms1-worker-functional.php --prepare` 只准备、保存真实全表/Redis值hash及绝对到期点/runtime快照，不生成PASS；`--observe` 在正常worker终止后核对两个回执、原生容器exit0/restart0、同PID与start_ticks、三个实际队列键清空及非预期副作用，形成一个T1W01 WORKER结果。实际队列键为queues:marketplace及delayed/reserved，配置prefix不参与；worker与producer共享本RUN专用业务Cache前缀以只读取得think:queue:restart。只认领实际两个载荷及精确自有键，不忽略整表/前缀、不回拨计数。私有状态/原生日志留仓库外；原始结果和失败如实保留。既有guard不变；为取得真实image及挂载归属，仅在bootstrap资源收集与harness manifest登记增加Docker.Image和Mounts，并核对worker的runtime为本RUN专用可写volume且其身份已经登记，其他启动/权限/故障控制行为保持。实际普通结论与验收边界见下述记录。

首个实际RUN `20261011195406edef53`（源码626b4399）：bootstrap/prepare原生exit0，worker实际exit255/restart0，T1W01 FAIL/observe Bash exit1，未产生两个任务回执。真实日志定位queue.InteractsWithTime→旧Carbon加载；CarbonInterface的旧jsonSerialize声明在PHP8产生E_DEPRECATED，Think初始化器Error把它转为ErrorException，使接口继承期间致命退出。失败与runtime差异保留，不能按Docker wait命令exit0称worker通过。修复仅在AppService.boot且PHP8/CLI队列命令中安装针对实际vendor/nesbot/carbon/src路径、E_DEPRECATED类别的兼容handler；其他类别/路径原样委托原Think handler，E_ALL、类型/业务/认证异常保留，不全局降错误等级，也不只在测试入口隐藏错误。该handler不修改Carbon声明或依赖版本。

追踪正常日期的producer/consumer后发现第二处兼容边界：本地已批准PHP8.2镜像中的正常DateTime实例，其原生getLastErrors()返回false（普通探针exit0）；旧Carbon的Creator构造器和createFromFormat两处把返回值交给setLastErrors(array)。在这两个原生返回调用点仅将严格false规范为旧版无错误四字段数组（warning_count=0、warnings=[]、error_count=0、errors=[]），保留非false返回值与私有array类型约束；不泛化null/任意类型、不抑制TypeError。这是tracked vendor的最小兼容回移，不升级包、不修改composer/lock或依赖树。首RUN的失败日志已保存，专用4容器/1网络/11卷已按manifest清理、teardown原生exit0。两处修复完成后使用新RUN重新做同一两个正常空任务，原断言保持，安全/异常/故障场景不运行。

正常日期探针随后实际exit255：CarbonInterface::modify($modify)的宽参数与继承的PHP8 DateTime::modify(string)不兼容，属于编译致命错误，不能由弃用handler修复。源码核对实际tracked Carbon2.20.0，而既有lock记为2.73.0；锁版完整恢复还涉及新增PHP8.1依赖及多包，不在本次最小队列修复中展开。按锁版上游 `9228ce90e1035ff2f0db84b40ec2e023ed802075` 中的原生转接模式，在共享Modifiers/Date traits增加modify、setDate、setISODate、setTime、setTimestamp的五个宽参数wrapper并标注ReturnTypeWillChange；统一调用对应parent方法，setTimestamp保持旧版整秒语义，不回移新版本的分数时间功能。保持mutable/immutable共用、PHP7.4可解析、旧queue时钟和setTestNow语义，不改Queue时间计算或依赖树；错误、业务异常仍由原处理链负责。健康日期探针的初次失败保留为原生255，修复后只验证正常now/格式化日期和实际两个空任务。

上述普通探针在五个wrapper补齐后原生exit0，覆盖mutable/immutable正常日期及setter；native编译仍提示Date.php五处旧`${var}`插值弃用。仅将实际五处改为PHP7/8均支持的等价`{$var}`写法，保持字符串/动态方法名，不创建警告或异常探针。这个正常日期结果不代替真实队列WORKER结果。

完成后的独立普通RUN `20261011202953d2f1c8` 实际源码bcd04b12：bootstrap/prepare/observe/teardown原生均exit0；同一容器两个真实空任务PID=1/start_ticks=38334384，实际worker exit0/restart0、正常引擎quit，三个队列已先清空，158表/Redis值与到期点/三个runtime快照均无差异，cleanup无需删除队列键。使用原626b4399三辅助文件及断言，SHA256未改变。原始成功行603字节追加至原失败行，records/ms1/results.jsonl共2行（1PASS/1FAIL），原失败SHA保持e1645cd28e1d1b6d8e0547dc6095119176057e516087c3b2a6b7b0bf5613ddfb，全2行SHA为fd91033b1f4d2b4f00c28698e1536eefb6b828cae62af5868cc753f8dc7216e7。两个RUN的专用资源均已按manifest清理，各剩余容器/网络/卷为0，临时install.lock不存在。仅Task1基础能力通过；商户上下文、迁移和M05/M09/M10完整验收仍未完成，安全/异常/故障等停用项不运行。

### Task 2: 数据库迁移

**Files:**
- Create: `help/migrations/marketplace-ms1.php`、`help/migrations/marketplace-ms1.md`
- Create: `help/audit-regressions/isolated/ms1-migration.php`

2026-10-12编码前已冻结marketplace-ms1.md的五表/七登记合同；独立源码审查确认，仅修正草稿中不合法的自增列CHECK。保留非自增mer_id正数检查与同商家组合RESTRICT FK，自增ID由正常省略写入及服务/上下文正数校验保证，不增加trigger/其他表。CLI沿纯PDO默认check，兼容check|apply/--check|--apply与操作者私有--config-file；命名锁内重检后创建，遇部分结构不自动修复，DDL隐式提交如实报告。普通隔离脚本使用既有audit-bootstrap内的audit_migrator执行真实CLI四步（check、apply、check、apply），原audit_app隔离保护和权限不变；完整旧表结构/行hash、可用checksum、旧菜单逐ID内容保留，仅允许五张空表及七条本版接口行和自然菜单计数器变化。新身份/菜单留在本RUN最终随实例销毁，不删表、不回拨计数、不清理Redis。异构结构/错误输入/故障/并发/恢复项按用户指示NOT RUN；基础脚本缺失的正常开发基线可记真实FAIL，不能注入故障或重跑停用套件。

普通harness独立核对已补齐精确table类型/engine/collation/版本标记、从冻结DDL解析的全列与PK/unique/普通索引、10条真实ENFORCED谓词、同一FK约束名/列序/引用schema及七行全字段。CLI临时配置仅在bootstrap secret挂载中，记录本次创建后才可清理；不得放入共享RUN目录。既有audit-bootstrap并未把宿主AUDIT_PHASE自动注入，调用需用compose run显式 `-e AUDIT_PHASE=baseline|after`。首次源码尚缺失的普通运行实际记录为after/FAIL(native1)、NOT RUN1，158表/所有快照完全一致且CLI steps=0；保留这份真实记录，不重新标注为baseline。正式基线在入口引入前显式传递phase运行。

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
- Modify（请求局部上下文的必要调用点）: `crmeb/app/Request.php`；新增对应MerchantForbiddenException/MerchantNotFoundException等明确HTTP异常类，位置沿现有 `crmeb/crmeb/exceptions/` 约定。

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
- Modify（同一v2扩展的必要调用点）: `crmeb/crmeb/services/AuthConfigGuard.php`、`auth/AuthTokenV2.php`、`auth/AuthSessionStore.php`、`auth/CredentialStamp.php`、`auth/AccountChangeGuard.php`
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
