# 普通下单 HTTP 失败诊断与读取入口

## 当前0c13实际失败与后继修订（2026-10-23）

Run `20261023124755f45a9e` 的实际来源为 `0c13c57ba1ec4bf15ea9240601a5d6361e986ce8`。完整来源物化1,060项原生操作通过，九文件 profile 已冻结；原 S 与付款 producer 实际退出1，付款准备在 `paid_real_confirmation` 的 `M4C_FORM_NORMAL_HTTP_JSON_200` 断言失败。Task6、售后评价、Worker 和 fresh B 未开始，没有成功 A0。

该次真实上传的六项受限元数据显示 POST、HTTP503、JSON解析成功（`json_error=0`）、响应216字节、SHA-256 `318ae4db8bedc98bc218ade87cd653fd885bb3f91b4e23968c88c62dbbed4670`。collector 实际只运行一次，报告14,579字节 / `9f2765c93cce0df15305064f307ab0f683eabf50cc0a1f528e3cac131a826974`。这补足此前未运行的新增诊断分支，不回补业务成功；不重读原日志、不重跑该次采集，仓库仍只保存摘要。

已证源码前置冲突：普通初始化只复制安装材料而未创建配置中的真实 `public/uploads`；私有表单存储构造器要求该配置目录可解析。后继只在本角色已核实的自有 tmpfs 内创建原本缺席的空0700目录；UI 完整目录树消费者严格同步该单一目录及父 nlink+1，其余原节点和业务检查保持。固定 Controller 信封和初始 preflight 收据可产生同样216字节和摘要，因此尚未证明实际首个 Throwable。

本次四份完整 PHP 修订为普通 HTTP 入口、UI prepare oracle、H5 helper 与 H5入口；两修复 bundle 均已独立 Source 审查。H5 只将资源校验同步为当前八基础卷及其后原四卷组成的十二卷，保留完整原身份和业务断言。四份修订已在固定PHP8.2镜像中实际完成一次纯语法检查：四整文件php-l及八项原生操作均0，原专用容器普通移除、前后label查询为空。实际语法结果13,625字节 / `fc19c057d24a5acfa233b5daf7546cd9e2fff4055bc871b398545e0cd51e5a1a`；Root完整实际回读858字节 / `96b405d89fe383be388cb4a63490a402298ec9a8e962afe04f7c023f3987096f`。采用身份以真实Git记录为准；Source及语法通过不等于完整正常 A、三端 UI、独立 H5 九场景、原图或业务验收。

本 Run 专用 close93项及独立 zero20项原生操作原句柄均0；Root 与 peer 已完整联合回读通过，44,567文件和6,860目录的原bytes/stat9、完整namespace及其他资源连续保持。Root完整报告1,248字节 / `69b7db168819cf72116a287923d285a5f5cf7d28b578f7d85c7874349cbafc2d`，peer完整报告5,199字节 / `920ad49125fd654728f47bf496886252a95a7c92db7a1bfa07ad8ecbde707f09`。收尾通过不回补本 Run 业务成功。采用身份以真实Git记录为准；新的完整Source/M/S、成功A0及业务通过仍未取得。当前授权、源码交接和完整未完成项见[当前状态](../../CURRENT-STATUS.md)。

## 历史诊断补充（881来源，保留原时点）

本次补充解决验证工具没有记录 HTTP 状态和 JSON 解析结果的问题。最后一次实际普通链来源8819524在paid_real_confirmation的M4C_FORM_NORMAL_HTTP_JSON_200断言失败；真实HTTP原因尚未确定，本变更不能记为根因修复。原实例已普通回收并独立核零，不重启。

## 行为与兼容

验证helper将同一次实际reply的kind、method、http、json_error、response_bytes、response_sha256附为六项元数据；原JSON/HTTP/业务断言、请求、3/12秒超时、大小限制及关闭逻辑不变。异常原对象重新抛出。失败result和stderr只增加可选normal_http_response_diagnostic，不公开URL、令牌、正文、headers或错误文字；长度/摘要仅描述原接收缓冲区，不证明响应完整。

新入口为[collect_paid_failure.py](../../../../help/audit-regressions/orchestration/collect_paid_failure.py)。它读取同Run的五份公件，核来源、freeze、登记CID、原created/exited1终态和失败result后，仅调用一次固定Docker的logs；原流仅存RAM。原五字段异常位置与可选六标量HTTP诊断分别重建，并与result交叉核对字段存在性和值。旧五字段读取器和旧运行记录保持原件。

未来新实例失败后，用该实例真实参数调用；不得把881来源或旧freeze当作新来源：

```text
python -B help/audit-regressions/orchestration/collect_paid_failure.py --context <actual-run-context.json绝对路径> --expected-source <真实新提交SHA> --expected-freeze <真实新freeze摘要> --out <Run外的新目录>
```

采集0只表示失败投影核对完成，业务仍为1/INCOMPLETE。该入口不exec、不请求HTTP、不读私有卷、不重试、不跟随日志，不保存原日志流。

## 历史验证边界（881来源补充时点）

- 两份完整PHP已通过固定PHP8.2语法检查：8项原生操作均0，检查容器已普通移除；Root完整收据回读1272/579646ffe126519d73e22838f4d45cd7e345d8b9031bf05b68752e8217f7d1e2。
- Root以原AST的公件校验前缀核对退役881实例的实际五份公开JSON，并用已保留的旧五字段安全对象验证兼容；未调用collector main、日志解析器或Docker。报告1139/e284dc18955828f7ed9cd1e1cd8f91a841d5370cd10f684ff0b678f75c372219。
- 独立三文件静态复核通过：真实五公件合同、五字段旧投影兼容、三文件正逆补丁、406条原断言及单一logs调用保持；报告75495/ac08b63384f786a53fee2cf0ca25718738ffb30054c25d6da8e9dbd732cf3683。没有把旧五字段兼容当作新六字段分支已运行。
- collector实际采集、新增六字段运行分支、下一来源完整M/S及业务：NOT RUN。原全部停止样本继续NOT RUN。没有A成功、页面、Worker或发布签收。

对应三份完整源码：form68191/d47bafd900c50d1e99e8359b46f0c6d6f67e1033d02050bba1890611ea1fc988；paid109849/956f4dbcf96afa7155a478cdb7e74b960891d120148e74ff8735b6da53d37e5d；collector15238/d351d753d1cfd9527c2daddc6c7f5506c889571971cb407965aba695eb14babb。仓库只保存摘要，原始运行材料与跨机完整复跑仍未交付。
