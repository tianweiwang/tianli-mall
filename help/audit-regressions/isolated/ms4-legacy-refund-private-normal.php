<?php
declare(strict_types=1);

/** Real ordinary HTTP only. Importing starts no App, fixture, server or test. */
require_once __DIR__.'/lib/harness.php';
const M4LR_ROOT='/run/audit-customer-refund';
const M4LR_PRIVATE='/run/audit-fixtures/ms4-legacy-refund-private.json';
const M4LR_FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
    'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
    'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
    'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id','source_qrcode_id',
    'created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
const M4LR_ROWS=['eb_user','eb_store_order','eb_store_order_cart_info','eb_store_order_refund','eb_store_order_status',
    'eb_marketplace_customer_asset','eb_system_log','eb_message_system','eb_system_notification','eb_store_service'];
const M4LR_MESSAGE_FIELDS=['id','mark','uid','title','content','data','look','type','add_time','is_del'];

function m4lr_expect(bool $ok,string $code):void
{
    if(preg_match('/\AM4LR_[A-Z0-9_]+\z/D',$code)!==1)throw new RuntimeException('M4LR_INVALID_ASSERTION_CODE');
    $GLOBALS['m4lr_assertions']=($GLOBALS['m4lr_assertions']??0)+1;
    if(!$ok)throw new RuntimeException($code);
}
function m4lr_write(string $path,string $bytes):void
{
    m4lr_expect(!file_exists($path)&&!is_link($path),'M4LR_CREATE_ONLY_OUTPUT');
    $h=fopen($path,'xb');m4lr_expect(is_resource($h),'M4LR_OUTPUT_OPEN');
    try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($h,substr($bytes,$offset));m4lr_expect(is_int($n)&&$n>0,'M4LR_FULL_OUTPUT_WRITE');$offset+=$n;}
        m4lr_expect(fflush($h),'M4LR_OUTPUT_FLUSH');}finally{fclose($h);}
    m4lr_expect(chmod($path,0600)&&file_get_contents($path)===$bytes,'M4LR_OUTPUT_MODE_AND_READBACK');
}
function m4lr_json(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
function m4lr_rows(PDO $db,string $sql,array $arguments=[]):array
{
    $s=$db->prepare($sql);m4lr_expect($s instanceof PDOStatement&&$s->execute($arguments),'M4LR_NATIVE_QUERY');
    try{$rows=$s->fetchAll(PDO::FETCH_ASSOC);m4lr_expect(is_array($rows)&&array_is_list($rows),'M4LR_NATIVE_ROWS');return $rows;}
    finally{m4lr_expect($s->closeCursor(),'M4LR_NATIVE_CURSOR_CLOSE');}
}
function m4lr_database(PDO $db):array
{
    static $firstCountersRecorded=false;
    m4lr_expect(!$db->inTransaction(),'M4LR_INDEPENDENT_OBSERVER_OUTSIDE_TRANSACTION');
    m4lr_expect(in_array($db->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn(),[0,'0'],true),
        'M4LR_OBSERVER_METADATA_EXPIRY_ZERO');
    $requestsBefore=$GLOBALS['m4lr_requests']??0;
    $tables=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out=['hashes'=>[],'rows'=>[],'ddl'=>[],'next'=>[]];$initialNext=[];
    foreach($tables as$name=>$next){
        m4lr_expect(preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D',$name)===1,'M4LR_COMPLETE_KNOWN_TABLE_NAMESPACE');
        $rows=m4lr_rows($db,'SELECT * FROM `'.$name.'`');$serialized=array_map('serialize',$rows);sort($serialized,SORT_STRING);
        $out['hashes'][$name]=hash('sha256',serialize($serialized));
        $out['rows'][$name]=$rows;
        $create=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);
        m4lr_expect(is_array($create)&&is_string($create[1]??null),'M4LR_COMPLETE_NATIVE_DDL');
        $out['ddl'][$name]=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$create[1]);$initialNext[$name]=$next===null?null:(string)$next;
        // Keep every native row in out; only this table's finished scratch references are no longer needed.
        unset($serialized,$rows,$create);
    }
    $fresh=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    m4lr_expect(array_keys($fresh)===array_keys($tables),'M4LR_COUNTER_TABLE_NAMESPACE_UNCHANGED');
    foreach($fresh as$name=>$next)$out['next'][$name]=$next===null?null:(string)$next;
    foreach(M4LR_ROWS as$name)m4lr_expect(isset($out['rows'][$name]),'M4LR_REQUIRED_ORIGINAL_TABLES');
    if(!$firstCountersRecorded){
        $requestsAfter=$GLOBALS['m4lr_requests']??0;
        m4lr_expect(is_int($requestsBefore)&&$requestsBefore>=0&&$requestsAfter===$requestsBefore,'M4LR_FIRST_SNAPSHOT_NO_HTTP_BETWEEN_COUNTER_MAPS');
        $manifest=audit_manifest();
        m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-private-first-snapshot-counters.json',m4lr_json([
            'scope'=>'CU_LEGACY_REFUND_FIRST_OBSERVER_COUNTER_MAPS','run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],
            'business_HTTP_between_maps'=>0,'normal_http_request_count_before'=>$requestsBefore,'normal_http_request_count_after'=>$requestsAfter,
            'before_table_reads'=>$initialNext,'after_table_reads'=>$out['next']]));
        $firstCountersRecorded=true;
    }
    return $out;
}
function m4lr_redis():array
{
    $redis=audit_redis();$clock=$redis->time();$start=(int)$clock[0]*1000+intdiv((int)$clock[1],1000);
    $names=$redis->keys('*');sort($names,SORT_STRING);$out=[];
    foreach($names as$key){$type=$redis->type($key);$value=match($type){Redis::REDIS_STRING=>$redis->get($key),Redis::REDIS_HASH=>$redis->hGetAll($key),
        Redis::REDIS_SET=>$redis->sMembers($key),Redis::REDIS_LIST=>$redis->lRange($key,0,-1),
            Redis::REDIS_ZSET=>$redis->zRange($key,0,-1,true),Redis::REDIS_STREAM=>$redis->xRange($key,'-','+'),default=>null};
        m4lr_expect($value!==null&&$value!==false,'M4LR_ALL_REAL_REDIS_TYPES');
        if($type===Redis::REDIS_HASH)ksort($value,SORT_STRING);if($type===Redis::REDIS_SET)sort($value,SORT_STRING);
        $expires=$redis->rawCommand('PEXPIRETIME',$key);m4lr_expect(is_int($expires)&&($expires===-1||$expires>0),'M4LR_REDIS_ABSOLUTE_EXPIRY');
        $out[$key]=['type'=>$type,'value'=>$value,'expires'=>$expires];
    }$clock=$redis->time();$end=(int)$clock[0]*1000+intdiv((int)$clock[1],1000);
    m4lr_expect($end>=$start,'M4LR_REAL_REDIS_OBSERVER_CLOCK');return ['entries'=>$out,'start_ms'=>$start,'end_ms'=>$end];
}
function m4lr_files(string $root,bool $private=false):array
{
    m4lr_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'M4LR_REAL_FILE_ROOT');$files=[];$dirs=[];$owner=posix_geteuid();
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){m4lr_expect(!$file->isLink(),'M4LR_NO_FILE_LINKS');$path=$file->getPathname();$relative=substr($path,strlen($root)+1);$stat=lstat($path);
        if($file->isDir()){$dirs[$relative]=[$stat['dev'],$stat['ino'],$stat['mode']&0777,$stat['uid']];
            if($private)m4lr_expect(($stat['mode']&0777)===0700&&$stat['uid']===$owner,'M4LR_PRIVATE_PARENT_MODE_OWNER');continue;}
        m4lr_expect($file->isFile()&&is_array($stat),'M4LR_REGULAR_OBSERVED_FILE');
        if($private)m4lr_expect(($stat['mode']&0777)===0600&&$stat['uid']===$owner&&$stat['nlink']===1,'M4LR_PRIVATE_FILE_MODE_OWNER');
        $files[$relative]=['bytes'=>$stat['size'],'sha256'=>hash_file('sha256',$path),'mode'=>$stat['mode']&0777,'uid'=>$stat['uid'],'nlink'=>$stat['nlink']];
    }ksort($files,SORT_STRING);ksort($dirs,SORT_STRING);return ['files'=>$files,'directories'=>$dirs];
}
function m4lr_state(PDO $db):array
{
    $redis=m4lr_redis();return ['database'=>m4lr_database($db),'redis'=>$redis['entries'],'redis_clock'=>[$redis['start_ms'],$redis['end_ms']],'private'=>m4lr_files(M4LR_ROOT,true),
        'public'=>m4lr_files(audit_repo_dir().'/crmeb/public'),'runtime'=>m4lr_files('/run/audit-app-runtime')];
}
function m4lr_sorted(array $rows):array {$out=array_map('serialize',$rows);sort($out,SORT_STRING);return $out;}
function m4lr_number(PDO $db,int|string $number):int|string {return $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)?(string)$number:(int)$number;}
function m4lr_window(mixed $value,array $reply):void
{m4lr_expect((is_int($value)||is_string($value))&&ctype_digit((string)$value)&&(int)$value>=$reply['start']&&(int)$value<=$reply['end'],'M4LR_REAL_HTTP_TIMESTAMP_WINDOW');}

/** Original fixed internal host; multipart uses a real PHP upload, never an in-memory Request substitute. */
function m4lr_http(string $method,string $path,?string $token=null,?array $body=null,?array $image=null):array
{
    m4lr_expect(in_array($method,['GET','POST'],true)&&str_starts_with($path,'/')&&!str_contains($path,'://'),'M4LR_FIXED_NORMAL_HTTP_PATH');
    $headers=['Cb-lang'=>'zh-CN'];if($token!==null)$headers['Authori-zation']='Bearer '.$token;
    $start=time();$startMs=(int)floor(microtime(true)*1000);$responseHeaders=[];
    $curl=curl_init('http://audit-app:8000'.$path);$lines=[];foreach($headers as$key=>$value)$lines[]=$key.': '.$value;
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$responseHeaders):int{
            if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($key))]=trim($value);}return strlen($line);}]);
    if($image===null){if($body!==null){$lines[]='Content-Type: application/json';curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}}
    else{
        m4lr_expect($method==='POST'&&$body===null&&is_file($image['path'])&&hash_file('sha256',$image['path'])===$image['sha256'],'M4LR_REAL_MULTIPART_FIXTURE');
        curl_setopt($curl,CURLOPT_POSTFIELDS,['filename'=>'pics','pics'=>new CURLFile($image['path'],'image/png',$image['name'])]);
    }
    curl_setopt($curl,CURLOPT_HTTPHEADER,$lines);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    m4lr_expect(is_string($raw)&&$errno===0,'M4LR_NATIVE_REAL_HTTP_RETURNED');$decoded=json_decode($raw,true);
    $reply=['http'=>$status,'raw'=>$raw,'json'=>is_array($decoded)?$decoded:null,'headers'=>$responseHeaders];
    $reply['start']=$start;$reply['end']=time();$reply['start_ms']=$startMs;$reply['end_ms']=(int)floor(microtime(true)*1000);
    $GLOBALS['m4lr_requests']=($GLOBALS['m4lr_requests']??0)+1;return $reply;
}
function m4lr_success(array $reply,bool $data=true):mixed
{
    $body=$reply['json'];m4lr_expect($reply['http']===200&&is_array($body)&&($body['status']??null)===200&&is_string($body['msg']??null),'M4LR_REAL_NORMAL_HTTP_SUCCESS');
    if($data)m4lr_expect(array_key_exists('data',$body)&&is_array($body['data']),'M4LR_REQUIRED_DATA_DTO');return $body['data']??null;
}
function m4lr_private_response(array $reply):void
{
    $headers=$reply['headers'];m4lr_expect(str_contains(strtolower($headers['cache-control']??''),'private')
        &&str_contains(strtolower($headers['cache-control']??''),'no-store')&&($headers['pragma']??null)==='no-cache'
        &&($headers['expires']??null)==='0','M4LR_REAL_PRIVATE_NO_STORE_PUBLICATION');
}
function m4lr_content(string $path,string $token,array $image,?string $ticket=null):array
{
    $headers=[];$curl=curl_init('http://audit-app:8000'.$path);$start=time();$startMs=(int)floor(microtime(true)*1000);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>array_merge(['Authori-zation: Bearer '.$token,'Cb-lang: zh-CN'],$ticket===null?[]:['X-Legacy-Refund-Read-Ticket: '.$ticket]),CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int{
            if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}return strlen($line);}]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    m4lr_expect($errno===0&&$status===200&&is_string($raw)&&strlen($raw)===$image['bytes']&&hash('sha256',$raw)===$image['sha256']
        &&($headers['content-type']??null)==='image/png'&&($headers['content-length']??null)===(string)$image['bytes']
        &&($headers['x-content-type-options']??null)==='nosniff'&&str_contains(strtolower($headers['cache-control']??''),'no-store'),'M4LR_REAL_AUTHENTICATED_ORIGINAL_IMAGE');
    $GLOBALS['m4lr_requests']=($GLOBALS['m4lr_requests']??0)+1;return ['start'=>$start,'end'=>time(),'start_ms'=>$startMs,'end_ms'=>(int)floor(microtime(true)*1000)];
}

/** Complete native tables/files/Redis are compared, not a broad table or cache-prefix exception. */
function m4lr_transition(PDO $db,array $before,array $after,array $fixture,array $reply,string $actor,array $changes=[],array $increments=[],?array $newFile=null,bool $upload=false,array $redisAdds=[],array $redisRemoves=[]):void
{
    $b=$before['database'];$a=$after['database'];m4lr_expect(array_keys($a['hashes'])===array_keys($b['hashes'])&&$a['ddl']===$b['ddl'],'M4LR_ALL_TABLES_AND_DDL_RETAINED');
    $expected=$b['rows'];foreach($changes as$table=>$rows)$expected[$table]=$rows;
    if(in_array($actor,['customer','mobile'],true)){
        $seen=0;foreach($expected['eb_user']as&$row)if((string)$row['uid']===(string)$fixture[$actor==='mobile'?'mobile':'customer']['id']){
            $matches=array_values(array_filter($a['rows']['eb_user'],fn(array $r):bool=>(string)$r['uid']===(string)$row['uid']));
            m4lr_expect(count($matches)===1,'M4LR_EXACT_OWN_AUTH_ROW');m4lr_window($matches[0]['last_time'],$reply);$row['last_time']=$matches[0]['last_time'];$seen++;
        }unset($row);m4lr_expect($seen===1,'M4LR_ONLY_REAL_CUSTOMER_AUTH_TIMESTAMP');
    }
    foreach($a['hashes']as$table=>$hash){
        if(isset($expected[$table])){
            $actualRows=m4lr_sorted($a['rows'][$table]);$expectedRows=m4lr_sorted($expected[$table]);$same=$actualRows===$expectedRows;
            try{
                if(!$same){
                    $orders=static function(array $rows):array{
                        $seen=[];foreach($rows as$row){$keys=array_keys($row);$seen[json_encode($keys,JSON_THROW_ON_ERROR)]=$keys;}return array_values($seen);
                    };
                    m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-private-row-failure.json',m4lr_json([
                        'scope'=>'CU_LEGACY_REFUND_ROW_FAILURE_DIAGNOSTIC_ONLY','status'=>'ORIGINAL_ROW_ASSERTION_FAILED',
                        'run_id'=>$fixture['run_id'],'source_sha'=>$fixture['source_sha'],'actor'=>$actor,'table'=>$table,
                        'expected_count'=>count($expectedRows),'actual_count'=>count($actualRows),
                        'expected_column_orders'=>$orders($expected[$table]),'actual_column_orders'=>$orders($a['rows'][$table]),
                        'expected_multiset_sha256'=>hash('sha256',serialize($expectedRows)),
                        'actual_multiset_sha256'=>hash('sha256',serialize($actualRows)),'raw_row_values_omitted'=>true]));
                }
            }finally{m4lr_expect($same,'M4LR_COMPLETE_EXPECTED_NATIVE_ROWS');}
        }else m4lr_expect($hash===$b['hashes'][$table],'M4LR_OTHER_BUSINESS_EVENTS_AND_FINANCIAL_TABLES_UNCHANGED');
        $wanted=$b['next'][$table];if(isset($increments[$table]))$wanted=(string)((int)$wanted+$increments[$table]);
        try{
            if($a['next'][$table]!==$wanted){
                m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-private-counter-failure.json',m4lr_json([
                    'scope'=>'CU_LEGACY_REFUND_NATIVE_COUNTER_FAILURE_ONLY','status'=>'ORIGINAL_COUNTER_ASSERTION_FAILED',
                    'run_id'=>$fixture['run_id'],'source_sha'=>$fixture['source_sha'],'actor'=>$actor,
                    'first_mismatch'=>['table'=>$table,'before'=>$b['next'][$table],'wanted'=>$wanted,'actual'=>$a['next'][$table]],
                    'increments'=>$increments]));
            }
        }finally{
            m4lr_expect($a['next'][$table]===$wanted,'M4LR_ONLY_EXPECTED_NATIVE_COUNTER_ADVANCE');
        }
    }
    $redis=$before['redis'];
    if($upload){
        $key=$fixture['prefix'].'start_uploads_'.$fixture['customer']['id'];$tag=$fixture['prefix'].'CRMEB:'.md5('crmeb');$count=isset($redis[$key])?(int)$redis[$key]['value']:0;
        $new=$after['redis'][$key]??null;m4lr_expect(is_array($new)&&$new['type']===Redis::REDIS_STRING&&$new['value']===(string)($count+1)
            &&$new['expires']>=$reply['start']*1000+86400000&&$new['expires']<=($reply['end']+1)*1000+86400000,'M4LR_EXACT_UPLOAD_QUOTA_VALUE_AND_DEADLINE');
        $redis[$key]=$new;$members=$redis[$tag]['value']??[];$members[]=$key;$members=array_values(array_unique($members));sort($members,SORT_STRING);
        $redis[$tag]=['type'=>Redis::REDIS_SET,'value'=>$members,'expires'=>-1];ksort($redis,SORT_STRING);
    }
    foreach($redisRemoves as$key){m4lr_expect(array_key_exists($key,$redis),'M4LR_EXACT_EXISTING_REDIS_REMOVAL');unset($redis[$key]);}
    foreach($redisAdds as$key=>$value){m4lr_expect(!array_key_exists($key,$redis),'M4LR_EXACT_NEW_REDIS_VALUE');$redis[$key]=$value;}
    // Each absent old key needs its own previously observed absolute expiry and an actual Redis clock.
    foreach($redis as$key=>$value)if(!array_key_exists($key,$after['redis'])&&$value['expires']!==-1){
        m4lr_expect($value['expires']<=$after['redis_clock'][1],'M4LR_EXACT_KEY_NATURAL_EXPIRY_OBSERVED');unset($redis[$key]);
    }ksort($redis,SORT_STRING);
    m4lr_expect($after['redis']===$redis,'M4LR_ALL_REDIS_TYPES_VALUES_ABSOLUTE_EXPIRIES');
    m4lr_expect($after['public']===$before['public']&&$after['runtime']===$before['runtime'],'M4LR_PUBLIC_AND_RUNTIME_FILES_UNCHANGED');
    $files=$before['private']['files'];$directories=$before['private']['directories'];
    if($newFile!==null){
        $key=$newFile['key'];m4lr_expect(!isset($files[$key]),'M4LR_NEW_PRIVATE_OBJECT_ONLY');$files[$key]=$newFile['expected'];ksort($files,SORT_STRING);
        $path=dirname($key);$parents=[];while($path!=='.'){$parents[]=$path;$path=dirname($path);}
        foreach($parents as$path)if(!isset($directories[$path])){m4lr_expect(isset($after['private']['directories'][$path]),'M4LR_ACTUAL_NEW_PRIVATE_PARENT');$directories[$path]=$after['private']['directories'][$path];}
        ksort($directories,SORT_STRING);
    }
    m4lr_expect($after['private']===['files'=>$files,'directories'=>$directories],'M4LR_ALL_PRIVATE_OBJECTS_AND_PARENTS_EXPECTED');
}


function m4lr_guard():array
{
    $manifest=audit_manifest();
    m4lr_expect(getenv('AUDIT_LEGACY_REFUND_NORMAL')==='1'&&($manifest['guard_mode']??null)==='read-only','M4LR_NORMAL_ONLY_PROFILE');
    audit_guard_assert();audit_assert_legacy_refund_private_mount();
    m4lr_expect(($manifest['isolation']??null)==='ISO-FULL'&&($manifest['commit_sha']??null)===getenv('AUDIT_SOURCE_SHA')
        &&preg_match('/\A[a-f0-9]{40}\z/D',(string)getenv('AUDIT_SOURCE_SHA'))===1,'M4LR_CURRENT_COMMITTED_SOURCE');
    m4lr_expect(PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE===8&&function_exists('posix_geteuid'),'M4LR_REAL_PHP82_RUNTIME');
    m4lr_expect(!file_exists(audit_repo_dir().'/crmeb/.env')&&!is_link(audit_repo_dir().'/crmeb/.env'),'M4LR_NO_SHARED_ENV');
    return $manifest;
}
function m4lr_one(PDO $db,string $table,string $pk,int|string $id):array
{
    m4lr_expect(in_array($table,['eb_user','eb_system_admin','eb_store_order','eb_store_order_cart_info','eb_store_order_refund',
        'eb_store_product','eb_store_product_attr_value','eb_marketplace_customer_asset'],true)&&in_array($pk,['uid','id'],true),'M4LR_FIXED_ROW');
    $rows=m4lr_rows($db,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'`=?',[$id]);m4lr_expect(count($rows)===1,'M4LR_ONE_NATIVE_ROW');return $rows[0];
}
function m4lr_app(string $prefix):\think\App
{
    m4lr_expect(empty($GLOBALS['m4lr_app']),'M4LR_ONE_FIXTURE_APP');$GLOBALS['m4lr_app']=true;
    require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
    require_once audit_repo_dir().'/crmeb/vendor/topthink/framework/src/helper.php';
    $config=audit_config();$app=new \think\App(audit_repo_dir().'/crmeb/');$runtime='/tmp/ms4-legacy-refund-'.bin2hex(random_bytes(8)).'/';
    m4lr_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4LR_OWN_FIXTURE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis',
        'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer'],
        'MARKETPLACE_CUSTOMER_REFUND_PRIVATE_ROOT'=>M4LR_ROOT]);
    $app->initialize();m4lr_expect($app->initialized()&&app()===$app&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('marketplace.assets.customer_refund_private_root')===M4LR_ROOT
        &&$app->config->get('marketplace.enabled')===false&&$app->config->get('marketplace.trade.enabled')===false,'M4LR_REAL_APP_FIXED_CONFIGURATION');
    date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));return $app;
}
function m4lr_migrations():array
{
    m4lr_expect(is_file('/run/audit-bootstrap/migrator.json'),'M4LR_BOOTSTRAP_ONLY_MIGRATION');
    $credential=json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'),true,16,JSON_THROW_ON_ERROR);
    m4lr_expect(($credential['username']??null)==='audit_migrator','M4LR_ONLY_DEDICATED_MIGRATOR');$pdo=audit_pdo($credential);
    foreach(['marketplace-ms1.php','marketplace-ms2.php','marketplace-ms4.php','marketplace-ms4-assets.php']as$file)
        require_once audit_repo_dir().'/help/migrations/'.$file;
    $one=new \Audit\Migration\MarketplaceMs1Migration($pdo,'eb_');$out=[];
    $out['ms1']=[$one->check(),$one->apply(),$one->check(),$one->apply()];
    m4lr_expect($out['ms1'][2]['state']==='completed'&&$out['ms1'][3]['state']==='completed'&&$out['ms1'][3]['changed']===false,'M4LR_ORIGINAL_MS1_CHECK_APPLY_REPEAT');
    $state='/run/audit-fixtures/ms4-legacy-refund-ms2-state.json';m4lr_expect(!file_exists($state)&&!is_link($state),'M4LR_NEW_PRIVATE_MS2_STATE');
    $two=new \Audit\Migration\MarketplaceMs2Migration($pdo,$one,'eb_',audit_repo_dir(),['enabled'=>false],$state);
    $out['ms2']=[$two->check(),$two->applyStructure(),$two->check(),$two->applyStructure()];
    m4lr_expect($out['ms2'][2]['state']==='completed'&&$out['ms2'][2]['native_exit']===0&&$out['ms2'][2]['enabled']===false
        &&$out['ms2'][2]['migration_complete']===false&&$out['ms2'][3]['native_exit']===0&&$out['ms2'][3]['changed']===false,'M4LR_MS2_STRUCTURE_IS_NOT_HISTORY_MAPPING');
    foreach(['ms4'=>new MarketplaceMs4Migration($pdo,'eb_','audit_auth'),'assets'=>new MarketplaceMs4AssetMigration($pdo,'eb_','audit_auth')]as$name=>$migration){
        $out[$name]=[$migration->check(),$migration->applyStructure(),$migration->check(),$migration->applyStructure()];
        m4lr_expect($out[$name][2]['state']==='completed'&&$out[$name][2]['native_exit']===0
            &&$out[$name][3]['native_exit']===0&&$out[$name][3]['changed']===false,'M4LR_ORIGINAL_STRUCTURE_CHECK_APPLY_REPEAT');
    }
    m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-migration-method-receipts.json',m4lr_json([
        'scope'=>'CU_LEGACY_REFUND_NEW_ISOLATED_PREREQUISITE_METHOD_RECEIPTS_ONLY','receipts'=>$out,
        'native_CLI_not_claimed'=>true,'history_mapping'=>'NOT RUN','financial_channel'=>'NOT RUN']));return $out;
}
/** Only new synthetic preparation records; never edits shared or historic business data. */
function m4lr_insert(PDO $db,string $table,array $values):array
{
    m4lr_expect(in_array($table,['eb_store_product','eb_store_product_attr_value','eb_store_order','eb_store_order_cart_info'],true),'M4LR_FIXED_SYNTHETIC_TABLE');
    foreach(array_keys($values)as$key)m4lr_expect(preg_match('/\A[a-z_]+\z/D',$key)===1,'M4LR_FIXED_SYNTHETIC_COLUMN');
    $s=$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')');
    m4lr_expect($s instanceof PDOStatement&&$s->execute(array_values($values))&&$s->rowCount()===1&&$s->closeCursor(),'M4LR_NEW_SYNTHETIC_INSERT');
    return m4lr_one($db,$table,'id',$db->lastInsertId());
}
function m4lr_legacy_order(\think\App $app,PDO $db,int $uid,array $product,array $sku,int $quantity):array
{
    $number=$app->make(\app\services\order\StoreOrderCreateServices::class)->getNewOrderId('cp');
    $cart=$app->make(\app\services\order\StoreOrderCreateServices::class)->getNewOrderId('');$sum=bcmul('12.34',(string)$quantity,2);
    // Synthetic paid is a T5 material prerequisite, never evidence of an actual payment or historical import.
    $order=m4lr_insert($db,'eb_store_order',['order_id'=>$number,'uid'=>$uid,'pay_uid'=>$uid,'pid'=>0,'paid'=>1,'pay_type'=>'yue',
        'pay_time'=>time(),'status'=>1,'real_name'=>'隔离材料顾客','user_phone'=>'19900000101','user_address'=>'隔离合成地址',
        'cart_id'=>json_encode([$cart],JSON_THROW_ON_ERROR),'total_num'=>$quantity,'total_price'=>$sum,'pay_price'=>$sum,
        'pay_postage'=>'0.00','add_time'=>time(),'unique'=>md5('legacy-material:'.$number),'marketplace_trade_version'=>0]);
    $product['attrInfo']=$sku;
    $snapshot=['id'=>$cart,'uid'=>$uid,'product_id'=>(int)$product['id'],'cart_num'=>$quantity,'product_attr_unique'=>$sku['unique'],
        'productInfo'=>$product,'truePrice'=>'12.34','vip_truePrice'=>'0.00','price_type'=>'level','costPrice'=>'4.00','sum_price'=>$sum,
        'sum_true_price'=>$sum,'coupon_price'=>0,'integral_price'=>0,'postage_price'=>0,'origin_postage_price'=>0,'use_integral'=>0,
        'one_brokerage'=>0,'two_brokerage'=>0,'staff_brokerage'=>0,'agent_brokerage'=>0,'division_brokerage'=>0,'level'=>0,'member'=>0];
    $item=m4lr_insert($db,'eb_store_order_cart_info',['oid'=>$order['id'],'uid'=>$uid,'product_id'=>$product['id'],'cart_id'=>$cart,
        'cart_num'=>$quantity,'surplus_num'=>$quantity,'refund_num'=>0,'cart_info'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'unique'=>md5($cart.'_'.$order['id'])]);
    m4lr_expect((int)$order['marketplace_trade_version']===0&&(int)$item['uid']===$uid,'M4LR_REAL_SAVED_SYNTHETIC_LEGACY_FAMILY');
    return ['order'=>$order,'item'=>$item];
}

/** Independent projection of the original status/notify eligibility; preserve duplicate UIDs. */
function m4lr_notice_recipients(array $rows):array
{
    $out=[];
    foreach($rows as$row)if(in_array($row['status'],[1,'1'],true)&&in_array($row['notify'],[1,'1'],true)){
        m4lr_expect(isset($row['uid'])&&ctype_digit((string)$row['uid'])&&(int)$row['uid']>0,'M4LR_REAL_NOTICE_RECIPIENT_UID');
        $out[]=['nickname'=>$row['nickname'],'phone'=>$row['phone'],'uid'=>$row['uid'],'customer'=>$row['customer']];
    }
    return $out;
}

/** New isolated fixture configuration only; the real notification hook and recipients remain enabled. */
function m4lr_prepare_notice(\think\App $app,PDO $db,array $manifest):array
{
    $columns=m4lr_rows($db,'SHOW FULL COLUMNS FROM eb_message_system');
    m4lr_expect(array_column($columns,'Field')===M4LR_MESSAGE_FIELDS,'M4LR_COMPLETE_ORIGINAL_MESSAGE_SCHEMA');
    $types=['id'=>'int(?:\(10\))?','mark'=>'varchar\(50\)','uid'=>'int(?:\(11\))? unsigned',
        'title'=>'varchar\(256\)','content'=>'varchar\(512\)','data'=>'varchar\(5000\)',
        'look'=>'tinyint(?:\(1\))?','type'=>'tinyint(?:\(1\))?','add_time'=>'int(?:\(11\))?','is_del'=>'tinyint(?:\(1\))?'];
    foreach($columns as$column){
        $name=$column['Field'];$numeric=in_array($name,['uid','look','type','add_time','is_del'],true);
        m4lr_expect(preg_match('~\A'.$types[$name].'\z~D',$column['Type'])===1&&$column['Null']==='NO'
            &&$column['Key']===($name==='id'?'PRI':'')&&$column['Extra']===($name==='id'?'auto_increment':'')
            &&($name==='id'?$column['Default']===null:($numeric?in_array($column['Default'],[0,'0'],true):$column['Default']==='')),
            'M4LR_ALL_ORIGINAL_MESSAGE_TYPES_DEFAULTS_AND_PRIMARY');
    }
    $create=$db->query('SHOW CREATE TABLE eb_message_system')->fetch(PDO::FETCH_NUM);
    m4lr_expect(is_array($create)&&is_string($create[1]??null),'M4LR_NATIVE_MESSAGE_DDL');
    $ddl=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$create[1]);
    $before=m4lr_rows($db,'SELECT * FROM eb_system_notification ORDER BY id');
    $serviceBefore=m4lr_rows($db,'SELECT * FROM eb_store_service ORDER BY id');
    $matches=array_values(array_filter($before,fn(array $row):bool=>$row['mark']==='send_order_apply_refund'));
    m4lr_expect(count($matches)===1,'M4LR_ONE_ORIGINAL_REFUND_NOTIFICATION');$original=$matches[0];
    foreach(['is_system','is_sms','is_wechat','is_ent_wechat']as$key)m4lr_expect(in_array($original[$key],[1,'1'],true),'M4LR_ORIGINAL_REFUND_NOTICE_ENABLED_DEFAULTS');
    m4lr_expect(in_array($original['is_routine'],[0,'0'],true)&&$original['url']===''
        &&is_string($original['system_title'])&&is_string($original['system_text']),'M4LR_ORIGINAL_REFUND_NOTICE_TEMPLATE_AND_NO_WEBHOOK');
    $recipients=m4lr_notice_recipients($serviceBefore);m4lr_expect($recipients!==[],'M4LR_ORIGINAL_NONEMPTY_NOTICE_RECIPIENTS');
    $services=$app->make(\app\services\kefu\service\StoreServiceServices::class);
    m4lr_expect(m4lr_sorted($services->getStoreServiceOrderNotice())===m4lr_sorted($recipients),'M4LR_REAL_RECIPIENT_PRODUCER_MAPPING');
    foreach($recipients as$recipient)m4lr_one($db,'eb_user','uid',$recipient['uid']);
    $notice=$app->make(\app\services\message\SystemNotificationServices::class);$configured=$original;
    foreach(['is_sms','is_wechat']as$key){
        // This is the exact original SystemNotification::set_status service leaf, before any notice warmup or HTTP.
        m4lr_expect($notice->update((int)$original['id'],[$key=>2])!==false,'M4LR_REAL_ISOLATED_NOTICE_CONFIGURATION_PRODUCER');
        $configured[$key]=m4lr_number($db,2);
    }
    $expected=m4lr_replace($before,'id',$original['id'],$configured);
    $after=m4lr_rows($db,'SELECT * FROM eb_system_notification ORDER BY id');
    $serviceAfter=m4lr_rows($db,'SELECT * FROM eb_store_service ORDER BY id');
    m4lr_expect($after===$expected&&$serviceAfter===$serviceBefore
        &&m4lr_sorted($services->getStoreServiceOrderNotice())===m4lr_sorted($recipients),'M4LR_ONLY_TWO_NOTICE_FLAGS_AND_ALL_RECIPIENTS_RETAINED');
    m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-private-notice-preparation.json',m4lr_json([
        'scope'=>'CU_LEGACY_REFUND_ISOLATED_NOTICE_CONFIGURATION_ONLY','run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],
        'producer'=>'SystemNotification::set_status / SystemNotificationServices::update','notification_id'=>(string)$original['id'],
        'changed_columns'=>['is_sms'=>['before'=>1,'after'=>2],'is_wechat'=>['before'=>1,'after'=>2]],
        'notification_rows'=>count($before),'before_sha256'=>hash('sha256',serialize($before)),
        'expected_sha256'=>hash('sha256',serialize($expected)),'after_sha256'=>hash('sha256',serialize($after)),
        'other_notification_fields_and_rows_retained'=>true,'store_service_rows'=>count($serviceBefore),
        'store_service_before_sha256'=>hash('sha256',serialize($serviceBefore)),'store_service_after_sha256'=>hash('sha256',serialize($serviceAfter)),
        'eligible_count'=>count($recipients),'eligible_multiset_sha256'=>hash('sha256',serialize(m4lr_sorted($recipients))),
        'message_schema_sha256'=>hash('sha256',serialize($columns)),'is_system'=>1,'is_routine'=>0,'is_ent_wechat'=>1,
        'enterprise_url_empty'=>true,'seed_and_business_hooks_changed'=>false,'cache_clear_called'=>false,'external_sms_wechat'=>'NOT RUN']));
    return ['configuration'=>$configured,'recipients'=>$recipients,'store_service_sha256'=>hash('sha256',serialize(m4lr_sorted($serviceBefore))),
        'message_schema'=>$columns,'message_ddl'=>$ddl];
}

function m4lr_prepare():array
{
    $manifest=m4lr_guard();$root=lstat(M4LR_ROOT);
    m4lr_expect(is_array($root)&&!is_link(M4LR_ROOT)&&realpath(M4LR_ROOT)===M4LR_ROOT&&($root['mode']&0170000)===0040000
        &&$root['uid']===posix_geteuid()&&scandir(M4LR_ROOT)===['.','..'],'M4LR_FRESH_OWN_PRIVATE_ROOT');
    m4lr_expect(chmod(M4LR_ROOT,0700),'M4LR_PRIVATE_ROOT_MODE');$migration=m4lr_migrations();
    $db=audit_db();m4lr_expect(m4lr_rows($db,'SELECT * FROM eb_marketplace_customer_asset')===[],'M4LR_NEW_INSTANCE_NO_EXISTING_ASSETS');
    $accounts=json_decode(file_get_contents('/run/audit-fixtures/accounts.json'),true,32,JSON_THROW_ON_ERROR);
    m4lr_expect(isset($accounts['user_101'],$accounts['user_102'],$accounts['admin_super']),'M4LR_ORIGINAL_SYNTHETIC_ACCOUNTS');
    $prefix='audit:http-lang:'.$manifest['run_id'].':'.(string)(int)floor(microtime(true)*1000).':';$app=m4lr_app($prefix);
    $customer=$accounts['user_101'];$mobile=$accounts['user_102'];$admin=$accounts['admin_super'];
    m4lr_expect((int)m4lr_one($db,'eb_system_admin','id',$admin['id'])['level']===0,'M4LR_REAL_ORIGINAL_PLATFORM_SUPER');
    $legacy='/statics/system_images/admin_head_pic.png';m4lr_expect(is_file(audit_repo_dir().'/crmeb/public'.$legacy),'M4LR_REAL_LEGACY_PUBLIC_IMAGE');
    $app->make(\app\services\order\DeliveryServiceServices::class)->saveDeliveryService([
        'image'=>['uid'=>$mobile['id'],'image'=>$legacy],'phone'=>'199'.(string)random_int(10000000,99999999),'nickname'=>'隔离配送资格','status'=>1]);
    m4lr_expect($app->make(\app\services\order\DeliveryServiceServices::class)->checkoutIsService((int)$mobile['id'])===true,'M4LR_ORIGINAL_MOBILE_QUALIFICATION_PRODUCER');
    $product=m4lr_insert($db,'eb_store_product',['store_name'=>'隔离退款材料商品','unit_name'=>'件','image'=>$legacy,
        'slider_image'=>json_encode([$legacy],JSON_THROW_ON_ERROR),'price'=>'12.34','cost'=>'4.00','stock'=>10,'add_time'=>time()]);
    $sku=m4lr_insert($db,'eb_store_product_attr_value',['product_id'=>$product['id'],'type'=>0,'unique'=>bin2hex(random_bytes(4)),
        'suk'=>'隔离规格','price'=>'12.34','cost'=>'4.00','stock'=>10,'image'=>$legacy]);
    $families=[];foreach(['zero','legacy','private']as$name)$families[$name]=m4lr_legacy_order($app,$db,(int)$customer['id'],$product,$sku,$name==='private'?2:1);
    $prior=$families['private'];$model=$app->make(\app\services\order\StoreOrderServices::class)->get((int)$prior['order']['id']);
    [$selected,$other]=$app->make(\app\services\order\StoreOrderSplitServices::class)->equalSplit((int)$prior['order']['id'],
        [['cart_id'=>$prior['item']['cart_id'],'cart_num'=>1]],$model);
    m4lr_expect($selected instanceof \app\model\order\StoreOrder&&$other instanceof \app\model\order\StoreOrder,'M4LR_ORIGINAL_EQUAL_SPLIT_REAL_MODELS');
    $canonical=m4lr_one($db,'eb_store_order','id',$prior['order']['id']);$child=m4lr_one($db,'eb_store_order','id',(int)$selected->getData('id'));
    m4lr_expect((int)$canonical['pid']===-1&&(int)$child['pid']===(int)$canonical['id']&&(int)$child['uid']===(int)$customer['id']
        &&(int)$child['marketplace_trade_version']===0,'M4LR_TRUE_SPLIT_CANONICAL_ROOT_AND_OWNED_CHILD');
    $children=m4lr_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid=?',[$child['id']]);m4lr_expect(count($children)===1,'M4LR_ONE_REAL_CHILD_ITEM');
    $families['private']=['order'=>$child,'item'=>$children[0],'canonical'=>$canonical,'other_id'=>(int)$other->getData('id')];
    m4lr_expect(m4lr_rows($db,"SELECT id FROM eb_out_account WHERE is_del=0 AND status=1 AND push_open=1")===[],'M4LR_NO_EXTERNAL_REFUND_PUSH_CANDIDATES');
    m4lr_expect(m4lr_rows($db,"SELECT id FROM eb_system_event WHERE mark IN ('user_login','admin_login','order_initiated_refund') AND is_del=0 AND is_open=1")===[],'M4LR_NO_EXTERNAL_CUSTOM_EVENT_CANDIDATES');
    foreach(m4lr_rows($db,"SELECT * FROM eb_system_notification WHERE custom_trigger='login_success'")as$row)
        foreach(['is_sms','is_wechat','is_routine','is_ent_wechat']as$key)m4lr_expect((int)$row[$key]===0,'M4LR_ORIGINAL_EXTERNAL_NOTICE_DISABLED');
    $refundNotice=m4lr_prepare_notice($app,$db,$manifest);
    $queue=m4lr_rows($db,"SELECT value FROM eb_system_config WHERE menu_name='queue_open'");
    m4lr_expect(count($queue)===1&&json_decode($queue[0]['value'],true,16,JSON_THROW_ON_ERROR)===0,'M4LR_ORIGINAL_QUEUE_DISABLED');
    foreach(m4lr_rows($db,'SELECT menu_name FROM eb_system_config ORDER BY id')as$row)\crmeb\services\SystemConfigService::get($row['menu_name']);
    foreach(m4lr_rows($db,'SELECT mark FROM eb_system_notification ORDER BY id')as$row)$app->make(\app\services\message\SystemNotificationServices::class)->getOneNotce(['mark'=>$row['mark']]);
    getLang('操作成功');sys_data('admin_login_slide');
    foreach($families as$family){$app->make(\app\services\order\StoreOrderCartInfoServices::class)->getOrderCartInfo((int)$family['order']['id']);
        $app->make(\app\services\order\StoreOrderCartInfoServices::class)->getCarIdByProductTitle((int)$family['order']['id']);}
    $images=[];foreach(['a'=>[12,45,90],'b'=>[91,32,13],'c'=>[34,117,61],'d'=>[64,12,121]]as$name=>$rgb){
        $image=imagecreatetruecolor(16,16);m4lr_expect($image instanceof GdImage,'M4LR_SYNTHETIC_PNG_CREATE');imagefill($image,0,0,imagecolorallocate($image,...$rgb));
        ob_start();m4lr_expect(imagepng($image),'M4LR_SYNTHETIC_PNG_BYTES');$bytes=ob_get_clean();imagedestroy($image);
        m4lr_expect(is_string($bytes)&&$bytes!==''&&getimagesizefromstring($bytes)[0]===16,'M4LR_REAL_PNG_DECODE');
        $path='/run/audit-fixtures/refund-'.$name.'.png';m4lr_write($path,$bytes);$images[$name]=['path'=>$path,'name'=>'refund-'.$name.'.png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'width'=>16,'height'=>16];
    }
    $fixture=['schema'=>1,'run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],'prefix'=>$prefix,'private_uid'=>posix_geteuid(),
        'customer'=>$customer,'mobile'=>$mobile,'admin'=>$admin,'families'=>$families,'images'=>$images,'legacy'=>$legacy,
        'refund_notice'=>$refundNotice,'created_at'=>time()];
    m4lr_write(M4LR_PRIVATE,m4lr_json($fixture));return ['scope'=>'CU_LEGACY_REFUND_PREPARATION_ONLY','status'=>'PREPARED_NOT_HTTP_VERIFIED',
        'source_sha'=>$manifest['commit_sha'],'synthetic_legacy_families'=>3,'actual_equalSplit'=>true,'business_HTTP'=>'NOT RUN',
        'paid_status'=>'SYNTHETIC_T5_PREREQUISITE_NOT_PAYMENT_PROOF','history_mapping'=>'NOT RUN','browser'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}

function m4lr_find(array $rows,string $key,int|string $id):array
{
    $matches=array_values(array_filter($rows,fn(array $row):bool=>(string)$row[$key]===(string)$id));
    m4lr_expect(count($matches)===1,'M4LR_EXACT_ONE_OBSERVED_ROW');return $matches[0];
}
function m4lr_replace(array $rows,string $key,int|string $id,array $expected):array
{
    $count=0;foreach($rows as&$row)if((string)$row[$key]===(string)$id){$row=$expected;$count++;}unset($row);
    m4lr_expect($count===1,'M4LR_REPLACE_EXACT_EXISTING_EXPECTED_ROW');return $rows;
}
function m4lr_log_delta(PDO $db,array $before,array $after,array $fixture,array $reply,string $rule):array
{
    $id=$before['database']['next']['eb_system_log'];m4lr_expect(is_string($id)&&ctype_digit($id),'M4LR_REAL_LOG_NATIVE_NEXT');
    $row=m4lr_find($after['database']['rows']['eb_system_log'],'id',$id);
    $names=m4lr_rows($db,'SELECT menu_name FROM eb_system_menus WHERE api_url=? LIMIT 1',[$rule]);$name=$names[0]['menu_name']??'未知';
    m4lr_window($row['add_time'],$reply);$expected=['id'=>m4lr_number($db,$id),'admin_id'=>m4lr_number($db,$fixture['admin']['id']),
        'admin_name'=>$fixture['admin']['account'],'path'=>$rule,'page'=>$name?:'未知','method'=>'adminapi','ip'=>gethostbyname(gethostname()),
        'type'=>'system','add_time'=>$row['add_time'],'merchant_id'=>m4lr_number($db,0)];
    m4lr_expect(count($row)===count($expected)&&array_diff_key($row,$expected)===[],'M4LR_ALL_ORIGINAL_PLATFORM_LOG_COLUMNS');
    m4lr_expect(array_keys($row)===array_keys($expected),'M4LR_NATIVE_PLATFORM_LOG_COLUMN_ORDER');
    foreach($expected as$key=>$value)m4lr_expect($row[$key]===$value,'M4LR_ALL_ORIGINAL_PLATFORM_LOG_VALUES');
    $rows=$before['database']['rows']['eb_system_log'];$rows[]=$expected;
    return [['eb_system_log'=>$rows],['eb_system_log'=>1]];
}
function m4lr_status_delta(PDO $db,array $before,array $after,array $reply,int $orderId,array $specs):array
{
    $remaining=$after['database']['rows']['eb_store_order_status'];
    foreach($before['database']['rows']['eb_store_order_status']as$old){
        $found=false;foreach($remaining as$key=>$row)if($row===$old){unset($remaining[$key]);$found=true;break;}
        m4lr_expect($found,'M4LR_ALL_EXISTING_STATUS_EVENTS_PRESERVED');
    }
    m4lr_expect(count($remaining)===count($specs),'M4LR_ONLY_EXACT_ORIGINAL_AND_NOTE_EVENTS');$expected=$before['database']['rows']['eb_store_order_status'];
    foreach($specs as[$type,$message]){
        $matches=array_filter($remaining,fn(array $row):bool=>(string)$row['oid']===(string)$orderId&&$row['change_type']===$type&&$row['change_message']===$message);
        m4lr_expect(count($matches)===1,'M4LR_UNAMBIGUOUS_EXPECTED_STATUS_EVENT');$key=array_key_first($matches);$row=$matches[$key];
        m4lr_window($row['change_time'],$reply);$wanted=['oid'=>m4lr_number($db,$orderId),'change_type'=>$type,'change_message'=>$message,'change_time'=>$row['change_time']];
        m4lr_expect($row===$wanted,'M4LR_COMPLETE_ORIGINAL_STATUS_EVENT');$expected[]=$wanted;unset($remaining[$key]);
    }return $expected;
}

/** Build all business values and the native ID set before the request, never from its new message rows. */
function m4lr_notice_intent(PDO $db,array $fixture,array $before,array $order):array
{
    $frozen=$fixture['refund_notice'];$notice=$frozen['configuration'];
    m4lr_expect(m4lr_find($before['database']['rows']['eb_system_notification'],'id',$notice['id'])===$notice
        &&in_array($notice['is_system'],[1,'1'],true)&&in_array($notice['is_sms'],[2,'2'],true)
        &&in_array($notice['is_wechat'],[2,'2'],true)&&in_array($notice['is_routine'],[0,'0'],true)
        &&in_array($notice['is_ent_wechat'],[1,'1'],true)&&$notice['url']==='','M4LR_EXACT_ISOLATED_NOTICE_CONFIGURATION_BEFORE_HTTP');
    $serviceRows=$before['database']['rows']['eb_store_service'];
    // Database snapshots have no ORDER BY; compare their complete native row multiset with the prepared set hash.
    m4lr_expect(m4lr_sorted(m4lr_notice_recipients($serviceRows))===m4lr_sorted($frozen['recipients'])
        &&hash('sha256',serialize(m4lr_sorted($serviceRows)))===$frozen['store_service_sha256']
        &&$before['database']['ddl']['eb_message_system']===$frozen['message_ddl'],'M4LR_FROZEN_REAL_NOTICE_RECIPIENTS_AND_SCHEMA');
    $templates=[];
    foreach($frozen['recipients']as$recipient)$templates[]=['mark'=>$notice['mark'],'uid'=>m4lr_number($db,$recipient['uid']),
        'title'=>str_replace('{order_id}',$order['order_id'],$notice['system_title']),
        'content'=>str_replace('{order_id}',$order['order_id'],$notice['system_text']),
        'data'=>json_encode(['order_id'=>$order['order_id']],JSON_THROW_ON_ERROR),'look'=>m4lr_number($db,0),'type'=>m4lr_number($db,2),
        'is_del'=>m4lr_number($db,0)];
    $next=$before['database']['next']['eb_message_system'];
    m4lr_expect(is_string($next)&&ctype_digit($next)&&(int)$next>0&&(int)$next<=PHP_INT_MAX-count($templates),'M4LR_REAL_MESSAGE_NATIVE_NEXT');
    $ids=[];foreach($templates as$index=>$unused)$ids[]=m4lr_number($db,(int)$next+$index);
    return ['ids'=>$ids,'templates'=>$templates];
}

function m4lr_notice_delta(PDO $db,array $before,array $after,array $reply,array $intent):array
{
    $expected=$before['database']['rows']['eb_message_system'];$templates=$intent['templates'];
    m4lr_expect(count($templates)>0&&count($intent['ids'])===count($templates),'M4LR_NONEMPTY_REAL_NOTICE_INTENT');
    foreach($intent['ids']as$id){
        $row=m4lr_find($after['database']['rows']['eb_message_system'],'id',$id);
        m4lr_expect(array_keys($row)===M4LR_MESSAGE_FIELDS,'M4LR_FULL10_MESSAGE_NATIVE_ROW');m4lr_window($row['add_time'],$reply);
        $values=$row;unset($values['id'],$values['add_time']);$match=null;
        foreach($templates as$key=>$template)if($values===$template){$match=$key;break;}
        m4lr_expect($match!==null,'M4LR_MESSAGE_MATCHES_PRE_HTTP_RECIPIENT_AND_TEMPLATE');$template=$templates[$match];unset($templates[$match]);
        $wanted=['id'=>$id,'mark'=>$template['mark'],'uid'=>$template['uid'],'title'=>$template['title'],'content'=>$template['content'],
            'data'=>$template['data'],'look'=>$template['look'],'type'=>$template['type'],'add_time'=>$row['add_time'],'is_del'=>$template['is_del']];
        m4lr_expect($row===$wanted,'M4LR_ALL10_INDEPENDENT_MESSAGE_VALUES');$expected[]=$wanted;
    }
    m4lr_expect($templates===[],'M4LR_EVERY_ORIGINAL_NOTICE_RECIPIENT_PRESERVED');
    $GLOBALS['m4lr_notice_application_requests']=($GLOBALS['m4lr_notice_application_requests']??0)+1;
    $GLOBALS['m4lr_notice_rows']=($GLOBALS['m4lr_notice_rows']??0)+count($intent['ids']);
    return [$expected,count($intent['ids'])];
}

function m4lr_descriptor(array $asset):array
{
    $row=$asset['row'];return ['attachment_ref'=>$asset['reference'],'asset_id'=>$asset['id'],'media_type'=>$row['media_type'],
        'byte_size'=>(int)$row['byte_size'],'width'=>(int)$row['width'],'height'=>(int)$row['height']];
}
function m4lr_upload(PDO $db,array $fixture,string $token,string $purpose,array $family,?array $refund,string $which):array
{
    m4lr_expect(in_array($purpose,['legacy_refund_evidence','refund_shipping_proof'],true),'M4LR_FIXED_UPLOAD_PURPOSE');
    $orderId=(int)$family['order']['id'];$rootId=(int)($family['canonical']['id']??$orderId);$refundId=$refund===null?null:(int)$refund['id'];
    m4lr_expect(($purpose==='legacy_refund_evidence')===($refundId===null),'M4LR_REAL_UPLOAD_CONTEXT_KIND');
    $image=$fixture['images'][$which];$before=m4lr_state($db);
    $path=$refundId===null?'/api/order/refund/legacy/orders/'.$orderId.'/materials':'/api/order/refund/legacy/'.$refundId.'/shipping-materials';
    $reply=m4lr_http('POST',$path,$token,null,$image);$data=m4lr_success($reply);m4lr_private_response($reply);$after=m4lr_state($db);
    $id=$before['database']['next']['eb_marketplace_customer_asset'];m4lr_expect(is_string($id)&&ctype_digit($id),'M4LR_REAL_UPLOAD_NATIVE_NEXT');
    $row=m4lr_find($after['database']['rows']['eb_marketplace_customer_asset'],'id',$id);$uid=(int)$fixture['customer']['id'];
    m4lr_expect(array_keys($row)===M4LR_FIELDS,'M4LR_EXACT_FULL34_NEW_STAGED');
    $prefix='legacy-refund/v1/'.$purpose.'/'.$uid.'/'.$rootId.'/'.($refundId??0).'/';
    m4lr_expect(preg_match('~\A'.preg_quote($prefix,'~').'[a-f0-9]{64}/content\.png\z~D',$row['object_key'])===1,'M4LR_REAL_PRIVATE_ROOT_PURPOSE_CONTEXT_PATH');
    m4lr_window($row['created_at'],$reply);$expected=array_fill_keys(M4LR_FIELDS,null);
    $values=['id'=>$id,'uid'=>$uid,'purpose'=>$purpose,'state'=>'STAGED','storage_type'=>1,'object_key'=>$row['object_key'],'thumbnail_key'=>'',
        'name'=>'content.png','real_name'=>$image['name'],'media_type'=>'image/png','byte_size'=>$image['bytes'],'width'=>16,'height'=>16,
        'content_sha256'=>hex2bin($image['sha256']),'created_at'=>(int)$row['created_at'],'root_order_id'=>$rootId,'refund_id'=>$refundId,'created_by_uid'=>$uid];
    foreach($values as$key=>$value)$expected[$key]=$value!==null&&in_array($key,['id','uid','storage_type','byte_size','width','height','created_at','root_order_id','refund_id','created_by_uid'],true)?m4lr_number($db,$value):$value;
    m4lr_expect($row===$expected,'M4LR_ALL34_INDEPENDENT_UPLOAD_VALUES');$rows=$before['database']['rows']['eb_marketplace_customer_asset'];$rows[]=$expected;
    $file=['key'=>$row['object_key'],'expected'=>['bytes'=>$image['bytes'],'sha256'=>$image['sha256'],'mode'=>0600,'uid'=>$fixture['private_uid'],'nlink'=>1]];
    m4lr_transition($db,$before,$after,$fixture,$reply,'customer',['eb_marketplace_customer_asset'=>$rows],['eb_marketplace_customer_asset'=>1],$file,true);
    $asset=['id'=>$id,'reference'=>'mp-refund:v1:'.$purpose.':'.$id,'row'=>$expected,'image'=>$image];$dto=['name'=>'content.png']+m4lr_descriptor($asset);
    m4lr_expect($data===$dto&&file_get_contents(M4LR_ROOT.'/'.$row['object_key'])===file_get_contents($image['path']),'M4LR_NAMED_UPLOAD_DTO_AND_ORIGINAL_BYTES');return $asset;
}

function m4lr_bind_expected(PDO $db,array $before,array $after,array $reply,array $selected,int $refundId):array
{
    $rows=$before['database']['rows']['eb_marketplace_customer_asset'];$found=[];
    foreach($rows as&$row)if(in_array((string)$row['id'],array_column($selected,'id'),true)){
        m4lr_expect($row['state']==='STAGED'&&$row['bound_at']===null&&($row['refund_id']===null||(int)$row['refund_id']===$refundId),'M4LR_REAL_SELECTED_UNBOUND_STATE');
        $actual=m4lr_find($after['database']['rows']['eb_marketplace_customer_asset'],'id',$row['id']);m4lr_window($actual['bound_at'],$reply);
        $row['state']='BOUND';$row['refund_id']=m4lr_number($db,$refundId);$row['bound_at']=$actual['bound_at'];
        m4lr_expect($actual===$row,'M4LR_SELECTED_ALL34_ONLY_REAL_BINDING_DELTA');$found[]=(string)$row['id'];
    }unset($row);
    sort($found,SORT_STRING);$wanted=array_column($selected,'id');sort($wanted,SORT_STRING);
    m4lr_expect($found===$wanted,'M4LR_EVERY_SELECTED_REAL_ASSET_BOUND');return $rows;
}
function m4lr_snowflake_delta(array $before,array $after,array $reply,string $number):array
{
    m4lr_expect(preg_match('/\A[1-9][0-9]{0,18}\z/D',$number)===1&&(int)$number>0,'M4LR_ORIGINAL_REAL_REFUND_SNOWFLAKE');
    $millis=((int)$number>>22)+(int)strtotime('2022-01-01')*1000;$sequence=(int)$number&4095;$key=(string)$millis;
    m4lr_expect($millis>=$reply['start_ms']&&$millis<=$reply['end_ms']&&$sequence===0&&!isset($before['redis'][$key]),'M4LR_ONE_REAL_ORIGINAL_SNOWFLAKE_CONTEXT');
    if(isset($after['redis'][$key])){
        $entry=$after['redis'][$key];m4lr_expect($entry['type']===Redis::REDIS_STRING&&$entry['value']==='1'
            &&$entry['expires']>=$millis+1000&&$entry['expires']<=$reply['end_ms']+1000,'M4LR_EXACT_ORIGINAL_SEQUENCE_KEY_VALUE_DEADLINE');return [$key=>$entry];
    }
    m4lr_expect($after['redis_clock'][0]>=$reply['end_ms']+1000,'M4LR_SEQUENCE_ABSENCE_AFTER_PROVEN_WHOLE_DEADLINE_WINDOW');return [];
}
function m4lr_apply(PDO $db,array $fixture,string $token,array $family,array $images,array $selected):array
{
    $order=$family['order'];$item=$family['item'];$before=m4lr_state($db);
    m4lr_expect(m4lr_find($before['database']['rows']['eb_store_order'],'id',$order['id'])===$order
        &&m4lr_find($before['database']['rows']['eb_store_order_cart_info'],'id',$item['id'])===$item,'M4LR_IMMUTABLE_REAL_ORDER_AND_CART_INTENT');
    $body=['text'=>'隔离正常材料申请','refund_reason_wap_explain'=>'保留原申请说明','refund_reason_wap_img'=>implode(',',$images),
        'refund_type'=>2,'cart_ids'=>[['cart_id'=>$item['cart_id'],'cart_num'=>(int)$item['cart_num']]]];
    $noticeIntent=m4lr_notice_intent($db,$fixture,$before,$order);
    $reply=m4lr_http('POST','/api/order/refund/apply/'.$order['id'],$token,$body);m4lr_success($reply,false);$after=m4lr_state($db);
    $id=$before['database']['next']['eb_store_order_refund'];m4lr_expect(is_string($id)&&ctype_digit($id),'M4LR_REAL_REFUND_NATIVE_NEXT');
    $actual=m4lr_find($after['database']['rows']['eb_store_order_refund'],'id',$id);m4lr_window($actual['add_time'],$reply);
    $expected=['id'=>m4lr_number($db,$id),'store_order_id'=>$order['id'],'store_id'=>$order['store_id'],'order_id'=>$actual['order_id'],
        'uid'=>$order['uid'],'refund_type'=>m4lr_number($db,2),'refund_num'=>$item['cart_num'],'refund_price'=>$order['pay_price'],'refunded_price'=>'0.00',
        'refund_phone'=>'','refund_express'=>'','refund_express_name'=>'','refund_explain'=>$body['refund_reason_wap_explain'],
        'refund_img'=>json_encode($images,JSON_THROW_ON_ERROR),'refund_reason'=>$body['text'],'refuse_reason'=>'','remark'=>'','refunded_time'=>m4lr_number($db,0),
        'cart_info'=>json_encode([json_decode($item['cart_info'],true,512,JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR),
        'is_cancel'=>m4lr_number($db,0),'is_pink_cancel'=>m4lr_number($db,0),'is_del'=>m4lr_number($db,0),'add_time'=>$actual['add_time'],
        'is_system_del'=>m4lr_number($db,0),'mer_id'=>null,'marketplace_refund_mode'=>null];
    m4lr_expect($actual===$expected,'M4LR_COMPLETE_ORIGINAL_REFUND_ROW_AND_COMPATIBLE_DEFAULTS');
    $refunds=$before['database']['rows']['eb_store_order_refund'];$refunds[]=$expected;
    $cart=$item;$cart['refund_num']=$item['cart_num'];$carts=m4lr_replace($before['database']['rows']['eb_store_order_cart_info'],'id',$item['id'],$cart);
    $status=m4lr_status_delta($db,$before,$after,$reply,(int)$order['id'],[['apply_refund','用户申请退款，原因：'.$body['text']]]);
    $assets=m4lr_bind_expected($db,$before,$after,$reply,$selected,(int)$id);
    [$messages,$messageCount]=m4lr_notice_delta($db,$before,$after,$reply,$noticeIntent);
    $adds=m4lr_snowflake_delta($before,$after,$reply,$expected['order_id']);$cache=$fixture['prefix'].md5('store_order_cart_info_'.$order['id']);
    m4lr_transition($db,$before,$after,$fixture,$reply,'customer',['eb_store_order_refund'=>$refunds,'eb_store_order_cart_info'=>$carts,
        'eb_store_order_status'=>$status,'eb_marketplace_customer_asset'=>$assets,'eb_message_system'=>$messages],
        ['eb_store_order_refund'=>1,'eb_message_system'=>$messageCount],null,false,$adds,[$cache]);
    return $expected;
}
function m4lr_agree(PDO $db,array $fixture,string $token,array $refund):array
{
    m4lr_expect((int)$refund['refund_type']===2,'M4LR_ORIGINAL_RETURN_GOODS_APPLICATION');$before=m4lr_state($db);
    $reply=m4lr_http('GET','/adminapi/refund/agree/'.$refund['id'],$token);m4lr_success($reply,false);$after=m4lr_state($db);
    $refund['refund_type']=m4lr_number($db,4);$rows=m4lr_replace($before['database']['rows']['eb_store_order_refund'],'id',$refund['id'],$refund);
    [$changes,$increments]=m4lr_log_delta($db,$before,$after,$fixture,$reply,'refund/agree/<id>');$changes['eb_store_order_refund']=$rows;
    m4lr_transition($db,$before,$after,$fixture,$reply,'platform',$changes,$increments);return $refund;
}
function m4lr_shipping(PDO $db,array $fixture,string $token,array $refund,array $submitted,array $selected,string $note,bool $confirmation=false):array
{
    m4lr_expect((int)$refund['refund_type']===($confirmation?5:4),'M4LR_REAL_SHIPPING_OR_CONFIRMATION_STAGE');$before=m4lr_state($db);
    $body=['id'=>(int)$refund['id'],'refund_express'=>'NORMAL20261025001','refund_phone'=>'19900000101','refund_express_name'=>'隔离正常物流',
        'refund_shipping_note'=>$note,'refund_img'=>implode(',',$submitted)];
    $reply=m4lr_http('POST','/api/order/refund/express',$token,$body);m4lr_success($reply,false);$after=m4lr_state($db);
    $expected=$refund;$all=json_decode($refund['refund_img'],true,512,JSON_THROW_ON_ERROR);
    foreach($submitted as$ref)if(!in_array($ref,$all,true))$all[]=$ref;
    if(!$confirmation){
        foreach(['refund_express','refund_phone','refund_express_name']as$key)$expected[$key]=$body[$key];
        $expected['refund_type']=m4lr_number($db,5);$expected['refund_img']=json_encode($all,JSON_THROW_ON_ERROR);
    }
    m4lr_expect(m4lr_find($after['database']['rows']['eb_store_order_refund'],'id',$refund['id'])===$expected
        &&$expected['refund_explain']===$refund['refund_explain']&&$expected['remark']===$refund['remark'],'M4LR_ALL_ORIGINAL_REFUND_VALUES_AND_APPLICATION_EXPLANATION_RETAINED');
    $changes=['eb_store_order_refund'=>m4lr_replace($before['database']['rows']['eb_store_order_refund'],'id',$refund['id'],$expected)];
    if(!$confirmation){
        $specs=[['refund_express','用户已退货，订单号：'.$body['refund_express']]];
        if($note!=='')$specs[]=['refund_shipping_note','退款单#'.$refund['id'].' 寄回备注：'.$note];
        $changes['eb_store_order_status']=m4lr_status_delta($db,$before,$after,$reply,(int)$refund['store_order_id'],$specs);
        $changes['eb_marketplace_customer_asset']=m4lr_bind_expected($db,$before,$after,$reply,$selected,(int)$refund['id']);
    }
    m4lr_transition($db,$before,$after,$fixture,$reply,'customer',$changes);return $expected;
}

function m4lr_read(PDO $db,array $fixture,string $token,array $family,array $asset,?array $refund,string $reader):void
{
    $orderId=(int)$family['order']['id'];$rootId=(int)($family['canonical']['id']??$orderId);$refundId=$refund===null?null:(int)$refund['id'];
    switch($reader){
        case 'application':$kind='application_preview';$actor='customer';$base='/api/order/refund/legacy/orders/'.$orderId.'/materials/'.$asset['id'];$rule=null;break;
        case 'customer':$kind='customer_refund';$actor='customer';$base='/api/order/refund/legacy/'.$refundId.'/materials/'.$asset['id'];$rule=null;break;
        case 'mobile':$kind='mobile_refund';$actor='mobile';$base='/api/admin/refund_order/detail/'.$refundId.'/materials/'.$asset['id'];$rule=null;break;
        case 'list':case 'detail':$kind='platform_'.$reader;$actor='platform';$base='/adminapi/refund/'.$reader.'/'.$refundId.'/materials/'.$asset['id'];
            $rule='refund/<reader_kind>/<refund_id>/materials/<asset_id>';break;
        default:throw new RuntimeException('M4LR_FIXED_ACTUAL_READER');
    }
    m4lr_expect(($reader==='application')===($refundId===null),'M4LR_TRUE_READER_SAVED_CONTEXT');
    $before=m4lr_state($db);$reply=m4lr_http('POST',$base.'/read-ticket',$token,['schema'=>1]);$ticket=m4lr_success($reply);m4lr_private_response($reply);$after=m4lr_state($db);
    m4lr_expect(array_keys($ticket)===['read_ticket','issued_at_ms','expires_at_ms']&&is_string($ticket['read_ticket'])
        &&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket['read_ticket'])===1&&is_int($ticket['issued_at_ms'])&&is_int($ticket['expires_at_ms'])
        &&$ticket['issued_at_ms']>=$reply['start_ms']&&$ticket['issued_at_ms']<=$reply['end_ms']
        &&$ticket['expires_at_ms']===$ticket['issued_at_ms']+300000,'M4LR_REAL_REDIS_TIME_SHORT_TICKET_DTO');
    $platform=$actor==='platform';$actorId=(int)$fixture[$platform?'admin':$actor]['id'];
    $payload=['schema'=>1,'reader_kind'=>$kind,'app'=>$platform?'adminapi':'api','actor_type'=>$platform?'admin':'customer','actor_id'=>$actorId,
        'session_sha256'=>hash('sha256',$token),'uid'=>(int)$fixture['customer']['id'],'asset_id'=>$asset['id'],'content_sha256'=>$asset['image']['sha256'],
        'root_order_id'=>$rootId,'order_id'=>$orderId,'refund_id'=>$refundId,'purpose'=>$asset['row']['purpose'],'issued_at_ms'=>$ticket['issued_at_ms'],
        'expires_at_ms'=>$ticket['expires_at_ms'],'scope'=>'legacy_refund_material_read'];
    ksort($payload,SORT_STRING);$raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $key=$fixture['prefix'].'mp_legacy_refund_read:'.hash('sha256',$ticket['read_ticket']);
    $addition=[$key=>['type'=>Redis::REDIS_STRING,'value'=>$raw,'expires'=>$ticket['expires_at_ms']]];
    [$changes,$increments]=$platform?m4lr_log_delta($db,$before,$after,$fixture,$reply,$rule.'/read-ticket'):[[],[]];
    m4lr_transition($db,$before,$after,$fixture,$reply,$actor,$changes,$increments,null,false,$addition);
    // The ticket transition consumed these snapshots; release them before allocating the next complete pair.
    unset($before,$after,$changes,$increments);
    $before=m4lr_state($db);$reply=m4lr_content($base.'/content',$token,$asset['image'],$ticket['read_ticket']);$after=m4lr_state($db);
    [$changes,$increments]=$platform?m4lr_log_delta($db,$before,$after,$fixture,$reply,$rule.'/content'):[[],[]];
    m4lr_transition($db,$before,$after,$fixture,$reply,$actor,$changes,$increments,null,false,[],[$key]);
}
function m4lr_dto(array $data,array $refund,array $application,array $shipping,string $note):void
{
    foreach(['id','uid','store_order_id','order_id','refund_type','refund_explain','refund_reason']as$key)
        m4lr_expect(isset($data[$key])&&(string)$data[$key]===(string)$refund[$key],'M4LR_ORIGINAL_SAVED_REFUND_IDENTITY_AND_EXPLANATION_DTO');
    $groups=['schema'=>1,'application'=>array_map('m4lr_descriptor',$application),'shipping'=>array_map('m4lr_descriptor',$shipping)];
    $legacy=array_values(array_filter(json_decode($refund['refund_img'],true,512,JSON_THROW_ON_ERROR),fn(string $ref):bool=>!str_starts_with($ref,'mp-refund:')));
    m4lr_expect(($data['refund_materials']??null)===$groups&&($data['refund_img']??null)===$legacy&&($data['refund_shipping_note']??null)===$note,
        'M4LR_EXACT_TWO_PRIVATE_GROUPS_AND_ORIGINAL_URL_ORDER_AND_NOTE');
    m4lr_expect(($data['cart_info']??null)===json_decode($refund['cart_info'],true,512,JSON_THROW_ON_ERROR),'M4LR_COMPLETE_SAVED_ORIGINAL_CART_INTENT_DTO');
}
function m4lr_detail(PDO $db,array $fixture,string $token,array $refund,array $application,array $shipping,string $note,string $reader):void
{
    $number=rawurlencode($refund['order_id']);$before=m4lr_state($db);
    switch($reader){
        case 'customer':$path='/api/order/refund/detail/'.$number;$actor='customer';$rule=null;break;
        case 'mobile':$path='/api/admin/refund_order/detail/'.$number;$actor='mobile';$rule=null;break;
        case 'detail':$path='/adminapi/refund/info/'.$number;$actor='platform';$rule='refund/info/<uni>';break;
        default:throw new RuntimeException('M4LR_FIXED_DETAIL_CONSUMER');
    }
    $reply=m4lr_http('GET',$path,$token);$data=m4lr_success($reply);$after=m4lr_state($db);
    m4lr_dto($reader==='detail'?$data['orderInfo']:$data,$refund,$application,$shipping,$note);
    [$changes,$increments]=$actor==='platform'?m4lr_log_delta($db,$before,$after,$fixture,$reply,$rule):[[],[]];
    m4lr_transition($db,$before,$after,$fixture,$reply,$actor,$changes,$increments);
}
function m4lr_list(PDO $db,array $fixture,string $token,array $refund,array $application,array $shipping,string $note,bool $platform):void
{
    $before=m4lr_state($db);$reply=m4lr_http('GET',($platform?'/adminapi/refund/list':'/api/order/refund/list').'?page=1&limit=20',$token);
    $data=m4lr_success($reply);$after=m4lr_state($db);
    m4lr_expect(is_array($data['list']??null)&&is_int($data['count']??null)&&$data['count']===count($data['list'])&&$data['count']<=20,'M4LR_COMPLETE_REAL_ORIGINAL_LIST_PAGE');
    $expected=array_filter($before['database']['rows']['eb_store_order_refund'],fn(array $row):bool=>(int)$row['is_cancel']===0
        &&(int)$row[$platform?'is_system_del':'is_del']===0&&($platform||(string)$row['uid']===(string)$fixture['customer']['id']));
    $ids=array_map(fn(array $row):string=>(string)$row['id'],$expected);$actual=array_map(fn(array $row):string=>(string)$row['id'],$data['list']);sort($ids,SORT_STRING);sort($actual,SORT_STRING);
    m4lr_expect($ids===$actual,'M4LR_REAL_OWNER_OR_PLATFORM_LIST_COVERAGE');$row=m4lr_find($data['list'],'id',$refund['id']);m4lr_dto($row,$refund,$application,$shipping,$note);
    [$changes,$increments]=$platform?m4lr_log_delta($db,$before,$after,$fixture,$reply,'refund/list'):[[],[]];
    m4lr_transition($db,$before,$after,$fixture,$reply,$platform?'platform':'customer',$changes,$increments);
}
function m4lr_legacy_image(PDO $db,array $fixture):void
{
    $before=m4lr_state($db);$reply=m4lr_http('GET',$fixture['legacy']);$after=m4lr_state($db);$bytes=file_get_contents(audit_repo_dir().'/crmeb/public'.$fixture['legacy']);
    m4lr_expect($reply['http']===200&&is_string($bytes)&&$bytes!==''&&$reply['raw']===$bytes,'M4LR_REAL_ORIGINAL_LEGACY_IMAGE_BYTES');
    m4lr_transition($db,$before,$after,$fixture,$reply,'public');
}

function m4lr_verify():array
{
    $manifest=m4lr_guard();$observerMemoryBefore=ini_get('memory_limit');
    m4lr_expect(PHP_SAPI==='cli'&&is_string($observerMemoryBefore)&&ini_set('memory_limit','512M')!==false
        &&ini_get('memory_limit')==='512M','M4LR_VERIFY_CLI_FIXED_MEMORY_LIMIT');
    $stat=lstat(M4LR_PRIVATE);
    m4lr_expect(is_array($stat)&&($stat['mode']&0777)===0600&&$stat['uid']===posix_geteuid()&&!is_link(M4LR_PRIVATE),'M4LR_PRIVATE_CURRENT_FIXTURE_FILE');
    $fixture=json_decode(file_get_contents(M4LR_PRIVATE),true,64,JSON_THROW_ON_ERROR);
    m4lr_expect(($fixture['schema']??null)===1&&($fixture['run_id']??null)===$manifest['run_id']&&($fixture['source_sha']??null)===$manifest['commit_sha']
        &&($fixture['private_uid']??null)===posix_geteuid(),'M4LR_CURRENT_RUN_SOURCE_AND_PRIVATE_OWNER');
    m4lr_expect(time()>=$fixture['created_at']&&time()-$fixture['created_at']<600,'M4LR_CURRENT_FRESH_SYNTHETIC_PREREQUISITE');
    m4lr_app($fixture['prefix']);m4lr_expect(ini_get('memory_limit')==='512M','M4LR_VERIFY_CLI_MEMORY_LIMIT_AFTER_APP');
    $db=audit_db();m4lr_expect($db->exec('SET SESSION information_schema_stats_expiry=0')===0
        &&in_array($db->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn(),[0,'0'],true),'M4LR_OBSERVER_FRESH_METADATA');
    $started=false;$error=null;$closed=false;$cases=[];
    try{
        $started=true;audit_app_start(['CACHE_PREFIX'=>$fixture['prefix'],'REFUND_PRIVATE_ROOT'=>M4LR_ROOT]);$tokens=[];
        foreach(['customer','mobile']as$actor){
            $login=m4lr_success(m4lr_http('POST','/api/login',null,['account'=>$fixture[$actor]['account'],'password'=>$fixture[$actor]['password'],'spread'=>0,'agent_id'=>0]));
            $token=$login['token']??null;m4lr_expect(is_string($token)&&$token!=='','M4LR_REAL_CUSTOMER_PASSWORD_LOGIN');
            $claims=(array)\Firebase\JWT\JWT::decode($token,audit_config()['signing_key'],['HS256']);
            m4lr_expect(($claims['jti']->type??null)==='api'&&(string)($claims['jti']->id??'')===(string)$fixture[$actor]['id'],'M4LR_TRUE_SIGNED_CUSTOMER_ACTOR');$tokens[$actor]=$token;
        }
        $login=m4lr_success(m4lr_http('POST','/adminapi/login',null,['account'=>$fixture['admin']['account'],'pwd'=>$fixture['admin']['password'],'key'=>'']));
        $admin=$login['token']??null;m4lr_expect(is_string($admin)&&$admin!=='','M4LR_REAL_PLATFORM_PASSWORD_LOGIN');
        $claims=(array)\Firebase\JWT\JWT::decode($admin,audit_config()['signing_key'],['HS256']);
        m4lr_expect(($claims['auth_schema']??null)===2&&($claims['session_epoch']??null)===audit_config()['auth']['session_epoch']
            &&(string)($claims['jti']->id??'')===(string)$fixture['admin']['id'],'M4LR_TRUE_SIGNED_V2_PLATFORM_ACTOR');
        $owner=$tokens['customer'];$mobile=$tokens['mobile'];
        m4lr_expect((int)$fixture['customer']['id']!==(int)$fixture['mobile']['id'],'M4LR_OWNER_AND_QUALIFIED_MOBILE_ACTOR_DISTINCT');
        // These actual authenticated reader warmups precede the first module snapshot; login/cache writes are not a zero-side-effect claim.
        $empty=m4lr_success(m4lr_http('GET','/api/order/refund/list?page=1&limit=20',$owner));
        m4lr_expect(($empty['list']??null)===[]&&($empty['count']??null)===0,'M4LR_NEW_NORMAL_INSTANCE_EMPTY_REFUND_LIST');
        m4lr_success(m4lr_http('GET','/adminapi/refund/list?page=1&limit=20',$admin));
        $zero=m4lr_apply($db,$fixture,$owner,$fixture['families']['zero'],[],[]);
        m4lr_detail($db,$fixture,$owner,$zero,[],[],'','customer');$zero=m4lr_agree($db,$fixture,$admin,$zero);
        $zero=m4lr_shipping($db,$fixture,$owner,$zero,[],[],'');m4lr_detail($db,$fixture,$owner,$zero,[],[],'','customer');
        $zero=m4lr_shipping($db,$fixture,$owner,$zero,[],[],'',true);$cases[]='zero_image_original_application_return_and_actual_saved_confirmation';
        $legacy='http://audit-app:8000'.$fixture['legacy'];
        $old=m4lr_apply($db,$fixture,$owner,$fixture['families']['legacy'],[$legacy],[]);
        m4lr_detail($db,$fixture,$owner,$old,[],[],'','customer');m4lr_legacy_image($db,$fixture);$old=m4lr_agree($db,$fixture,$admin,$old);
        $oldNote='原有材料，正常寄回';$old=m4lr_shipping($db,$fixture,$owner,$old,[$legacy],[],$oldNote);
        m4lr_detail($db,$fixture,$owner,$old,[],[],$oldNote,'customer');$cases[]='legacy_url_original_order_and_application_explanation_preserved';
        $family=$fixture['families']['private'];
        $a=m4lr_upload($db,$fixture,$owner,'legacy_refund_evidence',$family,null,'a');m4lr_read($db,$fixture,$owner,$family,$a,null,'application');
        $b=m4lr_upload($db,$fixture,$owner,'legacy_refund_evidence',$family,null,'b');m4lr_read($db,$fixture,$owner,$family,$b,null,'application');
        $refund=m4lr_apply($db,$fixture,$owner,$family,[$legacy,$a['reference']],[$a]);
        $firstBound=m4lr_one($db,'eb_marketplace_customer_asset','id',$a['id']);
        m4lr_expect($firstBound['state']==='BOUND'&&(int)$firstBound['root_order_id']===(int)$family['canonical']['id']
            &&(int)$firstBound['refund_id']===(int)$refund['id'],'M4LR_TRUE_SPLIT_ROOT_AND_REAL_REFUND_PK_BINDING');
        m4lr_detail($db,$fixture,$owner,$refund,[$a],[],'','customer');m4lr_list($db,$fixture,$owner,$refund,[$a],[],'',false);
        m4lr_read($db,$fixture,$owner,$family,$a,$refund,'customer');$cases[]='real_equalSplit_child_application_selected_full34_binding_and_unselected_staged';
        $refund=m4lr_agree($db,$fixture,$admin,$refund);
        $c=m4lr_upload($db,$fixture,$owner,'refund_shipping_proof',$family,$refund,'c');m4lr_read($db,$fixture,$owner,$family,$c,$refund,'customer');
        $d=m4lr_upload($db,$fixture,$owner,'refund_shipping_proof',$family,$refund,'d');m4lr_read($db,$fixture,$owner,$family,$d,$refund,'customer');
        $note='包装完整，正常寄回';$submitted=[$legacy,$c['reference']];$refund=m4lr_shipping($db,$fixture,$owner,$refund,$submitted,[$c],$note);
        $refund=m4lr_shipping($db,$fixture,$owner,$refund,$submitted,[],$note,true);$cases[]='shipping_stable_append_independent_human_note_and_no_duplicate_saved_confirmation';
        m4lr_list($db,$fixture,$owner,$refund,[$a],[$c],$note,false);m4lr_detail($db,$fixture,$owner,$refund,[$a],[$c],$note,'customer');
        m4lr_list($db,$fixture,$admin,$refund,[$a],[$c],$note,true);m4lr_detail($db,$fixture,$admin,$refund,[$a],[$c],$note,'detail');
        m4lr_detail($db,$fixture,$mobile,$refund,[$a],[$c],$note,'mobile');
        foreach([$a,$c]as$asset){
            m4lr_read($db,$fixture,$owner,$family,$asset,$refund,'customer');m4lr_read($db,$fixture,$admin,$family,$asset,$refund,'list');
            m4lr_read($db,$fixture,$admin,$family,$asset,$refund,'detail');m4lr_read($db,$fixture,$mobile,$family,$asset,$refund,'mobile');
        }
        $cases[]='actual_owner_pc_list_pc_detail_and_original_mobile_qualification_ticket_and_full_original_bytes';
        m4lr_expect(m4lr_one($db,'eb_marketplace_customer_asset','id',$a['id'])===$firstBound,'M4LR_APPLICATION_FIRST_BINDING_ALL34_RETAINED');
        foreach([$b,$d]as$unselected)m4lr_expect(m4lr_one($db,'eb_marketplace_customer_asset','id',$unselected['id'])===$unselected['row'],'M4LR_UNSELECTED_STAGED_ALL34_RETAINED');
        $cases[]='all_original_rows_objects_and_first_bindings_retained_with_complete_independent_side_effect_oracle';
        m4lr_expect(($GLOBALS['m4lr_notice_application_requests']??0)===3
            &&($GLOBALS['m4lr_notice_rows']??0)===3*count($fixture['refund_notice']['recipients']),'M4LR_ALL_THREE_REAL_APPLICATION_NOTICE_HOOKS');
        $cases[]='three_original_refund_notice_hooks_full_message_rows_and_native_counters';
    }catch(Throwable $failure){$error=$failure;}
    finally{if($started)try{audit_app_stop();$status=audit_read_control_json(audit_run_dir().'/app-status.json');
        m4lr_expect(($status['status']??null)==='stop'&&($status['members']??null)===[]&&($status['listener_open']??null)===false
            &&($status['stop_mode']??null)==='term-only'&&($status['force_signal_sent']??null)===false,'M4LR_NORMAL_TERM_ONLY_APP_GROUP_CLOSED');$closed=true;
    }catch(Throwable $failure){$error??=$failure;}}
    if($error!==null)throw $error;
    m4lr_expect(ini_get('memory_limit')==='512M','M4LR_VERIFY_CLI_MEMORY_LIMIT_HELD');
    return ['scope'=>'CU_LEGACY_REFUND_REAL_NORMAL_HTTP_ONLY','source_sha'=>$manifest['commit_sha'],'business_cases'=>$cases,
        'requests'=>$GLOBALS['m4lr_requests']??0,'assertions'=>$GLOBALS['m4lr_assertions']??0,'normal_app_group_closed'=>$closed,
        'observer_memory'=>['scope'=>'VERIFY_CLI_ONLY','before'=>$observerMemoryBefore,'effective'=>ini_get('memory_limit'),
            'fixed_limit_bytes'=>536870912,'peak_php_allocated_bytes'=>memory_get_peak_usage(true)],
        'real_system_notice_application_requests'=>$GLOBALS['m4lr_notice_application_requests']??0,'real_system_notice_rows'=>$GLOBALS['m4lr_notice_rows']??0,
        'external_sms_wechat'=>'NOT RUN',
        'resource_retirement'=>'PENDING_ROOT_ORDINARY_CLOSE_AND_INDEPENDENT_ZERO','browser_device_CORS'=>'NOT RUN',
        'financial_channel'=>'NOT RUN','historical_mapping'=>'NOT RUN','full_transaction_A_B'=>'NOT RUN','full_V1_regression'=>'NOT RUN',
        'stopped_negative_fault_concurrent_recovery'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $phase=$argv[1]??'';
    if(count($argv)!==2||!in_array($phase,['prepare','verify'],true)){fwrite(STDERR,"Usage: ms4-legacy-refund-private-normal.php prepare|verify; registered new ISO-FULL normal instance only\n");exit(2);}
    umask(0077);putenv('AUDIT_MILESTONE=MS4');
    if($phase==='prepare'){
        try{$result=m4lr_prepare();m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-private-prepare.json',m4lr_json($result));
            echo "PREPARED CU_LEGACY_REFUND synthetic prerequisite only; HTTP NOT RUN\n";exit(0);
        }catch(Throwable $error){$detail=preg_match('/\AM4LR_[A-Z0-9_]+\z/D',$error->getMessage())===1?$error->getMessage():get_class($error);
            fwrite(STDERR,'BLOCKED CU_LEGACY_REFUND preparation '.$detail."\n");exit(2);}
    }
    audit_case('CU_LEGACY_REFUND','normal-http','INTEGRATION',static function():array{
        $result=m4lr_verify();m4lr_write(audit_run_dir().'/evidence/ms4-legacy-refund-private-verify.json',m4lr_json($result));
        return audit_pass('real normal legacy refund HTTP and independent native observations; external retirement still pending');
    });audit_finish();
}
