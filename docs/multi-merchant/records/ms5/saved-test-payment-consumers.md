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

## 顾客金额读取补齐设计

源码追踪确认，详情 API 的 V1 投影已经给出 BC2 字符串，但顾客详情金额区仍沿原 `parseFloat(...).toFixed(2)` 路径。测试付款文案没有引入该转换；本片要把原有缺口一起补齐。商品总价的 `total_price + vip_true_price` 不能直接删去优惠加项：`total_price` 是保存的优惠后商品合计，`vip_true_price` 是从保存商品快照用 BC 合计的优惠额，真实等级/会员 producer 可以产生非零优惠。

只在上述顾客详情页分流九处金额插值。V1 的八处标量检查规范的非负两位小数字符串后原样显示；商品总价使用两个局部纯函数中的逐位十进制串加法，保留原价与优惠展示语义。不把完整金额转换成 Number、整数分或浮点，不使用 BigInt，不给缺失字段补零，不新增与现有 DTO 不一致的十位整数上限；加法结果自然保留进位。legacy 的九处原表达式、显示条件、API、DTO、CSS 和付款状态保持。

验证包含当前 PHP/JS/Vue 解析，以及纯金额函数的正常零值、分位进位、非零优惠与大额进位，用独立十进制期望核对。它们只证明金额读取与计算，不代替真实订单 API/DOM 验收。

## 验证与完成边界

实施后检查 legacy 文案、状态顺序、原金额和全局 allowlist/微信条件的实际差分。执行适用的最终 PHP 8.2 语法、JS/Vue 编译及构建；SOURCE/COMPILE 只证明相应层级，不证明真实 API 或页面已验收。

支付核心已完成 Main 源码整合及语法核对，实际业务仍待完整原入口验证，见同目录 payment-core-integration.md；顾客 27 表/H5 34 表/商家 25 表及来源/HTTP/DOM 消费者仍需后继完整适配。实际已付展示只能使用真实原 producer 创建、真实服务准入及 query 保存的订单；不插 paid 行、伪造响应、UID/JWT 或复用过期 Run。实际浏览器要核正常未付、真实测试已付、已取消等既定正常状态与相关尺寸；没有实际付单或运行前置时如实保留待验证，不用静态假数据代验收。

完整 MS4 五未付、customer/pending90/tinyG0 取消、keeper/planner、履约/售后/金融/真实渠道与发布仍未完成；已停止样本不执行。八个产品文件的字段/局部文案和顾客九处金额补齐已实施并完成独立有限源码复核。顾客金额补齐纯文本逆回后精确恢复原 72168B/摘要 `aacb73eada5e359398d209cde2a68dd4ee1226341779ae393693c2896428641d`；当前 73622B/摘要 `bc15d4f63c004c9c8abf781a2593b6019f1946972bc389ca07ebc1ae5c9fe013`。

Root 的实际检查位于仓库外 `artifacts/ms5-saved-consumers-20261017-r1/`：三个 PHP 在固定 PHP 8.2.33 镜像、无网络、只读来源/根文件系统及专用临时 tmpfs 中语法正常；商家现有 source-check 的 31 个脚本/74 个本地导入通过。Node 22.23.3 解析五份 JS/组件脚本，Vue compiler 2.7.16 编译商家两页及平台详情，2.6.14 编译顾客详情，四页均无 error/tip，生成的 render/staticRender 语法通过；六组正常十进制期望（零值、分位进位、非零优惠、大额进位）实际调用局部纯函数通过，来源前后保持。首次 PHP 工具检查因只读环境缺 /tmp 而退出，原失败保留；补 tmpfs 后版本与三次语法均真实0/stderr0，未启动应用。

商家完整离线构建已实际通过，测试来源为 clean `7d28865cbf0398d3c7cefd3f4b606d401452897d`。原26540个依赖文件逐字节复制，包/锁及9项直接依赖版本保持，不安装/ci/网络回退。Node22.23.3/npm10.9.9的真实 prebuild/source-check/build及来源前后检查全部native0；43份商家源码、原依赖树与锁保持，产出25文件/1468437B，HTML引用均为实际本地merchant资源且通用标题通过。结果 `artifacts/ms4-merchant-console-build/7d28865c-p1/build-receipt.json` 摘要 `d9773dbe9ad1ac40d58d1355f5c54d3a4c7c98672c908957c9eb7d4333aea105`。首次长目录复制因265字符路径及本机LongPathsEnabled0失败，原收据保留；只改新输出短目录，最大路径252，所有依赖仍保留，未改系统设置或builder。平台完整构建、真实API/DOM和视觉验收仍待完成。

顾客H5完整离线构建现已实际通过，测试来源为clean `af0bcd9c7e46d17609461fb0df2620c62d6b2a52`；相较旧38a14构建，545份完整源码仅订单详情页变化，无新增源。Root及独立有限SOURCE、18处创建器全文逆回与AST之后，真实source准备/545复制/五目录工具复制/三路径adapter准备/Node语法/完整compiler/final gate均native0。原29930工具文件/160957439B、8个编译guards、10个包及锁定来源保持，无安装或编译器源改写；实际Node22.22.2/Vue2.6.14/原冻结编译工具完成两次真实pass。产出334文件/10380318B，manifest `8f6549c01d97ebedcc7da4dbdf2603266d9e01a7cf27eab9a5d4b7b65febf383`；HTML的3项资源为实际本地文件、remote_refs=0，完整来源和依赖前后保持。结果 `artifacts/ms4-h5/pay-uni-af0-r1/BUILD-REPORT.json` 3815B/摘要 `fc358a6a9514b7ef2db2cf52d101b0b639a4c0386b306b68471eb60619411d51`，编译警告原样保留。该证据只覆盖完整H5编译与静态产物，不代替登录/付款API、DOM、视觉或整体验收。

## 2026-10-19 通联支付标签键兼容修复

独立六 DTO 期待复核 P1 已确认：`PayServices::ALLIN_PAY` 及冻结 formatter 使用保存键 `allinpay`，当前 V1 投影的 `listPayType`、`detailPayType` 两处却写为 `allin`，会落入默认文案。计划只把这两个映射键改为 `allinpay`，对齐现有通联支付标签；其他标签、`marketplace_test`、金额、支付准入、渠道行为、原 formatter 和独立 expected 均不改。

本次仅做精确差分、完整逆回与静态来源核对，不执行 PHP 或 Docker，不提交。后继由 Root 完成真实 PHP 8.2 语法及有意义的既有 formatter 兼容检查后再作限定路径本地提交；真实付款 API、三端 DOM、三宽展示及整体消费者验收继续保留为待验证，不以本次两键修复替代。

Root 后继实测：修订投影及独立六 DTO helper R2 在固定 PHP 8.2.33 镜像中语法均正常；直接加载真实投影并调用其原列表/详情标签方法，六种已付列表、六种未付列表、八种详情共 20 项既有显示语义全部通过。原 `allinpay` 恢复“通联支付”，其余原标签、未付列表空文案/线下例外及 `marketplace_test` 保持。三次调用均无网络、只读单文件挂载，无 App/数据库/HTTP/支付动作；九条原生操作实际退出 0、stderr 为空，三个专用临时容器正常自动移除且前后精确名称选择器为空，两件被测来源前后字节保持。结果 `artifacts/ms4-worker-normal-source-preparation-r1/pay-type-canonical-label-native-r1/RESULT.json` 为 8957B/`6274e19b0f3f44a0c73f41d7d5fe025ebbfe6bf8f70b6bcd95034dae93a17129`。仅本 PHP 与本记录进入本地提交，完整实际 API/DOM、履约/售后/评价和三宽验收仍待，不推送或部署。
