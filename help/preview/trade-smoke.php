<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

/**
 * Normal synthetic HTTP path only: logins -> product -> cart -> quote -> create -> merchant read.
 * No SQL business writes, payment, fulfillment, retries or acceptance-environment access.
 * The fixed seed's first product is 20.15, quantity two, free delivery: goods/payable 40.30.
 * Persist each mutation intent first. An uncertain create may only use its owner result lookup;
 * it is never submitted again. The receipt contains synthetic locators, never credentials/bodies.
 * A follow-up payment script must require stage=complete and normal_trade_http_verified=true.
 */
const PREVIEW_TRADE_FILE = '/workspace/crmeb/runtime/preview-trade.json';
$previewTradeStep = 'isolation';

final class PreviewTradeFailure extends RuntimeException
{
    public function __construct(public readonly string $field) { parent::__construct('Preview trade check failed'); }
}

function preview_trade_check(bool $ok, string $field): void
{
    if (!$ok) throw new PreviewTradeFailure($field);
}

function preview_trade_emit(array $facts): void
{
    echo json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function preview_trade_id(mixed $value, string $field): int
{
    preview_trade_check((is_int($value) || is_string($value))
        && preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$value) === 1
        && (int)$value <= 2147483647, $field);
    return (int)$value;
}

function preview_trade_cents(mixed $value, string $field): int
{
    preview_trade_check(is_string($value) || is_int($value) || (is_float($value) && is_finite($value)), $field . '.type');
    preview_trade_check(preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/D', (string)$value, $parts) === 1,
        $field . '.decimal');
    return (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
}

function preview_trade_save($stream, array $state): void
{
    $raw = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    preview_trade_check(rewind($stream) && ftruncate($stream, 0)
        && fwrite($stream, $raw) === strlen($raw) && fflush($stream) && fsync($stream), 'receipt.full_write');
}

function preview_trade_http(string $step, string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $previewTradeStep;
    $previewTradeStep = $step;
    preview_trade_check(in_array($method, ['GET', 'POST'], true) && str_starts_with($path, '/')
        && !str_contains($path, '://') && !str_contains($path, "\r") && !str_contains($path, "\n"), 'http.fixed_origin');
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init('http://preview-app:8000' . $path);
    preview_trade_check($curl !== false, 'http.handle');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
    // The empty owner-result lookup must be an object, not JSON [].
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl); $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $errno = curl_errno($curl);
    curl_close($curl);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $business = is_array($json) && is_int($json['status'] ?? null) ? $json['status'] : null;
    preview_trade_emit(['step' => $step, 'http' => $http, 'business_status' => $business, 'transport_errno' => $errno,
        'json_object' => is_array($json)]);
    preview_trade_check($errno === 0 && is_string($raw), 'http.transport');
    preview_trade_check($http === 200 && $business === 200, 'http.business_status');
    preview_trade_check(is_array($json['data'] ?? null), 'http.data');
    return $json['data'];
}

function preview_trade_login(string $role, array $credentials): string
{
    $path = ['admin' => '/adminapi/login', 'merchant' => '/merchantapi/login', 'customer' => '/api/login'][$role];
    preview_trade_check(is_string($credentials['account'] ?? null) && is_string($credentials['password'] ?? null), 'login.private_credentials');
    $body = ['account' => $credentials['account'], $role === 'admin' ? 'pwd' : 'password' => $credentials['password']];
    if ($role === 'customer') $body += ['spread' => 0, 'agent_id' => 0];
    $data = preview_trade_http('login_' . $role, 'POST', $path, $body);
    preview_trade_check(is_string($data['token'] ?? null) && $data['token'] !== '', 'login.token');
    return $data['token'];
}

function preview_trade_product(array $fixture, string $token, string $step): array
{
    $data = preview_trade_http($step, 'GET', '/api/product/detail/' . $fixture['product_id'], null, $token);
    $root = $data['storeInfo'] ?? null;
    preview_trade_check(is_array($root) && preview_trade_id($root['id'] ?? null, 'product.id') === $fixture['product_id']
        && preview_trade_id($root['mer_id'] ?? null, 'product.mer_id') === $fixture['merchant_id'], 'product.seed_identity');
    preview_trade_check(in_array($root['spec_type'] ?? null, [0, '0'], true)
        && ($data['spec_unique'] ?? null) === $fixture['sku_unique']
        && ($data['productAttr'] ?? null) === [] && ($data['productValue'] ?? null) === [], 'product.single_spec_contract');
    // V1 intentionally hides single-spec presentation values; the existing V2 API returns its real SKU.
    $attributes = preview_trade_http($step . '_sku', 'GET', '/api/v2/get_attr/' . $fixture['product_id'] . '/0', null, $token);
    $summary = $attributes['storeInfo'] ?? null;
    preview_trade_check(is_array($summary)
        && preview_trade_id($summary['id'] ?? null, 'sku_summary.id') === $fixture['product_id']
        && preview_trade_id($summary['mer_id'] ?? null, 'sku_summary.mer_id') === $fixture['merchant_id']
        && preview_trade_cents($summary['price'] ?? null, 'sku_summary.price') === 2015, 'sku_summary.seed_identity');
    preview_trade_check(is_array($attributes['productValue'] ?? null) && count($attributes['productValue']) === 1,
        'product.one_real_sku');
    $sku = null;
    foreach ($attributes['productValue'] as $candidate) {
        if (is_array($candidate) && ($candidate['unique'] ?? null) === $fixture['sku_unique']) {
            preview_trade_check($sku === null, 'product.unique_sku'); $sku = $candidate;
        }
    }
    preview_trade_check(is_array($sku) && preview_trade_id($sku['id'] ?? null, 'sku.id') === $fixture['sku_id']
        && preview_trade_id($sku['product_id'] ?? null, 'sku.product_id') === $fixture['product_id'], 'sku.seed_identity');
    preview_trade_check(preview_trade_cents($root['price'] ?? null, 'product.price') === 2015
        && preview_trade_cents($sku['price'] ?? null, 'sku.price') === 2015, 'product.seed_price_cents');
    foreach (['product_stock' => $root['stock'] ?? null, 'sku_summary_stock' => $summary['stock'] ?? null,
        'sku_stock' => $sku['stock'] ?? null] as $field => $value) {
        preview_trade_check((is_int($value) || is_string($value)) && ctype_digit((string)$value), $field . '.integer');
    }
    preview_trade_check((int)$summary['stock'] === (int)$root['stock'] && (int)$sku['stock'] === (int)$root['stock'],
        'product.single_sku_stock_consistency');
    return ['product_stock' => (int)$root['stock'], 'sku_stock' => (int)$sku['stock']];
}

function preview_trade_merchant_read(array &$state, $stream, string $token): void
{
    $data = preview_trade_http('merchant_order_list', 'GET', '/merchantapi/orders?page=1&limit=100', null, $token);
    preview_trade_check(is_array($data['list'] ?? null), 'merchant.list');
    $matches = array_values(array_filter($data['list'], static fn($row): bool =>
        is_array($row) && ($row['order_id'] ?? null) === $state['order_no']));
    preview_trade_check(count($matches) === 1, 'merchant.exact_created_order');
    $pk = preview_trade_id($matches[0]['id'] ?? null, 'merchant.order_pk');
    $state['order_id'] = $pk; preview_trade_save($stream, $state);
    $order = preview_trade_http('merchant_order_detail', 'GET', '/merchantapi/orders/' . $pk, null, $token);
    preview_trade_check(preview_trade_id($order['id'] ?? null, 'order.id') === $pk
        && ($order['order_id'] ?? null) === $state['order_no'] && ($order['marketplace_trade_version'] ?? null) === 1,
        'order.created_identity');
    preview_trade_check(($order['paid'] ?? null) === 0 && ($order['is_cancel'] ?? null) === 0
        && ($order['status'] ?? null) === 0 && ($order['total_num'] ?? null) === 2, 'order.unpaid_normal_state');
    foreach (['total_price' => 4030, 'pay_postage' => 0, 'pay_price' => 4030] as $field => $cents) {
        preview_trade_check(is_string($order[$field] ?? null)
            && preview_trade_cents($order[$field], 'order.' . $field) === $cents, 'order.' . $field);
    }
    $items = $order['items'] ?? null;
    preview_trade_check(is_array($items) && count($items) === 1, 'order.items.count');
    $item = $items[0];
    preview_trade_check(is_array($item), 'order.item.object');
    preview_trade_check(preview_trade_id($item['product_id'] ?? null, 'item.product_id') === $state['product_id']
        && ($item['quantity'] ?? null) === 2 && preview_trade_cents($item['unit_price'] ?? null, 'item.unit_price') === 2015
        && preview_trade_cents($item['line_price'] ?? null, 'item.line_price') === 4030
        && preview_trade_cents($item['postage_price'] ?? null, 'item.postage_price') === 0, 'order.item_amount_quantity');
    $state['order_item_id'] = preview_trade_id($item['id'] ?? null, 'item.id');
    preview_trade_save($stream, $state);
    preview_trade_emit(['step' => 'merchant_read_verified', 'order_id' => $pk, 'quantity' => 2, 'pay_cents' => 4030, 'paid' => 0]);
}

$stream = null; $state = null;
try {
    preview_trade_check(PHP_SAPI === 'cli', 'cli.only');
    preview_assert_isolated(); $config = preview_config();
    preview_trade_check(is_file('/run/preview-bootstrap/config.json') && is_array($config['credentials'] ?? null), 'bootstrap.synthetic_credentials_required');
    $initialized = json_decode(file_get_contents('/run/preview-app/initialized.json'), true, 64, JSON_THROW_ON_ERROR);
    preview_trade_check(($initialized['project'] ?? null) === $config['project']
        && ($initialized['run_id'] ?? null) === $config['run_id'] && ($initialized['result']['synthetic_only'] ?? null) === true,
        'initialization.identity');
    $fixture = $initialized['result']; $product = $fixture['products'][0] ?? null;
    preview_trade_check(is_array($product) && is_string($product['sku_unique'] ?? null)
        && preg_match('/\A[a-f0-9]{8}\z/D', $product['sku_unique']) === 1, 'fixture.sku');
    $plan = ['product_id' => preview_trade_id($product['product_id'] ?? null, 'fixture.product'),
        'sku_id' => preview_trade_id($product['sku_id'] ?? null, 'fixture.sku_id'), 'sku_unique' => $product['sku_unique'],
        'merchant_id' => preview_trade_id($fixture['merchant_id'] ?? null, 'fixture.merchant'),
        'customer_id' => preview_trade_id($fixture['customer_id'] ?? null, 'fixture.customer'),
        'address_id' => preview_trade_id($fixture['address_id'] ?? null, 'fixture.address')];
    $exists = file_exists(PREVIEW_TRADE_FILE);
    preview_trade_check(!is_link(PREVIEW_TRADE_FILE) && (!$exists || is_file(PREVIEW_TRADE_FILE)), 'receipt.regular');
    $stream = fopen(PREVIEW_TRADE_FILE, $exists ? 'r+b' : 'x+b');
    preview_trade_check(is_resource($stream) && flock($stream, LOCK_EX | LOCK_NB), 'receipt.exclusive');
    preview_trade_check(chmod(PREVIEW_TRADE_FILE, 0600), 'receipt.mode');
    if ($exists) {
        $raw = stream_get_contents($stream);
        $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        preview_trade_check(is_array($state) && ($state['schema'] ?? null) === 1
            && ($state['project'] ?? null) === $config['project'] && ($state['run_id'] ?? null) === $config['run_id'], 'receipt.identity');
        foreach ($plan as $key => $value) preview_trade_check(($state[$key] ?? null) === $value, 'receipt.fixture_identity');
        if (($state['stage'] ?? null) === 'complete') {
            preview_trade_emit(['status' => 'ALREADY_COMPLETE_NO_CREATION', 'order_id' => $state['order_id'], 'pay_cents' => 4030]);
            exit(0);
        }
        preview_trade_check(in_array($state['stage'] ?? null, ['new', 'cart_added', 'confirmed', 'create_attempted', 'order_created'], true),
            'receipt.previous_request_result_unknown_no_repeat');
    } else {
        $state = ['schema' => 1, 'project' => $config['project'], 'run_id' => $config['run_id'], 'stage' => 'new',
            'quantity' => 2, 'unit_cents' => 2015, 'goods_cents' => 4030, 'postage_cents' => 0, 'pay_cents' => 4030] + $plan;
        preview_trade_save($stream, $state);
    }
    $tokens = [];
    foreach (['admin', 'merchant', 'customer'] as $role) $tokens[$role] = preview_trade_login($role, $config['credentials'][$role] ?? []);
    unset($config['credentials']);
    $me = preview_trade_http('merchant_identity', 'GET', '/merchantapi/me', null, $tokens['merchant']);
    preview_trade_check(preview_trade_id($me['merchant']['mer_id'] ?? null, 'merchant.me.mer_id') === $state['merchant_id'], 'merchant.seed_identity');
    if ($state['stage'] === 'new') {
        $state['stock_before'] = preview_trade_product($plan, $tokens['customer'], 'product_before');
        preview_trade_check($state['stock_before']['product_stock'] >= 2 && $state['stock_before']['sku_stock'] >= 2, 'product.available_quantity');
        $cart = preview_trade_http('cart_before', 'GET', '/api/cart/list?page=1&limit=20', null, $tokens['customer']);
        preview_trade_check(($cart['valid'] ?? null) === [] && ($cart['invalid'] ?? null) === [], 'cart.original_empty');
        $state['stage'] = 'cart_attempted'; preview_trade_save($stream, $state);
        $added = preview_trade_http('cart_add', 'POST', '/api/cart/add', ['productId' => $state['product_id'], 'cartNum' => 2,
            'uniqueId' => $state['sku_unique'], 'new' => 0, 'is_new' => 0], $tokens['customer']);
        $state['cart_id'] = (string)preview_trade_id($added['cartId'] ?? null, 'cart.id');
        $state['stage'] = 'cart_added'; preview_trade_save($stream, $state);
    }
    if ($state['stage'] === 'cart_added') {
        $cart = preview_trade_http('cart_read', 'GET', '/api/cart/list?page=1&limit=20', null, $tokens['customer']);
        preview_trade_check(count($cart['valid'] ?? []) === 1 && ($cart['invalid'] ?? null) === [] && count($cart['groups'] ?? []) === 1, 'cart.one_valid_single_store');
        $row = $cart['valid'][0]; $group = $cart['groups'][0];
        preview_trade_check((string)($row['id'] ?? '') === $state['cart_id']
            && preview_trade_id($row['product_id'] ?? null, 'cart.product') === $state['product_id'] && (int)($row['cart_num'] ?? 0) === 2
            && preview_trade_id($group['mer_id'] ?? null, 'cart.mer_id') === $state['merchant_id']
            && ($group['cart_refs'] ?? null) === [$state['cart_id']], 'cart.selection');
        $state['stage'] = 'confirm_attempted'; preview_trade_save($stream, $state);
        $quote = preview_trade_http('confirm_order', 'POST', '/api/order/confirm', ['cartId' => $state['cart_id'],
            'mer_id' => $state['merchant_id'], 'addressId' => $state['address_id'], 'new' => 0, 'shipping_type' => 1, 'is_gift' => 0], $tokens['customer']);
        preview_trade_check(($quote['marketplace_confirmation_version'] ?? null) === 1 && ($quote['marketplace_create_enabled'] ?? null) === true
            && preview_trade_id($quote['mer_id'] ?? null, 'quote.mer_id') === $state['merchant_id']
            && ($quote['source_kind'] ?? null) === 'persistent', 'quote.single_store_contract');
        preview_trade_check(is_string($quote['orderKey'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/D', $quote['orderKey']) === 1, 'quote.key');
        preview_trade_check(is_string($quote['priceGroup']['totalPrice'] ?? null)
            && preview_trade_cents($quote['priceGroup']['totalPrice'], 'quote.totalPrice') === 4030, 'quote.goods_amount');
        $state['key'] = $quote['orderKey']; $state['idem_key'] = 'preview-trade:' . $state['run_id'];
        $state['stage'] = 'confirmed'; preview_trade_save($stream, $state);
    }
    if ($state['stage'] === 'confirmed') {
        $computed = preview_trade_http('compute_order', 'POST', '/api/order/computed/' . rawurlencode($state['key']),
            ['addressId' => $state['address_id'], 'shipping_type' => 1, 'payType' => '', 'mer_id' => $state['merchant_id'],
                'couponId' => 0, 'useIntegral' => 0], $tokens['customer']);
        preview_trade_check(($computed['status'] ?? null) === 'NONE', 'computed.status');
        foreach (['total_price' => 4030, 'pay_postage' => 0, 'pay_price' => 4030] as $field => $cents) {
            preview_trade_check(is_string($computed['result'][$field] ?? null)
                && preview_trade_cents($computed['result'][$field], 'computed.' . $field) === $cents, 'computed.' . $field);
        }
        $state['stage'] = 'create_attempted'; preview_trade_save($stream, $state);
        $created = preview_trade_http('create_order', 'POST', '/api/order/create/' . rawurlencode($state['key']),
            ['cartId' => $state['cart_id'], 'mer_id' => $state['merchant_id'], 'new' => 0, 'idem_key' => $state['idem_key'],
                'addressId' => $state['address_id'], 'invoice_id' => 0, 'payType' => '', 'shipping_type' => 1,
                'is_gift' => 0, 'couponId' => 0, 'useIntegral' => false, 'custom_form' => []], $tokens['customer']);
        preview_trade_check(($created['status'] ?? null) === 'SUCCESS' && ($created['result']['key'] ?? null) === $state['key'], 'create.response');
        $state['order_no'] = $created['result']['orderId'] ?? null;
    } elseif ($state['stage'] === 'create_attempted') {
        $result = preview_trade_http('lookup_original_creation', 'POST', '/api/order/confirmation/' . rawurlencode($state['key']), [], $tokens['customer']);
        preview_trade_check(($result['key'] ?? null) === $state['key'] && ($result['state'] ?? null) === 'created', 'creation.outcome_pending_no_resubmit');
        $state['order_no'] = $result['orderId'] ?? null;
    }
    preview_trade_check(is_string($state['order_no'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/D', $state['order_no']) === 1, 'create.order_no');
    $state['stage'] = 'order_created'; preview_trade_save($stream, $state);
    preview_trade_merchant_read($state, $stream, $tokens['merchant']);
    $after = preview_trade_product($plan, $tokens['customer'], 'product_after');
    preview_trade_check($after['product_stock'] === $state['stock_before']['product_stock'] - 2
        && $after['sku_stock'] === $state['stock_before']['sku_stock'] - 2, 'stock.exact_order_reservation');
    $state['stock_after'] = $after; $state['stage'] = 'complete'; $state['normal_trade_http_verified'] = true;
    $state['payment_called'] = false; $state['shipment_called'] = false; preview_trade_save($stream, $state);
    preview_trade_emit(['status' => 'PASS', 'order_id' => $state['order_id'], 'quantity' => 2, 'goods_cents' => 4030,
        'postage_cents' => 0, 'pay_cents' => 4030, 'stock_delta' => -2, 'payment_called' => false, 'shipment_called' => false]);
} catch (Throwable $error) {
    preview_trade_emit(['status' => 'FAIL', 'step' => $previewTradeStep,
        'field' => $error instanceof PreviewTradeFailure ? $error->field : 'unexpected_source_error',
        'error_type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    exit(1);
} finally {
    if (is_resource($stream)) { flock($stream, LOCK_UN); fclose($stream); }
}
