# 保存付款期限的顾客详情文案修正

2026-10-17。初始登记状态：DESIGN / NOT IMPLEMENTED / NOT PARSED / NOT RUN；后继SOURCE/PARSE检查点见§5，正常HTTP/页面验证仍未运行。

## 1. 已确认问题

Main本轮正常H5创建后，详情显示“请在10-17 03:52:07前完成支付”，与下单时刻相同。Root已实际核B4调用m4cb_saved，后者真实SELECT并逐字段核本单ledger的12列、本人UID/订单PK/原idem及cancel_at=0，B4正常验证已PASS。本文件记录Root交付的匹配运行事实；作者本轮仅读源码，不访问Run或私有证据，不把原R6通过归给后继修复。

Restore当前源码依据：MarketplaceOrderCreateServices::cancelAt（108–119行）明确零表示禁用自动超时，正值才按BCMath整秒保存原绝对期限；公共lib/ms4-customer-http-normal.php的229行设置原JSON字符串0，407–412行核保存期限0，H5后续配置不改该项。原tiny内部取消样本是另一条正小数路径，本次不运行或改写。

StoreOrderServices::tidyOrder（267–290行）仍用当前配置加add_time生成旧文案，零小时得到下单当秒。uni-app详情第10行只显示服务端_status._msg；V1快照STATUS保留该键，HEADER不输出stop_time/cancel_at。因此本次修正文案权威来源，不添加前端倒计时字段。

## 2. 固定结果

仅修真实本人、保存V1、pid=0商业根详情中的普通未付提示：保存期限0显示“订单尚未支付，请完成支付”；正值沿原服务端时区和格式显示“请在<m-d H:i:s>前完成支付!”，直接使用原保存秒。不能以当前配置、确认缓存、add_time加默认小时、当前商家开关或请求字段重建期限；期限到期本身不证明已取消、已付款或已释放库存。

legacy NULL/0/'0'输出、未知版本受控拒绝、已付/已取消及线下待商家处理的原状态文案保持。已有V1缺失ledger、多行或绑定不符时受控不可用，不猜0、不自动认领、不回退当前配置。范围限本人普通根详情，不扩平台/商家/退款/子单/批量列表或支付业务。

## 3. 两个产品文件

| 文件 | 最小实施合同 |
| --- | --- |
| crmeb/app/dao/marketplace/order/MarketplaceOrderDao.php | 新增只读savedPaymentDeadline(array $actualSavedOrder): int，仅接实际已授权保存根。取得同一真实默认主连接PDO；尚未建立时沿既有主连接SELECT 1做法初始化，核mysql、异常、原生预处理、原prefix及事务外身份。以原根PK/UID/mer/公开订单号、保存V1/pid0及ledger state1绑定一次实际SELECT；核唯一真实ledger、confirmation_ref与原unique的SHA256、原source/cart_refs与保存cart_id及add_time身份，返回规范UINT32原cancel_at（允许0）。不调用会FOR UPDATE的旧私有committedOrder冒充只读，不开事务/第二PDO、不锁行、不更新字段。 |
| crmeb/app/services/order/StoreOrderServices.php | 沿getUserOrderByKey（2684行）实际保存读取及2690–2692行本人版本检查；仅受影响的V1普通未付根详情调用一次上述读取，既有tidyOrder（2775行）之后只替换最终_status._msg。共享formatter与其他调用者保持，不给列表增加逐行查询，也不把旧formatter的临时文案作为最终V1详情期限依据。 |

无schema、DTO/HEADER/STATUS新增键、前端、创建/取消/付款或fixture改动。原stop_time仍不进入V1公开投影。只读期限不构成付款、取消、库存或金融写入授权。

## 4. 来源与验证责任

仅在独立Restore树开发。原20前端及5PHP的恢复责任保持，尤其confirmationProductData/formatOrderConfirmation/restoreOrderConfirmData、控制器restore、lookup优先和fresh/legacy顺序不得覆盖。RC8、Task4/MS4及其他原未完成项保持。Main当前R6、其冻结源、旧profile和Run不动，不回填旧证据。

执行状态仅记09 Task4的SD1–SD3，不在此另起任务清单。Root读回采用本设计后才改两PHP；独立SOURCE、最终PHP语法、新来源正常HTTP/页面与原保存事实验证分别记录，不能互相替代。正常验证核零期限文案及期限/订单事实保持；正期限直接显示原秒的源码和正常证据按Root后续允许范围补齐。用户停止的拒绝/越权/冲突/并发/故障/恢复样本不运行；本窄修复也不运行原tiny内部取消样本，不为通过修改deadline、时钟或配置。新运行如需host/profile/验证源码，先在同一09记录登记具体文件，再由Root执行与正常关闭/独立核零。

## 5. Root SOURCE/PARSE检查点

2026-10-17后继记录：Root已完整读回并采用上述先登记设计，完整读回两PHP差异，并核实际ORM、配置及model getter；again独立SOURCE PASS。窄源码实施及来源复核对应09的SD1完成，原Restore接缝及§4其他责任保持。最终两PHP如下：

| 来源 | 字节数 | SHA256 |
| --- | ---: | --- |
| crmeb/app/dao/marketplace/order/MarketplaceOrderDao.php | 84262 | 0e95dabf49d6537ca7c5fdf4f1723f121f8eaa1e0602bd824dac941944bb9d16 |
| crmeb/app/services/order/StoreOrderServices.php | 156931 | ecf2695a30bcad1418704b6cb98f417a849e0301208d93dde99fe8420d846d88 |

Root实际以PHP8.2.33对最终两PHP分别执行单文件`-l`，两child及outer均native0、stderr空；检查前后两PHP与当时设计/09共四份来源保持。真实收据为`H:/天俪商城/artifacts/ms4-confirmation-restore-source/20261017-deadline-r1/php-syntax-result.json`，SHA256为`c7a267a8e65f7de717c52a3b841440340657fd0192666133f18cec55fcdde5b0`。checker为4806B、SHA256 `3063ed3e8418e4dc006e24a4090de5d8cb7a69f2705698408372287c4845dca5`；Root已核8处有限替换、整文逆回及实际AST通过。此最终解析对应SD2完成；本段和09后继记录的文档更新不冒充上述检查时的设计/09字节。

上述检查没有import、App、SQL或样本执行。SD3仍未完成：尚无新来源正常HTTP/页面文案与保存期限/原事实不变的运行证明，也未据此完成正常关闭或独立核零；Main原B4/R6通过不归给本修复。原Restore、RC8及完整Task4/MS4责任均保留。
