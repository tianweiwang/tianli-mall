# BASE 交付报告

## P1 基本信息

- 初始 BASE 执行授权：L2；日期：2026-10-11；分支：`codex/audit-base`；初始交付未推送。后续批准的记录修正、契约修订和 L3 推送授权见文末补记。
- 工作目录：`/Volumes/Kingston/cdx/projects/tianli-mall--audit-base`。
- 起点 SHA：`3e2a1a158149dbf35b4438f5d317b6ec2316e166`；开工时远程 master 与此相同。
- 备份快照：`9580c80381e9e03823d8d69495a54491a3c3a6a9`；只读来源：`/Volumes/Kingston/cdx/projects/tianli-mall--private-backup-20261007-tianli-crmeb`。
- 交付基线 / `AUDIT_BASE_SHA`：`9685731dcd116784d38e6bbdb902cec3c0c84975`，即 Task 4 Step 3 提交。
- 记录运行标识：`base-20261011-9685731dcd11`。它仅标识本次文件核验，不是数据库隔离环境 RUN_ID。
- P2：本报告；P3：同目录 `results.jsonl`。所有结果的 `commit_sha` 均为 `AUDIT_BASE_SHA`。
- 记录提交 `docs(records): BASE rebuild report` 是基线后的单独提交，不计入 `AUDIT_BASE_SHA`；其 SHA 在最终回传中列出。

| SHA | 标题 |
|---|---|
| `3b238a8cec8bad62810815c0fed8112193437254` | `chore: port reviewed changes from private backup 9580c80` |
| `08198b135409eb82bb96a12a3f0900207606b897` | `fix: audit findings 2026-10-10 (A1-A9, B1-B5)` |
| `6f84c83755fe9c9afaa595ffb6fda101aed52430` | `docs: multi-merchant development docs` |
| `9685731dcd116784d38e6bbdb902cec3c0c84975` | `chore(openspec): pause add-store-booking (out of route A scope)` |

执行清单（按原计划顺序，保留迁入计划文档原样）：

- [x] Task 1：记录备份状态、核实远程 master、建立指定 worktree 和分支。
- [x] Task 2：迁入清单 A 的 5 个文件并停止跟踪空 `crmeb/.env`，单独提交。
- [x] Task 3：迁入 B/C 的 47 个文件，内容/LF/语法/security/敏感扫描通过，单独提交。
- [x] Task 4：16 个治理文档原样迁入并提交；两个预约文档加 PAUSED，另行提交。
- [x] Task 5 Step 1–3：变更集合、演示字段、备份不变量核验通过。
- [x] Task 5 Step 4 记录准备：本报告和 58 行结果已写入，格式、覆盖和提交范围检查通过；记录提交 SHA 见最终回传。

Task 5 Step 5 停止安排：记录提交及回传完成后停在 SP-BASE，等待验收。

## P4 变更文件

`3e2a1a158149dbf35b4438f5d317b6ec2316e166..9685731dcd116784d38e6bbdb902cec3c0c84975` 共 **67** 个路径：36 A、30 M、1 D。与输入清单逐项一致，遗漏 0、额外 0。A 与 B 的 `manifest.json` 只计一次。清单 C 的三个导出文件与 master 字节相同，因此不产生 diff。

| 路径 | 状态 | 对应任务 | 计划内 |
|---|---|---|---|
| `.gitignore` | M | Task 3 / B | 是 |
| `AGENTS.md` | A | Task 4 / E | 是 |
| `crmeb/.env` | D | Task 2 / A | 是 |
| `crmeb/app/adminapi/controller/PublicController.php` | M | Task 3 / B | 是 |
| `crmeb/app/api/controller/v1/PublicController.php` | M | Task 3 / B | 是 |
| `crmeb/app/dao/BaseDao.php` | M | Task 3 / B | 是 |
| `crmeb/app/dao/activity/coupon/StoreCouponUserDao.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/activity/coupon/StoreCouponUserServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/activity/lottery/LuckLotteryRecordServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/activity/lottery/LuckLotteryServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/admin/StoreManageServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/order/StoreOrderComputedServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/order/StoreOrderCreateServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/order/StoreOrderRefundServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/pay/YuePayServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/system/admin/SystemRoleServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/user/UserRechargeServices.php` | M | Task 3 / B | 是 |
| `crmeb/app/services/user/UserServices.php` | M | Task 3 / B | 是 |
| `docs/multi-merchant/00-implementation-spec.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/01-code-facts.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/02-plan-base-rebuild.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/03-test-environment.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/04-plan-ms0a.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/05-plan-ms0b.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/06-plan-ms1.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/07-plan-ms2.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/08-plan-ms3.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/09-plan-ms4-ms6.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/10-acceptance-matrix.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/11-acceptance-process.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/12-codex-protocol.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/README.md` | A | Task 4 / E | 是 |
| `docs/multi-merchant/records/README.md` | A | Task 4 / E | 是 |
| `help/audit-regressions/README.md` | A | Task 3 / C | 是 |
| `help/audit-regressions/commerce.php` | A | Task 3 / C | 是 |
| `help/audit-regressions/diy-http.py` | A | Task 3 / C | 是 |
| `help/audit-regressions/frontend-browser.cjs` | A | Task 3 / C | 是 |
| `help/audit-regressions/frontend-built-browser.cjs` | A | Task 3 / C | 是 |
| `help/audit-regressions/frontend-regression.cjs` | A | Task 3 / C | 是 |
| `help/audit-regressions/frontend-source-check.cjs` | A | Task 3 / C | 是 |
| `help/audit-regressions/frontend.md` | A | Task 3 / C | 是 |
| `help/audit-regressions/security.php` | A | Task 3 / C | 是 |
| `help/audit-regressions/theme-module.php` | A | Task 3 / C | 是 |
| `help/audit-regressions/wallet-admin.php` | A | Task 3 / C | 是 |
| `help/audit-regressions/wallet-store.php` | A | Task 3 / C | 是 |
| `help/docker/probes/php82-integration-compatibility.php` | M | Task 2 / A | 是 |
| `help/docker/verify-php82.ps1` | M | Task 2 / A | 是 |
| `help/migrations/README.md` | A | Task 3 / C | 是 |
| `help/migrations/theme-module.php` | A | Task 3 / C | 是 |
| `openspec/changes/add-store-booking/proposal.md` | M | Task 4 / E | 是 |
| `openspec/changes/add-store-booking/tasks.md` | M | Task 4 / E | 是 |
| `openspec/changes/fix-audit-findings-20261010/design.md` | A | Task 3 / C | 是 |
| `openspec/changes/fix-audit-findings-20261010/proposal.md` | A | Task 3 / C | 是 |
| `openspec/changes/fix-audit-findings-20261010/tasks.md` | A | Task 3 / C | 是 |
| `template/admin/package.json` | M | Task 3 / B | 是 |
| `template/admin/src/pages/notify/smsConfig/index.vue` | M | Task 2 / A | 是 |
| `template/uni-app/.env.development` | A | Task 3 / C | 是 |
| `template/uni-app/components/skeleton/index.vue` | M | Task 3 / B | 是 |
| `template/uni-app/config/api-origin.js` | A | Task 3 / C | 是 |
| `template/uni-app/config/app.js` | M | Task 3 / B | 是 |
| `template/uni-app/config/load-api-env.js` | A | Task 3 / C | 是 |
| `template/uni-app/manifest.json` | M | Task 2 / A；Task 3 / B | 是 |
| `template/uni-app/pages/points_mall/index.vue` | M | Task 3 / B | 是 |
| `template/uni-app/pages/users/user_vip_areer/index.vue` | M | Task 3 / B | 是 |
| `template/uni-app/store/getters.js` | M | Task 2 / A | 是 |
| `template/uni-app/utils/request.js` | M | Task 3 / B | 是 |
| `template/uni-app/vue.config.js` | M | Task 3 / B | 是 |

Task 5 记录提交另外新增且仅新增 `docs/multi-merchant/records/base/report.md` 和 `docs/multi-merchant/records/base/results.jsonl`；包括记录后应为 69 个路径。`crmeb/.env` 是计划明确要求的索引删除，本地 0 字节文件保留。master 中的 `vendor`、`public`、目录占位、后台构建配置及 `.constant` 均保留；没有迁入 `.private-backup/`、`docs/prototype/`、本机 Docker 运维修改或运行产物。

## P5 实际运行的命令

除注明来源外均在新 worktree 执行。备份只读 Git 调用一律设置 `GIT_OPTIONAL_LOCKS=0`；唯一写入是 Task 1 登记新 worktree。部分 Git 命令由 Python `subprocess` 以参数数组调用，排序、复制和比对使用 Python；未假装执行计划中的 shell 循环。下表路径参数 B/C 分别为证据目录中 `list-b.txt` / `list-c.txt` 的明确路径，不是通配暂存。

| 实际命令 / 操作 | 退出码 | 说明 |
|---|---|---|
| 备份：`git status --short --untracked-files=normal`、`git rev-parse HEAD` | 0 | 开始与结束快照；26 个已修改跟踪项，12 个合并显示的未跟踪项 |
| 备份：`git ls-remote origin refs/heads/master` | 0 | 返回指定起点，未 fetch |
| 备份：`git cat-file -e 3e2a1a158149dbf35b4438f5d317b6ec2316e166^{commit}` | 0 | 对象已存在 |
| 备份：`git worktree add -b codex/audit-base /Volumes/Kingston/cdx/projects/tianli-mall--audit-base 3e2a1a158149dbf35b4438f5d317b6ec2316e166` | 0 | 新目录、新分支 |
| 备份：对 A 逐文件 `git show 9580c80381e9e03823d8d69495a54491a3c3a6a9:<path>` | 0 | 5 个文件去 CR 写入；内容摘要见 task2-copy.json |
| `git rm --cached -- crmeb/.env` | 0 | 只从新 worktree 索引移除 |
| 对 B/C 逐文件 `read_bytes().replace(b'\r', b'')` 后 `write_bytes()`，再字节比较 | 0 | 47/47 MATCH；NO CR；只写新 worktree |
| `/opt/homebrew/opt/php@8.2/bin/php help/audit-regressions/security.php` | 0 | PHP 8.2.34；23 PASS、0 FAIL；UNIT-STUB |
| `git diff 3e2a1a158 -- .`，输出在内存中作为 `grep -nEi` 的 stdin；正则严格取 02 Task 3 Step 4 | diff 0；grep 1 | 无输出，按计划通过；未改正则、未排除删除行；扫描发生在 Task 3 暂存前，不是全仓历史凭据审计 |
| `git add --` 后显式传入 A、B、C、文档及两个预约路径；4 次按计划 `git commit -m ...` | 0 | 每次先核对暂存集合；没有通配 add 或 push |
| 原样复制 `AGENTS.md` 和 `docs/multi-merchant/`；两个预约文档插入指定标记 | 0 | 文档 16/16 字节相同；预约各 +2/-0，勾选状态保留 |
| `python3 /Volumes/Kingston/cdx/projects/audit-base-evidence/verify-base.py` | 0 | 执行 Task 5 全部不变量；具体 Git 调用见该脚本 |
| `git diff --name-status 3e2a1a158149dbf35b4438f5d317b6ec2316e166 9685731dcd116784d38e6bbdb902cec3c0c84975`，在 Python 中排序写入 `base-diff.txt` | 0 | 67 路径逐项等于输入清单 |
| `git show 9685731dcd116784d38e6bbdb902cec3c0c84975:template/uni-app/manifest.json`，仅输出键名、位置及是否为空 | 0 | 5 个字段位置全部为空 |
| `diff /Volumes/Kingston/cdx/projects/audit-base-evidence/backup-status-before.txt /Volumes/Kingston/cdx/projects/audit-base-evidence/backup-status-after.txt` | 0 | 无输出；`backup-status.diff` 为 0 字节 |
| `python3 /Volumes/Kingston/cdx/projects/audit-base-evidence/write-base-records.py` | 首次 1；兼容修正后 0 | 首次调用因本机 Python 的 `Path.write_text()` 不支持 `newline` 参数而在写入前退出；仓库外记录生成脚本改用 UTF-8 `write_bytes()`；没有修改迁入源码或测试 |

PHP 语法检查逐文件执行，共 21 个；以下资金或迁移脚本仅 `-l` 解析，未执行：

| 实际命令 | 退出码 |
|---|---|
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/adminapi/controller/PublicController.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/api/controller/v1/PublicController.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/dao/BaseDao.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/dao/activity/coupon/StoreCouponUserDao.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/activity/coupon/StoreCouponUserServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/activity/lottery/LuckLotteryRecordServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/activity/lottery/LuckLotteryServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/admin/StoreManageServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/order/StoreOrderComputedServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/order/StoreOrderCreateServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/order/StoreOrderRefundServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/pay/YuePayServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/system/admin/SystemRoleServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/user/UserRechargeServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l crmeb/app/services/user/UserServices.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l help/audit-regressions/commerce.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l help/audit-regressions/security.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l help/audit-regressions/theme-module.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l help/audit-regressions/wallet-admin.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l help/audit-regressions/wallet-store.php` | 0 |
| `/opt/homebrew/opt/php@8.2/bin/php -l help/migrations/theme-module.php` | 0 |

会话 Git auto-sync 登记只读核对返回 `project_not_enabled`（exit=1）；未登记项目、未启动同步任务。初始 BASE 执行时禁止推送；后续用户单独授权本分支推送，见文末补记。

## 结果统计

| 层级 | PASS | FAIL | NOT RUN | BLOCKED |
|---|---|---|---|---|
| 记录 | 10 | 0 | 0 | 0 |
| 源码静态 | 21 | 0 | 0 | 0 |
| UNIT-STUB | 23 | 0 | 0 | 0 |
| INTEGRATION | 0 | 0 | 1 | 0 |
| WORKER | 0 | 0 | 1 | 0 |
| BROWSER | 0 | 0 | 1 | 0 |
| EXTERNAL | 0 | 0 | 1 | 0 |

这些层级不可相加。记录层有 10 个核验子场景，对应矩阵 **Z01、Z03、Z05 三条用例，全部 PASS**。源码静态为 21 个语法命令；UNIT-STUB 为脚本原有 23 个子场景。每个 NOT RUN 计一个范围说明组，不虚构业务用例数量。`BASE-LINT`、`BASE-SECURITY`、`BASE-SCOPE` 是本报告补充编号，不修改验收矩阵。

PASS 子场景 finding_kind 分布：{"新增契约": 31, "既有修复回归": 23}。23 项归类表示来自 A1/A2 既有审计修复回归包，不表示新增了 23 个修复。其中 16 项完整断言依赖既有修复，另 7 项是行为原样保留的兼容对照；逐项依据见 `results.jsonl` 的 notes。迁移、治理核验和语法检查归类为新增契约。

## 关键用例

| 用例 | 子场景 | baseline 实际 | after 实际 | finding_kind | 证据 |
|---|---|---|---|---|---|
| Z01 | `backup-context-preserved` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | HEAD=9580c80381e9e03823d8d69495a54491a3c3a6a9；分支、上游、索引摘要相同；状态 diff exit=0，0 字节 | 新增契约 | `task5-backup.json` |
| Z01 | `exact-change-set` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | 67 路径：A=36、M=30、D=1；遗漏=0、额外=0；manifest.json 重叠只计一次 | 新增契约 | `task5-change-set.json` |
| Z01 | `normalized-content-and-lf` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | A 中非重叠的 4 文件与快照去 CR 后一致；B/C 47/47 一致；全部 69 个迁入和治理文本文件无 CR；A 的 manifest 清理后按 B 叠加 | 新增契约 | `task5-lf.json` |
| Z01 | `demo-fields-empty` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | 5 个字段出现位置全部为空字符串；Task 3 规定的敏感信息扫描无输出 | 新增契约 | `task5-manifest.json` |
| Z01 | `empty-env-untracked` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | git ls-files -- crmeb/.env 无输出；本地文件存在且为 0 字节；仅在新 worktree 移出索引 | 新增契约 | `verify-base.py` |
| Z01 | `governance-documents-verbatim` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | 16/16 文件与备份原字节相同；全部 LF；未修改 README 里程碑状态或规格内容 | 新增契约 | `task4-docs.json` |
| Z03 | `paused-marker-and-original-preserved` | 两个文件均无 PAUSED 标记 | 两个文件各 +2/-0；移除新增行后与 master 原字节相同；勾选状态不变 | 新增契约 | `task4-pause.json` |
| Z03 | `separate-governance-commit` | 两个文件均无 PAUSED 标记 | 9685731dcd116784d38e6bbdb902cec3c0c84975 只修改 proposal.md 和 tasks.md 两个预约文档 | 新增契约 | `task4-pause.json` |
| Z05 | `audit-source-archived` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | 23/23 内容与备份去 CR 后一致；20 个新增文件；3 个导出源码与 master 相同且无差异 | 新增契约 | `task3-copy.json` |
| Z05 | `generated-and-excluded-paths-preserved` | 见 Task 1 快照及 02 文档输入清单；未执行 master 业务测试 | 排除路径相对 master 无差异；备份状态不变；未添加、删除或执行本地运行产物 | 新增契约 | `verify-base.py` |

`security.php` 保留 23 个原始用例，逐项原名和结果见 `results.jsonl` 与仓库外 `security.txt`。独立复核方补充使用字节未变的测试脚本，分别加载固定 master 的相关类源码和 `AUDIT_BASE_SHA` 源码做 UNIT-STUB 对照：master 为 7 PASS / 16 FAIL / exit=1，AUDIT 为 23 PASS / 0 FAIL / exit=0。这是选定类源码的内存替身对照，没有启动完整 master 应用或执行真实业务集成测试。未登记路由/方法及既有豁免仍按 BASE 原测试通过，权限收紧属于后续 MS0a，不能据此宣称已完成。

16 项完整断言包括 A1 拒绝行为修复 3 项、缺失方法键的无警告兼容修复 1 项，以及 A2 拒绝行为修复 6 项、拒绝前不解析上传服务的修复 6 项。后一组在 master 中虽已返回拒绝，却因提前解析上传服务而未通过完整断言；不能仅按响应结果或用例名称中的 rejected 将其归为既有拒绝回归。7 项兼容对照在两组源码下均 PASS，不计作新增修复。

## P6 证据位置

仓库外目录：`/Volumes/Kingston/cdx/projects/audit-base-evidence`。索引、内容和文档摘要用于迁移核验；不保存凭据值。执行核验时已有文件如下：

- `AUDIT_BASE_SHA.txt`
- `backup-head.txt`
- `backup-status-after.txt`
- `backup-status-before.txt`
- `backup-status.diff`
- `base-diff.txt`
- `list-a.txt`
- `list-b.txt`
- `list-c.txt`
- `remote-master-before.txt`
- `security-exit.txt`
- `security.txt`
- `task1.json`
- `task2-commit.txt`
- `task2-copy.json`
- `task2-diff-stat.txt`
- `task3-commit.json`
- `task3-copy.json`
- `task3-php-lint.json`
- `task3-sensitive-scan.json`
- `task4-docs.json`
- `task4-pause.json`
- `task5-backup.json`
- `task5-change-set.json`
- `task5-lf.json`
- `task5-manifest.json`
- `task5-verification.json`
- `verify-base.py`
- `write-base-records.py`

原始测试输出是 `security.txt`；每个 PHP 语法退出码在 `task3-php-lint.json`。`task5-change-set.json` 有全部路径的一对一期望/实际核对，`base-diff.txt` 是排序后的 Git 变更集合。备份前后状态文件与 0 字节 `backup-status.diff` 为只读保护证据。`task5-record-validation.json` 记录 JSONL 格式、58 行覆盖、LF 和前四个提交范围核验（Python 检查 exit=0）。记录提交后的完整性核验将另记在仓库外 `task5-final-verification.json`、`task5-lf-final.json` 和 `task5-records-commit.json`，不回写本报告的基线 SHA。

## P7 迁移记录

本次为源码和文档迁入，无 DDL、数据迁移、数据库连接或容器启动。Task 2 从固定备份提交取 A；Task 3 从备份工作树取 B/C。文本统一去 CR；最终 69 个迁入及治理文本文件不含 CR。文档本身已是 LF，因此逐字节原样复制。清单 B/C 47 个源文件和 16 个治理文档在最终核验时与迁入时的源摘要仍相同。

## P8 未运行项

| 用例 / 子场景 | 原因 | 所缺条件 |
|---|---|---|
| INTEGRATION / 资金与真实应用集成脚本 | BASE 禁止数据库连接；隔离实例与后续里程碑授权未建立 | 后续对应里程碑的环境和授权 |
| WORKER / 常驻进程回归 | BASE 未启动应用、队列或 Workerman；需要后续隔离环境 | 后续对应里程碑的环境和授权 |
| BROWSER / 前端真实运行与接口联动 | 本次只迁入源码；需要后续独立的构建与浏览器验证范围 | 后续对应里程碑的环境和授权 |
| EXTERNAL / 支付与其他真实业务第三方 | BASE 未授权外部业务调用；需要对应里程碑和更高授权 | 后续对应里程碑的环境和授权 |

`commerce.php`、`wallet-admin.php`、`wallet-store.php`、`theme-module.php` 均未执行业务逻辑。没有数据库、Redis、HTTP 或 Worker 副作用快照；JSONL 中空 `db_diff` / `cache_diff` 表示没有连接这些资源，不代表已经做过真实环境零副作用验收。

## P9 遗留风险与下一停止点

BASE 的执行要求均已验证；当前交付限于源码迁移、治理记录与 UNIT-STUB 回归。真实应用与前端运行结果不能由本次静态/内存替身结果推出。

下一步为 **SP-BASE，等待验收**。按 11 文档，BASE 验收的 V4 独立复跑和 V7 独立代码审阅必须由未参与实现的复核方参与；执行方未自行签收，未创建 `acceptance.md`，README 状态保持原样。初始交付时没有进入 MS0a、没有推送；后续用户授权见以下补记。

## 2026-10-11 批准修正与推送补记

用户批准修正 BASE 报告分类，并批准对 03、04、11 文档的契约修订。原 `AUDIT_BASE_SHA` 和 58 行测试记录的执行结果、期望、状态、层级、证据引用均保留；本次只修正 23 项 UNIT-STUB 的 `finding_kind` 和 notes 及本报告的解释。原有 16 份治理文档在 `AUDIT_BASE_SHA` 与备份字节一致的历史核验仍有效。

契约修订另行提交为 `3110bd5b3df277c0d9469bcb8987c71165ae1cd0`（`docs: align MS0a tests with approved authorization contract`），仅涉及 03、04、11 文档。它要求后续 MS0a Task 7 在 SP-MS0a-1 确认后，将三个旧旁路的预期由放行收紧为拒绝，保留其他 20 项及前后证据；本次没有修改测试源码或鉴权实现。初始记录提交为 `538d3e384f55634f504efbca6b1a26b42682fbe8`。

独立复核补充证据位于仓库外 `/tmp/base-independent-review-20261011-gfg1gx7c/`：`classification-recommendations.md`、`evidence/classification-recommendations.json`、`evidence/master-stub-source-manifest.json`、`evidence/master-stub-security.stdout`、`evidence/master-stub-security.stderr` 和 `evidence/security.stdout`。原五个提交范围核对见 `evidence/per-commit-scope-review.json`；77 项历史证据核对全部匹配，见 `evidence/historical-evidence-review.json`。这些补充对照不计入原 58 行结果，不提升测试层级，也不构成验收人签收。

用户随后明确授权将这些提交推送到 GitHub，授权范围为 `codex/audit-base` 分支（L3）。推送前已核实仓库 `tianweiwang/tianli-mall` 为 PRIVATE，远程 master 仍为固定起点；实际推送结果及远程 SHA 核对在本轮回传中报告。该授权不包含合入 master、部署或代替验收人签收。隔离测试环境准备另获用户批准，但本次记录修正不表示环境已安装或 MS0a 已完成。
