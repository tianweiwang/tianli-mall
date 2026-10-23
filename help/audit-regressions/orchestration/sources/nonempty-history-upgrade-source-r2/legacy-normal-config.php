<?php
declare(strict_types=1);

/**
 * Finite synthetic configuration preparation for the complete 477 legacy source.
 * Definitions only: no bootstrap, autoloader, App, command, HTTP, cache, or business call.
 * Requires the existing pure m4m_expect/m4m_rows/m4m_quote/m4m_metadata definitions.
 * This receipt proves a configuration fixture delta only. Jobs, files, Redis, logs,
 * real actors, payment, fulfillment and migration still require their own observations.
 */

function m4lh_cfg_source(string $oldRoot): array
{
    m4m_expect($oldRoot === '/legacy-source' && realpath($oldRoot) === $oldRoot
        && is_dir($oldRoot) && !is_link($oldRoot), 'M4LH_CFG_FIXED_OLD_ROOT');
    $pins = [
        'crmeb/public/install/crmeb.sql' => [10237372, '7af188f42c622db523d8000c0cf50f95fa48df2fe41123969ca2dbb65ffb5eb2'],
        'crmeb/app/common.php' => [43222, 'a5379e999165f4fa1f1969523211111caf383d04d8c5a2ad21164a72f1ac4a7c'],
        'crmeb/crmeb/services/SystemConfigService.php' => [2252, 'e428242a758fb60b539a1319a1f309d1ce932483b000923da27f7856c4006d7b'],
        'crmeb/app/services/system/config/SystemConfigServices.php' => [62173, 'b9832b8869c17e4707de3f8e053e45268441b3c4d685f89bb4a1081ab55e01a2'],
        'crmeb/app/dao/system/config/SystemConfigDao.php' => [3042, '554206333f12af4cc4f2c5bc2f4a4383a919203a6cf023b00dc7b6b2d729d63e'],
        'crmeb/app/services/message/NoticeService.php' => [1541, '5fb097f1c302e687cca8bda8c07dba266f6de7affc9a98052ef4351586f60d9e'],
        'crmeb/app/services/message/SystemNotificationServices.php' => [20821, 'da2ff2529ce5b03d475c3514a641acc2816116f285512118154fcf9c050d90f1'],
        'crmeb/app/dao/system/SystemNotificationDao.php' => [2675, '9c205485e8a5dde0daa341d3b077334c17e6817ceb90304a4538f286859d87a4'],
        'crmeb/app/listener/notice/NoticeListener.php' => [26939, '5367c5529ebd0b98d2b872ec6c4c23c93e040ef392548cd7882d7842499cc3ba'],
        'crmeb/app/listener/notice/CustomNoticeListener.php' => [7145, '08a2bd6110cac289b6c7bb3c4e9365c30a1eb44253e057288bd7ccbee2a98862'],
        'crmeb/app/listener/CustomEventListener.php' => [1373, '574656356077a458403eadf4f7875ffbe6fbab31edb163b2dc062a54db57ea13'],
        'crmeb/crmeb/traits/QueueTrait.php' => [2879, 'd9aa02cccd2a9f4d9f3b1e7057dcb9497d673c317587473efd6b8648e49b8314'],
        'crmeb/app/jobs/OrderJob.php' => [14625, '7a43637080229c77555e1838d07de986a07dcdd2b2a95b0980c69eb21339f7b7'],
        'crmeb/app/services/user/UserLabelCateServices.php' => [4949, '6d3a3bd5a3c41c9960a2559211fbe9c03f2812a3c64ad08621c0cc66995971f3'],
        'crmeb/app/dao/other/CategoryDao.php' => [2043, '67abb00b436c6e4e14e9244b858946f43bcee88ca85bb8aac77c3c2b03f558c5'],
        'crmeb/app/adminapi/controller/v1/user/UserLabelCate.php' => [4735, '5c4b1e8f1e5a9e7675840d907941bf2b4490789f617500c50e33d31d013022a9'],
        'crmeb/app/services/user/UserLabelServices.php' => [6343, 'f96f0eecef95004ace2f57f859df067d0ec993b51f8a4ad22a30fd0d9128d66a'],
        'crmeb/app/dao/service/StoreServiceDao.php' => [3274, '9f5120752b864547bbca823002175b2a97bf4b0f771b796bbce280d611ed1037'],
        'crmeb/app/listener/out/OutPushListener.php' => [3262, 'cd821bed9124ff492be3684dc301a828b74ad2b3dd906a7f38434bab573bcd60'],
        'crmeb/app/services/system/SystemTicketServices.php' => [21053, '8103c3f27dacd19f70a3275b5ef5d6cea19defd2322a9c6ceb7b3b769f0a8f47'],
        'crmeb/app/services/order/StoreOrderSuccessServices.php' => [5443, '836b204a57e3c46ce546900672dd1a551c0afb976460547c99a643a43893846c'],
        'crmeb/app/services/order/StoreOrderRefundServices.php' => [66549, '7e645d5fa8d3ce46e6c6480e5132813bdef2a3ba97fae0ef18b61eb04a75509d'],
        'crmeb/app/services/order/StoreOrderDeliveryServices.php' => [39152, 'd81bc7c37af4ef5ee1c548399c2993eb9394e9653f5c14307413e704758ac0ed'],
        'crmeb/app/services/order/StoreOrderTakeServices.php' => [31053, '0b158e0dca5860ab74615f2a9cbabd28b5825fdccfcfe81fd5735e855c261df4'],
    ];
    $items = [];
    foreach ($pins as $relative => [$bytes, $sha]) {
        $path = $oldRoot . '/' . $relative;
        m4m_expect(is_file($path) && !is_link($path) && realpath($path) === $path
            && filesize($path) === $bytes && hash_file('sha256', $path) === $sha,
            'M4LH_CFG_EXACT_477_DEPENDENCY');
        $items[$relative] = ['bytes' => $bytes, 'sha256' => $sha];
    }
    return ['source_sha' => '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978', 'root' => $oldRoot, 'files' => $items];
}

function m4lh_cfg_values(string $runId, array $normalInputs): array
{
    m4m_expect(preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $runId) === 1
        && array_keys($normalInputs) === ['site_url'] && is_string($normalInputs['site_url']),
        'M4LH_CFG_REAL_RUN_AND_EXPLICIT_ORIGIN');
    $origin = $normalInputs['site_url'];
    m4m_expect(preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{3,4})\z~D', $origin, $port) === 1
        && (int)$port[1] >= 1024 && (int)$port[1] <= 65535, 'M4LH_CFG_OWNED_LOOPBACK_ORIGIN');
    return [
        'queue_open' => 0, 'station_open' => 1, 'store_free_postage' => '10000.00',
        'image_thumb_status' => 0, 'store_self_mention' => 0, 'integral_ratio' => '0.00',
        'order_cancel_time' => '0', 'order_activity_time' => '0', 'order_bargain_time' => '0',
        'order_seckill_time' => '0', 'order_pink_time' => '0', 'member_func_status' => 0,
        'member_card_status' => 0, 'member_price_status' => 0, 'brokerage_func_status' => 0,
        'brokerage_window_switch' => 0, 'division_status' => 0, 'order_give_integral' => 0,
        'order_shipping_open' => 0, 'system_delivery_time' => '7', 'balance_func_status' => 1, 'yue_pay_status' => 1,
        'invoice_func_status' => 0, 'special_invoice_status' => 0, 'pay_weixin_open' => 0,
        'ali_pay_status' => 0, 'offline_pay_status' => 2, 'site_url' => $origin, 'product_reply_examine' => 1,
    ];
}

function m4lh_cfg_marks(): array
{
    // Only this normal payment/manual-courier/receipt/refund path; no rejection scenario.
    return [
        'order_pay_success' => 1, 'admin_pay_success_code' => 2, 'order_postage_success' => 1,
        'order_take' => 1, 'send_admin_confirm_take_over' => 2, 'order_refund' => 1,
        'send_order_apply_refund' => 2,
    ];
}

/** Parse only the pinned installation's finite column declarations, never execute SQL source. */
function m4lh_cfg_columns(string $sql, string $table): array
{
    m4m_expect(in_array($table, ['eb_system_config', 'eb_system_notification', 'eb_category', 'eb_user_label'], true),
        'M4LH_CFG_ONLY_FOUR_INSERT_TABLES');
    $q = chr(96);
    $pattern = '/^CREATE TABLE IF NOT EXISTS ' . $q . preg_quote($table, '/') . $q . ' \(\R(.*?)^\) ENGINE=/ms';
    m4m_expect(preg_match($pattern, $sql, $match) === 1, 'M4LH_CFG_PINNED_TABLE_DDL');
    $columns = [];
    foreach (preg_split('/\R/', $match[1]) as $line) {
        if (preg_match('/^\s*' . $q . '([a-z_]+)' . $q . '\s+([a-z]+(?:\([0-9,]+\))?(?: UNSIGNED)?)(.*)$/i', $line, $part) !== 1) continue;
        $name = $part[1]; $tail = $part[3]; $auto = str_contains($tail, 'AUTO_INCREMENT');
        $nullable = !str_contains($tail, 'NOT NULL'); $default = null;
        if (preg_match("/\bDEFAULT '([^']*)'/", $tail, $literal) === 1) $default = $literal[1];
        elseif (str_contains($tail, 'DEFAULT NULL') || $nullable || $auto) $default = null;
        else m4m_expect(false, 'M4LH_CFG_REQUIRED_COLUMN_HAS_NO_EXPECTED_DEFAULT');
        $type = strtolower(preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/i', '$1', $part[2]));
        m4m_expect(!isset($columns[$name]), 'M4LH_CFG_UNIQUE_PINNED_COLUMN');
        $columns[$name] = ['type' => $type, 'nullable' => $nullable ? 'YES' : 'NO',
            'default' => $default, 'extra' => $auto ? 'auto_increment' : ''];
    }
    m4m_expect(isset($columns['id']) && $columns['id']['extra'] === 'auto_increment', 'M4LH_CFG_NATIVE_PRIMARY_KEY');
    return $columns;
}

function m4lh_cfg_text_row(array $row): array
{
    foreach ($row as &$value) {
        m4m_expect($value === null || is_string($value) || is_int($value), 'M4LH_CFG_NATIVE_SCALAR_ONLY');
        if ($value !== null) $value = (string)$value;
    }
    unset($value); return $row;
}

/** Full original-column multiset; NULL, duplicate rows and binary strings are preserved. */
function m4lh_cfg_projection(array $rows): array
{
    $serialized = [];
    foreach ($rows as $row) $serialized[] = serialize(m4lh_cfg_text_row($row));
    sort($serialized, SORT_STRING);
    $bytes = serialize($serialized);
    return ['rows' => count($serialized), 'serialized_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
}

function m4lh_cfg_capture(PDO $db): array
{
    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);
    $snapshot = ['names' => $names, 'modes' => m4m_rows($db,
        'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,'
        . '@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb'),
        'tables' => [], 'mutable_rows' => []];
    foreach ($names as $name) {
        m4m_expect(is_string($name) && preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D', $name) === 1,
            'M4LH_CFG_ISOLATED_TABLE_NAMESPACE');
        $meta = m4m_metadata($db, $name); $columns = array_keys($meta['columns']);
        $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($name));
        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);
        m4m_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'M4LH_CFG_FULL_NATIVE_DDL');
        $normalized = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $ddl[1], -1, $changes);
        m4m_expect($changes <= 1 && is_string($normalized), 'M4LH_CFG_ONLY_DDL_NATIVE_COUNTER_SLOT');
        $snapshot['tables'][$name] = ['metadata' => $meta, 'columns' => $columns,
            'ddl_without_counter_sha256' => hash('sha256', $normalized), 'projection' => m4lh_cfg_projection($rows)];
        if (in_array($name, ['eb_system_config', 'eb_system_notification', 'eb_category', 'eb_user_label'], true)) {
            $snapshot['mutable_rows'][$name] = array_map('m4lh_cfg_text_row', $rows);
        }
    }
    return $snapshot;
}

function m4lh_cfg_no_enabled_candidates(PDO $db, array $snapshot): void
{
    // Require the real selector columns; a missing table/column is not a disabled channel.
    $checks = [
        'eb_system_notification' => [
            ['is_system', 'is_sms', 'is_wechat', 'is_routine', 'is_ent_wechat', 'is_app'],
            'is_system=1 OR is_sms=1 OR is_wechat=1 OR is_routine=1 OR is_ent_wechat=1 OR is_app=1'],
        'eb_system_event' => [['is_del', 'is_open'], 'is_del=0 AND is_open=1'],
        'eb_system_ticket' => [['status'], 'status=1'],
        'eb_out_account' => [['is_del', 'status', 'push_open'], 'is_del=0 AND status=1 AND push_open=1'],
        'eb_store_service' => [['status', 'notify'], 'status=1 AND notify=1'],
        'eb_store_coupon_issue' => [['is_del', 'status'], 'is_del=0 AND status=1'],
        'eb_luck_lottery' => [['is_del', 'status'], 'is_del=0 AND status=1'],
    ];
    foreach ($checks as $table => [$needed, $where]) {
        m4m_expect(isset($snapshot['tables'][$table]), 'M4LH_CFG_REQUIRED_CHANNEL_TABLE');
        foreach ($needed as $name) m4m_expect(isset($snapshot['tables'][$table]['metadata']['columns'][$name]),
            'M4LH_CFG_REQUIRED_CHANNEL_COLUMN');
        $count = m4m_rows($db, 'SELECT COUNT(*) AS n FROM ' . m4m_quote($table) . ' WHERE ' . $where);
        m4m_expect(count($count) === 1 && (string)$count[0]['n'] === '0', 'M4LH_CFG_NO_ENABLED_CANDIDATE');
    }
    m4m_expect(isset($snapshot['tables']['eb_user_label_relation']['metadata']['columns']['uid'],
        $snapshot['tables']['eb_user_label_relation']['metadata']['columns']['label_id']),
        'M4LH_CFG_REAL_PAYMENT_LABEL_RELATION_SCHEMA');
}

function m4lh_cfg_plan_table(array $snapshot, string $table, array $sourceColumns, array $inputs): array
{
    m4m_expect(isset($snapshot['tables'][$table], $snapshot['mutable_rows'][$table])
        && $snapshot['tables'][$table]['columns'] === array_keys($sourceColumns), 'M4LH_CFG_EXACT_INSERT_SCHEMA');
    $meta = $snapshot['tables'][$table]['metadata']; $defaults = [];
    foreach ($sourceColumns as $name => $declared) {
        $column = $meta['columns'][$name];
        $type = strtolower(preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/i', '$1', $column['COLUMN_TYPE']));
        m4m_expect($type === $declared['type'] && $column['IS_NULLABLE'] === $declared['nullable']
            && ($column['COLUMN_DEFAULT'] === null ? null : (string)$column['COLUMN_DEFAULT']) === $declared['default']
            && $column['EXTRA'] === $declared['extra'] && $column['GENERATION_EXPRESSION'] === '',
            'M4LH_CFG_PINNED_DEFAULT_AND_NATIVE_COLUMN');
        $defaults[$name] = $declared['default'];
    }
    $next = $meta['table']['AUTO_INCREMENT'];
    m4m_expect((is_int($next) || is_string($next)) && preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$next) === 1
        && (int)$next + count($inputs) <= 2147483647, 'M4LH_CFG_BEFORE_NATIVE_ALLOCATION');
    $rows = []; $index = 0;
    foreach ($inputs as $input) {
        m4m_expect(!array_key_exists('id', $input) && array_diff(array_keys($input), array_keys($sourceColumns)) === [],
            'M4LH_CFG_INSERT_ONLY_FIXED_FIELDS');
        $expected = $defaults; $expected['id'] = (string)((int)$next + $index);
        foreach ($input as $name => $value) {
            m4m_expect(is_int($value) || is_string($value), 'M4LH_CFG_EXPLICIT_NORMAL_INPUT');
            $expected[$name] = (string)$value;
        }
        $rows[] = ['input' => $input, 'expected' => $expected]; ++$index;
    }
    return ['before_next' => (int)$next, 'after_next' => (int)$next + count($inputs), 'rows' => $rows];
}

function m4lh_cfg_native_insert(PDO $db, string $table, array $planned): int
{
    m4m_expect(in_array($table, ['eb_system_config', 'eb_system_notification', 'eb_category', 'eb_user_label'], true)
        && $db->inTransaction(), 'M4LH_CFG_FINITE_TRANSACTIONAL_INSERT');
    $input = $planned['input']; $columns = array_keys($input);
    m4m_expect(count($input) > 0 && !in_array('id', $columns, true), 'M4LH_CFG_NO_ASSIGNED_PRIMARY_KEY');
    $statement = $db->prepare('INSERT INTO ' . m4m_quote($table) . ' (' . implode(',', array_map('m4m_quote', $columns))
        . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach (array_values($input) as $index => $value) $statement->bindValue($index + 1, (string)$value, PDO::PARAM_STR);
    m4m_expect($statement->execute() && $statement->rowCount() === 1, 'M4LH_CFG_ONE_REAL_INSERT');
    $id = $db->lastInsertId();
    m4m_expect(is_string($id) && $id === $planned['expected']['id'], 'M4LH_CFG_ACTUAL_NATIVE_ID_NOT_AFTER_ORACLE');
    return (int)$id;
}

/** Expected rows/counters come exclusively from BEFORE plus the fully built insertion plan. */
function m4lh_cfg_expected(array $before, array $plans): array
{
    $expected = $before;
    foreach ($plans as $table => $plan) {
        $expectedRows = $before['mutable_rows'][$table];
        foreach ($plan['rows'] as $row) $expectedRows[] = $row['expected'];
        $expected['mutable_rows'][$table] = $expectedRows;
        $expected['tables'][$table]['projection'] = m4lh_cfg_projection($expectedRows);
        $was = $before['tables'][$table]['metadata']['table']['AUTO_INCREMENT'];
        $expected['tables'][$table]['metadata']['table']['AUTO_INCREMENT'] =
            is_int($was) ? $plan['after_next'] : (string)$plan['after_next'];
    }
    return $expected;
}

function m4lh_cfg_compare(array $expected, array $actual): void
{
    m4m_expect($actual['names'] === $expected['names'] && $actual['modes'] === $expected['modes'],
        'M4LH_CFG_ALL_ORIGINAL_TABLES_AND_SQL_MODES');
    m4m_expect($actual['tables'] === $expected['tables'], 'M4LH_CFG_COMPLETE_INDEPENDENT_ROW_METADATA_DDL_DELTA');
    foreach ($expected['mutable_rows'] as $table => $rows) {
        m4m_expect(m4lh_cfg_projection($actual['mutable_rows'][$table]) === m4lh_cfg_projection($rows),
            'M4LH_CFG_COMPLETE_MUTABLE_ROW_MULTISET');
    }
}

function m4lh_config_prepare(PDO $db, string $runId, string $oldRoot, array $normalInputs): array
{
    foreach (['m4m_expect', 'm4m_rows', 'm4m_quote', 'm4m_metadata'] as $function) {
        if (!function_exists($function)) throw new RuntimeException('M4LH_CFG_PURE_OBSERVER_DEFINITIONS_REQUIRED');
    }
    $values = m4lh_cfg_values($runId, $normalInputs); $source = m4lh_cfg_source($oldRoot);
    m4m_expect($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && !$db->inTransaction(),
        'M4LH_CFG_OWN_APPLICATION_PDO');
    $native = m4m_rows($db, 'SELECT DATABASE() AS db,CURRENT_USER() AS actor,'
        . '@@SESSION.auto_increment_increment AS increment_value,@@SESSION.auto_increment_offset AS offset_value,'
        . '@@SESSION.information_schema_stats_expiry AS stats_expiry');
    m4m_expect(count($native) === 1 && $native[0]['db'] === 'audit_auth'
        && preg_match('/\Aaudit_app@/', (string)$native[0]['actor']) === 1
        && (string)$native[0]['increment_value'] === '1' && (string)$native[0]['offset_value'] === '1'
        && (string)$native[0]['stats_expiry'] === '0', 'M4LH_CFG_RESTRICTED_ACTOR_AND_FRESH_NATIVE_COUNTERS');
    $sentinel = m4m_rows($db, 'SELECT run_id FROM audit_sentinel');
    m4m_expect(count($sentinel) === 1 && $sentinel[0]['run_id'] === $runId, 'M4LH_CFG_ACTUAL_RUN_SENTINEL');
    $sql = file_get_contents($oldRoot . '/crmeb/public/install/crmeb.sql');
    m4m_expect(is_string($sql), 'M4LH_CFG_ACTUAL_PINNED_INSTALL_SOURCE');
    $marks = m4lh_cfg_marks();
    foreach ($marks as $mark => $type) m4m_expect(preg_match("/^\([0-9]+, '" . preg_quote($mark, '/') . "',/m", $sql) === 1,
        'M4LH_CFG_REAL_INSTALL_NOTIFICATION_MARK');
    m4m_expect($db->beginTransaction(), 'M4LH_CFG_BEGIN');
    try {
        $before = m4lh_cfg_capture($db); m4lh_cfg_no_enabled_candidates($db, $before);
        m4m_expect(isset($before['tables']['eb_store_product']['metadata']['columns']['label_id']),
            'M4LH_CFG_REAL_PRODUCT_PURCHASE_LABEL_COLUMN');
        foreach (['eb_store_order', 'eb_store_order_cart_info', 'eb_store_order_refund', 'eb_store_product_reply'] as $table) {
            m4m_expect(isset($before['tables'][$table]), 'M4LH_CFG_EXISTING_LEGACY_BUSINESS_TABLE');
        }
        m4m_expect(!isset($before['tables']['eb_store_order']['metadata']['columns']['marketplace_trade_version']),
            'M4LH_CFG_ACTUAL_PRE_MS4_STAGE');
        $configInputs = [];
        foreach ($values as $key => $value) {
            foreach ($before['mutable_rows']['eb_system_config'] ?? [] as $row) m4m_expect($row['menu_name'] !== $key,
                'M4LH_CFG_CREATE_ONLY_CONFIG_KEY');
            $configInputs[] = ['menu_name' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'type' => 'text', 'info' => 'normal-' . $runId, 'desc' => 'synthetic-legacy-normal-config'];
        }
        $notificationInputs = [];
        foreach ($marks as $mark => $type) {
            foreach ($before['mutable_rows']['eb_system_notification'] ?? [] as $row) m4m_expect($row['mark'] !== $mark,
                'M4LH_CFG_CREATE_ONLY_NOTIFICATION_MARK');
            $notificationInputs[] = ['mark' => $mark, 'name' => 'normal-' . $runId, 'title' => 'synthetic normal ' . $runId,
                'is_system' => 0, 'is_sms' => 0, 'is_wechat' => 0, 'is_routine' => 0, 'is_ent_wechat' => 0,
                'is_app' => 0, 'type' => $type];
        }
        $categoryName = 'normal-label-category-' . $runId; $labelName = 'normal-purchase-label-' . $runId;
        foreach ($before['mutable_rows']['eb_category'] ?? [] as $row) m4m_expect($row['name'] !== $categoryName,
            'M4LH_CFG_CREATE_ONLY_NORMAL_CATEGORY');
        foreach ($before['mutable_rows']['eb_user_label'] ?? [] as $row) m4m_expect($row['label_name'] !== $labelName,
            'M4LH_CFG_CREATE_ONLY_NORMAL_LABEL');
        $plans = [];
        $plans['eb_system_config'] = m4lh_cfg_plan_table($before, 'eb_system_config',
            m4lh_cfg_columns($sql, 'eb_system_config'), $configInputs);
        $plans['eb_system_notification'] = m4lh_cfg_plan_table($before, 'eb_system_notification',
            m4lh_cfg_columns($sql, 'eb_system_notification'), $notificationInputs);
        $plans['eb_category'] = m4lh_cfg_plan_table($before, 'eb_category', m4lh_cfg_columns($sql, 'eb_category'),
            [['name' => $categoryName, 'type' => 0, 'owner_id' => 0, 'pid' => 0, 'sort' => 0]]);
        $categoryId = $plans['eb_category']['before_next'];
        $plans['eb_user_label'] = m4lh_cfg_plan_table($before, 'eb_user_label', m4lh_cfg_columns($sql, 'eb_user_label'),
            [['label_cate' => $categoryId, 'label_name' => $labelName]]);
        $labelId = $plans['eb_user_label']['before_next']; $expected = m4lh_cfg_expected($before, $plans);
        foreach ($plans as $table => $plan) foreach ($plan['rows'] as $row) m4lh_cfg_native_insert($db, $table, $row);
        $after = m4lh_cfg_capture($db); m4lh_cfg_compare($expected, $after); m4lh_cfg_no_enabled_candidates($db, $after);
        m4m_expect($db->commit(), 'M4LH_CFG_COMMIT');
        return ['schema' => 1, 'scope' => 'legacy_normal_config_fixture_only', 'run_id' => $runId, 'source' => $source,
            'normal_inputs' => $normalInputs, 'getter_values' => $values, 'notification_marks' => array_keys($marks),
            'category_id' => $categoryId, 'purchase_label_id' => $labelId,
            'native_insert_counts' => array_map(static fn(array $plan): int => count($plan['rows']), $plans),
            'native_counters' => array_map(static fn(array $plan): array =>
                ['before' => $plan['before_next'], 'after' => $plan['after_next']], $plans),
            'before_tables' => $before['tables'], 'expected_tables' => $expected['tables'],
            'observed_tables' => $after['tables'], 'sql_modes' => $before['modes'],
            'getter_check' => 'REQUIRED_AFTER_UNIQUE_OLD_APP_INITIALIZATION',
            'remaining_observations' => ['real_actors', 'business_jobs_and_captured_errors', 'files_and_logs',
                'redis_values_types_and_absolute_expiry', 'native_child_terminal_and_retirement'],
            'business_or_migration_pass' => false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\AM4LH_CFG_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'M4LH_CFG_PREPARATION_FAILED';
        throw new RuntimeException($code);
    }
}

/**
 * Only after the caller has independently initialized the unique old App.
 * Uses both the actual uncached old getter and sys_config (the business/cache path).
 * It does not load Composer/App and does not erase/refresh cache to make a check pass.
 * The caller must observe the real configuration-cache writes/absolute expiries.
 */
function m4lh_config_verify_getters(PDO $db, string $runId, string $oldRoot, array $receipt): array
{
    $source = m4lh_cfg_source($oldRoot);
    m4m_expect(($receipt['schema'] ?? null) === 1 && ($receipt['scope'] ?? null) === 'legacy_normal_config_fixture_only'
        && ($receipt['run_id'] ?? null) === $runId && ($receipt['source'] ?? null) === $source
        && is_array($receipt['normal_inputs'] ?? null), 'M4LH_CFG_SAME_PREPARATION_RECEIPT');
    $values = m4lh_cfg_values($runId, $receipt['normal_inputs']);
    m4m_expect(($receipt['getter_values'] ?? null) === $values && function_exists('sys_config') && function_exists('app'),
        'M4LH_CFG_ALREADY_INITIALIZED_REAL_OLD_GETTER');
    $reflection = new ReflectionFunction('sys_config'); $common = '/legacy-workspace/crmeb/app/common.php';
    m4m_expect($reflection->getFileName() === $common && !is_link($common)
        && hash_file('sha256', $common) === $source['files']['crmeb/app/common.php']['sha256'],
        'M4LH_CFG_REAL_OLD_COMMON_FUNCTION');
    m4m_expect(!$db->inTransaction() && $db->beginTransaction(), 'M4LH_CFG_GETTER_OBSERVATION_BEGIN');
    try {
        $before = m4lh_cfg_capture($db);
        foreach ($values as $key => $expected) {
            // The old business facade normalizes finite scalar JSON values to strings.
            $businessExpected = trim((string)$expected);
            $actual = sys_config($key, ['missing_config' => $runId]);
            m4m_expect($businessExpected !== '' && $actual === $businessExpected,
                'M4LH_CFG_REAL_BUSINESS_GETTER_VALUE_AND_TYPE');
        }
        $class = 'crmeb\\services\\SystemConfigService';
        m4m_expect(class_exists($class, false), 'M4LH_CFG_OLD_CONFIG_CLASS_ALREADY_RESOLVED');
        $method = new ReflectionMethod($class, 'get');
        $file = '/legacy-workspace/crmeb/crmeb/services/SystemConfigService.php';
        m4m_expect($method->getFileName() === $file && !is_link($file)
            && hash_file('sha256', $file) === $source['files']['crmeb/crmeb/services/SystemConfigService.php']['sha256'],
            'M4LH_CFG_REAL_OLD_UNCACHED_GETTER');
        foreach ($values as $key => $expected) m4m_expect($class::get($key, ['missing_config' => $runId], false) === $expected,
            'M4LH_CFG_REAL_UNCACHED_VALUE_AND_TYPE');
        $after = m4lh_cfg_capture($db); m4lh_cfg_compare($before, $after);
        m4lh_cfg_no_enabled_candidates($db, $after); m4m_expect($db->commit(), 'M4LH_CFG_GETTER_OBSERVATION_COMMIT');
        return ['scope' => 'legacy_normal_real_configuration_getters_only', 'run_id' => $runId,
            'keys' => array_keys($values), 'getter_count' => count($values) * 2,
            'database_rows_metadata_ddl_unchanged' => true, 'cache_effects_require_separate_observation' => true,
            'business_or_migration_pass' => false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\AM4LH_CFG_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'M4LH_CFG_GETTER_CHECK_FAILED';
        throw new RuntimeException($code);
    }
}
