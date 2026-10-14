# 平台商品独立提交审核菜单

外部源码候选，尚未接入/执行。此CLI只新增 `PUT marketplace/products/<id>/submit-review` 的 `marketplace-products-submit-review` 单leaf；原三审核菜单CLI、独立库存菜单及原MS1/MS2入口字节保持。提交权限只允许draft0/rejected3进入pending1；不授审核审批、内容保存或库存调整能力，不自动grant角色。

沿实际MS1私有六字段配置，下面是后续授权运行用法，本次均NOT RUN：

```sh
php help/migrations/marketplace-ms2-task7-submit-menu.php check --config-file=/private/migrator.json
php help/migrations/marketplace-ms2-task7-submit-menu.php apply --config-file=/private/migrator.json
php help/migrations/marketplace-ms2-task7-submit-menu.php check --config-file=/private/migrator.json
```

check只读；apply在专用迁移身份下用有限命名锁和真实数据库事务，核原22列菜单结构、当前permission/method/rule/marker冲突，仅插缺失的一行并读真实insertID。单leaf完成计数严格为1，重复apply不更新已有菜单/role。输出有限状态、ID、源hash，不输出私有config/连接串/原菜单行。失败不自动修历史结构，不启用商城、不猜self身份。

应用管理员另需真实当前grant。新request使用原PlatformAccess fresh管理员/menu/active role来源，成功scope生产后仅删除实际all_auth/current-role MD5两个已知权限cache来源；CLI本身DB-only，不添加Redis配置、凭据或clear权限。

提交是服务器状态操作：空body、真实product id/current DB owner、原samePDO Tx、root/库存/子身份完整保持及真实admin十列审计。already pending按实际商家提交producer真正无DML/no-audit重复；approved状态不可借本入口重提交。审核通过仍需独立review权限，商家/自营生命周期审批和启售也须真实独立处理。
