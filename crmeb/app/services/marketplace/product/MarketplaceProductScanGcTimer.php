<?php
declare(strict_types=1);

namespace app\services\marketplace\product;

use app\services\system\crontab\SystemCrontabServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\BoundedRedisConnection;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/** Own timer registration and a small cadence gate; lease cleanup stays in Staging. */
final class MarketplaceProductScanGcTimer
{
    public const MARK = 'marketplaceProductScanGc';
    private const PURPOSE = 'product-media-scan-v1';
    private const CRON = '*/1 * * * * *';
    private const COLUMNS = ['id', 'name', 'mark', 'content', 'type', 'month', 'week', 'day', 'hour',
        'minute', 'second', 'last_execution_time', 'next_execution_time', 'add_time', 'update_time',
        'is_del', 'is_open', 'customCode', 'timeStr'];
    private const FIELDS = [
        'name' => '商品扫码临时图片清理', 'mark' => self::MARK,
        'content' => '按配置周期清理已证可回收的私有扫码暂存',
        'type' => 1, 'month' => 0, 'week' => 0, 'day' => 0, 'hour' => 0,
        'minute' => 0, 'second' => 1, 'is_del' => 0, 'is_open' => 1, 'customCode' => '',
    ];
    private const GATE = <<<'LUA'
if #KEYS ~= 1 or #ARGV ~= 1 then return redis.error_reply('GC_GATE_ARGUMENTS') end
local period = tonumber(ARGV[1])
if not period or period ~= math.floor(period) or period < 1 or period > 86400 then
    return redis.error_reply('GC_GATE_PERIOD')
end
local time = redis.call('TIME')
if type(time) ~= 'table' or #time ~= 2 or type(time[1]) ~= 'string' or type(time[2]) ~= 'string'
    or not string.match(time[1], '^%d+$') or not string.match(time[2], '^%d+$') then
    return redis.error_reply('GC_GATE_TIME')
end
local seconds, micros = tonumber(time[1]), tonumber(time[2])
if not seconds or not micros or seconds < 1 or micros < 0 or micros > 999999 then
    return redis.error_reply('GC_GATE_TIME')
end
local now = seconds * 1000 + math.floor(micros / 1000)
if now ~= math.floor(now) or now > 9007199254740991 then return redis.error_reply('GC_GATE_TIME') end
local length = redis.call('STRLEN', KEYS[1])
if length > 16 then return redis.error_reply('GC_GATE_SAVED_TIME') end
local last = redis.call('GET', KEYS[1])
if last ~= false then
    if type(last) ~= 'string' or not string.match(last, '^[1-9]%d*$') then return redis.error_reply('GC_GATE_SAVED_TIME') end
    local previous = tonumber(last)
    if not previous or previous > 9007199254740991 or previous ~= math.floor(previous)
        or string.format('%.0f', previous) ~= last or previous > now then
        return redis.error_reply('GC_GATE_SAVED_TIME')
    end
    if now - previous < period * 1000 then return {'WAIT', string.format('%.0f', now)} end
end
local text = string.format('%.0f', now)
local saved = redis.call('SET', KEYS[1], text)
if type(saved) ~= 'table' or saved.ok ~= 'OK' then return redis.error_reply('GC_GATE_SAVE') end
return {'RUN', text}
LUA;

    public function __construct(private SystemCrontabServices $timers) {}

    /** Explicit CLI only. Never updates, enables, replaces or deletes an existing row. */
    public function register(): array
    {
        $settings = self::settings();
        $context = self::context(); $pdo = $context['pdo'];
        if (!in_array($pdo->getAttribute(PDO::ATTR_PERSISTENT), [false, 0], true)) self::fail();
        $lockName = 'marketplace-scan-gc:' . substr(hash('sha256', $context['database'] . "\0"
            . $context['table_name'] . "\0" . self::MARK), 0, 40);
        $attempted = false; $knownAcquired = false;
        try {
            self::same($context);
            $owner = self::lockOwner($context, $lockName);
            if ($owner === $context['session']) self::fail();
            // Acquisition may have happened even if execution/fetch/cursor acknowledgment fails.
            $attempted = true;
            $lock = self::rows($pdo, 'SELECT GET_LOCK(?,10) AS acquired', [$lockName]);
            if (count($lock) !== 1 || array_keys($lock[0]) !== ['acquired']
                || !in_array($lock[0]['acquired'], [1, '1'], true)) self::fail();
            $knownAcquired = true; self::same($context);
            $authority = self::metadataAuthority($context);
            self::compatible($context);
            if (self::metadataAuthority($context) !== $authority) self::fail();
            $fields = self::FIELDS + ['timeStr' => self::CRON];
            if ($this->timers->getTimerStr(self::FIELDS) !== self::CRON) self::fail();
            $started = false;
            try {
                if ($pdo->inTransaction() || $pdo->beginTransaction() !== true) self::fail();
                $started = true; self::same($context);
                $saved = self::rows($pdo, 'SELECT * FROM ' . $context['table'] . ' WHERE mark = ? ORDER BY id FOR UPDATE', [self::MARK]);
                if (count($saved) > 1) self::fail();
                $changed = $saved === [];
                if ($changed) {
                    $now = self::integer(time(), 1, 2147483646);
                    $next = $this->timers->getTimerCycleTime(self::FIELDS, $now);
                    if ($next !== $now + 1) self::fail();
                    $data = $fields + ['last_execution_time' => 0, 'next_execution_time' => $next,
                        'add_time' => $now, 'update_time' => $now];
                    $columns = array_keys($data);
                    $statement = $pdo->prepare('INSERT INTO ' . $context['table'] . ' (`' . implode('`,`', $columns)
                        . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
                    if (!$statement instanceof PDOStatement) self::fail();
                    try {
                        if ($statement->execute(array_values($data)) !== true || $statement->rowCount() !== 1) self::fail();
                    } finally { if ($statement->closeCursor() !== true) self::fail(); }
                    $id = self::integer($pdo->lastInsertId(), 1, 2147483647);
                    self::same($context);
                    $saved = self::rows($pdo, 'SELECT * FROM ' . $context['table'] . ' WHERE mark = ? ORDER BY id FOR UPDATE', [self::MARK]);
                    if (count($saved) !== 1 || self::integer($saved[0]['id'] ?? null, 1, 2147483647) !== $id) self::fail();
                }
                $id = self::verifyRow($saved[0]);
                // The transaction's real table metadata lock now spans this complete re-observation.
                self::compatible($context);
                if (self::metadataAuthority($context) !== $authority) self::fail();
                self::same($context);
                if ($pdo->commit() !== true || $pdo->inTransaction()) self::fail();
                $started = false;
            } catch (\Throwable $error) {
                // A failed/unknown commit is never reported as a confirmed rollback or insert.
                if ($started && $pdo->inTransaction()) {
                    self::same($context);
                    if ($pdo->rollBack() !== true || $pdo->inTransaction()) self::fail();
                }
                self::fail();
            }
            self::same($context);
            $list = self::rows($pdo, 'SELECT * FROM ' . $context['table'] . ' WHERE is_open = 1 AND is_del = 0 ORDER BY id');
            self::same($context);
            if (Cache::set('crontabCache', $list) !== true || Cache::get('crontabCache') !== $list) self::fail();
            self::same($context);
            return ['timer_id' => $id, 'changed' => $changed, 'mark' => self::MARK, 'timeStr' => self::CRON,
                'wake_seconds' => 1, 'gc_period_seconds' => $settings['period'], 'gc_batch' => $settings['batch']];
        } finally {
            if ($attempted) self::settleLock($context, $lockName, $knownAcquired);
        }
    }

    /** Ordinary App path: SELECT own row only; no trigger/schema privilege requirement here. */
    public function tick(): array
    {
        $settings = self::settings(); $context = self::context();
        self::currentRow($context);
        $run = self::due($context, $settings);
        if (!$run) return ['state' => 'waiting', 'wake_seconds' => 1, 'gc_period_seconds' => $settings['period']];
        // An old scheduler callback or a delayed Redis acknowledgment cannot bypass current disable/edit state.
        self::currentRow($context);
        $summary = app()->make(ProductMediaScanStaging::class)->gcBatch($settings['batch']);
        $videoSummary = ProductVideoUploadSessions::needsCollection()
            ? app()->make(ProductVideoUploadSessions::class)->gcBatch($settings['batch']) : null;
        $cloudSummary = ProductVideoCloudUploadSessions::needsCollection()
            ? app()->make(ProductVideoCloudUploadSessions::class)->gcBatch(1) : null;
        self::same($context);
        $result = ['state' => 'ran', 'wake_seconds' => 1, 'gc_period_seconds' => $settings['period'],
            'gc_batch' => $settings['batch'], 'summary' => $summary];
        if ($videoSummary !== null) $result['video_summary'] = $videoSummary;
        if ($cloudSummary !== null) $result['cloud_video_summary'] = $cloudSummary;
        return $result;
    }

    private static function currentRow(array $context): void
    {
        self::same($context);
        $rows = self::rows($context['pdo'], 'SELECT * FROM ' . $context['table'] . ' WHERE mark = ? ORDER BY id', [self::MARK]);
        if (count($rows) !== 1) self::fail();
        self::verifyRow($rows[0]); self::same($context);
    }

    private static function verifyRow(array $row): int
    {
        if (array_keys($row) !== self::COLUMNS) self::fail();
        foreach (self::FIELDS + ['timeStr' => self::CRON] as $key => $expected) {
            if (is_int($expected)) {
                if (self::integer($row[$key], 0, 2147483647) !== $expected) self::fail();
            } elseif (!is_string($row[$key]) || $row[$key] !== $expected) self::fail();
        }
        self::integer($row['last_execution_time'], 0, 2147483647);
        foreach (['next_execution_time', 'add_time', 'update_time'] as $key) self::integer($row[$key], 1, 2147483647);
        return self::integer($row['id'], 1, 2147483647);
    }

    private static function settings(): array
    {
        if (PHP_INT_SIZE < 8) self::fail();
        $config = Config::get('marketplace.product_media_scan');
        $store = Config::get('cache.default');
        $options = is_string($store) ? Config::get('cache.stores.' . $store) : null;
        if (!is_array($config) || !is_array($options) || !is_string($options['type'] ?? null)
            || strcasecmp($options['type'], 'redis') !== 0 || !is_string($options['prefix'] ?? null)
            || strlen($options['prefix']) > 128 || str_contains($options['prefix'], "\0")) self::fail();
        return ['period' => self::integer($config['gc_period_seconds'] ?? null, 1, 86400),
            'batch' => self::integer($config['gc_batch'] ?? null, 1, 256), 'prefix' => $options['prefix'],
            'redis_database' => self::integer($options['select'] ?? 0, 0, 2147483647)];
    }

    private static function due(array $context, array $settings): bool
    {
        $key = $settings['prefix'] . self::PURPOSE . ':timer:last_tick:' . hash('sha256',
            $context['database'] . "\0" . $context['table_name'] . "\0" . (string)$settings['redis_database']);
        $redis = null; $failure = null; $run = false;
        try {
            $redis = BoundedRedisConnection::fromDefaultCacheConfig();
            if ($redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE) !== true
                || $redis->setOption(\Redis::OPT_PREFIX, '') !== true || $redis->getLastError() !== null) self::fail();
            $result = $redis->eval(self::GATE, [$key, (string)$settings['period']], 1);
            if ($redis->getLastError() !== null || !is_array($result) || !array_is_list($result) || count($result) !== 2
                || !in_array($result[0], ['RUN', 'WAIT'], true) || !is_string($result[1])) self::fail();
            self::integer($result[1], 1, 9007199254740991);
            $run = $result[0] === 'RUN';
        } catch (\Throwable $error) { $failure = $error; }
        finally {
            if ($redis !== null) {
                try { if ($redis->close() !== true) $failure ??= new AuthUnavailableException(); }
                catch (\Throwable $error) { $failure ??= $error; }
            }
        }
        if ($failure !== null) self::fail();
        self::same($context); return $run;
    }

    private static function context(): array
    {
        $connection = Db::connect(); $existing = $connection->getPdo();
        if ($existing instanceof PDO && $existing->inTransaction()) self::fail();
        $connection->query('SELECT DATABASE() AS database_name', [], true);
        $pdo = self::pdo($connection);
        if ($pdo->inTransaction()) self::fail();
        $rows = self::rows($pdo, 'SELECT DATABASE() AS database_name,CAST(CONNECTION_ID() AS CHAR) AS session_id,CURRENT_USER() AS `current_user`');
        if (count($rows) !== 1 || array_keys($rows[0]) !== ['database_name', 'session_id', 'current_user']) self::fail();
        $row = $rows[0]; $prefix = $connection->getConfig('prefix');
        if (!is_string($row['database_name']) || preg_match('/\A[^\x00]{1,64}\z/uD', $row['database_name']) !== 1
            || !is_string($row['session_id']) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $row['session_id']) !== 1
            || !is_string($row['current_user']) || $row['current_user'] === '' || !is_string($prefix)
            || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || strlen($prefix . 'system_timer') > 64) self::fail();
        $name = $prefix . 'system_timer';
        return ['connection' => $connection, 'pdo' => $pdo, 'database' => $row['database_name'],
            'session' => $row['session_id'], 'current_user' => $row['current_user'],
            'table_name' => $name, 'table' => '`' . $name . '`'];
    }

    private static function same(array $context): void
    {
        if (self::pdo($context['connection']) !== $context['pdo']) self::fail();
        $rows = self::rows($context['pdo'], 'SELECT DATABASE() AS database_name,CAST(CONNECTION_ID() AS CHAR) AS session_id,CURRENT_USER() AS `current_user`');
        if ($rows !== [['database_name' => $context['database'], 'session_id' => $context['session'],
            'current_user' => $context['current_user']]]) self::fail();
    }

    private static function pdo(ConnectionInterface $connection): PDO
    {
        $pdo = $connection->getPdo();
        if ($connection !== Db::connect() || !$pdo instanceof PDO || $pdo !== Db::connect()->getPdo()
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)) self::fail();
        return $pdo;
    }

    /** Positive, direct database authority for complete trigger observation; explicit CLI only. */
    private static function metadataAuthority(array $context): array
    {
        self::same($context); $account = $context['current_user'];
        if (preg_match('/\A([A-Za-z0-9_.$-]{1,32})@([A-Za-z0-9_.:%-]{1,255})\z/D', $account, $match) !== 1) self::fail();
        $grantee = "'" . $match[1] . "'@'" . $match[2] . "'";
        $rows = self::rows($context['pdo'], 'SELECT GRANTEE,TABLE_CATALOG,TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE'
            . ' FROM information_schema.SCHEMA_PRIVILEGES WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY GRANTEE=BINARY ?'
            . " AND PRIVILEGE_TYPE IN ('SELECT','TRIGGER') ORDER BY BINARY PRIVILEGE_TYPE", [$context['database'], $grantee]);
        if (count($rows) !== 2) self::fail();
        foreach (['SELECT', 'TRIGGER'] as $offset => $privilege) {
            $row = $rows[$offset];
            if (array_keys($row) !== ['GRANTEE', 'TABLE_CATALOG', 'TABLE_SCHEMA', 'PRIVILEGE_TYPE', 'IS_GRANTABLE']
                || $row['GRANTEE'] !== $grantee || $row['TABLE_CATALOG'] !== 'def' || $row['TABLE_SCHEMA'] !== $context['database']
                || $row['PRIVILEGE_TYPE'] !== $privilege || !in_array($row['IS_GRANTABLE'], ['YES', 'NO'], true)) self::fail();
        }
        self::same($context); return [$account, $grantee, $rows];
    }

    private static function compatible(array $context): void
    {
        $pdo = $context['pdo']; $args = [$context['database'], $context['table_name']];
        $tables = self::rows($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES'
            . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?', $args);
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE'
            || strcasecmp((string)$tables[0]['ENGINE'], 'InnoDB') !== 0 || !is_string($tables[0]['TABLE_COLLATION'])
            || preg_match('/\Autf8mb4_[a-z0-9_]+\z/D', $tables[0]['TABLE_COLLATION']) !== 1) self::fail();
        $rows = self::rows($pdo, 'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_KEY,'
            . 'CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS'
            . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION', $args);
        if (count($rows) !== count(self::COLUMNS) || array_column($rows, 'COLUMN_NAME') !== self::COLUMNS) self::fail();
        $short = ['type', 'is_del', 'is_open']; $text = ['name', 'mark', 'content', 'timeStr'];
        foreach ($rows as $row) {
            $name = $row['COLUMN_NAME']; $id = $name === 'id'; $varchar = in_array($name, $text, true); $long = $name === 'customCode';
            $type = $varchar ? 'varchar' : ($long ? 'text' : (in_array($name, $short, true) ? 'tinyint' : 'int'));
            $allowed = $type === 'varchar' ? ['varchar(255)'] : ($type === 'text' ? ['text']
                : ($type === 'tinyint' ? ['tinyint', 'tinyint(1)'] : ['int', 'int(11)']));
            if (strtolower((string)$row['DATA_TYPE']) !== $type || !in_array(strtolower((string)$row['COLUMN_TYPE']), $allowed, true)
                || $row['IS_NULLABLE'] !== 'NO' || $row['EXTRA'] !== ($id ? 'auto_increment' : '')
                || $row['COLUMN_KEY'] !== ($id ? 'PRI' : '') || $row['GENERATION_EXPRESSION'] !== '') self::fail();
            if ($id || $long) { if ($row['COLUMN_DEFAULT'] !== null) self::fail(); }
            elseif ($row['COLUMN_DEFAULT'] === null || (string)$row['COLUMN_DEFAULT'] !== ($varchar ? '' : '0')) self::fail();
            if ($varchar || $long) {
                if ($row['CHARACTER_SET_NAME'] !== 'utf8mb4' || $row['COLLATION_NAME'] !== $tables[0]['TABLE_COLLATION']
                    || ($varchar && self::integer($row['CHARACTER_MAXIMUM_LENGTH'], 1, 2147483647) !== 255)) self::fail();
            } elseif ($row['CHARACTER_MAXIMUM_LENGTH'] !== null || $row['CHARACTER_SET_NAME'] !== null || $row['COLLATION_NAME'] !== null) self::fail();
        }
        $indexes = self::rows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,COLLATION'
            . ' FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?'
            . ' ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX', $args);
        if (count($indexes) !== 1 || $indexes[0]['INDEX_NAME'] !== 'PRIMARY'
            || self::integer($indexes[0]['NON_UNIQUE'], 0, 1) !== 0 || self::integer($indexes[0]['SEQ_IN_INDEX'], 1, 2147483647) !== 1
            || $indexes[0]['COLUMN_NAME'] !== 'id' || $indexes[0]['SUB_PART'] !== null
            || $indexes[0]['INDEX_TYPE'] !== 'BTREE' || $indexes[0]['COLLATION'] !== 'A') self::fail();
        if (self::rows($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS'
            . ' WHERE BINARY TRIGGER_SCHEMA=BINARY ? AND BINARY EVENT_OBJECT_TABLE=BINARY ?', $args) !== []) self::fail();
        self::same($context);
    }

    private static function lockOwner(array $context, string $name): ?string
    {
        self::same($context);
        $rows = self::rows($context['pdo'], 'SELECT CAST(CONNECTION_ID() AS CHAR) AS session_id,CAST(IS_USED_LOCK(?) AS CHAR) AS owner_id', [$name]);
        if (count($rows) !== 1 || array_keys($rows[0]) !== ['session_id', 'owner_id'] || $rows[0]['session_id'] !== $context['session']
            || ($rows[0]['owner_id'] !== null && (!is_string($rows[0]['owner_id'])
                || preg_match('/\A[1-9][0-9]{0,19}\z/D', $rows[0]['owner_id']) !== 1))) self::fail();
        return $rows[0]['owner_id'];
    }

    private static function settleLock(array $context, string $name, bool $knownAcquired): void
    {
        try {
            $owner = self::lockOwner($context, $name);
            if ($owner !== $context['session']) {
                if ($knownAcquired) self::fail();
                return;
            }
        } catch (\Throwable $error) {
            // An unknown ownership observation still permits only one original-session guarded release.
        }
        if (self::pdo($context['connection']) !== $context['pdo']) self::fail();
        $rows = self::rows($context['pdo'], 'SELECT CAST(CONNECTION_ID() AS CHAR) AS session_id,'
            . 'CASE WHEN BINARY CAST(CONNECTION_ID() AS CHAR)=BINARY ? THEN RELEASE_LOCK(?) ELSE NULL END AS released', [$context['session'], $name]);
        if (count($rows) !== 1 || array_keys($rows[0]) !== ['session_id', 'released'] || $rows[0]['session_id'] !== $context['session']
            || !in_array($rows[0]['released'], [1, '1'], true)) self::fail();
    }

    private static function rows(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql); if (!$statement instanceof PDOStatement) self::fail();
        try {
            if ($statement->execute($parameters) !== true) self::fail();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || !array_is_list($rows)) self::fail();
            return $rows;
        } finally { if ($statement->closeCursor() !== true) self::fail(); }
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && (strlen($value) < strlen((string)$maximum) || (strlen($value) === strlen((string)$maximum)
                && strcmp($value, (string)$maximum) <= 0))) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) self::fail();
        return $value;
    }

    private static function fail(): never { throw new AuthUnavailableException(); }
}
