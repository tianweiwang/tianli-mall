<?php
declare(strict_types=1);

/** Normal real HTTP only. Importing this file starts no App, fixture, server or test. */
require_once __DIR__.'/lib/harness.php';

const M4AL_ROOT='/run/audit-customer-licence';
const M4AL_PRIVATE='/run/audit-fixtures/ms4-agent-licence.json';
const M4AL_FIELDS=['id','uid','purpose','state','storage_type','object_key','thumbnail_key','name','real_name',
    'media_type','byte_size','width','height','content_sha256','created_at','expires_at','bound_at','retired_at',
    'root_order_id','order_item_id','refund_id','review_id','confirmation_ref','form_product_id',
    'form_definition_sha256','form_field_index','extract_id','agent_apply_id','source_product_id','source_qrcode_id',
    'created_by_uid','created_by_admin_id','created_by_job','legacy_att_id'];
const M4AL_APPLICATION=['id','uid','agent_name','name','phone','division_id','division_invite','images','add_time','status','refusal_reason','is_del'];
const M4AL_ROWS=['eb_user','eb_division_agent_apply','eb_marketplace_customer_asset','eb_system_log'];

function m4al_expect(bool $ok,string $code):void
{
    if(preg_match('/\AM4AL_[A-Z0-9_]+\z/D',$code)!==1)throw new RuntimeException('M4AL_INVALID_ASSERTION_CODE');
    $GLOBALS['m4al_assertions']=($GLOBALS['m4al_assertions']??0)+1;
    if(!$ok)throw new RuntimeException($code);
}
function m4al_write(string $path,string $bytes):void
{
    m4al_expect(!file_exists($path)&&!is_link($path),'M4AL_CREATE_ONLY_OUTPUT');
    $h=fopen($path,'xb');m4al_expect(is_resource($h),'M4AL_OUTPUT_OPEN');
    try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($h,substr($bytes,$offset));m4al_expect(is_int($n)&&$n>0,'M4AL_FULL_OUTPUT_WRITE');$offset+=$n;}
        m4al_expect(fflush($h),'M4AL_OUTPUT_FLUSH');}finally{fclose($h);}
    m4al_expect(chmod($path,0600)&&file_get_contents($path)===$bytes,'M4AL_OUTPUT_MODE_AND_READBACK');
}
function m4al_json(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
function m4al_rows(PDO $db,string $sql,array $arguments=[]):array
{
    $s=$db->prepare($sql);m4al_expect($s instanceof PDOStatement&&$s->execute($arguments),'M4AL_NATIVE_QUERY');
    try{$rows=$s->fetchAll(PDO::FETCH_ASSOC);m4al_expect(is_array($rows)&&array_is_list($rows),'M4AL_NATIVE_ROWS');return $rows;}
    finally{m4al_expect($s->closeCursor(),'M4AL_NATIVE_CURSOR_CLOSE');}
}
function m4al_one(PDO $db,string $table,string $pk,int|string $id):array
{
    m4al_expect(in_array($table,['eb_user','eb_system_admin','eb_division_agent_apply'],true)&&in_array($pk,['uid','id'],true),'M4AL_FIXED_ONE_ROW');
    $rows=m4al_rows($db,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'`=?',[$id]);m4al_expect(count($rows)===1,'M4AL_ONE_NATIVE_PARENT');return $rows[0];
}
function m4al_guard():array
{
    $manifest=audit_manifest();
    m4al_expect(getenv('AUDIT_LICENCE_NORMAL')==='1'&&($manifest['guard_mode']??null)==='read-only','M4AL_NORMAL_ONLY_PROFILE');
    audit_guard_assert();audit_assert_licence_private_mount();
    m4al_expect(($manifest['isolation']??null)==='ISO-FULL'&&($manifest['commit_sha']??null)===getenv('AUDIT_SOURCE_SHA')
        &&preg_match('/\A[a-f0-9]{40}\z/D',(string)getenv('AUDIT_SOURCE_SHA'))===1,'M4AL_CURRENT_COMMITTED_SOURCE_IDENTITY');
    m4al_expect(PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE===8&&function_exists('posix_geteuid'),'M4AL_REAL_PHP82_NATIVE_RUNTIME');
    m4al_expect(!file_exists(audit_repo_dir().'/crmeb/.env')&&!is_link(audit_repo_dir().'/crmeb/.env'),'M4AL_NO_SHARED_ENV');
    return $manifest;
}
function m4al_app(string $prefix):\think\App
{
    m4al_expect(empty($GLOBALS['m4al_app']),'M4AL_ONE_FIXTURE_APP');$GLOBALS['m4al_app']=true;
    require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
    require_once audit_repo_dir().'/crmeb/vendor/topthink/framework/src/helper.php';
    $config=audit_config();$app=new \think\App(audit_repo_dir().'/crmeb/');$runtime='/tmp/ms4-agent-licence-'.bin2hex(random_bytes(8)).'/';
    m4al_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4AL_OWN_FIXTURE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis',
        'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer'],
        'MARKETPLACE_CUSTOMER_LICENCE_PRIVATE_ROOT'=>M4AL_ROOT]);
    $app->initialize();m4al_expect($app->initialized()&&app()===$app&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('marketplace.customer_licence.private_root')===M4AL_ROOT,'M4AL_REAL_FIXTURE_APP_CONFIGURATION');
    date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));return $app;
}
function m4al_prepare():array
{
    $manifest=m4al_guard();$root=lstat(M4AL_ROOT);
    m4al_expect(is_array($root)&&!is_link(M4AL_ROOT)&&realpath(M4AL_ROOT)===M4AL_ROOT&&($root['mode']&0170000)===0040000
        &&$root['uid']===posix_geteuid()&&scandir(M4AL_ROOT)===['.','..'],'M4AL_FRESH_OWN_REGISTERED_PRIVATE_ROOT');
    m4al_expect(chmod(M4AL_ROOT,0700),'M4AL_OWN_PRIVATE_ROOT_MODE');
    m4al_expect(is_file('/run/audit-bootstrap/migrator.json'),'M4AL_BOOTSTRAP_ONLY_SCHEMA_PREREQUISITE');
    $migrator=json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'),true,16,JSON_THROW_ON_ERROR);
    m4al_expect(($migrator['username']??null)==='audit_migrator','M4AL_DEDICATED_MIGRATOR_ONLY');
    require_once audit_repo_dir().'/help/migrations/marketplace-ms4-assets.php';
    $ddl=new MarketplaceMs4AssetMigration(audit_pdo($migrator),'eb_','audit_auth');$before=$ddl->check();$apply=$ddl->applyStructure();$after=$ddl->check();$repeat=$ddl->applyStructure();
    m4al_expect($before['native_exit']===0&&$apply['native_exit']===0&&$after['native_exit']===0&&$after['state']==='completed'
        &&$repeat['native_exit']===0&&$repeat['changed']===false,'M4AL_ORIGINAL_ASSET_CREATE_CHECK_REPEAT');
    unset($ddl,$migrator);$db=audit_db();
    foreach(['eb_marketplace_customer_asset','eb_division_agent_apply']as$table)
        m4al_expect(m4al_rows($db,'SELECT * FROM `'.$table.'`')===[],'M4AL_NEW_INSTANCE_NO_EXISTING_ASSET_OR_APPLICATION');
    $accounts=json_decode(file_get_contents('/run/audit-fixtures/accounts.json'),true,32,JSON_THROW_ON_ERROR);
    m4al_expect(isset($accounts['user_101'],$accounts['user_102'],$accounts['admin_super'],$accounts['_permissions']['role']),'M4AL_ORIGINAL_SYNTHETIC_ACCOUNTS');
    $prefix='audit:http-lang:'.$manifest['run_id'].':'.(string)(int)floor(microtime(true)*1000).':';$app=m4al_app($prefix);
    $customer=$accounts['user_101'];$inviter=$accounts['user_102'];$admin=$accounts['admin_super'];
    m4al_expect((int)m4al_one($db,'eb_system_admin','id',$admin['id'])['level']===0,'M4AL_ORIGINAL_GRANTED_PLATFORM_SUPER');
    $divisionPassword=bin2hex(random_bytes(12));$divisionAccount='lic'.bin2hex(random_bytes(6));
    $ok=$app->make(\app\services\agent\DivisionServices::class)->divisionSave(['uid'=>$inviter['id'],'aid'=>0,
        'division_percent'=>1,'division_end_time'=>date('Y-m-d',time()+365*86400),'division_status'=>1,
        'division_name'=>'执照事业部','account'=>$divisionAccount,'pwd'=>$divisionPassword,'conf_pwd'=>$divisionPassword,
        'roles'=>[(int)$accounts['_permissions']['role']]]);
    m4al_expect($ok===true,'M4AL_REAL_DIVISION_INVITATION_PRODUCER');$division=m4al_one($db,'eb_user','uid',$inviter['id']);
    m4al_expect((int)$division['is_division']===1&&(int)$division['division_id']===(int)$inviter['id']
        &&preg_match('/\A[1-9][0-9]{7}\z/D',(string)$division['division_invite'])===1,'M4AL_REAL_SAVED_DIVISION_AND_INVITATION');
    $phone='199'.(string)random_int(10000000,99999999);$code=(string)random_int(100000,999999);
    m4al_expect($app->make(\app\services\user\UserServices::class)->update((int)$customer['id'],['phone'=>$phone],'uid')!==false,'M4AL_OWN_SYNTHETIC_CONTACT');
    $events=m4al_rows($db,"SELECT * FROM eb_system_event WHERE mark IN ('user_login','admin_login') AND is_del=0 AND is_open=1");
    m4al_expect($events===[],'M4AL_NO_EXTERNAL_CUSTOM_LOGIN_EVENTS');
    foreach(m4al_rows($db,"SELECT * FROM eb_system_notification WHERE custom_trigger='login_success'")as$row)
        foreach(['is_sms','is_wechat','is_routine','is_ent_wechat']as$key)m4al_expect((int)$row[$key]===0,'M4AL_NO_EXTERNAL_LOGIN_NOTICE');
    $queue=m4al_rows($db,"SELECT value FROM eb_system_config WHERE menu_name='queue_open'");
    m4al_expect(count($queue)===1&&json_decode($queue[0]['value'],true,16,JSON_THROW_ON_ERROR)===0,'M4AL_ORIGINAL_QUEUE_DISABLED');
    // Real original read producers, before the business baseline; no fabricated cache value is used as a target oracle.
    foreach(m4al_rows($db,'SELECT menu_name FROM eb_system_config ORDER BY id')as$row)\crmeb\services\SystemConfigService::get($row['menu_name']);
    getLang('操作成功');sys_data('admin_login_slide');
    $adminRow=m4al_one($db,'eb_system_admin','id',$admin['id']);
    $app->make(\app\services\system\SystemMenusServices::class)->getMenusList(explode(',',$adminRow['roles']),0);
    m4al_expect(\crmeb\services\CacheService::set('code_'.$phone,$code,600)===true,'M4AL_PRIVATE_SYNTHETIC_CODE_FIXTURE');
    $images=[];
    foreach(['a'=>[12,45,90],'b'=>[91,32,13],'c'=>[34,117,61]]as$name=>$rgb){
        $image=imagecreatetruecolor(16,16);m4al_expect($image instanceof GdImage,'M4AL_SYNTHETIC_PNG_CREATE');
        imagefill($image,0,0,imagecolorallocate($image,...$rgb));ob_start();m4al_expect(imagepng($image),'M4AL_SYNTHETIC_PNG_BYTES');$bytes=ob_get_clean();imagedestroy($image);
        m4al_expect(is_string($bytes)&&$bytes!==''&&getimagesizefromstring($bytes)[0]===16,'M4AL_SYNTHETIC_PNG_DECODE');
        $path='/run/audit-fixtures/licence-'.$name.'.png';m4al_write($path,$bytes);
        $images[$name]=['path'=>$path,'name'=>'licence-'.$name.'.png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'width'=>16,'height'=>16];
    }
    $legacy='/statics/system_images/admin_head_pic.png';
    m4al_expect(is_file(audit_repo_dir().'/crmeb/public'.$legacy)&&!is_link(audit_repo_dir().'/crmeb/public'.$legacy),'M4AL_REAL_LEGACY_STATIC_IMAGE');
    $fixture=['schema'=>1,'run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],'prefix'=>$prefix,'private_uid'=>posix_geteuid(),
        'customer'=>$customer,'admin'=>$admin,'division_id'=>(int)$inviter['id'],'invitation'=>(string)$division['division_invite'],
        'phone'=>$phone,'code'=>$code,'images'=>$images,'legacy'=>$legacy,'created_at'=>time()];
    m4al_write(M4AL_PRIVATE,m4al_json($fixture));
    return ['scope'=>'CU_LICENCE_NORMAL_PREPARATION_ONLY','status'=>'PREPARED_NOT_HTTP_VERIFIED','source_sha'=>$manifest['commit_sha'],
        'asset_structure_native_exits'=>[$before['native_exit'],$apply['native_exit'],$after['native_exit'],$repeat['native_exit']],
        'synthetic_images'=>3,'fixture_bytes'=>filesize(M4AL_PRIVATE),'fixture_sha256_prefix'=>substr(hash_file('sha256',M4AL_PRIVATE),0,12),
        'business_HTTP'=>'NOT RUN','browser'=>'NOT RUN','external_sms'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}

function m4al_database(PDO $db):array
{
    m4al_expect(!$db->inTransaction(),'M4AL_INDEPENDENT_OBSERVER_OUTSIDE_TRANSACTION');
    $tables=$db->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out=['hashes'=>[],'rows'=>[],'ddl'=>[],'next'=>[]];
    foreach($tables as$name=>$next){
        m4al_expect(preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D',$name)===1,'M4AL_COMPLETE_KNOWN_TABLE_NAMESPACE');
        $rows=m4al_rows($db,'SELECT * FROM `'.$name.'`');$serialized=array_map('serialize',$rows);sort($serialized,SORT_STRING);
        $out['hashes'][$name]=hash('sha256',serialize($serialized));
        if(in_array($name,M4AL_ROWS,true))$out['rows'][$name]=$rows;
        $create=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);
        m4al_expect(is_array($create)&&is_string($create[1]??null),'M4AL_COMPLETE_NATIVE_DDL');
        $out['ddl'][$name]=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$create[1]);$out['next'][$name]=$next===null?null:(string)$next;
    }
    foreach(M4AL_ROWS as$name)m4al_expect(isset($out['rows'][$name]),'M4AL_REQUIRED_ORIGINAL_TABLES');return $out;
}
function m4al_redis():array
{
    $redis=audit_redis();$names=$redis->keys('*');sort($names,SORT_STRING);$out=[];
    foreach($names as$key){$type=$redis->type($key);$value=match($type){Redis::REDIS_STRING=>$redis->get($key),Redis::REDIS_HASH=>$redis->hGetAll($key),
        Redis::REDIS_SET=>$redis->sMembers($key),default=>null};
        m4al_expect($value!==null&&$value!==false,'M4AL_ALL_REAL_REDIS_TYPES');
        if($type===Redis::REDIS_HASH)ksort($value,SORT_STRING);if($type===Redis::REDIS_SET)sort($value,SORT_STRING);
        $expires=$redis->rawCommand('PEXPIRETIME',$key);m4al_expect(is_int($expires)&&($expires===-1||$expires>0),'M4AL_REDIS_ABSOLUTE_EXPIRY');
        $out[$key]=['type'=>$type,'value'=>$value,'expires'=>$expires];
    }return $out;
}
function m4al_files(string $root,bool $private=false):array
{
    m4al_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'M4AL_REAL_FILE_ROOT');$files=[];$dirs=[];$owner=posix_geteuid();
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){m4al_expect(!$file->isLink(),'M4AL_NO_FILE_LINKS');$path=$file->getPathname();$relative=substr($path,strlen($root)+1);$stat=lstat($path);
        if($file->isDir()){$dirs[$relative]=[$stat['dev'],$stat['ino'],$stat['mode']&0777,$stat['uid']];
            if($private)m4al_expect(($stat['mode']&0777)===0700&&$stat['uid']===$owner,'M4AL_PRIVATE_PARENT_MODE_OWNER');continue;}
        m4al_expect($file->isFile()&&is_array($stat),'M4AL_REGULAR_OBSERVED_FILE');
        if($private)m4al_expect(($stat['mode']&0777)===0600&&$stat['uid']===$owner&&$stat['nlink']===1,'M4AL_PRIVATE_FILE_MODE_OWNER');
        $files[$relative]=['bytes'=>$stat['size'],'sha256'=>hash_file('sha256',$path),'mode'=>$stat['mode']&0777,'uid'=>$stat['uid'],'nlink'=>$stat['nlink']];
    }ksort($files,SORT_STRING);ksort($dirs,SORT_STRING);return ['files'=>$files,'directories'=>$dirs];
}
function m4al_state(PDO $db):array
{
    return ['database'=>m4al_database($db),'redis'=>m4al_redis(),'private'=>m4al_files(M4AL_ROOT,true),
        'public'=>m4al_files(audit_repo_dir().'/crmeb/public'),'runtime'=>m4al_files('/run/audit-app-runtime')];
}
function m4al_sorted(array $rows):array {$out=array_map('serialize',$rows);sort($out,SORT_STRING);return $out;}
function m4al_number(PDO $db,int|string $number):int|string {return $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)?(string)$number:(int)$number;}
function m4al_window(mixed $value,array $reply):void
{m4al_expect((is_int($value)||is_string($value))&&ctype_digit((string)$value)&&(int)$value>=$reply['start']&&(int)$value<=$reply['end'],'M4AL_REAL_HTTP_TIMESTAMP_WINDOW');}

/** Original fixed internal host; multipart uses a real PHP upload, never an in-memory Request substitute. */
function m4al_http(string $method,string $path,?string $token=null,?array $body=null,?array $image=null):array
{
    m4al_expect(in_array($method,['GET','POST'],true)&&str_starts_with($path,'/')&&!str_contains($path,'://'),'M4AL_FIXED_NORMAL_HTTP_PATH');
    $headers=['Cb-lang'=>'zh-CN'];if($token!==null)$headers['Authori-zation']='Bearer '.$token;
    $start=time();
    if($image===null){$reply=audit_http($method,'http://audit-app:8000'.$path,$headers,$body);}
    else{
        m4al_expect($method==='POST'&&$body===null&&is_file($image['path'])&&hash_file('sha256',$image['path'])===$image['sha256'],'M4AL_REAL_MULTIPART_FIXTURE');
        $curl=curl_init('http://audit-app:8000'.$path);$lines=[];foreach($headers as$key=>$value)$lines[]=$key.': '.$value;
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['filename'=>'pics','pics'=>new CURLFile($image['path'],'image/png',$image['name'])],
            CURLOPT_HTTPHEADER=>$lines,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'']);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
        m4al_expect(is_string($raw)&&$errno===0,'M4AL_NATIVE_MULTIPART_RETURNED');$decoded=json_decode($raw,true);$reply=['http'=>$status,'raw'=>$raw,'json'=>is_array($decoded)?$decoded:null];
    }
    $reply['start']=$start;$reply['end']=time();$GLOBALS['m4al_requests']=($GLOBALS['m4al_requests']??0)+1;return $reply;
}
function m4al_success(array $reply,bool $data=true):mixed
{
    $body=$reply['json'];m4al_expect($reply['http']===200&&is_array($body)&&($body['status']??null)===200&&is_string($body['msg']??null),'M4AL_REAL_NORMAL_HTTP_SUCCESS');
    if($data)m4al_expect(array_key_exists('data',$body)&&is_array($body['data']),'M4AL_REQUIRED_DATA_DTO');return $body['data']??null;
}
function m4al_content(string $path,string $token,array $image):array
{
    $headers=[];$curl=curl_init('http://audit-app:8000'.$path);$start=time();
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authori-zation: Bearer '.$token,'Cb-lang: zh-CN'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int{
            if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}return strlen($line);}]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    m4al_expect($errno===0&&$status===200&&is_string($raw)&&strlen($raw)===$image['bytes']&&hash('sha256',$raw)===$image['sha256']
        &&($headers['content-type']??null)==='image/png'&&($headers['content-length']??null)===(string)$image['bytes']
        &&($headers['x-content-type-options']??null)==='nosniff'&&str_contains(strtolower($headers['cache-control']??''),'no-store'),'M4AL_REAL_AUTHENTICATED_ORIGINAL_IMAGE');
    $GLOBALS['m4al_requests']=($GLOBALS['m4al_requests']??0)+1;return ['start'=>$start,'end'=>time()];
}

/** Complete native tables/files/Redis are compared, not a broad table or cache-prefix exception. */
function m4al_transition(PDO $db,array $before,array $after,array $fixture,array $reply,string $actor,array $changes=[],array $increments=[],?array $newFile=null,bool $upload=false):void
{
    $b=$before['database'];$a=$after['database'];m4al_expect(array_keys($a['hashes'])===array_keys($b['hashes'])&&$a['ddl']===$b['ddl'],'M4AL_ALL_TABLES_AND_DDL_RETAINED');
    $expected=$b['rows'];foreach($changes as$table=>$rows)$expected[$table]=$rows;
    if($actor==='customer'){
        $seen=0;foreach($expected['eb_user']as&$row)if((string)$row['uid']===(string)$fixture['customer']['id']){
            $matches=array_values(array_filter($a['rows']['eb_user'],fn(array $r):bool=>(string)$r['uid']===(string)$row['uid']));
            m4al_expect(count($matches)===1,'M4AL_EXACT_OWN_AUTH_ROW');m4al_window($matches[0]['last_time'],$reply);$row['last_time']=$matches[0]['last_time'];$seen++;
        }unset($row);m4al_expect($seen===1,'M4AL_ONLY_REAL_CUSTOMER_AUTH_TIMESTAMP');
    }
    foreach($a['hashes']as$table=>$hash){
        if(isset($expected[$table]))m4al_expect(m4al_sorted($a['rows'][$table])===m4al_sorted($expected[$table]),'M4AL_COMPLETE_EXPECTED_NATIVE_ROWS');
        else m4al_expect($hash===$b['hashes'][$table],'M4AL_OTHER_BUSINESS_EVENTS_AND_FINANCIAL_TABLES_UNCHANGED');
        $wanted=$b['next'][$table];if(isset($increments[$table]))$wanted=(string)((int)$wanted+$increments[$table]);
        m4al_expect($a['next'][$table]===$wanted,'M4AL_ONLY_EXPECTED_NATIVE_COUNTER_ADVANCE');
    }
    $redis=$before['redis'];
    if($upload){
        $key=$fixture['prefix'].'start_uploads_'.$fixture['customer']['id'];$tag=$fixture['prefix'].'CRMEB:'.md5('crmeb');$count=isset($redis[$key])?(int)$redis[$key]['value']:0;
        $new=$after['redis'][$key]??null;m4al_expect(is_array($new)&&$new['type']===Redis::REDIS_STRING&&$new['value']===(string)($count+1)
            &&$new['expires']>=$reply['start']*1000+86400000&&$new['expires']<=($reply['end']+1)*1000+86400000,'M4AL_EXACT_UPLOAD_QUOTA_VALUE_AND_DEADLINE');
        $redis[$key]=$new;$members=$redis[$tag]['value']??[];$members[]=$key;$members=array_values(array_unique($members));sort($members,SORT_STRING);
        $redis[$tag]=['type'=>Redis::REDIS_SET,'value'=>$members,'expires'=>-1];ksort($redis,SORT_STRING);
    }
    m4al_expect($after['redis']===$redis,'M4AL_ALL_REDIS_TYPES_VALUES_ABSOLUTE_EXPIRIES');
    m4al_expect($after['public']===$before['public']&&$after['runtime']===$before['runtime'],'M4AL_PUBLIC_AND_RUNTIME_FILES_UNCHANGED');
    $files=$before['private']['files'];$directories=$before['private']['directories'];
    if($newFile!==null){
        $key=$newFile['key'];m4al_expect(!isset($files[$key]),'M4AL_NEW_PRIVATE_OBJECT_ONLY');$files[$key]=$newFile['expected'];ksort($files,SORT_STRING);
        $path=dirname($key);$parents=[];while($path!=='.'){$parents[]=$path;$path=dirname($path);}
        foreach($parents as$path)if(!isset($directories[$path])){m4al_expect(isset($after['private']['directories'][$path]),'M4AL_ACTUAL_NEW_PRIVATE_PARENT');$directories[$path]=$after['private']['directories'][$path];}
        ksort($directories,SORT_STRING);
    }
    m4al_expect($after['private']===['files'=>$files,'directories'=>$directories],'M4AL_ALL_PRIVATE_OBJECTS_AND_PARENTS_EXPECTED');
}

function m4al_upload(PDO $db,array $fixture,string $token,int $context,string $which):array
{
    $image=$fixture['images'][$which];$before=m4al_state($db);$reply=m4al_http('POST','/api/agent/apply/'.$context.'/files',$token,null,$image);
    $data=m4al_success($reply);$after=m4al_state($db);$id=$before['database']['next']['eb_marketplace_customer_asset'];
    $rows=$after['database']['rows']['eb_marketplace_customer_asset'];$new=array_values(array_filter($rows,fn(array $r):bool=>(string)$r['id']===$id));
    m4al_expect(count($new)===1&&array_keys($new[0])===M4AL_FIELDS,'M4AL_EXACT_FULL34_NEW_STAGED');$row=$new[0];$uid=(int)$fixture['customer']['id'];
    $prefix='agent-licence/v1/'.$uid.'/'.$context.'/';
    m4al_expect(preg_match('~\A'.preg_quote($prefix,'~').'[a-f0-9]{64}/content\.png\z~D',$row['object_key'])===1,'M4AL_REAL_PRIVATE_APPLICATION_CONTEXT_PATH');
    m4al_window($row['created_at'],$reply);$expected=array_fill_keys(M4AL_FIELDS,null);
    $values=['id'=>$id,'uid'=>$uid,'purpose'=>'agent_licence','state'=>'STAGED','storage_type'=>1,'object_key'=>$row['object_key'],'thumbnail_key'=>'',
        'name'=>'content.png','real_name'=>$image['name'],'media_type'=>'image/png','byte_size'=>$image['bytes'],'width'=>16,'height'=>16,
        'content_sha256'=>hex2bin($image['sha256']),'created_at'=>(int)$row['created_at'],'created_by_uid'=>$uid];
    foreach($values as$key=>$value)$expected[$key]=in_array($key,['id','uid','storage_type','byte_size','width','height','created_at','created_by_uid'],true)?m4al_number($db,$value):$value;
    m4al_expect($row===$expected,'M4AL_ALL34_INDEPENDENT_UPLOAD_VALUES');$all=$before['database']['rows']['eb_marketplace_customer_asset'];$all[]=$expected;
    $file=['key'=>$row['object_key'],'expected'=>['bytes'=>$image['bytes'],'sha256'=>$image['sha256'],'mode'=>0600,'uid'=>$fixture['private_uid'],'nlink'=>1]];
    m4al_transition($db,$before,$after,$fixture,$reply,'customer',['eb_marketplace_customer_asset'=>$all],['eb_marketplace_customer_asset'=>1],$file,true);
    m4al_expect(array_keys($data)===['name','url','attachment_ref']&&$data['name']==='content.png'&&$data['attachment_ref']==='mp-licence:v1:'.$id
        &&$data['url']==='/api/agent/licence/'.$id.'/content'&&file_get_contents(M4AL_ROOT.'/'.$row['object_key'])===file_get_contents($image['path']),'M4AL_ORIGINAL_IMAGE_AND_NAMED_UPLOAD_DTO');
    return ['id'=>$id,'reference'=>$data['attachment_ref'],'row'=>$row,'image'=>$image];
}
function m4al_save(PDO $db,array $fixture,string $token,int $context,array $images):array
{
    $before=m4al_state($db);$body=['agent_name'=>'合成执照申请','name'=>'合成申请人','phone'=>$fixture['phone'],'code'=>$fixture['code'],
        'division_invite'=>$fixture['invitation'],'images'=>$images];
    $reply=m4al_http('POST','/api/agent/apply/'.$context,$token,$body);m4al_success($reply,false);$after=m4al_state($db);
    $id=$context===0?$before['database']['next']['eb_division_agent_apply']:(string)$context;
    $actual=array_values(array_filter($after['database']['rows']['eb_division_agent_apply'],fn(array $r):bool=>(string)$r['id']===$id));
    m4al_expect(count($actual)===1&&array_keys($actual[0])===M4AL_APPLICATION,'M4AL_REAL_SAVED_APPLICATION_FULL_ROW');$actual=$actual[0];m4al_window($actual['add_time'],$reply);
    $expected=['id'=>m4al_number($db,$id),'uid'=>m4al_number($db,$fixture['customer']['id']),'agent_name'=>$body['agent_name'],'name'=>$body['name'],
        'phone'=>$fixture['phone'],'division_id'=>m4al_number($db,$fixture['division_id']),'division_invite'=>m4al_number($db,$fixture['invitation']),
        'images'=>json_encode($images,JSON_THROW_ON_ERROR),'add_time'=>$actual['add_time'],'status'=>m4al_number($db,0),'refusal_reason'=>'','is_del'=>m4al_number($db,0)];
    $applications=$before['database']['rows']['eb_division_agent_apply'];
    if($context===0){foreach($applications as&$old)if((string)$old['uid']===(string)$fixture['customer']['id'])$old['is_del']=m4al_number($db,1);unset($old);$applications[]=$expected;}
    else{foreach($applications as&$old)if((string)$old['id']===$id){$expected['refusal_reason']=$old['refusal_reason'];$old=$expected;}unset($old);}
    m4al_expect($actual===$expected,'M4AL_ALL_ORIGINAL_APPLICATION_VALUES');$rows=$before['database']['rows']['eb_marketplace_customer_asset'];
    foreach($rows as&$row)if(in_array('mp-licence:v1:'.$row['id'],$images,true)&&$row['state']==='STAGED'){
        $new=array_values(array_filter($after['database']['rows']['eb_marketplace_customer_asset'],fn(array $r):bool=>(string)$r['id']===(string)$row['id']));
        m4al_expect(count($new)===1,'M4AL_ONE_NEW_BINDING');m4al_window($new[0]['bound_at'],$reply);
        $row['state']='BOUND';$row['agent_apply_id']=m4al_number($db,$id);$row['bound_at']=$new[0]['bound_at'];
    }unset($row);
    m4al_transition($db,$before,$after,$fixture,$reply,'customer',['eb_division_agent_apply'=>$applications,'eb_marketplace_customer_asset'=>$rows],
        $context===0?['eb_division_agent_apply'=>1]:[]);
    return $expected;
}
function m4al_read(PDO $db,array $fixture,string $token,array $asset,?array $application=null):void
{
    $before=m4al_state($db);$platform=$application!==null;
    $path=$platform?'/adminapi/agent/division/apply/'.$application['id'].'/licence/'.$asset['id'].'/content':'/api/agent/licence/'.$asset['id'].'/content';
    $reply=m4al_content($path,$token,$asset['image']);$after=m4al_state($db);
    [$changes,$increments]=$platform?m4al_log_delta($db,$before,$after,$fixture,$reply,'agent/division/apply/<apply_id>/licence/<asset_id>/content'):[[],[]];
    m4al_transition($db,$before,$after,$fixture,$reply,$platform?'platform':'customer',$changes,$increments);
}
function m4al_log_delta(PDO $db,array $before,array $after,array $fixture,array $reply,string $rule):array
{
    $id=$before['database']['next']['eb_system_log'];$rows=$after['database']['rows']['eb_system_log'];
    $new=array_values(array_filter($rows,fn(array $r):bool=>(string)$r['id']===$id));m4al_expect(count($new)===1,'M4AL_ONE_ORIGINAL_PLATFORM_ACCESS_LOG');$row=$new[0];
    $names=m4al_rows($db,'SELECT menu_name FROM eb_system_menus WHERE api_url=? LIMIT 1',[$rule]);$name=$names[0]['menu_name']??'未知';
    m4al_window($row['add_time'],$reply);$expected=['id'=>m4al_number($db,$id),'admin_id'=>m4al_number($db,$fixture['admin']['id']),
        'admin_name'=>$fixture['admin']['account'],'path'=>$rule,'page'=>$name?:'未知','ip'=>gethostbyname(gethostname()),
        'type'=>'system','add_time'=>$row['add_time'],'method'=>'adminapi','merchant_id'=>m4al_number($db,0)];
    m4al_expect(count($row)===count($expected)&&array_diff_key($row,$expected)===[],'M4AL_FULL_PLATFORM_LOG_COLUMNS');
    foreach($expected as$key=>$value)m4al_expect($row[$key]===$value,'M4AL_ORIGINAL_PLATFORM_LOG_VALUES');$all=$before['database']['rows']['eb_system_log'];$all[]=$row;
    return [['eb_system_log'=>$all],['eb_system_log'=>1]];
}
function m4al_history(PDO $db,array $fixture,string $token,array $application):void
{
    $before=m4al_state($db);$reply=m4al_http('GET','/api/agent/apply/info',$token);$data=m4al_success($reply);$after=m4al_state($db);
    $expected=$application;$expected['images']=json_decode($application['images'],true,16,JSON_THROW_ON_ERROR);
    $expected['add_time']=date('Y-m-d H:i:s',(int)$application['add_time']);
    m4al_expect($data===$expected,'M4AL_FULL_SAVED_APPLICATION_DTO_AND_ACTUAL_OWNER');
    m4al_transition($db,$before,$after,$fixture,$reply,'customer');
}
function m4al_legacy_image(PDO $db,array $fixture):void
{
    $before=m4al_state($db);$reply=m4al_http('GET',$fixture['legacy']);$after=m4al_state($db);
    $bytes=file_get_contents(audit_repo_dir().'/crmeb/public'.$fixture['legacy']);
    m4al_expect($reply['http']===200&&is_string($bytes)&&$bytes!==''&&$reply['raw']===$bytes,'M4AL_REAL_LEGACY_PUBLIC_IMAGE_PRESERVED');
    m4al_transition($db,$before,$after,$fixture,$reply,'public');
}

function m4al_verify():array
{
    $manifest=m4al_guard();$s=lstat(M4AL_PRIVATE);m4al_expect(is_array($s)&&($s['mode']&0777)===0600&&!is_link(M4AL_PRIVATE),'M4AL_PRIVATE_FIXTURE_FILE');
    $fixture=json_decode(file_get_contents(M4AL_PRIVATE),true,64,JSON_THROW_ON_ERROR);
    m4al_expect(($fixture['run_id']??null)===$manifest['run_id']&&($fixture['source_sha']??null)===$manifest['commit_sha']
        &&($fixture['private_uid']??null)===posix_geteuid(),'M4AL_CURRENT_RUN_SOURCE_AND_PRIVATE_OWNER');
    m4al_expect(time()-$fixture['created_at']>=0&&time()-$fixture['created_at']<300,'M4AL_FRESH_VALID_SYNTHETIC_CODE_WINDOW');
    m4al_app($fixture['prefix']);$db=audit_db();$started=false;$error=null;$closed=false;$cases=[];
    try{
        $started=true;audit_app_start(['CACHE_PREFIX'=>$fixture['prefix'],'LICENCE_PRIVATE_ROOT'=>M4AL_ROOT]);
        $login=m4al_success(m4al_http('POST','/api/login',null,['account'=>$fixture['customer']['account'],'password'=>$fixture['customer']['password'],'spread'=>0,'agent_id'=>0]));
        $owner=$login['token']??null;m4al_expect(is_string($owner)&&$owner!=='','M4AL_REAL_CUSTOMER_PASSWORD_LOGIN');
        $platform=m4al_success(m4al_http('POST','/adminapi/login',null,['account'=>$fixture['admin']['account'],'pwd'=>$fixture['admin']['password'],'key'=>'']));
        $admin=$platform['token']??null;m4al_expect(is_string($admin)&&$admin!=='','M4AL_REAL_PLATFORM_PASSWORD_LOGIN');
        $claims=(array)\Firebase\JWT\JWT::decode($owner,audit_config()['signing_key'],['HS256']);
        m4al_expect(($claims['jti']->type??null)==='api'&&(string)($claims['jti']->id??'')===(string)$fixture['customer']['id'],'M4AL_REAL_SIGNED_CUSTOMER_IDENTITY');
        $claims=(array)\Firebase\JWT\JWT::decode($admin,audit_config()['signing_key'],['HS256']);
        m4al_expect(($claims['auth_schema']??null)===2&&($claims['session_epoch']??null)===audit_config()['auth']['session_epoch']
            &&(string)($claims['jti']->id??'')===(string)$fixture['admin']['id'],'M4AL_REAL_SIGNED_PLATFORM_IDENTITY');
        // Warm the two real reader paths before the module baseline; this is not a substituted request or saved-row fixture.
        $history=m4al_success(m4al_http('GET','/api/agent/apply/info',$owner));m4al_expect(($history['status']??null)===-1,'M4AL_FIRST_REAL_APPLICATION_ABSENT');
        m4al_success(m4al_http('GET','/adminapi/agent/division/agent_apply/list?page=1&limit=20',$admin));
        $a=m4al_upload($db,$fixture,$owner,0,'a');$cases[]='real_multipart_first_staged_full34';m4al_read($db,$fixture,$owner,$a);
        $b=m4al_upload($db,$fixture,$owner,0,'b');$cases[]='real_multipart_unselected_staged_preserved';
        $legacy='http://audit-app:8000'.$fixture['legacy'];$application=m4al_save($db,$fixture,$owner,0,[$legacy,$a['reference']]);$cases[]='first_real_application_and_selected_binding';
        m4al_history($db,$fixture,$owner,$application);m4al_legacy_image($db,$fixture);
        $application=m4al_save($db,$fixture,$owner,(int)$application['id'],[$legacy,$a['reference']]);$cases[]='same_actual_id_resave_first_binding_unchanged';
        $c=m4al_upload($db,$fixture,$owner,(int)$application['id'],'c');
        $application=m4al_save($db,$fixture,$owner,(int)$application['id'],[$legacy,$a['reference'],$c['reference']]);$cases[]='owned_modification_and_second_selected_binding';
        m4al_history($db,$fixture,$owner,$application);
        foreach([$a,$c]as$asset)m4al_read($db,$fixture,$owner,$asset);
        $before=m4al_state($db);$reply=m4al_http('GET','/adminapi/agent/division/agent_apply/list?page=1&limit=20',$admin);$list=m4al_success($reply);$after=m4al_state($db);
        $matches=array_values(array_filter($list['list']??[],fn(array $r):bool=>(string)$r['id']===(string)$application['id']));
        m4al_expect(count($matches)===1&&$matches[0]['images']===[$legacy,$a['reference'],$c['reference']],'M4AL_ORIGINAL_GRANTED_LIST_SAVED_LOCATORS');
        [$changes,$increments]=m4al_log_delta($db,$before,$after,$fixture,$reply,'agent/division/agent_apply/list');
        m4al_transition($db,$before,$after,$fixture,$reply,'platform',$changes,$increments);
        foreach([$a,$c]as$asset)m4al_read($db,$fixture,$admin,$asset,$application);$cases[]='granted_platform_list_and_original_private_images';
        $application=m4al_save($db,$fixture,$owner,0,[$legacy,$a['reference'],$c['reference']]);$cases[]='owned_new_application_reuses_first_bound_assets';
        m4al_history($db,$fixture,$owner,$application);
        foreach([$a,$c]as$asset){m4al_read($db,$fixture,$owner,$asset);m4al_read($db,$fixture,$admin,$asset,$application);}
        $rows=m4al_rows($db,'SELECT * FROM eb_marketplace_customer_asset ORDER BY id');
        $unselected=array_values(array_filter($rows,fn(array $r):bool=>(string)$r['id']===$b['id']));
        m4al_expect($unselected===[$b['row']],'M4AL_UNSELECTED_ALL34_UNCHANGED');
    }catch(Throwable $failure){$error=$failure;}
    finally{if($started)try{audit_app_stop();$status=audit_read_control_json(audit_run_dir().'/app-status.json');
        m4al_expect(($status['status']??null)==='stop'&&($status['members']??null)===[]&&($status['listener_open']??null)===false
            &&($status['stop_mode']??null)==='term-only'&&($status['force_signal_sent']??null)===false,'M4AL_NORMAL_TERM_ONLY_APP_GROUP_CLOSED');$closed=true;
    }catch(Throwable $failure){$error??=$failure;}}
    if($error!==null)throw $error;
    return ['scope'=>'CU_LICENCE_REAL_NORMAL_HTTP_ONLY','source_sha'=>$manifest['commit_sha'],'business_cases'=>$cases,
        'requests'=>$GLOBALS['m4al_requests']??0,'assertions'=>$GLOBALS['m4al_assertions']??0,'normal_app_group_closed'=>$closed,
        'resource_retirement'=>'PENDING_ROOT_ORDINARY_CLOSE_AND_INDEPENDENT_ZERO','browser_and_device'=>'NOT RUN',
        'restricted_role_variant'=>'NOT RUN','external_sms_and_legacy_client_release'=>'NOT RUN','acceptance'=>'INCOMPLETE'];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $phase=$argv[1]??'';
    if(count($argv)!==2||!in_array($phase,['prepare','verify'],true)){fwrite(STDERR,"Usage: ms4-agent-licence-normal.php prepare|verify; registered new ISO-FULL normal instance only\n");exit(2);}
    umask(0077);putenv('AUDIT_MILESTONE=MS4');
    if($phase==='prepare'){
        try{$result=m4al_prepare();m4al_write(audit_run_dir().'/evidence/ms4-agent-licence-prepare.json',m4al_json($result));
            echo "PREPARED CU_LICENCE synthetic fixture only; real HTTP NOT RUN\n";exit(0);
        }catch(Throwable $error){$detail=preg_match('/\AM4AL_[A-Z0-9_]+\z/D',$error->getMessage())===1?$error->getMessage():get_class($error);
            fwrite(STDERR,'BLOCKED CU_LICENCE preparation '.$detail."\n");exit(2);}
    }
    audit_case('CU_LICENCE','normal-http','INTEGRATION',static function():array{
        $result=m4al_verify();m4al_write(audit_run_dir().'/evidence/ms4-agent-licence-verify.json',m4al_json($result));
        return audit_pass('real normal licence HTTP and independent native observations; external retirement still pending');
    });audit_finish();
}
