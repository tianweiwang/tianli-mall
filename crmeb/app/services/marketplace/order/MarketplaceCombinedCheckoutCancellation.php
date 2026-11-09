<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MarketplaceOrderDao;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\ConnectionInterface;
use think\facade\Config;
use think\facade\Db;

final class MarketplaceCombinedCheckoutCancellation
{
    public function __construct(
        private MarketplaceOrderDao $orders,
        private MarketplacePaymentDao $payments,
    ) {}

    public function cancelForCustomer(int $uid, string $orderNo): void
    {
        if (!self::enabled()) throw new ApiException('合单功能未开启');
        if ($uid < 1 || !preg_match('/\Acc[0-9a-f]{24}\z/D', $orderNo)) throw new ApiException('合单不存在');
        [$pdo] = self::database(Db::connect(), false);
        $this->cancel($pdo, $uid, $orderNo, false);
    }

    public function containsOrder(PDO $pdo, array $candidate): bool
    {
        return $this->memberCheckout($pdo, $candidate) !== null;
    }

    /** A system expiry starts from a real saved root, never a synthetic actor. */
    public function cancelExpiredMember(PDO $pdo, array $candidate): ?array
    {
        $orderNo = $this->memberCheckout($pdo, $candidate);
        if ($orderNo === null) return null;
        return [
            'state' => $this->cancel($pdo, $candidate['uid'], $orderNo, true),
            'order_id' => $candidate['order_no'],
        ];
    }

    public static function enabled(): bool
    {
        return getenv('APP_ENV') === 'testing'
            && Config::get('marketplace.combined_checkout.enabled', false) === true;
    }

    private function memberCheckout(PDO $pdo, array $candidate): ?string
    {
        if (!self::enabled()) return null;
        [$actual, $prefix] = self::database(Db::connect(), false);
        if ($pdo !== $actual) throw new AuthUnavailableException();
        $statement = $pdo->prepare("SELECT g.uid,g.order_no,m.mer_id
            FROM `{$prefix}marketplace_combined_checkout_order` m
            JOIN `{$prefix}marketplace_combined_checkout` g ON g.id=m.checkout_id
            WHERE m.root_order_id=?");
        $statement->execute([$candidate['order_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ((int)$row['uid'] !== $candidate['uid'] || (int)$row['mer_id'] !== $candidate['mer_id']) {
            throw new AuthUnavailableException();
        }
        return $row['order_no'];
    }

    private function cancel(PDO $pdo, int $uid, string $orderNo, bool $expired): string
    {
        [$actual, $prefix] = self::database(Db::connect(), false);
        if ($pdo !== $actual) throw new AuthUnavailableException();
        $group = self::group($pdo, $prefix, $uid, $orderNo, false);
        if ($group['state'] === 'quoted') throw new ApiException('合单尚未提交');
        $members = self::members($pdo, $prefix, $group['id'], false);
        self::assertMembers($group, $members);
        // The native candidate reader requires autocommit. Its hints are verified under the locks below.
        $candidates = [];
        foreach ($members as $member) {
            $id = (int)$member['root_order_id'];
            $candidate = $this->orders->expiryCancellationCandidate($pdo, $id);
            if ($candidate === null || $candidate['uid'] !== $uid
                || $candidate['mer_id'] !== (int)$member['mer_id'] || $candidate['order_id'] !== $id) {
                throw new AuthUnavailableException();
            }
            $candidates[$id] = $candidate;
        }

        return Db::transaction(function (ConnectionInterface $connection) use (
            $pdo, $prefix, $uid, $orderNo, $group, $members, $candidates, $expired
        ): string {
            [$actual] = self::database($connection, true);
            if ($actual !== $pdo) throw new AuthUnavailableException();
            // Same order as combined payment: checkout first, then the native root lock sequence.
            $current = self::group($pdo, $prefix, $uid, $orderNo, true);
            if ($current['id'] !== $group['id'] || $current['quote_data'] !== $group['quote_data']
                || $current['total_amount'] !== $group['total_amount']
                || self::members($pdo, $prefix, $current['id'], true) !== $members) {
                throw new AuthUnavailableException();
            }
            if ($current['state'] === 'paid') {
                if ($expired) return 'not_due';
                throw new ApiException('合单已支付，请按店铺申请售后');
            }
            $cancelled = $current['state'] === 'cancelled';
            if ((!$cancelled && $current['state'] !== 'pending') || $current['create_key'] === null
                || $current['payment_key'] !== null || $current['test_trade_no'] !== null || $current['paid_at'] !== null) {
                throw new AuthUnavailableException();
            }

            $now = time();
            $releases = [];
            foreach ($members as $member) {
                $candidate = $candidates[(int)$member['root_order_id']];
                $initial = $this->orders->lockCancellation($pdo, $candidate, $now, $expired);
                if ($expired && $initial['state'] === 'not_due') return 'not_due';
                if ($initial['payment_stage'] !== 'close' || $initial['payment']['attempts'] !== []
                    || $initial['order']['pay_price'] !== $member['amount']) {
                    throw new AuthUnavailableException();
                }
                $proof = $this->payments->closedCancellationProof($pdo, $uid, $candidate['order_id'],
                    $candidate['order_no'], $initial['payment']);
                if ($proof === null) throw new AuthUnavailableException();
                $locked = $this->orders->lockCancellation($pdo, $candidate, $now, $expired, $proof);
                if ($locked['state'] !== ($cancelled ? 'already_cancelled' : 'ready')) {
                    throw new AuthUnavailableException();
                }
                $releases[] = [$locked, $proof];
            }
            if ($cancelled) return 'already_cancelled';

            foreach ($releases as [$locked, $proof]) {
                $result = $this->orders->releaseCancellation($pdo, $locked, $now,
                    $expired ? 'system' : 'customer', $expired ? null : $uid, $proof);
                if ($result['state'] !== 'cancelled') throw new AuthUnavailableException();
            }
            $statement = $pdo->prepare("UPDATE `{$prefix}marketplace_combined_checkout`
                SET state='cancelled' WHERE id=? AND uid=? AND state='pending'");
            $statement->execute([$current['id'], $uid]);
            if ($statement->rowCount() !== 1) throw new AuthUnavailableException();
            return 'cancelled';
        });
    }

    private static function group(PDO $pdo, string $prefix, int $uid, string $orderNo, bool $lock): array
    {
        $statement = $pdo->prepare("SELECT id,state,quote_data,total_amount,create_key,payment_key,test_trade_no,paid_at
            FROM `{$prefix}marketplace_combined_checkout` WHERE order_no=? AND uid=?" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderNo, $uid]);
        $group = $statement->fetch(PDO::FETCH_ASSOC);
        if ($group === false) throw new ApiException('合单不存在');
        return $group;
    }

    private static function members(PDO $pdo, string $prefix, int|string $id, bool $lock): array
    {
        $statement = $pdo->prepare("SELECT root_order_id,mer_id,amount
            FROM `{$prefix}marketplace_combined_checkout_order`
            WHERE checkout_id=? ORDER BY root_order_id LIMIT 11" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function assertMembers(array $group, array $members): void
    {
        $parts = json_decode($group['quote_data'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($parts) || !array_is_list($parts) || count($members) !== count($parts)
            || count($members) < 2 || count($members) > 10) {
            throw new AuthUnavailableException();
        }
        $amounts = [];
        foreach ($parts as $part) $amounts[$part['view']['mer_id']] = $part['view']['amount'];
        $total = '0.00';
        foreach ($members as $member) {
            if (($amounts[$member['mer_id']] ?? null) !== $member['amount']) throw new AuthUnavailableException();
            $total = bcadd($total, $member['amount'], 2);
        }
        if ($total !== $group['total_amount']) throw new AuthUnavailableException();
    }

    private static function database(ConnectionInterface $connection, bool $inTransaction): array
    {
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO && !$inTransaction) {
            $connection->query('SELECT 1', [], true);
            $pdo = $connection->getPdo();
        }
        $prefix = $connection->getConfig('prefix');
        if (!$pdo instanceof PDO || $connection !== Db::connect() || $pdo !== Db::connect()->getPdo()
            || $pdo->inTransaction() !== $inTransaction || $connection->transactionDepth() !== ($inTransaction ? 1 : 0)
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)
            || !is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{0,24}\z/D', $prefix)) {
            throw new AuthUnavailableException();
        }
        return [$pdo, $prefix];
    }
}
