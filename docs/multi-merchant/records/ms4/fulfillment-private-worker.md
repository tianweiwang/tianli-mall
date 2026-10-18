# 履约私有 Worker 接线收口

本记录对应原09的Task6，沿通用多商户目标和已有L2自主实施授权；不另建执行清单，不启动生产或真实金融。Root在实施前记录当前决定，原采用与原交付保持。

## 需要统一的合同

原WRITE-SEAMS-r2及ROOT-WRITER-ADOPTION-r2已明确：系统收货只能由现有count1/socketless Workerman真实onWorkerStart中的私有闭包启动，固定60秒、batch100；不得登记到SystemCrontabServices、CrontabRunServices、markList、system_timer、通用-i或公开路由。既有全局crontab任务保持。原8400字节Timer候选据此实现，但此前被明确暂缓接入和激活。

后来S2设计47089字节及统一采用7287字节确实另采用了receipt mark/注册类/Crontab接缝，并非无采用。cfdcea664整合将其接入，未处理先前no-public要求；原公开cron dispatcher仍按保存mark动态调用。源码可推导新mark若注册且到调度时点，新scan可沿该入口到达。没有执行此HTTP或到期探针，也不声称已出现运行事故。两份采用在此形成未闭合冲突，不能同时激活两个扫描来源。

Root当前决定保留原明确的私有Worker/no-public边界，撤回后来S2设计第7节中的新global receipt mark/注册接缝，保留全部S2业务、完整facts/actions/refunds/分次收货与评价资格。调度直接调用当前同一MarketplaceOrderFulfillmentServices::scanDue，不退回旧54740字节服务或另写收货核心。此决定优先用于本次有限后继。

## 精确实施范围

- crmeb/crmeb/command/Timer.php：当前2379字节，采用原8400字节私有Worker完整候选，先核当前真实Workerman及实际autoload映射。保原命令、原任务、pid/log、socketless/count1和runAll；新scan不借-i或请求标记。
- crmeb/app/services/system/crontab/CrontabRunServices.php：只移除cfdcea664新增的fulfillment use、mark及同名receipt handler/另一路cursor，精确回其此前9686字节供体。原post-commit、legacy、其它任务与公开旧dispatcher不重做。
- 删除尚未发布且在当前代码仅见定义、未见实际调用的MarketplaceOrderFulfillmentReceiptTimer.php。保存完整原件及删除清单；不添加替代安装CLI/Queue/Job，不写或删除任何system_timer行、历史记录或缓存数据。

当前固定验证工作树和Run仍以59e6525独立运行；本Main记录及后继源码不写该工作树/P/Run，不冒用该Run结果证明后继Worker。

## 运行行为与验证边界

私有start准入沿实际Worker对象/唯一registry/workerId/count1/id0/无socket/正PID/同EventInterface loop与真实start/stop闭包。真实Timer::add返回正整数ID后才active，首次scan不早于60秒；每tick重核liveness，busy在finally清除。只采用当前scanner真实next cursor，缓存hint不作资格或完成依据。stop先失活私有状态，只删除同loop的实际owned timer，保其它任务；只记录固定pending码，不吞掉异常伪造成功。

接入前以当前实际依赖而非历史报告核对应API；完成后独立有限源码复核、实际PHP8.2语法及必要autowiring检查。真实正常Worker验证须另有明确独立运行角色、实际PID/loop/timer/tick、全量SQL/Redis/文件状态、同句柄正常结束及资源核零；不直接无约束启动全部mark，不删原任务或伪cache清单。

用户已停止expiry等样本。不得等待一天制造到期、改时钟/保存deadline、用小数截断或运行拒绝/冲突/并发/故障/恢复/重启样本。正常无到期tick仅证明对应调度调用、零收货业务写与正常生命周期，不签收到期系统收货、完整Worker或MS4。原Task6/7/8、三端API/DOM、非空历史、真实金融和发布缺口保持。

本记录为实施前决定。当前只有公开源码准备，尚未应用上述产品修订、解析或启动Worker；实际结果后续追加。

## 有限接入检查点

两件完整候选和一件删除清单已通过非作者有限源码复核；五组十raw、完整正逆及25件目录pins由Root实际重核。实际PHP8.2两产品语法均native0/stderr空，临时容器前后为空，候选保持。Timer为8400 B /17d1197c9cee0c309f09de8f8d93a99dc680366a2061fb2b3a5be0e84d400ac5；Crontab为9686 B /0152e6a8a0bca0398191ab025c66d6096e4e438ba50ea6c15f36648fb718aa74。被删除注册类原8810字节完整保存在仓库外；只删除未发布源码，不写删SQL、缓存或历史。

Root用当前真实vendor只读autoload核实际Timer/Crontab类路径、原postcommit方法保持且新global receipt handler缺席、当前scanner真实参数/返回类型和Worker公开API，实际native0/stderr空。原静态getEventLoopName在本固定PHP8.2环境选择Workerman Events Select，类实现EventInterface；这不是实际Worker实例、loop、timer或tick证明。React四适配类只实现React接口，原候选在其分支不激活；没有放宽准入或强制loop。

独审报告7942 B /f3c6253ba8ef16f447069758db2afd1d8ab3c073c6f26e11bade3e86174fa9d8。实际集成只包含上述三产品路径、本记录和原09；当前正常联调记录的未提交变化保持。固定59e测试快照和原Run不改，实际Worker正常生命周期及全部原未验责任继续保留。实际本地提交与原生检查点在仓库外root-main-integration-r1记录；没有推送或部署。
