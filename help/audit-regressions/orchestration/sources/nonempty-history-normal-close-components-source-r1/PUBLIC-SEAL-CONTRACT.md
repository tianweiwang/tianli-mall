# public seal 唯一字段合同（Root已采用，仅Source，尚无Run实例）

Root已完整读本文并采用该exact13和native安全视图，作为正常历史父生成器与Host消费者唯一Source接口。此采用仅是Source接口设计事实，不是Run、运行ready或用户验收；CU未来消费者固定同版。没有任何未来HEAD、freeze、producer CID或内容hash实例。未绑定当前Source/consumer完整资格时入口继续拒绝。正式输出固定create-only `/run/audit/evidence/legacy-history-producer-result.json`，完整原私receipt、raw file/stream/key/value与私stage结果不落Run。

顶层exact13键（所有其它键拒绝）：

1. schema：int1。
2. status：固定 `LEGACY_NORMAL_HISTORY_COMPLETE_ONLY`。
3. run_id：实际同原Run。
4. source_sha：实际同原currentSource。
5. legacy_source_sha：原477完整SHA。
6. freeze：原实际`path/bytes/sha256`，完整join原Source-binding。
7. producer：exact5 `container_id/created/source_binding/control_files/native_parent`。created/source_binding是原实际公件`path/bytes/sha256`；control_files为原精确11路径的真实Source descriptors，完整原Source和两个旧Source树的资格另由原函数严格完成；native_parent仅`pid/ppid/pgid/sid/starttime`。
8. http：原 `nhe_history_http_run` 的完整安全返回，保其原scope、37条http_steps、两个root/refund/review/fixture身份及原business_acceptance=NOT SIGNED；不混入state.actor_accounts、cookies或headers/body。
9. identities：原 `nhep_history_identities` 返回的独立exact4，严格与原http counterpart交叉核；不从HTTP反选完整expected行。
10. business_frames：exact4列表，每项exact3 `sequence/step/complete_original_frame_verified`。sequence/step固定为13/refund_family_pay、19/review_family_pay、22/unshipped_refund_apply、32/customer_purchased_item_comment。true来自原37实际observer在该四序列通过完整原subscriber帧、whole7和最后finish的调用事实；它是原Source路径的已验证事实，不是额外公开counter或新消费接口，不能从未运行的常量列表声称成功。
11. normal_close：exact5 `private_native_receipt_complete_verified/children_views/no_extra_view/files_complete_verified/only_parent_and_no_listeners_verified`。bool必须在完整原私receipt及下述独立五树/native模型通过后为true。children_views精确http/channel两个安全视图；no_extra_view只取已完整核验的原receipt允许native字段。
12. expected_m4m：原纯bridge完整原shape names/tables/redis/modes，依据独立accepted37及writer全停后真实TIME，严格join实际full DB/Redis后生成；保原164表/列序/native元数据/DDL/原投影与原namespace绝对期限，不含raw业务rows、raw Redis keys/values。
13. seal_redis_time：原真实atomic Redis TIME二字符串，与expected_m4m所据独立Redis snapshot相同；不能从after复制未知keys或跨四CLI续期。

children_views每项仍exact10字段，但明确只是安全view：

```text
stopped: true
group: exact4 leader_pid/pgid/sid/leader_starttime（全取原实测）
remaining_members: []
listener_open: false
first_terminal: exact7 pid/running/signaled/stopped/exitcode/termsig/stopsig
term_attempted: 原bool
stop_signal: 原null或实际正常SIGTERM整数
proc_close_exit: 原int，含-1原样保留
streams: exact2 stdout/stderr，每项仅exact2 bytes/complete_verified:true
waits: 原list，每项仅exact2 pid/status原int
```

公开group/PID只是既有资源native事实；不发布command/env/cwd/任意proc扩展字段。first_terminal不把unknown -1硬填0、不把signal退出说成exit0，不删真实wait记录。私receipt完整原10键及每个原stream bytes/hash必须先与原closed handle和终局命名文件全bytes/native9join，才允许视图的complete_verified=true；公开中不能含sha256/raw/完整stream path。

no_extra_view保持原receipt顶层exact11：schema/scope/run_id/quiet_ns/started_ns/ended_ns/zero_returns/http_closed/channel_before/channel_after/subscriber。http_closed原exact5 group/first_terminal/proc_close_exit/remaining_members/listener_open天然安全；group和terminal用上面的allowlist。两个channel proof视图exact4：native（原7字段）、group（原4）、members（每项只pid/ppid/pgid/sid/starttime）、listener（仅address/port/inode/owner_pids；owner_pids独立从原已核owners提取排序去重）。subscriber只原exact5 socket_id/local_endpoint/remote_endpoint/metadata_before/metadata_after，各metadata原exact4 unread_bytes/eof/blocked/timed_out。原完整receipt必须先通过原完整同socket/10秒/原HTTP关闭/Channel前后同身份门；view不能代替原no-extra算法。

files_complete_verified只在原完整两私树/旧public/两个runtime、原四私streams和workerman.log都严格等Source独立期待后设true，不能以安全view的存在代签。只发布这一必需事实，完整私树所有文件正文摘要也不发布。

Host必须另外核该原producer实际native exit0、同created/register/bind/启动/终态Source公件及原freeze/control全pins，然后普通退役同CID；以上status本身不是当前四CLI或全商城通过。原m4m_main运行四CLI后完整snapshots.before还要严格等expected_m4m，后继current-reader和enabled=true等原责任仍保留。若Root当前3控制或尚未完成的startup/reader/consumer Source未绑定，准确报告named UNBOUND并保持入口guard，不造任何futurehash。
