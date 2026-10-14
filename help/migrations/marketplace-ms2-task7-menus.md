# Task7 商品平台权限菜单增量

此入口只负责三个菜单叶子，不执行 MS1/MS2、历史归属迁移或角色授权。源码候选未解析、未运行；当前只是809来源的外部准备，最终集成和正常ISO须重新绑定实际新HEAD及before/manifest。

默认 `check` 只读：使用原M1六字段私有JSON配置或本地 `.env` 读取连接，核当前菜单schema及三tuple的完整匹配。`apply` 先命名锁，再真实PDO事务重新检查并只INSERT缺项；已有行完整一致时原样返回，不更新roles或旧七菜单。

在以后授权的新隔离实例中，执行者可使用仓库相对入口：

```text
php help/migrations/marketplace-ms2-task7-menus.php check --config-file=/private/task7/config.json
php help/migrations/marketplace-ms2-task7-menus.php apply --config-file=/private/task7/config.json
php help/migrations/marketplace-ms2-task7-menus.php apply --config-file=/private/task7/config.json
```

这里的私有路径是运行时输入示例，不能复制作者artifact路径当运行来源。配置仅包含原M1的 `hostname,hostport,database,username,password,prefix`，不存入仓库、响应或审计。失败只输出有限分类；lock-release失败的收据明确已经观察到的commit，不能假称商品/菜单回滚。schema/tuple冲突不自动修改既有行。

三权限为 `marketplace-products-list`、`marketplace-products-review`、`marketplace-products-off-shelf`。pid=0，auth_type=2；有权限管理员的新scope仍要求当前实际唯一menu和当前DB角色授权。新增菜单不会自动给真实角色授权，也不绕过当前最高管理员的实际登录和新menu登记。

CLI不接第二套Redis凭据或清全cache。新的实际 `PlatformAccessServices::scopeForRequest` 直接复核当前DB grants，在合法scope产出后精确删除 `all_auth` 和当前roles的既有MD5键；这是其已存在的真实失效producer。旧订单优惠券、库存、分类、公共tag及其它缓存/绝对expiry保持。`check`及正常重复 `apply` 没有框架缓存预热。

原M1/M2文件及其已交付菜单/四命令/PASS记录不变。这个CLI和三API不能替代旧商品跨商家写适配、D13完整验收、Task8发布/交易消费者或完整MS2。实际语法、菜单重复保持、正常业务和全部原停止项仍待对应授权与验证，不在本说明中填写通过。
