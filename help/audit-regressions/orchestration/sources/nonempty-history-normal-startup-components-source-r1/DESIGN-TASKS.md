# 原完整正常37前的独立 startup effects

本片接已独审通过的 LOAD-01 R2：唯一 producer before 为 `nonempty-history-normal-parent-components-source-r2/candidate/legacy-history-producer.php`（1052008B/6a01674bdabfdfb2ff40a7df915642889373f05ee948aeae204429f5e84be48a）。只在新外部 Source 目录实现；原 R1/R2、finding、Git 归档、W/Val、Run、canonical 与 vendor/App 不改。原完整37/四业务帧/原四CLI/当前reader/true-enabled/完整公开seal/正常close-zero责任不缩，原 direct169 与 Host 总准入仍拒绝。本片不执行应用、DB/Redis/HTTP、PHP 或资源；必要编译验证由 Root 安排。

Root 已在本片设计期间正式完成该 before（LOAD-01 R2）的固定 PHP8.2 纯 `-l`，八项 native 与 outer native/wait 全0，单 RO 候选、network none、nonroot、128M，原语法容器普通移除且标签 before/after 空。实际记录为 `../nonempty-history-load01-parent-php-syntax-actual-r1/RESULT.json`，Source job 为 `../nonempty-history-load01-parent-php-syntax-source-r1`；完整原收据仍由 Root 回读并稍后给 pin。本片尚未声称新 startup successor 的 PHP 语法已检查，也不改已冻 R2 的历史 NOT RUN 字段；该结果只签语法，不是 App/37/actors/业务运行。

## 已核真实来源及必须处理的顺序

old477 原 Worker::runAll 顺序是 init/touch log0622→parseCommand 一条全路径 DEBUG start 日志→原单worker init/listen→saveMasterPid 纯十进制无LF→displayUI 全七行→forkWorkers→monitor。原 Channel master+worker 真实在场且原 ready roundtrip 已结束，才能证明 UI 的 stdout flush 已发生；不能把其 stdout 当空，也不能遗漏首条裸 start 行。显示 socket 为 `frame://127.0.0.1:40003`，proto 才是 tcp；六列原默认宽度与 raw status 标签长度先扩宽，再由普通文件 stdout 的 safeEcho 去 markup，无 ANSI。唯一 user 来自同 native UID 的 passwd name，不能从 AFTER 输出选择。

公开官方 PHP8.2.33 Source 已完整下载且 pin 到真实 commit651db3ebfa622cae0c4e6b39766812efbd274ced，保 LICENSE，文件为 `../nonempty-history-normal-startup-public-source-r1/upstream/sapi/cli/php_cli_server.c`（85378B/6843266e3a7458e89c74c6d133ec21ae715da8c0708e718965db65fa560d4e63）。其 Linux system_time258–266→log_write759–785→logf1253–1272→startup2864–2870 说明 HTTP 首行仅 stderr 的 `[24byte UTC asctime] PHP 8.2.33 Development Server (http://127.0.0.1:8000) started` 加 LF；workers env absent 是原单进程分支，router 在 ctor 只保存并在请求时才执行。原 `nhep_http_time_reference`/`nhep_http_asctime` 已提供完整 UTC 文件/时区、weekday/date 与真实 wall 窗重建。

PHP C ctor 在首行写出前已 LISTEN，因此原 child_start 的 native listener ready 不能替代首行门。本片必须增加一个固定、有限、只读的 startup-ready 接缝：同原 HTTP stderr 命名文件和原持有句柄的 identity 完整绑定，不 seek 原 writer fd；短暂完整读取尚未结束时只继续原5秒尺度内的被动等待，完整首行必须逐字满足独立模板与真实 UTC wall 才返回。无 HTTP probe、额外消息、订阅重连或新 actor。之后才做原全7 observation，避免用调度时间或一次 LISTEN 当业务初始化已结束。

## 必要接口修订

R1 预留的 `plan(qualified, prepared)` 无 observer/Redis，无法在任何 child 前用原独立 observer 真实捕获并 join prepared。实际改为：

```text
nhep_normal_startup_ui(user, phpVersion): string
  纯 Source 构造原 start 裸行与完整原七行 UI，不调用 vendor。
nhep_normal_startup_plan(qualified, observer, redis, prepared): private plan
  原独立 full164/Redis/五树 BEFORE，严格 join 原 prepared；Source 期望先固定。
nhep_normal_startup_ready(qualified, children&, plan): private receipt
  同原 HTTP stdout/stderr/原 handles/native，完整原生首行门；只读且有限。
nhep_normal_startup_accept(qualified, observer, redis, children&, prepared, plan,
                          ready, actual7, privateReadback): independent expected7
  用 BEFORE+已定消息+受限定 native 变项构造完整期待，再由 main actual7===expected7。
```

main 只按实际新接口改 plan 的参数、在原 HTTP start 与原 observation 间调用 ready、accept 增加该私 receipt；最前同Source dependency guard 增列 ready。原完整前后业务调用、首错误/cleanup、公件、四factory/37/finish/close/seal不变，169字节尾仍原样。新增函数不开放 direct 入口或改变 Host。

## 独立期望的闭集

- BEFORE full164 DB 和五树严格等 prepared 原独立期待；Redis 仅原 snapshot+empty changes+真实 atomic TIME 的自然到期推进。任何 child 前证明 namespace 只余原父、固定两 listener 均不存在、channel 目录与四 stream 叶均不存在。
- 固定 PHP8.2.33/Linux/LF、原 TZ/TZDIR/workers env absent、原系统 UTC byte/stat 参考；同 native real/effective UID/GID 与非setuid PHP binary，passwd name 在 child 前读取。现有 Channel entry 与原 exec/Source 确保同原身份；不改用户名/系统时间。
- 唯一新增 namespace 是 channel 目录、其 workerman.log/pid 和四个原 `legacy-{http,channel}.{stdout,stderr}` 文件。root 只允许新增一个子目录引起 nlink+1/size不减/实际窗内的mtime/ctime；新 channel 目录0700/nlink2，log0622、pid0600、四stream0600、单链接、同原device/owner/group，各native inode独立正值且在原真实创建窗内。其余全部 namespace/bytes/stat9 完整保持。
- 原 `/tmp/_run_nonempty_legacy-history-channel.php.status` 在启动前与完整启动后均明确不存在且非 dangling link；不删除它、不加第六观察树，也不忽略原 stopAll 的条件 unlink 分支。
- 四stream的实际原 fd created identity 必须与完整全树/私raw readback相同。HTTP stdout 与 Channel stderr 恰为空；Channel stdout 必须等 Source 提前构造的完整八行；HTTP stderr 必须等先前同原 file/handle/readiness receipt 的独立首行。log 恰一条同原 master PID 的 UTC start 日志，timestamp 仅在限定真实窗内绑定后重构；pid 的完整 bytes/hash 只由原master十进制生成。
- 期待 native7 只用各原 owned handle 的原 PID 加固定 live 状态构造，完整 group/worker/listener/native-before-after 校验保留，namespace inventory 恰父+HTTP+Channel master+Channel worker四PID。原同subscriber state/socket/Run/ready nonce身份严格验证，不新发或偷读业务帧。
- 启动不执行 oldApp HTTP，因此 full DB 完全等 BEFORE；Channel ready 只RAM/socket，Redis只自然expiry。所有private/public/旧root runtime保持，完整 expected7 来自计划和固定模型，不直接复制 AFTER。所有 private plan/raw/file hash/receipt 都只返回 RAM，不加公开seal字段或输出。

## 任务与接受

先实现四完整函数与点名 main 有限接缝；标准正逆、原164其它定义/169尾保持、真实 Source 13/9/11 overlay、必要输入 pins/具名调用闭集齐全后交独立child窄审。启动正常5秒门与 full snapshot/私流原 Source 责任不能因实现困难放宽。若来源发现尚未建模的真实分支，保候选/guard并报告，不以 AFTER 任意append或部分流程换通过。Source/PHP语法、运行、业务与人工签收继续分开。
