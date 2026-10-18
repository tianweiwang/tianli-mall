# Task7 售后及私有凭证元数据结构 CLI

状态：**已整合到本地开发分支，PHP 8.2 语法检查通过；CLI help/check/apply、SQL 和任何售后业务均 NOT RUN。** 本件属于原 09 Task7，采用仓库外 `ms4-aftersale-task7-design-r1/ROOT-ADOPTION-r1.md` 及 `ms4-aftersale-evidence-contract-r1/ROOT-ADOPTION-r1.md`。本地整合范围与证据见 [售后核心整合](../../docs/multi-merchant/records/ms4/aftersale-core-integration.md)。履约复测使用单独固定源码快照，不将其结果算作本次售后增量验证。没有部署、金融执行或用户停止样本的授权扩展。

## 固定边界

同名 PHP 只负责增量结构，不加载 App、`.env` 或 Redis，不执行 DML、GRANT、安装种子、历史映射、文件对象读写或 writer 开关。应用账户仍仅原 DML 权限；后继结构操作只用原专用 migrator。不是直接给现有应用开启新售后的脚本。

```text
php help/migrations/marketplace-ms4-aftersale.php [check|apply-structure] --config-file=PRIVATE_JSON
php help/migrations/marketplace-ms4-aftersale.php --help
```

省略模式默认只读 check；只接一个模式/一个显式 config-file，拒绝重复/未知参数。配置沿原 bounded JSON：最多65536字节、depth16，固定 hostname/database/username/password/prefix，hostport可选默认3306。它不负责证明私有配置的tmpfs/0600/owner/no-link；该证明仍由后继正常隔离父入口执行，不在说明或stdout暴露配置值/秘密/私有路径/驱动错误。

同PDO验证 PHP64/MySQL8≥8.0.16（非MariaDB）、真实数据库、原生预处理/异常模式、无native事务、SESSION/GLOBAL严格mode。check 不改mode。原 MS4/MS5 completed 与原完整 producer 结构保留；履约原定义从实际 `MarketplaceMs4FulfillmentMigration::newTables()` 派生，精确保留原range。不能用旧24列CLI完成检查来认证新25列operation；本CLI按唯一固定prefix验证完整旧/新合同。

## 两个新表与原表增量

所有新ASCII列均显式ascii/ascii_bin；新表InnoDB、utf8mb4_unicode_ci。action表marker为 `marketplace-ms4-aftersale-v1`；file表marker为 `marketplace-ms4-aftersale-file-v1`；新列COMMENT固定空串。原退款表的旧列、COMMENT、signedness/DECIMAL精度及实际utf8mb4表排序规则保持，不做整表字符集转换。

- `marketplace_aftersale_action`：恰21列、8索引、5 RESTRICT/RESTRICT FK、5 ENFORCED CHECK。列序为 id,contract_version,root_order_id,uid,mer_id,refund_id,payment_attempt_id,action,actor_type,actor_id,actor_ref,idem_key,request_digest,request_payload,result_payload,state,previous_refund_action_id,fulfillment_cut_id,apply_refund_id,created_at,committed_at。U64 PK与payment/prev/fcut、signed退款引用、UINT根/uid/mer严格沿采用合同，无新增身份/验收表。
- 原 `store_order_refund`：原安装24列加原MS4两列共26列，新增八个NULL DEFAULT NULL列，最终34列；新增3索引、2 RESTRICT FK、1 CHECK，最终索引5项。八列完整顺序为 marketplace_root_order_id,marketplace_payment_attempt_id,marketplace_kind,marketplace_state,marketplace_physical_state,marketplace_finance_state,marketplace_apply_action_id,marketplace_last_action_id。原refund.id/uid/store_order_id为signed INT，refund_price/refunded_price为signed DECIMAL(12,2)，均不改。
- 原 `marketplace_fulfillment_operation`：24→25列，仅追加nullable U64 aftersale_cut_id；10→11索引、5→6FK、5→6CHECK。唯一替换原已知 `mm4f_op_ids`：原完整表达式仅contract_version从1变IN(1,2)，整体按TRUE-only包装；同一ALTER加入 `mm4a_op_cut`，v1/cut NULL，v2/cut非NULL且正。其余四旧CHECK及全部旧索引/FK保持。
- 原 `marketplace_fulfillment_range`：原30列/10索引/5FK/4CHECK逐项保留。
- `marketplace_aftersale_file`：恰**18列**、6索引、2 RESTRICT FK、2 ENFORCED CHECK；没有第19列。文件字节/私有盘/票据不属于本结构CLI。

file完整列序及原生类型：

| 列 | 类型及NULL/default |
|---|---|
| id | BIGINT UNSIGNED NOT NULL AUTO_INCREMENT |
| uid,root_order_id,mer_id | INT UNSIGNED NOT NULL，无默认 |
| refund_id | INT NULL DEFAULT NULL |
| image_position | TINYINT UNSIGNED NULL DEFAULT NULL |
| object_key | CHAR(64) ASCII NOT NULL，无默认 |
| media_type | VARCHAR(32) ASCII NOT NULL，无默认 |
| byte_size | INT UNSIGNED NOT NULL，无默认 |
| width,height | SMALLINT UNSIGNED NOT NULL，无默认 |
| content_sha256 | BINARY(32) NOT NULL，无默认 |
| upload_key | VARCHAR(128) ASCII NOT NULL，无默认 |
| state | VARCHAR(16) ASCII NOT NULL，无默认 |
| created_at,claim_expires_at | INT UNSIGNED NOT NULL，无默认 |
| claimed_at,purged_at | INT UNSIGNED NULL DEFAULT NULL |

六个精确索引：PRIMARY(id)、uq_ms4af_object_key UNIQUE(object_key)、uq_ms4af_upload UNIQUE(uid,upload_key)、uq_ms4af_refund_position UNIQUE(refund_id,image_position)、idx_ms4af_root(root_order_id,id)、idx_ms4af_expiry(state,claim_expires_at,id)。五个非PK名称为本候选固定命名；采用原文只固定其列序/唯一性，没有冒称原文已有名字。两外键按采用件指定 mm4af_root_fk(root→store_order.id)、mm4af_refund_fk(refund→store_order_refund.id)，索引前导完整覆盖，不允许自动多出第7索引。

## CHECK 的真实语义

本片全部新增/替换 CHECK 的完整谓词 P 都声明为 `COALESCE((P),0)=1`，FALSE/UNKNOWN均拒绝；不改无关旧约束。五action CHECK只处理原ids、actor/ref、action/apply/prev、state/result/time、SQL NULL/JSON OBJECT。customer/employee/admin先核actor_id非NULL；payment明确NULL，actor_ref绑定真实十进制payment attempt。JSON字面null不等于允许的SQL NULL，不准用JSON_TYPE返回UNKNOWN通过。

退款 `mm4a_refund_v1`：原新增八列全SQL NULL才是legacy；否则八列及mer_id/mode明确非NULL，并核正值、signed交集/金额、三kind/mode和完整business/physical/finance真值表。部分NULL不能降级legacy。原nullable历史mer_id/mode不被旧分支反向要求有值。

file `mm4af_values`：UID/root限INT_MAX、mer正、1..5MiB、宽高各1..4096、三种MIME、BINARY摘要长度、64位lowerhex object_key、原1..128 printable ASCII upload_key（0x20..0x7e、首尾非空格、内部可空格，不trim）、created正且先留86400秒UINT余量、expires严格created+86400。固定REGEXP_LIKE模式显式排除所有非允许字符，不靠可在最终换行前匹配的单个`$`锚。`mm4af_state`精确四状态：STAGED三绑定字段NULL/purged NULL；CLAIMED绑定齐全/退款正/position0..8/created≤claimed≤expires/purged NULL；DELETE_PENDING从未绑定/purged NULL；DELETED从未绑定/purged非NULL且≥created。PK自增id不出现在CHECK。文件实际hash/owner/对象/同refund归属必须由后继服务核，本CLI不伪造证明。

CHECK元数据沿原有限文法比较，不执行表达式；只增本片固定字面、COALESCE/CONCAT/OCTET_LENGTH/REGEXP_LIKE固定函数与arity，保旧JSON_TYPE树结构。原观测到的escaped-literal presentation只对固定字面解码，不删除任意反斜杠/CAST/COLLATE，不以字符串剥括号冒称相同。实际MySQL CHECK_CLAUSE呈现和函数许可仍待Root真实check/apply核验。

## 固定20步骤、部分DDL与收据

1. 完整CREATE action（全部列/索引/FK/CHECK一次建立）。
2. 第2–9步按上述顺序逐列ADD退款八列。
3. 第10–12步加idx_ms4a_refund_root、uq_ms4a_refund_apply、idx_ms4a_refund_last。
4. 第13–14步加mm4a_refund_apply_fk、mm4a_refund_last_fk。
5. 第15步加mm4a_refund_v1。
6. 第16步op.cut列，第17步idx_fop_aftersale_cut，第18步mm4a_fop_cut_fk。
7. 第19步同一ALTER精确DROP旧mm4f_op_ids、ADD新同名版本CHECK并ADD mm4a_op_cut。只在预检已逐字语义匹配已知原CHECK时执行；没有单独DROP留下约束空窗的路径。
8. 第20步完整CREATE file（18列/6索引/2FK/2CHECK）。

只承认这20步的唯一连续prefix（含stage0旧、stage20完整新）；不接受任意24/25列二选一、缺中间CHECK、乱序或未知额外列/索引/FK/CHECK/触发器。元数据完整列序、types/NULL/default/charset/collation/extra/comments、可见升序BTREE、完整FK与ENFORCED CHECK及constraint闭集都比对。constraint/index/FK/check/trigger SQL排序显式BINARY，与PHP SORT_STRING一致。

apply沿预检→GET_LOCK(10秒)→锁内重检→每条DDL及完整元数据读回→最终完整检查→finally RELEASE_LOCK。锁名为ms4-aftersale:加SHA256(database、NUL、prefix、NUL、MARKER)前48hex；不公开实际数据库/锁名。原生exec成功后才加入applied；changed只表示实际尝试DDL。每条后必须恰好stage+1，不能把异常/半集合当完成。DDL可部分提交，不称事务回滚；失败重读当前真实prefix，重检也失败则pending_steps_known=false，保真实applied和失败phase，不自动重试或修复未知结构。

收据沿原 mode/state/native_exit/changed/applied_steps/pending_steps/pending_steps_known/issues/failure_phase/marker/source_sha256/scope/prerequisite_source_sha256/writer/acceptance，明确增structure_stage及固定structure_step_count=20。check适用/完成exit0；结构不兼容exit2；配置、连接、锁、执行、读回或释放锁失败exit1。source SHA取实际完整字节，前置四迁移及真实安装SQL在同对象生命周期反复核未漂移；默认不把候选外部路径当已接入Main。

## 后继验证责任

Root须先实际PHP解析和help，再在专用新隔离实例按完整来源清单执行check→apply-structure→check→apply-structure，第二apply无变化。父入口独立保护所有原列/行/元数据、全非目标表、BINARY摘要、重复行、SQL modes及Redis值/绝对到期；本CLI不读取或证明这些业务事实。之后正常关闭与独立核零。新DAO必须精确识别最终结构，不能继续拿原24列profile作最终验收。

结构完成也不表示原生退款申请/credits/双cut/最终Context2、同PDO claim、私有上传/授权读取、三端接口/UI、非空图片或金融退款已实现。images仍按原顺序U64 ID保存，旧URL不转换；新开关在完整正常业务验证前保持关闭。用户停止的拒绝/冲突/并发/故障/越权/过期样本均NOT RUN，真实渠道/发布不执行。
