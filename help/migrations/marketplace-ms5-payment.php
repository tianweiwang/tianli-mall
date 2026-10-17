<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace-ms4.php';

/** Testing-only additive payment structure. No framework, business writes or historical birth. */
final class MarketplaceMs5PaymentMigration
{
    public const MARKER = 'marketplace-ms5-payment-structure-v1';
    private bool $writeAttempted = false;
    private array $applied = [];
    private string $database;

    public function __construct(private PDO $pdo, private string $prefix, string $database)
    {
        self::quote($database);
        foreach (array_keys(self::newTables() + self::producerContracts()) as $name) self::quote($prefix . $name);
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || PHP_INT_SIZE < 8
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || !$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)
            || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Migration configuration unavailable');
        }
        $this->database = $database;
    }

    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    /** Exact column order, comments, defaults and 2/6 indexes from the selected structure contract. */
    public static function newTables(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        return [
            'marketplace_payment_registry' => [
                'columns' => [
                    'order_id' => $c('int unsigned', '真实商业根订单主键'),
                    'uid' => $c('int unsigned', '原订单顾客编号'),
                    'mer_id' => $c('int unsigned', '原订单商家编号'),
                    'payment_contract_version' => $c('tinyint unsigned', '独立支付出生合同版本'),
                    'born_at' => $c('int unsigned', '商业根同事务支付登记秒'),
                    'attempt_generation' => $c('bigint unsigned', '完整付款尝试集合代次', false, '0'),
                    'winner_attempt_id' => $c('bigint unsigned', '唯一成功应用尝试主键', true),
                    'fulfillment_hold' => $c('tinyint unsigned', '持久平台履约限制零或一', false, '0'),
                    'hold_reason' => $c('varchar(32)', '首次固定履约限制原因', true, null, 'ascii'),
                    'hold_attempt_id' => $c('bigint unsigned', '首次履约限制真实尝试引用', true),
                    'hold_at' => $c('int unsigned', '首次履约限制本地登记秒', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['order_id']],
                    'uq_ms5_payment_winner' => [true, ['winner_attempt_id']],
                ],
            ],
            'marketplace_payment_attempt' => [
                'columns' => [
                    'id' => $c('bigint unsigned', '原生付款尝试主键', false, null, null, true),
                    'order_id' => $c('int unsigned', '原商业根订单主键'),
                    'uid' => $c('int unsigned', '原订单顾客编号'),
                    'mer_id' => $c('int unsigned', '原订单商家编号'),
                    'payment_contract_version' => $c('tinyint unsigned', '独立支付出生合同版本'),
                    'admitted_generation' => $c('bigint unsigned', '本次新尝试准入代次'),
                    'order_no' => $c('varbinary(32)', '原订单编号完整字节'),
                    'idem_key' => $c('varchar(128)', '原付款请求幂等键', false, null, 'ascii'),
                    'amount' => $c('decimal(12,2) unsigned', '原精确付款金额元'),
                    'currency' => $c('char(3)', '原付款币种快照', false, null, 'ascii'),
                    'account_snapshot' => $c('json', '原只读测试账户完整快照'),
                    'request_digest' => $c('binary(32)', '原规范付款请求SHA256原始摘要'),
                    'binding_digest' => $c('binary(32)', '原驱动绑定SHA256原始摘要'),
                    'account_domain_digest' => $c('binary(32)', '稳定测试账户域SHA256原始摘要'),
                    'created_at' => $c('int unsigned', '原付款意图本地登记秒'),
                    'attempt_ref' => $c('varchar(45)', '实际驱动返回的原尝试引用', true, null, 'ascii'),
                    'applied_trade_no' => $c('varchar(45)', '仅已应用成功的原测试交易引用', true, null, 'ascii'),
                    'evidence' => $c('json', '完整来源化观察首事实应用及异常'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_ms5_payment_order_idem' => [true, ['order_id', 'idem_key']],
                    'uq_ms5_payment_order_generation' => [true, ['order_id', 'admitted_generation']],
                    'idx_ms5_payment_order_id' => [false, ['order_id', 'id']],
                    'uq_ms5_payment_account_attempt' => [true, ['account_domain_digest', 'attempt_ref']],
                    'uq_ms5_payment_account_trade' => [true, ['account_domain_digest', 'applied_trade_no']],
                ],
            ],
        ];
    }

    /** Read-only prerequisites. Legacy text columns retain their actual utf8mb4 table collation. */
    private static function producerContracts(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        $ledger = MarketplaceMs4Migration::newTables()['marketplace_order_idempotency'];
        foreach ($ledger['columns'] as &$column) $column['auto'] = false;
        unset($column);
        return [
            'user' => [
                'columns' => ['uid' => $c('int unsigned', false, null, null, true)],
                'indexes' => ['PRIMARY' => [true, ['uid']]],
            ],
            'store_order' => [
                'columns' => [
                    'id' => $c('int unsigned', false, null, null, true),
                    'uid' => $c('int unsigned', false, '0'),
                    'mer_id' => $c('int unsigned', false, '0'),
                    'pid' => $c('int', false, '0'),
                    'order_id' => $c('varchar(32)', false, '0', 'utf8mb4'),
                    'unique' => $c('char(32)', false, '', 'utf8mb4'),
                    'cart_id' => $c('text', true, null, 'utf8mb4'),
                    'pay_uid' => $c('int', false, '0'),
                    'pay_price' => $c('decimal(12,2) unsigned', false, '0.00'),
                    'paid' => $c('tinyint unsigned', false, '0'),
                    'pay_time' => $c('int unsigned', false, '0'),
                    'pay_type' => $c('varchar(32)', false, '', 'utf8mb4'),
                    'trade_no' => $c('varchar(100)', false, '', 'utf8mb4'),
                    'status' => $c('tinyint', false, '0'),
                    'is_cancel' => $c('tinyint unsigned', false, '0'),
                    'is_del' => $c('tinyint unsigned', false, '0'),
                    'is_system_del' => $c('tinyint', false, '0'),
                    'refund_status' => $c('tinyint unsigned', false, '0'),
                    'add_time' => $c('int unsigned', false, '0'),
                    'marketplace_trade_version' => $c('tinyint unsigned', true, '0'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'root_unique' => [true, ['unique']],
                    'root_order_owner' => [true, ['order_id', 'uid']],
                    'merchant_root' => [false, ['mer_id', 'id']],
                ],
            ],
            'marketplace_order_idempotency' => $ledger,
            'marketplace_merchant' => [
                'columns' => [
                    'mer_id' => $c('int unsigned', false, null, null, true),
                    'review_status' => $c('tinyint unsigned', false, '0'),
                    'accept_orders' => $c('tinyint unsigned', false, '0'),
                    'fulfillment_enabled' => $c('tinyint unsigned', false, '1'),
                    'security_locked' => $c('tinyint unsigned', false, '0'),
                    'closed_at' => $c('int unsigned', true),
                ],
                'indexes' => ['PRIMARY' => [true, ['mer_id']]],
            ],
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
        $name = 'ms5-payment:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
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
        $ms4 = (new MarketplaceMs4Migration($this->pdo, $this->prefix, $this->database))->check();
        if (($ms4['native_exit'] ?? null) === 1) throw new RuntimeException('Prerequisite inspection unavailable');
        if (($ms4['state'] ?? null) !== 'completed') $issues[] = self::issue('ms4_schema_not_completed', 'migration');
        $this->checkProducer($issues);
        foreach (self::newTables() as $name => $definition) {
            $meta = $this->metadata($name);
            if ($meta === null) $steps[] = ['code' => $name, 'sql' => $this->tableDdl($name, $definition)];
            elseif (!$this->sameNewTable($meta, $definition)) $issues[] = self::issue('new_table_conflict', $name);
        }
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
            'payment_producer' => 'NOT IMPLEMENTED', 'acceptance' => 'NOT RUN'];
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
function marketplaceMs5PaymentConfiguration(string $path): array
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

function marketplaceMs5PaymentMain(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms5-payment.php [check|apply-structure] --config-file=PRIVATE_JSON\nDefault check is read-only. Require completed MS4; only the two testing payment tables may be created. No .env, framework, business writes, backfill or feature activation. DDL can partially commit; retain the receipt and rerun only exact missing tables.\n";
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
        $config = marketplaceMs5PaymentConfiguration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs5PaymentMigration($pdo, $config['prefix'], $config['database']);
        $result = $mode === 'check' ? $migration->check() : $migration->applyStructure();
    } catch (Throwable $error) {
        // No driver error, connection string, database/configuration value or private path is emitted.
        $result = ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => false,
            'applied_steps' => [], 'pending_steps' => [], 'pending_steps_known' => false,
            'issues' => [['kind' => 'configuration_or_connection_failed', 'target' => 'migration']],
            'failure_phase' => 'configuration_or_connection_failed', 'marker' => MarketplaceMs5PaymentMigration::MARKER,
            'source_sha256' => hash_file('sha256', __FILE__), 'history_mapping' => 'NOT RUN',
            'payment_producer' => 'NOT IMPLEMENTED', 'acceptance' => 'NOT RUN'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return $result['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(marketplaceMs5PaymentMain(array_slice($argv, 1)));
