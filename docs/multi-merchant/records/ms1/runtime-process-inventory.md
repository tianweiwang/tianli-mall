# MS1 常驻进程盘点（源码声明与ISO普通worker观察）

源码起点：`fe9ab18f2555d2cbc9f21a1dcd1a61a9c704a39f`，Windows功能worktree `H:/天俪商城/tianli-mall--mm`，分支 `feature/multi-merchant-v0.1`。日期2026-10-12。仅核对配置和对应实际实现，没有连接生产进程或读取业务数据/凭据。

| 入口 | 配置中声明 | 源码对象/进程边界 | 实际观察到 |
| --- | --- | --- | --- |
| FPM | `docker/php/supervisord.conf:17–29`，`php-fpm --nodaemonize`；optimized compose的crmeb_php，镜像tianli/crmeb-php:acceptance | FPM子进程可连续服务请求，PHP请求对象作用域不能当作跨请求共享容器；本次没有执行FPM边界观察 | 未观察生产PID/镜像/请求复用；MS0b ISO使用builtin HTTP，不能代替FPM |
| 原队列 | Supervisor的queue声明 `php think queue:listen --queue`，目录/var/www；`crmeb/config/queue.php`默认Redis，默认队列CRMEB+环境后缀 | 实版Listener在循环中每轮启动 `php think queue:work ... --once` 子进程；同一listener PID不等于同一处理job的PHP PID | 未观察生产PID或任务；不能用这项声明证明M05的相同job-worker PID |
| 新marketplace队列（ISO已接线；生产默认停用） | 独立 `php think queue:work --queue=marketplace --sleep=1 --tries=1`，Supervisor默认autostart=false；ISO audit-worker同命令加原Console标准--quiet | 不带--once的真实队列worker连续处理；任务局部上下文仍待Task8实现。两个普通空任务由同一真实job-worker处理 | 普通RUN20261011202953d2f1c8、源码bcd04b12：同一容器PID=1/start_ticks=38334384，两个原生任务正常delete/引擎quit；worker exit0/restart0，T1W01 PASS。没有生产PID观察或启用 |
| Timer | Supervisor timer：`php think timer start`；Timer命令设置runtime/timer.pid及timer.log，经SystemCrontabServices执行定时任务 | Workerman定时常驻进程，回调复用进程及应用容器的可能性由该路径确定；本阶段不注入商户全局上下文 | 未观察生产PID、调度周期或任务使用情况 |
| Workerman notice/chat/channel | Supervisor workerman：`php think workerman start`；Workerman命令和config/workerman.php声明admin/chat/channel服务，runtime/workerman.pid/log | 连接和回调常驻；notice登录缓存管理员身份，chat仍为原顾客/客服链。新商户上下文不能存入静态/复用服务 | 未观察生产PID、连接或镜像；MS0b移除两条完整消息打印的源码/lint证据不是WORKER验收 |
| QueueTrait同步分支 | `crmeb/crmeb/traits/QueueTrait.php:42–69`：queue_open且Redis时push，否则直接创建job并调用方法 | 同步调用也须由job入口重建任务局部上下文；不能仅保护异步消费者或把调用者请求ctx当作授权载荷 | 未运行本阶段任务；Task8正常调用证据另记 |

`help/docker/docker-compose.yml` 的旧PHP镜像声明是PHP7.4；optimized声明是本地PHP8.2 acceptance构建，demo-server通过必填PHP_IMAGE指定镜像。它们是三个配置入口，不能按文件存在推断实际发布采用哪一个。Task1 ISO继续使用已批准PHP8.2本地镜像/专用MySQL8/Redis、内部网络与新卷，不拉新镜像、不暴露宿主端口、不接共享服务。

源码依据：`docker/php/supervisord.conf`、`docker/php/Dockerfile`、三份help/docker compose、`crmeb/config/queue.php`、`vendor/topthink/think-queue/src/queue/Listener.php:70–106`、`crmeb/crmeb/command/Timer.php:36–67`、`crmeb/crmeb/command/Workerman.php:59–106`、`crmeb/crmeb/traits/QueueTrait.php:42–69`。Queue组件已在composer.json声明并随tracked vendor存在，不新增依赖。

普通worker基础能力已观察：按manifest登记真实容器、内部网络、image、专用runtime卷和私有DML目标，在独立队列连续处理两个不含商户身份的正常空任务。实际image为sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a；完整回执、源文件hash及无副作用结论见[task1-worker-summary.json](task1-worker-summary.json)。首次RUN20261011195406edef53因旧Carbon/PHP8兼容实际FAIL，修复后独立RUN的PASS作为第二行追加，失败与日志保留。两RUN各4容器/1内部网络/11卷已按manifest清理，剩余资源为0，临时install.lock已移除。

本次保留原queue时钟与依赖版本，只回移Carbon2.20的必要PHP8原生签名/错误状态兼容；不是Carbon2.73升级，PHP7.4运行未观察。此项只建立Task8普通A→B核对的运行前提，不复现异常、伪造上下文、故障、并发/断连或恢复场景；不会恢复被用户停用的综合套件，也不宣称生产队列已部署、商户上下文已实现或完整M05通过。
