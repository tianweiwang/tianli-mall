# 多商户正常验证编排源码

本目录补齐此前只保存在原机仓库外 `ms4-worker-normal-source-preparation-r1` 中的编排程序。`sources/` 按精确允许清单保存原文件的完整字节；[source-manifest.json](source-manifest.json) 记录逐文件来源相对路径、长度、SHA-256 和角色。

这是**源码归档和恢复资料**。旧完整编排的实际运行失败，最新修复对应的完整编排仍未完成；本目录不宣称可直接跨机器运行或业务验证通过。

## 从 Git 离线核对

在仓库根目录执行，使用 Python 3.14（与原检查环境一致），无需第三方库：

```text
python -I -B help/audit-regressions/orchestration/verify_sources.py
```

成功输出 `PASS_SOURCE_ARCHIVE_ONLY`。检查会完整读取清单内文件，校验长度、摘要、成员集合及 Python AST/JSON，确认旧主编排文件齐全，并保留新版本缺失项的明确状态。它不 import 或执行存档脚本，不连接 Docker、数据库、网络，也不读取原机目录。

该命令在只有本目录的干净 Git 导出中也应通过。Python 接缝片段不是独立程序，与 PHP、JavaScript、YAML 等原件一样在该命令中只作字节校验；完整 Python 脚本才作 AST 解析。它不代替其他语言检查、真实环境或业务验收。

## 归档入口

| 来源包 | 已收程序与配置 | 状态 |
|---|---|---|
| `full-a-raw-env-normal-next-source-r1` | materializer/prepare.py、launcher/launch.py、validation/run.py、实际九件 profile、作者源码和接缝 | 最后实际使用的完整主编排，绑定旧提交 `53e6d521b`；原付款进程失败 |
| `full-a-paid-fulfillment-contract-normal-next-source-r1` | 两个作者 Python、候选九件 profile 和设计说明 | 绑定修复 `76efa7807`；新 materializer/driver/launcher 尚不存在 |
| `fresh-b-customer-form-external-source-r3` | Root 编排、Host、初始化、资源复制、关闭与核零、浏览器程序和静态合同 | 草稿，尚未完成当前来源绑定与 UI 运行 |
| `nonempty-history-upgrade-source-r2` 与 `nonempty-history-pre37-*-components-source-r1` | 原历史升级程序、配置、actors/app/config 后继源码 | 供恢复源码调用链，不构成新的已采用集合 |
| `nonempty-history-private-prepare-components-source-r2` | 私有演员随机准备的初始化程序与配套挂载配置 | 只含生成程序，不含实际演员、口令或环境文件 |
| `nonempty-history-normal-close-components-source-r1` | 完整 producer、Channel、公开视图接缝及合同 | 已有原独立源码核对，未签业务运行 |
| `nonempty-history-normal-parent-components-source-r1` | 完整 parent producer 和接缝 | 保留 LOAD-01 缺陷与未完成的 startup 检查 |
| `nonempty-history-host-handoff-components-source-r1` | 完整 Host 和交接接缝 | 最终资格未绑定，原拒绝入口保持 |
| `full-a-paid-producer-failed-close-source-r1` | 原失败实例的正常关闭、独立核零源码 | 原实例已经退役；不得用旧程序操作新实例 |
| 三个 customer-form 来源与 proof INI | 原精确 PHP 快照及内存配置源码 | 只保存源码，不替代原运行回执 |

所有实际文件以清单为准，不按目录名递归导入其他原机材料。没有把整份原材料目录、原 Run 或所有历史作者输入公开。

`sources/` 是不可改写的原字节快照，少数原件含 CRLF 或历史空白；本目录的 `.gitattributes` 禁止 Git 对快照自动换行转换，以保持原摘要。新写的验证器、清单和说明使用 LF；不对归档原件作格式化修复。

## 运行依赖和恢复顺序

完整列表见 [runtime-dependencies.json](runtime-dependencies.json)。旧程序仍含原机绝对路径、历史来源与运行回执要求。仅复制本目录不能满足这些门禁；不要直接启动旧 `launch.py`、`prepare.py`、`run.py` 或关闭脚本，也不要通过伪造回执或删除检查来“适配”。

1. 先运行只读完整性检查，取得代码、调用顺序和未完成项。
2. 结合 [当前状态](../../../docs/multi-merchant/CURRENT-STATUS.md) 及 [09 计划](../../../docs/multi-merchant/09-plan-ms4-ms6.md)，完成修复提交对应的编排和来源绑定，处理已登记的缺陷。
3. 只有新来源、环境和普通流程均准备完毕后，按原授权范围创建新的隔离实例验证；生成新私有配置与新回执，正常收尾并独立核零。

原用户停止的安全复现、负向、故障、并发、断连和恢复样本继续 NOT RUN。本目录不恢复这些样本，不改变真实支付、生产数据和部署的授权要求。

原始收据、双流、私有环境、演员、Cookie/HAR、上传、数据库和 Redis 卷没有入库；失败历史与证据仍按原记录保留。源码交接补齐不补签任何业务、页面或发布验收。
