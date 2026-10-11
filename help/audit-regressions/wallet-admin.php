<?php
/** Real MySQL; only audit_wallet_<random> contains fixture writes. No events/network. */
declare(strict_types=1);

require __DIR__ . '/../../crmeb/vendor/autoload.php';
require __DIR__ . '/../../crmeb/vendor/topthink/framework/src/helper.php';

function getLang($message, array $replace = []) { return $message; }
function event(...$args) { return []; }
function sys_config(string $name, $default = '') { return $name === 'reward_money' ? '10.00' : $default; }

function walletConfig(): array
{
    $env = parse_ini_file(__DIR__ . '/../../crmeb/.env', true, INI_SCANNER_RAW);
    $cfg = array_change_key_case($env['DATABASE'] ?? $env['database'], CASE_LOWER);
    if ($path = getenv('AUDIT_DB_PASSWORD_FILE')) {
        $cfg['username'] = 'root';
        $cfg['password'] = rtrim(file_get_contents($path), "\r\n");
    }
    return $cfg;
}

function walletPdo(string $database = ''): PDO
{
    $cfg = walletConfig();
    return new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $cfg['hostport'] . ';charset=utf8mb4'
        . ($database ? ';dbname=' . $database : ''), $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function walletGuard(string $database): void
{
    if (!preg_match('/^audit_wallet_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Unsafe fixture database');
}

class WalletOrderIds extends app\services\order\StoreOrderCreateServices
{
    public function getNewOrderId(string $prefix = 'wx') { return $prefix . bin2hex(random_bytes(8)); }
}

class WalletLocalPaid extends app\services\order\StoreOrderSuccessServices
{
    public function paySuccess(array $orderInfo, string $paytype = 'weixin', array $other = [])
    {
        return (bool)$this->update($orderInfo['id'], ['paid' => 1, 'pay_type' => $paytype]);
    }
}

function walletBoot(string $database): void
{
    walletGuard($database);
    $cfg = walletConfig();
    $app = new think\App(realpath(__DIR__ . '/../../crmeb') . '/');
    $db = new think\DbManager();
    $db->setConfig(['default' => 'mysql', 'auto_timestamp' => false, 'connections' => ['mysql' => [
        'type' => crmeb\compat\think\orm\db\connector\Mysql::class,
        'hostname' => $cfg['hostname'], 'hostport' => $cfg['hostport'],
        'username' => $cfg['username'], 'password' => $cfg['password'], 'database' => $database,
        'prefix' => 'eb_', 'charset' => 'utf8mb4', 'debug' => true,
    ]]]);
    $app->instance('think\Db', $db);
    $app->instance('think\DbManager', $db);
    think\Model::setDb($db);
    think\Model::setInvoker([$app, 'invoke']);
    $db->listen(function ($sql) {
        if (preg_match('/^SELECT .*FROM `eb_user`/i', $sql) && stripos($sql, 'FOR UPDATE') === false) usleep(200000);
    });
    $app->instance(app\services\order\StoreOrderCreateServices::class,
        new WalletOrderIds(new app\dao\order\StoreOrderDao()));
    $app->instance(app\services\order\StoreOrderSuccessServices::class,
        new WalletLocalPaid(new app\dao\order\StoreOrderDao()));
}

function walletAction(string $database, string $action): array
{
    walletBoot($database);
    $users = app()->make(app\services\user\UserServices::class);
    try {
        switch ($action) {
            case 'add': $users->addNowMoney(1, '100.00', '20.00'); break;
            case 'cut': $users->cutNowMoney(1, '100.00', '80.00'); break;
            case 'reward': $users->rewardNewUser(1); break;
            case 'admin_add':
            case 'admin_sub':
                $users->updateInfo(1, ['money_status' => $action === 'admin_add' ? 1 : 2,
                    'money' => $action === 'admin_add' ? '20.00' : '80.00',
                    'integration_status' => 0, 'integration' => 0, 'is_other' => 1, 'mark' => 'audit']);
                break;
            case 'pay':
                $order = think\facade\Db::name('store_order')->where('id', 1)->find();
                $result = app()->make(app\services\pay\YuePayServices::class)->yueOrderPay($order, 1);
                if ($result['status'] !== true) throw new RuntimeException('Payment failed');
                break;
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function walletWorkers(string $database, array $actions): array
{
    $running = [];
    foreach ($actions as $action) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, $action],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Worker start failed');
        $running[] = [$process, $pipes];
    }
    foreach ($running as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_array($decoded = json_decode($out, true))) {
            throw new RuntimeException('Worker failed: ' . $err);
        }
        $results[] = $decoded;
    }
    return $results;
}

function walletSeed(PDO $pdo): void
{
    foreach (['user_money', 'user_recharge', 'store_order', 'user'] as $table) $pdo->exec('DELETE FROM eb_' . $table);
    $pdo->exec("INSERT INTO eb_user(uid,account,pwd,real_name,now_money) VALUES(1,'audit','audit','audit',100)");
    $pdo->exec("INSERT INTO eb_store_order(id,uid,order_id,pay_price,paid) VALUES(1,1,'audit',80,0)");
}

function walletState(PDO $pdo): array
{
    return [
        'balance' => $pdo->query('SELECT now_money FROM eb_user WHERE uid=1')->fetchColumn(),
        'ledger' => $pdo->query('SELECT pm,number,balance FROM eb_user_money ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'recharges' => (int)$pdo->query('SELECT COUNT(*) FROM eb_user_recharge')->fetchColumn(),
    ];
}

function walletLedgerMatches(array $state): bool
{
    $balance = '100.00';
    foreach ($state['ledger'] as $row) {
        $balance = (int)$row['pm'] === 1 ? bcadd($balance, (string)$row['number'], 2) : bcsub($balance, (string)$row['number'], 2);
        if (bccomp($balance, (string)$row['balance'], 2) !== 0) return false;
    }
    return bccomp($balance, (string)$state['balance'], 2) === 0;
}

if (($argv[1] ?? '') === 'worker') {
    fgets(STDIN);
    echo json_encode(walletAction($argv[2], $argv[3]), JSON_UNESCAPED_UNICODE);
    exit;
}

$database = 'audit_wallet_' . bin2hex(random_bytes(8));
walletGuard($database);
$admin = walletPdo();
$created = false;
$failures = 0;
function walletCheck(bool $ok, string $name, array $evidence): void
{
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ' ' . json_encode($evidence, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
try {
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $created = true;
    $cfg = walletConfig();
    $source = str_replace('`', '``', $cfg['database']);
    foreach (['user', 'user_money', 'user_recharge', 'store_order'] as $table) {
        $sourceTable = str_replace('`', '``', ($cfg['prefix'] ?? 'eb_') . $table);
        $admin->exec("CREATE TABLE `$database`.`eb_$table` LIKE `$source`.`$sourceTable`");
    }
    $pdo = walletPdo($database);
    foreach ([
        [['add', 'add'], '140.00', false],
        [['cut', 'cut'], '0.00', false],
        [['admin_add', 'admin_add'], '140.00', true],
        [['admin_sub', 'admin_sub'], '0.00', true],
        [['reward', 'reward'], '120.00', true],
        [['admin_add', 'pay'], '40.00', true],
        [['reward', 'pay'], '30.00', true],
    ] as [$actions, $expected, $ledger]) {
        walletSeed($pdo);
        $results = walletWorkers($database, $actions);
        $state = walletState($pdo);
        walletCheck(count(array_filter($results, fn($r) => $r['ok'])) === 2
            && $state['balance'] === $expected && (!$ledger || walletLedgerMatches($state)),
            implode('+', $actions), ['results' => $results, 'state' => $state]);
    }
    foreach (['admin_add', 'admin_sub', 'reward'] as $action) {
        walletSeed($pdo);
        $pdo->exec("CREATE TRIGGER audit_fail_balance BEFORE UPDATE ON eb_user FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit injected balance failure'");
        $result = walletWorkers($database, [$action])[0];
        $state = walletState($pdo);
        walletCheck(!$result['ok'] && $state['balance'] === '100.00' && !$state['ledger'] && !$state['recharges'],
            $action . ' rolls back ledger and recharge on update failure', $state);
        $pdo->exec('DROP TRIGGER audit_fail_balance');
    }
} finally {
    if ($created) { walletGuard($database); $admin->exec("DROP DATABASE `$database`"); }
}
echo 'Wallet admin regressions: ' . $failures . " failed; fixture database removed\n";
exit($failures ? 1 : 0);
