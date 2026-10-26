<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

function preview_storefront_require(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException('Preview storefront: ' . $reason);
}

/** Read only the first bundled theme tuple as data; never execute its INSERT or copy its business selections. */
function preview_storefront_builtin(): array
{
    $sql = file_get_contents('/workspace/crmeb/public/install/crmeb.sql');
    preview_storefront_require(is_string($sql) && strlen($sql) <= 16777216, 'bundled installation unavailable');
    preview_storefront_require(preg_match('/INSERT INTO `eb_theme` \(([^;]+?)\) VALUES \(/s', $sql, $match, PREG_OFFSET_CAPTURE) === 1,
        'bundled theme unavailable');
    preg_match_all('/`([a-z_]+)`/', $match[1][0], $columns);
    $position = $match[0][1] + strlen($match[0][0]); $values = [];
    foreach ($columns[1] as $column) {
        while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
        preview_storefront_require(isset($sql[$position]), 'bundled theme truncated');
        if ($sql[$position] === "'") {
            ++$position; $value = ''; $closed = false;
            while (isset($sql[$position])) {
                $character = $sql[$position++];
                if ($character === '\\') {
                    preview_storefront_require(isset($sql[$position]), 'bundled string truncated');
                    $escaped = $sql[$position++];
                    $value .= match ($escaped) { '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a", default => $escaped };
                } elseif ($character === "'") {
                    if (($sql[$position] ?? null) === "'") { $value .= "'"; ++$position; }
                    else { $closed = true; break; }
                } else { $value .= $character; }
            }
            preview_storefront_require($closed, 'bundled string incomplete');
        } else {
            $start = $position;
            while (isset($sql[$position]) && $sql[$position] !== ',' && $sql[$position] !== ')') ++$position;
            $value = trim(substr($sql, $start, $position - $start));
            // SQL NULL is case-insensitive; accept numeric literals, never expressions or executable SQL.
            preview_storefront_require(strcasecmp($value, 'NULL') === 0
                || preg_match('/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/D', $value) === 1,
                'unexpected bundled literal');
        }
        $values[$column] = $value;
        while (isset($sql[$position]) && ctype_space($sql[$position])) ++$position;
        if (count($values) < count($columns[1])) {
            preview_storefront_require(($sql[$position] ?? null) === ',', 'bundled tuple separator'); ++$position;
        } else preview_storefront_require(($sql[$position] ?? null) === ')', 'bundled tuple incomplete');
    }
    $result = [];
    foreach (['home', 'detail', 'user'] as $domain) {
        $value = json_decode($values[$domain . '_data'] ?? '', true, 64, JSON_THROW_ON_ERROR);
        preview_storefront_require(is_array($value) && $value !== [], 'bundled domain unavailable');
        $result[$domain] = $value;
    }
    return $result;
}

/** Keep component layout/style, with no remote assets, imported product IDs or old category/label selections. */
function preview_storefront_clean(mixed $value): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $item) $value[$key] = preview_storefront_clean($item);
        return $value;
    }
    if (is_string($value) && (preg_match('~(?:https?:)?//~i', $value) === 1 || str_contains($value, '/uploads/')
        || preg_match('/天俪|tianli|crmeb\.com/i', $value) === 1)) return '';
    return $value;
}

function preview_storefront_component(array $layout, string $name): array
{
    foreach ($layout['value'] ?? [] as $component) {
        if (is_array($component) && ($component['name'] ?? null) === $name) {
            $component = preview_storefront_clean($component);
            foreach (['goodsList', 'productList'] as $field) if (isset($component[$field])) $component[$field]['list'] = [];
            if (isset($component['goodsLabel'])) { $component['goodsLabel']['activeValue'] = []; $component['goodsLabel']['list'] = []; }
            if (isset($component['classList'])) $component['classList'] = ['classVal' => []];
            $component['isHide'] = false;
            return $component;
        }
    }
    throw new RuntimeException('Preview storefront: bundled component unavailable');
}

function preview_storefront_layouts(): array
{
    $builtin = preview_storefront_builtin();
    $goods = preview_storefront_component($builtin['home'], 'goodList');
    $goods['id'] = 'preview-products'; $goods['timestamp'] = 100;
    $goods['headerType'] = ['tabVal' => 0]; $goods['headerText'] = ['value' => '在售商品'];
    $goods['typeConfig']['activeValue'] = 3; $goods['classList'] = ['classVal' => []];
    $goods['goodsSort']['tabVal'] = 0; $goods['numberConfig']['val'] = 20;
    $navigation = preview_storefront_component($builtin['home'], 'pageFoot');
    $navigation['id'] = 'preview-navigation'; $navigation['timestamp'] = 200;
    $navigation['effectConfig']['tabVal'] = 1; $navigation['navStyleConfig']['tabVal'] = 1;
    $navigation['menuList'] = [];
    foreach (['首页' => '/pages/index/index', '分类' => '/pages/goods_cate/goods_cate',
        '购物车' => '/pages/order_addcart/order_addcart', '我的' => '/pages/user/index'] as $name => $link) {
        $navigation['menuList'][] = ['name' => $name, 'link' => $link, 'imgList' => ['', '']];
    }
    $home = ['type' => 'home', 'title' => '多商户商城预览', 'name' => '隔离预览', 'is_show' => 1,
        'is_bg_color' => 1, 'is_bg_pic' => 0, 'color_picker' => '#f5f5f5', 'bg_pic' => '',
        'value' => ['preview-products' => $goods, 'preview-navigation' => $navigation]];
    $detail = ['type' => 'detail', 'title' => '商品详情', 'value' => []];
    foreach (['productInfo', 'productDesc', 'bottomMenu'] as $index => $name) {
        $component = preview_storefront_component($builtin['detail'], $name);
        $component['id'] = 'preview-detail-' . $index; $component['timestamp'] = ($index + 1) * 100;
        $detail['value'][$component['id']] = $component;
        if ($name === 'productInfo') {
            $reviews = preview_storefront_component($builtin['detail'], 'reviews');
            $reviews['id'] = 'preview-detail-reviews'; $reviews['timestamp'] = 150;
            $reviews['checkBoxConfig']['type'] = [0, 1];
            $detail['value'][$reviews['id']] = $reviews;
        }
    }
    $member = preview_storefront_component($builtin['user'], 'member');
    $member['id'] = 'preview-member'; $member['timestamp'] = 100;
    $user = ['type' => 'user', 'title' => '个人中心', 'value' => ['preview-member' => $member]];
    $number = 0;
    foreach ($builtin['user']['value'] ?? [] as $candidate) {
        if (($candidate['name'] ?? null) !== 'menus') continue;
        $orders = array_values(array_filter($candidate['menuConfig']['list'] ?? [], static fn(array $item): bool =>
            is_string($item['info'][1]['value'] ?? null) && str_starts_with($item['info'][1]['value'], '/pages/goods/order_list/index')));
        if ($orders === []) continue;
        $candidate = preview_storefront_clean($candidate); $candidate['menuConfig']['list'] = preview_storefront_clean($orders);
        $candidate['id'] = 'preview-orders-' . $number; $candidate['timestamp'] = 200 + $number;
        $candidate['isHide'] = false; $user['value'][$candidate['id']] = $candidate; ++$number;
    }
    preview_storefront_require($number > 0, 'bundled order navigation unavailable');
    // The bundled theme has NULL colours; ThemeServices::getThemeInfo('theme') supplies its original defaults.
    return ['home' => $home, 'detail' => $detail, 'user' => $user];
}

/** May be called after preview_seed() and before bootstrap writes initialized.json. */
function preview_storefront(): array
{
    preview_storefront_require(PHP_SAPI === 'cli', 'CLI only');
    preview_assert_isolated(); $config = preview_config();
    $layouts = preview_storefront_layouts();
    $app = preview_app(); $connection = \think\facade\Db::connect();
    $services = $app->make(\app\services\diy\ThemeServices::class);
    $id = $connection->transaction(static function () use ($connection, $services, $layouts, $config): int {
        $pdo = $connection->getPdo();
        preview_storefront_require($pdo instanceof PDO && $pdo->inTransaction()
            && $pdo->query('SELECT DATABASE()')->fetchColumn() === 'mall_preview', 'real preview transaction');
        preview_storefront_require($pdo->query('SELECT id FROM eb_theme FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN) === [],
            'existing themes refused; initialization is create-only');
        $id = $services->saveTheme(0, ['tid' => 0, 'title' => '隔离预览通用主题', 'type' => 'home',
            'page_type' => 'theme', 'value' => $layouts['home']]);
        $idLimit = min(PHP_INT_MAX, 2147483647); // Original eb_theme.id is a signed INT.
        if (is_string($id)) {
            $limit = (string)$idLimit;
            preview_storefront_require(preg_match('/\A[1-9][0-9]*\z/D', $id) === 1
                && (strlen($id) < strlen($limit) || (strlen($id) === strlen($limit) && strcmp($id, $limit) <= 0)),
                'theme identity unavailable');
            $id = (int)$id;
        }
        preview_storefront_require(is_int($id) && $id > 0 && $id <= $idLimit, 'theme identity unavailable');
        foreach (['detail', 'user'] as $domain) {
            preview_storefront_require($services->saveTheme($id, ['tid' => 0, 'title' => '', 'type' => $domain,
                'page_type' => 'theme', 'value' => $layouts[$domain]]) === $id, 'theme domain save');
        }
        $statement = $pdo->prepare('UPDATE eb_theme SET info=? WHERE id=?');
        preview_storefront_require($statement->execute(['isolated-preview:' . $config['run_id'], $id]) && $statement->rowCount() === 1,
            'theme preview marker');
        preview_storefront_require($services->useTheme($id) === true, 'theme activation');
        foreach (['home', 'detail', 'user'] as $domain) {
            preview_storefront_require($services->getThemeInfo($id, $domain) === $layouts[$domain], 'full theme readback');
        }
        preview_storefront_require($services->themeNavigation() === $layouts['home']['value']['preview-navigation'], 'navigation readback');
        return $id;
    });
    preview_storefront_require($connection->getPdo() instanceof PDO && !$connection->getPdo()->inTransaction(), 'theme commit');
    preview_assert_isolated();
    return ['theme_id' => $id, 'home_components' => ['goodList', 'pageFoot'], 'product_source' => 'original_public_products_api',
        'imported_product_ids' => 0, 'real_payment_available' => false];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc !== 2 || $argv[1] !== 'initialize') throw new RuntimeException('Use storefront.php initialize');
        $config = preview_config();
        preview_storefront_require(is_file('/run/preview-app/initialized.json'), 'preview initialization required');
        $initialized = json_decode(file_get_contents('/run/preview-app/initialized.json'), true, 32, JSON_THROW_ON_ERROR);
        preview_storefront_require(($initialized['project'] ?? null) === $config['project']
            && ($initialized['run_id'] ?? null) === $config['run_id'], 'initialized preview identity');
        echo json_encode(preview_storefront(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { preview_error($error); exit(1); }
}
