<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use think\exception\HttpException;

/** Per-order arithmetic. The caller owns saved rule selection, source verification and eligibility. */
final class FinanceSettlementCalculation
{
    public static function calculate(array $draft,array $amounts):array
    {
        $draft=FinanceSettingsInput::draft($draft);
        $keys=array_keys($amounts);sort($keys);
        $expected=['goods_paid','shipping_paid','goods_refunded','shipping_refunded','channel_fee'];sort($expected);
        if($keys!==$expected)throw new HttpException(422,'结算计算金额字段不正确');
        $values=[];
        foreach(['goods_paid','shipping_paid','goods_refunded','shipping_refunded'] as $field)
            $values[$field]=FinanceBillReconciliation::cents($amounts[$field]);
        $channelFee=$amounts['channel_fee']===null?null:FinanceBillReconciliation::cents($amounts['channel_fee'],true);
        $sales=$values['goods_paid']+$values['shipping_paid'];
        $refund=$values['goods_refunded']+$values['shipping_refunded'];
        if($sales>999999999999||$values['goods_refunded']>$values['goods_paid']||$values['shipping_refunded']>$values['shipping_paid'])
            throw new HttpException(422,'退款金额超过对应实付金额');
        return self::project($draft, true, ['goods_paid'=>$values['goods_paid'],'order_paid'=>$sales],
            ['goods_paid'=>0,'order_paid'=>0], ['goods_paid'=>$values['goods_refunded'],'order_paid'=>$refund],
            $channelFee, 'cumulative_fee_calculation_only');
    }

    /** Amounts are cumulative across the order. Only the signed channel fee belongs to this period. */
    public static function calculatePeriod(array $draft, array $amounts): array
    {
        $draft = FinanceSettingsInput::draft($draft);
        $keys = array_keys($amounts);
        $expected = ['sale_in_period','goods_paid','shipping_paid','refunded_before','refunded_after',
            'goods_refunded_before','goods_refunded_after','channel_fee'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected || !is_bool($amounts['sale_in_period'])) {
            throw new HttpException(422, '期间结算计算字段不正确');
        }

        $goods = FinanceBillReconciliation::cents($amounts['goods_paid']);
        $shipping = FinanceBillReconciliation::cents($amounts['shipping_paid']);
        $paid = $goods + $shipping;
        $before = FinanceBillReconciliation::cents($amounts['refunded_before']);
        $after = FinanceBillReconciliation::cents($amounts['refunded_after']);
        if ($paid > 999999999999 || $after > $paid || $before > $after || ($amounts['sale_in_period'] && $before !== 0)) {
            throw new HttpException(422, '期间累计退款与原实付不一致');
        }

        $goodsBefore = self::goodsRefunded($amounts['goods_refunded_before'], $before, $goods, $shipping);
        $goodsAfter = self::goodsRefunded($amounts['goods_refunded_after'], $after, $goods, $shipping);
        if ($goodsBefore !== null && $goodsAfter !== null
            && ($goodsAfter < $goodsBefore || $goodsAfter - $goodsBefore > $after - $before)) {
            throw new HttpException(422, '商品及运费累计退款顺序不正确');
        }
        $channelFee = self::periodChannelFee($amounts['channel_fee']);

        return self::project($draft, $amounts['sale_in_period'], ['goods_paid'=>$goods,'order_paid'=>$paid],
            ['goods_paid'=>$goodsBefore,'order_paid'=>$before], ['goods_paid'=>$goodsAfter,'order_paid'=>$after],
            $channelFee, 'period_fee_calculation_only');
    }

    private static function periodChannelFee(mixed $value): ?int
    {
        if ($value === null) return null;
        // A period's fee can sum up to all 1000 valid individual CSV amounts.
        if (!is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]{0,12})\.[0-9]{2}\z/D', $value) !== 1 || $value === '-0.00') {
            throw new HttpException(422, '期间通道费须为固定两位小数');
        }
        $cents = (int)str_replace('.', '', $value);
        if (abs($cents) > FinanceBillReconciliation::MAX_ROWS * 999999999999) {
            throw new HttpException(422, '期间通道费超过账单总额上限');
        }
        return $cents;
    }

    private static function goodsRefunded(mixed $value, int $refunded, int $goodsPaid, int $shippingPaid): ?int
    {
        $minimum = max(0, $refunded - $shippingPaid);
        $maximum = min($refunded, $goodsPaid);
        if ($value === null) {
            return $minimum === $maximum ? $minimum : null;
        }
        $goods = FinanceBillReconciliation::cents($value);
        if ($goods < $minimum || $goods > $maximum) {
            throw new HttpException(422, '商品或运费累计退款超过对应实付');
        }
        return $goods;
    }

    private static function project(array $draft, bool $saleInPeriod, array $paid, array $before, array $after,
        ?int $channelFee, string $scope): array
    {
        $sales = $saleInPeriod ? $paid['order_paid'] : 0;
        $refund = $after['order_paid'] - $before['order_paid'];
        $required = ['platform_fee_enabled', 'channel_fee_payer'];
        if ($draft['platform_fee_enabled'] === true) {
            array_push($required, 'platform_fee_basis', 'platform_fee_rate', 'platform_fee_payer', 'refund_platform_fee');
        }
        $missing = [];
        foreach ($required as $field) {
            if ($draft[$field] === null) {
                $missing[] = $field;
            }
        }

        $fee = $saleInPeriod ? null : 0;
        $reversal = null;
        $basisUnknown = false;
        $basisKey = $draft['platform_fee_basis'];
        if ($draft['platform_fee_enabled'] === false) {
            $fee = 0;
            $reversal = 0;
        } elseif ($draft['platform_fee_enabled'] === true && $basisKey !== null && $draft['platform_fee_rate'] !== null) {
            $basis = $paid[$basisKey];
            $rate = (int)str_replace('.', '', $draft['platform_fee_rate']);
            $originalFee = intdiv($basis * $rate + 5000, 10000);
            if ($saleInPeriod) {
                $fee = $originalFee;
            }
            if ($draft['refund_platform_fee'] === 'none') {
                $reversal = 0;
            } elseif ($draft['refund_platform_fee'] === 'proportional') {
                if ($originalFee === 0 || $refund === 0) {
                    $reversal = 0;
                } elseif ($before[$basisKey] === null || $after[$basisKey] === null) {
                    $basisUnknown = true;
                } else {
                    $reversal = self::roundedReversal($originalFee, $basis, $after[$basisKey])
                        - self::roundedReversal($originalFee, $basis, $before[$basisKey]);
                }
            }
        }
        $netFee = $fee === null || $reversal === null ? null : $fee - $reversal;
        $receivable = null;
        if ($missing === [] && $netFee !== null && ($draft['channel_fee_payer'] === 'platform' || $channelFee !== null)) {
            $merchantPlatformFee = $draft['platform_fee_enabled'] === true && $draft['platform_fee_payer'] === 'merchant' ? $netFee : 0;
            $merchantChannelFee = $draft['channel_fee_payer'] === 'merchant' ? $channelFee : 0;
            $receivable = $sales - $refund - $merchantPlatformFee - $merchantChannelFee;
        }
        $format = static fn(?int $value): ?string => $value === null ? null : FinanceBillReconciliation::amount($value);
        $status = match (true) {
            $missing !== [] => 'RULE_INCOMPLETE',
            $basisUnknown => 'REFUND_BASIS_UNKNOWN',
            $channelFee === null => 'CHANNEL_FEE_UNKNOWN',
            default => 'CALCULATED_NOT_SETTLED',
        };
        return ['schema'=>1,'scope'=>$scope,'real_funds_enabled'=>false,'status'=>$status,
            'missing_fields'=>$missing,'sales_amount'=>$format($sales),'refund_amount'=>$format($refund),
            'channel_fee_amount'=>$format($channelFee),'platform_fee_amount'=>$format($fee),
            'platform_fee_reversal'=>$format($reversal),'platform_fee_net'=>$format($netFee),
            'merchant_receivable'=>$format($receivable),'settled_amount'=>null];
    }

    private static function roundedReversal(int $originalFee, int $basis, int $refundedBasis): int
    {
        // Two DECIMAL(12,2) cent amounts can exceed int64 when multiplied.
        $product = bcmul((string)$originalFee, (string)$refundedBasis, 0);
        return (int)bcdiv(bcadd($product, (string)intdiv($basis, 2), 0), (string)$basis, 0);
    }
}
