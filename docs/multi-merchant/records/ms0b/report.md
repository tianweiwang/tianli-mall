# MS0b 进度记录（未交付、未签收）

当前阶段：L2 隔离开发，Task1–3已实现并验证；Task4管理端接线已本地提交，30项HTTP和8项启动/缓存兼容回归通过，完整A21仍待Task6联合验证。Task5–10待完成，整个MS0b尚未完成；对外端尚未接入v2。没有推送、部署、外部通知或真实环境会话切换。README验收状态和SECURITY_BASE_SHA未由执行方改写。

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

## Task3：v2令牌与凭据戳

生产提交：f72fb5dcdcf2f7f5eaf23bccf004445b18f5220e。保持既有JWT默认30天；配置新增必填epoch、独立至少32字节stamp_key、固定issuer/apps绑定。pure authConfig返回本次有效配置，旧Guard两个方法体保持原样；新Redis连接独立且有界，不继承/修改共享Cache prefix/serializer/read-timeout。

- 测试先提交c784f309的123项；独立审查补全全部case的file-cache/log观察、独立key、null pwd、配置TTL及JWT全局状态后406f2d54的127项，分别123/127缺类FAIL、exit1，基线保留。case分布A10=35、A17=4、A18=4、A21=8、A22=57、A23=4、A25=7、A28=4、A29=4。
- 首轮after125PASS/2FAIL、exit1，均测试准备问题：ThinkPHP Env批量设置会展开数组，在Env.php:120对数字key调用strtoupper导致TypeError；隔离实际诊断env-array-setter-diagnostic.txt确认单键setter原样保留错误类型值。9e3fd519只改设置方式，不改配置错误值、503/无IO断言或任何生产代码；首轮证据保留。
- 最终127PASS/exit0；全部158张表、所有Redis值及绝对到期点、实际文件缓存/log哈希无意外变化。配置缺失/错误503，声明/类型/时间/schema/用途/当前摘要/会话不符401；无语言缓存预热或生产日志。真实PDO/Redis封闭端口连接拒绝为503，专用服务本身保持健康，不能报告为服务停机。
- 正常签发前session必须已持久；JWT不包含pwd/appsecret/credential_stamp/token_digest/stamp_key。HMAC绑定app/id/当前数据库摘要；回调仅在签名和声明全通过后运行。每次检查当前配置及epoch，常驻A/B/A不保存上次主体，JWT调用者timestamp/leeway在正负路径均恢复。
- refresh先只读EVAL验证、再单次EVAL兑换；真实执行后丢应答/断连不返回token、不重试、不复活旧sid；revoke原子核对exact token digest及有效服务器绑定后只删本人当前app/sid，坏摘要/主体/丢账号/consumed/坏stamp均零写入。
- 五PHP lint及既有UNIT-STUB权限43/43/exit0。UNIT文本证据security-task3-9e3fd519.txt单独保留，不包含在822条INTEGRATION原始行汇总内；它不是HTTP或实际业务客户端验收。独立只读审查task3-contract-review.md、task3-guard-store-review.md及Token/Stamp逐行复核无遗留必须修复项。
- 最终raw：ms0b-token-after-20261011103252.jsonl，SHA256=e2b4c328b5fbef7a932591b601520c578501e98163bf06c3f0cd7eb860e27e15。最终轮source元数据9e3fd519加当时未提交6生产文件，保留原样；task3-tested-source.json确认每文件哈希与当前生产提交一致。最终交付仍须Task10清洁冻结新RUN完整回归。
## Task4：管理端接线（完整A21尚待联合验证）

生产提交：7c26c85b（完整SHA见task4-tested-source-final.json），`feat(adminapi): switch to v2 sessions with deferred ORM cache connection`。8个生产文件均在05计划列出的同一修复调用范围内；不修改vendor、数据库结构或权限种子。

- 测试/环境先提交762382b6、83dc4070、282ec0e0；首次新RUN自检11PASS/1FAIL、exit1保留。首次runner运行才创建runner-runtime卷，旧资源清单严格拒绝缺登记卷的服务控制；run.sh先执行原guard再登记实际卷身份，不放宽控制断言。修复后12PASS/exit0，原6项自检不变。
- 三份30项旧服务HTTP基线全部保留：83dc4070为8PASS/22FAIL；282ec0e0同RUN为5PASS/25FAIL（旧基线PDO故障已污染日志，不能用作干净验收）；新RUN=20261011112142dd83e0为12PASS/18FAIL、exit1。旧正常凭据/权限行为通过，新v2接线缺失如实失败。download探针修正为真实路由支持的字母数字参数；正常错误密码窗口先做同前缀真实成功登录，保持精确counter/tag允许增量，独立冷故障不预热。源事实及前置调整详见05计划，不删除任何历史失败。
- 首次after，RUN=202610111127061c6db5、282ec0e0加5个待提交生产文件，为27PASS/3FAIL、exit1。logout独立快照证明仅当前sid撤销，并新增一条既有AdminLogMiddleware正常审计；测试只允许该条精确actor/path/ip/page/time及10列记录、旧日志行不变，其余157表和全部其他Redis值/期限不变。两项Redis pause HTTP失败定位到更早的App::initialize→ModelService→think.Db::__make→共享Redis AUTH，旧READ_TIMEOUT=0，早于请求及管理端异常处理器。独立AuthSessionStore工厂暂停测试实际约1011ms受控503、全快照无变化，未猜改其有界连接。
- 新启动测试先提交62c201ba：旧实现8项基线6PASS/2FAIL、exit1；6项已有DB别名/真实PSR16/tag/ORM缓存兼容通过，paused bootstrap及延迟连接要求失败。旧启动7397ms，仅在3秒看门狗请求恢复、宿主实际unpause后完成；看门狗不会把旧阻塞改报PASS。默认/命名缓存、真实原生客户端和序列化、TTL、选项以及标签行为均为实框架回归，无替身或缺类断言。
- 实现根AppService提前绑定兼容Db，保留config/event/log/triggerSql；标准think.Cache派生DbCache只在首次缓存操作时委托原共享manager的选定store，保留同原native client/backend/prefix/serializer/tag。改动只推迟ORM缓存连接，不冒称覆盖运行中后续业务缓存失效。独立只读代码复核无遗留必须修复项；3个新增/修改PHP lint通过，原5个接线文件lint已通过。
- 最终启动8PASS/exit0：暂停中App初始化76.933ms、首次真实认证工厂1010.917ms固定503、无previous、无看门狗恢复；全部158张表、所有Redis值和绝对到期点、实际双runtime缓存/log无意外变化。raw为ms0b-bootstrap-after-20261011122405.jsonl，SHA256=af5b35017fd8bc3aa7ef70ce6494089a575468446b7abc96bead67ceeb7a4371。
- 最终管理端30PASS/exit0：登录v2签名/声明/服务器会话/HMAC/期限、受限正常权限与六项白名单、未登记接口拒绝、旧schema在旧md5键仍存在时401、缺sid不重建、8项冷配置503、Redis暂停与数据库闭合端口503、错误/禁用/删除凭据，以及真实本人改密后旧token401、新登录200。raw为ms0b-admin-http-after-20261011122529.jsonl，SHA256=e7bd69e95de47aef7b6947aacc64da7af4bc085eafc8d725ba6666f04302e167。失败窗口全表/缓存/期限/日志严格观察，正向仅允许逐项必要会话、统计、本人资料及精确审计变化。
- 最终两轮实际source元数据为62c201ba加当时待提交的8个生产文件；原commit_sha不改写。task4-tested-source-final.json保存8文件SHA256并确认本地生产提交后逐文件相同；首次after的task4-tested-source.json及全部诊断/失败轮保留。此为阶段接线证据，Task10仍须新RUN、清洁冻结源码及完整回归。
- 当前无Task4同秒登录/改密竞争、永久版本撤销或状态重启复活通过结论；这些原A21要求保留在Task6联合验证。MS0a全部106项在v2输入下的完整回归仍待Task10，不以本轮30项代替。
- Task4三次RUN=20261011110747e9e9a8、20261011112142dd83e0、202610111127061c6db5均已按manifest核对并teardown、exit0；仅删除各自专用容器、internal网络和9个卷，仓库外原始证据保留。未清理日志以隐藏失败，后续测试使用新RUN。

## Task5：对外接线前置（业务接线未实现）

RUN=2026101112350380d12a，新建ISO-FULL专用内部网络、MySQL/Redis、9卷及真实初始化；完成worker前置及48项旧服务基线后，已核manifest并teardown、exit0，全部专用资源删除、仓库外证据保留。生产OutAccountServices、OutApiExceptionHandle及Login入口未修改。此阶段不声称完成A25 HTTP刷新。

- worker测试先提交563f045d，旧supervisor5项基线2PASS/3FAIL、exit1：默认单进程启动/停止及非法override拒绝已通过；多worker、只读进程观察和恢复单进程因缺少严格override能力而失败。所有158张表、Redis值/绝对期限、双runtime缓存/log及真实TCP停止状态无意外变化；原始失败保留。
- 隔离harness仅新增严格SERVER_WORKERS为空或2、本app容器内登记PID/PGID/SID/starttime、有限只读observe及真实fork worker有界退出。原setsid/php think run命令、环境主机/库/账号、所有既有override和root-fingerprint/guard均保持。原生数值标识以数字/数字字符串返回，既不读environ/cmdline也不打印请求或密码；本组监听inode与实际成员fd关联，observer不做Redis写/EVAL。
- 5项after全部PASS/exit0，实际PHP日志3个server PID均属于独立登记组（2个worker加master），重复observe保留generation；stop后无组成员或8000监听，空override实际恢复单进程。raw=ms0b-http-workers-after-20261011124413.jsonl，SHA256=71fe0ab7648280aadcdd48069f50e1fa45bb86072c5314d68ec91a42feeed4a8。基线raw=ms0b-http-workers-baseline-20261011123751.jsonl，SHA256=eff5edd50ac218e8dd64e3752a7bccc344eb17eac9b3c93cb6d0dfd923b5fb59。
- 原12项selftest全部PASS/exit0，包含16次受控启动、ISO隔离/DML边界/真实Redis故障与恢复，未放宽断言。raw=selftest-after-20261011124638.jsonl，SHA256=7d6b20f429e5ba86c8503dbae93135ac9c7328abe23f8e3d974c650ec526c504。该轮生产代码无未提交改动，harness待提交文件哈希按下文记录；不冒称Task10清洁冻结。
- 额外故障诊断只在同一专用app容器、核对登记leader和supervisor的真实PID/starttime/PGID/SID后SIGKILL确切wrapper。其后4个真实子进程及8000监听仍存活；stop按原登记组处理，即使wrapper已退出仍完整TERM/必要KILL并回收。独立docker exec中的/proc数值观察再次确认整个组（包括待回收成员）空、监听关闭、158表和全部Redis值/期限不变，exit0。证据diagnostic-http-wrapper-state.json及实际diagnostic-ms0b-http-wrapper.php保留仓库外，临时源码核对归档哈希后精确删除。该诊断证明worker退出边界，不代替HTTP业务竞态。
- harness生产于测试提交563f045d加单个待提交文件时运行，后本地提交7895776a；task5-harness-tested-source.json记录SHA256=d762074a49b40e2244cb1de27b529eb6edcbd45bf1b127084e266215d25ab5d3并确认与提交后一致。原始commit_sha不改写。
- 基本48项测试先提交5ac0d37e，lint通过、源合同经独立只读审查；真实旧服务完整基线6PASS/42FAIL、exit1，raw=ms0b-out-http-baseline-20261011125527.jsonl，SHA256=c5bdd451d3679dddff739d56da3be3e47b7cfb4cd0bb890db023f2ebc6b837c9。真实service默认/显式null、空串、字符串0全部跳过旧密码核对，产生目标统计与旧会话缓存写入；HTTP缺失/null/空串原validator400和错误/禁用/删除凭据原401共6项已通过。旧schema/缺sid、当前账号状态、冷配置和Redis/DB依赖边界等未满足新契约如实FAIL；不可拿未执行的草稿当修复结果。
- 8项MCP基线全部仅失败于“actual application and server logs contain no private credential or token”一条：此前旧HTTP数据库故障污染了日志；当前MCP响应及其全表/缓存/期限/runtime零写断言均通过。initialize和真实分类工具仍返回JSON-RPC result，错误/禁用/删除/缺header返回-32600；缺JWT epoch正常可用。不擦日志或把这8项改报PASS，也不声称出现MCP协议回归。后续新RUN完整after仍必须全部严格通过。
- 未执行草稿的静态复核修正留在测试提交中：刷新old sid仅status允许变，绝对期限仍逐键核对；CLI专用prefix必须初始空，最终只精确清本次新增键；公开攻击探针0不纳整份log/JWT子串私有值扫描，真实随机密码/摘要/token/marker/部署key匹配全部保留。合成fixture两非空明文字段和关闭推送目标只在专用数据中设置，观察后精确还原；不改业务权限种子。
- 独立实源审查识别cold A24刷新调用点：postMore即使在配置/Redis/PDO前置之后，仍先于schema拒绝调用filter_str/sys_config而写缓存。05已先记录必要修复设计：refreshToken用真实Request::post('access_token', '', null)读取POST body原始签名字符串，非字符串受控401，直接单次刷新服务；登录原postMore/validator不变。不放宽冷401全零断言、不靠预热掩盖、不加重复Token验证或全局filter改动。三业务文件仍只为仓库外草稿，未安装/验证；HTTP并发及响应前断开测试继续准备，尚无Task5接线通过结论。

## 未完成

Task4完整A21、Task5对外端、Task6全部变更入口、Task7世代轮换/RDB恢复、Task8验证码/URL令牌与浏览器下载、Task9发布准备、Task10完整冻结回归和独立验收均待完成。当前HTTP通过仅限Task4上述30项；无整个MS0b或WORKER/BROWSER/EXTERNAL验收通过结论，接口实际在用情况未知。此报告不构成人工签收。

results.jsonl当前共1082条INTEGRATION原始行：原Task1–3共822行，加Task4共190行，以及Task5 worker/selftest22行和对外旧服务基线48行。历史失败不抹除，原commit_sha不改写；Task1–3最后一轮239项及Task4最后38项全PASS，不能相加为整个里程碑验收。Task5前置17项通过仅证明隔离执行能力，wrapper额外诊断JSON单列；48项仍只是旧服务基线。UNIT-STUB43文本证据单独统计；整个MS0b未完成，后续任务与交付继续更新本记录。
