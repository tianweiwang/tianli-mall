# Task8 两处测试预期修正（仅外稿）

依据根代理已记录的 992b91d6 设计与真实首 RUN 基线。根代理执行的原基线为 3 PASS / 3 FAIL、native exit1、cleanup_failed=true；其原 JSONL、summary、每例证据和实际代码 ZIP 副本均保留。本代理本次只修外稿，没有执行或安装仓库。

1. SystemCrud.download 第705行以实际 site_url 拼下载路径。安装种子该值为空，因此 root-relative /adminapi/download/32lowerhex 是合法原行为，也被前端 URL(url, base) 支持。检查现在接受该 exact relative URL，或同配置 scheme/host/有效port 的 absolute URL；仍拒绝 user/pass/query/fragment、protocol-relative、外部 origin、非 exact 路径或 key。实际内部 HTTP 目标不变，未改安装种子。
2. Make.php 第328–329行将 TIME 填为实时 Y/m/d H:i:s。model/dao/service/validate 四个主 stub 第17行使用 TIME，原预览与实际生成分别在相邻秒，不能要求其注释时间绝对相同。只对这四个已预览映射文件的第17行固定 @date，保留相邻 author 行与原换行，按初始化后的真实 App 时区严格解析实际时间，要求在 producer start/end 秒内。仅将预览同一 TIME 位替换为该合法时间，再要求整个文件所有字节精确相等。其他五文件及其他日期/内容不替换。ZIP entry 继续与九个已逐一验证实际文件精确相等；任一文件未匹配，不能登记删除或允许 ZIP。

原基线的 encoded query 首次消费实际 HTTP200，Think Controller.php 第90/110行取 Request.param，Request.php 第862行合并 GET，Container.php 第454–455行按 key 参数名绑定 PublicController.download 的字符串参数，因此未进入 getMore 数组分支。不再声称本次 HTTP 复现 query TypeError。

T8F06 原失败不是已证实的 regex 不匹配：其联合断言首先检查 HTTP200，但实际 producer HTTP500、业务 JSON200，后面的 regex 已短路。源码 MysqlBackupService.php 第22行未打开 fp，而第512–514行析构无条件 gzclose(null)；已有 app-runtime.log 对应 TypeError。此为根代理将修的正常业务调用点，测试 HTTP200 期望、SQL/gzip、审计日志、六 case/selector、状态差异和清理标准均保持。
