# 保存的测试付款在三端的读取与展示

2026-10-17，Main 来源为 3a735e3d0fab9127eefdee8f37d6d1db1e5ad6cd，工作分支 feature/ms4-single-store，授权仍为本地 L2。本记录落实已经确定的测试付款消费者接缝，隶属 09 的 MS5 Task 2；不开放真实支付、退款、结算或发布。商品列表、规格、价格和金额快照继续复用现有实现。

## 保存字段与兼容性

测试付款文案必须同时满足保存的 marketplace_trade_version 为整数 1 或字符串 '1'，保存的 pay_type 严格为 marketplace_test。付款状态继续由 paid 判断，文案不能反推已支付、履约许可或清结算完成。NULL/0/'0' 的 legacy 映射保持原状，不使用当前配置、请求参数或临时 UI 标记判断测试付款。

顾客和平台详情已有 pay_type/pay_time/trade_no，列表已有 pay_type/pay_time，保留现有投影。商家只新增保存 pay_type：MerchantOrderDao 的 HEADER 显式查询，merchantOrder 通过原严格 text() 返回，merchant domain 的 orderRow 通过原严格 text() 消费；不向商家新增 pay_time/trade_no。原归属核验、完整 items、表单、金额恒等式及 INT 引用边界保持。

StoreOrderServices 的 tidyOrder/tidyOrderList 仅局部补“测试支付”，仍保留原未付款空文案和 legacy switch。平台详情和顾客详情把对应保存 V1 测试付款的“实际支付/实付款”明确为测试支付金额。商家已付文字和金额标题明确测试性质，列表原“结算金额”列及 pay_price 保留，只给测试行增加“测试金额”说明。金额一律使用原保存字符串，不经 Number/int/float 重算。

全局 PayServices::PAY_TYPE 同时参与创建准入，不能为显示而加入 marketplace_test。原微信确认/配送任务中精确 pay_type==weixin 的分支保持。Main OrderDao 已有 restore、保存 deadline、cancelAt、候选游标等增量不得覆盖；付款核心及 ORDER_FIELDS 的三字段整合属于后续另行核对的接缝。

## 本片 Files

- crmeb/app/dao/marketplace/order/MerchantOrderDao.php
- crmeb/app/services/marketplace/order/MarketplaceOrderSnapshotProjection.php
- crmeb/app/services/order/StoreOrderServices.php
- template/merchant/src/domain/order.js
- template/merchant/src/pages/order/list.vue
- template/merchant/src/pages/order/detail.vue
- template/admin/src/pages/order/orderList/handle/orderDetails.vue
- template/uni-app/pages/goods/order_details/index.vue

先补商家查询/投影/domain 字段，再补局部服务端与三端文案；最后做实际变更范围的 PHP/JS/Vue 解析与现有构建检查。本记录和 09 是本片工作记录，不另造付款 API、测试框架或副本页面。

## 验证与完成边界

实施后检查 legacy 文案、状态顺序、原金额和全局 allowlist/微信条件的实际差分。执行适用的最终 PHP 8.2 语法、JS/Vue 编译及构建；SOURCE/COMPILE 只证明相应层级，不证明真实 API 或页面已验收。

支付核心 Main 整合、顾客 27 表/H5 34 表/商家 25 表及来源/HTTP/DOM 消费者仍需后继完整适配。实际已付展示只能使用真实原 producer 创建、真实服务准入及 query 保存的订单；不插 paid 行、伪造响应、UID/JWT 或复用过期 Run。实际浏览器要核正常未付、真实测试已付、已取消等既定正常状态与相关尺寸；没有实际付单或运行前置时如实保留待验证，不用静态假数据代验收。

完整 MS4 五未付、customer/pending90/tinyG0 取消、keeper/planner、履约/售后/金融/真实渠道与发布仍未完成；已停止样本不执行。本记录当前为实施前合同，尚未修改上述八个产品文件。
