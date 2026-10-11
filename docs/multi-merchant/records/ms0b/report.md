# MS0b 进度记录（未交付、未签收）

当前阶段：L2 隔离开发，Task1–3已实现并验证；Task4管理端接线已本地提交，30项HTTP和8项启动/缓存兼容回归通过。Task5对外端已接入v2并本地提交，6项独立正常业务检查通过；Task6账号变更接线已本地提交，15项正常CLI服务检查通过、1项filePwd兼容检查NOT RUN。Task7世代清理命令与runbook已本地提交，3项独立正常CLI检查通过；Task8–10待完成，整个MS0b尚未完成。按用户要求跳过的安全复现、故障和并发验证保留未验收缺口。没有推送、部署、外部通知或真实环境会话切换。README验收状态和SECURITY_BASE_SHA未由执行方改写。

开发起点为MS0a交付3e7c966ecfa084ff4f219b03c61ed0efbac4caac；用户明确委托按规划自主继续并解决问题，V10人工签收仍待。工作目录H:/天俪商城/tianli-mall--ms0b，分支codex/ms0b。会话状态仅Redis，无新数据库表/列/种子。

## 2026-10-11 用户要求跳过安全复现与故障试验

用户最新指令“跳过行不行，再把我号封了”已纳入05计划。停止同类安全复现、故障注入及并发/断连试验，后续仅推进普通功能开发和常规检查；不自动恢复、改名或换入口重跑。认证与权限功能要求保留，完整安全验收仍有缺口，未签收。

| 用例 | 当前状态 | 实际执行 |
|---|---|---|
| A25 / out-http-two-worker-single-refresh | 按用户要求跳过 | 测试源码95b1e0cb已提交，未运行 |
| A25 / out-http-response-before-read-disconnect | 按用户要求跳过 | 测试源码95b1e0cb已提交，未运行 |
| Task4/6 并发及部分失败、Task7 RDB恢复、Task10同类综合验证 | 按用户要求跳过 | 保留原覆盖要求与缺口，不再自动执行 |

RUN=202610111416385a3cf0仅完成引导安装，没有产生新测试JSONL。已执行teardown.sh，manifest身份核对通过、exit0；本RUN的3容器、1隔离网络、8专用卷已移除，独立复查无audit容器及本RUN网络/卷残留。C:/Users/Vwang/.tianli-audit/202610111416385a3cf0保留，不删除原证据。此处跳过记录不追加为results.jsonl的实际运行行，不生成空副作用快照或PASS。

run.sh新增执行前保护，现有综合安全/故障套件在读取RUN或调用Docker前退出2；这表示未执行，不是测试通过。本次仅检查Bash语法、9个套件入口均在环境操作前按预期退出2、git diff --check，以及results.jsonl相对HEAD无变化；没有运行认证或故障试验。普通业务检查后续单独选择。现有1082条原始结果不变，既有失败和此前验证结论保留。

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

## Task5：对外接线前置历史与普通功能接线

RUN=2026101112350380d12a，新建ISO-FULL专用内部网络、MySQL/Redis、9卷及真实初始化；完成worker前置及48项旧服务基线后，已核manifest并teardown、exit0，全部专用资源删除、仓库外证据保留。生产OutAccountServices、OutApiExceptionHandle及Login入口未修改。此阶段不声称完成A25 HTTP刷新。

- worker测试先提交563f045d，旧supervisor5项基线2PASS/3FAIL、exit1：默认单进程启动/停止及非法override拒绝已通过；多worker、只读进程观察和恢复单进程因缺少严格override能力而失败。所有158张表、Redis值/绝对期限、双runtime缓存/log及真实TCP停止状态无意外变化；原始失败保留。
- 隔离harness仅新增严格SERVER_WORKERS为空或2、本app容器内登记PID/PGID/SID/starttime、有限只读observe及真实fork worker有界退出。原setsid/php think run命令、环境主机/库/账号、所有既有override和root-fingerprint/guard均保持。原生数值标识以数字/数字字符串返回，既不读environ/cmdline也不打印请求或密码；本组监听inode与实际成员fd关联，observer不做Redis写/EVAL。
- 5项after全部PASS/exit0，实际PHP日志3个server PID均属于独立登记组（2个worker加master），重复observe保留generation；stop后无组成员或8000监听，空override实际恢复单进程。raw=ms0b-http-workers-after-20261011124413.jsonl，SHA256=71fe0ab7648280aadcdd48069f50e1fa45bb86072c5314d68ec91a42feeed4a8。基线raw=ms0b-http-workers-baseline-20261011123751.jsonl，SHA256=eff5edd50ac218e8dd64e3752a7bccc344eb17eac9b3c93cb6d0dfd923b5fb59。
- 原12项selftest全部PASS/exit0，包含16次受控启动、ISO隔离/DML边界/真实Redis故障与恢复，未放宽断言。raw=selftest-after-20261011124638.jsonl，SHA256=7d6b20f429e5ba86c8503dbae93135ac9c7328abe23f8e3d974c650ec526c504。该轮生产代码无未提交改动，harness待提交文件哈希按下文记录；不冒称Task10清洁冻结。
- 额外故障诊断只在同一专用app容器、核对登记leader和supervisor的真实PID/starttime/PGID/SID后SIGKILL确切wrapper。其后4个真实子进程及8000监听仍存活；stop按原登记组处理，即使wrapper已退出仍完整TERM/必要KILL并回收。独立docker exec中的/proc数值观察再次确认整个组（包括待回收成员）空、监听关闭、158表和全部Redis值/期限不变，exit0。证据diagnostic-http-wrapper-state.json及实际diagnostic-ms0b-http-wrapper.php保留仓库外，临时源码核对归档哈希后精确删除。该诊断证明worker退出边界，不代替HTTP业务竞态。
- harness生产于测试提交563f045d加单个待提交文件时运行，后本地提交7895776a；task5-harness-tested-source.json记录SHA256=d762074a49b40e2244cb1de27b529eb6edcbd45bf1b127084e266215d25ab5d3并确认与提交后一致。原始commit_sha不改写。
- 基本48项测试先提交5ac0d37e，lint通过、源合同经独立只读审查；真实旧服务完整基线6PASS/42FAIL、exit1，raw=ms0b-out-http-baseline-20261011125527.jsonl，SHA256=c5bdd451d3679dddff739d56da3be3e47b7cfb4cd0bb890db023f2ebc6b837c9。真实service默认/显式null、空串、字符串0全部跳过旧密码核对，产生目标统计与旧会话缓存写入；HTTP缺失/null/空串原validator400和错误/禁用/删除凭据原401共6项已通过。旧schema/缺sid、当前账号状态、冷配置和Redis/DB依赖边界等未满足新契约如实FAIL；不可拿未执行的草稿当修复结果。
- 8项MCP基线全部仅失败于“actual application and server logs contain no private credential or token”一条：此前旧HTTP数据库故障污染了日志；当前MCP响应及其全表/缓存/期限/runtime零写断言均通过。initialize和真实分类工具仍返回JSON-RPC result，错误/禁用/删除/缺header返回-32600；缺JWT epoch正常可用。不擦日志或把这8项改报PASS，也不声称出现MCP协议回归。原计划的完整after未执行；含安全复现/故障的综合套件现按用户要求停止，常规业务检查另行选择，既有8项FAIL不改写。
- 未执行草稿的静态复核修正留在测试提交中：刷新old sid仅status允许变，绝对期限仍逐键核对；CLI专用prefix必须初始空，最终只精确清本次新增键；公开攻击探针0不纳整份log/JWT子串私有值扫描，真实随机密码/摘要/token/marker/部署key匹配全部保留。合成fixture两非空明文字段和关闭推送目标只在专用数据中设置，观察后精确还原；不改业务权限种子。
- 独立实源审查识别cold A24刷新调用点：postMore即使在配置/Redis/PDO前置之后，仍先于schema拒绝调用filter_str/sys_config而写缓存。05已先记录必要修复设计：refreshToken用真实Request::post('access_token', '', null)读取POST body原始签名字符串，非字符串受控401，直接单次刷新服务；登录原postMore/validator不变。不放宽冷401全零断言、不靠预热掩盖、不加重复Token验证或全局filter改动。此前三业务文件仅为仓库外草稿，未安装/验证；现已完成下述普通功能接线。HTTP并发及响应前断开测试定义已提交95b1e0cb，但未运行，现按用户要求跳过；尚无Task5接线通过结论。

## Task5：独立正常功能验证（已接线，安全覆盖仍按要求跳过）

测试提交6d8c86ca，业务提交3acfd5ae2dc63c16bd2cc8bdd846f3c73bb5447b。三个业务文件为OutAccountServices、OutApiExceptionHandle、outapi Login；其字节哈希与受审草稿和运行时hash一致。登录/parse/refresh统一用v2模块，正常统计只写last_time/ip；auth_info省略appsecret/apppwd/push_password，保留原规则形态；MCP、后台CRUD和推送读取未改。三项PHP8.2 lint和diff检查通过，独立静态复核无遗留必须修复项。

RUN=20261011143154925356，仅使用既有隔离初始化和合成账号。独立ms0b-out-functional.php只有F01–F06正常场景，不引用综合套件、fault或token-forge，不更改凭据/状态，不重发刷新前的token。正常初始化（两账号登录、分类首用缓存）单独保存完整变化摘要，不写成PASS；正式七个窗口检查全部158张表、所有Redis实际值和TIME/PTTL绝对期限、真实runtime文件/日志。目标last_time/ip精确核对；会话写入只允许实际正常返回token所绑定的键；其他行/列/业务缓存不变。

| 正常检查 | 旧服务基线 | 接线后 |
|---|---|---|
| F01 实际HTTP登录、v2会话与auth_info | FAIL：缺v2绑定/DTO契约 | PASS |
| F02 实际授权分类读取、8列和日期格式/顺序 | PASS | PASS |
| F03 一次正常刷新、实际新token读取及真实会话期限 | FAIL：正常请求已执行，缺v2会话契约 | PASS |
| F04 同一真实服务实例A/B/A身份DTO | FAIL：DTO仍含待隐藏字段 | PASS |
| F05 正常MCP header initialize | PASS | PASS |
| F06 正常MCP只读分类工具、实际数据库行/数量 | PASS | PASS |

基线3PASS/3FAIL；原PowerShell包装仅返回宿主exit0，没有捕获Bash原生码，不能以此记成功，失败结论来自六条实际记录。该包装缺口已在仓库外summary注明；未据无法检索到的Docker历史事件猜写exit码。修复后6PASS、Bash原生exit0且宿主exit0。以下原始文件和source元数据保留：

- ms0b-out-functional-baseline-20261011145132.jsonl，SHA256=976e31102443a9c8228b6a9f566f1356a6cd2498ba4bb4b9fa7e5d8afc07fd7c。
- ms0b-out-functional-after-20261011145454.jsonl，SHA256=7f5c2ee44e6acdad4c054bcbadfad4a206321bd2cb3efe8d50a69fabda3d3238。
- task5-functional-tested-source.json保留测试时HEAD=6d8c86ca及当时3个未提交业务文件哈希，注明提交后内容一致，不改raw commit_sha。测试脚本SHA256=7b9670298bf9e98bfbf347ae305cfc30ad81d6113461b2a0c597d6181c159e78。

本RUN已teardown、manifest身份核对通过、Bash exit0；3个专用容器、隔离网络、9个专用卷移除并复查无残留。证据根C:/Users/Vwang/.tianli-audit/20261011143154925356/evidence保留。这个6项通过只证明正常功能接线，不替代已跳过的原安全/故障/并发/断连验证或整个MS0b验收。

## Task6：账号变更接线与正常CLI服务验证（已本地提交）

业务提交8efff286c08e60c28a28d11227760f14ebf2980c，包含AccountChangeGuard、SystemAdminServices、OutAccountServices与DivisionServices，覆盖入口清单W01–W10。Guard持有真实最外层SQL事务，实际PDO退出事务后才按本次owner完成；Division组合中的内层writer复用同app、同连接、已保护主体集合。批量操作冻结真实管理员id，空集合不伪造主体；合法同值更新和原Model/boolean/void返回保留。普通新建保留自动id及原事务，纯资料/统计/推送配置不轮换版本。原filePwd方法未改、未调用；数据库结构、权限种子与其他应用端未变。四个PHP8.2 lint及检查脚本lint、diff检查通过，四个业务文件与独立审查的草稿和测试内容哈希一致。

正常检查测试先提交d52ba7dd。最初两份旧writer基线各4PASS/11FAIL/1NOT RUN、Bash exit1：W08/W09/W10及N01的Division窗口被检查脚本TypeError中断，其他已有窗口保留。e70aa915仅补类名/文件位置诊断，证实错误位于cf_user入口；PHP函数名不区分大小写，cf_same的通用is_callable把行数据昵称CF_USER当成同名函数，向array参数传了字符串。32953a26改为只执行显式Closure，时间谓词也显式包装；所有原行/列及期限断言保持，未换昵称或跳过字段。两份真实失败不删除、不改写。

同一独立RUN=202610111523384ecf19修正后的旧writer基线为5PASS/10FAIL/1NOT RUN、Bash exit1，W01–W10仅缺账号版本轮换。实现后15PASS/1NOT RUN、Bash exit0；N01/N02创建、纯资料、统计、推送配置均正常。W01/W05另用新密码/新secret各成功登录一次，核对真实签名/声明、当前数据库摘要HMAC、完整会话HASH、当前版本和exp+60期限；新登录只允许准确的一条新会话及目标统计列，未请求旧/错误凭据。

正式22个观察窗口全部完成，逐窗核对158张表、目标表全部行/列、所有Redis实际值及TIME/PTTL绝对期限、真实app/runner runtime文件和日志。仅允许目标列、目标账号版本轮换，以及完整绑定的新登录会话；其他账号、原会话记录/期限和业务缓存保持。本人资料缓存失效仅允许本次预先观察到的准确tag成员删除。每轮finally仅清本RUN注册的新合成行及对应键，四份cleanup均证明原数据行/其他缓存回到准备前快照，runtime清理期间不变；不回拨AUTO_INCREMENT或执行DDL。

原始文件位于C:/Users/Vwang/.tianli-audit/202610111523384ecf19/evidence，摘要见task6-functional-summary.json；results.jsonl追加四轮各16条，保留原commit_sha及全部历史失败：

- ms0b-account-functional-baseline-20261011154109.jsonl，SHA256=1c5c850b008c8ef9e670d9d840d19068e0372205afe9a6a52dc676d27f1863c3。
- ms0b-account-functional-baseline-20261011154318.jsonl，SHA256=bade26aba99eca0a22b6a69b6a69302f64a5807618de2e77e1071149843aa7e4。
- ms0b-account-functional-baseline-20261011154430.jsonl，SHA256=51a7535407959aa630e29011375678ddf1bde10be94617f17d25df9be333b9cf。
- ms0b-account-functional-after-20261011154543.jsonl，SHA256=ade4516336cbf056e113c762711e22149c172982d8823e7cb6823086b39593cc。

task6-functional-tested-source.json保留测试时HEAD=32953a26及当时四个未提交业务文件哈希，注明与8efff286一致；不把raw source改成提交后的HEAD。专用3容器、1内部网络、9卷已通过manifest身份核对teardown，原生Bash exit0，随后独立查询无残留，匹配本RUN的install.lock已移除，仓库外证据保留。

filePwd检查明确NOT RUN：安装表eb_system_admin没有file_pwd列，而旧setFilePassword赋值该列，未调用方法、未造DDL，不能声称密码持久化正常。上述15项仅是CLI-SERVICE正常功能证据，本Task的HTTP、旧令牌拒绝、并发/部分失败及其他被跳过验证没有通过结论。

## Task7：世代清理命令与操作说明（已本地提交）

业务提交a9c3ce355bb76349cc3cb2be31c0e2293b1a8b14，包含app/command/AuthEpochRotate、AuthSessionStore的窄deleteOldEpoch方法、console显式注册及runbook-session-loss.md。命令要求显式old-epoch/confirm，读取实际已加载的新配置并确认不同；Store复用现有独立有界连接，重复核当前live配置与实例一致，raw SCAN严格解析uint64游标文本与键名list，逐页完整前缀核对后最多200键一批DEL。成功仅输出实际deleted_keys数量/exit0，失败固定说明/exit1，无driver私有信息或自动重试。命令不替操作方修改配置或数据库，不证明全部部署实例已更新。三PHP lint及检查脚本lint、UTF-8/LF与diff检查通过，源与独立只读审查/测试内容哈希相同。

设计提交ed08f94b、测试903e0dfb先于业务。RUN=20261011160257a153b4独立普通基线2FAIL/1NOT RUN、Bash exit1：真实think list本身exit0但无注册，help及清理命令exit1；旧空间未被正常清空，所以空空间重复项NOT RUN、没有额外准备清理来制造通过。实现后3PASS、Bash exit0，四个实际think子进程均exit0：list/help成功，精确清理实际删除1026旧空间键、stdout严格为deleted_keys: 1026，独立Redis INFO证明7次DEL；同一个真实已空旧空间重复执行输出0、没有DEL。四个正式窗口逐一核对158表、所有其他Redis值/TIME/PTTL绝对期限及app/runner runtime和私密值日志，均保持。

准备阶段用真实AuthTokenV2.issue、Store.initAccount和CredentialStamp，在本RUN三种已确认初始为空的正常空间产生old1024个签发会话+2账户、current两会话+2账户、相似前缀sibling两会话+2账户。父进程Config仅在正常签发准备时选择对应空间，随后完整恢复当前配置；子进程通过实际PHP_BINARY/think/cwd及私有PHP_环境读取当前配置，未改.env。准备记录核对真实HASH/digest/HMAC/version及期限，仅作为准备证据；没有解析、验证或重放旧token，没有恢复Redis或修改数据库。两份finally清理均仅删精确登记的剩余自有键，原全表/其他Redis值与期限、runtime及父配置还原，证据根C:/Users/Vwang/.tianli-audit/20261011160257a153b4/evidence保留。

- ms0b-epoch-functional-baseline-20261011161722.jsonl，SHA256=f0826d1af0f55533faa2ecacf81ebb74da0f1b4927f545214540c06f4af93cf9。
- ms0b-epoch-functional-after-20261011161938.jsonl，SHA256=179183ac074d543e2596037821ef65dc18bf45f9fb38f73d49f1ca33f303e51e。
- task7-functional-tested-source.json保留运行时HEAD=903e0dfb及三未提交业务文件、runbook哈希，与a9c3ce35一致；检查脚本SHA256=1fccac5875bc76f96345d27eb36c30efd566582a33e8b17f449405c2e90b58cf，原始结果commit_sha不改写。仓内task7-functional-summary.json保存计数、原生退出、实际DEL调用、源码及准备/清理摘要。

专用3容器、1内部网络、9卷已按manifest核对身份teardown，原生Bash exit0；随后独立查询无残留，匹配本RUN的public/install.lock已移除。runbook明确人工隔离入口、全实例新配置下发/重载、精确旧空间清理、正常重新登录以及失败后保持新配置的处理顺序；还按实源注明MCP直接使用account/password请求头，不消费v2会话，数据库恢复时需单独核对该入口凭据/状态。当前没有真实部署、通知或恢复操作。

恢复、依赖故障、并发、旧令牌拒绝及负向选项检查未执行；这3项通过只支持普通命令功能，不构成完整A29或MS0b验收。

## 未完成

Task4完整A21、Task5原安全覆盖、Task6完整安全覆盖及filePwd兼容、Task7完整A29恢复验证（按用户要求跳过）、Task8验证码/URL令牌与浏览器下载、Task9发布准备、Task10常规冻结回归和独立验收待完成；同类安全复现/故障验证已按用户要求跳过，完整安全验收缺口保留。正常接线运行证据为Task4上述30项、Task5独立F01–F06（含真实HTTP、同实例CLI和MCP）、Task6上述15项CLI服务及Task7上述3项实际CLI检查；无整个MS0b或WORKER/BROWSER/EXTERNAL验收通过结论，接口实际在用情况未知。此报告不构成人工签收。

results.jsonl当前共1164条INTEGRATION原始行：原Task1–3共822行，加Task4共190行，以及Task5 worker/selftest22行、对外旧服务综合基线48行、普通功能基线6行和修复后6行，再追加Task6三份普通基线及一份after共64行、Task7普通基线及after共6行。原1158行逐字节保持，历史失败不抹除，原commit_sha不改写；Task1–3最后一轮239项、Task4最后38项、Task5普通功能最后6项各自全PASS，Task6最后15PASS/1NOT RUN，Task7最后3PASS，不能相加为整个里程碑验收。Task5前置17项通过仅证明隔离执行能力，wrapper额外诊断JSON单列；48项仍只是旧服务基线，其综合after已按用户要求停止。UNIT-STUB43文本证据单独统计；整个MS0b未完成，后续任务与交付继续更新本记录。

### Task8 普通检查：首份旧源基线（未完成）

2026-10-12 按用户跳过指令继续正常功能。测试提交0ecd0b51先于任何Task8业务安装；RUN `20261011165615374dae` 的6项正常场景实际3PASS/3FAIL，原生Bash exit1。Info、合法HTTP登录、现有验证码创建/图片展示形态通过；账号0真实DAO、CRUD producer/首次消费组合、备份producer/首次消费组合失败。准备成功、严格清理失败，原JSONL与summary未改写。普通route及encoded query两个首次消费窗口实际都HTTP200且字节/长度/文件名和文件缓存删除通过；不能将后者称为已复现query数组TypeError。

已核对首轮异常：旧账号0遗漏谓词；CRUD仍用确定性MD5 key。检查脚本还误拒安装空site_url产生的合法relative链接，并将更早预览的四个原生TIME字段当成实际producer时间，其余五生成文件通过；这些属于检查本身待修。备份producer则实测HTTP500/JSON业务200，已有日志与源码定位未开fp的MysqlBackupService析构gzclose(null)；保持HTTP200断言，按同调用点修真实PHP8普通缺陷。四个未匹配文件没有登记删除，rmdir非空原样记录。真实代码/ZIP已经manifest容器身份核对复制到外部证据，随后teardown原生exit0；3容器/1网络/10卷均无残留，安装锁删除，证据保留。

仓库results现1170条：原1164条字节完全保持（前缀SHA256 `b5c83a5d8cf558dd8e99f8849a70e0b6feec7b9de1326ad254ec8ebaefd0d61b`），只追加首轮6条真实结果；新原始JSONL SHA256 `410200a3155a137519c098cb88c183c0a907fcc3545085f14f66c52cb60ed695`。细节见 `task8-first-baseline-summary.json`。这轮没有安全复现、错误凭据、验证码解答、URL JWT拒绝、重复文件消费、故障或并发验证；Task8业务尚未安装，浏览器与完整验收仍待。

## Task8：登录与下载普通功能接线（浏览器验收待完成）

Q6 业务已本地提交 `5b95cfa7395c1c7c37444aa63f9af9c8cc1a4b50`，Q7 已本地提交 `875b93007f6b3741ca005c30483a6b25ced74382`，两组共15个唯一业务路径。本节更新前文“Task8业务尚未安装”的阶段状态；首轮历史失败和原始证据保留。当前只取得独立普通HTTP/CLI功能及前端构建证据，未取得完整Task8或MS0b验收。

RUN `2026101117390914bd52` 的四次原生Bash结果如下。四次 `prepare_failed=false`、`cleanup_failed=false`，根代理记录的 `recording_failed=false`；原始清理证据均证明原快照恢复、runtime在清理中不变及私密日志检查通过。

| 运行 | execution | 普通结果 | 原生Bash exit | raw记录的HEAD |
| --- | --- | --- | --- | --- |
| 全量旧源修正版基线 | 20261011174647361024 | 3PASS / 3FAIL | 1 | 6d23a4c6 |
| Q6普通after | 20261011175556317913 | 4PASS | 0 | 6d23a4c6 |
| Q7旧下载源基线 | 20261011175723275580 | 2FAIL | 1 | dfd2a2eb |
| Q6/Q7全量普通after | 20261011175814920773 | 6PASS | 0 | dfd2a2eb |

最后一轮的六项普通检查如下，原始层级均为INTEGRATION；账号0项通过同实例CLI真实DAO，其余为真实HTTP。

| case | 实际正常检查范围 | 最后结果 | 断言 / 正式窗口 |
| --- | --- | --- | --- |
| T8F01 | 登录信息JSON数字 `login_captcha=0` | PASS | 5/5；1窗 |
| T8F02 | 合法凭据登录；签名DTO、当前会话登记、账户版本保持及精确统计列 | PASS | 13/13；1窗 |
| T8F03 | 字符串账号0添加真实DAO谓词，完整列仅返回合成账号0行 | PASS | 5/5；1窗 |
| T8F04 | 现有验证码正常创建、图片数据可展示、未解答挑战缓存期限 | PASS | 12/12；1窗 |
| T8F05 | 真实CRUD producer；新32hex key；backup内9文件/唯一9条ZIP；首次header文件消费 | PASS | 30/30；2窗 |
| T8F06 | 真实备份producer；原bcrypt key；编码query key与header认证的首次文件消费 | PASS | 15/15；2窗 |

四轮共42个真实快照窗口：24个正式业务窗口、18个独立准备窗口；每窗核对158张实际表、所有其他Redis值及TIME/PTTL绝对期限、实际app/runner runtime、私密日志与backup树。许可只覆盖精确合成行、统计列、native会话登记、已核值/期限的具体缓存及tag增量、每个producer恰一条完整匹配的新审计日志，以及已逐一核对的自有文件；没有整表或整prefix忽略。四轮所有非预期db/cache差异为空，cleanup实际差异的tables/redis/runtime/backup均为空。最后全量after含8个正式窗口及5个准备窗口；准备不计为六项PASS。

首RUN `20261011165615374dae` 的原3PASS/3FAIL、Bash exit1、cleanup失败仍保留，raw SHA256 `410200a3155a137519c098cb88c183c0a907fcc3545085f14f66c52cb60ed695`。第二RUN的全量修正版基线仍3PASS/3FAIL：账号0旧谓词缺失、CRUD旧MD5合同、备份producer HTTP500/业务JSON200三个真实失败保留；其route与encoded query首次消费实际HTTP200，不能声称query数组TypeError已复现。检查预期只修正空site_url合法root-relative路径及四个原生stub第17行TIME：时间严格在真实producer窗口、使用实际App时区，随后仍比较每个完整文件字节。测试提交 `dfd2a2eb6b86143e13e7feb39a5490fb2f0c2813` 又精确统计ZIP的9个非目录条目、拒绝重复名称，不新增场景。Q7仅装Q6后的旧下载源独立基线仍2FAIL；Q7安装后两个producer及两个首次consumer均HTTP200。普通备份修复为未打开fp时避免析构 `gzclose(null)`，原业务HTTP200断言保留。

运行时 `commit_sha` 原样保留：Q6 after时HEAD为6d23a4c6，Q7全量after时HEAD为dfd2a2eb，业务候选尚未提交。通过 `q6-normal-tested-source.json`、`q7-normal-tested-source.json` 的文件SHA关联后续5b95cfa7/875b9300；15个提交文件均与测试收据一致，不能把raw的HEAD改成后续业务提交。最终Store SHA256为 `eda31f5e1856725a32e2777f096d0ccc5ec7b22841f599636a13584e3c54c054`。

第二RUN四份原始JSONL的SHA256依次为：

- `ms0b-login-download-functional-20261011174647361024.jsonl`：`3c7722d67682a1b406d0ee9e33ba85636404aa94363834bfb57b701c6d21552c`
- `ms0b-login-download-functional-20261011175556317913.jsonl`：`9416bd6d33107162b7c415c5d5d0ea591d2c68458b0728f0f60d7ffee72d1c10`
- `ms0b-login-download-functional-20261011175723275580.jsonl`：`063b4befe2d9f96c8a959e70339a890f047c25dac17f596cefb4be0fc8776d72`
- `ms0b-login-download-functional-20261011175814920773.jsonl`：`f9f7593b0c09923cfe5250eda4b3c8bf930750d5d9ee673337d1beb788e00f74`

详细summary、cleanup和最后六项证据的完整SHA见 `task8-functional-summary.json`。Q6/Q7源码收据SHA分别为 `8880e9040fcef7075ad0a50a8c6b31be2ae2d80f6c651ef6b6402dee8ee16e7d`、`70fb4e4961fa15898f1d76c291be309aef0d2d171cd66b801dfb9edc8390c331`。四份raw共18条，根代理已字节原样追加，results现1188条；原1170条/935629字节前缀SHA `3867b1ebebbfb66753ef6473c7b91f939d1f6838a68b8685ad51f2792fd9106c` 保留，新文件SHA `8865e3914da7aeafe49140cc3ccbe5060e726cbf1233417dcfd60be24d207405`，本稿只读复核一致。首轮6条和四轮所有FAIL均未抹除，累计行数不能当作整个里程碑验收通过数。

前端是独立BUILD证据：三份已提交源码与已构建副本SHA一致，`production_build_exit=0`，build-summary SHA `13d232da9210cb22ad18d966ace658a9a7fe3a1004ea798f71681ac2d24a22bd`，日志SHA `b04efe4b4613060c995c539f7a984224a29079b38d94fe816065ad5e80cf9bcc`。其中 `prepared_store_sha256=2821b29a…` 是构建收据中的历史后端稿引用，不能作为最终Store证明；最终身份以Q6测试收据为准。浏览器登录、真实保存文件和浏览器下载入口仍待验证，构建和后端HTTP不构成BROWSER通过。

验证码仅检查创建/图片展示数据，完整解答及人工通过未执行；错误凭据、失败计数门槛/绕过、URL JWT拒绝、重复消费拒绝、安全复现、故障、并发/断连及RDB/恢复检查均按用户要求NOT RUN，没有换入口重跑或记为PASS。普通逻辑cleanup已恢复；第二RUN专用Docker资源teardown另由根代理记录，本四份功能收据不包含该结论。仍为L2本地交付，未push、部署、外部通知或人工签收；Task9–10与完整验收缺口保留。

### Task8：正常浏览器文件保存补充证据

RUN `2026101117390914bd52` 的两个真实已构建后台页面完成正常首次 FileSaver 保存；B01/B02 为 BROWSER PASS。原检查脚本首次未找到可点击下载控件的失败保留为 B00 FAIL，不记为应用业务失败。第三次仅观察正常页面 console，不新增用例或 PASS。本段依据已有收据整理，未重新执行程序或测试。

| 执行 | 原始状态 / 原生 Node 退出码 | 实际结果 |
| --- | --- | --- |
| `20261011183458234495` | INCOMPLETE / 1 | 检查脚本 readiness 失败；没有执行下载，保存文件 0，截图 0；cleanup true。 |
| `20261011183753140496` | PASS / 0 | 两个真实 action 各触发一次正常保存；保存名称、字节数及 SHA 与实际 producer/首次消费响应一致；四截图完成。 |
| `20261011184546373226` | OBSERVED / 0 | 正常页面 console 观察，未再次下载，保存文件 0；不作为额外 PASS。 |

退出码来自 root 已捕获的原生执行收据，不是原始 summary 内的字段。三份原始 summary 位于该 RUN 的 `evidence/`，完整字节 SHA256 分别为：

- `task8-browser-20261011183458234495-summary.json`：`7e278bf5aa4b5853ca408b5117f71de8690db40507220fa64153ac0956738a52`。
- `task8-browser-20261011183753140496-summary.json`：`3b970c0ce11c729da8fff1e304a76b1355d19a9854daa35cef0d023cc0a8511f`。
- `task8-browser-20261011184546373226-summary.json`：`dce913f2d166a104e5da629fb7469d2ba7ed295f7e11d9edaf7f6919a31710e1`。

| 生成摘要用例 | 正常保存文件 | 字节数 | 文件 SHA256 |
| --- | --- | ---: | --- |
| B01 PASS | `systemCrudList.zip` | 12096 | `6675c93d027ba66bee251b9f3bbea14a634741b7a93092d5f1348c20ad4593ad` |
| B02 PASS | `20261012-023754-1.sql.gz` | 496 | `1ac147737c96818a785e47c0aa71ff01016e1ead2c76f1099583080a06e0c5fc` |

成功轮 source HEAD 为 `ec2c84b7dc92a38934b60051f4391bc7080a9cf0`。执行时 runner SHA 为 `5cc7bb2ad886e716c9ac0ad54bc005ad398dfee0f0ceaa3dcf07afc029bec0b0`，与 `browser/archive/run-browser-5cc7bb2a.cjs` 归档一致；当前 `run-browser.cjs` 是第三次观察版 `333798f6c4d82bc522d922ee925bdcdc83bbe70dadafe894970b6f8990616e2b`，不冒称成功轮源码。三轮 fixture `089742e11404dbf66eede95d3c4e7d735e350520b0785c591f49f1adb1dbc428`、transport `0e42ed55a037481e1986b941b3d537bea42bf807ddc45b34f12505d439a8ff4c` 与原收据一致。首次 runner 哈希仅保留收据引用，未声称已核实其归档。原 summary 的 `built_script_assets` 保留实际加载构建资产哈希，本次不是一次新构建。

成功轮 `crud-1365.png` / `crud-1024.png` / `backup-1365.png` / `backup-1024.png` 四图的 SHA、1365×900 / 1024×768 bounds 均与收据一致。root 已查看图片，下载 action 无裁剪、文字适配；原有列表固定列及水平滚动保持。截图完整哈希列于 `task8-browser-summary.json`。这只确认两项下载 action 及相应大小下的呈现，不扩为全站 UI 验收。

三轮 cleanup 均 true / original_restored true，未匹配日志及缺失审计事实均 0。成功轮精确清理自有 3 行、11 条已认领审计日志、27 个缓存键、11 个文件；首次为 3/4/27/1，观察轮为 3/9/27/1。原 158 表、全部 Redis 值及绝对到期点、runtime 和 backup 均恢复，各轮 pre/post diff 四类为空；正常统计、日志与缓存变化通过 fixture 精确认领。本段不保留私有 state、真实账号、JWT、密码、密钥、文件 key、请求/响应正文。

Console 并非无错。三轮均保留 pageerror `Event` 的消息 SHA `4e1f49a9c8ae8a158434e647c0c410352d718984a4fcd144d26cca3c394caa02`，次数 2/4/4；原 `socket_related=false` 不改写。root 核对固定文本哈希及未改 `template/admin/src/libs/socket.js:createSocket.error` 的 `reject(e)`，隔离环境两项 WS 服务未运行；另两种原 `socket_related=true` 的 error 哈希 `02305d49…` / `f56aad00…` 单独保留，次数均 1/2/2。该文件与 MS0a 开发基点 `3e7c966ecfa084ff4f219b03c61ed0efbac4caac` 的 diff 为空，仅为静态差异证据，不是 WORKER 通过或正式安全基线。

消息 `Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector` 的精确 SHA 为 `45427d24ad2d164eb9faf5df85dd0a36332d31f1ca61da52d0ddb04a097d105a`，次数 2/3/3；root 核对其对应 `main.js:193–195` 旧外部统计脚本被离线路由阻断。原 route_failures 各为 0，也不能据此宣称 console 清洁、外部统计、生产网络或 WORKER 验收通过。

`browser-results-summary.jsonl` 是依据原始浏览器及 root 执行收据生成的三条安全摘要，明确 `record_origin=GENERATED_SUMMARY_FROM_BROWSER_RECEIPT`；不是 harness 原始 raw JSONL。B00 为检查脚本 readiness FAIL，B01/B02 为正常文件保存 PASS；不为观察轮制造用例。外稿生成时仓库结果仍为 1188 行，本代理未追加三条、未改写原始 summary。原 1188 行 SHA 为 `8865e3914da7aeafe49140cc3ccbe5060e726cbf1233417dcfd60be24d207405`；根代理安装后另记录追加收据。

此前“两个下载页面正常保存待 BROWSER 核对”的缺口由上述窄范围证据补齐。验证码仍仅正常显示、未解答；没有原 URL JWT 浏览器基线。用户跳过的旧凭据/令牌拒绝、重复消费、安全复现、故障、并发、断连与恢复检查均未重跑、不记通过。Task8 和整个 MS0b 完整验收、WORKER/EXTERNAL 及人工签收缺口保留；L2 仅本地，未推送、部署或通知集成方。

根代理已按冻结稿完整字节追加上述3条生成摘要，results现1191条；原1188条前缀SHA保持8865e3914da7aeafe49140cc3ccbe5060e726cbf1233417dcfd60be24d207405，新增摘要SHA为f1587ce89e89963525e98b4de41ff01c66c63e1fecd1d1b2595d3a01de5db93f。安装收据位于仓库外browser/record-append-receipt.json。专用RUN已按manifest完成teardown：原生Bash exit0，复查容器/网络/卷均为0，install.lock不存在，证据保留；仓库外browser/teardown-summary.json记录该结论。

### Task9：发布准备文档本地交付

`integration-inventory.md` 已按00 §11.3列出14组源码入口，并关联用途、认证、重新取令牌、负责人、最近使用窗口、切换责任及普通验证方式；全部真实使用均为“未知，待核实”，没有读取生产账号/日志或联系集成方。`cutover-plan.md` 已列出发布前记录、配置实际加载合同、部署顺序、长连接卸载影响和保留MS0a/v2边界的回退约束。两份文档的本地Task8下载BROWSER证据与生产调用方待验状态分开记载；文档任务完成不代表发布前置条件满足。当前仅L2，推送需L3，部署/通知/生产轮换需L4，均未执行。

同次源码盘点在 `ec2c84b7` 分别移除WorkermanService与ChatService各一条原始消息var_dump；精确两行差异、PHP8.2两个文件lint exit0已记录于仓库外Task9收据SHA `09fa06ec699d75432a320f950ab1a718efaceac3152bb4dfed538c1b2f01074b`。未运行worker或读取生产日志，不能据此证明全部日志已脱敏、WORKER验收或旧连接卸载。没有新增安全复现或恢复被用户跳过的检查，Task10常规冻结和完整验收缺口仍保留。

### Task10：最终源码的普通功能冻结

源冻结HEAD为 `b3ba65da6acd0d518a5cd535f467e40492e02dd7`，新ISO-FULL RUN `202610111921564c510d` 使用157张原安装表及sentinel、私有DML账号/Redis/内部网络及新卷，无真实数据、共享服务或宿主端口。bootstrap原生Bash exit0；静态先确认仅下面四个正常脚本，不调用九个已停用套件或用户跳过场景。共同Redis工厂改变后，本次各运行一次，没有重新构建或重复BROWSER操作。

| 实际命令（共同前缀：`AUDIT_PHASE=after AUDIT_MILESTONE=MS0b bash help/audit-regressions/isolated/run.sh 202610111921564c510d`） | 原生Bash退出 | 原始结果 |
| --- | ---: | --- |
| `help/audit-regressions/isolated/ms0b-out-functional.php` | 0 | 6PASS，正常HTTP/MCP及单次刷新后新token读取 |
| `help/audit-regressions/isolated/ms0b-account-functional.php` | 0 | 15PASS/1NOT RUN，真实CLI-SERVICE正常账号变更/创建；file_pwd缺列项不执行 |
| `help/audit-regressions/isolated/ms0b-epoch-functional.php` | 0 | 3PASS，真实think命令及自有旧命名空间正常清理，非恢复演练 |
| `help/audit-regressions/isolated/ms0b-login-download-functional.php` | 0 | 6PASS，合法登录/验证码展示/两真实producer及首次header文件消费；准备、清理、记录均未失败 |

合计INTEGRATION层30PASS/1NOT RUN；层级标签沿原始脚本，CLI、HTTP和MCP的实际入口在各case另列，不能当作31项完整安全验收。账号及epoch检查的初始DB/缓存期限恢复、Task8原快照恢复均由各自cleanup收据确认。out脚本只清理本次cache/session键并正常停止HTTP服务，没有独立全状态恢复收据；bootstrap合成账号的合法登录统计保留在这次可丢弃RUN直到teardown，不能声称它们已恢复初始值。全部原case/evidence、四份原生执行收据及完整源码/脚本SHA见 `task10-normal-freeze-summary.json`。

31条原始JSONL完整字节追加至1222条，原1191条/951502字节前缀SHA `7a15b0a9f6df8a94973b08ff6c2497a93ff4d4097e48861cf84a61117c4c4e1f` 保持，新full SHA `12441374368050d31e056c612dbdebf87bf9fcb17aa2d7e917b0a2e4ffa7ae37`；既有FAIL和生成摘要标记均未改。manifest精确teardown原生Bash exit0，3容器/1网络/10卷复查均0，install.lock不存在，仓库外证据保留。源冻结相对 `3e7c966ecfa084ff4f219b03c61ed0efbac4caac` 的73路径已逐项核对计划/必要调用点；本次仅新增Task10记录，无额外业务改动。

普通开发交付已形成，完整MS0b安全验收、人工签收及真实集成/WORKER/生产回执仍未取得；用户取消的验证继续NOT RUN，不创建acceptance.md或SECURITY_BASE_SHA。按用户持续自主L2委托继续MS1普通开发，该委托不构成上述签收，也不授权推送、部署、真实库迁移或外部通知。
