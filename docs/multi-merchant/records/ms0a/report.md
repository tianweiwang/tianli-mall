# MS0a 交付报告

## P1 基本信息

- 分支：`codex/ms0a`；工作目录：`H:/天俪商城/tianli-mall--ms0a`；授权：L2。
- 起点：`6dee65785b5655d7bac7c3838b95106c7c0549cc`。用户已确认 BASE 通过；历史 `AUDIT_BASE_SHA=9685731dcd116784d38e6bbdb902cec3c0c84975`，起点与历史基线的业务树均为 `e5bc53996280f4d60f4f577d22da0fbf274b67be`。
- 已验证源码冻结点：`af3e3e0c48fab9cf5af18f45f6341e9c272b168f`。最终记录提交的交付 SHA 由 `git rev-parse HEAD` 及回传给出；必须与冻结点的 `crmeb`、`help/audit-regressions` 差异为空。
- 主验收 RUN：`202610110832052c8009`，全新 MySQL/Redis/内部网络/卷。主完整 HTTP 已 106/106 通过；独立完整 HTTP 同为 106/106，最终逐项差异0，独立副作用与源码审阅无必须修复项，详见下文。
- 没有推送、PR、合入、部署、外部通知或真实业务数据库操作。没有生成 `acceptance.md`、修改 README 验收状态或自行指定 `SECURITY_BASE_SHA`。
- 用户先后批准 ISO-FULL、已有商品规则权限探针、专用 migrator 真实初始化、六项精确白名单及三个入口补角色检查；后续明确授权按文档规划自主处理问题。唯一执行清单为 `04-plan-ms0a.md`。

## P4 变更文件与范围

起点到冻结源码共 34 文件，完整逐路径归属见仓库外 `ms0a-scope-coverage.md`。下列清单覆盖全部路径，必要调用点均已在 04 和相关提交中记录。

| 路径（仓库相对路径） | 任务与理由 |
|---|---|
| crmeb/crmeb/services/AuthConfigGuard.php；SessionWhitelist.php；crmeb/crmeb/utils/JwtAuth.php | Task 3：安全配置、原白名单精确比对、无状态 HS256 验签 |
| crmeb/app/services/system/admin/SystemAdminServices.php | Task 3/4：配置检查在写登录统计前；签发前 Redis 检查 |
| crmeb/app/services/system/admin/AdminAuthServices.php | Task 4：固定 admin、当前状态及密码摘要；删除认证失败草稿写入 |
| crmeb/app/services/kefu/LoginServices.php；crmeb/app/services/user/UserAuthServices.php | Task 5：固定接收端、当前白名单；客服补齐规格已有启用状态要求 |
| crmeb/app/services/out/OutAccountServices.php；crmeb/app/outapi/controller/Mcp.php；crmeb/app/adminapi/controller/v1/setting/SystemOutAccount.php | Task 6：统一 status=1/is_del=0；刷新固定 out；非法状态不写库；MCP 保留凭据协议 |
| crmeb/app/adminapi/config/auth_routes.php；crmeb/app/services/system/admin/SystemRoleServices.php；crmeb/app/adminapi/controller/v1/setting/SystemMenus.php | Task 7：六项精确白名单、未登记/CRUD 拒绝、菜单写后失效权限缓存；拒绝不写冷缓存、热命中不回写旧权限 |
| crmeb/app/adminapi/route/route.php | 已批准必要调用点：system/info、route/import_api、download 追加角色检查 |
| help/audit-regressions/isolated/README.md；compose.yml；bootstrap.sh；run.sh；teardown.sh；lib/guard.php；lib/harness.php；fixtures/ms0a.php；selftest.php | Task 1 及必要测试修复：ISO-FULL、资源身份保护、DML 权限、真实引导、Windows 控制文件瞬时空读重试、Redis SET 完整成员比较、宿主临时 CRUD 探针精确清理、原生 Git 基线路径核对 |
| help/audit-regressions/isolated/ms0a-auth.php；lib/token-forge.php | Task 2/7：106 个真实 HTTP 认证/授权子场景，原断言保留 |
| help/audit-regressions/isolated/route-inventory.php | Task 7：框架真实路由枚举、安装权限比对、全表/缓存只读证据 |
| help/audit-regressions/security.php | Task 7：20 原回归、3 批准收紧契约、20 新检查，共 43 UNIT-STUB |
| docs/multi-merchant/03-test-environment.md | 用户批准 ISO-FULL、已有权限探针与专用真实 writeMd5 初始化 |
| docs/multi-merchant/04-plan-ms0a.md | 批准决定、事实修正、必要调用点及唯一任务进度 |
| docs/multi-merchant/11-acceptance-process.md | 后续自主处理授权下修正实际测试 SHA 契约，保留真实 baseline 并要求冻结运行树一致 |
| docs/multi-merchant/records/ms0a/external-integrations.md；route-permission-matrix.md；report.md；results.jsonl | Task 6–8：入口影响、全量路由矩阵及交付证据 |

没有修改 vendor、public、.env、help/docker/run.sh、安装 SQL、业务结构或权限种子。测试仅在专用库建立计划内 audit_sentinel 和合成账号/角色；不是新增业务认证结构。

## P5 实际命令与结果

Windows 调用 Git Bash，所有退出码使用实际 `$LASTEXITCODE`；容器中的测试不能用宿主 PHP 结果替代。bootstrap/run/teardown 的完整参数见隔离 README；以下记录实际入口与证据。

| 实际命令/检查 | exit | 结果与证据 |
|---|---:|---|
| bash isolated/bootstrap.sh（冻结 af3e3e0c，主 RUN） | 0 | 新资源、安装 157 表及独立哨兵、真实 writeMd5、audit_app DML、应用启动 |
| AUDIT_PHASE=after bash isolated/run.sh 202610110832052c8009 help/audit-regressions/isolated/selftest.php | 0 | 6 PASS；Z07 含 16 次启停、login/info 均 200 |
| docker run（--network none、--read-only、只读 /workspace 挂载）内执行 php /workspace/help/audit-regressions/security.php | 0 | UNIT-STUB 43 PASS / 0 FAIL；security-after-af3e3e0c.txt |
| AUDIT_PHASE=after bash isolated/run.sh 202610110832052c8009 help/audit-regressions/isolated/ms0a-auth.php | 0 | 单次完整 106 PASS / 0 FAIL；ms0a-auth-after-20261011084959.jsonl |
| AUDIT_PHASE=after bash isolated/run.sh 202610110832052c8009 help/audit-regressions/isolated/route-inventory.php | 0 | 973 路由、961 角色检查、6 白名单、520 未登记拒绝；0 仅认证/CRUD/重复键；全部 158 表及 84 Redis 键不变，0 HTTP |
| php -l（容器内，22 个改动 PHP 文件） | 0 | 全部无语法错误，static-verification.txt |
| bash -n bootstrap.sh；run.sh；teardown.sh（逐文件分别执行） | 0 | 三个脚本均通过 |
| git diff --check 6dee6578 af3e3e0c；git diff --exit-code af3e3e0c -- crmeb help/audit-regressions | 0 | LF/差异检查与冻结运行树核对通过 |
| Scoped Python scan（34个变更文件，明显凭据格式/排除路径/.env） | 0 | 格式命中0，排除路径变更0，无.env；启发式扫描不代表穷尽所有秘密格式 |
| bash isolated/teardown.sh 202610110832052c8009 | 0 | manifest身份核对后仅清理本RUN容器/网络/新卷，证据保留 |
| 历史 AUDIT_PHASE=baseline run.sh（HEAD=9062a302）完整 85 项 | 1 | 24 PASS / 61 FAIL；正常测试失败，无环境退出/崩溃；业务树与历史基线一致 |

### 最终分层统计（Z04）

| 层级/阶段 | PASS | FAIL | NOT RUN/BLOCKED | 边界 |
|---|---:|---:|---|---|
| UNIT-STUB / after | 43 | 0 | 0 | 权限与扫码上传回归；不计作 HTTP |
| INTEGRATION / after 自检 | 6 | 0 | 0 | 新主 RUN、冻结源码 |
| INTEGRATION / after HTTP | 106 | 0 | 0 | 单次完整 106 个唯一子场景，非分组拼接 |
| INTEGRATION / 历史 baseline | 24 | 61 | 0 | 原 85 项，真实 SHA 保留；FAIL 不全等同身份旁路 |
| 记录 Z04/Z08 | 2（记录完成） | 0 | 实际调用方 UNKNOWN | 记录合同已满足，独立统计、不计自动化 PASS |
| WORKER / BROWSER / EXTERNAL | 0 | 0 | NOT RUN | MS0a 无 worker/UI 验收要求；未获真实调用方/环境访问及发布授权 |

### 关键修复与基线事实

| 用例 | baseline 实际 | after 要求及已有阶段验证 | 分类 |
|---|---|---|---|
| A09 | 四自身探针成功；6 错误应用组合返回 200；另有已经拒绝但业务码非 401 的组合 | 16 组合仅四对角线成功；错误应用不删除正确端会话 | 待复现风险与既有拒绝分别逐行记录 |
| A11/A13/A14 | 原密码摘要已拒绝 out 登录/客服/顾客令牌进入管理端 | 拒绝，并保持原应用会话可用 | 既有拒绝回归 |
| A12 | 真实 out 刷新后的令牌撞管理员 101/102 均返回 200 | 两种均 401，五表及全部 Redis 无未允许变化 | 基线已复现风险 |
| A15/A16/A21 | 管理员停用/软删仍可用；物理删除/不存在已拒绝；改密已有部分保护 | 当前账号状态和凭据一致才可用；仅精确撤销本端失效会话 | 风险与既有拒绝分别记录 |
| A15 客服补充 | 追加测试前停用客服旧会话仍返回 200 | 401；本端精确撤销；恢复后旧令牌仍拒绝，新登录可用 | 原规格遗漏补齐；旧 85 基线中无此新增行 |
| A17/A18 | 缺少/空 pwd、缓存 type/uid/token 不匹配存在放行；部分已拒绝但撤销不符 | 验签后固定类型、精确内容比对；不删除别端会话 | 按子场景事实分类 |
| A19 | 关闭后的访问/刷新仍 200；status=2 实际写库；MCP/软删入口原已拒绝 | 登录/访问/刷新/MCP 使用相同启用定义；2 不写库；正常 1→0 持久化 | 风险、既有拒绝与新增正向契约分别记录 |
| A27 | 错误签名/过期商品保存请求均改变 eb_cache 草稿 | 两种均 401；完整 eb_cache、eb_store_product 及草稿目标不变 | 基线已复现业务写入 |
| A10 | 空/default/file 配置仍签发，部分写登录统计 | 503，不签发，不写业务；检查在 verifyLogin 前 | 基线已复现配置降级 |
| A02–A06/A08/A30 | 新拒绝测试前 UNIT 29 PASS/13 FAIL；新增 HTTP 11 PASS/8 FAIL | 精确白名单；未登记/CRUD/未授予拒绝；真实撤权、删菜单、改 auth_type 后下次拒绝 | 原审计回归与批准的新契约 |
| A23 | Redis 停机能拒绝；MS0a 契约要求无业务变化 | 停机实际 HTTP 500 拒绝；恢复后业务和会话值完整保留 | 依赖故障 503 分类留 MS0b，不虚报本阶段已统一 |

最终 results.jsonl 共 245 行：历史 baseline85、最终 HTTP106、自检6、UNIT43、记录2、三层各1条 NOT RUN 范围摘要。HTTP分类为既有拒绝18、既有修复3、待复现风险38、新增契约47；UNIT分类为既有修复20、原旁路风险3、新增契约20。对85个同名历史场景，最终发现分类依据真实baseline并保留 raw_after_finding_kind、classification_basis 与原始结果指针；15个分类元数据有调整，状态/实际/期望/副作用保持原值。新21项不伪造历史 baseline；31个MS0a验收ID全部覆盖，补充BASE-SECURITY编号独立说明扫码上传。

## P6 仓库外证据

- 主 RUN 已按 manifest 清理，证据和工作树保留；以下为历史执行身份，不能作为当前服务运行证明。
- 主最终目录：`C:/Users/Vwang/.tianli-audit/202610110832052c8009`。manifest.json、network.json、containers.jsonl、volumes.txt 记录本 RUN 资源身份；秘密仅留专用凭据卷，不入库。
- `evidence/selftest-after-20261011083416.jsonl`：6 个冻结源码自检；`security-after-af3e3e0c.txt`：43 个单元子场景；`static-verification.txt`：语法/冻结树核对。
- `evidence/ms0a-auth-after-20261011084959.jsonl`：106 行完整最终 HTTP，全部源 SHA 为 af3e3e0c；evidence_ref 对应同目录脱敏响应、完整五表与全 Redis 前后摘要、追加可能受影响的权限表摘要。仅允许本应用失效会话精确撤销等已说明变化。`route-inventory-metadata.json` 和 `route-permission-matrix.md` 为最终只读枚举；31 个关键源文件 SHA 前后一致，仓库矩阵副本摘要相同。
- 历史目录：`C:/Users/Vwang/.tianli-audit/20261011065024d6dda7/evidence`。`ms0a-auth-baseline-20261011070721.jsonl` 保留 85 项真实 baseline；`20261011071124.jsonl` 保留 A09/A26 分类元数据复核；旧运行资源已按 manifest 清理，证据保留。
- 历史 after：`ms0a-auth-after-20261011073853.jsonl` 保留 85 PASS/1 FAIL（A23 SET DUMP 差异）；`20261011074043.jsonl` 保留修复后的 A23 2 PASS；`20261011074202.jsonl` 保留认证 86 PASS。它们是阶段证据，不冒充冻结源码全量。
- `task7-unit-before.txt`、`task7-unit-after.txt`、`task7-hot-cache-before.txt`：收紧契约与热缓存回写测试先失败后修复；`ms0a-auth-after-20261011082022.jsonl` 为客服停用修复前 2 PASS/1 FAIL，`20261011082108.jsonl` 为相关 28 项修复后 PASS。
- 最终汇总脚本及统计：主 evidence 中 `build-ms0a-results.py`、`results.candidate.counts.json`、`reviewed-z04-z08.jsonl`；仓库results与候选 SHA-256一致（`2ce0edc82b6982f6b2cea1cdfe717fd935c8fd6d32893df62aaa03b0e2441d3f`）。`scoped-secret-and-path-check.json`与仓库外scope记录支持范围检查。
- `redis-set-probe-before.json`、`redis-set-probe-after.json`：173 个完整 SET 成员排序摘要相同，而内部 DUMP 变化；快照改为完整排序成员与类型标记比较，其他类型仍 DUMP，全键覆盖/允许变化不扩大。

## 独立 V4 / V7

独立复核方 harness_review 未参与业务实现。冻结源码只读审阅、43 UNIT 源码与必查 27 份阶段原始证据已无必须修复项；这不替代最终 V4。

最终 V4 使用独立干净工作树 `H:/天俪商城/tianli-mall--accept-ms0a-1579fddf`（目录名保留旧编号，实际 HEAD 为 af3e3e0c）及自己的新 RUN `20261011083629920dac`。基线工作树 `H:/天俪商城/tianli-mall--accept-ms0a-base-9685731d` 保留历史业务源码，仅覆盖冻结版本的隔离测试目录；运行器强制核对历史 crmeb 树与干净业务目录。独立修复版 UNIT43、自检6、HTTP106均PASS/exit 0；历史原BASE UNIT23 PASS/exit 0，基线A12/A27/A19/A15抽样17行为3 PASS、14预期FAIL/exit 1，真实复现旧缺陷而非环境错误。两个版本各自完整初始化、自检和资源清理均exit 0。

独立记录：`H:/天俪商城/artifacts/ms0a-independent-1579fddf/review.md`，同目录 `commands.jsonl`、`result-comparison.json`、`final-results-review.json`、`mandatory-evidence-review.json`、`baseline-evidence-review.json`、`inventory-review.json`。独立原始HTTP为 `C:/Users/Vwang/.tianli-audit/20261011083629920dac/evidence/ms0a-auth-after-20261011084945.jsonl`；基线样本为 `C:/Users/Vwang/.tianli-audit/20261011084120a148e3/evidence/ms0a-auth-baseline-20261011084916.jsonl`。

双方原始HTTP106的状态、期望、实际、源SHA与原始分类逐子场景差异0；最终245行的核心结果无变化，15个分类调整和85条历史分类引用均正确追溯。独立打开31份必查/补充副作用证据，重算前后表与Redis摘要，无未允许变化；最终V7按11§5.2逐项审阅，无必须修复项。两个独立RUN已清理；其他50个容器、69个卷、16个网络身份不变。准备阶段仓库外命令记录器有127/本地解析错误，发生在harness调用前或只读分析，原失败日志保留，不计作测试通过。

上述是执行与独立复核事实，V10用户签收仍待；没有自填acceptance或改README。
## P7 数据与迁移

没有业务 DDL、数据迁移或真实库访问。安装 SQL 去 CR 后 SHA-256 为 `7af188f42c622db523d8000c0cf50f95fa48df2fe41123969ca2dbb65ffb5eb2`，157 张安装表另加 audit_sentinel；只导入仓库安装种子到新专用 audit_auth，audit_app 仅 SELECT/INSERT/UPDATE/DELETE，无全局权限。root/migrator 仅用于已核对的 bootstrap：真实 writeMd5 涉及 TRUNCATE，随后真实版本缓存标记核对；应用/runner 不接触 root。没有提高应用权限或伪造标记来绕过实际初始化。

## P8 未运行项与允许延后

| 项目 | 状态 | 原因/补齐边界 |
|---|---|---|
| Z08 实际外部调用方、启用账号及频率 | UNKNOWN | 只完成代码入口/行为清单；11 §5.2 明确允许延后，MS0b 发布前必须核实 |
| WORKER/BROWSER/EXTERNAL | NOT RUN | MS0a 无新增界面/worker 合同；未连接业务实例或第三方，不称生产验收 |
| V10 人工验收、README 状态与 SECURITY_BASE_SHA | 待验收人 | 独立审阅与测试不代替用户签收；后续 L2 按自主继续授权进行，不自行签名 |
| 推送/PR/合入/部署/密钥轮换/通知 | 未执行 | 当前授权边界为 L2；L3/L4 操作单独授权 |

## P9 遗留边界与后续

- 未登记拒绝清单针对安装种子与受限管理员；最高管理员 level=0 保留原角色豁免，但仍检查有效认证/账号。真实环境权限登记和实际使用方未知。
- menuslist 保留批准的全局搜索菜单元数据语义；menus 与 setting/menus/unique 仍按本人角色过滤。本人资料更新仅允许姓名、头像和凭旧密码改密。
- Redis 运行时故障 503 分类、v2 单次刷新、版本撤销与 epoch 丢失处理在 MS0b；当前 MS0a 复用原令牌与白名单，未声称已实现会话 v2。
- 热权限缓存命中不再回写，避免复活已清理的旧权限。原有冷缓存初始化与并发失效之间的窗口未引入新原子版本合同，不能把此阶段描述为权限 epoch 原子化。
- 遗留客户端 URL 令牌、验证码强制规则属于 MS0b 候选 Q6/Q7，后续按自主处理授权评估并记录兼容路径，不在本次修改签发协议。
- 本阶段验证与记录完成后保持人工验收待办，按用户“按文档规划目标推进，遇到问题按最优方式继续、不用问”的后续指令推进下一 L2 开发阶段；不伪造 SECURITY_BASE_SHA、不执行真实切换。

## 实施中的事实修正

1. 种子没有商品列表 GET 权限登记：经用户批准改用已有商品规则 GET 探针；未增加权限种子，商品列表进入未登记拒绝清单。
2. DML 干净启动因原 writeMd5 的 TRUNCATE 失败：经用户批准仅 bootstrap migrator 完成真实初始化，login/info 原 200 断言保留。
3. Windows 控制文件 rename 瞬时空读：只对命令/ACK 重试；manifest/秘密仍严格失败。基线包装器在禁止 MSYS 转换下使用原生 Git 绝对路径；最终重新冻结复跑。
4. security.php 原退出探针输入 POST 与真实 GET 不符：保留放行期望，仅修正输入 GET 并新增 POST 拒绝，原 BASE 证据保留。
5. 拒绝请求必须全 Redis 无未允许变化：冷权限缓存先只读，授权成功才填充；热命中不回写。新增失败证据先于修复。
6. 客服主键 DAO 不隐式过滤启用状态，后台停用不撤销旧会话：按 00 §4.2 补精确状态验证，先提交真实 HTTP 失败测试再修复。
7. 11 V1 原“所有结果等于交付 SHA”与真实 baseline/报告自身提交冲突：改为真实测试 SHA；最终源码冻结与记录提交的运行树差异必须为空。未覆盖历史结果、未降低覆盖。
