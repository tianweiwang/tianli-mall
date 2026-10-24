# Task6 legacy producer 最小修订记录

## 挂载合同修订（历史检查点）

本记录为通用多商户商城原MS4正常链的开发检查点，不是阶段签收。当前商城沿用原商品列表、SKU、规格、价格和库存；本次不引入天俪专属需求。

2026-10-24，来源 `fa2088f9571bdc215be1ec05a59b840ed8b3943c` 的profile-r2运行 `2026102403521460e674`，冻结摘要 `5b9f812ec1104beba4fcd3c1463a988165acfb236d178dd25ffeb0d31f448638`。initializer与files-bootstrap自然0；paid确已启动并自然0。原S的PID7400及履约Task6自然退出1，外层阶段为 `three_true_legacy_cli`。不能将paid的本轮结果套作旧paid1或未启动；完整A仍失败，尚无成功A0。

Root本次只运行一次受限safe collector，实际退出0（控制记录45680/0）；公开内部定位是 `three_legacy_source` / `M4C_CLOSED_NATIVE_MOUNT_SET`。只根据公开字段重建记录，与已发布的327字节长度及摘要比对；没有读取原子进程输出体、ENV或私有材料，也不在Git加入这些运行体。

本次唯一源码修订是 `help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php`。仅将 `m4trp_source` 的binds/volumes两份严格列表与同源父`m4fn_source`已批准合同对齐：3个bind、5个volume挂载项；`/workspace`和`/source`为同一当前project专用source-tree卷且均只读。原SQL文件bind、prep/run、app/fixture与customer-form-evidence的既定权限保持。单角色8个non-tmpfs挂载项不等于全实例9个卷；不放宽原动态数量、类型、Name或RW检查。不改变业务、TTL、逐次完整Source读取、native0或私有归档前置门，不修改旧冻结材料或重启原Run。

完整before为53,328字节 / `9da29b5edf6bc50c8bb483eaf4a67adaaacc6cddc4660e6cac0d2dabcb81a584`；候选为53,384字节 / `89e982e22b1c9e98a52360b328a9fa8f974dc6ae390d70e8862a94cb131cdd36`。完整PHP语法0与真实created29挂载守卫的正常检查0已取得；结果5,472字节 / `873009da173b23ef2b20d45b3364e54ba642e6f6c7f714ae57a56d9fab42acbb`。这证明语法及本次真实正常合同接缝，不代表已重跑Task6、完整业务或provider/页面验收。

本次最小修订的采用身份以包含本记录的真实Git提交为准。失败实例已普通收尾：执行进程7672自然0，90项原生操作均0，仅正常停止专用MySQL/Redis并移除原4个容器、空网络和9个卷；结果2,534,155字节 / `8a24fdf3daf4ba5b14c6f70f1a23537a4f71908570ffa9c54a9dada0f77debcd`。独立核零进程29344自然0，12项原生操作均0、六项查询为空；结果17,417字节 / `b3b2c96b8cc9968abca330405709d0c59c7b2971d401e3c15ac158faf8a026ba`。原Run完整命名空间、元数据及有限公开证据连续保持，其余私有材料与原M/S输出体未读取；收尾不改写原业务失败。T4的31份外部候选未采用，不混入本次挂载修复。新的完整Source与正常A、成功A0、同源fresh B、后续售后评价/Worker、三端与设备、独立H5、真实支付退款对账结算、发布恢复及人工验收仍待；仅BASE登记签收，不计算完成百分比。原停止样本保持NOT RUN。

## 付款用户封存后态修订（2026-10-24）

来源 `41c0d795ff36ff98ed267bc49bb042e2f6e17bf8`、Run `2026102406240181bf85`、冻结摘要 `8f91923e3495f3a76aba426a3650208c0c5a9d643164b7bf1a6d0c7636f84efc` 的initializer、TestDriver、files-bootstrap和测试付款均自然0；履约Task6及原S21412自然1。一次受限诊断53228/0定位到 `three_legacy_original_events_and_fixture` / `TASK6_THREE_LEGACY_REUSES_TRUE_PAID_HTTP_OWNER`，安全重建与原365字节摘要吻合；未读取原子进程输出体或私有用户行。诊断报告57,769字节 / `854910d69e7cd3517951c08a846b420e93cd99e978d5463cc0942bc4a5d4e744`，不能据此推定具体差异列值。

`m5pc_authenticated_owner` 在首次真实登录及GET后保存 `actual_user`，随后表单登录和kernel下单有合法认证字段变化。原 `m4fn_owner` 已通过 `m4cf_owner_after()` 验证同一UID的完整封存后态、原HTTP身份、付款与native34归档，`m4trp_fixture` 却再次要求实时整行等于较早的快照。其间准备步骤不修改用户行，检查应使用同一封存后态。

本次仅在 `m4trp_fixture` 调用既有 `m4cf_owner_after(customer_form, authenticated_owner.actual_user, uid)`，将重复检查的期望值改为它返回的完整行。保留 `count===1` 与整行 `===`，不忽略认证列、不只比较UID、不重登录或修改数据。其他16个函数及模块补集原字节保持，原归档、文件、地址、付款、来源与业务检查均不变。before为53,384字节 / `89e982e22b1c9e98a52360b328a9fa8f974dc6ae390d70e8862a94cb131cdd36`；采用文件为53,480字节 / `6bbe02134f3781b157c8a3f188f7544158e70a64bda8a5520dc90915a7fa22a1`。源码独审通过；真实PHP8.2语法进程22964退出0。

失败实例已普通回收：进程16868/0，90项原生操作均0，仅正常停止专用MySQL/Redis并移除确切4个容器、空网络与9个卷。结果2,591,529字节 / `d13fe26808509938079e6ac481f6797bb883a2e6da47aacda86fb9b1985d1696`。独立核零26148/0，12项操作均0、六项查询为空；结果17,414字节 / `8feb15ebebca14b87a06ed9d57cdbf6edc545e8d5b594bf6770ba20c088051e4`。Root及独立完整联合读回通过，原Run与Source记录连续保持，53个其他容器的完整挂载和网络记录保持；私有材料与原M/S输出体未读。原失败仍为失败，不重启旧Run。

本次为L2源码采用检查点。后继profile的活动helper指纹及Host/interface/close/zero依赖需按新文件重绑，旧冻结profile和全部历史记录保留。新完整正常链须在真实新Git身份、完整Source导出和冻结后执行；此前的语法检查不代签新Task6、完整A、售后评价、Worker、fresh B或页面验收。当前运行证据保留在专用本机目录，Git登记此摘要。其余原需求、授权边界及停止项NOT RUN保持。

最新状态见[CURRENT-STATUS](../../CURRENT-STATUS.md)，原完整任务与验收范围见[09计划](../../09-plan-ms4-ms6.md)。
