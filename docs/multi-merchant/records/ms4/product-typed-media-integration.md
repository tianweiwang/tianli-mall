# 通用商城商品媒体联调检查点

本工作分支是 `feature/product-typed-media-normal-20261024`，从已推送的 `41c0d795ff36ff98ed267bc49bb042e2f6e17bf8` 建立。代码采用身份以包含本记录的实际 Git 提交为准。本记录说明已接入的源码及验证边界，不是完整T4、订单链或上线验收。商品列表、SKU、规格、价格、库存仍复用原商城。

商品媒体现在有共同的实际格式/字节校验与原存储驱动接线，覆盖图片、MP4及既定音频格式；复制商品的提取、预取、生产、引用回写和最终保存也已接入候选实现。实际主体、商品及素材归属由原平台或商家事务确定，附件使用完整14字段核对。云端MIME传递和AWS PHP8.2兼容已有实现及相应正常模块证据；这不代表真实供应商上传已验收。

平台新增固定 `POST product/product/<product_id>/media/videos/upload` 与 `.../audio/upload`；当前新权限、原单件上传权限和商品保存权限继续三项同时核对。原菜单CLI只追加这两项，从15到17，保原角色复制与重复零写算法。商家新增 `POST products/<id>/media/videos/upload` 与 `.../audio/upload`，同时核 `product.write`、`attachment.manage` 和真实商品范围；新视频、音频列表仍用实际已有的 `attachment.manage`。0表示已授权创建准备，不伪造商品记录。

两个生产入口共享真实临时上传文件读取，按当前上传配置、完整内容和实际格式判断；沿原唯一事务完成物理生产、附件写入、审计及提交后重读。新视频记录type1，音频type0；旧合法本地MP4的type0表示在完整字节和归属检查后保留，旧行不改写。默认商家列表排除新音频，视频列表可选择既有合法本地MP4。原成功DTO及六字段未完成收据保持。

Root已将原31候选及两组入口、菜单和WebP Dockerfile修订整合到本独立工作区，共41份源文件（39份PHP）。当前39份PHP均已按各自精确字节对应到真实完整语法通过记录；35个命名类通过本工作区真实Composer自然加载与原框架E_ALL错误处理，未补自定义类映射。本轮两组上传及菜单源码交叉复核的finding为0。原订单链两个工作区继续固定在41c0，不将这些模块结果代签订单、履约或售后通过。

尚未完成：真实HTTP登录和两主体的上传/商品保存回读、SQL完整native14与提交/审计、17菜单首次及重复迁移和合法有限角色权限、旧图片/本地MP4的真实流程、实际云服务与静态HTTP MIME、选择器和三端页面、跨请求issued/分片及云直传finalize、类型1分类操作、平台29项、扫码自然GC及原完整T4。真实存量归属、其余私有用途、完整订单链/fresh B/H5/设备、金融与MS6发布运行要求继续保留。

支持WebP的正常模块镜像来自既有PHP8.2镜像的增量GD重编译；仓库Dockerfile只增加libwebp-dev与--with-webp，其完整runtime/vendor构建尚待后续验证，不能把该增量镜像结果外推为生产镜像构建通过。

当前仅本地联调检查点，不推送本分支、不合并主整合分支、不部署或处理真实资金。用户已停止的安全、负向、故障、并发及恢复样本保持NOT RUN。仅BASE登记人工签收，不计算整体完成百分比。

## 2026-10-25 平台商品视频跨请求增量（L2，编码前设计）

起点为 MEDIA `d7b6a5f485775a711a4e43a3e3a21af6208d36c9`；专用工作树 `H:/天俪商城/worktrees/product-video-upload-session`、分支 `feature/product-video-upload-session-20261025`。只补原 W13/VIDEO_BOUND 的平台商品本地分片到最终入库，平台图片导入、九项素材操作、扫码和最终引用门已有源码，不重写。商家分片与云直传 finalize 仍欠，不将本增量代签完整 T4。

设计：保留既有 `POST product/product/<product_id>/media/videos/upload`、菜单17项和原三项权限 AND；在同入口增加有限 `upload_operation=issue/chunk/finalize/cancel`。服务端签发随机引用，固定真实管理员会话五键摘要、商品/创建准备、实际商家及 type1 分类；每次请求沿原 writer 复核。分片取原组件3MiB、期限取已有私有暂存600秒，默认总容量16/每actor4均为可部署资源参数；总文件仍沿当前 `upload.filesize` 和 MP4实际内容资格。私有根必须显式配置且在 public/上传根之外，不回传服务器路径。顺序合并核每片和全体字节；`finalize` 消费服务器已生成内容，沿原 typed 生产、native14、审计与提交观察，不收 `video_path` 或 size0。保存开始先持久标记 processing，未知结果不重新生产；明确完成后重复 finalize 只经现有最终引用门读取原结果。清理只触及本用途确切私有文件，接入现有 GC Timer，不新建后台调度或清理正式对象。

兼容：默认分片功能关闭；未配置私有根保留旧整件上传。视频列表只给已授权选择器一个分片能力标记；启用后现有选择器大于3MiB的文件实际走 issue→chunk→merge→finalize，小文件仍走原整件上传。DTO 保持分片 `code/msg/file_path`，其中 ready 的 file_path 是不透明服务器引用，最终仍返回原 `{src}` 供既有选择/商品保存及最终视频引用门消费。显式分片请求未配置时说明暂存未配置，不静默降级。

允许 Files：本记录；`crmeb/config/marketplace.php`；新增 `crmeb/app/services/marketplace/product/ProductVideoUploadSessions.php`；`PlatformProductMediaServices.php`；`MarketplaceProductScanGcTimer.php`（同目录）；`crmeb/app/adminapi/controller/v1/product/PlatformProductMedia.php`；`template/admin/src/api/product-media.js`；`template/admin/src/components/uploadVideo2/index.vue`。无表、种子、权限迁移或新调度。

验收与任务：①实际选择器调用链及关闭时原整件分支静态核对；②跨请求同主体/用途/商品/分类、总大小/片顺序、最终完整资格及原 writer/提交接缝核对；③清理仍走已注册 Timer 且不删正式对象；④最小语法、差分与调用检查；⑤隔离正常 HTTP/真实文件/native14/审计/提交回读、自然定时清理、浏览器选择后商品保存仍待 Root 串行安排。本轮禁止 Docker、目标 HTTP，安全/负向/故障/并发/恢复样本保持 NOT RUN；静态通过不能标业务完成。

部署条件（本轮未执行）：开启 `marketplace.product_video_upload.enabled` 前显式给 `private_root` 配置持久独占0700目录；不得与扫码/售后等其它用途共根，所有App worker和原扫码 GC Timer 必须看到同一目录。原扫码 Timer 的注册、Redis cadence 和扫码私有根资格仍必需；本增量不自动注册/启用调度。默认 `max_open_sessions=16/per_actor=4/ttl_seconds=600` 可按已确认资源配置调整，降低容量不逐出已有责任。关闭 enabled 仅停止新业务；保留 private_root 时原 Timer 仍清理该用途已过期的 open/ready/complete，processing 的不确定入库结果保留原私有材料及容量，不自动再生产、不删商业附件，也不把文件与SQL说成原子。

源码候选与本轮检查：上述8个Files已接线，未提交。实际 Node `--input-type=module --check` 分别解析 product-media.js 和 Vue 的原 script，exit均0；`git diff --check` 为0。对d7原方法做逐字节静态比较：PlatformProductMediaServices原43方法有40个原文保留（只改listing/uploadVideo/uploadTyped，加2个session接缝）；原Timer17方法保留16个；原controller15方法保留14个。实际物理生产/native14/审计/提交观察及最终引用门方法未改。没有可用本机PHP命令，本增量PHP语法/Composer自然加载均 NOT RUN，原39份语法记录不覆盖新候选；Root/fresh余审待进行。没有运行候选、Docker、目标HTTP、GC、浏览器或新增安全/负向/故障/并发/恢复样本，不能据此签业务、完整T4或发布完成。


## 2026-10-25 Root语法及非作者独审证据追加（仅Source检查点）

本段仅追加已发生的验证记录。独审读取的是追加前完整8件：当时本记录为8106字节、SHA256 `8622df76fef8af0fe6ec82931c38f2128e89d570aed75ea1467a41e6f9071c41`；追加后本记录的字节不同，不将原独审代签本段。其余7件应用源码完整字节保持原独审版本，新增 ProductVideoUploadSessions.php 仍为20318字节、SHA256 `08a459199729d0aaa9a6b536b0811ad1d92c81b8c55a8f64e7a31138a1e66bd3`。原独审报告、Root状态记录及已封存材料均未改写。

Root实际PHP语法：固定已有PHP8.2镜像对本增量全部5份PHP完整输入执行纯 `php -l`，5/5通过，7个原生操作均0，容器代际前后保持，没有执行候选、创建新容器/网络/卷或产生业务请求。证据为仓库外 `H:/天俪商城/artifacts/project-organization-20261023/product-video-upload-session-php82-syntax-r1/RESULT.json`，1925字节、SHA256 `c01308cfc424bd446274fc62de5bb8a7be9c39e61d7c91b66eaa354f96427aa8`。这补充此前作者仅有JS/diff静态检查的记录，不将旧39份语法记录或旧Composer加载外推为本增量业务结果。

Root与非作者已完成源码余审，确定finding为0。非作者完整审查原8件差分、新增325行服务及实际会话/商品/归属/分类、typed生产和提交观察、最终引用、选择器消费者、原GC Timer接线；报告为仓库外 `H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R112-PRODUCT-VIDEO-SESSIONS-INDEPENDENT-SOURCE-REVIEW.json`，5655字节、SHA256 `f741d3a2869674881e043565e36ebccd77ed1431760a00ae73a813c63ce7aba2`。报告明确为Source独审，未运行PHP、Docker、HTTP、GC或浏览器，也没有编造逐输入FD/时间窗口。原两份JS script纯语法检查和diff检查0仍对应未改变的代码。

本增量真实隔离HTTP/多请求完整MP4上传、私有文件/native14/业务审计/提交回读、同引用完成态实际读回、自然Timer GC及浏览器实际选择后商品保存均 NOT RUN；本增量Composer自然加载、设备和外部存储供应商验证仍待。商家跨请求分片、云直传finalize仍未实现/未验，原整件视频上传保持。只形成可保存L2本地检查点的Source增量，不代表完整T4、19交付组或上线验收完成；不推送、合并、部署或处理真实资金。用户停止的安全、负向、故障、并发及恢复样本保持 NOT RUN，仅BASE人工签收状态不变。
