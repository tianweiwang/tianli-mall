# LOAD-01 固定组件装载后继 R2

本片在 Root 明确继续原完整目标的授权内，只修已确认的 LOAD-01。唯一目标 before 是冻结 `nonempty-history-normal-parent-components-source-r1/candidate/legacy-history-producer.php`（1051627B/606a49d80ccf211a14d4731c9cbd24077bea5a2a9a6b961c09cd1e86db30f9ab）；原 R1 及其独立 finding 全部保留，不改 W/Val、既有 Git 归档、canonical、Run 或冻结包。

原 shared qualifier 在 `nhe_qualify_source` 内校验完整 11 controls 和来源树，另装载原迁移控制库，但不执行三个定义组件。旧 main 在进入 fixed pre37 时已经需要 actors/config，原 private reader 内较晚的 actors require 无法弥补；HTTP runner 也缺装载。不能用尚未实现的 startup guard 遮蔽这个调用缺陷。

## 必要设计与接受条件

- 仅在现有 `$qualified = nhe_qualify($freeze, 'audit-legacy-history-producer')` 成功后，固定 `require_once` 三个已在原控制闭集内的绝对路径，次序为 `/run/nonempty/legacy-normal-config.php`、`legacy-actors.php`、`legacy-history-http.php`。三个源均为定义组件，顶层没有 App/资源/业务调用。
- 然后调用原 `nhe_includes($qualified)`，再进入原 parent qualification/write、observer DB/Redis 与 fixed pre37。不能动态查找替代文件、扩大 includes/control 闭集或借 observer 冒充 old App writer。
- 原 producer 165 个定义中只改变 main 的这个有限装载接缝；其余 164 个定义、尤其前片 162 个既有定义，全部逐字保留。原 169 字节 direct refusal 尾、startup plan/accept 缺失的前置 guard、原 37/四帧/四 CLI、完整 164 表/全 Redis/五树/private receipt/public exact13/最后正常收尾责任都保留。
- 更新实际 Source overlay 中 producer 身份，保持 13 artifacts/9 PHP/8 bundle + 3 Root-owned current controls = 11。没有未来 HEAD、freeze、Run、CID 或业务 PASS。
- 输出完整 before/candidate、精确接缝记录、标准正向/逆向 diff、原定义保持证据及 Source 输入窗口。独立 child 核真实 require/callflow，而不只查新增字符串。

## 验证与任务

1. 记录本设计及原 R1 finding 身份，再实施唯一有限替换。
2. 纯 Source 验证三实际定义源、完整正逆、164 unchanged/main-only、169 尾与缺失 startup guard；生成实际 13/9/11 overlay。
3. 独立窄审并保留原 R1 阻塞历史。必要 PHP 语法可在有合适现成编译器时只做编译检查；本片不启动任何业务或资源。当前 PATH 未发现 PHP，若无合适编译器则明确 NOT RUN 并由 Root 在正式检查点集中安排，不用其它 parser 冒充 PHP8.2 编译成功。
4. LOAD-01 关闭后继续原 startup effects 的必要 Source 设计/实现；在全启动 oracle、Host/current-reader/最终 bindings 等原缺口关闭前，总入口仍拒绝。Source 装载修复不是运行准入或业务验收。
