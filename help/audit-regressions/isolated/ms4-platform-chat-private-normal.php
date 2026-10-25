<?php
declare(strict_types=1);
/** PLATFORM_CHAT ordinary-only integration. Importing performs no fixture, App or test work. */
require_once __DIR__.'/lib/harness.php';
const M4PC_FIXTURE='/run/audit-fixtures/ms4-platform-chat-private.json';
const M4PC_ROWS=['eb_user','eb_store_service','eb_store_service_log','eb_store_service_record','eb_auxiliary',
    'eb_marketplace_platform_asset','eb_store_order','eb_store_order_cart_info','eb_wechat_user','eb_wechat_key','eb_wechat_reply','eb_cache','eb_system_config','eb_system_notification','eb_system_event'];
const M4PC_ASSET_FIELDS=['id','purpose','category_id','state','storage_type','object_key','thumbnail_key','name','real_name',
    'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at','created_by_admin_id',
    'created_by_service_id','created_by_uid','created_by_job','chat_customer_uid','chat_is_tourist','chat_service_id','chat_service_uid',
    'channel_qrcode_id','routine_version','legacy_att_id'];
const M4PC_LOG_FIELDS=['id','mer_id','msn','uid','to_uid','is_tourist','time_node','add_time','type','remind','msn_type'];
const M4PC_RECORD_FIELDS=['id','user_id','to_uid','nickname','avatar','is_tourist','online','type','add_time','update_time','mssage_num','message','message_type'];
const M4PC_AUX_FIELDS=['id','binding_id','relation_id','type','other','update_time','add_time'];

function m4pc_expect(bool $ok,string $code):void
{
    if(preg_match('/\AM4PC_[A-Z0-9_]+\z/D',$code)!==1)throw new RuntimeException('M4PC_INVALID_ASSERTION_CODE');
    $GLOBALS['m4pc_assertions']=($GLOBALS['m4pc_assertions']??0)+1;
    if(!$ok)throw new RuntimeException($code);
}

function m4pc_write(string $path,string $bytes):void
{
    m4pc_expect(!file_exists($path)&&!is_link($path),'M4PC_CREATE_ONLY_OUTPUT');
    $h=fopen($path,'xb');m4pc_expect(is_resource($h),'M4PC_OUTPUT_OPEN');
    try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($h,substr($bytes,$offset));m4pc_expect(is_int($n)&&$n>0,'M4PC_FULL_OUTPUT_WRITE');$offset+=$n;}
        m4pc_expect(fflush($h),'M4PC_OUTPUT_FLUSH');}finally{fclose($h);}
    m4pc_expect(chmod($path,0600)&&file_get_contents($path)===$bytes,'M4PC_OUTPUT_MODE_AND_READBACK');
}

function m4pc_json(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}

function m4pc_rows(PDO $db,string $sql,array $arguments=[]):array
{
    $s=$db->prepare($sql);m4pc_expect($s instanceof PDOStatement&&$s->execute($arguments),'M4PC_NATIVE_QUERY');
    try{$rows=$s->fetchAll(PDO::FETCH_ASSOC);m4pc_expect(is_array($rows)&&array_is_list($rows),'M4PC_NATIVE_ROWS');return $rows;}
    finally{m4pc_expect($s->closeCursor(),'M4PC_NATIVE_CURSOR_CLOSE');}
}

function m4pc_database(PDO $db):array
{
    static $firstCountersRecorded=false;
    m4pc_expect(!$db->inTransaction(),'M4PC_INDEPENDENT_OBSERVER_OUTSIDE_TRANSACTION');
    m4pc_expect(in_array($db->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn(),[0,'0'],true),
        'M4PC_OBSERVER_METADATA_EXPIRY_ZERO');
    $requestsBefore=$GLOBALS['m4pc_requests']??0;
    $tables=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out=['hashes'=>[],'rows'=>[],'ddl'=>[],'next'=>[]];$initialNext=[];
    foreach($tables as$name=>$next){
        m4pc_expect(preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D',$name)===1,'M4PC_COMPLETE_KNOWN_TABLE_NAMESPACE');
        $rows=m4pc_rows($db,'SELECT * FROM `'.$name.'`');$serialized=array_map('serialize',$rows);sort($serialized,SORT_STRING);
        $out['hashes'][$name]=hash('sha256',serialize($serialized));
        $out['rows'][$name]=$rows;
        $create=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);
        m4pc_expect(is_array($create)&&is_string($create[1]??null),'M4PC_COMPLETE_NATIVE_DDL');
        $out['ddl'][$name]=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$create[1]);$initialNext[$name]=$next===null?null:(string)$next;
        // Keep every native row in out; only this table's finished scratch references are no longer needed.
        unset($serialized,$rows,$create);
    }
    $fresh=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    m4pc_expect(array_keys($fresh)===array_keys($tables),'M4PC_COUNTER_TABLE_NAMESPACE_UNCHANGED');
    foreach($fresh as$name=>$next)$out['next'][$name]=$next===null?null:(string)$next;
    foreach(M4PC_ROWS as$name)m4pc_expect(isset($out['rows'][$name]),'M4PC_REQUIRED_ORIGINAL_TABLES');
    if(!$firstCountersRecorded){
        $requestsAfter=$GLOBALS['m4pc_requests']??0;
        m4pc_expect(is_int($requestsBefore)&&$requestsBefore>=0&&$requestsAfter===$requestsBefore,'M4PC_FIRST_SNAPSHOT_NO_HTTP_BETWEEN_COUNTER_MAPS');
        $manifest=audit_manifest();$phase=$GLOBALS['m4pc_phase']??null;m4pc_expect(in_array($phase,['prepare','verify'],true),'M4PC_EXACT_OBSERVER_PHASE');
        m4pc_write(audit_run_dir().'/evidence/ms4-platform-chat-private-first-snapshot-'.$phase.'-counters.json',m4pc_json([
            'scope'=>'PLATFORM_CHAT_FIRST_OBSERVER_COUNTER_MAPS','run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],
            'phase'=>$phase,'business_HTTP_between_maps'=>0,'normal_http_request_count_before'=>$requestsBefore,'normal_http_request_count_after'=>$requestsAfter,
            'before_table_reads'=>$initialNext,'after_table_reads'=>$out['next']]));
        $firstCountersRecorded=true;
    }
    return $out;
}

function m4pc_redis():array
{
    $redis=audit_redis();$clock=$redis->time();$start=(int)$clock[0]*1000+intdiv((int)$clock[1],1000);
    $names=$redis->keys('*');sort($names,SORT_STRING);$out=[];
    foreach($names as$key){$type=$redis->type($key);$value=match($type){Redis::REDIS_STRING=>$redis->get($key),Redis::REDIS_HASH=>$redis->hGetAll($key),
        Redis::REDIS_SET=>$redis->sMembers($key),Redis::REDIS_LIST=>$redis->lRange($key,0,-1),
            Redis::REDIS_ZSET=>$redis->zRange($key,0,-1,true),Redis::REDIS_STREAM=>$redis->xRange($key,'-','+'),default=>null};
        m4pc_expect($value!==null&&$value!==false,'M4PC_ALL_REAL_REDIS_TYPES');
        if($type===Redis::REDIS_HASH)ksort($value,SORT_STRING);if($type===Redis::REDIS_SET)sort($value,SORT_STRING);
        $expires=$redis->rawCommand('PEXPIRETIME',$key);m4pc_expect(is_int($expires)&&($expires===-1||$expires>0),'M4PC_REDIS_ABSOLUTE_EXPIRY');
        $out[$key]=['type'=>$type,'value'=>$value,'expires'=>$expires];
    }$clock=$redis->time();$end=(int)$clock[0]*1000+intdiv((int)$clock[1],1000);
    m4pc_expect($end>=$start,'M4PC_REAL_REDIS_OBSERVER_CLOCK');return ['entries'=>$out,'start_ms'=>$start,'end_ms'=>$end];
}

function m4pc_file_descriptor(string $path,bool $private=false):array
{
    clearstatcache(true,$path);$before=lstat($path);m4pc_expect(is_array($before)&&!is_link($path)&&($before['mode']&0170000)===0100000,'M4PC_REAL_NAMED_FILE');
    if($private)m4pc_expect(($before['mode']&07777)===0600&&$before['uid']===posix_geteuid()&&$before['nlink']===1,'M4PC_PRIVATE_FILE_MODE_OWNER');
    $h=fopen($path,'rb');m4pc_expect(is_resource($h),'M4PC_REAL_FILE_DESCRIPTOR');$hash=hash_init('sha256');$count=0;$keys=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    try{$fd=fstat($h);foreach($keys as$key)m4pc_expect($fd[$key]===$before[$key],'M4PC_FILE_PATH_HANDLE_IDENTITY');
        while(!feof($h)){$bytes=fread($h,65536);m4pc_expect(is_string($bytes),'M4PC_COMPLETE_FILE_READ');$count+=strlen($bytes);hash_update($hash,$bytes);}
        $after=fstat($h);clearstatcache(true,$path);$at=lstat($path);foreach($keys as$key)m4pc_expect($after[$key]===$before[$key]&&$at[$key]===$before[$key],'M4PC_FILE_STABLE_FULL_FD_WINDOW');
        m4pc_expect($count===$before['size'],'M4PC_FILE_COMPLETE_EOF');return ['bytes'=>$count,'sha256'=>hash_final($hash),'mode'=>$before['mode']&0777,'uid'=>$before['uid'],'nlink'=>$before['nlink'],
            'native9'=>array_intersect_key($before,array_fill_keys($keys,true))];
    }finally{m4pc_expect(fclose($h),'M4PC_FILE_OBSERVER_CLOSE');}
}
function m4pc_files(string $root,bool $private=false):array
{
    m4pc_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'M4PC_REAL_FILE_ROOT');$files=[];$dirs=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){m4pc_expect(!$file->isLink(),'M4PC_NO_FILE_LINKS');$path=$file->getPathname();$name=substr($path,strlen($root)+1);$stat=lstat($path);
        if($file->isDir()){$dirs[$name]=[$stat['dev'],$stat['ino'],$stat['mode']&0777,$stat['uid']];if($private)m4pc_expect(($stat['mode']&07777)===0700&&$stat['uid']===posix_geteuid(),'M4PC_PRIVATE_DIRECTORY_MODE_OWNER');}
        else{$files[$name]=m4pc_file_descriptor($path,$private);}
    }ksort($files,SORT_STRING);ksort($dirs,SORT_STRING);return ['files'=>$files,'directories'=>$dirs];
}

function m4pc_sorted(array $rows):array {$out=array_map('serialize',$rows);sort($out,SORT_STRING);return $out;}

function m4pc_number(PDO $db,int|string $number):int|string {return $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)?(string)$number:(int)$number;}

function m4pc_window(mixed $value,array $reply):void
{m4pc_expect((is_int($value)||is_string($value))&&ctype_digit((string)$value)&&(int)$value>=$reply['start']&&(int)$value<=$reply['end'],'M4PC_REAL_HTTP_TIMESTAMP_WINDOW');}

function m4pc_find(array $rows,string $key,int|string $id):array
{
    $matches=array_values(array_filter($rows,fn(array $row):bool=>(string)$row[$key]===(string)$id));
    m4pc_expect(count($matches)===1,'M4PC_EXACT_ONE_OBSERVED_ROW');return $matches[0];
}

function m4pc_replace(array $rows,string $key,int|string $id,array $expected):array
{
    $count=0;foreach($rows as&$row)if((string)$row[$key]===(string)$id){$row=$expected;$count++;}unset($row);
    m4pc_expect($count===1,'M4PC_REPLACE_EXACT_EXISTING_EXPECTED_ROW');return $rows;
}

function m4pc_migrations():array
{
    m4pc_expect(is_file('/run/audit-bootstrap/migrator.json'),'M4PC_BOOTSTRAP_ONLY_MIGRATION');
    $credential=json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'),true,16,JSON_THROW_ON_ERROR);
    m4pc_expect(($credential['username']??null)==='audit_migrator','M4PC_ONLY_DEDICATED_MIGRATOR');$pdo=audit_pdo($credential);
    foreach(['marketplace-ms1.php','marketplace-ms2.php','marketplace-ms4.php','marketplace-ms4-assets.php']as$file)
        require_once audit_repo_dir().'/help/migrations/'.$file;
    $one=new \Audit\Migration\MarketplaceMs1Migration($pdo,'eb_');$out=[];
    $out['ms1']=[$one->check(),$one->apply(),$one->check(),$one->apply()];
    m4pc_expect($out['ms1'][2]['state']==='completed'&&$out['ms1'][3]['state']==='completed'&&$out['ms1'][3]['changed']===false,'M4PC_ORIGINAL_MS1_CHECK_APPLY_REPEAT');
    $state='/run/audit-fixtures/ms4-platform-chat-ms2-state.json';m4pc_expect(!file_exists($state)&&!is_link($state),'M4PC_NEW_PRIVATE_MS2_STATE');
    $two=new \Audit\Migration\MarketplaceMs2Migration($pdo,$one,'eb_',audit_repo_dir(),['enabled'=>false],$state);
    $out['ms2']=[$two->check(),$two->applyStructure(),$two->check(),$two->applyStructure()];
    m4pc_expect($out['ms2'][2]['state']==='completed'&&$out['ms2'][2]['native_exit']===0&&$out['ms2'][2]['enabled']===false
        &&$out['ms2'][2]['migration_complete']===false&&$out['ms2'][3]['native_exit']===0&&$out['ms2'][3]['changed']===false,'M4PC_MS2_STRUCTURE_IS_NOT_HISTORY_MAPPING');
    foreach(['ms4'=>new MarketplaceMs4Migration($pdo,'eb_','audit_auth'),'assets'=>new MarketplaceMs4AssetMigration($pdo,'eb_','audit_auth')]as$name=>$migration){
        $out[$name]=[$migration->check(),$migration->applyStructure(),$migration->check(),$migration->applyStructure()];
        m4pc_expect($out[$name][2]['state']==='completed'&&$out[$name][2]['native_exit']===0
            &&$out[$name][3]['native_exit']===0&&$out[$name][3]['changed']===false,'M4PC_ORIGINAL_STRUCTURE_CHECK_APPLY_REPEAT');
    }
    m4pc_write(audit_run_dir().'/evidence/ms4-platform-chat-migration-method-receipts.json',m4pc_json([
        'scope'=>'PLATFORM_CHAT_NEW_ISOLATED_PREREQUISITE_METHOD_RECEIPTS_ONLY','receipts'=>$out,
        'native_CLI_not_claimed'=>true,'history_mapping'=>'NOT RUN','financial_channel'=>'NOT RUN']));return $out;
}

function m4pc_app(string $prefix):\think\App
{
    m4pc_expect(empty($GLOBALS['m4pc_app']),'M4PC_ONE_FIXTURE_APP');$GLOBALS['m4pc_app']=true;
    require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
    require_once audit_repo_dir().'/crmeb/vendor/topthink/framework/src/helper.php';
    $config=audit_config();$app=new \think\App(audit_repo_dir().'/crmeb/');$runtime='/tmp/ms4-platform-chat-'.bin2hex(random_bytes(8)).'/';
    m4pc_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4PC_OWN_FIXTURE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis',
        'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer'],
        'MARKETPLACE_PLATFORM_CHAT_PRIVATE_ROOT'=>M4PC_PRIVATE_ROOT]);
    $app->initialize();m4pc_expect($app->initialized()&&app()===$app&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('marketplace.platform_chat.private_root')===M4PC_PRIVATE_ROOT
        &&$app->config->get('marketplace.enabled')===false&&$app->config->get('marketplace.trade.enabled')===false,'M4PC_REAL_APP_FIXED_CONFIGURATION');
    date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));return $app;
}

function m4pc_guard():array
{
    $m=m4pc_profile_guard();m4pc_profile_mount();m4pc_expect(PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE===8,'M4PC_REAL_PHP82');
    m4pc_expect(!file_exists(audit_repo_dir().'/crmeb/.env')&&!is_link(audit_repo_dir().'/crmeb/.env'),'M4PC_NO_SHARED_ENV');return $m;
}
function m4pc_state(PDO $db):array
{
    m4pc_heartbeat();$redis=m4pc_redis();return ['database'=>m4pc_database($db),'redis'=>$redis['entries'],
        'redis_clock'=>[$redis['start_ms'],$redis['end_ms']],'private'=>m4pc_files(M4PC_PRIVATE_ROOT,true),
        'public'=>m4pc_files(audit_repo_dir().'/crmeb/public'),'runtime'=>m4pc_files('/run/audit-app-runtime')];
}
function m4pc_native_row(PDO $db,string $table,string $pk,int|string $id):array
{
    m4pc_expect(in_array($table,M4PC_ROWS,true)&&in_array($pk,['id','uid','key'],true),'M4PC_FIXED_NATIVE_ROW');
    $rows=m4pc_rows($db,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'`=?',[$id]);m4pc_expect(count($rows)===1,'M4PC_ONE_NATIVE_ROW');return $rows[0];
}
function m4pc_update_expected(PDO $db,array $rows,string $pk,int|string $id,array $fields,array $window):array
{
    $old=m4pc_find($rows,$pk,$id);foreach($fields as$key=>$value){m4pc_expect(array_key_exists($key,$old),'M4PC_REAL_COLUMN_ONLY');
        if($value==='@time'){$actual=$window['actual'][$key]??null;m4pc_window($actual,$window);$old[$key]=$actual;}
        else $old[$key]=is_int($value)?m4pc_number($db,$value):$value;
    }return m4pc_replace($rows,$pk,$id,$old);
}
/** Every mutable field is named by the concrete producer; remaining full rows and native counters stay exact. */
function m4pc_compare(array $before,array $after,array $rows=[],array $increments=[],array $redisAdd=[],array $redisRemove=[],?array $private=null,?array $runtime=null):void
{
    $b=$before['database'];$a=$after['database'];m4pc_expect(array_keys($a['rows'])===array_keys($b['rows'])&&$a['ddl']===$b['ddl'],'M4PC_ALL_DDL_NAMESPACE_UNCHANGED');
    foreach($b['rows']as$table=>$old){$wanted=$rows[$table]??$old;m4pc_expect(m4pc_sorted($a['rows'][$table])===m4pc_sorted($wanted),'M4PC_COMPLETE_ALL_NATIVE_ROWS');
        $next=$b['next'][$table];if(isset($increments[$table])){m4pc_expect(is_string($next)&&ctype_digit($next)&&is_int($increments[$table])&&$increments[$table]>=0,'M4PC_NATIVE_COUNTER_INPUT');$next=(string)((int)$next+$increments[$table]);}
        m4pc_expect($a['next'][$table]===$next,'M4PC_EXACT_NATIVE_NEXT_ADVANCE');
    }
    $wanted=$before['redis'];foreach($redisRemove as$key){m4pc_expect(isset($wanted[$key]),'M4PC_EXACT_EXISTING_REDIS_REMOVAL');unset($wanted[$key]);}
    foreach($redisAdd as$key=>$entry){m4pc_expect(is_array($entry)&&array_keys($entry)===['type','value','expires'],'M4PC_EXACT_REDIS_VALUE_SHAPE');$wanted[$key]=$entry;}
    foreach($wanted as$key=>$entry)if(!array_key_exists($key,$after['redis'])&&$entry['expires']!==-1){m4pc_expect($entry['expires']<=$after['redis_clock'][1],'M4PC_OLD_KEY_REAL_ABSOLUTE_EXPIRY');unset($wanted[$key]);}
    ksort($wanted,SORT_STRING);m4pc_expect($after['redis']===$wanted,'M4PC_ALL_REDIS_TYPES_VALUES_DEADLINES');
    m4pc_expect($after['public']===$before['public'],'M4PC_ALL_PUBLIC_BYTES_PRESERVED');
    m4pc_expect($after['private']===($private??$before['private']),'M4PC_COMPLETE_PRIVATE_BYTES_AND_IDENTITIES');
    m4pc_expect($after['runtime']===($runtime??$before['runtime']),'M4PC_COMPLETE_RUNTIME_FILES');
}
function m4pc_http(string $method,string $path,array $actor=[],?array $body=null,?array $upload=null,?string $ticket=null):array
{
    m4pc_expect(in_array($method,['GET','POST'],true)&&(preg_match('~\A/(?:api/|kefuapi/)[A-Za-z0-9_./<>-]+\z~D',$path)===1||($method==='GET'&&in_array($path,['/statics/system_images/admin_head_pic.png','/admin/system_static/media/notice.367f95a9.mp3'],true)))
        &&!str_contains($path,'..')&&!str_contains($path,'?'),'M4PC_FIXED_REAL_HTTP_NO_QUERY');
    $headers=['Cb-lang: zh-CN'];if($actor!==[]){m4pc_expect(isset($actor['kind'],$actor['token']),'M4PC_ACTUAL_ACTOR_CREDENTIAL');
        $headers[]=$actor['kind']==='guest'?'X-Platform-Chat-Guest-Token: '.$actor['token']:'Authori-zation: Bearer '.$actor['token'];}
    if($ticket!==null)$headers[]='X-Platform-Chat-Read-Ticket: '.$ticket;
    $seen=[];$start=time();$startMs=(int)floor(microtime(true)*1000);$curl=curl_init('http://audit-app:8000'.$path);
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$seen):int{if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$seen[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
    if($upload!==null){m4pc_expect($method==='POST'&&$body!==null&&is_file($upload['path'])&&hash_file('sha256',$upload['path'])===$upload['sha256'],'M4PC_REAL_MULTIPART_IMAGE');
        curl_setopt($curl,CURLOPT_POSTFIELDS,['filename'=>'file','file'=>new CURLFile($upload['path'],$upload['mime'],$upload['name'])]+$body);}
    elseif($body!==null){$headers[]='Content-Type: application/json';curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
    curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);$raw=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_errno($curl);curl_close($curl);
    m4pc_expect($error===0&&is_string($raw),'M4PC_ACTUAL_HTTP_COMPLETE');$json=json_decode($raw,true);$GLOBALS['m4pc_requests']=($GLOBALS['m4pc_requests']??0)+1;
    return ['http'=>$code,'raw'=>$raw,'json'=>is_array($json)?$json:null,'headers'=>$seen,'start'=>$start,'end'=>time(),'start_ms'=>$startMs,'end_ms'=>(int)floor(microtime(true)*1000)];
}
function m4pc_success(array $reply):array
{
    m4pc_expect($reply['http']===200&&is_array($reply['json'])&&($reply['json']['status']??null)===200&&is_array($reply['json']['data']??null),'M4PC_REAL_SUCCESS_ENVELOPE');return $reply['json']['data'];
}
function m4pc_read_exact($stream,int $length):string
{
    $bytes='';while(strlen($bytes)<$length){$part=fread($stream,$length-strlen($bytes));m4pc_expect(is_string($part)&&$part!=='','M4PC_COMPLETE_WS_NATIVE_BYTES');$bytes.=$part;}return $bytes;
}
function m4pc_ws(string $name,array $actor):void
{
    m4pc_expect(!isset($GLOBALS['m4pc_sockets'][$name]),'M4PC_NEW_REAL_WS_CLIENT');$stream=stream_socket_client('tcp://audit-app:40002',$errno,$message,5,STREAM_CLIENT_CONNECT);
    m4pc_expect(is_resource($stream),'M4PC_REAL_WS_TCP');stream_set_timeout($stream,10);$key=base64_encode(random_bytes(16));
    $request="GET / HTTP/1.1\r\nHost: audit-app:40002\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
    m4pc_expect(fwrite($stream,$request)===strlen($request),'M4PC_REAL_WS_HANDSHAKE_WRITE');$headers='';while(!str_ends_with($headers,"\r\n\r\n")){$headers.=m4pc_read_exact($stream,1);m4pc_expect(strlen($headers)<=8192,'M4PC_BOUNDED_WS_HANDSHAKE');}
    m4pc_expect(str_starts_with($headers,'HTTP/1.1 101 ')&&preg_match('/^Sec-WebSocket-Accept:\s*([^\r\n]+)\r$/mi',$headers,$m)===1
        &&hash_equals(base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true)),trim($m[1])),'M4PC_ACTUAL_WS_UPGRADE');
    $GLOBALS['m4pc_sockets'][$name]=['stream'=>$stream,'actor'=>$actor,'events'=>[]];m4pc_ws_send($name,['type'=>match($actor['kind']){'api'=>'login','kefu'=>'kefu_login','guest'=>'guest_login'},'data'=>$actor['token']]);
    $reply=m4pc_ws_wait($name,'success',static fn(array $d):bool=>($d['kind']??null)===$actor['kind']&&(int)($d['uid']??0)===$actor['uid']);
    m4pc_expect(($reply['data']['kind']??null)===$actor['kind'],'M4PC_REAL_WS_AUTHENTICATED');
}
function m4pc_ws_frame(string $name,int $opcode,string $payload):void
{
    $stream=$GLOBALS['m4pc_sockets'][$name]['stream'];$length=strlen($payload);m4pc_expect($length<=1048576,'M4PC_BOUNDED_WS_INPUT');$mask=random_bytes(4);
    $head=chr(0x80|$opcode).($length<126?chr(0x80|$length):($length<=65535?chr(0x80|126).pack('n',$length):chr(0x80|127).pack('NN',0,$length)));
    $masked='';for($i=0;$i<$length;$i++)$masked.=$payload[$i]^$mask[$i%4];$wire=$head.$mask.$masked;$offset=0;
    while($offset<strlen($wire)){$n=fwrite($stream,substr($wire,$offset));m4pc_expect(is_int($n)&&$n>0,'M4PC_COMPLETE_MASKED_WS_WRITE');$offset+=$n;}
}
function m4pc_ws_send(string $name,array $value):void {m4pc_ws_frame($name,1,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
function m4pc_ws_receive(string $name):array
{
    $stream=$GLOBALS['m4pc_sockets'][$name]['stream'];$head=m4pc_read_exact($stream,2);$a=ord($head[0]);$b=ord($head[1]);
    m4pc_expect(($a&0x80)!==0&&($a&0x70)===0&&($b&0x80)===0,'M4PC_ORIGINAL_SERVER_UNMASKED_FINAL_FRAME');$length=$b&127;
    if($length===126)$length=unpack('n',m4pc_read_exact($stream,2))[1];elseif($length===127){$n=unpack('Nhigh/Nlow',m4pc_read_exact($stream,8));m4pc_expect($n['high']===0,'M4PC_BOUNDED_WS_SERVER_FRAME');$length=$n['low'];}
    m4pc_expect($length<=1048576,'M4PC_BOUNDED_WS_RESPONSE');$payload=m4pc_read_exact($stream,$length);$opcode=$a&15;
    if($opcode===9){m4pc_ws_frame($name,10,$payload);return m4pc_ws_receive($name);}
    m4pc_expect($opcode===1,'M4PC_NORMAL_JSON_SERVER_FRAME');$value=json_decode($payload,true,32,JSON_THROW_ON_ERROR);
    m4pc_expect(is_array($value)&&is_string($value['type']??null)&&$value['type']!=='err_tip'&&$value['type']!=='error'&&empty($value['close']),'M4PC_ORIGINAL_NORMAL_WS_RESPONSE');
    $GLOBALS['m4pc_ws_frames']=($GLOBALS['m4pc_ws_frames']??0)+1;return $value;
}
function m4pc_ws_wait(string $name,string $type,callable $matches):array
{
    $events=&$GLOBALS['m4pc_sockets'][$name]['events'];foreach($events as$i=>$event)if($event['type']===$type&&$matches($event['data']??[])){array_splice($events,$i,1);return $event;}
    $deadline=microtime(true)+10;do{$event=m4pc_ws_receive($name);if($event['type']===$type&&$matches($event['data']??[]))return $event;$events[]=$event;
        m4pc_expect(count($events)<=256,'M4PC_EXACT_BOUNDED_PENDING_NORMAL_EVENTS');}while(microtime(true)<$deadline);throw new RuntimeException('M4PC_REAL_WS_EVENT_NOT_OBSERVED');
}
function m4pc_room(string $name,int $peer):void
{
    m4pc_ws_send($name,['type'=>'to_chat','data'=>['id'=>$peer]]);m4pc_ws_wait($name,'mssage_num',static fn(array $d):bool=>(int)($d['uid']??-1)===$peer&&(int)($d['num']??-1)===0);
    $GLOBALS['m4pc_sockets'][$name]['peer']=$peer;
}
function m4pc_heartbeat():void
{
    if(($GLOBALS['m4pc_heart_at']??0)+20>microtime(true))return;$GLOBALS['m4pc_heart_at']=microtime(true);
    foreach(array_keys($GLOBALS['m4pc_sockets']??[])as$name){m4pc_ws_send($name,['type'=>'ping']);m4pc_ws_wait($name,'ping',static fn(array $d):bool=>isset($d['now'],$d['datetime']));}
}
function m4pc_close_sockets():void
{
    foreach(array_keys($GLOBALS['m4pc_sockets']??[])as$name){m4pc_ws_frame($name,8,pack('n',1000));$stream=$GLOBALS['m4pc_sockets'][$name]['stream'];
        m4pc_expect(fclose($stream),'M4PC_NORMAL_WS_CLIENT_CLOSE');unset($GLOBALS['m4pc_sockets'][$name]);}
}

function m4pc_insert(PDO $db,string $table,array $data,string $pk='id'):array
{
    m4pc_expect(in_array($table,M4PC_ROWS,true)&&in_array($pk,['id','uid','key'],true),'M4PC_FIXED_SYNTHETIC_INSERT');
    $columns=m4pc_rows($db,'SHOW FULL COLUMNS FROM `'.$table.'`');$types=array_column($columns,'Type','Field');m4pc_expect(array_diff(array_keys($data),array_keys($types))===[],'M4PC_REAL_INSERT_COLUMNS');
    $expected=[];foreach($columns as$column){$key=$column['Field'];$value=array_key_exists($key,$data)?$data[$key]:$column['Default'];
        if($key!==$pk)m4pc_expect($value!==null||$column['Null']==='YES','M4PC_REQUIRED_FIXTURE_VALUE_BEFORE_DML');
        if($value!==null&&preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)\b/',$types[$key])===1)$value=m4pc_number($db,$value);$expected[$key]=$value;}
    // Everything except the not-yet-issued native PK is fixed from real DDL/defaults and explicit inputs before INSERT.
    $s=$db->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')');
    m4pc_expect($s->execute(array_values($data))&&$s->rowCount()===1&&$s->closeCursor(),'M4PC_REAL_FIXTURE_INSERT');$id=$data[$pk]??$db->lastInsertId();
    m4pc_expect((is_int($id)||is_string($id))&&(string)$id!=='','M4PC_ACTUAL_INSERTED_PRIMARY_KEY');$expected[$pk]=preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)\b/',$types[$pk])===1?m4pc_number($db,$id):$id;
    $rows=m4pc_rows($db,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'`=?',[$id]);m4pc_expect(count($rows)===1&&$rows[0]===$expected,'M4PC_COMPLETE_PREPARED_ROW_INDEPENDENT_EXPECTATION');return $expected;
}
function m4pc_prepare_preflight(PDO $db):array
{
    foreach(['eb_store_service','eb_store_service_record']as$table)m4pc_expect((int)$db->query('SELECT COUNT(*) FROM `'.$table.'` WHERE online<>0')->fetchColumn()===0,'M4PC_ORIGINAL_ONLINE_VALUES_ALREADY_ZERO');
    $from=strtotime('last week 00:00:00');$to=strtotime('this week 00:00:00 -1second');
    m4pc_expect(is_int($from)&&is_int($to)&&m4pc_rows($db,'SELECT id FROM eb_store_service_record WHERE is_tourist=1 AND update_time BETWEEN ? AND ?',[$from,$to])===[],
        'M4PC_ORIGINAL_CONSTRUCTOR_DELETE_RANGE_EMPTY');
    m4pc_expect((int)$db->query('SELECT @@auto_increment_increment')->fetchColumn()===1,'M4PC_REAL_NATIVE_INCREMENT_ONE');
    return ['calendar_last_week'=>[$from,$to],'old_online_rows_already_zero'=>true,'seed_rewritten'=>false];
}
function m4pc_prepare():array
{
    $m=m4pc_guard();$stat=lstat(M4PC_PRIVATE_ROOT);m4pc_expect(is_array($stat)&&!is_link(M4PC_PRIVATE_ROOT)&&realpath(M4PC_PRIVATE_ROOT)===M4PC_PRIVATE_ROOT
        &&$stat['uid']===posix_geteuid()&&scandir(M4PC_PRIVATE_ROOT)===['.','..']&&chmod(M4PC_PRIVATE_ROOT,0700),'M4PC_FRESH_REGISTERED_OWN_PRIVATE_ROOT');
    $migrations=m4pc_migrations();$db=audit_db();m4pc_expect($db->exec('SET SESSION information_schema_stats_expiry=0')===0,'M4PC_FRESH_METADATA_OBSERVER');
    $preflight=m4pc_prepare_preflight($db);$before=m4pc_state($db);$accounts=json_decode(file_get_contents('/run/audit-fixtures/accounts.json'),true,32,JSON_THROW_ON_ERROR);
    m4pc_expect(isset($accounts['user_101']['id']),'M4PC_ACTUAL_ORIGINAL_SYNTHETIC_ACCOUNT');$prefix='audit:platform-chat:'.$m['run_id'].':'.bin2hex(random_bytes(8)).':';$app=m4pc_app($prefix);
    m4pc_expect($app->config->get('marketplace.platform_chat.private_writes_enabled')===false,'M4PC_ORIGINAL_PRIVATE_DEFAULT_FALSE');
    $customer=m4pc_native_row($db,'eb_user','uid',$accounts['user_101']['id']);$template=$customer;$staff=[];$created=[];
    $services=m4pc_rows($db,'SELECT * FROM eb_store_service ORDER BY id');m4pc_expect($services!==[],'M4PC_ORIGINAL_COMPLETE_PLATFORM_SERVICE_SEED');
    foreach(['a','b']as$letter){$user=$template;unset($user['uid']);$name='audit-chat-'.substr(hash('sha256',$m['run_id']),0,10).'-'.$letter;m4pc_expect(strlen($name)<=25&&m4pc_rows($db,'SELECT uid FROM eb_user WHERE account=?',[$name])===[],'M4PC_SYNTHETIC_ACCOUNT_FITS_ORIGINAL_COLUMNS');
        foreach(['account'=>$name,'nickname'=>$name,'real_name'=>$name,'phone'=>'','status'=>1,'is_del'=>0,'last_time'=>0,'add_time'=>time(),'avatar'=>$template['avatar']]as$key=>$value){m4pc_expect(array_key_exists($key,$user),'M4PC_REAL_SYNTHETIC_USER_COLUMNS');$user[$key]=$value;}
        $user=m4pc_insert($db,'eb_user',$user,'uid');$service=$services[0];unset($service['id']);
        $service=array_replace($service,['uid'=>$user['uid'],'online'=>0,'account'=>$name,'nickname'=>$name,'status'=>1,'add_time'=>time()]);$service=m4pc_insert($db,'eb_store_service',$service);
        m4pc_expect((int)$service['id']!==(int)$user['uid']&&count(m4pc_rows($db,'SELECT id FROM eb_store_service WHERE uid=?',[$user['uid']]))===1,'M4PC_REAL_DISTINCT_SERVICE_ID_AND_UID');
        $staff[$letter]=['uid'=>(int)$user['uid'],'service_id'=>(int)$service['id'],'user'=>$user,'service'=>$service];$created['eb_user'][]=$user;$created['eb_store_service'][]=$service;
    }
    $legacyGuest=2000000101;m4pc_expect(m4pc_rows($db,'SELECT uid FROM eb_user WHERE uid=?',[$legacyGuest])===[],'M4PC_HISTORIC_GUEST_IS_NOT_FAKE_USER');
    foreach([$customer['uid']=>0,$legacyGuest=>1]as$uid=>$tourist)foreach($staff as$actor)foreach([[$actor['uid'],$uid],[$uid,$actor['uid']]]as[$from,$to]){
        m4pc_expect(m4pc_rows($db,'SELECT id FROM eb_store_service_record WHERE user_id=? AND to_uid=? AND is_tourist=?',[$from,$to,$tourist])===[],'M4PC_NEW_OWN_SYNTHETIC_HISTORY_PAIR');
        $row=m4pc_insert($db,'eb_store_service_record',['user_id'=>$from,'to_uid'=>$to,'nickname'=>'audit-chat-'.($tourist?'historic-guest':'customer'),'avatar'=>$customer['avatar'],
            'is_tourist'=>$tourist,'online'=>0,'type'=>1,'add_time'=>time(),'update_time'=>time(),'mssage_num'=>0,'message'=>'','message_type'=>1]);$created['eb_store_service_record'][]=$row;
    }
    $keyword='audit-chat-key-'.$m['run_id'];$replyText='audit-chat-original-auto-reply-'.$m['run_id'];m4pc_expect(m4pc_rows($db,'SELECT id FROM eb_wechat_key WHERE `keys`=? AND key_type=1',[$keyword])===[],'M4PC_NEW_OWN_KEYWORD');
    $autoReply=m4pc_insert($db,'eb_wechat_reply',['type'=>'text','data'=>json_encode(['content'=>$replyText],JSON_THROW_ON_ERROR),'status'=>1,'hide'=>0]);
    $autoKey=m4pc_insert($db,'eb_wechat_key',['reply_id'=>$autoReply['id'],'keys'=>$keyword,'key_type'=>1]);$created['eb_wechat_reply'][]=$autoReply;$created['eb_wechat_key'][]=$autoKey;
    $images=[];foreach(['jpg','png','gif','webp']as$index=>$extension){$image=imagecreatetruecolor(16,16);m4pc_expect($image instanceof GdImage,'M4PC_REAL_SYNTHETIC_IMAGE');
        imagefill($image,0,0,imagecolorallocate($image,40+$index*30,50,100));ob_start();$ok=match($extension){'jpg'=>imagejpeg($image,null,90),'png'=>imagepng($image),'gif'=>imagegif($image),'webp'=>imagewebp($image,null,90)};$bytes=ob_get_clean();imagedestroy($image);
        m4pc_expect($ok&&is_string($bytes)&&$bytes!==''&&getimagesizefromstring($bytes)[0]===16,'M4PC_ACTUAL_IMAGE_ENCODER_AND_DECODE');$path='/run/audit-fixtures/platform-chat-'.$extension.'.'.$extension;m4pc_write($path,$bytes);
        $images[$extension]=['path'=>$path,'name'=>'platform-chat-'.$extension.'.'.$extension,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mime'=>(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes),'width'=>16,'height'=>16];
    }
    $prepStart=time();$tokens=[];$tokenInfo=[];$jwt=$app->make(\crmeb\utils\JwtAuth::class);$tokenInfo['customer']=$jwt->createToken((int)$customer['uid'],'api');$tokens['customer']=$tokenInfo['customer']['token'];
    foreach($staff as$letter=>$actor){$tokenInfo[$letter]=$jwt->createToken($actor['service_id'],'kefu');$tokens[$letter]=$tokenInfo[$letter]['token'];}
    $cacheExpected=m4pc_warm_expected($app,$db,$prefix);
    foreach(array_merge([$customer['uid']],array_column($staff,'uid'))as$uid)m4pc_expect(m4pc_rows($db,"SELECT uid FROM eb_wechat_user WHERE uid=? AND user_type='wechat' AND subscribe<>0 AND openid<>''",[$uid])===[],
        'M4PC_SYNTHETIC_RECIPIENT_HAS_NO_EXTERNAL_WECHAT_CAPABILITY');
    m4pc_expect(m4pc_rows($db,"SELECT id FROM eb_system_event WHERE mark IN ('user_login','admin_login') AND is_del=0 AND is_open=1")===[],'M4PC_ORIGINAL_NO_CONFIGURED_EXTERNAL_LOGIN_EVENT');
    $product=m4pc_rows($db,'SELECT * FROM eb_store_product WHERE is_del=0 AND is_show=1 ORDER BY id LIMIT 1');m4pc_expect(count($product)===1,'M4PC_REAL_VISIBLE_LEGACY_PRODUCT');
    [$order,$orderCart]=m4pc_order_read_fixture($db,$app,$customer,$product[0],$m['run_id']);$created['eb_store_order'][]=$order;$created['eb_store_order_cart_info'][]=$orderCart;
    $after=m4pc_state($db);m4pc_prepare_redis($before,$after,$prefix,$tokens,$tokenInfo,$customer,$staff,$cacheExpected,$prepStart,time());$wanted=$before['database']['rows'];$increments=[];foreach($created as$table=>$rows){foreach($rows as$row)$wanted[$table][]=$row;$increments[$table]=count($rows);}
    // Exact fixture rows are independent insert inputs+real lastInsertId, not copied wholesale from after.
    foreach($wanted as$table=>$rows)m4pc_expect(m4pc_sorted($rows)===m4pc_sorted($after['database']['rows'][$table]),'M4PC_ALL_PREPARATION_ROWS_ORIGINALS_PRESERVED');
    m4pc_expect($after['database']['ddl']===$before['database']['ddl'],'M4PC_PREPARED_DDL_UNCHANGED');foreach($before['database']['next']as$table=>$next)
        m4pc_expect($after['database']['next'][$table]===($next===null?null:(string)((int)$next+($increments[$table]??0))),'M4PC_PREPARATION_NATIVE_COUNTERS_EXACT');
    m4pc_expect($after['private']===$before['private']&&$after['public']===$before['public']&&$after['runtime']===$before['runtime'],'M4PC_PREPARE_NO_BUSINESS_PRIVATE_OR_PUBLIC_WRITES');
    $fixture=['schema'=>1,'run_id'=>$m['run_id'],'source_sha'=>$m['commit_sha'],'prefix'=>$prefix,'customer'=>['uid'=>(int)$customer['uid'],'kind'=>'api','token'=>$tokens['customer'],'user'=>$customer],
        'a'=>$staff['a']+['kind'=>'kefu','token'=>$tokens['a']],'b'=>$staff['b']+['kind'=>'kefu','token'=>$tokens['b']],'legacy_guest_uid'=>$legacyGuest,
        'images'=>$images,'auto_reply'=>['keyword'=>$keyword,'content'=>$replyText,'key'=>$autoKey,'reply'=>$autoReply],
        'avatars'=>['customer'=>set_file_url($customer['avatar']),'a'=>set_file_url($staff['a']['service']['avatar']),'b'=>set_file_url($staff['b']['service']['avatar'])],
        'product'=>$product[0],'order'=>$order,'order_cart'=>$orderCart,'legacy'=>'/statics/system_images/admin_head_pic.png','voice'=>'/admin/system_static/media/notice.367f95a9.mp3','preflight'=>$preflight,'prepared_at'=>time(),
        'initial_fixture_rows'=>$created,'prepared_public_private_sha256'=>hash('sha256',serialize([$after['public'],$after['private']]))];
    m4pc_write(M4PC_FIXTURE,m4pc_json($fixture));m4pc_write('/run/audit-fixtures/platform-chat-prepared-full-state.serialized',serialize($after));
    return ['scope'=>'PLATFORM_CHAT_NORMAL_PREPARATION','status'=>'PREPARED_NOT_HTTP_WS_VERIFIED','source_sha'=>$m['commit_sha'],
        'new_synthetic_users'=>2,'new_synthetic_services'=>2,'private_fixture_rows'=>array_map('count',$created),'migrations'=>$migrations,
        'notification_seed_changed'=>false,'external'=>'NOT RUN','normal_HTTP_WS'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}

function m4pc_warm_expected(\think\App $app,PDO $db,string $prefix):array
{
    $values=[];foreach(m4pc_rows($db,'SELECT menu_name FROM eb_system_config ORDER BY id')as$row){$name=$row['menu_name'];
        $want=$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($name);$got=\crmeb\services\SystemConfigService::get($name);
        m4pc_expect($got===$want,'M4PC_SYSTEM_CACHE_ORIGINAL_DB_PRODUCER');$values['system_config_'.$name]=['value'=>$want,'ttl'=>0];}
    $orderKeys=['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time'];
    $orderConfig=$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigAll($orderKeys);
    m4pc_expect(is_array($orderConfig)&&array_diff(array_keys($orderConfig),$orderKeys)===[],'M4PC_FIXED_FIVE_ORIGINAL_DB_CONFIGURATION_KEYS');$orderExpected=[];
    foreach($orderKeys as$name)$orderExpected[$name]=$orderConfig[$name]??'';
    m4pc_expect(\crmeb\services\SystemConfigService::more($orderKeys)===$orderExpected,'M4PC_ORIGINAL_ORDER_COMPOUND_CACHE_COMPLETE_DB_VALUE');
    $values['system_config_'.md5(implode(',',$orderKeys))]=['value'=>$orderExpected,'ttl'=>0];
    $code=$app->make(\app\services\system\lang\LangCodeServices::class);$type=$app->make(\app\services\system\lang\LangTypeServices::class);$country=$app->make(\app\services\system\lang\LangCountryServices::class);
    $range=$type->value(['is_default'=>1],'file_name');m4pc_expect($range==='zh-CN','M4PC_INSTALLED_REAL_LANGUAGE_RANGE');$id=$country->value(['code'=>$range],'type_id')?:1;$types=$type->getColumn(['status'=>1,'is_del'=>0],'file_name','id');
    m4pc_expect(isset($types[$id]),'M4PC_INSTALLED_ENABLED_LANGUAGE');$language=['range_name'=>$range,'sys_lang_source_map'=>$code->getColumn(['type_id'=>1],'code','remarks'),
        'type_id_'.$range=>$id,'lang_type_data'=>$types,'lang_'.str_replace('-','_',$types[$id])=>$code->getColumn(['type_id'=>$id],'lang_explain','code')];
    foreach($language as$name=>$value)$values[$name]=['value'=>$value,'ttl'=>$name==='range_name'?0:3600];getLang('操作成功');
    foreach(['admin_login_slide','order_details_images']as$name){$group=$app->make(\app\services\system\config\SystemGroupDataServices::class)->getConfigNameValue($name,0);
        m4pc_expect(is_array($group)&&sys_data($name)===$group,'M4PC_ORIGINAL_COMPLETE_READ_GROUP_PRODUCER');$values['data_'.$name]=['value'=>$group,'ttl'=>0];}return $values;
}
function m4pc_prepare_redis(array $before,array $after,string $prefix,array $tokens,array $info,array $customer,array $staff,array $warm,int $start,int $end):void
{
    $want=$before['redis'];foreach($want as$key=>$entry)m4pc_expect(!str_starts_with($key,$prefix),'M4PC_NEW_CACHE_PREFIX_ABSENT');$tags=[];
    foreach($tokens as$name=>$token){$who=$name==='customer'?'api':'kefu';$id=$name==='customer'?(int)$customer['uid']:$staff[$name]['service_id'];$seconds=$info[$name]['params']['exp']-$info[$name]['params']['iat']+60;
        $key=$prefix.md5($token);$actual=$after['redis'][$key]??null;$value=serialize(['uid'=>$id,'type'=>$who,'token'=>$token,'exp'=>$seconds]);
        m4pc_expect(is_array($actual)&&$actual['type']===Redis::REDIS_STRING&&$actual['value']===$value&&$actual['expires']>=($start+$seconds)*1000&&$actual['expires']<=($end+$seconds+1)*1000,'M4PC_ORIGINAL_REAL_SESSION_VALUE_AND_ABSOLUTE_EXPIRY');
        $want[$key]=['type'=>Redis::REDIS_STRING,'value'=>$value,'expires'=>$actual['expires']];$tags[$who][]=$key;
    }
    foreach($warm as$name=>$source){$key=$prefix.$name;$value=is_numeric($source['value'])?(string)$source['value']:serialize($source['value']);$actual=$after['redis'][$key]??null;
        m4pc_expect(is_array($actual)&&$actual['type']===Redis::REDIS_STRING&&$actual['value']===$value,'M4PC_EXACT_WARM_SOURCE_SERIALIZATION');
        m4pc_expect($source['ttl']===0?$actual['expires']===-1:($actual['expires']>=($start+$source['ttl'])*1000&&$actual['expires']<=($end+$source['ttl']+1)*1000),'M4PC_EXACT_WARM_NATIVE_EXPIRY');
        $want[$key]=['type'=>Redis::REDIS_STRING,'value'=>$value,'expires'=>$actual['expires']];$tags['crmeb'][]=$key;
    }
    foreach($tags as$name=>$members){sort($members,SORT_STRING);$want[$prefix.'CRMEB:'.md5($name)]=['type'=>Redis::REDIS_SET,'value'=>$members,'expires'=>-1];}
    foreach($want as$key=>$entry)if(!isset($after['redis'][$key])&&$entry['expires']!==-1){m4pc_expect($entry['expires']<=$after['redis_clock'][1],'M4PC_PREPARE_OBSERVED_OLD_EXPIRY');unset($want[$key]);}
    ksort($want,SORT_STRING);m4pc_expect($want===$after['redis'],'M4PC_COMPLETE_PREPARATION_REDIS_NO_PREFIX_EXEMPTION');
}

function m4pc_auth_expected(PDO $db,array $before,array $after,array $uids,array $window):array
{
    $rows=$before['database']['rows']['eb_user'];foreach(array_unique($uids)as$uid){$got=m4pc_find($after['database']['rows']['eb_user'],'uid',$uid);$window['actual']=['last_time'=>$got['last_time']];
        $rows=m4pc_update_expected($db,$rows,'uid',$uid,['last_time'=>'@time'],$window);}return $rows;
}
function m4pc_login_step(PDO $db,string $name,array $actor):void
{
    $before=m4pc_state($db);$window=['start'=>time()];m4pc_ws($name,$actor);$window['end']=time();$after=m4pc_state($db);$changes=[];
    if($actor['kind']==='api')$changes['eb_user']=m4pc_auth_expected($db,$before,$after,[$actor['uid']],$window);
    if($actor['kind']!=='guest'){$records=$before['database']['rows']['eb_store_service_record'];foreach($records as&$row)if((int)$row['to_uid']===$actor['uid']){
            $row['online']=m4pc_number($db,1);if($actor['kind']==='api')$row['type']=m4pc_number($db,1);
        }unset($row);$changes['eb_store_service_record']=$records;}
    if($actor['kind']==='kefu'){$service=$before['database']['rows']['eb_store_service'];$row=m4pc_find($service,'id',$actor['service_id']);$row['online']=m4pc_number($db,1);$changes['eb_store_service']=m4pc_replace($service,'id',$actor['service_id'],$row);}
    m4pc_compare($before,$after,$changes);$GLOBALS['m4pc_cases'][]='actual_'.$actor['kind'].'_WS_login_full_side_effects';
}
function m4pc_room_step(PDO $db,string $name,int $peer,int $tourist):void
{
    $actor=$GLOBALS['m4pc_sockets'][$name]['actor'];$before=m4pc_state($db);$window=['start'=>time()];m4pc_room($name,$peer);$window['end']=time();$after=m4pc_state($db);$changes=[];
    $auth=$actor['kind']==='api'?[$actor['uid']]:[];if($actor['kind']==='kefu'&&$tourist===0)foreach($GLOBALS['m4pc_sockets']as$socket)if($socket['actor']['kind']==='api'&&$socket['actor']['uid']===$peer)$auth[]=$peer;
    if($auth!==[])$changes['eb_user']=m4pc_auth_expected($db,$before,$after,$auth,$window);
    if($actor['kind']!=='guest'){$records=$before['database']['rows']['eb_store_service_record'];foreach($records as&$row)if((int)$row['user_id']===$actor['uid']&&(int)$row['to_uid']===$peer&&(int)$row['is_tourist']===$tourist)$row['mssage_num']=m4pc_number($db,0);unset($row);
        $logs=$before['database']['rows']['eb_store_service_log'];foreach($logs as&$row)if((int)$row['uid']===$peer&&(int)$row['to_uid']===$actor['uid']&&(int)$row['is_tourist']===$tourist)$row['type']=m4pc_number($db,1);unset($row);
        $changes['eb_store_service_record']=$records;$changes['eb_store_service_log']=$logs;}
    m4pc_compare($before,$after,$changes);$GLOBALS['m4pc_cases'][]='actual_WS_room_entry_native_read_unread';
}
function m4pc_room_values(array $actor,array $peer,int $tourist):array
{
    $service=$actor['kind']==='kefu'?$actor:$peer;$customer=$actor['kind']==='kefu'?$peer:$actor;
    return ['customer_uid'=>$customer['uid'],'is_tourist'=>$tourist,'service_id'=>$service['service_id'],'service_uid'=>$service['uid']];
}
function m4pc_asset_expected(PDO $db,array $row,array $room,array $creator,array $image,array $window,string $state='STAGED',?array $first=null):array
{
    m4pc_expect(array_keys($row)===M4PC_ASSET_FIELDS,'M4PC_COMPLETE_PLATFORM29_NATIVE_COLUMNS');$id=\app\dao\marketplace\assets\PlatformChatAssetDao::assetId((string)$row['id']);
    $roomKey=hash('sha256',json_encode($room,JSON_THROW_ON_ERROR));$extension=pathinfo($image['name'],PATHINFO_EXTENSION);
    m4pc_expect(preg_match('~\Aplatform-chat/v1/'.preg_quote($roomKey,'~').'/[a-f0-9]{64}/content\.'.preg_quote($extension,'~').'\z~D',$row['object_key'])===1,'M4PC_REAL_NEW_ROOM_SCOPED_RANDOM_OBJECT_KEY');
    $created=$row['created_at'];m4pc_window($created,$window);$bound=null;if($state==='BOUND'){$bound=$row['bound_at'];m4pc_window($bound,$window);}
    $e=array_fill_keys(M4PC_ASSET_FIELDS,null);$e=array_replace($e,['id'=>m4pc_number($db,$id),'purpose'=>'platform_chat_image','category_id'=>m4pc_number($db,0),
        'state'=>$state,'storage_type'=>m4pc_number($db,1),'object_key'=>$row['object_key'],'thumbnail_key'=>'','name'=>'content.'.$extension,'real_name'=>$image['name'],
        'media_type'=>$image['mime'],'byte_size'=>m4pc_number($db,$image['bytes']),'width'=>m4pc_number($db,$image['width']),'height'=>m4pc_number($db,$image['height']),
        'content_sha256'=>hex2bin($image['sha256']),'created_at'=>$created,'expires_at'=>m4pc_number($db,(int)$created+3600),'bound_at'=>$bound,
        'created_by_service_id'=>$creator['kind']==='kefu'?m4pc_number($db,$creator['service_id']):null,'created_by_uid'=>$creator['kind']==='api'?m4pc_number($db,$creator['uid']):null,
        'chat_customer_uid'=>m4pc_number($db,$room['customer_uid']),'chat_is_tourist'=>m4pc_number($db,$room['is_tourist']),
        'chat_service_id'=>m4pc_number($db,$room['service_id']),'chat_service_uid'=>m4pc_number($db,$room['service_uid'])]);
    if($first!==null){$e=$first;$e['state']='BOUND';$e['bound_at']=$bound;}
    m4pc_expect($row===$e,'M4PC_COMPLETE_PLATFORM29_INDEPENDENT_INPUT_FORMULA');return $e;
}
function m4pc_new_private(array $before,array $after,array $assets,array $images,array $window):array
{
    $wanted=$before['private'];foreach($assets as$i=>$asset){$key=$asset['object_key'];$image=$images[$i];m4pc_expect(!isset($wanted['files'][$key])&&isset($after['private']['files'][$key]),'M4PC_ONE_NEW_PRIVATE_OBJECT');
        $native=$after['private']['files'][$key];m4pc_expect($native['bytes']===$image['bytes']&&$native['sha256']===$image['sha256']&&$native['mode']===0600
            &&$native['uid']===posix_geteuid()&&$native['nlink']===1,'M4PC_NEW_FULL_PRIVATE_BYTES_MODE_OWNER');
        $root=lstat(M4PC_PRIVATE_ROOT);m4pc_expect($native['native9']['dev']===$root['dev']&&$native['native9']['gid']===posix_getegid(),'M4PC_NEW_REGISTERED_VOLUME_NATIVE_FILE');
        foreach(['mtime','ctime']as$time)m4pc_window($native['native9'][$time],$window);foreach($wanted['files']as$old)m4pc_expect([$old['native9']['dev'],$old['native9']['ino']]!==[$native['native9']['dev'],$native['native9']['ino']],'M4PC_NEW_PRIVATE_NOT_EXISTING_FILE_IDENTITY');$wanted['files'][$key]=$native;
        $parent=dirname($key);while($parent!=='.'){if(!isset($wanted['directories'][$parent])){$d=$after['private']['directories'][$parent]??null;
                m4pc_expect(is_array($d)&&$d[2]===0700&&$d[3]===posix_geteuid(),'M4PC_NEW_PRIVATE_DIRECTORY_NATIVE_IDENTITY');$wanted['directories'][$parent]=$d;}$parent=dirname($parent);}
    }ksort($wanted['files'],SORT_STRING);ksort($wanted['directories'],SORT_STRING);m4pc_expect($after['private']===$wanted,'M4PC_NO_OTHER_PRIVATE_DELTA');return $wanted;
}
function m4pc_upload_step(PDO $db,array $fixture,array $actor,array $peer,int $tourist,array $image):array
{
    $before=m4pc_state($db);$path=$actor['kind']==='api'?'/api/user/service/files':'/kefuapi/service/files';
    $body=$actor['kind']==='api'?['service_uid'=>(string)$peer['uid']]:['peer_uid'=>(string)$peer['uid'],'is_tourist'=>(string)$tourist];
    $reply=m4pc_http('POST',$path,$actor,$body,$image);$dto=m4pc_success($reply);m4pc_expect(array_keys($dto)===['name','url','attachment_ref'],'M4PC_REAL_UPLOAD_FIXED_DTO');
    $id=\app\dao\marketplace\assets\PlatformChatAssetDao::referenceId($dto['attachment_ref']);$asset=m4pc_native_row($db,'eb_marketplace_platform_asset','id',$id);
    $room=m4pc_room_values($actor,$peer,$tourist);$expected=m4pc_asset_expected($db,$asset,$room,$actor,$image,$reply);
    m4pc_expect($dto['name']===$asset['name']&&$dto['url']===($actor['kind']==='api'?'/api/user':'/kefuapi').'/service/files/'.$id.'/content','M4PC_REAL_NONBEARER_UPLOAD_LOCATOR');
    $after=m4pc_state($db);$assets=$before['database']['rows']['eb_marketplace_platform_asset'];$assets[]=$expected;$changes=['eb_marketplace_platform_asset'=>$assets];
    if($actor['kind']==='api')$changes['eb_user']=m4pc_auth_expected($db,$before,$after,[$actor['uid']],$reply);
    $quota=$fixture['prefix'].'start_uploads_'.($actor['kind']==='kefu'?$actor['service_id']:$actor['uid']);$count=isset($before['redis'][$quota])?(int)$before['redis'][$quota]['value']:0;$entry=$after['redis'][$quota]??null;
    m4pc_expect(is_array($entry)&&$entry['type']===Redis::REDIS_STRING&&$entry['value']===(string)($count+1)&&$count<100
        &&$entry['expires']>=($reply['start']+86400)*1000&&$entry['expires']<=($reply['end']+86401)*1000,'M4PC_ORIGINAL_QUOTA_100_86400');
    $tag=$fixture['prefix'].'CRMEB:'.md5('crmeb');$members=$before['redis'][$tag]['value']??[];$members[]=$quota;$members=array_values(array_unique($members));sort($members,SORT_STRING);
    $redis=[$quota=>$entry,$tag=>['type'=>Redis::REDIS_SET,'value'=>$members,'expires'=>-1]];$private=m4pc_new_private($before,$after,[$asset],[$image],$reply);
    m4pc_compare($before,$after,$changes,['eb_marketplace_platform_asset'=>1],$redis,[],$private);$GLOBALS['m4pc_cases'][]='actual_'.$actor['kind'].'_multipart_'.$image['mime'].'_complete29_quota_private';
    return ['ref'=>$dto['attachment_ref'],'id'=>$id,'asset'=>$asset,'image'=>$image,'room'=>$room];
}
function m4pc_read_step(PDO $db,array $fixture,array $actor,array $asset,?string $message):void
{
    $base=($actor['kind']==='api'?'/api/user/service/files':($actor['kind']==='guest'?'/kefuapi/tourist/files':'/kefuapi/service/files')).'/'.$asset['id'];
    $before=m4pc_state($db);$reply=m4pc_http('POST',$base.'/read-ticket',$actor,$message===null?['mode'=>'preview']:['mode'=>'message','message_id'=>$message]);$dto=m4pc_success($reply);
    m4pc_expect(array_keys($dto)===['ticket','expires_in_ms']&&is_string($dto['ticket'])&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$dto['ticket'])===1
        &&is_int($dto['expires_in_ms'])&&$dto['expires_in_ms']>0&&$dto['expires_in_ms']<=300000,'M4PC_ACTUAL_READ_TICKET_DTO');
    $issued=m4pc_state($db);$key=$fixture['prefix'].'platform_chat_read_v1:'.hash('sha256',$dto['ticket']);$entry=$issued['redis'][$key]??null;
    m4pc_expect(!isset($before['redis'][$key])&&is_array($entry)&&$entry['type']===Redis::REDIS_STRING,'M4PC_NEW_FIXED_TICKET_KEY');$payload=json_decode($entry['value'],true,8,JSON_THROW_ON_ERROR);
    $r=$asset['room'];$expected=['schema'=>1,'reader_kind'=>$message===null?'preview':'message','app'=>$actor['kind']==='api'?'api':'kefuapi','actor_type'=>$actor['kind'],
        'actor_id'=>$actor['kind']==='kefu'?$actor['service_id']:$actor['uid'],'session_sha256'=>hash('sha256',$actor['token']),
        'customer_uid'=>$r['customer_uid'],'is_tourist'=>$r['is_tourist'],'service_id'=>$r['service_id'],'service_uid'=>$r['service_uid'],
        'asset_id'=>$asset['id'],'content_sha256'=>$asset['image']['sha256'],'message_id'=>$message===null?null:(int)$message,'purpose'=>'platform_chat_image'];
    m4pc_expect(is_int($payload['issued_at_ms']??null)&&$payload['issued_at_ms']>=$reply['start_ms']&&$payload['issued_at_ms']<=$reply['end_ms'],'M4PC_REAL_REDIS_TICKET_TIME_WINDOW');
    $expected+=['issued_at_ms'=>$payload['issued_at_ms'],'expires_at_ms'=>$payload['issued_at_ms']+300000,'scope'=>'platform_chat_material_read'];
    ksort($payload,SORT_STRING);ksort($expected,SORT_STRING);m4pc_expect($payload===$expected&&$entry['expires']===$expected['expires_at_ms']&&\app\services\marketplace\order\MarketplaceOrderInput::canonical($expected)===$entry['value'],'M4PC_FULL_EXACT_TICKET_BINDING_DEADLINE_CANONICAL');
    $changes=[];if($actor['kind']==='api')$changes['eb_user']=m4pc_auth_expected($db,$before,$issued,[$actor['uid']],$reply);m4pc_compare($before,$issued,$changes,[],[$key=>$entry]);
    $content=m4pc_http('GET',$base.'/content',$actor,null,null,$dto['ticket']);$image=$asset['image'];$headers=$content['headers'];
    m4pc_expect($content['http']===200&&strlen($content['raw'])===$image['bytes']&&hash('sha256',$content['raw'])===$image['sha256']
        &&($headers['content-type']??null)===$image['mime']&&($headers['content-length']??null)===(string)$image['bytes']
        &&($headers['x-content-type-options']??null)==='nosniff'&&str_contains(strtolower($headers['cache-control']??''),'no-store')
        &&($headers['pragma']??null)==='no-cache'&&($headers['expires']??null)==='0'&&!isset($headers['set-cookie']),'M4PC_REAL_AUTHORIZED_WHOLE_ORIGINAL_IMAGE');
    $after=m4pc_state($db);$changes=[];if($actor['kind']==='api')$changes['eb_user']=m4pc_auth_expected($db,$issued,$after,[$actor['uid']],$content);
    m4pc_compare($issued,$after,$changes,[],[],[$key]);$GLOBALS['m4pc_cases'][]='actual_'.$actor['kind'].'_'.($message===null?'STAGED_preview':'BOUND_message').'_ticket_single_consume_fullbytes';
}

function m4pc_expected_log(PDO $db,int|string $id,int $from,int $to,int $tourist,int $type,string $msn,int $msnType,int|string $at,array $window):array
{
    m4pc_window($at,$window);return ['id'=>m4pc_number($db,$id),'mer_id'=>m4pc_number($db,0),'msn'=>$msn,'uid'=>m4pc_number($db,$from),
        'to_uid'=>m4pc_number($db,$to),'is_tourist'=>m4pc_number($db,$tourist),'time_node'=>m4pc_number($db,0),'add_time'=>$at,
        'type'=>m4pc_number($db,$type),'remind'=>m4pc_number($db,0),'msn_type'=>m4pc_number($db,$msnType)];
}
function m4pc_record_formula(PDO $db,array $rows,int $from,int $to,int $tourist,string $message,int $messageType,int $unread,string $nickname,string $avatar,array $window,?array $receipt=null):array
{
    $reverse=[];foreach($rows as$i=>$row)if((int)$row['user_id']===$to&&(int)$row['to_uid']===$from&&(int)$row['is_tourist']===$tourist)$reverse[]=$i;
    m4pc_expect(count($reverse)<=1,'M4PC_UNIQUE_ORIGINAL_RECORD_PAIR');$actual=$window['record'];m4pc_expect(array_keys($actual)===M4PC_RECORD_FIELDS,'M4PC_COMPLETE_NATIVE_RECORD_COLUMNS');m4pc_window($actual['update_time'],$window);
    if($reverse!==[]){$i=$reverse[0];$want=$rows[$i];$want['type']=m4pc_number($db,1);$want['message']=$message;$want['message_type']=m4pc_number($db,$messageType);$want['update_time']=$actual['update_time'];$want['mssage_num']=m4pc_number($db,$unread);
        if($avatar!=='')$want['avatar']=$avatar;if($nickname!=='')$want['nickname']=$nickname;m4pc_expect($actual===$want,'M4PC_ORIGINAL_REVERSE_RECORD_FULL_FORMULA');$rows[$i]=$want;
    }else{m4pc_expect(is_array($receipt)&&(int)($receipt['id']??0)===(int)$actual['id'],'M4PC_NEW_RECORD_ID_FROM_REAL_RECIPIENT_EVENT');m4pc_window($actual['add_time'],$window);
        $want=['id'=>$actual['id'],'user_id'=>m4pc_number($db,$to),'to_uid'=>m4pc_number($db,$from),'nickname'=>$nickname,'avatar'=>$avatar,'is_tourist'=>m4pc_number($db,$tourist),'online'=>m4pc_number($db,0),
            'type'=>m4pc_number($db,1),'add_time'=>$actual['add_time'],'update_time'=>$actual['update_time'],'mssage_num'=>m4pc_number($db,$unread),'message'=>$message,'message_type'=>m4pc_number($db,$messageType)];
        m4pc_expect($actual===$want,'M4PC_COMPLETE_NEW_RECORD_FROM_INPUT_AND_REAL_PK');$wire=$want;unset($wire['online']);m4pc_expect((is_int($receipt['id'])||is_string($receipt['id']))&&(string)$receipt['id']===(string)$want['id'],'M4PC_NATIVE_NEW_RECORD_WIRE_PK');$receipt['id']=(int)$receipt['id'];m4pc_expect(\app\services\marketplace\order\MarketplaceOrderInput::canonical($receipt)===\app\services\marketplace\order\MarketplaceOrderInput::canonical($wire),'M4PC_NEW_RECORD_FULL_REAL_MODEL_DTO');$rows[]=$want;
    }
    foreach($rows as&$row)if((int)$row['user_id']===$from&&(int)$row['to_uid']===$to&&(int)$row['is_tourist']===$tourist){$row['message']=$message;$row['message_type']=m4pc_number($db,$messageType);$row['update_time']=$actual['update_time'];}unset($row);return $rows;
}
function m4pc_record_native(PDO $db,int $owner,int $peer,int $tourist):array
{
    $rows=m4pc_rows($db,'SELECT * FROM eb_store_service_record WHERE user_id=? AND to_uid=? AND is_tourist=?',[$owner,$peer,$tourist]);m4pc_expect(count($rows)===1,'M4PC_NATIVE_EXACT_RECORD_PAIR');return $rows[0];
}
/** Independent normal-fixture enrichment formulas from native inputs, not copied from the received WS DTO. */
function m4pc_enrichment(PDO $db,array $fixture,int $msnType,string $msn,int $tourist):array
{
    $product=[];$order=[];
    if($msnType===5&&$msn!==''){$rows=m4pc_rows($db,'SELECT store_name,IFNULL(sales,0)+IFNULL(ficti,0) AS sales,image,slider_image,price,vip_price,ot_price,stock,id FROM eb_store_product WHERE id=? AND is_del=0 AND is_show=1',[(int)$msn]);
        m4pc_expect(count($rows)===1&&(int)$rows[0]['id']===(int)$fixture['product']['id'],'M4PC_REAL_TYPE5_NATIVE_PRODUCT');$product=$rows[0];$product['slider_image']=is_string($product['slider_image'])?json_decode($product['slider_image'],true,32,JSON_THROW_ON_ERROR):[];}
    if($msnType===6&&$tourist===0&&$msn!==''){
        $order=$fixture['order'];$cartRow=$fixture['order_cart'];m4pc_expect($msn===$order['order_id']&&(int)$order['uid']===$fixture['customer']['uid']
            &&(int)$order['gift_uid']===0&&(int)$order['paid']===0&&$order['pay_type']===''&&(int)$order['is_cancel']===0,'M4PC_TYPE6_ONLY_ADOPTED_SYNTHETIC_UNPAID_OWNER_READ');
        $order['cart_id']=$order['cart_id']?json_decode($order['cart_id'],true,16,JSON_THROW_ON_ERROR):[];$order['custom_form']=is_string($order['custom_form'])?(json_decode($order['custom_form'],true)??[]):[];
        $cart=json_decode($cartRow['cart_info'],true,64,JSON_THROW_ON_ERROR);$cart['cart_num']=$cartRow['cart_num'];$cart['surplus_num']=$cartRow['surplus_num'];$cart['refund_num']=$cartRow['refund_num'];
        $cart['surplus_refund_num']=$cartRow['surplus_num']-$cartRow['refund_num'];$cart['unique']=$cartRow['unique'];
        $replies=(int)m4pc_rows($db,'SELECT COUNT(*) AS n FROM eb_store_product_reply WHERE `unique`=?',[$cartRow['unique']])[0]['n'];m4pc_expect($replies===0,'M4PC_NEW_SYNTHETIC_CART_NO_REPLY');$cart['is_reply']=$replies;
        m4pc_expect(!sys_config('image_thumb_status',0),'M4PC_INSTALLED_THUMB_OFF_READ_PRECONDITION_NO_SETTING_CHANGE');
        $cart['vip_sum_truePrice']=bcmul((string)$cart['vip_truePrice'],(string)$cart['cart_num'],2);$cart['is_valid']=1;$order['cartInfo']=[$cart];
        $cancel=sys_config('order_cancel_time',0);$seconds=is_string($cancel)?(float)$cancel:$cancel;$stop=$seconds*3600+$order['add_time'];
        $delivery=['send'=>'商家配送','express'=>'快递配送','fictitious'=>'虚拟发货','delivery_part_split'=>'拆分部分发货','delivery_split'=>'拆分发货完成'];
        $order['_status']=['_type'=>0,'_title'=>'未支付','_msg'=>'请在'.date('m-d H:i:s',(int)$stop).'前完成支付!','_class'=>'nobuy','_payType'=>'','_deliveryType'=>$delivery[$order['delivery_type']]??'其他方式'];
        $order['_pay_time']='';$order['_add_time']=date('Y-m-d H:i:s',(int)$order['add_time']);$order['stop_time']=$stop;$order['status_pic']='';
        foreach(sys_data('order_details_images')?:[]as$image)if(isset($image['order_status'])&&$image['order_status']==0){$order['status_pic']=$image['pic'];break;}
        $order['offlinePayStatus']=(int)sys_config('offline_pay_status');
        m4pc_expect(m4pc_rows($db,'SELECT * FROM eb_store_order_status WHERE oid=?',[$order['id']])===[],'M4PC_READ_FIXTURE_NO_TRADE_STATUS_ROWS');
        $order['order_log']=['create'=>'','pay'=>'','delivery'=>'','take'=>'','complete'=>''];$order['gift_user_info']=['gift_uid'=>$order['gift_uid'],'gift_nickname'=>'','gift_avatar'=>''];
        $order['add_time_y']=date('Y-m-d',(int)$order['add_time']);$order['add_time_h']=date('H:i:s',(int)$order['add_time']);
    }
    return ['productInfo'=>$product,'orderInfo'=>$order];
}
function m4pc_main_wire(array $dto,array $expected,string $nickname,string $avatar,array $enrichment):void
{
    $wanted=['to_uid'=>(int)$expected['to_uid'],'msn_type'=>(int)$expected['msn_type'],'msn'=>$expected['msn'],'uid'=>(int)$expected['uid'],'add_time'=>(int)$expected['add_time'],
        'is_tourist'=>(int)$expected['is_tourist'],'type'=>(int)$expected['type'],'id'=>(int)$expected['id'],'_add_time'=>date('Y-m-d H:i:s',(int)$expected['add_time']),
        'nickname'=>$nickname,'avatar'=>$avatar]+$enrichment;
    m4pc_expect(isset($dto['id'])&&(is_int($dto['id'])||is_string($dto['id']))&&(string)$dto['id']===(string)$expected['id'],'M4PC_REAL_WIRE_PK_VALUE');$dto['id']=(int)$dto['id'];
    // The actual JSON wire legitimately normalizes integral PHP floats; no expected field comes from this DTO.
    $wanted=json_decode(json_encode($wanted,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
    m4pc_expect(\app\services\marketplace\order\MarketplaceOrderInput::canonical($dto)===\app\services\marketplace\order\MarketplaceOrderInput::canonical($wanted),'M4PC_FULL_ORIGINAL_ACK_AND_ENRICHMENT_DTO');
}
function m4pc_chat_step(PDO $db,array $fixture,string $sender,?string $receiver,array $peer,int $tourist,int $msnType,string $msn,?array $asset=null,bool $auto=false):array
{
    $actor=$GLOBALS['m4pc_sockets'][$sender]['actor'];$before=m4pc_state($db);$enrichment=m4pc_enrichment($db,$fixture,$msnType,$msn,$tourist);$window=['start'=>time()];
    $clean=trim(strip_tags(str_replace(["\n","\t","\r",'&nbsp;'],'',htmlspecialchars_decode($msn))));m4pc_expect($clean===$msn,'M4PC_NORMAL_CANONICAL_MESSAGE_INPUT');
    $keys=app()->make(\app\services\wechat\WechatKeyServices::class);$replyId=$keys->value(['keys'=>$msn,'key_type'=>1],'reply_id');if(!$replyId)$replyId=$keys->value(['keys_like'=>$msn,'key_type'=>1],'reply_id');
    m4pc_expect($actor['kind']==='kefu'||($auto?(int)$replyId===(int)$fixture['auto_reply']['reply']['id']:!$replyId),'M4PC_EXACT_REAL_AUTO_REPLY_PRECONDITION');
    m4pc_ws_send($sender,['type'=>'chat','form_type'=>1,'data'=>['to_uid'=>$peer['uid'],'type'=>$msnType,'msn'=>$msn]]);
    $ack=m4pc_ws_wait($sender,'chat',static fn(array $d):bool=>($d['msn']??null)===$msn&&(int)($d['uid']??0)===$actor['uid']&&(int)($d['to_uid']??0)===$peer['uid']);
    $id=(string)($ack['data']['id']??'');m4pc_expect(preg_match('/\A[1-9][0-9]*\z/D',$id)===1,'M4PC_MAIN_REAL_SELF_ACK_NEW_PK');
    $delivery=null;if($receiver!==null)$delivery=m4pc_ws_wait($receiver,'reply',static fn(array $d):bool=>(string)($d['id']??'')===$id&&($d['msn']??null)===$msn);
    else{$deadline=microtime(true)+8;do{$r=m4pc_record_native($db,$peer['uid'],$actor['uid'],$tourist);if($r['message']===$msn)break;usleep(10000);}while(microtime(true)<$deadline);m4pc_expect($r['message']===$msn,'M4PC_OFFLINE_RECORD_COMPLETES_AFTER_EARLY_ACK');}
    $autoEvent=null;if($auto)$autoEvent=m4pc_ws_wait($sender,'reply',static fn(array $d):bool=>($d['msn']??null)===$fixture['auto_reply']['content']&&(int)($d['uid']??0)===$peer['uid']);
    $window['end']=time();$after=m4pc_state($db);$log=m4pc_find($after['database']['rows']['eb_store_service_log'],'id',$id);
    m4pc_expect(!array_filter($before['database']['rows']['eb_store_service_log'],static fn(array $r):bool=>(string)$r['id']===$id),'M4PC_ACK_IS_THIS_NEW_LOG_NOT_OLD_BOUND');
    $expected=m4pc_expected_log($db,$id,$actor['uid'],$peer['uid'],$tourist,$receiver!==null?1:0,$msn,$msnType,$log['add_time'],$window);
    m4pc_expect($log===$expected&&($ack['data']['msn_type']??null)==$msnType,'M4PC_REAL_COMPLETE_MAIN11_WITH_ONLINE_BIT');
    $logs=$before['database']['rows']['eb_store_service_log'];$logs[]=$expected;$changes=['eb_store_service_log'=>$logs];$increments=['eb_store_service_log'=>1];
    $auth=[];if($actor['kind']==='api')$auth[]=$actor['uid'];if($receiver!==null&&$peer['kind']==='api')$auth[]=$peer['uid'];if($auth!==[])$changes['eb_user']=m4pc_auth_expected($db,$before,$after,$auth,$window);
    $nickname=$actor['kind']==='guest'?'游客'.abs($actor['uid']):($actor['kind']==='kefu'?$actor['service']['nickname']:$actor['user']['nickname']);
    $avatar=$actor['kind']==='guest'?set_file_url($actor['avatar']):($actor['kind']==='api'?$fixture['avatars']['customer']:$fixture['avatars'][$sender]);
    m4pc_main_wire($ack['data'],$expected,$nickname,$avatar,$enrichment);if($delivery!==null){$wire=$delivery['data'];m4pc_expect(is_array($wire['recored']??null),'M4PC_REAL_DELIVERY_RECORD');unset($wire['recored']);m4pc_main_wire($wire,$expected,$nickname,$avatar,$enrichment);}
    $unread=count(array_filter($logs,static fn(array $r):bool=>(int)$r['uid']===$actor['uid']&&(int)$r['to_uid']===$peer['uid']&&(int)$r['type']===0&&(int)$r['is_tourist']===$tourist));
    $records=$before['database']['rows']['eb_store_service_record'];
    if(!$auto){$window['record']=m4pc_record_native($db,$peer['uid'],$actor['uid'],$tourist);$records=m4pc_record_formula($db,$records,$actor['uid'],$peer['uid'],$tourist,$msn,$msnType,$unread,$nickname,$avatar,$window,$delivery['data']['recored']??null);}
    else{
        $mainRecord=$delivery['data']['recored']??null;m4pc_expect(is_array($mainRecord),'M4PC_MAIN_DELIVERY_RECORD_BEFORE_AUTO_REPLY');$window['record']=$mainRecord;
        // DTO add/update time columns are native untouched record values, separate from main log time formatting.
        $records=m4pc_record_formula($db,$records,$actor['uid'],$peer['uid'],$tourist,$msn,$msnType,$unread,$nickname,$avatar,$window,$mainRecord);
        $autoId=(string)($autoEvent['data']['id']??'');m4pc_expect($autoId!==$id&&preg_match('/\A[1-9][0-9]*\z/D',$autoId)===1,'M4PC_REAL_AUTO_REPLY_CREATED_PK');
        $autoRow=m4pc_find($after['database']['rows']['eb_store_service_log'],'id',$autoId);$autoExpected=m4pc_expected_log($db,$autoId,$peer['uid'],$actor['uid'],$tourist,1,$fixture['auto_reply']['content'],1,$autoRow['add_time'],$window);
        m4pc_expect($autoRow===$autoExpected,'M4PC_COMPLETE_ORIGINAL_AUTO_REPLY_LOG');$logs[]=$autoExpected;$changes['eb_store_service_log']=$logs;$increments['eb_store_service_log']=2;
        $window['record']=m4pc_record_native($db,$actor['uid'],$peer['uid'],$tourist);$count=count(array_filter($logs,static fn(array $r):bool=>(int)$r['uid']===$peer['uid']&&(int)$r['to_uid']===$actor['uid']&&(int)$r['type']===0&&(int)$r['is_tourist']===$tourist));
        $records=m4pc_record_formula($db,$records,$peer['uid'],$actor['uid'],$tourist,$fixture['auto_reply']['content'],1,$count,$peer['service']['nickname'],$peer['service']['avatar'],$window,$autoEvent['data']['recored']??null);
        $autoWire=$autoEvent['data'];$recordWire=$autoWire['recored']??null;unset($autoWire['recored']);$autoWant=['to_uid'=>$actor['uid'],'msn_type'=>1,'msn'=>$fixture['auto_reply']['content'],'uid'=>$peer['uid'],'add_time'=>(int)$autoExpected['add_time'],'is_tourist'=>$tourist,'type'=>1,'id'=>(int)$autoId,'_add_time'=>date('Y-m-d H:i:s',(int)$autoExpected['add_time'])];
        m4pc_expect(is_array($recordWire)&&\app\services\marketplace\order\MarketplaceOrderInput::canonical($recordWire)===\app\services\marketplace\order\MarketplaceOrderInput::canonical($window['record']),'M4PC_COMPLETE_AUTO_REPLY_RECORD_DTO');
        m4pc_expect(isset($autoWire['id'])&&(string)$autoWire['id']===$autoId,'M4PC_AUTO_REPLY_REAL_WIRE_ID');$autoWire['id']=(int)$autoWire['id'];m4pc_expect(\app\services\marketplace\order\MarketplaceOrderInput::canonical($autoWire)===\app\services\marketplace\order\MarketplaceOrderInput::canonical($autoWant),'M4PC_COMPLETE_ORIGINAL_AUTO_REPLY_ACK_DTO');
    }
    $changes['eb_store_service_record']=$records;$newRecordCount=count($records)-count($before['database']['rows']['eb_store_service_record']);
    m4pc_expect($newRecordCount>=0&&$newRecordCount<=2,'M4PC_ONLY_ACTUAL_RECORD_INSERTS');if($newRecordCount>0)$increments['eb_store_service_record']=$newRecordCount;
    if($asset!==null){$assets=$before['database']['rows']['eb_marketplace_platform_asset'];$old=m4pc_find($assets,'id',$asset['id']);$got=m4pc_find($after['database']['rows']['eb_marketplace_platform_asset'],'id',$asset['id']);
        $wanted=$old;if($old['state']==='STAGED'){m4pc_window($got['bound_at'],$window);$wanted['state']='BOUND';$wanted['bound_at']=$got['bound_at'];}m4pc_expect($got===$wanted,'M4PC_ONLY_FIRST_BOUND_AT_CHANGE');
        $changes['eb_marketplace_platform_asset']=m4pc_replace($assets,'id',$asset['id'],$wanted);$asset['asset']=$wanted;}
    m4pc_compare($before,$after,$changes,$increments);$GLOBALS['m4pc_cases'][]='actual_'.($actor['kind']).'_WS_type'.$msnType.($asset!==null?'_private_full11_bound29':'_original_legacy').($auto?'_real_auto_reply':'');
    return ['id'=>$id,'log'=>$expected,'asset'=>$asset,'ack'=>$ack['data']];
}

function m4pc_guest_step(PDO $db,array $fixture):array
{
    $before=m4pc_state($db);$reply=m4pc_http('GET','/kefuapi/tourist/user');$data=m4pc_success($reply);
    m4pc_expect(is_int($data['tourist_uid']??null)&&$data['tourist_uid']<0&&$data['tourist_uid']>=-2147483647
        &&is_string($data['guest_token']??null)&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$data['guest_token'])===1
        &&is_int($data['guest_expires_at']??null)&&$data['guest_expires_at']>=$reply['start']+3600&&$data['guest_expires_at']<=$reply['end']+3600
        &&in_array((int)$data['uid'],[$fixture['a']['uid'],$fixture['b']['uid']],true),'M4PC_ACTUAL_GUEST_ISSUER_CAP_UID_SERVICE');
    $after=m4pc_state($db);$rows=$before['database']['rows']['eb_cache'];$old=array_values(array_filter($rows,static fn(array $r):bool=>$r['key']==='kefu_tourist_uid_seq'));
    m4pc_expect(count($old)<=1,'M4PC_ORIGINAL_SINGLE_GUEST_COUNTER');$previous=$old===[]?0:(int)$old[0]['result'];m4pc_expect($data['tourist_uid']===-($previous+1),'M4PC_ACTUAL_GUEST_UID_FROM_COMMITTED_COUNTER');
    $actual=m4pc_find($after['database']['rows']['eb_cache'],'key','kefu_tourist_uid_seq');
    if($old===[]){m4pc_window($actual['add_time'],$reply);$wanted=['key'=>'kefu_tourist_uid_seq','result'=>(string)($previous+1),'expire_time'=>m4pc_number($db,0),'add_time'=>$actual['add_time']];$rows[]=$wanted;}
    else{$wanted=$old[0];$wanted['result']=(string)($previous+1);$rows=m4pc_replace($rows,'key','kefu_tourist_uid_seq',$wanted);}
    m4pc_expect($actual===$wanted,'M4PC_COMPLETE_GUEST_COUNTER_COMMIT_ROW');$decoded=base64_decode(strtr($data['guest_token'],'-_','+/').'=',true);m4pc_expect(is_string($decoded)&&strlen($decoded)===32,'M4PC_GUEST_REAL_ENTROPY_CAP');
    $digest=hash('sha256',$decoded);$prefix='chat:guest:v1:'.audit_config()['auth']['session_epoch'].':';$payload=['token_digest'=>$digest,'uid'=>$data['tourist_uid'],'avatar'=>$data['tourist_avatar'],
        'purpose'=>\crmeb\services\workerman\chat\GuestChatSession::PURPOSE,'expires_at'=>$data['guest_expires_at']];
    $adds=[$prefix.$digest=>['type'=>Redis::REDIS_STRING,'value'=>json_encode($payload,JSON_THROW_ON_ERROR),'expires'=>$data['guest_expires_at']*1000],
        $prefix.'uid:'.$data['tourist_uid']=>['type'=>Redis::REDIS_STRING,'value'=>$digest,'expires'=>$data['guest_expires_at']*1000]];
    foreach($adds as$key=>$value)m4pc_expect(!isset($before['redis'][$key])&&($after['redis'][$key]??null)===$value,'M4PC_COMPLETE_GUEST_CAP_UID_PAIR_NATIVE_DEADLINE');
    m4pc_compare($before,$after,['eb_cache'=>$rows],[],$adds);$GLOBALS['m4pc_cases'][]='actual_original_guest_HTTP_issuer_counter_cap_absolute_expiry';
    return ['kind'=>'guest','uid'=>$data['tourist_uid'],'token'=>$data['guest_token'],'avatar'=>$data['tourist_avatar'],'expires_at'=>$data['guest_expires_at']];
}
function m4pc_capabilities_step(PDO $db,array $actor,bool $writes):void
{
    $before=m4pc_state($db);$base=$actor['kind']==='api'?'/api/user/service/files':($actor['kind']==='guest'?'/kefuapi/tourist/files':'/kefuapi/service/files');$reply=m4pc_http('GET',$base.'/capabilities',$actor);
    $data=m4pc_success($reply);m4pc_expect($data===['protocol'=>1,'private_writes_enabled'=>$writes&&$actor['kind']!=='guest','private_read_supported'=>true],'M4PC_REAL_CAPABILITIES_EXACT_CONFIGURATION');
    $after=m4pc_state($db);$changes=[];if($actor['kind']==='api')$changes['eb_user']=m4pc_auth_expected($db,$before,$after,[$actor['uid']],$reply);m4pc_compare($before,$after,$changes);
    $GLOBALS['m4pc_cases'][]='actual_'.$actor['kind'].'_capabilities_'.($writes?'on':'default_or_saved_read_off');
}

/** Isolated read fixture only. No create-order, confirmation, payment or trade acceptance is claimed. */
function m4pc_defaults(PDO $db,string $table,array $values):array
{
    m4pc_expect(in_array($table,['eb_store_order','eb_store_order_cart_info'],true),'M4PC_FIXED_ORDER_READ_FIXTURE_TABLE');$columns=m4pc_rows($db,'SHOW FULL COLUMNS FROM `'.$table.'`');$data=[];
    m4pc_expect(array_diff(array_keys($values),array_column($columns,'Field'))===[],'M4PC_NO_INVENTED_ORDER_FIELDS');foreach($columns as$column){$name=$column['Field'];if($column['Extra']==='auto_increment')continue;
        $value=array_key_exists($name,$values)?$values[$name]:$column['Default'];m4pc_expect($value!==null||$column['Null']==='YES','M4PC_EXPLICIT_REQUIRED_ORDER_INPUT');
        if($value!==null&&preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)\b/',$column['Type'])===1)$value=m4pc_number($db,$value);$data[$name]=$value;
    }return $data;
}
function m4pc_order_read_fixture(PDO $db,\think\App $app,array $owner,array $product,string $run):array
{
    $number='audit-chat-'.$run;$unique=md5($number);$cartKey='audit-chat-cart-'.$run;$unit=(string)$product['price'];
    m4pc_expect(bccomp($unit,'0',2)>0&&(int)$product['is_del']===0&&(int)$product['is_show']===1,'M4PC_ACTUAL_VISIBLE_PRODUCT_UNIT_PRICE');
    m4pc_expect(m4pc_rows($db,'SELECT id FROM eb_store_order WHERE order_id=? OR `unique`=?',[$number,$unique])===[],'M4PC_NEW_READ_FIXTURE_LOCATORS');
    $values=['uid'=>$owner['uid'],'gift_uid'=>0,'order_id'=>$number,'unique'=>$unique,'cart_id'=>json_encode([$cartKey],JSON_THROW_ON_ERROR),'total_num'=>1,
        'total_price'=>$unit,'pay_price'=>$unit,'add_time'=>time(),'paid'=>0,'pay_time'=>0,'pay_type'=>'','is_del'=>0,'is_cancel'=>0,'status'=>0,
        'refund_status'=>0,'refund_type'=>0,'pink_id'=>0,'combination_id'=>0,'seckill_id'=>0,'bargain_id'=>0,'advance_id'=>0,'shipping_type'=>1];
    $orderInput=m4pc_defaults($db,'eb_store_order',$values);$order=m4pc_insert($db,'eb_store_order',$orderInput);
    $model=$app->make(\app\services\product\product\StoreProductServices::class)->getProductInfo((int)$product['id']);
    m4pc_expect($model instanceof \app\model\product\product\StoreProduct&&(int)$model->id===(int)$product['id'],'M4PC_REAL_EXISTING_PRODUCT_READ_MODEL');$productInfo=$model->toArray();
    $cart=['id'=>$cartKey,'uid'=>(int)$owner['uid'],'product_id'=>(int)$product['id'],'cart_num'=>1,'truePrice'=>$unit,'vip_truePrice'=>$unit,
        'sum_price'=>$unit,'costPrice'=>(string)$product['cost'],'productInfo'=>$productInfo];
    $cartInput=m4pc_defaults($db,'eb_store_order_cart_info',['oid'=>$order['id'],'uid'=>$owner['uid'],'cart_id'=>$cartKey,'product_id'=>$product['id'],
        'cart_num'=>1,'refund_num'=>0,'surplus_num'=>1,'split_status'=>0,'cart_info'=>json_encode($cart,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'unique'=>md5($cartKey)]);
    $cartRow=m4pc_insert($db,'eb_store_order_cart_info',$cartInput);m4pc_expect((int)$cartRow['oid']===(int)$order['id']&&(int)$cartRow['uid']===(int)$owner['uid'],
        'M4PC_ACTUAL_ORDER_CART_PK_OWNER_JOIN');return [$order,$cartRow];
}

function m4pc_transfer_step(PDO $db,array $fixture,string $source,string $target,array $customer,?string $customerSocket,int $tourist,array $knownImages):array
{
    $actor=$fixture[$source];$destination=$fixture[$target];$before=m4pc_state($db);$sourceRecord=m4pc_record_native($db,$actor['uid'],$customer['uid'],$tourist);
    m4pc_expect((int)$sourceRecord['type']===1,'M4PC_ORIGINAL_TRANSFER_SOURCE_RECORD_TYPE_ONE');m4pc_record_native($db,$destination['uid'],$customer['uid'],$tourist);m4pc_record_native($db,$customer['uid'],$destination['uid'],$tourist);
    $aux=array_values(array_filter($before['database']['rows']['eb_auxiliary'],static fn(array $r):bool=>(int)$r['binding_id']===$actor['uid']&&(int)$r['relation_id']===$customer['uid']&&(int)$r['type']===0));m4pc_expect(count($aux)<=1,'M4PC_REAL_SINGLE_ORIGINAL_TRANSFER_CUTOFF');
    $sql='SELECT * FROM eb_store_service_log WHERE uid IN (?,?) AND to_uid IN (?,?) AND is_tourist=?';$args=[$actor['uid'],$customer['uid'],$actor['uid'],$customer['uid'],$tourist];
    if($aux!==[]&&$aux[0]['update_time']){$sql.=' AND add_time>?';$args[]=$aux[0]['update_time'];}$selected=m4pc_rows($db,$sql,$args);
    m4pc_expect(count($selected)>=2,'M4PC_FULL_SELECTED_MIXED_TRANSFER_HISTORY');$distinct=[];
    foreach($selected as$row)if((int)$row['msn_type']===3&&\app\dao\marketplace\assets\PlatformChatAssetDao::isPrivate($row['msn'])){
        $id=\app\dao\marketplace\assets\PlatformChatAssetDao::referenceId($row['msn']);if(!isset($distinct[$id]))$distinct[$id]=m4pc_find($before['database']['rows']['eb_marketplace_platform_asset'],'id',$id);}
    m4pc_expect(count($distinct)>=1,'M4PC_ACTUAL_PRIVATE_HISTORY_TRANSFER');$reply=m4pc_http('POST','/kefuapi/service/transfer',$actor,['kefuToUid'=>$destination['uid'],'uid'=>$customer['uid']]);m4pc_success($reply);
    $notice=m4pc_ws_wait($target,'transfer',static fn(array $d):bool=>(int)($d['recored']['user_id']??0)===$destination['uid']&&(int)($d['recored']['to_uid']??0)===$customer['uid']);
    $customerNotice=null;if($customerSocket!==null)$customerNotice=m4pc_ws_wait($customerSocket,'to_transfer',static fn(array $d):bool=>(int)($d['toUid']??0)===$destination['uid']&&(int)($d['is_tourist']??-1)===$tourist);
    $after=m4pc_state($db);$oldAssetIds=array_column($before['database']['rows']['eb_marketplace_platform_asset'],'id');$newAssets=array_values(array_filter($after['database']['rows']['eb_marketplace_platform_asset'],
        static fn(array $r):bool=>!in_array($r['id'],$oldAssetIds,true)));
    m4pc_expect(count($newAssets)===count($distinct),'M4PC_EACH_DISTINCT_SOURCE_EXACTLY_ONE_NEW_ASSET');
    $oldLogIds=array_column($before['database']['rows']['eb_store_service_log'],'id');$newLogs=array_values(array_filter($after['database']['rows']['eb_store_service_log'],static fn(array $r):bool=>!in_array($r['id'],$oldLogIds,true)));
    m4pc_expect(count($newLogs)===count($selected),'M4PC_ALL_SELECTED_LOGS_COPIED_NO_TRUNCATION');
    // These are real observed PKs, never before-MAX/next+ordinal guesses. The frozen producer's actual saveAll verifies keyed Models before commit.
    $decimal=static fn(array $a,array $b):int=>strlen((string)$a['id'])<=>strlen((string)$b['id'])?:strcmp((string)$a['id'],(string)$b['id']);usort($newAssets,$decimal);usort($newLogs,$decimal);
    $mapping=[];$assets=$before['database']['rows']['eb_marketplace_platform_asset'];$images=[];$room=['customer_uid'=>$customer['uid'],'is_tourist'=>$tourist,'service_id'=>$destination['service_id'],'service_uid'=>$destination['uid']];
    foreach(array_values($distinct)as$i=>$original){$new=$newAssets[$i];$image=$knownImages[(string)$original['id']]??null;m4pc_expect(is_array($image),'M4PC_ORIGINAL_IMAGE_INPUT_PRESENT');
        $want=m4pc_asset_expected($db,$new,$room,$actor,$image,$reply,'BOUND');m4pc_expect($new['id']!==$original['id']&&$new['object_key']!==$original['object_key'],'M4PC_INDEPENDENT_CLONE_OBJECT_AND_ROW');
        $mapping[(string)$original['id']]=['id'=>(string)$new['id'],'ref'=>\app\dao\marketplace\assets\PlatformChatAssetDao::reference((string)$new['id']),
            'asset'=>$want,'image'=>$image,'room'=>$room];$assets[]=$want;$images[]=$image;
    }
    $logs=$before['database']['rows']['eb_store_service_log'];foreach($selected as$i=>$original){$want=$original;$want['id']=$newLogs[$i]['id'];$want['add_time']=$newLogs[$i]['add_time'];m4pc_window($want['add_time'],$reply);
        if((int)$want['uid']===$actor['uid'])$want['uid']=m4pc_number($db,$destination['uid']);if((int)$want['to_uid']===$actor['uid'])$want['to_uid']=m4pc_number($db,$destination['uid']);
        if((int)$original['msn_type']===3&&\app\dao\marketplace\assets\PlatformChatAssetDao::isPrivate($original['msn']))$want['msn']=$mapping[\app\dao\marketplace\assets\PlatformChatAssetDao::referenceId($original['msn'])]['ref'];
        m4pc_expect(array_keys($want)===M4PC_LOG_FIELDS&&$newLogs[$i]===$want,'M4PC_REAL_COPY_PK_COMPLETE11_SOURCE_INPUT_ORDER');$logs[]=$want;
        if((int)$original['msn_type']===3&&\app\dao\marketplace\assets\PlatformChatAssetDao::isPrivate($original['msn']))$mapping[\app\dao\marketplace\assets\PlatformChatAssetDao::referenceId($original['msn'])]['messages'][]=(string)$want['id'];
    }
    $last=$newLogs[count($newLogs)-1];$record=m4pc_record_native($db,$destination['uid'],$customer['uid'],$tourist);$window=$reply;$window['record']=$record;
    m4pc_expect((string)($notice['data']['recored']['id']??'')===(string)$record['id'],'M4PC_REAL_CHANNEL_TARGET_RECORD_PK');
    $noticeRecord=$record;$noticeRecord['online']=$customerSocket!==null?1:0;if($tourist===0){$noticeRecord['nickname']=$customer['user']['nickname'];$noticeRecord['avatar']=$customer['user']['avatar'];}
    $noticeWanted=['recored'=>$noticeRecord,'kefuInfo'=>['avatar'=>$actor['service']['avatar'],'nickname'=>$actor['service']['nickname']]];
    m4pc_expect(\app\services\marketplace\order\MarketplaceOrderInput::canonical($notice['data'])===\app\services\marketplace\order\MarketplaceOrderInput::canonical($noticeWanted),'M4PC_FULL_REAL_TARGET_CHANNEL_NOTIFICATION');
    if($customerNotice!==null){$noticeWanted=['toUid'=>$destination['uid'],'avatar'=>$destination['service']['avatar'],'nickname'=>$destination['service']['nickname'],'is_tourist'=>$tourist];m4pc_expect(\app\services\marketplace\order\MarketplaceOrderInput::canonical($customerNotice['data'])===\app\services\marketplace\order\MarketplaceOrderInput::canonical($noticeWanted),'M4PC_FULL_REAL_CUSTOMER_GUEST_CHANNEL_NOTIFICATION');}
    // Original transfer deliberately uses absent log.message_type =>1; preserve it instead of silently correcting business behavior.
    $records=m4pc_record_formula($db,$before['database']['rows']['eb_store_service_record'],$customer['uid'],$destination['uid'],$tourist,$last['msn'],1,count($newLogs)-1,$sourceRecord['nickname'],$sourceRecord['avatar'],$window,$notice['data']['recored']);
    $auxRows=$before['database']['rows']['eb_auxiliary'];$nativeAux=m4pc_rows($db,'SELECT * FROM eb_auxiliary WHERE type=0 AND binding_id=? AND relation_id=?',[$actor['uid'],$customer['uid']]);m4pc_expect(count($nativeAux)===1&&array_keys($nativeAux[0])===M4PC_AUX_FIELDS,'M4PC_COMPLETE_ORIGINAL_AUX7');m4pc_window($nativeAux[0]['update_time'],$reply);
    if($aux===[]){m4pc_window($nativeAux[0]['add_time'],$reply);$want=['id'=>$nativeAux[0]['id'],'binding_id'=>m4pc_number($db,$actor['uid']),'relation_id'=>m4pc_number($db,$customer['uid']),
            'type'=>m4pc_number($db,0),'other'=>'','update_time'=>$nativeAux[0]['update_time'],'add_time'=>$nativeAux[0]['add_time']];$auxRows[]=$want;}
    else{$want=$aux[0];$want['update_time']=$nativeAux[0]['update_time'];$auxRows=m4pc_replace($auxRows,'id',$want['id'],$want);}m4pc_expect($nativeAux[0]===$want,'M4PC_AUX_REAL_INPUT_CUTOFF_ONLY_NO_LINEAGE_FICTION');
    $changes=['eb_marketplace_platform_asset'=>$assets,'eb_store_service_log'=>$logs,'eb_store_service_record'=>$records,'eb_auxiliary'=>$auxRows];
    if($customerSocket!==null&&$customer['kind']==='api'){$authWindow=['start'=>$reply['start'],'end'=>time()];$changes['eb_user']=m4pc_auth_expected($db,$before,$after,[$customer['uid']],$authWindow);}
    $private=m4pc_new_private($before,$after,$newAssets,$images,$reply);m4pc_compare($before,$after,$changes,['eb_marketplace_platform_asset'=>count($distinct),'eb_store_service_log'=>count($selected),'eb_auxiliary'=>$aux===[]?1:0],[],[],$private);
    foreach($distinct as$id=>$original)m4pc_expect(m4pc_find($after['database']['rows']['eb_marketplace_platform_asset'],'id',$id)===$original,'M4PC_ALL_SOURCE_ASSET_PAIR_BOUND_AT_UNCHANGED');
    m4pc_expect(m4pc_record_native($db,$actor['uid'],$customer['uid'],$tourist)===$sourceRecord,'M4PC_SOURCE_RECORD_PRESERVED');
    $GLOBALS['m4pc_cases'][]='actual_trusted_transfer_'.($customerSocket===null?'historic_positive_guest_no_fabricated_receiver':'two_real_Channel_receivers').'_distinct_clones_full_native_oracle';return $mapping;
}

function m4pc_production_source():void
{
    $fixed=[
        'crmeb/app/api/ApiExceptionHandle.php'=>['bytes'=>5185,'sha256'=>'474ad1204d6ccb4bfc2a6ad866dfb029b75cc1cb81eed9bbb0d5a97afefa553c'],
        'crmeb/app/api/route/v1.php'=>['bytes'=>64932,'sha256'=>'2eeed25acb51d72ac4d40b6266c0228295ba1ee6b70da4fea51557a38631199e'],
        'crmeb/app/kefuapi/KefuApiExceptionHandle.php'=>['bytes'=>5568,'sha256'=>'23b5ab1d4813802425911165c331cf67fdb6fa2a7b23ef3aa34564c30c6bb953'],
        'crmeb/app/kefuapi/controller/Service.php'=>['bytes'=>9665,'sha256'=>'40e513f8f70604e179291a05819086bbb206a38f71f9f72664a36cebde80e2df'],
        'crmeb/app/kefuapi/route/route.php'=>['bytes'=>10332,'sha256'=>'94fdafae93de655a94da1e3ee594def0124a6fdce94eca852f22db9ef70981d0'],
        'crmeb/app/listener/http/HttpEndListener.php'=>['bytes'=>3091,'sha256'=>'feb779fa1346cedaee48edaefdf532fff6182453642021699ba0ea2a41703cd6'],
        'crmeb/app/services/kefu/KefuServices.php'=>['bytes'=>11812,'sha256'=>'e4d9b4aac1fa9180ab99152d0d5c795c32dd5cf33675fd86f5b7e1eee104fae5'],
        'crmeb/config/cookie.php'=>['bytes'=>2424,'sha256'=>'17100fc739ff6e8c22801af650a19f3e5a829c0a7f69b3a69b5fa490c6274240'],
        'crmeb/config/marketplace.php'=>['bytes'=>4391,'sha256'=>'be269615dc5f395786ad94d569234f5e41838e1fc7cea5454baea8c10d18012e'],
        'crmeb/crmeb/services/workerman/chat/ChatHandle.php'=>['bytes'=>23357,'sha256'=>'920b5e5894ce90306ca1620e19f79e98f52d0a327a7d5ad7edca4a5817005f0e'],
        'crmeb/app/api/controller/v1/user/ChatAsset.php'=>['bytes'=>3304,'sha256'=>'e3f558c335d75f05e580de53b92e5a25f174422472cf19c328be3aa10959407e'],
        'crmeb/app/dao/marketplace/assets/PlatformChatAssetDao.php'=>['bytes'=>21727,'sha256'=>'17634a11486f3deafc2d2236d6f614a20961cd348eee4367f26c5bbc8fbe2424'],
        'crmeb/app/kefuapi/controller/ChatAsset.php'=>['bytes'=>1772,'sha256'=>'5919c5568a5437cebcc42a4d38dcb3a6b9d2f84efa340ad6282781bb45e76274'],
        'crmeb/app/services/marketplace/assets/PlatformChatAssetServices.php'=>['bytes'=>28893,'sha256'=>'159ccc62155a7c66736ceb6ff1c6cb194ed3080d29d8a98a4b853e2605cb81e6'],
        'crmeb/app/services/marketplace/assets/PlatformChatPrivateStorage.php'=>['bytes'=>15590,'sha256'=>'d02a6a267fa73fe00788b11f7d34cc00c0e5fd8590349aa8bc1a520abc58d1f5'],
        'crmeb/app/services/marketplace/assets/PlatformChatReadTickets.php'=>['bytes'=>13076,'sha256'=>'9d6918e04d588a1725eb43d932c277ac62a57c3bb3e53940ccac8bbfa3eca145'],
        'crmeb/app/services/marketplace/assets/PlatformChatRoomAccessServices.php'=>['bytes'=>9219,'sha256'=>'411ae4a474c1415d2d13600565963a0bed3a8bf69553729c796eabe80b5f0e20'],
    ];foreach($fixed as$name=>$pin)m4pc_expect(m4pc_profile_raw(audit_repo_dir().'/'.$name)===$pin,'M4PC_FIXED_REVIEWED_PRODUCTION_BODY');
}

function m4pc_runtime_expected(array $before,array $after,string $oldLog,string $phase,string $generation,array $window):array
{
    m4pc_expect($phase==='start'||$phase==='stop','M4PC_FIXED_RUNTIME_PHASE');$base=audit_run_dir().'/evidence/platform-chat-'.$generation.'-chat';
    $created=json_decode(file_get_contents($base.'.created.json'),true,32,JSON_THROW_ON_ERROR);$pid=$created['pid']??null;m4pc_expect(is_int($pid)&&$pid>0&&($created['role']??null)==='chat','M4PC_ACTUAL_CHAT_CHILD_PID');
    $log='platform-chat-workerman.log';$root='/run/audit-app-runtime';$session=$root.'/session';
    $firstSession=!array_key_exists('session',$before['directories']);
    m4pc_expect(!$firstSession||($phase==='start'&&($GLOBALS['m4pc_cases']??null)===[]),'M4PC_SESSION_DIRECTORY_ONLY_FIRST_REAL_HTTP_START');
    clearstatcache(true,$root);clearstatcache(true,$session);$rootStat=lstat($root);$sessionStat=lstat($session);
    m4pc_expect(is_array($rootStat)&&!is_link($root)&&realpath($root)===$root&&($rootStat['mode']&0170000)===0040000
        &&$rootStat['uid']===posix_geteuid()&&$rootStat['gid']===posix_getegid()
        &&is_array($sessionStat)&&!is_link($session)&&realpath($session)===$session&&($sessionStat['mode']&0170000)===0040000
        &&($sessionStat['mode']&07777)===0700&&$sessionStat['uid']===posix_geteuid()&&$sessionStat['gid']===posix_getegid()
        &&$sessionStat['dev']===$rootStat['dev']&&is_int($sessionStat['ino'])&&$sessionStat['ino']>0,
        'M4PC_CANONICAL_OWN_EMPTY_SESSION_DIRECTORY_NATIVE_IDENTITY');
    m4pc_expect(scandir($session)===['.','..'],'M4PC_EXACT_EMPTY_SESSION_DIRECTORY');clearstatcache(true,$session);$sessionAfter=lstat($session);
    foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
        m4pc_expect(is_array($sessionAfter)&&$sessionAfter[$key]===$sessionStat[$key],'M4PC_SESSION_NATIVE_IDENTITY_STABLE_DURING_EMPTY_READ');
    $sessionExpected=[$rootStat['dev'],$sessionStat['ino'],0700,posix_geteuid()];
    if($firstSession){foreach(['mtime','ctime']as$key)m4pc_expect($sessionStat[$key]>=$window['start']&&$sessionStat[$key]<=$window['end'],'M4PC_FIRST_HTTP_SESSION_CREATION_TIME_WINDOW');}
    else m4pc_expect($before['directories']['session']===$sessionExpected,'M4PC_ORIGINAL_SESSION_DIRECTORY_INODE_PRESERVED');
    m4pc_expect(($after['directories']['session']??null)===$sessionExpected,'M4PC_EXACT_SESSION_OBSERVER_TUPLE');
    $raw=file_get_contents($root.'/'.$log);m4pc_expect(is_string($raw)&&str_starts_with($raw,$oldLog),'M4PC_RUNTIME_LOG_FULL_OLD_PREFIX_PRESERVED');
    $observationBase=audit_run_dir().'/evidence/platform-chat-'.$generation.'-runtime';
    if($phase==='start'){
        $statusPath=audit_run_dir().'/app-status.json';$statusPin=m4pc_profile_raw($statusPath);$statusRaw=file_get_contents($statusPath);
        m4pc_expect(is_string($statusRaw)&&strlen($statusRaw)===$statusPin['bytes']&&hash('sha256',$statusRaw)===$statusPin['sha256']&&m4pc_profile_raw($statusPath)===$statusPin,'M4PC_STABLE_COMPLETE_START_STATUS_RAW');
        $status=json_decode($statusRaw,true,64,JSON_THROW_ON_ERROR);$group=$status['group']??null;
        m4pc_expect(is_array($status)&&($status['run_id']??null)===getenv('AUDIT_RUN_ID')&&($status['revision']??null)===$generation
            &&($status['generation_revision']??null)===$generation&&($status['status']??null)==='start'&&($status['force_signal_sent']??null)===false
            &&($status['listener_ports']??null)===[8000,40002,40003]&&is_array($group)&&array_keys($group)===['leader_pid','pgid','sid','leader_starttime']
            &&is_int($group['leader_pid'])&&$group['leader_pid']>1&&$group['pgid']===$group['leader_pid']&&$group['sid']===$group['leader_pid']
            &&is_string($group['leader_starttime'])&&ctype_digit($group['leader_starttime']),'M4PC_SAME_GENERATION_REGISTERED_START_STATUS');
        $receipts=[];foreach(['http','chat']as$role){$path=audit_run_dir().'/evidence/platform-chat-'.$generation.'-'.$role.'.created.json';$pin=m4pc_profile_raw($path);$receiptRaw=file_get_contents($path);
            m4pc_expect(is_string($receiptRaw)&&strlen($receiptRaw)===$pin['bytes']&&hash('sha256',$receiptRaw)===$pin['sha256']&&m4pc_profile_raw($path)===$pin,'M4PC_FULL_SAME_CHILD_CREATION_RAW');
            $receipt=json_decode($receiptRaw,true,32,JSON_THROW_ON_ERROR);$expectedArgv=$role==='http'?[PHP_BINARY,'-S','0.0.0.0:8000','-t',audit_repo_dir().'/crmeb/public',audit_repo_dir().'/help/audit-regressions/isolated/lib/platform-chat-private-profile.php']:[PHP_BINARY,audit_repo_dir().'/help/audit-regressions/isolated/lib/platform-chat-private-profile.php','__chat'];
            m4pc_expect(is_array($receipt)&&($receipt['role']??null)===$role&&is_int($receipt['pid']??null)&&$receipt['pid']>0&&($receipt['argv']??null)===$expectedArgv
                &&($receipt['group']['pid']??null)===$receipt['pid']&&($receipt['group']['ppid']??null)===$group['leader_pid']
                &&($receipt['group']['pgid']??null)===$group['pgid']&&($receipt['group']['sid']??null)===$group['sid'],'M4PC_ORIGINAL_CHILD_ARGV_AND_NATIVE_GROUP');$receipts[$role]=$receipt;
        }
        m4pc_expect($receipts['chat']===$created,'M4PC_SAME_ACTUAL_CHAT_MASTER_CREATION');$members=[];
        m4pc_expect(is_array($status['members']??null)&&count($status['members'])===5,'M4PC_COMPLETE_FIVE_PROCESS_START_OBSERVATION');
        foreach($status['members']as$member){m4pc_expect(is_array($member)&&array_keys($member)===['pid','ppid','pgid','sid','starttime','state','listener_ports']
                &&is_int($member['pid'])&&$member['pid']>1&&!isset($members[$member['pid']])&&is_int($member['ppid'])
                &&$member['pgid']===$group['pgid']&&$member['sid']===$group['sid']&&is_string($member['starttime'])&&ctype_digit($member['starttime'])
                &&(int)$member['starttime']>=(int)$group['leader_starttime']&&is_string($member['state'])&&!in_array($member['state'],['Z','X','x'],true)
                &&is_array($member['listener_ports']),'M4PC_ORIGINAL_NATIVE_MEMBER_FIELDS_ONLY');$members[$member['pid']]=$member;}
        $leader=$members[$group['leader_pid']]??null;$http=$members[$receipts['http']['pid']]??null;$master=$members[$pid]??null;$workers=[];
        foreach($members as$member)if($member['ppid']===$pid){if($member['listener_ports']===[40003])$workers['channel'][]=$member;elseif($member['listener_ports']===[40002])$workers['chat'][]=$member;}
        m4pc_expect(is_array($leader)&&$leader['listener_ports']===[]&&$leader['starttime']===$group['leader_starttime']
            &&is_array($http)&&$http['ppid']===$group['leader_pid']&&$http['starttime']===$receipts['http']['group']['starttime']&&$http['listener_ports']===[8000]
            &&is_array($master)&&$master['ppid']===$group['leader_pid']&&$master['starttime']===$created['group']['starttime']&&$master['listener_ports']===[40002,40003]
            &&count($workers['channel']??[])===1&&count($workers['chat']??[])===1,'M4PC_SOURCE_BOUND_FULL_ROLE_MATRIX');
        $workers=['channel'=>$workers['channel'][0],'chat'=>$workers['chat'][0]];$expected=[$leader['pid'],$http['pid'],$master['pid'],$workers['channel']['pid'],$workers['chat']['pid']];sort($expected,SORT_NUMERIC);$actual=array_keys($members);sort($actual,SORT_NUMERIC);
        m4pc_expect($expected===$actual,'M4PC_EXACT_FIVE_NATIVE_ROLE_IDENTITIES');$requests=$GLOBALS['m4pc_requests']??0;$frames=$GLOBALS['m4pc_ws_frames']??0;
        m4pc_expect(is_int($requests)&&$requests>=1&&is_int($frames)&&$frames>=0&&!isset($GLOBALS['m4pc_runtime_workers'][$generation]),'M4PC_FRESH_GENERATION_INDEPENDENT_OBSERVER_COUNTERS');
        $observation=['run_id'=>getenv('AUDIT_RUN_ID'),'generation'=>$generation,'registered_group'=>$group,'http_created'=>$receipts['http'],'chat_created'=>$created,
            'start_status'=>$statusPin,'workers'=>$workers,'http_count_after_readiness'=>$requests,'ws_frame_count'=>$frames,'expected_uid'=>posix_geteuid(),
            'uid_producer'=>'original m4pc_profile_observe/m4pc_profile_process_uid','session_native9'=>array_intersect_key($sessionStat,array_fill_keys(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'],true)),
            'first_session_directory'=>$firstSession,'start_window'=>$window];
        m4pc_write($observationBase.'.start-status.raw',$statusRaw);m4pc_write($observationBase.'.start-workers.json',m4pc_json($observation));
        $GLOBALS['m4pc_runtime_workers'][$generation]=['observation'=>$observation,'status_file'=>m4pc_file_descriptor($observationBase.'.start-status.raw',true),'workers_file'=>m4pc_file_descriptor($observationBase.'.start-workers.json',true)];
    }else{
        $capture=$GLOBALS['m4pc_runtime_workers'][$generation]??null;m4pc_expect(is_array($capture)&&($capture['observation']['run_id']??null)===getenv('AUDIT_RUN_ID')
            &&($capture['observation']['generation']??null)===$generation&&($capture['observation']['chat_created']??null)===$created,'M4PC_ORIGINAL_START_WORKER_CAPTURE_REQUIRED');
        m4pc_expect(m4pc_file_descriptor($observationBase.'.start-status.raw',true)===$capture['status_file']
            &&m4pc_file_descriptor($observationBase.'.start-workers.json',true)===$capture['workers_file'],'M4PC_ORIGINAL_CREATE_ONLY_START_EVIDENCE_PRESERVED');
        $observation=json_decode(file_get_contents($observationBase.'.start-workers.json'),true,64,JSON_THROW_ON_ERROR);m4pc_expect($observation===$capture['observation'],'M4PC_COMPLETE_ORIGINAL_WORKER_METADATA');$workers=$observation['workers'];
    }
    $lines=explode("\n",substr($raw,strlen($oldLog)));m4pc_expect(array_pop($lines)===''&&($phase==='start'?count($lines)===1:(count($lines)>=2&&count($lines)<=10)),'M4PC_EXACT_NATIVE_WORKERMAN_LOG_COUNT');$addition='';$masterMessages=[];$vectors=['channel'=>[],'chat'=>[]];$workerNames=['channel'=>'ChannelServer','chat'=>'AuditPlatformChatOrdinary'];$waitReceipts=['channel'=>null,'chat'=>null];
    foreach($lines as$i=>$line){m4pc_expect(preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) pid:([1-9][0-9]*) (.*)\z/D',$line,$m)===1,'M4PC_EXACT_ORIGINAL_LOG_LINE_FORMAT');
        $at=strtotime($m[1]);m4pc_expect(is_int($at)&&$at>=$window['start']&&$at<=$window['end'],'M4PC_RUNTIME_LOG_REAL_TIME_WINDOW');$actor=(int)$m[2];$message=$m[3];
        if($phase==='start')m4pc_expect($actor===$pid&&$message==='Workerman['.audit_repo_dir().'/help/audit-regressions/isolated/lib/platform-chat-private-profile.php] start in DEBUG mode','M4PC_EXACT_ORIGINAL_LOG_SOURCE_MESSAGE_AND_PID');
        elseif($actor===$pid&&in_array($message,['Workerman[platform-chat-private-profile.php] stopping ...','Workerman[platform-chat-private-profile.php] has been stopped'],true)){
            m4pc_expect(!isset($masterMessages[$message]),'M4PC_ONE_ORIGINAL_MASTER_MESSAGE');$masterMessages[$message]=$i;
        }elseif($actor===$pid){
            $role=null;foreach($workerNames as$candidate=>$name)if($message==='worker['.$name.':'.$workers[$candidate]['pid'].'] exit with status 15')$role=$candidate;
            m4pc_expect($role!==null&&$waitReceipts[$role]===null,'M4PC_SAME_ORIGINAL_WORKER_RAW_TERM_WAIT_RECEIPT');
            $waitReceipts[$role]=['role'=>$role,'name'=>$workerNames[$role],'pid'=>$workers[$role]['pid'],'raw_status'=>15,'line_index'=>$i];
        }else{
            $role=$actor===$workers['channel']['pid']?'channel':($actor===$workers['chat']['pid']?'chat':null);
            m4pc_expect($role!==null&&preg_match('/\Aworker\['.preg_quote((string)$actor,'/').'\] remains (0|[1-9][0-9]*) connection\(s\)\z/D',$message,$count)===1,'M4PC_SAME_ORIGINAL_WORKER_DESTRUCTOR_MESSAGE');$vectors[$role][]=(int)$count[1];
            m4pc_expect(count($vectors[$role])<=3,'M4PC_ORIGINAL_DESTRUCTOR_AT_MOST_THREE_MESSAGES');
        }
        $addition.=$m[1].' pid:'.$actor.' '.$message."\n";
    }
    if($phase==='stop'){
        m4pc_expect(count($masterMessages)===2&&isset($masterMessages['Workerman[platform-chat-private-profile.php] stopping ...'])
            &&($masterMessages['Workerman[platform-chat-private-profile.php] has been stopped']??null)===count($lines)-1
            &&$masterMessages['Workerman[platform-chat-private-profile.php] stopping ...']<count($lines)-1,'M4PC_ORIGINAL_MASTER_REAP_PARTIAL_ORDER');
        foreach($waitReceipts as$receipt)if($receipt!==null)m4pc_expect($masterMessages['Workerman[platform-chat-private-profile.php] stopping ...']<$receipt['line_index']
            &&$receipt['line_index']<$masterMessages['Workerman[platform-chat-private-profile.php] has been stopped'],'M4PC_ORIGINAL_WORKER_TERM_WAIT_BETWEEN_MASTER_STOP_MESSAGES');
        $requests=$GLOBALS['m4pc_requests']??0;$frames=$GLOBALS['m4pc_ws_frames']??0;m4pc_expect(is_int($requests)&&$requests>=$observation['http_count_after_readiness']
            &&is_int($frames)&&$frames>=$observation['ws_frame_count'],'M4PC_REAL_GENERATION_REQUEST_FRAME_COUNTER_DELTAS');
        // Independent upper bound only: completed HTTP after start, known readiness HTTP, sent WS frames,
        // one original Chat Channel connection and at most one incomplete no-retry request on a failed path.
        $bound=$requests-$observation['http_count_after_readiness']+$frames-$observation['ws_frame_count']+3;$catalog=[[]];
        for($n=1;$n<=$bound;$n++){$mod=(int)ceil($n/3);$vector=[];for($remaining=$n-1;$remaining>=0;$remaining--)if($remaining%$mod===0)$vector[]=$remaining;if(!in_array($vector,$catalog,true))$catalog[]=$vector;}
        foreach($vectors as$vector)m4pc_expect(in_array($vector,$catalog,true),'M4PC_COMPLETE_ORIGINAL_DESTRUCTOR_FINITE_CATALOG_VECTOR');
        m4pc_write($observationBase.'.stop-log-proof.json',m4pc_json(['run_id'=>getenv('AUDIT_RUN_ID'),'generation'=>$generation,
            'start_workers_file'=>$capture['workers_file'],'start_http_after_readiness'=>$observation['http_count_after_readiness'],'stop_http'=>$requests,
            'start_ws_frames'=>$observation['ws_frame_count'],'stop_ws_frames'=>$frames,'known_readiness_http'=>1,'original_chat_channel_connection'=>1,
            'conservative_at_most_one_incomplete_no_retry_http'=>1,'independent_count_upper_bound'=>$bound,'not_actual_active_object_count'=>true,
            'original_destructor_n_range'=>[0,$bound],'whole_worker_vectors'=>$vectors,'master_line_positions'=>$masterMessages,'worker_term_wait_receipts'=>$waitReceipts,
            'exact_addition'=>['bytes'=>strlen($addition),'sha256'=>hash('sha256',$addition)],'stop_window'=>$window]));
    }
    $wanted=$before;$wanted['directories']['session']=$sessionExpected;ksort($wanted['directories'],SORT_STRING);$descriptor=$after['files'][$log]??null;m4pc_expect(is_array($descriptor)&&$descriptor['bytes']===strlen($oldLog.$addition)&&$descriptor['sha256']===hash('sha256',$oldLog.$addition)
        &&$descriptor['mode']===0600&&$descriptor['uid']===posix_geteuid()&&$descriptor['nlink']===1,'M4PC_FULL_EXPECTED_WORKERMAN_LOG_BYTES_AND_METADATA');
    if(isset($before['files'][$log]))foreach(['dev','ino','mode','nlink','uid','gid']as$key)m4pc_expect($descriptor['native9'][$key]===$before['files'][$log]['native9'][$key],'M4PC_ORIGINAL_LOG_NATIVE_IDENTITY');
    foreach(['mtime','ctime']as$key)m4pc_expect($descriptor['native9'][$key]>=$window['start']&&$descriptor['native9'][$key]<=$window['end'],'M4PC_EXACT_LOG_METADATA_TIME_WINDOW');$wanted['files'][$log]=$descriptor;
    $pidFile='platform-chat.pid';if($phase==='start'){$file=$after['files'][$pidFile]??null;m4pc_expect(is_array($file)&&$file['bytes']===strlen((string)$pid)&&$file['sha256']===hash('sha256',(string)$pid)
            &&$file['mode']===0600&&$file['uid']===posix_geteuid()&&$file['nlink']===1&&!isset($before['files'][$pidFile]),'M4PC_REAL_GENERATION_PIDFILE');$wanted['files'][$pidFile]=$file;}
    else{m4pc_expect(isset($before['files'][$pidFile])&&!isset($after['files'][$pidFile]),'M4PC_OWN_WORKERMAN_PIDFILE_REMOVED');unset($wanted['files'][$pidFile]);}
    ksort($wanted['files'],SORT_STRING);m4pc_expect($wanted===$after,'M4PC_NO_OTHER_APP_RUNTIME_MUTATIONS');return $wanted;
}
function m4pc_start_step(PDO $db,array $fixture,bool $writes):string
{
    $before=m4pc_state($db);$log='/run/audit-app-runtime/platform-chat-workerman.log';$old=is_file($log)?file_get_contents($log):'';$window=['start'=>time()];
    audit_app_request('start',['CACHE_PREFIX'=>$fixture['prefix'],'PRIVATE_WRITES'=>$writes?'true':'false']);$ready=m4pc_success(m4pc_http('GET','/kefuapi/service/files/capabilities',$fixture['a']));
    m4pc_expect($ready===['protocol'=>1,'private_writes_enabled'=>$writes,'private_read_supported'=>true],'M4PC_REAL_KNOWN_KFEU_HTTP_READINESS');$window['end']=time();$status=audit_read_control_json(audit_run_dir().'/app-status.json');
    $generation=$status['generation_revision']??'';m4pc_expect(($status['status']??null)==='start'&&is_string($generation)&&preg_match('/\A[a-f0-9]{16}\z/D',$generation)===1
        &&($status['listener_ports']??null)===[8000,40002,40003]&&($status['force_signal_sent']??null)===false,'M4PC_ACTUAL_REGISTERED_TRIPLE_LISTENER_START');
    $after=m4pc_state($db);$runtime=m4pc_runtime_expected($before['runtime'],$after['runtime'],$old,'start',$generation,$window);m4pc_compare($before,$after,[],[],[],[],null,$runtime);
    $GLOBALS['m4pc_cases'][]='actual_HTTP_Chat_Channel_generation_'.($writes?'private_write_on':'private_write_off');return $generation;
}
function m4pc_stop_step(PDO $db,string $generation):array
{
    $before=m4pc_state($db);$log=file_get_contents('/run/audit-app-runtime/platform-chat-workerman.log');$window=['start'=>time()];audit_app_stop();$window['end']=time();
    $status=audit_read_control_json(audit_run_dir().'/app-status.json');m4pc_expect(($status['status']??null)==='stop'&&($status['members']??null)===[]&&($status['listener_ports']??null)===[]
        &&($status['listener_closed']??null)===true&&($status['stop_mode']??null)==='term-only'&&($status['force_signal_sent']??null)===false
        &&($status['combination_terminal']['native_exit']??null)===0,'M4PC_REAL_ORIGINAL_NATIVE_TERM_GROUP_COMPLETE');
    $after=m4pc_state($db);$runtime=m4pc_runtime_expected($before['runtime'],$after['runtime'],$log,'stop',$generation,$window);m4pc_compare($before,$after,[],[],[],[],null,$runtime);return $status;
}
function m4pc_close_socket(string $name):void
{
    m4pc_expect(isset($GLOBALS['m4pc_sockets'][$name]),'M4PC_CLOSE_ONLY_REAL_KNOWN_SOCKET');m4pc_ws_frame($name,8,pack('n',1000));
    $stream=$GLOBALS['m4pc_sockets'][$name]['stream'];m4pc_expect(fclose($stream),'M4PC_NORMAL_WS_CLIENT_CLOSE');unset($GLOBALS['m4pc_sockets'][$name]);
}
function m4pc_offline_step(PDO $db):void
{
    m4pc_expect(isset($GLOBALS['m4pc_sockets']['b'])&&$GLOBALS['m4pc_sockets']['b']['actor']['kind']==='kefu','M4PC_REAL_LAST_KNOWN_STAFF_CONNECTION');
    $keep=$GLOBALS['m4pc_sockets']['b']['actor']['uid'];$before=m4pc_state($db);foreach(array_keys($GLOBALS['m4pc_sockets'])as$name)if($name!=='b')m4pc_close_socket($name);
    $deadline=microtime(true)+6;do{$online=(int)m4pc_rows($db,'SELECT COUNT(*) AS n FROM eb_store_service WHERE uid<>? AND online<>0',[$keep])[0]['n']
        +(int)m4pc_rows($db,'SELECT COUNT(*) AS n FROM eb_store_service_record WHERE to_uid<>? AND online<>0',[$keep])[0]['n'];if($online===0)break;usleep(20000);}while(microtime(true)<$deadline);
    m4pc_expect($online===0,'M4PC_ORIGINAL_NONEMPTY_TIMER_UID_SET_OFFLINE_FORMULA');$after=m4pc_state($db);$changes=[];
    foreach(['eb_store_service'=>'uid','eb_store_service_record'=>'to_uid']as$table=>$column){$rows=$before['database']['rows'][$table];foreach($rows as&$row)if((int)$row[$column]!==$keep)$row['online']=m4pc_number($db,0);unset($row);$changes[$table]=$rows;}
    m4pc_compare($before,$after,$changes);unset($before,$after,$changes);$before=m4pc_state($db);m4pc_close_socket('b');usleep(100000);$after=m4pc_state($db);
    // The original empty-connection timer performs no DB update. Preserve the last staff online row, rather than manufacture a global offline write.
    m4pc_compare($before,$after);$GLOBALS['m4pc_cases'][]='actual_original_timer_notUid_then_last_close_preserves_empty_set_behavior';
}
function m4pc_memory():void {m4pc_expect(ini_get('memory_limit')==='512M','M4PC_ONLY_OBSERVER_CLI_FIXED_MEMORY_READBACK');}

function m4pc_legacy_content(PDO $db,array $fixture):void
{
    $before=m4pc_state($db);foreach(['legacy','voice']as$key){$path=audit_repo_dir().'/crmeb/public'.$fixture[$key];$pin=m4pc_file_descriptor($path);$response=m4pc_http('GET',$fixture[$key]);
        m4pc_expect($response['http']===200&&strlen($response['raw'])===$pin['bytes']&&hash('sha256',$response['raw'])===$pin['sha256'],'M4PC_ORIGINAL_PUBLIC_URL_FULL_SAME_BYTES');}
    $after=m4pc_state($db);m4pc_compare($before,$after);$GLOBALS['m4pc_cases'][]='actual_original_URL_image_static_bytes_no_private_redirect';
}
function m4pc_bound_pair_reads(PDO $db,array $fixture,array $left,array $right,array $asset,string $message):void
{
    m4pc_read_step($db,$fixture,$left,$asset,$message);m4pc_read_step($db,$fixture,$right,$asset,$message);
}
function m4pc_verify():array
{
    $manifest=m4pc_guard();m4pc_memory();m4pc_production_source();$fixture=json_decode(file_get_contents(M4PC_FIXTURE),true,64,JSON_THROW_ON_ERROR);
    m4pc_expect(($fixture['schema']??null)===1&&($fixture['run_id']??null)===$manifest['run_id']&&($fixture['source_sha']??null)===$manifest['commit_sha']
        &&isset($fixture['customer'],$fixture['a'],$fixture['b'],$fixture['order_cart'],$fixture['images']),'M4PC_THIS_SAME_SOURCE_RUN_PRIVATE_FIXTURE');
    $app=m4pc_app($fixture['prefix']);$db=audit_db();m4pc_expect($db->exec('SET SESSION information_schema_stats_expiry=0')===0,'M4PC_VERIFY_FRESH_METADATA_OBSERVER');
    $prepared=unserialize(file_get_contents('/run/audit-fixtures/platform-chat-prepared-full-state.serialized'),['allowed_classes'=>false]);m4pc_expect(is_array($prepared),'M4PC_FULL_PREPARATION_STATE');
    $fresh=m4pc_state($db);m4pc_compare($prepared,$fresh);unset($prepared,$fresh);$GLOBALS['m4pc_cases']=[];$GLOBALS['m4pc_sockets']=[];
    $active=false;$stopAttempted=false;$generation='';$closed=[];$error=null;$saved=[];$knownImages=[];$transfers=[];$guest=null;$historic=['kind'=>'historic','uid'=>$fixture['legacy_guest_uid']];
    try{
        // A real default-off generation has all three original listeners, not a changed producer setting or an auth bypass.
        $active=true;$generation=m4pc_start_step($db,$fixture,false);m4pc_memory();
        m4pc_capabilities_step($db,$fixture['customer'],false);m4pc_capabilities_step($db,$fixture['a'],false);m4pc_legacy_content($db,$fixture);
        $stopAttempted=true;$closed[]=m4pc_stop_step($db,$generation);$active=false;$stopAttempted=false;
        $active=true;$generation=m4pc_start_step($db,$fixture,true);m4pc_memory();
        foreach(['a','b','customer']as$name)m4pc_login_step($db,$name,$fixture[$name]);
        m4pc_room_step($db,'customer',$fixture['a']['uid'],0);m4pc_room_step($db,'a',$fixture['customer']['uid'],0);
        m4pc_capabilities_step($db,$fixture['customer'],true);m4pc_capabilities_step($db,$fixture['a'],true);
        foreach(['jpg','png','gif','webp']as$i=>$extension){$sender=$i%2===0?'customer':'a';$receiver=$sender==='customer'?'a':'customer';
            $asset=m4pc_upload_step($db,$fixture,$fixture[$sender],$fixture[$receiver],0,$fixture['images'][$extension]);m4pc_read_step($db,$fixture,$fixture[$sender],$asset,null);
            $message=m4pc_chat_step($db,$fixture,$sender,$receiver,$fixture[$receiver],0,3,$asset['ref'],$asset);$asset=$message['asset'];
            m4pc_bound_pair_reads($db,$fixture,$fixture['customer'],$fixture['a'],$asset,$message['id']);$saved[]=['asset'=>$asset,'message'=>$message['id']];$knownImages[$asset['id']]=$asset['image'];
        }
        $first=$saved[0];$bound=m4pc_native_row($db,'eb_marketplace_platform_asset','id',$first['asset']['id']);
        $reused=m4pc_chat_step($db,$fixture,'customer','a',$fixture['a'],0,3,$first['asset']['ref'],$first['asset']);
        m4pc_expect($reused['id']!==$first['message']&&m4pc_native_row($db,'eb_marketplace_platform_asset','id',$first['asset']['id'])===$bound,'M4PC_NEW_ACTUAL_LOG_REUSES_SAME_BOUND29_WITH_FIRST_TIME');
        m4pc_bound_pair_reads($db,$fixture,$fixture['customer'],$fixture['a'],$first['asset'],$reused['id']);
        $sameBytes=m4pc_upload_step($db,$fixture,$fixture['customer'],$fixture['a'],0,$fixture['images']['png']);
        $sameMessage=m4pc_chat_step($db,$fixture,'customer','a',$fixture['a'],0,3,$sameBytes['ref'],$sameBytes);$sameBytes=$sameMessage['asset'];
        m4pc_expect($sameBytes['id']!==$saved[1]['asset']['id']&&$sameBytes['image']['sha256']===$saved[1]['asset']['image']['sha256']
            &&$sameBytes['asset']['object_key']!==$saved[1]['asset']['asset']['object_key'],'M4PC_DISTINCT_SOURCE_OBJECTS_WITH_SAME_IMAGE_BYTES');
        $knownImages[$sameBytes['id']]=$sameBytes['image'];$saved[]=['asset'=>$sameBytes,'message'=>$sameMessage['id']];
        $legacy=[1=>'audit-ordinary-text-'.$manifest['run_id'],2=>'[微笑]',3=>'http://audit-app:8000'.$fixture['legacy'],
            4=>'http://audit-app:8000'.$fixture['voice'],5=>(string)$fixture['product']['id'],6=>$fixture['order']['order_id']];
        foreach($legacy as$type=>$payload)m4pc_chat_step($db,$fixture,'customer','a',$fixture['a'],0,$type,$payload);
        m4pc_chat_step($db,$fixture,'customer','a',$fixture['a'],0,1,$fixture['auto_reply']['keyword'],null,true);
        $GLOBALS['m4pc_cases'][]='all_original_six_message_types_product_and_synthetic_unpaid_order_enrichment_real_autoReply';
        $clones=m4pc_transfer_step($db,$fixture,'a','b',$fixture['customer'],'customer',0,$knownImages);$transfers[]=$clones;
        foreach($clones as$clone){foreach($clone['messages']as$message)m4pc_bound_pair_reads($db,$fixture,$fixture['customer'],$fixture['b'],$clone,$message);$knownImages[$clone['id']]=$clone['image'];}
        m4pc_bound_pair_reads($db,$fixture,$fixture['customer'],$fixture['a'],$first['asset'],$first['message']);
        $guest=m4pc_guest_step($db,$fixture);m4pc_login_step($db,'guest',$guest);m4pc_capabilities_step($db,$guest,true);
        m4pc_room_step($db,'guest',$fixture['a']['uid'],1);m4pc_room_step($db,'a',$guest['uid'],1);
        // Current guests retain all six original legacy branches; they do not gain private upload/send authority.
        foreach($legacy as$type=>$payload)m4pc_chat_step($db,$fixture,'guest','a',$fixture['a'],1,$type,$payload);
        $guestAsset=m4pc_upload_step($db,$fixture,$fixture['a'],$guest,1,$fixture['images']['png']);m4pc_read_step($db,$fixture,$fixture['a'],$guestAsset,null);
        $guestMessage=m4pc_chat_step($db,$fixture,'a','guest',$guest,1,3,$guestAsset['ref'],$guestAsset);$guestAsset=$guestMessage['asset'];$knownImages[$guestAsset['id']]=$guestAsset['image'];
        m4pc_bound_pair_reads($db,$fixture,$fixture['a'],$guest,$guestAsset,$guestMessage['id']);
        // Both destination records are created by ordinary real message/record flows, not synthesized after a transfer.
        m4pc_room_step($db,'guest',$fixture['b']['uid'],1);m4pc_room_step($db,'b',$guest['uid'],1);
        m4pc_chat_step($db,$fixture,'guest','b',$fixture['b'],1,1,'audit-guest-destination-'.$manifest['run_id']);
        m4pc_chat_step($db,$fixture,'b','guest',$guest,1,1,'audit-staff-destination-'.$manifest['run_id']);
        m4pc_room_step($db,'guest',$fixture['a']['uid'],1);m4pc_room_step($db,'a',$guest['uid'],1);
        $guestClones=m4pc_transfer_step($db,$fixture,'a','b',$guest,'guest',1,$knownImages);$transfers[]=$guestClones;
        foreach($guestClones as$clone){foreach($clone['messages']as$message)m4pc_bound_pair_reads($db,$fixture,$guest,$fixture['b'],$clone,$message);$knownImages[$clone['id']]=$clone['image'];}
        m4pc_bound_pair_reads($db,$fixture,$guest,$fixture['a'],$guestAsset,$guestMessage['id']);
        // A positive historic tourist has existing legal history only; no token or second guest connection is fabricated.
        m4pc_room_step($db,'a',$historic['uid'],1);
        m4pc_chat_step($db,$fixture,'a',null,$historic,1,1,'audit-historic-positive-'.$manifest['run_id']);
        m4pc_chat_step($db,$fixture,'a',null,$historic,1,3,'http://audit-app:8000'.$fixture['legacy']);
        $historicAsset=m4pc_upload_step($db,$fixture,$fixture['a'],$historic,1,$fixture['images']['jpg']);
        $historicMessage=m4pc_chat_step($db,$fixture,'a',null,$historic,1,3,$historicAsset['ref'],$historicAsset);$historicAsset=$historicMessage['asset'];$knownImages[$historicAsset['id']]=$historicAsset['image'];
        m4pc_read_step($db,$fixture,$fixture['a'],$historicAsset,$historicMessage['id']);
        $GLOBALS['m4pc_cases'][]='offline_tourist_early_return_and_positive_legacy_history_without_fabricated_current_guest';
        m4pc_offline_step($db);$stopAttempted=true;$closed[]=m4pc_stop_step($db,$generation);$active=false;$stopAttempted=false;
        // Existing authenticated reads and trusted transfer remain real when only new private writes are disabled.
        // Restore the staff retained by the empty-set timer before adding the other staff.
        $active=true;$generation=m4pc_start_step($db,$fixture,false);m4pc_memory();foreach(['b','a']as$name)m4pc_login_step($db,$name,$fixture[$name]);
        m4pc_capabilities_step($db,$fixture['customer'],false);m4pc_capabilities_step($db,$fixture['a'],false);m4pc_capabilities_step($db,$guest,false);
        m4pc_bound_pair_reads($db,$fixture,$fixture['customer'],$fixture['a'],$first['asset'],$first['message']);m4pc_bound_pair_reads($db,$fixture,$guest,$fixture['a'],$guestAsset,$guestMessage['id']);
        $historicalClones=m4pc_transfer_step($db,$fixture,'a','b',$historic,null,1,$knownImages);$transfers[]=$historicalClones;
        foreach($historicalClones as$clone){foreach($clone['messages']as$message)m4pc_read_step($db,$fixture,$fixture['b'],$clone,$message);$knownImages[$clone['id']]=$clone['image'];}
        m4pc_read_step($db,$fixture,$fixture['a'],$historicAsset,$historicMessage['id']);
        $GLOBALS['m4pc_cases'][]='defaultfalse_saved_reads_and_trusted_transfer_clones_preserve_original_history';
        m4pc_offline_step($db);$stopAttempted=true;$closed[]=m4pc_stop_step($db,$generation);$active=false;$stopAttempted=false;
    }catch(Throwable $failure){$error=$failure;}
    finally{
        // One ordinary stop of the exact pending generation. A failed stop is never retried or escalated here.
        try{m4pc_close_sockets();}catch(Throwable $failure){$error??=$failure;}
        if($active&&!$stopAttempted)try{$stopAttempted=true;audit_app_stop();$status=audit_read_control_json(audit_run_dir().'/app-status.json');
            m4pc_expect(($status['status']??null)==='stop'&&($status['members']??null)===[]&&($status['listener_ports']??null)===[]
                &&($status['listener_closed']??null)===true&&($status['stop_mode']??null)==='term-only'&&($status['force_signal_sent']??null)===false
                &&($status['combination_terminal']['native_exit']??null)===0,'M4PC_ERROR_FINALLY_ORIGINAL_TERM_ONLY_COMPLETE');
            $active=false;
        }catch(Throwable $failure){$error??=$failure;}
    }
    if($error!==null)throw $error;m4pc_memory();m4pc_expect(count($closed)===3&&!$active&&($GLOBALS['m4pc_sockets']??null)===[],'M4PC_ALL_THREE_ACTUAL_GENERATIONS_CLOSED');
    $end=m4pc_state($db);$cloneCount=0;foreach($transfers as$mapping)$cloneCount+=count($mapping);
    return ['scope'=>'PLATFORM_CHAT_REAL_ORDINARY_HTTP_WS_ONLY','source_sha'=>$manifest['commit_sha'],'business_cases'=>$GLOBALS['m4pc_cases'],
        'http_requests'=>$GLOBALS['m4pc_requests']??0,'real_WS_frames'=>$GLOBALS['m4pc_ws_frames']??0,'assertions'=>$GLOBALS['m4pc_assertions']??0,
        'normal_app_group_closed'=>true,'normal_generations'=>3,'distinct_transferred_asset_clones'=>$cloneCount,'verified_full_platform_columns'=>29,'verified_full_log_columns'=>11,
        'final_database_table_hashes'=>$end['database']['hashes'],'final_redis_sha256'=>hash('sha256',serialize($end['redis'])),
        'final_public_private_runtime_sha256'=>hash('sha256',serialize([$end['public'],$end['private'],$end['runtime']])),
        'observer_memory'=>m4pc_memory_report(),'notification_seed_changed'=>false,'external_Wechat_capability'=>'ABSENT_IN_EXACT_SYNTHETIC_RECIPIENTS_NOT_DISABLED',
        'real_funds_order_creation_payment'=>'NOT RUN','type6_fixture'=>'EXACT_TWO_SYNTHETIC_UNPAID_READ_ROWS_ONLY',
        'resource_retirement'=>'PENDING_ROOT_ORDINARY_CLOSE_AND_INDEPENDENT_SIX_FRESH_RAW_DUAL_EMPTY',
        'browser_device_CORS_and_old_client_release'=>'INCOMPLETE','full_A_B_and_V1'=>'NOT RUN','stopped_negative_fault_concurrent_recovery_K'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}
function m4pc_memory_report():array
{
    m4pc_memory();return ['scope'=>'PREPARE_OR_VERIFY_CLI_ONLY','mode'=>$GLOBALS['m4pc_phase'],'before'=>$GLOBALS['m4pc_memory_before'],
        'effective'=>ini_get('memory_limit'),'fixed_limit_bytes'=>536870912,'peak_php_allocated_bytes'=>memory_get_peak_usage(true)];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $phase=$argv[1]??'';if(count($argv)!==2||!in_array($phase,['prepare','verify'],true)){fwrite(STDERR,"Usage: ms4-platform-chat-private-normal.php prepare|verify; dedicated registered new ISO-FULL only\n");exit(2);}
    umask(0077);putenv('AUDIT_MILESTONE=MS4');$GLOBALS['m4pc_phase']=$phase;
    try{m4pc_guard();$GLOBALS['m4pc_memory_before']=ini_get('memory_limit');m4pc_expect(ini_set('memory_limit','512M')!==false,'M4PC_ONLY_OBSERVER_CLI_BUDGET_SET');m4pc_memory();}
    catch(Throwable $error){fwrite(STDERR,'BLOCKED PLATFORM_CHAT observer '.get_class($error)."\n");exit(2);}
    if($phase==='prepare'){
        try{$result=m4pc_prepare();$result['observer_memory']=m4pc_memory_report();m4pc_write(audit_run_dir().'/evidence/ms4-platform-chat-private-prepare.json',m4pc_json($result));
            echo "PREPARED PLATFORM_CHAT isolated prerequisites only; HTTP and WS NOT RUN\n";exit(0);
        }catch(Throwable $error){$code=preg_match('/\AM4PC_[A-Z0-9_]+\z/D',$error->getMessage())===1?$error->getMessage():get_class($error);fwrite(STDERR,'BLOCKED PLATFORM_CHAT preparation '.$code."\n");exit(2);}
    }
    audit_case('PLATFORM_CHAT','normal-http-ws','INTEGRATION',static function():array{
        $result=m4pc_verify();m4pc_write(audit_run_dir().'/evidence/ms4-platform-chat-private-verify.json',m4pc_json($result));
        return audit_pass('real ordinary HTTP WS Chat Channel and independent full native observations; external ordinary retirement pending');
    });audit_finish();
}
