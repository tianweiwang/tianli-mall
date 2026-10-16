# Task4 完整确认上下文刷新恢复：实施依据与源码检查点

状态：SOURCE CHECKPOINT / RESTORE RUNTIME NOT RUN。Root在现Task4原范围内采用已审r2及Root补充，任务状态只在09文档的一份RC清单记录。源基线为 `9e53170a7db817c36a209e26a8e34404ac60d612`；原r2主文24230B、摘要 `de61385f3889aa4226f56f9facec2ac4d07d1cd7e1730445956df7f1266f4f49` 保留在仓库外，本记录合入其必要修订。实施工作树R=`H:/天俪商城/worktrees/ms4-confirmation-restore`，分支 `feature/ms4-confirmation-restore`，U=`R/template/uni-app`，B=`R/crmeb`。当前Main的顾客H5 Run继续使用原冻结工作树，本分支不得写入该实例或把其结果作为新产品源的运行证据。仅L2本地开发；用户停止的运行样本保持NOT RUN。


## 1. 完整要求与边界

| 编号 | 必须达到的行为 |
| --- | --- |
| R1 | 真实登录owner＋原server key只读恢复完整确认DTO；原key、商家、来源、refs、SKU/数量、表单定义保持。禁止fresh confirm/cacheOrderInfo/new cart/againOrder/new key、续TTL或重建temporary。 |
| R2 | 已创建结果优先：即使frame已由正常postCommit删除、600秒已过、商品/当前开关已变化，仍先真实owner lookup，取得原orderId；不得伪造binding才能去详情。 |
| R3 | 原确认页、真正父页丢失、浏览器刷新/前进后退均恢复本UID的完整编辑上下文：地址/发票明确none或原选择、mark、contacts、contactsTel、gift_mark、payType、每组表单值及已上传图片URL顺序。空串、字符串`0`和图片数组不以truthy过滤。 |
| R4 | 每个实际create前，完整不可变attempt及同一owner/key上下文先同步持久写入并完整读回；失败即不发HTTP。不从刷新后的当前可编辑字段重造“原attempt”。 |
| R5 | 任何持久submitting/uncertain/已有send-intent的记录刷新后均为unknown只查询锁；processing/not_found/网络失败都不能自动重新confirm、重发、编辑或清锁。not_found不是旧请求结束证明。 |
| R6 | 加载草稿前强制当前真实GET `/api/user`，校验正整数UID及本次账号代次；不以token存在、URL UID、Cache.UID/USER_INFO、SETUID或旧内存userInfo作为授权。所有旧异步回包均须失效。 |
| R7 | 正常父实例仍在时复用本次navigateTo的真实eventChannel、返回真实保存ID；父实例丢失则由持久上下文＋只读restore回同key。不得依赖刷新后本页不存在的opener，或navigateBack到别的实例。 |
| R8 | 明确本地TTL/容量/history/成功清理/退出/换号/身份过期策略；未知提交不能因本地清理或过期退成editing。修复真实LOGIN/LOGOUT、USERINFO、checkLogin和请求层生产消费者。 |
| R9 | 本地价格、能力、地址正文、发票抬头和表单定义只作来源镜像，不是服务器授权；恢复后以生产完整DTO/本人当前记录/真实computed为准，quoteReady从false开始。原create幂等/价格/库存/地址/发票/表单校验不改。 |
| R10 | 普通V1 persistent/temporary均按原frame读取；legacy、礼物、旧历史订单/历史开票原分支保持。不新增表、服务端草稿、全局store、支付或上传自动重放，不扩已停止样本。 |

## 2. 已核源码接缝

| 实际源位置 | 实施依据 |
| --- | --- |
| `StoreOrderServices.php:1709–1836,1849–1865,1896–1929` | fresh完整DTO producer在1788必生成新key/set600秒；getCacheOrderInfo只读原owner缓存、当前cart及recheck。不能调用fresh方法恢复。 |
| `MarketplaceOrderCreateServices.php:96–105` / `MarketplaceOrderDao.php:775–792` | 原owner lookup锁真实UID、查key digest，无claim/cache依赖；返回created/processing/not_found。锁只包住lookup事务，不保证随后preview读取期间没有create完成。 |
| `MarketplaceCartSelection.php:17–73` | frame仅回uid/mer/source/refs/selection/custom_form，不含store/weights；store取getCacheOrderInfo当前局部group，weights由真实computed局部更新。recheck内部capture纯计算，不是另建cart/key。 |
| `StoreOrderComputedServices.php:82–167` / `PublicCatalogServices.php:385–410` | computed更新局部group并真实重算；addressId>0失效会throw。quoteOutput规范字符串refs/forms并删成本、内部UID/selection/weights，必须作为最终公开出口。 |
| `StoreOrderServices.php:1758–1761,1911–1917` / `MarketplaceOrderCreateServices.php:61` | cache reader在active=false可回legacy组，restore须显式核原version=1。computed消费group.other；restore要按当前真实配置刷新局部offlinePostage/integralRatio，不以原缓存other或旧报价当现价。 |
| 确认页`:620–672,696–899,1127–1164,1348–1447,1498–1515` | 现onLoad先要求cartId、遇key拒绝；完整hydrate/immutable binding/attempt只有内存。异步已有key/generation/revision，缺owner/page生命周期。父页openMarketplacePage错误预检自身opener。 |
| `marketplace-order-submit.js:11–52` / `marketplace-order-form.js:11–93` | 复用真实DTO绑定、`confirm:<key>`、完整冻结body和结果解析器，以及含definition hash/index/refs的答案保留；原字符串和数组类型不能重猜。 |
| `store/modules/app.js:21–95` / `libs/login.js:49–127` | LOGOUT不清state.userInfo；USERINFO初值{}直接命中且请求失败的外层Promise可能不结束；checkLogin又把全局USER_INFO反灌UPDATE_USERINFO，无token分支未统一LOGOUT/UID清理。 |
| `utils/request.js:27–81` / `users/user_info/index.vue:387–417,449–470` | request共用HEADER对象；旧401无账号代次检查。H5换号直接LOGIN后只更新页面userInfo，未统一更新store/UID/cache或清理旧确认上下文。 |
| `users/login/index.vue:458–470,607–619` / `api/user.js:17–18` | 普通密码/SMS登录已有真实getUserInfo，但仅SETUID；应共用修正后的真实USERINFO action，恢复每次仍force请求，不靠它的普通缓存分支。 |

## 3. 最小后端合同：专用只读restore＋共用完整DTO组装

沿r1拟议 `POST /api/order/confirmation/:key/restore`，在现本人认证组新增；原 `POST order/confirmation/:key`、computed/create/DAO响应与算法不改。新增controller验证Request.uid和Request.user.uid相等，key沿原confirmationKey校验；不接客户端UID、mer、cartId、source、价格或定义。先owner lookup，再解析preview提示；created/processing原样返回，不依赖frame/active/create开关。专用V1 restore在not_found后显式核原frame version===1，不能仅靠active分流或接受cache reader的legacy返回；新schema不可用时明确不可恢复，不改旧legacy路由。旧SOURCE-MAP“32字节”用词在本r2校正：后端现text校验为mb_strlen UTF-8最多32字符，前端binding另有限ASCII边界；实现沿实际共同校验，不改key格式。

仅not_found预览分支接显式 `addressId,invoice_id,payType`；0明确none，不能把遗漏或0解释为默认记录。地址用真实uid/is_del查本人当前行；发票复用getInvoice/checkInvoice和当前开关。失效/非本人选择只回统一unavailable，不泄露其是否属于别人：保留原提示id，局部报价传addressId=0、DTO.addressInfo=[]，绝不触发fresh默认地址分支。发票none保持不开发票；unavailable保持未解决，不静默改默认/标题。payType保留原字符串意图，按当前DTO真实支付能力校验，失效时禁止提交，不能悄悄换渠道或启动支付。

新 `restoreConfirmation` 编排可加在现MarketplaceOrderCreateServices（已负责lookup）中，无须假称存在MarketplaceOrderConfirmServices。`StoreOrderServices`新增只读restore方法，并抽一个共用完整DTO formatter：fresh保留原cart/capture/报价、原位置cacheOrderInfo的唯一新key写入，再调用formatter；restore从getCacheOrderInfo/recheck的原完整group取原key/selection，按现生产配置读取offline_postage/integral_ratio刷新局部other，再真实computed只改局部group，调用同formatter。不能仅复用缓存other或旧报价；不改create现raw配置/locked quote判定。原legacy自动领券仍只留fresh原分支外，不进入restore。formatter复用完整用户/地址/cart/forms/价格组/支付发票能力等现producer，最终统一quoteOutput；不裸返frame、不把computed.result当整页。

新API的data严格二分：created/processing沿原结果；预览为 `{state:'not_found',key,confirmation:<完整公开DTO>,selection:{address:{mode:'none'|'id',id,status:'none'|'resolved'|'unavailable'},invoice:{mode:'none'|'id',id,status:'none'|'resolved'|'unavailable',invoice:<本人当前行或[]>}}}`。它是本次新增合同，不是现有DTO。confirmation.orderKey必须等于输入；地址/发票status unavailable或pay失效时，完整视图可只读显示，但quoteReady=false、不能提交。输入提示解析/字段完整性在实现前固化于MarketplaceOrderInput的一个有限restore归一化方法。

读取frame/重算/formatter失败时再做owner lookup，created/processing优先，否则保留真实preview错误；成功返回完整DTO前也再lookup一次，防正常create完成清cache接缝。第二次not_found依旧不是取消或稳定未提交证明。禁止remember/set/cacheOrderInfo/getCacheKey/setCart/againOrder/ledger claim、库存或cart消费；get/has本身不续TTL。认证本来会更新user.last_time（UserAuthServices:76），不能把业务只读称全数据库零写。

## 4. 本地完整上下文与提交前封存

只新增一个小工具 `utils/marketplace-confirmation-draft.js`，使用uni同步storage；不建Vuex模块/通用持久框架。每真实owner一份带版本的单key envelope，key前缀 `marketplace-confirmation:v1:<UID>`，内部按原confirmation key分记录。单次完整JSON写后读回相同原字符串，再parse验证；避免Cache.set的过期标签＋数据多步写及返回值不代表持久成功问题。读取不刷新绝对期限，不把JSON摘要当防篡改/身份验证。

每记录精确保存：schema/ownerUid/key、首次confirmation请求开始时间、draftExpiresAt、revision、editGeneration、原DTO导出的binding（key/idemKey/merId/sourceKind/字符串refs）、phase、地址和发票的显式none/id意图与当时展示快照、fields(mark/contacts/contactsTel/gift_mark/payType)、完整form groups（product/hash/refs/index/定义＋值）、已成功上传URL数组及顺序、必要最小公开view镜像（店铺/商品短展示与输入标签，不含完整cart/productInfo/userInfo/旧报价）、不可变attempts和绝对attempt期限。view来自同次quoteOutput已公开数据；不存密码/token/headers/整个userInfo/成本/内部frame/临时本地图片文件或Promise。真实GET user之后才能读取/显示本UID的内容；view永远不能恢复quoteReady或绕过服务端DTO。

本次采用的明确本地策略（实施合同，当前未实现）：editing完整草稿到首次confirm请求开始＋600秒；每次编辑保存沿原截止值。attempt完整body最多保留首次send-intent＋24小时；到期或明确logout/switch清PII/body后，仍保留最小unknown只查询anchor：schema/ownerUid/key、原binding的merId/sourceKind/字符串refs、firstSendIntentAt、只查询锁。未知anchor没有自动时间淘汰，只有真实owner lookup=created才可清除；not_found/processing/24小时到期/换号/退出都不解除原提交未知锁。缺/过期/损坏记录的key-only入口仍lookup-only，永不默认editing。服务端原600秒及temporary独立期限仍唯一真值；不显示假精确server剩余秒数、不读/续Redis TTL。客户端时钟回退/无原时间视为不可编辑，仍可查真实结果。

容量固定：每owner最多5份完整上下文、单记录1MiB、owner envelope总5MiB，最多20个尚未取得真实created结果的不同send-intent key（含保留完整body的记录）；同key最多5份不同editGeneration的尚未知attempt。达到边界只清已到期且无未知请求的PII或已真实created的记录；不得为通过写入而挤掉未知attempt/anchor或清成editing。20个unknown满额时拒绝新增可能提交的记录，保留所有原key查询责任，不静默淘汰。不能完整保存即明确“无法安全保存，本次尚未发送”，停止create；不截断表单、图片、body或覆盖原attempt。所有清理只动本前缀，不清全站storage。物理浏览器数据被外部删除无法承诺恢复；此时原key只能查结果，不伪造遗失内容。

在现submit/snapshot之后、orderCreate之前：先同步锁当前页，校验owner会话/page/key/generation，取得原snapshotMarketplaceSubmission的完整frozen attempt；把当前全上下文＋submitting/send-intent一次写入并完整读回，验证body字段/类型、原key/idem/refs和同generation字节一致，重新deep-freeze读回对象后才发HTTP。同generation重试只能使用原封存attempt；现live调整产生新generation时追加新不可变attempt，不能删旧可能在途body。存储与服务器不是一个事务，send-intent不能证明HTTP已发出或服务器已收到；因此刷新后无论断点在写前/写后/请求前后，都不自动重发。

所有实际编辑点同步保存：setCheckoutText、form输入/date/time、删图、上传成功回调、invCancel/invChange及新建发票回传、地址选择及真实重读、mark输入、changePayType。空串/0/none必须按字段存在性处理。上传进行中只保存已成功URL，刷新不重传；旧upload回调按owner/epoch/page/key/hash/index/generation拒绝，不把busy标记当成功。onHide/onUnload仅补最后完整checkpoint，不能替代每次输入及提交前保存；持久写失败立即标记不可提交和可见提示，不能静默声称已保存。

## 5. 同一页面状态、路由和真实身份

| 恢复来源 | 真实动作 / 允许状态 |
| --- | --- |
| fresh普通确认 | 当前GET user确认owner；若历史cartId/new/mer与该owner的unknown原binding相同，转原key只查询，不能fresh重建；其余明确新checkout才只一次原fresh confirm。完整DTO绑定和初始编辑上下文写/读回成功后，用uni.redirectTo替换当前确认历史项为同页`?orderKey=<编码原key>`，再允许编辑；绝不先导航丢未存资料。新页只restore，不再checkShipping/confirm。 |
| 有完整未过期editing且从无attempt | key分支在cartId必填检查之前进入；force GET user→owner lookup→只读restore完整DTO→核原binding/定义/refs→hydrate全视图→保留已保存答案/文本/选择→真实记录/能力核与computed→才可editing/quoteReady。 |
| 持久submitting/uncertain/任一send-intent，或记录缺失/损坏 | 复用现uncertain状态＋恢复只查询锁标志，UI标“提交结果待确认”；先GET user再只owner lookup。不调用restore预览、send/retry/adjust/fresh confirm。not_found/processing/错误继续锁，仅提供独立“查询提交结果”和安全返回动作。 |
| 真实created | 解析原key/orderId，提取现completeMarketplaceOrder的详情导航落点供无binding路径复用；不造DTO/refs，先清本owner/key全部完整草稿/attempt后去真实详情；清理失败保留done锁，不能退回editing。 |
| frame过期/定义/选择已变化 | 保留已写普通输入供明确查看，解除不了原提交未知锁；未提交记录显示不可恢复和显式返回购物车，绝不自动新confirm/key。 |
| back/forward/子页返回/onShow | 复用owner/key记录并重新真实身份和结果核，不从URL cartId重建；旧fresh确认历史项已replace。不能把缓存done/orderNo当真实created，仍owner lookup。 |

实际GET user入口复用修正后的 `store.dispatch('USERINFO',true)`，但force必须真的请求网络，不能被{}、缓存UID或上次Promise命中。新增账号代次authRevision和verifiedUid只放现app state内存；每次恢复都强制新请求。LOGIN/不同token UPDATE_LOGIN先使原回包失效并剥除旧checkout PII/body、保留unknown anchor，再清state.userInfo/uid/verifiedUid及USER_INFO/UID缓存；同token日常checkLogin不重复换代。LOGOUT统一做这些清理，不能将旧unknown降成editing或删除其anchor。SETUID/UPDATE_USERINFO普通兼容写不能赋予verifiedUid；只有本次真实GET user回包经代次核后统一写uid、userInfo、两缓存与verifiedUid。USERINFO缺有效uid不命中缓存，失败必须reject/结束，不吞错或永远pending。

`checkLogin`只同步token存在性，不再反灌USER_INFO；无token分支统一LOGOUT。`request.js`每次复制HEADER并捕获实际请求token/代次，发起时无token明确移除旧auth header；受保护响应及401只有原代次仍当前才可应用/触发toLogin，旧401不得退出新账号。LOGIN类noAuth请求也需在其真实API wrapper标记identitySensitive并绑定发起代次，旧登录回包不能覆盖后来会话；公共配置请求不随账号切换误丢。普通H5密码/SMS、微信H5、切号及remoteRegister在LOGIN之后均通过同一真实USERINFO action完成owner核，不能只SETUID或只改页面userInfo。

每个confirmation/restore/lookup/quote/address/invoice/upload/子页回调捕获 `{authRevision,verifiedUid,pageInstance,key,editGeneration,requestRevision}`；成功、catch和finally写状态前都核同生命周期，onUnload/LOGOUT/换号递增失效。hydrate不得把unknown改editing，旧报价不得置quoteReady；H5 storage事件/返回前台只失效并重读身份/结果，不据本地变化自动创建或跨tab承诺原子锁。原server同key幂等仍是持久单笔真值。

## 6. 子页最小接线

确认页打开地址/发票前先完整保存并读回，仅使用本次navigateTo.success的真实channel；去掉“自身必须有opener”前置。channel上下文加本次owner/账号代次/parent实例和原key/mer/generation，仍只是本次返回关联，不是后端授权。返回仅给真实保存ID；接收时核这些值和当前父实例再用本人API重读，不从URL拼地址/抬头。

父页仍是同一实际栈对象时emit＋navigateBack；父页丢失或子页刷新缺channel时，先真实GET user＋本UID原key草稿/owner lookup，只有原editing无attempt上下文才允许继续原编辑；unknown/created转其既定只查询/详情。成功保存后，将真实ID选择提示写入同owner/key完整记录并读回，再redirectTo原key确认页。嵌套地址列表→地址编辑同样回传同上下文；不确定父实例时直接同key恢复，不猜navigateBack目标，不回legacy URL重新confirm。个人资料已保存但结算选择写失败时明确区分，不宣称原选择恢复成功，不另保存或重发资料请求。

## 7. 具体最小文件清单与实施任务

| 文件（R相对路径） | 只做的改动 |
| --- | --- |
| `crmeb/app/api/route/v1.php`；`crmeb/app/api/controller/v1/order/StoreOrderController.php` | 新本人restore路由/controller，原result lookup不改。 |
| `crmeb/app/services/marketplace/order/MarketplaceOrderCreateServices.php`；`crmeb/app/services/marketplace/order/MarketplaceOrderInput.php` | 新只读restore编排/有限提示归一化，lookup前后优先与失败再核；不改原claim/create/DAO。 |
| `crmeb/app/services/order/StoreOrderServices.php` | 抽真实完整formatter、只读同key group恢复，原fresh缓存和legacy自动领券边界保持；computed/quoteOutput/frame/invoice/address协作者优先逐字复用。 |
| `template/uni-app/utils/marketplace-confirmation-draft.js`（新） | 单owner有界完整记录、验证/写读回、不可变attempts、到期PII剥除/只查询anchor及受控清理；无新store/表。 |
| `template/uni-app/utils/util.js` | 同一上传异步合同的必要producer：uploadImageChange增加可选第六参数current函数，限定选图/压缩/延迟及实际HTTP前的原scope，并捕获发起token；内部完成/提示也守卫。参数缺省保留legacy，不自动重放。确认页及已纳入的资料页传各自原scope。 |
| `template/uni-app/api/order.js`；`template/uni-app/pages/goods/order_confirm/index.vue` | restore wrapper、完整共用hydrate、key-only分流/history replace、所有保存点/unknown门/无binding created、生命周期守卫。postOrderComputed的key同时统一encodeURIComponent，现wrapper未编码。 |
| `template/uni-app/store/modules/app.js`；`template/uni-app/libs/login.js`；`template/uni-app/utils/request.js` | 修真实缓存生产消费者/账号代次/force身份/旧响应及401/header隔离。 |
| `template/uni-app/api/user.js`；`template/uni-app/api/public.js`；`template/uni-app/api/api.js` | 仅真实token-producing API的identitySensitive选项；api/api.js限phoneLogin与wechatAppAuth两处，受保护的switchH5Login保持；getUserInfo仍原GET user，不造身份。 |
| `template/uni-app/pages/users/login/index.vue`；`template/uni-app/pages/users/wechat_login/index.vue`；`template/uni-app/pages/users/user_info/index.vue`；`template/uni-app/App.vue` | 实际H5登录/切号/remoteRegister消费统一真实USERINFO action和代次，不只缓存UID；MP/App既有入口仍受中央失效守卫，若其直接UPDATE_USERINFO需同步改成非授权展示/同action，不宣称跨平台已验收。 |
| `template/uni-app/pages/users/components/login_mobile/index.vue`；同目录 `routine_phone.vue`；`template/uni-app/pages/users/binding_phone/index.vue` | 同一身份修复的真实手机/微信绑定登录消费点：LOGIN后统一USERINFO(true)，核同会话/组件生命周期后通知或导航，去除只写旧UID/USER_INFO的授权假象；Routine.getCode等前置回包同样核代次。routine_phone原LOGIN后直接emit分支须先实际USERINFO。不改变手机号/绑定业务、模板或样式。 |
| `template/uni-app/pages/users/user_address_list/index.vue`；`template/uni-app/pages/users/user_address/index.vue`；`template/uni-app/pages/users/user_invoice_form/index.vue` | 原channel关联/父丢失同key恢复/真实ID持久回传；非V1个人资料与旧历史开票分支保持。 |

原Task4子项顺序（状态仅见09的RC清单）：T1登记本r2与完整状态/容量/清理合同；T2后台formatter/只读API及result优先；T3真实身份/cache/request生产消费者；T4单owner持久工具/完整attempt写读回；T5页面完整hydrate、所有编辑保存、unknown/key-only/history；T6子页关联和父丢失路径；T7针对真实差分做静态/source/最小解析检查并审原fresh/legacy逆向兼容；T8按当时实际授权记录正常运行/UI/SQL证据及保留未授权样本。先后端完整DTO和身份边界，再接持久/页面，不能只完成T2就签收刷新恢复。

## 8. 验收与当前禁止运行范围

| 检查项 | 所需真实证据 / 本轮状态 |
| --- | --- |
| A1完整DTO同key | 原生产fresh key、真实owner、完整cart/store/source/forms/user/价格/能力消费一致；无新key/cart/TTL写。源审＋未来合法正常API/DB/cache证据；本轮NOT RUN。 |
| A2完整上下文 | none地址/none发票与有值选择、空串/0、备注联系人/pay/forms/已上传图顺序逐字段恢复；旧局部computed不能充DTO。恢复运行样本停止中，未运行。 |
| A3attempt先于HTTP | 代码证明完整快照/同步写/原字符串读回/deep-freeze在orderCreate之前，失败不发请求；刷新后不能从可编辑字段重构或自动重发。故障断点样本未运行。 |
| A4真实身份 | GET user本次owner与生命周期；缓存旧UID/旧userInfo不能授权，LOGIN/LOGOUT/401/换号和旧异步无法串号；拒绝/换号/故障样本未运行。 |
| A5unknown与结果优先 | 任何已有attempt恢复只lookup；not_found/processing不解锁；created缺frame也去原真实详情且不造binding。恢复/晚回包样本未运行。 |
| A6TTL/容量/history | 原600不续，所有绝对本地期限不滑动；unknown anchor不自动到期/挤掉，logout/switch只剥除PII仍保留锁；旧checkout历史与unknown绑定相同时不fresh；key-only/缺失/损坏/过期均不fresh；只清本prefix。到期/容量/恢复样本未运行。 |
| A7子页 | 同实际父实例channel正常回传、原输入保留；父丢失/嵌套/子页刷新经owner+原key恢复，保存选择失败不冒称成功。相应恢复样本未运行。 |
| A8原责任保持 | 原普通fresh/legacy/历史快照/temporary生成消费/幂等/价格库存表单校验和既有正常12HTTP/商家34/25/9/23责任独立；新改动后只能凭当时真实最小回归记录通过，旧结果不冒作新验证。 |
| A9UI反馈与界面 | “正在恢复/结果待确认/资料已保存但未恢复选择”为持久notice，非按钮；查询/返回/查看订单为独立动作。控件整体居中、边界/禁用/长文字/320/390/1440与实际页面状态实看，按control-quality；本轮无视觉验收。 |

上述实际运行样本均未执行；源码和解析检查的真实结果单独见§11。用户已停止的拒绝、故障、恢复、并发等运行不得由本草案自动恢复；相关验收保持INCOMPLETE，不能改成降低断言/模拟响应/伪造UID/注入组件状态。Root可以先登记并实施已授权的产品源码、进行允许的静态/最小检查，运行范围另按当前用户授权执行。未完成上述完整原责任前，不将“只读restore API完成”或“URL带key”报为完整恢复通过。


## 9. Root独立复核补充

以下为本次实施的约束，相关数值已同步到正文：

1. unknown 容量按本 owner 所有已封存 send-intent 且未取得真实 created 结果的不同 confirmation key 合计，包含仍有完整 body 的记录，最多20。首次 send-intent 之前占用名额；由完整记录转为最小 anchor 不再新增名额。每次同步 writer 必须先读回最新 envelope，核同记录 revision，保留所有原 send-intent/不可变 attempt；旧 checkpoint 不能覆盖更新的提交事实或删除其字段。冲突时本次不发送 HTTP；不声称跨标签 storage 具备原子比较交换，服务端同 key 幂等仍是最终单笔事实。

2. 恢复地址为 none/unavailable 时，hydrate 不调用现 `getaddressInfo(0)` 的默认地址分支；当前本人 id 只经本人 detail 接口重读。不能因为0、原选择失效或父页消失而改选默认地址。现 `getInvoiceList` 不自动选默认发票，但找不到原 id 时会保留旧 `invTitle`；恢复必须明确清成 none 或标记 unresolved，只有本人当前行重读成功才显示为已核。quoteReady 在选择或渠道尚未解决时保持 false。

3. 取消草案的256KiB单记录/1MiB owner 数值，采用每完整记录1MiB、owner envelope合计5MiB，仍最多5个完整上下文、20个未知 key；同 key 最多5份既定不同 generation 的不可变 attempt。容量按实际 UTF-8 JSON 字节核，不是 JavaScript 字符数。必要 view 镜像只保留店铺/商品短展示与输入标签，不保存完整 cart/productInfo/userInfo/旧报价；实际完整 DTO 仍须从真实 restore 获取。五份接近原65535B上限的合法表单 attempt 已超过旧256KiB，不能为适配本地限额删除旧未知请求、截断答案、降低500 refs/每商品10字段原界限，或挪用缓存展示当完整服务端 DTO。固定本地限额不保证容纳所有公开展示数据；实际存储配额/写入/读回失败仍清楚提示并停止本次 HTTP，不缩窄服务端业务输入合同。


## 10. 并行实现的固定接口与写入责任

- Root唯一维护本工作树03/09和本记录、Git暂存/提交及任何实际检查/运行。Main与AUX均不得由本分支代理修改。产品代理仅在已分配的新工作树文件内写源码，不执行测试、import/CLI、Docker、SQL、网络或浏览器，不读取原Run/私有材料，不提交。
- 真实身份接口继续 `store.dispatch('USERINFO', true)`，resolve真实本次接口返回的user对象，失败必须reject。`store.state.app.authRevision` 为非负安全整数；`store.state.app.verifiedUid` 为真实GET user核过的规范正十进制字符串，未核为 `''`。普通SETUID/UPDATE_USERINFO不能设verifiedUid。页面与草稿工具都用规范字符串ownerUid；现展示用uid兼容字段保持。
- 身份代理负责app模块、login/request生产消费者和真实登录/切号入口。提交页代理负责持久工具、order API、确认页及三个地址/发票子页。后端代理只负责五个既定PHP调用点。跨代理契约变化须先回Root更新本记录，再写依赖代码，不各自猜接口。
- 草稿工具对身份模块仅导出同步 `pruneMarketplaceConfirmationOwner(ownerUid): boolean`：清本owner的完整草稿/PII/body，原send-intent转最小unknown anchor，保留原未知key责任；不存在记录视为成功，损坏/配额/IO失败返回false，不丢未知内容、不阻止退出身份。身份模块先定位旧owner（已核verifiedUid优先，旧uid只可作本机清理提示、不可授权恢复），清理后无论结果都使旧身份/回包失效；`state.app.confirmationCleanupFailed` 记录失败，确认页据此显示持久notice并停止新create，成功清理才解除该失败标记。该方法不得读取/显示其他owner的业务上下文，不增加Vuex模块或通用持久框架。
- 后端不得用fresh confirm、remember/set或延TTL恢复；前端不得从URL/缓存UID造身份或完整DTO。真实created优先、unknown只查询、完整attempt先写读回再HTTP、none不改默认、原fresh/legacy兼容及原未完成范围均保持。
- 当前不可用的原payType意图保持未解决，不阻断完整只读DTO恢复、不替换客户端选择。内部报价复用现validatePayment及真实当前配置判断；仅其明确业务ApiException可转为空渠道基准计算，当前有效渠道保持原值，配置或基础设施错误继续失败。关闭的offline不得继续套用offline免运费；不新增响应schema、不改create支付验证、不发起支付。fresh完整DTO共用组装分为商品/表单/价格前段与用户/能力尾段，原唯一cacheOrderInfo仍在两段之间，legacy自动领券仍只在fresh末尾。
- 身份代理文件清单扩至上述13个既定调用点（原10文件加手机弹层、routine_phone与binding_phone）；仅完成同一真实USERINFO/代次合同，不能顺带改其他业务。libs/routine.js现旧展示写入不能授予verifiedUid，本轮不据其兼容显示声称MP已完整验收。
- 原仍存活页面明确确认的live“调整信息”代次继续保留；这不构成刷新恢复或解除未知提交锁。子页仅在实际原父栈对象/真实channel、owner/账号代次、parent实例/key/binding/新编辑generation均匹配，且父页内存原不可变attempt与本owner封存副本全等时，允许同一live编辑往返；仍保留原attempt和原同key幂等责任，不自动创建/重发。缺少任一真实关联，或本地仅剩unknown anchor，一律同key只查询；持久send-intent刷新仍不能恢复为editing。
- 提交页代理另负责上述util.js的唯一uploadImageChange方法（其余上传方法不扩大），并继续负责三子页onShow真实USERINFO/owner lookup重核。onShow不得重跑初始化而丢真实channel或父实例；重核期间禁止旧上下文编辑/发送/显示，身份或原结果失效按同key只查询/真实详情处理，不能由内存predicate冒称本次真实身份核。首次onShow可等待同次已发起的onLoad身份/结果请求。三个子页的原生/HTTP success、catch、finally及Tips延迟回调仍须同scope后才能改状态/提示，不只成功分支。Root负责资料页调用点传同可选current参数；不改其模板或资料业务。

## 11. 实际源码检查点

2026-10-16后端五PHP已实现并经Root与独立源码复核；Root的实际PHP8.2语法检查5/5 native0、stderr空，前后字节相同。收据位于仓库外 `artifacts/ms4-confirmation-restore-source/20261016-r1/php-syntax-result.json`，摘要 `c5c90ced1fff0f33379adf071f2460db230007c6ea5225e607bac105e259cc43`。该后端检查仅SOURCE/PARSE，不加载App/SQL/HTTP。后继进度只按09的RC清单及本节实际证据，不以Main旧结果代替本源运行。

前端20文件已实施；身份13文件和提交/子页/上传7文件分别经独立有限SOURCE复核，Root核当前差分及必要生产消费者。上传producer、三子页onShow真实身份/owner lookup、完整回调守卫及原业务保持均纳入最终复核。Root实际执行现有官方三平台预处理、Vue模板编译、Node/Babel脚本解析、本地导入解析及样式编译：11 SFC与9 JS在h5/mp-weixin/app-plus共60组通过，issues为空，20源前后字节相同。最终 `ui-syntax-result-r4.json` 摘要 `78b5455dab40812205b588d12c9817d4b247dedce15865814d1d8b36906caddf`，实际Node22.22.2，仅使用既有离线依赖。r3同样20/60通过，摘要 `080454650e3131f4600779275aae81f20a07de5a807c7cb14d3b4ef6b1c32702`；其后只清api/user.js的27行注释尾空格，r4不改检查断言，仅换新输出文件名并重新核最终字节。

最初r1检查因遗漏现有Vue CLI的目录index.vue解析而报12项本地导入缺失，原FAIL源码/收据保持；Root核实际目录与既有官方解析配置后，r2仅补index.vue候选，19源57组通过，原收据保持。后继r3纳入新增必要util.js producer成为20源60组。以上检查不执行组件方法、登录、故障/换号样本、应用、SQL、HTTP或DOM；恢复运行、三端及视觉验收仍NOT RUN，RC8与完整Task4/MS4未完成。


## 12. 正常 H5 successor适配：实施合同与后续任务

2026-10-17，基于当前 Restore产品源码与Main/R6联调入口的有限只读比较补充。仅本节设计已记录，尚未适配helper/observer、生成新build/profile或执行正常H5；不改写§11历史证据，不勾RC8，不授权恢复已停止样本。实施须在Root完成现R6原Run正常close及独立zero后，保留Main已有修正再整合；本次只编辑本记录，Main和冻结profile不动。

### 12.1 本次真正的正常路径

正常V1确认已实际改为：当前真实USERINFO(true) → 原check_shipping/fresh confirm一次 → 完整DTO/草稿同步保存并读回 → redirectTo同页仅orderKey → 再真实USERINFO(true)及owner lookup → 无attempt的完整editing才调用只读restore → 共用完整DTO hydrate、本人选择/能力核对与真实computed → 完整不可变attempt封存读回 → 原单次create → 真实未付详情。此自动history替换是正常fresh路径的一部分，不是执行浏览器刷新/恢复样本；不得为沿用旧H5断言取消redirect、跳过lookup/restore或注入组件状态。

源接缝：Restore template/uni-app/pages/goods/order_confirm/index.vue:1734–1748、871–934、1038–1053；api/order.js:247–263。原fresh仍由真实cart/SKU/数量及本商家选择生成唯一原key；后继只能沿同key，不再confirm/cache/set/new cart/again，不续服务端600秒。created/processing始终先按真实owner结果处理，不强求预览或frame。

### 12.2 三层观察和原key的完整绑定

| 层与必要Files/方法 | 有限适配合同 |
| --- | --- |
| 新successor browser-v1/customer-network-observer.mjs的template及真实response解析 | 只新增两个POST模板 /api/order/confirmation/:confirmation 与 /api/order/confirmation/:confirmation/restore，沿真实编码key解析，不加任意URL白名单。key须来自本次真实fresh confirm；lookup正文为原始空JSON对象{}，restore仅addressId/invoice_id/payType三字段，地址/发票沿本人实际ID或显式0，不能从oracle重造请求。原始请求/响应字节、完整hash、request_id及实际来源保持；restore从data.confirmation读取完整DTO，不当computed.result或新fresh确认覆盖原绑定。 |
| Main help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php的m4ch_stage_target、m4ch_stage_network | 正常阶段仅B3纳入上述两个实际POST模板；GET user继续非空且逐条核真实本UID，不改成恰好一次。PHP assoc解码{}为[]，须仅对lookup结合原始post_data核“空object”形状，不全局放宽POST数组。restore精确key/type交给既定有限输入合同；保留原JSON/envelope/实际字节及同request_id投影比对。两新模板public nested_status仍NULL，真实data.state在私有完整capture核验，不伪造computed/create的嵌套SUCCESS。 |
| 同helper的m4ch_stage_frame及正常B3/Final DTO、selection核验 | 正常预览要求真实lookup/restore均为not_found且key等于原fresh key；unexpected created/processing如实终止本预览阶段，不假造not_found。核restore.confirmation.orderKey、V1/mer/source、完整字符串refs、product/SKU/qty、两组表单定义/hash、用户与报价消费和原confirm/实际owner cache frame一致；selection三字段提示与真实本人记录、none/resolved/unavailable保持原语义。用实际restore DTO及最终computed绑定本次所见视图和报价，不能只允许新路由而不验内容，不能复用旧价格为授权。 |
| Main help/audit-regressions/isolated/ms4-customer-h5.php的m4ch_http_ui_network、m4ch_http_ui_capture | 只同步新增上述两个有限network模板。confirm-filled接受正常redirect后的同源 /pages/goods/order_confirm/index?orderKey=原key，严格唯一query键、规范解码key、无额外身份/凭据参数；不再强求已被正常history替换的cartId/mer_id。入口不把URL本身当授权：原key、mer及refs最终与本次真实fresh/lookup/restore/frame和实际confirm-filled capture完整关联。 |

时序必须无循环：confirm-filled capture通常先于B3 stage封存。入口在写capture时先核同源/有限URL/完整I8及真实DOM、network；B3 oracle再用已经存在的原始network/capture将URL orderKey与原confirm key/frame核等，最终coverage重核关联。不得让入口等待未来stage-B3文件、预先写假的key/DTO或改动已封存capture。R6 browser的sceneMatches(confirm-filled)本已核可编辑DOM而未强制cartId，不为此无谓重写；其他真实动作/截图/review门禁保持。

新增观察仍只在原私有capture/pipe内保存完整body；公开投影保持既定字段和私密hash prefix12，不能把本地draft、PII/body/token或完整私有消息复制到公共evidence。未实际走到的地址编辑、发票新建或upload接口不因此整批加白；原child-return/onShow/owner核和upload第六current合同必须随产品源码保留，但不据正常下单结果声称这些停止中的样本已验收。

### 12.3 保持的断言、新来源和实施顺序

原25个完整业务表＋7辅助表、真实默认PDO/原owner认证允许增量、B0–B4及Final、原sealed inputs/fixture身份与完整SQL读回继续保持。正常仍为两商家/三cart行、仅目标商家两行创建一笔未付订单；原78.56总额、8.01运费、24.25成本、字符串0表单答案、发票/地址/完整form/hash/refs、未选cart保留、ledger/reserve/root/SKU/日志/库存守恒等已有断言不因新增只读POST而降低。lookup/restore不造cart、frame、ledger claim或库存消费；实际认证的user.last_time按原owner_auth_only规则核验，不冒称数据库绝对零写。新正常B3必须仍在唯一create之前核原key/完整frame，B4仍核真实create wire、唯一保存结果和真实详情，不能靠localStorage或UI显示冒充已保存订单。

当前Restore中的H5 helper仍为91294B，Main为91453B；Main:474跨producer environment使用m4ch_http_same_environment的修正必须保留，不能整目录复制回退。R6已采用的capture structuredClone比较及native截图成功后才record的顺序也须由新successor原样继承，不修改旧R6 source/收据来冒称新产品验证。

后续实施顺序如下，状态仍只由09现RC执行清单记录，本节不另设完成勾选：

1. Root审本合同，完成原R6正常关闭/独立zero后整合已核5PHP与20前端，保留13身份/7提交子页上传的完整配套；不遗漏新增marketplace-confirmation-draft.js或util producer。user.js及手机登录弹层已有CRLF→LF、注释尾空白变化必须如实纳入最终源字节，不能在build identity中口头忽略。
2. 仅依§12.2适配observer/Main H5 helper/entry及必要DTO/frame oracle，独立SOURCE与允许的最小语法/来源检查后才形成新clean源。原HTTP shared/helper和原12HTTP责任不因H5新增模板改成另一套接口；不扩大原PHP source-extra白名单或业务DML权限。
3. 真正新build并记录完整前端source/tool来源、实际输出全文件/目录manifest及摘要。旧b803 build的“所有前端字节相同”已被20文件及新增utility改变，不能再借旧复用收据；不猜新source/asset数量或为通过而放宽旧固定数量门禁，按实际新产物登记完整闭集和正常质量门禁。
4. 用最终Main产品/H5入口helper和实际新build创建新source successor/profile/freeze，完整绑定source、dist、public-copy、inputs、原native资源与新文件校验/初始化。保留原7＋20 profile来源闭集及已有正常停止/双网/十卷保护；若实际新增profile文件，须先明确更新闭集，不静默扩大。原R6/旧build/旧Run及失败、关闭、zero证据保留真实来源，不改署。
5. 只在当时实际授权范围执行新的正常完整H5/SQL/视觉、原生正常close及独立zero并分别记证据。新普通流程通过也不等于完整刷新恢复、身份故障、跨号、过期/容量、子页父丢失或未知提交样本通过；用户已停止的刷新/恢复/拒绝/冲突/故障/并发等样本继续NOT RUN。RC8、Task4完整恢复、三端视觉和MS4未完成责任不得由本设计或新正常结果代替。

### 12.4 R7实际source profile与真实材料检查点

Root在已集成的clean Main `182186945d6afa9dddb9b1b804183b6bdbc15911`实际采用仓库外 `prep/customer-h5-restore-profile-r1/` 三源；全文读回与独立有限SOURCE PASS后，单AST真实通过，三prep及Main两PHP前后字节保持。builder为30717B/摘要 `1fbbc712658f8363b9036552b4588f5e018869ffbc3b4f89b1f51639142028c7`，解析收据在同级 `customer-h5-restore-profile-r1-source-check/source-parse-result.json`，摘要 `714234fbf5b4026d2ea25035ebceb91a00390c7f4de095f59f21c2db1149780b`。

随后create-only builder原生会话97340真实exit0，生成 `H:/天俪商城/artifacts/ms4-customer-http-preparation/candidate-h5-r7` 的完整27文件/三目录；仅先写22源码，原materials producer真实重采四metadata、读回后才一次写derivation。材料保留实际新build `restore-context-20261017-r1` 的原HEAD `38a14b889bf2a726ccd86f4f0ea1f1192370fa87`、3815B报告摘要 `3271b9ff34ba3da0a08654a1742fe5aa6438403b3117944374219f707eff2687`，并真实重核当前1821869完整545前端路径/blob/原字节及原/副本29930工具相符；没有重编译、改署报告或调用仅接受原build HEAD的verify_frozen_inputs。

实际public-source为2536文件/279目录、h5-dist为334文件/23目录，完整files/directories含空目录及两批准输入按原schema采集；334产物10378944B/库存签名 `3dbc1438339156e1bedf582c28cd6c7708b6cac63ea68561a07a96032400d8e6`。新materials-result1748B/摘要 `f4a1c91e63745edc79609501e90cddf1c8b8d114592db40620c3ea71612fc262`，h5-derivation29543B/摘要 `fbb6aad50bb8522f9cbf6c14eacb2c9e2fcfadce8c323898e60a2e5d0ef8b727`。

Root另以生成后的实际字节独立逆回27文件，原7逐字、21文件未变；实际变化只有H5-README、observer、h5-derivation、h5-materials-result、manifests/h5-dist及ms4-h5-materials。四个真实重新采集metadata的逆回明确依赖硬pin且首尾未变的R6原字节preimage，未将旧收据当新结果；其余源码由有限四literal/两observer hunk/README前缀/derivation字段完整逆回。生成materials producer AST通过，候选/原源和13项build provenance前后保持，独立结果在 `prep/customer-h5-restore-profile-r1-source-check/profile-result.json`，摘要 `91f183551ba5653c2d1cef0448725a295609fe550d9f3edf4846e68ddf7a3e03`。

本检查点只证明新SOURCE和实际材料形成；没有新freeze/Run/HTTP/DOM/SQL、27原生JPEG或Final32验收。390截图超时与原1440截取观察仍保留，未改driver/bridge/host/bootstrap/close/zero来掩盖问题，也未使用校准图代替业务图。原R6保持已关闭，原HC2/EK3/RC8/SD3/SP3、完整恢复/交易/支付/履约/商家和停止样本责任保持；后继新clean来源的freeze将另核完整当前字节，不把本次追加文档当作前述1821869材料来源。
