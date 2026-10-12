<?php
declare(strict_types=1);

/* Ordinary Task6. Valid current credentials and owned records only.
 * Stopped denial, old-token, fault, concurrency and recovery cases remain NOT RUN.
 * Shared ordinary snapshot/language/session helpers copied from the PASS Task5 source.
 */
require __DIR__ . '/lib/harness.php';
if ((audit_manifest()['guard_mode'] ?? '') !== 'read-only') {
    fwrite(STDERR, "BLOCKED ordinary Task6 requires read-only isolation checks\n");
    exit(2);
}
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
function m6h_diag(Throwable $error): array
{
    $caller=$error->getTrace()[0] ?? [];
    return ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'caller_file'=>basename($caller['file'] ?? ''),'caller_line'=>$caller['line'] ?? null];
}

function m6h_scan(string $pattern): array
{
    $cursor=null; $keys=[]; $redis=audit_redis();
    do { $keys=array_merge($keys,$redis->scan($cursor,$pattern,200) ?: []); } while ($cursor !== 0);
    $keys=array_values(array_unique($keys)); sort($keys,SORT_STRING); return $keys;
}

function m6h_deadlines(array $keys): array
{
    if (!$keys) return [];
    $values=audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r",$keys,count($keys));
    audit_expect(is_array($values) && count($values)===count($keys),'every native cache deadline is available');
    $result=[]; foreach ($keys as $i=>$key) { audit_expect(is_int($values[$i]),'cache deadline is integral'); $result[$key]=$values[$i]; }
    return $result;
}

function m6h_snapshot(): array
{
    $pdo=audit_db(); $names=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($names,SORT_STRING);
    $snapshot=['names'=>$names,'rows'=>[],'ddl'=>[],'checksums'=>[],'redis'=>[]];
    foreach ($names as $name) {
        audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D',$name)===1,'snapshot table name differs');
        $primary=$pdo->query('SHOW KEYS FROM `'.$name.'` WHERE Key_name=\'PRIMARY\'')->fetchAll();
        usort($primary,static fn(array $a,array $b): int=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $order=array_map(static fn(array $key): string=>'`'.str_replace('`','``',$key['Column_name']).'`',$primary);
        $rows=$pdo->query('SELECT * FROM `'.$name.'`'.($order ? ' ORDER BY '.implode(',',$order) : ''))->fetchAll();
        // serialize preserves BINARY(32) setup digests without invalid-UTF8 JSON.
        $hashes=array_map(static fn(array $row): string=>hash('sha256',serialize($row)),$rows);
        if (!$order) sort($hashes,SORT_STRING);
        $snapshot['rows'][$name]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$hashes))];
        $create=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
        $snapshot['ddl'][$name]=['sha256'=>hash('sha256',$create),'without_counter_sha256'=>hash('sha256',preg_replace('/(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+ /','$1 ',$create))];
        $checksum=$pdo->query('CHECKSUM TABLE `'.$name.'`')->fetch(PDO::FETCH_ASSOC)['Checksum'];
        $snapshot['checksums'][$name]=$checksum===null ? null : (string)$checksum;
    }
    $redis=audit_redis(); $keys=m6h_scan('*'); $deadlines=m6h_deadlines($keys);
    foreach ($keys as $key) {
        if ($redis->type($key)===Redis::REDIS_SET) { $members=$redis->sMembers($key); sort($members,SORT_STRING); $value="set\0".serialize($members); }
        else { $value=(string)$redis->dump($key); }
        $snapshot['redis'][$key]=['sha256'=>hash('sha256',$value),'deadline'=>$deadlines[$key]];
    }
    return $snapshot;
}

function m6h_public_snapshot(array $snapshot): array
{
    $redis=[]; foreach ($snapshot['redis'] as $key=>$value) $redis[hash('sha256',$key)]=$value;
    ksort($redis); $snapshot['redis']=$redis; return $snapshot;
}

function m6h_same(array $before,array $after,array $changedTables=[],array $changedKeys=[],array $counterTables=[]): void
{
    audit_expect($before['names']===$after['names'],'table inventory changed');
    foreach ($before['names'] as $table) {
        if (!in_array($table,$changedTables,true)) audit_expect($before['rows'][$table]===$after['rows'][$table] && $before['checksums'][$table]===$after['checksums'][$table],'unrelated rows or checksum changed');
        $column=in_array($table,$counterTables,true) ? 'without_counter_sha256' : 'sha256';
        audit_expect($before['ddl'][$table][$column]===$after['ddl'][$table][$column],'unrelated schema changed');
    }
    foreach (array_unique(array_merge(array_keys($before['redis']),array_keys($after['redis']))) as $key) {
        if (in_array($key,$changedKeys,true)) continue;
        audit_expect(isset($before['redis'][$key],$after['redis'][$key]),'unrelated cache inventory changed');
        $a=$before['redis'][$key]; $b=$after['redis'][$key];
        audit_expect($a['sha256']===$b['sha256'],'unrelated cache value changed');
        audit_expect(($a['deadline']<0 || $b['deadline']<0) ? $a['deadline']===$b['deadline'] : abs($a['deadline']-$b['deadline'])<=1,'unrelated cache expiry changed');
    }
}

function m6h_app(array $config,string $prefix): \think\App
{
    $app=new \think\App(audit_repo_dir().'/crmeb/');
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],
        'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306','DATABASE_DATABASE'=>'audit_auth',
        'DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],'DATABASE_PREFIX'=>'eb_','DATABASE_DEBUG'=>false,
        'CACHE_DRIVER'=>'redis','REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
        'AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]);
    $app->initialize();
    $connection=$app->config->get('database.connections.mysql');
    audit_expect(is_array($connection),'actual default mysql configuration is available');
    $connection['charset']='utf8mb4'; $connection['debug']=false; $connection['break_reconnect']=false; $connection['trigger_sql']=false;
    $app->config->set(['connections'=>['mysql'=>$connection]],'database'); return $app;
}

function m6h_warm_language(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): void
{
    $code=$app->make(\app\services\system\lang\LangCodeServices::class);
    $type=$app->make(\app\services\system\lang\LangTypeServices::class);
    $country=$app->make(\app\services\system\lang\LangCountryServices::class);
    $range=$type->value(['is_default'=>1],'file_name');
    audit_expect($range==='zh-CN','installed default language must be the normal zh-CN source');
    $typeId=$country->value(['code'=>$range],'type_id') ?: 1;
    $types=$type->getColumn(['status'=>1,'is_del'=>0],'file_name','id');
    audit_expect(isset($types[$typeId]),'installed language type is enabled');
    $values=['range_name'=>$range,'sys_lang_source_map'=>$code->getColumn(['type_id'=>1],'code','remarks'),
        'type_id_'.$range=>$typeId,'lang_type_data'=>$types,
        'lang_'.str_replace('-','_',$types[$typeId])=>$code->getColumn(['type_id'=>$typeId],'lang_explain','code')];
    $tag=$prefix.'CRMEB:'.md5('crmeb'); $keys=array_map(static fn(string $name): string=>$prefix.$name,array_keys($values));
    $cleanupKeys=array_merge($keys,[$tag],array_map(static fn(string $key): string=>$key.'_lock',$keys));
    $before=m6h_snapshot();
    foreach ($cleanupKeys as $key) audit_expect(!isset($before['redis'][$key]),'exact language preparation key was already present');
    // Register before the real normal producer, for precise partial-run cleanup.
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,$cleanupKeys)));
    $start=time(); getLang('操作成功'); $end=time(); $after=m6h_snapshot(); $redis=audit_redis();
    $actualNew=array_values(array_diff(array_keys($after['redis']),array_keys($before['redis']))); $expectedNew=array_merge($keys,[$tag]);
    sort($actualNew,SORT_STRING); sort($expectedNew,SORT_STRING);
    audit_expect($actualNew===$expectedNew,'normal language warmup added unexpected keys');
    foreach ($values as $name=>$value) {
        $key=$prefix.$name; $expected=is_numeric($value) ? (string)$value : serialize($value);
        audit_expect($redis->type($key)===Redis::REDIS_STRING && $redis->get($key)===$expected,'language cache differs from its actual read-only DB producer');
        $deadline=$after['redis'][$key]['deadline'];
        audit_expect($name==='range_name' ? $deadline===-1 : $deadline>=($start+3600)*1000 && $deadline<=($end+3600)*1000+999,'language cache expiry differs from source');
    }
    $members=$redis->sMembers($tag); sort($members,SORT_STRING); $tagged=$keys; sort($tagged,SORT_STRING);
    audit_expect($redis->type($tag)===Redis::REDIS_SET && $members===$tagged && $after['redis'][$tag]['deadline']===-1,'language tag differs from exact producer keys');
    m6h_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['language_preparation']=['source'=>'real getLang and installed LangCode/LangType/LangCountry services',
        'logical_keys'=>array_keys($values),'added_key_hashes'=>array_map(static fn(string $key): string=>hash('sha256',$key),$expectedNew),
        'exact_values_and_source_expiry_verified'=>true,'unrelated_state_preserved'=>true];
}

function m6h_http(string $method,string $path,?string $token,?array $body,array &$evidence,bool $platform=false): array
{
    $headers=['cb-lang'=>'zh-CN']; if ($token!==null) $headers[$platform ? 'Authori-zation' : 'Authorization']='Bearer '.$token;
    $response=audit_http($method,'http://audit-app:8000'.$path,$headers,$body);
    $evidence['requests'][]=['method'=>$method,'path'=>$path,'http'=>$response['http'],'business_status'=>is_int($response['json']['status'] ?? null) ? $response['json']['status'] : null];
    audit_expect($response['http']===200 && is_array($response['json']) && ($response['json']['status'] ?? null)===200,'normal HTTP operation must succeed with real 200');
    $keys=array_keys($response['json']); sort($keys,SORT_STRING);
    audit_expect($keys===['data','msg','status'] && is_string($response['json']['msg']) && is_array($response['json']['data']),'response envelope differs');
    return $response['json']['data'];
}

function m6h_claims(string $token,array $config): array
{
    $timestamp=\Firebase\JWT\JWT::$timestamp; $leeway=\Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp=null; \Firebase\JWT\JWT::$leeway=60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp=$timestamp; \Firebase\JWT\JWT::$leeway=$leeway; }
}

function m6h_binding(array $data,array $f,array $config,string $v2,array &$ownedKeys,array &$secrets): array
{
    $keys=array_keys($data); sort($keys,SORT_STRING);
    audit_expect($keys===['exp','token'] && is_string($data['token']) && $data['token']!=='' && is_int($data['exp']),'token response contains exactly token/exp');
    $secrets[]=$data['token']; $claims=m6h_claims($data['token'],$config);
    foreach (['pwd','password','token_hash','credential_stamp','token_digest','stamp_key'] as $private) {
        audit_expect(!array_key_exists($private,$claims) && !array_key_exists($private,$claims['jti'] ?? []),'normal signed claims omit credential material');
    }
    audit_expect(($claims['auth_schema'] ?? null)===2 && ($claims['session_epoch'] ?? null)===$config['auth']['session_epoch']
        && ($claims['iss'] ?? null)===$config['auth']['issuer'] && ($claims['aud'] ?? null)==='tianli:merchantapi'
        && ($claims['jti']['type'] ?? null)==='merchant' && ($claims['jti']['id'] ?? null)===(string)$f['employee_id']
        && is_string($claims['sid'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$claims['sid'])===1
        && is_string($claims['session_version'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$claims['session_version'])===1
        && ($claims['exp'] ?? null)===$data['exp'] && is_int($claims['iat'] ?? null) && $claims['exp']-$claims['iat']===30*86400,'signed normal merchant binding differs');
    $account=$v2.'account:'.$f['employee_id']; $session=$v2.'session:'.$claims['sid']; $ownedKeys[]=$account; $ownedKeys[]=$session;
    $pdo=audit_db(); $stmt=$pdo->prepare('SELECT pwd FROM eb_marketplace_employee WHERE id=? AND mer_id=?'); $stmt->execute([$f['employee_id'],$f['mer_id']]); $digest=$stmt->fetchColumn();
    audit_expect(is_string($digest) && password_verify($f['password'],$digest),'current stored normal password matches'); $secrets[]=$digest;
    $stamp=hash_hmac('sha256','merchantapi|'.$f['employee_id'].'|'.$digest,$config['auth']['stamp_key']); $secrets[]=$stamp;
    $expected=['subject_id'=>(string)$f['employee_id'],'session_version'=>$claims['session_version'],'type'=>'merchant','audience'=>'tianli:merchantapi',
        'token_digest'=>hash('sha256',$data['token']),'credential_stamp'=>$stamp,'expires_at'=>(string)$claims['exp'],'status'=>'active'];
    $redis=audit_redis(); $actual=$redis->hGetAll($session); ksort($actual); ksort($expected);
    audit_expect($actual===$expected,'actual session hash differs from full current binding');
    $actualAccount=$redis->hGetAll($account); $expectedAccount=['initialized'=>'1','blocked'=>'0','session_version'=>$claims['session_version']]; ksort($actualAccount); ksort($expectedAccount);
    audit_expect($actualAccount===$expectedAccount && $redis->pttl($account)===-1,'actual account must be initialized persistent and unprotected');
    $deadline=m6h_deadlines([$session])[$session]; audit_expect(abs($deadline-($claims['exp']+60)*1000)<=1,'session deadline differs from signed expiry plus60');
    return ['token'=>$data['token'],'claims'=>$claims,'account'=>$account,'session'=>$session,'record'=>$actual,'account_record'=>$actualAccount,'deadline'=>$deadline];
}

function m6h_dto(array $actual,array $f): void
{
    $expected=['employee'=>['id'=>$f['employee_id'],'mer_id'=>$f['mer_id'],'role_id'=>$f['role_id'],'account'=>$f['account'],'name'=>$f['name'],'avatar'=>$f['avatar'],'status'=>1],
        'role'=>['id'=>$f['role_id'],'mer_id'=>$f['mer_id'],'name'=>$f['role_name'],'permissions'=>$f['permissions']],
        'merchant'=>['mer_id'=>$f['mer_id'],'name'=>$f['merchant_name'],'logo'=>$f['logo'] ?? '',
            'after_sale_contact'=>$f['after_sale_contact'] ?? '','shipping_note'=>$f['shipping_note'] ?? '']];
    $keys=array_keys($actual); sort($keys); audit_expect($keys===['employee','merchant','role'],'me contains exactly its public identity DTOs');
    foreach ($expected as $name=>$fields) {
        audit_expect(is_array($actual[$name]),'normal identity DTO is an array'); $keys=array_keys($actual[$name]); $wanted=array_keys($fields); sort($keys); sort($wanted);
        audit_expect($keys===$wanted,'normal identity DTO has unexpected fields');
        foreach ($fields as $field=>$value) {
            $got=$actual[$name][$field];
            audit_expect(is_int($value) ? ((is_int($got) || is_string($got)) && (string)$got===(string)$value) : $got===$value,'normal identity DTO differs from current fixture');
        }
    }
}

function m6h_logs_private(array $secrets): bool
{
    $paths=[audit_run_dir().'/evidence/app-runtime.log'];
    foreach (['/run/audit-app-runtime',audit_repo_dir().'/crmeb/runtime'] as $root) {
        if (!is_dir($root)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('~(^|/)log(/|$)~',$file->getPathname())===1) $paths[]=$file->getPathname();
        }
    }
    foreach (array_unique($paths) as $path) {
        if (!is_file($path)) continue; $bytes=file_get_contents($path); audit_expect(is_string($bytes),'actual log bytes are readable');
        foreach (array_unique($secrets) as $secret) if ($secret!=='' && str_contains($bytes,$secret)) return false;
    }
    return true;
}

function m6h_merchant_routes(\think\App $app,array &$evidence): void
{
    $before=m6h_snapshot(); require audit_repo_dir().'/crmeb/app/merchantapi/route/route.php'; $actual=[];
    foreach ($app->route->getRuleList() as $row) $actual[]=['method'=>strtoupper($row['method']),'rule'=>$row['rule'],'name'=>$row['name']];
    $expected=[['POST','login','merchant.login'],['POST','password/setup','merchant.password.setup'],['POST','refresh','merchant.refresh'],
        ['POST','logout','merchant.logout'],['GET','me','merchant.me'],['GET','employees','merchant.employee.list'],['POST','employees','merchant.employee.create'],
        ['PUT','employees/<id>','merchant.employee.update'],['PUT','employees/<id>/status','merchant.employee.status'],
        ['GET','roles','merchant.role.list'],['POST','roles','merchant.role.create'],['PUT','roles/<id>','merchant.role.update']];
    $expected=array_map(static fn(array $r): array=>['method'=>$r[0],'rule'=>$r[1],'name'=>$r[2]],$expected);
    $sort=static fn(array $a,array $b): int=>strcmp($a['rule'].'|'.$a['method'],$b['rule'].'|'.$b['method']);
    usort($actual,$sort); usort($expected,$sort); audit_expect($actual===$expected,'actual merchant routes must be exactly the twelve declared interfaces');
    m6h_same($before,m6h_snapshot()); $evidence['merchant_routes']=['actual_framework_rules'=>$actual,'exact_twelve_verified'=>true,'product_routes'=>0];
}

function m6h_valid_capability(string $cap): void
{
    $decoded=base64_decode(strtr($cap,'-_','+/').'=',true);
    audit_expect(preg_match('/^[A-Za-z0-9_-]{43}$/D',$cap)===1 && is_string($decoded) && strlen($decoded)===32
        && rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')===$cap,'normal capability has its canonical32-byte base64url representation');
}

function m6h_identity_rows(): array
{
    $rows=[]; $pdo=audit_db();
    foreach (['merchant','role','employee','password_setup','audit_log'] as $table) {
        $pk=$table==='merchant' ? 'mer_id' : 'id'; $rows[$table]=[];
        foreach ($pdo->query('SELECT * FROM eb_marketplace_'.$table.' ORDER BY `'.$pk.'`')->fetchAll() as $row) $rows[$table][(int)$row[$pk]]=$row;
    }
    return $rows;
}

function m6h_value(mixed $actual,mixed $expected): bool
{
    return is_int($expected) ? ((is_int($actual) || is_string($actual)) && (string)$actual===(string)$expected) : $actual===$expected;
}

function m6h_setup(array $s,array &$state,array $config,string $v2,array &$ownedKeys,array &$secrets,array &$evidence): void
{
    $account=$v2.'account:'.$s['employee_id']; audit_expect(audit_redis()->exists($account)===0,'new normal employee has no initialized account'); $ownedKeys[]=$account;
    $before=m6h_snapshot(); $start=time(); audit_expect(m6h_http('POST','/merchantapi/password/setup',null,['token'=>$s['cap'],'password'=>$s['password']],$evidence)===[],'normal setup response is empty'); $end=time();
    $after=m6h_identity_rows(); $employee=$after['employee'][$s['employee_id']]; $setup=$after['password_setup'][$s['setup_id']];
    audit_expect($state['employee'][$s['employee_id']]['pwd']===null && $state['password_setup'][$s['setup_id']]['consumed_at']===null,'normal capability and password were initially unused');
    audit_expect(is_string($employee['pwd']) && $employee['pwd']!==$s['password'] && password_verify($s['password'],$employee['pwd']),'normal setup stores an actual verified password hash'); $secrets[]=$employee['pwd'];
    audit_expect((int)$employee['update_time']>=$start && (int)$employee['update_time']<=$end && $setup['consumed_at']!==null
        && (int)$setup['consumed_at']>=$start && (int)$setup['consumed_at']<=$end && $setup['token_hash']===hash('sha256',$s['cap'],true)
        && (int)$setup['expires_at']===(int)$setup['add_time']+86400,'normal capability binding and consumption time differ');
    $expected=$state; $expected['employee'][$s['employee_id']]['pwd']=$employee['pwd']; $expected['employee'][$s['employee_id']]['update_time']=$employee['update_time'];
    $expected['password_setup'][$s['setup_id']]['consumed_at']=$setup['consumed_at']; audit_expect($after===$expected,'normal setup changed another identity field'); $state=$after;
    $record=audit_redis()->hGetAll($account); ksort($record); audit_expect($record===['blocked'=>'0','initialized'=>'0'] && audit_redis()->pttl($account)===-1,'first setup completes without minting an authorization version');
    m6h_same($before,m6h_snapshot(),['eb_marketplace_employee','eb_marketplace_password_setup'],[$account]);
}

function m6h_login(array $s,array $config,string $v2,array &$ownedKeys,array &$secrets,array &$evidence,\think\App $app): array
{
    $before=m6h_snapshot(); $data=m6h_http('POST','/merchantapi/login',null,['account'=>$s['account'],'password'=>$s['password']],$evidence);
    $bound=m6h_binding($data,$s,$config,$v2,$ownedKeys,$secrets); m6h_same($before,m6h_snapshot(),[],[$bound['account'],$bound['session']]);
    $before=m6h_snapshot(); $ctx=$app->make(\app\services\marketplace\EmployeeAuthServices::class)->authenticate($bound['token']);
    audit_expect($ctx instanceof \app\services\marketplace\context\MerchantContext && $ctx->merId===$s['mer_id'] && $ctx->employeeId===$s['employee_id']
        && $ctx->permissions===$s['permissions'] && $ctx->sessionVersion===$bound['claims']['session_version'] && trim($ctx->requestId)!=='','new valid credential login produces the current real context');
    m6h_same($before,m6h_snapshot()); return $bound;
}

function m6h_me(array $s,array $bound,array &$evidence): void
{
    $before=m6h_snapshot(); m6h_dto(m6h_http('GET','/merchantapi/me',$bound['token'],null,$evidence),$s); m6h_same($before,m6h_snapshot());
}

function m6h_employee_dto(array $actual,array $row): void
{
    $fields=['id','mer_id','role_id','account','name','avatar','status','add_time','update_time']; $keys=array_keys($actual); $wanted=$fields; sort($keys); sort($wanted);
    audit_expect($keys===$wanted,'employee DTO contains exactly its public nine fields');
    foreach ($fields as $field) {
        $expected=in_array($field,['id','mer_id','role_id','status','add_time','update_time'],true) ? (int)$row[$field] : $row[$field];
        audit_expect(m6h_value($actual[$field],$expected),'employee DTO differs from current database row');
    }
}

function m6h_accounts(string $v2,array $ids): array
{
    $accounts=[]; foreach ($ids as $id) { $key=$v2.'account:'.$id; $row=audit_redis()->hGetAll($key); ksort($row);
        audit_expect(($row['initialized'] ?? '')==='1' && ($row['blocked'] ?? '')==='0' && !isset($row['owner']) && preg_match('/^[a-f0-9]{32}$/D',$row['session_version'] ?? '')===1 && audit_redis()->pttl($key)===-1,'normal current account is initialized and persistent'); $accounts[(int)$id]=$row; }
    return $accounts;
}

function m6h_rotated(string $v2,array $before,array &$evidence,string $operation): array
{
    $after=m6h_accounts($v2,array_keys($before));
    foreach ($before as $id=>$row) { audit_expect($after[$id]['session_version']!==$row['session_version'],'normal identity or permission change rotates current server version');
        $expected=$row; $expected['session_version']=$after[$id]['session_version']; audit_expect($after[$id]===$expected,'normal account completion changes only server version'); }
    $evidence['version_observations'][]=['operation'=>$operation,'affected_employee_ids'=>array_keys($before),'changed_and_unblocked'=>true,'old_token_requests'=>0]; return $after;
}

function m6h_platform_rules(): array
{
    return [
        ['GET','marketplace/merchants','marketplace-merchants-list'],
        ['POST','marketplace/merchants','marketplace-merchants-create'],
        ['GET','marketplace/merchants/<id>','marketplace-merchants-read'],
        ['PUT','marketplace/merchants/<id>','marketplace-merchants-update'],
        ['PUT','marketplace/merchants/<id>/review','marketplace-merchants-review'],
        ['PUT','marketplace/merchants/<id>/flags','marketplace-merchants-flags'],
        ['POST','marketplace/merchants/<id>/admin-employee','marketplace-merchants-admin-employee'],
    ];
}

function m6h_routes(\think\App $app,array &$evidence): void
{
    m6h_merchant_routes($app,$evidence); $app->route->clear(); $before=m6h_snapshot();
    require audit_repo_dir().'/crmeb/app/adminapi/route/marketplace.php'; $actual=[]; $wanted=m6h_platform_rules();
    foreach ($app->route->getRuleList() as $row) {
        $method=strtoupper($row['method']); $matched=array_values(array_filter($wanted,static fn(array $r): bool=>$r[0]===$method && $r[1]===$row['rule']));
        audit_expect(count($matched)===1,'platform framework contains only one of the frozen seven method/rules'); $action=$matched[0][2];
        $options=$row['option']; audit_expect(($options['marketplace_api'] ?? null)===true && ($options['marketplace_permission'] ?? null)===$action,'platform action is selected by its real server route');
        $middleware=$options['middleware'] ?? []; audit_expect(is_array($middleware),'platform middleware metadata is integral');
        $classes=array_map(static fn(mixed $m): string=>is_array($m) ? (string)($m[0] ?? '') : (is_string($m) ? $m : ''),$middleware);
        $boundary=array_search(\app\adminapi\middleware\MarketplaceBoundaryMiddleware::class,$classes,true);
        $auth=array_search(\app\adminapi\middleware\AdminAuthTokenMiddleware::class,$classes,true);
        $scope=array_search(\app\adminapi\middleware\MarketplaceScopeMiddleware::class,$classes,true);
        audit_expect(is_int($boundary) && is_int($auth) && is_int($scope) && $boundary<$auth && $auth<$scope,'actual boundary precedes platform authentication and current-scope authorization');
        audit_expect(!in_array(\app\adminapi\middleware\AdminLogMiddleware::class,$classes,true),'platform read audit uses the confirmed service path');
        $actual[]=['method'=>$method,'rule'=>$row['rule'],'permission'=>$action,'boundary_auth_scope_order_verified'=>true];
    }
    audit_expect(count($actual)===7,'platform has exactly seven actual routes');
    $pdo=audit_db(); $registrations=[];
    foreach ($wanted as [$method,$rule,$action]) {
        $stmt=$pdo->prepare('SELECT id,menu_name,methods,api_url,unique_auth,auth_type,is_del,mark FROM eb_system_menus WHERE unique_auth=?'); $stmt->execute([$action]); $rows=$stmt->fetchAll();
        audit_expect(count($rows)===1 && $rows[0]['methods']===$method && $rows[0]['api_url']===$rule && (int)$rows[0]['auth_type']===2
            && (int)$rows[0]['is_del']===0 && $rows[0]['mark']==='marketplace-ms1-v1','actual current permission leaf matches its server action and route');
        $registrations[]=['id'=>(int)$rows[0]['id'],'method'=>$method,'rule'=>$rule,'permission'=>$action];
    }
    m6h_same($before,m6h_snapshot()); $app->route->clear();
    $evidence['platform_routes']=['actual_framework_rules'=>$actual,'permission_registrations'=>$registrations,'exact_seven_verified'=>true,'product_routes'=>0];
}

function m6h_admin(PDO $pdo,string $slug,array $config,string $v2,array &$owners,array &$ownedKeys,array &$secrets,array &$evidence,int $level=0,string $roles=''): array
{
    $account='m6p_'.substr(hash('sha256',$slug),0,20); $name='M6 platform'; $password='M6p-'.bin2hex(random_bytes(24)).'-x';
    $digest=password_hash($password,PASSWORD_BCRYPT); audit_expect(is_string($digest) && password_verify($password,$digest),'synthetic platform password has a real digest');
    $secrets=array_merge($secrets,[$account,$name,$password,$digest]); $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_system_admin WHERE account=?'); $stmt->execute([$account]);
    audit_expect((int)$stmt->fetchColumn()===0,'exact synthetic administrator account starts absent');
    $before=m6h_snapshot(); $now=time(); $stmt=$pdo->prepare('INSERT INTO eb_system_admin(account,pwd,real_name,roles,add_time,level,status,is_del) VALUES(?,?,?,?,?,?,1,0)');
    $stmt->execute([$account,$digest,$name,$roles,$now,$level]); $id=(int)$pdo->lastInsertId(); audit_expect($id>0,'synthetic platform administrator has a real positive identity');
    $owners['system_admin'][$id]=['id'=>$id,'account'=>$account,'real_name'=>$name,'pwd'=>$digest,'roles'=>$roles,'level'=>$level];
    $stmt=$pdo->prepare('SELECT * FROM eb_system_admin WHERE id=? AND account=?'); $stmt->execute([$id,$account]); $row=$stmt->fetch();
    $expected=['id'=>$id,'account'=>$account,'head_pic'=>'','pwd'=>$digest,'real_name'=>$name,'roles'=>$roles,'last_ip'=>'','last_time'=>0,'add_time'=>$now,'login_count'=>0,'level'=>$level,'status'=>1,'division_id'=>0,'is_del'=>0];
    audit_expect(is_array($row) && count($row)===count($expected),'synthetic administrator has the exact installed fields');
    foreach ($expected as $field=>$value) audit_expect(m6h_value($row[$field] ?? null,$value),'synthetic administrator differs from its own intended record');
    // Existing administrator records are never selected for credentials. Their complete hashes remain unchanged.
    m6h_same($before,m6h_snapshot(),['eb_system_admin'],[],['eb_system_admin']);
    $accountKey=$v2.'account:'.$id; audit_expect(audit_redis()->exists($accountKey)===0,'new synthetic platform account has no existing session state'); $ownedKeys[]=$accountKey;
    $before=m6h_snapshot(); $store=\crmeb\services\auth\AuthSessionStore::fromConfig(); $version=$store->initAccount('adminapi',(string)$id);
    $stamp=\crmeb\services\auth\CredentialStamp::for('adminapi',(string)$id,$digest); $secrets[]=$stamp;
    $issued=(new \crmeb\services\auth\AuthTokenV2($store))->issue('adminapi',(string)$id,$version,$stamp);
    audit_expect(is_string($issued['token'] ?? null) && is_int($issued['exp'] ?? null),'existing production issuer returns its actual token and expiry'); $secrets[]=$issued['token'];
    $claims=m6h_claims($issued['token'],$config); $sid=$claims['sid'] ?? null;
    audit_expect(($claims['auth_schema'] ?? null)===2 && ($claims['session_epoch'] ?? null)===$config['auth']['session_epoch'] && ($claims['iss'] ?? null)===$config['auth']['issuer']
        && ($claims['aud'] ?? null)==='adminapi' && ($claims['jti']['type'] ?? null)==='admin' && ($claims['jti']['id'] ?? null)===(string)$id
        && ($claims['session_version'] ?? null)===$version && is_string($sid) && preg_match('/^[a-f0-9]{32}$/D',$sid)===1
        && ($claims['exp'] ?? null)===$issued['exp'] && is_int($claims['iat'] ?? null) && $claims['exp']-$claims['iat']===30*86400,'real newly issued platform token has its fixed current binding');
    foreach (['pwd','password','credential_stamp','token_digest','stamp_key'] as $private) audit_expect(!array_key_exists($private,$claims) && !array_key_exists($private,$claims['jti']),'platform claims omit credential material');
    $session=$v2.'session:'.$sid; $ownedKeys[]=$session;
    $expected=['subject_id'=>(string)$id,'session_version'=>$version,'type'=>'admin','audience'=>'adminapi','token_digest'=>hash('sha256',$issued['token']),
        'credential_stamp'=>$stamp,'expires_at'=>(string)$issued['exp'],'status'=>'active']; ksort($expected); $actual=audit_redis()->hGetAll($session); ksort($actual);
    $accountExpected=['blocked'=>'0','initialized'=>'1','session_version'=>$version]; $actualAccount=audit_redis()->hGetAll($accountKey); ksort($actualAccount);
    audit_expect($actual===$expected && $actualAccount===$accountExpected && audit_redis()->pttl($accountKey)===-1,'real platform session and initialized account match their complete persisted records');
    audit_expect(abs(m6h_deadlines([$session])[$session]-($issued['exp']+60)*1000)<=1,'platform session preserves its actual expiry plus60');
    m6h_same($before,m6h_snapshot(),[],[$accountKey,$session]);
    $identity=['admin_id'=>$id,'level'=>$level,'role_ids'=>$roles==='' ? [] : array_map('intval',explode(',',$roles)),
        'provenance'=>'new synthetic Db record -> AuthSessionStore.initAccount -> existing AuthTokenV2.issue with current CredentialStamp',
        'real_binding_verified'=>true,'captcha_or_platform_login_requests'=>0,'existing_admin_credentials_read'=>false];
    $evidence['platform_identities'][]=$identity; if ($level===0) $evidence['platform_identity']=$identity;
    return ['id'=>$id,'account'=>$account,'real_name'=>$name,'row'=>$row,'token'=>$issued['token'],'claims'=>$claims];
}

function m6h_permission_cache(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence,array $roleIds=[]): array
{
    $names=['all_auth',md5(\app\services\system\admin\SystemRoleServices::ADMIN_RULES_LEVEL.'_2_'.implode('_',$roleIds))]; $keys=array_map(static fn(string $name): string=>$prefix.$name,$names);
    $rows=audit_db()->query("SELECT methods,api_url FROM eb_system_menus WHERE api_url<>'' AND auth_type=2")->fetchAll(); $all=[];
    foreach ($rows as $row) $all[strtolower(trim($row['methods']))][] = strtolower(trim(str_replace(' ','',$row['api_url'])));
    $roleMap=$roleIds ? $app->make(\app\services\system\admin\SystemRoleServices::class)->getRolesByAuth($roleIds,2) : [];
    $values=[$all,$roleMap]; $tag=$prefix.'CRMEB:'.md5('crmeb'); $before=m6h_snapshot(); $oldMembers=audit_redis()->sMembers($tag); sort($oldMembers);
    foreach ($keys as $key) audit_expect(!isset($before['redis'][$key]),'owned permission preparation key starts absent');
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,$keys)));
    foreach ($names as $index=>$name) audit_expect(\crmeb\services\CacheService::set($name,$values[$index])===true,'normal permission cache producer acknowledges its current Db map');
    $after=m6h_snapshot(); foreach ($keys as $index=>$key) audit_expect(audit_redis()->type($key)===Redis::REDIS_STRING && audit_redis()->get($key)===serialize($values[$index]) && $after['redis'][$key]['deadline']===-1,'actual permission cache matches its normal current Db source');
    $members=audit_redis()->sMembers($tag); sort($members); $expected=array_values(array_unique(array_merge($oldMembers,$keys))); sort($expected);
    audit_expect($members===$expected && $after['redis'][$tag]['deadline']===-1,'normal permission preparation adds only its two exact tag members');
    m6h_same($before,$after,[],array_merge($keys,[$tag]));
    $preparation=['source'=>'existing CacheService.set of current SystemMenus route map and current synthetic role grants',
        'role_ids'=>$roleIds,'logical_keys'=>$names,'exact_key_count'=>2,'other_cache_preserved'=>true,'tag_retained_after_authorization'=>true];
    $evidence['permission_cache_preparations'][]=$preparation; $evidence['permission_cache_preparation'] ??= $preparation; return $keys;
}

function m6h_grant_role(PDO $pdo,string $slug,array &$owners,array &$secrets,array &$evidence): int
{
    $name='m6grant_'.substr(hash('sha256',$slug),0,20); $secrets[]=$name; $ids=[];
    foreach (m6h_platform_rules() as [$method,$rule,$action]) {
        $stmt=$pdo->prepare('SELECT id,methods,api_url,auth_type,is_del FROM eb_system_menus WHERE unique_auth=?'); $stmt->execute([$action]); $rows=$stmt->fetchAll();
        audit_expect(count($rows)===1 && $rows[0]['methods']===$method && $rows[0]['api_url']===$rule && (int)$rows[0]['auth_type']===2 && (int)$rows[0]['is_del']===0,'positive role grants refer only to seven real current platform permission leaves'); $ids[]=(int)$rows[0]['id'];
    }
    sort($ids,SORT_NUMERIC); audit_expect(count(array_unique($ids))===7,'positive platform role has seven distinct real grants'); $rules=implode(',',$ids);
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_system_role WHERE role_name=?'); $stmt->execute([$name]); audit_expect((int)$stmt->fetchColumn()===0,'new positive grant role name starts absent');
    $before=m6h_snapshot(); $stmt=$pdo->prepare('INSERT INTO eb_system_role(role_name,rules,level,status) VALUES(?,?,1,1)'); $stmt->execute([$name,$rules]); $id=(int)$pdo->lastInsertId();
    audit_expect($id>0,'positive platform role has an actual new identity'); $owners['system_role'][$id]=['id'=>$id,'role_name'=>$name,'rules'=>$rules,'level'=>1,'status'=>1];
    $stmt=$pdo->prepare('SELECT * FROM eb_system_role WHERE id=? AND role_name=?'); $stmt->execute([$id,$name]); $row=$stmt->fetch(); $wanted=$owners['system_role'][$id];
    audit_expect(is_array($row) && count($row)===count($wanted),'new platform grant role has exactly its installed fields');
    foreach ($wanted as $field=>$value) audit_expect(m6h_value($row[$field] ?? null,$value),'new positive grant role contains only its intended enabled level and real menu IDs');
    m6h_same($before,m6h_snapshot(),['eb_system_role'],[],['eb_system_role']);
    $evidence['positive_platform_grants']=['role_id'=>$id,'menu_ids'=>$ids,'level'=>1,'status'=>1,'source'=>'new enabled role with actual current seven auth_type=2 menu IDs']; return $id;
}

function m6h_reduce_role(array $subject,array $binding,string $v2,array &$evidence): void
{
    $before=m6h_snapshot(); $state=m6h_identity_rows(); $role=$state['role'][$subject['role_id']];
    audit_expect(json_decode($role['permissions'],true,32,JSON_THROW_ON_ERROR)===\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN,'normal role reduction starts from current exact full-nine role');
    $permissions=array_values(array_diff(\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN,['freight.manage']));
    audit_expect(count($permissions)===8 && in_array('merchant.profile.read',$permissions,true) && in_array('merchant.employee.manage',$permissions,true)
        && in_array('merchant.role.manage',$permissions,true),'normal reduced role retains every management and profile grant');
    $ids=[]; foreach ($state['employee'] as $id=>$row) if ((int)$row['mer_id']===$subject['mer_id'] && (int)$row['role_id']===$subject['role_id']) $ids[]=$id;
    sort($ids,SORT_NUMERIC); audit_expect(count($ids)===2,'original A role has its two actual members before fallback'); $prior=m6h_accounts($v2,$ids); $start=time();
    $data=m6h_http('PUT','/merchantapi/roles/'.$subject['role_id'],$binding['token'],['permissions'=>$permissions],$evidence); $end=time(); $after=m6h_identity_rows(); $row=$after['role'][$subject['role_id']];
    audit_expect(json_decode($row['permissions'],true,32,JSON_THROW_ON_ERROR)===$permissions && (int)$row['update_time']>=$start && (int)$row['update_time']<=$end,'actual normal role write stores current eight permissions and server time');
    $expected=$state; $expected['role'][$subject['role_id']]['permissions']=$row['permissions']; $expected['role'][$subject['role_id']]['update_time']=$row['update_time'];
    audit_expect($after===$expected,'normal role reduction preserves all other rows, profile fields and prior platform audits');
    $fields=['id','mer_id','name','permissions','add_time','update_time']; $keys=array_keys($data); $wanted=$fields; sort($keys); sort($wanted); audit_expect($keys===$wanted,'normal role response has exactly its public six fields');
    foreach ($fields as $field) {
        $value=in_array($field,['id','mer_id','add_time','update_time'],true) ? (int)$row[$field] : $row[$field];
        audit_expect($field==='permissions' ? $data[$field]===$permissions : m6h_value($data[$field],$value),'normal role DTO agrees with current reduced database row');
    }
    m6h_rotated($v2,$prior,$evidence,'normal_full_nine_role_reduced_before_administrator_fallback');
    m6h_same($before,m6h_snapshot(),['eb_marketplace_role'],array_map(static fn(int $id): string=>$v2.'account:'.$id,$ids));
    $evidence['normal_role_reduction']=['role_id'=>$subject['role_id'],'mer_id'=>$subject['mer_id'],'current_permission_count'=>8,'protected_employee_ids'=>$ids,
        'all_management_and_profile_grants_retained'=>true,'old_token_requests_after_change'=>0];
}

function m6h_log_rows(): array
{
    $rows=[]; foreach (audit_db()->query('SELECT * FROM eb_system_log ORDER BY id')->fetchAll() as $row) $rows[(int)$row['id']]=hash('sha256',serialize($row)); return $rows;
}

function m6h_business_equal(array $before,array $after,array $expected): void
{
    unset($before['audit_log'],$after['audit_log'],$expected['audit_log']);
    foreach ($expected as &$rows) ksort($rows,SORT_NUMERIC); unset($rows);
    audit_expect($after===$expected,'platform operation changed another identity row or non-whitelisted column');
}

function m6h_merchant_dto(array $data,array $row): void
{
    $fields=['mer_id','name','logo','after_sale_contact','shipping_note','review_status','accept_orders','fulfillment_enabled','security_locked','closed_at','add_time','update_time'];
    $keys=array_keys($data); $wanted=$fields; sort($keys); sort($wanted); audit_expect($keys===$wanted,'platform merchant DTO contains exactly its twelve frozen fields');
    foreach ($fields as $field) {
        $value=in_array($field,['mer_id','review_status','accept_orders','fulfillment_enabled','security_locked','add_time','update_time'],true) ? (int)$row[$field] : $row[$field];
        if ($field==='closed_at' && $value!==null) $value=(int)$value;
        audit_expect($data[$field]===$value,'platform merchant DTO preserves its exact current value and type');
    }
}

function m6h_platform(string $method,string $path,array $admin,?array $body,string $action,?int $merId,array|Closure $detail,array $changedTables,array $changedKeys,
    array $cacheKeys,array &$owners,array &$secrets,array &$evidence): array
{
    $before=m6h_snapshot(); $state=m6h_identity_rows(); $logs=m6h_log_rows(); $start=time();
    $data=m6h_http($method,$path,$admin['token'],$body,$evidence,true); $end=time(); $after=m6h_identity_rows(); $nextLogs=m6h_log_rows();
    foreach ($cacheKeys as $key) audit_expect(audit_redis()->exists($key)===0,'successful current platform authorization clears only its precise two permission cache keys');
    if ($method==='GET') {
        audit_expect($after===$state,'platform read preserves all five identity tables');
        $added=array_values(array_diff(array_keys($nextLogs),array_keys($logs))); audit_expect(count($added)===1,'normal platform read writes exactly one real legacy request-log row'); $id=$added[0];
        $stmt=audit_db()->prepare('SELECT * FROM eb_system_log WHERE id=? AND admin_id=?'); $stmt->execute([$id,$admin['id']]); $row=$stmt->fetch();
        audit_expect(is_array($row),'actual platform read log belongs to the synthetic current administrator');
        $owners['system_log'][$id]=['id'=>$id,'admin_id'=>$admin['id'],'admin_name'=>$admin['account'],'path'=>$row['path'],'type'=>$row['type']];
        $rule=$action==='marketplace-merchants-list' ? 'marketplace/merchants' : 'marketplace/merchants/<id>';
        $page=app()->make(\app\services\system\SystemMenusServices::class)->getVisitName($rule) ?: '未知';
        $expected=['id'=>$id,'admin_id'=>$admin['id'],'admin_name'=>$admin['account'],'path'=>$rule,'page'=>$page,'method'=>'adminapi','ip'=>gethostbyname(gethostname()),'type'=>'system','merchant_id'=>0];
        $rowKeys=array_keys($row); $expectedKeys=array_merge(array_keys($expected),['add_time']); sort($rowKeys); sort($expectedKeys); audit_expect($rowKeys===$expectedKeys,'normal platform request log has complete legacy metadata');
        foreach ($expected as $field=>$value) audit_expect(m6h_value($row[$field] ?? null,$value),'real platform request log metadata differs from normal current request');
        audit_expect((int)$row['add_time']>=$start && (int)$row['add_time']<=$end,'real platform request log has server time');
        $expectedLogs=$logs; $expectedLogs[$id]=$nextLogs[$id]; ksort($expectedLogs,SORT_NUMERIC); audit_expect($nextLogs===$expectedLogs,'normal platform read changed another original log row');
        $evidence['platform_read_logs'][]=['id'=>$id,'admin_id'=>$admin['id'],'action'=>$action,'route'=>$rule,'complete_metadata_verified'=>true];
        m6h_same($before,m6h_snapshot(),['eb_system_log'],$cacheKeys,['eb_system_log']);
    } else {
        audit_expect($nextLogs===$logs,'platform merchant writes preserve the original system request-log rows');
        $added=array_values(array_diff(array_keys($after['audit_log']),array_keys($state['audit_log']))); audit_expect(count($added)===1,'each successful platform write has exactly one actual merchant audit'); $id=$added[0]; $row=$after['audit_log'][$id];
        if ($merId===null) $merId=is_int($data['mer_id'] ?? null) ? $data['mer_id'] : 0;
        $resource=$action==='marketplace-merchants-admin-employee' ? 'employee' : 'merchant'; $resourceId=$resource==='employee' ? ($data['employee']['id'] ?? 0) : $merId;
        audit_expect($merId>0 && is_int($resourceId) && $resourceId>0,'actual platform audit refers to its real positive resource');
        $wantedDetail=$detail instanceof Closure ? $detail($data,$after) : $detail;
        $expected=['id'=>$id,'mer_id'=>$merId,'actor_type'=>'admin','actor_id'=>$admin['id'],'action'=>$action,'resource_type'=>$resource,'resource_id'=>(string)$resourceId,'result'=>'success'];
        $keys=array_keys($row); $wanted=array_merge(array_keys($expected),['detail','add_time']); sort($keys); sort($wanted); audit_expect($keys===$wanted,'merchant audit has all ten frozen columns');
        foreach ($expected as $field=>$value) audit_expect(m6h_value($row[$field] ?? null,$value),'actual merchant audit attribution or action differs');
        $object=json_decode($row['detail'],false,32,JSON_THROW_ON_ERROR); audit_expect($object instanceof stdClass,'merchant audit detail is a JSON object');
        $actualDetail=get_object_vars($object); $aKeys=array_keys($actualDetail); $eKeys=array_keys($wantedDetail); sort($aKeys); sort($eKeys);
        audit_expect($aKeys===$eKeys,'merchant audit includes only its exact server metadata');
        foreach ($wantedDetail as $field=>$value) audit_expect($actualDetail[$field]===$value,'merchant audit detail differs from exact normal changes');
        audit_expect((int)$row['add_time']>=$start && (int)$row['add_time']<=$end,'actual merchant audit has server time');
        $expectedAudits=$state['audit_log']; $expectedAudits[$id]=$row; ksort($expectedAudits,SORT_NUMERIC); audit_expect($after['audit_log']===$expectedAudits,'normal write modified an earlier audit row');
        $owners['audit_log'][$id]=['id'=>$id,'mer_id'=>$merId,'actor_id'=>$admin['id'],'action'=>$action,'resource_type'=>$resource,'resource_id'=>(string)$resourceId];
        $evidence['merchant_audits'][]=['id'=>$id,'mer_id'=>$merId,'actor_id'=>$admin['id'],'action'=>$action,'resource_type'=>$resource,'resource_id'=>$resourceId,'detail'=>$wantedDetail,'complete_metadata_verified'=>true];
        $insertTables=match($action) {
            'marketplace-merchants-create'=>['eb_marketplace_merchant','eb_marketplace_role'],
            'marketplace-merchants-admin-employee'=>array_values(array_intersect($changedTables,['eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup'])),
            default=>[],
        };
        m6h_same($before,m6h_snapshot(),array_merge($changedTables,['eb_marketplace_audit_log']),array_merge($changedKeys,$cacheKeys),array_merge($insertTables,['eb_marketplace_audit_log']));
    }
    return ['data'=>$data,'before'=>$state,'after'=>$after,'start'=>$start,'end'=>$end];
}

function m6h_create_merchant(string $name,array $admin,array $cacheKeys,array &$owners,array &$secrets,array &$evidence): array
{
    $secrets[]=$name;
    $r=m6h_platform('POST','/adminapi/marketplace/merchants',$admin,['name'=>'  '.$name.'  '],'marketplace-merchants-create',null,
        ['changed_fields'=>['name','logo','after_sale_contact','shipping_note','review_status','accept_orders','fulfillment_enabled','security_locked','closed_at'],'protected_employee_count'=>0],
        ['eb_marketplace_merchant','eb_marketplace_role'],[],$cacheKeys,$owners,$secrets,$evidence);
    $data=$r['data']; audit_expect(is_int($data['mer_id'] ?? null) && $data['mer_id']>0,'real platform create returns its own merchant identity'); $id=$data['mer_id'];
    audit_expect(isset($r['after']['merchant'][$id]) && !isset($r['before']['merchant'][$id]),'actual created merchant row was absent before'); $row=$r['after']['merchant'][$id];
    $owners['merchant'][$id]=['id'=>$id,'names'=>[$name]];
    $fields=['mer_id'=>$id,'name'=>$name,'logo'=>'','after_sale_contact'=>'','shipping_note'=>'','review_status'=>0,'accept_orders'=>0,'fulfillment_enabled'=>1,'security_locked'=>0,'closed_at'=>null];
    $keys=array_keys($row); $wanted=array_merge(array_keys($fields),['add_time','update_time']); sort($keys); sort($wanted); audit_expect($keys===$wanted,'normal created merchant has the frozen twelve columns');
    foreach ($fields as $field=>$value) audit_expect(m6h_value($row[$field] ?? null,$value),'created merchant has exact trimmed profile and server defaults');
    audit_expect((int)$row['add_time']>=$r['start'] && (int)$row['add_time']<=$r['end'] && $row['update_time']===$row['add_time'],'created merchant times are the actual single server time');
    m6h_merchant_dto($data,$row); $roles=array_filter($r['after']['role'],static fn(array $role): bool=>(int)$role['mer_id']===$id);
    audit_expect(count($roles)===2,'merchant creation produces exactly two actual local default roles'); $names=[];
    foreach ($roles as $roleId=>$role) {
        $owners['role'][$roleId]=['id'=>$roleId,'mer_id'=>$id,'names'=>[$role['name']]];
        $roleKeys=array_keys($role); $wanted=['id','mer_id','name','permissions','add_time','update_time']; sort($roleKeys); sort($wanted); audit_expect($roleKeys===$wanted,'default role has exactly its frozen columns');
        audit_expect(in_array($role['name'],['管理员','运营'],true) && !isset($names[$role['name']]),'actual local role name is one distinct frozen default');
        $permissions=$role['name']==='管理员' ? \app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN : \app\services\marketplace\MerchantPermissions::BUILTIN_OPERATOR;
        audit_expect((int)$role['id']===$roleId && json_decode($role['permissions'],true,32,JSON_THROW_ON_ERROR)===$permissions
            && $role['add_time']===$row['add_time'] && $role['update_time']===$row['update_time'],'default role membership permissions and server times agree'); $names[$role['name']]=$roleId;
    }
    $expected=$r['before']; $expected['merchant'][$id]=$row; foreach ($roles as $roleId=>$role) $expected['role'][$roleId]=$role;
    m6h_business_equal($r['before'],$r['after'],$expected);
    $evidence['default_roles'][]=['mer_id'=>$id,'administrator_role_id'=>$names['管理员'],'operator_role_id'=>$names['运营'],'administrator_permissions'=>9,'operator_permissions'=>7,'actual_local_rows_verified'=>true];
    return ['mer_id'=>$id,'name'=>$name,'admin_role_id'=>$names['管理员'],'operator_role_id'=>$names['运营']];
}

function m6h_modify(int $merId,string $kind,array $body,array $admin,string $v2,array $cacheKeys,array &$owners,array &$secrets,array &$evidence): array
{
    $before=m6h_identity_rows(); $merchant=$before['merchant'][$merId]; $order=match($kind) {
        'profile'=>['name','logo','after_sale_contact','shipping_note'],'review'=>['review_status'],'flags'=>['accept_orders','fulfillment_enabled','security_locked'],
    }; $changes=[];
    foreach ($order as $field) if (array_key_exists($field,$body)) {
        $value=$field==='name' ? trim($body[$field]) : $body[$field];
        if (!m6h_value($merchant[$field],$value)) $changes[$field]=$value;
        if (is_string($value) && $value!=='') $secrets[]=$value;
    }
    if (isset($body['name'])) $owners['merchant'][$merId]['names'][]=trim($body['name']);
    $protect=array_intersect(array_keys($changes),['review_status','security_locked'])!==[]; $ids=[];
    if ($protect) foreach ($before['employee'] as $id=>$row) if ((int)$row['mer_id']===$merId) $ids[]=$id;
    sort($ids,SORT_NUMERIC); $prior=$ids ? m6h_accounts($v2,$ids) : [];
    $keys=array_map(static fn(int $id): string=>$v2.'account:'.$id,$ids); $suffix=$kind==='profile' ? '' : '/'.$kind; $action='marketplace-merchants-'.($kind==='profile' ? 'update' : $kind);
    $r=m6h_platform('PUT','/adminapi/marketplace/merchants/'.$merId.$suffix,$admin,$body,$action,$merId,
        ['changed_fields'=>array_keys($changes),'protected_employee_count'=>count($ids)],['eb_marketplace_merchant'],$keys,$cacheKeys,$owners,$secrets,$evidence);
    audit_expect($r['before']===$before,'normal modify started from the same current five-table source'); $after=$r['after']['merchant'][$merId]; $expected=$before;
    foreach ($changes as $field=>$value) { audit_expect(m6h_value($after[$field],$value),'actual merchant changed field equals its intended valid value'); $expected['merchant'][$merId][$field]=$after[$field]; }
    if ($changes) { audit_expect((int)$after['update_time']>=$r['start'] && (int)$after['update_time']<=$r['end'],'actual merchant update has server time'); $expected['merchant'][$merId]['update_time']=$after['update_time']; }
    m6h_business_equal($before,$r['after'],$expected); m6h_merchant_dto($r['data'],$after);
    if ($ids) m6h_rotated($v2,$prior,$evidence,'normal_platform_'.$kind.'_eligibility_change');
    if (!$changes) $evidence['valid_noops'][]=['resource'=>'merchant_'.$kind,'mer_id'=>$merId,'rows_times_and_versions_unchanged'=>true,'success_audit_inserted'=>true];
    return $r['data'];
}

function m6h_read(int $merId,array $admin,array $cacheKeys,array &$owners,array &$secrets,array &$evidence): array
{
    $r=m6h_platform('GET','/adminapi/marketplace/merchants/'.$merId,$admin,null,'marketplace-merchants-read',$merId,[],[],[],$cacheKeys,$owners,$secrets,$evidence);
    m6h_merchant_dto($r['data'],$r['after']['merchant'][$merId]); return $r['data'];
}

function m6h_list(array $admin,array $cacheKeys,array &$owners,array &$secrets,array &$evidence,int $page=1,int $limit=20,?int $review=null,bool $defaults=false): void
{
    $path='/adminapi/marketplace/merchants'; $query=['page'=>$page,'limit'=>$limit]; if ($review!==null) $query['review_status']=$review;
    if (!$defaults) $path.='?'.http_build_query($query);
    $r=m6h_platform('GET',$path,$admin,null,'marketplace-merchants-list',null,[],[],[],$cacheKeys,$owners,$secrets,$evidence); $data=$r['data']; $keys=array_keys($data); sort($keys);
    audit_expect($keys===['count','list'] && is_int($data['count']) && is_array($data['list']) && array_is_list($data['list']),'platform list contains exactly typed list/count');
    $matching=array_values(array_filter($r['after']['merchant'],static fn(array $row): bool=>$review===null || (int)$row['review_status']===$review));
    $rows=array_slice($matching,($page-1)*$limit,$limit); audit_expect($data['count']===count($matching) && count($data['list'])===count($rows),'real platform global list pagination and filter count differ');
    foreach ($rows as $index=>$row) m6h_merchant_dto($data['list'][$index],$row);
    $evidence['platform_list_observations'][]=['page'=>$page,'limit'=>$limit,'review_status'=>$review,'count'=>$data['count'],'returned_ids'=>array_map(static fn(array $row): int=>(int)$row['mer_id'],$rows),'explicit_defaults_verified'=>$defaults];
}

function m6h_create_employee(array $merchant,string $label,string $slug,array $admin,string $v2,array $cacheKeys,array &$owners,array &$secrets,array &$evidence,bool $fallback=false): array
{
    $account=$slug.'_'.strtolower($label); $name='Platform '.$label; $avatar='/statics/ms1-platform-'.$label.'.png';
    $secrets=array_merge($secrets,[$account,$name,$avatar]);
    $details=static fn(array $data,array $state): array=>['changed_fields'=>['role_id','account','name','avatar','status'],
        'employee_id'=>$data['employee']['id'] ?? 0,'role_id'=>$data['employee']['role_id'] ?? 0,'protected_employee_count'=>0];
    $r=m6h_platform('POST','/adminapi/marketplace/merchants/'.$merchant['mer_id'].'/admin-employee',$admin,
        ['account'=>'  '.strtoupper($account).'  ','name'=>$name,'avatar'=>$avatar],'marketplace-merchants-admin-employee',$merchant['mer_id'],$details,
        array_merge(['eb_marketplace_employee','eb_marketplace_password_setup'],$fallback ? ['eb_marketplace_role'] : []),[],$cacheKeys,$owners,$secrets,$evidence); $data=$r['data'];
    $keys=array_keys($data); sort($keys); audit_expect($keys===['employee','password_setup'] && is_array($data['employee']) && is_array($data['password_setup']),'normal platform administrator creation delivers its exact employee and capability DTO');
    $id=$data['employee']['id'] ?? null; audit_expect(is_int($id) && $id>0 && isset($r['after']['employee'][$id]) && !isset($r['before']['employee'][$id]),'created administrator has its actual new employee ID');
    $owners['employee'][$id]=['id'=>$id,'mer_id'=>$merchant['mer_id'],'accounts'=>[$account]]; $row=$r['after']['employee'][$id];
    $roleId=$fallback ? ($data['employee']['role_id'] ?? null) : $merchant['admin_role_id']; $roleName=$fallback ? '管理员 2' : '管理员';
    audit_expect(is_int($roleId) && $roleId>0,'created administrator returns its real selected role identity');
    if ($fallback) {
        $oldPermissions=json_decode($r['before']['role'][$merchant['admin_role_id']]['permissions'],true,32,JSON_THROW_ON_ERROR);
        audit_expect(count($oldPermissions)===8 && !in_array('freight.manage',$oldPermissions,true)
            && $r['before']['role'][$merchant['admin_role_id']]['name']==='管理员','the original local name remains occupied by its preserved eight-permission role');
        foreach ($r['before']['role'] as $role) if ((int)$role['mer_id']===$merchant['mer_id']) {
            $actual=json_decode($role['permissions'],true,32,JSON_THROW_ON_ERROR); $wanted=\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN; sort($actual); sort($wanted);
            audit_expect($actual!==$wanted,'normal fallback source has no local exact full-nine role');
        }
        audit_expect(!isset($r['before']['role'][$roleId]) && isset($r['after']['role'][$roleId]),'normal fallback creates an actual new local role'); $newRole=$r['after']['role'][$roleId];
        $owners['role'][$roleId]=['id'=>$roleId,'mer_id'=>$merchant['mer_id'],'names'=>[$roleName]];
        $roleKeys=array_keys($newRole); $wanted=['id','mer_id','name','permissions','add_time','update_time']; sort($roleKeys); sort($wanted);
        audit_expect($roleKeys===$wanted && (int)$newRole['id']===$roleId && (int)$newRole['mer_id']===$merchant['mer_id'] && $newRole['name']===$roleName
            && json_decode($newRole['permissions'],true,32,JSON_THROW_ON_ERROR)===\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN
            && (int)$newRole['add_time']>=$r['start'] && (int)$newRole['add_time']<=$r['end'] && $newRole['update_time']===$newRole['add_time'],'normal fallback selects the next available administrator name with all nine permissions');
    }
    $fields=['id'=>$id,'mer_id'=>$merchant['mer_id'],'role_id'=>$roleId,'account'=>$account,'pwd'=>null,'name'=>$name,'avatar'=>$avatar,'status'=>1,'is_del'=>0];
    $keys=array_keys($row); $wanted=array_merge(array_keys($fields),['add_time','update_time']); sort($keys); sort($wanted); audit_expect($keys===$wanted,'created administrator has exactly the frozen private database fields');
    foreach ($fields as $field=>$value) audit_expect(m6h_value($row[$field] ?? null,$value),'normal administrator selects its contracted current full-nine local role and normalized account');
    audit_expect((int)$row['add_time']>=$r['start'] && (int)$row['add_time']<=$r['end'] && $row['update_time']===$row['add_time'],'created administrator has single current server time'); m6h_employee_dto($data['employee'],$row);
    $delivery=$data['password_setup']; $keys=array_keys($delivery); sort($keys); audit_expect($keys===['endpoint','expires_at','token'] && $delivery['endpoint']==='/merchantapi/password/setup'
        && is_string($delivery['token']) && is_int($delivery['expires_at']),'platform capability contains only its normal endpoint token and absolute expiry'); m6h_valid_capability($delivery['token']);
    $secrets=array_merge($secrets,[$delivery['token'],hash('sha256',$delivery['token']),hash('sha256',$delivery['token'],true)]);
    $setups=array_filter($r['after']['password_setup'],static fn(array $row): bool=>(int)$row['mer_id']===$merchant['mer_id'] && (int)$row['employee_id']===$id);
    audit_expect(count($setups)===1,'created administrator has exactly one local setup record'); $setupId=array_key_first($setups); $setup=$setups[$setupId];
    $owners['password_setup'][$setupId]=['id'=>$setupId,'mer_id'=>$merchant['mer_id'],'employee_id'=>$id,'token_hash'=>$setup['token_hash']];
    $keys=array_keys($setup); $wanted=['id','mer_id','employee_id','token_hash','expires_at','consumed_at','add_time']; sort($keys); sort($wanted); audit_expect($keys===$wanted,'normal administrator setup has exactly its seven private columns');
    audit_expect((int)$setup['id']===$setupId && $setup['token_hash']===hash('sha256',$delivery['token'],true) && $setup['consumed_at']===null
        && $setup['add_time']===$row['add_time'] && (int)$setup['expires_at']===(int)$setup['add_time']+86400 && (int)$setup['expires_at']===$delivery['expires_at'],'actual setup matches32-byte digest and24h expiry');
    $expected=$r['before']; $expected['employee'][$id]=$row; $expected['password_setup'][$setupId]=$setup;
    if ($fallback) { audit_expect($newRole['add_time']===$row['add_time'],'normal fallback role and employee are created in the same actual server time'); $expected['role'][$roleId]=$newRole; }
    m6h_business_equal($r['before'],$r['after'],$expected);
    audit_expect(audit_redis()->exists($v2.'account:'.$id)===0,'platform employee creation does not preinitialize its version');
    $password='M6h-'.bin2hex(random_bytes(24)).'-x'; $secrets[]=$password;
    $evidence['administrator_deliveries'][]=['mer_id'=>$merchant['mer_id'],'employee_id'=>$id,'role_id'=>$roleId,'setup_id'=>$setupId,'canonical_hash_and24h_verified'=>true,'preinitialized_version'=>false,
        'administrator_role_fallback_created'=>$fallback,'original_role_preserved'=>true];
    return ['label'=>$label,'mer_id'=>$merchant['mer_id'],'merchant_name'=>$merchant['name'],'role_id'=>$roleId,'role_name'=>$roleName,
        'employee_id'=>$id,'setup_id'=>$setupId,'account'=>$account,'name'=>$name,'avatar'=>$avatar,'permissions'=>\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN,'cap'=>$delivery['token'],'password'=>$password];
}

function m6h_discover(PDO $pdo,array $plannedMerchants,array $plannedEmployees,array $admin,array &$owners): void
{
    foreach ($plannedMerchants as $names) foreach ($names as $name) {
        $stmt=$pdo->prepare('SELECT mer_id,name FROM eb_marketplace_merchant WHERE name=?'); $stmt->execute([$name]);
        foreach ($stmt->fetchAll() as $row) { $id=(int)$row['mer_id']; $owners['merchant'][$id] ??= ['id'=>$id,'names'=>[]]; $owners['merchant'][$id]['names']=array_values(array_unique(array_merge($owners['merchant'][$id]['names'],$names))); }
    }
    foreach ($owners['merchant'] as $merchant) {
        $stmt=$pdo->prepare('SELECT id,name FROM eb_marketplace_role WHERE mer_id=?'); $stmt->execute([$merchant['id']]);
        foreach ($stmt->fetchAll() as $row) if (in_array($row['name'],['管理员','运营','管理员 2'],true)) $owners['role'][(int)$row['id']]=['id'=>(int)$row['id'],'mer_id'=>$merchant['id'],'names'=>[$row['name']]];
        foreach ($plannedEmployees as $plan) if (in_array($merchant['id'],$plan['mer_ids'],true)) foreach ($plan['accounts'] as $account) {
            $stmt=$pdo->prepare('SELECT id FROM eb_marketplace_employee WHERE mer_id=? AND account=?'); $stmt->execute([$merchant['id'],$account]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $owners['employee'][(int)$id]=['id'=>(int)$id,'mer_id'=>$merchant['id'],'accounts'=>$plan['accounts']];
        }
        if (isset($admin['id'])) {
            $stmt=$pdo->prepare('SELECT id,action,resource_type,resource_id FROM eb_marketplace_audit_log WHERE mer_id=? AND actor_type=? AND actor_id=?'); $stmt->execute([$merchant['id'],'admin',$admin['id']]);
            foreach ($stmt->fetchAll() as $row) {
                if (!in_array($row['action'],array_column(m6h_platform_rules(),2),true)) continue;
                $owners['audit_log'][(int)$row['id']]=['id'=>(int)$row['id'],'mer_id'=>$merchant['id'],'actor_id'=>$admin['id'],'action'=>$row['action'],'resource_type'=>$row['resource_type'],'resource_id'=>$row['resource_id']];
            }
        }
    }
    foreach ($owners['employee'] as $employee) {
        $stmt=$pdo->prepare('SELECT id,token_hash FROM eb_marketplace_password_setup WHERE mer_id=? AND employee_id=?'); $stmt->execute([$employee['mer_id'],$employee['id']]);
        foreach ($stmt->fetchAll() as $row) $owners['password_setup'][(int)$row['id']]=['id'=>(int)$row['id'],'mer_id'=>$employee['mer_id'],'employee_id'=>$employee['id'],'token_hash'=>$row['token_hash']];
    }
    foreach ($owners['system_admin'] as $ownedAdmin) {
        $stmt=$pdo->prepare('SELECT id,admin_name,path,type FROM eb_system_log WHERE admin_id=? AND admin_name=?'); $stmt->execute([$ownedAdmin['id'],$ownedAdmin['account']]);
        foreach ($stmt->fetchAll() as $row) if (in_array($row['path'],['marketplace/merchants','marketplace/merchants/<id>'],true) && $row['type']==='system')
            $owners['system_log'][(int)$row['id']]=['id'=>(int)$row['id'],'admin_id'=>$ownedAdmin['id'],'admin_name'=>$row['admin_name'],'path'=>$row['path'],'type'=>$row['type']];
    }
}

function m6h_session_cleanup(string $v2,string $app,array $ids,array &$ownedKeys): void
{
    $ids=array_map('strval',$ids); foreach ($ids as $id) $ownedKeys[]=$v2.'account:'.$id;
    foreach (m6h_scan($v2.'session:*') as $key) {
        if (preg_match('/^'.preg_quote($v2.'session:','/').'[a-f0-9]{32}$/D',$key)!==1 || audit_redis()->type($key)!==Redis::REDIS_HASH) continue;
        $row=audit_redis()->hGetAll($key); $type=$app==='adminapi' ? 'admin' : 'merchant'; $audience=$app==='adminapi' ? 'adminapi' : 'tianli:merchantapi';
        if (in_array($row['subject_id'] ?? '',$ids,true) && ($row['type'] ?? '')===$type && ($row['audience'] ?? '')===$audience) $ownedKeys[]=$key;
    }
}

function m6h_cleanup(PDO $pdo,array $owners,array $ownedKeys): int
{
    foreach (['audit_log','password_setup','employee','role','merchant','system_log','system_admin','system_role'] as $table) foreach (array_reverse($owners[$table],true) as $owner) {
        if ($table==='audit_log') { $sql='DELETE FROM eb_marketplace_audit_log WHERE id=? AND mer_id=? AND actor_type=? AND actor_id=? AND action=? AND resource_type=? AND resource_id=?'; $args=[$owner['id'],$owner['mer_id'],'admin',$owner['actor_id'],$owner['action'],$owner['resource_type'],$owner['resource_id']]; }
        elseif ($table==='password_setup') { $sql='DELETE FROM eb_marketplace_password_setup WHERE id=? AND mer_id=? AND employee_id=? AND token_hash=?'; $args=[$owner['id'],$owner['mer_id'],$owner['employee_id'],$owner['token_hash']]; }
        elseif ($table==='merchant') { $sql='DELETE FROM eb_marketplace_merchant WHERE mer_id=? AND name IN ('.implode(',',array_fill(0,count($owner['names']),'?')).')'; $args=array_merge([$owner['id']],$owner['names']); }
        elseif ($table==='system_log') { $sql='DELETE FROM eb_system_log WHERE id=? AND admin_id=? AND admin_name=? AND path=? AND type=?'; $args=[$owner['id'],$owner['admin_id'],$owner['admin_name'],$owner['path'],$owner['type']]; }
        elseif ($table==='system_admin') { $sql='DELETE FROM eb_system_admin WHERE id=? AND account=? AND real_name=? AND pwd=? AND roles=? AND level=?'; $args=[$owner['id'],$owner['account'],$owner['real_name'],$owner['pwd'],$owner['roles'],$owner['level']]; }
        elseif ($table==='system_role') { $sql='DELETE FROM eb_system_role WHERE id=? AND role_name=? AND rules=? AND level=? AND status=?'; $args=[$owner['id'],$owner['role_name'],$owner['rules'],$owner['level'],$owner['status']]; }
        else { $column=$table==='employee' ? 'account' : 'name'; $values=$owner[$table==='employee' ? 'accounts' : 'names'];
            $sql='DELETE FROM eb_marketplace_'.$table.' WHERE id=? AND mer_id=? AND `'.$column.'` IN ('.implode(',',array_fill(0,count($values),'?')).')'; $args=array_merge([$owner['id'],$owner['mer_id']],$values); }
        $stmt=$pdo->prepare($sql); $stmt->execute($args); audit_expect($stmt->rowCount()===1,'precise owned row cleanup removes its actual single record');
    }
    $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0;
    audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys),'precise cache cleanup receives its native removed count');
    foreach ($keys as $key) audit_expect(audit_redis()->exists($key)===0,'precise owned cache cleanup completes'); return $removed;
}

audit_case('T6P01','normal_platform_merchant_management','INTEGRATION',static function(): array {
    $evidence=['run_id'=>getenv('AUDIT_RUN_ID'),'requests'=>[],'negative_cases_run'=>false,'old_token_requests'=>0,
        'unapproved_merchant_requests'=>0,'locked_merchant_requests'=>0,'browser_acceptance'=>false,
        'guard_mode'=>audit_manifest()['guard_mode'] ?? 'active-probe','application_privilege_ddl_probe'=>'NOT RUN'];
    $owners=['merchant'=>[],'role'=>[],'employee'=>[],'password_setup'=>[],'audit_log'=>[],'system_admin'=>[],'system_log'=>[],'system_role'=>[]];
    $subjects=[]; $admin=[]; $ownedKeys=[]; $secrets=[]; $plannedMerchants=[]; $plannedEmployees=[];
    $failure=null; $cleanupFailure=null; $pdo=null; $v2=null; $adminV2=null; $started=false;
    $counterTables=['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup','eb_marketplace_audit_log','eb_system_admin','eb_system_log','eb_system_role'];
    try {
        audit_expect($evidence['guard_mode']==='read-only','ordinary Task6 requires explicitly read-only isolation protection');
        $initial=m6h_snapshot(); $evidence['before']=m6h_public_snapshot($initial);
        // Missing implementation is observed before any business request or fixture and before five-table preparation assertions.
        foreach (['crmeb/app/adminapi/route/marketplace.php','crmeb/app/adminapi/controller/v1/marketplace/Merchant.php',
            'crmeb/app/services/marketplace/MerchantAdminServices.php','crmeb/app/services/marketplace/AuditLogServices.php','crmeb/app/services/marketplace/PlatformAccessServices.php',
            'crmeb/app/adminapi/middleware/MarketplaceBoundaryMiddleware.php','crmeb/app/adminapi/middleware/MarketplaceScopeMiddleware.php'] as $file)
            audit_expect(is_file(audit_repo_dir().'/'.$file),'Task6 platform implementation unavailable');
        audit_expect(count($initial['names'])===163,'normal platform management requires installed base plus five frozen identity tables');
        foreach (['merchant','role','employee','password_setup','audit_log'] as $table) audit_expect(($initial['rows']['eb_marketplace_'.$table]['count'] ?? -1)===0,'all five identity tables start empty');
        $config=audit_config(); $secrets=[$config['signing_key'],$config['auth']['stamp_key'],$config['redis_password'],$config['database']['password']];
        $prefix='audit:http-lang:'.getenv('AUDIT_RUN_ID').':'.gmdate('YmdHis').random_int(100000,999999).':';
        $v2='auth:v2:'.$config['auth']['session_epoch'].':merchantapi:'; $adminV2='auth:v2:'.$config['auth']['session_epoch'].':adminapi:';
        audit_expect(m6h_scan($prefix.'*')===[] && m6h_scan($v2.'*')===[],'new ordinary language and merchant session namespaces begin empty');
        $app=m6h_app($config,$prefix); m6h_routes($app,$evidence); m6h_warm_language($app,$prefix,$ownedKeys,$evidence);
        $pdo=audit_db(); $slug='ms1_platform_'.substr(hash('sha256',(string)getenv('AUDIT_RUN_ID')),0,12);
        $admin=m6h_admin($pdo,$slug,$config,$adminV2,$owners,$ownedKeys,$secrets,$evidence);
        $cacheKeys=m6h_permission_cache($app,$prefix,$ownedKeys,$evidence); $started=true; audit_app_request('start',['CACHE_PREFIX'=>$prefix]);
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,20,null,true);
        foreach (['A','B'] as $label) {
            $name=$slug.'_'.$label; $plannedMerchants[$label]=[$name];
            $merchants[$label]=m6h_create_merchant($name,$admin,$cacheKeys,$owners,$secrets,$evidence);
        }
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,20,null,true);
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,1);
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,2,1);
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,20,0);
        foreach (['A','B'] as $label) m6h_modify($merchants[$label]['mer_id'],'review',['review_status'=>1],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        foreach (['A1'=>'A','A2'=>'A','B1'=>'B'] as $label=>$merchantLabel) {
            $plannedEmployees[]=['mer_ids'=>[$merchants[$merchantLabel]['mer_id']],'accounts'=>[$slug.'_'.strtolower($label)]];
            $subjects[$label]=m6h_create_employee($merchants[$merchantLabel],$label,$slug,$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        }
        $state=m6h_identity_rows(); $bindings=[];
        foreach ($subjects as $label=>$subject) {
            m6h_setup($subject,$state,$config,$v2,$ownedKeys,$secrets,$evidence);
            $bindings[$label]=m6h_login($subject,$config,$v2,$ownedKeys,$secrets,$evidence,$app);
            m6h_me($subject,$bindings[$label],$evidence);
        }
        audit_expect(m6h_identity_rows()===$state,'actual normal administrator preparation preserves all other identities and audit rows');
        foreach (['A','B'] as $label) m6h_read($merchants[$label]['mer_id'],$admin,$cacheKeys,$owners,$secrets,$evidence);

        $aId=$merchants['A']['mer_id']; $bId=$merchants['B']['mer_id']; $profile=['name'=>$slug.'_A_updated','logo'=>'/statics/ms1-platform-merchant.png',
            'after_sale_contact'=>'Synthetic service contact','shipping_note'=>'Synthetic shipping note'];
        $plannedMerchants['A'][]=$profile['name'];
        m6h_modify($aId,'profile',$profile,$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        foreach (['A1','A2'] as $label) {
            $subjects[$label]['merchant_name']=$profile['name']; foreach (['logo','after_sale_contact','shipping_note'] as $field) $subjects[$label][$field]=$profile[$field];
        }
        // Advance the real clock only for a valid no-op so an unnecessary timestamp write is visible.
        usleep(1100000); m6h_modify($aId,'profile',$profile,$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        m6h_me($subjects['A1'],$bindings['A1'],$evidence); m6h_me($subjects['B1'],$bindings['B1'],$evidence);

        m6h_modify($aId,'flags',['accept_orders'=>1,'fulfillment_enabled'=>0],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        $bindings['A1']=m6h_login($subjects['A1'],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m6h_me($subjects['A1'],$bindings['A1'],$evidence);
        m6h_modify($aId,'flags',['accept_orders'=>0],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        $bindings['A1']=m6h_login($subjects['A1'],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m6h_me($subjects['A1'],$bindings['A1'],$evidence);
        usleep(1100000); m6h_modify($aId,'flags',['accept_orders'=>0,'fulfillment_enabled'=>0,'security_locked'=>0],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        $beforeEligibility=m6h_identity_rows();
        // Valid platform state changes only. No merchant request is issued while pending review or security locked.
        m6h_modify($aId,'review',['review_status'=>0],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        m6h_read($aId,$admin,$cacheKeys,$owners,$secrets,$evidence); m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,20,0);
        m6h_modify($aId,'review',['review_status'=>1],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        foreach (['A1','A2'] as $label) {
            $bindings[$label]=m6h_login($subjects[$label],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m6h_me($subjects[$label],$bindings[$label],$evidence);
        }
        usleep(1100000); m6h_modify($aId,'review',['review_status'=>1],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        m6h_modify($aId,'flags',['security_locked'=>1],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        $locked=m6h_read($aId,$admin,$cacheKeys,$owners,$secrets,$evidence); audit_expect($locked['security_locked']===1,'normal platform can read its currently locked merchant');
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,20,1);
        m6h_modify($aId,'flags',['security_locked'=>0],$admin,$v2,$cacheKeys,$owners,$secrets,$evidence);
        foreach (['A1','A2'] as $label) {
            $bindings[$label]=m6h_login($subjects[$label],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m6h_me($subjects[$label],$bindings[$label],$evidence);
        }
        m6h_read($aId,$admin,$cacheKeys,$owners,$secrets,$evidence); m6h_read($bId,$admin,$cacheKeys,$owners,$secrets,$evidence);
        m6h_list($admin,$cacheKeys,$owners,$secrets,$evidence,1,20,null,true);
        $final=m6h_identity_rows();
        audit_expect($final['merchant'][$bId]===$beforeEligibility['merchant'][$bId],'normal A eligibility transitions preserve the independent B merchant');
        audit_expect(count($evidence['requests'])===54 && count($evidence['merchant_audits'])===17 && count($evidence['platform_read_logs'])===14
            && count($evidence['valid_noops'])===3 && count($evidence['version_observations'])===4,'complete declared normal platform flow has54 real requests,17 write audits,14 read logs,3 no-ops and4 membership protections');
        $evidence['original_normal_flow']=['http_requests'=>54,'merchant_write_audits'=>17,'platform_read_logs'=>14,'valid_noops'=>3,'membership_protections'=>4,'complete_original_flow_preserved'=>true];

        // A new enabled DB role grants all seven actual platform leaves. This is solely a successful current-grant case.
        $grantRoleId=m6h_grant_role($pdo,$slug,$owners,$secrets,$evidence);
        $grantAdmin=m6h_admin($pdo,$slug.'_granted',$config,$adminV2,$owners,$ownedKeys,$secrets,$evidence,1,(string)$grantRoleId);
        $grantCacheKeys=m6h_permission_cache($app,$prefix,$ownedKeys,$evidence,[$grantRoleId]);
        m6h_list($grantAdmin,$grantCacheKeys,$owners,$secrets,$evidence,1,20,null,true);
        m6h_read($aId,$grantAdmin,$grantCacheKeys,$owners,$secrets,$evidence);
        $evidence['positive_platform_grants']['admin_id']=$grantAdmin['id']; $evidence['positive_platform_grants']['normal_get_requests']=2;
        $evidence['positive_platform_grants']['fresh_db_grant_branch_verified']=true;

        // The original A role remains named 管理员 with eight valid permissions. No affected old session is used afterward.
        m6h_reduce_role($subjects['A1'],$bindings['A1'],$v2,$evidence);
        $plannedEmployees[]=['mer_ids'=>[$aId],'accounts'=>[$slug.'_a3']];
        $fallbackMerchant=['mer_id'=>$aId,'name'=>$profile['name'],'admin_role_id'=>$merchants['A']['admin_role_id']];
        $subjects['A3']=m6h_create_employee($fallbackMerchant,'A3',$slug,$admin,$v2,$cacheKeys,$owners,$secrets,$evidence,true);
        foreach (['logo','after_sale_contact','shipping_note'] as $field) $subjects['A3'][$field]=$profile[$field];
        $state=m6h_identity_rows(); m6h_setup($subjects['A3'],$state,$config,$v2,$ownedKeys,$secrets,$evidence);
        $bindings['A3']=m6h_login($subjects['A3'],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m6h_me($subjects['A3'],$bindings['A3'],$evidence);
        audit_expect(m6h_identity_rows()===$state,'new full-nine fallback administrator preparation preserves the original eight-permission role and every other identity');
        audit_expect(count($evidence['requests'])===61 && count($evidence['merchant_audits'])===18 && count($evidence['platform_read_logs'])===16
            && count($evidence['valid_noops'])===3 && count($evidence['version_observations'])===5,'original54 plus seven positive coverage requests have61 real HTTP,18 write audits,16 read logs,3 no-ops and5 normal protections');
        $evidence['observations']=['merchant_ids'=>[$aId,$bId],'administrator_employee_ids'=>array_column($subjects,'employee_id'),
            'local_default_roles_verified'=>4,'multiple_administrators_per_merchant_verified'=>true,'capabilities_consumed_once'=>4,
            'profile_accept_orders_fulfillment_do_not_rotate'=>true,'review_and_security_protected_employee_count'=>2,'valid_noop_count'=>3,
            'normal_disabled_order_and_fulfillment_login_verified'=>true,'locked_platform_read_verified'=>true,
            'old_token_requests'=>0,'unapproved_merchant_requests'=>0,'locked_merchant_requests'=>0,
            'protected_member_fixture_status'=>'review/security phases use both current active local administrators; stopped disabled/deleted-actor request acceptance remains NOT RUN',
            'positive_current_db_role_grants_verified'=>true,'administrator_role_fallback_name_generation_verified'=>true,
            'fallback_role_id'=>$subjects['A3']['role_id'],'fallback_employee_id'=>$subjects['A3']['employee_id'],'original_a_role_permission_count'=>8,
            'original_normal_http_count'=>54,'additional_positive_http_count'=>7,'final_normal_http_count'=>61,
            'browser_acceptance'=>false,'log_switches_changed'=>false];
    } catch (Throwable $error) { $failure=m6h_diag($error); $evidence['diagnostic']=$failure; }
    finally {
        if ($started) { try { audit_app_request('stop'); $evidence['app_stopped']=true; } catch (Throwable $error) { $cleanupFailure=m6h_diag($error); $evidence['stop_diagnostic']=$cleanupFailure; } }
        try {
            if ($pdo instanceof PDO) {
                m6h_discover($pdo,$plannedMerchants,$plannedEmployees,$admin,$owners);
                if ($v2!==null) m6h_session_cleanup($v2,'merchantapi',array_keys($owners['employee']),$ownedKeys);
                if ($adminV2!==null) m6h_session_cleanup($adminV2,'adminapi',array_keys($owners['system_admin']),$ownedKeys);
                $removed=m6h_cleanup($pdo,$owners,$ownedKeys);
            } else {
                $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0;
                audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys),'precise partial preparation cleanup receives native removed count');
                foreach ($keys as $key) audit_expect(audit_redis()->exists($key)===0,'precise partial preparation cleanup completes');
            }
            $evidence['owned_keys_registered_count']=count(array_unique($ownedKeys)); $evidence['owned_keys_removed_count']=$removed;
        } catch (Throwable $error) { $cleanupFailure ??= m6h_diag($error); $evidence['cleanup_diagnostic']=m6h_diag($error); }
    }
    if (isset($initial)) {
        try {
            $after=m6h_snapshot(); $evidence['after']=m6h_public_snapshot($after);
            $allowedCounters=array_values(array_filter($counterTables,static fn(string $table): bool=>count($owners[str_starts_with($table,'eb_marketplace_') ? substr($table,15) : substr($table,3)] ?? [])>0));
            m6h_same($initial,$after,[],[],$allowedCounters); audit_guard_assert();
            $evidence['preservation']=['tables'=>count($initial['names']),'original_rows_schema_checksums_unchanged'=>true,'old_cache_values_and_absolute_expiry_unchanged'=>true,
                'counter_changes_allowed_only'=>$allowedCounters,'snapshot_restore_used'=>false];
            if ($secrets) { audit_expect(m6h_logs_private($secrets),'ordinary runtime logs contain no owned credential, capability or business body material'); $evidence['private_logs_verified_in_normal_configuration']=true; }
        } catch (Throwable $error) { $failure ??= m6h_diag($error); $evidence['preservation_diagnostic']=m6h_diag($error); }
    }
    $evidence['fixtures_cleaned']=array_sum(array_map('count',$owners))>0 && $cleanupFailure===null;
    $evidence['owned_row_ids']=array_map(static fn(array $rows): array=>array_keys($rows),$owners);
    $ref='evidence/ms1-platform-'.(getenv('AUDIT_PHASE') ?: 'after').'.json'; $serialized=json_encode($evidence,JSON_THROW_ON_ERROR);
    foreach ($secrets as $secret) audit_expect($secret==='' || !str_contains($serialized,$secret),'ordinary evidence contains no private material');
    audit_write_json(audit_run_dir().'/'.$ref,$evidence); $passed=$failure===null && $cleanupFailure===null;
    return ['status'=>$passed ? 'PASS' : 'FAIL','actual'=>$passed ? 'valid current platform merchant management, exact real audits and request logs, capability delivery and ordinary state transitions preserved with precise cleanup'
        : ($failure ?? $cleanupFailure)['class'].'@'.($failure ?? $cleanupFailure)['file'].':'.($failure ?? $cleanupFailure)['line'],
        'expected'=>'normal Task6 owned platform management only','evidence_ref'=>$ref,
        'notes'=>'new granted restricted administrator succeeds normally; no ungranted actor, locked/unapproved merchant request, old/invalid token or capability, fault/concurrency/recovery; ordinary result does not replace original D20/M06 refusal or browser acceptance'];
});

foreach ([['D20','old_merchant_only_platform_permission'],['M06','locked_employee_next_request'],['M08','unapproved_employee_request'],
    ['M06','prechange_session_next_request'],['M01','duplicate_normalized_administrator_account'],['M01','closed_merchant_administrator_create'],
    ['M01','invalid_or_repeated_setup_capability'],['M06','state_change_fault_concurrency_recovery']] as [$id,$subcase]) {
    audit_case($id,$subcase,'INTEGRATION',static fn(): array=>['status'=>'NOT RUN',
        'actual'=>'skipped at user request; no stopped denial, invalid credential, old-token, fault, concurrency or recovery request executed',
        'expected'=>'original refusal and exceptional-path acceptance remains unverified']);
}
audit_finish();
