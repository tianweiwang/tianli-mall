# MS0b 进度记录（未交付、未签收）

当前阶段：L2 隔离开发，Task1/2已实现并验证；Task3–10待完成。整个MS0b尚未完成，管理端/对外端尚未接入v2；没有推送、部署、外部通知或真实会话切换。README验收状态和SECURITY_BASE_SHA未由执行方改写。

开发起点为MS0a交付3e7c966ecfa084ff4f219b03c61ed0efbac4caac；用户明确委托按规划自主继续并解决问题，V10人工签收仍待。工作目录H:/天俪商城/tianli-mall--ms0b，分支codex/ms0b。会话状态仅Redis，无新数据库表/列/种子。

## 环境与证据

ISO-FULL，RUN=20261011092042df3a82：现有PHP8.2镜像、独立internal网络、专用MySQL/Redis和新卷；没有宿主端口或Docker socket。测试/应用audit_app仅具SELECT/INSERT/UPDATE/DELETE，bootstrap专用audit_migrator完成真实安装文件校验初始化。测试过程中每次核对实际容器、网络、卷身份和双哨兵。其他检出及服务未作为测试目标。

仓库外原始证据根：C:/Users/Vwang/.tianli-audit/20261011092042df3a82/evidence/。此文件为进行中摘要，Task10仍需清洁冻结源码、新RUN及完整回归，不能用当前任务测试冒充最终验收。

## Task1：隔离自检与故障适配器

- 测试先提交264fbcff；12项基线8PASS/4FAIL，exit1，缺故障适配器。
- 首次after保留10PASS/2FAIL（宿主exit2）：pause后误做健康检查导致等待恢复；phpredis原生EVAL状态OK实际可为bool true，另以真实只读命令核对。已修复控制顺序及严格true/OK成功回复检查，未改业务/副作用期望。
- 最终12PASS/exit0；真实pause/stop均有界失败，unpause/start恢复完整健康/双哨兵/全缓存不变。适配器委托真实Redis，可在EVAL执行前抛错、执行后丢应答/断连；不用于生产。
- 实现提交286e7fe6。最终raw：selftest-after-20261011094210.jsonl（原source元数据5a61f9ef加待提交Task1变更，保留原样）。Task1独立代码审查记录task1-independent-code-review.md，无遗留必须修复项。

## Task2：会话存储

生产提交：1736eed1bc6ae40ab0d20c215d0e2b25569bba11，feat(auth): versioned Redis session store with single-use redeem。

- 原82项测试提交14c4f9c4，基线82FAIL/exit1；独立审查补18项后测试提交a651aa97，100FAIL/exit1，均缺类且无环境/副作用错误。两份基线保留，不把82项结果当100项证据。
- 实现7个生产文件：AuthSessionStore、两项受控AuthException子类、create/redeem/verify/rotate四个Lua。三项PHP lint、LF及diff检查通过。独立只读审查确认与草稿逐字节一致、无必须修复项（task2-contract-review.md）。
- 最终100PASS/exit0；case分布A17=47、A18=8、A21=9、A23=7、A25=20、A28=4、A29=5。全158张表、全部Redis值及TIME/PTTL绝对到期点核对，无意外变化。
- 双PHP进程实际READY/GO并发兑换恰好一个成功，另一个401；旧sid已consumed且原到期点保持。真实pause/stop及EVAL前/后故障受控503、不重试、不复活旧版本、不向调用方报告成功。
- 账号持久，sid/version/owner为128位随机数；验证只读原子核对账号和会话。缺键访问/刷新/普通轮换拒绝且不重建；变更缺账号仅造不可授权保护标记，解除后仍须合法凭据登录初始化。owner CAS避免普通unblock解除变更保护。
- Lua先检查所有类型/字段/期限/目标空闲，再写；新旧凭证摘要不同，previous_token_digest只校验不存入新会话，EXPIREAT为expires_at+60。执行后丢应答一律503；complete已执行可能已解锁，不用补偿恢复旧版本或据回读结果改报成功。
- 最终raw：ms0b-session-store-after-20261011100431.jsonl，SHA256=f245cd7d118aa6ed4bccd848e3dd2ccde937c693f2ff09fc8b160564fff0e7f4。首次after为a651aa97加7个当时待提交生产文件，未改原结果commit_sha；task2-tested-source.json记录每文件哈希，并确认提交后与测试内容一致。

## 未完成

Task3令牌/凭据戳/config、Task4管理端、Task5对外端、Task6全部变更入口、Task7世代轮换/RDB恢复、Task8验证码/URL令牌与浏览器下载、Task9发布准备、Task10完整冻结回归和独立验收均待完成。当前无MS0b HTTP/WORKER/BROWSER/EXTERNAL通过结论；接口实际在用情况未知。此报告不构成人工签收。

results.jsonl当前保留Task1三轮12项与Task2两份基线及最终100项，共318行；历史失败不抹除，Task1/2最后一轮112项全PASS。后续任务与交付将继续更新本记录。
