# 测试环境、测试分层与证据格式

适用于 MS0a 及之后所有里程碑。测试脚本统一放在 `help/audit-regressions/`（决策 D6）；MS0a 起新增的隔离测试放在 `help/audit-regressions/isolated/`。

## 1. 测试分层

每条结果都要标明层级。不同层级的通过数不能相加，也不能用低层级的通过抵消高层级的失败。

| 层级 | 定义 | 能证明什么 | 不能证明什么 |
|---|---|---|---|
| `UNIT-STUB` | 加载真实类，依赖用内存替身（如现有 `security.php`） | 分支逻辑 | 真实 Redis、数据库、HTTP、框架装配 |
| `INTEGRATION` | 真实应用通过 HTTP 访问，连接隔离的 MySQL 与 Redis | 接口行为与数据库/缓存副作用 | 常驻进程复用、浏览器行为 |
| `WORKER` | 在实际使用的常驻进程（队列消费者、Workerman）中执行 | 上下文与对象跨任务不残留 | 其他进程类型 |
| `BROWSER` | 真实前端构建产物 + 真实 API + 测试库 | 页面与接口联动 | 真机、生产域名 |
| `EXTERNAL` | 真实支付机构、真实第三方调用方 | 外部契约 | —— 需要单独授权 |

## 2. 隔离等级

| 等级 | 适用 | 要求 |
|---|---|---|
| `ISO-MIN` | MS0a | 专用 MySQL、Redis 容器（新 compose project、新卷、随机凭据）；端口只绑定 `127.0.0.1` 的临时端口；应用用宿主机 PHP 8.2 CLI 内置服务器从**测试 worktree** 启动；测试账号无全局权限；哨兵校验 |
| `ISO-FULL` | MS0a 的 Windows 执行环境及 MS0b 起 | runner 容器与应用容器都在专用内部网络，不映射宿主端口；执行安全用例时禁止对外出站；常驻进程（队列、Workerman）按实际部署方式启动以支持 `WORKER` 层。MS0a 不由此获得 WORKER 层验收结果 |

两种等级都禁止：连接业务实例或共享实例；读取现有实例的 root 密码；从业务库复制数据；`DROP` 通配库、`FLUSHALL`、清业务卷；写备份检出（`$BACKUP`）的任何文件。

### 2.1 Windows 上的 MS0a 执行适配（2026-10-11 用户批准）

当前 Windows/WSL 无宿主 PHP 8.2，用户明确批准 MS0a 使用 ISO-FULL，并更新本文件、04 计划和启动脚本。业务契约、测试子场景和验收标准不变。

- 使用本机已有 `tianli/crmeb-php:acceptance`（运行时核对 PHP 8.2）；MySQL、Redis 镜像仍为 §3.1 的版本。所有服务 `pull_policy: never`，不依赖下载或业务容器。
- `audit-app`、`audit-runner`、`audit-bootstrap`、`audit-mysql`、`audit-redis` 仅连接本次 compose project 的 `internal: true` 网络，不映射端口，不挂载 Docker socket。源码来自当前测试 worktree；应用和 runner 只读挂载，运行缓存使用独立命名卷。
- 宿主运行目录只保存 manifest、控制记录和脱敏证据。随机密码、测试签名密钥和合成账号凭据保存在本次 project 的专用 Docker 卷中，目录/文件权限 0700/0600；root/migrator 卷只供引导使用，应用和 runner 只挂载受限账号所需的配置，runner 另挂合成账号凭据卷。不得在环境变量、命令行或日志中暴露密码。
- `bootstrap.sh` 在 Git Bash 或原生 Bash 执行，先检查所有镜像和版本、创建独立资源、导入安装种子，再记录容器 ID、卷名、内部网络与地址。`run.sh <RUN_ID> <脚本> [参数...]` 负责启动一次性 runner 和必要的服务故障场景；`teardown.sh` 按 manifest 与实际标签/ID 双重核对后仅清理本次资源。
- ISO-FULL 的启动保护沿用 §3.4 的 RUN_ID、双哨兵、库名、受限权限检查。主机固定为 `audit-mysql` / `audit-redis`、端口固定为 3306/6379，解析后的地址必须与 manifest 中经过 Docker 核验的本次容器地址一致。禁止接受调用方自选主机或业务实例地址。
- `audit_app_start()` / `audit_app_stop()` 通过本次运行目录中的受限控制记录请求专用应用容器启停 PHP 内置服务器；配置只从受限凭据卷注入 `PHP_*` 环境变量（ThinkPHP `Env::getEnv()` 的读取规则）。不创建 `crmeb/.env`。`install.lock` 仍按 §3.5 哨兵规则处理。
- 初始建库、导入种子、写入双哨兵属于引导阶段，只能在已核对的本次专用实例内完成；之后任何测试写入和应用启动都必须经过 guard。测试失败保留证据，不自动清理。
- 2026-10-11 用户批准安装后的文件校验初始化：安装种子没有当前版本的 `write_md5` 标记，真实 `login/info` 首次请求会通过 `SystemFileServices::writeMd5()` 重建 `eb_system_file_md5`，其 TRUNCATE 需要 DROP 权限。bootstrap 在双哨兵和受限账号 guard 已通过后，只用本次专用 `audit_migrator` 初始化真实 ThinkPHP，调用该现有方法实际生成当前源码的文件校验数据；成功后以现有 `CacheServices::setDbCache()` 写入当前版本标记，并检查数据非空。此步骤只在引导容器内执行，migrator 配置不传给应用或 runner；应用仍使用仅有 DML 权限的 `audit_app`。不新增表或权限种子，不伪造标记跳过校验生成，Z07 的业务码 200 断言保持不变。

- 2026-10-11 MS0b HTTP接线前置（依用户委托自主推进，05 Task4记录）：每个新RUN在私有app-secrets卷生成独立auth.session_epoch/stamp_key（与签名key不同），通过PHP_AUTH_*供给专用应用，不在宿主控制文件、命令行或输出传真实值。runner另只读挂载本次app-runtime卷，核对真实HTTP文件缓存/业务日志；访问日志正常增长单独做敏感值检查。冷错误场景仅TCP监听就绪，不调用login/info预热。supervisor配置override仅白名单中的缺AUTH值/short、debug true/false、同一audit-mysql的闭合端口1或3306、本次RUN安全cache prefix；guard连接仍固定真实3306/6379并核双哨兵/DML权限，不改变账号/库/网络主机。原MS0a配置override保留；安装writeMd5初始化仍只bootstrap专用migrator真实执行。
- 2026-10-11 MS0b Task5并发前置：仅本RUN Linux PHP服务允许SERVER_WORKERS为空（不设置PHP_CLI_SERVER_WORKERS）或2；不给宿主开放端口、不共享PID namespace/挂Docker socket或外网。supervisor在实际setsid后登记PID/PGID/SID/starttime，并验证独立组；有限observe只读本组实际成员及到audit-redis:6379的socket数字信息，屏障中不做Redis写入/EVAL观察。stop须验证整个登记组已退出/回收且8000无监听，wrapper先退出不能漏掉fork worker。测试先记录旧实现基线，实际运行PID/HTTP处理重叠/停止后状态分别验证；不按env值或ACK推断。CLIENT PAUSE WRITE固定短时仅对专用Redis，真实CLIENT LIST flags/command与app进程socket关联证明处理重叠；不加生产sleep/ignore_user_abort/test hook，不打印私有环境或请求内容。
- 同轮真实Redis暂停诊断定位框架启动先行连接：ModelService经Db::__make在请求/应用handler之前创建timeout=0的旧缓存客户端。修复使用根服务绑定的兼容Db及标准Cache manager延迟委托，保留default/named backend与共享client选项；测试必须从App::initialize之前暂停，不能先完成initialize再以工厂约1秒503替代HTTP启动覆盖。新bootstrap回归与真实HTTP仍在原专用网络/卷、原DML权限中执行，不修改vendor、镜像或网络边界。

- 2026-10-12 MS0b Task8普通下载检查：依据05计划同一调用点设计，app/runner共用本RUN新建的`backup-output`命名卷，挂载真实生产者固定的`/workspace/crmeb/backup`写入路径；不复制宿主或业务备份。源码/vendor继续只读，内部网络/无宿主端口/DML账号和全部启动保护保持。卷沿实际标签进入manifest，并由既有teardown精确清理；文件生产、内容和最终清理分别记录。此项只用于正常CRUD/备份下载，停止的复现、负向和故障验证不恢复。

MS4取消与MS5 Task2普通内部测试适配：仅新建的专用普通订单角色声明公开非敏感 APP_ENV=testing、PHP_MARKETPLACE_PAYMENT_TEST_ENABLED=true，运行时核真实getenv和Think配置strict bool；不通过helper临时设置Env/开关。该角色仍为原三角色协议中的只读源码/DML App/唯一内部网络/六个新卷/无宿主端口、无Docker socket；不更改原MS0a/MS0b角色或已关闭Run。新准备版本在实际容器created后用原生inspect只筛出这两个精确公开ENV值，保存同Run/source/CID/freeze绑定收据；该环境核对不导出完整ENV、不读取MySQL/root或私有卷值。入口同时校验原生收据及真实进程值，完整源码闭集包含新普通helper。TestDriver仅同App进程内pending→closed且不写paid/财务表；此结果不是HTTP/WORKER、跨进程attempt、真实渠道/退款或daemon验收。旧环境、正常关闭、独立六项零残留及已停止样本NOT RUN要求不变。

MS4商家保存订单普通读取的下一专用版本：因真实AuthSessionStore要求默认Redis，该角色另声明公开PHP_CACHE_DRIVER=redis，从同一App启动起使用本Run专用Redis及既有private App配置的真实Redis密码、独立signing/stamp key、session epoch和issuer；不临时切facade、不假ctx或自签JWT。保留原测试支付两个环境值与收据，新增只筛PHP_CACHE_DRIVER=redis的原生created容器观察及独立merchant环境收据，交叉绑定同Run/source/CID/freeze及source binding SHA，不导出完整ENV或私有配置。原普通下单/会员/读取/持久收尾/取消全部断言在该Redis配置下新Run复跑，旧File结果仅保留原范围。正式runner仍只用audit_app DML和原private App卷，无migrator挂载；真实role/employee/setup仅本Run原生夹具，经真实setupPassword/login/authenticate后测普通本店读取。配置、凭据、capability、JWT不进入公开证据或仓库。无新业务表，其余原三角色/六卷/内部网络/无端口/完整source绑定与精确正常关闭保持；HTTP/DOM另按既有隔离profile验证，内部服务不代替其验收，已停止样本不恢复。

MS4商家订单HTTP/DOM后继r3合同（2026-10-15，依据r2真实观察修正，待新Run验证）：先在全新Run复跑上述v5完整普通链；该阶段仍仅原订单CLI/MySQL/Redis三角色和唯一backend内部网络，CLI源码与三角色断言保持原值。backend沿原`<project>_isolation`名称，新增frontend为`<project>_frontend`。其native0且完整普通收据核过后，才创建并登记AppHTTP、NginxUI及本Run专用frontend bridge。最终为5个固定容器身份、2个网络、10个新专用卷：原6卷加http-runtime/http-control/http-browser-secrets/http-public。App、MySQL、Redis、CLI及有限bootstrap仍只连接原internal:true backend；只有UI同时连接backend和frontend。frontend为internal:false、enable_ipv6:false的独立bridge，配置com.docker.network.bridge.enable_ip_masquerade=false；该配置不等于已证明UI无外连。frontend运行端点仅允许原UI，不能把其他角色接入它；manifest的network/network_internal继续精确表示backend，frontend另行登记完整原生身份，不用true概括两网。此分工沿[Docker frontend bridge与backend internal模式](https://docs.docker.com/engine/network/#connecting-to-multiple-networks)，旧r2和已关闭Run不临时补网。

两个网络均须核原生ID、Name、Driver、Scope、Internal、EnableIPv6、Options、Labels及端点；原v5阶段仍只允许原内网，HTTP阶段专用collector才核精确双网，global consumer检查覆盖两网和原十卷。新增卷可在prepare创建并绑定原生名称/创建时间，不能省略旧六卷身份核对。UI保持user101:101、只读public与配置、cap_drop ALL及no-new-privileges；只发布127.0.0.1:18974到8080。须核实际NetworkSettings.Ports、Windows回环TCP成功及固定同源PNG的真实HTTP200、长度和SHA，HostConfig中的声明不能代替实际发布。App仅内部8000，无宿主端口；源码与配置只读，App无migrator、Docker socket或浏览器秘密卷。HTTP App服务显式stop_signal:SIGTERM，另以原生观察核实际Config.StopSignal与同Run/source/CID/freeze绑定，避免继承PHP-FPM镜像的SIGQUIT。主树保持无.env/install.lock；有限bootstrap从冻结当前public闭集创建独立快照，仅增加本Run安装标记、同源合成图片与当前merchant构建，之后App/UI只读挂载。bootstrap用audit_migrator真实writeMd5/checkFile/版本缓存，再以独立DML子进程创建HTTP演员和20笔真实新单，不让HTTP App继承migrator连接。新profile、builder、inverse、关闭源与证据使用新路径，登记/启动/关闭沿专用严格矩阵，不放宽原harness分支。

HTTP演员是本Run原生角色/员工/setup，经真实设密后仅将既有账号密码及必要原生ID交付至0700目录内create-only 0600私有文件；不包含JWT、setup capability或密码hash。桥仅从本Run已正常退出且source-bound的bootstrap原CID通过原生Docker文件管道读取到内存，在按原ID移除该helper前完成；此通道必须实际验证，不假设已退出容器可exec，不给App挂载秘密卷。浏览器重新正常填写登录，响应体和完整秘密摘要仅入私有控制通道，公开证据不含凭据，认证响应摘要只留前12位。普通订单/库存SQL基线在20单及引导校验完成后固定，正常登录Redis会话变化允许，HTTP读前后SQL事实必须一致。Cua只操作本任务owned tab；按320/390/1440实际viewport核DOM和截图，截图前排除密码输入值、token/capability URL。先真实退出/关闭owned tab，再通过原生control请求正常停止HTTP进程组并核SID/PGID/starttime、成员消失与8000无监听；不使用超时SIGKILL、重启或force。五容器/两个原网络/十卷须按原身份正常终态精确移除；两网均须分别核身份与空端点，独立六条双标签查询核零。HTTP/DOM、正常关闭与源检查分别记证据，不改已关闭v5或r2 Run，不恢复已停止样本。

§3 中的 127.0.0.1、宿主 PHP 与凭据文件位置适用于 ISO-MIN；本节是 ISO-FULL 的等效约束，不取消其他启动保护。新增 `run.sh` 属于本次批准的启动脚本范围。

HTTP普通控制补充合同（source准备，完整HTTP仍待运行）：在原五个private ingress模式之外增加只读`supervisor`，仅核同Run/source/CID/freeze/epoch和当前active-supervisor原nonce，返回实际对应响应或明确not_ready/null；没有active请求时不能制造一个not_started成功值或为取证明运行未授权样本。native0只证明有限读取完成，不能替代真实HTTP成员/监听ready。正常stop只终止已登记HTTP子进程组，核成员消失/8000无监听并写真实响应后，原supervisor以同PID空闲且禁止再start；桥读回后再由原ID一次正常docker stop time=-1，HTTP App实际Config.StopSignal须为SIGTERM，核容器exit0/PID0，不使用超时升级。原r2继承SIGQUIT的实际等待及单次显式TERM收尾另记09，不能据其修复过的正常退出冒充新配置已验收。runtime窗口固定实际time()起3600秒，过期不重启或改时钟。CUA原始截图为JPEG，保留原字节为.jpg并核image/jpeg、SHA及实际像素；CSS viewport/DPR另核，不转码或强制二者同尺寸。商品合成PNG合同保持。最终浏览器覆盖计划、私有capture/DOM核对与公开终态收据未齐前，新profile运行及关闭总门禁保持关闭；准备源码不记HTTP/DOM通过。

HTTP最终普通页面核对合同（编码前）：新增只读`plan` ingress，读取同HTTP进程在真实oracle/source门禁后一次生成的固定34步骤/25主capture/9visual私有计划；每步nonce由真实random_bytes产生，write command只接纳计划中下一步原完整消息且前步实际ACK存在，不开放任意命令或凭据参数。capture.query的空map在线上编码为JSON对象{}，不放宽浏览器的精确查询比较。原6个控制子目录保持，9份人工图像复核写入已有screenshots目录的独立create-only review文件，严格绑定原nonce/scene/实际JPEG摘要。audit必须逐项核完整计划、真实响应与保存oracle、结构化DOM/原字节全页JPEG/人工实看记录、owned tab关闭/viewport reset、23表事实和完整来源，才经宿主私有桥发布有限公开结果；App的Run挂载继续只读。新的只读plan、review种类和完整终态实现后仍须来源审查，不能据计划生成签收；手机表格实际横向可达的工具接口尚在核对，未实际操作前不记该项通过。

空表横向滚动补充验收合同（2026-10-16，实施前）：沿上述原34步骤/25capture/9visual/23表/3600秒合同，原非空表键盘及末列断言、私有command/ACK/PHP严格字段集合保持。新增浏览器模块独立导出的普通空表观察，在原第31步空商家列表完成后、第32步前调用；不增加或改写原计划command/ACK，不把补充图片计入原9图。在320/390实际有溢出的视口，使用真实具名body-wrapper的ArrowRight/ArrowLeft，核首次非零、实际最右及恢复左侧；逐处读取未过滤的文字/空块/滚动区几何、实际scrollLeft、宽度、焦点、tabindex/role/aria-label、模板根class、三个选择器匹配及computed sticky/left，保持原空态几何断言。每种手机宽度另存首次非零和最右的两张原始JPEG并实际人工复核，共4图；1440先实测，无溢出仅记measured-not-required，不能制造滚动。所有补充结果和图片在原Run目录外单独记账，绑定实际Run/source/CID/freeze、原窗口、第31步上下文及模块来源摘要，完整nonce不进入公开结果。调用结束实际回左并复位viewport，才继续原流程。该修复的验收要求是原完整浏览器/23表证明与补充空表观察及4图人工复核同时通过，再正常关闭和独立核零；原required=false或9张静止图不能单独证明修复完成。仅运行正常空数据/键盘操作，用户停止的其他样本保持NOT RUN。

本机浏览器运行限制（2026-10-16）：后继普通复验使用Edge或Chrome中本任务实际创建并核归属的标签，原owned-tab关闭证明由外部浏览器真实完成。内置浏览器IAB仅可复用或导航，不自动执行tab.close/browser.close、CDP Target.closeTarget/Browser.close、关闭快捷键或等价操作，不主动复现客户端退出；当前拦截钩子尚未获信任，不能依赖其保护。若后续用户明确指定IAB，保留其选择并先调整必要收尾记录，不能伪造closed证明。此限制不改变已执行R5的历史事实或业务验收结果。

普通助手终态记录修复合同（2026-10-16，R6关闭并独立核零后实施）：真实助手观察超时只表示仍在运行；Root等待同一CID的原生wait实际结束后，仍沿原observe/finish-helper入口核对。统一终态保存函数只接纳真实inspect的exited、严格整数PID0/restart0/exit_code，保留观察到的真实非0退出值。observe每次见到终态均create-only保存或严格比对；普通remove_finished和HTTP同名路径在原身份、完整mount及正常exit0核对后、任何rm之前调用同一保存函数。已有终态必须字段键集合相同，完整mount对象多重集及重复计数相同，其余字段的JSON类型和值逐项严格相同；不覆盖、不从created/result或哈希补造。原HTTP前置必须有完整auth terminal的断言保持。当前已删除助手的缺失终态继续缺失，本轮只能有限正常关闭，不能重开或冒认HTTP通过。新来源仍须原34/25/9/23、独立空表4图、正常关闭和独立核零共同验收；只运行已授权的普通样本。

R7 实际运行与收尾（2026-10-16）：新 Run `20261016042215b87fcf` 绑定 clean Main `a74f2b27275229dcb089581039dc30020afb4f32`、freeze `600404b61d2c6d276fec10a80db8c42b55aa7da94fceb6b70e7026f1d55f5416`。修复后的 host 实际完成有限 initializer 六子命令、结构迁移四命令和原完整内部普通订单，均 native0；授权助手的真实终态在删除前保存、删除后字节保持，解决新 Run 的终态缺口，未补造 R6 文件。商家准备实际 native0，创建20笔真实分页订单，随后完整 source-ready、UI smoke、原 HTTP 子进程正常启动均成功。

本次原浏览器第1步真实账号登录 HTTP/business200并进入通用商家后台，但 Root 调用 hash callback 时把实际响应转成 Node Buffer，跨执行域后不满足 bridge 的 `instanceof Uint8Array`，自然报 `MS4_BRIDGE_ACTUAL_BYTE_INPUT`。实际同一登录页 JPEG 的原 bytes 与当前域 `Uint8Array.from(bytes)` 均通过原 hash 校验且摘要完全相同，Node Buffer 版本被原类型断言拒绝；后继调用适配使用当前域 Uint8Array 的逐字节副本，不改 bridge/浏览器原断言、不转图像格式。该失败是 Root 适配问题，不是业务接口失败；原 command1/capture0/ACK0/计划截图0，后续原34步/9人工复核、空表四图和最终23表审计未执行。不重放命令、不补 ACK/终审、不把额外诊断 JPEG 当原计划证据。失败收据摘要 `ae4bd8dcf4ca4eea2ee1e53e6116dd8f91348c86c5ca818dffffc0e461cc1969`。

原 bridge 正常请求并确认同一 child 停止：stop_signal15、remaining_members空、listener关闭，实际 native_proc_close/child exitcode均-1、termsig0，原值保留。仅关闭自建 Edge 标签1338870427并复位 viewport、释放专用演员内存，IAB未操作。Root 按已独立源审的只读 observer `a17db3f6d42b768707d29a9ad8a1746e18873a4ff0701e3f56148bac97b6a2e5` 实际核原私有窗口/plan/第一command、1/0/0/0封闭集及仅原监督进程，native0；公开观察摘要 `4fc253aa4d7f2a2e0790c4484ea20be97b9b84d36d8ceafef9420beb76d2dfe7`。后继有限关闭源 `76d9cd8503fc5140853aa8cbe853c5ea61f75a0d906f70f867a379e61dd60df1` 复用原R7完整身份/全消费者和单次正常stop，原四服务与对应原生CLI均0，再非force精确移除原5CID、2空网络和10卷；实际关闭摘要 `29c91c77caa3a4e2cd9fcd75288d76ca303d1155227af5a780e88e164acea008`。Root独立源 `8ad9ffc78fde3be377ebde954aac09a9ab4879b5e466eac90cd0f59a9eec636a` 另做六条原生project/run标签查询全0，并核1563份原公开文件字节及Main原HEAD/clean保持；核零摘要 `db458077d838835837fb4b72d2c08d4c4dc0028eb3fea0aaa2c190467d06ef0f`。本Run关闭，不重开，完整浏览器/Task6/MS4仍INCOMPLETE。之后才解除Main源码冻结并实施09的顾客正常HTTP接线合同。

顾客enabled普通profile实施设计（2026-10-16，R7已关闭并独立核零）：在仓库外 `artifacts/ms4-customer-http-preparation/candidate-r1/` 派生 `compose.yml`、`ms4-host.py`、`ms4-initialize.php` 及同范围normal-close/独立zero；复用原六卷/一内部网络的有限157表安装、MS1/MS2六子命令和MS4 check/apply/check/apply，不执行旧closed订单或商家20单前置。固定六新卷为mysql-data、redis-data、bootstrap-secrets、redis-secrets、app-secrets、fixture-secrets；独立bootstrap沿原m6i_migrator的真实migrator/writeMd5/当前版本缓存段，结束后应用始终audit_app精确DML。prepare角色在真实终态保存后正常移除并交接私有fixture卷，正式只customer/MySQL/Redis三角色，当前两新增PHP具体合同见09。sourcebinding/created/environment/result路径与scope以外部interface-v1设计为同一依据，必须记录实际native事实和真实source字节，不用设计文件冒充收据。

customer verify容器有本内网alias audit-app、无宿主端口，显式stop_signal=SIGTERM并核原生Config.StopSignal；自身启动真实setsid HTTP child并使用原audit_http，无独立App容器/Nginx/商家dist/新控制协议或nonce窗口。public/runtime使用自身tmpfs，仅从只读/source复制真实index.php/router.php和本Run install.lock；Main不写env/lock/运行目录。真实两开关、自营ID与相同cache/auth/Redis在配置加载前从已绑定容器环境传给child。shared audit_serve/audit_app_stop_group含SIGKILL升级，明确不调用；只提取原m4h_stop的单次TERM/已发送标记及原注册组观察算法。五秒观察仍pending时保留同一process/group/sent继续只观察，不重发TERM、不退出PID1、不升级；TERM尝试与实际发送成功分别保存，收尾观察自身异常也保留原句柄和首个失败继续观察，不因外层catch退出PID1。尚未登记的原child只按其真实SID/PGID继续登记，不猜组发信号。沿原warning handler尊重被@抑制的/proc自然消失，其他实际warning仍失败。shared audit_guard_assert的失败会直接exit，因此有live child的区间仅用同一helper内等价可抛守卫，逐项保留read-only模式、Run/network、真实DB/Redis endpoint与双sentinel、private DML账号及SHOW GRANTS精确检查，不运行DDL拒绝探针；启动前/child关闭后仍核原shared守卫。成员空且监听关闭后保存实际child/proc_close值再正常退出。正常结束后宿主只单次正常stop原MySQL/Redis，原三个CID、空内网与六卷按身份/全消费者非force清理，Root独立六查询核零。自然失败保持真实有限结果，不自动重启、补通过或复活用户停止样本；本profile不替代原商家浏览器和顾客页面验收。

首个顾客prepare实际有限结果（2026-10-16）：clean Main `416009e6ccea254cadc6b7591a110329619402a5`，Run `20261016055340b5d5fb`，freeze `dc02983d1507dbd5d8cce0f5ab57101b11014268b636344170bd266c4169aba0`。原3容器/1内网/6新卷创建后，collector在读取MySQL的Config.StopSignal时原生exit1；Root只读核MySQL/Redis实际无该key，直接Go字段读取各exit1/stderr118B，改用原生index取键真实null且exit0，PHP原信号仍SIGQUIT。尚无actual-run-context、prepare-result或任何已启动角色，私配初始化/App/SQL/HTTP均NOT RUN。只读观察摘要 `da1b6774a4b3e1473451888689ca9a332816a293dfa2e82396f547a67a6ed3a5`；独立源审后Root唯一执行有限关闭源 `3c921b14f910a10272ba4cc34702d740471eccd8598d6ba937b3e700fe4997e5`，严格核3CID从未启动及完整native/mount多重集、原空内网/6卷身份和全消费者，非force精确移除，真实native0，关闭摘要 `9281d8ec6c0a75cca305b178cd705a4fe2be9bc910a04754434b3a5727f9ec32`。Root另执行独立六查询全0，原Main/来源及失败证据保持，核零摘要 `5e8f681f6a090b75de7964b4a93155803ae1510237107476785cffadf3fc4562`。原Run不重开；新candidate-r2只将新增StopSignal的Go表达式改为index取键，缺失保留真实null，verify的真实SIGTERM要求和全部immutable检查保持；关闭/核零仅更新新路径/hash绑定。r1和本次失败均保持，后继新来源须重新freeze/run，不据此宣称顾客下单通过。

第二个顾客 Run 实际结果（2026-10-16）：clean Main d5218e8f4894acd0624ac6b1c373511fc6aaab5c、Run 20261016060119eaec3f、freeze e69991aecc5a6995d88da9e8942a1cf030c0b91dd06a2a1532dd47dddb842b9e。有限初始化六子命令、四次结构迁移、专用文件引导和顾客 fixture prepare 均实际 native0；引导真实生成1219条校验并切回仅 DML 的 audit_app，prepare 仅生成1顾客/1商家/2商品/3SKU，未插购物车或订单。verify 实际 native1/PID0/restart0，停在 business_verify_fixture 的 M4C_PRIVATE_FIXTURE_BOUND_TO_ACTUAL_PREPARE_RESULT，尚未发出首个登录请求。原失败收据摘要 b4a7d8bca5d68da1f1849ddeb69925b255303b3e5bbc2e3a7ac5f2905e471837。Root 对原私有 fixture 只读内存比较，公开诊断 d28b113c55024371a25525ebbf6a206edb595575abbc8216a22d6d27830cb322 证明仅环境键序不同，全部名称、值、类型及其他绑定均相同；没有保存私有内容。HTTP child 已启动，但失败路径没有持久化实际 child 收尾对象，历史 child 正常停止证明保持 NOT_PERSISTED，不补造 TERM/proc_close 结果。

该失败 Run 已正常关闭：原 metadata 授权助手实际 native0、终态归档后按原 CID 移除；固定失败关闭源 e038b0cce3330c2f75ceee4151d94d0799ac07c527678f49aedb70b1a2e56aaa 仅允许原 customer 的真实退出1，原 MySQL/Redis 各单次无超时升级 stop，原生进程与容器均0。精确非 force 移除原3容器/1空内部网络/6卷，关闭收据摘要 460a6f7844643e2d2f9a713cc16500fcd5866a22d1c86871afb2d6867ea892a1；Root 独立源 3e62542dcc868e4079b295a158598c2bbe91f3454eeec88de2ed2234b12c8ab3 实际六条双标签查询 native0、stdout/stderr空，核零摘要 b17cc3f6a07186b013108bf4b4e9dfc8cbfc713ebc1147d40887091bf263a0a6。原失败、准备、来源字节及 Main clean HEAD 保持后才解除冻结；本 Run 不重开，不记顾客 HTTP/DOM 或 MS4 通过。后继比较与失败记录修复合同见09，仍使用新 clean 来源和新 Run。

## 3. ISO-MIN 环境（MS0a 交付）

### 3.1 文件

| 路径 | 职责 |
|---|---|
| `help/audit-regressions/isolated/README.md` | 使用说明：启动、运行、清理、故障处理 |
| `help/audit-regressions/isolated/compose.yml` | 服务 `audit-mysql`（`ccr.ccs.tencentyun.com/crmebky_php/mysql:v8.0.45`，与部署一致）、`audit-redis`（`ccr.ccs.tencentyun.com/crmebky_php/redis:v8.6.1`）；命名卷；标签 `tianli.audit=1`；端口 `127.0.0.1::3306`、`127.0.0.1::6379` |
| `help/audit-regressions/isolated/bootstrap.sh` | 生成 `RUN_ID`、运行目录、随机凭据；启动 compose；建库建账号；导入夹具；写哨兵；写 manifest |
| `help/audit-regressions/isolated/teardown.sh` | 只按 manifest 停止并删除本次 compose project 及其卷 |
| `help/audit-regressions/isolated/lib/guard.php` | 启动保护（§3.4） |
| `help/audit-regressions/isolated/lib/harness.php` | 用例记录、HTTP 客户端、数据库与 Redis 快照、应用启停 |
| `help/audit-regressions/isolated/fixtures/ms0a.php` | MS0a 合成账号 |
| `help/audit-regressions/isolated/selftest.php` | 环境自检用例（Z02、Z06、Z07、Z09） |

### 3.2 运行目录与凭据

- `RUN_ID`：`date +%Y%m%d%H%M%S` + 6 位随机十六进制。
- 运行目录：`AUDIT_RUN_DIR=${AUDIT_RUN_ROOT:-$HOME/.tianli-audit}/$RUN_ID`，权限 `0700`，位于仓库外。
- `bootstrap.sh` 用 `openssl rand -hex 24` 生成 MySQL root、`audit_migrator`、`audit_app`、Redis 密码以及各合成账号的密码，只写入运行目录（文件权限 `0600`）。凭据不进仓库、不进命令行参数、不进测试输出。
- `manifest.json`（运行目录内）：`run_id`、compose project 名 `tianli-audit-<RUN_ID>`、容器 ID、宿主端口、库名、夹具来源及其 SHA-256、创建时间。

### 3.3 数据库与账号

| 账号 | 权限 | 谁使用 |
|---|---|---|
| MySQL root（本实例新生成） | 全部 | 只有 `bootstrap.sh` 使用，用于建库和建账号；不传给测试脚本和应用 |
| `audit_migrator` | `audit_auth.*` 上的 DDL + DML | 夹具导入、迁移演练 |
| `audit_app` | `audit_auth.*` 上的 `SELECT, INSERT, UPDATE, DELETE` | 应用与测试脚本 |

- 库名固定为 `audit_auth`（实例本身是专用的）。
- 结构与种子数据来源：BASE 中跟踪的 `crmeb/public/install/crmeb.sql`（F-09）。导入前去掉 CR，记录 SHA-256。这是上游安装种子（菜单、配置等），不是业务数据。
- `eb_theme_module` 按需用 `help/migrations/theme-module.php` 在本库演练创建。
- 哨兵：表 `audit_sentinel(run_id varchar(64) primary key)`，写入 `RUN_ID`；Redis 键 `audit:sentinel` 写入 `RUN_ID`。

### 3.4 启动保护（`lib/guard.php`）

接口：`audit_guard_assert(): void`。任何写操作和应用启动之前必须调用；不满足以下任一条件就抛异常并以退出码 `2` 结束：

1. 环境变量 `AUDIT_ISOLATED=1`，且 `AUDIT_RUN_DIR/manifest.json` 存在，其中的 `run_id` 等于 `AUDIT_RUN_ID`。
2. 数据库主机为 `127.0.0.1`，端口等于 manifest 中的端口，库名为 `audit_auth`。
3. `SELECT run_id FROM audit_sentinel` 的结果等于 `AUDIT_RUN_ID`。
4. Redis 端口等于 manifest 中的端口，`GET audit:sentinel` 等于 `AUDIT_RUN_ID`。
5. 测试账号执行 `CREATE DATABASE audit_guard_probe` 必须失败（证明没有全局权限）。

2026-10-12普通开发按用户指示停止第5项DDL拒绝探针，原要求保留为NOT RUN。ISO-FULL正常引导使用 `AUDIT_GUARD_MODE=read-only bash help/audit-regressions/isolated/bootstrap.sh`，首次prepare把严格枚举的模式保存到RUN manifest；后续runner、应用supervisor及迁移准备都从该manifest读取。只读模式仍执行原RUN/project/内部网络地址、应用账号、库名、数据库/Redis双哨兵，以及 `SHOW GRANTS` 恰为USAGE和audit_auth库SELECT/INSERT/UPDATE/DELETE且无GRANT OPTION的完整核对，通过后返回。未声明模式的旧流程仍为active-probe，本次不运行；模式未知时拒绝，不能回退旧探针。普通guard CLI使用 `guard.php --read-only` 并在调用前确认模式；常驻PHP已加载的旧guard函数必须通过本专用应用正常重建加载新源码。Task6选择模式前的引导/基线使用旧保护逻辑，不能宣称全过程没有DDL探针；原记录保持，后续after明确记录read-only及探针NOT RUN。

Task7普通客服运行接线：profile `ms1-chat` 的专用audit-chat采用同一PHP只读源/vendor、RUN私有DML配置和内部网络；新增本RUN独占chat-runtime，runner仅只读观察。显式启动容器后先收集真实容器ID/IP/image/runtime卷并登记，再由普通harness发启动许可；入口只构造Channel40003和Chat40002，无admin40001、宿主端口或Docker socket。启动应用服务前直接PDO只读核对旧客服/会话online全部为0、实际上个日历周游客自动删除范围为空，不提前构造会扫删记录的DAO。正常结束只停止本次登记的进程代次及精确自有夹具；不恢复旧数据。实际新增卷数和运行结果另按真实资源盘点记录，安全复现和负向测试保持NOT RUN。

Task7普通浏览器接线：可选profile `ms1-chat-browser` 增加同一内部网络的audit-ui和audit-browser，两镜像固定实际ID且pull_policy=never。audit-ui仅8080内部监听，用本次真实后台dist/只读nginx配置服务历史路由，同源HTTP转audit-app:8000、/msg升级到audit-chat:40002，保留原HTTP_HOST端口；没有notice服务或宿主端口。audit-browser使用既有Node20/Chromium镜像及只读bundled Playwright Core、验收脚本，只挂本RUN browser-secret目录为只读输入、browser-evidence独占目录为可写输出，/tmp与共享内存为临时本实例空间；不挂其它RUN、Docker socket、整个RUN的写权限或app/bootstrap秘密卷。外域统计等请求在浏览器发送前阻止并单列，不mock应用HTTP/WS。profile启动前必须显式设置已核验的AUDIT_BROWSER_DIST与AUDIT_PLAYWRIGHT_CORE真实目录；未配置占位目录不作为验收输入。收集并登记两个实际容器的ID/IP/image、唯一内部网络、只读挂载和无宿主端口后，才给浏览器本RUN启动许可；普通服务/夹具准备完成后才允许业务页面。原163表、旧cache值/绝对期限/runtime及精确清理保护保持，两个正常视口结果和实际截图到位才勾浏览器完成。

首次普通T7B01实际启动定位到Nginx默认fastcgi_temp仍指向只读/var/cache/nginx，UI native exit1且没有有效IP，资源核对先于浏览器/夹具而失败。所有HTTP上游临时目录（client、proxy、fastcgi、uwsgi、scgi）必须显式放在本实例/tmp，不关闭rootfs只读设置。Node等待准备许可时亦读取本RUN公开终态；若PHP已finish/abort则正常结束等待并保留失败结果，不空等15分钟或启动Chromium。该轮原163表/cache保持，0业务请求/0夹具，专用主/引导实例资源全部0；实际FAIL留存，修正后用新RUN核对。

第二轮实际元数据确认浏览器镜像默认USER=node（UID/GID1000），而PHP生成的ready/login文件为UID/GID0、0600；故Node无法读取许可，正常窗口最终原生超时，Chromium/浏览器HTTP均0。browser profile显式user=0:0与该专用私密文件生产者一致；仍保持0600、只读输入、只读rootfs、cap_drop=ALL、非privileged、no-new-privileges及唯一内部网络，不增加其它挂载。Node启动记录并核对UID/GID，准备输入/输出若不可访问立即报告固定错误。该轮3个自有SQL行已清理，36精确键登记/18实际移除，163表和原cache保持，主/引导资源均0。没有降低私密文件读取权限或以其它入口代替浏览器验收。

第五轮普通浏览器准备已实际启动Chat/Channel及HTTP服务，但Chromium启动尝试失败，业务页面尚未访问。独立无网络、无凭据的启动依赖核对定位到Crashpad缺少可写database目录；保持同一镜像、只读rootfs与cap_drop=ALL，设置HOME=/tmp/audit-browser-home、XDG_CONFIG_HOME=/tmp/audit-browser-config、XDG_CACHE_HOME=/tmp/audit-browser-cache后，真实Chromium149.0.7827.53启动、打开about:blank并正常关闭，native exit0。三个目录都在本实例既有/tmp tmpfs内，不增加挂载；该普通依赖PASS不替代T7B01页面验收，必须在新RUN中继续真实登录与聊天。

含可选profile的RUN销毁必须用 `audit_compose --profile '*' down --volumes --remove-orphans`，保留原manifest身份核对。Task7首次普通RUN的旧down命令native0却留下1个audit-chat、1网络及3卷，已据真实资源盘点识别，不称清理完成；剩余资源必须逐一与原manifest的ID、标签、网络ID和卷创建身份匹配后，才沿同一project开启该profile完成销毁。以后每次销毁仍核对实际容器/网络/卷全部0，native0不能代替资源结果。

### 3.5 应用启动

- 应用从**测试 worktree** 启动（例如 MS0a 的 `codex/ms0a` worktree），绝不从备份检出启动。
- 配置注入：先阅读 `crmeb/vendor/topthink/framework/src/think/Env.php`，确认 ThinkPHP 读取环境变量的方式。如果必须使用 `crmeb/.env` 文件，只能在该文件**不存在**时写入，并在首行写 `# AUDIT-RUN <RUN_ID>`；`teardown.sh` 只删除首行哨兵匹配的文件。对 `crmeb/public/install.lock` 采用同样规则。
- 启动命令：`php think run -H 127.0.0.1 -p <随机空闲端口>`（在 `crmeb/` 目录下执行），PID 写入运行目录。
- harness 接口 `audit_app_start(array $envOverrides = []): string`（返回 base URL）与 `audit_app_stop(): void`。A10 的配置错误用例通过 `$envOverrides` 用错误配置重新启动应用。

### 3.6 合成夹具（`fixtures/ms0a.php`）

接口：`ms0a_fixtures(PDO $migrator, string $runDir): array`，返回各账号的 id 与密码（密码同时写入运行目录）。**故意让不同账号表的数字 ID 重合**；最高权限由 `level = 0` 和角色决定，不依赖 `id = 1`。

| 键 | 表 | id | 关键属性 |
|---|---|---|---|
| `admin_super` | `eb_system_admin` | 101 | `level = 0`，`status = 1`，`is_del = 0` |
| `admin_limited` | `eb_system_admin` | 102 | `level = 1`，角色 `R-limited` 只授予已有的"商品规则列表"读接口（`GET product/product/rule`，2026-10-11 用户批准；安装种子未登记 `GET product/product`，不补写种子） |
| `admin_disabled` | `eb_system_admin` | 103 | `status = 0` |
| `admin_deleted` | `eb_system_admin` | 104 | `is_del = 1` |
| `out_101` | `eb_out_account` | 101 | `status = 1`，与 `admin_super` 撞 ID；授予一个只读对外接口 |
| `out_102` | `eb_out_account` | 102 | `status = 1`，与 `admin_limited` 撞 ID |
| `out_disabled` | `eb_out_account` | 105 | `status = 0` |
| `kefu_101` | `eb_store_service` | 101 | 启用的客服，与 `admin_super` 撞 ID |
| `user_101` | `eb_user` | 101 | 启用的顾客，与 `admin_super` 撞 ID |
| `user_102` | `eb_user` | 102 | 启用的顾客 |

若某张表的必填字段导致插入失败，按安装结构补齐中性值，并在报告中注明；不得改动表结构。

## 4. 用例脚本约定

### 4.1 接口（`lib/harness.php`）

```php
audit_case(string $caseId, string $subcase, string $layer, callable $fn): void
// $fn 返回 ['status' => 'PASS'|'FAIL'|'BLOCKED', 'expected' => string, 'actual' => string,
//           'evidence' => array]；抛异常记为 FAIL 并保存异常摘要。
audit_http(string $method, string $url, array $headers = [], mixed $body = null): array
// 返回 ['http' => int, 'json' => ?array, 'raw' => string]
audit_db(): PDO                       // audit_app 账号
audit_redis(): \Redis                 // 测试 Redis
audit_snapshot(array $spec): array    // $spec: ['tables' => [表名 => WHERE 子句或 null], 'redis' => [键模式]]
                                      // 返回每张表的行数与按主键排序的整行 SHA-256、每个 Redis 模式的键集合与值摘要
audit_assert_unchanged(array $before, array $after, array $allow = []): array  // 返回差异列表；$allow 为允许变化的表或键模式
audit_finish(): never                 // 汇总，写 JSONL，有 FAIL 时以退出码 1 结束，全部 PASS 以 0 结束
```

### 4.2 运行阶段

环境变量 `AUDIT_PHASE` 取 `baseline` 或 `after`：

- `baseline`：在修复前的提交（如 `AUDIT_BASE_SHA`）上运行，记录实际结果。用例的期望是"修复后的正确行为"，所以有漏洞时 baseline 显示 FAIL，这是预期的，不得为了 baseline 改期望。
- `after`：在修复后的提交上运行。

### 4.3 允许的副作用

负向用例前后做快照，只允许以下变化：

- 管理员操作日志表 `eb_system_log`（安全审计）。
- 登录失败计数等限流键（例如 Redis `login_captcha`）。

除此之外，业务表（商品、订单、用户、`eb_cache` 等）和业务缓存键都必须不变。

## 5. 证据格式

### 5.1 位置

- 原始输出（完整 HTTP 响应、日志、快照）写到仓库外：`$AUDIT_RUN_DIR/evidence/`。
- 仓库内只提交摘要：`docs/multi-merchant/records/<milestone>/results.jsonl` 和 `report.md`（见 [records/README.md](records/README.md)）。
- 令牌、密码、密钥一律不写入任何证据文件；需要时只写 SHA-256 前 12 位。

### 5.2 JSONL 记录（每个子场景一行）

```json
{
  "case_id": "A12",
  "subcase": "out-refresh-token-to-admin-super-id-collision",
  "milestone": "MS0a",
  "layer": "INTEGRATION",
  "phase": "after",
  "commit_sha": "<实际 SHA>",
  "run_id": "<RUN_ID>",
  "finding_kind": "待复现风险",
  "expected": "business status 401; no protected read; no business write",
  "actual": "business status 401",
  "status": "PASS",
  "db_diff": [],
  "cache_diff": [],
  "evidence_ref": "evidence/A12-out-refresh-super.json",
  "notes": ""
}
```

`finding_kind` 取值：`待复现风险`、`既有拒绝回归`、`既有修复回归`、`新增契约`。

## 6. 现有脚本的使用规则

| 脚本 | 层级 | 规则 |
|---|---|---|
| `security.php` | UNIT-STUB | 可以随时运行。BASE 保留原始 23 项；MS0a 先记录原始基线，Task 7 在 SP-MS0a-1 确认后，将 `unregistered route proceeds`、`unregistered method proceeds without warning`、`existing CRUD exemption proceeds` 三项收紧为拒绝并相应改名，其余 20 项保留；追加新用例，不删除覆盖、不放宽断言。此契约调整须用户批准。 |
| `commerce.php`、`wallet-admin.php`、`wallet-store.php`、`theme-module.php` | INTEGRATION（旧式） | 需要建库、删库权限，只能在本文件 §3 的专用实例里，由 `bootstrap.sh` 用该实例新生成的 root 凭据运行；MS0a 不要求运行 |
| `diy-http.py` | INTEGRATION（只读） | 运行前显式传入测试应用的 base URL |
| `frontend-*.cjs` | 前端 | 按 `frontend.md` |

## 7. 清理

- `teardown.sh <RUN_ID>`：读取 manifest，执行 `docker compose -p tianli-audit-<RUN_ID> down -v`，停止应用进程，删除带本次哨兵的 `crmeb/.env` 与 `install.lock`。
- 失败时保留运行目录作为证据，不自动删除。
- 不对 manifest 之外的任何容器、卷、数据库、键执行删除。
