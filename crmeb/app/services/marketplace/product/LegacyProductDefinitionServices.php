<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use crmeb\exceptions\AdminException;
use crmeb\exceptions\AuthUnavailableException;
use think\db\Query;
use think\facade\Db;
use app\services\marketplace\PlatformAccessServices;

/** Preserve real SKU identities and quantities while saving the original legacy definition shape. */
final class LegacyProductDefinitionServices
{
    private const MUTABLE = ['suk','price','cost','ot_price','vip_price','weight','volume','brokerage','brokerage_two',
        'image','bar_code','bar_code_number','quota','quota_show','is_virtual','coupon_id','disk_info','is_show','is_default_select'];
    private const MONEY = ['price','cost','ot_price','vip_price','weight','volume','brokerage','brokerage_two'];

    public static function save(array $data, int $id, int $type = 0, $isVip = 0, $virtualType = 0): bool
    {
        if (!LegacyProductWriteServices::active() || $type !== 0 || !LegacyProductWriteServices::preserveSkus($id,$type)) {
            throw new AuthUnavailableException();
        }
        $parent = LegacyProductWriteServices::assertParent($id);
        $owner = LegacyProductWriteServices::owner($id);
        $virtualType = self::integer($virtualType,0,3);
        $isVip = self::flag($isVip);
        if ((int)$parent['virtual_type'] !== $virtualType) throw new AdminException('商品类型不能切换');
        foreach (['result','attrGroup','valueGroup'] as $key) if (!isset($data[$key]) || !is_array($data[$key])) self::invalid();
        $result = $data['result'];
        if (!isset($result['attr'],$result['value']) || !is_array($result['attr']) || !array_is_list($result['attr'])
            || !is_array($result['value']) || !array_is_list($result['value']) || !$result['value'] || !$data['attrGroup']) self::invalid();
        $old = self::query('store_product_attr_value',$id)->where('type',0)->order('id')->lock(true)->select()->toArray();
        $oldAttrs = self::query('store_product_attr',$id)->where('type',0)->order('id')->lock(true)->select()->toArray();
        $oldResults = self::query('store_product_attr_result',$id)->where('type',0)->order('id')->lock(true)->select()->toArray();
        $cards = self::query('store_product_virtual',$id)->order('id')->lock(true)->select()->toArray();
        if (count($oldResults) > 1) throw new AuthUnavailableException();
        $oldResult = null;
        if ($oldResults && is_string($oldResults[0]['result'])) {
            try { $decoded = json_decode($oldResults[0]['result'],true,512,JSON_THROW_ON_ERROR);if (is_array($decoded)) $oldResult = $decoded; }
            catch (\JsonException $ignored) {}
        }
        $desiredAttrs = self::attributes($data['attrGroup'],$id);
        $attributeNames = array_column($desiredAttrs,'attr_name');
        $specs = []; $seenSuks = []; $used = []; $uniqueValues = []; $uniqueCounts = [];
        foreach ($old as $row) {
            self::integer($row['id'],1,2147483647);
            if ((string)$row['product_id'] !== (string)$id || (string)$row['type'] !== '0') throw new AuthUnavailableException();
            $uniqueValues[(string)$row['unique']] = true;
            $uniqueCounts[(string)$row['unique']] = ($uniqueCounts[(string)$row['unique']] ?? 0) + 1;
        }
        foreach ($result['value'] as $index=>$value) {
            if (!is_array($value) || !isset($value['detail']) || !is_array($value['detail'])) self::invalid();
            $parts = []; $detail = [];
            foreach ($attributeNames as $name) {
                if (!array_key_exists($name,$value['detail'])) self::invalid();
                $part = self::text($value['detail'][$name],128); $parts[] = $part; $detail[$name] = $part;
            }
            $suk = self::text(implode(',',$parts),128);
            if (isset($seenSuks[$suk]) || !isset($data['valueGroup'][$suk]) || !is_array($data['valueGroup'][$suk])) self::invalid();
            $seenSuks[$suk] = true;
            $group = $data['valueGroup'][$suk];
            if ((string)($group['product_id'] ?? '') !== (string)$id || (string)($group['type'] ?? '') !== '0'
                || ($group['suk'] ?? null) !== $suk) self::invalid();
            $match = self::select($value,$group,$suk,$old);
            if ($match !== null && isset($used[(int)$match['id']])) self::invalid();
            $desiredStock = array_key_exists('stock',$group) ? self::integer($group['stock'],0,4294967295) : null;
            $patch = self::patch($group,$suk);
            if ($patch['is_virtual'] !== (in_array($virtualType,[1,2],true) ? 1 : 0)) self::invalid();
            if ($virtualType === 2 && $patch['coupon_id'] === 0) throw new AdminException('虚拟优惠券商品请选择优惠券');
            if ($isVip === 1 && $patch['vip_price'] === '0.00') throw new AdminException('会员价格不能为0');
            if ($match !== null) $used[(int)$match['id']] = true;
            $specs[] = ['index'=>$index,'input'=>$value,'detail'=>$detail,'patch'=>$patch,'old'=>$match,
                'desired_stock'=>$desiredStock,'cards_supplied'=>array_key_exists('virtual_list',$value), 'virtual_list'=>$group['virtual_list'] ?? []];
        }
        if (count($seenSuks) !== count($data['valueGroup'])) self::invalid();

        $expected = []; $actualActive = []; $virtualChanged = false; $intents = [];
        foreach ($specs as $spec) {
            $patch = $spec['patch']; $before = $spec['old'];
            if ($before !== null) {
                $skuId = (int)$before['id'];
                $effective = [];
                foreach ($patch as $key=>$value) if (!self::equal($before[$key],$value)
                    && !($key === 'disk_info' && $before[$key] === null && $value === '')) $effective[$key] = $value;
                if ($effective) {
                    $query = self::query('store_product_attr_value',$id)->where('type',0)->where('id',$skuId);
                    $affected = $query->update($effective);
                    if (!is_int($affected) || $affected < 0 || $affected > 1) throw new AuthUnavailableException();
                }
                $wanted = array_replace($before,$effective);
            } else {
                $unique = '';
                for ($attempt = 0; $attempt < 16; ++$attempt) {
                    $candidate = bin2hex(random_bytes(4));
                    if (!isset($uniqueValues[$candidate])) { $unique = $candidate;break; }
                }
                if ($unique === '') throw new AuthUnavailableException();
                $uniqueValues[$unique] = true;
                $skuId = self::integer(self::query('store_product_attr_value',$id)->insertGetId($patch
                    + ['product_id'=>$id,'type'=>0,'unique'=>$unique,'stock'=>0,'sales'=>0]),1,2147483647);
                $rows = self::query('store_product_attr_value',$id)->where('type',0)->where('id',$skuId)->lock(true)->select()->toArray();
                if (count($rows) !== 1) throw new AuthUnavailableException();
                $wanted = $rows[0];
                foreach ($patch + ['product_id'=>$id,'type'=>0,'unique'=>$unique,'stock'=>0,'sales'=>0] as $key=>$value) {
                    if (!array_key_exists($key,$wanted) || !self::equal($wanted[$key],$value)) throw new AuthUnavailableException();
                }
                $used[$skuId] = true;
            }
            $expected[$skuId] = $wanted;
            $cardSku = $virtualType === 1 && $patch['is_virtual'] === 1 && $patch['coupon_id'] === 0 && $patch['disk_info'] === '';
            if ($cardSku && ($uniqueCounts[(string)$wanted['unique']] ?? 1) !== 1) throw new AuthUnavailableException();
            $currentStock = self::integer($wanted['stock'],0,4294967295); $desiredStock = $spec['desired_stock'];
            if ($cardSku && $spec['cards_supplied']) {
                $plan = PlatformProductStockServices::cardPlan($id,(string)$wanted['unique'],$spec['virtual_list']);
                if (count($plan['unsold']) !== $currentStock && !$plan['conversion']) throw new AuthUnavailableException();
                $plannedAfter = count($plan['desired']);
                if ($desiredStock !== null && $desiredStock !== $currentStock && $desiredStock !== $plannedAfter) self::invalid();
                if ($plannedAfter !== $currentStock || $plan['conversion']) {
                    $intents[] = ['attr_value_id'=>$skuId,'sku_unique'=>(string)$wanted['unique'],'kind'=>'cards','virtual_list'=>$spec['virtual_list']];
                } else {
                    $issuedIds = array_map(static fn(array $row):int => (int)$row['id'],$plan['issued']);
                    $contentCards = array_map(static fn(array $pair):array => ['key'=>$pair['card_no'],'value'=>$pair['card_pwd']],$plan['desired']);
                    $virtualChanged = self::cards($id,(string)$wanted['unique'],$contentCards,$plan['owned'],$issuedIds) || $virtualChanged;
                    PlatformProductStockServices::verifyCardPlan($plan);
                }
            } elseif ($cardSku) {
                if ($desiredStock !== null && $desiredStock !== $currentStock) self::invalid();
            } elseif ($desiredStock !== null && $desiredStock !== $currentStock) {
                $intents[] = ['attr_value_id'=>$skuId,'sku_unique'=>(string)$wanted['unique'],'kind'=>'delta','delta'=>$desiredStock-$currentStock];
            }
            $entry = $spec['input'];
            foreach (self::MUTABLE as $key) if ($key !== 'image') $entry[$key] = $wanted[$key];
            $entry['id'] = $skuId; $entry['unique'] = (string)$wanted['unique'];
            $entry['product_id'] = $id; $entry['type'] = 0; $entry['stock'] = (int)$wanted['stock']; $entry['sales'] = (int)$wanted['sales'];
            $entry['pic'] = $wanted['image']; $entry['detail'] = $spec['detail']; $entry['attr_arr'] = array_values($spec['detail']);
            if ($cardSku) {
                $currentCards = self::query('store_product_virtual',$id)->where('attr_unique',(string)$wanted['unique'])->order('id')->lock(true)->select()->toArray();
                $entry['virtual_list'] = array_map(static fn(array $row): array => ['key'=>$row['card_no'],'value'=>$row['card_pwd']],$currentCards);
            }
            unset($entry['image']);
            $result['value'][$spec['index']] = $entry;
            $actualActive[] = $wanted;
        }
        foreach ($old as $row) if (!isset($used[(int)$row['id']])) {
            $skuId = (int)$row['id']; $patch = [];
            foreach (['is_show','is_default_select'] as $key) if ((string)$row[$key] !== '0') $patch[$key] = 0;
            if ($patch) {
                $affected = self::query('store_product_attr_value',$id)->where('type',0)->where('id',$skuId)->update($patch);
                if (!is_int($affected) || $affected < 0 || $affected > 1) throw new AuthUnavailableException();
            }
            $expected[$skuId] = array_replace($row,$patch);
        }
        $after = self::query('store_product_attr_value',$id)->where('type',0)->order('id')->lock(true)->select()->toArray();
        if (count($after) !== count($expected)) throw new AuthUnavailableException();
        foreach ($after as $row) {
            $skuId = (int)$row['id'];
            if (!isset($expected[$skuId]) || !self::sameRow($row,$expected[$skuId])) throw new AuthUnavailableException();
        }
        foreach ($old as $row) {
            $actual = $expected[(int)$row['id']] ?? null;
            if ($actual === null) throw new AuthUnavailableException();
            foreach (['id','unique','product_id','type','stock','sales'] as $key) if (!self::equal($row[$key],$actual[$key])) throw new AuthUnavailableException();
        }
        $attrsSame = self::attributesSame($oldAttrs,$desiredAttrs);
        if (!$attrsSame) {
            if (self::query('store_product_attr',$id)->where('type',0)->delete() !== count($oldAttrs)) throw new AuthUnavailableException();
            foreach ($desiredAttrs as $attr) if (self::query('store_product_attr',$id)->insert($attr) !== 1) throw new AuthUnavailableException();
        }
        $actualAttrs = self::query('store_product_attr',$id)->where('type',0)->order('id')->lock(true)->select()->toArray();
        if (!self::attributesSame($actualAttrs,$desiredAttrs)) throw new AuthUnavailableException();
        $beforeSemantic = $oldResult === null ? null : self::semantic($oldResult,$old);
        $afterSemantic = self::semantic($result,$after);
        if ($afterSemantic === null) throw new AuthUnavailableException();
        $semanticSame = $beforeSemantic !== null && $beforeSemantic === $afterSemantic;
        if (!$semanticSame || !$attrsSame || $virtualChanged || !$oldResults) self::result($id,$result,$oldResults);
        $currentResults = self::query('store_product_attr_result',$id)->where('type',0)->order('id')->lock(true)->select()->toArray();
        if (count($currentResults) !== 1) throw new AuthUnavailableException();
        if ($semanticSame && $attrsSame && !$virtualChanged && $oldResults) {
            if (!self::sameRow($currentResults[0],$oldResults[0])) throw new AuthUnavailableException();
        } else {
            $current = json_decode($currentResults[0]['result'],true,512,JSON_THROW_ON_ERROR);
            if ($current !== $result) throw new AuthUnavailableException();
        }
        $stock = '0';
        foreach ($after as $row) $stock = bcadd($stock,(string)self::integer($row['stock'],0,4294967295),0);
        if (bccomp($stock,'16777215',0) > 0) throw new AdminException('商品总库存超出实际字段范围');
        $vips = []; $defaultSku = '';
        foreach ($actualActive as $row) {
            $vip = LegacyProductWriteServices::money($row['vip_price']);if ($vip !== '0.00') $vips[] = $vip;
            if ((string)$row['is_default_select'] === '1') $defaultSku = (string)$row['suk'];
        }
        $vip = $vips ? LegacyProductWriteServices::minimum($vips) : '0.00';
        $parent = LegacyProductWriteServices::assertParent($id); $rootPatch = [];
        if (!self::equal($parent['stock'],$stock)) $rootPatch['stock'] = (int)$stock;
        if (!self::equal($parent['vip_price'],$vip)) $rootPatch['vip_price'] = $vip;
        if (!self::equal($parent['default_sku'],$defaultSku)) $rootPatch['default_sku'] = $defaultSku;
        if ($rootPatch) {
            $query = Db::name('store_product')->where('id',$id)->where('mer_id',$owner);
            LegacyProductWriteServices::query($query);
            $affected = $query->update($rootPatch);
            if (!is_int($affected) || $affected < 0 || $affected > 1) throw new AuthUnavailableException();
        }
        $root = LegacyProductWriteServices::assertParent($id);
        if (!self::equal($root['stock'],$stock) || !self::equal($root['vip_price'],$vip)
            || !self::equal($root['default_sku'],$defaultSku)) throw new AuthUnavailableException();
        if ($intents) {
            $scope = LegacyProductWriteServices::rawScope(); app()->make(PlatformAccessServices::class)->additionalStockGrant($scope);
            LegacyProductWriteServices::afterContent($id,$intents);
        }
        return true;
    }

    private static function select(array $value, array $group, string $suk, array $old): ?array
    {
        $id = isset($value['id']) && $value['id'] !== 0 && $value['id'] !== '0' ? self::integer($value['id'],1,2147483647) : null;
        $unique = $value['unique'] ?? ($group['unique'] ?? '');
        if (!is_string($unique) || ($unique !== '' && preg_match('/\A[A-Za-z0-9]{8}\z/D',$unique) !== 1)) self::invalid();
        $matches = [];
        foreach ($old as $row) if ($id !== null ? (int)$row['id'] === $id : ($unique !== '' ? $row['unique'] === $unique : $row['suk'] === $suk)) $matches[] = $row;
        if (count($matches) > 1) throw new AuthUnavailableException();
        $row = $matches[0] ?? null;
        if ($row === null && ($id !== null || $unique !== '')) self::invalid();
        if ($row !== null && $unique !== '' && $row['unique'] !== $unique) self::invalid();
        if ($id !== null && (!array_key_exists('unique',$value) || (string)$row['unique'] !== $unique)) self::invalid();
        return $row;
    }

    private static function patch(array $group, string $suk): array
    {
        $patch = ['suk'=>$suk];
        foreach (self::MONEY as $key) $patch[$key] = self::decimal($group[$key] ?? 0,in_array($key,['weight','volume','brokerage','brokerage_two'],true));
        $patch['image'] = self::text($group['image'] ?? '',128);
        foreach (['bar_code','bar_code_number'] as $key) $patch[$key] = self::text($group[$key] ?? '',50);
        foreach (['quota','quota_show'] as $key) $patch[$key] = self::integer($group[$key] ?? 0,-2147483648,2147483647);
        foreach (['is_virtual','is_show','is_default_select'] as $key) $patch[$key] = self::flag($group[$key] ?? ($key === 'is_show' ? 1 : 0));
        $patch['coupon_id'] = self::integer($group['coupon_id'] ?? 0,0,2147483647);
        $patch['disk_info'] = self::text($group['disk_info'] ?? '',65535,65535);
        return $patch;
    }

    private static function attributes(array $groups, int $id): array
    {
        if (!array_is_list($groups)) self::invalid(); $out = []; $names = [];
        foreach ($groups as $group) {
            if (!is_array($group) || (string)($group['product_id'] ?? '') !== (string)$id || (string)($group['type'] ?? '') !== '0'
                || !isset($group['attr_values']) || !is_array($group['attr_values']) || !$group['attr_values']) self::invalid();
            $values = [];
            foreach ($group['attr_values'] as $value) {
                if (!is_array($value) || !array_key_exists('value',$value)) self::invalid();
                $values[] = self::text($value['value'],4294967295,4294967295);
            }
            $name = self::text($group['attr_name'] ?? '',32);if (isset($names[$name])) self::invalid();$names[$name] = true;
            $out[] = ['product_id'=>$id,'type'=>0,'attr_name'=>$name, 'attr_values'=>implode(',',$values)];
        }
        return $out;
    }

    private static function attributesSame(array $actual, array $wanted): bool
    {
        if (count($actual) !== count($wanted)) return false;
        foreach ($wanted as $index=>$row) foreach ($row as $key=>$value) if (!array_key_exists($key,$actual[$index])
            || !self::equal($actual[$index][$key],$value)) return false;
        return true;
    }

    /** Compare meaningful structure with the actual owned SKU rows, not stale UI price/identity aliases. */
    private static function semantic(array $result, array $skus): ?array
    {
        try {
            if (!isset($result['attr'],$result['value']) || !is_array($result['attr']) || !is_array($result['value'])) return null;
            $out = ['attr'=>[],'value'=>[]]; $names = []; $seenNames = [];
            foreach ($result['attr'] as $attr) {
                if (!is_array($attr) || !isset($attr['value'],$attr['detail']) || !is_array($attr['detail'])) return null;
                $name = self::text($attr['value'],32);if (isset($seenNames[$name])) return null;$seenNames[$name] = true;$names[] = $name; $details = [];
                foreach ($attr['detail'] as $detail) {
                    $details[] = is_string($detail) ? ['value'=>self::text($detail,4294967295,4294967295),'pic'=>'']
                        : ['value'=>self::text($detail['value'],4294967295,4294967295),'pic'=>self::text($detail['pic'] ?? '',4294967295,4294967295)];
                }
                $out['attr'][] = ['value'=>$name,'detail'=>$details];
            }
            foreach ($result['value'] as $value) {
                if (!is_array($value) || !isset($value['detail']) || !is_array($value['detail'])) return null;
                $detail = []; foreach ($names as $name) {if (!array_key_exists($name,$value['detail'])) return null;$detail[$name] = self::text($value['detail'][$name],128);}
                $suk = implode(',',array_values($detail));
                $row = self::select($value,[],$suk,$skus);
                if ($row === null) return null;
                $project = ['detail'=>$detail];
                foreach (self::MUTABLE as $key) {
                    if (in_array($key,self::MONEY,true)) $project[$key] = self::decimal($row[$key],in_array($key,['weight','volume','brokerage','brokerage_two'],true));
                    elseif ($key === 'disk_info') $project[$key] = $row[$key] ?? '';
                    elseif (in_array($key,['quota','quota_show','coupon_id','is_virtual','is_show','is_default_select'],true)) $project[$key] = (int)$row[$key];
                    else $project[$key] = $row[$key];
                }
                $out['value'][] = $project;
            }
            return $out;
        } catch (\Throwable $ignored) { return null; }
    }

    private static function result(int $id, array $result, array $old): void
    {
        $json = json_encode($result,JSON_THROW_ON_ERROR);
        if ($old && $old[0]['result'] === $json) return;
        $now = time();
        if ($now <= 0 || $now > 4294967295) throw new AuthUnavailableException();
        if ($old) {
            $affected = self::query('store_product_attr_result',$id)->where('type',0)->where('id',(int)$old[0]['id'])
                ->update(['result'=>$json,'change_time'=>$now]);
            if (!is_int($affected) || $affected < 0 || $affected > 1) throw new AuthUnavailableException();
        } elseif (self::query('store_product_attr_result',$id)->insert(['product_id'=>$id,'type'=>0,'result'=>$json,'change_time'=>$now]) !== 1) {
            throw new AuthUnavailableException();
        }
    }

    /** Content replacement never changes issued cards or card-inventory quantity. */
    private static function cards(int $id, string $unique, mixed $input, array $all, array $issuedIds): bool
    {
        if (!is_array($input) || !array_is_list($input)) self::invalid();
        $owned = array_values(array_filter($all,static fn(array $row): bool => (string)$row['attr_unique'] === $unique));
        $sold = []; $unsold = [];
        foreach ($owned as $row) if (in_array((int)$row['id'],$issuedIds,true)) $sold[] = $row; else $unsold[] = $row;
        $wanted = []; $usedSold = [];
        foreach ($input as $item) {
            if (!is_array($item) || !array_key_exists('key',$item) || !array_key_exists('value',$item)) self::invalid();
            $pair = ['card_no'=>self::text($item['key'],255),'card_pwd'=>self::text($item['value'],255)]; $issued = false;
            foreach ($sold as $index=>$row) if (!isset($usedSold[$index]) && $row['card_no'] === $pair['card_no'] && $row['card_pwd'] === $pair['card_pwd']) {
                $usedSold[$index] = true; $issued = true; break;
            }
            if (!$issued) $wanted[] = $pair;
        }
        if (count($wanted) !== count($unsold)) throw new AdminException('卡密数量变更须通过独立库存操作处理');
        $assigned = []; $remaining = [];
        foreach ($wanted as $index=>$pair) {
            $found = null;
            foreach ($unsold as $slot=>$row) if (!isset($assigned[$slot]) && $row['card_no'] === $pair['card_no'] && $row['card_pwd'] === $pair['card_pwd']) { $found = $slot;break; }
            if ($found !== null) $assigned[$found] = $pair; else $remaining[] = $pair;
        }
        $changed = false;
        foreach ($unsold as $slot=>$row) if (!isset($assigned[$slot])) {
            $pair = array_shift($remaining);
            if (!is_array($pair)) throw new AuthUnavailableException();
            $conflict = Db::name('store_product_virtual')->where('card_no',$pair['card_no'])->where('card_pwd',$pair['card_pwd'])->where('id','<>',(int)$row['id'])->lock(true);
            LegacyProductWriteServices::assertParent($id); LegacyProductWriteServices::query($conflict);
            if ($conflict->count() !== 0) throw new AdminException('卡密内容与已有记录重复');
            $pair['card_unique'] = md5($unique . ',' . $pair['card_no'] . ',' . $pair['card_pwd']);
            $order = Db::name('store_order')->where('virtual_info',$pair['card_unique'])->lock(true);
            LegacyProductWriteServices::query($order);
            if ($order->count() !== 0) throw new AuthUnavailableException();
            $query = self::query('store_product_virtual',$id)->where('attr_unique',$unique)->where('id',(int)$row['id'])->where('uid',0)->where('order_id','');
            if ($query->update($pair) !== 1) throw new AuthUnavailableException();
            $assigned[$slot] = $pair; $changed = true;
        }
        $after = self::query('store_product_virtual',$id)->where('attr_unique',$unique)->order('id')->lock(true)->select()->toArray();
        if (count($after) !== count($owned)) throw new AuthUnavailableException();
        $expected = []; foreach ($sold as $row) $expected[(int)$row['id']] = $row;
        foreach ($unsold as $slot=>$row) $expected[(int)$row['id']] = array_replace($row,$assigned[$slot]);
        foreach ($after as $row) if (!isset($expected[(int)$row['id']]) || !self::sameRow($row,$expected[(int)$row['id']])) throw new AuthUnavailableException();
        return $changed;
    }

    private static function query(string $table, int $id): Query
    {
        if (!in_array($table,['store_product_attr','store_product_attr_value','store_product_attr_result','store_product_virtual'],true)) throw new AuthUnavailableException();
        LegacyProductWriteServices::assertParent($id);
        $query = Db::name($table)->where('product_id',$id); LegacyProductWriteServices::query($query); return $query;
    }
    private static function sameRow(array $actual, array $expected): bool
    {
        if (array_keys($actual) !== array_keys($expected)) return false;
        foreach ($expected as $key=>$value) if (!self::equal($actual[$key],$value)) return false;
        return true;
    }
    private static function equal(mixed $a, mixed $b): bool { return ($a === null) === ($b === null) && ($a === null || (string)$a === (string)$b); }
    private static function integer(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && strlen($value) <= 11 && preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D',$value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < $min || $value > $max) self::invalid(); return $value;
    }
    private static function flag(mixed $value): int { return self::integer(is_bool($value) ? (int)$value : $value,0,1); }
    private static function text(mixed $value, int $chars, ?int $bytes = null): string
    {
        if (!is_string($value) || preg_match('//u',$value) !== 1 || str_contains($value,"\0")
            || mb_strlen($value,'UTF-8') > $chars || ($bytes !== null && strlen($value) > $bytes)) self::invalid(); return $value;
    }
    private static function decimal(mixed $value, bool $signed): string
    {
        if (is_float($value)) {if (!is_finite($value)) self::invalid();$value = json_encode($value,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
        if (is_int($value)) $value = (string)$value;
        $negative = is_string($value) && str_starts_with($value,'-');
        if ($negative) {if (!$signed) self::invalid();$value = substr($value,1);}
        $amount = LegacyProductWriteServices::money($value); return $negative && $amount !== '0.00' ? '-' . $amount : $amount;
    }
    private static function invalid(): never { throw new AdminException('商品规格参数不正确'); }
}
