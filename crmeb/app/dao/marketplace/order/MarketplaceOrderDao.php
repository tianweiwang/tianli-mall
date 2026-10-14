<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\freight\MerchantFreightInput;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOException;
use PDOStatement;
use think\facade\Db;

/**
 * Internal version-one persistence only. The service owns the default-connection
 * transaction, authenticates its UID and supplies its actual confirmation frame
 * and private, freshly calculated quote. This DAO neither commits nor runs events.
 * A claimed state-zero row must be marked created before that transaction commits.
 */
final class MarketplaceOrderDao
{
    private const INT_MAX = 2147483647;
    private const UINT_MAX = 4294967295;
    private const ROOT_MAX = 8388607;
    private const ROOT_STOCK_MAX = 16777215;
    private const TABLES = ['marketplace_order_idempotency', 'marketplace_stock_movement', 'marketplace_merchant',
        'store_product', 'store_product_attr_value', 'shipping_templates', 'shipping_templates_region',
        'shipping_templates_free', 'shipping_templates_no_delivery', 'user_address', 'user_invoice',
        'store_cart', 'store_order', 'store_order_invoice', 'store_order_cart_info'];
    private const IDEM_FIELDS = ['uid','mer_id','idem_key','request_digest','confirmation_ref','source_kind',
        'cart_refs','state','order_id','post_commit_done','cancel_at','add_time'];
    private const MERCHANT_FIELDS = ['mer_id','name','logo','after_sale_contact','shipping_note','review_status',
        'accept_orders','fulfillment_enabled','security_locked','closed_at'];
    private const PRODUCT_FIELDS = ['id','mer_id','store_name','store_info','image','slider_image','unit_name',
        'price','cost','ot_price','vip_price','stock','sales','spec_type','is_show','is_del','is_virtual','virtual_type',
        'review_status','is_vip','freight','postage','temp_id','custom_form','is_limit','limit_type','limit_num',
        'min_qty','logistics','presale','give_integral'];
    private const SKU_FIELDS = ['id','product_id','type','unique','suk','image','price','cost','ot_price','vip_price',
        'stock','sales','weight','volume','is_show','is_virtual','coupon_id'];
    private const ADDRESS_FIELDS = ['id','uid','real_name','phone','province','city','city_id','district','detail','is_del'];
    private const INVOICE_FIELDS = ['id','uid','header_type','type','name','duty_number','drawer_phone','email',
        'tell','address','bank','card_number','is_del'];
    private const INVOICE_HEADING = ['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number'];
    private const CART_FIELDS = ['id','uid','type','product_id','product_attr_unique','cart_num','is_pay','is_del',
        'is_new','combination_id','seckill_id','bargain_id','advance_id','status'];
    private const ORDER_FIELDS = ['id','uid','mer_id','order_id','unique','marketplace_trade_version','cart_id',
        'total_num','total_price','total_postage','pay_price','pay_postage','cost','paid','is_cancel','is_del',
        'is_system_del','pay_uid','add_time','merchant_name_snapshot','merchant_after_sale_contact_snapshot'];
    private const ORDER_INPUT = ['uid','mer_id','order_id','unique','cart_id','total_num','total_price','total_postage',
        'pay_price','pay_postage','cost','add_time','real_name','user_phone','user_address','mark','gift_mark','pay_type',
        'channel_type','province','custom_form','merchant_name_snapshot','merchant_after_sale_contact_snapshot'];
    private const ITEM_FIELDS = ['id','oid','uid','mer_id','cart_id','product_id','cart_num','refund_num','surplus_num',
        'split_status','cart_info','unique'];
    private const MOVEMENT_FIELDS = ['id','mer_id','order_id','product_id','sku_id','sku_type','sku_unique','qty','kind',
        'idem_key','parent_reserve_id','order_item_id','refund_id','actor_type','actor_id','request_digest',
        'sku_stock_before','sku_stock_after','sku_sales_before','sku_sales_after','root_stock_before',
        'root_stock_after','root_sales_before','root_sales_after','add_time'];

    private string $prefix;

    public function __construct()
    {
        $prefix = Db::connect()->getConfig('prefix');
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) self::unavailable();
        foreach (self::TABLES as $table) if (strlen($prefix . $table) > 64) self::unavailable();
        $this->prefix = $prefix;
    }

    /**
     * $request is exactly MarketplaceOrderInput::create()['request'].
     * Returns claimed, the durable idem_key/digests, request and (for replay) raw order.
     * A new key for the same confirmation and digest reuses the original durable row.
     */
    public function claim(PDO $pdo, array $request, string $idemKey, int $now, int $cancelAt): array
    {
        $this->transaction($pdo);
        $request = self::request($request, $idemKey);
        $uid = self::integer($request['uid'], 1, self::INT_MAX);
        $merId = self::integer($request['mer_id'], 1, self::UINT_MAX);
        self::integer($now, 1, self::UINT_MAX);
        self::integer($cancelAt, $now, self::UINT_MAX);
        $digest = hash('sha256', MarketplaceOrderInput::canonical($request), true);
        $confirmation = hash('sha256', $request['confirm_key'], true);
        $row = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
            '`uid` = ? AND `idem_key` = ?', [$uid, $idemKey]);
        if ($row === null) $row = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
            '`uid` = ? AND `confirmation_ref` = ?', [$uid, $confirmation]);
        if ($row !== null) return $this->replay($pdo, $row, $request, $digest, $confirmation);
        $data = ['uid'=>$uid,'mer_id'=>$merId,'idem_key'=>$idemKey,'request_digest'=>$digest,
            'confirmation_ref'=>$confirmation,'source_kind'=>$request['source_kind'],
            'cart_refs'=>self::json($request['cart_refs']),'state'=>0,'order_id'=>null,'post_commit_done'=>0,
            'cancel_at'=>$cancelAt,'add_time'=>$now];
        try {
            $this->insert($pdo, 'marketplace_order_idempotency', $data, null, true);
        } catch (PDOException $error) {
            // Only an actual duplicate key is exposed by insert(); driver text is never returned.
            $row = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
                '`uid` = ? AND `idem_key` = ?', [$uid, $idemKey]);
            if ($row === null) $row = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
                '`uid` = ? AND `confirmation_ref` = ?', [$uid, $confirmation]);
            if ($row === null) self::unavailable();
            return $this->replay($pdo, $row, $request, $digest, $confirmation);
        }
        $stored = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
            '`uid` = ? AND `idem_key` = ?', [$uid, $idemKey]);
        if ($stored === null) self::unavailable();
        self::stored($stored, array_diff_key($data, ['cart_refs'=>true]));
        if (self::decode($stored['cart_refs']) !== $request['cart_refs']) self::unavailable();
        return ['claimed'=>true,'idem_key'=>$idemKey,'request_digest'=>$digest,'confirmation_ref'=>$confirmation,
            'request'=>$request,'order'=>null,'cancel_at'=>$cancelAt,'post_commit_done'=>0];
    }

    /**
     * $frame is the actual server MarketplaceCartSelection frame, never POST data.
     * Returns uid/mer_id/source_kind/cart_refs/selection; merchant is its actual raw
     * row, products and skus are keyed by physical PK with raw price/cost/member
     * fields, freight[temp_id] contains root and region/free/no_delivery rules,
     * address/invoice are actual own rows, and carts contains actual persistent rows.
     * Temporary references are checked against that real frame; no SQL cart is invented.
     */
    public function lockInputs(PDO $pdo, int $uid, int $merId, array $frame, int $addressId, int $invoiceId = 0): array
    {
        $this->transaction($pdo);
        self::integer($uid, 1, self::INT_MAX); self::integer($merId, 1, self::UINT_MAX);
        self::integer($addressId, 1, self::ROOT_STOCK_MAX); self::integer($invoiceId, 0, self::INT_MAX);
        $frame = MarketplaceCartSelection::frame($frame, $uid);
        if ($frame['mer_id'] !== $merId) self::conflict();
        $selection = self::selection($frame['selection'], $frame['cart_refs'], $frame['source_kind']);
        $merchant = $this->merchant($pdo, $merId);
        $productIds = array_values(array_unique(array_column($selection, 'product_id')));
        $skuIds = array_values(array_unique(array_column($selection, 'sku_id')));
        sort($productIds, SORT_NUMERIC); sort($skuIds, SORT_NUMERIC);
        $products = $skus = $templates = [];
        foreach ($productIds as $id) $products[$id] = $this->product($pdo, $id, $merId);
        foreach ($skuIds as $id) {
            $sku = $this->one($pdo, 'store_product_attr_value', self::SKU_FIELDS, '`id` = ?', [$id]);
            if ($sku === null) self::conflict();
            $skus[$id] = $sku;
        }
        foreach ($selection as $line) self::sku($skus[$line['sku_id']], $line);
        $templateIds = [];
        foreach ($products as $product) if (self::integer($product['freight'], 1, 3) === 3) {
            $templateIds[] = self::integer($product['temp_id'], 1, self::INT_MAX);
        }
        $templateIds = array_values(array_unique($templateIds)); sort($templateIds, SORT_NUMERIC);
        foreach ($templateIds as $id) {
            $root = $this->one($pdo, 'shipping_templates', ['id','mer_id','name','type','appoint','no_delivery','sort','add_time'],
                '`id` = ? AND `mer_id` = ?', [$id, $merId]);
            if ($root === null) self::conflict();
            $templates[$id] = ['root'=>$root,'rules'=>[]];
        }
        // Lock every selected template parent before locking its children.
        foreach ($templateIds as $id) foreach ([
            'region'=>['id','temp_id','province_id','city_id','first','first_price','continue','continue_price','type','uniqid'],
            'free'=>['id','temp_id','province_id','city_id','number','price','type','uniqid'],
            'no_delivery'=>['id','temp_id','province_id','city_id','uniqid'],
        ] as $kind=>$fields) {
            $templates[$id]['rules'][$kind] = $this->rows($pdo, 'shipping_templates_'.$kind, $fields,
                '`temp_id` = ?', [$id], '`id` ASC', MerchantFreightInput::ROW_MAX + 1);
            if (count($templates[$id]['rules'][$kind]) > MerchantFreightInput::ROW_MAX) self::unavailable();
        }
        $address = $this->one($pdo, 'user_address', self::ADDRESS_FIELDS, '`id` = ? AND `uid` = ? AND `is_del` = 0', [$addressId, $uid]);
        if ($address === null) self::conflict();
        $invoice = null;
        if ($invoiceId !== 0) {
            $invoice = $this->one($pdo, 'user_invoice', self::INVOICE_FIELDS, '`id` = ? AND `uid` = ? AND `is_del` = 0', [$invoiceId, $uid]);
            if ($invoice === null) self::conflict();
        }
        $carts = [];
        if ($frame['source_kind'] === 'persistent') foreach ($selection as $line) {
            $cart = $this->one($pdo, 'store_cart', self::CART_FIELDS, '`id` = ? AND `uid` = ?', [$line['cart_ref'], $uid]);
            if ($cart === null || self::bigint($cart['id']) !== $line['cart_ref']
                || self::integer($cart['product_id'], 1, self::ROOT_MAX) !== $line['product_id']
                || $cart['product_attr_unique'] !== $line['unique']
                || self::integer($cart['cart_num'], 1, 65535) !== $line['qty']
                || (string)$cart['type'] !== '0') self::conflict();
            foreach (['is_pay','is_del','is_new','combination_id','seckill_id','bargain_id','advance_id'] as $field) {
                if (self::integer($cart[$field], 0, self::UINT_MAX) !== 0) self::conflict();
            }
            if ($cart['status'] !== null && self::integer($cart['status'], 0, 1) !== 1) self::conflict();
            $cart['id'] = $line['cart_ref']; $carts[] = $cart;
        }
        return ['uid'=>$uid,'mer_id'=>$merId,'source_kind'=>$frame['source_kind'],'cart_refs'=>$frame['cart_refs'],
            'selection'=>$selection,'merchant'=>$merchant,'products'=>$products,'skus'=>$skus,'freight'=>$templates,
            'address'=>$address,'invoice'=>$invoice,'carts'=>$carts];
    }

    /**
     * $order contains ORDER_INPUT only; cart_id is the normalized string[] and
     * custom_form is the service's definition-validated form array. mark is raw text.
     * The service copies address/shop snapshots from lockInputs, not request text.
     * $invoice is the selected actual locked user_invoice row, or null.
     */
    public function insertOrder(PDO $pdo, array $order, ?array $invoice): int
    {
        $this->transaction($pdo);
        self::keys($order, self::ORDER_INPUT);
        $uid = self::integer($order['uid'], 1, self::INT_MAX);
        $merId = self::integer($order['mer_id'], 1, self::UINT_MAX);
        $key = self::text($order['unique'], 32, true);
        $claim = $this->pending($pdo, $uid, hash('sha256', $key, true));
        if (self::integer($claim['mer_id'], 1, self::UINT_MAX) !== $merId) self::conflict();
        $refs = self::references($order['cart_id'], $claim['source_kind']);
        if (self::decode($claim['cart_refs']) !== $refs) self::conflict();
        $data = ['uid'=>$uid,'mer_id'=>$merId,'order_id'=>self::text($order['order_id'], 32, true),'unique'=>$key,
            'cart_id'=>self::json($refs),'total_num'=>self::integer($order['total_num'], 1, self::UINT_MAX),
            'add_time'=>self::integer($order['add_time'], 1, self::UINT_MAX),'pay_uid'=>$uid,
            'marketplace_trade_version'=>1,'shipping_type'=>1,'is_gift'=>0,'paid'=>0,'is_cancel'=>0];
        foreach (['total_price','total_postage','pay_price','pay_postage','cost'] as $field) $data[$field] = self::money($order[$field]);
        foreach (['real_name'=>32,'user_phone'=>18,'user_address'=>100,'gift_mark'=>255,'pay_type'=>32,'channel_type'=>255,'province'=>255,
            'merchant_name_snapshot'=>128,'merchant_after_sale_contact_snapshot'=>255] as $field=>$max) {
            $data[$field] = self::text($order[$field], $max, in_array($field, ['real_name','user_phone','user_address','merchant_name_snapshot'], true));
        }
        if ($data['pay_type'] === 'yue') self::conflict();
        $data['mark'] = self::text(htmlspecialchars(self::text($order['mark'], 512), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 512);
        if (!is_array($order['custom_form']) || !array_is_list($order['custom_form'])) self::unavailable();
        $data['custom_form'] = self::json($order['custom_form']);
        $merchant = $this->merchant($pdo, $merId);
        if ($data['merchant_name_snapshot'] !== $merchant['name'] || $data['merchant_after_sale_contact_snapshot'] !== $merchant['after_sale_contact']) self::conflict();
        $id = $this->insert($pdo, 'store_order', $data, self::INT_MAX);
        $stored = $this->one($pdo, 'store_order', array_keys($data), '`id` = ?', [$id]);
        if ($stored === null) self::unavailable(); self::stored($stored, $data);
        if ($invoice !== null) {
            $invoiceId = self::integer($invoice['id'] ?? null, 1, self::INT_MAX);
            $actual = $this->one($pdo, 'user_invoice', self::INVOICE_FIELDS, '`id` = ? AND `uid` = ? AND `is_del` = 0', [$invoiceId, $uid]);
            if ($actual === null) self::conflict();
            foreach (self::INVOICE_HEADING as $field) if (!array_key_exists($field, $invoice) || (string)$actual[$field] !== (string)$invoice[$field]) self::conflict();
            $snapshot = ['uid'=>$uid,'category'=>'order','order_id'=>$id,'invoice_id'=>$invoiceId,
                'is_pay'=>0,'is_refund'=>0,'is_invoice'=>0,'add_time'=>self::integer($data['add_time'], 1, self::INT_MAX)];
            $snapshot['header_type'] = self::integer($actual['header_type'], 1, 2);
            $snapshot['type'] = self::integer($actual['type'], 1, 2);
            foreach (['name'=>100,'duty_number'=>50,'drawer_phone'=>30,'email'=>100,'tell'=>30,'address'=>255,'bank'=>50,'card_number'=>50] as $field=>$max) {
                $snapshot[$field] = self::text($actual[$field], $max);
            }
            $invoicePk = $this->insert($pdo, 'store_order_invoice', $snapshot, self::INT_MAX);
            $read = $this->one($pdo, 'store_order_invoice', array_keys($snapshot), '`id` = ?', [$invoicePk]);
            if ($read === null) self::unavailable(); self::stored($read, $snapshot);
        }
        return $id;
    }

    /** Returns sku_id => persisted movement plus exact cart_refs/cart_quantities. */
    public function reserve(PDO $pdo, int $uid, int $merId, int $orderId, array $lockedInputs, int $now): array
    {
        $this->transaction($pdo);
        self::integer($now, 1, self::UINT_MAX);
        $order = $this->order($pdo, $uid, $merId, $orderId, true);
        $this->pending($pdo, $uid, hash('sha256', $order['unique'], true));
        if (($lockedInputs['uid'] ?? null) !== $uid || ($lockedInputs['mer_id'] ?? null) !== $merId) self::unavailable();
        $kind = $lockedInputs['source_kind'] ?? null;
        $refs = self::references($lockedInputs['cart_refs'] ?? null, $kind);
        if (self::decode($order['cart_id']) !== $refs) self::conflict();
        $selection = self::selection($lockedInputs['selection'] ?? [], $refs, $kind);
        $groups = [];
        foreach ($selection as $line) {
            $id = $line['sku_id'];
            if (!isset($groups[$id])) $groups[$id] = ['product_id'=>$line['product_id'],'sku_id'=>$id,'sku_type'=>0,
                'unique'=>$line['unique'],'qty'=>0,'cart_quantities'=>[]];
            if ($groups[$id]['product_id'] !== $line['product_id'] || $groups[$id]['unique'] !== $line['unique']) self::conflict();
            $groups[$id]['qty'] += $line['qty'];
            self::integer($groups[$id]['qty'], 1, self::UINT_MAX);
            $groups[$id]['cart_quantities'][$line['cart_ref']] = $line['qty'];
        }
        ksort($groups, SORT_NUMERIC); $result = [];
        foreach ($groups as $skuId=>$group) {
            $root = $this->product($pdo, $group['product_id'], $merId);
            $sku = $this->one($pdo, 'store_product_attr_value', self::SKU_FIELDS, '`id` = ?', [$skuId]);
            if ($sku === null) self::conflict(); self::sku($sku, $group);
            $skuStock = self::integer($sku['stock'], 0, self::UINT_MAX);
            $skuSales = self::integer($sku['sales'], 0, self::UINT_MAX);
            $rootStock = self::integer($root['stock'], 0, self::ROOT_STOCK_MAX);
            $rootSales = self::integer($root['sales'], 0, self::ROOT_STOCK_MAX);
            $qty = $group['qty'];
            if ($qty > $skuStock || $qty > $rootStock || $skuSales > self::UINT_MAX - $qty
                || $rootSales > self::ROOT_STOCK_MAX - $qty) self::conflict();
            $skuAfter = $skuStock - $qty; $skuSalesAfter = $skuSales + $qty;
            $rootAfter = $rootStock - $qty; $rootSalesAfter = $rootSales + $qty;
            $n = $this->execute($pdo, 'UPDATE '.$this->table('store_product_attr_value').
                ' SET `stock` = ?, `sales` = ? WHERE `id` = ? AND `product_id` = ? AND `type` = 0 AND `unique` = ?'.
                ' AND `stock` = ? AND `sales` = ? AND `stock` >= ?',
                [$skuAfter,$skuSalesAfter,$skuId,$group['product_id'],$group['unique'],$skuStock,$skuSales,$qty])->rowCount();
            if ($n !== 1) self::conflict();
            $n = $this->execute($pdo, 'UPDATE '.$this->table('store_product').
                ' SET `stock` = ?, `sales` = ? WHERE `id` = ? AND `mer_id` = ? AND `stock` = ? AND `sales` = ? AND `stock` >= ?',
                [$rootAfter,$rootSalesAfter,$group['product_id'],$merId,$rootStock,$rootSales,$qty])->rowCount();
            if ($n !== 1) self::conflict();
            $actualSku = $this->one($pdo, 'store_product_attr_value', ['id','product_id','type','unique','stock','sales'], '`id` = ?', [$skuId]);
            $actualRoot = $this->one($pdo, 'store_product', ['id','mer_id','stock','sales'], '`id` = ?', [$group['product_id']]);
            if ($actualSku === null || $actualRoot === null) self::unavailable();
            self::stored($actualSku, ['product_id'=>$group['product_id'],'type'=>0,'unique'=>$group['unique'],'stock'=>$skuAfter,'sales'=>$skuSalesAfter]);
            self::stored($actualRoot, ['mer_id'=>$merId,'stock'=>$rootAfter,'sales'=>$rootSalesAfter]);
            $action = ['contract_version'=>1,'uid'=>$uid,'mer_id'=>$merId,'order_id'=>$orderId,'product_id'=>$group['product_id'],
                'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$qty,'kind'=>'reserve',
                'cart_refs'=>array_map('strval', array_keys($group['cart_quantities']))];
            $digest = hash('sha256', MarketplaceOrderInput::canonical($action), true);
            $movement = ['mer_id'=>$merId,'order_id'=>$orderId,'product_id'=>$group['product_id'],'sku_id'=>$skuId,
                'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$qty,'kind'=>'reserve',
                'idem_key'=>'reserve:'.$orderId.':'.$skuId,'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,
                'actor_type'=>'customer','actor_id'=>$uid,'request_digest'=>$digest,'sku_stock_before'=>$skuStock,
                'sku_stock_after'=>self::integer($actualSku['stock'],0,self::UINT_MAX),'sku_sales_before'=>$skuSales,
                'sku_sales_after'=>self::integer($actualSku['sales'],0,self::UINT_MAX),'root_stock_before'=>$rootStock,
                'root_stock_after'=>self::integer($actualRoot['stock'],0,self::ROOT_STOCK_MAX),'root_sales_before'=>$rootSales,
                'root_sales_after'=>self::integer($actualRoot['sales'],0,self::ROOT_STOCK_MAX),'add_time'=>$now];
            $id = $this->insert($pdo, 'marketplace_stock_movement', $movement, '18446744073709551615');
            $stored = $this->one($pdo, 'marketplace_stock_movement', self::MOVEMENT_FIELDS, '`id` = ?', [$id]);
            if ($stored === null || self::bigint($stored['id']) !== $id) self::unavailable(); self::stored($stored, $movement);
            $result[$skuId] = $stored + ['cart_refs'=>$action['cart_refs'],'cart_quantities'=>$group['cart_quantities']];
        }
        return $result;
    }

    /**
     * $cartInfo is the actual private quote in normalized cart order, not its public DTO.
     * The V1 price producer must supply origin_postage_price as the actual charged
     * whole-line postage snapshot, equal to postage_price at creation. A missing
     * snapshot is never inferred. $reserveMap is reserve()'s result.
     * Returns cart_ref => real signed-INT item PK.
     */
    public function insertItems(PDO $pdo, int $uid, int $merId, int $orderId, array $cartInfo, array $reserveMap): array
    {
        $this->transaction($pdo);
        $order = $this->order($pdo, $uid, $merId, $orderId, true);
        $claim = $this->pending($pdo, $uid, hash('sha256', $order['unique'], true));
        $refs = self::references(self::decode($claim['cart_refs']), $claim['source_kind']);
        if (!array_is_list($cartInfo) || count($cartInfo) !== count($refs)) self::conflict();
        $result = []; $quantities = []; $total = 0; $price = $postage = $cost = '0.00';
        foreach ($cartInfo as $index=>$cart) {
            if (!is_array($cart) || !is_array($cart['productInfo'] ?? null) || !is_array($cart['productInfo']['attrInfo'] ?? null)) self::unavailable();
            $ref = MarketplaceOrderInput::reference($cart['id'] ?? null, $claim['source_kind']);
            if ($ref !== $refs[$index]) self::conflict();
            $product = $cart['productInfo']; $sku = $product['attrInfo'];
            $productId = self::integer($product['id'] ?? null, 1, self::ROOT_MAX);
            $skuId = self::integer($sku['id'] ?? null, 1, self::INT_MAX);
            $qty = self::integer($cart['cart_num'] ?? null, 1, self::INT_MAX);
            $entry = $reserveMap[$skuId] ?? null;
            if (!is_array($entry) || !isset($entry['cart_quantities'][$ref]) || $entry['cart_quantities'][$ref] !== $qty) self::conflict();
            $reserveId = self::bigint($entry['id'] ?? null);
            $movement = $this->one($pdo, 'marketplace_stock_movement', self::MOVEMENT_FIELDS, '`id` = ?', [$reserveId]);
            if ($movement === null) self::conflict();
            self::stored($movement, ['mer_id'=>$merId,'order_id'=>$orderId,'product_id'=>$productId,
                'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>self::unique($sku['unique'] ?? null),'kind'=>'reserve',
                'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>'customer','actor_id'=>$uid]);
            if (self::integer($product['mer_id'] ?? null,1,self::UINT_MAX) !== $merId
                || self::integer($sku['product_id'] ?? null,1,self::ROOT_MAX) !== $productId
                || self::integer($sku['type'] ?? null,0,0) !== 0) self::conflict();
            $cart['id'] = $ref; $cart['product_id'] = $productId;
            foreach (['truePrice','vip_truePrice','sum_price','costPrice','postage_price','origin_postage_price'] as $field) $cart[$field] = self::money($cart[$field] ?? null);
            if ($cart['origin_postage_price'] !== $cart['postage_price']) self::conflict();
            $actualSku = $this->one($pdo,'store_product_attr_value',self::SKU_FIELDS,'`id` = ?',[$skuId]);
            if ($actualSku === null) self::conflict();
            self::sku($actualSku,['sku_id'=>$skuId,'product_id'=>$productId,'unique'=>$movement['sku_unique']]);
            if ($cart['costPrice'] !== self::money($actualSku['cost']) || $cart['sum_price'] !== self::money($actualSku['price'])
                || self::money($sku['cost'] ?? null) !== self::money($actualSku['cost'])
                || self::money($sku['price'] ?? null) !== self::money($actualSku['price'])) self::conflict();
            if (!is_string($cart['price_type'] ?? null) || !in_array($cart['price_type'],['member','level'],true)) self::unavailable();
            if (isset($cart['coupon_id']) && self::integer($cart['coupon_id'],0,0) !== 0) self::conflict();
            $cart['coupon_id'] = 0;
            $cart['sum_true_price'] = self::money(bcmul($cart['truePrice'], (string)$qty, 2));
            foreach (['coupon_price','integral_price','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage'] as $field) {
                if (array_key_exists($field,$cart) && !in_array($cart[$field],[0,'0','0.00'],true)) self::conflict();
                $cart[$field] = '0.00';
            }
            if (isset($cart['use_integral']) && self::integer($cart['use_integral'],0,0) !== 0) self::conflict();
            $cart['use_integral'] = 0;
            $cart['marketplace'] = ['cart_ref'=>$ref,'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$movement['sku_unique'],'reserve_id'=>$reserveId];
            $values = ['oid'=>$orderId,'uid'=>$uid,'mer_id'=>$merId,'cart_id'=>self::text($ref,50,true),'product_id'=>$productId,
                'cart_num'=>$qty,'surplus_num'=>$qty,'refund_num'=>0,'split_status'=>0,'unique'=>md5($ref . $orderId),
                'cart_info'=>self::json($cart)];
            $id = $this->insert($pdo, 'store_order_cart_info', $values, self::INT_MAX);
            $stored = $this->one($pdo, 'store_order_cart_info', self::ITEM_FIELDS, '`id` = ?', [$id]);
            if ($stored === null) self::unavailable(); self::stored($stored,$values);
            $result[$ref] = $id; $quantities[$reserveId] = ($quantities[$reserveId] ?? 0) + $qty;
            $total += $qty; self::integer($total,1,self::UINT_MAX);
            $price = bcadd($price,$cart['sum_true_price'],2); $postage = bcadd($postage,$cart['postage_price'],2);
            $cost = bcadd($cost,bcmul($cart['costPrice'],(string)$qty,2),2);
        }
        foreach ($reserveMap as $entry) {
            $id = self::bigint($entry['id'] ?? null);
            if (($quantities[$id] ?? 0) !== self::integer($entry['qty'] ?? null,1,self::UINT_MAX)) self::conflict();
        }
        if ($total !== self::integer($order['total_num'],1,self::UINT_MAX)
            || self::money($price) !== self::money($order['total_price'])
            || self::money($postage) !== self::money($order['pay_postage'])
            || self::money(bcadd($price,$postage,2)) !== self::money($order['pay_price'])
            || self::money($cost) !== self::money($order['cost'])) self::conflict();
        return $result;
    }

    /** Verifies actual order/items/reserves before the transaction owner may commit. */
    public function markCreated(PDO $pdo, int $uid, string $idemKey, int $orderId): void
    {
        $this->transaction($pdo); self::integer($uid,1,self::INT_MAX); self::integer($orderId,1,self::INT_MAX);
        $row = $this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `idem_key` = ?',[$uid,$idemKey]);
        if ($row === null || self::integer($row['state'],0,1) !== 0 || $row['order_id'] !== null
            || self::integer($row['post_commit_done'],0,1) !== 0) self::unavailable();
        $merId = self::integer($row['mer_id'],1,self::UINT_MAX);
        $order = $this->order($pdo,$uid,$merId,$orderId,true);
        if (!hash_equals($row['confirmation_ref'],hash('sha256',$order['unique'],true))) self::conflict();
        $refs = self::references(self::decode($row['cart_refs']),$row['source_kind']);
        if (self::decode($order['cart_id']) !== $refs) self::conflict();
        $items = $this->rows($pdo,'store_order_cart_info',self::ITEM_FIELDS,'`oid` = ?',[$orderId],'`id` ASC');
        if (count($items) !== count($refs)) self::unavailable();
        $seen = $reserved = []; $total = 0;
        foreach ($items as $item) {
            self::stored($item,['uid'=>$uid,'mer_id'=>$merId,'refund_num'=>0,'split_status'=>0]);
            $ref = MarketplaceOrderInput::reference($item['cart_id'],$row['source_kind']);
            if (!in_array($ref,$refs,true) || isset($seen[$ref]) || $item['unique'] !== md5($ref.$orderId)) self::unavailable();
            $snapshot = self::decode($item['cart_info']); $lineage = $snapshot['marketplace'] ?? null;
            if (!is_array($lineage) || ($lineage['cart_ref'] ?? null) !== $ref || ($snapshot['id'] ?? null) !== $ref) self::unavailable();
            $qty = self::integer($item['cart_num'],1,self::INT_MAX);
            if (self::integer($item['surplus_num'],1,self::INT_MAX) !== $qty || ($snapshot['cart_num'] ?? null) !== $qty) self::unavailable();
            $id = self::bigint($lineage['reserve_id'] ?? null);
            if (!isset($reserved[$id])) {
                $movement = $this->one($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,'`id` = ?',[$id]);
                if ($movement === null) self::unavailable();
                self::stored($movement,['mer_id'=>$merId,'order_id'=>$orderId,'kind'=>'reserve','actor_type'=>'customer','actor_id'=>$uid,
                    'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null]);
                $reserved[$id] = ['row'=>$movement,'qty'=>0];
            }
            self::stored($reserved[$id]['row'],['product_id'=>$item['product_id'],'sku_id'=>$lineage['sku_id'] ?? null,
                'sku_type'=>$lineage['sku_type'] ?? null,'sku_unique'=>$lineage['sku_unique'] ?? null]);
            $reserved[$id]['qty'] += $qty; $total += $qty; $seen[$ref] = true;
        }
        foreach ($reserved as $entry) if ($entry['qty'] !== self::integer($entry['row']['qty'],1,self::UINT_MAX)) self::unavailable();
        $all = $this->rows($pdo,'marketplace_stock_movement',['id'],'`order_id` = ? AND `kind` = ?',[$orderId,'reserve'],'`id` ASC');
        if (count($all) !== count($reserved) || $total !== self::integer($order['total_num'],1,self::UINT_MAX)) self::unavailable();
        $n = $this->execute($pdo,'UPDATE '.$this->table('marketplace_order_idempotency').
            ' SET `state` = 1, `order_id` = ?, `post_commit_done` = 0 WHERE `uid` = ? AND `idem_key` = ?'.
            ' AND `state` = 0 AND `order_id` IS NULL AND `request_digest` = ? AND `confirmation_ref` = ?',
            [$orderId,$uid,$idemKey,$row['request_digest'],$row['confirmation_ref']])->rowCount();
        if ($n !== 1) self::unavailable();
        $stored = $this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `idem_key` = ?',[$uid,$idemKey]);
        if ($stored === null) self::unavailable(); self::stored($stored,['state'=>1,'order_id'=>$orderId,'post_commit_done'=>0,'cancel_at'=>$row['cancel_at']]);
    }

    private function replay(PDO $pdo, array $row, array $request, string $digest, string $confirmation): array
    {
        if (self::integer($row['uid'],1,self::INT_MAX) !== $request['uid']
            || self::integer($row['mer_id'],1,self::UINT_MAX) !== $request['mer_id']
            || !is_string($row['request_digest']) || !hash_equals($row['request_digest'],$digest)
            || !is_string($row['confirmation_ref']) || !hash_equals($row['confirmation_ref'],$confirmation)
            || $row['source_kind'] !== $request['source_kind'] || self::decode($row['cart_refs']) !== $request['cart_refs']) self::conflict();
        if (self::integer($row['state'],0,1) !== 1) self::unavailable();
        $order = $this->order($pdo,$request['uid'],$request['mer_id'],self::integer($row['order_id'],1,self::INT_MAX),false);
        if (!hash_equals($confirmation,hash('sha256',$order['unique'],true)) || self::decode($order['cart_id']) !== $request['cart_refs']) self::unavailable();
        return ['claimed'=>false,'idem_key'=>$row['idem_key'],'request_digest'=>$digest,'confirmation_ref'=>$confirmation,
            'request'=>$request,'order'=>$order,'cancel_at'=>self::integer($row['cancel_at'],1,self::UINT_MAX),
            'post_commit_done'=>self::integer($row['post_commit_done'],0,1)];
    }

    private function pending(PDO $pdo, int $uid, string $confirmation): array
    {
        $row = $this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `confirmation_ref` = ?',[$uid,$confirmation]);
        if ($row === null || self::integer($row['state'],0,1) !== 0 || $row['order_id'] !== null
            || self::integer($row['post_commit_done'],0,1) !== 0) self::unavailable();
        return $row;
    }

    private function order(PDO $pdo, int $uid, int $merId, int $id, bool $creating): array
    {
        self::integer($uid,1,self::INT_MAX); self::integer($merId,1,self::UINT_MAX); self::integer($id,1,self::INT_MAX);
        $row = $this->one($pdo,'store_order',self::ORDER_FIELDS,'`id` = ? AND `uid` = ? AND `mer_id` = ?',[$id,$uid,$merId]);
        if ($row === null || self::integer($row['marketplace_trade_version'],1,1) !== 1) self::unavailable();
        self::text($row['unique'],32,true);
        if ($creating) self::stored($row,['paid'=>0,'is_cancel'=>0,'is_del'=>0,'is_system_del'=>0]);
        return $row;
    }

    private function merchant(PDO $pdo, int $id): array
    {
        $row = $this->one($pdo,'marketplace_merchant',self::MERCHANT_FIELDS,'`mer_id` = ?',[$id]);
        if ($row === null) self::conflict();
        self::stored($row,['review_status'=>1,'accept_orders'=>1,'security_locked'=>0,'closed_at'=>null]);
        return $row;
    }

    private function product(PDO $pdo, int $id, int $merId): array
    {
        $row = $this->one($pdo,'store_product',self::PRODUCT_FIELDS,'`id` = ? AND `mer_id` = ?',[$id,$merId]);
        if ($row === null) self::conflict();
        self::stored($row,['review_status'=>2,'is_show'=>1,'is_del'=>0,'is_virtual'=>0,'virtual_type'=>0,'presale'=>0]);
        return $row;
    }

    private static function sku(array $row, array $line): void
    {
        if (self::integer($row['id'],1,self::INT_MAX) !== $line['sku_id']
            || self::integer($row['product_id'],1,self::ROOT_MAX) !== $line['product_id']
            || self::integer($row['type'],0,0) !== 0 || self::unique($row['unique']) !== $line['unique']
            || self::integer($row['is_show'],0,1) !== 1 || self::integer($row['is_virtual'],0,0) !== 0
            || self::integer($row['coupon_id'],0,0) !== 0) self::conflict();
    }

    private static function request(array $request, string $idem): array
    {
        self::keys($request,['contract_version','uid','mer_id','source_kind','cart_refs','address_id','confirm_key','options']);
        if (($request['contract_version'] ?? null) !== 1 || !is_array($request['options'] ?? null)) self::unavailable();
        $uid = self::integer($request['uid'],1,self::INT_MAX);
        $refs = self::references($request['cart_refs'],$request['source_kind']);
        $raw = $request['options'] + ['addressId'=>$request['address_id'],'mer_id'=>$request['mer_id'],
            'cartId'=>implode(',',$refs),'new'=>$request['source_kind']==='temporary','idem_key'=>$idem];
        $actual = MarketplaceOrderInput::create($uid,self::text($request['confirm_key'],32,true),$raw)['request'];
        if (MarketplaceOrderInput::canonical($actual) !== MarketplaceOrderInput::canonical($request)) self::unavailable();
        return $actual;
    }

    private static function selection(array $lines, array $refs, string $kind): array
    {
        if (!array_is_list($lines) || count($lines) !== count($refs) || !$lines) self::unavailable();
        $out = [];
        foreach ($lines as $i=>$line) {
            if (!is_array($line)) self::unavailable(); self::keys($line,['cart_ref','product_id','sku_id','sku_type','unique','qty']);
            $ref = MarketplaceOrderInput::reference($line['cart_ref'],$kind);
            if ($ref !== $refs[$i] || self::integer($line['sku_type'],0,0) !== 0) self::conflict();
            $out[] = ['cart_ref'=>$ref,'product_id'=>self::integer($line['product_id'],1,self::ROOT_MAX),
                'sku_id'=>self::integer($line['sku_id'],1,self::INT_MAX),'sku_type'=>0,'unique'=>self::unique($line['unique']),
                'qty'=>self::integer($line['qty'],1,self::INT_MAX)];
        }
        return $out;
    }

    private static function references(mixed $refs, mixed $kind): array
    {
        if (!is_string($kind) || !in_array($kind,['persistent','temporary'],true) || !is_array($refs) || !array_is_list($refs) || !$refs) self::unavailable();
        foreach ($refs as $ref) if (!is_string($ref) || MarketplaceOrderInput::reference($ref,$kind) !== $ref) self::unavailable();
        if (MarketplaceOrderInput::references(implode(',',$refs),$kind) !== $refs) self::unavailable();
        return $refs;
    }

    private function transaction(PDO $pdo): void
    {
        $connection = Db::connect();
        if (PHP_INT_SIZE < 8 || !$pdo->inTransaction() || $connection->getPdo() !== $pdo
            || $connection->getConfig('prefix') !== $this->prefix || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)) self::unavailable();
    }

    private function execute(PDO $pdo, string $sql, array $values, bool $duplicate = false): PDOStatement
    {
        $this->transaction($pdo);
        try {
            $statement = $pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) self::unavailable();
            foreach (array_values($values) as $i=>$value) {
                if ($value !== null && !is_string($value) && !is_int($value)) self::unavailable();
                if (!$statement->bindValue($i+1,$value === null ? null : (string)$value,$value === null ? PDO::PARAM_NULL : PDO::PARAM_STR)) self::unavailable();
            }
            if (!$statement->execute()) self::unavailable();
            return $statement;
        } catch (PDOException $error) {
            if ($duplicate && ($error->errorInfo[0] ?? null) === '23000' && (int)($error->errorInfo[1] ?? 0) === 1062) throw $error;
            self::unavailable();
        }
    }

    private function one(PDO $pdo, string $table, array $fields, string $where, array $values): ?array
    {
        $rows = $this->rows($pdo,$table,$fields,$where,$values,'',2);
        if (count($rows) > 1) self::unavailable();
        return $rows[0] ?? null;
    }

    private function rows(PDO $pdo, string $table, array $fields, string $where, array $values, string $order = '', ?int $limit = null): array
    {
        $columns = implode(',',array_map(self::column(...),$fields));
        $sql = 'SELECT '.$columns.' FROM '.$this->table($table).' WHERE '.$where;
        if ($order !== '') $sql .= ' ORDER BY '.$order;
        if ($limit !== null) $sql .= ' LIMIT '.self::integer($limit,1,self::INT_MAX);
        $statement = $this->execute($pdo,$sql.' FOR UPDATE',$values);
        try { $rows = $statement->fetchAll(PDO::FETCH_ASSOC); }
        catch (PDOException $error) { self::unavailable(); }
        if (!is_array($rows)) self::unavailable();
        return $rows;
    }

    private function insert(PDO $pdo, string $table, array $values, int|string|null $pkMax, bool $duplicate = false): int|string|null
    {
        $columns = implode(',',array_map(self::column(...),array_keys($values)));
        $sql = 'INSERT INTO '.$this->table($table).' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($values),'?')).')';
        $statement = $this->execute($pdo,$sql,array_values($values),$duplicate);
        // Obtain the actual connection's ID before any following SQL statement.
        try { $id = $pkMax === null ? null : $pdo->lastInsertId(); }
        catch (PDOException $error) { self::unavailable(); }
        if ($statement->rowCount() !== 1) self::unavailable();
        if ($pkMax === null) return null;
        $id = self::bigint($id);
        if (is_int($pkMax)) return self::integer($id,1,$pkMax);
        if (strlen($id) > strlen($pkMax) || (strlen($id) === strlen($pkMax) && strcmp($id,$pkMax) > 0)) self::unavailable();
        return $id;
    }

    private function table(string $name): string
    {
        if (!in_array($name,self::TABLES,true)) self::unavailable();
        return '`'.$this->prefix.$name.'`';
    }

    private static function column(string $name): string
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/D',$name) !== 1) self::unavailable();
        return '`'.$name.'`';
    }

    private static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value),$keys) || array_diff($keys,array_keys($value))) self::unavailable();
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value) === 1) {
            $bound = (string)$max;
            if (strlen($value) > strlen($bound) || (strlen($value) === strlen($bound) && strcmp($value,$bound) > 0)) self::unavailable();
            $value = (int)$value;
        }
        if (!is_int($value) || $value < $min || $value > $max) self::unavailable();
        return $value;
    }

    private static function bigint(mixed $value): string
    {
        if (is_int($value) && $value > 0) $value = (string)$value;
        $max = '18446744073709551615';
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D',$value) !== 1 || strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value,$max) > 0)) self::unavailable();
        return $value;
    }

    private static function money(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value) !== 1) self::unavailable();
        return $value;
    }

    private static function unique(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9]{8}\z/D',$value) !== 1) self::unavailable();
        return $value;
    }

    private static function text(mixed $value, int $max, bool $nonempty = false): string
    {
        if (!is_string($value) || ($nonempty && $value === '') || preg_match('//u',$value) !== 1
            || str_contains($value,"\0") || mb_strlen($value,'UTF-8') > $max) self::unavailable();
        return $value;
    }

    private static function json(array $value): string
    {
        // Validate primitive types/UTF-8 without normalizing snapshot field order.
        MarketplaceOrderInput::canonical($value);
        $encoded = json_encode($value,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,32);
        if (strlen($encoded) > 65535) self::unavailable();
        return $encoded;
    }

    private static function decode(mixed $value): array
    {
        if (!is_string($value)) self::unavailable();
        try { $decoded = json_decode($value,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { self::unavailable(); }
        if (!is_array($decoded)) self::unavailable();
        return $decoded;
    }

    private static function stored(array $row, array $expected): void
    {
        foreach ($expected as $field=>$value) {
            if (!array_key_exists($field,$row) || ($value === null ? $row[$field] !== null : $row[$field] === null || (string)$row[$field] !== (string)$value)) self::unavailable();
        }
    }

    private static function conflict(): never { throw new ApiException('订单确认或幂等请求已变化',[],409); }
    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
