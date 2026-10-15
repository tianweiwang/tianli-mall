<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\services\marketplace\publiccatalog\PublicCatalogProjection;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;

/** Pure V1 forms. Callers supply actual captured/locked products; this class does not authenticate or query. */
final class MarketplaceOrderCustomForm
{
    private const GROUP_MAX = 500;
    private const FIELD_MAX = 10;
    private const IMAGE_MAX = 8;
    private const JSON_MAX = 65535;

    /**
     * @param array<int,array> $products Actual raw root rows, keyed by product PK.
     * @param array<array{cart_ref:string,product_id:int}> $selection Actual canonical selection (extra selection fields retained by caller).
     * @return list<array{product_id:int,product_name:string,cart_refs:list<string>,definition_sha256:string,fields:list<array>}>
     */
    public static function definitions(array $products,array $selection,string $sourceKind):array
    {
        $refs = self::productRefs($selection,$sourceKind); $out = [];
        foreach ($refs as $id=>$cartRefs) {
            $product = $products[$id] ?? null;
            if (!is_array($product) || self::id($product['id'] ?? null) !== $id) self::unavailable();
            $name = self::text($product['store_name'] ?? null);
            $raw = $product['custom_form'] ?? null;
            if ($raw === null || $raw === '') $raw = [];
            elseif (is_string($raw)) {
                try { $raw = json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) { self::unavailable(); }
            } else self::unavailable();
            if (!is_array($raw)) self::unavailable();
            $fields = [];
            foreach (PublicCatalogProjection::formConfig($raw) as $index=>$field) {
                $field['title'] = self::text($field['title']);
                if ($field['label'] === 'img') {
                    $field['value'] = $field['value'] === '' ? [] : self::images($field['value']);
                } else $field['value'] = self::text($field['value']);
                $fields[] = ['field_index'=>$index]+$field;
            }
            if (!$fields) continue;
            $out[] = ['product_id'=>$id,'product_name'=>$name,'cart_refs'=>$cartRefs,
                'definition_sha256'=>self::digest($id,$fields),'fields'=>$fields];
        }
        self::jsonBytes($out);
        return $out;
    }

    /**
     * Validate a server-owned frozen/public definition DTO against its actual selection, not client answers.
     * Never use on saved order snapshots: their values are answers while the hash intentionally binds the original definition.
     */
    public static function configuration(array $groups,array $selection,string $sourceKind):array
    {
        if (!array_is_list($groups) || count($groups)>self::GROUP_MAX) self::unavailable();
        $refs = self::productRefs($selection,$sourceKind); $last = 0;
        foreach ($groups as $group) {
            self::keys($group,['product_id','product_name','cart_refs','definition_sha256','fields']);
            $id = self::id($group['product_id']);
            if ($group['product_id']!==$id || $id<=$last || !isset($refs[$id]) || $group['cart_refs'] !== $refs[$id]) self::unavailable();
            $last = $id; self::text($group['product_name']);
            $fields = $group['fields'];
            if (!is_array($fields) || !array_is_list($fields) || !$fields || count($fields)>self::FIELD_MAX) self::unavailable();
            $plain = [];
            foreach ($fields as $index=>$field) {
                self::keys($field,['field_index','title','label','value','status']);
                if ($field['field_index'] !== $index) self::unavailable();
                self::text($field['title']);
                $row = $field; unset($row['field_index']); $plain[] = $row;
                if (($field['label'] ?? null) === 'img') self::images($field['value']);
                else self::text($field['value']);
            }
            PublicCatalogProjection::formConfig($plain);
            if (!is_string($group['definition_sha256']) || !hash_equals(self::digest($id,$fields),$group['definition_sha256'])) self::unavailable();
        }
        self::jsonBytes($groups);
        return $groups;
    }

    /** Canonical create answers: no client title/label/status; matching/required checks happen with actual definitions. */
    public static function answers(mixed $groups):array
    {
        if (!is_array($groups) || !array_is_list($groups) || count($groups)>self::GROUP_MAX) self::invalid();
        $out = [];
        foreach ($groups as $group) {
            self::keys($group,['product_id','definition_sha256','values'],true);
            $id = self::id($group['product_id']);
            $hash = $group['definition_sha256'];
            if (isset($out[$id]) || !is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D',$hash)!==1) self::invalid();
            $values = $group['values'];
            if (!is_array($values) || !array_is_list($values) || !$values || count($values)>self::FIELD_MAX) self::invalid();
            $fields = [];
            foreach ($values as $value) {
                self::keys($value,['field_index','value'],true);
                $index = $value['field_index'];
                if (!is_int($index) || $index<0 || $index>=self::FIELD_MAX || isset($fields[$index])) self::invalid();
                $answer = is_array($value['value']) ? self::images($value['value']) : self::text($value['value']);
                $fields[$index] = ['field_index'=>$index,'value'=>$answer];
            }
            ksort($fields,SORT_NUMERIC);
            $out[$id] = ['product_id'=>$id,'definition_sha256'=>$hash,'values'=>array_values($fields)];
        }
        ksort($out,SORT_NUMERIC); $out = array_values($out); self::encoded($out);
        return $out;
    }

    /**
     * Repricing does not initialize answers again. Caller carries returned answers with the same confirmation.
     * Allows still-empty required answers while editing, but binds every group/index/hash and value type.
     */
    public static function retainAnswers(array $frozen,array $answers,array $current):array
    {
        if ($frozen !== $current) self::changed();
        $answers = self::answers($answers);
        self::match($frozen,$answers,false);
        return $answers;
    }

    /**
     * First-create service MUST call with lockInputs()['products']/selection and the frozen confirmation definitions.
     * Returns a server-labelled grouped snapshot for the existing order custom_form TEXT (not JSON text).
     * Saved-order readers need a version-aware snapshot projection; configuration() is not that projection.
     */
    public static function snapshot(array $frozen,array $answers,array $lockedProducts,array $selection,string $sourceKind):array
    {
        self::configuration($frozen,$selection,$sourceKind);
        if ($frozen !== self::definitions($lockedProducts,$selection,$sourceKind)) self::changed();
        $out = self::match($frozen,self::answers($answers),true); self::encoded($out);
        return $out;
    }

    /** Encode the exact saved snapshot bytes; never trim/truncate answers to fit TEXT. */
    public static function encoded(array $value):string
    {
        $json = self::jsonBytes($value);
        if (strlen($json)>self::JSON_MAX) self::invalid();
        return $json;
    }

    private static function jsonBytes(array $value):string
    {
        try { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR,32); }
        catch (\JsonException $error) { self::invalid(); }
    }

    private static function match(array $definitions,array $answers,bool $required):array
    {
        if (count($definitions)!==count($answers)) self::invalid();
        $out = [];
        foreach ($definitions as $i=>$group) {
            $answer = $answers[$i];
            if ($group['product_id']!==$answer['product_id'] || !hash_equals($group['definition_sha256'],$answer['definition_sha256'])
                || count($group['fields'])!==count($answer['values'])) self::changed();
            foreach ($group['fields'] as $index=>&$field) {
                if ($answer['values'][$index]['field_index']!==$index) self::invalid();
                $value = $answer['values'][$index]['value'];
                if ($field['label']==='img') $value = self::images($value);
                else $value = self::text($value);
                if ($required && $field['status']) self::required($field['label'],$value);
                $field['value'] = $value;
            }
            unset($field); $out[] = $group;
        }
        return $out;
    }

    private static function required(string $label,string|array $value):void
    {
        $ok = match ($label) {
            'img' => count($value)>0,
            'number' => self::positiveNumber($value),
            'email' => preg_match('/\A\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*\z/D',$value)===1,
            'phone' => preg_match('/\A1(3|4|5|7|8|9|6)[0-9]{9}\z/D',$value)===1,
            default => preg_match('/\A[\s\x{FEFF}]*\z/u',$value)!==1,
        };
        if (!$ok) self::invalid();
    }

    private static function positiveNumber(string $value):bool
    {
        // Quantity-like form input is not money: preserve arbitrary decimal precision and original bytes.
        return preg_match('/\A\s*\+?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)\s*\z/uD',$value)===1
            && preg_match('/[1-9]/',$value)===1;
    }

    private static function productRefs(array $selection,string $kind):array
    {
        if (!array_is_list($selection) || !$selection || count($selection)>self::GROUP_MAX) self::unavailable();
        $out = []; $seen = [];
        foreach ($selection as $line) {
            if (!is_array($line)) self::unavailable();
            $id = self::id($line['product_id'] ?? null); $ref = $line['cart_ref'] ?? null;
            if (!is_string($ref) || MarketplaceOrderInput::reference($ref,$kind)!==$ref || isset($seen[$ref])) self::unavailable();
            $out[$id][] = $ref; $seen[$ref] = true;
        }
        foreach ($out as &$refs) $refs = MarketplaceOrderInput::references(implode(',',$refs),$kind);
        unset($refs); ksort($out,SORT_NUMERIC); return $out;
    }

    private static function digest(int $id,array $fields):string
    {
        return hash('sha256',MarketplaceOrderInput::canonical(['product_id'=>$id,'fields'=>$fields]));
    }
    private static function id(mixed $id):int
    {
        return MarketplaceOrderInput::unsigned($id,'表单商品',8388607) ?: self::invalid();
    }
    private static function images(mixed $value):array
    {
        if (!is_array($value) || !array_is_list($value) || count($value)>self::IMAGE_MAX) self::invalid();
        foreach ($value as $image) self::text($image); return $value;
    }
    private static function text(mixed $value):string
    {
        if (!is_string($value) || preg_match('//u',$value)!==1 || str_contains($value,"\0")) self::invalid();
        return $value;
    }
    private static function keys(mixed $row,array $keys,bool $client=false):void
    {
        if (!is_array($row) || count($row)!==count($keys) || array_diff(array_keys($row),$keys)) {
            if ($client) self::invalid(); else self::unavailable();
        }
    }
    private static function invalid():never { throw new ApiException('商品表单内容不完整或格式错误'); }
    private static function changed():never { throw new ApiException('商品表单已变化，请重新确认'); }
    private static function unavailable():never { throw new AuthUnavailableException(); }
}
