<?php
declare(strict_types=1);

use Audit\Migration\MarketplaceMs1Migration;

require_once __DIR__ . '/marketplace-ms1.php';

/** Additive structure only. No framework, historical mapping or business writes. */
final class MarketplaceMs4Migration
{
    public const MARKER = 'marketplace-ms4-structure-v1';
    private bool $writeAttempted = false;
    private array $applied = [];
    private string $database;

    public function __construct(private PDO $pdo, private string $prefix, string $database)
    {
        self::quote($database);
        foreach (array_keys(self::newTables() + self::additions()) as $name) self::quote($prefix . $name);
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || PHP_INT_SIZE < 8
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || !$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)
            || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Migration configuration unavailable');
        }
        $this->database = $database;
    }

    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset');
    }

    public static function newTables(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null): array => self::column($type, $comment, $null, $default, $charset);
        $tables = [
            'marketplace_order_idempotency' => [
                'columns' => [
                    'uid' => $c('int unsigned', '真实顾客用户编号'),
                    'mer_id' => $c('int unsigned', '首次核验的经营商家编号'),
                    'idem_key' => $c('varchar(128)', '顾客逻辑提交幂等键', false, null, 'ascii'),
                    'request_digest' => $c('binary(32)', '规范请求的SHA256原始摘要'),
                    'confirmation_ref' => $c('binary(32)', '真实确认键原字节的SHA256引用'),
                    'source_kind' => $c('varchar(16)', '真实购物车来源persistent或temporary', false, null, 'ascii'),
                    'cart_refs' => $c('json', '规范真实购物车引用字符串数组'),
                    'state' => $c('tinyint unsigned', '0仅事务内创建占位1已创建', false, '0'),
                    'order_id' => $c('int unsigned', '已创建订单的真实主键', true),
                    'post_commit_done' => $c('tinyint unsigned', '持久创建收尾是否完成', false, '0'),
                    'cancel_at' => $c('int unsigned', '创建时登记的未付取消期限'),
                    'add_time' => $c('int unsigned', '服务端创建时间'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['uid', 'idem_key']],
                    'uq_ms4_uid_confirmation' => [true, ['uid', 'confirmation_ref']],
                    'uq_ms4_created_order' => [true, ['order_id']],
                    'idx_ms4_finalize' => [false, ['post_commit_done', 'order_id']],
                    'idx_ms4_cancel' => [false, ['state', 'cancel_at', 'order_id']],
                ],
            ],
            'marketplace_stock_movement' => [
                'columns' => [
                    'id' => $c('bigint unsigned', '真实库存流水主键'),
                    'mer_id' => $c('int unsigned', '订单经营商家编号'),
                    'order_id' => $c('int unsigned', '实际动作订单主键'),
                    'product_id' => $c('mediumint', '原普通商品根主键'),
                    'sku_id' => $c('int', '原规格值物理主键'),
                    'sku_type' => $c('tinyint unsigned', '原规格类型首版普通商品为0'),
                    'sku_unique' => $c('char(8)', '单商品内原规格唯一值', false, null, 'ascii'),
                    'qty' => $c('int unsigned', '本次真实库存动作正数量'),
                    'kind' => $c('varchar(16)', 'reserve或release或return_in', false, null, 'ascii'),
                    'idem_key' => $c('varchar(128)', '服务端派生的唯一库存动作引用', false, null, 'ascii'),
                    'parent_reserve_id' => $c('bigint unsigned', '释放或退件所属原预留流水', true),
                    'order_item_id' => $c('int', '实际订单项主键退件必填', true),
                    'refund_id' => $c('int', '实际售后申请主键退件必填', true),
                    'actor_type' => $c('varchar(16)', '实际动作演员类型', false, null, 'ascii'),
                    'actor_id' => $c('int unsigned', '实际顾客管理员或员工编号系统任务为空', true),
                    'request_digest' => $c('binary(32)', '规范真实库存动作的SHA256原始摘要'),
                    'sku_stock_before' => $c('int unsigned', '实际规格库存动作前值'),
                    'sku_stock_after' => $c('int unsigned', '实际规格库存动作后值'),
                    'sku_sales_before' => $c('int unsigned', '实际规格销量动作前值'),
                    'sku_sales_after' => $c('int unsigned', '实际规格销量动作后值'),
                    'root_stock_before' => $c('mediumint unsigned', '实际商品根库存动作前值'),
                    'root_stock_after' => $c('mediumint unsigned', '实际商品根库存动作后值'),
                    'root_sales_before' => $c('mediumint unsigned', '实际商品根销量动作前值'),
                    'root_sales_after' => $c('mediumint unsigned', '实际商品根销量动作后值'),
                    'add_time' => $c('int unsigned', '服务端动作提交时间'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_ms4_stock_action' => [true, ['idem_key']],
                    'idx_ms4_order_sku' => [false, ['order_id', 'sku_id']],
                    'idx_ms4_parent_kind' => [false, ['parent_reserve_id', 'kind']],
                    'idx_ms4_refund_item_kind' => [false, ['refund_id', 'order_item_id', 'kind']],
                    'idx_ms4_product_sku' => [false, ['product_id', 'sku_id', 'id']],
                ],
            ],
        ];
        return $tables;
    }

    public static function additions(): array
    {
        return [
            'store_order' => ['columns' => [
                'marketplace_trade_version' => self::column('tinyint unsigned', '多商户交易合同版本旧单0或空新单1', true, '0'),
                'merchant_name_snapshot' => self::column('varchar(128)', '创建时的真实店铺名称快照', true, null, 'utf8mb4'),
                'merchant_after_sale_contact_snapshot' => self::column('varchar(255)', '创建时的真实店铺售后联系方式快照', true, null, 'utf8mb4'),
            ], 'indexes' => ['idx_ms4_order_owner' => [false, ['mer_id', 'id']]]],
            'store_order_cart_info' => ['columns' => ['mer_id' => self::column('int unsigned', '随原订单的经营商家编号', true)],
                'indexes' => ['idx_ms4_item_owner' => [false, ['mer_id', 'oid', 'id']]]],
            'store_order_refund' => ['columns' => [
                'mer_id' => self::column('int unsigned', '随原订单的经营商家编号', true),
                'marketplace_refund_mode' => self::column('varchar(16)', '申请时固定的money_only或return_goods模式', true, null, 'ascii'),
            ], 'indexes' => ['idx_ms4_refund_owner' => [false, ['mer_id', 'store_order_id', 'id']]]],
            'store_product_reply' => ['columns' => ['mer_id' => self::column('int unsigned', '随原订单商品的经营商家编号', true)],
                'indexes' => ['idx_ms4_reply_owner' => [false, ['mer_id', 'id']]]],
        ];
    }

    public function check(): array
    {
        $this->writeAttempted = false;
        $this->applied = [];
        try { return $this->receipt('check', $this->inspect()); }
        catch (Throwable $error) { return $this->failure('check', 'inspection_failed'); }
    }

    public function applyStructure(): array
    {
        $this->writeAttempted = false;
        $this->applied = [];
        $locked = false;
        $phase = 'precheck_failed';
        $name = 'marketplace-ms4:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
        try {
            $view = $this->inspect();
            if ($view['issues'] !== []) return $this->receipt('apply-structure', $view);
            $phase = 'lock_failed';
            if ((string) $this->scalar('SELECT GET_LOCK(?,10)', [$name]) !== '1') throw new RuntimeException('Migration lock unavailable');
            $locked = true;
            $phase = 'locked_recheck_failed';
            $view = $this->inspect();
            if ($view['issues'] !== []) $result = $this->receipt('apply-structure', $view);
            else {
                foreach ($view['steps'] as $step) {
                    $phase = 'structure_apply_failed';
                    $this->writeAttempted = true;
                    if ($this->pdo->exec($step['sql']) === false) throw new RuntimeException('Structure apply failed');
                    $this->applied[] = $step['code'];
                }
                $phase = 'postcheck_failed';
                $after = $this->inspect();
                if ($after['issues'] !== [] || $after['steps'] !== []) throw new RuntimeException('Structure postcheck failed');
                $result = $this->receipt('apply-structure', $after);
            }
        } catch (Throwable $error) {
            $result = $this->failure('apply-structure', $phase);
        } finally {
            if ($locked) {
                try {
                    if ((string) $this->scalar('SELECT RELEASE_LOCK(?)', [$name]) !== '1') throw new RuntimeException('Migration lock release failed');
                } catch (Throwable $error) {
                    $result = $this->failure('apply-structure', 'lock_release_failed');
                }
            }
        }
        return $result;
    }

    private function inspect(): array
    {
        $issues = [];
        $steps = [];
        if ($this->pdo->inTransaction()) $issues[] = self::issue('active_transaction', 'migration');
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) $issues[] = self::issue('database_changed', 'migration');
        $version = $this->scalar('SELECT VERSION()');
        if (!is_string($version) || preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) !== 1
            || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            return ['issues' => [self::issue('mysql_version', 'migration')], 'steps' => []];
        }
        $modes = $this->rows('SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode');
        foreach (['session_mode', 'global_mode'] as $key) {
            $flags = explode(',', strtoupper((string) ($modes[0][$key] ?? '')));
            if (!in_array('STRICT_TRANS_TABLES', $flags, true) && !in_array('STRICT_ALL_TABLES', $flags, true)) $issues[] = self::issue('strict_sql_mode', $key);
        }
        if ((new MarketplaceMs1Migration($this->pdo, $this->prefix))->check()['state'] !== 'completed') $issues[] = self::issue('ms1_schema_not_completed', 'migration');
        foreach ($this->baseContracts() as $name => $columns) {
            $meta = $this->metadata($name);
            if (!$this->baseTable($meta)) { $issues[] = self::issue('base_table', $name); continue; }
            foreach ($columns as $column => [$type, $nullable, $default]) {
                $want = self::column($type, '', $nullable, $default, preg_match('/\A(?:char|varchar)\(/', $type) === 1 ? 'utf8mb4' : null);
                $wantedCollation = $want['charset'] === null ? null : $meta['table']['TABLE_COLLATION'];
                if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $want, $wantedCollation, $column === 'id')) $issues[] = self::issue('base_column', $name . '.' . $column);
            }
            if (!$this->sameIndex($meta['indexes']['PRIMARY'] ?? [], [true, ['id']])) $issues[] = self::issue('base_primary', $name);
            $required = $name === 'store_order' ? [[true, ['unique']], [true, ['order_id', 'uid']]]
                : (in_array($name, ['store_order_cart_info', 'store_product_reply'], true) ? [[true, ['oid', 'unique']]] : []);
            foreach ($required as $index) if (!$this->hasIndex($meta, $index)) $issues[] = self::issue('base_unique', $name);
        }
        $this->checkMs2($issues);
        foreach (self::newTables() as $name => $definition) {
            $meta = $this->metadata($name);
            if ($meta === null) { $steps[] = ['code' => $name, 'sql' => $this->tableDdl($name, $definition)]; continue; }
            if (!$this->sameNewTable($meta, $definition)) $issues[] = self::issue('new_table_conflict', $name);
        }
        foreach (self::additions() as $name => $definition) {
            $meta = $this->metadata($name);
            if (!$this->baseTable($meta)) continue;
            foreach ($definition['columns'] as $column => $contract) {
                $collation = $contract['charset'] === 'ascii' ? 'ascii_bin' : ($contract['charset'] === 'utf8mb4' ? $meta['table']['TABLE_COLLATION'] : null);
                if (!isset($meta['columns'][$column])) {
                    $steps[] = ['code' => $name . '.' . $column, 'sql' => 'ALTER TABLE ' . $this->table($name) . ' ADD COLUMN ' . self::quote($column) . ' ' . $this->columnSql($contract, $collation)];
                } elseif (!$this->sameColumn($meta['columns'][$column], $contract, $collation, false, true)) $issues[] = self::issue('column_conflict', $name . '.' . $column);
            }
            foreach ($definition['indexes'] as $index => $contract) {
                if (isset($meta['indexes'][$index]) && !$this->sameIndex($meta['indexes'][$index], $contract)) { $issues[] = self::issue('index_conflict', $name . '.' . $index); continue; }
                $matching = false;
                foreach ($meta['indexes'] as $actual) if ($this->sameIndex($actual, $contract)) $matching = true;
                if (!$matching) $steps[] = ['code' => $name . '.' . $index, 'sql' => 'ALTER TABLE ' . $this->table($name) . ' ADD ' . $this->indexSql($index, $contract)];
            }
        }
        return compact('issues', 'steps');
    }

    private function baseContracts(): array
    {
        return [
            'store_order' => ['id' => ['int unsigned', false, null], 'uid' => ['int unsigned', false, '0'], 'mer_id' => ['int unsigned', false, '0'], 'pid' => ['int', false, '0'], 'unique' => ['char(32)', false, ''], 'pay_price' => ['decimal(12,2) unsigned', false, '0.00']],
            'store_order_cart_info' => ['id' => ['int', false, null], 'oid' => ['int', false, '0'], 'uid' => ['int', false, '0'], 'product_id' => ['int', false, '0'], 'cart_id' => ['varchar(50)', false, '0'], 'cart_info' => ['text', true, null], 'unique' => ['char(32)', false, '']],
            'store_order_refund' => ['id' => ['int', false, null], 'store_order_id' => ['int', false, '0'], 'uid' => ['int', false, '0'], 'cart_info' => ['longtext', true, null], 'refund_type' => ['tinyint', false, '0']],
            'store_product_reply' => ['id' => ['int', false, null], 'oid' => ['int', false, '0'], 'uid' => ['int', false, '0'], 'product_id' => ['int', false, '0'], 'unique' => ['char(32)', false, '']],
        ];
    }

    private function checkMs2(array &$issues): void
    {
        $roots = ['store_product' => ['id' => ['mediumint', false, null], 'stock' => ['mediumint unsigned', false, '0'], 'sales' => ['mediumint unsigned', false, '0'], 'review_status' => ['tinyint unsigned', false, '0'], 'review_reason' => ['varchar(512)', false, ''], 'review_admin_id' => ['smallint unsigned', true, null], 'review_time' => ['int unsigned', true, null]],
            'store_product_attr_value' => ['id' => ['int', false, null], 'product_id' => ['int unsigned', false, '0'], 'stock' => ['int unsigned', false, '0'], 'sales' => ['int unsigned', false, '0'], 'unique' => ['char(8)', false, ''], 'type' => ['tinyint', false, '0']],
            'system_attachment' => ['att_id' => ['int', false, null]], 'system_attachment_category' => ['id' => ['int', false, null]],
            'shipping_templates' => ['id' => ['int', false, null]], 'store_product_rule' => ['id' => ['int', false, null]]];
        foreach ($roots as $name => $columns) {
            $meta = $this->metadata($name);
            if (!$this->baseTable($meta)) { $issues[] = self::issue('ms2_base_table', $name); continue; }
            foreach ($columns as $column => [$type, $nullable, $default]) {
                $charset = preg_match('/\A(?:char|varchar)\(/', $type) === 1 ? 'utf8mb4' : null;
                if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], self::column($type, '', $nullable, $default, $charset), $charset ? $meta['table']['TABLE_COLLATION'] : null, in_array($column, ['id', 'att_id'], true))) $issues[] = self::issue('ms2_column', $name . '.' . $column);
            }
            $pk = $name === 'system_attachment' ? 'att_id' : 'id';
            if (!$this->sameIndex($meta['indexes']['PRIMARY'] ?? [], [true, [$pk]])) $issues[] = self::issue('ms2_primary', $name);
            if ($name === 'store_product_attr_value') continue;
            $owner = $meta['columns']['mer_id'] ?? null;
            $allowed = $name === 'store_product' ? [[false, '0'], [false, null]] : [[true, null], [false, null]];
            $valid = false;
            foreach ($allowed as [$null, $default]) if ($owner !== null && $this->sameColumn($owner, self::column('int unsigned', '', $null, $default), null)) $valid = true;
            if (!$valid) $issues[] = self::issue('ms2_owner', $name . '.mer_id');
            $index = [false, ['mer_id', $pk]];
            $found = false;
            foreach ($meta['indexes'] as $actual) if ($this->sameIndex($actual, $index)) $found = true;
            if (!$found) $issues[] = self::issue('ms2_owner_index', $name);
            if ($name === 'store_product' && !$this->hasIndex($meta, [false, ['review_status', 'is_show', 'is_del', 'mer_id', 'id']])) $issues[] = self::issue('ms2_review_index', $name);
        }
        $stock = $this->metadata('marketplace_stock_log');
        if (!$this->baseTable($stock) || $stock['table']['TABLE_COMMENT'] !== 'marketplace-ms2-structure-v1' || $stock['table']['TABLE_COLLATION'] !== 'utf8mb4_bin') $issues[] = self::issue('ms2_stock_log', 'marketplace_stock_log');
        else {
            $columns = ['id' => ['bigint unsigned', false, null], 'mer_id' => ['int unsigned', false, null], 'actor_employee_id' => ['int unsigned', false, null], 'product_id' => ['mediumint', false, null], 'attr_value_id' => ['int', false, null], 'sku_unique' => ['char(8)', false, null], 'action' => ['varchar(16)', false, null], 'delta' => ['bigint', false, null], 'stock_before' => ['int unsigned', false, null], 'stock_after' => ['int unsigned', false, null], 'root_stock_before' => ['mediumint unsigned', false, null], 'root_stock_after' => ['mediumint unsigned', false, null], 'reason' => ['varchar(255)', false, null], 'request_id' => ['varchar(64)', false, null], 'add_time' => ['int unsigned', false, null]];
            if (array_keys($stock['columns']) !== array_keys($columns)) $issues[] = self::issue('ms2_stock_columns', 'marketplace_stock_log');
            foreach ($columns as $column => [$type, $null, $default]) {
                $charset = preg_match('/\A(?:char|varchar)\(/', $type) === 1 ? 'utf8mb4' : null;
                if (!isset($stock['columns'][$column]) || !$this->sameColumn($stock['columns'][$column], self::column($type, '', $null, $default, $charset), $charset ? 'utf8mb4_bin' : null, $column === 'id')) $issues[] = self::issue('ms2_stock_column', $column);
            }
            $indexes = ['PRIMARY' => [true, ['id']], 'idx_mer_stock_id' => [false, ['mer_id', 'id']], 'idx_product_stock_id' => [false, ['product_id', 'id']], 'idx_mer_stock_actor' => [false, ['mer_id', 'actor_employee_id']]];
            ksort($indexes, SORT_STRING);
            if (array_keys($stock['indexes']) !== array_keys($indexes)) $issues[] = self::issue('ms2_stock_indexes', 'marketplace_stock_log');
            foreach ($indexes as $index => $contract) if (!$this->sameIndex($stock['indexes'][$index] ?? [], $contract)) $issues[] = self::issue('ms2_stock_index', $index);
            $foreign = $this->rows('SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$this->database, $this->prefix . 'marketplace_stock_log']);
            $wanted = [[$this->prefix . 'mm2_stock_employee_fk', 'marketplace_employee', ['mer_id', 'actor_employee_id'], ['mer_id', 'id']], [$this->prefix . 'mm2_stock_merchant_fk', 'marketplace_merchant', ['mer_id'], ['mer_id']]];
            $expected = [];
            foreach ($wanted as [$constraint, $target, $local, $reference]) foreach ($local as $i => $column) $expected[] = ['CONSTRAINT_NAME' => $constraint, 'ORDINAL_POSITION' => (string) ($i + 1), 'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $this->database, 'REFERENCED_TABLE_NAME' => $this->prefix . $target, 'REFERENCED_COLUMN_NAME' => $reference[$i], 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT', 'MATCH_OPTION' => 'NONE'];
            $foreign = array_map(static fn(array $row): array => array_map('strval', $row), $foreign);
            if ($foreign !== $expected) $issues[] = self::issue('ms2_stock_foreign', 'marketplace_stock_log');
            $checks = $this->rows("SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK'", [$this->database, $this->prefix . 'marketplace_stock_log']);
            if (count($checks) !== 1 || $checks[0]['CONSTRAINT_NAME'] !== $this->prefix . 'mm2_stock_positive' || $checks[0]['ENFORCED'] !== 'YES' || !self::stockCheck($checks[0]['CHECK_CLAUSE'])) $issues[] = self::issue('ms2_stock_check', 'marketplace_stock_log');
        }
    }

    /** The existing MS2 contract is exactly two positive comparisons joined by AND. */
    private static function stockCheck(string $source): bool
    {
        $source = preg_replace('/[`\s]+/', '', strtolower($source));
        if (strlen($source) > 512) return false;
        preg_match_all('/actor_employee_id|mer_id|and|[0-9]+|[()>]/', $source, $matches);
        $tokens = $matches[0]; if (implode('', $tokens) !== $source) return false;
        $offset = 0; $parse = null;
        $term = static function (int $depth) use (&$parse, &$offset, $tokens): array {
            if ($depth > 8) throw new RuntimeException('Metadata unavailable');
            if (($tokens[$offset] ?? null) === '(') { ++$offset; $node = $parse($depth + 1); if (($tokens[$offset++] ?? null) !== ')') throw new RuntimeException('Metadata unavailable'); return $node; }
            $column = $tokens[$offset++] ?? null;
            if (!in_array($column, ['mer_id', 'actor_employee_id'], true) || ($tokens[$offset++] ?? null) !== '>' || ($tokens[$offset++] ?? null) !== '0') throw new RuntimeException('Metadata unavailable');
            return ['>', $column, '0'];
        };
        $parse = static function (int $depth) use (&$offset, $tokens, $term): array {
            $node = $term($depth);
            while (($tokens[$offset] ?? null) === 'and') { ++$offset; $node = ['and', $node, $term($depth)]; }
            return $node;
        };
        try { $tree = $parse(0); return $offset === count($tokens) && $tree === ['and', ['>', 'mer_id', '0'], ['>', 'actor_employee_id', '0']]; }
        catch (Throwable $error) { return false; }
    }

    private function hasIndex(array $metadata, array $wanted): bool
    {
        foreach ($metadata['indexes'] as $index) if ($this->sameIndex($index, $wanted)) return true;
        return false;
    }

    private function baseTable(?array $meta): bool
    {
        return $meta !== null && $meta['table']['TABLE_TYPE'] === 'BASE TABLE' && strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') === 0
            && preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', (string) $meta['table']['TABLE_COLLATION']) === 1 && $meta['triggers'] === [];
    }

    private function sameNewTable(array $meta, array $contract): bool
    {
        if (!$this->baseTable($meta) || $meta['table']['TABLE_COLLATION'] !== 'utf8mb4_bin' || $meta['table']['TABLE_COMMENT'] !== self::MARKER
            || array_keys($meta['columns']) !== array_keys($contract['columns']) || $meta['foreign'] !== [] || $meta['checks'] !== []) return false;
        foreach ($contract['columns'] as $name => $column) {
            $collation = $column['charset'] === 'ascii' ? 'ascii_bin' : ($column['charset'] === 'utf8mb4' ? 'utf8mb4_bin' : null);
            if (!$this->sameColumn($meta['columns'][$name], $column, $collation, $name === 'id', true)) return false;
        }
        $expected = $contract['indexes']; ksort($expected, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($expected)) return false;
        foreach ($expected as $name => $index) if (!$this->sameIndex($meta['indexes'][$name], $index)) return false;
        return true;
    }

    private function sameColumn(array $actual, array $expected, ?string $collation, bool $auto = false, bool $comment = false): bool
    {
        $charset = $expected['charset'];
        if (in_array($expected['type'], ['text', 'longtext'], true)) { $charset = 'utf8mb4'; $collation = $actual['COLLATION_NAME']; }
        return $actual['COLUMN_TYPE'] === $expected['type'] && $actual['IS_NULLABLE'] === ($expected['nullable'] ? 'YES' : 'NO')
            && $actual['COLUMN_DEFAULT'] === $expected['default'] && $actual['CHARACTER_SET_NAME'] === $charset
            && $actual['COLLATION_NAME'] === $collation && $actual['EXTRA'] === ($auto ? 'auto_increment' : '')
            && (string) $actual['GENERATION_EXPRESSION'] === '' && (!$comment || $actual['COLUMN_COMMENT'] === $expected['comment']);
    }

    private function sameIndex(array $actual, array $expected): bool
    {
        [$unique, $columns] = $expected;
        if (count($actual) !== count($columns)) return false;
        foreach ($actual as $i => $row) if ((int) $row['SEQ_IN_INDEX'] !== $i + 1 || (string) $row['NON_UNIQUE'] !== ($unique ? '0' : '1')
            || $row['COLUMN_NAME'] !== $columns[$i] || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE'
            || $row['IS_VISIBLE'] !== 'YES' || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A') return false;
        return true;
    }

    private function metadata(string $name): ?array
    {
        $args = [$this->database, $this->prefix . $name];
        $rows = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', $args);
        if ($rows === []) return null;
        if (count($rows) !== 1) throw new RuntimeException('Metadata unavailable');
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $column) {
            $column['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ($column['COLUMN_DEFAULT'] !== null) $column['COLUMN_DEFAULT'] = (string) $column['COLUMN_DEFAULT'];
            $columns[$column['COLUMN_NAME']] = $column;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $index) $indexes[$index['INDEX_NAME']][] = $index;
        ksort($indexes, SORT_STRING);
        return ['table' => $rows[0], 'columns' => $columns, 'indexes' => $indexes,
            'foreign' => $this->rows('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION', $args),
            'checks' => $this->rows("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK' ORDER BY CONSTRAINT_NAME", $args),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
    }

    private function columnSql(array $column, ?string $collation): string
    {
        $sql = strtoupper($column['type']);
        if ($column['charset'] !== null) $sql .= ' CHARACTER SET ' . $column['charset'] . ' COLLATE ' . $collation;
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
        if ($column['default'] !== null) $sql .= ' DEFAULT ' . $this->pdo->quote($column['default']);
        elseif ($column['nullable']) $sql .= ' DEFAULT NULL';
        return $sql . ' COMMENT ' . $this->pdo->quote($column['comment']);
    }

    private function tableDdl(string $name, array $definition): string
    {
        $parts = [];
        foreach ($definition['columns'] as $column => $contract) {
            $collation = $contract['charset'] === 'ascii' ? 'ascii_bin' : ($contract['charset'] === 'utf8mb4' ? 'utf8mb4_bin' : null);
            $parts[] = self::quote($column) . ' ' . $this->columnSql($contract, $collation) . ($column === 'id' ? ' AUTO_INCREMENT' : '');
        }
        foreach ($definition['indexes'] as $index => $contract) $parts[] = $this->indexSql($index, $contract);
        return 'CREATE TABLE ' . $this->table($name) . " (\n  " . implode(",\n  ", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='" . self::MARKER . "'";
    }

    private function indexSql(string $name, array $contract): string
    {
        return ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($contract[0] ? 'UNIQUE KEY ' : 'KEY ') . self::quote($name))
            . ' (' . implode(',', array_map([self::class, 'quote'], $contract[1])) . ')';
    }

    private function receipt(string $mode, array $view, ?string $phase = null): array
    {
        $state = $view['issues'] !== [] ? 'incompatible' : ($view['steps'] === [] ? 'completed' : 'applicable');
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $phase !== null ? 1 : ($state === 'incompatible' ? 2 : 0),
            'changed' => $this->writeAttempted, 'applied_steps' => $this->applied, 'pending_steps' => array_column($view['steps'], 'code'),
            'pending_steps_known' => $view['known'] ?? true,
            'issues' => $view['issues'], 'failure_phase' => $phase, 'marker' => self::MARKER,
            'source_sha256' => hash_file('sha256', __FILE__), 'history_mapping' => 'NOT RUN', 'trade_enabled' => false, 'acceptance' => 'NOT RUN'];
    }

    private function failure(string $mode, string $phase): array
    {
        try { $view = $this->inspect(); } catch (Throwable $error) { $view = ['issues' => [], 'steps' => [], 'known' => false]; }
        $view['issues'][] = self::issue('operation_failed', 'migration');
        return $this->receipt($mode, $view, $phase);
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement->execute($parameters)) throw new RuntimeException('Metadata read failed');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql, array $parameters = []): mixed
    {
        $rows = $this->rows($sql, $parameters);
        return $rows === [] ? null : $rows[0][array_key_first($rows[0])];
    }
    private function table(string $name): string { return self::quote($this->prefix . $name); }
    private static function issue(string $kind, string $target): array { return compact('kind', 'target'); }
    public static function quote(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1) throw new RuntimeException('Identifier unavailable');
        return '`' . $name . '`';
    }
}

function marketplaceMs4Configuration(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Configuration unavailable');
    try { $raw = stream_get_contents($handle, 65537); } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 65536) throw new RuntimeException('Configuration unavailable');
    $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $keys = ['hostname', 'hostport', 'database', 'username', 'password', 'prefix'];
    if (!is_array($config) || array_is_list($config) || array_diff(array_keys($config), $keys) !== []) throw new RuntimeException('Configuration unavailable');
    foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $key) if (!isset($config[$key]) || !is_string($config[$key])) throw new RuntimeException('Configuration unavailable');
    $port = $config['hostport'] ?? '3306';
    if ((!is_string($port) && !is_int($port)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $port) !== 1 || (int) $port > 65535
        || $config['username'] === '' || preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname']) !== 1
        || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) !== 1 || preg_match('/\A[A-Za-z0-9_]*\z/D', $config['prefix']) !== 1) throw new RuntimeException('Configuration unavailable');
    $config['hostport'] = (string) $port;
    return $config;
}

function marketplaceMs4Main(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms4.php [check|apply-structure] --config-file=PRIVATE_JSON\nDefault check is read-only. No .env, framework, business writes, mapping or feature activation. DDL can partially commit; retain the receipt and rerun exact pending additions.\n";
        return 0;
    }
    $mode = 'check'; $file = null; $selected = false; $migration = null;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply-structure'], true) && !$selected) { $mode = $argument; $selected = true; }
            elseif (str_starts_with($argument, '--config-file=') && $file === null && strlen($argument) > 14) $file = substr($argument, 14);
            else throw new RuntimeException('CLI configuration unavailable');
        }
        if ($file === null) throw new RuntimeException('Explicit private configuration required');
        $config = marketplaceMs4Configuration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs4Migration($pdo, $config['prefix'], $config['database']);
        $result = $mode === 'check' ? $migration->check() : $migration->applyStructure();
    } catch (Throwable $error) {
        // Never expose driver errors, connection strings, configuration values or private paths.
        $result = ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => false,
            'applied_steps' => [], 'pending_steps' => [], 'issues' => [['kind' => 'configuration_connection_or_lock_release_failed', 'target' => 'migration']],
            'history_mapping' => 'NOT RUN', 'trade_enabled' => false, 'acceptance' => 'NOT RUN'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return $result['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(marketplaceMs4Main(array_slice($argv, 1)));
