<?php
declare(strict_types=1);

function ms0a_fixtures(PDO $migrator, string $runDir): array
{
    $menu = $migrator->query("SELECT id FROM eb_system_menus WHERE auth_type=2 AND LOWER(api_url)='product/product/rule' AND LOWER(methods)='get' ORDER BY id LIMIT 1")->fetchColumn();
    $outRoute = $migrator->query("SELECT id FROM eb_system_route WHERE app_name='outapi' AND LOWER(path)='category/list' AND LOWER(method)='get' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$menu || !$outRoute) { throw new RuntimeException('required readonly route seed missing; no replacement seed created'); }
    $insert = function (string $table, array $data) use ($migrator): void {
        $columns = array_keys($data);
        $statement = $migrator->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($data));
    };
    $insert('eb_system_role', ['id' => 101, 'role_name' => 'R-limited', 'rules' => (string)$menu, 'level' => 1, 'status' => 1]);
    $accounts = [];
    foreach (['admin_super' => 101, 'admin_limited' => 102, 'admin_disabled' => 103, 'admin_deleted' => 104] as $key => $id) {
        $password = bin2hex(random_bytes(12));
        $accounts[$key] = ['id' => $id, 'account' => 'audit_' . $key, 'password' => $password];
        $insert('eb_system_admin', ['id' => $id, 'account' => $accounts[$key]['account'], 'pwd' => password_hash($password, PASSWORD_BCRYPT), 'roles' => '101', 'level' => $id === 101 ? 0 : 1, 'status' => $id === 103 ? 0 : 1, 'is_del' => $id === 104 ? 1 : 0, 'real_name' => 'AUDIT']);
    }
    foreach (['out_101' => 101, 'out_102' => 102, 'out_disabled' => 105] as $key => $id) {
        $password = bin2hex(random_bytes(12));
        $accounts[$key] = ['id' => $id, 'account' => 'auditOut' . $id, 'password' => $password];
        // Required no-default installation columns receive neutral values, never schema changes.
        $insert('eb_out_account', ['id' => $id, 'appid' => $accounts[$key]['account'], 'appsecret' => password_hash($password, PASSWORD_BCRYPT), 'rules' => (string)$outRoute, 'status' => $id === 105 ? 0 : 1, 'is_del' => 0, 'push_account' => '', 'push_password' => '', 'push_token_url' => '', 'user_update_push' => '']);
    }
    $password = bin2hex(random_bytes(12));
    $accounts['kefu_101'] = ['id' => 101, 'account' => 'auditKefu101', 'password' => $password];
    $insert('eb_store_service', ['id' => 101, 'uid' => 101, 'account' => 'auditKefu101', 'password' => password_hash($password, PASSWORD_BCRYPT), 'status' => 1, 'nickname' => 'AUDIT']);
    foreach ([101, 102] as $id) {
        $password = bin2hex(random_bytes(12));
        $accounts['user_' . $id] = ['id' => $id, 'account' => 'audit_user_' . $id, 'password' => $password];
        $insert('eb_user', ['uid' => $id, 'account' => 'audit_user_' . $id, 'pwd' => md5($password), 'nickname' => 'AUDIT', 'status' => 1, 'is_del' => 0, 'user_type' => 'h5', 'last_time' => time(), 'add_time' => time()]);
    }
    $accounts['_permissions'] = ['admin_menu' => (int)$menu, 'out_route' => (int)$outRoute, 'role' => 101];
    file_put_contents($runDir . '/accounts.json', json_encode($accounts, JSON_THROW_ON_ERROR));
    chmod($runDir . '/accounts.json', 0600);
    return $accounts;
}
