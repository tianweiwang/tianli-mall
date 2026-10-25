<?php
declare(strict_types=1);
/** Ordinary synthetic withdrawal-material HTTP only. Importing executes no App, fixture, server or test. */
require_once __DIR__.'/lib/harness.php';
const M4WQ_ROOT='/run/audit-customer-withdrawal-qr';
const M4WQ_PRIVATE='/run/audit-fixtures/ms4-withdrawal-qr-private.json';
const M4WQ_FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
    'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
    'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
    'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id','source_qrcode_id',
    'created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
const M4WQ_EXTRACT_FIELDS=['id','wechat_order_id','uid','real_name','user_name','extract_type','bank_code','bank_address','alipay_code',
    'extract_price','extract_fee','mark','balance','fail_msg','fail_time','add_time','status','wechat','qrcode_url','channel_type',
    'out_bill_no','transfer_bill_no','state','package_info','fail_reason'];
const M4WQ_BROKERAGE_FIELDS=['id','uid','link_id','type','title','number','balance','pm','mark','status','take','frozen_time','add_time'];
const M4WQ_MESSAGE_FIELDS=['id','mark','uid','title','content','data','look','type','add_time','is_del'];
const M4WQ_ROWS=['eb_user','eb_user_extract','eb_user_brokerage','eb_marketplace_customer_asset','eb_system_log',
    'eb_message_system','eb_system_notification','eb_store_service','eb_system_event','eb_system_config'];

function m4wq_expect(bool $ok,string $code):void
{
    if(preg_match('/\AM4WQ_[A-Z0-9_]+\z/D',$code)!==1)throw new RuntimeException('M4WQ_INVALID_ASSERTION_CODE');
    $GLOBALS['m4wq_assertions']=($GLOBALS['m4wq_assertions']??0)+1;
    if(!$ok)throw new RuntimeException($code);
}

function m4wq_write(string $path,string $bytes):void
{
    m4wq_expect(!file_exists($path)&&!is_link($path),'M4WQ_CREATE_ONLY_OUTPUT');
    $h=fopen($path,'xb');m4wq_expect(is_resource($h),'M4WQ_OUTPUT_OPEN');
    try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($h,substr($bytes,$offset));m4wq_expect(is_int($n)&&$n>0,'M4WQ_FULL_OUTPUT_WRITE');$offset+=$n;}
        m4wq_expect(fflush($h),'M4WQ_OUTPUT_FLUSH');}finally{fclose($h);}
    m4wq_expect(chmod($path,0600)&&file_get_contents($path)===$bytes,'M4WQ_OUTPUT_MODE_AND_READBACK');
}

function m4wq_json(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}

function m4wq_rows(PDO $db,string $sql,array $arguments=[]):array
{
    $s=$db->prepare($sql);m4wq_expect($s instanceof PDOStatement&&$s->execute($arguments),'M4WQ_NATIVE_QUERY');
    try{$rows=$s->fetchAll(PDO::FETCH_ASSOC);m4wq_expect(is_array($rows)&&array_is_list($rows),'M4WQ_NATIVE_ROWS');return $rows;}
    finally{m4wq_expect($s->closeCursor(),'M4WQ_NATIVE_CURSOR_CLOSE');}
}

function m4wq_database(PDO $db):array
{
    static $firstCountersRecorded=false;
    m4wq_expect(!$db->inTransaction(),'M4WQ_INDEPENDENT_OBSERVER_OUTSIDE_TRANSACTION');
    m4wq_expect(in_array($db->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn(),[0,'0'],true),
        'M4WQ_OBSERVER_METADATA_EXPIRY_ZERO');
    $requestsBefore=$GLOBALS['m4wq_requests']??0;
    $tables=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out=['hashes'=>[],'rows'=>[],'ddl'=>[],'next'=>[]];$initialNext=[];
    foreach($tables as$name=>$next){
        m4wq_expect(preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D',$name)===1,'M4WQ_COMPLETE_KNOWN_TABLE_NAMESPACE');
        $rows=m4wq_rows($db,'SELECT * FROM `'.$name.'`');$serialized=array_map('serialize',$rows);sort($serialized,SORT_STRING);
        $out['hashes'][$name]=hash('sha256',serialize($serialized));
        $out['rows'][$name]=$rows;
        $create=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);
        m4wq_expect(is_array($create)&&is_string($create[1]??null),'M4WQ_COMPLETE_NATIVE_DDL');
        $out['ddl'][$name]=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$create[1]);$initialNext[$name]=$next===null?null:(string)$next;
        // Keep every native row in out; only this table's finished scratch references are no longer needed.
        unset($serialized,$rows,$create);
    }
    $fresh=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    m4wq_expect(array_keys($fresh)===array_keys($tables),'M4WQ_COUNTER_TABLE_NAMESPACE_UNCHANGED');
    foreach($fresh as$name=>$next)$out['next'][$name]=$next===null?null:(string)$next;
    foreach(M4WQ_ROWS as$name)m4wq_expect(isset($out['rows'][$name]),'M4WQ_REQUIRED_ORIGINAL_TABLES');
    if(!$firstCountersRecorded){
        $requestsAfter=$GLOBALS['m4wq_requests']??0;
        m4wq_expect(is_int($requestsBefore)&&$requestsBefore>=0&&$requestsAfter===$requestsBefore,'M4WQ_FIRST_SNAPSHOT_NO_HTTP_BETWEEN_COUNTER_MAPS');
        $manifest=audit_manifest();
        m4wq_write(audit_run_dir().'/evidence/ms4-withdrawal-qr-private-first-snapshot-counters.json',m4wq_json([
            'scope'=>'CU_WITHDRAWAL_QR_FIRST_OBSERVER_COUNTER_MAPS','run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],
            'business_HTTP_between_maps'=>0,'normal_http_request_count_before'=>$requestsBefore,'normal_http_request_count_after'=>$requestsAfter,
            'before_table_reads'=>$initialNext,'after_table_reads'=>$out['next']]));
        $firstCountersRecorded=true;
    }
    return $out;
}

function m4wq_redis():array
{
    $redis=audit_redis();$clock=$redis->time();$start=(int)$clock[0]*1000+intdiv((int)$clock[1],1000);
    $names=$redis->keys('*');sort($names,SORT_STRING);$out=[];
    foreach($names as$key){$type=$redis->type($key);$value=match($type){Redis::REDIS_STRING=>$redis->get($key),Redis::REDIS_HASH=>$redis->hGetAll($key),
        Redis::REDIS_SET=>$redis->sMembers($key),Redis::REDIS_LIST=>$redis->lRange($key,0,-1),
            Redis::REDIS_ZSET=>$redis->zRange($key,0,-1,true),Redis::REDIS_STREAM=>$redis->xRange($key,'-','+'),default=>null};
        m4wq_expect($value!==null&&$value!==false,'M4WQ_ALL_REAL_REDIS_TYPES');
        if($type===Redis::REDIS_HASH)ksort($value,SORT_STRING);if($type===Redis::REDIS_SET)sort($value,SORT_STRING);
        $expires=$redis->rawCommand('PEXPIRETIME',$key);m4wq_expect(is_int($expires)&&($expires===-1||$expires>0),'M4WQ_REDIS_ABSOLUTE_EXPIRY');
        $out[$key]=['type'=>$type,'value'=>$value,'expires'=>$expires];
    }$clock=$redis->time();$end=(int)$clock[0]*1000+intdiv((int)$clock[1],1000);
    m4wq_expect($end>=$start,'M4WQ_REAL_REDIS_OBSERVER_CLOCK');return ['entries'=>$out,'start_ms'=>$start,'end_ms'=>$end];
}

function m4wq_files(string $root,bool $private=false):array
{
    m4wq_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'M4WQ_REAL_FILE_ROOT');$files=[];$dirs=[];$owner=posix_geteuid();
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){m4wq_expect(!$file->isLink(),'M4WQ_NO_FILE_LINKS');$path=$file->getPathname();$relative=substr($path,strlen($root)+1);$stat=lstat($path);
        if($file->isDir()){$dirs[$relative]=[$stat['dev'],$stat['ino'],$stat['mode']&0777,$stat['uid']];
            if($private)m4wq_expect(($stat['mode']&0777)===0700&&$stat['uid']===$owner,'M4WQ_PRIVATE_PARENT_MODE_OWNER');continue;}
        m4wq_expect($file->isFile()&&is_array($stat),'M4WQ_REGULAR_OBSERVED_FILE');
        if($private)m4wq_expect(($stat['mode']&0777)===0600&&$stat['uid']===$owner&&$stat['nlink']===1,'M4WQ_PRIVATE_FILE_MODE_OWNER');
        $files[$relative]=['bytes'=>$stat['size'],'sha256'=>hash_file('sha256',$path),'mode'=>$stat['mode']&0777,'uid'=>$stat['uid'],'nlink'=>$stat['nlink']];
    }ksort($files,SORT_STRING);ksort($dirs,SORT_STRING);return ['files'=>$files,'directories'=>$dirs];
}

function m4wq_state(PDO $db):array
{
    $redis=m4wq_redis();return ['database'=>m4wq_database($db),'redis'=>$redis['entries'],'redis_clock'=>[$redis['start_ms'],$redis['end_ms']],'private'=>m4wq_files(M4WQ_ROOT,true),
        'public'=>m4wq_files(audit_repo_dir().'/crmeb/public'),'runtime'=>m4wq_files('/run/audit-app-runtime')];
}

function m4wq_sorted(array $rows):array {$out=array_map('serialize',$rows);sort($out,SORT_STRING);return $out;}

function m4wq_number(PDO $db,int|string $number):int|string {return $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)?(string)$number:(int)$number;}

function m4wq_window(mixed $value,array $reply):void
{m4wq_expect((is_int($value)||is_string($value))&&ctype_digit((string)$value)&&(int)$value>=$reply['start']&&(int)$value<=$reply['end'],'M4WQ_REAL_HTTP_TIMESTAMP_WINDOW');}

function m4wq_http(string $method,string $path,?string $token=null,?array $body=null,?array $image=null):array
{
    m4wq_expect(in_array($method,['GET','POST'],true)&&str_starts_with($path,'/')&&!str_contains($path,'://'),'M4WQ_FIXED_NORMAL_HTTP_PATH');
    $headers=['Cb-lang'=>'zh-CN'];if($token!==null)$headers['Authori-zation']='Bearer '.$token;
    $start=time();$startMs=(int)floor(microtime(true)*1000);$responseHeaders=[];
    $curl=curl_init('http://audit-app:8000'.$path);$lines=[];foreach($headers as$key=>$value)$lines[]=$key.': '.$value;
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$responseHeaders):int{
            if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($key))]=trim($value);}return strlen($line);}]);
    if($image===null){if($body!==null){$lines[]='Content-Type: application/json';curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}}
    else{
        m4wq_expect($method==='POST'&&$body===null&&is_file($image['path'])&&hash_file('sha256',$image['path'])===$image['sha256'],'M4WQ_REAL_MULTIPART_FIXTURE');
        curl_setopt($curl,CURLOPT_POSTFIELDS,['filename'=>'pics','pics'=>new CURLFile($image['path'],'image/png',$image['name'])]);
    }
    curl_setopt($curl,CURLOPT_HTTPHEADER,$lines);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    m4wq_expect(is_string($raw)&&$errno===0,'M4WQ_NATIVE_REAL_HTTP_RETURNED');$decoded=json_decode($raw,true);
    $reply=['http'=>$status,'raw'=>$raw,'json'=>is_array($decoded)?$decoded:null,'headers'=>$responseHeaders];
    $reply['start']=$start;$reply['end']=time();$reply['start_ms']=$startMs;$reply['end_ms']=(int)floor(microtime(true)*1000);
    $GLOBALS['m4wq_requests']=($GLOBALS['m4wq_requests']??0)+1;return $reply;
}

function m4wq_success(array $reply,bool $data=true):mixed
{
    $body=$reply['json'];m4wq_expect($reply['http']===200&&is_array($body)&&($body['status']??null)===200&&is_string($body['msg']??null),'M4WQ_REAL_NORMAL_HTTP_SUCCESS');
    if($data)m4wq_expect(array_key_exists('data',$body)&&is_array($body['data']),'M4WQ_REQUIRED_DATA_DTO');return $body['data']??null;
}

function m4wq_private_response(array $reply):void
{
    $headers=$reply['headers'];m4wq_expect(str_contains(strtolower($headers['cache-control']??''),'private')
        &&str_contains(strtolower($headers['cache-control']??''),'no-store')&&($headers['pragma']??null)==='no-cache'
        &&($headers['expires']??null)==='0','M4WQ_REAL_PRIVATE_NO_STORE_PUBLICATION');
}

function m4wq_content(string $path,string $token,array $image,?string $ticket=null):array
{
    $headers=[];$curl=curl_init('http://audit-app:8000'.$path);$start=time();$startMs=(int)floor(microtime(true)*1000);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>array_merge(['Authori-zation: Bearer '.$token,'Cb-lang: zh-CN'],$ticket===null?[]:['X-Withdrawal-Qr-Read-Ticket: '.$ticket]),CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int{
            if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}return strlen($line);}]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    m4wq_expect($errno===0&&$status===200&&is_string($raw)&&strlen($raw)===$image['bytes']&&hash('sha256',$raw)===$image['sha256']
        &&($headers['content-type']??null)==='image/png'&&($headers['content-length']??null)===(string)$image['bytes']
        &&($headers['x-content-type-options']??null)==='nosniff'&&str_contains(strtolower($headers['cache-control']??''),'no-store'),'M4WQ_REAL_AUTHENTICATED_ORIGINAL_IMAGE');
    $GLOBALS['m4wq_requests']=($GLOBALS['m4wq_requests']??0)+1;return ['start'=>$start,'end'=>time(),'start_ms'=>$startMs,'end_ms'=>(int)floor(microtime(true)*1000)];
}

function m4wq_transition(PDO $db,array $before,array $after,array $fixture,array $reply,string $actor,array $changes=[],array $increments=[],?array $newFile=null,bool $upload=false,array $redisAdds=[],array $redisRemoves=[]):void
{
    $b=$before['database'];$a=$after['database'];m4wq_expect(array_keys($a['hashes'])===array_keys($b['hashes'])&&$a['ddl']===$b['ddl'],'M4WQ_ALL_TABLES_AND_DDL_RETAINED');
    $expected=$b['rows'];foreach($changes as$table=>$rows)$expected[$table]=$rows;
    if($actor==='customer'){
        $seen=0;foreach($expected['eb_user']as&$row)if((string)$row['uid']===(string)$fixture['customer']['id']){
            $matches=array_values(array_filter($a['rows']['eb_user'],fn(array $r):bool=>(string)$r['uid']===(string)$row['uid']));
            m4wq_expect(count($matches)===1,'M4WQ_EXACT_OWN_AUTH_ROW');m4wq_window($matches[0]['last_time'],$reply);$row['last_time']=$matches[0]['last_time'];$seen++;
        }unset($row);m4wq_expect($seen===1,'M4WQ_ONLY_REAL_CUSTOMER_AUTH_TIMESTAMP');
    }
    foreach($a['hashes']as$table=>$hash){
        if(isset($expected[$table])){
            $actualRows=m4wq_sorted($a['rows'][$table]);$expectedRows=m4wq_sorted($expected[$table]);$same=$actualRows===$expectedRows;
            try{
                if(!$same){
                    $orders=static function(array $rows):array{
                        $seen=[];foreach($rows as$row){$keys=array_keys($row);$seen[json_encode($keys,JSON_THROW_ON_ERROR)]=$keys;}return array_values($seen);
                    };
                    m4wq_write(audit_run_dir().'/evidence/ms4-withdrawal-qr-private-row-failure.json',m4wq_json([
                        'scope'=>'CU_WITHDRAWAL_QR_ROW_FAILURE_DIAGNOSTIC_ONLY','status'=>'ORIGINAL_ROW_ASSERTION_FAILED',
                        'run_id'=>$fixture['run_id'],'source_sha'=>$fixture['source_sha'],'actor'=>$actor,'table'=>$table,
                        'expected_count'=>count($expectedRows),'actual_count'=>count($actualRows),
                        'expected_column_orders'=>$orders($expected[$table]),'actual_column_orders'=>$orders($a['rows'][$table]),
                        'expected_multiset_sha256'=>hash('sha256',serialize($expectedRows)),
                        'actual_multiset_sha256'=>hash('sha256',serialize($actualRows)),'raw_row_values_omitted'=>true]));
                }
            }finally{m4wq_expect($same,'M4WQ_COMPLETE_EXPECTED_NATIVE_ROWS');}
        }else m4wq_expect($hash===$b['hashes'][$table],'M4WQ_OTHER_BUSINESS_EVENTS_AND_FINANCIAL_TABLES_UNCHANGED');
        $wanted=$b['next'][$table];if(isset($increments[$table]))$wanted=(string)((int)$wanted+$increments[$table]);
        try{
            if($a['next'][$table]!==$wanted){
                m4wq_write(audit_run_dir().'/evidence/ms4-withdrawal-qr-private-counter-failure.json',m4wq_json([
                    'scope'=>'CU_WITHDRAWAL_QR_NATIVE_COUNTER_FAILURE_ONLY','status'=>'ORIGINAL_COUNTER_ASSERTION_FAILED',
                    'run_id'=>$fixture['run_id'],'source_sha'=>$fixture['source_sha'],'actor'=>$actor,
                    'first_mismatch'=>['table'=>$table,'before'=>$b['next'][$table],'wanted'=>$wanted,'actual'=>$a['next'][$table]],
                    'increments'=>$increments]));
            }
        }finally{
            m4wq_expect($a['next'][$table]===$wanted,'M4WQ_ONLY_EXPECTED_NATIVE_COUNTER_ADVANCE');
        }
    }
    $redis=$before['redis'];
    if($upload){
        $key=$fixture['prefix'].'start_uploads_'.$fixture['customer']['id'];$tag=$fixture['prefix'].'CRMEB:'.md5('crmeb');$count=isset($redis[$key])?(int)$redis[$key]['value']:0;
        $new=$after['redis'][$key]??null;m4wq_expect(is_array($new)&&$new['type']===Redis::REDIS_STRING&&$new['value']===(string)($count+1)
            &&$new['expires']>=$reply['start']*1000+86400000&&$new['expires']<=($reply['end']+1)*1000+86400000,'M4WQ_EXACT_UPLOAD_QUOTA_VALUE_AND_DEADLINE');
        $redis[$key]=$new;$members=$redis[$tag]['value']??[];$members[]=$key;$members=array_values(array_unique($members));sort($members,SORT_STRING);
        $redis[$tag]=['type'=>Redis::REDIS_SET,'value'=>$members,'expires'=>-1];ksort($redis,SORT_STRING);
    }
    foreach($redisRemoves as$key){m4wq_expect(array_key_exists($key,$redis),'M4WQ_EXACT_EXISTING_REDIS_REMOVAL');unset($redis[$key]);}
    foreach($redisAdds as$key=>$value){m4wq_expect(!array_key_exists($key,$redis),'M4WQ_EXACT_NEW_REDIS_VALUE');$redis[$key]=$value;}
    // Each absent old key needs its own previously observed absolute expiry and an actual Redis clock.
    foreach($redis as$key=>$value)if(!array_key_exists($key,$after['redis'])&&$value['expires']!==-1){
        m4wq_expect($value['expires']<=$after['redis_clock'][1],'M4WQ_EXACT_KEY_NATURAL_EXPIRY_OBSERVED');unset($redis[$key]);
    }ksort($redis,SORT_STRING);
    m4wq_expect($after['redis']===$redis,'M4WQ_ALL_REDIS_TYPES_VALUES_ABSOLUTE_EXPIRIES');
    m4wq_expect($after['public']===$before['public']&&$after['runtime']===$before['runtime'],'M4WQ_PUBLIC_AND_RUNTIME_FILES_UNCHANGED');
    $files=$before['private']['files'];$directories=$before['private']['directories'];
    if($newFile!==null){
        $key=$newFile['key'];m4wq_expect(!isset($files[$key]),'M4WQ_NEW_PRIVATE_OBJECT_ONLY');$files[$key]=$newFile['expected'];ksort($files,SORT_STRING);
        $path=dirname($key);$parents=[];while($path!=='.'){$parents[]=$path;$path=dirname($path);}
        foreach($parents as$path)if(!isset($directories[$path])){m4wq_expect(isset($after['private']['directories'][$path]),'M4WQ_ACTUAL_NEW_PRIVATE_PARENT');$directories[$path]=$after['private']['directories'][$path];}
        ksort($directories,SORT_STRING);
    }
    m4wq_expect($after['private']===['files'=>$files,'directories'=>$directories],'M4WQ_ALL_PRIVATE_OBJECTS_AND_PARENTS_EXPECTED');
}

function m4wq_guard():array
{
    $manifest=audit_manifest();
    m4wq_expect(getenv('AUDIT_WITHDRAWAL_QR_NORMAL')==='1'&&($manifest['guard_mode']??null)==='read-only','M4WQ_NORMAL_ONLY_PROFILE');
    audit_guard_assert();audit_assert_withdrawal_qr_private_mount();
    m4wq_expect(($manifest['isolation']??null)==='ISO-FULL'&&($manifest['commit_sha']??null)===getenv('AUDIT_SOURCE_SHA')
        &&preg_match('/\A[a-f0-9]{40}\z/D',(string)getenv('AUDIT_SOURCE_SHA'))===1,'M4WQ_CURRENT_COMMITTED_SOURCE');
    m4wq_expect(PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE===8&&function_exists('posix_geteuid'),'M4WQ_REAL_PHP82_RUNTIME');
    m4wq_expect(!file_exists(audit_repo_dir().'/crmeb/.env')&&!is_link(audit_repo_dir().'/crmeb/.env'),'M4WQ_NO_SHARED_ENV');
    return $manifest;
}

function m4wq_one(PDO $db,string $table,string $pk,int|string $id):array
{
    m4wq_expect(in_array($table,['eb_user','eb_system_admin','eb_user_extract','eb_user_brokerage','eb_marketplace_customer_asset'],true)&&in_array($pk,['uid','id'],true),'M4WQ_FIXED_ROW');
    $rows=m4wq_rows($db,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'`=?',[$id]);m4wq_expect(count($rows)===1,'M4WQ_ONE_NATIVE_ROW');return $rows[0];
}

function m4wq_app(string $prefix):\think\App
{
    m4wq_expect(empty($GLOBALS['m4wq_app']),'M4WQ_ONE_FIXTURE_APP');$GLOBALS['m4wq_app']=true;
    require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
    require_once audit_repo_dir().'/crmeb/vendor/topthink/framework/src/helper.php';
    $config=audit_config();$app=new \think\App(audit_repo_dir().'/crmeb/');$runtime='/tmp/ms4-withdrawal-qr-'.bin2hex(random_bytes(8)).'/';
    m4wq_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4WQ_OWN_FIXTURE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis',
        'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer'],
        'MARKETPLACE_CUSTOMER_WITHDRAWAL_QR_PRIVATE_ROOT'=>M4WQ_ROOT]);
    $app->initialize();m4wq_expect($app->initialized()&&app()===$app&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('marketplace.customer_withdrawal_qr.private_root')===M4WQ_ROOT
        &&$app->config->get('marketplace.enabled')===false&&$app->config->get('marketplace.trade.enabled')===false,'M4WQ_REAL_APP_FIXED_CONFIGURATION');
    date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));return $app;
}

function m4wq_migrations():array
{
    m4wq_expect(is_file('/run/audit-bootstrap/migrator.json'),'M4WQ_BOOTSTRAP_ONLY_MIGRATION');
    $credential=json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'),true,16,JSON_THROW_ON_ERROR);
    m4wq_expect(($credential['username']??null)==='audit_migrator','M4WQ_ONLY_DEDICATED_MIGRATOR');$pdo=audit_pdo($credential);
    foreach(['marketplace-ms1.php','marketplace-ms2.php','marketplace-ms4.php','marketplace-ms4-assets.php']as$file)
        require_once audit_repo_dir().'/help/migrations/'.$file;
    $one=new \Audit\Migration\MarketplaceMs1Migration($pdo,'eb_');$out=[];
    $out['ms1']=[$one->check(),$one->apply(),$one->check(),$one->apply()];
    m4wq_expect($out['ms1'][2]['state']==='completed'&&$out['ms1'][3]['state']==='completed'&&$out['ms1'][3]['changed']===false,'M4WQ_ORIGINAL_MS1_CHECK_APPLY_REPEAT');
    $state='/run/audit-fixtures/ms4-withdrawal-qr-ms2-state.json';m4wq_expect(!file_exists($state)&&!is_link($state),'M4WQ_NEW_PRIVATE_MS2_STATE');
    $two=new \Audit\Migration\MarketplaceMs2Migration($pdo,$one,'eb_',audit_repo_dir(),['enabled'=>false],$state);
    $out['ms2']=[$two->check(),$two->applyStructure(),$two->check(),$two->applyStructure()];
    m4wq_expect($out['ms2'][2]['state']==='completed'&&$out['ms2'][2]['native_exit']===0&&$out['ms2'][2]['enabled']===false
        &&$out['ms2'][2]['migration_complete']===false&&$out['ms2'][3]['native_exit']===0&&$out['ms2'][3]['changed']===false,'M4WQ_MS2_STRUCTURE_IS_NOT_HISTORY_MAPPING');
    foreach(['ms4'=>new MarketplaceMs4Migration($pdo,'eb_','audit_auth'),'assets'=>new MarketplaceMs4AssetMigration($pdo,'eb_','audit_auth')]as$name=>$migration){
        $out[$name]=[$migration->check(),$migration->applyStructure(),$migration->check(),$migration->applyStructure()];
        m4wq_expect($out[$name][2]['state']==='completed'&&$out[$name][2]['native_exit']===0
            &&$out[$name][3]['native_exit']===0&&$out[$name][3]['changed']===false,'M4WQ_ORIGINAL_STRUCTURE_CHECK_APPLY_REPEAT');
    }
    m4wq_write(audit_run_dir().'/evidence/ms4-withdrawal-qr-migration-method-receipts.json',m4wq_json([
        'scope'=>'CU_WITHDRAWAL_QR_NEW_ISOLATED_PREREQUISITE_METHOD_RECEIPTS_ONLY','receipts'=>$out,
        'native_CLI_not_claimed'=>true,'history_mapping'=>'NOT RUN','financial_channel'=>'NOT RUN']));return $out;
}

function m4wq_find(array $rows,string $key,int|string $id):array
{
    $matches=array_values(array_filter($rows,fn(array $row):bool=>(string)$row[$key]===(string)$id));
    m4wq_expect(count($matches)===1,'M4WQ_EXACT_ONE_OBSERVED_ROW');return $matches[0];
}

function m4wq_replace(array $rows,string $key,int|string $id,array $expected):array
{
    $count=0;foreach($rows as&$row)if((string)$row[$key]===(string)$id){$row=$expected;$count++;}unset($row);
    m4wq_expect($count===1,'M4WQ_REPLACE_EXACT_EXISTING_EXPECTED_ROW');return $rows;
}

function m4wq_log_delta(PDO $db,array $before,array $after,array $fixture,array $reply,string $rule):array
{
    $id=$before['database']['next']['eb_system_log'];m4wq_expect(is_string($id)&&ctype_digit($id),'M4WQ_REAL_LOG_NATIVE_NEXT');
    $row=m4wq_find($after['database']['rows']['eb_system_log'],'id',$id);
    $names=m4wq_rows($db,'SELECT menu_name FROM eb_system_menus WHERE api_url=? LIMIT 1',[$rule]);$name=$names[0]['menu_name']??'未知';
    m4wq_window($row['add_time'],$reply);$expected=['id'=>m4wq_number($db,$id),'admin_id'=>m4wq_number($db,$fixture['admin']['id']),
        'admin_name'=>$fixture['admin']['account'],'path'=>$rule,'page'=>$name?:'未知','method'=>'adminapi','ip'=>gethostbyname(gethostname()),
        'type'=>'system','add_time'=>$row['add_time'],'merchant_id'=>m4wq_number($db,0)];
    m4wq_expect(count($row)===count($expected)&&array_diff_key($row,$expected)===[],'M4WQ_ALL_ORIGINAL_PLATFORM_LOG_COLUMNS');
    m4wq_expect(array_keys($row)===array_keys($expected),'M4WQ_NATIVE_PLATFORM_LOG_COLUMN_ORDER');
    foreach($expected as$key=>$value)m4wq_expect($row[$key]===$value,'M4WQ_ALL_ORIGINAL_PLATFORM_LOG_VALUES');
    $rows=$before['database']['rows']['eb_system_log'];$rows[]=$expected;
    return [['eb_system_log'=>$rows],['eb_system_log'=>1]];
}

function m4wq_notice_recipients(array $rows):array
{
    $out=[];
    foreach($rows as$row)if(in_array($row['status'],[1,'1'],true)&&in_array($row['notify'],[1,'1'],true)){
        m4wq_expect(isset($row['uid'])&&ctype_digit((string)$row['uid'])&&(int)$row['uid']>0,'M4WQ_REAL_NOTICE_RECIPIENT_UID');
        $out[]=['nickname'=>$row['nickname'],'phone'=>$row['phone'],'uid'=>$row['uid'],'customer'=>$row['customer']];
    }
    return $out;
}

function m4wq_notice_delta(PDO $db,array $before,array $after,array $reply,array $intent):array
{
    $expected=$before['database']['rows']['eb_message_system'];$templates=$intent['templates'];
    m4wq_expect(count($templates)>0&&count($intent['ids'])===count($templates),'M4WQ_NONEMPTY_REAL_NOTICE_INTENT');
    foreach($intent['ids']as$id){
        $row=m4wq_find($after['database']['rows']['eb_message_system'],'id',$id);
        m4wq_expect(array_keys($row)===M4WQ_MESSAGE_FIELDS,'M4WQ_FULL10_MESSAGE_NATIVE_ROW');m4wq_window($row['add_time'],$reply);
        $values=$row;unset($values['id'],$values['add_time']);$match=null;
        foreach($templates as$key=>$template)if($values===$template){$match=$key;break;}
        m4wq_expect($match!==null,'M4WQ_MESSAGE_MATCHES_PRE_HTTP_RECIPIENT_AND_TEMPLATE');$template=$templates[$match];unset($templates[$match]);
        $wanted=['id'=>$id,'mark'=>$template['mark'],'uid'=>$template['uid'],'title'=>$template['title'],'content'=>$template['content'],
            'data'=>$template['data'],'look'=>$template['look'],'type'=>$template['type'],'add_time'=>$row['add_time'],'is_del'=>$template['is_del']];
        m4wq_expect($row===$wanted,'M4WQ_ALL10_INDEPENDENT_MESSAGE_VALUES');$expected[]=$wanted;
    }
    m4wq_expect($templates===[],'M4WQ_EVERY_ORIGINAL_NOTICE_RECIPIENT_PRESERVED');
    $GLOBALS['m4wq_notice_application_requests']=($GLOBALS['m4wq_notice_application_requests']??0)+1;
    $GLOBALS['m4wq_notice_rows']=($GLOBALS['m4wq_notice_rows']??0)+count($intent['ids']);
    return [$expected,count($intent['ids'])];
}

function m4wq_snowflake_delta(array $before,array $after,array $reply,string $number):array
{
    m4wq_expect(preg_match('/\A[1-9][0-9]{0,18}\z/D',$number)===1&&(int)$number>0,'M4WQ_ORIGINAL_REAL_CASH_SNOWFLAKE');
    $millis=((int)$number>>22)+(int)strtotime('2022-01-01')*1000;$sequence=(int)$number&4095;$key=(string)$millis;
    m4wq_expect($millis>=$reply['start_ms']&&$millis<=$reply['end_ms']&&$sequence===0&&!isset($before['redis'][$key]),'M4WQ_ONE_REAL_ORIGINAL_SNOWFLAKE_CONTEXT');
    if(isset($after['redis'][$key])){
        $entry=$after['redis'][$key];m4wq_expect($entry['type']===Redis::REDIS_STRING&&$entry['value']==='1'
            &&$entry['expires']>=$millis+1000&&$entry['expires']<=$reply['end_ms']+1000,'M4WQ_EXACT_ORIGINAL_SEQUENCE_KEY_VALUE_DEADLINE');return [$key=>$entry];
    }
    m4wq_expect($after['redis_clock'][0]>=$reply['end_ms']+1000,'M4WQ_SEQUENCE_ABSENCE_AFTER_PROVEN_WHOLE_DEADLINE_WINDOW');return [];
}

/** Original notice configuration and all eligible receivers are retained, including the real system message hook. */
function m4wq_prepare_notice(\think\App $app,PDO $db):array
{
    $columns=m4wq_rows($db,'SHOW FULL COLUMNS FROM eb_message_system');
    m4wq_expect(array_column($columns,'Field')===M4WQ_MESSAGE_FIELDS,'M4WQ_COMPLETE_ORIGINAL_MESSAGE_SCHEMA');
    $types=['id'=>'int(?:\(10\))?','mark'=>'varchar\(50\)','uid'=>'int(?:\(11\))? unsigned',
        'title'=>'varchar\(256\)','content'=>'varchar\(512\)','data'=>'varchar\(5000\)',
        'look'=>'tinyint(?:\(1\))?','type'=>'tinyint(?:\(1\))?','add_time'=>'int(?:\(11\))?','is_del'=>'tinyint(?:\(1\))?'];
    foreach($columns as$column){$name=$column['Field'];$numeric=in_array($name,['uid','look','type','add_time','is_del'],true);
        m4wq_expect(preg_match('~\A'.$types[$name].'\z~D',$column['Type'])===1&&$column['Null']==='NO'
            &&$column['Key']===($name==='id'?'PRI':'')&&$column['Extra']===($name==='id'?'auto_increment':'')
            &&($name==='id'?$column['Default']===null:($numeric?in_array($column['Default'],[0,'0'],true):$column['Default']==='')),
            'M4WQ_ALL_ORIGINAL_MESSAGE_TYPES_DEFAULTS_AND_PRIMARY');
    }
    $all=m4wq_rows($db,'SELECT * FROM eb_system_notification ORDER BY id');
    $matches=array_values(array_filter($all,fn(array $row):bool=>$row['mark']==='kefu_send_extract_application'));
    m4wq_expect(count($matches)===1,'M4WQ_ONE_ORIGINAL_CASH_NOTICE');$notice=$matches[0];
    // NoticeListener::handleKefuSendExtractApplication calls only SysMsg and WeWork, not SMS/Wechat/Routine.
    m4wq_expect(in_array($notice['is_system'],[1,'1'],true)&&in_array($notice['is_ent_wechat'],[1,'1'],true)&&$notice['url']===''
        &&is_string($notice['system_title'])&&is_string($notice['system_text']),'M4WQ_REAL_SYSTEM_NOTICE_AND_UNCONFIGURED_ENTERPRISE_WEBHOOK');
    $serviceRows=m4wq_rows($db,'SELECT * FROM eb_store_service ORDER BY id');$recipients=m4wq_notice_recipients($serviceRows);
    m4wq_expect($recipients!==[],'M4WQ_NONEMPTY_ORIGINAL_NOTICE_RECIPIENTS');
    $actual=$app->make(\app\services\kefu\service\StoreServiceServices::class)->getStoreServiceOrderNotice();
    m4wq_expect(m4wq_sorted($actual)===m4wq_sorted($recipients),'M4WQ_REAL_NOTICE_RECIPIENT_PRODUCER_MAPPING');
    foreach($recipients as$recipient)m4wq_one($db,'eb_user','uid',$recipient['uid']);
    $ddl=$db->query('SHOW CREATE TABLE eb_message_system')->fetch(PDO::FETCH_NUM);
    m4wq_expect(is_array($ddl)&&is_string($ddl[1]??null),'M4WQ_NATIVE_MESSAGE_DDL');
    return ['configuration'=>$notice,'recipients'=>$recipients,'notification_rows_sha256'=>hash('sha256',serialize(m4wq_sorted($all))),
        'store_service_sha256'=>hash('sha256',serialize(m4wq_sorted($serviceRows))),
        'message_schema'=>$columns,'message_ddl'=>preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl[1])];
}

function m4wq_prepare():array
{
    $manifest=m4wq_guard();$root=lstat(M4WQ_ROOT);
    m4wq_expect(is_array($root)&&!is_link(M4WQ_ROOT)&&realpath(M4WQ_ROOT)===M4WQ_ROOT&&($root['mode']&0170000)===0040000
        &&$root['uid']===posix_geteuid()&&scandir(M4WQ_ROOT)===['.','..'],'M4WQ_FRESH_OWN_PRIVATE_ROOT');
    m4wq_expect(chmod(M4WQ_ROOT,0700),'M4WQ_PRIVATE_ROOT_MODE');$migration=m4wq_migrations();$db=audit_db();
    m4wq_expect(m4wq_rows($db,'SELECT * FROM eb_marketplace_customer_asset')===[],'M4WQ_NEW_INSTANCE_NO_ASSETS');
    $accounts=json_decode(file_get_contents('/run/audit-fixtures/accounts.json'),true,32,JSON_THROW_ON_ERROR);
    m4wq_expect(isset($accounts['user_101'],$accounts['admin_super']),'M4WQ_ORIGINAL_DEDICATED_SYNTHETIC_ACCOUNTS');
    $customer=$accounts['user_101'];$admin=$accounts['admin_super'];
    $prefix='audit:http-lang:'.$manifest['run_id'].':'.(string)(int)floor(microtime(true)*1000).':';$app=m4wq_app($prefix);
    m4wq_expect((int)m4wq_one($db,'eb_system_admin','id',$admin['id'])['level']===0,'M4WQ_ORIGINAL_PLATFORM_SUPER');
    m4wq_expect(m4wq_rows($db,'SELECT * FROM eb_user_extract WHERE uid=?',[$customer['id']])===[]
        &&m4wq_rows($db,'SELECT * FROM eb_user_brokerage WHERE uid=?',[$customer['id']])===[],'M4WQ_NEW_SYNTHETIC_CUSTOMER_HAS_NO_FINANCIAL_HISTORY');
    $user=m4wq_one($db,'eb_user','uid',$customer['id']);
    m4wq_expect($app->make(\app\services\user\UserServices::class)->update((int)$customer['id'],['brokerage_price'=>'100.00'],'uid')!==false,
        'M4WQ_EXPLICIT_SYNTHETIC_BROKERAGE_FIXTURE_ONLY');
    $expectedUser=$user;$expectedUser['brokerage_price']='100.00';
    m4wq_expect(m4wq_one($db,'eb_user','uid',$customer['id'])===$expectedUser,'M4WQ_ONLY_SYNTHETIC_BROKERAGE_PREPARATION_DELTA');
    m4wq_expect(bccomp((string)sys_config('user_extract_min_price'),'1.23',2)<=0
        &&(int)sys_config('weixin_extract_type',0)===0&&(int)sys_config('alipay_extract_type',0)===0
        &&bccomp((string)sys_config('withdrawal_fee',0),'0',2)===0,'M4WQ_ORIGINAL_MANUAL_APPLICATION_RULES_NO_AUTO_PAYMENT');
    $allowed=$app->config->get('pay.extractType');
    m4wq_expect(is_array($allowed)&&in_array('weixin',$allowed,true)&&in_array('alipay',$allowed,true)
        &&bccomp((string)$app->make(\app\services\user\UserBrokerageServices::class)->getUserFrozenPrice((int)$customer['id']),'0',2)===0,
        'M4WQ_REAL_ALLOWED_TYPES_AND_UNFROZEN_SYNTHETIC_FUNDS');
    $channel=$app->config->get('workerman.channel');
    m4wq_expect($channel===['port'=>40003,'ip'=>'127.0.0.1'],'M4WQ_ORIGINAL_CHANNEL_LOOPBACK_ONLY');
    m4wq_expect(m4wq_rows($db,"SELECT id FROM eb_system_event WHERE mark IN ('user_login','admin_login','user_extract') AND is_del=0 AND is_open=1")===[],
        'M4WQ_NO_CONFIGURED_EXTERNAL_CUSTOM_EVENTS');
    foreach(m4wq_rows($db,"SELECT * FROM eb_system_notification WHERE custom_trigger='login_success'")as$row)
        foreach(['is_sms','is_wechat','is_routine','is_ent_wechat']as$key)m4wq_expect((int)$row[$key]===0,'M4WQ_ORIGINAL_EXTERNAL_LOGIN_NOTICE_DISABLED');
    $queue=m4wq_rows($db,"SELECT value FROM eb_system_config WHERE menu_name='queue_open'");
    m4wq_expect(count($queue)===1&&json_decode($queue[0]['value'],true,16,JSON_THROW_ON_ERROR)===0,'M4WQ_ORIGINAL_QUEUE_DISABLED');
    $notice=m4wq_prepare_notice($app,$db);
    m4wq_expect(array_column(m4wq_rows($db,'SHOW FULL COLUMNS FROM eb_user_extract'),'Field')===M4WQ_EXTRACT_FIELDS
        &&array_column(m4wq_rows($db,'SHOW FULL COLUMNS FROM eb_user_brokerage'),'Field')===M4WQ_BROKERAGE_FIELDS,'M4WQ_REAL_COMPLETE_NATIVE_FINANCIAL_SCHEMAS');
    m4wq_expect((int)$db->query('SELECT @@auto_increment_increment')->fetchColumn()===1,'M4WQ_NATIVE_INCREMENT_ONE');
    foreach(m4wq_rows($db,'SELECT menu_name FROM eb_system_config ORDER BY id')as$row)\crmeb\services\SystemConfigService::get($row['menu_name']);
    foreach(m4wq_rows($db,'SELECT mark FROM eb_system_notification ORDER BY id')as$row)$app->make(\app\services\message\SystemNotificationServices::class)->getOneNotce(['mark'=>$row['mark']]);
    getLang('操作成功');sys_data('admin_login_slide');
    $images=[];foreach(['a'=>[12,45,90],'b'=>[91,32,13]]as$name=>$rgb){
        $image=imagecreatetruecolor(16,16);m4wq_expect($image instanceof GdImage,'M4WQ_SYNTHETIC_PNG_CREATE');
        imagefill($image,0,0,imagecolorallocate($image,...$rgb));ob_start();m4wq_expect(imagepng($image),'M4WQ_SYNTHETIC_PNG_BYTES');$bytes=ob_get_clean();imagedestroy($image);
        m4wq_expect(is_string($bytes)&&$bytes!==''&&getimagesizefromstring($bytes)[0]===16,'M4WQ_REAL_PNG_DECODE');
        $path='/run/audit-fixtures/withdrawal-qr-'.$name.'.png';m4wq_write($path,$bytes);
        $images[$name]=['path'=>$path,'name'=>'withdrawal-qr-'.$name.'.png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'width'=>16,'height'=>16];
    }
    $legacy='/statics/system_images/admin_head_pic.png';m4wq_expect(is_file(audit_repo_dir().'/crmeb/public'.$legacy)&&!is_link(audit_repo_dir().'/crmeb/public'.$legacy),'M4WQ_ORIGINAL_LEGACY_PUBLIC_IMAGE');
    $fixture=['schema'=>1,'run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],'prefix'=>$prefix,'private_uid'=>posix_geteuid(),
        'customer'=>$customer,'admin'=>$admin,'images'=>$images,'legacy'=>$legacy,'cash_notice'=>$notice,'created_at'=>time()];
    m4wq_write(M4WQ_PRIVATE,m4wq_json($fixture));
    return ['scope'=>'CU_WITHDRAWAL_QR_PREPARATION_ONLY','status'=>'PREPARED_NOT_HTTP_VERIFIED','source_sha'=>$manifest['commit_sha'],
        'synthetic_brokerage'=>'100.00_NOT_REAL_FUNDS','notice_configuration_changed'=>false,'original_notice_receivers_retained'=>true,
        'business_HTTP'=>'NOT RUN','financial_channel'=>'NOT RUN','browser_device_CORS'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}

function m4wq_notice_intent(PDO $db,array $fixture,array $before,array $data):array
{
    $frozen=$fixture['cash_notice'];$notice=$frozen['configuration'];
    m4wq_expect(m4wq_find($before['database']['rows']['eb_system_notification'],'id',$notice['id'])===$notice
        &&hash('sha256',serialize(m4wq_sorted($before['database']['rows']['eb_system_notification'])))===$frozen['notification_rows_sha256']
        &&hash('sha256',serialize(m4wq_sorted($before['database']['rows']['eb_store_service'])))===$frozen['store_service_sha256']
        &&m4wq_sorted(m4wq_notice_recipients($before['database']['rows']['eb_store_service']))===m4wq_sorted($frozen['recipients'])
        &&$before['database']['ddl']['eb_message_system']===$frozen['message_ddl'],'M4WQ_FROZEN_ORIGINAL_NOTICE_AND_ALL_RECIPIENTS');
    $templates=[];
    foreach($frozen['recipients']as$recipient){$title=$notice['system_title'];$content=$notice['system_text'];
        foreach($data as$key=>$value){$title=str_replace('{'.$key.'}',$value,$title);$content=str_replace('{'.$key.'}',$value,$content);}
        $templates[]=['mark'=>$notice['mark'],'uid'=>m4wq_number($db,$recipient['uid']),'title'=>$title,'content'=>$content,
            'data'=>json_encode($data,JSON_THROW_ON_ERROR),'look'=>m4wq_number($db,0),'type'=>m4wq_number($db,2),'is_del'=>m4wq_number($db,0)];
    }
    $next=$before['database']['next']['eb_message_system'];m4wq_expect(is_string($next)&&ctype_digit($next)&&(int)$next>0
        &&(int)$next<=PHP_INT_MAX-count($templates),'M4WQ_REAL_MESSAGE_NATIVE_NEXT');$ids=[];
    foreach($templates as$index=>$unused)$ids[]=m4wq_number($db,(int)$next+$index);return ['ids'=>$ids,'templates'=>$templates];
}

function m4wq_upload(PDO $db,array $fixture,string $token,string $type,string $which):array
{
    m4wq_expect(in_array($type,['weixin','alipay'],true),'M4WQ_FIXED_ORIGINAL_UPLOAD_TYPE');$image=$fixture['images'][$which];$before=m4wq_state($db);
    $reply=m4wq_http('POST','/api/extract/files/'.$type,$token,null,$image);$data=m4wq_success($reply);m4wq_private_response($reply);$after=m4wq_state($db);
    $id=$before['database']['next']['eb_marketplace_customer_asset'];m4wq_expect(is_string($id)&&ctype_digit($id),'M4WQ_REAL_UPLOAD_NATIVE_NEXT');
    $row=m4wq_find($after['database']['rows']['eb_marketplace_customer_asset'],'id',$id);$uid=(int)$fixture['customer']['id'];
    m4wq_expect(array_keys($row)===M4WQ_FIELDS,'M4WQ_EXACT_FULL34_NEW_STAGED');$prefix='withdrawal-qr/v1/'.$uid.'/';
    m4wq_expect(preg_match('~\A'.preg_quote($prefix,'~').'[a-f0-9]{64}/content\.png\z~D',$row['object_key'])===1,'M4WQ_REAL_PRIVATE_UID_ONLY_NAMESPACE');
    m4wq_window($row['created_at'],$reply);$expected=array_fill_keys(M4WQ_FIELDS,null);
    $values=['id'=>$id,'uid'=>$uid,'purpose'=>'withdrawal_qr','state'=>'STAGED','storage_type'=>1,'object_key'=>$row['object_key'],'thumbnail_key'=>'',
        'name'=>'content.png','real_name'=>$image['name'],'media_type'=>'image/png','byte_size'=>$image['bytes'],'width'=>16,'height'=>16,
        'content_sha256'=>hex2bin($image['sha256']),'created_at'=>(int)$row['created_at'],'created_by_uid'=>$uid];
    foreach($values as$key=>$value)$expected[$key]=in_array($key,['id','uid','storage_type','byte_size','width','height','created_at','created_by_uid'],true)?m4wq_number($db,$value):$value;
    m4wq_expect($row===$expected,'M4WQ_ALL34_INDEPENDENT_UPLOAD_VALUES');$rows=$before['database']['rows']['eb_marketplace_customer_asset'];$rows[]=$expected;
    $file=['key'=>$row['object_key'],'expected'=>['bytes'=>$image['bytes'],'sha256'=>$image['sha256'],'mode'=>0600,'uid'=>$fixture['private_uid'],'nlink'=>1]];
    m4wq_transition($db,$before,$after,$fixture,$reply,'customer',['eb_marketplace_customer_asset'=>$rows],['eb_marketplace_customer_asset'=>1],$file,true);
    $reference='mp-withdrawal:v1:'.$id;
    m4wq_expect($data===['name'=>'content.png','url'=>'/api/extract/files/'.$id.'/content','attachment_ref'=>$reference]
        &&file_get_contents(M4WQ_ROOT.'/'.$row['object_key'])===file_get_contents($image['path']),'M4WQ_ORIGINAL_THREE_FIELD_DTO_AND_PRIVATE_BYTES');
    return ['id'=>$id,'reference'=>$reference,'row'=>$expected,'image'=>$image];
}

/** Each call is a new ordinary application, never a cash replay or a payment/approval invocation. */
function m4wq_cash(PDO $db,array $fixture,string $token,string $type,string $reference,?array $selected):array
{
    m4wq_expect(in_array($type,['weixin','alipay'],true),'M4WQ_ORIGINAL_REAL_CASH_TYPE');$before=m4wq_state($db);
    $user=m4wq_find($before['database']['rows']['eb_user'],'uid',$fixture['customer']['id']);
    $body=['alipay_code'=>$type==='alipay'?'isolated-manual-account':'','extract_type'=>$type,'money'=>'1.23',
        'user_name'=>'隔离收款人','name'=>'','bankname'=>'','cardnum'=>'','weixin'=>$type==='weixin'?'isolated-manual-account':'','qrcode_url'=>$reference];
    $notice=m4wq_notice_intent($db,$fixture,$before,['nickname'=>$user['nickname'],'money'=>'1.23']);
    $reply=m4wq_http('POST','/api/extract/cash',$token,$body);m4wq_success($reply,false);if($selected!==null)m4wq_private_response($reply);$after=m4wq_state($db);
    $id=$before['database']['next']['eb_user_extract'];$brokerId=$before['database']['next']['eb_user_brokerage'];
    m4wq_expect(is_string($id)&&ctype_digit($id)&&is_string($brokerId)&&ctype_digit($brokerId),'M4WQ_PRE_HTTP_NATIVE_FINANCIAL_NEXT');
    $row=m4wq_find($after['database']['rows']['eb_user_extract'],'id',$id);m4wq_window($row['add_time'],$reply);
    m4wq_expect(is_string($row['wechat_order_id'])&&str_starts_with($row['wechat_order_id'],'tx'),'M4WQ_ORIGINAL_TX_NUMBER_PREFIX');
    $expected=['id'=>m4wq_number($db,$id),'wechat_order_id'=>$row['wechat_order_id'],'uid'=>$user['uid'],
        'real_name'=>$body['user_name'],'user_name'=>$body['user_name'],'extract_type'=>$type,'bank_code'=>'','bank_address'=>'',
        'alipay_code'=>$body['alipay_code'],'extract_price'=>'1.23','extract_fee'=>'0.00','mark'=>'','balance'=>$user['brokerage_price'],
        'fail_msg'=>'','fail_time'=>m4wq_number($db,0),'add_time'=>$row['add_time'],'status'=>m4wq_number($db,0),'wechat'=>$body['weixin'],
        'qrcode_url'=>$reference,'channel_type'=>'','out_bill_no'=>'','transfer_bill_no'=>'','state'=>'','package_info'=>'','fail_reason'=>''];
    m4wq_expect(array_keys($row)===M4WQ_EXTRACT_FIELDS&&$row===$expected,'M4WQ_ALL25_INDEPENDENT_EXTRACT_VALUES_AND_ORIGINAL_DEFAULTS');
    $extracts=$before['database']['rows']['eb_user_extract'];$extracts[]=$expected;
    $balance=bcsub($user['brokerage_price'],'1.23',2);$wantedUser=$user;$wantedUser['brokerage_price']=$balance;
    $users=m4wq_replace($before['database']['rows']['eb_user'],'uid',$user['uid'],$wantedUser);
    $income=m4wq_find($after['database']['rows']['eb_user_brokerage'],'id',$brokerId);m4wq_window($income['add_time'],$reply);
    $wantedIncome=['id'=>m4wq_number($db,$brokerId),'uid'=>$user['uid'],'link_id'=>$id,'type'=>'extract','title'=>'佣金提现',
        'number'=>'1.23','balance'=>$balance,'pm'=>m4wq_number($db,0),'mark'=>($type==='weixin'?'使用微信提现':'使用支付宝提现').'1.23元',
        'status'=>m4wq_number($db,1),'take'=>m4wq_number($db,0),'frozen_time'=>m4wq_number($db,0),'add_time'=>$income['add_time']];
    m4wq_expect(array_keys($income)===M4WQ_BROKERAGE_FIELDS&&$income===$wantedIncome,'M4WQ_ALL13_ORIGINAL_REAL_BROKERAGE_AND_LINK_ID');
    $brokerages=$before['database']['rows']['eb_user_brokerage'];$brokerages[]=$wantedIncome;
    $assets=$before['database']['rows']['eb_marketplace_customer_asset'];
    if($selected!==null){
        m4wq_expect($reference===$selected['reference'],'M4WQ_ONLY_REAL_UPLOADED_REFERENCE_SELECTED');
        $prior=m4wq_find($assets,'id',$selected['id']);$actual=m4wq_find($after['database']['rows']['eb_marketplace_customer_asset'],'id',$selected['id']);$wanted=$prior;
        if($prior['state']==='STAGED'){
            m4wq_expect($prior['extract_id']===null&&$prior['bound_at']===null,'M4WQ_TRUE_FIRST_BINDING_BEFORE');m4wq_window($actual['bound_at'],$reply);
            $wanted['state']='BOUND';$wanted['extract_id']=m4wq_number($db,$id);$wanted['bound_at']=$actual['bound_at'];
        }else{
            m4wq_expect($prior['state']==='BOUND'&&(string)$prior['extract_id']!==$id,'M4WQ_REAL_DISTINCT_SECOND_APPLICATION_NOT_REPLAY');
            $first=m4wq_find($before['database']['rows']['eb_user_extract'],'id',$prior['extract_id']);
            m4wq_expect((string)$first['uid']===(string)$user['uid']&&$first['qrcode_url']===$reference,'M4WQ_FIRST_EXTRACT_REAL_SAME_UID_REFERENCE');
        }
        m4wq_expect(array_keys($actual)===M4WQ_FIELDS&&$actual===$wanted,'M4WQ_ALL34_FIRST_BIND_OR_UNCHANGED_REUSE');
        $assets=m4wq_replace($assets,'id',$selected['id'],$wanted);
    }
    [$messages,$count]=m4wq_notice_delta($db,$before,$after,$reply,$notice);
    $adds=m4wq_snowflake_delta($before,$after,$reply,substr($row['wechat_order_id'],2));
    m4wq_transition($db,$before,$after,$fixture,$reply,'customer',['eb_user'=>$users,'eb_user_extract'=>$extracts,
        'eb_user_brokerage'=>$brokerages,'eb_marketplace_customer_asset'=>$assets,'eb_message_system'=>$messages],
        ['eb_user_extract'=>1,'eb_user_brokerage'=>1,'eb_message_system'=>$count],null,false,$adds);
    return $expected;
}

function m4wq_read(PDO $db,array $fixture,string $token,array $asset,?array $extract,bool $platform):void
{
    $actor=$platform?'platform':'customer';$kind=$platform?'platform_list':'customer_preview';$extractId=$extract===null?null:(int)$extract['id'];
    if($platform)m4wq_expect($extract!==null&&$extract['qrcode_url']===$asset['reference'],'M4WQ_CURRENT_LIST_ROW_CONTEXT');
    $base=$platform?'/adminapi/finance/extract/'.$extractId.'/qr/'.$asset['id']:'/api/extract/files/'.$asset['id'];
    $rule=$platform?'finance/extract/<extract_id>/qr/<asset_id>':null;
    $before=m4wq_state($db);$reply=m4wq_http('POST',$base.'/read-ticket',$token,['schema'=>1]);$ticket=m4wq_success($reply);m4wq_private_response($reply);$after=m4wq_state($db);
    m4wq_expect(array_keys($ticket)===['read_ticket','issued_at_ms','expires_at_ms']&&is_string($ticket['read_ticket'])
        &&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket['read_ticket'])===1&&is_int($ticket['issued_at_ms'])&&is_int($ticket['expires_at_ms'])
        &&$ticket['issued_at_ms']>=$reply['start_ms']&&$ticket['issued_at_ms']<=$reply['end_ms']&&$ticket['expires_at_ms']===$ticket['issued_at_ms']+300000,
        'M4WQ_REAL_REDIS_TIME_SHORT_TICKET_DTO');
    $payload=['schema'=>1,'reader_kind'=>$kind,'app'=>$platform?'adminapi':'api','actor_type'=>$platform?'admin':'customer',
        'actor_id'=>(int)$fixture[$platform?'admin':'customer']['id'],'session_sha256'=>hash('sha256',$token),
        'uid'=>(int)$fixture['customer']['id'],'asset_id'=>$asset['id'],'content_sha256'=>$asset['image']['sha256'],
        'extract_id'=>$extractId,'purpose'=>'withdrawal_qr','issued_at_ms'=>$ticket['issued_at_ms'],'expires_at_ms'=>$ticket['expires_at_ms'],'scope'=>'withdrawal_qr_material_read'];
    ksort($payload,SORT_STRING);$raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $key=$fixture['prefix'].'mp_withdrawal_qr_read:'.hash('sha256',$ticket['read_ticket']);
    $addition=[$key=>['type'=>Redis::REDIS_STRING,'value'=>$raw,'expires'=>$ticket['expires_at_ms']]];
    [$changes,$increments]=$platform?m4wq_log_delta($db,$before,$after,$fixture,$reply,$rule.'/read-ticket'):[[],[]];
    m4wq_transition($db,$before,$after,$fixture,$reply,$actor,$changes,$increments,null,false,$addition);
    unset($before,$after,$changes,$increments);
    $before=m4wq_state($db);$reply=m4wq_content($base.'/content',$token,$asset['image'],$ticket['read_ticket']);$after=m4wq_state($db);
    [$changes,$increments]=$platform?m4wq_log_delta($db,$before,$after,$fixture,$reply,$rule.'/content'):[[],[]];
    m4wq_transition($db,$before,$after,$fixture,$reply,$actor,$changes,$increments,null,false,[],[$key]);
}

function m4wq_list(PDO $db,array $fixture,string $token,array $wanted):void
{
    $before=m4wq_state($db);$reply=m4wq_http('GET','/adminapi/finance/extract?page=1&limit=20',$token);$data=m4wq_success($reply);$after=m4wq_state($db);
    m4wq_expect(isset($data['list']['list'],$data['list']['count'])&&is_array($data['list']['list']),'M4WQ_REAL_ORIGINAL_FINANCE_LIST_DTO');
    foreach($wanted as$extract){$matches=array_values(array_filter($data['list']['list'],fn(array $row):bool=>(string)$row['id']===(string)$extract['id']));
        m4wq_expect(count($matches)===1,'M4WQ_SAVED_EXTRACT_REACHED_ORIGINAL_LIST');$row=$matches[0];
        foreach(M4WQ_EXTRACT_FIELDS as$key)m4wq_expect(array_key_exists($key,$row)&&$row[$key]===$extract[$key],'M4WQ_ALL25_ORIGINAL_LIST_FIELDS_AND_QR_REFERENCE');
    }
    m4wq_expect((int)$data['list']['count']===count($before['database']['rows']['eb_user_extract']),'M4WQ_ORIGINAL_LIST_COMPLETE_COUNT');
    [$changes,$increments]=m4wq_log_delta($db,$before,$after,$fixture,$reply,'finance/extract');
    m4wq_transition($db,$before,$after,$fixture,$reply,'platform',$changes,$increments);
}

function m4wq_legacy_image(PDO $db,array $fixture):void
{
    $before=m4wq_state($db);$url='http://audit-app:8000'.$fixture['legacy'];$bytes=file_get_contents(audit_repo_dir().'/crmeb/public'.$fixture['legacy']);$start=time();
    $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'']);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    m4wq_expect($errno===0&&$status===200&&is_string($raw)&&$raw===$bytes,'M4WQ_UNMODIFIED_LEGACY_URL_STILL_ORIGINAL_BYTES');
    $GLOBALS['m4wq_requests']=($GLOBALS['m4wq_requests']??0)+1;$after=m4wq_state($db);
    m4wq_transition($db,$before,$after,$fixture,['start'=>$start,'end'=>time()],'public');
}

function m4wq_verify():array
{
    $manifest=m4wq_guard();$memoryBefore=ini_get('memory_limit');
    m4wq_expect(PHP_SAPI==='cli'&&is_string($memoryBefore)&&ini_set('memory_limit','512M')!==false&&ini_get('memory_limit')==='512M','M4WQ_VERIFY_CLI_FIXED_MEMORY');
    $stat=lstat(M4WQ_PRIVATE);m4wq_expect(is_array($stat)&&($stat['mode']&0777)===0600&&$stat['uid']===posix_geteuid()&&!is_link(M4WQ_PRIVATE),'M4WQ_PRIVATE_CURRENT_FIXTURE_FILE');
    $fixture=json_decode(file_get_contents(M4WQ_PRIVATE),true,64,JSON_THROW_ON_ERROR);
    m4wq_expect(($fixture['schema']??null)===1&&($fixture['run_id']??null)===$manifest['run_id']&&($fixture['source_sha']??null)===$manifest['commit_sha']
        &&($fixture['private_uid']??null)===posix_geteuid()&&time()>=$fixture['created_at']&&time()-$fixture['created_at']<600,'M4WQ_CURRENT_RUN_SOURCE_OWNER_AND_FRESH_FIXTURE');
    m4wq_app($fixture['prefix']);m4wq_expect(ini_get('memory_limit')==='512M','M4WQ_VERIFY_CLI_MEMORY_AFTER_APP');$db=audit_db();
    m4wq_expect($db->exec('SET SESSION information_schema_stats_expiry=0')===0&&in_array($db->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn(),[0,'0'],true),'M4WQ_OBSERVER_FRESH_NATIVE_METADATA');
    $started=false;$closed=false;$error=null;$cases=[];
    try{
        $started=true;audit_app_start(['CACHE_PREFIX'=>$fixture['prefix'],'WITHDRAWAL_QR_PRIVATE_ROOT'=>M4WQ_ROOT]);
        $login=m4wq_success(m4wq_http('POST','/api/login',null,['account'=>$fixture['customer']['account'],'password'=>$fixture['customer']['password'],'spread'=>0,'agent_id'=>0]));
        $owner=$login['token']??null;m4wq_expect(is_string($owner)&&$owner!=='','M4WQ_REAL_CUSTOMER_PASSWORD_LOGIN');
        $claims=(array)\Firebase\JWT\JWT::decode($owner,audit_config()['signing_key'],['HS256']);
        m4wq_expect(($claims['jti']->type??null)==='api'&&(string)($claims['jti']->id??'')===(string)$fixture['customer']['id'],'M4WQ_TRUE_SIGNED_CUSTOMER_ACTOR');
        $login=m4wq_success(m4wq_http('POST','/adminapi/login',null,['account'=>$fixture['admin']['account'],'pwd'=>$fixture['admin']['password'],'key'=>'']));
        $admin=$login['token']??null;m4wq_expect(is_string($admin)&&$admin!=='','M4WQ_REAL_PLATFORM_PASSWORD_LOGIN');
        $claims=(array)\Firebase\JWT\JWT::decode($admin,audit_config()['signing_key'],['HS256']);
        m4wq_expect(($claims['auth_schema']??null)===2&&($claims['session_epoch']??null)===audit_config()['auth']['session_epoch']
            &&(string)($claims['jti']->id??'')===(string)$fixture['admin']['id'],'M4WQ_TRUE_SIGNED_V2_PLATFORM_ACTOR');
        // Real readers warm original caches before module observations; their login/warmup writes are not claimed absent.
        m4wq_success(m4wq_http('GET','/api/extract/bank',$owner));m4wq_success(m4wq_http('GET','/adminapi/finance/extract?page=1&limit=20',$admin));
        $saved=[];foreach(['weixin','alipay']as$type)$saved[]=m4wq_cash($db,$fixture,$owner,$type,'',null);
        m4wq_list($db,$fixture,$admin,$saved);$cases[]='both_original_types_optional_zero_image_no_new_material_row';
        $legacy='http://audit-app:8000'.$fixture['legacy'];foreach(['weixin','alipay']as$type)$saved[]=m4wq_cash($db,$fixture,$owner,$type,$legacy,null);
        m4wq_list($db,$fixture,$admin,$saved);m4wq_legacy_image($db,$fixture);$cases[]='both_legacy_url_new_writes_and_original_saved_list_static_bytes_preserved_not_private';
        $a=m4wq_upload($db,$fixture,$owner,'weixin','a');m4wq_read($db,$fixture,$owner,$a,null,false);
        $first=m4wq_cash($db,$fixture,$owner,'weixin',$a['reference'],$a);$saved[]=$first;$firstBound=m4wq_one($db,'eb_marketplace_customer_asset','id',$a['id']);
        m4wq_read($db,$fixture,$owner,$a,$first,false);m4wq_list($db,$fixture,$admin,$saved);m4wq_read($db,$fixture,$admin,$a,$first,true);
        $cases[]='private_weixin_upload_owner_staged_preview_original_cash_first_real_pk_bound_full34';
        $again=m4wq_cash($db,$fixture,$owner,'alipay',$a['reference'],$a);$saved[]=$again;
        m4wq_expect((string)$again['id']!==(string)$first['id']&&m4wq_one($db,'eb_marketplace_customer_asset','id',$a['id'])===$firstBound,'M4WQ_DISTINCT_LEGAL_CASH_REUSE_RETAINS_FIRST_EXTRACT_AND_BOUND_AT');
        m4wq_read($db,$fixture,$owner,$a,$first,false);m4wq_list($db,$fixture,$admin,$saved);m4wq_read($db,$fixture,$admin,$a,$again,true);
        $cases[]='same_uid_distinct_cash_reuses_bound_image_without_overwriting_first_id_time_and_current_finance_row_reads';
        $b=m4wq_upload($db,$fixture,$owner,'alipay','b');m4wq_read($db,$fixture,$owner,$b,null,false);
        $second=m4wq_cash($db,$fixture,$owner,'alipay',$b['reference'],$b);$saved[]=$second;
        m4wq_read($db,$fixture,$owner,$b,$second,false);m4wq_list($db,$fixture,$admin,$saved);m4wq_read($db,$fixture,$admin,$b,$second,true);
        m4wq_expect(m4wq_one($db,'eb_marketplace_customer_asset','id',$a['id'])===$firstBound,'M4WQ_ALL_FIRST34_RETAINED_AFTER_OTHER_ASSET');
        $cases[]='private_alipay_second_object_full_original_bytes_and_both_authenticated_readers';
        m4wq_expect(count($saved)===7&&($GLOBALS['m4wq_notice_application_requests']??0)===7
            &&($GLOBALS['m4wq_notice_rows']??0)===7*count($fixture['cash_notice']['recipients']),'M4WQ_ALL_SEVEN_REAL_CASH_NOTICE_HOOKS');
        $cases[]='all_seven_real_extract_and_brokerage_rows_user_deductions_system_notice_hooks_complete_database_ddl_native_counters_redis_files_oracle';
    }catch(Throwable $failure){$error=$failure;}
    finally{if($started)try{audit_app_stop();$status=audit_read_control_json(audit_run_dir().'/app-status.json');
        m4wq_expect(($status['status']??null)==='stop'&&($status['members']??null)===[]&&($status['listener_open']??null)===false
            &&($status['stop_mode']??null)==='term-only'&&($status['force_signal_sent']??null)===false,'M4WQ_NORMAL_TERM_ONLY_APP_GROUP_CLOSED');$closed=true;
    }catch(Throwable $failure){$error??=$failure;}}
    if($error!==null)throw $error;m4wq_expect(ini_get('memory_limit')==='512M','M4WQ_VERIFY_CLI_MEMORY_HELD');
    return ['scope'=>'CU_WITHDRAWAL_QR_REAL_NORMAL_HTTP_ONLY','source_sha'=>$manifest['commit_sha'],'business_cases'=>$cases,
        'requests'=>$GLOBALS['m4wq_requests']??0,'assertions'=>$GLOBALS['m4wq_assertions']??0,'normal_app_group_closed'=>$closed,
        'observer_memory'=>['scope'=>'VERIFY_CLI_ONLY','before'=>$memoryBefore,'effective'=>ini_get('memory_limit'),'fixed_limit_bytes'=>536870912,'peak_php_allocated_bytes'=>memory_get_peak_usage(true)],
        'synthetic_cash_applications'=>7,'real_system_notice_rows'=>$GLOBALS['m4wq_notice_rows']??0,'notice_configuration_changed'=>false,
        'resource_retirement'=>'PENDING_ROOT_ORDINARY_CLOSE_AND_INDEPENDENT_ZERO','financial_channel'=>'NOT RUN','external_notices'=>'NOT RUN',
        'browser_device_CORS'=>'NOT RUN','generic_public_compat_and_old_client_release'=>'INCOMPLETE','historical_mapping'=>'NOT RUN',
        'full_transaction_A_B_and_V1'=>'NOT RUN','stopped_negative_fault_concurrent_recovery_K'=>'NOT RUN','withdrawal_opened'=>false,'acceptance'=>'INCOMPLETE'];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $phase=$argv[1]??'';
    if(count($argv)!==2||!in_array($phase,['prepare','verify'],true)){fwrite(STDERR,"Usage: ms4-withdrawal-qr-private-normal.php prepare|verify; registered new ISO-FULL normal instance only\n");exit(2);}
    umask(0077);putenv('AUDIT_MILESTONE=MS4');
    if($phase==='prepare'){
        try{$result=m4wq_prepare();m4wq_write(audit_run_dir().'/evidence/ms4-withdrawal-qr-private-prepare.json',m4wq_json($result));
            echo "PREPARED CU_WITHDRAWAL_QR synthetic prerequisite only; HTTP NOT RUN\n";exit(0);
        }catch(Throwable $error){$detail=preg_match('/\AM4WQ_[A-Z0-9_]+\z/D',$error->getMessage())===1?$error->getMessage():get_class($error);
            fwrite(STDERR,'BLOCKED CU_WITHDRAWAL_QR preparation '.$detail."\n");exit(2);}
    }
    audit_case('CU_WITHDRAWAL_QR','normal-http','INTEGRATION',static function():array{
        $result=m4wq_verify();m4wq_write(audit_run_dir().'/evidence/ms4-withdrawal-qr-private-verify.json',m4wq_json($result));
        return audit_pass('real normal withdrawal QR HTTP and independent native observations; external retirement still pending');
    });audit_finish();
}
