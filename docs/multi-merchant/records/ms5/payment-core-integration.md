# 持久测试付款核心整合到 Main

2026-10-17，Main 当前 HEAD 为 47d6212af64f3d8ba0f5cae24401145d824b013f；AUX 冻结来源为 6e6a2c97458aee73823933370d2fb2fc47ffac5a。沿既有 MS5 Task 2、AUX physical §20/22/23 的合同做本地 L2 整合；真实渠道、退款、结算、推送与部署仍未授权。本片不修改正在运行或待关闭的 AUX 来源及隔离实例。

## 目标与必要设计

将已经实现的真实测试付款持久层和服务接入 Main 原创建/取消链路。订单在 insertOrder 同事务建立 G0 出生登记，在 markCreated 消费购物车前核完整新出生；付款尝试经真实账户与私有 create context 准入，query 读取实际驱动结果再持久应用，取消先在事务外关闭真实尝试，再携带 proof 两次共同重锁。采用现有证据、锁顺序、完整 U64/BINARY/JSON 合同，不另造付款入口或 paid SQL。

Main 的 restoreConfirmation、markCreated(cancelAt)、savedPaymentDeadline 和 expiredCancellationCandidates/scanExpired 游标都是后继增量，必须保留。OrderDao 仅补支付必要字段与方法，禁止 AUX 整文件覆盖。现有 Account/Resolver、Adapter、AppService factory 与 config 已字节相同，保持不改。全局支付准入与微信判断也保持。

## 文件与执行清单

- [ ] 新增 AUX 的 MarketplacePaymentDao、MarketplacePaymentEvidence、MarketplacePaymentServices 三个产品类。
- [ ] 整合 TestPaymentDriver 的私有上下文消费与实际测试付款成功状态。
- [ ] OrderDao 窄接线：三项 pay_type/pay_time/trade_no；注入 payments；insertOrder birth；markCreated assertFreshBirth；完整 lockPaymentApplicationFacts/paymentMovement；取消 proof 和 payment stage 接线。
- [ ] CancelServices 整合持久关闭与 proof，保留 Main scanExpired。
- [ ] 纳入现有 marketplace-ms5-payment.php/.md 增量迁移源；不在本片执行迁移或更改库。
- [ ] 独立源码复核必要闭集和 Main 增量保留，做当前 PHP 8.2.33 语法/适用静态检查。
- [ ] 适配原正常内部、顾客/H5/商家事实与来源消费者，再运行全新隔离实例的真实 producer/payment/取消与 API/DOM 验证。

## 验证与完成边界

源码、语法与本地提交分别记录，不能作为付款业务验收。AUX 当前普通付款实例在 account registration 的完整保持检查失败：168 表、模式、序列及目标行相同，仅 Redis 一个键消失；具体生命周期尚待核实，原 FAIL 保留。不能因本片整合就宣称该问题解决。

Main 旧取消 helper 仍直接调用 driver.createPayment，新增门禁后必须按原 PP5 合同适配；27/34/25 事实及来源消费者、原完整 MS4 五未付与自然90秒取消、履约售后、真实金融与发布等原待办全部保留。已停止样本不执行，不补假身份、假响应、paid fixture 或时间。
