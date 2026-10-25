# MS2 商品归属四命令迁移

`help/migrations/marketplace-ms2.php` 已实现 `check`、`apply-structure`、`backfill`、`enforce`。当前文件与已验证的仓库外完整 R2 候选原字节相同，SHA256 `eeb0a2892e29bffd20d8f644881fceef5fbb8836b8bd0e18f72be119c799a614`；原冻结 manifest SHA256 `2429b3a602d76dc188ec81b8c3de25dbddb48e5ab5a01fdb4f03de42f022b7d6`。本文只说明这版四命令。

真实历史归属映射仍为 **UNKNOWN / 未批准**。完整普通 ISO 运行 `2026101222353843267f` 已实际验证本 RUN 新声明合成夹具的四命令流程，范围为 **NORMAL SYNTHETIC FIXTURE ONLY**。完整 Task2/MS2、G01–G07、SP-MS2-1、L3d/L4、生产迁移及上线均未完成。程序始终保持 `marketplace.enabled=false`，不会因为迁移回执完成而启用多商户业务。

## 执行前提与私有输入

在已完成 MS1 五表及菜单迁移的专用实例中使用 PHP 8.2、MySQL 8.0.16+、PDO MySQL；图片 HTML 盘点还使用 DOMDocument。自动登记与私有映射/报告操作要求 Linux/POSIX、当前 UID 和 `fsync`；Windows 本机使用已批准的 Docker 隔离方式，不能把 Windows `chmod` 当作已建立私有 ACL。

冻结 CLI 与包含真实 `help/migrations/marketplace-ms1.php`、安装 SQL 和相关 producer/consumer 的检出必须同时存在。四命令均显式提供 `--repo-root` 和 `--config-file`。程序不读取 `.env`；账号具有本次结构 DDL 和确认 owner DML 所需的专用 schema 权限。真实库、权限、数量和映射的批准不能从本文或合成 ISO 结果推断。

私有 JSON 配置的六个连接字段为：

| 字段 | 类型与含义 |
|---|---|
| `hostname` | 字符串，实际专用 MySQL 端点 |
| `hostport` | 端口整数或数字字符串，默认 `3306` |
| `database` | 实际 schema 名 |
| `username` / `password` | 专用迁移账号及密码，仅存私有文件 |
| `prefix` | 实际表前缀，例如本 ISO 的 `eb_` |

可选 `marketplace` 对象只接受 `enabled=false` 和 `self_operated_mer_id`。后者为已确认且真实存在的正 UINT32 整数或 `null`；不接受用名字、ID 1 或旧商品正 owner 猜测自营身份。省略该对象时功能同样保持关闭。

操作目录放在仓库外、由当前 UID 持有且权限 `0700`；配置、私有登记和映射文件按 `0600` 管理。映射读取还严格检查文件为 `0600`、父目录为 `0700`，报告目录为 `0700`。状态/映射/报告采用绝对路径和真实目录，不使用符号链接。配置是私有输入，不加入 Git、公共回执或终端记录。

首次自动创建自营商家时，提供 `--state-file`，其父私有目录须已存在，而文件须尚不存在；不要预创建空状态文件。CLI 独占建立状态文件并登记实际 INSERT ID、创建时刻、数据库和结构指纹。后续命令继续使用同一个有效状态文件。若配置了明确正 `self_operated_mer_id`，CLI 只核该实际 ID；状态一旦存在仍须与其一致。

## 精确命令

下面使用隔离容器内的实际检出路径 `/workspace`；私有目录、配置文件及确认映射须已按本实例实际信息准备。脚本应为当前集成的 `help/migrations/marketplace-ms2.php`，其 SHA须与本文相符。

```sh
php /workspace/help/migrations/marketplace-ms2.php help

php /workspace/help/migrations/marketplace-ms2.php check \
  --repo-root=/workspace \
  --config-file=/tmp/ms2-private/config.json \
  --state-file=/tmp/ms2-private/self.json

php /workspace/help/migrations/marketplace-ms2.php apply-structure \
  --repo-root=/workspace \
  --config-file=/tmp/ms2-private/config.json \
  --state-file=/tmp/ms2-private/self.json
```

结构完成并登记真实自营身份后，先执行下面这条不带映射的只读检查，取得本实例的 `mapping_binding` 和五根 `mapping_expectations`；最初结构尚未完成时的 check 及 apply 回执不提供这两项。

```sh
php /workspace/help/migrations/marketplace-ms2.php check \
  --repo-root=/workspace \
  --config-file=/tmp/ms2-private/config.json \
  --state-file=/tmp/ms2-private/self.json
```

然后按下文固定合同和明确的归属确认，在私有目录准备 `confirmed.json`。未经确认的历史归属保持 UNKNOWN；不能直接将旧 NULL/0 行自动分配给自营商家。已有有效确认映射时，再执行映射检查、回填及最终约束：

```sh
php /workspace/help/migrations/marketplace-ms2.php check \
  --repo-root=/workspace \
  --config-file=/tmp/ms2-private/config.json \
  --state-file=/tmp/ms2-private/self.json \
  --mapping=/tmp/ms2-private/confirmed.json

php /workspace/help/migrations/marketplace-ms2.php backfill \
  --repo-root=/workspace \
  --config-file=/tmp/ms2-private/config.json \
  --state-file=/tmp/ms2-private/self.json \
  --mapping=/tmp/ms2-private/confirmed.json \
  --quarantine-dir=/tmp/ms2-private/quarantine

php /workspace/help/migrations/marketplace-ms2.php enforce \
  --repo-root=/workspace \
  --config-file=/tmp/ms2-private/config.json \
  --state-file=/tmp/ms2-private/self.json \
  --mapping=/tmp/ms2-private/confirmed.json \
  --quarantine-dir=/tmp/ms2-private/quarantine \
  --maintenance-confirmed
```

`help` / `--help` 不连接数据库。省略命令时默认为只读 `check`，但仍必须提供两项公共配置参数。`check` 可带映射，不能带 quarantine 或 maintenance 参数；`apply-structure` 不带映射、quarantine 或 maintenance 参数。`backfill` / `enforce` 都必须提供映射和 quarantine 目录；只有 `enforce` 使用 `--maintenance-confirmed`。

`--maintenance-confirmed` 是调用方声明：所有其它业务写方已经暂停。CLI 不发现、停用或控制外部写方，数据库命名锁也不能阻止不使用同一锁的应用写入；本参数不是维护状态或并发安全的自动证明。

## 四命令的实际变化

| 命令 | 行为与回执 |
|---|---|
| `check` | 只读结构、真实自营身份和可盘点的 owner/引用；结构就绪时输出真实 `mapping_binding` 与五根 `mapping_expectations`。不写状态、owner 或 quarantine 文件。 |
| `apply-structure` | 增加四个可空 owner 列、商品四个审核列、六个索引及 15 列库存表；按私有状态独占登记一个实际自营 ID。未知历史 owner、主键、路径和其它领域 mer 保持原值。 |
| `backfill` | 事务内重读真实五根/目标商家与确认映射，逐行仅更新确认的 `mer_id`，核实际 affected rows、完整非 owner 哈希和 after groups；不回填子表、审核字段、主键或图片路径。 |
| `enforce` | 当前五根每行均已显式确认且到达目标 owner、引用盘点清零后，收紧四新增 owner 为 `INT UNSIGNED NOT NULL` 且无默认，并移除商品 owner 的默认 0；保留原 COMMENT、列位置与其它结构。 |

自营初始化保持待审核/停止接新单等原默认 flags；它不是已激活商家。库存表具有 15 列、4 索引、2 个 RESTRICT 外键和 1 个生效 CHECK；结构迁移本身不写库存业务。

`check` 的结构 `completed` / native 0 不等于历史 owner 已解决。只有在最终 owner 结构、同一有效映射、所有当前行已到确认目标及真实严格模式均成立时，`check --mapping` 才报告 `migration_complete=true`。`apply-structure` 认可合法已经收紧的结构，不会把 owner 改回 NULL/default 0。

## 确认映射的固定合同

先用结构完成后的实际 `check` 取得 `mapping_binding` 和 `mapping_expectations`。映射只接受这些实际来源与经明确确认的决策，不从旧 `NULL`、0 或现有正数自动生成归属。原库存在的正 owner 也必须确认；不变行写明 `from_mer_id=to_mer_id`。

| 顶层固定字段 | 内容 |
|---|---|
| `schema` / `version` | 整数 `1`；非空人工版本，最多 128 bytes |
| `source` | 原样复制实际 `mapping_binding.source`：完整 CLI SHA 与十个必要真实 producer/consumer 的内容 SHA 汇总 |
| `binding` | 原样复制实际 `mapping_binding.binding`：数据库、完整相关 schema 指纹与真实 `self_mer_id` |
| `confirmation` | 仅 `confirmed=true`、`by`、`at`、`reference`；确认者非空≤128 bytes，真实无小数 RFC3339 时间，决策引用非空≤512 bytes |
| `tables` | 固定五个 logical 表对象，见下表 |
| `image_links` | 必有 list，可为空；每项仅 `reference_sha256`、`attachment_id`、`attachment_value_sha256` |
| `attachment_category_links` | 必有 list，可为空；每项仅实际 `attachment_id`、正 `pid`、`kind=category` 或 `usage` |

| 根表 logical 名 | 主键 |
|---|---|
| `store_product` | `id` |
| `system_attachment` | `att_id` |
| `system_attachment_category` | `id` |
| `shipping_templates` | `id` |
| `store_product_rule` | `id` |

每个表对象仅包含 `count`、`non_owner_rows_sha256`、`before_groups`、`after_groups`、`rows`。count/hash/before groups 来自当轮实际检查；after groups 由每条确认和未确认原行精确计算。group 为 `{mer_id: NULL或UINT32整数, count:正整数}`，空表 groups 为 `[]`，不重复组。每条 rows 决策仅 `{pk:真实正主键, from_mer_id:NULL或UINT32, to_mer_id:正UINT32}`；目标 merchant 必须真实存在。未确认行不列入 rows，保持原值并进入隔离盘点。

当前完整 groups 必须等于该映射 before 或 after；每行当前 owner 仅能等于其 from 或 to。数量和全部非 owner 字段不能变。新增商品、图片内容/路径、相关 schema、必要源码或其它字段发生变化时，不能复用旧映射绕过绑定，应根据新的实际检查和确认准备新版本。

非 owner 哈希按真实主键顺序读取全部列，去掉 `mer_id`，每行对 `json_encode(row, JSON_THROW_ON_ERROR)` 取 SHA256，再把行哈希以单 LF 相连取 SHA256；空表为 SHA256 空串。schema 指纹保留相关表类型、列序/注释、索引/约束/表选项，仅规范化本迁移批准的 owner 可空/默认变化。完整 Git clean HEAD另由冻结 manifest 和宿主门禁记录，CLI 不把 Linux 无法解析的 Windows worktree `.git` 指针当已核 Git。

`confirmation` 是调用者提供的确认声明，CLI 不代替审批或证明真人签收。合成正常 ISO 的 `NORMAL_ISO_FIXTURE_DECLARATION` 只适用于事前明确声明的本 RUN 新夹具，不能作为历史数据归属批准。

## 引用盘点与图片确认

owner 计数分别报告未知、未确认、孤儿、跨店和未知引用。`NULL`、0、目标 merchant 不存在属于未知 current owner；现有正值仍未由映射确认则属于未确认 owner。引用同店核对使用确认的目标归属，不靠旧正数推断。

普通 attr/value/result/description 按真实 type 选父：`0=store_product`、`1=store_seckill`、`2=store_bargain`、`3=store_combination`、`4=store_integral`、`6=store_advance`。活动子表 `product_id` 指向活动自身 PK；活动自身再经其 `product_id` 指向普通商品。relation 使用真实 category 区分父语义，`type` 是收藏等动作，不能当活动类型。普通分类、优惠券、虚拟 SKU、日志、评论及运输 free/region/no_delivery 等按实际源码关系盘点，子表不新加 owner。

素材分类正 pid 是父类别，须存在、确认同店且无循环。附件正 pid 可能是分类，也可能是固定用途：必须由 `attachment_category_links` 明确语义；`category` 关联实际类别，`usage` 只接受源码明确用途 1–6，pid 0 无分类。未使用或拼错的人工 link 不静默忽略。

商品 `freight=3` 消费模板，须为真实正 `temp_id` 和同店模板。`freight=1/2` 不消费模板，遗留 temp_id 保持并报告未使用，不擅自归零。历史规格预设没有持久化 rule_id，来源保持非阻断 `UNKNOWN`；规则本身仍须独立确认 owner，不能虚构其与商品的关联。

普通商品 image/recommend_image/video_link/slider、type0 SKU image、attr_result 嵌套 pic/image 和解码一次的 description 媒体逐项盘点。唯一精确匹配现附件 att_dir/satt_dir 且确认同店时可通过；无匹配、歧义、无法解析 HTML 或未确认归属报告未知。活动/预设媒体中源码不能恢复的部分在 `reference_limits` 明确 UNKNOWN，不伪称已全面核对。

人工 `image_links` 可确认特定现引用的消歧或前缀转换，但必须同时绑定真实引用 hash、实际附件 ID、当前附件路径 hash 和确认同店 owner；不会改图片 URL 或文件。reference hash 对固定有序 `{table,pk,field,ordinal,value_sha256}` 使用 `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR` 输出后取 SHA256。table 为 logical 名；无主键行使用实际稳定 row hash；field/ordinal 从实际私有报告取得。`attachment_value_sha256` 须匹配该附件当前非空 att_dir 或 satt_dir 的原字节 hash，不靠 basename/CDN 前缀猜。

`counts.orphan` 的单位是 issue items，一条子行可能有多项问题。R2另输出 `orphan_child_rows={total,tables}`，按实际子表+PK或无PK的稳定行 hash 去重，单位为 distinct child rows；它不是全部引用条数，也不能用来掩盖其它 owner/引用计数。stock ledger 还核实际普通商品、type0 SKU 的 ID/unique、merchant 和 actor employee 同店关联。

## 报告、退出码和继续边界

映射最多 8 MiB；单私有 quarantine 报告最多 32 MiB；完整盘点与映射决策有 200000 行/项边界，超限失败，不用截断后的清单继续写。私有报告按内容 SHA exclusive 创建，同内容已有报告只核原字节后复用，历史报告不覆盖或删除。公共 JSON仅给真实计数/hash、有限 issue、步骤和自营 ID；私有报告保存必要表/PK/reason/关联 hash，不存行内容、图片 URL 或凭据。

| native exit | 解释 |
|---|---|
| `0` | 对应命令的当前检查或操作完成；仍须看 ownership_phase、migration_complete、counts、changed 与实际步骤 |
| `2` | 条件/结构不兼容或归属/引用未清；backfill 可以是已提交确认 owner 后的 `quarantined`，不能理解为绝无变化 |
| `1` | 配置、连接、写入或检查失败；保留原有限失败 phase、applied_steps、自营 ID 和 changed，不输出 driver 消息 |

backfill 的确认更新和非 owner 校验在自身事务中进行；失败时只回滚这个 DML 事务。它可提交明确确认的行并把剩余项隔离，回执如实给 `updated_rows` / `changed`。再次执行相同有效映射只更新尚未到达目标的行，已完成的普通重复回填为 `updated_rows=0`。

enforce 要求 SESSION **及** GLOBAL 的实际 sql_mode 至少包含 `STRICT_TRANS_TABLES` 或 `STRICT_ALL_TABLES`。CLI 不设置模式，也不把调用方另行降低 SESSION 的连接当已保护；没有新增正数 CHECK，所以 NOT NULL/no default 不能被宣称可阻止显式写 0。专用新库的旧安装兼容窗口属于另行记录的 bootstrap 初始化，须先恢复后续严格模式，不应用到四命令或业务连接。

MySQL DDL逐条隐式提交；任何失败可能保留合法已完成的列/表/owner 收紧，结果未知时 `changed=null`。不要删原行、恢复旧快照、重造 self、预写状态或把合法最终 owner 放宽。先保留原失败、私有状态和报告，核当时实际 `check`、真实步骤、源/schema/映射绑定及维护前提；只在实际状态仍为 CLI认可的 structure/enforcing/enforced 合法形态时继续原命令。状态/身份/源不一致不能通过猜 ID、重建登记或改 hash 绕过。命令完成与生产恢复、历史确认和完整验收分别记录。

## 已验证范围与待办

旧结构候选 SHA `2eb13dd870f409494f5c5f42ad96b4157081de4a73921dcec475fefdda930c97` 在基线 `43cfb032cb5dc63d6cbfec4e3b8cfe9b5840a672` 的专用 ISO 中已实际执行 `check → apply-structure → check → apply-structure`，四子进程 native0。自营实际 INSERT ID1仅一行；158→163→164表、八列/六索引/库存表元数据、旧原字段/PK/path/owner投影、重复全状态、缓存绝对期限/runtime及精确清理已核对。该证据仅覆盖旧两命令普通结构范围；首轮自增缓存观察 FAIL仍保留，详见 `docs/multi-merchant/records/ms2/structure-normal-summary.json`。

完整 R2 候选本轮普通四命令状态：**已验证新声明合成夹具正常范围**。真实 RUN `2026101222353843267f` / exec `46053` 已 CLOSED native0，child native0/restart0，根独立收据核对 native0。十个真实不同 PID 全部 native0/stderr0：MS1 apply1，MS2 check3、apply-structure2、backfill2、enforce2。首次回填实际5行、重复0行；首次enforce changed=true、重复false；最终check只读、最终apply保持enforced，同一真实self不重复创建。

原完整行/PK/path/nonowner字段、仅批准schema变化、全部Redis绝对期限、四RO运行卷及整个纯PNG卷均保持；配置、状态、映射和已认领私有报告精确清理。本次只有一个真实RUN、双label六项资源计数全0，没有cleanup probe；.env/install.lock absent。本次实际验证输入是当时clean的91ca检出，完整CLI eeb0a289、候选manifest2429b3a6、R3 prep26c0534f均按完整SHA绑定；集成后的源码提交不会倒写成这次运行的输入。普通摘要见 [four-command-normal-summary.json](../../docs/multi-merchant/records/ms2/four-command-normal-summary.json)，根原生证据见 [wrapper-native-root.json](H:/天俪商城/artifacts/ms2-four-command-normal-attempt3/wrapper-native-root.json)。这是合成普通流程证据，不能勾完整Task2/MS2/G01–G07或批准真实历史映射。

先前R1/R2 CREATE初始化FAIL及其清理收据保持；R3只在独立fresh CREATE窗口采用旧镜像原安装mode，真实157原块均完成且无安装INSERT，原生COMPACT ticket(191)元数据/SHOW CREATE与旧成功baseline一致，源列/索引声明与手工ALTER均未改。SESSION实际精确恢复，GLOBAL与InnoDB strict保持；四命令严格模式断言也实际通过。初始化兼容范围与后续命令验证分别记录。

真实历史映射、SP-MS2-1、L3d/L4、仓库内完整 `ms2-migration.php` 测试辅助脚本、完整原验收、商品/公众/订单消费者、上线与人工签收仍未完成。当前仅集成原字节CLI、使用说明和普通摘要；没有操作真实数据库、改写原冻结候选/manifest、推送或部署。


### type4/type6 真实活动盘点兼容（L2 Source，2026-10-25）

积分、预售都先按各自真实活动PK查父，再沿其product_id查普通商品和已确认owner；读取全部实际父行，不加上架/删除/时间过滤。缺活动父仍TYPED_PARENT_MISSING，活动base缺失仍ACTIVITY_BASE_PRODUCT_MISSING；其它未知type继续ACTIVITY_TYPE_UNKNOWN。没有给活动新增owner或改四命令/backfill/enforce门。

分别选择原生列：积分id/product_id（没有freight/temp_id/postage）；预售id/product_id/temp_id（没有freight）。积分独立积分订单不消费运费模板，不虚造freight；普通base仍受原全量root运费检查。预售当前enabled运费由MerchantFreightPolicy重读base，保原root检查；其兼容旧路径经freshCart继承缺失freight/is_virtual。base freight1/2或is_virtual1不需模板，不因预售temp_id=0新增问题，非零未消费字段只计unused；base freight未知已有原root问题，mode3且虚拟语义未知另报告unknown_reference。physical+freight3时，正native temp_id须真实存在并已确认同base owner；明确0/负值/未知按ADVANCE_LEGACY_TEMPLATE_UNCONFIRMED隔离，正数缺失继续CONSUMED_TEMPLATE_MISSING。旧Computed429–430可达fallback1不是显式确认引用，不能因模板1恰好同owner而通过；此遵守原07 Review Focus:27，需确认真实引用或另行修复业务，不在本次猜ID或改报价。当前enabled只消费base的路径不能代签仍可达的legacy缺失。此为盘点现有消费，不修改报价算法。

mapping_binding新增两活动表的完整schema，source绑定保原成员并补两producer、积分订单、PublicCatalog、MerchantFreightPolicy和StoreCart真实消费者；旧映射必须以新check真实binding重新确认，旧Run/映射不重签。当前公开安装seed的9个type4支持集外项会转为缺真实积分父，原71保留，总80仍待核；type6无seed行，未签其运行覆盖。完整历史订单/cart/权益、归档恢复、Task2/G01–G07、真实存量L3d/L4及业务启用仍未完成。

仅完成Source和静态检查后交Root/非作者审；本作者不运行PHP、SQL、Docker、HTTP或任何候选，不提交/推送。停止的恢复/故障/并发/安全样本保持NOT RUN。
