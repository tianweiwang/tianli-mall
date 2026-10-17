# 持久测试付款核心整合到 Main

2026-10-17，实施前 Main 来源为 47d6212af64f3d8ba0f5cae24401145d824b013f；AUX 冻结来源为 6e6a2c97458aee73823933370d2fb2fc47ffac5a。沿既有 MS5 Task 2、AUX physical §20/22/23 的合同做本地 L2 整合；真实渠道、退款、结算、推送与部署仍未授权。本片不修改正在运行或待关闭的 AUX 来源及隔离实例。

## 目标与必要设计

将已经实现的真实测试付款持久层和服务接入 Main 原创建/取消链路。订单在 insertOrder 同事务建立 G0 出生登记，在 markCreated 消费购物车前核完整新出生；付款尝试经真实账户与私有 create context 准入，query 读取实际驱动结果再持久应用，取消先在事务外关闭真实尝试，再携带 proof 两次共同重锁。采用现有证据、锁顺序、完整 U64/BINARY/JSON 合同，不另造付款入口或 paid SQL。

Main 的 restoreConfirmation、markCreated(cancelAt)、savedPaymentDeadline 和 expiredCancellationCandidates/scanExpired 游标都是后继增量，必须保留。OrderDao 仅补支付必要字段与方法，禁止 AUX 整文件覆盖。现有 Account/Resolver、Adapter、AppService factory 与 config 已字节相同，保持不改。全局支付准入与微信判断也保持。

独立源码复核还发现迁移的旧列依赖覆盖缺口：MS5 虽然强制 MS4 前置检查，但两层都没有核新 DAO 必读的 store_order.cart_id/pay_uid。标准安装源分别为 nullable text/utf8mb4/默认NULL，以及非空 signed int/默认0。实施前补充决定：仅在 MS5 producerContracts 增这两项只读声明；文本排序规则继续与实际表继承值比较，不猜固定 collation，不加 DDL、索引或改旧列。AUX 仍冻结，待原实例正常关闭并独立核零后再同步同一修正及记录。

## 文件与执行清单

- [x] 新增 AUX 的 MarketplacePaymentDao、MarketplacePaymentEvidence、MarketplacePaymentServices 三个产品类。
- [x] 整合 TestPaymentDriver 的私有上下文消费与实际测试付款成功状态。
- [x] OrderDao 窄接线：三项 pay_type/pay_time/trade_no；注入 payments；insertOrder birth；markCreated assertFreshBirth；完整 lockPaymentApplicationFacts/paymentMovement；取消 proof 和 payment stage 接线。
- [x] CancelServices 整合持久关闭与 proof，保留 Main scanExpired。
- [x] 纳入现有 marketplace-ms5-payment.php/.md 增量迁移源并补两项只读旧列合同；不在本片执行迁移或更改库。
- [x] 独立源码复核必要闭集和 Main 增量保留，做当前 PHP 8.2.33 语法/适用静态检查。
- [ ] 适配原正常内部、顾客/H5/商家事实与来源消费者，再运行全新隔离实例的真实 producer/payment/取消与 API/DOM 验证。

## 验证与完成边界

源码、语法与本地提交分别记录，不能作为付款业务验收。八源独立有限 SOURCE PASS；OrderDao 保留的 Main savedPaymentDeadline 为完整3582B，去除此段后整文件严格等于冻结 AUX，创建的 cancelAt、候选游标、扫描及 CreateServices.restoreConfirmation 均保留。初次七个 PHP 语法及迁移 help 在固定 PHP 8.2.33、无网络和只读来源中真实0/stderr0（仓库外 artifacts/ms5-payment-core-source-20261017-r1/php-syntax-result.json）；补两项旧列后仅重核已改迁移 PHP 的语法与 help，仍真实0。删除这两条声明后该 PHP 整文件精确等于 AUX；最终迁移 PHP 摘要 `2e58f44b8d2140514d9b45a25d199820c80b90a635e10bd356cfda9a8eb0ccca`，没有 DDL/索引或其他行为差分。

AUX 本轮普通付款实例在 account registration 的完整保持检查失败：168 表、模式、序列及目标行相同，仅 Redis 一个键消失。消失摘要已唯一匹配执行窗口中的裸毫秒键，源 Snowflake resolver 对该类键使用 PSETEX1000；原记录未保存该键的绝对到期点，不能补造历史 TTL。原 FAIL 保留，本轮已正常关闭并独立六查询全零；后继须加入绑定真实 producer 序列键的只读自然到期观测，再在新实例重测，不能因 Main 整合就宣称问题解决。

Main 旧取消 helper 仍直接调用 driver.createPayment，新增门禁后必须按原 PP5 合同适配；27/34/25 事实及来源消费者、原完整 MS4 五未付与自然90秒取消、履约售后、真实金融与发布等原待办全部保留。已停止样本不执行，不补假身份、假响应、paid fixture 或时间。

## 后继消费者的已定位接缝（尚未实施）

顾客共享 lib/ms4-customer-http-normal.php 的 M4CB_TABLES 需25→27，registry按order_id排序、attempt按id，m4cb_saved另核真实完整11列G0/零attempt与唯一允许出生行，保原未付/发票/库存/统计/12HTTP。顾客入口原旧files-bootstrap/MS4前置须接真实9child→4MS5 CLI及实际ENV测试开关；不能只改App内存。

H5 的 lib/ms4-customer-h5-normal.php prepare baseline目前把完整facts交给JSON，非空attempt的BINARY32会失败。应复用既有有界native serialize/base64/摘要方法，同步stage封存读取而不全行字符串化；ms4-customer-h5.php的prepare/计数/环境/审计getter同步27+7=34。原B4未付详情、同一child、唯一App和Final只读责任保持，真实paid producer另设步骤，不藏进只读阶段。

商家 lib/ms4-merchant-browser-normal.php 的facts23→25、registry排序，同时需原ms4-order-normal.php的M4O_FACT_TABLES/m4o_facts，不能漏该相依源。现两facts已经native serialize，不改成JSON。ms4-merchant-browser-prepare.php接新前置，在真实创建后/oracle发布前另加同owner的真实付款case；lib/ms4-merchant-read-normal.php保原三详情与owned-paid-empty，再另加paid oracle。ms4-merchant-http.php同步25表/来源scope，保原34steps/25captures/9screens、空结果和键盘/响应式/金融零增量；实际paid读取另计证明。

该来源映射涉及上述八个既定helper及原M4相依源，不代表已完成实施设计或运行。实际paid owner必须等于真实登录actor，不能拿另一批MS5普通实例UID套用H5身份；使用同App真实账户→Services create→原driver complete→Services query持久保存，再核完整registry/attempt/winner/根四字段。非空二进制与JSON原生类型、U64/NULL必须保持，禁止raw BINARY交给JSON、JSON_BIGINT_AS_STRING或统一转字符串。相应profile、source/freeze、ENV、前置与close/zero须同一来源闭集，原完整验收不缩减。
