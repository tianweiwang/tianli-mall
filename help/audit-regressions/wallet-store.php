<?php
/** Real MySQL; writes only to a random audit_store_<hex> database, removed in finally. */
declare(strict_types=1);

require __DIR__ . '/../../crmeb/vendor/autoload.php';
require __DIR__ . '/../../crmeb/vendor/topthink/framework/src/helper.php';

function getLang($message, array $replace = []) { return $message; }
function event(...$args) { return []; }
function sys_config(string $name, $default = '') { return $default; }

function storeConfig(): array
{
    $env = parse_ini_file(__DIR__ . '/../../crmeb/.env', true, INI_SCANNER_RAW);
    $cfg = array_change_key_case($env['DATABASE'] ?? $env['database'], CASE_LOWER);
    if ($path = getenv('AUDIT_DB_PASSWORD_FILE')) {
        $password = file_get_contents($path);
        if ($password === false) throw new RuntimeException('Cannot read audit database password file');
        $cfg['username'] = 'root';
        $cfg['password'] = rtrim($password, "\r\n");
    }
    return $cfg;
}

function storePdo(string $database = ''): PDO
{
    $cfg = storeConfig();
    return new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $cfg['hostport'] . ';charset=utf8mb4'
        . ($database ? ';dbname=' . $database : ''), $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function storeGuard(string $database): void
{
    if (!preg_match('/^audit_store_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Unsafe fixture database');
}

class StoreAuditOrderIds extends app\services\order\StoreOrderCreateServices
{
    public function getNewOrderId(string $prefix = 'wx') { return $prefix . bin2hex(random_bytes(8)); }
}

class StoreAuditPaid extends app\services\order\StoreOrderSuccessServices
{
    public function paySuccess(array $orderInfo, string $paytype = 'weixin', array $other = [])
    {
        return (bool)$this->update($orderInfo['id'], ['paid' => 1, 'pay_type' => $paytype]);
    }
}

class StoreAuditLedgerFailure extends app\services\user\UserMoneyServices
{
    public function income(string $type, int $uid, $number, $balance, $linkId, string $mark = '')
    {
        if (getenv('AUDIT_STORE_LEDGER_FAILURE') === 'false') return false;
        parent::income($type, $uid, $number, $balance, $linkId, $mark);
        throw new RuntimeException('Injected ledger failure after insert');
    }
}

function storeBoot(string $database): void
{
    storeGuard($database);
    $cfg = storeConfig();
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
    // Widen the historical read -> write race without delaying locked reads.
    $db->listen(function ($sql) {
        if (preg_match('/^SELECT .*FROM `eb_user`/i', $sql) && stripos($sql, 'FOR UPDATE') === false) usleep(200000);
    });
    $app->instance(app\services\order\StoreOrderCreateServices::class, new StoreAuditOrderIds(new app\dao\order\StoreOrderDao()));
    $app->instance(app\services\order\StoreOrderSuccessServices::class, new StoreAuditPaid(new app\dao\order\StoreOrderDao()));
    if (getenv('AUDIT_STORE_LEDGER_FAILURE')) {
        $app->instance(app\services\user\UserMoneyServices::class, new StoreAuditLedgerFailure(new app\dao\user\UserMoneyDao()));
    }
}

function storeAction(string $database, string $action): array
{
    storeBoot($database);
    try {
        if ($action === 'pay') {
            $order = think\facade\Db::name('store_order')->where('id', 1)->find();
            $result = app()->make(app\services\pay\YuePayServices::class)->yueOrderPay($order, 1);
            if ($result['status'] !== true) throw new RuntimeException('Payment failed');
        } else {
            $number = $action === 'clamp' ? '180.00' : ($action === 'zero' ? '0.00' : '20.00');
            app()->make(app\services\admin\StoreManageServices::class)->userUpdate(1,
                ['type' => 0, 'number' => $number, 'status' => $action === 'add' ? 1 : 0]);
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'kind' => get_class($e)];
    }
}

function storeWorkers(string $database, array $actions): array
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
            throw new RuntimeException('Worker failed; output omitted');
        }
        $results[] = $decoded;
    }
    return $results;
}

function storeSeed(PDO $pdo): void
{
    foreach (['user_money', 'user_recharge', 'store_order', 'user'] as $table) $pdo->exec('DELETE FROM eb_' . $table);
    $pdo->exec("INSERT INTO eb_user(uid,account,pwd,real_name,now_money) VALUES(1,'audit','audit','audit',100)");
    $pdo->exec("INSERT INTO eb_store_order(id,uid,order_id,pay_price,paid) VALUES(1,1,'audit',40,0)");
}

function storeState(PDO $pdo): array
{
    return [
        'balance' => $pdo->query('SELECT now_money FROM eb_user WHERE uid=1')->fetchColumn(),
        'ledger' => $pdo->query('SELECT pm,number,balance FROM eb_user_money ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'recharges' => (int)$pdo->query('SELECT COUNT(*) FROM eb_user_recharge')->fetchColumn(),
        'paid' => (int)$pdo->query('SELECT paid FROM eb_store_order WHERE id=1')->fetchColumn(),
    ];
}

function storeLedgerMatches(array $state): bool
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
    echo json_encode(storeAction($argv[2], $argv[3]));
    exit;
}

$database = 'audit_store_' . bin2hex(random_bytes(8));
storeGuard($database);
$admin = storePdo();
$created = false;
$failures = 0;
$passes = 0;
function storeCheck(bool $ok, string $name, array $evidence): void
{
    global $failures, $passes;
    if ($ok) $passes++; else $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ' ' . json_encode($evidence) . "\n";
}
try {
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $created = true;
    $cfg = storeConfig();
    $source = str_replace('`', '``', $cfg['database']);
    foreach (['user', 'user_money', 'user_recharge', 'store_order'] as $table) {
        $sourceTable = str_replace('`', '``', ($cfg['prefix'] ?? 'eb_') . $table);
        $admin->exec("CREATE TABLE `$database`.`eb_$table` LIKE `$source`.`$sourceTable`");
    }
    $pdo = storePdo($database);
    foreach ([
        [['add', 'pay'], '80.00', 1], [['sub', 'pay'], '40.00', 0],
        [['add', 'add'], '140.00', 2], [['sub', 'sub'], '60.00', 0],
        [['clamp'], '0.00', 0], [['zero'], '100.00', 0],
    ] as [$actions, $expected, $recharges]) {
        storeSeed($pdo);
        $results = storeWorkers($database, $actions);
        $state = storeState($pdo);
        storeCheck(count(array_filter($results, fn($r) => $r['ok'])) === count($actions)
            && $state['balance'] === $expected && $state['recharges'] === $recharges
            && $state['paid'] === (int)in_array('pay', $actions, true)
            && count($state['ledger']) === count($actions) && storeLedgerMatches($state),
            implode('+', $actions), ['results' => $results, 'state' => $state]);
    }
    foreach (['add', 'sub'] as $action) {
        foreach (['false', 'after-insert'] as $failure) {
            storeSeed($pdo);
            putenv('AUDIT_STORE_LEDGER_FAILURE=' . $failure);
            $result = storeWorkers($database, [$action])[0];
            putenv('AUDIT_STORE_LEDGER_FAILURE');
            $state = storeState($pdo);
            storeCheck(!$result['ok'] && $state['balance'] === '100.00' && !$state['ledger'] && !$state['recharges'],
                $action . ' rolls back on ledger ' . $failure, $state);
        }
        storeSeed($pdo);
        $pdo->exec("CREATE TRIGGER audit_store_fail_balance BEFORE UPDATE ON eb_user FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit injected failure'");
        $result = storeWorkers($database, [$action])[0];
        $state = storeState($pdo);
        storeCheck(!$result['ok'] && $state['balance'] === '100.00' && !$state['ledger'] && !$state['recharges'],
            $action . ' rolls back ledger and recharge on balance failure', $state);
        $pdo->exec('DROP TRIGGER audit_store_fail_balance');
    }
} finally {
    putenv('AUDIT_STORE_LEDGER_FAILURE');
    if ($created) { storeGuard($database); $admin->exec("DROP DATABASE `$database`"); }
}
echo "Wallet store regressions: {$passes} passed, {$failures} failed; fixture database removed\n";
exit($failures ? 1 : 0);
