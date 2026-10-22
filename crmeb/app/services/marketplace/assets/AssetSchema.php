<?php
declare(strict_types=1);
namespace app\services\marketplace\assets;

use PDO;
use PDOStatement;
use RuntimeException;

/** Fixed asset-domain structure only; no App, connection creation or business-row access. */
final class AssetSchema
{
    public const VERSION = 'marketplace-asset-domains-v1';

    /**
     * Each column is [exact DDL fragment, native type, nullable, default, charset, collation, extra].
     * ENUM literals retain their case and order. No row values or current AUTO_INCREMENT are contracts.
     */
    private static function definitions(): array
    {
        return [
            'marketplace_customer_asset' => [
                'columns' => [
                    'id' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'bigint unsigned', 'NO', null, null, null, 'auto_increment'],
                    'uid' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'purpose' => ['ENUM(\'avatar_public\',\'public_upload_compat\',\'review_public_image\',\'user_promotion_image\',\'user_promotion_qr\',\'legacy_refund_evidence\',\'refund_shipping_proof\',\'order_form_image\',\'withdrawal_qr\',\'agent_licence\',\'order_verify_code\',\'gift_receive_code\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'enum(\'avatar_public\',\'public_upload_compat\',\'review_public_image\',\'user_promotion_image\',\'user_promotion_qr\',\'legacy_refund_evidence\',\'refund_shipping_proof\',\'order_form_image\',\'withdrawal_qr\',\'agent_licence\',\'order_verify_code\',\'gift_receive_code\')', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'state' => ['ENUM(\'STAGED\',\'BOUND\',\'RETIRED\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'STAGED\'', 'enum(\'STAGED\',\'BOUND\',\'RETIRED\')', 'NO', 'STAGED', 'ascii', 'ascii_bin', ''],
                    'storage_type' => ['TINYINT UNSIGNED NOT NULL', 'tinyint unsigned', 'NO', null, null, null, ''],
                    'object_key' => ['VARCHAR(512) NOT NULL', 'varchar(512)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'thumbnail_key' => ['VARCHAR(512) NOT NULL DEFAULT \'\'', 'varchar(512)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'name' => ['VARCHAR(100) NOT NULL', 'varchar(100)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'real_name' => ['VARCHAR(255) NOT NULL', 'varchar(255)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'media_type' => ['VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'varchar(127)', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'byte_size' => ['BIGINT UNSIGNED NOT NULL', 'bigint unsigned', 'NO', null, null, null, ''],
                    'width' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'height' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'content_sha256' => ['BINARY(32) NOT NULL', 'binary(32)', 'NO', null, null, null, ''],
                    'created_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'expires_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'bound_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'retired_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'root_order_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'order_item_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                    'refund_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                    'review_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                    'confirmation_ref' => ['BINARY(32) NULL DEFAULT NULL', 'binary(32)', 'YES', null, null, null, ''],
                    'form_product_id' => ['MEDIUMINT NULL DEFAULT NULL', 'mediumint', 'YES', null, null, null, ''],
                    'form_definition_sha256' => ['BINARY(32) NULL DEFAULT NULL', 'binary(32)', 'YES', null, null, null, ''],
                    'form_field_index' => ['SMALLINT UNSIGNED NULL DEFAULT NULL', 'smallint unsigned', 'YES', null, null, null, ''],
                    'extract_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'agent_apply_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'source_product_id' => ['MEDIUMINT NULL DEFAULT NULL', 'mediumint', 'YES', null, null, null, ''],
                    'source_qrcode_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_uid' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_admin_id' => ['SMALLINT UNSIGNED NULL DEFAULT NULL', 'smallint unsigned', 'YES', null, null, null, ''],
                    'created_by_job' => ['VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'varchar(32)', 'YES', null, 'ascii', 'ascii_bin', ''],
                    'legacy_att_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_storage_object' => [true, ['storage_type', 'object_key']],
                    'uq_legacy_att' => [true, ['legacy_att_id']],
                    'ix_user_purpose' => [false, ['uid', 'purpose', 'state', 'id']],
                    'ix_order_item' => [false, ['root_order_id', 'order_item_id', 'id']],
                    'ix_form' => [false, ['confirmation_ref', 'form_product_id', 'form_field_index', 'id']],
                ],
            ],
            'marketplace_platform_asset_category' => [
                'columns' => [
                    'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null, null, 'auto_increment'],
                    'pid' => ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, null, ''],
                    'name' => ['VARCHAR(50) NOT NULL DEFAULT \'\'', 'varchar(50)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'enname' => ['VARCHAR(50) NOT NULL DEFAULT \'\'', 'varchar(50)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'type' => ['TINYINT NOT NULL DEFAULT 0', 'tinyint', 'NO', '0', null, null, ''],
                    'created_by_admin_id' => ['SMALLINT UNSIGNED NOT NULL', 'smallint unsigned', 'NO', null, null, null, ''],
                    'updated_by_admin_id' => ['SMALLINT UNSIGNED NOT NULL', 'smallint unsigned', 'NO', null, null, null, ''],
                    'created_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'updated_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'legacy_category_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_name' => [true, ['name']],
                    'uq_legacy_category' => [true, ['legacy_category_id']],
                    'ix_parent' => [false, ['pid', 'id']],
                ],
            ],
            'marketplace_platform_asset' => [
                'columns' => [
                    'id' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'bigint unsigned', 'NO', null, null, null, 'auto_increment'],
                    'purpose' => ['ENUM(\'site_image\',\'site_file\',\'channel_qr\',\'routine_download_qr\',\'platform_chat_image\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'enum(\'site_image\',\'site_file\',\'channel_qr\',\'routine_download_qr\',\'platform_chat_image\')', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'category_id' => ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, null, ''],
                    'state' => ['ENUM(\'STAGED\',\'BOUND\',\'RETIRED\') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'STAGED\'', 'enum(\'STAGED\',\'BOUND\',\'RETIRED\')', 'NO', 'STAGED', 'ascii', 'ascii_bin', ''],
                    'storage_type' => ['TINYINT UNSIGNED NOT NULL', 'tinyint unsigned', 'NO', null, null, null, ''],
                    'object_key' => ['VARCHAR(512) NOT NULL', 'varchar(512)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'thumbnail_key' => ['VARCHAR(512) NOT NULL DEFAULT \'\'', 'varchar(512)', 'NO', '', 'utf8mb4', 'utf8mb4_bin', ''],
                    'name' => ['VARCHAR(100) NOT NULL', 'varchar(100)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'real_name' => ['VARCHAR(255) NOT NULL', 'varchar(255)', 'NO', null, 'utf8mb4', 'utf8mb4_bin', ''],
                    'media_type' => ['VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'varchar(127)', 'NO', null, 'ascii', 'ascii_bin', ''],
                    'byte_size' => ['BIGINT UNSIGNED NOT NULL', 'bigint unsigned', 'NO', null, null, null, ''],
                    'width' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'height' => ['INT UNSIGNED NOT NULL DEFAULT 0', 'int unsigned', 'NO', '0', null, null, ''],
                    'content_sha256' => ['BINARY(32) NOT NULL', 'binary(32)', 'NO', null, null, null, ''],
                    'created_at' => ['INT UNSIGNED NOT NULL', 'int unsigned', 'NO', null, null, null, ''],
                    'expires_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'bound_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'retired_at' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_admin_id' => ['SMALLINT UNSIGNED NULL DEFAULT NULL', 'smallint unsigned', 'YES', null, null, null, ''],
                    'created_by_service_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_uid' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'created_by_job' => ['VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'varchar(32)', 'YES', null, 'ascii', 'ascii_bin', ''],
                    'chat_customer_uid' => ['BIGINT NULL DEFAULT NULL', 'bigint', 'YES', null, null, null, ''],
                    'chat_is_tourist' => ['TINYINT UNSIGNED NULL DEFAULT NULL', 'tinyint unsigned', 'YES', null, null, null, ''],
                    'chat_service_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'chat_service_uid' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'channel_qrcode_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'int unsigned', 'YES', null, null, null, ''],
                    'routine_version' => ['VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'varchar(64)', 'YES', null, 'ascii', 'ascii_bin', ''],
                    'legacy_att_id' => ['INT NULL DEFAULT NULL', 'int', 'YES', null, null, null, ''],
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_storage_object' => [true, ['storage_type', 'object_key']],
                    'uq_legacy_att' => [true, ['legacy_att_id']],
                    'ix_category' => [false, ['category_id', 'id']],
                    'ix_purpose' => [false, ['purpose', 'state', 'id']],
                    'ix_chat' => [false, ['chat_customer_uid', 'chat_service_uid', 'chat_is_tourist', 'id']],
                ],
            ],
        ];
    }

    /** Only the three literal suffixes and the caller's validated actual prefix can form a table. */
    private static function physical(string $suffix, string $prefix): string
    {
        if (!array_key_exists($suffix, self::definitions())
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $prefix) !== 1
            || strlen($prefix . $suffix) > 64) {
            throw new RuntimeException('Asset schema identifier unavailable');
        }
        return $prefix . $suffix;
    }

    /** Exactly the recorded DDL, including its final semicolon, with only the real prefix substituted. */
    public static function createSql(string $suffix, string $prefix): string
    {
        $table = self::physical($suffix, $prefix);
        $definition = self::definitions()[$suffix];
        $parts = [];
        foreach ($definition['columns'] as $name => $column) $parts[] = chr(96) . $name . chr(96) . ' ' . $column[0];
        foreach ($definition['indexes'] as $name => [$unique, $columns]) {
            $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . chr(96) . $name . chr(96))
                . ' (' . implode(',', array_map(static fn(string $column): string => chr(96) . $column . chr(96), $columns)) . ')';
        }
        return 'CREATE TABLE ' . chr(96) . $table . chr(96) . " (\n  " . implode(",\n  ", $parts)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
    }

    /** SELECT-only full inspection requires proven direct metadata authority before any table observation. */
    public static function inspect(PDO $pdo, string $prefix, string $database): array
    {
        self::admit($pdo, $prefix, $database);
        $authority = self::metadataAuthority($pdo, $database);
        $issues = []; $pending = []; $tables = [];
        foreach (self::definitions() as $suffix => $definition) {
            $meta = self::metadata($pdo, $database, self::physical($suffix, $prefix));
            if ($meta === null) {
                $pending[] = $suffix;
                $tables[$suffix] = 'absent';
            } elseif (!self::sameTable($meta, $definition)) {
                $issues[] = ['kind' => 'incompatible_table', 'target' => $suffix];
                $tables[$suffix] = 'incompatible';
            } else {
                $tables[$suffix] = 'compatible';
            }
        }
        if (self::scalar($pdo, 'SELECT DATABASE()') !== $database) {
            throw new RuntimeException('Asset schema database changed');
        }
        if (self::metadataAuthority($pdo, $database) !== $authority) {
            throw new RuntimeException('Asset metadata authority changed');
        }
        return ['issues' => $issues, 'pending' => $pending, 'tables' => $tables];
    }

    /** Full structural authority is required; this is not a runtime DML-only visibility assertion. */
    public static function assertComplete(PDO $pdo, string $prefix, string $database): void
    {
        $view = self::inspect($pdo, $prefix, $database);
        if ($view['issues'] !== [] || $view['pending'] !== []) throw new RuntimeException('Asset schema unavailable');
    }

    /** Private direct-grant proof only. Unsupported account/grant forms cannot prove absence. */
    private static function metadataAuthority(PDO $pdo, string $database): array
    {
        $account = self::scalar($pdo, 'SELECT CURRENT_USER()');
        if (!is_string($account)
            || preg_match('/\A([A-Za-z0-9_.$-]{1,32})@([A-Za-z0-9_.:%-]{1,255})\z/D', $account, $match) !== 1) {
            throw new RuntimeException('Asset metadata authority unavailable');
        }
        $grantee = "'" . $match[1] . "'@'" . $match[2] . "'";
        $rows = self::rows($pdo, 'SELECT GRANTEE,TABLE_CATALOG,TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE'
            . ' FROM information_schema.SCHEMA_PRIVILEGES WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY GRANTEE=BINARY ?'
            . " AND PRIVILEGE_TYPE IN ('SELECT','TRIGGER') ORDER BY BINARY PRIVILEGE_TYPE", [$database, $grantee]);
        if (count($rows) !== 2) throw new RuntimeException('Asset metadata authority unavailable');
        foreach (['SELECT', 'TRIGGER'] as $offset => $privilege) {
            $row = $rows[$offset];
            if (array_keys($row) !== ['GRANTEE', 'TABLE_CATALOG', 'TABLE_SCHEMA', 'PRIVILEGE_TYPE', 'IS_GRANTABLE']
                || $row['GRANTEE'] !== $grantee || $row['TABLE_CATALOG'] !== 'def' || $row['TABLE_SCHEMA'] !== $database
                || $row['PRIVILEGE_TYPE'] !== $privilege || !in_array($row['IS_GRANTABLE'], ['YES', 'NO'], true)) {
                throw new RuntimeException('Asset metadata authority unavailable');
            }
        }
        if (self::scalar($pdo, 'SELECT CURRENT_USER()') !== $account) throw new RuntimeException('Asset metadata authority changed');
        return [$account, $grantee, $rows];
    }

    private static function admit(PDO $pdo, string $prefix, string $database): void
    {
        foreach (array_keys(self::definitions()) as $suffix) self::physical($suffix, $prefix);
        if (PHP_INT_SIZE < 8 || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)
            || self::scalar($pdo, 'SELECT DATABASE()') !== $database) {
            throw new RuntimeException('Asset schema connection unavailable');
        }
        $version = self::scalar($pdo, 'SELECT VERSION()');
        if (!is_string($version) || preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) !== 1
            || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            throw new RuntimeException('Asset schema server unavailable');
        }
        $modes = self::rows($pdo, 'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode');
        if (count($modes) !== 1) throw new RuntimeException('Asset schema mode unavailable');
        foreach (['session_mode', 'global_mode'] as $key) {
            if (!isset($modes[0][$key]) || !is_string($modes[0][$key])) throw new RuntimeException('Asset schema mode unavailable');
            $flags = explode(',', $modes[0][$key]);
            if (!in_array('STRICT_TRANS_TABLES', $flags, true) && !in_array('STRICT_ALL_TABLES', $flags, true)) {
                throw new RuntimeException('Asset schema strict mode unavailable');
            }
        }
    }

    private static function sameTable(array $meta, array $definition): bool
    {
        if ($meta['table']['TABLE_TYPE'] !== 'BASE TABLE' || $meta['table']['ENGINE'] !== 'InnoDB'
            || $meta['table']['TABLE_COLLATION'] !== 'utf8mb4_bin' || $meta['table']['TABLE_COMMENT'] !== ''
            || array_keys($meta['columns']) !== array_keys($definition['columns'])
            || $meta['foreign'] !== [] || $meta['checks'] !== [] || $meta['triggers'] !== []) return false;
        foreach ($definition['columns'] as $name => $column) {
            $row = $meta['columns'][$name];
            if ([self::nativeType($row['COLUMN_TYPE']), $row['IS_NULLABLE'], $row['COLUMN_DEFAULT'],
                $row['CHARACTER_SET_NAME'], $row['COLLATION_NAME'], $row['EXTRA']] !== array_slice($column, 1)
                || $row['GENERATION_EXPRESSION'] !== '' || $row['COLUMN_COMMENT'] !== '') return false;
        }
        $indexes = $definition['indexes']; ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => [$unique, $columns]) {
            $rows = $meta['indexes'][$name];
            if (count($rows) !== count($columns)) return false;
            foreach ($rows as $offset => $row) {
                if ((string)$row['SEQ_IN_INDEX'] !== (string)($offset + 1)
                    || (string)$row['NON_UNIQUE'] !== ($unique ? '0' : '1') || $row['COLUMN_NAME'] !== $columns[$offset]
                    || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
                    || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A' || $row['INDEX_COMMENT'] !== '') return false;
            }
        }
        $wanted = [];
        foreach ($indexes as $name => [$unique]) if ($unique) $wanted[$name] = [$name === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE', 'YES'];
        $actual = [];
        foreach ($meta['constraints'] as $row) {
            if (array_key_exists($row['CONSTRAINT_NAME'], $actual)) return false;
            $actual[$row['CONSTRAINT_NAME']] = [$row['CONSTRAINT_TYPE'], $row['ENFORCED']];
        }
        ksort($wanted, SORT_STRING); ksort($actual, SORT_STRING);
        return $actual === $wanted;
    }

    /** Normalize only the documented integer display width, never an ENUM value, unsignedness or arbitrary type. */
    private static function nativeType(mixed $type): ?string
    {
        if (!is_string($type)) return null;
        if (preg_match('/\A(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)( unsigned)?\z/D', $type, $match) === 1) {
            return $match[1] . ($match[2] ?? '');
        }
        return $type;
    }

    private static function metadata(PDO $pdo, string $database, string $table): ?array
    {
        $args = [$database, $table];
        $tables = self::rows($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES'
            . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?', $args);
        if ($tables === []) return null;
        if (count($tables) !== 1) throw new RuntimeException('Asset table metadata unavailable');
        $columns = [];
        foreach (self::rows($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,'
            . 'EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS'
            . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY ORDINAL_POSITION', $args) as $row) {
            if ((string)$row['ORDINAL_POSITION'] !== (string)(count($columns) + 1) || array_key_exists($row['COLUMN_NAME'], $columns)) {
                throw new RuntimeException('Asset column metadata unavailable');
            }
            $columns[$row['COLUMN_NAME']] = $row;
        }
        $indexes = [];
        foreach (self::rows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,'
            . 'EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS'
            . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX', $args) as $row) {
            $indexes[$row['INDEX_NAME']][] = $row;
        }
        ksort($indexes, SORT_STRING);
        return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
            'constraints' => self::rows($pdo, 'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS'
                . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? ORDER BY BINARY CONSTRAINT_NAME', $args),
            'foreign' => self::rows($pdo, 'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
                . ' WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? AND REFERENCED_TABLE_NAME IS NOT NULL'
                . ' ORDER BY BINARY CONSTRAINT_NAME,ORDINAL_POSITION', $args),
            'checks' => self::rows($pdo, "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS"
                . " WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ? AND CONSTRAINT_TYPE='CHECK' ORDER BY BINARY CONSTRAINT_NAME", $args),
            'triggers' => self::rows($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS'
                . ' WHERE BINARY EVENT_OBJECT_SCHEMA=BINARY ? AND BINARY EVENT_OBJECT_TABLE=BINARY ? ORDER BY BINARY TRIGGER_NAME', $args)];
    }

    private static function rows(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        if (!$statement instanceof PDOStatement || $statement->execute($parameters) !== true) {
            throw new RuntimeException('Asset schema read unavailable');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || !array_is_list($rows) || $statement->closeCursor() !== true) {
            throw new RuntimeException('Asset schema metadata unavailable');
        }
        return $rows;
    }

    private static function scalar(PDO $pdo, string $sql): mixed
    {
        $rows = self::rows($pdo, $sql);
        if (count($rows) !== 1 || count($rows[0]) !== 1) throw new RuntimeException('Asset schema scalar unavailable');
        return $rows[0][array_key_first($rows[0])];
    }
}
