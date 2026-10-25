# Env9 商品视频完整正常验证源码后继（Source-only）

## 本次需求、实证与设计（先于源码）

本次仍绑定实际 clean Git `941fdc7fe81a5d5b44dfce29cf5f87c8411c0a58`，parent `40a5f3c222c49dc3dfbe19d8b37177d3b7a127f3`。Root 已报告原 Env8 Run `20261025071058084006` 的 prepare 真实 native1：95 个原生命令的最后 seed exit2，公开 failure stage=`adapter_plan`，原生 SQL INSERT 尚未开始。旧 r8/失败 Run/原执行双流保持；本目录只修 Source，旧失败不转为通过。

已从实际 r8 Source 核定：`media-environment/media-seed-adapter.php` 的 SQL pin、DDL 去除后 REST pin、完整202语句计划及55表目标仍绑定原安装 SQL `7af188f42c622db523d8000c0cf50f95fa48df2fe41123969ca2dbb65ffb5eb2`；新941 SQL 已合法增加两中性归档目录9/10，真实 SQL pin 为 `1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828`。这是 Source 计划未随已采用的新安装 seed 更新。原135附件、原8目录及其余安装数据保持；新增名称不是恢复历史名称，不对旧实例补数据。

必要生产者/消费者同步另有：`media-environment/lib/harness.php:392` 的兼容192来源门也固定旧整份SQL pin，必须更新同一真实941 pin；该语句本身、ordinal192/table/单行SHA/唯一零日期与显式 error_code 都不得改变。其447行的最终55表总数须依实际新增两行由48249精确更新48251；保 count55 与完整 mode 相等，不降低门限。独立 Source reviewer 已在原16成员限定集合核到这些位置，不扫描私密目录。

有限算法依据直接来自原 harness:333–371：先移除所有 CR，再以原157 CREATE 块的同一锚定规则拆出完整 REST，所有非DDL正文与顺序保持。对 REST 中原202个 INSERT，用引号/反斜杠转义/括号深度感知的有限扫描取得原语句正文、REST绝对byte offset、bytes、SHA256、VALUES顶层tuple数量与闭括号byte端点；原53个 system_route 的 error_code 和1个 system_timer 的 customCode 只在原列尾/各tuple尾添加显式空TEXT。不得按正则逗号数猜行、删数据、补默认、忽略SQL错误、改DDL或放宽模式。

在产生新计划前，必须以 Git 中原40a5 SQL 完整重建旧 REST 与原全部202行计划，逐字段及JSON原字节和旧 adapter 相等，作为有限算法资格。随后对当前真实941 SQL独立重算全部202计划和55表计数、精确定位两新目录导致的差量，并对所有54个适配语句和完整 REST 作普通插入/逆删除字节相等核验。202 INSERT/55表/157 CREATE 不变；route 53语句1153行、timer 1语句11行不变；仅目录表8→10、全部数据48249→48251。兼容192及六旗/五旗/恢复、完整失败收据和原业务 oracle 不变。

只从 sealed r8 精确取必要16个 Source；不复制旧 PINS、source-input、tar/Tree、Run、日志或结果。修改范围限本 README、INTERFACE、adapter 及 harness 上述严格来源/计数；source-binding在新Git导出实际生成前不带旧完整tar/Tree资格。Host全部职责、6角色/18声明卷/4 CLI×4步骤/真实Timer/双根600秒/完整HTTP及普通TERM-only close/六单label zero 保持原样。current941-media-normal-http-source-r1 原sealed9件复用，不复制或重签HTTP。旧r8与当前工作树不改。

任务及验收：

- [x] 验真实941 HEAD/parent/clean；完成旧SQL→旧202计划/REST逐字节复现。
- [x] 重算新941完整202计划/REST/55表，核全部tuple/列端点、54TEXT适配与完整逆还原；仅合法目录行差量。
- [x] 保存最小 adapter/harness/公开接口Source后继，逆变换核其余原字节，保持全部原职责。
- [x] 只运行原本地Git Source构建器生成全新完整941 tar/Tree，全成员body/Gitblob/namespace/目录/全tar读回后封实际PINS；自身另1件。
- [ ] Root 非作者独审与真实 PHP纯语法/纯计划资格；本作者不运行PHP/任何候选/SQL/HTTP/Docker/Timer/目标。
- [ ] Root 在原失败实例实际普通收尾和独立zero后，再以新Run作fresh正常安装/业务/普通closezero；本目录不创建或冒签任何目标结果。

当下只写设计；所有未来目标资源ID/PID/运行结果均UNBOUND。停止的安全/负向/故障/并发/恢复样本仍NOT RUN。不提交/推送/部署，不写canonical。下附原r8 README是保留的历史时点，不能覆盖本节新计划与待验状态。

## 原 Env8 README 原字节历史

# Env8 商品视频完整正常验证源码后继（Source-only）

实际绑定 `941fdc7fe81a5d5b44dfce29cf5f87c8411c0a58`，parent `40a5f3c222c49dc3dfbe19d8b37177d3b7a127f3`；工作树 `H:/天俪商城/worktrees/media-normal-env7` / `feature/media-normal-env7-20261025` 的真实HEAD/parent/clean已核。941只修改新安装SQL与原record：为原135附件中pid9/10共61个引用补两个中性type0归档目录，原135×13列和原8目录保持；不是恢复历史名称、存量数据迁移或在旧失败Run补数据。原Run20261025060617546d5d保留M86准备失败，其普通close和独立zero已由Root实际完成，不能变成业务通过。

本目录只从sealed r7取必要16个Source，不复制原PINS、source-input、tar/Tree、Run、日志或执行结果；r7及原HTTP9保持不变。唯一Host逻辑修正为：foreign网络(name,NetworkID)改JSON原生list pair，完整值/完整raw mount多重集保持，修复原Python tuple与JSON load list不等导致无mutation误拒；普通收尾补MySQL/Redis必须实际exited/PID0/ExitCode0，保原App/Timer0、失败bootstrap退出与TERM-only/nonforce/only-own/foreign和6单label独立zero职责，不patch旧包/旧Run或运行时函数。当前仅Source，没有Docker/App/Timer/HTTP/GC、准入或新Run；Source不代表T4/整项目验收。

## 保留的原完整职责

复用Env6当前正常职责：原157 CREATE/202 INSERT adapter与所有原模式/兼容192/失败收据、vendor/SDK/SourceKeeper、原Source完整复制与174安装素材、原12正常素材、MS1/MS2/菜单17与真实账号、原HTTP所有媒体/商品/SKU/native14/审计/提交观察以及普通资源收尾/独立六query zero保持。没有复制历史before/diff/RESULT或巨型旧源码包。本目录现仅当前必要Source；完整Git tar/Tree/PINS未创建。

新增必要职责：

- 第6个明确角色audit-media-timer，固定既有image，原`php think timer start`前台Workerman入口。没有Swoole要求，也没有GC-only替代、daemon、重启或新调度。所有实际open SystemCrontab与原Task6保持；依次补原MS4→payment→fulfillment→aftersale各check/apply-structure/check/repeat，原13/2/2/20步骤及原零写断言；fulfillment旧24列检查只在aftersale20前。
- 仅新专用normal库原liveRoomStatus(id5)显式is_open关闭，避免其无条件真实微信调用。原服务还改update_time，故用Root已允许的单行受限DML，先核完整原19字段/SQL来源，事务内核其它完整行及后态，再独立连接核提交。完整前后行只在bootstrap0600私有收据，公开安全hash摘要。原GC注册+重复验证，原开关/生产SQL/其他任务不改。默认11任务/真实微信/生产MS6仍不在验收范围。
- 真实全部open marks、原配置行/JSON与实际缓存一致、原SELF/owner-ready、最终Task7五表、金融/通知/直播/海报零候选与真实native扩展前检，HTTP完成后启动Timer前再次核。交易关闭不替代Task6结构；不以空返回或吞异常当正常。
- 原3MiB三片的大MP4独立RO挂载；视频与扫码两独占持久0700根/0600文件，App和Timer共享RW，runner仅RO；独立Timer runtime0700/0600。root/migrator仅bootstrap，App/Timer仍原DML，无actor secrets。
- Timer channel复用原numeric PID/PGID/SID/starttime/完整组收尾，同proc句柄原生退出来源、独立双流和TERM-only，不增加强杀。只有可信Timer开启原log.timer_log Env bool，默认false，App默认不变。原完整日志保私有目录，只公开安全计数/bytes/hash，不导出原文。
- 正常HTTP全链成功后保存两真实完整、未到600秒的session/正式对象/行/Redis基线，然后启动原Timer。观察原Redis60秒gate和实际私有文件自然到期，不发HTTP或新issue、不调GC/tick、不改TTL/时钟。自然收集后计划TERM停止同一原组/native0，再读稳定私有日志，核实际Task6原事件、原SDK Parser给定真实安全窗口的已到时任务成功记录和所有失败分支；空日志/未到时日月任务不冒签。正式上传全树、完整商业行/native14/商品/SKU/审计及非本次有限原Redis生产者全部保持。
- 所有container list显式完整CID，修正Env6短ID与inspect64位ID交叉比较；不扩大删除范围。close仍普通stop/nonforce rm、唯一内网与精确own资源、foreign完整mount/network接续；zero仍六个单label独立查询，不以多label交集隐藏残留。

未来Root串行CLI合同保原phase参数，并新增`--large-fixtures-root <原真实大MP4所在独占RO目录>`。顺序：prepare → prepare-media → start → verify → start-timer → observe-gc → observe → close → zero。每阶段需前一实际0，失败保留，不自动重试/清理；启动失败的未知组保持拒绝关闭ACK，交Root有界诊断/普通owned收尾，不猜PID或强杀。`INTERFACE.json`列精确入口/公开字段；实际resource IDs/数量不是本Source的结果。


## 新源码导出与验证边界

941中的业务/PHP与40a5保持，只有已确认的新安装目录完整性修复及记录。外部12份PHP叶与sealed r7原bytes保持；本后继不重跑PHP，也不把既有纯-l代签新实例扩展/结构/业务。16个当前Source加本次真实build_git_source.py从clean941生成的完整Git archive、全blob/mode/目录核对SOURCE-TREE共18成员；只有实际生成并读取后才封PINS（自身另1件），不用旧tar/Tree/hash，不写未来资格。构建器仍是原字节，运行只做真实native Git/read-only Source导出，禁止Docker/HTTP/目标。

对应HTTP后继另为current941-media-normal-http-source-r1，保原12文件/174安装素材/真实8MB MP4三片及全部HTTP/商品/SKU/native14/审计/提交断言与timeout。新完整Source/Runtime资源资格、四CLI×4、原foreground Timer/双private根/600秒/普通closezero仍需Root在新Run实际验证，不缩成token/纯配置或本地单位检查。实际资源ID/数量/PID及运行结果UNBOUND，50MiB边界、真实六云finalize、浏览器/三端/设备、完整默认11任务/真实微信/全日月/MS6与原其余交付要求仍待。停止的安全/负向/故障/并发/恢复样本NOT RUN；未提交、推送、部署或写canonical。

实际Source构建与读回：原build_git_source.py本次native0，真实941 tree=83604f4771097cdaba709dd454677c6ad2fcfbfc；15764文件/3880目录/342445067正文bytes。作者再完整读tar的全部成员/正文/SHA256/Git blob与目录命名空间，并读全tar核SHA：archive 356618240B/c801010949643aeeb0ef7370dfb6deadae4af5b137ea60ebb01bd9b169c8fc79；SOURCE-TREE 3982301B/a90047cadd2416175f649ecdaac82dbccfb83203b692333fa7e4ee564e1d8c0b。source-binding只填这些已生成实值；随后封18成员PINS，自身另1件。仅Source导出与静态观察，Python Host未执行阶段，PHP/Docker/HTTP/Timer/GC/新Run均NOT RUN，交Root独审及实际准入。


## Env9 实际纯本地 Source 计划资格

作者纯 Python 字节解析和独立非作者纯字节解析分别重现原全部202项计划；未执行 adapter/harness 或任何候选。实际 SQL10237459B/1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828，REST10061042B/7d97145faeffb88e83c0bed53ae012830979af4c9a710559e14daace7c8dbc53，compact plan50006B/6595557723ffd98a6b79445a33f37dca7e932f991ec400c9d5d3e64609104ed5，适配REST10066454B/a777673ddb7a1674c7ed6941d4c102db409690f1e57ef2338ddf2ad846d08642。原旧SQL/REST/compact plan逐字节完整重现；新旧201个INSERT正文保持，唯一第73条保原8tuple并精确追加两个新中性tuple，74–202 offsets各+86；所有适配项column_at/tuple_ends原值保持；DDL仅第92块AUTO_INCREMENT声明9→11。54TEXT适配、完整REST逆还原和55表48251严格资格通过。adapter5处静态替换、harness两处静态替换完整逆变换回r8原件；PHP解析/纯计划执行、真实SQL与目标结果仍由Root另验。


## Env9 实际 Git Source 构建读回

原构建器本次真实native0，当前941 tree=83604f4771097cdaba709dd454677c6ad2fcfbfc；完整15764文件/3880目录/342445067正文bytes。随后作者独立逐成员完整EOF读tar正文，核每成员bytes/SHA256/Gitblob、Git全部tracked namespace/mode和全部目录，另完整读tar核SHA：tar 356618240B/c801010949643aeeb0ef7370dfb6deadae4af5b137ea60ebb01bd9b169c8fc79；SOURCE-TREE 3982301B/a90047cadd2416175f649ecdaac82dbccfb83203b692333fa7e4ee564e1d8c0b。这两件是本新目录实际生成，未复制旧件；同commit归档原字节可重现。source-binding只在生成及完整读回后填写实值，最终与r8相同因为Git941未变化。

原16个Source成员中仅README、INTERFACE、adapter、harness四件改变，其余12件原字节保持；Host没有改动。旧r8全部18成员已按原PINS完整核原值，sealed941 HTTP仍复用。按实际18成员封PINS，自身另1件。作者只做Source解析/Git导出/普通字节校验；PHP、SQL、Docker、HTTP、Timer、GC、Run准入/结果仍NOT RUN，Root须串行另验。
