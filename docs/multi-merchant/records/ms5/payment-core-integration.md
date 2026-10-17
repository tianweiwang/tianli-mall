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

### 实施前补充：新入口角色、最小支持文件和共享期望

2026-10-17，Main clean `fbe4e89912b06ec0e0a57e0d90c53b0c97aaa3bd` 源码盘点确认，最小新增支持文件仅 `help/audit-regressions/isolated/ms5-payment-migration.php`；沿 AUX 现有四子进程结构检查入口迁入，产品 CLI、说明、harness/guard 及其真实依赖均已存在，不复制另一个付款业务本体。原完整订单入口仍是 `ms4-order-normal.php`，新 profile 的唯一业务角色为 `audit-ms4-order-normal-ms5`，与 mysql/redis 构成三角色；旧 frozen profile 和旧商家 prepare 无参合同保持。

原测试环境收据严格四键 `APP_ENV=testing`、`PHP_MARKETPLACE_PAYMENT_TEST_ENABLED=true`、`PHP_MARKETPLACE_ENABLED=true`、`PHP_MARKETPLACE_TRADE_ENABLED=true`；另一个收据严格单键 `PHP_CACHE_DRIVER=redis`。四行原生筛选的 hash 只接受这四个精确行的全部24种排列及原有单/双换行末尾，不能仅核正/反两序、放宽键值或丢掉原 stdout hash。新 `m4o_trade_expected():bool` 只对实际执行本原入口、manifest 中唯一新角色与实际 hostname/CID、同 Run/source 和四项原生环境均匹配的已核路径返回 true；其余原导入调用保持 false。`m4o_app(bool $expectedTrade=false)` 必须核参数等于此实际角色期望，新 main 显式传该期望，所有九处 trade 比较复用同一函数；新路径另核真实加载的 enabled/trade 两配置为 true，main 只设置真实初始化 self ID，不以配置内存赋值代替 enabled 来源。

新 source binding 的前置摘要闭集为原六文件 `driver-terminal.json`、`initializer-completed-terminal.json`、`initializer-created.json`、`initializer-result.json`、`ms5-payment-migration-container-created.json`、`ms5-payment-migration-normal.json`，再追加 `ms5-payment-migration-formal-manifest.json`、`ms5-payment-migration-source-binding.json`，共八个；十源迁移 descriptor 按前述盘点另行逐项双 RO 核验，不并入或替代原四 roots/十 files。新主体验证结果用独立 scope/status 标明 MS5 前置合同，并保留所有原业务 proof 与停止样本边界；结果自身不声明 native0。以上是本轮实施依据，实际实现、语法及完整业务运行分别记录，仍沿09中原唯一执行清单。

## 已取得的隔离内部证据与 Main 待验范围

2026-10-17，AUX clean ca41929df3b89e2d8207fd4cc1db5a4125c1c994 的新Run202610170434553aa79a已实际通过原9→4与两笔persistent/temporary真实producer内部测试付款create→内部complete→query保存/跨秒重放；原结果2603985B/4c5acecd20209d7bb2327c8ebb2ea3acc3de6b965689af154dea204146bc0dd4为NORMAL_RETURN/entry0/nativeNULL，原同CID真实terminal0。独立只读观察SESSION统计设0后GLOBAL/其他模式保持，原生attempt自增1→3/2行、全168表与15Redis按有限delta保持。实例已正常关闭且独立六raw核零0，zero927a8ea89c0dece19612a7958cfca2f89656bb2b0bc4cde6fbe7235e7ce814a1。该证据证明AUX内部普通测试付款，不开放真实金融或签收Main。

Main产品付款源码已整合，但fresh创建还有本记录前述实际trade门禁与原完整回归/共享helper接缝，因此Main业务验收仍未完成。下一步先按第41行后的完整原入口设计落实真实native开关、来源/9+4/publisher、五未付/payment_birth/planner与90秒/tiny取消；再适配原27/H5 34/商家25及实际已付API/DOM。两笔付款结果不能替代这些原范围，原未验项和停止样本边界保持。

## 完整原入口 SOURCE/PARSE 检查点

2026-10-17，上述完整原入口及最小支持文件已实施，本地代码提交 `50c252997981a25023988ce6a446ee92c1573010`。原入口为130491B/SHA256 `c4b14534b9f01f514cfb2404b66be6cfba7ce8cb233945c74326fae1496c4355`；新增迁移检查入口42083B/`3043688e5b4813b93cddacebbc1880987e1ec4fc08a4a20cdcbb6af4bc475c71`，与既有 AUX 入口逐字相同。四 helper 分别为 cancel54010B/`776638ef105bcdb5782f0e587dd2f076831fd3b4082b8d93f7747be7dac9cc1b`、fulfillment19157B/`936a343bde60a41c94cf636b171a4e8147bbfd34da2a9ccf958975544633ee62`、saved18990B/`94fe4678909c514fcbde1dfe46aecb98b8bc9b0ae0d68945df3b0f25729b62c8`、merchant20484B/`90f825fc6db4198d96c35614766bced47a9e67df56ed60c164972de1a6bfcd7a`。旧共享前置函数、原四 roots/十 files 保持字节；25事实、完整出生读回、两种固定正期限及原五未付/planner/取消链已接入，尚无本轮业务运行。

独立稳定 SOURCE 审查无确定阻断。与原 Main 比较的24个原函数中16个字节不变；变更仅 source/app/main/facts/verify/verify_service/service_case/membership_case。facts/payment_birth/两 verify 与已审 AUX 相同，service_case/membership_case 只额外适配真实角色 trade 比较。四 helper 的四处 trade 表达式/说明逆回后全文严格等于 AUX（fulfillment 本身逐字相同）。Root 实际执行完整四 helper 逆回及检查器 AST，native0，1913B结果 `artifacts/ms4-order-ms5-source/20261017-full-original-r1/helpers-source-result.json` 摘要 `7be0b1a2a6444593ac390e0cd9b6154695811e3d6d05b78ee4c25a9c17e5019b`；不是业务样本。

Root 在原固定 PHP 8.2.33 镜像、无网络、只读源码及根文件系统、专用临时 tmpfs 中实际完成版本和六个变化 PHP 的语法检查，七条命令全部 native0/stderr空，检查时21份来源前后保持。结果 `artifacts/ms4-order-ms5-source/20261017-full-original-r1/php-syntax-result.json` 为7221B/摘要 `216eaa2dba515ebe243252ba414a3d1e7462da5fe09811815be8023edcd189a9`；代码提交后再实际核这21份来源仍相同，随后才补写本后记。`git diff --check` 通过。无 App/SQL/Redis/普通订单或 HTTP/DOM，本次不能记录完整 MS4 回归通过，也不更改验收勾选。

公开结果沿原 `evidence/ms4-order-normal.json`，scope=`MS4_ORDER_NORMAL_WITH_MS5_PREREQUISITES_INTERNAL_ONLY`、成功 status=`VERIFIED_MS4_ORDER_NORMAL_WITH_MS5_PREREQUISITES_INTERNAL`。原安全业务 section 路径/布尔/数量保留，显式 native 支付字段和非 UTF8 字节仅投影为有类型说明的 bytes/hash 摘要；完整原 evidence 另存 `native_proof_archive={encoding:php-serialize-base64-v1,bytes,sha256,base64}`。封存前递归核仅 array/string/int/bool/null，深度≤64、节点≤200000、字符串/键总和及 serialize raw 均≤8MiB；严格 base64 解码、无类反序列化及原类型/重序列化字节/hash 全比较。独立审查已沿各真实 proof 返回确认此合同相容。publisher 单次 create-only，正常 entry0/1 与 shutdown entryNULL 分开，native_exit 始终 NULL/HOST_NATIVE_REQUIRED；宿主须另核同 CID 真实终态。

### 后继 profile 的既有接线边界

仍沿09同一 Task2 和本记录前置设计，从现有仓库外 `ms5-payment-normal-preparation/candidate-normal-r2/` 及其 `prep/normal-r2` close/zero 有限派生新的完整订单 profile。initializer 原9子进程可保持字节；compose/host/close/zero 的角色、argv、三份原订单公开来源收据、两份环境收据及精确 scope/status 同步本入口。host/独立 zero 的 normal_inventory 只取原 M4O_FILES 与四 roots，不能重复追加已在十文件中的原入口，也不能继续要求 M5PN_ENTRY；独立迁移 DRIVER 仍是十源。原六前置摘要补齐 formal/binding 成八份，四 ENV 原 stdout hash 同步全部24排列及原单/双LF。freeze九键、binding十二键、本体来源十一键分别严格核对。

host 与独立 zero 现有 normal_result 都是“两笔已付订单”专属合同，必须整体替换为本原入口全部 section、五未付/payment_birth、保存读回、原25份 plan/20份 projection、顾客G1取消/90秒自然到期/tinyG0/keeper及商家读取的完整合同；不能只替换名称或沿两笔数量硬断言。实际完整 archive 的长度/hash与安全投影也须绑定。本体完整语法通过后仍需完成这套宿主 SOURCE/解析/闭环，再从新 clean 来源运行原九＋四及完整业务，正常 observe/finish/close/独立六 raw 核零；原迁移 formal 必须沿现有移除前 create-only 复制读回。当前新 profile 尚未生成或运行，原顾客27/H5 34/商家25、已付 API/DOM、履约/售后/真实金融/历史/发布及已停止样本责任保持。

## 完整原入口首次真实运行、失败关闭与单字段修复

2026-10-17，上节后继 profile 已在仓库外完成，最终 `candidate-full-order-r2` host 为91126B/`6d4496e07c402771b5558285170cea6ce7e2514e8e1a33e817c8ce9110178ad9`，compose3985B/`6e4dfe2d2bf155e6cf765cba125c289099f3c7ba68296bda0036c43aa71a2f3e`，initializer保持原30904B/`21bf0224425a699214f8c6299212ff286ad8b8a1571963247f23dff08443f854`。R1的独立zero曾对原producer条件等待额外要求非空片段和最后片段跨过期限；该差异仅在SOURCE阶段发现，原R1完整保留，R2四个等待块有限对齐原条件循环，不改变PHP的90秒自然期限或伪造睡眠。最终七源整文逆回、19来源前后保持、三AST/三help及固定PHP initializer语法均0，生成检查收据8132B/`945b5206207caef4d4ade79ef2e940226ae06926cc96877da4c65e34bb5bb03f`；没有把SOURCE校验当业务结果。

冻结 clean Main `32fa2f1309e091544ca19f959521e0b3344e9ab7`，freeze `43f8af484e4a745ce6cf53320a0db72da647136be6f876c53dbdbece0e86ccd8`，原四roots/十files共10811份normal源、十迁移源、五profile及四reference全部绑定。Run `20261017060341315379` 实际完成prepare、专用DB/Redis、九initializer child和四MS5迁移child，均native0，迁移formal在原CID正常移除前真实复制读回。原normal CID `c096e1c757333e3dad90265341cbdd83677f58b4f0347b8d91ad786cfd72eac2` 绑定后只启动一次，实际06:22:44.673160507Z至06:27:07.563795806Z退出1/PID0/restart0。49705B原结果摘要 `526247c380f77cb4a03ab2c5704333e79b054f17b6219434b9dace2018710d55` 为FAIL/NORMAL_RETURN/entry1/nativeNULL，phase=`merchant_saved_order_reads`、code=`MERCHANT_READ_DEFAULT_LIST_COUNT_EXACT_SAVED_DTO`。完整28 section及23133B native archive（摘要 `60668a5678472ee19e64d56f6469f0cae57efaaeca7362704932234cd2c325b9`）保留；此前保存、planner和取消中间proof不替代末尾源码与完整商家回归。

Root和独立SOURCE审查均确认：MerchantOrderDao HEADER、MarketplaceOrderSnapshotProjection与前端domain已按既有合同读取保存pay_type，但 `m4mr_saved()` 的完整expected DTO漏了该字段；`m4mr_same`先比完整键数，因此失败名称不证明分页count错误。本轮唯一PHP差分是在同一native保存行的paid之后补 `'pay_type'=>m4mr_text($order['pay_type']),`，无默认值、无paid推断，原严格比较、详情、分页、精确订单号、过滤及paid=1真实空结果全部保留。候选及应用后的helper同为20539B/`bcb620febe5e2e0c1bafb758ff226cb2d9a4e2f57ff292a7d3af5949b4843eef`，去该55B整文逆回原20484B/`90f825fc6db4198d96c35614766bced47a9e67df56ed60c164972de1a6bfcd7a`。固定PHP8.2.33单文件语法native0/stderr空，收据 `e76662dd81ffd33fa23f67e0d8884094acd425b6493b1023f5834ecccd1a5ec7`；初次语法容器缺/tmp配置导致的初始化失败收据保留，补回已有tmpfs合同后才取得此通过。没有产品逻辑改动或新业务通过证明。

因正常PASS关闭合同不能消费FAIL，另派生 `prep/failed-full-order-r2`：固定144851B计划 `021fa4ba15d5ffedd7c43c55c37ee1b4be65262b997381520d9aaafc2143f992`、17原公开输入和892原evidence，专用failed_result完整核失败原件/28逐section/source11/binding12/八前置/严格archive及真实9＋4；不调用或补丁原PASS normal_result。close45490B/`d5fcfc13a459ce16322e1eda9255d13dec16dffa45a71e75fbe919c422ff8f3d`，zero92238B/`1da4fe68b32f7b4e978cfa8bbcc989d0123bdabd328876d56bdf90564477dd4d`，两份整文逆回原正常基线相等。独立审查发现zero保留了一项close业务状态NOT RUN比较，先完整保存旧zero92231B/`5ae56404ee18fb77e6f64625f3979e67bdfc189b0e7eb234a08bb6d346d24d8c`，仅改该条件严格匹配FAIL_PRESERVED，迁移原NOT RUN保持；在任何授权执行前完成最终独立SOURCE PASS、Root四AST/两help及六源保持，3252B收据 `93e84134ca447d7be26d248d9ecf5f4b3a5261b8b50cc8bf80a41d0cfb1f6391`。

原授权helper `b6733db091f6156a956535cb6fd005da7e12273e0f6db9764c4bead9eb8c34fc` 实际native0并按原ID非force移除；授权派生结果摘要 `98460a3e551468e6f7fab9f643ec56abb449827b0cc3bc4b91283561b2bc1165`。原MySQL/Redis同一句柄stop --time -1，实际两个stop CLI及容器均0/PID0/restart0，随后精确原三CID/唯一内部网/六卷nonforce移除。189671B关闭结果 `2e6ae2337d0f88788001848b37791cd046002a942895b77e8db319ca9b7b19a6` 为FAILED_RUN_CLOSED/native0；19849B独立结果 `0e8429a1f3628cfcc1a2b414031b6ed6dc09a9cb7d55316c59e6ee4d2617aea7` 为FAILED_RUN_INDEPENDENT_SIX_ZERO/native0，六raw查询均0/严格空stdout和stderr，全局无原卷/原网消费者。原892文件/17输入、完整FAIL与旧来源保持；Main在核零完成后才解除冻结、应用单字段修复。业务始终FAIL_PRESERVED，不重开原Run；下一步新clean来源和新Run重跑完整原链，原M4及三端/履约/售后/真实金融/历史/发布责任均未签收。

### 后继消费者基础候选与依赖

并行仅在仓库外准备了顾客共享helper候选：`artifacts/ms5-payment-consumer-preparation/customer-http-source-r1/ms4-customer-http-normal.php.candidate`，58971B/`df212563fd05f1319940b047409aa6bdd51bc9b150893cec43eb4a976c74a8e7`。七个有限变化为27表/两支付表排序、独立PDO完整11列G0/零attempt、唯一真实出生增量和旧registry/整attempt表原生全等；原12HTTP、未付、金额/表单/发票/库存/重放及return keys保持，只改fact_table_count=27。Root完整逆回原56335B/`9d2e58d408306c8a43e8dd6ca254a711409f2909cf930c4606d2d286c57527cc`，固定PHP语法native0/stderr空，三来源前后保持，收据 `296448b2c16f1d78cbf72ddb4e94917e8b2e91c6fcbc1938a351d193b48a99f6`。尚未应用Main；Markdown展示块的围栏分隔换行需去除后才是原替换字节，不将Root前两次围栏提取断言失败当PHP或业务失败。

八消费者和相依profile仍须整体完成：顾客27、H5 27+7=34、商家25与registry/attempt稳定排序，U64/NULL/原生JSON/BINARY32保持；H5 prepare完整facts改native封存时，同时改stage_sealed、http_sealed_inputs和外部bootstrap的第三baseline消费者，沿已有有界serialize/base64/无类严格读回，公开JSON保持安全投影。新实际入口/角色/ENV及九＋四、formal、原终态/来源hash闭环须各自落实，导入消费者不能冒用m4o_main专属角色或仅在App内存翻开trade。

原顾客12HTTP、H5 B0–B4/Final、商家34steps/25captures/9screens及实际SQL/视觉/关闭核零保持原责任。H5原空baseline/B4未付详情/同child/Final只读，商家22未付根、分页[20,2]和owned-paid-empty不能被paid扩展污染；真实paid扩展另有实际生产和随后独立只读baseline，同App真实账户→Services create→原driver测试完成→Services query持久应用，再由独立PDO核winner/根四字段/hold。顾客UID须回绑真实登录actor，商家另核实际员工context的mer与生产根归属，不用AUX UID或paid fixture。下一实施先完成本轮单字段修复后的完整原normal，再收口各新入口App准入/正常base与paid阶段顺序/生命周期及对应profile同步范围；不另建或取消09原执行清单，停止样本保持。

## 单字段修复后的完整内部复测

2026-10-17，修复及前三记录本地提交为 `2e6d7e04ccce63a90484e6817188f64a72597446`，clean后沿原未改 `candidate-full-order-r2` 重新冻结：`full-order-source-freeze-after-pay-type-r1.json` 摘要 `feafcbc4684dda4ae19edc86277f93aeca505b418c923ed8b928f9f840a7f5df`。新Run `2026101706593515e7a6` 完成真实prepare、DB/Redis、九initializer及四MS5迁移child，原生均0。initializer原CID `723d7017b13857f42eb72884bacfa284ceec3be6643f340cde496289200aafb8` 实际07:01:56.006776562Z至07:03:18.082494307Z退出0；迁移原CID `75b3c870bb0008ad21a5b5a1ce18405355df64fe1c5a91a72ca526856351a3d8` 实际07:08:17.027941757Z至07:08:23.845128864Z退出0。两者PID0/restart0，完整结果由宿主真实验证，formal沿原迁移CID移除前真实复制读回，不沿用旧Run或补造原件。

原normal CID `75dbf978e9431543b4f704093004ce65bb21ac89dae78b48dea0d2174cb2c497` 创建/登记/绑定后只启动一次，实际07:13:50.462146906Z至07:20:44.719175106Z退出0/PID0/restart0。50884B完整结果 `0a27dacdff83ad6fa6ecb9d1d5af7c12eea72ed7534de4e4abd825b8eb576706` 为VERIFIED_MS4_ORDER_NORMAL_WITH_MS5_PREREQUISITES_INTERNAL、NORMAL_RETURN/entry0/nativeNULL、phase=final_source，完整native archive23677B/`461018778520c703ea0f4f5b96e409867e9b793319cdf53a8481d07cdf44765e`。宿主authorize-close与未改正常close、独立zero均完整调用成功normal_result验证；不是只看header、退出码或子阶段布尔。原direct DAO、persistent/temporary、level/paid会员五未付、完整G0出生、post-commit/同请求重放、保存读取、25plans/20projections、顾客取消/自然90秒/极小正期限/keeper、三真实商家context/三详情/两页及最终来源闭集均通过。paid会员种类不表示该根已付款；pure planner不表示真实拆单或发货持久化。

原cleanup helper `b092a0c1d8e22952102d8afea80eec46a7aa4245e3e43bb3b50ca4bf4a80ca99` 实际native0并按原ID移除；未改正常close21296B/`46a507d30f6202d63f382a5086c2a8aa2b15f9c15c98550e3f4998b07b2956ce` 核全来源、业务、前置及cleanup后，对原MySQL/Redis同句柄stop --time -1，两个真实stop CLI与容器均0/PID0/restart0，精确原3CID/1内网/6卷nonforce移除。160014B结果 `8694e2ada49d43bfdcf2a1cc5429fc89417089cf5ddb629f47ca23f3d7794e70` 为VERIFIED_MS4_ORDER_WITH_MS5_NORMAL_CLOSE_ZERO_RESIDUAL/native0。未改独立zero72458B/`e89762d9f421785d5237dbc6293c6c22463e1ddf6a601cf8289c7161e08a4946` 取得19528B结果 `3b0f81cb9097801de5d1d3b921714aee4021bb3c2aef7697725ccf34081b9984`、VERIFIED_MS4_ORDER_WITH_MS5_NORMAL_INDEPENDENT_SIX_ZERO/native0，六raw查询全部0且stdout/stderr严格空，全局无原网/卷消费者，业务原件/来源/manifest字节保持。清理脚本的business_acceptance=NOT RUN只限定该脚本范围，不撤销另行完整验证的内部业务结果。Main在独立核零后才解除冻结写本后记，原失败Run和所有旧源/收据保持；HTTP/DOM、已付展示、履约售后、真实金融、非空历史和发布仍未验收。

### 下一顾客HTTP适配的实施边界

继续09原Task2，先完成独立顾客普通HTTP，再做H5/商家和独立paid扩展。顾客fixture要求user/product/cart/order/idempotency/stock movement等全空，因此后继不执行或消费同库完整M4业务。实际链为新九initializer（原MS1三＋MS2三＋新增MS4 check/apply-structure/check）→四MS5 CLI→一次真实files-bootstrap→customer prepare→原12HTTP verify。新九项不含App/writeMd5/版本缓存，不能把新增三个结构child当成files-bootstrap；保留一次实际migrator引导、真实文件校验及版本缓存后再切回DML App。保留旧customer initializer的register/cleanup/files-bootstrap分支及实际native捕获职责，有限纳入新九child合同，不直接覆盖整文件或伪装旧六收据。

后继profile从既有 `ms4-customer-http-preparation/candidate-r2` 有限派生，保留旧profile/Run，顾客业务两角色固定沿原 `audit-ms4-customer-prepare` 和 `audit-ms4-customer-http`，固定argv仍原entry加prepare/verify；初始化使用 `audit-ms4-initialize`，迁移使用 `audit-ms5-payment-migration` 和真实MS5入口。新来源区分为prepare binding_scope=`MS4_CUSTOMER_PREPARE_MS5_SOURCE`、result scope=`CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARATION_ONLY`、status=`VERIFIED_CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARED_ONLY`；verify binding_scope=`MS4_CUSTOMER_HTTP_MS5_SOURCE`、result scope=`NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY`、status=`VERIFIED_NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY`。沿原公开文件名，严格新scope/status/角色/同Run和来源核对，不能消费旧六项/MS4结果冒名通过。两实际业务role的原生ENV精确为APP_ENV=testing、marketplace enabled/trade/payment test=true、真实self ID和Redis六项；compose、宿主原生筛选/hash、entry和加载后bool及实际HTTP child环境一起同步。结构初始化/迁移保留禁用业务的原阶段合同，不做内存开关替代。

两mode绑定保持原十二键再新增 `prerequisites_sha256` 为十三键闭集，schema仍1，scope按上述新合同。common八前置沿本记录原八文件；prepare另外严格绑定files-bootstrap的created/terminal/result/shutdown四份，verify再绑定customer-prepare的created/terminal/result三份，均使用实际完整原件hash、原native终态和语义验证，不只核数量。initializer/helper/files原件的scope统一为 `MS5_PAYMENT_PREREQUISITES_ONLY`，initializer成功status为 `VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY`；files保留 `VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY` 但迁移摘要指向真实MS5结果。顾客专用有限prerequisite consumer按本入口的freeze_sha256/实际role/binding处理；不能直接调用硬绑m4o_main角色与文件的m4o_ms5_prerequisites。顾客自己四roots/九extras保持，entry已在extras中不重复；独立迁移DRIVER八→十，包含真实MS5 CLI/说明及迁移入口，不把完整订单业务本体变成顾客依赖。宿主、initializer、files-bootstrap、mode binding、close和独立zero的descriptor、原native捕获字段、来源和27表count必须同源适配；旧版本保持冻结。原私有fixture、真实prepare RW/verify RO、同一HTTP child监督/SIGTERM、内网无端口、原12HTTP和正常关闭/独立核零均不减。

顾客共享27表候选已另经独立SOURCE PASS，未应用Main；H5后续沿原18身份+scope，把baseline四个raw facts/JSON hash字段替换成单份native_snapshot四键（encoding/bytes/sha256/base64），payload仍完整facts27+auxiliary_rows7。复用现有stage shape/serialize/unserialize的原边界：raw≤1572864B、外层私有JSON≤2097152B、strict base64/hash/重编码、allowed_classes=false/max_depth16、重serialize字节相等及真实PDO完整===；不自动扩限、不解码JSON列或全行字符串化。prepare、stage_sealed、http_sealed_inputs、外部bootstrap baseline/native_baseline及host/bridge/close/zero的34表/count/scope/interface/descriptor必须一起改；stage原snapshot_base64/snapshot_sha256 wire、B0–B4/Final与真实身份保持。

paid API/DOM采用独立后继Run/明确生产后只读baseline，不污染上述原空库/未付/paid-empty与完整页面验收。登录只给token/expiry，不能从该响应声称已取得UID；顾客独立扩展在原12之外用真实token GET /api/user核UID与sealed fixture，H5保留既有真实/api/user capture核验。商家EmployeeAuthServices实际login/authenticate生成的employeeId不是顾客UID，现分页buyer无密码也不作为已登录用户；另用真实可登录buyer，核商家context.merId等于保存根归属。真实cart/confirm/create、测试账户、PaymentServices createForCustomer、原driver descriptor completeTestPayment、queryForCustomer持久winner/application/根四字段/hold必须同一App/factory/driver完成；后续HTTP App只读真实保存结果，不做跨进程driver伪恢复。该扩展不签收真实渠道/退款/对账，原履约、售后、历史与发布仍保留。

### 顾客适配 SOURCE/PARSE 检查点

2026-10-17，沿上述既定合同完成 Main 顾客入口与共享读回的有限接线。入口48560B/SHA256 `8c919f932fc1ea29bb4b94c3f936d9f59f61f228a79a491d2b7e1a1cfc163a06`，共享59154B/`9d25dff9b0060866126b488b96f1f553370e36c03f456a16aa109a0f80418d06`。原12HTTP、未付/金额/库存/发票/统计/重放断言、四roots/九extras、真实actor、私有fixture与同一HTTP child监督保持；只追加两支付事实表、目标完整G0出生与唯一允许增量，并接新9＋4/12或15前置hash/13键binding和六项真实ENV。原生环境与加载后test布尔值均严格核对，没有内存赋值开启。入口七替换与shared四替换的整文逆回已由独立SOURCE审查和Root验证，独立SOURCE均PASS；共享R1到原Main的七项逆回证据继续保持。

新外部profile `artifacts/ms5-payment-consumer-preparation/candidate-customer-http-r1` 从旧顾客candidate-r2派生；host76625B/`c9c130efc42894090b7e07cd53a0e27ae13e01221b7c9d91ff0dbf2bf0ceb4a3`、initializer57617B/`b370d9ea1b032113f4884b6802b1dc35a40dfc73559d548828d89a247394c33b`。host/compose/interface及initializer各经独立SOURCE PASS；原seed/gate/migrator字节与native user/stop_signal/完整mount保持。新正常close与不导入host的独立zero已形成源码，保原同句柄stop -1、精确3CID/1内网/6卷及独立六raw，并补同源9＋4/files/27表业务与全局无消费者核对，已独立SOURCE PASS；close17151B/`391b2abd8f153b35a3a463fa4116fc90fee96608408c0f5ba9b57bc0cfb06b30`、zero47341B/`6b2240c4dc46ae8747700bd20d98cb286ed3443a6f179fe0629d893e12572b88`，纯文本全文逆回分别等于原16949B/12629B基线。Root的AST/全局符号闭集/help已通过；首次draft缺CUSTOMER_ENTRY常量仅由静态检查发现，修正前源码与推导记录按before摘要保留，没有读取或启动Run。

固定PHP8.2.33、无网络/只读来源/tmpfs下实际核共享、initializer、入口语法，均native0/stderr空；宿主/正常close/独立zero的AST及help实际0/stderr空。仓库外检查收据分别为 `source-checks-customer-initial-r1.json` 摘要 `a8169b9ad18a0a65c70ab7b228e6e90f99cadee012ac742370670de8c2e8636e` 和 `source-checks-customer-entry-profile-r1.json` 摘要 `0cc5b13f359db428453ab32e8e6dd9222269fa7db6f783248bf4c5d6d1ce09f5`。这些仅证明SOURCE/PARSE；新顾客业务Run尚未启动，12HTTP/真实关闭核零、H5 34、商家25、paid API/DOM及原完整未验项均不勾选。下一步从clean本地来源冻结并运行新的独立空库；旧profile/Run/收据原样保留。


### 顾客files-bootstrap实际失败与正常关闭（2026-10-17）

被测clean Main `95bbd14f38b04f4d57ede24cebcedf6bfb8c8a7e`、freeze `66f70b6d60f62ab40bcfa260a3310b41805d6034a048e0d99195cd51b8fade01`、新Run `20261017080054d24516`。原九项初始化真实native0；原MS5四CLI真实native0，宿主完整prerequisite_result/payment_result验证通过，initializer原terminal与MS5原formal在其原CID正常移除前保留。files-bootstrap原CID `f3e309358203d850411fea83f34c9ea166ae4eba13aa995570ea48729786b277` 从08:08:21.781110335Z至08:10:56.935360546Z实际exit1/PID0/restart0，原FAIL诊断 `CUSTOMER_EXACT_PHASE_MANIFEST`、phase=files-bootstrap、非fatal shutdown；专用文件引导 `m6i_migrator()`、customer prepare和原12HTTP均未启动。

原因由源码与本Run原件共同确认：原host在files-bootstrap-create只collect当前真实files helper/DB/Redis，保留此前MS5正式manifest，不重新登记helper；collect不改manifest。新R1 consumer却要求正式清单包含audit-bootstrap，并从该不存在的角色读取files CID。实际当前manifest全字节等于保存的原MS5formal，正式清单仍是迁移driver/DB/Redis，driver已正常移除；临时files helper有独立真实created/terminal。独立SOURCE审查此前漏掉这一生命周期错误，SOURCE/PARSE通过不构成运行通过。

本Run没有重启或修改冻结源。原正常PASS close/helper授权分支要求成功顾客结果，且harness授权严格比较manifest与当前观察CID集合，因此不拿它冒充失败关闭。Root按已有失败files直接正常关闭模式，固定165702B plan `e155c02627062646f407ced9b031a8f237f221e525f2aa7692e1cc98e6645191` 与677份原公共输入、原3CID/单网/6卷；新专用close24865B/`34ce76a3e708c53903aa3fc7bcf715e9684eb4d9dfb2ae021db9c56b993fa014`、独立zero42841B/`50dfd628cd76a9b2046b202d9acbc3d764501b83ccf7fccdf5ca62ba0a184403`均独立SOURCE PASS，Root AST/全局符号闭集/help实际0，检查收据 `6927f24eefda7d344586da0d3f6d48dffc63d22aa0fc76330450c6a9fa1113c8`。

原MySQL/Redis分别沿同一stop --time -1 CLI PID20308/8024取得真实CLI0及容器0/PID0，原files raw1保持；精确3CID/1内部网/6卷nonforce移除，未创建授权helper。关闭实际native0，5918B结果 `ad6c44953603a76dd7c0a5b66bdf21c0ec051f54600e103227496d8ed9124bb9`；独立核零实际native0，38461B结果 `64e2c10d9c62d6fac3460581ab30d3c489494200c596a1339522820902ef1201`，六raw均0且stdout/stderr严格空，全局无原网卷消费者，677原件/原FAIL及来源保持。清理成功只限定关闭，files/business仍FAILURE_PRESERVED，customer HTTP仍NOT RUN。Main在独立zero0后才解除冻结；旧zero不对后续新Main重跑。

已在外部独立构造initializer R2：60155B/`8e730830c4129b8b4984a6409982ce1c11a7cd87cfd325fb0618c568299af3b0`，只改两个消费者函数五处，全文逆回精确R1。R2严格核当前manifest全字节/decoded值等于原MS5formal，保原MS5三角色；files自己从真实created和main已核m4i_gate五键绑定核CID/hostname/role/argv/当前PID/来源与hash，三个不同CID取各自真实created，不捏造formal角色、不要求已移除driver存活。原首末gate、九＋四、native与完整mount/来源、一次真实migrator和全部原断言保持；files后再核formal和common8字节不变。独立SOURCE PASS，固定PHP语法实际0，收据 `3289f3317dd50fa54e0a2e5cd9fecc5a9ab0a2b02d21aa09c45a9dbdfe503a8b`。

future profile `candidate-customer-http-r2` 只替换该initializer/README/derivation，host/compose/interface/seed保持R1字节；配套正常close/zero只改profile路径、文件名及实际pin，全文逆回等于原版，独立SOURCE PASS、AST/help0。此R2尚未冻结或运行，下一步从新clean本地来源启动全新独立空库，沿9＋4→files→prepare→原12HTTP验证；本次9＋4与失败关闭不能替代该HTTP或付款验收。

并行H5 shared仅在外部形成108045B/`c75a2a855812ba1a90698417e097acc8fc57ccc0004d466c293d6fc6459e4b3e`候选，原18身份+scope改单native_snapshot四键，完整facts27+aux7保原native类型/大小/权限/stage wire，独立SOURCE和Root全文逆回/固定PHP语法0（收据 `d21dede164b60a18f854b65427ca43ba28a8ac01e12b1d6a6f2bf80acadc28f4`）。未写Main；H5 entry/bootstrap及host/bridge/close/zero仍须同源适配，原B0–B4/Final、页面验收、独立paid、履约售后、历史、真实金融和发布全部保留。


### 顾客R2真实files通过、source失败与正常关闭

2026-10-17，clean Main `c25b879880791ce81dc0dc16ae22ca9920b165a6`、freeze `4e9b272aceb9facc3a24557726b2a2b5e83a62e73d8900209ad413d634b9df5e`、新Run `202610170844240f83f1`。九项initializer与四项MS5 CLI均实际native0；修正后的files原CID从09:04:24.79929493Z至09:09:04.419136958Z实际0/PID0/restart0，真实migrator文件校验、版本缓存与DML切回经宿主完整核验通过。此前R1的formal/helper混用已在此新实例越过，旧FAIL不改。

顾客prepare原CID `93fc636cc75471946c05568df9f8610c5a12593eb87f3d4cdd8742dae99e6700` 于09:17:04.176425833Z至09:19:33.699563275Z实际exit1/PID0/restart0，code=`M4C_TEN_MIGRATION_DUAL_RO_SOURCE_BYTES`、phase=source、HTTP0/childNULL；夹具、App和原12HTTP未执行。source尚未返回，原catch只输出398B FAIL_PRESERVED，未生成业务result/failure.json。Root逐字节保存stdout、空stderr和真实日志capture，capture的CLI0与容器1分别保留，实际非fatal prepare-shutdown不冒充业务成功。

无网络、只读源且不加载App/SQL的几何诊断确认：十迁移源×双root的20项中，仅 `/workspace/crmeb/public/install/crmeb.sql` 被原public tmpfs遮住；另19项保持。诊断5908B/`1d585fed4336befdb0269fb2ffbc951c474cc1dc70a3777a8c24a7ba9aa33689`。单文件RO bind正面诊断6294B/`e3ef6907be3aeef3daa0a756485da808fdc7a412ab6ff37f2c1cc9b17346ee7c`已核全部20项且SQL不可写。辅助诊断最初遗漏原private /tmp使PHP锁文件失败，未加载App，不计业务证据；补齐原tmpfs后才取得以上结果。

本Run未重启、未修改冻结源。沿固定失败关闭模式，264923B plan `fe4fee29298380e18f9053038a8bb0ea79426ee682c208b730f95c66927bc0ce`固定1087原公共输入、原3CID/1网/6卷；两关闭源独立SOURCE PASS，Root AST/全局符号闭集/help0，收据 `f33bef1cad6fd6e4b24dd4900cf0af560d72f9891356128d37c3378b875346f7`。同一MySQL/Redis stop --time -1 CLI PID4860/46396均真实0，DB容器0/PID0，prepare原raw1保持；精确nonforce移除原3CID/1网/6卷，无新helper/force/restart/retry。关闭native0，6736B结果 `317028fb0a8d3e31bc0512b9aa69a9559bdf2059d390e24205e8441ca1239b53`；不导入host/close的独立zero实际0，39268B结果 `61456e9a01e40a522b374c7cbf2a08d6e97b4469c369c4c6b680546c90889319`，六raw严格空、全局无原消费者、1087原件及原source/FAIL保持。业务仍FAILURE_PRESERVED/HTTP NOT RUN，Main只在此zero0后解除冻结；旧zero不对后续Main重跑。

### 顾客R3只读public源修复与公共验证实施合同

先保留原public/runtime tmpfs、十迁移源双RO和全部原12HTTP。只给prepare/verify两个普通role新增原仓库SQL文件到 `/workspace/crmeb/public/install/crmeb.sql` 的唯一RO bind；同步Main source闭集5bind+2volume、host与独立zero对应7项native mount，其余角色及所有字段/完整mount多重集不放宽。public初态精确只有install，install精确只有crmeb.sql；无link、realpath精确，取实际freeze唯一SQL descriptor核双源完整bytes/hash且均不可写；runtime仍真空。原index/router/install.lock复制与App、UID、HTTP child、金额/库存及G0断言保持。七profile artifacts、四roots/九extras、十driver源、六卷/单网不扩大。

为H5后继复用，原customer entry内部有限提取 `m4c_entry_ms5_history(source)`：固定G8+F4十二原件，保原nine/four/files完整语义，新增原create-only context完整SHA/六卷/网/两DB created到历史formal的独立核验，不拿当前H5十卷作历史依据；返回self、initializer/migration/files三CID、已核formal、context_sha256和十二键history_sha256 map。普通wrapper保12/15闭集、原currentformal/DB端点与当前CID差异、真实prepare13键/ENV/result。原ENV消费者增加固定默认scope（原顾客/新H5两值），并有限提取六真实值/native完整720排列校验；原收据身份和projection相等仍在wrapper。临时H5 bootstrap没有独立environment.json，未来须用其真实binding/native原件调用纯helper，不补假收据。无新App/SQL/PDO、wire或source文件。

外部候选已独立SOURCE PASS、全文逆回与固定PHP语法0：最终entry59523B/`c7a6ed64dd28126f3c108ccfbec49d076919a0eb8facac95adca9a44739df7c7`。R3 profile从R2有限派生：仅compose/host/interface/README/derivation及正常close/zero对应路径、实际hash和单file闭集；initializer/seed原字节。host `12087e60329fa8249bd6039441c13d3268c42facdbcf85ed94427f2c6afa7283`，normal close `94e93611bcdc11fed072231d7f1f2266871c8dbab4d0e8b9997c267796f403d4`，zero `c07134d4eb8352855baf713093636fb07640bd2e1fafdccf290ae43ae9894045`。独立SOURCE/完整逆回及Root AST/符号/help/YAML/JSON/PHP均通过，收据 `b4ffdfd34aa4c4caafc8f5e4583412e85851120e45c0f4f2d38b19caee574b47`。本段先记录设计，再落入上述已审entry；后继须新clean freeze和全新空库9+4/files/prepare/12HTTP，源码诊断不替代该业务验证。

H5仅有外部baseline/entry候选及已审设计，未写Main，不计HTTP/DOM。H5自己的16/26 binding、分阶段前置map及真实网络合同仍须接全；历史六卷context与当前四新增卷A1分开。原前端网络Internal=false且ip_masquerade=false、后端Internal=true，不误称两内网。public-copy原卷须真空并create-only复制SQL，与公共history在copy前的双RO十源存在尚未解决时序接缝，不能将普通SQL bind推广H5 copy或静默跳过源码。entry本身不新增PDO；独立native比较由shared/原bootstrap承担。已停止刷新/恢复保持NOT RUN，依赖restore captures的完整B3/Final/27capture不得自动运行、伪造或宣称通过。商家25、独立paid API/DOM、持久履约/售后、历史、真实金融和发布继续保留，沿09原唯一清单推进。
