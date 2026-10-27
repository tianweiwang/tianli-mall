# 多商家改造（路线 A）开发文档

本目录是"现有 CRMEB 改造为多商家商城 v0.1"的唯一执行依据，替代 `~/Downloads` 下 v0.1–v0.3 三套草稿。Codex 和其他执行方只按本目录工作；草稿与本目录冲突时以本目录为准。

当前开发交接请先读 [当前状态与后续工作](CURRENT-STATUS.md)：查看当前工作来源、本机入口、已验范围与候选待验边界。执行顺序只维护在原 [PROGRESS.md](H:/天俪商城/artifacts/project-organization-20261023/current20261025-business-progress-r1/PROGRESS.md)，操作与运行来源见 [本机预览说明](../../help/preview/README.md)；这些导航不替代下列正式计划或验收记录。

此前仅在原机仓库外保存的编排程序现有 [源码归档与离线核对入口](../../help/audit-regressions/orchestration/README.md)。归档包含实际主编排和当前候选的完整源文件；旧运行回执和私有数据不在其中，运行依赖及未完成入口明确列出。

> 状态声明（只写一次）：本目录是设计、计划与验收标准，不是测试报告。所有验收用例的初始状态都是 NOT RUN；实际结果只记录在 `records/` 下，并以提交 SHA 为准。

## 1. 阅读顺序

| 顺序 | 文件 | 用途 | 谁必须读 |
|---|---|---|---|
| 1 | [12-codex-protocol.md](12-codex-protocol.md) | 执行规约：授权级别、停止点、禁止事项、回传模板 | 所有执行方，每次开工前 |
| 2 | [00-implementation-spec.md](00-implementation-spec.md) | 实施规格：范围、决策、命名、身份、归属、交易、支付、迁移 | 所有执行方 |
| 3 | [01-code-facts.md](01-code-facts.md) | 已核实的代码事实与安全发现（带文件位置） | 改代码前 |
| 4 | [03-test-environment.md](03-test-environment.md) | 隔离测试环境、测试分层、证据格式 | 跑任何测试前 |
| 5 | 当前里程碑的计划文件（见下表） | 具体任务、接口、测试与提交 | 执行该里程碑时 |
| 6 | [10-acceptance-matrix.md](10-acceptance-matrix.md) | 全部验收用例 | 写测试、出报告时 |
| 7 | [11-acceptance-process.md](11-acceptance-process.md) | 每个模块完成后怎么验收、谁签收、什么算通过 | 交付和验收时 |

## 2. 里程碑与当前状态

正式验收仍由用户或其指定验收人按 [11 文档](11-acceptance-process.md)作出。以下人工验收列只登记已有决定；实施与普通验证另列，不能据此代签其余里程碑。原“未开始”是初始值，不能继续代表已开展的开发。

| 里程碑 | 计划文件 | 内容 | 前置条件 | 人工验收记录 |
|---|---|---|---|---|
| BASE | [02-plan-base-rebuild.md](02-plan-base-rebuild.md) | 以 master 为基线重建，迁入备份快照中的必要修改和审计修复，得到 `AUDIT_BASE_SHA` | 无 | 用户已确认通过，见 [既有决定补记](records/base/acceptance.md) |
| MS0a | [04-plan-ms0a.md](04-plan-ms0a.md) | 最小安全修复：跨应用令牌混用、认证失败写入、账号状态、权限放行、认证配置 | BASE 通过 | 未登记用户签收 |
| MS0b | [05-plan-ms0b.md](05-plan-ms0b.md) | 会话 v2：版本化会话、撤销、单次兑换刷新 | MS0a 通过 | 未登记用户签收 |
| MS1 | [06-plan-ms1.md](06-plan-ms1.md) | 商家、员工、角色、merchantapi、MerchantContext | MS0b 通过（merchantapi 直接使用 v2 会话） | 未登记用户签收 |
| MS2 | [07-plan-ms2.md](07-plan-ms2.md) | 商品、规格、素材、运费模板、分类绑定隔离与 mer_id 迁移 | MS1 通过 | 未登记用户签收 |
| MS3 | [08-plan-ms3.md](08-plan-ms3.md) | 独立商家后台 `template/merchant`、平台审核页、顾客端店铺展示 | MS2 通过 | 未登记用户签收 |
| MS4 | [09-plan-ms4-ms6.md](09-plan-ms4-ms6.md) §MS4 | 单店购物车、订单、库存、发货、售后、评价 | MS3 通过 | 未登记用户签收 |
| MS5 | [09-plan-ms4-ms6.md](09-plan-ms4-ms6.md) §MS5 | 支付、退款、对账 | MS4 通过，且开放问题 Q4/Q5 已决定 | 未登记用户签收 |
| MS6 | [09-plan-ms4-ms6.md](09-plan-ms4-ms6.md) §MS6 | 发布、恢复、限流、防回滚 | MS5 通过 | 未登记用户签收 |

### 实施与普通验证概览（不替代验收）

目标是通用多商户商城，复用现有商品规格与价格体系。当前来源、开发授权与指定分支源码交接状态见[当前状态](CURRENT-STATUS.md)；BASE 历史单独的 L3 授权保留在原报告，不扩展为后续授权。

| 阶段 | 已有实施或证据 | 尚未闭合的边界 |
|---|---|---|
| BASE | 重建与交付报告已形成；用户原文确认“确认 BASE 通过，授权 MS0a 按 L2 开发” | 仅登记该既有决定，不追加测试或扩大授权；原 SHA、历史结果和证据不改 |
| MS0a | 认证、账号状态与权限收紧已有实现；[报告](records/ms0a/report.md)记录主、独立 HTTP 各 106/106 | 普通/隔离结果不代表生产验证，V10 用户签收未登记 |
| MS0b | 会话 v2 及账号变更、清理、登录下载已开发；[最终普通冻结记录](records/ms0b/report.md)为 30 PASS / 1 NOT RUN | 完整安全验收、真实集成与人工签收未取得；停止项不恢复 |
| MS1 | 商家、员工/角色、上下文及管理接线已有实现；[普通报告](records/ms1/report.md)含 API、Worker 局部证据及聊天界面正常流程 | 原组合失败保留；整套回归、全部界面功能、真机与人工验收未完成 |
| MS2 | 结构/归属命令及商品、库存等已有实现与[普通记录](records/ms2/four-command-normal-summary.json) | 各局部通过不能汇总为完整 MS2；历史归属映射和完整迁移/商品链验收未闭合 |
| MS3 | 已有商家、平台页面源码及[构建检查记录](records/ms3/frontend-source-checkpoint.json) | 页面源码或构建不等于真实业务 UI；顾客店铺展示与完整阶段验收仍未闭合 |
| MS4 | 交易、履约、售后、评价已有实现；最新实际来源、失败边界及候选状态见[当前状态](CURRENT-STATUS.md) | 新clean完整业务及同源fresh B、售后评价/Worker、原图与三端页面/设备验收仍待，按[当前计划](09-plan-ms4-ms6.md)继续 |
| MS5 | 已有支付核心、测试支付消费者及三端订单查询验证相关实现/源码，见 [支付记录](records/ms5/payment-core-integration.md) | 真实账户、回调、退款、账单/费用及完整业务交付与验证尚未完成；测试驱动不代替真实渠道，外部条件与 L4 实测另列 |
| MS6 | 版本门、发布启动接线、商家请求配额及有限运行日志已有实现和本机局部正常证据，见[本机预览说明](../../help/preview/README.md)与[当前状态](CURRENT-STATUS.md) | 完整R04/R06、发布/恢复、防回滚与全量回归仍待；候选或局部正常结果不代表完整MS6、生产发布或人工签收 |

以下运行段落均按其原执行时点保留；最新检查点见[当前状态](CURRENT-STATUS.md)，不由历史局部结果补签本轮。

历史进展（R31，2026-10-21）：内部测试付款、发收货及288次三方内部读取通过；售后验证在新增订单阶段因五个测试断言caller误用共享M4C前缀合同自然1，完整A失败。原隔离实例已正常收尾并独立核零，14863份原公件及340份close公件保持。当前仅修正五处caller为本地m4ar_expect，金额/商品/规格/价格、生产代码及原业务断言保持；静态契约与固定PHP8.2语法已通过，新Run待运行；尚未签售后、评价、Worker或三端页面通过。真实支付退款对账、发布和人工验收仍待。**整体当前没有可信完成百分比**。

当前开发记录（2026-10-23）：通用多商户商城继续复用原商品列表、SKU、规格、价格和库存体系。Run20261022170115298d9f/eadf的内部测试付款、两次发货、三次收货、五次同key重放及288次内部读取通过；售后评价阶段第175个HTTP请求（评价图片POST）返回HTTP503，六键收据停在preflight，完整A仍失败，Worker和fresh B未开始。该实例已正常关闭原四容器、一内部网、七卷，84项close及12项独立zero原生命令均实际0。后继只在新的正常链初始化缺席的自有uploads目录，并接续订单表单私有图片和店铺完整验收覆盖；旧503的具体前置失败原因尚未证明。本次本地采用范围为九PHP和这两份记录，外部浏览器driver及八卷配置另按真实最终文件绑定；采用状态以实际Git提交为准。源码、语法和构建不替代新完整业务、真实页面或设备验收。真实金融、历史迁移、发布和后续人工签收仍未完成，整体不计算完成百分比。

当前后继记录（2026-10-23，4e八卷；此前各段按其历史时点原样保留）：Source `4e2723c2b96f4e0265b9fc75483e307820f3fe79` 的 Run `20261022200902eba4fa` 已完成原初始化，但支付迁移 driver 实际 exited1、phase=source、receipt=false、private=NOT_CREATED，具体最先触发断言仍 UNKNOWN；本轮内部测试付款、Task6、AR、Worker均未启动，完整A失败。原M239成功只证明完整Source冻结，不能签业务。原三CID、一内部网、完整八卷已精确普通非force收口，close93及独立zero20原生均实际0；Root与非作者联合实际读回报告 `full-a-eight-volume-driver-failed-close-zero-actual-independent-r1/REPORT.json` 为8162B / `52b519f34cdb01b778ac584fc73247563e16b3d15a3112a962e7454566469c75`，529读取窗口全闭、findings为空，原失败保持。后继仅拟本地采用五件既有测试PHP（normal A的支付迁移、early迁移、顾客入口三件，以及UI prepare/backend两件）与本两份docs：全manifest严格使用mysql-data、redis-data、bootstrap-secrets、redis-secrets、app-secrets、fixture-secrets、aftersale-evidence、customer-form-evidence八个当前project专用名称及完整身份，不能按本角色挂载子集放行；UI Source集合为34/37，Web卷为8+4=12，prepare/backend各为ENV9，customer-form卷在prepare为RO（native RW=false及kernel ro），在backend为RW（native RW=true及kernel rw），无ENV8退回。UI候选要求真实preApp owner校验、显式语言准备及其完整独立变化证明，并保独立CU目录/原图完整窗口；r3仍有该窗口finding，r4修正及独审以实际最终报告为准，不能记为已通过或已采用。正常九件profile另只重绑顾客入口115040B / `ebabf552c2c79e12c7cc307804d6f9610495ce67c557b21dcf28f9c28c7dd14c`、七CU表及完整九件DAG，支付迁移和early另两件只进入新M的真实Source groups。A三件已有纯PHP语法0；UI两件和新initializer的必要语法、最终采用身份以实际Git和原回执为准，未取得证据的项不先填全PASS或未来HEAD。新完整A、同HEAD fresh B、三端UI20（20steps/20observer ACK/42nativeframes）与三宽DOM、独立H5九场景、非空历史37步与四CLI、原图/媒体及MP/APP/真机设备、真实支付退款回调对账费用结算、发布恢复和人工验收仍待，完整责任不取消；UI20与独立H5的driver/DAG不得互换。继续仅L2本地，无推送、部署或真实资金；已停止样本仍NOT RUN，Task9新商家客服默认关闭、原平台客服保留，只有BASE既有人工签收，整体无可信完成百分比。

### 当前1b失败实例收口与挂载合同修复（2026-10-23）

原 Run `202610222149258b3850`、Source `1b819d0f7b9e7c6e58c9847a2868b696d9070151` 的完整正常链已失败：初始化与支付结构验证通过，履约迁移入口在 source 阶段退出 1；实际首个抛错仍未知。本轮尚未执行内部测试付款、发货收货、售后评价或 Worker，不以历史通过结果代证本轮。

原 4 容器、1 内部网络、8 专用卷已正常非 force 收尾，95 项原生命令通过；另一次独立核零的 20 项原生命令及六项空查询通过，原源码和公开证据保持。真实结果见仓库外 `full-a-eight-volume-early-failed-normal-close-r2/RESULT.json`（e249c4e68f748a5f83eb974523b41126856b58f9b9be1d03142ff154d9b0ac78）、`full-a-eight-volume-early-failed-independent-zero-r2/RESULT.json`（ce617f6ac8d0b986675c8d6de00e7f8d29376553badbc5faa44267c54679e991）。

本次仅修两处测试前代读取函数：`m4ae_ms5_terminal`、`m4c_entry_predecessor` 对 initializer 的完整挂载清单补入当前 project 的 `customer-form-evidence` 专用卷，严格核名称、唯一目的地和 RW；原配置卷仍为 RO，其他角色和完整八卷身份要求保持。另将 UI backend 对顾客测试入口的文件长度与摘要重绑到新原件，共三份 PHP；商品、SKU、规格、价格、库存及业务断言保持。

固定 PHP 8.2 对三份待采用原件和正向入口的语法检查通过；直接加载完整原 PHP，使用本 Run 原公开 metadata，对初始化和原支付迁移两种前代分别调用两原函数，共四次正向调用通过。原临时容器正常移除、前后专用查询为空。结果 `full-a-initializer-mount-php-positive-actual-r1/RESULT.json` 为 9386 B / `570c95998dfe249a34ddb8b60f338b99d14704ef624319115bf0d919eef0bed1`。这只证明必要语法与这四次实际校验，不代表新的完整业务通过；B 草稿 initializer 集合优化仍只有 Source 及模型证据，未据此签 PHP 或性能通过。依赖函数准确名称为 `m4c_entry_ms5_prerequisites`。

后续仍需新 clean 来源完整正常链及收尾、同来源独立 fresh B、三端页面和设备验收、原图与非空历史升级、真实支付退款对账结算、发布恢复和人工验收。通用多商户目标及复用原商品体系保持；仅 L2 本地，不推送、不部署、不处理真实资金。用户已停止的样本继续 NOT RUN，Task9 新商家客服关闭、原平台客服保留，只有 BASE 已登记人工签收，整体不计算完成百分比。

用户已停止的安全复现、负向、故障、并发/断连及恢复样本继续 **NOT RUN**，不得换名重跑或记为 PASS。本概览不改变原规格、覆盖要求或正式验收流程；后续只据实际证据更新，不以行数、构建成功或准备完成换算项目百分比。

### 2fa顾客准备失败收口与raw环境守恒修订（2026-10-23）

通用多商户商城继续复用原商品列表、SKU、规格、价格和库存。Run `202610222308394826f8` / Source `2fa872e6fda7a8bf46c55f88dd946b972ec79102` / freeze `b141e6e2b57285964e50ac6c1a3ed073273a416158f762ea6762aa095e9c8046` 的初始化、MS5结构、early五组迁移及文件初始化通过；顾客准备在 `business_app` 的 `M4C_FORM_OFFICIAL_INITIALIZE_PRESERVES_ACTUAL_ENV` 校验自然退出1，HTTP调用0、HTTP child为空。内部付款、Task6、售后评价及Worker未开始，完整A未通过。工具连接中断使原S的实际退出值未知、terminal缺席；只读续接观察确认同原CID终态1并正确停止，不补写原S成功，不替换或重启原业务。

原4容器、1内部网和8专用卷已正常非force收尾，95项原生命令实际通过；另一次独立核零20项只读命令及六fresh空查询通过、0资源变更。close结果 `2c3e338f6bd73d23827d227836e6dc537f27c3b1cba857f5261fd264993f222a`，zero结果 `68563e388b8b7b5dbfea3d9427fac6375cde4df3a58954a021601b83c7d5a5a5`；原36962份公件、Source和真实失败记录保持。独立zero同时完整核过95项close原始回执；收口不回补业务通过。

确定的Source错配位于共享 `lib/ms4-customer-http-normal.php::m4c_app`：无参 `Env::get()` 返回raw data，命名getter会规范名称并将部分字符串转换bool，旧校验混用两种语义。公开失败未记录实际首个差异键和值，不宣称唯一现场firstdiff。本次单lib有限修订只在官方 `initialize()` 后取同域raw快照，对全部原键要求存在且原值严格 `===`；原assertcode、初始化调用及后续typed配置/PDO/订单/表单/源门保持，不新增全named预读。候选65119B / `925314fc7050d8e1e0928aa056f680fed88662cf8f4b2ae2f87d070b9ae6de2d` 已独立Source及固定PHP8.2纯语法通过，语法结果 `70acadd6d0e4ef9116bf4f52fa9215637c76e486630011a72a2db71dc9d0f34c`；这不代表修后实际业务已通过。

仓库外新normal initializer另复用既有B已审的两处集合查询，完整Source读取、hash、顺序和descriptor严格相等均保持；候选78955B / `7e2db8b37a680ad558f40fd648cf7e868aadaf60fa4d42961c83f37f71d0600b` 的Source及必要纯语法通过（结果 `dbfad08d6237f13df59366b962a2c5490dcb0bf65bf07a8823aea759b42a6259`）。实际PHP manifest、耗时及新N9来源/DAG采用仍待；不修改旧冻结profile或宣称已测性能。本次本地采用范围仅上述共享lib及本两份记录，实际提交身份以Git为准，正常profile在新完整来源中另行绑定。

仍需新clean同源完整A及正常close/zero、成功同源fresh B、三端UI20/20observer ACK/42native frames与320/390/1440 DOM、独立H5九场景、顾客真实multipart/原图、非空旧历史37步与四CLI/完整旧权益归属和最终enabled=true；真实支付退款回调对账费用结算、发布恢复及人工验收未完成。MP/APP正式构建仍缺明确正式HTTPS API origin。继续L2本地，无推送部署或真实资金；停止样本保持NOT RUN，Task9新商家客服关闭、原平台客服保留，只有BASE既有人工签收，不计算整体百分比。

## 3. 已定决策

| 编号 | 决策 | 来源 |
|---|---|---|
| D1 | 路线 A：在 `tianweiwang/tianli-mall` 现有 CRMEB 上改造；目标产品版本为多商家 v0.1；不做预约、检测、区域代理、定制会员、补贴，不合并 H5 Demo | 用户，2026-10-11 |
| D2 | 基线采用方案 B：从 `master@3e2a1a158149dbf35b4438f5d317b6ec2316e166` 重建，迁入备份快照中经审阅的修改和审计修复；备份分支 `codex/private-backup-20261007-tianli-crmeb` 只读保留，不合并 | 用户，2026-10-11 |
| D3 | MS0 拆为 MS0a（最小安全修复，复用现有令牌格式和会话白名单）和 MS0b（会话 v2）。MS0b 不是 MS0a 合入的前提 | 用户，2026-10-11 |
| D4 | `mer_id` 是唯一的经营商家归属字段；不新增同义的 `merchant_id`；`0` 不代表自营或全局；自营商家是显式创建的正整数 ID | 规格 v0.3 |
| D5 | 新商家体系用 `marketplace` 命名空间、`crmeb/app/merchantapi/` 应用、`template/merchant/` 前端；旧 `adminapi/merchant` 路由仍然表示门店/店员/核销 | 规格 v0.3 |
| D6 | MS0–MS3 的测试扩展 `help/audit-regressions/`，不引入 PHPUnit，不升级依赖树 | 规格 v0.3 |
| D7 | 商品分类为平台全局只读；运费模板、规格、素材归商家；评价的经营归属跟随原订单商品 | 规格 v0.3 |
| D8 | `openspec/changes/add-store-booking` 标记 PAUSED（路线 A 范围外），保留文件和勾选状态 | 规格 v0.3 |
| D9 | 本轮不改变 master 对 `crmeb/vendor`、`crmeb/public` 的跟踪方式；是否停止跟踪构建产物另行决定（Q2） | 本目录，默认值 |
| D10 | `crmeb/.env` 停止跟踪（master 中是空文件且已在 `.gitignore`）；备份快照中对上游演示凭据的清理（地图 key、短信 token、微信 appsecret、示例 token）迁入新基线 | 本目录，默认值 |

## 4. 开放问题（未决定前，相关里程碑不得开工或不得发布）

| 编号 | 问题 | 阻塞 | 默认处理 |
|---|---|---|---|
| Q1 | 审计证据文档（`openspec/changes/fix-audit-findings-20261010/tasks.md`、`help/audit-regressions/frontend.md`）中的本机绝对路径是否脱敏 | 不阻塞 | 私有仓库，保留原文 |
| Q2 | 是否停止跟踪 `crmeb/vendor`、`crmeb/public` 构建产物 | 不阻塞 MS0–MS2；MS6 发布前必须决定 | 保持 master 现状 |
| Q3 | master 历史中出现过的演示凭据（见 01 文档 F-20）是否属于本项目自有、是否需要轮换 | 阻塞 MS6 发布 | 视为上游公开演示值，发布前由用户确认 |
| Q4 | 收款产品、经营主体、商户账户方案 | 阻塞 MS5 开工 | 无 |
| Q5 | 平台费用的承担方、计算基数、结算时点、退款后费用是否退回 | 阻塞 MS5 结算功能 | 不开放自动结算与提现 |
| Q6 | 后台登录失败后服务端不强制验证码（01 文档 F-17）是否纳入 MS0b | 不阻塞 MS0a | 纳入 MS0b 候选，开工前确认 |
| Q7 | 管理端令牌允许经 URL `?token=` 传递（01 文档 F-18）是否收紧 | 不阻塞 MS0a | 纳入 MS0b 候选，开工前确认 |
| Q8 | 商家员工初始密码的发放方式 | 阻塞 MS1 上线（不阻塞开发） | 平台生成一次性设置链接，不发明文口令 |

## 5. 目录约定

```text
docs/multi-merchant/
  README.md                 本文件：索引、决策、状态
  00-…12-…                  规格、事实、计划、环境、矩阵、验收流程、执行规约
  records/                  每个里程碑的实际执行记录与验收签收（见 records/README.md）
AGENTS.md                   仓库根目录，Codex 自动加载的入口
```

规格类文件（00、01、03、10、11、12）的修改需要用户批准。执行中发现文档与代码不符时，按 12 文档 §5 停止并回报，不要自行改写规格来迁就实现。


### 53e付款测试失败收口与两入口合同修订（2026-10-23）

通用多商户目标及原商品列表、SKU、规格、价格、库存复用保持。Run `20261023003421d1fc4b` / Source `53e6d521b387b19a4cf370e0e381670b762d325e` 的初始化、MS5结构、early 20条迁移命令、文件初始化和顾客准备实际通过；付款producer实际退出1，原S49572实际native/wait1且原terminal存在。公开日志只有 `M4C_PAID_UNCLASSIFIED_SOURCE_FAILURE`，付款结果公件缺席；catch可能在发布时再失败，因此实际首Throwable位置、phase与HTTP完成数仍UNKNOWN，不从缺公件推定HTTP0或唯一实因。Task6、售后评价、Worker和fresh B尚未启动，本完整A失败。

该Run实际只剩DB2+paid三CID，顾客准备0已由原paid-create正常退役，不再操作。原3容器、1内部网络、8专用卷已普通非force收尾，93项原生命令实际0；独立zero20项只读命令实际0、六fresh查询为空、0资源变更，38245份原公件及31008条两源码树记录保持。close结果104458B / `f8caa4f49f5d008c802a9820f663ee218f920d0c3e57db34fc1afe53d15a6e7e`，zero24927B / `b76c7f627eea39ea50d6aedf88652f631e1bbd3966c48daa151fd01b5ccbb343`。Root实际逐项读回两者113条native创建/终态/完整双流，未用收尾结果回补业务通过。

确定的Source缺陷是付款与履约入口把 `CU_FORM_FIXED_ROLE_ROOT` 交给仅接受 `M4C_` 前缀的共享断言函数，前置code检查会无条件抛错；两工厂还残留raw环境快照与命名getter混比。修订只将两名称改为合法 `M4C_CU_FORM_FIXED_ROLE_ROOT`，两次官方initialize后用同域raw快照逐原键存在及严格值===比较；付款catch入口另捕原异常类型、代码basename/line及phase，仅以固定安全字段写STDERR，不取message/args/trace/环境，保原code、stdout和result合同及原失败优先级。诊断自身I/O失败不会覆盖原失败。源码缺陷与现象相符，仍不宣称已证明本轮唯一现场first cause。

两个候选PHP在固定PHP8.2、nonroot、network none、RO容器内实际各一次 `php -l` 通过；8项原生命令和外层40000 native/wait0，原同CID普通移除、前后自有label查询为空。必要语法结果9021B / `960e77e3193b9aef06492dc282400d403a31a815c74e2a64d07d7e6b5825443c`；Root合并实际读回2514B / `87c2de85205ebd5ddcb0d9faa47f769ac6a4f78291c674f707bb5b37e0ff8cd5`。这只证明修订的Source与必要语法，尚无修后完整业务通过。此次本地采用范围仅两份测试PHP及本README/09计划，实际提交以Git为准；原冻结Source/Run/失败结果不改。

后续仍需新clean同源完整A及close/zero、同HEAD fresh B、三端UI20/20 ACK/42原生frames与三宽DOM、独立H5九场景、顾客真实multipart/原图、非空旧历史37与四CLI/完整权益归属/current reader/最终enabled=true；真实支付退款回调对账费用结算、发布恢复及人工验收未完成。MP/APP正式构建仍缺明确正式HTTPS API origin。继续仅L2本地，无推送部署或真实资金；已停止样本保持NOT RUN，Task9新商家客服关闭、原平台客服保留，只有BASE既有人工签收，不换算整体百分比。
