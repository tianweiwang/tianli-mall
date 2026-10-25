<?php
declare(strict_types=1);
require_once '/media-checks/verify.php';
require_once audit_repo_dir().'/help/audit-regressions/isolated/lib/media-normal-profile.php';

/** Bounded observations only. This file never issues HTTP, mutates a session, or invokes GC/tick. */
function mg_read(string $path,int $maximum,bool $private=true): array
{
    clearstatcache(true,$path);$before=lstat($path);m86_expect(is_array($before)&&!is_link($path)&&($before['mode']&0170000)===0100000
        &&$before['nlink']===1&&$before['size']>=0&&$before['size']<=$maximum,'GC_REAL_BOUNDED_REGULAR_FILE');
    if($private)m86_expect(($before['mode']&0777)===0600&&$before['uid']===posix_geteuid(),'GC_PRIVATE_FILE_MODE_OWNER');
    $pick=static function(array$s):array{$v=[];foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$k)$v[$k]=$s[$k];return $v;};$stat=$pick($before);
    $h=fopen($path,'rb');m86_expect(is_resource($h),'GC_FILE_HANDLE');
    try{m86_expect($pick(fstat($h))===$stat,'GC_FILE_NAMED_HANDLE_IDENTITY');$bytes='';$hash=hash_init('sha256');
        while(strlen($bytes)<$before['size']){$part=fread($h,min(1048576,$before['size']-strlen($bytes)));m86_expect(is_string($part)&&$part!=='','GC_COMPLETE_FILE_READ');$bytes.=$part;hash_update($hash,$part);}
        m86_expect(fread($h,1)===''&&feof($h),'GC_FILE_EOF');clearstatcache(true,$path);$after=lstat($path);$fd=fstat($h);
        m86_expect(is_array($after)&&is_array($fd)&&!is_link($path)&&$pick($after)===$stat&&$pick($fd)===$stat,'GC_FILE_STABLE_FULL_READ');$sha=hash_final($hash);
    }finally{m86_expect(fclose($h),'GC_FILE_HANDLE_CLOSED');}return ['bytes'=>$bytes,'sha256'=>$sha,'stat'=>$stat];
}
function mg_published_tree(): array
{
    $root='/run/media-uploads-observe';m86_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'GC_ORIGINAL_PUBLISHED_ROOT');$out=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as$item){m86_expect(!$item->isLink()&&count($out)<4096,'GC_COMPLETE_PUBLISHED_TREE_NAMESPACE');$path=$item->getPathname();$relative=substr($path,strlen($root)+1);$key=hash('sha256',$relative);m86_expect(!isset($out[$key]),'GC_UNIQUE_PUBLISHED_NAME_HASH');
        if($item->isDir()){$s=lstat($path);$out[$key]=['kind'=>'directory','dev'=>$s['dev'],'ino'=>$s['ino'],'mode'=>$s['mode'],'uid'=>$s['uid'],'gid'=>$s['gid'],'mtime'=>$s['mtime'],'ctime'=>$s['ctime']];}
        else{$v=mg_read($path,52428800,false);$out[$key]=['kind'=>'file','stat'=>$v['stat'],'bytes'=>strlen($v['bytes']),'sha256'=>$v['sha256']];}}
    ksort($out,SORT_STRING);return $out;
}
function mg_commercial(): array
{
    $tables=['eb_system_attachment','eb_marketplace_audit_log','eb_store_product','eb_store_product_attr','eb_store_product_attr_value','eb_store_product_attr_result','eb_store_product_description','eb_store_product_cate',
        'eb_store_order','eb_store_order_cart_info','eb_store_order_refund','eb_store_order_status','eb_store_order_invoice','eb_store_pink','eb_live_goods','eb_live_room','eb_user_sign','eb_store_cart','eb_user',
        'eb_marketplace_order_idempotency','eb_marketplace_stock_movement','eb_marketplace_payment_registry','eb_marketplace_payment_attempt','eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range','eb_marketplace_aftersale_action','eb_marketplace_aftersale_file'];$out=[];
    foreach($tables as$table){$rows=m86_q(m86_db(),'SELECT * FROM `'.$table.'`');$raw=array_map('serialize',$rows);sort($raw,SORT_STRING);$out[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',serialize($raw))];}return $out;
}
function mg_redis(): array
{
    $r=audit_redis();$names=$r->keys('*');sort($names,SORT_STRING);$out=[];
    foreach($names as$name){$type=$r->type($name);$value=match($type){Redis::REDIS_STRING=>$r->get($name),Redis::REDIS_HASH=>$r->hGetAll($name),Redis::REDIS_SET=>$r->sMembers($name),default=>null};
        m86_expect($value!==null&&$value!==false,'GC_ALL_REAL_REDIS_TYPES');if($type===Redis::REDIS_HASH)ksort($value,SORT_STRING);if($type===Redis::REDIS_SET)sort($value,SORT_STRING);
        $expires=$r->rawCommand('PEXPIRETIME',$name);m86_expect(is_int($expires)&&($expires===-1||$expires>0),'GC_NATIVE_REDIS_ABSOLUTE_EXPIRY');$out[hash('sha256',$name)]=['type'=>$type,'sha256'=>hash('sha256',serialize($value)),'expires'=>$expires];}ksort($out,SORT_STRING);return $out;
}
function mg_gc_key(): string
{
    $app=app();$options=$app->config->get('cache.stores.redis');m86_expect($app->config->get('cache.default')==='redis'&&$options['prefix']==='c:'&&$options['tag_prefix']==='CRMEB:'&&$options['select']===0
        &&$app->config->get('marketplace.product_media_scan.gc_period_seconds')===60,'GC_ACTUAL_ORIGINAL_REDIS_PROFILE');return 'c:product-media-scan-v1:timer:last_tick:'.hash('sha256',"audit_auth\0eb_system_timer\0".'0');
}
function mg_before(): void
{
    m86_start();audit_media_normal_roots();$p=audit_media_normal_prepared();$e=m86_read_json(audit_run_dir().'/evidence/media-http.json');
    m86_expect($e['run_id']===$p['run_id']&&$e['source_sha']===$p['source_sha']&&$e['status']==='PASS_NORMAL_LOCAL_MEDIA_PRODUCT_LIFECYCLE_SLICE_ONLY'
        &&count($e['completed_video_references']??[])===2,'GC_COMPLETE_ORIGINAL_HTTP_AND_BOTH_REAL_LARGE_CHAINS_REQUIRED');
    $apps=array_column($e['completed_video_references'],'app');sort($apps,SORT_STRING);m86_expect($apps===['adminapi','merchantapi'],'GC_BOTH_ACTUAL_AUTHORIZED_ACTORS_REQUIRED');
    $by=[];foreach($e['completed_video_references']as$row){m86_expect(in_array($row['app'],['adminapi','merchantapi'],true)&&!isset($by[$row['reference_sha256']]),'GC_TWO_UNIQUE_ACTUAL_REFERENCE_HASHES');$by[$row['reference_sha256']]=$row;}
    $actors=m86_actor_inputs();$large=m86_large_fixture();$root=AUDIT_MEDIA_ROOTS[0];$names=scandir($root);$sessions=[];$seen=[];
    foreach($names as$name){if(in_array($name,['.','..','.lock'],true))continue;m86_expect(preg_match('/\A([0-9a-f]{32})\.(json|bin)\z/D',$name,$m)===1,'GC_ONLY_OWN_REAL_PRIVATE_SESSION_FILES');
        $ref=$m[1];$hash=hash('sha256',$ref);m86_expect(isset($by[$hash]),'GC_NO_UNKNOWN_SESSION');$seen[$hash][$m[2]]=true;if($m[2]!=='json')continue;
        $manifest=mg_read($root.'/'.$name,65536);$state=json_decode($manifest['bytes'],true,32,JSON_THROW_ON_ERROR);$row=$by[$hash];$owner=$row['app']==='adminapi'?$actors['self_operated_mer_id']:$actors['merchant']['mer_id'];
        $actor=$row['app']==='adminapi'?hash('sha256','admin:'.$actors['platform']['id']):hash('sha256','merchant:'.$actors['merchant']['mer_id'].':'.$actors['merchant']['employee_id']);
        $native=m86_native_media($row['att_id'],$owner);$keys=['purpose','binding','actor','filename','total_size','chunk_size','total_chunks','chunks','created_at','expires_at','state','sha256','result','data_dev','data_ino'];
        m86_expect(array_keys($state)===$keys&&$state['purpose']==='product-video-upload-v1'&&is_string($state['binding'])&&preg_match('/\A[0-9a-f]{64}\z/D',$state['binding'])===1&&$state['actor']===$actor
            &&$state['filename']===$large['name']&&$state['total_size']===$large['bytes']&&$state['chunk_size']===3145728&&$state['total_chunks']===3&&$state['chunks']===array_column($large['chunks'],'sha256')
            &&$state['state']==='complete'&&$state['sha256']===$large['sha256']&&$state['result']===$native['att_dir']&&is_int($state['created_at'])&&is_int($state['expires_at'])
            &&$state['created_at']<=time()&&$state['expires_at']-$state['created_at']===600&&time()<$state['expires_at'],'GC_REAL_COMPLETED_UNEXPIRED_600S_SESSION_AND_PUBLISHED_RESULT');
        $data=mg_read($root.'/'.$ref.'.bin',52428800);m86_expect(strlen($data['bytes'])===$large['bytes']&&$data['sha256']===$large['sha256']&&$data['stat']['dev']===$state['data_dev']&&$data['stat']['ino']===$state['data_ino'],'GC_REAL_FULL_STAGED_MP4_DATA_IDENTITY');
        $sessions[$hash]=['app'=>$row['app'],'att_id'=>$row['att_id'],'created_at'=>$state['created_at'],'expires_at'=>$state['expires_at'],'manifest'=>$manifest['stat'],'manifest_sha256'=>$manifest['sha256'],'data'=>$data['stat'],'data_sha256'=>$data['sha256'],'published_native14_sha256'=>hash('sha256',m86_json($native))];
    }
    m86_expect(count($sessions)===2&&array_keys($sessions)===array_keys($seen),'GC_COMPLETE_TWO_SESSION_NAMESPACE');foreach($seen as$pair){$keys=array_keys($pair);sort($keys);m86_expect($keys===['bin','json'],'GC_BOTH_PRIVATE_FILES_PRESENT');}
    ksort($sessions,SORT_STRING);mg_read($root.'/.lock',65536);audit_media_app();$gcKey=mg_gc_key();$redis=audit_redis();m86_expect($redis->get($gcKey)===false,'GC_GATE_NOT_PREINVOKED_BY_A_TEST');
    $tags=$redis->sMembers('c:CRMEB:'.md5('crmeb'));sort($tags,SORT_STRING);$timers=m86_q(m86_db(),'SELECT * FROM eb_system_timer WHERE is_open=1 AND is_del=0 ORDER BY id');$rules=[];$service=app()->make(\app\services\system\crontab\SystemCrontabServices::class);
    foreach($timers as$row)$rules[$row['mark']]=$row['timeStr']!==''?$row['timeStr']:$service->getTimerStr($row);
    $v=['schema'=>1,'scope'=>'REAL_COMPLETE_VIDEO_SESSIONS_BEFORE_ORIGINAL_TIMER','run_id'=>$p['run_id'],'source_sha'=>$p['source_sha'],'observed_at'=>time(),'monotonic_ns'=>hrtime(true),'sessions'=>$sessions,
        'commercial'=>mg_commercial(),'published'=>mg_published_tree(),'redis'=>mg_redis(),'original_tag_member_hashes'=>array_map(static fn(string$s):string=>hash('sha256',$s),$tags),'actual_open_rules'=>$rules,'active_rows_sha256'=>hash('sha256',serialize($timers)),
        'observer_HTTP_requests'=>count($GLOBALS['m86_requests']),'gc_called_by_observer'=>false,'original_default_11_WeChat_browser_MS6'=>'NOT RUN'];
    m86_expect($v['observer_HTTP_requests']===0,'GC_BEFORE_OBSERVER_HAS_NO_HTTP');$file=audit_run_dir().'/media-gc-before.json';m86_expect(!file_exists($file),'GC_BEFORE_CREATE_ONLY');audit_write_json($file,$v);
}
function mg_timer_observe(): array
{
    audit_app_request('observe',[],'media-timer');$v=audit_read_control_json(audit_run_dir().'/media-timer-status.json');
    m86_expect(($v['status']??null)==='observe'&&($v['run_id']??null)===getenv('AUDIT_RUN_ID')&&count($v['members']??[])>=2&&($v['listener_open']??null)===false,'GC_REAL_ORIGINAL_TIMER_LIVE_GROUP');return $v;
}
function mg_logs(array $group,int $childPid,int $started,int $ended,array $rules): array
{
    $root='/run/media-timer-runtime-observe';$rootStat=audit_media_stat($root);m86_expect(($rootStat['mode']&0777)===0700&&$rootStat['uid']===posix_geteuid(),'GC_PRIVATE_TIMER_RUNTIME_ROOT');$files=[];$success=[];$events=[];$failures=0;
    $messages=['orderCancel'=>'执行未支付自动取消订单','pinkExpiration'=>'执行拼团到期订单处理','agentUnbind'=>'执行自动解绑上级绑定','liveProductStatus'=>'执行更新直播商品状态','liveRoomStatus'=>'执行更新直播间状态',
        'takeDelivery'=>'执行自动收货','advanceOff'=>'执行预售到期商品自动下架','productReplay'=>'执行自动好评','clearPoster'=>'执行清除昨日海报','autoInvoice'=>'执行自动开具/冲红电子发票','signRemind'=>'执行未签到提醒'];
    $directory=$root.'/log';m86_expect(is_dir($directory)&&!is_link($directory),'GC_ACTUAL_ORIGINAL_FRAMEWORK_LOG_DIRECTORY');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST)as$item){
        m86_expect(!$item->isLink(),'GC_PRIVATE_LOG_NO_LINK');$path=$item->getPathname();if($item->isDir()){$stat=lstat($path);m86_expect(($stat['mode']&0777)===0700&&$stat['uid']===posix_geteuid(),'GC_PRIVATE_LOG_DIRECTORY_MODE');continue;}
        m86_expect(count($files)<256,'GC_FINITE_PRIVATE_LOG_FILE_COUNT');$v=mg_read($path,16777216);$files[hash('sha256',substr($path,strlen($root)+1))]=['bytes'=>strlen($v['bytes']),'sha256'=>$v['sha256'],'stat'=>$v['stat']];
        foreach(explode("\n",$v['bytes'])as$line){if(trim($line)==='')continue;
            if(preg_match('/\|(?:error|warning|fail)\|/i',$line)||str_contains($line,'失败')||str_contains($line,'方法不存在')||preg_match('/MARKETPLACE_[A-Z0-9_]*(?:PENDING|UNAVAILABLE)/',$line))++$failures;
            foreach($messages as$mark=>$message)if(str_contains($line,'|crontab|')&&str_contains($line,$message))$success[$mark]=($success[$mark]??0)+1;
            $marker='MARKETPLACE_FULFILLMENT_WORKER_EVENT ';$pos=strpos($line,$marker);if($pos!==false){$event=json_decode(substr($line,$pos+strlen($marker)),true,64,JSON_THROW_ON_ERROR);
                m86_expect(is_array($event)&&in_array($event['phase']??null,['timer_started','scan_returned','timer_removed'],true)&&is_int($event['pid']??null)&&$event['pid']===$childPid&&$event['pid']!==$group['leader_pid']
                    &&$event['owned']===true&&$event['worker_count']===1&&$event['slot']===0&&$event['count']===1&&$event['socketless']===true&&is_int($event['timer_id'])&&$event['timer_id']>0,'GC_ORIGINAL_TASK6_ACTUAL_OWNED_TIMER_EVENT');
                if($event['phase']==='scan_returned')m86_expect(($event['details']['scan']['processed']??null)===0&&($event['details']['scan']['pending']??null)===0&&($event['details']['cursor_saved']??null)===true,'GC_ORIGINAL_TASK6_EMPTY_SUCCESS_SCAN_AND_CURSOR');
                $events[]=['phase'=>$event['phase'],'pid'=>$event['pid'],'worker_id'=>$event['worker_id'],'loop_class'=>$event['loop_class'],'timer_id'=>$event['timer_id'],'monotonic_ns'=>$event['monotonic_ns']];}
        }
    }
    m86_expect($failures===0&&array_sum($success)>0&&($success['liveRoomStatus']??0)===0,'GC_NO_EXECUTED_ORIGINAL_TIMER_FAILURE_AND_REAL_CRONTAB_LOGGING_ENABLED');
    $startedEvents=array_values(array_filter($events,static fn(array$e):bool=>$e['phase']==='timer_started'));$removed=array_values(array_filter($events,static fn(array$e):bool=>$e['phase']==='timer_removed'));$scans=array_values(array_filter($events,static fn(array$e):bool=>$e['phase']==='scan_returned'));
    m86_expect(count($startedEvents)===1&&count($removed)===1&&count($scans)>=2,'GC_ORIGINAL_TASK6_START_MULTIPLE_SCANS_AND_REMOVAL_REQUIRED');$first=$startedEvents[0];
    foreach($events as$event)m86_expect($event['pid']===$first['pid']&&$event['worker_id']===$first['worker_id']&&$event['loop_class']===$first['loop_class']&&$event['timer_id']===$first['timer_id'],'GC_ONE_ACTUAL_ORIGINAL_TASK6_TIMER_IDENTITY');
    $parser=new \Workerman\Crontab\Parser();$expected=[];$safeStart=intdiv($started,60)*60+120;$safeEnd=$ended-2;
    foreach($messages as$mark=>$message){if(!isset($rules[$mark]))continue;$due=[];for($minute=intdiv($safeStart,60)*60;$minute<=$safeEnd;$minute+=60)foreach($parser->parse($rules[$mark],$minute)as$time)if($time>=$safeStart&&$time<=$safeEnd)$due[$time]=true;
        $minimum=count($due);m86_expect(($success[$mark]??0)>=$minimum,'GC_EVERY_ORIGINAL_TASK_DUE_IN_SAFE_ACTUAL_WINDOW_HAS_REAL_SUCCESS_LOG');$expected[$mark]=['safe_due_count'=>$minimum,'actual_success_count'=>$success[$mark]??0,'scope'=>$minimum===0?'NOT_SCHEDULED_IN_SAFE_OBSERVED_WINDOW':'ACTUALLY_SCHEDULED_WINDOW_ONLY'];}
    foreach(['stdout','stderr']as$kind){$v=mg_read('/run/media-timer-logs-observe/media-timer-runtime.log.'.$kind,16777216);m86_expect($kind!=='stderr'||$v['bytes']==='','GC_ORIGINAL_TIMER_NATIVE_STDERR_EMPTY');
        m86_expect(preg_match('/(?:PHP (?:Warning|Fatal|Parse)|Fatal error|Uncaught (?:Exception|Error)):/i',$v['bytes'])!==1,'GC_ORIGINAL_TIMER_NATIVE_STREAM_NO_PHP_FAILURE');$files['original_timer_'.$kind]=['bytes'=>strlen($v['bytes']),'sha256'=>$v['sha256'],'stat'=>$v['stat']];}
    $workerLog=mg_read($root.'/timer.log',16777216);$initial=m86_read_json(audit_run_dir().'/media-private-roots.json');
    m86_expect($initial['run_id']===getenv('AUDIT_RUN_ID')&&$initial['source_sha']===M86_SOURCE&&$initial['timer_log']['name']==='timer.log'&&$initial['timer_log']['bytes']===0&&$initial['timer_log']['sha256']===hash('sha256',''),'GC_SAME_REAL_PRECREATED_TIMER_LOG_RECEIPT');
    foreach(['dev','ino','mode','uid','gid','nlink']as$key)m86_expect($workerLog['stat'][$key]===$initial['timer_log']['stat'][$key],'GC_ORIGINAL_TIMER_LOG_SAME_INITIAL_PRIVATE_FILE');
    foreach(['dev','ino','mode','uid','gid']as$key)m86_expect($rootStat[$key]===$initial['timer_runtime'][$key],'GC_SAME_INITIAL_PRIVATE_TIMER_RUNTIME');
    m86_expect(preg_match('/\b(?:[A-Za-z_\\\\]*Exception|Error|TypeError):/',$workerLog['bytes'])!==1,'GC_ORIGINAL_WORKERMAN_PRIVATE_LOG_NO_EXCEPTION');$files['original_Workerman_log']=['bytes'=>strlen($workerLog['bytes']),'sha256'=>$workerLog['sha256'],'stat'=>$workerLog['stat']];
    $heartbeat=mg_read($root.'/.timer',32);m86_expect(ctype_digit($heartbeat['bytes'])&&(int)$heartbeat['bytes']>=$started&&(int)$heartbeat['bytes']<=$ended,'GC_ORIGINAL_SCHEDULER_ACTUAL_HEARTBEAT');
    return ['private_files'=>$files,'raw_log_content_omitted'=>true,'actual_original_crontab_records_prove_timer_log_enabled'=>true,'executed_failure_count'=>$failures,'actual_scheduled_scope'=>$expected,'actual_Task6_events'=>$events,
        'original_scheduler_heartbeat'=>['bytes'=>strlen($heartbeat['bytes']),'sha256'=>$heartbeat['sha256'],'last_epoch'=>(int)$heartbeat['bytes']],'full_day_month_default_11_and_MS6'=>'NOT RUN'];
}
function mg_redis_after(array $before,array $samples,array $seenMutable,int $finished): array
{
    $after=mg_redis();$redis=audit_redis();$key=mg_gc_key();$tag='c:CRMEB:'.md5('crmeb');$allowed=[$key,$tag,'c:marketplace_order_cancel_cursor','c:marketplace_fulfillment_receive_cursor','c:sign_remind_expire'];$hashes=array_map(static fn(string$s):string=>hash('sha256',$s),$allowed);
    foreach($before['redis']as$name=>$row)if(!in_array($name,$hashes,true))m86_expect(isset($after[$name])&&$after[$name]===$row,'GC_ALL_OTHER_ORIGINAL_REDIS_VALUES_TYPES_AND_ABSOLUTE_EXPIRIES');
    foreach($after as$name=>$row)m86_expect(isset($before['redis'][$name])||in_array($name,$hashes,true),'GC_NO_UNREGISTERED_TIMER_REDIS_KEY');
    m86_expect(count($samples)>=2,'GC_MULTIPLE_REAL_REDIS_CADENCE_SAMPLES');for($i=1;$i<count($samples);$i++)m86_expect($samples[$i]-$samples[$i-1]>=60000,'GC_ORIGINAL_60S_CADENCE_NOT_SHORTENED');
    m86_expect($redis->type($key)===Redis::REDIS_STRING&&$redis->get($key)===(string)end($samples)&&$redis->rawCommand('PEXPIRETIME',$key)===-1,'GC_ACTUAL_ORIGINAL_GATE_VALUE_AND_NO_TTL');
    m86_expect(\crmeb\services\CacheService::get('marketplace_order_cancel_cursor')===['deadline'=>0,'order_id'=>0]
        &&\crmeb\services\CacheService::get('marketplace_fulfillment_receive_cursor')===['due_at'=>0,'ship_id'=>'0'],'GC_ORIGINAL_EMPTY_CANCEL_AND_TASK6_CURSOR_VALUES');
    foreach(['c:marketplace_order_cancel_cursor','c:marketplace_fulfillment_receive_cursor']as$name)m86_expect($redis->type($name)===Redis::REDIS_STRING&&$redis->rawCommand('PEXPIRETIME',$name)===-1,'GC_ORIGINAL_CURSOR_NATIVE_TYPE_AND_EXPIRY');
    $tags=$before['original_tag_member_hashes'];foreach(array_slice($allowed,2)as$name)if($redis->exists($name)||isset($seenMutable[hash('sha256',$name)]))$tags[]=hash('sha256',$name);$tags=array_values(array_unique($tags));sort($tags,SORT_STRING);$actual=array_map(static fn(string$s):string=>hash('sha256',$s),$redis->sMembers($tag));sort($actual,SORT_STRING);
    m86_expect($redis->type($tag)===Redis::REDIS_SET&&$actual===$tags&&$redis->rawCommand('PEXPIRETIME',$tag)===-1,'GC_ORIGINAL_CACHE_TAG_EXACT_MEMBERS_AND_EXPIRY');
    if($redis->exists('c:sign_remind_expire')){$expires=$redis->rawCommand('PEXPIRETIME','c:sign_remind_expire');$tomorrow=strtotime('tomorrow')*1000;
        m86_expect($redis->type('c:sign_remind_expire')===Redis::REDIS_STRING&&(string)\crmeb\services\CacheService::get('sign_remind_expire')==='1'
            &&$expires>=$tomorrow&&$expires<=$tomorrow+($finished-$before['observed_at']+1)*1000,'GC_ORIGINAL_EMPTY_REMINDER_VALUE_AND_REAL_DAY_DEADLINE_WINDOW');}
    elseif(isset($seenMutable[hash('sha256','c:sign_remind_expire')]))m86_expect($seenMutable[hash('sha256','c:sign_remind_expire')]['expires']>0&&$seenMutable[hash('sha256','c:sign_remind_expire')]['expires']<=$finished*1000,'GC_ONLY_ORIGINAL_OBSERVED_DAY_EXPIRY_MAY_DISAPPEAR');
    return ['all_native_key_states'=>$after,'allowed_actual_producers'=>['original_gc_cadence','original_order_cancel_cursor','original_Task6_cursor','original_empty_sign_reminder_day_expiry','original_crmeb_tag_members'],
        'all_other_keys_values_types_and_absolute_expiries_preserved'=>true,'gc_cadence_millisecond_samples'=>$samples,'actual_selected_producer_key_expiry_observations'=>$seenMutable];
}
function mg_observe(): void
{
    m86_start();audit_media_normal_roots();$p=audit_media_normal_prepared();$before=m86_read_json(audit_run_dir().'/media-gc-before.json');m86_expect($before['run_id']===$p['run_id']&&$before['source_sha']===$p['source_sha']&&count($before['sessions'])===2,'GC_CURRENT_BEFORE_RECEIPT');
    audit_media_app();$gcKey=mg_gc_key();$redis=audit_redis();$start=time();$first=mg_timer_observe();$group=$first['group'];$observations=[$first];$samples=[];$seenMutable=[];$expires=max(array_column($before['sessions'],'expires_at'));$deadline=$expires+180;$lastObserve=$start;
    m86_expect($start<$expires&&$start-$before['observed_at']>=0,'GC_REAL_TIMER_OBSERVATION_BEFORE_LATEST_600S_EXPIRY');
    while(true){$now=time();$seen=[];foreach(scandir(AUDIT_MEDIA_ROOTS[0])as$name){if(in_array($name,['.','..','.lock'],true))continue;
            m86_expect(preg_match('/\A([0-9a-f]{32})\.(json|bin)\z/D',$name,$m)===1,'GC_DURING_ONLY_REGISTERED_SESSION_NAMES');$hash=hash('sha256',$m[1]);m86_expect(isset($before['sessions'][$hash]),'GC_DURING_NO_NEW_UNKNOWN_ISSUED_SESSION');$seen[$hash]=true;}
        foreach($before['sessions']as$hash=>$session)if(!isset($seen[$hash]))m86_expect($now>=$session['expires_at'],'GC_NO_EARLY_PRIVATE_SESSION_REMOVAL');
        foreach(['c:marketplace_order_cancel_cursor','c:marketplace_fulfillment_receive_cursor','c:sign_remind_expire']as$name)if($redis->exists($name))$seenMutable[hash('sha256',$name)]=['observed_at'=>$now,'expires'=>$redis->rawCommand('PEXPIRETIME',$name)];
        $value=$redis->get($gcKey);if($value!==false){m86_expect(is_string($value)&&preg_match('/\A[1-9][0-9]{0,15}\z/D',$value)===1,'GC_ACTUAL_NATIVE_CADENCE_MILLISECONDS');$number=(int)$value;if($samples===[]||end($samples)!==$number)$samples[]=$number;}
        if($seen===[]){m86_expect($now>=$expires,'GC_ALL_BOTH_SESSION_EXPIRIES_REACHED');if(count($samples)>=2)break;
            m86_expect($now<=max($deadline,$start+240),'GC_SECOND_ORIGINAL_CADENCE_SAMPLE_MISSING');}
        else m86_expect($now<=$deadline,'GC_REAL_ORIGINAL_TIMER_DID_NOT_COLLECT_BY_ORIGINAL_PERIOD_WINDOW');
        if($now-$lastObserve>=60){$observed=mg_timer_observe();m86_expect($observed['group']===$group,'GC_SAME_REAL_TIMER_GROUP_THROUGHOUT');$observations[]=$observed;$lastObserve=$now;}usleep(1000000);
    }
    $last=mg_timer_observe();m86_expect($last['group']===$group,'GC_SAME_TIMER_GROUP_AT_NATURAL_COLLECTION');$observations[]=$last;
    m86_expect(scandir(AUDIT_MEDIA_ROOTS[0])===['.','..','.lock']&&mg_commercial()===$before['commercial']&&mg_published_tree()===$before['published'],'GC_ONLY_PRIVATE_SESSION_FILES_REMOVED_ALL_COMMERCIAL_ROWS_AND_OBJECTS_PRESERVED');
    $timers=m86_q(m86_db(),'SELECT * FROM eb_system_timer WHERE is_open=1 AND is_del=0 ORDER BY id');m86_expect(hash('sha256',serialize($timers))===$before['active_rows_sha256'],'GC_CONFIGURED_ORIGINAL_TIMER_ROWS_UNCHANGED');
    // This is the planned ordinary lifecycle after successful natural collection, never failure cleanup.
    audit_app_request('stop',[],'media-timer');$stop=audit_read_control_json(audit_run_dir().'/media-timer-status.json');$ended=time();
    m86_expect(($stop['status']??null)==='stop'&&($stop['run_id']??null)===$p['run_id']&&($stop['stopped_group']??null)===$group&&($stop['remaining_members']??null)===[]&&($stop['listener_closed']??null)===true
        &&($stop['direct_child_native_exit']??null)===0&&($stop['stop_mode']??null)==='term-only'&&($stop['force_signal_sent']??null)===false,'GC_ORIGINAL_TIMER_TERM_ONLY_SAME_GROUP_NATIVE_ZERO');
    $children=array_values(array_filter($last['members'],static fn(array$m):bool=>$m['pid']!==$group['leader_pid']));m86_expect(count($children)===1,'GC_ONE_ACTUAL_ORIGINAL_WORKERMAN_CHILD');
    $logs=mg_logs($group,$children[0]['pid'],$start,$ended,$before['actual_open_rules']);$cache=mg_redis_after($before,$samples,$seenMutable,$ended);
    m86_expect(mg_commercial()===$before['commercial']&&mg_published_tree()===$before['published']&&count($GLOBALS['m86_requests'])===0,'GC_AFTER_NORMAL_STOP_ALL_PUBLISHED_BUSINESS_AND_NO_OBSERVER_HTTP');
    $v=['schema'=>1,'scope'=>'NATURAL_VIDEO_GC_DEDICATED_EXPLICIT_NORMAL_TIMER_CONFIG_ONLY','run_id'=>$p['run_id'],'source_sha'=>$p['source_sha'],'status'=>'OBSERVED_TWO_COMPLETE_SESSIONS_NATURALLY_EXPIRED',
        'before_receipt'=>m86_pin(file_get_contents(audit_run_dir().'/media-gc-before.json')),'observed_start'=>$start,'observed_end'=>$ended,'original_video_ttl_seconds'=>600,'original_gc_period_seconds'=>60,
        'actual_group_observations'=>$observations,'ordinary_Timer_stop'=>$stop,'private_log_observation'=>$logs,'redis_observation'=>$cache,'formal_native14_audit_product_SKU_and_all_published_objects_preserved'=>true,
        'observer_HTTP_requests'=>0,'gc_or_tick_called_by_observer'=>false,'original_default_11_real_WeChat_full_day_month_MS6_browser'=>'NOT RUN','full_T4_acceptance'=>'INCOMPLETE'];
    $file=audit_run_dir().'/media-gc-observed.json';m86_expect(!file_exists($file),'GC_OBSERVED_CREATE_ONLY');audit_write_json($file,$v);
}
try{umask(0077);m86_expect(count($argv)===2&&in_array($argv[1],['before','observe'],true),'GC_FINITE_NORMAL_OBSERVATION_PHASE');if($argv[1]==='before')mg_before();else mg_observe();echo m86_json(['status'=>'NORMAL_GC_OBSERVATION_PHASE_COMPLETE','phase'=>$argv[1],'full_T4_acceptance'=>'INCOMPLETE'])."\n";exit(0);}
catch(Throwable$error){echo m86_json(['status'=>'NORMAL_GC_OBSERVATION_STOPPED','phase'=>$argv[1]??'','failure'=>m86_safe_error($error),'full_T4_acceptance'=>'INCOMPLETE'])."\n";exit(1);}
