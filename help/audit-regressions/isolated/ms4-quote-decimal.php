<?php
declare(strict_types=1);

use app\services\marketplace\order\MarketplaceDecimal;

// Actual pure quote primitives only: no App, database, user, HTTP or payment fixture.
require dirname(__DIR__, 3) . '/crmeb/app/services/marketplace/order/MarketplaceDecimal.php';

$assertions = 0;
function decimalSame(string $expected, string $actual, string $label): void
{
    global $assertions;
    ++$assertions;
    if ($expected !== $actual) throw new RuntimeException('Decimal normal example failed: ' . $label);
}

decimalSame('5.35', MarketplaceDecimal::jsonNumber('5.35'), 'raw JSON decimal source');
decimalSame('5.35', MarketplaceDecimal::jsonNumber('"5.35"'), 'JSON decimal text source');
decimalSame('5.35', MarketplaceDecimal::jsonNumber('53.5e-1'), 'original JSON exponent text');
decimalSame('0.00000001', MarketplaceDecimal::jsonNumber('1e-8'), 'preserve original small threshold');
decimalSame('0.000000', MarketplaceDecimal::jsonNumber(null), 'missing configuration default');
decimalSame('1', MarketplaceDecimal::ceil(bcdiv('1.001', '1', 2)), 'existing BC2 quotient before ceil');
decimalSame('2', MarketplaceDecimal::ceil(bcdiv('1.01', '1', 2)), 'positive decimal ceil');
decimalSame('9007199254740993', MarketplaceDecimal::ceil('9007199254740993.00'), 'no native float integer loss');
decimalSame('90071992547409.93', bcmul(MarketplaceDecimal::value('9007199254740993'), '0.01', 2), 'decimal product');
decimalSame('5.35', MarketplaceDecimal::greatest(['5.34', '5.35', '0.00']), 'decimal maximum');
decimalSame('0.94', bcmul(bcdiv('90', '100', 2), MarketplaceDecimal::value('1.05'), 2), 'existing BC2 member truncation');
if (MarketplaceDecimal::compare('0.00000000', '0.00000001') >= 0) throw new RuntimeException('Small decimal threshold comparison failed.');
++ $assertions;
echo json_encode(['status' => 'NORMAL_DECIMAL_PRIMITIVES_PASS_NOT_API', 'assertions' => $assertions,
    'App_SQL_UID_HTTP_or_payment_executed' => false], JSON_THROW_ON_ERROR) . "\n";
