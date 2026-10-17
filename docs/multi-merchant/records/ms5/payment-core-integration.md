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

## 完整 MS4 原入口接入 MS5 前置（实施前设计）

2026-10-17，设计读取的 Main 为 `5dd8616e6bbc36685162f7184516bf52fa143a54`。本段只收口上方既有未完成项中的原完整内部普通回归；不新增第二执行清单，不改AUX当前冻结来源或R4实例。后继必须实际执行原 `ms4-order-normal.php::m4o_main()` 全链，不能用MS5 normal的两笔付款或其结果代替五个原未付根、保存读取、planner与取消回归。顾客12HTTP、H5、商家34/25/9、真实金融及发布仍是各自未验项，不由本段签收。

**来源与真实前置。** 接线点为原 `m4o_source()`、`m4o_main()` 首尾前置调用及末尾再次source/included核。旧共享 `m4o_prerequisites():int` 字节和默认调用合同保持，另增独立 `m4o_ms5_prerequisites(array $source):array` 供新main首尾核验；替换main调用点，不替换商家prepare仍无参调用的旧函数本体。新后继profile以新实际原生角色、原入口固定argv启动原完整本体，保留同Run/source/freeze、实际created/CID/image/role、双RO源码、DML app-secrets、内网/原卷、真实testing与Redis环境及默认PDO门禁；不把新MS5结果伪装成旧六child/MS4四CLI收据。原MS4前置职责保留，后继严格核九个真实initializer child和MS5 check→apply-structure→check→apply-structure四个真实CLI，以及各阶段的来源、原生终态、私有配置清理与实际self商家。原四个M4O_ROOTS/十项M4O_FILES和全部实际included闭集继续完整核；迁移CLI/说明及其前置descriptor另按实际来源绑定，不能仅因应用四目录已含支付类就漏掉迁移来源。原迁移formal manifest须在其原CID正常移除前create-only完整复制并读回，不能从后来的normal manifest重建。旧冻结profile/收据保持其原合同，只派生新的同源完整回归profile。

**原业务链与Main真实准入前提。** 顺序保留原direct DAO六边界同PDO、完整独立读回/同请求重放、post-commit handler，随后persistent、temporary、level与paid会员计价、saved_reads、fulfillment_plans、ordinary_cancel、merchant_saved_reads及final_source；`membership_creations.paid`仅指会员种类，该订单仍须未付款。商品/SKU/金额/表单/发票、购物车消费/保留、1800/0保存期限、旧权益计价、真实owner/商家及所有原正常业务断言不删除。Main生产Policy要求marketplace.enabled与trade.enabled同时为true，fresh CreateServices先核此guard；AUX旧CreateServices仅核active，故即使AUX普通链通过，也不等于Main完整验收。后继新角色必须显式原生声明PHP_MARKETPLACE_TRADE_ENABLED=true，并同时核实际marketplace.enabled=true的原生声明/配置来源，纳入source/environment/profile及原生筛选读回，从真实配置加载而非只翻App内存。原 `m4o_app()`、service收尾、membership收尾、main设置后及final共五处false断言须有限适配为已核role对应的真实配置期望；旧调用默认false/旧角色合同保持，新角色期望true，不能仍以GATE_CLOSED名称宣称关闭。期望值只来自已核role与真实environment，不收请求或任意布尔授权标记；原main内存赋值不能替代准入证据。此外，原完整本体实际调用的lib/ms4-saved-order-normal.php末尾（当前238行）、lib/ms4-cancel-normal.php环境/收尾（288/352行）、lib/ms4-merchant-read-normal.php收尾（267行）还有四处同类false期望，须同样有限接入已核role期望；它们已在原十项M4O_FILES内，不能仅改主体五处后漏掉这些真实消费者。只改变新角色的配置前提比较与相应描述，不删除保存读取/取消/商家读回的原业务断言。此为Main真实producer调用前提修复，明确不是business全字节不变，也不改产品guard、全局allowlist或HTTP入口；无HTTP/端口/假身份，原未付/金额/重放/keeper/planner责任保持。

**五未付、出生与planner的精确证据。** `m4o_verify()`、`m4o_verify_service()` 及两会员proof均补同根 `payment_birth`：独立native PDO读registry完整11列：order_id/uid/mer_id等于实际根，payment_contract_version=1，born_at=原根add_time，attempt_generation为规范U64字符串'0'，winner_attempt_id=NULL，fulfillment_hold=0，hold_reason/hold_attempt_id/hold_at均NULL，并核真实零attempt；不调用生产Evidence构造expected，不插registry/attempt夹具、不backfill或把不存在登记当known-none。M4O_FACT_TABLES仅把原23表追加两支付表成25表，registry按order_id、attempt按id；原事实摘要继续native serialize，U64/JSON/NULL/BINARY32不全行转字符串。`m4o_fulfillment_plan_normal()`只能使用原direct/persistent/temporary/level/paid五份proof的五个不同实际PK，五根仍paid=0，分别核其出生；原完整根/items/reserves、金额/区间/表单plan/projection与前后全25表保持原样，不改成另外两笔已付款样本。

**取消G1与G0分开。** `lib/ms4-cancel-normal.php::m4cn_attempt()` 改用同App真实账户与MarketplacePaymentServices::createForCustomer的原九键DTO，只从实际保存create_result取得原descriptor；原23业务表严格保持，只允许目标registry G0→1及一条完整18列attempt增量。取消仍核原五业务表的精确delta、当前库存加回本单reserve数量及keeper保持，不回填历史stock；两支付表仅允许目标attempt实际query→close→query的保存evidence增量，完整G/intent/ref/非目标行保持。另以真实短事务取得并独立核完整closed proof、三事实与first_closed，且无success/application/hold；正常重放全25表和原实际付款结果不变。原保存0期限订单仍not_due且无写。

**自然90秒与原tiny。** 原顾客取消之后、原tiny之前，增加唯一固定参数 `('0.025',90)` 的真实新普通pending订单：先核出生G0并在真实保存期限前由服务准入为G1，再沿同一原生进程分段等待真实wallclock到原ledger.cancel_at，期限/时间不改、不续期、不另起worker或重启。实际保存期限应等于原add_time+90，实际超时关闭/原handler、system/NULL、完整proof/库存/keeper/重放分别读回，新增独立 `pending_expiry` 结果。完成后对本Run实际配置作原值CAS为0、读回并经真实cache getter刷新，再按原tiny producer顺序创建正亚秒截秒0订单。tiny保留cancel_at==add_time、创建后商家停接、原system/NULL及既有orderCancel handler；不再对已到期tiny发放attempt，须核真实G0/零attempt/空proof且两支付表无写。原 `expiry` 结果仍指tiny，不被pending_expiry替换。

**结果发布与后续宿主闭环。** publisher接缝为 `m4o_main()` 的evidence汇总/final_source与 `m4o_new()`：必须保留原全部phase、proof和失败，不用两笔付款结果拼接成功；只有上述完整链、末次全来源及实际included核均成功后才发布完整内部普通回归结果。新结果分别声明本体entry_return_code/termination和HOST_NATIVE_REQUIRED的native_exit=NULL，宿主再从原同一句柄取得真实terminal/exit0；NULL不能当0。完整native行/二进制proof不直接交m4o_json；内存逐列核验，需封存时用有界serialize/base64与完整hash、严格无类读回，公开结果只保留必要安全字段/数量/摘要。后继host的create/bind/start/observe/finish、环境原生筛选、完整source freeze、normal结果及新close/zero须精确绑定同一原入口/实际CID和九+四前置。observe仍running时继续观察同进程，不以超时重建；close仅在完整普通结果及真实normal终态/授权helper均核全后，沿原same-handle stop -1、原三CID/单网/六卷非force移除及独立六raw查询/global消费者责任。当前只记录设计，尚未实施/解析/运行；现有检查勾选和全部未验项保持，用户已停止的拒绝/安全/冲突/并发/故障/恢复样本不恢复。

## 已取得的隔离内部证据与 Main 待验范围

2026-10-17，AUX clean ca41929df3b89e2d8207fd4cc1db5a4125c1c994 的新Run202610170434553aa79a已实际通过原9→4与两笔persistent/temporary真实producer内部测试付款create→内部complete→query保存/跨秒重放；原结果2603985B/4c5acecd20209d7bb2327c8ebb2ea3acc3de6b965689af154dea204146bc0dd4为NORMAL_RETURN/entry0/nativeNULL，原同CID真实terminal0。独立只读观察SESSION统计设0后GLOBAL/其他模式保持，原生attempt自增1→3/2行、全168表与15Redis按有限delta保持。实例已正常关闭且独立六raw核零0，zero927a8ea89c0dece19612a7958cfca2f89656bb2b0bc4cde6fbe7235e7ce814a1。该证据证明AUX内部普通测试付款，不开放真实金融或签收Main。

Main产品付款源码已整合，但fresh创建还有本记录前述实际trade门禁与原完整回归/共享helper接缝，因此Main业务验收仍未完成。下一步先按第41行后的完整原入口设计落实真实native开关、来源/9+4/publisher、五未付/payment_birth/planner与90秒/tiny取消；再适配原27/H5 34/商家25及实际已付API/DOM。两笔付款结果不能替代这些原范围，原未验项和停止样本边界保持。
