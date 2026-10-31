# 通用多商户商城 Docker 运行与发布检查

优化版将 PHP 源码、Composer 依赖和前端静态文件构建进 Docker 镜像，避免 Windows 绑定盘的小文件性能问题。它启用了 OPcache，并修复了 Workerman 在容器重启后因残留 PID 无法启动的问题。

镜像现基于 **PHP 8.2**（`php:8.2-fpm-bookworm`），JIT 显式关闭。

> 仅用于本地开发和验收。完成生产安全加固、TLS 和备份恢复演练之前，不得直接用于公网生产环境。遗留阻塞项见文末「已知问题」。

## 首次配置

```powershell
Copy-Item help\docker\.env.runtime.example help\docker\.env.runtime
Copy-Item help\docker\.env.application.example help\docker\.env.application
```

修改两个本地配置文件，确保 MySQL 和 Redis 口令一致。应用模板使用两份编排共同的 `mysql` / `redis` 服务名；已有部署应保留其实际有效主机名。它们已加入 `.gitignore`，不得提交真实口令。

现有验收库迁移时，应保留当前本地配置中的数据库口令，避免已有 MySQL 数据卷与新容器凭据不一致。

应用模板的 `[AUTH] SESSION_EPOCH`、`STAMP_KEY` 留空，部署前必须填写：epoch为1–128位字母、数字或 `._-`，stamp key至少32字节且与实际 `APP.APP_KEY` 不同；原APP_KEY占位也必须替换。`ISSUER=generic-multi-merchant` 仅是新部署示例。已有部署复制模板时应保留现有有效APP_KEY、epoch、stamp key、issuer及实际自营商家ID，不能借更新模板自动轮换凭据或身份。容器health和版本检查通过不等于已具备后台/商家登录资格。

`[MARKETPLACE]` 使用实际扁平键，例如 `TRADE_ENABLED`；进程环境对应 `PHP_MARKETPLACE_TRADE_ENABLED`。`SELF_OPERATED_MER_ID` 必须填写经过实际MS2归属确认的自营商家ID；公开商品查询在总开关关闭时也会校验它，不能填猜测值。全部新功能开关默认false，目录配置不自动开放交易、售后、聊天私有写或视频上传。两份发布Compose固定 `APP_ENV=production`，测试支付不可用；测试支付仅限另外明确隔离、实际进程为testing且测试开关已开的实例。云上传在真实私有暂存桶、供应商策略和资格未完成前保持关闭，不填写虚构存储ID或地址。

两份发布Compose仅给PHP挂载项目命名卷 `private-assets:/private`，Nginx不挂载。镜像预置 `customer-form`、`aftersale`、`refund`、`licence`、`withdrawal`、`chat`、`product-video`、`product-media-scan`、`product-video-cloud` 九个子目录，所有者UID/GID33:33、模式0700；首次挂载新的空卷时由Docker copy-up初始化。既有卷沿用原内容，入口不递归修改私有文件权限；缺目录或权限异常应核对原卷及资产归属，不删卷来重新初始化。数据库、公开uploads和私有卷须共同纳入业务备份；现有上传快照清单不能代替私有卷备份。认证会话不随业务备份恢复，恢复后的epoch轮换仍按原独立维护流程执行。

上述模板、私有卷copy-up和显式队列接线的实际正常结果见下文验证范围；真实生产Compose启动、完整恢复与生产验收仍未完成。

## 验收快照

切换容器或升级依赖前，应先停止业务写入并把验收快照保存到仓库外。快照包含规范化数据库结构/数据摘要和每个上传文件的路径、字节数、SHA256；数据库正文、上传文件和口令不会写入 JSON 清单。

对正在运行的旧栈采集新快照：

```powershell
$snapshotDir = "D:\backups\crmeb-before-change"
pwsh -NoProfile -File help\docker\capture-acceptance-snapshot.ps1 `
  -Mode Capture `
  -OutputDirectory $snapshotDir
```

已有规范化 SQL 和上传 SHA256 CSV 时，可导入为机器可读清单：

```powershell
$snapshotDir = "D:\backups\crmeb-before-change"
pwsh -NoProfile -File help\docker\capture-acceptance-snapshot.ps1 `
  -Mode Import `
  -OutputDirectory $snapshotDir `
  -SchemaPath "$snapshotDir\crmeb-schema-pre-change.sql" `
  -DataPath "$snapshotDir\crmeb-data-pre-change.sql" `
  -UploadManifestCsv "$snapshotDir\uploads-pre-change.sha256.csv" `
  -TableCount 157 `
  -DumpClient "mysqldump 8.0"
```

采集和严格比对必须在停写的迁移窗口执行。恢复业务写入后，新增订单、用户或上传文件会让旧快照校验失败，这是预期保护行为，不应通过修改快照绕过。

## 构建、检查与发布

部署主机需要 Docker Compose v2、Python 3.10+（Windows 为 3.12+，依据[标准库管道支持](https://docs.python.org/3/library/os.html#os.set_blocking)），以及事先独立认可的检查器镜像完整 `sha256:` ID。检查器必须来自可信的当前发布工具链，不能自动把本次候选镜像当成可信检查器。`MARKETPLACE_CHECKER_IMAGE` 只填写这个已核准的固定 ID；镜像来源认可与版本兼容检查是两项不同责任。

正式候选先在已认可的 Windows / Python 3.14 资格机完成同一最终 HEAD、同一资格包的 admin / merchant / H5 三端真实构建，再运行该 Source 内的正式复制工具。下面变量须取自已复核的来源、构建和输出目录记录；B 验收材料或当前预览目录不能替代正式输出：

```powershell
python -I -B -X utf8 "$qualifiedSource/help/docker/prepare-marketplace-public.py" `
  --build-source $buildSource --pins-sha256 $buildPinsSha256 `
  --runs-file $runsFile --out $copyOut
```

仅发布工具更新时，可以保留已经通过的同一应用 Source 和三端构建。先独立复核新工具，再由 Root 明确提供复核文件及完整 SHA；用已复核的新工具路径执行同一命令，并同时追加 `--tool-review-file $toolReviewFile --tool-review-sha256 $toolReviewSha256`。复核必须绑定原构建包、原工具和当前工具的完整内容；三端同 HEAD、Source、依赖、复制及读回检查继续执行。未提供这两个参数时，仍要求工具本身属于同一 qualified Source。复核格式和实际范围见 [版本门记录](../../docs/multi-merchant/records/ms6/version-gate-core.md)。

工具成功后，`$copyOut/context` 已是完整的正式构建目录：同一 qualified Source 的应用源码、Composer 锁文件、Dockerfile 和 Docker 配置，以及按正式映射装入的三端页面。直接以这份已完成全树读回的输出作为镜像 context。构建前核对 `FORMAL-COPY.json`、`COPY-PLAN.json`、`CONTEXT-TREE.json` 与该目录仍一致，并记录 `.dockerignore` 对实际镜像输入的排除。运行配置、上传数据和凭据不进入构建目录；Windows 的逻辑文件模式也不能代替镜像内的 Linux 权限检查。

从这份已核对的完整目录构建双镜像，记录固定 `sha256:` ID，再进行默认检查。以下标签对应优化 Compose；演示配置须同步其 `PHP_IMAGE` / `NGINX_IMAGE`。构建不会切换现有服务：

```powershell
$imageContext = Join-Path $copyOut 'context'
docker build --file "$imageContext/docker/php/Dockerfile" --tag tianli/crmeb-php:acceptance $imageContext
docker build --file "$imageContext/docker/nginx/Dockerfile" --tag tianli/crmeb-nginx:acceptance $imageContext
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml config --quiet
$checkerImage = $env:MARKETPLACE_CHECKER_IMAGE
python -X utf8 help/docker/deploy-marketplace.py `
  --env-file help/docker/.env.runtime `
  --compose-file help/docker/docker-compose.optimized.yml `
  --checker-image $checkerImage
```

`deploy-marketplace.py` **默认只检查**。它将 Compose 中的候选 PHP/Nginx 镜像解析为固定 ID，只从未启动的 PHP 候选容器读取 `marketplace-release.json`；真正连接数据库的检查在独立可信镜像中执行。检查复用目标服务的配置、环境与现存网络，沿原主库连接核真实 metadata 结构、schema 范围和最低应用版本，不初始化应用、不迁移数据库。成功不等于已部署。前置失败返回 `REJECTED`；一旦已尝试启动，后续失败返回 `APPLY_FAILED` 并标明可能已有变更，保留现场且不自动回滚。检查器只支持明确的应用配置和数据挂载；应用代码、vendor、版本声明或数据库配置代码被挂载覆盖时拒绝。Nginx 的 public 和配置也必须来自镜像，public 内仅允许 uploads 数据挂载；不支持的 configs / secrets 提供方式会明确拒绝；tmpfs 临时目录也按同一目标路径规则检查，保留不覆盖代码或配置的正常用法。未支持的网络/DNS或配置提供方式也会明确拒绝，不能静默换成其他数据库。

首次安装应先按原安装和维护流程准备数据库、网络、私有配置并完成 [MS6 metadata 迁移](../migrations/marketplace-ms6-schema.md)；库或 metadata 缺失不能跳过检查。配置必须由运行用户 UID33 可读，使用 `/run/application/.env`；不要把真实配置、凭据或展开后的 Compose 配置提交到 Git。新的默认检查不再替操作者修改文件权限。

只有已获本次部署授权、原检查通过且进入配置/迁移串行维护窗口后，才显式应用：

```powershell
python -X utf8 help/docker/deploy-marketplace.py `
  --env-file help/docker/.env.runtime `
  --compose-file help/docker/docker-compose.optimized.yml `
  --checker-image $checkerImage --apply
```

应用路径重新核配置和版本，使用同一固定双镜像 ID，禁止临时拉取或构建；然后执行原 `up --wait` 并刷新 Nginx。刷新后在180秒内核同一Nginx容器、项目、服务及固定镜像，并等待其健康状态为 `healthy`，再完成下述PHP常驻进程检查，才返回 `APPLIED`；无健康检查、异常状态或超时均保留现场并返回 `APPLY_FAILED`，不自动重启或重建。镜像内启动版本门仍保留。操作期间不得并行修改配置或迁移；这不宣称消除了全部外部并发窗口。多份 Compose 使用重复 `--compose-file` 参数，非默认服务名使用 `--php-service` / `--nginx-service`，项目可用 `--project-name` 指定。

发布成功前还会在独立180秒期限内核本次PHP容器的ID、固定镜像、Compose项目/服务和运行健康状态，再以已有Docker操作权限执行固定、只读的Supervisor具名状态查询。`php-fpm / queue / timer / workerman`必须各出现一次、为RUNNING并有正PID；实际容器环境`MARKETPLACE_QUEUE_AUTOSTART=true`时还必须满足`marketplace-queue`。未设置、空值或false沿入口默认只要求四项；false不是强制停止可选队列。STARTING只允许有界等待，其他非就绪、缺失或异常查询返回固定`RUNTIME_NOT_READY`及原`APPLY_FAILED/changes_may_have_occurred`，保留现场。检查本身不start/restart、修改权限或修复程序。

成功的`APPLIED`回执增加`runtime.status=READY`、`runtime.required_programs`（固定名称与RUNNING）及`runtime.marketplace_queue_required`。默认`CHECKED`仍只核版本兼容，不查询进程、启动应用或增加runtime字段。Supervisor控制socket继续root所有/0700，业务worker和demo健康检查用户不变；不要给www-data增加控制socket权限。此结果是进程就绪快照，不能代替队列业务消费、Timer业务或稳定性验收。

源码或静态页面变化后重复“同源三端构建 → 正式 public 复制 → 完整镜像 context 核对 → 双镜像构建 → 默认检查 → 已授权的 apply”，不要直接从仓库旧 public 构建正式候选或运行 `up --build`。发布后的快照、页面与业务验证仍按原验收责任执行；优化栈入口默认 `http://127.0.0.1:8011/`，MySQL、Redis、FPM 和 Workerman 仅在 Compose 网络内访问。

### 当前验证范围

2026-10-27已在本机专用18186实例，以实际 Compose、配置卷和内部网络完成默认检查。候选app 0.1.0、schema范围1，与实际目标库schema1/最低app0.1.0相符；检查器源码哈希核同。检查前后版本行、商品/SKU库存、订单和余额投影、Redis值及绝对期限、配置/上传/私有文件哈希保持；原33容器身份、启动时间、重启数和挂载保持，临时检查容器零残留。Python/PHP/shell语法和独立源码审查通过。

该18186批次只执行默认检查；后续隔离首启的apply结果见下文。实际生产部署替换、完整R03及MS6验收仍待各自验证。停止的负向、故障、并发、恢复和K样本保持NOT RUN，以上正常只读结果不替代这些责任。

### 认证、私有卷与队列接线验证

2026-10-27候选在独立18187实例完成原镜像启动、健康检查与平台/商家/顾客真实登录；`prepare --marketplace-queue-autostart`保存的配置使专用队列自动进入RUNNING。合法合成员工沿原 `AuditLogJob::dispatch` 提交一条资料读取审计，常驻Worker实际生成唯一记录，队列三种计数归零，原商品/SKU/订单/余额投影保持。五个Supervisor程序PID保持。本次没有停止消费者或手动调用任务执行函数。

应用模板由真实ThinkPHP Env读取，填入仅存内存的合成认证配置后通过原AuthConfigGuard。独立新空卷实际从镜像初始化九个UID/GID33、0700目录，六类原私有素材存储构造器读取通过；七个业务开关仍为false。两份发布Compose的默认/显式队列配置均已解析核对，但本次运行实例使用隔离预览编排，**没有启动生产Compose、执行`--apply`、重启恢复或本轮私有上传HTTP**。首次配置探针因未载框架helper失败，修正工具后通过，原失败保留。

本轮改动位于独立工作树，旧完整预览所绑定的原启动文件已恢复并在运行容器内核同；原33个预览容器身份、状态、启动时间、镜像、重启数及挂载保持。汇总证据在仓库外 `artifacts/multi-merchant-release-images-20261027-r3/verification.json`。上述结果不代替完整MS6或生产验收。

### 原发布 Compose 的隔离首启验证

2026-10-27已在新专属18188使用原optimized Compose与 `help/preview/compose.release.yml` 完成首次安装、默认 `CHECKED` 及实际 `--apply`。四个日常服务均healthy，Nginx刷新后重新通过健康确认才返回 `APPLIED`；五个Supervisor程序均RUNNING。方法与命令见 [预览说明](../preview/README.md#发布-compose-隔离首装新实例)。本次是本机新隔离实例，不是生产替换。

三角色实际HTTP登录、商家me及商品列表通过；两件合成商品价格20.15/30.25、库存各100。默认七开关关闭时，公开列表只显示自营商品；该实例尚无自营商品，实际正常返回空列表。浏览器已打开顾客首页和商家登录入口，未将入口渲染当成浏览器完整交易验收。日常应用实际为production、DML用户仅SELECT/INSERT/UPDATE/DELETE，认证配置与schema1检查通过；真实私有卷九目录UID/GID33、0700，六类原存储构造器正常，Nginx不挂私有卷，bootstrap夹具卷独立。

41个原预览容器的身份、状态、启动时间、镜像、重启数及挂载保持，旧绑定控制源码哈希保持，临时版本检查容器零残留。汇总证据在仓库外 `artifacts/multi-merchant-release-compose-20261027-r1/verification.json`。未运行负向、故障、并发、恢复或K样本；真实支付、生产部署、完整MS6和正式验收仍待完成。

### 发布就绪的常驻进程补验（2026-10-28）

r44已用既有已审不可变双镜像和检查器，在新18196默认配置、18197显式开启多商户队列配置沿原预览工具实际完成发布。两次APPLIED分别包含四项/五项RUNNING；具名原生状态及正PID观测相符，控制socket仍700/root，www-data原健康检查exit0。两环境各GET login/info与公开products，四次HTTP/业务状态均200，不据此签页面视觉或完整业务验收。默认CHECKED在配置已准备但尚无App容器时通过；另一已运行目标的CHECKED也保持原11字段及容器身份/启动/重启计数，无runtime字段。最初缺运行配置的CHECKED拒绝及准备时序/网段失败原件保留，补齐原生产步骤后核真实通过，未放宽断言。

原132个容器的所选控制字段及430个完整挂载对象保持，原18180的42项观测值、22订单记录/24明细保持；不扩称整个Docker inspect、全库、Redis或卷内容逐字相同。两套本轮验证实例已沿原stop正常退出0，具名卷保留，本轮临时版本检查容器无残留。只改发布消费者，无新应用镜像/前端构建或生产部署；STARTING仅源合同审查，停止的负向、故障、并发、恢复和K样本保持NOT RUN；本轮未验证业务任务消费。证据位于`artifacts/multi-merchant-runtime-readiness-20261028-r1`，实际运行根分别为`multi-merchant-runtime-readiness-default-20261028-r1`和`multi-merchant-runtime-readiness-queue-20261028-r1`；本批不等于完整MS6验收。

### HTTP 请求关联与日志字段

新镜像的 API 响应通过 `X-Request-ID` 返回诊断编号。Nginx用自身生成的编号，经专用FastCGI参数传给PHP；应用不采用客户端同名请求头。没有该网关参数的直接PHP入口本地生成编号。一次请求的网关access记录、应用完成/异常记录和商家认证上下文使用同一编号；队列任务仍保留原独立编号，未在本片改签名载荷或审计表。

网关access日志改为有限JSON字段：时间、编号、方法、状态、字节数和耗时，不记录URI、查询串、来源页、客户端IP或User-Agent。HTTP异常处理器和请求完成记录复用原Think日志，只保存固定事件、编号、应用、方法、数值状态、耗时和受限代码位置，不保存请求参数、完整响应、口令、令牌或原始异常消息。原 `success_log` / `fail_log` 开关继续选择对应级别；关闭时仍有有限字段的info完成记录。日志写入失败不改变业务返回。

客户端收到响应时，完成日志可能尚在HttpEnd阶段写入，核对编号应允许短暂落盘时间。此改动只覆盖HTTP处理器、完成记录和网关access通道；原支付/队列的其他日志及失败任务保存、网关error日志仍需分别完成，不代表全部日志或完整R04已验收。

正常验证（2026-10-27）：新专用18189实例沿原发布Compose完成初始化和实际apply。平台登录信息、三角色登录、两次商家本人读取、商家商品及公开商品共8次正常请求均为HTTP/业务200；每次响应有唯一32位编号，并各匹配一条网关记录和一条PHP完成记录，字段闭集检查通过。实际员工认证复用所给编号，旧调用仍生成独立编号且身份一致。对探针时段Nginx日志和当前ThinkPHP应用日志目录（`/var/www/runtime/log`）内全部文件，以11个不同的实际合成口令、令牌及配置秘密在内存作精确扫描，零匹配；不输出秘密或保存日志原文。这是本次样本的结果，不是所有历史或未来日志的保证。

13个候选运行源码哈希与已审构建输入相同，原45个预览容器和两份绑定源码保持；新四服务healthy、五个原Supervisor程序RUNNING。浏览器核对顾客首页及商家登录入口后恢复原18180页面，仍保留两标签；未重跑完整浏览器交易。语法、实际HTTP/日志、资源保持和入口证据在仓库外 `artifacts/multi-merchant-http-logs-20261027-r1/verification.json`。停止的负向、故障、并发、恢复及K样本保持NOT RUN。

### 队列关联与处理状态

队列诊断沿原生任务编号关联投递、处理轮次、进入任务和返回。四个框架事件写有限字段的 `queue.processing`、`queue.returned`、`queue.exception`、`queue.failed`，包含连接类别、原生job ID、尝试次数及框架状态，不保存完整载荷或异常原文。`returned`只表示处理函数返回；`repeat_attempt`只表示再次处理轮次，`released`等标志不替代Redis确认。普通BaseJobs额外记录实际进入函数和其原吞错位置，原执行、删除、释放规则保持。

原普通Queue封装保留两次入队尝试，记录 `queue.submit_returned` 或固定失败事件；正常返回不提升为严格持久化确认。由HTTP调用时使用当前请求编号，CLI来源明确记录为console。AuditLogJob在其原生RPUSH正确认后，以 `queue.submitted` 桥接已认证MerchantContext编号、独立任务编号及job ID；原六键签名载荷不增加字段，数据库提交与严格删除确认完成后才写 `queue.audit_completed`。当前业务Controller没有自动调用此审计任务，登录或读取本人资料不等于自动投递。

通用BaseJobs异常、Queue最终入队失败和全局CLI report改用有限字段。console的异常渲染、框架状态输出、其他业务任务自有日志及失败任务表的原载荷/异常存储仍有各自范围；失败任务数据保留原重试用途。这里不宣称全部Worker输出或完整R04已经通过，停止的故障、重试故障、并发及恢复样本不因此恢复执行。

正常验证（2026-10-27）：新专用18190实例沿原发布流程实际apply，复用既有正常Worker验证的身份、审计和业务投影断言。合法服务认证后经原AuditLogJob投递一项，由常驻消费者产生唯一成功审计；source context编号、独立任务编号及原生job ID贯通五条有限日志。再经原Queue封装投递原CheckQueueJob，由普通消费者写入本次合成检查标记，对应四条有限日志。两类任务均为首次正常处理，两队列的pending/reserved/delayed全零，商品/SKU/订单/用户余额投影不变，五个Supervisor程序状态及PID保持。未用HTTP登录代替任务投递，也未手动调用fire。

当前ThinkPHP日志目录 `/var/www/runtime/log` 内文件及PHP容器当时全部stdout/stderr，以12项实际凭据、配置秘密及合成检测值作内存精确扫描，零匹配；不保存原始日志或私密扫描数组。运行8个受核来源（7个PHP和Nginx配置）与构建输入一致，49旧容器及两份旧绑定源码保持，三角色登录、消费后的正常API和商家浏览器入口可用。汇总证据为仓库外 `artifacts/multi-merchant-queue-logs-20261027-r1/verification.json`。这只证明两项正常任务及本次日志样本，不替代完整Worker、重试或正式验收。

### 支付保存结果的诊断关联

测试支付的创建结果、查询/关闭观察和单独保存的履约暂停状态，在原事务提交并完成读回之后记录有限事件。HTTP事件沿响应的 `X-Request-ID` 关联；命令行入口使用独立诊断编号。内部订单主键、支付尝试ID、操作、保存状态和固定原因码用于定位原生支付记录，日志不包含订单号、账户快照、渠道交易号、幂等键或完整支付结果。日志异常不改变支付结果；暂停事件也不代表付款成功。

旧支付宝回调处理、验签、转账失败和转账异常的原始错误日志改为固定事件及受限代码位置。验签、资金调用与返回值保持原语义。测试支付与支付宝回调是两条独立链路；本机测试支付的通过不能证明真实渠道回调或转账成功。其他渠道日志、完整R04与真实收付款仍需分别完成，用户停止的故障、负向、并发和恢复样本保持NOT RUN。

正常验证（2026-10-27）：新专用18191普通testing实例使用原镜像编排完成建单与一次测试支付。顾客、商家、平台付款前后均核对2件、40.30；付款后均为已支付、`marketplace_test`，商品与SKU库存保持98，余额和库存流水未被付款改变。真实创建结果pending与查询结果applied各产生一条有限支付日志，与响应、网关及HTTP完成记录使用同一请求编号；14次正常请求的关联均通过。15项实际合成凭据、配置秘密和本次支付键在当时应用日志及PHP/Nginx输出的内存扫描中零匹配。运行4个受核来源与已审输入一致，53旧容器及两份旧绑定源码保持；新四服务运行，MySQL/PHP健康、Redis/Nginx原本未配置health，五个Supervisor程序RUNNING。首次探针在付款前因平台请求头名称错误失败，原记录保留；修正为原`Authori-zation`后通过。汇总证据在仓库外 `artifacts/multi-merchant-payment-logs-20261027-r1/verification.json`；不代替真实渠道或完整验收。

通联客户端只记录JSON成功解析、收到回调、签名验证返回等固定事件，保留原 `isDebug` 门；有限事件使用info级别。日志没有响应、header、回调参数或签名。原debug配置默认过滤debug，框架context只用于消息占位符替换，因此这里不宣称旧实例已实际记录完整响应。JSON错误判断先于日志编码，原解码标志、异常和返回保持；“解析成功”只描述JSON，不表示支付成功，`verification_returned`也不宣称验签通过。

针对性正常检查脚本为 `help/audit-regressions/isolated/allin-logging-normal.php`。从仓库取得该脚本，以只读方式挂载到候选PHP镜像，替换下列两个占位值后执行：

```sh
docker run --rm --network none --read-only --user 33:33 --tmpfs /tmp --mount "type=bind,source=<绝对仓库路径>/help/audit-regressions/isolated/allin-logging-normal.php,target=/check.php,readonly" --entrypoint php <已核候选PHP镜像ID> -d opcache.enable_cli=0 /check.php --app-root=/var/www
```

测试进程仅替换HTTP传输及日志写入依赖，加载真实通联客户端、签名、回调、Request和Think日志代码。只使用本次生成的合成参数，标为UNIT-STUB；不调用机构、不写订单，不证明cURL/TLS、RSA或真实资金链路。现有浏览器预览实例仍保留原镜像，候选镜像和验证结果以本次交付记录为准。

2026-10-27实际候选检查通过：成功JSON及U64字符串保持、默认MD5正常发送、有效签名回调三项正常检查全部通过；两次替身传输、一次回调、四条有限info记录，debug捕获自检开启。15项本次合成密钥、签名、业务与header标记在内存日志扫描中零匹配。候选运行的三生产文件哈希与已审构建输入相同，测试进程退出0、stderr为空并已清理；57旧预览容器及两份旧绑定源码保持，没有新建数据库实例。汇总证据位于仓库外 `artifacts/multi-merchant-allin-logs-20261027-r1/verification.json`。真实渠道及停止样本仍未运行。

## PHP 8.2 兼容性验证

镜像构建后、切换容器前，先做静态验证。`Candidate` 模式检查运行时版本与扩展、平台依赖、应用 lint、运行期诊断、图像输出比对，以及九项离线集成用例：

```powershell
pwsh -NoProfile -File help\docker\verify-php82.ps1 -Mode Candidate -Image tianli/crmeb-php:acceptance
```

期望末行为 `CANDIDATE_STATUS=GREEN`。

单独复跑某项集成用例（用 `www-data` 运行，与 FPM 进程用户一致；用 root 会使只读断言失败）：

```powershell
docker run --rm --network none --user www-data `
  -v ${PWD}\help\docker\probes:/probes:ro tianli/crmeb-php:acceptance `
  sh -c "cd /var/www && php /probes/php82-integration-compatibility.php jwt"
```

可用用例：`jwt`、`spreadsheet`、`order-export`、`product-export`、`easywechat`、`payment`、`sms`、`uploads`、`service-discovery`。

JDOSS 惰性 `S3Client` 的 AWS SDK 诊断单独验证：

```powershell
docker run --rm --network none `
  -v ${PWD}\help\docker\probes:/probes:ro tianli/crmeb-php:acceptance `
  sh -c "cd /var/www && php /probes/php82-aws-s3client.php"
```

严格验收脚本会比较两次 Supervisor PID、Docker 重启次数和本轮新增日志。手工排查时仍可执行：

```powershell
docker exec crmeb_php supervisorctl status
docker logs crmeb_php 2>&1 | Select-String -Pattern "Implicit conversion|process terminated" | Measure-Object
```

四个默认程序都应为 `RUNNING`，且计数为 0；显式启用marketplace队列时，另核该消费者状态及实际任务结果。

## 停止与回滚

停止优化栈：

```powershell
docker compose --env-file help\docker\.env.runtime -f help\docker\docker-compose.optimized.yml down
```

### 回退兼容版本

将拟回退的镜像配置为候选，再经过同一默认检查和已授权的 `--apply`。旧单商家镜像没有有效 manifest，或版本低于目标库 `min_app_version` 时，都不能启动。不得通过改标签、直接 `docker compose up`、旧 `run.sh install/start/restart` 或手工降 metadata 绕过；绕过发布入口属于不受支持的违规操作。

旧 `run.sh` 的安装、启动和重启路径已停用，以免其旧清理逻辑在版本检查前删除数据或网络；查看日志和原停止路径仍保留。任何停止命令都不得添加 `-v`。数据库、上传文件和私有素材卷必须保留；需要恢复时遵循单独授权的备份恢复方案，不能把恢复视为普通镜像回退的附带动作。原恢复验收未因本文的操作说明而完成。

## 已知问题（仍阻塞公网生产）

PHP 8.2 兼容迁移已完成，但以下问题未解决，上线前必须单独处理：

- **依赖安全公告 24 条，涉及 9 个包。** 已从迁移前的 33 条 / 11 个包降低，但未清零。
- **两个已废弃包**：`guzzle/guzzle`（Guzzle 3，应迁移到 `guzzlehttp/guzzle`）和 `topthink/think-factory`（无替代）。`overtrue/wechat` 3.x 亦已停止维护。
- **无第一方自动化测试套件。** `app/adminapi/controller/Test.php` 是业务控制器，不是测试。本次迁移依靠专用探测脚本验证，不能替代回归测试。
- **`think-image` `gif/Encoder.php:77`** 处理静态 GIF 时报 `Undefined array key 0`。该缺陷在 PHP 7.4 上即已存在（当时为 Notice），输出字节不受影响，故不计为迁移引入的问题。修复它需要改动帧数组逻辑，属于功能修复。
- TLS、生产密钥管理、备份保留策略与恢复演练均未配置。

## 阿里云演示服务器

`docker-compose.demo-server.yml` 用于不接域名的隔离演示环境。它只发布 `8011` 端口，并从本地已验证的 PHP/Nginx 镜像启动，不在服务器上编译源码。

服务器目录固定为 `/srv/tianli/crmeb-demo`：

```bash
cd /srv/tianli/crmeb-demo
export MARKETPLACE_CHECKER_IMAGE='sha256:替换为已核准的64位摘要'
sh redeploy-demo-server.sh             # 默认只检查
# 本次部署获授权后：sh redeploy-demo-server.sh --apply
```

MySQL、Redis、runtime 和 uploads 分别保存在 `data/` 下；私有素材使用本项目的 `private-assets` 命名卷。停止或升级不得清空这些目录或私有卷，也不得在 Compose 命令中添加 `-v`。首次启动时 `init/` 下的权威示例 SQL 仅在空数据目录中导入一次。

更新 PHP 镜像时使用上述入口；将新 `deploy-marketplace.py` 和 `marketplace-pre-up.php` 一同放在部署目录。默认检查不会重建 PHP 或重启 Nginx；显式应用成功后才按原逻辑刷新 Nginx 上游地址。候选镜像写入 `.env.runtime` 的 `PHP_IMAGE` / `NGINX_IMAGE`，检查与应用使用同一配置。

首次配置时由维护人员将 `.env.application` 和 `.constant` 设为 `root:www-data`、权限 `0640`，再进行检查。默认检查不会改权限；PHP 健康检查及版本检查均以 `www-data` 身份读取配置。demo 与优化模式均把 `.env.application` 只读挂载到 `/run/application/.env`，与镜像内链接一致。

阿里云安全组还必须放行入方向 TCP `8011`；演示期间优先限制为甲方和维护人员的公网出口地址，确需公开演示时再临时放宽来源范围。

该环境仅用于客户演示，不得接入真实支付、真实用户数据或作为公网生产环境。

### 项目自有兼容覆盖类

以下 vendor 类因无法在 PHP 8.2 运行且上游无可用修复版本，由项目自有实现覆盖，通过 `composer.json` 的 `exclude-from-classmap` 与 `classmap` 注册，位于 `crmeb/crmeb/compat/` 和 `crmeb/crmeb/services/`。升级相关依赖时必须同步复查：

| 覆盖对象 | 原因 |
| --- | --- |
| `think\image\gif\Decoder` / `Encoder` | 花括号偏移语法在 PHP 8.0 被移除 |
| `think\Image` | 文字水印坐标隐式浮点转换 |
| `EasyWeChat\Support\Collection` | `__set_state()` 参数数量、接口返回类型、序列化契约 |
| `think\Paginator`、`think\Model`、`Conversion`、`think\db\Where` | 接口返回类型声明 |
| `think\Collection` | PHP 8.2 接口返回类型声明，避免 ORM 结果集首次转换时终止 Worker |
| `Pimple\Container` | 接口返回类型声明 |
| `Workerman\Events\Select` | `stream_select()` 微秒参数隐式浮点转换 |

## 旧单商户说明的适用范围

原上游的一键镜像、直接 Compose 启动和“清空目录重新安装”步骤不适用于当前多商户版本，已从当前运行手册退役。已有数据库目录非空时，先核对实例身份、原卷映射、安装完成状态和配置，保留数据与失败现场；不得通过清空数据库、上传目录或重置安装锁来通过检查。首次空库准备与恢复是单独流程，后续启动仍走上面的版本检查入口。
