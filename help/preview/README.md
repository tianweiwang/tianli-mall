# 本机多商户预览

使用当前工作树的 PHP、迁移和三端页面，配套专用 MySQL、Redis 和数据卷。后端服务仅接入专用内部网络；nginx 同时接入该内部网络和专用入口网络，对宿主机仅绑定 `127.0.0.1:18180`。所有账号、商品和订单均为合成数据；测试支付不扣真实资金。

```powershell
python -X utf8 help/preview/preview.py prepare
python -X utf8 help/preview/preview.py initialize
python -X utf8 help/preview/preview.py storefront
python -X utf8 help/preview/build-frontends.py --frontend merchant
python -X utf8 help/preview/build-frontends.py --frontend admin
python -X utf8 help/preview/build-frontends.py --frontend h5 --allow-docker
python -X utf8 help/preview/preview.py serve
python -X utf8 help/preview/preview.py timers
python -X utf8 help/preview/preview.py queue
python -X utf8 help/preview/preview.py smoke
python -X utf8 help/preview/preview.py accounts-file
```

构建复用本机已有 Node、HBuilderX 和依赖缓存；首次在其他机器使用时先调整 `build-frontends.py` 顶部工具路径。没有依赖下载或生产部署步骤。`prepare` 只用于新的运行目录，已有实例使用 `status` 检查，不重复初始化。

## 当前候选：平台首页统计修复（R51，2026-10-28）

当前入口仍为 [18198平台首页](http://127.0.0.1:18198/admin/index)，顾客小程序继续使用r49工程。PHP已更新为 `sha256:60fc5ae417e1ed7f7b082298ce6c087e7a3fd41df4df6a636e8c619766294c80`，应用来源 `ca6eac4013cfb6e62994459d192d6078cf82c005`；继续使用R50网关 `sha256:07d3691588bb832828e1dc878612669c2c2e92172ddce9dc6c2ec6fbdf904403`。下列R48–R50记录保留各自当时镜像与结果，初始state.base_head不重写。

修复首页默认“30天”订单图表的两个真实PHP兼容问题：无成交日期的金额从空字符串改为零，上期没有订单时的null合计在原round调用处按零处理。仅修改两个表达式，保留真实成交金额、原SQL/筛选、日期范围、两位精度、其它周期及原页面。原正常API的空字符串400、首修后null400及关联日志均保留；最终同一正常回归通过，30个日期中29天为零，成交日仍为原DAO的2单/60.45元。平台原首页实际刷新后已显示柱线图，销售额和订单量与该记录一致；其它周期没有新增运行验收。

使用原装包器和PHP Dockerfile实际构建，镜像3814个源文件与新context一致，三端产物与R50保持。当前专用testing预览仅更新state.images.PHP、正常替换PHP服务并调用原网关reload解析新上游；原Nginx容器/镜像、数据库/Redis及卷保持。捕获的订单/支付/库存/履约/商品、评价/附件/审计和顾客资产字段保持；144容器中其余143个所采字段及完整mount对象保持。旧18180以R50末快照为真实基线，42字段与本轮末快照一致。原reader临时目录与挂载枚举顺序的观察失败亦保留，未改变应用、挂载或业务断言来消除失败。证据在原trade目录 `r51-*`/`r51b-*` 及两个 `multi-merchant-home-chart-package-20261028-r51*` 包目录；这不代签全库、全周期、完整阶段、真机或生产发布，停止样本仍为NOT RUN。

## 评价与网关交付（R50，2026-10-28）

18198继续作为当前候选入口，小程序仍使用下文r49工程，无需重建或重新登录。当前Nginx为 `sha256:07d3691588bb832828e1dc878612669c2c2e92172ddce9dc6c2ec6fbdf904403`，PHP仍为R48的 `sha256:4641d40cfb1149740aa460cd18466f30d25ebc32faf9dc9008d0ae0b904c779e`。R48/R49段落记录各自当时交付；活动state仅更新 `images.NGINX`，初始 `base_head` 保留，新网关来源为 `5f3c15bdbae72afc70fb9ca4175a3ae32b75cc72`。

本轮修复了镜像网关把内部路由参数加入查询串，导致正常评价读取返回503的问题。五个API应用改用原分离预览已有的PATH_INFO转发方式，保留真实客户端查询串、身份头、request_id及原接口校验；其他静态、SPA、PHP与WebSocket配置原字节保持。同一后端的原503、候选200、活动18198复测200均保留。原Dockerfile实际构建新镜像，公共文件与原context全部同；只通过原compose助手替换当前预览的一个网关，临时18199网关已正常关闭移除，未修改PHP、数据库权限、表结构或前端构建。此为本机testing预览升级，不是production或release apply。

评价已可实际操作：小程序“我的→订单→20.15元已收订单→商品评价→查看商品评价”可查看本轮评价1；平台“经营商家→商品评价”审核，商家“商品评价”回复。也可直接打开[平台评价详情](http://127.0.0.1:18198/admin/marketplace/reviews/1)或[商家评价详情](http://127.0.0.1:18198/merchant/#/reviews/1)。该记录来自原付款订单3／源商品项3，商品4星、服务5星，正文和回复均明确标为本机合成内容。

已通过小程序原表单单次提交、待审阶段匿名公开列表/统计保持、平台原页面审核通过、商家原页面保存回复，以及小程序自己的评价记录和商品5公开评价页回读。待审时公开计数为0；通过后全部1/中评1/好评0/差评0，按原规则显示4星、好评率0%，不把4/5评分改写为双5。原文、评分和图片空数组在审核/回复后保持；文字与图片本来选填，本轮没有新选图或图片上传验收。公众结果沿原12字段脱敏展示。

本轮捕获的订单/支付/库存/履约/商品和用户资产字段保持，未创建新订单或改库存；只新增评价及其审核、回复和必要审计。144容器中仅网关身份/镜像/启动时间改变，其余143个所选字段及完整挂载对象保持；旧18180沿最近R49权威基线的42字段与本轮末读回相同。证据在原trade目录 `r50-*` 和新包目录 `multi-merchant-gateway-package-20261028-r50`，原失败与历史回执保留。完整N2/D19、正式微信/新选图隐私条件/真机、真实资金和整阶段验收仍待，停止的负向/故障/并发/恢复/K样本保持NOT RUN。

另发现平台首页订单图表在无成交日期使用空字符串金额而触发PHP类型错误，已留实际日志，下一项修复此图表；评价页面不受该问题阻断。

## 统一镜像首次交付（R48，2026-10-28）

最新的独立候选入口为 **http://127.0.0.1:18198/**。它使用原装包器和原 Dockerfile 真正构建的当前应用及三端静态文件；日常 PHP/Nginx 不绑定宿主应用源码、vendor 或前端目录。原18180实例及其订单、小程序工程和浏览器草稿继续保留，两实例数据独立。

| 入口 | 地址 |
| --- | --- |
| 顾客 H5 | http://127.0.0.1:18198/ |
| 平台后台 | http://127.0.0.1:18198/admin/ |
| 商家后台 | http://127.0.0.1:18198/merchant/#/login |
| 本轮已收货样例 | http://127.0.0.1:18198/merchant/#/orders/1 |

本轮运行目录为 `H:/天俪商城/artifacts/multi-merchant-current-image-runtime-20261028-r1`。三角色合成账号在该目录的 `private/preview-accounts.json`，不要提交或复制到公开记录。文件继承其父目录的受保护权限，当前用户为所有者，保留 OWNER RIGHTS、SYSTEM 和 Administrators；不是仅当前用户一个 ACL 条目。使用对应实例的账号，原18180账号及用户本人设置的商家3密码不适用于此新库。

正常操作顺序为：顾客选择“基础体验商品”及数量后下单，在明确标注的测试收银台付款；商家进入本店订单，填发货数量及合成物流，预览后确认；顾客进入包裹确认收货。已保存样例根单 `cp638202983510704128` 为两件×20.15元、运费0、测试支付40.30元，包裹 `cf638204446433607680` 已收2件，待发/待收均0；只需查看此样例，不重复支付、发货或收货。

实际验证范围：原正常接口建单、测试支付及顾客收货，商家原浏览器页面发货，三角色认证读取各阶段保存状态/金额/数量一致。库存仅创建时100→98，随后保持98；一笔预留、一次成功测试支付及两次履约动作的商品/SKU/件号关联已核对。公开H5首页及商家桌面结果已查看；本轮没有顾客认证H5或新候选小程序操作，原r35小程序仍连接18180，不能拿其历史结果代签18198。

包根 `artifacts/multi-merchant-current-image-package-20261028-r1` 绑定受控来源 `f86c99ff142657226c74c3cd96408ad88931fc40`，PHP镜像为 `sha256:4641d40cfb1149740aa460cd18466f30d25ebc32faf9dc9008d0ae0b904c779e`（PHP8.2.35），Nginx镜像为 `sha256:675c2aa8e43f42a2d982cee0b80e447cff8366c9a10aaf6c7095c72b9ea1a0c3`。全部3814应用来源文件及2357公共静态文件与context核同；vendor由原lock生成。6个实际入口/深链及其36个引用资产核200及对应字节，不宣称每个文件都经HTTP请求。Admin/merchant产物摘要为本次装包观察，H5沿原afterEmit记录，不补签正式copy资格。

该实例为 `testing/full-runtime`，多商户、交易、测试支付、售后及私有聊天写入开启，配额关闭。五个Supervisor程序RUNNING及UID33健康检查已通过，只证明进程就绪，不表示每种任务已消费。镜像预置9个私有目录；实际卷由原bootstrap只准备6个常规域，均33:33/0700，视频分片、扫描和云视频3域未准备（空root；视频开关关闭，扫描没有独立enabled开关）。首次额外观察器错误要求运行卷必有9域的失败保留，未通过建目录或修改应用权限使其通过。一次性bootstrap仍使用受控只读源和独立vendor卷；不能把日常无源码绑定泛称全部容器无绑定。

原140容器所选字段及446个完整挂载对象保持，18180捕获的42字段及23订单/25明细保持。证据位于运行目录的 `normal-trade-verification.json`、`runtime-observation.json`、`source-runtime-http-verification.json`、`daily-private-directory-permissions.json`、`environment-preservation.json` 和两份独立review；旧快照在原trade目录的 `r48-*`。临时公开页已关闭，保留原3页及新商家结果页。新候选4服务保留供操作；不要重复prepare/initialize或重建旧18180。

本轮没有production部署、R44发布apply/APPLIED、真实收款或实物寄送，也未完成完整MS6/fresh B/人工签收。正式微信配对配置、账号隐私接口申报、新选图及真机、真实支付与结算Q4/Q5、历史迁移和最终完整回归继续待办；停止的负向/故障/并发/恢复/K样本保持NOT RUN。首次只读镜像观察器的opcache临时锁失败、运行观察器类型错误及私有配置观察器键错误均保留为准备/观察问题，不冒作业务故障测试。

## 当前小程序：连接统一镜像（R49，2026-10-28）

当前可操作的小程序工程为 `H:/天俪商城/artifacts/multi-merchant-miniprogram-20261028-r49/mp-weixin/dist`，连接 **18198** 的统一镜像候选，合成顾客已通过原账号页登录。旧r35工程及18180数据保留；两个工程同名，务必按绝对路径打开，不能只凭窗口标题判断接口环境：

```powershell
wechatide -c Codex open_project_window --project 'H:/天俪商城/artifacts/multi-merchant-miniprogram-20261028-r49/mp-weixin/dist' --window-mode liteMode
```

小程序正常路径为：首页“基础体验商品”→“立即购买”→规格数量确认→提交订单→选择付款方式→测试支付→确认测试支付（不扣款）；商家从18198本店订单发货后，顾客在原订单“查看包裹”→“确认当前可收全部”→弹窗“确认提交”。账号继续使用18198运行目录的私有合成账号文件，不使用旧18180密码。

本轮已通过上述小程序登录、创建、测试支付和实际收货控件，商家原浏览器页面确认发货。新根单 `cp638214943312707584`、包裹 `cf638215872015499264` 为一件20.15元、运费0；返回原订单已显示已发1/已收1、待发0/待收0，原生反馈“收货结果已保存”，无待确认请求或读取错误。可从“我的→订单”查看该已完成样例，勿重复提交。平台、商家与顾客认证读取四阶段保存事实一致；库存98→97后保持，仅一笔新预留，支付和履约关联已核对。原R48两条订单记录/两明细及已采集付款/库存/履约记录保持；原18180捕获42字段和144容器所选字段/455完整挂载对象保持，不扩大为全库、全Docker inspect或r35完整历史文件树验收。

本轮复用现有页面和业务源码，原构建器在新目录生成开发AppID/API配置及产物，未修改原工程或三端源码。565输入与r35同，实际编译指向18198、编译JS无旧18180 origin；构建与原生正常交易分别验证。首次新窗口自动化查询等待超时后，读取实际窗口已为首页，再查询同一工程正常；没有重启、重新扫码或改安全设置。正式微信、新选图隐私条件、真机、真实资金和完整阶段验收继续待办，停止的负向/故障/并发/恢复/K样本保持NOT RUN。证据在原trade目录 `r49-*`，包括构建来源、正常交易及两份独立复核。

## 微信小程序本机预览

使用当前 uni-app 源码和现有 Node 22/HBuilderX 插件编译。输出目录必须是仓库外的新目录；AppID 与接口地址显式指定。以下为本机已选接口测试号和18180合成预览的命令：

```powershell
$mpOutput = Join-Path 'H:/天俪商城/artifacts' ('mall-mp-' + (Get-Date -Format 'yyyyMMddHHmmss'))
python -B -X utf8 help/preview/build-frontends.py --frontend mp-weixin --mp-output $mpOutput --api-origin http://127.0.0.1:18180 --appid wxbb73db82f69581b3 --without-live-plugin
wechatide -c Codex open_project_window --project (Join-Path $mpOutput 'mp-weixin/dist') --window-mode liteMode
```

`--without-live-plugin` 仅供未开通原微信直播插件的本机开发预览：在一次性输入副本去掉该插件声明，保留原源码/默认构建；此预览不含直播，直播资格与正式发布继续待验。工具不会安装依赖、改原AppID、上传代码或发布。编译成功还需在开发者工具实际检查页面，不能当作微信登录、下单或真机验收。

小程序已有账号可直接操作：从“我的 → 请点击登录 → 手机号登录”，点击“账号密码登录”进入原账号表单；账号页也可用“快速登录”切回短信模式。新入口仅在小程序手机号登录模式及原手机号登录开关开启时显示，绑定手机流程保持。账号输入提示和上限与原5至16位账号校验一致，短信手机号仍限11位。账号页仍需自行阅读并勾选原协议，保留原服务端认证和登录后返回路径。

r25已实际完成390宽度的上述普通导航；320宽度以原页面导航进入手机号页后，真实点击新入口并切换短信模式。两宽度入口均居中、未溢出，账号页协议初始未勾选，原短信页已由redirect退出。受影响源码与MP/H5/APP预处理检查、完整小程序构建及构建来源核对通过；本次没有提交登录、发送短信或写订单，前后业务资料保持。H5/APP账号提示与长度为共用源码变化，其运行页面未由本批代验。证据见同一trade目录 `r25-login-entry-*`、`r25-sms-entry-*`、`r25-account-*`。本次只签登录入口，不代签订单9收货、微信授权或真机。

本机默认使用 `liteMode`，窗口仅显示模拟器；需要编辑器和调试器时改用 `fullMode`。已有窗口不会因重复打开而切换模式，先保存页面草稿并正常关闭该工程窗口，再按所需模式打开。

MP的商品列表、详情和规格接口会将已登记的 `/uploads/` 图片/视频地址补为所选API origin；数据库引用、金额、库存和其它业务文本保持。首页主题没有搜索/组合导航时，会按当前状态栏和胶囊高度留出内容空间。H5保持原同源行为。本机HTTP地址用于开发者工具；真机及发布需相应可达地址和正式配置。

2026-10-27 已在微信开发者工具 r12 工程通过本机账号密码登录、单店确认、创建订单、测试支付和原生包裹收货。商品5/SKU104实际生成订单10，1件20.15元；商家原认证接口发出包裹11，小程序点击“查看包裹 → 确认当前可收全部 → 确认提交”，三端读回均已收货，商品与SKU库存98→97且仅一次预留。商家发货本轮为HTTP接口，原商家浏览器流程另有此前验证；此结果不是微信OAuth或真机验收。源码修复原密码输入的原生掩码、下单初始数据读取及子包模板事件编译问题；确认页、购物车和包裹图片沿用原公开路径补全。

现有商品7的订单9（测试支付12.50元、一件）已从原商家页面发出包裹12，r31补齐小程序实际收货：打开该订单“查看包裹”，核对保存商品/表单与物流，点击“确认当前可收全部”，在原弹窗确认提交。当前三端均为已发1件、已收1件、待收0件，库存保持19；合成物流为“预览合成物流”，不会寄出真实商品。唯一新增receive operation10及range10正确关联原ship7/reserve12，未新建订单、明细、预留或支付。证据见同一trade目录r24发货记录和r31-order9-before/after.json、r31-old-order9-data-before/after.json、r31-receipt-saved-320.json。继续时先读本单已保存结果，勿重复发货、付款或收货。

商品7仍保留必填参考图片；所选接口测试号实际选图返回 `api scope is not declared in the privacy agreement`，须满足账号隐私接口申报条件后继续，未取消必填或替换上传。正式微信授权还需后台配对的AppID/AppSecret，当前18180未配置；小程序原生新选图上传、真机及正式发布仍待。后端公开上传已接入18180，HTTP/H5正常上传与小程序历史图片读取的验证范围见下方共用图片上传说明。重新编译后打开新的输出工程，保留旧失败证据。

此前r31/r32交易验证的本机工程为 `H:/天俪商城/artifacts/multi-merchant-miniprogram-20261027-r31/mp-weixin/dist`，默认仅模拟器小窗口，显示比例使用自适应，其320验证采用iPhone 5。r14按原商城订单页统一主题状态头、文字层级、信息行与胶囊按钮；售后/评价位于订单内容后，收银台金额恢复原50rpx字号。开发者工具390及320宽度检查了已收订单、包裹和已付款收银台的禁用状态；r30/r31补齐两宽度正常可收包裹控件及原确认收货，320确认弹窗和成功反馈均实际通过。不代表全站、H5新样式或真机视觉验收。

r35为最近完成本次有限正常页面验证的本机工程：`H:/天俪商城/artifacts/multi-merchant-miniprogram-20261027-r35/mp-weixin/dist`。共享包裹组件仅在已验证根单未付款、摘要及包裹均为null时，按待付款/已取消显示一条正常说明，加载、读取错误、已付款和原包裹分支保持。三端模板/JS预处理、实际DTO和完整MP构建已通过，源与构建副本/产物已核同。用户本次确认仅本机合成预览协议后，原合成账号经原登录页登录；随后从商品8原规格/确认/提交控件创建唯一根单17（cp638131142997835776），基础款SKU107一件19.90元、运费0，未执行付款。320/390逻辑宽度下分别核到“订单待付款，暂无履约包裹”，原取消按钮及确认只提交一次，取消后两宽度均显示“订单已取消，暂无履约包裹”，付款/取消按钮消失。三端读回paid0/is_cancel1、同规格数量及19.90元，库存8→7→8；预留19/释放20均为顾客3、数量1且正确关联。原已付款根单14在两宽度仍显示已收1件和原包裹/物流，旧16单及明细摘要、最终全部商品/SKU和原支付流水保持。新售后基线5申请/14动作/5私有凭证的三表行摘要保持，不用于补签r38旧二进制before缺口。证据见同一trade目录r35-delivery.json、r35-data-resume-before.json、r35-data-final.json、r35-three-role-order17-final.json、r35-aftersale-resume-before.json、r35-aftersale-final.json和r35-unpaid/cancelled17/paid14的320/390截图。现仅保留一个390的liteMode小窗口，未重建HTTP服务；继续时可核已保存取消单，无需重复创建或取消。本次只签上述正常页面，正式微信/隐私新选图、真机、真实资金及完整阶段验收仍待。

r39补齐按件运费模板的本机正常页面与正数报价链：商家2在原“运费模板→新建模板”保存模板2“本机合成按件运费”，默认全国首1件2.00元、续1件1.00元，指定包邮和不配送均关闭；桌面及375宽度详情已核。商品8现改用该本店模板，经原商品保存、平台重新审核及本店上架，基础/升级规格与SKU107/108价格、最终库存保持。原全局满额包邮仍10000.00元，未改配置。小程序390原基础款一件实际报价19.90元商品+2.00元运费=21.90元，原提交保存唯一根单18（cp638145038890565632）后从原按钮及弹窗正常取消，未付款；三端共同金额及未付/已取消状态一致，保存明细邮费2.00，SKU107库存8→7→8，预留21/释放22关联正确，升级108仍5。当前商品8后续下单会沿此模板报价；旧17单/明细及原根14/15、原支付/履约和售后5/14/5保持，旧订单保存邮费不受本次商品运费变更影响。原保存器会重建定义子行，本次product8的attr组摘要有变化；当前版本/基础/升级业务值与实施前表单相同、全部SKU最终摘要相同，但原attr完整行字节未留全，不宣称其逐字保持。商家DTO原合同仅提供商品/应付邮费/合计三个金额字段，没有total_postage；顾客/平台额外原始邮费均2.00，不把商家缺键当0。证据见同一trade目录r39-delivery.json、r39-template-api.json、r39-three-role-order18-final.json、r39-definition-readback.json及r39-data-current-before/unpaid/final、supplement与对应截图。本次只签一件、默认全国、390小程序正常运费链；未付款截图底部操作区未据此单独签完整外界，320运费链、续件/其他计费类型与地域特例UI、完整Task5/真机仍待，停止样本NOT RUN。

r40补齐商家端正常履约说明：原订单列表和详情对已验证的未付根单，根据未取消/已取消分别显示“订单待付款，暂无履约包裹”“订单已取消，暂无履约包裹”。详情只在当前身份、已成功回读且摘要/包裹均为null时显示一次，加载/读取错误、已付款及原包裹/分页/发货分支保持。原商家构建器已正常BUILT，三文件源/构建副本同SHA，HTTP与静态挂载首页相同，18180七服务未重建；旧包及构建记录保留。实际订单18在桌面与375窗口（可用CSS内容宽360，含滚动条差异）的列表/详情已验：取消说明完整、详情无重复通用提示，商品19.90/邮费2.00/应付21.90保持；窄屏列表按原横向滚动查看状态列。原已付款订单9在列表、详情及包裹12正常显示已收1件/12.50元，两宽度包裹及原私有参考图可读。相关订单18/9包裹响应及完整本店列表返回对象前后相同，不扩为全库/Redis保持。本轮没有新建订单或交易；当前没有活跃未付合成单，待付款文案仅源级正常派生检查，未以取消单冒称待付款页面实证，错误/负向运行仍NOT RUN。证据见同一trade目录r40-delivery.json、component-checks、source-build-http、data-comparison、detail/list最终图与paid9回归图；窄屏快截图比例错误原件保留，列表视觉以r40-list-after-375-full.png为准。后端、顾客小程序/H5和原商品7草稿未改，完整验收/真机/真实资金等原责任继续保留。

r41补齐同一申请两件分次退货的正常页面链，复用原测试支付根单1/包裹2，不新下单或付款。顾客H5从原售后申请选择包裹内第1-2件、金额40.30元并上传新的合成私有凭证；商家2批准合成回寄地址，顾客保存唯一合成运单。商家先仅验收第1件，三端读回部分退件、已验1/仅余第2件，库存97→98；再仅验收第2件，三端RETURN_ACCEPTED、已验2/可验0，库存98→99、销量3→2→1。return_in23/24各1件并关联原reserve1，商家/平台验收动作关闭。原付款40.30、已收2件及订单历史保持，确认退款仍0、占用40.30/可用0、资金明确UNSUPPORTED，未发生真实退款。顾客原物流更正表单按既有合同继续保留，关闭仅指验收。可在顾客“多商户售后”及商家售后列表查看售后638153309122723840，或[打开已保存顾客结果](http://127.0.0.1:18180/pages/goods/marketplace_aftersale/index?refund_no=638153309122723840)；本样本已完成两件验收，不重复登记。

此次联调发现并修复商家售后列表/商品表/退件序号表的列复用问题：仅补三张表各自固定标识，实际同一部分态的DOM对照从错误五列变为正确商品项2、申请1-2、已验1、可验2，列表往返及重新读取仍正确；最终已验序号1和2、可验为空。原数据、权限、列定义及样式不改。现有source-check和merchant完整构建通过、源/副本/HTTP已核同，7服务未重建，H5/MP未构建。顾客320/390和商家桌面/375窗口的有限正常页面已验；商家375窗口可用CSS内容宽360，右侧列仍按原横向滚动，不称所有列同屏可见。新file6的CLAIMED归属与授权读取1003字节/320×240/SHA核同；旧18单/明细、原支付/履约及旧5售后/14动作/5凭证摘要保持，其他商品/SKU保持。原商家错误缓冲8条均在修复构建前，新构建后的已观测缓冲0，顾客缓冲0，未清空旧错误。证据见原trade目录r41-delivery.json、partial-final/final-three-role、root1-final-three-role、data/supplement-before/partial/final、table-before/after检查及稳定最终图。完整AR/阶段、正式微信新选图、真机和真实资金仍待，停止样本NOT RUN；不由本次新基线补签R38/R39旧证据缺口。

r42（2026-10-27至28操作，10-28交付）已补齐小程序原购物车的同店两规格合单：商品8基础款SKU107与升级款SKU108各1件，经原详情分别加入购物车3/4，390逻辑宽度原购物车只选择这两行；原确认页为persistent来源、商品49.80元、同模板2合并运费3.00元、应付52.80元。唯一保存未付根单19/cp638164245535522816；顾客、商家、平台的保存读取均为两条规格各1件，单价19.90/29.90、每行运费1.50元。小程序原按钮取消后，三端均paid0/is_cancel1，SKU107库存8→7→8、SKU108库存5→4→5；reserve25/26与release27/28各1且分别正确关联，未支付或产生真实资金。可在小程序“我的→订单”查看该取消单，商家订单列表/详情也可核对52.80及正常包裹提示；本样本不重复提交或取消。

本轮无业务源码改动或重新构建，实际补验小程序待付款/取消的原页面，并补齐r40当时仅源级的商家活跃未付列表与详情：正常说明均显示一次，没有两条通用错误提示。原18订单/18明细、支付6次尝试及旧24库存流水保持，全部已观察商品/规格摘要最终恢复，当前基线6售后/19动作/6私有文件与四运费表逐行摘要保持。7原服务/60完整挂载对象与r41同，只对Docker挂载数组枚举顺序作规范比较，不忽略对象字段。购物车提交前原API两行、成功后原接口及表为空，原创建器正常消费两行，未另行删除。证据沿原trade目录r42-data/supplement-before/created/final、created/final-details-three-role、native-cart/confirmation/final-state、merchant-pending/final、data/runtime-comparison与r42-delivery.json；首次tab导航动作错误、观察器类型和挂载数组顺序的失败记录保留，不作为业务失败或通过替代。范围仅全国计件模板、两规格各1件、390模拟器正常未付链；其他计费/地域、真机、完整阶段和真实资金仍待，停止样本NOT RUN，R38/R39旧证据缺口不补签。

r43（2026-10-28）补齐同一两规格订单的测试支付与逐包裹发收货正常页面链。小程序原购物车基础款107、升级款108各1件，原收银台明确“不扣真实资金”，唯一根单20/cp638168689274781696测试支付52.80元。商家先仅发基础款1件，形成包裹21/cf638169791185551360；顾客从原“查看包裹”确认该件后，根仍部分发货、保留升级款待发1件。再发升级款1件，形成包裹22/cf638171086214660096，顾客正常确认后根与两包裹均已收货，已发2/已收2、待发0/待收0。两包裹保存金额分别19.90+1.50=21.40、29.90+1.50=31.40，合计等于根52.80，没有重复收取模板首件费。可在小程序“我的→订单”打开该付款订单，或在商家订单列表按完整付款单号查询并查看两个包裹；这些样本已完成，不重复支付或收货。

本轮复用现有源码/构建，无新业务代码或服务重建；本机390小程序和商家桌面原页面、平台认证读取均核对保存事实。唯一测试尝试7成功应用、根registry指向7，异常0；包裹公开DTO继承根付款，原生子单paid0不表示需要再付。创建仅reserve29/30各1，SKU库存8→7、5→4，发货/收货保持7/4；四条履约动作和范围11–14按各SKU、原预留和对应发货关联。原19条订单记录/20明细、6旧支付尝试、28旧库存流水与10旧履约动作/范围保持；当前6售后/19动作/6私有文件、运费表及其他商品/规格保持，目标两SKU仅库存/销量变。7服务/60完整挂载对象与r42同。证据沿原trade目录r43-data/supplement-before及各阶段/final、各阶段three-role、final-packages-three-role、native-*、merchant-*、data-comparison及r43-delivery.json；包裹DTO没有顶层总金额或SKU ID，金额按保存明细合计并对原生子单，身份按保存快照/履约范围核对。范围仅正常多SKU、正运费、两个单件包裹与手工合成收货，不代签包裹内部分件号、Worker、真机、正式微信、真实资金或完整阶段；停止样本NOT RUN，历史证据缺口保留。

r45（2026-10-28）补齐原小程序订单自然超时正常链，无新业务源码或构建。UID3原页面购买商品8基础款107一件，根23/cp638189278295228416保存商品19.90＋运费2.00＝21.90，原期限90秒；未支付、未点取消、未直接调用扫描。原Timer在截止后11秒正常关闭，唯一system release32关联reserve31，库存7→6→7；原生重进详情显示已取消且付款/取消入口消失，三端认证读取金额/规格/状态一致。期限已用原helper恢复捕获的0.5小时，DB/应用缓存/helper last_value与任务/任务缓存均同原值，旧15条保存截止逐值保持。

本轮42观测字段35同/7为新交易，旧22订单/24明细完整摘要、7支付尝试、30库存流水、14履约动作/范围及捕获商品/全部规格保持；7服务所选控制字段及60完整挂载对象同。当前为23订单/25明细，目标SKU107/108最终仍7/4。使用官方小程序运行时组件事件及提交tap，截图接口核390逻辑视口；桌面窗口截图失焦未用于点击。待付款截图底部控件仅部分可见，不签完整布局、其它宽度或真机。沿原trade目录r45-*及[r45交付记录](H:/天俪商城/artifacts/multi-merchant-miniprogram-trade-20261027-r1/r45-delivery.json)保存正常事实、失败观察说明及有限边界；不把既有自动关单记为新开发，不代签其它任务、正式微信、资金或完整阶段，停止样本仍NOT RUN。

r47（2026-10-28）修复商家“角色管理→编辑/新建”中的权限说明不可见：原ElementUI权限组字号0被段落继承，12项说明高度均为0；现仅该组恢复14px、沿原1.6行高。售后处理说明同时改为批准/拒绝本店申请及退件验收，并明确查看还需订单查看权限，不承诺资金退款。原构建器只更新商家包，1280与375本机浏览器的两列/单列、长短说明、已勾选/空白未勾选、滚动至取消区均已核对；没有保存角色，原3角色/2员工完整行集摘要保持。旧商家包与构建记录保留，52输入及30产物HTTP字节核同，7服务所选字段/60完整挂载对象保持；后端/H5/小程序不切换，临时检查页已关闭。仅本机受影响页面正常验证，不代签权限变更、真机或完整阶段，证据沿原trade目录r47-*。



r27补齐分类图片的MP服务器地址：仅在原分类API响应中处理分类及其子分类已有的pic/big_pic字段，复用原公开图片地址规则；原记录、分类缓存流程、模板、样式、筛选和H5/APP响应保持。分类41“预览商品”的图片已在390宽度模拟器显示，组件字段与原API仅差预期地址前缀，原图片HTTP200且文件摘要一致。源码/三端预处理、正常嵌套分类及缓存读取检查、完整MP构建已通过；商品/SKU、订单、资产、消息及余额积分保持。当时窗口控制工具画面与目标窗口不一致，已停止据该画面点击；原390结果来自微信工具按项目截图及实际组件数据。r30后续已通过真实原控件完成首页→分类41→商品列表→商品9详情，逻辑宽度实核390；r32补齐320已登录状态的同一原分类→列表→商品8详情，图片与路由正常。r34已补齐320未登录分支：正常退出后，原分类→列表→商品8详情→商家2店铺均可浏览，逐页核UID0/无令牌/verifiedUid空，详见下段；真机仍待。原证据见同一trade目录 `r27-category-*`，补充原入口证据见 `r30-mp-category-entry.json`、`r30-mp-product-list-entry.json`、`r30-mp-product9-entry.json`、`r30-mp-viewport.json` 及r32对应入口JSON/截图。

r30修正普通商品小程序详情在登录后自动请求分享码的问题。原network中确有 `product/code/9?user_type=routine` 返回“二维码生成失败”；当前本机后端尚未配置小程序取码AppID及secret。仅MP登录监听不再预生成分享码，优惠券/购物车刷新保持，原“生成海报”仍在用户明确点击后请求真实微信码；H5/APP保持。同时修正MP登录返回地址遗漏前导斜线和查询参数：原商品9进入原手机号/账号登录后，既有合成账号实际登录、身份验证并自动返回同商品9。新工程缓存网络未出现普通登录触发的product/code请求，源码/三端预处理、独立源审与完整构建通过。微信分享码本身仍待真实配对配置与单独验收；不通过替代二维码冒签。配置核对只保存presence布尔值，不保存配置值或原始network凭证。

r30新入驻商家3首单已通过正常业务链：本店上传图片、建立商品9“新店合成体验商品”及SKU109，8.88元、包邮，原库存调整入库4件，经平台审核后上架并开启接单。小程序原规格窗口选择默认规格1件，确认页保存根单14，再在原收银台明确选择“测试支付（不扣款）”；商家原订单页面发出包裹15，小程序核对后确认收货，显示“收货结果已保存”。当前三端根单14均已收货、1件8.88元、0运费、marketplace_test；商家/顾客包裹均已发1、已收1、待收0，商品与SKU库存3、销量1，唯一预留16。商家3新增本店附件97，原有商品、13条旧订单及明细、素材、身份、资金、顾客资产和聊天保持。可从商家3当前订单页或小程序订单列表核对已保存结果，勿重复付款或发货。

本批入口分类链及最终收货确认使用原生窗口点击，跨组件购买/包裹/收货入口使用官方自动化的原公开组件事件；确认订单、测试支付使用原按钮，所有阶段均再核实际页面和保存结果，未注入token或改订单状态。390宽度正常链已验，320完整链、原商品7必填选图、正式微信登录/分享、真机与发布仍按各自范围待验。证据为同一trade目录的 `r30-merchant3-data-*`、`r30-mp-*`、`r30-r2-login-return.json`、`r30-r2-no-eager-code.json`、`r30-received-platform-customer.json` 及对应截图；停止的负向、故障、并发、恢复样本仍NOT RUN。

r31修复带私有参考图的订单/包裹空白：原临时图片缓存使用JSON数组键，微信setData把其中方括号解释为非法路径，导致JS资料已读取而原生页面未更新。仅MP把同一完整元组编码为字母数字键，读写共用原helper；H5/APP键、保存表单/图片引用及权限/下载/释放流程保持。实际根单9私有图读回后进入包裹12，两宽度页面与图片正常，JS和原生数据同步。390/320按钮外界、文字居中与换行正常；320从原生按钮和确认弹窗只提交一次，网络为GET待收→POST收货200→GET已收1，成功提示为静态文字且没有待确认/错误状态。未再出现该非法setData路径错误。三端金额/状态一致，其余订单（含14/15）、全部商品/SKU、库存及支付流水、素材、权限、顾客资产和余额积分保持；新图片上传、320完整付款后交易链、其他三个表单消费者UI和正式微信/真机仍待各自验收。证据见同一trade目录 `r31-root9-private-image-*`、`r31-receipt-*`、`r31-console-*` 和前后快照；本批未改后端或重启服务，停止样本仍NOT RUN。

r22修复商品“产品介绍”的相对媒体地址：仅MP向原详情解析器传入API origin，按原解析器契约补齐相对src及CSS URL，绝对/data地址、原description字符串和页面样式保持；H5仍为空domain，APP仍用原v-html。商品7原音频控件已实际显示00:00→00:04→00:05并恢复播放按钮，原商品视频已正常显示连续变化的画面并回到停止状态；不以封面或调用play前的状态值代签播放。390/320宽度媒体区域完整，价格12.50、库存19及原订单/SKU/资产/余额积分保持。完整MP构建、目标WXML、原解析器正常样本及三端预处理检查通过；证据为同一仓库外trade目录的r22-*。本次没有修改数据库、重启后端或新建交易，不代表可听声音测量、真机或全部媒体格式验收。

原选图报错按微信官方错误说明对应后台隐私声明缺项，需要当前同一测试号在“服务内容声明 → 用户隐私保护指引”声明“收集你选中的照片或视频信息”。后台是否提供该入口仍待用户确认，不能据此断言测试号一律不支持选图。当前保留隐私检查与原chooseImage，未改成其他接口或替换必填图片；此条件只阻塞新选图相关流程。

r34沿用同一r31工程，先经官方正常导航从订单详情返回首页，再实际点击“我的→设置→退出登录→确认”，没有清缓存或直接改登录状态。原生点击320分类41的“全部商品”，从列表进入商品8，再点原“进入店铺”；全过程运行核UID0、无令牌、verifiedUid空/isLogin false。分类图片、列表及详情图正常，商品8公开基础款19.90/库存8、升级款29.90/库存5；本店页仅显示mer2的商品8/7/6/5，没有混入商家3的商品9，原样式和店铺入口边界正常。16张原订单、商品/SKU及完整已采集业务投影前后快照逐字一致，不扩大为全库或全Redis保持（原退出会删除当前会话，浏览统计可按原流程更新）。无应用源缺陷，无重新构建或后端重启。证据见同一trade目录的r34-anonymous-*.json、r34-native-anonymous-*-320.png及r34-data-before/after/comparison.json；当前窗口停在未登录的商家2店铺。此结果不代签登录后购买、隐私选图、正式微信、真机或停止样本。

r23修复窄屏商品详情中客服悬浮按钮遮挡“进入店铺”：店铺卡右侧保留浮层占用的空间，原卡片主题、按钮、进店路由和客服逻辑保持。320/390模拟器中原两个入口均实际点击通过，分别进入商家2店铺和携带productId=7的原客服对话页；店铺文字、按钮居中及边界正常。订单、商品/SKU、资产、消息及余额积分与r22一致，未发送新消息或新建交易。证据为同一trade目录r23-store-card-*、r23-*-entry-*及r23-store-layout-*；不代签其它商品类型、客服方式、H5/APP运行或真机验收。

r19补齐登录Logo与订单列表缩略图的MP服务器地址，保留原图片、缓存和接口数据。个人中心“多商户售后”移入已有装修底部插槽，紧接原菜单显示，点击可查看本人售后记录；原订单菜单仍进入原订单列表，再点“查看详情”进入售后与评价。实际原账号登录、登录页/个人中心320及390宽度、320订单图片，以及列表→订单10详情→售后/评价入口已验证。四个Logo消费者均完成源码与跨平台编译检查，其中登录页图片实际显示、头像资料组件原缓存的展示地址实际核对；Authorize弹窗、正式微信授权页全流程及H5/真机未由本轮代签。

2026-10-27 已修正个人中心订单角标：装修接口复用订单列表现有 `getOrderData` 统计，保留原退款入口。当前合成顾客的待发货/待评价为1/0，与用户资料、订单列表一致；小程序实际点击分别得到订单9一条和空列表，返回个人中心仍一致。三端订单10金额20.15元、已收货状态、商品/SKU库存及四条原售后记录保持。本次为后端修复，继续使用r19小程序工程；没有重建前端、改变订单或重复付款。证据见同一仓库外目录 `r20-theme-count-*`；旧订单混合样本、其它客户端全流程和正式发布仍按原范围待验。

本机18180当前HTTP服务保留r21十个只读PHP覆盖、r28八个首次设置链接补发相关PHP覆盖及只读迁移目录，r33再增加商家业务请求配额与日志接线。r21来源为 `H:/天俪商城/artifacts/multi-merchant-miniprogram-trade-20261027-r1/r21-public-upload-source/crmeb/`；r28来源为同一trade目录的 `r28-password-setup-source/`，逐文件SHA256与冻结迁移副本摘要见 `r28-source-manifest.json`；r33来源为 `r33-request-quota-source/candidate/`。维护这个现有实例时，使用以下持久Compose保留覆盖：

```powershell
$previewCompose = 'H:/天俪商城/artifacts/multi-merchant-preview-20261026/app-quota.compose.json'
docker compose --project-name mall-preview-20261026055805284f53 --file $previewCompose up -d --no-deps --timeout -1 preview-app
docker compose --project-name mall-preview-20261026055805284f53 --file $previewCompose exec -T preview-web nginx -t -c /etc/nginx/preview.conf
docker compose --project-name mall-preview-20261026055805284f53 --file $previewCompose exec -T preview-web nginx -s reload -c /etc/nginx/preview.conf
```

该Compose仅为原HTTP服务追加上述只读来源和显式本机配额配置，不更换镜像、账户、卷或网络；它属于本机运行材料，不是通用升级工具。原 `app-setup.compose.json` 保持，r33复制其25项挂载后增加7项只读覆盖；运行候选共313份源文件逐份核同。迁移目录包含与原实例逐字一致的27个旧文件及1个新增文件，避免向只读工作树创建新挂载点。旧 `app-setup.compose.json` 不含r33，`app-upload.compose.json`、`app-counts.compose.json` 也不是当前维护配置。旧来源的 `preview.py serve`、`serve-api`、`video` 等可能按旧Compose重建HTTP服务并撤销这些覆盖，应继续使用上述维护路径；不要重复initialize或改state.repo。新建预览仍使用仓库标准prepare/initialize流程及已提交源码。完整后端版本门升级与正式发布验收仍单独推进。

r33配额只在原认证与权限通过后，对同商家的业务请求共用计数；登录、首次设密、刷新与退出保持原流程。本机显式策略为 `r33-local-v1 / 60秒 / 600次`。新源码默认关闭，正式部署需自行明确启用、策略版本、窗口和额度，不能照搬本机值。原子Redis窗口不随正常读取延长；关联日志只含可信商家、静态路由、请求编号和有限计数，不保存请求正文或凭据。当前merchantapi没有导出入口，本片不新增导出，也不代表完整R06资源治理或监控验收。

正常验证中，商家2读取原订单13、商家3以原浏览器会话读取本人身份及订单14包裹，各自计数1→2且HTTP/业务均200；商家2订单响应数据摘要与变更前相同，商家3原登录及已收货8.88元状态保持。原16单、商品/SKU及完整已纳入快照的业务资料前后摘要一致。另以声明的合成Context做正常服务原语检查，实核关闭分支、同店累计、两店独立和绝对期限不续期；该原语不代替HTTP认证。PHP8.2语法和独立源审已通过。证据见同一trade目录 `r33-runtime-source-check.json`、`r33-native-normal.json`、`r33-live-log-projection.json`、`r33-data-before.json`、`r33-data-after.json`；超额、高频、故障、并发、恢复及K样本保持NOT RUN，真实渠道与正式发布仍待。

运行资源核对见 `r33-runtime-comparison-diagnostic.json` 和 `r33-runtime-candidate-hashes-observed.json`：313项实际容器文件哈希与候选清单完全相同，其他六服务身份、启动时间及挂载字段值保持。HTTP app的 `/workspace` Source原始字符串由Docker Desktop的Linux路径变为Windows路径；该差异保留，并以未重建events的相同内核挂载、设备/inode及七个基础文件与宿主机的摘要对应证实仍是同一实际只读挂载，未把原始元数据写成全部相同。

首次设置链接补发需要独立权限叶 `marketplace-merchants-password-setup-reissue`。现有环境在维护阶段显式执行 `php help/migrations/marketplace-password-setup-reissue-menu.php apply --config-file=<私有数据库配置>`，随后执行同文件的 `check`；先读check时，`applicable`表示尚未登记（退出码1），不得当作已经完成。迁移只补一项菜单，不修改普通角色授权；权限分配仍沿原角色管理。新装预览已在原seed流程接线，既有实例不要重跑seed。生产镜像不包含help迁移目录，发布维护需携带此迁移和原依赖；本批没有升级生产镜像、修改schema版本或完成正式发布。

共用图片上传兼容：顾客登录后原 `POST /api/upload/image` 支持单文件默认字段 `file`、显式 `filename=file`，以及当前聊天使用的文件字段 `pics` 和 `filename=pics`。保留 `name/url/attachment_ref` 响应；图片按真实顾客登记为 `public_upload_compat/STAGED`，保存到独立 `/uploads/customer/compat/` 目录，不写商家附件。它是公开图片，不用于新的执照、提现二维码、退款凭证或订单私有表单；这些入口继续使用已有具名接口，历史图片读取保持。

本机已验证三种正常上传格式及原H5聊天选图，新增公开资产6–9；顾客3发出的图片消息20由客服实时收到，刷新历史和原图正常，小程序同一会话也实际显示并可预览原图。共享的具名头像上传正常回归新增暂存资产10，未保存头像资料；原头像、订单、库存、商业附件、私有聊天资产及余额积分保持，上传配额8→13。五张图片均1054字节、320×240，保存摘要、物理文件与HTTP内容一致。

本次H5兼容页面验证临时仅在HTTP app的独立common.php副本关闭既有私有新上传开关，保留私有读取与原events服务；验证后已移除该临时挂载，当前写/读能力均恢复true。`app-upload-compat.compose.json`及副本仅保存当次验证来源，不是日常启动配置。小程序新选图仍待微信隐私声明条件，不能用历史读取替代上传验收；云存储、其它旧客户端、真机及完整阶段仍待，停止的测试保持NOT RUN。

小程序评价：在已收货订单底部点击“商品评价”，选择商品后设置商品质量、服务态度星级，填写文字，再点“提交评价”。新评价显示待审核；平台“经营商家 → 商品评价”打开对应记录，选择审核通过并确认。商家“商品评价”打开同一记录，可以填写回复并确认。顾客刷新自己的评价记录，或从商品详情进入评价列表，即可读取通过审核的内容与商家回复。

r15实际完成根单10/商品项10的评价3：小程序原控件提交4/5分及合成文字，平台页面审核、商家页面回复，顾客记录和商品5公开列表均读回相同内容；订单20.15元、已收货状态及商品/SKU库存保持。评价页沿原双行星级、商品卡、浅灰输入区和主题胶囊按钮，390/320宽度实际检查通过。已有公开评价2的相对图片地址仅在MP展示和放大时补API origin，原记录不改；自己的评价页与公开列表均已实际显示并放大。新的小程序选图上传仍依赖前述微信隐私接口申报，不以既有图片可读代替上传验收。证据见仓库外 `multi-merchant-miniprogram-trade-20261027-r1/r15-review-after.json` 及对应原生截图。

小程序平台客服：先从平台“客服 → 客服列表”进入已有客服的工作台保持在线，再在小程序自己的订单详情点“联系客服”。原聊天顶部可点“发送客服”发送订单卡，底部可输入文字；客服在原工作台打开顾客会话并回复。顾客重新进入聊天后可以读取历史，点已有聊天图片打开原图，点“关闭”返回聊天。

r16已通过原页面控件保存订单卡17、顾客咨询18及客服回复19，两端实时显示；r17正常重新登录、重新进入及客服刷新后读回同一记录，未重复发送。客服可查看订单10的只读摘要，顾客沿原订单卡片布局展示。公开商品和头像的相对路径仅在MP展示时补API origin。MP私有原图改用页内预览，保留原归属校验及离页清理；公开图片、H5/APP继续原预览路径。390/320实际检查了聊天、原图打开/关闭，正常离页后预览与临时显示引用清空。订单20.15元、已收货状态、商品/SKU库存及已保存消息内容保持。新选图上传、真机、其它完整客服流程仍待验；证据见同一仓库外目录的 `r17-chat-history-390.json`、`r17-chat-preview-320.json`、`r17-chat-preview-leave.json`、`r17-chat-api-final.json` 及原生截图。

小程序售后：在自己的已付款订单详情点“售后服务”，可读取本单申请资格、商品和可申请金额；页面顶部“售后记录”进入本人列表，点“查看详情”读取处理状态及已有凭证。业务处理、实物退回和资金退款分别显示，退件已验收不表示已经退款。新申请至少需要一张凭证，当前选图前置未满足，因此尚未完成小程序新申请提交。

r18修复了MP组件接收未绑定页面方法导致已有售后凭证读不出来的问题，保留原身份、目标和生命周期检查；三处公开商品图仅MP补API origin。售后列表、详情、申请及寄回信息区沿原退款页白色分组、字号和胶囊按钮。已实际读取本人四条历史记录、售后4与2的私有凭证，以及订单10原申请资格；未新建申请、覆盖历史运单或执行资金退款。390/320页面和控件检查、三端同一售后内容及订单/库存前后核对见仓库外同一目录 `r18-aftersale-*`。凭证为页面内联展示，本次没有新增点击放大；其它共享组件消费者的完整MP流程、H5新样式及真机仍待验。

`storefront` 要求实例已完成 `initialize`，仅在主题表全空时创建预览首页，使用原动态商品接口。它是 create-only 操作；已有实例已经创建首页或已有装修主题时，不重复执行，也不覆盖装修。

旧订单评价兼容使用另一个新运行目录：首次 `prepare --root <新目录> --port 18181 --legacy-review-fixture`，后续 `preview.py` 命令继续指定同一 `--root`。前端构建仍输出默认预览的 `build` 目录；第二实例可复制同一源码已成功构建的三个 `dist` 到新目录的 `build/{admin,merchant,h5}/dist` 后运行 `serve`。该模式在正常初始化后才启用原旧订单创建流程；合成商品归已确认自营商家，配置零费用默认运费和200元合成余额，仅开启余额测试支付。它不切换已有18180实例，不改变已有订单版本，也不代表真实资金或非空历史迁移验收。模式不能用于已有实例。

新预览安装保持严格模式：二维码表的COMPACT行格式改为DYNAMIC以保完整索引；原路由元数据缺失的 `error_code` 按原写入器补JSON空数组。初始化回执记录源语句调整，原安装SQL不改；续接回执不代表已重执行这些调整。

旧订单评价图片现在使用 `order/legacy/reviews/<unique>/files`，原评价提交同时绑定图片，仍保留原评分、审核及无图入口。2026-10-26 在18181独立合成环境通过真实HTTP完成原购物车、下单、余额支付、平台发货和顾客收货，再上传PNG、提交评价、平台审核及公开读回。图片资产从STAGED变为BOUND，1054字节、320×240及SHA256与原件相符；评价期间订单金额、余额及商品/SKU库存不变，订单按原逻辑转为已完成。H5构建和组件正常检查通过；最初浏览器控制中断时未将HTTP结果当作页面验收，后续正常页面结果如下。

后续浏览器验收已补齐：另一个正常已收货旧订单通过顾客页面选择图片、填写五星和文字并提交，真实请求带对应资产编号，页面显示成功；刷新订单为已完成，平台原接口审核后公开评价页刷新仍显示同一图片。320/375宽度下的图片、状态提示及操作按钮已检查，保存后的库存与余额保持。

商品详情现在包含原有评价组件；有已审核评价时显示内容、图片和好评率，点击右侧好评率可进入完整评价列表。主18180和旧版18181预览均已通过该实际入口跳转。新主题初始化自动包含组件；已有主题通过原装修保存接口合入，原组件、商品和订单保留。装修主题读取同时兼容PHP8.2下的空主题颜色数据，空值仍保持null。

入口：

- 顾客端：`http://127.0.0.1:18180/`
- 平台后台：`http://127.0.0.1:18180/admin/`
- 商家后台：`http://127.0.0.1:18180/merchant/#/login`
- 平台客服登录：`http://127.0.0.1:18180/kefu`

平台客服复用现有后台构建，`/kefu` 及 `/kefu/` 下的页面由后台入口加载。原聊天地址 `/msg` 接入专用事件服务40002端口，平台通知 `/notice` 接入40001端口；内部channel保持不对宿主机开放。客服身份独立于平台管理员，应在平台“客服 → 客服列表”建立关联用户及客服账号；需要新密码时由账号本人完成设置，直接打开工作台不能代替客服登录。

客服个人快捷回复：已登录客服打开 `http://127.0.0.1:18180/kefu/pc_list`，选择顾客会话，点击聊天工具栏的“话术”图标。在“个人库”左侧“分组 +”建立分组，点击右侧标题输入框展开正文，填写内容并选择分组后保存。点击已保存话术只会填入聊天输入框，检查后再点“发送”。个人库没有分组时可正常打开，不自动创建默认分组。

r26已在原客服界面保存个人分组“预览订单回复”及“订单查看”“补充咨询”两条合成话术；修复原空分组报错、标题聚焦无法展开表单，以及成功回执没有data字段时列表刷新中断。保存后即时显示、关闭重开、选入未自动发送、原发送按钮单次提交、顾客H5实时接收与两端刷新历史均已验证。新增消息21来自客服用户4、发给合成顾客3；原20条消息、订单/商品/SKU、资产、余额积分及上传配额保持。公共库原系统分组可读；分类编辑/删除、搜索、移动客服与小程序本次新消息接收不由本轮代验。原样式、接口及客服归属校验保持。源码/SFC检查、后台构建及18180实际服务字节核对通过，证据在仓库外 `multi-merchant-miniprogram-trade-20261027-r1/r26-quick-reply-*`；第一次保存成功但列表未刷新的失败记录保留。

没有在线客服时，顾客从订单“联系客服”进入离线留言页，填写姓名、手机号和内容并提交；平台在“客服 → 用户留言”查看同一记录，点击“处理”填写备注并设为已处理，之后可点“备注”重新查看。隔离体验仅使用合成资料，不拨号、不发短信。

随机凭据在专用私有卷中生成。`accounts-file` 将三角色合成账号保存到运行目录的 `private/preview-accounts.json`，Windows 仅当前用户可读；不会把密码写入命令日志。应用仅有数据库读写权限，建表账号只供初始化使用。运行记录保存在仓库外 `artifacts/multi-merchant-preview-20261026/`，不提交凭据、上传文件、数据库或日志。

`serve-api` 可在前端构建期间先启动真实接口。`serve` 只有在三端构建存在且应用健康检查成功后才报告可访问；实际业务和浏览器验收需另外完成。`stop` 正常停止本预览的服务并保留数据卷。 `serve` 在容器启动后检查并普通重载nginx，使重建后的后端地址及时生效；`serve-api` 在本实例web已运行时也执行此刷新，首次只启API不要求前端。HTTP应用使用内置服务器普通停止信号SIGINT，events/timer使用Workerman普通停止信号SIGINT，marketplace队列使用SIGTERM；不继承PHP-FPM的SIGQUIT。普通维护先确认操作已保存并离开聊天页，再核主进程实际退出；普通停止不承诺连接排空，框架原有超时兜底仍保留。

`timers` 在已启动的预览应用中用原服务登记每30秒执行的唯一 `orderCancel` 任务，并启动专用 `preview-timer`（原 `console.php timer start`）。默认新订单待付款期限为30分钟；配置成功后 `serve` / `serve-api` 会连同该服务启动。原Timer还保留每60秒的自动收货扫描，按已保存发货截止执行；本预览不另建调度器，商品素材清理须另行执行 `video` 显式登记。

r36为原CrontabRunServices的14项登记任务和未知调用补齐有限JSON日志。普通日志沿原 `log.timer_log` 开关，默认关闭；开启后，同一次调用的 `timer.started` 与 `timer.returned`/`timer.exception` 共用 `timer_run_id`，可在runtime/log的crontab日志中按编号与task_name查找。`returned`只表示函数返回，不表示业务成功。两项special原本始终输出的GC异常及post-commit异常/游标诊断继续保留，换为有限字段并关联编号；关闭普通日志不会新增正常事件。记录不包含异常原文、trace、参数或自定义代码；底层旧业务服务日志仍需各自核对，不能由此签完整R04。

本批在独立新ISO空库实际调用原自动取消和自动收货各一次，自动收货原配置为7天，V1/legacy取消及legacy收货候选均为0；取得两组开始/返回日志、原void返回，176张应用表的完整行摘要前后相同。仅验证进程内临时开启普通日志并恢复false，未启动调度器/队列/HTTP，未运行其它任务或故障样本。原18180七服务身份/镜像/启动时间/挂载字段保持，16单及已采集业务投影保持；原常驻Timer未替换，不把一次性正常验证说成旧进程已升级。新ISO `H:/天俪商城/artifacts/multi-merchant-timer-log-20261027-r2` 的两数据库已正常停止并保卷，未做恢复或重启验收。证据见原trade目录 `r36-timer-normal.json`、`r36-runtime-final.json` 和 `r36-data-before/after.json`。

新建split/source full预览也可显式传 `--preview-subnet $previewSubnet --ingress-subnet $ingressSubnet`。两值须为已核与Docker和宿主路由不重叠的RFC1918 CIDR；工具检查Docker分配，宿主路由由操作者另核。它只在新root写固定 `networks.compose.json`，继续使用专用内部网络；已有root沿保存配置使用，不把额外参数当作现有网络修改。无参数旧实例及image/release路径保持。r36-r1因默认地址池耗尽的原失败保留；r2显式子网的真实prepare、initialize和正常调用通过，不删除旧网络或挪用既有商城网络。不要把本次已占用的子网复制给另一个新实例。

普通拆分预览的 `queue` 启动原marketplace专用队列消费者（`queue:work marketplace --queue=marketplace --sleep=1 --tries=1`），复用内部网络、DML角色和现有任务实现。完成资源归属检查后才保存启用标记；之后 `serve` / `serve-api` 会带上该服务。2026-10-26在18180实际验证合成员工正常登录后投递一项 `merchant.profile.read` 审计任务，原worker消费并产生唯一成功审计行，pending/reserved/delayed均归零。该结果不代表订单异步任务或完整Worker验收；该次拆分预览没有启动普通CRMEB队列。

`python -X utf8 help/preview/preview.py video` 为已初始化的标准预览启用原商品视频分片；指定其他标准实例时追加 `--root <运行目录>`，旧订单兼容实例不支持此动作。命令普通停止并重新启动该实例的HTTP应用和原Timer，登记原素材清理任务；重复登记保留同一任务。启用标记绑定本实例project/run，保存在app-secrets卷；两个独立暂存目录 `/private/product-video`、`/private/product-media-scan` 使用原private-assets卷，权限0700、所有者UID1000。之后 `serve` / `serve-api` 会带上原Timer，普通停止和重建保留标记及暂存数据。

按当前配置重新创建的HTTP应用，其PHP单文件限额为4MiB、POST为8MiB、内存为256MiB，nginx仍为20MiB。视频超过3MiB时使用原3MiB顺序分片，完整文件业务上限仍为50MiB；不放宽为整包大POST，云上传保持关闭。暂存TTL保持600秒，原清理任务每60秒检查；页面上传期间禁用操作，完整保存且列表读回后才提示成功。页面上传、审核上架后的播放及TTL后的自然清理须在目标实例分别验证，命令成功不代表这些验收已完成。

正常超时体验可使用 `timers --hours 0.025`，然后从顾客页面新建一张不付款订单，等待90秒截止及至多一个30秒扫描周期，再刷新详情核对关闭与库存。先捕获当前期限，结束按捕获值用 `configure` 恢复；`timers-restore` 恢复的是首次运行前值，不能替代本轮恢复。V1只给之后创建的订单保存新截止，`0`关闭新单截止；旧V1保存截止不变，原Timer仍处理到期单。旧版普通未付订单每次扫描使用当前期限，临时缩短前须按原DAO完整条件确认候选为空，不能仅查V1主单。原备份在runtime卷 `preview-timer-config.json`，helper窄清相关配置缓存并核读回。维护含额外Compose覆盖的既有实例时，沿实际活动Compose对现有App执行原 `timers.php configure <小时>`；原 `timers` 动作还会附带基础Compose的Timer启动，不直接套用于当前18180的 `app-quota.compose.json` 覆盖。

专用预览HTTP进程开启PHP源码时间戳检查，使本工作树的后端修正可在下一次请求生效；正式部署镜像配置保持原样。前端修改仍需重建受影响的dist。后台重建后，已打开的旧页面应整页刷新再继续操作；旧页面保留的分块文件名可能已变化。本次观察到旧会话列表加载原分块时收到HTML并报语法错误，整页刷新后原列表跳转恢复；此记录不代表已完成正式发布的跨版本资源兼容。

初始化中断时先查看运行目录中对应命令的原始结果，确认断点后才使用 `resume-initialize`。续接要求当前实例身份一致且业务夹具尚未建立，不清空数据库。

完成登录后，顾客可选择体验商品下单，从订单详情进入收银台并选择“测试支付（不扣款）”；商家在订单页发货，顾客回到订单详情确认收货。测试支付只在 `APP_ENV=testing` 和 `marketplace.payment.test_enabled=true` 同时成立时开放。真实支付、退款、结算和正式发布验收仍按主执行清单推进。

专用预览开启 `marketplace.aftersale.enabled`，允许顾客创建售后申请；原登录、订单归属、金额、数量和私有凭证校验保留。该开关只控制新申请，不关闭已有售后记录的读取或处理。当前测试支付驱动尚不支持资金退款：商家批准取消未发商品后会恢复相应库存，页面仍明确显示“资金退款暂不支持”和已确认金额0元，不能当作退款到账。

## 完整运行模式（新实例）

此模式使用独立的新运行目录和本机端口，例如18183；目录必须尚不存在，端口必须空闲。它不转换已有预览，不改18180/18181/18182的服务或数据。以下命令从当前工作树执行，后续始终使用同一个 `--root`：

```powershell
$runtimeRoot = 'H:\天俪商城\artifacts\multi-merchant-full-runtime-new'
python -X utf8 help/preview/preview.py prepare --root $runtimeRoot --port 18183 --full-runtime
python -X utf8 help/preview/preview.py initialize --root $runtimeRoot
python -X utf8 help/preview/preview.py storefront --root $runtimeRoot
# 将与当前源码对应并已核同的三端 dist 放到 $runtimeRoot/build/{admin,merchant,h5}/dist
python -X utf8 help/preview/preview.py runtime --root $runtimeRoot
python -X utf8 help/preview/preview.py smoke --root $runtimeRoot
python -X utf8 help/preview/preview.py accounts-file --root $runtimeRoot
```

三端构建入口仍是上述 `build-frontends.py`；它输出默认预览目录，准备完整模式时需将同源构建的三个 `dist` 复制到新目录，逐文件核同后再运行 `runtime`。三个目录都须包含 `index.html`。访问入口与普通预览相同，只将端口换成18183；合成账号仍由 `accounts-file` 保存到该运行目录的私有文件。

`runtime` 通过原entrypoint和Supervisor启动FPM、Workerman、Timer及普通CRMEB队列；marketplace专用队列默认 `autostart=false`，新实例可用下述显式prepare开关启用。服务仅接入专用内部网络，网页由同一实例的nginx转发真实FastCGI和WebSocket。命令先等待原健康检查的版本、MySQL、Redis及9000/40001/40002/40003监听通过，再启动网页并检查原 `/adminapi/login/info`；不使用旧 `preview-health`。Timer、队列是否实际运行还须核对Supervisor及进程，网页和业务仍需单独验证。

完整模式和下述镜像模式均可在 `runtime` 成功后执行 `python -X utf8 help/preview/preview.py queue --root $runtimeRoot`。它使用同一容器的Supervisor管理 `marketplace-queue`，不增加拆分容器；只有STOPPED才执行start，已RUNNING时只读核对PID，其他状态保留并报错。确认运行和资源归属后才保存 `queue_configured`；之后正常 `runtime` 路径按该标记启用，默认仍不自动启动。为新的完整/镜像实例设置开机消费，可在 `prepare --full-runtime` 时增加 `--marketplace-queue-autostart`；该布尔值保存到实例state，后续runtime沿同一配置启动Supervisor。旧实例不会因源码更新自动重建。

完整模式先核固定PHP镜像与当前源码的composer.lock（仅统一CRLF/LF行尾）及已安装包版本/源码引用，随后离线复制镜像中的依赖并按当前composer.json生成自动加载文件。原普通预览仍沿用其既有依赖来源。首次准备将当前应用源码复制到专用 `runtime-app` 卷，日常容器只读挂载；vendor另卷只读，runtime、uploads和私有素材目录可写并交给UID33，私有根权限保持0700。仅在专用卷生成 `.env`（root:33、0640）和安装锁，不在仓库生成凭据。已经完整准备的实例只核对来源、配置、权限和就绪标记，匹配才继续；部分准备现场保留，不能自动覆盖或修补。

正常 `stop --root $runtimeRoot` 保留数据卷；在来源和配置未变时，可用同一 `runtime --root $runtimeRoot` 再启动。这里说明使用方式，不表示已完成重启或恢复验收。完整模式不支持 `serve`、`serve-api`、`video`、`timers`、`timers-restore` 等拆分服务动作。当前复用已有PHP镜像和同源只读应用卷；正式不可变镜像构建、发布前端copy及完整MS6验收仍需另行完成。

2026-10-27已在独立18184实例实际完成首装、原entrypoint启动、FPM/Timer/Workerman/普通CRMEB队列进程核对、完整health、三角色登录及商家商品列表页面。平台、商家、H5和客服入口均与复用构建字节一致。初次18183实例因复制仓库旧vendor造成队列退出，现改为上述锁一致的镜像依赖；原失败记录及停止后的数据卷保留。原18180/18181/18182共17个容器未重建或重启，登录信息接口仍正常。本次未重跑交易全链、普通队列业务任务、停止恢复或完整发布验收。

## 镜像运行模式（新实例）

2026-10-27新18187使用显式 `--marketplace-queue-autostart` 准备，原startup/health和三角色登录通过，专用队列自动启动并消费一条真实合成审计任务。该实例使用工作树 `multi-merchant-release-config`，入口为 `http://127.0.0.1:18187/`；私有账号位于仓库外 `artifacts/multi-merchant-image-runtime-20261027-r3/private/preview-accounts.json`。无需新增浏览器标签即可打开。新发布空卷的九域初始化另经真实copy-up验证；预览原bootstrap路径仍独立使用，不把两者互相代证。完整发布、恢复及私有上传HTTP未由本次结果完成。

新镜像内含九个私有素材目录（UID33、0700）。发布栈的新空命名卷由镜像复制初始化；预览bootstrap使用 `nocopy`，继续自己的隔离建目录和权限准备，两个初始化途径分别验证。既有非空私有卷不会自动补目录或改历史文件。

从当前工作树执行以下步骤，逐条确认成功后再继续。三端须已有对应源码的成功构建；`--image-context` 只核对并装包，不触发构建，不修改旧产物。输出目录和运行目录都必须是新目录，宿主端口必须空闲。示例使用新时间戳生成本地镜像标签：

```powershell
$runLabel = Get-Date -Format 'yyyyMMddHHmmss'
$packageRoot = Join-Path 'H:\天俪商城\artifacts' "image-package-$runLabel"
$runtimeRoot = Join-Path 'H:\天俪商城\artifacts' "image-runtime-$runLabel"
$phpImage = "mall/php:local-$runLabel"
$nginxImage = "mall/nginx:local-$runLabel"
python -X utf8 -B help/preview/build-frontends.py --image-context $packageRoot
$context = Join-Path $packageRoot 'context'
docker build --pull=false -f (Join-Path $context 'docker/php/Dockerfile') -t $phpImage $context
docker build --pull=false -f (Join-Path $context 'docker/nginx/Dockerfile') -t $nginxImage $context
$phpId = docker image inspect --format '{{.Id}}' $phpImage
$nginxId = docker image inspect --format '{{.Id}}' $nginxImage
# 先核对本机及 VPN 路由，再填写两个不同且空闲的 RFC1918 IPv4 CIDR。
$previewSubnet = Read-Host '内部网络 CIDR'
$ingressSubnet = Read-Host '入口网络 CIDR'
python -X utf8 help/preview/preview.py prepare --root $runtimeRoot --port 18185 --full-runtime --php-image $phpId --nginx-image $nginxId --preview-subnet $previewSubnet --ingress-subnet $ingressSubnet
python -X utf8 help/preview/preview.py initialize --root $runtimeRoot
python -X utf8 help/preview/preview.py storefront --root $runtimeRoot
python -X utf8 help/preview/preview.py runtime --root $runtimeRoot
python -X utf8 help/preview/preview.py smoke --root $runtimeRoot
python -X utf8 help/preview/preview.py accounts-file --root $runtimeRoot
```

现有构建不在默认目录时，装包命令追加 `--build-root <已有build根>`。后端和Docker输入仅来自Git已追踪的路径；新源码须先按明确范围加入Git索引，再核 `IMAGE-CONTEXT.json` 包含本次新增文件，不能把未追踪文件视为已装包。工具核对原成功命令记录、当前源码与构建工作副本、产物和H5原afterEmit摘要；将H5、平台和商家页面分别放入public根、`admin/`、`merchant/`，保留后端入口及依赖锁，排除运行配置、安装器和私有数据。`IMAGE-CONTEXT.json` 记录本次来源及复制读回；Admin/商家输出哈希是装包时观察值，不追认为原构建时回执，也不替代旧formal-copy资格。两个镜像须由上面的原Dockerfile实际构建，不能只给旧镜像换标签；构建可能需要原Dockerfile声明的基础镜像和依赖下载。

镜像模式首次prepare必须同时提供两张本地镜像、`--full-runtime` 和成对网段；镜像解析为固定ID后写入本实例state，后续命令只需同一 `--root`。两个网段必须属于RFC1918、彼此不重叠且不与已有Docker网络重叠；操作者还须检查宿主机及VPN路由。当前机器本轮选择 `10.253.240.0/24` 和 `10.253.241.0/24`，这是当时核对后的实例选择，不能作为其他机器或后继实例的固定默认值。程序不清理旧网络来腾出地址。

运行中的PHP/Nginx使用镜像原入口与内置代码、vendor、三端页面和配置，不绑定这些宿主机目录。PHP只挂配置卷到 `/run/application`，镜像内 `/var/www/.env` 链接到其中的 `.env`；该卷仅有私有配置和准备标记，`.env` 为root:33/0640。应用可写数据位于专用runtime、uploads、private-assets三个卷，沿用UID33和私有根0700；MySQL、Redis也各用本实例专用卷。初始化仍通过现有bootstrap使用受控源码和维护账号，日常应用继续仅有DML权限。

`runtime` 等待镜像原health，再开放本机网页入口；FPM、Timer、Workerman及普通CRMEB队列沿原Supervisor启动，marketplace队列默认未启，可用上述同容器 `queue` 动作显式启用。四个默认程序须另核实际进程，`smoke` 的登录成功不能证明队列业务消费。原split/full实例和数据保持，镜像实例同样不支持 `serve`、`serve-api`、`video`、`timers`、`timers-restore` 拆分动作。准备或构建失败时保留原命令、目录及实例现场，不覆盖失败记录或自动修补。

2026-10-27已实际完成3818文件上下文装包和双镜像构建；镜像内PHP应用3809个来源文件、Nginx public的2357个文件全部核同，PHP另有Dockerfile生成的install.lock。独立18185实例已沿原入口启动，health及FPM/Timer/Workerman/普通CRMEB队列四进程正常，三角色HTTP登录成功；实际商家页面读到商品5/6、售价20.15/30.25、库存各100。六个首页/深链入口与镜像装包字节一致；运行中的PHP/Nginx没有应用、vendor、脚本或前端宿主绑定，DML身份和配置卷权限也已实核。已完成配置准备的再次核对只读返回changed=false。

首次prepare因Docker默认地址池耗尽而未创建任何实例资源，原commands/002保留；加入上述显式网段后沿同一首次引导完成，未更改全局Docker配置或清理其他网络。原四个运行预览共21容器的身份、启动时间、重启数和挂载保持，四个登录信息接口正常；原18183停止实例及32个既有网络保持。该次首装浏览器核对后恢复原18180页面，仍仅两个标签。上述首装结果未包含交易全链及队列业务消费，后续结果如下；真机、原formal-copy资格、完整MS6和正式上线验收仍未完成，用户停止的样本保持NOT RUN。

2026-10-27后续在同一18185实例，合成员工合法认证后通过原 `AuditLogJob::dispatch` 投递一项 `merchant.profile.read`，Supervisor常驻marketplace worker实际消费，产生唯一审计记录，pending/reserved/delayed均为0。marketplace worker及原四个默认程序均RUNNING。HTTP登录和 `me` 本身不自动投递任务；这只证明该项原生产任务的正常消费，不代表订单异步任务或完整Worker验收。

`trade-smoke.php` 继续使用原单店正常HTTP建单与金额、库存断言，请求地址按镜像/完整/拆分模式分别使用本实例web的80/8080端口或app的8000端口。新完整/镜像回执为UID/GID33:33、0600；已有完整/镜像回执只核验，不修补异常归属。18185实际创建2件单价20.15元、共40.30元订单，库存100→98；顾客页面确认测试支付、商家页面发出2件、顾客页面收货2件，平台列表及详情显示测试支付40.30元、已收货、已发2/已收2和1个包裹。原生独立复核通过；这些合成数据结果不代表真实收款或生产验收。

18185旧镜像的平台登录“消息队列”误提示源于 `AdminLoginListener`：`queue_open=0` 时仍先读取不存在的runtime `.queue`，异常使关闭配置的豁免未执行。18185镜像及实例保持原样；新候选仅调整检查顺序，先判断队列是否关闭，开启时仍读取 `.queue` 并严格比较原key，Timer的70秒检测保持不变。

2026-10-27在独立18186使用新PHP候选镜像、复用已核同的原Nginx镜像，原入口health、三角色smoke及实际平台登录通过，登录结果queue/timer均为true。UID33实读确认 `console_remind=true`、`queue_open='0'`、`.queue` 仍不存在且运行中的listener与候选源码哈希一致；真实浏览器登录后首页没有队列或Timer通知，未关闭提醒来掩盖问题。截图保存在 `artifacts/multi-merchant-image-runtime-20261027-r2/admin-login-without-false-warning.png`。这项正常页面验证不替代完整MS6、真实支付或生产验收，用户停止的样本仍为NOT RUN。

## 发布 Compose 隔离首装（新实例）

`--release-runtime` 复用 `help/docker/docker-compose.optimized.yml` 的 `mysql/redis/phpfpm/nginx` 四个日常服务；`compose.release.yml` 仅提供隔离资源和临时引导接点。它要求新的运行目录、空闲端口、已核同的双镜像与两个空闲网段，并显式指定独立认可的检查器完整镜像ID。使用上文镜像准备方式取得这些输入，再逐条执行：

```powershell
$releaseRoot = Join-Path 'H:\天俪商城\artifacts' ("release-runtime-" + (Get-Date -Format 'yyyyMMddHHmmss'))
$checkerImage = Read-Host '事先独立认可的检查器 sha256 镜像ID'
python -X utf8 help/preview/preview.py prepare --root $releaseRoot --port 18188 --full-runtime --release-runtime --php-image $phpId --nginx-image $nginxId --checker-image $checkerImage --preview-subnet $previewSubnet --ingress-subnet $ingressSubnet --marketplace-queue-autostart
python -X utf8 help/preview/preview.py initialize --root $releaseRoot
python -X utf8 help/preview/preview.py storefront --root $releaseRoot
python -X utf8 help/preview/preview.py runtime --root $releaseRoot
python -X utf8 help/preview/preview.py smoke --root $releaseRoot
python -X utf8 help/preview/preview.py accounts-file --root $releaseRoot
```

数据库首次即属于该发布栈。MySQL不自动建立应用库或高权限应用用户，仍由原create-only引导建立数据库、维护用户和仅DML的日常用户。临时bootstrap只有显式运行时启用；日常apply不启动它。专用根密码和连接配置只保存在本实例私有卷及受ACL保护的 `private/release-environment.json`，不要把它们或展开的Compose配置放进日志或Git。`empty.env` 不包含凭据。

日常应用配置由实际 `.env.application.example` 生成，填入本实例连接、认证配置和已确认自营商家ID，使用 `APP_ENV=production`，七个业务开关保持false。此模式用于发布运行检查，**不提供测试支付或交易体验**；交易体验继续使用原testing预览。初始化自身仍采用原隔离testing流程，不能拿引导进程环境代替日常应用配置。bootstrap私有夹具卷和日常 `private-assets` 分开，后者从镜像首次初始化九个私有目录。

`runtime` 准备配置后调用原 `deploy-marketplace.py --apply`，经过真实版本检查、固定镜像启动、Nginx刷新后健康检查及PHP具名常驻进程就绪检查，成功才报告入口可用；不直接启动另一套preview应用服务。原拆分动作在此模式不可用。失败保留原回执和实例，不自动覆盖配置、放宽权限或清空数据。此模式不修改已有实例，不代表已获生产部署授权或已完成完整MS6及恢复验收。

r44已补发布就绪消费者：四默认程序必须RUNNING，prepare显式`--marketplace-queue-autostart`时再要求第五项；APPLIED附固定runtime READY及具名结果，默认CHECKED不变。控制socket仍root/0700，www-data健康检查保持，不由检查自动启动或修复程序。新18196/18197分别实际通过四项/五项、两次默认CHECKED和各两个正常GET；两套验证实例随后正常停止并保留数据卷，当前交易预览18180及其22订单/24明细保持。详细行为、有限范围与保留失败见[发布说明](../docker/README.md#发布就绪的常驻进程补验2026-10-28)；只证明进程就绪，不代签任务消费、真机或生产运行。

2026-10-27本机新18188已沿上述路径完成原发布四服务首装、默认CHECKED、实际APPLIED与刷新后健康确认，五个Supervisor程序RUNNING。平台/商家/顾客真实HTTP登录通过，商家商品列表读到两件原合成商品及20.15/30.25价格、各100库存；生产默认开关关闭时，公开列表正确返回无自营商品。实际私有卷九目录UID/GID33、0700，日常数据库角色仅DML，认证与schema检查通过。浏览器首页/商家登录入口已打开核对，原两页随后恢复；完整交易和浏览器角色操作不由本批代验。运行目录为 `artifacts/multi-merchant-release-runtime-20261027-r1`，私有账号仍在其 `private/preview-accounts.json`；汇总位于 `artifacts/multi-merchant-release-compose-20261027-r1/verification.json`。旧41容器及原绑定控制源码保持，生产部署及完整MS6尚未完成。

## 操作顺序

1. 使用 `accounts-file` 输出的本机私有文件取得平台、商家、顾客三个合成账号；顾客登录页选择“账号登录”。
2. 平台“经营商家 → 入驻商家”可建立、审核商家并配置接单/履约开关；“建立管理员”返回仅本次显示、24小时有效的一次性首次密码设置链接。尚未设密码的有效员工链接过期或丢失时，有独立权限的平台管理员可在审核通过、未锁定且未关闭的商家行点击“补发首次设置链接”，输入该商家已有员工账号，确认后旧未使用链接全部作废、新链接有效24小时；账号和权限保持，已设密码员工不能通过此入口重置密码。新员工亲自完成设置，再返回员工登录页登录。链接仅在本次弹窗中交付；首设页读取凭证后会清除地址栏参数，刷新该页会丢失当前凭证。初始化已提供一个可以直接登录的合成商家账号。
3. 商家“商品列表 → 新建商品”：填写名称、分类、售价，选择本店图片并保存；在已保存SKU上调整库存，填写入库原因，再提交审核。
4. 平台“经营商家 → 商品审核”审核通过后，商家回商品列表点击“上架”。顾客首页、所属店铺及商品详情均读取同一已上架商品。
5. 顾客选择商品/规格/数量，加入购物车并按一家店结算，或立即购买；确认收件地址和服务端金额后提交订单。在订单详情进入付款方式，明确选择并确认测试支付。尚未付款时也可在订单详情点击“取消订单”：弹窗可选择“保留订单”或“确认取消”，提交期间付款与取消按钮均禁用，结果以原页重新读取的状态为准；取消成功后相应库存释放。
6. 商家“订单列表 → 详情”选择本次发货数量，填写合成承运信息和物流单号，核对并提交。顾客进入订单内“查看包裹”，确认本包裹实际收到的数量；平台订单详情与商家详情可以查看同一保存结果。
7. 顾客在已收货订单中进入“商品评价（资格及记录）”，查询原商品资格，填写评价并按需点击“选择并上传公开图片”；上传完成后提交。平台“经营商家 → 商品评价”审核通过后，商家可在“商品评价”回复。顾客原评价页及商品公开评价列表可以读回审核结果、公开图片和回复。公开评价图片不要使用私有售后凭证。
8. 另选一张已测试支付但尚未发货的订单，进入“售后服务”，选择“取消未发商品”，填写实际申请金额、原因及数量，并上传至少一张合成私有凭证。提交后商家在“多商户售后”打开记录，点击“批准申请 → 核对本次处理 → 确认提交”。平台和顾客可读取同一业务、实物和资金状态；批准取消后相应商品及SKU库存恢复，资金退款仍未完成。

多规格商品沿用原商品和SKU：新建页选择“多规格”，填写规格名称和选项，点击“生成规格组合”，逐行填写售价。生成后选择第一张本店图片会填入各行空图片；也可以逐项选择SKU图片。保存后分别调整各SKU库存，提交审核、审核通过再上架。顾客购买时先选择规格，再设置数量；切换规格会重新设置起购数量，提交前以确认页金额为准。

2026-10-27已在18180原平台页面为商家3员工2正常补发一次：旧首设记录2终止且保留历史字段，新记录3有效期恰好24小时，成功审计94仅记员工编号和终止数量1。员工完整行、商家、商家角色及平台角色均未改变，原商品、订单、资产、余额积分和客服资料保持。后台构建、PHP8.2语法与独立源审通过；1280/375弹窗完整，成功提示与按钮可区分，设置链接默认隐藏，关闭后清除；正常复制后已打开原首次设密页。用户本人已在原页面完成设置，员工密码初始化状态及新凭证消费已实核；随后在localhost:18180正常登录，原本店信息显示“浏览器体验商家 / 浏览器体验管理员 / 管理员”，刷新后身份保持。127.0.0.1原商家2草稿来源保持独立，最终业务快照与首设后相同。证据见同一trade目录的 `r28-menu-*`、`r28-reissue-data-*`、`r28-reissue-verification.json`、`r28-runtime-check.json` 及截图；停止的负向、并发、故障、恢复样本仍NOT RUN。

2026-10-27已通过正常页面发布商品8“隔离预览多规格商品”：基础款SKU107为19.90元、库存8，升级款SKU108为29.90元、库存5。顾客H5从首页进入商品，选择升级款2件并保存59.80元未付款订单13，再点击“取消订单 → 确认取消”。升级款库存5→3→5，基础款始终8，总库存13→11→13；唯一预留14及释放15均为2件且正确关联。顾客、商家和平台读回相同规格、数量、金额及未支付/已取消状态，两端刷新仍保持。原商品、订单、资产、余额积分、素材和客服记录保持。可从首页打开此商品或商家商品列表继续操作，已有取消订单保留作核对，不必重复创建。本批未支付、未发货；小程序后续正常结果见r32，真机仍待验，H5结果不代签。证据见同一trade目录的 `r29-product-*`、`r29-order-*-three-role.json`、`r29-multisku-data-*` 和页面截图。

r32已实际通过320宽度小程序原生操作：首页“分类 → 全部商品 → 隔离预览多规格商品 → 立即购买”，先选升级款，再按数量加号设为2，点击“确定”进入原确认页，核对升级款×2、单价29.90及总额59.80元后提交。实际保存未付款根单16，只有SKU108库存5→3；再点原“取消订单 → 确认取消”，原库存回5、基础款仍8，总库存回13。唯一预留17/释放18均为同SKU两件且正确关联，三端同一规格、数量、金额及已取消/未支付状态；重新打开原订单仍显示“订单已取消”，无pending或错误。原15单和明细、其他资料保持，最终全部商品/SKU摘要与本轮开始一致，未支付或发货。原窗口样式、规格/数量/提交和确认控件正常，没有应用源码改动或重新构建；继续使用上述r31工程。证据见同一trade目录 `r32-*-320.png`、各入口JSON、`r32-created-three-role.json`、`r32-cancelled-three-role.json`、`r32-multisku-data-*` 与 `r32-cancelled-refresh-runtime.json`；不代签未登录320、真实微信、真机或停止样本。

已收货商品退货：顾客从原订单的“售后服务”选择“退回已发商品”，按实际包裹选择商品序号并上传合成凭证。商家打开申请，点击“批准申请”，填写本次回寄收件人、联系电话和完整地址，核对后确认。顾客重新读取售后详情，可看到批准时保存的地址，再填写承运商及运单号；商家实际收到退件后，按对应序号登记验收。平台、商家和顾客均显示相同回寄地址及退件状态。地址固定在本次批准中，之后保存运单和验收不会改变；历史无地址记录保留原样，不自动套用平台地址。

回寄地址仅对新批准的“退回已发商品”必填，取消未发商品和仅申请金额保持原流程。实物验收恢复对应库存，保留原订单已收货记录；本机合成测试不会实际寄件，也不代表资金退款到账。

撤回与拒绝申请：顾客可在待处理售后详情点击“撤回此申请”；商家可在待处理详情点击“拒绝申请”，填写原因，经核对后确认提交。两种处理都结束本次申请并释放其占用金额，不取消原订单，也不调整库存。顾客可回到原订单售后入口重新读取额度并按实际诉求另行申请，原记录保留。拒绝后的三端详情分别显示顾客申请原因与已保存的拒绝原因；原因来自该次处理记录，不能把申请理由当作商家的处理说明。

2026-10-26 已验证正常预览：真实HTTP创建2件共40.30元的订单后，顾客浏览器测试支付、商家浏览器发货、顾客浏览器收货均成功，三端同为已收货2件，商品及SKU库存100→98。随后完全通过浏览器新建12.50元商品、入库20件、平台审核、商家上架、顾客从首页/店铺加购并下单、测试支付、商家发出1件及顾客确认收货；只读回查订单为已支付/已收货，商品和SKU库存均为19。

后续已通过该已收货商品的无图文字评价、平台审核、商家回复及公开展示正常链。多商户收银台完成320/375/1280/1440浏览器视口检查，加载提示、未选择/已选择状态、暂不支付返回及手机尺寸下测试支付均已实际操作。另建1件12.50元的合成订单，经测试支付后商品和SKU库存均为18；再由顾客上传私有PNG并申请取消未发商品，商家批准后两者均恢复为19，三端显示已批准/未发商品已取消/资金退款暂不支持，已确认金额仍0元。

基础体验商品的已收货订单另完成带图评价2：实际文件选择上传 `preview-review.png`、提交、平台审核和顾客公开列表展示均通过。只读回查确认公开素材已绑定该评价，原文件名、1054字节、320×240尺寸及SHA256与上传文件一致；公开HTTP返回200且图片字节哈希一致。售后私有PNG已绑定对应申请，授权商家页面实际加载同尺寸图片。这些结果仅证明本机合成数据正常流程；新商家员工首次密码设置及登录串联、其余页面、真机和正式阶段验收继续保留，资金退款与其他售后分支尚未完成。

未付款取消正常链已通过：顾客实际创建1件12.50元的订单，进入收银台后选择“暂不支付”，先在取消确认中保留订单，再确认取消。详情原地重新读取为已取消，付款与取消按钮消失；商家和平台同为未支付/已取消。只读回查保留原金额、paid=0/is_cancel=1，一次库存预留19→18及对应的一次释放18→19，取消日志一条。新按钮通过320/375/1280浏览器视口的边界与居中检查，确认期间两按钮均禁用。V1未付款金额在顾客和平台详情中标为“订单金额”，平台支付方式标为“未支付”；已支付测试订单及旧订单的显示分支保留。手动取消与自动超时的验证结果分别记录。

自动超时正常链已通过：顾客浏览器在17:29:25创建1件12.50元的合成订单，原服务保存17:30:55截止；原Timer在17:31:00自动关闭，未执行手动取消。原生记录为paid=0/is_cancel=1，唯一system释放关联原预留，商品和SKU库存19→18→19，取消日志一条；顾客、商家与平台读回同一结果。期间将新单配置从90秒恢复原值0、再设为30分钟，已有订单保存截止保持不变，最终数据库及两处配置缓存均为0.5小时。V1取消提示统一为“订单已取消”，不会把系统超时归因于用户手动操作。

接线时发现原调度器动态写入Workerman的task_ids在PHP8.2触发错误，已改为回调闭包持有任务句柄，原调度周期与更新时间处理保持。修正前未按时关闭的样本和日志保留，修正后由原任务处理；上述正常样本为修正后新建。当前非root容器启动仍有Workerman无法将UID1000映射到用户名的提示，实际主/子进程持续运行及业务关闭已核实；不以启动日志或容器running代替业务验证。完整Worker阶段验收与用户停止的样本不因此改为通过。

已收货退货正常链已通过：顾客为已收货的1件12.50元商品申请退货并上传私有凭证，商家填写回寄地址后批准，顾客保存合成运单，商家验收第1件。三端均为已批准/退件已验收/资金退款暂不支持，已确认退款0元；唯一return_in关联原预留与该售后，商品和SKU库存19→20，原付款订单及包裹的已收货状态保留。批准、运单更正、验收各阶段的回寄地址一致。商家表单375宽度与详情1280宽度、顾客地址及结果375宽度已检查，不能代替真机和完整阶段验收。

正常联调同时修正了顾客运单已保存却提示结果未确认的问题：原页面按JSON对象字段顺序比较返回值，数据库改变字段顺序后会误报。现在在原字段闭集和类型校验之后，严格比较承运商与运单号的值。原失败记录保留，修正后的真实页面完整保存与读回已验证。

撤回与商家拒绝正常链已通过：在原已收货40.30元订单上先申请10.00元并由顾客撤回，再另次申请10.00元并由商家填写原因拒绝。两次占用均从10.00元归零，可申请额度恢复40.30元；商品及SKU库存98、销量2保持不变，没有增加库存流水，原订单仍为已收货。三端列表和详情保留两条已结束记录，拒绝原因与商家原提交文字一致。原因仅补入当前读取结果，原动作回执和历史摘要未改。

本批还修正平台从售后详情返回列表出现空表的问题：列表、商品和退件表使用独立组件标识。原撤回与拒绝记录分别进入详情再返回，都能正常显示4条现有记录，无需整页刷新。新增拒绝说明通过顾客375宽度、商家375宽度及桌面页面检查；这些正常预览结果不替代完整阶段、旧客户端和真机验收，停止的测试仍保持NOT RUN。

2026-10-27 H5当前源码接入与仅申请金额批准（r38）：18180已接入本工作分支的uni-app静态产物，个人中心售后入口采用原装修底部的列表行，320/390宽度已实际点击进入；H5原订单14正常显示已收包裹，已取消订单16只显示一次“订单已取消，暂无履约包裹”。顾客从8.88元已收货订单上传新私有合成PNG并申请1.00元，商家3由本店原订单入口核对并批准，顾客、商家及平台实际页面均为已批准/无需退件/资金退款暂不支持。申请及批准后确认金额0、占用1.00、可申请7.88；批准不表示退款到账。新凭证归属本申请，授权商家实际下载的1054字节、320×240及SHA256与上传件相同。原16单、商品/SKU库存销量及库存流水、支付记录和7服务身份保持；没有重建服务。完整N1仍待：本次额外观察器对旧动作/凭证的原行摘要因二进制列无效，不能据其签完整字节保持，失败原件及纠正均保留。源码/构建、正常页面和完整验收分别记实，停止样本仍NOT RUN。

商品换图：商家在“素材库”建立图片分类并上传公开商品图片，再进入已有商品编辑页。移除轮播中的旧图并选择本店新素材后，第一张图成为主图；SKU 图片在对应规格行单独选择。保存内容修改会让商品重新进入待审核并下架，平台审核通过后由商家重新上架。移除商品引用不会删除素材库原文件，也不会改写已下单商品的图片快照。

下单填写资料：商家在商品编辑页添加资料项，填写标题、选择原有文本、数字、邮件、日期、时间、身份证、手机号或图片类型，并按需设为必填，最多10项。保存实际变更后沿商品审核、上架流程发布；顾客在确认订单时按商品填写资料并上传合成参考图。图片使用订单私有资料接口，不能替代为公开商品素材；已经生成的订单保留当时的资料快照。旧商品未修改表单时，普通商品编辑保留原定义及默认值；新建或实际修改的标题要求非空且最多128字。

表单图片的窄正常源码检查：将 `PREVIEW_VUE_MODULES` 指向已有且包含 Vue 2 的 `node_modules`，运行 `node help/preview/check-order-form-reactivity.cjs`。检查真实组件初始化和异步图片显示的响应式依赖，不访问应用、认证或 HTTP；实际上传、私有图片读取和订单保存仍需通过页面验证。

下单资料正常闭环已通过：商家为商品7配置必填定制说明与参考图片，保存后由平台审核、商家上架；顾客真实填写、选择合成PNG上传并创建1件12.50元订单，再完成测试支付。顾客、商家、平台均实际显示同一保存文字和320×240私有图片。只读回查资产4绑定根订单9，1054字节及内容哈希与上传原件一致；商品和SKU库存20→19、销量0→1，只有一次库存预留，原8张订单及8条商品快照未变。

联调修正了确认页两处响应式依赖：页面初始化前计算图片开关时过早读取非响应式生命周期标志，导致不发起图片读取；首次渲染时图片字典未被订阅，导致异步图片不更新。修正保留原身份、页面状态及私有读取检查。真实Vue检查保留修前失败和修后通过，顾客端完整构建成功后实际票据/内容读取均返回200；确认页和顾客保存详情375宽度、商家与平台桌面详情已检查。先前等待构建而自然过期的两次确认及临时图片保留，未生成订单；不计为恢复测试，也不代替真机或完整阶段验收。

素材到商品展示的正常链已通过：商家真实建立分类、选择本机合成 PNG 上传，将其用于原商品主图和 SKU 图，保存后待审核/未上架；平台批准、商家重新上架后，顾客首页与详情实际加载同一新图片。上传文件及公开图片均为1054字节、320×240，内容哈希一致；原商品7/SKU106身份、12.50元价格、库存20及销量0保持，6条历史订单商品快照未变，原素材仍保留。编辑页保存说明已与实际审核流程一致，并在375宽度检查；临时说明验证草稿已还原、未再次保存。以上仅为本机合成正常预览，不代替其余素材类型、私有表单、真机或完整阶段验收。

平台客服预览接线已验证：原先误返回顾客端HTML的客服入口现可显示客服登录页和工作台；九个客服及三端入口的HTML归属、四个后台首屏资源正常，浏览器聊天与通知WebSocket均完成101握手。顾客从实际订单点击联系客服后，经正常聊天连接进入离线留言，保存成功并返回原订单；平台读到留言1（合成顾客UID3），处理后列表显示已处理，重新打开备注与保存文字一致。随后用户已亲自建立关联用户与客服账号；正常平台进入工作台、顾客与客服双向文字及私有图片收发、刷新历史读取已通过。专用预览开启现有私有聊天写入，原身份、房间、票据及资产绑定检查保留。客服选图钩子按ElementUI合同拒绝自动旧上传，避免私有消息已保存后又额外上传报错；普通文本粘贴不再触发图片提示，顾客订单预览仅在副价格存在时显示它。旧公共图片上传、其他旧客户端和完整阶段验收仍有待完成，不能以本次正常聊天替代。用户停止的测试保持NOT RUN。

V1咨询订单卡片正常链已通过：顾客从自己的订单进入聊天并点击“发送客服”，客服可实时收到只读摘要，刷新后仍可从历史记录读取。摘要显示订单号、保存的商家名称、下单时间、全部商品及规格、数量、下单单价和商品总数量；已付款显示“实付金额”，未付款显示“订单金额”。正式顾客端点击自己的卡片仍进入原订单详情。客服摘要没有旧订单管理按钮，不请求旧客服详情；旧客服对V1订单的管理详情、改价、退款和发货权限没有开放，legacy订单卡片保持原行为。

本次实际检查包括客服桌面与375像素手机视口（本机浏览器，非真机），以及顾客打开自己的订单详情，均为本机隔离合成数据。实时已付/未付卡片及已付历史证据见仓库外预览目录 `browser/platform-chat-order-card-live-paid.json`、`platform-chat-order-card-live-unpaid.json`、`platform-chat-order-card-paid-history.json`；移动客服历史、实际375像素边界与截图见同目录的 `platform-chat-order-card-mobile-history.json`、`platform-chat-order-card-mobile-geometry.json`、`platform-chat-order-card-mobile-375.png`。源码渲染与构建输入/HTTP字节核对分别记录在同目录的 `platform-chat-order-card-frontend-check.json` 和 `platform-chat-order-card-build-verification.json`。其他appChat旧客户端壳、全部客户端流程、真机及正式阶段验收仍待完成；本次卡片交付不代表完整客服订单管理，也未修复旧公共图片上传。

顾客头像正常链已实际验证：进入顾客端“设置”，点击头像选择图片，再点“保存修改”。本机顾客3选用合成PNG后，具名 `user/avatar/files` 保存顾客资产5为STAGED，`user/edit` 实际保存后转为BOUND；重新打开设置页的 `user` 返回同一头像并实际加载。原图、保存文件均1054字节、320×240，内容哈希一致；上传计数5→6，保存不重复上传。375×812浏览器视口已检查，原商业附件、商品/库存、订单、聊天消息、其他用户及其他资产保持。此为现有源码的正常运行验收，未重建或改写上传业务；没有独立裁剪UI，不能把圆形头像显示称为图片已裁剪。证据见仓库外预览目录 `browser/customer-avatar-verification.json`。

旧通用上传入口仍不补0或默认自营商家。旧评价、非V1订单表单和头像分别使用具名接口；顾客聊天在私有能力开启时已走私有消息链，能力关闭时仍留有旧公共图片分支，兼容行为待处理。不能以单项正常预览代替其余旧客户端或完整资产阶段验收。

旧版订单资料图片：在18181专用旧模式预览，用顾客端打开商品6并点击“立即购买”，填写“资料说明”、选择“参考图片”、选择原合成收货地址后提交。订单金额30.25元；本次只验证创建和图片保存，不需再次支付。顾客订单详情和平台“订单管理→详情”读取同一私有图片；点击图片可放大。图片上传前需有当前有效订单确认，旧确认过期时从商品重新进入。

新图片值保存为 `legacy-form:v1:<id>`，沿原订单事务绑定；历史普通URL和V1分组表单继续原流程。确认页及顾客、平台、旧移动管理分别使用具名鉴权读取，私有图片不生成公开链接。图片处理或提交结果未确认时，页面保留草稿并提示先核实原订单，不自动重复上传或提交。

旧版资料图片正常接口及浏览器链已通过：接口根订单3/资产3之后，顾客实际选择地址、填写资料、选图并提交，保存订单4（cp637815988682752000）与资产4；金额30.25元、未付款，库存98→97、销量2→3，余额与积分保持。资产仅从暂存转为绑定并记录根订单和时间，原三订单及商品项、旧资产、评价和附件表摘要不变。顾客刷新详情与平台重新打开详情都显示同一320×240私有图，两端鉴权读取1054字节及哈希与原件一致。确认页和顾客详情固定320/375宽度、平台桌面及原图放大已检查；运行中直接改变视口曾保留旧尺寸，证据保留，不宣称所有动态缩放场景已通过。

联调修复了两处实际正常阻断：原请求过滤将必填标记转换为字符串，现仅兼容其有限等价值；旧确认响应已经完成时，页面依据当前身份与确认 key 解除准备状态，不再错误等待V1绑定。V1与历史普通URL保持。两端真实构建通过，H5记录为SECOND_PASS_OBSERVED，10个修改的前端文件与构建输入一致。证据位于该预览目录的 legacy-form-ui-verification.json 及所引接口、原生回查和浏览器材料。移动管理、礼品、拆单、真机及完整阶段验收未因此完成，停止的测试保持NOT RUN。

手机客服：已登录的客服可打开 /kefu/mobile_list，从用户列表进入对话，点击底部“更多→图片”选择合成图片。上传中和消息已保存均显示为文字状态；顾客可以实时收到并回复图片，客服刷新会话后仍能读回，点击图片可查看原图。私有图片继续沿真实房间及参与者读取，不生成公开图片链接。

本次正常页面验证使用375×812浏览器视口，已完成客服与顾客双向图片、客服刷新历史及私有原图读取。新增消息10/11与资产4/5分别属于原顾客3和客服2；原9条消息及3条资产保持。另从原 /kefu/appChat 入口正常取得临时游客租约，用该实际游客打开原客服会话页：初始历史为200/空数组，正常下拉加载后结束，客服发送首张私有图片后游客实时显示，消息12与资产6绑定该真实游客房间。游客未创建持久账号，也未开放上传；客服主动首图前后游客列表均为空，源码确认原记录仅创建接收方视角，客服视角记录要等游客主动发言；本次保留原写入顺序，不声称已验证从游客列表发起首个会话。

两处旧移动聊天组件现在允许合法空历史，且空会话下拉时不读取不存在的首条消息或滚动锚点，原非空分页保持。两组件正常方法与SFC检查通过，最终后台构建成功并在18180/18181服务一致。三个新图片文件均1054字节、320×240且哈希与原件一致；六张业务表摘要、顾客/客服余额积分不变。源码检查与实际页面结果分别保存在仓库外预览目录 browser/mobile-chat-history-after-r2.json、platform-chat-mobile-empty-pull-r2.json、platform-chat-mobile-guest-first-image.json，以及 platform-chat-mobile-guest-native.json。另一 appChat 移动壳的实际登录/空历史、其他完整客户端流程和真机仍待验；旧公共上传及外部客户端发布条件仍开放，用户停止的样本保持NOT RUN。

旧顾客移动聊天页：先在同一地址的商城H5正常登录，再用手机浏览器打开 /kefu/mobile_user_chat；带订单咨询可打开 /kefu/appChat?orderId=<订单号>，手机跳转会保留订单参数。该页复用商城原登录，不需再次设置客服账号，也不把平台/客服登录当顾客身份。读取订单走现有顾客本人详情接口，登录凭据只放请求头；发送使用读取结果的正式订单号。子单或无可咨询商品的订单会提示从主订单进入，不伪造父单；原客服管理权限保持。

本机已验证顾客3在这个旧移动壳实际发送文字13、选择私有图片14，客服回复图片15，两端实际显示且原图可放大；资产7/8绑定原真实房间，旧12条消息/6条资产以及订单、库存、余额积分保持。随后从通用入口带订单9进入，实际本人详情200、页面显示12.50元，点击“发送客服”保存消息16；双方实时和刷新历史摘要一致，且与已有消息8的同订单快照一致。订单卡片仍是只读咨询摘要，旧卡片导航源码已改用顾客自己的商城详情，实际legacy卡片点击留待有对应正常会话的环境验证。

本轮还修正了保存主题样式覆盖手机字号、以及手机字号被主题恢复到桌面的问题：先恢复主题，再应用现有手机适配；平板/桌面移除仅供手机的内联字号，保留主题配置。320/375手机视口及1280桌面实际检查通过；原缩小文字、窄成竖条的卡片和桌面字号异常证据保留。方法/SFC检查、独立源码复核及最终后台构建通过，四个前端输入和两预览服务一致。汇总见仓库外预览目录 browser/appchat-mobile-entry-verification.json。以上为浏览器模拟，桌面旧appChat的外部Cookie来源、真机、旧公共上传和完整阶段仍未完成；停止的样本仍NOT RUN。

旧版退款申请凭证：在18181专用旧模式预览中，顾客从已付款且可申请的原订单点击“申请退款”，选择原因、填写备注并上传合成图片，再提交申请。提交后从退货列表进入该退款单的详情查看“申请材料”；平台从“订单 → 售后订单”查看列表材料，或“更多 → 订单详情”查看并放大原图。退款详情使用新退款编号，不能用原订单号代替。顾客材料区直接展示原图，当前没有独立放大按钮。

本机正常页面链已通过：旧订单2申请1件、30.25元，保存退款单1与私有资产5，顾客刷新及平台列表/详情均实际读到320×240图片。资产由STAGED转为BOUND并关联原根订单和新退款单；文件1054字节及SHA256与上传原件一致。原订单、商品库存、顾客余额积分、旧资产及评价保持，仅所选商品项的退款申请数量0→1。本次只提交申请，未批准或执行资金退款。未修改业务源码、开关或通知配置，复用现有构建。证据见旧模式预览目录 `legacy-refund-ui-verification.json`；移动管理、真机及完整阶段验收仍待，停止的样本保持NOT RUN。

旧版寄回凭证：顾客选择“退货并退款”提交后，平台在该申请的“更多 → 同意退货”确认；这一步只允许退回，不执行资金退款。顾客刷新退款详情后点击“填写退货信息”，选择现有快递公司、填写运单和联系方式、寄回备注并上传图片，再点“提交”。原申请说明和图片保持，寄回备注及图片单独展示；平台列表和详情可读取两组材料并放大原图。真实退货前应在平台“订单配置 → 售后退款配置”填写收货人、电话和地址；未配置时页面继续提示联系管理员。

本机已实际完成旧订单1的退货申请2、平台同意退回及顾客寄回信息保存：状态2→4→5，申请资产6及寄回资产7分别绑定该退款单，两张图片均320×240、1054字节且与各自原件哈希一致。顾客刷新与平台列表/详情读取通过，原申请说明、前一退款单及资产保持，原订单、库存、余额积分和资金账单不变；无实际寄件或资金退款。联调修正了顾客详情把待寄回/已寄回都显示为“审核中”的文案，现分别提示填写寄回信息、等待商家收货处理，业务状态和操作权限保持。PHP8.2语法及独立源码复核通过；证据见旧模式预览目录 `legacy-return-ui-verification.json`。真实收货退款、物流查询、移动管理及真机仍未验。

商品视频：商家进入“商品列表 → 编辑 → 选择商品视频”，在原视频选择器点击“上传本店视频”，选用 MP4 后保存商品。保存会重新进入待审核并下架；平台在“经营商家 → 商品审核”通过后，商家再次上架，顾客商品详情的首个轮播项即可播放。这里复用原商品、SKU、素材及播放器；视频属于公开商品素材，商品审核控制的是商品展示入口。

本机已通过商品7的正常页面闭环：商家2上传192641字节、320×240、6秒H264合成MP4，保存附件94，完成审核与上架；顾客实际播放时间从0.2836秒推进到6秒并结束，刷新后仍读到同一视频。公开HTTP 200、video/mp4、物理文件和上传原件字节哈希一致。SKU106、售价12.50元、库存19及原订单、私有材料、余额积分保持，原两份附件未删除；上传、保存、审核和上架分别产生原审计记录69–72。商家重新进入编辑页也正确显示原视频及已上架状态。本轮无业务源码修改、前端重建或服务重启；证据见主预览目录 product-video-ui-verification.json。

上述小视频验收时，预览PHP单文件限额为2MiB，POST为8MiB，nginx为20MiB，分片与云上传均关闭；这次小视频正常链不代表业务50MiB上限、分片或云供应商已验收。浏览器375像素视口无横向溢出，实际手机设备及完整阶段验收仍待；停止的样本保持NOT RUN。

大视频分片已在主预览完成正常页面验证：51,549,331字节（约49.16MiB）、1280×720、8秒H264合成视频，通过原17片上传和合并生成商家2附件95；每片及整文件哈希与原件一致。商品7保存后待审下架，经平台审核、商家上架，顾客实际播放0.2726→8秒结束，双方刷新仍读到相同视频；SKU106、12.50元、库存19、三份旧附件及原业务数据保持。原600秒TTL到期后，原Timer自然清理完成会话的临时JSON和数据文件，正式附件/MP4保持，清理后公开HTTP200及字节哈希仍正确。原orderCancel任务与素材GC并存，日常App仍仅DML权限，旧兼容预览实例未重启且分片关闭。

接线过程中修正了原GC身份查询中current_user别名的MySQL引号，以及内置HTTP服务器继承FPM停止信号的问题；保持原认证、归属、大小、配额和回收算法。PHP语法、Python解析、compose配置、原登记与重复核对、独立源码/证据复核均通过；未重建前端。证据见主预览目录 product-video-chunks-verification.json。该正常大文件结果不替代云供应商、实际手机设备或完整阶段验收；停止的样本保持NOT RUN。

本说明是操作入口；各功能实际完成状态统一维护在仓库外现有 `current20261025-business-progress-r1/PROGRESS.md`。

商品详情音频：商家进入“商品列表 → 编辑 → 追加详情音频”，在原选择器上传本店音频并选择；音频会追加到已有详情末尾。保存后重新待审并下架，平台审核通过、商家上架后，顾客在“产品介绍”中点击原生音频控件播放。这里复用原商品、SKU、素材及详情解析器；音频为公开商品素材，审核控制商品展示入口。

本机已通过小MP3正常链：5秒低音量合成音，60,857字节，44.1kHz双声道，新增商家2附件96。商品7原说明后仅追加一个audio，详情在数据库编码一次、读取解码一次；上传后商品未变，保存/审核/上架正常完成。顾客实际播放0.1627→5秒结束，刷新后再次播放结束，播放器error为空；375像素浏览器视口无横向溢出。源文件、落盘文件与公开HTTP200/audio/mpeg的大小和SHA256一致；原两视频文件、视频链接、SKU106、12.50元、库存19、自定义表单及原订单/资金/私有材料保持。

本批复用既有实现和构建，无业务代码、权限、开关或服务重启。证据见主预览目录 product-audio-ui-verification.json、browser/product-audio-playback.json；大音频、其他格式、云供应商、真机和完整阶段仍待验，不能把本次小MP3正常链当作50MiB业务上限已验收。停止的样本保持NOT RUN。

MS6版本检查：新源码在业务App启动时检查固定发布描述和数据库兼容元数据，功能开关关闭也不豁免。新预览的原维护初始化会在首次App之前调用既有metadata迁移，随后以日常DML账户检查同一版本；App仍无DDL权限。健康入口也执行原standalone版本检查，成功返回实际schema，不再只检查sentinel。

现有实例升级必须先在维护阶段准备metadata，再切换源码。不要把新App强门直接写入仍绑定旧数据库的预览目录，也不要对已有root重复initialize。本次使用独立工作树multi-merchant-ms6-runtime和新专用root multi-merchant-ms6-runtime-20261027-r1（本机端口18182），原18180/18181保持原绑定。三端前端源未变，复用并逐文件核同已有预览构建，不代表正式发布的前端copy资格。

原维护CLI及正式容器接线见 help/migrations/marketplace-ms6-schema.md。版本0.1.0/schema1是兼容代际，不是生产发布或完整MS6验收；正式镜像的entrypoint、单容器全部监听、pre-up/runbook及其余原需求仍需各自实际验证，停止的样本保持NOT RUN。

本机MS6正常接线已验证：新18182首装metadata返回created，原维护CLI重复apply为unchanged、默认check为compatible；重复维护前后176张表的完整结构/行摘要、两账户权限、runtime/上传/私有文件及Redis内容一致。日常App以preview_app成功初始化，仍只有SELECT/INSERT/UPDATE/DELETE权限；HTTP健康返回同一schema，两次读取约45–59ms（本机观察，不是性能承诺）。三端真实登录及商家me均200，浏览器商家正常登录后显示两件合成商品和原价格/库存。旧18180/18181的12个容器身份、启动时间、挂载及重启数保持，两者原登录信息接口仍200。汇总位于新预览目录 schema-gate-verification.json；正式镜像完整启动/health仍未因此验收。
