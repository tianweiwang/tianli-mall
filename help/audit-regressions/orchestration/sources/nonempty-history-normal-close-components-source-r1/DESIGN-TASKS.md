# 原两个writer正常关闭、完整五树与原公开seal接缝

## 当前实施设计：Root已采用的原worker析构分支修订

下文旧“stdout/log恰两条master行且Channel stderr不变”的预设已被真实TcpConnection::__destruct984–999否定，不能作为当前实施合同。旧稿已原字节保留为 `close-two-master-lines-only-unadopted.fragment.php`（15540/bc144b789ab0844da7a5769a244e120f930004be8559dc7da39b155842dcaa77）和 `DESIGN-two-master-lines-only-unadopted.md`（7657/7024d469b495a987f9c7bcb1fd804e53ca03df32a103c152e66e5bb46d57b2ab）。它们未封版/未采用/未执行。当前以下修订优先，保原完整37/四帧/no-extra/正常TERM及五树责任，不改vendor/App/业务协议，不用静默等待假定drain。

真实原worker在graceful后每析构一条TCP连接递减connection_count；首次以m=(int)ceil(N/3)固定mod，只有剩余count%m=0时写原 `worker[实际workerPID] remains count connection(s)`，0时再正常stopAll。N>=1的完整progress计数在执行前独立算为intdiv(N-1,m)*m，然后每次减m直到0，最多3条；N=0无progress。不能假设N<=38，也不遍历N或从AFTER任意日志选N。两条原master日志仍各一次且stopping先于stopped，master stopped在worker正常退出后，所以是两份输出的末条。worker progress在各自文件中严格递减原序；master stopping可以与其交错。Worker::log先stdout后另行LOCK_EX append log，故不能要求两文件跨进程总序相同。

Root已明确授权测试Channel有限生命周期修订：设置并严格readback原已pin compat Select eventLoopClass（protected getEventLoopName1236首分支直接返回）；在Composer之后/runAll之前实际核pcntl_async_signals()===false。已选Select只有loop268的同步dispatch，Tcp/Timer/Server无dispatch/ticks/async setter，因此TERM处理在事件循环边界，不会在Tcp析构减count后异步打断而错记N。不反射调用protected方法，不用compiled-extension猜实际loop。

原onWorkerStop初值null处绑定本Source固定单次scalar-only callback，只读原ConnectionInterface::$statistics.connection_count、实际master/worker身份、graceful状态、UTC/time，并向**已有私stderr**严格追加一条 `NHE_CHANNEL_NORMAL_CLOSE_META ` 加规范JSON及LF。metadata exact9：schema1/scope=`channel_original_normal_stop_native_before_destruct`/run_id/master_pid/worker_pid/connection_count/graceful_stop=true/utc_second/timezone=UTC。callback显式single-use guard、不读或保留任何Tcp对象引用、不改计数；原Worker::stop先callback，之后才unlisten/原graceful析构，回返后stopping=true使后续TERM/stopAll不会再callback。仍核onMasterStop===null。不新建任何文件/卷/actor，不发布metadata或其bodyhash；已有stderr原完整bytes必须保留，恰追加这一条且原句柄/native9/真实TERM窗口全部核。

外层纯close oracle先用该受冻结Source控制的真实before-effect元数据绑定N、原同worker/master/Run/UTC窗口和单次/规范完整stderr格式，然后独立派生全部progress消息。stdout和log都必须是原完整prefix加**精确相同独立消息集合**，每个PID消息序严格保持、master stopped最后，且每条log UTC前缀在实际Channel TERM窗口内；worker日志不得早于metadata的before-effect utc_second。同一进程时间顺序不倒退，跨进程日志时间与append次序不强行排序。两输出跨文件还保Worker::log“每事件stdout→log→本进程下一stdout”的真实约束：若stdout在master stopping前已有p条worker progress、log中在master stopping前有q条，则q>=max(0,p-1)，避免允许不可能的双文件交错。只在该有限部分序通过后按已知消息/已核native时间重建完整expected bytes/hash，不能把任意AFTER正文当期待。

当前允许改变的已有普通文件因此是channel stdout、channel stderr（仅metadata）、workerman.log；每个都保持原identity/owner/mode/nlink与完整prefix，size严格等独立字节长度、mtime/ctime严格在真实TERM窗口。全namespace仍只删除原pid叶；两私树/旧public/旧root runtime、HTTP两streams及所有其它原runtime文件/目录完全保持。统计叶before/after明确不存在，原native正常收据与只余parent/无listeners门完整保留。公共exact13及native安全视图无需加入metadata字段：所有私streams正文/任何bodyhash继续只RAM，公开仅完整native byte count/complete_verified。

## 原设计来源和保持的其余合同（上面的析构分支修订取代旧两行预设）

本片按原 outer37-finish-and-four-cli-source-design-r2 §6–8 继续原完整37/四业务帧/四CLI/current-reader/seal/close-zero目标。只新增外部Source候选，不改既有冻结件、Main/Val、Run或canonical，不执行PHP、Host、资源、SQL/Redis/HTTP或任何样本。固定pre37候选已封但窄审排在Root当前正常收尾Source之后；不提前代签。本片不放开direct169或Host总guard，直至原完整Source合同均闭合。

必要真实来源已定位：old477 Workerman Worker.php SIGTERM1127–1130明确gracefulStop=true→stopAll1774；仅master1779写固定stopping行，原worker没有onWorkerStop，masterStop也为null；正常监控1638→exitAndClearAll1661先删除原pidFile1670，再log固定has been stopped行1671，exit0。正常worker status0不写异常exit日志。非daemon resetStd1187–1191直接返回，不创建配置中的workerman.stdout。Worker::log2080只safeEcho固定消息LF，再向同logFile append UTC时间/PID前缀的同消息；stdout是父原生常规文件，outputStream2124判定非decorated，无ANSI输出。ChannelServer构造只有onMessage/onClose，无额外stop callback；onClose只改内存订阅，不写文件/DB/Redis。

唯一必要Channel测试入口修订已获Root授权：正常初始化显式date_default_timezone_set('UTC')并严格readback；核sys_get_temp_dir()==='/tmp'，保持vendor/App/系统时间不变。原无App Channel fresh exec没有显式PHP时区，不能用parent已变AsiaShanghai的date()解释原生日志。并在runAll前保原daemon=false/一个原worker，实际核onWorkerStop/onMasterStop为null。来源和9PHP身份须相应真实重算；不改vendor字节。

统计文件由原unique_prefix('/run/nonempty/legacy-history-channel.php')确定为 `/tmp/_run_nonempty_legacy-history-channel.php.status`。固定start命令没有status分支，正常init只设置该路径，stopAll仅当其存在才unlink；在TERM之前和之后明确证明其不存在，避免授权删除未建模外部文件。五个既定完整观察根保持，不把统计叶当成第六私树，也不忽略该已定位条件动作。

关闭前先调用原finish取独立accepted37；独立identity函数仍消费该DB2及原HTTPsummary。再在两个原children仍live时fresh完整nhep_observation和私readback，严格join accepted37的DB/rows/private/public/logs/native，Redis仅按原真实TIME纯自然expiry推进。最后一次私stream readback由原同handles完整读取，与这次全runtime树严格绑定；额外完整读取同inode/stat9的workerman.log原bytes，保留私RAM。不得从关闭AFTER重新选择baseline或绕过第37步。

固定正常顺序严格是：记录HTTP TERM前wall→原nhep_child_close(http)→真实HTTP stopped/group空/原句柄终态→原同subscriber nhep_channel_no_extra完整10s门→nhep_channel_close同原state→记录Channel TERM前wall→原nhep_child_close(channel)→两角色nhep_children_closed完整native inventory→记录Channel结束wall→全库/全Redis/全五树和原streams末态。HTTP close时Channel仍live；no-extra不以read超时异常充当空。始终保原一次正常TERM/无force/无SIGKILL/不重启/不换句柄。

独立五树close oracle只允许原Source明确变化：

- app/fixtures两私树、旧public、旧root runtime完整不变；主runtime的完整namespace只删除 `channel/workerman.pid` 这一原叶，保 `channel` 目录；workerman.stdout始终不存在，所有其它文件/目录及stat9全部不变。
- HTTP stdout/stderr、Channel stderr所有原bytes、hash、stat9不变；Channel stdout严格保原完整prefix，只追加两条原固定消息LF，不能包含额外行或ANSI。
- Workerman log严格保原完整prefix，只追加恰两条原消息；每条前缀为实际UTC规范Y-m-d H:i:s、原Channel master PID。两时间必须各自在实际Channel TERM前/后wall窗口内、原顺序不倒退；只在该完整格式/独立消息/PID/时间约束都通过后绑定时间前缀，独立构造完整expected bytes/hash。不能直接取AFTER完整日志当expected。
- 原pidFile BEFORE完整bytes必须等同原master PID十进制无LF，原inode/权限/原完整tree资格保留；AFTER必须缺失且不dangling link。
- 仅上面两append文件允许size精确等独立expected字节长度、原dev/ino/mode/nlink/uid/gid不变，mtime/ctime不倒退且在Channel真实wall窗口内。仅原channel目录允许unlink引起的kernel size/mtime/ctime变化：原身份/owner/mode/nlink全部相同，size不增加，mtime/ctime按原窗口严格验证；全部namespace已独立证明只少pidfile。不能将其余AFTER stat或树复制为期待。

关闭后的原stdout/stderr handles已经由nhep_child_streams真实fstat/read/close；后续读取仅为同原命名文件私readback，须join原closed receipt complete bytes/hash与原created identity及全树stat9。不再调用只允许live的nhep_child_stream_capture，不假造已关闭fd，也不把proc_close=-1硬填0。完整原两份close10键收据、no-extra receipt和原native group/parent身份继续单独保真；最终inventory只能原producer父PID、两个listener和原组皆空。

writer全停后full164 DB2严格等accepted37的独立全DB；Redis严格等accepted37加emptychanges/实际TIME纯自然expiry；完整五树严格等上述独立close期待。然后只调用原纯nhep_migration_snapshot_expected建原full m4m shape，保原四CLI及八Source不变。原cli和后继读取只能经Host同CID真实归档/普通退役后执行，本片不新跑check或迁移。

公开seal具体Source schema按原已允许最小字段形成单一提案并与Root/CU共享：同schema/run/source/old477/freeze/producer actual CID及真实控制Source pins；原full37完整HTTP安全summary、四业务帧事实和独立身份crosscheck；原close/no-extra真实安全收据、完整m4m expected及其实际Redis TIME；不公开私Actor/Token/rows/raw Redis/私stream正文或正文摘要。尚未绑定的source/consumer/current-reader等必要输入按具名项明确拒绝，不能伪造futurehash或用status聚合替代原事实。最终字段落源码前再次发Root/CU对齐；不因此停止当前正常close实际算法。

Root现已明确公开native边界：原10键close receipt及其完整私流/摘要只在RAM保留逐项核，不改原函数返回。另建安全view：每stream仅native bytes和complete_verified=true，任何bodyhash都不公开；group/first_terminal/waits只取实际native固定allowlist，实际proc_close/exitcode（含-1/UNKNOWN）保真不填0；公开记private_native_receipt_complete_verified=true，不能把view冒充原receipt。具体字段设计见同包PUBLIC-SEAL-CONTRACT.md，供Root/CU单一采用，不生成未来Run实例。

后续实际设计状态：Root已完整阅读并采用PUBLIC-SEAL-CONTRACT.md的exact13与native安全view，明确为父生成器/Host唯一Source合同。此状态不是Run/运行ready/用户验收；仍无未来HEAD/CID/hash，未绑定总准入继续拒绝。

后续固定父算法实际调用顺序沿原§8：严格normal argv/freeze→原qualification/parent receipt→固定pre37→原Channel start/subscriber→原HTTP start→原四factory完整37→finish/独立identity→上述实际normal close/纯baseline桥→create-only安全结果。catch/finally保首业务错误和独立cleanup事实，不发成功seal。child启动完整files/logs/native oracle、完整public consumer/currentreader和最终准入未闭均明确保留，不得只移除guard跑前缀。
