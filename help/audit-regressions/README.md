# 审计修复回归

使用 PHP 8.2，仓库根目录运行。安全测试使用真实控制器/中间件和内存依赖；资金及主题表测试需要本地 MySQL、已恢复的 vendor 和 `crmeb/.env`。

```sh
php help/audit-regressions/security.php
AUDIT_DB_PASSWORD_FILE=/absolute/path/to/private-password-file php help/audit-regressions/commerce.php
AUDIT_DB_PASSWORD_FILE=/absolute/path/to/private-password-file php help/audit-regressions/wallet-admin.php
AUDIT_DB_PASSWORD_FILE=/absolute/path/to/private-password-file php help/audit-regressions/wallet-store.php
AUDIT_DB_PASSWORD_FILE=/absolute/path/to/private-password-file php help/audit-regressions/theme-module.php
python3 help/audit-regressions/diy-http.py
node help/audit-regressions/frontend-regression.cjs
```

MySQL 测试使用唯一随机前缀的隔离库并在 `finally` 中清理。资金用例从现有库复制表结构，再写入虚构账户/订单；不复制或修改真实业务记录。需要建库/删库权限时，通过 `AUDIT_DB_PASSWORD_FILE` 指定本地 root 密码文件；密码不写入命令行、仓库或测试输出。不要将此变量指向不可信文件。

- `security.php`：拒绝未授权角色、保留既有角色豁免；上传凭证为空、过期、错误类型、宽松比较绕过均拒绝；有效凭证继续调用上传。
- `commerce.php`：同账户并发支付、商品/会员共享余额、同单重复请求、资金和账本失败回滚；并发充值/退款/佣金转入；优惠券计算无副作用、原子核销、有效期、归属及失败下单返券。
- `wallet-admin.php`：过时余额参数、后台增减余额和注册奖励与支付并发；数据库写入失败后余额、流水、充值记录一致回滚。
- `wallet-store.php`：移动端商家调额与支付并发、双加双减、截零/零额，以及流水和余额写入失败回滚。抽奖扣余额及现金领奖的并发、重复领取、失败回滚和父事务嵌套测试纳入 `commerce.php`。
- `theme-module.php`：真实 ThemeModule 模型/DAO/服务 CRUD，以及迁移只读检查、幂等、拒绝不兼容结构和空表回滚。
- `diy-http.py`：默认连接本地 `http://127.0.0.1:8011`，只读对照 v1/v2 默认页、显式 ID 和不存在 ID 的响应。
- 前端配置、源码检查、构建与浏览器 mock 测试见 [frontend.md](frontend.md)。

资金测试运行实际服务、DAO 和余额账本；支付渠道、外部事件/通知、佣金冻结查询等使用测试替身。这些证据证明本地数据库计算及事务行为，不等于微信支付、退款渠道、外部队列通知的完整验收。既有提交前推送不能随数据库回滚撤回，本次未改跨系统通知协议。

主题表迁移是单独的显式操作，见 [迁移说明](../migrations/README.md)。回归测试不会替真实业务库执行迁移。
