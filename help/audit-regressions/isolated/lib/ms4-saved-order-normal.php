<?php
declare(strict_types=1);

use app\dao\order\StoreOrderDao as SavedNormalOrderDao;
use app\services\order\StoreOrderEconomizeServices as SavedNormalEconomizeServices;
use app\services\order\StoreOrderRefundServices as SavedNormalRefundServices;
use app\services\order\StoreOrderServices as SavedNormalOrderServices;
use app\services\order\StoreOrderWapServices as SavedNormalWapServices;
use think\facade\Db as SavedNormalDb;

/** Require exact saved decimal text, without using the projection under test as an oracle. */
function m4s_money(mixed $value): string
{
    m4o_expect(is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D',$value) === 1,'SAVED_READ_DECIMAL_STRING');
    return $value;
}

/** Only compare public leaf facts; the expected row is independently read native saved JSON. */
function m4s_carts(array $actual,array $expected): void
{
    m4o_expect(array_is_list($actual) && count($actual) === count($expected),'SAVED_READ_CART_COUNT');
    $seen=[];$goods=$postage='0.00';
    foreach($actual as $cart){
        m4o_expect(is_array($cart) && is_string($cart['id'] ?? null) && isset($expected[$cart['id']])
            && !isset($seen[$cart['id']]),'SAVED_READ_EXACT_CART_REFERENCE');
        $ref=$cart['id'];$seen[$ref]=true;$stored=$expected[$ref];$raw=$stored['cart'];$item=$stored['item'];
        m4o_expect(array_diff(array_keys($cart),['id','type','product_id','product_attr_unique','cart_num','unique',
            'surplus_num','refund_num','surplus_refund_num','is_reply','is_valid','price_type','truePrice','vip_truePrice',
            'vip_sum_truePrice','sum_price','sum_true_price','postage_price','origin_postage_price','productInfo']) === [],
            'SAVED_READ_CART_PUBLIC_KEYS');
        foreach(['product_id','product_attr_unique','cart_num','price_type'] as $field)
            m4o_expect(array_key_exists($field,$cart) && (string)$cart[$field] === (string)$raw[$field],'SAVED_READ_CART_SAVED_IDENTITY');
        foreach(['surplus_num','refund_num'] as $field)if(array_key_exists($field,$cart))
            m4o_expect((string)$cart[$field] === (string)$item[$field],'SAVED_READ_CART_SAVED_QUANTITY');
        foreach(['truePrice','vip_truePrice','sum_price','sum_true_price','postage_price','origin_postage_price'] as $field)
            m4o_expect(m4s_money($cart[$field] ?? null) === m4s_money($raw[$field] ?? null),'SAVED_READ_CART_SAVED_MONEY');
        if(array_key_exists('vip_sum_truePrice',$cart))m4o_expect(m4s_money($cart['vip_sum_truePrice'])
            === bcmul(m4s_money($raw['vip_truePrice']),(string)$item['cart_num'],2),'SAVED_READ_CART_SAVED_MEMBER_TOTAL');
        m4o_expect($cart['origin_postage_price'] === $cart['postage_price'],'SAVED_READ_ACTUAL_CHARGED_LINE_POSTAGE');
        $product=$cart['productInfo'] ?? null;$rawProduct=$raw['productInfo'];
        m4o_expect(is_array($product) && array_diff(array_keys($product),['id','mer_id','store_name','image','unit_name',
            'is_vip','is_virtual','virtual_type','spec_type','store_mention','price','ot_price','vip_price','attrInfo']) === [],
            'SAVED_READ_PRODUCT_PUBLIC_KEYS');
        foreach(['id','mer_id','store_name','image','unit_name'] as $field)
            m4o_expect(array_key_exists($field,$product) && (string)$product[$field] === (string)$rawProduct[$field],
                'SAVED_READ_PRODUCT_SAVED_FACT');
        $sku=$product['attrInfo'] ?? null;$rawSku=$rawProduct['attrInfo'];
        m4o_expect(is_array($sku) && array_diff(array_keys($sku),['id','product_id','type','unique','suk','image','price','ot_price','vip_price']) === [],
            'SAVED_READ_SKU_PUBLIC_KEYS');
        foreach(['id','product_id','type','unique','suk','image'] as $field)
            m4o_expect(array_key_exists($field,$sku) && (string)$sku[$field] === (string)$rawSku[$field],'SAVED_READ_SKU_SAVED_FACT');
        foreach([[$product,$rawProduct],[$sku,$rawSku]] as [$public,$native])foreach(['price','ot_price','vip_price'] as $field)
            if(array_key_exists($field,$public))m4o_expect(m4s_money($public[$field]) === m4s_money($native[$field]),'SAVED_READ_PUBLIC_BASE_PRICE');
        $goods=bcadd($goods,$cart['sum_true_price'],2);
        // These are actual charged whole-line amounts: never multiply them by cart_num again.
        $postage=bcadd($postage,$cart['postage_price'],2);
    }
    m4o_expect(count($seen) === count($expected),'SAVED_READ_COMPLETE_CART_SELECTION');
    $nativeGoods=$nativePostage='0.00';
    foreach($expected as $row){$nativeGoods=bcadd($nativeGoods,$row['cart']['sum_true_price'],2);
        $nativePostage=bcadd($nativePostage,$row['cart']['postage_price'],2);}
    m4o_expect($goods === $nativeGoods && $postage === $nativePostage,'SAVED_READ_CART_WHOLE_LINE_TOTALS');
}

/** Recursively check actual public containers; ordinary own-customer invoice text is not emitted. */
function m4s_no_private(array $row): void
{
    $private=['cost','costPrice','stock','sales','marketplace','reserve_id','parent_reserve_id','actor_id','actor_type',
        'request_digest','confirmation_ref','idem_key','post_commit_done','one_brokerage','two_brokerage','staff_brokerage',
        'agent_brokerage','division_brokerage','weight','volume','user','spread','division','gift_user_info','pay_uid','gift_uid'];
    foreach($row as $key=>$value){
        m4o_expect(!in_array((string)$key,$private,true),'SAVED_READ_NO_PRIVATE_FIELD');
        if(is_array($value))m4s_no_private($value);
    }
}

function m4s_order_row(array $actual,array $expected,string $scope,bool $detail=false): void
{
    $order=$expected['order'];
    m4o_expect(($actual['marketplace_trade_version'] ?? null) === 1,'SAVED_READ_REAL_SAVED_VERSION');
    foreach(['id','order_id','mer_id','total_num','paid','status'] as $field)
        m4o_expect(array_key_exists($field,$actual) && (string)$actual[$field] === (string)$order[$field],'SAVED_READ_ORDER_SAVED_IDENTITY');
    foreach(['total_price','pay_price','pay_postage','total_postage'] as $field)
        m4o_expect(m4s_money($actual[$field] ?? null) === m4s_money($order[$field]),'SAVED_READ_ORDER_SAVED_MONEY');
    foreach(['merchant_name_snapshot','merchant_after_sale_contact_snapshot'] as $field)
        m4o_expect(($actual[$field] ?? null) === $order[$field],'SAVED_READ_SHOP_SAVED_TEXT');
    if($scope==='platform')m4o_expect((string)($actual['uid'] ?? '') === (string)$order['uid'],'SAVED_READ_PLATFORM_CUSTOMER_ID');
    else m4o_expect(!array_key_exists('uid',$actual),'SAVED_READ_NO_CUSTOMER_ID_ALIAS');
    m4s_no_private($actual);
    if($scope==='customer' || $detail)m4o_expect(is_array($actual['_status'] ?? null)
        && ($actual['_status']['_type'] ?? null) === 0,'SAVED_READ_CUSTOMER_UNPAID_STATUS');
    else m4o_expect(($actual['_status'] ?? null) === 1,'SAVED_READ_STAFF_UNPAID_STATUS');
    $aliases=0;
    foreach(['cartInfo','cart_info','refund_cartInfo'] as $key)if(array_key_exists($key,$actual)){
        m4o_expect(is_array($actual[$key]),'SAVED_READ_CART_ALIAS_ARRAY');m4s_carts($actual[$key],$expected['items']);++$aliases;
    }
    if(array_key_exists('_info',$actual)){
        m4o_expect(is_array($actual['_info']) && array_is_list($actual['_info']),'SAVED_READ_WRAPPER_LIST');$carts=[];
        foreach($actual['_info'] as $wrapper){m4o_expect(is_array($wrapper) && array_keys($wrapper) === ['cart_info']
            && is_array($wrapper['cart_info']),'SAVED_READ_WRAPPER_PUBLIC_KEYS');$carts[]=$wrapper['cart_info'];}
        m4s_carts($carts,$expected['items']);++$aliases;
    }
    m4o_expect($aliases>0,'SAVED_READ_PRESENT_CART_ALIASES');
    if($scope==='customer' || $detail)m4o_expect(array_key_exists('cartInfo',$actual),'SAVED_READ_CUSTOMER_CART_CONTRACT');
    else m4o_expect(array_key_exists('_info',$actual),'SAVED_READ_STAFF_CART_CONTRACT');
    if(array_key_exists('cart_id',$actual))m4o_expect($actual['cart_id'] === $expected['refs'],'SAVED_READ_ORDER_REFERENCE_STRINGS');
    if(!$detail)m4o_expect(!array_key_exists('custom_form',$actual) && !array_key_exists('invoice',$actual),'SAVED_READ_LIST_NO_DETAIL_PACK');
}

function m4s_detail(array $actual,array $expected): void
{
    m4s_order_row($actual,$expected,'customer',true);
    m4o_expect(isset($actual['refund_cartInfo']) && !array_key_exists('_info',$actual),'SAVED_READ_DETAIL_ALIAS_CONTRACT');
    $forms=$actual['custom_form'] ?? null;$native=$expected['forms'];
    m4o_expect(is_array($forms) && array_is_list($forms) && count($forms) === count($native) && count($forms) === 2,
        'SAVED_READ_FORM_GROUP_COUNT');
    foreach($forms as $index=>$group){
        $stored=$native[$index];
        m4o_expect(is_array($group) && array_diff(array_keys($group),['product_id','product_name','definition_sha256','fields']) === [],
            'SAVED_READ_FORM_PUBLIC_KEYS');
        foreach(['product_id','product_name','definition_sha256'] as $field)
            m4o_expect(($group[$field] ?? null) === $stored[$field],'SAVED_READ_FORM_SAVED_DEFINITION');
        // This hash belongs to the original definition. Filled answers are never rehashed.
        m4o_expect(is_string($group['definition_sha256']) && preg_match('/\A[0-9a-f]{64}\z/D',$group['definition_sha256']) === 1,
            'SAVED_READ_ORIGINAL_DEFINITION_HASH');
        m4o_expect(is_array($group['fields'] ?? null) && array_is_list($group['fields'])
            && count($group['fields']) === count($stored['fields']),'SAVED_READ_FORM_FIELD_COUNT');
        foreach($group['fields'] as $fieldIndex=>$field){
            m4o_expect(is_array($field) && array_diff(array_keys($field),['field_index','title','label','status','value']) === [],
                'SAVED_READ_FORM_FIELD_PUBLIC_KEYS');
            foreach(['field_index','title','label','status','value'] as $key)
                m4o_expect(array_key_exists($key,$field) && $field[$key] === $stored['fields'][$fieldIndex][$key],
                    'SAVED_READ_EXACT_SAVED_ANSWER');
        }
    }
    $invoice=$actual['invoice'] ?? null;$stored=$expected['invoice'];
    m4o_expect(is_array($invoice) && $invoice!==[] && array_diff(array_keys($invoice),['id','invoice_id','category','is_invoice',
        'is_pay','header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number','remark',
        'invoice_number','invoice_time','invoice_image','add_time','pay_price','invoice_price']) === [],'SAVED_READ_INVOICE_PUBLIC_KEYS');
    foreach(['id','invoice_id','is_pay','header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number'] as $field)
        m4o_expect(array_key_exists($field,$invoice) && (string)$invoice[$field] === (string)$stored[$field],'SAVED_READ_ACTUAL_INVOICE');
}

/**
 * Actual ordinary internal reads only. No HTTP/authentication context, refund rows or business writes.
 * @param array{persistent:array{source_kind:string,uid:int,order_no:string},temporary:array{source_kind:string,uid:int,order_no:string}} $contexts
 * @return array Fixed saved_reads proof; no raw UID, address, answers, invoice or DTO is returned.
 */
function m4o_saved_order_normal(\think\App $app,PDO $pdo,array $contexts): array
{
    m4o_expect(app() === $app && $pdo !== SavedNormalDb::connect()->getPdo() && !$pdo->inTransaction()
        && !SavedNormalDb::connect()->getPdo()->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql',
        'SAVED_READ_REAL_INDEPENDENT_PDO');
    $kinds=array_keys($contexts);sort($kinds,SORT_STRING);
    m4o_expect($kinds === ['persistent','temporary'],'SAVED_READ_ORIGINAL_TWO_CONTEXTS');
    $before=m4o_facts($pdo);
    m4o_expect(m4o_rows($pdo,'SELECT `id` FROM `eb_store_order_refund` ORDER BY `id`') === [],'SAVED_READ_REAL_REFUNDS_EMPTY');
    $expected=$identities=[];
    foreach(['persistent','temporary'] as $kind){
        $context=$contexts[$kind];
        m4o_expect(is_array($context) && ($context['source_kind'] ?? null) === $kind && is_int($context['uid'] ?? null)
            && $context['uid']>0 && $context['uid']<=2147483647 && is_string($context['order_no'] ?? null)
            && $context['order_no']!=='','SAVED_READ_REAL_CONTEXT_SHAPE');
        $rows=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$context['uid'],$context['order_no']]);
        m4o_expect(count($rows) === 1,'SAVED_READ_NATIVE_ORDER');$order=$rows[0];$id=(int)$order['id'];
        m4o_expect($id>0 && $id<=2147483647 && (int)$order['marketplace_trade_version'] === 1
            && (int)$order['paid'] === 0 && (int)$order['status'] === 0 && !isset($expected[$id]),'SAVED_READ_REAL_CREATED_ORDER');
        $ledger=m4o_rows($pdo,'SELECT `source_kind`,`state` FROM `eb_marketplace_order_idempotency` WHERE `uid` = ? AND `order_id` = ?',[$context['uid'],$id]);
        m4o_expect(count($ledger) === 1 && $ledger[0]['source_kind'] === $kind && (int)$ledger[0]['state'] === 1,'SAVED_READ_REAL_CREATED_SOURCE');
        $refs=json_decode($order['cart_id'],true,32,JSON_THROW_ON_ERROR);$items=[];$goods=$postage='0.00';
        foreach(m4o_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]) as $item){
            $cart=json_decode($item['cart_info'],true,32,JSON_THROW_ON_ERROR);$ref=(string)$item['cart_id'];
            m4o_expect(is_array($cart) && ($cart['id'] ?? null) === $ref && in_array($ref,$refs,true)
                && !isset($items[$ref]) && (int)$item['uid'] === $context['uid'],'SAVED_READ_NATIVE_ITEM_REFERENCE');
            $items[$ref]=['item'=>$item,'cart'=>$cart];$goods=bcadd($goods,m4s_money($cart['sum_true_price']),2);
            $postage=bcadd($postage,m4s_money($cart['postage_price']),2);
        }
        m4o_expect(count($items) === 3 && count($refs) === 3 && $goods === m4s_money($order['total_price'])
            && $postage === m4s_money($order['pay_postage']) && $postage === m4s_money($order['total_postage'])
            && bcadd($goods,$postage,2) === m4s_money($order['pay_price']),'SAVED_READ_NATIVE_WHOLE_LINE_TOTALS');
        $invoices=m4o_rows($pdo,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id]);
        m4o_expect(count($invoices) === 1 && (int)$invoices[0]['uid'] === $context['uid'],'SAVED_READ_NATIVE_INVOICE');
        $forms=json_decode($order['custom_form'],true,32,JSON_THROW_ON_ERROR);
        m4o_expect(is_array($forms) && array_is_list($forms),'SAVED_READ_NATIVE_FORMS');
        $expected[$id]=['order'=>$order,'items'=>$items,'refs'=>$refs,'invoice'=>$invoices[0],'forms'=>$forms];
        $identities[]=[$kind,$context['uid'],$id,$order['order_id'],$order['mer_id']];
    }
    $orders=$app->make(SavedNormalOrderServices::class);$wap=$app->make(SavedNormalWapServices::class);
    $refunds=$app->make(SavedNormalRefundServices::class);$dao=$app->make(SavedNormalOrderDao::class);
    [$page,$limit]=$orders->getPageValue();
    m4o_expect($page === 0 && $limit === 0,'SAVED_READ_REAL_DEFAULT_PAGINATION');
    $ids=array_keys($expected);$where=['ids'=>$ids,'is_del'=>0,'is_system_del'=>0,'pid'=>0];
    $platform=$orders->getOrderList($where,['*'],[],'platform');
    m4o_expect(($platform['count'] ?? null) === 2 && is_array($platform['data'] ?? null)
        && count($platform['data']) === 2,'SAVED_READ_PLATFORM_LIST_COUNT');$platformSeen=[];
    foreach($platform['data'] as $row){$id=(int)($row['id'] ?? 0);
        m4o_expect(isset($expected[$id]) && !isset($platformSeen[$id]),'SAVED_READ_PLATFORM_ACTUAL_ORDER');
        m4s_order_row($row,$expected[$id],'platform');$platformSeen[$id]=true;}
    $customerCount=$mobileCount=$details=0;
    foreach($expected as $id=>$native){
        $uid=(int)$native['order']['uid'];$ownedWhere=['uid'=>$uid,'is_del'=>0,'is_system_del'=>0,'pid'=>0];
        $customer=$orders->getOrderApiList($ownedWhere,['*'],[],'customer',$uid);
        m4o_expect(count($customer) === 1,'SAVED_READ_CUSTOMER_LIST_COUNT');m4s_order_row($customer[0],$native,'customer');
        $customerCount+=count($customer);
        $mobile=$wap->getWapAdminOrderList($where,'mobile',$uid);
        m4o_expect(count($mobile) === 1,'SAVED_READ_MOBILE_LIST_COUNT');m4s_order_row($mobile[0],$native,'mobile');
        $mobileCount+=count($mobile);
        m4o_expect($dao->countVisibleMarketplaceList($where,$uid) === 1,'SAVED_READ_OWN_LIST_REAL_SQL_COUNT');
        $detail=$orders->getUserOrderByKey($app->make(SavedNormalEconomizeServices::class),$native['order']['order_id'],$uid);
        m4s_detail($detail,$native);++$details;
    }
    $sorted=array_values($expected);usort($sorted,static fn(array $a,array $b): int =>
        ((int)$b['order']['add_time'] <=> (int)$a['order']['add_time']) ?: ((int)$b['order']['id'] <=> (int)$a['order']['id']));
    $pageIds=[];
    foreach([1,2] as $index=>$number){
        $rows=$dao->getOrderList($where,['*'],$number,1);
        m4o_expect(count($rows) === 1 && (string)$rows[0]['id'] === (string)$sorted[$index]['order']['id'],
            'SAVED_READ_REAL_DAO_PAGE');$pageIds[]=(int)$rows[0]['id'];
    }
    $pageSet=$pageIds;sort($pageSet,SORT_NUMERIC);$idSet=$ids;sort($idSet,SORT_NUMERIC);
    $daoCount=$dao->count($where,false);
    m4o_expect($pageSet === $idSet && $daoCount === 2,'SAVED_READ_REAL_DAO_PAGES_AND_COUNT');
    foreach(['customer','platform','mobile'] as $scope){
        $viewers=$scope==='platform'?[null]:array_values($contexts);
        foreach($viewers as $context){
            $uid=$context['uid'] ?? 0;$refundWhere=['is_cancel'=>0,'is_del'=>0,'is_system_del'=>0];
            if($scope==='customer')$refundWhere['uid']=$uid;
            $result=$refunds->refundList($refundWhere,$scope,$uid);
            m4o_expect(($result['list'] ?? null) === [] && ($result['count'] ?? null) === 0
                && is_array($result['num'] ?? null) && count($result['num']) === 7,'SAVED_READ_REAL_EMPTY_REFUND_LIST');
            foreach($result['num'] as $bucket)m4o_expect(is_array($bucket) && ($bucket['num'] ?? null) === 0,
                'SAVED_READ_REAL_EMPTY_REFUND_BUCKET');
        }
    }
    m4o_expect(m4o_rows($pdo,'SELECT `id` FROM `eb_store_order_refund` ORDER BY `id`') === []
        && m4o_facts($pdo) === $before,'SAVED_READ_DATABASE_FACTS_UNCHANGED');
    m4o_expect(!$pdo->inTransaction() && !SavedNormalDb::connect()->getPdo()->inTransaction()
        && $app->config->get('marketplace.trade.enabled') === m4o_trade_expected(),'SAVED_READ_FINISHED_ROLE_TRADE_EXPECTATION');
    return ['verified'=>true,'scope'=>'NORMAL_SAVED_ORDER_INTERNAL_READ_ONLY',
        'orders'=>['customer_count'=>$customerCount,'platform_count'=>count($platformSeen),'mobile_count'=>$mobileCount],
        'details'=>$details,'dao_pages'=>count($pageIds),'dao_count'=>$daoCount,
        'refund_empty_scopes'=>['customer','platform','mobile'],'saved_amounts_verified'=>true,'aliases_verified'=>true,
        'forms_verified'=>true,'invoice_verified'=>true,'facts_unchanged'=>true,
        'identity_sha256'=>hash('sha256',serialize($identities))];
}
