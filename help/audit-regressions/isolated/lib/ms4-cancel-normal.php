<?php
declare(strict_types=1);

use app\services\marketplace\order\MarketplaceCartSelection as CancelNormalSelection;
use app\services\marketplace\order\MarketplaceOrderCancelServices as CancelNormalService;
use app\services\marketplace\order\MarketplaceOrderCreateServices as CancelNormalCreate;
use app\services\marketplace\order\MarketplaceOrderInput as CancelNormalInput;
use app\services\order\StoreCartServices as CancelNormalCart;
use app\services\order\StoreOrderServices as CancelNormalOrders;
use app\services\system\crontab\CrontabRunServices as CancelNormalRunner;
use app\services\user\UserServices as CancelNormalUsers;
use crmeb\services\CacheService as CancelNormalCache;
use crmeb\services\pay\marketplace\PaymentAccountResolver as CancelNormalAccounts;
use crmeb\services\pay\marketplace\PaymentAdapter as CancelNormalAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver as CancelNormalDriver;
use think\facade\Db as CancelNormalDb;

/** U64 movement identities stay canonical decimal strings, including above PHP's signed range. */
function m4cn_u64(mixed $value): string
{
    m4o_expect((is_int($value)||is_string($value))&&preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$value)===1
        &&bccomp((string)$value,'18446744073709551615',0)<=0,'CANCEL_NATIVE_U64');
    return (string)$value;
}

function m4cn_text_rows(array $rows): array
{
    return array_map('m4pc_row_text',$rows);
}

/** All five permitted cancellation tables are read completely; other tables retain their original facts. */
function m4cn_capture(PDO $pdo): array
{
    $tables=[];
    foreach(['eb_store_order','eb_store_product','eb_store_product_attr_value','eb_marketplace_stock_movement','eb_store_order_status'] as $table){
        m4o_expect(in_array($table,M4O_FACT_TABLES,true),'CANCEL_CAPTURE_CLOSED_TABLE');
        $order=$table==='eb_store_order_status'?'`oid`,`change_type`,`change_message`,`change_time`':'`id`';
        $tables[$table]=m4o_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY '.$order);
    }
    return ['tables'=>$tables,'facts'=>m4o_facts($pdo)];
}

function m4cn_native_row(PDO $pdo,string $table,string $key,int $id): array
{
    m4o_expect(in_array([$table,$key],[['eb_store_product','id'],['eb_store_product_attr_value','id'],
        ['eb_marketplace_merchant','mer_id']],true),'CANCEL_NATIVE_ROW_CLOSED_TABLE');
    $rows=m4o_rows($pdo,'SELECT * FROM `'.$table.'` WHERE `'.$key.'` = ?',[$id]);
    m4o_expect(count($rows)===1&&m4pc_integer($rows[0][$key],1,4294967295)===$id,'CANCEL_NATIVE_ROW_IDENTITY');
    return $rows[0];
}

/** Saved items supply each parent's real SKU, quantity and opaque cart references. */
function m4cn_reserves(PDO $pdo,array $saved): array
{
    $order=$saved['order'];$id=m4pc_integer($order['id'],1);$uid=m4pc_integer($order['uid'],1);
    $mer=m4pc_integer($order['mer_id'],1,4294967295);$groups=[];
    foreach($saved['items'] as $item){
        $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$lineage=$cart['marketplace']??null;
        m4o_expect(is_array($lineage),'CANCEL_SAVED_ITEM_LINEAGE');$parent=m4cn_u64($lineage['reserve_id']??null);$slot='id:'.$parent;
        $product=m4pc_integer($item['product_id'],1,8388607);$sku=m4pc_integer($lineage['sku_id']??null,1);
        $quantity=m4pc_integer($item['cart_num'],1);$unique=$cart['product_attr_unique']??null;
        m4o_expect(is_string($unique)&&preg_match('/\A[A-Za-z0-9]{8}\z/D',$unique)===1
            &&m4pc_integer($cart['product_id']??null,1,8388607)===$product
            &&m4pc_integer($item['mer_id'],1,4294967295)===$mer&&($lineage['cart_ref']??null)===$item['cart_id']
            &&($lineage['sku_unique']??null)===$unique&&m4pc_integer($lineage['sku_type']??null,0,0)===0,
            'CANCEL_SAVED_PHYSICAL_IDENTITY');
        if(!isset($groups[$slot]))$groups[$slot]=['parent_id'=>$parent,'product_id'=>$product,'sku_id'=>$sku,
            'unique'=>$unique,'qty'=>0,'cart_refs'=>[]];
        m4o_expect($groups[$slot]['product_id']===$product&&$groups[$slot]['sku_id']===$sku
            &&$groups[$slot]['unique']===$unique,'CANCEL_SINGLE_PARENT_PHYSICAL_SKU');
        $groups[$slot]['qty']+=$quantity;$groups[$slot]['cart_refs'][]=$item['cart_id'];
    }
    $rows=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `id`',[$id]);
    m4o_expect(count($rows)===count($groups),'CANCEL_ONLY_REAL_PARENT_RESERVES');$parents=[];
    foreach($rows as $row){
        $parent=m4cn_u64($row['id']);$slot='id:'.$parent;$group=$groups[$slot]??null;
        m4o_expect(is_array($group)&&$row['kind']==='reserve'&&$row['parent_reserve_id']===null
            &&$row['order_item_id']===null&&$row['refund_id']===null&&$row['actor_type']==='customer'
            &&m4pc_integer($row['actor_id'],1)===$uid&&m4pc_integer($row['order_id'],1)===$id
            &&m4pc_integer($row['mer_id'],1,4294967295)===$mer
            &&m4pc_integer($row['product_id'],1,8388607)===$group['product_id']
            &&m4pc_integer($row['sku_id'],1)===$group['sku_id']&&m4pc_integer($row['sku_type'],0,0)===0
            &&$row['sku_unique']===$group['unique']&&m4pc_integer($row['qty'],1,4294967295)===$group['qty']
            &&$row['idem_key']==='reserve:'.$id.':'.$group['sku_id'],'CANCEL_REAL_PARENT_BINDING');
        $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$group['product_id'],
            'sku_id'=>$group['sku_id'],'sku_type'=>0,'sku_unique'=>$group['unique'],'qty'=>$group['qty'],
            'kind'=>'reserve','cart_refs'=>$group['cart_refs']];
        m4o_expect(is_string($row['request_digest'])&&hash_equals(hash('sha256',CancelNormalInput::canonical($action),true),$row['request_digest']),
            'CANCEL_REAL_PARENT_DIGEST');
        $parents[]=['row'=>$row,'group'=>$group];
    }
    usort($parents,static fn(array $a,array $b):int=>$a['group']['sku_id']<=>$b['group']['sku_id']);
    return $parents;
}

/** Create one real second customer and personal address, then buy one of the first order's same physical SKUs. */
function m4cn_keeper(\think\App $app,PDO $pdo,array $original,array $parents): array
{
    $chosen=$parents[0];$skuId=$chosen['group']['sku_id'];$productId=$chosen['group']['product_id'];
    $mer=m4pc_integer($original['order']['mer_id'],1,4294967295);
    $rootBefore=m4cn_native_row($pdo,'eb_store_product','id',$productId);
    $skuBefore=m4cn_native_row($pdo,'eb_store_product_attr_value','id',$skuId);
    m4o_expect(m4pc_integer($skuBefore['product_id'],1,8388607)===$productId&&$skuBefore['unique']===$chosen['group']['unique']
        &&m4pc_integer($rootBefore['mer_id'],1,4294967295)===$mer,'CANCEL_KEEPER_REAL_SAME_SKU');
    $config=m4o_rows($pdo,'SELECT `value` FROM `eb_system_config` WHERE `menu_name` = ?',['order_cancel_time']);
    m4o_expect($config===[['value'=>json_encode('0',JSON_THROW_ON_ERROR)]],'CANCEL_KEEPER_SAVED_ZERO_HOURS_SOURCE');
    $addresses=m4o_rows($pdo,'SELECT * FROM `eb_user_address` WHERE `uid` = ? AND `is_del` = 0 ORDER BY `id`',[$original['order']['uid']]);
    m4o_expect($addresses!==[],'CANCEL_KEEPER_REAL_ADDRESS_SOURCE');$source=$addresses[0];
    $fixture=CancelNormalDb::transaction(static function(\think\db\ConnectionInterface $connection)use($source):array{
        $native=m4o_business_pdo($connection);$now=time();
        $user=m4o_insert($native,'eb_user',['account'=>'ms4-'.getenv('AUDIT_RUN_ID').'-c2','nickname'=>'顺序购买验证顾客',
            'user_type'=>'h5','is_money_level'=>0,'is_ever_level'=>0,'add_time'=>$now],'uid');
        $address=m4o_insert($native,'eb_user_address',['uid'=>$user['uid'],'real_name'=>'顺序购买验证',
            'phone'=>$source['phone'],'province'=>$source['province'],'city'=>$source['city'],
            'district'=>$source['district'],'detail'=>$source['detail'],'is_default'=>1,'add_time'=>$now]);
        return compact('user','address');
    });
    m4pc_context($app,$pdo);$uid=m4pc_integer($fixture['user']['uid'],1);$addressId=m4pc_integer($fixture['address']['id'],1,16777215);
    m4o_expect($uid!==m4pc_integer($original['order']['uid'],1),'CANCEL_KEEPER_DIFFERENT_NATIVE_CUSTOMER');
    $sqlCartBefore=m4o_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    $ref=$app->make(CancelNormalCart::class)->setCart($uid,$productId,1,$skuBefore['unique'],0,true);
    m4o_expect(is_string($ref)&&CancelNormalInput::reference($ref,'temporary')===$ref,'CANCEL_KEEPER_REAL_SET_CART');
    $payload=CancelNormalCache::get($ref);
    m4o_expect(is_array($payload)&&($payload['catalog_cart_version']??null)===1&&($payload['uid']??null)===$uid
        &&($payload['cartData']['id']??null)===$ref&&($payload['cartData']['product_id']??null)===$productId
        &&($payload['cartData']['product_attr_unique']??null)===$skuBefore['unique']&&($payload['cartData']['cart_num']??null)===1,
        'CANCEL_KEEPER_NATIVE_CART_READBACK');
    $choice=CancelNormalInput::confirm(['mer_id'=>$mer,'cartId'=>$ref,'addressId'=>$addressId,'new'=>true]);
    $user=$app->make(CancelNormalUsers::class)->getUserInfo($uid);
    m4o_expect($user!==null&&m4pc_integer($user->getData('uid'),1)===$uid,'CANCEL_KEEPER_ACTUAL_USER_MODEL');
    $confirm=$app->make(CancelNormalOrders::class)->getOrderConfirmData($user->toArray(),$choice['cartId'],true,$addressId,1,0,$choice);
    $key=$confirm['orderKey'];m4o_expect(is_string($key)&&CancelNormalInput::confirmationKey($key)===$key,'CANCEL_KEEPER_SERVER_CONFIRMATION_KEY');
    $frame=CancelNormalSelection::frame(CancelNormalCache::get('user_order_'.$uid.$key),$uid);
    m4o_expect($frame['mer_id']===$mer&&$frame['source_kind']==='temporary'&&$frame['cart_refs']===[$ref]
        &&count($frame['selection'])===1&&count($frame['custom_form'])===1,'CANCEL_KEEPER_REAL_ONE_ITEM_FRAME');
    $answers=[];foreach($frame['custom_form'] as $group){
        $values=[];foreach($group['fields'] as $field){
            m4o_expect(in_array($field['label'],['text','number'],true),'CANCEL_KEEPER_EXISTING_FIXTURE_FORM_KIND');
            $values[]=['field_index'=>$field['field_index'],'value'=>$field['label']==='number'?'0':'顺序购买填写'];
        }
        $answers[]=['product_id'=>$group['product_id'],'definition_sha256'=>$group['definition_sha256'],'values'=>$values];
    }
    $raw=['mer_id'=>$mer,'cartId'=>$ref,'new'=>true,'addressId'=>$addressId,'invoice_id'=>0,
        'idem_key'=>'cancel-keeper:'.$uid.':'.$key,'payType'=>'','mark'=>'顺序购买库存验证','custom_form'=>$answers];
    m4pc_context($app,$pdo);$created=$app->make(CancelNormalCreate::class)->create($uid,$key,$raw);
    $rows=m4o_rows($pdo,'SELECT `id` FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$uid,$created['order_id']]);
    m4o_expect(count($rows)===1&&$created['key']===$key,'CANCEL_KEEPER_ACTUAL_CREATED_ORDER');$id=m4pc_integer($rows[0]['id'],1);
    $saved=m4pc_order($pdo,$id,1);$reserves=m4cn_reserves($pdo,$saved);
    m4o_expect(count($saved['items'])===1&&count($reserves)===1&&$reserves[0]['group']['sku_id']===$skuId
        &&$reserves[0]['group']['qty']===1&&m4pc_integer($saved['ledger']['cancel_at'],0,0)===0
        &&m4pc_integer($saved['order']['paid'],0,0)===0&&m4pc_integer($saved['order']['is_cancel'],0,0)===0,
        'CANCEL_KEEPER_ONE_UNIT_REMAINS_RESERVED');
    $cart=json_decode($saved['items'][0]['cart_info'],true,64,JSON_THROW_ON_ERROR);$order=$saved['order'];
    m4o_expect((string)$rootBefore['freight']==='2'&&$cart['truePrice']===$skuBefore['price']
        &&$cart['costPrice']===$skuBefore['cost']&&$cart['sum_true_price']===$skuBefore['price']
        &&$order['total_price']===$skuBefore['price']&&$order['cost']===$skuBefore['cost']
        &&$order['pay_postage']===$rootBefore['postage']&&$order['total_postage']===$rootBefore['postage']
        &&$order['pay_price']===bcadd($skuBefore['price'],$rootBefore['postage'],2),
        'CANCEL_KEEPER_REAL_BASE_SKU_PRICE_COST_AND_ONE_LINE_POSTAGE');
    $rootAfter=m4cn_native_row($pdo,'eb_store_product','id',$productId);$skuAfter=m4cn_native_row($pdo,'eb_store_product_attr_value','id',$skuId);
    foreach([[$rootBefore,$rootAfter,16777215],[$skuBefore,$skuAfter,4294967295]] as [$before,$after,$maximum]){
        $expected=$before;$expected['stock']=m4pc_integer($before['stock'],1,$maximum)-1;
        $expected['sales']=m4pc_integer($before['sales'],0,$maximum)+1;
        m4o_expect(m4pc_row_text($after)===m4pc_row_text($expected),'CANCEL_KEEPER_ACTUAL_SEQUENTIAL_STOCK_DELTA');
    }
    $move=$reserves[0]['row'];
    foreach(['sku'=>[$skuBefore,$skuAfter,4294967295],'root'=>[$rootBefore,$rootAfter,16777215]] as $kind=>[$before,$after,$maximum]){
        foreach(['stock','sales'] as $field)m4o_expect(m4pc_integer($move[$kind.'_'.$field.'_before'],0,$maximum)===m4pc_integer($before[$field],0,$maximum)
            &&m4pc_integer($move[$kind.'_'.$field.'_after'],0,$maximum)===m4pc_integer($after[$field],0,$maximum),'CANCEL_KEEPER_REAL_RESERVE_ARITHMETIC');
    }
    m4o_expect(m4o_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid])===$sqlCartBefore,
        'CANCEL_KEEPER_TEMPORARY_CART_NO_SQL_CHANGE');
    $post=m4o_post_commit_verify($app,$pdo,$id);m4o_expect($post['item_stat_count']===1,'CANCEL_KEEPER_REAL_POST_COMMIT');
    $forms=json_decode($saved['order']['custom_form'],true,32,JSON_THROW_ON_ERROR);
    m4o_expect(count($forms)===1&&$forms[0]['definition_sha256']===$answers[0]['definition_sha256'],'CANCEL_KEEPER_ORIGINAL_FORM_HASH');
    foreach($forms[0]['fields'] as $index=>$field)m4o_expect($field['field_index']===$answers[0]['values'][$index]['field_index']
        &&$field['value']===$answers[0]['values'][$index]['value'],'CANCEL_KEEPER_REAL_FORM_ANSWERS');
    return ['saved'=>$saved,'parents'=>$reserves,'sku_id'=>$skuId,'product_id'=>$productId,
        'proof'=>['verified'=>true,'order_id'=>$id,'item_count'=>1,'reserve_count'=>1,'quantity'=>1,
            'different_native_uid'=>true,'same_physical_sku'=>true,'cancel_at'=>0,'post_commit_verified'=>true]];
}

/** Only the real factory and resolver create the same-App nonempty pending attempt. */
function m4cn_attempt(\think\App $app,PDO $pdo,CancelNormalDriver $driver,array $saved): array
{
    m4pc_context($app,$pdo);$order=$saved['order'];$id=m4pc_integer($order['id'],1);$mer=m4pc_integer($order['mer_id'],1,4294967295);
    $before=m4o_facts($pdo);$resolver=$app->make(CancelNormalAccounts::class);
    $reference='test:cancel:'.getenv('AUDIT_RUN_ID').':'.$mer;
    $account=$resolver->registerTestAccount($mer,$reference,1,'CNY');$resolved=$resolver->forOrder($order);
    m4o_expect($resolved===$account&&$account->source==='test_config'&&$account->account_id===null
        &&$account->account_ref===$reference&&$account->version===1&&$account->mer_id===$mer
        &&$account->channel==='test'&&$account->currency==='CNY'&&$account->verification_scope==='test_driver_only',
        'CANCEL_REAL_TEST_CONFIG_ACCOUNT');
    $attempt=$driver->createPayment($order,$account,'cancel-normal:'.$id);
    $expected=['order_pk'=>$id,'order_no'=>$order['order_id'],'uid'=>m4pc_integer($order['uid'],1),'mer_id'=>$mer,
        'amount'=>$order['pay_price'],'currency'=>'CNY','channel'=>'test','account_source'=>'test_config',
        'account_id'=>null,'account_ref'=>$reference,'account_version'=>1];
    m4o_expect($attempt['state']==='pending'&&$attempt['scope']==='testing_process_only'&&$attempt['binding']===$expected
        &&is_string($attempt['attempt_ref'])&&preg_match('/\Atest-attempt:[a-f0-9]{32}\z/D',$attempt['attempt_ref'])===1
        &&$attempt['binding_sha256']===hash('sha256',CancelNormalInput::canonical($expected))&&$attempt['provider_trade_no']===null,
        'CANCEL_REAL_PENDING_ATTEMPT_COMPLETE_BINDING');
    m4o_expect($driver->queryPayment($attempt)===$attempt&&$driver->attemptsForOrder($order)
        ===['state'=>'known','scope'=>'testing_process_only','attempts'=>[$attempt]],'CANCEL_SAME_APP_NONEMPTY_KNOWN_ATTEMPTS');
    m4o_expect(m4o_facts($pdo)===$before,'CANCEL_TEST_DRIVER_NO_SQL_WRITE');
    return $attempt;
}

/** Independent current-value oracle, complete preserved tables, native release identities and actor. */
function m4cn_verify_cancel(\think\App $app,PDO $pdo,array $saved,array $parents,array $before,
    string $actor,?int $actorId,int $started,int $ended,CancelNormalDriver $driver,array $attempt): array
{
    m4pc_context($app,$pdo);$id=m4pc_integer($saved['order']['id'],1);$uid=m4pc_integer($saved['order']['uid'],1);
    $mer=m4pc_integer($saved['order']['mer_id'],1,4294967295);$after=m4cn_capture($pdo);
    foreach($before['facts'] as $table=>$facts)if(!isset($before['tables'][$table]))
        m4o_expect($after['facts'][$table]===$facts,'CANCEL_OTHER_COMPLETE_TABLES_UNCHANGED');
    $expectedOrders=$before['tables']['eb_store_order'];$found=false;
    foreach($expectedOrders as &$row)if(m4pc_integer($row['id'],1)===$id){$row['is_cancel']=1;$found=true;}unset($row);
    m4o_expect($found&&m4cn_text_rows($after['tables']['eb_store_order'])===m4cn_text_rows($expectedOrders),
        'CANCEL_ONLY_IS_CANCEL_CHANGED_ALL_ORDERS');
    $rootDelta=$skuDelta=[];$rootState=$skuState=[];
    foreach($before['tables']['eb_store_product'] as $row)$rootState[m4pc_integer($row['id'],1,8388607)]=$row;
    foreach($before['tables']['eb_store_product_attr_value'] as $row)$skuState[m4pc_integer($row['id'],1)]=$row;
    foreach($parents as $parent){$g=$parent['group'];$rootDelta[$g['product_id']]=($rootDelta[$g['product_id']]??0)+$g['qty'];
        $skuDelta[$g['sku_id']]=($skuDelta[$g['sku_id']]??0)+$g['qty'];}
    foreach(['eb_store_product'=>[$rootDelta,16777215],'eb_store_product_attr_value'=>[$skuDelta,4294967295]] as $table=>[$deltas,$maximum]){
        $wanted=$before['tables'][$table];foreach($wanted as &$row){$rowId=m4pc_integer($row['id'],1);if(!isset($deltas[$rowId]))continue;
            $row['stock']=m4pc_integer($row['stock'],0,$maximum)+$deltas[$rowId];
            $row['sales']=m4pc_integer($row['sales'],0,$maximum)-$deltas[$rowId];
            m4o_expect($row['stock']<=$maximum&&$row['sales']>=0,'CANCEL_CURRENT_STOCK_BOUNDARIES');}unset($row);
        m4o_expect(m4cn_text_rows($after['tables'][$table])===m4cn_text_rows($wanted),'CANCEL_CURRENT_STOCK_PLUS_SAVED_RESERVE_ALL_ROWS');
    }
    $oldMoves=[];foreach($before['tables']['eb_marketplace_stock_movement'] as $row)$oldMoves['id:'.m4cn_u64($row['id'])]=$row;
    $newMoves=[];$oldSeen=0;
    foreach($after['tables']['eb_marketplace_stock_movement'] as $row){$slot='id:'.m4cn_u64($row['id']);
        if(isset($oldMoves[$slot])){m4o_expect(m4pc_row_text($row)===m4pc_row_text($oldMoves[$slot]),'CANCEL_ALL_OLD_MOVEMENTS_IMMUTABLE');++$oldSeen;}
        else $newMoves[]=$row;}
    m4o_expect($oldSeen===count($oldMoves)&&count($newMoves)===count($parents),'CANCEL_EXACT_NEW_RELEASE_COUNT');
    $byParent=[];foreach($newMoves as $row){$slot='id:'.m4cn_u64($row['parent_reserve_id']);
        m4o_expect(!isset($byParent[$slot]),'CANCEL_ONE_RELEASE_PER_REAL_PARENT');$byParent[$slot]=$row;}
    $stamp=null;
    foreach($parents as $parent){$g=$parent['group'];$parentId=m4cn_u64($parent['row']['id']);$release=$byParent['id:'.$parentId]??null;
        m4o_expect(is_array($release),'CANCEL_PARENT_RELEASE_EXISTS');$when=m4pc_integer($release['add_time'],1,4294967295);
        m4o_expect($when>=$started&&$when<=$ended&&($stamp===null||$stamp===$when),'CANCEL_REAL_SINGLE_COMMIT_TIME');$stamp=$when;
        $root=$rootState[$g['product_id']];$sku=$skuState[$g['sku_id']];$quantity=$g['qty'];
        $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$g['product_id'],
            'sku_id'=>$g['sku_id'],'sku_type'=>0,'sku_unique'=>$g['unique'],'qty'=>$quantity,'kind'=>'release','parent_reserve_id'=>$parentId];
        $expected=['id'=>m4cn_u64($release['id']),'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$g['product_id'],'sku_id'=>$g['sku_id'],
            'sku_type'=>0,'sku_unique'=>$g['unique'],'qty'=>$quantity,'kind'=>'release','idem_key'=>'release:'.$id.':'.$parentId,
            'parent_reserve_id'=>$parentId,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>$actor,'actor_id'=>$actorId,
            'request_digest'=>hash('sha256',CancelNormalInput::canonical($action),true),
            'sku_stock_before'=>$sku['stock'],'sku_stock_after'=>m4pc_integer($sku['stock'],0,4294967295)+$quantity,
            'sku_sales_before'=>$sku['sales'],'sku_sales_after'=>m4pc_integer($sku['sales'],0,4294967295)-$quantity,
            'root_stock_before'=>$root['stock'],'root_stock_after'=>m4pc_integer($root['stock'],0,16777215)+$quantity,
            'root_sales_before'=>$root['sales'],'root_sales_after'=>m4pc_integer($root['sales'],0,16777215)-$quantity,'add_time'=>$when];
        m4o_expect(m4pc_row_text($release)===m4pc_row_text($expected),'CANCEL_FULL_RELEASE_IDENTITY_ACTOR_DIGEST_CURRENT_ARITHMETIC');
        foreach(['stock','sales'] as $field){$skuState[$g['sku_id']][$field]=$expected['sku_'.$field.'_after'];
            $rootState[$g['product_id']][$field]=$expected['root_'.$field.'_after'];}
    }
    $newStatuses=$after['tables']['eb_store_order_status'];$expectedStatuses=$before['tables']['eb_store_order_status'];
    $expectedStatuses[]=['oid'=>$id,'change_type'=>'cancel_order','change_message'=>'订单取消','change_time'=>$stamp];
    $sort=static function(array $rows):array{$rows=m4cn_text_rows($rows);usort($rows,static fn(array $a,array $b):int=>strcmp(serialize($a),serialize($b)));return $rows;};
    m4o_expect($sort($newStatuses)===$sort($expectedStatuses),'CANCEL_ONE_STATUS_AND_ALL_OLD_STATUSES_PRESERVED');
    $closed=$attempt;$closed['state']='closed';
    m4o_expect($driver->queryPayment($attempt)===$closed&&$driver->attemptsForOrder($saved['order'])
        ===['state'=>'known','scope'=>'testing_process_only','attempts'=>[$closed]],'CANCEL_REAL_DRIVER_CLOSED_ORIGINAL_BINDING');
    return ['verified'=>true,'order_id'=>$id,'item_count'=>count($saved['items']),'reserve_count'=>count($parents),
        'release_count'=>count($newMoves),'actor_type'=>$actor,'current_stock_verified'=>true,
        'saved_snapshots_unchanged'=>true,'payment_closed'=>true];
}

function m4cn_replay(\think\App $app,PDO $pdo,CancelNormalService $service,array $saved,
    CancelNormalDriver $driver,array $attempt,bool $expired): void
{
    $before=m4o_facts($pdo);$closed=$driver->queryPayment($attempt);$order=$saved['order'];
    $result=$expired?$service->cancelExpired(m4pc_integer($order['id'],1))
        :$service->cancelForCustomer(m4pc_integer($order['uid'],1),$order['order_id']);
    m4o_expect($result===['state'=>'already_cancelled','order_id'=>$order['order_id']],'CANCEL_ORDINARY_REPLAY_SAME_ORDER');
    m4pc_context($app,$pdo);m4o_expect(m4o_facts($pdo)===$before&&$driver->queryPayment($attempt)===$closed,
        'CANCEL_ORDINARY_REPLAY_ALL_FACTS_AND_ORIGINAL_PAYMENT_UNCHANGED');
}

/** Ordinary same-App test-driver cancellation only; no HTTP/auth, real channel, daemon or stopped samples. */
function m4o_cancel_normal(\think\App $app,PDO $pdo,array $originalServiceProofs,array $originalMemberProofs): array
{
    m4pc_context($app,$pdo);audit_guard_assert();
    m4o_expect(getenv('APP_ENV')==='testing'&&getenv('PHP_MARKETPLACE_PAYMENT_TEST_ENABLED')==='true'
        &&$app->config->get('marketplace.payment.test_enabled')===true
        &&$app->config->get('marketplace.trade.enabled')===false,'CANCEL_ACTUAL_DECLARED_TESTING_ENVIRONMENT');
    $driver=$app->make(CancelNormalAdapter::class);
    m4o_expect($driver instanceof CancelNormalDriver&&$driver===$app->make(CancelNormalDriver::class)
        &&$driver===$app->make(CancelNormalAdapter::class),'CANCEL_REAL_FACTORY_SAME_APP_DRIVER');
    $service=$app->make(CancelNormalService::class);
    foreach([[$originalServiceProofs,'persistent'],[$originalMemberProofs,'paid']] as [$proofs,$key])
        m4o_expect(($proofs[$key]['verified']??null)===true&&is_int($proofs[$key]['order_id']??null),'CANCEL_ORIGINAL_VERIFIED_NATIVE_ORDER_PROOF');
    $original=m4pc_order($pdo,$originalServiceProofs['persistent']['order_id'],1);$parents=m4cn_reserves($pdo,$original);
    m4o_expect(count($original['items'])===3&&count($parents)===2&&m4pc_integer($original['ledger']['cancel_at'],1,4294967295)>0,
        'CANCEL_ORIGINAL_PERSISTENT_THREE_ITEMS_TWO_PARENTS');
    $GLOBALS['m4o_phase']='cancel_second_native_customer_purchase';$keeper=m4cn_keeper($app,$pdo,$original,$parents);
    $GLOBALS['m4o_phase']='cancel_actual_customer_attempt';$attempt=m4cn_attempt($app,$pdo,$driver,$original);
    $before=m4cn_capture($pdo);$started=time();
    $result=$service->cancelForCustomer(m4pc_integer($original['order']['uid'],1),$original['order']['order_id']);$ended=time();
    m4o_expect($result===['state'=>'cancelled','order_id'=>$original['order']['order_id']],'CANCEL_ACTUAL_CUSTOMER_RESULT');
    $customer=m4cn_verify_cancel($app,$pdo,$original,$parents,$before,'customer',m4pc_integer($original['order']['uid'],1),$started,$ended,$driver,$attempt);
    $keeperAfter=m4pc_order($pdo,$keeper['proof']['order_id'],1);
    m4o_expect($keeperAfter===$keeper['saved']&&m4cn_reserves($pdo,$keeperAfter)===$keeper['parents'],'CANCEL_KEEPER_ORDER_AND_RESERVE_STILL_HELD');
    $skuAfter=m4cn_native_row($pdo,'eb_store_product_attr_value','id',$keeper['sku_id']);
    $rootAfter=m4cn_native_row($pdo,'eb_store_product','id',$keeper['product_id']);$history=null;
    foreach($parents as $parent)if($parent['group']['sku_id']===$keeper['sku_id'])$history=$parent['row'];
    m4o_expect(is_array($history)&&m4pc_integer($skuAfter['stock'],0,4294967295)===m4pc_integer($history['sku_stock_before'],1,4294967295)-1
        &&m4pc_integer($rootAfter['stock'],0,16777215)===m4pc_integer($history['root_stock_before'],1,16777215)-1,
        'CANCEL_CURRENT_DELTA_DOES_NOT_RESTORE_HISTORICAL_ABSOLUTE_STOCK');
    m4cn_replay($app,$pdo,$service,$original,$driver,$attempt,false);
    $customer+=['repeat_unchanged'=>true,'keeper_unchanged'=>true,'reserve_history_not_restored'=>true];
    $GLOBALS['m4o_phase']='cancel_saved_zero_deadline_not_due';
    $member=m4pc_order($pdo,$originalMemberProofs['paid']['order_id'],1);
    m4o_expect(m4pc_integer($member['order']['paid'],0,0)===0&&m4pc_integer($member['ledger']['cancel_at'],0,0)===0,
        'CANCEL_PAID_MEMBERSHIP_IS_UNPAID_ORDER_WITH_DISABLED_TIMEOUT');
    $facts=m4o_facts($pdo);$notDue=$service->cancelExpired(m4pc_integer($member['order']['id'],1));m4pc_context($app,$pdo);
    m4o_expect($notDue===['state'=>'not_due','order_id'=>$member['order']['order_id']]&&m4o_facts($pdo)===$facts,
        'CANCEL_REAL_ZERO_DEADLINE_NOT_DUE_NO_DATA_WRITE');
    $disabled=['verified'=>true,'order_id'=>m4pc_integer($member['order']['id'],1),'state'=>'not_due','cancel_at'=>0,'facts_unchanged'=>true];
    $GLOBALS['m4o_phase']='cancel_positive_subsecond_fresh_creation';$extraContexts=[];
    $tinyProof=m4o_service_case($app,'persistent',$extraContexts,'0.0000001',0);
    $tiny=m4pc_order($pdo,$tinyProof['order_id'],1);$tinyParents=m4cn_reserves($pdo,$tiny);
    m4o_expect(count($tiny['items'])===3&&count($tinyParents)===2&&m4pc_integer($tiny['ledger']['cancel_at'],1,4294967295)
        ===m4pc_integer($tiny['order']['add_time'],1,4294967295),'CANCEL_REAL_POSITIVE_HOURS_NATURALLY_DUE');
    $tinyAttempt=m4cn_attempt($app,$pdo,$driver,$tiny);
    m4o_expect($tinyAttempt['attempt_ref']!==$attempt['attempt_ref'],'CANCEL_DISTINCT_REAL_DRIVER_ATTEMPTS');
    $mer=m4pc_integer($tiny['order']['mer_id'],1,4294967295);$merchant=m4cn_native_row($pdo,'eb_marketplace_merchant','mer_id',$mer);
    CancelNormalDb::transaction(static function(\think\db\ConnectionInterface $connection)use($mer):void{
        $native=m4o_business_pdo($connection);$statement=$native->prepare('UPDATE `eb_marketplace_merchant` SET `accept_orders` = 0 WHERE `mer_id` = ? AND `accept_orders` = 1');
        m4o_expect($statement instanceof PDOStatement&&$statement->bindValue(1,(string)$mer,PDO::PARAM_STR)
            &&$statement->execute()&&$statement->rowCount()===1,'CANCEL_DISABLE_ONLY_OWN_ACTUAL_MERCHANT');
    });
    $disabledMerchant=m4cn_native_row($pdo,'eb_marketplace_merchant','mer_id',$mer);$wanted=$merchant;$wanted['accept_orders']=0;
    m4o_expect(m4pc_row_text($disabledMerchant)===m4pc_row_text($wanted),'CANCEL_OWN_MERCHANT_DISABLE_INDEPENDENT_READBACK');
    $due=m4o_rows($pdo,'SELECT `o`.`id` FROM `eb_store_order` AS `o` INNER JOIN `eb_marketplace_order_idempotency` AS `l` ON `l`.`order_id` = `o`.`id`'
        .' WHERE `o`.`marketplace_trade_version` = 1 AND `o`.`paid` = 0 AND `o`.`is_cancel` = 0 AND `l`.`state` = 1 AND `l`.`cancel_at` > 0 AND `l`.`cancel_at` <= ? ORDER BY `l`.`cancel_at`,`o`.`id`',[time()]);
    m4o_expect(count($due)===1&&m4pc_integer($due[0]['id'],1)===$tinyProof['order_id'],'CANCEL_ONLY_REAL_TINY_ORDER_DUE');
    $GLOBALS['m4o_phase']='cancel_existing_order_cancel_handler';$before=m4cn_capture($pdo);$started=time();
    $runner=$app->make(CancelNormalRunner::class);
    m4o_expect(($runner->markList['orderCancel']??null)==='未支付自动取消订单'&&method_exists($runner,'orderCancel'),'CANCEL_REAL_EXISTING_TIMER_MARK');
    $runner->orderCancel();$ended=time();
    $expiry=m4cn_verify_cancel($app,$pdo,$tiny,$tinyParents,$before,'system',null,$started,$ended,$driver,$tinyAttempt);
    m4o_expect(m4cn_native_row($pdo,'eb_marketplace_merchant','mer_id',$mer)===$disabledMerchant,'CANCEL_EXPIRY_KEEPS_MERCHANT_DISABLED');
    m4cn_replay($app,$pdo,$service,$tiny,$driver,$tinyAttempt,true);
    $expiry+=['repeat_unchanged'=>true,'merchant_disabled'=>true,'positive_hours_truncated_seconds'=>0,
        'saved_deadline_equals_add_time'=>true,'existing_handler_verified'=>true];
    $keeperFinal=m4pc_order($pdo,$keeper['proof']['order_id'],1);
    m4o_expect($keeperFinal===$keeper['saved']&&m4cn_reserves($pdo,$keeperFinal)===$keeper['parents'],'CANCEL_KEEPER_REMAINS_HELD_AFTER_EXPIRY_HANDLER');
    m4pc_context($app,$pdo);audit_guard_assert();
    m4o_expect($app->config->get('marketplace.trade.enabled')===false,'CANCEL_CREATE_GATE_STAYS_CLOSED');
    return ['verified'=>true,'scope'=>'NORMAL_CANCEL_INTERNAL_TEST_DRIVER_ONLY','customer'=>$customer,
        'disabled_deadline'=>$disabled,'expiry'=>$expiry,'keeper'=>$keeper['proof'],
        'test_driver'=>['scope'=>'testing_process_only','known_nonempty'=>true,'attempt_count'=>2,'closed_count'=>2,
            'account_source'=>'test_config','account_ids_null'=>true]];
}
