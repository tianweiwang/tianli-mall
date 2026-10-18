<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\ApiException;
use JsonException;
use stdClass;

/** Pure wire normalization. No actor, owner, price source, clock, eligibility or persistence is inferred. */
final class MarketplaceOrderRefundInput
{
    private const MAX_ID = 2147483647;
    private const MAX_BYTES = 65536;
    private const BUSINESS_STATES = ['REQUESTED', 'APPROVED', 'WITHDRAWN', 'REJECTED', 'COMPLETED'];

    /** HTTP callers use the original body here first; nested JSON objects must not become list-shaped PHP arrays. */
    public static function decode(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || preg_match('//u', $bytes) !== 1) self::failure('请求内容');
        try { $value = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { self::failure('JSON请求'); }
        if (!$value instanceof stdClass) self::failure('JSON对象');
        return get_object_vars($value);
    }

    public static function apply(array $raw): array
    {
        self::keys($raw, ['schema', 'idem_key', 'operation_kind', 'amount', 'reason', 'images', 'selection']);
        self::schema($raw); $key = self::key($raw['idem_key']);
        $kind = $raw['operation_kind'];
        if (!in_array($kind, ['money_only', 'cancel_unshipped', 'return_goods'], true)) self::failure('operation_kind');
        $selection = $kind === 'return_goods' ? self::childSelection($raw['selection']) : self::rootSelection($raw['selection']);
        return self::bounded(['schema' => 2, 'idem_key' => $key, 'operation_kind' => $kind,
            'amount' => self::money($raw['amount']), 'reason' => self::text($raw['reason'], 'reason', 255),
            'images' => self::images($raw['images']), 'selection' => $selection]);
    }

    public static function withdraw(array $raw): array
    {
        self::keys($raw, ['schema', 'idem_key']); self::schema($raw);
        return self::bounded(['schema' => 2, 'idem_key' => self::key($raw['idem_key'])]);
    }

    public static function approve(array $raw): array
    {
        self::keys($raw, ['schema', 'idem_key']); self::schema($raw);
        return self::bounded(['schema' => 2, 'idem_key' => self::key($raw['idem_key'])]);
    }

    public static function reject(array $raw): array
    {
        self::keys($raw, ['schema', 'idem_key', 'reason']); self::schema($raw);
        return self::bounded(['schema' => 2, 'idem_key' => self::key($raw['idem_key']),
            'reason' => self::text($raw['reason'], 'reason', 255)]);
    }

    public static function express(array $raw): array
    {
        self::keys($raw, ['schema', 'idem_key', 'refund_no', 'return_delivery']); self::schema($raw);
        $delivery = self::object($raw['return_delivery'], ['carrier_name', 'tracking_no']);
        return self::bounded(['schema' => 2, 'idem_key' => self::key($raw['idem_key']),
            'refund_no' => self::text($raw['refund_no'], 'refund_no', 50),
            'return_delivery' => ['carrier_name' => self::text($delivery['carrier_name'], 'carrier_name', 255),
                'tracking_no' => self::text($delivery['tracking_no'], 'tracking_no', 100)]]);
    }

    public static function acceptReturn(array $raw): array
    {
        self::keys($raw, ['schema', 'idem_key', 'items']); self::schema($raw);
        return self::bounded(['schema' => 2, 'idem_key' => self::key($raw['idem_key']),
            'items' => self::childItems($raw['items'])]);
    }

    public static function id(mixed $value): int { return self::positive($value, 'id'); }

    public static function quote(array $query): array
    {
        self::optionalKeys($query, ['contract', 'fulfillment_page', 'fulfillment_limit']);
        self::contract($query);
        return ['contract' => 2, 'fulfillment_page' => self::page($query['fulfillment_page'] ?? 1, 'fulfillment_page'),
            'fulfillment_limit' => self::limit($query['fulfillment_limit'] ?? 20, 'fulfillment_limit')];
    }

    public static function customerPage(array $query): array
    {
        self::optionalKeys($query, ['contract', 'page', 'limit']); self::contract($query);
        return ['contract' => 2, 'page' => self::page($query['page'] ?? 1, 'page'),
            'limit' => self::limit($query['limit'] ?? 20, 'limit')];
    }

    public static function merchantPage(array $query): array
    {
        self::optionalKeys($query, ['page', 'limit', 'business_state']);
        return ['page' => self::page($query['page'] ?? 1, 'page'), 'limit' => self::limit($query['limit'] ?? 20, 'limit'),
            'business_state' => self::businessState($query)];
    }

    public static function platformPage(array $query): array
    {
        self::optionalKeys($query, ['page', 'limit', 'business_state', 'mer_id']);
        $merchant = null;
        if (array_key_exists('mer_id', $query)) {
            $merchant = MarketplaceOrderInput::unsigned($query['mer_id'], 'mer_id');
            if ($merchant === 0) self::failure('mer_id');
        }
        return ['page' => self::page($query['page'] ?? 1, 'page'), 'limit' => self::limit($query['limit'] ?? 20, 'limit'),
            'business_state' => self::businessState($query), 'mer_id' => $merchant];
    }

    private static function rootSelection(mixed $value): array
    {
        $value = self::object($value, ['mode', 'items']);
        if ($value['mode'] !== 'root_quantities' || !is_array($value['items']) || !array_is_list($value['items'])
            || $value['items'] === []) self::failure('selection');
        $items = []; $seen = [];
        foreach ($value['items'] as $item) {
            $item = self::object($item, ['item_id', 'quantity']);
            $id = self::positive($item['item_id'], 'item_id');
            if (isset($seen[$id])) self::failure('重复商品'); $seen[$id] = true;
            $items[] = ['item_id' => $id, 'quantity' => self::positive($item['quantity'], 'quantity')];
        }
        usort($items, static fn(array $left, array $right): int => $left['item_id'] <=> $right['item_id']);
        return ['mode' => 'root_quantities', 'items' => $items];
    }

    private static function childSelection(mixed $value): array
    {
        $value = self::object($value, ['mode', 'child_order_no', 'items']);
        if ($value['mode'] !== 'child_items') self::failure('selection');
        return ['mode' => 'child_items', 'child_order_no' => self::text($value['child_order_no'], 'child_order_no', 32),
            'items' => self::childItems($value['items'])];
    }

    private static function childItems(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) self::failure('items');
        $items = []; $seen = [];
        foreach ($value as $item) {
            $item = self::object($item, ['item_id', 'segments']);
            $id = self::positive($item['item_id'], 'item_id');
            if (isset($seen[$id]) || !is_array($item['segments']) || !array_is_list($item['segments'])
                || $item['segments'] === []) self::failure('商品范围');
            $seen[$id] = true; $segments = [];
            foreach ($item['segments'] as $segment) {
                $segment = self::object($segment, ['first', 'last']);
                $first = self::positive($segment['first'], 'first'); $last = self::positive($segment['last'], 'last');
                if ($last < $first) self::failure('单位范围');
                $segments[] = ['first' => $first, 'last' => $last];
            }
            usort($segments, static fn(array $left, array $right): int => ($left['first'] <=> $right['first']) ?: ($left['last'] <=> $right['last']));
            $normal = [];
            foreach ($segments as $segment) {
                $lastIndex = count($normal) - 1;
                if ($lastIndex >= 0 && $segment['first'] <= $normal[$lastIndex]['last']) self::failure('重复或交叠单位范围');
                $normal[] = $segment;
            }
            $items[] = ['item_id' => $id, 'segments' => $normal];
        }
        usort($items, static fn(array $left, array $right): int => $left['item_id'] <=> $right['item_id']);
        return $items;
    }

    private static function images(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 9) self::failure('images');
        $seen = [];
        foreach ($value as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) !== 1
                || strlen($id) > 20 || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
                || isset($seen['id:' . $id])) self::failure('凭证编号');
            $seen['id:' . $id] = true;
        }
        return $value;
    }

    private static function money(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $value) !== 1
            || MarketplaceDecimal::compare($value, '0.00') <= 0 || MarketplaceDecimal::compare($value, '9999999999.99') > 0)
            self::failure('amount');
        return $value;
    }

    private static function key(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[\x20-\x7e]{1,128}\z/D', $value) !== 1
            || $value[0] === ' ' || substr($value, -1) === ' ') self::failure('idem_key');
        return $value;
    }
    private static function positive(mixed $value, string $name): int
    {
        $id = MarketplaceOrderInput::unsigned($value, $name, self::MAX_ID);
        if ($id === 0) self::failure($name); return $id;
    }
    private static function page(mixed $value, string $name): int { return self::positive($value, $name); }
    private static function limit(mixed $value, string $name): int
    {
        $limit = MarketplaceOrderInput::unsigned($value, $name, 100);
        if ($limit === 0) self::failure($name); return $limit;
    }
    private static function text(mixed $value, string $name, int $maximum): string
    {
        if (!is_string($value) || $value === '' || preg_match('//u', $value) !== 1
            || str_contains($value, "\0") || mb_strlen($value, 'UTF-8') > $maximum) self::failure($name);
        return $value;
    }
    private static function object(mixed $value, array $expected): array
    {
        if ($value instanceof stdClass) $value = get_object_vars($value);
        if (!is_array($value)) self::failure('对象'); self::keys($value, $expected); return $value;
    }
    private static function schema(array $value): void { if ($value['schema'] !== 2) self::failure('schema'); }
    private static function contract(array $query): void
    {
        if (!array_key_exists('contract', $query) || MarketplaceOrderInput::unsigned($query['contract'], 'contract', 2) !== 2) self::failure('contract');
    }
    private static function businessState(array $query): ?string
    {
        if (!array_key_exists('business_state', $query)) return null;
        if (!in_array($query['business_state'], self::BUSINESS_STATES, true)) self::failure('business_state');
        return $query['business_state'];
    }
    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value); sort($keys, SORT_STRING); sort($expected, SORT_STRING);
        if ($keys !== $expected) self::failure('字段闭集');
    }
    private static function optionalKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) self::failure('筛选字段');
    }
    private static function bounded(array $value): array
    {
        if (strlen(MarketplaceOrderInput::canonical($value)) > self::MAX_BYTES) self::failure('请求长度'); return $value;
    }
    private static function failure(string $field): never { throw new ApiException('售后' . $field . '格式错误'); }
}
