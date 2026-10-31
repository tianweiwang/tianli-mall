<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace-ms5-payment.php';
require_once __DIR__ . '/marketplace-ms4-aftersale.php';

/** Additive test-refund intent structure and one explicit operation permission; no business or old grant writes. */
final class MarketplaceMs5RefundMigration
{
    public const MARKER = 'marketplace-ms5-refund-structure-v1';
    private bool $writeAttempted = false;
    private array $applied = [];
    private string $database;

    public function __construct(private PDO $pdo, private string $prefix, string $database)
    {
        self::quote($database);
        foreach (array_keys(self::newTables()) as $name) self::quote($prefix . $name);
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || PHP_INT_SIZE < 8
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)
            || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Migration configuration unavailable');
        }
        $this->database = $database;
    }

    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    /** One immutable intent per real aftersale refund; native payment/refund identities are never reborn here. */
    public static function newTables(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        return [
            'marketplace_refund_attempt' => [
                'columns' => [
                    'id' => $c('bigint unsigned', '原生测试退款意图主键', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', '独立测试退款合同版本'),
                    'refund_id' => $c('int', '唯一原生售后申请主键'),
                    'root_order_id' => $c('int unsigned', '原商业根订单主键'),
                    'uid' => $c('int unsigned', '原订单顾客编号'),
                    'mer_id' => $c('int unsigned', '原订单商家编号'),
                    'payment_attempt_id' => $c('bigint unsigned', '已验证原付款成功尝试主键'),
                    'approval_action_id' => $c('bigint unsigned', '已提交批准售后动作主键'),
                    'idem_key' => $c('varchar(128)', '原测试退款请求幂等键', false, null, 'ascii'),
                    'request_digest' => $c('binary(32)', '完整规范测试退款请求SHA256原始摘要'),
                    'binding_digest' => $c('binary(32)', '原退款驱动绑定SHA256原始摘要'),
                    'account_domain_digest' => $c('binary(32)', '原测试账户域SHA256原始摘要'),
                    'account_snapshot' => $c('json', '原付款测试账户完整不可变快照'),
                    'amount' => $c('decimal(12,2) unsigned', '本申请剩余全额精确退款金额元'),
                    'currency' => $c('char(3)', '原付款币种完整快照', false, null, 'ascii'),
                    'attempt_ref' => $c('varchar(45)', '实际驱动返回的退款尝试引用', true, null, 'ascii'),
                    'applied_refund_no' => $c('varchar(45)', '仅已应用成功的原测试退款引用', true, null, 'ascii'),
                    'evidence' => $c('json', '完整真实create及query观察应用事实'),
                    'created_at' => $c('int unsigned', '原测试退款意图本地登记秒'),
                    'updated_at' => $c('int unsigned', '最后真实退款观察本地登记秒'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_ms5_refund_refund' => [true, ['refund_id']],
                    'idx_ms5_refund_root' => [false, ['root_order_id', 'id']],
                    'idx_ms5_refund_payment' => [false, ['payment_attempt_id', 'id']],
                    'uq_ms5_refund_account_attempt' => [true, ['account_domain_digest', 'attempt_ref']],
                    'uq_ms5_refund_account_number' => [true, ['account_domain_digest', 'applied_refund_no']],
                ],
            ],
        ];
    }

    /** Operation-local read-only gate. It never configures PDO, starts/ends a transaction, or invokes old migrations. */
    public static function metadataGate(PDO $pdo, string $database, string $prefix): bool
    {
        try {
            $migration = new self($pdo, $prefix, $database);
            $meta = $migration->metadata('marketplace_refund_attempt');
            return $meta !== null && $migration->sameNewTable($meta, self::newTables()['marketplace_refund_attempt']);
        } catch (Throwable $error) {
            return false;
        }
    }

    /** Read-only final structure verification; an absent table is not a completed migration. */
    public function verify(): array
    {
        $result = $this->check();
        $result['mode'] = 'verify';
        if ($result['state'] === 'applicable') {
            $result['state'] = 'incompatible';
            $result['native_exit'] = 2;
            $result['issues'][] = self::issue('refund_schema_not_completed', 'marketplace_refund_attempt');
        }
        return $result;
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
        $name = 'ms5-refund:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
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
        if ($this->scalar('SELECT DATABASE()') !== $this->database) $issues[] = self::issue('database_changed', 'migration');
        if ($issues !== []) return compact('issues', 'steps');
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
        foreach ([
            'ms5_payment' => new MarketplaceMs5PaymentMigration($this->pdo, $this->prefix, $this->database),
            'ms4_aftersale' => new MarketplaceMs4AftersaleMigration($this->pdo, $this->prefix, $this->database),
        ] as $name => $prerequisite) {
            $receipt = $prerequisite->check();
            if (($receipt['native_exit'] ?? null) === 1) throw new RuntimeException('Prerequisite inspection unavailable');
            if (($receipt['state'] ?? null) !== 'completed') $issues[] = self::issue($name . '_schema_not_completed', 'migration');
        }
        foreach (self::newTables() as $name => $definition) {
            $meta = $this->metadata($name);
            if ($meta === null) $steps[] = ['code' => $name, 'sql' => $this->tableDdl($name, $definition)];
            elseif (!$this->sameNewTable($meta, $definition)) $issues[] = self::issue('new_table_conflict', $name);
        }
        return compact('issues', 'steps');
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
            || array_keys($meta['columns']) !== array_keys($contract['columns'])
            || $meta['foreign'] !== [] || $meta['checks'] !== []) return false;
        foreach ($contract['columns'] as $name => $expected) {
            $collation = $expected['charset'] === 'ascii' ? 'ascii_bin' : null;
            if (!$this->sameColumn($meta['columns'][$name], $expected, $collation, true)) return false;
        }
        $indexes = $contract['indexes'];
        ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => $expected) if (!$this->sameIndex($meta['indexes'][$name], $expected)) return false;
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
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $column) {
            $column['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ($column['COLUMN_DEFAULT'] !== null) $column['COLUMN_DEFAULT'] = (string) $column['COLUMN_DEFAULT'];
            $columns[$column['COLUMN_NAME']] = $column;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $index) $indexes[$index['INDEX_NAME']][] = $index;
        ksort($indexes, SORT_STRING);
        return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
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
        $state = $view['issues'] !== [] ? 'incompatible' : ($view['steps'] === [] ? 'completed' : 'applicable');
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $phase !== null ? 1 : ($state === 'incompatible' ? 2 : 0),
            'changed' => $this->writeAttempted, 'applied_steps' => $this->applied,
            'pending_steps' => array_column($view['steps'], 'code'), 'pending_steps_known' => $view['known'] ?? true,
            'issues' => $view['issues'], 'failure_phase' => $phase, 'marker' => self::MARKER,
            'source_sha256' => hash_file('sha256', __FILE__), 'history_mapping' => 'NOT RUN',
            'refund_producer' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
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


final class MarketplaceMs5RefundMenus
{
    private const MARKER = 'marketplace-ms5-refund-test-menu-v1';
    private string $database;
    private string $table;
    private ?int $parentId = null;

    public function __construct(private PDO $pdo, string $prefix)
    {
        if (!$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) throw new RuntimeException('Configuration unavailable');
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
            || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) throw new RuntimeException('Configuration unavailable');
        $this->database = $database;
        $this->table = self::identifier($prefix . 'system_menus');
    }

    public function check(): array { return $this->inspect('check'); }

    public function apply(): array
    {
        $locked = false; $committed = false; $created = []; $phase = 'precheck';
        $result = null;
        $lockName = 'ms5-refund-menu:' . substr(hash('sha256', $this->database . "\0" . $this->table . "\0" . self::MARKER), 0, 47);
        try {
            if ($this->pdo->inTransaction()) return $this->result('apply', 'incompatible', [], false, ['active_transaction']);
            $before = $this->inspect('apply');
            if ($before['state'] === 'incompatible') return $before;
            $phase = 'named_lock';
            $lock = $this->rows('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (count($lock) !== 1 || (string)$lock[0]['acquired'] !== '1') throw new RuntimeException('Lock unavailable');
            $locked = true;
            $phase = 'begin_transaction';
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transaction unavailable');
            $phase = 'locked_recheck';
            $current = $this->inspect('apply', true);
            if ($current['state'] === 'incompatible') {
                if (!$this->pdo->rollBack()) throw new RuntimeException('Rollback unavailable');
                $result = $current;
            } else {
                $columns = array_keys(self::menuRows($current['parent_id'])[0]);
                $sql = 'INSERT INTO ' . self::quote($this->table) . ' (' . implode(',', array_map([self::class, 'quote'], $columns))
                    . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                $insert = $this->pdo->prepare($sql);
                $phase = 'insert_missing_menus';
                foreach (self::menuRows($current['parent_id']) as $row) if (!isset($current['menu_ids'][$row['unique_auth']])) {
                    if (!$insert->execute(array_values($row)) || $insert->rowCount() !== 1) throw new RuntimeException('Insert unavailable');
                    $native = $this->pdo->lastInsertId();
                    if (!is_string($native) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $native) !== 1
                        || (int)$native > 65535) throw new RuntimeException('Native identity unavailable');
                    $created[$row['unique_auth']] = (int)$native;
                }
                $phase = 'postcheck';
                $after = $this->inspect('apply', true);
                if ($after['state'] !== 'completed' || $after['menu_ids'] !== $current['menu_ids'] + $created) {
                    // Compare maps by their fixed permission keys rather than incidental insertion order.
                    $wanted = $current['menu_ids'] + $created;
                    $actual = $after['menu_ids']; ksort($wanted); ksort($actual);
                    if ($after['state'] !== 'completed' || $actual !== $wanted) throw new RuntimeException('Postcheck unavailable');
                }
                $phase = 'commit';
                if (!$this->pdo->commit() || $this->pdo->inTransaction()) throw new RuntimeException('Commit unavailable');
                $committed = true;
                $result = $this->result('apply', 'completed', $after['menu_ids'], $created !== [], []);
                $result['created_menu_ids'] = $created;
            }
        } catch (Throwable $error) {
            $rolledBack = false;
            try { if ($this->pdo->inTransaction()) $rolledBack = $this->pdo->rollBack(); } catch (Throwable $ignored) {}
            $result = $this->result('apply', 'failed', [], $committed && $created !== [], [$phase . '_failed']);
            $result['commit_observed'] = $committed;
            $result['rollback_observed'] = $rolledBack;
            $result['created_menu_ids'] = $committed ? $created : [];
        } finally {
            if ($locked) {
                try {
                    $released = $this->rows('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                    if (count($released) !== 1 || (string)$released[0]['released'] !== '1') throw new RuntimeException('Release unavailable');
                } catch (Throwable $ignored) {
                    $result = $this->result('apply', 'failed', $result['menu_ids'] ?? [], $committed && $created !== [], ['lock_release_failed']);
                    $result['commit_observed'] = $committed;
                    $result['created_menu_ids'] = $committed ? $created : [];
                }
            }
        }
        if (!is_array($result)) throw new RuntimeException('Result unavailable');
        return $result;
    }

    private function inspect(string $mode, bool $lock = false): array
    {
        if (!$this->compatible()) return $this->result($mode, 'incompatible', [], false, ['menu_schema']);
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Transaction unavailable');
        $suffix = $lock ? ' FOR UPDATE' : '';
        $anchors = $this->anchors($suffix);
        if ($anchors['issues'] !== []) return $this->result($mode, 'incompatible', [], false, $anchors['issues']);
        $this->parentId = $anchors['parent_id'];
        $expectedRows = self::menuRows($this->parentId);
        $registered = $this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE auth_type = 2 ORDER BY id' . $suffix, []);
        $menuIds = []; $issues = []; $expectedPermissions = array_column($expectedRows, 'unique_auth');
        foreach ($expectedRows as $expected) {
            $matches = [];
            foreach ($this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE unique_auth = ? ORDER BY id' . $suffix,
                [$expected['unique_auth']]) as $row) $matches[(string)$row['id']] = $row;
            foreach ($registered as $row) if (trim(strtolower((string)$row['methods'])) === strtolower($expected['methods'])
                && trim(strtolower(str_replace(' ', '', (string)$row['api_url']))) === $expected['api_url']) $matches[(string)$row['id']] = $row;
            if (count($matches) > 1) $issues[] = 'permission_or_route_collision';
            elseif ($matches !== []) {
                $row = array_values($matches)[0];
                if (!$this->sameMenu($row, $expected)) $issues[] = 'menu_fields_conflict';
                else $menuIds[$expected['unique_auth']] = (int)$row['id'];
            }
        }
        foreach ($this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE mark = ? ORDER BY id' . $suffix,
            [self::MARKER]) as $row) if (!in_array($row['unique_auth'], $expectedPermissions, true)) $issues[] = 'marker_conflict';
        return $this->result($mode, $issues ? 'incompatible' : (count($menuIds) === 1 ? 'completed' : 'applicable'),
            $menuIds, false, array_values(array_unique($issues)));
    }

    /** The marketplace aftersale route is static Vue navigation, not a system_menus page.
     * This single operation leaf belongs to the existing installed refund page; no page or old grant is created. */
    private function anchors(string $suffix): array
    {
        $definitions = [
            'order_root' => ['unique_auth'=>'admin-order', 'auth_type'=>'1', 'menu_path'=>'/order', 'api_url'=>'', 'methods'=>'', 'pid'=>'0'],
            'refund_page' => ['unique_auth'=>'admin-order-refund', 'auth_type'=>'1', 'menu_path'=>'/order/refund', 'api_url'=>'', 'methods'=>''],
        ];
        $selected = [];
        foreach ($definitions as $name=>$expected) {
            $rows = $this->rows('SELECT * FROM ' . self::quote($this->table) . ' WHERE unique_auth = ? ORDER BY id' . $suffix, [$expected['unique_auth']]);
            if (count($rows) !== 1) return ['issues'=>[$name . '_missing_or_ambiguous']];
            $row = $rows[0]; $id = (string)($row['id'] ?? '');
            if (count($row) !== 22 || preg_match('/\A[1-9][0-9]{0,4}\z/D', $id) !== 1 || (int)$id > 65535)
                return ['issues'=>[$name . '_identity_conflict']];
            foreach ($expected + ['is_show'=>'1', 'is_show_path'=>'1', 'access'=>'1', 'is_del'=>'0'] as $field=>$value) {
                if (!array_key_exists($field, $row) || $row[$field] === null || (string)$row[$field] !== $value)
                    return ['issues'=>[$name . '_identity_conflict']];
            }
            $selected[$name] = $row;
        }
        if ((string)$selected['refund_page']['pid'] !== (string)$selected['order_root']['id'])
            return ['issues'=>['refund_anchor_chain_conflict']];
        return ['issues'=>[], 'parent_id'=>(int)$selected['refund_page']['id']];
    }

    public static function menuRows(int $parent): array
    {
        if ($parent < 1 || $parent > 65535) throw new RuntimeException('Native identity unavailable');
        $common = ['pid'=>$parent,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,
            'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'',
            'is_header'=>0,'is_del'=>0,'mark'=>self::MARKER];
        return [$common + ['menu_name'=>'执行测试退款','methods'=>'POST',
            'api_url'=>'marketplace/aftersales/<id>/test-refund','unique_auth'=>'marketplace-aftersales-test-refund']];
    }

    private function sameMenu(array $actual, array $expected): bool
    {
        $id = (string)($actual['id'] ?? '');
        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $id) !== 1 || (int)$id > 65535 || count($actual) !== 22) return false;
        foreach ($expected as $key=>$value) if (!array_key_exists($key, $actual) || $actual[$key] === null
            || (string)$actual[$key] !== (string)$value) return false;
        return true;
    }

    /** Exact installed menu structure used by the original MS1 menu compatibility check. */
    private function compatible(): bool
    {
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->database,$this->table]);
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string)$tables[0]['ENGINE'],'InnoDB') !== 0
            || !is_string($tables[0]['TABLE_COLLATION']) || !str_starts_with($tables[0]['TABLE_COLLATION'],'utf8mb4_')) return false;
        $columns = $this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$this->database,$this->table]);
        $expected = [
            ['id','smallint unsigned',null,true], ['pid','smallint unsigned','0'], ['icon','varchar(16)',''], ['menu_name','varchar(32)',''],
            ['module','varchar(32)','',false,'utf8mb3','utf8mb3_bin'], ['controller','varchar(64)','',false,'utf8mb3','utf8mb3_bin'],
            ['action','varchar(32)','',false,'utf8mb3','utf8mb3_bin'], ['api_url','varchar(100)',''], ['methods','varchar(10)',''],
            ['params','varchar(128)','[]',false,'utf8mb3','utf8mb3_bin'], ['sort','tinyint','1'], ['is_show','tinyint unsigned','1'],
            ['is_show_path','tinyint','0'], ['access','tinyint unsigned','1'], ['menu_path','varchar(128)',''], ['path','varchar(255)',''],
            ['auth_type','tinyint','0'], ['header','varchar(50)',''], ['is_header','tinyint','0'], ['unique_auth','varchar(150)',''],
            ['is_del','tinyint','0'], ['mark','varchar(500)',''],
        ];
        if (count($columns) !== count($expected)) return false;
        foreach ($expected as $index=>$column) {
            $actual = $columns[$index]; $text = str_starts_with($column[1], 'varchar(');
            $charset = $text ? ($column[4] ?? 'utf8mb4') : null;
            $collation = $text ? ($column[5] ?? $tables[0]['TABLE_COLLATION']) : null;
            $actualCharset = $actual['CHARACTER_SET_NAME'] === 'utf8' ? 'utf8mb3' : $actual['CHARACTER_SET_NAME'];
            $actualCollation = $actual['COLLATION_NAME'] === 'utf8_bin' ? 'utf8mb3_bin' : $actual['COLLATION_NAME'];
            $type = preg_replace('/\b(tinyint|smallint|int)\([0-9]+\)/', '$1', strtolower((string)$actual['COLUMN_TYPE']));
            $default = $actual['COLUMN_DEFAULT'] === null ? null : (string)$actual['COLUMN_DEFAULT'];
            if ($actual['COLUMN_NAME'] !== $column[0] || $type !== $column[1] || $actual['IS_NULLABLE'] !== 'NO'
                || $default !== $column[2] || $actualCharset !== $charset || $actualCollation !== $collation
                || $actual['EXTRA'] !== (($column[3] ?? false) ? 'auto_increment' : '') || $actual['GENERATION_EXPRESSION'] !== '') return false;
        }
        $indexes = $this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$this->database,$this->table]);
        $actualIndexes = [];
        foreach ($indexes as $row) {
            if ($row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
                || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A' || !in_array((string)$row['NON_UNIQUE'], ['0','1'], true)) return false;
            $name = $row['INDEX_NAME']; $unique = (string)$row['NON_UNIQUE'] === '0';
            $actualIndexes[$name] ??= [$unique, []];
            if ($actualIndexes[$name][0] !== $unique || (int)$row['SEQ_IN_INDEX'] !== count($actualIndexes[$name][1]) + 1) return false;
            $actualIndexes[$name][1][] = $row['COLUMN_NAME'];
        }
        $expectedIndexes = ['PRIMARY'=>[true,['id']], 'pid'=>[false,['pid']], 'is_show'=>[false,['is_show']], 'access'=>[false,['access']]];
        ksort($actualIndexes); ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) return false;
        foreach ([['SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', [$this->database,$this->table]],
            ["SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'", [$this->database,$this->table]],
            ['SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?', [$this->database,$this->table]]] as [$sql,$arguments]) {
            $rows = $this->rows($sql,$arguments);
            if (count($rows) !== 1 || (string)$rows[0]['n'] !== '0') return false;
        }
        return true;
    }

    private function rows(string $sql, array $arguments): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement->execute($arguments)) throw new RuntimeException('Inspection unavailable');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function result(string $mode, string $state, array $menuIds, bool $changed, array $issues): array
    {
        return ['mode'=>$mode, 'state'=>$state, 'changed'=>$changed, 'tables'=>[], 'menu_ids'=>$menuIds,
            'issues'=>array_map(fn(string $kind): array => ['kind'=>$kind,'path'=>$this->table], $issues),
            'source_binding'=>['entry_sha256'=>hash_file('sha256',__FILE__), 'ms1_sha256'=>hash_file('sha256',__DIR__.'/marketplace-ms1.php')],
            'authorization_cache_producer'=>'fresh_scope_for_request_exact_all_auth_and_current_roles', 'roles_modified'=>false,
            'parent_id'=>$this->parentId, 'navigation_changed'=>false,
            'permission_scope'=>'EXISTING_REFUND_PAGE_OPERATION_LEAF_ONLY_STATIC_MARKETPLACE_ROUTE_UNCHANGED'];
    }

    private static function identifier(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name) !== 1) throw new RuntimeException('Configuration unavailable');
        return $name;
    }
    private static function quote(string $name): string { return '`' . self::identifier($name) . '`'; }
}


/** Explicit private configuration only; the original bounded MS5 parser is reused without .env fallback. */
function marketplaceMs5RefundMain(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms5-refund.php [check|apply-structure|verify|check-menus|apply-menus] --config-file=PRIVATE_JSON\n"
            . "Default check is read-only. Apply-structure creates only the missing exact refund-attempt table; apply-menus inserts only one operation permission under the existing refund page. No App, feature activation, old role/grant update, backfill or refund is performed. DDL may partially commit; retain the receipt.\n";
        return 0;
    }
    $mode = 'check'; $file = null; $selected = false;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check','apply-structure','verify','check-menus','apply-menus'], true) && !$selected) {
                $mode = $argument; $selected = true;
            } elseif (str_starts_with($argument, '--config-file=') && $file === null && strlen($argument) > 14) {
                $file = substr($argument, 14);
            } else throw new RuntimeException('CLI configuration unavailable');
        }
        if ($file === null) throw new RuntimeException('Explicit private configuration required');
        $config = marketplaceMs5PaymentConfiguration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>3]);
        $migration = new MarketplaceMs5RefundMigration($pdo, $config['prefix'], $config['database']);
        if ($mode === 'check') $result = $migration->check();
        elseif ($mode === 'apply-structure') $result = $migration->applyStructure();
        elseif ($mode === 'verify') $result = $migration->verify();
        else {
            $structure = $migration->verify();
            if ($structure['state'] !== 'completed') {
                $result = $structure;
                $result['mode'] = $mode;
            } else {
                $menus = new MarketplaceMs5RefundMenus($pdo, $config['prefix']);
                $result = $mode === 'apply-menus' ? $menus->apply() : $menus->check();
                $result['mode'] = $mode;
                $result['native_exit'] = $result['state'] === 'failed' ? 1
                    : (in_array($result['state'], ['completed','applicable'], true) ? 0 : 2);
            }
        }
    } catch (Throwable $error) {
        // Never output driver messages, SQL, connection values, configuration or private paths.
        $result = ['mode'=>$mode, 'state'=>'incompatible', 'native_exit'=>1, 'changed'=>false,
            'applied_steps'=>[], 'pending_steps'=>[], 'pending_steps_known'=>false,
            'issues'=>[['kind'=>'configuration_or_inspection_failed','target'=>'migration']],
            'failure_phase'=>'configuration_or_inspection_failed', 'marker'=>MarketplaceMs5RefundMigration::MARKER,
            'source_sha256'=>hash_file('sha256', __FILE__), 'history_mapping'=>'NOT RUN',
            'refund_producer'=>'NOT RUN', 'acceptance'=>'NOT RUN', 'roles_modified'=>false];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return $result['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(marketplaceMs5RefundMain(array_slice($argv, 1)));
