<?php
declare(strict_types=1);

/* Ordinary HTTP draft only. Root installs/reviews before execution.
 * Valid fresh capabilities, valid passwords and current tokens only.
 * No stopped suite, refusal, wrong credential, replay, fault or concurrency.
 */
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';

function m4h_diag(Throwable $error): array
{
    return ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()];
}

function m4h_scan(string $pattern): array
{
    $cursor=null; $keys=[]; $redis=audit_redis();
    do { $keys=array_merge($keys,$redis->scan($cursor,$pattern,200) ?: []); } while ($cursor !== 0);
    $keys=array_values(array_unique($keys)); sort($keys,SORT_STRING); return $keys;
}

function m4h_deadlines(array $keys): array
{
    if (!$keys) return [];
    $values=audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r",$keys,count($keys));
    audit_expect(is_array($values) && count($values)===count($keys),'every native cache deadline is available');
    $result=[]; foreach ($keys as $i=>$key) { audit_expect(is_int($values[$i]),'cache deadline is integral'); $result[$key]=$values[$i]; }
    return $result;
}

function m4h_snapshot(): array
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
    $redis=audit_redis(); $keys=m4h_scan('*'); $deadlines=m4h_deadlines($keys);
    foreach ($keys as $key) {
        if ($redis->type($key)===Redis::REDIS_SET) { $members=$redis->sMembers($key); sort($members,SORT_STRING); $value="set\0".serialize($members); }
        else { $value=(string)$redis->dump($key); }
        $snapshot['redis'][$key]=['sha256'=>hash('sha256',$value),'deadline'=>$deadlines[$key]];
    }
    return $snapshot;
}

function m4h_public_snapshot(array $snapshot): array
{
    $redis=[]; foreach ($snapshot['redis'] as $key=>$value) $redis[hash('sha256',$key)]=$value;
    ksort($redis); $snapshot['redis']=$redis; return $snapshot;
}

function m4h_same(array $before,array $after,array $changedTables=[],array $changedKeys=[],array $counterTables=[]): void
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

function m4h_app(array $config,string $prefix): \think\App
{
    $app=new \think\App(audit_repo_dir().'/crmeb/');
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],
        'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306','DATABASE_DATABASE'=>'audit_auth',
        'DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],'DATABASE_PREFIX'=>'eb_','DATABASE_DEBUG'=>false,
        'CACHE_DRIVER'=>'redis','REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
        'AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]);
    $app->initialize(); return $app;
}

function m4h_warm_language(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): void
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
    $before=m4h_snapshot();
    foreach ($cleanupKeys as $key) audit_expect(!isset($before['redis'][$key]),'exact language preparation key was already present');
    // Register before the real normal producer, for precise partial-run cleanup.
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,$cleanupKeys)));
    $start=time(); getLang('操作成功'); $end=time(); $after=m4h_snapshot(); $redis=audit_redis();
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
    m4h_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['language_preparation']=['source'=>'real getLang and installed LangCode/LangType/LangCountry services',
        'logical_keys'=>array_keys($values),'added_key_hashes'=>array_map(static fn(string $key): string=>hash('sha256',$key),$expectedNew),
        'exact_values_and_source_expiry_verified'=>true,'unrelated_state_preserved'=>true];
}

function m4h_route_inventory(\think\App $app,array &$evidence): void
{
    $before=m4h_snapshot();
    require audit_repo_dir().'/crmeb/app/merchantapi/route/route.php';
    $actual=[];
    foreach ($app->route->getRuleList() as $rule) {
        $actual[]=['method'=>strtoupper($rule['method']),'rule'=>$rule['rule'],'name'=>$rule['name']];
    }
    $expected=[['method'=>'POST','rule'=>'login','name'=>'merchant.login'],
        ['method'=>'POST','rule'=>'password/setup','name'=>'merchant.password.setup'],
        ['method'=>'POST','rule'=>'refresh','name'=>'merchant.refresh'],
        ['method'=>'POST','rule'=>'logout','name'=>'merchant.logout'],
        ['method'=>'GET','rule'=>'me','name'=>'merchant.me'],
        ['method'=>'GET','rule'=>'employees','name'=>'merchant.employee.list'],
        ['method'=>'POST','rule'=>'employees','name'=>'merchant.employee.create'],
        ['method'=>'PUT','rule'=>'employees/<id>','name'=>'merchant.employee.update'],
        ['method'=>'PUT','rule'=>'employees/<id>/status','name'=>'merchant.employee.status'],
        ['method'=>'GET','rule'=>'roles','name'=>'merchant.role.list'],
        ['method'=>'POST','rule'=>'roles','name'=>'merchant.role.create'],
        ['method'=>'PUT','rule'=>'roles/<id>','name'=>'merchant.role.update']];
    usort($actual,static fn(array $a,array $b): int=>strcmp($a['rule'],$b['rule']));
    usort($expected,static fn(array $a,array $b): int=>strcmp($a['rule'],$b['rule']));
    audit_expect($actual===$expected,'actual framework merchant routes must contain exactly the twelve contracted interfaces');
    m4h_same($before,m4h_snapshot());
    $evidence['routes']=['actual_framework_rules'=>$actual,'exact_contracted_count'=>12,
        'source'=>'real App service boot and same merchantapi route definition; no undeclared HTTP requests'];
}

function m4h_fixtures(PDO $pdo,array &$fixtures,array &$secrets): void
{
    $slug='ms1_http_'.substr(hash('sha256',(string)getenv('AUDIT_RUN_ID')),0,12);
    audit_expect(!$pdo->inTransaction(),'fixture transaction must be owned'); $pdo->beginTransaction();
    try {
        foreach (['A'=>\app\services\marketplace\MerchantPermissions::BUILTIN_ADMIN,'B'=>\app\services\marketplace\MerchantPermissions::BUILTIN_OPERATOR] as $label=>$permissions) {
            $now=time(); $cap=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); $password='M4h-'.bin2hex(random_bytes(20)).'-x';
            $decoded=base64_decode(strtr($cap,'-_','+/').'=',true);
            audit_expect(preg_match('/^[A-Za-z0-9_-]{43}$/D',$cap)===1 && is_string($decoded) && strlen($decoded)===32
                && rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')===$cap,'normal capability is the canonical32-byte base64url value');
            $secrets[]=$cap; $secrets[]=$password; $secrets[]=hash('sha256',$cap); $secrets[]=hash('sha256',$cap,true);
            $fixtures[$label]=['label'=>$label,'merchant_name'=>$slug.'_'.$label,'role_name'=>'HTTP '.$label,'account'=>$slug.'_'.strtolower($label),
                'name'=>'HTTP '.$label,'avatar'=>'/statics/ms1-http-'.$label.'.png','permissions'=>$permissions,'cap'=>$cap,'password'=>$password];
            $f=&$fixtures[$label];
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_merchant(name,review_status,accept_orders,fulfillment_enabled,add_time,update_time) VALUES(?,?,0,0,?,?)');
            $stmt->execute([$f['merchant_name'],$label==='A' ? 0 : 1,$now,$now]); $f['mer_id']=(int)$pdo->lastInsertId();
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_role(mer_id,name,permissions,add_time,update_time) VALUES(?,?,?,?,?)');
            $stmt->execute([$f['mer_id'],$f['role_name'],json_encode($permissions,JSON_THROW_ON_ERROR),$now,$now]); $f['role_id']=(int)$pdo->lastInsertId();
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_employee(mer_id,role_id,account,name,avatar,add_time,update_time) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$f['mer_id'],$f['role_id'],$f['account'],$f['name'],$f['avatar'],$now,$now]); $f['employee_id']=(int)$pdo->lastInsertId();
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_password_setup(mer_id,employee_id,token_hash,expires_at,add_time) VALUES(?,?,?,?,?)');
            $stmt->execute([$f['mer_id'],$f['employee_id'],hash('sha256',$cap,true),$now+86400,$now]); $f['setup_id']=(int)$pdo->lastInsertId(); unset($f);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function m4h_rows(PDO $pdo,array $fixtures): array
{
    $result=[];
    foreach ($fixtures as $label=>$f) {
        foreach (['merchant'=>['mer_id',$f['mer_id']],'role'=>['id',$f['role_id']],'employee'=>['id',$f['employee_id']],'password_setup'=>['id',$f['setup_id']]] as $table=>[$pk,$id]) {
            $stmt=$pdo->prepare('SELECT * FROM eb_marketplace_'.$table.' WHERE `'.$pk.'`=?'); $stmt->execute([$id]);
            $row=$stmt->fetch(); audit_expect(is_array($row),'normal owned fixture is present'); $result[$label][$table]=$row;
        }
    }
    return $result;
}

function m4h_http(string $method,string $path,?string $token,?array $body,array &$evidence): array
{
    $headers=['cb-lang'=>'zh-CN']; if ($token!==null) $headers['Authorization']='Bearer '.$token;
    $response=audit_http($method,'http://audit-app:8000'.$path,$headers,$body);
    $evidence['requests'][]=['method'=>$method,'path'=>$path,'http'=>$response['http'],'business_status'=>is_int($response['json']['status'] ?? null) ? $response['json']['status'] : null];
    audit_expect($response['http']===200 && is_array($response['json']) && ($response['json']['status'] ?? null)===200,'normal HTTP operation must succeed with real 200');
    $keys=array_keys($response['json']); sort($keys,SORT_STRING);
    audit_expect($keys===['data','msg','status'] && is_string($response['json']['msg']) && is_array($response['json']['data']),'response envelope differs');
    return $response['json']['data'];
}

function m4h_claims(string $token,array $config): array
{
    $timestamp=\Firebase\JWT\JWT::$timestamp; $leeway=\Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp=null; \Firebase\JWT\JWT::$leeway=60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp=$timestamp; \Firebase\JWT\JWT::$leeway=$leeway; }
}

function m4h_binding(array $data,array $f,array $config,string $v2,array &$ownedKeys,array &$secrets): array
{
    $keys=array_keys($data); sort($keys,SORT_STRING);
    audit_expect($keys===['exp','token'] && is_string($data['token']) && $data['token']!=='' && is_int($data['exp']),'token response contains exactly token/exp');
    $secrets[]=$data['token']; $claims=m4h_claims($data['token'],$config);
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
    $deadline=m4h_deadlines([$session])[$session]; audit_expect(abs($deadline-($claims['exp']+60)*1000)<=1,'session deadline differs from signed expiry plus60');
    return ['token'=>$data['token'],'claims'=>$claims,'account'=>$account,'session'=>$session,'record'=>$actual,'account_record'=>$actualAccount,'deadline'=>$deadline];
}

function m4h_dto(array $actual,array $f): void
{
    $expected=['employee'=>['id'=>$f['employee_id'],'mer_id'=>$f['mer_id'],'role_id'=>$f['role_id'],'account'=>$f['account'],'name'=>$f['name'],'avatar'=>$f['avatar'],'status'=>1],
        'role'=>['id'=>$f['role_id'],'mer_id'=>$f['mer_id'],'name'=>$f['role_name'],'permissions'=>$f['permissions']],
        'merchant'=>['mer_id'=>$f['mer_id'],'name'=>$f['merchant_name'],'logo'=>'','after_sale_contact'=>'','shipping_note'=>'']];
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

function m4h_setup_effect(PDO $pdo,array $fixtures,string $label,array $before,int $start,int $end,array &$secrets): array
{
    $after=m4h_rows($pdo,$fixtures); $f=$fixtures[$label]; $employee=$after[$label]['employee']; $setup=$after[$label]['password_setup'];
    audit_expect($before[$label]['employee']['pwd']===null && $before[$label]['password_setup']['consumed_at']===null,'first setup fixture was originally unused');
    audit_expect(is_string($employee['pwd']) && $employee['pwd']!==$f['password'] && password_verify($f['password'],$employee['pwd']),'setup stores a real verified password hash');
    $secrets[]=$employee['pwd'];
    audit_expect((int)$employee['update_time']>=$start && (int)$employee['update_time']<=$end
        && $setup['consumed_at']!==null && (int)$setup['consumed_at']>=$start && (int)$setup['consumed_at']<=$end
        && (int)$setup['expires_at']===(int)$setup['add_time']+86400 && $setup['token_hash']===hash('sha256',$f['cap'],true),'setup consumption and capability binding differ');
    $expected=$before; $expected[$label]['employee']['pwd']=$employee['pwd']; $expected[$label]['employee']['update_time']=$employee['update_time']; $expected[$label]['password_setup']['consumed_at']=$setup['consumed_at'];
    audit_expect($after===$expected,'setup changed unrelated fixture columns or identities'); return $after;
}

function m4h_fixture_review(PDO $pdo,array $fixtures,array $before,array &$evidence): array
{
    $f=$fixtures['A']; audit_expect((int)$before['A']['merchant']['review_status']===0,'normal A fixture begins awaiting review');
    $snapshot=m4h_snapshot(); $start=time();
    $stmt=$pdo->prepare('UPDATE eb_marketplace_merchant SET review_status=1,update_time=? WHERE mer_id=? AND name=? AND review_status=0');
    $stmt->execute([time(),$f['mer_id'],$f['merchant_name']]); $end=time();
    audit_expect($stmt->rowCount()===1,'exact owned fixture review transition affects one row');
    $after=m4h_rows($pdo,$fixtures); $merchant=$after['A']['merchant'];
    audit_expect((int)$merchant['review_status']===1 && (int)$merchant['update_time']>=$start && (int)$merchant['update_time']<=$end,'normal owned fixture review transition differs');
    $expected=$before; $expected['A']['merchant']['review_status']=$merchant['review_status']; $expected['A']['merchant']['update_time']=$merchant['update_time'];
    audit_expect($after===$expected,'fixture review changes only A review_status/update_time');
    m4h_same($snapshot,m4h_snapshot(),['eb_marketplace_merchant']);
    $evidence['fixture_review_transition']=['mer_id'=>$f['mer_id'],'from'=>0,'to'=>1,'exact_row_and_other_state_verified'=>true,
        'source'=>'precise synthetic fixture DML after valid setup and before login','platform_review_api_verified'=>false,'task6_pending'=>true];
    return $after;
}

function m4h_discover_owned_sessions(string $v2,array $fixtures,array &$ownedKeys): void
{
    $ids=array_map(static fn(array $f): string=>(string)$f['employee_id'],array_filter($fixtures,static fn(array $f): bool=>isset($f['employee_id'])));
    foreach (m4h_scan($v2.'session:*') as $key) {
        if (preg_match('/^'.preg_quote($v2.'session:','/').'[a-f0-9]{32}$/D',$key)!==1 || audit_redis()->type($key)!==Redis::REDIS_HASH) continue;
        $row=audit_redis()->hGetAll($key);
        if (in_array($row['subject_id'] ?? '',$ids,true) && ($row['type'] ?? '')==='merchant' && ($row['audience'] ?? '')==='tianli:merchantapi') $ownedKeys[]=$key;
    }
}

function m4h_cleanup(PDO $pdo,array $fixtures,array $ownedKeys): int
{
    foreach (array_reverse($fixtures,true) as $f) {
        foreach (['password_setup'=>'DELETE FROM eb_marketplace_password_setup WHERE id=? AND mer_id=? AND employee_id=? AND token_hash=?',
            'employee'=>'DELETE FROM eb_marketplace_employee WHERE id=? AND mer_id=? AND account=?',
            'role'=>'DELETE FROM eb_marketplace_role WHERE id=? AND mer_id=? AND name=?',
            'merchant'=>'DELETE FROM eb_marketplace_merchant WHERE mer_id=? AND name=?'] as $table=>$sql) {
            $params=match($table) {
                'password_setup'=>isset($f['setup_id']) ? [$f['setup_id'],$f['mer_id'],$f['employee_id'],hash('sha256',$f['cap'],true)] : null,
                'employee'=>isset($f['employee_id']) ? [$f['employee_id'],$f['mer_id'],$f['account']] : null,
                'role'=>isset($f['role_id']) ? [$f['role_id'],$f['mer_id'],$f['role_name']] : null,
                'merchant'=>isset($f['mer_id']) ? [$f['mer_id'],$f['merchant_name']] : null,
            };
            if ($params!==null) { $stmt=$pdo->prepare($sql); $stmt->execute($params); }
        }
    }
    $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0;
    audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys),'exact owned cleanup received a native count');
    foreach ($keys as $key) audit_expect(audit_redis()->exists($key)===0,'exact owned cache cleanup is incomplete');
    return $removed;
}

function m4h_logs_private(array $secrets): bool
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

audit_case('T4A01','normal_merchant_http_lifecycle','INTEGRATION',static function(): array {
    $evidence=['run_id'=>getenv('AUDIT_RUN_ID'),'requests'=>[],'observations'=>[],'negative_cases_run'=>false];
    $fixtures=[]; $ownedKeys=[]; $secrets=[]; $failure=null; $cleanupFailure=null; $started=false; $pdo=null; $v2=null;
    $counterTables=['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup'];
    try {
        $initial=m4h_snapshot(); $evidence['before']=m4h_public_snapshot($initial);
        foreach (['crmeb/app/merchantapi/route/route.php','crmeb/app/merchantapi/provider.php','crmeb/app/merchantapi/MerchantApiExceptionHandle.php',
            'crmeb/app/merchantapi/middleware/MerchantAuthTokenMiddleware.php','crmeb/app/services/marketplace/EmployeeAuthServices.php'] as $file) audit_expect(is_file(audit_repo_dir().'/'.$file),'Task4 implementation unavailable');
        audit_expect(count($initial['names'])===163,'installed base plus five identity tables required');
        foreach (array_merge($counterTables,['eb_marketplace_audit_log']) as $table) audit_expect(($initial['rows'][$table]['count'] ?? -1)===0,'fresh empty identity tables required');
        $config=audit_config(); $secrets=[$config['signing_key'],$config['auth']['stamp_key'],$config['redis_password'],$config['database']['password']];
        $prefix='audit:http-lang:'.getenv('AUDIT_RUN_ID').':'.gmdate('YmdHis').random_int(100000,999999).':';
        $v2='auth:v2:'.$config['auth']['session_epoch'].':merchantapi:';
        audit_expect(m4h_scan($v2.'*')===[] && m4h_scan($prefix.'*')===[],'new normal merchant and language namespaces must initially be empty');
        $app=m4h_app($config,$prefix); m4h_route_inventory($app,$evidence); m4h_warm_language($app,$prefix,$ownedKeys,$evidence);
        $pdo=audit_db(); m4h_fixtures($pdo,$fixtures,$secrets); $fixtureRows=m4h_rows($pdo,$fixtures);
        foreach ($fixtures as $f) { $key=$v2.'account:'.$f['employee_id']; audit_expect(audit_redis()->exists($key)===0,'new employee account key was absent'); $ownedKeys[]=$key; }
        // The existing supervisor verifies its real listener; no extra admin request.
        $started=true; audit_app_request('start',['CACHE_PREFIX'=>$prefix]);
        foreach ($fixtures as $label=>$f) {
            $before=m4h_snapshot(); $start=time(); $data=m4h_http('POST','/merchantapi/password/setup',null,['token'=>$f['cap'],'password'=>$f['password']],$evidence); $end=time();
            audit_expect($data===[],'setup response must have no private data'); $fixtureRows=m4h_setup_effect($pdo,$fixtures,$label,$fixtureRows,$start,$end,$secrets);
            $accountKey=$v2.'account:'.$f['employee_id']; $account=audit_redis()->hGetAll($accountKey); ksort($account);
            audit_expect($account===['blocked'=>'0','initialized'=>'0'] && audit_redis()->pttl($accountKey)===-1,'first setup completion must not mint an authorization version');
            m4h_same($before,m4h_snapshot(),['eb_marketplace_employee','eb_marketplace_password_setup'],[$accountKey]);
            if ($label==='A') $fixtureRows=m4h_fixture_review($pdo,$fixtures,$fixtureRows,$evidence);
            $before=m4h_snapshot(); $data=m4h_http('POST','/merchantapi/login',null,['account'=>'  '.strtoupper($f['account']).'  ','password'=>$f['password']],$evidence);
            $login=m4h_binding($data,$f,$config,$v2,$ownedKeys,$secrets); m4h_same($before,m4h_snapshot(),[],[$accountKey,$login['session']]);
            $before=m4h_snapshot(); $context=$app->make(\app\services\marketplace\EmployeeAuthServices::class)->authenticate($login['token']);
            audit_expect($context instanceof \app\services\marketplace\context\MerchantContext
                && $context->merId===$f['mer_id'] && $context->employeeId===$f['employee_id'] && $context->permissions===$f['permissions']
                && $context->sessionVersion===$login['claims']['session_version'] && trim($context->requestId)!=='','actual verified current session builds the exact local merchant context');
            m4h_same($before,m4h_snapshot());
            $evidence['contexts'][$label]=['mer_id'=>$context->merId,'employee_id'=>$context->employeeId,'permissions'=>count($context->permissions),
                'verified_version_matches_signed_current_session'=>true,'request_id_present'=>true,'current_token_only'=>true,'snapshot_unchanged'=>true];
            $before=m4h_snapshot(); m4h_dto(m4h_http('GET','/merchantapi/me',$login['token'],null,$evidence),$f); m4h_same($before,m4h_snapshot());
            $before=m4h_snapshot(); $data=m4h_http('POST','/merchantapi/refresh',$login['token'],null,$evidence);
            $current=m4h_binding($data,$f,$config,$v2,$ownedKeys,$secrets);
            audit_expect($current['session']!==$login['session'] && $current['claims']['session_version']===$login['claims']['session_version'] && $current['account_record']===$login['account_record'],'normal refresh uses the same account version and a new session');
            $old=audit_redis()->hGetAll($login['session']); ksort($old); $expected=$login['record']; $expected['status']='consumed'; ksort($expected);
            audit_expect($old===$expected && abs(m4h_deadlines([$login['session']])[$login['session']]-$login['deadline'])<=1,'normal refresh preserves exact predecessor binding/deadline and consumes only status');
            m4h_same($before,m4h_snapshot(),[],[$login['session'],$current['session']]);
            $before=m4h_snapshot(); m4h_dto(m4h_http('GET','/merchantapi/me',$current['token'],null,$evidence),$f); m4h_same($before,m4h_snapshot());
            $before=m4h_snapshot(); audit_expect(m4h_http('POST','/merchantapi/logout',$current['token'],null,$evidence)===[],'logout response must be empty');
            $account=audit_redis()->hGetAll($current['account']); ksort($account);
            audit_expect(audit_redis()->exists($current['session'])===0 && $account===$current['account_record'],'normal logout removes only the current session');
            m4h_same($before,m4h_snapshot(),[],[$current['session']]);
            audit_expect(m4h_rows($pdo,$fixtures)===$fixtureRows,'normal login/read/refresh/logout must preserve all identity rows');
            $evidence['observations'][$label]=['mer_id'=>$f['mer_id'],'employee_id'=>$f['employee_id'],'role_id'=>$f['role_id'],'setup_id'=>$f['setup_id'],
                'permissions'=>count($f['permissions']),'password_hash_and_consumption_verified'=>true,'first_setup_no_authorization_version'=>true,
                'setup_before_review_verified'=>$label==='A',
                'accept_orders'=>0,'fulfillment_enabled'=>0,'normal_login_me_refresh_me_logout_verified'=>true,'consumed_predecessor_observed_only'=>true,'current_session_absent_after_logout'=>true];
        }
        audit_expect(count($evidence['requests'])===12,'two complete normal six-request lifecycles required');
    } catch (Throwable $error) { $failure=m4h_diag($error); $evidence['diagnostic']=$failure; }
    finally {
        if ($started) { try { audit_app_request('stop'); $evidence['app_stopped']=true; } catch (Throwable $error) { $cleanupFailure=m4h_diag($error); $evidence['stop_diagnostic']=$cleanupFailure; } }
        try {
            if ($v2!==null) m4h_discover_owned_sessions($v2,$fixtures,$ownedKeys);
            if ($pdo instanceof PDO) $removed=m4h_cleanup($pdo,$fixtures,$ownedKeys);
            else {
                $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0;
                audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys),'exact preparation cleanup received a native count');
                foreach ($keys as $key) audit_expect(audit_redis()->exists($key)===0,'exact preparation key cleanup is complete');
            }
            $evidence['owned_keys_registered_count']=count(array_unique($ownedKeys)); $evidence['owned_keys_removed_count']=$removed;
        } catch (Throwable $error) { $cleanupFailure ??= m4h_diag($error); $evidence['cleanup_diagnostic']=m4h_diag($error); }
    }
    if (isset($initial)) {
        try {
            $after=m4h_snapshot(); $evidence['after']=m4h_public_snapshot($after);
            m4h_same($initial,$after,[],[],$fixtures ? $counterTables : []); audit_guard_assert();
            $evidence['preservation']=['tables'=>count($initial['names']),'original_rows_schema_checksums_unchanged'=>true,'cache_values_and_absolute_expiry_unchanged'=>true,'counter_changes_allowed_only'=>$fixtures ? $counterTables : []];
            audit_expect(m4h_logs_private($secrets),'actual logs must contain no capability/password/token or credential material'); $evidence['private_logs_verified']=true;
        } catch (Throwable $error) { $failure ??= m4h_diag($error); $evidence['preservation_diagnostic']=m4h_diag($error); }
    }
    $evidence['fixtures_cleaned']=$fixtures!==[] && $cleanupFailure===null;
    $evidence['synthetic_fixture_ids']=array_map(static fn(array $f): array=>array_intersect_key($f,array_flip(['mer_id','role_id','employee_id','setup_id'])),$fixtures);
    $ref='evidence/ms1-merchantapi-'.(getenv('AUDIT_PHASE') ?: 'after').'.json';
    $serialized=json_encode($evidence,JSON_THROW_ON_ERROR);
    foreach ($secrets as $secret) audit_expect($secret==='' || !str_contains($serialized,$secret),'evidence must contain no private material');
    audit_write_json(audit_run_dir().'/'.$ref,$evidence); $passed=$failure===null && $cleanupFailure===null;
    return ['status'=>$passed ? 'PASS' : 'FAIL','actual'=>$passed ? 'two real valid merchant HTTP lifecycles, exact setup/session/DTO effects, precise cleanup and original state preserved' : ($failure ?? $cleanupFailure)['class'].'@'.($failure ?? $cleanupFailure)['file'].':'.($failure ?? $cleanupFailure)['line'],
        'expected'=>'normal Task4 five endpoints and current-session observations only','evidence_ref'=>$ref,'notes'=>'no refusal, wrong credentials, old-token request, capability replay, faults or complete original-case acceptance'];
});

foreach ([['M03','client_merchant_selector'],['M04','missing_context_service'],['M06','security_lock_refusal'],['M08','unapproved_merchant_refusal'],
    ['T4P01','wrong_setup_capability'],['T4P02','expired_setup_capability'],['T4P03','repeated_setup_capability']] as [$id,$subcase]) {
    audit_case($id,$subcase,'INTEGRATION',static fn(): array=>['status'=>'NOT RUN','actual'=>'skipped at user request; no rejection or replay request executed','expected'=>'original refusal acceptance remains unverified']);
}
audit_finish();
