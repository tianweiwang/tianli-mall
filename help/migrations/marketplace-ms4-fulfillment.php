<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace-ms5-payment.php';

/** Task6 structure only. No App, business writes, historical backfill or writer activation. */
final class MarketplaceMs4FulfillmentMigration
{
    public const MARKER = 'marketplace-ms4-fulfillment-v1';
    public const SCOPE = 'MS4_FULFILLMENT_STRUCTURE_ONLY';
    private bool $writeAttempted = false;
    private array $applied = [];
    private string $database;
    private array $sourceHashes;

    public function __construct(private PDO $pdo, private string $prefix, string $database)
    {
        self::quote($database);
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || PHP_INT_SIZE < 8
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || !$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)
            || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Migration configuration unavailable');
        }
        foreach (self::newTables() as $name => $definition) {
            self::quote($prefix . $name);
            foreach (array_keys($definition['foreign'] + $definition['checks']) as $constraint) self::quote($prefix . $constraint);
        }
        foreach (array_keys(self::producerContracts()) as $name) self::quote($prefix . $name);
        $this->database = $database;
        $this->sourceHashes = self::sourceHashes();
    }

    private static function sourceHashes(): array
    {
        $out = [];
        foreach (['marketplace-ms1.php', 'marketplace-ms4.php', 'marketplace-ms5-payment.php'] as $name) {
            $path = __DIR__ . '/' . $name;
            if (!is_file($path) || is_link($path) || !is_string($hash = hash_file('sha256', $path))) {
                throw new RuntimeException('Prerequisite source unavailable');
            }
            $out['help/migrations/' . $name] = $hash;
        }
        if (!is_file(__FILE__) || is_link(__FILE__) || !is_string($hash = hash_file('sha256', __FILE__))) {
            throw new RuntimeException('Migration source unavailable');
        }
        $out['self'] = $hash;
        return $out;
    }

    private function assertSources(): void
    {
        if (self::sourceHashes() !== $this->sourceHashes) throw new RuntimeException('Migration source changed');
    }

    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    /** R3: exact 24/30 columns, 10/10 indexes, 5/5 foreign keys and 5/4 enforced checks. */
    public static function newTables(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        return [
            'marketplace_fulfillment_operation' => [
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', false, '1'),
                    'root_order_id' => $c('int unsigned'),
                    'child_order_id' => $c('int unsigned'),
                    'uid' => $c('int unsigned'),
                    'mer_id' => $c('int unsigned'),
                    'action' => $c('varchar(16)', false, null, 'ascii'),
                    'idem_key' => $c('varchar(128)', false, null, 'ascii'),
                    'request_digest' => $c('binary(32)'),
                    'source_snapshot_digest' => $c('binary(32)'),
                    'allocation_digest' => $c('binary(32)'),
                    'request_payload' => $c('json'),
                    'result_payload' => $c('json', true),
                    'state' => $c('tinyint unsigned', false, '0'),
                    'ship_child_order_id' => $c('int unsigned', true),
                    'target_operation_id' => $c('bigint unsigned', true),
                    'target_action' => $c('varchar(16)', true, null, 'ascii'),
                    'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                    'actor_id' => $c('int unsigned', true),
                    'created_at' => $c('int unsigned'),
                    'committed_at' => $c('int unsigned', true),
                    'shipped_at' => $c('int unsigned', true),
                    'receipt_due_at' => $c('int unsigned', true),
                    'received_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_fop_request' => [true, ['root_order_id', 'action', 'idem_key']],
                    'uq_fop_ship_child' => [true, ['ship_child_order_id']],
                    'uq_fop_identity' => [true, ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                    'idx_fop_root' => [false, ['root_order_id', 'id']],
                    'idx_fop_child' => [false, ['child_order_id', 'action', 'id']],
                    'idx_fop_target' => [false, ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                    'idx_fop_owner' => [false, ['uid', 'root_order_id', 'id']],
                    'idx_fop_merchant' => [false, ['mer_id', 'root_order_id', 'id']],
                    'idx_fop_due' => [false, ['action', 'state', 'receipt_due_at', 'id']],
                ],
                'checks' => [
                    'mm4f_op_ids' => '`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `root_order_id`<>`child_order_id`',
                    'mm4f_op_state' => '(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)',
                    'mm4f_op_json' => "JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR JSON_TYPE(`result_payload`)='OBJECT')",
                    'mm4f_op_action' => "`created_at`>0 AND ((`action`='ship' AND `ship_child_order_id` IS NOT NULL AND `ship_child_order_id`=`child_order_id` AND `target_operation_id` IS NULL AND `target_action` IS NULL AND `shipped_at` IS NOT NULL AND `shipped_at`>0 AND `receipt_due_at` IS NOT NULL AND (`receipt_due_at`=0 OR `receipt_due_at`>=`shipped_at`) AND `received_at` IS NULL) OR (`action`='receive' AND `ship_child_order_id` IS NULL AND `target_operation_id` IS NOT NULL AND `target_operation_id`>0 AND `target_action` IS NOT NULL AND `target_action`='ship' AND `shipped_at` IS NULL AND `receipt_due_at` IS NULL AND `received_at` IS NOT NULL AND `received_at`>0))",
                    'mm4f_op_actor' => "(`action`='ship' AND `actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0) OR (`action`='receive' AND ((`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid`) OR (`actor_type`='system' AND `actor_id` IS NULL)))",
                ],
                'foreign' => [
                    'mm4f_op_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4f_op_child_fk' => ['store_order', ['child_order_id'], ['id'], 'PRIMARY'],
                    'mm4f_op_user_fk' => ['user', ['uid'], ['uid'], 'PRIMARY'],
                    'mm4f_op_mer_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id'], 'PRIMARY'],
                    'mm4f_op_target_fk' => ['marketplace_fulfillment_operation', ['target_operation_id', 'target_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
                ],
            ],
            'marketplace_fulfillment_range' => [
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'operation_id' => $c('bigint unsigned'),
                    'operation_action' => $c('varchar(16)', false, null, 'ascii'),
                    'root_order_id' => $c('int unsigned'),
                    'child_order_id' => $c('int unsigned'),
                    'uid' => $c('int unsigned'),
                    'mer_id' => $c('int unsigned'),
                    'source_item_id' => $c('int'),
                    'child_item_id' => $c('int'),
                    'reserve_id' => $c('bigint unsigned'),
                    'source_ship_range_id' => $c('bigint unsigned', true),
                    'source_range_action' => $c('varchar(16)', true, null, 'ascii'),
                    'product_id' => $c('mediumint'),
                    'sku_id' => $c('int'),
                    'sku_type' => $c('tinyint unsigned', false, '0'),
                    'sku_unique' => $c('char(8)', false, null, 'ascii'),
                    'cart_ref' => $c('varchar(50)', false, null, 'ascii'),
                    'original_quantity' => $c('int unsigned'),
                    'unit_start' => $c('int unsigned'),
                    'unit_end' => $c('int unsigned'),
                    'quantity' => $c('int unsigned'),
                    'unit_price' => $c('decimal(12,2) unsigned'),
                    'price_type' => $c('varchar(16)', false, null, 'ascii'),
                    'goods' => $c('decimal(12,2) unsigned'),
                    'postage' => $c('decimal(12,2) unsigned'),
                    'origin_postage' => $c('decimal(12,2) unsigned'),
                    'payable' => $c('decimal(12,2) unsigned'),
                    'cost' => $c('decimal(12,2) unsigned'),
                    'base_goods' => $c('decimal(22,2) unsigned'),
                    'discount' => $c('decimal(22,2) unsigned'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_frange_operation_start' => [true, ['operation_id', 'source_item_id', 'unit_start']],
                    'uq_frange_identity' => [true, ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
                    'idx_frange_operation' => [false, ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id']],
                    'idx_frange_occupied' => [false, ['root_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                    'idx_frange_child' => [false, ['child_order_id', 'operation_action', 'source_item_id', 'unit_start', 'id']],
                    'idx_frange_source_item' => [false, ['source_item_id', 'id']],
                    'idx_frange_child_item' => [false, ['child_item_id', 'id']],
                    'idx_frange_reserve' => [false, ['reserve_id', 'id']],
                    'idx_frange_ship_parent' => [false, ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id']],
                ],
                'checks' => [
                    'mm4f_range_ids' => '`root_order_id` BETWEEN 1 AND 2147483647 AND `child_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `source_item_id`>0 AND `child_item_id`>0 AND `reserve_id`>0 AND `product_id`>0 AND `sku_id`>0 AND `sku_type`=0',
                    'mm4f_range_units' => '`original_quantity` BETWEEN 1 AND 2147483647 AND `unit_start`<`unit_end` AND `unit_end`<=`original_quantity` AND `quantity`=`unit_end`-`unit_start`',
                    'mm4f_range_action' => "(`operation_action`='ship' AND `source_ship_range_id` IS NULL AND `source_range_action` IS NULL) OR (`operation_action`='receive' AND `source_ship_range_id` IS NOT NULL AND `source_ship_range_id`>0 AND `source_range_action` IS NOT NULL AND `source_range_action`='ship')",
                    'mm4f_range_money' => "`price_type` IN ('level','member') AND `payable`=`goods`+`postage` AND `origin_postage`=`postage`",
                ],
                'foreign' => [
                    'mm4f_range_operation_fk' => ['marketplace_fulfillment_operation', ['operation_id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], ['id', 'action', 'root_order_id', 'child_order_id', 'uid', 'mer_id'], 'uq_fop_identity'],
                    'mm4f_range_source_fk' => ['store_order_cart_info', ['source_item_id'], ['id'], 'PRIMARY'],
                    'mm4f_range_child_fk' => ['store_order_cart_info', ['child_item_id'], ['id'], 'PRIMARY'],
                    'mm4f_range_reserve_fk' => ['marketplace_stock_movement', ['reserve_id'], ['id'], 'PRIMARY'],
                    'mm4f_range_parent_fk' => ['marketplace_fulfillment_range', ['source_ship_range_id', 'source_range_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], ['id', 'operation_action', 'root_order_id', 'child_order_id', 'uid', 'mer_id', 'source_item_id'], 'uq_frange_identity'],
                ],
            ],
        ];
    }

    /** Original MS4/MS5 checks retain their full contracts; these are the additional typed FK/source prerequisites. */
    private static function producerContracts(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        $stock = MarketplaceMs4Migration::newTables()['marketplace_stock_movement'];
        foreach ($stock['columns'] as $name => &$column) $column['auto'] = $name === 'id';
        unset($column);
        return [
            'user' => ['columns' => ['uid' => $c('int unsigned', false, null, null, true)], 'indexes' => ['PRIMARY' => [true, ['uid']]]],
            'store_order' => [
                'columns' => ['id' => $c('int unsigned', false, null, null, true), 'uid' => $c('int unsigned', false, '0'),
                    'mer_id' => $c('int unsigned', false, '0'), 'pid' => $c('int', false, '0'),
                    'marketplace_trade_version' => $c('tinyint unsigned', true, '0')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'owner' => [false, ['mer_id', 'id']]],
            ],
            'store_order_cart_info' => [
                'columns' => ['id' => $c('int', false, null, null, true), 'oid' => $c('int', false, '0'),
                    'uid' => $c('int', false, '0'), 'mer_id' => $c('int unsigned', true), 'product_id' => $c('int', false, '0'),
                    'cart_num' => $c('int', false, '0'), 'refund_num' => $c('int', false, '0'),
                    'surplus_num' => $c('int', false, '0'), 'split_status' => $c('int', false, '0'),
                    'cart_id' => $c('varchar(50)', false, '0', 'utf8mb4'), 'cart_info' => $c('text', true, null, 'utf8mb4'),
                    'unique' => $c('char(32)', false, '', 'utf8mb4')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'item_identity' => [true, ['oid', 'unique']], 'item_owner' => [false, ['mer_id', 'oid', 'id']]],
            ],
            'marketplace_merchant' => ['columns' => ['mer_id' => $c('int unsigned', false, null, null, true)],
                'indexes' => ['PRIMARY' => [true, ['mer_id']]]],
            'marketplace_stock_movement' => $stock,
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
        $name = 'ms4-fulfill:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
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
                    $this->assertSources();
                    $this->writeAttempted = true;
                    if ($this->pdo->exec($step['sql']) === false) throw new RuntimeException('Structure apply failed');
                    $this->applied[] = $step['code'];
                    $this->assertSources();
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
        $this->assertSources();
        $issues = [];
        $steps = [];
        if ($this->pdo->inTransaction()) $issues[] = self::issue('active_transaction', 'migration');
        if ($this->scalar('SELECT DATABASE()') !== $this->database) $issues[] = self::issue('database_changed', 'migration');
        if ($issues !== []) return ['issues' => $issues, 'steps' => [], 'known' => false];
        $version = $this->scalar('SELECT VERSION()');
        if (!is_string($version) || preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) !== 1
            || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            return ['issues' => [self::issue('mysql_version', 'migration')], 'steps' => [], 'known' => false];
        }
        $modes = $this->rows('SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode');
        foreach (['session_mode', 'global_mode'] as $key) {
            $flags = explode(',', strtoupper((string) ($modes[0][$key] ?? '')));
            if (!in_array('STRICT_TRANS_TABLES', $flags, true) && !in_array('STRICT_ALL_TABLES', $flags, true)) $issues[] = self::issue('strict_sql_mode', $key);
        }
        foreach (['ms4' => new MarketplaceMs4Migration($this->pdo, $this->prefix, $this->database),
            'ms5' => new MarketplaceMs5PaymentMigration($this->pdo, $this->prefix, $this->database)] as $name => $migration) {
            $receipt = $migration->check();
            if (($receipt['native_exit'] ?? null) === 1) throw new RuntimeException('Prerequisite inspection unavailable');
            if (($receipt['native_exit'] ?? null) !== 0 || ($receipt['state'] ?? null) !== 'completed'
                || ($receipt['changed'] ?? null) !== false || ($receipt['issues'] ?? null) !== []) {
                $issues[] = self::issue($name . '_schema_not_completed', 'migration');
            }
        }
        $this->checkProducer($issues);
        foreach (self::newTables() as $name => $definition) {
            $meta = $this->metadata($name);
            foreach (['foreign' => 'FOREIGN KEY', 'checks' => 'CHECK'] as $kind => $type) {
                foreach (array_keys($definition[$kind]) as $constraint) {
                    $occupied = $this->rows('SELECT TABLE_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=?', [$this->database, $this->prefix . $constraint]);
                    if (count($occupied) > 1 || ($occupied !== [] && ($occupied[0]['TABLE_NAME'] !== $this->prefix . $name
                        || $occupied[0]['CONSTRAINT_TYPE'] !== $type || $meta === null))) {
                        $issues[] = self::issue('constraint_name_conflict', $constraint);
                    }
                }
            }
            if ($meta === null) $steps[] = ['code' => $name, 'sql' => $this->tableDdl($name, $definition)];
            elseif (!$this->sameNewTable($meta, $definition)) $issues[] = self::issue('new_table_conflict', $name);
        }
        $this->assertSources();
        return compact('issues', 'steps');
    }

    private function checkProducer(array &$issues): void
    {
        foreach (self::producerContracts() as $name => $contract) {
            $meta = $this->metadata($name);
            if (!$this->baseTable($meta)) { $issues[] = self::issue('producer_table', $name); continue; }
            foreach ($contract['columns'] as $column => $expected) {
                $collation = $expected['charset'] === 'ascii' ? 'ascii_bin'
                    : ($expected['charset'] === 'utf8mb4' ? $meta['table']['TABLE_COLLATION'] : null);
                if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $expected, $collation)) $issues[] = self::issue('producer_column', $name . '.' . $column);
            }
            foreach ($contract['indexes'] as $index => $expected) {
                $found = $index === 'PRIMARY' && $this->sameIndex($meta['indexes']['PRIMARY'] ?? [], $expected);
                if ($index !== 'PRIMARY') foreach ($meta['indexes'] as $actual) if ($this->sameIndex($actual, $expected)) $found = true;
                if (!$found) $issues[] = self::issue('producer_index', $name . '.' . $index);
            }
        }
    }

    private function baseTable(?array $meta): bool
    {
        return $meta !== null && $meta['table']['TABLE_TYPE'] === 'BASE TABLE'
            && strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') === 0
            && preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', (string) $meta['table']['TABLE_COLLATION']) === 1
            && $meta['triggers'] === [];
    }

    private function sameNewTable(array $meta, array $contract): bool
    {
        if (!$this->baseTable($meta) || $meta['table']['TABLE_COLLATION'] !== 'utf8mb4_bin'
            || $meta['table']['TABLE_COMMENT'] !== self::MARKER
            || array_keys($meta['columns']) !== array_keys($contract['columns'])) return false;
        foreach ($contract['columns'] as $name => $expected) {
            $collation = $expected['charset'] === 'ascii' ? 'ascii_bin' : null;
            if (!$this->sameColumn($meta['columns'][$name], $expected, $collation, true)) return false;
        }
        $indexes = $contract['indexes'];
        ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => $expected) if (!$this->sameIndex($meta['indexes'][$name], $expected)) return false;
        if (!$this->sameForeign($meta['foreign'], $contract['foreign'])) return false;
        $checks = [];
        foreach ($meta['checks'] as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $tree = self::checkMetadataExpression($row['CHECK_CLAUSE']);
            if (isset($checks[$name]) || $row['ENFORCED'] !== 'YES' || $tree === null) return false;
            $checks[$name] = $tree;
        }
        $wanted = [];
        foreach ($contract['checks'] as $name => $expression) {
            $tree = self::checkExpression($expression);
            if ($tree === null) throw new RuntimeException('Declared check unavailable');
            $wanted[$this->prefix . $name] = $tree;
        }
        ksort($checks, SORT_STRING); ksort($wanted, SORT_STRING);
        return $checks === $wanted;
    }

    private function sameForeign(array $rows, array $expected): bool
    {
        $actual = [];
        foreach ($rows as $row) $actual[$row['CONSTRAINT_NAME']][] = $row;
        $wanted = [];
        foreach ($expected as $name => $contract) $wanted[$this->prefix . $name] = $contract;
        ksort($actual, SORT_STRING); ksort($wanted, SORT_STRING);
        if (array_keys($actual) !== array_keys($wanted)) return false;
        foreach ($wanted as $name => [$target, $columns, $references, $unique]) {
            if (count($actual[$name]) !== count($columns)) return false;
            foreach ($actual[$name] as $offset => $row) {
                if ((string)$row['ORDINAL_POSITION'] !== (string)($offset + 1)
                    || (string)$row['POSITION_IN_UNIQUE_CONSTRAINT'] !== (string)($offset + 1)
                    || $row['COLUMN_NAME'] !== $columns[$offset] || $row['REFERENCED_COLUMN_NAME'] !== $references[$offset]
                    || $row['REFERENCED_TABLE_SCHEMA'] !== $this->database || $row['REFERENCED_TABLE_NAME'] !== $this->prefix . $target
                    || $row['UNIQUE_CONSTRAINT_SCHEMA'] !== $this->database || $row['UNIQUE_CONSTRAINT_NAME'] !== $unique
                    || $row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT'
                    || $row['MATCH_OPTION'] !== 'NONE') return false;
            }
        }
        return true;
    }

    private function sameColumn(array $actual, array $expected, ?string $collation, bool $comment = false): bool
    {
        return $actual['COLUMN_TYPE'] === $expected['type']
            && $actual['IS_NULLABLE'] === ($expected['nullable'] ? 'YES' : 'NO')
            && $actual['COLUMN_DEFAULT'] === $expected['default']
            && $actual['CHARACTER_SET_NAME'] === $expected['charset']
            && $actual['COLLATION_NAME'] === $collation
            && $actual['EXTRA'] === ($expected['auto'] ? 'auto_increment' : '')
            && (string) $actual['GENERATION_EXPRESSION'] === ''
            && (!$comment || $actual['COLUMN_COMMENT'] === $expected['comment']);
    }

    private function sameIndex(array $actual, array $expected): bool
    {
        [$unique, $columns] = $expected;
        if (count($actual) !== count($columns)) return false;
        foreach ($actual as $i => $row) if ((int) $row['SEQ_IN_INDEX'] !== $i + 1
            || (string) $row['NON_UNIQUE'] !== ($unique ? '0' : '1') || $row['COLUMN_NAME'] !== $columns[$i]
            || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
            || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A') return false;
        return true;
    }

    private function metadata(string $name): ?array
    {
        $args = [$this->database, $this->prefix . $name];
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', $args);
        if ($tables === []) return null;
        if (count($tables) !== 1) throw new RuntimeException('Metadata unavailable');
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $column) {
            if ((string)$column['ORDINAL_POSITION'] !== (string)(count($columns) + 1) || isset($columns[$column['COLUMN_NAME']])) {
                throw new RuntimeException('Column metadata unavailable');
            }
            $column['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ($column['COLUMN_DEFAULT'] !== null) $column['COLUMN_DEFAULT'] = (string)$column['COLUMN_DEFAULT'];
            $columns[$column['COLUMN_NAME']] = $column;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $index) $indexes[$index['INDEX_NAME']][] = $index;
        ksort($indexes, SORT_STRING);
        return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
            'foreign' => $this->rows('SELECT rc.CONSTRAINT_NAME,rc.UPDATE_RULE,rc.DELETE_RULE,rc.MATCH_OPTION,rc.UNIQUE_CONSTRAINT_SCHEMA,rc.UNIQUE_CONSTRAINT_NAME,kcu.ORDINAL_POSITION,kcu.POSITION_IN_UNIQUE_CONSTRAINT,kcu.COLUMN_NAME,kcu.REFERENCED_TABLE_SCHEMA,kcu.REFERENCED_TABLE_NAME,kcu.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME=rc.TABLE_NAME AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA=? AND rc.TABLE_NAME=? ORDER BY rc.CONSTRAINT_NAME,kcu.ORDINAL_POSITION', $args),
            'checks' => $this->rows("SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", $args),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
    }

    /** Only MS1's observed escaped-delimiter presentation for these fixed ASCII literals is decoded. */
    private static function checkMetadataExpression(string $expression): ?array
    {
        foreach (['OBJECT', 'ship', 'receive', 'employee', 'customer', 'system', 'level', 'member'] as $literal) {
            $expression = str_replace("_utf8mb4\\'" . $literal . "\\'", "_utf8mb4'" . $literal . "'", $expression);
        }
        return self::checkExpression($expression);
    }

    /** Finite MS1 grammar extended for the exact R3 checks, never evaluated as SQL/PHP. */
    private static function checkExpression(string $expression): ?array
    {
        if (strlen($expression) > 16384) return null;
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (!preg_match("/\\G(?:\\s+|`([A-Za-z0-9_]+)`|('(?:[^'\\\\]|'')*')|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+)|(<>|<=|>=|[(),+\\-=<>]))/A", $expression, $match, 0, $offset)) return null;
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') continue;
            if (($match[1] ?? '') !== '') $tokens[] = ['id', strtolower($match[1])];
            elseif (($match[2] ?? '') !== '') $tokens[] = ['string', str_replace("''", "'", substr($match[2], 1, -1))];
            elseif (($match[3] ?? '') !== '') $tokens[] = ['word', strtolower($match[3])];
            elseif (($match[4] ?? '') !== '') $tokens[] = ['number', $match[4]];
            else $tokens[] = ['symbol', $match[5]];
            if (count($tokens) > 4096) return null;
        }
        $position = 0; $depth = 0;
        $peek = static function (string $value) use (&$tokens, &$position): bool {
            return isset($tokens[$position]) && $tokens[$position][1] === $value && in_array($tokens[$position][0], ['word', 'symbol'], true);
        };
        $take = static function (string $value) use (&$position, $peek): bool {
            if (!$peek($value)) return false;
            ++$position; return true;
        };
        $or = $and = $comparison = $addition = $primary = null;
        $primary = static function () use (&$tokens, &$position, &$depth, &$or, $take): array {
            if (++$depth > 128) throw new RuntimeException('Check grammar');
            try {
                if ($take('(')) {
                    $node = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    return $node;
                }
                $token = $tokens[$position++] ?? null;
                if ($token === null) throw new RuntimeException('Check grammar');
                if ($token === ['word', '_utf8mb4']) {
                    $token = $tokens[$position++] ?? null;
                    if (($token[0] ?? '') !== 'string') throw new RuntimeException('Check grammar');
                }
                if ($token[0] === 'string') {
                    if (!in_array($token[1], ['OBJECT', 'ship', 'receive', 'employee', 'customer', 'system', 'level', 'member'], true)) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token[0] === 'number') {
                    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $token[1]) !== 1) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token[0] !== 'id' && $token[0] !== 'word') throw new RuntimeException('Check grammar');
                if ($take('(')) {
                    if ($token[1] !== 'json_type') throw new RuntimeException('Check grammar');
                    $argument = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    return ['function', 'json_type', $argument];
                }
                return ['id', $token[1]];
            } finally { --$depth; }
        };
        $addition = static function () use (&$primary, $peek, $take): array {
            $node = $primary();
            while ($peek('+') || $peek('-')) {
                $operator = $take('+') ? '+' : '-';
                if ($operator === '-' && !$take('-')) throw new RuntimeException('Check grammar');
                $node = [$operator, $node, $primary()];
            }
            return $node;
        };
        $comparison = static function () use (&$addition, $take): array {
            $node = $addition();
            foreach (['=', '<>', '<=', '>=', '<', '>'] as $operator) if ($take($operator)) return [$operator, $node, $addition()];
            if ($take('is')) {
                $negative = $take('not');
                if (!$take('null')) throw new RuntimeException('Check grammar');
                return [$negative ? 'is_not_null' : 'is_null', $node];
            }
            if ($take('between')) {
                $lower = $addition();
                if (!$take('and')) throw new RuntimeException('Check grammar');
                return ['between', $node, $lower, $addition()];
            }
            if ($take('in')) {
                if (!$take('(')) throw new RuntimeException('Check grammar');
                $values = [$addition()];
                while ($take(',')) $values[] = $addition();
                if (!$take(')')) throw new RuntimeException('Check grammar');
                return ['in', $node, $values];
            }
            return $node;
        };
        $flatten = static function (string $operator, array $nodes): array {
            if (count($nodes) === 1) return $nodes[0];
            $flat = [];
            foreach ($nodes as $node) foreach ($node[0] === $operator ? $node[1] : [$node] as $part) $flat[] = $part;
            return [$operator, $flat];
        };
        $and = static function () use (&$comparison, $take, $flatten): array {
            $nodes = [$comparison()];
            while ($take('and')) $nodes[] = $comparison();
            return $flatten('and', $nodes);
        };
        $or = static function () use (&$and, $take, $flatten): array {
            $nodes = [$and()];
            while ($take('or')) $nodes[] = $and();
            return $flatten('or', $nodes);
        };
        try {
            $tree = $or();
            return $position === count($tokens) ? $tree : null;
        } catch (Throwable $error) { return null; }
    }

    private function columnSql(array $column, ?string $collation): string
    {
        $sql = strtoupper($column['type']);
        if ($column['charset'] !== null) $sql .= ' CHARACTER SET ' . $column['charset'] . ' COLLATE ' . $collation;
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
        if ($column['default'] !== null) $sql .= ' DEFAULT ' . $this->pdo->quote($column['default']);
        elseif ($column['nullable']) $sql .= ' DEFAULT NULL';
        if ($column['auto']) $sql .= ' AUTO_INCREMENT';
        return $sql . ' COMMENT ' . $this->pdo->quote($column['comment']);
    }

    private function tableDdl(string $name, array $definition): string
    {
        $parts = [];
        foreach ($definition['columns'] as $column => $contract) {
            $collation = $contract['charset'] === 'ascii' ? 'ascii_bin' : null;
            $parts[] = self::quote($column) . ' ' . $this->columnSql($contract, $collation);
        }
        foreach ($definition['indexes'] as $index => $contract) $parts[] = $this->indexSql($index, $contract);
        foreach ($definition['checks'] as $constraint => $expression) {
            if (self::checkExpression($expression) === null) throw new RuntimeException('Declared check unavailable');
            $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . $constraint) . ' CHECK (' . $expression . ') ENFORCED';
        }
        foreach ($definition['foreign'] as $constraint => [$target, $columns, $referenced]) {
            $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . $constraint) . ' FOREIGN KEY ('
                . implode(',', array_map([self::class, 'quote'], $columns)) . ') REFERENCES ' . $this->table($target)
                . ' (' . implode(',', array_map([self::class, 'quote'], $referenced)) . ') ON UPDATE RESTRICT ON DELETE RESTRICT';
        }
        return 'CREATE TABLE ' . $this->table($name) . " (\n  " . implode(",\n  ", $parts)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='" . self::MARKER . "'";
    }

    private function indexSql(string $name, array $contract): string
    {
        return ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($contract[0] ? 'UNIQUE KEY ' : 'KEY ') . self::quote($name))
            . ' (' . implode(',', array_map([self::class, 'quote'], $contract[1])) . ')';
    }

    private function receipt(string $mode, array $view, ?string $phase = null): array
    {
        try { $this->assertSources(); }
        catch (Throwable $error) {
            $phase ??= 'source_changed';
            $view['issues'][] = self::issue('source_changed', 'migration');
            $view['known'] = false;
        }
        $state = $view['issues'] !== [] ? 'incompatible' : ($view['steps'] === [] ? 'completed' : 'applicable');
        $prerequisites = $this->sourceHashes;
        unset($prerequisites['self']);
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $phase !== null ? 1 : ($state === 'incompatible' ? 2 : 0),
            'changed' => $this->writeAttempted, 'applied_steps' => $this->applied,
            'pending_steps' => array_column($view['steps'], 'code'), 'pending_steps_known' => $view['known'] ?? true,
            'issues' => $view['issues'], 'failure_phase' => $phase, 'marker' => self::MARKER,
            'source_sha256' => $this->sourceHashes['self'], 'scope' => self::SCOPE,
            'prerequisite_source_sha256' => $prerequisites, 'writer' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    }

    private function failure(string $mode, string $phase): array
    {
        try { $view = $this->inspect(); }
        catch (Throwable $error) { $view = ['issues' => [], 'steps' => [], 'known' => false]; }
        $view['issues'][] = self::issue('operation_failed', 'migration');
        return $this->receipt($mode, $view, $phase);
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!($statement instanceof PDOStatement) || !$statement->execute($parameters)) throw new RuntimeException('Metadata read failed');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) throw new RuntimeException('Metadata unavailable');
        return $rows;
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

/** Bounded explicit JSON parser; private-file owner/mode/tmpfs are the isolated caller's responsibility. */
function marketplaceMs4FulfillmentConfiguration(string $path): array
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

function marketplaceMs4FulfillmentMain(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms4-fulfillment.php [check|apply-structure] --config-file=PRIVATE_JSON\nDefault check is read-only. Require completed original MS4 and MS5 structures. Only the two exact fulfillment tables may be created. No .env, App, business writes, backfill, DROP or writer activation. DDL can partially commit; preserve the receipt and only exact missing CREATE steps may be resumed.\n";
        return 0;
    }
    $mode = 'check'; $file = null; $selected = false;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply-structure'], true) && !$selected) { $mode = $argument; $selected = true; }
            elseif (str_starts_with($argument, '--config-file=') && $file === null && strlen($argument) > 14) $file = substr($argument, 14);
            else throw new RuntimeException('CLI configuration unavailable');
        }
        if ($file === null) throw new RuntimeException('Explicit private configuration required');
        $config = marketplaceMs4FulfillmentConfiguration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs4FulfillmentMigration($pdo, $config['prefix'], $config['database']);
        $result = $mode === 'check' ? $migration->check() : $migration->applyStructure();
    } catch (Throwable $error) {
        // No SQL, driver error, private path, configuration value or credential is emitted.
        $result = ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => false,
            'applied_steps' => [], 'pending_steps' => [], 'pending_steps_known' => false,
            'issues' => [['kind' => 'configuration_or_connection_failed', 'target' => 'migration']],
            'failure_phase' => 'configuration_or_connection_failed', 'marker' => MarketplaceMs4FulfillmentMigration::MARKER,
            'source_sha256' => hash_file('sha256', __FILE__), 'scope' => MarketplaceMs4FulfillmentMigration::SCOPE,
            'prerequisite_source_sha256' => [], 'writer' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return $result['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(marketplaceMs4FulfillmentMain(array_slice($argv, 1)));
