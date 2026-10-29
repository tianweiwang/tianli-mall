# 平台资金设置草稿迁移

仅新增资金规则草稿存储与两个权限叶，不启用真实收款、退款、结算或提现，不改订单、账户、旧配置、角色授权或旧安装 SQL。

## 执行

使用既有 MS1 私有六字段 JSON 配置：`hostname`、`hostport`、`database`、`username`、`password`、`prefix`。配置文件必须满足原加载器的私有文件校验；凭据不放命令参数或回执。

```bash
php help/migrations/marketplace-finance-settings.php check --config-file=/private/migration.json
php help/migrations/marketplace-finance-settings.php apply --config-file=/private/migration.json
php help/migrations/marketplace-finance-settings.php check --config-file=/private/migration.json
```

默认 `check` 只读。`applicable` 表示可补缺项，`completed` 表示结构、单例和权限叶完整；`incompatible` 或 `failed` 必须保留原回执并检查原因。不要删除已有表、改旧记录或清空缓存来制造“新安装”。

`apply` 仅补缺失的兼容初装对象；DDL 不可事务回滚。回执的 `progress` 保留 DDL 尝试/完成、结果未知、DML 提交、回滚和锁释放观察。失败可能已留下新表；不自动删除，后续先 `check`，只有完整兼容的缺项可再次 `apply`。已有草稿、历史和菜单逐行保持；不把自然递增的菜单编号改回旧值。

## 固定结构

- `{prefix}marketplace_finance_settings`：`id TINYINT UNSIGNED PRIMARY KEY`（唯一行 1）、`revision INT UNSIGNED`、`payload JSON`、可空 `updated_by/updated_at INT UNSIGNED`。
- `{prefix}marketplace_finance_settings_history`：`revision INT UNSIGNED PRIMARY KEY`、`payload JSON`、`changed_fields JSON`、`actor_id/created_at INT UNSIGNED`。其余字段均 NOT NULL，无自增或额外索引。
- 两表为 InnoDB、utf8mb4_bin；初始 revision=0，updated_by/updated_at=NULL，history 空。14 个 draft 字段全部为 JSON null，payload 不含 schema。HTTP 响应的 schema 另由应用提供。
- 初始字段：contracting_entity、payment_provider、payment_product、account_model、platform_fee_enabled、aftersale_hold、platform_fee_payer、channel_fee_payer、platform_fee_basis、platform_fee_rate、settlement_trigger、settlement_cycle、settlement_delay_days、refund_platform_fee。
- 权限：`marketplace-finance-settings-read`（GET）和 `marketplace-finance-settings-write`（PUT），同一路由 `marketplace/finance-settings`，均 auth_type=2、pid=0。不自动添加到已有角色。

## 安装入口与现有预览

现有 `help/preview/bootstrap.php initialize` 委派给 `help/preview/seed.php::preview_seed_migrations()`，后者已追加本迁移。已有 18198 预览须由维护入口使用原配置/PDO，仅调用本专用迁移，不能重跑 bootstrap/旧 seed。

原 `crmeb/public/install/index.php` 仍只导入旧 `crmeb.sql`，没有既有多商户迁移接缝；本次不修改旧 SQL，也不宣称原安装器已完成多商户安装。正式安装流程需在已经完成必要多商户前置迁移后显式执行上述专用 check/apply/check。

这里只是操作说明。源码和 PHP 语法检查不代表原生迁移、后台保存重入、完整 A/B 或真实资金验收通过。
