# MS0b 认证集成清单（代码侧清单；真实使用待核实）

依据 `00-implementation-spec.md §11.3` 与 `05-plan-ms0b.md Task9`；主源码快照 `9dc8e99dc0024a50c8e37aa61cee21108053dace`，另核对两条原始消息打印移除提交 `ec2c84b7dc92a38934b60051f4391bc7080a9cf0`，日期2026-10-12。文档路径为 `docs/multi-merchant/records/ms0b/integration-inventory.md`。

本清单只证明源码存在这些入口，不证明任何实际账号或集成正在使用。下列标识是用途分组的脱敏占位编号，不能当作已发现的生产账号；用户/责任人应按每个真实账号、应用及认证模式拆成独立行，即使同一个appid兼用JWT与MCP也不能合并其切换责任。没有查询生产DB、访问日志、调度配置或外部系统，所有“实际在用”均为“未知，待核实”，观察窗口均未提供。

真实凭据、密码、JWT、fileToken、download key、MCP头正文、完整请求/响应和真实账号映射不得写入此清单。别名到真实账号的映射由用户保存在受控资料中；此处仅保留负责人、脱敏证据引用、时间/时区/范围及验证结论。

## 代码入口与认证模式

表中HTTP路径以应用挂载`/adminapi`、`/outapi`为默认代码侧入口，实际域名/代理挂载和worker部署地址需核实。`JWT v2`两应用共用部署epoch而有独立app/type/audience绑定；默认HTTP认证头为`Authori-zation: Bearer <token>`（真实名称取`cookie.token_name`）。顾客/客服/fileToken/扫码能力不因名称中含“token”就归入v2。

| 脱敏账号标识（占位） | 用途 | 认证模式 | 调用入口 / 实源生产消费链 | 能否重新取令牌（代码能力；实际待核实） |
| --- | --- | --- | --- | --- |
| ADM-UI-01（待填真实别名） | 管理员后台合法登录、菜单及管理业务、本人退出 | adminapi JWT v2；account/pwd领取；Q6既有验证码协议 | POST `/adminapi/login`，GET `login/info`、`ajcaptcha`，POST `ajcheck`；受保护adminapi路由与GET `setting/admin/logout`；AdminAuthTokenMiddleware→AdminAuthServices | 有登录入口；没有本阶段admin专用refresh接口，不可借outapi刷新；实际账户、可登录/验证码能力与负责人待核实 |
| ADM-HTTP-01（待填真实别名） | 第三方/内部脚本调用adminapi | adminapi JWT v2，header认证 | 同adminapi登录及其已授予角色的接口；公开/登录即可访问白名单与角色路由分别按实际合同执行 | 代码可重新正常登录；无人值守脚本能否遵守验证码门槛/保存新token未知，不能用URL JWT或跳过captcha代替 |
| ADM-WS-01（待填真实别名） | 后台notice长连接与订单/系统通知 | 管理员v2 JWT作为WebSocket登录消息data；不是HTTP query JWT | 默认对外`/notice`；get_workerman_url→getWorkerManUrl；WorkermanService.onMessage→WorkermanHandle.login→AdminAuthServices.parseToken | 代码可先正常adminapi登录再发`type=login`消息；现连接缓存adminInfo，须关闭旧连接并以新令牌重连；真实客户端能力未知 |
| ADM-DL-CRUD-01（待填真实别名） | 后台代码生成或调用方生成ZIP下载 | adminapi JWT v2 header +独立300秒文件key | GET `/adminapi/system/crud/download/:id`→SystemCrud.download→CacheService.setDownload；首次GET `/adminapi/download/:key`→consumeDownload；前端codeGeneration/list.vue→libs/download.js | 先重新登录；记录过期/消费后重新正常生成key；只有文件key不能授权；真实使用者及保存方式未知 |
| ADM-DL-BACKUP-01（待填真实别名） | 后台/运维备份记录下载 | adminapi JWT v2 header +原bcrypt格式文件key | GET `/adminapi/backup/download?time=...`→SystemDatabackup→MysqlBackupService.downloadFile→setDownload；首次GET `/adminapi/download?key=...`，query key编码；systemDatabackup/index.vue→libs/download.js | 先重新登录并由真实备份producer重新生成文件key；JWT不入URL；实际授权/备份记录归属与保存能力未知 |
| ADM-DL-OTHER-01（按实际功能/别名拆行） | 其他数据导出、升级包/项目导出及主题归档 | 管理API为adminapi JWT v2+角色；主题生成的public静态归档URL本身不是generic下载JWT，生产静态服务控制未知 | `/adminapi/export/*`；POST `system/package_download/:package_key`、GET `system/upgrade_export/:id/:type`；Theme导出/轮询元数据后返回 `/theme/download/...zip`；不同于本轮两种setDownload producer | 管理API可重新正常登录；各自包key/静态URL生成合同及真实消费者待核实。不能把主题静态URL当作已受adminapi/download鉴权，也不自动改这些原协议 |
| EXT-JWT-01（按真实appid拆行） | 对外商品分类、商品、订单、售后、优惠券、用户等级及用户集成 | outapi JWT v2，appid/appsecret领取，header认证及OutInterfaceServices接口权限 | POST `/outapi/access_token`→Login.getToken→OutAccountServices.authLogin；outapi route.php中的category/product/order/refund/coupon/user_level/user分组→AuthTokenMiddleware | 有正常access_token入口；负责人能否取得当前合法凭据、账号是否启用/具备接口权限及新token存储办法未知 |
| EXT-REFRESH-01（按真实appid/任务拆行） | 对外端刷新、长周期任务或token缓存 | outapi v2当前token POST body单次兑换 | POST `/outapi/refresh_token`，原始body字段`access_token`→Login.refreshToken→OutAccountServices.refresh | 旧格式/旧世代token不能作为迁移refresh材料，应先正常领取新token；之后单次兑换并保存新token；真实任务频率/负责人未知 |
| MCP-DIRECT-01（按真实appid拆行） | AI助手/自动化MCP调用 | MCP直连account/password请求头，对应当前appid/appsecret；不消费JWT v2会话 | POST `/outapi/mcp`→Mcp.initialize/authByAppSecret；当前未删除、启用账号及password_verify核对；MCP自身调用合同独立 | 不需领JWT；epoch不换MCP密码。当前有效凭据来源、调用工具、真实状态及负责人待核实，不能以JWT切换代替此行核实 |
| FILE-EDIT-01（待填真实别名） | 后台文件管理的独立fileToken能力 | 外层adminapi v2+角色；内层原file_edit JWT/cache能力，非v2会话 | POST `/adminapi/system/file/login`需当前最高管理员；SystemFileServices.Login核对`filesystem.password`，生成JWT并返回其MD5句柄；`system/file/*`经AdminEditorTokenMiddleware从query `fileToken`取缓存后parseToken | 代码有重新领取入口，实际filesystem密码/功能是否使用未知。不能当作admin v2 JWT；也不把Task6未运行的admin.file_pwd兼容项等同于本配置密码流程 |
| SCAN-UPLOAD-01（待填真实别名） | 后台扫码上传图像的独立能力 | 原uploadToken与专用缓存值比对；不是admin/out JWT v2 | SystemAttachment扫码生成`scan_upload`缓存（600秒）；POST `/adminapi/image/scan_upload`以uploadToken→PublicController.scanUpload→原上传服务 | 代码侧可由原管理入口重新生成扫码能力；真实入口使用/归属与负责人未知；本轮不改协议，不把其query/body token归为待删除JWT |
| CHAT-USER-01（待填真实别名） | 顾客聊天连接及独立顾客端边界 | 原api JWT+SessionWhitelist，由UserAuthServices核对；非v2迁移 | 默认`/msg`；ChatService.onMessage→ChatHandle.login→UserAuthServices.parseToken；HTTP顾客端领取入口保持原实现 | 有原顾客端认证链；具体客户端重新登录/连接能力未知。新AUTH_SESSION_EPOCH不自动替代原api命名空间 |
| CHAT-KEFU-01（待填真实别名） | 客服聊天连接及独立客服端边界 | 原kefu JWT+SessionWhitelist，LoginServices核对；非v2迁移 | 默认`/msg`；ChatService.onMessage→ChatHandle.kefu_login→app/services/kefu/LoginServices.parseToken；客服端原登录链 | 有原客服登录链；具体负责人和重连能力未知。如共享worker重启会中断，需纳入用户通知，不能宣布客服已改成v2 |
| OPS-CLI-01（待填运维别名） | 世代清理、常驻服务及配置管理；同时盘点保存JWT的定时/队列脚本 | 主机运维权限/受控Redis与DB配置；若脚本调用admin/out HTTP，另建上述JWT行 | `AuthEpochRotate`命令；`crmeb/crmeb/command/Workerman.php`的admin/chat/channel服务管理；实际scheduler/queue/部署入口清单待提供 | 命令不领取token也不生成epoch；JWT任务须按各自领取入口办理。不能从CLI源码推断真实存在的任务/账号清单 |

outapi业务分组的完整代码侧集合为category、product（含库存）、order（含发货/拆单/发票）、refund、coupon、user_level、user（含余额/积分等）。这是认证接入范围索引，不授权在验收中调用写业务接口；正常核实优先选真实账号已有权限的只读路径。MCP与JWT账户可以来自同一out_account数据，但认证方式、续令牌、权限入口和通知责任必须分别记录。

## 负责人、使用证据与切换验证

以下每行与上表同标识关联，共同构成§11.3要求的完整记录。此表中的“拟定验证”只是正常验收方法与证据层级，当前不执行；本地合成账号/源码/build证据不代表生产最近使用或外部调用方签收。

| 脱敏账号标识 | 用途负责人 | 最近使用证据及观察窗口 | 实际在用 | 切换负责人 | 拟定普通验证方式 / 当前证据边界 |
| --- | --- | --- | --- | --- | --- |
| ADM-UI-01 | 待用户指定 | 未提供；起止时间、时区及覆盖客户端未知 | 未知，待核实 | 待用户指定 | 真实合法登录、本人菜单/只读页面，记录BROWSER回执；本地T8F01/02普通HTTP已PASS，Task8合成账号构建页面的正常登录及两下载保存已观察，不能替代生产调用方验收 |
| ADM-HTTP-01 | 待用户与系统方指定 | 未提供；脚本/任务周期和访问来源窗口未知 | 未知，待核实 | 待用户与系统方指定 | 责任人按当前header正常取token及已有权限只读调用，记录EXTERNAL回执；不试旧URL JWT拒绝或绕过 |
| ADM-WS-01 | 待用户与运维指定 | 未提供；连接/通知采样窗口未知 | 未知，待核实 | 待用户与运维指定 | 将两行输出移除修复纳入包，关闭旧连接后正常领取并重连、接收普通通知，单列WORKER；两行静态/lint不证明全部日志或worker，本轮未运行worker |
| ADM-DL-CRUD-01 | 待用户指定 | 未提供；下载页面/脚本的真实使用窗口未知 | 未知，待核实 | 待用户与前端负责人指定 | 普通生成及首次header文件保存、9entry/字节对应；本地T8F05 HTTP PASS/BUILD0及B01正常BROWSER保存PASS，四图中的两CRUD截图已查看；私有传输桥不代替生产网络/真实使用方验收 |
| ADM-DL-BACKUP-01 | 待用户与运维指定 | 未提供；实际备份下载窗口未知 | 未知，待核实 | 待用户与前端/运维指定 | 普通已有备份producer与首次编码query key/header下载，核对文件归属/保存；本地T8F06 HTTP PASS及B02正常BROWSER保存PASS，两备份截图已查看；真实运维使用、网络及外部验收仍待 |
| ADM-DL-OTHER-01 | 待用户与对应功能/下载负责人指定 | 未提供；导出、包下载及静态归档实际使用窗口未知 | 未知，待核实 | 待用户与对应负责人指定 | 先只读核对每种实际下载地址/认证链与负责人；经批准的普通自有文件下载回执单列，不以T8F05/06代替，不运行升级、业务数据导出或额外静态授权试验 |
| EXT-JWT-01 | 待对应集成方指定 | 未提供；接口访问或集成侧请求回执/业务周期窗口未知 | 未知，待核实 | 待用户与对应集成方指定 | 正常重新领token及已许可只读接口，脱敏EXTERNAL回执；Task5本地正常接线证据不是真实集成使用 |
| EXT-REFRESH-01 | 待对应任务负责人指定 | 未提供；令牌保存/刷新周期/任务运行证据窗口未知 | 未知，待核实 | 待用户与任务负责人指定 | 正常领取后单次刷新与客户端保存合同核对；不执行并发、断连、旧token或故障检查 |
| MCP-DIRECT-01 | 待MCP使用方指定 | 未提供；MCP请求或客户端配置核对窗口未知 | 未知，待核实 | 待用户与MCP使用方指定 | 当前合法直连凭据正常initialize/tools-list等非写入调用，单列MCP/EXTERNAL；不混作JWT测试 |
| FILE-EDIT-01 | 待用户与文件管理负责人指定 | 未提供；原fileToken/文件管理使用窗口未知 | 未知，待核实 | 待用户与运维指定 | 只读核对功能/密码配置归属和合法目录访问；不改生产文件，不把fileToken当JWT迁移；兼容证据未取得 |
| SCAN-UPLOAD-01 | 待用户与上传负责人指定 | 未提供；扫码能力领取及上传来源窗口未知 | 未知，待核实 | 待用户与上传负责人指定 | 配置/入口归属核对；真实自有图片正常上传回执由责任人另安排；无本阶段运行证据 |
| CHAT-USER-01 | 待用户与顾客端负责人指定 | 未提供；实际聊天/顾客端窗口未知 | 未知，待核实 | 待用户与运维/顾客端指定 | 核对原api边界及共享worker重启影响；如受影响仅普通合法重连，客户体验回执单列；不宣称v2迁移 |
| CHAT-KEFU-01 | 待用户与客服端负责人指定 | 未提供；客服在线/重连窗口未知 | 未知，待核实 | 待用户与客服/运维指定 | 核对原kefu边界及共享worker影响；如受影响普通客服连接回执单列；无WORKER验收 |
| OPS-CLI-01 | 待用户与运维指定 | 未提供；实际服务管理/任务及配置发布窗口未知 | 未知，待核实 | 待用户指定 | 核对部署实例、真实加载配置、准确旧epoch和命令正常退出/计数；Task7本地普通CLI不是生产轮换证明 |

清单待填时，不选任意“最近7天无日志”作为未使用结论。责任人须指定覆盖其真实业务周期的起止窗口及来源/覆盖范围；低频月度任务、缓存JWT脚本或长连必须纳入。即使最终确认未使用，也应记录责任人、观察窗口和可核实依据。只有源码无对应调用日志不能消除发布阻塞。

## 源码依据与必须分开的边界

| 依据（仓库相对路径） | 本次只读确认 |
| --- | --- |
| `crmeb/app/adminapi/route/route.php:24–38,53–57`；`crmeb/app/adminapi/route/setting.php:32` | 登录、验证码、notice URL与扫码入口；受保护文件下载含admin+角色；本人退出走当前header |
| `crmeb/app/adminapi/middleware/AdminAuthTokenMiddleware.php:41`；`app/services/system/admin/AdminAuthServices.php:44–70` | header→AuthTokenV2 verify及当前DB账号凭据/状态；没有GET token fallback |
| `crmeb/app/outapi/route/route.php:21–23,26–102`；`outapi/controller/Login.php:64–92` | access_token、refresh_token、全部JWT业务分组及独立MCP入口 |
| `crmeb/app/outapi/controller/Mcp.php:30–79` | 覆盖initialize，不走JWT中间件；account/password核对当前appid、appsecret及启用/删除状态 |
| `crmeb/crmeb/services/workerman/WorkermanHandle.php:29–57`；`crmeb/crmeb/services/workerman/WorkermanService.php:59–81`（原快照） | notice登录消息data为admin令牌，成功后缓存adminInfo；原onMessage完整res打印已在ec2c84b7单独移除，连接缓存卸载仍待 |
| `crmeb/crmeb/services/workerman/chat/ChatHandle.php:63–118`；`crmeb/crmeb/services/workerman/chat/ChatService.php:107–118`（原快照） | kefu/user使用各自parseToken；原完整res打印已在ec2c84b7移除，不据此声称全部worker日志脱敏 |
| `crmeb/app/common.php:78–83`；`crmeb/config/workerman.php`；`crmeb/crmeb/command/Workerman.php:59–61` | 默认notice/msg地址、admin/chat/channel配置与命令管理；生产代理和进程范围未知 |
| `crmeb/app/adminapi/controller/PublicController.php:30–42`；`crmeb/crmeb/services/CacheService.php:84,124` | 文件key从route/标量query取得，原生确认写入及成功原子消费；不代替admin/角色认证 |
| `crmeb/app/adminapi/controller/v1/setting/SystemCrud.php:705–710`；`crmeb/crmeb/services/MysqlBackupService.php:276`；两Vue与`template/admin/src/libs/download.js` | 两真实文件producer及当前header下载客户端；别的主题/扫码/升级下载能力未自动归入此协议 |
| `crmeb/app/adminapi/route/export.php`；`crmeb/app/adminapi/route/system.php:121,127`；`crmeb/app/services/diy/ThemeServices.php:864`；`template/admin/src/pages/setting/theme/myTheme/index.vue:212–219` | 其他导出/包接口走管理API边界，主题下载返回public目录静态归档URL；实际消费者/代理控制未知，不能用本轮两个下载正常结果覆盖 |
| `crmeb/app/adminapi/controller/v1/system/SystemFile.php:60–71`；`app/services/system/log/SystemFileServices.php:57–110`；`AdminEditorTokenMiddleware.php:32` | file_edit用filesystem.password及缓存JWT句柄，外层仍admin+role；与admin.file_pwd字段方法不是同一流程 |
| `crmeb/app/adminapi/controller/v1/file/SystemAttachment.php:155`；`PublicController.php:64–78` | 独立scan_upload缓存/uploadToken比对，协议保留 |
| `crmeb/config/auth.php`；`AuthConfigGuard.php:32–60`；`vendor/topthink/framework/src/think/Env.php:64–99` | v2配置纯验证、独立stamp、实际PHP_环境映射；切换需真实进程加载核对 |

源码中两个worker的完整消息stdout打印，已由根在ec2c84b7仅删除原两条调用，每文件numstat均0insert/1delete、PHP8.2 lint0。收据 `H:/天俪商城/artifacts/ms0b-task9/raw-message-removal-summary.json` SHA256为 `09fa06ec699d75432a320f950ab1a718efaceac3152bb4dfed538c1b2f01074b`。没有运行有效/错误令牌消息、没有读取生产日志，不推断真实泄露事件或历史无泄露；该静态修复不能证明全部日志已脱敏，也不能把worker正常连接、实际Config重载或缓存adminInfo卸载记为通过。

顾客api、客服kefu、扫码上传、file_edit及MCP的原协议保持各自边界。后续merchantapi不在当前两个v2 app绑定中，MS1接入时须更新本清单。尚未通知调用方、部署、切换、签收或执行本表拟定验证；用户跳过的错误凭据、验证码绕过、旧/URL JWT拒绝、重复消费、安全综合、故障、并发/断连及恢复检查继续NOT RUN。请与 `cutover-plan.md` 一起复核，真实盘点与用户通知完成前不能据此发布。
