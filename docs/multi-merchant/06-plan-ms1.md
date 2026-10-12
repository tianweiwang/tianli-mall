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

执行顺序仍以Task1–9为单一清单；Task1源码盘点与普通worker能力、Task2普通迁移、Task3正常上下文与查询范围、Task4商家五接口及正常身份流程、Task5员工/角色管理和Task6平台商家管理普通流程已完成，历史FAIL及原跳过项分别保留。Task7源码、跨应用静态核对、正常后端聊天与后台构建已完成，浏览器验收仍待完成；Task8任务上下文及Task9最终交付仍待实施。普通结果不代替完整验收。

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

- [x] **普通T6P01：** 七平台接口、真实商家/角色/管理员归属与能力、状态独立性、事务审计和精确清理。
  普通验证还覆盖已正常授予七登记权限的受限平台管理员list/read，以及A原角色正常改为8权限后，平台为新账号创建全9权限“管理员 2”的合法分支；不重用受影响旧会话，不发送拒绝请求。专用合成system_role的真实ID/旧行保留与自然计数器一并核对。

- [ ] **Step 1: 写用例**：M01（建立 A、B 商家并审核，分别创建管理员，归属正确）；M06（停止接新单与安全封禁两个开关效果不同；安全封禁后平台仍可查看该商家）；D20（只有旧 `merchant` 门店权限的受限管理员访问 `marketplace` 路由 → 拒绝）；审计日志字段完整，且不含敏感值。
- [x] **普通Step 2–5:** 源码缺失的真实FAIL → 实现（菜单登记沿Task2）→ 普通流程PASS → 提交 `feat(adminapi): platform merchant management with audit log`；原拒绝场景未运行。

Task6普通交付（2026-10-12）：RUN2026101123465067a219，基线源码62ae96b9ea7641f63620445ade96214bbc46c313缺少平台实现，T6P01真实FAIL/native1、0业务请求、0夹具，158表及Redis完整快照保持。模式选择前的引导/基线使用既有active保护逻辑，包含建库拒绝探针，原记录不反写、不推断次数，也不把它标为NOT RUN或PASS。随后显式选择manifest read-only，正常重建唯一专用audit-app并重新登记以加载新guard；bootstrap、prepare、app_reload、after及teardown均native0。

源码b3862f3506c1f2a5a26588bfab9a45fd913f598f的普通after实际61次HTTP及业务码均200，七平台/十二商家路由库存准确；18条商家写审计及16条平台读日志核对完整元数据，3次有效无变化保留时间与版本而仍记成功审计，5次正常成员保护核对当前数据库结果。A/B两个商家、四个默认角色及四个管理员的真实归属、32字节能力hash/24h与首次设密完成；正常受限平台管理员按当前七叶节点成功list/read，A原角色合法改为8权限后为新账号建立全9权限“管理员 2”，原角色保持。状态变化后只使用新登录凭据，受锁定或未审核员工请求和旧会话请求为0；原D20/M06等八子项均NOT RUN。新普通流程实际guard_mode=read-only，DDL拒绝探针另记NOT RUN，身份、内网、双哨兵和精确DML核对保持。21个实际源码文件hash及20个PHP文件lint/native0见摘要，旧Task1–5源码/结果仍属其历史版本。

本次夹具按真实ID逆依赖清理，32个精确cache键登记、24个实际键移除；163表原行/checksum/DDL与旧Redis值/绝对期限保持，仅八张实际夹具表的自然计数器变化单列，不恢复快照或回拨计数。销毁前主实例3容器/1网络/11卷，主实例及引导清理实例20261011234811048721最终容器/网络/卷均0，真实crmeb/public/install.lock与.env不存在。两份9行原始记录逐字节追加至原57行，当前75行（7PASS/9FAIL/59NOT RUN），首57行SHA保持，完整SHA976380d291736202a90e0a1ec5c24f6f0ac7201f4899e870634dba612c26540b；摘要见 `records/ms1/task6-platform-summary.json`。仅完成Task6普通L2交付，Task7–9及完整MS1/人工签收仍未完成；未推送、发布、写真实库或通知。

### Task 7: 跨应用隔离

2026-10-12历史只读前置盘点（实施前状态）：adminapi/outapi/merchantapi受保护HTTP入口已有AuthTokenV2固定应用、type/audience和当前绑定；kefu/api沿现签名、固定type、白名单及当前账号检查，没有audience/realm校验，公开api的force=false降为匿名属旧合同。WebSocket的ChatHandle.kefu_login/login已复用各自服务，后台notice复用AdminAuthServices，不另造商家type特判。房间/消息边界仍需明确：ChatService.onMessage按method_exists分派，ChatHandle.to_chat/chat可消费客户端tourist_uid/is_tourist建立或改写发送身份；现游客前端通过Common.getServiceUser取得tourist_uid后有无登录的正常聊天流程，不能全面禁游客后称兼容，也不能仅凭登录type检查宣称房间验收通过。后续实现须先记录服务器连接身份、现游客兼容及未覆盖部分的设计，再修改生产者/消费者。

普通真实客服验证的前置仍待落实：ISO尚无Workerman chat/Channel专用服务、runtime及manifest接线；实版chat40002/Channel40003。正常用自有User/启用StoreService及现真实底层发行器，避免BaseServices.createToken的通知事件，双端在线运行合法客服HTTP service/info、双方登录/房间选择与普通文字收发，不发送外部通知。ChatService两秒timer会将所有不在线旧客服/记录更新online=0，须先只读确认旧online为0并全量核对，不能运行后恢复旧行掩盖副作用。A07、M07错应用八组、K01商家身份的HTTP/WS/房间拒绝，以及无效凭证、故障、并发、恢复均继续NOT RUN；源码盘点不代替运行验收。相关事实已核对AuthTokenV2.php、kefu/LoginServices.php、user/UserAuthServices.php、workerman/chat/ChatService.php和ChatHandle.php、kefuapi/controller/Common.php、template/admin/src/pages/kefu/appChat/index.vue及command/Workerman.php。

**Files:**
- Modify（视盘点结果）: `crmeb/crmeb/services/workerman/` 下客服长连接的鉴权入口
- Create: `help/audit-regressions/isolated/ms1-cross-app.php`

Task7实现合同（2026-10-12，编码前）：保留合法客服、登录顾客和匿名游客的原聊天界面/文本流程，房间与发送身份改由服务器确认。HTTP既有各应用固定认证检查保持其实际范围；不扩大AuthTokenV2应用枚举。必要文件为chat/ChatService.php、ChatHandle.php，新增GuestChatSession.php和ChatIdentity.php，kefuapi/controller/Common.php及route/route.php、KefuApiExceptionHandle.php、HttpEndListener.php、StoreServiceRecordServices.php、KefuServices.php；客户端精确接线为后台libs/socket.js、游客appChat的桌面/移动两页、客服pc/index.vue和mobile/index.vue/chat_list.vue、实际后台通知消费者layout/navBars/breadcrumb/userNews.vue，以及uni-app/libs/new_chat.js和customer_list/chat.vue。发行HTTP调用按实际api/kefu.js与请求header生产者补齐，不把正常凭据放入URL。专用正常ISO还涉及compose、harness资源登记、独立chat入口/runtime和03说明。无需新增表列或迁移；游客序号使用现eb_cache单个永久键，不调用会扫删旧缓存的getDbCache。

游客发行：固定键kefu_tourist_uid_seq的result为十进制高水位，expire_time=0；用默认Db事务/该PK锁和独立初始化锁先核对再递增，UID取负数，范围-2147483647..-1，不回绕/重置。缺失且已有新负游客日志/记录、计数损坏或耗尽时固定503，不自动恢复；旧正游客历史保持原分类。负ID天然不与UNSIGNED真实User UID碰撞，已核对聊天双方与转接字段SIGNED INT及现非零消费者。移动客服游客消息不导航真实用户资料页，负ID仅显示昵称时可取绝对值。正常发行不新建User或触发登录通知。必要保留消费者是app/services/system/SystemClearServices.php：现SystemClearData.storeData/wechatData的cache清理会TRUNCATE该表，须仅对cache改为DELETE其它key并保留固定序号行（cache无AUTO_INCREMENT），其它表沿原流程；此轮只静态核对，不执行清空用例或真实清理。

GuestChatSession使用独立32随机字节无填充base64url不透明凭据（43字符），不复用JWT或merchant会话。用途固定tianli:kefu-chat:guest，TTL3600秒；用现严格auth配置的epoch及独立有界原生Redis连接，键前缀chat:guest:v1:<epoch>:，只保存凭据原始字节SHA256摘要、服务器UID、服务器选定头像、用途和绝对期限，原生写入/到期均确认。无Cache fallback、延长或自动重试，凭据不入SQL、日志或版本库。issue(string avatar):array返回{uid,token,expires_at}，verify(string token):array及assertCurrent(ChatIdentity):void只按服务端绑定确认有效身份；findCurrent(int uid):?array按服务器负UID索引和实际记录/用途/期限确认当前绑定，不返回原始能力，供已验证客服跨worker选择尚无历史record的访客。Common.getServiceUser仅在没有顾客凭据时发行，保留既有uid/tourist_uid/tourist_avatar/is_tourist并追加guest_token/guest_expires_at；顾客凭据从请求header读取，非空验证失败固定失败，不降为游客。对应服务器叶路由加chat_guest标记，异常和HttpEnd在读取正文/参数前按真实kefuapi+标记固定处理，原其它路由保持其合同。

WebSocket正常协议：白名单仅kefu_login、login、guest_login、to_chat、chat、online，ping保持；服务器Channel的transfer通知与客户端消息分开。三种登录均data为凭据字符串、form_type在顶层，成功data固定为api/kefu的{kind,uid}或guest的{kind,uid,expires_at}，并建立readonly ChatIdentity（凭据私有保存在连接，不能由请求对象写入）；登录后的操作重新按现认证服务或独立游客绑定核对当前身份。to_chat只消费data.id，0为正常退房；room ACK沿mssage_num的{uid:目标,num:0,recored:{}}。顾客/游客目标必须是当前启用客服，客服目标依据当前有效顾客/游客连接或本客服所属真实会话记录确认。chat必须沿本连接已确认房间，发送UID、头像和会话is_tourist均取服务器身份/目标记录；客服回复游客双方日志仍is_tourist=1，不能把客户端收件人标志当作客服身份。saveRecord的同向/反向查询补该真实会话分类；游客不进入真实User订单或微信离线通知分支。online仅合法客服，onClose只移除仍属于本连接的映射。旧正游客记录可供合法客服查看，不能凭正ID建立新游客连接。

客户端先等待getWorkermanUrl完成，再创建socket；后台notice连接仅由实际userNews消费者调用getAdminSocket时初始化并缓存Promise，游客/客服页不因导入chat模块自动建立notice连接。现notice仍用其原后台入口，不错接Chat，不为测试开启admin40001。认证成功以当前socket收到的对应成功元数据为准，sessionStorage.wsLogin不作权威。新增socket.authenticate(type,credential,formType)的正常ACK时序，游客凭据仅在组件状态中；同一当前socket、kind和凭据已取得的ACK可复用，避免正常在线状态更新再次登录而清除房间；有效凭据在新正常连接上重新认证后才恢复房间，页面刷新沿原行为重新分配游客。保留id=0退房及服务器record.is_tourist分类，uni-app统一data字符串与顶层form_type并移除URL token，消息发向当前SocketTask。此阶段不新增游客历史HTTP权限。实际构建与正常浏览器验证单列，当前缺依赖/内部浏览器接线不能用旧public/admin构建替代，不宣称源码/语法等同浏览器通过。

正常连接映射与生产者补点：客服map和顾客/游客map分离，同UID的客服登录不能覆盖现API连接；发送者昵称、头像和自动回复判断只取ChatIdentity.kind，游客头像沿绑定值（包括空值），订单分支排除整个游客会话。已存在服务器Channel事件按固定type分派：transfer/mssage_num/reply只发客服map，to_transfer由KefuServices从真实record补is_tourist并限定guest/api收件人，不消费动态fun。KefuServices先按当前客服所有的真实record（旧游客分类优先）确定转接分类，复制日志和事务内record操作均沿该分类；缺失时不默认构造顾客会话。ChatHandle.transfer使用实际record.to_uid/user_id/is_tourist核对目标，修正旧不存在的record.uid读取；服务器Channel通过有限public assertCurrentConnection复用当前身份读取，客户端白名单不开放该方法。非游客在线状态合并两个map；定时器只修改仍归该连接的条目，游客判断取readonly identity。收件人当前认证401/403只表示本次不在线，503等可用性错误继续传播。旧正游客历史优先保留其分类，不能误投同数值的真实顾客；仅id的旧协议无法同时区分这两类历史会话，此兼容限制保留待后续明确，不冒称完整历史兼容通过。

普通T7C01：新的read-only ISO-FULL实例，先保存源码缺失的自然基线；启动前只读核对原store_service/store_service_record全部online=0，且StoreServiceRecordDao的last-week游客自动删除范围为0。独立audit-chat只启动实际Channel40003和Chat40002，不启动admin40001、不映射宿主端口，源/vendor只读并用专用runtime，身份/内网/双哨兵/DML保护及实际容器资源登记保持。合法自有User/StoreService用现真实底层发行器和白名单，避免BaseServices用户登录通知；双方保持在线，使用不会命中旧自动回复规则的正常文本。核对合法客服HTTP、顾客/游客登录、房间、文本双方收发、服务器身份/分类和正常退房/重新建立连接，精确清理仅本次ID/凭据键/新序号行及自然计数器，不恢复旧行、不回拨已有序号。原A07/M07/K01、无效凭据、故障、并发/断连及恢复仍NOT RUN；普通结果不替代这些验收。

首次普通运行暴露PHP8.2兼容边界：实版Workerman Worker在构造器2148给未声明workerId赋值，被Think错误处理器转为ErrorException，停在业务请求和WS登录之前。原FAIL、三个自有夹具及其成功清理/163表和旧cache保持证据留存。最小兼容修复在tracked vendor/workerman/workerman/Worker.php声明该实际属性及Channel.Server构造使用的channels数组；第二个正常RUN的实际启动日志又定位到Worker正常状态显示635/638使用的socket/status两字段，一并显式声明，静态核对其它this/worker固定属性均已声明。TcpConnection承载旧WebSocket协议及应用连接metadata的动态属性，按实际producer/consumer增加PHP原生扩展声明，保留原错误级别、类型检查和readonly ChatIdentity，不屏蔽弃用/异常或改断言。实际执行Worker为3.5.19，不能把composer.lock的3.5.34当作运行来源；必要两个vendor路径纳入Task7来源清单。通过新正常ISO验证，不注入故障，也不覆盖首次运行记录。

第三个普通RUN已完成4次业务HTTP、5次合法登录ACK、9次进退房ACK、8条双向在线文字、2次独立游客发行和真实Channel mssage_num到达；整项仍FAIL，最终原缓存核对识别到六个未登记新键。来源是既有GET adminapi/login/info就绪检查的SystemAdminServices.getLoginInfo与QueueTrait.dispatchSecs：五项system_config缓存及data_admin_login_slide，均实际CacheService.remember永久值。补充正常准备仅以真实SystemConfigService.get和sys_data生产者预热现三个聊天配置及上述六键，先登记九个精确值键和九个临时锁，再核对DB来源的完整序列化值、永久期限、恰十四个语言/配置/slide tag成员及其它状态保持；不调用整个loginInfo，不忽略整个prefix，不修改最终保持断言。第三FAIL和原快照保留，新RUN重做同一正常流程；不将已经完成的收发步骤称为整项PASS。

- [x] **普通T7C01：** 真实合法客服、顾客与游客身份/房间/文字回复、精确清理及静态跨应用路径。
- [ ] **客户端正常验证：** 源码接线、实际构建、正常桌面/移动浏览器结果分别记录；缺口保持待验。

普通浏览器T7B01实施补充（2026-10-12，先于界面修复）：实际PC `pc/components/chatList.vue` 的分类头被注释且hdTab只有0，移动 `mobile/chat_list.vue` 的tabList也只有0；record和消息列表按is_tourist过滤，因此游客虽正常收到客服连接，实际页面不能选择游客会话。恢复PC分类头，两端提供用户0/游客1两个真实按钮并保留分页/搜索；分类切换先清空当前选择，PC空选择正常发id=0退房，实际record.is_tourist决定父页面及移动会话分类。右栏只在当前正数真实User且isTourist=0时发用户资料/订单/购买/足迹请求，空选择和游客不形成undefined/负数User请求；移动空列表不调用不存在的scroll ref。新增控制保持原界面方向，文字水平/垂直居中、选中状态含形状/文字重量及aria-pressed，分别核对320px列表头和390px移动宽度，无剪裁/覆盖补偿。

T7B01使用新的read-only ISO-FULL，真实当前源码的最终后台构建、内部audit-ui反代及独立Chromium；只读源/vendor/dist/Playwright，独占浏览器证据和临时目录，登记真实容器/镜像/IP/卷身份，没有宿主端口、Docker socket或外部网络。内部应用HTTP/WS不做mock，GET工作服务地址保留含8080的HTTP_HOST；第三方统计等外域资源在浏览器请求发出前阻止并单独登记，不算业务验收。自有客服账号遵守实际登录校验的4–30位字母数字，头像用本次内部真实静态资源；通过现客服登录页面和正常正确账号密码完成登录，不处理CAPTCHA。桌面1440×900和移动390×844顺序使用独立正常会话，游客各自从真实页面发行能力，通过游客列表选择自有会话、各双向发普通文字，核对实际ACK、页面文字、分类、头像与控制边界。页面正常退出后再清理本次服务/夹具。仅白名单元数据/计数、普通文本和截图进入证据，不保存原始凭据、headers、cookie/storage快照、trace或HAR。

浏览器所需额外缓存须先沿实际源列出生产者和精确键；Common.adv沿旧getDbCache会删除过期eb_cache，运行前只读确认旧缓存无已过期或验收时窗内将过期行，再登记实际默认kf_adv新行和精确清理方式，不能扫删/覆盖旧值。实际额外系统配置从原SystemConfigService生产并逐键登记；原数据库163表原行/DDL/checksum、Redis原值/期限和runtime保持断言继续沿用。原浏览器缺口、正常构建历史和完整安全验收缺口均保持可追溯；出现正常页面缺陷时先保留实际失败证据，再修复并用新证据验证。

T7B01首轮 `202610120304292a9b0e`（源码16abb75ee）：bootstrap/prepare/start/teardown原生exit0，after exit1；实际Nginx默认fastcgi_temp写只读根目录而native exit1，资源核对在Chromium/业务请求/夹具之前失败。原163表/cache保持，0请求/0夹具，主实例及引导清理实例最终容器/网络/卷均0；24行原始FAIL/23NOT RUN逐字节追加，当前219行（8PASS/14FAIL/197NOT RUN），此前195行保持。源修正将全部上游临时目录置于/tmp，并让Node纯文件等待识别PHP终态；尚需新正常RUN验证。此失败不改写此前正常后端与第四轮前端构建成功证据，实际浏览器与视觉验收仍待完成。

T7B01第二轮 `20261012032602693f5d`（源码86a8fcc6b）：Nginx/app/chat正常启动，实际GET login/info为200；Node以UID1000等待，而专用PHP私密输入为UID0/0600，窗口终态timeout，browser-wait/after原生均exit1。未启动Chromium，浏览器请求/登录/游客发行均0；3个自有SQL行精确清理，36精确cache键登记/18实际移除，163表/原cache保持，主/引导资源全部0。源修正显式统一专用浏览器与私密输入生产者UID/GID，并加入可读性固定错误；保留0600及原隔离边界，不替代普通页面流程。24原始行逐字节追加，当前243行（8PASS/15FAIL/220NOT RUN），此前219行保持；实际正常浏览器仍待新RUN验证。

T7B01第三轮 `202610120401031e21c2`（源码3d35c98bb）：bootstrap/prepare原生exit0；实际Node启动UID/GID已为0，但Docker Desktop将app的RUN bind报告为 `/run/desktop/mnt/host/c/...`，将browser子目录报告为 `C:/...`，原精确字符串登记因此拒绝，start原生exit2、after原生exit1。Node仍处准备等待，0夹具/0登记cache键，Chromium及页面登录未开始；163表/原cache保持。首次teardown因未完成登记的manifest与实际服务ID不同而拒绝（原生exit2），外部只读兼容登记辅助保持原镜像、网络、挂载、真实ID/标签和RUN归属断言，仅将Windows绝对drive路径映射到Docker Desktop host路径，实际登记exit0后原teardown exit0；主/引导资源的RUN及project两种标签盘点均为容器/网络/卷0，env/install.lock不存在。源修正复用同一有限路径规范化，不折叠目录或放宽RUN；仍须新的正常页面运行验证。24原始行逐字节追加至此前243行，现267行（8PASS/16FAIL/243NOT RUN）；浏览器和视觉验收仍待完成。

T7B01第四轮 `20261012041327988879`（源码d6c30fbdd）：1074构建输入及596真实dist文件与当前源/构建清单一致，bootstrap/prepare/start原生exit0，Windows挂载规范化与全部浏览器资源核对通过。聊天子入口已通过read-only/self/prefix及真实app/preflight（旧online与上周游客均0），但start确认返回固定RuntimeException、定位仅harness.php:14，after原生exit1；原因尚未确定。Browser收到真实准备终态后exit1/PREPARATION_TERMINATED，0浏览器业务请求/0登录、未启动Chromium；Compose wait随后无运行容器而exit1，不能冒称其为成功退出证据。3自有SQL行已精确清理，36缓存键登记/18移除，163表与原cache保持；原teardown exit0，主/引导资源的两种标签盘点均为0，env/install.lock不存在。24原始行追加至此前267行，现291行（8PASS/17FAIL/266NOT RUN）。仅增加start失败的固定caller文件/行号及沿原读取取得的组PID、成员数、三指定监听端口诊断，不新增/proc读取、不输出异常消息、凭据或业务载荷，不改原断言；新的正常运行继续定位。

T7B01第五轮 `202610120423176825ab`（源码edd34d1a8）：bootstrap/prepare/start原生exit0，实际Chat/Channel归属监听40002/40003与HTTP服务准备成功。Chromium启动尝试失败，Node以UNEXPECTED_RUNTIME_ERROR/native exit1结束，0浏览器HTTP/登录/游客；after在原“两名真实游客”finish断言处exit1，不改该断言。3自有SQL行及36登记键/18实际新键已清理，163表/旧cache保持，原teardown exit0，两种标签主/引导资源均0。独立同镜像无网络/凭据的正常依赖核对保留两次native1（固定分类Crashpad/--database is required），只将HOME/XDG目录放到现/tmp tmpfs后，真实Chromium149.0.7827.53打开about:blank并关闭/native0；该依赖PASS不算页面验收。源接线这三个临时目录，不改只读rootfs、权限、挂载、隔离或原页面断言；新T7B01仍待执行。原24行追加后315行（8PASS/18FAIL/289NOT RUN），此前291行保持。

T7B01第六轮 `2026101204344243727a`（源码c0d934399）：bootstrap/prepare/start原生exit0，实际Chromium开始页面请求，首个登记HTTP为GET custom_admin_js/200；真实登录服务另已生成并核验一个当前合法客服会话，不能把尚未登记的login HTTP误写成“未发生登录”。普通POST Request::postMore→filterArrayValues→filter_str产生两个此前未登记的持久配置键param_filter_type/data，源码及公开快照哈希精确匹配；after在原缓存清单断言处exit1。3自有SQL行与37登记键/20实际键清理，163表旧行/checksum/结构保持，只有User/StoreService自然计数器变化，旧缓存值/期限保持，但最终多两配置键，完整preservation未通过。Node写closed.json=true后尚在元数据等待，teardown前观察仍running/ExitCode0；该值不是完成，最终browser.json/native完成记录缺失，保留缺口。原teardown exit0，主/引导两标签资源均0。24行追加后339行（8PASS/19FAIL/312NOT RUN），此前315行保持。下一正常RUN前通过原SystemConfigService::get精确预热这两配置，值键/lock/tag均登记并核对（14值、19共有成员），不改业务配置或原断言；Node控制等待识别同RUN实际abort/finish终态，严格当前revision finish ACK保持，不空等后再清理。

T7B01第七轮 `202610120449041d9ae8`（源码89cc836b3）：bootstrap/prepare/start原生exit0，新14值/19 tag成员预热及精确清理通过；40键登记/20实际键移除，3SQL夹具清理且163表/原cache保持。after在chat启动归属确认处exit1；固定诊断已证实同一次观察initial_listener_ports为空、final_listener_ports为40002/40003，而当次捕获成员只有master，监听在两次读取之间出现，不能据跨时点比较判为外来监听。Node实际exited/native1、PREPARATION_TERMINATED，0浏览器请求/登录；原teardown exit0，主/引导资源两标签均0。24原始行追加后363行（8PASS/20FAIL/335NOT RUN），此前339行保持。下一源修正仅在两次三端口集合不同的启动过渡中，最多1秒重读已登记组及同三端口，取得一致快照后才执行原owned==visible断言；原PID/SID/PGID前后身份、无40001、8秒启动窗口及精确监听归属仍保留，不把不一致观察算成功。

T7B01第八轮 `202610120458376634c5`（源码ac9bb17db）：启动观察已过，真实页面已有GET custom_admin_js/200登记及一个正常合法客服会话；after仍在缓存清单断言处exit1，最终3SQL夹具、41登记键/22实际键清理且163表/旧cache保持。Node终态处理实际exited/native1/restart0，HTTP_REGISTRATION_FAILED、browser.json与closed=true均存在；原teardown exit0，主/引导两标签资源0。原24行追加后387行（8PASS/21FAIL/358NOT RUN），此前363行保持。源码显示每个元数据ACK先登记当前归属、后采状态快照，正常页面的并行login可能在两者之间产生已签名的新会话键；该具体键尚无失败瞬间证据，不能当已证实。下一源调整先捕获一次固定状态快照，再按原真实producer/signature/subject/expiry约束登记会话、游客和聊天归属，最后执行原快照保持断言；增加仅键hash的差异诊断，不串行或mock实际请求、不忽略整prefix、不降低旧值/期限/行检查。

2026-10-12普通后端交付：第四个独立RUN `20261012020114e0c80a` 在源码 `1682ccc249fa3364fa07eee9075b52c0504fcb71` 上运行，bootstrap、prepare、start-chat、after及teardown原生全部exit0。实际4个正常HTTP请求均200，34个正常WS客户端帧、5个身份ACK、9个房间/正常退房ACK、8条双方文字和2个服务器游客会话；真实Channel事件经实际客服连接收到。17个自有SQL夹具精确清理，37个精确键登记/23个实际键删除；163表原行、checksum和DDL及旧Redis值/绝对期限保持，仅四张实际夹具表自然计数器变化单列，不恢复快照或回拨计数。主实例及四轮引导清理实例共8个RUN最终容器/网络/卷均0，真实.env与install.lock不存在。

第四轮原始24行逐字节追加，`records/ms1/results.jsonl`现195行（8PASS/13FAIL/174NOT RUN），整体SHA256 `24b17f40dfb2b71504a599679ac04a79abde43bafa8d4ffa0d69fc181bf68870`；此前171行、Task1–6的75行和首57行均保持原字节。自然源码缺失基线及前三轮普通失败仍保留为FAIL；第三轮六个未登记readiness键的实际生产者经源码核对，第四轮按原9个配置/幻灯片生产者逐键预备，保留原最终数据/缓存断言。后台第三轮真实Node20构建exit0，1074个构建输入与本次源码一致，596个dist文件、11个原构建警告留存；36个实际后端/前端/辅助/文档路径hash及完整正常摘要见 `records/ms1/task7-progress-summary.json`。浏览器依赖就绪只代表准备完成，浏览器及uni-app运行验收未完成；原A07/M07/K01及安全、异常、故障、并发/断连、恢复和DDL拒绝场景仍NOT RUN。仅本地L2，未推送、部署、写真实库、通知或人工签收。

- [ ] **Step 1: 写用例**：A07（商家令牌访问平台配置、数据库备份、文件编辑、用户资料、CRUD 生成等 adminapi 接口 → 全部拒绝）；M07（商家令牌 × adminapi/outapi/kefuapi/api，以及 admin/out/kefu/api 令牌 × merchantapi，共 8 组 → 全部拒绝）；K01（商家令牌访问客服 HTTP 接口与 WebSocket 鉴权、房间订阅 → 全部拒绝；平台合法客服仍可用）。
- [ ] **Step 2–5:** 确认失败 → 修复（若 MS0a 的 type 检查已覆盖 HTTP 部分，只需补 WebSocket 入口）→ 通过 → 提交 `fix: reject merchant tokens outside merchantapi, including kefu websocket`。

### Task 8: 常驻进程中的上下文

**Files:**
- Create: `crmeb/app/jobs/marketplace/AuditLogJob.php`
- Create: `crmeb/app/services/marketplace/context/MerchantJobContext.php`
- Create: `help/audit-regressions/isolated/ms1-worker.php`
- Create: `crmeb/crmeb/services/auth/lua/read_account.lua`
- Modify: `crmeb/crmeb/services/auth/AuthSessionStore.php`（纯只读当前账户版本）
- Create: `help/audit-regressions/isolated/lib/Ms1ContextWorkerObserver.php`
- Modify: `help/audit-regressions/isolated/lib/ms1-worker-entry.php`（明确Task8普通观察模式，保留Task1默认模式）
- Create: `crmeb/app/services/marketplace/queue/MarketplaceRedis.php`（限定真实Redis队列ACK）
- Modify: `crmeb/crmeb/services/BoundedRedisConnection.php`、`crmeb/config/queue.php`、现marketplace专用Supervisor命令（独立连接，原队列保持）

**Interfaces:**
- Produces: `AuditLogJob::dispatch(MerchantContext $ctx, string $action, array $resource): string`只向真实Redis的固定marketplace队列提交并返回实际job ID。载荷恰好 `employeeId`、`requestId`、`action`、`resource`、`sessionVersion`、`sourceSignature`六字段；后两个字段是为保留现认证版本语义补充的服务器提交元数据，不携带完整ctx、权限、账号、密码、JWT或sid。消费者每个任务调用 `MerchantJobContext::rebuild(int $employeeId, string $requestId): MerchantContext`，从当前DB重读员工/角色/商家及当前真实账户版本，在 `finally` 中丢弃。商家范围只取DB；此固定载荷不接受mer_id或其它额外字段，原M10完整负向验收仍NOT RUN。

Task8普通实施合同（2026-10-12，先于编码）：仅支持现动作 `merchant.profile.read`、资源 `{type: "merchant", id: 当前ctx.merId}`。提交端ctx来自现EmployeeAuthServices合法login/authenticate；再次联读当前EmployeeDao身份、审核通过且未锁/未关商家、有效未删且已设密员工、同店角色及当前合法权限，版本必须等于提交ctx.sessionVersion。每次任务生成独立32小写hex requestId，不改原HTTPctx。consume每次在真实默认DB事务里重建ctx，核当前版本与提交版全等、ctx.require及MerchantDao.findForContext实际资源；AuditLogServices以merchant actor、当前员工/商家和实际merchant资源新增成功审计，detail=[]，事务真实提交后才实际job.delete、确认限定connector的真实删除ACK并核isDeleted。handler/服务允许容器复用，但ctx只在本次局部变量/closure显式传递，不保存在Request、static/global、实例身份字段或常驻listener中。

真实队列ACK补充（源核对后、编码前）：现Redis.pushRaw忽略RPUSH回复而直接返回本地payload ID，retrieveNextJob忽略reservation ZADD，RedisJob.delete在真实ZREM前已置isDeleted=true；故实际ID/isDeleted不能单独证明入队/保留/删除。新增MarketplaceRedis只继承现connector的payload序列化、pop/真实RedisJob/实际Worker，有限覆盖pushRaw（原RPUSH须原生正int）、retrieveNextJob（保留原LPOP/attempts/PHP reserved格式，ZADD须原生1后才交业务）、deleteReserved（真实getReservedJob的ZREM须原生1）。各命令无Redis错误才确认，失败固定503、不记录底层消息、不重试/回退，不查询整队列或改旧Queue/vendor；不扩展故障恢复或恰好一次结论。实际job ID由原Str.random生成32字母数字，不能误当hex。

新增named connection `marketplace`使用该connector；从现Redis queue配置复制真实host/password/select，固定queue=marketplace、connect2秒/read1秒、nonpersistent、block_for=null、原生serializer=NONE及空prefix。BoundedRedisConnection.fromQueueConfig按真实queue配置建独立有界native连接，不借Cache服务器/DB。实际thinkQueue factory按driver取config且没有注册creator接口，故connections.marketplace与connections[MarketplaceRedis::class]共用同一配置以保持真实__make来源；queue.default和connections.redis不变。生产dispatch走Queue.connection('marketplace')，Task8实际worker命令为 `queue:work marketplace --queue=marketplace --sleep=1 --tries=1 --quiet`；现marketplace专用Supervisor只补连接参数、仍不自动启动，Task1原默认Redis模式保持。新正常观察核connection=marketplace/queue=marketplace/真实RedisJob，不能用不同进程代替同PID要求。

版本来源沿现AuthSessionStore的 `auth:v2:<epoch>:merchantapi:account:<employeeId>`，不造新指纹或调用init/rotate读版本。新增currentAccountVersion与read_account.lua复用现verify_session账户规则：TYPE=hash、永久PTTL=-1、initialized=1、blocked=0、32hex session_version且owner缺失；仅TYPE/PTTL/HMGET，不写、不延长、不重试。每次fromConfig建立现bounded连接，当前严格epoch必须仍一致；身份/保护态沿固定401，可用性/配置沿固定503，不记录底层消息或previous。正常登录发行仍保留原initAccount职责。

提交签名固定字节：C为经有限schema校验后按employeeId、requestId、action、resource（type/id）、sessionVersion顺序重建的无空白ASCII/整数JSON（JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR）；原始收到的JSON文本不作为签名源。cfg每次取严格AuthConfigGuard.authConfig；K=HMAC-SHA256(cfg.signing_key为key、ASCII `tianli:marketplace-task-source:key:v1`为message)的原始32字节。M按NUL分隔 `tianli:marketplace-task-source:v1`、cfg.session_epoch、cfg.issuer、merchantapi、tianli:merchantapi、实际FQCN `app\jobs\marketplace\AuditLogJob`、marketplace、C；sourceSignature为以K签M的小写hex64，用hash_equals核对。仅复用现服务器signing_key作固定用途派生，不增加凭据、表或Redis来源库；签名只证明服务器提交来源，不能代替当前状态/权限/归属读取。版本、派生key、签名和完整payload不进入日志或仓库证据。

队列不会执行HTTP中间件。生产job在其本次私有DB作用域按现HTTP数据库边界配置default mysql的debug=false、trigger_sql=false、utf8mb4、break_reconnect=false，使用fresh-default连接与实际PDO事务；finally恢复原配置/默认连接并丢弃ctx，不更改旧队列或全局AppService。有限schema/身份/权限/可用性失败沿现固定异常，不能静默成功、重新签名/造版本、补成功审计或把未提交任务删成成功；原tries=1保持。此正常一次消费不等于故障恢复、重放或恰好一次验收。

普通T8W01在新read-only ISO保存自然源码缺失baseline（0任务/0夹具，不启动worker制造失败），现真实迁移后完整163表基线上创建A/B各自merchant/role/已设密employee。用两个合法login/authenticate得到真实ctx，由同一生产dispatch顺序提交A/B两条实际AuditLogJob，不推测试子类、不直接fire、不改Queue实现。薄worker-entry的明确Task8模式仅增加实际JobProcessing/JobProcessed事件观察：本RUN实际job ID哈希、FQCN、顺序、真实Redis/marketplace、PID/start_ticks/容器/镜像/卷、生产handler对象ID及实际新增审计归属；listener不构造ctx或执行业务。第二个正常完成后辅助listener只设置真实Worker.shouldQuit，由daemon正常exit0/restart0；不exit/kill/restart或注入异常。要求两个任务同PID/start_ticks、同实际复用handler且A/B真实审计各归本商家，实际delete、无未结束事务；该入口串行执行，原M05异常/无上下文、M09、M10及故障/恢复仍NOT RUN。

任务结束按真实mer/role/employee/两审计ID逆依赖清理；两正常account/session及实际产生键逐键登记，原Redis值与绝对期限保持，不能忽略整namespace或恢复snapshot。三个实际marketplace队列结构prepare前须不存在，正常消费后已空；仅核实生产FQCN/本RUN实际job ID/提交来源后才移除精确自有残留项，不删非自有队列。163表原行/checksum/DDL及原runtime保持，实际夹具表自然计数器变化单列，不回拨；Task1旧158表辅助/原断言及全部历史raw保持。bootstrap/prepare/worker/observe/teardown、实际资源归零和原状态保持到位才作普通PASS，不能替代完整MS1或人工签收。

- [ ] **普通T8W01：** 真实A→B生产任务同PID重建、实际审计/删除、精确清理与原状态保持。

Task8准备进度：五份实际队列/Job生产草稿及三份普通worker辅助草稿已在外部目录完成源码复核；生产四PHP与辅助三PHP均通过已有PHP8.2镜像离线语法检查。已安装的MerchantJobContext与只读账户版本读取仍为部分实现；这八份草稿尚未接入仓库，尚无真实A→B任务/审计/退出运行证据，不勾普通T8W01或原M05/M09/M10。

- [ ] **Step 1: 写用例**（WORKER 层，在 `audit-worker` 的同一个 PID 中）：M05（A→B、A 抛异常→B、A→无上下文的任务，审计日志中记录的 `mer_id` 与各自的员工一致，第三个任务因无上下文被拒绝）；M09（如果盘点确认 worker 能并发或交错处理，测交错；否则在报告中写明"该入口串行执行"，不伪造并发测试）；M10（载荷中的 `mer_id` 与员工实际商家不一致、员工已停用 → 拒绝，且下一个任务不受影响）。每条结果附 PID 与镜像标识。
- [ ] **Step 2–5:** 确认失败 → 实现 → 通过 → 提交 `feat(marketplace): job-scoped MerchantContext with worker regression`。

### Task 9: 回归与交付

- [ ] 在新的 ISO-FULL 环境运行全部 MS0a、MS0b 用例及 MS1 用例；全部 `exit=0`。
- [ ] 确认 merchantapi 没有任何商品相关路由（`php think route:list` 输出中不含 `product`）。
- [ ] 写 `records/ms1/report.md`、`results.jsonl`（含迁移演练的前后摘要）；提交；停止，等待验收（11 文档 §5.4）。
