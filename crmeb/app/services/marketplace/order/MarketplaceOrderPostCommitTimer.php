<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\services\system\crontab\SystemCrontabServices;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Cache;
use think\facade\Db;

/** Explicit timer DML, separate from the structure-only marketplace migration. */
final class MarketplaceOrderPostCommitTimer
{
    public const MARK = 'marketplaceOrderPostCommit';

    private const FIELDS = [
        'name' => '多商户订单提交后收尾',
        'mark' => self::MARK,
        'content' => '按持久收尾登记完成已提交订单',
        'type' => 2, 'month' => 0, 'week' => 0, 'day' => 0, 'hour' => 0,
        'minute' => 1, 'second' => 0, 'is_del' => 0, 'is_open' => 1, 'customCode' => '',
    ];

    public function __construct(private SystemCrontabServices $timers) {}

    /** Repeated installation verifies the saved task; it never enables or replaces an edited task. */
    public function register(): array
    {
        $connection = Db::connect();
        $existing = $connection->getPdo();
        if ($existing instanceof PDO && $existing->inTransaction()) throw new AuthUnavailableException();
        // Initialize the actual default primary connection, without a separate migration connection.
        $connection->query('SELECT DATABASE() AS database_name', [], true);
        $pdo = self::pdo($connection);
        if ($pdo->inTransaction()) throw new AuthUnavailableException();
        $database = self::rows($pdo, 'SELECT DATABASE() AS database_name')[0]['database_name'] ?? null;
        $prefix = $connection->getConfig('prefix');
        if (!is_string($database) || preg_match('/\A[^\x00]{1,64}\z/uD', $database) !== 1
            || !is_string($prefix) || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
            throw new AuthUnavailableException();
        }
        $name = $prefix . 'system_timer';
        if (strlen($name) > 64) throw new AuthUnavailableException();
        $table = '`' . $name . '`';
        $lockName = 'ms4-post-commit:' . substr(hash('sha256', $database . "\0" . $name . "\0" . self::MARK), 0, 48);
        $locked = false;
        try {
            $lock = self::rows($pdo, 'SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (count($lock) !== 1 || (string)($lock[0]['acquired'] ?? '') !== '1') throw new AuthUnavailableException();
            $locked = true;
            self::compatible($pdo, $database, $name);
            $fields = self::FIELDS + ['timeStr' => $this->timers->getTimerStr(self::FIELDS)];
            $result = $connection->transaction(function (ConnectionInterface $current) use ($connection, $pdo, $table, $fields): array {
                if ($current !== $connection || self::pdo($current) !== $pdo || !$pdo->inTransaction()) {
                    throw new AuthUnavailableException();
                }
                $rows = self::rows($pdo, 'SELECT * FROM ' . $table . ' WHERE mark = ? ORDER BY id FOR UPDATE', [self::MARK]);
                if (count($rows) > 1) throw new AuthUnavailableException();
                $changed = $rows === [];
                if ($changed) {
                    $now = time();
                    $data = $fields + ['last_execution_time' => 0,
                        'next_execution_time' => $this->timers->getTimerCycleTime(self::FIELDS, $now),
                        'add_time' => $now, 'update_time' => $now];
                    $columns = array_keys($data);
                    $insert = $pdo->prepare('INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES ('
                        . implode(',', array_fill(0, count($columns), '?')) . ')');
                    if (!$insert->execute(array_values($data)) || $insert->rowCount() !== 1) throw new AuthUnavailableException();
                    $id = self::integer($pdo->lastInsertId(), false);
                    $rows = self::rows($pdo, 'SELECT * FROM ' . $table . ' WHERE mark = ? ORDER BY id FOR UPDATE', [self::MARK]);
                    if (count($rows) !== 1 || self::integer($rows[0]['id'] ?? null, false) !== $id) {
                        throw new AuthUnavailableException();
                    }
                }
                $id = self::verify($rows[0], $fields);
                return ['timer_id' => $id, 'changed' => $changed, 'mark' => self::MARK, 'timeStr' => $fields['timeStr']];
            });
            if (self::pdo($connection) !== $pdo || $pdo->inTransaction()) throw new AuthUnavailableException();
            // Refresh the same complete cache consumed by the existing scheduler, after the physical commit.
            $list = self::rows($pdo, 'SELECT * FROM ' . $table . ' WHERE is_open = 1 AND is_del = 0 ORDER BY id');
            if (Cache::set('crontabCache', $list) !== true || Cache::get('crontabCache') !== $list) {
                throw new AuthUnavailableException();
            }
            return $result;
        } finally {
            if ($locked) {
                $released = self::rows($pdo, 'SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                if (count($released) !== 1 || (string)($released[0]['released'] ?? '') !== '1') throw new AuthUnavailableException();
            }
        }
    }

    private static function verify(array $row, array $fields): int
    {
        $keys = array_keys($fields + ['id' => 0, 'last_execution_time' => 0, 'next_execution_time' => 0, 'add_time' => 0, 'update_time' => 0]);
        $actualKeys = array_keys($row); sort($keys); sort($actualKeys);
        if ($keys !== $actualKeys) throw new AuthUnavailableException();
        foreach ($fields as $key => $value) {
            if ($row[$key] === null || (string)$row[$key] !== (string)$value) throw new AuthUnavailableException();
        }
        self::integer($row['last_execution_time'], true);
        foreach (['next_execution_time', 'add_time', 'update_time'] as $key) self::integer($row[$key], false);
        return self::integer($row['id'], false);
    }

    private static function compatible(PDO $pdo, string $database, string $table): void
    {
        $tables = self::rows($pdo, 'SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]);
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string)$tables[0]['ENGINE'], 'InnoDB') !== 0) {
            throw new AuthUnavailableException();
        }
        $ids = self::rows($pdo, 'SELECT COLUMN_TYPE, COLUMN_KEY, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$database, $table, 'id']);
        if (count($ids) !== 1 || !in_array(strtolower((string)$ids[0]['COLUMN_TYPE']), ['int', 'int(11)'], true)
            || $ids[0]['COLUMN_KEY'] !== 'PRI' || $ids[0]['IS_NULLABLE'] !== 'NO' || $ids[0]['EXTRA'] !== 'auto_increment') {
            throw new AuthUnavailableException();
        }
        $primary = self::rows($pdo, 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$database, $table, 'PRIMARY']);
        if ($primary !== [['COLUMN_NAME' => 'id']]) throw new AuthUnavailableException();
        if (self::rows($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [$database, $table]) !== []) {
            throw new AuthUnavailableException();
        }
    }

    private static function pdo(ConnectionInterface $connection): PDO
    {
        $pdo = $connection->getPdo();
        if ($connection !== Db::connect() || !$pdo instanceof PDO || $pdo !== Db::connect()->getPdo()
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)) throw new AuthUnavailableException();
        return $pdo;
    }

    private static function rows(PDO $pdo, string $sql, array $values = []): array
    {
        $statement = $pdo->prepare($sql);
        if (!$statement->execute($values)) throw new AuthUnavailableException();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function integer(mixed $value, bool $allowZero): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$value) !== 1
            || (int)$value > 2147483647 || (!$allowZero && (int)$value === 0)) throw new AuthUnavailableException();
        return (int)$value;
    }
}
