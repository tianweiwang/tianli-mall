# MS0a 对外接口调用方影响（代码侧核对）

本记录仅依据当前源码与专用隔离测试。实际在用的外部调用方、启用账号和调用频率未核实（Z08）；未访问业务数据库或第三方服务，未发送通知或部署。

## JWT 入口

路由定义：`crmeb/app/outapi/route/route.php`；控制器：`app/outapi/controller/Login.php`。

| 入口 | 凭据来源 | 验证/签发路径 |
|---|---|---|
| POST /outapi/access_token | 请求体 appid、appsecret | Login::getToken → OutAccountServices::authLogin；签发 type=out |
| POST /outapi/refresh_token | 请求体 access_token | Login::refreshToken → OutAccountServices::refresh；验证旧令牌，撤销旧白名单，再签发固定 type=out |
| 受保护业务路由 | 现有配置指定的令牌请求头 | AuthTokenMiddleware → OutAccountServices::parseToken → OutInterfaceServices::verifyAuth |

受保护业务路由覆盖 category（列表/详情/增改删/显示）、product（列表/详情/增改/显示/库存）、order（列表/详情/备注/收货/物流/发货/配送）、refund（列表/详情/退款/同意/拒绝）、coupon（列表/增改状态/删除）、user_level（列表）、user（列表/详情/新增/修改/余额及积分调整）。这些入口继承同一 AuthTokenMiddleware；未改其业务权限检查。

## MCP 入口

`POST /outapi/mcp` 不挂 JWT 中间件。`Mcp::authByAppSecret()` 使用请求头 account、password，查询 appid 对应的未删除账号并验证密码。此次仅复用统一的启用状态判断；JWT 载荷、白名单及刷新行为的变化不改变 MCP 的凭据协议。拒绝仍采用原有 JSON-RPC 错误响应；不能将 MCP 的 HTTP 200 当作认证成功。

## 行为变化与兼容边界

- 仅 status=1 且 is_del=0 的对外账号可登录、访问、刷新或使用 MCP。
- 已关闭（status=0）的账号，其现存 JWT 在下一次请求或刷新时被拒绝；仅在确认白名单属于 out 应用后撤销该会话。恢复账号状态后需要重新登录。
- 验证先固定 HS256 验签，再检查 type=out、白名单 type/uid/token 与当前账号。其他应用的令牌或不匹配的白名单被拒绝，原应用会话保留。
- 认证拒绝业务码统一为 401；签名密钥或会话存储配置错误为 503。Redis 运行时宕机只保证拒绝，依赖故障分类留待 MS0b。
- set_status 仅接受路由字符串 0、1；其他值返回参数错误且不写库。正常启用/关闭操作及令牌返回字段保持原格式。

隔离证据与提交进度见 `report.md`；真实调用方核实和发布前告知仍需后续授权，当前不作为已完成项。
