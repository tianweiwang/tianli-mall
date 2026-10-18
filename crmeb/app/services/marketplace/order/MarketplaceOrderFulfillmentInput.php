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

    /** Public merchant item IDs name the already published original root items, never a client plan. */
    public static function shipPublic(mixed $rootId,array $raw):array
    {
        self::keys($raw,['idem_key','items','delivery']);
        if(!is_array($raw['items'])||!array_is_list($raw['items'])||$raw['items']===[])throw new ApiException('items格式错误');
        $quantities=[];
        foreach($raw['items'] as$item){
            if(!is_array($item))throw new ApiException('items格式错误');self::keys($item,['item_id','quantity']);
            $quantities[]=['source_item_id'=>self::positive($item['item_id'],'item_id'),'quantity'=>self::positive($item['quantity'],'quantity')];
        }
        $normal=self::ship($rootId,['idem_key'=>$raw['idem_key'],'quantities'=>$quantities,'delivery'=>$raw['delivery']]);
        return ['idem_key'=>$normal['idem_key'],'quantities'=>$normal['request']['quantities'],'delivery'=>$normal['request']['delivery']];
    }

    /** HTTP-only child-local syntax. No fabricated root/child IDs and no private source coordinates. */
    public static function receivePublic(array $raw):array
    {
        $keys=['idem_key'];if(array_key_exists('selection',$raw))$keys[]='selection';self::keys($raw,$keys);
        $key=self::idempotencyKey($raw['idem_key']);$selection=array_key_exists('selection',$raw)?$raw['selection']:['mode'=>'all_eligible'];
        if(!is_array($selection))throw new ApiException('selection格式错误');
        if(($selection['mode']??null)==='all_eligible')$selection=self::selection($selection);
        else{
            self::keys($selection,['mode','items']);
            if($selection['mode']!=='items'||!is_array($selection['items'])||!array_is_list($selection['items'])||$selection['items']===[])
                throw new ApiException('selection格式错误');
            $items=[];$seen=[];
            foreach($selection['items'] as$item){
                if(!is_array($item))throw new ApiException('items格式错误');self::keys($item,['item_id','segments']);
                $id=self::positive($item['item_id'],'item_id');
                if(isset($seen[$id])||!is_array($item['segments'])||!array_is_list($item['segments'])||$item['segments']===[])
                    throw new ApiException('包裹商品选择格式错误');
                $seen[$id]=true;$segments=[];
                foreach($item['segments'] as$segment){
                    if(!is_array($segment))throw new ApiException('segments格式错误');self::keys($segment,['first','last']);
                    $first=self::positive($segment['first'],'first');$last=self::positive($segment['last'],'last');
                    if($last<$first)throw new ApiException('包裹单位范围格式错误');$segments[]=['first'=>$first,'last'=>$last];
                }
                usort($segments,static fn(array $a,array $b):int=>($a['first']<=>$b['first'])?:($a['last']<=>$b['last']));
                $merged=[];
                foreach($segments as$segment){
                    $index=count($merged)-1;
                    if($index>=0&&$segment['first']<=$merged[$index]['last'])throw new ApiException('包裹单位范围重复或交叠');
                    if($index>=0&&$segment['first']===$merged[$index]['last']+1)$merged[$index]['last']=$segment['last'];
                    else $merged[]=$segment;
                }
                $items[]=['item_id'=>$id,'segments'=>$merged];
            }
            usort($items,static fn(array $a,array $b):int=>$a['item_id']<=>$b['item_id']);$selection=['mode'=>'items','items'=>$items];
        }
        $out=['idem_key'=>$key,'selection'=>$selection];
        if(strlen(MarketplaceOrderInput::canonical($out))>self::MAX_REQUEST_BYTES)throw new ApiException('履约请求内容超出限制');
        return $out;
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
