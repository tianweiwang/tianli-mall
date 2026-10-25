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

## 2026-10-25 云直传视频具名 finalize（编码前设计，待 Root 采用）

本项沿原 T4/W13/VIDEO_BOUND 和既有 L2，目标是平台、商家浏览器真实直传云暂存对象后，由服务器凭本次具名引用完成原商业附件生产/绑定；不把已实现的“本地分片合并 bytes 后由服务器上传云”冒充直传。独立工作树为 `H:/天俪商城/worktrees/product-cloud-finalize`，分支 `feature/product-cloud-finalize-20261025`，实际起点 `b9089a5ae9d34bdeb350df774ec96b87b0eb55c6`。建立前目标目录/分支均不存在，managed artifacts 为空无可复用目标；原商家/平台工作树保持。Spec workflow 与 Git auto-sync 已读，新旧 W 均实际 project_not_enabled，不 enroll；无 .codegraph，不初始化。本步仅本记录追加设计，Root 采用后才实现；不重复向用户索取已授权阶段确认。

### 已核实接缝与保留范围

- 原平台 `PlatformProductMediaServices::uploadVideoSession:261-286`、商家 `MerchantProductServices::uploadVideoSession:140-181` 绑定真实认证/商品/分类；共享 `ProductVideoUploadSessions::command:311-321` 只消费本地私有完整 bytes。其现有 issue/chunk/finalize/cancel、600 秒责任和自然 GC 不重写、不改变本地 schema。
- `MerchantAttachmentStorage::uploadTypedBytes:708-844` 已支持 type2-7 server streamTyped 和真实完整云 GET/hash/MIME/格式/配置重核，`ProductMediaReferenceServices::assertReferences:135-182` 已锁 owner 完整 native14 并重核物理内容；平台原生产/审计/提交叶子 `PlatformProductMediaServices:552-610` 和商家 `MerchantProductServices:204-254` 继续使用。无需新附件表、迁移、权限菜单、产品/SKU/价格库存功能或通用 owner 框架。
- 旧 `StoreProduct::getTempKeys:403-418` 无本次商品绑定；旧 `SystemAttachment::videoDataSave:204-223` 信 video_path 并写 size0。两商品选择器已绕开该通用链（uploadVideo2:607/642）；本项不恢复调用该链、不修改其余 site_file/global 消费者，也不以关闭旧 global 上传替代其剩余责任。

### 两主体合同、配置与暂存状态

继续原两固定 POST 视频上传路由：平台 `product/product/<product_id>/media/videos/upload`（原新上传/单件上传/商品保存权限 AND），商家 `products/<id>/media/videos/upload`（原 product.write AND attachment.manage、真实 MerchantContext）。不新增路由/seed。新增严格有限 operation `cloud_issue/cloud_finalize/cloud_cancel`；issue 仅收原 pid 或 category_id、filename、total_size、sha256，后两项只视客户端预期，不能替代服务器真实观察；finalize/cancel 仅收原分类与 upload_reference。引用由服务器随机生成 32hex，不收 key/bucket/URL/owner/session。每阶段复用原视频 session binding 的实际平台 verified session 五键/epoch 或商家 fingerprint/merId/employeeId/sessionVersion/epoch，冻结真实 product/创建准备、owner 和 type1 分类；每次在原真实 writer 中复核，业务身份前不读文件或签发。

新增 `marketplace.product_video_cloud_upload` 默认 enabled=false：private_root 为独占持久0700非 public/runtime且不与扫码/本地分片/其它私有用途重叠的元数据根；max_open_sessions=16/per_actor=4/ttl_seconds=600；staging_storage_id 必须指向当前 type2-7、同实际凭据账号的一条真实未删除 SystemStorage 行；不接受客户端存储ID，不猜桶名，不自动创建桶/修改ACL/写种子。当前 active 存储继续作为正式目标。云暂存要求独立私有 bucket/已实测私有前缀，浏览器只获得精确对象的上传能力，匿名/CDN不得读暂存，正式生产只用 server-only 随机 key；七牛等不能在公开桶中单对象保证私有时须用真实私有 staging bucket。缺少该部署资格保持直传关闭，原 server upload 与本地分片仍可用；配置声明本身不算真实云资格证据。

冻结 actual type、staging/final storage 完整相关字段（真实 id/type/name/region/domain/cdn/access_key/status/未删除资格）和当前凭据来源指纹；指纹用现 auth stamp 的 HMAC 覆盖必要配置/凭据，不把 secret、原 credential 或可重用签名存进 session/audit/记录。stage key 只由服务器派生 `uploads/merchant/<actual_owner>/video-staging/<random32>/<random32>.mp4`，不能等于正式 namespace。签发与内容读回只用实际 HTTPS storage endpoint，禁止请求体替代、重定向或浏览器向云附带商城 Authorization/cookie；签名有效期不晚于本 session expires_at，容量/当前 upload.filesize 在签发前及消费时重核。type1 不提供云能力，配置/凭据变化不得把原引用悄悄转向新桶/新 key。

新增具名 ProductVideoCloudUploadSessions，仅维护此固定用途的0600 JSON manifest和本根锁，不复制既有 .bin/合并流程，不向本地严格 schema 塞云字段。状态 issued → processing → complete；cancelled/过期停止 finalize 但不假称尚有效的上传能力已撤销。manifest 保存 binding/actor、真实关系/配置指纹、服务器 locator、预期 size/digest、真实观察与 committed result；不保存临时上传 token/签名URL、原认证或 secret。上限计算包括未知/待清理责任，不逐出旧条目换容量。持久化沿现同用途的无链接/0600/owner/nlink/同FD/完整读回、原子替换模式，不新建通用状态框架。

### 六现有 provider 的具体签发能力与未验边界

均只给本次 key，不给桶级/前缀任意写、List/Delete/Copy 能力；真实视频内容最终必须由服务器完整读取，Content-Type/客户端 hash/ETag/SDK ACK 都不是完整 MP4 证明。六类型都纳入 Source，不以单供应商成功外推其余五种。

| type / 现 provider | 已核本地依赖 API 与拟采用限制 | 明确保留的真实验收 |
| --- | --- | --- |
| 2 七牛 | 已安装 Qiniu/Auth.php:74-120 的 uploadToken(bucket, exactKey, remainingTTL, policy, true)，scope 精确 bucket:key、isPrefixalScope=0、insertOnly=1、fsizeMin=fsizeLimit=声明 size、mimeLimit=video/mp4/detectMime；签名不发 secret，浏览器 FormData 只用返回 token 和固定 key | 真服务 MIME 识别、上传域选择/CORS、期限/大小策略、私有 staging 下载、原站/CDN需实测；policy 支持字段不等于服务已接受 |
| 3 阿里 OSS | 现 Oss.php:281-309 的 POST policy/HMAC 机制，有限 adapter 改为固定 bucket/key 等值条件（不用 starts-with），content-length-range=[size,size]、Content-Type=video/mp4、绝对 expiration≤session；浏览器表单全字段来自本次计划 | 所配置 origin/CNAME 的表单/签名、Content-Type 字段大小写、大小/期限及私有性真实验证；原 SDK signUrl 虽可 PUT，但不能把未签 Content-Length 当大小限制 |
| 4 腾讯 COS | 已有 Signature.php:55/144-196 的 createAuthorization('PUT', exactPath, [], signedHeaders, explicitExpiry)，签 Host、Content-Type=video/mp4 和 Content-Length=size，保其原 canonical/needCheckHeader；不用原 getTempKeys 的 allowPrefix=* 或整组 STS；保存并还原该旧构造器的时区副作用 | 浏览器不可手工设 Content-Length，需实际 File PUT 由网络栈发出准确长度且服务校验已签 header；CORS 必须允许实际所需头。若实际协议不支持，不放宽为无大小约束、不签通过，修订有据 provider 方案 |
| 5 京东 S3兼容 | 已安装 Aws/S3/PostObjectV4.php:39-78/105-128，以现 AwsErrorCompatibility 和显式静态 credentials 创建 S3Client；PostObjectV4(client, actualBucket, exact inputs, exact key/bucket/Content-Type/size 条件, absoluteExpiry)，读 getFormInputs/getFormAttributes。实际 CNAME action 只可按已核 storage endpoint 窄设，不能任凭 SDK再拼不同 host | JDOSS 实际是否支持该 S3 POST policy 及端点需真实验证。已核 SignatureV4.php:36-42 把 content-type/content-length 排除签名，不能把现 createPresignedRequest(PutObject)当已签精确大小。无真实支持时保持本 provider NOT VERIFIED，不能退化/虚报 |
| 6 华为 OBS | 现 Obs.php:266-300 的有限 POST policy/HMAC 机制，精确 bucket/key、content-length-range=[size,size]、Content-Type=video/mp4、session绝对 expiration；不复用宽前缀 policy，不发 secret | 真服务 policy 等值字段、期限、私有桶/endpoint/CORS 与原始视频 bytes/MIME 需实测 |
| 7 天翼 OBS兼容 | 现 Tyoss.php:399-428 同款已存在 OBS policy/HMAC，以实际 type7/真实区域桶和独立配置指纹构造 exact policy；不因旧返回type=OBS而混用type6账号/locator | type7 真服务兼容性、策略和 private staging/原站/CDN 需单列证明，不据华为通过外推 |

以上是本地代码能构造的有限签发计划，不是供应商规范或真实云通过声明；完整已核原依赖，不改 vendor/锁文件、不引入 SDK。type5 POST 和 type4 原生浏览器长度能力保留为具体待证条件，Source 先把限制及失败显式表达，不能为提高通过率改无界 token 或降格云功能目标。

### Finalize、正式对象、提交及同引用观察

cloud_finalize 先在本 manifest 锁中核当前身份/关系/配置/期限，再按服务器保存的 staging locator，通过现 MerchantAttachmentReferenceBytes 的原签名完整 GET 叶子读取（TLS/固定endpoint/无redirect/无proxy/绝对上限与超时），只取该实际对象。核真实 bytes=size、SHA256=issue预期，并走现 qualifyTypedUploadBytes/实际 MP4 解析，核真实 MIME/格式；不采用 HEAD/回调/ETag 当内容证明。再次复核真实身份/配置，持久标 processing 后才进入原 publisher，不在保存未知后重读暂存并重新生产。

原 uploadTyped 接缝继续接收完整 bytes/display_name，沿原 streamTyped 为正式 active storage 生成全新服务器随机 namespace/key并完整读回，绝不把仍可被有效上传能力覆盖的 staging key 直接写 native14。最终 key 不出现在浏览器上传计划，staging 能力不能写它；即使浏览器之后改写暂存，也不能改变已生产的正式原图。原两 writer 的商品/owner/分类锁、native14 全字段、真实 type1 视频、审计、PDO/Connection 连续性与提交后原重读保持。平台首次返回原 {src}，商家首次返回原11字段 DTO；已验证原引用门继续负责商品保存/列表选择/重复 finalize 的物理及归属证明。

只有原 publisher 实际确认提交后持久 complete/result；完成态重复只在原 writer 与最终引用门读回原结果/真实 native14，不插新附件、不再 stream、不更首次结果。六键 receipt 原三态与物理/SQL非原子事实保持；数据库尝试即unknown、未知提交保存processing/对象/容量，不自动重POST/清理/改complete。stage 的独立清理责任保留 manifest，不伪造原成功 DTO 或把“暂存仍待自然清理”写成商业 SQL 未提交；cleanup ACK未知仍不能改成已观察不存在。取消/未完成只清本次具名暂存，不用 cleanupNew 删除可能已正式提交的对象。

### 原 Timer、前端和兼容边界

仅在现 MarketplaceProductScanGcTimer::tick:145-160 的原 scan、本地 video GC 后增加本具名云 gcBatch（默认每tick最多1项；每个云 DELETE/absence 观察网络请求各≤5秒，整tick实际延迟须验证），不加 scheduler、手动GC、时钟/TTL变更或业务资金动作。disabled 只停新 issue，已配置根仍保持清理责任。过期 issued/cancelled 或 complete 的 staging 可按本次精确对象删除并实际观察；provider ACK本身不证明不存在，权限/网络/配置指纹不一致、未确知absence或潜在有效/在途写责任仍 held且保manifest/容量。processing 的提交不确定责任不自动回收。云 bucket/prefix 生命周期及上传最长完成期限是部署与EXTERNAL资格，不能把一次404写成永久不会晚到；需要真实有限上界/生命周期证据才能签云清理容量闭合。日志只给安全计数/阶段/摘要，不输出 locator/签名/凭据；完整原 Task6/cadence、扫码及本地视频正常回收保持。

平台列表与商家视频列表仅追加严格 boolean video_cloud_upload 能力，当前 type2-7 且显式配置齐备时才 true，不据此冒签真实供应商。两个现选择器对>3MiB真实 File：云能力 true 走本次 cloud_issue → 返回计划的真实云 FormData 或 File PUT → cloud_finalize；否则继续既有本地分片能力/整件分支，小文件维持原整件。issue前由实际 File 计算SHA256，仅作预期；浏览器对云请求 credentials=omit、redirect=error、no-store、只执行固定transport/白名单headers/fields，不调用旧videoCloud随机key工具。每一步前后核现冻结ctx/生命周期；传输ACK后仅反馈“视频已传入暂存，正在保存”，最终原DTO通过并列表回读后才成功。未知提交保原错误/草稿和人工读回，不自动降级/重issue/重finalize/取消；本地/云未配置的旧行为保持。涉及状态与控件前读Control-quality并在真实目标尺寸/状态观察；纯script/build不签视觉或设备。

### 精确 Files 与唯一执行清单

Root采用后的生产Files限下列14件（含本record；不新增表/列/索引/seed/权限/路由）：

1. 本 `docs/multi-merchant/records/ms4/product-typed-media-integration.md`：设计、任务、实际证据边界。
2. `crmeb/config/marketplace.php`：仅 product_video_cloud_upload 默认关闭及显式资源配置。
3. 新 `crmeb/app/services/marketplace/product/ProductVideoCloudUploadSessions.php`：本用途 identity/manifest/issue/finalize/cancel/needsCollection/gcBatch。
4. 新 `crmeb/app/services/marketplace/attachment/ProductVideoCloudUploadSigner.php`：只构造已核type2-7本次exact计划，不授权owner、不写对象。
5. `crmeb/app/services/marketplace/attachment/MerchantAttachmentStorage.php`：沿原typedUploadContext新增具名真实staging/final storage配置、指纹、签发/完整读取/精确清理适配，不改原 typed/整件/引用合同。
6. `crmeb/app/services/marketplace/attachment/MerchantAttachmentReferenceBytes.php`：有限提取现云签名叶子供原cloud与新增 exact-object presence 观察复用，原完整 GET 成功门/字节上限/MIME规则保持；absence只返回真实状态，不伪造bytes。
7. `crmeb/app/services/marketplace/product/PlatformProductMediaServices.php`：原uploadVideo/listing内新operation接线与原typed publisher/最终引用回读。
8. `crmeb/app/services/marketplace/product/MerchantProductServices.php`：原uploadVideo新operation接线，原typed writer/mediaDto/最终引用回读。
9. `crmeb/app/services/marketplace/MerchantAttachmentServices.php`：仅视频列表新能力位。
10. `crmeb/app/services/marketplace/product/MarketplaceProductScanGcTimer.php`：原tick末新增本具名gcBatch，原注册/scan/localvideo合同不变。
11. `template/admin/src/api/product-media.js`：具名issue/固定云transport/finalize，原认证请求与opaque ref保持。
12. `template/admin/src/components/uploadVideo2/index.vue`：读取新能力、现商品选择器云分流及准确暂存/保存反馈。
13. `template/merchant/src/api/index.js`：相同有限云操作与无商城凭据云transport，保原11字段mediaRow。
14. `template/merchant/src/components/AttachmentLibrary.vue`：读取能力/现视频选择器分流、ctx/未知结果/列表回读。

正常验证先复用原ISO-FULL/现Source HTTP入口能力，具体新增fixture/profile文件和外部driver身份另列Root核准后才写，不改任何冻结Run/包/断言。原媒体原12夹具/174安装、9API/native14/审计/提交/商品SKU保持，不能用只测云token构造或单provider本地签名替代真实直传→serverfinalize全链。

- [x] 核实真实缺口、现六provider/SDK能力与native14/事务/选择器调用点；创建独立b908工作树并确认原W保持；本record设计追加。
- [x] Root全文阅读并采用本设计及14件范围，尤其private staging资格、type4/type5精确限制待证、stage→server-only正式生产、GC held/生命周期责任；进入Source实现，不重复问用户。
- [ ] 按14件限定实现；保当前本地session/原typed producer/最终引用门；独立Source复核全部新差分和SDK/调用关系。
- [ ] 实际PHP8.2语法/Composer自然加载、两前端纯解析/适用build与diff检查，由Root串行安排，不执行候选或用旧语法报告冒签。
- [ ] 新clean Source/fresh ISO-FULL正常HTTP真实actor与本次File：签发→真实直传→完整暂存bytes→正式云对象→native14/audit/commit→列表选择→商品保存→同ref回读；每type2-7单列真实供应商环境条件，不能凭stubs/签名输出计供应商通过。
- [ ] 原正常全DB/DDL/hash/counters、Redis完整值/type/tags/absoluteexpiry、public/runtime/private文件、完整云对象责任与原product/SKU/URL/全部media API保持，原Timer自然清理与held/容量事实按实际观察；真实合法平台和商家角色分别验证。
- [ ] 真实平台/商家浏览器选择器、大小/进度/暂存与成功反馈、返回/关闭/草稿/ctx生命周期和目标尺寸；HTTPS/CORS/六供应商原站CDN/设备/发布外部验收分层保留。
- [ ] Root普通TERM/同代自然exit0/nonforce only-own关闭、独立zero及原生双流读回；后续本地提交/推送/合并/部署按既有授权与Root串行决定，不据Source关闭T4/19组。

本步仅工作树与设计记录，全部业务代码/签名实际生成/PHP/App/HTTP/云/IAM/ACL/lifecycle/Browser/GC及新实例收尾均NOT RUN；未读取真实credentials、未访问外网、未改vendor/锁文件/原W或canonical C-D。用户停用的安全/负向/故障/并发/恢复样本仍NOT RUN，仅BASE人工签收不变；当前未提交或推送，不宣布完整T4或上线完成。

实施前接缝补记：Root已采用上述设计。原七牛 SDK 的 Region::queryRegion 使用无scheme默认网络查询且不能从实际配置自动证明新区域上传域；本项不调用该隐式查询。配置增加 qiniu_upload_url/qiniu_rs_url 两个默认空的真实HTTPS上传/管理endpoint，只由部署显式绑定并纳入原配置指纹，不硬猜区域/信客户端。GC增加 upload_completion_bound_seconds 默认0；只有部署已通过真实供应商证据确认有限在途完成上界后才能填写正值。在0/有效期未过/当前实际时间未越过expires_at+已声明上界/配置变化/任何unknown时持续held且不释放容量；越界后仍须本次精确删除及原站真实absence，不能仅凭一次404在无上界时丢责任。参数是部署声明、不能替代EXTERNAL证据；本Source不填写虚假资格，也不据正值本身宣称真实生命周期闭合。上述均在已采用config与有限叶子范围内；具体实现尚待独立Source/语法/运行验证。

Source候选实际接线补记：上述14件（9 PHP含两个具名新类、4前端、本record）已有候选，尚未提交。实际Node对两API与两Vue原script纯--check均exit0/stderr0；merchant现有source-check实际40scripts/97本地imports PASS，明确build/browser/HTTP=false；git diff--check为0。原本地ProductVideoUploadSessions整20318B/SHA256 08a459199729d0aaa9a6b536b0811ad1d92c81b8c55a8f64e7a31138a1e66bd3保持；原Storage typed生产/context/最终物理引用、原完整GET读取体、平台typed生产/native14/audit/commit及旧本地session方法、商家typed writer/mediaDto/commit及旧本地session方法均逐原方法完整bytes保持。仅云签名提取为同一原GET leaf并保其异常转AuthUnavailable门；默认关闭时新增能力判断不额外调用sys_config。作者余审补了真正空dict的JSON对象形状、七牛实际签名policy的scope/size/mime/deadline回读、落盘fd/命名文件连续性；没有运行候选或把这些Source观察当业务证明。

候选现在交Root/非作者做完整Source复核和9份新/变PHP实际8.2语法；Composer自然加载、实际签发、六云HTTP/完整bytes/native14/审计/提交、当前role正常链、原Timer自然清理及界限/私有性、完整DB/Redis/files、真实浏览器/布局/设备均NOT RUN。Control-quality已完整读，新增进行中反馈沿原alert/status并区别success，不据源码通过签视觉。无Docker/目标调用/secret读取、提交/推送/部署、冻结包或旧Run更改；未写canonical C-D。原七牛cn-east-2等未被当前SDK静态Zone覆盖的区域使用显式真实HTTPS上传/管理端点资格，不引入其无scheme动态query；type4 browser长度、type5 JDOSS POST、所有provider的真实policy与lifecycle证明保持具体外部门。原T4/19组和全部原要求仍未关闭，停止样本NOT RUN。

独审Source修正（先记后改，Root已采用）：非作者media_env7_review指出ProductVideoCloudUploadSessions::stat38–42返回完整lstat数组，而read54/save68做全数组严格比较；读取可更新atime，故会误把正常读取当文件变化。Root与作者实际读同14761B/75328ea2…源码确认，不是已执行的云/文件失败。仅本类stat输出收束为原STAT定义的dev/ino/mode/nlink/uid/gid/size/mtime/ctime九个稳定字段；原owner、nlink1、regular/no-link、0600、同FD、完整bytes/digest/EOF、写回及其它身份门不变，不接受atime比较或放宽原稳定字段。修正前先追加本记录，之后仅改此1个PHP文件，原其它12件应用/前端候选保持；交Root/原独审重新核字节。PHP/候选运行/云/HTTP/GC仍NOT RUN，无提交或推送。

云前端结果未知责任补记（Root已采用，先记后改）：实际两API的cloud_issue引用只留局部issued，两picker在开始即清input；finalize返回异常后没有ctx下pending，和本设计“保草稿/本次定位，不自动重放”不符。仅原4frontend件补具名当前ctx的安全pending：服务器32hex引用、原文件名/size/SHA256、必要File及phase/既有安全六键receipt；issue后即交当前picker，finalize调用前标unknown，异常保留本次引用/草稿供人工核对并阻断该picker自动或新发重传。不得存plan/token/签名/商城凭据，不自动issue/finalize/cancel/切本地；只有原最终DTO及列表同对象已核成功才释放，ctx关闭/失效隔离释放。显示沿原只读消息/alert，保核对编号与准确状态，四脚本parse与merchant纯检查另跑；实际browser/故障/网络样本不运行。

Signer普通GC精确有界修正（独审发现、Root采用，先记后改）：原type3 OSS deleteObject经过SDK RequestCore FOLLOWLOCATION/最多3retry，type5 AWS默认retry也使单次timeout5不等于整个删除5秒。实际OSS signUrl1854–1859仅GET/PUT，auth2039也是private，不能假称公开DELETE签名接口或用subclass/Reflection。采用本Signer内唯一对象薄适配：公开signUrl(GET)取得SDK构造的HTTPS同host/port/path、仅OSSAccessKeyId/Expires/Signature的规范URL；核同实际AK/有效短期expiry，用已核2160–2171的空MD5/MIME与实际ASCII bucket/key先重算GET并hash_equals SDK Signature，只有完全一致才换固定DELETE重算Signature。2170–2172 PREAUTH返回早于2185 send_request与2197retry，故此GET构造不会发网；任何额外header/query/STS/canonical差异失败held，不猜宽签名。AWS只getCommand(DeleteObject)+createPresignedRequest，核实际DELETE方法、同HTTPS host/path及仅host签名后交原request。两者网络仅经过本Signer原CURL一次TLS/no-redirect/no-proxy/5000ms，不改vendor/其它producer/absence/held门、不新增库；实际云支持/删除/GC仍NOT RUN，需独审新diff和实际PHP验证。

Signer签名query范围补记：实际S3SignatureV4::presign37–50添加UNSIGNED-PAYLOAD，SignatureV4::moveHeadersToQuery365–379移至query；DELETE适配仅接SDK当前7个明确X-Amz字段、host签名、固定30秒、同实际AK scope与同HTTPS对象，拒重复/额外/STS，不用parse_str合并未知字段。这里是Source约束，不是实际JDOSS签名/删除通过。

本轮有限修正实际Source收口：四前端已具名保issue后安全pending（无plan/token/signature/auth），finalize前unknown，异常保原receipt/ref/File，原ctx失效清理、成功DTO与列表同对象核对后释放；原两本地分片方法保持，不自动重发任何写操作。修订后4个API/Vue原script纯Node parse均0/stderr0，merchant原40scripts/97imports PASS，diffcheck0；这不是故障/浏览器样本。Signer现type3先严格验证SDK GET规范query与重算GET一致才签固定DELETE，type5只SDK纯presign且核7当前query/host签名；网络归原单次CURL，未调用SDK deleteObject/execute，仍需原独审与Root实际PHP语法。所有真实provider/HTTP/自然GC/未知结果真实UI及设备仍NOT RUN，不把修正后Source计作验证通过；未提交、推送、目标或canonical更改。


Root正常SDK兼容复核补记（2026-10-25）：实际PostObjectV4构造器65行调用TimestampShape::format；实际TimestampShape25–33只接受可变DateTime/string/int，不接受DateTimeImmutable。原type5传Immutable会在正常构造阶段抛InvalidArgumentException，纯-l不能证明这一行为。仅将这一个已冻结expires参数改为SDK明确支持的原整数值，仍原session绝对期限/精确policy，不改变供应商、key、大小或身份合同；前述9PHP纯-l结果只对应修前字节。后续Root将network-none下仅用进程内动态合成凭据做六类型正常计划构造与SDK/字段结构检查，不发云、不读真实凭据、不执行商户业务；这种离线结果不证明JDOSS POST/COS浏览器或真实供应商。真实六云/自然清理/浏览器仍未验。


Root自然回收接缝修正（2026-10-25，源码依据）：原gcBatch固定ref顺序，在collect前先消耗唯一网络处理额度；旧manifest配置指纹因真实配置变更而不再匹配时，collect首个只读资格检查即抛错，旧项永久held并每tick挡住后续有效项。复用原currentCloudVideo严格配置和cloudVideoObject精确key的无网络资格叶，配置/关系不符保持held并在消耗额度前跳过；符合者仍先消耗唯一额度再collect，网络失败也不允许扩大每tick处理数。collect内部原双presence/DELETE/配置重核/absence和processing/完成期限/容量保留不变，不新增队列、cursor或宽松配置。此为正常新配置session自然回收可继续推进的必要Source修复，未运行云、故障或恢复样本，真实自然回收仍待验。


Root实际验证与本地Source检查点（2026-10-25）：最初9PHP纯-l全0后，Root修正TimestampShape整数参数及GC无网络资格后，两变化GC PHP又在固定PHP8.2.33纯-l2/2、5native全部0；结果6561B/5a8aaf62758120c362f8a964d2acd8b66ae67c2b2f469cbb5429f0e228d1d0b9。当前Signer亦在新离线正常检查中实际-l0。network-none/只读公开app+vendor+crmeb源码、进程内动态合成凭据的正常6类型计划构造实际0，Qiniu/COS/AWS真实本地SDK及另三类型有限policy结构、精确key/size/expiry/签字段均经当前代码执行观察，Signer+941HTTP3共4PHP纯-l均0；实际5native的完整created/terminal同PID/wait双流2242B及输入摘要已Root6daf10读回，RESULT8675B/c166231b106fe3935e8912a4e07e734924fe761e666584dfee28ee70745ebe1a。检查器无云请求、没有真实凭据/配置/业务初始化，helper自然Exited0/PID0后nonforce移除。首个离线helper在PHP opcode只读锁初始化失败254且未进入契约，原输出保持、普通nonforce退役；新隔离helper只增加有界/tmp与关闭CLI opcode完成离线检查，未改应用配置。

非作者已全文复核原14路径及stat/前端pending/单次DELETE/整数参数/配置held回收修正，最终有限差量可精确逆回上一版且无新增确定Source问题。前端原4脚本解析与商家40scripts/97imports仅静态证据。当前保存14件为本地Source检查点；所有真实type2–7云上传、暂存私有性/CORS/期限/站点与CDN、COS浏览器长度、JDOSS POST、自然云回收/生命周期上界、页面/设备/实际商业事务仍未运行或验收，不据离线计划代签供应商通过。原T4/19项/完整A-B-H5/真实支付/MS6目标不缩减，未推送部署。
