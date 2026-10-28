# 多商户正常验证编排源码

本目录补齐此前只保存在原机仓库外 `ms4-worker-normal-source-preparation-r1` 中的编排程序。`sources/` 按精确允许清单保存原文件的完整字节；[source-manifest.json](source-manifest.json) 记录逐文件来源相对路径、长度、SHA-256 和角色。

这是**源码归档和恢复资料**。包含旧完整编排、后续失败版本和修正后的完整编排源码。本目录不宣称可直接跨机器运行或业务验证通过。交付分支为 `feature/ms4-asset-consumers`；最早的 `codex/audit-base` 链接不包含本次交接。

## 当前候选的正常回归接入

`current_source_inventory.py` 与 `current_normal_source.py` 是当前候选的本机作者入口，归档 `sources/` 保持原字节。前者核完整 Git blob、两份独立干净检出和保护目录，只输出 `CAPTURED_NOT_APPROVED`；后者必须读取调用方明确指定摘要的独立技术复核，不能把捕获结果自行批准。每次来源 SHA 必须在 authority、独审、完整捕获与差量中一致，再固定到生成的 M/S；更换候选必须重新核对来源，不能沿用旧批准。

新版复用已存在的九卷 Core profile（含独立 `source-tree`），更新当前售后入口的必要固定引用及摘要依赖。旧八卷归档不通过替换 HEAD 或摘要获得新资格。新材料写入仓库外目录；旧运行、失败、退役和来源记录仍按原身份保留。完整正常迁移、顾客、测试付款、履约、售后评价、Worker、普通关闭和独立核零的责任不减；源码派生、语法检查和来源技术通过都不代表这些业务已经运行。

同一作者的 `--b-source <新目录> --b-inputs-file <固定输入JSON> --b-inputs-sha256 <摘要>` 有限接入原13卷B来源；仍须提供 `--authority-file/--authority-sha256`。输入绑定真实M及外层双流、成功A的外层终态/正常结果/关闭/独立核零/后续历史五项证明，以及当前五材料和三端实际构建依据；A未通过时不生成B。来源包沿原17成员和浏览器合同固定完整摘要，两个Root入口在执行时读取独立技术复核，这不是新增用户确认。原A默认派生、历史记录、业务检查及停止样本范围保持。

B的两个网络使用固定输入 `network_subnets.isolation/ui_web`，只接收互不重叠的规范RFC1918网段并核实际IPAM。Root启动前仍须检查地址空闲；复用A网段须先有真实关闭及独立核零。部署public采用同一根部install排除投影，完整安装器来源仍保全；三端构建保留各自真实来源，只有完整输入一致才能复用。

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
| `nonempty-history-normal-startup-components-source-r1` | 完整 producer、启动检查、作者程序、正逆变更及公开上游源码依据 | 独立源码核对通过；新候选语法及完整业务待验，最终准入仍未闭合 |
| `nonempty-history-host-handoff-components-source-r1` | 完整 Host 和交接接缝 | 最终资格未绑定，原拒绝入口保持 |
| `full-a-paid-producer-failed-close-source-r1` | 原失败实例的正常关闭、独立核零源码 | 原实例已经退役；不得用旧程序操作新实例 |
| 三个 customer-form 来源与 proof INI | 原精确 PHP 快照及内存配置源码 | 只保存源码，不替代原运行回执 |

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
