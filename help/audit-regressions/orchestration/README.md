# 多商户正常验证编排源码

本目录补齐此前只保存在原机仓库外 `ms4-worker-normal-source-preparation-r1` 中的编排程序。`sources/` 按精确允许清单保存原文件的完整字节；[source-manifest.json](source-manifest.json) 记录逐文件来源相对路径、长度、SHA-256 和角色。

现用作者另需的两组固定公共 donor 也保存在同一索引：原 `project-organization-20261023` 下的 baseline PINS/Core9/B31 与 historical-normal PINS/18件，新增60件、66,018,409字节。新增行单列真实 `origin_root`，原242行及其归档字节保持；只选源码、来源索引和合成棋盘图，不递归收入原目录中的其它文件或运行资料。

这是**源码归档和恢复资料**。包含旧完整编排、后续失败版本和修正后的完整编排源码。本目录不宣称可直接跨机器运行或业务验证通过。当前交付分支为 `feature/multi-merchant-release-config-20261027`；初次源码交接保留在 `feature/ms4-asset-consumers`；最早的 `codex/audit-base` 链接不包含本次交接。

## 当前候选的正常回归接入

`current_source_inventory.py` 与 `current_normal_source.py` 是当前候选的本机作者入口，归档 `sources/` 保持原字节。前者核完整 Git blob、两份独立干净检出和保护目录，只输出 `CAPTURED_NOT_APPROVED`；后者必须读取调用方明确指定摘要的独立技术复核，不能把捕获结果自行批准。每次来源 SHA 必须在 authority、独审、完整捕获与差量中一致，再固定到生成的 M/S；更换候选必须重新核对来源，不能沿用旧批准。

新版复用已存在的九卷 Core profile（含独立 `source-tree`），更新当前售后入口的必要固定引用及摘要依赖。旧八卷归档不通过替换 HEAD 或摘要获得新资格。新材料写入仓库外目录；旧运行、失败、退役和来源记录仍按原身份保留。完整正常迁移、顾客、测试付款、履约、售后评价、Worker、普通关闭和独立核零的责任不减；源码派生、语法检查和来源技术通过都不代表这些业务已经运行。

表单检查 helper 更新独立使用来源独审的 `customer_form_helper_update`，精确绑定唯一 `ms4-customer-form-normal.php` 的 baseline/capture 前后值，不加入原两项或四项 `active_profile_updates`。表单来源名单及 Host/zero 常量承接该行；source-tree 的七个主入口名单保持原成员集合和更新范围，其余完整来源比较和整件正逆还原保留。当前四项更新加该 helper 时，接口实际变更为原7行加1行，元数据记8行；旧 helper 未变的情形保持原九件输出。

该 helper 变化时，initializer 的同一来源行也按唯一字面值替换并核整文件逆回；派生结束前逐项核对 initializer 完整七行与 Host、zero、interface 及当前 inventory 一致，避免初始化阶段仍携带旧 helper 摘要。旧 helper 未变化时不进入这两项补充处理。

Worker 作者保留原 `129901/f420b2…` 目标，并允许入口有限 fatal 诊断的 `131974/1c192ecf…` 目标；只按实际 inventory 的完整 path/bytes/SHA 选择这两项之一。同次独审的 `worker_fixtures_archive_update.before` 必须仍精确为原 `127340/9cb8a8…`，`after` 必须等于所选完整目标；Core9 与 B 源接缝均拒绝其它值。接口当前行、Host/zero 常量和 initializer 两处字面量沿所选项传播并整件逆回，旧来源保持旧目标。入口诊断只在原 shutdown 前释放 65536 字节预留并输出有界安全 fatal 分类，不改变业务、原成功 schema 或资源上限；新目标仍须新的来源捕获与独审，语法通过不代替原 Worker 255 的根因或正常运行资格。

平台订单表单读取前的 Redis tag 类型检查复用已有 `m4tr_redis_type`，通过只读 `EVAL_RO` 取得实际类型字符串。当前 PHP Redis 客户端的直接 `rawCommand('TYPE', ...)` 会返回布尔状态，不能直接与 `none/set` 比较。成员完整摘要、类型、绝对过期时间及后续整库检查保持；同一原隔离 Redis 的只读观察块已从原失败变为通过，完整 A 仍须在修后来源运行，旧失败保留。

编号序列只在三个新编号的 after 快照显式请求 RAM 侧证明：创建根单、售后 apply、履约 ship。共享 Redis 观察器在同一次 `EVAL_RO` 内为原完整快照中的13位正十进制毫秒键采值，非 string 类型不执行 GET；默认 Lua、三字段主返回及完整四列行检查保持。侧证明仅含类型、字节数、SHA、正十进制判定及同次 type/绝对 expiry，精确覆盖本次目标集合，绑定完整三字段 Redis 状态；AR 再绑定完整原六字段 after 快照，主快照不加字段。消费者保留编号/seq0→1映射、正数字串与长度/SHA精确值、三个原绝对期限界和自然缺失分支；失败诊断只用已持有证明，不再晚发 GET/TIME，不输出原键或原值，成功结果仍为原29字段。R81 原失败已证明混用了两个观察时点，旧失败和R80未重现结果保留。两候选完整 PHP8.2.33 语法及三个真实 Redis resolver／同次 EVAL_RO／原 delta 正向样本已通过；探针沿既有 R80，DB/rows 为空 fixture，私有目录为只读源码样本，不能代签 SQL、App 或完整 A。临时两容器一网络已普通清退；新来源与完整正常运行仍须另行验证。

共享 paid helper 的新整件来源同时影响当前 A/B：作者以固定 PAID_BEFORE／PAID_CURRENT 和同次源码独审的 `paid_consumer_helper_update` 精确绑定，有限更新当前七项表单/运行向量、paid entry、Host/zero 常量及 initializer 两处字面量，并沿原规则整件逆回。历史 donor/previous/composite 描述及其它 entry 检查保持；不将新 helper 混进冻结 Source615，也不补写旧来源资格。新源码独审应在既有报告中给出该精确 before/after，原 authority schema 不增加字段。

资金草稿的专用迁移在原 MS6 schema 保全之后、首次 App 初始化之前执行 check→apply→check→apply；A 与 B 共用同一有限接线，原九个迁移子进程和 early 175 表历史基准不变。维护账号只新增两张资金草稿表、初始全未配置单例和两个未授予角色的权限叶；DML 应用账号独立核对完整五列表结构、初始值及全部旧表数据。菜单只允许两条确证新行和自然自增两位的结构差量，其余 174 张旧表、原 schema 单例、Redis 绝对期限、SQL 模式与账户授权继续精确保全。原生回执与 Host 消费分别绑定同一次运行和完整来源，真实资金仍未启用；接线和语法检查不能替代新实例的完整正常验证。

履约与 B 页面准备共用的前置检查也必须消费这份完整资金迁移回执，再将其严格确认的两张表加入原 175 表加 schema 表的闭合集合。仍逐项核验五个履约/售后目标、原观察模式以及付款后完整数据库和 Redis 摘要；售后与 Worker 继续继承动态完整快照，不能忽略新增表或改写旧历史基准。

B 对该 helper 使用同一独审前后值，并逐项连接 B 当前表单向量中的唯一 baseline 行和当前 inventory；只有完全匹配的旧行可转换为已审新行，不把路径直接加入通用更新清单。原 initializer 来源循环继续承接该行，其它当前来源集合和历史记录保持。

纯作者工具修复可与冻结的应用来源分别固定：应用 authority、capture、candidate/validation 和实际 M/A 继续绑定同一应用 SHA，两检出保持干净；另行记录新作者工具的完整字节摘要、提交、独立有限复核及真实执行 argv，新 normal18 独审覆盖完整派生产物。不得声称旧应用 capture 包含新工具，也不能把维护工具的工作树替代为 M 的 main/repo。

正常 Core9 派生仅在 Host 的 `verify_freeze` 内复用同次完整 `source_tree_manifest` 实际读取的 path/bytes/SHA 映射，核对各角色、准备引用及固定入口；接口和 PREP/artifact 仍按原方式读取。所有原角色集合、额外路径、Git 跟踪、seed 及比较断言保留，函数末尾再完整读取源码树并严格核同首次全部文件与目录闭集。无跨 CLI 缓存，不以 mtime 代替摘要；B、历史及业务步骤不变。唯一函数与整 Host 逆回、其余函数原字节和原九件正逆回放须通过；已冻结来源和旧运行不改，源码检查不代替实际只读校验、正常业务或完整验收，也不能据此宣称已测得运行提速。

同一作者的 `--b-source <新目录> --b-inputs-file <固定输入JSON> --b-inputs-sha256 <摘要>` 有限接入原13卷B来源；仍须提供 `--authority-file/--authority-sha256`。输入绑定真实M及外层双流、成功A的外层终态/正常结果/关闭/独立核零/后续历史五项证明，以及当前五材料和三端实际构建依据；A未通过时不生成B。来源包沿原17成员和浏览器合同固定完整摘要，两个Root入口在执行时读取独立技术复核，这不是新增用户确认。原A默认派生、历史记录、业务检查及停止样本范围保持。

同一入口的 `--post-a-history <全新外部目录> --post-a-inputs-file <固定公开输入JSON> --post-a-inputs-sha256 <摘要>` 配合原 authority 两参数，收集成功 A 的公共记录。输入固定为 `schema/scope/source_authority/normal/A/post_a_contract/runtime_root`，scope 为 `CURRENT_POST_A_FIXED_PUBLIC_INPUTS`；normal 复用原 M 四项，A 提供真实 session、outer 四件及 context/normal_root_result/worker_close/independent_zero。工具先消费外层自然 exit/wait 0，再复用当前 M/A 关联与原协调器回执；不会启动、重跑或清理实例。A 驱动自身已串行执行普通关闭和独立核零，不能另行重复调用。

正常 M 外层同时支持原完整复制布局，以及显式传入其独审指针时的精确七键 created／九键 terminal 投影。投影逐项核同四个进程身份字段，并真实读取正常来源独审、核 created 的固定 scope 与 longpaths=true、原十一项 materialize 参数及 launcher/cwd 关系；原自然 exit/wait 0、完成时间和完整双流检查保留。A 与 B 自身外层继续使用原完整复制规则，原回执不补写。

正常 M 的已知毫秒回执单列严格分支：created／terminal 分别精确匹配十键／十二键字段集合，七项共同身份必须相等，并核进程内 longpaths 记录、原 safe.directory 注记、应用 Source 与独审一致，以及完整 launcher 字节等于独审成员。时间只在原 started_ms／finished_ms 单位中比较，不换写为纳秒；仍须显式独审、原 materialize 十一参数、自然 exit/wait 0 和完整双流。原纳秒分支保持，未向 A/B 的其它回执开放毫秒布局。

派生的 Root M 在 `current_known_normal_root` 前检中也显式传入已有 `CURRENT_B_NORMAL_REVIEW`，与来源复核入口使用同一严格消费者；不会因缺少第二参数误拒有效的 M 投影回执。

`post_a_contract` 保留原六键：`header/files_key/retained_public_files/prior_public_inventory/public_roots/public_root_files`。六棵公共树和十三个固定公件从当前绑定推导，与输入全等；旧清单原件及全部原行、retained 和当前公件形成完整并集，重叠路径必须字节相同。显式未来 B 的 runtime_root 只用于原隔离边界检查，不创建 B。全量复读及原六键消费者回读通过后，依次保留 `POST-A-PUBLIC-HISTORY.json`、`POST-A-CONTRACT.json`、`PUBLIC-INPUT-WINDOWS.json`，最后发布 `RESULT.json`；失败时保留已有输出，不能覆盖重用。回执单列实际维护工具摘要，既不改写冻结 App 资格，也不代表 B、页面或完整验收已经通过。

同一作者的 `--history-source <全新外部目录>` 配合原 authority 两参数，组合已归档的非空历史组件。固定 overlay 的原 13 件末尾只加 reader，保留 producer 的 13 件／11 控制文件合同；reader 使用独立的 12 项控制文件，完整包为 14 件。内部 `history_host_source.py`、`history_reader_source.py` 只作固定原件的有限字节变换，核整件正逆和非目标成员保全，没有独立 CLI，也不运行 Host、应用或资源操作。

输出保存 before/candidate、实际作者及内部模块原字节、完整输入来源、publication、标准正逆 patch 和全成员摘要。publication 只是来源清单，运行仍须另有真实外部五键 source review。当前 Host 在 prepare 前核该 review、四键 publication 与七项实际绑定，将两份来源原件完整复制到 Run；后继步骤和 reader 重核同一原件。`current-structure-upgrade` 已接原 37 步 seal、四个 CLI 与 producer/driver 两次真实退役前置，再沿原 16 个结构 CLI 和完整数据库、文件、Redis 观察升级；reader 仍须核 173 表基线和原九项 HTTP，最后普通关闭、独立核零。源码接线和来源采用不代替这些实际运行结果。

历史 initializer 的默认运费模板在 MS2 结构迁移后含第八列 `mer_id`。来源变换保留原安装 SQL 和七列 canonical 值比较，使用固定 `mer_id => null` 的完整八列行生成快照预期，并增加全行严格比较；原全库、Redis、metadata、DDL 和自增值检查保持。旧七列预期已用实际正常行在 PHP 重放失败，修后表达式通过；新的完整 initializer 和非空历史链仍须在新来源、新隔离 Run 中实际验证，旧失败不改签。

历史文件准备 CLI 会解码完整双源清单并整读源码；实际 R8 在 PHP 128M 上限退出255。其固定字节变换只在原入口检查后、首次完整资格读取前，核原128M并将本进程预算设为512M、核返回值与回读；共享 Compose、挂载、命令及业务 HTTP 默认预算不变。该 CLI 同进程的旧 App 初始化也使用此预算，所有原数据与文件检查保持；新完整运行仍须实证，原失败不重启或改签。

历史 producer 的固定直接入口采用同文件已有的 `nhep_normal_history_main()`；其固定原件摘要、末尾唯一接缝和整件逆变换均须核同。主函数、同文件 startup、原 37 步 HTTP、正常 seal/close/publish 及全部来源和业务断言逐字节保留；正常 CLI 参数及共享角色配置保持。新包仍为十四件，七件变换、七件整件保持；采用须另行独审、生成新 freeze 和新 Run，旧 R9 失败不重启或改签。

同一直接 CLI 入口为运行时 fatal 诊断保留 32 KiB，并注册 shutdown handler；仅实际 `E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR` 时向 stderr 写入至多 2048 字节 JSON 加换行。只记录错误类型、文件标签及路径摘要、行号、严格格式的内存上限、堆用量/峰值，以及匹配固定内存耗尽消息时的两项字节数，不输出原消息、堆栈、参数、环境或 SQL。正常返回和非直接 CLI 调用静默，handler 不改退出码或内存预算，不安装到独立 HTTP 子进程；脚本在 handler 注册前的解析/启动失败无法由它捕获。原 R10 的退出 255、空双流不能据此追签为内存不足，后继新正常运行才提供诊断证据。

R11 新正常运行的安全 fatal 实际证明父 producer 在 128M 限额读取完整来源时耗尽 PHP 内存；原失败保持。因此，仅在同一自文件直接 CLI 入口完成上述 handler 注册后、调用原 main 和首次完整资格读取前，严格核原 `128M`，将本进程 `memory_limit` 设为 `512M`，并核 `ini_set` 返回原 `128M` 与回读 `512M`。不改 Compose、Host、配置文件、环境、命令、全部业务断言或独立 HTTP/channel 子进程的默认预算；32 KiB reserve 和原诊断代码保持。原容器 `Memory=0` 只说明未设置该 cgroup 限额，不证明宿主容量充足。语法与静默正常入口检查不代签新历史 Run、37 步或后继验收，停止的 OOM/故障样本不主动运行。

B的两个网络使用固定输入 `network_subnets.isolation/ui_web`，只接收互不重叠的规范RFC1918网段并核实际IPAM。Root启动前仍须检查地址空闲；复用A网段须先有真实关闭及独立核零。部署public采用同一根部install排除投影，完整安装器来源仍保全；三端构建保留各自真实来源，只有完整输入一致才能复用。

B Root M 的 proof 配置回执保留原 Host `PROOF_INI_REFERENCE` 的真实绝对路径、字节数和摘要，不随新 B 包的 `PREPARED` 目录迁移。作者核原/派生 Host 指针完全相同并读取原回执，只替换 `frozen_resources` 内这一处路径表达式；不复制旧回执或放宽单文件、内存值、角色、扫描目录和完整资源比较。B 工具修复独立记录实际工具字节，不改已冻结的应用来源或重启正在运行的 A。

B 浏览器接线沿 `derive_b_profile` 作有限派生：Task2 使用同一真实 owned tab、自然完成的 HTML 文档 URL 和原生事件的完整 `{tabId}` 来源绑定。保留原始事件、序号、请求 ID、完整响应和两类 ExtraInfo，以及原 clean/readiness 的零未分配事件检查。当前工具不支持的 `Target.getTargetInfo` 与直接 CSS 文档导航不再作为该接缝的前置；不补造 targetId，不修改通用网络采集器或业务场景。

DOM 截图从同一真实 tab 的 CDP `Page.captureScreenshot` 显式取得原生 PNG，仅作有界、canonical base64 解码，随后沿原 PNG 解析与原字节保存路径。PNG 签名、CRC、IHDR、zlib、像素尺寸、DOM 前后稳定性、调用时间、隐私、布局、三种宽度及关闭/核零检查保持。标准截图接口的 JPEG 输出不伪装或转码为原 PNG。原 browser8/PINS 和交叉摘要继续重算并完整正逆核对；新工具字节另行固定与独审，应用来源及其实际 A 资格分别保留。

隔离初始化只创建原安装表结构，按声明准备测试资料。原九个迁移完成后、退出权限及全部 early 快照之前，`isolated/lib/ms4-order-menu-fixture.php` 从当前已审安装 SQL 的唯一菜单块解析四个原订单锚；不执行安装 INSERT。四锚使用数据库生成 ID，按实际父 ID 映射 `pid` 和页面/分组的 `path`，其余全部字段保留原 SQL。normal-A 固定调用既有 `marketplace-ms4-order-read-menus.php` 的 check→apply→check/reapply，B 固定只准备四锚，原 UI 阶段仍须首次登记两个读取叶。此差别由作者生成的源码常量确定，不提供运行时开关；AR、B UI 与独立 oracle 的既有权限断言保留。

`order_menu_receipt.py` 由作者按本次冻结来源及完整摘要读出、原字节嵌入 Host，消费同一 initializer 的独立回执；不增加运行时 import。回执绑定同 Run/CID/source/freeze、四锚完整源字段与真实 ID 映射、A 原两叶迁移结果或 B 严格未创建状态，以及全部旧菜单、角色、其它表、结构、Redis、模式、授权和原私有配置保持。仅允许本次声明的四条（B）或六条（A）新菜单；现有角色不追加授权，不导入其它原接口。

新商家退出的两权限叶在原 initializer 的九个迁移子进程全部完成后、private cleanup 与完整 source-tree 复制前调用原迁移的 check→apply→check；该点仍早于首次 App 及所有 paid/early 快照。沿原维护账号，不追加角色授权；独立回执绑定同一 run、CID、freeze 和三份完整当前来源，逐行保留旧菜单，并核除两条新叶以外的全部原表数据、表结构、Redis、SQL 模式及账户授权保持。Host 在原 initializer 结果消费点验证回执，后续原菜单全量保全继续使用含两叶的真实 early 快照，不过滤新叶或改写旧 hash。新实例必须实际执行；本机 R65 迁移回执不可跨实例复用，源码派生与语法通过不代表数据库或完整 M/A/B 已通过。

当前应用要求 MS6 版本表。普通验证沿现有 files-bootstrap 容器和专用维护账号，在原175表先决条件之后、首次 App 初始化之前调用原 MS6 迁移的 apply/check，再由 DML 账号读取同一版本记录。新增回执嵌入原 files-bootstrap 结果，由原四件证据链继续传递；原表、Redis和SQL模式保持检查保留，表清单只精确增加这一张元数据表。新独审必须逐项列明售后及顾客消费者的 before/after 固定引用；旧 Run、业务版本门和应用权限不变。

手动装配应用的旧CLI及订单测试先装入真实provider、应用配置和database，再启动AppService，继续经过生产版本门；共享订单装配函数的商家浏览器准备沿用同一顺序。原 `ms4-offline-postage.php` 只注册组件并执行原共享计算例，不启动完整AppService；它的结果仅证明运费方法兼容，数据库、身份、HTTP和Schema验收仍为NOT RUN。

`current_frontend_build_source.py` 复用已有三端构建器，接收同样明确固定的独审依据。它更新当前完整 Git 清单及资格引用，保留原依赖依据、工具和构建命令；作者不运行构建。实际三端构建及 B/UI 仍需各自验证，不能由来源派生代签。

该作者可选 `--windows-copy-workers 1|4`，默认1保持原派生产物及元数据逻辑。4只在新的来源包中将 Windows 依赖复制改为标准库四线程、有界提交；每个文件仍执行原完整读取、哈希、独占创建、读回及单链接检查，原前后整树、文件身份和全部构建命令保持。所有线程退出后才继续校验或记录失败；失败现场保留，但不保证残留按严格串行前缀排列。派生元数据明确标注 run 与局部 copy_one 的有限变化，并以整文件逆还原核对；不修改旧来源或正在运行的构建，也不改变 H5。四线程的实际耗时须由后继正常构建测量，来源派生不证明提速。

`current_ui_material_source.py` 沿固定 r3 五材料 producer 派生当前报告读取接缝，不执行构建或材料生产。新 producer 保留原 `--paths-json` 和 `--output`，另须显式提供 `--current-build-proofs` 及其固定 `--current-build-proofs-sha256`。证明文件分别绑定三端真实 builder 包、PINS、资格、Run 与外层原生回执；本代应用来源须与独审一致，各 builder 保留自己的真实来源。未完成真实三端构建时，只能取得源码候选，不能借旧报告字段获得执行通过。旧 metadata 只保留原固定图片与历史依据；原输入反复核读、PNG、碰撞、五材料和四 manifest 输出合同保留。当前布局读取使用真实 `DIST-TREE`、依赖目录和原生四件，不生成旧成功字段；H5仍核原双 pass、工具与普通清退。Windows跨API身份只为四种普通可执行扩展名规范化比较用的FD执行位，双方各自完整观测及其余身份不变。实际材料生产与B/UI须后续独立验证。

H5 仅接收已独审的四个固定 LX 别名属性后继算法；原 r5 只作有限派生依据。producer 只读核对实际 before/after 原始回执、完整身份和原生查询流，原始属性只接受1056或263200；当前重算仅比较稳定字段，不改写历史回执。无需新增 CLI 参数，实际 H5 完成证明仍是运行前提。

部署 public 只排除根部 `install/`，同名嵌套目录仍包含；安装器另以原 reader 完整读取并随原 finish 复核，included/excluded 两套完整目录、文件哈希及摘要均绑定同一 capture，写入现有 `producer-result.json.public_source_scope`。原逐文件 input-inventory、五材料、10键 profile 和 `NO_PUBLIC_ENV_FILE` 保持。后续 B 的 `ms4-ui-public-copy.php` 在 `m4uic_copy` 的完整 public 比较及末尾全树复读（原276–278、351行）须同步为完整来源保全与根 install 排除投影，既有 `install/.env` 固定模板读取/复制特例（原18–28、77–78、167–170行）也须一并明确调整；本作者不改 B，不以允许部署 `.env` 绕过检查。

作者使用 `--output`、`--authority-file`、`--authority-sha256`，仅创建新的源码候选及其正逆变更。producer 的 paths 文件仍为 `{schema:1, paths:{...}}`，路径键精确为 `metadata/source/public/h5_build/merchant_build/admin_build`。current-build-proofs 文件精确包含 `schema:1`、`scope:CURRENT_THREE_FRONTEND_BUILD_PROOFS`、`source_sha` 和 `builds`；builds 下三端各自包含 `packet`、`pins`、`run`、`outer`，pins 是对应包 `PINS.json` 的绝对路径、字节数和SHA-256，outer 是该 Run 的原 `-launch` 目录。旧 metadata 不能替代这份当前三端证明。

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

历史种子来源记录也必须连接当前已审的完整 `ms2-migration.php`。作者先核原记录、两个原种子函数的完整摘要、五个来源检查调用与整函数逆回，再仅更新候选记录的整文件来源摘要；原种子代码、函数摘要、Host 断言和存档原件保持。实际整文件来源及旧摘要另记在输入证明中，不能直接把失败记录改为成功。

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
