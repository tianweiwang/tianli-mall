# 多商户正常验证编排源码

当前非空历史整轮使用 `run_history_normal.py`，先查看 `python -I -B -X utf8 help/audit-regressions/orchestration/run_history_normal.py --help`。必须显式提供 `--history-source`、`--freeze`、`--freeze-sha256`、`--run-root`、全新外置 `--capture`、`--preview-project`、`--preview-origin` 和可重复的 `--reserved-subnet`。应用及旧来源、Host 摘要从已核冻结件读取，子网和 MySQL 启动参数从同包 Compose 读取；不能继续执行写死旧版本与路径的归档 launcher。

入口沿原顺序执行准备、数据库、初始化、旧数据生产、迁移、当前读取、归属、正常关闭及独立核零。归属入口 `history_ownership_normal.py` 也必须显式传 `--source`，并继续和原 context、freeze、来源审查及读取结果核同。参数化不表示当前来源的业务已验收。

运行失败先写原始 `FAILURE.json` 和实际阶段的进程、双流及终态，再尝试回收本次专用资源，结果单独写 `retirement/RESULT.json`；清理成功仍返回业务失败。删除前核对原记录、run/project 双标签、容器身份、挂载和全局消费者，正常停止后非强制删除，再核三类资源为零与预览保持。身份不明、仍有自有宿主子进程或共享消费者时，记录保留范围供接续处理，不按前缀猜测删除。

Docker 卷挂载的 `Source` 也可能是卷名；仅当 `Type=volume`、`Source=Name` 且同次完整卷清单含该准确身份时，使用 `volume inspect` 的物理 `Mountpoint` 继续原共享引用检查。普通 bind、未知卷名和路径检查保持。接续已结束运行的收尾可向同一 `retire_failed_run` 显式传全新 `retirement-*` 子目录，保留原失败及首次回收记录；不能因此启动新的业务运行。

宿主进程被强制结束或机器断电时，进程内回收代码无法执行；接续任务必须先读取已有记录、核对真实进程与资源并完成收尾，再启动新实例。旧冻结脚本和证据原样保留，不宣称它们均有自动回收。组件检查也不代替下一次实际运行的关闭结果。

每轮结束的资源复查必须覆盖本轮及已结束组件测试的实际名称和标签，包括独立创建的快照网络；不能只因 `tianli-audit-*` 为零就认定没有遗留资源。逐项核对原始身份、所有容器引用和保留原因，删除已废弃的专用容器、卷和空网络，并确认当前预览入口及其他项目资源保持。旧 B 冻结启动入口未接自动失败回收，执行者必须在原进程结束后完成独立精确收尾并保存结果，之后才可结束本轮或启动新实例。

正常验收的分段记录沿用 `INTERRUPTED_A_WITH_INDEPENDENT_COMPLETION` 五键输入。新增的 `completion.kind=ORIGINAL_NORMAL_CONTINUATION` 形式要求八键：`kind/source/source_review/result/admission/supervisor/independent_zero/outer`。它只支持完整成功前缀、唯一未完成的观察、同容器同参数续观及原业务尾段；原记录保持各自序号，不能跳过未知的创建、启动或删除操作。独立审查明确记录实际审查时间；执行后读回只能核对原启动摘要，不倒签执行前审查。

该形式以纯读取方式核完整捕获、当前构建来源、原业务顺序、关闭与独立核零；原 A 外层仍标记 `UNKNOWN/INTERRUPTED`，不补写缺失终态或原 `RESULT`。两个后续 B 入口共用同一证明，并从证明中读取实际数量、缺口和原核零脚本摘要。既有完整 A 和旧中断格式保持，原 prepare 的独立捕获也必须纳入 post-A 公共文件清单；源检查通过不表示 B 页面验收已执行。

`verify_history_zero.py` 是当前 history reader 正常关闭后的独立只读入口，仅接受显式 `--profile current-history-reader-v1`，并要求 `--context`、`--close-result-sha256`、`--close-source-sha256`、`--source-sha256` 和全新外置 `--output`。Root 须先取得原 Host close 外层自然退出0；工具精确消费实际12键 close、9键 before、11条普通关闭操作及原7/12键 native 四件的 `wait_exit`，不补造旧协议字段。原冻结 independent-zero r2 及其 PLAN 保持，工具只依赖标准库和同目录固定摘要的 `retire_failed_history.py` 纯 helper，运行不加载仓库外脚本。

该入口核 phase INDEX/原 pins、冻结双源及14件准备来源，从本次独立窗口起保全公件完整字节、原生九字段与命名空间，私有内容只读元数据；五个资源原件 `manifest.json/network.json/containers.jsonl/volumes.txt/volumes.jsonl` 按阶段正常演进，各归档自身仍严格按 INDEX 校验，其余已归档原件保持。随后仅执行六项 fresh 标签查询和安全全局 CID/名称/网络/物理挂载别名投影，全部原生四件写入 OUT，不停止或删除资源。结果不声称 R21 未产生的旧 PUBLIC-INPUTS 关闭期保全，不替代13seal→4CLI→22CLI→9HTTP的完整非作者业务读回，`full_acceptance` 保持 `INCOMPLETE`；SOURCE/compile/help 不代表真实 zero 已执行。

本目录补齐此前只保存在原机仓库外 `ms4-worker-normal-source-preparation-r1` 中的编排程序。`sources/` 按精确允许清单保存原文件的完整字节；[source-manifest.json](source-manifest.json) 记录逐文件来源相对路径、长度、SHA-256 和角色。

现用作者另需的两组固定公共 donor 也保存在同一索引：原 `project-organization-20261023` 下的 baseline PINS/Core9/B31 与 historical-normal PINS/18件，新增60件、66,018,409字节。新增行单列真实 `origin_root`，原242行及其归档字节保持；只选源码、来源索引和合成棋盘图，不递归收入原目录中的其它文件或运行资料。

正式三端构建的固定公开输入现另归档58件、27,094,591字节：原 `integrated-three-frontend-build-source-r5` 的53个源成员与原PINS，加四份依赖/Node-npm清单。总索引为360件、131,641,209字节；原302件及60个normal/B公共donor保持。新集合由独立的 `frontend_build_public_inputs` 精确允许清单核对，不递归引入依赖缓存、Node二进制、运行收据或私有材料。原声明的唯一空 `.tmp` 目录不在Git归档中创建，只在新派生包内按原11目录恢复。

`current_frontend_build_source.py` 从此归档读取原r5与四份公开authority；生成的QUALIFICATION仍保持原12键，仅将四份metadata指针映射为归档实际路径。R61派生记录中的 `public_input_pointer_rebinding` 明列原逻辑指针、实际指针、完整bytes/SHA保全和四路径逆回；为兼容现复制消费者保留的 `dependency_authority_preserved` / `npm_runtime_authority_preserved` 仅表示authority原字节资格保持，不表示绝对路径未变。实际Node、依赖目录、工具版本、输出位置及其所有构建检查仍是环境前提；源码派生不代表已构建或发布。

这是**源码归档和恢复资料**。包含旧完整编排、后续失败版本和修正后的完整编排源码。本目录不宣称可直接跨机器运行或业务验证通过。当前维护分支为 `feature/finance-refund-facts-20261102`；此前交付保留在 `feature/multi-merchant-release-config-20261027`；初次源码交接保留在 `feature/ms4-asset-consumers`；最早的 `codex/audit-base` 链接不包含本次交接。

## 当前候选的正常回归接入

`current_source_inventory.py` 与 `current_normal_source.py` 是当前候选的本机作者入口，归档 `sources/` 保持原字节。前者核完整 Git blob、两份独立干净检出和保护目录，只输出 `CAPTURED_NOT_APPROVED`；后者必须读取调用方明确指定摘要的独立技术复核，不能把捕获结果自行批准。每次来源 SHA 必须在 authority、独审、完整捕获与差量中一致，再固定到生成的 M/S；更换候选必须重新核对来源，不能沿用旧批准。

新版复用已存在的九卷 Core profile（含独立 `source-tree`），更新当前售后入口的必要固定引用及摘要依赖。旧八卷归档不通过替换 HEAD 或摘要获得新资格。新材料写入仓库外目录；旧运行、失败、退役和来源记录仍按原身份保留。完整正常迁移、顾客、测试付款、履约、售后评价、Worker、普通关闭和独立核零的责任不减；源码派生、语法检查和来源技术通过都不代表这些业务已经运行。

表单检查 helper 更新独立使用来源独审的 `customer_form_helper_update`，精确绑定唯一 `ms4-customer-form-normal.php` 的 baseline/capture 前后值，不加入原两项或四项 `active_profile_updates`。表单来源名单及 Host/zero 常量承接该行；source-tree 的七个主入口名单保持原成员集合和更新范围，其余完整来源比较和整件正逆还原保留。当前四项更新加该 helper 时，接口实际变更为原7行加1行，元数据记8行；旧 helper 未变的情形保持原九件输出。

该 helper 变化时，initializer 的同一来源行也按唯一字面值替换并核整文件逆回；派生结束前逐项核对 initializer 完整七行与 Host、zero、interface 及当前 inventory 一致，避免初始化阶段仍携带旧 helper 摘要。旧 helper 未变化时不进入这两项补充处理。

Worker 作者保留原 `129901/f420b2…`、入口有限 fatal 诊断的 `131974/1c192ecf…`、分段释放 fixtures 校验副本的 `132395/0d0d0571…` 三个目标，并加入仅在尚未就绪时分类同身份空 cmdline 的 `133975/1cfa53cb…` 目标，自然到期准备屏障的 `142574/e9e2335f…` 目标，以及完整初始见证可逆归档的 `142676/76551d5f…` 目标；只按实际 inventory 的完整 path/bytes/SHA 选择这六项之一，空样本不写 exec_initial/ready，原最终完整 NUL/argv/title 身份门保持。同次独审的 `worker_fixtures_archive_update.before` 必须仍精确为原 `127340/9cb8a8…`，`after` 必须等于所选完整目标；Core9 与 B 源接缝均拒绝其它值。接口当前行、Host/zero 常量和 initializer 两处字面量沿所选项传播并整件逆回，旧来源保持旧目标。入口诊断只在原 shutdown 前释放 65536 字节预留并输出有界安全 fatal 分类，不改变业务、原成功 schema 或资源上限；新目标仍须新的来源捕获与独审，语法通过不代替原 Worker 255 的根因或正常运行资格。 内存生命周期修复保留首轮完整 native/gzip 字节与摘要、严格数组逆回及实际重新序列化，再释放数组后按原96MiB上限重新解码逐字节比较；只释放完整校验后不再读取的 keep/封装读回对象，512M、64MiB封装、96MiB decoded及格式均保持。正常驻留组件与后续完整 A 的资格分别验证，源码检查不代表 Worker 已正常通过。

新自然到期目标仅在真实 lifecycle 之前增加只读准备屏障：保存前驱及初始完整状态，只被动等待原 finite 键按原绝对期限自然缺失；每轮对前驱、初始和上一轮同时核完整 DB/文件及直接 strict Redis 保全，有限 ID 集合严格递减，时间链与总等待有界。三项固定缓存若跨 snapshot/cache 时点自然消失，只在临时校核副本中按严格原期限投影，原完整 snapshot 仍保留并由下一轮证明缺失。private before archive 新增 preparation，Host 不独立解释该字段；公开形状、512M和原封存上限、180秒窗口、120秒 lifecycle、正常收尾与核零均保持。固定 PHP8.2语法、真实 sealed 四项选择及专用 Redis 两轮自然到期组件已通过，旧失败保持；完整 Worker/A 仍须新来源、新 Run 实际通过。

第六项目标保留前五项来源，仅把新增 private preparation.initial 复用原 fixtures 六字段可逆归档：完整 native 序列化、gzip 解压、禁止类的反序列化逐值严格相等及再序列化字节均先回验，展开副本释放后进入原 BEFORE seal。初始完整见证、三组 DB/文件/strict Redis 比较、时间预算与自然到期行为保持，shared seal、snapshot、normal_final、512M和原64MiB整体限额均不改。此修复针对实际封存读回的内存耗尽；源码、语法及正向内存组件与新来源的实际 Worker/A 资格分开，旧失败实例不重放。

平台订单表单读取前的 Redis tag 类型检查复用已有 `m4tr_redis_type`，通过只读 `EVAL_RO` 取得实际类型字符串。当前 PHP Redis 客户端的直接 `rawCommand('TYPE', ...)` 会返回布尔状态，不能直接与 `none/set` 比较。成员完整摘要、类型、绝对过期时间及后续整库检查保持；同一原隔离 Redis 的只读观察块已从原失败变为通过，完整 A 仍须在修后来源运行，旧失败保留。

编号序列只在三个新编号的 after 快照显式请求 RAM 侧证明：创建根单、售后 apply、履约 ship。共享 Redis 观察器在同一次 `EVAL_RO` 内为原完整快照中的13位正十进制毫秒键采值，非 string 类型不执行 GET；默认 Lua、三字段主返回及完整四列行检查保持。侧证明仅含类型、字节数、SHA、正十进制判定及同次 type/绝对 expiry，精确覆盖本次目标集合，绑定完整三字段 Redis 状态；AR 再绑定完整原六字段 after 快照，主快照不加字段。消费者保留编号/seq0→1映射、正数字串与长度/SHA精确值、三个原绝对期限界和自然缺失分支；失败诊断只用已持有证明，不再晚发 GET/TIME，不输出原键或原值，成功结果仍为原29字段。R81 原失败已证明混用了两个观察时点，旧失败和R80未重现结果保留。两候选完整 PHP8.2.33 语法及三个真实 Redis resolver／同次 EVAL_RO／原 delta 正向样本已通过；探针沿既有 R80，DB/rows 为空 fixture，私有目录为只读源码样本，不能代签 SQL、App 或完整 A。临时两容器一网络已普通清退；新来源与完整正常运行仍须另行验证。

共享 paid helper 的新整件来源同时影响当前 A/B：作者以固定 PAID_BEFORE／PAID_CURRENT 和同次源码独审的 `paid_consumer_helper_update` 精确绑定，有限更新当前七项表单/运行向量、paid entry、Host/zero 常量及 initializer 两处字面量，并沿原规则整件逆回。历史 donor/previous/composite 描述及其它 entry 检查保持；不将新 helper 混进冻结 Source615，也不补写旧来源资格。新源码独审应在既有报告中给出该精确 before/after，原 authority schema 不增加字段。

资金草稿的专用迁移在原 MS6 schema 保全之后、首次 App 初始化之前执行 check→apply→check→apply；A 与 B 共用同一有限接线，原九个迁移子进程和 early 175 表历史基准不变。维护账号只新增两张资金草稿表、初始全未配置单例和三个未授予角色的权限叶；DML 应用账号独立核对完整五列表结构、初始值及全部旧表数据。旧 Gate 分支的菜单只允许这三条确证新行和自然自增三位的结构差量，其余 174 张旧表、原 schema 单例、Redis 绝对期限、SQL 模式与账户授权继续精确保全。原生回执与 Host 消费分别绑定同一次运行和完整来源，真实资金仍未启用；接线和语法检查不能替代新实例的完整正常验证。

退款正式就绪分支只接受已审 Gate 12380/f4cac597…、共享 Readiness 21017/6d1ed4f1…与退款迁移39251/24b1a48f…的完整来源组合，旧 Gate 12267/25bf37fa…保留原四源与175→176→178路径。新分支在原early175表保全后、MS6前，由原维护身份完成退款结构check→applyStructure→verify→applyStructure→verify及菜单check→apply→check→apply，并用独立receipt绑定一张空退款意图表和一条未授予旧角色的操作叶；schema精确五源及单独退款迁移均从冻结双树核完整字节。表数依次175→176→177→179，退款叶占原counter，三资金叶依次counter+1…3，完整四ID与总自增四位之外的旧菜单/metadata/DDL/类型/重复项、175旧表、角色、Redis、模式和原权限均保持。Core/B共享同一有限生成及整件逆回；履约和B准备通过原共享前置消费同一退款receipt。此步骤不执行退款、不授予新操作权限、不签新Source或运行资格，实际迁移、DML只读Gate及完整正常链仍须随后真实验证。

履约与 B 页面准备共用的前置检查也必须消费这份完整资金迁移回执，再将其严格确认的两张表加入原 175 表加 schema 表的闭合集合。仍逐项核验五个履约/售后目标、原观察模式以及付款后完整数据库和 Redis 摘要；售后与 Worker 继续继承动态完整快照，不能忽略新增表或改写旧历史基准。

B 对该 helper 使用同一独审前后值，并逐项连接 B 当前表单向量中的唯一 baseline 行和当前 inventory；只有完全匹配的旧行可转换为已审新行，不把路径直接加入通用更新清单。原 initializer 来源循环继续承接该行，其它当前来源集合和历史记录保持。

图片表单准备只接受 `B_IMAGE_FORM_QA_PINS` 中三份完整源码的组合。该分支把 `audit-ms4-ui-prepare` 已有的 `customer-form-evidence` 卷改为可写，用于原商品必填图片的真实上传；Host、独立核零及 PHP 自身的原生挂载检查共同要求这一访问模式，两个 `source-tree` 挂载仍为只读。其余角色、卷、关闭逻辑与未命中新组合的旧候选保持。浏览器 Core3 仍为后台入口、后台库和 oracle，仅其中 oracle 的完整长度及摘要更新；UI 准备入口沿原 UI8/initializer 来源向量核验。整件正逆与正常原生事实组件通过不表示新来源已批准或完整 B 已运行。原 A 必须保留其实际来源；共享 helper 的 UI 路径扩展和商家页面改动须按实际依赖分别核实，不能据此声称旧 A 或构建字节未变。

纯作者工具修复可与冻结的应用来源分别固定：应用 authority、capture、candidate/validation 和实际 M/A 继续绑定同一应用 SHA，两检出保持干净；另行记录新作者工具的完整字节摘要、提交、独立有限复核及真实执行 argv，新 normal18 独审覆盖完整派生产物。不得声称旧应用 capture 包含新工具，也不能把维护工具的工作树替代为 M 的 main/repo。

正常 Core9 的 Host 在 `verify_freeze` 内复用同次完整 `source_tree_manifest` 实际读取的 path/bytes/SHA 映射，核对各角色、准备引用及固定入口；接口和 PREP/artifact 仍按原方式读取。所有原角色集合、额外路径、Git 跟踪、seed 及比较断言保留，函数末尾再完整读取源码树并严格核同首次全部文件与目录闭集。无跨 CLI 缓存，不以 mtime 代替摘要；B、历史及业务步骤不变。各目标函数与整 Host 逆回、其余函数原字节和原九件正逆回放须通过；已冻结来源和旧运行不改，源码检查不代替实际只读校验、正常业务或完整验收。

每次 `source_tree_manifest` 对父目录按原首次出现顺序去重，逐项执行原目录/非链接/真实路径校核；全部文件仍逐字节读取并计算摘要，随后在最终 Git clean 检查前再完整校核一次相同父目录集合。没有跨 scan 的缓存，两次独立完整 scan 均保留。2026-11-02 在原冻结树的单次正常组件中，15976文件/413136077字节的完整manifest逐字段相同；父目录校核从99345次降到实际7816次（两遍3908个目录），两次耗时观察分别为9.334秒和1.634秒。该单对计时存在缓存和主机活动差异，只证明本接缝的实际结果及调用数，不代表44分钟准备总耗时已解决或Worker已经通过；完整原件位于仓库外 `finance-refund-facts-20261102-r1/worker-prep-perf-r1`。

当前自然到期目标的源码优化先在临时 guard 副本中将唯一精确新 Worker tuple 逆为原启动目标，再核原完整函数摘要；实际输出和逆回仍保留新 tuple，不接受其它函数变化。作者维护工具版本与冻结的 App 来源分列记录，不回写冻结树。

当前 `WORKER_STARTUP_READINESS` 和 `WORKER_NATURAL_PREPARATION` 的 Core9 initializer 只在 `register-worker-normal`、`authorize-worker-cleanup` 两个 proof 内复用同一次 gate 已核实的源码。先完整执行原 `m4i_worker_sources`：两个 namespace 分别遍历四根、核五组完整闭集及相同 descriptor、31个 extras、固定 donor 和全部文件字节，通过后才返回该次局部映射。原 gate 后续命中 `/workspace` 或 `/source` 中同 path 时仍严格核完整 descriptor 相等；未命中、准备引用和 `/run/ms4-prep` artifacts 继续原物理读取。原 `m4i_sourceFile`、其它调用点与非 Worker proof 的物理检查保持，无 static、跨 gate 或跨 phase 缓存。两目标函数及整 initializer 逐字逆回、其余函数保全和完整 Core9 正逆派生须通过；B/history 不接此变换。PHP语法、正常源码块组件和完整真实 gate/Worker 运行分别记证据。

2026-11-02 的实际正常源码块组件使用相同原冻结树双只读挂载、固定公开 binding、PHP 8.2及512M内存限制；原版539.180秒/87663次物理文件校核，候选130.848秒/21933次物理校核，并增加65730次同 gate 完整 descriptor 相等校核。除随重复读取减少的物理文件/路径检查计数外，其余原闭集、双 namespace、目录及 donor 校核计数全部相同；两进程均自然退出0、stderr空，两个临时 helper 均已正常移除。新 initializer 与该组件的 PHP 8.2语法、完整 Core9 派生和正逆回放也已通过。结果见同外置目录 `gate-comparison.json`；这是单对正常源码块测量，不执行完整 gate 的身份/环境前置，不连接App/DB/Redis，不代签Worker、完整A或44分钟全准备流程，也未重放旧失败。

同一作者的 `--b-source <新目录> --b-inputs-file <固定输入JSON> --b-inputs-sha256 <摘要>` 有限接入原13卷B来源；仍须提供 `--authority-file/--authority-sha256`。输入绑定真实M及外层双流、成功A的外层终态/正常结果/关闭/独立核零/后续历史五项证明，以及当前五材料和三端实际构建依据；A未通过时不生成B。来源包沿原17成员和浏览器合同固定完整摘要，两个Root入口在执行时读取独立技术复核，这不是新增用户确认。原A默认派生、历史记录、业务检查及停止样本范围保持。

同一入口的 `--post-a-history <全新外部目录> --post-a-inputs-file <固定公开输入JSON> --post-a-inputs-sha256 <摘要>` 配合原 authority 两参数，收集成功 A 的公共记录。输入固定为 `schema/scope/source_authority/normal/A/post_a_contract/runtime_root`，scope 为 `CURRENT_POST_A_FIXED_PUBLIC_INPUTS`；normal 复用原 M 四项，A 提供真实 session、outer 四件及 context/normal_root_result/worker_close/independent_zero。工具先消费外层自然 exit/wait 0，再复用当前 M/A 关联与原协调器回执；不会启动、重跑或清理实例。A 驱动自身已串行执行普通关闭和独立核零，不能另行重复调用。

正常 M 外层同时支持原完整复制布局，以及显式传入其独审指针时的精确七键 created／九键 terminal 投影。投影逐项核同四个进程身份字段，并真实读取正常来源独审、核 created 的固定 scope 与 longpaths=true、原十一项 materialize 参数及 launcher/cwd 关系；原自然 exit/wait 0、完成时间和完整双流检查保留。A 与 B 自身外层继续使用原完整复制规则，原回执不补写。

正常 M 的已知毫秒回执单列严格分支：created／terminal 分别精确匹配十键／十二键字段集合，七项共同身份必须相等，并核进程内 longpaths 记录、原 safe.directory 注记、应用 Source 与独审一致，以及完整 launcher 字节等于独审成员。时间只在原 started_ms／finished_ms 单位中比较，不换写为纳秒；仍须显式独审、原 materialize 十一参数、自然 exit/wait 0 和完整双流。原纳秒分支保持，未向 A/B 的其它回执开放毫秒布局。

派生的 Root M 在 `current_known_normal_root` 前检中也显式传入已有 `CURRENT_B_NORMAL_REVIEW`，与来源复核入口使用同一严格消费者；不会因缺少第二参数误拒有效的 M 投影回执。

`post_a_contract` 保留原六键：`header/files_key/retained_public_files/prior_public_inventory/public_roots/public_root_files`。六棵公共树和十三个固定公件从当前绑定推导，与输入全等；显式选定的 prior 清单原件及全部原行、retained 和当前公件形成完整并集，重叠路径必须字节相同。显式未来 B 的 runtime_root 只用于原隔离边界检查，不创建 B。所选完整并集复读及原六键消费者回读通过后，依次保留 `POST-A-PUBLIC-HISTORY.json`、`POST-A-CONTRACT.json`、`PUBLIC-INPUT-WINDOWS.json`，最后发布 `RESULT.json`；失败时保留已有输出，不能覆盖重用。回执单列实际维护工具摘要，既不改写冻结 App 资格，也不代表 B、页面或完整验收已经通过。

2026-11-06 起，本轮输入按当前版本的实际依赖闭集选定，避免继承与当前运行无关的几十万条旧归档。当前 A 的 864 条完整公件由真实分段证明导出为独立 prior 数据清单，并完整加入 retained；当前来源、M、A、续段、关闭、独立核零、六树十三公件及原 B 的 184 条协议、35 条旧来源、30 条准备失败公件仍须按原消费者检查。旧历史清单、合同和结果保留原件及叶指针，不递归带入无关子行。本轮的“保持不变”只指显式选定并实际重读的闭集，不表示重新核验未读的旧归档子文件；旧结论保留原验证范围。该输入调整不改业务断言、消费者循环或冻结来源，不代签正式 B 或任何未运行的验收。

同一作者的 `--history-source <全新外部目录>` 配合原 authority 两参数，组合已归档的非空历史组件。固定 overlay 的原 13 件末尾只加 reader，保留 producer 的 13 件／11 控制文件合同；reader 使用独立的 12 项控制文件，完整包为 14 件。内部 `history_host_source.py`、`history_reader_source.py` 只作固定原件的有限字节变换，核整件正逆和非目标成员保全，没有独立 CLI，也不运行 Host、应用或资源操作。

输出保存 before/candidate、实际作者及内部模块原字节、完整输入来源、publication、标准正逆 patch 和全成员摘要。publication 只是来源清单，运行仍须另有真实外部五键 source review。当前 Host 在 prepare 前核该 review、四键 publication 与七项实际绑定，将两份来源原件完整复制到 Run；后继步骤和 reader 重核同一原件。`current-structure-upgrade` 已接原 37 步 seal、四个 CLI 与 producer/driver 两次真实退役前置，再沿原 16 个结构 CLI 和完整数据库、文件、Redis 观察升级；reader 仍须核 173 表基线和原九项 HTTP，最后普通关闭、独立核零。源码接线和来源采用不代替这些实际运行结果。

历史 initializer 的默认运费模板在 MS2 结构迁移后含第八列 `mer_id`。来源变换保留原安装 SQL 和七列 canonical 值比较，使用固定 `mer_id => null` 的完整八列行生成快照预期，并增加全行严格比较；原全库、Redis、metadata、DDL 和自增值检查保持。旧七列预期已用实际正常行在 PHP 重放失败，修后表达式通过；新的完整 initializer 和非空历史链仍须在新来源、新隔离 Run 中实际验证，旧失败不改签。

历史文件准备 CLI 会解码完整双源清单并整读源码；实际 R8 在 PHP 128M 上限退出255。其固定字节变换只在原入口检查后、首次完整资格读取前，核原128M并将本进程预算设为512M、核返回值与回读；共享 Compose、挂载、命令及业务 HTTP 默认预算不变。该 CLI 同进程的旧 App 初始化也使用此预算，所有原数据与文件检查保持；新完整运行仍须实证，原失败不重启或改签。

历史 producer 的固定直接入口采用同文件已有的 `nhep_normal_history_main()`；其固定原件摘要、末尾唯一接缝和整件逆变换均须核同。主函数在原 `audit_db()` 创建 observer 后显式设置 `PDO::ATTR_EMULATE_PREPARES=false`，严格核 setter 返回 `true` 和属性回读为整数 `0`，再接原 Redis 与 pre37。原 DML 账户和身份断言保持；同文件 startup、原 37 步 HTTP、正常 seal/close 及其余来源和业务断言保持，正常 CLI 参数及共享角色配置保持；最终公件的本机文件系统接缝见下段。新包仍为十四件，八件变换、六件整件保持；采用须另行独审、生成新 freeze 和新 Run，旧 R9/R17/R18 失败不重启或改签。同原镜像、私有配置及公共 factory 的新鲜连接已实测原属性为整数 `1`，显式设置后返回 `true` 与整数 `0`；完整新历史链仍须实际验证。

历史 producer 的最终安全公件先在既有 `/legacy-runtime` Linux tmpfs执行原严格0700目录、0600文件、create-only、同句柄/stat9及完整读回，再将同一RAM安全字节导出到固定宿主路径 `/run/audit/evidence/legacy-history-producer-result.json`。Windows宿主绑定目录实际为0777，不能冒称Linux私有目录；公共出口核真实owner、类型、单链接、设备、canonical、handle/path身份和完整写读，并核父目录身份保持。原qualified/exact13/schema/status/source/run/freeze门保持，无任意路径或通用writer参数。已在同固定PHP8.2镜像上通过真实语法和单次正常合成组件：私有0700/0600、宿主目录0777、公件0600、完整字节相同，两个helper均自然0并普通移除。组件明确不是历史完成seal；旧失败Run不续签，新完整历史运行仍待。

HTTP 包装层仅在原失败 catch 内格式化一条不超过2048字节（含换行）的 CLI STDERR 安全诊断：固定 scope/status、受限异常类名和 NHE/NHEH 常量 code、固定来源标签/正整数行号及前16帧中的首个已知 callsite，以及 `step_entries_count`、`last_entry_sequence`。这两个数字只反映原 `nheh_step` 已追加条目的序号，0条时后一项为 null，不表示尝试次数或完整业务验收。原包装 code 过滤、throw、外层 cleanup/exit、成功37步和13字段 seal 保持；无原始 message/path/trace/args、请求/响应、配置或凭据输出，诊断错误不替换原失败。原 R18 具体失败原因仍未知。

同一直接 CLI 入口为运行时 fatal 诊断保留 32 KiB，并注册 shutdown handler；仅实际 `E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR` 时向 stderr 写入至多 2048 字节 JSON 加换行。只记录错误类型、文件标签及路径摘要、行号、严格格式的内存上限、堆用量/峰值，以及匹配固定内存耗尽消息时的两项字节数，不输出原消息、堆栈、参数、环境或 SQL。正常返回和非直接 CLI 调用静默，handler 不改退出码或内存预算，不安装到独立 HTTP 子进程；脚本在 handler 注册前的解析/启动失败无法由它捕获。原 R10 的退出 255、空双流不能据此追签为内存不足，后继新正常运行才提供诊断证据。

R11 新正常运行的安全 fatal 实际证明父 producer 在 128M 限额读取完整来源时耗尽 PHP 内存；原失败保持。因此，仅在同一自文件直接 CLI 入口完成上述 handler 注册后、调用原 main 和首次完整资格读取前，严格核原 `128M`，将本进程 `memory_limit` 设为 `512M`，并核 `ini_set` 返回原 `128M` 与回读 `512M`。不改 Compose、Host、配置文件、环境、命令、全部业务断言或独立 HTTP/channel 子进程的默认预算；32 KiB reserve 和原诊断代码保持。原容器 `Memory=0` 只说明未设置该 cgroup 限额，不证明宿主容量充足。语法与静默正常入口检查不代签新历史 Run、37 步或后继验收，停止的 OOM/故障样本不主动运行。

B的两个网络使用固定输入 `network_subnets.isolation/ui_web`，只接收互不重叠的规范RFC1918网段并核实际IPAM。Root启动前仍须检查地址空闲；复用A网段须先有真实关闭及独立核零。部署public采用同一根部install排除投影，完整安装器来源仍保全；三端构建保留各自真实来源，只有完整输入一致才能复用。

B Root M 的 proof 配置回执保留原 Host `PROOF_INI_REFERENCE` 的真实绝对路径、字节数和摘要，不随新 B 包的 `PREPARED` 目录迁移。作者核原/派生 Host 指针完全相同并读取原回执，只替换 `frozen_resources` 内这一处路径表达式；不复制旧回执或放宽单文件、内存值、角色、扫描目录和完整资源比较。B 工具修复独立记录实际工具字节，不改已冻结的应用来源或重启正在运行的 A。

B 浏览器接线沿 `derive_b_profile` 作有限派生：Task2 使用同一真实 owned tab、自然完成的 HTML 文档 URL 和原生事件的完整 `{tabId}` 来源绑定。保留原始事件、序号、请求 ID、完整响应和两类 ExtraInfo，以及原 clean/readiness 的零未分配事件检查。当前工具不支持的 `Target.getTargetInfo` 与直接 CSS 文档导航不再作为该接缝的前置；不补造 targetId，不修改通用网络采集器或业务场景。

DOM 截图从同一真实 tab 的 CDP `Page.captureScreenshot` 显式取得原生 PNG，仅作有界、canonical base64 解码，随后沿原 PNG 解析与原字节保存路径。PNG 签名、CRC、IHDR、zlib、像素尺寸、DOM 前后稳定性、调用时间、隐私、布局、三种宽度及关闭/核零检查保持。标准截图接口的 JPEG 输出不伪装或转码为原 PNG。原 browser8/PINS 和交叉摘要继续重算并完整正逆核对；新工具字节另行固定与独审，应用来源及其实际 A 资格分别保留。

隔离初始化只创建原安装表结构，按声明准备测试资料。原九个迁移完成后、退出权限及全部 early 快照之前，`isolated/lib/ms4-order-menu-fixture.php` 从当前已审安装 SQL 的唯一菜单块解析四个原订单锚；不执行安装 INSERT。四锚使用数据库生成 ID，按实际父 ID 映射 `pid` 和页面/分组的 `path`，其余全部字段保留原 SQL。normal-A 固定调用既有 `marketplace-ms4-order-read-menus.php` 的 check→apply→check/reapply，B 固定只准备四锚，原 UI 阶段仍须首次登记两个读取叶。此差别由作者生成的源码常量确定，不提供运行时开关；AR、B UI 与独立 oracle 的既有权限断言保留。

`order_menu_receipt.py` 由作者按本次冻结来源及完整摘要读出、原字节嵌入 Host，消费同一 initializer 的独立回执；不增加运行时 import。回执绑定同 Run/CID/source/freeze、四锚完整源字段与真实 ID 映射、A 原两叶迁移结果或 B 严格未创建状态，以及全部旧菜单、角色、其它表、结构、Redis、模式、授权和原私有配置保持。仅允许本次声明的四条（B）或六条（A）新菜单；现有角色不追加授权，不导入其它原接口。

精确的新退款 Gate、共享 readiness、退款迁移与已审 fixture/receipt 新源码对共同选择五锚分支：在上述四锚之后、原读取叶之前，从同一安装 SQL 解析原897退款页面完整22字段，使用数据库自然 ID，并将 `pid`/`path` 映射到实际订单根。新 A 仅增加这一个页面锚成为五锚加原两读取叶，B 仅五锚；原四锚 read-migration proof、旧来源整件派生、角色授权和全部保全断言保持。未知源码组合拒绝；旧53四锚失败来源不因本工具更新获得新回执或业务资格。

同一新源码对也显式选择历史两锚准备：沿已绑定当前安装 SQL，在37步前准备原 ID4订单根与原 ID897退款页面的完整22字段原链，保留原七个商家操作叶、角色及其它材料；冲突拒绝，不使用 upsert。该分支是升级前的旧材料准备，不扩大后继13来源、22CLI、174表或唯一退款操作叶的允许差分。历史默认和旧源码对保持原派生；参数不作为运行时开关。两锚producer从`/workspace/crmeb/public/install/crmeb.sql`读取与initializer的`/source/crmeb/public/install/crmeb.sql`同属当前冻结应用的安装SQL，并保持原整件长度/SHA、完整行、角色及metadata断言和独立旧应用来源绑定。两锚producer的商品`give_integral`沿旧安装SQL的unsigned int/default 0及原native metadata合同严格比较整数`0`；订单`gain_integral`仍保留decimal字符串合同，其余创建分支条件、fixture及旧11/无锚13派生不变。

同一现行两锚13分支仅在两次原余额支付的同一curl上接私有during闭包，并与原before/after按同producer/冻结controls核Reflection。进度回调复用原EVAL_RO完整Redis捕获，按采样完成时hrtime限频且无attempt截止，仅各保留首份存在键见证（不筛错值），最多两份加一份临时capture；状态绑定同pending/请求/序号/BEFORE摘要/Redis客户端与DB0，逐step清空。采样或桥接错误sticky，回调仍返回0，原after七部捕获后拒绝；不改原37、11键response、单次HTTP/3秒连接与15秒总超时，不sleep、重试、改TTL或保证必见证。after键仍在按原wire/期限核且与首见证完整DUMP/期限相等；缺失有见证须原生微秒顺序/同Run、真实wire/源TTL/HTTP窗合格且after严格晚于其已观测期限，无见证仍用严格最晚界（仅LOCK可用已独立验证pay_time收紧，coupon不可）。有限安全诊断仅增加NHEPOPR与定形非私有family/TTL/时间字段；不保存Throwable、key/token/DUMP或私有pending。模式只由既有两锚参数显式传递，无新public开关；旧11/无锚13的producer、HTTP、initializer全字节保持，原冻结来源不改签。来源/纯组件和语法不代真实正常历史链。同一新Gate的initializer仅在refund_bootstrap失败catch补原m4c_entry_same调用点行号：只取固定customer helper文件/函数的正整数行，先过滤去重再最多8条；提取失败不盖原Throwable并原对象rethrow，不保存trace/args/message/值，不改成功调用及旧四源派生，也不把callsite当根因。

新商家退出的两权限叶在原 initializer 的九个迁移子进程全部完成后、private cleanup 与完整 source-tree 复制前调用原迁移的 check→apply→check；该点仍早于首次 App 及所有 paid/early 快照。沿原维护账号，不追加角色授权；独立回执绑定同一 run、CID、freeze 和三份完整当前来源，逐行保留旧菜单，并核除两条新叶以外的全部原表数据、表结构、Redis、SQL 模式及账户授权保持。Host 在原 initializer 结果消费点验证回执，后续原菜单全量保全继续使用含两叶的真实 early 快照，不过滤新叶或改写旧 hash。新实例必须实际执行；本机 R65 迁移回执不可跨实例复用，源码派生与语法通过不代表数据库或完整 M/A/B 已通过。

当前应用要求 MS6 版本表。普通验证沿现有 files-bootstrap 容器和专用维护账号，在原175表先决条件之后、首次 App 初始化之前调用原 MS6 迁移的 apply/check，再由 DML 账号读取同一版本记录。新增回执嵌入原 files-bootstrap 结果，由原四件证据链继续传递；原表、Redis和SQL模式保持检查保留，表清单只精确增加这一张元数据表。新独审必须逐项列明售后及顾客消费者的 before/after 固定引用；旧 Run、业务版本门和应用权限不变。

手动装配应用的旧CLI及订单测试先装入真实provider、应用配置和database，再启动AppService，继续经过生产版本门；共享订单装配函数的商家浏览器准备沿用同一顺序。原 `ms4-offline-postage.php` 只注册组件并执行原共享计算例，不启动完整AppService；它的结果仅证明运费方法兼容，数据库、身份、HTTP和Schema验收仍为NOT RUN。

`current_frontend_build_source.py` 复用已有三端构建器，接收同样明确固定的独审依据。它更新当前完整 Git 清单及资格引用，保留原依赖依据、工具和构建命令；作者不运行构建。实际三端构建及 B/UI 仍需各自验证，不能由来源派生代签。

该作者可选 `--windows-copy-workers 1|4`，默认1保持原派生产物及元数据逻辑。4只在新的来源包中将 Windows 依赖复制改为标准库四线程、有界提交；每个文件仍执行原完整读取、哈希、独占创建、读回及单链接检查，原前后整树、文件身份和全部构建命令保持。所有线程退出后才继续校验或记录失败；失败现场保留，但不保证残留按严格串行前缀排列。派生元数据明确标注 run 与局部 copy_one 的有限变化，并以整文件逆还原核对；不修改旧来源或正在运行的构建，也不改变 H5。四线程的实际耗时须由后继正常构建测量，来源派生不证明提速。

`current_ui_material_source.py` 沿固定 r3 五材料 producer 派生当前报告读取接缝，不执行构建或材料生产。新 producer 保留原 `--paths-json` 和 `--output`，另须显式提供 `--current-build-proofs` 及其固定 `--current-build-proofs-sha256`。证明文件分别绑定三端真实 builder 包、PINS、资格、Run 与外层原生回执；本代应用来源须与独审一致，各 builder 保留自己的真实来源。未完成真实三端构建时，只能取得源码候选，不能借旧报告字段获得执行通过。旧 metadata 只保留原固定图片与历史依据；原输入反复核读、PNG、碰撞、五材料和四 manifest 输出合同保留。当前布局读取使用真实 `DIST-TREE`、依赖目录和原生四件，不生成旧成功字段；H5仍核原双 pass、工具与普通清退。Windows跨API身份只为四种普通可执行扩展名规范化比较用的FD执行位，双方各自完整观测及其余身份不变。实际材料生产与B/UI须后续独立验证。

H5 仅接收已独审的四个固定 LX 别名属性后继算法；原 r5 只作有限派生依据。producer 只读核对实际 before/after 原始回执、完整身份和原生查询流，原始属性只接受1056或263200；当前重算仅比较稳定字段，不改写历史回执。无需新增 CLI 参数，实际 H5 完成证明仍是运行前提。

部署 public 只排除根部 `install/`，同名嵌套目录仍包含；安装器另以原 reader 完整读取并随原 finish 复核，included/excluded 两套完整目录、文件哈希及摘要均绑定同一 capture，写入现有 `producer-result.json.public_source_scope`。原逐文件 input-inventory、五材料、10键 profile 和 `NO_PUBLIC_ENV_FILE` 保持。后续 B 的 `ms4-ui-public-copy.php` 在 `m4uic_copy` 的完整 public 比较及末尾全树复读（原276–278、351行）须同步为完整来源保全与根 install 排除投影，既有 `install/.env` 固定模板读取/复制特例（原18–28、77–78、167–170行）也须一并明确调整；本作者不改 B，不以允许部署 `.env` 绕过检查。

作者使用 `--output`、`--authority-file`、`--authority-sha256`，仅创建新的源码候选及其正逆变更。producer 的 paths 文件仍为 `{schema:1, paths:{...}}`，路径键精确为 `metadata/source/public/h5_build/merchant_build/admin_build`。current-build-proofs 文件精确包含 `schema:1`、`scope:CURRENT_THREE_FRONTEND_BUILD_PROOFS`、`source_sha` 和 `builds`；普通构建的每端记录包含 `packet`、`pins`、`run`、`outer`，pins 是对应包 `PINS.json` 的绝对路径、字节数和SHA-256，outer 是该 Run 的原 `-launch` 目录。旧 metadata 不能替代这份当前三端证明。

Admin 另接收固定的 `PREVIEW_NODE22_ADMIN_BUILD_JSON_V1`：记录精确为 `protocol/run/input/root_build/build/outer`，后三份 JSON 与 outer 的 created、terminal、stdout、stderr 均使用完整固定描述符。它只复用现有 b1c58 构建，不运行原 builder；原构建身份与当前应用身份分列。固定原工具的11个纯定义保留，`context_read` 仅桥接原 reader；含多行配置字符串的 `parallelism_addition` 顶层原文保持。当前完整 capture、1099件原编译输入及 helper、原 work 的唯一配置变换、原命令和完整输出流、dist 和三次输入复核均须通过。原普通四键路径保持。

该 Admin 协议的 `current_frontend_bytes_match_original_builds` 表示当前编译输入与原输入逐件相等；dist 哈希范围明确为 `PACKAGING_TIME_OBSERVATION_NOT_ORIGINAL_BUILD_HASH`，包锁证据不替代原始完整依赖树回执。`_b_materials` 按同一协议关联固定原记录、当前 capture、五材料和实际 dist，并核此范围标记；不补造普通构建的 `BUILD-REPORT`、`ADMISSION` 或历史依赖保全文件。针对性读取通过不代表完整材料、B、UI或发布通过。

`Inputs.finish` 仅从本次既有 tree snapshot 的精确文件 key 跳过首循环重复读；standalone 顺序、各树最后完整字节/FD/目录复读与三阶段 finish/alias 调用保持，不使用 mtime 或跨调用缓存，冻结来源不改签，提速须以后继正常运行实测。

本代应用来源从调用方的真实独审 authority 动态固定。允许复用较早完成的真实构建，但逐端保留其原报告 head、资格、builder authority 和全部原生 argv；按原 `frontend_source` 规则推导的完整输入、目录、Git mode/blob、字节数及SHA-256必须与本代来源完全相同，任何差异都须重建该端。prebuild/helper、编译器、lock和原工具依赖仍核对；public另按本代候选完整清单验证。结果分别记录原构建来源和本代输入等价关系，不宣称旧构建是在新 head 上重跑。

Docker 默认地址池耗尽时，可在 profile 作者的 `--output` 阶段指定 `--isolation-subnet`，将一个规范 RFC1918 IPv4 子网固定写入新来源的内部网络 IPAM。生成前和实际启动前须检查现有 Docker 网段及宿主/VPN/WSL 路由；作者本身不证明网段空闲，也不自动尝试其他地址。启动后核对实际 IPAM 与绑定子网，保留 `internal: true`、原标签和九卷拓扑；不修改 Docker 全局设置或既有网络。未指定此参数时，原生成逻辑保持。

执行顺序和实际结果继续只维护在现有 [PROGRESS.md](H:/天俪商城/artifacts/project-organization-20261023/current20261025-business-progress-r1/PROGRESS.md)。本轮正在接入完整编排；未获得新 M/A/B 结果前，不直接启动旧 `launch.py` 或把 profile 的来源常量非空视为运行资格。

## 从 Git 离线核对

在仓库根目录执行，使用 Python 3.14（与原检查环境一致），无需第三方库：

```text
python -I -B help/audit-regressions/orchestration/verify_sources.py
```

成功输出 `PASS_SOURCE_ARCHIVE_ONLY`。检查会完整读取清单内文件，校验长度、摘要、成员集合及 Python AST/JSON，并检查旧主编排与当前后继各自的完整 18 件源码。它不 import 或执行存档脚本，不连接 Docker、数据库、网络，也不读取原机目录。

该命令在只有本目录的干净 Git 导出中也应通过。Python 接缝片段不是独立程序，与 PHP、JavaScript、YAML 等原件一样在该命令中只作字节校验；完整 Python 脚本才作 AST 解析。它不代替其他语言检查、真实环境或业务验收。

现用 `current_normal_source.py` 通过同一索引的固定白名单读取这60件公共输入，不按文件是否存在选择原机路径。原来源的逻辑描述与真正读取的归档文件指针分别记录，并严格连接原长度和摘要；authority 的逻辑 baseline 描述不变，旧冻结包不改写。新 normal 的历史源码集合固定为 baseline PINS、normal PINS、materializer、driver 和 profile publication 五项；新派生文件明确保存实际归档位置。post-A/B 的公共源码和逆变换前件同样指向归档原件。INI源码复用原已归档的18字节配置，未把它等同于原运行时内存检查。

新作者按自身真实 Git 目录定位同级索引与 `sources/`；只复制这一个 Python 文件不能替代该完整来源目录。已冻结的旧作者副本仍沿其原依赖合同使用。

消费既有合格 normal 时，只兼容原 `historical_source_origins` 键不存在、且整份四项逻辑来源映射与固定原 PINS 完全相同的合同：normal PINS、materializer、driver、profile publication。此时读取同摘要的四份实际归档原件并明确记 `LEGACY_FIXED4`；有 origins 键时必须是完整新五项合同 `ARCHIVED_FIXED5`。两种布局及实际来源均绑定生成代码中的固定常量，不接受空 origins、任意键、按存在性搜索或仅由输入自报摘要；既有 binding、来源独审、M/A外层身份、正常关闭和独立核零要求保持。

这只补齐现用作者选择公共 donor 的源码依赖。来源批准、真实语法/构建/付款/普通关闭与独立核零回执、十一份历史退役依据，以及固定 `PROOF_INI_REFERENCE` 对应的原实际证明仍属外部证据。它们不会由源码归档重新生成、降为可选或伪造成功；仅克隆 Git 仍不代表可以立即执行完整业务验收。

## 归档入口

| 来源包 | 已收程序与配置 | 状态 |
|---|---|---|
| `full-a-raw-env-normal-next-source-r1` | materializer/prepare.py、launcher/launch.py、validation/run.py、实际九件 profile、作者源码和接缝 | 最后实际使用的完整主编排，绑定旧提交 `53e6d521b`；原付款进程失败 |
| `full-a-paid-fulfillment-contract-normal-next-source-r1` | 两个作者 Python、候选九件 profile 和设计说明 | 绑定修复 `76efa7807` 的中间版本；该包只生成 profile，完整后继在下面两包 |
| `full-a-published-current-normal-next-source-r1` | 完整 materializer、driver、launcher、实际九件 profile、来源控制及作者源码 | 绑定 `584532ba2`；实际 materialize 在源码冻结时失败，业务未启动 |
| `full-a-published-current-normal-next-source-r2` | 修正后的完整 18 件、profile 原件与变更、完整作者程序 | 同样绑定 `584532ba2`；修正活跃 caller 版本引用，新 initializer 纯语法检查通过，未执行新 materialize 或业务链 |
| `fresh-b-customer-form-external-source-r3` | Root 编排、Host、初始化、资源复制、关闭与核零、浏览器程序和静态合同 | 草稿，尚未完成当前来源绑定与 UI 运行 |
| `nonempty-history-upgrade-source-r2` 与 `nonempty-history-pre37-*-components-source-r1` | 原历史升级程序、配置、actors/app/config 后继源码 | 供恢复源码调用链，不构成新的已采用集合 |
| `nonempty-history-private-prepare-components-source-r2` | 私有演员随机准备的初始化程序与配套挂载配置 | 只含生成程序，不含实际演员、口令或环境文件 |
| `nonempty-history-normal-close-components-source-r1` | 完整 producer、Channel、公开视图接缝及合同 | 已有原独立源码核对，未签业务运行 |
| `nonempty-history-normal-parent-components-source-r1` | 完整 parent producer 和接缝 | 保留 LOAD-01 缺陷与未完成的 startup 检查，供追溯 |
| `nonempty-history-normal-parent-components-source-r2` | 完整 parent producer、作者程序及正逆变更 | 修正三组件固定装载；独立源码核对和 PHP 纯语法通过，未签业务运行 |
| `nonempty-history-normal-startup-components-source-r1` | 完整 producer、启动检查、作者程序、正逆变更及公开上游源码依据 | 原独立源码与对应完整 PHP 8.2 语法已通过；业务及最终准入仍待 |
| `nonempty-history-host-handoff-components-source-r1` | 完整 Host 和交接接缝 | 最终资格未绑定，原拒绝入口保持 |
| `nonempty-history-host-handoff-components-source-r3` | 后继 Host 完整源码与原 PINS | 修正旧订单唯一号类型检查；七项最终绑定与运行入口仍未闭合 |
| `nonempty-history-current-reader-private-context-components-source-r2` | 最新 reader、原 PINS 与完整组件映射 | 对应完整 PHP 8.2 语法已通过；当前角色与最终采用仍未接通 |
| `legacy-current-reader-runtime-source-r2` | reader 阶段的旧 Host、Compose、initializer 及 SOURCE 记录 | 仅供有限接缝复用；旧 producer 消费合同不能覆盖最新 Host |
| `full-a-paid-producer-failed-close-source-r1` | 原失败实例的正常关闭、独立核零源码 | 原实例已经退役；不得用旧程序操作新实例 |
| 三个 customer-form 来源与 proof INI | 原精确 PHP 快照及内存配置源码 | 只保存源码，不替代原运行回执 |

新增历史来源按原路径完整保存，未复制运行数据、环境配置或账户资料。最新组件映射中的 producer 13 件与 reader 的额外来源均可在归档中定位；旧 reader Host/Compose/initializer 只提供阶段接线参考，不表示可直接整包运行。原37步、四次迁移、producer与reader各自的控制文件合同及旧数据保全要求继续保留；新增归档及纯语法通过均不代表这些流程实际完成。

当前 `--history-source` 继续通过同一作者及三个内部纯字节变换模块组装14件历史候选，保留原13件 producer 投影和11项控制，reader另核12项控制。有限接线涵盖原37步/四次迁移、独立166→173结构升级、reader实际资源登记与九次读取、各角色普通退役及最终关闭/独立核零；其中16条结构CLI与旧数据保全由 initializer/reader分别核对。该源码包保存作者原字节、完整正逆变更和11项当前迁移依赖；生成候选不签发运行资格。

最终采用须由独立审查绑定真实14件 publication 和七项来源。Host 在创建资源前复核，并把两份审查原件按原字节发布到新 Run 的固定公开别名；initializer 与 reader 从实际挂载逐件复核来源，新增两原件追加到原100项归档。升级入口另核完整旧业务结果、四次迁移及两个角色的真实普通退役，再登记当前维护角色；reader 仍在 HTTP 前核实际173表基线。缺少或不符的实际依据应拒绝，不能用固定字段或空值冒充成功。`--history-source` 可沿现有 `--isolation-subnet` 固定私有 IPv4 子网，生成前与启动前各自检查冲突，并在实际创建后核对 IPAM；作者只生成源码。源码实现、语法和正逆检查不表示历史流程已经运行通过。

历史作者按已审Gate完整来源选择旧11依赖/16CLI/166→173，或新f4ca Gate、6d1e Readiness及24b1退款迁移的13依赖/22CLI/166→174；三个内部变换器的refund_readiness参数仅由此固定组合传入，默认旧initializer、Reader及Host整字节保持。新分支保留原12项迁移后，先执行退款check/apply-structure/verify/check-menus/apply-menus/check-menus六项原CLI，再执行原MS6四项；逐项原生四件/完整结果成功后才继续。只新增一张空退款意图表和一条未授旧角色的操作叶，完整保存旧166表及原退款旧列/新增nullable列、全部旧菜单类型/重复项/metadata/DDL与唯一自然自增、角色、Redis期限、文件及私有配置；Reader从最终实际全行独立复核，不套175表Oracle。额外six-native闭集、174表及新菜单回执均由Host/Reader/归档同步严格消费，不改变原37步或13seal。退款两锚分支的reader注册严格核对升级后的完整174表，旧11依赖及无两锚旧13依赖分支保持原173表断言和完整输出，其余表清单、数据与控制文件校核不变。

新退款 Readiness 要求实际读取连接使用原生预处理。QA 仅在传给 Readiness/Gate 的局部 PDO 上关闭 `PDO::ATTR_EMULATE_PREPARES`，随后保留原检查；独立的维护连接设置不会传播到新建的 `audit_db()` 连接。normal 的新退款来源分支和 shared 顾客退款/结构回执读取均按原来源选择处理；history 的两个连接接缝仅随现有 `legacy_refund_anchors=true` 的新五锚来源分支启用，旧11及旧13默认整件输出保持。退款菜单完整观察器还仅在自己的专用连接上设置 `SESSION information_schema_stats_expiry=0`，与原 early/finance 观察器一致，以读取实际原生计数；其他分支不新增此设置，MySQL 全局不变。全局 guard、产品 Readiness、账号权限、SQL 模式、原断言和已冻结 Run 均不因此改变；源码与语法检查不代替新正式完整运行。

normal 新退款 Gate 的 `m4i_refund_bootstrap` 在首次完整观察前，使用已有 `audit_app` 连接按 `SHOW CREATE TABLE`、`SELECT *` 顺序打开刚创建的同一退款空表，严格核 DDL 形状及真实空行；随后保留原 observer 和 receipt 的完整元数据比较。同 MySQL 8.0.45 原生空表组件已观察到首次打开前后 `AUTO_INCREMENT` 从 NULL 变为 1，先打开后连续捕获全字段一致；源码不转换该值、不省略字段、不重试或增加权限。旧 Gate 输出及原业务后缀保持；此组件不是原 A3 丢失叶值的重构，也不代替后继完整正常运行。

共享历史响应头模型还按冻结旧App启用的LoadLangPack与SessionInit精确接受各一条原生cb_lang=zh-cn和32hex PHPSESSID Cookie，两条均仅path=/。原完整字节/CRLF、单响应块、无截断、Cookie属性与Session未复用校验保留，未知或重复Cookie仍拒绝；不关闭语言Cookie、不改旧App配置。此共同修复作用于后继producer来源；原R19失败原始头未保存，正常原生Cookie组件不代表该旧响应已重放或37业务通过。新工具来源、语法/纯组件和真实历史新Run资格分别保留，停止样本仍不执行。

失败历史 producer 的普通退役使用 `python -I -B -X utf8 help/audit-regressions/orchestration/retire_failed_history.py --help` 所列单角色入口。执行时必须显式给定并固定 SHA256 的 `--host`（仅支持已审201800字节历史Host）、`--context`、`--source-authority`、`--preview-baseline`，以及原 `--freeze-sha256`、`--review-sha256` 和全新外部 `--output`。它只处理尚无成功seal或后继角色的原producer退出1、两base、六卷与一网；沿原Host普通stop `--timeout=-1`及非force删除，不调用成功历史关闭。所有权始终使用原context，仅原native记录落点重定向外部OUT；原Run应零追加。预览只在RAM比较8个容器全部27字段、Cmd及完整Mounts多重集，公开仅保留摘要。

还必须提供外部 `--classification` 及其 `--classification-sha256`：JSON精确为 `{"schema":1,"run_id":"原Run ID","files":{"相对文件名":"public或private_metadata_only"}}`。该正向分类须先依据原prepare/bootstrap/producer写入、阶段INDEX及真实native四件逐项确认，覆盖Run完整现存文件闭集；未知JSON/日志不能默认公开，也不提供自动全公开或空私有推断。命令在任何Docker调用前核分类，私有项只取metadata，不打开或哈希正文；三棵Source/Val/legacy树沿原冻结正向名单核全字节与stat，原Host排除的Git/runtime仅核metadata。缺少分类、来源/pin或资源身份即失败且不自动重试/清退。原native四件与自动summary逐项闭合、保全和本入口fresh六查询完成仍不构成历史成功；非作者独立fresh核零与保全另行执行。该工具没有运行或生产授权含义。

失败正常 Worker 的普通退役使用 `python -I -B -X utf8 help/audit-regressions/orchestration/retire_failed_normal.py --help`。它仅支持已审402025字节正常Host、Worker真实退出255/paid退出0、两base与原九卷一网；复用实际R90普通退役步骤，保留原context归属、stop `--timeout=-1`、原wait及非force删除，不调用成功Worker close/zero，也不使用历史3/6/1入口。固定SHA的 `--host`、`--context`、`--source-authority`、`--preview-baseline`、`--classification`、原 `--freeze-sha256` 和全新外部 `--output` 均为必需输入；分类格式与上文相同，未知叶在Docker前拒绝，私有项只核metadata。

该入口复用未改的 `retire_failed_history.py` 纯快照/preview函数；运行时不依赖归档R90脚本路径，原17945字节/SHA256与实际22392/0仅作为实现来源记录。原native四件及summary全部外置；原INI观察函数只替换两处输出根，全部原Run/source/reference/512M输入与原生分类断言保持，四角色20观察叶只写OUT。原Run零追加、两棵完整冻结树bytes/stat及8预览27字段/Cmd RAM/重复Mounts均须保全；真实清退0后仍需非作者独立fresh六零、全部历史CID及物理alias复核，不能签Worker或完整A通过。

历史种子来源记录也必须连接当前已审的完整 `ms2-migration.php`。作者先核原记录、两个原种子函数的完整摘要、五个来源检查调用与整函数逆回，再仅更新候选记录的整文件来源摘要；原种子代码、函数摘要、Host 断言和存档原件保持。实际整文件来源及旧摘要另记在输入证明中，不能直接把失败记录改为成功。

历史退款详情的独立模型在现有 `legacy_refund_anchors=true` 分支中沿用原 formatter 的 `cartInfo ?? []` 临时数组遍历语义：局部计算会员价及总价，但保存的 `cartInfo` 与 `cart_info` 均保持完整原购物车，不凭空追加 `vip_sum_truePrice`。原61个顶层字段及顺序、BC金额汇总、完整DTO严格比较，以及数据库、Redis、文件和时间断言保留；旧11依赖及无两锚13依赖输出不变。原R23失败及丢失的当时HTTP正文、before不重构；纯正常循环组件只证明该模型差异，修订源码和语法不代替后继完整历史验证。

同一现有分支的历史 producer 与 current-reader 同时保留正常退款根单的五条日志：生成、付款、申请退款、退积分、退款。详情模型从独立 BEFORE 中唯一选择同根单、同顾客的保存退款行，核其原因与原 actor 的 `normal-refund-<run_id>` 及根单原因一致，再构造申请日志的完整期望文案；终态身份模型从已完整校核的根单原因构造文案，并继续核完整保存退款行，均不照抄状态日志自身的消息。精确五类、每类唯一、原生时间和范围、付款至申请至退款的顺序及完整状态行校核保留，原五项日期 DTO、四身份、164 表、Redis 和文件比较不变。只改这两份模型中的详情与身份函数，`historyTimes` 不外传；Host 无日志字段或行数合同变更。原 R24 失败材料保持；固定普通五日志组件的 red/green 只证明该模块，不能代替后继完整历史运行。

所有实际文件以清单为准，不按目录名递归导入其他原机材料。没有把整份原材料目录、原 Run 或所有历史作者输入公开。两份 published-current 完整编排保留各自来源 `584532ba2`；归档提交本身会前移 Git HEAD，不能据此宣称已在归档后的 HEAD 运行通过。

r2 原冻结设计有两句关于当前工作树的旧措辞，原件保留；必须结合[设计澄清](sources/published-current-normal-next-source-r2-design-clarification-r1/CLARIFICATION.md)读取。实际作者核对复用旧 Main 已闭合证据，并读取历史 Git 对象和 Val 的 194 项；没有签当前 Main 或归档后的提交可以运行。校验器也要求该澄清随包存在。

`sources/` 是不可改写的原字节快照，少数原件含 CRLF 或历史空白；本目录的 `.gitattributes` 禁止 Git 对快照自动换行转换，以保持原摘要。新写的验证器、清单和说明使用 LF；不对归档原件作格式化修复。

## 运行依赖和恢复顺序

完整列表见 [runtime-dependencies.json](runtime-dependencies.json)。旧程序仍含原机绝对路径、历史来源与运行回执要求。仅复制本目录不能满足这些门禁；不要直接启动旧 `launch.py`、`prepare.py`、`run.py` 或关闭脚本，也不要通过伪造回执或删除检查来“适配”。

1. 先运行只读完整性检查，取得代码、调用顺序和未完成项。
2. 结合 [当前状态](../../../docs/multi-merchant/CURRENT-STATUS.md)、[本次交接记录](../../../docs/multi-merchant/records/ms4/orchestration-source-handoff.md) 及 [09 计划](../../../docs/multi-merchant/09-plan-ms4-ms6.md)，完成归档后提交的来源绑定与资格核对，处理仍未闭合的运行前提。
3. 只有新来源、环境和普通流程均准备完毕后，按原授权范围创建新的隔离实例验证；生成新私有配置与新回执，正常收尾并独立核零。

原用户停止的安全复现、负向、故障、并发、断连和恢复样本继续 NOT RUN。本目录不恢复这些样本，不改变真实支付、生产数据和部署的授权要求。

原始收据、双流、私有环境、演员、Cookie/HAR、上传、数据库和 Redis 卷没有入库；失败历史与证据仍按原记录保留。源码交接补齐不补签任何业务、页面或发布验收。

售后正常验证的共同 Redis 差量断言若自然失败，失败结果可附 `redis_failure_context`：只投影断言当时已持有的原子快照，记录完整快照摘要、时间、键数量及首16项差异的键摘要、类型、值摘要和绝对到期时间，并标明总数和是否截断。调用位置不含参数；HTTP上下文只保留已有请求序号、方法、路由族、路径摘要及可用的白名单操作标签。诊断不再读取 Redis、HTTP、文件或数据库，不输出原键、原值、凭据、路径或调用参数。原完整比较、自然到期规则、失败状态和成功结果形状保持；旧失败没有这些快照时不能追签原因。正常路径不发布此字段，停止的故障和负向样本不用于主动触发诊断分支。

B 的两处外层回执读取器保留原 `bytes`/`sha256` 流摘要，并兼容原回执中带 `path` 的三字段指针。三字段必须与 `outcome` 已绑定的实际 `.stdout`/`.stderr` 指针逐字段相同；路径须是采用 POSIX 分隔符的规范绝对路径，严格解析后原串不变，且后缀匹配当前流。未知字段、错流和别名路径不属于兼容范围。原完整复制与限定 M 投影、进程身份和时间、自然退出及双流完整字节校验保持，A 成功、普通清退和独立核零条件不变；此项不改写旧回执、不重跑 M，也不代签完整 B 或业务验收。
