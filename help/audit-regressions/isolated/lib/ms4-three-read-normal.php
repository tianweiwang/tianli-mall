<?php
declare(strict_types=1);

/** SOURCE-only normal preparation and six-view verification candidate; host/profile/native/HTTP/DOM remain NOT RUN. */
require_once dirname(__DIR__).'/ms4-fulfillment-normal.php';

/** Decode only full real native rows produced by the approved legacy and V1 creators. */
function m4tr_sequence_sources(PDO $db,\think\App $app,array $roots):array
{
    m4fn_expect(PHP_INT_SIZE===8&&app()===$app&&date_default_timezone_get()==='Asia/Shanghai'
        &&$app->config->get('app.default_timezone')==='Asia/Shanghai'&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('cache.stores.redis.type')==='redis','TASK6_THREE_REAL_SEQUENCE_GENERATOR_ENVIRONMENT');
    m4fn_expect(array_is_list($roots)&&count($roots)>=1&&count($roots)<=100,'TASK6_THREE_ONLY_FINITE_TRUE_NEW_ROOTS');
    $epochSeconds=strtotime('2022-01-01');m4fn_expect(is_int($epochSeconds)&&$epochSeconds>0,'TASK6_THREE_ORIGINAL_SEQUENCE_EPOCH');
    $epochMs=bcmul((string)$epochSeconds,'1000',0);m4fn_expect($epochMs==='1640966400000','TASK6_THREE_SAVED_SHANGHAI_SEQUENCE_EPOCH_MS');
    $keys=[];$seen=[];
    foreach($roots as$root){
        m4fn_expect(is_array($root),'TASK6_THREE_FULL_REAL_NATIVE_PRODUCER_ROOT');$id=m5pcp_int($root['id'],1);
        m4fn_expect(!isset($seen[$id])&&m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$id])===[$root]
            &&m5pcp_int($root['pid'],0,0)===0&&m5pcp_int($root['paid'],0,0)===0
            &&in_array(m5pcp_int($root['marketplace_trade_version'],0,1),[0,1],true),
            'TASK6_THREE_ORIGINAL_FULL_UNPAID_ROOT_EQUALS_NATIVE_READBACK');$seen[$id]=true;
        $number=$root['order_id'];m4fn_expect(is_string($number)&&str_starts_with($number,'cp')&&strlen($number)>2,
            'TASK6_THREE_TRUE_SAVED_CP_PRODUCER_NUMBER');$suffix=substr($number,2);
        m4fn_expect('cp'.$suffix===$number&&preg_match('/\\A[1-9][0-9]{0,18}\\z/D',$suffix)===1
            &&bccomp($suffix,'9223372036854775807',0)<=0,'TASK6_THREE_EXACT_POSITIVE_SNOWFLAKE_SUFFIX');
        $offset=bcdiv($suffix,'4194304',0);$key=bcadd($epochMs,$offset,0);
        m4fn_expect(bccomp($offset,'2199023255551',0)<=0&&preg_match('/\\A[1-9][0-9]{12}\\z/D',$key)===1,
            'TASK6_THREE_INDEPENDENT_SEQUENCE_KEY_FROM_TRUE_NATIVE_ORDER');$hash=hash('sha256',$key);
        $keys[$hash]??=['key'=>$key,'sources'=>[]];$keys[$hash]['sources'][]=[
            'source_kind'=>(int)$root['marketplace_trade_version']===0?'real_legacy_order':'real_v1_order',
            'identifier_sha256'=>hash('sha256',$number)];
    }
    ksort($keys,SORT_STRING);return ['epoch_ms'=>$epochMs,'source_id_count'=>count($roots),'keys'=>$keys];
}

function m4tr_sequence_expiry_barrier(PDO $db,array $sequence): array
{
    m5pcp_expect(m5pcp_keys($sequence,['epoch_ms','source_id_count','keys'])&&$sequence['epoch_ms']==='1640966400000'
        &&is_int($sequence['source_id_count'])&&$sequence['source_id_count']>=1&&$sequence['source_id_count']<=100
        &&is_array($sequence['keys'])&&count($sequence['keys'])>=1
        &&count($sequence['keys'])<=$sequence['source_id_count'],'EXACT_SEQUENCE_BARRIER_INPUT');
    $started=hrtime(true);m5pcp_expect(is_int($started)&&$started>0,'REAL_MONOTONIC_SEQUENCE_BARRIER_START');$lastNative=$started;
    $bounded=static function()use($started,&$lastNative):int{
        $now=hrtime(true);m5pcp_expect(is_int($now)&&$now>=$lastNative&&$now-$started<=10000000000,
            'TEN_SECOND_MONOTONIC_SEQUENCE_BARRIER_BOUND');$lastNative=$now;return $now;
    };
    $redis=audit_redis();$bounded();$first=m5pcp_atomic_redis($redis);$bounded();
    $others=$first['keys'];$states=[];
    foreach($sequence['keys']as$keyHash=>$target){
        m5pcp_expect(is_string($keyHash)&&is_array($target)&&m5pcp_keys($target,['key','sources'])
            &&is_string($target['key'])&&hash('sha256',$target['key'])===$keyHash
            &&is_array($target['sources'])&&array_is_list($target['sources'])&&$target['sources']!==[],
            'EXACT_INTERNAL_SEQUENCE_TARGET');
        $initial=$first['keys'][$keyHash]??null;
        if($initial!==null)m5pcp_expect($initial['type']==='string'&&$initial['expires']>0
            &&bccomp((string)$initial['expires'],$first['time_ms'],0)>0
            &&bccomp(bcsub((string)$initial['expires'],$first['time_ms'],0),'1000',0)<=0,
            'REAL_SEQUENCE_STRING_AND_FIRST_OBSERVED_ONE_SECOND_EXPIRY');
        $states[$keyHash]=['initial'=>$initial,'missing'=>$initial===null,
            'first_absence_ms'=>$initial===null?$first['time_ms']:null];unset($others[$keyHash]);
    }
    $lastServer=$first['time_ms'];$samples=[];
    $observe=static function(array $current)use(&$states,$others,&$lastServer,&$samples):bool{
        m5pcp_expect(bccomp($current['time_ms'],$lastServer,0)>=0,'SEQUENCE_BARRIER_REAL_SERVER_TIME_NONDECREASING');
        $lastServer=$current['time_ms'];$currentOthers=$current['keys'];$allMissing=true;
        foreach($states as$keyHash=>&$state){$value=$current['keys'][$keyHash]??null;
            if($value!==null){
                m5pcp_expect(!$state['missing']&&$state['initial']!==null&&$value===$state['initial'],
                    'SEQUENCE_ORIGINAL_VALUE_EXPIRY_AND_NO_REAPPEARANCE');
                m5pcp_expect(bccomp($current['time_ms'],(string)$value['expires'],0)<0,'SEQUENCE_PRESENT_BEFORE_ORIGINAL_EXPIRY');
                $allMissing=false;
            }elseif(!$state['missing']){
                m5pcp_expect($state['initial']!==null
                    &&bccomp($current['time_ms'],(string)$state['initial']['expires'],0)>=0,
                    'SEQUENCE_ABSENT_ONLY_AFTER_OBSERVED_ORIGINAL_EXPIRY');
                $state['missing']=true;$state['first_absence_ms']=$current['time_ms'];
            }
            unset($currentOthers[$keyHash]);
        }unset($state);
        m5pcp_expect($currentOthers===$others,'ALL_NON_TARGET_REDIS_VALUES_TYPES_AND_ABSOLUTE_EXPIRIES_UNCHANGED');
        $samples[]=['time'=>$current['time'],'time_ms'=>$current['time_ms'],'key_count'=>count($current['keys']),
            'complete_keys_sha256'=>hash('sha256',serialize($current['keys']))];return $allMissing;
    };
    $observe($first);$before=m5pcp_database_snapshot($db);$bounded();
    $current=m5pcp_atomic_redis($redis);$bounded();$complete=$observe($current);
    while(!$complete){$bounded();usleep(50000);$bounded();$current=m5pcp_atomic_redis($redis);$bounded();$complete=$observe($current);}
    $after=m5pcp_database_snapshot($db);$bounded();
    m5pcp_expect($after===$before,'SEQUENCE_BARRIER_COMPLETE_DATABASE_STRICTLY_UNCHANGED');
    $final=m5pcp_atomic_redis($redis);$finished=$bounded();
    m5pcp_expect($observe($final),'SEQUENCE_BARRIER_FINAL_ALL_EXACT_TARGET_KEYS_ABSENT');$targets=[];
    foreach($states as$keyHash=>$state)$targets[]=['key_sha256'=>$keyHash,'sources'=>$sequence['keys'][$keyHash]['sources'],
        'initial_observation'=>$state['initial']===null?'ALREADY_ABSENT':'PRESENT_WITH_OBSERVED_EXPIRY',
        'initial_expires_at_ms'=>$state['initial']['expires']??null,'initial_value_sha256'=>$state['initial']['sha256']??null,
        'first_absence_time_ms'=>$state['first_absence_ms'],'natural_expiry_observed'=>$state['initial']!==null,'final_absent'=>true];
    return ['scope'=>'READONLY_REAL_PRODUCER_SEQUENCE_EXPIRY_BARRIER_ONLY','status'=>'VERIFIED_TRUE_PREPARE_SEQUENCE_KEYS_ABSENT_BEFORE_READ_BASELINE',
        'epoch_ms'=>$sequence['epoch_ms'],'source_id_count'=>$sequence['source_id_count'],'key_count'=>count($states),
        'targets'=>$targets,'server_samples'=>$samples,'monotonic_elapsed_ns'=>$finished-$started,
        'complete_database_before_sha256'=>hash('sha256',serialize($before)),
        'complete_database_after_sha256'=>hash('sha256',serialize($after)),
        'non_target_redis_sha256'=>hash('sha256',serialize($others)),
        'all_non_target_redis_values_and_absolute_expiries_unchanged'=>true,
        'complete_database_strictly_unchanged'=>true,'all_database_and_non_target_cache_preserved'=>true];
}

/** Read complete existing tag membership. Names are private in-memory native facts, never public output. */
function m4tr_crmeb_tag_members():array
{
    $redis=audit_redis();$name='c:CRMEB:'.md5('crmeb');$type=$redis->rawCommand('TYPE',$name);
    m4fn_expect(in_array($type,['none','set'],true),'TASK6_THREE_ORIGINAL_CRMEB_TAG_NATIVE_TYPE');
    $members=$type==='none'?[]:$redis->rawCommand('SMEMBERS',$name);
    m4fn_expect(is_array($members)&&array_is_list($members),'TASK6_THREE_COMPLETE_NATIVE_TAG_MEMBERS');
    foreach($members as$member)m4fn_expect(is_string($member),'TASK6_THREE_NATIVE_TAG_MEMBER_STRING');
    m4fn_expect(count($members)===count(array_unique($members,SORT_STRING)),'TASK6_THREE_NO_DUPLICATE_NATIVE_TAG_MEMBER');
    sort($members,SORT_STRING);return $members;
}
/** The original Driver serializer stores numeric scalars directly; all others use PHP serialize. */
function m4tr_cache_wire(mixed $value):string
{
    return is_numeric($value)?(string)$value:serialize($value);
}
/** Only fixed producer-derived physical names may change, after independent raw value/type/expiry proof. */
function m4tr_cache_delta(array $before,array $after,array $expectedPhysicalRaw,array $tagBefore,array $addedTagNames,array $removedNames=[],array $originalConfirmationExpiry=[]):void
{
    $redis=audit_redis();$expected=$before['keys'];$tagName='c:CRMEB:'.md5('crmeb');
    m4fn_expect(count($originalConfirmationExpiry)<=1,'TASK6_THREE_AT_MOST_ONE_ORIGINAL_CONFIRMATION_EXPIRY');
    foreach($originalConfirmationExpiry as$name=>$expires)m4fn_expect(is_string($name)&&str_starts_with($name,'c:user_order_')
        &&array_key_exists($name,$expectedPhysicalRaw)&&is_int($expires)&&$expires>0,
        'TASK6_THREE_ONLY_REAL_EXPECTED_CONFIRMATION_ORIGINAL_ABSOLUTE_EXPIRY');
    foreach($expectedPhysicalRaw as$name=>$raw){
        m4fn_expect(is_string($name)&&str_starts_with($name,'c:')&&$name!==$tagName&&is_string($raw),
            'TASK6_THREE_FIXED_PRODUCER_CACHE_NAME_AND_BYTES');$hash=hash('sha256',$name);$actual=$after['keys'][$hash]??null;
        $expires=$originalConfirmationExpiry[$name]??-1;
        m4fn_expect($redis->rawCommand('TYPE',$name)==='string'&&$redis->rawCommand('GET',$name)===$raw
            &&$redis->rawCommand('PEXPIRETIME',$name)===$expires&&is_array($actual)&&$actual['type']==='string'&&$actual['expires']===$expires,
            'TASK6_THREE_INDEPENDENT_REAL_CACHE_VALUE_TYPE_AND_ABSOLUTE_EXPIRY');$expected[$hash]=$actual;
    }
    foreach($removedNames as$name){
        m4fn_expect(is_string($name)&&str_starts_with($name,'c:')&&$name!==$tagName&&$redis->rawCommand('EXISTS',$name)===0
            &&!array_key_exists(hash('sha256',$name),$after['keys']),'TASK6_THREE_ONLY_TRUE_PRODUCER_CACHE_CONSUMED');
        unset($expected[hash('sha256',$name)]);
    }
    $tag=array_values(array_unique(array_merge($tagBefore,$addedTagNames),SORT_STRING));sort($tag,SORT_STRING);
    m4fn_expect(m4tr_crmeb_tag_members()===$tag&&$redis->rawCommand('PEXPIRETIME',$tagName)===-1,
        'TASK6_THREE_EXACT_OLD_TAG_PLUS_TRUE_PRODUCER_NAMES_NO_MEMBER_REMOVAL');
    $tagValue=['type'=>'set','sha256'=>hash('sha256',"set\0".serialize($tag)),'expires'=>-1];
    m4fn_expect(($after['keys'][hash('sha256',$tagName)]??null)===$tagValue,'TASK6_THREE_COMPLETE_NATIVE_TAG_CONTENT_HASH');
    $expected[hash('sha256',$tagName)]=$tagValue;ksort($expected,SORT_STRING);
    m4fn_expect($after['keys']===$expected,'TASK6_THREE_ALL_OTHER_CACHE_NAMES_VALUES_TYPES_ABSOLUTE_EXPIRIES_UNCHANGED');
}
/** Real getters warm only the finite original cart/old-confirm/create/event capability sources. */
function m4tr_warm_legacy_configuration(\think\App $app,PDO $db):array
{
    $names=['queue_open','brokerage_func_status','division_status','member_func_status','member_card_status','member_price_status',
        'image_thumb_status','store_free_postage','offline_postage','integral_ratio','offline_pay_status','balance_func_status',
        'yue_pay_status','pay_weixin_open','friend_pay_status','store_self_mention','ali_pay_status','invoice_func_status',
        'special_invoice_status','is_self_brokerage','user_brokerage_type'];
    $before=m4trp_snapshot($db);$tagBefore=m4tr_crmeb_tag_members();$expected=[];$physical=[];
    foreach($names as$name){
        $rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($rows)<=1,'TASK6_THREE_UNIQUE_REAL_WARM_CONFIGURATION_ROW');
        $value=$rows===[]?null:json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR);
        m4fn_expect($value===null||is_scalar($value),'TASK6_THREE_ORIGINAL_WARM_CONFIG_SCALAR_OR_NULL');
        $wanted=$value===null?'':trim((string)$value);$actual=sys_config($name);
        m4fn_expect($actual===$wanted,'TASK6_THREE_REAL_SYSTEM_GETTER_MATCHES_NATIVE_JSON_SOURCE');
        $name='c:system_config_'.$name;$expected[$name]=m4tr_cache_wire($value);$physical[]=$name;
    }
    $hours=['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time'];$values=[];
    foreach($hours as$name){
        $rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($rows)<=1,'TASK6_THREE_UNIQUE_REAL_MULTI_CONFIG_SOURCE');
        $values[$name]=$rows===[]?'':(json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR)??'');
    }
    $actual=\crmeb\services\SystemConfigService::more($hours);m4fn_expect($actual===$values,'TASK6_THREE_ORIGINAL_MULTI_GETTER_EXACT_DEFAULTS');
    $name='c:system_config_'.md5(implode(',',$hours));$expected[$name]=m4tr_cache_wire($values);$physical[]=$name;
    $after=m4trp_snapshot($db);m4trp_database_delta($before,$after,[]);
    m4tr_cache_delta($before['redis'],$after['redis'],$expected,$tagBefore,$physical);
    return ['schema'=>1,'source'=>'ORIGINAL_REAL_SYSTEM_CONFIGURATION_GETTERS','fixed_getter_count'=>count($names),
        'multi_getter_count'=>1,'native_json_values_verified'=>true,'full_database_zero'=>true,
        'all_other_redis_preserved'=>true,'before_sha256'=>hash('sha256',serialize($before)),
        'after_sha256'=>hash('sha256',serialize($after)),
        // Private preparatory material only; the eventual public proof must project explicit finite keys.
        'expected_physical_raw'=>$expected,'physical_tag_names'=>$physical];
}

/** Failure-only, bounded public child diagnostics. Never a private archive read or an exit proof. */
function m4tr_legacy_public_failure_stderr(array $nativeChild,string $stdout):void
{
    try{
        if(($nativeChild['native_completed_normally']??null)!==false||($nativeChild['native_exit']??null)!==1
            ||($nativeChild['process_closed']??null)!==true||($nativeChild['registered_group_gone']??null)!==true
            ||($nativeChild['remaining_members']??null)!==[]||($nativeChild['capture_complete']??null)!==true
            ||($nativeChild['stderr_bytes']??null)!==0||strlen($stdout)<1||strlen($stdout)>16384
            ||($nativeChild['stdout_bytes']??null)!==strlen($stdout)
            ||($nativeChild['stdout_sha256']??null)!==hash('sha256',$stdout))return;
        $terminal=$nativeChild['same_handle_first_terminal']??null;
        if(!is_array($terminal)||($terminal['running']??null)!==false||($terminal['signaled']??null)!==false
            ||($terminal['exitcode']??null)!==1)return;
        $reply=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($reply)||array_keys($reply)!==['schema','scope','status','phase','diagnostic','native_exit','native_exit_observation']
            ||$reply['schema']!==1||$reply['scope']!=='MS4_THREE_LEGACY_NATIVE_PRODUCER'
            ||$reply['status']!=='INCOMPLETE_TRUE_LEGACY_ONLY'||$reply['native_exit']!==null
            ||$reply['native_exit_observation']!=='PARENT_SAME_HANDLE_REQUIRED'||m4cb_json($reply)!==$stdout)return;
        $diagnostic=$reply['diagnostic'];
        if(!is_string($reply['phase'])||strlen($reply['phase'])>80
            ||preg_match('/\A[a-z][a-z0-9_]*\z/D',$reply['phase'])!==1
            ||!is_array($diagnostic)||array_keys($diagnostic)!==['code','class','file','line']
            ||!is_string($diagnostic['code'])||strlen($diagnostic['code'])>256
            ||preg_match('/\A[A-Z0-9_]+\z/D',$diagnostic['code'])!==1
            ||!is_string($diagnostic['class'])||strlen($diagnostic['class'])>256
            ||!is_string($diagnostic['file'])||strlen($diagnostic['file'])>128
            ||preg_match('/\A[A-Za-z0-9_.-]+\.php\z/D',$diagnostic['file'])!==1
            ||basename($diagnostic['file'])!==$diagnostic['file']
            ||!is_int($diagnostic['line'])||$diagnostic['line']<1||$diagnostic['line']>2147483647)return;
        foreach(explode('\\',$diagnostic['class'])as$part)
            if(preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$part)!==1)return;
        $line=json_encode(['schema'=>1,'scope'=>$reply['scope'],'status'=>$reply['status'],'phase'=>$reply['phase'],
            'diagnostic'=>['code'=>$diagnostic['code'],'class'=>$diagnostic['class'],
                'file'=>$diagnostic['file'],'line'=>$diagnostic['line']]],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(strlen($line)<=2048)@fwrite(STDERR,$line);
    }catch(Throwable $ignored){
        // Diagnostics must never replace the original native-zero failure or expose an exception message.
    }
}
/** Parent-side readback only, after the caller proves the SAME actual child handle ended naturally with group absent. */
function m4tr_legacy_archive(\think\App $app,PDO $db,array $source,array $owner,array $before,array $nativeChild,string $stdout):array
{
    m4fn_expect(app()===$app&&$app->config->get('marketplace.enabled')===true&&$app->config->get('marketplace.trade.enabled')===true
        &&\app\services\marketplace\order\MarketplaceTradePolicy::active()===true
        &&\app\services\marketplace\order\MarketplaceTradePolicy::v1CreateEnabled()===true,
        'TASK6_THREE_PARENT_FACTORY_AND_REAL_POLICY_STAY_TRUE');
    foreach($source['environment']as$name=>$value)m4fn_expect(getenv($name)===$value,'TASK6_THREE_PARENT_SIX_REAL_ENV_VALUES_UNCHANGED');
    m4tr_legacy_public_failure_stderr($nativeChild,$stdout);
    m4fn_expect(($nativeChild['native_completed_normally']??null)===true&&($nativeChild['native_exit']??null)===0
        &&($nativeChild['process_closed']??null)===true&&($nativeChild['registered_group_gone']??null)===true
        &&($nativeChild['remaining_members']??null)===[]&&($nativeChild['capture_complete']??null)===true
        &&($nativeChild['stderr_bytes']??null)===0&&($nativeChild['failure_code']??null)===null
        &&($nativeChild['stdout_bytes']??null)===strlen($stdout)&&($nativeChild['stdout_sha256']??null)===hash('sha256',$stdout)
        &&strlen($stdout)>0&&strlen($stdout)<=16384,'TASK6_THREE_ACTUAL_SAME_HANDLE_CLI_NATIVE_ZERO_BEFORE_PRIVATE_READ');
    $reply=json_decode($stdout,true,64,JSON_THROW_ON_ERROR);m4c_entry_keys($reply,['schema','scope','status','run_id','source_sha',
        'freeze_sha256','container_id','archive','native_exit','native_exit_observation']);
    m4fn_expect($reply['schema']===1&&$reply['scope']==='MS4_THREE_LEGACY_NATIVE_PRODUCER'&&$reply['status']==='PREPARED_TRUE_LEGACY_ONLY'
        &&$reply['run_id']===$source['run_id']&&$reply['source_sha']===$source['source_sha']&&$reply['freeze_sha256']===$source['freeze_sha256']
        &&$reply['container_id']===$source['container_id']&&$reply['native_exit']===null&&$reply['native_exit_observation']==='PARENT_SAME_HANDLE_REQUIRED',
        'TASK6_THREE_CLI_JSON_IS_BINDING_ONLY_NEVER_ITS_OWN_EXIT_PROOF');
    $descriptor=$reply['archive'];m4c_entry_keys($descriptor,['path','bytes','sha256','stat','native_bytes','native_sha256']);
    m4fn_expect($descriptor['path']===M4TRP_ARCHIVE&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0&&$descriptor['bytes']<=23068672
        &&m5pc_hex($descriptor['sha256'])&&is_int($descriptor['native_bytes'])&&$descriptor['native_bytes']>0
        &&$descriptor['native_bytes']<=16777216&&m5pc_hex($descriptor['native_sha256']),
        'TASK6_THREE_ONE_FIXED_BOUNDED_REAL_PRIVATE_LEGACY_DESCRIPTOR');
    $keys=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];m4c_entry_keys($descriptor['stat'],$keys);
    $directory=dirname(M4TRP_ARCHIVE);$parent=lstat($directory);clearstatcache(true,M4TRP_ARCHIVE);$stat=lstat(M4TRP_ARCHIVE);
    m4fn_expect(is_array($parent)&&is_dir($directory)&&!is_link($directory)&&realpath($directory)===$directory
        &&($parent['mode']&0777)===0700&&$parent['uid']===posix_geteuid()
        &&is_array($stat)&&is_file(M4TRP_ARCHIVE)&&!is_link(M4TRP_ARCHIVE)&&realpath(M4TRP_ARCHIVE)===M4TRP_ARCHIVE
        &&($stat['mode']&0170000)===0100000&&($stat['mode']&0777)===0600&&$stat['nlink']===1&&$stat['uid']===posix_geteuid()
        &&$stat['size']===$descriptor['bytes']&&array_intersect_key($stat,array_flip($keys))===$descriptor['stat'],
        'TASK6_THREE_PARENT_EXACT_REAL_PRIVATE_ARCHIVE_MODE_OWNER_STAT');
    $raw=file_get_contents(M4TRP_ARCHIVE);clearstatcache(true,M4TRP_ARCHIVE);$again=lstat(M4TRP_ARCHIVE);
    m4fn_expect(is_string($raw)&&strlen($raw)===$descriptor['bytes']&&hash('sha256',$raw)===$descriptor['sha256']
        &&is_array($again)&&array_intersect_key($again,array_flip($keys))===$descriptor['stat'],
        'TASK6_THREE_PARENT_COMPLETE_NATIVE_ARCHIVE_BYTES_STABLE_DURING_READ');
    $frame=json_decode($raw,true,64,JSON_THROW_ON_ERROR);m4c_entry_keys($frame,['schema','scope','run_id','source_sha','freeze_sha256',
        'container_id','source_binding_sha256','native']);
    m4fn_expect($frame['schema']===1&&$frame['scope']==='MS4_THREE_LEGACY_NATIVE_PRIVATE'&&$frame['run_id']===$source['run_id']
        &&$frame['source_sha']===$source['source_sha']&&$frame['freeze_sha256']===$source['freeze_sha256']
        &&$frame['container_id']===$source['container_id']&&$frame['source_binding_sha256']===$source['source_binding_sha256'],
        'TASK6_THREE_PRIVATE_ARCHIVE_SAME_SOURCE_FREEZE_CONTAINER_BINDING');$encoded=$frame['native'];
    m4c_entry_keys($encoded,['encoding','bytes','sha256','serialized_base64']);$native=base64_decode($encoded['serialized_base64'],true);
    m4fn_expect($encoded['encoding']==='php-serialize-array-base64-v1'&&$encoded['bytes']===$descriptor['native_bytes']
        &&$encoded['sha256']===$descriptor['native_sha256']&&is_string($native)&&strlen($native)===$encoded['bytes']
        &&hash('sha256',$native)===$encoded['sha256']&&base64_encode($native)===$encoded['serialized_base64'],
        'TASK6_THREE_PARENT_EXACT_NATIVE_ARRAY_ENCODING');$state=unserialize($native,['allowed_classes'=>false,'max_depth'=>64]);
    m4fn_expect(is_array($state)&&serialize($state)===$native,'TASK6_THREE_PARENT_ARRAY_ONLY_NATIVE_RESTORE_NO_OBJECTS');
    m4c_entry_keys($state,['schema','scope','authenticated_owner_uid','original_inputs','observer','whole_before','whole_after',
        'event_preconditions','warm','produced','cache_verification']);
    m4fn_expect($state['schema']===1&&$state['scope']==='TRUE_LEGACY_PREPARATION_NATIVE_ONLY'
        &&$state['authenticated_owner_uid']===$owner['uid'],'TASK6_THREE_PRIVATE_ACTUAL_ORIGINAL_PAID_HTTP_OWNER_SOURCE');
    $inputs=[];foreach($owner['inputs']as$input)$inputs[]=['path'=>$input['path'],'stat'=>$input['stat'],'sha256'=>$input['sha256']];
    m4fn_expect($state['original_inputs']===$inputs,'TASK6_THREE_PRIVATE_ARCHIVE_ORIGINAL_INPUT_BYTES_STAT_SOURCE');m4fn_inputs_unchanged($owner['inputs']);
    m4trp_database_delta($before,$state['whole_before'],[]);
    m4fn_expect($before['redis']['keys']===$state['whole_before']['redis']['keys'],
        'TASK6_THREE_CHILD_PREPARE_BEGAN_FROM_TRUE_PARENT_FULL_BASELINE');
    $root=$state['produced']['root'];$item=$state['produced']['item'];
    m4fn_expect(m5pcp_int($root['uid'],1)===$owner['uid']&&m5pcp_int($root['marketplace_trade_version'],0,0)===0
        &&m5pcp_int($root['paid'],0,0)===0&&m5pcp_int($root['pid'],0,0)===0
        &&m5pcp_int($item['oid'],1)===m5pcp_int($root['id'],1)&&m5pcp_int($item['uid'],1)===$owner['uid']
        &&m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$root['id']])===[$root]
        &&m5pcp_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid = ?',[$root['id']])===[$item],
        'TASK6_THREE_TRUE_COMMITTED_LEGACY_ROOT_FULL_ITEM_MATCH_INDEPENDENT_PARENT_PDO');
    $after=m4trp_snapshot($db);m4trp_database_delta($state['whole_after'],$after,[]);
    m4fn_expect($state['whole_after']['redis']['keys']===$after['redis']['keys'],
        'TASK6_THREE_PARENT_ALL_NATIVE_DATABASE_AND_CACHE_EQUALS_CHILD_ARCHIVE');
    return ['descriptor'=>$descriptor,'native_child'=>$nativeChild,'state'=>$state,'after'=>$after];
}

/** Preparation only: every new root already exists with its native saved zero before the one exact config change. */
function m4tr_current_one_after_saved_zero(\think\App $app,PDO $db,array $owner,array $newRoots):array
{
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();
    m4fn_context($app,$connection,$default,$db);
    m4fn_expect(array_is_list($newRoots)&&count($newRoots)===100,'TASK6_THREE_CONFIG_ONLY_AFTER_ONE_LEGACY_AND_NINETY_NINE_V1');
    $seen=[];$v1=[];$legacy=0;
    foreach($newRoots as$root){
        $id=m5pcp_int($root['id'],1);$uid=m5pcp_int($root['uid'],1);$version=m5pcp_int($root['marketplace_trade_version'],0,1);
        m4fn_expect(!isset($seen[$id])&&$uid===$owner['uid']&&m5pcp_int($root['pid'],0,0)===0&&m5pcp_int($root['paid'],0,0)===0
            &&m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$id])===[$root],
            'TASK6_THREE_CONFIG_ALL_TRUE_NEW_ROOTS_NATIVE_UNCHANGED');$seen[$id]=true;
        if($version===0){++$legacy;continue;}
        $ledgers=m5pcp_rows($db,'SELECT * FROM eb_marketplace_order_idempotency WHERE order_id = ?',[$id]);
        m4fn_expect(count($ledgers)===1,'TASK6_THREE_CONFIG_ONE_TRUE_COMMITTED_LEDGER_PER_V1');$ledger=$ledgers[0];
        m4fn_expect(m5pcp_int($ledger['uid'],1)===$uid&&m5pcp_int($ledger['mer_id'],1)===m5pcp_int($root['mer_id'],1)
            &&m5pcp_int($ledger['order_id'],1)===$id&&m5pcp_int($ledger['state'],1,1)===1
            &&m5pcp_int($ledger['post_commit_done'],1,1)===1&&m5pcp_int($ledger['cancel_at'],0,0)===0
            &&m5pcp_int($ledger['add_time'],1)===m5pcp_int($root['add_time'],1)
            &&$ledger['source_kind']==='persistent'&&$ledger['confirmation_ref']===hash('sha256',$root['unique'],true)
            &&json_decode($ledger['cart_refs'],true,32,JSON_THROW_ON_ERROR)===json_decode($root['cart_id'],true,32,JSON_THROW_ON_ERROR),
            'TASK6_THREE_CONFIG_NATIVE_COMPLETE_LEDGER_IDENTITY_AND_SAVED_ZERO');
        $v1[]=['root'=>$root,'ledger'=>$ledger];
    }
    m4fn_expect($legacy===1&&count($v1)===99,'TASK6_THREE_CONFIG_EXACT_REAL_PREPARATION_POPULATION');
    // The original one-second cp keys expire naturally before a strict config-delta window.
    $sequence=m4tr_sequence_sources($db,$app,$newRoots);$barrier=m4tr_sequence_expiry_barrier($db,$sequence);
    $before=m4trp_snapshot($db);$tagBefore=m4tr_crmeb_tag_members();
    $config=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',['order_cancel_time']);
    $oldValue=json_encode('0',JSON_THROW_ON_ERROR);$newValue=json_encode('1',JSON_THROW_ON_ERROR);
    m4fn_expect(count($config)===1&&$config[0]['type']==='text'&&$config[0]['value']===$oldValue,
        'TASK6_THREE_CONFIG_EXISTING_UNIQUE_EXACT_JSON_STRING_ZERO');$original=$config[0];
    $hours=['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time'];$oldHours=[];
    foreach($hours as$name){
        $rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($rows)<=1,'TASK6_THREE_CONFIG_UNIQUE_MULTI_GETTER_NATIVE_INPUT');
        $oldHours[$name]=$rows===[]?'':(json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR)??'');
    }
    m4fn_expect($oldHours['order_cancel_time']==='0','TASK6_THREE_CONFIG_NATIVE_MULTIPLE_ZERO_IS_STRING');
    $logical=['system_config_order_cancel_time','system_config_'.md5(implode(',',$hours))];
    $oldRaw=[$logical[0]=>m4tr_cache_wire('0'),$logical[1]=>m4tr_cache_wire($oldHours)];$exists=[];$redis=audit_redis();
    foreach($logical as$name){$physical='c:'.$name;$present=array_key_exists(hash('sha256',$physical),$before['redis']['keys']);
        $exists[$name]=$present;
        m4fn_expect($redis->rawCommand('EXISTS',$physical)===($present?1:0),'TASK6_THREE_CONFIG_ACTUAL_PREDELETE_EXISTENCE');
        if($present)m4fn_expect($redis->rawCommand('TYPE',$physical)==='string'&&$redis->rawCommand('GET',$physical)===$oldRaw[$name]
            &&$redis->rawCommand('PEXPIRETIME',$physical)===-1,'TASK6_THREE_CONFIG_ORIGINAL_WARM_CACHE_REAL_ZERO_INPUT');
    }
    \think\facade\Db::transaction(static function(\think\db\ConnectionInterface $active)use($original,$oldValue,$newValue):void{
        $pdo=m4cb_transaction_pdo($active);
        m4fn_expect(m5pcp_rows($pdo,'SELECT * FROM eb_system_config WHERE menu_name = ?',['order_cancel_time'])===[$original],
            'TASK6_THREE_CONFIG_SAME_DEFAULT_TRANSACTION_FULL_BEFORE');
        $statement=$pdo->prepare('UPDATE eb_system_config SET value = ? WHERE id = ? AND menu_name = ? AND value = ?');
        m4fn_expect($statement instanceof PDOStatement,'TASK6_THREE_CONFIG_ORIGINAL_NATIVE_PREPARE');
        foreach([$newValue,(string)$original['id'],'order_cancel_time',$oldValue]as$index=>$part)
            m4fn_expect($statement->bindValue($index+1,$part,PDO::PARAM_STR),'TASK6_THREE_CONFIG_ORIGINAL_NATIVE_STRING_BIND');
        m4fn_expect($statement->execute()&&$statement->rowCount()===1,'TASK6_THREE_CONFIG_ORIGINAL_CONDITIONAL_ONE_ROW_WRITE');
        $expected=$original;$expected['value']=$newValue;
        m4fn_expect(m5pcp_rows($pdo,'SELECT * FROM eb_system_config WHERE id = ?',[$original['id']])===[$expected]
            &&m4cb_transaction_pdo($active)===$pdo,'TASK6_THREE_CONFIG_SAME_DEFAULT_PDO_COMPLETE_READBACK');
    });
    m4fn_context($app,$connection,$default,$db);$expectedRow=$original;$expectedRow['value']=$newValue;
    $rows=$before['rows']['eb_system_config'];$replaced=0;
    foreach($rows as&$row)if($row===$original){$row=$expectedRow;++$replaced;}unset($row);
    m4fn_expect($replaced===1,'TASK6_THREE_CONFIG_ONE_COMPLETE_ROW_REPLACEMENT');
    $written=m4trp_snapshot($db);m4trp_database_delta($before,$written,['eb_system_config'=>$rows]);
    m4fn_expect($before['redis']['keys']===$written['redis']['keys'],'TASK6_THREE_CONFIG_NATIVE_WRITER_NO_CACHE_DELTA');
    foreach($logical as$name){
        m4fn_expect(\crmeb\services\CacheService::delete($name)===$exists[$name],
            'TASK6_THREE_CONFIG_ORIGINAL_EXACT_CACHE_DELETE_RESULT_MATCHES_EXISTENCE');
        m4fn_expect($redis->rawCommand('EXISTS','c:'.$name)===0,'TASK6_THREE_CONFIG_EXACT_DELETED_VALUE_ABSENT');
    }
    $deleted=m4trp_snapshot($db);m4trp_database_delta($written,$deleted,[]);
    $physical=array_map(static fn(string $name):string=>'c:'.$name,$logical);
    m4tr_cache_delta($written['redis'],$deleted['redis'],[],$tagBefore,[],$physical);
    $newHours=$oldHours;$newHours['order_cancel_time']='1';
    m4fn_expect(sys_config('order_cancel_time')==='1'&&\crmeb\services\SystemConfigService::more($hours)===$newHours,
        'TASK6_THREE_CONFIG_ORIGINAL_GETTERS_READ_CURRENT_STRING_ONE');
    foreach($logical as$name)m4fn_expect($redis->rawCommand('EXISTS','c:'.$name.'_lock')===0,
        'TASK6_THREE_CONFIG_NO_TEMPORARY_REAL_GETTER_LOCK_REMAINS');
    $after=m4trp_snapshot($db);m4trp_database_delta($written,$after,[]);
    $expectedPhysical=[$physical[0]=>m4tr_cache_wire('1'),$physical[1]=>m4tr_cache_wire($newHours)];
    m4tr_cache_delta($before['redis'],$after['redis'],$expectedPhysical,$tagBefore,$physical);
    foreach($v1 as$record)m4fn_expect(m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$record['root']['id']])===[$record['root']]
        &&m5pcp_rows($db,'SELECT * FROM eb_marketplace_order_idempotency WHERE order_id = ?',[$record['root']['id']])===[$record['ledger']],
        'TASK6_THREE_CONFIG_ALL_NINETY_NINE_NATIVE_ROOT_AND_ZERO_LEDGER_ROWS_STAY_IDENTICAL');
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);
    return ['schema'=>1,'scope'=>'PREPARATION_SAVED_ZERO_VS_CURRENT_ONE_ONLY','v1_root_count'=>99,'legacy_root_count'=>1,
        'saved_deadline_zero_count'=>99,'configuration_row_id'=>(int)$original['id'],'before_json_value_sha256'=>hash('sha256',$oldValue),
        'after_json_value_sha256'=>hash('sha256',$newValue),'one_configuration_value_changed'=>true,'all_other_database_rows_preserved'=>true,
        'exact_configuration_cache_count'=>2,'all_other_redis_preserved'=>true,'sequence_barrier'=>$barrier,
        'before_sha256'=>hash('sha256',serialize($before)),'after_sha256'=>hash('sha256',serialize($after))];
}

/** Fixed new V1 catalog only; ordinary native fixture inserts never clone a saved order. */
function m4tr_v1_fixture_row(PDO $db,string $table,array $values,string $pk='id'):array
{
    m4fn_expect(in_array([$table,$pk],[['eb_marketplace_merchant','mer_id'],['eb_store_product','id'],
        ['eb_store_product_attr','id'],['eb_store_product_attr_value','id']],true),'TASK6_THREE_FIXED_NEW_V1_CATALOG_TABLE');
    $before=m4trp_snapshot($db);$row=m4cb_insert($db,$table,$values,$pk);$id=m5pcp_u64($row[$pk]);
    m4fn_expect($id===m5pcp_u64($before['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']),
        'TASK6_THREE_NEW_V1_CATALOG_NATURAL_NATIVE_PK');
    $checked=m4trp_expected_row($before['database']['tables'][$table]['metadata'],$row,[$pk=>$id]+$values);
    $rows=$before['rows'][$table];$rows[]=$checked;$after=m4trp_snapshot($db);
    m4trp_database_delta($before,$after,[$table=>$rows],[$table=>1]);
    m4fn_expect($before['redis']['keys']===$after['redis']['keys'],'TASK6_THREE_NEW_V1_CATALOG_INSERT_COMPLETE_REDIS_ZERO');return $checked;
}
function m4tr_v1_fixture(\think\App $app,PDO $db,array $owner):array
{
    $uid=$owner['uid'];$users=m5pcp_rows($db,'SELECT * FROM eb_user WHERE uid = ?',[$uid]);
    m4fn_expect(count($users)===1&&m5pcp_int($users[0]['uid'],1)===$uid&&m5pcp_int($users[0]['level'],0,0)===0
        &&m5pcp_int($users[0]['is_money_level'],0,0)===0&&m5pcp_int($users[0]['is_ever_level'],0,0)===0,
        'TASK6_THREE_NEW_V1_USES_TRUE_ORIGINAL_NONMEMBER_BUYER');
    $addressRows=m5pcp_rows($db,'SELECT * FROM eb_user_address WHERE uid = ? AND is_default = 1 AND is_del = 0',[$uid]);
    m4fn_expect(count($addressRows)===1,'TASK6_THREE_NEW_V1_USES_TRUE_ORIGINAL_DEFAULT_ADDRESS');$address=$addressRows[0];
    foreach(['member_func_status','member_card_status','member_price_status']as$name){
        $config=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($config)===1&&$config[0]['value']==='0'&&sys_config($name)==='0',
            'TASK6_THREE_NEW_V1_ORIGINAL_MEMBERSHIP_OFF_BEFORE_NINETY_EIGHT');
    }
    $cancel=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',['order_cancel_time']);
    m4fn_expect(count($cancel)===1&&$cancel[0]['value']===json_encode('0',JSON_THROW_ON_ERROR),
        'TASK6_THREE_NEW_V1_REAL_CANCEL_ZERO_BEFORE_ALL_CREATION');$now=time();
    $merchant=m4tr_v1_fixture_row($db,'eb_marketplace_merchant',['name'=>'三端真实读取专用店铺','review_status'=>1,'accept_orders'=>1,
        'fulfillment_enabled'=>1,'security_locked'=>0,'add_time'=>$now,'update_time'=>$now],'mer_id');$products=$skus=[];
    foreach(['ordinary','member']as$kind){$member=$kind==='member';
        $product=m4tr_v1_fixture_row($db,'eb_store_product',['mer_id'=>$merchant['mer_id'],
            'store_name'=>$member?'三端会员成交价商品':'三端普通读取商品','unit_name'=>'件','spec_type'=>1,
            'stock'=>200,'sales'=>0,'price'=>'20.15','cost'=>'7.45','vip_price'=>$member?'16.00':'0.00',
            'postage'=>'0.00','freight'=>1,'temp_id'=>0,'is_show'=>1,'review_status'=>2,'is_vip'=>$member?1:0,
            'logistics'=>'1','custom_form'=>'[]','min_qty'=>1,'image'=>'/ms4-three-normal.png','add_time'=>$now]);
        m4tr_v1_fixture_row($db,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>'标准']);
        $sku=m4tr_v1_fixture_row($db,'eb_store_product_attr_value',['product_id'=>$product['id'],'type'=>0,'unique'=>bin2hex(random_bytes(4)),
            'suk'=>'标准','stock'=>200,'sales'=>0,'price'=>'20.15','vip_price'=>$member?'16.00':'0.00','cost'=>'7.45',
            'is_show'=>1,'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00','image'=>'/ms4-three-normal-sku.png']);
        $products[$kind]=$product;$skus[$kind]=$sku;
    }
    return ['user'=>$users[0],'address'=>$address,'merchant'=>$merchant,'products'=>$products,'skus'=>$skus,
        'ordinary_count'=>98,'member_count'=>1,'total_count'=>99];
}
/** Original finite member-right fixture, literal columns and natural ID; no order/member payment or card is inserted. */
function m4tr_v1_member_right(PDO $db,string $type,int $number):array
{
    m4fn_expect(in_array([$type,$number],[['vip_price',1],['express',80]],true),'TASK6_THREE_ORIGINAL_FINITE_MEMBER_RIGHT');
    $before=m4trp_snapshot($db);m4fn_expect(m5pcp_rows($db,'SELECT * FROM eb_member_right WHERE right_type = ?',[$type])===[],
        'TASK6_THREE_MEMBER_RIGHT_DOES_NOT_REPLACE_EXISTING');$now=time();
    $statement=$db->prepare('INSERT INTO eb_member_right (right_type,title,number,status,add_time) VALUES (?,?,?,?,?)');
    m4fn_expect($statement instanceof PDOStatement,'TASK6_THREE_MEMBER_RIGHT_NATIVE_INSERT_PREPARE');
    foreach([$type,'普通报价权益',(string)$number,'1',(string)$now]as$index=>$part)
        m4fn_expect($statement->bindValue($index+1,$part,PDO::PARAM_STR),'TASK6_THREE_MEMBER_RIGHT_NATIVE_BIND');
    m4fn_expect($statement->execute()&&$statement->rowCount()===1,'TASK6_THREE_MEMBER_RIGHT_NATIVE_INSERT_ONE');$id=$db->lastInsertId();
    m4fn_expect(is_string($id)&&$id===m5pcp_u64($before['database']['tables']['eb_member_right']['metadata']['table']['AUTO_INCREMENT']),
        'TASK6_THREE_MEMBER_RIGHT_NATIVE_GENERATED_ID');
    $actual=m5pcp_rows($db,'SELECT * FROM eb_member_right WHERE id = ?',[$id]);m4fn_expect(count($actual)===1,'TASK6_THREE_MEMBER_RIGHT_FULL_NATIVE_READBACK');
    $row=m4trp_expected_row($before['database']['tables']['eb_member_right']['metadata'],$actual[0],
        ['id'=>$id,'right_type'=>$type,'title'=>'普通报价权益','number'=>$number,'status'=>1,'add_time'=>$now]);
    $expected=$before['rows']['eb_member_right'];$expected[]=$row;$after=m4trp_snapshot($db);
    m4trp_database_delta($before,$after,['eb_member_right'=>$expected],['eb_member_right'=>1]);
    m4fn_expect($before['redis']['keys']===$after['redis']['keys'],'TASK6_THREE_MEMBER_RIGHT_INSERT_COMPLETE_REDIS_ZERO');return $row;
}
/** Call only after 98 real ordinary orders have committed; existing UID/credential source stays untouched. */
function m4tr_v1_member_preparation(\think\App $app,PDO $db,array $owner):array
{
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();m4fn_context($app,$connection,$default,$db);
    $before=m4trp_snapshot($db);$tagBefore=m4tr_crmeb_tag_members();$uid=$owner['uid'];
    m4fn_expect($before['rows']['eb_member_right']===[]&&$before['rows']['eb_system_user_level']===[]&&$before['rows']['eb_user_level']===[],
        'TASK6_THREE_ORIGINAL_FRESH_MEMBER_RIGHT_AND_LEVEL_MATERIAL');
    $users=m5pcp_rows($db,'SELECT * FROM eb_user WHERE uid = ?',[$uid]);
    m4fn_expect(count($users)===1&&m5pcp_int($users[0]['level'],0,0)===0&&m5pcp_int($users[0]['is_money_level'],0,0)===0
        &&m5pcp_int($users[0]['is_ever_level'],0,0)===0,'TASK6_THREE_REAL_EXISTING_BUYER_BEFORE_QUALIFICATION');$userBefore=$users[0];
    $keys=['member_func_status','member_card_status','member_price_status'];$original=[];
    foreach($keys as$key){$rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$key]);
        m4fn_expect(count($rows)===1&&$rows[0]['value']==='0','TASK6_THREE_MEMBERSHIP_EXISTING_EXACT_NUMERIC_JSON_ZERO');$original[$key]=$rows[0];}
    \think\facade\Db::transaction(static function(\think\db\ConnectionInterface $active)use($original):void{
        $pdo=m4cb_transaction_pdo($active);
        foreach($original as$key=>$row){
            m4fn_expect(m5pcp_rows($pdo,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$key])===[$row],
                'TASK6_THREE_MEMBERSHIP_SAME_DEFAULT_FULL_CONFIG_BEFORE');
            $statement=$pdo->prepare('UPDATE eb_system_config SET value = ? WHERE id = ? AND menu_name = ? AND value = ?');
            m4fn_expect($statement instanceof PDOStatement,'TASK6_THREE_MEMBERSHIP_ORIGINAL_CONFIG_PREPARE');
            foreach(['1',(string)$row['id'],$key,'0']as$index=>$part)
                m4fn_expect($statement->bindValue($index+1,$part,PDO::PARAM_STR),'TASK6_THREE_MEMBERSHIP_ORIGINAL_CONFIG_BIND');
            m4fn_expect($statement->execute()&&$statement->rowCount()===1,'TASK6_THREE_MEMBERSHIP_ORIGINAL_CONDITIONAL_UPDATE');
            $row['value']='1';m4fn_expect(m5pcp_rows($pdo,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$key])===[$row],
                'TASK6_THREE_MEMBERSHIP_SAME_DEFAULT_FULL_CONFIG_READBACK');
        }
        m4fn_expect(m4cb_transaction_pdo($active)===$pdo,'TASK6_THREE_MEMBERSHIP_SAME_DEFAULT_PDO');
    });
    $rows=$before['rows']['eb_system_config'];$changed=0;
    foreach($rows as&$row)foreach($original as$old)if($row===$old){$row['value']='1';++$changed;break;}unset($row);
    m4fn_expect($changed===3,'TASK6_THREE_MEMBERSHIP_EXACT_THREE_CONFIG_ROWS');$written=m4trp_snapshot($db);
    m4trp_database_delta($before,$written,['eb_system_config'=>$rows]);
    m4fn_expect($before['redis']['keys']===$written['redis']['keys'],'TASK6_THREE_MEMBERSHIP_NATIVE_WRITER_COMPLETE_CACHE_ZERO');
    $expectedCache=[];$physical=[];$redis=audit_redis();
    foreach($keys as$key){$logical='system_config_'.$key;$name='c:'.$logical;$present=array_key_exists(hash('sha256',$name),$written['redis']['keys']);
        if($present)m4fn_expect($redis->rawCommand('TYPE',$name)==='string'&&$redis->rawCommand('GET',$name)==='0'
            &&$redis->rawCommand('PEXPIRETIME',$name)===-1,'TASK6_THREE_MEMBERSHIP_OLD_REAL_CONFIGURATION_CACHE_ZERO');
        m4fn_expect(\crmeb\services\CacheService::delete($logical)===$present&&$redis->rawCommand('EXISTS',$name)===0,
            'TASK6_THREE_MEMBERSHIP_ORIGINAL_EXACT_INVALIDATION');
        m4fn_expect($app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($key)===1&&sys_config($key)==='1',
            'TASK6_THREE_MEMBERSHIP_REAL_PRODUCTION_GETTERS_ONE');$expectedCache[$name]='1';$physical[]=$name;
        m4fn_expect($redis->rawCommand('EXISTS',$name.'_lock')===0,'TASK6_THREE_MEMBERSHIP_NO_REAL_GETTER_LOCK_REMAINS');
    }
    $warmed=m4trp_snapshot($db);m4trp_database_delta($written,$warmed,[]);
    m4tr_cache_delta($written['redis'],$warmed['redis'],$expectedCache,$tagBefore,$physical);
    $rights=[m4tr_v1_member_right($db,'vip_price',1),m4tr_v1_member_right($db,'express',80)];
    $qualifiedBefore=m4trp_snapshot($db);$start=time();
    $changed=$app->make(\app\services\user\UserServices::class)->setMemberOverdueTime(30,$uid,1,'month');$end=time();
    m4fn_expect($changed instanceof \app\model\user\User,'TASK6_THREE_ORIGINAL_REAL_MEMBERSHIP_QUALIFICATION_WRITER');
    $users=m5pcp_rows($db,'SELECT * FROM eb_user WHERE uid = ?',[$uid]);m4fn_expect(count($users)===1,'TASK6_THREE_QUALIFICATION_FULL_NATIVE_USER');$user=$users[0];
    $overdue=m5pcp_int($user['overdue_time'],1);
    m4fn_expect($overdue>=$start+2592000&&$overdue<=$end+2592000&&$overdue>time(),
        'TASK6_THREE_ORIGINAL_QUALIFICATION_ACTUAL_CLOCK_INTERVAL');$expectedUser=$userBefore;
    foreach(['is_money_level'=>1,'is_ever_level'=>0,'overdue_time'=>$overdue]as$key=>$value)
        $expectedUser[$key]=m4fn_native_value($qualifiedBefore['database']['tables']['eb_user']['metadata']['columns'][$key],$value);
    m4fn_expect($user===$expectedUser,'TASK6_THREE_QUALIFICATION_ONLY_ORIGINAL_THREE_USER_FIELDS');$allUsers=$qualifiedBefore['rows']['eb_user'];$replaced=0;
    foreach($allUsers as&$row)if($row===$userBefore){$row=$expectedUser;++$replaced;}unset($row);
    m4fn_expect($replaced===1,'TASK6_THREE_QUALIFICATION_ONE_TRUE_EXISTING_BUYER');$after=m4trp_snapshot($db);
    m4trp_database_delta($qualifiedBefore,$after,['eb_user'=>$allUsers]);
    m4fn_expect($qualifiedBefore['redis']['keys']===$after['redis']['keys'],'TASK6_THREE_QUALIFICATION_ORIGINAL_WRITER_NO_CACHE_DELTA');
    $model=$app->make(\app\services\user\UserServices::class)->getUserInfo($uid);
    m4fn_expect($model instanceof \app\model\user\User,'TASK6_THREE_REAL_MEMBERSHIP_USER_MODEL_EXISTS');
    // User::toArray hides actual credential/IP fields; compare native getData without changing that real display policy.
    foreach($user as$key=>$value)m4fn_expect($model->getData($key)===$value,'TASK6_THREE_REAL_MEMBERSHIP_MODEL_NATIVE_FIELD');
    m4fn_expect((string)$app->make(\app\services\user\member\MemberCardServices::class)->isOpenMemberCard('vip_price')==='1'
        &&(string)$app->make(\app\services\user\member\MemberCardServices::class)->isOpenMemberCard('express')==='80',
        'TASK6_THREE_REAL_MEMBERSHIP_GETTER_AND_NATIVE_RIGHTS');
    $checked=m4trp_snapshot($db);m4trp_database_delta($after,$checked,[]);
    m4fn_expect($after['redis']['keys']===$checked['redis']['keys'],'TASK6_THREE_MEMBERSHIP_GETTER_READ_COMPLETE_ZERO');
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);
    return ['schema'=>1,'scope'=>'PREPARATION_ORIGINAL_MEMBERSHIP_ONLY','uid'=>$uid,'user_before'=>$userBefore,'user_after'=>$user,
        'rights'=>$rights,'qualification_interval'=>[$start,$end],'discount_percent'=>'100.00','member_price'=>'16.00',
        'base_sku_price'=>'20.15','member_saving'=>'4.15','expected_physical_raw'=>$expectedCache,'physical_tag_names'=>$physical,
        'before_sha256'=>hash('sha256',serialize($before)),'after_sha256'=>hash('sha256',serialize($checked))];
}

/** One real persistent cart/confirmation/create/postCommit; independent saved/native oracle, never a DTO oracle. */
function m4tr_v1_create_one(\think\App $app,PDO $db,array $owner,array $fixture,string $kind,int $ordinal):array
{
    m4fn_expect(in_array($kind,['ordinary','member'],true)&&$ordinal>=1&&$ordinal<=99,'TASK6_THREE_FIXED_REAL_V1_PREPARE_CASE');
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();m4fn_context($app,$connection,$default,$db);
    $uid=$owner['uid'];$mer=m5pcp_int($fixture['merchant']['mer_id'],1);$p=m5pcp_int($fixture['products'][$kind]['id'],1);
    $s=m5pcp_int($fixture['skus'][$kind]['id'],1);$a=m5pcp_int($fixture['address']['id'],1);
    $products=m5pcp_rows($db,'SELECT * FROM eb_store_product WHERE id = ?',[$p]);$skus=m5pcp_rows($db,'SELECT * FROM eb_store_product_attr_value WHERE id = ?',[$s]);
    $users=m5pcp_rows($db,'SELECT * FROM eb_user WHERE uid = ?',[$uid]);
    m4fn_expect(count($products)===1&&count($skus)===1&&count($users)===1,'TASK6_THREE_REAL_V1_NATIVE_CATALOG_AND_OWNER');
    $product=$products[0];$sku=$skus[0];$userRow=$users[0];$member=$kind==='member';$price=$member?'16.00':'20.15';$saving=$member?'4.15':'0.00';
    m4fn_expect($sku['price']==='20.15'&&$sku['cost']==='7.45'&&$sku['vip_price']===($member?'16.00':'0.00')
        &&m5pcp_int($product['freight'],1,1)===1&&m5pcp_int($product['temp_id'],0,0)===0
        &&m5pcp_int($product['is_vip'],0,1)===($member?1:0)&&m5pcp_int($userRow['level'],0,0)===0
        &&m5pcp_int($userRow['is_money_level'],0,1)===($member?1:0),'TASK6_THREE_INDEPENDENT_ACTUAL_SKU_PRICE_AND_QUALIFICATION');
    if($member){$rights=m5pcp_rows($db,'SELECT * FROM eb_member_right WHERE right_type = ?',['vip_price']);
        m4fn_expect(count($rights)===1&&m5pcp_int($rights[0]['status'],1,1)===1&&m5pcp_int($rights[0]['number'],1,1)===1
            &&m5pcp_int($userRow['overdue_time'],1)>time()&&$price!==$sku['price'],
            'TASK6_THREE_REAL_MEMBER_PRICE_DISCRIMINATOR_BEFORE_ORDER');}
    m4fn_expect(m5pcp_rows($db,'SELECT * FROM eb_store_cart WHERE uid = ? AND product_id = ? AND product_attr_unique = ? AND is_del = 0 AND is_new = 0 AND is_pay = 0 AND status = 1',
        [$uid,$p,$sku['unique']])===[],'TASK6_THREE_EACH_REAL_V1_GETS_A_NEW_NATURAL_CART');
    $before=m4trp_snapshot($db);$tagBefore=m4tr_crmeb_tag_members();$logBefore=\think\facade\Log::getLog();$runtimeBefore=m4tr_parent_runtime_files($app);
    $start=time();$ref=$app->make(\app\services\order\StoreCartServices::class)->setCart($uid,$p,1,$sku['unique'],0,false);$cartEnd=time();
    $ref=(string)$ref;m4fn_expect(preg_match('/\A[1-9][0-9]*\z/D',$ref)===1,'TASK6_THREE_REAL_PERSISTENT_CART_REFERENCE');
    $cartRows=m5pcp_rows($db,'SELECT * FROM eb_store_cart WHERE id = ?',[$ref]);m4fn_expect(count($cartRows)===1,'TASK6_THREE_REAL_CART_NATIVE_ONE');
    $cartId=$before['database']['tables']['eb_store_cart']['metadata']['table']['AUTO_INCREMENT'];
    m4fn_expect($ref===m5pcp_u64($cartId),'TASK6_THREE_NATURAL_CART_NATIVE_AUTO_ID');
    $cart=m4trp_expected_row($before['database']['tables']['eb_store_cart']['metadata'],$cartRows[0],
        ['id'=>$ref,'uid'=>$uid,'product_id'=>$p,'product_attr_unique'=>$sku['unique'],'cart_num'=>1,'type'=>'0',
        'seckill_id'=>0,'bargain_id'=>0,'combination_id'=>0,'advance_id'=>0,'add_time'=>m4trp_time($cartRows[0]['add_time'],$start,$cartEnd)]);
    $afterCart=m4trp_snapshot($db);$newLogs=array_values(array_filter($afterCart['rows']['eb_store_product_log'],
        static fn(array $row):bool=>!in_array($row,$before['rows']['eb_store_product_log'],true)));
    m4fn_expect(count($newLogs)===1,'TASK6_THREE_CART_REAL_SYNCHRONOUS_STAT_ROW');
    $cartLog=m4trp_expected_row($before['database']['tables']['eb_store_product_log']['metadata'],$newLogs[0],
        ['id'=>$before['database']['tables']['eb_store_product_log']['metadata']['table']['AUTO_INCREMENT'],'type'=>'cart','product_id'=>$p,
        'uid'=>$uid,'cart_num'=>1,'add_time'=>m4trp_time($newLogs[0]['add_time'],$start,$cartEnd)]);
    $cartSet=$before['rows']['eb_store_cart'];$cartSet[]=$cart;$logSet=$before['rows']['eb_store_product_log'];$logSet[]=$cartLog;
    m4trp_database_delta($before,$afterCart,['eb_store_cart'=>$cartSet,'eb_store_product_log'=>$logSet],['eb_store_cart'=>1,'eb_store_product_log'=>1]);
    m4fn_expect($before['redis']['keys']===$afterCart['redis']['keys'],'TASK6_THREE_REAL_PERSISTENT_CART_COMPLETE_REDIS_ZERO');
    $user=$app->make(\app\services\user\UserServices::class)->getUserInfo($uid);
    m4fn_expect($user instanceof \app\model\user\User,'TASK6_THREE_REAL_V1_OWNER_MODEL');
    foreach($userRow as$key=>$value)m4fn_expect($user->getData($key)===$value,'TASK6_THREE_V1_OWNER_MODEL_MATCHES_NATIVE_DATA');
    $choice=\app\services\marketplace\order\MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>$ref,'addressId'=>$a,'new'=>false]);
    $orders=$app->make(\app\services\order\StoreOrderServices::class);$confirmStart=time();
    $confirmation=$orders->getOrderConfirmData($user->toArray(),$choice['cartId'],false,$a,1,0,$choice);$confirmEnd=time();$key=$confirmation['orderKey']??null;
    m4fn_expect(is_string($key)&&\app\services\marketplace\order\MarketplaceOrderInput::confirmationKey($key)===$key,'TASK6_THREE_TRUE_V1_CONFIRMATION_KEY');$confirmationName='c:user_order_'.$uid.$key;$redis=audit_redis();
    $cached=\crmeb\services\CacheService::get('user_order_'.$uid.$key);
    m4fn_expect(is_array($cached)&&$redis->rawCommand('TYPE',$confirmationName)==='string'
        &&$redis->rawCommand('GET',$confirmationName)===serialize($cached),'TASK6_THREE_TRUE_V1_CONFIRMATION_NATIVE_BYTES');
    $expiry=$redis->rawCommand('PEXPIRETIME',$confirmationName);
    m4fn_expect(is_int($expiry)&&$expiry>=($confirmStart+600)*1000&&$expiry<($confirmEnd+601)*1000,
        'TASK6_THREE_TRUE_V1_CONFIRMATION_ORIGINAL_SIX_HUNDRED_SECONDS');
    $frame=\app\services\marketplace\order\MarketplaceCartSelection::frame($cached,$uid);
    $expectedSelection=[['cart_ref'=>$ref,'product_id'=>$p,'sku_id'=>$s,'sku_type'=>0,'unique'=>$sku['unique'],'qty'=>1]];
    m4fn_expect($frame['mer_id']===$mer&&$frame['source_kind']==='persistent'&&$frame['cart_refs']===[$ref]
        &&$frame['selection']===$expectedSelection&&$frame['custom_form']===[],
        'TASK6_THREE_REAL_V1_COMPLETE_SELECTION_AND_TRUE_EMPTY_FORM');
    $group=$orders->getCacheOrderInfo($uid,$key);m4fn_expect(is_array($group),'TASK6_THREE_REAL_CURRENT_CONFIRMATION_GROUP');
    $computed=$app->make(\app\services\order\StoreOrderComputedServices::class)->setParamData([])->computedOrder($uid,$user->toArray(),$group,$a,'',false,0,true,1,0,true);
    m4fn_expect(count($group['cartInfo'])===1&&$group['priceGroup']['totalPrice']===$price&&$group['priceGroup']['costPrice']==='7.45'
        &&$computed['pay_price']===$price&&$computed['pay_postage']==='0.00','TASK6_THREE_INDEPENDENT_ONE_UNIT_FREE_FREIGHT_TOTALS');$quote=$group['cartInfo'][0];
    m4fn_expect((string)$quote['id']===$ref&&$quote['cart_num']===1&&$quote['sum_price']==='20.15'&&$quote['costPrice']==='7.45'
        &&$quote['truePrice']===$price&&$quote['vip_truePrice']===$saving
        &&$quote['price_type']===($member?'member':'level')&&$quote['postage_price']==='0.00'&&$quote['origin_postage_price']==='0.00',
        'TASK6_THREE_QUOTE_AMOUNTS_FROM_NATIVE_SKU_AND_RIGHTS_NOT_READ_DTO');
    foreach(['id'=>$p,'mer_id'=>$mer,'price'=>'20.15','cost'=>'7.45','freight'=>1,'temp_id'=>0,'is_vip'=>$member?1:0]as$field=>$value)
        m4fn_expect((string)$quote['productInfo'][$field]===(string)$value,'TASK6_THREE_QUOTE_COMPLETE_SELECTED_ROOT_INPUT');
    foreach(['id'=>$s,'product_id'=>$p,'type'=>0,'unique'=>$sku['unique'],'price'=>'20.15','cost'=>'7.45','vip_price'=>$member?'16.00':'0.00','suk'=>$sku['suk']]as$field=>$value)
        m4fn_expect((string)$quote['productInfo']['attrInfo'][$field]===(string)$value,'TASK6_THREE_QUOTE_SELECTED_SKU_INPUT');
    $beforeCreate=m4trp_snapshot($db);m4trp_database_delta($afterCart,$beforeCreate,[]);
    m4tr_cache_delta($afterCart['redis'],$beforeCreate['redis'],[$confirmationName=>serialize($cached)],$tagBefore,[$confirmationName],[],[$confirmationName=>$expiry]);
    $raw=['mer_id'=>$mer,'cartId'=>$ref,'new'=>false,'addressId'=>$a,'invoice_id'=>0,'idem_key'=>'three-read:'.$ordinal.':'.bin2hex(random_bytes(16)),
        'payType'=>'','mark'=>'三端正常读取准备','gift_mark'=>'','custom_form'=>[]];
    $input=\app\services\marketplace\order\MarketplaceOrderInput::create($uid,$key,$raw);$createStart=time();
    $result=$app->make(\app\services\marketplace\order\MarketplaceOrderCreateServices::class)->create($uid,$key,$raw);$createEnd=time();
    m4c_entry_keys($result,['order_id','key']);m4fn_expect($result['key']===$key&&is_string($result['order_id']),
        'TASK6_THREE_REAL_CREATE_PUBLIC_BINDING');
    $actualRoots=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE uid = ? AND order_id = ?',[$uid,$result['order_id']]);
    m4fn_expect(count($actualRoots)===1,'TASK6_THREE_REAL_NEW_NATIVE_ROOT');$id=m5pcp_int($actualRoots[0]['id'],1);
    $added=m4trp_time($actualRoots[0]['add_time'],$createStart,$createEnd);$address=$fixture['address'];
    $rootValues=['id'=>$beforeCreate['database']['tables']['eb_store_order']['metadata']['table']['AUTO_INCREMENT'],'uid'=>$uid,'mer_id'=>$mer,
        'order_id'=>$result['order_id'],'unique'=>$key,'cart_id'=>json_encode([$ref],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
        'total_num'=>1,'add_time'=>$added,'pay_uid'=>$uid,'marketplace_trade_version'=>1,'shipping_type'=>1,'is_gift'=>0,'paid'=>0,'is_cancel'=>0,
        'total_price'=>$price,'total_postage'=>'0.00','pay_price'=>$price,'pay_postage'=>'0.00','cost'=>'7.45','real_name'=>$address['real_name'],
        'user_phone'=>$address['phone'],'user_address'=>$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail'],
        'gift_mark'=>'','pay_type'=>'','channel_type'=>$userRow['user_type'],'province'=>'','merchant_name_snapshot'=>$fixture['merchant']['name'],
        'merchant_after_sale_contact_snapshot'=>$fixture['merchant']['after_sale_contact'],'mark'=>'三端正常读取准备','custom_form'=>'[]'];
    $root=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_order']['metadata'],$actualRoots[0],$rootValues);
    m4fn_expect(m5pcp_int($root['id'],1)===$id,'TASK6_THREE_NATIVE_ORDER_ID_IS_CREATED_ID');
    // Observe the actual original Snowflake key's natural absence before the full strict after window.
    $sequence=m4tr_sequence_sources($db,$app,[$root]);$barrier=m4tr_sequence_expiry_barrier($db,$sequence);$after=m4trp_snapshot($db);
    $actualItems=m5pcp_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid = ?',[$id]);
    $actualMoves=m5pcp_rows($db,'SELECT * FROM eb_marketplace_stock_movement WHERE order_id = ?',[$id]);
    $actualLedgers=m5pcp_rows($db,'SELECT * FROM eb_marketplace_order_idempotency WHERE order_id = ?',[$id]);
    $actualBirths=m5pcp_rows($db,'SELECT * FROM eb_marketplace_payment_registry WHERE order_id = ?',[$id]);
    m4fn_expect(count($actualItems)===1&&count($actualMoves)===1&&count($actualLedgers)===1&&count($actualBirths)===1,
        'TASK6_THREE_NATIVE_ONE_ITEM_RESERVE_LEDGER_AND_PAYMENT_BIRTH');
    $movementId=$beforeCreate['database']['tables']['eb_marketplace_stock_movement']['metadata']['table']['AUTO_INCREMENT'];
    $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$p,'sku_id'=>$s,'sku_type'=>0,
        'sku_unique'=>$sku['unique'],'qty'=>1,'kind'=>'reserve','cart_refs'=>[$ref]];
    $move=m4trp_expected_row($beforeCreate['database']['tables']['eb_marketplace_stock_movement']['metadata'],$actualMoves[0],
        ['id'=>$movementId,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$p,'sku_id'=>$s,'sku_type'=>0,'sku_unique'=>$sku['unique'],'qty'=>1,'kind'=>'reserve',
        'idem_key'=>'reserve:'.$id.':'.$s,'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>'customer','actor_id'=>$uid,
        'request_digest'=>hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($action),true),
        'sku_stock_before'=>$sku['stock'],'sku_stock_after'=>m5pcp_int($sku['stock'],1)-1,'sku_sales_before'=>$sku['sales'],'sku_sales_after'=>m5pcp_int($sku['sales'],0)+1,
        'root_stock_before'=>$product['stock'],'root_stock_after'=>m5pcp_int($product['stock'],1)-1,'root_sales_before'=>$product['sales'],
        'root_sales_after'=>m5pcp_int($product['sales'],0)+1,'add_time'=>$added]);
    $savedCart=$quote;$savedCart['id']=$ref;$savedCart['product_id']=$p;$savedCart['coupon_id']=0;$savedCart['sum_true_price']=$price;
    foreach(['coupon_price','integral_price','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage']as$field)$savedCart[$field]='0.00';
    $savedCart['use_integral']=0;$savedCart['marketplace']=['cart_ref'=>$ref,'sku_id'=>$s,'sku_type'=>0,'sku_unique'=>$sku['unique'],'reserve_id'=>(string)$movementId];
    $savedCart=json_decode(json_encode($savedCart,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
    $item=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_order_cart_info']['metadata'],$actualItems[0],
        ['id'=>$beforeCreate['database']['tables']['eb_store_order_cart_info']['metadata']['table']['AUTO_INCREMENT'],'oid'=>$id,'uid'=>$uid,'mer_id'=>$mer,
        'cart_id'=>$ref,'product_id'=>$p,'cart_num'=>1,'surplus_num'=>1,'refund_num'=>0,'split_status'=>0,'unique'=>md5($ref.$id),'cart_info'=>['json'=>$savedCart]]);
    $ledger=m4trp_expected_row($beforeCreate['database']['tables']['eb_marketplace_order_idempotency']['metadata'],$actualLedgers[0],
        ['uid'=>$uid,'mer_id'=>$mer,'idem_key'=>$input['idem_key'],'request_digest'=>hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($input['request']),true),
        'confirmation_ref'=>hash('sha256',$key,true),'source_kind'=>'persistent','cart_refs'=>json_encode([$ref],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
        'state'=>1,'order_id'=>$id,'post_commit_done'=>1,'cancel_at'=>0,'add_time'=>$added]);
    $birth=m4trp_expected_row($beforeCreate['database']['tables']['eb_marketplace_payment_registry']['metadata'],$actualBirths[0],
        ['order_id'=>$id,'uid'=>$uid,'mer_id'=>$mer,'payment_contract_version'=>1,'born_at'=>$added,'attempt_generation'=>'0',
        'winner_attempt_id'=>null,'fulfillment_hold'=>0,'hold_reason'=>null,'hold_attempt_id'=>null,'hold_at'=>null]);
    $statuses=m5pcp_rows($db,'SELECT * FROM eb_store_order_status WHERE oid = ?',[$id]);m4fn_expect(count($statuses)===1,'TASK6_THREE_REAL_CREATED_STATUS_ONE');
    $status=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_order_status']['metadata'],$statuses[0],
        ['oid'=>$id,'change_type'=>'cache_key_create_order','change_message'=>'订单生成','change_time'=>$added]);
    $orderLogs=array_values(array_filter($after['rows']['eb_store_product_log'],static fn(array $row):bool=>!in_array($row,$beforeCreate['rows']['eb_store_product_log'],true)));
    m4fn_expect(count($orderLogs)===1,'TASK6_THREE_REAL_POSTCOMMIT_PRODUCT_STAT_ONE');
    $orderLog=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_product_log']['metadata'],$orderLogs[0],
        ['id'=>$beforeCreate['database']['tables']['eb_store_product_log']['metadata']['table']['AUTO_INCREMENT'],'type'=>'order','product_id'=>$p,'uid'=>$uid,
        'visit_num'=>0,'cart_num'=>0,'order_num'=>1,'pay_num'=>0,'pay_price'=>'0.00','cost_price'=>'0.00','pay_uid'=>0,
        'refund_num'=>0,'refund_price'=>'0.00','collect_num'=>0,'add_time'=>$added]);
    $changes=[];$inserted=[];
    foreach(['eb_store_order'=>$root,'eb_store_order_cart_info'=>$item,'eb_marketplace_stock_movement'=>$move,
        'eb_marketplace_order_idempotency'=>$ledger,'eb_marketplace_payment_registry'=>$birth,'eb_store_order_status'=>$status,'eb_store_product_log'=>$orderLog]as$table=>$row){
        $changes[$table]=$beforeCreate['rows'][$table];$changes[$table][]=$row;
        if(in_array($table,['eb_store_order','eb_store_order_cart_info','eb_marketplace_stock_movement','eb_store_product_log'],true))$inserted[$table]=1;
    }
    $changes['eb_store_cart']=array_values(array_filter($beforeCreate['rows']['eb_store_cart'],static fn(array $row):bool=>$row!==$cart));
    m4fn_expect(count($changes['eb_store_cart'])===count($beforeCreate['rows']['eb_store_cart'])-1,'TASK6_THREE_CREATE_CONSUMES_ONLY_TRUE_SELECTED_CART');
    foreach(['eb_store_product'=>['id'=>$p,'before'=>$product],'eb_store_product_attr_value'=>['id'=>$s,'before'=>$sku]]as$table=>$selected){
        $replacement=$selected['before'];foreach(['stock'=>m5pcp_int($replacement['stock'],1)-1,'sales'=>m5pcp_int($replacement['sales'],0)+1]as$field=>$value)
            $replacement[$field]=m4fn_native_value($beforeCreate['database']['tables'][$table]['metadata']['columns'][$field],$value);
        $changes[$table]=m4fn_replace_row($beforeCreate['rows'][$table],$replacement);
    }
    m4trp_database_delta($beforeCreate,$after,$changes,$inserted);
    m4tr_cache_delta($beforeCreate['redis'],$after['redis'],[],$tagBefore,[$confirmationName],[$confirmationName]);
    m4fn_expect(\think\facade\Log::getLog()===$logBefore&&m4tr_parent_runtime_files($app)===$runtimeBefore,
        'TASK6_THREE_V1_CAUGHT_PRODUCER_OR_POSTCOMMIT_ERROR_IS_NOT_SUCCESS');
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);
    return ['root'=>$root,'item'=>$item,'movement'=>$move,'ledger'=>$ledger,'payment_registry'=>$birth,'status'=>$status,
        'ordinal'=>$ordinal,'price_kind'=>$kind,'independent_unit_price'=>$price,'native_sku_base_price'=>'20.15','native_member_saving'=>$saving,
        'confirmation_expiry_at_ms'=>$expiry,'interval'=>[$createStart,$createEnd],'sequence_barrier'=>$barrier,
        'whole_before_sha256'=>hash('sha256',serialize($before)),'whole_after_sha256'=>hash('sha256',serialize($after)),
        'full_native_preparation_delta_verified'=>true,'post_commit_done'=>1];
}
/** Fixed 98+1 normal producer, same genuine buyer; no bulk SQL order or state updates. */
function m4tr_v1_create_ninety_nine(\think\App $app,PDO $db,array $owner):array
{
    $fixture=m4tr_v1_fixture($app,$db,$owner);$warm=m4tr_warm_legacy_configuration($app,$db);$records=[];
    for($ordinal=1;$ordinal<=98;++$ordinal){m4fn_phase('three_real_v1_ordinary_'.$ordinal);
        $records[]=m4tr_v1_create_one($app,$db,$owner,$fixture,'ordinary',$ordinal);}
    $membership=m4tr_v1_member_preparation($app,$db,$owner);m4fn_phase('three_real_v1_member_99');
    $records[]=m4tr_v1_create_one($app,$db,$owner,$fixture,'member',99);
    m4fn_expect(count($records)===99&&count(array_unique(array_column(array_column($records,'root'),'id')))===99,
        'TASK6_THREE_ACTUAL_NINETY_NINE_DISTINCT_REAL_V1_ROOTS');$total='0.00';
    foreach($records as$record){m4fn_expect($record['ledger']['cancel_at']===0||$record['ledger']['cancel_at']==='0',
            'TASK6_THREE_EVERY_REAL_NEW_V1_SAVED_DEADLINE_ZERO');$total=bcadd($total,$record['independent_unit_price'],2);}
    m4fn_expect($total===bcadd(bcmul('20.15','98',2),'16.00',2)&&$records[98]['independent_unit_price']!==$records[98]['native_sku_base_price'],
        'TASK6_THREE_FULL_REAL_V1_TOTAL_AND_MEMBER_PRICE_GAP');
    return ['fixture'=>$fixture,'warm'=>$warm,'membership'=>$membership,'records'=>$records,'root_count'=>99,
        'ordinary_count'=>98,'member_count'=>1,'independent_goods_total'=>$total];
}

function m4tr_parent_runtime_files(\think\App $app):array
{
    $root=$app->getRuntimePath();m4fn_expect($root==='/tmp/ms4-fulfillment-normal-'.getenv('AUDIT_RUN_ID').'/'
        &&is_dir($root)&&!is_link($root),'TASK6_THREE_ONE_OWN_PARENT_NORMAL_RUNTIME');$out=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as$file){$name=$file->getPathname();
        m4fn_expect(!$file->isLink()&&$file->isFile()&&$file->getSize()<=2097152&&str_starts_with($name,$root),
            'TASK6_THREE_ONLY_OWN_BOUNDED_REGULAR_RUNTIME_FILES');$relative=substr($name,strlen($root));
        m4fn_expect($relative!==''&&!isset($out[$relative]),'TASK6_THREE_UNIQUE_RUNTIME_PATH');
        $raw=file_get_contents($name);m4fn_expect(is_string($raw)&&strlen($raw)===$file->getSize(),'TASK6_THREE_REAL_COMPLETE_RUNTIME_BYTES');
        $out[$relative]=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
    }ksort($out,SORT_STRING);return $out;
}

/** Same-handle lifecycle derived from the stopped R3 proposal; no retry, alternative argv, HTTP or environment mode. */
function m4tr_legacy_cli(array &$child):array
{
    m4tr_legacy_start($child);return m4tr_legacy_observe($child);
}
function m4tr_legacy_start(array &$child): void
{
    m4fn_expect($child === [], 'TASK6_THREE_CHILD_STATE_MUST_BE_NEW');
    $supervisor = audit_app_proc_stat(getmypid());
    m4fn_expect(getmypid() === 1 && $supervisor !== null && $supervisor['pid'] === 1
        && !audit_app_listener_open(), 'TASK6_THREE_PARENT_ONE_WITHOUT_HTTP');
    $argv = ['setsid', 'php', 'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php', 'legacy'];
    $pipes = [];
    $started = hrtime(true);
    $process = proc_open($argv, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes, '/workspace', null);
    $child = ['process' => $process, 'pipes' => $pipes, 'first_status' => null,
        'last_native_status' => null, 'first_terminal' => null, 'pid' => null,
        'group' => null, 'remaining_members' => null, 'proc_close_exit' => null,
        'native_exit' => null, 'process_closed' => false, 'pipes_eof' => false,
        'term_attempted' => false, 'term_sent' => false, 'failure_code' => null,
        'failure_started_ns' => null, 'close_deadline_ns' => null, 'close_budget_exhausted' => false,
        'stdout' => '', 'stderr' => '', 'counts' => [1 => 0, 2 => 0],
        'hashes' => [1 => hash_init('sha256'), 2 => hash_init('sha256')]];
    $child['supervisor']=$supervisor;$child['argv']=$argv;$child['started']=$started;
    // State owns the real return values BEFORE any start/pipe validation.
    // Even proc_open=false or malformed pipes produces a retained fail receipt;
    // do not throw past an already-created child or discard a partial handle.
    $failure = static function (string $code) use (&$child): void {
        if ($child['failure_code'] === null) {
            $child['failure_code'] = $code;
            $child['failure_started_ns'] = hrtime(true);
            $child['close_deadline_ns'] = $child['failure_started_ns'] + 9000000000;
        }
    };
    if (!is_resource($process)) $failure('CHILD_START_FAILED');
    elseif (array_keys($pipes) !== [1, 2]) $failure('CHILD_PIPE_SHAPE');
    foreach ($pipes as $pipe) {
        try { if (!stream_set_blocking($pipe, false)) $failure('PIPE_NONBLOCKING'); }
        catch (Throwable $error) { $failure('PIPE_MODE_' . get_class($error)); }
    }

}

function m4tr_legacy_observe(array &$child): array
{
    // Only start owns creation. A later normal-close observation consumes this exact same native state.
    $supervisor=$child['supervisor'];$argv=$child['argv'];$started=$child['started'];
    $failure = static function (string $code) use (&$child): void {
        if ($child['failure_code'] === null) {
            $child['failure_code'] = $code;
            $child['failure_started_ns'] = hrtime(true);
            $child['close_deadline_ns'] = $child['failure_started_ns'] + 9000000000;
        }
    };

    while (is_resource($child['process']) || $child['process_closed']) {
        $budgetExpired = $child['close_deadline_ns'] !== null && hrtime(true) >= $child['close_deadline_ns'];
        if ($budgetExpired) $child['close_budget_exhausted'] = true;
        // The only native status reader. Save a non-running observation
        // BEFORE validating its other fields; unknown values stay unknown.
        if ($child['first_terminal'] === null && is_resource($child['process'])) {
            try {
                $status = proc_get_status($child['process']);
                $status = is_array($status) ? array_intersect_key($status,
                    array_flip(['pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'])) : $status;
                $child['last_native_status'] = $status;
                if (is_array($status)) {
                    $child['first_status'] ??= $status;
                    if (($status['running'] ?? null) === false) $child['first_terminal'] = $status;
                }
                m4fn_expect(is_array($status) && is_int($status['pid'] ?? null) && $status['pid'] > 1,
                    'TASK6_THREE_NATIVE_CHILD_PID');
                if ($child['pid'] === null) $child['pid'] = $status['pid'];
                m4fn_expect($status['pid'] === $child['pid'], 'TASK6_THREE_SAME_NATIVE_HANDLE_PID');
            } catch (Throwable $error) { $failure('STATUS_' . get_class($error)); }
        }
        try {
            if ($child['group'] === null && is_int($child['pid'])) {
                $leader = audit_app_proc_stat($child['pid']);
                if ($leader !== null && $leader['ppid'] === 1 && $leader['pgid'] === $child['pid']
                    && $leader['sid'] === $child['pid']) {
                    $group = ['leader_pid' => $child['pid'], 'pgid' => $leader['pgid'],
                        'sid' => $leader['sid'], 'leader_starttime' => $leader['starttime']];
                    $members = audit_app_group_members($group, $supervisor);
                    $child['group'] = $group; $child['remaining_members'] = $members;
                } elseif ($child['first_terminal'] !== null) $failure('GROUP_NOT_OBSERVED_BEFORE_EXIT');
            }
        } catch (Throwable $error) { $failure('REGISTRATION_' . get_class($error)); }

        try {
            $read = [];
            foreach ($child['pipes'] as $pipe) if (is_resource($pipe) && !feof($pipe)) $read[] = $pipe;
            if ($read !== []) {
                $write = null; $except = null;
                $ready = @stream_select($read, $write, $except, 0, $budgetExpired ? 0 : 200000);
                if ($ready === false) $failure('PIPE_SELECT');
                else foreach ($read as $pipe) {
                    $part = @fread($pipe, 8192);
                    if (!is_string($part)) { $failure('PIPE_READ'); continue; }
                    $channel = array_search($pipe, $child['pipes'], true);
                    if ($channel !== 1 && $channel !== 2) { $failure('PIPE_CHANNEL'); continue; }
                    $child['counts'][$channel] += strlen($part); hash_update($child['hashes'][$channel], $part);
                    if ($channel === 2 && $part !== '') $failure('STDERR_NOT_EMPTY');
                    $name = $channel === 1 ? 'stdout' : 'stderr'; $room = 1048576 - strlen($child[$name]);
                    if ($room > 0) $child[$name] .= substr($part, 0, $room);
                    if ($child['counts'][$channel] > 1048576) $failure('OUTPUT_LIMIT');
                }
            }
            if (!$child['pipes_eof']) {
                $eof = array_keys($child['pipes']) === [1, 2];
                foreach ($child['pipes'] as $pipe) if (!is_resource($pipe) || !feof($pipe)) $eof = false;
                $child['pipes_eof'] = $eof;
            }
        } catch (Throwable $error) { $failure('CAPTURE_' . get_class($error)); }
        if (!empty($GLOBALS['m4c_terminate_requested'])) $failure('PARENT_TERM_REQUESTED');
        $terminal = $child['first_terminal'];
        if ($terminal !== null && (($terminal['pid'] ?? null) !== $child['pid']
            || ($terminal['signaled'] ?? null) !== false || ($terminal['stopped'] ?? null) !== false
            || ($terminal['exitcode'] ?? null) !== 0)) $failure('CHILD_TERMINAL_NOT_ZERO');

        // Every signalling/closure decision uses a fresh successful complete
        // group observation. Observation failure becomes NULL, never stale [].
        $child['remaining_members'] = null;
        try {
            if ($child['group'] !== null) {
                $child['remaining_members'] = audit_app_group_members($child['group'], $supervisor);
                if ($terminal !== null && $child['remaining_members'] !== []) $failure('DESCENDANT_REMAINS_AFTER_DIRECT_TERMINAL');
                if ($child['failure_code'] !== null && !$child['term_attempted'] && $child['remaining_members'] !== []) {
                    $child['term_attempted'] = true;
                    $child['term_sent'] = posix_kill(-$child['group']['pgid'], SIGTERM);
                    if (!$child['term_sent'] && audit_app_group_members($child['group'], $supervisor) !== []) $failure('NORMAL_TERM_FAILED');
                }
            }
        } catch (Throwable $error) { $child['remaining_members'] = null; $failure('GROUP_OBSERVATION_' . get_class($error)); }
        $groupGone = $child['group'] !== null && $child['remaining_members'] === [];
        // Unknown terminal fields do not prevent actual same-handle close
        // after the registered group is truly empty AND both pipes reached EOF.
        // A directly observed running=false likewise proves the handle may
        // close, but absence of group proof still makes the result a failure.
        if (!$child['process_closed'] && $child['pipes_eof'] && ($terminal !== null || $groupGone)) {
            try {
                foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
                $child['proc_close_exit'] = proc_close($child['process']);
                $child['process'] = null; $child['process_closed'] = true;
                $close = $child['proc_close_exit'];
                $terminalExit = is_array($terminal) && ($terminal['pid'] ?? null) === $child['pid']
                    && ($terminal['running'] ?? null) === false && ($terminal['signaled'] ?? null) === false
                    && ($terminal['stopped'] ?? null) === false && is_int($terminal['exitcode'] ?? null)
                    && $terminal['exitcode'] >= 0 ? $terminal['exitcode'] : null;
                if ($terminalExit === null) $failure('NATIVE_TERMINAL_UNKNOWN');
                elseif (is_int($close) && $close >= 0 && $close === $terminalExit) $child['native_exit'] = $close;
                elseif ($close === -1) $child['native_exit'] = $terminalExit;
                else $failure('NATIVE_CLOSE_DISAGREES');
            } catch (Throwable $error) { $failure('CLOSE_' . get_class($error)); }
        }
        if ($child['process_closed']) {
            $child['remaining_members'] = null;
            try {
                if ($child['group'] !== null) {
                    // Direct child already proc_closed: only now reap adopted
                    // children of the exact registered group, without blocking.
                    while (pcntl_waitpid(-$child['group']['pgid'], $waitStatus, WNOHANG) > 0) {
                        if ($child['close_deadline_ns'] !== null && hrtime(true) >= $child['close_deadline_ns']) break;
                    }
                    $child['remaining_members'] = audit_app_group_members($child['group'], $supervisor);
                }
            } catch (Throwable $error) { $failure('FINAL_GROUP_' . get_class($error)); }
            if ($child['group'] === null || $child['remaining_members'] === []) break;
        }
        if ($budgetExpired) break;
        if ($child['close_deadline_ns'] !== null && hrtime(true) >= $child['close_deadline_ns']) continue;
        // Includes repeated status/group/select exceptions; never busy-spin.
        usleep(20000);
    }
    $groupGone = $child['group'] !== null && $child['remaining_members'] === [];
    if (!$child['process_closed'] || !$groupGone) $failure('CLOSURE_NOT_PROVEN');
    if ($child['counts'][2] !== 0) $failure('STDERR_NOT_EMPTY');
    $listener = null;
    try { $listener = audit_app_listener_open(); if ($listener) $failure('UNEXPECTED_HTTP_LISTENER'); }
    catch (Throwable $error) { $failure('LISTENER_OBSERVATION_' . get_class($error)); }
    return ['mode' => 'legacy', 'argv' => $argv, 'cwd' => '/workspace', 'environment_inherited' => true,
        'pid' => $child['pid'], 'supervisor' => array_intersect_key($supervisor, array_flip(['pid', 'pgid', 'sid', 'starttime'])),
        'registered_group' => $child['group'], 'first_native_status' => $child['first_status'],
        'last_native_status' => $child['last_native_status'], 'same_handle_first_terminal' => $child['first_terminal'],
        'proc_close_exit' => $child['proc_close_exit'], 'native_exit' => $child['native_exit'],
        'process_closed' => $child['process_closed'], 'remaining_members' => $child['remaining_members'],
        'registered_group_gone' => $groupGone, 'listener_open' => $listener,
        'term_attempted' => $child['term_attempted'], 'term_sent' => $child['term_sent'], 'normal_only' => true,
        'capture_complete' => $child['pipes_eof'], 'stdout_bytes' => $child['counts'][1],
        'stdout_sha256' => hash_final(hash_copy($child['hashes'][1])), 'stderr_bytes' => $child['counts'][2],
        'stderr_sha256' => hash_final(hash_copy($child['hashes'][2])), 'failure_close_budget_ns' => 9000000000,
        'failure_close_started_ns' => $child['failure_started_ns'], 'failure_close_deadline_ns' => $child['close_deadline_ns'],
        'failure_close_budget_exhausted' => $child['close_budget_exhausted'],
        'observed_elapsed_ns' => hrtime(true) - $started, 'failure_code' => $child['failure_code'],
        'native_completed_normally' => $child['failure_code'] === null && $groupGone
            && $child['process_closed'] && $child['pipes_eof'] && $child['native_exit'] === 0];
}

/** Failure path only. No business action may run between checkpoints; host timeout can only leave INCOMPLETE. */
function m4tr_legacy_normal_close(array &$child):array
{
    do{
        $receipt=m4tr_legacy_observe($child);$GLOBALS['m4tr_legacy_native_receipt']=$receipt;
        if($receipt['process_closed']&&$receipt['registered_group_gone'])return $receipt;
        // No resource means there is no same handle left to consume. Preserve the unknown/failure proof.
        if(!is_resource($child['process'])&&!$child['process_closed'])return $receipt;
        if($child['process_closed']&&$child['group']===null)return $receipt;
        // The first failure timestamp/deadline remains unchanged after its original 9-second observation budget.
        // Each subsequent checkpoint still drains actual pipes and observes/reaps only the verified original group.
        usleep(50000);
    }while(true);
}

/** Independent child wire expectation from original native operation/cent-range oracle, never readHistory/DTO output. */
function m4tr_children_expected(array $source,array $snapshot,array $history,bool $detail=false):array
{
    $rootId=$source['identity']['id'];$nativeRoots=[];$nativeChildren=[];$nativeItems=[];$nativeOperations=[];$nativeRanges=[];
    foreach($snapshot['rows']['eb_store_order']as$row){$id=m5pcp_int($row['id'],1);
        if($id===$rootId)$nativeRoots[]=$row;
        elseif(m5pcp_int($row['pid'],0)===$rootId)$nativeChildren[$id]=$row;
    }
    foreach($snapshot['rows']['eb_store_order_cart_info']as$row){$oid=m5pcp_int($row['oid'],1);if(isset($nativeChildren[$oid]))$nativeItems[$oid][]=$row;}
    foreach($snapshot['rows']['eb_marketplace_fulfillment_operation']as$row)
        if(m5pcp_int($row['root_order_id'],1)===$rootId)$nativeOperations[]=$row;
    foreach($snapshot['rows']['eb_marketplace_fulfillment_range']as$row)
        if(m5pcp_int($row['root_order_id'],1)===$rootId)$nativeRanges[]=$row;
    m4fn_expect(count($nativeRoots)===1&&count($nativeChildren)===count($history['ships'])
        &&m4fn_row_multiset($nativeOperations)===m4fn_row_multiset(array_column($history['operations'],'operation'))
        &&m4fn_row_multiset($nativeRanges)===m4fn_row_multiset(array_column($history['operations'],'range')),
        'TASK6_THREE_EXPECTED_COMPLETE_REAL_FAMILY_OPERATION_AND_RANGE_SET');$root=$nativeRoots[0];
    $ships=[];$shipQty=0;$receivedQty=0;$lastShip=null;$lastReceived=null;
    foreach($history['ships']as$ship){$id=m5pcp_int($ship['child']['id'],1);
        m4fn_expect(!isset($ships[$id])&&isset($nativeChildren[$id])&&$ship['action']==='ship'
            &&m5pcp_int($ship['operation']['state'],1,1)===1,'TASK6_THREE_EXPECTED_ONE_TRUE_SHIP_PER_CHILD');
        $ships[$id]=$ship;$shipQty+=m5pcp_int($ship['plan']['totals']['quantity'],1);
        $time=m5pcp_int($ship['operation']['shipped_at'],1);$lastShip=$lastShip===null?$time:max($lastShip,$time);
    }
    foreach($history['operations']as$record)if($record['action']==='receive'){
        $receivedQty+=m5pcp_int($record['range']['quantity'],1);$time=m5pcp_int($record['operation']['received_at'],1);
        $lastReceived=$lastReceived===null?$time:max($lastReceived,$time);
    }
    $summary=m4fn_summary($shipQty,$receivedQty,count($ships));$expectedRoot=$source['saved']['order'];
    $status=$summary['closed']?2:($shipQty===3?1:0);
    $expectedRoot['status']=m4fn_native_value($snapshot['database']['tables']['eb_store_order']['metadata']['columns']['status'],$status);
    m4fn_expect($root===$expectedRoot&&m5pcp_int($root['paid'],1,1)===1&&$root['pay_type']==='marketplace_test',
        'TASK6_THREE_EXPECTED_NATIVE_PARENT_PAYMENT_ONLY_ORIGINAL_ROOT_STATUS_DELTA');
    $ordered=array_values($ships);usort($ordered,static fn(array $a,array $b):int=>bccomp($b['operation_id'],$a['operation_id'],0));$dtos=[];
    foreach($ordered as$ship){$childId=m5pcp_int($ship['child']['id'],1);$child=$nativeChildren[$childId];$q=m5pcp_int($ship['plan']['totals']['quantity'],1);
        $received=0;$childLast=null;$intervals=[];
        foreach($history['operations']as$record)if($record['action']==='receive'&&m5pcp_int($record['operation']['child_order_id'],1)===$childId){
            m4fn_expect($record['internal']['target_operation_id']===$ship['operation_id']
                &&m5pcp_u64($record['range']['source_ship_range_id'])===$ship['range_id'],'TASK6_THREE_EXPECTED_RECEIPT_TRUE_SAVED_SHIP_REFERENCE');
            $from=m5pcp_int($record['range']['unit_start'],0);$to=m5pcp_int($record['range']['unit_end'],1);
            m4fn_expect($to>$from&&$to-$from===m5pcp_int($record['range']['quantity'],1),
                'TASK6_THREE_EXPECTED_ACTUAL_RECEIPT_INTERVAL_QUANTITY');
            foreach($intervals as$previous)m4fn_expect($to<=$previous[0]||$from>=$previous[1],
                'TASK6_THREE_EXPECTED_RECEIPT_INTERVALS_DO_NOT_OVERLAP');$intervals[]=[$from,$to];
            $received+=$to-$from;$time=m5pcp_int($record['operation']['received_at'],1);$childLast=$childLast===null?$time:max($childLast,$time);
        }
        m4fn_expect($received<=$q,'TASK6_THREE_EXPECTED_CHILD_RECEIPT_WITHIN_REAL_SHIPPED_QUANTITY');$complete=$received===$q;
        $expectedChild=$ship['child'];$expectedChild['status']=m4fn_native_value($snapshot['database']['tables']['eb_store_order']['metadata']['columns']['status'],$complete?2:1);
        m4fn_expect($child===$expectedChild&&isset($nativeItems[$childId])&&count($nativeItems[$childId])===1
            &&$nativeItems[$childId][0]===$ship['item'],'TASK6_THREE_EXPECTED_COMPLETE_NATIVE_CHILD_AND_UNCHANGED_ITEM');
        $allocation=$ship['plan']['allocations'][0];$item=$nativeItems[$childId][0];$originalId=$allocation['source_item_id'];$display=null;
        foreach($source['display']as$row)if($row['id']===$originalId)$display=$row;
        m4fn_expect(is_array($display),'TASK6_THREE_EXPECTED_TRUE_ORIGINAL_DISPLAY_SOURCE_FOR_CHILD');
        $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$sku=$cart['productInfo']['attrInfo'];$product=$cart['productInfo'];
        $image=$sku['image']!==''?$sku['image']:$product['image'];
        m4fn_expect($cart['truePrice']===$source['lines'][$originalId]['unit_price']&&$cart['sum_true_price']===$allocation['goods']
            &&$cart['postage_price']===$allocation['postage']&&$cart['origin_postage_price']===$allocation['origin_postage']
            &&$image===$display['image']&&$product['store_name']===$display['product_name']&&$sku['suk']===$display['sku_name'],
            'TASK6_THREE_EXPECTED_CHILD_REAL_SAVED_PRICE_AND_ORIGINAL_CENT_ALLOCATION');
        $items=[['id'=>m5pcp_int($item['id'],1),'product_id'=>$allocation['product_id'],'product_name'=>$display['product_name'],
            'sku_name'=>$display['sku_name'],'image'=>$image,'quantity'=>$q,'unit_price'=>$source['lines'][$originalId]['unit_price'],
            'line_price'=>$allocation['goods'],'postage_price'=>$allocation['postage']]];
        $dto=['schema'=>1,'scope'=>'marketplace_fulfillment_child_v1','id'=>$childId,'order_id'=>$child['order_id'],
            'root_order_id'=>$rootId,'root_order_no'=>$root['order_id'],'marketplace_trade_version'=>1,
            'merchant_name_snapshot'=>$root['merchant_name_snapshot'],'shipping_type'=>m5pcp_int($child['shipping_type'],1,1),
            'carrier_code'=>$ship['request']['delivery']['carrier_code'],'carrier_name'=>$ship['request']['delivery']['carrier_name'],
            'tracking_no'=>$ship['request']['delivery']['tracking_no'],'shipped_at'=>m5pcp_int($ship['operation']['shipped_at'],1),
            'receipt_due_at'=>m5pcp_int($ship['operation']['receipt_due_at'],0,0),'last_received_at'=>$childLast,'received_at'=>$complete?$childLast:null,
            'shipping_state'=>'shipped','receipt_state'=>$complete?'received':($received===0?'pending':'partial'),'closed'=>$complete,
            'original'=>$q,'shipped'=>$q,'received'=>$received,'cancelled_unshipped'=>0,'returned_accepted'=>0,'to_ship'=>0,'to_receive'=>$q-$received,
            'items'=>$items,'payment_owner_order_id'=>$rootId,'paid'=>1,'pay_type'=>$root['pay_type'],'pay_time'=>m5pcp_int($root['pay_time'],1),
            'pay_type_name'=>'测试支付'];
        if($detail){$forms=[];foreach($source['forms']as$form)if($form['product_id']===$allocation['product_id'])$forms[]=$form;
            m4fn_expect(json_decode($child['custom_form'],true,32,JSON_THROW_ON_ERROR)===$forms,
                'TASK6_THREE_EXPECTED_DETAIL_TRUE_CHILD_PRODUCT_FORMS_ONLY');
            $dto['recipient']=['real_name'=>$child['real_name'],'user_phone'=>$child['user_phone'],'user_address'=>$child['user_address']];
            $dto['mark']=$child['mark'];$dto['custom_form']=$forms;
        }
        $dtos[]=$dto;
    }
    return ['root'=>$root,'summary'=>$summary,'history_times'=>['last_shipped_at'=>$lastShip,'last_received_at'=>$lastReceived,
        'received_completed_at'=>$summary['closed']?$lastReceived:null],'children'=>$dtos];
}
function m4tr_children_page_expected(array $source,array $snapshot,array $history,int $page,int $limit):array
{
    m4fn_expect($page>=1&&$limit>=1&&$limit<=100&&$page-1<=intdiv(PHP_INT_MAX,$limit),
        'TASK6_THREE_NORMAL_CHILD_PAGE_EXPECTATION_BOUNDS');$native=m4tr_children_expected($source,$snapshot,$history,false);
    return ['page'=>$page,'limit'=>$limit,'count'=>count($native['children']),'list'=>array_slice($native['children'],($page-1)*$limit,$limit)];
}

/** The dedicated normal dataset's independent full pid0 selection; no candidate filter/parser/DAO is called. */
function m4tr_native_commercial_roots(PDO $db,array $owner):array
{
    $rows=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE pid = 0 AND is_system_del = 0 AND is_del = 0 AND uid = ? ORDER BY add_time DESC,id DESC',[$owner['uid']]);
    $all=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE pid = 0 ORDER BY add_time DESC,id DESC');
    m4fn_expect(count($rows)===101&&$all===$rows,'TASK6_THREE_ACTUAL_DEDICATED_ONE_HUNDRED_ONE_COMMERCIAL_ROOTS');
    $legacy=0;$v1=0;$paid=0;$seen=[];$previous=null;
    foreach($rows as$row){$id=m5pcp_int($row['id'],1);$time=m5pcp_int($row['add_time'],1);$version=m5pcp_int($row['marketplace_trade_version'],0,1);
        m4fn_expect(!isset($seen[$id])&&m5pcp_int($row['uid'],1)===$owner['uid']&&m5pcp_int($row['pid'],0,0)===0
            &&($previous===null||$time<$previous[0]||($time===$previous[0]&&$id<$previous[1])),
            'TASK6_THREE_NATIVE_FULL_SORT_WITH_TIES_NO_SYNTHETIC_ORDER');$seen[$id]=true;$previous=[$time,$id];
        foreach(['combination_id','pink_id','seckill_id','bargain_id','advance_id','virtual_type','is_gift']as$key)
            m4fn_expect(m5pcp_int($row[$key],0,0)===0,'TASK6_THREE_ONLY_TRUE_NORMAL_ORDINARY_CREATION_SCOPE');
        if($version===0){++$legacy;m4fn_expect(m5pcp_int($row['paid'],0,0)===0,'TASK6_THREE_TRUE_LEGACY_REMAINS_UNPAID');}
        else{++$v1;if(m5pcp_int($row['paid'],0,1)===1){++$paid;
            m4fn_expect($id===m5pcp_int($owner['saved']['order']['id'],1),'TASK6_THREE_ONLY_ORIGINAL_REAL_PAID_ROOT');}}
    }
    m4fn_expect($legacy===1&&$v1===100&&$paid===1,'TASK6_THREE_NATIVE_POPULATION_ONE_LEGACY_NINETY_NINE_UNPAID_ONE_PAID');return $rows;
}
/** Complete plan/value comparison; expected DTOs must already come from independent native/old formatter material. */
function m4tr_owned_page_expected(array $actual,string $kind,array $expectedRoots,int $wholeCount,array $expectedDtos):array
{
    m4fn_expect(in_array($kind,['customer_page','platform_page','mobile_page'],true)&&array_is_list($expectedRoots)
        &&in_array($wholeCount,[1,101],true),'TASK6_THREE_FIXED_NORMAL_OWNED_PAGE_EXPECTED_SOURCE');m4c_entry_keys($actual,['kind','slots','count','detail']);
    m4fn_expect($actual['kind']===$kind&&$actual['count']===$wholeCount&&$actual['detail']===null
        &&is_array($actual['slots'])&&array_is_list($actual['slots'])&&count($actual['slots'])===count($expectedRoots),
        'TASK6_THREE_OWNED_PAGE_EXACT_SHAPE_COUNT_AND_PAGE_LENGTH');$legacy=0;$v1=0;$ids=[];
    foreach($expectedRoots as$index=>$root){$id=m5pcp_int($root['id'],1);$slot=$actual['slots'][$index];m4c_entry_keys($slot,['id','legacy','header','dto']);
        m4fn_expect($slot['id']===$id&&!isset($ids[$id]),'TASK6_THREE_OWNED_NATIVE_SLOT_ORDER_NO_LOSS_OR_DUPLICATION');$ids[$id]=true;
        if(m5pcp_int($root['marketplace_trade_version'],0,1)===0){++$legacy;
            m4fn_expect($slot['legacy']===true&&$slot['header']===$root&&$slot['dto']===null,
                'TASK6_THREE_OWNED_REAL_LEGACY_HEADER_EXACT_ORIGINAL_SLOT');
        }else{++$v1;m4fn_expect($slot['legacy']===false&&$slot['header']===null&&array_key_exists($id,$expectedDtos),
                'TASK6_THREE_OWNED_V1_SAFE_DTO_EXPECTED_MUST_EXIST');m5pcp_same($slot['dto'],$expectedDtos[$id]);}
    }
    return ['kind'=>$kind,'native_count'=>$wholeCount,'returned_count'=>count($expectedRoots),'legacy_slots'=>$legacy,'v1_slots'=>$v1,
        'selected_native_ids_sha256'=>hash('sha256',serialize(array_keys($ids))),'complete_expected_values_and_types_verified'=>true];
}
function m4tr_owned_root_expected(array $actual,string $kind,array $selected,array $expectedDto,?array $expectedUserInfo=null):array
{
    m4fn_expect(in_array($kind,['customer_root','platform_root','mobile_root'],true),
        'TASK6_THREE_FIXED_NORMAL_OWNED_ROOT_EXPECTED_KIND');m4c_entry_keys($actual,['kind','slots','count','detail']);
    m4fn_expect($actual['kind']===$kind&&$actual['count']===1&&is_array($actual['slots'])&&array_is_list($actual['slots'])
        &&count($actual['slots'])===1,'TASK6_THREE_OWNED_ROOT_EXACT_FOUND_PLAN');
    m5pcp_same($actual['detail'],['state'=>'FOUND','user_info'=>$expectedUserInfo]);$slot=$actual['slots'][0];m4c_entry_keys($slot,['id','legacy','header','dto']);
    $legacy=m5pcp_int($selected['marketplace_trade_version'],0,1)===0;$id=m5pcp_int($selected['id'],1);
    m4fn_expect($slot['id']===$id&&$slot['legacy']===$legacy,'TASK6_THREE_OWNED_EXACT_REAL_SELECTED_LOCATOR_AND_VERSION');
    if($legacy)m4fn_expect($slot['header']===$selected&&$slot['dto']===null,'TASK6_THREE_OWNED_LEGACY_ROOT_HEADER_PRESERVED');
    else{m4fn_expect($slot['header']===null,'TASK6_THREE_OWNED_V1_RAW_HEADER_NEVER_PUBLIC');m5pcp_same($slot['dto'],$expectedDto);}
    return ['kind'=>$kind,'locator_class'=>m5pcp_int($selected['pid'],0)>0?'real_child':($legacy?'real_legacy':'real_root'),
        'selected_native_id_sha256'=>hash('sha256',(string)$id),'complete_expected_values_and_types_verified'=>true];
}

/** Frozen original pre-six-read whitefilter leaves only; independent native/old formatter supplies all input material. */
final class M4ThreeOriginalWhitefilter
{
    private const HEADER = ['id','order_id','store_order_id','store_order_sn','mer_id','pid','type','status','paid','pay_type',
        'shipping_type','delivery_type','delivery_id','delivery_name','delivery_code','real_name','user_phone','user_address',
        'mark','remark','gift_mark','trade_no','add_time','pay_time','_pay_time','_add_time','add_time_y','add_time_h',
        'is_cancel','is_del','is_gift','virtual_type','fictitious_content','verify_code','_verify_code','code','status_pic',
        'is_apply_refund','is_refund_available','order_shipping_open','refund_status','refund_type','refund_explain','refund_reason',
        'refuse_reason','refund_express','refund_express_name','refund_num','total_num','refund_total_num',
        'seckill_id','combination_id','bargain_id','pink_id','discount_id','advance_id','use_integral',
        'routine_contact_type','invoice_func','special_invoice','pay_weixin_open','ali_pay_status','pay_allin_open',
        'friend_pay_status','yue_pay_status','offlinePayStatus','nickname','avatar',
        'merchant_name_snapshot','merchant_after_sale_contact_snapshot'];
    private const MONEY = ['total_price','pay_price','pay_postage','total_postage','refund_price','refund_pay_price',
        'coupon_price','deduction_price','integral_price','gift_price','vip_true_price','levelPrice','memberPrice','postage_price','member_price'];
    private const CART = ['type','product_id','product_attr_unique','cart_num','unique','surplus_num','refund_num','surplus_refund_num',
        'is_reply','is_valid','price_type'];
    private const CART_MONEY = ['truePrice','vip_truePrice','vip_sum_truePrice','sum_price','sum_true_price','postage_price','origin_postage_price'];
    private const PRODUCT = ['id','mer_id','store_name','image','unit_name','is_vip','is_virtual','virtual_type','spec_type','store_mention'];
    private const SKU = ['id','product_id','type','unique','suk','image'];
    private const PRODUCT_MONEY = ['price','ot_price','vip_price'];
    private const STATUS = ['_type','_title','_msg','_class','_payType','refund_name','refund_phone','refund_address'];
    private const INVOICE = ['id','invoice_id','category','is_invoice','is_pay','header_type','type','name','duty_number',
        'drawer_phone','email','tell','address','bank','card_number','remark','invoice_number','invoice_time','invoice_image','add_time'];

    public static function customerOrder(array $saved):array
    {
        if (\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($saved)) return $saved;
        $out = self::scalars($saved,self::HEADER);
        foreach (self::MONEY as $key) if (array_key_exists($key,$saved)) $out[$key]=self::money($saved[$key]);
        $out['marketplace_trade_version']=1;
        foreach (['cartInfo','refund_cartInfo','cart_info'] as $key) if (array_key_exists($key,$saved)) $out[$key]=self::carts($saved[$key]);
        $out['custom_form']=self::customForms($saved['custom_form'] ?? []);
        if (isset($saved['_status'])) $out['_status']=self::scalars(self::row($saved['_status']),self::STATUS);
        if (array_key_exists('invoice',$saved)) {
            $invoice=$saved['invoice'];
            if ($invoice===null || $invoice===false || $invoice===[]) $out['invoice']=$invoice;
            else {
                $out['invoice']=self::scalars(self::row($invoice),self::INVOICE);
                foreach (['pay_price','invoice_price'] as $key) if (array_key_exists($key,$invoice)) $out['invoice'][$key]=self::money($invoice[$key]);
            }
        }
        if (isset($saved['help_info'])) $out['help_info']=self::scalars(self::row($saved['help_info']),['help_status','pay_nickname','pay_avatar']);
        if (array_key_exists('system_store',$saved)) $out['system_store']=$saved['system_store']===false ? false :
            self::scalars(self::row($saved['system_store']),['id','name','phone','address','detailed_address','image','latitude','longitude','day_time']);
        if (array_key_exists('refund_img',$saved)) $out['refund_img']=self::images($saved['refund_img']==='' ? [] : $saved['refund_img']);
        if (array_key_exists('express_list',$saved)) {
            $out['express_list']=[];foreach(self::list($saved['express_list']) as $company)
                $out['express_list'][]=self::scalars(self::row($company),['id','name','code','logo']);
        }
        if (array_key_exists('split',$saved)) {
            $out['split']=[];
            foreach(self::list($saved['split']) as $child){
                $child=self::row($child);
                if(\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($child))self::unavailable();
                $out['split'][]=self::customerOrder($child);
            }
        }
        return $out;
    }

    public static function platformOrder(array $saved):array
    {
        if(\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($saved))return $saved;
        $out=self::customerOrder($saved);
        if(array_key_exists('is_all_refund',$saved))$out+=self::scalars($saved,['is_all_refund']);
        if(array_key_exists('refunded_price',$saved))$out['refunded_price']=self::money($saved['refunded_price']);
        if(array_key_exists('refund',$saved)){
            $out['refund']=[];
            foreach(self::list($saved['refund']) as $refund){
                $refund=self::row($refund);
                $summary=self::scalars($refund,['id','order_id','store_order_id','refund_type','refund_num','is_cancel','add_time']);
                if(array_key_exists('refund_price',$refund))$summary['refund_price']=self::money($refund['refund_price']);
                $out['refund'][]=$summary;
            }
        }
        // No _info, raw refund cart JSON, costs or unsupported brokerage fields leave this DTO.
        return $out;
    }

    public static function orderListRow(array $saved,string $scope):array
    {
        if(!array_key_exists('marketplace_trade_version',$saved))self::unavailable();
        if(\app\services\marketplace\order\MarketplaceTradePolicy::savedOrderIsLegacy($saved))return $saved;
        return self::listRow($saved,$scope,false);
    }

    private static function listRow(array $saved,string $scope,bool $refund):array
    {
        if(!in_array($scope,['customer','platform','mobile'],true))self::unavailable();
        $out=self::scalars($saved,['id','order_id','store_order_id','store_order_order_id','store_order_status','mer_id','pid',
            'type','status','paid','pay_type','shipping_type','delivery_type','is_cancel','is_del','is_gift','virtual_type',
            'refund_status','refund_type','refund_num','total_num','is_all_refund','add_time','_add_time','pay_time','_pay_time',
            '_refund_time','pay_type_name','pink_name','color','mark','remark','refuse_reason','refund_express','refund_reason',
            'merchant_name_snapshot','merchant_after_sale_contact_snapshot']);
        $out['marketplace_trade_version']=1;
        foreach(['total_price','pay_price','total_postage','pay_postage','refund_price','refunded_price'] as $key)
            if(array_key_exists($key,$saved))$out[$key]=self::money($saved[$key]);
        if($scope==='platform')$out+=self::scalars($saved,['uid','nickname']);
        if(isset($saved['_status'])){
            if($refund || $scope==='customer')$out['_status']=self::scalars(self::row($saved['_status']),self::STATUS);
            else {
                if(!is_int($saved['_status']))self::unavailable();
                $out['_status']=$saved['_status'];
            }
        }
        if(isset($saved['status_name'])){
            $status=self::row($saved['status_name']);$out['status_name']=self::scalars($status,['status_name']);
            if(array_key_exists('pics',$status))$out['status_name']['pics']=self::images($status['pics']);
        }
        foreach(['cartInfo','cart_info','refund_cartInfo'] as $key)
            if(array_key_exists($key,$saved))$out[$key]=self::carts($saved[$key]);
        if(array_key_exists('_info',$saved)){
            $out['_info']=[];
            foreach(self::row($saved['_info']) as $wrapper){
                $wrapper=self::row($wrapper);
                $out['_info'][]=['cart_info'=>self::carts([self::row($wrapper['cart_info'] ?? null)])[0]];
            }
        }
        if(array_key_exists('cart_id',$saved)){
            $out['cart_id']=[];foreach(self::list($saved['cart_id']) as $ref)$out['cart_id'][]=self::text($ref);
        }
        if(array_key_exists('refund',$saved)){
            $out['refund']=[];
            foreach(self::list($saved['refund']) as $row){
                $row=self::row($row);$summary=self::scalars($row,['id','order_id','store_order_id','refund_type','refund_num','is_cancel','add_time']);
                if(array_key_exists('refund_price',$row))$summary['refund_price']=self::money($row['refund_price']);
                $out['refund'][]=$summary;
            }
        }
        if(array_key_exists('split',$saved)){
            $out['split']=[];foreach(self::list($saved['split']) as $row)$out['split'][]=self::scalars(self::row($row),['id','pid']);
        }
        if(array_key_exists('refund_img',$saved))$out['refund_img']=self::images($saved['refund_img']===''?[]:$saved['refund_img']);
        return $out;
    }

    public static function customForms(mixed $raw,?array $productIds=null):array
    {
        if(is_string($raw)){
            if(strlen($raw)>65535)self::unavailable();
            try{$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $error){self::unavailable();}
        }
        $groups=self::list($raw);if(count($groups)>500)self::unavailable();$out=[];$seen=[];
        foreach($groups as $group){
            $group=self::row($group);$id=self::productId($group['product_id'] ?? null);
            $hash=$group['definition_sha256'] ?? null;
            if(isset($seen[$id]) || !is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D',$hash)!==1)self::unavailable();
            $seen[$id]=true;$fields=self::list($group['fields'] ?? null);
            if(!$fields || count($fields)>10)self::unavailable();$values=[];
            foreach($fields as $index=>$field){
                $field=self::row($field);$label=$field['label'] ?? null;
                if(($field['field_index'] ?? null)!==$index || !is_bool($field['status'] ?? null)
                    || !in_array($label,['text','number','email','data','time','id','phone','img'],true))self::unavailable();
                $values[]=['field_index'=>$index,'title'=>self::text($field['title'] ?? null),'label'=>$label,'status'=>$field['status'],
                    'value'=>$label==='img' ? self::images($field['value'] ?? null,8) : self::text($field['value'] ?? null)];
            }
            $projected=['product_id'=>$id,'product_name'=>self::text($group['product_name'] ?? null),'definition_sha256'=>$hash,'fields'=>$values];
            if($productIds===null || in_array($id,$productIds,true))$out[]=$projected;
        }
        return $out;
    }

    public static function carts(mixed $raw):array
    {
        $out=[];
        foreach(self::list($raw) as $cart){
            $cart=self::row($cart);$product=self::row($cart['productInfo'] ?? null);
            $id=self::productId($product['id'] ?? null);
            if(isset($cart['product_id']) && self::productId($cart['product_id'])!==$id)self::unavailable();
            $row=self::scalars($cart,self::CART);$row['id']=self::text($cart['id'] ?? null);
            if($row['id']==='' || strlen($row['id'])>50)self::unavailable();
            foreach(self::CART_MONEY as $key)if(array_key_exists($key,$cart))$row[$key]=self::money($cart[$key]);
            $row['productInfo']=self::scalars($product,self::PRODUCT);
            foreach(self::PRODUCT_MONEY as $key)if(array_key_exists($key,$product))$row['productInfo'][$key]=self::money($product[$key]);
            if(isset($product['attrInfo'])){
                $sku=self::row($product['attrInfo']);
                if(self::productId($sku['product_id'] ?? null)!==$id)self::unavailable();
                $row['productInfo']['attrInfo']=self::scalars($sku,self::SKU);
                foreach(self::PRODUCT_MONEY as $key)if(array_key_exists($key,$sku))$row['productInfo']['attrInfo'][$key]=self::money($sku[$key]);
            }
            $out[]=$row;
        }
        return $out;
    }

    private static function scalars(array $raw,array $fields):array
    {
        $out=[];foreach($fields as $key)if(array_key_exists($key,$raw)){
            $value=$raw[$key];
            if(is_string($value))self::text($value);
            elseif(!is_int($value) && !is_bool($value) && $value!==null)self::unavailable();
            $out[$key]=$value;
        }
        return $out;
    }
    private static function money(mixed $value):string
    {
        $money=\app\services\marketplace\order\MarketplaceDecimal::value($value);
        if(bccomp($money,'9999999999.99',2)>0)self::unavailable();return $money;
    }
    private static function productId(mixed $id):int
    {
        return \app\services\marketplace\order\MarketplaceOrderInput::unsigned($id,'保存商品',8388607) ?: self::unavailable();
    }
    private static function text(mixed $value):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||str_contains($value,"\0"))self::unavailable();return $value;
    }
    private static function images(mixed $value,int $maximum=500):array
    {
        $value=self::list($value);if(count($value)>$maximum)self::unavailable();foreach($value as $image)self::text($image);return $value;
    }
    private static function row(mixed $value):array { if(!is_array($value))self::unavailable();return $value; }
    private static function list(mixed $value):array { if(!is_array($value)||!array_is_list($value))self::unavailable();return $value; }
    private static function unavailable():never { throw new \crmeb\exceptions\AuthUnavailableException(); }
}

/** Independent six-view expectations: original actual Model/formatter plus separately native saved facts. */
function m4tr_v1_expected(\think\App $app,PDO $db,array $owner,array $root,string $view,array $paidSource,array $snapshot,array $history,int $childrenPage=1,int $childrenLimit=20):array
{
    m4fn_expect(in_array($view,['customer_list','platform_list','mobile_list','customer_detail','platform_detail','mobile_detail'],true)
        &&m5pcp_int($root['marketplace_trade_version'],1,1)===1&&m5pcp_int($root['pid'],0,0)===0
        &&m5pcp_int($root['uid'],1)===$owner['uid'],'TASK6_THREE_EXPECTED_FIXED_VIEW_REAL_SAVED_OWNER');
    $id=m5pcp_int($root['id'],1);$detail=str_ends_with($view,'_detail');$customerDetail=in_array($view,['customer_detail','mobile_detail'],true);
    $relations=match($view){'customer_list'=>['user','spread','refund'],'platform_list'=>['user','spread','refund','pink','invoice','division'],
        'mobile_list'=>['user','spread','refund','pink'],'platform_detail'=>['refund','invoice'],default=>['invoice','user']};
    $native=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$id]);m4fn_expect($native===[$root],
        'TASK6_THREE_EXPECTED_INDEPENDENT_FULL_NATIVE_HEADER');
    foreach(['is_cancel','refund_status','refund_type','is_gift','virtual_type','combination_id','pink_id','seckill_id','bargain_id','advance_id',
        'store_id','delivery_uid','is_channel']as$key)m4fn_expect(m5pcp_int($root[$key],0,0)===0,'TASK6_THREE_EXPECTED_ACTUAL_NORMAL_CREATOR_DOMAIN');
    m4fn_expect($root['verify_code']===''&&m5pcp_int($root['shipping_type'],1,1)===1&&m5pcp_int($root['pay_uid'],1)===$owner['uid'],
        'TASK6_THREE_EXPECTED_NO_EXTERNAL_QR_MANAGED_PAYER_SUBSTITUTION');
    $items=m5pcp_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid = ? ORDER BY id',[$id]);
    $allItems=[];foreach($snapshot['rows']['eb_store_order_cart_info']as$item)if(m5pcp_int($item['oid'],1)===$id)$allItems[]=$item;
    m4fn_expect($items!==[]&&$items===$allItems,'TASK6_THREE_EXPECTED_FULL_ORIGINAL_ITEM_SET_NOT_PAGE_OR_REMAINING_QUANTITY');
    $refunds=m5pcp_rows($db,'SELECT * FROM eb_store_order_refund WHERE store_order_id = ? AND refund_type <> 3 AND is_cancel = 0',[$id]);
    $invoice=m5pcp_rows($db,'SELECT * FROM eb_store_order_invoice WHERE order_id = ?',[$id]);
    $buyer=m5pcp_rows($db,'SELECT * FROM eb_user WHERE uid = ?',[$owner['uid']]);
    $originalPaid=$id===$paidSource['identity']['id'];
    m4fn_expect($refunds===[]&&$invoice===($originalPaid?$paidSource['saved']['invoice']:[])&&count($buyer)===1,
        'TASK6_THREE_EXPECTED_ACTUAL_NORMAL_REFUND_ABSENCE_ORIGINAL_INVOICE_AND_REAL_BUYER');
    $model=(new \app\model\order\StoreOrder())->with($relations)->where('id',$id)->find();
    m4fn_expect($model instanceof \app\model\order\StoreOrder,'TASK6_THREE_EXPECTED_ORIGINAL_MODEL_NOT_SELECTED_MATERIALIZER');
    $data=$model->getData();foreach($root as$key=>$value)m4fn_expect(array_key_exists($key,$data)&&$data[$key]===$value,
        'TASK6_THREE_EXPECTED_MODEL_EVERY_NATIVE_COLUMN_AND_TYPE');
    $row=$model->toArray();unset($row['split']);$reference=new \app\services\order\M4ThreeLegacyReference($owner['uid']);
    $summary=null;$times=['last_shipped_at'=>null,'last_received_at'=>null,'received_completed_at'=>null];$children=null;
    if(m5pcp_int($root['paid'],0,1)===1){
        m4fn_expect($id===$paidSource['identity']['id'],'TASK6_THREE_EXPECTED_ONLY_ORIGINAL_GENUINE_PAID_ROOT');
        $family=m4tr_children_expected($paidSource,$snapshot,$history,false);m4fn_expect($family['root']===$root,
            'TASK6_THREE_EXPECTED_NATIVE_FAMILY_ROOT_IS_THIS_ACTUAL_MODEL');$summary=$family['summary'];$times=$family['history_times'];
        if($detail)$children=m4tr_children_page_expected($paidSource,$snapshot,$history,$childrenPage,$childrenLimit);
    }else{
        $ledgers=m5pcp_rows($db,'SELECT * FROM eb_marketplace_order_idempotency WHERE order_id = ?',[$id]);
        m4fn_expect(count($ledgers)===1&&m5pcp_int($ledgers[0]['cancel_at'],0,0)===0,
            'TASK6_THREE_EXPECTED_ACTUAL_SAVED_ZERO_LEDGER_INDEPENDENT_OF_CURRENT_ONE');
    }
    if(in_array($view,['platform_list','mobile_list'],true)){
        $list=$reference->tidyOrderList([$row]);m4fn_expect(count($list)===1,'TASK6_THREE_EXPECTED_ORIGINAL_LIST_FORMATTER_ONE_ROW');$row=$list[0];
        $row['is_all_refund']=false;
        if($summary!==null&&$summary['shipping_state']==='partial'){$row['_status']=8;$row['status_name']=['status_name'=>'部分发货','pics'=>[]];}
        elseif(m5pcp_int($root['status'],0)===2){$row['_status']=5;$row['status_name']=['status_name'=>'已收货','pics'=>[]];}
        // Complete original item JSON remains the display source; no current directory/SKU price lookup is an oracle.
        $expected=M4ThreeOriginalWhitefilter::orderListRow($row,$view==='platform_list'?'platform':'mobile');
    }else{
        if($detail)$row['custom_form']=$model->getData('custom_form');
        if($customerDetail){
            if(sys_config('store_self_mention')==0)$row['shipping_type']=1;
            $row['add_time_y']=date('Y-m-d',$root['add_time']);$row['add_time_h']=date('H:i:s',$root['add_time']);
            $row['system_store']=false;$row['code']='';$row['mapKey']=sys_config('tengxun_map_key');
            $row['yue_pay_status']=(int)sys_config('balance_func_status')&&(int)sys_config('yue_pay_status')===1?1:2;
            $row['pay_weixin_open']=sys_config('pay_weixin_open')!='0';$row['ali_pay_status']=sys_config('ali_pay_status','0')!='0';
            $row['friend_pay_status']=(int)sys_config('friend_pay_status');
        }
        $row=$reference->tidyOrder($row,true,$detail);
        if($summary!==null&&$summary['shipping_state']==='partial')$row['_status']=array_replace($row['_status'],[
            '_type'=>1,'_title'=>'部分发货','_msg'=>date('m月d日H时i分',$times['last_shipped_at']).'服务商已部分发货','_class'=>'state-ysh']);
        elseif(m5pcp_int($root['status'],0)===1)$row['_status']=array_replace($row['_status'],[
            '_type'=>2,'_title'=>'待收货','_msg'=>date('m月d日H时i分',$times['last_shipped_at']).'服务商已发货','_class'=>'state-ysh']);
        elseif(m5pcp_int($root['status'],0)===2)$row['_status']=array_replace($row['_status'],[
            '_type'=>3,'_title'=>'已收货','_msg'=>'已收货,感谢您的支持','_class'=>'state-ypj']);
        if($customerDetail&&m5pcp_int($root['paid'],0,1)===0)$row['_status']['_msg']='订单尚未支付，请完成支付';
        if($view==='customer_list'){
            foreach($row['cartInfo']as$key=>$cart){if($row['_status']['_type']===3)$row['cartInfo'][$key]['add_time']=isset($cart['add_time'])?date('Y-m-d H:i',(int)$cart['add_time']):'时间错误';
                $row['cartInfo'][$key]['productInfo']['price']=$cart['truePrice'];}
            $row['is_all_refund']=false;$expected=M4ThreeOriginalWhitefilter::orderListRow($row,'customer');
        }else{
            $vip=$member=$level='0.00';foreach($row['cartInfo']as$cart){$saving=bcmul(m4fn_money($cart['vip_truePrice']),(string)m5pcp_int($cart['cart_num'],1),2);
                m4fn_expect($cart['vip_sum_truePrice']===$saving,'TASK6_THREE_EXPECTED_NATIVE_SAVINGS_BY_SAVED_QUANTITY');$vip=bcadd($vip,$saving,2);
                if($cart['price_type']==='member')$member=bcadd($member,$saving,2);if($cart['price_type']==='level')$level=bcadd($level,$saving,2);}
            $row['vip_true_price']=$vip;$row['levelPrice']=$level;$row['memberPrice']=$member;
            if($customerDetail){
                $economize=m5pcp_rows($db,'SELECT * FROM eb_store_order_economize WHERE order_id = ?',[$root['order_id']]);
                m4fn_expect(count($economize)<=1,'TASK6_THREE_EXPECTED_REAL_ECONOMIZE_SINGLE_ROW');
                $row['postage_price']=$economize===[]?0:$economize[0]['postage_price'];$row['member_price']=$economize===[]?0:$economize[0]['member_price'];
                $row['routine_contact_type']=sys_config('routine_contact_type',0);
                $invoiceStatus=$app->make(\app\services\user\UserInvoiceServices::class)->invoiceFuncStatus();
                $row['invoice_func']=$invoiceStatus['invoice_func'];$row['special_invoice']=$invoiceStatus['special_invoice'];
                $row['refund_cartInfo']=$row['cartInfo'];$row['refund_total_num']=$row['total_num'];$row['refund_pay_price']=$row['pay_price'];$row['is_apply_refund']=true;
                $row['help_info']=['help_status'=>0,'pay_nickname'=>'','pay_avatar'=>''];$row['order_shipping_open']=false;
                $days=(int)sys_config('refund_time_available');$row['is_refund_available']=$days===0||$times['received_completed_at']===null
                    ||$times['received_completed_at']+$days*86400>=time();
            }else{$row['is_all_refund']=false;}
            $expected=$view==='platform_detail'?M4ThreeOriginalWhitefilter::platformOrder($row):M4ThreeOriginalWhitefilter::customerOrder($row);
        }
    }
    $expected['fulfillment_summary']=$summary;$expected['allowed_actions']=['ship'=>false,'receive'=>false];
    if($detail){
        $logs=m5pcp_rows($db,'SELECT oid,change_type,change_message,change_time FROM eb_store_order_status WHERE oid = ?',[$id]);$column=[];
        foreach($logs as$log){m4fn_expect(m5pcp_int($log['oid'],1)===$id,'TASK6_THREE_EXPECTED_LOG_REAL_ROOT');$column[$log['change_type']]=m5pcp_int($log['change_time'],0);}
        $expected['order_log']=['create'=>isset($column['cache_key_create_order'])?date('Y-m-d',$column['cache_key_create_order']):'',
            'pay'=>isset($column['pay_success'])?date('Y-m-d',$column['pay_success']):'',
            'delivery'=>$times['last_shipped_at']===null?'':date('Y-m-d',$times['last_shipped_at']),
            'take'=>$times['received_completed_at']===null?'':date('Y-m-d',$times['received_completed_at']),
            'complete'=>isset($column['check_order_over'])?date('Y-m-d',$column['check_order_over']):''];$expected['fulfillment_children']=$children;
    }
    // Independently bind every exposed original item to the native full saved JSON, including member price != SKU base price.
    $shown=$expected['cartInfo']??array_column($expected['_info']??[],'cart_info');
    m4fn_expect(count($shown)===count($items),'TASK6_THREE_EXPECTED_DISPLAY_COMPLETE_ORIGINAL_ITEM_COUNT');
    foreach($items as$index=>$item){$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        m4fn_expect($shown[$index]['product_id']===m5pcp_int($item['product_id'],1)
            &&$shown[$index]['cart_num']===$item['cart_num']&&$shown[$index]['truePrice']===m4fn_money($cart['truePrice'])
            &&$shown[$index]['sum_true_price']===m4fn_money($cart['sum_true_price'])
            &&$shown[$index]['postage_price']===m4fn_money($cart['postage_price'])
            &&bcmul($cart['truePrice'],(string)$item['cart_num'],2)===$cart['sum_true_price'],
            'TASK6_THREE_EXPECTED_NATIVE_PRODUCT_QUANTITY_SAVED_UNIT_LINE_POSTAGE_PRICES');
    }
    return ['dto'=>$expected,'user_info'=>$view==='platform_detail'?['real_name'=>$buyer[0]['real_name']]:null];
}

/** Genuine ordinary platform rows for INTERNAL identity provenance only; no login/session/grant is produced here. */
function m4tr_internal_platform_actor(\think\App $app,PDO $db,array $owner):array
{
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();m4fn_context($app,$connection,$default,$db);
    $suffix=bin2hex(random_bytes(7));$account='m4tr_'.$suffix;$password='M4tr-'.bin2hex(random_bytes(12)).'-Z9';
    $digest=password_hash($password,PASSWORD_BCRYPT);m4fn_expect(is_string($digest)&&password_verify($password,$digest),
        'TASK6_THREE_INTERNAL_PLATFORM_REAL_PASSWORD_HASH_NOT_TOKEN');$roleName='M4TR '.$suffix;$role=null;$admin=null;
    foreach(['eb_system_role','eb_system_admin']as$table){
        $before=m4fn_snapshot($db);$oldRows=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table).' ORDER BY id');
        if($table==='eb_system_role'){
            m4fn_expect(m5pcp_rows($db,'SELECT * FROM eb_system_role WHERE role_name = ?',[$roleName])===[],
                'TASK6_THREE_INTERNAL_NEW_ROLE_NAME_ABSENT');$values=['role_name'=>$roleName,'rules'=>'','level'=>1,'status'=>1];
            $statement=$db->prepare('INSERT INTO eb_system_role(role_name,rules,level,status) VALUES(?,?,1,1)');
            $params=[$roleName,''];
        }else{
            m4fn_expect(is_array($role)&&m5pcp_rows($db,'SELECT * FROM eb_system_admin WHERE account = ?',[$account])===[],
                'TASK6_THREE_INTERNAL_NEW_ADMIN_BINDS_ACTUAL_ORDINARY_ROLE');$now=time();
            $values=['account'=>$account,'pwd'=>$digest,'real_name'=>'三端内部读取','roles'=>(string)$role['id'],
                'add_time'=>$now,'level'=>1,'status'=>1,'is_del'=>0];
            $statement=$db->prepare('INSERT INTO eb_system_admin(account,pwd,real_name,roles,add_time,level,status,is_del) VALUES(?,?,?,?,?,1,1,0)');
            $params=[$account,$digest,'三端内部读取',(string)$role['id'],$now];
        }
        m4fn_expect($statement instanceof PDOStatement&&$statement->execute($params)&&$statement->rowCount()===1,
            'TASK6_THREE_INTERNAL_PLATFORM_ONE_NATURAL_FIXTURE_INSERT');$id=m5pcp_u64($db->lastInsertId());
        m4fn_expect($id===m5pcp_u64($before['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']),
            'TASK6_THREE_INTERNAL_PLATFORM_ACTUAL_NATURAL_PK');
        $found=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table).' WHERE id = ?',[$id]);m4fn_expect(count($found)===1,
            'TASK6_THREE_INTERNAL_PLATFORM_FULL_NATIVE_ROW_READBACK');
        $row=m4trp_expected_row($before['database']['tables'][$table]['metadata'],$found[0],['id'=>$id]+$values);$expectedRows=$oldRows;$expectedRows[]=$row;
        $after=m4fn_snapshot($db);$expected=$before['database'];$new=$after['database']['tables'][$table];
        m5pcp_attempt_metadata_advance($before['database']['tables'][$table],$new,$id);
        m4fn_expect(m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table).' ORDER BY id')===$expectedRows
            &&$new['projection']===m4fn_projection($expectedRows),'TASK6_THREE_INTERNAL_PLATFORM_ALL_DEFAULTS_ROWS_COUNTERS_NATIVE');
        $expected['tables'][$table]=$new;
        m4fn_expect($after['database']===$expected&&$after['rows']===$before['rows']&&$after['redis']['keys']===$before['redis']['keys'],
            'TASK6_THREE_INTERNAL_PLATFORM_ALL_OTHER_DATABASE_ROWS_METADATA_AND_REDIS_ZERO');
        if($table==='eb_system_role')$role=$row;else $admin=$row;
    }
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);unset($password,$digest);
    m4fn_expect(is_array($admin)&&is_array($role)&&m5pcp_int($admin['level'],1,1)===1&&$role['rules']==='',
        'TASK6_THREE_INTERNAL_PLATFORM_ORDINARY_NOT_AUTHENTICATED_HTTP');
    return ['admin'=>$admin,'role'=>$role,'admin_id'=>m5pcp_int($admin['id'],1),'HTTP_AUTH'=>'NOT RUN','role_API_registration'=>'NOT CLAIMED'];
}

/** One actual public entry and one complete native before/after proof; never an HTTP authentication substitute. */
function m4tr_read_call(\think\App $app,PDO $db,array $owner,array $platform,array $case):array
{
    m4c_entry_keys($case,['view','wrapper','query','locator','page','limit','selected','dtos','count','user_info','wire']);
    $view=$case['view'];$wrapper=$case['wrapper'];$list=str_ends_with($view,'_list');
    m4fn_expect(in_array($view,['customer_list','platform_list','mobile_list','customer_detail','platform_detail','mobile_detail'],true)
        &&is_bool($wrapper)&&is_array($case['selected'])&&array_is_list($case['selected']),
        'TASK6_THREE_FIXED_REAL_PUBLIC_CALL_DESCRIPTOR');
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();m4fn_context($app,$connection,$default,$db);
    $before=m4fn_snapshot($db);$runtime=m4tr_parent_runtime_files($app);$start=time();
    $service=$app->make(\app\services\marketplace\order\MarketplaceOrderFulfillmentServices::class);
    $orders=$app->make(\app\services\order\StoreOrderServices::class);$uid=$owner['uid'];$admin=$platform['admin_id'];
    $economize=$app->make(\app\services\order\StoreOrderEconomizeServices::class);
    if(!$wrapper)$actual=match($view){
        'customer_list'=>$service->readCustomerPage($uid,$case['query'],$case['page'],$case['limit']),
        'platform_list'=>$service->readPlatformPage($admin,$case['query'],$case['page'],$case['limit']),
        'mobile_list'=>$service->readMobilePage($uid,$case['query'],$case['page'],$case['limit']),
        'customer_detail'=>$service->readCustomerRoot($uid,$case['locator'],$case['page'],$case['limit']),
        'platform_detail'=>$service->readPlatformRoot($admin,$case['locator'],$case['page'],$case['limit']),
        'mobile_detail'=>$service->readMobileRoot($uid,$case['locator'],$case['page'],$case['limit'])};
    else $actual=match($view){
        'customer_list'=>$orders->readCustomerOrderPage($uid,$case['query']),
        'platform_list'=>$orders->readPlatformOrderPage($admin,$case['query']),
        'mobile_list'=>$app->make(\app\services\order\StoreOrderWapServices::class)->getWapAdminOrderList($case['query'],'mobile',$uid),
        'customer_detail'=>$orders->readCustomerOrderDetail($economize,$case['locator'],$uid,$case['page'],$case['limit']),
        'platform_detail'=>$orders->readPlatformOrderDetail($admin,$case['locator'],$case['page'],$case['limit']),
        'mobile_detail'=>$orders->readMobileOrderDetail($economize,$case['locator'],$uid,$case['page'],$case['limit'])};
    $end=time();m4fn_context($app,$connection,$default,$db);$after=m4fn_snapshot($db);m4fn_zero($before,$after);
    m4fn_expect(m4tr_parent_runtime_files($app)===$runtime,'TASK6_THREE_EACH_READ_RUNTIME_FILES_UNCHANGED');m4fn_inputs_unchanged($owner['inputs']);
    if($wrapper){m5pcp_same($actual,$case['wire']);$comparison=['kind'=>$view,'returned_count'=>$list?count($case['selected']):1,
        'native_count'=>$case['count'],'complete_original_wrapper_envelope_values_and_types_verified'=>true];}
    elseif($list)$comparison=m4tr_owned_page_expected($actual,str_replace('_list','_page',$view),$case['selected'],$case['count'],$case['dtos']);
    else{m4fn_expect(count($case['selected'])===1,'TASK6_THREE_ROOT_CALL_ONE_ACTUAL_NATIVE_SELECTION');$selected=$case['selected'][0];
        $comparison=m4tr_owned_root_expected($actual,str_replace('_detail','_root',$view),$selected,
            $case['dtos'][m5pcp_int($selected['id'],1)]??[],$case['user_info']);}
    $ids=[];foreach($case['selected']as$row)$ids[]=m5pcp_int($row['id'],1);
    return ['view'=>$view,'surface'=>$wrapper?'REAL_BUSINESS_WRAPPER':'OWNED_PUBLIC_SERVICE','authentication'=>'INTERNAL_ONLY_HTTP_NOT_RUN',
        'page'=>$case['page'],'limit'=>$case['limit'],'expected_native_count'=>$case['count'],'returned_count'=>count($ids),
        'selected_native_ids_sha256'=>hash('sha256',serialize($ids)),'comparison'=>$comparison,'actual_interval'=>[$start,$end],
        'database_before_sha256'=>hash('sha256',serialize($before['database'])),'database_after_sha256'=>hash('sha256',serialize($after['database'])),
        'redis_before_sha256'=>hash('sha256',serialize($before['redis']['keys'])),'redis_after_sha256'=>hash('sha256',serialize($after['redis']['keys'])),
        'complete_database_rows_metadata_counters_and_redis_values_absolute_expiries_zero'=>true,'same_default_connection_pdo_depth_zero'=>true,
        'runtime_files_and_original_paid_inputs_unchanged'=>true];
}

function m4tr_query(string $scope,string $orderNo=''):array
{
    m4fn_expect(in_array($scope,['customer','platform','mobile'],true),'TASK6_THREE_FIXED_ORIGINAL_QUERY_SCOPE');
    if($scope==='customer')return ['status'=>'','real_name'=>$orderNo,'refundTypes'=>''];
    if($scope==='platform')return ['status'=>'','real_name'=>$orderNo,'is_del'=>0,'time'=>'','type'=>'','pay_type'=>'','order'=>'',
        'field_key'=>$orderNo===''?'':'order_id'];
    return ['status'=>'','is_del'=>0,'time'=>'','type'=>'','field_key'=>$orderNo===''?'':'order_id','field_value'=>'','real_name'=>$orderNo,'pay_type'=>''];
}
function m4tr_window(array $roots,int $page,int $limit):array
{
    if($page===0||$limit===0)return $roots;$limit=min($limit,100);m4fn_expect($page>=1&&$limit>=1,
        'TASK6_THREE_ONLY_ACTUAL_NORMAL_POSITIVE_OR_WHOLE_WINDOW');return array_slice($roots,($page-1)*$limit,$limit);
}
function m4tr_legacy_expected(PDO $db,array $owner,int $id,string $view):array
{
    $reference=new \app\services\order\M4ThreeLegacyReference($owner['uid']);
    return match($view){'customer_list'=>$reference->customerList($db,$id)[0],'platform_list'=>$reference->platformList($db,$id)[0],
        'mobile_list'=>$reference->mobileList($db,$id)[0],'customer_detail'=>$reference->customerDetail($db,$id),
        'platform_detail'=>$reference->platformDetail($db,$id),'mobile_detail'=>$reference->mobileDetail($db,$id)};
}
function m4tr_wrapper_dto(array $dto,string $view):array
{
    // The real original thumb producer is proven off in preparation. Do not emulate an unrun image pipeline.
    m4fn_expect(sys_config('image_thumb_status',0)==='0','TASK6_THREE_REAL_ORIGINAL_THUMB_OFF_PASSTHROUGH_ONLY');
    if(in_array($view,['customer_detail','mobile_detail'],true)&&array_key_exists('avatar',$dto))$dto['avatar']=set_file_url($dto['avatar']);
    return $dto;
}
/** All six original fulfillment states retain their merchant pair; these additional reads each have their own zero proof. */
function m4tr_read_stage(\think\App $app,PDO $db,array $owner,array $prepared,array $paidSource,array $history,int $stage):array
{
    m4fn_expect($stage>=0&&$stage<=5,'TASK6_THREE_ONLY_ORIGINAL_SIX_NORMAL_STATES');
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();m4fn_context($app,$connection,$default,$db);
    $baseline=m4fn_snapshot($db);$runtime=m4tr_parent_runtime_files($app);$roots=m4tr_native_commercial_roots($db,$owner);
    $paidRows=array_values(array_filter($roots,static fn(array $row):bool=>m5pcp_int($row['id'],1)===$paidSource['identity']['id']));
    m4fn_expect(count($paidRows)===1,'TASK6_THREE_STAGE_ACTUAL_PAID_COMMERCIAL_ROOT');$paid=$paidRows[0];
    $legacyId=m5pcp_int($prepared['legacy']['state']['produced']['root']['id'],1);$memberId=m5pcp_int($prepared['v1']['records'][98]['root']['id'],1);
    $legacyRows=array_values(array_filter($roots,static fn(array $row):bool=>m5pcp_int($row['id'],1)===$legacyId));
    $memberRows=array_values(array_filter($roots,static fn(array $row):bool=>m5pcp_int($row['id'],1)===$memberId));
    m4fn_expect(count($legacyRows)===1&&count($memberRows)===1,'TASK6_THREE_STAGE_REAL_LEGACY_AND_MEMBER_ROOT_LOCATORS');
    // The supplied search token is the complete actual saved order number. All other searchable normal values are native checked.
    $like='%'.$paid['order_id'].'%';$fuzzy=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE pid = 0 AND is_system_del = 0 AND is_del = 0 AND uid = ? AND (order_id LIKE ? OR real_name LIKE ? OR user_phone LIKE ?) ORDER BY add_time DESC,id DESC',[$owner['uid'],$like,$like,$like]);
    m4fn_expect($fuzzy===[$paid],'TASK6_THREE_NATIVE_SAVED_HEADER_SEARCH_IS_EXACT_ONE_REAL_PAID_ROOT');
    foreach(['eb_store_bargain','eb_store_combination','eb_store_seckill']as$table)m4fn_expect(m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table))===[],
        'TASK6_THREE_NO_UNOBSERVED_ACTIVITY_TITLE_MATCHES_FOR_NORMAL_SEARCH');
    $products=m5pcp_rows($db,'SELECT * FROM eb_store_product WHERE store_name LIKE ? OR keyword LIKE ?',[$like,$like]);
    $users=m5pcp_rows($db,'SELECT * FROM eb_user WHERE nickname LIKE ? OR uid LIKE ? OR phone LIKE ?',[$like,$like,$like]);
    m4fn_expect($products===[]&&$users===[],'TASK6_THREE_NATIVE_CUSTOMER_FUZZY_REMAINING_BRANCHES_CANNOT_ADD_A_ROOT');
    $orders=$app->make(\app\services\order\StoreOrderServices::class);$wap=$app->make(\app\services\order\StoreOrderWapServices::class);
    $orderWindow=$orders->getPageValue();$mobileWindow=$wap->getPageValue();
    m4fn_expect(is_array($orderWindow)&&count($orderWindow)===3&&is_array($mobileWindow)&&count($mobileWindow)===3
        &&is_int($orderWindow[0])&&is_int($orderWindow[1])&&is_int($mobileWindow[0])&&is_int($mobileWindow[1]),
        'TASK6_THREE_REAL_UNMODIFIED_GET_PAGE_VALUE_NO_REQUEST_INJECTION');$proofs=[];$full=in_array($stage,[0,5],true);
    foreach(['customer','platform','mobile']as$scope){
        $listView=$scope.'_list';$detailView=$scope.'_detail';$expected=[];$wire=[];$selection=$full?$roots:[$paid];
        foreach($selection as$row){$id=m5pcp_int($row['id'],1);
            if(m5pcp_int($row['marketplace_trade_version'],0,1)===0)$wire[$id]=m4tr_legacy_expected($db,$owner,$id,$listView);
            else{$nativeExpected=m4tr_v1_expected($app,$db,$owner,$row,$listView,$paidSource,$baseline,$history);
                $expected[$id]=$nativeExpected['dto'];$wire[$id]=m4tr_wrapper_dto($nativeExpected['dto'],$listView);}}
        $query=m4tr_query($scope,$paid['order_id']);$paidId=m5pcp_int($paid['id'],1);$dtos=[$paidId=>$expected[$paidId]];
        foreach([false,true]as$wrapper){$window=$scope==='mobile'?$mobileWindow:$orderWindow;
            $page=$wrapper?$window[0]:0;$limit=$wrapper?$window[1]:0;$selected=m4tr_window([$paid],$page,$limit);
            $rows=[];foreach($selected as$row)$rows[]=$wire[m5pcp_int($row['id'],1)];
            $wireValue=$scope==='platform'?['data'=>$rows,'count'=>1]:$rows;
            $case=['view'=>$listView,'wrapper'=>$wrapper,'query'=>$query,'locator'=>null,'page'=>$page,'limit'=>$limit,
                'selected'=>$selected,'dtos'=>$dtos,'count'=>1,'user_info'=>null,'wire'=>$wireValue];
            $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);
        }
        $detail=m4tr_v1_expected($app,$db,$owner,$paid,$detailView,$paidSource,$baseline,$history,1,20);$locator=$scope==='platform'?$paidId:$paid['order_id'];
        foreach([false,true]as$wrapper){$wireValue=m4tr_wrapper_dto($detail['dto'],$detailView);
            if($scope==='platform')$wireValue=['orderInfo'=>$wireValue,'userInfo'=>$detail['user_info']];
            $case=['view'=>$detailView,'wrapper'=>$wrapper,'query'=>[],'locator'=>$locator,'page'=>1,'limit'=>20,'selected'=>[$paid],
                'dtos'=>[$paidId=>$detail['dto']],'count'=>1,'user_info'=>$detail['user_info'],'wire'=>$wireValue];
            $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);}
        if($full){
            foreach([[0,0],[1,0],[1,1],[100,1],[101,1],[102,1],[1,100],[2,100],[3,100]]as[$page,$limit]){
                $selected=m4tr_window($roots,$page,$limit);$case=['view'=>$listView,'wrapper'=>false,'query'=>m4tr_query($scope),'locator'=>null,
                    'page'=>$page,'limit'=>$limit,'selected'=>$selected,'dtos'=>$expected,'count'=>101,'user_info'=>null,'wire'=>null];
                $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);
            }
            $window=$scope==='mobile'?$mobileWindow:$orderWindow;$selected=m4tr_window($roots,$window[0],$window[1]);$rows=[];
            foreach($selected as$row)$rows[]=$wire[m5pcp_int($row['id'],1)];
            $case=['view'=>$listView,'wrapper'=>true,'query'=>m4tr_query($scope),'locator'=>null,'page'=>$window[0],'limit'=>$window[1],
                'selected'=>$selected,'dtos'=>$expected,'count'=>101,'user_info'=>null,'wire'=>$scope==='platform'?['data'=>$rows,'count'=>101]:$rows];
            $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);
            foreach([$memberRows[0],$legacyRows[0]]as$row){$id=m5pcp_int($row['id'],1);$legacy=m5pcp_int($row['marketplace_trade_version'],0,1)===0;
                $material=$legacy?['dto'=>[],'user_info'=>null]:m4tr_v1_expected($app,$db,$owner,$row,$detailView,$paidSource,$baseline,$history,1,20);
                $wireValue=$legacy?m4tr_legacy_expected($db,$owner,$id,$detailView):m4tr_wrapper_dto($material['dto'],$detailView);
                if(!$legacy&&$scope==='platform')$wireValue=['orderInfo'=>$wireValue,'userInfo'=>$material['user_info']];
                foreach([false,true]as$wrapper){$case=['view'=>$detailView,'wrapper'=>$wrapper,'query'=>[],
                    'locator'=>$scope==='platform'?$id:$row['order_id'],'page'=>1,'limit'=>20,'selected'=>[$row],
                    'dtos'=>[$id=>$material['dto']],'count'=>1,'user_info'=>$material['user_info'],'wire'=>$wireValue];
                    $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);}
            }
        }
        $family=m4tr_children_expected($paidSource,$baseline,$history,true);
        if($family['children']!==[]){
            foreach([[1,1],[2,1],[count($family['children'])+1,1]]as[$page,$limit]){
                $material=m4tr_v1_expected($app,$db,$owner,$paid,$detailView,$paidSource,$baseline,$history,$page,$limit);
                $wireValue=m4tr_wrapper_dto($material['dto'],$detailView);if($scope==='platform')$wireValue=['orderInfo'=>$wireValue,'userInfo'=>$material['user_info']];
                foreach([false,true]as$wrapper){$case=['view'=>$detailView,'wrapper'=>$wrapper,'query'=>[],'locator'=>$locator,'page'=>$page,'limit'=>$limit,
                    'selected'=>[$paid],'dtos'=>[$paidId=>$material['dto']],'count'=>1,'user_info'=>$material['user_info'],'wire'=>$wireValue];
                    $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);}
            }
            foreach($family['children']as$child){$id=$child['id'];$rows=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$id]);
                m4fn_expect(count($rows)===1&&m5pcp_int($rows[0]['pid'],1)===$paidId,'TASK6_THREE_DIRECT_CHILD_TRUE_SAVED_LOCATOR');
                $buyer=m5pcp_rows($db,'SELECT real_name FROM eb_user WHERE uid = ?',[$owner['uid']]);m4fn_expect(count($buyer)===1,
                    'TASK6_THREE_DIRECT_CHILD_REAL_PARENT_PAYMENT_BUYER');$userInfo=$scope==='platform'?$buyer[0]:null;$wireValue=m4tr_wrapper_dto($child,$detailView);
                if($scope==='platform')$wireValue=['orderInfo'=>$wireValue,'userInfo'=>$userInfo];
                foreach([false,true]as$wrapper){$case=['view'=>$detailView,'wrapper'=>$wrapper,'query'=>[],'locator'=>$scope==='platform'?$id:$child['order_id'],
                    'page'=>1,'limit'=>20,'selected'=>$rows,'dtos'=>[$id=>$child],'count'=>1,'user_info'=>$userInfo,'wire'=>$wireValue];
                    $proofs[]=m4tr_read_call($app,$db,$owner,$prepared['platform'],$case);}
            }
        }
    }
    m4fn_zero($baseline,m4fn_snapshot($db));m4fn_expect(m4tr_parent_runtime_files($app)===$runtime,
        'TASK6_THREE_WHOLE_STAGE_EXPECTED_BUILD_AND_EVERY_TARGET_READ_FILES_AND_DB_REDIS_ZERO');
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);
    return ['stage'=>$stage,'whole_population'=>101,'paid_roots'=>1,'unpaid_v1_roots'=>99,'legacy_roots'=>1,
        'member_saved_price_differs_from_base'=>true,'actual_wrapper_windows'=>['customer_platform'=>$orderWindow,'mobile'=>$mobileWindow],
        'calls'=>$proofs,'call_count'=>count($proofs),'complete_stage_zero'=>true];
}

/** Actual same-handle child closure is consumed before any failure result; no missing/unknown exit is successful. */
function m4tr_close_legacy_on_failure():void
{
    if(!isset($GLOBALS['m4tr_legacy_child'])||$GLOBALS['m4tr_legacy_child']===[])return;
    try{
        $child=&$GLOBALS['m4tr_legacy_child'];
        if(($child['process_closed']??false)!==true||($child['remaining_members']??null)!==[])
            $GLOBALS['m4tr_legacy_native_receipt']=m4tr_legacy_normal_close($child);
        $receipt=$GLOBALS['m4tr_legacy_native_receipt']??null;
        if(!is_array($receipt)||($receipt['process_closed']??false)!==true||($receipt['registered_group_gone']??false)!==true)
            $GLOBALS['m4tr_cleanup_failure']=['code'=>'TASK6_THREE_CHILD_CLOSURE_INCOMPLETE','class'=>null];
    }catch(Throwable $error){$GLOBALS['m4tr_cleanup_failure']=['code'=>'TASK6_THREE_CHILD_CLOSE_OBSERVATION_FAILURE','class'=>get_class($error)];}
}
/** A separate finite public result. Raw roots/items/UIDs/accounts/password hashes/tokens never enter this projection. */
function m4tr_result(array $source,bool $success,?array $diagnostic):array
{
    $prepared=$GLOBALS['m4tr_prepared']??null;$stages=$GLOBALS['m4tr_read_stages']??[];$native=$GLOBALS['m4tr_legacy_native_receipt']??null;
    $calls=0;$owned=0;$wrappers=0;
    foreach($stages as$stage)foreach($stage['calls']as$call){++$calls;if($call['surface']==='OWNED_PUBLIC_SERVICE')++$owned;else ++$wrappers;}
    if($success){
        m4fn_expect(is_array($prepared)&&count($stages)===6&&array_column($stages,'stage')===[0,1,2,3,4,5]
            &&$owned>=36&&$wrappers>=36&&is_array($native)&&$native['native_completed_normally']===true
            &&$native['native_exit']===0&&$native['process_closed']===true&&$native['registered_group_gone']===true
            &&$native['remaining_members']===[]&&$native['stderr_bytes']===0&&$native['capture_complete']===true
            &&empty($GLOBALS['m4tr_cleanup_failure']),'TASK6_THREE_ACTUAL_COMPLETE_SIX_STATES_AND_NATIVE_CHILD_CLOSED');
    }
    $fixture=null;$baseline=null;
    if(is_array($prepared)){
        $records=$prepared['v1']['records'];
        $fixture=['original_paid_commercial_roots'=>1,'new_true_legacy_commercial_roots'=>1,'new_true_v1_unpaid_roots'=>count($records),
            'whole_commercial_roots'=>101,'new_ordinary_v1_roots'=>$prepared['v1']['ordinary_count'],
            'new_member_v1_roots'=>$prepared['v1']['member_count'],'member_saved_unit_price'=>$records[98]['independent_unit_price'],
            'member_saved_base_price'=>$records[98]['native_sku_base_price'],'independent_new_v1_goods_total'=>$prepared['v1']['independent_goods_total'],
            'saved_deadline_zero_roots'=>99,'current_cancel_hours'=>1,'platform_ordinary_role_rows'=>1,'platform_ordinary_admin_rows'=>1,
            'internal_identity_is_not_HTTP_authentication'=>true,'full_native_preparation_delta_verified'=>true,
            'legacy_native_archive_sha256'=>$prepared['legacy']['descriptor']['sha256'],
            'private_preparation_facts_sha256'=>hash('sha256',serialize($prepared))];
        $baseline=['database_sha256'=>hash('sha256',serialize($prepared['baseline']['database'])),
            'redis_keys_values_absolute_expiries_sha256'=>hash('sha256',serialize($prepared['baseline']['redis']['keys'])),
            'complete_native_baseline_sha256'=>hash('sha256',serialize($prepared['baseline']))];
    }
    return ['schema'=>1,'scope'=>'MS4_THREE_READ_NORMAL_INTERNAL_ONLY',
        'status'=>$success?'VERIFIED_THREE_READ_NORMAL_INTERNAL_ONLY':'INCOMPLETE_THREE_READ_NORMAL_INTERNAL_ONLY',
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
        'container_id'=>$source['container_id'],'source_binding_sha256'=>$source['source_binding_sha256'],
        'phase'=>$GLOBALS['m4c_phase']??'source','entry_return_code'=>$success?0:1,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
        'legacy_native'=>$native,'fixture'=>$fixture,'baseline'=>$baseline,'stage_count'=>count($stages),
        'owned_call_count'=>$owned,'wrapper_call_count'=>$wrappers,'total_call_count'=>$calls,'stages'=>$stages,
        'final_source_and_original_inputs_verified'=>$success,'diagnostic'=>$diagnostic,
        'cleanup_failure'=>$GLOBALS['m4tr_cleanup_failure']??null,'HTTP_API_DOM'=>'NOT RUN','worker'=>'NOT RUN','task7'=>'NOT RUN',
        'full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN'];
}
function m4tr_publish_result(array $source,bool $success,?array $diagnostic):void
{
    m4fn_expect(empty($GLOBALS['m4tr_result_published']),'TASK6_THREE_ONE_SUPPLEMENTAL_RESULT_ONLY');
    $result=m4tr_result($source,$success,$diagnostic);m4c_entry_publish('/run/audit/evidence/ms4-three-read-normal.json',$result);
    $GLOBALS['m4tr_result_published']=true;
}

/** Real original cache producers, with independently native group/config contents and the exact finite Redis delta. */
function m4tr_warm_read_materials(\think\App $app,PDO $db):array
{
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();m4fn_context($app,$connection,$default,$db);
    $before=m4trp_snapshot($db);$files=m4tr_parent_runtime_files($app);$tagBefore=m4tr_crmeb_tag_members();$raw=[];$tagNames=[];$observed=[];
    foreach(['site_url'=>'','tengxun_map_key'=>'','routine_contact_type'=>0,'order_shipping_open'=>0,'refund_time_available'=>'']as$name=>$defaultValue){
        $rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($rows)<=1,'TASK6_THREE_WARM_EXTRA_UNIQUE_NATIVE_CONFIG_SOURCE');
        $value=$rows===[]?null:json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR);
        m4fn_expect($value===null||is_scalar($value),'TASK6_THREE_WARM_EXTRA_NATIVE_SCALAR_CONFIG');
        $callerValue=$value;
        if(is_string($callerValue)&&(str_contains($callerValue,'/uploads/system/')||str_contains($callerValue,'/statics/system_images/'))){
            m4fn_expect($name!=='site_url'&&isset($observed['site_url']),'TASK6_THREE_NATIVE_CONFIG_UPLOAD_SOURCE_HAS_REAL_SITE');
            if(substr($callerValue,0,4)!=='http'&&substr($callerValue,0,2)!=='//')$callerValue=$observed['site_url'].str_replace('\\','/',$callerValue);
        }
        $wanted=$callerValue===null?$defaultValue:trim((string)$callerValue);if($wanted==='')$wanted=$defaultValue;
        $actual=sys_config($name,$defaultValue);
        m4fn_expect($actual===$wanted,'TASK6_THREE_WARM_REAL_EXTRA_GETTER_MATCHES_NATIVE');$observed[$name]=$actual;
        $physical='c:system_config_'.$name;$raw[$physical]=m4tr_cache_wire($value);$tagNames[]=$physical;
    }
    $groups=m5pcp_rows($db,'SELECT * FROM eb_system_group WHERE config_name = ?',['order_details_images']);
    m4fn_expect(count($groups)<=1,'TASK6_THREE_WARM_UNIQUE_NATIVE_STATUS_IMAGE_GROUP');$gid=$groups===[]?0:m5pcp_int($groups[0]['id'],1);
    $rows=m5pcp_rows($db,'SELECT value,id FROM eb_system_group_data WHERE gid = ? AND status = 1 ORDER BY sort DESC,id DESC',[$gid]);$expected=[];
    $url=static function(mixed $image)use($observed):mixed{
        if(!$image)return $image;$site=$observed['site_url'];
        $one=static function(string $value)use($site):string{return substr($value,0,4)!=='http'&&substr($value,0,2)!=='//'?
            $site.str_replace('\\','/',$value):$value;};
        if(is_array($image)){$out=[];foreach($image as$key=>$value){m4fn_expect(is_string($value),'TASK6_THREE_WARM_REAL_UPLOAD_ARRAY_STRING');$out[$key]=$one($value);}return $out;}
        m4fn_expect(is_string($image),'TASK6_THREE_WARM_REAL_UPLOAD_STRING');return $one($image);
    };
    foreach($rows as$row){$id=m5pcp_int($row['id'],1);$fields=json_decode($row['value'],true,32,JSON_THROW_ON_ERROR);
        m4fn_expect(is_array($fields),'TASK6_THREE_WARM_NATIVE_GROUP_FIELDS_ARRAY');$value=['id'=>$row['id']];
        foreach($fields as$key=>$field){m4fn_expect(is_string($key)&&is_array($field)&&isset($field['type'])&&array_key_exists('value',$field),
                'TASK6_THREE_WARM_COMPLETE_NATIVE_GROUP_FIELD_TYPE_AND_VALUE');
            $value[$key]=$field['type']==='upload'?$url($field['value']):$field['value'];}
        $expected[]=$value;
    }
    $images=sys_data('order_details_images');m5pcp_same($images,$expected);
    $physical='c:data_order_details_images';$raw[$physical]=m4tr_cache_wire($expected);$tagNames[]=$physical;
    m4fn_expect(sys_config('image_thumb_status',0)==='0','TASK6_THREE_WARM_REAL_DISABLED_THUMB_ENGINE_SOURCE');
    foreach($expected as$image)if(isset($image['pic']))m4fn_expect(get_thumb_water(['image'=>$image['pic']])===['image'=>$image['pic']],
        'TASK6_THREE_WARM_ORIGINAL_THUMB_TRUE_PASSTHROUGH_NOT_SIMULATED_IMAGE');
    $after=m4trp_snapshot($db);m4trp_database_delta($before,$after,[]);
    m4tr_cache_delta($before['redis'],$after['redis'],$raw,$tagBefore,$tagNames);
    m4fn_expect(m4tr_parent_runtime_files($app)===$files,'TASK6_THREE_WARM_NO_CATCH_LOG_OR_IMAGE_FILE_WRITE');m4fn_context($app,$connection,$default,$db);
    return ['scope'=>'ORIGINAL_REAL_EXTRA_CONFIGURATION_AND_STATUS_IMAGE_GETTERS','extra_config_getters'=>5,'sys_data_getters'=>1,
        'native_group_rows'=>count($rows),'native_status_images_sha256'=>hash('sha256',serialize($expected)),
        'native_config_values_sha256'=>hash('sha256',serialize($observed)),'database_before_sha256'=>hash('sha256',serialize($before['database'])),
        'database_after_sha256'=>hash('sha256',serialize($after['database'])),'all_other_redis_exact'=>true,'native_permanent_TTL_and_tag_union_verified'=>true,
        'runtime_files_unchanged'=>true];
}

/** The one finite preparation after genuine paid/migration predecessors; never before the paid producer. */
function m4tr_prepare_native(\think\App $app,PDO $db,array $source,array $owner,array &$child):array
{
    $connection=\think\facade\Db::connect();$default=$connection->getPdo();
    if(!$default instanceof PDO){$connection->query('SELECT 1',[],true);$default=$connection->getPdo();}
    m4fn_expect($default instanceof PDO,'TASK6_REAL_APP_DEFAULT_PDO');m4fn_context($app,$connection,$default,$db);
    m4fn_expect($child===[]&&$app->config->get('marketplace.enabled')===true&&$app->config->get('marketplace.trade.enabled')===true,
        'TASK6_THREE_FINITE_PREPARATION_PARENT_TRUE_BEFORE_LEGACY_CHILD');m4fn_inputs_unchanged($owner['inputs']);
    $before=m4trp_snapshot($db);m4fn_phase('three_true_legacy_cli');$native=m4tr_legacy_cli($child);
    $GLOBALS['m4tr_legacy_native_receipt']=$native;
    $legacy=m4tr_legacy_archive($app,$db,$source,$owner,$before,$native,$child['stdout']);
    m4fn_context($app,$connection,$default,$db);m4fn_phase('three_true_ninety_nine_v1');$v1=m4tr_v1_create_ninety_nine($app,$db,$owner);
    $newRoots=[$legacy['state']['produced']['root']];foreach($v1['records']as$record)$newRoots[]=$record['root'];
    m4fn_phase('three_saved_zero_current_one');$configuration=m4tr_current_one_after_saved_zero($app,$db,$owner,$newRoots);
    m4fn_phase('three_original_formatter_material_warm');$warm=m4tr_warm_read_materials($app,$db);
    // The legacy producer already generated and verified its title/cart native caches. These six actual original formatters must now be zero.
    $formatterBefore=m4fn_snapshot($db);$files=m4tr_parent_runtime_files($app);$legacyId=m5pcp_int($legacy['state']['produced']['root']['id'],1);
    foreach(['customer_list','platform_list','mobile_list','customer_detail','platform_detail','mobile_detail']as$view)
        m4tr_legacy_expected($db,$owner,$legacyId,$view);
    m4fn_zero($formatterBefore,m4fn_snapshot($db));m4fn_expect(m4tr_parent_runtime_files($app)===$files,
        'TASK6_THREE_ORIGINAL_LEGACY_FULL_FORMATTER_WARM_NO_UNLISTED_WRITE');
    m4fn_phase('three_actual_platform_internal_identity');$platform=m4tr_internal_platform_actor($app,$db,$owner);
    $roots=m4tr_native_commercial_roots($db,$owner);m4fn_expect(count($roots)===101&&count($newRoots)===100,
        'TASK6_THREE_COMPLETE_REAL_PREPARATION_POPULATION_BEFORE_READ_BASELINE');
    $baseline=m4fn_snapshot($db);$catalog=m4trp_snapshot($db);
    m4fn_expect($catalog['database']===$baseline['database']&&$catalog['redis']['keys']===$baseline['redis']['keys'],
        'TASK6_THREE_FULL_NATIVE_ROW_AND_NAMESPACE_BASELINES_MATCH');
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);m4cb_guard_readonly();
    foreach($source['environment']as$name=>$value)m4fn_expect(getenv($name)===$value,'TASK6_THREE_PREPARATION_SIX_NATIVE_ENV_REMAIN_TRUE');
    m4fn_expect($app->config->get('marketplace.enabled')===true&&$app->config->get('marketplace.trade.enabled')===true
        &&\app\services\marketplace\order\MarketplaceTradePolicy::v1CreateEnabled()===true,
        'TASK6_THREE_AFTER_PREPARATION_ORIGINAL_PARENT_FACTORY_UNCHANGED');
    return ['legacy'=>$legacy,'v1'=>$v1,'configuration'=>$configuration,'warm'=>$warm,'platform'=>$platform,
        'baseline'=>$baseline,'full_native_catalog_baseline'=>$catalog,'whole_before_sha256'=>hash('sha256',serialize($before)),
        'full_native_preparation_delta_verified'=>true];
}
