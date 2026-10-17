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

### 顾客R3原12HTTP实际通过与独立核零

2026-10-17，clean `2545d33328bf55dcba25c2d2903e9832327e435b`、新Run `20261017095520b2f912`，实际冻结2186034B/`3ad794d578d0dbaa477fa52ef6ccc8728c5ed0d3ac27956a9fa23cb14269496c`。九initializer、四MS5 CLI、一次真实files-bootstrap、prepare及原12HTTP全部native0，原host完整验证各阶段结果；没有执行同库完整M4业务。prepare实际10:26:54.3858964Z至10:33:16.485394174Z退出0，结果1339B/`8244baaa00defdd5c3fe4223c8c65a8d13cbf1f75e18a52fbc3e690861951e6a`；原1顾客/1商家/2商品/3SKU夹具，真实生产reset且prepare无HTTP/创建订单。

verify实际10:38:33.752343738Z至10:44:55.397461446Z退出0，PID0/restart0。结果2572B/`a0c452bfba379235b6072c37749dcc5920ef8ddb4a36e76cb2a59522fd4879a0`，`VERIFIED_NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY`、phase=final_source、全来源保持。真实登录/真实Request UID与生产handler、27表独立已提交读回、保存金额/非空表单/原发票/整行运费、owner lookup/saved computed和同完整请求顺序重放均通过；保存计数为1order/2items/2reserves/1invoice/1ledger/1未选cart、3cart统计/2order统计/1创建状态。订单保持未付，payment_or_shipping_executed=false。原同一HTTP child/group使用SIGTERM正常收尾，remaining_members=[]、listener_open=false；native_proc_close原值-1不改写或等同native0。

authorize-cleanup原helper实际10:46:09.614853754Z至10:48:44.365988512Z退出0，按原ID移除。固定R3正常close核完整proof后，用同原生CLI stop --time -1停止原MySQL/Redis，CLI PID41744/32116实际0，容器terminal0/PID0，原3CID/1网/6卷非force精确移除；关闭结果1065B/`ca209956b6f9be05823069bf21de64df4e936d103040f4a6cf376d5cdcb4d307`。Root在Main未变时按此SHA执行固定R3独立zero，六原生raw查询exit0且stdout/stderr严格空，额外53现存容器的全局扫描无原资源消费者，原完整source/结果/生命周期均复核；核零结果35244B/`45147a65467401a6d623b06735534951992eda569d16eaae14c3b23a947db8ac`，实际native0。随后才解冻Main。本Run已关闭，不重开或用旧CID续跑，不在新source上重跑旧zero；DOM、H5/商家、独立paid及原整体未验责任保持。

### H5有限SOURCE适配与后继先决事项

外部三份已采用设计为 `artifacts/ms5-payment-consumer-preparation/h5-integration-design-r2.md`、`h5-shared-validation-design-addendum-r2.md`、`h5-copy-prerequisite-order-design.md`。copy时序已明确为原六卷live核完整9＋4/files/C3并正常移除C3，再create-only新增四卷A1和实际公共材料复制；copy阶段三次getter不要求已有SQL/私有baseline，真实复制后在成功发布前执行完整20源双RO history。其他phase保完整history，不将普通顾客单SQL bind推广H5 copy，不伪造历史context或环境receipt。

四PHP外部候选已经独立SOURCE复核和Root固定PHP语法：shared150952B/`cb040d2baa35d821738b550687a344337ffd99af3cdcd013161f0e92867ce230`，entry108739B/`19c98410a87b18668ae4922184831df12b8c131195c3be7b695f397310de7843`，bootstrap69833B/`3200e3d708cbeb06079ffebc73e029b7f643f6ee5d86d20919bd835f501b9834`，copier14895B/`3b14ad7f087cdefaec869f5bf3fed2e9e1b6a79d0667107206d25d45c3d9a2c6`。原业务/私有wire/完整B0–B4/Final/真实JPEG验收责任保持，新增7阶段map16/19/19/22/22/25/28、真实16/22/26 binding、六ENV与27＋7来源合同。新host112058B/`e5abc2a3d2b516f9672f28de6a7f2ddf90fc648f5ded7b2645075866ef1d92b4`、配套interface/basehost/compose/bridge与fullclose46944B/`7ac36b5bfc9fd79ce9e5982a8e23ac05e036453baa25e50f3559494edc9b6929`亦独立SOURCE通过。Root解析收据 `source-checks-h5-profile-core-r1.json` 为1556B/`fbb10cc77174f12e8c14bf72039175520aa263ce2bd2ee32af583aeb2f254573`，host/fullclose收据1240B/`a03d5dc4298e2c47fc46b02ad05197a9c8ac52f31d8bf283c699acf3fbc728b6`，均在仓库外；最初Root校验器误假设ingress有PHP environment的KeyError已记录，仅修验证器，候选不变。

H5 full independentzero外部候选亦已稳定：115061B/`ba340158db1533aeddda353747a2b2925b578d717c9c9a9417b0e1ccf5bd6a79`，22组全文正逆回及真实R3九＋四/files/C3、历史六卷与当前十卷/双网/四角色/7阶段map均经独立SOURCE复核；Root AST/compile、56模块定义唯一、全局符号及仅stdlib导入检查实际0，收据745B/`d8a571a71400d4feb340d25356db532f7a6c3c1a5a3bf399cff2be784f2a257a`。以上H5候选均未写Main、未组装成可运行profile或运行H5，真实20＋7 artifact闭集/材料与各pin尚待。full normal-close仍要求原B0–B4/Final/27capture，已停止restore及相依完整B3/Final继续NOT RUN，不能为关闭伪造完整验收。若只运行可独立授权的正常阶段，须另按实际阶段设计正常关闭及独立核零，旧R5/R6固定Run的partial工具不能直接冒用。本阶段先按09原Task6新记录的五文件收货旧链隔离合同（独立复核补平台后台首次写入口）修复已确认副作用入口，再继续原H5/商家/独立paid及履约售后目标；不以源码接线或清理通过提高整体完成度。

### 收货旧链五源隔离SOURCE检查点

R3正常关闭/独立zero0之后，先在09原Task6记录实施合同，再按真实caller独立复核补齐平台后台先写入口。Main五源已逐字应用已审候选：StoreOrderTakeServices32368B/`d67e0d51364610d13ded8343c44b94c8a0a4fd63f5e8c3f2408869c1f37d4d7d`，TakeOrderJob2820B/`13300fa5afb1d72bddf90d9d61cbe4dc45b583ac33e55b3451f02873e2033924`，OrderTakeListener3183B/`ee040a6d27d40b960d74b6d9e33c065922c6080a8ca5e46e71e4412f75de119e`，OutStoreOrderServices18042B/`48098dea6a767dd4d44c7c1bb3aad00cb081e49f535059d9b01d97ee7f445dbb`，平台admin StoreOrder35385B/`254ffa0b53710441755c68c5d220a963b3b878ef3c852803b29b163dd25fdfc9`。已有policy严格保存版本门禁在首次副作用前复用，共享/事件按真实PK重读；确知V1异步无写结束，原无行安全结束及legacy奖励正文保持。14组完整正逆回和真实caller首写覆盖独立SOURCE PASS，Root五项固定PHP语法均native0，证据见09原Task6和artifacts/ms4-receipt-legacy-boundary/source-r1。未执行App/SQL/收货/奖励或停止样本，尚无此新来源的收货运行证明。这里不新增V1收货/持久履约或任何真实金融入口；H5/商家/独立paid与原完整未验责任保持，继续原09唯一清单。

### H5两源实际合入与新profile组装的实施依据

2026-10-17，当前clean Main `a6286afab7b36834e903678a12fdbea9d7b4587b`。明确采用前述 `h5-integration-design-r2.md`、`h5-shared-validation-design-addendum-r2.md`、`h5-copy-prerequisite-order-design.md`：底层以R3七artifact为准，两普通顾客role的单SQL只读bind不推广H5；原不可变六卷context和真实A1四卷分开，公共copy三次getter不要求尚不存在的SQL/baseline，真实create-only复制后完整20双RO检查才可发布。Main待接线文件只有 `help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php` 和 `help/audit-regressions/isolated/ms4-customer-h5.php`；外部两PHP bootstrap/copier不是新仓库支持文件，仍属原H5二十artifact。原四roots/九extras加H5两源的闭集、原十项迁移源、四preparation references不扩大。

Root从Main shared106271B/`308425b472e021fc8a1f836bbe643856aed8cd3b4858a54a0a19f93c5cf2f2a2`与entry115909B/`a0ae902ef91cf560c7743520e7d9358f082a553f31d636625eda641d7f3697fb`经各自baseline再MS5候选的完整36组正逆变换，严格回到当前原件；最终150952B/`cb040d2baa35d821738b550687a344337ffd99af3cdcd013161f0e92867ce230`和108739B/`19c98410a87b18668ae4922184831df12b8c131195c3be7b695f397310de7843`仍等于已审已解析候选。新收据 `source-checks-h5-Main-chain-r1.json` 1529B/`2e5b0b01edcd2a028b7999f96e10b754a890fd823a433aee4ba534ccf4435677`，仅完整字节链，不新增业务运行证明。原27＋7原生snapshot/18身份/大小和权限、真实UID/App/PDO/同child、全部B0–B4/Final/真实JPEG断言保持；接线只补已设计的MS5来源/原件map/六ENV和完整前驱验证。

Root采用新外部目录 `artifacts/ms5-payment-consumer-preparation/candidate-h5-ms5-r1`，仅在目录不存在时create-only组装已审实际base7与H5 20，四个生成材料目标初始必须不存在。完成本次Main来源本地提交并核clean后，才运行实际materialize重新核完整public、545前端来源/blob/bytes、两完整旧工具树、原build/dist和批准输入/PNG，并生成本轮三个manifest及materials receipt。另显式读回receipt.source_sha和build_reuse.current_head等于该新clean HEAD；不把host.freeze未单独比较这两个字段误报为已比较。freeze再取所有实际完整bytes/hash和当前build复用证明，不从历史derivation里的旧pin推导。profile尚未组装、未冻结或启动；允许的B0-B2正常阶段须另有准确部分关闭/独立zero设计并审查才运行。原fullclose仍要求真实B0–B4/Final/27review，已停止restore及相依完整验收NOT RUN保持，不能伪造Final或用fullFinish做清理。以上采用发生在依赖它的Main接线之前，不新建执行清单、不完成原H5/商家/paid/履约售后里程碑。

两Main文件现已逐字应用已审最终候选，Root对实际Main文件在固定PHP8.2镜像、无网络、只读根/源码、cap-drop ALL及临时/tmp下分别php -l，native0/stderr空；前后完整字节等于候选，临时容器原生查询空。实际应用与语法收据 `source-checks-h5-Main-apply-r1.json` 2594B/`99e67e134a4e0e17f7c60293532749c6ee9135829c202c60c3f9c570f48ba63e`。没有启动App/SQL/HTTP/DOM，原完整页面和停止样本未验状态保持；下一步待本地clean提交后由Root实际组装材料与freeze。

### B0–B2阶段准确正常收尾的实施采用

采用仓库外 `artifacts/ms5-payment-consumer-preparation/h5-partial-normal-close-source-r1/DESIGN.md`，13775B/`97c62d0fa4acf25ad0f1ea49b63fb5d586890f90591bbad52ca3fb9ad56e47b7`，沿原09 Task2，不新增执行清单。Root已在实际shared1551–1637确认B0/B1/B2分别核完整原生baseline、真实登录与/api/user、两店三商品/cart/visit及辅助producer，不依赖restore；B3才走frame/restore。后继只用原三stage producer与原有九项业务flags，不造公共stage、不解PHPserialize或JSON化BINARY。close从真实同CID内存读原runtime/ready/三stage/start-stop完整I8、原canonical snapshot及实际capture/JPEG/review库存，重算安全partial投影并与当次Root实际ACK/progress观察完整hash比较；所有原公共输入及evidence字节保全，私有原件首末完整性和受控销毁责任保持。

部分收尾只用原bridge独立stop/observe、forgetActors及driver release，保同child一次TERM15、空组/无listener与原native_proc_close；Root在原tab仍可观察时实际复位viewport、读回，再真实关闭本任务owned tab并用新inventory核其消失，保存实际操作/读回安全摘要，不把两项布尔自述当证据。绝不调用full requestFinish/confirmOwnedTabClosed/publishFinalAudit，B3/B4/attempt/finish/browser-closed/SQL/full audit实际缺失须保真。新partial固定scope/status和三stage/缺两stage/NOT RUN/INCOMPLETE字面值按设计逐项实现，不复用full成功值，不将缺图或缺review改作三宽或完整27张通过。

新partial pair只在该外部目录另行SOURCE实现，绑定真实context/Root观察SHA/自身与peer/full profile实际来源；不加进原H5二十artifact、不改full pair。close按真实MS5/H5全部前置、完整四role/两网/十卷身份与全局消费者，分别一次同PID stop --time -1、native0及非force精确移除；独立zero不import host/close/App、不开私有K、不执行PHP/SQL或mutation，自含必要来源/历史与partial证据消费者、六raw严格native0且双流空和前后全局无原消费者。若三stage未真实成功，保失败另按实际状态处理，不能补造stage套正常合同。当前只有已采用设计，partial代码/独审/解析和新Run均未发生；完成这些前不启动本次H5实例。

### 静态组装与Root UI安全观察附录采用

Main两份H5接线及此前记录已本地提交 `fe9d4bc4e7357e206c0cdcfde01ef78e8c8e9209`，Root实际提交后确认clean。按11700B/`8f5413caf0c5197275694a8cb02e620c771212af18daca90950b15eb49a87fc7`的组装说明，Root先确认目标不存在，再在新 `candidate-h5-ms5-r1` create-only复制实际23份供体，逐份核原件和目标完整bytes/hash及精确闭集；不拷四份旧材料。实际收据 `source-checks-h5-profile-assembly-r1.json` 7282B/`f6282fc5c0390a6c041e116affcff984df0fd4bb173eccc8526bbba26fed40a4`，四个生成目标全部仍不存在，materialize/freeze/Run均NOT RUN。材料必须在本记录提交后的最终clean来源重新真实生成，不能沿fe9d4bc旧header。

Root全文采用partial目录的 `UI-EVIDENCE-ADDENDUM.md` 5490B/`991024fffe18f47d1c0da72174911551e8e36fccd6c61baa242bfaaf1c112eee`，独立SOURCE审阅通过，补充已采用DESIGN。三份create-only UTF-8 JSON是Root真实工具调用的安全投影：完整共同Run/source/freeze/serve及UI CID/context/binding/browser/tab身份；实际毫秒时间；同tab原始尺寸、真实viewport.reset返回undefined及后读回相等；实际tab.close返回undefined；随后新发browser.tabs.list的ID-only安全投影且目标缺失。三原件完整bytes/hash在Root观察绑定，close和独立zero各自重读、核exact shape与身份时间顺序，不能只信摘要或布尔值。空白页探测的尺寸/ID/时间不写入商城证据；不冒称工具原生JSON、native0或不存在的raw body，不持久化URL/title/凭据。

partial来源由真实DESIGN/本附录/两个脚本四descriptor绑定，不用循环SHA常量，不扩原二十artifact。Root此时未操作商城页面、新Run或收据；上述采用先于依赖它的UI consumer实施，完整B3/Final/27capture、商家/独立paid/履约售后/历史/金融/发布及停止样本状态均保持。下一步先记录新clean来源并生成实际材料与freeze；原partial脚本仍须独立审源和必要解析通过后才可开始新实例。

### 材料producer引用过时构建的实际诊断与实施依据

实际 `h5-materialize-native-r1.json` 1080B/`967c9f7f40147991fa7410f9e7e99ce7bb945f425f29a47dde6d1fcc406362d2` 保存原PID10064、11:53:06.797208Z至11:53:33.978427Z、native1及完整双流；stderr1177B/`25c84e573790f64d98dec49bf64a2d5428d9d31bdca261b776dfe03d92496767`，失败点为当前545源码blob与旧38a14构建不符。四个create-only材料目标实际全部不存在，Main仍clean9c5ac646，未启动App/SQL/HTTP/DOM或任何Run。首次PowerShell解释器路径选择得到多路径数组，尚未启动producer即失败；改用实际Python314单一路径后的这次native1为真实producer结果，不将两者混记为业务失败或成功。

Root只读诊断确认旧构建差异仅 `template/uni-app/pages/goods/order_details/index.vue`，正是原八文件保存测试付款文案增量。原记录 `saved-test-payment-consumers.md` 已有真实较新构建 `artifacts/ms4-h5/pay-uni-af0-r1`；Root重核该3815B BUILD-REPORT完整SHA `fc358a6a9514b7ef2db2cf52d101b0b639a4c0386b306b68471eb60619411d51`、原HEAD `af0bcd9c7e46d17609461fb0df2620c62d6b2a52`、13项实际references，以及当前545全部路径/blob/字节和该build/source副本完全一致。诊断收据 `h5-current-build-source-diagnosis-r1.json` 2811B/`80617ee639786f213d1a3b1abe5c0c0cb970009a6b13268938765ba4cccee56e`；此时尚未宣称双工具树或dist全核通过。

采用最小修正：仅新profile的 `ms4-h5-materials.py` 三个常量BUILD/BUILD_REPORT_SHA/OLD_HEAD分别引用上述真实目录/报告/hash与原HEAD；其余完整校验正文和供体旧文件保持。新P的说明/derivation须把旧nested pin标作历史，并记录实际采用的af0来源，不能把原编译HEAD改成当前Main。Root核三替换完整逆回及独立SOURCE后，再用本记录提交后的真实clean HEAD启动新的create-only材料producer；全545路径/blob/字节、两套完整29930工具树、Node/原helper、adapter/compiler回执、全部dist/空目录和批准输入均须仍按原检查实际通过，再核新receipt.source_sha/build_reuse.current_head。旧native1全保留，修正不改变业务、UI/full验收、停止样本或原09唯一清单。

新producer现为9908B/`4d0940a446c309320ee4922edc72d78e1a02d4303afab2af19e57621aa489eed`，三处唯一常量替换全文逆回原9921B/332c0d29…，独立SOURCE及Root AST/compile通过。P内两README仅前置实际采用说明，历史正文完整保留；derivation改历史键名、加实际来源段及有限84行CRLF→LF，按原换行序号可全文逆回。四文件采用收据 `source-checks-h5-build-adoption-r1.json` 3222B/`119780c9dffc62bc7f243ded01d7b4502b686af4b38adee06cb15f8d7f11dab9`，七组文字变化加一组换行归一，不增加运行gate。初次说明生成器在发现旧JSON混合换行时停止，随后只从两已完成的精确README字节继续完成该有限变换，旧供体/失败producer/四材料不存在事实保持；不是重跑业务。

### B0–B2 partial pair源码与解析检查点

最终外部close53564B/`e85c46c0b4fa2bf1d873afad9cebe4af35e69b5228b87b5a021b97dbb9eec193`、zero141570B/`eeb7001b54d04e542156af3e3e9fb45975d8f2386a5ae77fa2b34f44f3d922b1`独立SOURCE复核通过。原三stage producer不改；新capture消费者按本stage真实request_id关联原network、B1 user及B2完整集合，保正常config GET；UI整数schema、原三ACK bool/int精确类型与完整对象比较均核。Root实际读取完整非截断replacement数据，6组close全文逆回R6、4组zero全文逆回已审full zero，AST/compile、22/68顶层定义唯一、六个明确在close内从已审full源绑定的global helper及其余名称检查通过。最初检查器未计这六个真实函数内global赋值而报未解析，Root按实际赋值和供体定义修正检查器，候选未因此改写。

两个真实 `--help` 子进程native0/stderr空，前后候选及供体字节保持，Root收据 `source-checks-h5-partial-pair-r1.json` 5223B/`7501877e0beb99d03b52fa695f7a4893993e6b693096faa64cab9a9aeb67ced3`。来源说明10629B/`7b44e8da7cb1dce8bebb27f056e7e8fdeb8f77f8776e6554390e2ae5d549407c`及replacement161569B/`0c023261845d63e5b8507a1119ae8ebb524a60c92bd2137dd67d8e6ad2b568a5`保全。仅SOURCE/PARSE/HELP；没有启动新H5 Run、实际Root UI操作或执行partial close/zero，原full B3/Final/27capture、HTTP/DOM与完整商城验收仍未完成。

### 独立已付消费者producer设计采用

Root全文审原R1与R2六组完整文字差异，采用 `artifacts/ms5-payment-consumer-preparation/paid-consumer-source-r1/DESIGN-r2.md` 24533B/`537d3e49a1023e3d1a28f62f1c915e1ac533df4213dd8fdafb66b2fc7c32561d`，原R1保持。六组forward/inverse全文相等的Root收据 `source-checks-paid-design-r2.json` 393B/`bd597bbd63dba15ddaeb38ed35e3d5d3ece13cae9e15a2a089679ce5d1b46f5f`；原独立评审指出的public tmpfs材料初始化与平台第三端责任均显式补入，未扩 `/api/user` 写字段allowlist。

沿原Task2先在仓库外编写唯一 `ms5-paid-consumer-normal.php` 候选，尚不把未审候选写Main。新paid独立role/proof/argv、自己的十三键binding与paid_source_*明确含专属第10extra；普通原九extra、C3实际原十三键/entryhash及原未付/H5/商家夹具保持。真实public材料先于父唯一App/身份HTTP child，POST login后只从真实GET/user核buyer UID，正常停止同child后父App同Connection/PDO/factory原driver完成真实cart→confirm→create/G0→test account/create→原六键descriptor complete→query持久winner/application。独立PDO完整27+7原生封存保持BINARY/U64/JSON/NULL类型及原codec/私有字节权限上界；不造UID/JWT、付款SQL、假scope或跨进程driver状态，不新增测试付款HTTP API。

后继三端消费者仍须分别真实鉴权及API/DOM证据：顾客本人、商家真实mer context、平台adminapi order_info/tidyOrder与admin详情测试金额文案；不把两端或源码构建记为三端完成。原未付链、原H5/商家全部视觉责任、持久履约/收货/售后退款、非空历史、真实金融及发布验收保留，已停止样本不恢复。本采用先于paid候选实施，当前没有新entry实现/paid Run或baseline，后续SOURCE/PARSE/producer/封存/HTTP/DOM/正常关闭和独立核零分别取证，不新建第二执行清单。


### H5 实际 B0/B1 通过、B2 失败保留与独立正常收口

2026-10-18（Asia/Taipei），沿原09 Task2。Run `202610171222555a7646` 来源为 clean Main `e9438df017d71f03ec33d178c980f81f6e8e0608`；原9初始化、4个MS5迁移命令、files/C3、H5公共复制/prepare/files及final登记均已实际正常0并保留原件。真实消费者登录和两店三商品加购（甲2、乙1、丙1）已在通用多商户页面观察；只有B0/B1完成原27业务＋7辅助表SQL断言。B2唯一次 `verifyCustomerSqlStage` 原调用拒绝，仅有 `M4CH_BRIDGE_NATIVE_TERMINAL_CHECK`；旧桥已清掉双流，原生退出及底层断言均不可知，不能宣称PHP1、SQL原因或B2通过，也未重跑B2。

原390宽整页截图在record前超时，原pipeline capture/JPEG/review均为0。两张Run外原生观察图只作观察，不计原27capture或视觉验收；未执行确认/创建/支付，B3/B4/Final及原停止样本仍NOT RUN。原HTTP child已通过原bridge一次正常stop/observe；Root真实release/forget、恢复原viewport、关闭owned tab及新清单观察已保存为完整安全收据。cleanup真实晚于业务deadline，专用设计只允许正常收尾观察在期限后，原B0/B1及child业务期限保持，未补历史原件。

采用外部 `h5-b0-b1-failed-b2-close-source-r1` 四SOURCE，独立审查及Root完整32/31/7组正逆回、AST/compile/help均通过，检查收据5346B/`214b16c8620e074895666ab57f98787e1957616165053366c1bafd5e9703f779`。Root观察4310B/`a94217991d2945408428bc8aaee1aecd67f7bb55e70b40ad092f41cb4bbb91fa`只承认两stage六flags、B2失败未知底层和零capture。原UI/serve/MySQL/Redis四父各用同一原生进程正常stop；serve在最终完整来源核对后正常退出，没有强制停止或重启。关闭程序实际native0，原4CID/2网/10卷非force精确移除，结果SHA256 `d33f2e4d7fe1e10e73e802f1bfca3292d9c3827fbdec512caded9027f85ea81a`。随后独立zero实际native0、六raw查询严格空、前后各53个现有容器全局扫描无原资源消费者，结果72975B/`4d86fcefbcba7eead6f91c4628928eac3c78ed422fdeae39f4c336906f59283d`。两结果均明确FAILURE_PRESERVED/商城INCOMPLETE；只在zero0后解冻Main。该Run已关闭，原P/freeze和失败保持历史，不再重开或在新来源重跑旧核零。

### 后继错误诊断候选与测试付款入口实际接线依据

Root已全文采用外部 `h5-native-failure-observability-source-r1/NATIVE-FAILURE-OBSERVABILITY-DESIGN-r1.md` 12765B/`c625d5c4fe45e1acd0ab6fcb3a8040257df97b48b8c33669e8e75a58b32115f3`。bridge successor 54063B/`c6b653f76958f836e6dcdae793c6723a3fd627dc7e14797b1c3165a07cec7b98`独立SOURCE PASS，Root六组完整正逆回及Node24.18.0的仅语法检查native0，收据1642B/`a6f4bb0a8aea35e5c6ec25320b63572fbf4b7ef235e21973f3bacc4619156cdf`。它保留真实close code/signal、完整双流bytes/hash和错误标志，仅投影完整严格安全PHP失败；create-only收据后仍抛同一错误，原成功wire及stage尝试/成功递增不变。尚未组装后继P/冻结/运行，不补当前B2原因或历史，也不恢复停止样本；后继必须完整传播实际20来源及材料/freeze。

本记录在依赖它的Main接线前采用已审外部 `paid-consumer-source-r1/ms5-paid-consumer-normal.php.candidate`，101356B/`8ad9926a13ca06ae7731e2acb56a515f434be0fdb44a25a1089a0e2310eafc91`：只逐字新建Main `help/audit-regressions/isolated/ms5-paid-consumer-normal.php`。其完整来源、独立SOURCE审查和原固定PHP语法已通过；不改原未付/H5/商家入口、付款HTTP API或真实金融配置。新独立paid profile仍须从实际clean Main形成source9与paid专属第10extra、完整9+4/files/C3和九artifact实际来源。真实登录GET UID、同父App/factory真实cart-confirm-create/G0/test-driver complete-query、独立PDO27+7原生封存及三端API/DOM、正常关闭/独立核零仍须另行真实执行，接线或语法通过不算paid验收。


Main实际应用与语法检查点（2026-10-18）：唯一新entry已逐字写入，前后均等于已审101356B/`8ad9926a13ca06ae7731e2acb56a515f434be0fdb44a25a1089a0e2310eafc91`候选；Root对实际Main文件用已有固定PHP8.2镜像、network none、只读根和单文件bind、cap-drop ALL执行php -l，实际native0/stderr空，临时自建容器前后原生库存严格空。收据2428B/`054dc201c64f98d49a5ce4f8fdc3897a1d563e62d42a0c077da13ea13863ba8c`。这只证明已应用与语法；未启动新paid profile/Run、App/SQL/付款生产者或三端API/DOM，完整09范围和原停止样本保持未验。
