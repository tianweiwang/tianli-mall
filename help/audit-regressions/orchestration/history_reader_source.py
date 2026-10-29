"""Finite reader source seams for the existing normal-source author.

This module accepts only the archived reader below. It performs no I/O and has
no CLI. The emitted source candidate adopts only a real external review and its
publication through two fixed Run aliases. Upgrade receipts and normal retirement
remain actual public inputs; source adoption does not grant runtime qualification.

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
_ADOPTED_QUALIFY = b"""function nhecr_qualify(array $freezePin, bool $httpChild = false): array
{
    // The real source review, publication and complete artifacts are joined after context qualification below.
"""

# Ordered, exact byte replacements; reverse_reader applies these in reverse.
# All other bytes, including the producer's original eleven-control seal, stay
# unchanged. These transformations do not establish a runtime verification result.
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
    """Apply only the declared finite seams to the exact archived reader bytes."""
    _original_reader(raw)
    candidate = raw
    for name, old, new in READER_SEAMS:
        candidate = _replace_once(candidate, old, new, name)
    if candidate.count(_ADOPTED_QUALIFY) != 1 or _FIRST_UNBOUND in candidate or reverse_reader(candidate) != raw:
        raise ValueError("HISTORY_READER_SOURCE_ADOPTION_AND_WHOLE_REVERSE")
    return candidate


def reverse_reader(candidate: bytes) -> bytes:
    """Restore and authenticate the entire archived input, not only seams."""
    if type(candidate) is not bytes or candidate.count(_ADOPTED_QUALIFY) != 1 or _FIRST_UNBOUND in candidate:
        raise ValueError("HISTORY_READER_CANDIDATE_BYTES_AND_SOURCE_ADOPTION")
    original = candidate
    for name, old, new in reversed(READER_SEAMS):
        original = _replace_once(original, new, old, name)
    _original_reader(original)
    return original


# Pure contract declarations adapted from the independently reviewed upgrade
# author. No initializer/reader include is performed and no CLI can run here.
UPGRADE_CONTRACT_SOURCE_PIN = (37529, "baa947854c42afa6f192ea0f40a57840b74fb0ddbd51f9946bae23ba3638bee1")
_UPGRADE_SHARED_CONTRACTS = rb'''function nhecu_sourceFiles(): array
{
    return [
        ['path' => 'help/audit-regressions/isolated/ms4-migration.php', 'bytes' => 24637, 'sha256' => '7a9e26fdad809649634a40f549fd5429ce10452b74a242444c48e160831401f4'],
        ['path' => 'help/migrations/marketplace-ms1.php', 'bytes' => 38810, 'sha256' => '9d73229f911b5712ca9c7051a18468c98837ae44f48bf77d20b2c165ac02b17a'],
        ['path' => 'help/migrations/marketplace-ms4.php', 'bytes' => 37120, 'sha256' => '8954a47a9233b119a666267fc4a0f6b33b2ce8da41925b702e0f3a9ff77e95b4'],
        ['path' => 'help/migrations/marketplace-ms5-payment.php', 'bytes' => 27010, 'sha256' => '2e58f44b8d2140514d9b45a25d199820c80b90a635e10bd356cfda9a8eb0ccca'],
        ['path' => 'help/migrations/marketplace-ms4-fulfillment.php', 'bytes' => 45363, 'sha256' => '01a976fb1d980c83d04b280c1c6df4eece7070d1e1eda39ead5f83a1e6f6da3f'],
        ['path' => 'help/migrations/marketplace-ms4-aftersale.php', 'bytes' => 59273, 'sha256' => '18aae0d1dc9293f5e6a2032f56421bd452b96f0cbb0b8d6f25ec81426d53a23f'],
        ['path' => 'help/migrations/marketplace-ms6-schema.php', 'bytes' => 11129, 'sha256' => 'f42353122c658e4a3d1cceae7ab341b5ea904409c1068ddca6c369bea7bc03c5'],
        ['path' => 'crmeb/crmeb/exceptions/MarketplaceSchemaException.php', 'bytes' => 638, 'sha256' => '87f3cff9efa3198b8b2a1f072d2d0563f5ac01810400a22f086349862d84222b'],
        ['path' => 'crmeb/crmeb/services/marketplace/SchemaVersionGate.php', 'bytes' => 12267, 'sha256' => '25bf37fa585d76110d74fc8f8aa7615aad2915d99c04c83e3cab850ab2cbba31'],
        ['path' => 'crmeb/marketplace-release.json', 'bytes' => 114, 'sha256' => 'a81a548d6265653de876c80e2dbe1bac293ac2365de65d4cafb44b9829d963db'],
        ['path' => 'crmeb/public/install/crmeb.sql', 'bytes' => 10237459, 'sha256' => '1e5e56c805f551a75332be58b2ab96572509c82835b812cdff3ca5c3eb7ad828'],
    ];
}
function nhecu_keys(array $value, array $wanted): void
{
    $actual = array_keys($value); sort($actual, SORT_STRING); sort($wanted, SORT_STRING);
    nhecr_expect($actual === $wanted, 'CURRENT_UPGRADE_EXACT_KEYS');
}
function nhecu_plan(): array
{
    $plan = [];
    foreach (['marketplace-ms5-payment.php', 'marketplace-ms4-fulfillment.php', 'marketplace-ms4-aftersale.php'] as $script)
        foreach (['check', 'apply-structure', 'check', 'apply-structure'] as $mode) $plan[] = [$script, $mode];
    foreach (['apply', 'check', 'apply', 'check'] as $mode) $plan[] = ['marketplace-ms6-schema.php', $mode];
    return $plan;
}
function nhecu_steps(int $group): array
{
    if ($group === 0) return ['marketplace_payment_registry', 'marketplace_payment_attempt'];
    if ($group === 1) return ['marketplace_fulfillment_operation', 'marketplace_fulfillment_range'];
    nhecr_expect($group === 2, 'CURRENT_UPGRADE_FINITE_STRUCTURE_GROUP');
    $steps = ['create.marketplace_aftersale_action'];
    foreach (array_keys(nhecu_refundColumns()) as $name) $steps[] = 'refund.column.' . $name;
    foreach (['idx_ms4a_refund_root', 'uq_ms4a_refund_apply', 'idx_ms4a_refund_last'] as $name) $steps[] = 'refund.index.' . $name;
    foreach (['mm4a_refund_apply_fk', 'mm4a_refund_last_fk'] as $name) $steps[] = 'refund.foreign.' . $name;
    return array_merge($steps, ['refund.check.mm4a_refund_v1', 'operation.column.aftersale_cut_id',
        'operation.index.idx_fop_aftersale_cut', 'operation.foreign.mm4a_fop_cut_fk',
        'operation.checks.replace_known_ids_and_add_cut', 'create.marketplace_aftersale_file']);
}
function nhecu_structureReceipt(array $result, int $group, int $step): void
{
    $scripts = ['marketplace-ms5-payment.php', 'marketplace-ms4-fulfillment.php', 'marketplace-ms4-aftersale.php'];
    $keys = ['mode', 'state', 'native_exit', 'changed', 'applied_steps', 'pending_steps', 'pending_steps_known',
        'issues', 'failure_phase', 'marker', 'source_sha256', 'acceptance'];
    $extra = $group === 0 ? ['history_mapping', 'payment_producer'] : ['scope', 'prerequisite_source_sha256', 'writer'];
    if ($group === 2) $extra = array_merge($extra, ['structure_stage', 'structure_step_count']);
    nhecu_keys($result, array_merge($keys, $extra)); $steps = nhecu_steps($group);
    $mode = ['check', 'apply-structure', 'check', 'apply-structure'][$step];
    nhecr_expect($result['mode'] === $mode && $result['state'] === ($step === 0 ? 'applicable' : 'completed')
        && $result['native_exit'] === 0 && $result['changed'] === ($step === 1)
        && $result['applied_steps'] === ($step === 1 ? $steps : [])
        && $result['pending_steps'] === ($step === 0 ? $steps : []) && $result['pending_steps_known'] === true
        && $result['issues'] === [] && $result['failure_phase'] === null && $result['acceptance'] === 'NOT RUN'
        && $result['source_sha256'] === hash_file('sha256', '/workspace/help/migrations/' . $scripts[$group]),
        'CURRENT_UPGRADE_EXACT_NORMAL_STRUCTURE_RECEIPT');
    if ($group === 0) nhecr_expect($result['marker'] === 'marketplace-ms5-payment-structure-v1'
        && $result['history_mapping'] === 'NOT RUN' && $result['payment_producer'] === 'NOT IMPLEMENTED', 'CURRENT_UPGRADE_PAYMENT_RECEIPT');
    else {
        $prerequisites = [];
        foreach (['marketplace-ms1.php', 'marketplace-ms4.php', 'marketplace-ms5-payment.php'] as $file)
            $prerequisites['help/migrations/' . $file] = hash_file('sha256', '/workspace/help/migrations/' . $file);
        if ($group === 2) {
            $prerequisites['help/migrations/marketplace-ms4-fulfillment.php'] = hash_file('sha256', '/workspace/help/migrations/marketplace-ms4-fulfillment.php');
            $prerequisites['crmeb/public/install/crmeb.sql'] = hash_file('sha256', '/workspace/crmeb/public/install/crmeb.sql');
        }
        nhecr_expect($result['marker'] === ($group === 1 ? 'marketplace-ms4-fulfillment-v1' : 'marketplace-ms4-aftersale-v1')
            && $result['scope'] === ($group === 1 ? 'MS4_FULFILLMENT_STRUCTURE_ONLY' : 'MS4_AFTERSALE_STRUCTURE_ONLY')
            && $result['prerequisite_source_sha256'] === $prerequisites && $result['writer'] === 'NOT RUN', 'CURRENT_UPGRADE_PREREQUISITE_SOURCE_JOIN');
        if ($group === 2) nhecr_expect($result['structure_step_count'] === 20
            && $result['structure_stage'] === ($step === 0 ? 0 : 20), 'CURRENT_UPGRADE_FINAL_AFTERSALE_STAGE_TWENTY');
    }
}
function nhecu_cachePreserved(array $before, array $after): void
{
    nhecr_expect(is_int($after['redis_time_before']) && is_int($after['redis_time_after'])
        && $after['redis_time_after'] >= $after['redis_time_before'], 'CURRENT_UPGRADE_NATIVE_REDIS_OBSERVATION_INTERVAL');
    $actual = $after['snapshot']['redis'];
    // SQL precedes Redis inside the original m4m snapshot. The native interval
    // bounds that observation; no claim is made that it occurs at its start.
    foreach ($actual as $key => $row) nhecr_expect(array_key_exists($key, $before) && $row === $before[$key],
        'CURRENT_UPGRADE_NO_NEW_KEYS_OR_CHANGED_DUMPS_OR_DEADLINES');
    foreach ($before as $key => $row) if (!array_key_exists($key, $actual))
        nhecr_expect(is_int($row['expires']) && $row['expires'] > 0 && $row['expires'] <= $after['redis_time_after'],
            'CURRENT_UPGRADE_MISSING_KEY_ONLY_REAL_TIME_PASSIVE_EXPIRY');
}
function nhecu_unchanged(array $before, array $after): void
{
    foreach (['names', 'tables', 'modes'] as $key) nhecr_expect($before['snapshot'][$key] === $after['snapshot'][$key], 'CURRENT_UPGRADE_READ_ONLY_FULL_DATABASE');
    nhecr_expect($before['original_projection'] === $after['original_projection'], 'CURRENT_UPGRADE_READ_ONLY_ORIGINAL_PROJECTION');
    nhecu_cachePreserved($before['snapshot']['redis'], $after);
}
function nhecu_existingTables(array $before, array $after, array $changed): void
{
    foreach ($changed as $name) nhecr_expect(isset($before['snapshot']['tables'][$name]), 'CURRENT_UPGRADE_DECLARED_EXISTING_TARGET');
    foreach ($before['snapshot']['tables'] as $name => $row) if (!in_array($name, $changed, true))
        nhecr_expect(($after['snapshot']['tables'][$name] ?? null) === $row, 'CURRENT_UPGRADE_PREVIOUSLY_CREATED_AND_ALL_OTHER_TABLES_PRESERVED');
}
function nhecu_refundColumns(): array
{
    return ['marketplace_root_order_id' => ['int unsigned', null], 'marketplace_payment_attempt_id' => ['bigint unsigned', null],
        'marketplace_kind' => ['varchar(24)', 'ascii'], 'marketplace_state' => ['varchar(16)', 'ascii'],
        'marketplace_physical_state' => ['varchar(24)', 'ascii'], 'marketplace_finance_state' => ['varchar(16)', 'ascii'],
        'marketplace_apply_action_id' => ['bigint unsigned', null], 'marketplace_last_action_id' => ['bigint unsigned', null]];
}
function nhecu_refundPreserved(array $before, array $actual, array $refundRows): array
{
    $columns = nhecu_refundColumns(); $nulls = [];
    nhecr_expect(count($before['columns']) === 26 && array_keys($actual['columns']) === array_merge(array_keys($before['columns']), array_keys($columns)), 'CURRENT_UPGRADE_ONLY_EIGHT_REFUND_COLUMNS');
    foreach ($columns as $name => [$type, $charset]) {
        $column = $actual['columns'][$name]; $nativeType = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
        nhecr_expect($column === ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $column['COLUMN_TYPE'], 'IS_NULLABLE' => 'YES',
            'COLUMN_DEFAULT' => null, 'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $charset === null ? null : 'ascii_bin',
            'EXTRA' => '', 'GENERATION_EXPRESSION' => '', 'COLUMN_COMMENT' => ''] && $nativeType === $type, 'CURRENT_UPGRADE_EXACT_NULLABLE_REFUND_COLUMN');
        $nulls[$name] = 0;
        foreach ($refundRows as $row) { nhecr_expect(array_key_exists($name, $row), 'NHECU_NEW_REFUND_COLUMN_EXISTS_IN_EVERY_NATIVE_ROW'); if ($row[$name] !== null) ++$nulls[$name]; }
        nhecr_expect($nulls[$name] === 0, 'CURRENT_UPGRADE_ALL_OLD_REFUND_ROWS_NEW_COLUMNS_NULL'); unset($actual['columns'][$name]);
    }
    foreach (['idx_ms4a_refund_root' => [1, ['marketplace_root_order_id', 'id']],
        'uq_ms4a_refund_apply' => [0, ['marketplace_apply_action_id']], 'idx_ms4a_refund_last' => [1, ['marketplace_last_action_id']]] as $name => [$nonUnique, $names]) {
        $rows = $actual['indexes'][$name] ?? []; nhecr_expect(count($rows) === count($names), 'CURRENT_UPGRADE_EXACT_NEW_REFUND_INDEX_LENGTH');
        foreach ($names as $position => $column) {
            $row = $rows[$position];
            nhecr_expect($row['INDEX_NAME'] === $name && (string)$row['NON_UNIQUE'] === (string)$nonUnique
                && (string)$row['SEQ_IN_INDEX'] === (string)($position + 1) && $row['COLUMN_NAME'] === $column
                && $row['SUB_PART'] === null && $row['INDEX_TYPE'] === 'BTREE' && $row['IS_VISIBLE'] === 'YES'
                && $row['EXPRESSION'] === null && $row['COLLATION'] === 'A' && $row['INDEX_COMMENT'] === '', 'CURRENT_UPGRADE_EXACT_NEW_REFUND_INDEX');
        }
        unset($actual['indexes'][$name]);
    }
    $wanted = ['uq_ms4a_refund_apply' => 'UNIQUE', 'eb_mm4a_refund_apply_fk' => 'FOREIGN KEY',
        'eb_mm4a_refund_last_fk' => 'FOREIGN KEY', 'eb_mm4a_refund_v1' => 'CHECK']; $remaining = []; $seen = [];
    foreach ($actual['constraints'] as $row) {
        $name = $row['CONSTRAINT_NAME'];
        if (!isset($wanted[$name])) { $remaining[] = $row; continue; }
        nhecr_expect(!isset($seen[$name]) && $row === ['CONSTRAINT_NAME' => $name, 'CONSTRAINT_TYPE' => $wanted[$name], 'ENFORCED' => 'YES'], 'CURRENT_UPGRADE_EXACT_NEW_REFUND_CONSTRAINT'); $seen[$name] = true;
    }
    nhecr_expect(count($seen) === 4, 'CURRENT_UPGRADE_FOUR_NEW_REFUND_CONSTRAINTS'); $actual['constraints'] = $remaining;
    $remaining = []; $seen = [];
    foreach ($actual['foreign'] as $row) {
        $name = $row['CONSTRAINT_NAME']; $columns = ['eb_mm4a_refund_apply_fk' => 'marketplace_apply_action_id', 'eb_mm4a_refund_last_fk' => 'marketplace_last_action_id'];
        if (!isset($columns[$name])) { $remaining[] = $row; continue; }
        nhecr_expect(!isset($seen[$name]) && (string)$row['ORDINAL_POSITION'] === '1' && $row['COLUMN_NAME'] === $columns[$name]
            && $row['REFERENCED_TABLE_SCHEMA'] === 'audit_auth' && $row['REFERENCED_TABLE_NAME'] === 'eb_marketplace_aftersale_action'
            && $row['REFERENCED_COLUMN_NAME'] === 'id' && $row['UPDATE_RULE'] === 'RESTRICT' && $row['DELETE_RULE'] === 'RESTRICT'
            && $row['MATCH_OPTION'] === 'NONE', 'CURRENT_UPGRADE_TWO_EXACT_REFUND_FOREIGN_KEYS'); $seen[$name] = true;
    }
    nhecr_expect(count($seen) === 2, 'CURRENT_UPGRADE_TWO_REFUND_FOREIGN_KEYS'); $actual['foreign'] = $remaining;
    $remaining = []; $count = 0;
    foreach ($actual['checks'] as $row) {
        if ($row['CONSTRAINT_NAME'] !== 'eb_mm4a_refund_v1') { $remaining[] = $row; continue; }
        // The original aftersale stage20 CLI checks this expression with its finite AST parser.
        nhecr_expect($row['ENFORCED'] === 'YES' && is_string($row['CHECK_CLAUSE']) && $row['CHECK_CLAUSE'] !== '', 'CURRENT_UPGRADE_REFUND_ENFORCED_CHECK'); ++$count;
    }
    nhecr_expect($count === 1, 'CURRENT_UPGRADE_ONE_NEW_REFUND_CHECK'); $actual['checks'] = $remaining;
    nhecr_expect($actual === $before, 'CURRENT_UPGRADE_ALL_ORIGINAL_REFUND_METADATA_PRESERVED'); return $nulls;
}
'''

_UPGRADE_READER_FUNCTIONS = rb'''
/** The seven declared additions are protected non-targets during all nine requests. */
function nhecu_newTables(): array
{
    return ['eb_marketplace_payment_registry', 'eb_marketplace_payment_attempt',
        'eb_marketplace_fulfillment_operation', 'eb_marketplace_fulfillment_range',
        'eb_marketplace_aftersale_action', 'eb_marketplace_aftersale_file', 'eb_marketplace_schema'];
}
function nhecu_same(mixed $left, mixed $right): bool
{
    if (gettype($left) !== gettype($right)) return false;
    if (!is_array($left)) return $left === $right;
    if (array_is_list($left) !== array_is_list($right)) return false;
    $a = array_keys($left); $b = array_keys($right);
    if (!array_is_list($left)) { sort($a, SORT_STRING); sort($b, SORT_STRING); }
    if ($a !== $b) return false;
    foreach ($a as $key) if (!nhecu_same($left[$key], $right[$key])) return false;
    return true;
}
function nhecu_public(string $path, array &$proofs): array
{
    $read = nhecr_public_read($path);
    if (isset($proofs[$path])) nhecr_expect($proofs[$path] === $read, 'NHECU_ORIGINAL_PUBLIC_STAT9_AND_BYTES_HELD');
    else $proofs[$path] = $read;
    $value = json_decode($read['raw'], true, 256, JSON_THROW_ON_ERROR);
    nhecr_expect(is_array($value) && !array_is_list($value), 'NHECU_PUBLIC_OBJECT'); return $value;
}
/** Only the 32 fixed CLI streams may be empty; the original JSON reader is unchanged. */
function nhecu_stream(string $path, array $pin, array &$proofs): string
{
    nhecr_expect(preg_match('~\A/run/audit/evidence/current-structure-upgrade-cli-(?:0[1-9]|1[0-6])\.(?:stdout|stderr)\z~D', $path) === 1,
        'NHECU_FIXED_SIXTEEN_PUBLIC_STREAM_PAIRS');
    nhecr_keys($pin, ['path', 'bytes', 'sha256']); clearstatcache(true, $path); $before = lstat($path);
    nhecr_expect($pin['path'] === $path && is_int($pin['bytes']) && $pin['bytes'] >= 0 && $pin['bytes'] <= 1048576
        && is_array($before) && is_file($path) && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && $before['nlink'] === 1 && $before['size'] === $pin['bytes'], 'NHECU_REGULAR_BOUNDED_NATIVE_STREAM');
    $handle = fopen($path, 'rb'); nhecr_expect(is_resource($handle), 'NHECU_NATIVE_STREAM_HANDLE');
    try { $opened = fstat($handle); $raw = stream_get_contents($handle, 1048577); $ended = fstat($handle);
        nhecr_expect(is_string($raw) && strlen($raw) === $pin['bytes'] && fgetc($handle) === false && feof($handle)
            && hash('sha256', $raw) === $pin['sha256'], 'NHECU_COMPLETE_NATIVE_STREAM_BYTES');
    } finally { fclose($handle); }
    clearstatcache(true, $path); $named = lstat($path); $stat = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        nhecr_expect($before[$key] === $opened[$key] && $before[$key] === $ended[$key] && $before[$key] === $named[$key], 'NHECU_NATIVE_STREAM_STABLE_STAT9');
        $stat[$key] = $before[$key];
    }
    $value = ['raw' => $raw, 'stat9' => $stat, 'pin' => $pin];
    if (isset($proofs[$path])) nhecr_expect($proofs[$path] === $value, 'NHECU_NATIVE_STREAM_REREAD_HELD');
    else $proofs[$path] = $value;
    return $raw;
}
function nhecu_held(array &$proofs): void
{
    foreach ($proofs as $path => $value) {
        if (preg_match('/\.(stdout|stderr)\z/D', $path) === 1) nhecu_stream($path, $value['pin'], $proofs);
        else nhecr_expect(nhecr_public_read($path) === $value, 'NHECU_ALL_UPGRADE_PUBLIC_ORIGINALS_HELD');
    }
}
function nhecu_jsonLines(string $raw): array
{
    nhecr_expect($raw !== '' && str_ends_with($raw, "\n"), 'NHECU_COMPLETE_NATIVE_JSONL');
    $lines = explode("\n", substr($raw, 0, -1)); $rows = [];
    foreach ($lines as $line) { nhecr_expect($line !== '', 'NHECU_NO_EMPTY_NATIVE_JSONL_ROW');
        $row = json_decode($line, true, 128, JSON_THROW_ON_ERROR); nhecr_expect(is_array($row) && !array_is_list($row), 'NHECU_NATIVE_JSONL_OBJECT'); $rows[] = $row; }
    return $rows;
}
/** Exact donor fifteen-key retirement contract, also used for the upgrade helper. */
function nhecu_retired(array $context, string $proof, array $prerequisites, array &$proofs): array
{
    $roles = ['legacy-history-producer' => 'audit-legacy-history-producer', 'driver' => 'audit-ms4-migration', 'current-structure-upgrade' => 'audit-bootstrap'];
    nhecr_expect(isset($roles[$proof]), 'NHECU_FINITE_NORMAL_RETIREMENT_ROLE');
    $base = '/run/audit/evidence/'; $receipt = nhecu_public($base . $proof . '-retired.json', $proofs);
    nhecr_keys($receipt, ['schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'role', 'proof_name', 'container_id',
        'created', 'terminal', 'binding', 'result', 'normal_remove', 'resource_after', 'prerequisites']);
    $created = nhecu_public($base . $proof . '-created.json', $proofs); $terminal = nhecu_public($base . $proof . '-terminal.json', $proofs);
    $cid = $created['id'];
    nhecr_expect($receipt['schema'] === 1 && $receipt['scope'] === 'NONEMPTY_HISTORY_EXACT_NORMAL_ROLE_RETIRED'
        && $receipt['run_id'] === $context['run_id'] && $receipt['source_sha'] === $context['source_sha']
        && $receipt['freeze_sha256'] === $context['freeze_sha256'] && $receipt['role'] === $roles[$proof]
        && $receipt['proof_name'] === $proof && $receipt['container_id'] === $cid && preg_match('/\A[a-f0-9]{64}\z/D', $cid) === 1
        && $receipt['prerequisites'] === $prerequisites && $terminal['id'] === $cid && $terminal['status'] === 'exited'
        && $terminal['exit_code'] === 0 && $terminal['pid'] === 0 && $terminal['restart_count'] === 0, 'NHECU_SAME_RUN_EXACT_NORMAL_RETIREMENT');
    $binding = $proof === 'legacy-history-producer' ? $base . 'audit-legacy-history-producer-source-binding.json'
        : ($proof === 'driver' ? $base . 'ms4-migration-source-binding.json' : '/run/audit/preparation-source-binding.json');
    $result = $base . ($proof === 'driver' ? 'ms4-migration-normal.json' : $proof . '-result.json');
    foreach (['created' => $base . $proof . '-created.json', 'terminal' => $base . $proof . '-terminal.json',
        'binding' => $binding, 'result' => $result, 'resource_after' => $base . $proof . '-retired-resources.json'] as $key => $path) {
        nhecu_public($path, $proofs); nhecr_expect($receipt[$key] === $proofs[$path]['pin'], 'NHECU_RETIREMENT_FULL_PUBLIC_PIN_JOIN');
    }
    $remove = $receipt['normal_remove']; nhecr_keys($remove, ['path', 'bytes', 'sha256']);
    nhecr_expect(preg_match('~\A/run/audit/evidence/native-[0-9]{4,}\.json\z~D', $remove['path']) === 1, 'NHECU_ORIGINAL_NATIVE_REMOVE_LEDGER_PATH');
    $native = nhecu_public($remove['path'], $proofs);
    nhecr_keys($native, ['stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted']);
    nhecr_expect($remove === $proofs[$remove['path']]['pin'] && $native['stage'] === $proof . '-exact-nonforce-remove'
        && $native['native_exit'] === 0 && $native['stdout_bytes'] === 65 && $native['stdout_sha256'] === hash('sha256', $cid . "\n")
        && $native['stderr_bytes'] === 0 && $native['stderr_sha256'] === hash('sha256', '')
        && $native['private_environment_logs_omitted'] === true, 'NHECU_ONE_REAL_ORDINARY_REMOVE_ZERO');
    $paths = glob($base . 'native-*.json'); nhecr_expect(is_array($paths) && count($paths) > 0 && count($paths) <= 4096, 'NHECU_BOUNDED_ORIGINAL_REMOVE_LEDGERS');
    $matches = [];
    foreach ($paths as $path) {
        nhecr_expect(preg_match('~\A/run/audit/evidence/native-[0-9]{4,}\.json\z~D', $path) === 1, 'NHECU_FIXED_NATIVE_LEDGER_NAMESPACE');
        $read = nhecr_public_read($path); $row = json_decode($read['raw'], true, 64, JSON_THROW_ON_ERROR);
        if (($row['stage'] ?? null) === $proof . '-exact-nonforce-remove') $matches[] = $read['pin'];
    }
    nhecr_expect($matches === [$remove], 'NHECU_UNIQUE_ORIGINAL_REMOVE_NO_RETRY');
    $resources = nhecu_public($base . $proof . '-retired-resources.json', $proofs);
    nhecr_keys($resources, ['schema', 'run_id', 'source_sha', 'freeze_sha256', 'retired_container_id', 'public_raw']);
    nhecr_expect($resources['schema'] === 1 && $resources['run_id'] === $context['run_id'] && $resources['source_sha'] === $context['source_sha']
        && $resources['freeze_sha256'] === $context['freeze_sha256'] && $resources['retired_container_id'] === $cid, 'NHECU_REAL_POST_REMOVE_RESOURCE_JOIN');
    $raw = $resources['public_raw']; nhecr_keys($raw, ['network.json', 'containers.jsonl', 'volumes.txt', 'volumes.jsonl']);
    $network = json_decode($raw['network.json'], true, 128, JSON_THROW_ON_ERROR); $volumes = nhecu_jsonLines($raw['volumes.jsonl']);
    $containers = nhecu_jsonLines($raw['containers.jsonl']); $wantedVolumes = [];
    foreach (['app-secrets', 'bootstrap-secrets', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets'] as $leaf) $wantedVolumes[] = $context['project'] . '_' . $leaf;
    sort($wantedVolumes, SORT_STRING); $ids = $context['initial_service_ids']; nhecr_keys($ids, ['audit-mysql', 'audit-redis']);
    nhecr_expect(nhecu_same($network, $context['initial_network']) && nhecu_same($volumes, $context['initial_volumes'])
        && $raw['volumes.txt'] === implode("\n", $wantedVolumes) . "\n" && count($containers) === 2
        && count(array_unique(array_values($ids))) === 2, 'NHECU_ONLY_ORIGINAL_TWO_BASE_SIX_VOLUMES_NETWORK');
    $found = [];
    foreach ($containers as $row) {
        nhecr_keys($row, ['id', 'image_id', 'mounts', 'labels', 'networks', 'ports', 'read_only', 'privileged', 'network_mode']);
        $role = array_search($row['id'], $ids, true);
        nhecr_expect(is_string($role) && !isset($found[$role]) && $row['id'] !== $cid && $row['labels']['com.docker.compose.service'] === $role
            && $row['labels']['com.docker.compose.project'] === $context['project'] && $row['labels']['tianli.audit.run'] === $context['run_id']
            && array_keys($row['networks']) === [$context['project'] . '_isolation']
            && $row['networks'][$context['project'] . '_isolation']['NetworkID'] === $context['network_id'], 'NHECU_RETIRED_BASE_NATIVE_IDENTITIES');
        $original = $context['initial_service_created'][$role];
        foreach (['id' => 'id', 'image_id' => 'image', 'labels' => 'labels', 'read_only' => 'read_only',
            'privileged' => 'privileged', 'network_mode' => 'network_mode'] as $key => $source)
            nhecr_expect(nhecu_same($row[$key], $original[$source]), 'NHECU_RETIRED_COMPLETE_ORIGINAL_BASE_IMMUTABLE_FIELDS');
        $mountSets = [];
        foreach ([$original['mounts'], $row['mounts']] as $mounts) {
            nhecr_expect(is_array($mounts) && array_is_list($mounts), 'NHECU_RETIRED_COMPLETE_NATIVE_MOUNT_LIST'); $byDestination = [];
            foreach ($mounts as $mount) {
                nhecr_expect(is_array($mount) && is_string($mount['Destination'] ?? null)
                    && !array_key_exists($mount['Destination'], $byDestination), 'NHECU_RETIRED_UNIQUE_NATIVE_MOUNT_DESTINATION');
                $byDestination[$mount['Destination']] = $mount;
            }
            ksort($byDestination, SORT_STRING); $mountSets[] = $byDestination;
        }
        nhecr_expect(nhecu_same($mountSets[0], $mountSets[1]) && in_array($original['port_bindings'], [null, []], true)
            && ($row['ports'] === null || is_array($row['ports'])), 'NHECU_RETIRED_FULL_MOUNT_OBJECTS_AND_NO_HOST_BINDINGS');
        foreach ($row['ports'] ?? [] as $ports) nhecr_expect($ports === null, 'NHECU_RETIRED_NO_PUBLISHED_BASE_PORTS');
        // This nine-field collection proves identity, not liveness. The unchanged qualifier
        // joins the registered base CIDs and runs the real DNS/database/Redis sentinel guard.
        $found[$role] = true;
    }
    return $receipt;
}
function nhecu_view(array $view, array $names, array $originalNames): void
{
    nhecr_keys($view, ['snapshot', 'original_projection', 'redis_time_before', 'redis_time_after']); $snapshot = $view['snapshot'];
    nhecr_keys($snapshot, ['names', 'tables', 'redis', 'modes']);
    nhecr_expect($snapshot['names'] === $names && array_keys($snapshot['tables']) === $names
        && array_keys($view['original_projection']) === $originalNames && is_int($view['redis_time_before'])
        && is_int($view['redis_time_after']) && $view['redis_time_before'] > 0 && $view['redis_time_after'] >= $view['redis_time_before'], 'NHECU_COMPLETE_SNAPSHOT_NAMESPACE_AND_REAL_TIME');
    foreach ($snapshot['tables'] as $table) {
        nhecr_keys($table, ['metadata', 'ddl_sha256', 'projection', 'projected_columns']);
        nhecr_keys($table['metadata'], ['table', 'columns', 'indexes', 'constraints', 'checks', 'foreign', 'triggers']);
        nhecr_expect($table['projected_columns'] === array_keys($table['metadata']['columns'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $table['ddl_sha256']) === 1, 'NHECU_FULL_COLUMN_METADATA_AND_DDL');
        nhecu_projection($table['projection']);
    }
    foreach ($view['original_projection'] as $projection) nhecu_projection($projection);
    foreach ($snapshot['redis'] as $key => $entry) {
        nhecr_keys($entry, ['sha256', 'expires']);
        nhecr_expect(preg_match('/\A[a-f0-9]{64}\z/D', $key) === 1 && preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256']) === 1
            && is_int($entry['expires']) && ($entry['expires'] === -1 || $entry['expires'] > 0), 'NHECU_ORIGINAL_REDIS_COMPLETE_NATIVE_DESCRIPTOR');
    }
}
function nhecu_projection(array $value): void
{
    nhecr_keys($value, ['rows', 'sha256']);
    nhecr_expect(is_int($value['rows']) && $value['rows'] >= 0 && preg_match('/\A[a-f0-9]{64}\z/D', $value['sha256']) === 1, 'NHECU_M4M_JSON_PROJECTION_TYPE');
}
/** Pure m4m JSON row projection, distinct from the reader's serialize projection. */
function nhecu_jsonProjection(array $rows, array $columns): array
{
    $hashes = [];
    foreach ($rows as $row) { $projected = []; foreach ($columns as $column) {
        nhecr_expect(array_key_exists($column, $row), 'NHECU_REAL_ORIGINAL_PROJECTED_COLUMN_PRESENT'); $projected[$column] = $row[$column]; }
        $hashes[] = hash('sha256', json_encode($projected, JSON_THROW_ON_ERROR)); }
    sort($hashes, SORT_STRING); return ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $hashes))];
}
function nhecu_nativeChild(array $child, int $index, array &$proofs): array
{
    nhecr_keys($child, ['argv', 'pid', 'started_at', 'finished_at', 'proc_close', 'native_exit', 'initial_status', 'stdout', 'stderr', 'script', 'mode', 'terminal', 'result']);
    [$script, $mode] = nhecu_plan()[$index]; $argv = [PHP_BINARY, '/workspace/help/migrations/' . $script, $mode];
    if ($script === 'marketplace-ms6-schema.php') $argv[] = '--repo-root=/workspace';
    $argv[] = '--config-file=/tmp/current-structure-upgrade-' . getenv('AUDIT_RUN_ID') . '/config.json';
    $stem = '/run/audit/evidence/current-structure-upgrade-cli-' . sprintf('%02d', $index + 1);
    $created = nhecu_public($stem . '.created.json', $proofs); $terminal = nhecu_public($stem . '.terminal.json', $proofs);
    nhecr_keys($created, ['argv', 'pid', 'started_at']); nhecr_keys($terminal, ['argv', 'pid', 'started_at', 'finished_at', 'proc_close', 'native_exit', 'initial_status', 'stdout', 'stderr']);
    nhecr_keys($terminal['initial_status'], ['running', 'signaled', 'exitcode']); $status = $terminal['initial_status'];
    nhecr_expect($child['argv'] === $argv && $created === ['argv' => $argv, 'pid' => $child['pid'], 'started_at' => $child['started_at']]
        && is_int($child['pid']) && $child['pid'] > 0 && is_int($child['started_at']) && $child['started_at'] > 0
        && is_int($child['finished_at']) && $child['finished_at'] >= $child['started_at'] && $child['native_exit'] === 0
        && is_bool($status['running']) && is_bool($status['signaled']) && is_int($status['exitcode'])
        && ($child['proc_close'] === 0 || ($child['proc_close'] === -1 && $status['running'] === false && $status['signaled'] === false && $status['exitcode'] === 0))
        && $child['script'] === $script && $child['mode'] === $mode && $child['terminal'] === $proofs[$stem . '.terminal.json']['pin'], 'NHECU_REAL_FIXED_CHILD_ARGV_PID_EXIT_WINDOW');
    $wanted = $child; foreach (['script', 'mode', 'terminal', 'result'] as $key) unset($wanted[$key]);
    nhecr_expect($terminal === $wanted, 'NHECU_NATIVE_TERMINAL_COMPLETE_RESULT_JOIN');
    $stdout = nhecu_stream($stem . '.stdout', $child['stdout'], $proofs); $stderr = nhecu_stream($stem . '.stderr', $child['stderr'], $proofs);
    nhecr_expect($stdout !== '' && $stderr === '' && json_decode($stdout, true, 128, JSON_THROW_ON_ERROR) === $child['result'], 'NHECU_REAL_COMPLETE_CLI_DOUBLE_STREAMS');
    return $child['result'];
}
function nhecu_upgradePublic(array $context, array $freeze, array $handoff, array &$proofs, string $artifactRoot = '/run/nonempty'): array
{
    nhecr_expect($artifactRoot === '/run/nonempty' || ($artifactRoot === '/run/ms4-prep'
        && PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === '/run/ms4-prep/ms4-initialize.php'
        && getenv('MS4_PROOF_NAME') === 'register-legacy-current-reader'), 'NHECU_FIXED_READER_OR_REGISTRATION_ARTIFACT_ROOT');
    $artifacts = array_column($freeze['artifact_files'], null, 'path');
    nhecr_expect(isset($artifacts['ms4-initialize.php']), 'NHECU_REAL_FROZEN_UPGRADE_INITIALIZER_ARTIFACT');
    nhe_file($artifactRoot . '/ms4-initialize.php', array_replace($artifacts['ms4-initialize.php'], ['path' => $artifactRoot . '/ms4-initialize.php']));
    $base = '/run/audit/evidence/'; $producer = nhecu_retired($context, 'legacy-history-producer', [], $proofs);
    $producerPin = $proofs[$base . 'legacy-history-producer-retired.json']['pin'];
    nhecu_retired($context, 'driver', ['producer_retired' => $producerPin], $proofs);
    $driverPin = $proofs[$base . 'driver-retired.json']['pin'];
    $retired = nhecu_retired($context, 'current-structure-upgrade', ['producer_retired' => $producerPin, 'driver_retired' => $driverPin], $proofs);
    $created = nhecu_public($base . 'current-structure-upgrade-created.json', $proofs);
    $terminal = nhecu_public($base . 'current-structure-upgrade-terminal.json', $proofs);
    $result = nhecu_public($base . 'current-structure-upgrade-result.json', $proofs); $cid = $created['id'];
    nhecr_expect($created['run_id'] === $context['run_id'] && $created['source_sha'] === $context['source_sha']
        && $created['proof_name'] === 'current-structure-upgrade' && $created['status'] === 'created' && $created['pid'] === 0
        && $created['restart_count'] === 0 && $created['image'] === M4M_IMAGE && $created['hostname'] === substr($cid, 0, 12)
        && $created['read_only'] === true && $created['privileged'] === false && $created['cap_drop'] === ['ALL']
        && in_array($created['security_options'], [['no-new-privileges'], ['no-new-privileges:true']], true)
        && $created['network_mode'] === $context['project'] . '_isolation' && in_array($created['port_bindings'], [null, []], true)
        && $created['entrypoint'] === ['php'] && $created['workdir'] === '/workspace'
        && $created['command'] === ['/run/ms4-prep/ms4-initialize.php', 'current-structure-upgrade']
        && $created['labels']['tianli.audit'] === '1' && $created['labels']['tianli.audit.run'] === $context['run_id']
        && $created['labels']['com.docker.compose.project'] === $context['project']
        && $created['labels']['com.docker.compose.service'] === 'audit-bootstrap', 'NHECU_ORIGINAL_HELPER_CREATED_IDENTITY');
    foreach (['id', 'image', 'hostname', 'labels', 'tmpfs', 'read_only', 'privileged', 'network_mode', 'command', 'entrypoint',
        'workdir', 'port_bindings', 'cap_drop', 'security_options', 'created_at'] as $key)
        nhecr_expect(nhecu_same($created[$key], $terminal[$key]), 'NHECU_HELPER_IMMUTABLE_TERMINAL_JOIN');
    nhecr_keys($created['tmpfs'], ['/tmp']); $wanted = [];
    foreach (['/workspace' => $context['repo_dir'], '/source' => $context['repo_dir'], '/run/ms4-prep' => $context['prep_dir'], '/run/audit' => $context['run_dir']] as $destination => $source)
        $wanted[] = ['Type' => 'bind', 'Source' => $source, 'Destination' => $destination, 'RW' => $destination === '/run/audit', 'Name' => ''];
    $mounts = nhe_mount_rows($created['mounts']);
    foreach (['bootstrap-secrets' => '/run/audit-bootstrap', 'redis-secrets' => '/run/audit-redis', 'app-secrets' => '/run/audit-app', 'fixture-secrets' => '/run/audit-fixtures'] as $leaf => $destination) {
        $rows = array_values(array_filter($mounts, static fn(array $r): bool => $r['Destination'] === $destination));
        nhecr_expect(count($rows) === 1 && $rows[0]['Type'] === 'volume' && $rows[0]['RW'] === false
            && $rows[0]['Name'] === $context['project'] . '_' . $leaf && is_string($rows[0]['Source']) && $rows[0]['Source'] !== '', 'NHECU_HELPER_ONLY_ORIGINAL_PRIVATE_RO_MOUNTS'); $wanted[] = $rows[0];
    }
    nhecr_expect($mounts === nhe_mount_rows($wanted) && $mounts === nhe_mount_rows($terminal['mounts']), 'NHECU_HELPER_COMPLETE_EIGHT_MOUNTS');
    nhecr_keys($result, ['schema', 'scope', 'status', 'run_id', 'source_sha', 'children', 'private_cleanup', 'private_cleanup_verified',
        'App_HTTP_backfill_enforce_payment', 'MS4_migration', 'acceptance', 'binding', 'current_structure_upgrade', 'native_exit']);
    nhecr_expect($result['schema'] === 1 && $result['scope'] === 'CURRENT_READER_STRUCTURE_UPGRADE_ONLY'
        && $result['status'] === 'VERIFIED_CURRENT_STRUCTURE_UPGRADE_ONLY' && $result['native_exit'] === 0
        && $result['run_id'] === $context['run_id'] && $result['source_sha'] === $context['source_sha']
        && $result['private_cleanup_verified'] === true && $result['App_HTTP_backfill_enforce_payment'] === 'NOT RUN'
        && $result['MS4_migration'] === 'NOT RUN' && $result['acceptance'] === 'NOT RUN', 'NHECU_ONLY_REAL_COMPLETED_STRUCTURE_ENVELOPE');
    nhecr_keys($result['binding'], ['container_id', 'entry_namespace_pid', 'source_sha', 'created_sha256', 'freeze_sha256']);
    nhecr_expect($result['binding']['container_id'] === $cid && is_int($result['binding']['entry_namespace_pid']) && $result['binding']['entry_namespace_pid'] > 0
        && $result['binding']['source_sha'] === $context['source_sha'] && $result['binding']['freeze_sha256'] === $context['freeze_sha256']
        && $result['binding']['created_sha256'] === $proofs[$base . 'current-structure-upgrade-created.json']['pin']['sha256'], 'NHECU_HELPER_REAL_BINDING');
    nhecr_expect(count($result['private_cleanup']) === 1, 'NHECU_ONE_PRIVATE_CONFIG_NORMAL_CLEANUP'); $cleanup = $result['private_cleanup'][0];
    nhecr_keys($cleanup, ['kind', 'bytes', 'mode', 'owned', 'same_known_file', 'removed', 'private_content_and_config_hash_omitted']);
    nhecr_expect($cleanup['kind'] === 'config' && is_int($cleanup['bytes']) && $cleanup['bytes'] > 0 && $cleanup['bytes'] <= 65536
        && $cleanup['mode'] === '0600' && $cleanup['owned'] === true && $cleanup['same_known_file'] === true
        && $cleanup['removed'] === true && $cleanup['private_content_and_config_hash_omitted'] === true, 'NHECU_EXACT_OWNED_PRIVATE_CONFIG_REMOVED');
    $shutdown = nhecu_public($base . 'current-structure-upgrade-shutdown.json', $proofs);
    nhecr_keys($shutdown, ['schema', 'phase', 'fatal', 'type', 'file', 'line', 'primary_failure', 'cleanup_failure', 'memory_limit', 'peak_bytes']);
    nhecr_expect($shutdown['schema'] === 1 && $shutdown['phase'] === 'current-structure-upgrade' && $shutdown['fatal'] === false
        && $shutdown['type'] === 0 && $shutdown['file'] === '' && $shutdown['line'] === 0
        && $shutdown['primary_failure'] === null && $shutdown['cleanup_failure'] === null, 'NHECU_REAL_HELPER_NATURAL_SHUTDOWN');
    $upgrade = $result['current_structure_upgrade'];
    nhecr_keys($upgrade, ['schema', 'expected_before_table_count', 'expected_after_table_count', 'source_files', 'snapshots', 'original_four_cli_unchanged',
        'original_four_cli', 'before_table_count', 'refund_null_counts', 'schema_create_window', 'schema_row', 'after_table_count']);
    nhecr_expect($upgrade['schema'] === 1 && $upgrade['expected_before_table_count'] === 166 && $upgrade['expected_after_table_count'] === 173
        && $upgrade['before_table_count'] === 166 && $upgrade['after_table_count'] === 173 && $upgrade['original_four_cli_unchanged'] === true
        && $upgrade['original_four_cli'] === $proofs[$base . 'ms4-migration-normal.json']['pin'] && $upgrade['source_files'] === nhecu_sourceFiles(), 'NHECU_EXACT_INDEPENDENT_166_TO_173_SOURCE_BOUNDARY');
    $currentSources = array_column($freeze['current_reader']['source_files'], null, 'path');
    foreach ($upgrade['source_files'] as $pin) {
        nhecr_expect(isset($currentSources[$pin['path']]) && $currentSources[$pin['path']] === $pin, 'NHECU_UPGRADE_ALL_DECLARED_SOURCES_IN_CURRENT_FREEZE');
        foreach (['/workspace', '/source'] as $root) nhe_file($root . '/' . $pin['path'], array_replace($pin, ['path' => $root . '/' . $pin['path']]));
    }
    $snapshots = $upgrade['snapshots']; $keys = ['before']; foreach (range(1, 16) as $i) $keys[] = 'cli_' . $i; $keys[] = 'after';
    nhecr_expect(array_keys($snapshots) === $keys && is_array($result['children']) && array_is_list($result['children'])
        && count($result['children']) === 16 && count(array_unique(array_column($result['children'], 'pid'))) === 16, 'NHECU_COMPLETE_SIXTEEN_NATIVE_STEPS_AND_SNAPSHOTS');
    $old = $handoff['driver_after']; $names = $old['names']; $before = $snapshots['before']; nhecu_view($before, $names, $names);
    nhecr_expect(count($names) === 166 && $before['snapshot']['modes'] === $old['modes'], 'NHECU_ORIGINAL_DRIVER_166_UNMODIFIED');
    foreach ($old['tables'] as $name => $row) nhecr_expect($before['snapshot']['tables'][$name]['metadata'] === $row['metadata']
        && $before['snapshot']['tables'][$name]['ddl_sha256'] === $row['ddl_sha256']
        && $before['original_projection'][$name] === $before['snapshot']['tables'][$name]['projection'], 'NHECU_UPGRADE_BEFORE_FULL166_METADATA_AND_PROJECTION');
    nhecu_cachePreserved($old['redis'], $before); $previous = $before; $new = []; $schemaRow = $upgrade['schema_row'];
    nhecr_keys($schemaRow, ['id', 'schema_version', 'min_app_version', 'updated_at']);
    nhecr_expect($schemaRow['id'] === 1 && $schemaRow['schema_version'] === 1 && $schemaRow['min_app_version'] === '0.1.0'
        && is_int($schemaRow['updated_at']) && $schemaRow['updated_at'] > 0 && $schemaRow['updated_at'] <= 4294967295, 'NHECU_FIXED_DECLARED_SCHEMA_SINGLETON');
    foreach ($result['children'] as $index => $child) {
        if ($index > 0) nhecr_expect($child['started_at'] >= $result['children'][$index - 1]['finished_at'], 'NHECU_ORDERED_NATIVE_CHILD_TIME_WINDOWS');
        $receipt = nhecu_nativeChild($child, $index, $proofs); $view = $snapshots['cli_' . ($index + 1)];
        $changed = in_array($index, [1, 5, 9, 12], true);
        if ($index < 12) nhecu_structureReceipt($receipt, intdiv($index, 4), $index % 4);
        else {
            nhecr_keys($receipt, ['mode', 'state', 'changed', 'code', 'schema']);
            nhecr_expect($receipt === ['mode' => ['apply', 'check', 'apply', 'check'][$index - 12],
                'state' => ['created', 'compatible', 'unchanged', 'compatible'][$index - 12], 'changed' => $index === 12,
                'code' => null, 'schema' => $schemaRow], 'NHECU_EXACT_FOUR_MS6_REAL_RECEIPTS');
        }
        if ($changed) $new = array_slice(nhecu_newTables(), 0, [1 => 2, 5 => 4, 9 => 6, 12 => 7][$index]);
        $wanted = array_merge($names, $new); sort($wanted, SORT_STRING); nhecu_view($view, $wanted, $names);
        nhecr_expect($view['redis_time_before'] >= $previous['redis_time_after'] && $view['snapshot']['modes'] === $before['snapshot']['modes'], 'NHECU_ORDERED_REAL_OBSERVATION_WINDOWS_AND_MODES');
        nhecu_cachePreserved($previous['snapshot']['redis'], $view);
        foreach ($before['snapshot']['tables'] as $name => $row)
            nhecr_expect($view['original_projection'][$name] === $row['projection'], 'NHECU_ALL_FULL166_ORIGINAL_COLUMN_ROWS_EACH_STEP');
        if (!$changed) nhecu_unchanged($previous, $view);
        else {
            nhecu_existingTables($previous, $view, $index === 9 ? ['eb_store_order_refund', 'eb_marketplace_fulfillment_operation'] : []);
            foreach ($new as $name) nhecr_expect($view['snapshot']['tables'][$name]['projection']['rows'] === ($name === 'eb_marketplace_schema' ? 1 : 0), 'NHECU_ONLY_SIX_EMPTY_TABLES_AND_ONE_SCHEMA_ROW');
        }
        $previous = $view;
    }
    nhecr_expect($snapshots['after'] === $previous && count($previous['snapshot']['names']) === 173
        && $upgrade['refund_null_counts'] === array_fill_keys(array_keys(nhecu_refundColumns()), 0)
        && $upgrade['schema_create_window'] === [$result['children'][12]['started_at'], $result['children'][12]['finished_at']]
        && $schemaRow['updated_at'] >= $upgrade['schema_create_window'][0] && $schemaRow['updated_at'] <= $upgrade['schema_create_window'][1], 'NHECU_REAL_COMPLETE173_SCHEMA_WINDOW_AND_REFUND_NULL_RECEIPT');
    nhecu_held($proofs);
    return ['receipt' => $result, 'retirement' => $retired, 'before' => $before, 'after' => $previous,
        'schema_row' => $schemaRow, 'retired_pin' => $proofs[$base . 'current-structure-upgrade-retired.json']['pin']];
}
/** Exact MS6 declaration from SchemaVersionGate, checked against captured metadata. */
function nhecu_schemaMetadata(array $meta): void
{
    nhecr_expect($meta['table'] === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic',
        'TABLE_COLLATION' => 'utf8mb4_bin', 'TABLE_COMMENT' => 'marketplace-ms6-schema-v1', 'CREATE_OPTIONS' => '', 'AUTO_INCREMENT' => null]
        && array_keys($meta['columns']) === ['id', 'schema_version', 'min_app_version', 'updated_at']
        && $meta['foreign'] === [] && $meta['triggers'] === [], 'NHECU_SCHEMA_EXACT_DECLARED_BASE_TABLE');
    foreach (['id' => [['tinyint unsigned', 'tinyint(3) unsigned'], null],
        'schema_version' => [['int unsigned', 'int(10) unsigned'], null], 'min_app_version' => [['varchar(32)'], 'ascii'],
        'updated_at' => [['int unsigned', 'int(10) unsigned'], null]] as $name => [$types, $charset]) {
        $column = $meta['columns'][$name];
        nhecr_expect(in_array(strtolower($column['COLUMN_TYPE']), $types, true)
            && $column === ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $column['COLUMN_TYPE'], 'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null, 'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $charset === null ? null : 'ascii_bin',
                'EXTRA' => '', 'GENERATION_EXPRESSION' => '', 'COLUMN_COMMENT' => ''], 'NHECU_SCHEMA_EXACT_FOUR_NATIVE_COLUMN_DEFINITIONS');
    }
    nhecr_expect(array_keys($meta['indexes']) === ['PRIMARY'] && count($meta['indexes']['PRIMARY']) === 1, 'NHECU_SCHEMA_ONLY_PRIMARY_INDEX');
    $index = $meta['indexes']['PRIMARY'][0];
    nhecr_expect((string)$index['NON_UNIQUE'] === '0' && (string)$index['SEQ_IN_INDEX'] === '1'
        && $index === ['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => $index['NON_UNIQUE'], 'SEQ_IN_INDEX' => $index['SEQ_IN_INDEX'],
            'COLUMN_NAME' => 'id', 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null,
            'COLLATION' => 'A', 'INDEX_COMMENT' => ''], 'NHECU_SCHEMA_COMPLETE_PRIMARY_INDEX_METADATA');
    nhecr_expect($meta['constraints'] === [
        ['CONSTRAINT_NAME' => 'eb_mm_schema_id', 'CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES'],
        ['CONSTRAINT_NAME' => 'eb_mm_schema_positive', 'CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES'],
        ['CONSTRAINT_NAME' => 'PRIMARY', 'CONSTRAINT_TYPE' => 'PRIMARY KEY', 'ENFORCED' => 'YES']] && count($meta['checks']) === 2, 'NHECU_SCHEMA_EXACT_ENFORCED_CONSTRAINTS');
    $expected = ['eb_mm_schema_id' => 'id=1', 'eb_mm_schema_positive' => 'schema_version>=1'];
    foreach ($meta['checks'] as $check) {
        nhecr_keys($check, ['CONSTRAINT_NAME', 'ENFORCED', 'CHECK_CLAUSE']); $name = $check['CONSTRAINT_NAME'];
        nhecr_expect(isset($expected[$name]) && $check['ENFORCED'] === 'YES'
            && strtolower((string)preg_replace('/[\s`()]+/', '', $check['CHECK_CLAUSE'])) === $expected[$name], 'NHECU_SCHEMA_TWO_FIXED_CHECK_EXPRESSIONS');
        unset($expected[$name]);
    }
    nhecr_expect($expected === [], 'NHECU_SCHEMA_COMPLETE_FIXED_CHECK_SET');
}
function nhecu_baseline(array $state, array $database, array $cache, array $driverAfter): void
{
    $upgrade = $state['structure_upgrade']; $before = $upgrade['before']['snapshot']; $after = $upgrade['after']['snapshot'];
    $wanted = array_merge($driverAfter['names'], nhecu_newTables()); sort($wanted, SORT_STRING);
    nhecr_expect(count($driverAfter['names']) === 166 && $before['names'] === $driverAfter['names']
        && $after['names'] === $wanted && count($wanted) === 173 && $database['database']['names'] === $wanted, 'NHECU_REAL_EXACT_ORIGINAL166_PLUS_SEVEN');
    $modes = []; foreach (['session_mode', 'global_mode', 'session_innodb', 'global_innodb'] as $key) $modes[$key] = $database['database']['modes'][0][$key];
    nhecr_expect([$modes] === $driverAfter['modes'] && [$modes] === $before['modes'] && [$modes] === $after['modes'], 'NHECU_ORIGINAL_SQL_MODES_ALL_BOUNDARIES');
    foreach ($driverAfter['tables'] as $name => $old) {
        $rows = $database['rows'][$name]; $full166 = $before['tables'][$name];
        nhecr_expect(nhecu_jsonProjection($rows, $old['projected_columns']) === $old['projection']
            && nhecu_jsonProjection($rows, $full166['projected_columns']) === $full166['projection'], 'NHECU_INDEPENDENT_OLD_DRIVER_AND_FULL166_JSON_PROJECTIONS');
        if ($name === 'eb_store_order_refund') nhecu_refundPreserved($full166['metadata'], $after['tables'][$name]['metadata'], $rows);
        else nhecr_expect($after['tables'][$name] === $full166, 'NHECU_ALL_165_NON_TARGETS_FULL_DDL_METADATA_DATA_AI');
    }
    foreach ($after['tables'] as $name => $expected) {
        $table = $database['database']['tables'][$name];
        nhecr_expect($table['metadata'] === $expected['metadata'] && hash('sha256', $table['ddl']) === $expected['ddl_sha256']
            && $table['projected_columns'] === $expected['projected_columns']
            && nhecu_jsonProjection($database['rows'][$name], $expected['projected_columns']) === $expected['projection'], 'NHECU_COMPLETE173_REAL_METADATA_DDL_FULL_COLUMN_ROWS');
    }
    foreach (array_slice(nhecu_newTables(), 0, 6) as $name) nhecr_expect($database['rows'][$name] === [], 'NHECU_ALL_SIX_NEW_BUSINESS_TABLES_EMPTY');
    $row = $upgrade['schema_row']; $metadata = $database['database']['tables']['eb_marketplace_schema']['metadata'];
    nhecu_schemaMetadata($metadata);
    $native = []; foreach ($row as $key => $value) $native[$key] = m4lh_actor_native($metadata['columns'][$key], $value);
    nhecr_expect($database['rows']['eb_marketplace_schema'] === [$native], 'NHECU_SCHEMA_EXACT_SOURCE_VERSION_REAL_TIMESTAMP_SINGLETON');
}
'''

_UPGRADE_BASELINE_OLD = rb'''function nhecr_baseline(array $state, array $database, array $cache): array
{
    nhecr_db_validate($database); nhepar_capture($cache);
    $handoff = $state['handoff'];
    nhecr_keys($handoff, ['schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'legacy_parent', 'identities',
        'seal', 'migration', 'driver_after', 'current_reader_authorized', 'remaining']);
    nhecr_expect($handoff['schema'] === 1 && $handoff['scope'] === 'CURRENT_READER_SEALED_BASELINE_PREREQUISITES_ONLY'
        && $handoff['run_id'] === $state['context']['run_id'] && $handoff['source_sha'] === $state['context']['source_sha']
        && $handoff['freeze_sha256'] === $state['context']['freeze_sha256'] && $handoff['current_reader_authorized'] === false
        && $handoff['remaining'] === 'CURRENT_ROLE_RETIREMENT_ROOT_SOURCE_AND_PRIVATE_FIXTURE_CONTEXT_UNBOUND',
        'NHECR_BASELINE_ONLY_REAL_EXACT13_FOURCLI_PREREQUISITES');
    $raw = $state['public_inputs']['/run/audit/evidence/ms4-migration-normal.json']['raw'];
    $driver = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
    $seal = json_decode($state['public_inputs']['/run/audit/evidence/legacy-history-producer-result.json']['raw'], true, 256, JSON_THROW_ON_ERROR);
    nhecr_expect($seal === $handoff['seal'] && $driver['snapshots']['before'] === $seal['expected_m4m']
        && $driver['snapshots']['after'] === $handoff['driver_after'] && $handoff['identities'] === $seal['identities']
        && $handoff['legacy_parent'] === $seal['producer']['native_parent'], 'NHECR_BASELINE_SAME_ORIGINAL_VALIDATED_PUBLIC_OBJECTS');
    $after = $handoff['driver_after']; nhecr_keys($after, ['names', 'tables', 'redis', 'modes']);
    nhecr_expect(count($after['names']) === 166 && array_keys($after['tables']) === $after['names'],
        'NHECR_BASELINE_COMPLETE166_TABLE_MAP_NO_SKIPPED_OR_EXTRA_TABLE');
    nhecr_expect($database['database']['names'] === $after['names'], 'NHECR_REAL_166_NAMES_AFTER_ORIGINAL_FOUR_CLI');
    $modes = $database['database']['modes'][0]; $selected = [];
    foreach (['session_mode', 'global_mode', 'session_innodb', 'global_innodb'] as $key) $selected[$key] = $modes[$key];
    nhecr_expect([$selected] === $after['modes'], 'NHECR_ORIGINAL_FOUR_OBSERVER_MODES');
    foreach ($after['tables'] as $name => $expected) {
        $table = $database['database']['tables'][$name];
        nhecr_expect($table['metadata'] === $expected['metadata'] && hash('sha256', $table['ddl']) === $expected['ddl_sha256'],
            'NHECR_COMPLETE_POST_MIGRATION_NATIVE_METADATA_DDL_AI');
        $hashes = [];
        foreach ($database['rows'][$name] as $row) {
            $projected = []; foreach ($expected['projected_columns'] as $column) $projected[$column] = $row[$column];
            $hashes[] = hash('sha256', json_encode($projected, JSON_THROW_ON_ERROR));
        }
        sort($hashes, SORT_STRING);
        nhecr_expect(['rows' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))] === $expected['projection'],
            'NHECR_INDEPENDENT_FULL_CURRENT_ROWS_ORIGINAL_COLUMN_PROJECTION');
    }
    foreach (['eb_store_order' => ['marketplace_trade_version' => 0, 'merchant_name_snapshot' => null, 'merchant_after_sale_contact_snapshot' => null],
        'eb_store_order_cart_info' => ['mer_id' => null], 'eb_store_order_refund' => ['mer_id' => null, 'marketplace_refund_mode' => null],
        'eb_store_product_reply' => ['mer_id' => null]] as $table => $values)
        foreach ($database['rows'][$table] as $row) foreach ($values as $key => $value)
            nhecr_expect($row[$key] === $value, 'NHECR_SEVEN_ORIGINAL_ADDED_NATIVE_DEFAULTS_NOT_BACKFILL');
    $expectedRedis = $after['redis'];
    foreach ($expectedRedis as $key => $descriptor) if ($descriptor['expires'] > 0
        && bccomp($cache['snapshot']['time_ms'], (string)$descriptor['expires'], 0) > 0) unset($expectedRedis[$key]);
    $currentRedis = [];
    foreach ($cache['snapshot']['keys'] as $key => $descriptor) $currentRedis[$key] = ['sha256' => $descriptor['sha256'], 'expires' => $descriptor['expires']];
    nhecr_expect($currentRedis === $expectedRedis, 'NHECR_ONLY_REAL_TIME_PASSIVE_EXPIRY_AFTER_WRITERS_STOPPED');
    return $state['handoff']['identities'];
}'''
_UPGRADE_BASELINE_NEW = rb'''function nhecr_baseline(array $state, array $database, array $cache): array
{
    nhecr_db_validate($database); nhepar_capture($cache);
    $handoff = $state['handoff'];
    nhecr_keys($handoff, ['schema', 'scope', 'run_id', 'source_sha', 'freeze_sha256', 'legacy_parent', 'identities',
        'seal', 'migration', 'driver_after', 'current_reader_authorized', 'remaining']);
    nhecr_expect($handoff['schema'] === 1 && $handoff['scope'] === 'CURRENT_READER_SEALED_BASELINE_PREREQUISITES_ONLY'
        && $handoff['run_id'] === $state['context']['run_id'] && $handoff['source_sha'] === $state['context']['source_sha']
        && $handoff['freeze_sha256'] === $state['context']['freeze_sha256'] && $handoff['current_reader_authorized'] === false
        && $handoff['remaining'] === 'CURRENT_ROLE_RETIREMENT_ROOT_SOURCE_AND_PRIVATE_FIXTURE_CONTEXT_UNBOUND',
        'NHECR_BASELINE_ONLY_REAL_EXACT13_FOURCLI_PREREQUISITES');
    $raw = $state['public_inputs']['/run/audit/evidence/ms4-migration-normal.json']['raw'];
    $driver = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
    $seal = json_decode($state['public_inputs']['/run/audit/evidence/legacy-history-producer-result.json']['raw'], true, 256, JSON_THROW_ON_ERROR);
    nhecr_expect($seal === $handoff['seal'] && $driver['snapshots']['before'] === $seal['expected_m4m']
        && $driver['snapshots']['after'] === $handoff['driver_after'] && $handoff['identities'] === $seal['identities']
        && $handoff['legacy_parent'] === $seal['producer']['native_parent'], 'NHECR_BASELINE_SAME_ORIGINAL_VALIDATED_PUBLIC_OBJECTS');
    $after = $handoff['driver_after']; nhecr_keys($after, ['names', 'tables', 'redis', 'modes']);
    nhecr_expect(count($after['names']) === 166 && array_keys($after['tables']) === $after['names'],
        'NHECR_BASELINE_COMPLETE166_TABLE_MAP_NO_SKIPPED_OR_EXTRA_TABLE');
    nhecu_baseline($state, $database, $cache, $after);
    foreach (['eb_store_order' => ['marketplace_trade_version' => 0, 'merchant_name_snapshot' => null, 'merchant_after_sale_contact_snapshot' => null],
        'eb_store_order_cart_info' => ['mer_id' => null], 'eb_store_order_refund' => ['mer_id' => null, 'marketplace_refund_mode' => null],
        'eb_store_product_reply' => ['mer_id' => null]] as $table => $values)
        foreach ($database['rows'][$table] as $row) foreach ($values as $key => $value)
            nhecr_expect($row[$key] === $value, 'NHECR_SEVEN_ORIGINAL_ADDED_NATIVE_DEFAULTS_NOT_BACKFILL');
    $expectedRedis = $after['redis'];
    foreach ($expectedRedis as $key => $descriptor) if ($descriptor['expires'] > 0
        && bccomp($cache['snapshot']['time_ms'], (string)$descriptor['expires'], 0) > 0) unset($expectedRedis[$key]);
    $currentRedis = [];
    foreach ($cache['snapshot']['keys'] as $key => $descriptor) $currentRedis[$key] = ['sha256' => $descriptor['sha256'], 'expires' => $descriptor['expires']];
    nhecr_expect($currentRedis === $expectedRedis, 'NHECR_ONLY_REAL_TIME_PASSIVE_EXPIRY_AFTER_WRITERS_STOPPED');
    return $state['handoff']['identities'];
}'''
_UPGRADE_ADDITIONAL_SEAMS = (
    ('upgrade_qualification_public', b'    $proofs = []; $handoff = nhecr_public_handoff($context, $proofs); $actorProofs = [];', b'    $proofs = []; $handoff = nhecr_public_handoff($context, $proofs); $actorProofs = [];\n    $upgrade = nhecu_upgradePublic($context, $freeze, $handoff, $proofs);'),
    ('upgrade_retirement_prerequisite', b"    foreach (['producer_retired' => 'legacy-history-producer-retired.json', 'driver_retired' => 'driver-retired.json'] as $key => $leaf) {", b"    foreach (['producer_retired' => 'legacy-history-producer-retired.json', 'driver_retired' => 'driver-retired.json',\n        'upgrade_retired' => 'current-structure-upgrade-retired.json'] as $key => $leaf) {"),
    ('upgrade_state_binding', b"        'handoff' => $handoff, 'history_actors_receipt' => $actorReceipt, 'public_inputs' => $proofs, 'actor_public_inputs' => $actorProofs];", b"        'handoff' => $handoff, 'structure_upgrade' => $upgrade, 'history_actors_receipt' => $actorReceipt, 'public_inputs' => $proofs, 'actor_public_inputs' => $actorProofs];"),
    ('upgrade_fresh_complete_join', b"        'binding_raw', 'container_id', 'source_stats', 'handoff', 'history_actors_receipt', 'public_inputs', 'actor_public_inputs',", b"        'binding_raw', 'container_id', 'source_stats', 'handoff', 'structure_upgrade', 'history_actors_receipt', 'public_inputs', 'actor_public_inputs',"),
    ('nhecr_db_capture_full173', b"function nhecr_db_capture(PDO $db, string $runId): array\n{\n    m4lh_actor_identity($db, $runId); $modes = nhep_database_modes($db);\n    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);\n    nhep_expect(count($names) === 166 && count(array_unique($names, SORT_STRING)) === 166,\n        'NHE_DATABASE_COMPLETE_CURRENT_166_TABLES');\n    $database = ['names' => $names, 'modes' => $modes, 'tables' => []]; $allRows = [];\n    foreach ($names as $name) {\n        nhep_expect(is_string($name) && preg_match('/\\A(?:eb_[a-z0-9_]+|audit_sentinel)\\z/D', $name) === 1,\n            'NHE_DATABASE_ONLY_ACTUAL_OWN_TABLE_NAMESPACE');\n        $metadata = m4m_metadata($db, $name); $columns = array_keys($metadata['columns']);\n        nhep_expect($columns !== [], 'NHE_DATABASE_COMPLETE_NONEMPTY_COLUMN_ORDER');\n        $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($name));\n        foreach ($rows as $row) nhep_expect(array_keys($row) === $columns, 'NHE_DATABASE_ALL_NATIVE_COLUMNS_IN_ORIGINAL_ORDER');\n        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));\n        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);\n        nhep_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'NHE_DATABASE_COMPLETE_ORIGINAL_DDL');\n        $database['tables'][$name] = ['metadata' => $metadata, 'ddl' => $ddl[1],\n            'projected_columns' => $columns, 'projection' => m4lh_actor_projection($rows)];\n        $allRows[$name] = $rows;\n    }\n    nhep_expect(nhep_database_modes($db) === $modes, 'NHE_DATABASE_OBSERVER_MODES_PRESERVED_DURING_FULL_SCAN');\n    return ['database' => $database, 'rows' => $allRows];\n}", b"function nhecr_db_capture(PDO $db, string $runId): array\n{\n    m4lh_actor_identity($db, $runId); $modes = nhep_database_modes($db);\n    $names = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names, SORT_STRING);\n    nhep_expect(count($names) === 173 && count(array_unique($names, SORT_STRING)) === 173,\n        'NHE_DATABASE_COMPLETE_CURRENT_173_TABLES');\n    $database = ['names' => $names, 'modes' => $modes, 'tables' => []]; $allRows = [];\n    foreach ($names as $name) {\n        nhep_expect(is_string($name) && preg_match('/\\A(?:eb_[a-z0-9_]+|audit_sentinel)\\z/D', $name) === 1,\n            'NHE_DATABASE_ONLY_ACTUAL_OWN_TABLE_NAMESPACE');\n        $metadata = m4m_metadata($db, $name); $columns = array_keys($metadata['columns']);\n        nhep_expect($columns !== [], 'NHE_DATABASE_COMPLETE_NONEMPTY_COLUMN_ORDER');\n        $rows = m4m_rows($db, 'SELECT ' . implode(',', array_map('m4m_quote', $columns)) . ' FROM ' . m4m_quote($name));\n        foreach ($rows as $row) nhep_expect(array_keys($row) === $columns, 'NHE_DATABASE_ALL_NATIVE_COLUMNS_IN_ORIGINAL_ORDER');\n        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));\n        $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($name))->fetch(PDO::FETCH_NUM);\n        nhep_expect(is_array($ddl) && is_string($ddl[1] ?? null), 'NHE_DATABASE_COMPLETE_ORIGINAL_DDL');\n        $database['tables'][$name] = ['metadata' => $metadata, 'ddl' => $ddl[1],\n            'projected_columns' => $columns, 'projection' => m4lh_actor_projection($rows)];\n        $allRows[$name] = $rows;\n    }\n    nhep_expect(nhep_database_modes($db) === $modes, 'NHE_DATABASE_OBSERVER_MODES_PRESERVED_DURING_FULL_SCAN');\n    return ['database' => $database, 'rows' => $allRows];\n}"),
    ('nhecr_db_expected_full173', b"function nhecr_db_expected(array $before, array $expectedRows, array $insertCounts = []): array\n{\n    nhe_keys($before, ['database', 'rows'], 'NHE_DATABASE_COMPLETE_BEFORE_MODEL_KEYS');\n    nhe_keys($before['database'], ['names', 'modes', 'tables'], 'NHE_DATABASE_ORIGINAL_DATABASE_MODEL_KEYS');\n    $names = $before['database']['names']; $sorted = $names; sort($sorted, SORT_STRING);\n    nhep_expect(array_is_list($names) && count($names) === 166 && $names === $sorted\n        && count(array_unique($names, SORT_STRING)) === 166\n        && array_keys($before['database']['tables']) === $names && array_keys($before['rows']) === $names\n        && array_diff(array_keys($expectedRows), $names) === []\n        && array_diff(array_keys($insertCounts), array_keys($expectedRows)) === [], 'NHE_DATABASE_COMPLETE_BEFORE_AND_EXPLICIT_MODEL_TABLES');\n    $expected = $before;\n    foreach ($expectedRows as $name => $rows) {\n        $old = $before['database']['tables'][$name];\n        nhep_expect(is_array($rows) && array_is_list($rows)\n            && $old['projected_columns'] === array_keys($old['metadata']['columns']), 'NHE_DATABASE_ORIGINAL_FULL_EXPECTED_ROW_COLUMNS');\n        foreach ($rows as $row) nhep_expect(is_array($row) && array_keys($row) === $old['projected_columns'],\n            'NHE_DATABASE_EACH_INDEPENDENT_EXPECTED_FULL_ROW');\n        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));\n        $expected['rows'][$name] = $rows;\n        $expected['database']['tables'][$name]['projection'] = m4lh_actor_projection($rows);\n        $count = $insertCounts[$name] ?? 0;\n        nhep_expect(is_int($count) && $count >= 0 && $count <= 4294967294, 'NHE_DATABASE_INDEPENDENT_NATURAL_INSERT_COUNT');\n        if ($count === 0) continue;\n        $auto = [];\n        foreach ($old['metadata']['columns'] as $column => $definition)\n            if ($definition['EXTRA'] === 'auto_increment') $auto[] = $column;\n        nhep_expect(count($auto) === 1, 'NHE_DATABASE_COUNTER_ONLY_ON_ORIGINAL_AUTO_INCREMENT_COLUMN');\n        $id = m4lh_actor_next($old, $auto[0]); $next = $id + $count;\n        nhep_expect($next <= 4294967295, 'NHE_DATABASE_ORIGINAL_NORMAL_BUSINESS_COUNTER_RANGE');\n        $counter = $old['metadata']['table']['AUTO_INCREMENT'];\n        $expected['database']['tables'][$name]['metadata']['table']['AUTO_INCREMENT'] = is_int($counter) ? $next : (string)$next;\n        $expected['database']['tables'][$name]['ddl'] = m4lh_actor_counter_ddl($old['ddl'], $id, $next);\n    }\n    return $expected;\n}", b"function nhecr_db_expected(array $before, array $expectedRows, array $insertCounts = []): array\n{\n    nhe_keys($before, ['database', 'rows'], 'NHE_DATABASE_COMPLETE_BEFORE_MODEL_KEYS');\n    nhe_keys($before['database'], ['names', 'modes', 'tables'], 'NHE_DATABASE_ORIGINAL_DATABASE_MODEL_KEYS');\n    $names = $before['database']['names']; $sorted = $names; sort($sorted, SORT_STRING);\n    nhep_expect(array_is_list($names) && count($names) === 173 && $names === $sorted\n        && count(array_unique($names, SORT_STRING)) === 173\n        && array_keys($before['database']['tables']) === $names && array_keys($before['rows']) === $names\n        && array_diff(array_keys($expectedRows), $names) === []\n        && array_diff(array_keys($insertCounts), array_keys($expectedRows)) === [], 'NHE_DATABASE_COMPLETE_BEFORE_AND_EXPLICIT_MODEL_TABLES');\n    nhep_expect(array_intersect(array_keys($expectedRows), nhecu_newTables()) === [], 'NHECU_SEVEN_NEW_TABLES_ALWAYS_NON_TARGET');\n    $expected = $before;\n    foreach ($expectedRows as $name => $rows) {\n        $old = $before['database']['tables'][$name];\n        nhep_expect(is_array($rows) && array_is_list($rows)\n            && $old['projected_columns'] === array_keys($old['metadata']['columns']), 'NHE_DATABASE_ORIGINAL_FULL_EXPECTED_ROW_COLUMNS');\n        foreach ($rows as $row) nhep_expect(is_array($row) && array_keys($row) === $old['projected_columns'],\n            'NHE_DATABASE_EACH_INDEPENDENT_EXPECTED_FULL_ROW');\n        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));\n        $expected['rows'][$name] = $rows;\n        $expected['database']['tables'][$name]['projection'] = m4lh_actor_projection($rows);\n        $count = $insertCounts[$name] ?? 0;\n        nhep_expect(is_int($count) && $count >= 0 && $count <= 4294967294, 'NHE_DATABASE_INDEPENDENT_NATURAL_INSERT_COUNT');\n        if ($count === 0) continue;\n        $auto = [];\n        foreach ($old['metadata']['columns'] as $column => $definition)\n            if ($definition['EXTRA'] === 'auto_increment') $auto[] = $column;\n        nhep_expect(count($auto) === 1, 'NHE_DATABASE_COUNTER_ONLY_ON_ORIGINAL_AUTO_INCREMENT_COLUMN');\n        $id = m4lh_actor_next($old, $auto[0]); $next = $id + $count;\n        nhep_expect($next <= 4294967295, 'NHE_DATABASE_ORIGINAL_NORMAL_BUSINESS_COUNTER_RANGE');\n        $counter = $old['metadata']['table']['AUTO_INCREMENT'];\n        $expected['database']['tables'][$name]['metadata']['table']['AUTO_INCREMENT'] = is_int($counter) ? $next : (string)$next;\n        $expected['database']['tables'][$name]['ddl'] = m4lh_actor_counter_ddl($old['ddl'], $id, $next);\n    }\n    return $expected;\n}"),
    ('nhecr_db_validate_full173', b"function nhecr_db_validate(array $view): void\n{\n    nhe_keys($view, ['database', 'rows'], 'NHE_AUTH_DB_FULL_VIEW');\n    nhe_keys($view['database'], ['names', 'modes', 'tables'], 'NHE_AUTH_DB_FULL_DATABASE');\n    $names = $view['database']['names']; $sorted = $names; sort($sorted, SORT_STRING);\n    nhep_expect(array_is_list($names) && count($names) === 166 && $names === $sorted\n        && count(array_unique($names, SORT_STRING)) === 166 && is_array($view['database']['modes'])\n        && array_keys($view['database']['tables']) === $names && array_keys($view['rows']) === $names,\n        'NHE_AUTH_DB_ORIGINAL_ALL_166_TABLES');\n    foreach ($names as $name) {\n        $table = $view['database']['tables'][$name];\n        nhe_keys($table, ['metadata', 'ddl', 'projected_columns', 'projection'], 'NHE_AUTH_DB_FULL_TABLE');\n        nhep_expect(is_array($table['metadata']) && is_array($table['metadata']['columns'] ?? null)\n            && is_string($table['ddl']) && $table['ddl'] !== ''\n            && $table['projected_columns'] === array_keys($table['metadata']['columns'])\n            && is_array($view['rows'][$name]) && array_is_list($view['rows'][$name]),\n            'NHE_AUTH_DB_ORIGINAL_COLUMNS_DDL_AND_ROWS');\n        foreach ($view['rows'][$name] as $row) nhep_expect(is_array($row)\n            && array_keys($row) === $table['projected_columns'], 'NHE_AUTH_DB_EACH_FULL_ORIGINAL_ROW');\n        $rows = $view['rows'][$name];\n        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));\n        nhep_expect($rows === $view['rows'][$name]\n            && m4lh_actor_projection($rows) === $table['projection'], 'NHE_AUTH_DB_FULL_NATIVE_PROJECTION');\n    }\n    foreach (['eb_system_admin', 'eb_user', 'eb_system_log', 'eb_system_menus',\n        'eb_marketplace_merchant', 'eb_marketplace_employee', 'eb_marketplace_audit_log',\n        'eb_system_event', 'eb_system_notification', 'eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'] as $name)\n        nhep_expect(in_array($name, $names, true), 'NHE_AUTH_DB_REQUIRED_REAL_TABLE');\n}", b"function nhecr_db_validate(array $view): void\n{\n    nhe_keys($view, ['database', 'rows'], 'NHE_AUTH_DB_FULL_VIEW');\n    nhe_keys($view['database'], ['names', 'modes', 'tables'], 'NHE_AUTH_DB_FULL_DATABASE');\n    $names = $view['database']['names']; $sorted = $names; sort($sorted, SORT_STRING);\n    nhep_expect(array_is_list($names) && count($names) === 173 && $names === $sorted\n        && count(array_unique($names, SORT_STRING)) === 173 && is_array($view['database']['modes'])\n        && array_keys($view['database']['tables']) === $names && array_keys($view['rows']) === $names,\n        'NHE_AUTH_DB_ORIGINAL_ALL_173_TABLES');\n    foreach ($names as $name) {\n        $table = $view['database']['tables'][$name];\n        nhe_keys($table, ['metadata', 'ddl', 'projected_columns', 'projection'], 'NHE_AUTH_DB_FULL_TABLE');\n        nhep_expect(is_array($table['metadata']) && is_array($table['metadata']['columns'] ?? null)\n            && is_string($table['ddl']) && $table['ddl'] !== ''\n            && $table['projected_columns'] === array_keys($table['metadata']['columns'])\n            && is_array($view['rows'][$name]) && array_is_list($view['rows'][$name]),\n            'NHE_AUTH_DB_ORIGINAL_COLUMNS_DDL_AND_ROWS');\n        foreach ($view['rows'][$name] as $row) nhep_expect(is_array($row)\n            && array_keys($row) === $table['projected_columns'], 'NHE_AUTH_DB_EACH_FULL_ORIGINAL_ROW');\n        $rows = $view['rows'][$name];\n        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));\n        nhep_expect($rows === $view['rows'][$name]\n            && m4lh_actor_projection($rows) === $table['projection'], 'NHE_AUTH_DB_FULL_NATIVE_PROJECTION');\n    }\n    foreach (['eb_system_admin', 'eb_user', 'eb_system_log', 'eb_system_menus',\n        'eb_marketplace_merchant', 'eb_marketplace_employee', 'eb_marketplace_audit_log',\n        'eb_system_event', 'eb_system_notification', 'eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'] as $name)\n        nhep_expect(in_array($name, $names, true), 'NHE_AUTH_DB_REQUIRED_REAL_TABLE');\n    foreach (nhecu_newTables() as $name) nhep_expect(in_array($name, $names, true), 'NHECU_ALL_SEVEN_CURRENT_STRUCTURE_TABLES_REQUIRED');\n}"),
    ('private_fixture_current173_7', b"    nhecr_expect(($state['private_fixture_context']['scope'] ?? null) === 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT'\n", b"    nhecr_expect(($state['private_fixture_context']['scope'] ?? null) === 'CURRENT_READER_VALIDATED173_PRIVATE_FIXTURE_CONTEXT'\n"),
    ('private_fixture_current173_8', b"    $fixture = ['schema' => 1, 'scope' => 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT', 'run_id' => $run,\n", b"    $fixture = ['schema' => 1, 'scope' => 'CURRENT_READER_VALIDATED173_PRIVATE_FIXTURE_CONTEXT', 'run_id' => $run,\n"),
    ('private_fixture_current173_9', b"    nhep_expect($fixture['schema'] === 1 && $fixture['scope'] === 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT'\n", b"    nhep_expect($fixture['schema'] === 1 && $fixture['scope'] === 'CURRENT_READER_VALIDATED173_PRIVATE_FIXTURE_CONTEXT'\n"),
    ('private_fixture_current173_10', b"        && ($fixture['scope'] ?? null) === 'CURRENT_READER_VALIDATED166_PRIVATE_FIXTURE_CONTEXT'\n", b"        && ($fixture['scope'] ?? null) === 'CURRENT_READER_VALIDATED173_PRIVATE_FIXTURE_CONTEXT'\n"),
    ('current_boundary_label_11', b'NHECR_BINDING_ORIGINAL_TWO_NORMAL_RETIRES', b'NHECR_BINDING_ORIGINAL_THREE_NORMAL_RETIRES'),
    ('current_boundary_label_12', b'NHECR_CURRENT_APP_INITIALIZATION_INDEPENDENT_166_CACHE_FILES_ONLY_NATURAL_EXPIRY', b'NHECR_CURRENT_APP_INITIALIZATION_INDEPENDENT_173_CACHE_FILES_ONLY_NATURAL_EXPIRY'),
    ('current_boundary_label_13', b'NHE_HISTORY_IDENTITIES_DISTINCT_PRIVATE166_CONTEXT_NOT_AN_ORIGINAL_RECEIPT', b'NHE_HISTORY_IDENTITIES_DISTINCT_PRIVATE173_CONTEXT_NOT_AN_ORIGINAL_RECEIPT'),
    ('current_boundary_label_14', b'NHE_HISTORY_IDENTITIES_SAME_VALIDATED_PRIVATE166_NATIVE_CONTEXT_SCOPE', b'NHE_HISTORY_IDENTITIES_SAME_VALIDATED_PRIVATE173_NATIVE_CONTEXT_SCOPE'),
    ('current_boundary_label_15', b'NHECR_COMPLETE166_CURRENT_LOGIN_OR_AUTH_ONLY_NATIVE_USER_TIME_IP', b'NHECR_COMPLETE173_CURRENT_LOGIN_OR_AUTH_ONLY_NATIVE_USER_TIME_IP'),
    ('current_boundary_label_16', b'NHECR_FIRST_BEFORE_IS_REAL_SEALED_CURRENT_166_BASELINE', b'NHECR_FIRST_BEFORE_IS_REAL_SEALED_CURRENT_173_BASELINE'),
    ('current_boundary_label_17', b'NHECR_ALL_WRITERS_NORMAL_STOPPED_FINAL166_FILES_AND_ONLY_PASSIVE_REDIS_EXPIRY', b'NHECR_ALL_WRITERS_NORMAL_STOPPED_FINAL173_FILES_AND_ONLY_PASSIVE_REDIS_EXPIRY'),
    ('current_boundary_label_18', b'NHECR_CAT_FULL_166_TABLE_INDEPENDENT_EXPECTED', b'NHECR_CAT_FULL_173_TABLE_INDEPENDENT_EXPECTED'),
    ('upgrade_independent_baseline', _UPGRADE_BASELINE_OLD, _UPGRADE_BASELINE_NEW),
    ('upgrade_pure_public_definitions', b'/** Full166 capture must join the original completed migration snapshot without discarding new columns. */', _UPGRADE_SHARED_CONTRACTS + _UPGRADE_READER_FUNCTIONS + b'\n/** Full173 capture joins the preserved original four-CLI166 and the independent structure upgrade. */'),
)
READER_SEAMS += _UPGRADE_ADDITIONAL_SEAMS

# Final source adoption uses actual external original bytes; no review is authored
# or inferred here. The Windows pointers are identifiers, never Linux file paths.
_SOURCE_ADOPTION_FUNCTIONS = rb'''/** Public source-only adoption shared by the reader and the actual initializer gate. */
function nhecr_source_handoff(array $context, array $freeze, string $artifactRoot, array &$proofs): void
{
    if ($artifactRoot === '/run/ms4-prep') {
        $proof = getenv('MS4_PROOF_NAME');
        nhecr_expect(function_exists('m4i_gate') && PHP_SAPI === 'cli'
            && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === '/run/ms4-prep/ms4-initialize.php'
            && in_array($proof, ['current-structure-upgrade', 'register-legacy-current-reader'], true),
            'NHECR_SOURCE_REAL_INITIALIZER_GATE_REQUIRED');
        m4i_gate($proof === 'current-structure-upgrade' ? 'current-structure-upgrade' : 'register');
    } else nhecr_expect($artifactRoot === '/run/nonempty' && __FILE__ === '/run/nonempty/legacy-current-reader.php'
        && realpath(__FILE__) === __FILE__, 'NHECR_SOURCE_FIXED_READER_ARTIFACT_ROOT');
    nhecr_expect(is_string($context['source_sha'] ?? null) && preg_match('/\A[a-f0-9]{40}\z/D', $context['source_sha']) === 1
        && ($freeze['source_sha'] ?? null) === $context['source_sha'] && ($freeze['status'] ?? null) === 'CLEAN_SOURCE_FROZEN_ONLY'
        && ($freeze['runtime_SQL_App'] ?? null) === 'NOT RUN'
        && ($freeze['nonempty_history_runtime'] ?? null) === 'SOURCE_ADOPTED_RUNTIME_NOT_RUN'
        && is_string($context['prep_dir'] ?? null) && preg_match('~\A(?:[A-Za-z]:/|/)~D', $context['prep_dir']) === 1
        && !str_contains($context['prep_dir'], '\\') && !str_contains($context['prep_dir'], "\0")
        && !preg_match('~(?:^|/)\.\.?(?:/|$)~D', $context['prep_dir']), 'NHECR_SOURCE_SAME_CURRENT_FREEZE_AND_HOST_PREP');
    $documents = [];
    foreach (['history_source_review' => 'history-source-review.json', 'history_source_publication' => 'history-source-publication.json'] as $key => $leaf) {
        $pin = $freeze[$key] ?? null; nhecr_expect(is_array($pin), 'NHECR_SOURCE_REAL_EXTERNAL_DESCRIPTOR_REQUIRED');
        nhecr_keys($pin, ['path', 'bytes', 'sha256']);
        nhecr_expect(is_string($pin['path']) && preg_match('~\A(?:[A-Za-z]:/|/)~D', $pin['path']) === 1
            && !str_contains($pin['path'], '\\') && !str_contains($pin['path'], "\0")
            && !preg_match('~(?:^|/)\.\.?(?:/|$)~D', $pin['path'])
            && is_int($pin['bytes']) && $pin['bytes'] > 0 && $pin['bytes'] <= 67108864
            && is_string($pin['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $pin['sha256']) === 1,
            'NHECR_SOURCE_COMPLETE_ORIGINAL_EXTERNAL_POINTER');
        $path = '/run/audit/' . $leaf; $documents[$key] = nhecu_public($path, $proofs);
        nhecr_expect(nhecu_same($proofs[$path]['pin'], array_replace($pin, ['path' => $path])),
            'NHECR_SOURCE_RUN_ALIAS_FULL_ORIGINAL_BYTES_AND_SHA');
    }
    $review = $documents['history_source_review']; $publication = $documents['history_source_publication'];
    nhecr_keys($review, ['schema', 'status', 'source_sha', 'publication', 'bindings']);
    nhecr_keys($publication, ['schema', 'scope', 'source_sha', 'artifact_files']);
    nhecr_expect($review['schema'] === 1 && $review['status'] === 'PASS_CURRENT_HISTORY_SOURCE_REVIEW'
        && $review['source_sha'] === $context['source_sha']
        && nhecu_same($review['publication'], $freeze['history_source_publication'])
        && $publication['schema'] === 1 && $publication['scope'] === 'CURRENT_HISTORY_SOURCE_PUBLICATION'
        && $publication['source_sha'] === $context['source_sha'], 'NHECR_SOURCE_REAL_REVIEW_PUBLICATION_SAME_SOURCE_JOIN');
    $names = ['README.md', 'compose.yml', 'ms4-host.py', 'ms4-initialize.php', 'seed-source-inverse.json',
        'legacy-app.php', 'legacy-files-bootstrap.php', 'legacy-normal-config.php', 'legacy-actors.php',
        'legacy-history-http.php', 'legacy-history-channel.php', 'legacy-history-producer.php',
        'legacy-history-router.php', 'legacy-current-reader.php'];
    $artifacts = $freeze['artifact_files'] ?? null;
    nhecr_expect(is_array($artifacts) && array_is_list($artifacts) && count($artifacts) === 14
        && array_column($artifacts, 'path') === $names && nhecu_same($publication['artifact_files'], $artifacts),
        'NHECR_SOURCE_COMPLETE_ORDERED_FOURTEEN_PUBLICATION_AND_FREEZE');
    $indexed = []; $raw = [];
    foreach ($artifacts as $pin) {
        nhecr_keys($pin, ['path', 'bytes', 'sha256']);
        nhecr_expect(is_int($pin['bytes']) && $pin['bytes'] > 0 && $pin['bytes'] <= 67108864,
            'NHECR_SOURCE_NONEMPTY_BOUNDED_ARTIFACT');
        $indexed[$pin['path']] = $pin; $path = $artifactRoot . '/' . $pin['path'];
        $raw[$pin['path']] = nhe_file($path, array_replace($pin, ['path' => $path]));
    }
    $legacy = $freeze['nonempty_history']; $current = $freeze['current_reader'];
    nhecr_keys($current, ['schema', 'source_sha', 'source_files', 'source_directories', 'control_files']);
    $paths = ['/run/nonempty/legacy-actors.php', '/run/nonempty/legacy-app.php', '/run/nonempty/legacy-current-reader.php',
        '/run/nonempty/legacy-files-bootstrap.php', '/run/nonempty/legacy-history-channel.php', '/run/nonempty/legacy-history-http.php',
        '/run/nonempty/legacy-history-producer.php', '/run/nonempty/legacy-history-router.php', '/run/nonempty/legacy-normal-config.php',
        '/workspace/help/audit-regressions/isolated/lib/guard.php', '/workspace/help/audit-regressions/isolated/lib/harness.php',
        '/workspace/help/audit-regressions/isolated/ms4-migration.php'];
    nhecr_expect($current['schema'] === 1 && $current['source_sha'] === $context['source_sha']
        && $legacy['legacy_source_sha'] === '477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
        && is_array($legacy['control_files']) && array_is_list($legacy['control_files']) && count($legacy['control_files']) === 11
        && is_array($current['control_files']) && array_is_list($current['control_files'])
        && count($current['control_files']) === 12 && array_column($current['control_files'], 'path') === $paths,
        'NHECR_SOURCE_ORIGINAL_ELEVEN_CURRENT_TWELVE_FIXED_CONTROLS');
    $producerControls = []; $sources = array_column($current['source_files'], null, 'path');
    foreach ($current['control_files'] as $pin) {
        nhecr_keys($pin, ['path', 'bytes', 'sha256']); $path = $pin['path'];
        if ($path !== '/run/nonempty/legacy-current-reader.php') $producerControls[] = $pin;
        if (str_starts_with($path, '/run/nonempty/')) {
            $leaf = substr($path, strlen('/run/nonempty/'));
            nhecr_expect(isset($indexed[$leaf]) && nhecu_same($pin, array_replace($indexed[$leaf], ['path' => $path])),
                'NHECR_SOURCE_EACH_BUNDLE_CONTROL_COMPLETE_ARTIFACT_JOIN');
            nhecr_expect(nhe_file($artifactRoot . '/' . $leaf, array_replace($pin, ['path' => $artifactRoot . '/' . $leaf])) === $raw[$leaf],
                'NHECR_SOURCE_EACH_ACTUAL_BUNDLE_CONTROL_FULL_BYTES');
        } else {
            $relative = substr($path, strlen('/workspace/'));
            nhecr_expect(isset($sources[$relative]) && nhecu_same($pin, array_replace($sources[$relative], ['path' => $path])),
                'NHECR_SOURCE_EACH_CURRENT_CONTROL_FULL_TREE_JOIN');
            foreach (['/workspace', '/source'] as $root) nhe_file($root . '/' . $relative, array_replace($pin, ['path' => $root . '/' . $relative]));
        }
    }
    nhecr_expect(nhecu_same($producerControls, $legacy['control_files']), 'NHECR_SOURCE_ALL_ORIGINAL_ELEVEN_CONTROLS_PRESERVED');
    $bindings = $review['bindings']; nhecr_keys($bindings, ['producer', 'library', 'artifacts', 'control_files', 'startup', 'reader', 'consumer']);
    $producerArtifacts = array_values(array_filter($artifacts, static fn(array $pin): bool => !in_array($pin['path'], ['ms4-host.py', 'legacy-current-reader.php'], true)));
    nhecr_expect(count($producerArtifacts) === 12 && nhecu_same($bindings['artifacts'], $producerArtifacts)
        && nhecu_same($bindings['control_files'], $legacy['control_files'])
        && nhecu_same($bindings['producer'], $indexed['legacy-history-producer.php'])
        && nhecu_same($bindings['library'], $indexed['legacy-app.php']), 'NHECR_SOURCE_FOUR_REAL_ORIGINAL_EXTERNAL_BINDINGS');
    foreach (['startup' => 'legacy-history-producer.php', 'reader' => 'legacy-current-reader.php', 'consumer' => 'ms4-host.py'] as $key => $leaf)
        nhecr_expect(nhecu_same($bindings[$key], array_replace($indexed[$leaf], ['path' => rtrim($context['prep_dir'], '/') . '/' . $leaf])),
            'NHECR_SOURCE_THREE_REAL_HOST_POINTER_BINDINGS_NO_HOST_PATH_OPEN');
    foreach ($artifacts as $pin) {
        $path = $artifactRoot . '/' . $pin['path'];
        nhecr_expect(nhe_file($path, array_replace($pin, ['path' => $path])) === $raw[$pin['path']], 'NHECR_SOURCE_ALL_FOURTEEN_ORIGINAL_BYTES_HELD');
    }
    nhecu_held($proofs);
}

/** Match real original public stage pins; never manufacture a registration stage. */
function nhecr_manifest_stages(array $context, array $manifest, array &$proofs, bool $reader): void
{
    $history = $manifest['nonempty_history'] ?? null; nhecr_expect(is_array($history), 'NHECR_SOURCE_REAL_HISTORY_MANIFEST');
    nhecr_keys($history, ['schema', 'stages']);
    $wanted = ['audit-legacy-files-bootstrap', 'audit-legacy-history-producer'];
    if ($reader) $wanted[] = 'audit-legacy-current-reader';
    sort($wanted, SORT_STRING); $names = array_keys($history['stages']); sort($names, SORT_STRING);
    nhecr_expect($history['schema'] === 1 && $names === $wanted, 'NHECR_SOURCE_EXACT_ORIGINAL_OR_READER_STAGE_SET');
    foreach ($wanted as $role) {
        $stage = $history['stages'][$role]; nhecr_keys($stage, ['created', 'binding']);
        $base = '/run/audit/evidence/' . $role;
        foreach (['created' => '-container-created.json', 'binding' => '-source-binding.json'] as $key => $suffix) {
            $path = $base . $suffix; nhecu_public($path, $proofs);
            nhecr_expect($stage[$key] === $proofs[$path]['pin'], 'NHECR_SOURCE_EACH_STAGE_REAL_ORIGINAL_FULL_PIN');
        }
        $made = json_decode($proofs[$base . '-container-created.json']['raw'], true, 128, JSON_THROW_ON_ERROR);
        $binding = json_decode($proofs[$base . '-source-binding.json']['raw'], true, 128, JSON_THROW_ON_ERROR);
        nhecr_expect($made['run_id'] === $context['run_id'] && $made['source_sha'] === $context['source_sha']
            && $made['labels']['com.docker.compose.service'] === $role
            && $binding['run_id'] === $context['run_id'] && $binding['source_sha'] === $context['source_sha']
            && $binding['role'] === $role && $binding['container_id'] === $made['id'], 'NHECR_SOURCE_EACH_STAGE_SAME_RUN_ROLE_SOURCE_CID');
        $alias = '/run/audit/evidence/' . substr($role, strlen('audit-')) . '-created.json';
        nhecr_expect(nhecu_same(nhecu_public($alias, $proofs), $made), 'NHECR_SOURCE_EACH_STAGE_ORIGINAL_NATIVE_CREATED_ALIAS');
    }
}

'''

READER_SEAMS += (
    ('adopt_real_external_source_after_context', _FIRST_UNBOUND, _ADOPTED_QUALIFY),
    ('fixed_two_real_source_public_aliases',
     b'actual-run-context|preparation-source-binding|manifest',
     b'actual-run-context|preparation-source-binding|manifest|history-source-review|history-source-publication'),
    ('source_adoption_pure_definitions',
     b'/** Pure native-created validator shared by actual bootstrap registration and the new reader qualifier. */',
     _SOURCE_ADOPTION_FUNCTIONS + b'/** Pure native-created validator shared by actual bootstrap registration and the new reader qualifier. */'),
    ('source_adoption_current_context_join',
     b"    $authority = $freeze['nonempty_history']; $current = $freeze['current_reader'];",
     b"    $sourceProofs = []; nhecr_source_handoff($context, $freeze, '/run/nonempty', $sourceProofs);\n"
     b"    $authority = $freeze['nonempty_history']; $current = $freeze['current_reader'];"),
    ('source_adoption_public_input_window',
     b'    $upgrade = nhecu_upgradePublic($context, $freeze, $handoff, $proofs);',
     b'    $upgrade = nhecu_upgradePublic($context, $freeze, $handoff, $proofs);\n    $proofs += $sourceProofs; nhecr_manifest_stages($context, $manifest, $proofs, true);'),
)
