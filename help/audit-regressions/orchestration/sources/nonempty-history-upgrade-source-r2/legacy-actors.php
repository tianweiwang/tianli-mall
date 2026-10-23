<?php
declare(strict_types=1);

/** Finite synthetic actors for the complete 477 source. Definitions only.
 * The caller owns Source/role qualification and private credential storage.
 * No App/autoloader, resource, HTTP, wallet, order, refund or review is created here.
 * Snapshot helpers below hold private native rows; never publish those arrays.
 */
function m4lh_actor_expect(bool $ok, string $code): void
{
    if (!$ok) throw new RuntimeException($code);
}
function m4lh_actor_keys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($keys, SORT_STRING);
    m4lh_actor_expect($actual === $keys, 'M4LH_ACTOR_EXACT_KEYS');
}
function m4lh_actor_tables(): array
{
    return ['eb_user', 'eb_system_admin', 'eb_user_address', 'eb_store_product',
        'eb_store_product_attr', 'eb_store_product_attr_value', 'eb_shipping_templates', 'eb_express'];
}
function m4lh_actor_source(string $root): array
{
    m4lh_actor_expect(function_exists('m4m_metadata') && function_exists('m4lh_cfg_source'), 'M4LH_ACTOR_PURE_HELPERS_REQUIRED');
    $source = m4lh_cfg_source($root);
    $pins = [
        'crmeb/app/services/user/LoginServices.php' => [22710, 'ac5ecb8f9405683bbc6c194ac20ffa2189668e26a5691424b78661a41580257f'],
        'crmeb/app/services/system/admin/SystemAdminServices.php' => [27119, 'fbafecb614824ca16f75a521757d72a78fe6b94a865c090cde2e1b55023acab6'],
        'crmeb/app/services/BaseServices.php' => [7493, '23444cf1057598bd72b284ce29cc1419bb62019664ef24a271b9c5cafcfcdadb'],
        'crmeb/app/dao/user/UserDao.php' => [10898, '5753f9387dfae1f2b68ff83db91a480265e573226c43ff1e9c3fb02e5522e67d'],
        'crmeb/app/model/user/User.php' => [10133, 'cc9d08ec02188b8fcffe4ddba87444d6b52d5250491a8b0de88aee2a740f5181'],
        'crmeb/public/statics/system_images/spread_level_1.png' => [53659, '24f2038472ec8158292c74539610b241b74dbc49c8dc03b8b62b160a13b4b18d'],
    ];
    foreach ($pins as $relative => [$bytes, $sha]) {
        $path = $root . '/' . $relative;
        m4lh_actor_expect(is_file($path) && !is_link($path) && realpath($path) === $path
            && filesize($path) === $bytes && hash_file('sha256', $path) === $sha, 'M4LH_ACTOR_EXACT_477_SOURCE');
    }
    return $source;
}
function m4lh_actor_private(array $private): void
{
    m4lh_actor_keys($private, ['user_account', 'user_password', 'admin_account', 'admin_password']);
    foreach (['user_account', 'admin_account'] as $key)
        m4lh_actor_expect(is_string($private[$key]) && preg_match('/\A[a-z0-9_]{10,32}\z/D', $private[$key]) === 1,
            'M4LH_ACTOR_PRIVATE_ACCOUNT_FORMAT');
    foreach (['user_password', 'admin_password'] as $key)
        m4lh_actor_expect(is_string($private[$key]) && strlen($private[$key]) >= 6 && strlen($private[$key]) <= 32
            && $private[$key] !== '123456' && !str_contains($private[$key], "\0"), 'M4LH_ACTOR_PRIVATE_PASSWORD_FORMAT');
    m4lh_actor_expect($private['user_account'] !== $private['admin_account']
        && $private['user_password'] !== $private['admin_password'], 'M4LH_ACTOR_DISTINCT_PRIVATE_CREDENTIALS');
}
function m4lh_actor_identity(PDO $db, string $runId): void
{
    m4lh_actor_expect(preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $runId) === 1
        && $runId === getenv('AUDIT_RUN_ID') && !$db->inTransaction() && PHP_INT_SIZE >= 8
        && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        && $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
        && $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0, 'M4LH_ACTOR_NATIVE_DML_PDO');
    m4lh_actor_expect($db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
        && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_app@%'
        && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [$runId]
        && audit_grants_are_restricted($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)), 'M4LH_ACTOR_OWN_DML_SCHEMA_SENTINEL');
}
function m4lh_actor_native(array $column, mixed $value): mixed
{
    if ($value === null) {
        m4lh_actor_expect($column['IS_NULLABLE'] === 'YES', 'M4LH_ACTOR_REAL_NULL_DEFAULT'); return null;
    }
    if (preg_match('/\A(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?( unsigned)?\z/D',
        strtolower($column['COLUMN_TYPE']), $type) === 1) {
        m4lh_actor_expect((is_string($value) || is_int($value))
            && preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', (string) $value) === 1, 'M4LH_ACTOR_INTEGER_INPUT');
        $bits = ['tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'bigint' => 64][$type[1]];
        $unsigned = isset($type[2]) && $type[2] !== ''; $power = bcpow('2', (string) ($unsigned ? $bits : $bits - 1), 0);
        m4lh_actor_expect(bccomp((string) $value, $unsigned ? '0' : '-' . $power, 0) >= 0
            && bccomp((string) $value, bcsub($power, '1', 0), 0) <= 0, 'M4LH_ACTOR_NATIVE_INTEGER_RANGE');
        return bccomp((string) $value, (string) PHP_INT_MAX, 0) <= 0 ? (int) $value : (string) $value;
    }
    m4lh_actor_expect(is_string($value), 'M4LH_ACTOR_NONINTEGER_NATIVE_STRING'); return $value;
}
function m4lh_actor_projection(array $rows): array
{
    $values = array_map(static fn(array $row): string => serialize($row), $rows); sort($values, SORT_STRING);
    $bytes = serialize($values);
    return ['rows' => count($rows), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
}
function m4lh_actor_capture(PDO $db): array
{
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $snapshot = ['names' => $names, 'modes' => m4m_rows($db,
        'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,'
        . '@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb,'
        . '@@SESSION.auto_increment_increment AS session_increment,@@GLOBAL.auto_increment_increment AS global_increment,'
        . '@@SESSION.auto_increment_offset AS session_offset,@@GLOBAL.auto_increment_offset AS global_offset'),
        'tables' => [], 'private_rows' => []];
    m4lh_actor_expect(count($snapshot['modes']) === 1, 'M4LH_ACTOR_ACTUAL_NATIVE_MODES');
    foreach (['session_increment', 'global_increment', 'session_offset', 'global_offset'] as $key)
        m4lh_actor_expect($snapshot['modes'][0][$key] === 1, 'M4LH_ACTOR_UNIT_NATIVE_COUNTERS');
    foreach ($names as $name) {
        m4lh_actor_expect(is_string($name) && preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D', $name) === 1,
            'M4LH_ACTOR_COMPLETE_OWN_TABLE_NAMESPACE');
        $metadata = m4m_metadata($db, $name); $columns = array_keys($metadata['columns']);
        $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($name));
        foreach ($rows as $row) m4lh_actor_expect(array_keys($row) === $columns, 'M4LH_ACTOR_FULL_NATIVE_COLUMN_ORDER');
        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);
        m4lh_actor_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'M4LH_ACTOR_COMPLETE_NATIVE_DDL');
        $snapshot['tables'][$name] = ['metadata' => $metadata, 'columns' => $columns,
            'ddl' => $ddl[1], 'projection' => m4lh_actor_projection($rows)];
        if (in_array($name, m4lh_actor_tables(), true)) {
            usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));
            $snapshot['private_rows'][$name] = $rows;
        }
    }
    m4lh_actor_expect(array_diff(m4lh_actor_tables(), $names) === [], 'M4LH_ACTOR_ALL_EIGHT_REAL_TABLES');
    return $snapshot;
}
function m4lh_actor_expected_row(array $metadata, array $values): array
{
    m4lh_actor_expect(array_diff(array_keys($values), array_keys($metadata['columns'])) === [], 'M4LH_ACTOR_ONLY_REAL_COLUMNS');
    $expected = [];
    foreach ($metadata['columns'] as $name => $column) {
        m4lh_actor_expect($column['GENERATION_EXPRESSION'] === '', 'M4LH_ACTOR_NO_GENERATED_FIXTURE_COLUMN');
        $value = array_key_exists($name, $values) ? $values[$name] : $column['COLUMN_DEFAULT'];
        $expected[$name] = m4lh_actor_native($column, $value);
    }
    return $expected;
}
function m4lh_actor_next(array $table, string $pk): int
{
    $value = $table['metadata']['table']['AUTO_INCREMENT'];
    m4lh_actor_expect((is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) === 1
        && bccomp((string) $value, '4294967294', 0) <= 0
        && $table['metadata']['columns'][$pk]['EXTRA'] === 'auto_increment', 'M4LH_ACTOR_BEFORE_NATIVE_ID');
    m4lh_actor_native($table['metadata']['columns'][$pk], $value); return (int) $value;
}
function m4lh_actor_counter_ddl(string $ddl, int $old, int $next): string
{
    $changes = 0;
    $expected = preg_replace_callback('/(\) ENGINE=InnoDB)(?: AUTO_INCREMENT=([1-9][0-9]*))?(?= )/',
        static function (array $match) use ($old, $next): string {
            m4lh_actor_expect(isset($match[2]) ? $match[2] === (string) $old : $old === 1, 'M4LH_ACTOR_ORIGINAL_DDL_COUNTER');
            return $match[1] . ' AUTO_INCREMENT=' . $next;
        }, $ddl, -1, $changes);
    m4lh_actor_expect($changes === 1 && is_string($expected), 'M4LH_ACTOR_ONE_DDL_COUNTER_OPTION'); return $expected;
}
function m4lh_actor_expected(array $before, array $append, array $insertCounts): array
{
    $expected = $before;
    foreach ($append as $table => $rows) {
        m4lh_actor_expect(in_array($table, m4lh_actor_tables(), true) && is_array($rows) && array_is_list($rows), 'M4LH_ACTOR_FINITE_EXPECTED_TABLE');
        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));
        $expected['private_rows'][$table] = $rows;
        $expected['tables'][$table]['projection'] = m4lh_actor_projection($rows);
        $count = $insertCounts[$table] ?? 0;
        if ($count !== 0) {
            m4lh_actor_expect($count === 1, 'M4LH_ACTOR_ONE_INSERT_PER_REAL_TABLE');
            $old = $before['tables'][$table]['metadata']['table']['AUTO_INCREMENT']; $next = (int) $old + 1;
            $expected['tables'][$table]['metadata']['table']['AUTO_INCREMENT'] = is_int($old) ? $next : (string) $next;
            $expected['tables'][$table]['ddl'] = m4lh_actor_counter_ddl($before['tables'][$table]['ddl'], (int) $old, $next);
        }
    }
    return $expected;
}
function m4lh_actor_insert(PDO $db, string $table, string $pk, array $values, array $expected): void
{
    m4lh_actor_expect($db->inTransaction() && in_array($table, m4lh_actor_tables(), true)
        && $table !== 'eb_shipping_templates'
        && $pk === ($table === 'eb_user' ? 'uid' : 'id') && !array_key_exists($pk, $values), 'M4LH_ACTOR_NATIVE_INSERT_ONLY');
    if ($table === 'eb_user') m4lh_actor_expect(($values['now_money'] ?? null) === '0.00'
        && !array_key_exists('pwd', $values), 'M4LH_ACTOR_ZERO_BALANCE_AND_NO_SQL_PASSWORD');
    $columns = array_keys($values);
    $statement = $db->prepare('INSERT INTO ' . m4m_quote($table) . ' (' . implode(',', array_map('m4m_quote', $columns))
        . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach (array_values($values) as $index => $value)
        m4lh_actor_expect($statement->bindValue($index + 1, $value === null ? null : (string) $value,
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR), 'M4LH_ACTOR_NATIVE_BIND');
    m4lh_actor_expect($statement->execute() && $statement->rowCount() === 1
        && $db->lastInsertId() === (string) $expected[$pk], 'M4LH_ACTOR_REAL_NATURAL_PRIMARY_KEY');
    m4lh_actor_expect(m4m_rows($db, 'SELECT * FROM ' . m4m_quote($table) . ' WHERE ' . m4m_quote($pk) . '=?', [$expected[$pk]]) === [$expected],
        'M4LH_ACTOR_EVERY_COLUMN_FROM_BEFORE_AND_INPUT');
}

/** Return only public IDs/normal inputs. Accounts/passwords remain caller-private. */
function m4lh_actors_prepare(PDO $db, string $runId, int $actualSelfMerId, array $configReceipt,
    array $normalInputs, array $private, string $oldRoot = '/legacy-source'): array
{
    $source = m4lh_actor_source($oldRoot); m4lh_actor_private($private); m4lh_actor_identity($db, $runId);
    m4lh_actor_keys($normalInputs, ['site_url']);
    m4lh_actor_expect(($configReceipt['schema'] ?? null) === 1
        && ($configReceipt['scope'] ?? '') === 'legacy_normal_config_fixture_only'
        && ($configReceipt['run_id'] ?? '') === $runId && ($configReceipt['source'] ?? null) === $source
        && ($configReceipt['normal_inputs'] ?? null) === $normalInputs && $actualSelfMerId > 0,
        'M4LH_ACTOR_ACTUAL_CONFIGURATION_RECEIPT');
    $category = $configReceipt['category_id'] ?? null; $label = $configReceipt['purchase_label_id'] ?? null;
    m4lh_actor_expect(is_int($category) && $category > 0 && is_int($label) && $label > 0, 'M4LH_ACTOR_REAL_LABEL_IDS');
    $self = m4m_rows($db, 'SELECT * FROM eb_marketplace_merchant WHERE mer_id=?', [$actualSelfMerId]);
    m4lh_actor_expect(count($self) === 1 && (int) $self[0]['mer_id'] === $actualSelfMerId, 'M4LH_ACTOR_ORIGINAL_INITIALIZER_SELF_ROW');
    $categories = m4m_rows($db, 'SELECT * FROM eb_category WHERE id=?', [$category]);
    $labels = m4m_rows($db, 'SELECT * FROM eb_user_label WHERE id=?', [$label]);
    m4lh_actor_expect(count($categories) === 1 && $categories[0]['name'] === 'normal-label-category-' . $runId
        && (int) $categories[0]['type'] === 0 && (int) $categories[0]['owner_id'] === 0 && (int) $categories[0]['pid'] === 0
        && count($labels) === 1 && (int) $labels[0]['label_cate'] === $category
        && $labels[0]['label_name'] === 'normal-purchase-label-' . $runId, 'M4LH_ACTOR_ORIGINAL_CONFIGURATION_LABEL_ROWS');
    $before = m4lh_actor_capture($db);
    m4lh_actor_expect(!isset($before['tables']['eb_marketplace_order_idempotency'])
        && !isset($before['tables']['eb_marketplace_stock_movement'])
        && !isset($before['tables']['eb_store_order']['metadata']['columns']['marketplace_trade_version']), 'M4LH_ACTOR_PRE_MS4_STAGE');
    foreach (M4M_ADDITIONS as $table => $columns) foreach ($columns as $column)
        m4lh_actor_expect(!isset($before['tables'][$table]['metadata']['columns'][$column]), 'M4LH_ACTOR_ALL_SEVEN_PRE_MS4_COLUMNS_ABSENT');
    m4lh_actor_expect(m4m_rows($db, 'SELECT uid FROM eb_user WHERE account=? OR phone=?', [$private['user_account'], $private['user_account']]) === []
        && m4m_rows($db, 'SELECT id FROM eb_system_admin WHERE account=?', [$private['admin_account']]) === [], 'M4LH_ACTOR_CREATE_ONLY_ACCOUNTS');
    $defaultTemplate = m4lh_actor_expected_row($before['tables']['eb_shipping_templates']['metadata'],
        ['id' => 1, 'name' => '默认模版', 'type' => 1, 'appoint' => 0, 'no_delivery' => 0, 'sort' => 0, 'add_time' => 1658394592]);
    m4lh_actor_expect($before['private_rows']['eb_shipping_templates'] === [$defaultTemplate]
        && in_array($before['tables']['eb_shipping_templates']['metadata']['table']['AUTO_INCREMENT'], [2, '2'], true),
        'M4LH_ACTOR_ORIGINAL_INITIALIZER_TEMPLATE_AND_COUNTER');
    m4lh_actor_expect(m4m_rows($db, 'SELECT id FROM eb_express WHERE code=?', ['shunfeng']) === [], 'M4LH_ACTOR_CREATE_ONLY_MANUAL_COURIER');
    $now = time(); $ids = ['eb_shipping_templates' => 1];
    foreach (m4lh_actor_tables() as $table) if ($table !== 'eb_shipping_templates')
        $ids[$table] = m4lh_actor_next($before['tables'][$table], $table === 'eb_user' ? 'uid' : 'id');
    $image = '/statics/system_images/spread_level_1.png'; $unique = substr(hash('sha256', 'legacy-sku-' . $runId), 0, 8);
    $adminDigest = password_hash($private['admin_password'], PASSWORD_BCRYPT);
    m4lh_actor_expect(is_string($adminDigest) && password_verify($private['admin_password'], $adminDigest)
        && password_get_info($adminDigest)['algoName'] === 'bcrypt', 'M4LH_ACTOR_REAL_PLATFORM_BCRYPT');
    $values = [
        'eb_user' => ['account' => $private['user_account'], 'nickname' => '旧历史普通顾客', 'user_type' => 'h5',
            'now_money' => '0.00', 'is_money_level' => 0, 'status' => 1, 'is_del' => 0, 'spread_uid' => 0, 'add_time' => $now],
        'eb_system_admin' => ['account' => $private['admin_account'], 'pwd' => $adminDigest, 'real_name' => '隔离平台管理员',
            'roles' => '', 'level' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => $now],
        'eb_user_address' => ['uid' => $ids['eb_user'], 'real_name' => '隔离普通顾客', 'phone' => '00000000000',
            'province' => '验证省', 'city' => '验证市', 'district' => '验证区', 'detail' => '专用旧历史地址', 'is_default' => 1, 'add_time' => $now],
        'eb_store_product' => ['mer_id' => $actualSelfMerId, 'store_name' => '旧历史商品-' . $runId, 'unit_name' => '件',
            'spec_type' => 1, 'stock' => 10, 'sales' => 0, 'price' => '20.15', 'cost' => '7.45', 'postage' => '0.00',
            'freight' => 1, 'temp_id' => $ids['eb_shipping_templates'], 'is_postage' => 1, 'is_show' => 1, 'review_status' => 2,
            'is_vip' => 0, 'logistics' => '1', 'min_qty' => 1, 'is_limit' => 0, 'label_id' => (string) $label,
            'image' => $image, 'slider_image' => json_encode([$image], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'custom_form' => '[]', 'add_time' => $now],
        'eb_store_product_attr' => ['product_id' => $ids['eb_store_product'], 'type' => 0, 'attr_name' => '规格', 'attr_values' => '普通'],
        'eb_store_product_attr_value' => ['product_id' => $ids['eb_store_product'], 'type' => 0, 'unique' => $unique,
            'suk' => '普通', 'stock' => 10, 'sales' => 0, 'price' => '20.15', 'cost' => '7.45', 'is_show' => 1,
            'is_virtual' => 0, 'weight' => '0.00', 'volume' => '0.00', 'image' => $image],
        'eb_express' => ['code' => 'shunfeng', 'name' => '顺丰速运', 'partner_id' => 1, 'is_show' => 1, 'status' => 0],
    ];
    $planned = []; $rows = $before['private_rows']; $counts = [];
    foreach ($values as $table => $input) {
        $pk = $table === 'eb_user' ? 'uid' : 'id';
        $planned[$table] = m4lh_actor_expected_row($before['tables'][$table]['metadata'], [$pk => $ids[$table]] + $input);
        $rows[$table][] = $planned[$table]; $counts[$table] = 1;
    }
    $expected = m4lh_actor_expected($before, $rows, $counts); $committed = false;
    m4lh_actor_expect($db->beginTransaction(), 'M4LH_ACTOR_OWN_TRANSACTION_BEGIN');
    try {
        m4lh_actor_expect(m4lh_actor_capture($db) === $before, 'M4LH_ACTOR_BEFORE_FULL_DATABASE_RECHECK');
        foreach ($values as $table => $input) m4lh_actor_insert($db, $table, $table === 'eb_user' ? 'uid' : 'id', $input, $planned[$table]);
        m4lh_actor_expect(m4lh_actor_capture($db) === $expected, 'M4LH_ACTOR_ONLY_PLANNED_FULL_NATIVE_DELTA');
        m4lh_actor_expect($db->commit(), 'M4LH_ACTOR_COMMIT'); $committed = true;
        m4lh_actor_expect(m4lh_actor_capture($db) === $expected, 'M4LH_ACTOR_COMMITTED_FULL_DATABASE_RECHECK');
        m4lh_actor_expect(m4lh_actor_source($oldRoot) === $source, 'M4LH_ACTOR_FIXED_SOURCE_AFTER');
        return ['schema' => 1, 'scope' => 'legacy_normal_actors_fixture_only', 'run_id' => $runId,
            'legacy_source_sha' => $source['source_sha'], 'self_operated_mer_id' => $actualSelfMerId,
            'uid' => $ids['eb_user'], 'admin_id' => $ids['eb_system_admin'], 'address_id' => $ids['eb_user_address'],
            'product_id' => $ids['eb_store_product'], 'attr_id' => $ids['eb_store_product_attr'], 'sku_id' => $ids['eb_store_product_attr_value'],
            'shipping_template_id' => $ids['eb_shipping_templates'], 'shipping_template_source' => 'initializer_original_install_row',
            'express_id' => $ids['eb_express'],
            'purchase_label_id' => $label, 'category_id' => $category, 'sku_unique' => $unique,
            'sku_suk' => $planned['eb_store_product_attr_value']['suk'],
            'express_code' => $planned['eb_express']['code'], 'express_name' => $planned['eb_express']['name'],
            'fixture_time' => $now,
            'price' => '20.15', 'cost' => '7.45', 'initial_stock' => 10, 'qty_per_order' => 1,
            'public_image_path' => $image, 'old_public_image_file' => 'crmeb/public' . $image,
            'manual_delivery' => ['type' => 1, 'express_record_type' => 1, 'delivery_code' => 'shunfeng', 'delivery_name' => '顺丰速运'],
            'native_insert_counts' => $counts, 'full_other_database_preserved' => true,
            'initial_user_balance' => '0.00', 'password_reset' => 'REQUIRED_REAL_OLD_SERVICE',
            'HTTP_login_executed' => false, 'wallet_order_refund_review_created' => false,
            'redis_and_files_observation' => 'CALLER_REQUIRED', 'business_or_migration_pass' => false];
    } catch (Throwable $error) {
        if (!$committed && $db->inTransaction()) $db->rollBack();
        // A failed insert may consume native auto-increment values. Never retry or reset them.
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\AM4LH_ACTOR_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'M4LH_ACTOR_PREPARATION_FAILED';
        throw new RuntimeException($code);
    }
}

/** Real old service reset only; no HTTP reset or login is claimed. */
function m4lh_actors_reset_password(object $oldApp, PDO $db, string $runId, array $receipt,
    array $private, string $oldRoot = '/legacy-source'): array
{
    $source = m4lh_actor_source($oldRoot); m4lh_actor_private($private); m4lh_actor_identity($db, $runId);
    m4lh_actor_expect(($receipt['schema'] ?? null) === 1 && ($receipt['scope'] ?? '') === 'legacy_normal_actors_fixture_only'
        && ($receipt['run_id'] ?? '') === $runId && ($receipt['legacy_source_sha'] ?? '') === $source['source_sha']
        && is_int($receipt['uid'] ?? null) && $receipt['uid'] > 0 && get_class($oldApp) === 'think\\App'
        && is_int($receipt['fixture_time'] ?? null) && $receipt['fixture_time'] > 0
        && function_exists('app') && app() === $oldApp && $oldApp->getRootPath() === '/legacy-workspace/crmeb/'
        && $oldApp->initialized() === true
        && $oldApp->config->get('database.connections.mysql.hostname') === 'audit-mysql'
        && $oldApp->config->get('database.connections.mysql.database') === 'audit_auth'
        && $oldApp->config->get('database.connections.mysql.username') === 'audit_app'
        && $oldApp->db->connect()->getPdo() === $db, 'M4LH_ACTOR_REAL_OLD_DML_APP');
    $method = new ReflectionMethod('app\\services\\user\\LoginServices', 'reset');
    $path = '/legacy-workspace/crmeb/app/services/user/LoginServices.php';
    m4lh_actor_expect($method->getFileName() === $path && !is_link($path) && filesize($path) === 22710
        && hash_file('sha256', $path) === 'ac5ecb8f9405683bbc6c194ac20ffa2189668e26a5691424b78661a41580257f',
        'M4LH_ACTOR_TRUE_477_PASSWORD_RESET');
    $users = m4m_rows($db, 'SELECT * FROM eb_user WHERE is_del=0 AND (account=? OR phone=?)',
        [$private['user_account'], $private['user_account']]);
    m4lh_actor_expect(count($users) === 1 && $users[0]['uid'] === $receipt['uid']
        && $users[0]['pwd'] === '' && $users[0]['now_money'] === '0.00', 'M4LH_ACTOR_ONE_NEW_REAL_RESET_SUBJECT');
    $before = m4lh_actor_capture($db);
    $expectedSubject = m4lh_actor_expected_row($before['tables']['eb_user']['metadata'],
        ['uid' => $receipt['uid'], 'account' => $private['user_account'], 'nickname' => '旧历史普通顾客', 'user_type' => 'h5',
            'now_money' => '0.00', 'is_money_level' => 0, 'status' => 1, 'is_del' => 0, 'spread_uid' => 0,
            'add_time' => $receipt['fixture_time']]);
    m4lh_actor_expect($users === [$expectedSubject], 'M4LH_ACTOR_COMPLETE_ORIGINAL_NEW_USER_BEFORE_RESET');
    $expectedRows = $before['private_rows']['eb_user']; $seen = 0;
    foreach ($expectedRows as &$row) if ($row['uid'] === $receipt['uid']) { $row['pwd'] = md5($private['user_password']); ++$seen; }
    unset($row); m4lh_actor_expect($seen === 1, 'M4LH_ACTOR_EXACT_NEW_USER_RESET_ONLY');
    $expected = m4lh_actor_expected($before, ['eb_user' => $expectedRows], []);
    try {
        m4lh_actor_expect($oldApp->make('app\\services\\user\\LoginServices')->reset($private['user_account'], $private['user_password']) === true,
            'M4LH_ACTOR_REAL_PRODUCTION_PASSWORD_RESET');
        m4lh_actor_expect(m4lh_actor_capture($db) === $expected, 'M4LH_ACTOR_RESET_ONLY_PWD_ALL_OTHER_DATABASE_PRESERVED');
        m4lh_actor_expect(m4lh_actor_source($oldRoot) === $source, 'M4LH_ACTOR_RESET_SOURCE_AFTER');
        return ['schema' => 1, 'scope' => 'legacy_real_customer_password_reset_only', 'run_id' => $runId,
            'legacy_source_sha' => $source['source_sha'], 'uid' => $receipt['uid'], 'production_reset_executed' => true,
            'full_other_database_preserved' => true, 'HTTP_reset_executed' => false, 'HTTP_login_executed' => false,
            'redis_and_files_observation' => 'CALLER_REQUIRED', 'business_or_migration_pass' => false];
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\AM4LH_ACTOR_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'M4LH_ACTOR_REAL_RESET_FAILED';
        throw new RuntimeException($code);
    }
}
