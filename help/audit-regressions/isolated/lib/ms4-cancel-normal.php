<?php
declare(strict_types=1);

use app\dao\marketplace\payment\MarketplacePaymentDao as CancelNormalPaymentDao;
use app\services\marketplace\payment\MarketplacePaymentServices as CancelNormalPayments;
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
function m4cn_u64(mixed $value,bool $zero=false): string
{
    m4o_expect((is_int($value)||is_string($value))&&(($zero&&(string)$value==='0')||preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$value)===1)
        &&bccomp((string)$value,'18446744073709551615',0)<=0,'CANCEL_NATIVE_U64');
    return (string)$value;
}

function m4cn_text_rows(array $rows): array
{
    return array_map('m4pc_row_text',$rows);
}

const M4CN_REGISTRY_FIELDS=['order_id','uid','mer_id','payment_contract_version','born_at','attempt_generation',
    'winner_attempt_id','fulfillment_hold','hold_reason','hold_attempt_id','hold_at'];
const M4CN_ATTEMPT_FIELDS=['id','order_id','uid','mer_id','payment_contract_version','admitted_generation','order_no',
    'idem_key','amount','currency','account_snapshot','request_digest','binding_digest','account_domain_digest',
    'created_at','attempt_ref','applied_trade_no','evidence'];

function m4cn_keys(array $value,array $fields): void
{
    $keys=array_keys($value);sort($keys,SORT_STRING);sort($fields,SORT_STRING);
    m4o_expect($keys===$fields,'CANCEL_PAYMENT_COMPLETE_FIELD_SET');
}
function m4cn_object(mixed $bytes): array
{
    m4o_expect(is_string($bytes),'CANCEL_PAYMENT_NATIVE_JSON_TEXT');
    $node=json_decode($bytes,false,64,JSON_THROW_ON_ERROR);
    m4o_expect($node instanceof stdClass,'CANCEL_PAYMENT_REAL_JSON_OBJECT');
    $shape=static function(mixed $value)use(&$shape): mixed{
        if($value instanceof stdClass){
            $fields=get_object_vars($value);
            m4o_expect(!array_is_list($fields),'CANCEL_PAYMENT_NONEMPTY_NAMED_JSON_OBJECT');
            foreach($fields as $key=>$child)$fields[$key]=$shape($child);
            return $fields;
        }
        if(is_array($value)){
            m4o_expect(array_is_list($value),'CANCEL_PAYMENT_REAL_JSON_ARRAY');
            foreach($value as $key=>$child)$value[$key]=$shape($child);
            return $value;
        }
        m4o_expect(is_int($value)||is_bool($value)||is_string($value)||$value===null,'CANCEL_PAYMENT_ORIGINAL_JSON_SCALAR_TYPE');
        return $value;
    };
    return $shape($node);
}
function m4cn_payment_rows(PDO $pdo): array
{
    return ['eb_marketplace_payment_registry'=>m4o_rows($pdo,'SELECT * FROM `eb_marketplace_payment_registry` ORDER BY `order_id`'),
        'eb_marketplace_payment_attempt'=>m4o_rows($pdo,'SELECT * FROM `eb_marketplace_payment_attempt` ORDER BY `id`')];
}
function m4cn_registry(array $row,array $order,string $generation): void
{
    m4cn_keys($row,M4CN_REGISTRY_FIELDS);
    foreach(['order_id'=>m4pc_integer($order['id'],1),'uid'=>m4pc_integer($order['uid'],1),
        'mer_id'=>m4pc_integer($order['mer_id'],1,4294967295),'payment_contract_version'=>1,
        'born_at'=>m4pc_integer($order['add_time'],1,4294967295),'fulfillment_hold'=>0] as $field=>$value)
        m4o_expect(m4pc_integer($row[$field],0,4294967295)===$value,'CANCEL_PAYMENT_REGISTRY_REAL_IDENTITY');
    m4o_expect(m4cn_u64($row['attempt_generation'],true)===$generation,'CANCEL_PAYMENT_REGISTRY_GENERATION');
    foreach(['winner_attempt_id','hold_reason','hold_attempt_id','hold_at'] as $field)
        m4o_expect($row[$field]===null,'CANCEL_PAYMENT_NO_WINNER_OR_HOLD');
}

/** Normalize only designated SQL numeric values for the private typed proof, never the raw table oracle. */
function m4cn_payment_attempt(array $raw,array $order,array $dto): array
{
    m4cn_keys($raw,M4CN_ATTEMPT_FIELDS);
    m4cn_keys($dto,['schema','scope','order_pk','order_no','attempt_id','admitted_generation','account_snapshot','state','create_result']);
    $id=m4pc_integer($order['id'],1);$uid=m4pc_integer($order['uid'],1);$mer=m4pc_integer($order['mer_id'],1,4294967295);
    m4o_expect($dto['schema']===1&&$dto['scope']==='testing_persisted_payment_v1'&&$dto['order_pk']===$id
        &&$dto['order_no']===$order['order_id']&&$dto['state']==='pending'&&$dto['admitted_generation']==='1'
        &&is_string($dto['attempt_id'])&&m4cn_u64($dto['attempt_id'])===$dto['attempt_id'],
        'CANCEL_REAL_NINE_FIELD_PENDING_DTO');
    $row=$raw;$row['id']=m4cn_u64($raw['id']);$row['admitted_generation']=m4cn_u64($raw['admitted_generation']);
    m4o_expect($row['id']===$dto['attempt_id']&&$row['admitted_generation']==='1','CANCEL_REAL_SAVED_ATTEMPT_U64');
    foreach(['order_id'=>$id,'uid'=>$uid,'mer_id'=>$mer,'payment_contract_version'=>1] as $field=>$value){
        $row[$field]=m4pc_integer($raw[$field],1,4294967295);
        m4o_expect($row[$field]===$value,'CANCEL_PAYMENT_SAVED_ATTEMPT_IDENTITY');
    }
    m4o_expect($raw['order_no']===$order['order_id']&&$raw['idem_key']==='cancel-normal:'.$id
        &&$raw['amount']===$order['pay_price']&&$raw['currency']==='CNY','CANCEL_SAVED_ATTEMPT_ORIGINAL_INTENT');
    $row['created_at']=m4pc_integer($raw['created_at'],1,4294967295);
    $account=m4cn_object($raw['account_snapshot']);
    m4cn_keys($account,['source','account_id','account_ref','version','mer_id','channel','currency','verification_scope']);
    m4o_expect($account===$dto['account_snapshot']||CancelNormalInput::canonical($account)===CancelNormalInput::canonical($dto['account_snapshot']),
        'CANCEL_SAVED_ACCOUNT_ACTUAL_DTO_SNAPSHOT');
    m4o_expect($account['source']==='test_config'&&$account['account_id']===null&&$account['version']===1&&$account['mer_id']===$mer
        &&$account['account_ref']==='test:cancel:'.getenv('AUDIT_RUN_ID').':'.$mer&&$account['channel']==='test'
        &&$account['currency']==='CNY'&&$account['verification_scope']==='test_driver_only','CANCEL_COMPLETE_REAL_TEST_ACCOUNT');
    $row['account_snapshot']=$account;
    $binding=['order_pk'=>$id,'order_no'=>$order['order_id'],'uid'=>$uid,'mer_id'=>$mer,'amount'=>$order['pay_price'],
        'currency'=>'CNY','channel'=>'test','account_source'=>'test_config','account_id'=>null,
        'account_ref'=>$account['account_ref'],'account_version'=>1];
    foreach(['request_digest'=>['binding'=>$binding,'idempotency_key'=>$raw['idem_key']],
        'binding_digest'=>$binding,'account_domain_digest'=>['source'=>'test_config','channel'=>'test','account_ref'=>$account['account_ref']]] as $field=>$value)
        m4o_expect(is_string($raw[$field])&&strlen($raw[$field])===32
            &&hash_equals(hash('sha256',CancelNormalInput::canonical($value),true),$raw[$field]),'CANCEL_COMPLETE_NATIVE_PAYMENT_DIGEST');
    $descriptor=$dto['create_result'];m4o_expect(is_array($descriptor),'CANCEL_ACTUAL_CREATE_DESCRIPTOR');
    m4cn_keys($descriptor,['state','scope','attempt_ref','binding_sha256','binding','provider_trade_no']);
    m4o_expect($descriptor['state']==='pending'&&$descriptor['scope']==='testing_process_only'&&$descriptor['provider_trade_no']===null
        &&is_string($raw['attempt_ref'])&&preg_match('/\Atest-attempt:[a-f0-9]{32}\z/D',$raw['attempt_ref'])===1
        &&$descriptor['attempt_ref']===$raw['attempt_ref']&&$descriptor['binding_sha256']===bin2hex($raw['binding_digest'])
        &&$descriptor['binding']===$binding&&$raw['applied_trade_no']===null,'CANCEL_ORIGINAL_DESCRIPTOR_ONLY_FROM_DTO');
    $evidence=m4cn_object($raw['evidence']);
    m4cn_keys($evidence,['schema','observations','first_closed','first_succeeded','application','exceptions']);
    m4o_expect($evidence['schema']===1&&is_array($evidence['observations'])&&array_is_list($evidence['observations'])
        &&count($evidence['observations'])>=1&&count($evidence['observations'])<=8&&$evidence['first_succeeded']===null
        &&$evidence['application']===null&&$evidence['exceptions']===[],'CANCEL_REAL_NORMAL_PAYMENT_ENVELOPE');
    $facts=[];$firstClosed=null;$creates=0;
    foreach($evidence['observations'] as $observation){
        m4o_expect(is_array($observation),'CANCEL_PAYMENT_OBSERVATION_OBJECT');m4cn_keys($observation,['key','received_at','fact']);
        m4o_expect(is_int($observation['received_at'])&&$observation['received_at']>=$row['created_at']
            &&$observation['received_at']<=time(),'CANCEL_REAL_OBSERVATION_TIME');
        $fact=$observation['fact'];m4o_expect(is_array($fact),'CANCEL_PAYMENT_FACT_OBJECT');
        m4cn_keys($fact,['op','attempt_id','generation','account_sha256','result']);
        m4o_expect(in_array($fact['op'],['createPayment','queryPayment','closePayment'],true)
            &&$fact['attempt_id']===$row['id']&&$fact['generation']==='1'
            &&$fact['account_sha256']===hash('sha256',CancelNormalInput::canonical($account)),'CANCEL_FULL_PAYMENT_FACT_BINDING');
        $result=$fact['result'];m4o_expect(is_array($result),'CANCEL_PAYMENT_RESULT_OBJECT');
        m4cn_keys($result,['state','scope','attempt_ref','binding_sha256','binding','provider_trade_no']);
        m4o_expect(in_array($result['state'],['pending','closed'],true)&&$result['scope']==='testing_process_only'
            &&$result['attempt_ref']===$raw['attempt_ref']&&$result['binding_sha256']===bin2hex($raw['binding_digest'])
            &&is_array($result['binding'])&&CancelNormalInput::canonical($result['binding'])===CancelNormalInput::canonical($binding)
            &&$result['provider_trade_no']===null,'CANCEL_COMPLETE_REAL_RESULT_BINDING');
        if($fact['op']==='createPayment'){
            ++$creates;m4o_expect(CancelNormalInput::canonical($result)===CancelNormalInput::canonical($descriptor),
                'CANCEL_RETAIN_ORIGINAL_PENDING_CREATE');
        }
        if($fact['op']==='closePayment')m4o_expect($result['state']==='closed','CANCEL_ONLY_REAL_CLOSED_CLOSE_RESULT');
        $key=hash('sha256',"marketplace-payment-fact:v1\n".CancelNormalInput::canonical($fact));
        m4o_expect(is_string($observation['key'])&&hash_equals($key,$observation['key'])&&!isset($facts[$key]),
            'CANCEL_INDEPENDENT_CANONICAL_FACT_KEY');$facts[$key]=$observation;
        if($result['state']==='closed'&&$firstClosed===null)$firstClosed=$key;
    }
    m4o_expect($creates===1&&$evidence['first_closed']===$firstClosed,'CANCEL_COMPLETE_CREATE_AND_FIRST_CLOSED_POINTER');
    $row['evidence']=$evidence;return ['row'=>$row,'facts'=>$facts];
}

/** Read actual proof references in a real short transaction, then independently resolve every field against native SQL. */
function m4cn_payment_proof(\think\App $app,PDO $pdo,array $saved,?array $dto): array
{
    m4pc_context($app,$pdo);$before=m4o_facts($pdo);$order=$saved['order'];$id=m4pc_integer($order['id'],1);
    $uid=m4pc_integer($order['uid'],1);$mer=m4pc_integer($order['mer_id'],1,4294967295);$dao=$app->make(CancelNormalPaymentDao::class);
    $proof=CancelNormalDb::transaction(static function(\think\db\ConnectionInterface $connection)use($dao,$uid,$id,$order):array{
        $native=m4o_business_pdo($connection);$locked=$dao->lockPayment($native,$uid,$id,$order['order_id']);
        $proof=$dao->closedCancellationProof($native,$uid,$id,$order['order_id'],$locked);
        m4o_expect(is_array($proof)&&$dao->assertCancellationProof($native,$uid,$id,$order['order_id'],$proof)===$locked,
            'CANCEL_REAL_COMPLETE_DURABLE_PROOF_READBACK');
        m4o_expect(m4o_business_pdo($connection)===$native,'CANCEL_PROOF_SAME_DEFAULT_PDO');return $proof;
    });
    m4pc_context($app,$pdo);m4o_expect(m4o_facts($pdo)===$before,'CANCEL_PROOF_READ_ALL_25_TABLES_UNCHANGED');
    m4cn_keys($proof,['schema','root','generation','attempts']);
    m4o_expect($proof['schema']===1&&$proof['root']===['id'=>$id,'uid'=>$uid,'mer_id'=>$mer,'order_no'=>$order['order_id']]
        &&$proof['generation']===($dto===null?'0':'1')&&is_array($proof['attempts'])&&array_is_list($proof['attempts']),
        'CANCEL_INDEPENDENT_PROOF_ROOT_GENERATION');
    $registries=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_payment_registry` WHERE `order_id` = ?',[$id]);
    m4o_expect(count($registries)===1,'CANCEL_PROOF_COMPLETE_REGISTRY');m4cn_registry($registries[0],$order,$proof['generation']);
    $rows=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_payment_attempt` WHERE `order_id` = ? ORDER BY `id`',[$id]);
    if($dto===null){m4o_expect($rows===[]&&$proof['attempts']===[],'CANCEL_ACTUAL_G0_EMPTY_PROOF');}
    else{
        m4o_expect(count($rows)===1&&count($proof['attempts'])===1,'CANCEL_COMPLETE_PROOF_ATTEMPT_SET');
        $checked=m4cn_payment_attempt($rows[0],$order,$dto);$row=$checked['row'];$entry=$proof['attempts'][0];
        m4o_expect(is_array($entry),'CANCEL_COMPLETE_PROOF_ENTRY');m4cn_keys($entry,['intent','attempt_ref','query_before','close','query_after']);
        m4o_expect(is_array($entry['intent'])&&$entry['attempt_ref']===$row['attempt_ref'],'CANCEL_PROOF_ORIGINAL_REFERENCE');
        $fields=array_slice(M4CN_ATTEMPT_FIELDS,0,15);m4cn_keys($entry['intent'],$fields);
        foreach($fields as $field)m4o_expect($field==='account_snapshot'
            ?CancelNormalInput::canonical($entry['intent'][$field])===CancelNormalInput::canonical($row[$field])
            :$entry['intent'][$field]===$row[$field],'CANCEL_PROOF_FULL_IMMUTABLE_INTENT');
        foreach(['query_before'=>['queryPayment',['pending','closed']],'close'=>['closePayment',['closed']],
            'query_after'=>['queryPayment',['closed']]] as $field=>[$op,$states]){
            $fact=$checked['facts'][$entry[$field]]??null;
            m4o_expect(is_array($fact)&&$fact['fact']['op']===$op&&in_array($fact['fact']['result']['state'],$states,true),
                'CANCEL_PROOF_REAL_QUERY_CLOSE_QUERY_FACT');
        }
        m4o_expect($row['evidence']['first_closed']!==null,'CANCEL_PROOF_REAL_FIRST_CLOSED');
    }
    return ['verified'=>true,'generation'=>$proof['generation'],'attempt_count'=>count($rows),'empty_proof'=>$dto===null];
}

/** Read the five exact business deltas and both complete payment tables; every other table stays fixed. */
function m4cn_capture(PDO $pdo): array
{
    $tables=[];
    foreach(['eb_store_order','eb_store_product','eb_store_product_attr_value','eb_marketplace_stock_movement','eb_store_order_status',
        'eb_marketplace_payment_registry','eb_marketplace_payment_attempt'] as $table){
        m4o_expect(in_array($table,M4O_FACT_TABLES,true),'CANCEL_CAPTURE_CLOSED_TABLE');
        $order=match($table){'eb_store_order_status'=>'`oid`,`change_type`,`change_message`,`change_time`',
            'eb_marketplace_payment_registry'=>'`order_id`',default=>'`id`'};
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
            'different_native_uid'=>true,'same_physical_sku'=>true,'cancel_at'=>0,'post_commit_verified'=>true,
            'payment_birth'=>m4o_payment_birth($pdo,$order)]];
}

/** Real service admission owns the single driver create; only its retained DTO supplies the original descriptor. */
function m4cn_attempt(\think\App $app,PDO $pdo,CancelNormalDriver $driver,array $saved): array
{
    m4pc_context($app,$pdo);$order=$saved['order'];$id=m4pc_integer($order['id'],1);$mer=m4pc_integer($order['mer_id'],1,4294967295);
    m4o_payment_birth($pdo,$order);$before=m4o_facts($pdo);$old=m4cn_payment_rows($pdo);
    $resolver=$app->make(CancelNormalAccounts::class);$reference='test:cancel:'.getenv('AUDIT_RUN_ID').':'.$mer;
    $account=$resolver->registerTestAccount($mer,$reference,1,'CNY');$resolved=$resolver->forOrder($order);
    m4o_expect($resolved===$account&&$account->source==='test_config'&&$account->account_id===null
        &&$account->account_ref===$reference&&$account->version===1&&$account->mer_id===$mer
        &&$account->channel==='test'&&$account->currency==='CNY'&&$account->verification_scope==='test_driver_only',
        'CANCEL_REAL_TEST_CONFIG_ACCOUNT');
    $started=time();$dto=$app->make(CancelNormalPayments::class)->createForCustomer(m4pc_integer($order['uid'],1),$order['order_id'],'cancel-normal:'.$id);
    $ended=time();m4pc_context($app,$pdo);
    m4o_expect($driver===$app->make(CancelNormalAdapter::class)&&$driver===$app->make(CancelNormalDriver::class),
        'CANCEL_PERSISTED_CREATE_SAME_APP_FACTORY');
    $after=m4o_facts($pdo);$actual=m4cn_payment_rows($pdo);
    foreach($before as $table=>$facts)if(!array_key_exists($table,$old))
        m4o_expect($after[$table]===$facts,'CANCEL_CREATE_ORIGINAL_23_COMPLETE_TABLES_UNCHANGED');
    $registries=$actual['eb_marketplace_payment_registry'];$oldRegistries=$old['eb_marketplace_payment_registry'];
    m4o_expect(count($registries)===count($oldRegistries),'CANCEL_CREATE_NO_REGISTRY_INSERT');$found=0;
    foreach($registries as $index=>$row){
        $original=$oldRegistries[$index];
        if(m4pc_integer($row['order_id'],1)===$id){
            ++$found;m4cn_registry($original,$order,'0');m4cn_registry($row,$order,'1');
            unset($original['attempt_generation'],$row['attempt_generation']);
        }
        m4o_expect($row===$original,'CANCEL_CREATE_ONLY_REAL_TARGET_GENERATION_CHANGED');
    }
    m4o_expect($found===1,'CANCEL_CREATE_TARGET_REGISTRY_COMPLETE');$new=null;$oldAfter=[];
    foreach($actual['eb_marketplace_payment_attempt'] as $row){
        if(m4cn_u64($row['id'])===($dto['attempt_id']??null)){
            m4o_expect($new===null,'CANCEL_CREATE_SINGLE_ACTUAL_ATTEMPT');$new=$row;
        }else $oldAfter[]=$row;
    }
    m4o_expect(is_array($new)&&$oldAfter===$old['eb_marketplace_payment_attempt'],'CANCEL_CREATE_EXACT_ONE_ATTEMPT_ALL_OLD_NATIVE_ROWS_UNCHANGED');
    foreach($old['eb_marketplace_payment_attempt'] as $row)
        m4o_expect(m4cn_u64($row['id'])!==$dto['attempt_id'],'CANCEL_CREATE_FRESH_NATIVE_U64_ID');
    $checked=m4cn_payment_attempt($new,$order,$dto);
    m4o_expect($checked['row']['created_at']>=$started&&$checked['row']['created_at']<=$ended
        &&$checked['row']['evidence']['first_closed']===null&&count($checked['facts'])===1
        &&CancelNormalInput::canonical($checked['row']['account_snapshot'])===CancelNormalInput::canonical($account->snapshot()),
        'CANCEL_REAL_SINGLE_CREATE_SAVED_BEFORE_CLOSE');
    $descriptor=$dto['create_result'];
    m4o_expect($driver->queryPayment($descriptor)===$descriptor&&$driver->attemptsForOrder($order)
        ===['state'=>'known','scope'=>'testing_process_only','attempts'=>[$descriptor]],'CANCEL_SAME_APP_NONEMPTY_KNOWN_ATTEMPTS');
    m4o_expect(m4o_facts($pdo)===$after,'CANCEL_DRIVER_ORACLE_READ_NO_EXTRA_SQL');return $dto;
}

/** Independent current-value oracle, complete preserved tables, native release identities and actor. */
function m4cn_verify_cancel(\think\App $app,PDO $pdo,array $saved,array $parents,array $before,
    string $actor,?int $actorId,int $started,int $ended,CancelNormalDriver $driver,?array $attempt): array
{
    m4pc_context($app,$pdo);$order=$saved['order'];$id=m4pc_integer($order['id'],1);$uid=m4pc_integer($order['uid'],1);
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
    m4o_expect($after['tables']['eb_marketplace_payment_registry']===$before['tables']['eb_marketplace_payment_registry'],
        'CANCEL_REGISTRY_ALL_NATIVE_ROWS_AND_GENERATIONS_UNCHANGED');
    $oldAttempts=$before['tables']['eb_marketplace_payment_attempt'];$newAttempts=$after['tables']['eb_marketplace_payment_attempt'];
    m4o_expect(count($newAttempts)===count($oldAttempts),'CANCEL_NO_ATTEMPT_INSERT_OR_DELETE');$found=0;
    foreach($newAttempts as $index=>$row){
        $original=$oldAttempts[$index];
        if($attempt!==null&&m4cn_u64($row['id'])===$attempt['attempt_id']){
            ++$found;$beforeChecked=m4cn_payment_attempt($original,$order,$attempt);$checked=m4cn_payment_attempt($row,$order,$attempt);
            $oldEvidence=$beforeChecked['row']['evidence'];$newEvidence=$checked['row']['evidence'];
            m4o_expect(array_slice($newEvidence['observations'],0,count($oldEvidence['observations']))===$oldEvidence['observations']
                &&$oldEvidence['first_closed']===null&&$newEvidence['first_closed']!==null,'CANCEL_ONLY_APPEND_REAL_CLOSE_OBSERVATIONS');
            $triplet=['pending_query'=>false,'closed_close'=>false,'closed_query'=>false];
            foreach($checked['facts'] as $key=>$observation){
                $fact=$observation['fact'];
                if(!isset($beforeChecked['facts'][$key]))m4o_expect(in_array($fact['op'],['queryPayment','closePayment'],true)
                    &&$observation['received_at']>=$started&&$observation['received_at']<=$ended,'CANCEL_REAL_NEW_OBSERVATION_TIME_AND_OPERATION');
                if($fact['op']==='queryPayment'&&$fact['result']['state']==='pending')$triplet['pending_query']=true;
                if($fact['op']==='closePayment'&&$fact['result']['state']==='closed')$triplet['closed_close']=true;
                if($fact['op']==='queryPayment'&&$fact['result']['state']==='closed')$triplet['closed_query']=true;
            }
            m4o_expect(!in_array(false,$triplet,true),'CANCEL_ACTUAL_PERSISTED_PENDING_QUERY_CLOSE_CLOSED_QUERY');
            unset($original['evidence'],$row['evidence']);
        }
        m4o_expect($row===$original,'CANCEL_ONLY_TARGET_EVIDENCE_CHANGED_ALL_NATIVE_COLUMNS');
    }
    m4o_expect($found===($attempt===null?0:1),'CANCEL_COMPLETE_TARGET_ATTEMPT_SET');
    $paymentProof=m4cn_payment_proof($app,$pdo,$saved,$attempt);
    if($attempt!==null){
        $descriptor=$attempt['create_result'];$closed=$descriptor;$closed['state']='closed';
        m4o_expect($driver->queryPayment($descriptor)===$closed&&$driver->attemptsForOrder($saved['order'])
            ===['state'=>'known','scope'=>'testing_process_only','attempts'=>[$closed]],'CANCEL_REAL_DRIVER_CLOSED_ORIGINAL_BINDING');
    }
    return ['verified'=>true,'order_id'=>$id,'item_count'=>count($saved['items']),'reserve_count'=>count($parents),
        'release_count'=>count($newMoves),'actor_type'=>$actor,'current_stock_verified'=>true,
        'saved_snapshots_unchanged'=>true,'payment_closed'=>$attempt!==null,'payment_proof'=>$paymentProof];
}

function m4cn_replay(\think\App $app,PDO $pdo,CancelNormalService $service,array $saved,
    CancelNormalDriver $driver,?array $attempt,bool $expired): void
{
    $before=m4o_facts($pdo);$descriptor=$attempt===null?null:$attempt['create_result'];
    $closed=$descriptor===null?null:$driver->queryPayment($descriptor);$order=$saved['order'];
    $result=$expired?$service->cancelExpired(m4pc_integer($order['id'],1))
        :$service->cancelForCustomer(m4pc_integer($order['uid'],1),$order['order_id']);
    m4o_expect($result===['state'=>'already_cancelled','order_id'=>$order['order_id']],'CANCEL_ORDINARY_REPLAY_SAME_ORDER');
    m4pc_context($app,$pdo);m4o_expect(m4o_facts($pdo)===$before&&($descriptor===null||$driver->queryPayment($descriptor)===$closed),
        'CANCEL_ORDINARY_REPLAY_ALL_FACTS_AND_ORIGINAL_PAYMENT_UNCHANGED');
}

/** Ordinary same-App test-driver cancellation only; no HTTP/auth, real channel, daemon or stopped samples. */
function m4o_cancel_normal(\think\App $app,PDO $pdo,array $originalServiceProofs,array $originalMemberProofs): array
{
    m4pc_context($app,$pdo);audit_guard_assert();
    m4o_expect(getenv('APP_ENV')==='testing'&&getenv('PHP_MARKETPLACE_PAYMENT_TEST_ENABLED')==='true'
        &&$app->config->get('marketplace.payment.test_enabled')===true
        &&$app->config->get('marketplace.trade.enabled')===m4o_trade_expected(),'CANCEL_ACTUAL_DECLARED_TESTING_ROLE_TRADE_CONFIGURATION');
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
    $GLOBALS['m4o_phase']='cancel_pending_ninety_second_creation';$pendingContexts=[];
    $pendingProof=m4o_service_case($app,'persistent',$pendingContexts,'0.025',90);
    $pending=m4pc_order($pdo,$pendingProof['order_id'],1);$pendingParents=m4cn_reserves($pdo,$pending);
    $pendingDeadline=m4pc_integer($pending['ledger']['cancel_at'],1,4294967295);
    m4o_expect($pendingDeadline===m4pc_integer($pending['order']['add_time'],1,4294967295)+90,
        'CANCEL_REAL_SAVED_NINETY_SECOND_DEADLINE');
    $pendingAttempt=m4cn_attempt($app,$pdo,$driver,$pending);$admittedAt=time();
    m4o_expect($admittedAt<$pendingDeadline&&$pendingAttempt['create_result']['attempt_ref']!==$attempt['create_result']['attempt_ref'],
        'CANCEL_DISTINCT_REAL_PENDING_ADMITTED_BEFORE_DEADLINE');
    $dueSql='SELECT `o`.`id` FROM `eb_store_order` AS `o` INNER JOIN `eb_marketplace_order_idempotency` AS `l` ON `l`.`order_id` = `o`.`id`'
        .' WHERE `o`.`marketplace_trade_version` = 1 AND `o`.`paid` = 0 AND `o`.`is_cancel` = 0 AND `l`.`state` = 1 AND `l`.`cancel_at` > 0 AND `l`.`cancel_at` <= ? ORDER BY `l`.`cancel_at`,`o`.`id`';
    m4o_expect(m4o_rows($pdo,$dueSql,[$admittedAt])===[],'CANCEL_PENDING_NOT_DUE_BEFORE_REAL_WAIT');
    $waitFacts=m4o_facts($pdo);$waitStarted=time();$waitSegments=[];$lastClock=$waitStarted;
    $GLOBALS['m4o_phase']='cancel_pending_natural_wallclock_wait';
    while(($remaining=$pendingDeadline-time())>0){
        $clock=time();m4o_expect($clock>=$lastClock&&count($waitSegments)<90,'CANCEL_NATURAL_CLOCK_FORWARD_BOUNDED_WAIT');
        $seconds=min(5,$remaining);m4o_expect(sleep($seconds)===0,'CANCEL_REAL_WAIT_NOT_INTERRUPTED');$returned=time();
        m4o_expect($returned>=$clock,'CANCEL_REAL_WAIT_CLOCK_NOT_REWOUND');
        $waitSegments[]=['started'=>$clock,'requested_seconds'=>$seconds,'returned'=>$returned];$lastClock=$returned;
    }
    $waitEnded=time();m4pc_context($app,$pdo);audit_guard_assert();
    m4o_expect($waitEnded>=$pendingDeadline&&m4o_facts($pdo)===$waitFacts,'CANCEL_NATURAL_DEADLINE_NO_SQL_MUTATION');
    $due=m4o_rows($pdo,$dueSql,[$waitEnded]);
    m4o_expect(count($due)===1&&m4pc_integer($due[0]['id'],1)===$pendingProof['order_id'],
        'CANCEL_ONLY_REAL_PENDING_ORDER_NATURALLY_DUE');
    $GLOBALS['m4o_phase']='cancel_pending_existing_handler';$before=m4cn_capture($pdo);$started=time();
    $pendingRunner=$app->make(CancelNormalRunner::class);
    m4o_expect(($pendingRunner->markList['orderCancel']??null)==='未支付自动取消订单'&&method_exists($pendingRunner,'orderCancel'),
        'CANCEL_PENDING_REAL_EXISTING_TIMER_MARK');
    $pendingRunner->orderCancel();$ended=time();
    $pendingExpiry=m4cn_verify_cancel($app,$pdo,$pending,$pendingParents,$before,'system',null,$started,$ended,$driver,$pendingAttempt);
    m4cn_replay($app,$pdo,$service,$pending,$driver,$pendingAttempt,true);
    $pendingExpiry+=['repeat_unchanged'=>true,'configured_seconds'=>90,'saved_deadline'=>$pendingDeadline,
        'admitted_at'=>$admittedAt,'wait_started'=>$waitStarted,'wait_ended'=>$waitEnded,'wait_segments'=>$waitSegments,
        'natural_wallclock_due'=>true,'existing_handler_verified'=>true];
    $GLOBALS['m4o_phase']='cancel_pending_restore_own_configuration';$configBefore=m4o_facts($pdo);
    $configRows=m4o_rows($pdo,'SELECT * FROM `eb_system_config` ORDER BY `id`');
    CancelNormalDb::transaction(static function(\think\db\ConnectionInterface $connection):void{
        $native=m4o_business_pdo($connection);
        $rows=m4o_rows($native,'SELECT `id`,`value` FROM `eb_system_config` WHERE `menu_name` = ? FOR UPDATE',['order_cancel_time']);
        m4o_expect(count($rows)===1&&$rows[0]['value']===json_encode('0.025',JSON_THROW_ON_ERROR),'CANCEL_NINETY_CONFIG_ACTUAL_BEFORE_RESET');
        $zero=json_encode('0',JSON_THROW_ON_ERROR);
        $statement=$native->prepare('UPDATE `eb_system_config` SET `value` = ? WHERE `id` = ? AND `menu_name` = ? AND `value` = ?');
        m4o_expect($statement instanceof PDOStatement,'CANCEL_NINETY_CONFIG_RESET_PREPARE');
        foreach([$zero,(string)$rows[0]['id'],'order_cancel_time',$rows[0]['value']] as $i=>$value)
            m4o_expect($statement->bindValue($i+1,$value,PDO::PARAM_STR),'CANCEL_NINETY_CONFIG_RESET_BIND');
        m4o_expect($statement->execute()&&$statement->rowCount()===1,'CANCEL_NINETY_CONFIG_REAL_CAS_RESET');
        m4o_expect(m4o_rows($native,'SELECT `value` FROM `eb_system_config` WHERE `id` = ?',[$rows[0]['id']])===[['value'=>$zero]]
            &&m4o_business_pdo($connection)===$native,'CANCEL_NINETY_CONFIG_RESET_REAL_READBACK');
    });
    $changed=0;foreach($configRows as &$row)if($row['menu_name']==='order_cancel_time'){
        ++$changed;m4o_expect($row['value']===json_encode('0.025',JSON_THROW_ON_ERROR),'CANCEL_RESET_ONLY_KNOWN_OWN_CONFIG');
        $row['value']=json_encode('0',JSON_THROW_ON_ERROR);
    }unset($row);
    m4o_expect($changed===1&&m4o_rows($pdo,'SELECT * FROM `eb_system_config` ORDER BY `id`')===$configRows,
        'CANCEL_RESET_ONE_VALUE_ALL_NATIVE_CONFIG_ROWS');
    foreach(m4o_facts($pdo) as $table=>$facts)if($table!=='eb_system_config')
        m4o_expect($facts===$configBefore[$table],'CANCEL_RESET_OTHER_24_TABLES_UNCHANGED');
    CancelNormalCache::delete('system_config_order_cancel_time');
    m4o_expect(sys_config('order_cancel_time')==='0','CANCEL_RESET_EXACT_CACHE_REAL_GETTER');
    $GLOBALS['m4o_phase']='cancel_positive_subsecond_fresh_creation';$extraContexts=[];
    $tinyProof=m4o_service_case($app,'persistent',$extraContexts,'0.0000001',0);
    $tiny=m4pc_order($pdo,$tinyProof['order_id'],1);$tinyParents=m4cn_reserves($pdo,$tiny);
    m4o_expect(count($tiny['items'])===3&&count($tinyParents)===2&&m4pc_integer($tiny['ledger']['cancel_at'],1,4294967295)
        ===m4pc_integer($tiny['order']['add_time'],1,4294967295),'CANCEL_REAL_POSITIVE_HOURS_NATURALLY_DUE');
    $tinyBirth=m4o_payment_birth($pdo,$tiny['order']);
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
    $expiry=m4cn_verify_cancel($app,$pdo,$tiny,$tinyParents,$before,'system',null,$started,$ended,$driver,null);
    m4o_expect(m4cn_native_row($pdo,'eb_marketplace_merchant','mer_id',$mer)===$disabledMerchant,'CANCEL_EXPIRY_KEEPS_MERCHANT_DISABLED');
    m4cn_replay($app,$pdo,$service,$tiny,$driver,null,true);
    $expiry+=['repeat_unchanged'=>true,'merchant_disabled'=>true,'positive_hours_truncated_seconds'=>0,
        'saved_deadline_equals_add_time'=>true,'existing_handler_verified'=>true,'payment_birth'=>$tinyBirth];
    $keeperFinal=m4pc_order($pdo,$keeper['proof']['order_id'],1);
    m4o_expect($keeperFinal===$keeper['saved']&&m4cn_reserves($pdo,$keeperFinal)===$keeper['parents'],'CANCEL_KEEPER_REMAINS_HELD_AFTER_EXPIRY_HANDLER');
    m4pc_context($app,$pdo);audit_guard_assert();
    m4o_expect($app->config->get('marketplace.trade.enabled')===m4o_trade_expected(),'CANCEL_ROLE_TRADE_EXPECTATION_RETAINED');
    return ['verified'=>true,'scope'=>'NORMAL_CANCEL_INTERNAL_TEST_DRIVER_ONLY','customer'=>$customer,
        'disabled_deadline'=>$disabled,'pending_expiry'=>$pendingExpiry,'expiry'=>$expiry,'keeper'=>$keeper['proof'],
        'test_driver'=>['scope'=>'testing_process_only','known_nonempty'=>true,'attempt_count'=>count([$attempt,$pendingAttempt]),
            'closed_count'=>$customer['payment_proof']['attempt_count']+$pendingExpiry['payment_proof']['attempt_count'],
            'account_source'=>'test_config','account_ids_null'=>true]];
}
