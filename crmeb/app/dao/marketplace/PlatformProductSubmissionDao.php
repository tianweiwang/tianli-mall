<?php
declare(strict_types=1);
namespace app\dao\marketplace;

use app\dao\marketplace\scope\PlatformScope;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\db\Query;
use think\exception\HttpException;
use think\facade\Db;

/** Submission changes five review fields and current owned binding status; all stock and identities stay intact. */
final class PlatformProductSubmissionDao
{
    private const CHILDREN = [
        'store_product_attr'=>['type',0,true], 'store_product_attr_result'=>['type',0,true],
        'store_product_attr_value'=>['type',0,true], 'store_product_description'=>['type',0,false],
        'store_product_cate'=>[null,null,true], 'store_product_coupon'=>[null,null,true],
        'store_product_reply'=>['reply_type','product',true], 'store_product_virtual'=>[null,null,true],
    ];

    public function lockProduct(PlatformScope $scope,int $id): array
    {
        $row = $this->root($scope)->where('id',$id)->field(['id','mer_id'])->find();
        if (!is_array($row)) throw new HttpException(404,'资源不存在');
        $owner = self::integer($row['mer_id'],1,4294967295);
        $merchant = self::query('marketplace_merchant')->where('mer_id',$owner)->field('mer_id')->lock(true)->find();
        if (!is_array($merchant) || (string)$merchant['mer_id'] !== (string)$owner) throw new AuthUnavailableException();
        return $this->snapshot($scope,$id,$owner);
    }

    public function assertDefinition(array $state): void
    {
        $id = self::integer($state['root']['id'],1,8388607);$results = $state['children']['store_product_attr_result'];
        if (count($results) !== 1) throw new AuthUnavailableException();
        $result = json_decode($results[0]['result'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($result) || !isset($result['attr'],$result['value']) || !is_array($result['attr']) || !array_is_list($result['attr'])
            || !is_array($result['value']) || !array_is_list($result['value']) || !$result['attr'] || !$result['value']) throw new AuthUnavailableException();
        $names = [];foreach ($result['attr'] as $attr) {
            if (!is_array($attr) || !isset($attr['value']) || !is_string($attr['value']) || $attr['value'] === ''
                || preg_match('//u',$attr['value']) !== 1 || str_contains($attr['value'],"\0") || in_array($attr['value'],$names,true)) throw new AuthUnavailableException();
            $names[] = $attr['value'];
        }
        $skus = $state['children']['store_product_attr_value'];$seen = [];$active = 0;
        foreach ($result['value'] as $value) {
            if (!is_array($value) || !isset($value['detail']) || !is_array($value['detail'])
                || count($value['detail']) !== count($names) || array_diff(array_keys($value['detail']),$names)) throw new AuthUnavailableException();
            $parts = [];foreach ($names as $name) { $part = $value['detail'][$name];
                if (!is_string($part) || preg_match('//u',$part) !== 1 || str_contains($part,"\0")) throw new AuthUnavailableException();$parts[] = $part; }
            $suk = implode(',',$parts);$matches = [];$hasId = array_key_exists('id',$value);$unique = $value['unique'] ?? '';
            if (!is_string($unique) || ($unique !== '' && preg_match('/\A[A-Za-z0-9]{8}\z/D',$unique) !== 1)) throw new AuthUnavailableException();
            $skuId = $hasId ? self::integer($value['id'],1,2147483647) : null;
            if ($hasId && $unique === '') throw new AuthUnavailableException();
            foreach ($skus as $sku) if ($hasId ? (string)$sku['id'] === (string)$skuId && $sku['unique'] === $unique
                : ($unique !== '' ? $sku['unique'] === $unique : $sku['suk'] === $suk)) $matches[] = $sku;
            if (count($matches) !== 1) throw new AuthUnavailableException();$sku = $matches[0];$skuId = self::integer($sku['id'],1,2147483647);
            if (isset($seen[$skuId]) || $sku['suk'] !== $suk || (isset($value['suk']) && $value['suk'] !== $suk)
                || (isset($value['product_id']) && (string)$value['product_id'] !== (string)$id)
                || (isset($value['type']) && (string)$value['type'] !== '0')) throw new AuthUnavailableException();
            $seen[$skuId] = true;if (self::integer($sku['is_show'],0,1) === 1) ++$active;
        }
        if ($active === 0 || count($state['children']['store_product_description']) > 1) throw new AuthUnavailableException();
    }

    public function submit(PlatformScope $scope,int $id,array $before): array
    {
        $owner = self::integer($before['root']['mer_id'],1,4294967295);
        if (!in_array(self::integer($before['root']['review_status'],0,3),[0,3],true)) throw new AuthUnavailableException();
        $current = $this->snapshot($scope,$id,$owner);
        if ($current !== $before) throw new AuthUnavailableException();
        $wanted = ['review_status'=>1,'review_reason'=>'','review_admin_id'=>null,'review_time'=>null,'is_show'=>0];$changes = [];
        foreach ($wanted as $field=>$value) if (!self::equal($before['root'][$field],$value)) $changes[$field] = $value;
        if ($this->root($scope)->where('id',$id)->where('mer_id',$owner)->update($changes) !== 1) throw new AuthUnavailableException();
        $expected = $before;$expected['root'] = array_replace($before['root'],$changes);$fields = array_keys($changes);$hidden = false;
        foreach ($before['children']['store_product_cate'] as $index=>$binding) {
            if ((string)$binding['product_id'] !== (string)$id) throw new AuthUnavailableException();
            if ((string)$binding['status'] !== '0') {
                $query = self::child('store_product_cate',$id)->where('id',self::integer($binding['id'],1,2147483647));
                if ($query->update(['status'=>0]) !== 1) throw new AuthUnavailableException();
                $expected['children']['store_product_cate'][$index]['status'] = is_int($binding['status']) ? 0 : '0';$hidden = true;
            }
        }
        if ($hidden) $fields[] = 'categories';
        $after = $this->snapshot($scope,$id,$owner);
        if (!self::sameState($after,$expected)) throw new AuthUnavailableException();return [$after,$fields];
    }

    public function auditRows(PlatformScope $scope,int $id,int $owner): array
    {
        $root = $this->root($scope)->where('id',$id)->where('mer_id',$owner)->field(['id','mer_id'])->lock(true)->find();
        if (!is_array($root) || (string)$root['id'] !== (string)$id || (string)$root['mer_id'] !== (string)$owner) throw new AuthUnavailableException();
        $rows = self::query('marketplace_audit_log')->where('mer_id',$owner)->where('action','marketplace-products-submit-review')
            ->where('resource_type','product')->where('resource_id',(string)$id)->order('id')->lock(true)->select()->toArray();
        $map = [];foreach ($rows as $row) $map[self::integer($row['id'],1,4294967295)] = $row;return $map;
    }
    public function assertAudit(PlatformScope $scope,int $id,int $owner,array $before,array $fields,int $start,int $end): void
    {
        if ($start < 1 || $end < $start || $end > 4294967295) throw new AuthUnavailableException();
        $after = $this->auditRows($scope,$id,$owner);$new = [];
        foreach ($before as $auditId=>$row) if (!isset($after[$auditId]) || $after[$auditId] !== $row) throw new AuthUnavailableException();
        foreach ($after as $auditId=>$row) if (!isset($before[$auditId])) $new[$auditId] = $row;
        if (count($new) !== 1) throw new AuthUnavailableException();$row = array_values($new)[0];
        $keys = array_keys($row);sort($keys,SORT_STRING);
        $columns = ['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time'];sort($columns,SORT_STRING);
        if ($keys !== $columns) throw new AuthUnavailableException();
        foreach (['mer_id'=>$owner,'actor_type'=>'admin','actor_id'=>$scope->adminId,'action'=>'marketplace-products-submit-review',
            'resource_type'=>'product','resource_id'=>(string)$id,'result'=>'success'] as $field=>$value) if (!self::equal($row[$field],$value)) throw new AuthUnavailableException();
        self::integer($row['id'],1,4294967295);$time = self::integer($row['add_time'],1,4294967295);
        if ($time < $start || $time > $end || json_decode($row['detail'],true,512,JSON_THROW_ON_ERROR) !== ['changed_fields'=>$fields]) throw new AuthUnavailableException();
    }

    private function snapshot(PlatformScope $scope,int $id,int $owner): array
    {
        $root = $this->root($scope)->where('id',$id)->where('mer_id',$owner)->lock(true)->find();
        if (!is_array($root) || (string)$root['id'] !== (string)$id || (string)$root['mer_id'] !== (string)$owner) throw new HttpException(404,'资源不存在');
        $children = [];foreach (self::CHILDREN as $table=>[$filter,$value,$ordered]) {
            $query = self::child($table,$id);if ($ordered) $query->order('id');$children[$table] = $query->lock(true)->select()->toArray();
        }
        return ['root'=>$root,'children'=>$children,'ledger'=>self::query('marketplace_stock_log')->where('product_id',$id)->order('id')->lock(true)->select()->toArray()];
    }
    private function root(PlatformScope $scope): Query
    {
        if ($scope->targetMerId !== null || $scope->permission !== 'marketplace-products-submit-review') throw new AuthUnavailableException();
        $query = $scope->apply(self::query('store_product'));self::transaction($query);return $query;
    }
    private static function child(string $table,int $id): Query
    {
        if (!isset(self::CHILDREN[$table])) throw new AuthUnavailableException();$query = self::query($table)->where('product_id',$id);
        [$filter,$value] = self::CHILDREN[$table];if ($filter !== null) $query->where($filter,$value);return $query;
    }
    private static function query(string $table): Query
    { $query = Db::name($table);self::transaction($query);return $query; }
    private static function transaction(Query $query): void
    {
        $connection = Db::connect();$pdo = $connection->getPdo();if (!$pdo instanceof PDO || !$pdo->inTransaction()
            || $query->getConnection() !== $connection || $query->getConnection()->getPdo() !== $pdo) throw new AuthUnavailableException();
    }
    private static function sameState(array $actual,array $expected): bool
    {
        if (array_keys($actual) !== array_keys($expected) || !self::sameRow($actual['root'],$expected['root'])
            || $actual['ledger'] !== $expected['ledger'] || array_keys($actual['children']) !== array_keys($expected['children'])) return false;
        foreach ($expected['children'] as $table=>$rows) {
            if (count($rows) !== count($actual['children'][$table])) return false;
            foreach ($rows as $index=>$row) if (!self::sameRow($actual['children'][$table][$index],$row)) return false;
        }return true;
    }
    private static function sameRow(array $actual,array $expected): bool
    { if (array_keys($actual) !== array_keys($expected)) return false;foreach ($expected as $field=>$value) if (!self::equal($actual[$field],$value)) return false;return true; }
    private static function equal(mixed $a,mixed $b): bool
    { return ($a === null) === ($b === null) && ($a === null || (string)$a === (string)$b); }
    private static function integer(mixed $value,int $minimum,int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new AuthUnavailableException();return $value;
    }
}
