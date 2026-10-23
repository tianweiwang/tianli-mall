<?php
declare(strict_types=1);

/** Normal-chain observation only. No test identity, product write, alternate payment or schema model. */
const M4CF_ROOT='/run/audit-customer-form';
const M4CF_SOURCE='help/audit-regressions/isolated/lib/ms4-customer-form-normal.php';
function m4cf_import_paths():array
{
    return ['help/audit-regressions/isolated/ms4-aftersale-review-normal.php','help/audit-regressions/isolated/ms4-fulfillment-normal.php',
        'help/audit-regressions/isolated/ms5-paid-consumer-normal.php','help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php',
        'help/audit-regressions/isolated/lib/ms4-three-legacy-reference.php','help/audit-regressions/isolated/lib/ms4-three-read-normal.php'];
}
function m4cf_import_allowed():array
{
    if(empty($GLOBALS['m4cf_imported_observers']))return [];
    $freeze=audit_read_immutable_source_freeze(null,static function(bool $ok,string $code):void{m4c_expect($ok,'M4C_FORM_IMPORT_'.$code);});$allowed=[];
    foreach(m4cf_import_paths()as$name){$matches=array_values(array_filter($freeze['worker_source_files'],static fn(array $r):bool=>$r['path']===$name));
        m4cf_expect(count($matches)===1,'IMPORT_FINITE_UNION_MEMBER');$row=$matches[0];
        foreach(['/workspace','/source']as$root){$p=$root.'/'.$name;
            m4cf_expect(is_file($p)&&!is_link($p)&&realpath($p)===$p&&filesize($p)===$row['bytes']
                &&hash_file('sha256',$p)===$row['sha256'],'IMPORT_FINITE_ALLOWED_FULLBYTES');$allowed[$p]=true;}}
    return $allowed;
}

function m4cf_observers():void
{
    $freeze=audit_read_immutable_source_freeze(null,static function(bool $ok,string $code):void{m4c_expect($ok,'M4C_FORM_IMPORT_'.$code);});
    $required=m4cf_import_paths();
    foreach($required as$name){$matches=array_values(array_filter($freeze['worker_source_files'],static fn(array $r):bool=>$r['path']===$name));
        m4cf_expect(count($matches)===1,'IMPORT_FROZEN_COMPLETE_UNION_MEMBER');$row=$matches[0];
        foreach(['/workspace','/source']as$root){$p=$root.'/'.$name;
            m4cf_expect(is_file($p)&&!is_link($p)&&realpath($p)===$p&&filesize($p)===$row['bytes']
                &&hash_file('sha256',$p)===$row['sha256'],'IMPORT_ACTUAL_FROZEN_BYTES');}}
    $GLOBALS['m4cf_imported_observers']=true;
    // Imports definitions only: each original entry retains its real __FILE__ main gate.
    require_once __DIR__.'/../ms4-aftersale-review-normal.php';
    foreach(['m4ar_assertions','m4fn_assertions']as$name)$GLOBALS[$name]??=0;
}
function m4cf_expect(bool $value,string $code):void
{m4c_expect($value,'M4C_FORM_'.$code);}
function m4cf_enabled():bool
{return getenv('PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT')===M4CF_ROOT;}
function m4cf_base_environment(array $values):array
{
    if(array_key_exists('PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT',$values)){
        m4cf_expect($values['PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT']===M4CF_ROOT,'ONLY_KNOWN_ADDITIONAL_ENV');
        unset($values['PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT']);
    }
    m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    ksort($values,SORT_STRING);return $values;
}
function m4cf_environment(array $values,array $native):array
{
    $names=['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED',
        'PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER','PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT'];
    m4c_entry_keys($values,$names);m4cf_expect(m4cf_enabled()&&$values['PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT']===M4CF_ROOT,'REAL_PRIVATE_ROOT_ENV');
    foreach(['PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED']as$key)
        m4cf_expect($values[$key]==='true','ORIGINAL_ENABLED_FLAGS');
    m4cf_expect($values['APP_ENV']==='testing'&&$values['PHP_CACHE_DRIVER']==='redis'
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0,'ORIGINAL_REAL_ENV_VALUES');
    ksort($values,SORT_STRING);$lines=[];
    foreach($values as$key=>$value){m4cf_expect(getenv($key)===$value,'ACTUAL_PROCESS_ENV');$lines[]=json_encode($key.'='.$value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    $raw=implode("\n",$lines)."\n\n";
    m4cf_expect($native['stage']==='customer-public-environment-inspect'&&$native['native_exit']===0&&$native['stderr_bytes']===0
        &&$native['stderr_sha256']===hash('sha256','')&&$native['private_environment_logs_omitted']===true
        &&$native['stdout_bytes']===strlen($raw)&&$native['stdout_sha256']===hash('sha256',$raw),'COMPLETE_SEVEN_NATIVE_ENV_LINES');
    return $values;
}
function m4cf_snapshot(PDO $db):array
{
    m4cf_observers();$database=m5pcp_database_snapshot($db);$rows=[];
    foreach(m4ar_tables()as$table)$rows[$table]=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table));
    m4cf_expect(app()->config->get('marketplace.customer_form.private_root')===M4CF_ROOT&&m4cf_enabled(),'REAL_LOADED_CONFIG_ROOT');
    $s=lstat(M4CF_ROOT);m4cf_expect(is_array($s)&&is_dir(M4CF_ROOT)&&!is_link(M4CF_ROOT)&&realpath(M4CF_ROOT)===M4CF_ROOT
        &&($s['mode']&0777)===0700&&$s['uid']===posix_geteuid(),'DEPLOYED_ROOT_OWNER_MODE');
    return ['database'=>$database,'rows'=>$rows,'redis'=>m5pcp_atomic_redis(audit_redis()),
        'private'=>m4ar_files(M4CF_ROOT),'public'=>m4ar_files('/workspace/crmeb/public')];
}
function m4cf_files():array
{
    m4cf_observers();m4cf_expect(m4cf_enabled()&&app()->config->get('marketplace.customer_form.private_root')===M4CF_ROOT,'PHYSICAL_REAL_ROOT_CONFIG');
    return m4cf_native_files();
}
/** The original Worker parent is native observers only; it must not create a business App. */
function m4cf_native_files():array
{
    m4cf_observers();$s=lstat(M4CF_ROOT);
    m4cf_expect(m4cf_enabled()&&is_array($s)&&is_dir(M4CF_ROOT)&&!is_link(M4CF_ROOT)&&realpath(M4CF_ROOT)===M4CF_ROOT
        &&($s['mode']&0777)===0700&&$s['uid']===posix_geteuid(),'NATIVE_CONFIGURED_ROOT_OWNER_MODE');
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(M4CF_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$entry){$path=$entry->getPathname();$stat=lstat($path);
        m4cf_expect(!$entry->isLink()&&is_array($stat)&&realpath($path)===$path&&$stat['uid']===posix_geteuid()
            &&($entry->isDir()?($stat['mode']&0777)===0700:($entry->isFile()&&($stat['mode']&0777)===0600&&$stat['nlink']===1)),
            'NATIVE_COMPLETE_PRIVATE_TREE_TYPES_OWNER_MODES');}
    return m4ar_files(M4CF_ROOT);
}
function m4cf_replay(PDO $db,array $proof):void
{
    $actual=m5pcp_rows($db,'SELECT * FROM eb_marketplace_customer_asset');
    m4cf_expect($actual===$proof['rows']&&m4cf_files()===$proof['private'],'SAME_BODY_ASSET_ROWS_BOUND_TIME_AND_BYTES_UNCHANGED');
}
function m4cf_json(array $reply):array
{
    try{
    m4cf_expect($reply['http']===200&&is_array($reply['json']),'NORMAL_HTTP_JSON_200');
    m4c_entry_keys($reply['json'],['status','msg','data']);
    m4cf_expect($reply['json']['status']===200&&is_string($reply['json']['msg'])&&is_array($reply['json']['data']),'NORMAL_HTTP_BUSINESS_200');
    return $reply['json']['data'];
    }catch(Throwable $error){
        if(!isset($GLOBALS['m4cf_normal_http_first_failure'])&&is_array($reply['normal_http_diagnostic']??null))
            $GLOBALS['m4cf_normal_http_first_failure']=$reply['normal_http_diagnostic'];
        throw $error;
    }
}
/** Optional fixed scalar projection only; malformed diagnostic data never changes business assertions. */
function m4cf_public_http_failure():?array
{
    $row=$GLOBALS['m4cf_normal_http_first_failure']??null;
    if(!is_array($row)||array_keys($row)!==['kind','method','http','json_error','response_bytes','response_sha256'])return null;
    if(!in_array($row['kind'],['login','user','computed','upload','ticket','content','view'],true)
        ||!in_array($row['method'],['GET','POST'],true)||!is_int($row['http'])||$row['http']<0||$row['http']>599
        ||!($row['json_error']===null||(is_int($row['json_error'])&&$row['json_error']>=0&&$row['json_error']<=11))
        ||!is_int($row['response_bytes'])||$row['response_bytes']<0||$row['response_bytes']>52428800
        ||!is_string($row['response_sha256'])||preg_match('/\A[a-f0-9]{64}\z/D',$row['response_sha256'])!==1)return null;
    return ['kind'=>$row['kind'],'method'=>$row['method'],'http'=>$row['http'],'json_error'=>$row['json_error'],
        'response_bytes'=>$row['response_bytes'],'response_sha256'=>$row['response_sha256']];
}
function m4cf_confirmation(int $uid,string $key,array $frame):array
{
    m4cf_expect(app()->config->get('cache.default')==='redis'&&app()->config->get('cache.stores.redis.prefix')==='c:','REAL_CACHE_PREFIX');
    $physical='c:user_order_'.$uid.$key;
    $lua="local t=redis.call('TIME');return {redis.call('GET',KEYS[1]),redis.call('PEXPIRETIME',KEYS[1]),t}";
    $reply=audit_redis()->rawCommand('EVAL_RO',$lua,1,$physical);
    m4cf_expect(is_array($reply)&&count($reply)===3&&is_string($reply[0])&&is_int($reply[1])&&is_array($reply[2]),'NATIVE_CONFIRMATION_READ');
    $cached=unserialize($reply[0],['allowed_classes'=>false]);
    m4cf_expect(is_array($cached)&&serialize($cached)===$reply[0]
        &&\app\services\marketplace\order\MarketplaceCartSelection::frame($cached,$uid)===$frame,'ORIGINAL_CONFIRMATION_FRAME_BYTES');
    $now=bcadd(bcmul($reply[2][0],'1000',0),bcdiv($reply[2][1],'1000',0),0);
    m4cf_expect($reply[1]>(int)$now,'ORIGINAL_LIVE_DEADLINE');
    return ['physical'=>$physical,'raw'=>$reply[0],'deadline_ms'=>$reply[1],'time_ms'=>(int)$now];
}
function m4cf_same_confirmation(int $uid,string $key,array $frame,array $before):void
{
    $after=m4cf_confirmation($uid,$key,$frame);
    foreach(['physical','raw','deadline_ms']as$field)m4cf_expect($after[$field]===$before[$field],'CONFIRMATION_NOT_EXTENDED_OR_REPLACED');
}
function m4cf_fixture_images():array
{
    m4cf_observers();$png=m4ar_png();$image=imagecreatefromstring($png);m4cf_expect($image instanceof GdImage,'REAL_FIXTURE_IMAGE');
    ob_start();m4cf_expect(imagejpeg($image,null,92),'REAL_JPEG_ENCODE');$jpeg=ob_get_clean();
    ob_start();m4cf_expect(imagegif($image),'REAL_GIF_ENCODE');$gif=ob_get_clean();imagedestroy($image);
    $out=[];
    foreach([['bytes'=>$jpeg,'name'=>'normal-form.jpg','mime'=>'image/jpeg'],['bytes'=>$png,'name'=>'normal-form.png','mime'=>'image/png'],
        ['bytes'=>$gif,'name'=>'normal-form.gif','mime'=>'image/gif']]as$row){
        $size=getimagesizefromstring($row['bytes']);
        m4cf_expect(is_string($row['bytes'])&&$row['bytes']!==''&&$size[0]===1&&$size[1]===1
            &&(new finfo(FILEINFO_MIME_TYPE))->buffer($row['bytes'])===$row['mime']
            &&in_array(pathinfo($row['name'],PATHINFO_EXTENSION),app()->config->get('upload.fileExt'),true)
            &&in_array($row['mime'],app()->config->get('upload.fileMime'),true),'REAL_ALLOWED_NORMAL_BYTES');
        $out[]=$row+['width'=>1,'height'=>1,'sha256'=>hash('sha256',$row['bytes'],true)];
    }return $out;
}
function m4cf_login(PDO $db,array $source,array $private):array
{
    $uid=(int)$private['fixture']['user']['uid'];$before=m4cf_snapshot($db);$tag='c:CRMEB:'.md5('api');
    $GLOBALS['m4ar_tag_members'][$tag]=audit_redis()->sMembers($tag);
    $reply=m4cf_transport('POST','/api/login',null,['account'=>$private['account'],'password'=>$private['password'],'spread'=>0,'agent_id'=>0]);
    $data=m4cf_json($reply);$actor=['app'=>'api','id'=>$uid,'token'=>$data['token']??null];
    m4cf_expect(is_string($actor['token'])&&$actor['token']!=='','REAL_EXTRA_CREDENTIAL_TOKEN');
    $after=m4cf_snapshot($db);m4ar_customer_login_delta($before,$after,$actor,$reply);
    m4cf_expect($before['private']===$after['private']&&$before['public']===$after['public'],'LOGIN_NO_FILE_DELTA');
    return $actor;
}
function m4cf_upload(PDO $db,array $frame,int $uid,string $key,int $product,int $field,array $image,array $actor):array
{
    $ordinal=($GLOBALS['m4cf_upload_ordinal']??0)+1;$GLOBALS['m4cf_upload_ordinal']=$ordinal;
    $before=m4cf_snapshot($db);$confirm=m4cf_confirmation($uid,$key,$frame);$table='eb_marketplace_customer_asset';
    $meta=$before['database']['tables'][$table]['metadata'];
    m4cf_expect(array_keys($meta['columns'])===array_keys(m4ca_definitions()['marketplace_customer_asset']['columns']),'ORIGINAL_NATIVE34_SCHEMA');
    $id=m5pcp_u64($meta['table']['AUTO_INCREMENT']);$definition=null;
    foreach($frame['custom_form']as$group)if($group['product_id']===$product)$definition=$group;
    m4cf_expect(is_array($definition)&&$definition['fields'][$field]['label']==='img','REAL_FROZEN_IMAGE_FIELD');
    $counter='c:start_uploads_'.$uid;$old=audit_redis()->get($counter);$oldCount=$old===false?0:(is_string($old)&&preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$old)===1?(int)$old:null);
    m4cf_expect(is_int($oldCount)&&$oldCount>=0&&$oldCount<100&&($old===false||$old===(string)$oldCount),'EXACT_BEFORE_QUOTA');
    $tag='c:CRMEB:'.md5('crmeb');$GLOBALS['m4ar_tag_members'][$tag]=audit_redis()->sMembers($tag);
    $GLOBALS['m4cf_upload_diagnostic_context']=['upload_ordinal'=>$ordinal,'deadline_ms'=>$confirm['deadline_ms']];
    $reply=m4cf_multipart('/api/order/confirm/'.$key.'/forms/'.$product.'/'.$field.'/files',$actor['token'],$image['bytes'],$image['name'],$image['mime']);
    $data=m4cf_json($reply);m4c_entry_keys($data,['name','url','attachment_ref']);$after=m4cf_snapshot($db);
    $new=m4ar_new_rows($before['rows'][$table],$after['rows'][$table]);m4cf_expect(count($new)===1,'ONE_NEW_STAGED_NATIVE34');$row=$new[0];
    $extension=pathinfo($image['name'],PATHINFO_EXTENSION);
    $prefix='form/v1/'.$uid.'/'.hash('sha256',$key).'/'.$confirm['deadline_ms'].'/';
    m4cf_expect(is_string($row['object_key'])&&str_starts_with($row['object_key'],$prefix)
        &&preg_match('~\A'.preg_quote($prefix,'~').'[a-f0-9]{64}/content\.'.preg_quote($extension,'~').'\z~D',$row['object_key'])===1,'REAL_PRIVATE_RANDOM_PATH');
    $at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$row['created_at']);
    $values=['id'=>$id,'uid'=>$uid,'purpose'=>'order_form_image','state'=>'STAGED','storage_type'=>1,'object_key'=>$row['object_key'],
        'thumbnail_key'=>'','name'=>basename($row['object_key']),'real_name'=>$image['name'],'media_type'=>$image['mime'],
        'byte_size'=>(string)strlen($image['bytes']),'width'=>$image['width'],'height'=>$image['height'],'content_sha256'=>$image['sha256'],
        'created_at'=>$at,'expires_at'=>intdiv($confirm['deadline_ms'],1000),'bound_at'=>null,'retired_at'=>null,'root_order_id'=>null,
        'order_item_id'=>null,'refund_id'=>null,'review_id'=>null,'confirmation_ref'=>hash('sha256',$key,true),'form_product_id'=>$product,
        'form_definition_sha256'=>hex2bin($definition['definition_sha256']),'form_field_index'=>$field,'extract_id'=>null,'agent_apply_id'=>null,
        'source_product_id'=>null,'source_qrcode_id'=>null,'created_by_uid'=>$uid,'created_by_admin_id'=>null,'created_by_job'=>null,'legacy_att_id'=>null];
    m4cf_expect(count($values)===34,'COMPLETE_INDEPENDENT34_VALUES');$expected=m4fn_expected_row($meta,$row,$values);
    m4cf_expect(m5pcp_rows($db,'SELECT * FROM eb_marketplace_customer_asset WHERE id=?',[$id])===[$expected],'INDEPENDENT_NATIVE34_COMMITTED');
    $rows=[$table=>array_merge($before['rows'][$table],[$expected])];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$rows);
    m4ar_database_delta($before,$after,$rows,[$table=>1]);
    m4cf_expect(audit_redis()->get($counter)===(string)($oldCount+1),'EXACT_QUOTA_INCREMENT');$entry=$after['redis']['keys'][hash('sha256',$counter)];
    m4cf_expect($entry['type']==='string'&&$entry['expires']>=$reply['actual_interval'][0]*1000+86400000
        &&$entry['expires']<=($reply['actual_interval'][1]+1)*1000+86400000,'QUOTA_REAL_ABSOLUTE_DEADLINE');
    m4ar_redis_delta($before,$after,[$counter=>$entry]+m4ar_tag_change($before,$after,$tag,[$counter]));
    $want=$before['private'];$want[$row['object_key']]=['bytes'=>strlen($image['bytes']),'sha256'=>hash('sha256',$image['bytes']),
        'mode'=>0600,'uid'=>posix_geteuid(),'nlink'=>1];ksort($want,SORT_STRING);
    m4cf_expect($after['private']===$want&&$after['public']===$before['public']
        &&file_get_contents(M4CF_ROOT.'/'.$row['object_key'])===$image['bytes'],'ONLY_COMPLETE_NEW_PRIVATE_ORIGINAL');
    $cursor=M4CF_ROOT;foreach(explode('/',dirname($row['object_key']))as$part){$cursor.='/'.$part;$s=lstat($cursor);
        m4cf_expect(is_array($s)&&is_dir($cursor)&&!is_link($cursor)&&realpath($cursor)===$cursor
            &&($s['mode']&0777)===0700&&$s['uid']===posix_geteuid(),'REAL_PRIVATE_PARENT_0700_OWNER');}
    m4cf_expect($data['attachment_ref']==='mp-form:v1:'.$id&&$data['name']===$row['name']
        &&$data['url']==='http://audit-app:8000/api/order/confirm/'.$key.'/form-files/'.$id.'/content','REAL_NAMED_UPLOAD_DTO');
    m4cf_same_confirmation($uid,$key,$frame,$confirm);
    return ['row'=>$expected,'bytes'=>$image['bytes'],'reference'=>$data['attachment_ref']];
}
function m4cf_read(PDO $db,array $actor,string $kind,array $root,array $asset,string $base,?int $deadline=null):void
{
    $row=$asset['row'];$id=m5pcp_u64($row['id']);$before=m4cf_snapshot($db);
    $reply=m4cf_transport('POST',$base.'/read-ticket',$actor['token'],['schema'=>1]);$data=m4cf_json($reply);
    m4c_entry_keys($data,['read_ticket','issued_at_ms','expires_at_ms']);$ticket=$data['read_ticket'];
    m4cf_expect(is_string($ticket)&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket)===1
        &&is_int($data['issued_at_ms'])&&is_int($data['expires_at_ms']),'REAL_THREE_KEY_TICKET');
    $after=m4cf_snapshot($db);$issued=$data['issued_at_ms'];$expires=min($issued+300000,$deadline??PHP_INT_MAX);
    m4cf_expect($issued>=(int)$before['redis']['time_ms']&&$issued<=(int)$after['redis']['time_ms']
        &&$data['expires_at_ms']===$expires,'REAL_REDIS_MILLISECOND_TICKET_DEADLINE');
    $rootId=$kind==='confirmation'?null:(int)$root['id'];
    $payload=['schema'=>1,'reader_kind'=>$kind,'app'=>$actor['app'],'actor_type'=>$actor['app']==='api'?'customer':($actor['app']==='merchantapi'?'employee':'admin'),
        'actor_id'=>$actor['id'],'session_sha256'=>hash('sha256',$actor['token']),'uid'=>(int)$row['uid'],'asset_id'=>$id,
        'content_sha256'=>bin2hex($row['content_sha256']),'confirmation_ref'=>bin2hex($row['confirmation_ref']),
        'form_product_id'=>(int)$row['form_product_id'],'form_definition_sha256'=>bin2hex($row['form_definition_sha256']),
        'form_field_index'=>(int)$row['form_field_index'],'root_order_id'=>$rootId,'mer_id'=>(int)$root['mer_id'],
        'issued_at_ms'=>$issued,'expires_at_ms'=>$expires,'confirmation_deadline_ms'=>$deadline,'purpose'=>'order_form_read'];
    $key='c:mp_order_form_read:'.hash('sha256',$ticket);$hash=hash('sha256',$key);
    m4cf_expect(!isset($before['redis']['keys'][$hash])&&audit_redis()->get($key)===m5pcp_canonical($payload)
        &&audit_redis()->rawCommand('PEXPIRETIME',$key)===$expires,'EXACT_NEW_NX_TICKET_PAYLOAD');
    $rows=[];$counts=[];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$rows);
    if($actor['app']==='adminapi')m4ar_audit_delta($before,$after,$actor,['global'=>true,
        'rule'=>'order/info/<root_id>/form-files/<asset_id>/read-ticket'],$reply['actual_interval'],$rows,$counts);
    m4ar_database_delta($before,$after,$rows,$counts);m4ar_redis_delta($before,$after,[$key=>$after['redis']['keys'][$hash]]+m4ar_cache_deletes($before,$after,$actor));
    m4cf_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TICKET_FILES_UNCHANGED');
    $reply=m4cf_transport('GET',$base.'/content',$actor['token'],null,null,$ticket);$last=m4cf_snapshot($db);
    m4cf_expect($reply['http']===200&&$reply['raw']===$asset['bytes']&&($reply['headers']['content-type']??null)===$row['media_type']
        &&($reply['headers']['content-length']??null)===(string)strlen($asset['bytes'])
        &&($reply['headers']['cache-control']??null)==='private, no-store, max-age=0'
        &&($reply['headers']['x-content-type-options']??null)==='nosniff','COMPLETE_ORIGINAL_BINARY_HEADERS');
    $rows=[];$counts=[];m4ar_auth_delta($after,$last,$actor,$reply['actual_interval'],$rows);
    if($actor['app']==='adminapi')m4ar_audit_delta($after,$last,$actor,['global'=>true,
        'rule'=>'order/info/<root_id>/form-files/<asset_id>/content'],$reply['actual_interval'],$rows,$counts);
    m4ar_database_delta($after,$last,$rows,$counts);m4ar_redis_delta($after,$last,[$key=>null]+m4ar_cache_deletes($after,$last,$actor));
    m4cf_expect(audit_redis()->exists($key)===0&&$after['private']===$last['private']&&$after['public']===$last['public'],'NATIVE_ONCE_CONSUMED_NO_FILE_CHANGE');
    $GLOBALS['m4cf_read_observations'][]=['reader_kind'=>$kind,'root_id'=>$rootId,'asset_id'=>$id,
        'content_sha256'=>hash('sha256',$reply['raw']),'bytes'=>strlen($reply['raw']),
        'issued_at_ms'=>$issued,'expires_at_ms'=>$expires,'native_once_consumed'=>true,'full_original_verified'=>true];
}
function m4cf_stage(PDO $db,array $source,array $fixture,array $frame,int $uid,string $key,array $actor):array
{
    $before=m4cf_snapshot($db);$confirmation=m4cf_confirmation($uid,$key,$frame);$images=m4cf_fixture_images();$answers=[];$assets=[];$selected=[];
    m4cf_expect(count($frame['custom_form'])===2,'ORIGINAL_TWO_FORM_GROUPS');
    foreach($frame['custom_form']as$groupIndex=>$definition){$product=$fixture['products'][$definition['product_id']];
        $raw=json_decode($product['custom_form'],true,32,JSON_THROW_ON_ERROR);$fields=$values=[];
        foreach($raw as$i=>$field){$fields[]=['field_index'=>$i]+$field;
            if($field['label']!=='img')$value=$field['label']==='number'?'0':'正常HTTP填写'.$definition['product_name'];
            elseif($groupIndex===0&&$field['status']){
                $a=m4cf_upload($db,$frame,$uid,$key,$definition['product_id'],$i,$images[0],$actor);
                $b=m4cf_upload($db,$frame,$uid,$key,$definition['product_id'],$i,$images[1],$actor);
                $unused=m4cf_upload($db,$frame,$uid,$key,$definition['product_id'],$i,$images[1],$actor);
                foreach([$a,$b,$unused]as$asset)$assets[m5pcp_u64($asset['row']['id'])]=$asset;
                $value=[$a['reference'],$b['reference'],$a['reference'],$b['reference'],$a['reference'],$b['reference'],$a['reference'],$b['reference']];
                $selected[]=(string)$a['row']['id'];$selected[]=(string)$b['row']['id'];
            }elseif($groupIndex===1){
                $a=m4cf_upload($db,$frame,$uid,$key,$definition['product_id'],$i,$images[2],$actor);$assets[m5pcp_u64($a['row']['id'])]=$a;
                $selected[]=(string)$a['row']['id'];$value=[$a['reference']];
            }else $value=[];
            $values[]=['field_index'=>$i,'value'=>$value];
        }
        m4cf_expect($definition['fields']===$fields&&$definition['definition_sha256']===hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical([
            'product_id'=>$definition['product_id'],'fields'=>$fields])),'INDEPENDENT_ORIGINAL_FROZEN_DEFINITION');
        $answers[]=['product_id'=>$definition['product_id'],'definition_sha256'=>$definition['definition_sha256'],'values'=>$values];
    }
    m4cf_expect(count($assets)===4&&count($selected)===3,'NORMAL_MATRIX_DISTINCT_SELECTED_AND_UNSELECTED');
    foreach($selected as$id)m4cf_read($db,$actor,'confirmation',['mer_id'=>$frame['mer_id']],$assets[$id],
        '/api/order/confirm/'.$key.'/form-files/'.$id,$confirmation['deadline_ms']);
    $quotedBefore=m4cf_snapshot($db);$reply=m4cf_transport('POST','/api/order/computed/'.rawurlencode($key),$actor['token'],
        ['addressId'=>(int)$fixture['address']['id'],'shipping_type'=>1,'payType'=>'','mer_id'=>$frame['mer_id'],'couponId'=>0,'useIntegral'=>0]);
    $quote=m4cf_json($reply);m4cf_expect(($quote['status']??null)==='NONE'&&($quote['result']['marketplace_create_enabled']??null)===true,'ORIGINAL_NORMAL_REPRICE_RESULT');
    $member=($source['rule']['proof']??null)==='aftersale-review-normal';
    m4cf_expect(in_array($source['rule']['proof']??null,['paid-producer','aftersale-review-normal','customer'],true),'ORIGINAL_NORMAL_REPRICE_PRODUCER');
    if($member)m4cf_expect((int)$fixture['user']['is_money_level']===1&&(int)$fixture['user']['is_ever_level']===0
        &&bccomp(m5pcp_u64($fixture['user']['overdue_time']),(string)time(),0)>0,'ORIGINAL_AR_REAL_MEMBER_REPRICE');
    m4cb_public_money($quote['result'],'pay_price',$member?'76.95':'78.56');m4cb_public_money($quote['result'],'pay_postage',$member?'6.40':'8.01');
    $quotedAfter=m4cf_snapshot($db);$auth=[];m4ar_auth_delta($quotedBefore,$quotedAfter,$actor,$reply['actual_interval'],$auth);
    m4ar_database_delta($quotedBefore,$quotedAfter,$auth);m4ar_redis_delta($quotedBefore,$quotedAfter);
    m4cf_expect($quotedBefore['private']===$quotedAfter['private']&&$quotedBefore['public']===$quotedAfter['public'],'REPRICE_KEEPS_COMPLETE_ORIGINAL_FILES');
    m4cf_same_confirmation($uid,$key,$frame,$confirmation);
    return ['before'=>$before,'after'=>m4cf_snapshot($db),'answers'=>$answers,'assets'=>$assets,'selected'=>$selected,
        'confirmation'=>$confirmation,'uid'=>$uid,'key'=>$key];
}
function m4cf_bound(PDO $db,array $stage,array $root,array $observation,array $actor):array
{
    $after=m4cf_snapshot($db);$table='eb_marketplace_customer_asset';$rows=$stage['after']['rows'][$table];$bound=[];
    foreach($stage['assets']as$id=>$asset){$row=m4ar_one($after['rows'][$table],'id',$id);$expected=$asset['row'];
        if(in_array((string)$id,$stage['selected'],true)){$expected['state']='BOUND';$expected['root_order_id']=m4fn_native_value($after['database']['tables'][$table]['metadata']['columns']['root_order_id'],$root['id']);
            $expected['bound_at']=m4fn_native_value($after['database']['tables'][$table]['metadata']['columns']['bound_at'],
                m4ar_time($observation['actual_interval'][0],$observation['actual_interval'][1],$row['bound_at']));}
        m4cf_expect($row===$expected,'ONLY_THREE_BOUND_COLUMNS_OTHER31_PRESERVED');$rows=m4ar_replaced($rows,'id',$id,$expected);$bound[(string)$id]=['row'=>$expected,'bytes'=>$asset['bytes'],'reference'=>$asset['reference']];
        m4cf_expect(file_get_contents(M4CF_ROOT.'/'.$row['object_key'])===$asset['bytes'],'BOUND_ORIGINAL_FULL_BYTES');
    }
    m4cf_expect($after['rows'][$table]===$rows&&$after['private']===$stage['after']['private'],'ALL_ASSET_ROWS_AND_PHYSICAL31_RETAINED');
    $auth=[];m4ar_auth_delta($stage['after'],$after,$actor,$observation['actual_interval'],$auth);
    return ['assets'=>$bound,'rows'=>$rows,'asset_inserts'=>count($stage['assets']),'owner_after'=>m4ar_one($after['rows']['eb_user'],'uid',$stage['uid']),
        'private'=>$after['private'],'uid'=>$stage['uid'],'root_id'=>(int)$root['id'],'root_no'=>$root['order_id'],'complete34_verified'=>true,
        'only_three_bound_columns'=>true,'original_bytes_retained'=>true,
        'staged_previews'=>array_values(array_filter($GLOBALS['m4cf_read_observations']??[],static fn(array $r):bool=>
            $r['reader_kind']==='confirmation'&&in_array($r['asset_id'],$stage['selected'],true)))];
}
function m4cf_owner_after(array $proof,array $original,int $uid):array
{
    $proof=m4cf_restore($proof);
    m4cf_expect($proof['uid']===$uid&&$proof['complete34_verified']===true&&$proof['only_three_bound_columns']===true
        &&$proof['original_bytes_retained']===true,'PRIVATE_FORM_PROOF_COMPLETE');
    m4cb_owner_auth_only([$original],[$proof['owner_after']],$uid,true);
    m4cf_expect(m4cf_files()===$proof['private'],'SEALED_PAID_PRIVATE_ORIGINALS_STILL_IDENTICAL');
    return $proof['owner_after'];
}
function m4cf_saved_readers(PDO $db,array $root,array $actors):void
{
    $uid=(int)$root['uid'];$id=(int)$root['id'];$number=$root['order_id'];$customer=$actors['customer'];
    m4cf_expect($customer['id']===$uid,'ORIGINAL_READER_OWNER');$assets=[];
    foreach(json_decode($root['custom_form'],true,32,JSON_THROW_ON_ERROR)as$group)foreach($group['fields']as$field)if($field['label']==='img')foreach($field['value']as$ref){
        m4cf_expect(is_string($ref)&&preg_match('/\Amp-form:v1:([1-9][0-9]{0,19})\z/D',$ref,$m)===1,'SAVED_PRIVATE_REFERENCE_ONLY');$assetId=m5pcp_u64($m[1]);
        if(isset($assets[$assetId]))continue;$row=m4ar_one(m4cf_snapshot($db)['rows']['eb_marketplace_customer_asset'],'id',$assetId);
        m4cf_expect($row['state']==='BOUND'&&(int)$row['root_order_id']===$id&&(int)$row['uid']===$uid,'REAL_SAME_BOUND_ROOT');
        $bytes=file_get_contents(M4CF_ROOT.'/'.$row['object_key']);$size=getimagesizefromstring($bytes);
        m4cf_expect(is_string($bytes)&&strlen($bytes)===(int)$row['byte_size']&&hash('sha256',$bytes,true)===$row['content_sha256']
            &&$size[0]===(int)$row['width']&&$size[1]===(int)$row['height']
            &&(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)===$row['media_type'],'COMPLETE_SAVED_ORIGINAL_NATIVE_PHYSICAL');
        $assets[$assetId]=['row'=>$row,'bytes'=>$bytes];}
    foreach(['customer_order'=>['actor'=>$customer,'view'=>'/api/order/detail/'.$number,'base'=>'/api/order/'.$id],
        'merchant_order'=>['actor'=>$actors['merchant'],'view'=>'/merchantapi/orders/'.$id,'base'=>'/merchantapi/orders/'.$id],
        'platform_order'=>['actor'=>$actors['platform'],'view'=>'/adminapi/order/info/'.$id,'base'=>'/adminapi/order/info/'.$id],
        'mobile_order'=>['actor'=>$customer,'view'=>'/api/admin/order/detail/'.$number,'base'=>'/api/admin/order/detail/'.$id]]as$kind=>$spec){
        $before=m4cf_snapshot($db);$reply=m4cf_transport('GET',$spec['view'],$spec['actor']['token']);$view=m4cf_json($reply);
        if($kind==='platform_order')$view=$view['orderInfo'];
        m4cf_expect((int)$view['id']===$id&&$view['order_id']===$number&&$view['marketplace_trade_version']===1
            &&$view['custom_form']===json_decode($root['custom_form'],true,32,JSON_THROW_ON_ERROR),'REAL_AUTHORIZED_SAVED_ROOT_VIEW');
        $after=m4cf_snapshot($db);$rows=[];$counts=[];m4ar_auth_delta($before,$after,$spec['actor'],$reply['actual_interval'],$rows);
        if($kind==='platform_order')m4ar_audit_delta($before,$after,$spec['actor'],['global'=>true,'rule'=>'order/info/<id>'],$reply['actual_interval'],$rows,$counts);
        m4ar_database_delta($before,$after,$rows,$counts);m4ar_redis_delta($before,$after,m4ar_cache_deletes($before,$after,$spec['actor']));
        m4cf_expect($before['private']===$after['private']&&$before['public']===$after['public'],'REAL_VIEW_FILES_UNCHANGED');
        foreach($assets as$assetId=>$asset)m4cf_read($db,$spec['actor'],$kind,$root,$asset,$spec['base'].'/form-files/'.$assetId);
    }
}

/** Only the two original authorized package DTOs provide the saved root locator. */
function m4cf_package_readers(PDO $db,array $root,array $actors,array $child):void
{
    $id=(int)$root['id'];$number=$root['order_id'];
    m4cf_expect((int)$child['pid']===$id&&(int)$child['uid']===(int)$root['uid'],'REAL_CHILD_SAVED_ROOT_RELATION');
    foreach([['actor'=>$actors['customer'],'path'=>'/api/order/fulfillment/packages/'.$child['order_id']],
        ['actor'=>$actors['merchant'],'path'=>'/merchantapi/packages/'.$child['id']]]as$spec){
        $before=m4cf_snapshot($db);$reply=m4cf_transport('GET',$spec['path'],$spec['actor']['token']);$data=m4cf_json($reply);
        $package=$data['package']??null;
        m4cf_expect(is_array($package)&&(int)$package['id']===(int)$child['id']&&$package['order_id']===$child['order_id']
            &&$package['root_order_id']===$id&&$package['root_order_no']===$number&&$package['marketplace_trade_version']===1
            &&$package['custom_form']===json_decode($root['custom_form'],true,32,JSON_THROW_ON_ERROR),'ACTUAL_PACKAGE_DTO_SAME_SAVED_FORM_ROOT');
        $after=m4cf_snapshot($db);$rows=[];m4ar_auth_delta($before,$after,$spec['actor'],$reply['actual_interval'],$rows);
        m4ar_database_delta($before,$after,$rows);m4ar_redis_delta($before,$after,m4ar_cache_deletes($before,$after,$spec['actor']));
        m4cf_expect($before['private']===$after['private']&&$before['public']===$after['public'],'PACKAGE_VIEW_ALL_ORIGINAL_BYTES_HELD');
        $GLOBALS['m4cf_package_observations'][]=['root_id'=>$id,'root_no'=>$number,'child_id'=>(int)$child['id'],
            'child_no'=>$child['order_id'],'app'=>$spec['actor']['app'],'saved_form_sha256'=>hash('sha256',$root['custom_form'])];
    }
    // Read services still take the real root PK; a package PK is never passed as a root or an authorization.
    m4cf_saved_readers($db,$root,$actors);
}

/** Passive normal lifecycle observation, never TTL/time mutation or an expired-request sample. */
function m4cf_historical_readers(PDO $db,array $root,array $actors):void
{
    $before=m4cf_snapshot($db);$deadline=0;$count=0;
    foreach($before['rows']['eb_marketplace_customer_asset']as$row)if($row['state']==='BOUND'&&(int)$row['root_order_id']===(int)$root['id']){
        m4cf_expect(preg_match('~\Aform/v1/[1-9][0-9]*/[a-f0-9]{64}/([1-9][0-9]{12,15})/[a-f0-9]{64}/content\.[a-z]+\z~D',$row['object_key'],$m)===1,
            'ORIGINAL_OBJECT_RETAINS_ACTUAL_CONFIRMATION_DEADLINE');
        $native=(int)$m[1];m4cf_expect((string)$native===$m[1]&&intdiv($native,1000)===(int)$row['expires_at'],'NATIVE_ACTUAL_ORIGINAL_CONFIRMATION_DEADLINE');
        $deadline=max($deadline,$native);++$count;
    }
    m4cf_expect($count===3&&$deadline>0&&$deadline<=(int)$before['redis']['time_ms']+600000,'ONLY_ORIGINAL_BOUND_NORMAL_LIFECYCLE');
    $started=hrtime(true);$previous=['redis'=>$before['redis']];
    while((int)$previous['redis']['time_ms']<=$deadline){
        m4cf_expect(empty($GLOBALS['m4c_terminate_requested'])&&hrtime(true)-$started<=610000000000,'NORMAL_LIFECYCLE_MONOTONIC_BOUND');
        usleep(200000);$current=['redis'=>m5pcp_atomic_redis(audit_redis())];m4ar_redis_delta($previous,$current);$previous=$current;
    }
    $after=m4cf_snapshot($db);m4ar_database_delta($before,$after,[]);m4ar_redis_delta($before,$after);
    m4cf_expect($after['private']===$before['private']&&$after['public']===$before['public'],'HISTORICAL_ROWS_AND_COMPLETE_ORIGINAL_BYTES_RETAINED');
    $GLOBALS['m4cf_historical_observations'][]=['root_id'=>(int)$root['id'],'actual_confirmation_deadline_ms'=>$deadline,
        'observed_redis_time_ms'=>(int)$after['redis']['time_ms'],'private_map_sha256'=>hash('sha256',serialize($after['private']))];
    m4cf_saved_readers($db,$root,$actors);
}

/** Real installed customer-workbench identity for this existing buyer; no merchant role or permission bypass. */
function m4cf_mobile_fixture(\think\App $app,PDO $db,array $owner):void
{
    $before=m4cf_snapshot($db);$uid=$owner['uid'];$table='eb_store_service';$user=m4ar_one($before['rows']['eb_user'],'uid',$uid);
    $service=$app->make(\app\services\kefu\service\StoreServiceServices::class);
    $phone='199'.(string)random_int(10000000,99999999);
    m4cf_expect($service->count(['uid'=>$uid])===0&&check_phone($phone)
        &&$service->count(['phone'=>$phone])===0,'ACTUAL_BUYER_VALID_UNASSIGNED_MOBILE_IDENTITY');
    $account='CFN'.bin2hex(random_bytes(10));$password=bin2hex(random_bytes(9));$hash=password_hash($password,PASSWORD_DEFAULT);
    m4cf_expect(preg_match('/\A[a-zA-Z0-9]{4,30}\z/D',$account)===1&&preg_match('/\A[0-9a-z_$]{6,20}\z/Di',$password)===1
        &&is_string($hash)&&password_verify($password,$hash)&&$service->count(['account'=>$account])===0,'ORIGINAL_CUSTOMER_SERVICE_PRODUCER_VALIDATION');
    $started=time();$data=['uid'=>$uid,'avatar'=>$user['avatar'],'customer'=>1,'notify'=>0,'phone'=>$phone,
        'account'=>$account,'password'=>$hash,'nickname'=>$user['nickname'],'status'=>1,'add_time'=>$started];
    $created=$service->save($data);$ended=time();unset($password);
    m4cf_expect($created instanceof \app\model\service\StoreService,'ACTUAL_CUSTOMER_SERVICE_MODEL_CREATED');
    $after=m4cf_snapshot($db);$added=m4ar_new_rows($before['rows'][$table],$after['rows'][$table]);m4cf_expect(count($added)===1,'EXACT_ONE_MOBILE_IDENTITY_ROW');
    $row=$added[0];$meta=$before['database']['tables'][$table]['metadata'];$id=m5pcp_u64($meta['table']['AUTO_INCREMENT']);
    $values=['id'=>$id,'mer_id'=>0,'uid'=>$uid,'online'=>0,'account'=>$account,'password'=>$hash,'avatar'=>$user['avatar'],
        'nickname'=>$user['nickname'],'phone'=>$phone,'add_time'=>m4ar_time($started,$ended,$row['add_time']),
        'status'=>1,'notify'=>0,'customer'=>1,'uniqid'=>''];
    $expected=m4fn_expected_row($meta,$row,$values);m4ar_database_delta($before,$after,[$table=>array_merge($before['rows'][$table],[$expected])],[$table=>1]);
    m4ar_redis_delta($before,$after);m4cf_expect($before['private']===$after['private']&&$before['public']===$after['public']
        &&$service->checkoutIsService(['uid'=>$uid,'status'=>1,'customer'=>1])===true,'REAL_MOBILE_MIDDLEWARE_PRODUCER_READY_NO_OTHER_DELTA');
}

function m4cf_saved_observations():array
{
    return ['reads'=>$GLOBALS['m4cf_read_observations']??[],'packages'=>$GLOBALS['m4cf_package_observations']??[],
        'historical'=>$GLOBALS['m4cf_historical_observations']??[]];
}
function m4cf_saved_receipt():array
{
    return ['schema'=>1,'scope'=>'CU_FORM_NORMAL_SAVED_READERS','roots_read'=>2,'staged_reader'=>true,
        'saved_reader_kinds'=>['customer_order','merchant_order','platform_order','mobile_order'],
        'package_root_locators'=>true,'historical_bound_after_actual_confirmation_deadline'=>true,'original_full_bytes'=>true];
}
function m4cf_saved_observations_check(PDO $db,array $observations,array $receipt,array $roots):void
{
    m4c_entry_same($receipt,m4cf_saved_receipt());m4c_entry_keys($observations,['reads','packages','historical']);
    m4cf_expect(count($roots)===2&&count(array_unique($roots))===2,'TWO_ORIGINAL_REAL_SAVED_ROOTS');$seen=[];$staged=0;
    foreach($observations['reads']as$read){
        m4c_entry_keys($read,['reader_kind','root_id','asset_id','content_sha256','bytes','issued_at_ms','expires_at_ms','native_once_consumed','full_original_verified']);
        $asset=m4ar_one(m5pcp_rows($db,'SELECT * FROM eb_marketplace_customer_asset'),'id',$read['asset_id']);
        m4cf_expect($read['content_sha256']===bin2hex($asset['content_sha256'])&&$read['bytes']===(int)$asset['byte_size']
            &&$read['native_once_consumed']===true&&$read['full_original_verified']===true
            &&is_int($read['issued_at_ms'])&&is_int($read['expires_at_ms'])&&$read['expires_at_ms']>$read['issued_at_ms']
            &&$read['expires_at_ms']<=$read['issued_at_ms']+300000,'ACTUAL_FULL_BINARY_AND_CONSUMED_TICKET_OBSERVATION');
        if($read['reader_kind']==='confirmation'){m4cf_expect($read['root_id']===null,'STAGED_PREVIEW_REAL_UNBOUND_LOCATOR');++$staged;continue;}
        m4cf_expect(in_array($read['root_id'],$roots,true)&&in_array($read['reader_kind'],$receipt['saved_reader_kinds'],true)
            &&(int)$asset['root_order_id']===$read['root_id']&&$asset['state']==='BOUND','SAVED_OBSERVATION_REAL_BOUND_ROOT');
        $seen[$read['root_id']][$read['reader_kind']][$read['asset_id']]=true;
    }
    m4cf_expect($staged===3,'SECOND_PRODUCER_THREE_ACTUAL_STAGED_PREVIEWS');
    foreach($roots as$id)foreach($receipt['saved_reader_kinds']as$kind)m4cf_expect(count($seen[$id][$kind]??[])===3,'ALL_FOUR_SAVED_READERS_THREE_DISTINCT_ORIGINALS');
    m4cf_expect(count($observations['packages'])===2&&array_column($observations['packages'],'app')===['api','merchantapi'],'ACTUAL_CUSTOMER_MERCHANT_PACKAGE_VIEWS');
    foreach($observations['packages']as$package){$row=m4ar_one(m5pcp_rows($db,'SELECT * FROM eb_store_order'),'id',$package['child_id']);
        $root=m4ar_one(m5pcp_rows($db,'SELECT * FROM eb_store_order'),'id',$package['root_id']);
        m4cf_expect((int)$row['pid']===(int)$root['id']&&$row['order_id']===$package['child_no']&&$root['order_id']===$package['root_no']
            &&hash('sha256',$root['custom_form'])===$package['saved_form_sha256'],'OBSERVED_PACKAGE_REAL_STORED_RELATION');}
    $history=[];foreach($observations['historical']as$entry)m4cf_expect(in_array($entry['root_id'],$roots,true)
        &&is_int($entry['actual_confirmation_deadline_ms'])&&is_int($entry['observed_redis_time_ms'])
        &&$entry['observed_redis_time_ms']>$entry['actual_confirmation_deadline_ms']
        &&m5pc_hex($entry['private_map_sha256'])&&!isset($history[$entry['root_id']])&&($history[$entry['root_id']]=true),'ACTUAL_NATURAL_CONFIRMATION_DEADLINE_OBSERVATION');
    m4cf_expect(count($history)===2,'BOTH_SAVED_ROOTS_READ_AFTER_THEIR_REAL_CONFIRMATION_LIFECYCLE');
}

function m4cf_matrix_receipt(string $scope):array
{
    return ['schema'=>1,'scope'=>$scope,'uploads'=>4,'selected_distinct'=>3,'required_image_entries'=>8,'optional_empty'=>true,
        'multi_product'=>true,'complete34_independent_before'=>true,'three_bind_columns_only'=>true,'original_full_bytes'=>true];
}
function m4cf_paid_receipt(array $proof):array
{
    m4cf_expect(($proof['upload_child']['stopped']??null)===true&&$proof['complete34_verified']===true
        &&$proof['only_three_bound_columns']===true&&$proof['original_bytes_retained']===true,'COMPLETE_PAID_PRIVATE_FORM_PROOF');
    return m4cf_matrix_receipt('CU_FORM_NORMAL_PAID')+['upload_child'=>$proof['upload_child'],
        'first_create_official_kernel'=>true,'same_body_original_early_return'=>true];
}
function m4cf_paid_receipt_check(array $value):void
{
    $expected=m4cf_matrix_receipt('CU_FORM_NORMAL_PAID')+['upload_child'=>$value['upload_child']??null,
        'first_create_official_kernel'=>true,'same_body_original_early_return'=>true];m4c_entry_same($value,$expected);
    $child=$value['upload_child'];m4c_entry_keys($child,['stopped','group','remaining_members','listener_open','stop_signal',
        'term_attempted','native_child','native_proc_close','normal_only','streams']);
    m4cf_expect($child['stopped']===true&&$child['remaining_members']===[]&&$child['listener_open']===false
        &&$child['stop_signal']===SIGTERM&&$child['term_attempted']===true&&$child['normal_only']===true
        &&$child['native_child']['running']===false&&in_array($child['native_child']['termsig'],[0,SIGTERM],true)
        &&in_array($child['native_proc_close'],[-1,0],true),'DISTINCT_UPLOAD_CHILD_NORMAL_CLOSED_RECEIPT');
    m4c_entry_keys($child['group'],['leader_pid','pgid','sid','leader_starttime']);
    m4c_entry_keys($child['native_child'],['pid','running','signaled','stopped','exitcode','termsig','stopsig']);
    $leader=m5pcp_int($child['group']['leader_pid'],1);
    m4cf_expect($child['group']['pgid']===$leader&&$child['group']['sid']===$leader&&$child['native_child']['pid']===$leader
        &&m5pcp_u64($child['group']['leader_starttime'])!=='0'&&is_bool($child['native_child']['signaled'])
        &&$child['native_child']['stopped']===false&&$child['native_child']['stopsig']===0
        &&in_array($child['native_child']['exitcode'],[-1,0],true),'HISTORICAL_UPLOAD_CHILD_ACTUAL_REGISTERED_GROUP_AND_NATIVE_TERMINAL');
    m4c_entry_keys($child['streams'],['stdout','stderr']);
    foreach($child['streams']as$stream){m4c_entry_keys($stream,['path','bytes','sha256','stat']);
        m4cf_expect(is_string($stream['path'])&&preg_match('~\A/workspace/crmeb/runtime/customer-form-upload-[a-f0-9]{32}\.(stdout|stderr)\z~D',$stream['path'])===1
            &&is_int($stream['bytes'])&&$stream['bytes']>=0&&$stream['bytes']<=16777216&&m5pc_hex($stream['sha256'])
            &&$stream['stat']['size']===$stream['bytes']&&($stream['stat']['mode']&0777)===0600&&$stream['stat']['nlink']===1,
            'HISTORICAL_OWN_COMPLETE_UPLOAD_STREAM_DESCRIPTOR');}
}

/** The original baseline is JSON; native BINARY fields and original image bytes stay lossless in this private envelope. */
function m4cf_archive(array $value):array
{
    $raw=serialize($value);m4cf_expect(strlen($raw)>0&&strlen($raw)<=262144,'BOUNDED_PRIVATE_FORM_NATIVE_PROOF');
    $archive=['encoding'=>'php-serialize-base64-v1','bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'base64'=>base64_encode($raw)];
    m4cf_expect(m4cf_restore($archive)===$value,'PRIVATE_FORM_COMPLETE_NATIVE_PROOF_READBACK');return $archive;
}
function m4cf_restore(array $archive):array
{
    m4c_entry_keys($archive,['encoding','bytes','sha256','base64']);
    m4cf_expect($archive['encoding']==='php-serialize-base64-v1'&&is_int($archive['bytes'])&&$archive['bytes']>0
        &&$archive['bytes']<=262144&&m5pc_hex($archive['sha256'])&&is_string($archive['base64'])
        &&strlen($archive['base64'])<=349528,'PRIVATE_FORM_ARCHIVE_ENVELOPE');
    $raw=base64_decode($archive['base64'],true);
    m4cf_expect(is_string($raw)&&strlen($raw)===$archive['bytes']&&base64_encode($raw)===$archive['base64']
        &&hash('sha256',$raw)===$archive['sha256'],'PRIVATE_FORM_ARCHIVE_COMPLETE_BYTES');
    $value=unserialize($raw,['allowed_classes'=>false,'max_depth'=>32]);
    m4cf_expect(is_array($value)&&serialize($value)===$raw,'PRIVATE_FORM_ARCHIVE_NATIVE_TYPES');return $value;
}

/** Source component only: merge into the one ms4-customer-form-normal.php helper. */

function m4cfk_guard(array $source):array
{
    audit_guard_assert();
    $proof=$source['rule']['proof']??null;
    if($proof==='paid-producer')m5pc_full_source($source);
    elseif($proof==='aftersale-review-normal')m4ar_full_source($source);
    elseif($proof==='customer')m4c_entry_full_source($source);
    else m4c_expect(false,'M4C_FORM_KNOWN_ORIGINAL_CALLER_SOURCE');
    $out=[];
    foreach(['run_id','source_sha','container_id','freeze_sha256','source_binding_sha256','environment_sha256','native_created_sha256']as$key){
        m4c_expect(is_string($source[$key]??null)&&$source[$key]!=='','M4C_FORM_REAL_SOURCE_BINDING_VALUE');$out[$key]=$source[$key];
    }
    $out['proof']=$proof;return $out;
}

function m4cfk_source():array
{
    foreach(['m4ar_source'=>'aftersale-review-normal','m5pc_source'=>'paid-producer','m4c_entry_source'=>'customer']as$name=>$proof){
        $source=$GLOBALS[$name]??null;
        if(is_array($source)&&($source['rule']['proof']??null)===$proof){m4cfk_guard($source);return $source;}
    }
    m4c_expect(false,'M4C_FORM_ACTUAL_CALLER_SOURCE_NOT_AVAILABLE');return [];
}

function m4cfk_token(string $token):void
{
    m4c_expect(strlen($token)>=16&&strlen($token)<=8192
        &&preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D',$token)===1,
        'M4C_FORM_REAL_LOGIN_TOKEN_WIRE_ONLY');
}

function m4cfk_decimal(string $id,string $maximum):void
{
    m4c_expect(preg_match('/\A[1-9][0-9]*\z/D',$id)===1&&strlen($id)<=strlen($maximum)
        &&(strlen($id)<strlen($maximum)||strcmp($id,$maximum)<=0),'M4C_FORM_CANONICAL_ROUTE_DECIMAL');
}

function m4cfk_key(string $segment):string
{
    $key=rawurldecode($segment);
    m4c_expect(rawurlencode($key)===$segment
        &&\app\services\marketplace\order\MarketplaceOrderInput::confirmationKey($key)===$key,
        'M4C_FORM_ACTUAL_CANONICAL_CONFIRMATION_LOCATOR');return $key;
}

/** Only existing login/user, CU_FORM leaves, and their original read-only root/package locators. */
function m4cfk_route(string $method,string $path):string
{
    m4c_expect(in_array($method,['GET','POST'],true)&&strlen($path)<=1024&&str_starts_with($path,'/')
        &&!str_contains($path,'?')&&!str_contains($path,'#')&&!str_contains($path,'\\')
        &&!str_contains($path,'..')&&!preg_match('/[\x00-\x20\x7f]/',$path),'M4C_FORM_FINITE_NORMAL_ROUTE_WIRE');
    if($method==='POST'&&preg_match('~\A/api/order/computed/([^/]+)\z~D',$path,$m)===1){m4cfk_key($m[1]);return 'computed';}
    if($method==='POST'&&$path==='/api/login')return 'login';
    if($method==='GET'&&$path==='/api/user')return 'user';
    if(preg_match('~\A/api/order/confirm/([^/]+)/forms/([1-9][0-9]{0,6})/([0-9])/files\z~D',$path,$m)===1){
        m4cfk_key($m[1]);m4cfk_decimal($m[2],'8388607');m4c_expect($method==='POST','M4C_FORM_UPLOAD_POST');return 'upload';
    }
    if(preg_match('~\A/api/order/confirm/([^/]+)/form-files/([1-9][0-9]{0,19})/(read-ticket|content)\z~D',$path,$m)===1){
        m4cfk_key($m[1]);m4cfk_decimal($m[2],'18446744073709551615');
        m4c_expect($method===($m[3]==='content'?'GET':'POST'),'M4C_FORM_CONFIRMATION_READ_METHOD');return $m[3]==='content'?'content':'ticket';
    }
    if(preg_match('~\A/(api/order|merchantapi/orders|adminapi/order/info|api/admin/order/detail)/([1-9][0-9]{0,9})/form-files/([1-9][0-9]{0,19})/(read-ticket|content)\z~D',$path,$m)===1){
        m4cfk_decimal($m[2],'2147483647');m4cfk_decimal($m[3],'18446744073709551615');
        m4c_expect($method===($m[4]==='content'?'GET':'POST'),'M4C_FORM_SAVED_READ_METHOD');return $m[4]==='content'?'content':'ticket';
    }
    if($method==='GET'&&preg_match('~\A/api/(?:order/detail|admin/order/detail|order/fulfillment/packages)/[A-Za-z0-9]{1,64}\z~D',$path)===1)return 'view';
    if($method==='GET'&&preg_match('~\A/(?:merchantapi/(?:orders|packages)|adminapi/order/info)/([1-9][0-9]{0,9})\z~D',$path,$m)===1){
        m4cfk_decimal($m[1],'2147483647');return 'view';
    }
    m4c_expect(false,'M4C_FORM_UNREGISTERED_NORMAL_ROUTE');return '';
}

/** A response is preserved even at 503; the independent caller retains the original exact-200 gate. */
function m4cf_transport(string $method,string $path,?string $token=null,mixed $body=null,
    ?string $multipart=null,?string $ticket=null):array
{
    $source=m4cfk_source();$binding=m4cfk_guard($source);$kind=m4cfk_route($method,$path);
    if($kind==='login'){
        m4c_expect($token===null&&$ticket===null&&$multipart===null&&is_array($body),'M4C_FORM_REAL_LOGIN_ENVELOPE');
        $keys=array_keys($body);sort($keys,SORT_STRING);
        m4c_expect($keys===['account','agent_id','password','spread']&&is_string($body['account'])&&$body['account']!==''
            &&is_string($body['password'])&&$body['password']!==''&&$body['agent_id']===0&&$body['spread']===0,'M4C_FORM_ORIGINAL_FIXTURE_LOGIN_BODY');
    }else{m4c_expect(is_string($token),'M4C_FORM_ACTUAL_AUTHENTICATED_CALL');m4cfk_token($token);}
    if($kind==='computed')m4c_expect(is_array($body)&&array_keys($body)===['addressId','shipping_type','payType','mer_id','couponId','useIntegral']
        &&is_int($body['addressId'])&&$body['addressId']>0&&is_int($body['mer_id'])&&$body['mer_id']>0
        &&$body['shipping_type']===1&&$body['payType']===''&&$body['couponId']===0&&$body['useIntegral']===0&&$multipart===null,'M4C_FORM_ORIGINAL_NORMAL_REPRICE_BODY');
    if($kind==='content')m4c_expect(is_string($ticket)&&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket)===1,'M4C_FORM_NATIVE_ISSUED_TICKET_HEADER');
    else m4c_expect($ticket===null,'M4C_FORM_NO_TICKET_ON_OTHER_ROUTE');
    if($method==='GET')m4c_expect($body===null&&$multipart===null,'M4C_FORM_GET_EMPTY_BODY');
    if($kind==='ticket')m4c_expect($body===['schema'=>1]&&$multipart===null,'M4C_FORM_THREE_KEY_TICKET_REQUEST_SCHEMA');
    $headers=['Cb-lang: zh-CN','Accept: '.($kind==='content'?'*/*':'application/json')];
    if($token!==null)$headers[]=(str_starts_with($path,'/merchantapi/')?'Authorization':'Authori-zation').': Bearer '.$token;
    if($ticket!==null)$headers[]='X-Order-Form-Read-Ticket: '.$ticket;
    if($multipart!==null){
        m4c_expect($kind==='upload'&&is_string($body)&&strlen($body)<=52432896
            &&preg_match('/\AAuditCustomerForm[a-f0-9]{32}\z/D',$multipart)===1,'M4C_FORM_FINITE_REAL_MULTIPART_BODY');
        $headers[]='Content-Type: multipart/form-data; boundary='.$multipart;
    }elseif($body!==null){
        m4c_expect(is_array($body)&&$kind!=='upload','M4C_FORM_FIXED_JSON_BODY');$body=m4cb_json($body);
        m4c_expect(strlen($body)<=1048576,'M4C_FORM_JSON_REQUEST_LIMIT');$headers[]='Content-Type: application/json';
    }else m4c_expect($kind!=='upload','M4C_FORM_UPLOAD_REQUIRES_REAL_MULTIPART');
    $requestBytes=$body===null?0:strlen($body);$requestHash=hash('sha256',$body??'');
    $limit=$kind==='content'?52428800:1048576;$raw='';$headerBytes=0;$headerLines=[];$responseHeaders=[];$overflow=false;
    $uploadDiagnostic=null;
    if($kind==='upload'){
        $context=$GLOBALS['m4cf_upload_diagnostic_context']??null;
        if(is_array($context)&&is_int($context['upload_ordinal']??null)&&$context['upload_ordinal']>=1&&$context['upload_ordinal']<=4
            &&is_int($context['deadline_ms']??null)&&$context['deadline_ms']>0)
            $uploadDiagnostic=['schema'=>1,'scope'=>'NORMAL_FORM_UPLOAD_PREFLIGHT','upload_ordinal'=>$context['upload_ordinal'],
                'deadline_at_last_read'=>'BEFORE','deadline_before_http'=>m4cf_upload_deadline_relation($context['deadline_ms'])];
    }
    $curl=curl_init('http://audit-app:8000'.$path);m4c_expect($curl!==false,'M4C_FORM_REAL_CURL_HANDLE');$started=time();
    try{
        m4c_expect(curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>static function($handle,string $part)use(&$raw,&$overflow,$limit):int{
                if(strlen($part)>$limit-strlen($raw)){$overflow=true;return 0;}$raw.=$part;return strlen($part);
            },CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headerBytes,&$headerLines,&$responseHeaders,&$overflow):int{
                $headerBytes+=strlen($line);if($headerBytes>65536||count($headerLines)>=512){$overflow=true;return 0;}
                $headerLines[]=$line;$pair=explode(':',$line,2);
                if(count($pair)===2)$responseHeaders[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);
            }]),'M4C_FORM_REAL_CURL_OPTIONS');
        if($body!==null)m4c_expect(curl_setopt($curl,CURLOPT_POSTFIELDS,$body),'M4C_FORM_REAL_REQUEST_BODY');
        $done=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);
    }finally{curl_close($curl);$ended=time();unset($headers,$token,$ticket,$body);}
    $json=null;$jsonError=null;
    if($kind!=='content'&&strlen($raw)<=1048576){$json=json_decode($raw,true,64,JSON_BIGINT_AS_STRING);$jsonError=json_last_error();}
    $reply=['http'=>$http,'raw'=>$raw,'json'=>$json,'headers'=>$responseHeaders,'header_lines'=>$headerLines,
        'actual_interval'=>[$started,$ended],'curl_errno'=>$errno,'json_error'=>$jsonError,
        'request_bytes'=>$requestBytes,'request_sha256'=>$requestHash];
    $reply['normal_http_diagnostic']=['kind'=>$kind,'method'=>$method,'http'=>$http,'json_error'=>$jsonError,
        'response_bytes'=>strlen($raw),'response_sha256'=>hash('sha256',$raw)];
    if($kind==='upload'&&$http!==200&&!isset($GLOBALS['m4cf_failed_upload_context']))$GLOBALS['m4cf_failed_upload_context']=$uploadDiagnostic;
    $complete=$done!==false&&$errno===0&&!$overflow&&$http>=100&&$http<=599;
    if(!$complete&&!isset($GLOBALS['m4cf_normal_http_first_failure']))
        $GLOBALS['m4cf_normal_http_first_failure']=$reply['normal_http_diagnostic'];
    if(!$complete&&!isset($GLOBALS['m4cf_transport_first_failure']))$GLOBALS['m4cf_transport_first_failure']=[
        'kind'=>$kind,'method'=>$method,'http'=>$http,'curl_errno'=>$errno,'overflow'=>$overflow,
        'response_bytes'=>strlen($raw),'response_sha256'=>hash('sha256',$raw),'actual_interval'=>[$started,$ended]];
    m4c_expect(m4cfk_guard($source)===$binding,'M4C_FORM_TRANSPORT_ORIGINAL_SOURCE_BINDINGS_UNCHANGED');
    m4c_expect($complete,'M4C_FORM_COMPLETE_NATIVE_HTTP_RESPONSE_NO_RETRY');return $reply;
}


/** One diagnostic TIME only; errors cannot alter a business assertion, key or deadline. */
function m4cf_upload_deadline_relation(int $deadline):string
{
    $installed=false;$redis=null;
    try{
        set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{return true;});$installed=true;
        $redis=audit_redis();$time=$redis->rawCommand('TIME');
        if(!is_array($time)||array_keys($time)!==[0,1]||!is_string($time[0])||!is_string($time[1])
            ||preg_match('/\A[1-9][0-9]{0,18}\z/D',$time[0])!==1||preg_match('/\A[0-9]{1,6}\z/D',$time[1])!==1
            ||bccomp($time[1],'999999',0)>0)return 'UNAVAILABLE';
        $now=bcadd(bcmul($time[0],'1000',0),bcdiv($time[1],'1000',0),0);
        return bccomp((string)$deadline,$now,0)>0?'BEFORE':'AT_OR_AFTER';
    }catch(Throwable $diagnosticError){return 'UNAVAILABLE';}
    finally{if($redis instanceof Redis){try{$redis->close();}catch(Throwable $ignored){}}if($installed)restore_error_handler();}
}
/** Called only inside the existing full closed stderr read; raw bytes never leave that path. */
function m4cf_observe_preflight_record(string $bytes):void
{
    $GLOBALS['m4cf_preflight_location']=null;
    try{
        $prefix='MALL_ORDER_FORM_PREFLIGHT ';$records=[];
        foreach(explode("\n",$bytes)as$line)if(str_starts_with($line,$prefix))$records[]=substr($line,strlen($prefix));
        if(count($records)!==1||strlen($records[0])>1024)return;
        $value=json_decode($records[0],true,8,JSON_THROW_ON_ERROR);
        if(!is_array($value)||array_keys($value)!==['schema','scope','substage','class','file','line']
            ||$value['schema']!==1||$value['scope']!=='MALL_ORDER_FORM_PREFLIGHT'
            ||!is_string($value['substage'])||!in_array($value['substage'],['request_identity','request_envelope','request_file','primary_pdo','transaction_start','transaction_entry','owner_before_lock','lock_user','lookup_confirmation','read_confirmation','lock_parents','cached_frame','locked_frame','field_authorization','owner_confirmation_before_quota','quota_read','uploaded_tempfile','storage_construct','image_qualify','owner_confirmation_before_write','private_write_preflight'],true)
            ||json_encode($value,JSON_UNESCAPED_SLASHES)!==$records[0])return;
        if($value['class']!==null&&(!is_string($value['class'])||strlen($value['class'])>192
            ||preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,191}\z/D',str_replace('\\','_',$value['class']))!==1))return;
        if($value['file']!==null&&(!is_string($value['file'])||strlen($value['file'])>96
            ||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\z/D',$value['file'])!==1))return;
        if($value['line']!==null&&(!is_int($value['line'])||$value['line']<1||$value['line']>10000000))return;
        $GLOBALS['m4cf_preflight_location']=$value;
    }catch(Throwable $diagnosticError){}
}
function m4cf_public_preflight_failure():?array
{
    $context=$GLOBALS['m4cf_failed_upload_context']??null;
    if(!is_array($context)||array_keys($context)!==['schema','scope','upload_ordinal','deadline_at_last_read','deadline_before_http'])return null;
    return $context+['location'=>$GLOBALS['m4cf_preflight_location']??null];
}

function m4cf_multipart(string $path,string $token,string $bytes,string $name='normal-form.png',string $mime='image/png'):array
{
    m4c_expect(m4cfk_route('POST',$path)==='upload'&&strlen($bytes)>0&&strlen($bytes)<=52428800
        &&strlen($name)>0&&strlen($name)<=255&&!preg_match('/[\x00-\x1f\x7f"]/',$name)
        &&!str_contains($name,'\\')&&!str_contains($name,'/')
        &&preg_match('~\Aimage/[a-z0-9.+-]{1,64}\z~D',$mime)===1,'M4C_FORM_REAL_NORMAL_IMAGE_MULTIPART');
    m4c_expect((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)===$mime,'M4C_FORM_ACTUAL_IMAGE_MIME_BEFORE_TRANSPORT');
    $boundary='AuditCustomerForm'.bin2hex(random_bytes(16));
    $raw='--'.$boundary."\r\nContent-Disposition: form-data; name=\"filename\"\r\n\r\npics\r\n";
    $raw.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"pics\"; filename=\"".$name."\"\r\nContent-Type: ".$mime."\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
    return m4cf_transport('POST',$path,$token,$raw,$boundary);
}

/** Independent paid upload child; AR deliberately keeps its already-existing child and real customer token. */
function m4cf_child_start(array $source):array
{
    $binding=m4cfk_guard($source);
    m4c_expect(($source['rule']['proof']??null)==='paid-producer'&&!isset($GLOBALS['m4cf_child_started'])
        &&!audit_app_listener_open(),'M4C_FORM_ONE_NEW_PAID_CHILD_AFTER_ORIGINAL_OWNER_CHILD_CLOSED');
    $GLOBALS['m4cf_child_started']=true;$supervisor=audit_app_proc_stat(getmypid());
    m4c_expect($supervisor!==null,'M4C_FORM_ACTUAL_UPLOAD_SUPERVISOR');
    $stem='/workspace/crmeb/runtime/customer-form-upload-'.bin2hex(random_bytes(16));
    $handle=['schema'=>1,'source'=>$source,'binding'=>$binding,'supervisor'=>$supervisor,'process'=>null,'group'=>null,
        'term_sent'=>false,'term_attempted'=>false,'stdout'=>$stem.'.stdout','stderr'=>$stem.'.stderr','proof'=>null];
    $GLOBALS['m4cf_child_handle']=$handle;
    register_shutdown_function(static function():void{
        $active=$GLOBALS['m4cf_child_handle']??null;if(!is_array($active)||($active['proof']['stopped']??false))return;
        if(is_resource($active['process'])||$active['group']!==null){try{m4cf_child_close($active);}
            catch(Throwable $error){$GLOBALS['m4cf_child_cleanup_failure']=['class'=>get_class($error),'phase'=>'shutdown-normal-close'];}}
    });
    try{
        $mask=umask(0077);
        try{$handle['process']=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],
            [['file','/dev/null','r'],['file',$handle['stdout'],'x'],['file',$handle['stderr'],'x']],$pipes,
            '/workspace/crmeb',m4c_entry_child_environment($source));}finally{umask($mask);}
        $GLOBALS['m4cf_child_handle']=$handle;m4c_expect(is_resource($handle['process']),'M4C_FORM_ACTUAL_PRIVATE_UPLOAD_HTTP_CHILD');
        $handle['group']=audit_app_register_group($handle['process'],$supervisor);$GLOBALS['m4cf_child_handle']=$handle;
        $until=microtime(true)+5.0;
        do{$observation=audit_app_process_observation($handle['group'],$supervisor);
            if($observation['members']!==[]&&$observation['listener_open'])break;usleep(10000);
        }while(microtime(true)<$until);
        m4c_expect($observation['members']!==[]&&$observation['listener_open'],'M4C_FORM_REGISTERED_REAL_UPLOAD_LISTENER');
        m4c_expect(m4cfk_guard($source)===$binding,'M4C_FORM_UPLOAD_CHILD_SOURCE_BINDINGS_UNCHANGED');return $handle;
    }catch(\Throwable $error){
        $GLOBALS['m4cf_child_handle']=$handle;
        $GLOBALS['m4cf_child_first_failure']=['class'=>get_class($error),'phase'=>'start'];
        if(is_resource($handle['process'])||$handle['group']!==null){try{m4cf_child_close($handle);}catch(\Throwable $secondary){
            $GLOBALS['m4cf_child_cleanup_failure']=['class'=>get_class($secondary),'phase'=>'normal-close'];}}
        throw $error;
    }
}

function m4cfk_stream(string $path):array
{
    clearstatcache(true,$path);$named=lstat($path);
    m4c_expect(is_array($named)&&!is_link($path)&&is_file($path)&&realpath($path)===$path
        &&($named['mode']&0170000)===0100000&&($named['mode']&0777)===0600&&$named['uid']===posix_geteuid()
        &&$named['nlink']===1&&$named['size']>=0&&$named['size']<=16777216,'M4C_FORM_OWN_CLOSED_NATIVE_STREAM');
    $stream=fopen($path,'rb');m4c_expect(is_resource($stream),'M4C_FORM_COMPLETE_NATIVE_STREAM_OPEN');
    try{$opened=fstat($stream);$bytes=stream_get_contents($stream);
        m4c_expect(is_array($opened)&&is_string($bytes)&&fread($stream,1)===''&&feof($stream)
            &&strlen($bytes)===$named['size'],'M4C_FORM_COMPLETE_NATIVE_STREAM_EOF');$final=fstat($stream);
    }finally{fclose($stream);}
    clearstatcache(true,$path);$atPath=lstat($path);
    foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
        m4c_expect(is_array($final)&&is_array($atPath)&&$named[$key]===$opened[$key]&&$named[$key]===$final[$key]
            &&$named[$key]===$atPath[$key],'M4C_FORM_STABLE_CLOSED_NATIVE_STREAM');
    if(str_ends_with($path,'.stderr'))m4cf_observe_preflight_record($bytes);
    return ['path'=>$path,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'stat'=>$atPath];
}

/** Normal observations may continue; original m4c_entry_stop sends at most one registered-group TERM. */
function m4cf_child_close(array &$handle):array
{
    m4c_expect(($handle['schema']??null)===1&&is_array($handle['source']??null)
        &&is_array($handle['supervisor']??null),'M4C_FORM_REAL_OWN_CHILD_HANDLE');
    if(is_array($handle['proof']??null)&&$handle['proof']['stopped']===true)return $handle['proof'];
    $failure=null;$proof=null;
    do{
        try{$proof=m4c_entry_stop($handle['process'],$handle['group'],$handle['supervisor'],$handle['term_sent'],$handle['term_attempted']);}
        catch(\Throwable $error){if($failure===null)$failure=$error;usleep(20000);}
        $GLOBALS['m4cf_child_handle']=$handle;
    }while($proof===null||!$proof['stopped']);
    $proof['streams']=['stdout'=>m4cfk_stream($handle['stdout']),'stderr'=>m4cfk_stream($handle['stderr'])];
    m4c_expect($proof['stopped']===true&&$proof['remaining_members']===[]&&$proof['listener_open']===false
        &&$proof['stop_signal']===SIGTERM&&$proof['term_attempted']===true&&$proof['normal_only']===true
        &&is_array($proof['native_child'])&&$proof['native_child']['running']===false&&$proof['native_child']['termsig']!==9
        &&is_int($proof['native_proc_close']),'M4C_FORM_SAME_UPLOAD_CHILD_NORMALLY_CLOSED');
    $handle['proof']=$proof;$GLOBALS['m4cf_child_handle']=$handle;$GLOBALS['m4cf_child_proof']=$proof;
    m4c_expect(m4cfk_guard($handle['source'])===$handle['binding'],'M4C_FORM_CLOSED_CHILD_ORIGINAL_BINDINGS_UNCHANGED');
    if($failure!==null)throw $failure;return $proof;
}

function m4cfk_session(\PDO $pdo):array
{
    $statement=$pdo->query('SELECT CONNECTION_ID() AS connection_id, @@SESSION.autocommit AS autocommit,'
        .' @@SESSION.transaction_isolation AS transaction_isolation, @@SESSION.sql_mode AS sql_mode,'
        .' @@SESSION.time_zone AS time_zone, @@SESSION.innodb_strict_mode AS innodb_strict_mode');
    m4c_expect($statement instanceof \PDOStatement,'M4C_FORM_ACTUAL_DEFAULT_SESSION_QUERY');
    try{$rows=$statement->fetchAll(\PDO::FETCH_ASSOC);}finally{$statement->closeCursor();}
    m4c_expect(count($rows)===1&&array_keys($rows[0])===['connection_id','autocommit','transaction_isolation','sql_mode','time_zone','innodb_strict_mode']
        &&in_array($rows[0]['autocommit'],[1,'1'],true),'M4C_FORM_COMPLETE_OUTSIDE_DEFAULT_SESSION');return $rows[0];
}

/** Exactly one first-create kernel dispatch per original producer App; never a retained authenticated Request. */
function m4cf_kernel_create(\think\App $app,\PDO $db,array $source,int $uid,string $key,array $raw,string $token,
    ?array &$observation=null):array
{
    $binding=m4cfk_guard($source);m4cfk_token($token);
    m4c_expect(app()===$app&&($GLOBALS['m4c_application']??null)===$app&&$app->initialized()
        &&in_array($source['rule']['proof']??null,['paid-producer','aftersale-review-normal'],true)
        &&$uid>0&&$uid<=2147483647,'M4C_FORM_ORIGINAL_INITIALIZED_PRODUCER_APP');
    m4c_expect(\app\services\marketplace\order\MarketplaceOrderInput::confirmationKey($key)===$key,'M4C_FORM_ORIGINAL_CREATE_KEY');
    $object=spl_object_id($app);m4c_expect(!isset($GLOBALS['m4cf_kernel_dispatched'][$object]),'M4C_FORM_ONE_FIRST_CREATE_DISPATCH_PER_APP');
    $wire=m4cb_json($raw);m4c_expect(strlen($wire)>0&&strlen($wire)<=1048576,'M4C_FORM_REAL_CREATE_JSON_LIMIT');
    $connection=\think\facade\Db::connect();$pdo=$connection->getPdo();m4c_expect($pdo instanceof \PDO,'M4C_FORM_REAL_DEFAULT_PDO');
    m5pcp_context($app,$connection,$pdo,$db);$session=m4cfk_session($pdo);$configuration=[];
    foreach(['database','cache','marketplace','auth','upload','filesystem']as$name)$configuration[$name]=$app->config->get($name);
    $http=$app->http;m4c_expect($http->getName()===''&&$http->getPath()===''&&!$http->isBind()
        &&!$app->exists(\think\app\MultiApp::class),'M4C_FORM_FIRST_OFFICIAL_MULTIPLE_APP_DISPATCH');
    $previous=$app->request;$serverBefore=$_SERVER;$path='/api/order/create/'.rawurlencode($key);
    $server=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>$path,'PATH_INFO'=>$path,'QUERY_STRING'=>'',
        'SCRIPT_FILENAME'=>'/workspace/crmeb/public/index.php','SCRIPT_NAME'=>'/index.php','PHP_SELF'=>'/index.php',
        'SERVER_NAME'=>'audit-app','HTTP_HOST'=>'audit-app:8000','SERVER_PORT'=>'8000','REQUEST_SCHEME'=>'http',
        'SERVER_PROTOCOL'=>'HTTP/1.1','CONTENT_TYPE'=>'application/json','CONTENT_LENGTH'=>(string)strlen($wire),
        'REMOTE_ADDR'=>gethostbyname(gethostname())];
    m4c_expect(is_file($server['SCRIPT_FILENAME'])&&!is_link($server['SCRIPT_FILENAME'])
        &&filter_var($server['REMOTE_ADDR'],FILTER_VALIDATE_IP)!==false,'M4C_FORM_ACTUAL_PUBLIC_ENTRY_AND_CONTAINER_ADDRESS');
    // MultiApp reads global SCRIPT_FILENAME; the fixed real public entry selects URL parsing, never an identity stamp.
    $request=null;$started=time();$response=null;$failure=null;$data=null;
    try{
        $_SERVER=array_replace($_SERVER,$server);$request=$app->make('request',[],true);
        m4c_expect($request instanceof \app\Request&&$request!==$previous&&in_array($request->filter(),[null,'',[]],true),
            'M4C_FORM_FRESH_OFFICIAL_REQUEST_WITH_ORIGINAL_FILTER');
        $request->withServer($server)->withEnv($app->env)->withHeader(['host'=>'audit-app:8000','content-type'=>'application/json',
            'content-length'=>(string)strlen($wire),'accept'=>'application/json','cb-lang'=>'zh-CN','authori-zation'=>'Bearer '.$token])
            ->withGet([])->withCookie([])->withFiles([])->withInput($wire);
        m4c_expect($request->method(true)==='POST'&&$request->getContent()===$wire&&$request->post(false)===$raw
            &&$request->get(false)===[],'M4C_FORM_ACTUAL_POST_SERVER_AND_COMPLETE_RAW_BODY');
        $GLOBALS['m4cf_kernel_dispatched'][$object]=true;$started=time();
        $response=$http->run($request);$content=$response->getContent();$ended=time();
        $json=json_decode($content,true,64,JSON_BIGINT_AS_STRING);$error=json_last_error();
        $observation=['http'=>$response->getCode(),'raw'=>$content,'json'=>$json,'headers'=>$response->getHeader(),
            'actual_interval'=>[$started,$ended],'start'=>$started,'end'=>$ended,'json_error'=>$error];
        m4c_expect($app->request===$request&&$http->getName()==='api'&&$request->method(true)==='POST'
            &&$request->action(true)==='create'&&$request->route('key',null,null)===$key
            &&in_array($request->uid(),[$uid,(string)$uid],true),'M4C_FORM_REAL_ROUTER_AND_AUTHENTICATED_CONTROLLER');
        m4c_expect(strlen($content)<=1048576&&$response->getCode()===200&&$error===JSON_ERROR_NONE&&is_array($json),
            'M4C_FORM_REAL_CREATE_RESPONSE_200');
        m4c_entry_keys($json,['status','msg','data']);m4c_expect($json['status']===200&&is_string($json['msg'])&&is_array($json['data']),
            'M4C_FORM_REAL_CREATE_JSON_ENVELOPE');
        $number=m4cb_wire_created($json['data'],$key);$data=['order_id'=>$number,'key'=>$json['data']['result']['key']];
    }catch(\Throwable $error){$failure=$error;}
    finally{
        if($response!==null){try{$http->end($response);}catch(\Throwable $error){if($failure===null)$failure=$error;}}
        $_SERVER=$serverBefore;$app->instance('request',$previous);
        if($request instanceof \app\Request)$request->withHeader([]);unset($token);
        try{
            m5pcp_context($app,$connection,$pdo,$db);m4c_expect(m4cfk_session($pdo)===$session,'M4C_FORM_SAME_COMPLETE_DEFAULT_SESSION');
            foreach($configuration as$name=>$expected)m4c_expect($app->config->get($name)===$expected,'M4C_FORM_ORIGINAL_CONFIGURATION_UNCHANGED');
            m4c_expect(m4cfk_guard($source)===$binding,'M4C_FORM_KERNEL_ORIGINAL_SOURCE_BINDINGS_UNCHANGED');
        }catch(\Throwable $error){if($failure===null)$failure=$error;}
    }
    if($failure!==null)throw $failure;
    m4c_expect(is_array($data),'M4C_FORM_COMPLETE_REAL_CREATE_RESULT');return $data;
}
