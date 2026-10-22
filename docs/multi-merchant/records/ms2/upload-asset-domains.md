# 上传资产域与旧消费者兼容设计（T2）

状态：**部分源码已接入并本地提交，运行和验收未完成**。原设计依据 Source `13479ad620e30948db33caab31c573a1945a5fe4`，细化上传归属设计唯一 T1–T8 清单的 T2，不另设执行清单。T4 平台商品素材/扫码和 T5 已购评价图片的先行源码已接入；具体提交及分层验证见文末按日期追加的记录。三表 DDL 仍待隔离安装验证，完整 T1–T8、业务启用和人工验收未完成；不能将先行代码或语法检查计作完整功能验收。

关联：[实施规范](../../00-implementation-spec.md)、[MS2计划](../../07-plan-ms2.md)、[MS4–MS6计划](../../09-plan-ms4-ms6.md)。原 Task1 四框、真实历史确认、完整五根门、三端与金融验收仍按原义保留。本文随候选移入仓库后自洽，不依赖机器上的外部 artifact 路径。

## 资源域与边界

商业商品素材/分类继续使用 `system_attachment` / `system_attachment_category`，`mer_id` 只能来自真实商家、已授权目标商品或创建上下文；0/NULL不是自营、平台或公共。顾客作者、订单商品经营商家、平台权限和会话参与者不同，不能由UID、module_type、pid、管理员或图片中的PID推经营归属。

拟新增顾客资产域、平台资产域及平台独立分类表。评价图是顾客内容，经营关系来自本人已购root/item，商家不能编辑原图或原文。v1私有售后继续沿既有 `marketplace_aftersale_file` 上传/claim/replay/read-ticket/content，不并入普通公开素材。本记录只兼容现平台客服；新商家客服、Task9、K02–K05保持关闭。

用途由服务端具名路由及实际调用上下文固定。body中的uid/mer/domain/visibility不授权，pid只定位已核本域分类。不能从全局att_id、URL、module或任意parent类型猜域，不建通用OwnerContext、任意对象认领或全局ID分配器。

## 三表完整结构（PROPOSED）

以下DDL未执行。`{{prefix}}`只替换为已读真实前缀，通过`\A[A-Za-z_][A-Za-z0-9_]*\z`及完整表名白名单，不猜eb_。InnoDB/utf8mb4、utf8mb4_bin固定；真实旧collation/比较/重名集合须在迁移前读取并明确兼容，不声称等价。无自动FK，服务在同writer PDO锁父根和范围。列序、类型、NULL/default、extra、字符集、索引全是精确schema合同。

新BIGINT id线路为十进制string，native全行保持真实PDO/DDL类型；只有原DTO需要且处于安全范围的数字作明确视图转换，不截断。内容摘要不能替代原文件或完整历史字节保全。

### marketplace_customer_asset（34列）

列序：`id`, `uid`, `purpose`, `state`, `storage_type`, `object_key`, `thumbnail_key`, `name`, `real_name`, `media_type`, `byte_size`, `width`, `height`, `content_sha256`, `created_at`, `expires_at`, `bound_at`, `retired_at`, `root_order_id`, `order_item_id`, `refund_id`, `review_id`, `confirmation_ref`, `form_product_id`, `form_definition_sha256`, `form_field_index`, `extract_id`, `agent_apply_id`, `source_product_id`, `source_qrcode_id`, `created_by_uid`, `created_by_admin_id`, `created_by_job`, `legacy_att_id`。

```sql
CREATE TABLE `{{prefix}}marketplace_customer_asset` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` INT UNSIGNED NOT NULL,
  `purpose` ENUM('avatar_public','public_upload_compat','review_public_image','user_promotion_image','user_promotion_qr','legacy_refund_evidence','refund_shipping_proof','order_form_image','withdrawal_qr','agent_licence','order_verify_code','gift_receive_code') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` ENUM('STAGED','BOUND','RETIRED') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'STAGED',
  `storage_type` TINYINT UNSIGNED NOT NULL,
  `object_key` VARCHAR(512) NOT NULL,
  `thumbnail_key` VARCHAR(512) NOT NULL DEFAULT '',
  `name` VARCHAR(100) NOT NULL,
  `real_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `byte_size` BIGINT UNSIGNED NOT NULL,
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `content_sha256` BINARY(32) NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `expires_at` INT UNSIGNED NULL DEFAULT NULL,
  `bound_at` INT UNSIGNED NULL DEFAULT NULL,
  `retired_at` INT UNSIGNED NULL DEFAULT NULL,
  `root_order_id` INT UNSIGNED NULL DEFAULT NULL,
  `order_item_id` INT NULL DEFAULT NULL,
  `refund_id` INT NULL DEFAULT NULL,
  `review_id` INT NULL DEFAULT NULL,
  `confirmation_ref` BINARY(32) NULL DEFAULT NULL,
  `form_product_id` MEDIUMINT NULL DEFAULT NULL,
  `form_definition_sha256` BINARY(32) NULL DEFAULT NULL,
  `form_field_index` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `extract_id` INT UNSIGNED NULL DEFAULT NULL,
  `agent_apply_id` INT UNSIGNED NULL DEFAULT NULL,
  `source_product_id` MEDIUMINT NULL DEFAULT NULL,
  `source_qrcode_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_by_uid` INT UNSIGNED NULL DEFAULT NULL,
  `created_by_admin_id` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `created_by_job` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `legacy_att_id` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_storage_object` (`storage_type`,`object_key`),
  UNIQUE KEY `uq_legacy_att` (`legacy_att_id`),
  KEY `ix_user_purpose` (`uid`,`purpose`,`state`,`id`),
  KEY `ix_order_item` (`root_order_id`,`order_item_id`,`id`),
  KEY `ix_form` (`confirmation_ref`,`form_product_id`,`form_field_index`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
```

键顺序：`PRIMARY KEY (`id`)`；`UNIQUE KEY `uq_storage_object` (`storage_type`,`object_key`)`；`UNIQUE KEY `uq_legacy_att` (`legacy_att_id`)`；`KEY `ix_user_purpose` (`uid`,`purpose`,`state`,`id`)`；`KEY `ix_order_item` (`root_order_id`,`order_item_id`,`id`)`；`KEY `ix_form` (`confirmation_ref`,`form_product_id`,`form_field_index`,`id`)`。

约束：

- uid来自认证顾客或实际受权目标用户，不来自body；job/admin生成还须核目标用户真实存在，created_by三列按真实调用恰一项。
- purpose→公开性固定；除各purpose允许的具体父列外全部父列NULL，禁止任意parent类型。review要求root/item，form要求confirmation/product/definition/field；提现/申请提交后绑定extract/apply，不能将NULL当已绑定。
- 私有STAGED过期只影响未绑定新认领；BOUND历史责任不因新上传时限失效，沿售后现有责任模式；不自动删文件。
- 旧映射legacy_att_id只来源显式确认；旧uploader未知不能写假uid，该批迁移不得直接插入本表，需先确认真实顾客主体或保完整历史兼容记录。
- Review引用原1..200字符合同保持；object_key容量512不允许生成超长review attachment_ref后放宽原Input。

### marketplace_platform_asset_category（10列）

列序：`id`, `pid`, `name`, `enname`, `type`, `created_by_admin_id`, `updated_by_admin_id`, `created_at`, `updated_at`, `legacy_category_id`。

```sql
CREATE TABLE `{{prefix}}marketplace_platform_asset_category` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `pid` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(50) NOT NULL DEFAULT '',
  `enname` VARCHAR(50) NOT NULL DEFAULT '',
  `type` TINYINT NOT NULL DEFAULT 0,
  `created_by_admin_id` SMALLINT UNSIGNED NOT NULL,
  `updated_by_admin_id` SMALLINT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,
  `legacy_category_id` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`),
  UNIQUE KEY `uq_legacy_category` (`legacy_category_id`),
  KEY `ix_parent` (`pid`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
```

键顺序：`PRIMARY KEY (`id`)`；`UNIQUE KEY `uq_name` (`name`)`；`UNIQUE KEY `uq_legacy_category` (`legacy_category_id`)`；`KEY `ix_parent` (`pid`,`id`)`。

约束：

- 真实Source旧category是id/pid/name/enname/type五列，安装SQL29623–29631无sort字段，现UI未提供sort；本表不猜造sort，列表按id ASC，层级按真实pid。
- 保空目录/任意有限合法层级；pid0为本域根，非商家/usage编号。真实父目录必须存在且同platform域，允许父子type混合，不新增父子同type门；禁止循环，保原有子目录时不可换父的服务门。
- name真实后端长度1..50（表单maxlength30只是UI）；原值不截断；全平台域同名规则明确采用本表utf8mb4_bin。历史旧collation比较差异必须在迁移清单显式检查和兼容确认。
- type仅原图0/视频1；资产category_id0表示未分组。global分类CRUD仍有原审计/同PDO资格，不写商业category mer_id。
- 叶目录删除保原可删除动作，但原Source会遗留素材pid。新域在同一真实writer PDO/原事务内锁原目录、非0真实父目录及该叶全部完整29列asset，先逐件确认asset对原目录的既定type资格；真实父为0则迁本域未分组0，父非0且该asset的实际type适合父目录则迁父，不适合则迁本域未分组0。不能放宽任何资产type门或禁止混合type目录树；文件、asset id及除category_id外全部字段保持。审计实际迁父/迁0数量与总数，最终重读并核影响行；仅然后删除叶目录，不删文件。
- 完整旧category原行/id/pid/type/enname/空目录和所有附件链接必须由显式映射保全，legacy_category_id不自动从pid值猜。

### marketplace_platform_asset（29列）

列序：`id`, `purpose`, `category_id`, `state`, `storage_type`, `object_key`, `thumbnail_key`, `name`, `real_name`, `media_type`, `byte_size`, `width`, `height`, `content_sha256`, `created_at`, `expires_at`, `bound_at`, `retired_at`, `created_by_admin_id`, `created_by_service_id`, `created_by_uid`, `created_by_job`, `chat_customer_uid`, `chat_is_tourist`, `chat_service_id`, `chat_service_uid`, `channel_qrcode_id`, `routine_version`, `legacy_att_id`。

```sql
CREATE TABLE `{{prefix}}marketplace_platform_asset` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purpose` ENUM('site_image','site_file','channel_qr','routine_download_qr','platform_chat_image') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `category_id` INT NOT NULL DEFAULT 0,
  `state` ENUM('STAGED','BOUND','RETIRED') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'STAGED',
  `storage_type` TINYINT UNSIGNED NOT NULL,
  `object_key` VARCHAR(512) NOT NULL,
  `thumbnail_key` VARCHAR(512) NOT NULL DEFAULT '',
  `name` VARCHAR(100) NOT NULL,
  `real_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `byte_size` BIGINT UNSIGNED NOT NULL,
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `content_sha256` BINARY(32) NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `expires_at` INT UNSIGNED NULL DEFAULT NULL,
  `bound_at` INT UNSIGNED NULL DEFAULT NULL,
  `retired_at` INT UNSIGNED NULL DEFAULT NULL,
  `created_by_admin_id` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `created_by_service_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_by_uid` INT UNSIGNED NULL DEFAULT NULL,
  `created_by_job` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `chat_customer_uid` BIGINT NULL DEFAULT NULL,
  `chat_is_tourist` TINYINT UNSIGNED NULL DEFAULT NULL,
  `chat_service_id` INT UNSIGNED NULL DEFAULT NULL,
  `chat_service_uid` INT UNSIGNED NULL DEFAULT NULL,
  `channel_qrcode_id` INT UNSIGNED NULL DEFAULT NULL,
  `routine_version` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `legacy_att_id` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_storage_object` (`storage_type`,`object_key`),
  UNIQUE KEY `uq_legacy_att` (`legacy_att_id`),
  KEY `ix_category` (`category_id`,`id`),
  KEY `ix_purpose` (`purpose`,`state`,`id`),
  KEY `ix_chat` (`chat_customer_uid`,`chat_service_uid`,`chat_is_tourist`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
```

键顺序：`PRIMARY KEY (`id`)`；`UNIQUE KEY `uq_storage_object` (`storage_type`,`object_key`)`；`UNIQUE KEY `uq_legacy_att` (`legacy_att_id`)`；`KEY `ix_category` (`category_id`,`id`)`；`KEY `ix_purpose` (`purpose`,`state`,`id`)`；`KEY `ix_chat` (`chat_customer_uid`,`chat_service_uid`,`chat_is_tourist`,`id`)`。

约束：

- 四created_by列真实恰一项；平台管理员经实际权限，系统生产者有限job来源，顾客/客服只可platform_chat_image，不获得全局素材管理。
- platform_chat_image必须private/local type1、category_id0、四chat字段全有值；正顾客UID<=4294967295，游客负UID>=-2147483647，且实际GuestChatSession当前租约/原历史记录权限证明，不把负号当自营。
- 非chat purpose四chat字段全部NULL；渠道码有实际channel_qrcode_id，小程序下载码有实际受权版本上下文；不用通用parent表。
- category_id非0必须指本platform表中真实且对该asset实际type适合的目录；资格来自完整native媒体/原允许类型，不由客户端或父目录type替代。实际图片对应目录type0、实际视频对应type1，其他site_file无图/视频资格时仅可未分组0。父子目录允许混合type，资产入目录的type门仍逐件严格核。新顾客域当前无自定义目录UI，不建第四张顾客分类表。
- 公开站点资产/渠道/下载不能通过直接URL或DTOatt_id误入商业商品；须另经可信商品操作的有限导入。
- 新域native读取本column_order全部29列；customer全部34列；category全部10列。仅DTO适配可明确别名id→att_id、object_key→att_dir，不用于DB资格，不填假mer_id。

平台分类保真实旧id/pid/name/enname/type，原无sort，不新增排序UI。允许空目录、有限合法层级和混合type父子目录，保禁止循环、有子目录不可换父/删除的原门。资产是否适合目录由每件完整实际媒体决定：图→type0、视频→type1、其他无图/视频资格的site_file仅未分组0；混合树不放宽资产type门。

叶删同原writer PDO/事务锁原目录、非0真实父及叶下全部完整29列资产，先核每件对原目录资格。父0→未分组0；父非0且适合该件→父；父不适合→本域未分组0。只改category_id，文件、id及其他字段保持；审计真实迁父/迁0和总数，核影响行并最终重读后才删叶，不删文件。这是拟议引用完整性增量，不冒称旧实现已有。

## purpose、主体与父关系

顾客uid来自认证顾客或真实已授权目标用户，created_by_uid/admin/job为真实恰一项；顾客表除下表允许的目的性父列外全部NULL。平台四个created_by也真实恰一项；顾客/客服只可私有聊天，不获全局素材权限。“可后绑”不表示已绑定：首次实际提交后才记录父ID，不从AFTER反选期待或把NULL当完成。

| 顾客purpose | 可见性 | 必需父列 | 可后绑/有限可选 | 正常责任 |
|---|---|---|---|---|
| avatar_public | public | 无 | 无 | user.uid=asset.uid；头像合法原外部URL保持旧合同，不将其当新域本地asset |
| public_upload_compat | public | 无 | 无 | 只有发布门通过后保原公开通用用途，不能被任何私有材料消费 |
| review_public_image | public | root_order_id,order_item_id | review_id | 真实本人已购项资格；review_id新create后真实绑定 |
| user_promotion_image | public | 无 | source_product_id,source_qrcode_id | 实际本人/受权目标用户，实际原允许推广内容；非商品经营owner |
| user_promotion_qr | public | 无 | source_qrcode_id | 真实目标user+原推广用途；存在实际qrCodeForever/qrcode行时才记source_qrcode_id，H5 getWechatQrcodePathAgent直接生成?spread=UID而无该行时必须NULL，不造qrcode PK。 |
| legacy_refund_evidence | private | root_order_id | refund_id | 原version0本人根/原refund责任 |
| refund_shipping_proof | private | root_order_id,refund_id | 无 | 原本人退款物流实际阶段 |
| order_form_image | private | confirmation_ref,form_product_id,form_definition_sha256,form_field_index | root_order_id | 真实确认快照与img定义，最终root只从实际create提交值绑定；旧确认单独原UID/key/frame验证 |
| withdrawal_qr | private | 无 | extract_id | 真实user/允许提现类型，extract_id记录首次实际绑定；任何后续同UID正常复用以真实extract.qrcode_url引用验证，不默改旧首次id |
| agent_licence | private | 无 | agent_apply_id | 实际认证UID/实际申请；agent_apply_id首次绑定，合法再次申请或修改依真实images集合，不猜新mer |
| order_verify_code | private | root_order_id | 无 | 实际有权原order+verify码内容 |
| gift_receive_code | private | root_order_id | 无 | 真实gift责任/原gift授权，不泄露领取能力 |

| 平台purpose | 可见性 | 必需主体/父关系 | 其他父列/分类 |
|---|---|---|---|
| site_image | public | created_by_admin_id OR created_by_job | all NULL；0 or real platform category type0 |
| site_file | public | created_by_admin_id OR created_by_job | all NULL；0 or real platform category of actual file/video type |
| channel_qr | public | channel_qrcode_id；created_by_admin_id OR created_by_job | chat/routine NULL；0 or real platform image category |
| routine_download_qr | public | routine_version；created_by_admin_id | chat/channel NULL；0 or real platform image category |
| platform_chat_image | private | chat_customer_uid；chat_is_tourist；chat_service_id；chat_service_uid；one actual created_by_uid OR created_by_service_id；actual original platform room/participants/guest lease or permitted history; no merchant conversation implementation | channel/routine NULL；0 |


STAGED新认领期限不消除BOUND历史责任，不按新上传过期自动删绑定文件。旧uploader未知不能填假uid，应隔离或保完整受权历史兼容并阻断启用。核销/礼品码、财务材料不得公开URL；private只在真实非public本地根和正确部署权限下生成，云私有ACL未闭合不回退公开云。

## 文件前授权、同PDO与生成record职责

先从原认证/当前会话确定真实身份和固定用途。有意义的principal/parent查询令同一App连接真实初始化writer PDO，核connection/PDO/database；不能另建observer PDO或只connect/getPdo/SELECT1声称有写资格。

原事务owner开同PDO事务，锁真实父根、当前权限、分类和参与者；review/create/cash/apply绑定不得另开独立嵌套事务或提前commit。重核资格/存储配置、实际完整bytes/类型/尺寸/解码/摘要后才create新对象；再插入完整元数据、原业务引用与审计，完整native行读回，最终重选principal/父/对象并观察提交后才返回成功。

recordGeneratedForUser、recordGeneratedForPlatform、recordOrderCode均为**生成后复核/记录**。真实caller在任何生成、下载或外部读取I/O前必须先完成当前身份/父关系授权及锁定，保持同writer PDO与原事务；queue user快照也须I/O前重读真实用户。不能用record后置检查补签事前授权，或声称进入record后才生成。cold/warm查找与有限清理同UID/purpose/真实来源，不全库按name查。

复用现MerchantAttachmentServices/Dao、AccountChangeGuard事务及物理失败收据，不给新域伪造MerchantContext/PlatformScope/售后root。LegacyProductWriteServices真实run/selfWorker/transaction/owner给商品媒体上下文；UploadService::fresh与现解码/视频检测只复用存储能力。AuditLogServices当前仅admin/merchant，不给customer/kefu假审计角色，后者保真实metadata创建者和实际业务日志/失败类。

失败收据沿六键complete/phase/physical_changed/physical_complete/database_committed/cleanup_complete。SQL回滚不等于文件回滚：只有明确未提交且对象确认本次新建，才能窄cleanupNew；commit未知保留对象/记录并独立核实，不自动重试、补self或删旧文件。既有售后不自动删文件策略保持。实际新增元数据/引用、audit/syslog、start_uploads_*原86400秒限频、scan原600秒绑定缓存、权限缓存DEL、文件/日志与提交观察均须按真实顺序独立before→delta→after证明，不预签零副作用。

## writer接线索引

这是原用途盘点的实现索引，不是第二任务清单，不表示已被调用/验证。潜在helper、无保存分支及有条件旧权益和真实已到达的writer分别记录。

| 定位 | 原正常调用 | 目标域/用途 | 真实主体/父来源 | 接口组 |
|---|---|---|---|---|
| W01 | template/uni-app/components/eidtUserModal/index.vue:97-115；template/uni-app/pages/users/user_info/index.vue:524,551 | customer / avatar_public / public_upload_compat | request真实顾客UID；非mer；user完整真实行 | CU_PUBLIC |
| W02 | 本人已购root/item评价页及pages/goods/goods_comment_con/index.vue:167 | customer / review_public_image | 真实认证顾客+原已购root/item；原root/item/payment/eligible审核链；新图片经营绑定源于原订单商品 | CU_REVIEW |
| W03 | api/order.js:returnGoodsSubmit→order/refund/apply/:id | existing_private_aftersale_or_customer / v1原私有files；v0 legacy_refund_evidence | 真实本人UID；v0归属仍按原历史责任；实际order+选择items/refund | CU_LEGACY_REFUND |
| W04 | api/order.js:refundExpress→order/refund/express | customer / refund_shipping_proof | 真实本人UID；真实退款id→原order；绑定原refund_img读取/提交接口 | CU_LEGACY_REFUND |
| W05 | 新Marketplace确认frame的custom_form.img；旧确认getCacheOrderInfo与StoreOrderCreateServices.php:147-153,282 | customer / order_form_image | 真实UID，不取页面mer_id；新confirmation_ref/selection→实际product/definition_sha256/field_index；旧frame按UID+key独立验证，创建后绑真实order | CU_FORM |
| W06 | extractCash→extract/cash，qrcode_url微信/支付宝 | customer / withdrawal_qr | 服务端$request->uid()；本人真实user与提现许可/类型；提交后真实user_extract行 | CU_WITHDRAWAL |
| W07 | api/store.js:create→agent/apply/:id；applyInfo及后台审核详情 | customer / agent_licence | 必须来自认证UID，不能沿Controller现postMore uid相信客户端；已有申请id须本人；首次为真实申请资格/phone验证码/邀请业务，提交后真实申请行 | CU_LICENCE |
| W08 | 移动商品新增/轮播/规格图/HTML详情提交；平台productAdd共享图片picker | merchant_commercial / product_media | 真实商家员工Context或实际平台自营/商品操作Scope，不用顾客token当商家；锁定product原owner；创建为受权self实际商家行 | PRODUCT_MEDIA |
| W09 | merchantapi attachment.manage routes；真实MerchantContext分类/上传/移动/改名/删除 | merchant_commercial / merchant_media | EmployeeAuthServices现有可信MerchantContext；本merchant分类与实际storage资格 | KEEP_MERCHANT |
| W10 | admin uploadPictures/index.vue→api/uploadPictures.js:fileListApi/move/update/delete；商品编辑器、站点配置、主题、关注回复等共享picker | merchant_commercial_or_platform / 商业product_media 或 platform site_image/site_file；由实际业务上下文决定 | 实际平台admin认证+相应用途权限/商业目标授权；商品上下文或全局platform分类；不能用menu_name/pid决定 | PLATFORM_PICKER |
| W11 | admin扫码页 app/upload/index.vue 与scan qrcode/image/remove | merchant_commercial_or_platform / 由签发时已授权purpose固定 | 签发admin+真实会话/操作上下文；上传再次验证；商业分类/商品或platform分类完整绑定 | SCAN_BOUND |
| W12 | 后台在线抓图 | merchant_commercial_or_platform / 已授权product_media 或 site_image | 实际admin/商家scope；与同一picker上下文一致；先核新下载URL的原许可与目的域 | REMOTE_IMAGE |
| W13 | 上传组件分片、合并、video_data_save | merchant_commercial_or_platform / product_video 或 site_file | 同一真实actor/授权上下文贯穿所有chunk与save；实际商业目标或平台分类；metadata save核服务端已生成对象，不信video_path | VIDEO_BOUND |
| W14 | shared picker分类树/表单/空目录CRUD；复制商品自建分类 | merchant_commercial_or_platform / 真实商业category 或 platform_asset_category | 商业上下文或实际平台素材权限；同域父id、完整父链/重复name/删除叶语义 | CATEGORY |
| W15 | uni-app extension/customer_list/chat.vue:592-608；admin kefu/mobile501、appChat/mobile580及原客服上传组件 | platform / platform_chat_image | 真实现api/kefu/guest聊天身份及当前参与者；非mer；原chatToUid/isTourist/实际Service.id+uid、用户/GuestChatSession当前租约或允许历史记录 | PLATFORM_CHAT |
| W16 | 本人spread_banner/getRoutineCode；PosterJob::spreadPoster已有队列调用 | customer / user_promotion_image/user_promotion_qr | 实际本人或受权目标用户；job重新读真实用户不信旧队列快照；真实user、允许的原推广用途及配置；非任一商品owner | GENERATED_CUSTOMER |
| W17 | adminapi/controller/v1/agent/AgentManage.php:181 lookXcxCode；api/controller/v1/user/DivisionController.php:98-105 getStaffList | customer / user_promotion_qr | 真实受权目标UID/员工业务关系；不是UID==商家；实际user/agent/staff关系及原qrcode记录 | GENERATED_CUSTOMER |
| W18 | 平台渠道码create/update/read cold生成；已认证后台getDownloadInfo | platform / channel_qr/routine_download_qr | 实际平台admin权限或有限系统生产者；实际qrcode渠道行/受权小程序版本配置 | GENERATED_PLATFORM |
| W19 | StoreProductServices.php:2236 ordinary产品码；StoreSeckillController146/StoreCombinationController128/StoreAdvanceServices280按真实catalog family | merchant_commercial / catalog_product_qr | 真实合格PublicCatalog根owner；ordinaryRoot/nativeBase family真实根与发布条件 | KEEP_CATALOG_QR |
| W20 | PC HomeController.php:82/85、DiyServices425、MemberCardBatch100/102、OtherOrder72/74均显式isSaveAttach=false；StoreOrderServices.php:614真实gift type7默认保存 | platform_or_customer / false调用无商业DB写；真实gift为customer gift_receive_code | 真实受权平台用途/实际礼品订单责任；具体callsite；禁止用默认type/id字段推owner | ORDER_CODE,GENERATED_PLATFORM |
| W21 | 本人订单详情/实际商家或平台合法订单读取；本人发票详情 | customer / order_verify_code | 订单实际UID为顾客责任，真实调用actor另记；完整实际订单及原核销资格；不把orderID或verifycode当owner | ORDER_CODE |
| W22 | 实际平台采集/商品新增保存同步下载；afterCommit ProductCopyJob经selfWorker，规格/轮播/描述各分支 | merchant_commercial / product_media | 真实受权创建或锁定商品owner；队列须原selfWorker和真实父校验；product/SKU/type0及本merchant分类 | PRODUCT_COPY |
| W23 | 原BargainController.posterInfo230-232；CombinationController102/113-115已有合法历史展示 | customer / user_promotion_image | 实际本人/允许历史参与者；原活动/用户关系及已批准的旧权益边界 | GENERATED_CUSTOMER |
| W24 | v1真实本人售后上传、apply/replay、顾客/商家/平台ticket/content | existing_private_aftersale / 现固定私有STAGED/CLAIMED文件合同 | 实际customer/MerchantContext/PlatformScope权限；真实root/refund/UID/mer，原同PDO锁与票据 | KEEP_AFTERSALE |


W17实际writer在AgentManageServices297–327、DivisionAgentApplyServices203–248，caller为实际后台181与DivisionController98–105，不沿原稿误记controller327/model248。W19已有catalog根owner保持。W20显式isSaveAttach=false不算附件INSERT；默认true潜能不算已调用。W22优先真实持久商品后的ForProduct，潜在下载helper也守卫但未定位外部caller不计覆盖。W23只原允许旧权益展示，不重开活动/分销或停止样本。

## 具名接口与原权限

除KEEP既有链，以下全部**PROPOSED，不是当前可调用实现**。locator不授权，真实route/原认证/父业务重新建范围；参数及返回形状是实施合同，不是已上线承诺。

### CU_PUBLIC（PROPOSED）

```text
CustomerAssetServices::uploadAvatar(Request $request,int $actualUid):array
CustomerAssetServices::uploadPublicCompat(Request $request,int $actualUid):array
```

真实Auth/User当前资格→同writer事务锁user→检查实际上传原bytes/MIME/完整解码/配置→写customer专用namespace→存完整34列→提交观察，返回原name/url/attachment_ref。头像旧外部URL不被冒充新本地asset，也不擅收紧原已有URL合同；publicCompat必须通过发布门。

### CU_REVIEW（PROPOSED）

```text
CustomerAssetServices::uploadForReview(Request $request,int $actualUid,int $rootId,int $itemId):array
MarketplaceOrderReviewDao::publicPicturesForCreate(ConnectionInterface $connection,PDO $pdo,array $fullRoot,array $fullItem,int $actualUid,array $references):array
```

新建议POST /api/order/reviews/:root/:item/files；实际quote/create的身份、付款/可评价项先独立核，再文件写。Dao按每reference顺序返回完整来源标签+native行集合，旧publicPictures作为完整14列legacy分支，不猜UID。新域限本人/实际root/item与review_public_image，完整34列；create前和最终228均调用新wrapper，fresh严格相同；read公开展示不开放修改原图。

### CU_LEGACY_REFUND（PROPOSED）

```text
CustomerAssetServices::uploadLegacyRefund(Request $request,int $actualUid,int $rootId,?int $refundId,string $fixedBranch):array
LegacyCustomerMaterialServices::bindRefundImages(ConnectionInterface $connection,PDO $pdo,array $fullOrder,?array $fullRefund,array $assetReferences,string $fixedBranch):array
```

固定branch仅legacy_refund_evidence/refund_shipping_proof；与真实legacy order/refund服务同PDO核version/UID/原责任及原流程条件，再写/保存引用。v1明确转现原AftersaleFileServices；新wrapper不能用v0根骗现v1 API。旧refund_img非空unset分支必须由实际新绑定/持久化/读取适配替代，保原成功业务及其余字段。

### CU_FORM（PROPOSED）

```text
CustomerAssetServices::uploadOrderForm(Request $request,int $actualUid,string $confirmationKey,int $productId,string $definitionSha256,int $fieldIndex):array
CustomerAssetServices::bindOrderForms(ConnectionInterface $connection,PDO $pdo,array $lockedProducts,array $serverConfirmation,array $fullNewOrder,array $answers):array
```

新增POST /api/order/confirm/:key/forms/:product/:field/files（拟议）；服务读取真实UID所属confirmation，不信client mer/definitions，核当前选择、冻结definition SHA及img field。新Marketplace使用现confirmation/CustomForm，legacy仅真实getCacheOrderInfo UID+key分支并核原definitions，不能从AFTER挑value。CreateServices.snapshot后同PDO锁资产/新order，保存原custom_form TEXT及私有locator，order读取按原有权身份私有预览；确认失效保留实际未确认上传不公开fallback。

### CU_WITHDRAWAL（PROPOSED）

```text
CustomerAssetServices::uploadWithdrawalQr(Request $request,int $actualUid,string $extractType):array
CustomerAssetServices::bindWithdrawalQr(ConnectionInterface $connection,PDO $pdo,array $fullUser,array $fullExtract,string $reference):array
```

新增POST /api/extract/files/:extractType（weixin/alipay固定）；沿真实UserExtractController UID和cash业务资格，先核不触发金融动作的上传资格；cash原真实事务中绑定同UID+withdrawal_qr并保存原qrcode_url的私有locator。本人和现财务审核读取分别从真实extract行派生，不给商家素材权限；资金本体不改变。

### CU_LICENCE（PROPOSED）

```text
CustomerAssetServices::uploadAgentLicence(Request $request,int $actualUid,?int $ownedApplyId):array
CustomerAssetServices::bindAgentLicences(ConnectionInterface $connection,PDO $pdo,int $actualUid,array $fullApplication,array $references):array
```

新增POST /api/agent/apply/:id/files，0代表真实本人首次申请上下文；Controller须由request.uid强制业务uid，更新id必须属于本人，保原手机验证码/邀请资格。在实际申请保存事务原images引用字段内绑定私有locators；本人applyInfo/原平台审核详情通过真实申请与权限读取，业务未成为merchant不猜mer。

### PRODUCT_MEDIA（PROPOSED）

```text
PlatformProductMediaServices::forCreate(Request $request,callable $operation):mixed
PlatformProductMediaServices::forProduct(Request $request,int $productId,callable $operation):mixed
PlatformProductMediaServices::importPlatformImage(Request $request,int $productIdOrZero,string $platformAssetId,int $categoryId):array
```

仅具体商品/创建用途的窄适配，不建全局OwnerContext。后端复用真实LegacyProductWriteServices::run(array,callable,bool,bool)、transaction/owner/assertParent或MerchantContext；旧移动API还须原CustomerMiddleware真实staff/customer/delivery资格及实际自营操作资格，不能只登录UID。已存在商品锁真实owner；创建须允许create且真实配置self merchant存在。upload/page/category/choose/submit全部重核同实际业务scope；import仅已授权platform完整行/实际对象，另写目标商家自己的对象和metadata，原平台件不变，不接收任意URL。

### PLATFORM_PICKER（PROPOSED）

```text
PlatformAssetServices::upload(Request $request,PlatformScope $scope,string $fixedPurpose,int $categoryId):array
PlatformAssetServices::page(Request $request,PlatformScope $scope,array $query):array
PlatformAssetServices::rename(Request $request,PlatformScope $scope,string $assetId,string $name):array
PlatformAssetServices::move(Request $request,PlatformScope $scope,array $assetIds,int $categoryId):array
PlatformAssetServices::delete(Request $request,PlatformScope $scope,array $assetIds):array
```

fixedPurpose只能由真实调用页面对应服务端route/permission闭集设定，不从menu_name/pid/body owner认定。平台global permission无target owner；商品路径必须进入PRODUCT_MEDIA，不能global列表选platform行后直接存商品图。旧/file/*路由可作薄dispatcher，但server验证用途和scope；JSON字段形状按各旧controller适配，native资格不伪造商业row。

### CATEGORY（PROPOSED）

```text
PlatformAssetCategoryServices::tree(Request $request,PlatformScope $scope,array $query):array
PlatformAssetCategoryServices::save(Request $request,PlatformScope $scope,?int $categoryId,array $body):array
PlatformAssetCategoryServices::delete(Request $request,PlatformScope $scope,int $categoryId):array
```

全局platform素材权限+实际同writer PDO，完整10列原生行；原id/pid/name/enname/type与tree title/children/loading/expand、表单结构兼容，空目录及混合type父子目录支持。叶删除同原事务锁原分类/非0真实父与该目录全部完整29列assets，先确认每件对原目录的type资格；父0→未分组0，父非0且适合该件→父，不适合→本域未分组0；原资产type门不放宽，不添加父子同type门。文件/id及除category_id外全字段保持，审计迁父/迁0真实数量并最终重读后删叶；有子目录不删/不改父门保持。商业分类仍原同merchantDAO而非本表。

### SCAN_BOUND（PROPOSED）

```text
PlatformAssetServices::issueScan(Request $request,PlatformScope $scope,array $verifiedUploadUse):array
PlatformAssetServices::uploadScanned(Request $request,string $opaqueUploadContext):array
```

新扫码缓存必须绑定已授权actor/session、固定域/purpose、真实category、product或create scope、原600s时限；实际随机token/完整上下文只私有保存。upload、scan-image/read/delete均核绑定，非全局scan_upload通行证；HTTP外部未知客户端迁移是发布前置。

### REMOTE_IMAGE（PROPOSED）

```text
PlatformAssetServices::uploadRemote(Request $request,PlatformScope $scope,array $verifiedUse,array $sources):array
```

只在已受权平台/商业目的上下文内调用现下载/实际存储，新bytes先inspect；物理写入先于metadata的失败须保真。真实源存储/远程来源配置不读私密，原正常网络能力后续受权验证，不把body提供owner或已存在任意URL当服务端对象证明。

### VIDEO_BOUND（PROPOSED）

```text
PlatformAssetServices::uploadChunk(Request $request,PlatformScope $scope,array $verifiedUse,array $body):array
PlatformAssetServices::saveUploadedVideo(Request $request,PlatformScope $scope,string $serverUploadReference):array
```

原分片/合并DTO code/msg/file_path保持；每chunk/最终save同actor/use，服务端已绑定文件才可保存，不直接相信video_path/size0；实际文件类型、完整size/digest/存储资格核原支持集。商业视频入原商家库，global视频入platform site_file；私有材料不借此公开。

### PLATFORM_CHAT（PROPOSED）

```text
PlatformChatAssetServices::uploadCustomer(Request $request,int $actualUid,int $actualTargetServiceUid):array
PlatformChatAssetServices::uploadService(Request $request,int $actualServiceId,int $actualPeerUid):array
PlatformChatAssetServices::assertMessageImage(ConnectionInterface $connection,PDO $pdo,ChatIdentity $identity,int $target,bool $isTourist,string $reference):array
```

同现UserAuthServices/LoginServices/GuestChatSession身份资格与ChatHandle198–260真实room判断，复用/有限提取此逻辑供HTTP与WS，不造merchant会话API。锁实际平台Service id/uid/status与user/原room records，UID/peer只作locator、不得作owner证明。私有platform29列的chat_customer_uid/is_tourist/serviceid/serviceuid精确匹配；WS chat保存303之前和final重读需校验同图，历史record/log读取也从真实参与者校验。游客负UID不是user表/商家ID，只现租约/允许历史身份；不新增此前不存在的游客上传权。

### GENERATED_CUSTOMER（PROPOSED）

```text
CustomerAssetServices::recordGeneratedForUser(ConnectionInterface $connection,PDO $pdo,array $actualUser,string $fixedPurpose,array $actualObject,array $serverProducer):array
```

生成前的真实caller必须先按本人/admin/job当前真实身份与父关系授权，锁实际用户/必要父根，并保持同writer PDO及原事务，再调用实际生成I/O；queue supplied user快照须在I/O前用实际writer重读核真实用户。生成后的本record只复核完整actualObject与既定上下文并写记录，不能事后补授权或声称进入record后才生成。原name查找按UID+purpose+具体source关系，cold/warm及原定清理同域；customer UID不推经营商家。

### GENERATED_PLATFORM（PROPOSED）

```text
PlatformAssetServices::recordGeneratedForPlatform(ConnectionInterface $connection,PDO $pdo,PlatformScope $scope,string $fixedPurpose,array $actualSource,array $actualObject):array
```

渠道/版本真实平台来源先锁定并授权，再调用原二维码生成者；新29列完整行，固定scope并有真实生成点时间/bytes，warm仍同purpose/source，外部微信配置/动作未运行。isSaveAttach=false分支保原无商业INSERT，只改必要typed文件使用资格。 这里“先锁定/授权再生成”是实际caller在I/O前的明确义务，保持同writer PDO及原事务；本record收到已生成actualObject后仅完整复核并记录，不能用事后record代替前置授权/父锁。

### ORDER_CODE（PROPOSED）

```text
CustomerAssetServices::recordOrderCode(ConnectionInterface $connection,PDO $pdo,array $actualFullOrder,string $fixedPurpose,array $actualActor,array $actualObject):array
```

Order/Invoice/gift现读取者须在生成/按名查找前已有真实订单权限；从该根派生UID及真实orderid，返回code/gift_code原字段string私有locator，实际H5/小程序/后台预览适配ticket+binary，不能公开核销/领取秘密；这是本合同明确的新私有化兼容工作，非声称旧Source已有。 该权限/真实根锁定必须由真实caller在任何生成/外部读取I/O前完成并保持同writer PDO及原事务；本record只核完整actualObject/既定根和记录，不补做事前授权。

### PRODUCT_COPY（PROPOSED）

```text
CopyTaobaoServices::downloadCopyImageForProduct(int $actualProductId,string $source):string
```

仅已有明确商品操作内调用，实际已定位下载均在product持久化后；复用LegacyProductWriteServices::transaction()/owner()/selfWorker实际PDO与父锁。同步和job各传真实product PK；SKU分支从真实父relation独立取PK而不是从图找owner。分类和图片同商业owner；潜在uploadDescriptionImage/helper也须实际product context后才可写。没有必要新增泛型create-array上下文。

### PRIVATE_READ（PROPOSED）

```text
CustomerAssetReadServices::ticketForCustomer(Request $request,int $actualUid,string $assetId):array
CustomerAssetReadServices::ticketForPlatformBusiness(Request $request,PlatformScope $scope,string $fixedBusiness,int $businessId,string $assetId):array
CustomerAssetReadServices::ticketForOwnedOrder(Request $request,array $actualActor,int $rootId,string $assetId):array
PlatformChatAssetServices::ticketForParticipant(Request $request,array $actualChatIdentity,string $assetId):array
```

私有读固定purpose分支，先从实际business记录+原权限建立关系后才读对象。浏览器用原售后ticket→完整binary→对象URL的消费模式；新票据scope/key与现aftersale严格分开，不能直接传新用途骗MarketplaceAftersaleReadTickets固定绑定；只复用其issue/peek/consume设计与既有Auth存储/实际凭证读取，不把新接口冒称已存在。

### KEEP_MERCHANT（保留既有链）

```text
existing MerchantAttachmentServices::upload(MerchantContext $ctx,array $body):array
existing MerchantAttachmentDao::add(MerchantContext $ctx,array $data):array
```

保同实际writer PDO、ctx.merId强制写、storage真实receipt与原完整schema；新增平台adapter只能真实目标merchant，不伪造ctx。

### KEEP_CATALOG_QR（保留既有链）

```text
existing QrcodeServices::getCatalogRoutineQrcodePath(int $id,int $uid,int $family,array $param=[])
existing QrcodeServices::getCatalogAdvanceWechatQrcodePath(int $id)
```

与pc getProductRoutineCode三条已有经营owner链保持；新域不能替换其正确商业owner。

### KEEP_AFTERSALE（保留既有链）

```text
existing MarketplaceAftersaleFileServices::uploadForCustomer(Request $request,int $actualUid,mixed $rootId):array
existing ticket/content/claim/replay
```

完全保原private domain/root/UID/mer票据合同及真实可重试副作用；不得因新资产域改变或公开fallback。


当前PlatformAccessServices有限ACTIONS不含新素材动作，必须同步具名路由、method/rule/option、唯一菜单与当前admin-role grants，沿真实scopeForRequest/authorizeScope与原AdminAuth/AdminCheckRole核资格；不能传不存在的permission或构造Scope就算授权。保旧file/*需新有限legacy-file授权factory，按真实原route/current grants重核用途，不能改Request伪装另一canonical route。商品媒体同样扩真实LegacyProductAccessServices有限媒体动作。

下列是拟议canonical动作，尚未注册；原合法人员授权迁移须真实实施，不自动给跨商家或商家客服权限。

| method | adminapi下route | permission/action |
|---|---|---|
| GET | marketplace/platform-assets | platform-assets-list |
| POST | marketplace/platform-assets | platform-assets-upload |
| PUT | marketplace/platform-assets/<id>/name | platform-assets-rename |
| PUT | marketplace/platform-assets/move | platform-assets-move |
| POST | marketplace/platform-assets/delete | platform-assets-delete |
| GET | marketplace/platform-asset-categories | platform-asset-categories-list |
| POST | marketplace/platform-asset-categories | platform-asset-categories-create |
| PUT | marketplace/platform-asset-categories/<id> | platform-asset-categories-update |
| DELETE | marketplace/platform-asset-categories/<id> | platform-asset-categories-delete |
| POST | marketplace/platform-assets/scan-context | platform-assets-scan |
| POST | marketplace/platform-assets/remote | platform-assets-remote |
| POST | marketplace/platform-assets/video-chunks | platform-assets-video |
| POST | marketplace/platform-assets/video-save | platform-assets-video-save |
| POST | marketplace/products/<id>/import-platform-image | marketplace-products-import-platform-image |


## DTO、评价、private与self消费者

保原顾客name/url/attachment_ref、客服name/url、后台src、分片code/msg/file_path及文件页list/count。新表native完整行不丢列，不在DB资格层把id/object_key转成att_id/att_dir或填假mer_id；view别名不授权跨域或保证全局ID唯一。

评价旧publicPictures每reference的有序完整system_attachment14列原生行保持，原重复/顺序不删，不为旧module3追造uploader。新评价图独立取完整customer34列，真实uid/root/item/purpose和固定locator两次fresh重选。内部拟议wrapper每reference固定domain=legacy_public或customer_review、rows=完整原生行列表，只为pre/final严格比较；body.pics、1..200字符限制和create DTO不变。ReviewServices两调用传早已锁定fullRoot/fullItem/actualUid，不从HTTP或AFTER挑父。ReviewDao旧14列类型/default/extra/索引及路径/module/原图片/MIME资格保持，仅精确兼容MS2结构期YES/null与最终NO/null两owner阶段；新native不能伪装14列。

原upload/image的提现码、营业执照、订单自定义img、旧退款/物流图、平台聊天逐个改具名private上传与受权ticket/binary读取；保字段形状不等于旧URL已私有。头像合法原外部URL保持旧合同，不说它是新域本地asset。旧公开材料暴露/下载/缓存未知，需独立实际引用及受权读取迁移证据，不能只改名。

平台/移动商品从上传、分页count/list、分类、选择、扫码/在线/视频、移动删除直至最终image/SKU/slider/HTML/video/recommend提交，共享同一真实商品/创建商业范围。移动原staff/customer/delivery资格仍核，不将顾客UID升级商家。平台原图仅在该已授权商品操作中从真实platform记录/内容建立目标商家完整商业对象，原件保持，不接受任意URL导入。

structure false+self现兼容保持；真实素材/分类/引用确认、旧客户端发布及writer全闭合后，最终enabled=true的self也必须走mediaReference/HTML/SKU/video/recommend完整门，不能只限制picker或继续self提前return；非self原owner门不削弱。

新三表加入SystemCrudServices禁止通用CRUD与SystemClearServices保护集合。旧module2“清昨日”不扫新private/BOUND，各purpose有限生命周期需真实引用和删除收据；旧archive不自动清理。

## 真实历史、可恢复转存与发布门

商业行沿原MS2每PK、完整原行、现owner、证据、目标商家与image/category links显式确认；正owner也需确认。不凭目录、UID、module、订单owner、pid=1或“只有一家店”推历史。PK/业务引用/非owner字段与文件保持，未知冲突隔离并阻断启用。

确有非商业旧行才另版元数据域迁移，超出原owner-only backfill。执行前取得真实DDL/collation、规模/引用、确认manifest、可恢复备份及完整原行/att_id/category id/pid/原文件字节，所有旧消费者按原键受权解析。新legacy_att_id/legacy_category_id只做显式映射，不能代替完整历史旧行；本记录不造通用归档表、不猜行数或uploader。

要让完整五根可enforce，已确认非商业行如需迁出商业活动集合，另审事务性转存/恢复方案保全原行、旧引用/URL/分类链接及历史副本，建目标记录/所有兼容解析并独立验证。不能只删NULL活动行、补self、module过滤/跳坏行，或留NULL后声称通过全五根。转存不移动/清理物理文件；DDL部分提交如实记，不虚称事务回滚恢复文件。不能无损保留的真实引用先阻断迁移。已确认公开历史14列读取保持原资格，不放宽当前system_attachment结构门。

新private不证明旧公开材料已安全；旧uploader未知不得插假非空uid。外部H5/小程序/App/管理端版本与调用现状未知，须有明确可核的发布/兼容观察条件。仓库内和真实外部private消费者全部完成专用入口及合法完整读取后，才可保默认upload/image的public_upload_compat；不从Source未见caller或无日志推无人使用，不停旧正常功能求过门。

原唯一T1–T8中的T3–T6承接schema/validator/writer/consumer/权限与发布接线，T7仍依赖真实历史确认和恢复前提，T8仍需完整正常主线、实际backfill/enforce及enabled=true验收。本T2不改原Task1四框、历史确认停止点或原执行授权。

## 后继正常证据（全部未运行）

仍需商家/平台代管/自营素材正常上传选择改名分类/合法删除，顾客头像普通图预览保存再读，真实已购含图评价→审核→展示→商家回复，private售后首次/重放绑定和三方合法票据读取，现平台客服发收/历史图，配置/渠道/下载/扫码与实际允许cold/warm派生图/旧权益正常展示；各自独立BEFORE→完整允许delta→AFTER、真实主体/父、完整文件字节、提交与业务/native结果。

真实全五根映射和strict enforce之后还需新正常上传、完整产品→订单→售后→评价、三端页面/原图/历史权益、真实金融/结算/发布。结构期false+self、合成旧37、Source/语法或局部返回均不代签。原普通退役/独立zero、完整来源/环境/权限保持；已停止安全/负向/并发/故障样本、Task9和新商家客服不因本记录开启。

公开证据仅安全Source/阶段与主体/父摘要、原生终态/等待/完整双流及受控业务观察，不留token、票据、private路径或正文。失败保首真实阶段和中间态，不改fixture/归属/断言或删历史。


## 2026-10-22 T3完整结构检查资格与T4有限接缝状态

三表DDL及其73列完整tuple/16索引不变。T3的独立AssetSchema与CREATE-only迁移已有Source实现，尚未执行PHP语法、check/apply或业务验证。迁移锁申请未知结果的P2已由r2有限Source修复：同一PDO先确认不是caller原锁，申请执行前标未知，可能已获取时负责确认和单次有条件释放；原check无锁/配置/三CREATE与逐步readback不变。Source复核不代签运行；完整T3仍未验收。

完整inspect/assertComplete另须先证明实际provided PDO能观察结构。MySQL的TRIGGERS只显示有该表TRIGGER权限的信息，空集合不可独自证明没有trigger。完整门限定正向证明的结构身份：当前支持CURRENT_USER实际账户、目标数据库直接库级SELECT和TRIGGER两权限在SCHEMA_PRIVILEGES中精确存在；仅支持无歧义的有限ASCII账户/host格式，不解析角色/全局权限或猜资格。无法支持或证明则失败，不返回compatible或已知absent。三表metadata的schema定位统一精确binary匹配既定database，authority与真实账户在完整观察前后核对。保SELECT-only、provided PDO和既有严格mode/完整metadata门，不GRANT/SET ROLE，不增audit_app原四DML权限。

完整结构资格在已有audit_migrator结构阶段取得；数据库私配置本身不证明元数据authority或环境隔离。runtime业务仍用实际writer PDO、真实principal/父锁/原事务核其可见事实，不得把局部观察或App无TRIGGER的空集合冒充完整结构门。后续若需runtime局部helper须另名并保持完整迁移/启用门，本轮不新增该API或接DAO。其他真实权限形式需先形成可核只读资格方案，不以扩App权限或跳过检查兼容。本项是在后继helper r3实施前的设计修正，r2及其原来源快照保留。

T4已有两处Source有限接缝：LegacyProductWriteServices新增mediaOwner(productIdOrZero)，只在其当前真实同PDO事务中从已锁商品owner或allow_create===true的捕获self派生；Storage保原员工upload/locate签名、Services认证和物理算法，新增uploadForProduct/locateForProduct，物理前后核同writer PDO/事务/owner，后者要求caller同PDO重选完整14列owned附件行，不能传body拼row。Root完整原Source重建及独立Source复核通过，PHP语法和新旧正常业务仍NOT RUN。route/action/current grants、商业DAO/分类/元数据与审计、扫码/在线/视频/平台图导入、三端locator与最终引用门尚未接完，不标T4完成或实际可用。

本追加承接原T1–T8，不勾原任务和真实历史/最终启用验收。原Main/Val13479及原R40保持；当前原S65041在售后评价正常阶段继续，禁止并行新collector/额外Docker/PHP-l，停止样本NOT RUN、Task9关闭、L2本地未推送未部署。


T3 r3有限Source复核收口：helper24991/0c8c4a44a32cd026e2038c2550e9601f770b71e1e66fb3c02e3c8df62528112a，四stage全正逆/五非缝、21必要来源1054964B及原生native9由Root1867f0与非作者分别核对。原三DDL/73tuple/16索引及r2迁移保持；原DML-only不能凭不可见trigger空集合通过。此Source PASS只限授权配置完成且稳定后新建的结构PDO；当前唯一实际caller是独立迁移main新建的非持久PDO，Source7090a0核无runtime调用者。不得复用授权变更前选库的旧PDO：MySQL说明数据库权限变更在后继USE才对已有会话生效，目录正向行不替代本会话前提。helper不执行USE/新建PDO/扩权限来补签；后继消费者若改变此调用模型须先落实同等可核资格，不能把本Source签为任意旧writer可用。官方依据：https://dev.mysql.com/doc/refman/8.0/en/privilege-changes.html 。真实权限、PHP语法、check/apply、完整T3和业务验收仍NOT RUN。


## 2026-10-22 T4 平台商品图片列表与上传第一步实施前设计

本步承接原唯一T4，仅实现GET/POST product/product/<product_id>/media，不将两入口视为完整T4或最终启用。既有商品使用原writer已锁根的owner；product_id=0仅是已授权创建范围，在原真实事务中用捕获且已锁的self。复用真实Request、LegacyProductAccessServices、LegacyProductWriteServices及既有Storage商品接缝，不接受客户端mer_id，不构造MerchantContext，不新增旁路PDO。原九件WIP保留，不改五PHP已经实测语法0的内容；此前“语法未运行”是旧窗口，本轮以后仅六PHP语法实际0，完整T3/T4业务仍NOT RUN。

两action为legacy-product-media-list、legacy-product-media-upload，均走原Boundary→AdminAuth→LegacyProductScope→AdminCheckRole→AdminLog所在完整middleware链。受限管理员从当前启用角色授权并集中同时取得本项新leaf、对应旧GET file/file或POST file/upload/<upload_type?> grant、任一实际POST product/product/<id> save grant；允许三项分布不同角色，按实际有效菜单方法/规则查，不写死安装ID。最高管理员保原有限action访问。独立菜单DML只按逐角色实际旧FILEgrant追加对应新leaf，不能要求同角色另有商品save；停用角色保状态且复制原FILEgrant，无授权的空/null rules原样保留，malformed rules在任何写前报不兼容。核完整菜单22列、角色6列、真实nativeID及非目标完整字段，重跑零变。新CLI锁申请先记未知、同session有条件确认/单次释放，不领用caller原锁、不循环或RELEASE_ALL；不能照抄旧stock-menu仅fetch成功后标locked的缺口。

GET保持picker pid/real_name/page/limit参数。在原同PDO、商户与商品锁范围读取SELECT *完整native14附件列(att_id,name,att_dir,satt_dir,att_size,att_type,pid,time,image_type,module_type,real_name,scan_token,type,mer_id)，分类完整6列(id,pid,name,enname,type,mer_id)；固定mediaOwner、module1/type0。pid0为全部分类，正pid先锁定本owner/type0分类；list/count使用同一过滤，att_id DESC。显式投影去掉scan_token，保原平台list/count、SystemAttachmentServices62–76中site_url非空才改URL/日期及att_dir可能采用已处理satt_dir的既有分支；不顺带修历史DTO表达式或换merchant DTO。LegacyWrite.finish对非self仍记录product审计changed_fields=[]，所以不声明GET全库零写；商品/SKU/库存和素材原行不变才是正确局部断言。

POST接收multipart file及pid，先限定jpg/jpeg/png/gif/webp扩展，再调用原Storage深层MIME/解码/配置/物理核对，防原MP4分支在image-only入口写文件。物理前后、metadata完整插入及重选均核同writer PDO/事务与mediaOwner；完整真实14列再交locateForProduct，不拼body row。scan_token固定空、module1/type0，元数据取实际对象及服务器时间。补AuditLogServices.record的实际admin/actualOwner、legacy-product-media-upload/attachment/actualId/success审计，只用现允许changed_fields，不加product_id或扩白名单。成功保持原上传成功/src。失败保持原六键receipt与未知提交状态，只允许按原可证明条件清理本次确切新对象，不删除旧文件或宣称物理事务回滚。

限定产品文件8件（均相对本Dev）：修改crmeb/app/adminapi/route/product.php及crmeb/app/services/marketplace/product/LegacyProductAccessServices.php；新增crmeb/app/adminapi/controller/v1/product/PlatformProductMedia.php、crmeb/app/services/marketplace/product/PlatformProductMediaServices.php、crmeb/app/dao/marketplace/product/PlatformProductMediaDao.php、help/migrations/marketplace-ms4-product-media-menus.php及同名.md；最后为本既有record。路由置于通用product/:id前并用原有限writer option。forCreate(Request,callable)委托run([],callback,true,false)，forProduct(Request,int,callable)委托run([id],callback,false,false)，先核真实Request相同及两action。既有mediaOwner/Storage两件不需继续改。

先在仓库外final-enabled-upload-t4-product-media-source-r1生成上述7件完整候选及NOTES、DIFF、PINS，明确冻结后Root/非作者核必要生产链、完整diff/同PDO与权限交集；代理不直接写Dev，不执行Git/Docker/PHP/SQL或实际目标。Root后续统一安排真实PHP8.2语法、新正向隔离业务与接入；不与当前R41真实normal资源窗口混跑。验收要有self创建/既有self/非self的合法列表上传、跨角色组合正常授权、实际文件/完整metadata/审计、重复菜单零变和旧功能保留；不恢复用户停止的负向/安全/故障/并发样本。Source/lint不签接口或页面通过。

后继原T4仍包括分类管理、改名/移动/删除、扫码/在线/视频、平台图导入、三端真实调用及image/SKU/slider/HTML/video/recommend最终引用门，原T5–T8 purpose/private/历史及enabled=true全门保持。L2本地、Task9关闭、未推送未部署，真实历史、金融、三端完整验收和人工签收仍未完成。


T4新菜单CLI完整结构资格补记（实施前）：原stock-menu compatible把TRIGGERS空集合作无trigger，而未证明provided PDO有可见资格。本次新CLI不复制该缺口；沿T3 r3已落实的完整观察合同，在稳定授权后由独立main新建非持久结构PDO，正向证明CURRENT_USER有限ASCII实际账户及目标database直接库级SELECT/TRIGGER两权限，以精确BINARY schema定位并在完整观察前后核authority/账户。仅在此资格下检查菜单22列/角色6列及indexes/constraints/triggers；不GRANT/USE/SET ROLE，不扩audit_app四DML，不复用授权前已有会话来冒签，不改现AssetSchema或旧stock CLI。该落实不增加业务表/接口/产品文件数，Source不代签真实SQL或权限效果。


T4菜单角色列数实施前勘误：Source作者复核原安装crmeb.sql并由Root再次读取确认，system_role实际完整5列为id/role_name/rules/level/status，前述两API草案中的“角色6列/其它5列”系计数误写；本步须核真实角色5列且保rules之外其余4列，禁止造第6列、改表或漏字段。附件category加mer_id后完整6列、附件完整14列和菜单完整22列保持。新CLI及NOTES按真实5列实施；没有运行SQL或改变既有数据库。旧设计原文作为历史前缀保留，本勘误为当前约束。


T4新两action合法NULL角色并集修正（r3实施前）：独立Source审查发现原LegacyProductAccess current collector在新分支前会将每个当前启用角色rules交ids，合法nullable TEXT的NULL会抛AuthUnavailable；PDO column保留NULL且模型没有getter转换。若其它当前角色已具备完整newleaf/FILE/save三权限，额外NULL无授权角色仍会让新API报503，不满足当前角色授权并集目标。此为新两API范围内必须完成的普通兼容，不以继承旧行为当新接口通过。仅为两新actions在原collector之前建立独立有限分支：当前真实有效roles照旧查，NULL仅贡献空集、空串沿原ids为空，非NULL继续原ids严格验证，三AND可分散角色且完整保持；原旧17actions的98–107 collector/单grant块与控制流逐字保留，不扩大改动。r1/r2冻结保留，r3仅改Access，其它六候选完整相同。后续正常业务需覆盖合法三权限分散角色加另一启用NULL角色的成功组合；不恢复用户停止负向/安全样本。r2其余核心/DAO/菜单Source充分核对保留，但r2整体不签新API Source通过；PHP/SQL/HTTP/UI仍NOT RUN。


T4两API r3源码已本地接入Dev（2026-10-22，未运行）：Root采用双审通过的七完整候选，两新API/controller/service/DAO、Access/route及菜单CLI/md均与冻结r3逐字相同；Rootf2220e完整50件11483375B/native9与非作者23件425885B增量Source确认合法NULL角色并集修复、新分支先于旧collector且旧17完整原文保持，其余六件复用r2充分Source检查。原九件WIP在接入七件前后完整字节保持，当前仅本record再append。本轮没有PHP语法/SQL菜单迁移/HTTP/UI或正常业务通过，没有暂存、提交、推送或部署。当前R41交易S12736仍在运行，素材后继实际语法/正常API验证必须等本实例普通收尾和独立zero后再安排；Source实现不代签完整T4、三端或最终enabled=true。


## 2026-10-22 T4 平台商品图片九入口：分类与整理实施前设计

本步在已接入Dev的两图片API基础上补七个整理入口，承接唯一T4；不把九个接口等同完整T4或最终enabled=true。实际Dev HEAD仍13479、原有16件WIP，Main/Val clean bd217与当前R41冻结运行保持；本步只改同七产品文件及本既有record，不改LegacyProductWriteServices/Storage/旧员工DAO正文，不增加表、通用owner上下文或仿造MerchantContext。先在仓库外final-enabled-upload-t4-product-media-crud-source-r1生成七完整候选加NOTES/DIFF/PINS共十件，明确冻结后Root和非作者Source复核，再统一采用。代理不写Dev/Main/Val，不做Git/PHP/Docker/SQL/HTTP或目标资源操作。

以下P=product/product/<product_id>/media，均保原Boundary→AdminAuth→LegacyProductScope→AdminCheckRole→AdminLog链及真实Request→原writer真实商户/商品锁→同PDO/mediaOwner。product_id=0仅沿原allow_create真实self范围；已有self/非self保持真实owner。每项必须同时有新leaf、对应旧FILE grant、实际POST product/product/<id> save grant，三项可分散在当前启用角色并集；合法NULL role贡献空集。旧17action完整collector和单grant块逐字保留。

|方法|新路径|新action|对应旧FILE grant|
|---|---|---|---|
|GET|P/categories|legacy-product-media-categories-list|GET file/category|
|POST|P/categories|legacy-product-media-categories-create|POST file/category|
|PUT|P/categories/<category_id>|legacy-product-media-categories-update|PUT file/category/<id>|
|DELETE|P/categories/<category_id>|legacy-product-media-categories-delete|DELETE file/category/<id>|
|PUT|P/<att_id>/name|legacy-product-media-rename|PUT file/file/update/<id>|
|PUT|P/move|legacy-product-media-move|PUT file/file/do_move|
|POST|P/delete|legacy-product-media-delete|POST file/file/delete|

菜单CLI从两leaf扩为九leaf及完整旧FILE映射。既有两leaf完整22列/native IDs保持；仅按每role实际旧FILEgrant追加对应newleaf，不要求同role另有save。roles完整5列、rules之外4列、NULL/empty原值与disabled状态保持，原rules字符串只追加所缺授权；完整before→独立expected→after与repeat零变、稳定授权后fresh非持久migrator的直接SELECT/TRIGGER正向资格、BINARY元数据与原锁未知/单次条件释放合同保持。不得凭body/安装固定ID授予权限。

分类只操作真实owner/type0图片分类完整native6列id/pid/name/enname/type/mer_id，沿旧名称数据库collation作同owner/type0唯一检查，不引入平台新表的binary名称语义。完整树最多10000节点/64层，父存在且同owner/type0，无环、无孤儿；新增name/pid/type0/enname空，以真实INSERT ID完整重选，修改仅name/pid其余4列保持。有子分类不能换父，不能隐去跨owner/type的子节点后误认叶子。GET保name/pid/all语义及旧list、title=name、children/expand或lazy loading=false/children=[]形状，all1用完整树；name过滤仍来自实际SQL/数据库collation。有限级联pid如沿旧表单接入，只接受合法ID列表并使用末项实际parent，不能将空或异型输入强制变0。

叶分类删除先锁完整分类、实际父和所有真实pid引用。先拒绝任何子分类以及不支持的跨owner/module/type或无法确认的附件链接；查询不能先加owner过滤而静默漏掉坏引用。所属商业图片附件完整14列保持，只把实际pid改为有效owned image父分类或0，并逐行完整重选确认其余13列不变，再删分类。映射marketplace_platform_asset_category.legacy_category_id仍引用该真实ID时不能删除。无物理I/O，不改文件；对实际变化附件记录changed_fields=['pid']及实际分类删除审计。

重命名沿body real_name非空UTF-8上限255，仅改完整14列中的real_name，不改name、路径、key或物理文件。移动沿旧picker body images有限逗号ID字符串与pid，正ID去重排序且最多50，完整锁所有selected真实owner/module1/type0附件及目标完整6列分类（0为未分组）；仅改变不同pid的行，完整重选其余13列保持。完全相同afterstate不执行目标DML、不新增素材业务变更审计；原writer非self GET/操作的product changed_fields=[]审计仍按原实现存在，不能签整个数据库零写。新增/修改/移动审计仅既有allowed changed_fields和实际admin/owner/resource ID，不加虚构计数或任意detail键。

图片删除完整锁14列selected集合，每行交原Storage.locateForProduct并核同writer PDO/owner，以实际Storage.identity去重物理对象。物理前做旧持久文本/JSON/二进制字符串aliases引用检查，以及三个已知新表的实际整数映射引用检查：marketplace_customer_asset.legacy_att_id、marketplace_platform_asset.legacy_att_id及分类删除的marketplace_platform_asset_category.legacy_category_id。MerchantAttachmentDao.assertUnreferenced实际是实例方法；可在已核同PDO的原真实事务中调用，不伪造ctx或改旧DAO，其文本扫描不能代替整数映射门。已知映射必须直接查询实际表/列，缺表、缺列、未知结果在任何物理删除前失败，不按未发现跳过、不调用需要TRIGGER资格的整个AssetSchema，也不扩audit_app权限。T3真实三表check/apply/check/repeat是启用这些删除入口的前置，当前尚未运行。

删除复用原Storage.remove，顺序为全selected/引用确认→实际物理对象删除→严格metadata删除与完整重选→实际admin审计→原writer提交观察；在同PDO前后核资格，不能只删可用子集。保原六键receipt，physical_changed在已知本地unlink后才true，云ACK仍保未知null而不虚称已读云字节；database_committed在尝试DML/提交后未知时保持null，成功只能在原writer正常返回后为true。部分unlink无法用DB rollback恢复，删除失败不调用cleanupNew、不伪造旧文件还原。upload现有cleanupNew合同不变。

验收清单：[ ]七候选+NOTES/DIFF/PINS冻结；[ ]Root/非作者完整Source/旧17及旧两入口保持复核；[ ]当前A正常结束且原普通close/独立zero后实际新六PHP固定8.2纯语法；[ ]按当前完整MS4结构底座和真实T3三表、九leaf首次/重复迁移，独立完整SQL/Redis/受控文件oracle；[ ]真实创建self/已有self/非self九API正常分类/列表/上传/改名/移动/叶分类迁移与合法未引用删除，三权限分散角色及额外NULL角色成功；[ ]原员工附件和原商品正常保存保持；[ ]真实HTTP/菜单/文件/metadata/审计/提交与正常收尾/独立核零；[ ]后继真实UI接线与三宽验收。Source、语法和迁移不代签HTTP或UI。旧picker GET file/category/create与/<id>/edit表单仍需后继商品范围submit URL/本地表单，不能把新树操作提交回旧全局file接口。

完整T4其余扫码/在线/合法视频、平台图导入、最终image/SKU/slider/HTML/video/recommend引用门及真实平台/员工/移动/OutAPI/拷贝后台消费者继续保留。Source盘点已发现self提前return、OutAPI未调用saveReferences和远程拷贝/队列写回绕口，后继须按真实parent/actor/同PDO共享typed验证补齐；不能只改self条件。现员工MP4生产者合法但元数据type0，旧平台视频type1入口又缺真实物理证明；最终type1视频是待统一的目标，不猜历史type0是图片或自动重标。历史真实映射/旧权益、T5–T8 purpose/private与恢复、backfill/enforce/enabled=true、完整A→B三端/N1N2、MS5真实金融/MS6/人工验收仍未完成。L2本地，无push/deploy/真实资金；Task9关闭及用户停止样本NOT RUN保持。


T4九入口请求体实施前澄清：独立只读核对原uploadPictures/index.vue446–476及SystemAttachment::delete58–64，原删除POST file/file/delete取body ids（单ID或逗号字符串），并非images；images逗号字符串只用于move。新P/delete沿ids有限正ID集合（单ID或逗号串、最多50、去重排序），新P/move沿images逗号串+pid，不把两者混用。类别FormBuilder/create/edit的URL接线仍属后继UI，不以旧全局URL代替新商品范围。未读取未冻候选或运行目标。


T4九图片入口资格实施前修正：Root74f2a2再次实读Storage69–114与MerchantAttachmentServices98–101，确认合法员工MP4会保存module1/type0；现r3图片list仅按这两列过滤，会把此类视频计为图片。这是明确Source语义缺口，不能只因新七动作而保留错误列表。九图片API统一使用商业owner/module1/type0及实际完整row的图片MIME资格；沿当前ReviewDao19既有图片集合image/jpg、image/jpeg、image/gif、image/png、image/x-icon、image/vnd.microsoft.icon、image/webp，不扩新upload原5扩展/原MIME/GD核验。列表/count同一SQL条件加入该有限MIME集，完整14列仍保，existing DTO分支及页序不变；重命名/移动/删除实际锁全selected后逐行核，不能跳过selected坏行后部分成功。叶分类删除读取所有引用，遇video/未知或跨owner/module/type行在任何修改前失败，不把其自动移动或重标image。这里MIME元数据分类不是完整字节/解码证明，最终typed引用门与历史确认仍未完成。旧员工MP4入口/旧generic列表及Storage正文完全不改，合法视频支持继续按后继T4目标落实，不为图片接口停掉视频。

分类级联输入另按真实旧表单保持根0/[0]正常语义：有界列表逐项核非负合法ID、末项实际parent并由完整owner/type0树核归属；空/异型列表不自动转0。旧picker全部图片的treeId=''是UI哨兵，后继接线显式映射0，新API不靠空字符串强转授权。


### T4九API r1正常批量删除缺口及r2实施前窄修正

r1候选exact10已冻结，Root5b8a04的50件11441490B全文/native9两读及路由/action/菜单映射、旧17/旧两方法/DAO有限MIME接缝/CLI原完整authority锁main均保持。独立主审53件11542624B、菜单审21件10578472B读取窗均已明确结束。r1整体不签Source PASS：Source确认新selectedIds允许50，但delete把全部objects的aliases一次传原DAO；旧DAO141拒aliases>500，普通http site_url下每本地图片四family共24唯一alias，21张504、50张1200，合法正常批量会在物理前503。Root1ad7ab实读同producer/consumer，非实际业务失败或目标样本，未运行PHP/SQL/HTTP。

r2只改新PlatformProductMediaServices.delete的文本引用接缝：对实际identity去重的每object调用Storage.aliases([$object])，逐次仍传完整selected ids给原实例assertUnreferenced并前后核同writer PDO/mediaOwner。必须完成所有对象的全部alias/已知整数映射检查后才进入原remove循环，不允许逐检查逐删、漏alias、降50上限或扩旧DAO限制。原shared DAO/Storage、三表mapping、定位、全14行metadata、审计和六键真实未知收据全部不改，其余六产品候选全bytes等r1。先在外部final-enabled-upload-t4-product-media-crud-source-r2形成同七完整候选+NOTES/DIFF/PINS exact10；旧r1冻结及其Source P2保留，双审后Root才采用。语法、T3迁移、九API正常实测/UI与完整T4仍未运行。


### T4九图片API r2源码已双审并接入Dev（未作运行验收）

Root01ff82对r2 60件11649545B全文/native9双读确认：仅新Service逐对象alias接缝完整正逆闭合，六其它产品文件全等r1，r1十件完整保持，Dev40最新来源当窗保持。非作者增量30件605331B/完整native9窗口已结束并Source PASS；其r1主体53件和另一菜单21件审查复用，原r1正常批量P2不改署。新Service16817/92694cdb802a3b5caab5a86ea8e14421937a12241b1503c45780d6c049da8157，r2 PINS28777/e0800f72622bc031a8618ad187d296d78b19a212800ab639478c037251f22897。所有去重对象完整文本aliases和整数映射检查在唯一物理remove循环前完成，每次完整selected ids及同PDO/owner，不降50或改shared上限。

Root仅将r2七完整候选采用到当前Dev真实r3 baseline，接入后逐字读回，全八其它原WIP保持；随后仅追加本record，旧73907完整前缀保持。没有stage/commit/PHP/SQL/HTTP/UI或额外Docker资源，当前R41仍不消费Dev新素材代码。旧Source读取/native9快照是已结束窗口，合法采用/本追加不把旧窗口改为失败，也不当作当前Dev永久基线。

九图片API源码现含分类增改查删、列表/上传、改名/移动/删除及配套九leaf/原权限三AND并集；Source PASS不等于语法、T3三表真实安装/迁移/权限、菜单首次重复DML、新旧HTTP/物理文件与审计或UI通过。待原A正常收尾/独立zero之后，安排本次六PHP语法及有限L2 Source checkpoint、新完整当前结构底座的普通验证（含合法批量范围），原视频/在线/扫码/平台导入/typed最终引用、三端/private/真实历史/最终enabled=true/MS5金融/MS6与人工验收继续未完成。


## 2026-10-22 T4在线图片与扫码共同字节叶子：实施前设计

九图片API r2已实际接入Dev但未运行；本步承接同一T4，先实现在线图片及供后继PC扫码claim共用的合格bytes→商业对象叶子，完整扫码目标保留。只读主窗38件616201B、在线子窗9件126318B和独立25件290233B已结束/fullbytes与Python整数native9保持。真实线上旧链是SystemAttachmentServices.onlineUpload→CopyTaobaoServices.downloadImage直接curl/readfile→UploadService.stream→全局metadata，非HttpService；stream本身不执行图片validate。旧global下载/上传、原员工MP4及multipart/move正文不改，不先全局save再补mer_id。

新增POST product/product/<product_id>/media/online_upload，action=legacy-product-media-online-upload，沿旧POST file/online_upload grant。原真实Request/完整middleware/当前角色并集三AND（newleaf+对应FILE+product-save）、NULL角色空集及原17动作完整控制流保持。菜单9→10只新增一leaf/旧FILE映射，保原九完整leaf/nativeID、roles5/menus22、rules原字符串前缀、fresh结构authority和同session锁合同。

请求仅pid与images，images为1..50个有序URL字符串，保顺序和重复项，不静默跳过失败。每URL最多2048字节，HTTP/HTTPS，无userinfo/fragment/控制字符。实际raw bytes不trim；每图不超过现Config upload.filesize（当前Source为52428800），本批下载总量最多64MiB，先完整预取/资格检查，未成功整批不创建商业对象。网络阶段在首次真实Request授权之后、商品writer事务之前进行，避免网络等待长期占实际商品锁；最终进入writer时重验当前角色、实际商品/商户锁及分类与mediaOwner，不把先前scope或body owner当最终授权。

商品专用下载器使用PHP8.2实际支持的cURL：仅HTTP/S GET、TLS证书/主机验证、无代理/NETRC/认证或Cookie转发；最多3次手动跳转，每跳重新验证URL/目标并固定本次已验证地址，采用现Guzzle PSR7 Uri/UriResolver解析相对Location，不新装依赖。公网目标采用PHP8.2 FILTER_FLAG_GLOBAL_RANGE资格（并保保留地址检查）；唯一非公网例外为当前配置site_url的精确origin且位于实际public磁盘URL /uploads/之内的规范路径，不是按客户端允许任意内网。DNS只解析当前目标、有限CNAME/地址集合并在cURL RESOLVE固定；最终实际peer须与本次pin一致。每跳cURL超时不超过10s/剩余预算、连接不超过5s，预取以单调时钟60s预算逐步核；同步系统DNS不能伪称可硬中断，解析前后核预算并如实保留环境前提。每响应header最多64KiB，跳转及响应体均计下载预算，最终只接受完整transport/HTTP200。不能调用getUploadInfo另发HEAD、把Content-Type或URL扩展当图片证明，也不返回原URL/错误细节到审计或公开错误。

PHP官方确认PREREQFUNCTION要PHP8.4，不能给本8.2方案用： https://www.php.net/manual/en/curl.constants.php 。GLOBAL_RANGE从PHP8.2可用： https://www.php.net/manual/en/filter.constants.validation.php 。这些是API设计依据，不是当前环境运行证明。已有AdminLog仅记录route/actor/IP，不记录body（Root64a67c实际Source），新业务审计仍只allowed changed_fields，不保存原URL或查询串。

Storage新增两个有限方法，旧正文完整保持：qualifyImageBytes(string $bytes): array仅资格检查，无写文件/owner/Request变造，返回extension/mime/size/width/height/sha256；JPEG/PNG/GIF/WebP与现配置五扩展、finfo真实MIME、getimagesizefromstring及GD完整解码/尺寸相符。扩展由实际内容及允许配置选择，不要求远程URL有扩展。解码前以实际有限PHP memory_limit、现memory_get_usage、宽高估算和保留量核足够预算，不能把压缩文件大小当解码内存；不关闭GD检查。uploadBytesForProduct(int $productIdOrZero,string $bytes,string $displayName,array &$object,array &$receipt): array再独立核资格并在原writer同PDO/actualOwner前后核，返回现7个真实metadata字段。displayName用服务端URL hash加已确认扩展，不把URL/query或client mer写metadata。

正式对象仍server owner派生merchant/<owner>/<random16bytes>独立命名空间和新随机32hex文件名，长度匹配原name100/路径200。本地按真实Filesystem public配置根写，使用已存在的真实临时File+公开putFileAs接口（临时文件必须位于非public私人位置、真实长度/身份/hash与关闭均核），不用Local.stream硬public/uploads及filesystem.default猜根；写后完整字节/hash/size/MIME重读与定位确认。在线沿旧online明确不生成缩略图/水印，satt_dir为同原图；原multipart处理行为不变。云使用fresh实际storage/原observed driver.stream，显式不启缩略图，沿原实际type4/6/7 transport/http观察及其他SDK返回，真实fileInfo和locator建立对象；不把云ACK说成独立读回云文件字节，真实云渠道仍待后继L4正常证明。

全部预取和图片资格通过后，在一次原writer范围先创建全部正式物理对象，再尝试全部完整13字段INSERT/14列重选、locateForProduct identity及逐件实际admin/owner审计，保scan_token=''及module1/type0。只有原writer正常返回才database_committed=true；首次metadata尝试前即null。每对象用独立六键physical收据，批级聚合保any true优先于unknown/null，physical_complete仅全部对象完成才true，不能第二件失败抹掉第一件已发生事实。失败只在明确未尝试DML时对本批确切新对象cleanupNew；一旦提交未知不清理/自动重试/假称回滚文件。私有临时File关闭未知不得被商业对象清理成功覆盖成cleanup_complete=true。正常API返回原“上传成功”及新增实际list[{att_id,src}]（从原writer真实保存行投影，便于新picker消费），不回scan_token/私有路径。

产品范围八件：修改现PlatformProductMedia controller/service、route/product、LegacyProductAccessServices、MerchantAttachmentStorage、菜单CLI/md七件；新增product/ProductMediaRemoteImages.php。PlatformProductMediaDao保持完整不改，继续旧add与native14完整重选。仓库外先分两个Source-only候选：byte叶子仅Storage+NOTES/PINS，online其它七件（含新downloader、不含Storage）+NOTES/DIFF/PINS；都明确冻结后Root合并为exact11（8完整候选+三辅件），Root/非作者双审才采用。代理不得写Dev/Main/Val/record/canonical，不运行Git/PHP/SQL/HTTP/Docker/目标；不与原S12736并行额外collector。采用后需本次八PHP实际语法（原六含DAO，加Storage与新downloader），不拿旧Storage语法0代签新bytes叶子。

扫码后继保持免平台登录：实际公开/adminapi/image/scan_upload与router mobile_upload是现流程，并非会员/api/upload/image module3。采用发行PC真实admin+实际product/create/owner/category+当前身份版本/session/purpose/原600s绑定的独立随机能力；手机仅合格图片server staging，不直接system_attachment/商品DML。PC真实Request发行/轮询/claim/关闭并重新核三AND、当前issuer及原writer同PDO/owner，claim才写正式对象/native14/admin审计；旧writer不增加匿名或伪admin分支。发行/读取/claim/关闭及独立公开stage入口需后继具体设计，使用有界非公开暂存介质、数量/字节/生命周期和提交未知合同，不把Redis+SQL说成原子事务。当前add.scan_token空值硬门保留，另设有限server-issued stage/claim标识接缝（32字符内，不收client uploadToken）后再实现扫码metadata。不得仅换图片GET而继续global扫码fallback，也不强制手机管理员登录来缩小功能。

验收继续现唯一T4清单：[ ]共同bytes叶子/online Source冻结双审采用；[ ]本次八PHP语法；[ ]self创建/已有self/非self合法单图与有序批量在线，含无URL扩展及同站已有公开图片；[ ]原图片/员工MP4/旧商品保存保持、完整DB/Redis/cache真实允许delta与全部文件/原生14/审计/提交；[ ]独立扫码发行/stage/PCclaim/关闭及scope全链；[ ]PC三入口和实际手机/员工UI及三宽正常验收；[ ]普通收尾和独立zero。网络样本/运行本次均NOT RUN，用户停止样本不恢复。视频、平台图导入、最终typed引用门、T5T8/private/真实历史/backfill/enforce/enabled=true、完整A/B/三端/N1N2/MS5真实金融/MS6/人工验收继续未完；L2本地不推送部署或碰真实资金。


## 2026-10-22 T4共同bytes与online：冻结双审及云收据兼容修订

共同bytes exact3已冻结：Storage36002/1f0a48dcf23188986c8613f7668bda11aa30f1dec57ba4678f989be8f527acf6，PINS20939/00988903ab84a3773cffa1983ad6e7920c08aee2cc1b8331f063d33c2664eda9。Root23件375951B完整字节/native9及两插入whole正逆通过；非作者25件380098B首末保持，报告NOTES7161/03b916e445b86abde168b226d13f4f93963fb4e3904ce7474a4085289f1edb13，PINS13241/54625e0a701b46315afed16f782e985922b76e032fdb3301eb56d6334e328844。旧18方法/非函数字节保持，新增两方法SOURCE PASS；窗口明确结束，未运行PHP/目标。

online exact10七候选已冻结，PINS15806/b168676e8341b49882a8ba6318d324d6a8c4ae4009d1c97711669f3120fa47fd。Root与独审分别完整36件571700B/native9保持，原九API/old17/菜单authority与锁正文反向保持。双方确认一个P2：Service157强制physical_changed===true，而共同bytes叶子对真实云stream ACK只确认physical_complete=true，physical_changed=null以保留未独立读回云bytes的事实；因此正常云上传会在metadata之前被caller拒绝。原r1保留此不通过结论，不将SOURCE当业务结果。

修订设计与任务：Root在新的combined exact11（八完整候选+NOTES/DIFF.patch/PINS）仅把该caller条件改为physical_complete严格true且physical_changed严格属于[true,null]，false仍拒绝。原online其余六件与共同Storage整件保持冻结原字节，六键聚合any true优先null、临时cleanup false、首metadata尝试前数据库false/尝试即null、unknown后不清理、实际writer正常返回才true全部保持。不是将云unknown改true或省略type4/6/7 ACK。记录本段后才生成修订候选，再非作者复核该条件和完整八件后采用；本次八PHP语法与实际正常业务仍NOT RUN。

CURLAUTH_NONE的待核假设已由官方PHP8.2 curl.stub.php1210–1213实际声明排除（https://raw.githubusercontent.com/php/php-src/PHP-8.2/ext/curl/curl.stub.php）；仍不代替本镜像实际cURL资格。下载已有单图50MiB/批64MiB有限预算，RAM接收余量为后继实际资格关注项，不冒称已发生OOM或增加停止样本。扫码私有staging只读具体设计已形成27源建议，容量/claim权限/GC接线尚待正式落实，未实施或计完成。原S12736仍在完整正常履约阶段，内部测试付款已验证，完整A/B/三端与T4其余目标不变；L2本地不推送部署。


## 2026-10-22 T4在线与共同bytes：本地实际采用

Root按冻结combined exact11实际采用八件到Dev：新增独立ProductMediaRemoteImages，其余七件替换为已审完整候选。combined PINS43822/148eaae6be6b914f1fb39a5b4716a6aaecaa9035e18c4871b92078132d8fe407；最终Service23479/8c4e22ce31868567e2457a6697f54a3c9e3c397487b4aed4a8b9857eaf714fb0，Storage36002/1f0a48dcf23188986c8613f7668bda11aa30f1dec57ba4678f989be8f527acf6。非作者组合SOURCE PASS报告4221/904f5fff9af13459b1b2491b2a1ba034bbdf5d586681a641b2d8017db5f7853e，66件1036825B完整字节/native9保持且窗口已结束后才采用。云complete=true且changed=null正常路径已接通，false仍拒绝；其余六online及Storage整件与先前冻结完全相同。

采用前当前Dev16项脏文件/HEAD13479及Main/Val clean bd217均核实；只新增第17项downloader。DAO及另七件既有改动完整字节/native9保持，本记录仅追加且86757字节原前缀保持；未stage/commit/push/deploy或运行目标。本次八PHP语法仍需原S12736正常收尾和独立核零后执行，T3三表/菜单迁移、新10API全正常HTTP与物理文件/DB/Redis/审计、PC与手机扫码/UI/三宽验收均未由SOURCE或采用替代；完整T4与项目继续未完成。


## 2026-10-22 T4扫码：实施前统一合同与分步任务

先前三个只读设计窗均已结束：issuer25源306635B（NOTES13958/dfce3a52d0cdfe137ed88b18144496a7934b061b08b7259f41bb1a060f6e9987，PINS10601/a5b71687f4aa8ceed0cc05a3e8dc935d468db24fcf1e241ab92b2ec058d8c7c2）；staging r2的26源335992B（NOTES20815/5457fd37e8ad67aeaa689ea7802cb7c4590e2946518b0cf6ea49e9df6324821a，PINS10985/688a3cd35be6621de8363472ab07dae7db5d94e4869d5dbc957977d0e15dd0bd）；UI18源358320B（NOTES11701/b37386005bc706575a9316ebfafa9e87c5618a5c37653355515b482da013312c，PINS8882/a8fe3508a6688ae1446492ca0a92ad47650f212cfb911c7257320462421c78c1）。Root另读current27源332144B/fullbytes/Python整数native9，READ11356/15db0754d4b342809e078246b983ee636977e7451d0a27060779cab20e6025be；旧record88029窗口均结束后才追加本段。以下为正式取舍，覆盖建议中不一致签名/字段，不把建议本身当实现或运行。

目标仍是保留手机免平台登录的完整扫码功能。手机只有独立有限capability可上传合格图片到非public暂存，不能写商品/system_attachment或伪造admin Request；电脑必须真实原admin Request/PlatformScope及当前newleaf+对应旧FILE+product-save三AND，原writer同PDO/实际mediaOwner才创建正式对象/native14/admin audit。原旧17/当前10图片API与最高管理员有限语义保持，不先global上传后补mer_id。

### 身份与权限闭集

AdminAuthServices.parseToken保原两个入参、验证次数和admin DTO，只增加第三个可选by-ref ?array verifiedSession输出：入口清null，原AuthTokenV2.verify实际成功三元组id/sid/version在原DTO完整构造后才输出。Request新增私有四键id/sid/version/fingerprint，只由现middleware在原认证成功后写入，fingerprint必须等于现已核token指纹，入口/finally均清。AuthTokenV2、AuthSessionStore及verify_session.lua原正文完全不改，不存JWT/pwd，也不自行decode或重新解析header猜会话。

私有issuer精确五键id/sid/version/fingerprint/epoch；前四来自该真实Request，epoch从当前AuthConfigGuard取得，type/audience从当前trusted config派生不重复持久化。AdminAuthServices新增verifyProductMediaScanIssuer(binding)只读当前真实admin/pwd/stamp并调用现AuthSessionStore.verify，不初始化/续期/轮换会话。Access新增有限authorizeProductScanIssuer(adminId,operation)（issue/poll/preview/claim/close）映射五个固定新action后调用原private current，current正文完整保持，不能接受任意action或造PlatformScope。

新增ProductMediaScanIssuer有限服务：capture(Request):array返回五键；verify(array issuer,int productIdOrZero,int ownerId,int categoryId):void检查当前会话+原issuer issue三AND+实际target；forRequest(Request,array issuer,string operation,int productIdOrZero,int ownerId,int categoryId):void还核本Request真实writer/action/当前scope/admin四元组与绑定完全一致及本operation三AND；assertLeaseTarget三int仅在实际默认连接/PDO前后相同的SELECT重选product mer_id、merchant真实存在和category六字段owner/type0。product0须owner等于现只读selfId实际配置行。它不在writer外调用mediaOwner，不额外禁止草稿、软删或暂停商户；原writer没有的商户审核/接单准入不自行新增。

P=product/product/<product_id>/media，五新action均前缀legacy-product-media-：scan-issue POST P/scan→旧GET file/scan_upload/qrcode；scan-poll GET P/scan/<lease_id>→旧GET file/scan_upload/image/<scan_token>；scan-preview GET P/scan/<lease_id>/items/<stage_id>/content→同旧collection；scan-claim POST P/scan/<lease_id>/claim→实际旧PUT file/file/do_move；scan-close DELETE P/scan/<lease_id>→旧DELETE file/scan_upload/qrcode。明确preview独立第五leaf，菜单10→15；每角色沿各自旧FILE复制新leaf，原rules前缀/roles5/menus22/authority/锁算法不改。公开POST image/product_scan_upload只stage，不挂管理员角色中间件或扩匿名LegacyWriter。

### 私有暂存、时间与容量

采用staging r2的有限Redis+文件状态协议和内部方法清单，统一purpose为product-media-scan-v1、issuer只存上述五键；废弃建议里重复保存app/type/audience/session_version/token_digest/auth_epoch或issue再验header token的替代写法。新增marketplace.product_media_scan配置private_root默认空，必须显式绑定持久非public根、实际root身份与所有worker/timer可见性；可显式选filesystem.local.root子目录，但代码不猜root。max_open_leases默认16、per_issuer默认4、max_terminal_leases默认64、gc_batch默认16/gc_period_seconds默认60，均为可部署资源配置，不能当固定业务人数限制；缩小配置不逐出已准入责任。每lease最多50项/64MiB，单图沿当前Config upload.filesize且不超过现50MiB合同；PENDING也计数/bytes，不静默去重或部分跳过。

BoundedRedisConnection独立原生连接只存小metadata，namespace沿实际default-cache prefix/DB+固定purpose，不随auth epoch变名；epoch仅在binding，变更后业务拒绝、GC仍发现旧lease。lease/stage/claim/upload-op各server random16bytes→32hex，raw phone cap=random32bytes→64hex仅issue返回一次，server只存cap SHA256，不写日志/私有manifest或公开证据。原生Redis TIME统一issued_at_ms/expires_at_ms（严格可表示整数，期限固定600000ms）；600秒控制新stage/new claim，不延长或接管已经START的原claim。private root身份及binding digest固定，原owner/product/initial category不可由手机变更。

正式采用r2的issue→ALLOCATING/OPEN、reserveUpload(PENDING)→writeReserved(.part/.bin实际完整write/flush/close/native/hash)→publishUpload(READY)、listReady/readReady、beginClaim(START/PROCESSING/DONE/UNKNOWN/GONE)、sealClaimPlan、markSqlAttempted、markClaimed/markUnknown、beginClose/cleanClosed/finishClose/gcBatch有限op。所有script只专用namespace/server ids及固定白名单，不碰auth键，不自动重放未知写；live metadata不靠TTL消失释放资源。PENDING或CLAIMING/UNKNOWN无到期接管，GC不能猜进程已结束。全部私人I/O实际关闭/消失未知保cleanupfalse/hold，不能让正式对象cleanup成功覆盖。

### 认领与普通重复

PC claim请求为有序去重1..50个stage_ids及destination_pid（0或同owner真实分类），保留旧PC最后move可选目标分类能力；初始category仍是手机不可改的binding，destination由实际PC在原writer内重验并冻结进selection/claim-plan，不强制只写初始分类。相同claim重复必须selection顺序和destination均一致；不得改变后当新批。stage保存实际UploadedFile original_name经现text255资格供PC显示；共同bytes叶子仍用server内容SHA256+qualified ext作安全传输名，正式metadata real_name由claim业务层设为该已核原文件名，name/att_dir等真实物理字段不编造。

原writer内先全部物理对象后全部metadata，接受physical_complete严格true且changed严格true/null，保各六键真实三态。全部实际物理字段/owner/destination/time/module1/type0/claim marker/预期audit形成封闭private prepared plan；上界应真实覆盖50项（先以256KiB，编码前核不能截断），完整close/readback/SHA后seal_plan及markSqlAttempted均拿实际ACK，才首次DML并置database null。普通DAO.add(scan_token='')整段硬门保持，新增仅真实scan-claim action同writer/context的addScanClaim(...,serverClaimId)，32hex marker只从实际beginClaim/sealed plan流入。提交仅原writer正常返回才能true，再保存实际完整native14/有序结果；审计record返回void，不虚构audit id。

DONE在任何physical/metadata/audit写之前按实际原rows/marker/owner/identity/audit完整只读核后返回原结果；PROCESSING不重做，UNKNOWN不自动清理或重新写。只有完整plan对应的全批native14/object/audit事实一致才可只读确认已提交，0/部分/缺plan仍UNKNOWN，不猜原SQL会话已回滚。未知hold保容量，不把Redis+SQL或文件与SQL说原子。正常close只清确切私人文件/空lease目录，不删正式对象；确认后才release live配额并入有界terminal，终态淘汰旧ID只GONE绝不新建/重写。

### 公开DTO、UI和正常GC

issue 200 data={lease_id,url,expires_at_ms}，url=/app/upload?scene=product_media&lease_id=...#scan_cap=...；只在PC本地现QR组件编码，不发送第三方。stage 200 data={stage_id,sequence,state:ready}；poll 200 data={lease_id,state,expires_at_ms,list:[stage_id,sequence,name,mime,size]}，不回att_id/att_dir/cap/session/path。preview是实际管理员保护的binary200，实际图片Content-Type、no-store/nosniff。claim只有state=claimed才“上传成功”并给真实list[{att_id,src}]；处理中用200 state=processing/list=[]静态反馈，未知用503 state=unknown/有限六键receipt，不伪装成功。close只在实际清理已证时state=closed/cleanup_complete=true，否则pending或unknown。

PC三入口原8文件计划保持，显式product context默认null（0创建不可假值回落），通用消费者仍走原分支。手机最早捕获fragment后清地址栏及$route.hash，仅独立无admin header/credentials omit传输stage，保匿名及一次20张/继续上传/原600秒；PC Blob预览带现auth header，正常close/切product/身份/销毁取消请求及revoke objectURL，晚到响应不得改旧scope。目标分类变化可作为明确destination，不能把stage id交原global move。新反馈/控件按control-quality实际运行验收，Source/build不代签。

实际现timer→SystemCrontabServices.crontabCommandRun→CrontabRunServices可复用，新增固定marketplaceProductScanGc mark/方法、默认60s与专用marketplace:product-scan-gc-install登记CLI；只登记/核对该一条system_timer并真实刷新完整crontabCache，普通重复不覆写被编辑/停用任务，不改原订单扫描或装外部服务。无新issue仍须实际后台tick回收正常expired OPEN；Source不证明timer已运行/私有盘已挂载。

实施次序：[ ]A Auth/Request/Access+Issuer Source；[ ]B 私有Staging/Lua/config Source；[ ]C PC/public入口、DAO marker/read、原writer/audit/菜单；[ ]D timer登记与PC/手机UI；各自先NOTES/候选冻结/Root+非作者复核再采用，随后当前新PHP/前端编译、真实隔离完整普通HTTP/DB/Redis/private/file/审计、三端尺寸/手机、普通收尾与独立zero。原SOURCE旧窗不重签current，当前S12736不并行额外PHP/Docker/SQL/HTTP。内部付款后Task6本轮已actual normal0、full_source=true且原helper正常0/精确退役，现同S进行售后注册，不据此签完整A/B或扫码。视频/平台图导入/typed最终引用、T5T8/private、真实历史/backfill/enforce/enabled=true/MS5金融/MS6与人工验收继续未完；停止样本仍NOT RUN，仅L2本地，不推送部署。

内部组合签名补明（仍在编码前）：Staging.issue的trustedBinding精确四键issuer/product_id/mer_id/category_id，issuer用上述五键；purpose、root identity、server ids与Redis时间由Staging产生。reserveUpload(array lease,array qualified,string originalName)第三参数保现实际UploadedFile原文件名；qualified仍是共同叶子原六键，不塞client owner。beginClaim(array lease,array orderedStageIds,int destinationPid)把实际PC最终分类并入不可变selection digest；原初始category不被手机改变。readReady返回内部bytes/qualified/original_name/private proof，只有已授权业务服务能消费且不得直接投影HTTP。Access可新增固定PRODUCT_SCAN_ACTIONS五项映射供Issuer复用，原private current和现10项授权保持。


## 2026-10-22 T4扫码五包实际接入与后继顾客评价图片实施合同

Root在全部A/B/C/UI/timer及T5/child只读窗口明确结束后，实际将31完整候选接入本Dev（30文件需要写；旧api/uploadPictures.js全字节相同未写）。A issuer、B staging-r2、C五PC叶/公开stage、UI-r3和timer-r1均已有Root/非作者源码复核。UI r1旧关闭结果跨scope与r2缺import两项NOTPASS保留；r3只补currentProductContext既有import，有限独审通过。当前新增私有扫码stage、PC认领/预览/关闭、真实三个picker入口/Wang v4/手机fragment清理和显式GC注册/实际cadence接线已在产品源码中，不能据此签运行或功能验收。

采用前PRE位于外部scan-all-five-dev-adoption-r1/PRE.json（53823/d890ad09edfd63bba85e25b7d24dd670223821de3ed0cec230cf723b5da86558）：每个已存在目标都匹配实际Source原输入，八新增路径不存在。Root保留22个被覆盖旧文件完整副本；另11原WIP先全bytes/native9保持，此record随后仅追加，另10仍全保持。Main/Val同clean bd217不动；Dev仍HEAD13479/feature/ms4-asset-domains，无stage/commit/push/deploy。实际RESULT另按产生的真实文件记录，不预填未来SHA。

实际检查已具备：前18PHP纯语法0；新timer4PHP纯语法0；余ReviewDAO/LegacyWriter/AssetSchema/CREATE-only迁移4PHP纯语法0（RESULT6855/cd41b34ec394d2151657b333d795027963802bd68ee88adc98ba57bb58d9973d）。UI原10script/7template/65import及r3两改件2script/2template/16import/30具名绑定检查实际0，原三处未定义引用所在原方法整件保持，新增未定义0。原首次检查失败保留。语法/解析均未执行App或HTTP；T3真实三表check/apply/check/repeat、15leaf菜单首次/重复、完整媒体API/文件/native14/审计、phone/PC三宽UI、实际timer与GC以及普通close/独立zero仍待。

T5/T6后继先做真实V1已购评价图片闭环，沿原本节既定完整顾客资产域，不缩减default兼容/private/头像/历史责任。实施前有限方案t5-t6-customer-review-assets-design-r1 exact2已冻结（NOTES15856/90883cfa4c45ed415514c915d83168e86eed0a8777c1c9be477d894268cea69f；PINS17778/07b776a76cad85386af738295cec39330242e1e4f90f4ad0cf1ba3bd69a105f1），42真实来源首末保持。它确认当前CustomerAssetServices/Dao/Storage及domain wrapper均未存在；新方案不能代签R41业务503已修。

下一份外部Source有限11文件：新增assets/CustomerAssetServices.php、dao/marketplace/assets/CustomerAssetDao.php、assets/CustomerAssetStorage.php；修改OrderReviewController、api/route/v1、MarketplaceOrderReviewServices、MarketplaceOrderReviewDao、uni-app api/marketplace-review.js及goods/marketplace_review/index.vue、SystemCrudServices和SystemClearServices。只接POST order/reviews/<root_id>/<item_id>/files（route名orderReviewFiles），固定review_public_image，单实际file，真实已登录UID/root/item/已付款已收货资格；不接收purpose/mer/uid作为授权，不将quote GET或create JSON入口用于multipart。两个公开头像/默认兼容入口本次不发布。

服务签名uploadForReview(Request,int actualUid,int rootId,int itemId):array；Review新增withCustomerUpload(Request,int rootId,int itemId,callable publish):array，由真实customer解析取得actor并在原默认Mysql/同primaryPDO事务锁payment/root/items/user、全资格和同体原评价检查后，将有限可信上下文交publish；内部context仅供新Services/Dao/Storage消费，包含真实Request/actualUid/fullRoot/fullItem/connection/PDO，服务比对actualUid与actor。不能扩AccountChangeGuard的app集合、造MerchantContext/PlatformScope/假admin审计或另造通用事务框架。

图片先验沿当前纯qualifyImageBytes，物理前核真实raw File/配置/bytes/MIME/GD尺寸/hash，customer随机namespace与原1..200reference上限预先验证；local全bytes/native/hash，public云只记实际ACK与三态六键receipt，未知不删/不重写。限频沿真实start_uploads_UID的100/86400合同，保实际副作用顺序和commit观察；具体上传leaf在独立Source NOTES先写清实现接缝，不改商业Storage writer。新完整customer34行只用actor UID及本次锁定root/item；purpose固定review_public_image、STAGED、expires_at=NULL，不新发明TTL/扫码期限，其余用途父列/其他creator/legacy_att_id NULL。数据库false/null/true只按实际DML/commit观察，只有确认提交才成功DTO。

ReviewDao新增publicPicturesForCreate(connection,pdo,fullRoot,fullItem,actualUid,references)内部逐引用domain/全行wrapper。保顺序/重复、旧legacy_public完整14列与原SQL，不伪造历史uploader；新customer_review精确BINARY object_key完整34列、本人/root/item/用途/状态，跨域碰撞或多义不fallback。ReviewServices原196和228两次完整wrapper严格相等保留；其后才在同事务用实际新fullReview/id绑定真实asset，仅允许state=BOUND、review_id、bound_at三列变化，余31全保持，提交前再核全34和实际review对应。原saved sameBody replay早返保持，不因绑定去重改变pics业务顺序。新增Dao绑定接口bindActualReview(connection,pdo,fullRoot,fullItem,actualUid,fullReview,validatedWrappers)与最终readback必须基于原独立期望，不能用AFTER反造期望。

公开响应仍用实际已保存pics的原path/URL，不放宽Input或静默改opaque id。前端具名upload(path,root,item)传当前实际context，attachment_ref存pics、url仅预览，原双阶段身份/late-response guards保持。三资产表须在供业务使用前补通用CRUD与全局清理保护，先完整批次检查；不把AssetSchema完整metadata资格塞进DML App或扩TRIGGER权限。

该11件只是T5先行实施项，avatar、default public_upload_compat、退款/物流/表单/提现QR/执照/聊天private上传+受权读取、外部客户端实际发布兼容门和历史转存继续未完。default upload/image保原路径直到完整门实证闭合；公开五根严格归属/200断言不削弱，不fake mer、不modulefilter、不清NULL。不盲重跑R42。原其余T4视频/导入/最终typed引用、真实历史权益归属/37步四CLI/Task1、finalenabled=true、完整A/B/三端/N1N2、MS5真实金融/MS6/人工验收保持。通用多商户、L2本地和停止样本NOT RUN不变。


### 2026-10-22 本地Source检查点与提交观察接缝（非业务验收）

为使新T4隔离验证绑定完整真实clean来源，Root在全部原record读取窗明确结束后保留外部106282完整preimage（SHA65f455bfea2651766486376a4d5d3cc5a512dd78712def920c483f4255a06e74），仅将23处CRLF规范为LF，未改既有文字。T5/环境作者改用同bytes不可变上下文副本，旧采用/事后复核时的前缀保持事实仍成立；本次是其后合法格式前进，不冒称原live记录仍为旧字节。其它40源文件全文/native9保持。

本次实际本地代码提交为45c253deb79653e494a8c531adb0d3f51e603f08（37路径），00规格链接按规约单独提交为12f015bb48d62b44be334052d9eeebb18d64bd21（1路径）；剩余07/09计划与本record三路径另作本地记录提交，最终真实HEAD由外部事后收据记录，本文不预填自己的未来SHA。全批41路径属于已知T3/T4素材改造及其必要文档，无全量add、无push/deploy或业务库操作。已知强凭据模式扫描无命中、无活动本地hooks；具体native PID/wait/完整双流和逐scope staged/readback在外部dev-source-checkpoint-local-r1。

资格仅为已审Source、26PHP语法（18+timer4+四既有依赖）、前端原10脚本/7模板及后继两改件绑定检查；T3实际169结构/15菜单/新旧接口/文件/审计/GC/phonePC三宽未运行。只有BASE已有用户签收，后续阶段不据Source提交签完成。

另核实际vendor PDOConnection.transaction1370–1384与commit1426–1434丢弃PDO commit布尔值。T5已在自己NOTES先落实：withCustomerUpload正常返回且outside后，完全同Connection/PDO/database/session、PDO无活动事务并只读@@session.autocommit=1，完整SELECT*新asset34严格等callback实际native row且再核parent/当前Request/actor/fingerprint，才以持久行观察标database_committed=true；不冒称取得PDO commit ACK。任一未知或不一致保null，不清对象、不重试，不改ORM/造observer/set autocommit。该必要接缝不改变原pre/final wrapper、实际review绑定、legacy14/原replay/五根门。

T4专用初始化先从同一冻结安装SQL完整真实导入system_menus/system_role/system_config与三张system_lang原始非业务INSERT，空表及全行独立核对，原值顺序不变；不REPLACE/IGNORE、不造用户商品订单付款。再真实9CLI→166、T3四命令169、15leaf四命令及独立writeMd5；不借MS5收据。Source初始化状态只能INIT_ONLY_NOT_BUSINESS，完整普通业务尾及所有原未验责任继续保留。


## 2026-10-22 扫码二维码完整网址修正与 T5 冻结 Source 进度

实际源码调用链确认 ProductMediaScanServices.issue 返回逻辑相对地址 /app/upload，而前端 issueScan 虽构造 URL 校验却仍把原相对字符串交给 QRCode.text。手机二维码因此缺少 origin。真实 frameOut/history 路由是 /app/upload，不应附加平台 /admin 前缀。本次仅采用已冻结、非作者 Source 复核通过的 product-media.js 两处接缝：校验当前前端 origin，并返回 url.href；query、私有 fragment、lease/expiry、其余 DTO、后端和认证/状态流程保持。

既有同一正常正向 DTO 的纯 Node 消费者检查保留 original exit1、candidate exit0及实际 PID/完整双流；非作者报告7664B/e47738edbb0e确认完整模块正逆和其余字节。此为 UNIT-STUB/Source 修正，不是浏览器、实际手机扫码或 HTTP/15接口/GC 通过。采用前 Dev clean bd702、Main/Val clean bd217，读取窗口已明确结束；仅本 JS 与本记录作窄 L2 本地提交，无推送部署或真实业务操作。

T5 先行顾客已购评价图片11候选 exact14 已冻结，PINS25903B/8860c35a56dc；9PHP 在既有 PHP8.2.33 无网络只读非root环境纯语法均正常0，未加载 App/SQL/Redis。顾客完整34字段资产、原实际 UID/根/项、同 PDO 和完整原生行绑定仍须业务证明；候选尚未采用、前端解析及完整运行尚待，不回补 R41 原售后 GET503 或完整A失败。T4新正常环境 Source 仍在外部实现，不把初始化准备称业务通过。

此前“三张system_lang”简称在此更正：真实安装表为 eb_lang_code、eb_lang_country、eb_lang_type，与 system_menus/system_role/system_config 共六个非业务表。后继隔离初始化须保完整原 INSERT/原值/顺序及独立全行核对，不据错误简称造表。

整体剩余范围不变：T4 实际结构/菜单/素材与扫码接口/文件审计/phonePC/自然GC/三宽；T5完整私有用途/avatar/defaultCompat/历史发布门；完整A/B/三端/N1N2；原历史权益归属、37步/四CLI、backfill/enforce/final enabled=true、真实MS5金融/MS6和人工验收。通用多商户复用商品/SKU规格价格，Task9关闭，用户停止样本 NOT RUN；目标继续 active。


## 2026-10-22 T5 已购评价图片先行代码接入本地开发分支

冻结 exact14/11候选已由 Root 复核实际调用链和完整候选，并经非作者有限 Source 复核 PASS（报告104091B/dcb5fccfb4bc）。新具名 POST order/reviews/<root_id>/<item_id>/files 使用真实 api 顾客 Request/UID/已付款收货资格和原实际根项，同原 Mysql/PDO 事务写 customer34，不再把这一评价上传写入商业 system_attachment。保实际 full13 商品项 old_cart_id、full20 评价、原 legacy14/重复顺序和完整 wrapper 两读，再绑定唯一资产 state/review_id/bound_at 三字段。原同体 replay 早返保持；不扩 App 权限、不借 MerchantContext 或 AccountChangeGuard。

9PHP 纯语法实际正常0，原完整 native stdout/stderr 已核；2前端脚本、Vue模板和8个直接本地 import 的最小解析通过，实际 Node PID51876/exit0，RESULT10263B/c57ef46867bb。首轮解析本身0但依赖指纹观察缺私有 he1.2.0 而失败的 ATTEMPT1 保留，补先验依赖 pin 后同输入通过；未改候选来适应检查。此处没有 Uni 编译器/条件预处理或完整构建，不签 UI、HTTP、实际存储/SQL/业务验收。

Root 在当前无新 T4 目标实例、环境 Source 尚未冻结时先接入此11完整候选及本记录；采用前 clean42dd569。原约定保持的是实际目标运行窗口，不能让尚未开始的运行永久冻结完成的代码；后继环境必须显式绑定本次真实新 clean SHA，并在实际运行至普通关闭/独立核零期间保持该来源。冻结包、其余原 Source 和 Main/Val 保持，L2 仅本地窄提交，不推送部署。

本次只接 V1 已购评价图。avatar/defaultCompat、私有用途/其它上传消费者和历史发布门未取消；旧全局 upload/image 仍在，R41 售后 GET503 和完整A失败不回补成功。正常已购上传→新评价绑定→公开读取、原零图/legacy 图与同体重复、全原生 DB/Redis/files 保持、实际云存储和三端页面均须后续实际验证；完整 T5/T6/T8、T4正常接口/扫码/GC、历史最终归属启用、MS5真实金融/MS6和人工验收继续未完成。Task9关闭，停止样本 NOT RUN，通用多商户复用商品/SKU规格价格与 active 目标保持。


## 2026-10-22 已购评价图片的正常消费者接线（源码与纯语法）

沿原T5及唯一实施清单，只采用 help/audit-regressions/isolated/ms4-aftersale-review-normal.php 一件。冻结候选230150B/e5f9fc2cded46cad6e9e11bf9b24bf543c5cbcf9c2edb1486932f24aae4a796c，非作者REVIEW81957B/a001fcd3d423c5b22e9dc0711f6d357f4373970a7ca87f0e30daa2fadb934952为PASS_SOURCE_ONLY，全部窗口结束；Root已读完整有限差分和必要生产消费源。

正常评价图改走真实本人已收货root/item的具名multipart上传，不再把顾客资产编号当旧att_id。独立构造全34原生字段及真实PNG物理文件、原100/86400配额和完整DB/Redis/文件差分；创建评价时只允许同资产state/review_id/bound_at三字段变化，其他31字段和计数器保持，同体重放不重绑。原93未改函数、旧generic完整体、顶层result/business、售后13动作/15重放/5退款/2S2、公开12字段/原图/keep责任保持。

Root固定PHP8.2纯语法实际0，来源与完整native双流见仓库外 normal-upload-consumer-focused-check-r1/RESULT.json22639B/0d79d07258078680612e556ee66c4ebfa9f1f46fba04239abb44999855025cfc。仅源码和语法；尚未运行新正常业务。三asset表必须在paid/Task6基线前真实初始化，不能在AR中DDL或扩App四DML。N2 UI旧generic/legacy14、defaultCompat/private/avatar/历史、R41原失败、完整A/B/三端与真实金融/MS6/人工验收仍未闭合；本地L2，无推送或部署，停止样本NOT RUN。


## 2026-10-22 本人头像具名上传与资料选择接线（源码完成，正常业务待验）

已采用CU_PUBLIC的avatar_public两阶段流程：本人认证上传先保存STAGED资产，返回原name/url/attachment_ref和规范十进制字符串avatar_asset_id；原两个头像入口保存资料时带回该id。后端在同一实际writer/PDO事务先锁本人user、再核本人完整34列资产与原图/canonical URL，经原UserDao保存资料，仅把所选STAGED资产state/bound_at改为BOUND。普通同体再次保存保原资产和绑定时间。原合法外部/历史URL不带id时沿原流程，不猜造资产；昵称更新不误绑定，不自动删除旧图或预览图，不增加TTL/GC。原review、默认及private上传职责保持。

原UserDao/UserServices资料写入及原事件payload沿用，事件仍在实际提交和完整读回之后发出；两Vue只改脚本接线，模板/样式保持。商品、SKU、规格、价格及库存复用原通用体系。本次仅独立Next分支L2源码接入，未修改当前失败Run的Dev/Val/Profile。

外部Source包customer-avatar-public-source-r1共十候选；Root全文审查唯一DIFF/NOTES，独立完整Source复核无确定阻断发现。r1独审报告中native9经JS Number舍入，原报告保留，r2改用Python原生int完整首末校核后才采用，不将舍入值当原生身份。实际固定PHP8.2七文件纯语法均0；原Babel有限H5/MP-WEIXIN/APP-PLUS投影通过，不代表正式Uni构建。Root再次核全部13包文件、29输入、30原生检查PID/完整双流/终态及Next每件before完整字节。证据保留于仓库外customer-avatar-root-adoption-r1。

真实HTTP上传/预览/保存/同体再保存、完整SQL与文件差分、原事件及两头像UI尚待正常验证；本记录不签其通过。完整A/B、三端/三宽、历史归属与旧权益、真实金融、MS6及人工验收仍待，停止样本NOT RUN、Task9关闭保持。无推送、部署或真实资金操作。


## 2026-10-22 资产检查、上传配额读回与三端目录联合修复（源码/语法，业务待验）

原Run2026102212053520120d的early20个CLI及容器、source17/六快照/assets175实际通过，files-bootstrap随后因固定错误码格式自然失败1；原结果/private_cleanup=false保持，未进入后继业务。该Run已按真实身份正常stop两DB并非force退役原4CID/1网/7卷，close与独立zero均native/wait0；Root及非作者实际全量读回保原35174公件/Source30974等，未重启旧Run或回补业务通过。

本次同一次联合采用三个已独审的现有PHP源：客户entry只修9个M4C_错误码前缀、最终assets快照及原6后追加3表保全；CustomerAssetServices评价上传与头像上传两处post-set读回复用原counter规范化真实Redis数字字符串，再严格比目标整数，保set true/100上限/86400/tag、同IO次数、同事务与未知结果receipt；Core后台只修customer专用RW叶、原7+UI4共11卷、gateway4RO、实际精确review34行/原PNG/ref/父链准入与当前entry真实pin，所有原文件/目录和legacy14/phase14-15 oracle保留。Core客户entry原pin已证过时，本次明确同步采用实际114528B/d2b909...候选，并非把未改187声明通过。

实际PHP8.2.33对客户entry/Service及两头像测试候选的4个-l均0；真实Driver serialize/unserialize与原counter合法正常值纯合同0，共22native/5纯检查CID正常nonforce rm。Core -l及exact nginx -t实际0，共10native/2纯检查CID正常rm；nginx仅network-none解析及固定loopback hostname，不签实际DNS/App/HTTP。完整raw/来源在仓库外asset-entry-quota-avatar-php-check-r1与n2-core-nginx-syntax-root-r1。三份独立Source审查及全部相关窗结束后才采用；无活动本地hooks、限定四路径本地提交，实际新SHA另由外部COMMIT.json记录，不预填自己的未来身份。

只有三个PHP和本记录纳入本次Git提交；对应nginx新配置仍在已审外部候选，待新UI17完整装配，不修改原冻结profile。Host/compose/copy/public-copy/browser/close/zero/早期T3及元数据DAG仍待适配；头像kernel/HTTP测试候选本次只语法，不接入或运行。下一步按真实新HEAD重闭完整A来源，正常close/独立zero后再同源fresh B。完整A/B/三端三宽/N1N2、T4媒资/扫码/自然GC、T5其它私有用途/头像/default与历史兼容、旧权益归属与最终迁移启用、真实金融/MS6及人工验收仍未完成。通用多商户复用商品/SKU规格价格，Task9关闭、用户停止样本NOT RUN及T4已拒操作不重试保持；L2仅本地，无推送部署或真实资金操作。
