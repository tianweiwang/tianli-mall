<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\services\marketplace\payment\MarketplacePaymentServices;
use app\services\order\StoreOrderComputedServices;
use app\services\order\StoreOrderServices;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\pay\marketplace\PaymentAccount;
use crmeb\services\pay\marketplace\PaymentAccountResolver;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\facade\Db;

/** One testing checkout and payment, with one native root order per shop. */
final class MarketplaceCombinedCheckoutServices
{
    private const QUOTE_TTL = 540;

    public function __construct(
        private StoreOrderServices $orders,
        private StoreOrderComputedServices $computed,
        private MarketplaceOrderCreateServices $creator,
        private MarketplacePaymentServices $payments,
        private PaymentAccountResolver $accounts,
    ) {}

    public static function available(): bool
    {
        return getenv('APP_ENV') === 'testing'
            && Config::get('marketplace.combined_checkout.enabled', false) === true
            && Config::get('marketplace.payment.test_enabled', false) === true
            && MarketplaceTradePolicy::v1CreateEnabled();
    }

    public function quote(int $uid, array $user, array $body): array
    {
        $this->requireAvailable();
        self::keys($body, ['address_id', 'groups']);
        $addressId = MarketplaceOrderInput::unsigned($body['address_id'], '收货地址', 16777215);
        $groups = $body['groups'];
        if ((int)($user['uid'] ?? 0) !== $uid || $addressId === 0 || !is_array($groups)
            || !array_is_list($groups) || count($groups) < 2 || count($groups) > 10) {
            throw new ApiException('请选择收货地址和至少两家店铺的商品');
        }

        $parts = [];
        $seen = [];
        foreach ($groups as $group) {
            if (!is_array($group)) throw new ApiException('店铺选择不正确');
            self::keys($group, ['mer_id', 'cart_ids']);
            $merchant = MarketplaceOrderInput::unsigned($group['mer_id'], '店铺');
            $references = $group['cart_ids'];
            if (!$merchant || isset($parts[$merchant]) || !is_array($references)
                || !array_is_list($references) || !$references || count($references) > 30) {
                throw new ApiException('店铺或购物车选择不正确');
            }
            foreach ($references as &$reference) {
                $reference = MarketplaceOrderInput::reference($reference, 'persistent');
                if (isset($seen[$reference])) throw new ApiException('购物车商品不能重复选择');
                $seen[$reference] = true;
            }
            unset($reference);
            if (count($seen) > 30) throw new ApiException('一次合单最多选择 30 项商品');

            $raw = ['mer_id' => $merchant, 'cartId' => implode(',', $references),
                'addressId' => $addressId, 'new' => 0, 'shipping_type' => 1, 'is_gift' => 0];
            $choice = MarketplaceOrderInput::confirm($raw);
            MarketplaceTradePolicy::preview($raw, $choice);
            $confirmation = $this->orders->getOrderConfirmData($user, $choice['cartId'], false, $addressId, 1, 0, $choice);
            if (!empty($confirmation['custom_form'])) {
                throw new ApiException('需要填写下单资料的商品请按店结算');
            }
            $key = $confirmation['orderKey'];
            $cached = $this->orders->getCacheOrderInfo($uid, $key);
            if (!is_array($cached)) throw new AuthUnavailableException();
            $amounts = $this->computed->setParamData([])->computedOrder($uid, $user, $cached,
                $addressId, '', false, 0, false, 1, 0, true);
            $goods = self::money($amounts['total_price']);
            $shipping = self::money($amounts['pay_postage']);
            $amount = self::money($amounts['pay_price']);
            if (bcadd($goods, $shipping, 2) !== $amount || bccomp($amount, '0.00', 2) <= 0) {
                throw new ApiException('店铺结算金额不支持合并测试支付');
            }

            $items = [];
            foreach ($confirmation['cartInfo'] as $line) {
                $product = $line['productInfo'];
                $items[] = ['name' => (string)$product['store_name'],
                    'sku_name' => (string)($product['attrInfo']['suk'] ?? ''),
                    'quantity' => (int)$line['cart_num'],
                    'unit_price' => self::money($line['truePrice'] ?? $product['price']),
                    'image' => (string)(($product['attrInfo']['image'] ?? '') ?: $product['image'])];
            }
            $parts[$merchant] = ['key' => $key, 'input' => $raw, 'view' => ['mer_id' => $merchant,
                'name' => $confirmation['store']['name'], 'goods_amount' => $goods,
                'shipping_amount' => $shipping, 'amount' => $amount, 'items' => $items]];
        }

        ksort($parts, SORT_NUMERIC);
        $total = '0.00';
        foreach ($parts as $part) $total = bcadd($total, $part['view']['amount'], 2);
        $total = self::money($total);
        $orderNo = 'cc' . bin2hex(random_bytes(12));
        [$pdo, $prefix] = self::database(Db::connect());
        $sql = "INSERT INTO `{$prefix}marketplace_combined_checkout`
            (order_no,uid,address_id,quote_data,total_amount,state,created_at) VALUES (?,?,?,?,?,'quoted',?)";
        $pdo->prepare($sql)->execute([$orderNo, $uid, $addressId,
            json_encode(array_values($parts), JSON_THROW_ON_ERROR), $total, time()]);
        return $this->detail($uid, $orderNo);
    }

    public function create(int $uid, string $orderNo, array $body): array
    {
        $this->requireAvailable();
        $key = self::idempotency($body);
        $roots = Db::transaction(function (ConnectionInterface $connection) use ($uid, $orderNo, $key): array {
            [$pdo, $prefix] = self::database($connection);
            $group = self::group($pdo, $prefix, $uid, $orderNo, true);
            if ($group['state'] !== 'quoted') {
                if ($group['create_key'] !== $key) throw new ApiException('合单已提交，请查看原订单');
                return array_column(self::members($pdo, $prefix, (int)$group['id']), 'root_order_id');
            }
            if (time() >= (int)$group['created_at'] + self::QUOTE_TTL) {
                throw new ApiException('合单确认已过期，请返回购物车重新确认');
            }
            $parts = self::parts($group);
            $members = [];
            foreach ($parts as $part) {
                $view = $part['view'];
                $input = $part['input'] + ['idem_key' => $orderNo . ':' . $view['mer_id'],
                    'invoice_id' => 0, 'payType' => '', 'couponId' => 0, 'useIntegral' => false, 'custom_form' => []];
                $root = $this->creator->createWithinCheckout($connection, $uid, $part['key'], $input,
                    ['total_price' => $view['goods_amount'], 'pay_postage' => $view['shipping_amount'],
                        'pay_price' => $view['amount']]);
                $pdo->prepare("INSERT INTO `{$prefix}marketplace_combined_checkout_order`
                    (checkout_id,root_order_id,mer_id,amount) VALUES (?,?,?,?)")
                    ->execute([$group['id'], $root['order_pk'], $view['mer_id'], $view['amount']]);
                $members[] = $root['order_pk'];
            }
            $pdo->prepare("UPDATE `{$prefix}marketplace_combined_checkout` SET state='pending',create_key=? WHERE id=?")
                ->execute([$key, $group['id']]);
            return $members;
        });
        foreach ($roots as $rootId) $this->creator->finishCheckoutOrder((int)$rootId);
        return $this->detail($uid, $orderNo);
    }

    public function pay(int $uid, string $orderNo, array $body): array
    {
        $this->requireAvailable();
        PaymentAccount::assertTesting();
        $key = self::idempotency($body);
        [$pdo, $prefix] = self::database(Db::connect());
        $candidate = self::group($pdo, $prefix, $uid, $orderNo);
        if ($candidate['state'] === 'paid') {
            if ($candidate['payment_key'] !== $key) throw new ApiException('合单已支付');
            return $this->detail($uid, $orderNo);
        }
        if ($candidate['state'] !== 'pending') throw new ApiException('合单尚未提交或已关闭');

        $accounts = [];
        foreach (self::members($pdo, $prefix, (int)$candidate['id']) as $member) {
            $merchant = (int)$member['mer_id'];
            $this->accounts->registerTestAccount($merchant, 'test:isolated-preview', 1, 'CNY');
            $accounts[(int)$member['root_order_id']] = $this->accounts->forOrder(
                ['id' => (int)$member['root_order_id'], 'order_id' => $member['order_no'],
                    'uid' => $uid, 'mer_id' => $merchant, 'marketplace_trade_version' => 1,
                    'pay_price' => $member['pay_price'], 'paid' => $member['paid'],
                    'is_cancel' => $member['is_cancel'], 'status' => $member['status']]);
        }

        Db::transaction(function (ConnectionInterface $connection) use ($uid, $orderNo, $key, $accounts): void {
            [$pdo, $prefix] = self::database($connection);
            $group = self::group($pdo, $prefix, $uid, $orderNo, true);
            if ($group['state'] === 'paid') {
                if ($group['payment_key'] !== $key) throw new ApiException('合单已支付');
                return;
            }
            if ($group['state'] !== 'pending') throw new ApiException('合单已关闭');
            $members = self::members($pdo, $prefix, (int)$group['id']);
            $parts = self::parts($group);
            if (count($members) !== count($parts)) throw new AuthUnavailableException();
            $sum = '0.00';
            foreach ($members as $member) {
                if ((int)$member['uid'] !== $uid || (int)$member['paid'] !== 0 || (int)$member['is_cancel'] !== 0
                    || self::money($member['pay_price']) !== self::money($member['amount'])) {
                    throw new ApiException('店铺订单状态已变化，请刷新合单');
                }
                $sum = bcadd($sum, $member['amount'], 2);
            }
            if ($sum !== self::money($group['total_amount'])) throw new AuthUnavailableException();
            $at = time();
            $tradeNo = 'ct' . substr($orderNo, 2);
            $pdo->prepare("UPDATE `{$prefix}marketplace_combined_checkout`
                SET state='paid',payment_key=?,test_trade_no=?,paid_at=? WHERE id=?")
                ->execute([$key, $tradeNo, $at, $group['id']]);
            foreach ($members as $member) {
                $rootId = (int)$member['root_order_id'];
                if (!isset($accounts[$rootId])) throw new AuthUnavailableException();
                $this->payments->applyCombinedTestAllocation($connection, $pdo, $uid, (int)$group['id'],
                    $rootId, $member['order_no'], $accounts[$rootId], $at);
            }
        });
        return $this->detail($uid, $orderNo);
    }

    public function detail(int $uid, string $orderNo): array
    {
        $this->requireAvailable();
        [$pdo, $prefix] = self::database(Db::connect());
        return self::view($pdo, $prefix, self::group($pdo, $prefix, $uid, $orderNo));
    }

    public function listing(int $uid): array
    {
        if (!self::available()) return ['list' => []];
        [$pdo, $prefix] = self::database(Db::connect());
        $statement = $pdo->prepare("SELECT * FROM `{$prefix}marketplace_combined_checkout`
            WHERE uid=? AND state<>'quoted' ORDER BY id DESC LIMIT 20");
        $statement->execute([$uid]);
        return ['list' => array_map(static fn(array $group): array => self::view($pdo, $prefix, $group),
            $statement->fetchAll(PDO::FETCH_ASSOC))];
    }

    private static function view(PDO $pdo, string $prefix, array $group): array
    {
        $parts = self::parts($group);
        $members = $group['state'] === 'quoted' ? [] : self::members($pdo, $prefix, (int)$group['id']);
        if ($group['state'] !== 'quoted' && count($members) !== count($parts)) throw new AuthUnavailableException();
        $byMerchant = array_column($members, null, 'mer_id');
        $stores = [];
        $refund = '0.00';
        $state = $group['state'];
        foreach ($parts as $part) {
            $view = $part['view'];
            $row = $byMerchant[$view['mer_id']] ?? null;
            if ($row !== null) {
                if ((int)$row['uid'] !== (int)$group['uid'] || self::money($row['amount']) !== $view['amount']) {
                    throw new AuthUnavailableException();
                }
                $refunded = self::money($row['refunded_amount']);
                $refund = bcadd($refund, $refunded, 2);
                if ($state === 'pending' && (int)$row['is_cancel'] === 1) $state = 'cancelled';
                $view += ['order_no' => $row['order_no'], 'order_id' => (int)$row['root_order_id'],
                    'paid' => (int)$row['paid'] === 1, 'status' => (int)$row['status'],
                    'cancelled' => (int)$row['is_cancel'] === 1, 'refunded_amount' => $refunded];
            }
            $stores[] = $view;
        }
        $amount = self::money($group['total_amount']);
        return ['order_no' => $group['order_no'], 'state' => $state, 'address_id' => (int)$group['address_id'],
            'total_amount' => $amount, 'paid_amount' => $state === 'paid' ? $amount : '0.00',
            'refunded_amount' => $refund, 'stores' => $stores];
    }

    private static function group(PDO $pdo, string $prefix, int $uid, string $orderNo, bool $lock = false): array
    {
        if ($uid < 1 || !preg_match('/\Acc[0-9a-f]{24}\z/D', $orderNo)) throw new ApiException('合单不存在');
        $statement = $pdo->prepare("SELECT * FROM `{$prefix}marketplace_combined_checkout`
            WHERE order_no=? AND uid=?" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderNo, $uid]);
        $group = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new ApiException('合单不存在');
        return $group;
    }

    private static function members(PDO $pdo, string $prefix, int $checkoutId): array
    {
        $statement = $pdo->prepare("SELECT m.root_order_id,m.mer_id,m.amount,o.order_id AS order_no,
            o.uid,o.pay_price,o.paid,o.status,o.is_cancel,
            COALESCE((SELECT SUM(r.refunded_price) FROM `{$prefix}store_order_refund` r
                WHERE r.marketplace_root_order_id=o.id AND r.uid=o.uid AND r.mer_id=o.mer_id),0.00) AS refunded_amount
            FROM `{$prefix}marketplace_combined_checkout_order` m
            JOIN `{$prefix}store_order` o ON o.id=m.root_order_id AND o.mer_id=m.mer_id
            WHERE m.checkout_id=? AND o.pid=0 AND o.marketplace_trade_version=1 ORDER BY m.root_order_id");
        $statement->execute([$checkoutId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function parts(array $group): array
    {
        $parts = json_decode($group['quote_data'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($parts) || !array_is_list($parts) || count($parts) < 2) throw new AuthUnavailableException();
        return $parts;
    }

    private static function database(ConnectionInterface $connection): array
    {
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            $connection->query('SELECT 1', [], true);
            $pdo = $connection->getPdo();
        }
        $prefix = $connection->getConfig('prefix');
        if (!$pdo instanceof PDO || $connection !== Db::connect() || $pdo !== Db::connect()->getPdo()
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || !is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{0,24}\z/D', $prefix)) {
            throw new AuthUnavailableException();
        }
        return [$pdo, $prefix];
    }

    private static function keys(array $body, array $expected): void
    {
        $keys = array_keys($body);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) throw new ApiException('合单参数不正确');
    }

    private static function idempotency(array $body): string
    {
        self::keys($body, ['idempotency_key']);
        $key = $body['idempotency_key'];
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $key)) {
            throw new ApiException('合单请求编号不正确');
        }
        return $key;
    }

    private static function money(mixed $value): string
    {
        if (!is_scalar($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/D', (string)$value)) {
            throw new AuthUnavailableException();
        }
        return bcadd((string)$value, '0', 2);
    }

    private function requireAvailable(): void
    {
        if (!self::available()) throw new ApiException('当前环境未开启跨店测试结算');
    }
}
