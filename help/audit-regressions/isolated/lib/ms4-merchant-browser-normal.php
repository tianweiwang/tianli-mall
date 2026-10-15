<?php
declare(strict_types=1);

use app\services\marketplace\EmployeeAuthServices as MerchantBrowserAuth;
use app\services\marketplace\order\MarketplaceCartSelection as MerchantBrowserSelection;
use app\services\marketplace\order\MarketplaceOrderCreateServices as MerchantBrowserCreate;
use app\services\marketplace\order\MarketplaceOrderInput as MerchantBrowserInput;
use app\services\marketplace\order\MerchantOrderServices as MerchantBrowserOrders;
use app\services\order\StoreCartServices as MerchantBrowserCarts;
use app\services\order\StoreOrderServices as MerchantBrowserConfirm;
use app\services\user\UserServices as MerchantBrowserUsers;
use crmeb\services\CacheService as MerchantBrowserCache;
use think\facade\Db as MerchantBrowserDb;

// The HTTP auditor can load this library without loading the ordinary CLI or initializing an App.
const M4MB_FACT_TABLES = ['eb_store_order','eb_store_order_invoice','eb_store_order_cart_info',
    'eb_marketplace_order_idempotency','eb_marketplace_stock_movement','eb_store_product','eb_store_product_attr_value','eb_store_cart',
    'eb_user','eb_marketplace_merchant','eb_store_product_attr','eb_user_address','eb_user_invoice','eb_system_config',
    'eb_system_user_level','eb_user_level','eb_member_right','eb_store_order_status','eb_store_product_log','eb_system_timer',
    'eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup'];

function m4mb_expect(bool $ok,string $code): void
{
    if(function_exists('m4o_expect')){m4o_expect($ok,$code);return;}
    if(!$ok)throw new RuntimeException($code);
}

function m4mb_query(PDO $pdo,string $sql,array $args=[]): array
{
    $statement=$pdo->prepare($sql);m4mb_expect($statement instanceof PDOStatement,'BROWSER_NATIVE_PREPARE');
    foreach(array_values($args)as$i=>$value)m4mb_expect($statement->bindValue($i+1,$value===null?null:(string)$value,
        $value===null?PDO::PARAM_NULL:PDO::PARAM_STR),'BROWSER_NATIVE_BIND');
    m4mb_expect($statement->execute(),'BROWSER_NATIVE_EXECUTE');return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Identical table closure, row ordering and native serialization to the unchanged ordinary facts. */
function m4mb_sql_rows(PDO $pdo): array
{
    $tables=[];
    foreach(M4MB_FACT_TABLES as$table){
        $order=match($table){'eb_marketplace_order_idempotency'=>'`uid`,`idem_key`',
            'eb_store_order_status'=>'`oid`,`change_type`,`change_message`,`change_time`',
            'eb_user'=>'`uid`','eb_marketplace_merchant'=>'`mer_id`',default=>'`id`'};
        $tables[$table]=m4mb_query($pdo,'SELECT * FROM `'.$table.'` ORDER BY '.$order);
    }
    return $tables;
}

function m4mb_sql_facts(PDO $pdo): array
{
    $facts=[];foreach(m4mb_sql_rows($pdo)as$table=>$rows)
        $facts[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
    return $facts;
}

/** Fixed private destinations only; no credentials, private contents or their digest enter stdout. */
function m4mb_private_new(string $path,array $value): void
{
    m4mb_expect(in_array($path,['/run/audit-http-browser/merchant-actors.json',
        '/run/audit-http-control/sql-baseline.json','/run/audit-http-control/merchant-oracle.json'],true),
        'BROWSER_PRIVATE_FIXED_FILE');
    $parent=dirname($path);m4mb_expect(is_dir($parent)&&!is_link($parent)&&(fileperms($parent)&0777)===0700
        &&!file_exists($path)&&!is_link($path),
        'BROWSER_PRIVATE_CREATE_ONLY');
    $bytes=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    m4mb_expect(strlen($bytes)<=($path==='/run/audit-http-browser/merchant-actors.json'?16384:2097152),
        'BROWSER_PRIVATE_BOUNDED_FILE');
    $stream=fopen($path,'xb');m4mb_expect(is_resource($stream),'BROWSER_PRIVATE_EXCLUSIVE_OPEN');
    try{m4mb_expect(chmod($path,0600)&&fwrite($stream,$bytes)===strlen($bytes)&&fflush($stream),
        'BROWSER_PRIVATE_COMPLETE_WRITE');}finally{fclose($stream);}
    clearstatcache(true,$path);
    m4mb_expect((fileperms($path)&0777)===0600&&fileowner($path)===fileowner($parent)
        &&!is_link($path)&&file_get_contents($path)===$bytes,
        'BROWSER_PRIVATE_MODE_AND_BYTES');
}

/** Every pre-existing row remains byte-equivalent; only the one real configuration value changes. */
function m4mb_original_rows(array $before,array $after,int $configurationId): void
{
    foreach($before as$table=>$rows){
        $remaining=[];foreach($after[$table]as$row){$key=serialize(m4pc_row_text($row));$remaining[$key]=($remaining[$key]??0)+1;}
        foreach($rows as$row){
            if($table==='eb_system_config'&&m4mr_integer($row['id'],1)===$configurationId)$row['value']=json_encode('0',JSON_THROW_ON_ERROR);
            $key=serialize(m4pc_row_text($row));
            m4mb_expect(($remaining[$key]??0)>0,'BROWSER_ORIGINAL_ROW_UNCHANGED');$remaining[$key]--;
        }
    }
}

/** Native fixture inserts only; order rows, payment flags and historic inventory are not fixture inputs. */
function m4mb_fixture(int $merId,string $imageUrl): array
{
    return MerchantBrowserDb::transaction(static function(\think\db\ConnectionInterface $connection)use($merId,$imageUrl):array{
        $pdo=m4o_business_pdo($connection);$now=time();
        $merchant=m4mb_query($pdo,'SELECT * FROM `eb_marketplace_merchant` WHERE `mer_id` = ? FOR UPDATE',[$merId]);
        m4mb_expect(count($merchant)===1&&m4mr_integer($merchant[0]['review_status'],1,1)===1
            &&m4mr_integer($merchant[0]['accept_orders'],1,1)===1&&m4mr_integer($merchant[0]['security_locked'],0,0)===0
            &&$merchant[0]['closed_at']===null,'BROWSER_REAL_OWNED_TRADING_STORE');
        $rows=m4mb_query($pdo,'SELECT * FROM `eb_system_config` WHERE `menu_name` = ? FOR UPDATE',['order_cancel_time']);
        m4mb_expect(count($rows)===1&&$rows[0]['value']===json_encode('0.0000001',JSON_THROW_ON_ERROR),
            'BROWSER_REAL_TINY_CONFIGURATION_BEFORE');$configuration=$rows[0];
        $statement=$pdo->prepare('UPDATE `eb_system_config` SET `value` = ? WHERE `id` = ? AND `menu_name` = ? AND `value` = ?');
        m4mb_expect($statement instanceof PDOStatement,'BROWSER_CONFIGURATION_PREPARE');
        foreach([json_encode('0',JSON_THROW_ON_ERROR),$configuration['id'],'order_cancel_time',$configuration['value']]as$i=>$part)
            m4mb_expect($statement->bindValue($i+1,(string)$part,PDO::PARAM_STR),'BROWSER_CONFIGURATION_BIND');
        m4mb_expect($statement->execute()&&$statement->rowCount()===1,'BROWSER_CONFIGURATION_CONDITIONAL_UPDATE');
        $actual=m4mb_query($pdo,'SELECT * FROM `eb_system_config` WHERE `id` = ?',[$configuration['id']]);
        $expected=$configuration;$expected['value']=json_encode('0',JSON_THROW_ON_ERROR);
        m4mb_expect(count($actual)===1&&m4pc_row_text($actual[0])===m4pc_row_text($expected),'BROWSER_CONFIGURATION_EXACT_READBACK');
        $user=m4o_insert($pdo,'eb_user',['account'=>'ms4-'.getenv('AUDIT_RUN_ID').'-browser','nickname'=>'分页验证顾客',
            'user_type'=>'h5','is_money_level'=>0,'level'=>0,'add_time'=>$now],'uid');
        $uid=m4mr_integer($user['uid'],1);
        $address=m4o_insert($pdo,'eb_user_address',['uid'=>$uid,'real_name'=>'分页验证','phone'=>'00000000000',
            'province'=>'验证省','city'=>'验证市','district'=>'验证区','detail'=>'分页专用验证地址','is_default'=>1,'add_time'=>$now]);
        $forms=[['title'=>'保存资料','label'=>'text','status'=>true,'value'=>''],
            ['title'=>'保存资料','label'=>'number','status'=>false,'value'=>'']];
        $product=m4o_insert($pdo,'eb_store_product',['mer_id'=>$merId,'store_name'=>'普通分页保存商品','unit_name'=>'件',
            'image'=>$imageUrl,'spec_type'=>1,'stock'=>20,'sales'=>0,'price'=>'20.15','cost'=>'7.45','postage'=>'2.67',
            'freight'=>2,'temp_id'=>0,'is_show'=>1,'review_status'=>2,'is_vip'=>0,'is_limit'=>0,'min_qty'=>1,
            'logistics'=>'1','custom_form'=>json_encode($forms,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'add_time'=>$now]);
        $productId=m4mr_integer($product['id'],1,8388607);
        m4o_insert($pdo,'eb_store_product_attr',['product_id'=>$productId,'type'=>0,'attr_name'=>'规格','attr_values'=>'普通']);
        $sku=m4o_insert($pdo,'eb_store_product_attr_value',['product_id'=>$productId,'type'=>0,'unique'=>bin2hex(random_bytes(4)),
            'suk'=>'普通','image'=>$imageUrl,'stock'=>20,'sales'=>0,'price'=>'20.15','cost'=>'7.45','is_show'=>1,
            'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00']);
        m4mr_integer($sku['id'],1);m4mr_integer($address['id'],1,16777215);
        m4mb_expect(m4o_business_pdo($connection)===$pdo,'BROWSER_FIXTURE_SAME_DEFAULT_PDO');
        return compact('user','address','product','sku','configuration');
    });
}

/** One genuine persistent cart is confirmed and committed before the next one is produced. */
function m4mb_create_one(\think\App $app,PDO $pdo,array $fixture,int $ordinal): array
{
    $uid=m4mr_integer($fixture['user']['uid'],1);$mer=m4mr_integer($fixture['product']['mer_id'],1,4294967295);
    $product=m4mr_integer($fixture['product']['id'],1,8388607);$address=m4mr_integer($fixture['address']['id'],1,16777215);
    $GLOBALS['m4o_phase']='browser_create_'.$ordinal.'_set_cart';
    $ref=MerchantBrowserInput::reference($app->make(MerchantBrowserCarts::class)->setCart($uid,$product,1,$fixture['sku']['unique'],0,false),'persistent');
    $GLOBALS['m4o_phase']='browser_create_'.$ordinal.'_confirm';
    $choice=MerchantBrowserInput::confirm(['mer_id'=>$mer,'cartId'=>$ref,'addressId'=>$address,'new'=>false]);
    $user=$app->make(MerchantBrowserUsers::class)->getUserInfo($uid);
    m4mb_expect($user!==null&&(int)$user->getData('uid')===$uid,'BROWSER_NATIVE_BUYER_MODEL');
    $confirmation=$app->make(MerchantBrowserConfirm::class)->getOrderConfirmData($user->toArray(),$ref,false,$address,1,0,$choice);
    $key=MerchantBrowserInput::confirmationKey($confirmation['orderKey']);
    $cached=MerchantBrowserCache::get('user_order_'.$uid.$key);
    $frame=MerchantBrowserSelection::frame($cached,$uid);
    m4mb_expect($frame['mer_id']===$mer&&$frame['source_kind']==='persistent'&&$frame['cart_refs']===[$ref]
        &&count($frame['selection'])===1&&count($frame['custom_form'])===1,'BROWSER_REAL_SINGLE_ITEM_CONFIRMATION');
    $group=$frame['custom_form'][0];$answers=[];
    foreach($group['fields']as$field)$answers[]=['field_index'=>$field['field_index'],
        'value'=>$field['label']==='number'?'0':'普通分页资料 '.$ordinal];
    $raw=['mer_id'=>$mer,'cartId'=>$ref,'new'=>false,'addressId'=>$address,'invoice_id'=>0,'payType'=>'',
        'idem_key'=>'browser-'.bin2hex(random_bytes(16)),'mark'=>'普通分页订单 '.$ordinal,
        'custom_form'=>[['product_id'=>$group['product_id'],'definition_sha256'=>$group['definition_sha256'],'values'=>$answers]]];
    $GLOBALS['m4o_phase']='browser_create_'.$ordinal.'_create';
    m4mb_expect(!MerchantBrowserDb::connect()->getPdo()->inTransaction(),'BROWSER_CREATE_NO_OUTER_TRANSACTION');
    $result=$app->make(MerchantBrowserCreate::class)->create($uid,$key,$raw);
    $GLOBALS['m4o_phase']='browser_create_'.$ordinal.'_readback';
    m4mb_expect(is_string($result['order_id']??null)&&strlen($result['order_id'])<=32&&($result['key']??null)===$key,
        'BROWSER_REAL_CREATED_ORDER_NUMBER');
    $rows=m4mb_query($pdo,'SELECT * FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$uid,$result['order_id']]);
    m4mb_expect(count($rows)===1,'BROWSER_CREATED_ORDER_INDEPENDENT_READ');
    return ['order'=>$rows[0],'ref'=>$ref,'key'=>$key,'idem'=>$raw['idem_key'],'ordinal'=>$ordinal,
        'form_hash'=>$group['definition_sha256']];
}

/** Independent saved-row checks; this buyer intentionally has twenty genuine orders. */
function m4mb_verify_order(PDO $pdo,array $fixture,array $created): array
{
    $order=$created['order'];$id=m4mr_integer($order['id'],1);$uid=m4mr_integer($fixture['user']['uid'],1);
    $mer=m4mr_integer($fixture['product']['mer_id'],1,4294967295);$product=m4mr_integer($fixture['product']['id'],1,8388607);
    $skuId=m4mr_integer($fixture['sku']['id'],1);$index=$created['ordinal']-1;
    foreach(['uid'=>$uid,'mer_id'=>$mer,'marketplace_trade_version'=>1,'pid'=>0,'is_system_del'=>0,
        'paid'=>0,'is_cancel'=>0,'status'=>0,'total_num'=>1]as$field=>$expected)
        m4mb_expect(m4mr_integer($order[$field],0,4294967295)===$expected,'BROWSER_SAVED_ORDER_FIELD');
    foreach(['total_price'=>'20.15','pay_postage'=>'2.67','pay_price'=>'22.82','cost'=>'7.45']as$field=>$expected)
        m4mb_expect($order[$field]===$expected,'BROWSER_INDEPENDENT_ORDER_MONEY');
    m4mb_expect($order['unique']===$created['key']&&json_decode($order['cart_id'],true,32,JSON_THROW_ON_ERROR)===[$created['ref']],
        'BROWSER_SAVED_ORIGINAL_REFERENCES');
    $address=$fixture['address'];
    m4mb_expect($order['real_name']===$address['real_name']&&$order['user_phone']===$address['phone']
        &&$order['user_address']===$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail']
        &&$order['mark']==='普通分页订单 '.$created['ordinal'],'BROWSER_SAVED_RECIPIENT_AND_MARK');
    $form=[['product_id'=>$product,'product_name'=>$fixture['product']['store_name'],'definition_sha256'=>$created['form_hash'],
        'fields'=>[['field_index'=>0,'title'=>'保存资料','label'=>'text','status'=>true,'value'=>'普通分页资料 '.$created['ordinal']],
            ['field_index'=>1,'title'=>'保存资料','label'=>'number','status'=>false,'value'=>'0']]]];
    m4mb_expect(m4mr_same(m4mr_forms($order['custom_form'],[$product]),$form),'BROWSER_SAVED_ORIGINAL_FORM_VALUES');
    $items=m4mb_query($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]);
    $ledgers=m4mb_query($pdo,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `order_id` = ?',[$id]);
    $movements=m4mb_query($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `id`',[$id]);
    m4mb_expect(count($items)===1&&count($ledgers)===1&&count($movements)===1,'BROWSER_SINGLE_ITEM_LEDGER_RESERVE');
    $item=$items[0];$ledger=$ledgers[0];$movement=$movements[0];
    foreach(['uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'state'=>1,'cancel_at'=>0,'post_commit_done'=>1]as$field=>$expected)
        m4mb_expect(m4mr_integer($ledger[$field],0,4294967295)===$expected,'BROWSER_SAVED_LEDGER_FIELD');
    m4mb_expect($ledger['source_kind']==='persistent'&&$ledger['idem_key']===$created['idem']
        &&hash_equals(hash('sha256',$created['key'],true),$ledger['confirmation_ref'])
        &&json_decode($ledger['cart_refs'],true,32,JSON_THROW_ON_ERROR)===[$created['ref']],'BROWSER_SAVED_LEDGER_REFERENCES');
    foreach(['oid'=>$id,'uid'=>$uid,'mer_id'=>$mer,'product_id'=>$product,'cart_num'=>1,'refund_num'=>0,'surplus_num'=>1]as$field=>$expected)
        m4mb_expect(m4mr_integer($item[$field],0,4294967295)===$expected,'BROWSER_SAVED_ITEM_FIELD');
    m4mb_expect($item['cart_id']===$created['ref']&&$item['unique']===md5($created['ref'].$id),'BROWSER_SAVED_ITEM_REFERENCE');
    $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    $reserveId=MerchantBrowserInput::reference($movement['id'],'persistent');
    m4mb_expect(m4mr_same($cart['marketplace']??null,['cart_ref'=>$created['ref'],'sku_id'=>$skuId,'sku_type'=>0,
        'sku_unique'=>$fixture['sku']['unique'],'reserve_id'=>$reserveId]),'BROWSER_REAL_RESERVE_LINEAGE');
    foreach(['truePrice'=>'20.15','sum_true_price'=>'20.15','sum_price'=>'20.15','costPrice'=>'7.45',
        'postage_price'=>'2.67','origin_postage_price'=>'2.67']as$field=>$expected)
        m4mb_expect(($cart[$field]??null)===$expected,'BROWSER_SAVED_PRIVATE_AND_PUBLIC_PRICE');
    m4mb_expect(($cart['productInfo']['image']??null)===$fixture['product']['image']
        &&($cart['productInfo']['attrInfo']['image']??null)===$fixture['sku']['image'],'BROWSER_SAVED_SAME_ORIGIN_PNG');
    m4mb_expect(m4mr_integer($order['uid'],1,4294967295)===$uid&&m4mr_integer($ledger['uid'],1,4294967295)===$uid
        &&$movement['actor_type']==='customer'&&m4mr_integer($movement['actor_id'],1,4294967295)===$uid,
        'BROWSER_SAVED_CUSTOMER_IDENTITY');
    foreach(['mer_id'=>$mer,'order_id'=>$id,'product_id'=>$product,'sku_id'=>$skuId,'sku_type'=>0,'qty'=>1,
        'actor_id'=>$uid,'sku_stock_before'=>20-$index,'sku_stock_after'=>19-$index,'root_stock_before'=>20-$index,
        'root_stock_after'=>19-$index,'sku_sales_before'=>$index,'sku_sales_after'=>$index+1,
        'root_sales_before'=>$index,'root_sales_after'=>$index+1]as$field=>$expected)
        m4mb_expect(m4mr_integer($movement[$field],0,4294967295)===$expected,'BROWSER_ACTUAL_STOCK_STEP');
    m4mb_expect($movement['kind']==='reserve'&&$movement['actor_type']==='customer'
        &&$movement['sku_unique']===$fixture['sku']['unique']&&$movement['parent_reserve_id']===null
        &&$movement['order_item_id']===null&&$movement['refund_id']===null
        &&$movement['idem_key']==='reserve:'.$id.':'.$skuId,'BROWSER_RESERVE_ACTION_IDENTITY');
    $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$product,
        'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$fixture['sku']['unique'],'qty'=>1,'kind'=>'reserve','cart_refs'=>[$created['ref']]];
    m4mb_expect(hash_equals(hash('sha256',MerchantBrowserInput::canonical($action),true),$movement['request_digest']),
        'BROWSER_RESERVE_ORIGINAL_DIGEST');
    $statuses=m4mb_query($pdo,'SELECT * FROM `eb_store_order_status` WHERE `oid` = ?',[$id]);
    m4mb_expect(count($statuses)===1&&m4pc_row_text($statuses[0])===m4pc_row_text(['oid'=>$id,
        'change_type'=>'cache_key_create_order','change_message'=>'订单生成','change_time'=>$order['add_time']]),
        'BROWSER_COMPLETED_CREATION_STATUS');
    m4mb_expect(m4mb_query($pdo,'SELECT * FROM `eb_store_cart` WHERE `id` = ?',[$created['ref']])===[]
        &&m4mb_query($pdo,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id])===[],
        'BROWSER_CART_CONSUMED_NO_INVOICE');
    m4mb_expect(MerchantBrowserCache::get('user_order_'.$uid.$created['key'],null)===null,
        'BROWSER_CONFIRMATION_CACHE_REMOVED');
    return ['id'=>$id,'order_id'=>$order['order_id'],'item_id'=>m4mr_integer($item['id'],1),'reserve_id'=>$reserveId];
}

/** All side effects are confined to the dedicated database and the fixed private volumes. */
function m4mb_prepare(\think\App $app,PDO $pdo,array $normal,array $binding): array
{
    m4mb_expect(M4MB_FACT_TABLES===M4O_FACT_TABLES,'BROWSER_FACT_TABLE_CLOSURE');
    m4mb_expect(($binding['image_url']??null)==='/__ms4_fixture__/product.png','BROWSER_FIXED_SAME_ORIGIN_PNG');
    MerchantBrowserDb::transaction(static function(\think\db\ConnectionInterface $connection):void{
        m4o_business_pdo($connection);
    });
    m4pc_context($app,$pdo);$before=m4mb_sql_rows($pdo);
    $originalId=m4mr_integer($normal['service_creations']['persistent']['order_id']??null,1);
    $stoppedId=m4mr_integer($normal['cancellations']['expiry']['order_id']??null,1);
    $original=m4mb_query($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$originalId]);
    $stopped=m4mb_query($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$stoppedId]);
    m4mb_expect(count($original)===1&&count($stopped)===1,'BROWSER_ORIGINAL_PROOF_NATIVE_ORDERS');
    $ownedMer=m4mr_integer($original[0]['mer_id'],1,4294967295);$stoppedMer=m4mr_integer($stopped[0]['mer_id'],1,4294967295);
    m4mb_expect($ownedMer!==$stoppedMer&&count(m4mr_saved($pdo,$ownedMer))===2&&count(m4mr_saved($pdo,$stoppedMer))===1,
        'BROWSER_ORIGINAL_OWNED_STOPPED_COUNTS');
    $actors=m4mr_fixtures($pdo,$ownedMer,$stoppedMer);$contexts=[];$private=[];
    foreach($actors as$label=>$actor){
        $contexts[$label]=m4mr_actor($app,$pdo,$app->make(MerchantBrowserAuth::class),$actor);
        m4mb_expect($actor['permissions']===['merchant.profile.read','order.read'],'BROWSER_HTTP_ONLY_MINIMAL_ROLE');
        $private[$label]=['mer_id'=>$actor['merId'],'employee_id'=>m4mr_integer($actor['employee']['id'],1),
            'role_id'=>m4mr_integer($actor['role']['id'],1,4294967295),'account'=>$actor['employee']['account'],'password'=>$actor['password']];
    }
    $fixture=m4mb_fixture($ownedMer,$binding['image_url']);
    MerchantBrowserCache::delete('system_config_order_cancel_time');
    m4mb_expect(sys_config('order_cancel_time')==='0'&&MerchantBrowserCache::get('system_config_order_cancel_time')==='0',
        'BROWSER_REAL_ZERO_CANCEL_CONFIGURATION_GETTER');
    $created=[];$ids=$numbers=$keys=$refs=[];
    for($i=1;$i<=20;$i++){
        $current=m4mb_create_one($app,$pdo,$fixture,$i);
        $GLOBALS['m4o_phase']='browser_create_'.$i.'_verify_order';$proof=m4mb_verify_order($pdo,$fixture,$current);
        m4mb_expect(!in_array($proof['id'],$ids,true)&&!in_array($proof['order_id'],$numbers,true)
            &&!in_array($current['key'],$keys,true)&&!in_array($current['ref'],$refs,true),'BROWSER_DISTINCT_REAL_ORDER_IDENTITIES');
        $ids[]=$proof['id'];$numbers[]=$proof['order_id'];$keys[]=$current['key'];$refs[]=$current['ref'];$created[]=$proof;
    }
    $uid=m4mr_integer($fixture['user']['uid'],1);$product=m4mr_integer($fixture['product']['id'],1,8388607);
    $newOrders=m4mb_query($pdo,'SELECT `id` FROM `eb_store_order` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    m4mb_expect(array_map(static fn(array $row):int=>m4mr_integer($row['id'],1),$newOrders)===$ids,'BROWSER_EXACT_TWENTY_BUYER_ORDERS');
    m4mb_expect(m4mb_query($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ?',[$uid])===[],
        'BROWSER_NO_REMAINING_NEW_BUYER_CART');
    foreach([['eb_store_product',$product],['eb_store_product_attr_value',$fixture['sku']['id']]]as[$table,$id]){
        $rows=m4mb_query($pdo,'SELECT * FROM `'.$table.'` WHERE `id` = ?',[$id]);
        m4mb_expect(count($rows)===1&&m4mr_integer($rows[0]['stock'],0,0)===0&&m4mr_integer($rows[0]['sales'],20,20)===20,
            'BROWSER_FINAL_REAL_STOCK_AND_SALES');
        $expected=$table==='eb_store_product'?$fixture['product']:$fixture['sku'];$expected['stock']=0;$expected['sales']=20;
        m4mb_expect(m4pc_row_text($rows[0])===m4pc_row_text($expected),'BROWSER_ONLY_NEW_INVENTORY_COUNTERS_CHANGED');
    }
    $stats=m4mb_query($pdo,'SELECT * FROM `eb_store_product_log` WHERE `uid` = ? AND `type` = ? ORDER BY `id`',[$uid,'order']);
    $expectedStats=$actualStats=[];
    foreach(m4mb_query($pdo,'SELECT * FROM `eb_store_order` WHERE `uid` = ? ORDER BY `id`',[$uid])as$order)
        $expectedStats[]=implode(':',['order',$product,$uid,1,$order['add_time']]);
    foreach($stats as$row){
        foreach(['visit_num','cart_num','pay_num','pay_uid','refund_num','collect_num']as$field)
            m4mb_expect(m4mr_integer($row[$field],0,0)===0,'BROWSER_STAT_NO_OTHER_ACTION');
        foreach(['pay_price','cost_price','refund_price']as$field)m4mb_expect($row[$field]==='0.00','BROWSER_STAT_NO_FINANCIAL_WRITE');
        $actualStats[]=implode(':',[$row['type'],$row['product_id'],$row['uid'],$row['order_num'],$row['add_time']]);
    }
    sort($expectedStats,SORT_STRING);sort($actualStats,SORT_STRING);
    m4mb_expect(count($stats)===20&&$actualStats===$expectedStats,'BROWSER_EXACT_SAVED_ITEM_STAT_MULTISET');
    $oracle=[];$orders=$app->make(MerchantBrowserOrders::class);$readBefore=m4mb_sql_facts($pdo);
    foreach($actors as$label=>$actor){
        $oracle[$label]=m4mr_saved($pdo,$actor['merId']);$rows=array_column($oracle[$label],'list');
        $expectedCount=['owned'=>22,'stopped'=>1,'empty'=>0][$label];
        m4mb_expect(count($rows)===$expectedCount,'BROWSER_FINAL_OWNED_STOPPED_EMPTY_COUNTS');
        $actual=$orders->list($contexts[$label],['page'=>1,'limit'=>20]);
        m4mb_expect(m4mr_same($actual,['list'=>array_slice($rows,0,20),'count'=>$expectedCount]),'BROWSER_REAL_DEFAULT_PAGE_ORACLE');
        foreach($oracle[$label]as$row)m4mb_expect(m4mr_same($orders->detail($contexts[$label],$row['list']['id']),$row['detail']),
            'BROWSER_REAL_DETAIL_SAVED_ORACLE');
    }
    m4mb_expect(m4mr_same($orders->list($contexts['owned'],['page'=>2,'limit'=>20]),
        ['list'=>array_column(array_slice($oracle['owned'],20,20),'list'),'count'=>22]),'BROWSER_REAL_SECOND_PAGE_TWO_ROWS');
    m4mb_expect(m4mb_sql_facts($pdo)===$readBefore,'BROWSER_SAVED_READS_NO_SQL_WRITE');
    m4mb_original_rows($before,m4mb_sql_rows($pdo),m4mr_integer($fixture['configuration']['id'],1));
    m4pc_context($app,$pdo);audit_guard_assert();
    m4mb_expect($app->config->get('marketplace.trade.enabled')===false,'BROWSER_CREATE_HTTP_GATE_STILL_CLOSED');
    $identity=['schema'=>1,'run_id'=>getenv('AUDIT_RUN_ID'),'source_sha'=>getenv('AUDIT_SOURCE_SHA'),'binding'=>$binding];
    $publicOracle=[];foreach($oracle as$label=>$rows)$publicOracle[$label]=['mer_id'=>$actors[$label]['merId'],
        'employee_id'=>$private[$label]['employee_id'],'role_id'=>$private[$label]['role_id'],
        'orders'=>array_map(static fn(array $row):array=>['list'=>$row['list'],'detail'=>$row['detail']],$rows)];
    $facts=m4mb_sql_facts($pdo);$factsSha=hash('sha256',serialize($facts));
    m4mb_private_new('/run/audit-http-control/sql-baseline.json',$identity+[
        'scope'=>'NORMAL_MERCHANT_BROWSER_SQL_BASELINE','facts'=>$facts,'facts_sha256'=>$factsSha]);
    m4mb_private_new('/run/audit-http-control/merchant-oracle.json',$identity+[
        'scope'=>'NORMAL_MERCHANT_BROWSER_SAVED_ORACLE','actors'=>$publicOracle,'new_orders'=>$created]);
    m4mb_private_new('/run/audit-http-browser/merchant-actors.json',$identity+[
        'scope'=>'NORMAL_MERCHANT_BROWSER_PRIVATE_ACTORS','actors'=>$private]);
    unset($private,$actors,$contexts);
    return ['actors'=>['count'=>3,'credential_login_authenticated'=>true,'permissions_exact'=>true,'private_delivery'=>true],
        'orders'=>['created'=>20,'owned_count'=>22,'stopped_count'=>1,'empty_count'=>0,'page_size'=>20,'page_rows'=>[20,2],
            'all_unpaid'=>true,'cancel_at_zero'=>true,'post_commit_done_count'=>20],
        'inventory'=>['initial_stock'=>20,'remaining_stock'=>0,'sales'=>20],
        'original_facts_unchanged'=>true,'sql_baseline_sha256'=>$factsSha,'private_credentials_emitted'=>false];
}
