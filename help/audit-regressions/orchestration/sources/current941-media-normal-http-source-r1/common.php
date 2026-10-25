<?php
declare(strict_types=1);
require_once '/media-environment/lib/harness.php';
const M86_SOURCE='941fdc7fe81a5d5b44dfce29cf5f87c8411c0a58';
const M86_NATIVE14=['att_id','name','att_dir','satt_dir','att_size','att_type','pid','time','image_type','module_type','real_name','scan_token','type','mer_id'];
const M86_AUDIO=['audio/mpeg','audio/mp3','audio/x-mp3','audio/wav','audio/wave','audio/x-wav','audio/vnd.wave','audio/amr','audio/amr-wb','audio/x-ms-wma','audio/x-ms-asf','video/x-ms-asf','application/vnd.ms-asf'];
$GLOBALS['m86_phase']='entry';$GLOBALS['m86_requests']=[];
function m86_expect(bool $ok,string $code): void {if(!$ok)throw new RuntimeException('M86_'.$code);}
function m86_phase(string $phase): void {$GLOBALS['m86_phase']=$phase;}
function m86_json(mixed $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function m86_pin(string $bytes): array {return ['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];}
function m86_read_json(string $path): array {$raw=file_get_contents($path);m86_expect(is_string($raw),'JSON_READ');$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);m86_expect(is_array($v),'JSON_OBJECT');return $v;}
function m86_start(): void {
    m86_expect(PHP_SAPI==='cli'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','FIXED_SOURCE_RUN_PATHS');
    $manifest=audit_manifest();m86_expect(($manifest['commit_sha']??'')===M86_SOURCE&&getenv('AUDIT_SOURCE_SHA')===M86_SOURCE&&($manifest['guard_mode']??'')==='read-only','REAL_BFE_READONLY_GUARD');
    audit_guard_assert();require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
}
function m86_db(): PDO {$p=audit_db();m86_expect($p->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)&&$p->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false),'NATIVE_OBSERVER_CONFIGURATION');m86_expect(!$p->inTransaction()&&(string)$p->query('SELECT @@session.autocommit')->fetchColumn()==='1','INDEPENDENT_OBSERVER_AUTOCOMMIT');return $p;}
function m86_q(PDO $p,string $sql,array $args=[]): array {$s=$p->prepare($sql);m86_expect($s instanceof PDOStatement&&$s->execute($args),'SQL_OBSERVE');try{return $s->fetchAll(PDO::FETCH_ASSOC);}finally{m86_expect($s->closeCursor(),'SQL_CURSOR_CLOSED');}}
function m86_identifier(string $s): string {m86_expect(preg_match('/\A[a-z][a-z0-9_]*\z/D',$s)===1,'FIXED_SQL_IDENTIFIER');return '`'.$s.'`';}
function m86_table(PDO $p,string $table,string $order): array {return m86_q($p,'SELECT * FROM '.m86_identifier($table).' ORDER BY '.m86_identifier($order));}
function m86_preserved(array $before,array $after,string $pk,array $allowed=[]): array {
    $a=[];foreach($after as$r){$key=(string)$r[$pk];m86_expect(!isset($a[$key]),'UNIQUE_OBSERVED_PK');$a[$key]=$r;}
    $old=[];foreach($before as$r){$key=(string)$r[$pk];$old[$key]=true;m86_expect(isset($a[$key]),'OLD_ROW_NOT_REMOVED');if(!in_array($key,array_map('strval',$allowed),true))m86_expect($a[$key]===$r,'OLD_COMPLETE_ROW_UNCHANGED');}
    return array_values(array_filter($after,static fn(array$r):bool=>!isset($old[(string)$r[$pk]])));
}
function m86_safe_error(Throwable $e): array {$message=$e->getMessage();return ['phase'=>$GLOBALS['m86_phase'],'code'=>preg_match('/\AM86_[A-Z0-9_]+\z/D',$message)===1?$message:'APPLICATION_OR_TRANSPORT_FAILURE','class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine(),'message_and_raw_body_omitted'=>true];}
function m86_actor_inputs(): array {$v=m86_read_json('/run/audit-fixtures/media-actors.json');m86_expect(($v['schema']??null)===1&&($v['run_id']??'')===getenv('AUDIT_RUN_ID')&&($v['source_sha']??'')===M86_SOURCE,'PRIVATE_ACTOR_RUN_SOURCE');return $v;}
function m86_large_fixture(): array {
    $v=m86_read_json('/media-checks/LARGE-VIDEO.json');$r=$v['file']??[];
    m86_expect(($v['schema']??null)===1&&($r['name']??null)==='synthetic-testsrc2-720p30-16s.mp4'&&($r['kind']??null)==='video'
        &&($r['container_path']??null)==='/media-large-fixture/synthetic-testsrc2-720p30-16s.mp4'&&($r['bytes']??null)===8425717
        &&($r['sha256']??null)==='cbd202099bd7d410602bdc92a23f948a83a17f42f6465c45ee1e2ced89da8f55'
        &&($v['chunk_bytes']??null)===3145728&&($v['total_chunks']??null)===3
        &&array_column($v['chunks']??[],'number')===[1,2,3]&&array_column($v['chunks']??[],'offset')===[0,3145728,6291456]
        &&array_column($v['chunks']??[],'bytes')===[3145728,3145728,2134261],'REGISTERED_REAL_LARGE_MP4_DESCRIPTOR');
    $path=$r['container_path'];clearstatcache(true,$path);
    m86_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$r['bytes']
        &&hash_file('sha256',$path)===$r['sha256'],'EXACT_REAL_LARGE_MP4_BYTES');$r['path']=$path;$r['chunks']=$v['chunks'];return $r;
}
function m86_fixture(string $name): array {
    if($name==='synthetic-testsrc2-720p30-16s.mp4')return m86_large_fixture();
    $manifest=m86_read_json('/media-checks/FIXTURES.json');$matches=array_values(array_filter($manifest['files'],static fn(array$r):bool=>$r['name']===$name));m86_expect(count($matches)===1&&basename($name)===$name,'REGISTERED_NORMAL_FIXTURE');$r=$matches[0];$path='/media-fixtures/'.$name;
    m86_expect(is_file($path)&&!is_link($path)&&filesize($path)===$r['bytes']&&hash_file('sha256',$path)===$r['sha256'],'EXACT_UNCHANGED_FIXTURE_BYTES');$r['path']=$path;return $r;
}
function m86_url(string $path): string {
    $manifest=audit_manifest();m86_expect(is_string($manifest['services']['audit-app']['address']??null)&&is_string($manifest['services']['audit-app']['id']??null)&&gethostbyname('audit-app')===$manifest['services']['audit-app']['address'],'REGISTERED_ACTUAL_APPLICATION_ENDPOINT');
    $url=str_starts_with($path,'/')?'http://audit-app:8000'.$path:$path;$p=parse_url($url);
    m86_expect(is_array($p)&&($p['scheme']??'')==='http'&&($p['host']??'')==='audit-app'&&($p['port']??0)===8000&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment']),'REAL_INTERNAL_URL_ONLY');return $url;
}
function m86_admin_visit_rule(string $method,string $path): string {
    // SystemLogServices stores Request.rule.getRule(), whose placeholders come from the pinned route Source.
    $plain=strtok($path,'?');
    if(in_array($method,['GET','POST'],true)&&preg_match('~\A/adminapi/product/product/(?:0|[1-9][0-9]*)\z~D',$plain)===1)return 'product/product/<id>';
    if($method==='GET'&&preg_match('~\A/adminapi/product/product/[1-9][0-9]*/media\z~D',$plain)===1)return 'product/product/<product_id>/media';
    if($method==='POST'&&preg_match('~\A/adminapi/product/product/(?:0|[1-9][0-9]*)/media(?<suffix>/videos/upload|/audio/upload|/categories|)\z~D',$plain,$match)===1)return 'product/product/<product_id>/media'.$match['suffix'];
    throw new RuntimeException('M86_NO_EXACT_REGISTERED_ADMIN_VISIT_RULE');
}
function m86_http(string $app,string $method,string $path,?string $token,?array $body=null,?array $file=null): array {
    audit_guard_assert();m86_expect(in_array($app,['adminapi','merchantapi'],true)&&str_starts_with($path,'/'.$app.'/')&&in_array($method,['GET','POST','PUT'],true),'FIXED_NORMAL_API');$plain=strtok($path,'?');$allowed=[
        'POST /adminapi/login','POST /merchantapi/password/setup','POST /merchantapi/login',
        'POST /merchantapi/products','POST /merchantapi/attachments/upload','POST /merchantapi/attachment-categories',
        'GET /merchantapi/attachments','GET /merchantapi/attachments/videos','GET /merchantapi/attachments/audio'];
    $finite=in_array($method.' '.$plain,$allowed,true)
        ||preg_match('~\A(?:GET|POST) /adminapi/product/product/(?:0|[1-9][0-9]*)\z~D',$method.' '.$plain)===1
        ||preg_match('~\APOST /adminapi/product/product/(?:0|[1-9][0-9]*)/media(?:/videos/upload|/audio/upload|/categories)?\z~D',$method.' '.$plain)===1
        ||preg_match('~\AGET /adminapi/product/product/[1-9][0-9]*/media\z~D',$method.' '.$plain)===1
        ||preg_match('~\A(?:GET|PUT) /merchantapi/products/[1-9][0-9]*\z~D',$method.' '.$plain)===1
        ||preg_match('~\APOST /merchantapi/products/(?:0|[1-9][0-9]*)/media/(?:videos|audio)/upload\z~D',$method.' '.$plain)===1;
    m86_expect($finite&&!($method==='GET'&&$plain==='/adminapi/product/product/0'),'ONLY_ACTUAL_NORMAL_SLICE_ROUTES');$headers=['Cb-lang'=>'zh-CN'];
    if($token!==null)$headers[$app==='adminapi'?'Authori-zation':'Authorization']='Bearer '.$token;
    $before=[];$actors=null;if($app==='adminapi'&&$token!==null){$actors=m86_actor_inputs();$before=m86_q(m86_db(),'SELECT * FROM eb_system_log WHERE admin_id=? ORDER BY id',[$actors['platform']['id']]);}
    $start=time();
    if($file===null)$r=audit_http($method,m86_url($path),$headers,$body);
    else{
        m86_expect($method==='POST','MULTIPART_POST_ONLY');$c=curl_init(m86_url($path));m86_expect($c!==false,'MULTIPART_CLIENT');$lines=[];foreach($headers as$k=>$v)$lines[]=$k.': '.$v;
        $fields=$body??[];m86_expect(!array_key_exists('file',$fields),'ONE_REAL_UPLOAD_FIELD');$mime=mime_content_type($file['path']);m86_expect(is_string($mime),'ACTUAL_FIXTURE_MIME');$fields['file']=new CURLFile($file['path'],$mime,$file['name']);
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$lines,CURLOPT_POSTFIELDS=>$fields,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP]);
        $raw=curl_exec($c);$http=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);$errno=curl_errno($c);curl_close($c);m86_expect(is_string($raw)&&$errno===0,'ACTUAL_MULTIPART_TRANSPORT');$j=json_decode($raw,true);$r=['http'=>$http,'json'=>is_array($j)?$j:null,'raw'=>$raw];
    }
    $end=time();$GLOBALS['m86_requests'][]=['app'=>$app,'method'=>$method,'path'=>$path,'http'=>$r['http'],'business_status'=>$r['json']['status']??null,'response'=>m86_pin($r['raw']),'actual_multipart'=>$file!==null];
    m86_expect($r['http']===200&&is_array($r['json'])&&($r['json']['status']??null)===200&&is_string($r['json']['msg']??null),'REAL_HTTP_BUSINESS_200');
    $keys=array_keys($r['json']);sort($keys);m86_expect(in_array($keys,[['data','msg','status'],['msg','status']],true),'REAL_SUCCESS_ENVELOPE');
    if($actors!==null){$after=m86_q(m86_db(),'SELECT * FROM eb_system_log WHERE admin_id=? ORDER BY id',[$actors['platform']['id']]);$added=m86_preserved($before,$after,'id');m86_expect(count($added)===1,'ONE_REAL_ADMIN_VISIT');$v=$added[0];m86_expect($v['admin_name']===$actors['platform']['account']&&$v['method']==='adminapi'&&$v['type']==='system'&&$v['path']===m86_admin_visit_rule($method,$path)&&$v['ip']===gethostbyname(gethostname())&&(int)$v['add_time']>=$start&&(int)$v['add_time']<=$end,'ACTUAL_ADMIN_VISIT_FIELDS');}
    $data=$r['json']['data']??[];m86_expect(is_array($data),'ARRAY_SUCCESS_DATA');return $data;
}
function m86_claims(string $token,array $config): array {
    $stamp=\Firebase\JWT\JWT::$timestamp;$leeway=\Firebase\JWT\JWT::$leeway;
    try{\Firebase\JWT\JWT::$timestamp=null;\Firebase\JWT\JWT::$leeway=60;return json_decode(json_encode(\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);}
    finally{\Firebase\JWT\JWT::$timestamp=$stamp;\Firebase\JWT\JWT::$leeway=$leeway;}
}
function m86_session(string $app,string $token,int $id,string $digest,int $exp,array &$evidence): array {
    $config=audit_config();$claims=m86_claims($token,$config);$type=$app==='adminapi'?'admin':'merchant';$aud=$app==='adminapi'?'adminapi':'tianli:merchantapi';
    m86_expect(($claims['auth_schema']??null)===2&&($claims['session_epoch']??null)===$config['auth']['session_epoch']&&($claims['iss']??null)===$config['auth']['issuer']&&($claims['aud']??null)===$aud&&($claims['jti']['type']??null)===$type&&($claims['jti']['id']??null)===(string)$id,'ACTUAL_SIGNED_IDENTITY');
    m86_expect(is_int($claims['iat']??null)&&($claims['nbf']??null)===$claims['iat']&&($claims['exp']??null)===$exp&&$exp-$claims['iat']===30*86400&&preg_match('/\A[a-f0-9]{32}\z/D',$claims['sid']??'')===1&&preg_match('/\A[a-f0-9]{32}\z/D',$claims['session_version']??'')===1,'ACTUAL_V2_LIFETIME_BINDING');
    foreach(['pwd','password','token_hash','credential_stamp','token_digest','stamp_key']as$k)m86_expect(!array_key_exists($k,$claims)&&!array_key_exists($k,$claims['jti']??[]),'CLAIMS_NO_CREDENTIAL_MATERIAL');
    $base='auth:v2:'.$config['auth']['session_epoch'].':'.$app.':';$session=$base.'session:'.$claims['sid'];$account=$base.'account:'.$id;
    $expected=['subject_id'=>(string)$id,'session_version'=>$claims['session_version'],'type'=>$type,'audience'=>$aud,'token_digest'=>hash('sha256',$token),'credential_stamp'=>hash_hmac('sha256',$app.'|'.$id.'|'.$digest,$config['auth']['stamp_key']),'expires_at'=>(string)$exp,'status'=>'active'];
    $r=audit_redis();$actual=$r->hGetAll($session);ksort($actual);ksort($expected);m86_expect($actual===$expected,'REAL_SESSION_ALL_FIELDS');$record=$r->hGetAll($account);ksort($record);$want=['blocked'=>'0','initialized'=>'1','session_version'=>$claims['session_version']];ksort($want);m86_expect($record===$want&&$r->pttl($account)===-1,'REAL_ACCOUNT_VERSION');
    $clock=$r->eval("local t=redis.call('TIME');local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000);return {n,redis.call('PTTL',KEYS[1])}",[$session],1);m86_expect(is_array($clock)&&count($clock)===2&&$clock[1]>0&&abs(($clock[0]+$clock[1])-($exp+60)*1000)<=1,'REAL_SESSION_ABSOLUTE_EXPIRY');
    $evidence['sessions'][]=['app'=>$app,'subject_id'=>$id,'actual_signed_and_cache_binding'=>true,'session_key_sha256'=>hash('sha256',$session),'fixed_lifetime_seconds'=>30*86400];return $claims;
}
function m86_login_admin(array $a,array &$evidence): string {
    m86_phase('admin_real_login');$before=m86_q(m86_db(),'SELECT * FROM eb_system_admin WHERE id=?',[$a['id']]);m86_expect(count($before)===1&&password_verify($a['password'],$before[0]['pwd']),'REAL_SYNTHETIC_ADMIN_PASSWORD');$start=time();$d=m86_http('adminapi','POST','/adminapi/login',null,['account'=>$a['account'],'pwd'=>$a['password'],'key'=>'']);$end=time();m86_expect(is_string($d['token']??null)&&$d['token']!==''&&is_int($d['expires_time']??null),'REAL_ADMIN_TOKEN_RESPONSE');
    $after=m86_q(m86_db(),'SELECT * FROM eb_system_admin WHERE id=?',[$a['id']]);m86_expect(count($after)===1,'ADMIN_AFTER_LOGIN');foreach($before[0]as$k=>$v)if(!in_array($k,['last_time','last_ip','login_count'],true))m86_expect($after[0][$k]===$v,'ADMIN_LOGIN_OTHER_COLUMNS');m86_expect((int)$after[0]['login_count']===(int)$before[0]['login_count']+1&&(int)$after[0]['last_time']>=$start&&(int)$after[0]['last_time']<=$end,'REAL_ADMIN_LOGIN_STATISTICS');
    m86_session('adminapi',$d['token'],$a['id'],$after[0]['pwd'],$d['expires_time'],$evidence);m86_expect((int)($d['user_info']['id']??0)===$a['id']&&is_array($d['unique_auth']??null),'REAL_ADMIN_LOGIN_DTO');return $d['token'];
}
function m86_login_merchant(array $a,array &$evidence): string {
    m86_phase('merchant_real_password_setup');$p=m86_db();$eb=m86_q($p,'SELECT * FROM eb_marketplace_employee WHERE id=? AND mer_id=?',[$a['employee_id'],$a['mer_id']]);$sb=m86_q($p,'SELECT * FROM eb_marketplace_password_setup WHERE id=? AND employee_id=?',[$a['setup_id'],$a['employee_id']]);m86_expect(count($eb)===count($sb)&&count($eb)===1&&$eb[0]['pwd']===null&&$sb[0]['consumed_at']===null,'FRESH_REAL_SETUP_CAPABILITY');
    $start=time();m86_expect(m86_http('merchantapi','POST','/merchantapi/password/setup',null,['token'=>$a['cap'],'password'=>$a['password']])===[],'REAL_SETUP_EMPTY_SUCCESS');$end=time();$ea=m86_q(m86_db(),'SELECT * FROM eb_marketplace_employee WHERE id=? AND mer_id=?',[$a['employee_id'],$a['mer_id']]);$sa=m86_q(m86_db(),'SELECT * FROM eb_marketplace_password_setup WHERE id=?',[$a['setup_id']]);
    m86_expect(count($ea)===count($sa)&&count($ea)===1&&is_string($ea[0]['pwd'])&&password_verify($a['password'],$ea[0]['pwd'])&&$sa[0]['token_hash']===hash('sha256',$a['cap'],true)&&(int)$sa[0]['consumed_at']>=$start&&(int)$sa[0]['consumed_at']<=$end,'REAL_SETUP_HASH_AND_CONSUMPTION');
    foreach($eb[0]as$k=>$v)if(!in_array($k,['pwd','update_time'],true))m86_expect($ea[0][$k]===$v,'SETUP_OTHER_EMPLOYEE_COLUMNS');foreach($sb[0]as$k=>$v)if($k!=='consumed_at')m86_expect($sa[0][$k]===$v,'SETUP_OTHER_CAPABILITY_COLUMNS');
    m86_phase('merchant_real_login');$d=m86_http('merchantapi','POST','/merchantapi/login',null,['account'=>$a['account'],'password'=>$a['password']]);$keys=array_keys($d);sort($keys);m86_expect($keys===['exp','token']&&is_string($d['token'])&&is_int($d['exp']),'REAL_MERCHANT_TOKEN_RESPONSE');m86_session('merchantapi',$d['token'],$a['employee_id'],$ea[0]['pwd'],$d['exp'],$evidence);return $d['token'];
}
function m86_audit_delta(array $before,array $after,string $actorType,int $actorId,int $owner,string $action,string $resource,int $id,int $start,int $end): array {
    $added=m86_preserved($before,$after,'id');m86_expect(count($added)===1,'ONE_REAL_MARKETPLACE_AUDIT');$r=$added[0];m86_expect($r['actor_type']===$actorType&&(int)$r['actor_id']===$actorId&&(int)$r['mer_id']===$owner&&$r['action']===$action&&$r['resource_type']===$resource&&(string)$r['resource_id']===(string)$id&&$r['result']==='success'&&(int)$r['add_time']>=$start&&(int)$r['add_time']<=$end,'REAL_AUDIT_OWNER_ACTOR_RESOURCE');$detail=json_decode($r['detail'],true,512,JSON_THROW_ON_ERROR);m86_expect(is_array($detail)&&is_array($detail['changed_fields']??null),'REAL_AUDIT_METADATA');return $r;
}
function m86_native_media(int $id,int $owner): array {$rows=m86_q(m86_db(),'SELECT * FROM eb_system_attachment WHERE att_id=? AND mer_id=?',[$id,$owner]);m86_expect(count($rows)===1&&array_keys($rows[0])===M86_NATIVE14,'COMPLETE_NATIVE14_RESELECT');return $rows[0];}
function m86_local_object(array $native): array {
    $url=m86_url($native['att_dir']);$parts=parse_url($url);m86_expect(!isset($parts['query'])&&str_starts_with($parts['path']??'','/uploads/'),'NATIVE_ORIGINAL_LOCAL_OBJECT_PATH');$relative=rawurldecode(substr($parts['path'],strlen('/uploads/')));$pieces=explode('/',$relative);
    m86_expect($relative!==''&&!str_contains($relative,"\0")&&!str_contains($relative,'\\'),'LOCAL_OBJECT_SAFE_SEGMENTS');$root='/run/media-uploads-observe';m86_expect(is_dir($root)&&!is_link($root),'ACTUAL_SAME_UPLOAD_VOLUME_READONLY_OBSERVER');$path=$root;
    foreach($pieces as$i=>$piece){m86_expect($piece!==''&&$piece!=='.'&&$piece!=='..','LOCAL_OBJECT_NO_TRAVERSAL');$path.='/'.$piece;m86_expect(!is_link($path)&&($i===count($pieces)-1?is_file($path):is_dir($path)),'ACTUAL_LOCAL_OBJECT_NO_LINKS');}
    clearstatcache(true,$path);$before=lstat($path);m86_expect(is_array($before)&&($before['mode']&0170000)===0100000&&$before['size']===(int)$native['att_size']&&$before['size']>0,'ACTUAL_LOCAL_NATIVE_SIZE');$fields=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];$named=static function(array$s)use($fields):array{$v=[];foreach($fields as$k){m86_expect(is_int($s[$k]??null),'LOCAL_NAMED_STAT');$v[$k]=$s[$k];}return $v;};$snapshot=$named($before);$h=fopen($path,'rb');m86_expect(is_resource($h),'LOCAL_OBJECT_OPEN');$hash=hash_init('sha256');$count=0;
    try{m86_expect($named(fstat($h))===$snapshot,'LOCAL_OPEN_SAME_NATIVE_OBJECT');while(!feof($h)){$chunk=fread($h,1048576);m86_expect(is_string($chunk),'LOCAL_OBJECT_STREAM');if($chunk===''){m86_expect(feof($h),'LOCAL_OBJECT_EOF');break;}$count+=strlen($chunk);m86_expect($count<=$before['size'],'LOCAL_OBJECT_NATIVE_BOUND');hash_update($hash,$chunk);}m86_expect($count===$before['size']&&feof($h)&&$named(fstat($h))===$snapshot,'LOCAL_FULL_BYTES_EOF_SAME_IDENTITY');}finally{m86_expect(fclose($h),'LOCAL_OBJECT_CLOSED');}
    clearstatcache(true,$path);m86_expect($named(lstat($path))===$snapshot,'LOCAL_OBJECT_NAME_STABLE');return ['path'=>$path,'stat'=>$snapshot,'bytes'=>$count,'sha256'=>hash_final($hash),'relative_path_sha256'=>hash('sha256',$relative)];
}
function m86_get_media(string $url,array $native,string $kind,?array $fixture,array &$evidence): void {
    $physical=m86_local_object($native);$url=m86_url($url);m86_expect(str_starts_with(parse_url($url,PHP_URL_PATH)??'','/uploads/'),'ACTUAL_PUBLIC_UPLOAD_URL');$maximum=(int)$native['att_size'];m86_expect($maximum>0,'REAL_NATIVE_OBJECT_SIZE');$body='';$types=[];$encoding=[];$c=curl_init($url);m86_expect($c!==false,'MEDIA_HTTP_CLIENT');
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_HTTPGET=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_HTTPHEADER=>['Accept-Encoding: identity'],CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$types,&$encoding):int{if(str_starts_with($line,'HTTP/')){$types=[];$encoding=[];}elseif(stripos($line,'Content-Type:')===0)$types[]=trim(substr($line,13));elseif(stripos($line,'Content-Encoding:')===0)$encoding[]=trim(substr($line,17));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$body,$maximum):int{if(strlen($body)>$maximum-strlen($chunk))return 0;$body.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);$errno=curl_errno($c);curl_close($c);m86_expect($ok!==false&&$errno===0&&$status===200&&strlen($body)===$maximum&&count($types)===1&&($encoding===[]||$encoding===['identity']),'REAL_MEDIA_FULL_HTTP_BODY');$served=strtolower(trim(explode(';',$types[0],2)[0]));$facts=\app\services\marketplace\attachment\MerchantAttachmentReferenceFormat::qualify($body);m86_expect($facts['resolved_kind']===$kind&&$facts['bytes']===$maximum&&in_array($native['att_type'],$facts['mime_aliases'],true)&&in_array($served,$facts['mime_aliases'],true),'ACTUAL_BODY_NATIVE_AND_SERVED_MIME');
    m86_expect($physical['bytes']===$facts['bytes']&&$physical['sha256']===$facts['sha256'],'HTTP_BODY_EQUALS_COMPLETE_REAL_UPLOAD_VOLUME_OBJECT');clearstatcache(true,$physical['path']);$namedAfter=lstat($physical['path']);foreach($physical['stat']as$k=>$v)m86_expect(($namedAfter[$k]??null)===$v,'PHYSICAL_OBJECT_UNCHANGED_THROUGH_HTTP_READ');
    if($fixture!==null&&$kind!=='image')m86_expect($facts['sha256']===$fixture['sha256']&&$facts['bytes']===$fixture['bytes'],'UNCHANGED_VIDEO_AUDIO_FIXTURE_BYTES');
    $evidence['http_media'][]=['att_id'=>(int)$native['att_id'],'kind'=>$kind,'actual_url_sha256'=>hash('sha256',$url),'actual_complete_body'=>['bytes'=>$facts['bytes'],'sha256'=>$facts['sha256']],'served_mime'=>$served,'native_mime'=>$native['att_type'],'fixture_sha256'=>$fixture['sha256']??null,'image_source_processing_may_reencode'=>$kind==='image','actual_upload_volume_full_bytes_equal_HTTP'=>true,'physical_stat'=>$physical['stat'],'relative_path_sha256'=>$physical['relative_path_sha256']];unset($body);
}
