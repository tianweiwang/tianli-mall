<?php
declare(strict_types=1);

use app\services\marketplace\order\MarketplacePostageAllocation;

// Pure normal decimal examples only; no framework, database, API or payment setup.
$source = dirname(__DIR__, 3) . '/crmeb/app/services/marketplace/order/MarketplacePostageAllocation.php';
if (!is_file($source)) {
    fwrite(STDERR, "FAIL: MarketplacePostageAllocation implementation is missing\n");
    exit(1);
}
require $source;

$assertions = 0;
$invocations = 0;
function same(mixed $expected, mixed $actual, string $label): void
{
    global $assertions;
    ++$assertions;
    if ($actual !== $expected) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}

function allocation(string $case, string $total, array $lines, array $postages): array
{
    global $invocations;
    ++$invocations;
    $expected = [];
    foreach ($lines as $index => $line) {
        $expected[] = ['cart_ref' => $line['cart_ref'], 'sku_id' => $line['sku_id'], 'postage' => $postages[$index]];
    }
    $actual = MarketplacePostageAllocation::allocate($total, $lines);
    same($expected, $actual, $case . ' exact allocation and original identities/order');
    $sum = '0.00';
    $nonnegative = true;
    foreach ($actual as $line) {
        $sum = bcadd($sum, $line['postage'], 2);
        $nonnegative = $nonnegative && bccomp($line['postage'], '0', 2) >= 0;
    }
    same(bcadd($total, '0', 2), $sum, $case . ' sum equals original budget');
    same(true, $nonnegative, $case . ' every line is nonnegative');
    return $actual;
}

try {
    allocation('half_cent', '5.35', [
        ['cart_ref' => '10', 'sku_id' => 101, 'weight' => '2.675000'],
        ['cart_ref' => '2', 'sku_id' => 102, 'weight' => '2.675000'],
    ], ['2.68', '2.67']);

    allocation('three_cents_five_lines', '0.03', [
        ['cart_ref' => 'cart:01', 'sku_id' => '201', 'weight' => '1'],
        ['cart_ref' => 'cart:02', 'sku_id' => '202', 'weight' => '1'],
        ['cart_ref' => 'cart:03', 'sku_id' => '203', 'weight' => '1'],
        ['cart_ref' => 'cart:04', 'sku_id' => '204', 'weight' => '1'],
        ['cart_ref' => 'cart:05', 'sku_id' => '205', 'weight' => '1'],
    ], ['0.01', '0.01', '0.01', '0.00', '0.00']);

    allocation('zero_budget', '0.00', [
        ['cart_ref' => 31, 'sku_id' => 301, 'weight' => '0'],
        ['cart_ref' => '032', 'sku_id' => '302', 'weight' => '0.000000'],
        ['cart_ref' => 'temporary-cart:33', 'sku_id' => 303, 'weight' => '0'],
    ], ['0.00', '0.00', '0.00']);

    allocation('unequal_weights', '12.34', [
        ['cart_ref' => '41', 'sku_id' => 401, 'weight' => '1'],
        ['cart_ref' => '42', 'sku_id' => 402, 'weight' => '2'],
        ['cart_ref' => '43', 'sku_id' => 403, 'weight' => '3'],
    ], ['2.06', '4.11', '6.17']);

    // The existing expression keeps per-line BC6 and cumulative BC4 values.
    $firstWeight = bcmul('1.00', bcdiv('1', '3', 6), 6);
    $cumulative = bcadd(bcadd('0', $firstWeight, 4), $firstWeight, 4);
    $lastWeight = bcsub('1.00', $cumulative, 6);
    same(['0.333333', '0.6666', '0.333400'], [$firstWeight, $cumulative, $lastWeight], 'original BC6/BC4 weight source');
    allocation('original_precision_difference', '1.00', [
        ['cart_ref' => '51', 'sku_id' => 501, 'weight' => $firstWeight],
        ['cart_ref' => '52', 'sku_id' => 502, 'weight' => $firstWeight],
        ['cart_ref' => '53', 'sku_id' => 503, 'weight' => $lastWeight],
    ], ['0.33', '0.33', '0.34']);

    allocation('product_beyond_native_integer', '21474836.47', [
        ['cart_ref' => '61', 'sku_id' => '601', 'weight' => '1000000.000001'],
        ['cart_ref' => '62', 'sku_id' => '602', 'weight' => '1000000.000002'],
    ], ['10737418.23', '10737418.24']);

    $stableLines = [
        ['cart_ref' => 'temporary-cart:71', 'sku_id' => '10', 'weight' => '1'],
        ['cart_ref' => 'temporary-cart:71', 'sku_id' => '2', 'weight' => '1'],
        ['cart_ref' => 'temporary-cart:72', 'sku_id' => 703, 'weight' => '1'],
    ];
    $original = allocation('stable_sku_tie', '0.01', $stableLines, ['0.00', '0.01', '0.00']);
    foreach ([[2, 0, 1], [1, 2, 0]] as $order) {
        $lines = [];
        $expectedPostages = [];
        foreach ($order as $index) {
            $lines[] = $stableLines[$index];
            $expectedPostages[] = $original[$index]['postage'];
        }
        allocation('reordered_identity_allocation', '0.01', $lines, $expectedPostages);
    }
    fwrite(STDOUT, 'PASS: 7 normal scenarios, ' . $invocations . ' allocations, ' . $assertions . " assertions\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
