# 顾客表单辅助脚本断言名称修复

日期：2026-10-23。范围：专用隔离验证辅助脚本；不改变商品、订单、支付或权限业务规则。

## 问题与修复约束

来源 `2e4af47c7595947e16bef0abfa9785823b1369d5` 的完整正常链 Run `20261023082740ae535d` 在付款准备阶段实际退出 1。有限诊断为 `RuntimeException`、`ms4-customer-http-normal.php:29`、`paid_real_parent_factory`。该异常只证明本轮失败；HTTP 已完成次数仍为 UNKNOWN，后续履约、售后评价、Worker 未启动。

真实调用为 `m5pc_produce` → `m4cf_child_start` → `m4cfk_guard` → `m4c_expect`。共用断言器先校验名称必须属于 `M4C_` 家族；表单辅助脚本的 60 个直接调用传入了 `M4CF_` 名称，即使条件成立也会被格式检查拒绝。

采用现有规范名称 `M4C_FORM_`，仅替换这 60 个直接调用的第二参数字符串。共用格式检查、每一判断条件、调用、PHP 常量、权限和原验收预期全部保留。失败来源与原始回执冻结保存，不修改历史结果。

计划文件：

- `help/audit-regressions/isolated/lib/ms4-customer-form-normal.php`：采用经过审查的完整候选。
- 本记录及 `docs/multi-merchant/CURRENT-STATUS.md`：同步失败原因、验证范围与交接入口。

## 已有验证与采用门槛

- [x] 当前失败实例按原方法正常收尾：93 项命令全部 native/wait 0；移除本轮 3 个容器、1 个内部网络、8 个原专用卷。
- [x] 独立核零：20 项命令全部 native/wait 0；六项 fresh 查询均为空，零资源变更。
- [x] Root 对上述全部实际 created、terminal 和原始 stdout/stderr 回读；53 个外部容器的完整挂载项集合和其它消费者字段保留。Docker 挂载枚举顺序不作业务变化。
- [x] 完整候选静态核对：恰好 60 个直接断言名称，全部第一参数条件与其它字节不变；正逆补丁和唯一字符串逆回均闭合。
- [x] 独立源码审查无 finding：46 个函数中 13 个只因名称改变，其余 33 个全文不变。
- [x] 完整新候选 PHP 8.2 `php -l` 实际通过；专用无网络只读语法容器正常移除。没有运行候选入口、断言谓词或业务样本。
- [x] 按完整文件指纹采用到整合工作分支；独立核对工作树差量，只提交本次三个文件。
- [ ] 采用后重新资格化完整正常链来源，执行新的完整 A；成功后在同一来源执行独立 fresh B。

候选完整文件：66,476 字节，SHA-256 `adf3d548b77cf6a198c74632aa1cc916156f0967d9ad8f9c80c26d58d3f2017c`。原文件：66,236 字节，SHA-256 `1d06fe3ed2cbe4bdc020e03849f128c3b447b7ea5365c54e59edee96762b5043`。

## 仓库外证据摘要

相对根 `ms4-worker-normal-source-preparation-r1`；这里只保留摘要，原始运行材料不进入 Git：

| 项目 | 相对路径 | 字节 / SHA-256 |
|---|---|---|
| 实际正常收尾 | `current2e4-paid-producer-failed-normal-close-r1/RESULT.json` | 104831 / `c0e013f88ccfa4d28224b8f1c1d4ff2f4b1b124fe13306e3f5b2db75df52711f` |
| 实际独立核零 | `current2e4-paid-producer-failed-independent-zero-r1/RESULT.json` | 25301 / `48d4cc4b5c04c25edbd4914ea8b805f4223df8b0096dd097e37d3e18e99b24e2` |
| Root 全部实际回执回读 | `current2e4-paid-failure-retirement-actual-root-readback-r1/REPORT.json` | 1256 / `c1abeec4308feddfd542bd5df82e4250a7e00fe0b18847b689c412388a581d5f` |
| 独立修复源码审查 | `customer-form-assertion-prefix-independent-r1/REPORT.json` | 4335 / `c8d2495921bba19c9d123f5b9bf08e43e80404de1cb9d54ead54f211fcb92f96` |
| 完整新候选实际语法 | `customer-form-assertion-prefix-php-syntax-actual-r1/RESULT.json` | 8482 / `50da6b32e6e4dbb82eced97e03a37f156a5e4e8b377e015b0849d38658c8a915` |

本记录不签完整交易通过、页面通过或发布验收。用户已停止的安全复现、负向、故障、并发、断连和恢复样本仍 NOT RUN。没有部署或真实资金操作。
