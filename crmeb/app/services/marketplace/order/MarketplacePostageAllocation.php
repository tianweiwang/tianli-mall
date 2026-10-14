<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/** Allocates an existing quote's postage budget; it does not produce a quote. */
final class MarketplacePostageAllocation
{
    /**
     * References and physical SKU identities must already be resolved by the caller.
     * Decimal strings prevent weak callers from silently coercing float amounts.
     *
     * @param mixed $totalPostage Nonnegative decimal string with at most two fraction digits.
     * @param array $lines List of cart_ref (int|string), sku_id (positive int|string),
     *                     and weight (nonnegative decimal string, at most six fraction digits).
     * @return array Same input order and identities, with two-decimal postage strings.
     */
    public static function allocate(mixed $totalPostage, array $lines): array
    {
        if (!is_string($totalPostage) || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $totalPostage) !== 1) {
            throw new InvalidArgumentException('Postage must be a nonnegative decimal string with at most two fraction digits.');
        }
        if (!array_is_list($lines)) {
            throw new InvalidArgumentException('Postage lines must be a list.');
        }

        $budget = bcmul($totalPostage, '100', 0);
        $weightTotal = '0';
        $allocation = [];
        $result = [];
        $identities = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line) || !array_key_exists('cart_ref', $line) || !array_key_exists('sku_id', $line)) {
                throw new InvalidArgumentException('Postage lines require cart and physical SKU references.');
            }
            $cartRef = $line['cart_ref'];
            if ((!is_string($cartRef) && !is_int($cartRef)) || (is_string($cartRef) ? $cartRef === '' : $cartRef < 1)) {
                throw new InvalidArgumentException('Postage lines require a cart reference.');
            }
            $skuId = $line['sku_id'];
            if ((!is_string($skuId) && !is_int($skuId)) || preg_match('/\A[0-9]+\z/D', (string)$skuId) !== 1 || bccomp((string)$skuId, '0', 0) <= 0) {
                throw new InvalidArgumentException('Postage lines require a positive physical SKU identity.');
            }
            $weight = $line['weight'] ?? null;
            if (!is_string($weight) || preg_match('/\A[0-9]+(?:\.[0-9]{1,6})?\z/D', $weight) !== 1) {
                throw new InvalidArgumentException('Postage weights must be nonnegative decimal strings with at most six fraction digits.');
            }

            $skuDecimal = bcadd((string)$skuId, '0', 0);
            $identity = strlen((string)$cartRef) . ':' . $cartRef . ':' . $skuDecimal;
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('Postage lines must have distinct cart and SKU references.');
            }
            $identities[$identity] = true;
            $weightUnits = bcmul($weight, '1000000', 0);
            $weightTotal = bcadd($weightTotal, $weightUnits, 0);
            $allocation[] = ['index' => $index, 'cart_ref' => (string)$cartRef, 'sku_id' => $skuDecimal, 'weight' => $weightUnits];
            $result[] = ['cart_ref' => $cartRef, 'sku_id' => $skuId, 'postage' => '0.00'];
        }

        if (bccomp($budget, '0', 0) === 0) {
            return $result;
        }
        if (bccomp($weightTotal, '0', 0) === 0) {
            throw new UnexpectedValueException('Positive postage requires the quote\'s allocation weights.');
        }

        $allocated = '0';
        $cents = [];
        foreach ($allocation as &$line) {
            $product = bcmul($budget, $line['weight'], 0);
            $cents[$line['index']] = bcdiv($product, $weightTotal, 0);
            $line['remainder'] = bcmod($product, $weightTotal, 0);
            $allocated = bcadd($allocated, $cents[$line['index']], 0);
        }
        unset($line);

        $remaining = bcsub($budget, $allocated, 0);
        // Each remainder is below W, so the unallocated cents are fewer than the lines.
        if (bccomp($remaining, '0', 0) < 0 || bccomp($remaining, (string)count($lines), 0) >= 0) {
            throw new LogicException('Postage allocation remainder exceeds its line bound.');
        }
        $remainingCount = (int)$remaining;
        if ($remainingCount > 0) {
            usort($allocation, static function (array $left, array $right): int {
                $remainder = bccomp($right['remainder'], $left['remainder'], 0);
                if ($remainder !== 0) {
                    return $remainder;
                }
                $cart = strcmp($left['cart_ref'], $right['cart_ref']);
                return $cart !== 0 ? $cart : bccomp($left['sku_id'], $right['sku_id'], 0);
            });
            for ($index = 0; $index < $remainingCount; ++$index) {
                $lineIndex = $allocation[$index]['index'];
                $cents[$lineIndex] = bcadd($cents[$lineIndex], '1', 0);
            }
        }
        foreach ($cents as $index => $amount) {
            $result[$index]['postage'] = bcdiv($amount, '100', 2);
        }
        return $result;
    }
}
