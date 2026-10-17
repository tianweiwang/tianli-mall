<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\freight\MerchantFreightInput;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOException;
use PDOStatement;
use think\db\ConnectionInterface;
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
    private const TEMPORARY_PAGE_SIZE = 100;
    private const TABLES = ['user', 'marketplace_order_idempotency', 'marketplace_stock_movement', 'marketplace_merchant',
        'store_product', 'store_product_attr_value', 'shipping_templates', 'shipping_templates_region',
        'shipping_templates_free', 'shipping_templates_no_delivery', 'user_address', 'user_invoice',
        'store_cart', 'store_order', 'store_order_invoice', 'store_order_cart_info', 'store_order_status', 'store_product_log'];
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
    private const ADDRESS_FIELDS = ['id','uid','real_name','phone','province','city','city_id','district','detail',
        'post_code','longitude','latitude','is_default','is_del','add_time'];
    private const INVOICE_FIELDS = ['id','uid','header_type','type','name','duty_number','drawer_phone','email',
        'tell','address','bank','card_number','is_del'];
    private const INVOICE_HEADING = ['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number'];
    private const CART_FIELDS = ['id','uid','type','product_id','product_attr_unique','cart_num','is_pay','is_del',
        'is_new','combination_id','seckill_id','bargain_id','advance_id','status'];
    private const ORDER_FIELDS = ['id','uid','mer_id','order_id','unique','marketplace_trade_version','cart_id',
        'total_num','total_price','total_postage','pay_price','pay_postage','cost','paid','is_cancel','is_del',
        'is_system_del','pay_uid','add_time','merchant_name_snapshot','merchant_after_sale_contact_snapshot','pay_type','pay_time','trade_no'];
    private const ORDER_INPUT = ['uid','mer_id','order_id','unique','cart_id','total_num','total_price','total_postage',
        'pay_price','pay_postage','cost','add_time','real_name','user_phone','user_address','mark','gift_mark','pay_type',
        'channel_type','province','custom_form','merchant_name_snapshot','merchant_after_sale_contact_snapshot'];
    private const ITEM_FIELDS = ['id','oid','uid','mer_id','cart_id','product_id','cart_num','refund_num','surplus_num',
        'split_status','cart_info','unique'];
    private const MOVEMENT_FIELDS = ['id','mer_id','order_id','product_id','sku_id','sku_type','sku_unique','qty','kind',
        'idem_key','parent_reserve_id','order_item_id','refund_id','actor_type','actor_id','request_digest',
        'sku_stock_before','sku_stock_after','sku_sales_before','sku_sales_after','root_stock_before',
        'root_stock_after','root_sales_before','root_sales_after','add_time'];
    private const CREATED_STATUS = 'cache_key_create_order';
    private const STATUS_FIELDS = ['oid','change_type','change_message','change_time'];
    private const PRODUCT_LOG_FIELDS = ['id','type','product_id','uid','visit_num','cart_num','order_num','pay_num',
        'pay_price','cost_price','pay_uid','refund_num','refund_price','collect_num','add_time'];
    private const CANCEL_FIELDS = ['id','uid','mer_id','order_id','unique','marketplace_trade_version','cart_id','total_num',
        'pay_price','paid','is_cancel','status','refund_status','pid','shipping_type','is_gift','delivery_type','delivery_id','delivery_name'];
    private const CANCEL_STATUS = 'cancel_order';

    private string $prefix;

    public function __construct(private MarketplacePaymentDao $payments)
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
        self::integer($cancelAt, $cancelAt === 0 ? 0 : $now, self::UINT_MAX);
        // Every create/replay uses this actual customer row before any ledger lock.
        $this->lockCustomer($pdo, $uid);
        $digest = hash('sha256', MarketplaceOrderInput::canonical($request), true);
        $confirmation = hash('sha256', $request['confirm_key'], true);
        $row = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
            '`uid` = ? AND `idem_key` = ?', [$uid, $idemKey]);
        if ($row === null) $row = $this->one($pdo, 'marketplace_order_idempotency', self::IDEM_FIELDS,
            '`uid` = ? AND `confirmation_ref` = ?', [$uid, $confirmation]);
        if ($row !== null) return $this->replay($pdo, $row, $request, $digest, $confirmation);
        if ($request['source_kind'] === 'temporary') $this->assertTemporaryUnconsumed($pdo, $uid, $request['cart_refs']);
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
        $this->payments->birth($pdo,$uid,$claim['idem_key'],$id,$data['add_time']);
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

    /** Verifies saved facts and consumes the actual locked selection before commit. */
    public function markCreated(PDO $pdo, int $uid, string $idemKey, int $orderId, array $lockedInputs, ?int $cancelAt = null): void
    {
        $this->transaction($pdo); self::integer($uid,1,self::INT_MAX); self::integer($orderId,1,self::INT_MAX);
        $row = $this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `idem_key` = ?',[$uid,$idemKey]);
        if ($row === null || self::integer($row['state'],0,1) !== 0 || $row['order_id'] !== null
            || self::integer($row['post_commit_done'],0,1) !== 0) self::unavailable();
        $merId = self::integer($row['mer_id'],1,self::UINT_MAX);
        $order = $this->order($pdo,$uid,$merId,$orderId,true);
        $cancelAt = $cancelAt ?? self::integer($row['cancel_at'],0,self::UINT_MAX);
        self::integer($cancelAt,$cancelAt === 0 ? 0 : self::integer($order['add_time'],1,self::UINT_MAX),self::UINT_MAX);
        if (!hash_equals($row['confirmation_ref'],hash('sha256',$order['unique'],true))) self::conflict();
        $refs = self::references(self::decode($row['cart_refs']),$row['source_kind']);
        if (self::decode($order['cart_id']) !== $refs) self::conflict();
        if (($lockedInputs['uid'] ?? null) !== $uid || ($lockedInputs['mer_id'] ?? null) !== $merId
            || ($lockedInputs['source_kind'] ?? null) !== $row['source_kind']
            || ($lockedInputs['cart_refs'] ?? null) !== $refs) self::conflict();
        $selection = self::selection($lockedInputs['selection'] ?? [], $refs, $row['source_kind']);
        $selected = []; foreach ($selection as $line) $selected[$line['cart_ref']] = $line;
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
            $line = $selected[$ref];
            if ($qty !== $line['qty'] || self::integer($item['product_id'],1,self::ROOT_MAX) !== $line['product_id']
                || ($lineage['sku_id'] ?? null) !== $line['sku_id'] || ($lineage['sku_type'] ?? null) !== 0
                || ($lineage['sku_unique'] ?? null) !== $line['unique']) self::conflict();
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
        $this->payments->assertFreshBirth($pdo,$uid,$row['idem_key'],$orderId);
        if ($row['source_kind'] === 'persistent') $this->consumePersistentCarts($pdo, $uid, $selection);
        $n = $this->execute($pdo,'UPDATE '.$this->table('marketplace_order_idempotency').
            ' SET `state` = 1, `order_id` = ?, `post_commit_done` = 0, `cancel_at` = ? WHERE `uid` = ? AND `idem_key` = ?'.
            ' AND `state` = 0 AND `order_id` IS NULL AND `request_digest` = ? AND `confirmation_ref` = ?',
            [$orderId,$cancelAt,$uid,$idemKey,$row['request_digest'],$row['confirmation_ref']])->rowCount();
        if ($n !== 1) self::unavailable();
        $stored = $this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `idem_key` = ?',[$uid,$idemKey]);
        if ($stored === null) self::unavailable(); self::stored($stored,['state'=>1,'order_id'=>$orderId,'post_commit_done'=>0,'cancel_at'=>$cancelAt]);
    }

    /** UID is already locked by claim; read the whole committed temporary history. */
    private function assertTemporaryUnconsumed(PDO $pdo, int $uid, array $refs): void
    {
        $cursor = null;
        do {
            $where = '`uid` = ? AND `source_kind` = ? AND `state` = 1';
            $values = [$uid, 'temporary'];
            if ($cursor !== null) { $where .= ' AND `idem_key` > ?'; $values[] = $cursor; }
            $statement = $this->execute($pdo, 'SELECT `uid`,`idem_key`,`source_kind`,`cart_refs`,`state`,`order_id` FROM '.
                $this->table('marketplace_order_idempotency').' FORCE INDEX (`PRIMARY`) WHERE '.$where.
                ' ORDER BY `idem_key` ASC LIMIT '.self::TEMPORARY_PAGE_SIZE.' FOR UPDATE', $values);
            try { $page = $statement->fetchAll(PDO::FETCH_ASSOC); }
            catch (PDOException $error) { self::unavailable(); }
            if (!is_array($page)) self::unavailable();
            foreach ($page as $row) {
                self::stored($row, ['uid'=>$uid,'source_kind'=>'temporary','state'=>1]);
                self::integer($row['order_id'],1,self::INT_MAX);
                $key = self::text($row['idem_key'],128,true);
                if ($cursor !== null && strcmp($key,$cursor) <= 0) self::unavailable();
                foreach (self::references(self::decode($row['cart_refs']),'temporary') as $used) {
                    if (in_array($used,$refs,true)) self::conflict();
                }
                $cursor = $key;
            }
        } while (count($page) === self::TEMPORARY_PAGE_SIZE);
    }

    /** Same PDO/transaction: only the selected, unchanged ordinary own rows disappear. */
    private function consumePersistentCarts(PDO $pdo, int $uid, array $selection): void
    {
        foreach ($selection as $line) {
            $ref = self::bigint($line['cart_ref']);
            $n = $this->execute($pdo, 'DELETE FROM '.$this->table('store_cart').
                ' WHERE `id` = ? AND `uid` = ? AND `product_id` = ? AND BINARY `product_attr_unique` = ? AND `cart_num` = ?'.
                ' AND `type` = 0 AND `is_pay` = 0 AND `is_new` = 0 AND `is_del` = 0'.
                ' AND `combination_id` = 0 AND `seckill_id` = 0 AND `bargain_id` = 0 AND `advance_id` = 0'.
                ' AND (`status` IS NULL OR `status` = 1)',
                [$ref,$uid,$line['product_id'],$line['unique'],$line['qty']])->rowCount();
            if ($n !== 1) self::conflict();
            if ($this->one($pdo,'store_cart',['id'],'`id` = ? AND `uid` = ?',[$ref,$uid]) !== null) self::unavailable();
        }
    }

    /** Autocommit identity read only; the following transaction must lock the real UID first. */
    public function postCommitCandidate(PDO $pdo, int $orderId): ?array
    {
        self::integer($orderId,1,self::INT_MAX);
        $rows=$this->postCommitRead($pdo,'SELECT `uid`,`order_id` FROM '.$this->table('marketplace_order_idempotency').
            ' WHERE `order_id` = ? AND `state` = 1 LIMIT 2',[$orderId]);
        if(count($rows)>1)self::unavailable();
        if(!$rows)return null;
        return ['uid'=>self::integer($rows[0]['uid'],1,self::INT_MAX),
            'order_id'=>self::integer($rows[0]['order_id'],1,self::INT_MAX)];
    }

    /** Owner-authorized saved root deadline only; no lock, current configuration or mutation. */
    public function savedPaymentDeadline(array $actualSavedOrder): int
    {
        [$id,$uid,$merId,$number,$key,$added]=self::savedPaymentDeadlineIdentity($actualSavedOrder);
        try{
            $connection=Db::connect();
            // The adopted single-database connection is the primary; do not borrow a read replica or open another PDO.
            if($connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false)self::unavailable();
            $pdo=$connection->getPdo();
            if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
            if(!$pdo instanceof PDO)self::unavailable();
            $rows=$this->postCommitRead($pdo,'SELECT l.`uid`,l.`mer_id`,l.`state`,l.`order_id`,l.`cancel_at`,l.`add_time`,'.
                'l.`source_kind`,l.`cart_refs`,l.`confirmation_ref`,o.`order_id` AS `order_no`,'.
                'o.`unique` AS `confirmation_key`,o.`cart_id` AS `saved_cart_id`,o.`add_time` AS `order_add_time`'.
                ' FROM '.$this->table('marketplace_order_idempotency').' l JOIN '.$this->table('store_order').' o'.
                ' ON o.`id` = l.`order_id` AND o.`uid` = l.`uid` AND o.`mer_id` = l.`mer_id`'.
                ' WHERE l.`order_id` = ? AND l.`uid` = ? AND l.`mer_id` = ? AND l.`state` = 1'.
                ' AND o.`marketplace_trade_version` = 1 AND o.`pid` = 0 AND o.`paid` = 0 AND o.`is_cancel` = 0'.
                ' AND BINARY o.`order_id` = BINARY ? AND BINARY o.`unique` = BINARY ? LIMIT 2',
                [$id,$uid,$merId,$number,$key]);
            if($connection!==Db::connect()||$connection->getPdo()!==$pdo||$pdo->inTransaction())self::unavailable();
        }catch(\Throwable $error){self::unavailable();}
        if(count($rows)!==1)self::unavailable();$row=$rows[0];
        $refs=self::savedPaymentDeadlineReferences($actualSavedOrder,$row,$id,$uid,$merId,$number,$key,$added);
        if(self::decode($row['saved_cart_id'])!==$refs)self::unavailable();
        return self::savedPaymentDeadlineValue($row,$added);
    }

    /**
     * Deadlines for the caller's actual eligible raw roots in one owned read snapshot.
     * The caller owns Auth and RR READ ONLY; this DAO does not establish either.
     * Returns root PK => saved cancel_at in input order, including an actual zero.
     */
    public function readSavedPaymentDeadlinesForRoots(ConnectionInterface $connection,PDO $pdo,array $fullUnpaidRoots):array
    {
        $this->readSnapshotConnection($connection,$pdo);
        if(!array_is_list($fullUnpaidRoots)||count($fullUnpaidRoots)>100)self::unavailable();
        $roots=[];$rootOrder=[];$identities=[];$savedCarts=[];
        foreach($fullUnpaidRoots as$header){
            if(!is_array($header))self::unavailable();
            foreach($header as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
            foreach(self::ORDER_FIELDS as$field)if(!array_key_exists($field,$header))self::unavailable();
            if(!array_key_exists('pid',$header))self::unavailable();
            $identity=self::savedPaymentDeadlineIdentity($header);[$id]=$identity;
            self::integer($header['paid'],0,0);self::integer($header['is_cancel'],0,0);
            // The old tidyOrder branch is type zero for precisely this unpaid, uncancelled, non-offline subset.
            if(!is_string($header['pay_type'])||$header['pay_type']==='offline')self::unavailable();
            if(isset($roots[$id]))self::unavailable();
            $roots[$id]=$header;$rootOrder[]=$id;$identities[$id]=$identity;
            $savedCarts[$id]=self::decode($header['cart_id']);
        }
        if($roots===[]){$this->readSnapshotConnection($connection,$pdo);return [];}
        $rootIds=array_keys($roots);sort($rootIds,SORT_NUMERIC);
        $marks=implode(',',array_fill(0,count($rootIds),'?'));
        $rows=$this->paymentApplicationSnapshotRows($connection,$pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,
            '`order_id` IN ('.$marks.')',$rootIds,'`order_id` ASC');
        $ledgers=[];$previous=0;
        foreach($rows as$row){
            $id=self::integer($row['order_id'],1,self::INT_MAX);
            if($id<=$previous||!isset($roots[$id])||isset($ledgers[$id]))self::unavailable();
            $ledgers[$id]=$row;$previous=$id;
        }
        if(count($ledgers)!==count($roots))self::unavailable();
        $result=[];
        foreach($rootOrder as$id){
            $this->readSnapshotConnection($connection,$pdo);
            $header=$roots[$id];[$id,$uid,$merId,$number,$key,$added]=$identities[$id];
            $order=$header;$order['cart_id']=$savedCarts[$id];
            // These aliases are exact columns of the already selected same-snapshot root, not another query.
            $row=$ledgers[$id];$row['order_no']=$header['order_id'];
            $row['confirmation_key']=$header['unique'];$row['order_add_time']=$header['add_time'];
            $refs=self::savedPaymentDeadlineReferences($order,$row,$id,$uid,$merId,$number,$key,$added);
            if($savedCarts[$id]!==$refs)self::unavailable();
            $result[$id]=self::savedPaymentDeadlineValue($row,$added);
        }
        $this->readSnapshotConnection($connection,$pdo);return $result;
    }

    /** The original saved-header identity checks, before either caller reads ledger facts. */
    private static function savedPaymentDeadlineIdentity(array $actualSavedOrder):array
    {
        $id=self::integer($actualSavedOrder['id']??null,1,self::INT_MAX);
        $uid=self::integer($actualSavedOrder['uid']??null,1,self::INT_MAX);
        $merId=self::integer($actualSavedOrder['mer_id']??null,1,self::UINT_MAX);
        if(self::integer($actualSavedOrder['marketplace_trade_version']??null,1,1)!==1
            ||self::integer($actualSavedOrder['pid']??null,0,0)!==0)self::unavailable();
        $number=self::text($actualSavedOrder['order_id']??null,32,true);
        $key=self::text($actualSavedOrder['unique']??null,32,true);
        if(strlen($number)>32||strlen($key)>32)self::unavailable();
        $added=self::integer($actualSavedOrder['add_time']??null,1,self::UINT_MAX);
        return [$id,$uid,$merId,$number,$key,$added];
    }

    /** Both callers supply their actual saved header and ledger facts; no SQL or Auth here. */
    private static function savedPaymentDeadlineReferences(array $actualSavedOrder,array $row,int $id,int $uid,int $merId,
        string $number,string $key,int $added):array
    {
        if(self::integer($row['uid'],1,self::INT_MAX)!==$uid
            ||self::integer($row['mer_id'],1,self::UINT_MAX)!==$merId
            ||self::integer($row['state'],1,1)!==1||self::integer($row['order_id'],1,self::INT_MAX)!==$id
            ||$row['order_no']!==$number||$row['confirmation_key']!==$key
            ||self::integer($row['add_time'],1,self::UINT_MAX)!==$added
            ||self::integer($row['order_add_time'],1,self::UINT_MAX)!==$added
            ||!is_string($row['confirmation_ref'])||strlen($row['confirmation_ref'])!==32
            ||!hash_equals($row['confirmation_ref'],hash('sha256',$key,true)))self::unavailable();
        $refs=self::references(self::decode($row['cart_refs']),$row['source_kind']);
        if(self::references($actualSavedOrder['cart_id']??null,$row['source_kind'])!==$refs)self::unavailable();
        return $refs;
    }

    /** Preserve the original zero-or-saved deadline semantics. */
    private static function savedPaymentDeadlineValue(array $row,int $added):int
    {
        $deadline=self::integer($row['cancel_at'],0,self::UINT_MAX);
        if($deadline!==0&&$deadline<$added)self::unavailable();
        return $deadline;
    }

    /** Nonlocking candidates are hints only. Every finish re-locks and verifies its saved order. */
    public function pendingPostCommits(PDO $pdo, int $cursor, int $batch): array
    {
        self::integer($cursor,0,self::INT_MAX);self::integer($batch,1,100);
        $rows=$this->postCommitRead($pdo,'SELECT `order_id` FROM '.$this->table('marketplace_order_idempotency').
            ' FORCE INDEX (`idx_ms4_finalize`) WHERE `post_commit_done` = 0 AND `state` = 1 AND `order_id` > ?'.
            ' ORDER BY `order_id` ASC LIMIT '.$batch,[$cursor]);
        $ids=[];
        foreach($rows as$row){$id=self::integer($row['order_id'],1,self::INT_MAX);
            if($id<=$cursor)self::unavailable();$ids[]=$id;$cursor=$id;}
        return $ids;
    }

    /**
     * Default transaction lock order: UID, ledger, actual saved order, saved items by PK.
     * Returns post_commit_done, raw ledger/order and selection rows containing the exact
     * cart_ref/product_id/sku_id/sku_type/unique/qty plus private saved cart_info.
     * Current merchant admission, catalog and price services have no role in this path.
     */
    public function lockPostCommit(PDO $pdo, int $uid, int $orderId): array
    {
        $this->transaction($pdo);self::integer($uid,1,self::INT_MAX);self::integer($orderId,1,self::INT_MAX);
        $this->lockCustomer($pdo,$uid);
        $row=$this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,
            '`uid` = ? AND `order_id` = ?',[$uid,$orderId]);
        if($row===null)self::unavailable();
        self::stored($row,['uid'=>$uid,'order_id'=>$orderId,'state'=>1]);
        self::text($row['idem_key'],128,true);
        if(!is_string($row['request_digest'])||strlen($row['request_digest'])!==32)self::unavailable();
        $done=self::integer($row['post_commit_done'],0,1);$order=$this->committedOrder($pdo,$row);
        return ['post_commit_done'=>$done,'ledger'=>$row,'order'=>$order,
            'selection'=>$done===1?[]:$this->postCommitItems($pdo,$row,$order)];
    }

    /**
     * After the service has observed all precisely owned caches absent, complete the
     * database responsibility atomically. No caller-supplied statistics or status data.
     * The legacy tables lack an order/effect unique key; the locked ledger is the deduper.
     */
    public function completePostCommit(PDO $pdo, int $uid, string $idemKey, int $orderId): array
    {
        $locked=$this->lockPostCommit($pdo,$uid,$orderId);$row=$locked['ledger'];$order=$locked['order'];
        if($row['idem_key']!==$idemKey)self::unavailable();
        if($locked['post_commit_done']===1)return ['post_commit_done'=>1,'completed_now'=>false];
        $createdAt=self::integer($order['add_time'],1,self::INT_MAX);
        // There is no status PK/unique key. An unexplained existing row is not proof of completion.
        if($this->rows($pdo,'store_order_status',self::STATUS_FIELDS,
            '`oid` = ? AND `change_type` = ?',[$orderId,self::CREATED_STATUS],'',2))self::unavailable();
        $status=['oid'=>$orderId,'change_type'=>self::CREATED_STATUS,'change_message'=>'订单生成','change_time'=>$createdAt];
        $this->insert($pdo,'store_order_status',$status,null);
        $saved=$this->rows($pdo,'store_order_status',self::STATUS_FIELDS,
            '`oid` = ? AND `change_type` = ?',[$orderId,self::CREATED_STATUS],'',2);
        if(count($saved)!==1)self::unavailable();self::stored($saved[0],$status);
        foreach($locked['selection'] as$line){
            $log=['type'=>'order','product_id'=>$line['product_id'],'uid'=>$uid,'visit_num'=>0,'cart_num'=>0,
                'order_num'=>$line['qty'],'pay_num'=>0,'pay_price'=>'0.00','cost_price'=>'0.00','pay_uid'=>0,
                'refund_num'=>0,'refund_price'=>'0.00','collect_num'=>0,'add_time'=>$createdAt];
            $id=$this->insert($pdo,'store_product_log',$log,self::INT_MAX);
            $saved=$this->one($pdo,'store_product_log',self::PRODUCT_LOG_FIELDS,'`id` = ?',[$id]);
            if($saved===null)self::unavailable();self::stored($saved,['id'=>$id]+$log);
        }
        $n=$this->execute($pdo,'UPDATE '.$this->table('marketplace_order_idempotency').
            ' SET `post_commit_done` = 1 WHERE `uid` = ? AND `idem_key` = ? AND `order_id` = ?'.
            ' AND `mer_id` = ? AND `state` = 1 AND `post_commit_done` = 0 AND `request_digest` = ? AND `confirmation_ref` = ?',
            [$uid,$idemKey,$orderId,$row['mer_id'],$row['request_digest'],$row['confirmation_ref']])->rowCount();
        if($n!==1)self::unavailable();
        $saved=$this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `idem_key` = ?',[$uid,$idemKey]);
        if($saved===null)self::unavailable();self::stored($saved,['uid'=>$uid,'order_id'=>$orderId,'state'=>1,'post_commit_done'=>1]);
        return ['post_commit_done'=>1,'completed_now'=>true];
    }

    /**
     * Called after PaymentDao's common locks on this same transaction. Historical
     * reserve facts are independent of today's catalog, stock, price and admission.
     * A completed post-commit flag never substitutes for the actual saved item set.
     */
    public function lockPaymentApplicationFacts(PDO $pdo, int $uid, int $orderId): array
    {
        $this->transaction($pdo);self::integer($uid,1,self::INT_MAX);self::integer($orderId,1,self::INT_MAX);
        $this->lockCustomer($pdo,$uid);
        $ledger=$this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,
            '`uid` = ? AND `order_id` = ?',[$uid,$orderId]);
        $ledger=self::applicationLedger($uid,$orderId,$ledger);
        $order=$this->committedOrder($pdo,$ledger);$merId=self::integer($order['mer_id'],1,self::UINT_MAX);
        $orderNo=self::text($order['order_id'],32,true);
        $selection=$this->postCommitItems($pdo,$ledger,$order);
        $items=$this->rows($pdo,'store_order_cart_info',self::ITEM_FIELDS,'`oid` = ?',[$orderId],'`id` ASC');
        $groups=self::applicationGroups($items,$selection);
        $parents=$this->rows($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,
            '`order_id` = ? AND `kind` = ?',[$orderId,'reserve'],'`id` ASC');
        if(count($parents)!==count($groups))self::unavailable();$reserves=[];$byChild=[];$seenParents=[];
        foreach($parents as$parent){
            [$parent,$parentId,$group,$seenParents]=self::applicationReserve($uid,$merId,$orderId,$parent,$groups,$seenParents);
            $reserves[]=$parent;
            $children=$this->rows($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,
                '`parent_reserve_id` = ?',[$parentId],'`id` ASC');
            $byChild=$this->applicationCredits($uid,$merId,$orderId,$parentId,$group,$children,$byChild);
        }
        $children=$this->rows($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,
            '`order_id` = ? AND `kind` IN (?,?)',[$orderId,'release','return_in'],'`id` ASC');
        $children=self::applicationAllCredits($children,$byChild);
        $childOrders=$this->rows($pdo,'store_order',['id','uid','mer_id','pid','marketplace_trade_version',
            'order_id','paid','status','refund_status','is_cancel'],'`pid` = ?',[$orderId],'`id` ASC');
        $childOrders=self::applicationChildOrders($childOrders,$orderId);
        return self::applicationResult($uid,$orderId,$merId,$orderNo,$items,$selection,$reserves,$children,$childOrders);
    }

    /**
     * Pure original saved facts supplied by a separately authorized same-snapshot reader.
     * Owner UID is data, not actor authority. This method never loads or locks a row.
     */
    public function readPaymentApplicationFactsFromFacts(int $ownerUid,int $orderId,array $facts):array
    {
        if(PHP_INT_SIZE<8)self::unavailable();self::integer($ownerUid,1,self::INT_MAX);self::integer($orderId,1,self::INT_MAX);
        self::keys($facts,['customer','ledger','root','items','reserves','reserve_children','credits','child_orders']);
        foreach(['customer'=>['uid'],'ledger'=>self::IDEM_FIELDS,'root'=>self::ORDER_FIELDS] as$name=>$fields){
            if(!is_array($facts[$name]))self::unavailable();self::keys($facts[$name],$fields);
            foreach($facts[$name] as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
        }
        $childFields=['id','uid','mer_id','pid','marketplace_trade_version','order_id','paid','status','refund_status','is_cancel'];
        foreach(['items'=>self::ITEM_FIELDS,'reserves'=>self::MOVEMENT_FIELDS,'reserve_children'=>self::MOVEMENT_FIELDS,
            'credits'=>self::MOVEMENT_FIELDS,'child_orders'=>$childFields] as$name=>$fields){
            $rows=$facts[$name];if(!is_array($rows)||!array_is_list($rows))self::unavailable();$previous=null;
            foreach($rows as$row){
                if(!is_array($row))self::unavailable();self::keys($row,$fields);
                foreach($row as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
                $id=self::bigint($row['id']);
                if($previous!==null&&(strlen($id)<strlen($previous)||(strlen($id)===strlen($previous)&&strcmp($id,$previous)<=0)))self::unavailable();
                $previous=$id;
            }
        }
        self::customerFacts($facts['customer'],$ownerUid);
        $ledger=self::applicationLedger($ownerUid,$orderId,$facts['ledger']);
        $refs=self::committedReferences($ledger);
        $uid=self::integer($ledger['uid'],1,self::INT_MAX);$merId=self::integer($ledger['mer_id'],1,self::UINT_MAX);
        $id=self::integer($ledger['order_id'],1,self::INT_MAX);
        $order=self::savedOrderFacts($facts['root'],false);
        self::integer($order['id'],1,self::INT_MAX);self::integer($order['uid'],1,self::INT_MAX);self::integer($order['mer_id'],1,self::UINT_MAX);
        self::stored($order,['id'=>$id,'uid'=>$uid,'mer_id'=>$merId]);
        $order=self::committedOrderReferences($ledger,$order,$refs);
        $merId=self::integer($order['mer_id'],1,self::UINT_MAX);$orderNo=self::text($order['order_id'],32,true);
        $identity=self::postCommitIdentity($ledger,$order);
        $items=$facts['items'];$selection=self::postCommitItemsFromRows($ledger,$order,$items,$identity);
        $groups=self::applicationGroups($items,$selection);$parents=$facts['reserves'];
        if(count($parents)!==count($groups))self::unavailable();$reserves=[];$byChild=[];$seenParents=[];$linked=[];
        foreach($facts['reserve_children'] as$child){
            $parentId=self::bigint($child['parent_reserve_id']);$linked[$parentId][]=$child;
        }
        foreach($parents as$parent){
            [$parent,$parentId,$group,$seenParents]=self::applicationReserve($ownerUid,$merId,$orderId,$parent,$groups,$seenParents);
            $reserves[]=$parent;
            $byChild=$this->applicationCredits($ownerUid,$merId,$orderId,$parentId,$group,$linked[$parentId]??[],$byChild);
        }
        if(array_diff_key($linked,$seenParents)!==[])self::unavailable();
        $children=self::applicationAllCredits($facts['credits'],$byChild);
        $childOrders=self::applicationChildOrders($facts['child_orders'],$orderId);
        return self::applicationResult($ownerUid,$orderId,$merId,$orderNo,$items,$selection,$reserves,$children,$childOrders);
    }

    /**
     * Complete page-local application facts from the caller's owned native read snapshot.
     * Raw saved owners are data; the caller separately owns authorization and RR READ ONLY.
     */
    public function readPaymentApplicationFactsBatch(ConnectionInterface $connection,PDO $pdo,array $authorizedRootHeaders):array
    {
        $this->readSnapshotConnection($connection,$pdo);
        if(!array_is_list($authorizedRootHeaders)||count($authorizedRootHeaders)>100)self::unavailable();
        $roots=[];$rootOrder=[];$owners=[];
        foreach($authorizedRootHeaders as$header){
            if(!is_array($header))self::unavailable();
            foreach($header as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
            $root=[];
            foreach(self::ORDER_FIELDS as$field){
                if(!array_key_exists($field,$header))self::unavailable();$root[$field]=$header[$field];
            }
            if(!array_key_exists('pid',$header))self::unavailable();self::integer($header['pid'],0,0);
            self::integer($root['marketplace_trade_version'],1,1);
            $id=self::integer($root['id'],1,self::INT_MAX);$uid=self::integer($root['uid'],1,self::INT_MAX);
            self::integer($root['mer_id'],1,self::UINT_MAX);
            if(isset($roots[$id]))self::unavailable();$roots[$id]=$root;$rootOrder[]=$id;$owners[$uid]=true;
        }
        if($roots===[]){$this->readSnapshotConnection($connection,$pdo);return [];}
        $rootIds=array_keys($roots);$ownerIds=array_keys($owners);sort($rootIds,SORT_NUMERIC);sort($ownerIds,SORT_NUMERIC);
        $rootMarks=implode(',',array_fill(0,count($rootIds),'?'));$ownerMarks=implode(',',array_fill(0,count($ownerIds),'?'));
        $users=[];$previous=0;
        $rows=$this->paymentApplicationSnapshotRows($connection,$pdo,'user',['uid'],'`uid` IN ('.$ownerMarks.')',$ownerIds,'`uid` ASC');
        foreach($rows as$row){
            $uid=self::integer($row['uid'],1,self::INT_MAX);
            if($uid<=$previous||!isset($owners[$uid])||isset($users[$uid]))self::unavailable();
            $users[$uid]=$row;$previous=$uid;
        }
        if(count($users)!==count($owners))self::unavailable();
        $ledgers=[];$previous=0;
        $rows=$this->paymentApplicationSnapshotRows($connection,$pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,
            '`order_id` IN ('.$rootMarks.')',$rootIds,'`order_id` ASC');
        foreach($rows as$row){
            $id=self::integer($row['order_id'],1,self::INT_MAX);
            if($id<=$previous||!isset($roots[$id])||isset($ledgers[$id]))self::unavailable();
            $ledgers[$id]=$row;$previous=$id;
        }
        if(count($ledgers)!==count($roots))self::unavailable();
        $items=[];$reserves=[];$reserveChildren=[];$credits=[];$childOrders=[];
        foreach($rootIds as$id){$items[$id]=[];$reserves[$id]=[];$reserveChildren[$id]=[];$credits[$id]=[];$childOrders[$id]=[];}
        $previous=0;
        $rows=$this->paymentApplicationSnapshotRows($connection,$pdo,'store_order_cart_info',self::ITEM_FIELDS,
            '`oid` IN ('.$rootMarks.')',$rootIds,'`id` ASC');
        foreach($rows as$row){
            $id=self::integer($row['id'],1,self::INT_MAX);$oid=self::integer($row['oid'],1,self::INT_MAX);
            if($id<=$previous||!isset($roots[$oid]))self::unavailable();$items[$oid][]=$row;$previous=$id;
        }
        $movementWhere='(`order_id` IN ('.$rootMarks.') OR `parent_reserve_id` IN (SELECT `id` FROM '
            .$this->table('marketplace_stock_movement').' WHERE `order_id` IN ('.$rootMarks.') AND `kind` = ?))';
        $movements=$this->paymentApplicationSnapshotRows($connection,$pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,
            $movementWhere,array_merge($rootIds,$rootIds,['reserve']),'`id` ASC');
        $parentRoots=[];$previous=null;
        foreach($movements as$row){
            $id=self::bigint($row['id']);
            if($previous!==null&&(strlen($id)<strlen($previous)||(strlen($id)===strlen($previous)&&strcmp($id,$previous)<=0)))self::unavailable();
            $previous=$id;$oid=self::integer($row['order_id'],1,self::INT_MAX);
            if(!is_string($row['kind'])||!in_array($row['kind'],['reserve','release','return_in'],true))self::unavailable();
            if(isset($roots[$oid])){
                if($row['kind']==='reserve'){$reserves[$oid][]=$row;$parentRoots[$id]=$oid;}
                else $credits[$oid][]=$row;
            }
        }
        foreach($movements as$row){
            $oid=self::integer($row['order_id'],1,self::INT_MAX);$linkedRoot=null;
            if($row['parent_reserve_id']!==null){
                $parentId=self::bigint($row['parent_reserve_id']);$linkedRoot=$parentRoots[$parentId]??null;
                if($linkedRoot!==null)$reserveChildren[$linkedRoot][]=$row;
            }
            if(!isset($roots[$oid])&&$linkedRoot===null)self::unavailable();
        }
        $previous=0;
        $rows=$this->paymentApplicationSnapshotRows($connection,$pdo,'store_order',
            ['id','uid','mer_id','pid','marketplace_trade_version','order_id','paid','status','refund_status','is_cancel'],
            '`pid` IN ('.$rootMarks.')',$rootIds,'`id` ASC');
        foreach($rows as$row){
            $id=self::integer($row['id'],1,self::INT_MAX);$pid=self::integer($row['pid'],1,self::INT_MAX);
            if($id<=$previous||!isset($roots[$pid]))self::unavailable();$childOrders[$pid][]=$row;$previous=$id;
        }
        $result=[];
        foreach($rootOrder as$id){
            $this->readSnapshotConnection($connection,$pdo);$root=$roots[$id];$uid=self::integer($root['uid'],1,self::INT_MAX);
            $facts=['customer'=>$users[$uid],'ledger'=>$ledgers[$id],'root'=>$root,'items'=>$items[$id],
                'reserves'=>$reserves[$id],'reserve_children'=>$reserveChildren[$id],'credits'=>$credits[$id],'child_orders'=>$childOrders[$id]];
            $result[$id]=$this->readPaymentApplicationFactsFromFacts($uid,$id,$facts);
        }
        $this->readSnapshotConnection($connection,$pdo);return $result;
    }

    private function readSnapshotConnection(ConnectionInterface $connection,PDO $pdo):void
    {
        if(!($connection instanceof \crmeb\compat\think\orm\db\connector\Mysql))self::unavailable();
        $depth=$connection->transactionDepth();
        if(PHP_INT_SIZE!==8||$connection!==Db::connect()||$connection->getPdo()!==$pdo
            ||!is_int($depth)||$depth!==0||$connection->getConfig('deploy')!==0||$connection->getConfig('rw_separate')!==false
            ||$connection->getConfig('prefix')!==$this->prefix||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)||$pdo->inTransaction()!==true)self::unavailable();
    }

    /** Fixed internal SELECT callers only; never use the locking rows()/one() here. */
    private function paymentApplicationSnapshotRows(ConnectionInterface $connection,PDO $pdo,string $table,array $fields,
        string $where,array $values,string $order):array
    {
        $this->readSnapshotConnection($connection,$pdo);
        $columns=implode(',',array_map(self::column(...),$fields));
        $sql='SELECT '.$columns.' FROM '.$this->table($table).' WHERE '.$where.' ORDER BY '.$order;
        $statement=$this->execute($pdo,$sql,$values);$rows=null;$failed=false;$closed=false;
        try{$rows=$statement->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $error){$failed=true;}
        try{$closed=$statement->closeCursor();}catch(PDOException $error){$failed=true;}
        unset($statement);$this->readSnapshotConnection($connection,$pdo);
        if($failed||$closed!==true||!is_array($rows)||!array_is_list($rows))self::unavailable();
        foreach($rows as$row){
            if(!is_array($row))self::unavailable();self::keys($row,$fields);
            foreach($row as$value)if($value!==null&&!is_string($value)&&!is_int($value))self::unavailable();
        }
        return $rows;
    }

    private static function applicationLedger(int $uid,int $orderId,?array $ledger):array
    {
        if($ledger===null)self::unavailable();
        self::stored($ledger,['uid'=>$uid,'order_id'=>$orderId,'state'=>1]);
        self::text($ledger['idem_key'],128,true);self::integer($ledger['post_commit_done'],0,1);
        if(!is_string($ledger['request_digest'])||strlen($ledger['request_digest'])!==32)self::unavailable();
        return $ledger;
    }

    private static function applicationGroups(array $items,array $selection):array
    {
        if(count($items)!==count($selection))self::unavailable();$groups=[];
        foreach($items as$item){
            $qty=self::integer($item['cart_num'],1,self::INT_MAX);
            self::integer($item['refund_num'],0,$qty);self::integer($item['surplus_num'],0,$qty);
            self::integer($item['split_status'],0,2);self::text($item['unique'],32,true);
        }
        foreach($selection as$line){
            $parentId=self::bigint($line['cart_info']['marketplace']['reserve_id']);
            if(!isset($groups[$parentId]))$groups[$parentId]=['product_id'=>$line['product_id'],
                'sku_id'=>$line['sku_id'],'unique'=>$line['unique'],'qty'=>0,'cart_refs'=>[]];
            $group=&$groups[$parentId];
            if($group['product_id']!==$line['product_id']||$group['sku_id']!==$line['sku_id']
                ||$group['unique']!==$line['unique'])self::unavailable();
            $group['qty']+=$line['qty'];self::integer($group['qty'],1,self::UINT_MAX);
            $group['cart_refs'][]=$line['cart_ref'];unset($group);
        }
        return $groups;
    }

    private static function applicationReserve(int $uid,int $merId,int $orderId,array $parent,array $groups,array $seenParents):array
    {
        $parent=self::paymentMovement($parent);$parentId=$parent['id'];
        $group=$groups[$parentId]??null;
        if($group===null||isset($seenParents[$parentId]))self::unavailable();$seenParents[$parentId]=true;
        self::stored($parent,['mer_id'=>$merId,'order_id'=>$orderId,'product_id'=>$group['product_id'],
            'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],
            'kind'=>'reserve','parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,
            'actor_type'=>'customer','actor_id'=>$uid,'idem_key'=>'reserve:'.$orderId.':'.$group['sku_id']]);
        $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$merId,'order_id'=>$orderId,
            'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],
            'qty'=>$group['qty'],'kind'=>'reserve','cart_refs'=>$group['cart_refs']];
        self::movementDigest($parent,$action);self::reserveArithmetic($parent);
        return [$parent,$parentId,$group,$seenParents];
    }

    private function applicationCredits(int $uid,int $merId,int $orderId,string $parentId,array $group,array $children,array $byChild):array
    {
        $credited=0;$released=false;
        foreach($children as$child){
            $child=self::paymentMovement($child);$childId=$child['id'];
            if(isset($byChild[$childId]))self::unavailable();
            self::stored($child,['mer_id'=>$merId,'order_id'=>$orderId,'product_id'=>$group['product_id'],
                'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],'parent_reserve_id'=>$parentId]);
            if(!in_array($child['kind'],['release','return_in'],true))self::unavailable();
            $qty=self::integer($child['qty'],1,self::UINT_MAX);
            if($qty>$group['qty']-$credited)self::unavailable();$credited+=$qty;
            if($child['kind']==='release'){
                if($released)self::unavailable();$released=true;
                self::stored($child,['idem_key'=>'release:'.$orderId.':'.$parentId,'qty'=>$group['qty'],
                    'order_item_id'=>null,'refund_id'=>null]);
                if(!($child['actor_type']==='customer'&&(string)$child['actor_id']===(string)$uid)
                    &&!($child['actor_type']==='system'&&$child['actor_id']===null))self::unavailable();
                self::movementDigest($child,$this->releaseAction($uid,$merId,$orderId,$parentId,$group));
                self::releaseArithmetic($child);
            }
            // return_in has no approved writer/action yet: retain its real full
            // row and budget, without claiming a release digest proves a refund.
            $byChild[$childId]=$child;
        }
        return $byChild;
    }

    private static function applicationAllCredits(array $children,array $byChild):array
    {
        if(count($children)!==count($byChild))self::unavailable();
        foreach($children as&$child){
            $child=self::paymentMovement($child);
            if(!isset($byChild[$child['id']])||$byChild[$child['id']]!==$child)self::unavailable();
        }unset($child);
        return $children;
    }

    private static function applicationChildOrders(array $childOrders,int $orderId):array
    {
        foreach($childOrders as$child){
            self::integer($child['id'],1,self::INT_MAX);self::stored($child,['pid'=>$orderId]);
            self::integer($child['uid'],0,self::INT_MAX);
            if($child['mer_id']!==null)self::integer($child['mer_id'],0,self::UINT_MAX);
            if($child['marketplace_trade_version']!==null)self::integer($child['marketplace_trade_version'],0,255);
            self::text($child['order_id'],32,true);self::integer($child['paid'],0,1);self::integer($child['is_cancel'],0,1);
            self::integer($child['status'],-128,127);self::integer($child['refund_status'],0,255);
        }
        return $childOrders;
    }

    private static function applicationResult(int $uid,int $orderId,int $merId,string $orderNo,array $items,array $selection,
        array $reserves,array $children,array $childOrders):array
    {
        return ['root'=>['id'=>$orderId,'uid'=>$uid,'mer_id'=>$merId,'order_no'=>$orderNo],
            'items'=>$items,'selection'=>$selection,'reserves'=>$reserves,'children'=>$children,'child_orders'=>$childOrders];
    }

    /** Validate every stored movement field; only U64 references are normalized. */
    private static function paymentMovement(array $row): array
    {
        self::keys($row,self::MOVEMENT_FIELDS);$row['id']=self::bigint($row['id']);
        if($row['parent_reserve_id']!==null)$row['parent_reserve_id']=self::bigint($row['parent_reserve_id']);
        foreach(['order_item_id','refund_id'] as$field)
            if($row[$field]!==null)self::integer($row[$field],1,self::INT_MAX);
        self::integer($row['mer_id'],1,self::UINT_MAX);self::integer($row['order_id'],1,self::INT_MAX);
        self::integer($row['product_id'],1,self::ROOT_MAX);self::integer($row['sku_id'],1,self::INT_MAX);
        self::integer($row['sku_type'],0,0);self::unique($row['sku_unique']);self::integer($row['qty'],1,self::UINT_MAX);
        if(!in_array($row['kind'],['reserve','release','return_in'],true))self::unavailable();
        self::text($row['idem_key'],128,true);self::text($row['actor_type'],32,true);
        if($row['actor_id']!==null)self::integer($row['actor_id'],1,self::UINT_MAX);
        if(!is_string($row['request_digest'])||strlen($row['request_digest'])!==32)self::unavailable();
        foreach(['sku'=>self::UINT_MAX,'root'=>self::ROOT_STOCK_MAX] as$part=>$max)
            foreach(['stock_before','stock_after','sales_before','sales_after'] as$field)self::integer($row[$part.'_'.$field],0,$max);
        self::integer($row['add_time'],1,self::UINT_MAX);return $row;
    }

    /** Real owner/order locators only, read outside the following top-level transaction. */
    public function customerCancellationCandidate(PDO $pdo, int $uid, string $orderNo): ?array
    {
        self::integer($uid,1,self::INT_MAX);self::text($orderNo,32,true);
        return $this->cancellationCandidate($pdo,'`uid` = ? AND `order_id` = ?',[$uid,$orderNo]);
    }

    public function expiryCancellationCandidate(PDO $pdo, int $orderId): ?array
    {
        self::integer($orderId,1,self::INT_MAX);
        return $this->cancellationCandidate($pdo,'`id` = ?',[$orderId]);
    }

    /** Saved deadline/order cursor; no current configuration, merchant state or post-commit filter. */
    public function expiredCancellationCandidates(PDO $pdo, int $deadline, int $orderId, int $now, int $batch): array
    {
        self::integer($deadline,0,self::UINT_MAX);self::integer($orderId,0,self::INT_MAX);
        self::integer($now,1,self::UINT_MAX);self::integer($batch,1,100);
        $rows=$this->postCommitRead($pdo,'SELECT `cancel_at`,`order_id` FROM '.$this->table('marketplace_order_idempotency').
            ' FORCE INDEX (`idx_ms4_cancel`) WHERE `state` = 1 AND `cancel_at` > 0 AND `cancel_at` <= ?'.
            ' AND (`cancel_at` > ? OR (`cancel_at` = ? AND `order_id` > ?)) ORDER BY `cancel_at`,`order_id` LIMIT '.$batch,
            [$now,$deadline,$deadline,$orderId]);
        $out=[];foreach($rows as$row)$out[]=['deadline'=>self::integer($row['cancel_at'],1,self::UINT_MAX),
            'order_id'=>self::integer($row['order_id'],1,self::INT_MAX)];return $out;
    }

    /**
     * UID -> ledger -> order -> raw merchant -> registry/attempts -> roots -> SKUs -> items -> reserves/children.
     * Returns state ready/already_cancelled/not_due/pending plus private actual locked facts.
     * Candidate root/SKU IDs are hints and must exactly match the newly locked saved items.
     */
    public function lockCancellation(PDO $pdo, array $candidate, int $now, bool $expired, ?array $paymentProof = null): array
    {
        $this->transaction($pdo);self::integer($now,1,self::UINT_MAX);
        foreach(['root_ids'=>self::ROOT_MAX,'sku_ids'=>self::INT_MAX] as$field=>$max){
            $ids=$candidate[$field]??null;if(!is_array($ids)||!array_is_list($ids)||!$ids)self::unavailable();
            foreach($ids as$value)self::integer($value,1,$max);
            $sorted=array_values(array_unique($ids,SORT_NUMERIC));sort($sorted,SORT_NUMERIC);
            if($sorted!==$ids)self::unavailable();
        }
        $uid=self::integer($candidate['uid']??null,1,self::INT_MAX);$id=self::integer($candidate['order_id']??null,1,self::INT_MAX);
        $this->lockCustomer($pdo,$uid);
        $ledger=$this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,'`uid` = ? AND `order_id` = ?',[$uid,$id]);
        if($ledger===null)self::unavailable();$saved=$this->committedOrder($pdo,$ledger);
        $order=$this->one($pdo,'store_order',self::CANCEL_FIELDS,'`id` = ? AND `uid` = ? AND `mer_id` = ?',[$id,$uid,$ledger['mer_id']]);
        if($order===null||$order['order_id']!==$candidate['order_no'])self::unavailable();
        $merId=self::integer($ledger['mer_id'],1,self::UINT_MAX);
        if(self::integer($candidate['mer_id']??null,1,self::UINT_MAX)!==$merId)self::unavailable();
        if($this->one($pdo,'marketplace_merchant',['mer_id'],'`mer_id` = ?',[$merId])===null)self::unavailable();
        // Payment's complete common-lock set is acquired before any inventory row.
        // A no-proof read only captures facts for a later, transaction-free close.
        $payment=$paymentProof===null
            ?$this->payments->lockPayment($pdo,$uid,$id,$order['order_id'])
            :$this->payments->assertCancellationProof($pdo,$uid,$id,$order['order_id'],$paymentProof);
        $result=['state'=>'pending','candidate'=>$candidate,'ledger'=>$ledger,'order'=>$order,'selection'=>[],'reserves'=>[],
            'payment'=>$payment,'payment_stage'=>null];
        if(self::integer($order['paid'],0,1)!==0)return $result;
        $cancelled=self::integer($order['is_cancel'],0,1)===1;
        $deadline=self::integer($ledger['cancel_at'],0,self::UINT_MAX);
        if($expired&&!$cancelled&&($deadline===0||$deadline>$now)){$result['state']='not_due';return $result;}
        foreach($candidate['root_ids'] as$rootId){
            self::integer($rootId,1,self::ROOT_MAX);
            if($this->one($pdo,'store_product',['id','mer_id','stock','sales'],'`id` = ? AND `mer_id` = ?',[$rootId,$merId])===null)self::unavailable();
        }
        $lockedSkus=[];foreach($candidate['sku_ids'] as$skuId){
            self::integer($skuId,1,self::INT_MAX);
            $sku=$this->one($pdo,'store_product_attr_value',['id','product_id','type','unique','stock','sales'],'`id` = ?',[$skuId]);
            if($sku===null||!in_array(self::integer($sku['product_id'],1,self::ROOT_MAX),$candidate['root_ids'],true)
                ||self::integer($sku['type'],0,0)!==0)self::unavailable();
            $lockedSkus[$skuId]=$sku;
        }
        $selection=$this->postCommitItems($pdo,$ledger,$saved);$roots=$skus=$groups=[];
        foreach($selection as$line){
            self::stored($lockedSkus[$line['sku_id']]??[],['product_id'=>$line['product_id'],'type'=>0,'unique'=>$line['unique']]);
            $roots[$line['product_id']]=$line['product_id'];$skus[$line['sku_id']]=$line['sku_id'];
            $parent=self::bigint($line['cart_info']['marketplace']['reserve_id']);
            if(!isset($groups[$parent]))$groups[$parent]=['product_id'=>$line['product_id'],'sku_id'=>$line['sku_id'],
                'unique'=>$line['unique'],'qty'=>0,'cart_refs'=>[]];
            if($groups[$parent]['product_id']!==$line['product_id']||$groups[$parent]['sku_id']!==$line['sku_id']
                ||$groups[$parent]['unique']!==$line['unique'])self::unavailable();
            $groups[$parent]['qty']+=$line['qty'];self::integer($groups[$parent]['qty'],1,self::UINT_MAX);
            $groups[$parent]['cart_refs'][]=$line['cart_ref'];
        }
        sort($roots,SORT_NUMERIC);sort($skus,SORT_NUMERIC);
        if($roots!==$candidate['root_ids']||$skus!==$candidate['sku_ids'])self::unavailable();
        $items=$this->rows($pdo,'store_order_cart_info',self::ITEM_FIELDS,'`oid` = ?',[$id],'`id` ASC');
        foreach($items as$item){if((string)$item['split_status']!=='0'||(string)$item['refund_num']!=='0'
            ||(string)$item['surplus_num']!==(string)$item['cart_num'])return $result;}
        foreach(['status','refund_status','pid','is_gift'] as$field)if((string)$order[$field]!=='0')return $result;
        if((string)$order['shipping_type']!=='1'||$order['delivery_type']!==''||$order['delivery_id']!==''||$order['delivery_name']!=='')return $result;
        $parents=$this->rows($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,
            '`order_id` = ? AND `kind` = ?',[$id,'reserve'],'`id` ASC');
        if(count($parents)!==count($groups))self::unavailable();$reserves=[];$released=0;$first=null;$childIds=[];
        foreach($parents as$parent){
            $parentId=self::bigint($parent['id']);$group=$groups[$parentId]??null;if($group===null)self::unavailable();
            self::stored($parent,['mer_id'=>$merId,'order_id'=>$id,'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],
                'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],'kind'=>'reserve','parent_reserve_id'=>null,
                'order_item_id'=>null,'refund_id'=>null,'actor_type'=>'customer','actor_id'=>$uid,'idem_key'=>'reserve:'.$id.':'.$group['sku_id']]);
            $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$merId,'order_id'=>$id,'product_id'=>$group['product_id'],
                'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],'kind'=>'reserve','cart_refs'=>$group['cart_refs']];
            self::movementDigest($parent,$action);self::reserveArithmetic($parent);
            $children=$this->rows($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,'`parent_reserve_id` = ?',[$parentId],'`id` ASC');
            $credited=0;$release=null;
            foreach($children as$child){
                $childIds[self::bigint($child['id'])]=true;
                self::stored($child,['mer_id'=>$merId,'order_id'=>$id,'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],
                    'sku_type'=>0,'sku_unique'=>$group['unique'],'parent_reserve_id'=>$parentId]);
                if(!in_array($child['kind'],['release','return_in'],true))self::unavailable();
                $qty=self::integer($child['qty'],1,self::UINT_MAX);
                if($qty>$group['qty']-$credited)self::unavailable();$credited+=$qty;
                if($child['kind']==='release'){
                    $expected=$this->releaseAction($uid,$merId,$id,$parentId,$group);
                    self::stored($child,['idem_key'=>'release:'.$id.':'.$parentId,'qty'=>$group['qty'],
                        'order_item_id'=>null,'refund_id'=>null]);self::movementDigest($child,$expected);self::releaseArithmetic($child);
                    if($release!==null)self::unavailable();$release=$child;
                }
            }
            if($children!==[]){
                if(count($children)!==1||$release===null||$credited!==$group['qty'])return $result;
                $actor=$release['actor_type'];$actorId=$release['actor_id'];
                if(!($actor==='customer'&&(string)$actorId===(string)$uid)&&!($actor==='system'&&$actorId===null))self::unavailable();
                $stamp=[self::integer($release['add_time'],1,self::UINT_MAX),$actor,$actorId===null?null:(string)$actorId];
                if($first!==null&&$first!==$stamp)self::unavailable();$first=$stamp;++$released;
            }
            $reserves[]=$parent+['release_group'=>$group];
        }
        $allChildren=$this->rows($pdo,'marketplace_stock_movement',['id'],
            '`order_id` = ? AND `kind` IN (?,?)',[$id,'release','return_in'],'`id` ASC');
        if(count($allChildren)!==count($childIds))self::unavailable();
        foreach($allChildren as$child)if(!isset($childIds[self::bigint($child['id'])]))self::unavailable();
        if($cancelled){
            if($released!==count($parents)||$first===null)return $result;
            $statuses=$this->rows($pdo,'store_order_status',self::STATUS_FIELDS,'`oid` = ? AND `change_type` = ?',[$id,self::CANCEL_STATUS],'',2);
            if(count($statuses)!==1)return $result;
            self::stored($statuses[0],['oid'=>$id,'change_type'=>self::CANCEL_STATUS,'change_message'=>'订单取消','change_time'=>$first[0]]);
            if($paymentProof===null){$result['payment_stage']='close';return $result;}
            $result['state']='already_cancelled';return $result;
        }
        if($released!==0)return $result;
        $result['selection']=$selection;$result['reserves']=$reserves;
        if($paymentProof===null)$result['payment_stage']='close';else $result['state']='ready';
        return $result;
    }

    /** Independently recheck the same durable proof before the original stock writes. */
    public function releaseCancellation(PDO $pdo, array $locked, int $now, string $actorType, ?int $actorId, array $paymentProof): array
    {
        $current=$this->lockCancellation($pdo,$locked['candidate'],$now,$actorType==='system',$paymentProof);
        $order=$current['order'];
        $uid=self::integer($order['uid'],1,self::INT_MAX);$merId=self::integer($order['mer_id'],1,self::UINT_MAX);$id=self::integer($order['id'],1,self::INT_MAX);
        if(!($actorType==='customer'&&$actorId===$uid)&&!($actorType==='system'&&$actorId===null))self::unavailable();
        if($current['state']!=='ready')return $current;
        $reserves=$current['reserves'];usort($reserves,static fn(array $a,array $b):int=>(int)$a['sku_id']<=>(int)$b['sku_id']);
        foreach($reserves as$parent){
            $parentId=self::bigint($parent['id']);$group=$parent['release_group'];$qty=$group['qty'];
            $root=$this->one($pdo,'store_product',['id','mer_id','stock','sales'],'`id` = ? AND `mer_id` = ?',[$group['product_id'],$merId]);
            $sku=$this->one($pdo,'store_product_attr_value',['id','product_id','type','unique','stock','sales'],'`id` = ?',[$group['sku_id']]);
            if($root===null||$sku===null)self::unavailable();self::stored($sku,['product_id'=>$group['product_id'],'type'=>0,'unique'=>$group['unique']]);
            $ss=self::integer($sku['stock'],0,self::UINT_MAX);$sa=self::integer($sku['sales'],0,self::UINT_MAX);
            $rs=self::integer($root['stock'],0,self::ROOT_STOCK_MAX);$ra=self::integer($root['sales'],0,self::ROOT_STOCK_MAX);
            if($qty>self::UINT_MAX-$ss||$qty>self::ROOT_STOCK_MAX-$rs||$qty>$sa||$qty>$ra)self::unavailable();
            $n=$this->execute($pdo,'UPDATE '.$this->table('store_product_attr_value').
                ' SET `stock` = ?, `sales` = ? WHERE `id` = ? AND `product_id` = ? AND `type` = 0 AND BINARY `unique` = ? AND `stock` = ? AND `sales` = ?',
                [$ss+$qty,$sa-$qty,$group['sku_id'],$group['product_id'],$group['unique'],$ss,$sa])->rowCount();
            if($n!==1)self::unavailable();
            $n=$this->execute($pdo,'UPDATE '.$this->table('store_product').
                ' SET `stock` = ?, `sales` = ? WHERE `id` = ? AND `mer_id` = ? AND `stock` = ? AND `sales` = ?',
                [$rs+$qty,$ra-$qty,$group['product_id'],$merId,$rs,$ra])->rowCount();if($n!==1)self::unavailable();
            $afterSku=$this->one($pdo,'store_product_attr_value',['id','stock','sales'],'`id` = ?',[$group['sku_id']]);
            $afterRoot=$this->one($pdo,'store_product',['id','stock','sales'],'`id` = ?',[$group['product_id']]);
            if($afterSku===null||$afterRoot===null)self::unavailable();
            self::stored($afterSku,['stock'=>$ss+$qty,'sales'=>$sa-$qty]);self::stored($afterRoot,['stock'=>$rs+$qty,'sales'=>$ra-$qty]);
            $action=$this->releaseAction($uid,$merId,$id,$parentId,$group);
            $movement=['mer_id'=>$merId,'order_id'=>$id,'product_id'=>$group['product_id'],'sku_id'=>$group['sku_id'],
                'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$qty,'kind'=>'release','idem_key'=>'release:'.$id.':'.$parentId,
                'parent_reserve_id'=>$parentId,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>$actorType,'actor_id'=>$actorId,
                'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($action),true),'sku_stock_before'=>$ss,'sku_stock_after'=>$ss+$qty,
                'sku_sales_before'=>$sa,'sku_sales_after'=>$sa-$qty,'root_stock_before'=>$rs,'root_stock_after'=>$rs+$qty,
                'root_sales_before'=>$ra,'root_sales_after'=>$ra-$qty,'add_time'=>$now];
            $movementId=$this->insert($pdo,'marketplace_stock_movement',$movement,'18446744073709551615');
            $read=$this->one($pdo,'marketplace_stock_movement',self::MOVEMENT_FIELDS,'`id` = ?',[$movementId]);
            if($read===null)self::unavailable();self::stored($read,['id'=>$movementId]+$movement);
        }
        if($this->rows($pdo,'store_order_status',self::STATUS_FIELDS,'`oid` = ? AND `change_type` = ?',[$id,self::CANCEL_STATUS],'',2))self::unavailable();
        $status=['oid'=>$id,'change_type'=>self::CANCEL_STATUS,'change_message'=>'订单取消','change_time'=>$now];
        $this->insert($pdo,'store_order_status',$status,null);
        $n=$this->execute($pdo,'UPDATE '.$this->table('store_order').
            ' SET `is_cancel` = 1 WHERE `id` = ? AND `uid` = ? AND `mer_id` = ? AND `marketplace_trade_version` = 1'.
            ' AND `paid` = 0 AND `is_cancel` = 0 AND `status` = 0 AND `refund_status` = 0',[$id,$uid,$merId])->rowCount();
        if($n!==1)self::unavailable();
        $read=$this->one($pdo,'store_order',self::CANCEL_FIELDS,'`id` = ?',[$id]);
        if($read===null)self::unavailable();$expected=$order;$expected['is_cancel']=1;self::stored($read,$expected);
        $statuses=$this->rows($pdo,'store_order_status',self::STATUS_FIELDS,'`oid` = ? AND `change_type` = ?',[$id,self::CANCEL_STATUS],'',2);
        if(count($statuses)!==1)self::unavailable();self::stored($statuses[0],$status);
        $current['state']='cancelled';$current['order']=$read;return $current;
    }

    /** Read-only resolution serialized with create on the same actual UID, never a new claim. */
    public function lookupConfirmation(PDO $pdo, int $uid, string $key): array
    {
        $this->transaction($pdo); self::integer($uid,1,self::INT_MAX);
        $key=MarketplaceOrderInput::confirmationKey($key); self::text($key,32,true);
        $this->lockCustomer($pdo,$uid);
        $confirmation=hash('sha256',$key,true);
        $row=$this->one($pdo,'marketplace_order_idempotency',self::IDEM_FIELDS,
            '`uid` = ? AND `confirmation_ref` = ?',[$uid,$confirmation]);
        if($row===null)return ['state'=>'not_found','key'=>$key];
        if(self::integer($row['uid'],1,self::INT_MAX)!==$uid || !is_string($row['confirmation_ref'])
            || !hash_equals($confirmation,$row['confirmation_ref']))self::unavailable();
        if(self::integer($row['state'],0,1)===0){
            if($row['order_id']!==null || self::integer($row['post_commit_done'],0,1)!==0)self::unavailable();
            return ['state'=>'processing','key'=>$key];
        }
        $order=$this->committedOrder($pdo,$row);
        if($order['unique']!==$key)self::unavailable();
        return ['state'=>'created','key'=>$key,'orderId'=>$order['order_id']];
    }

    private function cancellationCandidate(PDO $pdo, string $where, array $values): ?array
    {
        $rows=$this->postCommitRead($pdo,'SELECT `id`,`uid`,`mer_id`,`order_id`,`marketplace_trade_version` FROM '.
            $this->table('store_order').' WHERE '.$where.' LIMIT 2',$values);
        if(count($rows)>1)self::unavailable();if(!$rows)return null;$order=$rows[0];
        self::integer($order['marketplace_trade_version'],1,1);$id=self::integer($order['id'],1,self::INT_MAX);
        $uid=self::integer($order['uid'],1,self::INT_MAX);$merId=self::integer($order['mer_id'],1,self::UINT_MAX);
        $items=$this->postCommitRead($pdo,'SELECT `product_id`,`cart_info` FROM '.$this->table('store_order_cart_info').
            ' WHERE `oid` = ? ORDER BY `id`',[$id]);if(!$items)self::unavailable();$roots=$skus=[];
        foreach($items as$item){
            $rootId=self::integer($item['product_id'],1,self::ROOT_MAX);$cart=self::decode($item['cart_info']);
            if(!is_array($cart['marketplace']??null))self::unavailable();
            $skuId=self::integer($cart['marketplace']['sku_id']??null,1,self::INT_MAX);
            $roots[$rootId]=$rootId;$skus[$skuId]=$skuId;
        }
        sort($roots,SORT_NUMERIC);sort($skus,SORT_NUMERIC);
        return ['uid'=>$uid,'mer_id'=>$merId,'order_id'=>$id,'order_no'=>self::text($order['order_id'],32,true),
            'root_ids'=>$roots,'sku_ids'=>$skus];
    }

    private function releaseAction(int $uid,int $merId,int $id,string $parentId,array $group):array
    {
        return ['contract_version'=>1,'uid'=>$uid,'mer_id'=>$merId,'order_id'=>$id,'product_id'=>$group['product_id'],
            'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],
            'kind'=>'release','parent_reserve_id'=>$parentId];
    }

    private static function movementDigest(array $movement,array $action):void
    {
        if(!is_string($movement['request_digest'])||strlen($movement['request_digest'])!==32
            ||!hash_equals($movement['request_digest'],hash('sha256',MarketplaceOrderInput::canonical($action),true)))self::unavailable();
    }

    private static function releaseArithmetic(array $movement):void
    {
        $qty=self::integer($movement['qty'],1,self::UINT_MAX);
        foreach(['sku'=>self::UINT_MAX,'root'=>self::ROOT_STOCK_MAX] as$part=>$max){
            $stock=self::integer($movement[$part.'_stock_before'],0,$max);$sales=self::integer($movement[$part.'_sales_before'],0,$max);
            if($qty>$max-$stock||$qty>$sales)self::unavailable();
            self::stored($movement,[$part.'_stock_after'=>$stock+$qty,$part.'_sales_after'=>$sales-$qty]);
        }
    }

    private static function reserveArithmetic(array $movement):void
    {
        $qty=self::integer($movement['qty'],1,self::UINT_MAX);
        foreach(['sku'=>self::UINT_MAX,'root'=>self::ROOT_STOCK_MAX] as$part=>$max){
            $stock=self::integer($movement[$part.'_stock_before'],0,$max);$sales=self::integer($movement[$part.'_sales_before'],0,$max);
            if($qty>$stock||$qty>$max-$sales)self::unavailable();
            self::stored($movement,[$part.'_stock_after'=>$stock-$qty,$part.'_sales_after'=>$sales+$qty]);
        }
    }

    /** Saved item facts only, including exact opaque refs and original physical SKU lineage. */
    private function postCommitItems(PDO $pdo, array $ledger, array $order): array
    {
        $identity=self::postCommitIdentity($ledger,$order);[$uid,$merId,$orderId,$refs]=$identity;
        $rows=$this->rows($pdo,'store_order_cart_info',self::ITEM_FIELDS,'`oid` = ?',[$orderId],'`id` ASC');
        return self::postCommitItemsFromRows($ledger,$order,$rows,$identity);
    }

    private static function postCommitIdentity(array $ledger,array $order):array
    {
        $uid=self::integer($order['uid'],1,self::INT_MAX);$merId=self::integer($order['mer_id'],1,self::UINT_MAX);
        $orderId=self::integer($order['id'],1,self::INT_MAX);
        $refs=self::references(self::decode($ledger['cart_refs']),$ledger['source_kind']);
        return [$uid,$merId,$orderId,$refs];
    }

    private static function postCommitItemsFromRows(array $ledger,array $order,array $rows,array $identity):array
    {
        [$uid,$merId,$orderId,$refs]=$identity;
        if(count($rows)!==count($refs))self::unavailable();$byRef=[];$total=0;
        foreach($rows as$item){
            self::stored($item,['oid'=>$orderId,'uid'=>$uid,'mer_id'=>$merId]);
            $itemId=self::integer($item['id'],1,self::INT_MAX);
            $ref=MarketplaceOrderInput::reference($item['cart_id'],$ledger['source_kind']);
            if(!is_string($item['cart_id'])||$ref!==$item['cart_id']||isset($byRef[$ref]))self::unavailable();
            $productId=self::integer($item['product_id'],1,self::ROOT_MAX);$qty=self::integer($item['cart_num'],1,self::INT_MAX);
            $cart=self::decode($item['cart_info']);$product=$cart['productInfo']??null;$lineage=$cart['marketplace']??null;
            if(!is_array($product)||!is_array($product['attrInfo']??null)||!is_array($lineage)
                ||($cart['id']??null)!==$ref||($lineage['cart_ref']??null)!==$ref)self::unavailable();
            self::stored($cart,['product_id'=>$productId,'cart_num'=>$qty]);
            self::stored($product,['id'=>$productId,'mer_id'=>$merId]);
            $skuId=self::integer($lineage['sku_id']??null,1,self::INT_MAX);
            self::integer($lineage['sku_type']??null,0,0);$unique=self::unique($lineage['sku_unique']??null);
            self::bigint($lineage['reserve_id']??null);
            self::stored($product['attrInfo'],['id'=>$skuId,'product_id'=>$productId,'type'=>0,'unique'=>$unique]);
            $byRef[$ref]=['item_id'=>$itemId,'cart_ref'=>$ref,'product_id'=>$productId,'sku_id'=>$skuId,
                'sku_type'=>0,'unique'=>$unique,'qty'=>$qty,'cart_info'=>$cart];
            $total+=$qty;self::integer($total,1,self::UINT_MAX);
        }
        if($total!==self::integer($order['total_num'],1,self::UINT_MAX))self::unavailable();$selection=[];
        foreach($refs as$ref){if(!isset($byRef[$ref]))self::unavailable();$selection[]=$byRef[$ref];}
        return $selection;
    }

    /** Autocommit reads cannot establish an older consistent snapshot inside the lock transaction. */
    private function postCommitRead(PDO $pdo, string $sql, array $values): array
    {
        $connection=Db::connect();
        if(PHP_INT_SIZE<8||$pdo->inTransaction()||$connection->getPdo()!==$pdo
            ||$connection->getConfig('prefix')!==$this->prefix||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES))self::unavailable();
        try{
            $statement=$pdo->prepare($sql);if(!$statement instanceof PDOStatement)self::unavailable();
            foreach(array_values($values) as$i=>$value){
                if(!is_string($value)&&!is_int($value))self::unavailable();
                if(!$statement->bindValue($i+1,(string)$value,PDO::PARAM_STR))self::unavailable();
            }
            if(!$statement->execute())self::unavailable();$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $error){self::unavailable();}
        if(!is_array($rows))self::unavailable();return $rows;
    }

    private function lockCustomer(PDO $pdo, int $uid): void
    {
        $user=$this->one($pdo,'user',['uid'],'`uid` = ?',[$uid]);
        self::customerFacts($user,$uid);
    }

    private static function customerFacts(?array $user,int $uid):void
    {
        if($user===null || self::integer($user['uid'],1,self::INT_MAX)!==$uid)self::conflict();
    }

    /** Preserve the saved-order identity checks shared by replay and the owner-only lookup. */
    private function committedOrder(PDO $pdo, array $row): array
    {
        $refs=self::committedReferences($row);
        $order=$this->order($pdo,self::integer($row['uid'],1,self::INT_MAX),self::integer($row['mer_id'],1,self::UINT_MAX),
            self::integer($row['order_id'],1,self::INT_MAX),false);
        return self::committedOrderReferences($row,$order,$refs);
    }

    private static function committedReferences(array $row):array
    {
        if(self::integer($row['state'],0,1)!==1 || !is_string($row['confirmation_ref'])
            || strlen($row['confirmation_ref'])!==32)self::unavailable();
        $refs=self::references(self::decode($row['cart_refs']),$row['source_kind']);
        return $refs;
    }

    private static function committedOrderReferences(array $row,array $order,array $refs):array
    {
        if(!hash_equals($row['confirmation_ref'],hash('sha256',$order['unique'],true))
            || self::decode($order['cart_id'])!==$refs)self::unavailable();
        return $order;
    }

    private function replay(PDO $pdo, array $row, array $request, string $digest, string $confirmation): array
    {
        if (self::integer($row['uid'],1,self::INT_MAX) !== $request['uid']
            || self::integer($row['mer_id'],1,self::UINT_MAX) !== $request['mer_id']
            || !is_string($row['request_digest']) || !hash_equals($row['request_digest'],$digest)
            || !is_string($row['confirmation_ref']) || !hash_equals($row['confirmation_ref'],$confirmation)
            || $row['source_kind'] !== $request['source_kind'] || self::decode($row['cart_refs']) !== $request['cart_refs']) self::conflict();
        $order = $this->committedOrder($pdo,$row);
        return ['claimed'=>false,'idem_key'=>$row['idem_key'],'request_digest'=>$digest,'confirmation_ref'=>$confirmation,
            'request'=>$request,'order'=>$order,'cancel_at'=>self::integer($row['cancel_at'],0,self::UINT_MAX),
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
        return self::savedOrderFacts($row,$creating);
    }

    private static function savedOrderFacts(?array $row,bool $creating):array
    {
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
