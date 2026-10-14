<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\PlatformAccessServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AccountChangeGuard;
use crmeb\services\CacheService;
use PDO;
use think\db\Query;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\facade\Db;

/** Platform inventory has its own real grant and audit; no employee or legacy ledger is fabricated. */
final class PlatformProductStockServices
{
    public const ACTION = 'marketplace-products-stock-adjust';
    private const STOCK_MAX = 4294967295;
    private const ROOT_MAX = 16777215;
    private const CHILDREN = ['store_product_attr','store_product_attr_result','store_product_description',
        'store_product_cate','store_product_coupon','store_product_reply'];
    private static ?array $operation = null;
    private static ?array $auditing = null;

    public function __construct(private PlatformAccessServices $access, private AuditLogServices $audit) {}

    public function adjust(PlatformScope $scope, int $productId, array $data): array
    {
        $this->access->authorizeScope($scope,self::ACTION);
        if ($scope->targetMerId !== null) throw new AuthUnavailableException();
        self::integer($productId,1,8388607);
        if (array_diff(array_keys($data),['unique','delta','reason','virtual_list']) || !isset($data['unique'],$data['reason'])) self::invalid();
        $unique = self::unique($data['unique']); self::text($data['reason'],255,true);
        if (!array_key_exists('delta',$data) && !array_key_exists('virtual_list',$data)) self::invalid();
        $delta = array_key_exists('delta',$data) ? self::integer($data['delta'],-self::STOCK_MAX,self::STOCK_MAX) : null;
        $connection = Db::connect(); $pdo = $connection->connect();
        if (!$pdo instanceof PDO || $pdo->inTransaction() || self::$operation !== null) throw new AuthUnavailableException();
        $keys = []; $controlled = null;
        try {
            $receipts = AccountChangeGuard::runMany('adminapi',[],function()use($scope,$productId,$unique,$data,$delta,&$keys,&$controlled):array{
                try {
                    $this->access->authorizeScope($scope,self::ACTION);
                    $owner = $this->lockProduct($productId);
                    $rows = self::plain('store_product_attr_value')->where('product_id',$productId)->where('type',0)->order('id')->lock(true)->select()->toArray();
                    $matches = array_values(array_filter($rows,static fn(array $row):bool => $row['unique'] === $unique));
                    if (!$matches) throw new HttpException(404,'资源不存在');
                    if (count($matches) !== 1) throw new AuthUnavailableException();
                    $sku = $matches[0]; $intent = ['attr_value_id'=>self::integer($sku['id'],1,2147483647),'sku_unique'=>$unique];
                    if (array_key_exists('virtual_list',$data)) $intent += ['kind'=>'cards','virtual_list'=>$data['virtual_list'],'claimed_delta'=>$delta];
                    else $intent += ['kind'=>'delta','delta'=>$delta];
                    return $this->perform($scope,$productId,$owner,[$intent],false,$keys);
                } catch (HttpException $error) {
                    $status = $error->getStatusCode(); if (!in_array($status,[401,403,404,422],true)) throw $error;
                    $controlled = $status; throw new ValidateException('平台库存请求未完成');
                }
            });
        } catch (ValidateException $error) {
            if ($controlled !== null) throw new HttpException($controlled,match($controlled){401=>'请登录',403=>'没有权限访问',404=>'资源不存在',422=>'库存请求参数或状态不正确'});
            throw new AuthUnavailableException();
        } catch (AuthException $error) { throw $error; }
        catch (\Throwable $error) { throw new AuthUnavailableException(); }
        try { foreach ($keys as $key) CacheService::delete($key); }
        catch (\Throwable $error) { error_log('{"event":"platform_stock_committed_cache_unavailable"}');throw new AuthUnavailableException(); }
        if (count($receipts) !== 1) throw new AuthUnavailableException(); return $receipts[0];
    }

    /** One callback for one product: all SKU receipts share the phase's initial/final root stock. */
    public function applyLegacyIntents(PlatformScope $scope, int $productId, array $intents): array
    {
        if (!LegacyProductWriteServices::active() || !LegacyProductWriteServices::afterContentPhase()
            || LegacyProductWriteServices::rawScope() !== $scope) throw new AuthUnavailableException();
        $this->access->additionalStockGrant($scope); $owner = LegacyProductWriteServices::owner($productId); $keys = [];
        return $this->perform($scope,$productId,$owner,$intents,true,$keys);
    }

    /** Read-only plan for the legacy content helper; private card/order data never becomes a DTO or audit detail. */
    public static function cardPlan(int $productId, string $unique, mixed $input): array
    {
        LegacyProductWriteServices::assertParent($productId); self::unique($unique);
        return self::planCards($productId,$unique,$input,true);
    }
    public static function verifyCardPlan(array $plan): void
    { LegacyProductWriteServices::transaction();self::checkOrders($plan['orders']); }

    /** Audit consumes this server-owned state only while the stock service is actually recording its current receipt. */
    public static function currentAudit(PlatformScope $scope): array
    {
        $state = self::$auditing;
        if ($state === null || $state['scope'] !== $scope || self::$operation === null
            || $state['connection'] !== Db::connect() || $state['pdo'] !== Db::connect()->getPdo()
            || !$state['pdo']->inTransaction()) throw new AuthUnavailableException();
        return $state;
    }

    private function lockProduct(int $id): int
    {
        $row = self::plain('store_product')->where('id',$id)->field(['id','mer_id'])->find();
        if (!is_array($row)) throw new HttpException(404,'资源不存在');
        $owner = self::integer($row['mer_id'],1,4294967295);
        $merchant = self::plain('marketplace_merchant')->where('mer_id',$owner)->field('mer_id')->lock(true)->find();
        if (!is_array($merchant)) throw new AuthUnavailableException();
        $root = self::plain('store_product')->where('id',$id)->where('mer_id',$owner)->lock(true)->find();
        if (!is_array($root)) throw new AuthUnavailableException(); return $owner;
    }

    private function perform(PlatformScope $scope, int $id, int $owner, array $intents, bool $legacy, array &$keys): array
    {
        if (self::$operation !== null || !$intents || !array_is_list($intents)) throw new AuthUnavailableException();
        $connection = Db::connect(); $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) throw new AuthUnavailableException();
        self::$operation = ['scope'=>$scope,'connection'=>$connection,'pdo'=>$pdo,'product_id'=>$id,'owner'=>$owner,'legacy'=>$legacy];
        try {
            $before = self::snapshot($id,$owner);
            if ((string)$before['root']['is_del'] !== '0') throw new HttpException(404,'资源不存在');
            $rootBefore = self::integer($before['root']['stock'],0,self::ROOT_MAX);
            if (self::sumStock($before['skus']) !== $rootBefore) throw new AuthUnavailableException();
            $plans = []; $used = []; $totalDelta = 0;
            foreach ($intents as $intent) {
                if (!is_array($intent) || !isset($intent['attr_value_id'],$intent['sku_unique'],$intent['kind'])) throw new AuthUnavailableException();
                $skuId = self::integer($intent['attr_value_id'],1,2147483647); $unique = self::unique($intent['sku_unique']);
                if (isset($used[$skuId])) throw new AuthUnavailableException(); $used[$skuId] = true;
                $matches = array_values(array_filter($before['skus'],static fn(array $row):bool => (string)$row['id'] === (string)$skuId && $row['unique'] === $unique));
                if (count($matches) !== 1) throw new AuthUnavailableException(); $sku = $matches[0];
                $stockBefore = self::integer($sku['stock'],0,self::STOCK_MAX); $card = null;
                if ($intent['kind'] === 'cards') {
                    if (!self::isCard($before['root'],$sku)) self::invalid();
                    $card = self::planCards($id,$unique,$intent['virtual_list'] ?? null,$legacy);
                    if (count($card['unsold']) !== $stockBefore && !$card['conversion']) throw new AuthUnavailableException();
                    $delta = count($card['desired']) - $stockBefore;
                    if ($delta === 0 && !$card['conversion'] && !self::samePairs($card['unsold'],$card['desired'])) self::invalid();
                    if (isset($intent['claimed_delta']) && self::integer($intent['claimed_delta'],-self::STOCK_MAX,self::STOCK_MAX) !== $delta) self::invalid();
                } elseif ($intent['kind'] === 'delta') {
                    if (self::isCard($before['root'],$sku)) self::invalid();
                    $delta = self::integer($intent['delta'] ?? null,-self::STOCK_MAX,self::STOCK_MAX);
                } else throw new AuthUnavailableException();
                $afterStock = $stockBefore + $delta;
                if ($afterStock < 0 || $afterStock > self::STOCK_MAX) self::invalid(); $totalDelta += $delta;
                $plans[] = ['sku'=>$sku,'stock_before'=>$stockBefore,'stock_after'=>$afterStock,'delta'=>$delta,'cards'=>$card];
            }
            $rootAfter = $rootBefore + $totalDelta;
            if ($rootAfter < 0 || $rootAfter > self::ROOT_MAX) self::invalid();
            $expectedCards = $before['virtual']; $receipts = [];
            foreach ($plans as $plan) {
                $sku = $plan['sku']; $skuId = (int)$sku['id'];
                if ($plan['cards'] !== null) $expectedCards = self::applyCards($id,$sku['unique'],$plan['cards'],$expectedCards);
                if ($plan['delta'] !== 0) {
                    if (self::child('store_product_attr_value',$id)->where('id',$skuId)->where('unique',$sku['unique'])->update(['stock'=>$plan['stock_after']]) !== 1) throw new AuthUnavailableException();
                    foreach ([[$sku['suk'],''],['',$sku['unique']],[$sku['suk'],$sku['unique']]] as [$suk,$unique]) $keys[md5('product_attr_stock_'.$id.'_'.$suk.'_'.$unique)] = true;
                }
                $receipts[] = ['product_id'=>$id,'attr_value_id'=>$skuId,'sku_unique'=>$sku['unique'],'stock_before'=>$plan['stock_before'],
                    'delta'=>$plan['delta'],'stock_after'=>$plan['stock_after'],'root_stock_before'=>$rootBefore,'root_stock_after'=>$rootAfter];
            }
            if ($rootAfter !== $rootBefore && self::plain('store_product')->where('id',$id)->where('mer_id',$owner)->update(['stock'=>$rootAfter]) !== 1) throw new AuthUnavailableException();
            self::updateResult($id,$plans);
            $after = self::snapshot($id,$owner); self::checkAfter($before,$after,$receipts,$expectedCards);
            foreach ($plans as $plan) if ($plan['cards'] !== null) self::checkOrders($plan['cards']['orders']);
            foreach ($receipts as $receipt) {
                if ($legacy) $this->access->additionalStockGrant($scope); else $this->access->authorizeScope($scope,self::ACTION);
                $auditTime = time(); if ($auditTime < 1 || $auditTime > 4294967295) throw new AuthUnavailableException();
                self::$auditing = self::$operation + ['receipt'=>$receipt,'audit_time'=>$auditTime];
                try { $auditId = $this->audit->recordPlatformStock($scope); $this->checkAudit($auditId,$scope,$owner,$receipt,$auditTime); }
                finally { self::$auditing = null; }
            }
            if ($legacy) $this->access->additionalStockGrant($scope); else $this->access->authorizeScope($scope,self::ACTION);
            $keys = array_keys($keys); return $receipts;
        } finally { self::$auditing = null; self::$operation = null; }
    }

    private static function planCards(int $id, string $unique, mixed $input, bool $legacy): array
    {
        if (!is_array($input) || !array_is_list($input)) self::invalid();
        $skuRows = self::child('store_product_attr_value',$id)->where('unique',$unique)->lock(true)->select()->toArray();
        if (count($skuRows) !== 1) throw new AuthUnavailableException(); $sku = $skuRows[0];
        $owned = self::child('store_product_virtual',$id)->where('attr_unique',$unique)->order('id')->lock(true)->select()->toArray();
        $hashes = []; $orderIds = [];
        foreach ($owned as $row) {
            self::integer($row['id'],1,4294967295);
            if (!is_string($row['card_unique']) || preg_match('/\A[a-fA-F0-9]{32}\z/D',$row['card_unique']) !== 1) throw new AuthUnavailableException();
            $hashes[] = $row['card_unique']; if ($row['order_id'] !== '') $orderIds[] = $row['order_id'];
        }
        $orders = [];
        if ($hashes || $orderIds) {
            $query = self::plain('store_order')->where(function(Query $query)use($hashes,$orderIds):void{
                if ($hashes) $query->whereIn('virtual_info',array_values(array_unique($hashes)));
                if ($orderIds) {if ($hashes) $query->whereOr('order_id','in',array_values(array_unique($orderIds)));else $query->whereIn('order_id',array_values(array_unique($orderIds)));}
            });
            $orders = $query->order('id')->lock(true)->select()->toArray();
        }
        $referenced = []; foreach ($orders as $order) $referenced[strtolower(rtrim((string)$order['virtual_info'],' '))] = true;
        $issued = []; $unsold = []; $issuedPairs = []; $currentPairs = [];
        foreach ($owned as $row) {
            $pair = ['card_no'=>self::text($row['card_no'],255),'card_pwd'=>self::text($row['card_pwd'],255)]; $key = self::pairKey($pair);
            if ((string)$row['uid'] !== '0' || $row['order_id'] !== '' || isset($referenced[strtolower($row['card_unique'])])) {
                $issued[] = $row; $issuedPairs[$key] = true;
            } else {
                if (isset($currentPairs[$key])) throw new AuthUnavailableException(); $currentPairs[$key] = true; $unsold[] = $row;
            }
        }
        $desired = []; $seen = [];
        foreach ($input as $item) {
            if (!is_array($item) || !array_key_exists('key',$item) || !array_key_exists('value',$item)) self::invalid();
            $pair = ['card_no'=>self::text($item['key'],255),'card_pwd'=>self::text($item['value'],255)]; $key = self::pairKey($pair);
            if (isset($issuedPairs[$key])) {if (!$legacy) self::invalid();continue;}
            if (!isset($seen[$key])) { $seen[$key] = true; $desired[] = $pair; }
        }
        $conversion = false;
        if ($legacy && LegacyProductWriteServices::active() && LegacyProductWriteServices::hasPlatformScope()) {
            $beforeSku = LegacyProductWriteServices::contentSkuBefore($id,(int)$sku['id']);
            if ($beforeSku !== null) {
                foreach (['id','product_id','type','unique','stock','sales'] as $field) if (!self::equal($beforeSku[$field],$sku[$field])) throw new AuthUnavailableException();
                $root = LegacyProductWriteServices::assertParent($id);
                $conversion = self::isCard($root,$sku) && !self::isCard($root,$beforeSku);
            }
        }
        return ['owned'=>$owned,'issued'=>$issued,'unsold'=>$unsold,'desired'=>$desired,'orders'=>$orders,'conversion'=>$conversion];
    }

    private static function applyCards(int $id, string $unique, array $plan, array $all): array
    {
        $desired = []; foreach ($plan['desired'] as $pair) $desired[self::pairKey($pair)] = $pair;
        $retained = []; $remove = [];
        foreach ($plan['unsold'] as $row) {
            $key = self::pairKey($row);
            if (isset($desired[$key])) { $retained[] = $row;unset($desired[$key]); }
            else $remove[] = $row;
        }
        foreach ($remove as $row) {
            if (self::child('store_product_virtual',$id)->where('attr_unique',$unique)->where('id',(int)$row['id'])->where('uid',0)->where('order_id','')->delete() !== 1) throw new AuthUnavailableException();
        }
        $expected = array_merge($plan['issued'],$retained);
        foreach ($desired as $pair) {
            if (self::plain('store_product_virtual')->where('card_no',$pair['card_no'])->where('card_pwd',$pair['card_pwd'])->lock(true)->count() !== 0) self::invalid();
            $hash = md5($unique . ',' . $pair['card_no'] . ',' . $pair['card_pwd']);
            if (self::plain('store_order')->where('virtual_info',$hash)->lock(true)->count() !== 0) throw new AuthUnavailableException();
            $insert = $pair + ['product_id'=>$id,'attr_unique'=>$unique,'card_unique'=>$hash,'order_id'=>'','uid'=>0];
            $cardId = self::integer(self::plain('store_product_virtual')->insertGetId($insert),1,4294967295);
            $row = self::child('store_product_virtual',$id)->where('attr_unique',$unique)->where('id',$cardId)->lock(true)->find();
            if (!is_array($row)) throw new AuthUnavailableException();
            foreach ($insert as $field=>$value) if (!self::equal($row[$field],$value)) throw new AuthUnavailableException();
            $expected[] = $row;
        }
        $after = self::child('store_product_virtual',$id)->where('attr_unique',$unique)->order('id')->lock(true)->select()->toArray();
        if (!self::sameRows($after,$expected)) throw new AuthUnavailableException();
        $rest = array_values(array_filter($all,static fn(array $row):bool => $row['attr_unique'] !== $unique)); return array_merge($rest,$after);
    }

    private static function snapshot(int $id, int $owner): array
    {
        $root = self::plain('store_product')->where('id',$id)->where('mer_id',$owner)->lock(true)->find();
        if (!is_array($root)) throw new AuthUnavailableException(); $children = [];
        foreach (self::CHILDREN as $table) $children[$table] = self::child($table,$id)->lock(true)->select()->toArray();
        return ['root'=>$root,'skus'=>self::child('store_product_attr_value',$id)->order('id')->lock(true)->select()->toArray(),
            'virtual'=>self::child('store_product_virtual',$id)->order('id')->lock(true)->select()->toArray(),
            'ledger'=>self::plain('marketplace_stock_log')->where('product_id',$id)->order('id')->lock(true)->select()->toArray(),'children'=>$children];
    }
    private static function updateResult(int $id, array $plans): void
    {
        if (!array_filter($plans,static fn(array $plan):bool => $plan['delta'] !== 0 || ($plan['cards']['conversion'] ?? false))) return;
        $rows = self::child('store_product_attr_result',$id)->order('id')->lock(true)->select()->toArray();
        if (count($rows) > 1) throw new AuthUnavailableException(); if (!$rows) return;
        $definition = json_decode($rows[0]['result'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($definition) || !isset($definition['attr'],$definition['value']) || !is_array($definition['value'])) throw new AuthUnavailableException();
        foreach ($plans as $plan) {
            if ($plan['delta'] === 0 && !($plan['cards']['conversion'] ?? false)) continue;
            $sku = $plan['sku']; $matched = [];
            foreach ($definition['value'] as $index=>$value) {
                if (!is_array($value)) throw new AuthUnavailableException();
                if (isset($value['unique']) && $value['unique'] !== '') $isMatch = $value['unique'] === $sku['unique'];
                elseif (isset($value['id'])) $isMatch = (string)$value['id'] === (string)$sku['id'];
                else { $parts = [];foreach ($definition['attr'] as $attr) {if (!isset($value['detail'][$attr['value']])) throw new AuthUnavailableException();$parts[] = $value['detail'][$attr['value']];}$isMatch = implode(',',$parts) === $sku['suk']; }
                if ($isMatch) $matched[] = $index;
            }
            // Retired inventory may have no active UI definition; keep that result shape intact.
            if (count($matched) > 1) throw new AuthUnavailableException(); if (!$matched) continue;
            $index = $matched[0]; $definition['value'][$index]['stock'] = $plan['stock_after'];
            if ($plan['cards'] !== null) {
                $cards = self::child('store_product_virtual',$id)->where('attr_unique',$sku['unique'])->order('id')->lock(true)->select()->toArray();
                $definition['value'][$index]['virtual_list'] = array_map(static fn(array $row):array => ['key'=>$row['card_no'],'value'=>$row['card_pwd']],$cards);
            }
        }
        $json = json_encode($definition,JSON_THROW_ON_ERROR);
        if ($json !== $rows[0]['result']) {
            if (self::child('store_product_attr_result',$id)->where('id',(int)$rows[0]['id'])->update(['result'=>$json]) !== 1) throw new AuthUnavailableException();
        }
    }
    private static function checkAfter(array $before, array $after, array $receipts, array $expectedCards): void
    {
        $a = $before['root'];$b = $after['root'];unset($a['stock'],$b['stock']);if ($a !== $b || $before['ledger'] !== $after['ledger']) throw new AuthUnavailableException();
        $receiptMap = [];foreach ($receipts as $receipt) $receiptMap[$receipt['attr_value_id']] = $receipt;
        if (count($before['skus']) !== count($after['skus'])) throw new AuthUnavailableException();
        foreach ($before['skus'] as $row) {
            $matches = array_values(array_filter($after['skus'],static fn(array $value):bool => (string)$value['id'] === (string)$row['id']));
            if (count($matches) !== 1) throw new AuthUnavailableException(); $current = $matches[0]; $receipt = $receiptMap[(int)$row['id']] ?? null;
            $expected = $row; if ($receipt !== null) $expected['stock'] = $receipt['stock_after'];
            if (!self::sameRow($current,$expected)) throw new AuthUnavailableException();
        }
        if (self::sumStock($after['skus']) !== self::integer($after['root']['stock'],0,self::ROOT_MAX)
            || !self::sameRows($after['virtual'],$expectedCards)) throw new AuthUnavailableException();
        foreach (self::CHILDREN as $table) {
            $a = $before['children'][$table];$b = $after['children'][$table];
            if ($table === 'store_product_attr_result') foreach ([&$a,&$b] as &$rows) {
                foreach ($rows as &$row) { $value = json_decode($row['result'],true,512,JSON_THROW_ON_ERROR);foreach ($value['value'] as &$sku) unset($sku['stock'],$sku['virtual_list']);unset($sku);$row['result'] = $value; }unset($row);
            }unset($rows);
            if ($a !== $b) throw new AuthUnavailableException();
        }
    }
    private static function checkOrders(array $before): void
    {
        if (!$before) return; $ids = array_column($before,'id');
        $after = self::plain('store_order')->whereIn('id',$ids)->order('id')->lock(true)->select()->toArray();
        if ($before !== $after) throw new AuthUnavailableException();
    }
    private function checkAudit(int $id, PlatformScope $scope, int $owner, array $receipt, int $auditTime): void
    {
        $row = self::plain('marketplace_audit_log')->where('id',$id)->where('mer_id',$owner)->lock(true)->find();
        if (!is_array($row)) throw new AuthUnavailableException();
        $columns = array_keys($row); sort($columns,SORT_STRING);
        $expectedColumns = ['id','mer_id','actor_type','actor_id','action','resource_type','resource_id','result','detail','add_time'];sort($expectedColumns,SORT_STRING);
        if ($columns !== $expectedColumns) throw new AuthUnavailableException();
        foreach (['id'=>$id,'mer_id'=>$owner,'actor_type'=>'admin','actor_id'=>$scope->adminId,'action'=>self::ACTION,'resource_type'=>'product-stock',
            'resource_id'=>(string)$receipt['attr_value_id'],'result'=>'success','add_time'=>$auditTime] as $field=>$value) if (!self::equal($row[$field],$value)) throw new AuthUnavailableException();
        $detail = json_decode($row['detail'],true,512,JSON_THROW_ON_ERROR);ksort($detail);$expected = $receipt;ksort($expected);
        if ($detail !== $expected) throw new AuthUnavailableException();
    }
    private static function child(string $table, int $id): Query
    {
        if (!in_array($table,array_merge(self::CHILDREN,['store_product_attr_value','store_product_virtual']),true)) throw new AuthUnavailableException();
        if (LegacyProductWriteServices::active()) LegacyProductWriteServices::assertParent($id);
        elseif (self::$operation === null || self::$operation['product_id'] !== $id) throw new AuthUnavailableException();
        $query = self::plain($table)->where('product_id',$id);
        if (in_array($table,['store_product_attr','store_product_attr_result','store_product_attr_value','store_product_description'],true)) $query->where('type',0);
        if ($table === 'store_product_reply') $query->where('reply_type','product'); return $query;
    }
    private static function plain(string $table): Query
    {
        $connection = Db::connect();$pdo = $connection->getPdo();if (!$pdo instanceof PDO || !$pdo->inTransaction()) throw new AuthUnavailableException();
        if (self::$operation !== null && ($connection !== self::$operation['connection'] || $pdo !== self::$operation['pdo'])) throw new AuthUnavailableException();
        if (LegacyProductWriteServices::active() && LegacyProductWriteServices::transaction() !== $pdo) throw new AuthUnavailableException();
        $query = Db::name($table);if ($query->getConnection() !== $connection || $query->getConnection()->getPdo() !== $pdo) throw new AuthUnavailableException();return $query;
    }
    private static function isCard(array $root, array $sku): bool
    { return (string)$root['virtual_type'] === '1' && (string)$sku['is_virtual'] === '1' && (string)$sku['coupon_id'] === '0' && ($sku['disk_info'] ?? '') === ''; }
    private static function samePairs(array $rows, array $pairs): bool
    { $a = array_map([self::class,'pairKey'],$rows);$b = array_map([self::class,'pairKey'],$pairs);sort($a,SORT_STRING);sort($b,SORT_STRING);return $a === $b; }
    private static function pairKey(array $row): string { return json_encode([$row['card_no'],$row['card_pwd']],JSON_THROW_ON_ERROR); }
    private static function sumStock(array $rows): int
    { $sum = 0;foreach ($rows as $row) {$sum += self::integer($row['stock'],0,self::STOCK_MAX);if ($sum > self::ROOT_MAX) throw new AuthUnavailableException();}return $sum; }
    private static function sameRows(array $actual, array $wanted): bool
    { usort($actual,static fn(array $a,array $b):int => (int)$a['id'] <=> (int)$b['id']);usort($wanted,static fn(array $a,array $b):int => (int)$a['id'] <=> (int)$b['id']);if (count($actual) !== count($wanted)) return false;foreach ($actual as $i=>$row) if (!self::sameRow($row,$wanted[$i])) return false;return true; }
    private static function sameRow(array $a, array $b): bool
    { if (array_keys($a) !== array_keys($b)) return false;foreach ($b as $key=>$value) if (!self::equal($a[$key],$value)) return false;return true; }
    private static function equal(mixed $a, mixed $b): bool { return ($a === null) === ($b === null) && ($a === null || (string)$a === (string)$b); }
    private static function integer(mixed $value, int $min, int $max): int
    { if (is_string($value) && preg_match('/\A(?:0|-?[1-9][0-9]{0,9})\z/D',$value) === 1) $value = (int)$value;if (!is_int($value) || $value < $min || $value > $max) self::invalid();return $value; }
    private static function unique(mixed $value): string
    { if (!is_string($value) || preg_match('/\A[A-Za-z0-9]{8}\z/D',$value) !== 1) self::invalid();return $value; }
    private static function text(mixed $value, int $max, bool $required = false): string
    { if (!is_string($value) || preg_match('//u',$value) !== 1 || str_contains($value,"\0") || mb_strlen($value,'UTF-8') > $max || ($required && trim($value) === '')) self::invalid();return $value; }
    private static function invalid(): never { throw new HttpException(422,'库存请求参数或状态不正确'); }
}
