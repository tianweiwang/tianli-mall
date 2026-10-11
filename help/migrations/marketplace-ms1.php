<?php
declare(strict_types=1);

namespace Audit\Migration;

use PDO;
use RuntimeException;
use Throwable;

/** Only the safe receipt is exposed; the underlying driver error is never retained. */
final class MarketplaceMs1MigrationFailure extends RuntimeException
{
    private array $receipt;

    public function __construct(array $receipt)
    {
        parent::__construct('Marketplace migration failed');
        $this->receipt = $receipt;
    }

    public function receipt(): array
    {
        return $this->receipt;
    }
}

final class MarketplaceMs1Migration
{
    private const MARKER = 'marketplace-ms1-v1';
    private const TARGETS = ['marketplace_merchant', 'marketplace_role', 'marketplace_employee', 'marketplace_audit_log', 'marketplace_password_setup'];
    private PDO $pdo;
    private string $prefix;
    private string $database;

    public function __construct(PDO $pdo, string $prefix = 'eb_')
    {
        if (!preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix)) {
            throw new RuntimeException('Invalid migration configuration');
        }
        foreach (array_merge(self::TARGETS, ['system_menus', 'mm_setup_employee_fk', 'mm_role_permissions']) as $name) {
            self::identifier($prefix . $name);
        }
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || !$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION) || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) {
            throw new RuntimeException('Invalid migration configuration');
        }
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Invalid migration configuration');
        }
        $this->database = $database;
    }

    public function check(): array
    {
        try {
            return $this->inspect('check');
        } catch (Throwable $error) {
            throw new MarketplaceMs1MigrationFailure($this->failure('check', 'inspection_failed', []));
        }
    }

    public function apply(): array
    {
        $locked = false;
        $failed = false;
        $ownsTransaction = false;
        $created = [];
        $phase = 'precheck_failed';
        $lockName = 'marketplace-ms1:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
        try {
            if ($this->pdo->inTransaction()) {
                return $this->result('apply', 'incompatible', [], [self::issue('active_transaction', 'migration')]);
            }
            $before = $this->inspect('apply');
            if ($before['state'] === 'incompatible') {
                return $before;
            }
            $phase = 'lock_failed';
            $lock = $this->rows('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (count($lock) !== 1 || (string) $lock[0]['acquired'] !== '1') {
                throw new RuntimeException('Migration lock unavailable');
            }
            $locked = true;
            $phase = 'recheck_failed';
            $current = $this->inspect('apply');
            if ($current['state'] !== 'applicable') {
                return $current;
            }
            $phase = 'create_failed';
            foreach ($this->definitions() as $name => $definition) {
                if ($this->pdo->exec($definition['ddl']) === false) {
                    throw new RuntimeException('Create failed');
                }
                $created[] = $this->table($name);
            }
            $phase = 'menu_insert_failed';
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Transaction unavailable');
            }
            $ownsTransaction = true;
            $menuRows = self::menuRows();
            $columns = array_keys($menuRows[0]);
            $sql = 'INSERT INTO ' . self::quote($this->table('system_menus')) . ' (' . implode(', ', array_map([self::class, 'quote'], $columns)) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $insert = $this->pdo->prepare($sql);
            $inserted = [];
            foreach ($menuRows as $row) {
                if (!$insert->execute(array_values($row))) {
                    throw new RuntimeException('Menu insert failed');
                }
                $id = $this->pdo->lastInsertId();
                if (!is_string($id) || !preg_match('/\A[1-9][0-9]*\z/D', $id) || strlen($id) > 5 || (int) $id > 65535) {
                    throw new RuntimeException('Menu identity unavailable');
                }
                $inserted[] = (int) $id;
            }
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Menu commit failed');
            }
            $ownsTransaction = false;
            $phase = 'postcheck_failed';
            $after = $this->inspect('apply');
            if ($after['state'] !== 'completed' || $after['menu_ids'] !== $inserted) {
                throw new RuntimeException('Postcheck failed');
            }
            $after['changed'] = true;
            $after['created_tables'] = $created;
            return $after;
        } catch (Throwable $error) {
            $failed = true;
            if ($ownsTransaction) {
                try { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } } catch (Throwable $ignored) { /* No DDL rollback is claimed. */ }
            }
            throw new MarketplaceMs1MigrationFailure($this->failure('apply', $phase, $created));
        } finally {
            if ($locked) {
                try {
                    $released = $this->rows('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                    if (count($released) !== 1 || (string) $released[0]['released'] !== '1') {
                        throw new RuntimeException('Lock release failed');
                    }
                } catch (Throwable $error) {
                    if (!$failed) {
                        throw new MarketplaceMs1MigrationFailure($this->failure('apply', 'lock_release_failed', $created));
                    }
                }
            }
        }
    }

    private function inspect(string $mode): array
    {
        $issues = [];
        $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($version) || !preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            $issues[] = self::issue('mysql_version', 'migration');
            return $this->result($mode, 'incompatible', [], $issues);
        }
        $menus = $this->metadata($this->table('system_menus'));
        $menuCompatible = $menus !== null && $this->menusCompatible($menus);
        if (!$menuCompatible) {
            $issues[] = self::issue($menus === null ? 'installed_menu_missing' : 'installed_menu_schema', $this->table('system_menus'));
        }
        $present = [];
        foreach ($this->definitions() as $name => $definition) {
            $table = $this->table($name);
            $metadata = $this->metadata($table);
            if ($metadata === null) { continue; }
            $present[] = $table;
            foreach ($this->schemaIssues($table, $metadata, $definition) as $issue) { $issues[] = $issue; }
        }
        $menuIds = [];
        $absent = 0;
        if ($menuCompatible) {
            $registered = $this->rows('SELECT * FROM ' . self::quote($this->table('system_menus')) . ' ORDER BY id', []);
            foreach (self::menuRows() as $expected) {
                $matches = $this->matchingMenus($expected, $registered);
                if ($matches === []) { ++$absent; continue; }
                if (count($matches) !== 1 || !$this->sameMenu($matches[0], $expected)) {
                    $issues[] = self::issue('menu_conflict', $expected['unique_auth']);
                } else {
                    $menuIds[] = (int) $matches[0]['id'];
                }
            }
        }
        if ($issues === [] && $present === [] && $absent === 7) {
            return $this->result($mode, 'applicable', [], []);
        }
        if ($issues === [] && count($present) === 5 && count($menuIds) === 7 && $absent === 0) {
            return $this->result($mode, 'completed', $menuIds, []);
        }
        if (count($present) !== 0 && count($present) !== 5) {
            $issues[] = self::issue('partial_tables', 'migration');
        }
        if ($menuCompatible && $absent !== 0 && $absent !== 7) {
            $issues[] = self::issue('partial_menus', $this->table('system_menus'));
        }
        if ($issues === []) {
            $issues[] = self::issue('partial_resources', 'migration');
        }
        $result = $this->result($mode, 'incompatible', $menuIds, $issues);
        $result['partial_resources'] = ['tables' => $present, 'menu_ids' => $menuIds];
        return $result;
    }

    private function metadata(string $table): ?array
    {
        $tables = $this->rows('SELECT TABLE_TYPE, ENGINE, TABLE_COLLATION, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->database, $table]);
        if ($tables === []) { return null; }
        if (count($tables) !== 1) { throw new RuntimeException('Ambiguous metadata'); }
        return [
            'table' => $tables[0],
            'columns' => $this->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->database, $table]),
            'indexes' => $this->rows('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, IS_VISIBLE, EXPRESSION, COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$this->database, $table]),
            'foreign' => $this->rows('SELECT rc.CONSTRAINT_NAME, rc.UPDATE_RULE, rc.DELETE_RULE, rc.MATCH_OPTION, kcu.ORDINAL_POSITION, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_SCHEMA, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME = rc.TABLE_NAME AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA = ? AND rc.TABLE_NAME = ? ORDER BY rc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION', [$this->database, $table]),
            'checks' => $this->rows("SELECT tc.CONSTRAINT_NAME, tc.ENFORCED, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK' ORDER BY tc.CONSTRAINT_NAME", [$this->database, $table]),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [$this->database, $table]),
        ];
    }

    private function schemaIssues(string $table, array $actual, array $expected): array
    {
        $issues = [];
        $meta = $actual['table'];
        if ($meta['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $meta['ENGINE'], 'InnoDB') !== 0 || $meta['TABLE_COLLATION'] !== 'utf8mb4_bin' || $meta['TABLE_COMMENT'] !== self::MARKER) {
            $issues[] = self::issue('table_metadata', $table);
        }
        if (!$this->sameColumns($actual['columns'], $expected['columns'], 'utf8mb4', 'utf8mb4_bin')) {
            $issues[] = self::issue('columns', $table);
        }
        if (!$this->sameIndexes($actual['indexes'], $expected['indexes'])) { $issues[] = self::issue('indexes', $table); }
        if (!$this->sameForeign($actual['foreign'], $expected['foreign'])) { $issues[] = self::issue('foreign_keys', $table); }
        $checks = [];
        foreach ($actual['checks'] as $check) {
            $name = $check['CONSTRAINT_NAME'];
            $tree = self::checkMetadataExpression((string) $check['CHECK_CLAUSE']);
            if (isset($checks[$name]) || $check['ENFORCED'] !== 'YES' || $tree === null) {
                $issues[] = self::issue('checks', $table);
                return $issues;
            }
            $checks[$name] = $tree;
        }
        $wanted = [];
        foreach ($expected['checks'] as $name => $expression) { $wanted[$this->prefix . $name] = self::checkExpression($expression); }
        ksort($checks); ksort($wanted);
        if ($checks !== $wanted) { $issues[] = self::issue('checks', $table); }
        if ($actual['triggers'] !== []) { $issues[] = self::issue('triggers', $table); }
        return $issues;
    }

    private function menusCompatible(array $actual): bool
    {
        $table = $actual['table'];
        if ($table['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0 || !is_string($table['TABLE_COLLATION']) || strpos($table['TABLE_COLLATION'], 'utf8mb4_') !== 0) { return false; }
        $columns = [
            self::column('id', 'smallint unsigned', false, null, true), self::column('pid', 'smallint unsigned', false, '0'),
            self::column('icon', 'varchar(16)', false, ''), self::column('menu_name', 'varchar(32)', false, ''),
            self::column('module', 'varchar(32)', false, '', false, 'utf8mb3', 'utf8mb3_bin'),
            self::column('controller', 'varchar(64)', false, '', false, 'utf8mb3', 'utf8mb3_bin'),
            self::column('action', 'varchar(32)', false, '', false, 'utf8mb3', 'utf8mb3_bin'),
            self::column('api_url', 'varchar(100)', false, ''), self::column('methods', 'varchar(10)', false, ''),
            self::column('params', 'varchar(128)', false, '[]', false, 'utf8mb3', 'utf8mb3_bin'),
            self::column('sort', 'tinyint', false, '1'), self::column('is_show', 'tinyint unsigned', false, '1'),
            self::column('is_show_path', 'tinyint', false, '0'), self::column('access', 'tinyint unsigned', false, '1'),
            self::column('menu_path', 'varchar(128)', false, ''), self::column('path', 'varchar(255)', false, ''),
            self::column('auth_type', 'tinyint', false, '0'), self::column('header', 'varchar(50)', false, ''),
            self::column('is_header', 'tinyint', false, '0'), self::column('unique_auth', 'varchar(150)', false, ''),
            self::column('is_del', 'tinyint', false, '0'), self::column('mark', 'varchar(500)', false, ''),
        ];
        return $this->sameColumns($actual['columns'], $columns, 'utf8mb4', $table['TABLE_COLLATION'])
            && $this->sameIndexes($actual['indexes'], ['PRIMARY' => [true, ['id']], 'pid' => [false, ['pid']], 'is_show' => [false, ['is_show']], 'access' => [false, ['access']]])
            && $actual['foreign'] === [] && $actual['checks'] === [] && $actual['triggers'] === [];
    }

    private function sameColumns(array $actual, array $expected, string $charset, string $collation): bool
    {
        if (count($actual) !== count($expected)) { return false; }
        foreach ($expected as $offset => $column) {
            $value = $actual[$offset];
            $type = strtolower((string) $value['COLUMN_TYPE']);
            $type = preg_replace('/\b(tinyint|smallint|int)\([0-9]+\)/', '$1', $type);
            $isText = strpos($column['type'], 'varchar(') === 0;
            $wantedCharset = $isText ? ($column['charset'] ?? $charset) : null;
            $wantedCollation = $isText ? ($column['collation'] ?? $collation) : null;
            $valueCharset = $value['CHARACTER_SET_NAME'] === 'utf8' ? 'utf8mb3' : $value['CHARACTER_SET_NAME'];
            $valueCollation = $value['COLLATION_NAME'] === 'utf8_bin' ? 'utf8mb3_bin' : $value['COLLATION_NAME'];
            $default = $value['COLUMN_DEFAULT'] === null ? null : (string) $value['COLUMN_DEFAULT'];
            if ($value['COLUMN_NAME'] !== $column['name'] || $type !== $column['type'] || $value['IS_NULLABLE'] !== ($column['nullable'] ? 'YES' : 'NO') || $default !== $column['default'] || $valueCharset !== $wantedCharset || $valueCollation !== $wantedCollation || $value['EXTRA'] !== ($column['auto'] ? 'auto_increment' : '') || (string) $value['GENERATION_EXPRESSION'] !== '') { return false; }
        }
        return true;
    }

    private function sameIndexes(array $rows, array $expected): bool
    {
        $actual = [];
        foreach ($rows as $row) {
            if ($row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES' || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A' || !in_array((string) $row['NON_UNIQUE'], ['0', '1'], true)) { return false; }
            $name = $row['INDEX_NAME'];
            if (!isset($actual[$name])) { $actual[$name] = [(string) $row['NON_UNIQUE'] === '0', []]; }
            if ($actual[$name][0] !== ((string) $row['NON_UNIQUE'] === '0') || (int) $row['SEQ_IN_INDEX'] !== count($actual[$name][1]) + 1) { return false; }
            $actual[$name][1][] = $row['COLUMN_NAME'];
        }
        ksort($actual); ksort($expected);
        return $actual === $expected;
    }

    private function sameForeign(array $rows, array $expected): bool
    {
        $actual = [];
        foreach ($rows as $row) {
            if ($row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT' || $row['MATCH_OPTION'] !== 'NONE' || $row['REFERENCED_TABLE_SCHEMA'] !== $this->database) { return false; }
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($actual[$name])) { $actual[$name] = [$row['REFERENCED_TABLE_NAME'], [], []]; }
            if ($actual[$name][0] !== $row['REFERENCED_TABLE_NAME'] || (int) $row['ORDINAL_POSITION'] !== count($actual[$name][1]) + 1) { return false; }
            $actual[$name][1][] = $row['COLUMN_NAME'];
            $actual[$name][2][] = $row['REFERENCED_COLUMN_NAME'];
        }
        $wanted = [];
        foreach ($expected as $name => $fk) { $wanted[$this->prefix . $name] = [$this->table($fk[0]), $fk[1], $fk[2]]; }
        ksort($actual); ksort($wanted);
        return $actual === $wanted;
    }

    private function matchingMenus(array $expected, array $registered): array
    {
        // Preserve the installed column's equality semantics for permission collisions,
        // and PHP trim/strtolower/space removal exactly as verifyAuth uses for routes.
        $matches = [];
        foreach ($this->rows('SELECT * FROM ' . self::quote($this->table('system_menus')) . ' WHERE unique_auth = ? ORDER BY id', [$expected['unique_auth']]) as $row) {
            $matches[(string) $row['id']] = $row;
        }
        foreach ($registered as $row) {
            if (trim(strtolower((string) $row['methods'])) === strtolower($expected['methods']) && trim(strtolower(str_replace(' ', '', (string) $row['api_url']))) === strtolower($expected['api_url'])) {
                $matches[(string) $row['id']] = $row;
            }
        }
        return array_values($matches);
    }

    private function sameMenu(array $actual, array $expected): bool
    {
        $id = (string) ($actual['id'] ?? '');
        if (!preg_match('/\A[1-9][0-9]*\z/D', $id) || strlen($id) > 5 || (int) $id > 65535 || count($actual) !== 22) { return false; }
        foreach ($expected as $name => $value) {
            if (!array_key_exists($name, $actual) || $actual[$name] === null || (string) $actual[$name] !== (string) $value) { return false; }
        }
        return true;
    }

    private static function menuRows(): array
    {
        $common = ['pid' => 0, 'icon' => '', 'module' => '', 'controller' => '', 'action' => '', 'params' => '[]', 'sort' => 1, 'is_show' => 1, 'is_show_path' => 1, 'access' => 1, 'menu_path' => '', 'path' => '', 'auth_type' => 2, 'header' => '', 'is_header' => 0, 'is_del' => 0, 'mark' => self::MARKER];
        $leaves = [
            ['商家列表', 'GET', 'marketplace/merchants', 'marketplace-merchants-list'],
            ['新建商家', 'POST', 'marketplace/merchants', 'marketplace-merchants-create'],
            ['商家详情', 'GET', 'marketplace/merchants/<id>', 'marketplace-merchants-read'],
            ['修改商家', 'PUT', 'marketplace/merchants/<id>', 'marketplace-merchants-update'],
            ['审核商家', 'PUT', 'marketplace/merchants/<id>/review', 'marketplace-merchants-review'],
            ['商家开关', 'PUT', 'marketplace/merchants/<id>/flags', 'marketplace-merchants-flags'],
            ['商家管理员', 'POST', 'marketplace/merchants/<id>/admin-employee', 'marketplace-merchants-admin-employee'],
        ];
        return array_map(static fn(array $leaf): array => $common + ['menu_name' => $leaf[0], 'methods' => $leaf[1], 'api_url' => $leaf[2], 'unique_auth' => $leaf[3]], $leaves);
    }

    private function definitions(): array
    {
        $uint = static fn(string $name, bool $nullable = false, ?string $default = null, bool $auto = false): array => self::column($name, 'int unsigned', $nullable, $default, $auto);
        $text = static fn(string $name, int $length, bool $nullable = false, ?string $default = null): array => self::column($name, 'varchar(' . $length . ')', $nullable, $default);
        $flag = static fn(string $name, string $default): array => self::column($name, 'tinyint unsigned', false, $default);
        $definitions = [
            'marketplace_merchant' => [
                'columns' => [$uint('mer_id', false, null, true), $text('name', 128), $text('logo', 512, false, ''), $text('after_sale_contact', 255, false, ''), $text('shipping_note', 1000, false, ''), $flag('review_status', '0'), $flag('accept_orders', '0'), $flag('fulfillment_enabled', '1'), $flag('security_locked', '0'), $uint('closed_at', true), $uint('add_time'), $uint('update_time')],
                'indexes' => ['PRIMARY' => [true, ['mer_id']], 'idx_review_mer' => [false, ['review_status', 'mer_id']]],
                'checks' => ['mm_mer_review' => '`review_status` IN (0, 1, 2)', 'mm_mer_flags' => '`accept_orders` IN (0, 1) AND `fulfillment_enabled` IN (0, 1) AND `security_locked` IN (0, 1)'],
                'foreign' => [],
            ],
            'marketplace_role' => [
                'columns' => [$uint('id', false, null, true), $uint('mer_id'), $text('name', 64), self::column('permissions', 'json'), $uint('add_time'), $uint('update_time')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'uq_mer_role_id' => [true, ['mer_id', 'id']], 'uq_mer_role_name' => [true, ['mer_id', 'name']]],
                'checks' => ['mm_role_positive' => '`mer_id` > 0', 'mm_role_permissions' => "JSON_TYPE(`permissions`) = 'ARRAY'"],
                'foreign' => ['mm_role_merchant_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id']]],
            ],
            'marketplace_employee' => [
                'columns' => [$uint('id', false, null, true), $uint('mer_id'), $uint('role_id'), $text('account', 191), $text('pwd', 255, true), $text('name', 64, false, ''), $text('avatar', 512, false, ''), $flag('status', '1'), $flag('is_del', '0'), $uint('add_time'), $uint('update_time')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'uq_employee_account' => [true, ['account']], 'uq_mer_employee_id' => [true, ['mer_id', 'id']], 'idx_mer_employee_role' => [false, ['mer_id', 'role_id']]],
                'checks' => ['mm_emp_positive' => '`mer_id` > 0 AND `role_id` > 0', 'mm_emp_flags' => '`status` IN (0, 1) AND `is_del` IN (0, 1)'],
                'foreign' => ['mm_emp_role_fk' => ['marketplace_role', ['mer_id', 'role_id'], ['mer_id', 'id']]],
            ],
            'marketplace_audit_log' => [
                'columns' => [$uint('id', false, null, true), $uint('mer_id'), $text('actor_type', 16), $uint('actor_id'), $text('action', 100), $text('resource_type', 64), $text('resource_id', 64), $text('result', 16), self::column('detail', 'json'), $uint('add_time')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'idx_mer_audit_id' => [false, ['mer_id', 'id']]],
                'checks' => ['mm_audit_positive' => '`mer_id` > 0 AND `actor_id` > 0', 'mm_audit_detail' => "JSON_TYPE(`detail`) = 'OBJECT'"],
                'foreign' => ['mm_audit_merchant_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id']]],
            ],
            'marketplace_password_setup' => [
                'columns' => [$uint('id', false, null, true), $uint('mer_id'), $uint('employee_id'), self::column('token_hash', 'binary(32)'), $uint('expires_at'), $uint('consumed_at', true), $uint('add_time')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'uq_setup_hash' => [true, ['token_hash']], 'idx_mer_setup_employee' => [false, ['mer_id', 'employee_id']]],
                'checks' => ['mm_setup_positive' => '`mer_id` > 0 AND `employee_id` > 0', 'mm_setup_expiry' => '`expires_at` = `add_time` + 86400'],
                'foreign' => ['mm_setup_employee_fk' => ['marketplace_employee', ['mer_id', 'employee_id'], ['mer_id', 'id']]],
            ],
        ];
        foreach ($definitions as $name => &$definition) {
            $parts = [];
            foreach ($definition['columns'] as $column) {
                $sql = self::quote($column['name']) . ' ' . strtoupper($column['type']) . ($column['nullable'] ? ' NULL' : ' NOT NULL');
                if ($column['auto']) { $sql .= ' AUTO_INCREMENT'; }
                elseif ($column['default'] !== null) { $sql .= ' DEFAULT ' . (strpos($column['type'], 'varchar(') === 0 ? $this->pdo->quote($column['default']) : $column['default']); }
                elseif ($column['nullable']) { $sql .= ' DEFAULT NULL'; }
                $parts[] = $sql;
            }
            foreach ($definition['indexes'] as $index => [$unique, $columns]) {
                $parts[] = ($index === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . self::quote($index)) . ' (' . implode(', ', array_map([self::class, 'quote'], $columns)) . ')';
            }
            foreach ($definition['checks'] as $constraint => $expression) {
                $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . $constraint) . ' CHECK (' . $expression . ')';
            }
            foreach ($definition['foreign'] as $constraint => [$target, $columns, $referenced]) {
                $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . $constraint) . ' FOREIGN KEY (' . implode(', ', array_map([self::class, 'quote'], $columns)) . ') REFERENCES ' . self::quote($this->table($target)) . ' (' . implode(', ', array_map([self::class, 'quote'], $referenced)) . ') ON UPDATE RESTRICT ON DELETE RESTRICT';
            }
            $definition['ddl'] = 'CREATE TABLE ' . self::quote($this->table($name)) . " (\n  " . implode(",\n  ", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='" . self::MARKER . "'";
        }
        unset($definition);
        return $definitions;
    }

    /** Decode only MySQL metadata's escaped delimiters for our two fixed literals. */
    private static function checkMetadataExpression(string $expression): ?array
    {
        $delimiter = "\\'";
        foreach (['ARRAY', 'OBJECT'] as $literal) {
            $expression = str_replace('_utf8mb4' . $delimiter . $literal . $delimiter, "_utf8mb4'" . $literal . "'", $expression);
        }
        return self::checkExpression($expression);
    }

    /** Parse only the frozen CHECK grammar. Grouping changes the tree, not just text. */
    private static function checkExpression(string $expression): ?array
    {
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (!preg_match("/\\G(?:\\s+|`([A-Za-z0-9_]+)`|('(?:[^'\\\\]|'')*')|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+)|([(),+>=]))/A", $expression, $match, 0, $offset)) { return null; }
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') { continue; }
            if (($match[1] ?? '') !== '') { $tokens[] = ['id', strtolower($match[1])]; }
            elseif (($match[2] ?? '') !== '') { $tokens[] = ['string', str_replace("''", "'", substr($match[2], 1, -1))]; }
            elseif (($match[3] ?? '') !== '') { $tokens[] = ['word', strtolower($match[3])]; }
            elseif (($match[4] ?? '') !== '') { $tokens[] = ['number', $match[4]]; }
            else { $tokens[] = ['symbol', $match[5]]; }
        }
        $position = 0;
        $peek = static function (string $value) use (&$tokens, &$position): bool { return isset($tokens[$position]) && $tokens[$position][1] === $value && in_array($tokens[$position][0], ['word', 'symbol'], true); };
        $take = static function (string $value) use (&$position, $peek): bool { if (!$peek($value)) { return false; } ++$position; return true; };
        $and = $comparison = $addition = $primary = null;
        $primary = static function () use (&$tokens, &$position, &$and, $take): array {
            if ($take('(')) {
                $node = $and();
                if (!$take(')')) { throw new RuntimeException('Check grammar'); }
                return $node;
            }
            if (!isset($tokens[$position])) { throw new RuntimeException('Check grammar'); }
            $token = $tokens[$position++];
            if ($token === ['word', '_utf8mb4']) {
                $token = $tokens[$position++] ?? [];
                if (($token[0] ?? '') !== 'string') { throw new RuntimeException('Check grammar'); }
            }
            if ($token[0] === 'string' || $token[0] === 'number') { return $token; }
            if ($token[0] !== 'id' && $token[0] !== 'word') { throw new RuntimeException('Check grammar'); }
            if ($take('(')) {
                if ($token[1] !== 'json_type') { throw new RuntimeException('Check grammar'); }
                $argument = $and();
                if (!$take(')')) { throw new RuntimeException('Check grammar'); }
                return ['function', 'json_type', $argument];
            }
            return ['id', $token[1]];
        };
        $addition = static function () use (&$primary, $take): array {
            $node = $primary();
            while ($take('+')) { $node = ['+', $node, $primary()]; }
            return $node;
        };
        $comparison = static function () use (&$addition, $take): array {
            $node = $addition();
            foreach (['=', '>'] as $operator) {
                if ($take($operator)) { return [$operator, $node, $addition()]; }
            }
            if ($take('in')) {
                if (!$take('(')) { throw new RuntimeException('Check grammar'); }
                $values = [$addition()];
                while ($take(',')) { $values[] = $addition(); }
                if (!$take(')')) { throw new RuntimeException('Check grammar'); }
                return ['in', $node, $values];
            }
            return $node;
        };
        $and = static function () use (&$comparison, $take): array {
            $nodes = [$comparison()];
            while ($take('and')) { $nodes[] = $comparison(); }
            if (count($nodes) === 1) { return $nodes[0]; }
            $flat = [];
            foreach ($nodes as $node) { foreach ($node[0] === 'and' ? $node[1] : [$node] as $part) { $flat[] = $part; } }
            return ['and', $flat];
        };
        try {
            $tree = $and();
            return $position === count($tokens) ? $tree : null;
        } catch (Throwable $error) { return null; }
    }

    private static function column(string $name, string $type, bool $nullable = false, ?string $default = null, bool $auto = false, ?string $charset = null, ?string $collation = null): array
    {
        return compact('name', 'type', 'nullable', 'default', 'auto', 'charset', 'collation');
    }

    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement->execute($parameters)) { throw new RuntimeException('Inspection failed'); }
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function result(string $mode, string $state, array $menuIds, array $issues): array
    {
        return ['mode' => $mode, 'state' => $state, 'changed' => false, 'tables' => array_map(fn(string $name): string => $this->table($name), self::TARGETS), 'menu_ids' => $menuIds, 'issues' => $issues];
    }

    private function failure(string $mode, string $kind, array $created): array
    {
        $present = [];
        $menuIds = [];
        $issues = [self::issue($kind, 'migration')];
        try {
            foreach (self::TARGETS as $name) {
                $table = $this->table($name);
                if ($this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->database, $table]) !== []) { $present[] = $table; }
            }
        } catch (Throwable $error) { $issues[] = self::issue('partial_inspection_failed', 'migration'); }
        try {
            $metadata = $this->metadata($this->table('system_menus'));
            if ($metadata !== null && $this->menusCompatible($metadata)) {
                $registered = $this->rows('SELECT * FROM ' . self::quote($this->table('system_menus')) . ' ORDER BY id', []);
                foreach (self::menuRows() as $expected) {
                    $matches = $this->matchingMenus($expected, $registered);
                    if (count($matches) === 1 && $this->sameMenu($matches[0], $expected)) { $menuIds[] = (int) $matches[0]['id']; }
                }
            }
        } catch (Throwable $error) { $issues[] = self::issue('menu_inspection_failed', $this->table('system_menus')); }
        $result = $this->result($mode, 'incompatible', $menuIds, $issues);
        $result['changed'] = $created !== [];
        $result['created_tables'] = $created;
        $result['partial_resources'] = ['tables' => $present, 'menu_ids' => $menuIds];
        return $result;
    }

    private static function issue(string $kind, string $path): array { return compact('kind', 'path'); }
    private function table(string $name): string { return self::identifier($this->prefix . $name); }
    private static function identifier(string $name): string
    {
        if (strlen($name) > 64 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $name)) { throw new RuntimeException('Invalid migration configuration'); }
        return $name;
    }
    private static function quote(string $name): string { return '`' . self::identifier($name) . '`'; }
}

function marketplaceMs1Configuration(?string $file): array
{
    if ($file !== null) {
        $stream = @fopen($file, 'rb');
        if ($stream === false) { throw new RuntimeException('Configuration unavailable'); }
        try { $raw = @stream_get_contents($stream, 65537); } finally { fclose($stream); }
        if (!is_string($raw) || strlen($raw) > 65536) { throw new RuntimeException('Configuration unavailable'); }
        $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($config) || array_diff(array_keys($config), ['hostname', 'hostport', 'database', 'username', 'password', 'prefix']) !== []) { throw new RuntimeException('Configuration unavailable'); }
    } else {
        $env = @parse_ini_file(dirname(__DIR__, 2) . '/crmeb/.env', true, INI_SCANNER_RAW);
        if (!is_array($env)) { throw new RuntimeException('Configuration unavailable'); }
        $env = array_change_key_case($env, CASE_LOWER);
        $config = $env['database'] ?? null;
        if (!is_array($config)) { throw new RuntimeException('Configuration unavailable'); }
        $config = array_change_key_case($config, CASE_LOWER);
        $config += ['hostport' => '3306', 'prefix' => 'eb_'];
    }
    foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $name) {
        if (!array_key_exists($name, $config) || !is_string($config[$name])) { throw new RuntimeException('Configuration unavailable'); }
    }
    $port = $config['hostport'] ?? null;
    if ((!is_string($port) && !is_int($port)) || !preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $port) || (int) $port > 65535 || $config['username'] === '' || !preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname']) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) || !preg_match('/\A[A-Za-z0-9_]*\z/D', $config['prefix'])) { throw new RuntimeException('Configuration unavailable'); }
    $config['hostport'] = (string) $port;
    return $config;
}

function marketplaceMs1Main(array $arguments): int
{
    if ($arguments === ['--help'] || $arguments === ['help']) {
        echo "Usage: php help/migrations/marketplace-ms1.php [check|apply|--check|--apply] [--config-file=PATH]\nDefault: read-only check; connection and prefix are read from local crmeb/.env in memory.\n";
        return 0;
    }
    $mode = 'check';
    $selected = false;
    $file = null;
    $migration = null;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply', '--check', '--apply'], true) && !$selected) {
                $mode = ltrim($argument, '-'); $selected = true;
            } elseif (strpos($argument, '--config-file=') === 0 && $file === null && strlen($argument) > 14) {
                $file = substr($argument, 14);
            } else { throw new RuntimeException('CLI configuration unavailable'); }
        }
        $config = marketplaceMs1Configuration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs1Migration($pdo, $config['prefix']);
        $result = $mode === 'apply' ? $migration->apply() : $migration->check();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return $result['state'] === 'incompatible' ? 2 : 0;
    } catch (MarketplaceMs1MigrationFailure $error) {
        echo json_encode($error->receipt(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 1;
    } catch (Throwable $error) {
        echo '{"mode":"' . $mode . '","state":"incompatible","changed":false,"tables":[],"menu_ids":[],"issues":[{"kind":"configuration_or_connection_failed","path":"migration"}]}' . "\n";
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(marketplaceMs1Main(array_slice($argv, 1)));
}
