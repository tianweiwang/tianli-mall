# 当前开发状态与交接

## 2026-10-25 通用多商户商城源码整理候选

当前完整正常编排来源为 `bafb6305062aeb6b6e945c21da572863062ca480`，包含严格 M37、24 步来源链和完整 18 件主程序；原 N29 加 PINS 共 30 件静态 Source 合同逐字节保存。本次在既有 525 份源码上精确追加 60 份完整原件，共 585 份、171,739,425 字节。它们是仓库外最终脚本的完整文本，未执行存档程序。

两个新整理的原生外层入口已完成 Root 与非作者源码复审，并以 r2 原字节加入：M 启动外层、成功 S 双读回器外层。本候选 `source_delivery_complete=true` 只表示这次选定的完整程序文本交齐；实际 Git 采用与推送尚未执行，原历史资格、运行材料和跨机恢复仍未交付。原完整 M/launcher 和 S/读回算法保持原字节；新的 M 外层不会被当作原历史执行件，现有 M 输出必须拒绝重跑；成功读回器仅可在真实 S0 资格完整后由 Root 调用。

原机 M 的 1,150 项原生命令及两份完整 M 回读已在外部合格。Root 已报告同一正常 S 启动，本归档未纳入其后续终态和成功回读，不能据此签 S0。UI `83daa6283ed5280beddd9ce0886712e5654a0766`、媒体 `d7b6a5f485775a711a4e43a3e3a21af6208d36c9` 与主业务分支分别保存，尚未在交接业务树合并；当前仍有 7 类开发/接通、1 类历史待核及 11 组主要验证，共 19 组未闭环。

各 HEAD 相对直接父提交的选定清单为 Main 2、UI 2、MEDIA 41 件，它们不代表全部 outgoing。Root 的完整祖先差集另含 bfe311 的 44 件：四个 outgoing 提交共 89 个不同 blob、2,158,491 字节；推送精确 d7b6 会连同 bfe 前序完整保存，不会合并分叉。产品差集高置信扫描无命中仍须人工复核，新归档提交另扫；本候选没有推送。

离线校验只检查本归档的字节、成员、AST 和 JSON。`runtime_ready=false` 保持。SOURCE-WINDOWS 与语法资格指针是原 Source 合同的历史元数据，不代表归档副本保留原文件标识或新资格；绝对路径、旧 stat/native9/birth 门、公开历史权威及新隔离环境仍须单独恢复设计。Root 运行报告、Run、原始双流、巨大 M/freeze、ENV、凭据及业务数据均未导入。

最新机器可读清单见 [r8 源码整理记录](records/ms4/current-source-handoff-r8.json)。以下原有段落保留其历史时点；其中“当前”不能覆盖本节的来源与未完成状态。


### 当前源码fa与最新实际业务边界（2026-10-24）

当前产品源码为fa2088f9571bdc215be1ec05a59b840ed8b3943c，parent6e3850fe210b2b1e99b2a33baa68123e34aefab6。七份已审PHP精确采用、八完整PHP必要语法和本地提交已完成，feature/ms4-asset-consumers的明确远端ref已核同SHA；未部署。只改变Source-I/O和已审child INI合同，所有原full_source读取、TTL及业务断言保持。fa完整后继Source准入、物化与正常业务验证仍待完成；37/21至多是未来推导，不能拿本归档6e的36/20或语法通过代签。

6e实际Run2026102321491304a643的M1,104项与双完整M读回通过；S2388和paid均退出1。唯一collector证明第二上传前同一600秒绝对deadline由BEFORE跨至AT_OR_AFTER，随后read_confirmation抛AuthUnavailableException并返回HTTP503/216字节。不能将共享158行、INI或介质写成唯一失败类型，也未测每个函数的独立耗时。原八卷实例close93、独立zero20及Root/peer完整实际读回均0，第十一份whole退役authority已封；收尾不回补交易成功，仍无A0。

本次归档保留旧486份原件和原清单行，只新增6e的39份完整Source，合计525份、41包、118,925,175字节；没有以272份导出子集覆盖旧253份独有来源。当前归档绑定6e的完整18件、11件必要作者/模块、2份设计及8份选定同流程脚本，严格36字段/20步；原115成员namespace未全部交付，原32/16、33/17、34/18、35/19及旧准备包仍保留。离线verifier仅源码校验，本轮525份归档已实际通过离线源码校验：178份Python AST、134份JSON及五代完整模式。该结果仅为源码交接，不代表业务通过。

源码交接分支codex/orchestration-handoff-20261023的业务树仍基于2e4；本轮补交范围为上述525份来源及相关文档；离线校验摘要见 `docs/multi-merchant/records/ms4/current-source-handoff-r6.json`。不要将fa功能分支已推送等同本归档已交付，也不要把6e归档视为fa运行准备完成。142MB whole11、117MB实际M、原Run/原生双流、ENV/private/actors/Cookies/上传/卷不进Git，runtime_ready=false。

通用多商户商城继续复用原商品列表、SKU、规格、价格和库存。完整正常A、同源fresh B、三端UI20/20ACK/42帧及320/390/1440、原Task2三场景、独立H5九场景/27宽格/JPEG、四次真实表单上传与五原图reader/保存后读取、非空历史37步/四CLI、媒体/设备、真实支付退款回调对账结算、发布恢复与人工验收均保留未完成责任；停止样本NOT RUN，新商家客服默认关闭、原平台客服保留，只有BASE人工签收。

## 历史正文（原时点完整保留，当前状态以上文为准）

当前源码交接位于 `codex/orchestration-handoff-20261023`，业务代码位于 `feature/ms4-asset-consumers`。最新完整编排绑定业务 `0c13c57ba1ec4bf15ea9240601a5d6361e986ce8`：18件主编排及8件作者源码/设计；累计307文件、27包，原281件保持原字节。原 `codex/audit-base` 链接不含补交。见[编排入口](../../help/audit-regressions/orchestration/README.md)及[当前摘要](records/ms4/current-source-handoff-r5.json)。

该来源M已实际完成1,060项native/wait 0，完整33字段物化结果已有Root及独立回读。新完整S已通过准入并启动，本次快照为 `NOT_TERMINAL_AT_SNAPSHOT`；尚无A成功或完整业务验收，不能把M通过、已启动或观察命令退出0当业务完成。

此前881来源完整链在 `paid_real_confirmation` 的 `M4C_FORM_NORMAL_HTTP_JSON_200` 失败，原S及订单准备均退出1；后续履约、售后评价、Worker未启动。原3容器/1内部网络/8卷已正常回收，93项close和20项独立核零通过。测试辅助函数位置不能证明HTTP根因。见[881真实终态](records/ms4/orchestration-delivery-readback-20261023.json)。

交接分支业务代码树仍基于2e4；业务0c13仅在上述feature分支。Git提供源码及摘要，原Run、双流、完整作者历史输入和私有运行准备未交付；`runtime_ready=false`，换机完整复跑仍未完成。下面保留各历史阶段和未完成业务项，源码交接不补签验收。

## 1. 项目定位与交付范围

- 产品：基于 CRMEB v6.0.0 改造的通用多商户商城 v0.1。
- 复用原商品列表、SKU、规格、价格、库存和适用的旧业务能力；不引入天俪专属需求或历史演示站需求。
- 当前整合分支：`feature/ms4-asset-consumers`；本次整理前的代码提交：`76efa7807c6137bc430e314e2faa78691cf60431`。
- 本次交付为源码和开发记录检查点，不是发布版本。用户本次授权整理并推送当前项目；部署、真实数据库写入、真实支付与退款仍不在本次范围。
- 只有 BASE 已登记用户验收。其余阶段的已有实现和局部验证不构成整阶段签收，不计算整体完成百分比。
- 各历史记录中的“未推送”描述其原执行时点。本次 Git 交付不改写历史结果。

## 2. 代码与文档导航

| 目录或文件 | 用途 |
|---|---|
| `crmeb/app/merchantapi/` | 独立商家 API、身份和权限入口 |
| `crmeb/app/adminapi/` | 平台管理、商家管理、商品审核等接口 |
| `crmeb/app/api/` | 顾客端商品、店铺、购物车和订单接口 |
| `crmeb/app/services/marketplace/` | 多商户业务服务，包括商品、资产、订单和支付 |
| `crmeb/app/dao/marketplace/` | 商家范围查询与持久化 |
| `template/merchant/` | 独立商家后台 Vue 源码 |
| `template/admin/` | 平台后台 Vue 源码 |
| `template/uni-app/` | 顾客端 H5、小程序与 APP 共用源码 |
| `help/migrations/` | 分阶段结构、归属及菜单迁移工具与说明 |
| `help/audit-regressions/isolated/` | 专用隔离验证入口和辅助工具 |
| [文档索引](README.md) | 原决策、规格、计划和验收流程 |
| [执行记录](records/README.md) | 各阶段实际结果与验收记录 |

## 3. 功能实施状态

| 模块 | 已有实现 | 当前验证边界 |
|---|---|---|
| 认证与会话 | 登录、退出、刷新、版本化会话、账号状态、权限检查 | 已有隔离普通流程结果；完整验收与用户签收未取得 |
| 商家管理 | 商家创建、资料、审核、接单与履约开关、商家管理员 | 已有正常 API 验证；完整后台页面验收未完成 |
| 员工与角色 | 员工创建、编辑、启停、角色及权限管理 | 已有正常 API 验证；阶段整体验收未完成 |
| 商品与 SKU | 商品列表、详情、新建、编辑、送审、上下架、规格价格及库存流水 | 复用原体系；已有正常 API 验证，完整商品经营页面链仍待 |
| 素材、运费与分类 | 商家素材库、上传、运费模板、平台分类绑定、平台商品审核 | 已有局部正常验证；实际云存储与顾客交易全链未完整验收 |
| 平台与商家后台 | 独立商家后台和平台商家管理、审核等页面源码 | 已有构建与源码检查；不据此签真实登录和页面业务通过 |
| 顾客店铺与购物车 | 店铺展示接线、购物车按店分组、单店选择与确认 | 已有源码和部分组件检查；完整真实页面与设备验收待完成 |
| 订单与履约 | 单商家创建、库存占用、取消、订单读取、拆单、发货和收货相关实现 | 历史隔离链有内部付款、发收货及读取通过；最新完整链未通过 |
| 售后与评价 | 售后、附件、退款额度、评价及三端消费者相关实现 | 完整正常流程、Worker 和页面验收未通过 |
| 支付 | 支付核心、持久化、测试支付驱动及消费者接线 | 内部测试付款有历史结果；真实收款、退款、回调、对账、费用和结算未完成 |
| 发布与运行 | 保留发布、恢复、限流和防回滚要求 | 尚未完成发布验证和上线验收 |

实际记录入口：[MS0a](records/ms0a/report.md)、[MS0b](records/ms0b/report.md)、[MS1](records/ms1/report.md)、[MS2](07-plan-ms2.md)、[MS3](records/ms3/frontend-source-checkpoint.json)、[MS4–MS6](09-plan-ms4-ms6.md)、[支付核心](records/ms5/payment-core-integration.md)。

## 4. 历史完整链及修复（保留原时点）

本节历史记录的完整正常链为 Run `20261023003421d1fc4b`，来源提交 `53e6d521b387b19a4cf370e0e381670b762d325e`。

1. 初始化、支付结构检查、early 20 条迁移命令、文件初始化和顾客准备实际通过。
2. 付款测试进程实际退出 1，完整链失败。原公开日志不足以确定首次异常位置、阶段或 HTTP 完成数；这些信息保持未知。
3. 后续履约、售后评价、Worker 和独立页面验证未在该链启动，不以旧结果补签本轮。
4. 原 3 个容器、1 个内部网络和 8 个专用卷已正常收尾；93 项收尾命令、20 项独立核零命令通过，六项 fresh 查询为空。
5. 提交 `76efa7807` 修正两份测试入口的断言名称前缀和初始化前后环境比较，并补充付款失败的有限诊断。两份最终 PHP 的固定 PHP 8.2 语法检查实际通过。

修订后的新业务链尚未运行。绑定 `584532ba2` 的完整新编排已经生成；其第一次 materialize 在第 673 条源码冻结操作失败，前 672 条为 0，没有创建业务 Run、App 或业务资源。只读诊断确认编排的独立 caller 合同仍引用旧测试入口，首个固定错误为 `EXACT_REVIEWED_REAL_PAID_ENTRY`。修正后的完整后继源码和必要 initializer 语法结果已保存；新 materialize、业务链和后续页面均未运行。

上述语法、来源核对及资源收尾不能作为交易业务通过证据。历史内部付款、两次发货、三次收货、五次重放和 288 次内部读取结果仍按原来源单独保留，不能汇总为最新版本整体通过。

## 5. 后续执行入口

继续使用 [09 计划](09-plan-ms4-ms6.md) 中原执行清单。本总览只列恢复工作的先后关系，不生成另一套验收标准：

1. 完成当前修复来源的验证文件绑定与必要初始化脚本语法检查，运行新的完整正常交易链，再正常收尾并独立核零。
2. 在成功的同一代码来源上完成独立 fresh 页面验证；原三端 20 步观察和独立 H5 九场景各自保留。
3. 完成售后、评价、Worker、顾客表单真实上传及原图读取，补齐真实页面、响应式和设备验收。
4. 完成非空历史数据升级、原 37 步业务、四条 CLI、旧权益归属和启用前检查；不得用空库代替。
5. 完成素材私有用途、扫描和自然清理等原有待验范围。
6. 明确真实支付账户及费用规则后，完成实际收款、退款、回调、对账和结算；相关外部操作另受授权限制。
7. 补齐明确的正式 HTTPS API origin 后完成小程序与 APP 正式构建和设备验证，再按 MS6 完成发布、恢复、限流、防回滚和人工验收。

用户已停止的安全复现、负向、故障、并发、断连和恢复样本继续 NOT RUN，不换名重跑。新商家客服默认关闭，原平台客服保留。

## 6. 本地工作区与未采用候选

当前开发以 `feature/ms4-asset-consumers` 为整合入口；同来源的 `ms4-aftersale-normal-check` 工作区用于隔离核对。其他分阶段工作树和备份保留其历史来源，不能仅凭分支名称或提交先后批量合并。

原始运行证据和私有准备数据保留在仓库外，不随源码推送。此前遗漏的主编排、后续完整 M/S/launcher 及候选程序已补入 [编排源码归档](../../help/audit-regressions/orchestration/README.md)，可从 Git 完整读取和离线校验；这不是可直接运行的环境包。[交接记录](records/ms4/orchestration-source-handoff.md) 区分源码存在、原实际失败和未运行项。业务修复位于 `feature/ms4-asset-consumers`，编排源码交接位于 `codex/orchestration-handoff-20261023`；原 `codex/audit-base` 链接不包含本次整理。源码中的 `records/` 仍只保留摘要。此前冻结的失败来源和结果不改写。

仓库外原交接根名为 `ms4-worker-normal-source-preparation-r1`，本机位于工作空间的 `artifacts/` 下。归档清单按该根的相对路径保存选定源码；不依赖接收者能访问原机目录。以下仍是待续工作，不属于已通过的业务交付：

| 本地候选 | 状态 |
|---|---|
| `full-a-paid-fulfillment-contract-normal-next-source-r1` | 原 profile 中间包保留；完整后继见下面两项 |
| `full-a-published-current-normal-next-source-r1` | 完整编排已生成，实际源码冻结失败；无业务 Run |
| `full-a-published-current-normal-next-source-r2` | 修正活跃 caller 版本合同的完整后继；新 initializer 纯语法通过，未 materialize 或运行业务 |
| `fresh-b-customer-form-external-source-r3` | 页面验证草稿，仍需绑定最新来源；未执行 |
| `nonempty-history-normal-close-components-source-r1` | 已有独立源码核对；未证明目标业务运行 |
| `nonempty-history-normal-parent-components-source-r1` | 原缺三个组件固定装载的失败来源保留 |
| `nonempty-history-normal-parent-components-source-r2` | 完整装载修复，独立源码核对和纯语法通过；未签业务 |
| `nonempty-history-normal-startup-components-source-r1` | 完整启动预期、就绪和副作用检查源码已实现并独审；新候选语法、最终准入和完整业务待验 |
| `nonempty-history-host-handoff-components-source-r1` | 来源组件已冻结，最终绑定未完成，入口保持拒绝；未运行 |

这些候选不能直接作为可运行发布包。published-current 两包冻结来源为归档前的 `584532ba2`；本次归档会前移 Git HEAD，不能把历史来源材料签成归档后提交已经运行。恢复时先核实际文件、原独立报告和未满足的绑定，避免把源码核对或脚本存在当作业务验收。


## 本分支后继源码交接（2026-10-25）

本轮补交109份作者源码，归档累计694文件、72包、264,349,255B；原585份与历史描述符保留原件。范围包括b54完整Core及最新独立S、M/S读回器与必要构造程序/模块/模板、Env9与941媒体HTTP、licence Host6和refund Host-r3。MS2仓库内Host引用真实产品提交057f769e及其Git路径，未在归档分支复制业务实现或合并产品分叉。

完整离线核对通过693文件、249 AST、178 JSON及7份Source合同；非作者完成新增原件、历史保全与最终依赖差量复核。具体来源、核对范围和未交付项见[交接记录](records/ms4/orchestration-source-handoff.md)与[r9摘要](records/ms4/current-source-handoff-r9.json)。这项结果只证明选定源码归档的完整性。

业务树仍基于2e4；原Core4份资格输入、M reader原41路径中的36项前置材料、生成tar/Tree、实际素材、完整作者重放输入和新环境身份资格尚未交齐。`runtime_ready=false`，跨机复现与业务验收仍未通过。Root另行管理实际产品分支、正常验证和Git交付，源码归档不关闭原交易、历史升级、真实支付、设备或阶段验收。

最后已知源码遗漏仅补历史清退程序`legacy-refund-normal-r3-memory-failed-retire-source-r1/retire.py`原33716B及固定平面来源映射；原108份快照和Root既有核对事实保留。只归档作者程序文本，不导入其oldRun、Stop、CLOSE、ZERO或原生双流，不扩到后续Host-r4。本次完整离线检查已自然0：694文件、72包、264,349,255B，250 AST、178 JSON、109选集及7份Source合同；未执行存档程序；非作者已对最后一件原件、固定平面映射及控制差量收口Source0，未重复运行完整694检查。上段693/249 AST的核对保留其原时点与范围。
