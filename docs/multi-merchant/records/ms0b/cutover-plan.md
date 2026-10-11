# MS0b 认证切换准备方案（文档交付，不执行发布）

编写依据：`00-implementation-spec.md §11.3`、`05-plan-ms0b.md Task9`、`records/ms0b/runbook-session-loss.md`；主源码快照 `9dc8e99dc0024a50c8e37aa61cee21108053dace`，另核对两行日志修复提交 `ec2c84b7dc92a38934b60051f4391bc7080a9cf0`。日期：2026-10-12。文档路径为 `docs/multi-merchant/records/ms0b/cutover-plan.md`。

当前仍是L2本地交付：Q6 `5b95cfa7`、Q7 `875b9300`，Task8最后六项普通HTTP/CLI检查PASS、原生Bash exit0，前端构建exit0。两个真实构建下载页面通过本RUN私有传输桥完成正常首次BROWSER保存，名称、长度与哈希对应实际文件，四份截图已查看，见 `task8-browser-summary.json`；这不是生产网络、全站或WORKER验收，console缺口单列。验证码完整解答和用户跳过的安全、异常、故障、并发/断连、恢复检查未执行。本方案只提供L2文档交付。推送需要L3授权；通知、部署、生产轮换、数据恢复及外部验收需要L4授权，当前均未执行。全部实际集成使用情况为“未知，待核实”，见同目录 `integration-inventory.md`。

## 发布前必须取得的记录

| 前置项 | 必须形成的可复核记录 | 当前状态 / 责任 |
| --- | --- | --- |
| 调用方盘点 | 每个真实脱敏账号及每种认证模式分别建行，填用途、负责人、真实最近使用来源和观察窗口、入口、重新取令牌办法、切换负责人、普通验证办法；包括定时和低频任务 | 未核实；用户协调调用方负责人，不能由代码或无日志推断未使用 |
| 通知 | 用户实际完成维护窗口、新令牌领取、header下载及worker重连等通知，提供时间、范围和回执的脱敏记录 | 未执行；用户负责通知，执行代理不发送 |
| 发布授权与窗口 | L4范围、目标环境、部署/切换负责人、维护起止、停止/开放入口的人、正常验证负责人及中止条件 | 未取得；本稿不代替授权 |
| 候选包 | 固定完整Git SHA、15个Task8业务文件及上游v2/MS0a、Task9两处worker日志修复文件哈希、同构前端构建产物、运行时/依赖版本和配置版本引用 | 仅本地源码/构建证据；最终发布包与Task10常规冻结记录待定 |
| 验收缺口 | 本地两项BROWSER正常下载已补证，生产调用方BROWSER/WORKER/EXTERNAL仍待；跳过项NOT RUN、完整MS0b尚未签收的明确记录；由验收责任人决定发布是否满足条件，不改写为全PASS | 保留；本稿不创建人工签收或SECURITY_BASE_SHA |
| 实例范围 | 实际HTTP实例、反向代理、CLI/定时任务、admin通知worker、共享chat/channel、Redis数据库及MySQL连接的部署清单 | 未核实；ISO-FULL三容器不能当作生产拓扑 |
| 配置与加载证据 | 密钥库引用版本、同一新epoch、issuer/type/audience/TTL、有效signing/stamp配置、所有目标进程实际加载一致 | 未下发/未加载核对 |
| 回退包 | 具体可用的、兼容v2且保留MS0a及本轮边界的回退SHA/包哈希与配置记录 | 未指定；不存在合格回退包时不能假定旧单商家包可回退 |
| worker日志 | 核对原始消息输出修复包和实际部署范围；完整日志边界及正常连接证据仍分开记录 | 两条调用已由根在ec2c84b7移除，PHP8.2两文件lint0、各0insert/1delete；未运行worker或读取生产日志，不构成全部日志已脱敏结论 |

Task9可以完成文档准备，不能据此把发布前置条件改为完成。用户跳过的测试不是本阶段新执行任务，也不通过改名、换入口或部署前演练恢复执行。

## 配置合同、生成与受控下发

| 配置 / 用途 | 当前源码实际合同 | 运维准备方式（本次不生成值） |
| --- | --- | --- |
| `AUTH_SESSION_EPOCH` / `auth.session_epoch` | `^[A-Za-z0-9._-]{1,128}$`；adminapi/outapi共用同一配置世代，Redis服务端会话按app分区 | 在受控运维端以操作系统CSPRNG生成32随机字节的64位十六进制文本，记录为新的部署标识；若存在旧v2世代必须与准确旧值不同；不能用时间戳/账号/默认值替代随机方案 |
| `AUTH_STAMP_KEY` / `auth.stamp_key` | 字符串至少32字节，且必须与signing key不同；HMAC服务端凭据戳及登录组合计数所用的独立密钥 | 独立CSPRNG生成，与signing key采用不同生成结果和密钥库条目；只通过受控secret引用、受限配置文件或secret挂载下发；文档/日志/命令行不保存值 |
| `APP_APP_KEY` / `app.app_key` | AuthConfigGuard实际从Env读取：必须是非空字符串且不为`default`；源码没有“签名密钥最少32字节”的附加长度判断 | 优先保留现有有效受控签名配置，本轮不自动轮换此共享密钥。首次配置或另行批准更换时可独立生成32随机字节并编码；须额外盘点顾客、客服、fileToken等使用同一key的原协议影响 |
| `AUTH_ISSUER` / `auth.issuer` | 默认为`tianli-mall`；实际允许1–128个英文字母/数字/`._:/-`字符 | 所有实例保持同一明确值，不能随节点主机名改变 |
| `auth.apps` | adminapi type=`admin`、outapi type=`out`；默认audience分别adminapi/outapi，默认TTL均30天；有效TTL必须为正整数且≤2147483587 | 使用已冻结配置和代码；不能靠未知客户端要求改变应用绑定或扩大TTL |
| 默认Redis与数据库 | default Cache必须Redis；独立原生工厂connect2秒/read1秒，支持原password/ACL和select；真实账号凭据/状态还需MySQL读取 | 下发既有受控连接引用，核对目标数据库/Redis实例/库号和权限；不扩权，不改业务Cache prefix/tag/serializer选项，不改数据库结构 |

`crmeb/config/auth.php` 的逻辑名称不能等同于任意OS环境变量。当前Think Env将逻辑名转大写下划线后，优先读其已加载的Env数据；OS fallback读取带`PHP_`前缀的名称。因此容器/进程直接注入时应使用对应的 `PHP_AUTH_SESSION_EPOCH`、`PHP_AUTH_STAMP_KEY`、`PHP_AUTH_ISSUER`、`PHP_APP_APP_KEY`；受控.env或明确的Env.set映射则必须由实际加载结果证明。已有.env值可能优先于新OS变量；只修改环境变量但进程Config仍旧不算生效。不能把ISO测试私有配置、开发默认值或生成过程输出复制为生产秘密。

密钥生成和下发在发布责任人的受控主机/密钥库内完成。只把引用版本、发放范围、时间、接收人和一致性结论写入脱敏发布记录；密钥正文、令牌、密码、Authorization头、Redis原始错误不进入Git、命令参数、CI日志、工单正文或本清单。本稿没有生成真实epoch或密钥。

## 另行授权后的部署与切换顺序

以下是待授权的具体顺序，不表示当前执行。

1. 用户与调用方确认维护窗口及通知回执，固定候选包、完整实例清单和兼容v2回退包。保存原部署/配置引用及准确旧v2世代；若是首次从MS0a legacy迁入v2，记录“此前无已确认v2世代”，不编造OLD_EPOCH。
2. 由应用外层隔离adminapi/outapi登录、访问、刷新及下载的认证入口，暂停会调用这些入口的相关脚本/定时任务。控制通知worker的新连接并关闭已建立的admin连接；`WorkermanHandle::login`只在登录时验证后缓存adminInfo，改epoch本身不会让旧连接即时重新验证。若管理方式会连带重启chat/channel，先纳入客服/顾客连接影响及通知范围。
3. 在隔离保持期间部署完整后端v2及MS0a边界源码和配套前端下载代码，采用已固定包和文件哈希；不让旧认证节点与新节点同时对外发行/接受会话。MS0b本轮没有发布DDL或历史业务数据迁移，不清库、不重置权限/账号种子。
4. 对全部相关实例下发一致的新epoch、独立stamp secret引用、有效现有signing引用和issuer/app绑定；同一批次重载或重启HTTP与需要加载这些配置的worker/CLI服务，并处理部署层旧配置缓存。保留其他应用的配置及原认证命名空间。前端与后端一起切换，避免旧页面继续拼URL JWT下载。
5. 逐实例取得下节的真实加载证据。任何实例仍为旧世代、缺少必要secret引用、AuthConfigGuard未通过或实际后端依赖不可用时保持隔离；不降级为旧JWT/文件缓存/进程内存、不放宽角色、不增加配置fallback。
6. 仅在存在已确认旧v2世代且另行授权了旧会话清理时，依现有 `runbook-session-loss.md` 在新配置已实际加载的应用发布目录执行 `php think auth:epoch-rotate --old-epoch='OLD_EPOCH' --confirm`。OLD_EPOCH必须是记录的准确旧值；检查原生exit0及`deleted_keys: N`。命令只SCAN/分批DEL旧`auth:v2:<old>:*`，不改DB/配置或其他缓存。首次legacy迁入且没有旧v2世代时该命令步骤不适用；旧legacy记录即便尚存也不能成为v2授权依据，不用通配符或FLUSH替代。
7. 由指定责任人使用本次合法凭据重新取令牌，核对下节的普通业务路径，再分批开放受影响入口、恢复经核实的任务和worker连接。管理员重新登录后使用新令牌重连notice；outapi通过access_token重新领取，不能用旧格式token走refresh来迁移。
8. 用户取得调用方正常验收回执并更新集成清单，记录实际包/Config/实例证据、原生退出、删除数、开放时间及问题处置。仅按取得的证据记录HTTP、CLI、BUILD、BROWSER、WORKER、EXTERNAL层级，不相互替代。

密钥/epoch配置、停止worker、清理旧会话与开放入口都不是本次文档操作；没有生产恢复或RDB试验步骤。

## 真实加载与普通业务核对

| 核对对象 | 必须取得的实际证据 | 不成立的替代证据 |
| --- | --- | --- |
| 每个HTTP/常驻认证进程 | 进程/实例及发布SHA明确；其已加载`Config::get('auth')`和`Env::get('app.app_key')`经`AuthConfigGuard::authConfig()`判断有效，实际epoch/issuer/app绑定一致；secret引用一致性只记录布尔结果和引用版本，不能导出整个返回数组（含signing key） | 仅检查.env、镜像env、进程启动成功或单节点登录 |
| 服务端会话依赖 | 在受控维护窗口通过正常合法登录/本人已授权的只读请求取得真实HTTP结果，并记录所用实例与配置版本；v2会话使用新世代原生Redis及当前DB凭据 | 纯authConfig通过不能证明Redis/DB连接正常，CacheService旧truthy返回不能证明新会话写入 |
| 后台页面及下载 | 普通合法登录、正常页面及一次真实CRUD/备份生成和首次header下载；实际保存的文件与当前生成文件对应；header名按`cookie.token_name`（默认`Authori-zation`），文件query key正确编码 | 本地六项HTTP或build0不能替代真实浏览器操作；URL中的文件key不是JWT，不能补回GET token fallback |
| 对外JWT集成 | 责任人以本账号正常getToken领取，再调用其已有权限的只读接口；如实际使用刷新，按单次兑换合同原子保存新token再续用 | 不拿MCP正常响应证明outapi JWT已切换；不检查错误凭据、旧token拒绝或并发刷新 |
| MCP | 单列其当前有效account/password及启用状态配置归属，责任人执行正常initialize/tools-list等非写入调用；不保存头正文 | epoch清理不更新MCP凭据，也不证明MCP在真实环境可用 |
| worker | 将ec2c84b7两行修复纳入发布包，再确认实际重启/关闭旧连接、正确新配置及普通notice新令牌登录/正常通知；WORKER证据单列 | 两行静态/lint、普通HTTP、单位替身或worker进程status不能证明全部日志已脱敏或现存连接重新鉴权；当前尚无WORKER发布验收 |
| 外部调用方 | 用户提供各行责任人普通回执、时间/窗口及脱敏证据引用 | 没有生产使用日志不是未使用，接线源码或合成账号不是外部验收 |

仓库没有现成`auth:config`诊断命令；本稿不发明新接口或命令。实际加载核对方式必须由发布责任人在已批准、不会打印秘密的运维工具/受控进程观察方式中落实；无法取得进程加载证据时，该发布前置项仍未满足。上述正常核对未在本稿执行；用户跳过的安全、故障、异常及并发等检查保持NOT RUN，不附加到此表。

两行修复的独立主机收据为 `H:/天俪商城/artifacts/ms0b-task9/raw-message-removal-summary.json`，SHA256 `09fa06ec699d75432a320f950ab1a718efaceac3152bb4dfed538c1b2f01074b`。它只覆盖原9dc8e99d中 `crmeb/crmeb/services/workerman/WorkermanService.php:78` 的 `var_dump('adminMessage', $res)`、`crmeb/crmeb/services/workerman/chat/ChatService.php:114` 的 `var_dump('chatMessage', $res)` 的移除、精确源码差异和根代理语法核对。未启动worker、未检查历史生产日志；不能推断过去没有泄露，不能宣称全部日志完成脱敏，也不能替代常驻连接及缓存adminInfo卸载的待验事项。

## 影响及回退约束

- adminapi/outapi只接受符合当前世代、app/type/audience和服务端记录的v2会话。首次切换的旧格式令牌、世代切换前的v2会话以及旧refresh输入都不能沿用；管理员和JWT调用方须正常重新领取。受影响窗口及自动任务改动由各真实负责人确认。
- notice worker已有连接保存过管理员身份，因此切换要关闭并重新建立连接；可能的消息短暂中断和共享chat/channel影响必须由用户纳入通知。chat顾客/客服JWT仍是原认证路径，不能把本次epoch旋转说成它们全部改成v2。
- MCP请求头直连凭据不消费v2会话，本次epoch清理不重置其appsecret或账号状态；若另行更改账号/DB恢复，应按该模式独立核对，不能借切换伪造已验证结论。
- 下载继续要求admin和角色授权。JWT改为header，文件key仍可在route或编码query中传递；它是300秒下载记录标识而非登录身份。维护期间过期/已消费的文件key需要正常重新生成，epoch命令不清全业务缓存。扫码uploadToken、fileToken、顾客/客服令牌不自动改协议或混作v2。
- 回退只切到已指定且保留MS0a签名/角色/账号状态/会话边界、识别v2 schema及本轮header/原生缓存边界的兼容包。不能直接以pre-v2的MS0a包、最初audit-base或旧单商家源码连接现数据库作为“保留安全”的回退，也不自行指定SECURITY_BASE_SHA。
- 保持已切换的新epoch、当前stamp/signing引用及有效服务端状态，禁止恢复旧世代、旧令牌白名单、GET token fallback、未登记路由默认允许、纯JWT或文件缓存认证。若为了隔离问题另行更换epoch，仍按真实配置下发/重载和用户通知流程处理，不能伪称旧会话恢复。
- 不因代码回退自动恢复业务DB、RDB或权限种子。数据恢复是独立授权及损失窗口说明的操作，当前按用户要求未执行相关演练。本次文档既不准备恢复脚本，也不恢复跳过测试。

## 文档完成与发布状态

本文完成发布准备的合同、顺序和待核实字段，作为本地仓库文档交付；没有改实际配置、生成秘密、执行发布核对、访问外网或发送通知。真实集成盘点、通知、L4、加载核对、兼容回退包、生产调用方BROWSER/WORKER/EXTERNAL回执均没有完成证据。本地Task8两正常BROWSER保存有独立收据。Task9“文档准备”与真实发布、Task10及完整验收分开记录。
