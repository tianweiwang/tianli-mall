<?php
declare(strict_types=1);

/**
 * MS0a Task 7 Step 1: read the actual ThinkPHP adminapi route registry and the
 * isolated installation permission rows. No HTTP request, controller dispatch,
 * import/sync service, database mutation or cache mutation is performed.
 *
 * Run through isolated/run.sh. Source is mounted read-only; output goes only to
 * /run/audit/evidence. SP-MS0a-1 approved six exact whitelist keys and role
 * coverage for three formerly authentication-only routes. Actual middleware
 * and approved expectations are reported separately; this script does not
 * create auth_routes configuration or modify verifyAuth.
 */
require_once __DIR__ . '/lib/harness.php';

const INVENTORY_AUTHENTICATED = [
    'get setting/admin/logout' => '用户已批准；退出仅删除本人会话，固定 GET 方法',
    'get menuslist' => '用户已批准；全局可搜索菜单元数据未按当前角色筛选，固定 GET 方法',
    'get menus' => '用户已批准；以当前管理员 roles/level 返回本人菜单与 unique',
    'get setting/menus/unique' => '用户已批准；以当前管理员 roles/level 返回本人菜单与 uniqueAuth',
    'get setting/info' => '用户已批准；返回当前登录管理员本人资料',
    'put setting/update_admin' => '用户已批准；仅本人姓名、头像及凭旧密码改密，固定 PUT 方法',
];
const INVENTORY_ROLE_ADDITIONS = ['get system/info', 'get route/import_api', 'get download/<key?>'];

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
        return ['覆盖边界', in_array($key, INVENTORY_ROLE_ADDITIONS, true)
            ? '当前实际仍仅认证；用户已批准补角色检查，完成后受限管理员因未登记被拒绝；尚不能视为已实施'
            : '实际仅认证，不进入 verifyAuth；未在批准的补角色清单，须如实记录覆盖限制'];
    }
    if (isset(INVENTORY_AUTHENTICATED[$key])) { return ['白名单', INVENTORY_AUTHENTICATED[$key]]; }
    if ($row['registered']) { return ['已登记授权', '接口登记存在；受限管理员仍须获得当前有效角色授权']; }
    if ($row['crud']) { return ['拒绝', '生成 CRUD 未登记；移除前缀放行，不能仅凭 crud/ 自动授权']; }
    return ['拒绝', '未登记且不在用户批准的六项白名单；受限管理员拒绝'];
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

function inventory_source_hashes(): array
{
    $base = audit_repo_dir() . '/';
    $files = array_merge([
        $base . 'crmeb/app/services/system/SystemRouteServices.php',
        $base . 'crmeb/app/services/system/admin/SystemRoleServices.php',
        $base . 'crmeb/app/adminapi/middleware/AdminCheckRoleMiddleware.php',
        $base . 'crmeb/app/adminapi/config/auth_routes.php',
        $base . 'crmeb/vendor/topthink/framework/src/think/route/RuleName.php',
        $base . 'crmeb/vendor/topthink/framework/src/think/route/Rule.php',
        $base . 'crmeb/vendor/topthink/framework/src/think/route/RuleItem.php',
        $base . 'crmeb/vendor/topthink/framework/src/think/route/Resource.php',
    ], glob($base . 'crmeb/app/adminapi/route/*.php') ?: [], glob($base . 'crmeb/app/adminapi/route/crud/*.php') ?: []);
    sort($files);
    $hashes = [];
    foreach ($files as $file) {
        if (is_file($file)) { $hashes[substr($file, strlen($base))] = hash_file('sha256', $file); }
    }
    return $hashes;
}

try {
    audit_guard_assert();
    audit_expect(!file_exists(audit_repo_dir() . '/crmeb/app/adminapi/route/crud/audit-ms0a.php'), 'temporary CRUD probe must be removed before route inventory');
    $pdo = audit_db();
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $spec = ['tables' => array_fill_keys($tables, null), 'redis' => ['*']];
    $before = audit_snapshot($spec);
    $sourceHashes = inventory_source_hashes();

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
    foreach (array_merge(array_keys(INVENTORY_AUTHENTICATED), INVENTORY_ROLE_ADDITIONS) as $required) {
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
    $approvedAdditions = $select(static fn(array $r): bool => in_array($r['method'] . ' ' . $r['rule'], INVENTORY_ROLE_ADDITIONS, true));
    $pendingAdditions = array_values(array_filter($approvedAdditions, static fn(array $r): bool => !$r['role']));
    $pendingRejectCount = count(array_filter($pendingAdditions, static fn(array $r): bool => !$r['registered']));
    $counts = [
        'total_routes' => count($rows), 'unique_method_rules' => count($keys),
        'public_routes' => count($public), 'authentication_only_routes' => count($authOnly),
        'authorization_routes' => count($protected), 'miss_routes' => count($miss),
        'crud_routes' => count($crud), 'registered_authorization_routes' => count($protected) - count($unregistered),
        'unregistered_authorization_routes' => count($unregistered), 'approved_whitelist_routes' => count($whitelist),
        'approved_rejected_routes_with_current_role_middleware' => count($reject),
        'pending_approved_role_additions' => count($pendingAdditions),
        'expected_authorization_routes_after_approved_changes' => count($protected) + count($pendingAdditions),
        'expected_unregistered_rejected_routes_after_approved_changes' => count($reject) + $pendingRejectCount,
        'permission_rows' => count($menus),
        'empty_api_permission_rows' => $emptyPermissionRows, 'unique_permission_keys' => count($registered),
        'duplicate_method_rules' => count(array_unique($duplicates)),
    ];
    audit_expect(count($rows) === count($public) + count($authOnly) + count($protected) + count($miss), 'route coverage counts do not reconcile');

    $after = audit_snapshot($spec);
    $differences = audit_assert_unchanged($before, $after);
    audit_expect($differences === [], 'read-only inventory changed database/cache state');
    audit_expect($sourceHashes === inventory_source_hashes(), 'route/authorization source changed during inventory');
    $generated = gmdate('Y-m-d\TH:i:s\Z');
    $sourceSha = getenv('AUDIT_SOURCE_SHA') ?: '';
    $runId = getenv('AUDIT_RUN_ID') ?: '';
    $text = "# MS0a adminapi 路由与权限登记矩阵\n\n";
    $text .= "状态：SP-MS0a-1 已由用户于 2026-10-11 批准六项白名单和三个入口补角色检查。中间件列只写本次真实枚举状态；批准政策与预期统计不能代替实现或行为测试。尚缺角色中间件的批准入口数：" . count($pendingAdditions) . "。\n\n";
    $text .= "- 执行时间（UTC）：`$generated`；RUN_ID：`$runId`；源码 HEAD：`$sourceSha`。\n";
    $text .= "- 实际枚举读取只读挂载的工作树；HEAD 只标识 Git 基点，不声称工作树与 HEAD 一致。关键路由、框架枚举 API 与授权源码的 SHA-256 已记录在脱敏元数据，盘点前后保持一致。\n";
    $text .= "- 枚举 API：`SystemRouteServices::getRouteListAll('adminapi')`，真实载入框架路由，包含嵌套 group、资源路由的 only/except 过滤与 CRUD 自动载入。\n";
    $text .= "- 权限来源：专用 `audit_auth` 中安装种子的 `eb_system_menus`，仅 `auth_type = 2`；不修改权限登记。比对谓词与现有 `verifyAuth()` 一致，未额外按 is_del、is_show 或 access 过滤。\n";
    $text .= "- 规则采用框架返回模板（例如 `<id>`、`<key?>`）；不猜测具体 URL。路由规则为 trim+lowercase；菜单规则额外删除普通空格；方法为 trim+lowercase，按方法与规则精确匹配。\n";
    $text .= "- 只读证据：全部 " . count($tables) . " 张表及 Redis 全键前后摘要一致；无 HTTP、控制器调用、导入/同步、数据修改或缓存清理。启动 guard 的无权限 CREATE DATABASE 探针被拒绝，未创建数据库。\n\n";
    $text .= "## 实际统计\n\n| 项目 | 数量 |\n|---|---|\n";
    foreach ($counts as $name => $count) { $text .= '| `' . $name . '` | ' . $count . " |\n"; }
    $text .= "\n## 用户批准：六项精确已登录白名单\n\n";
    $text .= "白名单精确绑定方法与规则；其余已登记路由要求角色授权，未登记接口拒绝。三个原仅认证入口补角色检查后也因未登记被拒绝。此拒绝策略针对受限管理员 level>0；最高管理员 level=0 的现有角色中间件豁免保持现状。\n\n";
    $text .= inventory_table($whitelist);
    $text .= "\n### 已批准本人菜单与搜索菜单的语义差异\n\n";
    $text .= "| 方法与规则 | 具体调用 | 语义 | 批准政策 |\n|---|---|---|---|\n";
    foreach (['get menus' => ['v1.setting.SystemMenus/menus', '以当前管理员 roles/level 调用 getMenusList，返回本人菜单与 unique'], 'get setting/menus/unique' => ['v1.setting.SystemMenus/unique', '以当前管理员 roles/level 调用 getMenusList，返回本人菜单与 uniqueAuth'], 'get menuslist' => ['Common/menusList', '调用 SystemMenusDao::getSearchList，未按当前角色筛选的可搜索菜单元数据']] as $key => [$target, $meaning]) {
        $candidate = array_values(array_filter($rows, static fn(array $r): bool => $r['method'] . ' ' . $r['rule'] === $key))[0];
        $text .= '| `' . $key . '` | `' . $target . '` | ' . $meaning . ' | ' . $candidate['policy'] . " |\n";
    }
    $text .= "\n`post menussearch` 会搜索菜单、配置项、配置标签，未按当前角色过滤；用户未批准将其或其他业务接口加入白名单。本人资料 `get setting/info`、`put setting/update_admin` 已批准，更新仅接收姓名、头像和凭旧密码改密。\n\n";
    $text .= "## 已批准补角色检查的三个入口：实际挂载与预期分开\n\n";
    $text .= "用户已批准给以下三项补 AdminCheckRoleMiddleware。中间件列按本次实际枚举填写；如仍为“否”，该入口当前尚不进入 verifyAuth，只有补齐后才按未登记拒绝受限管理员。get route/import_api 接收 file_path，调用 PublicController::import → SystemRouteServices::import → importData，写入/更新 eb_system_route；system/info 返回服务器环境信息；download/<key?> 下载后删除一次性缓存键。本盘点不触发上述操作。\n\n";
    $text .= inventory_table($approvedAdditions);
    $text .= "\n## 公开入口与 MISS/CORS\n\n公开入口不加入已登录白名单；本次收紧 verifyAuth 不改变其现有分组。MISS 是未匹配路由的 OPTIONS/CORS 或 404 响应，不作为受保护业务路由计数。\n\n";
    $text .= inventory_table(array_merge($public, $miss));
    $text .= "\n## 全部未登记且挂授权中间件的路由\n\n本次实际挂角色中间件的未登记路由共 " . count($unregistered) . " 项，其中六项白名单已批准；其余标为拒绝的路由拒绝受限管理员，超级管理员现有 level=0 旁路保持现状。\n\n";
    $text .= inventory_table($unregistered);
    $text .= "\n## 已批准拒绝的具体清单：按实际授权挂载\n\n本次已挂角色中间件的拒绝路由共 " . count($reject) . " 项；待补角色的未登记入口另有 " . $pendingRejectCount . " 项（上节列明），完成批准变更后预期合计 " . ($counts['expected_unregistered_rejected_routes_after_approved_changes']) . " 项。预期不包含未来新增路由；临时 CRUD 测试探针须在最终盘点前清理。\n\n";
    $text .= inventory_table($reject);
    $text .= "\n## CRUD 生成路由\n\n";
    $crudPath = audit_repo_dir() . '/crmeb/app/adminapi/route/crud';
    $crudFiles = is_dir($crudPath) ? array_values(array_filter(scandir($crudPath) ?: [], static fn(string $name): bool => $name !== '.' && $name !== '..')) : [];
    $text .= "`route/crud.php` 真实扫描并 include `route/crud/`；本次该目录 " . (is_dir($crudPath) ? '存在' : '不存在') . '，文件数 ' . count($crudFiles) . '，枚举到 `crud/` 前缀路由 ' . count($crud) . " 项。本盘点不添加探针；A04 的临时测试文件必须在最终盘点前清理。`system/crud/...` 属于系统模块业务路由，不等于 `crud/` 前缀放行例外。\n\n";
    $text .= $crud ? inventory_table($crud) : "无真实生成路由。\n";
    $text .= "\n## adminapi 全量矩阵\n\n“覆盖边界”表示实际公开、仅认证或 MISS，不是白名单策略；实际中间件不因政策批准而推定改变。已有登记并不表示当前受限管理员已有该权限。\n\n";
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
        'source_file_sha256' => $sourceHashes,
        'counts' => $counts, 'approved_authenticated' => array_keys(INVENTORY_AUTHENTICATED), 'approved_role_additions' => INVENTORY_ROLE_ADDITIONS,
        'duplicates' => array_values(array_unique($duplicates)), 'routes' => $rows,
        'read_only' => ['verified' => true, 'table_count' => count($tables), 'table_summaries' => $before['tables'], 'redis_key_count' => count($before['redis']['*']), 'redis_summary_sha256' => hash('sha256', json_encode($before['redis']['*'], JSON_THROW_ON_ERROR)), 'db_diff' => [], 'cache_diff' => [], 'http_requests' => 0],
    ]);
    echo 'Route inventory counts: ' . json_encode($counts, JSON_THROW_ON_ERROR) . "\n";
    echo 'Approved policy: six exact whitelist keys; pending role middleware additions: ' . count($pendingAdditions) . "; inventory does not mutate policy.\n";
    echo 'Read-only database/Redis summaries unchanged; duplicate method/rule keys: ' . count(array_unique($duplicates)) . ".\n";
    exit(0);
} catch (Throwable $error) {
    // Do not print environment values, driver exceptions, credentials or data.
    fwrite(STDERR, 'BLOCKED route inventory: ' . get_class($error) . ($error instanceof RuntimeException && !$error instanceof PDOException ? ': ' . $error->getMessage() : '') . "\n");
    exit(2);
}
