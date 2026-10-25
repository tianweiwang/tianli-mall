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


## 2026-10-23 私有订单表单图片与移动退款详情接线（本地Source，实际业务待验）

已接入最终35件Source：后端19、顾客Uni8、商家/平台LF六件、移动退款详情一件，以及隔离正常评价图片上传的有限失败收据诊断一件。上传只保存本人确认上下文的STAGED私有资产与opaque引用，首次下单在原writer/PDO事务核真实父项并绑定；保存订单读取按当前顾客、商家、平台或移动资格核真实根与完整资产，票据通过header换原图。原商品、规格SKU、价格库存、保存字段和顺序复用；无私有引用的原下单分支保持。

移动退款页按实际schema2展示原状态、金额、数量、退件区间及运单，依退款真实单号定位原受权根详情，严格核根PK/单号/本人后读取保存表单；凭证复用原私有售后读取，v1处理跳原顾客售后页重核动作，原legacy模板/动作/CSS前缀保留。商家/平台及顾客消费者使用临时图片映射，生命周期结束释放，不将临时URL写回保存字段。

35件具名来源、实际093 before19/ABSENT16、完整补丁正逆和各组件/装配独审已核；最终20PHP均有真实语法通过收据，15前端/33平台目标语法通过。后台r1的CRLF在独立r2只归一为LF，其旧原件和收据保留。代码语法/源码审阅不是实际业务或视觉验收。

采用前原Run20261022134605842b51的正常close84原生和独立zero12原生已实际0、Root完整读回96回执，精确退役其4容器/1内部网/7专用卷并保47295原公件；原093内部Task6成功及评价图片POST175 HTTP503失败原样保留。新增诊断仅保控制器原有六键处理收据，不记录令牌/URL/原图或其它响应，不放行503；当前生产根因细节仍未知。

专用持久private_root仍默认空且需显式配置，未据Source签实际上传/首次下单绑定/多角色读取/原图、SQL/Redis或副作用通过。新Source三端正式构建、正常API与H5/MP/APP/三宽浏览器、完整A/B/N1N2、旧数据历史权益归属和最终启用、真实金融/MS6与人工签收均待；旧093结果不改署本次提交。用户停止样本NOT RUN，Task9新商家客服关闭，目标为通用多商户，L2本地不推送部署或操作真实资金。

## 2026-10-25 CU_LICENCE 私有执照接线设计与执行记录

范围仅为原 CU_LICENCE（本文件298–306行）及 W07：真实本人申请、执照上传、同事务绑定、本人申请详情和平台审核图片。复用34列 customer asset 表及原 division_agent_apply.images，不新增表、列、索引、种子、通用owner框架；不改现form/review/avatar消费者，不涉及退款、提现或T4。当前独立工作树从 b54ad9c39 起步，L2源码实施；PHP/App/Docker/HTTP、浏览器/设备和业务验收本步均NOT RUN。

已核原发布边界：本文件“真实历史、可恢复转存与发布门”要求所有仓库内及外部private消费者完成专用入口及合法读取后才可保默认public_upload_compat；T5先行记录要求default upload/image保原路径直到完整门实证闭合。该边界不等于批准新增默认false的CU_LICENCE开关。本步不增加该开关：新仓库执照消费者始终走具名private入口，根未配置或上传失败只报告未完成，绝不回退公开上传。旧保存URL展示及旧外部客户端原提交格式暂保兼容；原外部发布/历史转存/T8门仍未完成，不据此声称全部历史或外部写入已私有化。

接口与权限：新增POST /api/agent/apply/:id/files（id0=真实本人首次申请上下文，正id必须是本人未删除申请），只接受pics multipart；返回name、attachment_ref（mp-licence:v1:十进制asset id）及受权内容定位。新增GET /api/agent/licence/:asset_id/content作本人预览/保存后读取。平台新增GET /adminapi/agent/division/apply/:apply_id/licence/:asset_id/content；固定新route专用中间件复用原GET agent/division/agent_apply/list的当前真实session、fresh启用角色/menu授权，并保持实际admin.division_id=0全范围、正值只本事业部的原列表语义。内容每次重读实际申请images及完整资产，不借form/refund票据或商品PlatformScope；直接认证响应使用private,no-store、nosniff，不颁发可匿名读取的URL。

数据/事务：所有申请写入强制Request.uid；更新锁本人实际申请，保原验证码/邀请/审核规则。与原ORM writer使用同一真实Connection/PDO，先锁user/申请/所选完整34列资产并核私有原图，再执行原申请保存；仅首次选中STAGED改state/bound_at/agent_apply_id，值来自真实保存后的申请。BOUND重用须由本人原已保存images证明，首次绑定ID/时间不改；不删除旧图或未选择预览图，不新增GC/TTL。原URL分支保持原保存格式；新private引用不得变成public URL或临时显示URL。事务外用同PDO完整原生行及原图读回确认持久结果，不冒充取得ORM未提供的commit ACK；未知结果保留文件且不重试清理。

物理叶复用现form严格文件读写与窄清理实现，但使用独立CustomerAgentLicencePrivateStorage及显式customer_licence.private_root空默认、固定agent-licence/v1/{uid}/{申请上下文0或真实id}/{随机}/content.ext。继续复用CustomerAssetStorage.qualify完整字节检查，不假造confirmation，不改form叶，不fallback public/runtime/cloud。前端只保locator进申请，认证下载成页面临时图；切页/身份变化/销毁释放临时图。原最多10张及原平台图片展示位置保持，状态反馈与上传控件分开。

Files（本修复必要调用点）：新具名CustomerAgentLicenceAssetServices/PrivateStorage/AssetDao及平台Access/中间件/内容controller；原CustomerAssetServices具名委托、DivisionController、DivisionAgentApplyServices/Dao、api/adminapi agent route和marketplace配置；uni-app api/具名图片transport与annex/settled页面；admin api/具名图片transport与division/agent/applyList页面；本记录及既有isolated目录中的正常用例。现form/review/avatar原实现保持零差分。本作者当前只写Source、不提交；Root可在既有L2授权内于Source/测试审查和必要语法之后做限定local checkpoint，以满足clean HEAD真实绑定，不构成新审批或业务验收；推送/部署依原范围另列。

唯一CU_LICENCE执行清单：

- [ ] 实现具名私有上传、完整34列STAGED及真实本人/申请上下文；保持完整文件与未知结果处理。
- [ ] 原真实ORM申请保存的同PDO锁/绑定、UID强制、本人更新、BOUND正常重用及原业务兼容。
- [ ] 本人详情/平台审核受权原图及三端临时图消费者，原保存locator保持。
- [x] 有界Source/静态检查及独立复核：19路径修后独审未有其它确定问题，全部14PHP实际PHP8.2语法0；仅该有界源码项完成，运行/业务/视觉仍待。
- [ ] ISO-FULL正常验证：真实认证multipart→首次申请→同体再保存→本人修改/再申请→本人和原有权平台审核读原图；完整34列/原申请行/文件/Redis/原业务事件前后比对，普通close/独立zero。
- [ ] 实际H5三宽、MP/APP构建/设备、外部客户端发布和历史转存/T8验收。用户停止样本保持NOT RUN；源码或构建不勾业务验收。

本次Source实施已写入：具名上传/34列STAGED、真实Request UID与本人申请锁、原ORM同Connection/PDO保存后绑定、本人及原列表有权平台的认证原图读取；uni-app编辑及待审/通过/拒绝状态的临时图、平台审核临时图均已接线。首次绑定后普通同体保存/后续真实本人申请重用不更新首次agent_apply_id/bound_at，不删旧图；503完整收据为未知/已提交或响应坏包时，前端要求先读回申请而非继续首次创建。旧全局upload/image及保存的legacy URL未改，新私有消费者没有公开回退。没有新增enabled开关。

初次Source有限静态检查：19变更路径UTF-8/LF；14份PHP字符串/注释/括号词法平衡（不是PHP解析）；4份前端脚本Babel解析、2份模板解析均0，uni模板使用原生image/input unary规则；git diff --check为0。独立前端作者及本任务分别解析了同四份脚本/两模板。新存储的13个完整物理guard方法与现form叶机械相同，rootGate只换固定配置键；现form三件及CustomerAssetStorage四个原件零diff。其后的Source独审与Root实际PHP8.2语法证据见下文；正式构建、HTTP/SQL/Redis/文件/正常收尾及视觉/设备仍NOT RUN，执行清单不勾完整业务完成。

后继ISO-FULL需显式设置PHP_MARKETPLACE_CUSTOMER_LICENCE_PRIVATE_ROOT，绑定实际应用uid拥有的0700持久私有根，并由既有隔离机制准备真实合成本人、原合法邀请码/手机验证码及原申请列表有权平台账号。最小正常调用顺序为：本人GET agent/apply/info；POST agent/apply/0/files（filename=pics、真实pics multipart）；本人GET agent/licence/{asset}/content；POST agent/apply/0以原业务字段和locator保存；GET本人info取得真实id；POST该真实id同体重存，再在本人原申请上下文上传/修改；GET平台原申请列表，再GET agent/division/apply/{真实id}/licence/{asset}/content；本人保存后再次读原图。须用独立原生前后观察核全部34列、实际申请行/原UID与邀请/审核状态、首次绑定保持、真实图片完整bytes、所有其它表/文件/Redis及既有事件允许差分，之后正常close/独立zero。下节仅准备具名normal入口/profile/fixture及原harness必要接线的Source；Root独审、必要语法与clean checkpoint之后才可实际隔离执行，不改变或扩权当前另一个W/Val的冻结正常S。

Source独审后的两项正常行为修订：页面隐藏dispose只关闭传输/释放临时图，同账号草稿保留；另保存完整token/revision/verified UID/当前user/Cache的sameIdentity谓词，不依赖已dispose的alive或页面scope。隐藏期间订阅及onShow即使context=null也先核该身份，变化时清本人字段/申请/图片/同意状态；同账号返回包括仅legacy URL的图片也先建立有效scope才展示。验证码错误、空邀请码等明确尚未进入申请保存的原校验失败保原msg并带标准六键database_committed=false收据，因此不锁成未知提交或丢弃刚上传的草稿；实际保存未知null/true及坏包仍须先读回。仅修改必要API/Vue/controller及本记录，不恢复任何停止样本；后继正常身份生命周期、表单校验和业务/视觉检查仍待实际隔离验证。

该修订实际静态检查：2份受影响前端脚本Babel解析、1份uni原生模板解析均0；4份修订文件UTF-8/LF；Controller词法平衡及git diff --check为0。剩余原非空邀请码校验在原service进入资产save之前抛出的ApiException亦返回同样明确未保存收据；真正writer/commit异常已由资产save统一包装为MerchantAttachmentOperationException，仍先返回其原真实六键未知收据，不被该校验分支覆盖。没有执行账号切换、校验失败或任何其它App/HTTP/浏览器样本，没有PHP/Docker运行，不冒签这些行为已实测。

独审又确认原Json::make在data=null时只生成status/msg；原申请成功返回success('提交成功')没有data，新transport原先误判为未知。最小Source修订仅具名save为json传saveAcknowledgement标志：真实HTTP200/status200及字符串msg的原无data成功DTO可完成保存应答，随后仍沿原页面读取实际申请；GET history继续必须有data，上传的三字段结果不变。所有身份门、错误六键收据及null/true未知结果先读回均保持，不改后端成功DTO或制造空data。实际业务调用仍NOT RUN。

该DTO修订后实际2脚本Babel/1uni模板静态解析0、两份修订文件UTF-8/LF、git diff --check=0；未执行PHP、App、HTTP、样本或浏览器，未提交/推送。当前Source再次停写交原独审者复核，不以静态解析代签保存成功。

Root实际PHP8.2检查（2026-10-25）：固定已有镜像PHP8.2.33，对本修复全部14个完整PHP逐件-l均自然0；8项Docker原生命令均0/空stderr，nonroot、只读源/根、network-none及专用/tmp内存目录，唯一语法容器普通移除、前后专用label查询为空，镜像无声明匿名卷，14源完整字节保持。仓库外证据licence-private-php82-syntax-r2/RESULT.json。首轮因/tmp未提供而PHP启动失败，在源码解析前停止，已正常移除；不作为业务红信号。此仅PHP语法，不是App/ORM/HTTP/数据库/文件或UI验收；上述正常ISO-FULL与浏览器设备清单仍待。

### CU_LICENCE 正常验证 Source 准备

复用现ISO-FULL compose、bootstrap的prepare/register/seed与原harness App supervisor、固定audit-app:8000 HTTP、read-only guard、数据库/Redis双哨兵及DML检查，不复制完整MS4编排或恢复bootstrap的旧停止样本。新增Files仅isolated/ms4-agent-licence-normal.php、compose.ms4-agent-licence.yml，以及原lib/harness.php的具名固定private-root传递叶。Root先完成原实例普通精确回收/独立zero，再串行安排任何新Docker变更；作者本步不运行Docker/PHP/App/HTTP。

当前feature工作树有未提交的真实实现，不能以b54的HEAD签新运行。顺序为：测试Source/必要公共harness差量独审、新改PHP纯语法→Root依既有L2限定local checkpoint→新clean HEAD及独立Val/原Source导出与完整字节绑定→全新RUN。保全部clean/source guard，不制造未提交代码已测的结果。旧bootstrap脚本会自动运行历史cleanup probe，新的正常操作只复用其已核原语，不直接无区别重放该完整脚本；所有启动、观察、停止、回收仍由Root用实际原生句柄及原严格资源清单执行。

专用overlay只给app/runner/bootstrap增加同一新customer-licence-private卷（app/bootstrap RW，runner RO），固定/run/audit-customer-licence，纳入真实volume labels/created_at、mount Name/Type/RW、内部唯一网络和零宿主端口核对。应用public源码和私密配置仍RO；App/runner只有audit_app四DML，无migrator/root卷。新harness叶只允许该overlay具名标志下的固定LICENCE_PRIVATE_ROOT值，传PHP_MARKETPLACE_CUSTOMER_LICENCE_PRIVATE_ROOT，不放开任意ENV/path。新卷0700、真实App运行uid拥有，非public/runtime；配置空或任何前提缺失都拒绝准备，不通过生产hook、假Request或直接调用待测保存服务求通过。

该overlay沿用原base容器user，并未把Root语法容器的nonroot条件冒称App实际用户。运行前须由Root原生核实际bootstrap/App/runner euid一致及卷owner；固定root不因用户名推定而放宽。真实登录、首次不存在详情和原列表预热发生在模块baseline之前；具名正常验证检查实际签名身份并使用原会话，未将这段准备声称为完整旧登录/AuthV2套件重新验收。

prepare仅在本RUN专用bootstrap中用原CREATE-only资产迁移完成三表check/apply/check/repeat；之后原生独立DML观察，真实App以audit_app初始化。沿原合成账号夹具和真实密码登录：本人、原最高平台账号（原列表有权）；邀请码由原DivisionServices为本RUN合成事业部实际产生，验证码仅作为本RUN私有合成CacheService夹具，明确不证明外部短信。账号、密码、验证码、原生行和HTTP响应体只进0700/0600私有区，不入公开报告。旧URL样本使用当前真实同源静态图片，不生成假远程材料；三张新PNG为本RUN明确合成fixture，两张最终选中、一张始终STAGED。

verify沿真实HTTP：本人/平台正常登录和必要预热；首次multipart及本人原图；第二张未选中multipart；首次申请保存并由本人详情取得真实ID；同体重存；在该真实申请上下文上传第三张并修改；本人详情原图及原平台列表/所列原图；合法本人再申请重用BOUND引用，核旧申请is_del及新真实ID、首次agent_apply_id/bound_at保持；legacy URL与private locator保存、返回和实际图分别核对。没有错误口令、越权、故障、并发或恢复样本。

独立expected固定完整34列、所有其余绑定列NULL、真实uid/purpose/保存申请、path命名/PNG原bytes/尺寸/摘要；首次仅state/agent_apply_id/bound_at改变，重复/再申请不重绑，未选中预览图与旧图不删。全库表集合、原DDL、完整原生行前后观察：仅真实本人auth的last_time、原申请保存/软删除和原平台访问日志有按实际HTTP窗口/原字段合同独立建立的变化；其它业务/财务/事件表全部保持。完整Redis类型/值/绝对过期独立观察：预热后仅真实上传配额按次+1、86400秒及原tag成员的明确变化；原短信夹具与真实auth/session等其它键保持。完整public/runtime/private文件观察禁止未列变化，新private文件0600、父目录0700且同真实owner。测试Source仍需独审和实际验证，不据准备值代签通过。

App正常停止由原supervisor同句柄完成，要求原组/8000监听为空；Root再按原manifest及原生CID/卷/网普通精确回收，另进程独立zero，不能将entry结束或清理Source当已回收。页面草稿/隐藏同账号保留、正常切换另一合成账号后的旧申请清除及legacy预览仍需真实构建+BROWSER验证；PHP正常HTTP不冒充页面行为或MP/APP/外部发布验收。全部19交付组保持未闭环。

本轮Source实际检查仅有：新增normal PHP与受影响harness的字符串/注释/括号词法平衡，4个受影响文件UTF-8/LF，现有js-yaml解析overlay及固定3服务/具名卷/mode约束，git diff --check均0。一次辅助静态脚本错误地预设25个函数而失败，改为检查实际26个局部函数名无重复后自然0；不涉及PHP或App，不记业务红/绿。此前14PHP语法结果不覆盖本轮新增normal入口与新harness差量；两者仍待Root独审和实际PHP8.2纯-l。作者停写，不提交/推送。

仅当上述新clean/source绑定、原prepare/register/seed、原生private挂载/euid和全新RUN前提已满足，Root可串行复用同一原compose调用（加此overlay）运行audit-bootstrap的`help/audit-regressions/isolated/ms4-agent-licence-normal.php prepare`，随后audit-runner的同入口`verify`。prepare只回PREPARED_NOT_HTTP_VERIFIED，verify的正常业务检查即使通过也仍列resource retirement/浏览器/设备/外部发布未完成；不运行原完整bootstrap/run脚本中的停止样本，不跳过任何guard。

正常收尾接缝修订设计（先记录，后改Source）：独审确认原audit_app_stop_group会在TERM三秒后升级KILL，与本轮普通停/超时保留不符。仅AUDIT_LICENCE_NORMAL=1且原read-only/实际private挂载身份合格的固定profile改用独立term-only分支；沿原proc句柄、supervisor/leader starttime、SID/PGID、精确组成员及8000监听保护，只一次TERM并有限观察/回收已经自然退出的本组子进程。未结束或身份/观察失败保留原proc/group/generation及状态，不close活句柄、不KILL、不自动再stop、不重启；后续start被拒绝。supervisor自身TERM若无法正常关组也报告并继续保留，不以退出PID1间接清掉现场。其它mode原两级逻辑不改。本normal入口的finally只发这一次普通stop请求，未知/失败收据保留并阻断业务通过。新entry+harness前一版Root实际PHP8.2-l为2/2自然0、8原生0，证据licence-normal-fixture-php82-syntax-r1/RESULT.json；本修订后harness仍待Root串行语法，作者不执行Docker/PHP/App或停止样本。

上述Source修订已写入audit_app_stop_licence_normal_group及原supervisor的固定profile分支；只在完整组/监听/原handle均自然退出后close，超时留住generation。普通停成功回执显式含members空、listener_open=false及stop_mode=term-only，normal入口同时核该回执。状态落盘失败亦保留supervisor/活句柄并报告，不因异常退出间接清现场。实际3文件UTF-8/LF、两PHP词法平衡和git diff --check均0；原audit_app_stop_group函数与HEAD逐字相同，新普通叶仅一个TERM原生signal调用、无KILL词法路径。此为源码复查，未执行进程或样本；修后PHP8.2语法/独审/正常业务/普通回收仍待，作者再次停写交原独审者。

Root补核后的状态保留约束：首次普通stop不完整后闭锁后继stop/start，只有原observe可继续只读观察；不会第二次signal、close或清空原generation。后续失败状态还须保留首次不完整stop的原revision/句柄/组/监听事实，不能用一次被拒绝的start覆盖首次原因。该固定profile在supervisor内保留首次快照并随失败状态重复发布；其它profile无此闭锁变化。

Root修后实际检查（2026-10-25）：最终normal入口37405/5d9d1406与harness55618/9027eacd经完整源码独审无剩余确定缺陷；固定PHP8.2.33两整文件纯-l实际2/2自然0，8原生0/空stderr，专用network-none/nonroot/只读源/tmpfs容器普通移除、前后专用label查询空。原14生产PHP完整字节仍等此前已过语法的版本。仓库外证据licence-normal-fixture-php82-syntax-r2/RESULT.json。此不执行App/HTTP/业务或停止样本。Root按已授权L2保存22路径限定本地checkpoint，仅为后续clean实际HEAD/独立Val绑定；正式正常业务/资源回收核零及BROWSER/设备仍待，不标功能交付通过，不推送或部署。

2026-10-25 正常准备214失败的实证与修订设计（先记录，后改Source）：旧实例2026102501391094fcd4的native214只进入ENTRY prepare，因M4AL_ORIGINAL_ASSET_CREATE_CHECK_REPEAT自然2；prepare/verify结果均未产生，真实HTTP未开始。Root同bootstrap只读diag-r1/RESULT（2657B，SHA256 1f43b673c47e8d1e86d5dc805c0c3aa138aaa22896dbdc396c43db2c99abc9cf）确认原audit_pdo的emulate=1及check inspection_failed；局部false后的diag-r3/RESULT（2222B，SHA256 52b621dde4e7c14e758476a8f5b7d8531c55829ca17012998f92db3373e52898）确认仍缺strict mode；diag-r4/RESULT（2188B，SHA256 0268dd7e7131953cabd88e7570f14bfcd52b719f3c5324bf61bd2731ae669df9）确认SESSION/GLOBAL均只有NO_ENGINE_SUBSTITUTION，SELECT/TRIGGER直授予已具备。没有改权限、SQL mode或DDL。这是两个准备前提遗漏，缺表本身的applicable/native0合同正确，原四步合并断言不改。

本次仅修改本normal prepare的局部assetPdo：关闭PDO::ATTR_EMULATE_PREPARES并实际读取确认native prepares，再执行原check/apply/check/repeat和全部原断言；在该合并断言前把四份完整真实receipt写入本RUN evidence的create-only私有0600文件，保留changed/attempted/applied/phase/lock等原始责任信息，不合成DDL结果。不改通用audit_pdo、生产逻辑、迁移schema或权限。新Host setup另由Root安排在原legacy seed完成后，仅为全新专用ISO实例设置并确认GLOBAL strict且保NO_ENGINE_SUBSTITUTION，避免更早strict改变旧安装SQL的导入行为；之后新连接须确认SESSION strict。作者不修改该Host或旧Val/Caller/Run。

旧214及其stdout/stderr、既有准备/续行失败历史、原源码和场景永久保留，不重跑当前prepare、不补丁运行旧App，不用既有全成功close合同冒签当前失败可直接收尾。Root将对本次Source独审/实际PHP8.2语法后保存新checkpoint，绑定新clean HEAD/独立Val并fresh ISO验证；HTTP、业务、视觉/设备及19交付组均仍待，不以本修订或既有旧语法结果标新版本通过。

本次作者实际检查仅为两文件UTF-8/LF、PHP字符串/注释/括号词法平衡、原全部断言code与四回执合并断言保持及git diff --check均0；不是PHP解析或运行。只出现这两条tracked路径差量，作者停写；实际PHP8.2语法、独审、新checkpoint及新ISO正常验证交Root执行，未提交/推送，也未触碰旧Val/Caller/Run。

2026-10-25 verify140计数器观察失败的实证与修订设计（先记录，后改Source）：新实例202610250241450732f5的native139 ENTRY prepare实际0，四迁移原receipt依次applicable/native0、completed/changed=true/native0、completed/native0及repeat changed=false/native0；native140 ENTRY verify实际1，停于M4AL_ONLY_EXPECTED_NATIVE_COUNTER_ADVANCE。真实HTTP已启动，finally沿原TERM-only停止，members空、listener=false、force=false，但整条正常链未通过，没有成功NORMAL结果。当前140与其真实已保存payload、原源码及旧Val/Run永久保留，不重跑当前verify，也不能调用成功retire合同冒签收尾。

Root同实例只读诊断licence-fresh2-verify140-readonly-diagnosis-r1/RESULT.json（10417B，SHA256 c34d28e00076ec798028ac9c7f6dc3f4af9d1a0a2fe8712c2631def71b739b64）证实默认observer SESSION information_schema_stats_expiry=86400；只对诊断新连接设SESSION=0后，资产AUTO_INCREMENT由缓存1变即时2，SHOW CREATE亦为2，实际row_count/min/max=1/1/1；其它多个空表也由cached NULL变fresh1。没有更改GLOBAL mode、grant、DDL、数据或HTTP。故整个原生counter观察门均须即时metadata，不能只放宽资产+1或忽略其它表。

本次仅在m4al_verify获取独立audit_db observer后、任何before snapshot之前SET SESSION information_schema_stats_expiry=0并实际SELECT确认0；m4al_database入口另只读确认同session仍为0，防遗漏初始化。复用既有m4lc_db的局部session前提，不改生产/ORM连接、GLOBAL配置、DDL或权限；原+1、其它counter不变、完整行/34字段、文件和Redis断言全部保持，不以MAX+1代替真实ID或修改期望值。作者只改本normal入口与本记录；新checkpoint、新Val/fresh ISO及实际PHP8.2语法/非作者审由Root后续完成，本Source修订不签业务、视觉/设备或19交付组完成。

本轮作者仅核两文件UTF-8/LF、PHP词法平衡、原全部断言code顺序保持、m4al_transition整函数原字节保持及git diff --check为0；不是PHP解析或运行。只这两条tracked路径差量，作者再次停写，未Docker/PHP/HTTP/提交，未动当前140场景、旧Val或Run。


2026-10-25 fresh3首笔上传counter失败的证据补充设计（先记录，后改Source）：当前实例20261025110820af91a9绑定Source65ab44b4aff4927f9c59a0467b59b384fea248e1，Root实际normal139 prepare为0、140 verify为1，仍返回M4AL_ONLY_EXPECTED_NATIVE_COUNTER_ADVANCE（stdout109B、SHA256 649d4211226e36cd8856d690befe03b126bad07145d45ec1b82742ef0b799fb3，stderr空）；App组由TERM-only正常停止、members空/listener=false。Root从本Run真实app-runtime.log安全提取确认仅login、admin login、本人info、平台list和首次POST files200共五请求，尚无GET执照原图，故停于首upload的transition；此前真实资产id与完整34列断言已通过。当前Source已固定并确认observer SESSION stats_expiry=0，不能再将同错误码直接归因旧默认缓存；当前before/after counter快照未持久化，事后metadata不足以确认NULL到1或其它具体根因。旧Run/Val/日志、首次实际payload及失败结果保持，不重跑或补丁运行当前实例。

本轮仅为下一fresh正常验证补安全失败证据，不改baseline、读取顺序、NULL归一、独立expected或生产分配。在原counter严格比较失败时，沿现m4al_write创建一次audit evidence/ms4-agent-licence-counter-failure.json（create-only、0600、完整写入/flush/完整读回）；仅保存当前fixture已验证的Run/source、actor、首个table、原before/wanted/actual及具名increments，不含凭据、令牌、任何用户/业务行、原图或其路径。该目录不在被观察的public、runtime或private原图根中。沿finally执行原比较与原M4AL_ONLY_EXPECTED_NATIVE_COUNTER_ADVANCE断言；若证据写入失败，仍保原失败码，缺失或不完整receipt不得当成有效证据。正常比较成功分支无新增写入、HTTP或业务行为；每轮首个失败即抛原code，不覆盖或追加第二份失败收据。

Files仍仅本normal.php与本记录；原counter/所有行与34字段/Redis/文件断言均保留。作者只做Source与静态差分检查，实际PHP8.2语法、非作者审、新checkpoint/独立Val/fresh ISO由Root安排；此修订仅补定位材料，不宣称已修复counter问题，不勾业务、视觉/设备或19交付组完成，不Docker/PHP/HTTP/提交，也不动旧Source/Val/Run。

本轮实际静态检查：两文件UTF-8/LF、PHP字符串/注释/括号词法平衡、原全部断言code顺序一致、仅将该安全证据块逆还原即整PHP字节等HEAD65ab，以及git diff --check均0。不是PHP解析或执行；工作树恰这两条tracked路径、无暂存。作者停写交Root实际PHP8.2语法和非作者审，不将证据补充当作counter修复或正常验证通过。

Root实际复核追加（只记已发生结果）：上述38983B诊断PHP完整输入已由固定PHP8.2纯php-l通过，同行商家2PHP合计3/3、5原生全0；前后同一原bootstrap的CID/PID/StartedAt/readonly保持，未执行候选或产生业务请求。结果仓库外fixture-and-merchant-video-php82-syntax-r1/RESULT.json为6750B，SHA256 ec37ff71b1cef7eafbef4c0c830f1999ff947ca19822edf840df56bd7c6b52bb。Root aaec8c完整diff及原writer/断言已核，非作者product_media_next_gap对追加前两件完整Source审finding0；本追加仅记录，不改变PHP或把旧审当新文档审。fresh3失败实例已正常close110/独立zero10全0、六查询空，原失败输入保持。当前仅本地Source检查点；下一新clean HEAD/Val/fresh正常链的首坏表receipt尚未生成，counter根因、正常业务、页面/设备与19交付组仍未通过。

2026-10-25 fresh4首坏表实证与observer取值时点修订设计（先记录，后改Source）：新Run2026102511314480852b使用d878d1f319c834dd7cffdb7433a3a2797631ac77，原normal工具33237已自然终态b66c0e/外层1，139 prepare0、140 verify1。首次安全counter receipt真实342B、SHA256 18a928d59a761fddb0954df17b2c4cf16d77d2dc5f971e9975eeb060bdb965ed，首坏表为eb_agent_level_task，before=NULL、wanted=NULL、actual='1'，increments仅customer asset +1、actor=customer。Root当前只读公件licence-fresh4-counter-readonly-proof-r1/RESULT.json（5248B，SHA256 6afa91dc3cd012713fd625d3d47ec2b112f88f40f6853243a19cabcc8eab1fbf）核原140全部双流/PID/wait和App TERM-only空组；目标表为真实InnoDB/id auto_increment，当前row_count=0、MIN/MAX=NULL、next=1；资产一行STAGED/103B、申请0。首坏表及空表metadata的NULL到1已经证实；当前还没有首次快照内部、无业务HTTP间隔的两次metadata实证，不能把“本次SELECT/SHOW CREATE自身初始化”推断写成已证根因。

本轮仅改m4al_database的counter取值时点：原完整TABLE_NAME/AUTO_INCREMENT初始batch仍用于表枚举并保规范化初始metadata；原每表完整rows、序列化多重集hash、原DDL/列定义及目标行全部保留。所有表读取结束后，再执行同一完整I_S batch，严格核前后table namespace和顺序完全相同，再按原NULL或string规则赋out.next；只增加一批真实查询，不逐表追加查询、不把NULL统一改1、不用MAX+1、不改独立increment或严格counter断言。首次m4al_database用局部static标志仅额外创建一次evidence/ms4-agent-licence-first-snapshot-counters.json，保存真实manifest Run/source及整表读取前后两张规范化metadata map；同时核并记录原正常HTTP请求计数在两map之间不变，明确只是首次before snapshot内部的零业务HTTP间隔。沿现m4al_write create-only/0600/完整写入flush及读回，不含用户行、token、凭据或原图路径；后续snapshot不再写该证据。原失败counter receipt和所有原public/runtime/private观察与断言保持。

Files仍仅normal.php与本记录。下一fresh需实际读取新首快照receipt，区分metadata是否在该无HTTP间隔内初始化，并完成原完整正常业务/副作用/收尾及独立zero；本设计和Source检查不等于已证明初始化原因或业务通过。当前失败Run/Val3及原材料保持，退役由Root另审另行执行；作者不触碰Val/Run/C/D，不运行Docker/PHP/App/HTTP或任何停止样本，不提交/推送。原19交付组及视觉/设备、外部发布边界不变。

本轮最终Source静态核对：PHP差量仅限m4al_database，函数以外全部字节（含原transition严格counter及首坏表失败收据）等HEADd878；原完整逐表读取/hash/DDL和全部原断言code顺序保留，只新增namespace及首次快照零HTTP计数两项guard。相同完整metadata SQL从1处变2处，NULL或string规则保持。两文件UTF-8/LF、PHP字符串/注释/括号词法平衡和git diff --check均0，恰两条tracked未暂存路径。没有执行PHP解析或任何目标；作者停写交Root实际PHP8.2语法及product非作者审，后继fresh实证和业务仍待。

Root复核与检查点追加：上述40238B完整PHP已在固定PHP8.2实际纯php-l通过，3原生全0，同现bootstrap CID/PID/StartedAt/User/readonly前后保持；未执行候选或新增Docker资源/HTTP。仓库外licence-metadata-read-order-php82-syntax-r1/RESULT.json为3733B、SHA256 2fa394249fb7730da2f8632b4ecbc0460fddfb1d76e334f8d765736fdeeff201。Root a88ff9完整差量及原writer/观察边界复核无确定问题，非作者product_media_next_gap对追加前PHP与原记录完整Source审finding0；PHP原字节不变，本追加只记已发生结果，不冒充独审包含新增文字。下一新clean提交/独立Val/fresh实例的首次snapshot实际metadata证据和原正常业务仍待，当前只本地Source检查点，不签初始化根因、业务或19组完成，不推送部署或处理真实资金。

2026-10-25 fresh5平台列表默认UID合同的实证与修复设计（先记录，后改Source）：Run2026102511551391d74d绑定a47606ff51dda2336ed0e69b14f943e34aa939a4，原normal工具6629已终态accd6f/外层1，139 prepare0、140 verify1，安全输出109B、SHA256 e9bbd3b09ff5a71c492f717e18de7a25cd99153d6a7107e73dee2148a62f62c7，失败码为M4AL_ORIGINAL_GRANTED_LIST_SAVED_LOCATORS。此前首次metadata收据8559B、SHA256 4ece654471120d940bf2126e04acba12a9ef79384aefc7d0bcb1e85ddd336ba5，真实HTTP计数4到4，87表在该首次快照内部metadata变化，含此前坏表NULL到1；本轮没有counter失败收据，原严格counter门已通过已走步骤，不再把新列表错误归为旧counter问题。真实16个已提取具名HTTP均200，已经走到三次上传、本人原图、首次/同id/修改保存及本人详情，最后平台列表后断言失败；这些是部分正常链，不代表平台列表DTO、平台原图读取、再次申请重用或整业务已通过。App已沿TERM-only正常关闭，members空/listener=false；成功NORMAL不存在，当前原Run/旧Val3和全部历史保持，不重放HTTP或调用成功退役入口。

Root当前只读公件licence-fresh5-platform-list-readonly-proof-r1/RESULT.json（6761B，SHA256 311cc690653d6142e7bb6b85d047d1756241fd881291a5005f79acf012528ca7）实际确认PHP8.2.33的0!=''为true，管理员level0/division0；原事业部范围is_del0申请1条，加默认uid0条件后为0条，真实合成顾客申请1条；实际保存images为3项、含2个私有引用，资产3行中BOUND2/STAGED1及各完整原文件一致。未持久化最后列表DTO，不能以HTTP200代签其正确；默认UID过滤的根因由原请求参数/真实源码链、PHP实际比较结果及同实例只读计数共同证实，无HTTP、DDL、grant、全局设置或业务数据变更。

需求与最小设计：原AdminApplyList控制器默认uid=0表示可选UID筛选未启用，真实applyList页面同样不传uid。仅在DivisionAgentApply::searchUidAttr使用严格sentinel membership，明确0、'0'、''、null及原false语义均不加UID条件；正UID和其它原值继续原where('uid', value)，不以(int)转换把非法字符串等其它值当0放宽。不改Controller默认值、真实admin.division_id范围、角色/权限、route、DAO、images JSON到array合同或任何消费者；不改正常fixture/原列表及全部业务断言，也不靠测试追加uid绕过生产缺陷，不批量修改其它模型。

Files恰为crmeb/app/model/agent/DivisionAgentApply.php与本记录两件；原累计22条交付路径全部保留，该model新增为第23个审查路径。后继Host6/PINS需绑定真实新checkpoint的完整23路径并保原15763完整Source body资格，不能沿用22漏掉model。作者只做Source/静态差分，Root及product非作者复核、实际PHP8.2语法与新clean checkpoint/fresh ISO之后才验证默认列表命中、原保存locator及完整余下正常链/副作用、普通收尾与独立zero；本修复源码不勾业务、视觉/设备、外部发布或19交付组完成。停止样本保持NOT RUN，本作者不Docker/PHP/App/HTTP/提交，不触碰旧Val/Run/C/D。

本轮作者实际Source检查：model仅上述单行严格sentinel条件变化，逆还原该行即整model字节等HEADa476，所有其它函数/字段/事业部搜索器原字节保持；本记录只追加。两文件UTF-8/LF、model字符串/注释/括号词法平衡及git diff --check均0，工作树恰这两条tracked未暂存路径；Root当前6761B公件全文读回及SHA吻合。不是PHP解析或运行，未执行任何条件样本、目标、Docker、PHP或HTTP；作者停写交Root实际PHP8.2语法及product非作者审，不据本次静态结果签默认列表或整业务通过。

Root真实检查与本地checkpoint追加：上述2237B模型完整输入已在固定PHP8.2纯php-l通过，3原生均0，同现bootstrap CID/PID/StartedAt/User/readonly前后保持，无candidate/query条件样本或HTTP执行；仓库外licence-list-uid-php82-syntax-r1/RESULT.json为3647B，SHA256 c386bba3c60003a0f8e3b5b7aee96244878045b6da771490a8fdf8bc33710b47。Root75ae13完整diff与原请求/DAO/模型链条已核，product_media_next_gap对追加前两件全文Source审finding0，模型原字节保持；本追加只记真实结果，不把旧审代签新文字。后继Source必须把该model作为实际第23路径并完整15763文件资格，在fresh实例正常默认列表/平台原图/新申请重用与副作用/普通close和独立zero后才签对应业务；当前仍未推送合并部署或处理真实资金。

### CU_LICENCE fresh6 实际正常链验证与普通收尾（2026-10-25）

本次实测 Source_HEAD 固定为 `864dda32fba62ab12944a01369d816a20be5aec2`，Run 为 `2026102512495632759f`；本段为该 Source 之后仅文档的追加，不包含执行源码变动，后续文档提交不替代上述实测 Source。Root 已完整读回原 normal 工具83299/46a3c2自然0及该 Run 的169条 native 创建/终态、原PID/同句柄wait和完整双流，共15,114,591B；prepare139与verify140均自然0。独立非作者正常结果复核 finding0。

原正常 fixture 实际完成25个业务HTTP、7个原业务case、289326次原断言：真实multipart首次STAGED及完整34列、未选STAGED保留、首次真实申请与选择绑定、同实际ID重存且首绑不变、本人修改及第二资产绑定、原授权平台列表与私有原图、新申请复用已有BOUND资产。完整34列及全库表rows/DDL/hash/next、Redis完整类型/值/绝对expiry、public/runtime/private文件、legacy混合引用及原业务预期副作用均按原断言核对通过。首次counter快照记录业务HTTP4到4的零请求间隔及87表metadata初始化；原严格counter门保持并走完。服务器原生HTTP200共26次，其中原harness就绪探针1次、业务25次，按25次业务计数，未因计数重复运行测试。

Root 随后使用已审正常成功收尾入口完成普通close，原36621/208d7b0自然0，实际109条native全部自然0。5个原同代CID均经显式SIGTERM/timeout=-1自然退出，Exited0/PID0/no-restart核验后，nonforce准确移除本Run的5CID、13专用卷、1内部网络及Val3精确ownlock。独立zero原终态17c01e已完成，Root b7456d完整读回10条native创建/终态/双流及原PID/wait均自然0；6条project/run资源查询的stdout与stderr均空，ownlock不存在。普通收尾与独立zero已完成。

以下均为原机仓库外 `artifacts/project-organization-20261023` 下的运行证据；本记录只保存摘要、长度与SHA256，不含密码、私有locator或原始日志：

| 证据相对路径 | 字节 | SHA256 |
| --- | ---: | --- |
| `licence-normal-runtime-r6/2026102512495632759f/NORMAL.json` | 788 | `f17841ea55086c900f3db37f988d24b58d304e56976cf9b4419262efc1c56c30` |
| `licence-normal-runtime-r6/2026102512495632759f/evidence/ms4-agent-licence-verify.json` | 729 | `ac3994d57c2cd17a56bc7972f401b618ab3772fa506532ba2e818c339de01b25` |
| `current41c0-normal-root-admission-r1/R115-LICENCE-864DDA-NORMAL-ROOT-READBACK.json` | 3425 | `1515e567cc42c5b38afbeb43f365f5709bbf3929f3b2260a0b256d15f05be320` |
| `licence-fresh6-normal-close-r1/CLOSE.json` | 26733 | `1a22c8a6336271911672d3a6ffb73e86626e2f26bda0d6e913212b891e28857c` |
| `licence-fresh6-normal-zero-r1/ZERO.json` | 1337 | `8cc6aa27c171645009727e2e34d14b647f309947373fb3efb6042099a7b6b97e` |
| `current41c0-normal-root-admission-r1/R115-LICENCE-FRESH6-CLOSE-ZERO-ROOT-READBACK.json` | 1514 | `3c2bedb247761321fb1f64761c69dcf245f9d1d7f8df876a5a94d1bf246e1a40` |

该结果完成本CU_LICENCE隔离实例后台正常链与资源收尾验证子项。H5/UI、MP/APP及真实设备、restricted-role变体、外部短信、旧client实际发布均NOT RUN；停止样本仍NOT RUN。T5整组及原19个交付组继续保持未关闭，浏览器/设备/外部发布与最终验收仍待完成。本次记录不包含部署、合并、推送或真实资金操作；文档作者仅追加本段，未运行Docker/PHP/HTTP或修改原Run/C-D。

### CU_LEGACY_REFUND / W03–W04 独立设计与任务（2026-10-25，待Root阅读采用）

本项沿原T5/L2，独立工作树为 `H:/天俪商城/worktrees/ms4-legacy-refund-private`，分支 `feature/ms4-legacy-refund-private-20261025`，从实际 `c8913ba9fb8ce5e052287360fb8b64ddf4fd139c` 创建。c891仅追加已实测Source864dda的执照记录；本项不改旧执照W/Val/Run、C-D或其它候选。原规格/规约、当前MS4计划及验收文件已读；本W无.codegraph，不初始化。Git-auto-sync只读检查为project_not_enabled，不enroll/自动推送。本步只设计并修改本原record，业务实现、PHP/App/Docker/HTTP/实际资金与提交/推送均NOT RUN；Root读设计后再进入实现，不向用户重复索取既定授权。

#### 范围与实际调用依据

目标是旧v0退款申请材料及寄回物流材料的具名私有上传、原业务保存绑定、本人/原平台合法读取。商品/SKU/价格库存、退款金额/件数/拆单/原事件与原V1售后链保持。旧订单由原平台自营业务责任处理；不写mer_id=0表示自营，不造MerchantContext，不把旧根送入V1文件服务。

已核实际链：`goods/goods_return/index.vue:198-234 → api/order.js:returnGoodsSubmit → StoreOrderController::applyRefund`；Controller先按保存order版本分流V1，v0将 `refund_reason_wap_img` 逗号串编码为 `store_order_refund.refund_img` JSON数组，`StoreOrderRefundServices::applyRefund:1109-1133` 在原transaction保存真实uid/store_order_id/退款PK及单号。`order_refund_goods/index.vue:132-168 → refundExpress → StoreOrderRefundController::applyExpress:133-157 → editRefundExpress:1000-1021`；旧两页仍用公开upload/image。寄回writer原非空refund_img/refund_explain被unset，空值仍进入update；两行来自2022年原提交2fcbe8a231，未找到“忽略寄回凭证”产品约定。本项替代图片丢失/覆盖断口，不通过删图片UI或停旧业务入口消除责任。

必须同时补保存端真实身份：现旧applyExpress只调editRefundExpress($data)，assertLegacySavedRefund仅核真实行/legacy父单而未核当前顾客。拟改必填调用为 `editRefundExpress(array $data,int $actualUid)`，Controller传 `(int)$request->uid()`，不得提供默认UID或信post.uid；该方法的全部旧调用均经过同一核验。原V1 JSON refund_no分支仍按原uid查询并交原MarketplaceOrderRefundWriter，不改其输入、版本、权限、18列文件表或正常链。

#### 两purpose与完整34列

| 固定用途 | 新上传的必要真实父关系 | 正式保存时 |
| --- | --- | --- |
| legacy_refund_evidence | 认证本人uid；真实可申请旧订单及其legacy根；root_order_id非空，refund_id暂NULL | 原申请真实插入refund后，用该真实PK绑定refund_id，STAGED→BOUND并记首次bound_at |
| refund_shipping_proof | 认证本人uid；真实refund及store_order_id解析出的同一legacy根；root_order_id/refund_id均非空 | 原寄回transaction核待寄回阶段后绑定；不覆盖申请资产及其首次绑定 |

复用 `marketplace_customer_asset` 原完整34列，purpose枚举、root_order_id/refund_id已存在，不加表/列/索引/种子。uid及created_by_uid来自同一真实认证本人，created_by_admin_id/created_by_job及无关父列均NULL；实际storage_type/object_key/文件名/MIME/尺寸/字节/摘要来自完整真实物理观察，不猜常量。新文件legacy_att_id为NULL。应用引用用具名格式 `mp-refund:v1:<fixed-purpose>:<asset_id>`，无逗号、无磁盘路径/签名URL；服务器校验完整34列后认定purpose与关系，不相信客户端前缀自行授予资格。

root_order_id取原legacy父链实际规范根PK，同时保留当前选择order/当前refund的真实store_order_id关系在本次事务上下文中；旧拆单根pid=-1或合法子单不能套V1的pid=0门。父链各行UID/版本与实际业务关系须一致，部分退款后store_order_id转为真实子单仍须能归回原根，资产父列不随显示查询改写。未知/断裂历史关系不能猜根或补mer；只记录未完成，不做历史转存/修复。此关系解析须以原拆单真实源及正常夹具复核，不另建通用owner框架。

具名上传在任何读上传临时bytes/文件写入前，核当前Request/应用/会话、有效顾客、实际旧order/refund及原业务允许条件。申请图先复用原待处理退款/商品剩余件数等合法申请资格；不是仅有一个order PK就可上传。寄回图要求原退款未取消/未删除且refund_type=4“商品待退货”，当前顾客同时等于refund.uid与真实parent.uid。type5用于既有结果/凭证读取；不为新上传重开已结束阶段。新私有根拟用 `marketplace.assets.customer_refund_private_root`（隔离注入 `PHP_MARKETPLACE_CUSTOMER_REFUND_PRIVATE_ROOT`），为实际应用uid拥有的持久非public/runtime根；缺失/失败不回退公开上传或公开云。只复用既有完整图片校验/安全私有I/O叶子与六键收据模式，现V1/表单/执照固定purpose校验不放宽。

#### refund_img统一持久化、分组DTO与旧client合同

DB仍为一个有序JSON字符串数组 `refund_img`，保原合法URL字符串和新具名私有ref字符串，不改成JSON对象、不另建图片列。初次申请按原选择顺序保存；寄回时在同事务先读完整已保存数组，保全部原申请ref/旧URL及已绑定历史，再有序追加本次寄回ref或兼容URL，稳定去重同一已有ref而不重绑。未选/上传后移除的STAGED保持，不删BOUND文件；页面删除只作用于未提交选择。已绑定申请/寄回资料都作为历史保留，新集合不能替换另一用途集合。对保存结果未知先读回真实refund与资产，不自动重放物流动作、重建ref或清理对象。

旧客户端字段名及CSV提交保持：申请仍传refund_reason_wap_img，寄回仍传refund_img；合法旧URL型申请材料保原存储，旧URL型寄回材料按兼容项追加，修复原非空被丢弃而不覆盖原申请图。不远程下载旧URL，不伪造其asset/UID/私有归属。旧refund_explain及其它业务字段的业务语义单列保持，不因图片合并顺手改退款说明、金额、电话、单号、拆单或事件；若为空值行为需另修，先报告，不混进图片私有化。首方两页改具名private上传，失败保草稿/明确结果，不尝试upload/image fallback；global upload/image及外部旧client发布门保持原责任，未升级client不能被写成已支持新私有图。

读取在现本人/平台授权后增加一个最小 `refund_materials` DTO：`{schema:1, application:[私有图元数据], shipping:[私有图元数据]}`，分组由完整asset.purpose及实际保存引用推导。每项仅attachment_ref、asset_id、media_type、byte_size、width、height等预览必需元数据，不返回object_key/磁盘路径/永久可读URL。原响应refund_img数组投影为原兼容URL集合，保持旧合法URL顺序/值；新私有ref不再作为img src冒充URL。新页面同时显示原URL组及两私有组，私有原图经ticket→完整binary→临时图，临时URL只放预览映射，绝不提交回refund_img。旧client仍处理原字段/URL和原物流动作；其读取新私有图需发布升级，真实外部兼容/T8门继续未完成，不能据DTO存在关闭旧入口或宣称全部历史已私有化。

实际消费者包括顾客goods_return、order_refund_goods的上传/提交，goods/order_details的本人退款原图；平台PC `admin/src/pages/order/refund/index.vue` 列表图；移动 `pages/admin/refund_order_detail` 与 `pages/admin/orderDetail` 的legacy图。它们都需通过真实API新增DTO读取与释放临时图，不能只改上传器。`StoreOrderRefund::getRefundImgAttr` 保原JSON解码，不在全局模型getter发网络/读私有磁盘或承担权限；投影在已经确认实际旧业务身份的服务响应叶子完成。V1 schema2分支仍走原售后DTO/前端组件。

#### 原事务、读取权限与失败边界

申请绑定插入原 `StoreOrderRefundServices::applyRefund` 的真实transaction：同Connection/PDO锁当前user、选择order/legacy父链和相关退款/资产，重核真实UID/版本/原申请条件；原save产生真实refund PK后绑定选择资产，再核完整refund/34列和当前身份；不另开transaction、不提前commit。原金额/部分件数计算、cart退款数量、clearOrderCartInfo及OrderRefundCreateAfterListener/Notice/OutPush/CustomEvent/Channel顺序保持。测试独立列出这些真实允许delta，不能把它们当零副作用或取消hook。

寄回保存的必填actualUid在原transaction内复核，锁真实refund+parent/family并核refunder/parent均属当前UID、原version0与type4等允许阶段，再按上述合并结果绑定/保存；更新WHERE含真实refund PK、uid及已核store_order_id，不只靠上传器授权。原refund_type=5推进与refund_express日志保持一次真实首次动作的原顺序。已保存结果的同体确认只依据真实回读，不自动再执行状态/日志；如实际原重复合同与此不同，先向Root回报明确差异，不改断言或自动重试。原V1 refund_no调用、退款支付/入库/清算函数完全不接入新purpose。

本人ticket/content从当前认证UID、完整旧order/refund和实际保存ref集合确认；本人未提交STAGED仅在其真实允许上传上下文可预览，平台仅读已保存且BOUND的引用。平台PC按真实DTO消费者区分固定列表/详情预览来源，分别核原GET refund/list或GET refund/info/:uni的登记/已启用角色及对应旧数据范围，不能把仅列表合法读者一律改成必须具详情权限，也不能把来源选择器当授权。沿现AdminAuth和原角色归一化核当前账号/授权，票据绑定该固定reader_kind；不新增宽泛白名单、菜单/seed或商家权限。最高管理员仍先过真实认证。移动管理页的api身份不是adminapi：新读路由留在原admin组，沿AuthTokenMiddleware+CustomerMiddleware当前客服/门店员工/配送资格，且只读原该退款详情已允许的v0数据，不加withRule例外、不把普通顾客或门店当入驻商家；当前actor与退款owner分别绑定并复核。两类原权限适配必须按真实接口/角色源冻结，不能由前端“admin”标志决定。

新票据使用独立固定namespace/scope，绑定实际app/reader_kind/actor_id、认证fingerprint、owner uid、规范root、refund（未提交申请可NULL）、asset及固定purpose/内容摘要，短有效期、peek后重新核业务/当前资格及完整bytes，成功发布前consume；返回private/no-store binary。复用既有票据和临时图机制，不向MarketplaceAftersaleReadTickets塞34资产ID：原V1 marketplace_aftersale_file为18列/STAGED→CLAIMED，要求version1/真实正mer_id，原300秒票据scope保持。六键物理/数据库结果收据沿用，真正commit未知保持null并保对象，只有明确未提交且本次新对象可窄cleanup；不恢复已消费票据，不删历史文件。真实凭据、完整token、私有locator和原始日志不进入本记录。

#### 拟议最小Files与唯一执行清单

本步实际Files只有本record。Root阅读采用后，生产改动限定现api/route/v1.php、adminapi/route/order.php、StoreOrderController::applyRefund、StoreOrderRefundController::applyExpress、StoreOrderRefundServices的上述保存/响应叶子、config/marketplace.php；按实际职责新增具名legacy退款asset/DAO/private-storage/read-ticket/read及原平台权限适配、api/adminapi文件控制器，优先复用现有叶子，不复制整套售后或增加通用owner框架。前端限定现uni api/order.js、具名legacy-material API/helper、上述5个Uni页面及PC退款列表/其API helper。实现前把最终必要类/路由/方法精确列回本段供Root核，不边写边扩大。正常验证复用ISO-FULL/bootstrap/harness及既有fixture能力，新增有限normal入口/profile的具体Files也先审；不改原冻结运行包或旧Run，不写未来资格/结果。

- [x] 从c891建立独立分支/工作树，核原licence W保持clean；读取原规格/规约、用途清单及真实producer/writer/consumer；记录本设计。
- [ ] Root阅读采用本设计及refund_img合并/DTO/旧client/移动原权限合同；此项为内部设计核对，不是用户重新授权。
- [ ] 冻结精确Files和必要调用点后实现两purpose；独立Source复核、PHP8.2真实语法及前端解析/构建按实际发生记录，不代业务通过。
- [ ] 新clean Source/独立Val/fresh ISO-FULL中真实本人v0正常申请：零图/旧URL、新multipart图、真实refund PK绑定、完整34列/原申请行与预期事件；原图片内容/顺序和字段保存。
- [ ] 原平台正常同意退货进入type4，真实本人寄回：含已保存申请图、新寄回图和兼容URL的合并保存→type5；申请和寄回组均可本人/原平台PC及原移动资格读取，图片bytes/摘要/尺寸一致；同体已保存确认不重绑，不丢原申请材料。无真实扣款/退款。
- [ ] 独立完整34列、全退款行及相关order/cart/status/event表、全库rows/DDL/hash/next、Redis完整类型/值/tag/绝对expiry、public/runtime/private全部文件做before→允许delta→after；原legacy URL、字段形状、原V1文件/票据/业务保持。Counter观察沿已修完整读后metadata方式，不用MAX+1或NULL归一，不镜像被测DAO造expected。
- [ ] 真实H5/PC及移动UI分组、选择/预览/保存/返回、临时图释放、原图与正常账号往返和适用尺寸；浏览器/MP/APP/设备/外部client发布分层记录，缺少条件保持NOT RUN。
- [ ] Root串行真实普通TERM→同代自然Exited0→nonforce精确only-own资源与ownlock清理，再独立六查询zero及完整原native/PID/wait/双流读回。普通收尾不签T5/19组全完成。

当前只完成工作树和设计；全部实现/语法/业务/UI/设备/EXTERNAL及新资源收尾仍NOT RUN。用户停止的负向/故障/并发/恢复样本继续NOT RUN，不新增跨号/权限请求或重新触发；源码须保认证/权限边界，未运行项不能计PASS。没有提交、推送、Docker/HTTP/资金、部署或canonical写入；作者在本record追加后停写交Root，原前缀保持。

### CU_LEGACY_REFUND 内部设计补充与精确Files（2026-10-25，待Root最后核定）

Root已采用上一节图片两purpose、完整34列、原事务真实PK绑定、历史材料追加、分组DTO及原合法读者方向。本补充只完善实际发现的寄回备注与type5确认，未编码、未运行或提交。当前寄回页面的`getOrderInfo`仅赋orderInfo/express_list/id，备注输入实际初始空，未从申请说明初始化；此前会话摘要中相反说法以此实际源码纠正。

**备注的数据落点。** 安装DDL中`store_order_refund.refund_explain varchar(255)`为退款用户说明，Controller申请时写真实申请说明；`remark`由PC `RefundOrder::remark`和移动refundRemark作为原平台售后备注写入，二者都不能被顾客寄回覆盖。现status已有`oid/change_type varchar(32)/change_message varchar(256)/change_time`。`StoreOrderStatusServices::getStatusList`原样返回change_message，PC `orderList/handle/orderRecord.vue:12`和`orderDetails.vue:369`均以文本插值显示，故不用裸JSON。

采用固定`change_type=refund_shipping_note`，`change_message='退款单#'+实际refund主键+' 寄回备注：'+note`。oid必须为已锁真实refund.store_order_id；前缀完全由真实PK产生，读者按同oid/type及当前精确前缀读取，而非模糊搜索用户文本。备注须有效UTF-8、≤100 Unicode字符；完整最终message须≤256 Unicode字符，超长拒绝，无静默截断。blank不增加备注行；非空在同原transaction、原refund_express首次事件之后保存一行，正常验证将其单列为允许delta。读侧为真实refund新增`refund_shipping_note`字符串，匹配记录只能0或1，重复/异常不猜取最后一条。

原`refund_explain`始终保存申请说明，包括寄回提交空值也不得抹除；不更新平台`remark`、原订单说明或图片字段承载文本。首方页面明确将备注输入绑定新字段`refund_shipping_note`，保留可编辑的寄回备注能力。旧client未发新字段时，原`refund_explain`提交仅作为寄回备注兼容别名，仍不UPDATE申请说明；若显式新字段存在，按该新字段处理。真正已保存备注只从上述实际status记录回读；不得伪造已有历史备注或迁移旧数据。

**type4首次推进与type5确认。** upload仍只允许新材料对应的真实合法申请/待寄回上下文，寄回新增上传限定type4。原writer接收必填actualUid并在事务内锁refund、parent/family及选择资产。type4首次保持原refund_express事件文案/顺序、refund_type=5及物流保存。type5仅允许真实保存状态等价确认：物流3字段（refund_express/refund_phone/refund_express_name）及上述回读备注相同，按原序稳定合并本次图片后必须与已存完整refund_img数组完全相同，所有引用资产已正确BOUND给同一refund，不能含待绑定STAGED；无任何UPDATE、状态日志或rebind。此为保存状态等价合同，不声称存在原请求字节摘要；网络结果未知仍先读回，前端不自动重发动作。其它阶段不重新推进寄回。

**精确Files。** 本项业务实现限定以下相对路径和职责，必要调用点已逐一有源码依据；不修改全局模型getter、V1票据/18列文件服务、生产SQL/DDL/种子或其它资产用途。需要扩大时先给Root实际依据并先更新本段。

| 相对路径 | 精确职责 |
| --- | --- |
| `docs/multi-merchant/records/ms2/upload-asset-domains.md` | 本唯一设计/执行清单与实证摘要，只追加，不改原前缀 |
| `crmeb/config/marketplace.php` | 固定assets.customer_refund_private_root配置，无public/runtime/cloud fallback |
| `crmeb/app/dao/marketplace/assets/CustomerLegacyRefundAssetDao.php`（新） | 同Connection/PDO的真实user/legacy family/refund锁、34列读取、STAGED及BOUND；备注有限事件原生读回 |
| `crmeb/app/services/marketplace/assets/CustomerLegacyRefundPrivateStorage.php`（新） | 固定退款根/用途的完整图片资格、0700/0600私有I/O和六键对象收据；复用现图片qualification，既有固定purpose门不放宽 |
| `crmeb/app/services/marketplace/assets/CustomerLegacyRefundAssetServices.php`（新） | 两固定上传、原writer内选择/绑定、真实业务身份/会话复核、旧URL与ref有序解析及授权后DTO投影 |
| `crmeb/app/services/marketplace/assets/CustomerLegacyRefundReadTickets.php`（新） | 固定独立namespace/scope、实际actor/owner/root/refund/purpose/hash/session绑定、短票据peek/consume |
| `crmeb/app/services/marketplace/assets/CustomerLegacyRefundReadServices.php`（新） | 本人STAGED/BOUND和原平台PC/移动读者的真实业务复核、完整bytes、票据发布及no-store结果 |
| `crmeb/app/services/marketplace/assets/CustomerLegacyRefundPlatformAccessServices.php`（新） | PC固定list/detail来源分别重核当前原GET refund/list或GET refund/info/:uni授权，不新增角色/菜单seed |
| `crmeb/app/adminapi/middleware/LegacyRefundReadPermissionMiddleware.php`（新） | 固定私有读路由只映射上述原权限，认证沿原AdminAuth |
| `crmeb/app/api/controller/v1/order/LegacyRefundMaterialController.php`（新） | 顾客两固定上传及本人/移动读票据与binary薄控制器 |
| `crmeb/app/adminapi/controller/v1/order/LegacyRefundMaterial.php`（新） | PC固定list/detail读票据及binary薄控制器 |
| `crmeb/app/api/route/v1.php` | 在原顾客认证组及原移动CustomerMiddleware组加具名固定路由，无withRule豁免 |
| `crmeb/app/adminapi/route/order.php` | PC具名私有读路由，原AdminAuth及具名原权限适配 |
| `crmeb/app/api/controller/v1/order/StoreOrderRefundController.php` | applyExpress必传Request真实uid；接新寄回备注字段，原V1分支不改 |
| `crmeb/app/services/order/StoreOrderRefundServices.php` | applyRefund原真实save后同事务绑定；editRefundExpress真实归属/阶段/合并/备注/同体确认；customer/platform/list已授权叶子DTO |
| `crmeb/app/api/controller/v1/admin/StoreOrderController.php` | 原refundOrderDetail legacy响应叶子加DTO，原CustomerMiddleware资格保持；不修改资金动作 |
| `template/uni-app/api/legacy-refund-materials.js`（新） | 实际认证/页面scope、具名multipart、票据binary临时图与释放、明确结果未知先读回；不改公用upload/image |
| `template/uni-app/pages/goods/goods_return/index.vue` | 本人旧申请具名上传/选择，原申请字段和V1分支保持 |
| `template/uni-app/pages/goods/order_refund_goods/index.vue` | 本人寄回具名上传/选择、新寄回备注、原材料显示及结果回读 |
| `template/uni-app/pages/goods/order_details/index.vue` | 本人真实退款两私有组+原URL图、寄回备注、页面退出释放 |
| `template/uni-app/pages/admin/refund_order_detail/index.vue` | 原移动退款详情分组/备注及原资格读取、退出释放 |
| `template/uni-app/pages/admin/orderDetail/index.vue` | 同原移动退款详情实际消费者，非退款/原V1分支保持 |
| `template/admin/src/api/legacy-refund-materials.js`（新） | PC当前身份scope、固定list/detail票据与完整bytes/临时图释放 |
| `template/admin/src/pages/order/refund/index.vue` | 原退款列表合法读者的两组预览与备注，原URL和动作保持 |
| `template/admin/src/pages/order/orderList/handle/orderDetails.vue` | is_refund旧退款分支两组预览/申请说明与寄回备注；普通订单分支保持。该组件实际由退款列表details-from渲染，为必要消费者 |
| `help/audit-regressions/isolated/ms4-legacy-refund-private-normal.php`（新） | 仅具名新ISO-FULL正常入口、真实multipart/旧业务写读和独立完整副作用oracle；不调用停跑样本 |
| `help/audit-regressions/isolated/compose.ms4-legacy-refund-private.yml`（新） | 单一具名私有卷overlay，bootstrap/app RW及runner RO，无端口/新网络/提权 |
| `help/audit-regressions/isolated/lib/legacy-refund-normal-profile.php`（新） | 对实际注册Run/私有卷/容器代际的固定profile资格及普通TERM监督接线，不接管其它运行包 |
| `help/audit-regressions/isolated/lib/harness.php` | 仅固定新profile的加载/白名单配置/既有监督接入；原默认及现licence流程保持 |

未来具名外部编排包/SourceTree/tar/PINS/实际Run需由Root在代码与真实语法复核后按实际提交绑定；此清单不是这些未来产物已存在的声明。当前冻结候选共29个仓库路径；`StoreOrderController::applyRefund`现CSV→JSON已经容纳新ref，不因形式需要修改；公用api/order.js原字段/外部入口保持，新helper由涉及页面直接引入。已读Control quality；现有PrivateEvidence只接收真实asset id及具名reader，可复用组件而不放宽其原V1调用。

- [ ] Root最后核定本补充（人类可读备注事件/保存状态等价/29 Files）。
- [ ] 核定后在本W实现；交独立Source复核，Root串行真实PHP/完整正常业务与UI按原唯一清单推进，未跑仍NOT RUN。

- [x] Root实际e6be12全文核定995–1044补充：采用人类可读寄回备注、type5真实保存状态等价和29路径；原935节及本节的内部设计核对项据此完成。2026-10-25在本独立W进入server producer/writer/consumer实现，随后前端与normal；真实PHP/HTTP/运行/提交推送仍未发生，按实际分别记录。

#### CU_LEGACY_REFUND 必要调用点与独审修正（2026-10-25，Root采用）

独审确认committed()的已提交/未知读回异常若经原api全局handler，会退成无六键的generic fail。Root采用唯一新增第30路径`crmeb/app/api/controller/v1/order/StoreOrderController.php`：只包当前legacy `applyRefund`既有service调用的MerchantAttachmentOperationException窄catch，固定HTTP503/private no-store及原六键receipt；第14路径StoreOrderRefundController同样只包legacy applyExpress调用。原V1分支、全局exception handler、字段/金额/cart/hook不动。新前端失败先读取真实保存状态，不自动POST。此项在修改该新增路径前先记录；原29路径保留，其它新增需继续有实际依据。

另按实际源码纠正调用清单：确有两个editRefundExpress文本caller；StoreOrderRefundController::applyExpress是当前唯一已注册入口，传真实Request.uid；StoreOrderController::refund_express:864旧死方法未有路由/前端引用，本来使用不匹配的(id,express_id)调用，此次保持未接入，不加路由、不造物流字段、不兼容错误签名或默认UID。

正常局部退款会把cartInfos替换为getSplitOrderCartInfo计算结果；故另保原首次getCartColunm的实际snapshot，在同事务锁内对原snapshot核对，保原局部金额/件数计算。独审还确认原cart/cache与Refund/Notice/OutPush/Custom/Channel责任必须先保持完成：最终图片committed读回移至原全部post-commit责任之后、return前，绑定仍在原transaction；不吞未知异常、不取消原hook、不引入outbox。

Root采用独审确认的固定CORS header必要调用点，第31路径`crmeb/config/cookie.php`：只将`X-Legacy-Refund-Read-Ticket`追加到现Access-Control-Allow-Headers，原允许域/凭据/认证/权限种子和其它header不改。ReadServices/前端binary已使用该固定header；同源normal不会据此计跨域、H5/PC视觉或外部client验收通过，真实browser仍按原清单分别验证。先记录后修改该新路径。

### CU_LEGACY_REFUND 当前实际源码与验证边界（2026-10-25）

在上述独立W、基线c891上，当前已实现具名legacy退款申请/寄回上传、原保存transaction中真实PK绑定、实际UID/旧父链/阶段复核、旧材料稳定追加与独立寄回备注、同体保存状态确认、本人/原PC list/detail及移动合法资格的票据和完整binary、原授权后最小DTO；两上传页与5个既定Uni消费者（其中两页兼上传）及PC列表/共享详情已接线。原商品/SKU/价格库存业务未改；新数据仍为原34列两purpose，不改变V1 18列表/服务或原V1调用分支。此为实际候选实现，不是完整业务通过。

Root实际固定image `297b37…` 的PHP8.2.33 `-n -l`对server17件均native0；5项原生命令及完整created/terminal同PID、wait和双流已由Root读回，专用readonly/network-none语法helper自然Exited0/PID0并nonforce移除。仓库外`legacy-refund-server-php82-syntax-r1/RESULT.json`为17100B / `e44987a0ff1ef60807d5d1f6e254026f68b8721390dc0ac4e0b66ce00c123c0e`。本作者af5e1f/7947f0及本次再次核原件及17个实际文件fp一致。只parse，无include/App/HTTP/Timer/业务/Composer；后写normal profile/harness不在该17内。

前端两helper和7个Vue共9script已实际Node纯module编译9/9 native0，74个真正静态相对/alias依赖全部存在；没有evaluate任何应用module。仓库外`legacy-refund-private-source-check-r1/JAVASCRIPT.json`为9368B / `d7f1c123c16b64f6ec0a5fb889a33e5f5d28c9bf4fc1a8f23e0a09b1d2ecd2b4`，本次9文件fp仍一致。Node的VM模块实验性提示与项目代码错误分开；早先regex把原注释中的esp.js算入，真实编译依赖已排除该原注释。检查不覆盖Vue template编译/build、实际UI/交互/浏览器或MP/APP/设备，这些仍NOT RUN。

独审已指出并按Root采用修正：末尾私有读回置于原全部postcommit责任之后；两个legacy控制器保原六键未知回执；显式新备注presence与旧别名区分；固定票据header的CORS追加；实际PDO在callback之后commit的异常以“callback全部原写入完成且有本用途outcome”有限转换commit=null，不把早前bind后的outcome当完成标志，不猜rollback等于未提交，不跑未确认提交后的hook、不改ORM/加重试。独立最终Source结论仍以review者实际修后读回为准，不能由这些修正推定全PASS。

normal已写独立private卷overlay、function-only固定profile及harness有限接线，只有新AUDIT_LEGACY_REFUND_NORMAL、固定/run/audit-customer-refund与REFUND_PRIVATE_ROOT、原同代TERM-only停止；旧默认/执照flag各自原根保持，新旧profile不可混用。normal完整入口fixture/oracle还在实现；新Source/Val/Root host编排、提交/新HEAD/tar/Tree/PINS/实际Run均未绑定。除上述Root纯语法Docker helper外，截止本段无本项App/HTTP/实际资金、业务结果、业务实例收口或推送/部署。

#### normal fixture与独立oracle设计补充（Root已采用方向，尚未运行）

新专用ISO-FULL准备先执行既有MS1 check/apply/check/repeat、MS2及MS4/Asset原check/apply-structure/check/repeat，使用仅bootstrap的audit_migrator和MS2本Run新0600stateFile；不手写跳过原schema gate，不扩大audit_app DDL权限。原MS2会在该新实例产生真实测试自营正ID，但不作真实历史映射、不启用marketplace/trade，也不把旧mer_id=0解释为自营。普通业务及oracle始终切回DML audit_app。

T5夹具是本Run合成v0 paid状态、真实顾客UID、完整order/cart/商品snapshot；paid=1仅为本隔离材料用例的合成前提，不冒充付款producer、真实渠道、真实旧数据或原历史37步。至少零图、旧URL、新私有申请三条正常材料路径；新私有路径在准备中真实调用原equalSplit取得pid=-1规范根及合法子单，在HTTP申请里使用子单真实PK，资产root_order_id必须实际归回规范根。合法移动actor由原配送资格业务建立，与退款owner分别核对；原PC使用现真实最高管理员认证，不伪造受限授权菜单。

HTTP保原申请/真实refund PK→原平台同意退货(type4，无资金)→本人寄回→type5→同体保存状态确认。选中和未选STAGED区别保留，已有申请图/旧URL/申请说明/首次bound不得覆盖；新寄回备注作为唯一人类可读原status事件；本人、PC list/detail和原移动资格分别经真实ticket/content读取原bytes，真实旧URL单独读取。每个实际请求前后均独立比较所有实际表rows/DDL/hash/真实next counters，Redis全部实际类型/值/tag/绝对expires，public/runtime/private完整文件/目录、真实原14列与原事件/audit/commit允许delta；不镜像新DAO生成expected，不用MAX+1/NULL归一、不宽泛豁免整表或缓存prefix。首次metadata计数沿完整表读取后真实重读记录，不把观察初始化当业务写入。

原Notice/OutPush/Custom/Channel责任保留，通过实际配置与候选查询证明不会发外部消息/资金；不能删除hook以消除副作用，真实允许delta需独立列清。原完整交易A/B、V1完整运行回归、历史37步、真实支付/退款/对账/结算与全部UI/设备/外部client/人工验收仍按原goal清单推进，不由本T5合成正常链代签。已停止负向/故障/并发/恢复样本不运行，也不记PASS。

#### CU_LEGACY_REFUND 前端独审有限修正（Root采用，2026-10-25）

原api旧退款列表包含type3/6，旧application仅阻断pending1/2/4/5；同一旧订单可再次合法申请，故内容/时间相近不能证明unknown POST因果。补有限完整意图确认：保POST前不可变body及真实cart_id/件数选择、请求退款类型；只有本草稿真实上传并选中的私有ref提供本次关联，保存行仍为确切申请type1/2且全部选择/说明/用途ref匹配时，才可确认对应已保存状态。由于原agreeExpress能将任意原类型改为4，不能从4/5反推初始类型；无法证明、零图或缺完整cart/本次关联时保持unknown，只读取真实记录供查看，不自动重POST，不以旧type3/6确认。此为有限旧接口结果核对，不加通用幂等框架/数据列。

将同步busy锁移至首个await之前，冻结该次body/选择；真实scope仍先于业务POST。finally统一释放，双击不能替换pending。严格解析服务端六键receipt（固定字段、类型及安全phase），随error传递并在当前草稿保留，以只读状态文字呈现；不显示locator/token或把状态做按钮。PC共享退款详情legacy分支补原refund_img旧URL顺序预览，与两私有组并显，普通订单/V1分支保持。Root已采用，限现helper、PC详情与两上传页，修后重跑9script实际编译/依赖及diff检查；不添加故障/负向请求或样本。

前端修正后实际Node9/9 script编译、74个真实静态依赖存在、diff --check native0；外部r2 `legacy-refund-private-source-check-r2/JAVASCRIPT.json`为9538B / `a57d611a5858df7313b1d39af780f20955f7e9f8a6b00dfba829166777768579`。此证据只parse/deps，不覆盖template/build/UI/设备/业务，原r1不再是当前前端pin。独审仍按修后实际源码收口。

normal原producer补充：`StoreOrderCreateServices::getNewOrderId('')`走原RedisSequenceResolver，raw handler无prefix，key为Snowflake实际毫秒（由真实新退款号反解2022-01-01 epoch），首次原Lua设string 1及PSETEX1000。这里只独立核该确切单key/值/实际HTTP毫秒与Redis时钟期限；不是放宽时间prefix。Redis观察置于全表读取前并记录观察前后实际TIME，旧key自然到期只能按该key原绝对deadline和实际after观察时钟证明，仍保所有其它类型/值/绝对到期/标签；不睡眠等过期来消除副作用。完整正常入口继续据此实现，尚未执行。

normal初始化事实纠正：安装seed的`send_order_apply_refund`存在渠道flag=1，不能将全0当原安装状态；原设计中“实际flags0”不适用于该退款通知。已逐个读原Sms::sendAdminRefund、Wechat::sendAdminOrder、SystemMsg::kefuSystemSend：三者均先取原`getStoreServiceOrderNotice`；本新专用夹具必须真实空notify客服列表，配送资格不构成该客服列表。原EnterpriseWechat::weComSend另要求`is_ent_wechat==1 && url!=''`；准备守门须实际证明退款通知唯一且其flag非1或url空，保原配置不修改；登录通知仍按原实际关闭渠道核，另保原queue_open0实读。保Notice/OutPush/Custom/Channel代码/实际责任及完整副作用oracle；此为按真实producer修正合成前提，不冒称消息发送验收。已向Root报告，尚无目标执行。

Root采用前端回读细节修正：没有本次关联proof的零图/不完整意图也需真实读取原退款列表，才符合“读取已保存结果”按钮含义；仅删findApplication无intent早退，仍完整GET原实际分页，matchesApplication(!intent)始终false，因此结果维持unknown、不猜本次因果、不自动POST。先记录后改，normal63283/6a6bb03a冻结供独审/Root纯-l，不并写。

当前normal候选已写完整prepare/verify入口：真实MS1/MS2/MS4/Asset前提、合成paid旧单与真实equalSplit、完整34列上传、零图/旧URL/私有申请原transaction、原同意(type4无资金)、寄回type5/人类可读独立备注/实际保存同体确认、本人/PC list/detail/原移动资格真实ticket/content/旧URL，以及每次请求完整表/真实next/Redis/文件精确副作用核对；normal完整运行、外部编排/提交Source绑定与TERM/资源独立收口均仍NOT RUN，不能由入口存在计PASS。entry现63283B / `6a6bb03a848b75980ca08c6a146dbf5e9b97db0dc028074decc416c343534f8c`；profile3652B / `5f2faa424be4ceed36aeeeb37f5c73a1f2a14a0717fc174d904ee6fe8ae76f50`；harness56450B / `84f36a6e70049ce21eb8c1908af824fbaa338d9daed38c46f630cd396ac245b1`；overlay767B / `c271fcb387b0b783a7496d33af22383d0a6796d53c8ad2c691473a1b5503c986`。这3PHP尚未有本作者运行/parse声明，Root拟串行纯-l；独审最终source仍待实际读回。

仅删无intent早退后实际Node9/9、74静态依赖及diff --check native0；外部r3 `legacy-refund-private-source-check-r3/JAVASCRIPT.json`为9538B / `e8036159fff350226b976329333fb5c900f234b63bfc3a87df8e76ed6d5e2bf6`。Uni helper30277B / `f33f005bdabf90b962168f6afd68102890d52201697af1fb3d59e97e2e79378c`，其余8前端保持r2。Vue template/build/实际browser/CORS/MP/APP/设备仍NOT RUN，不由纯script parse代签。


Root当前Source验证与本地检查点（2026-10-25）：17件生产PHP已在固定PHP8.2.33纯-l17/17全0，原结果17100B/e44987a0ff1ef60807d5d1f6e254026f68b8721390dc0ac4e0b66ce00c123c0e；当前正常入口63283/6a6bb03a、profile3652/5f2faa42与harness56450/84f36a6e亦串行纯-l3/3全0，5native结果7196B/f4e3e7caceadb9bbb6c734548f2398d53e4d0daa7dedba6c6ad55c538e0c56a2，Root0d2f82完整PID/wait/双流1763B和3来源前后指纹读回。均只解析，不include/App/HTTP；只读network-none helper自然Exited0/PID0后nonforce移除。当前生产17和正常3共20件PHP有对应实际语法证据，不能据此宣称正常业务通过。

九前端当前r3解析9/9与74依赖存在检查0，结果9538B/e8036159fff350226b976329333fb5c900f234b63bfc3a87df8e76ed6d5e2bf6；非作者已逐段复核六键收据保留/只读呈现、同步busy锁、PC原URL并显、本次已上传ref+阶段和完整cart件数/原因说明才确认unknown，零图不猜关联且主动读取确实GET，正常HTTP明确成功保原跳转。其它原5件保持原字节，V1和普通订单未漂移。当前normal4件全Source独审0且全部原副作用条件保留；表/Redis实际效果、真实5reader/跨域/模板构建/浏览器/设备与外部精确资源收尾尚未执行，不计Runtime/阶段验收。原17server/9front/normal4/record共31路径保存本地Source检查点，后续须clean Source/独立Val/fresh ISO-FULL真实正常链后才记该范围业务通过，不代真实付款/37历史/fullA/T5全部/19组完成；未推送部署。

#### CU_LEGACY_REFUND 正常通知准备与站内信独立核对修正（2026-10-25，Root采用）

Root回传的原Run `202610251550496d5a8f` 中，setup正常完成；normal的prepare原生退出2，唯一失败为 `M4LR_ORIGINAL_REFUND_NOTICE_NO_QUALIFIED_SYSTEM_RECEIVER`。prepare尚未完成，verify及业务HTTP未启动。原DAO实际按status=1且notify=1选择客服；原通知配置开启站内信和短信、公众号渠道，实际存在合法接收者，故前述“必须空列表且不改原配置”的合成前提错误。本段取代该准备假设，不改写原失败和冻结Source/Val/Host/Run，不把阻断计为业务通过。

Root已采用两路径修正：本record及 `help/audit-regressions/isolated/ms4-legacy-refund-private-normal.php`。在从81ec14建立的新独立 `ms4-legacy-refund-normal-notice` 工作树实现，原生产服务、hook、seed SQL、客服行、前端、profile/harness/overlay保持。新fresh实例在预热通知及任何HTTP之前，只经原后台 `SystemNotification::set_status` 所使用的 `SystemNotificationServices::update($actualId, [$type => $status])` 真实服务叶，分别把本次唯一 `send_order_apply_refund` 的is_sms、is_wechat从1设为原DDL明确的关闭值2；不使用0伪造“渠道不存在”。保is_system=1、原全部模板及接收客服全集/重复关系/UID/手机号、is_routine=0、原is_ent_wechat=1和空url；后两项继续用真实严格门确认无可达企业外发。准备不调用controller的宽缓存clear、不清Redis、不替换消息驱动或依赖空凭据、断网、异常被吞来通过。原业务仍执行所有Notice/RefundCreate/OutPush/Custom/Channel hook；真实外部短信/微信验收继续NOT RUN。

配置准备先以原生PDO独立读取完整通知行集合和客服全表，服务写后核只改变真实唯一ID的上述两列；其余字段、行和接收者关系完全保持。专用0600证据记录本Run实际来源、原默认、精确配置差量及接收者资格摘要；完整实际配置和接收者投影只冻结进原0600私有fixture，报告不含手机号等个人信息。不能在prepare复用verify的首次counter证据输出路径，原完整读取后重读native next的门保持。

三次原正常申请均须由真实SystemMsg hook新增站内信。独立oracle在请求前据冻结模板、原真实order_id和实际eligible客服多重集合构造期待，按实际原生AUTO_INCREMENT next与接收行数核新增ID集合及完整列；不取MAX+1、不从after反选期待、不得去重接收者。当前安装DDL为10列id/mark/uid/title/content/data/look/type/add_time/is_del，严格按真实完整列集核对，不人为添加“第11列”。模板占位替换与原json_encode订单参数、type=2、look=0、is_del=0、实际HTTP时间窗和全部旧站内信行保全均必核；保存失败若被原hook捕获，缺少预期新行仍必须让本oracle失败。只有三次申请可有这些精确新行和native next增量；其它请求和全库所有其它rows/DDL/next、Redis类型/值/tag/绝对期、公私/runtime文件继续原完整比较。

本修正继续原唯一执行清单，不新增平行验收流程：

- [x] Root采用实际失败原因、专用测试配置与保留真实站内信的设计；新独立worktree及两路径范围已核。
- [x] 实现prepare的真实两字段配置、独立完整行差量、私有fixture冻结及三次真实站内信oracle，原业务case和严格完整比较不减。
- [x] 仅做Source/静态及原生Git差分检查，向Root提供当前精确bytes/SHA及余审点；不执行候选或自行提交/推送。
- [ ] Root独立复核、当前字节PHP语法、新commit/Val/Host绑定及fresh正常运行、普通收尾/独立zero按实际另记；目前均NOT RUN。

原零图/旧URL/私有材料、真实equalSplit、type4同意/type5寄回及同体保存确认、五reader、完整34列和全部原副作用责任不变。Source SHA沿现manifest和真实未来commit自然绑定，不写未来Run、通过结果或资源身份；停止的负向/故障/并发/恢复及K样本仍NOT RUN。本作者当前只在上述新W写Source，不Docker/PHP/SQL/HTTP/资金/目标或秘密读取。

本轮Source实施与静态收口：normal当前73129B / `22f053f44f8b15e5db06a1203f66adfc6e649fbc57011fb95828b7996c2d4d02`，40个原函数中37个正文逐字节保持；仅prepare/apply/verify接线变化，新增4个有限通知helper，原main dispatcher不变。原完整record前缀保持，只有本节追加；两变更路径UTF-8/LF及git diff --check为0。静态保留核对确认原三次申请和全部既有case仍在，两个错误通知前提由本段Root已采用的正向严格条件替代。独立非作者已全文核新增helper、真实producer/10列DDL及相关原调用关系，finding0；其复核对应本段追加前record204710B/74b6dead…，normal字节未变。本作者与该独审均未执行候选、PHP或测试，当前字节的PHP语法、fresh正常链和清退/核零继续NOT RUN，Root另行安排；没有提交或推送。

Root本地提交前补充：964783全文diff、138499原record增量及原NoticeListener/SystemMsg/配置getter/退款调用链逐项读回，未发现新增确定问题；独立notice_oracle_review对应相同normal73129/22f053f4 Source0。实际固定1099镜像PHP8.2.33对当前normal纯-l为0，5native/10完整双流983B经Root核对全0，只读networknone helper自然Exited0/PID0后nonforce移除，未include/App/DB/HTTP。结果 `artifacts/project-organization-20261023/legacy-refund-normal-notice-php82-r1/RESULT.json` 5851/2588785587c61964d6a7c4c25aedb0f57800b1394d946699e8b47d29aaa1dc0d。该证据只签解析，fresh普通业务及收尾仍未运行；原失败Run已普通close0/zero0、5CID13卷1网络精确清退，失败不重放。

#### CU_LEGACY_REFUND 平台日志期望列顺序修正（2026-10-25）

实际新Run20261025163202364696准备已通过，9个HTTP200后在零图平台同意后的完整行比较失败；原FAIL不重签、不重放。Root已全文查实际SystemLog DDL和m4lr_log_delta：真实列序page/method/ip/type/add_time，而独立expected把method放add_time后。逐字段值门已先通过，完整serialize多重集才因PHP关联数组键序误报；新只读PDO afc61a用相同值复现“旧序不同、原生序相同”，只用作诊断，不从after生成新expected。原App finally已TERM-only关闭，失败实例现普通close0/独立zero0、5CID13卷1内部网nonforce并完整Root回读c3bf2f。

此小修复复用当前独立feature工作树，保e499提交对象、detached Val-r2、Host/Run/回执不变；只把m4lr_log_delta独立literal的method移到真实DDL位置并加入明确原生键序门。原完整所有列值/类型/时间窗、完整行与全表/DDL/next/Redis/files校验保持。另在原完整行断言失败前写一个不含原行值的专用诊断：实际table/actor/行数、列序集合与整行多重集摘要；诊断写失败也必须保原断言失败，不能因此通过或遮蔽原错误。无生产服务、fixture数据、seed、前端、profile或原业务case变化。

验收：先核只改本record与normal两个文件，再Source独审/当前PHP语法；新本地提交后采用新来源/独立Val/fresh实例复跑完整普通链，原失败不续跑/不冒签。此时实现及实际运行结果待下文按事实补记，外发/金融/37历史/完整A/B/V1/浏览器设备与阶段验收仍未运行，停止样本仍NOT RUN。


Root实际提交前证据（2026-10-25）：已全文核两路径diff；非作者media_env7_review独审当前normal74444B/69da1241cf914fa058dbc16486f2fd19ed9f61f1b8dcf473bd004665e43ae485为Source0，原日志值独立、原排序/全状态及全部case保持。实际固定1099镜像PHP8.2.33当前normal纯-l原生0，与MS2 normal共同报告 `artifacts/project-organization-20261023/migration-and-refund-normal-php82-r1/RESULT.json` 6486B/35097bf8c72f0dbf1baa0a5589f12f4605a4e3939f4ad2e1e1f09cf78021e243。Root核5native/10完整双流1352B均0、source pin一致、两只读mount/networknone、helper自然Exited0/PID0/noRestart后nonforce移除；没有include/App/DB/HTTP。旧r2失败已普通close/zero，109+10native全0；Root回读 `artifacts/project-organization-20261023/legacy-refund-normal-r2-launcher-r1/ROOT-FAILED-VERIFY-CLOSE-ZERO-READBACK.json` 1318B/fcd36f04cf8a0723df99340c873b6a69727607d924eb2ba8b342a91ad7dae579。仅本两路径进入新L2本地提交；新独立Val/fresh普通链仍待，未签业务通过、未推送部署。


#### CU_LEGACY_REFUND 完整观察器有界内存与局部引用生命周期（2026-10-25）

Root实际r3 Run20261025095534ceb090以Source92c6eaf运行，setup/原145 prepare均0；原146 verify为255，stdout0/547B stderr明确134217728B内存分配失败，发生在normal第50行完整原表serialized多重集hash。原完整146native/292流11364329B已Root核，未生成NORMAL/verify成功，19条HTTP日志不代完整oracle通过。OOM未执行原finally，Root已另用原harness single audit_app_stop及原registered supervisor/child handle实际TERM-only关闭同一group，5native/10流9035B全0，members空/listener关/no force。随后专用内存失败准入Source33716/58bcb4df经Root/非作者Source0，Root实际普通close109native/218流95133B和独立zero10native/20流298B均0，5同代Exited0/PID0/noRestart、13卷1内网nonforce及Val3 ownlock精确清退。最终Root回执817B/0e5d98ad9045e4ce7b89ea7f568cb731c1ed4ac6d394347d624a7f99274bf1dd；旧Run/Val/Source/失败原件保持且不重放。

实施前只读Source设计及Root采用：m4lr_database保存所有原表native rows，同时按原serialize→sort→serialize公式hash；上一表serialized临时数组直到下一赋值RHS仍在。m4lr_read的ticket完整transition后旧before/after仍在，紧随的before=m4lr_state() RHS可在同一时刻保留三份完整库快照。只在normal及本原record两路径修复，不丢表/行/duplicate、不改公式、不换stream hash、不从after构造expected、不减任何原断言。

1. 每表原hash、完整rows、DDL和初始next均入out后，释放本地serialized/rows/create三个临时引用；out原完整行仍保留，两个独立counter map/HTTP为零的观察窗不变。
2. ticket的完整transition成功后，在这些值最后真实使用点释放before/after/changes/increments，再取原content前后两个完整独立快照。其后无旧值消费者；原ticket/payload/Redis类型与绝对期限、真实binary及全表/DDL/counter/files检查保持。
3. 仅m4lr_verify()内，在原profile/ISO/source guard成功后且任何完整快照前，给这一个CLI设固定512M并readback；App初始化后及正常返回前再核保持，结果只新增before/effective/fixed bytes/真实peak数字。prepare、独立HTTP App、php.ini、Host/Compose、数据库权限、原私有根/notice/全部业务case不改，不设-1或动态按失败翻倍。

原base/overlay/Host Source未声明mem_limit；Root另实际只读engine MemTotal33498484736B（31.2GiB），capacity RESULT770B/865adeb3ca8abe6dae1a5316e8ea962979f76646d145ef82d3773b9d1898b36a，支持单verify固定536870912B有界选择。容量/Source不证明真实峰值或业务通过；新Source须Root同固定1099 Runtime纯-l、新clean提交/Val/Host及fresh完整正常链实跑并记录实际peak，再普通close/独立zero，旧失败不续跑。

- [x] 冻结旧Run，Root实际App普通TERM关闭与5resident/13卷/独立zero完成后才开始写本两路径；设计先写入本原执行记录。
- [x] 仅三原函数的小范围Source实施、最后使用点/原所有oracle及case字节保全、静态与非作者独审；本轮Source0，实际Runtime仍待。
- [ ] Root当前PHP语法、真实新Source完整正常流程/有效512M与实际peak、普通清退及独立归零，未发生仍NOT RUN。

Source修复不会关闭真实退款渠道/37历史/FullA-B/V1/CORS/浏览器设备/外发或人工阶段；原停止的negative/故障/恢复/并发/K仍NOT RUN。此时本作者仅写Source和静态核，不执行PHP/Docker/SQL/HTTP/目标、秘密、提交推送或C-D。


本次Source实际收口：normal75398B/`d23151e3b2a1e03fc78cca40794bac68f3ec5d72004540dd0a63312d92740527`，44原函数只database/read/verify三件有限变化、其余41正文原字节保持；六编辑点整份逆回原74444/69da1241，原expectation字面量、case/HTTP/state调用数量及原dispatcher完全保持。227cc9仅词法括号/UTF8LF/diff-check原生0，不冒PHP解析；d694f2为完整逆证。非作者media_env7_review实际97b258/677ff3/ff1489全文核三函数和全部调用处、最后使用点及Host消费：原out全行保留、ticket原pair无后继消费者，key/ticket/addition标量留用，独立content前后state和完整transition不变；verify-only512M和三次readback、真实PHP allocated peak字段不混称RSS，不改变独立App/prepare或Host。当前normal字节Source0；其审Doc为本事实段追加前212660/ae2a424a，之后仅本原record补事实。

Root另已实际固定1099镜像PHP8.2.33当前normal纯-l原生0（e585fe），1ca19a核完整5native/10双流983B全0、当前Source before/after一致，networknone/只读helper自然Exited0/PID0后nonforce移除。报告 `artifacts/project-organization-20261023/legacy-refund-normal-memory-php82-r1/RESULT.json` 5820B/096c9cc2502c7c3574349861cef6ea8b32409f86ac972eae32d529d08bc49dd5。这只签解析，没有include/App/DB/HTTP或新业务Runtime，也不证明真实peak低于512M。新clean commit/Val/Host、原完整普通链有效配置与实际峰值、普通close/独立zero仍须Root实际完成；未因Source0或-l关闭原验收项。

本作者没有运行PHP/候选/任何目标、读秘密、提交推送或改C-D，只改normal及原record；原冻结Run/Val/Host/Source对象保持。原80/37历史、真实金融、FullA-B/V1/CORS/浏览器设备/外发/人工及停止样本边界不变。


#### 通用多商户统一集成设计与任务（2026-10-25，Source-only，待Root采用）

本节继续本文件这一份执行清单。目标是在同一真实Git来源上整合MS2活动类型迁移、商品图片/本地分片视频/云视频finalize、既有执照与旧退款私有材料及完整正常链修复，复用原商品列表、SKU、规格、价格和库存，不引入天俪专属业务。当前授权仅为独立W准备和本节设计；不合并、不改应用/测试代码，不执行Docker/PHP/SQL/HTTP/目标，不提交推送或改canonical C/D。Root采用本设计后才进入代码整合，任何后续结果按实际另记。

实际未发现合适的已存在集成W/branch；已从已提交退款`0d1c9d4b4f96983033a9f1f0029356e14f4c4c74`新建`H:/天俪商城/worktrees/multi-merchant-integrated`，分支`feature/multi-merchant-integrated-20261025`，创建后HEAD仍为0d1且初始clean。本节是该W当前唯一计划改动。原退款W、detached Val、全部冻结Source/Host/Run/失败与退役证据均保留；新分支不是已完成集成版本。Git auto-sync实际为project_not_enabled，不自动登记或同步。

固定输入与依赖：MS2采用`63260f9d2d7a943e382c4f5f83f60be6e33ed9b8`，其文档所记实际正常四阶段来源是`057f769e37bc79c31d96b3ad05d56e8590d26768`，不得把632文档提交冒充057f测试来源；云视频采用`021a7de4b07fb1c6375b8b1b1772a12db74ee408`，其离线SDK/语法结果不证明真实供应商/自然云回收。退款0d1已真实继承`d0a5d5398546e223122b5c2a2f115e1908f78ced`、`b54ad9c39ca95a1b8b15ed6724082b98b1370c53`及`bafb6305062aeb6b6e945c21da572863062ca480`；不能被较早媒体叶覆盖。两个分支相对退款的真实共同祖先均为`41c0d795ff36ff98ed267bc49bb042e2f6e17bf8`。

**最小union及真实五处交集。** 已用共同祖先到各输入的原生Git差量核对：MS2与退款恰有下表5路径交集；cloud与退款交集为其中除harness外的4路径。其它独有变更按真实差量整合；MS2/cloud共有的媒体实现先核原字节与后继云扩展，保一份完整最新实现，不复制整个旧树覆盖退款基线、不丢测试或文档责任。

| 路径 | 必须保留的最小合并规则 |
|---|---|
| `crmeb/app/api/route/v1.php` | 保退款、执照及原路由完整分组/认证/options，另保MS2/cloud同一条mobile商品图片上传路由且只登记一次；不增加旧死方法入口、不替换真实actor或放宽权限。 |
| `crmeb/config/marketplace.php` | 保assets.customer_refund_private_root、customer_licence等原私有根；并入product_video_upload及product_video_cloud_upload，重复本地视频块只留一份；保原Env键、显式私有根、全部限额/期限和enabled默认false。原marketplace/trade开关与整件上传默认路径不改变。 |
| `help/audit-regressions/isolated/lib/harness.php` | 合并原media normal的app/media-timer分channel、独立control/status/Timer命令与日志、精确trusted overrides及Timer原生正常0观察；同时完整保licence/refund两个固定private profile的真实挂载资格、单次TERM、原句柄/组/代际、失败现场与restart_blocked。设计将media/licence/refund normal显式互斥，media-timer只在media profile可选；默认channel仍app、默认非normal原分支保留，不把任何private停止降回可升级路径，也不让channel/override跨用途通用化。 |
| `docs/multi-merchant/CURRENT-STATUS.md` | 后续按组件真实来源合并事实与未验项，旧失败与收尾保留；不同HEAD局部PASS不能相加为统一HEAD、全MS2/MS4或人工签收，不填完成百分比。当前不写此文件。 |
| `docs/multi-merchant/records/ms2/upload-asset-domains.md` | 保原0d1完整前缀、两分支既有设计/证据与本节统一任务；相冲突的历史状态按原时点保留，再追加当前事实，不覆盖原失败或减少原验收。 |

非交集也须保持三项精确责任：d0a的`m4trp_fixture`继续通过既有`m4cf_owner_after(customer_form, authenticated_owner.actual_user, uid)`取得完整sealed付款用户后态，再保单行及整行严格比较，原native34/文件/身份/付款归档不变，不退成只比UID或忽略认证列。b54先由真实语言服务证明原映射非空，只在`sys_lang_source_map`、`lang_type_data`这两个逻辑键的原`CacheService::get(name,null) === []`时单键delete且必须true，再走原`getLang('操作成功')`；不删缺失/非空键、不set预制map、不flush Redis、不改生产getter或TTL/标签。bafb仅保限定元数据的warm_failure_context诊断，原失败断言与同一异常重抛，不增加缓存操作或把失败转成功。

**安装SQL与语言组件的前置兼容证明。** 原b54/退款/cloud安装SQL为`crmeb/public/install/crmeb.sql`，10237372B / `7af188f42c622db523d8000c0cf50f95fa48df2fe41123969ca2dbb65ffb5eb2`；MS2携带媒体941安装SQL，10237459B / `1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828`。只读完整比对已证明差量仅attachment_category的AUTO_INCREMENT 9→11与追加原历史未分类图片PK9/10两行；不再改seed或原历史80归属。三语言表完整DDL及全部原INSERT（不是只比中文选集）在两份SQL分别逐字节相同，连续完整表段为：

| 原表 | 完整DDL+全部INSERT连续段 bytes / SHA256 |
|---|---|
| `eb_lang_code` | 2457682 / `ba7c7d77fbf5505e9758f67680c3be74635afe7993eead861e4d5e5a218dc40a` |
| `eb_lang_country` | 15875 / `3d5dd9950db16a71fe39de1a426a0d05e2d6274f3fa23cbe6147c6713fd71235` |
| `eb_lang_type` | 1114 / `becde14465e6b02b9ba4b497696d8c609a3ab5b02e8fb73e9148ec1259170109` |

作者与非作者已各自只读核三表raw段；独立DDL与全部INSERT各自的bytes/SHA亦相同。原全SQL语言rows为code25150/country350/type10；原decode仍严格核31条INSERT与这些完整总量，再按既有条件选择中文canonical code2515/country1/type1，不因集成删其它语言。若统一树采MS2的941 SQL，只窄重绑`ms4-language-cache-normal.php`的M4LC_SQL完整文件描述及依赖它的真实来源闭包，保两个只读Source根完整同字节、原全表解码/列集/原PK/参数类型、真实事务commit及完整reselect门；不得删EXACT_ORIGINAL_INSTALL_SQL或接受任意SQL。此时仍未修改该pin或执行安装。生产common.php原getter在两来源完整43222B / `a5379e999165f4fa1f1969523211111caf383d04d8c5a2ad21164a72f1ac4a7c`相同；941较早afterreview叶不包含b54后修，合并不能用它替换b54叶。

**数据、权限与默认兼容。** 只复用组件已批准结构与接口，不另增表/列/索引/owner抽象或新种子；真实正mer_id来自原MerchantContext，0仍不代表自营。MS2 type4/type6活动PK与base商品PK、同owner模板映射及原首次8/重复0等完整oracle不改；历史80/37不据合成数据补签。bootstrap-only audit_migrator仅用于新的专用实例原迁移与真实初始化，普通App仍DML-only，无root/migrator秘密挂载。customer asset原34列两purpose/执照、product attachment原native14、原Notice/OutPush/Custom/Channel及提交未知责任、五reader与CORS前提保全；不靠清库、清缓存、放宽rows/DDL/next/Redis绝对期限/files断言取得通过。退款verify-only固定512M和三次readback/真实PHP allocated peak保留，prepare/独立App/Host/Compose不扩容。云视频默认关闭，保原本地/整件兼容、正式对象新key、unknown不自动重发、精确暂存责任与原自然Timer GC；供应商、私有性、CORS/上传期限/自然清理等真实资格仍单列未验。

**统一验收与收尾/恢复设计。** 代码整合和独审后由Root形成真实统一clean提交；所有新Val/Source全tracked namespace、每body blob1/SHA256、稳定path-handle、分Git目录及Host/descriptor闭包按该同一统一HEAD重新绑定，数量以真实树为准，不搬用旧26/31闭包或旧Runtime结果。各互斥用途从该统一HEAD建立各自新的专用Val/Run，不把不同profile硬塞同一实例；setup→原完整normal→普通close→独立zero必须各有实际原生同PID/argv/句柄终态与完整双流。旧组件的057f/941/b54/0d1及退款历次失败/收尾仅保历史参考，不能给新HEAD代签。

后续须在统一HEAD重跑MS2既有完整普通迁移/38行/10CLI与真实重复语义、商品媒体/原Timer正常链、语言组件、执照/旧退款完整链；再继续原完整交易A、同源fresh B、售后评价/Worker、V1完整回归和三端页面/CORS/浏览器设备等原验收，不以新增小样本代替。DB完整rows/DDL/native next、Redis所有类型/值/tag/绝对期、公私/runtime文件与原业务独立期望保持。真实支付退款/对账结算、供应商与外部通知、发布/部署/人工阶段仍需原条件和相应授权，当前不启真实资金；仅BASE人工已签。原停止的负向/故障/并发/恢复/K样本仍NOT RUN，不因本计划恢复。

每个新实例成功也仅为retirement pending；只依它实际context/engine、同代role→CID、完整mount对象/重复数、labels、实际卷与内部网络设计有限收尾。普通TERM原注册group，保原句柄/代际；超时/身份不符/未知结果保现场，不KILL、不force、不重试原失败Run。仅真实同代Exited0/PID0/noRestart和only-own消费者门通过后nonforce精确移除，另进程六次fresh单label container/network/volume查询原始双流均空。失败不标PASS；Root先冻结真实失败及公共证据，再作该实例有限ordinary退役设计。恢复仅设计为保留旧Git/Source/Run证据、回到已核代码检查点并从新clean来源建立新的专用实例，不在旧失败上补丁续跑，不对共享/业务数据作回滚操作；本轮不执行恢复样本。

本节单一任务表（本轮只完成设计准备，后继未执行）：

- [x] 读Spec workflow、核Git enrollment与既有W/branch；实际从0d1创建独立集成W，真实共同祖先/五交集及三语言完整Source证明已核。
- [x] 在原record先记最小union、依赖、默认off、数据/权限/兼容、完整验收与普通收尾/恢复设计；保原前缀，不写任何新业务PASS。
- [ ] Root采用本节后，按真实差量整合MS2/cloud及五接缝，保d0a/b54/bafb和原全部case；语言SQL仅按上述证明窄rebind，再完成完整来源闭包检查。
- [ ] 非作者Source审、变更范围/diff检查及Root当前PHP/前端必要静态检查；语法/离线检查不代Runtime或视觉验收。
- [ ] Root形成统一clean提交，重新冻结完整Source/Val/Host；以同一HEAD的各用途fresh实例完成原正常链和普通close/独立六fresh raw-zero，逐项留实际证据。
- [ ] 继续原完整A/fresh B/V1/Worker/三端与设备、真实外部条件和人工阶段等未验项；已停止样本保持NOT RUN，不因组件局部通过关闭原责任。

Root采用：已全文核本节设计及共享源差量，独立复核Source0；Root另完整读取两Git SQL正文并核三语言CREATE到下一CREATE的全部原字节与31 INSERT一致。允许在此独立集成W按本节分步进行代码union及必要完整来源绑定，先整合MS2，再合并云finalize，全部保持仅L2/source阶段；实际目标由Root串行安排，统一HEAD验收仍未执行，旧退款r4 Source/Val/Run冻结保持。


#### MS2来源独有历史补录（文档冲突union，保留原时点）

以下原段完整来自MS2提交`63260f9d2d7a943e382c4f5f83f60be6e33ed9b8`相对共同祖先`41c0d795ff36ff98ed267bc49bb042e2f6e17bf8`的独有追加。前文退款HEAD及统一设计/Root采用句完整保留；本段原候选状态按当时来源读取，不与其它HEAD的局部PASS相加为统一验收，原停止样本继续NOT RUN。


## R97 平台site_image第一片真实生产与显式导入

固定GET/POST marketplace/platform-assets、GET marketplace/platform-asset-categories；POST marketplace/products/<id>/import-platform-image。scope/current admin/权限及完整native29、类别10按Root原单一T4记录；purpose只服务端site_image。新公开库件STAGED，expires_at/bound_at/retired_at均NULL，created_at实际time，无自动过期/清理；复制生成目标实际商家的另一物理对象/native14，平台原29/state/完整bytes保持。source BIGINT id全链decimal string，不伪装商业att_id/mer。

GET平台category_id=0列全部合格图片；POST=0写真实未分组。分页page1/limit18最多100/real_name，list返回独立platform_asset_id/src/satt_dir/name/real_name/category_id/state/byte_size/width/height/media_type，count实际同谓词；分类按原混合type树/lazy，id/pid是真实INT。import body仅platform_asset_id和目标商业pid，返回list[{att_id:真实新INT,src:真实新商业路径}]，不能直接把平台src选入商品。原商品transport身份/epoch/generation和最终image/SKU/HTML等保存门保持。

import实际route同时标记同一marketplace_permission与legacy_product_write，当前scope仍同一真实对象；newleaf/旧GET file/file/实际productsave AND额外platform-assets-list。平台三leaf具名注册，仅按对应原grant向启用角色追加；原17商品菜单完整保持，第18 import仅同样向启用角色复制。无生产/角色默认全权、伪scope、假self或自动enabled。

平台上传审计具名recordPlatformAssetUpload同PDO完整重选真实29，SystemLogServices新增有限row-return方法，原recordAdminLog八payload/bool行为完整保持。实际安装system_log为10列（id,admin_id,admin_name,path,page,method,ip,type,add_time,merchant_id），最后原default0不得丢弃；新方法仍只插原八payload，真实PK SELECT*核全10及原merchant_id0，未把该0当商业owner。全局generic record(null)写闭集保持。当前候选未做真实PHP语法、正常HTTP/数据库/存储/菜单/浏览器验证，provider1..7完整合同保留。其它平台purpose/绑定/退役/CRUD/商家平台导入、旧61分类、私有迁移和完整T4各原责任未被本片替代。


##### 第一阶段MS2标准merge与语言身份必要闭包（2026-10-25，实施前补充）

Root已采用设计并形成仅doc提交23c812e72cfb22d2d5ef856af1abd5a4d6021587；本独立W实际执行`git merge --no-ff --no-commit 63260f9d2d7a943e382c4f5f83f60be6e33ed9b8`，保标准MERGE_HEAD和两个真实父历史，不手拼单父提交。真实交集仍为前述五路径，其中routes/config已自动合并，两个doc与harness为三处实际内容冲突。两个doc先保HEAD全部原文，再附MS2相对共同祖先的完整独有历史原段及其原时点说明；本record首225934B仍精确等于已采用HEAD，不把MS2原历史状态当统一HEAD通过。

实际语言调用链还存在第二个相同身份硬门：`ms4-language-cache-normal.php::M4LC_SQL`之外，共享`ms4-aftersale-review-normal.php::m4ar_language_prepare`也严格要求source_files中唯一SQL描述为10237372/7af。前节及Root已对两完整SQL、三语言完整DDL/31 INSERT/原全rows作独立字节证明；统一树现采用MS2的941 SQL，故这两处均只窄改固定bytes为10237459及SHA为1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828。两个原完整文件读取根、唯一descriptor、所有列/PK/类型、decode全总量与中文选集、原真实事务/commit/reselect、b54两个精确空map及原getter、bafb失败诊断/原异常继续保持。只替换每处这一对来源literal，再用整份逆证证明无其它变动；旧SQL、旧Run/Val/Host和归档的历史pins不改，不执行安装或PHP。

harness按已采用最小union解决：保MS2原media-timer专属channel/trusted overrides/Timer原生0与TERM-only，保两个private leaf的原完整身份及普通停止失败保留分支，默认app路径原分支保持；在request及serve两个调度入口先拒绝media/licence/refund profile混用，不把private hardcoded app状态路径暴露给Timer。Source资格与全部原业务case保全另据实际diff/独审，不以本段实施设计代签Runtime。

梳理另发现active `lib/ms4-ui-backend-normal.php::M4UIB_BOOTSTRAP`五producer中四个来源literal落后当前文件，guard仍符。四项涉及customer HTTP entry、harness、customer HTTP library和customer form library；目前只定位，不抄当前hash放行。先追各producer真实来源差量、active UI消费者及完整原契约，保原merchant34步骤/25captures/9screens/23facts，不能以UI20或未采用UI17替代，也不引入旧未采用分支的新需求。只有必要功能兼容与原身份/fixture/返回值/副作用责任保持经Source核清后，才先在本record记具体有限来源设计再rebind；静态资格不代统一HEAD实际merchant验收。尚未发生的整合提交、统一Runtime、close/zero和人工阶段继续NOT RUN。


##### 第一阶段UI来源开放项与Source收口边界

四producer已追到真实Git原件，不以当前hash自行放行：customer HTTP entry121046B/9ba3cf59、HTTP library65119B/925314fc、form library72482B/a1ee3249在共同祖先41c、HEAD23c及MS2 632均已同字节，三处旧pin早于本次merge；旧entry115616/84186来自2fa872e6，旧两library65044/90317与66236/1d06来自0a0c35b4。harness旧48344/d1f6来自ceadbcff，HEAD56450与MS2 51190各有已采用增量，本次union59726/af3a3b24另按真实完整diff资格核对。

完整差量区分：entry后继有source-tree第9卷/initializer完整copy/freeze、两个RO源码卷、公有uploads根初始化及唯一fixed PHP_INI_SCAN_DIR透传；HTTP library仅官方initialize后按整个initializedEnv的真实key存在及原值严格相等；form后继包括原上传quota真实decimal string/100/86400及tag保持、有限失败/期限诊断与M4C_FORM错误前缀，原sealed owner_after保持。它们不是仅hash变化，也不应因旧pin倒退已采用修订。

M4UIB_BOOTSTRAP的真实调用是`ms4-ui-backend.php`→`lib/ms4-ui-backend-normal.php::m4uib_bootstrap`，在两个源码根逐件稳定读取完整bytes/SHA后function-only require customer entry；不会改SCRIPT_FILENAME或直接运行customer main。其直接消费的terminal/child_status、sealed owner_after以及harness七个proc/组/监听/immutable-source primitive与旧原件正文保持；child environment只增加精确fixedINI值两行。因此这些有限primitive有Source兼容证据，但不能据此宣称整个UI上游profile/装配、history、mount/volume及完整闭包已取得新资格。UI自身原8卷与原8+UI4门、customer完整链9卷属于不同用途，尚不能仅据数字认定必需把UI改9；后继由Root确认原fullS/active UI调用链及实际同源profile，必要时先在本record限定完整source-tree/copy/freeze/mount/history设计，再处理。

Root已限定本阶段保持M4UIB五项原常量（含四旧pin）、原8卷等硬门，consumer127620B/5d6f3496保持原字节，不作仅hash rebind、不引入旧未采用UI17需求；这处开放项不标PASS，也不阻断其它正确union。原商家34步走`ms4-merchant-http.php`及`ms4-merchant-browser-prepare.php`→merchant-browser/read helpers，未引用M4UIB；本次不改其原34steps/25captures/9screens/23facts及其它原要求，UI20不替代它，统一HEAD全部实际merchant/UI/设备验收仍待。

本次harness 34原命名函数相对HEAD仅request/stop_group/serve三处变化，相对MS2仅request/serve两处变化；全部其余原函数正文、两个private leaf及原seven UI primitives逐字保持，stop_group完整3446B/1ed94c76等于MS2原实现。两个SQL身份文件各只改固定bytes/SHA一对literal，整份逆回原b54正文；原warm/诊断/完整oracle和所有case不变。当前仅Source union，非PHP解析或Runtime结果；标准MERGE_HEAD、完整变化范围/独审及精确暂存状态以本次实际收口为准，不在这里编写未来合并提交或通过结果。


第一阶段Source实际收口（尚未merge commit）：标准HEAD保持23c812e7、MERGE_HEAD保持63260f9d；真实变化89路径（16新增/73修改），七个明确union/身份路径外其余82变更Git blob逐件等MS2，所有其它15704个HEAD路径保持原blob，index有15793个真实条目。两冲突doc保各HEAD完整前缀及MS2独有历史全文；无U、无unstaged/untracked，cached及worktree diffcheck均0的实际检查已取得，最后doc事实追加后再作同样最终检查。harness59726/af3a3b24经非作者全文与函数边界Source0：31个HEAD原函数和MS2 stop_group原文保持，profile互斥/两个private TERM叶、原media Timer通路/nativeZero及默认原支无确定问题；两语言身份文件保持前述精确二literal逆证。M4UIB四旧pin及完整原8/12装配门不改，开放项不标通过。

Root另已全文核本次harness union及两SQL精确literal diff，并实际用固定1099镜像PHP8.2.33对当前三文件纯-l全0：harness59726/af3a3b24、M4LC17628/9021f1be、afterreview245730/898eb47a前后字节保持。Root6583ef实际核5native/10完整双流1726B，三Source只读/netnone/helper自然Exited0/PID0后nonforce移除，报告`artifacts/project-organization-20261023/integrated-ms2-union-php82-r2/RESULT.json`为7116B/0a2aa6ff0352f5a8a8e0048daa3925436ed4962d2dac52b1bfa6a26e44521bb7。首个Root wrapper把自有created放入要求fresh的输出目录，被既有前置保护拒绝且未启动Docker；外置launcher后的r2正常0，保护未改，不是业务目标失败。本作者仅只读核公开报告指纹并引用Root实际回读事实，没有执行PHP、候选或目标。

这些证据只签本阶段Source与当前三文件解析；Root完整scope审与此次标准MS2 merge提交尚待，之后才进入第二阶段cloud普通merge。真实统一HEAD/new Val/Host/fresh Run、完整A/fresh B/商家34及其它原页面/设备/资金/外部/人工与ordinary close/独立zero仍须各自按实际新来源验证，旧组件及已退役退款Run不代签本次统一Runtime。未提交推送、未更改其它W/Val/Host/Run或Root canonical C/D，停止样本继续NOT RUN。


##### 第二阶段cloud标准merge实施前事实与有限union（2026-10-25，Source-only）

Root已实际提交第一阶段双父merge`de5b60d5e345a1e9535635b3d88a84117f8744fc`（parents为23c812e7与63260f9d），本W进入第二阶段前真实clean；不把该源码合并提交算成统一Runtime通过。现依已采用设计，仅在此W以`git merge --no-ff --no-commit 021a7de4b07fb1c6375b8b1b1772a12db74ee408`保标准MERGE_HEAD/父历史，暂不commit、push或执行目标。

实施前原生Git实核的新共同祖先为`b9089a5ae9d34bdeb350df774ec96b87b0eb55c6`，cloud相对该祖先恰14路径（9PHP、4前端、1原typed-media记录）；de5侧相对该祖先66路径，真实交集仅`crmeb/config/marketplace.php`及`docs/multi-merchant/records/ms4/product-typed-media-integration.md`。9PHP/4前端的021原Git正文、完整bytes/SHA256/blob1已逐件读取冻结；除下述实际union外不重写已审cloud实现、不重复原完整功能。

最小合并：marketplace配置以de5完整内容为基础，仅加入021原`product_video_cloud_upload`块，已有本地视频块不重复，原assets退款根/customer_licence根及其它配置保持；cloud的enabled默认false、空私有根/零staging storage/限额/期限/上传完成上界、固定供应商端点等完整值原样。typed-media记录保两边既有完整历史与原时点，不能拿旧941普通结果或离线SDK计划充作新统一HEAD真实云验收；若实际发生尾部冲突，以两边原完整独有段union并注明历史边界。本原record保de5完整236682B前缀，仅续本节唯一任务清单；实际冲突范围及最终diff按真实合并收口记录，不预填成功结果。

其余8PHP与4前端必须逐字等021原Gitbody，并逐项保持原默认off、本地/整件兼容、私有staging、正式对象另生新key及完整bytes/内容校验、原writer/owner/native14/审计和提交后回读、unknown不自动issue/finalize/重发/取消/降级、原ctx/pending及安全六键receipt。Timer只保原scan与本地视频之后的原具名cloud gcBatch及已核有限配置/held/absence责任，不新增Timer、手动清理或改时钟TTL。不开第三方/真实凭据/实际资金；真实type2–7云、私有性/CORS/浏览器长度/期限/生命周期/自然GC与UI设备等原验收继续未验，不以Source导入或旧静态结果补签。

de5已有harness media channel及三profile互斥/private TERM-only/default原支、941 SQL两新身份门、d0a sealed owner_after、b54两精确空map/原getter及bafb诊断、本record和所有原未验责任必须整份保持；UI四旧pin与原8/12门及其不确定开放项不变，不引入UI17。完成后只精确stage本次必要路径，冻结完整范围和全部非cloud路径原blob保全、8PHP/4前端原021正文、config有限逆证及两文档原文保全证明，再由非作者Source复核及Root当前必要静态/完整scope审后决定此标准merge提交。统一HEAD新Val/Run/完整A-B/商家34及其25captures/9screens/23facts、V1/Worker/三端与设备/外部/金融/人工和ordinary close/独立zero责任不缩减；停止样本仍NOT RUN。当前仍未执行Docker/PHP/SQL/HTTP/候选或目标、未改其它W/Val/Host/Run或Root canonical C/D。


第二阶段有限Source union事实：实际标准merge保持HEAD de5与MERGE_HEAD021，唯一内容冲突是typed-media文档；config自动合并。config当前3840B/56b5c100，可完整减去021原1127B/4843a3b3的cloud配置块后逐字逆回de5的2713B/a1339e42，其余配置完全不变；本地与云两个视频块各仅一份，所有原private roots、默认off与cloud期限/上界等原值保持。其余8PHP、4前端逐件完整正文直接等021 Git原件，未重写任何已审功能。

typed-media两分支均在共同16616B/8bc86aae后追加：本次保de5完整33507B/d662b1f2前缀，再加434B原时点归属说明并接021独有28586B/e54dc5e6原文，最终62527B/4d070109；两个分支原历史/设计/未验条款一字未丢，不把局部941或离线SDK当统一HEAD真实云通过。本原record仍保de5完整236682B前缀，后面仅本阶段设计与事实。真实范围为原14cloud路径加本record这一必要记录路径，不能为强求14删设计；完整清单、Git blob/正文与非cloud旧树保全、冲突解除和精确stage/diff检查按最终实际冻结证据交Root非作者审。

当前仍只Source；021原9PHP解析/离线6plan等历史证据仅归旧组件。本次新config当前解析与全部最终scope仍由Root另行检查，完整统一Runtime、真实云与自然清理/跨域/browser/设备/金融/完整A-B/V1/merchant34及人工验收均未由此次merge完成。UI四旧pin和原8/12装配门/开放项、全部原停止NOT RUN保持；未提交或推送、未执行第三方/资金/Docker/PHP/SQL/HTTP/目标，不改其它W/Val/Host/Run或Root canonical C/D。最终Source冻结后停写，待Root采用才可形成此第二阶段标准merge提交。


UI开放项后续只读证据（Root/media当前时点，保前述未核段历史）：已从de5真实来源核实可达链`m4up_source → m4up_prerequisites`，后者在`ms4-ui-prepare.php:256–257`先强制同一formal为原8卷，258再真实调用`m4fn_prerequisites(m4up_old_view(source))`；old_view:229–237只投影环境与前提hash，并不改context/formal。当前`m4fn_prerequisites`又在`ms4-fulfillment-normal.php:248`调用`m4c_entry_ms5_history`，该entry:287–297要求同一context.initial_volumes为精确9卷并含source-tree，306–333还要求initializer完整source_tree copy。这已是实际可达上游资格矛盾，超出前段仅见不同用途数字时的未核假设；有限import primitive兼容不能消除此完整资格问题。

本阶段仅如实保留这一后续Source事实：当前该UI资格需后继限定适配，不能仅改四hash或把8改9，必须在确认原S/B实际必经链后先设计完整formal/context/Source卷、copy/freeze/mount/history/consumer闭包并核全部原契约。Root/media继续追实际S/B依赖和必要最小profile范围；本cloud阶段不改UI代码、旧pins或原8/12门，不扩旧未采用UI17要求，也不缩减原merchant34/25captures/9screens/23facts等验收。统一Source合并提交不等于UI ready，实际UI/原全链仍未通过。


#### withdrawal_qr 私有材料接线设计（2026-10-25，Source-only，待Root与非作者采用）

本节只细化原T5/T6的CU_WITHDRAWAL/W06，不另开需求或验收清单。原规格00 §9/Q5的首版不开放自动结算与提现不变；本项不新增商家提现、付款/审核/转账能力，不改金额、手续费、佣金、状态或通知规则，不执行真实资金及外发。chat使用platform29、default public_upload_compat及外部旧client发布门是另外的未完子项，本节只列依赖，不暗扩其实现。已通过局部正常链的证照/旧退款与已接T4不重做、不据本项关闭整个T5或整体验收。

**工作来源与阶段。** 已核原证照/退款W为各自冻结历史来源，不承接集成后的新材料工作；目标目录与分支原先均不存在，已原生创建独立 `H:/天俪商城/worktrees/remaining-private-assets` / `feature/remaining-private-assets-20261025`，精确起点 `3a5106fd0df6580ef527a6744b48068f5994fdbb`，初始clean。原集成W的UI/B文档由另一作者独占，本W不覆盖它，后继合入须保全两份各自增量。无.codegraph，不初始化；已读spec-workflow、git-auto-sync及原规约/规格/上传域与现调用源，sync实际返回project_not_enabled，不enroll。当前只追加本record；业务/测试/Host代码、PHP/Node候选、Docker/SQL/HTTP/Run、资金、提交推送、C-D均NOT RUN。以后真实clean HEAD/Val/Host/PINS/Run及结果UNBOUND，不能将3a51提前写成未来实现已测身份。

**已核事实及需求。** 原 `user_cash/index.vue:251–259` 两种收款码均经 `upload/image`，将返回url存qrcodeUrlW/Z；365/373再放原cash的qrcode_url。API `UserExtractController:51–85`以真实request.uid调用cash，qrcode_url默认空；weixin/alipay原来都不强制有图，零图继续合法。`UserExtractServices:510–617`保原资格/金额/冻结佣金检查，586/595收该字段，603原事务604保存真实UserExtract model，607–615扣brokerage并记原佣金流水，616返回这个真实model。原DDL的user_extract为25列、qrcode_url是varchar(255)、id/uid为unsigned int；不是另造收款码业务表。

财务原 `GET adminapi/finance/extract → UserExtract::index → UserExtractServices::index/getUserExtractList`返回完整记录，`finance/userExtract/index.vue:130–138`直接img读qrcode_url。本人现只有本页上传后preview；bank()仅银行/金额/配置，内部getExtract()不是本人详情API，getUserExtract()只是求和。不能虚构本人提现详情/列表、把bank当确认读取，或凭资产BOUND/first extract_id推断本次cash已成功。

安装SQL另有system_route的GET finance/extract接口文档，但其system_menus没有该auth_type=2读叶：39只是页面菜单，2967–2970是拒绝/通过/修改/修改表单。原finance路由有AdminAuthToken+AdminCheckRole；新材料reader须重核原GET finance/extract的当前真实登记与grant。最高管理员保原level0范围；当前fresh种子受限管理员仍默认拒绝，不借PUT审核/编辑或menu39授读，不加新菜单/seed/白名单。未查询真实实例，不能把实际部署已登记/未登记当已知事实。

**D1：purpose、私有对象与34列。** 复用现customer_asset完整34列及AssetSchema既有withdrawal_qr/extract_id定义；没有mer_id，不引selfMer、MerchantContext或商家owner。上传真实当前Request UID、会话fingerprint、完整有效user及原weixin/alipay类型资格，从原认证/配置取，不信body uid/mer/purpose，不触发现金申请或任何资金动作。上传类型先只限原提案的weixin/alipay，不扩大bank/balance的图片职责；不新增强制二维码规则。先沿真实writer Connection/PDO锁user并核同一身份，再检查完整实际file、MIME/完整解码/大小/摘要与当前存储资格后才创建对象，保原100次/86400秒限频及其实际Redis副作用。

新对象固定withdrawal_qr私有namespace，local root必须真实位于public外、同预期Linux owner，目录0700/文件0600、exclusive新建/fsync/同FD与path identity/无link/全bytes EOF读回；图片资格复用CustomerAssetStorage当前真实qualify能力，不扩大支持集。配置拟只增加 `marketplace.customer_withdrawal_qr.private_root`，默认空，类似现licence/refund的实际root前提，不另加默认false业务开关。根缺失或物理能力未知时新私有上传返回未完成，不回退upload/image或公开云。不得通过复用证照/退款的实际root、purpose、票据scope冒认本材料；可复用已审物理/会话/六键接缝，不改原消费者语义。云私有ACL/期限/原图资格本项不实现、不借公开云冒签。

完整34列中uid/purpose真实，created_by_uid恰一项；extract_id初始NULL，所有无关root/item/refund/form/agent/source父列均NULL。STAGED原目的性行与文件保留；不在本项增加新定时器、自动清除或资金幂等规则，expires_at按既有允许NULL合同固定，不按ticket到期删材料；BOUND更不按新上传期限清理。收到真实asset自增ID后使用新固定opaque locator `mp-withdrawal:v1:<asset_id>`（拟议新合同，须严格native ID范围），原qrcode_url仍是string且长度适合原255列。任何mp-withdrawal保留前缀但格式不符均拒绝，不降格为旧URL；数据库只存永久locator，不存signed URL、票据、Blob URL或实际磁盘路径。

**D2：原cash事务内绑定与未知提交。** CustomerAssetServices保原提案uploadWithdrawalQr/bindWithdrawalQr薄门，具体窄服务/DAO只接withdrawal_qr，不放宽现avatar/review/licence/refund叶。新引用的cash由原controller交真实当前Request及其verified identity进入材料接缝；旧零图/URL分支保持原调用、返回/异常与金融算法。现BaseServices::transaction委托Db::transaction，真实PDOConnection callback传同一Connection后才commit；私有绑定只在原603–617 callback里接实际ConnectionInterface，核它与默认Db::connect同对象、同PDO、depth=1、inTransaction、实际database/CURRENT_USER/CONNECTION_ID一致，不能另开资产事务/savepoint、提前commit或另接observer PDO。

在原save/扣佣金/income的全部原DML完成后、原callback return之前，用$res1的真实保存PK在同PDO锁/重选完整user_extract25列及真实native user；asset ID只作locator，按真实uid+withdrawal_qr锁完整34列及实际物理对象。第一次STAGED只能绑定本次真实extract行（uid及qrcode_url精确对应），改state/extract_id/bound_at并读回完整行；同UID后续合法cash再次保存同引用时，验证这次真实extract的uid/qrcode_url并保持首次extract_id/bound_at原字节，不用新PK覆盖，也不能要求首次ID等于每次新PK。金额、balance、费率、订单号、原status、流水link_id等一律来自原writer，不从AFTER拼期待或MAX+1猜ID。资产复用不是cash重放幂等。

原事务外619后的Channel WITHDRAW、adminNewPush、Notice、CustomEvent顺序与原异常责任完整保留；新增图片提交后观察放在全部原责任之后，不让图片readback在SQL已经提交后截断原hook。观察同原Connection/PDO、outside transaction/autocommit、真实extract/asset完整行及private全bytes，并按独立列明的原hook合法delta复核，不把取得PDO commit ACK作为已有事实。vendor commit布尔返回并未传上层；不能改ORM、set autocommit或开补偿写求通过。

沿六键complete/phase/physical_changed/physical_complete/database_committed/cleanup_complete真实收据。仅原callback完整结束且本次有真实私有绑定结果时，transaction外commit异常才转材料unknown（database_committed=null）；原callback未完的业务错误及无私有引用分支保原异常，不统一吞错。cash不创建新物理文件，未知时不得删已上传对象/资产/旧引用或自动再POST，亦不执行原本只会在transaction成功返回后才执行的hook来伪造成功。controller只对新增材料OperationException窄返回503/no-store/原六键，不改global handler。成功返回仍保原cash的status/msg与流程，不把本项扩成提现详情或资金回执API。

**D3：旧URL、新写与合法读取分开。** 过渡语义与本记录原证照/旧退款兼容责任一致：新首方页面选图只走具名private，失败保草稿/原始File、ref与安全收据，无公开fallback；零图仍传原空字符串。外部旧client按原DTO提交的合法URL型新写继续原可达分支，原字符串保持，不为它造asset/uid、下载转存、改object或声称已私有。已存旧URL历史只由原真实extract/财务权限读取，不新增迁移、改写、删除或公开下载代理。旧URL新写与旧URL历史只读须在报告分别计数；它们仍使default public_upload_compat/T5最终发布门未闭合，不能把仅新增private上传当作全部客户端完成。以后限制旧URL新写，须先有原仓库内/真实外部版本发布与历史兼容实证并另在原record记录，不在本项关旧功能求通过。

拟议仅新增材料读叶（不是提现详情）：本人对自己STAGED图片的上传preview及已绑定图片，财务对当前真实extract行的图片。本人BOUND读取从asset首次真实extract引用重核；财务读取从本次实际列表行id/uid/qrcode_url派生，合法同UID复用时不以首次ID强等于当前ID。每次重新取真实user/asset/extract和当前session/权限，不把URL/assetid当授权。保旧name/url/attachment_ref上传DTO；新前端用attachment_ref提交qrcode_url，url只是需认证的content位置，不把临时preview值回写。

具体拟议闭集：`POST api/extract/files/<extractType>`；本人 `POST api/extract/files/<asset_id>/read-ticket`、`GET .../content`；财务 `POST adminapi/finance/extract/<extract_id>/qr/<asset_id>/read-ticket`、`GET .../content`。现extract/bank/cash原路由及财务原list/edit/adopt/refuse不变。新财务读组用真实AdminAuthToken及一个仅这些fixed routes的WithdrawalQrReadPermission适配器，沿原GET finance/extract当前真实已启用角色登记重核权限，保原最高管理员，绝不改Request伪装旧route，不沿用adminInfo缓存roles或添加菜单登记。本人读组沿真实api认证，商家/客服无此权限。

采用与refund相同的Redis TIME/Lua有限票据模式，但独立固定withdrawal_qr scope/缓存前缀和 `X-Withdrawal-Qr-Read-Ticket` header，300000ms期限；不把withdrawal加进原退款/售后票据闭集，licence当前是直接认证binary而不是已有ticket类。票据严绑reader_kind/app/actor/session fingerprint/uid/asset/content SHA/purpose及实际extract引用（本人STAGED为NULL、BOUND为真实原引用；财务为请求行），二次重核后原图binary/no-store。private read只返回完整已验证内容/MIME，保实际文件/FD/全bytes与票据peek/consume责任，不传URL query bearer。cookie.header仅追加这一固定允许header，原origin/credentials/CORS权限不放宽；跨域正常与原图读取未测不得签通过。

UI保留原两种可选二维码、原金额输入与提交反馈：新增helper将永久ref、原始File、临时Blob preview及完整pending body/阶段分开，type/UID/session/路由context不混用，旧URL展示并存，票据/Blob及时释放但不把unknown改成失败。busy在首await前锁住，unknowncash保草稿/只读安全收据，不自动重复原cash、不提示可安全重试；读取图片或资产已BOUND不等于本次cash成功，不添加虚假的“读取已保存提现结果”。当前没有本人业务确认reader，跨会话未知仍须按原财务业务核实，不能承诺资金强幂等。正常明确HTTP成功保持原页面更新/跳转。状态是只读反馈，不画成按钮；真实动作另列，控件边界/双轴文字居中/长文与loading须按control-quality实际runtime核，Source/build不代视觉验收。

**最小Files（本步实际只改本record；待采用后精确限定实现）。**

| 路径 | 必要职责 |
|---|---|
| 本 `docs/multi-merchant/records/ms2/upload-asset-domains.md` | 原唯一设计/任务/实际证据，旧前缀保全。 |
| `crmeb/app/services/marketplace/assets/CustomerAssetServices.php` | 原拟议withdrawal具名薄门，不改已接其它用途。 |
| 新 `crmeb/app/services/marketplace/assets/CustomerWithdrawalQrAssetServices.php`、`CustomerWithdrawalQrPrivateStorage.php`、`CustomerWithdrawalQrReadTickets.php`、`CustomerWithdrawalQrPlatformAccessServices.php` | 同PDO上传/绑定/提交观察、专用非public物理、独立票据/本人及财务read、原真实GET读权限；复用原能力不另造通用owner或事务框架。 |
| 新 `crmeb/app/dao/marketplace/assets/CustomerWithdrawalQrAssetDao.php` | 完整34列/原生类型与锁、first extract及实际新引用重核；AssetSchema表/列/索引/seed均不改。 |
| `crmeb/app/api/controller/v1/user/UserExtractController.php`、`crmeb/app/api/route/v1.php`、`crmeb/app/services/user/UserExtractServices.php` | 原当前Request/UID与材料闭集、原cash事务内实际PK绑定及有限unknown catch；原零图/旧URL/金额/通知保持。 |
| `crmeb/app/adminapi/controller/v1/finance/UserExtract.php`、`crmeb/app/adminapi/route/finance.php`、新 `crmeb/app/adminapi/middleware/WithdrawalQrReadPermissionMiddleware.php` | 已有财务列表实际行到私有材料读取，仅复用原真实GET权限，不新菜单/白名单/金融操作。 |
| `crmeb/config/marketplace.php`、`crmeb/config/cookie.php` | 仅专用root默认空与固定ticket允许header；保其他既有默认开关、origin/credentials。 |
| 新 `template/uni-app/api/withdrawal-qr-materials.js`、`template/uni-app/pages/users/user_cash/index.vue` | 私有上传/认证binary preview、永久ref提交/原可选图/零图、完整pending与unknown不自动POST；原cash DTO和旧正常分支保持。 |
| 新 `template/admin/src/api/withdrawal-qr-materials.js`、`template/admin/src/pages/finance/userExtract/index.vue` | 原已授权列表行图片的票据/binary与旧URL并显、Blob/context释放，不新增审核或详情业务。 |
| 新 `help/audit-regressions/isolated/ms4-withdrawal-qr-private-normal.php`、`lib/withdrawal-qr-normal-profile.php`、`compose.ms4-withdrawal-qr-private.yml`及必要 `lib/harness.php` dispatcher | 复用既有正常ISO完整资格/资源模式做原材料正向链，不造新测试框架、不改原default或其它profile门。实际normal/Host设计与代码须另经Root串行采用，不能以Files行当本轮执行授权。 |

外部Host只有后续真实源码冻结后才按既有licence/refund成熟caller派生同源专用新包并绑定真实W/独立Val/Run；本步不创建它、不抄旧PINS/Runtime结果，不复用旧成功Run。若实际实现证明其它原调用点必要，先将精确事实/Files/保全验收追加本record再动，不广改公共上传或聊天。默认公共兼容、旧确认表单、platform29聊天与真实历史转存仍未完成，另按各自原合同推进。

本子项任务与验收（Source/实际Runtime/浏览器分开，不另闭合原T1–T8）：

- [x] 核3a51真实source、原默认空图/DTO、25列extract与34列asset、当前Request UID、唯一cash callback/PDO及财务原读权限缺登记事实；独立新W/sync检查完成。
- [x] 在原record先记本有限需求/设计/Files/旧URL过渡与验收；当前仅文档，不写业务/测试代码、不运行候选或目标。
- [ ] Root与非作者全文Source审并采用；未采用前不开始QR代码。
- [ ] 在所列真实接口/同PDO/私有根/字段/purpose范围实现并冻结Source，完整正逆diff及原零图、旧URL、金额/费率/流水/通知/其余用途raw合同保全；独立Source审和最小当前PHP/前端静态检查分别记结果。
- [ ] Root在新clean Source/独立Val/专用ISO实际SQL/Redis/private/files正向链验证：原允许weixin/alipay可选零图、合法旧URL新写与历史读取、新真实私有上传→本人STAGED预览→cash首次完整34绑定→同UID后续真实cash复用不改首次ID→本人BOUND图片/真实最高管理员财务列表原图读取，全部完整原bytes/MIME/引用/DTO保持。fresh种子不存在受限读grant，不制造该正向演员权限或运行新负向样本。
- [ ] 独立before→expected delta→after核全数据库所有旧行/原生DDL/真实next counter、extract25/asset34、user扣佣金与income原link_id/全部原hook允许delta、完整Redis值/类型/绝对期和原限频/票据/Snowflake实际时间窗、public与private目录/对象/log/原生权限身份；期待来自原Source/实际before和真实返回ID，不从after生成、MAX+1、prefix豁免或删记录凑通过。新材料upload和read不发生资金动作；cash仅专用合成正向原DML，不能冒真实支付/提现通道验收。
- [ ] 原Channel/Notice/Custom等hook不删/不stub；准备先从真实配置/receiver/调用源证明不会外发，只在新专用实例显式采用已审必要测试配置并核原完整站内副作用，缺此前提则该normal保持NOT RUN，不靠断网、伪造号码或关hook通过。真实金融/外部短信微信及其它渠道均NOT RUN。
- [ ] Root串行真实普通TERM/同代自然Exited0/PID0/only-own/nonforce退役，再独立六fresh单label raw双流全空及全native/PID/wait/双流读回；UI H5/MP/APP及财务实际runtime、代表尺寸/状态/原图预览与跨域分别验收，当前无build/浏览器/设备结果。
- [ ] 零图/旧URL兼容、旧client调用与真实历史/发布条件继续记录，不把局部私有链通过写成default公共兼容/T5/商户提现开放或整体验收完成；停止negative/fault/concurrency/recovery/K继续NOT RUN，不新增恢复/批量迁移执行。

Root非作者全文采用本withdrawal_qr限定设计：98ac2e完整核新增17959B，1df7c3实读原cash/两个controller/34列无mer_id，27d6a0实核BaseServices原Db::transaction与vendor原callback实际Connection及commit返回未上抛；c15a66 enrollment仍project_not_enabled。补明必要调用者边界：只有新增私有码cash分支，一经真实POST派发，对网络/5xx/无可证未提交收据的原hook异常等都须保持未确认状态和安全草稿，不把旧异常擅当已回滚或安全可重发；无新增本人业务确认API，BOUND也不证明本次cash成功。保原零图/旧URL金融分支与异常/全部hook，材料读写不等于开放提现；不会持久化登录票据或向日志/Git写私有财务请求。实现与Runtime仍分开，真实外部/资金及停止样本NOT RUN。现在仅允许本节Files范围Source实现，Root实际隔离验证另按真实源码冻结串行安排。


##### withdrawal_qr 第一批生产 Source 实施交接（2026-10-25，尚未运行）

在已采用 c46516af（父 3a5106fd）的独立 remaining-private-assets W 内，按上述18个生产 Files 写具名 private 上传、完整34列专用DAO、独立 root/namespace、独立300000ms票据与固定 header、本人上传 preview/BOUND 图片及原财务列表材料读取；配置只加默认空 private_root，不增加业务开关。保 name/url/attachment_ref 原DTO三字段；原 qrcode_url 只写永久 mp-withdrawal:v1 引用，临时 Blob/票据不入库。原 GET finance/extract 未登记受限权限的事实保留，新读叶按当前真实原 GET grant/最高管理员判断，不加菜单/seed，不借 PUT 审核权限。

cash 原 save/扣佣/income 源片段及其后 Channel/adminNewPush/Notice/Custom 完整原责任块逐字保留；新私有分支只在同实际 callback/PDO 前核真实 UID/session/user、DML末尾用实际保存PK绑定，首次 extract_id/bound_at 保留且同 UID 后续合法申请允许复用。原 callback 完成后的 commit 异常给六键 unknown；新增提交观察在全部原 hook 之后。原零图/URL调用与金融算法保留。Uni 新 private POST 前保 memory-only 完整 body/ref/File 草稿，所有无确证的结果保 unknown 并阻断自动再次 cash POST；不从 BOUND、首次ID或图片可读推定本次提现成功，没有虚构本人提现详情确认入口。原明确成功仍走原更新/跳转；旧URL仍按原列表并显。临时预览在隐藏/身份/route失效时释放，pending不由隐藏/abort改成失败或安全重试。

本批实际只有18生产路径；本段是第19个dirty路径。作者只做 Python 字节/词法括号自核和原生 Git 检查：14个 PHP 的词法括号平衡（不是 PHP parser/-l）、原 financial/hook 片段 raw 保全、实际18路径/index空，以及 diff --check。尚未 PHP/Node parse/build、候选执行、Docker/SQL/HTTP/真实资金/外发或浏览器；不能据此标 Source 独审0或功能通过。代码暂冻结交 Root 非作者审，之后才继续既定专用 normal/profile/overlay 源码；现没有 Host/Run/Val、测试结果或未来身份。

- [x] 原采用设计内的生产 Source 第一批实现；未经独立复核/实际验证，不作完成验收。
- [ ] Root/非作者完整核现金原责任、真实34/25行/PDO、票据/财务scope、未知草稿及旧DTO兼容。
- [ ] 依采用设计补既有范式的专用普通验证 Source，再经独审/真实clean HEAD派生Host。
- [ ] 原完整正向隔离/全库DDL/Redis/files副作用、UI/CORS/设备与发布gate仍待实际证据；外部通道和全部停止样本保持NOT RUN。


##### withdrawal_qr Root非作者生产源码检查点（2026-10-25）

Root已全文核18个生产路径的实际差量、6份新PHP与2份helper完整内容，并追原cash事务/25列extract/34列asset/财务原GET权限/通知与认证上下游。原金融save、扣佣金和income区段、其后Channel/adminNewPush/Notice/Custom完整原责任块均以c465原件逐字核同；没有改金额、费率、资金规则或开放商户提现。首次材料绑定和同UID后续复用保首extract_id/bound_at；每次财务读取仍按当前真实提现行核UID与永久ref。private cash一经派发，前端把无法确证的结果保留为memory-only pending，不自动再次POST；零图/旧URL继续原分支，隐藏/abort/BOUND不能作已回滚或本次成功的证明。

Root实际纯检查：withdrawal-qr-production-php82-r1以固定本地PHP8.2.33镜像执行14文件-l，5个原生进程/10份完整双流全0，容器自然Exited0/PID0/restart_count0，14只读文件挂载、network none，随后仅原CID非force删除；未include或执行App。withdrawal-qr-production-frontend-r1用现有Babel7.27.5与Vue2.7.16 compiler完整解析4份前端module、编译两份Vue模板，原Node PID15820自然0、双流完整且stderr空；执行器为Node24.18.0，证据仅为解析/模板，不宣称满足项目Node<23完整构建资格。两项检查前后源字节相同，完整回读见外部withdrawal-qr-production-root-review-r1/RESULT.json。

此为生产Source本地里程碑：18生产路径和本record共19路径，专用normal/profile/compose/harness仍由作者独立实现，未纳入本检查点。没有新Source运行资格、ISO业务、SQL/Redis/HTTP、实际H5/MP/APP/财务页面或设备、第三方渠道/真实资金/外发的通过记录；原完整正向链、全库DDL/Redis/files与通知副作用及UI/发布gate仍待。默认公共兼容、平台聊天及旧客户端发布边界仍开放；停止的负向/故障/并发/恢复/K样本继续NOT RUN。

- [x] Root非作者生产Source审查及原金融/通知raw区段保全核对。
- [x] 当前14 PHP实际-l与4前端module/2Vue模板离线检查。
- [ ] 专用普通验证Source、同clean HEAD真实ISO全链及普通退役/独立zero。
- [ ] 原三端/财务页面实际运行验收及其余T5/发布条件。


##### withdrawal_qr 专用普通验证Source采用与本地检查点（2026-10-25）

在生产43371dd1基础上，四件专用normal/profile/compose/harness Source已冻结；Root全文读35函数、固定profile/overlay与完整harness差量，并由非作者/root/refund_memory_repair_source/b_ui_profile_source独立逐项追真实cash/佣金13列link_id、Notice handler/SysMsg全10列/原DAO完整receiver、WeWork空URL真实前提、adminNewPush/Custom/Channel、财务原GET权限、Request/Auth、Redis票据与私有fullFD/EOF/bytes/原SQL25-13-10列，未发现新的确定缺陷，Source0。同行结论只签源码阅读，未运行候选、目标、资金、GUI或Host；保存外部withdrawal-qr-normal-root-adoption-r1/PEER-SOURCE-REVIEW.json，不将其写成运行回执。

采用的正常范围保持七次独立合成cash：原weixin/alipay零图各一次、旧URL各一次、新weixin私有首次、同UID同BOUND引用的新alipay申请保首次ID/time且按当前新财务行读取、另一alipay私有首次；两真实multipart上传，本人STAGED/BOUND及最高管理员原列表/票据/完整原图。既有合成user_101起始100.00只改必要brokerage字段，原真实min=1、fee=0、两手动提现方式、queue0/原通知receiver与模板均不修改；每次仍执行全部原hook并核完整站内信。准备前实际外发条件不满足即停，不能禁hook、改配置、靠断网或伪造权限凑通过。原80归属/真实历史、商户提现开放、真实通道/支付/资金和外部通知均不在本合成运行范围。

Root当前三个PHP真实8.2.33纯-l与完整5native/10双流1760B回读全0，natural helper原CID退出0/PID0/restart_count0、network none/3RO后非force移除，当前文件完整字节不变；不是include或执行业务。四件Source及本record合计五路径本地提交，保生产18件原完整字节。后续只能在真实clean Source与独立Val均已绑定同一新HEAD后派生新的专用Host/PINS，Root再独审实际资格与串行运行；未来HEAD、Val、Run、CID、volume/engine身份、native PID、setup/normal/ordinaryclose/六fresh raw zero结果不能预填或借旧成功。

- [x] 四normal Source完整独立审查并采用，原生产/数据/权限/通知/停止样本边界保全。
- [x] 当前三PHP纯语法检查及完整原生双流回读。
- [ ] 真正新clean Source/独立Val与完整Tracked namespace资格、有限Host/安装引导Source及PINS独审。
- [ ] 新ISO正向业务、所有原完整oracle、单次TERM/同代普通清退和独立六fresh原始空查询。
- [ ] H5/MP/APP/财务页面、设备/CORS/外部与旧客户端发布责任；默认兼容/聊天及整个T5仍开放。
