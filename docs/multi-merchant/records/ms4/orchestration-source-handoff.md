# 编排源码交接补充

日期：2026-10-23。范围：补齐源码交接、离线完整性校验和真实状态记录，不构成业务或发布验收。

本文保留各次交付时点记录。最新补充见文末“881 编排与作者源码补充”和 [R4 摘要](current-source-handoff-r4.json)；早期 `2e4`、`584` 的运行状态不是当前业务终态。

## 问题与交付

此前交接引用仓库外 `ms4-worker-normal-source-preparation-r1`，接收方没有原机目录，Git 也缺完整主编排程序。这是源码交付遗漏。

源码现归档于 [help/audit-regressions/orchestration](../../../../help/audit-regressions/orchestration/README.md)，最新交接分支为 `codex/orchestration-handoff-20261023`，业务修复分支为 `feature/ms4-asset-consumers`，不是原 `codex/audit-base`。逐文件清单记录原相对路径、长度和 SHA-256，原件字节与历史失败版本保留。

- 旧完整编排及两份 published-current 后继包含 materializer、validation driver、launcher、九件实际 profile 和其余来源控制；当前后继完整 18 件由离线校验器逐项检查。
- 后续源码包括 LOAD-01 固定装载修复、完整 startup 检查、作者程序、正逆变更和有许可证的公开 PHP 上游依据。
- 原 Run、双流、私有环境、账号、Cookie、上传和数据卷不入库。原始运行收据仍需另行取得，不能由源码补签。

在只有该 Git 目录的独立导出中执行：

```text
python -I -B help/audit-regressions/orchestration/verify_sources.py
```

预期 `PASS_SOURCE_ARCHIVE_ONLY`。该命令只读取本目录、核摘要与 AST/JSON，不执行存档程序或连接原机、Docker、数据库及网络。通过只证明源码完整性；`runtime_ready` 保持 false。

## 实际结果与未完成边界

旧业务链 `53e6d521b` 的付款进程失败，资源已正常收尾并独立核零。后续绑定 `584532ba2` 的完整 r1 materialize 实际前 672 条原生操作退出 0，第 673 条源码冻结失败；外层 native/wait 为 1，没有创建业务 Run、App 或业务资源。

原完整 freeze 的只读诊断确认首个固定错误为 `EXACT_REVIEWED_REAL_PAID_ENTRY`，原因是独立 caller 检查仍引用旧入口版本。r2 只修活跃 paid/fulfillment 合同并重闭原依赖，保留历史描述和全部业务检查。新 initializer 的固定 PHP 8.2 纯语法作业实际 8 条 native/wait 为 0，原容器普通移除；这不代表执行了 initializer 或业务。

两份 published-current 完整快照仍绑定历史提交 `584532ba2`。本次归档会前移 HEAD；当前工作树或归档后的提交未被这些历史 Source 材料签收。运行前仍需补齐新提交来源、原回执依赖、环境和全部实时准入，不能直接启动归档程序。

r2 原冻结设计保留了两句旧措辞；随包的[设计澄清](../../../../help/audit-regressions/orchestration/sources/published-current-normal-next-source-r2-design-clarification-r1/CLARIFICATION.md)纠正其当前工作树含义。其最终作者结果只核历史 `584532ba2`：复用原 Main 已闭合窗口，另读历史 Git 对象和 Val 194 项；未断言当前 Main。完整运行的实时检查保持。

LOAD-01 R2 已有独立源码核对与 PHP 纯语法结果。startup R1 已有独立源码核对，新候选语法、最终来源与 Host/current-reader/启用准入、完整 37 步业务、四条 CLI、正常收尾和独立核零仍待。完整交易、Worker、页面及后续各里程碑均按原计划验收；用户停止的样本继续 NOT RUN。

## 当前 2e4 编排补充

上述内容记录原 `2e4` 归档提交之前的交付。本次在独立 `codex/orchestration-handoff-20261023` 分支补入 `full-a-2e4-current-normal-next-source-r2`：完整 18 件和 4 件作者控制，共新增 22 文件、6,132,173 字节。原 233 条归档原件和清单条目保留；总计 255 文件、43,909,804 字节、25 包。

新增原件包括显式 `import ast` 修复后的 M/S、launcher、九件实际 profile、其余来源控制与作者源码。离线验证器同时对当前 M/S 做递归符号表检查，不 import 或执行归档程序。两份历史 `584` 完整编排继续保留，当前 required 18 件改为绑定业务提交 `2e4af47c7595947e16bef0abfa9785823b1369d5` 的版本。

该来源的实际完整 M 已有 1,037 条原生命令 native/wait 0；[仓库内摘要](current-source-handoff-r3.json)记录结果边界与外部 Root 回读报告摘要。准备交接时原 S 运行中，没有将其业务结果、付款、履约、售后评价、Worker 或资源收尾签为通过。原 S 自带正常关闭与独立核零；若原 S 成功，不能重复关闭。

补充分支从业务来源 `2e4` 派生，原 Main/Val 保持 `2e4`；保存源码的后续提交不是运行中的业务来源。原实际收据与双流、完整作者重放输入、历史依据和私有配置仍不入库。换机复跑尚缺这些前提及新的隔离环境资格，本次只补齐最新完整编排的源码交接，不宣称可移植运行或完整商城验收。

## 881 编排与作者源码补充

新增 `full-a-881-form-prefix-normal-next-source-r3` 的完整 18 件主编排和 8 件作者源码/设计，共 26 文件、7,126,397 字节。包括完整 materializer、validation driver、launcher、九件 profile，以及 assemble、source_reader、author_support、三项作者程序、拼装片段和设计。原 255 条清单及原件保持不变；合计 281 文件、51,036,201 字节、26 包。

最新包绑定业务提交 `8819524a60028090c55bdcd3a9da05e50eaf19d0`。该来源实际 M 的 1,046 项操作全部 native/wait 0，Root 与独立完整回读通过，摘要指纹记在 [R4 记录](current-source-handoff-r4.json)。这是源码物化与冻结结果，完整业务仍未验收；本次源码交接没有执行其中任何程序。

交接分支业务代码树仍基于 `2e4`，最新 `8819524` 业务修复在 `feature/ms4-asset-consumers`。归档提交既不替代 `8819524` 的来源绑定，也不推进 Main/Val 或重启其原验证。离线检查新增作者成员和本地模块闭合检查，继续只做原字节、AST、JSON、符号表验证。

缺少完整编排源码的交付问题在本次补齐；原始运行材料并未随 Git 提供。完整作者重放输入、原始运行回执、原机路径、私有准备及新环境资格仍是跨机复跑前提，`runtime_ready=false`。原停用样本保持 NOT RUN，源码完整不替代业务验收。


## 当前交付复核与881实际终态

远端 `codex/orchestration-handoff-20261023` 的源码交接提交 `6b96e2d` 已逐文件复核：281文件、26包、51,036,201字节，完整18件主编排和8件作者源码/设计均存在。离线验证实际输出 `PASS_SOURCE_ARCHIVE_ONLY`；原始281件字节未修改。见[复核摘要](orchestration-delivery-readback-20261023.json)。

881来源实际S随后退出1，订单准备容器退出1，固定断言为 `M4C_FORM_NORMAL_HTTP_JSON_200`，阶段为 `paid_real_confirmation`；后续履约、售后评价和Worker未启动。断言位置不等于已确定根因。108项Root命令退出0仅证明观察命令正常结束，不能把被观察容器的失败写成业务通过。原失败实例保留，本次正常回收与独立核零待完成；旧2e4实例已回收的记录不用于签本次。

本次摘要只记录可公开事实和仓库外原件摘要，不包含原Run、日志、私有配置或完整运行证据。完整编排源码缺失已补齐；原始验证材料未随Git交付和跨机完整复跑仍未解决。后续新候选和未完成准入不得被本次源码交接签为通过。
