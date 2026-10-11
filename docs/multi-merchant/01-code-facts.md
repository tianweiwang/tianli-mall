# 代码事实与安全发现

核对日期：2026-10-11。核对方式：静态阅读源码与 git 对象，没有运行利用链。除非另注，代码位置指"master 内容 + 审计修复"（即 BASE 完成后的 `AUDIT_BASE_SHA` 内容）；行号会随修改漂移，以方法名为准。

状态说明：**已核实** = 源码或 git 对象可直接确认；**静态推断** = 由调用链推出，需在隔离环境复现；**待盘点** = 需要真实数据库或运行环境。

## 1. 仓库与基线

| 编号 | 事实 | 状态 | 影响 |
|---|---|---|---|
| F-01 | 远程默认分支为 `master@3e2a1a158`；本地只有备份分支 `codex/private-backup-20261007-tianli-crmeb@9580c8038` 及其远程跟踪，没有本地 `main` 或 `master` | 已核实 | 02 文档 |
| F-02 | 备份快照相对 master 删除了 `crmeb/vendor`（8837）、`crmeb/public`（2536）、`crmeb/runtime/readme.md`、`crmeb/backup/readme.md`、`template/admin/.env`、`.env.dev`、`.env.production`、后台 3 个导出文件 | 已核实 | 基线不能用备份快照 |
| F-03 | master 文本文件为 LF；备份快照和备份检出把约 2400 个文件改为 CRLF。`.gitattributes` 只对 `*.sh` 规定 `eol=lf` | 已核实 | 迁移时按 master 的换行符转换 |
| F-04 | 备份快照中扣除换行符后的真实内容修改共 23 项，清单与处理方式见 02 文档 §输入清单 | 已核实 | 02 文档 Task 2 |
| F-05 | 备份检出的未提交工作：26 个已跟踪修改（24 个审计修复 + `help/docker/README.md` + `help/docker/run.sh`）、23 个审计相关未跟踪文件、未跟踪的 `crmeb/vendor`、`crmeb/public`、`crmeb/runtime`；索引为空 | 已核实 | 02 文档 Task 3 |
| F-06 | `help/docker/run.sh` 的"修改"只是换行符差异：HEAD 中的 blob 为 CRLF，而 `.gitattributes` 要求 LF，内容没有变化 | 已核实 | 不迁移 |
| F-07 | 审计修复中没有混合 hunk；后端 diff 行数大，是因为代码被包进事务闭包后缩进变化，忽略空白后为 +161/−91 行 | 已核实 | 可整文件迁移 |
| F-08 | 后台 3 个导出文件（`template/admin/src/vendor/*.js`）的未跟踪版本与 master 中的 blob 完全一致 | 已核实 | 在新基线上不产生差异 |
| F-09 | master 跟踪 `crmeb/public/install/crmeb.sql`（10 MB，157 张表，含菜单等种子数据）。本地运行库同为 157 张表（+ 审计新增的 `eb_theme_module`） | 已核实 | 测试夹具的结构来源 |

## 2. 认证与会话

| 编号 | 事实 | 位置 | 状态 | 关联用例 |
|---|---|---|---|---|
| F-10 | `JwtAuth::parseToken()` 先解码**未验签**的载荷，返回 `[id, type, pwd]`；`verifyToken()` 才验签，并且把令牌暂存在实例属性 `$token` 上 | `crmeb/crmeb/utils/JwtAuth.php` | 已核实 | A20, M05 |
| F-11 | 签名密钥为 `Env::get('app.app_key', 'default')`，缺失时回退字面量 `default` | 同上 `getToken()`、`verifyToken()` | 已核实 | A10 |
| F-12 | `JwtAuth::createToken()` 把 `{uid, type, token, exp}` 写入缓存，键为 `md5(token)`、标签为 type。这就是现有的会话白名单 | 同上 | 已核实 | A18 |
| F-13 | `AdminAuthServices::parseToken()`：不检查 `type === 'admin'`；只看缓存键是否存在，不比对内容；`pwd` 声明为空时跳过密码比对；不检查管理员 `status`、`is_del` | `crmeb/app/services/system/admin/AdminAuthServices.php` | 已核实 | A11–A16 |
| F-14 | `CacheService::get()` 只有 `$name`、`$default` 两个参数；调用方传入的第 4 个参数 `'admin'`、`'out'` 被忽略，看起来像按应用隔离，实际没有 | `crmeb/crmeb/services/CacheService.php` | 已核实 | A18 |
| F-15 | `OutAccountServices::refresh()` 用 `$jwtAuth->createToken($id, $type)` 续签，令牌不含 `pwd` 声明。结合 F-13，刷新后的对外令牌若与某管理员 ID 相同，可能被管理端当作该管理员；若该管理员 `level = 0`，还会跳过动作授权 | `crmeb/app/services/out/OutAccountServices.php` | 静态推断（高危） | A12 |
| F-16 | `AdminAuthServices::authFailAfter()` 在认证失败时，用**未验签**的 id，在 `POST product/product/<id>` 请求上调用 `saveProduct()`，把请求体写入数据库缓存表 `eb_cache`，键为 `{id}_product_data`。正常的已授权草稿保存在 `StoreProduct` 控制器中另有实现 | 同上；`crmeb/app/services/other/CacheServices.php::setDbCache()`；`crmeb/app/adminapi/controller/v1/product/StoreProduct.php` | 静态推断 | A27 |
| F-17 | 后台登录只在请求**带了**验证码时才校验；失败计数 `login_captcha` 是全局键，不分账号；服务端从不强制要求验证码 | `crmeb/app/adminapi/controller/Login.php::login()` | 已核实 | Q6，MS0b 候选 |
| F-18 | 管理端中间件在请求头缺少令牌时，接受 URL 参数 `?token=` | `crmeb/app/adminapi/middleware/AdminAuthTokenMiddleware.php` | 已核实 | Q7，MS0b 候选 |
| F-19 | 对外账号状态在管理后台开关为 `1` 开启、`0` 关闭（数据库默认 `1`），但三处判断不一致：`authLogin()` 拒绝 `status == 0`；`checkAuth()`（访问与刷新）只拒绝 `status == 2`；MCP 拒绝 `0` 或 `2`。结果是**被关闭的对外账号，其已签发令牌仍可访问和刷新** | `OutAccountServices.php`、`crmeb/app/outapi/controller/Mcp.php`、`template/admin/src/pages/setting/systemOutAccount/index.vue` | 静态推断 | A19 |
| F-20 | master 中跟踪了上游演示凭据：uni-app `manifest.json` 中的地图 appkey 与微信 appsecret、后台 `smsConfig/index.vue` 中 iframe 的 token、`store/getters.js` 注释里的示例 JWT。备份快照已清理，新基线迁入清理 | master 对应文件 | 已核实 | Q3 |
| F-21 | 上游安装结构中有 `mer_id` 的表：`eb_article`、`eb_store_combination`、`eb_store_integral_order`、`eb_store_order`、`eb_store_product`、`eb_store_service`、`eb_store_service_log`、`eb_system_user_level`、`eb_user_level`，默认值都是 `0`；`eb_system_log` 上另有 `merchant_id` 列 | `crmeb/public/install/crmeb.sql` | 已核实（安装结构）；真实库待盘点 | G04, G05 |
| F-22 | `StoreProduct::searchMerIdAttr()` 写的是 `where('mer_id', $value ?? 0)`；商品详情响应把顶层 `mer_id` 写死为 `0` | `crmeb/app/model/product/product/StoreProduct.php`；`StoreProductServices.php` 详情方法 | 已核实 | D15, D16 |
| F-23 | MCP 入口不经过 JWT 中间件，直接用请求头 `account`/`password` 校验 `eb_out_account` | `crmeb/app/outapi/controller/Mcp.php` | 已核实 | Z08 |
| F-24 | outapi 的 `verifyToken()` 检查 `type == 'out'`，但同样先用缓存键存在与否判断白名单，不比对内容 | `OutAccountServices::verifyToken()` | 已核实 | A18 |
| F-25 | 运行配置 `crmeb/.env` 与 `help/docker/.env.application.example` 的缓存驱动为 `redis`；但 `config/cache.php` 的默认值是 `Env::get('cache.driver', 'file')`，环境变量缺失时会悄悄变成文件缓存 | `crmeb/config/cache.php` | 已核实 | A10 |
| F-26 | 客服端 `kefu\LoginServices::parseToken()` 与顾客端 `UserAuthServices::parseToken()` 都**不检查 type**，也不比对缓存记录内容：客服端只看缓存键存在后按 id 取客服；顾客端按 `uid` 取启用的用户。后台、对外或客服令牌只要数字 ID 撞上，就可能被当作该客服或顾客。反方向（客服/顾客令牌访问管理端）因管理端的 `pwd` 比对而被拒绝（签发时 `pwd = md5('')`） | `crmeb/app/services/kefu/LoginServices.php`、`crmeb/app/services/user/UserAuthServices.php`、`crmeb/app/services/BaseServices.php::createToken()` | 静态推断（高危） | A09 |
| F-27 | 代码编辑器另有一套令牌（`SystemFileServices::parseToken()`，type 为文件管理用途，id 为密码摘要），不在 A09 矩阵中，MS0a 只记录不改 | `crmeb/app/services/system/log/SystemFileServices.php` | 已核实 | A09 备注 |
| F-28 | JWT 库为 `firebase/php-jwt v5.0.0`，`JWT::decode($jwt, $key, ['HS256'])` 需要显式算法白名单 | `crmeb/composer.lock` | 已核实 | A20 |

## 3. 授权

| 编号 | 事实 | 位置 | 状态 | 关联用例 |
|---|---|---|---|---|
| F-30 | `AdminCheckRoleMiddleware` 只对 `level > 0` 的管理员调用 `verifyAuth()`；`level = 0` 直接放行 | `crmeb/app/adminapi/middleware/AdminCheckRoleMiddleware.php` | 已核实 | A05 |
| F-31 | `verifyAuth()` 在已登记但无权限时抛出 `AuthException`（审计修复 A1 已改）；但"未登记的接口"和 `crud/` 前缀的接口直接放行 | `crmeb/app/services/system/admin/SystemRoleServices.php::verifyAuth()` | 已核实 | A02–A04 |
| F-32 | `crud/` 路由由 `adminapi/route/crud.php` 自动加载 `route/crud/` 目录下的生成文件，挂了认证和授权中间件 | `crmeb/app/adminapi/route/crud.php` | 已核实 | A04 |
| F-33 | `all_auth`（全部已登记接口）和 `getRolesByAuth()`（角色接口权限）用 `CacheService::remember` 永久缓存，标签为 `crmeb`。角色增删改时控制器会调用 `CacheService::clear()`；**菜单（接口登记）增删改时没有清缓存** | `SystemRoleServices.php`；`crmeb/app/adminapi/controller/v1/setting/SystemRole.php`、`SystemMenus.php` | 已核实 | A08, A30 |
| F-34 | `AuthException` 在 adminapi 中被渲染为 `app('json')->make(code ?: 400, msg)`，即 HTTP 200 加业务码。业务码 401 表示未认证 | `crmeb/app/adminapi/AdminApiExceptionHandle.php` | 已核实 | 判定规则 |

## 4. 数据访问、支付与运行

| 编号 | 事实 | 位置 | 状态 |
|---|---|---|---|
| F-40 | `BaseDao` 的 `get`、`getCount`、`be`、`getOne`、`getColumn`、`delete`、`update`、`selectModel(search=false)` 直接用模型查询，不经过 `search()` | `crmeb/app/dao/BaseDao.php` | 已核实 |
| F-41 | `PayServices::pay()` 根据全局 `sys_config('pay_wechat_type')` 等决定支付类型 | `crmeb/app/services/pay/PayServices.php` | 已核实 |
| F-42 | `config/app.php` 中 `auto_multi_app => true`，可新增 `merchantapi` 应用 | `crmeb/config/app.php` | 已核实 |
| F-43 | 旧 `adminapi/route/merchant.php` 与 `controller/v1/merchant/` 指门店、店员、核销 | 同路径 | 已核实 |
| F-44 | `app()->make()` 默认复用容器实例；在常驻进程中，`JwtAuth` 等带状态的对象会跨请求或跨任务保留 | ThinkPHP 容器 | 已核实（框架行为）；实际常驻进程待盘点 |
| F-45 | 部署声明中常驻进程（队列、Workerman、定时任务）的实际运行情况尚未盘点 | `docker/`、`help/docker/` | 待盘点（MS1 前） |

## 5. 现有测试资产

| 编号 | 文件 | 性质 | 限制 |
|---|---|---|---|
| F-50 | `help/audit-regressions/security.php` | UNIT-STUB：加载真实的权限中间件、角色服务、上传控制器，Request、Cache、DAO、上传都是内存替身；不启动应用 | 不能证明真实 Redis 或数据库行为 |
| F-51 | `commerce.php`、`wallet-admin.php`、`wallet-store.php`、`theme-module.php` | 读取 `crmeb/.env` 和 `crmeb/vendor/autoload.php`；通过 `AUDIT_DB_PASSWORD_FILE` 以 root 身份执行 `CREATE DATABASE`、`DROP DATABASE`；资金用例从现有库复制表结构 | 不得在共享或业务实例上运行；只能在 03 文档的隔离实例中运行 |
| F-52 | `diy-http.py` | 只读 HTTP，默认 `http://127.0.0.1:8011` | 指向哪个服务要显式确认 |
| F-53 | `frontend-*.cjs`、`frontend.md` | 前端配置、源码检查、构建与浏览器 mock 回归 | 与后端认证无关 |
