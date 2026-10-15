<?php
declare(strict_types=1);

use app\services\marketplace\EmployeeAuthServices as MerchantReadNormalAuth;
use app\services\marketplace\context\MerchantContext as MerchantReadNormalContext;
use app\services\marketplace\order\MerchantOrderServices as MerchantReadNormalOrders;

/** Ordinary real credentials and saved SQL reads only. This does not prove HTTP or browser behavior. */
function m4mr_integer(mixed $value,int $minimum=0,int $maximum=2147483647): int
{
    m4o_expect((is_int($value)||is_string($value))
        &&preg_match('/\A(?:0|-?[1-9][0-9]{0,9})\z/D',(string)$value)===1
        &&(int)$value>=$minimum&&(int)$value<=$maximum,'MERCHANT_READ_NATIVE_INTEGER');
    return (int)$value;
}

function m4mr_text(mixed $value): string
{
    m4o_expect(is_string($value)&&preg_match('//u',$value)===1&&!str_contains($value,"\0"),'MERCHANT_READ_SAVED_TEXT');
    return $value;
}

/** Dictionary key order is immaterial; list order, public key sets and scalar types are exact. */
function m4mr_same(mixed $actual,mixed $expected): bool
{
    if(!is_array($expected))return $actual===$expected;
    if(!is_array($actual)||array_is_list($actual)!==array_is_list($expected)||count($actual)!==count($expected))return false;
    $a=array_keys($actual);$b=array_keys($expected);sort($a,SORT_STRING);sort($b,SORT_STRING);
    if($a!==$b)return false;
    foreach($expected as $key=>$value)if(!m4mr_same($actual[$key],$value))return false;
    return true;
}

/** JSON columns are compared as actual typed JSON, without changing the shared byte-exact fixture writer. */
function m4mr_role(PDO $pdo,int $merId,string $name,array $permissions,int $now): array
{
    $json=json_encode($permissions,JSON_THROW_ON_ERROR);
    $statement=$pdo->prepare('INSERT INTO `eb_marketplace_role` (`mer_id`,`name`,`permissions`,`add_time`,`update_time`) VALUES (?,?,?,?,?)');
    m4o_expect($statement instanceof PDOStatement,'MERCHANT_READ_NATIVE_ROLE_PREPARE');
    foreach([$merId,$name,$json,$now,$now] as $i=>$value)
        m4o_expect($statement->bindValue($i+1,(string)$value,PDO::PARAM_STR),'MERCHANT_READ_NATIVE_ROLE_BIND');
    m4o_expect($statement->execute(),'MERCHANT_READ_NATIVE_ROLE_INSERT');
    $id=$pdo->lastInsertId();
    m4o_expect($statement->rowCount()===1&&is_string($id)&&preg_match('/\A[1-9][0-9]*\z/D',$id)===1,'MERCHANT_READ_NATIVE_ROLE_ID');
    $nativeId=m4mr_integer($id,1,4294967295);
    $rows=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_role` WHERE `id` = ?',[$id]);
    m4o_expect(count($rows)===1&&m4mr_integer($rows[0]['id'],1,4294967295)===$nativeId
        &&m4mr_integer($rows[0]['mer_id'],1,4294967295)===$merId&&$rows[0]['name']===$name
        &&m4mr_integer($rows[0]['add_time'],1,4294967295)===$now
        &&m4mr_integer($rows[0]['update_time'],1,4294967295)===$now
        &&is_string($rows[0]['permissions'])
        &&json_decode($rows[0]['permissions'],true,32,JSON_THROW_ON_ERROR)===$permissions,'MERCHANT_READ_NATIVE_ROLE_TYPED_JSON_READBACK');
    return $rows[0];
}

/** Three real actors: existing owned store, existing stopped store and one new empty store. */
function m4mr_fixtures(PDO $pdo,int $ownedMer,int $stoppedMer): array
{
    m4o_expect(!$pdo->inTransaction()&&$ownedMer!==$stoppedMer,'MERCHANT_READ_DISTINCT_EXISTING_STORES');
    $who=m4o_rows($pdo,'SELECT CURRENT_USER() AS `current_user`');
    m4o_expect(count($who)===1&&is_string($who[0]['current_user'])
        &&str_starts_with($who[0]['current_user'],'audit_app@'),'MERCHANT_READ_FIXTURE_DML_ACCOUNT');
    $permissions=['merchant.profile.read','order.read'];$now=time();$fixtures=[];
    $pdo->beginTransaction();
    try{
        $empty=m4o_insert($pdo,'eb_marketplace_merchant',['name'=>'普通查单空店','review_status'=>1,'accept_orders'=>0,
            'fulfillment_enabled'=>0,'security_locked'=>0,'closed_at'=>null,'add_time'=>$now,'update_time'=>$now],'mer_id');
        $emptyMer=m4mr_integer($empty['mer_id'],1,4294967295);
        m4o_expect(!in_array($emptyMer,[$ownedMer,$stoppedMer],true),'MERCHANT_READ_NATIVE_EMPTY_STORE');
        foreach(['owned'=>$ownedMer,'stopped'=>$stoppedMer,'empty'=>$emptyMer] as $label=>$merId){
            $suffix=bin2hex(random_bytes(8));$account='ms4_read_'.$label.'_'.$suffix;
            $role=m4mr_role($pdo,$merId,'普通查单 '.$label.' '.$suffix,$permissions,$now);
            $employee=m4o_insert($pdo,'eb_marketplace_employee',['mer_id'=>$merId,'role_id'=>$role['id'],'account'=>$account,
                'pwd'=>null,'name'=>'普通查单','avatar'=>'','status'=>1,'is_del'=>0,'add_time'=>$now,'update_time'=>$now]);
            $capability=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $password='Ms4Read-'.bin2hex(random_bytes(24));
            $setup=m4o_insert($pdo,'eb_marketplace_password_setup',['mer_id'=>$merId,'employee_id'=>$employee['id'],
                'token_hash'=>hash('sha256',$capability,true),'expires_at'=>$now+86400,'consumed_at'=>null,'add_time'=>$now]);
            m4o_expect(m4mr_integer($employee['mer_id'],1,4294967295)===$merId
                &&m4mr_integer($employee['role_id'],1,4294967295)===m4mr_integer($role['id'],1,4294967295)
                &&m4mr_integer($setup['mer_id'],1,4294967295)===$merId
                &&m4mr_integer($setup['employee_id'],1,4294967295)===m4mr_integer($employee['id'],1,4294967295),
                'MERCHANT_READ_REAL_FIXTURE_PARENT_REFERENCES');
            $merchant=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_merchant` WHERE `mer_id` = ?',[$merId]);
            m4o_expect(count($merchant)===1&&m4mr_integer($merchant[0]['review_status'],1,1)===1
                &&m4mr_integer($merchant[0]['security_locked'],0,0)===0&&$merchant[0]['closed_at']===null,
                'MERCHANT_READ_REAL_ELIGIBLE_STORE');
            $fixtures[$label]=compact('merId','role','employee','setup','capability','password','permissions');
        }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    return $fixtures;
}

/** Actual setup/login/authenticate; capability, password, JWT and context stay in memory. */
function m4mr_actor(\think\App $app,PDO $pdo,MerchantReadNormalAuth $authentication,array $fixture): MerchantReadNormalContext
{
    $started=time();$authentication->setupPassword($fixture['capability'],$fixture['password']);$ended=time();
    m4pc_context($app,$pdo);
    $employees=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_employee` WHERE `id` = ? AND `mer_id` = ?',
        [$fixture['employee']['id'],$fixture['merId']]);
    $setups=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_password_setup` WHERE `id` = ? AND `mer_id` = ?',
        [$fixture['setup']['id'],$fixture['merId']]);
    $roles=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_role` WHERE `id` = ? AND `mer_id` = ?',
        [$fixture['role']['id'],$fixture['merId']]);
    m4o_expect(count($employees)===1&&count($setups)===1&&count($roles)===1,'MERCHANT_READ_ACTUAL_SETUP_ROWS');
    $employee=$employees[0];$setup=$setups[0];
    m4o_expect(is_string($employee['pwd'])&&password_verify($fixture['password'],$employee['pwd'])
        &&m4mr_integer($employee['update_time'],1,4294967295)>=$started
        &&m4mr_integer($employee['update_time'],1,4294967295)<=$ended
        &&m4mr_integer($setup['consumed_at'],1,4294967295)>=$started
        &&m4mr_integer($setup['consumed_at'],1,4294967295)<=$ended,'MERCHANT_READ_REAL_PASSWORD_AND_CAPABILITY_CONSUMED');
    $expectedEmployee=$fixture['employee'];$expectedEmployee['pwd']=$employee['pwd'];$expectedEmployee['update_time']=$employee['update_time'];
    $expectedSetup=$fixture['setup'];$expectedSetup['consumed_at']=$setup['consumed_at'];
    m4o_expect(m4pc_row_text($employee)===m4pc_row_text($expectedEmployee)
        &&m4pc_row_text($setup)===m4pc_row_text($expectedSetup)
        &&m4pc_row_text($roles[0])===m4pc_row_text($fixture['role']),'MERCHANT_READ_ONLY_ACTUAL_NEW_SETUP_FIELDS_CHANGED');
    $before=m4o_facts($pdo);$issued=$authentication->login($employee['account'],$fixture['password']);
    $keys=array_keys($issued);sort($keys,SORT_STRING);
    m4o_expect($keys===['exp','token']&&is_string($issued['token'])&&$issued['token']!==''
        &&is_int($issued['exp'])&&$issued['exp']>time(),'MERCHANT_READ_REAL_CREDENTIAL_SESSION');
    $ctx=$authentication->authenticate($issued['token']);
    m4o_expect($ctx instanceof MerchantReadNormalContext&&$ctx->merId===$fixture['merId']
        &&$ctx->employeeId===m4mr_integer($employee['id'],1,4294967295)
        &&$ctx->permissions===$fixture['permissions']&&$ctx->can('order.read')&&$ctx->can('merchant.profile.read')
        &&$ctx->sessionVersion!==''&&$ctx->requestId!=='','MERCHANT_READ_ACTUAL_AUTHENTICATED_CONTEXT');
    m4o_expect(m4o_facts($pdo)===$before,'MERCHANT_READ_LOGIN_AUTHENTICATE_SQL_UNCHANGED');
    return $ctx;
}

/** Independently reconstruct only public saved form fields; never call the projection under verification. */
function m4mr_forms(mixed $json,array $productIds): array
{
    m4o_expect(is_string($json)&&strlen($json)<=65535,'MERCHANT_READ_SAVED_FORM_JSON');
    $groups=json_decode($json,true,32,JSON_THROW_ON_ERROR);$result=[];$seen=[];
    m4o_expect(is_array($groups)&&array_is_list($groups),'MERCHANT_READ_SAVED_FORM_GROUPS');
    foreach($groups as $group){
        m4o_expect(is_array($group)&&is_int($group['product_id']??null)
            &&in_array($group['product_id'],$productIds,true)&&!isset($seen[$group['product_id']])
            &&is_string($group['definition_sha256']??null)
            &&preg_match('/\A[a-f0-9]{64}\z/D',$group['definition_sha256'])===1,'MERCHANT_READ_ORIGINAL_FORM_PRODUCT_AND_HASH');
        $fields=$group['fields']??null;$public=[];
        m4o_expect(is_array($fields)&&array_is_list($fields)&&count($fields)>=1&&count($fields)<=10,'MERCHANT_READ_FORM_FIELDS');
        foreach($fields as $index=>$field){
            m4o_expect(is_array($field)&&($field['field_index']??null)===$index&&is_bool($field['status']??null)
                &&in_array($field['label']??null,['text','number','email','data','time','id','phone','img'],true),
                'MERCHANT_READ_ORIGINAL_FORM_FIELD_TYPES');
            $value=$field['value']??null;
            if($field['label']==='img'){
                m4o_expect(is_array($value)&&array_is_list($value)&&count($value)<=8,'MERCHANT_READ_FORM_IMAGE_VALUES');
                foreach($value as $url)m4mr_text($url);
            }else $value=m4mr_text($value);
            $public[]=['field_index'=>$index,'title'=>m4mr_text($field['title']??null),'label'=>$field['label'],
                'status'=>$field['status'],'value'=>$value];
        }
        $result[]=['product_id'=>$group['product_id'],'product_name'=>m4mr_text($group['product_name']??null),
            'definition_sha256'=>$group['definition_sha256'],'fields'=>$public];
        $seen[$group['product_id']]=true;
    }
    return $result;
}

/** Actual native order/item rows are the oracle; current catalog/configuration is never consulted. */
function m4mr_saved(PDO $pdo,int $merId): array
{
    $orders=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `mer_id` = ? AND `marketplace_trade_version` = 1'
        .' AND `is_system_del` = 0 AND `pid` = 0 ORDER BY `id` DESC',[$merId]);$out=[];
    foreach($orders as $order){
        $id=m4mr_integer($order['id'],1);$uid=m4mr_integer($order['uid'],1);
        m4o_expect(m4mr_integer($order['mer_id'],1,4294967295)===$merId,'MERCHANT_READ_ORACLE_REAL_ORDER_OWNER');
        $items=m4o_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id` ASC',[$id]);
        $dto=['id'=>$id,'order_id'=>m4mr_text($order['order_id']),'marketplace_trade_version'=>1,
            'add_time'=>m4mr_integer($order['add_time'],1,4294967295),'paid'=>m4mr_integer($order['paid'],0,1),
            'status'=>m4mr_integer($order['status'],-128,127),'is_cancel'=>m4mr_integer($order['is_cancel'],0,1),
            'refund_status'=>m4mr_integer($order['refund_status'],0,255),'shipping_type'=>m4mr_integer($order['shipping_type'],1,2),
            'total_num'=>m4mr_integer($order['total_num'],1,4294967295),'total_price'=>m4s_money($order['total_price']),
            'pay_price'=>m4s_money($order['pay_price']),'pay_postage'=>m4s_money($order['pay_postage']),'items'=>[]];
        $products=[];$quantity=0;$goods='0.00';$postage='0.00';
        m4o_expect(count($items)>=1,'MERCHANT_READ_ORACLE_ACTUAL_ITEMS');
        foreach($items as $item){
            m4o_expect(m4mr_integer($item['mer_id'],1,4294967295)===$merId&&m4mr_integer($item['uid'],1)===$uid
                &&m4mr_integer($item['oid'],1)===$id&&is_string($item['cart_info']),'MERCHANT_READ_ORACLE_ITEM_OWNER');
            $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            $product=$cart['productInfo']??null;$sku=$product['attrInfo']??null;$productId=m4mr_integer($item['product_id'],1,8388607);
            $qty=m4mr_integer($item['cart_num'],1);
            m4o_expect(is_array($cart)&&is_array($product)&&is_array($sku)
                &&m4mr_integer($cart['product_id']??null,1,8388607)===$productId
                &&m4mr_integer($product['id']??null,1,8388607)===$productId
                &&m4mr_integer($sku['product_id']??null,1,8388607)===$productId
                &&m4mr_integer($cart['cart_num']??null,1)===$qty
                &&m4mr_integer($cart['uid']??null,1)===$uid
                &&m4mr_integer($product['mer_id']??null,1,4294967295)===$merId,'MERCHANT_READ_ORACLE_SAVED_IDENTITY');
            $unit=m4s_money($cart['truePrice']??null);$line=m4s_money($cart['sum_true_price']??null);
            $freight=m4s_money($cart['postage_price']??null);$skuImage=m4mr_text($sku['image']??null);
            m4o_expect(bcmul($unit,(string)$qty,2)===$line,'MERCHANT_READ_ORACLE_LINE_AMOUNT');
            $dto['items'][]=['id'=>m4mr_integer($item['id'],1),'product_id'=>$productId,
                'product_name'=>m4mr_text($product['store_name']??null),'sku_name'=>m4mr_text($sku['suk']??null),
                'image'=>$skuImage!==''?$skuImage:m4mr_text($product['image']??null),'quantity'=>$qty,
                'unit_price'=>$unit,'line_price'=>$line,'postage_price'=>$freight];
            $quantity+=$qty;$goods=bcadd($goods,$line,2);$postage=bcadd($postage,$freight,2);$products[$productId]=true;
        }
        m4o_expect($quantity===$dto['total_num']&&$goods===$dto['total_price']&&$postage===$dto['pay_postage']
            &&bcadd($goods,$postage,2)===$dto['pay_price'],'MERCHANT_READ_ORACLE_ORDER_SAVED_AMOUNTS');
        $detail=$dto;$detail['recipient']=['real_name'=>m4mr_text($order['real_name']),
            'user_phone'=>m4mr_text($order['user_phone']),'user_address'=>m4mr_text($order['user_address'])];
        $detail['mark']=m4mr_text($order['mark']);$detail['custom_form']=m4mr_forms($order['custom_form'],array_keys($products));
        $out[]=['saved'=>$order,'list'=>$dto,'detail'=>$detail];
    }
    return $out;
}

/** @return array Fixed non-identity proof only; all actual credentials/DTOs remain local. */
function m4o_merchant_read_normal(\think\App $app,PDO $pdo,array $serviceProofs,array $cancellations): array
{
    $GLOBALS['m4o_phase']='merchant_read_actual_actors';m4pc_context($app,$pdo);
    m4o_expect($app->config->get('cache.default')==='redis'
        &&strtolower((string)$app->config->get('cache.stores.redis.type'))==='redis'
        &&getenv('PHP_CACHE_DRIVER')==='redis','MERCHANT_READ_REAL_REDIS_FROM_APP_START');
    m4o_expect(($serviceProofs['persistent']['verified']??null)===true&&($cancellations['verified']??null)===true
        &&is_int($serviceProofs['persistent']['order_id']??null)&&is_int($cancellations['expiry']['order_id']??null)
        &&is_int($cancellations['keeper']['order_id']??null),'MERCHANT_READ_ORIGINAL_NATIVE_ORDER_PROOFS');
    $original=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$serviceProofs['persistent']['order_id']]);
    $expired=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$cancellations['expiry']['order_id']]);
    $keeper=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$cancellations['keeper']['order_id']]);
    m4o_expect(count($original)===1&&count($expired)===1&&count($keeper)===1,'MERCHANT_READ_REAL_ORIGINAL_ROOT_ROWS');
    $ownedMer=m4mr_integer($original[0]['mer_id'],1,4294967295);$stoppedMer=m4mr_integer($expired[0]['mer_id'],1,4294967295);
    m4o_expect($ownedMer!==$stoppedMer&&m4mr_integer($keeper[0]['mer_id'],1,4294967295)===$ownedMer,
        'MERCHANT_READ_KEEPER_SAME_REAL_STORE');
    $stopped=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_merchant` WHERE `mer_id` = ?',[$stoppedMer]);
    m4o_expect(count($stopped)===1&&m4mr_integer($stopped[0]['accept_orders'],0,0)===0,'MERCHANT_READ_EXISTING_STOPPED_STORE');
    $fixtures=m4mr_fixtures($pdo,$ownedMer,$stoppedMer);$authentication=$app->make(MerchantReadNormalAuth::class);$contexts=[];
    foreach($fixtures as $label=>$fixture)$contexts[$label]=m4mr_actor($app,$pdo,$authentication,$fixture);
    $GLOBALS['m4o_phase']='merchant_saved_order_reads';$before=m4o_facts($pdo);$expected=[];
    foreach($fixtures as $label=>$fixture)$expected[$label]=m4mr_saved($pdo,$fixture['merId']);
    m4o_expect(count($expected['owned'])===2&&count($expected['stopped'])===1&&$expected['empty']===[],
        'MERCHANT_READ_ACTUAL_OWNED_STOPPED_EMPTY_COUNTS');
    $ownedIds=array_column(array_column($expected['owned'],'list'),'id');
    $actualIds=[$serviceProofs['persistent']['order_id'],$cancellations['keeper']['order_id']];rsort($actualIds,SORT_NUMERIC);
    m4o_expect($ownedIds===$actualIds&&$expected['stopped'][0]['list']['id']===$cancellations['expiry']['order_id'],
        'MERCHANT_READ_ONLY_THE_REAL_CREATED_STORE_ROOTS');
    $orders=$app->make(MerchantReadNormalOrders::class);$details=0;
    foreach($contexts as $label=>$ctx){
        $rows=array_column($expected[$label],'list');$actual=$orders->list($ctx);
        m4o_expect(m4mr_same($actual,['list'=>$rows,'count'=>count($rows)]),'MERCHANT_READ_DEFAULT_LIST_COUNT_EXACT_SAVED_DTO');
        foreach($expected[$label] as $row){
            m4o_expect(m4mr_same($orders->detail($ctx,$row['list']['id']),$row['detail']),
                'MERCHANT_READ_DETAIL_EXACT_SAVED_RECIPIENT_FORM_AND_AMOUNTS');$details++;
        }
    }
    foreach([1,2] as $page)m4o_expect(m4mr_same($orders->list($contexts['owned'],['page'=>$page,'limit'=>1]),
        ['list'=>[$expected['owned'][$page-1]['list']],'count'=>2]),'MERCHANT_READ_TWO_ACTUAL_PAGES_SAME_COUNT');
    $first=$expected['owned'][0]['list'];
    m4o_expect(m4mr_same($orders->list($contexts['owned'],['order_id'=>$first['order_id']]),['list'=>[$first],'count'=>1]),
        'MERCHANT_READ_FULL_SAVED_ORDER_NUMBER');
    foreach([['paid'=>0],['is_cancel'=>0],['is_cancel'=>1],['paid'=>0,'is_cancel'=>1]] as $filter){
        $selected=[];
        foreach($expected['owned'] as $row){$matches=true;foreach($filter as $key=>$value)if($row['list'][$key]!==$value)$matches=false;
            if($matches)$selected[]=$row['list'];}
        m4o_expect($selected!==[]&&m4mr_same($orders->list($contexts['owned'],$filter),['list'=>$selected,'count'=>count($selected)]),
            'MERCHANT_READ_NORMAL_PAID_AND_CANCELLATION_FILTERS');
    }
    m4o_expect(array_filter($expected['owned'],static fn(array $row):bool=>$row['list']['paid']===1)===[]
        &&m4mr_same($orders->list($contexts['owned'],['paid'=>1]),['list'=>[],'count'=>0]),
        'MERCHANT_READ_VALID_PAID_FILTER_ACTUAL_EMPTY_RESULT');
    m4pc_context($app,$pdo);
    m4o_expect($details===3&&m4o_facts($pdo)===$before,'MERCHANT_READ_ALL_SAVED_READ_FACTS_UNCHANGED');
    m4o_expect($app->config->get('marketplace.trade.enabled')===false,'MERCHANT_READ_CREATION_GATE_STAYS_CLOSED');
    audit_guard_assert();
    return ['verified'=>true,'scope'=>'NORMAL_MERCHANT_ORDER_INTERNAL_AUTHENTICATED_READ_ONLY',
        'actors'=>['count'=>3,'native_fixtures_verified'=>true,'credential_login_authenticated'=>true,
            'permissions_exact'=>true,'authentication_sql_unchanged'=>true],
        'orders'=>['owned_count'=>2,'stopped_count'=>1,'empty_count'=>0],'details'=>3,'dao_pages'=>2,
        'exact_order_id_verified'=>true,'filters_verified'=>true,'saved_amounts_verified'=>true,
        'recipient_verified'=>true,'forms_verified'=>true,'facts_unchanged'=>true];
}
