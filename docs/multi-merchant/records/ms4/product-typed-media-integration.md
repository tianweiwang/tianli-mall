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

## 2026-10-25 商家商品视频分片接线（编码前设计，待Root复核）

从已确认clean的818cf0374ae8cf4844a6b834ac8486895df4ac61建立专用 `H:/天俪商城/worktrees/merchant-video-upload-session` / `feature/merchant-video-upload-session-20261025`，原平台工作树和818cf保持。需求是原T4/W13的商家真实商品/创建准备的视频跨请求链，接入现有商家素材选择器；不变更商品/SKU/价格/库存，不扩大为云直传finalize，也不签完整T4。

设计：沿原 `POST products/<id>/media/videos/upload` 和真实merchantapi中间件，全部阶段保 `product.write` 与 `attachment.manage`。当前中间件在真实AuthTokenV2验证后写入的token fingerprint、MerchantContext实际merId/employeeId/sessionVersion、当前session_epoch，与writer锁定的真实商品/创建准备及type1分类形成商家专用用途绑定；不使用每请求变化的requestId，不接受client owner/session/path。分片issue/chunk/finalize/cancel复用818cf的ProductVideoUploadSessions原字节、同配置/容量/期限/私有根及原GC Timer，actor hash明确区分商家与平台。预备/最终消费/完成态读回均通过原商家writer再次验证真实身份与范围；最终完整MP4仍走原typed物理生产、完整native14、审计及真实提交观察。首次成功继续原11字段素材DTO；重复finalize由原ProductMediaReferenceServices的真实native14/完整物理proof复用mediaDto，不按路径猜查或由前端拼造。

实际前端：仅视频列表顶层加video_chunk_upload能力位，逐行原11字段不变。AttachmentLibrary读取严格true后，对大于3MiB的真实File经已有API封装执行issue→顺序真实multipart chunks→finalize；关闭/小文件/音频/图片保原整件链。每请求前后检查冻结merchant context及本次picker ticket有效性；只有最终11字段通过原mediaRow、实际分类/type核对及列表回读后才能反馈上传成功，原select→form.video_link→saveProduct消费者保持。暂存阶段的失败只反馈分片未完成，finalize仍保原ApiError/六键收据及未知提交提示，不自动重试/重issue/取消。

允许Files仅5件：本记录；`crmeb/app/services/marketplace/product/MerchantProductServices.php`；`crmeb/app/services/marketplace/MerchantAttachmentServices.php`；`template/merchant/src/api/index.js`；`template/merchant/src/components/AttachmentLibrary.vue`。不改平台代码、共享session类、Request/认证中间件、路由/权限种子、DB或调度，不新建框架/验证包。

任务/验收：①保原授权/商品和分类范围、稳定会话用途及同writer连接；②新链首次和完成态重复返回真实11字段并保原MP4/native14/审计/提交路径；③真实picker调用及生命周期/未知结果处理、旧整件分支和平台原字节保持；④纯Node/source检查、diff与原方法静态比较；⑤Root审完整新diff后安排PHP纯语法、实际正常HTTP/文件/SQL/自然GC和浏览器验收。本轮仅Source及无资源副作用静态检查，不Docker/PHP/HTTP/设备/目标执行，不提交或推送；运行、视觉/设备和云直传继续NOT RUN/未完成，停用样本不运行。auto-sync实际project_not_enabled，不enroll；.codegraph不存在，不初始化。

Source候选已接线，待Root完整diff复核，不提交。现有 `template/merchant/scripts/source-check.cjs` 实际纯静态执行为PASS：40个script语法、97个本地import，明确build/browser/HTTP=false；`git diff --check` 为0。逐方法原文核对：MerchantProductServices原19方法保17个，只改uploadVideo/uploadTypedMedia并加2个私有session接缝；MerchantAttachmentServices原13方法保12个，只在listMedia加能力位。原native14/审计/提交观察、mediaDto及最终引用门原文不变；共享session整20318字节/SHA256 `08a459199729d0aaa9a6b536b0811ad1d92c81b8c55a8f64e7a31138a1e66bd3` 保持，平台服务/页面、原GC Timer、配置和Request/merchant认证中间件均与818cf原字节相同。商家AttachmentLibrary的template/style未改，只接原上传/错误反馈行为；没有据此声称视觉验收。

本候选2份PHP的实际PHP8.2语法和Composer自然加载均NOT RUN（交Root串行）；实际HTTP/完整大MP4多请求/完成态同引用回读/native14/文件/审计与提交、自然GC、浏览器选择到商品保存、设备及云直传仍NOT RUN/未完成。既有平台语法/独审报告不覆盖这2份新PHP差分；本轮未运行或导入应用模块/停用样本，不把Source静态检查、候选接线或原检查点代签商家业务/完整T4/19交付组通过。

Root验证与本地检查点补记：上述2份商家PHP的完整输入已在固定PHP8.2实际php-l通过；与Licence诊断1份合计3/3、5原生操作均0，同一原bootstrap的CID/PID/StartedAt/readonly前后保持，未创建Docker资源或执行候选。仓库外fixture-and-merchant-video-php82-syntax-r1/RESULT.json为6750B、SHA256 ec37ff71b1cef7eafbef4c0c830f1999ff947ca19822edf840df56bd7c6b52bb。Root a81531完整5件diff及真实typed上传/授权writer/Request fingerprint/前端context/import等消费者已读，无确定新问题；非作者private_asset_next_gap全文5件及共享session/原producer-consumer核对finding0，独立Node40scripts/97imports纯检查0。该独审对应本追加前记录15331B；本追加只记已发生事实，其余4件应用代码原文不变，不把原审代签新追加文档。

当前仅保存L2本地Source检查点；实际merchant多请求大MP4、首次/重复finalize的真实11字段/native14/审计与提交回读、自然GC、浏览器选择后商品保存及设备仍NOT RUN，Composer自然加载未验。模板/样式保持，已完整读Control-quality，未据Source/构建声明视觉通过。云直传finalize仍未实现/未验，完整T4/19组未关闭，不合并、推送、部署或处理真实资金。
