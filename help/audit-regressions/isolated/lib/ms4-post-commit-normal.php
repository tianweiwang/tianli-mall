<?php
declare(strict_types=1);

use app\services\marketplace\order\MarketplaceOrderInput as PostCommitNormalInput;
use app\services\marketplace\order\MarketplaceOrderPostCommitServices as PostCommitNormalServices;
use app\services\marketplace\order\MarketplaceOrderPostCommitTimer as PostCommitNormalTimer;
use app\services\system\crontab\CrontabRunServices as PostCommitNormalRunner;
use app\services\system\crontab\SystemCrontabServices as PostCommitNormalTimers;
use crmeb\services\CacheService as PostCommitNormalCache;
use think\facade\Cache as PostCommitNormalCacheFacade;
use think\facade\Db as PostCommitNormalDb;
use think\helper\Str as PostCommitNormalStr;

/** Actual native saved rows are the oracle; no HTTP/authentication actor or replacement PDO is constructed. */
function m4pc_context(\think\App $app,PDO $pdo): void
{
    $default=PostCommitNormalDb::connect()->getPdo();
    m4o_expect(app()===$app && $default instanceof PDO && $pdo!==$default && !$pdo->inTransaction()
        && !$default->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql',
        'POST_COMMIT_REAL_INDEPENDENT_PDO');
    foreach(['eb_store_order_status','eb_store_product_log','eb_system_timer'] as $table)
        m4o_expect(in_array($table,M4O_FACT_TABLES,true),'POST_COMMIT_FACT_TABLE_CLOSED');
}

function m4pc_integer(mixed $value,int $minimum=0,int $maximum=2147483647): int
{
    m4o_expect((is_int($value)||is_string($value)) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)===1,
        'POST_COMMIT_NATIVE_INTEGER_TEXT');
    $number=(int)$value;
    m4o_expect($number>=$minimum && $number<=$maximum,'POST_COMMIT_NATIVE_INTEGER_RANGE');
    return $number;
}

/** Preserve NULL and every actual column while allowing native PDO int/string representations. */
function m4pc_row_text(array $row): array
{
    $text=[];
    foreach($row as $key=>$value){
        m4o_expect(is_string($key) && ($value===null||is_int($value)||is_string($value)),
            'POST_COMMIT_NATIVE_ROW_SCALARS');
        $text[$key]=$value===null?null:(string)$value;
    }
    ksort($text,SORT_STRING);return $text;
}

/** Read identities and original time only from this committed order, its ledger and saved items. */
function m4pc_order(PDO $pdo,int $orderId,int $done): array
{
    m4pc_integer($orderId,1);m4o_expect(in_array($done,[0,1],true),'POST_COMMIT_EXPECTED_DONE_VALUE');
    $rows=m4o_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$orderId]);
    m4o_expect(count($rows)===1,'POST_COMMIT_NATIVE_ORDER');$order=$rows[0];
    $uid=m4pc_integer($order['uid'],1);$mer=m4pc_integer($order['mer_id'],1,4294967295);
    m4o_expect(m4pc_integer($order['id'],1)===$orderId && m4pc_integer($order['marketplace_trade_version'],1,1)===1
        && is_string($order['unique']) && PostCommitNormalInput::confirmationKey($order['unique'])===$order['unique'],
        'POST_COMMIT_SAVED_ORDER_IDENTITY');
    $owned=m4o_rows($pdo,'SELECT `id` FROM `eb_store_order` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    m4o_expect(count($owned)===1 && m4pc_integer($owned[0]['id'],1)===$orderId,'POST_COMMIT_FIXTURE_ACTUAL_SINGLE_ORDER_UID');
    m4pc_integer($order['add_time'],1);
    $rows=m4o_rows($pdo,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `order_id` = ?',[$orderId]);
    m4o_expect(count($rows)===1,'POST_COMMIT_NATIVE_LEDGER');$ledger=$rows[0];
    m4o_expect(m4pc_integer($ledger['uid'],1)===$uid && m4pc_integer($ledger['mer_id'],1,4294967295)===$mer
        && m4pc_integer($ledger['order_id'],1)===$orderId && m4pc_integer($ledger['state'],1,1)===1
        && m4pc_integer($ledger['post_commit_done'],0,1)===$done
        && is_string($ledger['confirmation_ref']) && hash_equals(hash('sha256',$order['unique'],true),$ledger['confirmation_ref'])
        && in_array($ledger['source_kind'],['persistent','temporary'],true),'POST_COMMIT_SAVED_LEDGER_IDENTITY');
    $refs=json_decode($ledger['cart_refs'],true,32,JSON_THROW_ON_ERROR);
    m4o_expect(is_array($refs) && array_is_list($refs) && $refs!==[]
        && json_decode($order['cart_id'],true,32,JSON_THROW_ON_ERROR)===$refs,'POST_COMMIT_SAVED_REFERENCES');
    foreach($refs as $ref)m4o_expect(is_string($ref)
        && PostCommitNormalInput::reference($ref,$ledger['source_kind'])===$ref,'POST_COMMIT_EXACT_REFERENCE_TEXT');
    m4o_expect(PostCommitNormalInput::references(implode(',',$refs),$ledger['source_kind'])===$refs,
        'POST_COMMIT_SAVED_REFERENCE_ORDER');
    $items=m4o_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$orderId]);
    m4o_expect(count($items)===count($refs),'POST_COMMIT_SAVED_ITEM_COUNT');$seen=[];
    foreach($items as $item){
        $ref=$item['cart_id'];$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);
        m4o_expect(is_string($ref) && in_array($ref,$refs,true) && !isset($seen[$ref])
            && m4pc_integer($item['id'],1)>0 && m4pc_integer($item['oid'],1)===$orderId
            && m4pc_integer($item['uid'],1)===$uid && m4pc_integer($item['mer_id'],1,4294967295)===$mer
            && is_array($cart) && ($cart['id']??null)===$ref,'POST_COMMIT_SAVED_ITEM_IDENTITY');
        $product=m4pc_integer($item['product_id'],1,8388607);$quantity=m4pc_integer($item['cart_num'],1);
        m4o_expect(m4pc_integer($cart['uid']??null,1)===$uid
            && m4pc_integer($cart['product_id']??null,1,8388607)===$product
            && m4pc_integer($cart['cart_num']??null,1)===$quantity,'POST_COMMIT_SAVED_ITEM_QUANTITY');
        $seen[$ref]=true;
    }
    return compact('order','ledger','items','refs');
}

/** Logical cache absence is checked with the real driver's has plus the real public getter. */
function m4pc_cache_absent(array $saved): int
{
    $order=$saved['order'];$keys=['user_order_'.$order['uid'].$order['unique']];
    $temporary=$saved['ledger']['source_kind']==='temporary'?$saved['refs']:[];
    foreach($temporary as $ref)$keys[]=$ref;
    foreach($keys as $key)m4o_expect(PostCommitNormalCacheFacade::has($key)===false
        && PostCommitNormalCache::get($key,null)===null,'POST_COMMIT_ACTUAL_CACHE_ABSENT');
    return count($temporary);
}

/** Normal fresh orders have exactly their saved per-item statistics, including repeated products. */
function m4pc_completed_facts(PDO $pdo,array $saved): int
{
    $order=$saved['order'];$orderId=m4pc_integer($order['id'],1);$uid=m4pc_integer($order['uid'],1);
    $created=m4o_rows($pdo,'SELECT * FROM `eb_store_order_status` WHERE `oid` = ? ORDER BY `change_type`,`change_message`,`change_time`',[$orderId]);
    m4o_expect(count($created)===1 && m4pc_row_text($created[0])===m4pc_row_text(['oid'=>$orderId,
        'change_type'=>'cache_key_create_order','change_message'=>'订单生成','change_time'=>$order['add_time']]),
        'POST_COMMIT_EXACT_SINGLE_CREATION_STATUS');
    // Each fixture has its own actual native UID and only one order; do not hide duplicates behind a time/product filter.
    $stats=m4o_rows($pdo,'SELECT * FROM `eb_store_product_log` WHERE `uid` = ? AND `type` = ? ORDER BY `id`',[$uid,'order']);
    $expected=$actual=[];
    foreach($saved['items'] as $item)$expected[]=implode(':',['order',(string)$item['product_id'],(string)$item['uid'],
        (string)$item['cart_num'],(string)$order['add_time']]);
    $ids=[];
    foreach($stats as $row){
        $id=m4pc_integer($row['id'],1);m4o_expect(!isset($ids[$id]),'POST_COMMIT_NATIVE_STAT_PK');$ids[$id]=true;
        foreach(['visit_num','cart_num','pay_num','pay_uid','refund_num','collect_num'] as $field)
            m4o_expect(m4pc_integer($row[$field],0,0)===0,'POST_COMMIT_STAT_OTHER_COUNTS_ZERO');
        foreach(['pay_price','cost_price','refund_price'] as $field)
            m4o_expect(is_string($row[$field]) && $row[$field]==='0.00','POST_COMMIT_STAT_NO_FINANCIAL_ACTION');
        m4o_expect($row['type']==='order' && m4pc_integer($row['uid'],1)===$uid,'POST_COMMIT_ORDER_STAT_TYPE_OWNER');
        $actual[]=implode(':',[$row['type'],(string)m4pc_integer($row['product_id'],1,8388607),(string)$uid,
            (string)m4pc_integer($row['order_num'],1),(string)m4pc_integer($row['add_time'],1)]);
    }
    sort($expected,SORT_STRING);sort($actual,SORT_STRING);
    m4o_expect($actual===$expected,'POST_COMMIT_EXACT_SAVED_ITEM_STAT_MULTISET');
    return count($stats);
}

/** Independently verify a real completed order, then invoke the actual ordinary finish replay. */
function m4o_post_commit_verify(\think\App $app,PDO $pdo,int $orderId): array
{
    m4pc_context($app,$pdo);$saved=m4pc_order($pdo,$orderId,1);
    $statCount=m4pc_completed_facts($pdo,$saved);$temporaryCount=m4pc_cache_absent($saved);
    $before=m4o_facts($pdo);$result=$app->make(PostCommitNormalServices::class)->finish($orderId);
    m4o_expect($result===['post_commit_done'=>1,'completed_now'=>false],'POST_COMMIT_ORDINARY_FINISH_REPLAY_RESULT');
    m4pc_context($app,$pdo);
    m4o_expect(m4o_facts($pdo)===$before,'POST_COMMIT_ORDINARY_FINISH_REPLAY_NO_DATA_WRITE');
    m4o_expect(m4pc_cache_absent($saved)===$temporaryCount,'POST_COMMIT_REPLAY_CACHE_STILL_ABSENT');
    return ['verified'=>true,'post_commit_done'=>1,'creation_status_count'=>1,'item_stat_count'=>$statCount,
        'statistics_verified'=>true,'confirmation_cache_absent'=>true,'temporary_cache_absent'=>true,
        'temporary_ref_count'=>$temporaryCount,'repeat_unchanged'=>true];
}

/** Register the real timer twice, then execute only its actual handler for the naturally pending direct order. */
function m4o_post_commit_schedule(\think\App $app,PDO $pdo,int $directId): array
{
    m4pc_context($app,$pdo);$saved=m4pc_order($pdo,$directId,0);
    m4o_expect($saved['ledger']['source_kind']==='persistent','POST_COMMIT_DIRECT_REAL_PERSISTENT_SOURCE');
    $pending=m4o_rows($pdo,'SELECT `order_id` FROM `eb_marketplace_order_idempotency` WHERE `state` = 1 AND `post_commit_done` = 0 ORDER BY `order_id`');
    m4o_expect(count($pending)===1 && m4pc_integer($pending[0]['order_id'],1)===$directId,
        'POST_COMMIT_ONLY_DIRECT_NATURALLY_PENDING');
    m4o_expect(m4o_rows($pdo,'SELECT * FROM `eb_store_order_status` WHERE `oid` = ?',[$directId])===[]
        && m4o_rows($pdo,'SELECT * FROM `eb_store_product_log` WHERE `uid` = ? AND `type` = ?',[$saved['order']['uid'],'order'])===[],
        'POST_COMMIT_DIRECT_HAS_NO_SYNTHETIC_COMPLETION');
    $mark='marketplaceOrderPostCommit';$before=m4o_facts($pdo);
    $timersBefore=m4o_rows($pdo,'SELECT * FROM `eb_system_timer` ORDER BY `id`');
    m4o_expect(m4o_rows($pdo,'SELECT * FROM `eb_system_timer` WHERE `mark` = ?',[$mark])===[],
        'POST_COMMIT_TIMER_NOT_YET_REGISTERED');
    $started=time();$installer=$app->make(PostCommitNormalTimer::class);$registered=$installer->register();$ended=time();
    m4pc_context($app,$pdo);
    m4o_expect(is_array($registered) && is_int($registered['timer_id']??null) && ($registered['changed']??null)===true
        && ($registered['mark']??null)===$mark && ($registered['timeStr']??null)==='0 */1 * * * *',
        'POST_COMMIT_ACTUAL_FIRST_REGISTRATION_RESULT');
    $timerId=m4pc_integer($registered['timer_id'],1);
    $rows=m4o_rows($pdo,'SELECT * FROM `eb_system_timer` WHERE `mark` = ? ORDER BY `id`',[$mark]);
    m4o_expect(count($rows)===1 && m4pc_integer($rows[0]['id'],1)===$timerId,'POST_COMMIT_REAL_TIMER_NATIVE_PK');$timer=$rows[0];
    $fixed=['name'=>'多商户订单提交后收尾','mark'=>$mark,'content'=>'按持久收尾登记完成已提交订单',
        'type'=>2,'month'=>0,'week'=>0,'day'=>0,'hour'=>0,'minute'=>1,'second'=>0,
        'is_del'=>0,'is_open'=>1,'customCode'=>'','timeStr'=>'0 */1 * * * *'];
    $keys=array_keys($fixed+['id'=>0,'last_execution_time'=>0,'next_execution_time'=>0,'add_time'=>0,'update_time'=>0]);
    $actualKeys=array_keys($timer);sort($keys,SORT_STRING);sort($actualKeys,SORT_STRING);
    m4o_expect($keys===$actualKeys,'POST_COMMIT_TIMER_COMPLETE_SAVED_FIELDS');
    foreach($fixed as $field=>$value)m4o_expect($timer[$field]!==null && (string)$timer[$field]===(string)$value,
        'POST_COMMIT_TIMER_CANONICAL_FIELD');
    $added=m4pc_integer($timer['add_time'],1);
    m4o_expect($added>=$started && $added<=$ended && m4pc_integer($timer['update_time'],1)===$added
        && m4pc_integer($timer['last_execution_time'],0,0)===0
        && m4pc_integer($timer['next_execution_time'],1)===$added+60,'POST_COMMIT_TIMER_REAL_REGISTRATION_TIMES');
    $timerService=$app->make(PostCommitNormalTimers::class);
    m4o_expect($timerService->getTimerStr($fixed)===$timer['timeStr']
        && $timerService->getTimerCycleTime($fixed,$added)===m4pc_integer($timer['next_execution_time'],1),
        'POST_COMMIT_TIMER_EXISTING_SCHEDULER_FORMULA');
    $timersAfter=m4o_rows($pdo,'SELECT * FROM `eb_system_timer` ORDER BY `id`');$remaining=[];
    foreach($timersAfter as $row)if(m4pc_integer($row['id'],1)!==$timerId)$remaining[]=$row;
    m4o_expect(count($timersAfter)===count($timersBefore)+1 && $remaining===$timersBefore,
        'POST_COMMIT_TIMER_ONLY_ACTUAL_NEW_ROW');
    $after=m4o_facts($pdo);
    foreach($before as $table=>$facts)if($table!=='eb_system_timer')
        m4o_expect($after[$table]===$facts,'POST_COMMIT_REGISTRATION_NO_BUSINESS_WRITE');
    $again=$installer->register();
    m4o_expect($again===['timer_id'=>$timerId,'changed'=>false,'mark'=>$mark,'timeStr'=>'0 */1 * * * *'],
        'POST_COMMIT_SECOND_REGISTRATION_SAME_NATIVE_PK');
    m4pc_context($app,$pdo);
    m4o_expect(m4o_rows($pdo,'SELECT * FROM `eb_system_timer` ORDER BY `id`')===$timersAfter
        && m4o_facts($pdo)===$after,'POST_COMMIT_SECOND_REGISTRATION_ALL_FIELDS_NO_EXTRA_WRITE');
    $open=m4o_rows($pdo,'SELECT * FROM `eb_system_timer` WHERE `is_open` = 1 AND `is_del` = 0 ORDER BY `id`');
    $cached=PostCommitNormalCacheFacade::get('crontabCache');
    m4o_expect(is_array($cached) && array_is_list($cached) && count($cached)===count($open),'POST_COMMIT_REAL_SCHEDULER_CACHE');
    foreach($open as $index=>$row)m4o_expect(is_array($cached[$index])
        && m4pc_row_text($cached[$index])===m4pc_row_text($row),'POST_COMMIT_COMPLETE_SCHEDULER_CACHE_READBACK');
    $runner=$app->make(PostCommitNormalRunner::class);$handler=PostCommitNormalStr::camel($timer['mark']);
    m4o_expect($handler===$mark && ($runner->markList[$timer['mark']]??null)===$timer['name']
        && method_exists($runner,$handler),'POST_COMMIT_ACTUAL_REGISTERED_MARK_HANDLER');
    $scan=$runner->$handler();m4pc_context($app,$pdo);
    $scanKeys=is_array($scan)?array_keys($scan):[];sort($scanKeys,SORT_STRING);
    m4o_expect(is_array($scan) && ($scan['processed']??null)===1 && ($scan['completed']??null)===1
        && ($scan['already_done']??null)===0 && ($scan['pending']??null)===0
        && $scanKeys===['already_done','completed','next_cursor','pending','processed','wrapped']
        && ($scan['next_cursor']??null)===0 && ($scan['wrapped']??null)===true,'POST_COMMIT_ACTUAL_HANDLER_COMPLETES_DIRECT');
    m4o_expect(PostCommitNormalCacheFacade::has('marketplace_order_post_commit_cursor')===true
        && m4pc_integer(PostCommitNormalCacheFacade::get('marketplace_order_post_commit_cursor'),0,0)===0,
        'POST_COMMIT_ACTUAL_HANDLER_CURSOR_HINT_READBACK');
    m4o_expect(m4o_rows($pdo,'SELECT `order_id` FROM `eb_marketplace_order_idempotency` WHERE `state` = 1 AND `post_commit_done` = 0 ORDER BY `order_id`')===[],
        'POST_COMMIT_NO_UNFINISHED_DIRECT_AFTER_HANDLER');
    m4o_expect(m4o_rows($pdo,'SELECT * FROM `eb_system_timer` ORDER BY `id`')===$timersAfter,
        'POST_COMMIT_HANDLER_DOES_NOT_REWRITE_REGISTRATION');
    $proof=m4o_post_commit_verify($app,$pdo,$directId);
    return ['verified'=>true,'timer_id'=>$timerId,'mark'=>$mark,'registered_twice_unchanged'=>true,
        'pending_before'=>count($pending),'handler_processed'=>$scan['processed'],'handler_completed'=>$scan['completed'],'direct'=>$proof];
}
