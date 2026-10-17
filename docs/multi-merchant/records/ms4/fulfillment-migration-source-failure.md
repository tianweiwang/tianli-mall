# 履约来源校验失败与正常收尾

2026-10-18，通用多商户商城 MS4 Task6，L2 本地。实际来源 `cf3138f601f549c8e2bb4ceb08aab5e3f6719819`，隔离 Run `202610172227139806f6`。没有推送、部署或真实金融操作。

原9 initializer、4 MS5 CLI、files bootstrap、C3 prepare 和真实测试付款 producer 均退出0。履约迁移容器在 source 阶段自然退出1，原日志 entry_return_code=1、native_exit=null；两者是应用返回值与宿主容器终态两层事实。私有配置未创建，迁移四个CLI子命令未执行，履约业务未启动。

确定的源码缺陷：迁移 predecessor 要求原Docker terminal具有 run_id/source_sha，但原terminal是26键原生观察，两个身份字段仅存在于29键created。修复保留原terminal完整摘要，校对其与原created的id/image/完整labels；原created的run/source、付款前置和其他断言不变。原catch只公开source阶段，未公开首次抛错的精确guard，故完整修复效果仍待新Run验证。

独立SOURCE检查另发现业务入口三个TASK6标签传给只接受M4C前缀的m4c_expect。仅将三处callee改为已有m4fn_expect，原条件和标签不变。此入口本Run未执行，此缺陷不能解释较早的实际迁移失败。两件均已完整字节正逆、独立SOURCE复核、固定PHP8.2语法检查0；语法检查不加载应用或SQL。

专用失败收尾程序及独立核零程序完成全文SOURCE复核、完整派生逆回及语法/help检查后，由Root真实运行，两者均native0且stderr为空。数据库各一次同原生句柄正常停止，无超时强停；paid自然0、migration自然1保持。原4容器、1内网、6卷精确非force移除。独立六次fresh查询native0、stdout/stderr均空；前后各53个现存容器的全局检查均无原消费者。原4291公开原件、P九件、冻结和原失败保持，仅在独立zero0后解冻Main。

仓库外证据位于 `H:/天俪商城/artifacts/ms4-fulfillment-normal-runs/202610172227139806f6/`。专用close结果34487B，摘要 `183c60335357b247f148eedbc7f2d104c49d3d3f269c9b7903966eca16419c7a`；独立zero结果71726B，摘要 `8f231d252fad6c733cc5133d7ec80aefc2d3307eeef8f671b444661b19b51c36`。来源及真实native双流摘要另在仓库外 `ms4-fulfillment-writer-preparation/migration-source-failure-close-r1/` 保存。

本记录只证明本轮失败的来源、修复源码及资源正常收口。迁移业务、两次发货/三次收货、三端HTTP/DOM、Worker及Task6完整验收仍未通过；售后退款、评价、真实支付/对账结算、非空历史与发布等原责任保持。用户停止的样本仍NOT RUN，不补造成功记录或重开原Run。
