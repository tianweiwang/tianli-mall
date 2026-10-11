<?php
declare(strict_types=1);

/**
 * MS0a Task 7 Step 1: read the actual ThinkPHP adminapi route registry and the
 * isolated installation permission rows. No HTTP request, controller dispatch,
 * import/sync service, database mutation or cache mutation is performed.
 *
 * Run through isolated/run.sh. Source is mounted read-only; output goes only to
 * /run/audit/evidence. The matrix proposes policy for SP-MS0a-1; it does not
 * create auth_routes configuration or modify verifyAuth.
 */
require_once __DIR__ . '/lib/harness.php';

function inventory_normalize_rule(string $value, bool $menu = false): string
{
    return trim(strtolower($menu ? str_replace(' ', '', $value) : $value));
}

function inventory_middlewares(array $options): array
{
    $classes = [];
    foreach ((array)($options['middleware'] ?? []) as $entry) {
        $class = is_array($entry) ? ($entry[0] ?? null) : $entry;
        if (is_string($class)) { $classes[] = ltrim($class, '\\'); }
    }
    return array_values(array_unique($classes));
}

function inventory_policy(array $row): array
{
    $key = $row['method'] . ' ' . $row['rule'];
    if ($row['miss']) { return ['覆盖边界', 'MISS 返回 OPTIONS/CORS 或 404，不是业务控制器，不进入 verifyAuth']; }
    if (!$row['auth']) { return ['覆盖边界', '现有公开分组，不进入 verifyAuth；不加入已登录白名单']; }
    if (!$row['role']) {
        return ['覆盖边界', $key === 'get route/import_api'
            ? '仅认证；导入会写 eb_system_route 元数据；收紧 verifyAuth 不覆盖，补授权须单独决定'
            : '仅认证，不进入 verifyAuth；收紧后仍按现有认证分组处理，须明确接受覆盖限制'];
    }
    if ($key === 'get setting/admin/logout') { return ['白名单', '建议保留现有例外，删除本人会话；仅 GET，等待用户确认']; }
    if ($key === 'get menuslist') { return ['白名单', '建议保留现有例外；搜索菜单元数据未按当前角色筛选，仅 GET，等待用户确认']; }
    if ($row['registered']) { return ['已登记授权', '接口登记存在；受限管理员仍须获得当前有效角色授权']; }
    if ($row['crud']) { return ['拒绝', '生成 CRUD 未登记；移除前缀放行，不能仅凭 crud/ 自动授权']; }
    if ($key === 'get menus' || $key === 'get setting/menus/unique') {
        return ['拒绝', '严格两项白名单方案下拒绝；按本人角色返回菜单/标识，可另选扩大白名单，尚未批准'];
    }
    return ['拒绝', '未登记且不在建议的两项白名单；受限管理员将被拒绝'];
}

function inventory_cell(mixed $value): string
{
    return str_replace(['|', "\r", "\n"], ['\\|', '', ' '], (string)$value);
}

function inventory_table(array $rows): string
{
    $text = "| 方法 | 规则 | 有认证中间件 | 有授权中间件 | 已登记 | 建议策略（已登记授权 / 白名单 / 拒绝） | 理由 |\n|---|---|---|---|---|---|---|\n";
    foreach ($rows as $row) {
        $cells = [strtoupper($row['method']), '`' . $row['rule'] . '`', $row['auth'] ? '是' : '否', $row['role'] ? '是' : '否', $row['registered'] ? '是' : '否', $row['policy'], $row['reason']];
        $text .= '| ' . implode(' | ', array_map('inventory_cell', $cells)) . " |\n";
    }
    return $text;
}

function inventory_app(): \think\App
{
    require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
    $app = new \think\App(audit_repo_dir() . '/crmeb/');
    $config = audit_config();
    $app->env->set([
        'APP_DEBUG' => false, 'APP_APP_KEY' => $config['signing_key'],
        'DATABASE_HOSTNAME' => 'audit-mysql', 'DATABASE_HOSTPORT' => '3306', 'DATABASE_DATABASE' => 'audit_auth',
        'DATABASE_USERNAME' => 'audit_app', 'DATABASE_PASSWORD' => $config['database']['password'],
        'DATABASE_PREFIX' => 'eb_', 'DATABASE_CHARSET' => 'utf8mb4', 'DATABASE_DEBUG' => false,
        'CACHE_DRIVER' => 'redis', 'REDIS_REDIS_HOSTNAME' => 'audit-redis', 'REDIS_PORT' => '6379',
        'REDIS_REDIS_PASSWORD' => $config['redis_password'],
    ]);
    $app->initialize();
    return $app;
}

try {
    audit_guard_assert();
    $pdo = audit_db();
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $spec = ['tables' => array_fill_keys($tables, null), 'redis' => ['*']];
    $before = audit_snapshot($spec);

    $app = inventory_app();
    // This existing service performs real includes (including route/crud/) in
    // the correct object context, expands nested groups/resources, and removes
    // resource actions excluded by only/except after test-mode expansion.
    $rawRoutes = $app->make(\app\services\system\SystemRouteServices::class)->getRouteListAll('adminapi');
    $menus = $pdo->query("SELECT id, api_url, methods FROM eb_system_menus WHERE auth_type = 2 ORDER BY id")->fetchAll();
    $registered = [];
    $emptyPermissionRows = 0;
    foreach ($menus as $menu) {
        if ((string)$menu['api_url'] === '') { ++$emptyPermissionRows; continue; }
        $key = trim(strtolower((string)$menu['methods'])) . ' ' . inventory_normalize_rule((string)$menu['api_url'], true);
        $registered[$key][] = (int)$menu['id'];
    }

    $rows = [];
    $keys = [];
    $duplicates = [];
    foreach ($rawRoutes as $route) {
        $method = trim(strtolower((string)$route['method']));
        $rule = inventory_normalize_rule((string)$route['rule']);
        $classes = inventory_middlewares((array)$route['option']);
        $auth = in_array('app\\adminapi\\middleware\\AdminAuthTokenMiddleware', $classes, true);
        $role = in_array('app\\adminapi\\middleware\\AdminCheckRoleMiddleware', $classes, true);
        $miss = str_ends_with($rule, '<miss>');
        audit_expect(!$role || $auth, 'authorization route is missing token authentication');
        audit_expect(!$role || in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true), 'protected route has a method contract requiring a separate decision');
        $key = $method . ' ' . $rule;
        $row = [
            'method' => $method, 'rule' => $rule, 'auth' => $auth, 'role' => $role,
            'registered' => isset($registered[$key]), 'permission_ids' => $registered[$key] ?? [],
            'crud' => str_starts_with($rule, 'crud/'), 'miss' => $miss,
            'target' => is_string($route['route']) ? $route['route'] : '[closure]',
            'middlewares' => $classes,
        ];
        [$row['policy'], $row['reason']] = inventory_policy($row);
        if (isset($keys[$key])) { $duplicates[] = $key; }
        $keys[$key] = true;
        $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int => [$a['rule'], $a['method'], $a['target']] <=> [$b['rule'], $b['method'], $b['target']]);
    foreach (['get setting/admin/logout', 'get menuslist', 'get menus', 'get setting/menus/unique'] as $required) {
        audit_expect(isset($keys[$required]), 'expected menu/logout route is absent');
    }

    $select = static fn(callable $filter): array => array_values(array_filter($rows, $filter));
    $public = $select(static fn(array $r): bool => !$r['auth'] && !$r['miss']);
    $authOnly = $select(static fn(array $r): bool => $r['auth'] && !$r['role']);
    $protected = $select(static fn(array $r): bool => $r['role']);
    $miss = $select(static fn(array $r): bool => $r['miss']);
    $crud = $select(static fn(array $r): bool => $r['crud']);
    $unregistered = $select(static fn(array $r): bool => $r['role'] && !$r['registered']);
    $reject = $select(static fn(array $r): bool => $r['policy'] === '拒绝');
    $whitelist = $select(static fn(array $r): bool => $r['policy'] === '白名单');
    $counts = [
        'total_routes' => count($rows), 'unique_method_rules' => count($keys),
        'public_routes' => count($public), 'authentication_only_routes' => count($authOnly),
        'authorization_routes' => count($protected), 'miss_routes' => count($miss),
        'crud_routes' => count($crud), 'registered_authorization_routes' => count($protected) - count($unregistered),
        'unregistered_authorization_routes' => count($unregistered), 'proposed_whitelist_routes' => count($whitelist),
        'proposed_rejected_routes' => count($reject), 'permission_rows' => count($menus),
        'empty_api_permission_rows' => $emptyPermissionRows, 'unique_permission_keys' => count($registered),
        'duplicate_method_rules' => count(array_unique($duplicates)),
    ];
    audit_expect(count($rows) === count($public) + count($authOnly) + count($protected) + count($miss), 'route coverage counts do not reconcile');

    $after = audit_snapshot($spec);
    $differences = audit_assert_unchanged($before, $after);
    audit_expect($differences === [], 'read-only inventory changed database/cache state');
    $generated = gmdate('Y-m-d\TH:i:s\Z');
    $sourceSha = getenv('AUDIT_SOURCE_SHA') ?: '';
    $runId = getenv('AUDIT_RUN_ID') ?: '';
    $text = "# MS0a adminapi 路由与权限登记矩阵\n\n";
    $text .= "状态：Task 7 Step 1 已执行；SP-MS0a-1 待用户确认。所有策略均为建议，尚未修改 `verifyAuth()` 或创建 `auth_routes.php`。\n\n";
    $text .= "- 执行时间（UTC）：`$generated`；RUN_ID：`$runId`；源码 HEAD：`$sourceSha`。\n";
    $text .= "- 枚举 API：`SystemRouteServices::getRouteListAll('adminapi')`，真实载入框架路由，包含嵌套 group、资源路由的 only/except 过滤与 CRUD 自动载入。\n";
    $text .= "- 权限来源：专用 `audit_auth` 中安装种子的 `eb_system_menus`，仅 `auth_type = 2`；不修改权限登记。比对谓词与现有 `verifyAuth()` 一致，未额外按 is_del、is_show 或 access 过滤。\n";
    $text .= "- 规则采用框架返回模板（例如 `<id>`、`<key?>`）；不猜测具体 URL。路由规则为 trim+lowercase；菜单规则额外删除普通空格；方法为 trim+lowercase，按方法与规则精确匹配。\n";
    $text .= "- 只读证据：全部 " . count($tables) . " 张表及 Redis 全键前后摘要一致；无 HTTP、控制器调用、导入/同步、数据修改或缓存清理。启动 guard 的无权限 CREATE DATABASE 探针被拒绝，未创建数据库。\n\n";
    $text .= "## 实际统计\n\n| 项目 | 数量 |\n|---|---|\n";
    foreach ($counts as $name => $count) { $text .= '| `' . $name . '` | ' . $count . " |\n"; }
    $text .= "\n## 停止点建议：严格保留现有两项已登录白名单\n\n";
    $text .= "推荐仅列入 `get setting/admin/logout` 与 `get menuslist`，绑定实际 GET 方法；其余已登记路由继续要求角色授权，未登记且有授权中间件的路由拒绝。此方案尽量收窄现有例外，但会拒绝下文未登记业务接口，影响需要用户明确接受。\n\n";
    $text .= inventory_table($whitelist);
    $text .= "\n### 本人菜单候选与语义差异（未批准，不实施）\n\n";
    $text .= "| 方法与规则 | 具体调用 | 语义 | 严格两项方案 |\n|---|---|---|---|\n";
    foreach (['get menus' => ['v1.setting.SystemMenus/menus', '以当前管理员 roles/level 调用 getMenusList，返回本人菜单与 unique'], 'get setting/menus/unique' => ['v1.setting.SystemMenus/unique', '以当前管理员 roles/level 调用 getMenusList，返回本人菜单与 uniqueAuth'], 'get menuslist' => ['Common/menusList', '调用 SystemMenusDao::getSearchList，未按当前角色筛选的可搜索菜单元数据']] as $key => [$target, $meaning]) {
        $candidate = array_values(array_filter($rows, static fn(array $r): bool => $r['method'] . ' ' . $r['rule'] === $key))[0];
        $text .= '| `' . $key . '` | `' . $target . '` | ' . $meaning . ' | ' . $candidate['policy'] . " |\n";
    }
    $text .= "\n`post menussearch` 会搜索菜单、配置项、配置标签，未按当前角色过滤，不建议仅凭“菜单搜索”名称加入白名单。本人资料 `get setting/info`、`put setting/update_admin` 也不自动加入；如扩大白名单，应另行逐项确认方法、规则及含义。\n\n";
    $text .= "## 覆盖边界：只有认证的入口\n\n";
    $text .= "这些入口未挂 `AdminCheckRoleMiddleware`，因此不会进入 `verifyAuth()`。矩阵不得把它们标成收紧后会被拒绝。`get route/import_api` 接收 file_path，调用 PublicController::import → SystemRouteServices::import → importData，向 `eb_system_route` 写入/更新数据，属于有写入行为的仅认证入口。`system/info` 返回服务器环境信息；`download/<key?>` 依据缓存文件键下载并删除该缓存键。是否补授权中间件或接受现状，需要单独决定；当前不扩大修复。\n\n";
    $text .= inventory_table($authOnly);
    $text .= "\n## 公开入口与 MISS/CORS\n\n公开入口不加入已登录白名单；本次收紧 verifyAuth 不改变其现有分组。MISS 是未匹配路由的 OPTIONS/CORS 或 404 响应，不作为受保护业务路由计数。\n\n";
    $text .= inventory_table(array_merge($public, $miss));
    $text .= "\n## 全部未登记且挂授权中间件的路由\n\n共 " . count($unregistered) . " 项。表中两项建议白名单仍须确认；其余标为拒绝的路由将拒绝受限管理员，超级管理员现有 level=0 旁路保持现状。\n\n";
    $text .= inventory_table($unregistered);
    $text .= "\n## 将被拒绝的具体清单（严格两项方案）\n\n共 " . count($reject) . " 项；只有既未登记又不在建议白名单、且挂授权中间件的真实路由。\n\n";
    $text .= inventory_table($reject);
    $text .= "\n## CRUD 生成路由\n\n";
    $crudPath = audit_repo_dir() . '/crmeb/app/adminapi/route/crud';
    $crudFiles = is_dir($crudPath) ? array_values(array_filter(scandir($crudPath) ?: [], static fn(string $name): bool => $name !== '.' && $name !== '..')) : [];
    $text .= "`route/crud.php` 真实扫描并 include `route/crud/`；本次该目录 " . (is_dir($crudPath) ? '存在' : '不存在') . '，文件数 ' . count($crudFiles) . '，枚举到 `crud/` 前缀路由 ' . count($crud) . " 项。没有为了盘点添加探针路由；A04 临时探针留待白名单决定后的测试任务。`system/crud/...` 属于系统模块业务路由，不等于 `crud/` 前缀放行例外。\n\n";
    $text .= $crud ? inventory_table($crud) : "无真实生成路由。\n";
    $text .= "\n## adminapi 全量矩阵\n\n“覆盖边界”表示公开、仅认证或 MISS，不是白名单策略；三种授权建议只针对经过 verifyAuth 的路由。已有登记并不表示当前受限管理员已有该权限。\n\n";
    $text .= inventory_table($rows);
    $text .= "\n## 异常与限制\n\n";
    $text .= $duplicates ? '枚举中出现重复方法/规则键：' . implode('、', array_map(static fn(string $key): string => '`' . $key . '`', array_unique($duplicates))) . "。完整枚举行保留，不以去重推断运行时分派优先级。\n" : "未发现重复方法/规则键；授权路由均有认证中间件，方法可精确列举。\n";
    $text .= "\n仅建立源码路由登记与安装权限记录的对应关系；未触发任何接口，也没有浏览器验收或生产验证。未登记的数量反映当前安装种子，不能推断真实环境的权限登记或使用情况。\n";

    $output = audit_run_dir() . '/evidence';
    audit_expect(is_dir($output), 'run evidence directory is missing');
    audit_expect(file_put_contents($output . '/route-permission-matrix.md', $text) !== false, 'matrix evidence write failed');
    chmod($output . '/route-permission-matrix.md', 0600);
    audit_write_json($output . '/route-inventory-metadata.json', [
        'run_id' => $runId, 'source_sha' => $sourceSha, 'generated_at' => $generated,
        'script_sha256' => hash_file('sha256', __FILE__), 'matrix_sha256' => hash('sha256', $text),
        'counts' => $counts, 'duplicates' => array_values(array_unique($duplicates)), 'routes' => $rows,
        'read_only' => ['verified' => true, 'table_count' => count($tables), 'table_summaries' => $before['tables'], 'redis_key_count' => count($before['redis']['*']), 'redis_summary_sha256' => hash('sha256', json_encode($before['redis']['*'], JSON_THROW_ON_ERROR)), 'db_diff' => [], 'cache_diff' => [], 'http_requests' => 0],
    ]);
    echo 'Route inventory counts: ' . json_encode($counts, JSON_THROW_ON_ERROR) . "\n";
    echo "Recommendation: confirm exactly get setting/admin/logout and get menuslist; no policy implemented.\n";
    echo 'Read-only database/Redis summaries unchanged; duplicate method/rule keys: ' . count(array_unique($duplicates)) . "; STOP SP-MS0a-1.\n";
    exit(0);
} catch (Throwable $error) {
    // Do not print environment values, driver exceptions, credentials or data.
    fwrite(STDERR, 'BLOCKED route inventory: ' . get_class($error) . ($error instanceof RuntimeException && !$error instanceof PDOException ? ': ' . $error->getMessage() : '') . "\n");
    exit(2);
}
