# Task8 普通功能草稿的准备与覆盖

此目录只有仓库外草稿，未执行 PHP、Node、浏览器、Docker 或测试。脚本安装位置为仓库 `help/audit-regressions/isolated/ms0b-login-download-functional.php`，只 require 同目录 harness 与真实 autoload，不加载停用综合套件。支持 `--only=all`（默认六项）、`--only=q6`（前四项）、`--only=q7`（后两项）。所有准备和清理均单列证据，不作为功能 PASS。

前提为已登记的新专用 ISO-FULL RUN：158 张实际表、独立 MySQL/Redis、DML 账号、内部网络、无宿主端口和 Docker socket、source/vendor 只读。根代理提供本 RUN 专用共享 `backup-output` 卷，精确挂载 app/runner 的 `/workspace/crmeb/backup`；runner 继续只读观察实际 app-runtime。脚本选择既有 supervisor 接受的数字 `audit:http-lang:RUN:execution:` 缓存前缀，不修改控制白名单。私密值只留内存；证据仅检查布尔、摘要、计数和合成行 ID。

准备仅插入明确合成行，不修改安装种子。两行 `eb_system_admin` 分别为正常随机账号（level=0，真实合法 HTTP 登录）和账号字符串 `0`（仅真实只读 DAO 查询，不发送该账号的登录请求）。HTTP 使用本脚本刚生成的合法随机密码，避免旧 DAO 把 `0` 当空筛选后意外产生错误登录。记录所有实际创建 ID，finally 删除这两行，原主体统计随该自有行删除，不回拨 AUTO_INCREMENT。

Q7 另插入一行 `eb_system_crud_list`（含本 RUN 识别值）和一行 `eb_system_crud`，后者指向已安装的 `system_crud_list`。field 的 table/from/column/search/association 数组采用生成器接受的空数组；不会创建表或调用 CRUD 创建接口。`make_path` 明确提供 model/dao/service/validate/controller/route/api/router/pages 九项。正式生产者 `SystemCrud.download` 的 653–660 行将它们改写到 `backup/systemCrudList` 内，services 的 makeFile 1073–1145 行只读模板/元数据，isMake=false 时不写文件。准备先使用该真实只读预览逐个确认九个目标；正式调用仍是实际 HTTP `GET system/crud/download/{id}`。预览缺项、路径越界或已有同名输出时停止准备，不用可写源码兜底。

备份夹具使用真实 `MysqlBackupService`，setFile 指定新时间名，调用其 `backup('eb_system_crud_list', 0)` 正常生成 gzip 文件。该方法 354 行执行的是 `SHOW CREATE TABLE` 读取；359–360 行的 DROP/CREATE 仅写进备份文本，从不执行。插入一行列表数据是必要条件：旧方法仅在表 count 非零时实际 write（366–380 行）。不调用导入/还原，不扩权限，不造 DDL。由原 `backup/download?time=...` HTTP 生产者读取该真实备份并返回 bcrypt key，然后 RFC3986 完整编码 query key、带当前 header 首次消费。

六项正常场景：T8F01 info 的数字 login_captcha=0；T8F02 合法 HTTP 登录、原始声明/签名、真实会话八字段/HMAC/digest/version/exp+60 和精确统计；T8F03 account0 DAO 命中该合成行的所有实际列；T8F04 真实 blockPuzzle captcha 创建、两张可解码图片与新挑战缓存类型/期限（不读答案、不解题、不调用 ajcheck 或二次校验）；T8F05 真实 CRUD 生产者与一次 route header 下载；T8F06 真实备份生产者与一次编码 query header 下载。旧 CRUD 返回 md5(path) 会记录新 key 契约 FAIL，但继续其正常首次下载，不能以预检查阻断基线业务流程。旧 query 解包 TypeError 仍为真实 FAIL。

每个实际窗口观察全部 158 表、四张目标表（含 system_log）全部行/字段、所有 Redis 值及 TIME/PTTL 绝对期限、app/runner 全 runtime 文件与 shared backup 文件/目录。冷缓存仅接受 info/login 的明确 setting 名和对应 DB 值，config/data 期限必须为永久、四个语言映射为本次窗口内实际 SETEX3600 原期限；tag 精确增加这些物理键。既有账号 HASH/原版本/永久期限必须完全不变，只有首次账号真实初始化可放行。下载生产者只允许本次 file key 与 crmeb tag 的准确增量，SETEX300 原始期限；首次消费只允许该 file key 消失，tag 和文件字节保持。验证码仅以真实 BackgroundVo/TemplateVo 从原公开图像资产只读生成精确 pixel map，无 cache callback、不读 challenge point、不调用求解；期限仍永久，不豁免前缀。文件输出逐个匹配真实预览，ZIP 必须恰好包含九文件的精确字节。

HTTP transport 仅允许固定 info/login/captcha 创建、当前合成 CRUD ID、当前备份时间及本次实际返回的两个 download key，固定目标 audit-app:8000、禁用 curl 跳转和代理；不接受任意 URL。CRUD 返回的绝对链接只用于协议和 key 观察，不连接其中主机。浏览器桥由根代理另行负责：需选实际本站 origin 并保留真实 producer URL，不以外部 URL 重写或 API 假数据代替。

finally 仅删除登记的新行、实际自有 key 和逐个初始不存在的自有文件/空目录；核对原始全快照及期限。整个真实清理捕获 Throwable，只记固定类名与 basename:line，并设置 cleanup_failed；所有原严格断言与具体差异仍保留，失败日志不擦。随后本脚本独立写唯一原始 JSONL，逐行写 harness 已产生的原记录，不改变六项状态；分开 summary 记真实 counts/phase/source/准备与清理状态。最终 native exit 同时考虑准备失败、清理失败、记录失败、实际 FAIL/BLOCKED 和无 case。启动 guard 与前置断言继续在外层，不吞隔离失败。未经执行不能声称语法检查、正常基线或功能验收已完成。错误/旧凭据、缺验证码拒绝、URL JWT 拒绝、重复下载拒绝、故障、并发和恢复仍按用户要求跳过。

冷缓存 setting 的实源列表：queue_open（QueueTrait 77、AdminLoginListener 31），site_logo_square/site_logo/login_logo/site_name/nncnL_crmeb_copyright（SystemAdminServices 251–256），new_order_audio_link/model_checkbox（231–238），site_url（common.php set_file_url 295 及 SystemCrud producer 705），param_filter_type/param_filter_data（common.php filter_str 674–677）。仅 data_admin_login_slide（GroupDataService 42–47）及 sys_lang_source_map/type_id_zh-CN/lang_type_data/lang_zh_CN（common.php 1119–1141）可作为对应正常缓存。HTTP 明确 Cb-lang=zh-CN，因此不放行其他语言或任意 suffix。

正常生产者审计来源：AdminLogMiddleware 36 调用 recordAdminLog，无 method 过滤；SystemLogServices 47–65 写 adminapi/module、当前 admin id/account、system 类型、实际规则与可信 IP/时间。Think RuleItem 136–143 将 CRUD 规则标准化为 system/crud/download/<id>，备份为 backup/download；page 由同一个真实 SystemMenusDao.getVisitName（80–82）只读查询，缺名保持未知。每个 producer 仅允许恰一新增 log 行并逐列核对 merchant_id=0；历史行逐列保持，finally 仅删除确实匹配的本次新 log ID。两 Public download route 没有 AdminLogMiddleware，消费窗口不得新增日志。
