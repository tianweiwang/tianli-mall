# 普通下单 HTTP 失败诊断与读取入口

本次补充解决验证工具没有记录 HTTP 状态和 JSON 解析结果的问题。最后一次实际普通链来源8819524在paid_real_confirmation的M4C_FORM_NORMAL_HTTP_JSON_200断言失败；真实HTTP原因尚未确定，本变更不能记为根因修复。原实例已普通回收并独立核零，不重启。

## 行为与兼容

验证helper将同一次实际reply的kind、method、http、json_error、response_bytes、response_sha256附为六项元数据；原JSON/HTTP/业务断言、请求、3/12秒超时、大小限制及关闭逻辑不变。异常原对象重新抛出。失败result和stderr只增加可选normal_http_response_diagnostic，不公开URL、令牌、正文、headers或错误文字；长度/摘要仅描述原接收缓冲区，不证明响应完整。

新入口为[collect_paid_failure.py](../../../../help/audit-regressions/orchestration/collect_paid_failure.py)。它读取同Run的五份公件，核来源、freeze、登记CID、原created/exited1终态和失败result后，仅调用一次固定Docker的logs；原流仅存RAM。原五字段异常位置与可选六标量HTTP诊断分别重建，并与result交叉核对字段存在性和值。旧五字段读取器和旧运行记录保持原件。

未来新实例失败后，用该实例真实参数调用；不得把881来源或旧freeze当作新来源：

```text
python -B help/audit-regressions/orchestration/collect_paid_failure.py --context <actual-run-context.json绝对路径> --expected-source <真实新提交SHA> --expected-freeze <真实新freeze摘要> --out <Run外的新目录>
```

采集0只表示失败投影核对完成，业务仍为1/INCOMPLETE。该入口不exec、不请求HTTP、不读私有卷、不重试、不跟随日志，不保存原日志流。

## 本次验证边界

- 两份完整PHP已通过固定PHP8.2语法检查：8项原生操作均0，检查容器已普通移除；Root完整收据回读1272/579646ffe126519d73e22838f4d45cd7e345d8b9031bf05b68752e8217f7d1e2。
- Root以原AST的公件校验前缀核对退役881实例的实际五份公开JSON，并用已保留的旧五字段安全对象验证兼容；未调用collector main、日志解析器或Docker。报告1139/e284dc18955828f7ed9cd1e1cd8f91a841d5370cd10f684ff0b678f75c372219。
- 独立三文件静态复核通过：真实五公件合同、五字段旧投影兼容、三文件正逆补丁、406条原断言及单一logs调用保持；报告75495/ac08b63384f786a53fee2cf0ca25718738ffb30054c25d6da8e9dbd732cf3683。没有把旧五字段兼容当作新六字段分支已运行。
- collector实际采集、新增六字段运行分支、下一来源完整M/S及业务：NOT RUN。原全部停止样本继续NOT RUN。没有A成功、页面、Worker或发布签收。

对应三份完整源码：form68191/d47bafd900c50d1e99e8359b46f0c6d6f67e1033d02050bba1890611ea1fc988；paid109849/956f4dbcf96afa7155a478cdb7e74b960891d120148e74ff8735b6da53d37e5d；collector15238/d351d753d1cfd9527c2daddc6c7f5506c889571971cb407965aba695eb14babb。仓库只保存摘要，原始运行材料与跨机完整复跑仍未交付。
