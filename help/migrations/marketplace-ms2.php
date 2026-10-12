<?php
declare(strict_types=1);

namespace Audit\Migration;

use PDO;
use RuntimeException;
use Throwable;

/** External review candidate. No framework bootstrap, history mapping or feature activation. */
final class MarketplaceMs2Migration
{
    private const MARKER = 'marketplace-ms2-structure-v1';
    private const SELF_COLUMNS = ['mer_id', 'name', 'logo', 'after_sale_contact', 'shipping_note', 'review_status', 'accept_orders', 'fulfillment_enabled', 'security_locked', 'closed_at', 'add_time', 'update_time'];
    private const ROOTS = ['system_attachment' => 'att_id', 'system_attachment_category' => 'id', 'shipping_templates' => 'id', 'store_product_rule' => 'id'];
    private const REVIEW = [
        'review_status' => ['tinyint unsigned', false, '0'],
        'review_reason' => ['varchar(512)', false, ''],
        'review_admin_id' => ['smallint unsigned', true, null],
        'review_time' => ['int unsigned', true, null],
    ];
    private PDO $pdo;
    private MarketplaceMs1Migration $ms1;
    private string $prefix;
    private string $database;
    private string $repoRoot;
    private ?int $configuredSelfId;
    private ?string $stateFile;
    /** @var resource|null */
    private $stateHandle = null;
    private ?string $stateBytesHash = null;
    private bool $changed = false;
    private bool $writeAttempted = false;
    private array $applied = [];

    public function __construct(PDO $pdo, MarketplaceMs1Migration $ms1, string $prefix, string $repoRoot, array $marketplace = [], ?string $stateFile = null)
    {
        if (array_diff(array_keys($marketplace), ['enabled', 'self_operated_mer_id']) !== [] || (array_key_exists('enabled', $marketplace) && $marketplace['enabled'] !== false)) {
            throw new RuntimeException('Configuration unavailable');
        }
        $id = $marketplace['self_operated_mer_id'] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1 || $id > 4294967295)) { throw new RuntimeException('Configuration unavailable'); }
        if (PHP_INT_SIZE < 8 || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Configuration unavailable');
        }
        $root = realpath($repoRoot);
        if ($root === false || !is_dir($root)) { throw new RuntimeException('Configuration unavailable'); }
        $this->pdo = $pdo;
        $this->ms1 = $ms1;
        $this->prefix = $prefix;
        $this->repoRoot = $root;
        $this->configuredSelfId = $id;
        $this->stateFile = $stateFile === null ? null : $this->privateStatePath($stateFile);
        if (!$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION) || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) { throw new RuntimeException('Configuration unavailable'); }
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') { throw new RuntimeException('Configuration unavailable'); }
        $this->database = $database;
        foreach (array_merge(array_keys(self::ROOTS), ['store_product', 'store_product_attr_value', 'marketplace_stock_log', 'marketplace_merchant', 'marketplace_employee', 'mm2_stock_positive', 'mm2_stock_merchant_fk', 'mm2_stock_employee_fk']) as $name) {
            self::identifier($prefix . $name);
        }
    }

    public function check(): array
    {
        $this->changed = false;
        $this->writeAttempted = false;
        $this->applied = [];
        try {
            $view = $this->inspect();
            $identity = $view['issues'] === [] ? $this->identity($view) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            return $this->receipt('check', $view, $identity);
        } catch (Throwable $ignored) {
            return $this->failure('check', 'inspection_failed');
        }
    }

    public function applyStructure(): array
    {
        $this->changed = false;
        $this->writeAttempted = false;
        $this->applied = [];
        $locked = false;
        $transaction = false;
        $phase = 'precheck_failed';
        $result = null;
        $lockName = 'marketplace-ms2:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
        try {
            if ($this->pdo->inTransaction()) { return $this->failure('apply-structure', 'active_transaction', 2); }
            $before = $this->inspect();
            $beforeIdentity = $before['issues'] === [] ? $this->identity($before) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            if (array_merge($before['issues'], $beforeIdentity['issues']) !== []) { return $this->receipt('apply-structure', $before, $beforeIdentity); }
            if ($beforeIdentity['kind'] === 'binding_required') { return $this->failure('apply-structure', 'identity_binding_required', 2); }
            $phase = 'lock_failed';
            if ((string) $this->scalar('SELECT GET_LOCK(?, 10)', [$lockName]) !== '1') { throw new RuntimeException('Operation failed'); }
            $locked = true;
            $phase = 'locked_recheck_failed';
            $view = $this->inspect();
            $identity = $view['issues'] === [] ? $this->identity($view) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            if (array_merge($view['issues'], $identity['issues']) !== []) {
                $result = $this->receipt('apply-structure', $view, $identity);
            } else {
                if ($this->stateFile !== null && ($identity['kind'] !== 'configured' || file_exists($this->stateFile))) {
                    $phase = 'private_state_lock_failed';
                    $newState = $identity['kind'] === 'new_required';
                    $this->openStateHandle($newState);
                    if ($newState) {
                        $phase = 'private_intent_write_failed';
                        $this->writeState($this->stateRecord($view, 'structure_pending'));
                    }
                    // Re-read through the same inode after the private file lock.
                    $identity = $this->identity($view);
                    if ($identity['issues'] !== []) { throw new RuntimeException('Operation failed'); }
                }
                $phase = 'structure_apply_failed';
                foreach ($view['steps'] as $step) {
                    $this->writeAttempted = true;
                    if ($this->pdo->exec($step['sql']) === false) { throw new RuntimeException('Operation failed'); }
                    $this->changed = true;
                    $this->applied[] = $step['code'];
                }
                $phase = 'structure_postcheck_failed';
                $after = $this->inspect();
                if ($after['issues'] !== [] || $after['steps'] !== [] || $after['structure_sha256'] !== $view['structure_sha256']) { throw new RuntimeException('Operation failed'); }
                if ($identity['kind'] === 'structure_pending') {
                    $phase = 'self_insert_intent_failed';
                    $this->writeState($this->stateRecord($after, 'insert_pending'));
                    $phase = 'self_insert_failed';
                    if (!$this->pdo->beginTransaction()) { throw new RuntimeException('Operation failed'); }
                    $transaction = true;
                    $now = time();
                    if ($now < 1 || $now > 4294967295) { throw new RuntimeException('Operation failed'); }
                    $this->writeAttempted = true;
                    $this->rows('INSERT INTO ' . $this->table('marketplace_merchant') . ' (name,logo,after_sale_contact,shipping_note,review_status,accept_orders,fulfillment_enabled,security_locked,closed_at,add_time,update_time) VALUES (?,?,?,?,?,?,?,?,?,?,?)', ['自营商家', '', '', '', 0, 0, 1, 0, null, $now, $now]);
                    $id = self::positiveUint($this->pdo->lastInsertId());
                    $row = $this->selfRow($id);
                    if ($row['name'] !== '自营商家' || $row['logo'] !== '' || $row['after_sale_contact'] !== '' || $row['shipping_note'] !== '' || (string) $row['review_status'] !== '0' || (string) $row['accept_orders'] !== '0' || (string) $row['fulfillment_enabled'] !== '1' || (string) $row['security_locked'] !== '0' || $row['closed_at'] !== null || (int) $row['add_time'] !== $now || (int) $row['update_time'] !== $now) { throw new RuntimeException('Operation failed'); }
                    $phase = 'self_exact_id_register_failed';
                    $state = $this->stateRecord($after, 'bound') + ['self_mer_id' => $id, 'created_at' => $now, 'row_shape_sha256' => $this->selfShapeHash($row)];
                    $this->writeState($state);
                    $phase = 'self_commit_failed';
                    if (!$this->pdo->commit()) { throw new RuntimeException('Operation failed'); }
                    $transaction = false;
                    $this->changed = true;
                    $this->applied[] = 'self_identity_created';
                }
                $phase = 'identity_postcheck_failed';
                $finalIdentity = $this->identity($after);
                if ($finalIdentity['issues'] !== [] || !in_array($finalIdentity['kind'], ['configured', 'bound'], true)) { throw new RuntimeException('Operation failed'); }
                $result = $this->receipt('apply-structure', $after, $finalIdentity);
            }
        } catch (Throwable $ignored) {
            if ($transaction) { try { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } } catch (Throwable $ignoredRollback) { /* DDL is not rolled back. */ } }
            $result = $this->failure('apply-structure', $phase);
        } finally {
            if (is_resource($this->stateHandle)) { @flock($this->stateHandle, LOCK_UN); @fclose($this->stateHandle); $this->stateHandle = null; }
            if ($locked) {
                try { if ((string) $this->scalar('SELECT RELEASE_LOCK(?)', [$lockName]) !== '1') { throw new RuntimeException('Operation failed'); } }
                catch (Throwable $ignoredRelease) {
                    $first = $result['issues'] ?? [];
                    $result = $this->failure('apply-structure', 'lock_release_failed');
                    $result['issues'] = array_merge($first, $result['issues']);
                }
            }
        }
        return $result ?? $this->failure('apply-structure', 'result_unavailable');
    }

    private function inspect(): array
    {
        $issues = [];
        $steps = [];
        $pristine = true;
        $version = $this->scalar('SELECT VERSION()');
        if (!is_string($version) || preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) !== 1 || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            return ['issues' => [self::issue('mysql_version', 'migration')], 'steps' => [], 'pristine' => false];
        }
        if ($this->ms1->check()['state'] !== 'completed') { $issues[] = self::issue('ms1_schema_not_completed', 'migration'); }
        $baseline = [];
        foreach (self::ROOTS + ['store_product' => 'id', 'store_product_attr_value' => 'id'] as $name => $pk) {
            $meta = $this->metadata($name);
            $wantedId = $name === 'store_product' ? 'mediumint' : 'int';
            if ($meta === null || $meta['table']['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') !== 0 || preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', (string) $meta['table']['TABLE_COLLATION']) !== 1 || $meta['triggers'] !== [] || !isset($meta['columns'][$pk]) || !$this->sameColumn($meta['columns'][$pk], [$wantedId, false, null], null, true) || !isset($meta['indexes']['PRIMARY']) || !$this->sameIndex($meta['indexes']['PRIMARY'], [$pk], true)) {
                $issues[] = self::issue('installed_base_schema', $name);
                continue;
            }
            $contractColumns = isset(self::ROOTS[$name]) ? ['mer_id' => ['int unsigned', true, null]] : ($name === 'store_product' ? self::REVIEW : []);
            if ($name === 'store_product') {
                foreach (['mer_id' => ['int unsigned', false, '0'], 'stock' => ['mediumint unsigned', false, '0'], 'is_show' => ['tinyint', false, '1'], 'is_del' => ['tinyint unsigned', false, '0']] as $column => $expected) {
                    if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $expected)) { $issues[] = self::issue('installed_product_schema', $column); }
                }
            }
            if ($name === 'store_product_attr_value') {
                foreach (['stock' => ['int unsigned', false, '0'], 'unique' => ['char(8)', false, '']] as $column => $expected) {
                    if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $expected, $column === 'unique' ? $meta['table']['TABLE_COLLATION'] : null)) { $issues[] = self::issue('installed_sku_schema', $column); }
                }
            }
            foreach ($contractColumns as $column => $expected) {
                $collation = $column === 'review_reason' ? $meta['table']['TABLE_COLLATION'] : null;
                if (isset($meta['columns'][$column])) {
                    $pristine = false;
                    if (!$this->sameColumn($meta['columns'][$column], $expected, $collation)) { $issues[] = self::issue('column_conflict', $name . '.' . $column); }
                } else {
                    $steps[] = ['code' => $name . '.' . $column, 'sql' => 'ALTER TABLE ' . $this->table($name) . ' ADD COLUMN ' . self::quote($column) . ' ' . $this->columnSql($expected, $collation)];
                }
            }
            $indexes = isset(self::ROOTS[$name]) || $name === 'store_product' ? ['idx_ms2_owner' => ['mer_id', $pk]] : [];
            if ($name === 'store_product') { $indexes['idx_ms2_review'] = ['review_status', 'is_show', 'is_del', 'mer_id', 'id']; }
            foreach ($indexes as $index => $columns) {
                if (isset($meta['indexes'][$index]) && !$this->sameIndex($meta['indexes'][$index], $columns)) { $issues[] = self::issue('index_conflict', $name . '.' . $index); }
                $matching = false;
                foreach ($meta['indexes'] as $value) { if ($this->sameIndex($value, $columns)) { $matching = true; } }
                if ($matching) { $pristine = false; }
                elseif (!isset($meta['indexes'][$index])) { $steps[] = ['code' => $name . '.' . $index, 'sql' => 'ALTER TABLE ' . $this->table($name) . ' ADD KEY ' . self::quote($index) . ' (' . implode(',', array_map([self::class, 'quote'], $columns)) . ')']; }
            }
            foreach (array_keys($contractColumns) as $column) { unset($meta['columns'][$column]); }
            foreach ($meta['indexes'] as $index => $value) {
                foreach ($indexes as $columns) { if ($this->sameIndex($value, $columns)) { unset($meta['indexes'][$index]); } }
            }
            $baseline[$name] = $meta;
        }
        foreach (['marketplace_merchant', 'marketplace_employee'] as $name) { $baseline[$name] = $this->metadata($name); }
        $stock = $this->metadata('marketplace_stock_log');
        if ($stock === null) { $steps[] = ['code' => 'marketplace_stock_log', 'sql' => $this->stockDdl()]; }
        else {
            $pristine = false;
            if (!$this->sameStock($stock)) { $issues[] = self::issue('stock_log_schema_conflict', 'marketplace_stock_log'); }
        }
        $uuid = $this->scalar('SELECT @@server_uuid');
        if (!is_string($uuid) || preg_match('/\A[0-9a-fA-F-]{36}\z/D', $uuid) !== 1) { $issues[] = self::issue('database_identity_unavailable', 'migration'); }
        return ['issues' => $issues, 'steps' => $steps, 'pristine' => $pristine, 'database_sha256' => hash('sha256', (string) $uuid . "\0" . $this->database . "\0" . $this->prefix), 'structure_sha256' => hash('sha256', self::json($baseline))];
    }

    private function metadata(string $logical): ?array
    {
        $table = $this->prefix . $logical;
        $found = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->database, $table]);
        if ($found === []) { return null; }
        if (count($found) !== 1) { throw new RuntimeException('Inspection unavailable'); }
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$this->database, $table]) as $row) {
            $row['COLUMN_TYPE'] = self::normalType($row['COLUMN_TYPE']);
            if ($row['COLUMN_DEFAULT'] !== null) { $row['COLUMN_DEFAULT'] = (string) $row['COLUMN_DEFAULT']; }
            if ($row['CHARACTER_SET_NAME'] === 'utf8') { $row['CHARACTER_SET_NAME'] = 'utf8mb3'; }
            if ($row['COLLATION_NAME'] === 'utf8_bin') { $row['COLLATION_NAME'] = 'utf8mb3_bin'; }
            $columns[$row['COLUMN_NAME']] = $row;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$this->database, $table]) as $row) { $indexes[$row['INDEX_NAME']][] = $row; }
        ksort($indexes);
        return ['table' => $found[0], 'columns' => $columns, 'indexes' => $indexes,
            'foreign' => $this->rows('SELECT rc.CONSTRAINT_NAME,rc.UPDATE_RULE,rc.DELETE_RULE,rc.MATCH_OPTION,kcu.ORDINAL_POSITION,kcu.COLUMN_NAME,kcu.REFERENCED_TABLE_SCHEMA,kcu.REFERENCED_TABLE_NAME,kcu.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME=rc.TABLE_NAME AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA=? AND rc.TABLE_NAME=? ORDER BY rc.CONSTRAINT_NAME,kcu.ORDINAL_POSITION', [$this->database, $table]),
            'checks' => $this->rows("SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", [$this->database, $table]),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$this->database, $table])];
    }

    private static function normalType(string $type): string
    {
        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower(trim($type)));
    }

    private function sameColumn(array $actual, array $expected, ?string $collation = null, bool $auto = false): bool
    {
        $text = preg_match('/\A(?:char|varchar)\(/', $expected[0]) === 1;
        return $actual['COLUMN_TYPE'] === $expected[0] && $actual['IS_NULLABLE'] === ($expected[1] ? 'YES' : 'NO') && $actual['COLUMN_DEFAULT'] === $expected[2]
            && $actual['CHARACTER_SET_NAME'] === ($text ? 'utf8mb4' : null) && $actual['COLLATION_NAME'] === ($text ? $collation : null)
            && $actual['EXTRA'] === ($auto ? 'auto_increment' : '') && (string) $actual['GENERATION_EXPRESSION'] === '';
    }

    private function sameIndex(array $actual, array $columns, bool $unique = false): bool
    {
        if (count($actual) !== count($columns)) { return false; }
        foreach ($actual as $offset => $row) {
            if ((int) $row['SEQ_IN_INDEX'] !== $offset + 1 || (string) $row['NON_UNIQUE'] !== ($unique ? '0' : '1') || $row['COLUMN_NAME'] !== $columns[$offset] || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES' || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A') { return false; }
        }
        return true;
    }

    private function columnSql(array $expected, ?string $collation = null): string
    {
        $sql = strtoupper($expected[0]);
        if ($collation !== null) { $sql .= ' CHARACTER SET utf8mb4 COLLATE ' . self::identifier($collation); }
        $sql .= $expected[1] ? ' NULL' : ' NOT NULL';
        if ($expected[2] !== null) { $sql .= ' DEFAULT ' . (str_starts_with($expected[0], 'varchar(') ? $this->pdo->quote($expected[2]) : $expected[2]); }
        return $sql;
    }

    private function stockColumns(): array
    {
        return ['id' => ['bigint unsigned', false, null], 'mer_id' => ['int unsigned', false, null], 'actor_employee_id' => ['int unsigned', false, null], 'product_id' => ['mediumint', false, null], 'attr_value_id' => ['int', false, null], 'sku_unique' => ['char(8)', false, null], 'action' => ['varchar(16)', false, null], 'delta' => ['bigint', false, null], 'stock_before' => ['int unsigned', false, null], 'stock_after' => ['int unsigned', false, null], 'root_stock_before' => ['mediumint unsigned', false, null], 'root_stock_after' => ['mediumint unsigned', false, null], 'reason' => ['varchar(255)', false, null], 'request_id' => ['varchar(64)', false, null], 'add_time' => ['int unsigned', false, null]];
    }

    private function stockIndexes(): array
    {
        return ['PRIMARY' => [true, ['id']], 'idx_mer_stock_id' => [false, ['mer_id', 'id']], 'idx_product_stock_id' => [false, ['product_id', 'id']], 'idx_mer_stock_actor' => [false, ['mer_id', 'actor_employee_id']]];
    }

    private function stockDdl(): string
    {
        $parts = [];
        foreach ($this->stockColumns() as $column => $expected) { $parts[] = self::quote($column) . ' ' . $this->columnSql($expected) . ($column === 'id' ? ' AUTO_INCREMENT' : ''); }
        foreach ($this->stockIndexes() as $index => [$unique, $columns]) { $parts[] = ($index === 'PRIMARY' ? 'PRIMARY KEY' : 'KEY ' . self::quote($index)) . ' (' . implode(',', array_map([self::class, 'quote'], $columns)) . ')'; }
        $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . 'mm2_stock_positive') . ' CHECK (`mer_id` > 0 AND `actor_employee_id` > 0)';
        $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . 'mm2_stock_merchant_fk') . ' FOREIGN KEY (`mer_id`) REFERENCES ' . $this->table('marketplace_merchant') . ' (`mer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT';
        $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . 'mm2_stock_employee_fk') . ' FOREIGN KEY (`mer_id`,`actor_employee_id`) REFERENCES ' . $this->table('marketplace_employee') . ' (`mer_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT';
        return 'CREATE TABLE ' . $this->table('marketplace_stock_log') . ' (' . implode(',', $parts) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='" . self::MARKER . "'";
    }

    private function sameStock(array $meta): bool
    {
        if ($meta['table']['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') !== 0 || $meta['table']['TABLE_COLLATION'] !== 'utf8mb4_bin' || $meta['table']['TABLE_COMMENT'] !== self::MARKER || $meta['triggers'] !== [] || array_keys($meta['columns']) !== array_keys($this->stockColumns()) || array_keys($meta['indexes']) !== array_keys($this->sortedStockIndexes())) { return false; }
        foreach ($this->stockColumns() as $column => $expected) { if (!$this->sameColumn($meta['columns'][$column], $expected, 'utf8mb4_bin', $column === 'id')) { return false; } }
        foreach ($this->stockIndexes() as $index => [$unique, $columns]) { if (!$this->sameIndex($meta['indexes'][$index], $columns, $unique)) { return false; } }
        $checks = $meta['checks'];
        if (count($checks) !== 1 || $checks[0]['CONSTRAINT_NAME'] !== $this->prefix . 'mm2_stock_positive' || $checks[0]['ENFORCED'] !== 'YES' || !self::positiveStockCheck($checks[0]['CHECK_CLAUSE'])) { return false; }
        $expected = [$this->prefix . 'mm2_stock_employee_fk' => ['marketplace_employee', ['mer_id', 'actor_employee_id'], ['mer_id', 'id']], $this->prefix . 'mm2_stock_merchant_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id']]];
        $foreign = [];
        foreach ($meta['foreign'] as $row) {
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($expected[$name]) || $row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT' || $row['MATCH_OPTION'] !== 'NONE' || $row['REFERENCED_TABLE_SCHEMA'] !== $this->database || $row['REFERENCED_TABLE_NAME'] !== $this->prefix . $expected[$name][0]) { return false; }
            $offset = count($foreign[$name] ?? []);
            if ((int) $row['ORDINAL_POSITION'] !== $offset + 1 || $row['COLUMN_NAME'] !== ($expected[$name][1][$offset] ?? null) || $row['REFERENCED_COLUMN_NAME'] !== ($expected[$name][2][$offset] ?? null)) { return false; }
            $foreign[$name][] = true;
        }
        return count($foreign[$this->prefix . 'mm2_stock_employee_fk'] ?? []) === 2 && count($foreign[$this->prefix . 'mm2_stock_merchant_fk'] ?? []) === 1 && count($foreign) === 2;
    }

    private function sortedStockIndexes(): array { $indexes = $this->stockIndexes(); ksort($indexes); return $indexes; }

    /** Only the fixed two-comparison AND tree is accepted; grouping is preserved. */
    private static function positiveStockCheck(string $expression): bool
    {
        if (strlen($expression) > 512) { return false; }
        $source = preg_replace('/[`\s]+/', '', strtolower($expression));
        preg_match_all('/actor_employee_id|mer_id|and|[0-9]+|[()>]/', $source, $matches);
        $tokens = $matches[0];
        if (implode('', $tokens) !== $source) { return false; }
        $offset = 0;
        $parse = null;
        $term = static function (int $depth) use (&$parse, &$offset, $tokens): array {
            if ($depth > 8) { throw new RuntimeException('Metadata unavailable'); }
            if (($tokens[$offset] ?? null) === '(') {
                ++$offset;
                $node = $parse($depth + 1);
                if (($tokens[$offset++] ?? null) !== ')') { throw new RuntimeException('Metadata unavailable'); }
                return $node;
            }
            $column = $tokens[$offset++] ?? null;
            if (!in_array($column, ['mer_id', 'actor_employee_id'], true) || ($tokens[$offset++] ?? null) !== '>' || ($tokens[$offset++] ?? null) !== '0') { throw new RuntimeException('Metadata unavailable'); }
            return ['gt', $column, 0];
        };
        $parse = static function (int $depth) use ($term, &$offset, $tokens): array {
            $node = $term($depth);
            if (($tokens[$offset] ?? null) === 'and') { ++$offset; return ['and', $node, $term($depth)]; }
            return $node;
        };
        try { $tree = $parse(0); }
        catch (Throwable $ignored) { return false; }
        return $offset === count($tokens) && $tree === ['and', ['gt', 'mer_id', 0], ['gt', 'actor_employee_id', 0]];
    }

    private function identity(array $view): array
    {
        $state = $this->readState();
        if ($state !== null) {
            $allowed = ['schema', 'marker', 'database_sha256', 'structure_sha256', 'phase'];
            if (($state['phase'] ?? '') === 'bound') { $allowed = array_merge($allowed, ['self_mer_id', 'created_at', 'row_shape_sha256']); }
            if (array_diff(array_keys($state), $allowed) !== [] || count($state) !== count($allowed) || ($state['schema'] ?? null) !== 1 || ($state['marker'] ?? null) !== self::MARKER || ($state['database_sha256'] ?? null) !== $view['database_sha256'] || ($state['structure_sha256'] ?? null) !== $view['structure_sha256']) { return $this->identityIssue('identity_state_mismatch'); }
            if ($state['phase'] === 'bound') {
                if (!is_int($state['self_mer_id']) || $state['self_mer_id'] < 1 || $state['self_mer_id'] > 4294967295 || !is_int($state['created_at']) || $state['created_at'] < 1 || !is_string($state['row_shape_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $state['row_shape_sha256']) !== 1 || ($this->configuredSelfId !== null && $this->configuredSelfId !== $state['self_mer_id'])) { return $this->identityIssue('identity_state_mismatch'); }
                $row = $this->selfRow($state['self_mer_id']);
                if ((int) $row['add_time'] !== $state['created_at'] || !hash_equals($state['row_shape_sha256'], $this->selfShapeHash($row))) { return $this->identityIssue('identity_row_mismatch'); }
                return ['kind' => $this->configuredSelfId === null ? 'bound' : 'configured', 'id' => $state['self_mer_id'], 'issues' => []];
            }
            if ($state['phase'] === 'structure_pending' && $this->configuredSelfId === null) { return ['kind' => 'structure_pending', 'id' => null, 'issues' => []]; }
            return $this->identityIssue('identity_incomplete_state');
        }
        if ($this->configuredSelfId !== null) { $this->selfRow($this->configuredSelfId); return ['kind' => 'configured', 'id' => $this->configuredSelfId, 'issues' => []]; }
        if ($this->stateFile === null) { return ['kind' => 'binding_required', 'id' => null, 'issues' => []]; }
        if (!$view['pristine']) { return $this->identityIssue('identity_state_missing'); }
        return ['kind' => 'new_required', 'id' => null, 'issues' => []];
    }

    private function selfRow(int $id): array
    {
        $rows = $this->rows('SELECT ' . implode(',', array_map([self::class, 'quote'], self::SELF_COLUMNS)) . ' FROM ' . $this->table('marketplace_merchant') . ' WHERE mer_id=? LIMIT 2', [$id]);
        if (count($rows) !== 1 || array_keys($rows[0]) !== self::SELF_COLUMNS || self::positiveUint($rows[0]['mer_id']) !== $id) { throw new RuntimeException('Identity unavailable'); }
        $row = $rows[0];
        foreach (['name' => 128, 'logo' => 512, 'after_sale_contact' => 255, 'shipping_note' => 1000] as $name => $limit) {
            if (!is_string($row[$name]) || preg_match('//u', $row[$name]) !== 1 || str_contains($row[$name], "\0") || preg_match_all('/./us', $row[$name]) > $limit || ($name === 'name' && trim($row[$name]) === '')) { throw new RuntimeException('Identity unavailable'); }
        }
        foreach (['review_status' => 2, 'accept_orders' => 1, 'fulfillment_enabled' => 1, 'security_locked' => 1] as $name => $maximum) {
            if (!preg_match('/\A[0-2]\z/D', (string) $row[$name]) || (int) $row[$name] > $maximum) { throw new RuntimeException('Identity unavailable'); }
        }
        self::positiveUint($row['add_time']); self::positiveUint($row['update_time']);
        if ($row['closed_at'] !== null) { self::positiveUint($row['closed_at']); }
        return $row;
    }

    private function selfShapeHash(array $row): string { return hash('sha256', self::json(['mer_id' => (int) $row['mer_id'], 'add_time' => (int) $row['add_time'], 'columns' => self::SELF_COLUMNS])); }
    private function stateRecord(array $view, string $phase): array { return ['schema' => 1, 'marker' => self::MARKER, 'database_sha256' => $view['database_sha256'], 'structure_sha256' => $view['structure_sha256'], 'phase' => $phase]; }

    private function privateStatePath(string $path): string
    {
        // Windows chmod cannot establish the required private ACL; do not pretend otherwise.
        if (DIRECTORY_SEPARATOR !== '/' || !str_starts_with($path, '/') || str_contains($path, "\0") || !function_exists('posix_geteuid') || !function_exists('fsync')) { throw new RuntimeException('Private state unavailable'); }
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent) || ($parent === $this->repoRoot || str_starts_with($parent . '/', rtrim($this->repoRoot, '/') . '/')) || (fileperms($parent) & 0077) !== 0 || fileowner($parent) !== posix_geteuid()) { throw new RuntimeException('Private state unavailable'); }
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') { throw new RuntimeException('Private state unavailable'); }
        return $parent . '/' . $name;
    }

    private function readState(): ?array
    {
        if ($this->stateFile === null) { return null; }
        clearstatcache(true, $this->stateFile);
        if (is_link($this->stateFile)) { throw new RuntimeException('Private state unavailable'); }
        if (!file_exists($this->stateFile)) { $this->stateBytesHash = null; return null; }
        if (!is_file($this->stateFile) || (fileperms($this->stateFile) & 0077) !== 0 || fileowner($this->stateFile) !== posix_geteuid()) { throw new RuntimeException('Private state unavailable'); }
        $handle = $this->stateHandle ?? @fopen($this->stateFile, 'rb');
        if (!is_resource($handle)) { throw new RuntimeException('Private state unavailable'); }
        try {
            $actual = lstat($this->stateFile); $open = fstat($handle);
            if ($actual === false || $open === false || $actual['ino'] !== $open['ino'] || $actual['dev'] !== $open['dev']) { throw new RuntimeException('Private state unavailable'); }
            @rewind($handle); $bytes = @stream_get_contents($handle, 16385);
        }
        finally { if ($this->stateHandle === null) { fclose($handle); } }
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 16384) { throw new RuntimeException('Private state unavailable'); }
        $state = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || array_is_list($state)) { throw new RuntimeException('Private state unavailable'); }
        $this->stateBytesHash = hash('sha256', $bytes);
        return $state;
    }

    private function openStateHandle(bool $new): void
    {
        if ($this->stateFile === null) { throw new RuntimeException('Private state unavailable'); }
        $handle = @fopen($this->stateFile, $new ? 'x+b' : 'r+b');
        if (!is_resource($handle)) { throw new RuntimeException('Private state unavailable'); }
        $this->stateHandle = $handle;
        if (($new && !@chmod($this->stateFile, 0600)) || !@flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Private state unavailable'); }
        if ($new) {
            // Write the bound structure intent before parsing this newly created empty file.
            $this->stateBytesHash = hash('sha256', '');
        }
    }

    private function writeState(array $state): void
    {
        if (!is_resource($this->stateHandle) || $this->stateFile === null) { throw new RuntimeException('Private state unavailable'); }
        clearstatcache(true, $this->stateFile);
        $actual = lstat($this->stateFile);
        $open = fstat($this->stateHandle);
        if ($actual === false || $open === false || is_link($this->stateFile) || $actual['ino'] !== $open['ino'] || $actual['dev'] !== $open['dev'] || (fileperms($this->stateFile) & 0077) !== 0) { throw new RuntimeException('Private state unavailable'); }
        rewind($this->stateHandle);
        $old = stream_get_contents($this->stateHandle, 16385);
        if (!is_string($old) || strlen($old) > 16384 || $this->stateBytesHash === null || !hash_equals($this->stateBytesHash, hash('sha256', $old))) { throw new RuntimeException('Private state unavailable'); }
        $bytes = self::json($state) . "\n";
        if (strlen($bytes) > 16384 || !@ftruncate($this->stateHandle, 0) || !@rewind($this->stateHandle)) { throw new RuntimeException('Private state unavailable'); }
        $offset = 0;
        while ($offset < strlen($bytes)) { $written = @fwrite($this->stateHandle, substr($bytes, $offset)); if ($written === false || $written < 1) { throw new RuntimeException('Private state unavailable'); } $offset += $written; }
        if (!@fflush($this->stateHandle) || !@fsync($this->stateHandle)) { throw new RuntimeException('Private state unavailable'); }
        $this->stateBytesHash = hash('sha256', $bytes);
    }

    private function receipt(string $mode, array $view, array $identity): array
    {
        $issues = array_merge($view['issues'], $identity['issues']);
        $state = $issues !== [] ? 'incompatible' : ($view['steps'] === [] && in_array($identity['kind'], ['bound', 'configured'], true) ? 'completed' : 'applicable');
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $issues === [] ? 0 : 2, 'changed' => $this->changed, 'applied_steps' => $this->applied, 'pending_steps' => array_column($view['steps'], 'code'), 'self_identity' => $identity['kind'], 'self_mer_id' => $identity['id'], 'enabled' => false, 'issues' => $issues, 'backfill' => 'NOT IMPLEMENTED', 'enforce' => 'NOT IMPLEMENTED', 'history_mapping' => 'UNKNOWN', 'acceptance' => 'NOT RUN'];
    }

    private function failure(string $mode, string $kind, int $exit = 1): array { return ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => $exit, 'changed' => $this->changed ? true : ($this->writeAttempted ? null : false), 'applied_steps' => $this->applied, 'issues' => [self::issue($kind, 'migration')], 'enabled' => false, 'backfill' => 'NOT IMPLEMENTED', 'enforce' => 'NOT IMPLEMENTED', 'history_mapping' => 'UNKNOWN', 'acceptance' => 'NOT RUN']; }
    private function identityIssue(string $kind): array { return ['kind' => 'invalid', 'id' => null, 'issues' => [self::issue($kind, 'self_identity')]]; }
    private static function issue(string $kind, string $target): array { return compact('kind', 'target'); }
    private function rows(string $sql, array $parameters = []): array { $statement = $this->pdo->prepare($sql); if (!$statement->execute($parameters)) { throw new RuntimeException('Operation failed'); } return $statement->fetchAll(PDO::FETCH_ASSOC); }
    private function scalar(string $sql, array $parameters = []) { $statement = $this->pdo->prepare($sql); if (!$statement->execute($parameters)) { throw new RuntimeException('Operation failed'); } return $statement->fetchColumn(); }
    private static function positiveUint($value): int { if ((!is_string($value) && !is_int($value)) || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) !== 1 || (int) $value > 4294967295) { throw new RuntimeException('Identity unavailable'); } return (int) $value; }
    private function table(string $name): string { return self::quote($this->prefix . $name); }
    private static function identifier(string $name): string { if (strlen($name) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) { throw new RuntimeException('Configuration unavailable'); } return $name; }
    private static function quote(string $name): string { return '`' . self::identifier($name) . '`'; }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}

/** Config is private input only; no values, path or connection strings are exposed. */
function marketplaceMs2Configuration(string $file): array
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) { throw new RuntimeException('Configuration unavailable'); }
    try { $raw = stream_get_contents($handle, 65537); } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 65536) { throw new RuntimeException('Configuration unavailable'); }
    $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config) || array_is_list($config) || array_diff(array_keys($config), ['hostname', 'hostport', 'database', 'username', 'password', 'prefix', 'marketplace']) !== []) { throw new RuntimeException('Configuration unavailable'); }
    foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $key) { if (!isset($config[$key]) || !is_string($config[$key])) { throw new RuntimeException('Configuration unavailable'); } }
    $port = $config['hostport'] ?? '3306';
    if ((!is_string($port) && !is_int($port)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $port) !== 1 || (int) $port > 65535 || $config['username'] === '' || preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname']) !== 1 || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) !== 1 || preg_match('/\A[A-Za-z0-9_]*\z/D', $config['prefix']) !== 1 || (array_key_exists('marketplace', $config) && (!is_array($config['marketplace']) || ($config['marketplace'] !== [] && array_is_list($config['marketplace']))))) { throw new RuntimeException('Configuration unavailable'); }
    $config['hostport'] = (string) $port;
    $config['marketplace'] = $config['marketplace'] ?? [];
    return $config;
}

function marketplaceMs2Main(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php marketplace-ms2.php [check|apply-structure] --repo-root=PATH --config-file=PRIVATE_JSON [--state-file=PRIVATE_ABSOLUTE_PATH]\nDefault check is read-only. Feature stays disabled. backfill/enforce are not implemented.\n";
        return 0;
    }
    $mode = $arguments[0] ?? 'check';
    if (in_array($mode, ['backfill', 'enforce'], true)) {
        echo json_encode(['mode' => $mode, 'state' => 'not_implemented', 'native_exit' => 2, 'changed' => false, 'issues' => [['kind' => 'command_not_implemented', 'target' => 'migration']]], JSON_UNESCAPED_SLASHES) . "\n";
        return 2;
    }
    if (!in_array($mode, ['check', 'apply-structure'], true)) { $mode = 'check'; }
    try {
        $options = [];
        $selected = false;
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply-structure'], true) && !$selected) { $mode = $argument; $selected = true; continue; }
            if (!is_string($argument) || preg_match('/\A--(repo-root|config-file|state-file)=(.+)\z/Ds', $argument, $match) !== 1 || isset($options[$match[1]])) { throw new RuntimeException('Configuration unavailable'); }
            $options[$match[1]] = $match[2];
        }
        if (!isset($options['repo-root'], $options['config-file'])) { throw new RuntimeException('Configuration unavailable'); }
        $root = realpath($options['repo-root']);
        if ($root === false || !is_file($root . '/help/migrations/marketplace-ms1.php')) { throw new RuntimeException('Configuration unavailable'); }
        require_once $root . '/help/migrations/marketplace-ms1.php';
        $config = marketplaceMs2Configuration($options['config-file']);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs2Migration($pdo, new MarketplaceMs1Migration($pdo, $config['prefix']), $config['prefix'], $root, $config['marketplace'], $options['state-file'] ?? null);
        $result = $mode === 'check' ? $migration->check() : $migration->applyStructure();
        $output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($output) > 8192) { throw new RuntimeException('Receipt unavailable'); }
        echo $output . "\n";
        return $result['native_exit'];
    } catch (Throwable $ignored) {
        echo '{"mode":"' . $mode . '","state":"incompatible","native_exit":1,"changed":false,"issues":[{"kind":"configuration_or_connection_failed","target":"migration"}],"acceptance":"NOT RUN"}' . "\n";
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { exit(marketplaceMs2Main(array_slice($argv, 1))); }
