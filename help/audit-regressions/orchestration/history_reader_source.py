"""Finite reader source seams for the existing normal-source author.

This module accepts only the archived reader below. It performs no I/O and has
no CLI. The original first UNBOUND remains: the post-four-CLI upgrade receipt,
current role and retirement qualification are separate, unfinished seams.

Refund expectations come from MarketplaceMs4AftersaleMigration::refundAdditions
and CustomerLegacyRefundAssetServices::project / its DAO's note reader, not an
observed HTTP response. The original Redis oracle is outside these seams.
"""

import hashlib


READER_SOURCE_BYTES = 310962
READER_SOURCE_SHA256 = "2b0dd14ff42adf9cc17776d4313f97fd7ad286ba11ccbfae849358610bb84c00"

_FIRST_UNBOUND = b"""function nhecr_qualify(array $freezePin, bool $httpChild = false): array
{
    nhecr_expect(false, 'NHECR_EXACT13_READER_FINAL_SOURCE_UNBOUND');
"""

# Ordered, exact byte replacements; reverse_reader applies these in reverse.
# All other bytes, including the producer's original eleven-control seal, stay
# unchanged. These are source transformations, not a new runtime contract gate.
READER_SEAMS = (
    (
        "includes_current_control_files",
        b"    $controls = array_column($state['authority']['control_files'], null, 'path');\n",
        b"    $controls = array_column($state['current_authority']['control_files'], null, 'path');\n",
    ),
    (
        "current_reader_exact_keys",
        b"    nhecr_keys($current, ['schema', 'source_sha', 'source_files', 'source_directories']);\n",
        b"    nhecr_keys($current, ['schema', 'source_sha', 'source_files', 'source_directories', 'control_files']);\n",
    ),
    (
        "current_twelve_controls_equal_original_eleven_plus_reader",
        br"""    $controlPaths = array_column($authority['control_files'], 'path');
    nhecr_expect(count($controlPaths) === 12 && $controlPaths === [
        '/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-current-reader.php',
        '/run/nonempty/legacy-files-bootstrap.php', '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php',
        '/run/nonempty/legacy-history-producer.php', '/run/nonempty/legacy-history-router.php', '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'], 'NHECR_EXACT_COMPLETE_CONTROL_TWELVE');
    foreach ($authority['control_files'] as $pin) nhe_file($pin['path'], $pin);
    $artifacts = array_column($freeze['artifact_files'], null, 'path');
    nhecr_expect(count($artifacts) === 14 && count($freeze['artifact_files']) === 14 && isset($artifacts['legacy-current-reader.php'])
        && array_replace($artifacts['legacy-current-reader.php'], ['path' => __FILE__])
            === array_column($authority['control_files'], null, 'path')[__FILE__], 'NHECR_TRUE_FOURTEEN_ARTIFACT_AND_CONTROL_JOIN');
""",
        br"""    nhecr_expect(is_array($authority['control_files']) && array_is_list($authority['control_files'])
        && count($authority['control_files']) === 11 && is_array($current['control_files'])
        && array_is_list($current['control_files']) && count($current['control_files']) === 12,
        'NHECR_SEPARATE_ORIGINAL_ELEVEN_AND_CURRENT_TWELVE_CONTROLS');
    $controlPaths = array_column($current['control_files'], 'path');
    nhecr_expect(count($controlPaths) === 12 && $controlPaths === [
        '/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-current-reader.php',
        '/run/nonempty/legacy-files-bootstrap.php', '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php',
        '/run/nonempty/legacy-history-producer.php', '/run/nonempty/legacy-history-router.php', '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'], 'NHECR_EXACT_COMPLETE_CONTROL_TWELVE');
    $producerControls = [];
    foreach ($current['control_files'] as $pin) {
        nhecr_keys($pin, ['path', 'bytes', 'sha256']);
        if ($pin['path'] !== __FILE__) $producerControls[] = $pin;
    }
    nhecr_expect($producerControls === $authority['control_files'],
        'NHECR_CURRENT_CONTROLS_PRESERVE_EVERY_ORIGINAL_PRODUCER_PIN');
    foreach ($current['control_files'] as $pin) nhe_file($pin['path'], $pin);
    $artifacts = array_column($freeze['artifact_files'], null, 'path');
    nhecr_expect(count($artifacts) === 14 && count($freeze['artifact_files']) === 14 && isset($artifacts['legacy-current-reader.php'])
        && array_replace($artifacts['legacy-current-reader.php'], ['path' => __FILE__])
            === array_column($current['control_files'], null, 'path')[__FILE__], 'NHECR_TRUE_FOURTEEN_ARTIFACT_AND_CONTROL_JOIN');
""",
    ),
    (
        "refund_exact_eight_null_columns_after_original_twenty_six",
        br"""    nhep_expect(count($defaults) === 24 && array_keys($metadata['columns']) === array_merge(array_keys($defaults), ['mer_id', 'marketplace_refund_mode']) && $row['mer_id'] === null && $row['marketplace_refund_mode'] === null,
        'NHEPRFD_CONTEXT_ORIGINAL_COMPLETE_TWENTY_FOUR_REFUND_COLUMNS');
""",
        br"""    $aftersaleColumns = [
        'marketplace_root_order_id' => ['int unsigned', null],
        'marketplace_payment_attempt_id' => ['bigint unsigned', null],
        'marketplace_kind' => ['varchar(24)', 'ascii'],
        'marketplace_state' => ['varchar(16)', 'ascii'],
        'marketplace_physical_state' => ['varchar(24)', 'ascii'],
        'marketplace_finance_state' => ['varchar(16)', 'ascii'],
        'marketplace_apply_action_id' => ['bigint unsigned', null],
        'marketplace_last_action_id' => ['bigint unsigned', null],
    ];
    nhep_expect(count($defaults) === 24 && count($metadata['columns']) === 34
        && array_keys($metadata['columns']) === array_merge(array_keys($defaults), ['mer_id', 'marketplace_refund_mode'], array_keys($aftersaleColumns))
        && $row['mer_id'] === null && $row['marketplace_refund_mode'] === null,
        'NHEPRFD_CONTEXT_ORIGINAL_26_AND_EXACT_EIGHT_AFTERSALE_COLUMNS');
    // The original metadata reader preserves ordinal order in the column keys.
    foreach ($aftersaleColumns as $column => [$wantedType, $charset]) {
        $definition = $metadata['columns'][$column];
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($definition['COLUMN_TYPE']));
        nhep_expect(array_key_exists($column, $row) && $row[$column] === null
            && $definition['COLUMN_NAME'] === $column && $type === $wantedType
            && $definition['IS_NULLABLE'] === 'YES' && $definition['COLUMN_DEFAULT'] === null
            && $definition['CHARACTER_SET_NAME'] === $charset
            && $definition['COLLATION_NAME'] === ($charset === null ? null : 'ascii_bin')
            && $definition['EXTRA'] === '' && $definition['GENERATION_EXPRESSION'] === ''
            && $definition['COLUMN_COMMENT'] === '',
            'NHEPRFD_CONTEXT_EXACT_AFTERSALE_NULL_COLUMN_CONTRACT');
    }
""",
    ),
    (
        "refund_complete_seventy_three_key_projection_from_before",
        br"""    $dto['pay_postage'] = $payPostage;
    $added = ['mapKey', 'yue_pay_status', 'pay_weixin_open', 'ali_pay_status', 'store_order_sn', 'cartInfo',
        '_pay_time', 'type', 'use_integral', 'integral_price', 'coupon_price', 'deduction_price', 'vip_true_price',
        'postage_price', 'pay_postage', 'member_price', 'routine_contact_type', 'levelPrice', 'memberPrice', 'pay_type',
        '_add_time', 'add_time_y', 'add_time_h', '_status', 'real_name', 'user_phone', 'user_address', 'nickname',
        'total_num', 'pay_price', 'refund_status', 'total_price', 'paid', 'mark', 'express_list', 'custom_form', 'help_info'];
    nhep_expect(count($added) === 37 && count($dto) === 63
        && array_keys($dto) === array_merge(array_keys($metadata['columns']), $added)
        && $dto['cart_info'] === [$cart] && $dto['cartInfo'][0]['sum_true_price'] === $cart['sum_true_price']
        && array_keys($dto['cartInfo'][0]) === array_merge(array_keys($cart), ['vip_sum_truePrice'])
        && $dto['cartInfo'][0]['vip_sum_truePrice'] === '0.00'
        && $dto['levelPrice'] === '0.00' && $dto['memberPrice'] === 0 && $dto['pay_postage'] === '0.00'
        && $dto['total_price'] === '20.15' && $dto['pay_price'] === '20.15' && $dto['add_time'] === $refundTime,
        'NHEPRFD_VALUE_EXACT_SIXTY_ONE_KEY_ORDER_AND_INDEPENDENT_CART_COPIES_BC_TYPES_AND_NATIVE_ADD_TIME');
""",
        br"""    $dto['pay_postage'] = $payPostage;
    // The fixed pre-request family has no SQL-equivalent shipping-note type.
    foreach ($beforeDB2['rows']['eb_store_order_status'] as $statusIndex => $unused) {
        $statusRow = nhepcv_native_row($beforeDB2, 'eb_store_order_status', $statusIndex);
        if ($statusRow['oid'] !== $oid) continue;
        $changeType = $statusRow['change_type'];
        nhep_expect(is_string($changeType) && preg_match('/\A[\x20-\x7e]*\z/D', $changeType) === 1
            && strtolower(rtrim($changeType, ' ')) !== 'refund_shipping_note',
            'NHEPRFD_VALUE_ORIGINAL_FAMILY_HAS_NO_REFUND_SHIPPING_NOTE');
    }
    $dto['refund_materials'] = ['schema' => 1, 'application' => [], 'shipping' => []];
    $dto['refund_shipping_note'] = '';
    $added = ['mapKey', 'yue_pay_status', 'pay_weixin_open', 'ali_pay_status', 'store_order_sn', 'cartInfo',
        '_pay_time', 'type', 'use_integral', 'integral_price', 'coupon_price', 'deduction_price', 'vip_true_price',
        'postage_price', 'pay_postage', 'member_price', 'routine_contact_type', 'levelPrice', 'memberPrice', 'pay_type',
        '_add_time', 'add_time_y', 'add_time_h', '_status', 'real_name', 'user_phone', 'user_address', 'nickname',
        'total_num', 'pay_price', 'refund_status', 'total_price', 'paid', 'mark', 'express_list', 'custom_form', 'help_info',
        'refund_materials', 'refund_shipping_note'];
    nhep_expect(count($added) === 39 && count($dto) === 73
        && array_keys($dto) === array_merge(array_keys($metadata['columns']), $added)
        && $dto['refund_img'] === [] && $dto['refund_materials'] === ['schema' => 1, 'application' => [], 'shipping' => []]
        && $dto['refund_shipping_note'] === ''
        && $dto['cart_info'] === [$cart] && $dto['cartInfo'][0]['sum_true_price'] === $cart['sum_true_price']
        && array_keys($dto['cartInfo'][0]) === array_merge(array_keys($cart), ['vip_sum_truePrice'])
        && $dto['cartInfo'][0]['vip_sum_truePrice'] === '0.00'
        && $dto['levelPrice'] === '0.00' && $dto['memberPrice'] === 0 && $dto['pay_postage'] === '0.00'
        && $dto['total_price'] === '20.15' && $dto['pay_price'] === '20.15' && $dto['add_time'] === $refundTime,
        'NHEPRFD_VALUE_EXACT_SEVENTY_THREE_KEY_ORDER_AND_INDEPENDENT_CART_COPIES_BC_TYPES_AND_NATIVE_ADD_TIME');
""",
    ),
    (
        "refund_complete_response_diagnostic",
        b"'NHECR_CURRENT_REFUND_COMPLETE_INDEPENDENT63_DTO'",
        b"'NHECR_CURRENT_REFUND_COMPLETE_INDEPENDENT73_DTO'",
    ),
)


def _original_reader(raw: bytes) -> None:
    if (type(raw) is not bytes or len(raw) != READER_SOURCE_BYTES
            or hashlib.sha256(raw).hexdigest() != READER_SOURCE_SHA256):
        raise ValueError("HISTORY_READER_ORIGINAL_PIN")


def _replace_once(raw: bytes, old: bytes, new: bytes, name: str) -> bytes:
    if old == new or raw.count(old) != 1:
        raise ValueError("HISTORY_READER_UNIQUE_SEAM:" + name)
    result = raw.replace(old, new, 1)
    if result.count(new) != 1:
        raise ValueError("HISTORY_READER_UNIQUE_INVERSE:" + name)
    return result


def transform_reader(raw: bytes) -> bytes:
    """Apply only the six declared seams to the exact archived reader bytes."""
    _original_reader(raw)
    candidate = raw
    for name, old, new in READER_SEAMS:
        candidate = _replace_once(candidate, old, new, name)
    if candidate.count(_FIRST_UNBOUND) != 1 or reverse_reader(candidate) != raw:
        raise ValueError("HISTORY_READER_UNBOUND_AND_WHOLE_REVERSE")
    return candidate


def reverse_reader(candidate: bytes) -> bytes:
    """Restore and authenticate the entire archived input, not only seams."""
    if type(candidate) is not bytes or candidate.count(_FIRST_UNBOUND) != 1:
        raise ValueError("HISTORY_READER_CANDIDATE_BYTES_AND_UNBOUND")
    original = candidate
    for name, old, new in reversed(READER_SEAMS):
        original = _replace_once(original, new, old, name)
    _original_reader(original)
    return original
