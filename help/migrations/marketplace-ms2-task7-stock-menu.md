# 平台商品独立库存菜单增量

源码候选尚未运行/接入。此入口只新增实际 `POST marketplace/products/<id>/stock` 的一项 `marketplace-products-stock-adjust`，与原三审核菜单增量、原MS1/MS2四命令分开；不修改已有role权限、不启用marketplace、不猜self身份。

使用原MS1私有六字段配置文件：

```sh
php help/migrations/marketplace-ms2-task7-stock-menu.php check --config-file=/private/migrator.json
php help/migrations/marketplace-ms2-task7-stock-menu.php apply --config-file=/private/migrator.json
php help/migrations/marketplace-ms2-task7-stock-menu.php check --config-file=/private/migrator.json
```

check只读；apply使用该专用迁移身份、命名锁和实际数据库事务，严格检查原菜单结构、实际rule和marker冲突，只插入缺失的一行，记录真实insertID并验证重复不变。输出有限状态/ID/源码hash，不含连接串、凭据或菜单原行。缺失/冲突/未完整postcheck均返回失败，不自动重建已有menu。

平台管理员需另行取得这项真实当前grant。旧content/save权限不会自动授库存能力。数量及未售卡密动作由新库存服务真实server count和同PDO专用十列审计处理，不冒充merchant employee或写旧十五列ledger；卡密、订单/已发内容不进入有限收据。正常验证及停止项均尚未运行。
