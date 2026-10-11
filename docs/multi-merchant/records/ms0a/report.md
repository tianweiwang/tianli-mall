# MS0a 执行记录（进行中，未交付）

## P1 基本信息

- 用户于 2026-10-11 在当前会话确认 BASE 通过，授权 MS0a L2；未授权推送或部署。
- 分支：`codex/ms0a`；工作目录：`H:/天俪商城/tianli-mall--ms0a`。
- 起点：`6dee65785b5655d7bac7c3838b95106c7c0549cc`，与用户指定远程分支一致。
- 历史 `AUDIT_BASE_SHA` 仍为 `9685731dcd116784d38e6bbdb902cec3c0c84975`；从该提交到本次起点只涉及文档和 BASE 记录。
- 已提交：`f9bfb095`（批准的 Windows ISO-FULL 环境适配）、`760fdbbd`（批准的现有商品规则权限探针）、`da1af4f6`（批准的安装初始化）、`9062a302`（Task 1 隔离环境）、`80c2db24`（Task 2 回归用例）、`55da15f3`（控制文件读取竞态修复）、`3e1f7fea`（Task 3）、`c2653b2e`（Task 4）、`908ba5b9`（Task 5）、`53a318e5`（Task 6 正向状态回归）、`7ba8cbe6`（Redis SET 快照修复）、`b2b2e922`（Task 6）。尚未生成 `SECURITY_BASE_SHA`。
- Task 2 基线源码一致性：`git rev-parse AUDIT_BASE_SHA:crmeb HEAD:crmeb` 均为 `e5bc53996280f4d60f4f577d22da0fbf274b67be`；结果仍记录实际运行的 HEAD，不把在当前 worktree 运行的结果伪标为历史提交。
- 唯一执行清单仍为 `04-plan-ms0a.md`；Task 1–6 已完成。Task 2 原 85 个认证子场景已完成基线运行，Task 6 追加 1 个正常状态切换回归，原断言不变。Task 6 全量复测 86 PASS / 0 FAIL；Task 7 正在生成路由盘点，随后须在 SP-MS0a-1 等待用户决定，Task 8 尚未开始。

## P4 当前变更范围

- 已批准的规格和计划适配：03、04 文档。
- 环境文件：`help/audit-regressions/isolated/`，含原计划文件及用户批准的 `run.sh`；Task 3–6 认证修复已提交，Task 7 仅完成路由盘点，没有修改授权策略。
- 本记录用于保存实际进度，不代表 MS0a 验收完成。

## P5 已执行检查

| 检查 | 结果 | 层级与边界 |
|---|---|---|
| 原分支 `security.php`，PHP 8.2.33、本地镜像、只读挂载、无网络 | 23 PASS / 0 FAIL，exit 0 | UNIT-STUB；未验证 HTTP、数据库或 Redis |
| 初次执行新 `selftest.php` | exit 1，harness 尚未实现 | 写测试后的预期失败 |
| 新增 4 个 PHP 文件语法检查 | exit 0 | 源码静态 |
| Bash 脚本语法检查 | exit 0 | 源码静态 |
| 首轮专用环境启动 | MySQL 临时服务器造成过早健康判定，PDO 2002 | 已将健康检查改为 TCP；仅按 manifest 清理该轮资源 |
| 第二轮专用环境 | 内部网络、无宿主端口；157 张安装表与哨兵已导入 | 新建隔离实例，无业务库数据 |
| 受限账号 guard 真实复核 | exit 0 | 双哨兵匹配、端点匹配、仅 audit_auth DML、CREATE DATABASE 被拒 |
| 合成账号夹具初始化 | BLOCKED：计划要求的商品列表权限登记不存在 | 未补登记、未修改安装种子或断言 |
| 商品规则列表探针与夹具 | 用户批准后已完成初始化 | 保留安装权限种子；out/kefu 合成登录名使用验证器允许的字母数字 |
| PHP 8.2 应用启动 | 已成功监听 HTTP | 现有 Composer 自动加载表过旧；在专用 vendor 卷离线生成当前 composer.json 的兼容类映射，未改源码或锁文件 |
| 专用卷清理身份核验 | 已补实际标签、卷名及创建时间核对 | 新环境清理探针保留其他 project 的容器 ID |
| 六项环境自检（初始化补充前） | INTEGRATION 5 PASS / 1 FAIL，exit 1 | Z07 失败：login/info 返回业务码 400；其余五项通过 |
| Z07 原因复核 | MySQL errno 1142，TRUNCATE 所需 DROP 权限被拒 | audit_app 仍仅有 audit_auth DML；未扩大权限 |
| 批准的专用初始化 | 真实校验数据生成并与源码匹配，版本标记成功写入 | 仅 bootstrap 使用专用 migrator |
| 全新环境启动（最新） | bootstrap exit 0，自检 INTEGRATION 6 PASS / 0 FAIL，exit 0 | RUN_ID=20261011065024d6dda7；未使用上一轮卷或手动补丁 |
| 正常认证流程预跑 A28 | 3 PASS / 1 FAIL，exit 1 | out 旧令牌已拒绝，但为业务 400，尚未统一 401；其登录、刷新、新令牌均正常 |
| MS0a 完整 baseline | INTEGRATION 24 PASS / 61 FAIL，共 85 行，exit 1 | 实际测试 HEAD=9062a302，业务源码与历史 AUDIT_BASE_SHA 相同 |
| A09/A26 分类复核 baseline | 8 PASS / 13 FAIL，exit 1 | 已拒绝且仅为 400/402→401 契约差异的情况，不归为身份旁路 |
| A23 实际 Redis 停启 | PASS；拒绝时 HTTP 500、无业务或会话变化 | 恢复后双哨兵与会话值保留；MS0a 只要求拒绝，依赖故障 503 属于 MS0b |
| A10 重复配置切换时的启动器竞态 | 已修复；加强后的六项自检 6 PASS，exit 0 | Windows bind mount 在 rename 替换瞬间可能读不到控制文件；仅命令/ACK 空读重试，manifest/凭据仍严格失败；Z07 连续 16 次启停均真实返回 200 |
| Task 3 A10/A20 修复前 | 15 FAIL，exit 1 | 保留实际失败；Task 3 后 A10 登录密钥保护 4 PASS / 5 待后续任务 |
| Task 4 复测及 A20 | 43 项 31 PASS / 12 待后续端；A20 6 PASS，exit 0 | 管理端验签、状态、密码摘要与精确撤销通过；A27 认证失败不再写草稿 |
| Task 5 复测 | 43 项 34 PASS / 9 待 out，exit 1 | kefu/api 固定 type、四端缓存篡改与错误端请求后原会话回归；9 FAIL 均属待 Task 6 |
| Task 6 修复前 | 35 项 24 PASS / 11 FAIL，exit 1 | 关闭后访问/刷新、非法状态 2、out 文件缓存签发等实际失败；正常 1→0 持久化通过 |
| Task 6 首次全量复测 | 86 项 85 PASS / 1 FAIL，exit 1 | 仅 A23 的 Redis SET DUMP 摘要变化；业务表零变化，未将失败隐去 |
| A23 摘要差异只读诊断 | 集合 173 个完整成员排序摘要前后一致，DUMP SHA 变化 | 本次实例仅重启 Redis，不发 HTTP；目标是管理员标签 SET；不是会话值变化 |
| Redis SET 快照修复后的 A23 | 2 PASS / 0 FAIL，exit 0 | 对 SET 比较排序后的全部 SMEMBERS，保留类型标记；其他类型仍 DUMP；全部 Redis 键和成员仍严格比较，无新增允许变化 |
| 修复快照后的完整认证复测 | INTEGRATION 86 PASS / 0 FAIL，exit 0 | run.sh RUN_ID ms0a-auth.php；head=7ba8cbe6，加上随后提交 b2b2e922 的 Task 6 工作树改动；Task 8 的干净交付 SHA 验证尚未执行 |

## P6 证据位置

当前隔离运行目录：`C:/Users/Vwang/.tianli-audit/20261011065024d6dda7`。前轮 `2026101106410417ff4a` 的资源已按身份核验清理，证据仍保留。

- `manifest.json`、`network.json`、`containers.jsonl`、`volumes.txt`：本次专用资源身份。
- `evidence/selftest-after-20261011065236.jsonl`：全新运行的六项真实自检结果；仓库中 `results.jsonl` 保留脱敏摘要。前轮失败输出保留在前轮运行目录。
- `evidence/autoload-build.log`：离线生成自动加载表，5252 个类；`app-runtime.log`：PHP 8.2.33 内置服务器启动与 HTTP 请求。
- `evidence/ms0a-auth-baseline-20261011070721.jsonl`：完整 85 项认证基线，逐行指向脱敏响应、五表与全部 Redis 前后摘要；后续分类复核文件保留 A09/A26 的实际结果。
- `evidence/ms0a-auth-after-20261011072329.jsonl`、`20261011072500.jsonl`、`20261011072801.jsonl`：Task 4/5 及 A20 分步验证；中间结果的 commit_sha 是当时 HEAD，修复代码仍在 worktree，不能当成交付提交的干净环境验收。
- `evidence/ms0a-auth-after-20261011073657.jsonl`：Task 6 修复前；`20261011073853.jsonl`：首次全量 85/1；`20261011074043.jsonl`：A23 快照修复后 2/0。
- `evidence/ms0a-auth-after-20261011074202.jsonl`：完整 86 PASS；仓库 results.jsonl 当前只保存自检、原 baseline 与该完整 after 的阶段快照，尚不是最终交付包。
- `evidence/redis-set-probe-before.json`、`redis-set-probe-after.json`：只读 SET 值摘要诊断；全部成员保留，未记录成员内容、键名或令牌。
- `evidence/Z07-preflight.json`：只记录业务状态、MySQL 错误编号、调用链及统计，不含凭据或原始 HTTP 数据。
- 前轮 `202610110628309baa41/evidence/fixture-route-preflight.json` 保留商品列表未登记的只读事实；前轮专用资源已清理。
- 密码、密钥只在本次 project 的独立 Docker 卷中，不复制进报告或仓库。

## P7 数据边界

只在本次专用 MySQL 中建立 `audit_auth` 和受限账号、导入仓库安装 SQL、增加计划内的 `audit_sentinel`。Redis 同样为新建实例。没有连接既有 CRMEB、commerce-platform 或其他业务实例。

## P8 尚未验证

六项环境自检已通过，认证 baseline 已完成；Task 3–6 已实现并验证。路由清单、权限收紧与 Task 8 全新环境验证尚未完成。WORKER、BROWSER、EXTERNAL 未运行。当前不得描述为 MS0a 或多商户功能完成。

## P9 发现与决定

03 文档要求 `admin_limited` 的 `R-limited` 角色仅有商品列表读权限，但安装种子中 `auth_type=2 AND api_url='product/product' AND methods='GET'` 的数量为 0。源码 `adminapi/route/product.php` 的 `GET product/product` 路由确实存在；因此不能把它当作已登记已授权接口测试。

种子已有 `GET product/product/rule`（商品规则列表）权限登记，以及 outapi 的 `GET category/list` 登记。用户于 2026-10-11 明确批准使用现有商品规则列表作为正常授权探针；03/04 文档及夹具已据此调整。商品列表未登记的事实保留，后续纳入 Task 7 清单；没有修改权限种子。

## P10 已解决的安装初始化差异

触发 12 文档 §5.1：计划要求受限 DML 账号在仅导入安装种子的干净 worktree 上访问 `GET /adminapi/login/info` 返回 200，但现有代码第一次请求会执行安装后的文件校验初始化。

实际链路：`SystemAdminServices::getLoginInfo()`（189–192）发现 `eb_cache` 中不存在当前版本的 `write_md5` 标记，调用 `SystemFileServices::writeMd5()`（504–527）；其 `SystemFileMd5Services::clearMd5List()`（34–37）执行 `TRUNCATE TABLE eb_system_file_md5`，MySQL 要求 DROP 权限。因此业务响应为 400 / errno 1142。只读统计：write_md5 标记行数 0，安装种子的文件校验行数 1071。该行为不属于本次认证修复。

建议用户批准的具体方案：在 bootstrap 引导阶段，以本次实例的 `audit_migrator` 配置启动真实 ThinkPHP，调用现有 `writeMd5()` 生成当前源码的校验数据，确认成功后用现有 `CacheServices::setDbCache()` 写入真实版本的 `write_md5` 标记；再恢复受限 `audit_app` 并执行 Z07 的原始 200 断言。引导在已核对的专用资源内执行，不新增表或权限种子，不伪造缓存来跳过实际生成。03/04 文档同步记录这项新增初始化步骤。

用户于 2026-10-11 明确批准上述方案；03/04 文档已单独提交 `da1af4f6`。引导初始化已实现并验证，Z07 恢复为 PASS。应用权限未扩大；该引导提交没有业务源码改动，后续认证修复另列 Task 3–6。推送和部署均未发生。

## P11 已复现的认证基线

- A09：四个自身应用探针均成功；6 个错误应用组合返回 200，部分顾客路径还更新了 eb_user；另 3 个进入 outapi 的组合已被 400 拒绝，只缺统一 401。
- A11/A13/A14：原有管理端密码摘要检查已拒绝 out 登录、客服、顾客令牌；归为既有拒绝回归。
- A12：out 真实续签令牌在管理员 ID 101/102 碰撞时都能访问管理端探针（200）。
- A15/A16：禁用管理员与软删除管理员仍返回 200；物理删除/不存在已拒绝并精确撤销本会话。
- A17/A18：缺少或空 pwd、缓存 type/uid/token 不匹配可绕过；既有拒绝但无精确撤销、或错误撤销的情况另记契约差异。
- A19：关闭账号的现有令牌仍可访问/刷新；非法 status=2 写入；MCP 和软删除入口原本已拒绝，统一业务码仍需修复。
- A26：limited 令牌先打不存在的客服 ID 后，原管理端会话也被删；正确应用随后 401。
- A27：错误签名、过期令牌命中商品保存路由，两项均实际改变 eb_cache 草稿记录；独立精确准备与恢复，不触碰其他缓存行。
- A10：空/default 密钥与文件缓存均会继续签发，且登录统计有业务写入。

以上为基线事实，FAIL 保留最终契约断言；不能把所有 FAIL 都称为本次新增漏洞或修复成果。

## P12 必要调用点顺序

Task 3 的签名配置保护同时在 `SystemAdminServices::login()` 的 `verifyLogin()` 之前接入。该文件已在 Task 4 的计划范围，属于相同配置修复的必要调用点：verifyLogin 已写登录统计，单独在 JwtAuth::getToken 检查会导致虽然返回 503、业务表却已改变。A10 基线已观察到这项实际变化。这里只调整实现任务的归属与调用顺序，不改变已批准的行为、权限或测试期望；Task 4 的 Redis 存储保护仍在同一位置接入。

## P13 阶段独立复核

未参与实现的 harness_review 代理只读审阅 Task 3–6 源码与完整 after 结果，确认 86 个唯一 INTEGRATION 子场景全部 PASS；抽开 A09 的 16 项、A12 的 2 项、A27 的 2 项、A19 关闭访问/刷新 2 项、A26 的 5 项，共 27 份脱敏原始证据。跨端拒绝、原端会话保留、五表和缓存无未允许变化均与结果一致；没有必须修复项。

该复核不代替 11 文档要求的最终 V4 新 worktree / 新 RUN 复跑及基线抽样；Task 7 授权与菜单缓存审阅仍未完成，执行方没有自行签收。

## P14 SP-MS0a-1 路由决定待确认

实际命令：`AUDIT_PHASE=after bash help/audit-regressions/isolated/run.sh 20261011065024d6dda7 help/audit-regressions/isolated/route-inventory.php`，exit 0。枚举 973 条唯一方法/规则：11 公开、3 仅认证、958 认证加角色授权、1 MISS。958 条中 435 已登记、523 未登记；安装 auth_type=2 权限 641 行，对应 437 个唯一键。CRUD 目录为空。全部 158 张表和 Redis 405 键摘要前后一致，无 HTTP 请求。

完整矩阵及原两项白名单方案的 521 条拒绝清单见 `route-permission-matrix.md`；运行证据 `route-inventory-metadata.json`、`route-permission-matrix.md` 保存在 P6 的仓库外 evidence 目录，矩阵 SHA 与仓库副本一致。此阶段没有新增权限种子。

综合本人菜单与个人中心的源码调用，执行方建议六项精确方法/规则白名单，等待用户确认：

| 方法与规则 | 登录即可访问的范围 |
|---|---|
| get setting/admin/logout | 退出本人会话，现有例外 |
| get menuslist | 全局可搜索菜单元数据，现有例外；没有本人角色过滤，不等同本人菜单 |
| get menus | 本人启用角色范围内的菜单与权限标识 |
| get setting/menus/unique | 同一本人角色过滤的菜单与权限标识，供导航刷新 |
| get setting/info | 本人账户资料，隐藏密码、删除和状态字段 |
| put setting/update_admin | 仅修改本人姓名、头像，或凭旧密码改密；不能修改角色、等级、账号或状态 |

该六项方案下，其余未登记且挂角色中间件的 517 条路由将拒绝受限管理员。具体清单等于矩阵原 521 条拒绝清单，扣除 `get menus`、`get setting/menus/unique`、`get setting/info`、`put setting/update_admin` 四条。保守选项为只加两条本人菜单（四项白名单、519 条拒绝），或严格保留原两项（521 条拒绝，但本人导航刷新及资料修改会被拒绝）。已登记接口仍须对应角色授权；最高管理员 level=0 的现有角色豁免按计划保持。

额外覆盖决定：`get system/info`、`get route/import_api`、`get download/<key?>` 现仅认证，不经过 verifyAuth。建议在其现有分组补 AdminCheckRoleMiddleware，三项保持未登记并拒绝受限管理员；最高管理员仍可调用。采用六项白名单并补这三项角色中间件后，受限管理员的未登记拒绝清单共 520 条。导入有路由元数据写入，下载成功会删除一次性文件缓存键；不应将其标为无副作用接口。

补中间件会涉及 `crmeb/app/adminapi/route/route.php` 这一必要调用点，并需在 04 文档记录用户批准的范围和对应负向测试。当前该文件、verifyAuth、白名单均未修改；停在 04 Task 7 Step 2 / 12 文档 SP-MS0a-1，等待这两项决定。WORKER、BROWSER、EXTERNAL 与最终验收仍未执行。
