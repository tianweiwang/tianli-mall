<?php
declare(strict_types=1);

/** Synthetic preview only. No orders, paid flags, permission bypass or formal acceptance. */
function preview_seed_require(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException('Preview seed: ' . $reason);
}

function preview_seed_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function preview_seed_insert(PDO $db, string $table, array $row): int
{
    preview_seed_require(preg_match('/\Aeb_[a-z_]+\z/D', $table) === 1 && $row !== [], 'invalid fixture table');
    foreach (array_keys($row) as $column) {
        preview_seed_require(is_string($column) && preg_match('/\A[a-z_]+\z/D', $column) === 1, 'invalid fixture field');
    }
    $query = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row))
        . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
    preview_seed_require($query->execute(array_values($row)) && $query->rowCount() === 1, 'fixture insert incomplete');
    return (int)$db->lastInsertId();
}

function preview_seed_completed(array $receipt): void
{
    if (($receipt['state'] ?? null) !== 'completed' || ($receipt['issues'] ?? null) !== []
        || (array_key_exists('native_exit', $receipt) && $receipt['native_exit'] !== 0)) {
        echo preview_seed_json(['migration' => array_intersect_key($receipt,
            array_flip(['mode', 'state', 'native_exit', 'issues']))]) . "\n";
    }
    preview_seed_require(($receipt['state'] ?? null) === 'completed' && ($receipt['issues'] ?? null) === []
        && (!array_key_exists('native_exit', $receipt) || $receipt['native_exit'] === 0), 'migration incomplete');
}

/** Use the original installer's statement/line split, with an explicit data allow-list. */
function preview_seed_install(PDO $db, bool $resume = false): array
{
    $path = '/workspace/crmeb/public/install/crmeb.sql';
    $raw = file_get_contents($path);
    preview_seed_require(is_string($raw) && $raw !== '', 'installation source unavailable');
    $allowed = ['eb_agreement', 'eb_express', 'eb_lang_code', 'eb_lang_country', 'eb_lang_type',
        'eb_system_city', 'eb_system_config', 'eb_system_config_tab', 'eb_system_event_data',
        'eb_system_group', 'eb_system_menus', 'eb_system_notification', 'eb_system_route', 'eb_system_route_cate'];
    $statements = []; $created = []; $seeded = []; $storageOverrides = [];
    $routeStatements = 0; $routeRows = 0;
    foreach (explode(";\n", trim(str_replace("\r", '', $raw))) as $chunk) {
        $lines = array_filter(explode("\n", trim($chunk)), static fn(string $line): bool =>
            $line !== '' && !in_array(substr(ltrim($line), 0, 1), ['#', '-'], true));
        $statement = trim(implode("\n", $lines));
        if ($statement === '') continue;
        if (preg_match('/\ACREATE TABLE IF NOT EXISTS `(eb_[a-z0-9_]+)`\s*\(/D', $statement, $match) === 1) {
            preview_seed_require(!isset($created[$match[1]]), 'duplicate installation table');
            if ($match[1] === 'eb_qrcode') {
                // Keep the complete utf8mb4 ticket index under strict MySQL mode.
                $statement = str_replace('ROW_FORMAT=COMPACT', 'ROW_FORMAT=DYNAMIC', $statement, $replacements);
                preview_seed_require($replacements === 1, 'qrcode installation storage shape changed');
                $storageOverrides['eb_qrcode.row_format'] = 'DYNAMIC';
            }
            $created[$match[1]] = true; $statements[] = $statement;
        } elseif (preg_match('/\AINSERT INTO `(eb_[a-z0-9_]+)`\s*\(/D', $statement, $match) === 1
            && in_array($match[1], $allowed, true)) {
            if ($match[1] === 'eb_system_route') {
                // Original route writer stores the omitted error-code array as JSON [].
                $routeLines = explode("\n", $statement);
                $header = array_shift($routeLines);
                preview_seed_require(!str_contains($header, '`error_code`') && $routeLines !== [], 'route installation columns changed');
                $header = preg_replace('/\) VALUES\z/D', ', `error_code`) VALUES', $header, -1, $replacements);
                preview_seed_require(is_string($header) && $replacements === 1, 'route installation header changed');
                foreach ($routeLines as $index => &$line) {
                    $last = $index === count($routeLines) - 1;
                    preview_seed_require(str_starts_with($line, '(') && str_ends_with($line, $last ? ')' : '),'),
                        'route installation tuple layout changed');
                    $end = strlen($line) - ($last ? 1 : 2);
                    $line = substr($line, 0, $end) . ", '[]'" . substr($line, $end);
                    $routeRows++;
                }
                unset($line);
                $statement = implode("\n", array_merge([$header], $routeLines));
                $routeStatements++;
            }
            $seeded[$match[1]] = true; $statements[] = $statement;
        }
        // All installation business rows, cached state, file MD5s and scheduled jobs are omitted.
    }
    preview_seed_require(count($created) === 157 && isset($seeded['eb_system_menus'], $seeded['eb_system_config'],
        $seeded['eb_system_event_data'], $seeded['eb_system_notification']), 'installation shape changed');
    preview_seed_require($routeStatements === 53 && $routeRows === 1153, 'route installation data shape changed');
    $sourceAdjustments = ['preview_storage_overrides' => $storageOverrides,
        'preview_route_error_code_default' => ['json' => '[]', 'statements' => $routeStatements, 'rows' => $routeRows]];
    if ($resume) {
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        preview_seed_require(array_diff(array_keys($created), $tables) === [], 'partial installation cannot resume');
        return ['installation_tables' => count($created), 'source_sha256' => hash('sha256', $raw),
            'installation_reused' => true, 'create_source_adjustments' => $sourceAdjustments];
    }
    foreach ($statements as $statement) $db->exec($statement);
    $count = (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    preview_seed_require($count === 158, 'installation namespace differs');
    return ['installation_tables' => count($created), 'source_sha256' => hash('sha256', $raw),
        'installation_reused' => false, 'create_source_adjustments' => $sourceAdjustments];
}

function preview_seed_private_json(string $path, array $value): void
{
    $stream = fopen($path, 'x+b');
    preview_seed_require(is_resource($stream), 'private migration file already exists');
    try {
        preview_seed_require(chmod($path, 0600), 'private migration file mode');
        $raw = preview_seed_json($value) . "\n";
        preview_seed_require(fwrite($stream, $raw) === strlen($raw) && fflush($stream), 'private migration file write');
    } finally { fclose($stream); }
}

/** Only bootstrap may publish the self identity returned by the real MS2 migration. */
function preview_seed_publish_self(int $selfId): void
{
    $path = '/run/preview-app/config.json';
    preview_seed_require($selfId > 0 && is_file('/run/preview-bootstrap/config.json') && !is_link($path), 'bootstrap identity required');
    $stream = fopen($path, 'r+b');
    preview_seed_require(is_resource($stream), 'public configuration unavailable');
    try {
        preview_seed_require(flock($stream, LOCK_EX), 'public configuration lock');
        $before = fstat($stream); $config = preview_config();
        preview_seed_require(is_array($before) && $before['uid'] === 1000 && ($before['mode'] & 0777) === 0600,
            'public configuration ownership');
        unset($config['database']['migrator'], $config['credentials']);
        $config['self_operated_mer_id'] = $selfId;
        $raw = preview_seed_json($config) . "\n";
        preview_seed_require(rewind($stream) && ftruncate($stream, 0) && fwrite($stream, $raw) === strlen($raw)
            && fflush($stream) && rewind($stream) && stream_get_contents($stream) === $raw, 'public configuration write');
        $after = fstat($stream);
        foreach (['dev', 'ino', 'uid', 'gid', 'mode'] as $field) {
            preview_seed_require($after[$field] === $before[$field], 'public configuration identity changed');
        }
    } finally { flock($stream, LOCK_UN); fclose($stream); }
    preview_seed_require(preview_config()['self_operated_mer_id'] === $selfId, 'public self identity readback');
}

function preview_seed_migrations(PDO $db): int
{
    $directory = '/workspace/help/migrations/';
    foreach (['marketplace-ms1.php', 'marketplace-ms2.php', 'marketplace-ms4.php', 'marketplace-ms5-payment.php',
        'marketplace-ms4-fulfillment.php', 'marketplace-ms4-aftersale.php', 'marketplace-ms4-assets.php',
        'marketplace-ms2-task7-menus.php', 'marketplace-ms2-task7-stock-menu.php', 'marketplace-ms2-task7-submit-menu.php',
        'marketplace-ms4-order-read-menus.php', 'marketplace-ms4-review-menus.php', 'marketplace-ms4-aftersale-menus.php',
        'marketplace-ms4-product-media-menus.php', 'marketplace-ms4-platform-asset-menus.php',
        'marketplace-password-setup-reissue-menu.php', 'marketplace-merchant-exit-menus.php',
        'marketplace-finance-settings.php'] as $file) require_once $directory . $file;
    $ms1 = new \Audit\Migration\MarketplaceMs1Migration($db, 'eb_');
    preview_seed_completed($ms1->apply()); preview_seed_completed($ms1->check());
    $ms2 = new \Audit\Migration\MarketplaceMs2Migration($db, $ms1, 'eb_', '/workspace', ['enabled' => false],
        '/run/preview-bootstrap/ms2-state.json');
    $structure = $ms2->check();
    if (($structure['state'] ?? null) !== 'completed') $structure = $ms2->applyStructure();
    preview_seed_completed($structure);
    $self = $structure['self_mer_id'] ?? null;
    preview_seed_require(is_int($self) && $self > 0, 'migration self identity unavailable');
    $check = $ms2->check(); preview_seed_completed($check);
    $binding = $check['mapping_binding'] ?? null; $expectations = $check['mapping_expectations'] ?? null;
    preview_seed_require(is_array($binding) && is_array($expectations) && count($expectations) === 5, 'empty ownership binding unavailable');
    $tables = [];
    foreach ($expectations as $table => $expectation) {
        preview_seed_require($expectation['count'] === 0 && $expectation['groups'] === []
            && $expectation['non_owner_rows_sha256'] === hash('sha256', ''), 'installation imported business ownership');
        $tables[$table] = ['count' => 0, 'non_owner_rows_sha256' => hash('sha256', ''),
            'before_groups' => [], 'after_groups' => [], 'rows' => []];
    }
    // Later additive migrations may change the bound schema before a bootstrap resume.
    // All five roots were just proven empty; retain earlier bindings and bind this exact shape.
    $map = '/run/preview-bootstrap/ms2-empty-mapping-' . $binding['binding']['schema_sha256'] . '.json';
    if (!is_file($map)) preview_seed_private_json($map, ['schema' => 1, 'version' => 'preview-empty-' . preview_config()['run_id'],
        'source' => $binding['source'], 'binding' => $binding['binding'],
        'confirmation' => ['confirmed' => true, 'by' => 'ISOLATED_PREVIEW_EMPTY_DATABASE',
            'at' => gmdate('Y-m-d\TH:i:s\Z'), 'reference' => 'No historical rows; only this new sentinel-bound preview database'],
        'tables' => $tables, 'image_links' => [], 'attachment_category_links' => []]);
    $quarantine = '/run/preview-bootstrap/ms2-quarantine';
    preview_seed_require(is_dir($quarantine) || mkdir($quarantine, 0700), 'migration quarantine required');
    preview_seed_completed($ms2->enforce($map, $quarantine, true));
    $check = $ms2->check($map); preview_seed_completed($check);
    preview_seed_require(($check['migration_complete'] ?? false) === true, 'empty ownership not enforced');
    // Resume from real schemas, not a stage flag. Fresh databases retain the original order.
    // Aftersale stages 0..20 validate every Fulfillment table, including its replacement
    // operation contract; replaying the older exact contract after stage 16 rejects valid DDL.
    $core = new MarketplaceMs4Migration($db, 'eb_', 'mall_preview');
    $payment = new MarketplaceMs5PaymentMigration($db, 'eb_', 'mall_preview');
    $fulfillment = new MarketplaceMs4FulfillmentMigration($db, 'eb_', 'mall_preview');
    $aftersale = new MarketplaceMs4AftersaleMigration($db, 'eb_', 'mall_preview');
    $assets = new MarketplaceMs4AssetMigration($db, 'eb_', 'mall_preview');
    $completeStructure = static function (object $migration): void {
        $receipt = $migration->check();
        if (($receipt['state'] ?? null) === 'applicable'
            && ($receipt['issues'] ?? null) === [] && ($receipt['native_exit'] ?? null) === 0) {
            preview_seed_completed($migration->applyStructure());
        } else {
            preview_seed_completed($receipt);
        }
        preview_seed_completed($migration->check());
    };
    $completeStructure($core); $completeStructure($payment);
    $successor = $aftersale->check();
    $knownFulfillmentSuccessor = ($successor['issues'] ?? null) === []
        && ($successor['native_exit'] ?? null) === 0
        && ($successor['pending_steps_known'] ?? null) === true
        && is_int($successor['structure_stage'] ?? null)
        && $successor['structure_stage'] >= 0 && $successor['structure_stage'] <= 20
        && in_array($successor['state'] ?? null, ['applicable', 'completed'], true);
    if (!$knownFulfillmentSuccessor) $completeStructure($fulfillment);
    $completeStructure($aftersale); $completeStructure($assets);
    foreach ([MarketplaceMs2Task7Menus::class, MarketplaceMs2Task7StockMenu::class, MarketplaceMs2Task7SubmitMenu::class,
        MarketplaceMs4OrderReadMenus::class, MarketplaceMs4ReviewMenus::class, MarketplaceMs4AftersaleMenus::class,
        MarketplaceMs4ProductMediaMenus::class, MarketplaceMs4PlatformAssetMenus::class,
        MarketplacePasswordSetupReissueMenu::class, MarketplaceMerchantExitMenus::class] as $class) {
        $migration = new $class($db, 'eb_');
        preview_seed_completed($migration->apply()); preview_seed_completed($migration->check());
    }
    // Fresh installations receive only an unconfigured draft and ungranted permission leaves.
    // Existing previews use the dedicated migration, never replay this seed for a settings update.
    $finance = new MarketplaceFinanceSettingsMigration($db, 'eb_');
    $financeBefore = $finance->check();
    preview_seed_require(in_array($financeBefore['state'] ?? null, ['applicable', 'completed'], true), 'finance settings precheck failed');
    preview_seed_completed($finance->apply()); preview_seed_completed($finance->check());
    return $self;
}

/** Metadata only, on native PDOs, before the first App can enforce its version gate. */
function preview_seed_schema(PDO $db): array
{
    require_once '/workspace/help/migrations/marketplace-ms6-schema.php';
    $manifest = \crmeb\services\marketplace\SchemaVersionGate::manifest('/workspace/crmeb/marketplace-release.json');
    $migration = new \Audit\Migration\MarketplaceMs6SchemaMigration($db, 'eb_', $manifest);
    $applied = $migration->apply();
    $checked = $migration->check();
    $schema = \crmeb\services\marketplace\SchemaVersionGate::assertCompatible(preview_pdo(), 'eb_', $manifest);
    $state = $applied['state'] ?? null;
    preview_seed_require(in_array($state, ['created', 'unchanged'], true)
        && $applied === ['mode' => 'apply', 'state' => $state, 'changed' => $state === 'created',
            'code' => null, 'schema' => $schema]
        && $checked === ['mode' => 'check', 'state' => 'compatible', 'changed' => false,
            'code' => null, 'schema' => $schema], 'schema migration receipt mismatch');
    return ['apply' => $applied, 'check' => $checked, 'application_schema' => $schema];
}

function preview_seed_configuration(PDO $db): void
{
    $values = ['site_name' => '多商户商城隔离预览', 'site_url' => '', 'station_open' => 1, 'queue_open' => 0,
        'image_thumb_status' => 0, 'upload_type' => 1, 'member_func_status' => 0, 'member_card_status' => 0,
        'member_price_status' => 0, 'store_self_mention' => 0, 'store_free_postage' => '10000.00',
        'invoice_func_status' => 0, 'special_invoice_status' => 0, 'offline_pay_status' => 0, 'offline_postage' => 0,
        'integral_ratio' => '0.00', 'balance_func_status' => 0, 'yue_pay_status' => 0, 'pay_weixin_open' => 0,
        'ali_pay_status' => 0, 'friend_pay_status' => 0, 'order_cancel_time' => '0', 'order_receiving_time' => '0',
        'sms_account' => '', 'sms_token' => '', 'custom_admin_js' => ''];
    if ((preview_config()['legacy_review_fixture'] ?? false) === true) {
        $values['site_name'] = '旧版评价合成验证（测试余额）';
        $values['balance_func_status'] = 1;
        $values['yue_pay_status'] = 1;
    }
    foreach ($values as $name => $value) {
        $find = $db->prepare('SELECT id FROM eb_system_config WHERE menu_name=?'); $find->execute([$name]);
        $ids = $find->fetchAll(PDO::FETCH_COLUMN); preview_seed_require(count($ids) <= 1, 'duplicate configuration');
        if ($ids === []) preview_seed_insert($db, 'eb_system_config', ['menu_name' => $name, 'type' => 'text',
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => 1]);
        else $db->prepare('UPDATE eb_system_config SET value=? WHERE id=?')->execute([
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $ids[0]]);
    }
}

function preview_seed_picture(string $run): string
{
    $relative = '/uploads/preview-' . $run . '/product.png';
    $directory = '/workspace/crmeb/public' . dirname($relative);
    preview_seed_require(!file_exists($directory) && mkdir($directory, 0755), 'new preview picture directory required');
    $image = imagecreatetruecolor(320, 240);
    preview_seed_require($image instanceof GdImage, 'preview image allocation');
    try {
        imagefilledrectangle($image, 0, 0, 319, 239, imagecolorallocate($image, 234, 244, 255));
        imagefilledrectangle($image, 95, 45, 225, 175, imagecolorallocate($image, 54, 119, 221));
        imagestring($image, 5, 103, 100, 'PREVIEW', imagecolorallocate($image, 255, 255, 255));
        preview_seed_require(imagepng($image, '/workspace/crmeb/public' . $relative), 'preview picture write');
    } finally { imagedestroy($image); }
    preview_seed_require(chmod('/workspace/crmeb/public' . $relative, 0644)
        && chmod($directory, 0755)
        && chown($directory, 1000) && chown('/workspace/crmeb/public' . $relative, 1000), 'preview picture ownership');
    return $relative;
}

function preview_seed(bool $resume = false): array
{
    preview_assert_isolated(); $config = preview_config(); $db = preview_pdo(true);
    preview_seed_require(is_file('/run/preview-bootstrap/config.json') && PHP_SAPI === 'cli', 'bootstrap only');
    $tables = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    preview_seed_require($db->query('SELECT DATABASE()')->fetchColumn() === 'mall_preview' && ($resume || $tables === ['audit_sentinel'])
        && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [$config['run_id']], 'new empty preview database required');
    if ($resume) {
        foreach (['eb_system_admin', 'eb_user', 'eb_store_product', 'eb_store_order', 'eb_store_cart',
            'eb_marketplace_employee', 'eb_marketplace_role'] as $table) {
            preview_seed_require(in_array($table, $tables, true)
                && (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0,
                'resume requires no business fixtures');
        }
    }
    $credentials = $config['credentials'] ?? null;
    foreach (['admin', 'merchant', 'customer'] as $role) {
        $actor = $credentials[$role] ?? null;
        preview_seed_require(is_array($actor) && is_string($actor['account'] ?? null)
            && preg_match('/\A[a-z0-9_]{4,32}\z/D', $actor['account']) === 1
            && is_string($actor['password'] ?? null) && strlen($actor['password']) >= 8 && strlen($actor['password']) <= 72,
            'synthetic credentials required');
    }
    $install = preview_seed_install($db, $resume);
    $selfId = preview_seed_migrations($db); preview_seed_publish_self($selfId);
    $schemaBootstrap = preview_seed_schema($db);
    require_once '/workspace/crmeb/vendor/autoload.php';
    preview_seed_configuration($db);
    $picture = preview_seed_picture($config['run_id']); $now = time();
    $db->beginTransaction();
    try {
        // These are explicit fixtures, not evidence of real management create/review workflows.
        $adminId = preview_seed_insert($db, 'eb_system_admin', ['account' => $credentials['admin']['account'],
            'pwd' => password_hash($credentials['admin']['password'], PASSWORD_BCRYPT), 'real_name' => '预览平台管理员',
            'roles' => '', 'level' => 0, 'status' => 1, 'is_del' => 0, 'add_time' => $now]);
        $merchantId = preview_seed_insert($db, 'eb_marketplace_merchant', ['name' => '预览合成商家', 'review_status' => 1,
            'accept_orders' => 1, 'fulfillment_enabled' => 1, 'security_locked' => 0, 'closed_at' => null,
            'after_sale_contact' => '隔离预览售后', 'add_time' => $now, 'update_time' => $now]);
        $productMerchantId = $merchantId;
        if (($config['legacy_review_fixture'] ?? false) === true) {
            // The original catalog reads only the explicitly configured self-operated owner.
            $enableSelf = $db->prepare('UPDATE eb_marketplace_merchant SET review_status=1, accept_orders=1, update_time=? WHERE mer_id=?');
            $enableSelf->execute([$now, $selfId]);
            preview_seed_require($enableSelf->rowCount() === 1, 'new synthetic self-operated merchant required');
            $productMerchantId = $selfId;
            preview_seed_insert($db, 'eb_shipping_templates', ['id' => 1, 'mer_id' => $selfId,
                'name' => '合成默认运费（零费用）', 'type' => 1, 'add_time' => $now]);
            preview_seed_insert($db, 'eb_shipping_templates_region', ['temp_id' => 1, 'city_id' => 0,
                'first' => '1.00', 'first_price' => '0.00', 'continue' => '1.00', 'continue_price' => '0.00',
                'type' => 1, 'uniqid' => 'preview-legacy-default']);
        }
        $roleId = preview_seed_insert($db, 'eb_marketplace_role', ['mer_id' => $merchantId, 'name' => '预览管理员',
            'permissions' => preview_seed_json(\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN),
            'add_time' => $now, 'update_time' => $now]);
        $employeeId = preview_seed_insert($db, 'eb_marketplace_employee', ['mer_id' => $merchantId, 'role_id' => $roleId,
            'account' => $credentials['merchant']['account'], 'pwd' => null, 'name' => '预览商家员工',
            'status' => 1, 'is_del' => 0, 'add_time' => $now, 'update_time' => $now]);
        $capability = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        preview_seed_insert($db, 'eb_marketplace_password_setup', ['mer_id' => $merchantId, 'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $capability, true), 'expires_at' => $now + 86400, 'consumed_at' => null, 'add_time' => $now]);
        $customerId = preview_seed_insert($db, 'eb_user', ['account' => $credentials['customer']['account'],
            'nickname' => '预览合成顾客', 'user_type' => 'h5', 'is_money_level' => 0,
            'now_money' => ($config['legacy_review_fixture'] ?? false) === true ? '200.00' : '0.00',
            'status' => 1, 'is_del' => 0, 'spread_uid' => 0, 'add_time' => $now]);
        $addressId = preview_seed_insert($db, 'eb_user_address', ['uid' => $customerId, 'real_name' => '预览顾客',
            'phone' => '00000000000', 'province' => '预览省', 'city' => '预览市', 'district' => '预览区',
            'detail' => '专用隔离合成地址', 'is_default' => 1, 'add_time' => $now]);
        $categoryId = preview_seed_insert($db, 'eb_store_category', ['pid' => 0, 'cate_name' => '预览商品',
            'is_show' => 1, 'sort' => 0, 'pic' => $picture, 'add_time' => $now]);
        preview_seed_insert($db, 'eb_system_attachment', ['mer_id' => $productMerchantId, 'name' => 'product.png',
            'att_dir' => $picture, 'satt_dir' => $picture, 'att_size' => (string)filesize('/workspace/crmeb/public' . $picture),
            'att_type' => 'image/png', 'pid' => 0, 'time' => $now, 'image_type' => 1, 'module_type' => 1,
            'real_name' => 'preview-product.png', 'scan_token' => '', 'type' => 0]);
        $products = [];
        foreach ([['name' => '基础体验商品', 'price' => '20.15'], ['name' => '第二件体验商品', 'price' => '30.25']] as $spec) {
            $id = preview_seed_insert($db, 'eb_store_product', ['mer_id' => $productMerchantId, 'store_name' => $spec['name'],
                'store_info' => '仅供当前隔离环境体验，不产生真实交易', 'unit_name' => '件', 'spec_type' => 0,
                'stock' => 100, 'sales' => 0, 'price' => $spec['price'], 'cost' => '7.45', 'postage' => '0.00',
                'freight' => 1, 'temp_id' => 0, 'is_show' => 1, 'review_status' => 2, 'is_vip' => 0,
                'logistics' => '1', 'min_qty' => 1, 'is_limit' => 0, 'image' => $picture,
                'slider_image' => preview_seed_json([$picture]), 'cate_id' => (string)$categoryId, 'custom_form' => '[]', 'add_time' => $now]);
            $unique = bin2hex(random_bytes(4));
            $sku = ['product_id' => $id, 'type' => 0, 'unique' => $unique, 'suk' => '默认', 'stock' => 100, 'sales' => 0,
                'price' => $spec['price'], 'cost' => '7.45', 'ot_price' => '0.00', 'vip_price' => '0.00',
                'is_show' => 1, 'is_default_select' => 1, 'is_virtual' => 0, 'weight' => '0.00', 'volume' => '0.00',
                'bar_code' => '', 'bar_code_number' => '', 'image' => $picture];
            $skuId = preview_seed_insert($db, 'eb_store_product_attr_value', $sku);
            preview_seed_insert($db, 'eb_store_product_attr', ['product_id' => $id, 'type' => 0, 'attr_name' => '规格', 'attr_values' => '默认']);
            $definition = $sku; unset($definition['stock'], $definition['sales'], $definition['is_virtual'], $definition['image']);
            $definition += ['id' => $skuId, 'pic' => $picture, 'detail' => ['规格' => '默认'], 'attr_arr' => ['默认']];
            preview_seed_insert($db, 'eb_store_product_attr_result', ['product_id' => $id, 'type' => 0, 'change_time' => $now,
                'result' => preview_seed_json(['attr' => [['value' => '规格', 'detail' => [['value' => '默认', 'pic' => '']]]], 'value' => [$definition]])]);
            preview_seed_insert($db, 'eb_store_product_description', ['product_id' => $id, 'type' => 0,
                'description' => htmlspecialchars('<p>独立预览合成商品。</p>')]);
            preview_seed_insert($db, 'eb_store_product_cate', ['product_id' => $id, 'cate_id' => $categoryId,
                'cate_pid' => $categoryId, 'status' => 1, 'add_time' => $now]);
            $products[] = ['product_id' => $id, 'sku_id' => $skuId, 'sku_unique' => $unique];
        }
        preview_seed_require($db->commit(), 'fixture commit');
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    // Use original password writers; do not fabricate a request, token, session or paid order.
    $app = preview_app(true);
    $app->make(\app\services\marketplace\EmployeeAuthServices::class)->setupPassword($capability, $credentials['merchant']['password']);
    preview_seed_require($app->make(\app\services\user\LoginServices::class)->reset($credentials['customer']['account'],
        $credentials['customer']['password']) === true, 'customer password initialization');
    unset($capability, $credentials, $config);
    preview_seed_require($app->make(\app\services\system\log\SystemFileServices::class)->writeMd5() === true,
        'original file checksum initialization');
    $cache = $app->make(\app\services\other\CacheServices::class);
    $cache->setDbCache('write_md5', get_crmeb_version_vode());
    preview_seed_require($cache->checkDbCache('write_md5', get_crmeb_version_vode()), 'original version initialization');
    foreach (['eb_store_order', 'eb_store_cart', 'eb_marketplace_payment_registry', 'eb_marketplace_payment_attempt'] as $table) {
        preview_seed_require((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, 'seed created transaction state');
    }
    preview_assert_isolated();
    return $install + ['self_operated_mer_id' => $selfId, 'admin_id' => $adminId, 'merchant_id' => $merchantId,
        'employee_id' => $employeeId, 'customer_id' => $customerId, 'address_id' => $addressId,
        'products' => $products, 'schema_bootstrap' => $schemaBootstrap,
        'synthetic_only' => true, 'orders_created' => 0, 'management_workflows_verified' => false];
}
