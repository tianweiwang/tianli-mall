<?php
/**
 * Isolated MySQL regression probe for balance payments and coupon checkout.
 * Run: AUDIT_DB_PASSWORD_FILE=/path/to/root-secret php help/audit-regressions/commerce.php
 * Creates only a random audit_commerce_<hex> database and drops it in finally.
 * Existing tables are read only for CREATE TABLE LIKE; no customer rows are copied.
 * Payment-success notifications/queues are replaced with local database-only stubs.
 */
declare(strict_types=1);

use app\dao\activity\coupon\StoreCouponUserDao;
use app\dao\order\StoreOrderDao;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\order\OtherOrderServices;
use app\services\order\StoreOrderComputedServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderSuccessServices;
use app\services\pay\YuePayServices;
use app\services\user\UserMoneyServices;
use think\facade\Db;

const PROJECT_ROOT = __DIR__ . '/../..';
require PROJECT_ROOT . '/crmeb/vendor/autoload.php';
require PROJECT_ROOT . '/crmeb/vendor/topthink/framework/src/helper.php';

// Avoid loading application helpers that consult the real configuration/cache.
function getLang($message, array $replace = []) { return $message; }
function sys_config(string $name, $default = '') { return $default; }

function connectionConfig(): array
{
    $env = parse_ini_file(PROJECT_ROOT . '/crmeb/.env', true, INI_SCANNER_RAW);
    $db = array_change_key_case($env['DATABASE'], CASE_LOWER);
    if ($file = getenv('AUDIT_DB_PASSWORD_FILE')) {
        $password = file_get_contents($file);
        if ($password === false) throw new RuntimeException('Cannot read the test database credential file');
        $db['username'] = 'root';
        $db['password'] = trim($password);
    }
    return $db;
}

function pdo(array $cfg, string $database = ''): PDO
{
    $dsn = 'mysql:host=' . $cfg['hostname'] . ';port=' . $cfg['hostport'] . ';charset=utf8mb4';
    if ($database !== '') $dsn .= ';dbname=' . $database;
    return new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function guardedDatabase(string $name): void
{
    if (!preg_match('/^audit_commerce_[a-f0-9]{16}$/D', $name)) {
        throw new RuntimeException('Refusing to operate outside the isolated audit database');
    }
}

function boot(string $database): void
{
    guardedDatabase($database);
    $cfg = connectionConfig();
    $app = new think\App(realpath(PROJECT_ROOT . '/crmeb') . '/');
    $manager = new think\DbManager();
    $manager->setConfig([
        'default' => 'mysql', 'auto_timestamp' => false,
        'connections' => ['mysql' => [
            'type' => crmeb\compat\think\orm\db\connector\Mysql::class,
            'hostname' => $cfg['hostname'], 'hostport' => $cfg['hostport'],
            'username' => $cfg['username'], 'password' => $cfg['password'],
            'database' => $database, 'prefix' => 'eb_', 'charset' => 'utf8mb4',
            'debug' => true, 'fields_strict' => true,
        ]],
    ]);
    $app->instance('think\DbManager', $manager);
    $app->instance('think\Db', $manager);
    think\Model::setDb($manager);
    think\Model::setInvoker([$app, 'invoke']);
    // Force overlap for the historical unlocked SELECT -> absolute UPDATE race.
    $manager->listen(function ($sql) {
        if (preg_match('/^SELECT .*FROM `eb_(user|luck_lottery_record)`/i', $sql) && stripos($sql, 'FOR UPDATE') === false) {
            usleep(180000);
        }
    });
    $app->instance(StoreOrderSuccessServices::class, new LocalProductPayment(new StoreOrderDao()));
    $app->instance(OtherOrderServices::class, new LocalMemberPayment(new app\dao\order\OtherOrderDao()));
}

class LocalProductPayment extends StoreOrderSuccessServices
{
    public function paySuccess(array $orderInfo, string $paytype = 'weixin', array $other = [])
    {
        if (getenv('AUDIT_FAIL_PAYMENT') === '1') throw new RuntimeException('Injected payment failure');
        return (bool)$this->update($orderInfo['id'], ['paid' => 1, 'pay_type' => $paytype]);
    }
}

class LocalMemberPayment extends OtherOrderServices
{
    public function paySuccess(array $orderInfo, string $paytype = 'weixin', array $other = [])
    {
        if (getenv('AUDIT_FAIL_PAYMENT') === '1') throw new RuntimeException('Injected payment failure');
        return (bool)$this->update($orderInfo['id'], ['paid' => 1, 'pay_type' => $paytype]);
    }
}

class FailingLedger extends UserMoneyServices
{
    public function income(string $type, int $uid, $number, $balance, $linkId, string $mark = '')
    {
        throw new RuntimeException('Injected ledger failure');
    }
}

function payment(string $database, string $table, int $id): array
{
    boot($database);
    if (getenv('AUDIT_DELAY_PAYMENT') === '1') usleep(60000);
    if (getenv('AUDIT_FAIL_LEDGER') === '1') {
        app()->instance(UserMoneyServices::class, new FailingLedger(new app\dao\user\UserMoneyDao()));
    }
    // Snapshot is deliberately taken before the transaction, as in the controller.
    $order = Db::name($table)->where('id', $id)->find();
    try {
        return app()->make(YuePayServices::class)->yueOrderPay($order, 1);
    } catch (Throwable $e) {
        return ['status' => false, 'message' => $e->getMessage()];
    }
}

function walletAction(string $database, string $action): array
{
    boot($database);
    app()->instance(app\services\statistic\CapitalFlowServices::class, new class {
        public function setFlow($order, $type = '') { return true; }
    });
    app()->instance(crmeb\services\pay\Pay::class, new class {
        public function refund($tradeNo, $data) { return true; }
    });
    app()->instance(StoreOrderCreateServices::class, new CheckoutWithStockFailure(new StoreOrderDao()));
    app()->instance(app\services\user\UserBrokerageServices::class, new class {
        public function getUserFrozenPrice($uid) { return 0; }
        public function income(...$args) { return true; }
    });
    if (getenv('AUDIT_FAIL_LEDGER') === '1') {
        app()->instance(UserMoneyServices::class, new FailingLedger(new app\dao\user\UserMoneyDao()));
    }
    try {
        $recharge = app()->make(app\services\user\UserRechargeServices::class);
        switch ($action) {
            case 'recharge': $recharge->rechargeSuccess('audit-recharge', ['pay_type' => 'weixin']); break;
            case 'refund': app()->make(app\services\order\StoreOrderRefundServices::class)->yueRefund(['uid' => 1, 'id' => 900], ['refund_price' => '30.00']); break;
            case 'recharge_refund': $recharge->refund_update(1, '0'); break;
            case 'commission': $recharge->importNowMoney(1, '30.00'); break;
            case 'bc_credit': app()->make(app\services\user\UserServices::class)->bcInc(1, 'now_money', '30.00', 'uid'); break;
            case 'lottery_spend':
                $user = app()->make(app\services\user\UserServices::class)->getUserInfo(1)->toArray();
                app()->make(app\services\activity\lottery\LuckLotteryServices::class)->lotteryFactor(1, $user, ['id' => 1, 'factor' => 2, 'factor_num' => '30.00']);
                break;
            case 'lottery_prize':
                app()->make(app\services\activity\lottery\LuckLotteryRecordServices::class)->receivePrize(1, 1);
                break;
            case 'lottery_parent':
                Db::transaction(function () {
                    $user = app()->make(app\services\user\UserServices::class)->getUserInfo(1)->toArray();
                    app()->make(app\services\activity\lottery\LuckLotteryServices::class)->lotteryFactor(1, $user, ['id' => 1, 'factor' => 2, 'factor_num' => '30.00']);
                    app()->make(app\services\activity\lottery\LuckLotteryRecordServices::class)->receivePrize(1, 1);
                });
                break;
            default: throw new RuntimeException('Unknown wallet action');
        }
        return ['status' => true];
    } catch (Throwable $e) {
        return ['status' => false, 'message' => $e->getMessage()];
    }
}

class CheckoutCalculation extends StoreOrderComputedServices
{
    public function computedOrder(int $uid, array $userInfo, array $cartGroup, int $addressId, string $payType, bool $useIntegral = false, int $couponId = 0, bool $isCreate = false, int $shippingType = 1, int $is_gift = 0)
    {
        [$price, $coupon] = $this->useCouponId($couponId, $uid, $cartGroup['cartInfo'], 40, $isCreate);
        // Only unrelated postage/integral calculations are replaced.
        return ['coupon_price' => $coupon, 'pay_price' => $price, 'pay_postage' => 0,
            'deduction_price' => 0, 'gift_price' => 0, 'usedIntegral' => 0];
    }
}

class CheckoutWithStockFailure extends StoreOrderCreateServices
{
    public static bool $failStock = false;
    public function getNewOrderId(string $prefix = 'wx') { return 'audit' . bin2hex(random_bytes(8)); }
    public function decGoodsStock(array $cartInfo, int $combinationId, int $seckillId, int $bargainId, int $advanceId)
    {
        if (self::$failStock) throw new RuntimeException('Injected stock failure');
    }
}

function checkout(string $database, string $mode): array
{
    boot($database);
    CheckoutWithStockFailure::$failStock = $mode === 'stock_failure';
    app()->instance(StoreOrderComputedServices::class, new CheckoutCalculation(new StoreOrderDao()));
    app()->instance(app\services\order\StoreOrderServices::class, new class {
        public function getCacheOrderInfo($uid, $key) {
            return ['cartInfo' => [['id' => 1, 'truePrice' => '40.00', 'cart_num' => 1,
                'seckill_id' => 0, 'bargain_id' => 0, 'combination_id' => 0, 'advance_id' => 0,
                'productInfo' => ['id' => 1, 'virtual_type' => 0]]],
                'priceGroup' => ['totalPrice' => 40, 'storePostage' => 0, 'costPrice' => 10]];
        }
    });
    app()->instance(app\services\user\UserAddressServices::class, new class {
        public function getOne($where) {
            return new class { public function toArray() {
                return ['real_name' => 'audit', 'phone' => '000', 'province' => '', 'city' => '', 'district' => '', 'detail' => 'audit'];
            }};
        }
    });
    app()->instance(app\services\order\StoreOrderCartInfoServices::class, new class {
        public function setCartInfo($id, $uid, $cartInfo) { return true; }
        public function getCarIdByProductTitle($id) { return 'audit'; }
    });
    $user = ['uid' => 1, 'user_type' => 'h5', 'division_id' => 0, 'agent_id' => 0, 'staff_id' => 0];
    try {
        $service = new CheckoutWithStockFailure(new StoreOrderDao());
        $service->createOrder(1, 'audit' . bin2hex(random_bytes(8)), $user, $mode === 'address_failure' ? 0 : 1, 'yue', false, 1);
        return ['status' => true];
    } catch (Throwable $e) {
        return ['status' => false, 'message' => $e->getMessage()];
    }
}

function workers(string $database, array $jobs): array
{
    $running = [];
    foreach ($jobs as [$table, $id]) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, $table, (string)$id],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start worker');
        $running[] = [$process, $pipes];
    }
    foreach ($running as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode($out, true);
        if ($code !== 0 || !is_array($result)) throw new RuntimeException('Worker failed: ' . $err);
        $results[] = $result;
    }
    return $results;
}

function seed(PDO $db, string $balance, array $prices, bool $member = false): void
{
    foreach (['user_money', 'store_order', 'other_order', 'user'] as $table) $db->exec('DELETE FROM eb_' . $table);
    $q = $db->prepare("INSERT INTO eb_user(uid,account,pwd,real_name,now_money) VALUES(1,'audit','audit','audit',?)");
    $q->execute([$balance]);
    foreach ($prices as $i => $price) {
        $table = $member && $i === 1 ? 'other_order' : 'store_order';
        $q = $db->prepare("INSERT INTO eb_$table(id,uid,order_id,pay_price,paid" . ($table === 'other_order' ? ',member_type' : ',`unique`') . ") VALUES(?,1,?,?,0" . ($table === 'other_order' ? ',1' : ',?') . ')');
        $values = [$i + 1, 'audit' . ($i + 1), $price];
        if ($table !== 'other_order') $values[] = 'audit-key-' . ($i + 1);
        $q->execute($values);
    }
}

function state(PDO $db): array
{
    return [
        'balance' => $db->query('SELECT now_money FROM eb_user WHERE uid=1')->fetchColumn(),
        'paid' => (int)$db->query('SELECT (SELECT COUNT(*) FROM eb_store_order WHERE paid=1)+(SELECT COUNT(*) FROM eb_other_order WHERE paid=1)')->fetchColumn(),
        'ledger' => $db->query('SELECT number,balance,pm FROM eb_user_money ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

if (($argv[1] ?? '') === 'worker') {
    fgets(STDIN);
    if ($argv[3] === 'checkout') $result = checkout($argv[2], ['success', 'address_failure', 'stock_failure'][(int)$argv[4]]);
    elseif (str_starts_with($argv[3], 'wallet:')) $result = walletAction($argv[2], substr($argv[3], 7));
    else $result = payment($argv[2], $argv[3], (int)$argv[4]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$database = 'audit_commerce_' . bin2hex(random_bytes(8));
guardedDatabase($database);
$admin = pdo(connectionConfig());
$created = false;
$failures = 0;
function check(bool $condition, string $name, array $evidence = []): void
{
    global $failures;
    if (!$condition) $failures++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . ' ' . json_encode($evidence, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

try {
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $created = true;
    $cfg = connectionConfig();
    foreach (['store_order', 'other_order', 'user', 'user_money', 'store_coupon_user', 'store_coupon_issue', 'user_recharge', 'user_extract', 'luck_prize', 'luck_lottery_record'] as $table) {
        $source = str_replace('`', '``', $cfg['database']);
        $sourceTable = str_replace('`', '``', ($cfg['prefix'] ?? 'eb_') . $table);
        $admin->exec("CREATE TABLE `$database`.`eb_$table` LIKE `$source`.`$sourceTable`");
    }
    $db = pdo($cfg, $database);
    // All seed data lives in the random test database; tolerate legacy NOT NULL defaults.
    $db->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");

    seed($db, '100.00', ['80.00', '80.00']);
    $results = workers($database, [['store_order', 1], ['store_order', 2]]);
    $s = state($db);
    check($s['balance'] === '20.00' && $s['paid'] === 1 && count($s['ledger']) === 1, 'overlapping payments reject insufficient combined balance', $s);

    seed($db, '100.00', ['40.00', '40.00']);
    workers($database, [['store_order', 1], ['store_order', 2]]);
    $s = state($db);
    check($s['balance'] === '20.00' && $s['paid'] === 2 && array_column($s['ledger'], 'balance') === ['60.00', '20.00'], 'two successful payments retain both debits and ledger balances', $s);

    seed($db, '100.00', ['40.00']);
    workers($database, [['store_order', 1], ['store_order', 1]]);
    $s = state($db);
    check($s['balance'] === '60.00' && $s['paid'] === 1 && count($s['ledger']) === 1, 'duplicate payment debits once', $s);

    seed($db, '100.00', ['80.00', '80.00'], true);
    workers($database, [['store_order', 1], ['other_order', 2]]);
    $s = state($db);
    check($s['balance'] === '20.00' && $s['paid'] === 1 && count($s['ledger']) === 1, 'product and member payments share the account lock', $s);

    foreach (['AUDIT_FAIL_PAYMENT', 'AUDIT_FAIL_LEDGER'] as $failure) {
        seed($db, '100.00', ['40.00']);
        putenv($failure . '=1');
        $result = workers($database, [['store_order', 1]]);
        putenv($failure);
        $s = state($db);
        check($s['balance'] === '100.00' && $s['paid'] === 0 && !$s['ledger'] && str_starts_with($result[0]['message'] ?? '', 'Injected '), $failure . ' rolls back balance, ledger and paid state', $s);
    }

    foreach (['recharge', 'refund', 'recharge_refund', 'commission', 'bc_credit'] as $action) {
        seed($db, '100.00', ['40.00']);
        $db->exec('UPDATE eb_user SET brokerage_price=100 WHERE uid=1');
        $db->exec('DELETE FROM eb_user_recharge');
        $db->exec('DELETE FROM eb_user_extract');
        $db->exec("INSERT INTO eb_user_recharge(id,uid,order_id,price,give_price,paid,recharge_type) VALUES(1,1,'audit-recharge',30,0," . ($action === 'recharge_refund' ? 1 : 0) . ",'weixin')");
        $results = workers($database, [['store_order', 1], ['wallet:' . $action, 0]]);
        $s = state($db);
        $expected = $action === 'recharge_refund' ? '30.00' : '90.00';
        $running = '100.00';
        $ledgerCorrect = true;
        foreach ($s['ledger'] as $entry) {
            $running = $entry['pm'] ? bcadd($running, $entry['number'], 2) : bcsub($running, $entry['number'], 2);
            $ledgerCorrect = $ledgerCorrect && $running === $entry['balance'];
        }
        // bc_credit is the DAO primitive: its caller owns the separate ledger.
        if ($action === 'bc_credit') $ledgerCorrect = count($s['ledger']) === 1;
        check($s['balance'] === $expected && $s['paid'] === 1 && $ledgerCorrect && count(array_filter($results, fn($r) => $r['status'] === true)) === 2,
            'payment concurrent with ' . $action . ' preserves balance and ledger', ['state' => $s, 'results' => $results]);
    }

    foreach (['recharge', 'refund', 'recharge_refund', 'commission'] as $action) {
        seed($db, '100.00', []);
        $db->exec('UPDATE eb_user SET brokerage_price=100 WHERE uid=1');
        $db->exec('DELETE FROM eb_user_recharge');
        $db->exec('DELETE FROM eb_user_extract');
        $paid = $action === 'recharge_refund' ? 1 : 0;
        $db->exec("INSERT INTO eb_user_recharge(id,uid,order_id,price,give_price,paid,recharge_type) VALUES(1,1,'audit-recharge',30,0,$paid,'weixin')");
        putenv('AUDIT_FAIL_LEDGER=1');
        $result = workers($database, [['wallet:' . $action, 0]]);
        putenv('AUDIT_FAIL_LEDGER');
        $s = state($db);
        $recharges = $db->query('SELECT paid,refund_price FROM eb_user_recharge')->fetchAll(PDO::FETCH_ASSOC);
        $commission = $db->query('SELECT brokerage_price FROM eb_user WHERE uid=1')->fetchColumn();
        $extracts = (int)$db->query('SELECT COUNT(*) FROM eb_user_extract')->fetchColumn();
        check($s['balance'] === '100.00' && !$s['ledger'] && $commission === '100.00' && !$extracts && count($recharges) === 1
            && (int)$recharges[0]['paid'] === $paid && $recharges[0]['refund_price'] === '0.00'
            && ($result[0]['message'] ?? '') === 'Injected ledger failure',
            $action . ' ledger failure rolls back all local writes', ['state' => $s, 'recharges' => $recharges, 'commission' => $commission, 'extracts' => $extracts, 'result' => $result]);
    }

    foreach (['0.00', '10.00'] as $availableBalance) {
        seed($db, $availableBalance, []);
        $db->exec('DELETE FROM eb_user_recharge');
        $db->exec("INSERT INTO eb_user_recharge(id,uid,order_id,price,give_price,paid,recharge_type) VALUES(1,1,'audit-recharge',30,0,1,'weixin')");
        $result = workers($database, [['wallet:recharge_refund', 0]]);
        $s = state($db);
        $refunded = $db->query('SELECT refund_price FROM eb_user_recharge WHERE id=1')->fetchColumn();
        check($result[0]['status'] === true && $s['balance'] === '0.00' && $refunded === '30.00'
            && count($s['ledger']) === 1 && $s['ledger'][0]['number'] === $availableBalance && $s['ledger'][0]['balance'] === '0.00',
            'recharge refund preserves capped debit with balance ' . $availableBalance, ['state' => $s, 'refunded' => $refunded, 'result' => $result]);
    }

    seed($db, '100.00', []);
    $db->exec('DELETE FROM eb_user_recharge');
    $db->exec("INSERT INTO eb_user_recharge(id,uid,order_id,price,give_price,paid,recharge_type) VALUES(1,1,'audit-recharge',30,0,0,'weixin')");
    $results = workers($database, [['wallet:recharge', 0], ['wallet:recharge', 0]]);
    $s = state($db);
    check($s['balance'] === '130.00' && count($s['ledger']) === 1 && count(array_filter($results, fn($r) => $r['status'] === true)) === 1,
        'duplicate recharge callbacks credit once', ['state' => $s, 'results' => $results]);

    boot($database);
    $userDao = new app\dao\user\UserDao();
    $before = $db->query('SELECT now_money FROM eb_user WHERE uid=1')->fetchColumn();
    check($userDao->bcDec(1, 'now_money', '999.00', 'uid') === false && $userDao->bcInc(999, 'now_money', '1.00', 'uid') === false,
        'bc preserves insufficient and missing-account false results');
    try {
        Db::transaction(function () use ($userDao) {
            $userDao->bcInc(['uid' => 1], 'now_money', '30.00');
            throw new RuntimeException('Injected outer rollback');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Injected outer rollback') throw $e;
    }
    check($db->query('SELECT now_money FROM eb_user WHERE uid=1')->fetchColumn() === $before, 'nested bc transaction respects outer rollback');
    $db->exec("UPDATE eb_user SET now_money=100.55 WHERE uid=1");
    $userDao->bc(1, 'now_money', '0.05', 'uid', 1, 1);
    check($db->query('SELECT now_money FROM eb_user WHERE uid=1')->fetchColumn() === '100.60', 'bc retains caller-selected decimal precision');

    $now = time();
    $db->exec("INSERT INTO eb_store_coupon_user(id,cid,uid,coupon_title,coupon_price,use_min_price,start_time,end_time,status,is_fail) VALUES(1,1,1,'audit',10,0," . ($now - 60) . ',' . ($now + 3600) . ',0,0)');
    $computed = app()->make(StoreOrderComputedServices::class);
    $computed->useCouponId(1, 1, [['truePrice' => '40.00', 'cart_num' => 1]], 40, true);
    check((int)$db->query('SELECT status FROM eb_store_coupon_user WHERE id=1')->fetchColumn() === 0, 'price computation does not consume a coupon');
    $db->exec('UPDATE eb_store_coupon_user SET status=0,use_time=0');
    $dao = new StoreCouponUserDao();
    $wrongUser = $dao->useCoupon(1, 2);
    check(!$wrongUser, 'coupon consumption rejects another user');
    $db->exec('UPDATE eb_store_coupon_user SET status=0,use_time=0');
    $first = $dao->useCoupon(1, 1);
    $second = $dao->useCoupon(1, 1);
    check($first === 1 && $second === 0, 'coupon is consumed at most once', ['first' => $first, 'second' => $second]);

    foreach ([['is_fail', 1], ['end_time', $now - 1], ['start_time', $now + 3600]] as [$field, $value]) {
        $db->exec('UPDATE eb_store_coupon_user SET status=0,use_time=0,is_fail=0,start_time=' . ($now - 60) . ',end_time=' . ($now + 3600));
        $db->exec("UPDATE eb_store_coupon_user SET $field=$value WHERE id=1");
        check($dao->useCoupon(1, 1) === 0, 'coupon atomic consumption rejects ' . $field);
    }
    $db->exec('UPDATE eb_store_coupon_user SET is_fail=0,start_time=' . ($now - 60) . ',end_time=' . ($now + 3600));

    foreach ([1 => 'address_failure', 2 => 'stock_failure'] as $mode => $label) {
        seed($db, '100.00', []);
        $db->exec('UPDATE eb_store_coupon_user SET status=0,use_time=0');
        $result = workers($database, [['checkout', $mode]]);
        $status = (int)$db->query('SELECT status FROM eb_store_coupon_user WHERE id=1')->fetchColumn();
        $orders = (int)$db->query('SELECT COUNT(*) FROM eb_store_order')->fetchColumn();
        check($result[0]['status'] === false && $status === 0 && $orders === 0, $label . ' preserves coupon and creates no order', ['coupon_status' => $status, 'orders' => $orders, 'result' => $result]);
    }
    seed($db, '100.00', []);
    $db->exec('UPDATE eb_store_coupon_user SET status=0,use_time=0');
    $result = workers($database, [['checkout', 0], ['checkout', 0]]);
    $orders = (int)$db->query('SELECT COUNT(*) FROM eb_store_order')->fetchColumn();
    $status = (int)$db->query('SELECT status FROM eb_store_coupon_user WHERE id=1')->fetchColumn();
    check($orders === 1 && $status === 1 && count(array_filter($result, fn($r) => $r['status'] === true)) === 1, 'two checkout requests consume one coupon for one order', ['orders' => $orders, 'coupon_status' => $status, 'result' => $result]);

    $db->exec("INSERT INTO eb_luck_prize(id,lottery_id,type,num,name) VALUES(1,1,3,30,'audit cash prize')");
    $db->exec("INSERT INTO eb_luck_lottery_record(id,lottery_id,prize_id,uid,type,num,is_receive,channel_type) VALUES(1,1,1,1,3,30,0,'h5')");
    foreach (['lottery_spend', 'lottery_prize'] as $action) {
        seed($db, '100.00', ['40.00']);
        $db->exec('UPDATE eb_luck_lottery_record SET is_receive=0');
        putenv('AUDIT_DELAY_PAYMENT=1');
        $results = workers($database, [['wallet:' . $action, 0], ['store_order', 1]]);
        putenv('AUDIT_DELAY_PAYMENT');
        $s = state($db);
        $running = '100.00';
        $ledgerCorrect = true;
        foreach ($s['ledger'] as $entry) {
            $running = $entry['pm'] ? bcadd($running, $entry['number'], 2) : bcsub($running, $entry['number'], 2);
            $ledgerCorrect = $ledgerCorrect && $running === $entry['balance'];
        }
        $expected = $action === 'lottery_spend' ? '30.00' : '90.00';
        check($s['balance'] === $expected && $s['paid'] === 1 && count($s['ledger']) === 2 && $ledgerCorrect
            && count(array_filter($results, fn($r) => $r['status'] === true)) === 2,
            'payment concurrent with ' . $action . ' preserves money and ledger', ['state' => $s, 'results' => $results]);
    }
    seed($db, '50.00', ['40.00']);
    putenv('AUDIT_DELAY_PAYMENT=1');
    $results = workers($database, [['wallet:lottery_spend', 0], ['store_order', 1]]);
    putenv('AUDIT_DELAY_PAYMENT');
    $s = state($db);
    check($s['balance'] === '10.00' && $s['paid'] === 1 && count($s['ledger']) === 1
        && $results[0]['status'] === false && $results[1]['status'] === true,
        'lottery rechecks insufficient balance after concurrent payment', ['state' => $s, 'results' => $results]);
    seed($db, '100.00', []);
    $db->exec('UPDATE eb_luck_lottery_record SET is_receive=0');
    $results = workers($database, [['wallet:lottery_prize', 0], ['wallet:lottery_prize', 0]]);
    $s = state($db);
    check($s['balance'] === '130.00' && count($s['ledger']) === 1 && count(array_filter($results, fn($r) => $r['status'] === true)) === 1,
        'duplicate cash-prize claims credit exactly once', ['state' => $s, 'results' => $results]);
    foreach (['lottery_spend', 'lottery_prize'] as $action) {
        seed($db, '100.00', []);
        $db->exec('UPDATE eb_luck_lottery_record SET is_receive=0');
        putenv('AUDIT_FAIL_LEDGER=1');
        $results = workers($database, [['wallet:' . $action, 0]]);
        putenv('AUDIT_FAIL_LEDGER');
        $s = state($db);
        $received = (int)$db->query('SELECT is_receive FROM eb_luck_lottery_record WHERE id=1')->fetchColumn();
        check($s['balance'] === '100.00' && !$s['ledger'] && $received === 0 && ($results[0]['message'] ?? '') === 'Injected ledger failure',
            $action . ' ledger failure rolls back balance and prize status', ['state' => $s, 'received' => $received]);
    }
    seed($db, '100.00', []);
    $db->exec('UPDATE eb_luck_lottery_record SET is_receive=0');
    $results = workers($database, [['wallet:lottery_parent', 0], ['wallet:lottery_prize', 0]]);
    $s = state($db);
    // Either the parent wins (30 debit + 30 prize), or its failed claim rolls the debit back.
    check(in_array($s['balance'], ['100.00', '130.00'], true)
        && count(array_filter($s['ledger'], fn($r) => (int)$r['pm'] === 1)) === 1
        && count(array_filter($results, fn($r) => $r['status'] === true)) === 1,
        'nested lottery debit then prize uses a consistent account lock order', ['state' => $s, 'results' => $results]);
    seed($db, '100.00', []);
    $db->exec('UPDATE eb_luck_lottery_record SET is_receive=0');
    $results = workers($database, [['wallet:lottery_parent', 0]]);
    $s = state($db);
    check($results[0]['status'] === true && $s['balance'] === '100.00' && array_column($s['ledger'], 'balance') === ['70.00', '100.00']
        && (int)$db->query('SELECT is_receive FROM eb_luck_lottery_record WHERE id=1')->fetchColumn() === 1,
        'parent lottery transaction can debit and award cash successfully', ['state' => $s, 'results' => $results]);
} finally {
    if ($created) {
        guardedDatabase($database);
        $admin->exec("DROP DATABASE `$database`");
        echo "CLEAN isolated database removed\n";
    }
}
exit($failures ? 1 : 0);
