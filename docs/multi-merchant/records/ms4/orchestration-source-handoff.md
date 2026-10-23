# 编排源码交接补充

日期：2026-10-23。范围：补齐源码交接、离线完整性校验和真实状态记录，不构成业务或发布验收。

## 问题与交付

此前交接引用仓库外 `ms4-worker-normal-source-preparation-r1`，接收方没有原机目录，Git 也缺完整主编排程序。这是源码交付遗漏。

源码现归档于 [help/audit-regressions/orchestration](../../../../help/audit-regressions/orchestration/README.md)，交付分支为 `feature/ms4-asset-consumers`，不是原 `codex/audit-base`。逐文件清单记录原相对路径、长度和 SHA-256，原件字节与历史失败版本保留。

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
