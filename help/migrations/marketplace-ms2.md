# MS2 商品归属结构迁移

当前提供 `check` 和 `apply-structure`。`backfill --mapping=<文件>`、`enforce` 尚未接入这一版，执行时返回 `not_implemented`、exit 2；完整 MS2 Task 2 未完成。历史归属映射未确认，真实库未核对，尚未开启多商户业务。

## 输入与命令

在已完成 MS1 五表迁移的专用实例内使用 PHP 8.2 / MySQL 8.0.16+。显式私有 JSON 配置包含 `hostname`、`hostport`、`database`、`username`、`password`、`prefix` 六字段；可选 `marketplace` 对象只接受 `enabled=false` 和 `self_operated_mer_id`（正整数或 null）。程序不自动读取 `.env`，也不输出凭据或完整配置。

```sh
php help/migrations/marketplace-ms2.php check \
  --config-file=/run/private/ms2.json \
  --state-file=/tmp/ms2-private/self.json

php help/migrations/marketplace-ms2.php apply-structure \
  --config-file=/run/private/ms2.json \
  --state-file=/tmp/ms2-private/self.json
```

配置和登记文件必须在仓库外。自动创建自营身份的登记分支要求 Linux/POSIX 和已存在的私有 0700 目录；首次登记文件由 CLI 独占创建为 0600，重跑使用原有效登记文件，不预创建空文件。Windows 本机请使用已批准的 Docker 隔离方式。`--repo-root` 可显式指定包含 MS1 迁移的检出。若配置了已确认的正 `self_operated_mer_id`，只核对该 ID，不按名字或 ID 1 猜身份。

`check` 只读，报告结构、自营身份和待执行步骤。首次 `apply-structure` 只增加四个归属列、商品四个审核列、六个索引及库存流水表；登记并创建一个待审核、停止接新单的自营商家，返回真实 ID。重复执行保持同一 ID 和已有结构。原商品/附件/模板/预设的归属、主键和文件路径保持；未知归属仍待后续显式回填。

## 数据与失败边界

四个新增归属列为可空 `INT UNSIGNED`；原商品 `mer_id` 和默认 0 在这一版保持。库存流水有 15 列、4 索引、2 项 RESTRICT 外键和 1 项生效 CHECK；现阶段没有库存业务写入。

MySQL DDL 逐条提交。失败可能保留已完成的结构，回执列出实际步骤；结果未知时 `changed=null`。程序不宣称整批回滚，不删旧行、不恢复快照。私有登记绑定数据库、结构和真实自营 ID；状态不一致时停止，不重新猜测或重复创建商家。

## 已验证范围

源码 SHA256 `2eb13dd870f409494f5c5f42ad96b4157081de4a73921dcec475fefdda930c97` 在基线提交 `43cfb032cb5dc63d6cbfec4e3b8cfe9b5840a672` 的专用 ISO 内，以仓库外冻结候选执行 `check → apply-structure → check → apply-structure`，四个真实子进程均 exit 0。自营实际 ID 为 1，仅有一行；此数值来自真实 INSERT，不是预设值。旧 158 表保留，经 MS1 达到 163 表，再到 164 表；旧列完整投影和原结构保持，重复后的 164 表、缓存绝对期限、四个原运行卷及引导运行目录全等。私有文件精确清理，主/引导双标签 12 项资源盘点全零。

首轮真实失败保留：信息结构的自增缓存为 1，但同一 `SHOW CREATE TABLE` 的真实下一编号为 2；修正辅助观察后取得上述通过结果。该结果只证明两种命令的结构流程，不代替四命令的 G01–G07、历史映射、商品接口、完整验收或生产迁移。详见 `docs/multi-merchant/records/ms2/structure-normal-summary.json`。
