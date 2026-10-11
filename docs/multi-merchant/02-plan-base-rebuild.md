# BASE：以 master 为基线重建 Implementation Plan

> **For agentic workers (Codex):** 先读 [12-codex-protocol.md](12-codex-protocol.md)。按任务顺序执行，步骤使用复选框（`- [ ]`）跟踪。本计划需要授权级别 L2（在指定分支提交）；推送需要 L3，另行授权。

**Goal:** 在 `master@3e2a1a158` 上建立一条干净的开发线，迁入备份快照中经审阅的修改、审计修复和本目录文档，得到 `AUDIT_BASE_SHA`。

**Architecture:** 用 `git worktree` 在备份检出之外新建工作目录，从 master 切出分支 `codex/audit-base`。备份检出只读，作为文件来源。每个文件按 master 的换行符（LF）写入，用"去掉 CR 后内容完全一致"作为迁移正确性的判据。

**Tech Stack:** git、bash、PHP 8.2 CLI（只做语法检查和现有 UNIT-STUB 回归）。

**Spec:** [00-implementation-spec.md](00-implementation-spec.md) §2，[01-code-facts.md](01-code-facts.md) F-01–F-09、F-20。

## Global Constraints

- 备份检出路径（只读来源）：`BACKUP=/Volumes/Kingston/cdx/projects/tianli-mall--private-backup-20261007-tianli-crmeb`
- 新工作目录：`BASE_WT=/Volumes/Kingston/cdx/projects/tianli-mall--audit-base`
- 新分支：`codex/audit-base`，起点 `3e2a1a158149dbf35b4438f5d317b6ec2316e166`
- 备份快照提交：`9580c80381e9e03823d8d69495a54491a3c3a6a9`
- 备份检出的工作树、索引、未跟踪文件全程不得改变。禁止 `reset`、`clean`、`stash`、`checkout --`，禁止在 `$BACKUP` 中执行 `git add`。
- 禁止 `git add .`、`git add -A`；只按本文件列出的路径暂存。
- 写入 `$BASE_WT` 的文本文件一律为 LF（master 中这些路径都是 LF，见 F-03）。
- 不运行资金测试脚本（F-51），不连接任何数据库，不启动容器。

## Review Focus

- 迁移遗漏或多带：最终 `git diff --name-only 3e2a1a158 HEAD` 必须与 §输入清单完全一致，多一项、少一项都算失败。
- 换行符：任何迁入文件含 CR，都会让后续 diff 全文件变化。Task 5 的检查必须覆盖。
- 演示凭据回流：`manifest.json` 先迁入清理版（Task 2），再叠加审计修改（Task 3）；最终版本里不得出现被清理的值。
- 备份检出被改动：Task 1 记录 `git status` 快照，Task 5 对比。
- master 已前进：开工时远程 master 若不再是 `3e2a1a158`，停止并回报，不自行改起点。

---

## 输入清单

### A. 从备份快照 `9580c8038` 迁入的内容修改（Task 2）

| 路径 | 处理 | 理由 |
|---|---|---|
| `help/docker/probes/php82-integration-compatibility.php` | 迁入 | 新增 `dao-join-aliases` 探针，对应 master 上的 PHP 8.2 动态属性修复 |
| `help/docker/verify-php82.ps1` | 迁入 | 注册上面的探针 |
| `template/admin/src/pages/notify/smsConfig/index.vue` | 迁入 | 清理 iframe 中的 token |
| `template/uni-app/manifest.json` | 迁入 | 清理地图 appkey 和微信 appsecret（Task 3 再叠加审计修改） |
| `template/uni-app/store/getters.js` | 迁入 | 清理注释中的示例 JWT |
| `crmeb/.env` | `git rm --cached` | master 中为空文件且已在 `.gitignore`；停止跟踪，保留本地文件 |
| `.private-backup/README.md`、`.private-backup/manifest.json` | 不迁入 | 备份元数据 |
| `crmeb/.constant` | 不迁入 | 安装实例专属值 |
| `crmeb/backup/readme.md`、`crmeb/runtime/readme.md` 的删除 | 不迁入 | 保留 master 的目录占位 |
| `docs/prototype/`（6 个文件） | 不迁入 | Demo 原型，路线 A 范围外 |
| `template/admin/.env`、`.env.dev`、`.env.production` 的删除 | 不迁入 | 构建配置，不含凭据 |
| `template/admin/src/vendor/*.js` 的删除 | 不迁入 | 审计 A4 恢复的就是 master 版本（F-08） |
| `crmeb/vendor`、`crmeb/public` 的删除 | 不迁入 | 决策 D9 |

### B. 备份检出中已跟踪的审计修复（24 个，Task 3）

| 路径 | 审计项 |
|---|---|
| `.gitignore` | A4、A7 |
| `crmeb/app/adminapi/controller/PublicController.php` | A2 |
| `crmeb/app/api/controller/v1/PublicController.php` | A9 |
| `crmeb/app/dao/BaseDao.php` | A3 |
| `crmeb/app/dao/activity/coupon/StoreCouponUserDao.php` | A5 |
| `crmeb/app/services/activity/coupon/StoreCouponUserServices.php` | A5 |
| `crmeb/app/services/activity/lottery/LuckLotteryRecordServices.php` | A3 |
| `crmeb/app/services/activity/lottery/LuckLotteryServices.php` | A3 |
| `crmeb/app/services/admin/StoreManageServices.php` | A3 |
| `crmeb/app/services/order/StoreOrderComputedServices.php` | A5 |
| `crmeb/app/services/order/StoreOrderCreateServices.php` | A5 |
| `crmeb/app/services/order/StoreOrderRefundServices.php` | A3 |
| `crmeb/app/services/pay/YuePayServices.php` | A3 |
| `crmeb/app/services/system/admin/SystemRoleServices.php` | A1 |
| `crmeb/app/services/user/UserRechargeServices.php` | A3 |
| `crmeb/app/services/user/UserServices.php` | A3 |
| `template/admin/package.json` | A4 |
| `template/uni-app/components/skeleton/index.vue` | B2 |
| `template/uni-app/config/app.js` | A7 |
| `template/uni-app/manifest.json` | B1 |
| `template/uni-app/pages/points_mall/index.vue` | B1 |
| `template/uni-app/pages/users/user_vip_areer/index.vue` | B1 |
| `template/uni-app/utils/request.js` | A8 |
| `template/uni-app/vue.config.js` | A7 |

### C. 备份检出中未跟踪的审计文件（23 个，Task 3）

```text
help/audit-regressions/README.md
help/audit-regressions/commerce.php
help/audit-regressions/diy-http.py
help/audit-regressions/frontend-browser.cjs
help/audit-regressions/frontend-built-browser.cjs
help/audit-regressions/frontend-regression.cjs
help/audit-regressions/frontend-source-check.cjs
help/audit-regressions/frontend.md
help/audit-regressions/security.php
help/audit-regressions/theme-module.php
help/audit-regressions/wallet-admin.php
help/audit-regressions/wallet-store.php
help/migrations/README.md
help/migrations/theme-module.php
openspec/changes/fix-audit-findings-20261010/design.md
openspec/changes/fix-audit-findings-20261010/proposal.md
openspec/changes/fix-audit-findings-20261010/tasks.md
template/admin/src/vendor/Blob.js            # 与 master 相同，不产生差异
template/admin/src/vendor/Export2Excel.js    # 与 master 相同，不产生差异
template/admin/src/vendor/newToExcel.js      # 与 master 相同，不产生差异
template/uni-app/.env.development
template/uni-app/config/api-origin.js
template/uni-app/config/load-api-env.js
```

### D. 不迁入的备份检出内容

| 路径 | 理由 |
|---|---|
| `help/docker/README.md` 的修改 | 2026-10-08 Mac 本机恢复记录，含本机路径和运维信息；留在备份检出 |
| `help/docker/run.sh` | 只有换行符差异（F-06） |
| 未跟踪的 `crmeb/vendor/`、`crmeb/public/`、`crmeb/runtime` | 本地运行产物；不添加，也不删除 |

### E. 文档与治理（Task 4）

- `docs/multi-merchant/` 整个目录和仓库根目录的 `AGENTS.md`，从备份检出原样复制（这些文件本来就是 LF）。
- `openspec/changes/add-store-booking/proposal.md` 和 `tasks.md`：在第一个标题行之后插入一行
  `> **PAUSED（2026-10-11）**：路线 A 范围外，不随多商家改造执行。保留原内容与勾选状态。见 docs/multi-merchant/README.md 决策 D8。`

---

### Task 1: 建立工作目录并固定起点

**Files:** 无仓库文件变更。记录写入 `$BASE_WT/../audit-base-evidence/`（仓库外）。

**Interfaces:**
- Produces: `$BASE_WT` 工作目录，位于分支 `codex/audit-base`，HEAD 为 `3e2a1a158`；`$EVID=/Volumes/Kingston/cdx/projects/audit-base-evidence` 证据目录。

- [ ] **Step 1: 记录备份检出的状态快照**

```bash
EVID=/Volumes/Kingston/cdx/projects/audit-base-evidence; mkdir -p "$EVID"
cd "$BACKUP" && GIT_OPTIONAL_LOCKS=0 git status --short --untracked-files=normal > "$EVID/backup-status-before.txt"
GIT_OPTIONAL_LOCKS=0 git rev-parse HEAD > "$EVID/backup-head.txt"
```

Expected: `backup-head.txt` 内容为 `9580c80381e9e03823d8d69495a54491a3c3a6a9`；`backup-status-before.txt` 有 26 行 ` M` 和若干 `??`。

- [ ] **Step 2: 确认远程 master 未前进**

Run: `git -C "$BACKUP" ls-remote origin refs/heads/master`
Expected: `3e2a1a158149dbf35b4438f5d317b6ec2316e166	refs/heads/master`。若不同，**停止**，按 12 文档 §5 回报。

- [ ] **Step 3: 创建 worktree**

`3e2a1a158` 是备份快照的父提交，对象已在本地，不需要 fetch。

```bash
git -C "$BACKUP" cat-file -e 3e2a1a158149dbf35b4438f5d317b6ec2316e166^{commit} && echo PRESENT
git -C "$BACKUP" worktree add -b codex/audit-base "$BASE_WT" 3e2a1a158149dbf35b4438f5d317b6ec2316e166
```

Expected: `git -C "$BASE_WT" rev-parse HEAD` 输出 `3e2a1a158…`；`git -C "$BASE_WT" status --short` 为空。

### Task 2: 迁入备份快照中的内容修改

**Files:**
- Modify: §输入清单 A 中标为"迁入"的 5 个文件
- Remove from index: `crmeb/.env`

**Interfaces:**
- Consumes: Task 1 的 `$BASE_WT`
- Produces: 提交 `chore: port reviewed changes from private backup 9580c80`

- [ ] **Step 1: 按 LF 写入 5 个文件**

```bash
cd "$BASE_WT"
for f in help/docker/probes/php82-integration-compatibility.php help/docker/verify-php82.ps1 \
         template/admin/src/pages/notify/smsConfig/index.vue template/uni-app/manifest.json \
         template/uni-app/store/getters.js; do
  git -C "$BACKUP" show "9580c80381e9e03823d8d69495a54491a3c3a6a9:$f" | tr -d '\r' > "$f"
done
git rm --cached crmeb/.env
```

- [ ] **Step 2: 校验只有预期的内容变化**

Run: `git diff --stat && git diff --cached --stat`
Expected: 只有上述 5 个文件和 `crmeb/.env`（deleted from index）；`git diff` 中没有整文件替换（每个文件只有少量行变化）。

- [ ] **Step 3: 提交**

```bash
git add help/docker/probes/php82-integration-compatibility.php help/docker/verify-php82.ps1 \
        template/admin/src/pages/notify/smsConfig/index.vue template/uni-app/manifest.json \
        template/uni-app/store/getters.js
git commit -m "chore: port reviewed changes from private backup 9580c80" \
  -m "Probe for DAO join aliases; scrub upstream demo credentials; stop tracking empty crmeb/.env."
```

### Task 3: 迁入审计修复与审计测试源码

**Files:**
- Modify: §输入清单 B 的 24 个文件
- Create: §输入清单 C 的 23 个文件（其中 3 个与 master 相同，不产生差异）

**Interfaces:**
- Consumes: Task 2 的提交
- Produces: 提交 `fix: audit findings 2026-10-10 (A1-A9, B1-B5)`

- [ ] **Step 1: 按 LF 复制 47 个文件**

```bash
cd "$BASE_WT"
LIST_B="$EVID/list-b.txt"; LIST_C="$EVID/list-c.txt"   # 把 §B、§C 的路径各写一行到这两个文件
cat "$LIST_B" "$LIST_C" | while read -r f; do
  mkdir -p "$(dirname "$f")"; tr -d '\r' < "$BACKUP/$f" > "$f"
done
```

- [ ] **Step 2: 校验内容等价**

```bash
cat "$LIST_B" "$LIST_C" | while read -r f; do
  cmp -s "$f" <(tr -d '\r' < "$BACKUP/$f") || echo "MISMATCH $f"
done
grep -lU $'\r' $(cat "$LIST_B" "$LIST_C") && echo "CR FOUND" || echo "NO CR"
```

Expected: 没有 `MISMATCH`；输出 `NO CR`。

- [ ] **Step 3: 语法检查与现有 UNIT-STUB 回归**

```bash
for f in $(grep '\.php$' "$LIST_B" "$LIST_C" | cut -d: -f2); do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
php help/audit-regressions/security.php; echo "exit=$?"
```

Expected: 没有 `LINT FAIL`；`security.php` 输出 23 个通过、0 个失败，`exit=0`（审计记录的基准值，见 `openspec/changes/fix-audit-findings-20261010/tasks.md`）。结果不同时停止并回报，不修改测试。

- [ ] **Step 4: 敏感内容扫描**

Run: `git diff 3e2a1a158 -- . | grep -nEi '(password|secret|appkey|token)\s*[:=]\s*["'\''][^"'\'']{8,}'`
Expected: 无输出。有输出时停止并回报。

- [ ] **Step 5: 提交**

```bash
xargs git add -- < "$LIST_B"
xargs git add -- < "$LIST_C"
git commit -m "fix: audit findings 2026-10-10 (A1-A9, B1-B5)" \
  -m "Ported from the private backup working tree with LF endings. See openspec/changes/fix-audit-findings-20261010."
```

### Task 4: 迁入文档并暂停预约变更

**Files:**
- Create: `AGENTS.md`、`docs/multi-merchant/**`
- Modify: `openspec/changes/add-store-booking/proposal.md`、`openspec/changes/add-store-booking/tasks.md`

**Interfaces:**
- Produces: 提交 `docs: multi-merchant development docs` 和 `chore(openspec): pause add-store-booking`

- [ ] **Step 1: 复制文档**

```bash
cd "$BASE_WT"
mkdir -p docs/multi-merchant && cp -R "$BACKUP/docs/multi-merchant/." docs/multi-merchant/
cp "$BACKUP/AGENTS.md" AGENTS.md
git add AGENTS.md docs/multi-merchant
git commit -m "docs: multi-merchant development docs"
```

- [ ] **Step 2: 插入 PAUSED 标记**

在两个文件的第一个 `#` 标题行之后插入 §输入清单 E 中的那一行，其他内容不变。

Run: `git diff --stat`
Expected: 两个文件各 +2 行左右（标记行加空行），无删除。

- [ ] **Step 3: 提交**

```bash
git add openspec/changes/add-store-booking/proposal.md openspec/changes/add-store-booking/tasks.md
git commit -m "chore(openspec): pause add-store-booking (out of route A scope)"
```

### Task 5: 校验不变量并回报

**Files:**
- Create: `docs/multi-merchant/records/base/report.md`、`docs/multi-merchant/records/base/results.jsonl`

**Interfaces:**
- Produces: `AUDIT_BASE_SHA` = Task 4 Step 3 的提交 SHA（记录提交本身不计入）

- [ ] **Step 1: 变更集合与预期一致**

Run: `git diff --name-status 3e2a1a158 HEAD | sort > "$EVID/base-diff.txt"; wc -l < "$EVID/base-diff.txt"`
Expected: 包含且只包含：5 个 `M`（清单 A）、1 个 `D crmeb/.env`、24 个清单 B 文件（`manifest.json` 只出现一次）、20 个 `A`（清单 C 去掉 3 个相同文件）、`AGENTS.md` 与 `docs/multi-merchant/` 下的文件、2 个 add-store-booking 文件。逐项对照，并把对照结果写进报告。

- [ ] **Step 2: 演示凭据未回流**

Run: `git show HEAD:template/uni-app/manifest.json | grep -nE '"appkey_(ios|android)"|"appsecret"'`
Expected: 这几项的值都是空字符串。

- [ ] **Step 3: 备份检出未被改动**

```bash
cd "$BACKUP" && GIT_OPTIONAL_LOCKS=0 git status --short --untracked-files=normal > "$EVID/backup-status-after.txt"
diff "$EVID/backup-status-before.txt" "$EVID/backup-status-after.txt" && echo UNCHANGED
```

Expected: `UNCHANGED`。（`docs/multi-merchant/`、`AGENTS.md` 在开工前就已作为未跟踪文件存在，前后快照应一致。）

- [ ] **Step 4: 写记录并提交**

按 [records/README.md](records/README.md) 的格式写 `report.md` 与 `results.jsonl`，覆盖用例 Z01、Z03、Z05，并写明 `AUDIT_BASE_SHA`。

```bash
git add docs/multi-merchant/records/base
git commit -m "docs(records): BASE rebuild report"
```

- [ ] **Step 5: 停止**

按 12 文档 §7.1 回传，等待验收。没有 L3 授权不推送。
