<?php
declare(strict_types=1);

namespace crmeb\services\marketplace;

use crmeb\exceptions\MarketplaceSchemaException as Failure;
use PDO;
use Throwable;

/** This class never initializes App, writes SQL, touches Redis or formats translated errors. */
final class SchemaVersionGate
{
    public const PRODUCT = 'generic-multi-merchant';
    public const MARKER = 'marketplace-ms6-schema-v1';

    public static function manifest(string $path): array
    {
        $stream = null;
        try {
            $before = @lstat($path);
            if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
                || $before['size'] < 1 || $before['size'] > 4096) {
                throw new Failure(Failure::IDENTITY);
            }
            $stream = @fopen($path, 'rb');
            if (!is_resource($stream)) { throw new Failure(Failure::IDENTITY); }
            $opened = fstat($stream);
            $raw = stream_get_contents($stream, 4097);
            $finished = fstat($stream);
            fclose($stream);
            $stream = null;
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($opened) || !is_array($finished) || !is_array($after)
                || !is_string($raw) || strlen($raw) !== $before['size']
                || !self::sameStat($before, $after) || !self::sameStat($opened, $finished)
                || ($opened['mode'] & 0170000) !== 0100000) {
                throw new Failure(Failure::IDENTITY);
            }
            foreach (['dev', 'ino', 'nlink', 'uid', 'gid', 'size', 'mtime'] as $field) {
                if ($before[$field] !== $opened[$field]) { throw new Failure(Failure::IDENTITY); }
            }
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new Failure(Failure::IDENTITY); }
            $value = self::descriptor($value);
            // Canonical bytes also reject duplicate keys, hidden fields and ambiguous JSON forms.
            if (json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" !== $raw) {
                throw new Failure(Failure::IDENTITY);
            }
            return $value;
        } catch (Throwable $error) {
            if (is_resource($stream)) { @fclose($stream); }
            throw new Failure(Failure::IDENTITY);
        }
    }

    private static function sameStat(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if (!array_key_exists($field, $before) || !array_key_exists($field, $after)
                || $before[$field] !== $after[$field]) { return false; }
        }
        return true;
    }

    public static function descriptor(array $value): array
    {
        if (array_keys($value) !== ['product', 'app_version', 'schema_min', 'schema_max']
            || $value['product'] !== self::PRODUCT || !is_string($value['app_version'])
            || !is_int($value['schema_min']) || !is_int($value['schema_max'])
            || $value['schema_min'] < 1 || $value['schema_max'] < $value['schema_min']
            || $value['schema_max'] > 4294967295) {
            throw new Failure(Failure::IDENTITY);
        }
        try { self::version($value['app_version']); }
        catch (Failure $error) { throw new Failure(Failure::IDENTITY); }
        return $value;
    }

    public static function version(string $value): array
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\z/D', $value, $parts)) {
            throw new Failure(Failure::INCOMPATIBLE);
        }
        $parts = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        foreach ($parts as $part) { if ($part > 65535) { throw new Failure(Failure::INCOMPATIBLE); } }
        return $parts;
    }

    public static function compare(string $left, string $right): int
    {
        $a = self::version($left);
        $b = self::version($right);
        foreach ([0, 1, 2] as $index) {
            if ($a[$index] !== $b[$index]) { return $a[$index] <=> $b[$index]; }
        }
        return 0;
    }

    public static function table(string $prefix): string
    {
        return self::identifier($prefix . 'marketplace_schema');
    }

    private static function identifier(string $value): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $value)) { throw new Failure(Failure::INCOMPATIBLE); }
        return $value;
    }

    public static function ddl(string $prefix): string
    {
        $table = self::table($prefix);
        $id = self::identifier($prefix . 'mm_schema_id');
        $version = self::identifier($prefix . 'mm_schema_positive');
        return 'CREATE TABLE `' . $table . "` (\n"
            . "  `id` TINYINT UNSIGNED NOT NULL,\n"
            . "  `schema_version` INT UNSIGNED NOT NULL,\n"
            . "  `min_app_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "  `updated_at` INT UNSIGNED NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . '  CONSTRAINT `' . $id . "` CHECK (`id` = 1),\n"
            . '  CONSTRAINT `' . $version . "` CHECK (`schema_version` >= 1)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='" . self::MARKER . "'";
    }

    /** null means absent only; an existing partial, heterogeneous or empty table is incompatible. */
    public static function inspect(PDO $pdo, string $prefix): ?array
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new Failure(Failure::INCOMPATIBLE); }
            $table = self::table($prefix);
            $idCheck = self::identifier($prefix . 'mm_schema_id');
            $versionCheck = self::identifier($prefix . 'mm_schema_positive');
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($database) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database)) {
                throw new Failure(Failure::INCOMPATIBLE);
            }
            $meta = self::rows($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$database, $table]);
            if ($meta === []) { return null; }
            if (count($meta) !== 1 || $meta[0]['TABLE_TYPE'] !== 'BASE TABLE'
                || strtoupper((string) $meta[0]['ENGINE']) !== 'INNODB'
                || $meta[0]['TABLE_COLLATION'] !== 'utf8mb4_bin'
                || $meta[0]['TABLE_COMMENT'] !== self::MARKER || $meta[0]['AUTO_INCREMENT'] !== null) {
                throw new Failure(Failure::INCOMPATIBLE);
            }
            $columns = self::rows($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$database, $table]);
            $expected = [
                ['id', ['tinyint unsigned', 'tinyint(3) unsigned'], null, null],
                ['schema_version', ['int unsigned', 'int(10) unsigned'], null, null],
                ['min_app_version', ['varchar(32)'], 'ascii', 'ascii_bin'],
                ['updated_at', ['int unsigned', 'int(10) unsigned'], null, null],
            ];
            if (count($columns) !== 4) { throw new Failure(Failure::INCOMPATIBLE); }
            foreach ($expected as $index => [$name, $types, $charset, $collation]) {
                $column = $columns[$index];
                if ($column['COLUMN_NAME'] !== $name || !in_array(strtolower((string) $column['COLUMN_TYPE']), $types, true)
                    || $column['IS_NULLABLE'] !== 'NO' || $column['COLUMN_DEFAULT'] !== null
                    || $column['CHARACTER_SET_NAME'] !== $charset || $column['COLLATION_NAME'] !== $collation
                    || $column['EXTRA'] !== '' || $column['GENERATION_EXPRESSION'] !== '') {
                    throw new Failure(Failure::INCOMPATIBLE);
                }
            }
            $indexes = self::rows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$database, $table]);
            if (count($indexes) !== 1 || $indexes[0]['INDEX_NAME'] !== 'PRIMARY'
                || (string) $indexes[0]['NON_UNIQUE'] !== '0' || (string) $indexes[0]['SEQ_IN_INDEX'] !== '1'
                || $indexes[0]['COLUMN_NAME'] !== 'id' || $indexes[0]['SUB_PART'] !== null
                || $indexes[0]['INDEX_TYPE'] !== 'BTREE' || $indexes[0]['IS_VISIBLE'] !== 'YES'
                || $indexes[0]['EXPRESSION'] !== null) { throw new Failure(Failure::INCOMPATIBLE); }
            $foreign = self::rows($pdo, "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'", [$database, $table]);
            if ($foreign !== []) { throw new Failure(Failure::INCOMPATIBLE); }
            $checks = self::rows($pdo, "SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", [$database, $table]);
            if (count($checks) !== 2) { throw new Failure(Failure::INCOMPATIBLE); }
            $expressions = [$idCheck => 'id=1', $versionCheck => 'schema_version>=1'];
            foreach ($checks as $check) {
                $name = $check['CONSTRAINT_NAME'];
                $expression = strtolower((string) preg_replace('/[\s`()]+/', '', (string) $check['CHECK_CLAUSE']));
                if (!isset($expressions[$name]) || $check['ENFORCED'] !== 'YES' || $expression !== $expressions[$name]) {
                    throw new Failure(Failure::INCOMPATIBLE);
                }
                unset($expressions[$name]);
            }
            if ($expressions !== []) { throw new Failure(Failure::INCOMPATIBLE); }
            $rows = self::rows($pdo, 'SELECT id,schema_version,min_app_version,updated_at FROM `' . $table . '` LIMIT 2', []);
            if (count($rows) !== 1 || (string) $rows[0]['id'] !== '1'
                || !self::uint($rows[0]['schema_version'], 1) || !self::uint($rows[0]['updated_at'], 1)
                || !is_string($rows[0]['min_app_version'])) { throw new Failure(Failure::INCOMPATIBLE); }
            self::version($rows[0]['min_app_version']);
            return ['id' => 1, 'schema_version' => (int) $rows[0]['schema_version'],
                'min_app_version' => $rows[0]['min_app_version'], 'updated_at' => (int) $rows[0]['updated_at']];
        } catch (Failure $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new Failure(Failure::UNAVAILABLE);
        }
    }

    private static function uint($value, int $minimum): bool
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value)) { return false; }
        $text = (string) $value;
        return (strlen($text) < 10 || strcmp($text, '4294967295') <= 0) && (int) $value >= $minimum;
    }

    private static function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false || !$statement->execute($parameters)) { throw new Failure(Failure::UNAVAILABLE); }
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function assertCompatible(PDO $pdo, string $prefix, array $manifest): array
    {
        $manifest = self::descriptor($manifest);
        $row = self::inspect($pdo, $prefix);
        if ($row === null || $row['schema_version'] < $manifest['schema_min']
            || $row['schema_version'] > $manifest['schema_max']
            || self::compare($manifest['app_version'], $row['min_app_version']) < 0) {
            throw new Failure(Failure::INCOMPATIBLE);
        }
        return $row;
    }
}
