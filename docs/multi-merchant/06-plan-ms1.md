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

执行顺序仍以Task1–9为单一清单；Task1源码盘点与普通worker能力、Task2普通迁移、Task3正常上下文与查询范围、Task4商家五接口及正常身份流程、Task5员工/角色管理普通流程已完成，历史FAIL及原跳过项分别保留。Task6–9的平台管理接口、跨应用静态核对、任务上下文及最终交付仍待实施；普通结果不代替完整验收。

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

实际普通记录：源码缺失时同一RUN2026101120502249a619保留实际after及正式baseline两次FAIL/native1，各附T2M02 NOT RUN，158表完整快照保持且CLI steps=0。实现332326bd后的新RUN20261011210408f41e62首次check native0；apply确已创建五表及七登记，但完成复检native1，失败保留。只读定位MySQL8.0.45 CHECK_CLAUSE的两个既定字面量引号转义后，2992483e/19e94f3d仅解码实际元数据入口，DDL、谓词与断言未改；该实例只读复检completed不能替代新的四步结果。

最终新RUN20261011211731fed7ed、源码19e94f3d：真实check/apply/check/apply四命令原生均exit0，状态依次applicable/completed/completed/completed，changed依次false/true/false/false；T2M01 PASS、T2M02 NOT RUN，bootstrap及teardown亦native0。恰好五张空表、七行登记（实际ID3465–3471），46列、21索引列记录、10条ENFORCED CHECK及4个FK的6列记录独立核对通过；158原表结构/旧行、1096旧菜单行、157可用非菜单checksum及Redis值/绝对到期点保持。三个Task2 RUN的专用容器/网络/卷最终均为0，临时install.lock不存在。全部8条Task2原始结果逐字节追加至原2条Task1记录，总10行（2PASS/4FAIL/4NOT RUN）；首2行SHA保持，完整摘要及原始文件hash见 `records/ms1/task2-migration-summary.json`。只完成Task2普通迁移交付，未填安全基线或人工签收，未推送或执行真实库。

**Interfaces:**
- Produces: 五张表（§Global Constraints）；`eb_system_menus` 中新增平台菜单与接口登记（`auth_type = 2`），权限标记以 `marketplace-` 开头；命令 `php help/migrations/marketplace-ms1.php check|apply`

- [x] **Step 1: 普通用例**：已安装且五目标表不存在时check报告可应用，apply正常创建且重复apply不再写入；独立核对全列/索引/CHECK/FK、七登记和旧结构/行/缓存。菜单只允许七行新增与自然计数器前进，其余DDL/原ID逐行内容保持；不把新增后的菜单整体checksum要求为不变。
- [ ] **原异构结构验收（T2M02）**：预置同名但列不同的表后拒绝且不改动的原要求保留；按用户指示NOT RUN，不创建异构结构、错误输入或故障。不能据普通PASS宣称该项通过。
- [x] **Step 2–4（普通流程）:** 两次真实源码缺失FAIL → 实现 → 首次真实应用复检FAIL及最小元数据修复 → 新实例原四命令PASS；所有失败与NOT RUN保留。
- [x] **Step 5:** 本地提交332326bd `feat(migration): add MS1 identity tables and interface registrations`；必要复检修复2992483e/19e94f3d及普通记录另行提交。L2交付，未推送。

### Task 3: 权限目录、MerchantContext 与查询范围

**Files:**
- Create: `crmeb/app/services/marketplace/MerchantPermissions.php`
- Create: `crmeb/app/services/marketplace/context/MerchantContext.php`
- Create: `crmeb/app/dao/marketplace/scope/MerchantScope.php`、`PlatformScope.php`、`PublicCatalogScope.php`
- Create: `help/audit-regressions/isolated/ms1-context.php`
- Modify（请求局部上下文的必要调用点）: `crmeb/app/Request.php`；新增对应MerchantForbiddenException/MerchantNotFoundException等明确HTTP异常类，位置沿现有 `crmeb/crmeb/exceptions/` 约定。

Task3编码依据（2026-10-12）：沿上面的签名及00 §5，不开放商品HTTP接口，不改变旧全局DAO。`MerchantPermissions::validate(array): array`只接受9个目录码组成的字符串list，去重保留次序；Context保存其值副本，正merId/employeeId及非空版本/请求标识在构造时校验，`can/require`严格匹配。Task4的sessionVersion必须取自AuthTokenV2在verifyCurrent完成后的已验证version；当前verify只返回id/sid，Task4需在同一必要调用点显式返回version，不能再读取未经验证的原始载荷。requestId由服务器生成，Task8每任务重建；Task3普通值对象核对使用明确合成版本，不冒称会话认证已经完成。

Request新增私有nullable属性与 `setMerchantContext/merchantContext/clearMerchantContext`，不使用Macroable注册表或容器共享属性；缺失时抛固定401的MerchantUnauthenticatedException。Forbidden/NotFound/Unauthenticated三个异常继承实际think HttpException，固定真实状态403/404/401与无敏感上下文的消息；不调用getLang或保存previous。Task4的新handler及Task6限定marketplace的处理仍需把这些状态显式映射到 `{status,msg,data}`，旧AdminApiExceptionHandle尚不是新接口HTTP结论。

`MerchantScope::apply`将Model转换为其新db Query，Query输入先clone，必须使用返回的新Query，原筛选模板不携带当前上下文；实际Builder::buildWhere直接拼接顶层AND/OR，因此已有where先用Query副本在Closure内整体分组，保留原绑定，再AND可信正merId。column仅接受调用方固定的单列或alias.column标识符；assertOwned读取array或Model原始getData，接受真实整数或同值十进制字符串，不通过访问器推断归属。MerchantScope与PlatformScope均只接收不含union的单一查询（仍用于读/写）；实际Builder独立拼接union分支，输入已有union时明确拒绝，不静默删掉或漏筛其他分支，该拒绝场景只做源码核对、未运行。后续仓储先构造全部业务筛选，最后apply并执行；不得在apply后重写/追加顶层OR、where(Query)或union。受保护写入仍须在SQL WHERE中使用相同范围，不能以读前检查替代。

PlatformScope为readonly的授权结果值对象：adminId为正数，permission为非空服务端动作码，targetMerId必须显式给出（null为受审计全局，否则为正数）；构造器不把字符串当作鉴权成功。其apply同样先整体分组已有条件；有目标时AND mer_id，无目标返回已明确授权的全局Query。真实动作授权及全局审计在Task6调用点落实，不能因scope对象存在就略过鉴权。

PublicCatalogScope当前固定两个资源：`merchant`为 `[mer_id,name,logo,after_sale_contact,shipping_note]`；`product`为 `[id,mer_id,store_name,store_info,image,slider_image,price,ot_price,vip_price,unit_name,sales,stock,spec_type,is_postage,postage,is_vip,video_link]`，均核对实际迁移/安装表列。未知资源抛参数异常，不返回星号或后台投影；这些字段不包含cost、mer_use、review字段、内部备注或顾客数据。MS2接线时按其公众合同补齐必要独立子资源与响应组装，已发布/商家状态筛选仍在MS2服务中落实，不由字段投影冒称完成。

普通核对契约：新的ISO-FULL安装库先记源码缺失的自然FAIL（不得注入异常），实现后在bootstrap专属迁移账号准备既有五表；正式脚本只持audit_app DML配置，用真实DbManager/Query/Model和合成A/B商家、角色、员工核对正常Context、两个Request实例、同值Owned行、Query与Model入口及带OR的正常列表、显式平台目标/全局范围和两个公开投影。夹具只在新身份表写入，精确按本次真实ID逆序清理，不改旧产品或原账号，不回拨计数器；全表旧行/结构（仅三张夹具表的自然计数器变化单列记明）及Redis值/到期点保持。原M04/M08的非法ID、缺失上下文、他店对象拒绝和无权限动作等场景继续NOT RUN，不把普通结果计为原拒绝验收通过。

首个实现后的普通RUN2026101121344901117b记录native1：真实Model与同值Owned行已走到通过，生成的SQL正确为已有OR整体括号后AND `e.mer_id = '1'`，但最初harness正则只接受无引号的任意数字而失败；夹具已精确清理，163表原行/结构（仅三自然计数器）和缓存保持。实版Builder::parseWhereItem按限定字段名查未限定类型字典，未命中alias字段时用PDO::PARAM_STR，Fetch正常显示带引号数值。修正只在harness展示形式边界：分组后的最终AND必须精确匹配当前ctx正merId，接受该整数原文或同值成对SQL引号形式，锚定SQL末尾；实际column结果仍必须严格等于本商家的真实员工ID。比原任意数字匹配更严格，不改变生产范围、期望归属或断言结果；原FAIL保留，使用新RUN重跑普通流程。

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

- [x] **Step 1（普通用例）:** T3C01使用真实DbManager/Query/Model、合成A/B身份验证正常正整数Context、9/7权限目录、本人array/Model行、带绑定和OR分组的查询及原模板保持、两个Request的本地属性与正常清理、显式平台目标/全局范围、商家真实公开查询及商品SQL字段投影；不替代会话认证或商品HTTP行为。
- [ ] **原M04/M08拒绝验收:** 0/负数/null商家ID、缺少Context、他店行404、无权限动作等原要求保留，按用户指示NOT RUN。已有union输入的拒绝也只核对源码，未运行；未宣称这些场景通过。
- [x] **Step 2–4（普通流程）:** 原实现缺失基线FAIL → 实现 → 原展示格式假设导致正常after FAIL → 按实版ORM修正展示匹配且加强精确归属 → 新RUN T3C01 PASS；全部FAIL和NOT RUN保留。
- [x] **Step 5:** 本地d5f1c9cb `feat(marketplace): add permission catalog, context and query scopes`，普通脚本ea086ab5及SQL展示核对ad2ff12a；记录另行提交，未推送。

Task3普通交付：最终RUN20261011214404d81a84、源码ad2ff12a，T3C01 INTEGRATION PASS/native0；M04 UNIT-STUB NOT RUN、M08 INTEGRATION NOT RUN。bootstrap、一次正常迁移准备和teardown均native0。真实A/B Query分别返回员工1/2，已有绑定及OR整体分组后AND真实商家1/2，原模板保持；两个Request值及clear均为各自实例。只证明合成Context与范围基础，真实v2身份生成在Task4、平台鉴权/审计在Task6、商品发布可见性在MS2、worker上下文在Task8继续实施。

夹具只写三张新身份表，均已按真实ID/商家/账号或名称精确清理；163表原行/checksum/DDL（仅三张夹具表的自然AUTO_INCREMENT前进）和Redis值/绝对到期点保持。首RUN的基线FAIL和实现后FAIL均保留，两个主RUN及其初始化清理实例最终容器/网络/卷各为0，install.lock不存在。9条Task3原始结果逐字节追加至此前10条，当前总19行（3PASS/6FAIL/10NOT RUN），原10行SHA保持；摘要、原始文件hash及真实边界见 `records/ms1/task3-context-summary.json`。完整MS1与签收未发生，L2本地源码交付，无真实库写入、推送、发布或通知。

### Task 4: merchantapi 应用与员工认证

**Files:**
- Create: `crmeb/app/merchantapi/`：`route/route.php`、`middleware/MerchantAuthTokenMiddleware.php`、`middleware/MerchantPermissionMiddleware.php`、`controller/Login.php`、`controller/Me.php`、`MerchantApiExceptionHandle.php`、`provider.php`、`event.php`、`config/`（按 adminapi 的结构）
- Create: `crmeb/app/model/marketplace/`、`crmeb/app/dao/marketplace/` 下对应的 Merchant、Employee、Role、PasswordSetup 模型与 DAO
- Create: `crmeb/app/services/marketplace/EmployeeAuthServices.php`
- Modify: `crmeb/config/auth.php`（加入 `apps.merchantapi`：type `merchant`，audience `tianli:merchantapi`）
- Modify（同一v2扩展的必要调用点）: `crmeb/crmeb/services/AuthConfigGuard.php`、`auth/AuthTokenV2.php`、`auth/AuthSessionStore.php`、`auth/CredentialStamp.php`、`auth/AccountChangeGuard.php`
- Create: `help/audit-regressions/isolated/ms1-merchantapi.php`
- Modify（新登录入口的必要隐私调用点）: `crmeb/app/listener/http/HttpEndListener.php`；全局结束事件先按服务器已选应用名排除merchantapi正文日志，子应用空事件不能移除全局监听器。

**Interfaces:**
- Produces: 路由 `POST /merchantapi/login`、`POST /merchantapi/logout`、`POST /merchantapi/refresh`、`GET /merchantapi/me`、`POST /merchantapi/password/setup`；`EmployeeAuthServices::login(string $account, string $password): array`；中间件成功后 `$request->merchantContext()` 返回 `MerchantContext`。

员工能登录的条件：员工 `status = 1` 且 `is_del = 0`；所属商家 `review_status = 1` 且 `security_locked = 0`；所属商家未关闭。`accept_orders` 与 `fulfillment_enabled` 不影响登录，只影响 MS4 的具体动作。

Task4编码合同（2026-10-12）：沿既有v2 Redis协议扩展固定merchantapi枚举，type=merchant、audience=tianli:merchantapi、ttl=30天；旧admin/out绑定不变。`verify()`只在现有verifyCurrent成功后增加返回已验证version，原id/sid保留。商家身份由同一次受核对的员工/同商家角色/商家连接读取建立；状态及9项权限目录每请求重读，Ctx不从客户端商家选择、原始JWT或共享属性建立。服务器生成requestId；中间件在进入时清空当前Request，成功后设置，finally清空。本人资料需merchant.profile.read，并由服务再检查。

五接口采用JSON `{status,msg,data}`及真实HTTP状态：login请求account/password，成功data仅token/exp；refresh成功同样仅token/exp；logout与password/setup成功data为空数组；me成功data仅employee（id、mer_id、role_id、account、name、avatar、status）、role（id、mer_id、name、permissions）、merchant（mer_id、name、logo、after_sale_contact、shipping_note）。商家会话只来自标准 `Authorization: Bearer <token>` 请求头，固定前缀完整解析；不读取URL或请求体中的登录会话。刷新/退出先正常认证。login/setup读取未过滤的字符串，账号用ASCII trim及ASCII小写规范化，必须有效UTF-8且字符数不超过191；密码8–72字节且无NUL，不截断，使用password_hash/password_verify。参数类型/格式错误固定422，认证状态固定401，权限403、对象404、依赖问题503；新handler不回传驱动消息、参数或trace。子应用database配置沿基础默认连接，仅固定debug=false、charset=utf8mb4及break_reconnect=false，避免SQL正文记录和事务重连。

首次设置请求token/password：token沿既有冻结合同为32随机字节的无填充base64url（43字符），消费者核对32字节解码及规范重编码一致，数据库仅存原始SHA256 32字节，精确24小时。先按摘要读取真实员工ID，再交既有AccountChangeGuard持有同一默认Db的外层事务；回调锁住能力和员工、重检绑定/有效期/未消费/员工正常及空密码，两项影响行数各为1后共同提交。不伪造Ctx、不在guard外开启/自行提交事务、不预先初始化账号版本；设置可在商家审核前完成，但员工正常且商家未关闭，实际登录仍严格遵守上述商家条件。只有有效凭据登录通过后initAccount，重读同一密码及最新状态后签发。协议内的受控身份失败使用既有AuthSessionException，由新HTTP handler映射；不复制协议或Lua实现。

DAO为有限用途独立类，不继承旧BaseDao的全局写入口；身份读取不依赖客户端Ctx，普通资料查询必须消费MerchantScope返回Query。公共DTO/日志无密码、摘要、会话版本或设置能力。全局HttpEndListener按应用名在读取正文前排除merchantapi，避免将成功token或设密正文写入日志；其他应用现行为保留。Task6管理员设置链接返回同样需限定处理日志，留在Task6落实。

实际路由链补充：框架CaptchaService在全局启动时登记captcha规则，MultiApp未自动清空。merchantapi路由定义文件在登记五接口前使用实际Route::clear()限定当前应用规则，旧应用不调用它；普通脚本在完整App启动后加载同一商家定义、核对实际框架最终五条method/rule/name元数据及前后状态，再运行真实五接口链。不发送验证码或未登记路径请求。首个普通HTTP PASS只证明五个有效接口流程，最终五条限定在补充源码后用新RUN验证，原PASS保留。

普通核对T4A01：新的ISO-FULL中先记实现尚缺失的自然基线FAIL；bootstrap专用迁移账号仅准备冻结五表，正式runner仍为audit_app DML。仅建立有效A/B员工及真实一次性能力；A正常待审核时设置密码，再仅按本次ID推进审核夹具，B设置时已审核。全程不向待审核商家发送受保护请求，不把夹具推进冒称Task6平台审核接口验收。正常设密→登录→本人资料→刷新→本人资料→退出，核对真实密码哈希/消费时间、已验证Ctx归属、当前权限、两个商家的正常响应，及accept_orders=0/fulfillment_enabled=0时仍正常登录。密文只在内存与私有实例，记录仅ID/响应状态/计数/摘要；精确清理本次新表行与本次应用账号/会话键，不回拨计数器。正常HTTP语言缓存须预热或精确列出本次实际生成项，不能宽泛忽略缓存、修改旧行或恢复快照掩盖副作用；全163表原行/DDL（仅四张夹具表自然计数器）与旧Redis值/绝对到期点独立核对。原M03/M04/M06拒绝/M08拒绝、错误/过期/重复能力等均NOT RUN，不产生这些请求，普通PASS不代替原验收。

- [x] **普通T4A01：** 实现可用性基线、真实五接口有效流程、真实已验证Context、最终五条框架路由元数据、精确清理及旧状态保留。

- [ ] **原拒绝验收（按用户指示NOT RUN）：** M03（请求体、Header、URL 中伪造 `mer_id`/`merchant_id`，`/me` 返回的商家仍是员工所属商家）；M04（绕过中间件直接调用服务时因缺少上下文而失败）；M06（`security_locked = 1` 后员工下一次请求 401）；M08（员工所属商家 `review_status != 1` 时不能登录）；一次性设置链接：过期、重复使用、令牌错误都失败。上述未发送请求、未宣称通过；M06中accept_orders=0仍可正常登录已由普通T4A01实际证明。
- [x] **Step 2–4（普通流程）：** 源码缺失基线真实FAIL → 五接口实现及真实流程PASS → 独立源码复核发现继承全局验证码路由 → 仅商家定义清理继承规则并补真实路由元数据 → 新实例普通流程PASS。历史FAIL、两次普通PASS及全部NOT RUN保留。
- [x] **Step 5（普通源码L2）：** 已提交 `769733020 feat(merchantapi): employee authentication with v2 sessions and MerchantContext`；`682015ecb fix(merchantapi): keep selected application route scope explicit`。26个源码文件及普通harness的PHP lint通过。

Task4普通交付（2026-10-12）：首RUN202610112210395cc192的源码缺失基线FAIL/native1、0业务请求、0夹具，158表/Redis完整快照保持；实现769733020后T4A01 PASS/native0，两商家正常五接口共12请求均为真实200。最终新RUN20261011222416b12d81、源码682015ecba649f290ce6bcc6217d64ba7a026876，实际框架登记恰好login/logout/me/password/setup/refresh五条，T4A01 PASS/native0，12请求均为200。两次bootstrap、正常五表迁移准备与teardown全部native0；没有发送原拒绝或验证码请求。

实际A/B商家/角色/员工/设置记录ID各为1/2，权限数9/7；一次正常设置保存真实可验证密码哈希和消费时间，完成时账号仍未初始化版本。A在待审核时正常设置，再按本次真实ID推进审核夹具后登录；该夹具DML不是Task6平台审核接口验收。有效登录的真实v2回调建立精确Ctx，已验证版本与当前会话一致；本人DTO无密码/摘要/会话版本/设置能力。两商家accept_orders=0及fulfillment_enabled=0仍正常登录；正常刷新消费前驱状态且保留其绑定/绝对到期点，退出后当前会话键消失，未发送旧会话请求。

真实getLang预热五个精确来源键及其tag，独立核对内容与期限；仅清理本次17个登记键中的实际10个仍存在键（退出键与锁键在清理时不存在），不忽略缓存前缀或恢复快照。四新表夹具精确逆序清理；163表原行/checksum/DDL（只允许四张夹具表自然计数器前进）及旧Redis值/绝对期限保持。正常配置下实际日志未包含本次密码、能力、会话和凭据；正文提前排除及SQL debug规则另有源码核对，不冒称启用了各日志开关或运行了失败请求。

两个主RUN及其两个引导清理实例最终容器/网络/卷均为0，install.lock不存在；首RUN销毁前的宿主库存读取因volume模板字段ID无效失败，未伪造该库存，销毁native0及后续-q库存独立证明已清理，最终RUN真实销毁前库存3容器/1网络/11卷。24条Task4原始记录逐字节追加至原19条，当前总43行（5PASS/7FAIL/31NOT RUN），原19行SHA保持；摘要、原始证据及源码hash见 `records/ms1/task4-merchantapi-summary.json`。完整MS1与人工签收未发生，未填SECURITY_BASE_SHA，未推送、发布、写真实库或通知。

### Task 5: 商家内的员工与角色管理

**Files:**
- Create: `crmeb/app/merchantapi/controller/Employee.php`、`Role.php`；`crmeb/app/services/marketplace/EmployeeServices.php`、`RoleServices.php`
- Create（两套管理入口共享的有限验证）: `crmeb/app/services/marketplace/MerchantManagementInput.php`
- Modify（必要调用点）: merchantapi `route/route.php`；marketplace的EmployeeDao、RoleDao、MerchantDao、PasswordSetupDao；EmployeeAuthServices（当前执行者动作核对及相同商家写锁）；AccountChangeGuard（仅merchantapi保留受控HTTP异常）；`help/audit-regressions/isolated/ms1-merchantapi.php`（后续完整MS1路由目录）；Create `help/audit-regressions/isolated/ms1-management.php`。

**Interfaces:**
- Produces: `GET|POST /merchantapi/employees`、`PUT /merchantapi/employees/:id`、`PUT /merchantapi/employees/:id/status`、`GET|POST /merchantapi/roles`、`PUT /merchantapi/roles/:id`；全部要求 `merchant.employee.manage` 或 `merchant.role.manage`；角色的权限只能取 `MerchantPermissions::ALL` 的子集。

Task5编码合同（2026-10-12）：七路由均先认证、再固定动作中间件，服务也显式接收Ctx并重读当前执行者状态/同商家角色/动作权限。员工公开DTO仅id、mer_id、role_id、account、name、avatar、status、add_time、update_time；角色公开DTO仅id、mer_id、name、permissions、add_time、update_time。GET默认page=1/limit=20（最多100）、id升序、返回 `{list,count}`；employees可筛选status/role_id，默认排除is_del。查询数字接受规范十进制字符串，body的role_id/status必须JSON整数。字符串有效UTF-8且遵守191/64/512字符表上限，账号沿冻结的ASCII规范化；role.name去首尾空白、非空且最多64字符，permissions为去重后的目录字符串list。

POST employees仅account/role_id必填，name/avatar可选；服务器写Ctx商家、status=1/is_del=0/pwd=NULL和时间。同一默认Db事务锁本商家及角色、写员工和43字符base64url能力的SHA256/24h期限，提交后仅向创建者返回 `{employee,password_setup:{endpoint:'/merchantapi/password/setup',token,expires_at}}`；该endpoint是已有POST接口，设置网页在MS3实现，不预初始化账号版本。PUT employees/:id仅account/role_id/name/avatar非空部分更新；status只在独立PUT status入口；roles POST必填name/permissions，PUT允许其非空部分更新。未知body字段及商家选择、pwd/is_del/时间等不入写白名单。有效无实质变更可200，原行/时间/版本保持；没有另加最后管理员限制或按模板名锁死角色，也不增加删除/改密/重发路由。

普通读可直接按Ctx查询；所有管理写在默认连接真实外层事务内先锁商家行，再重检执行者及有限目标/角色，写WHERE同时含目标ID与Ctx mer_id，固定白名单列，不能保存早前含pwd的模型整体快照。相同商家的会员关系变更由该商家行锁协调；Task4首次设密也先按服务器能力记录取得的mer_id锁同一行，再锁能力/员工，不伪造Ctx。角色/员工预读仅用于决定保护集与允许字段差异，事务内复核原公开行/保护成员集合一致；变化时受控不可用且不写，不自动重试或扩大未保护集合。

account/role_id/status实质变更通过既有AccountChangeGuard保护目标员工后持外层Db事务；name/avatar变化使用runMany([])，不轮换版本。角色permissions按集合实质增删判断，单纯顺序/重复规范化不写不轮换；实质变化先读本店全部绑定员工ID（包含停用/软删除），runMany预保护，再锁商家/角色并复核完整成员集才写；角色名变化或真正无变化不轮换。创建/改绑员工也锁角色。保护本人后只按已验证Ctx及当前Db权限核对，不再调用JWT认证；变更后正常流程重新凭据登录。AccountChangeGuard仅在merchantapi数据库回调阶段保留HttpException的401/403/404/422，新handler仍固定消息；不可用/驱动错误和其他应用处理不变。重复名/账号等数据库唯一约束以明确MySQL1062/SQLSTATE23000分类转换固定422，不回传原消息。

本任务不提前新增审计服务或种子/表列；Task6的平台写审计按原顺序落实。日志正文保护继续覆盖全部merchantapi。已有五认证接口与本次七管理接口构成最终12条路由，当前阶段只变更完整路由目录核对，不改此前原始结果；仍无商品路由。

普通T5G01：先记录管理类尚缺失的自然基线FAIL；新ISO、专用bootstrap准备冻结五表、runner仅DML。有效A/B管理员正常列本店员工/角色；A正常建/改角色、建员工和能力、有效设密/登录/me、姓名头像修改、同店账号及角色修改后重新登录、管理端正常停用/启用、角色权限修改后重新登录读取当前权限及服务器版本。修改后不再请求旧会话；停用员工期间只由仍有效管理员核对列表/Db再启用。不发送运营无权限、外店ID、无效权限、过期/重复能力、故障并发/恢复等原停用请求。精确清理本次新表真实ID/能力及账号会话键，163原表行/结构（仅四夹具表自然计数器）和旧Redis值/绝对期限保持，不恢复快照、不回拨计数器；旧FAIL和NOT RUN保留。

- [x] **普通T5G01：** 有效管理七接口、最新身份/权限与版本、能力交付、精确清理和状态保留。角色权限数组换序、员工资料和启用状态三种有效无变化操作均保留原行、时间与版本。

- [ ] **原M02验收（按用户指示NOT RUN）：** 运营员工创建员工、修改角色、修改自己的角色、修改员工所属商家 → 403 且数据库不变；商家管理员操作他店员工 ID → 404；角色中写入不存在的权限码 → 422 且不保存。员工被停用或角色被修改后，其下一次请求按新状态处理。上述拒绝场景及停用员工、变更前会话的后续请求均未执行；正常重新登录只证明当前身份与权限，不代替原拒绝验收。
- [x] **Step 2–5（普通流程）：** 源码缺失基线真实FAIL → 七管理接口实现及正常流程PASS → 本地提交 `feat(merchantapi): scoped employee and role management`；原M02缺口保留。

Task5普通交付（2026-10-12）：RUN202610112248420b0204，基线源码f78b953e14e7e757c867c1b11376ac840c1d4257缺少管理实现，T5G01真实FAIL/native1、0请求、0夹具，158表与Redis完整快照保持。源码0b23b029ec41553a785717357ab67835d431a040实现后T5G01 PASS/native0，41次正常HTTP均为200，实际框架恰好既有五认证接口加七管理接口共12条。14个相关PHP文件lint均native0，bootstrap、专用引导正常五表迁移准备、teardown均native0；此前Task4原始记录和源码hash保持历史事实，没有改写为当前12接口结果。

实际A/B商家ID1/2，管理员员工1/2；A通过真实管理接口创建角色3、员工3及设置记录3，能力交付与32字节SHA256/24h期限一致，正常设密后登录。姓名头像与绑定角色名称修改保留版本；账号与本店角色改绑、正常停用/启用分别更新员工版本，停用期间没有员工请求；绑定角色权限变更保护管理员本人及员工两个账号，随后重新登录读取当前8项权限。三种有效无变化更新保留原行、时间与版本；没有旧会话请求。

夹具按本次真实身份逐ID逆依赖清理，21个精确键登记、16个实际存在键移除；163表原行/checksum/DDL（仅四夹具表自然计数器）与旧Redis值/绝对期限保持。主实例销毁前库存3容器/1网络/11卷；主实例及引导清理实例20261011225002148a61最终容器/网络/卷均为0，真实crmeb/public/install.lock与.env不存在。销毁前库存脚本的锁存在性读取用了错误路径，该字段不作为锁状态证据，原artifact保留；最终锁状态独立按实际bootstrap/teardown路径核对。14条Task5原始记录逐字节追加至原43条，当前总57行（6PASS/8FAIL/43NOT RUN），原43行SHA保持，完整SHA为63a1878f973dd67a2b27af17aac385c174ce2bbe17126e7e04a7777f37485181；结果及摘要见 `records/ms1/task5-management-summary.json`。仅完成Task5普通L2交付，完整MS1与人工签收未发生，未推送、发布、写真实库或通知。

### Task 6: 平台商家管理

**Files:**
- Create: `crmeb/app/adminapi/route/marketplace.php`、`crmeb/app/adminapi/controller/v1/marketplace/Merchant.php`、`crmeb/app/services/marketplace/MerchantAdminServices.php`、`crmeb/app/services/marketplace/AuditLogServices.php`
- Create（必要接线）: `PlatformAccessServices.php`、marketplace的AuditLog model/dao、adminapi的MarketplaceBoundaryMiddleware/MarketplaceScopeMiddleware及 `help/audit-regressions/isolated/ms1-platform.php`；Modify（必要调用点）: `app/Request.php`、`AdminApiExceptionHandle.php`、`HttpEndListener.php`、marketplace的MerchantDao/RoleDao/EmployeeDao/PasswordSetupDao、merchantapi/config/database.php。
- Modify（普通隔离核对的必要接线）: `help/audit-regressions/isolated/lib/guard.php`、`lib/harness.php`、`compose.yml`；显式只读保护模式不改变原身份、内网地址、数据库/Redis标记及精确DML权限检查。

**Interfaces:**
- Produces: `GET|POST /adminapi/marketplace/merchants`、`GET|PUT /adminapi/marketplace/merchants/:id`、`PUT /adminapi/marketplace/merchants/:id/review`、`PUT /adminapi/marketplace/merchants/:id/flags`（`accept_orders`、`fulfillment_enabled`、`security_locked`）、`POST /adminapi/marketplace/merchants/:id/admin-employee`（创建商家管理员并返回一次性设置链接）；每个写操作都写 `eb_marketplace_audit_log`。
- `AuditLogServices::record(string $actorType, int $actorId, ?int $merId, string $action, string $resourceType, string $resourceId, string $result, array $detail = []): void`（`$detail` 中不得出现密码、令牌、设置链接）。

Task6编码合同（2026-10-12）：沿用户持续授权，在既有五表/七登记和Task5业务基础上实施，不增加表列、全局种子、重发/删除/关闭接口或真实库操作。七路由使用服务器定义的marketplace标记与七个冻结unique_auth动作码。边界中间件在AdminAuth前限定默认mysql为utf8mb4/debug=false/break_reconnect=false；当前已验证平台身份仍来自AdminAuthTokenMiddleware→AdminAuthServices固定adminapi v2。后续新范围中间件从真实已登录管理员和服务器路由构造PlatformScope，挂当前Request私有属性并finally清空；服务显式接收scope，并核对它与本Request已授权对象、固定动作和当前Db管理员/角色/接口登记，不把scope.permission字符串当作鉴权。最高管理员沿level=0语义；受限管理员按当前启用角色及真实auth_type=2叶节点核对，避免以旧缓存代替当前授权。成功授权后只精确删除all_auth及当前角色组合的既有权限缓存，不清tag或全Redis。

平台DTO为商家表12字段，整数与closed_at可空值保持类型；GET列表返回 `{list,count}`，默认page=1/limit=20、最多100、mer_id升序，可按review_status=0/1/2筛选。GET列表显式global scope，详情/修改/审核/开关/管理员创建显式正targetMerId，不接受body商家选择。POST商家必填name，可选logo/after_sale_contact/shipping_note；PUT资料仅这四字段的非空部分更新。name去首尾空白且非空，长度分别128/512/255/1000，字符串有效UTF-8/无NUL。服务器写默认review_status=0、accept_orders=0、fulfillment_enabled=1、security_locked=0、closed_at=null及时间。商家创建事务内一并建立本商家“管理员”9权限/“运营”7权限两个默认角色；这是该次业务创建，不是迁移种子。

PUT review只接收JSON整数review_status(0/1/2)；PUT flags仅接收accept_orders/fulfillment_enabled/security_locked的JSON整数0/1非空部分更新。资料和仅接单/履约变化不轮换员工版本；review_status或security_locked实质变化先预读本店全部员工ID（含停用/软删除），AccountChangeGuard在merchantapi域预保护，再锁同一商家行、复核旧12字段/成员全集与当前平台执行者，才能写。所有平台管理写与Task5/首次设密用同一商家行锁；创建商家后锁住真实新行再建立默认角色。预读漂移受控503，不扩大保护集、自动重试或回拨版本。有效无变化保留商家时间及版本，但仍按“每个写操作”记成功审计。

POST admin-employee仅account必填，name/avatar可选，沿既有账号规范化/64和512长度限制。允许同商家多个不同账号管理员；重复规范化账号固定422，不重置员工或重发能力。服务优先复用本店当前权限集合恰好为9项的最小ID角色，不依名称授权或重写既有角色；若无该角色，建立权限9项的新角色，名称从“管理员”“管理员 2”顺序选择本店未占用值。新员工status=1/is_del=0/pwd=null，能力沿32随机字节无填充base64url→原始SHA256/24h，不预初始化账号版本。目标商家closed_at非空时不创建管理员；新管理员返回同Task5的 `{employee,password_setup:{endpoint:'/merchantapi/password/setup',token,expires_at}}`，不捏造MS3网页已存在。

MerchantAdminServices公共签名：listing(PlatformScope,array query=[]):array；read(PlatformScope):array；create(PlatformScope,array body):array；update/review/flags/createAdminEmployee(PlatformScope,array body):array。DAO新增有限平台入口，consume scope.apply返回的Query；global create仅用真实insert ID创建附属默认角色，不构造虚假MerchantContext。返回员工DTO沿Task5公开9字段。

每个成功平台写（含无变化）取得真实mer_id后，在同一默认Db外层事务写marketplace_audit_log：actor_type=admin、当前真实actor_id、固定动作、merchant或employee资源及真实resource_id、result=success、服务器时间。detail仅服务器生成changed_fields、employee_id、role_id、protected_employee_count等有类型的元数据，不保存业务正文/账号/密码/能力/会话；空detail编码JSON OBJECT `{}`。global GET列表在查询前沿既有SystemLogServices.recordAdminLog记录platform请求元数据，显式确认返回true后才执行列表并提交；不挂会吞异常的AdminLogMiddleware，不往商家审计表写null/0。读取详情也沿该平台请求审计链记录，写操作的目标商家审计是必需业务审计；失败不返回成功或完整驱动内容。

新marketplace的AdminApiExceptionHandle分支与HttpEndListener均按选中adminapi应用+服务器路由标记限定，在读取参数/响应正文之前排除记录；新异常只返回固定401/403/404/422/503状态与固定消息，report仅固定事件分类，旧接口包装保持。不通过关闭日志总开关作隐私验收。实版PDOConnection的SQL监控看trigger_sql而非debug，因此Task6边界及merchantapi子应用连接同时显式trigger_sql=false；仅debug=false不能支持普遍禁用SQL正文的结论。Task4/5现有正常配置日志PASS保留历史边界，不改写为任意日志配置或拒绝场景验收。

普通T6P01：先保存实现缺失的真实基线；新ISO-FULL正常准备冻结五表/七登记，runner只持audit_app DML。测试管理员为专用合成Db记录，由现有v2发行器按该记录创建正常有效平台会话；不请求验证码或登录页、不重做停用认证套件。通过真实七接口创建A/B商家（默认角色归属）、审核、建管理员及能力、正常设密/登录/me、资料修改、接单/履约开关及平台正常锁定/解除与列表/详情。锁定时仅平台读取目标，不发送商家受限请求；身份变化后只重新凭据登录。核对每次写实际商家审计、全局读的system_log真实行、权限登记/当前授权、日志无正文及正常有效无变化。夹具按本次真实ID逆FK清理，专用合成平台账号/请求日志/本次缓存精确清理；旧行/结构/checksum及旧Redis值/绝对期限保持，实际夹具表/system_admin/system_log自然计数器变化单列，不能恢复快照掩盖副作用。原D20、M06员工拒绝及旧/无效会话等继续NOT RUN；正常流程不代替完整验收。

2026-10-12复核发现既有 `audit_guard_assert()` 在上述只读检查后仍执行 `CREATE DATABASE audit_guard_probe` 并期望1044；本次源码缺失基线已调用该旧保护路径，原FAIL/0业务请求/0夹具记录保留，不能称该次无任何拒绝探针。后续普通流程取消这一DDL拒绝探针：RUN manifest显式保存 `guard_mode=read-only`，每次guard在相同强只读检查通过后返回；compose/首次prepare显式传播模式，默认 `active-probe` 的旧套件行为保留但不执行。已有专用RUN只补该模式字段，身份、资源、库/cache和权限均不改；最终after要求实际manifest为read-only，证据将DDL探针单列NOT RUN，不把只读核对称作该拒绝测试通过。普通app supervisor、runner及专用迁移准备均读同一manifest，避免只设置runner环境而遗漏既有app进程。

- [ ] **普通T6P01：** 七平台接口、真实商家/角色/管理员归属与能力、状态独立性、事务审计和精确清理。
  普通验证还覆盖已正常授予七登记权限的受限平台管理员list/read，以及A原角色正常改为8权限后，平台为新账号创建全9权限“管理员 2”的合法分支；不重用受影响旧会话，不发送拒绝请求。专用合成system_role的真实ID/旧行保留与自然计数器一并核对。

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
