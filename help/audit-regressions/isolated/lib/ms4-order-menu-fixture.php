<?php
declare(strict_types=1);

/** Four original installation menu anchors; no installer SQL or App bootstrap is executed. */
const M4I_ORDER_MENU_SOURCE_META = <<<'M4I_ORDER_MENU_SOURCE_JSON'
{
  "columns": [
    "id",
    "pid",
    "icon",
    "menu_name",
    "module",
    "controller",
    "action",
    "api_url",
    "methods",
    "params",
    "sort",
    "is_show",
    "is_show_path",
    "access",
    "menu_path",
    "path",
    "auth_type",
    "header",
    "is_header",
    "unique_auth",
    "is_del",
    "mark"
  ],
  "rows": {
    "order_root": [
      4,
      0,
      "s-order",
      "订单",
      "admin",
      "order",
      "index",
      "",
      "",
      "[]",
      120,
      1,
      1,
      1,
      "/order",
      "",
      1,
      "home",
      1,
      "admin-order",
      0,
      "订单"
    ],
    "order_page": [
      5,
      4,
      "",
      "订单管理",
      "admin",
      "order.store_order",
      "index",
      "",
      "",
      "[]",
      10,
      1,
      1,
      1,
      "/order/list",
      "4",
      1,
      "order",
      0,
      "admin-order-storeOrder-index",
      0,
      "订单管理"
    ],
    "order_info_group": [
      2562,
      5,
      "",
      "订单详情",
      "admin",
      "",
      "",
      "",
      "",
      "[]",
      0,
      1,
      1,
      1,
      "/",
      "4/5",
      3,
      "",
      0,
      "order-info",
      0,
      "订单详情"
    ],
    "order_info": [
      2585,
      2562,
      "",
      "订单详情",
      "",
      "",
      "",
      "order/info/<id>",
      "GET",
      "[]",
      1,
      1,
      1,
      1,
      "",
      "",
      2,
      "",
      0,
      "order-info-6465a369a2c3b",
      0,
      "订单详情"
    ]
  }
}
M4I_ORDER_MENU_SOURCE_JSON;

/** Same bounded SQL-literal state machine as preview_storefront_builtin; expressions are rejected. */
function m4iom_literal(string $sql, int &$position): mixed
{
    while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
    m2f_assert(isset($sql[$position]), 'ORDER_MENU_SQL_LITERAL_PRESENT');
    if ($sql[$position] === "'") {
        ++$position; $value = ''; $closed = false;
        while (isset($sql[$position])) {
            $character = $sql[$position++];
            if ($character === '\\') {
                m2f_assert(isset($sql[$position]), 'ORDER_MENU_SQL_STRING_ESCAPE_COMPLETE');
                $escaped = $sql[$position++];
                $value .= match ($escaped) {
                    '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                    default => $escaped,
                };
            } elseif ($character === "'") {
                if (($sql[$position] ?? null) === "'") { $value .= "'"; ++$position; }
                else { $closed = true; break; }
            } else { $value .= $character; }
        }
        m2f_assert($closed, 'ORDER_MENU_SQL_STRING_CLOSED');
        return $value;
    }
    $start = $position;
    while (isset($sql[$position]) && $sql[$position] !== ',' && $sql[$position] !== ')') ++$position;
    $literal = trim(substr($sql, $start, $position - $start));
    if (strcasecmp($literal, 'NULL') === 0) return null;
    m2f_assert(preg_match('/\A(?:0|[1-9][0-9]*|-[1-9][0-9]*)\z/D', $literal) === 1
        && (string)(int)$literal === $literal, 'ORDER_MENU_SQL_CANONICAL_INTEGER_LITERAL');
    return (int)$literal;
}

function m4iom_source_meta(string $sql): array
{
    $meta = json_decode(M4I_ORDER_MENU_SOURCE_META, true, 16, JSON_THROW_ON_ERROR);
    m2f_assert(array_keys($meta) === ['columns', 'rows'] && count($meta['columns']) === 22
        && count(array_unique($meta['columns'])) === 22
        && array_keys($meta['rows']) === ['order_root', 'order_page', 'order_info_group', 'order_info'],
        'ORDER_MENU_FIXED_DECLARATIVE_META');
    $matched = preg_match_all('/^INSERT INTO `eb_system_menus` \(([^\r\n]+)\) VALUES\r?\n/m', $sql, $headers, PREG_OFFSET_CAPTURE);
    m2f_assert($matched === 1 && strlen($sql) <= 16777216, 'ORDER_MENU_ONE_ORIGINAL_SQL_STATEMENT');
    $header = $headers[1][0][0];
    m2f_assert(preg_match('/\A`[a-z_]+`(?:,\s*`[a-z_]+`)*\z/D', $header) === 1,
        'ORDER_MENU_SQL_ONLY_DECLARED_COLUMN_IDENTIFIERS');
    preg_match_all('/`([a-z_]+)`/', $header, $matches); $columns = $matches[1];
    m2f_assert($columns === $meta['columns'], 'ORDER_MENU_ORIGINAL_EXACT_22_COLUMNS');
    $selected = []; $names = [];
    foreach ($meta['rows'] as $name => $cells) {
        m2f_assert(array_is_list($cells) && count($cells) === 22, 'ORDER_MENU_COMPLETE_SOURCE_ROW');
        $names[$cells[array_search('unique_auth', $columns, true)]] = $name;
    }
    m2f_assert(count($names) === 4, 'ORDER_MENU_FOUR_UNIQUE_SOURCE_IDENTITIES');
    $position = $headers[0][0][1] + strlen($headers[0][0][0]); $tupleCount = 0;
    while (true) {
        while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
        m2f_assert(($sql[$position] ?? null) === '(', 'ORDER_MENU_SQL_TUPLE_OPEN'); ++$position;
        $cells = [];
        for ($index = 0; $index < 22; ++$index) {
            $cells[] = m4iom_literal($sql, $position);
            while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
            m2f_assert(($sql[$position] ?? null) === ($index === 21 ? ')' : ','), 'ORDER_MENU_SQL_EXACT_TUPLE_SEPARATOR');
            ++$position;
        }
        ++$tupleCount; $unique = $cells[array_search('unique_auth', $columns, true)];
        if (is_string($unique) && isset($names[$unique])) {
            $name = $names[$unique]; m2f_assert(!isset($selected[$name]), 'ORDER_MENU_SOURCE_IDENTITY_NOT_DUPLICATED');
            $selected[$name] = $cells;
        }
        while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
        $delimiter = $sql[$position] ?? null;
        m2f_assert($delimiter === ',' || $delimiter === ';', 'ORDER_MENU_SQL_STATEMENT_CLOSED'); ++$position;
        if ($delimiter === ';') break;
    }
    $ordered = [];
    foreach ($meta['rows'] as $name => $cells) {
        m2f_assert(isset($selected[$name]) && $selected[$name] === $cells, 'ORDER_MENU_RUNTIME_SQL_EQUALS_FULL_FIXED_SOURCE_META');
        $ordered[$name] = $selected[$name];
    }
    m2f_assert(count($ordered) === 4 && $tupleCount >= 4, 'ORDER_MENU_COMPLETE_FOUR_ORIGINAL_ANCHORS');
    return ['columns' => $columns, 'rows' => $ordered];
}

function m4iom_row(PDO $db, array $columns, int $id, array $expected): array
{
    $statement = $db->prepare('SELECT * FROM eb_system_menus WHERE id = ?');
    m2f_assert($statement->execute([$id]), 'ORDER_MENU_NATIVE_ROW_READ'); $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    m2f_assert(count($rows) === 1 && array_keys($rows[0]) === $columns && array_keys($expected) === $columns,
        'ORDER_MENU_NATIVE_COMPLETE_22_FIELDS');
    foreach ($columns as $column) {
        $actual = $rows[0][$column]; $wanted = $expected[$column];
        m2f_assert($wanted === null ? $actual === null : ($actual !== null && (string)$actual === (string)$wanted),
            'ORDER_MENU_ONLY_DECLARED_NATIVE_ID_PARENT_PATH_MAPPING');
    }
    return $rows[0];
}

/** Called only by the source-bound initializer after its original nine children, before early sealing. */
function m4iom_prepare(array $binding, array $sourceFiles, bool $withReadLeaves): array
{
    audit_guard_assert();
    m2f_assert(empty($GLOBALS['m4c_app_started']) && !isset($GLOBALS['m4c_application']), 'ORDER_MENU_BEFORE_FIRST_APP_ONLY');
    $paths = ['help/audit-regressions/isolated/lib/ms4-order-menu-fixture.php',
        'help/preview/storefront.php', 'crmeb/public/install/crmeb.sql',
        'help/migrations/marketplace-ms4-order-read-menus.php', 'help/migrations/marketplace-ms1.php',
        'help/audit-regressions/orchestration/order_menu_receipt.py'];
    $actualPaths = array_column($sourceFiles, 'path'); $sortedPaths = $paths; $sortedActual = $actualPaths;
    sort($sortedPaths, SORT_STRING); sort($sortedActual, SORT_STRING);
    m2f_assert(array_is_list($sourceFiles) && count($sourceFiles) === 6 && $sortedPaths === $sortedActual,
        'ORDER_MENU_EXACT_SIX_SOURCE_INPUTS');
    $byPath = [];
    foreach ($sourceFiles as $row) {
        m2f_assert(array_keys($row) === ['path', 'bytes', 'sha256'] && is_int($row['bytes']) && $row['bytes'] > 0
            && is_string($row['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) === 1,
            'ORDER_MENU_COMPLETE_FIXED_SOURCE_DESCRIPTOR');
        $byPath[$row['path']] = $row;
    }
    $sql = file_get_contents('/workspace/crmeb/public/install/crmeb.sql'); $sqlRow = $byPath['crmeb/public/install/crmeb.sql'];
    m2f_assert(is_string($sql) && strlen($sql) === $sqlRow['bytes'] && hash('sha256', $sql) === $sqlRow['sha256'],
        'ORDER_MENU_ACTUAL_BOUND_INSTALL_SOURCE_BYTES');
    $meta = m4iom_source_meta($sql); $columns = $meta['columns'];
    $privateBefore = ['migrator' => m2f_file('/run/audit-bootstrap/migrator.json'), 'app' => m2f_file('/run/audit-app/config.json')];
    $migrator = m4i_read('/run/audit-bootstrap/migrator.json'); $config = audit_config();
    m2f_assert(($migrator['username'] ?? null) === 'audit_migrator' && ($config['database']['username'] ?? null) === 'audit_app',
        'ORDER_MENU_ORIGINAL_MAINTENANCE_AND_DML_ACCOUNTS');
    $maintenance = new PDO('mysql:host=audit-mysql;port=3306;dbname=audit_auth;charset=utf8mb4',
        $migrator['username'], $migrator['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 3]);
    unset($migrator); $db = audit_db();
    // Match the existing migration before any native anchor row or snapshot is read.
    m2f_assert($db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
        && $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC)
        && $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false), 'ORDER_MENU_ORIGINAL_NATIVE_PDO_CONFIGURATION');
    m2f_assert(!$db->inTransaction() && !$maintenance->inTransaction()
        && $db->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_app@%'
        && $maintenance->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_migrator@%'
        && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
        && $maintenance->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth', 'ORDER_MENU_REAL_EXISTING_DEDICATED_IDENTITIES');
    $before = m4i_exit_snapshot($db, $maintenance);
    $menusBefore = $db->query('SELECT * FROM eb_system_menus ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $absence = $db->prepare('SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth = ?');
    foreach ($meta['rows'] as $cells) {
        $unique = $cells[array_search('unique_auth', $columns, true)];
        m2f_assert($absence->execute([$unique]) && (int)$absence->fetchColumn() === 0, 'ORDER_MENU_FRESH_FOUR_ANCHORS_ABSENT');
    }
    foreach (['order-list-read', 'order-chart-read'] as $permission) {
        m2f_assert($absence->execute([$permission]) && (int)$absence->fetchColumn() === 0, 'ORDER_MENU_TWO_READ_LEAF_IDENTITIES_ABSENT');
    }
    $route = $db->prepare("SELECT COUNT(*) FROM eb_system_menus WHERE auth_type = 2 AND LOWER(REPLACE(TRIM(api_url), ' ', '')) = ? AND UPPER(TRIM(methods)) = 'GET'");
    foreach (['order/info/<id>', 'order/list', 'order/chart'] as $api) {
        m2f_assert($route->execute([$api]) && (int)$route->fetchColumn() === 0, 'ORDER_MENU_FRESH_NATIVE_READ_ROUTES_ABSENT');
    }
    $ids = []; $sourceIds = []; $expected = []; $anchors = [];
    $insertColumns = array_values(array_filter($columns, static fn(string $name): bool => $name !== 'id'));
    m2f_assert($columns[0] === 'id' && count($insertColumns) === 21, 'ORDER_MENU_INSERT_OMITS_ONLY_NATIVE_ID');
    $insert = $db->prepare('INSERT INTO eb_system_menus (`' . implode('`,`', $insertColumns)
        . '`) VALUES (' . implode(',', array_fill(0, count($insertColumns), '?')) . ')');
    m2f_assert($db->beginTransaction(), 'ORDER_MENU_FOUR_ANCHOR_TRANSACTION_STARTED');
    try {
        foreach ($meta['rows'] as $name => $cells) {
            $row = array_combine($columns, $cells); $sourceIds[$name] = $row['id']; unset($row['id']);
            if ($name === 'order_page') { $row['pid'] = $ids['order_root']; $row['path'] = (string)$ids['order_root']; }
            elseif ($name === 'order_info_group') { $row['pid'] = $ids['order_page']; $row['path'] = $ids['order_root'] . '/' . $ids['order_page']; }
            elseif ($name === 'order_info') $row['pid'] = $ids['order_info_group'];
            m2f_assert(array_keys($row) === $insertColumns && $insert->execute(array_values($row)) && $insert->rowCount() === 1,
                'ORDER_MENU_EXACT_ONE_SOURCE_ANCHOR_INSERT');
            $native = $db->lastInsertId();
            m2f_assert(is_string($native) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $native) === 1 && (int)$native <= 65535,
                'ORDER_MENU_DATABASE_GENERATED_SMALLINT_ID');
            $ids[$name] = (int)$native; $expected[$name] = ['id' => $ids[$name]] + $row;
            $anchors[$name] = m4iom_row($db, $columns, $ids[$name], $expected[$name]);
        }
        m2f_assert(count(array_unique($ids)) === 4 && $db->commit() && !$db->inTransaction(), 'ORDER_MENU_FOUR_REAL_NATIVE_ANCHORS_COMMITTED');
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    require_once '/workspace/help/migrations/marketplace-ms4-order-read-menus.php';
    $proof = [];
    foreach (['order_root', 'order_page', 'order_info', 'order_info_group'] as $name) {
        $proof[$name] = ['id' => $ids[$name], 'sha256' => hash('sha256', json_encode($anchors[$name],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }
    $fresh = ['mode' => 'check', 'state' => 'applicable', 'changed' => false, 'tables' => [], 'menu_ids' => [],
        'issues' => [], 'anchors' => $proof, 'source_binding' => [
            'entry_sha256' => $byPath['help/migrations/marketplace-ms4-order-read-menus.php']['sha256'],
            'ms1_sha256' => $byPath['help/migrations/marketplace-ms1.php']['sha256']],
        'authorization_cache_producer' => 'fresh_scope_for_request_exact_all_auth_and_current_roles', 'roles_modified' => false];
    $migration = new MarketplaceMs4OrderReadMenus($db, 'eb_'); $precheck = $migration->check();
    m2f_assert($precheck === $fresh, 'ORDER_MENU_REAL_FRESH_TWO_READ_LEAVES_CHECK');
    $leafReceipts = ['precheck' => $precheck]; $leafIds = [];
    if ($withReadLeaves) {
        $apply = $migration->apply(); $leafIds = $apply['menu_ids'] ?? [];
        m2f_assert(is_array($leafIds) && array_keys($leafIds) === ['order-list-read', 'order-chart-read']
            && count(array_unique($leafIds)) === 2, 'ORDER_MENU_TWO_NATIVE_READ_LEAF_IDS');
        foreach ($leafIds as $id) m2f_assert(is_int($id) && $id > 0 && $id <= 65535 && !in_array($id, $ids, true),
            'ORDER_MENU_NEW_DISJOINT_NATIVE_READ_LEAF');
        $completed = $fresh; $completed['state'] = 'completed'; $completed['menu_ids'] = $leafIds;
        $expectedApply = $completed; $expectedApply['mode'] = 'apply'; $expectedApply['changed'] = true;
        $expectedApply['created_menu_ids'] = $leafIds;
        $postcheck = $migration->check(); $reapply = $migration->apply();
        $expectedReapply = $completed; $expectedReapply['mode'] = 'apply'; $expectedReapply['created_menu_ids'] = [];
        m2f_assert($apply === $expectedApply && $postcheck === $completed && $reapply === $expectedReapply
            && !$db->inTransaction(), 'ORDER_MENU_A_ORIGINAL_APPLY_CHECK_REAPPLY_TWO_LEAVES_ONLY');
        $leafReceipts += ['apply' => $apply, 'postcheck' => $postcheck, 'reapply' => $reapply];
    }
    foreach ($ids as $name => $id) m2f_assert(m4iom_row($db, $columns, $id, $expected[$name]) === $anchors[$name],
        'ORDER_MENU_ALL_FOUR_FULL_NATIVE_ANCHORS_REMAIN_EXACT');
    $menusAfter = $db->query('SELECT * FROM eb_system_menus ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $newIds = array_merge(array_values($ids), array_values($leafIds)); $retained = [];
    foreach ($menusAfter as $row) if (!in_array((int)$row['id'], $newIds, true)) $retained[] = $row;
    $after = m4i_exit_snapshot($db, $maintenance);
    $privateAfter = ['migrator' => m2f_file('/run/audit-bootstrap/migrator.json'), 'app' => m2f_file('/run/audit-app/config.json')];
    m2f_assert($before === $after && $retained === $menusBefore && count($newIds) === ($withReadLeaves ? 6 : 4)
        && count($menusAfter) === count($menusBefore) + count($newIds) && $privateAfter === $privateBefore,
        'ORDER_MENU_ONLY_DECLARED_NEW_ROWS_ALL_OLD_ROWS_ROLES_AND_INPUTS_RETAINED');
    audit_guard_assert();
    return ['schema' => 1, 'scope' => 'MS4_NATIVE_ORDER_MENU_FIXTURE_BEFORE_EARLY_ONLY',
        'status' => 'VERIFIED_MS4_NATIVE_ORDER_MENU_FIXTURE_BEFORE_EARLY_ONLY', 'profile_mode' => $withReadLeaves ? 'A' : 'B',
        'binding' => ['run_id' => getenv('AUDIT_RUN_ID')] + array_intersect_key($binding, array_flip([
            'source_sha', 'container_id', 'entry_namespace_pid', 'created_sha256', 'freeze_sha256'])),
        'source_files' => $sourceFiles, 'source_sql_sha256' => hash('sha256', $sql),
        'source_meta_sha256' => hash('sha256', m2f_json($meta)), 'source_anchor_ids' => $sourceIds,
        'anchor_ids' => $ids, 'anchors' => $anchors, 'leaf_receipts' => $leafReceipts,
        'preservation_before' => $before, 'preservation_after' => $after,
        'menus_before' => ['count' => count($menusBefore), 'sha256' => hash('sha256', m2f_json($menusBefore))],
        'retained_menus_after' => ['count' => count($retained), 'sha256' => hash('sha256', m2f_json($retained))],
        'menus_after' => ['count' => count($menusAfter), 'sha256' => hash('sha256', m2f_json($menusAfter))],
        'private_inputs_before' => $privateBefore, 'private_inputs_after' => $privateAfter,
        'accounts' => ['application' => 'audit_app', 'maintenance' => 'audit_migrator'], 'App_business' => 'NOT RUN'];
}
