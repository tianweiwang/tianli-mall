<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\ApiException;

/** Pure request normalization; saved facts, eligibility and authorization belong to the writer. */
final class MarketplaceOrderFulfillmentInput
{
    private const MAX_ID = 2147483647;
    private const MAX_REQUEST_BYTES = 65536;

    public static function ship(mixed $rootId, array $raw): array
    {
        self::keys($raw, ['idem_key', 'quantities', 'delivery']);
        $idem = self::idempotencyKey($raw['idem_key']);
        $request = [
            'schema' => 1,
            'action' => 'ship',
            'root_order_id' => self::positive($rootId, 'root_order_id'),
            'quantities' => self::quantities($raw['quantities']),
            'delivery' => self::delivery($raw['delivery']),
        ];
        return self::result($idem, $request);
    }

    public static function receive(mixed $rootId, mixed $childId, array $raw): array
    {
        $fields = ['idem_key'];
        $hasSelection = array_key_exists('selection', $raw);
        if ($hasSelection) $fields[] = 'selection';
        self::keys($raw, $fields);
        $idem = self::idempotencyKey($raw['idem_key']);
        $request = [
            'schema' => 1,
            'action' => 'receive',
            'root_order_id' => self::positive($rootId, 'root_order_id'),
            'child_order_id' => self::positive($childId, 'child_order_id'),
            'selection' => $hasSelection ? self::selection($raw['selection']) : ['mode' => 'all_eligible'],
        ];
        return self::result($idem, $request);
    }

    private static function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) throw new ApiException('履约请求字段不完整或未登记');
    }

    private static function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $value) !== 1) {
            throw new ApiException('idem_key格式错误');
        }
        return $value;
    }

    private static function positive(mixed $value, string $name): int
    {
        $value = MarketplaceOrderInput::unsigned($value, $name, self::MAX_ID);
        if ($value === 0) throw new ApiException($name . '格式错误');
        return $value;
    }

    private static function quantities(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new ApiException('quantities格式错误');
        }
        $out = [];
        $seen = [];
        foreach ($value as $row) {
            if (!is_array($row)) throw new ApiException('quantities格式错误');
            self::keys($row, ['source_item_id', 'quantity']);
            $itemId = self::positive($row['source_item_id'], 'source_item_id');
            if (isset($seen[$itemId])) throw new ApiException('履约商品重复');
            $seen[$itemId] = true;
            $out[] = ['source_item_id' => $itemId, 'quantity' => self::positive($row['quantity'], 'quantity')];
        }
        usort($out, static fn(array $a, array $b): int => $a['source_item_id'] <=> $b['source_item_id']);
        return $out;
    }

    private static function delivery(mixed $value): array
    {
        if (!is_array($value)) throw new ApiException('delivery格式错误');
        self::keys($value, ['type', 'carrier_code', 'carrier_name', 'tracking_no']);
        if ($value['type'] !== 'express') throw new ApiException('配送类型格式错误');
        return [
            'type' => 'express',
            'carrier_code' => self::byteText($value['carrier_code'], 'carrier_code', 50),
            'carrier_name' => self::byteText($value['carrier_name'], 'carrier_name', 64),
            'tracking_no' => self::byteText($value['tracking_no'], 'tracking_no', 64),
        ];
    }

    private static function byteText(mixed $value, string $name, int $maxBytes): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxBytes
            || preg_match('//u', $value) !== 1 || str_contains($value, "\0")
            || preg_match('/(*UCP)(?:\A\s|\s\z)/u', $value) !== 0) {
            throw new ApiException($name . '格式错误');
        }
        return $value;
    }

    private static function selection(mixed $value): array
    {
        if (!is_array($value)) throw new ApiException('selection格式错误');
        if (($value['mode'] ?? null) === 'all_eligible') {
            self::keys($value, ['mode']);
            return ['mode' => 'all_eligible'];
        }
        self::keys($value, ['mode', 'ranges']);
        if ($value['mode'] !== 'ranges' || !is_array($value['ranges'])
            || !array_is_list($value['ranges']) || $value['ranges'] === []) {
            throw new ApiException('selection格式错误');
        }
        $ranges = [];
        foreach ($value['ranges'] as $row) {
            if (!is_array($row)) throw new ApiException('ranges格式错误');
            self::keys($row, ['source_item_id', 'unit_start', 'unit_end']);
            $start = MarketplaceOrderInput::unsigned($row['unit_start'], 'unit_start', self::MAX_ID);
            $end = MarketplaceOrderInput::unsigned($row['unit_end'], 'unit_end', self::MAX_ID);
            if ($end <= $start) throw new ApiException('履约范围格式错误');
            $ranges[] = [
                'source_item_id' => self::positive($row['source_item_id'], 'source_item_id'),
                'unit_start' => $start,
                'unit_end' => $end,
            ];
        }
        usort($ranges, static fn(array $a, array $b): int =>
            ($a['source_item_id'] <=> $b['source_item_id'])
            ?: ($a['unit_start'] <=> $b['unit_start'])
            ?: ($a['unit_end'] <=> $b['unit_end']));
        $out = [];
        foreach ($ranges as $range) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['source_item_id'] === $range['source_item_id']) {
                if ($range['unit_start'] < $out[$last]['unit_end']) {
                    throw new ApiException('履约范围重复或交叠');
                }
                if ($range['unit_start'] === $out[$last]['unit_end']) {
                    $out[$last]['unit_end'] = $range['unit_end'];
                    continue;
                }
            }
            $out[] = $range;
        }
        return ['mode' => 'ranges', 'ranges' => $out];
    }

    private static function result(string $idem, array $request): array
    {
        $canonical = MarketplaceOrderInput::canonical($request);
        if (strlen($canonical) > self::MAX_REQUEST_BYTES) throw new ApiException('履约请求内容超出限制');
        return ['idem_key' => $idem, 'request' => $request];
    }
}
