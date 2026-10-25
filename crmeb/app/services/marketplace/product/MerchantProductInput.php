<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use think\exception\HttpException;

/** Finite ordinary physical-product DTO; integers and decimal yuan stay exact. */
final class MerchantProductInput
{
    public const ROOT_MAX = 8388607;
    public const STOCK_MAX = 16777215;
    public const PROTECTED = ['mer_id', 'merchant_id', 'review_status', 'review_reason', 'review_admin_id', 'review_time', 'is_show'];
    public static function invalid(): never { throw new HttpException(422, '商品参数不正确'); }
    public static function body(array $body, array $allowed, array $required = []): array
    {
        foreach (array_keys($body) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) self::invalid();
        foreach ($required as $key) if (!array_key_exists($key, $body)) self::invalid();
        return $body;
    }
    public static function integer(mixed $value, int $min, int $max, bool $string = false): int
    {
        if ($string && is_string($value) && strlen($value) <= 10 && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) $value = (int)$value;
        if (!is_int($value) || $value < $min || $value > $max) self::invalid();
        return $value;
    }
    public static function text(mixed $value, int $max, bool $nonempty = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || str_contains($value, "\0") || mb_strlen($value, 'UTF-8') > $max || ($nonempty && trim($value) === '')) self::invalid();
        return $value;
    }
    public static function money(mixed $value): string
    {
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value) || preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D', $value, $match) !== 1) self::invalid();
        return $match[1] . '.' . str_pad($match[2] ?? '', 2, '0');
    }
    public static function minimum(array $values): string
    {
        if (!$values) self::invalid();
        $cents = array_map(static fn($v): int => (int)str_replace('.', '', self::money($v)), $values);
        $min = min($cents); return intdiv($min, 100) . '.' . str_pad((string)($min % 100), 2, '0', STR_PAD_LEFT);
    }
    /** Source createSpu algorithm with the PHP 8.2 non-null separator contract. */
    public static function spu(): string
    {
        return substr(implode('', array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8)
            . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
    public static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function ids(mixed $values, int $max = self::ROOT_MAX): array
    {
        if (!is_array($values) || !array_is_list($values) || !$values || count($values) > 100) self::invalid();
        $ids = array_map(static fn($v): int => self::integer($v, 1, $max), $values);
        if (count(array_unique($ids)) !== count($ids)) self::invalid();
        sort($ids, SORT_NUMERIC); return $ids;
    }
    public static function listing(array $filter, int $page, int $limit): array
    {
        self::integer($page, 1, 2147483647); self::integer($limit, 1, 100);
        self::body($filter, ['keyword', 'review_status', 'is_del']); $result = [];
        if (isset($filter['keyword'])) $result['keyword'] = self::text($filter['keyword'], 128);
        if (array_key_exists('review_status', $filter)) $result['review_status'] = self::integer($filter['review_status'], 0, 3, true);
        $result['is_del'] = self::integer($filter['is_del'] ?? 0, 0, 1, true); return $result;
    }
    public static function product(array $body): array
    {
        self::body($body, ['store_name','store_info','keyword','unit_name','slider_image','video_link','description','cate_id','spec_type','items','attrs','rule_id','freight','postage','temp_id'], ['store_name','slider_image','cate_id','spec_type','attrs']);
        $root = ['store_name' => self::text($body['store_name'], 128, true), 'store_info' => self::text($body['store_info'] ?? '', 256), 'keyword' => self::text($body['keyword'] ?? '', 256), 'unit_name' => self::text($body['unit_name'] ?? '件', 32, true), 'video_link' => self::text($body['video_link'] ?? '', 500)];
        $slider = $body['slider_image']; if (!is_array($slider) || !array_is_list($slider) || !$slider || count($slider) > 12) self::invalid();
        foreach ($slider as &$value) $value = self::text($value, 256, true); unset($value);
        $root['image'] = $slider[0]; $root['slider_image'] = self::json($slider); if (strlen($root['slider_image']) > 2000) self::invalid();
        $cats = self::ids($body['cate_id'], 2147483647); $root['cate_id'] = implode(',', $cats); if (strlen($root['cate_id']) > 64) self::invalid();
        $root['spec_type'] = self::integer($body['spec_type'], 0, 1);
        $root['freight'] = self::integer($body['freight'] ?? 1, 1, 3); $root['postage'] = self::money($body['postage'] ?? '0.00');
        $root['temp_id'] = self::integer($body['temp_id'] ?? 0, 0, 2147483647);
        if ($root['freight'] !== 3) { $root['temp_id'] = 0; if ($root['freight'] === 1) $root['postage'] = '0.00'; }
        else { if ($root['temp_id'] === 0) self::invalid(); $root['postage'] = '0.00'; }
        $description = self::text($body['description'] ?? '', 60000); if (strlen(htmlspecialchars($description)) > 65535) self::invalid();
        $rule = self::integer($body['rule_id'] ?? 0, 0, 2147483647);
        $items = $body['items'] ?? []; if (!is_array($items) || !array_is_list($items)) self::invalid();
        $attrs = $body['attrs']; if (!is_array($attrs) || !array_is_list($attrs) || !$attrs || count($attrs) > 256) self::invalid();
        return ['root' => $root, 'categories' => $cats, 'description' => $description, 'rule_id' => $rule, 'items' => $items, 'attrs' => $attrs, 'slider' => $slider];
    }
    /** Source-compatible attr/result shape; no DAO reads or caller stock/identity write. */
    public static function definitions(array $input, ?array $preset = null): array
    {
        $items = $preset ?? $input['items'];
        if ($preset !== null && $input['items'] !== []) self::invalid();
        if ($input['root']['spec_type'] === 0) {
            if ($preset !== null || count($input['attrs']) !== 1) self::invalid();
            $items = [['value' => '规格', 'detail' => ['默认']]];
        }
        if (!$items || count($items) > 8) self::invalid(); $dimensions = []; $normalized = []; $media = [];
        foreach ($items as $item) {
            if (!is_array($item)) self::invalid();
            self::body($item, ['value','detail','add_pic','detailValue','attrHidden'], ['value','detail']);
            $name = trim(self::text($item['value'], 32, true)); if (preg_match('/[,=;]/u', $name) || isset($dimensions[$name])) self::invalid();
            $detail = $item['detail']; if (!is_array($detail) || !array_is_list($detail) || !$detail || count($detail) > 100) self::invalid(); $options = []; $entries = [];
            foreach ($detail as $value) {
                $pic = ''; if (is_array($value)) { self::body($value, ['value','pic'], ['value']); $pic = self::text($value['pic'] ?? '', 128); $value = $value['value']; }
                $value = trim(self::text($value, 64, true)); if (preg_match('/[,=;]/u', $value) || isset($options[$value])) self::invalid();
                $options[$value] = true; $entries[] = ['value' => $value, 'pic' => $pic]; if ($pic !== '') $media[] = $pic;
            }
            $dimensions[$name] = $options; $normalized[] = ['value' => $name, 'detail' => $entries];
        }
        $skus = []; $seen = [];
        foreach ($input['attrs'] as $index => $attr) {
            if (!is_array($attr)) self::invalid();
            self::body($attr, ['id','unique','detail','price','cost','ot_price','vip_price','stock','pic','bar_code','bar_code_number','weight','volume','is_default_select'], ['price','pic']);
            $detail = $input['root']['spec_type'] === 0 ? ['规格'=>'默认'] : ($attr['detail'] ?? null);
            if (!is_array($detail) || count($detail) !== count($dimensions) || array_diff(array_keys($detail), array_keys($dimensions))) self::invalid();
            $ordered = []; foreach ($dimensions as $name => $options) { $value = self::text($detail[$name], 64, true); if (!isset($options[$value])) self::invalid(); $ordered[$name] = $value; }
            $suk = implode(',', $ordered); if (mb_strlen($suk, 'UTF-8') > 128 || isset($seen[$suk])) self::invalid(); $seen[$suk] = true;
            $unique = self::text($attr['unique'] ?? '', 8); if ($unique !== '' && preg_match('/\A[a-zA-Z0-9]{8}\z/D', $unique) !== 1) self::invalid();
            $selector = ['id' => isset($attr['id']) ? self::integer($attr['id'], 1, 2147483647) : null, 'unique' => $unique, 'stock' => array_key_exists('stock', $attr) ? self::integer($attr['stock'], 0, 4294967295) : null];
            $row = ['suk' => $suk, 'price' => self::money($attr['price']), 'cost' => self::money($attr['cost'] ?? '0.00'), 'ot_price' => self::money($attr['ot_price'] ?? '0.00'), 'vip_price' => self::money($attr['vip_price'] ?? '0.00'), 'image' => self::text($attr['pic'],128,true), 'bar_code' => self::text($attr['bar_code'] ?? '',50), 'bar_code_number' => self::text($attr['bar_code_number'] ?? '',50), 'weight' => self::money($attr['weight'] ?? '0.00'), 'volume' => self::money($attr['volume'] ?? '0.00'), 'is_show' => 1, 'is_default_select' => self::integer($attr['is_default_select'] ?? 0,0,1)];
            $media[] = $row['image']; $skus[] = ['selector' => $selector, 'row' => $row, 'detail' => $ordered];
        }
        $usedOptions = []; foreach ($skus as $spec) foreach ($spec['detail'] as $name => $value) $usedOptions[$name][$value] = true;
        foreach ($normalized as &$item) $item['detail'] = array_values(array_filter($item['detail'], static fn(array $entry): bool => isset($usedOptions[$item['value']][$entry['value']]))); unset($item);
        if (array_sum(array_column(array_column($skus,'row'), 'is_default_select')) > 1) self::invalid();
        return ['items' => $normalized, 'skus' => $skus, 'media' => array_values(array_unique($media))];
    }
    /** Parse source-compatible decoded HTML without fetching or executing media. */
    public static function htmlMedia(string $html): array
    {
        if ($html === '') return [];
        if (!class_exists(\DOMDocument::class) || stripos($html,'<!DOCTYPE') !== false || stripos($html,'<?xml') !== false) self::invalid();
        $old = libxml_use_internal_errors(true); libxml_clear_errors();
        try { $dom = new \DOMDocument(); $ok = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        if (!$ok) self::invalid(); $media = [];
        $fields = ['img'=>['src'],'video'=>['src','poster'],'audio'=>['src'],'source'=>['src']];
        foreach ($dom->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->nodeName); if (in_array($tag,['script','iframe','object','embed','svg','image','link','style'],true)) self::invalid();
            foreach ($node->attributes as $attribute) if (preg_match('/\Aon[a-z]+\z/i',$attribute->nodeName) || in_array(strtolower($attribute->nodeName),['srcset','srcdoc','data-src','data-original'],true) || ($attribute->nodeName === 'style' && preg_match('/url\s*\(|@import/i',$attribute->nodeValue))) self::invalid();
            foreach ($fields[$tag] ?? [] as $name) if ($node->hasAttribute($name) && $node->getAttribute($name) !== '') $media[] = self::text($node->getAttribute($name),512,true);
            if (count($media)>256) self::invalid();
        }
        return array_values(array_unique($media));
    }
    /** Ordered parsing intent only; no ownership or physical-media qualification. */
    public static function typedHtmlMedia(string $html): array
    {
        if ($html === '') return [];
        if (!class_exists(\DOMDocument::class) || stripos($html,'<!DOCTYPE') !== false || stripos($html,'<?xml') !== false) self::invalid();
        $old = libxml_use_internal_errors(true); libxml_clear_errors();
        try { $dom = new \DOMDocument(); $ok = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        if (!$ok) self::invalid(); $media = [];
        $fields = ['img'=>['src'],'video'=>['src','poster'],'audio'=>['src'],'source'=>['src']];
        foreach ($dom->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->nodeName); if (in_array($tag,['script','iframe','object','embed','svg','image','link','style'],true)) self::invalid();
            foreach ($node->attributes as $attribute) if (preg_match('/\Aon[a-z]+\z/i',$attribute->nodeName) || in_array(strtolower($attribute->nodeName),['srcset','srcdoc','data-src','data-original'],true) || ($attribute->nodeName === 'style' && preg_match('/url\s*\(|@import/i',$attribute->nodeValue))) self::invalid();
            // libxml HTML4 may create element children inside browser text contexts.
            // Keep all original node/attribute syntax checks above, but such children
            // are not typed media intentions and have no raw attribute copy slot.
            $ancestor = $node->parentNode; $textContext = false;
            while ($ancestor instanceof \DOMElement) {
                if (in_array(strtolower($ancestor->nodeName), ['title','textarea','script','style','xmp','iframe','noembed','noframes','plaintext'], true)) { $textContext = true; break; }
                $ancestor = $ancestor->parentNode;
            }
            if ($textContext) continue;
            foreach ($fields[$tag] ?? [] as $name) if ($node->hasAttribute($name) && $node->getAttribute($name) !== '') {
                $path = self::text($node->getAttribute($name),512,true);
                $parent = $node->parentNode;
                $parentTag = $parent instanceof \DOMElement ? strtolower($parent->nodeName) : '';
                $kind = match ($tag) {
                    'img' => 'image',
                    'video' => $name === 'poster' ? 'image' : 'video',
                    'audio' => 'audio',
                    'source' => match ($parentTag) {
                        'video' => 'video',
                        'audio' => 'audio',
                        default => 'unresolved',
                    },
                };
                $media[] = ['kind'=>$kind, 'path'=>$path, 'tag'=>$tag, 'attribute'=>$name, 'parentTag'=>$parentTag, 'index'=>count($media)];
            }
            if (count($media)>256) self::invalid();
        }
        return $media;
    }
}
