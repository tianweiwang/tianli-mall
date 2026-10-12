# MS2 Task1 安装DDL核对草稿

来源只读`crmeb/public/install/crmeb.sql`的CREATE TABLE块；未读取/输出INSERT种子，未连真实库。真实库未核对；无真实GROUP BY数量；无已确认历史映射。AUTO_INCREMENT值不作业务行数证据。

## 三类结构/语义结论

| 类别 | 对象 | 安装结构结论/限制 |
|---|---|
| 同语义可复用（候选，待历史映射） | eb_store_product.mer_id | unsigned int、NOT NULL/default0，注释0仅为总后台创建；可用作未来商品owner列，但旧0/旧正数不等于MS1 merchant ID，默认0须后续enforce移除，不能本轮改表。 |
| 缺列需新增（计划层） | attachment、attachment_category、shipping_templates、store_product_rule | 无mer_id；07要求未来nullable unsigned mer_id+索引→只按确认映射backfill→完整非NULL/非0后enforce。此处不执行DDL。 |
| 语义冲突需映射/保留（待确认） | F21其余8表及system_log.merchant_id | 同名legacy商户标记不证明关联现marketplace merchant；客服/等级字段有signed int，文章/活动/订单多为unsigned。system_log是历史管理员操作日志，不替代新marketplace_audit_log或直接改名。 |
| 通过父对象继承（结构候选） | 商品attr/value/result/description/cate/coupon/virtual/log/relation/reply；运费free/region/no_delivery | 无mer_id，通过product_id+type或temp_id继承；安装DDL未声明实际FK，须先核父Scope、孤儿/跨店引用再谈迁移；不自动额外加列/新表。 |
| 共享字典/独立预设需裁定 | category、product_param/protection/label/label_cate | param/protection/label不含product_id/mer_id；07只明确rule归店与分类平台共享，不能误当商品子表随父回填；额外入口/所有权需审议。 |

## 实际CREATE列/索引摘要

| 表及CREATE行 | 归属/父关联列（原声明） | 原索引（全部；未见mer_id索引/FK不擅加） |
|---|---|
| [eb_article](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:126) | `mer_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商户id'; `product_id` int(10) NOT NULL DEFAULT '0' COMMENT '商品关联id' | PRIMARY KEY (`id`) USING BTREE |
| [eb_store_combination](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28101) | `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商品id'; `mer_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商户id'; `temp_id` int(11) NOT NULL DEFAULT '0' COMMENT '运费模板ID' | PRIMARY KEY (`id`) USING BTREE |
| [eb_store_integral_order](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28301) | `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品id'; `mer_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商户ID' | PRIMARY KEY (`id`) USING BTREE; UNIQUE KEY `order_id_2` (`order_id`,`uid`) USING BTREE; KEY `uid` (`uid`) USING BTREE; KEY `add_time` (`add_time`) USING BTREE; KEY `status` (`status`) USING BTREE; KEY `is_del` (`is_del`) USING BTREE |
| [eb_store_order](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28363) | `pid` int(10) NOT NULL DEFAULT '0' COMMENT '父类订单id'; `unique` char(32) NOT NULL DEFAULT '' COMMENT '唯一id(md5加密)类似id'; `mer_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商户ID' | PRIMARY KEY (`id`) USING BTREE; UNIQUE KEY `order_id_2` (`order_id`,`uid`) USING BTREE; UNIQUE KEY `unique` (`unique`) USING BTREE; KEY `uid` (`uid`) USING BTREE; KEY `add_time` (`add_time`) USING BTREE; KEY `pay_price` (`pay_price`) USING BTREE; KEY `paid` (`paid`) USING BTREE; KEY `pay_time` (`pay_time`) USING BTREE; KEY `pay_type` (`pay_type`) USING BTREE; KEY `status` (`status`) USING BTREE; KEY `is_del` (`is_del`) USING BTREE; KEY `coupon_id` (`coupon_id`) USING BTREE |
| [eb_store_product](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28638) | `mer_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商户Id(0为总后台管理员创建,不为0的时候是商户后台创建)'; `cate_id` varchar(64) NOT NULL DEFAULT '' COMMENT '分类id'; `temp_id` int(11) NOT NULL DEFAULT '1' COMMENT '运费模板ID' | PRIMARY KEY (`id`) USING BTREE; KEY `cate_id` (`cate_id`) USING BTREE; KEY `is_hot` (`is_hot`) USING BTREE; KEY `is_benefit` (`is_benefit`) USING BTREE; KEY `is_best` (`is_best`) USING BTREE; KEY `is_new` (`is_new`) USING BTREE; KEY `toggle_on_sale, is_del` (`is_del`) USING BTREE; KEY `price` (`price`) USING BTREE; KEY `is_show` (`is_show`) USING BTREE; KEY `sort` (`sort`) USING BTREE; KEY `sales` (`sales`) USING BTREE; KEY `add_time` (`add_time`) USING BTREE; KEY `is_postage` (`is_postage`) USING BTREE |
| [eb_store_service](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29282) | `mer_id` int(11) NOT NULL DEFAULT '0' COMMENT '商户id' | PRIMARY KEY (`id`) USING BTREE |
| [eb_store_service_log](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29332) | `mer_id` int(11) NOT NULL DEFAULT '0' COMMENT '商户id'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已读（0：否；1：是；）' | PRIMARY KEY (`id`) USING BTREE |
| [eb_system_user_level](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:51488) | `mer_id` int(11) NOT NULL DEFAULT '0' COMMENT '商户id' | PRIMARY KEY (`id`) USING BTREE |
| [eb_user_level](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:52021) | `mer_id` int(11) NOT NULL DEFAULT '0' COMMENT '商户id' | PRIMARY KEY (`id`) USING BTREE; UNIQUE KEY `id` (`id`) USING BTREE |
| [eb_system_log](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:48637) | `type` varchar(32) NOT NULL DEFAULT '' COMMENT '类型'; `merchant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商户id' | PRIMARY KEY (`id`) USING BTREE; KEY `admin_id` (`admin_id`) USING BTREE; KEY `add_time` (`add_time`) USING BTREE; KEY `type` (`type`) USING BTREE |
| [eb_system_attachment](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29458) | `att_id` int(10) NOT NULL AUTO_INCREMENT COMMENT '自增ID'; `pid` int(10) NOT NULL DEFAULT '0' COMMENT '分类ID0编辑器,1商品图片,2拼团图片,3砍价图片,4秒杀图片,5文章图片,6组合数据图'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '类型，0图片，1视频' | PRIMARY KEY (`att_id`) USING BTREE |
| [eb_system_attachment_category](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29623) | `pid` int(11) NOT NULL DEFAULT '0' COMMENT '父级ID'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '类型，0图片，1视频' | PRIMARY KEY (`id`) USING BTREE; UNIQUE KEY `id` (`id`) USING BTREE |
| [eb_shipping_templates](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:27756) | `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '计费方式' | PRIMARY KEY (`id`) |
| [eb_shipping_templates_free](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:27780) | `temp_id` int(11) NOT NULL DEFAULT '0' COMMENT '模板ID'; `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '计费方式' | PRIMARY KEY (`id`) |
| [eb_shipping_templates_no_delivery](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:27798) | `temp_id` int(10) NOT NULL DEFAULT '0' COMMENT '模板ID' | PRIMARY KEY (`id`) |
| [eb_shipping_templates_region](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:27813) | `temp_id` int(11) NOT NULL DEFAULT '0' COMMENT '模板ID'; `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '计费方式' | PRIMARY KEY (`id`) USING BTREE |
| [eb_store_product_rule](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29177) | 无归属/父关联列 | PRIMARY KEY (`id`) USING BTREE |
| [eb_store_product_attr](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28737) | `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商品ID'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '活动类型 0=商品，1=秒杀，2=砍价，3=拼团' | PRIMARY KEY (`id`); KEY `store_id` (`product_id`) USING BTREE |
| [eb_store_product_attr_value](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28841) | `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商品ID'; `unique` char(8) NOT NULL DEFAULT '' COMMENT '唯一值'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '活动类型 0=商品，1=秒杀，2=砍价，3=拼团' | PRIMARY KEY (`id`); KEY `unique` (`unique`,`suk`) USING BTREE; KEY `store_id` (`product_id`,`suk`) USING BTREE |
| [eb_store_product_attr_result](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28801) | `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商品ID'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '活动类型 0=商品，1=秒杀，2=砍价，3=拼团' | PRIMARY KEY (`id`); KEY `product_id` (`product_id`) USING BTREE |
| [eb_store_product_description](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29003) | `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID'; `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '商品类型' | KEY `product_id` (`product_id`,`type`) |
| [eb_store_product_cate](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28938) | `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品id'; `cate_id` int(11) NOT NULL DEFAULT '0' COMMENT '分类id' | PRIMARY KEY (`id`) USING BTREE |
| [eb_store_product_coupon](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:28980) | `product_id` int(10) NOT NULL DEFAULT '0' COMMENT '商品id' | PRIMARY KEY (`id`) |
| [eb_store_product_virtual](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29198) | `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品id' | PRIMARY KEY (`id`) |
| [eb_store_product_log](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29064) | `type` enum('visit','cart','order','pay','collect','refund') NOT NULL COMMENT '类型'; `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID' | PRIMARY KEY (`id`) |
| [eb_store_product_relation](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29125) | `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '商品ID'; `type` varchar(32) NOT NULL DEFAULT '' COMMENT '类型(收藏(collect）、点赞(like))' | UNIQUE KEY `uid` (`uid`,`product_id`,`type`,`category`) USING BTREE; KEY `type` (`type`) USING BTREE; KEY `category` (`category`) USING BTREE |
| [eb_store_product_reply](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29142) | `unique` char(32) NOT NULL DEFAULT '' COMMENT '唯一id'; `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品id' | PRIMARY KEY (`id`) USING BTREE; UNIQUE KEY `order_id_2` (`oid`,`unique`) USING BTREE; KEY `add_time` (`add_time`) USING BTREE; KEY `parent_id` (`reply_type`) USING BTREE; KEY `is_del` (`is_del`) USING BTREE; KEY `product_score` (`product_score`) USING BTREE; KEY `service_score` (`service_score`) USING BTREE |
| [eb_store_product_param](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29089) | 无归属/父关联列 | PRIMARY KEY (`id`) |
| [eb_store_product_protection](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29106) | 无归属/父关联列 | PRIMARY KEY (`id`) |
| [eb_store_product_label](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29026) | `cate_id` int(11) NOT NULL DEFAULT '0' COMMENT '分类id'; `type` int(11) NOT NULL DEFAULT '0' COMMENT '效果设置0自定义1图片' | PRIMARY KEY (`id`) |
| [eb_store_product_label_cate](H:/天俪商城/tianli-mall--mm/crmeb/public/install/crmeb.sql:29049) | 无归属/父关联列 | PRIMARY KEY (`id`) |

商品root PK为signed mediumint，SKU product_id部分unsigned int，description/cate等signed int；现安装没有父子FK，不能据列名认定range/参照完整性。attr/value/result.type的0/1/2/3表示普通商品/秒杀/砍价/拼团，父实体应按type辨识；description也带type，不能统一join所有product_id到store_product。

实际运费结构仅templates+free+region+no_delivery四CREATE块，城市字段为province_id/city_id整数和uniqid分组；RegionCityDao:43、FreeCityDao:41、NoDeliveryCityDao:41复用上述模型而join SystemCity，不应凭类名新建三张city表。

下一真实核对只在另有L3d授权时做SHOW CREATE与分组数量，不读取业务内容；此草稿只记录安装事实，三类结论尚待SP-MS2-1和MS1验收，marketplace.enabled保持false。
