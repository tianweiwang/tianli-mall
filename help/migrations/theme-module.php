<?php
declare(strict_types=1);

namespace Audit\Migration;

use PDO;
use PDOException;
use RuntimeException;

/** Schema derived from the local ThemeModule model, DAO and service contract. */
final class ThemeModuleMigration
{
    public const MARKER = 'audit-theme-module-20261010';
    private PDO $db;
    private string $database;
    private string $table;

    public function __construct(PDO $db, string $prefix = 'eb_')
    {
        $this->db = $db;
        $this->database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $this->table = $prefix . 'theme_module';
        identifier($this->database);
        identifier($this->table);
    }

    private function rows(string $sql): array
    {
        $query = $this->db->prepare($sql);
        $query->execute([$this->database, $this->table]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Read-only: inspect metadata without creating or changing any table. */
    public function check(): array
    {
        $tables = $this->rows('SELECT TABLE_TYPE, ENGINE, TABLE_COLLATION, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
        $result = ['database' => $this->database, 'table' => $this->table, 'state' => 'missing', 'changed' => false, 'issues' => []];
        if (!$tables) { return $result; }
        $table = $tables[0];
        $issues = [];
        if ($table['TABLE_TYPE'] !== 'BASE TABLE') { $issues[] = 'Expected a base table'; }
        if (strtolower((string)$table['ENGINE']) !== 'innodb') { $issues[] = 'Engine must be InnoDB'; }
        if (strpos((string)$table['TABLE_COLLATION'], 'utf8mb4_') !== 0) { $issues[] = 'Table charset must be utf8mb4'; }

        $columns = $this->rows('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, CHARACTER_MAXIMUM_LENGTH, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $byName = array_column($columns, null, 'COLUMN_NAME');
        $names = array_keys($byName);
        sort($names);
        if ($names !== ['data', 'id', 'type']) { $issues[] = 'Expected exactly the columns id, type, data'; }
        if (isset($byName['id'])) {
            $id = $byName['id'];
            if ($id['DATA_TYPE'] !== 'int' || strpos($id['COLUMN_TYPE'], 'unsigned') === false
                || $id['IS_NULLABLE'] !== 'NO' || $id['COLUMN_DEFAULT'] !== null
                || $id['EXTRA'] !== 'auto_increment') {
                $issues[] = 'id must be INT UNSIGNED NOT NULL AUTO_INCREMENT';
            }
        }
        if (isset($byName['type'])) {
            $type = $byName['type'];
            if ($type['DATA_TYPE'] !== 'varchar' || (int)$type['CHARACTER_MAXIMUM_LENGTH'] !== 100
                || $type['IS_NULLABLE'] !== 'NO' || $type['COLUMN_DEFAULT'] !== ''
                || $type['CHARACTER_SET_NAME'] !== 'utf8mb4' || $type['EXTRA'] !== '') {
                $issues[] = "type must be VARCHAR(100) utf8mb4 NOT NULL DEFAULT ''";
            }
        }
        if (isset($byName['data'])) {
            $data = $byName['data'];
            if ($data['DATA_TYPE'] !== 'longtext' || $data['IS_NULLABLE'] !== 'YES'
                || $data['COLUMN_DEFAULT'] !== null || $data['CHARACTER_SET_NAME'] !== 'utf8mb4'
                || $data['EXTRA'] !== '') {
                $issues[] = 'data must be LONGTEXT utf8mb4 NULL DEFAULT NULL';
            }
        }
        foreach ($columns as $column) {
            if ($column['GENERATION_EXPRESSION'] !== '') { $issues[] = 'Generated columns are not supported'; }
        }
        $indexes = $this->rows('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $primary = [];
        foreach ($indexes as $index) {
            if ($index['INDEX_NAME'] === 'PRIMARY') {
                $primary[] = $index['COLUMN_NAME'];
            } elseif ((int)$index['NON_UNIQUE'] === 0) {
                $issues[] = 'Unexpected unique index: ' . $index['INDEX_NAME'];
            }
        }
        if ($primary !== ['id']) { $issues[] = 'Primary key must contain only id'; }
        $result['state'] = $issues ? 'incompatible' : 'compatible';
        $result['issues'] = $issues;
        $result['migration_owned'] = $table['TABLE_COMMENT'] === self::MARKER;
        return $result;
    }

    public function apply(): array
    {
        $before = $this->check();
        if ($before['state'] === 'incompatible') { throw new RuntimeException('Incompatible existing schema; no changes made: ' . implode('; ', $before['issues'])); }
        if ($before['state'] === 'compatible') { return $before; }
        $table = identifier($this->table);
        try {
            $this->db->exec("CREATE TABLE {$table} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(100) NOT NULL DEFAULT '',
                `data` LONGTEXT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='" . self::MARKER . "'");
        } catch (PDOException $e) {
            // Another migration may have created it after the metadata check.
            if ((int)($e->errorInfo[1] ?? 0) !== 1050) { throw $e; }
            $existing = $this->check();
            if ($existing['state'] !== 'compatible') { throw new RuntimeException('Concurrent table creation has an incompatible schema; no changes made'); }
            return $existing;
        }
        $after = $this->check();
        if ($after['state'] !== 'compatible') { throw new RuntimeException('Created table did not pass schema verification'); }
        $after['state'] = 'created';
        $after['changed'] = true;
        return $after;
    }

    /** Explicit recovery only: refuse unowned, changed or nonempty tables. */
    public function rollbackEmpty(): array
    {
        $before = $this->check();
        if ($before['state'] === 'missing') { return $before; }
        if ($before['state'] !== 'compatible' || !($before['migration_owned'] ?? false)) {
            throw new RuntimeException('Rollback requires the unchanged schema and this migration marker');
        }
        $table = identifier($this->table);
        $this->db->exec('LOCK TABLES ' . $table . ' WRITE');
        try {
            // Recheck under the write lock so a concurrent writer cannot race DROP.
            $locked = $this->check();
            if ($locked['state'] !== 'compatible' || !($locked['migration_owned'] ?? false)) {
                throw new RuntimeException('Schema changed before rollback; no changes made');
            }
            if ((int)$this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                throw new RuntimeException('Refusing rollback: theme_module contains data');
            }
            $this->db->exec('DROP TABLE ' . $table);
        } finally {
            $this->db->exec('UNLOCK TABLES');
        }
        return ['database' => $this->database, 'table' => $this->table, 'state' => 'removed-empty', 'changed' => true, 'issues' => []];
    }
}

function identifier(string $value): string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value)) { throw new RuntimeException('Invalid database or table identifier'); }
    return '`' . $value . '`';
}

/** Read local credentials in memory only; never include them in output. */
function databaseConfig(?string $rootPasswordFile = null): array
{
    $env = @parse_ini_file(dirname(__DIR__, 2) . '/crmeb/.env', true, INI_SCANNER_RAW);
    if (!is_array($env)) { throw new RuntimeException('Cannot read crmeb/.env'); }
    $env = array_change_key_case($env, CASE_LOWER);
    $db = array_change_key_case($env['database'] ?? [], CASE_LOWER);
    foreach (['hostname', 'database', 'username', 'password'] as $field) {
        if (!isset($db[$field]) || !is_string($db[$field])) { throw new RuntimeException('Missing DATABASE connection fields in crmeb/.env'); }
    }
    $db += ['hostport' => '3306', 'prefix' => 'eb_'];
    if ($rootPasswordFile !== null) {
        $password = @file_get_contents($rootPasswordFile);
        if ($password === false || rtrim($password, "\r\n") === '') { throw new RuntimeException('Cannot read the root password file'); }
        $db['username'] = 'root';
        $db['password'] = rtrim($password, "\r\n");
    }
    identifier($db['database']);
    identifier($db['prefix'] . 'theme_module');
    return $db;
}

function connectDatabase(array $config, ?string $database = null): PDO
{
    $database = $database ?? $config['database'];
    identifier($database);
    return new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $database . ';charset=utf8mb4',
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}

function main(array $args): int
{
    if ($args === ['--help']) {
        echo "Usage: php help/migrations/theme-module.php [--check | --apply | --rollback-empty] [--database=NAME] [--root-password-file=PATH]\nDefault: read-only schema check. Connection/prefix: crmeb/.env.\n";
        return 0;
    }
    $mode = 'check';
    $selected = false;
    $database = null;
    $rootPasswordFile = null;
    foreach ($args as $arg) {
        if (in_array($arg, ['--check', '--apply', '--rollback-empty'], true) && !$selected) {
            $mode = substr($arg, 2);
            $selected = true;
        } elseif (strpos($arg, '--database=') === 0 && $database === null) {
            $database = substr($arg, 11);
            identifier($database);
        } elseif (strpos($arg, '--root-password-file=') === 0 && $rootPasswordFile === null) {
            $rootPasswordFile = substr($arg, 21);
            if ($rootPasswordFile === '') { throw new RuntimeException('Missing root password file path'); }
        } else {
            throw new RuntimeException('Unknown or conflicting arguments; use --help');
        }
    }
    $config = databaseConfig($rootPasswordFile);
    $migration = new ThemeModuleMigration(connectDatabase($config, $database), $config['prefix']);
    $result = $mode === 'apply' ? $migration->apply() : ($mode === 'rollback-empty' ? $migration->rollbackEmpty() : $migration->check());
    echo json_encode(['mode' => $mode] + $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    return in_array($result['state'], ['missing', 'incompatible'], true) && $mode === 'check' ? 2 : 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(main(array_slice($argv, 1)));
    } catch (PDOException $e) {
        fwrite(STDERR, 'Database operation failed (SQLSTATE ' . $e->getCode() . "); credentials omitted.\n");
        exit(1);
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
