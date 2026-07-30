## Context

当前仓库同时包含完整 Apache-2.0 根许可证、README 的免费商用声明，以及 `crmeb/LICENSE.txt`、安装协议和 1242 个项目自有源码文件中的限制性声明。运行时还存在两条商业授权链：当前后台的授权查询/申请链，以及未被当前路由引用但会同步站点信息的旧升级客户端。管理端与商城端在品牌未配置时强制回退 CRMEB 文案、链接和图片；当前在线升级服务的构造函数会立即读取域名并认证，导致只进入相关控制器也可能联网。

现有 `harden-runtime-acceptance` 变更要求 `/copyright` 等商城启动 API 返回业务成功，因此不能简单删除版权接口。当前工作区还有未提交的 PHP 8.2 与运行时验收修改，本变更不得覆盖这些修改，也不得读取或提交 `.env`、`.constant`、`.codex/`。

## Goals / Non-Goals

**Goals:**

- 让软件许可声明和实际运行行为与根 Apache-2.0 规则一致。
- 删除商业授权门槛、购买入口、授权种子数据和默认外联。
- 保留来源归属、普通安全鉴权、本地品牌配置和离线升级。
- 对新安装、现有数据库和已发布静态资源执行同一合规验收。
- 保证商品、订单、用户、上传和示例数据不变。

**Non-Goals:**

- 判定上游著作权或商标争议。
- 删除第三方许可证、上游 copyright/author 归属或普通登录/角色权限。
- 绕过显式启用后的官方升级认证、签名或升级包资格。
- 删除与软件授权无关的短信、物流、支付、微信、云存储等可选服务。
- 借机升级依赖或重构商城业务。

## Decisions

### Treat Apache-2.0 as the single software license

根 `LICENSE` 保持完整。应用许可证和安装器改为 Apache-2.0，不增加保留底部链接、购买授权或限制二次开发等附加条件。项目自有源码头只替换限制性许可行，保留 CRMEB 原 copyright 和 author 行。`vendor/`、第三方资源和历史证据不做机械改写。

批量替换使用精确旧文本和显式目录白名单。测试先记录当前命中集合，实施后拒绝项目自有源码再次出现限制性文本。因文件内容变化，安装 SQL 和嵌入升级清单中仍保留文件的 MD5 必须按最终内容重生成。

### Remove authorization instead of faking success

删除 `SystemAuthServices`、`Common` 中商业授权方法及三条路由；删除管理端 API、首页自动请求、购买 iframe 和授权状态逻辑。请求旧端点得到 404。普通 `meta.auth`、`unique_auth`、管理员令牌、角色中间件和微信 OAuth 明确不在删除范围。

旧 `SystemUpgradeClient`、`UpgradeService`、`UpgradeOpenApiService` 没有当前调用者且包含域名/IP/站点信息发送和升级资格检查，直接删除并同步清单，而不是保留死代码。

### Preserve system diagnostics and menu ownership

现有 `system/auth` 页的可见主体是本地环境诊断。将目录、路由、组件、API 符号和权限码改为 `system/info` / `system_info` / `system-maintain-info`，保留环境诊断并提供只读写本地 `/copyright` 接口的站点品牌设置区，删除商业逻辑。

安装 SQL 和存量迁移都原地更新菜单 ID `605`，不删除再插入。角色规则继续引用同一 ID，避免已有角色丢失菜单。迁移后由部署步骤清理权限与菜单缓存。

### Keep the copyright endpoint as neutral site branding

`/copyright` 的 URL 和响应字段 `copyrightContext`、`copyrightImage` 保持兼容，因为已发布商城和运行时验收依赖它们。内部键改为 `site_copyright_text`、`site_copyright_image`，管理端符号改为 `getSiteBranding`、`saveSiteBranding`。PC、客服、后台和商城端统一读取新键。

存量迁移在目标键不存在时重命名旧键；目标键已存在时保留目标值并删除旧重复键。空值或接口失败时隐藏品牌区域或使用已有站点名称/Logo，不回退 CRMEB 文案、URL 或图片。

### Make official remote upgrades default-off and lazy

`config/upgrade.php` 从 `UPGRADE.OFFICIAL_REMOTE_ENABLED` 读取布尔值，默认 `false`；Docker 应用环境示例也为 `false`。官方 URL 只从配置读取并要求 HTTPS。

`UpgradeServices::__construct()` 不执行网络或读取请求域名。远程方法先通过统一 `assertOfficialRemoteEnabled()`，通过后才惰性初始化版本、域名、签名和 token。禁用错误使用“官方在线升级未启用”，配置、认证和资格错误使用各自明确文案。

增加纯本地状态端点供前端读取开关。在线升级页禁用时不调用 `upgrade_status`、列表或协议；启用后沿用官方认证，不伪造授权。远程日志同样受门禁。`crossVersion*`、备份和回滚方法不调用远程初始化，保持离线。

### Use a narrow, idempotent data migration

迁移仅操作 `system_route`、`system_config` 和 `system_menus` 的明确记录，使用项目表前缀，占用事务并可重复执行。它删除三条授权 API 元数据和 `cert_crmeb`，更新菜单 ID `605`，迁移两个品牌键。测试夹具在迁移前后计算业务表示例行的摘要，证明未触碰其他数据。

新安装 SQL 同步上述最终状态，并删除被移除文件的文件树/MD5 行、授权 API 文档和授权专用翻译。保留的文件记录按最终内容更新，而不是放弃完整性功能。

### Rebuild and prune static artifacts

管理端从 `template/admin` 构建，按入口 manifest 同步到 `crmeb/public/admin`。同步时只删除新入口不再引用的旧哈希资源，避免残留商业授权 chunk，也避免清空用户未确认的目录。

商城端使用当前可复现构建链重建；无法构建的目标必须在验证报告中明确标为未验证。最终否定清单同时扫描源码和实际发布目录，并排除 OpenSpec/设计文档、第三方许可证和保留的来源归属。

## Data Flow

### System information

`系统信息菜单(ID 605)` -> `pages/system/info` -> `GET system/info` + 本地 `/copyright` -> 环境诊断与站点品牌设置。链路中没有商业授权 API、CRMEB 商城 iframe 或远程请求。

### Site branding

管理员在系统信息页保存品牌 -> `POST copyright` -> `site_copyright_*` -> `/copyright` 兼容响应 -> 后台/客服/PC/商城按有值显示、空值隐藏。

### Official upgrade

进入在线升级页 -> 纯本地状态端点 -> `official_remote_enabled=false` 时停止；只有部署者显式启用后，远程方法才初始化认证并访问配置的 HTTPS 官方服务。本地跨版本升级始终走独立离线链路。

## Error Handling

- 远程升级关闭、配置缺失、认证失败、官方资格不足分别返回不同错误，不再使用“授权信息丢失”泛化所有情况。
- 品牌请求失败不显示 CRMEB 兜底，也不写入虚假品牌值。
- 迁移发现重复目标或数据库异常时回滚；重复执行已完成迁移时返回零变更而非失败。
- 构建或资源同步失败时保留原发布目录并报告失败，不将半构建结果描述为已发布。

## Testing

1. 先写静态合规测试，在旧代码上确认其因限制性许可、商业授权路由/UI、旧品牌键和发布 chunk 而失败。
2. 为远程升级增加可替换 HTTP 客户端或现有 HTTP 观察点，验证默认构造和禁用端点产生零请求；启用路径仍调用原认证。
3. 用最小数据库夹具验证存量迁移首次执行、重复执行、目标品牌键已存在和菜单角色 ID 保留。
4. 运行 PHP 语法、聚焦后端测试、管理端 build、可复现商城构建、OpenSpec 和现有 runtime acceptance。
5. 登录本地后台进行浏览器网络与可见性验证，覆盖首页、系统信息、品牌有值/空值、在线升级关闭和本地跨版本升级。
6. 比较变更前快照，确认商品、订单、用户、上传文件和示例数据未变化。

## Risks / Trade-offs

- [上游内嵌许可与根许可冲突可能涉及权属] -> 明确记录发行前提，保留来源归属，并建议项目所有者保存上游 Apache-2.0 发布证据。
- [1242 个源码头产生宽 diff] -> 仅精确替换一行、使用白名单脚本、输出清单并单独提交机械变更。
- [文件完整性哈希大量变化] -> 从最终工作树确定性重生成对应清单并加入一致性测试。
- [权限码改名可能受缓存影响] -> 保留菜单 ID `605`，提供缓存清理和有/无权限角色验证。
- [官方服务 HTTPS 行为可能变化] -> 默认关闭；显式启用时严格失败，不降级 HTTP。
- [旧客户端依赖版权字段] -> 保持 URL 和响应字段不变，只迁移内部键和空值行为。

## Rollback

回滚代码、静态资源、菜单路径/权限码和品牌键迁移即可。商品、订单、用户、上传和示例数据未被变更，不需要业务数据回滚。商业授权端点和限制性许可文本不作为运行所需状态恢复；若项目所有者撤销 Apache-2.0 发行决定，应先建立新的许可变更而不是直接恢复隐藏门禁。
