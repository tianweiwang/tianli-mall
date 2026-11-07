<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\exceptions\AuthUnavailableException;

/** A test report based on one saved reconciliation and one verified current snapshot. */
final class FinanceStatementProjection
{
    private const AMOUNTS = ['sales_amount','refund_amount','channel_fee_amount','platform_fee_amount',
        'platform_fee_reversal','platform_fee_net','merchant_receivable','settled_amount'];
    private const ORDER_FIELDS = ['root_order_id','sale_in_period','goods_paid','shipping_paid','refunded_before',
        'refunded_after','goods_refunded_before','goods_refunded_after','local_refs'];

    public static function build(array $billDetail, array $evidence, array $draft): array
    {
        $draft = FinanceSettingsInput::draft($draft);
        Facts::keys($evidence, ['rows','issues','source_sha256','orders']);
        foreach (['rows','issues','orders'] as $field) {
            if (!is_array($evidence[$field]) || !array_is_list($evidence[$field])) throw new AuthUnavailableException();
        }
        if (count($evidence['rows']) > FinanceBillReconciliation::MAX_ROWS
            || count($evidence['orders']) > count($evidence['rows'])) throw new AuthUnavailableException();
        $currentDigest = self::digest($evidence['source_sha256']);
        $savedDigest = self::digest($billDetail['source_sha256'] ?? null);
        if (!hash_equals($currentDigest, hash('sha256', Facts::canonical(['rows'=>$evidence['rows'], 'issues'=>$evidence['issues']])))) {
            throw new AuthUnavailableException();
        }

        $periodOpen = $billDetail['period_open'] ?? null;
        $savedIssues = $billDetail['source_issues'] ?? null;
        $comparison = $billDetail['comparison'] ?? [];
        $differences = $comparison['counts']['differences'] ?? null;
        $matched = $comparison['counts']['matched'] ?? null;
        $comparisons = $comparison['rows'] ?? null;
        if (!is_bool($periodOpen) || !is_array($savedIssues) || !array_is_list($savedIssues)
            || !is_int($differences) || $differences < 0 || !is_int($matched) || $matched < 0
            || !is_array($comparisons) || !array_is_list($comparisons) || $matched + $differences !== count($comparisons)) {
            throw new AuthUnavailableException();
        }
        $sameSource = hash_equals($savedDigest, $currentDigest);
        if ($sameSource && Facts::canonical($savedIssues) !== Facts::canonical($evidence['issues'])) {
            throw new AuthUnavailableException();
        }

        $reasons = [];
        if (!$sameSource) $reasons[] = 'SOURCE_CHANGED';
        if ($periodOpen) $reasons[] = 'PERIOD_OPEN';
        if ($sameSource && $evidence['rows'] === []) $reasons[] = 'NO_MOVEMENTS';
        if ($differences > 0) $reasons[] = 'RECONCILIATION_DIFFERENCES';
        if ($savedIssues !== [] || $evidence['issues'] !== []) $reasons[] = 'SOURCE_REVIEW_REQUIRED';
        if (!$sameSource) {
            // Today's values do not belong to the saved comparison selected for this statement.
            return ['status'=>'PENDING_REVIEW', 'reasons'=>$reasons, 'totals'=>array_fill_keys(self::AMOUNTS, null), 'orders'=>[]];
        }

        $movements = self::byReference($evidence['rows']);
        $fees = self::matchedFees($comparisons, $movements, $matched);
        $orders = [];
        $seenOrders = [];
        foreach ($evidence['orders'] as $sourceOrder) {
            $order = self::order($sourceOrder, $movements, $fees, $draft);
            $root = $order['root_order_id'];
            if (isset($seenOrders[$root])) throw new AuthUnavailableException();
            $seenOrders[$root] = true;
            $orders[] = $order;
            foreach ($order['reasons'] as $reason) {
                if (!in_array($reason, $reasons, true)) $reasons[] = $reason;
            }
        }
        if ($movements !== []) throw new AuthUnavailableException();

        return ['status'=>$reasons === [] ? 'ESTIMATED' : 'PENDING_REVIEW', 'reasons'=>$reasons,
            'totals'=>self::totals($orders), 'orders'=>$orders];
    }

    private static function byReference(array $rows): array
    {
        $movements = [];
        foreach ($rows as $row) {
            Facts::keys($row, FinanceBillReconciliation::COLUMNS);
            $reference = Facts::key($row['local_ref']);
            if (isset($movements[$reference]) || !in_array($row['kind'], ['payment','refund'], true)
                || $row['channel_fee'] !== null || FinanceBillReconciliation::cents($row['amount']) <= 0) {
                throw new AuthUnavailableException();
            }
            $movements[$reference] = $row;
        }
        return $movements;
    }

    private static function matchedFees(array $comparisons, array $movements, int $matched): array
    {
        $fees = [];
        $withoutFee = ['channel_fee'=>true];
        foreach ($comparisons as $comparison) {
            if (($comparison['status'] ?? null) !== 'MATCHED') continue;
            $reference = Facts::key($comparison['local_ref'] ?? null);
            $local = $comparison['local'] ?? null;
            $bill = $comparison['bill'] ?? null;
            if (array_key_exists($reference, $fees) || !isset($movements[$reference]) || !is_array($local) || !is_array($bill)
                || Facts::canonical($local) !== Facts::canonical($movements[$reference])
                || !array_key_exists('channel_fee', $bill) || $local['provider_no'] === null
                || Facts::canonical(array_diff_key($bill, $withoutFee)) !== Facts::canonical(array_diff_key($local, $withoutFee))) {
                throw new AuthUnavailableException();
            }
            $fees[$reference] = $bill['channel_fee'] === null ? null : FinanceBillReconciliation::cents($bill['channel_fee'], true);
        }
        if (count($fees) !== $matched) throw new AuthUnavailableException();
        return $fees;
    }

    private static function order(array $order, array &$unassigned, array $fees, array $draft): array
    {
        Facts::keys($order, self::ORDER_FIELDS);
        $root = $order['root_order_id'];
        $sale = $order['sale_in_period'];
        $references = $order['local_refs'];
        if (!is_int($root) || Facts::id($root) !== $root || !is_bool($sale) || !is_array($references)
            || !array_is_list($references) || $references === []) throw new AuthUnavailableException();

        $payments = $sales = $refunds = $fee = 0;
        $feeKnown = true;
        foreach ($references as $reference) {
            $reference = Facts::key($reference);
            if (!isset($unassigned[$reference])) throw new AuthUnavailableException();
            $row = $unassigned[$reference];
            unset($unassigned[$reference]);
            $amount = FinanceBillReconciliation::cents($row['amount']);
            if ($row['kind'] === 'payment') {
                $payments++;
                $sales += $amount;
            } else {
                $refunds += $amount;
            }
            if (($fees[$reference] ?? null) === null) $feeKnown = false;
            else $fee += $fees[$reference];
        }

        $inputs = array_diff_key($order, ['root_order_id'=>true, 'local_refs'=>true]);
        $inputs['channel_fee'] = $feeKnown ? FinanceBillReconciliation::amount($fee) : null;
        // An explicitly selected trial revision applies only when the original sale is in this period.
        $calculated = FinanceSettlementCalculation::calculatePeriod($sale ? $draft : FinanceSettingsInput::emptyDraft(), $inputs);
        if ($payments !== ($sale ? 1 : 0) || $calculated['sales_amount'] !== FinanceBillReconciliation::amount($sales)
            || $calculated['refund_amount'] !== FinanceBillReconciliation::amount($refunds)) {
            throw new AuthUnavailableException();
        }

        $reasons = [];
        if (!$sale) $reasons[] = 'ORIGINAL_SALE_RULE_UNKNOWN';
        elseif ($calculated['missing_fields'] !== []) $reasons[] = 'RULE_INCOMPLETE';
        elseif ($calculated['status'] === 'REFUND_BASIS_UNKNOWN') $reasons[] = 'REFUND_BASIS_UNKNOWN';
        if (!$feeKnown) $reasons[] = 'CHANNEL_FEE_UNKNOWN';

        return ['root_order_id'=>$root, 'sale_in_period'=>$sale,
            'amounts'=>array_intersect_key($calculated, array_fill_keys(self::AMOUNTS, true)), 'reasons'=>$reasons];
    }

    private static function totals(array $orders): array
    {
        $totals = array_fill_keys(self::AMOUNTS, null);
        $totals['sales_amount'] = $totals['refund_amount'] = '0.00';
        if ($orders === []) return $totals;
        foreach (self::AMOUNTS as $field) {
            $sum = 0;
            foreach ($orders as $order) {
                $value = $order['amounts'][$field];
                if ($value === null) continue 2;
                // These are exact decimals produced by the calculator. The 1000-row bound keeps totals in int64.
                $sum += (int)str_replace('.', '', $value);
            }
            $totals[$field] = FinanceBillReconciliation::amount($sum);
        }
        return $totals;
    }

    private static function digest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) throw new AuthUnavailableException();
        return $value;
    }
}
