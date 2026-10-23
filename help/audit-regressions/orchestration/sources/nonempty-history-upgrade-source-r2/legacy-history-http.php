<?php
declare(strict_types=1);

/**
 * Complete-477 normal HTTP library, definitions only. No App/bootstrap/SQL/files
 * writer, provider callback, token issuer, runner, retry or resource lifecycle.
 *
 * nhe_history_http_run($qualified, $origin, $runId, $actors, $fixture, $before, $after)
 * requires the caller's actual nhe_qualify state and two real observer closures
 * defined in the same frozen /run/nonempty/ producer source. Neither observer is
 * supplied here. A missing observer, boolean acknowledgement or empty snapshot
 * is rejected before any HTTP. The outer producer owns independent row/default/
 * native-ID/time models, all Redis/files/log effects, real Channel delivery and
 * final normal child retirement. This library never returns business acceptance.
 *
 * PRIVATE observer API (do not serialize arguments):
 *   before(intent, privateRequest) -> full seven-part BEFORE snapshot.
 *   after(intent, before, privateRequest, privateResponse)
 *       -> ['before_sha256'=>sha256(serialize(before)),
 *           'expected'=>independently modelled full snapshot,
 *           'actual'=>actual full snapshot].
 * Seven parts: database, rows, redis, private, public, logs, native.
 * privateResponse.headers retains original callback lines and blank-line-delimited
 * blocks (terminators included), bytes, complete and limit_exceeded in RAM only.
 * The 64-KiB bound aborts curl before accepting an overflowing callback line;
 * after still receives the actual transport failure and explicitly partial capture.
 * local_ip/local_port are curl's actual local endpoint, never inferred from origin.
 * database retains the complete original names/tables/modes/metadata/DDL/full
 * row projections; rows retain the actual original mutable business rows.
 * native.http and native.channel are actual owned live proc_get_status facts.
 * The real before observer also binds intent.origin to that HTTP child's actual
 * owned listening socket; a syntactically valid loopback URL alone is no proof.
 * expected is not an alias/copy of after; the frozen observer source is reviewed
 * separately. The library additionally checks complete expected===actual.
 * The observer binds privateRequest.actor_identity to the full original actor
 * row and the real admin AuthV2 / customer JWT+SessionWhitelist subject; public DTOs need not
 * expose hidden account fields. Those private facts never become public proof.
 * Raw authorization, credentials, bodies, profiles and observer snapshots stay
 * in private memory only; the final result contains business IDs/states only.
 */

function nheh_expect(bool $value, string $code): void
{
    if (!$value) throw new RuntimeException($code);
}

function nheh_id(mixed $value, bool $zero = false): int
{
    nheh_expect(is_int($value) || is_string($value), 'NHEH_NATIVE_INTEGER_TYPE');
    nheh_expect(preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,9})\z/D' : '/\A[1-9][0-9]{0,9}\z/D', (string)$value) === 1
        && (int)$value <= 4294967295, 'NHEH_NATIVE_INTEGER_RANGE');
    return (int)$value;
}

function nheh_opaque(mixed $value): string
{
    nheh_expect(is_string($value) && preg_match('/\A[A-Za-z0-9]{1,128}\z/D', $value) === 1,
        'NHEH_ACTUAL_BUSINESS_NUMBER_OR_UNIQUE');
    return $value;
}

function nheh_money(mixed $value, string $expected): void
{
    nheh_expect(is_string($value) || is_int($value) || is_float($value), 'NHEH_ORIGINAL_PUBLIC_AMOUNT_TYPE');
    $text = (string)$value;
    nheh_expect(preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D', $text, $parts) === 1,
        'NHEH_ORIGINAL_PUBLIC_DECIMAL');
    $canonical = $parts[1] . '.' . str_pad($parts[2] ?? '', 2, '0');
    nheh_expect($canonical === $expected, 'NHEH_FIXED_NORMAL_AMOUNT_MATCHES_ACTUAL_RESPONSE');
}

function nheh_origin(string $origin): int
{
    nheh_expect(preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{3,4})\z~D', $origin, $match) === 1
        && (int)$match[1] >= 1024 && (int)$match[1] <= 65535, 'NHEH_EXPLICIT_OWNED_LOOPBACK_ORIGIN');
    return (int)$match[1];
}

function nheh_observers(array $qualified, string $runId, Closure $before, Closure $after): void
{
    nheh_expect(function_exists('nhe_file') && ($qualified['context']['run_id'] ?? null) === $runId
        && ($qualified['authority']['legacy_source_sha'] ?? null) === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
        && is_array($qualified['freeze_pin'] ?? null), 'NHEH_REAL_QUALIFIED_SOURCE_AND_OBSERVERS_REQUIRED');
    $raw = nhe_file('/run/audit/preparation-source-binding.json', $qualified['freeze_pin']);
    $freeze = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    nheh_expect(is_array($freeze) && ($freeze['status'] ?? null) === 'CLEAN_SOURCE_FROZEN_ONLY'
        && ($freeze['nonempty_history'] ?? null) === $qualified['authority']
        && ($qualified['context']['freeze_sha256'] ?? null) === $qualified['freeze_pin']['sha256'],
        'NHEH_ACTUAL_FROZEN_OBSERVER_AUTHORITY');
    $a = new ReflectionFunction($before); $b = new ReflectionFunction($after);
    $path = $a->getFileName();
    nheh_expect(!$a->isInternal() && !$b->isInternal() && is_string($path) && $path !== __FILE__
        && str_starts_with($path, '/run/nonempty/') && $b->getFileName() === $path,
        'NHEH_REAL_SAME_FROZEN_PRODUCER_CLOSURES');
    $controls = array_column($qualified['authority']['control_files'], null, 'path');
    nheh_expect(isset($controls[$path], $controls[__FILE__]), 'NHEH_OBSERVER_AND_HTTP_LIBRARY_IN_ACTUAL_FREEZE');
    nhe_file($path, $controls[$path]); nhe_file(__FILE__, $controls[__FILE__]);
}

function nheh_snapshot(array $snapshot): void
{
    $keys = array_keys($snapshot); sort($keys, SORT_STRING);
    nheh_expect($keys === ['database', 'logs', 'native', 'private', 'public', 'redis', 'rows'],
        'NHEH_FULL_SEVEN_PART_NATIVE_OBSERVATION');
    foreach ($snapshot as $part) nheh_expect(is_array($part), 'NHEH_NO_BOOLEAN_OBSERVER_ACK');
    $database = $snapshot['database'];
    nheh_expect(isset($database['names'], $database['tables'], $database['modes'])
        && is_array($database['names']) && array_is_list($database['names']) && count($database['names']) === 164
        && is_array($database['tables']) && is_array($database['modes']) && $database['modes'] !== [],
        'NHEH_COMPLETE_ORIGINAL_164_TABLE_OBSERVATION');
    $names = $database['names']; sort($names, SORT_STRING);
    nheh_expect($names === $database['names'] && count(array_unique($names, SORT_STRING)) === count($names),
        'NHEH_COMPLETE_SORTED_UNIQUE_ORIGINAL_TABLE_NAMES');
    $tables = array_keys($database['tables']); sort($tables, SORT_STRING);
    nheh_expect($tables === $names, 'NHEH_NO_OMITTED_TABLE_METADATA_DDL_OR_PROJECTION');
    foreach ($database['tables'] as $table) nheh_expect(is_array($table) && isset($table['metadata'], $table['projection'])
        && is_array($table['metadata']) && $table['metadata'] !== [] && is_array($table['projection'])
        && $table['projection'] !== [] && (isset($table['ddl']) || isset($table['ddl_sha256'])),
        'NHEH_FULL_ORIGINAL_NATIVE_TABLE_FACTS');
    foreach (['eb_user', 'eb_system_admin', 'eb_store_cart', 'eb_store_order', 'eb_store_order_cart_info',
        'eb_store_order_refund', 'eb_store_product_reply', 'eb_store_product', 'eb_store_product_attr_value',
        'eb_marketplace_merchant', 'eb_marketplace_audit_log'] as $table) {
        nheh_expect(array_key_exists($table, $snapshot['rows']) && is_array($snapshot['rows'][$table])
            && array_is_list($snapshot['rows'][$table]), 'NHEH_REAL_MUTABLE_BUSINESS_ROW_OBSERVATION');
    }
    foreach (['http', 'channel'] as $child) {
        $native = $snapshot['native'][$child] ?? null;
        nheh_expect(is_array($native) && is_int($native['pid'] ?? null) && $native['pid'] > 0
            && ($native['running'] ?? null) === true && ($native['signaled'] ?? null) === false
            && ($native['stopped'] ?? null) === false && ($native['exitcode'] ?? null) === -1,
            'NHEH_ACTUAL_OWN_HTTP_AND_CHANNEL_CHILD_STILL_RUNNING');
    }
}

/** Private transport with a finite original-route allowlist, no arbitrary destination. */
function nheh_step(array &$state, string $step, string $method, string $path, string $actor,
    #[SensitiveParameter] ?string $token = null, #[SensitiveParameter] ?array $body = null): array
{
    $fixed = ['POST /adminapi/login', 'GET /adminapi/setting/info', 'POST /api/login', 'GET /api/userinfo',
        'POST /api/cart/add', 'POST /api/order/confirm', 'POST /api/order/pay', 'POST /api/order/take',
        'POST /api/order/comment', 'GET /api/order/refund/list', 'GET /adminapi/product/reply'];
    $leaf = $method . ' ' . explode('?', $path, 2)[0]; $allowed = in_array($leaf, $fixed, true);
    foreach ([
        '~\APUT /adminapi/marketplace/merchants/[1-9][0-9]*/review\z~',
        '~\APUT /adminapi/marketplace/merchants/[1-9][0-9]*/flags\z~',
        '~\APUT /adminapi/user/update_other/[1-9][0-9]*\z~',
        '~\APOST /api/order/create/[A-Za-z0-9]+\z~', '~\AGET /api/order/detail/[A-Za-z0-9]+\z~',
        '~\APOST /api/order/refund/apply/[1-9][0-9]*\z~', '~\AGET /api/order/refund/detail/[A-Za-z0-9]+\z~',
        '~\APUT /adminapi/refund/refund/[1-9][0-9]*\z~', '~\APUT /adminapi/order/delivery/[1-9][0-9]*\z~',
        '~\APUT /adminapi/product/reply/set_status/[1-9][0-9]*/1\z~',
        '~\APUT /adminapi/product/reply/set_reply/[1-9][0-9]*\z~',
    ] as $pattern) if (preg_match($pattern, $leaf) === 1) $allowed = true;
    nheh_expect($allowed && preg_match('/\A[a-z][a-z0-9_]{1,79}\z/D', $step) === 1
        && in_array($actor, ['admin', 'customer'], true) && strlen($path) < 2048
        && !str_contains($path, '..') && !str_contains($path, '\\') && !str_contains($path, '%')
        && !str_contains($path, '#') && !str_contains($path, "\r") && !str_contains($path, "\n"),
        'NHEH_ONLY_SELECTED_NORMAL_477_ROUTES');
    if (str_contains($path, '?')) {
        parse_str(explode('?', $path, 2)[1], $query);
        nheh_expect(array_diff(array_keys($query), ['product_id', 'page', 'limit']) === []
            && count($query) <= 3, 'NHEH_ONLY_FIXED_NORMAL_LIST_QUERY');
        foreach ($query as $value) nheh_id($value);
    }
    nheh_expect(($method === 'GET') === ($body === null), 'NHEH_EXACT_NORMAL_REQUEST_BODY_METHOD');
    if ($token !== null) nheh_expect(preg_match('/\A[A-Za-z0-9._~-]{16,4096}\z/D', $token) === 1,
        'NHEH_PRIVATE_REAL_LOGIN_TOKEN_SHAPE');
    nheh_expect(($token === null) === in_array($leaf, ['POST /adminapi/login', 'POST /api/login'], true),
        'NHEH_NO_UNAUTHENTICATED_BUSINESS_CALL');
    $intent = ['run_id' => $state['run_id'], 'sequence' => count($state['steps']) + 1,
        'step' => $step, 'origin' => $state['origin'], 'method' => $method, 'path' => $path, 'actor' => $actor,
        'actor_id' => $state['actor_ids'][$actor]];
    // The envelope is private, including login bodies and authorization. Never log it.
    $request = ['token' => $token, 'body' => $body, 'actor_identity' => [
        'id' => $state['actor_ids'][$actor], 'account' => $state['actor_accounts'][$actor]]];
    $before = ($state['before'])($intent, $request);
    nheh_expect(is_array($before), 'NHEH_REAL_BEFORE_OBSERVER_REQUIRED'); nheh_snapshot($before);
    $bindNativeActor = static function (array $observed) use ($actor, $request): void {
        $table = $actor === 'admin' ? 'eb_system_admin' : 'eb_user'; $pk = $actor === 'admin' ? 'id' : 'uid';
        $matches = [];
        foreach ($observed['rows'][$table] as $row) if (nheh_id($row[$pk] ?? null) === $request['actor_identity']['id'])
            $matches[] = $row;
        nheh_expect(count($matches) === 1 && ($matches[0]['account'] ?? null) === $request['actor_identity']['account'],
            'NHEH_PRIVATE_ORIGINAL_ACTOR_ID_AND_ACCOUNT_BINDING');
    };
    $bindNativeActor($before);
    $headers = ['Cb-lang: zh-CN', 'Form-type: h5'];
    if ($token !== null) $headers[] = 'Authori-zation: Bearer ' . $token;
    $wire = null;
    if ($body !== null) {
        $wire = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }
    $curl = curl_init($state['origin'] . $path); nheh_expect($curl instanceof CurlHandle, 'NHEH_REAL_CURL_HANDLE');
    $start = time(); $raw = false; $transportFailure = null;
    $headerLines = []; $headerBlocks = []; $headerBlock = []; $headerBytes = 0; $headerLimitExceeded = false;
    try {
        nheh_expect(curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (
                &$headerLines, &$headerBlocks, &$headerBlock, &$headerBytes, &$headerLimitExceeded): int {
                $length = strlen($line);
                if ($length > 65536 - $headerBytes) { $headerLimitExceeded = true; return 0; }
                $headerBytes += $length; $headerLines[] = $line; $headerBlock[] = $line;
                if ($line === "\r\n" || $line === "\n") { $headerBlocks[] = $headerBlock; $headerBlock = []; }
                return $length;
            }]),
            'NHEH_FIXED_DIRECT_LOOPBACK_TRANSPORT');
        if ($wire !== null) nheh_expect(curl_setopt($curl, CURLOPT_POSTFIELDS, $wire), 'NHEH_REAL_REQUEST_BYTES');
        $raw = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $ip = curl_getinfo($curl, CURLINFO_PRIMARY_IP); $port = (int)curl_getinfo($curl, CURLINFO_PRIMARY_PORT);
        $localIp = curl_getinfo($curl, CURLINFO_LOCAL_IP); $localPort = curl_getinfo($curl, CURLINFO_LOCAL_PORT);
        $errno = curl_errno($curl);
    } catch (Throwable $error) { $transportFailure = get_class($error); }
    finally { curl_close($curl); }
    $end = time();
    if ($headerBlock !== []) $headerBlocks[] = $headerBlock;
    $response = ['http' => $http ?? 0, 'raw' => is_string($raw) ? $raw : null, 'json' => null,
        'actual_interval' => [$start, $end], 'transport_errno' => $errno ?? -1,
        'primary_ip' => $ip ?? null, 'primary_port' => $port ?? 0, 'transport_failure_class' => $transportFailure,
        'headers' => ['lines' => $headerLines, 'blocks' => $headerBlocks, 'bytes' => $headerBytes,
            'complete' => !$headerLimitExceeded && ($errno ?? -1) === 0 && is_string($raw),
            'limit_exceeded' => $headerLimitExceeded], 'local_ip' => $localIp ?? null, 'local_port' => $localPort ?? null];
    if (is_string($raw) && strlen($raw) <= 6291456) $response['json'] = json_decode($raw, true);
    // Also observe failed transport/business results once; no retry or alternate route.
    $proof = ($state['after'])($intent, $before, $request, $response);
    nheh_expect(is_array($proof), 'NHEH_REAL_AFTER_OBSERVER_REQUIRED');
    $keys = array_keys($proof); sort($keys, SORT_STRING);
    nheh_expect($keys === ['actual', 'before_sha256', 'expected']
        && ($proof['before_sha256'] ?? null) === hash('sha256', serialize($before))
        && is_array($proof['expected']) && is_array($proof['actual']), 'NHEH_BOUND_INDEPENDENT_FULL_AFTER_ORACLE');
    nheh_snapshot($proof['expected']); nheh_snapshot($proof['actual']); $bindNativeActor($proof['actual']);
    foreach (['http', 'channel'] as $child) nheh_expect(
        $proof['actual']['native'][$child]['pid'] === $before['native'][$child]['pid'], 'NHEH_SAME_OWNED_NATIVE_CHILD');
    nheh_expect($proof['expected'] === $proof['actual'], 'NHEH_COMPLETE_EXPECTED_NATIVE_DELTA');
    nheh_expect($transportFailure === null && ($errno ?? -1) === 0 && is_string($raw) && strlen($raw) <= 6291456
        && ($http ?? 0) === 200 && ($ip ?? null) === '127.0.0.1' && ($port ?? 0) === $state['port'],
        'NHEH_REAL_NO_REDIRECT_NORMAL_HTTP_200');
    $json = $response['json'];
    nheh_expect(is_array($json) && !array_is_list($json) && ($json['status'] ?? null) === 200
        && is_string($json['msg'] ?? null), 'NHEH_REAL_ORIGINAL_JSON_SUCCESS');
    $state['steps'][] = ['step' => $step, 'http' => $http];
    unset($before, $proof, $request, $bindNativeActor, $headers, $wire, $response, $raw, $token, $body,
        $headerLines, $headerBlocks, $headerBlock);
    return $json;
}

function nheh_data(array $json): array
{
    nheh_expect(isset($json['data']) && is_array($json['data']), 'NHEH_ACTUAL_ORIGINAL_DATA_ARRAY');
    return $json['data'];
}

function nheh_root(array $json, array $fixture, string $number, int $paid, int $status): array
{
    $data = nheh_data($json);
    nheh_expect(nheh_id($data['id'] ?? null) > 0 && ($data['order_id'] ?? null) === $number
        && nheh_id($data['uid'] ?? null) === $fixture['uid'] && nheh_id($data['pid'] ?? null, true) === 0
        && nheh_id($data['paid'] ?? null, true) === $paid
        && in_array($data['status'] ?? null, [$status, (string)$status], true)
        && nheh_id($data['shipping_type'] ?? null) === 1 && nheh_id($data['total_num'] ?? null) === 1,
        'NHEH_REAL_OWN_ORIGINAL_ONE_ITEM_ROOT_STATE');
    nheh_money($data['pay_price'] ?? null, '20.15'); nheh_money($data['pay_postage'] ?? null, '0.00');
    $rows = $data['cartInfo'] ?? null;
    nheh_expect(is_array($rows) && array_is_list($rows) && count($rows) === 1, 'NHEH_ONE_REAL_SAVED_ORDER_ITEM');
    $item = $rows[0]; $sku = $item['productInfo']['attrInfo'] ?? null;
    nheh_expect(is_array($sku) && nheh_id($item['product_id'] ?? null) === $fixture['product_id']
        && nheh_id($item['cart_num'] ?? null) === 1 && nheh_id($sku['id'] ?? null) === $fixture['sku_id']
        && ($sku['unique'] ?? null) === $fixture['sku_unique'] && ($sku['suk'] ?? null) === $fixture['sku_suk'],
        'NHEH_ORIGINAL_CART_SKU_IDENTITY_NOT_GUESSED');
    return ['id' => nheh_id($data['id']), 'order_no' => $number,
        'order_unique' => nheh_opaque($data['unique'] ?? null), 'item_unique' => nheh_opaque($item['unique'] ?? null),
        'cart_id' => nheh_id($item['id'] ?? null), 'paid' => nheh_id($data['paid'], true),
        'status' => (int)$data['status'], 'refund_status' => nheh_id($data['refund_status'] ?? null, true)];
}

function nheh_buy(array &$state, string $family, string $token, array $fixture): array
{
    $cart = nheh_data(nheh_step($state, $family . '_cart', 'POST', '/api/cart/add', 'customer', $token, [
        'productId' => $fixture['product_id'], 'cartNum' => 1, 'uniqueId' => $fixture['sku_unique'],
        'new' => 0, 'is_new' => 0, 'combinationId' => 0, 'secKillId' => 0, 'bargainId' => 0, 'advanceId' => 0, 'pinkId' => 0]));
    $cartId = nheh_id($cart['cartId'] ?? null);
    $confirm = nheh_data(nheh_step($state, $family . '_confirm', 'POST', '/api/order/confirm', 'customer', $token,
        ['cartId' => (string)$cartId, 'new' => 0, 'addressId' => $fixture['address_id'], 'shipping_type' => 1, 'is_gift' => 0]));
    $key = nheh_opaque($confirm['orderKey'] ?? null);
    nheh_expect(nheh_id($confirm['userInfo']['uid'] ?? null) === $fixture['uid']
        && nheh_id($confirm['addressInfo']['id'] ?? null) === $fixture['address_id']
        && nheh_id($confirm['addressInfo']['uid'] ?? null) === $fixture['uid']
        && ($confirm['valid_count'] ?? null) === 1, 'NHEH_REAL_OWNER_ADDRESS_AND_CONFIRMATION');
    $created = nheh_data(nheh_step($state, $family . '_create', 'POST', '/api/order/create/' . $key, 'customer', $token, [
        'addressId' => $fixture['address_id'], 'couponId' => 0, 'payType' => 'yue', 'useIntegral' => 0,
        'mark' => $state['run_id'] . '-' . $family, 'combinationId' => 0, 'pinkId' => 0, 'seckill_id' => 0,
        'bargainId' => 0, 'shipping_type' => 1, 'store_id' => 0, 'new' => 0, 'invoice_id' => 0,
        'advanceId' => 0, 'custom_form' => [], 'is_gift' => 0, 'gift_mark' => '']));
    nheh_expect(($created['status'] ?? null) === 'SUCCESS' && ($created['result']['key'] ?? null) === $key,
        'NHEH_ACTUAL_FIRST_CREATE_RESPONSE');
    $number = nheh_opaque($created['result']['orderId'] ?? null);
    $unpaid = nheh_root(nheh_step($state, $family . '_unpaid_read', 'GET', '/api/order/detail/' . $number,
        'customer', $token), $fixture, $number, 0, 0);
    nheh_expect($unpaid['cart_id'] === $cartId && $unpaid['refund_status'] === 0, 'NHEH_CREATED_ROOT_BINDING');
    $payment = nheh_data(nheh_step($state, $family . '_pay', 'POST', '/api/order/pay', 'customer', $token,
        ['uni' => $number, 'paytype' => 'yue', 'quitUrl' => '', 'type' => 0]));
    nheh_expect(($payment['status'] ?? null) === 'SUCCESS', 'NHEH_REAL_YUE_PAYMENT_RESPONSE');
    $paid = nheh_root(nheh_step($state, $family . '_paid_read', 'GET', '/api/order/detail/' . $number,
        'customer', $token), $fixture, $number, 1, 0);
    nheh_expect($paid['id'] === $unpaid['id'] && $paid['order_unique'] === $unpaid['order_unique']
        && $paid['item_unique'] === $unpaid['item_unique'] && $paid['cart_id'] === $cartId
        && $paid['refund_status'] === 0, 'NHEH_SAME_REAL_NATIVE_ROOT_AFTER_YUE');
    unset($token, $key, $confirm, $created, $payment); return $paid;
}

function nhe_history_http_run(array $qualified, string $origin, string $runId,
    #[SensitiveParameter] array $actors, array $fixture, Closure $beforeStep, Closure $afterStep): array
{
    nheh_expect(preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D', $runId) === 1 && function_exists('curl_init'),
        'NHEH_REAL_RUN_AND_CURL_REQUIRED');
    $port = nheh_origin($origin); nheh_observers($qualified, $runId, $beforeStep, $afterStep);
    foreach (['uid', 'admin_id', 'address_id', 'product_id', 'sku_id', 'express_id', 'self_operated_mer_id'] as $key) {
        $fixture[$key] = nheh_id($fixture[$key] ?? null);
    }
    nheh_expect(is_string($fixture['sku_unique'] ?? null)
        && preg_match('/\A[A-Za-z0-9]{8}\z/D', $fixture['sku_unique']) === 1
        && is_string($fixture['sku_suk'] ?? null) && $fixture['sku_suk'] !== ''
        && is_string($fixture['express_code'] ?? null) && preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $fixture['express_code']) === 1
        && is_string($fixture['express_name'] ?? null) && $fixture['express_name'] !== ''
        && strlen($fixture['express_name']) <= 100, 'NHEH_ACTUAL_FIXTURE_SKU_AND_EXPRESS_INPUT');
    foreach (['admin', 'customer'] as $kind) {
        $actor = $actors[$kind] ?? null;
        nheh_expect(is_array($actor) && nheh_id($actor['id'] ?? null) === $fixture[$kind === 'admin' ? 'admin_id' : 'uid']
            && is_string($actor['account'] ?? null) && $actor['account'] !== ''
            && is_string($actor['password'] ?? null) && strlen($actor['password']) >= 6 && strlen($actor['password']) <= 32,
            'NHEH_REAL_PRIVATE_FIXTURE_CREDENTIALS');
    }
    $state = ['origin' => $origin, 'port' => $port, 'run_id' => $runId, 'before' => $beforeStep, 'after' => $afterStep,
        'actor_ids' => ['admin' => $fixture['admin_id'], 'customer' => $fixture['uid']],
        'actor_accounts' => ['admin' => $actors['admin']['account'], 'customer' => $actors['customer']['account']], 'steps' => []];
    $adminToken = null; $customerToken = null;
    try {
        $admin = nheh_data(nheh_step($state, 'admin_login', 'POST', '/adminapi/login', 'admin', null,
            ['account' => $actors['admin']['account'], 'pwd' => $actors['admin']['password'],
                'key' => '', 'captchaVerification' => '', 'captchaType' => '']));
        nheh_expect(nheh_id($admin['user_info']['id'] ?? null) === $fixture['admin_id']
            && ($admin['user_info']['account'] ?? null) === $actors['admin']['account']
            && nheh_id($admin['user_info']['level'] ?? null, true) === 0
            && is_string($admin['token'] ?? null) && is_int($admin['expires_time'] ?? null)
            && $admin['expires_time'] > time(), 'NHEH_REAL_PLATFORM_LOGIN_ID_AND_EXPIRY');
        $adminToken = $admin['token'];
        $adminInfo = nheh_data(nheh_step($state, 'admin_identity', 'GET', '/adminapi/setting/info', 'admin', $adminToken));
        nheh_expect(nheh_id($adminInfo['id'] ?? null) === $fixture['admin_id']
            && ($adminInfo['account'] ?? null) === $actors['admin']['account'], 'NHEH_REAL_AUTHENTICATED_PLATFORM_ID');
        // The outer oracle independently models each merchant field/update_time,
        // one native audit row/counter, and exact permission-cache removals.
        $reviewedMerchant = nheh_data(nheh_step($state, 'platform_self_merchant_review', 'PUT',
            '/adminapi/marketplace/merchants/' . $fixture['self_operated_mer_id'] . '/review', 'admin', $adminToken,
            ['review_status' => 1]));
        nheh_expect(nheh_id($reviewedMerchant['mer_id'] ?? null) === $fixture['self_operated_mer_id']
            && ($reviewedMerchant['review_status'] ?? null) === 1, 'NHEH_REAL_SELF_MERCHANT_REVIEW_APPROVED');
        $enabledMerchant = nheh_data(nheh_step($state, 'platform_self_merchant_accept_orders', 'PUT',
            '/adminapi/marketplace/merchants/' . $fixture['self_operated_mer_id'] . '/flags', 'admin', $adminToken,
            ['accept_orders' => 1]));
        nheh_expect(nheh_id($enabledMerchant['mer_id'] ?? null) === $fixture['self_operated_mer_id']
            && ($enabledMerchant['review_status'] ?? null) === 1 && ($enabledMerchant['accept_orders'] ?? null) === 1
            && ($enabledMerchant['security_locked'] ?? null) === 0 && array_key_exists('closed_at', $enabledMerchant)
            && $enabledMerchant['closed_at'] === null, 'NHEH_REAL_SELF_MERCHANT_OPEN_FOR_NORMAL_ORDERS');
        unset($reviewedMerchant, $enabledMerchant);
        $customer = nheh_data(nheh_step($state, 'customer_login', 'POST', '/api/login', 'customer', null,
            ['account' => $actors['customer']['account'], 'password' => $actors['customer']['password'], 'spread' => 0, 'agent_id' => 0]));
        nheh_expect(is_string($customer['token'] ?? null) && is_int($customer['expires_time'] ?? null)
            && $customer['expires_time'] > time(), 'NHEH_REAL_CUSTOMER_LOGIN_TOKEN_AND_EXPIRY');
        $customerToken = $customer['token'];
        $user = nheh_data(nheh_step($state, 'customer_identity', 'GET', '/api/userinfo', 'customer', $customerToken));
        nheh_expect(nheh_id($user['uid'] ?? null) === $fixture['uid'], 'NHEH_REAL_AUTHENTICATED_BUYER_UID');
        nheh_money($user['now_money'] ?? null, '0.00');
        unset($actors, $admin, $adminInfo, $customer, $user, $actor);
        $funded = nheh_step($state, 'platform_system_balance_add', 'PUT', '/adminapi/user/update_other/' . $fixture['uid'],
            'admin', $adminToken, ['money_status' => 1, 'money' => '100.00', 'integration_status' => 0, 'integration' => '0', 'mark' => $runId]);
        nheh_expect($funded['msg'] === '修改成功', 'NHEH_REAL_SYSTEM_BALANCE_OPERATION_MESSAGE');
        $user = nheh_data(nheh_step($state, 'funded_balance_read', 'GET', '/api/userinfo', 'customer', $customerToken));
        nheh_expect(nheh_id($user['uid'] ?? null) === $fixture['uid'], 'NHEH_SAME_FUNDED_BUYER');
        nheh_money($user['now_money'] ?? null, '100.00'); unset($user, $funded);
        $refundRoot = nheh_buy($state, 'refund_family', $customerToken, $fixture);
        $reviewRoot = nheh_buy($state, 'review_family', $customerToken, $fixture);
        nheh_expect($refundRoot['id'] !== $reviewRoot['id'] && $refundRoot['order_no'] !== $reviewRoot['order_no']
            && $refundRoot['item_unique'] !== $reviewRoot['item_unique'] && $refundRoot['cart_id'] !== $reviewRoot['cart_id'],
            'NHEH_TWO_DISTINCT_REAL_PAID_FAMILIES');
        $balance = nheh_data(nheh_step($state, 'two_paid_balance_read', 'GET', '/api/userinfo', 'customer', $customerToken));
        nheh_money($balance['now_money'] ?? null, '59.70'); unset($balance);
        nheh_step($state, 'unshipped_refund_apply', 'POST', '/api/order/refund/apply/' . $refundRoot['id'], 'customer', $customerToken,
            ['text' => 'normal-refund-' . $runId, 'refund_reason_wap_img' => '', 'refund_reason_wap_explain' => '',
                'refund_type' => 1, 'refund_price' => '20.15', 'cart_ids' => []]);
        $list = nheh_data(nheh_step($state, 'owned_refund_identity', 'GET', '/api/order/refund/list?page=1&limit=20',
            'customer', $customerToken));
        nheh_expect(is_array($list['list'] ?? null) && array_is_list($list['list']), 'NHEH_REAL_OWN_REFUND_LIST');
        $matches = [];
        foreach ($list['list'] as $row) if (nheh_id($row['store_order_id'] ?? null) === $refundRoot['id']
            && nheh_id($row['uid'] ?? null) === $fixture['uid']) $matches[] = $row;
        nheh_expect(count($matches) === 1, 'NHEH_ONE_ACTUAL_SAVED_OWN_REFUND');
        $refund = $matches[0]; $refundId = nheh_id($refund['id'] ?? null); $refundNo = nheh_opaque($refund['order_id'] ?? null);
        nheh_expect(nheh_id($refund['refund_type'] ?? null) === 1 && nheh_id($refund['refund_num'] ?? null) === 1
            && ($refund['store_order_order_id'] ?? null) === $refundRoot['order_no'], 'NHEH_ORIGINAL_UNSHIPPED_REFUND_BINDING');
        nheh_money($refund['refund_price'] ?? null, '20.15');
        nheh_step($state, 'platform_refund_approve', 'PUT', '/adminapi/refund/refund/' . $refundId, 'admin', $adminToken,
            ['refund_price' => '20.15', 'type' => 1]);
        $done = nheh_data(nheh_step($state, 'owned_refund_completed_read', 'GET', '/api/order/refund/detail/' . $refundNo,
            'customer', $customerToken));
        nheh_expect(nheh_id($done['id'] ?? null) === $refundId && ($done['order_id'] ?? null) === $refundNo
            && nheh_id($done['uid'] ?? null) === $fixture['uid'] && nheh_id($done['store_order_id'] ?? null) === $refundRoot['id']
            && ($done['store_order_sn'] ?? null) === $refundRoot['order_no'] && nheh_id($done['refund_type'] ?? null) === 6,
            'NHEH_REAL_SAME_COMPLETED_REFUND');
        nheh_money($done['refunded_price'] ?? null, '20.15');
        $refundIdentity = ['id' => nheh_id($done['id']), 'order_no' => $done['order_id'],
            'root_id' => nheh_id($done['store_order_id']), 'refund_type' => nheh_id($done['refund_type'])];
        $refundedRoot = nheh_root(nheh_step($state, 'owned_refunded_root_read', 'GET',
            '/api/order/detail/' . $refundRoot['order_no'], 'customer', $customerToken),
            $fixture, $refundRoot['order_no'], 1, -2);
        nheh_expect($refundedRoot['id'] === $refundRoot['id']
            && $refundedRoot['item_unique'] === $refundRoot['item_unique']
            && $refundedRoot['refund_status'] === 2, 'NHEH_SAME_ORIGINAL_ROOT_ACTUALLY_REFUNDED');
        $balance = nheh_data(nheh_step($state, 'refunded_balance_read', 'GET', '/api/userinfo', 'customer', $customerToken));
        nheh_money($balance['now_money'] ?? null, '79.85');
        unset($list, $matches, $refund, $done, $balance);
        $tracking = 'NHE' . $runId;
        nheh_step($state, 'platform_manual_courier', 'PUT', '/adminapi/order/delivery/' . $reviewRoot['id'], 'admin', $adminToken,
            ['type' => 1, 'express_record_type' => 1, 'delivery_name' => $fixture['express_name'],
                'delivery_code' => $fixture['express_code'], 'delivery_id' => $tracking]);
        $shippedJson = nheh_step($state, 'owned_shipped_read', 'GET', '/api/order/detail/' . $reviewRoot['order_no'], 'customer', $customerToken);
        $shipped = nheh_root($shippedJson, $fixture, $reviewRoot['order_no'], 1, 1); $shipData = nheh_data($shippedJson);
        nheh_expect($shipped['id'] === $reviewRoot['id'] && $shipped['item_unique'] === $reviewRoot['item_unique']
            && $shipped['refund_status'] === 0 && ($shipData['delivery_id'] ?? null) === $tracking
            && ($shipData['delivery_code'] ?? null) === $fixture['express_code'], 'NHEH_REAL_MANUAL_EXPRESS_SAVED');
        nheh_step($state, 'customer_receipt', 'POST', '/api/order/take', 'customer', $customerToken, ['uni' => $reviewRoot['order_no']]);
        $received = nheh_root(nheh_step($state, 'owned_received_read', 'GET', '/api/order/detail/' . $reviewRoot['order_no'],
            'customer', $customerToken), $fixture, $reviewRoot['order_no'], 1, 2);
        nheh_expect($received['id'] === $reviewRoot['id'] && $received['item_unique'] === $reviewRoot['item_unique']
            && $received['refund_status'] === 0, 'NHEH_RECEIPT_ON_ORIGINAL_NONREFUND_FAMILY');
        $comment = 'normal-history-' . $runId; $replyText = 'normal-reply-' . $runId;
        nheh_step($state, 'customer_purchased_item_comment', 'POST', '/api/order/comment', 'customer', $customerToken,
            ['unique' => $received['item_unique'], 'comment' => $comment, 'pics' => '', 'product_score' => 5, 'service_score' => 5]);
        $reviews = nheh_data(nheh_step($state, 'platform_pending_review_identity', 'GET',
            '/adminapi/product/reply?product_id=' . $fixture['product_id'] . '&page=1&limit=20', 'admin', $adminToken));
        nheh_expect(is_array($reviews['list'] ?? null) && array_is_list($reviews['list']), 'NHEH_REAL_PLATFORM_REVIEW_LIST');
        $matches = [];
        foreach ($reviews['list'] as $row) if (nheh_id($row['oid'] ?? null) === $reviewRoot['id']
            && ($row['unique'] ?? null) === $reviewRoot['item_unique']
            && nheh_id($row['product_id'] ?? null) === $fixture['product_id']) $matches[] = $row;
        nheh_expect(count($matches) === 1 && nheh_id($matches[0]['uid'] ?? null) === $fixture['uid']
            && nheh_id($matches[0]['status'] ?? null, true) === 0 && ($matches[0]['comment'] ?? null) === $comment,
            'NHEH_REAL_PURCHASED_PENDING_REVIEW_NOT_VIRTUAL_OR_ALREADY_APPROVED');
        $reviewId = nheh_id($matches[0]['id'] ?? null);
        nheh_step($state, 'platform_review_approve', 'PUT', '/adminapi/product/reply/set_status/' . $reviewId . '/1',
            'admin', $adminToken, []);
        nheh_step($state, 'platform_review_reply', 'PUT', '/adminapi/product/reply/set_reply/' . $reviewId,
            'admin', $adminToken, ['content' => $replyText]);
        $reviews = nheh_data(nheh_step($state, 'platform_approved_replied_read', 'GET',
            '/adminapi/product/reply?product_id=' . $fixture['product_id'] . '&page=1&limit=20', 'admin', $adminToken));
        $matches = [];
        foreach ($reviews['list'] ?? [] as $row) if (nheh_id($row['id'] ?? null) === $reviewId) $matches[] = $row;
        nheh_expect(count($matches) === 1 && nheh_id($matches[0]['oid'] ?? null) === $reviewRoot['id']
            && ($matches[0]['unique'] ?? null) === $reviewRoot['item_unique'] && nheh_id($matches[0]['uid'] ?? null) === $fixture['uid']
            && nheh_id($matches[0]['status'] ?? null) === 1 && nheh_id($matches[0]['is_reply'] ?? null) === 1
            && ($matches[0]['merchant_reply_content'] ?? null) === $replyText, 'NHEH_SAME_REVIEW_ACTUALLY_APPROVED_AND_REPLIED');
        $reviewIdentity = ['id' => nheh_id($matches[0]['id']), 'root_id' => nheh_id($matches[0]['oid']),
            'status' => nheh_id($matches[0]['status']), 'is_reply' => nheh_id($matches[0]['is_reply'])];
        $complete = nheh_root(nheh_step($state, 'owned_commented_completed_read', 'GET',
            '/api/order/detail/' . $reviewRoot['order_no'], 'customer', $customerToken), $fixture, $reviewRoot['order_no'], 1, 3);
        nheh_expect($complete['id'] === $reviewRoot['id'] && $complete['item_unique'] === $reviewRoot['item_unique'],
            'NHEH_SAME_ORIGINAL_COMPLETED_REVIEW_ROOT');
        return ['schema' => 1, 'scope' => 'legacy_http_ids_and_observed_states_only', 'run_id' => $runId,
            'legacy_source_sha' => '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978', 'buyer_uid' => $fixture['uid'],
            'admin_id' => $fixture['admin_id'], 'product_id' => $fixture['product_id'], 'sku_id' => $fixture['sku_id'],
            'refund_family' => $refundedRoot, 'review_family' => $complete,
            'refund' => $refundIdentity, 'review' => $reviewIdentity,
            'http_steps' => $state['steps'], 'business_acceptance' => 'NOT SIGNED',
            'native_children_close_and_independent_zero' => 'REQUIRED_BY_OUTER_PRODUCER'];
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException ? $error->getMessage() : '';
        if (preg_match('/\ANHEH_[A-Z0-9_]+\z/D', $code) !== 1) $code = 'NHEH_NORMAL_HTTP_OR_OBSERVER_FAILED';
        throw new RuntimeException($code);
    } finally {
        unset($actors, $adminToken, $customerToken, $state, $admin, $customer, $adminInfo, $user,
            $shippedJson, $shipData, $reviews, $matches);
    }
}
