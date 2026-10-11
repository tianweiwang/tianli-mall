<?php
declare(strict_types=1);

/* Ordinary Task5 draft. Valid current credentials and owned objects only.
 * No stopped suite, refusal, old-token request, fault or concurrency.
 * Snapshot/language/session helpers copied from the reviewed ordinary Task4 source.
 */
require __DIR__ . '/lib/harness.php';
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
function m5h_diag(Throwable $error): array
{
    $caller=$error->getTrace()[0] ?? [];
    return ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'caller_file'=>basename($caller['file'] ?? ''),'caller_line'=>$caller['line'] ?? null];
}

function m5h_scan(string $pattern): array
{
    $cursor=null; $keys=[]; $redis=audit_redis();
    do { $keys=array_merge($keys,$redis->scan($cursor,$pattern,200) ?: []); } while ($cursor !== 0);
    $keys=array_values(array_unique($keys)); sort($keys,SORT_STRING); return $keys;
}

function m5h_deadlines(array $keys): array
{
    if (!$keys) return [];
    $values=audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r",$keys,count($keys));
    audit_expect(is_array($values) && count($values)===count($keys),'every native cache deadline is available');
    $result=[]; foreach ($keys as $i=>$key) { audit_expect(is_int($values[$i]),'cache deadline is integral'); $result[$key]=$values[$i]; }
    return $result;
}

function m5h_snapshot(): array
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
    $redis=audit_redis(); $keys=m5h_scan('*'); $deadlines=m5h_deadlines($keys);
    foreach ($keys as $key) {
        if ($redis->type($key)===Redis::REDIS_SET) { $members=$redis->sMembers($key); sort($members,SORT_STRING); $value="set\0".serialize($members); }
        else { $value=(string)$redis->dump($key); }
        $snapshot['redis'][$key]=['sha256'=>hash('sha256',$value),'deadline'=>$deadlines[$key]];
    }
    return $snapshot;
}

function m5h_public_snapshot(array $snapshot): array
{
    $redis=[]; foreach ($snapshot['redis'] as $key=>$value) $redis[hash('sha256',$key)]=$value;
    ksort($redis); $snapshot['redis']=$redis; return $snapshot;
}

function m5h_same(array $before,array $after,array $changedTables=[],array $changedKeys=[],array $counterTables=[]): void
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

function m5h_app(array $config,string $prefix): \think\App
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

function m5h_warm_language(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): void
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
    $before=m5h_snapshot();
    foreach ($cleanupKeys as $key) audit_expect(!isset($before['redis'][$key]),'exact language preparation key was already present');
    // Register before the real normal producer, for precise partial-run cleanup.
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,$cleanupKeys)));
    $start=time(); getLang('操作成功'); $end=time(); $after=m5h_snapshot(); $redis=audit_redis();
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
    m5h_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['language_preparation']=['source'=>'real getLang and installed LangCode/LangType/LangCountry services',
        'logical_keys'=>array_keys($values),'added_key_hashes'=>array_map(static fn(string $key): string=>hash('sha256',$key),$expectedNew),
        'exact_values_and_source_expiry_verified'=>true,'unrelated_state_preserved'=>true];
}

function m5h_http(string $method,string $path,?string $token,?array $body,array &$evidence): array
{
    $headers=['cb-lang'=>'zh-CN']; if ($token!==null) $headers['Authorization']='Bearer '.$token;
    $response=audit_http($method,'http://audit-app:8000'.$path,$headers,$body);
    $evidence['requests'][]=['method'=>$method,'path'=>$path,'http'=>$response['http'],'business_status'=>is_int($response['json']['status'] ?? null) ? $response['json']['status'] : null];
    audit_expect($response['http']===200 && is_array($response['json']) && ($response['json']['status'] ?? null)===200,'normal HTTP operation must succeed with real 200');
    $keys=array_keys($response['json']); sort($keys,SORT_STRING);
    audit_expect($keys===['data','msg','status'] && is_string($response['json']['msg']) && is_array($response['json']['data']),'response envelope differs');
    return $response['json']['data'];
}

function m5h_claims(string $token,array $config): array
{
    $timestamp=\Firebase\JWT\JWT::$timestamp; $leeway=\Firebase\JWT\JWT::$leeway;
    try {
        \Firebase\JWT\JWT::$timestamp=null; \Firebase\JWT\JWT::$leeway=60;
        return json_decode(json_encode(\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    } finally { \Firebase\JWT\JWT::$timestamp=$timestamp; \Firebase\JWT\JWT::$leeway=$leeway; }
}

function m5h_binding(array $data,array $f,array $config,string $v2,array &$ownedKeys,array &$secrets): array
{
    $keys=array_keys($data); sort($keys,SORT_STRING);
    audit_expect($keys===['exp','token'] && is_string($data['token']) && $data['token']!=='' && is_int($data['exp']),'token response contains exactly token/exp');
    $secrets[]=$data['token']; $claims=m5h_claims($data['token'],$config);
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
    $deadline=m5h_deadlines([$session])[$session]; audit_expect(abs($deadline-($claims['exp']+60)*1000)<=1,'session deadline differs from signed expiry plus60');
    return ['token'=>$data['token'],'claims'=>$claims,'account'=>$account,'session'=>$session,'record'=>$actual,'account_record'=>$actualAccount,'deadline'=>$deadline];
}

function m5h_dto(array $actual,array $f): void
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

function m5h_logs_private(array $secrets): bool
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

function m5h_routes(\think\App $app,array &$evidence): void
{
    $before=m5h_snapshot(); require audit_repo_dir().'/crmeb/app/merchantapi/route/route.php'; $actual=[];
    foreach ($app->route->getRuleList() as $row) $actual[]=['method'=>strtoupper($row['method']),'rule'=>$row['rule'],'name'=>$row['name']];
    $expected=[['POST','login','merchant.login'],['POST','password/setup','merchant.password.setup'],['POST','refresh','merchant.refresh'],
        ['POST','logout','merchant.logout'],['GET','me','merchant.me'],['GET','employees','merchant.employee.list'],['POST','employees','merchant.employee.create'],
        ['PUT','employees/<id>','merchant.employee.update'],['PUT','employees/<id>/status','merchant.employee.status'],
        ['GET','roles','merchant.role.list'],['POST','roles','merchant.role.create'],['PUT','roles/<id>','merchant.role.update']];
    $expected=array_map(static fn(array $r): array=>['method'=>$r[0],'rule'=>$r[1],'name'=>$r[2]],$expected);
    $sort=static fn(array $a,array $b): int=>strcmp($a['rule'].'|'.$a['method'],$b['rule'].'|'.$b['method']);
    usort($actual,$sort); usort($expected,$sort); audit_expect($actual===$expected,'actual merchant routes must be exactly the twelve declared interfaces');
    m5h_same($before,m5h_snapshot()); $evidence['routes']=['actual_framework_rules'=>$actual,'exact_twelve_verified'=>true,'product_routes'=>0];
}

function m5h_capability(): string
{
    $cap=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); m5h_valid_capability($cap); return $cap;
}

function m5h_valid_capability(string $cap): void
{
    $decoded=base64_decode(strtr($cap,'-_','+/').'=',true);
    audit_expect(preg_match('/^[A-Za-z0-9_-]{43}$/D',$cap)===1 && is_string($decoded) && strlen($decoded)===32
        && rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')===$cap,'normal capability has its canonical32-byte base64url representation');
}

function m5h_seeds(PDO $pdo,string $slug,array &$subjects,array &$owners,array &$secrets): void
{
    audit_expect(!$pdo->inTransaction(),'seed fixture owns its transaction'); $pdo->beginTransaction();
    try {
        foreach (['A','B'] as $label) {
            $now=time(); $cap=m5h_capability(); $password='M5h-'.bin2hex(random_bytes(20)).'-x';
            $secrets=array_merge($secrets,[$cap,$password,hash('sha256',$cap),hash('sha256',$cap,true)]);
            $subjects[$label]=['label'=>$label,'merchant_name'=>$slug.'_'.$label,'role_name'=>'Management '.$label,
                'account'=>$slug.'_admin_'.strtolower($label),'name'=>'Manager '.$label,'avatar'=>'/statics/ms1-manager-'.$label.'.png',
                'permissions'=>\app\services\marketplace\MerchantPermissions::ALL,'cap'=>$cap,'password'=>$password]; $s=&$subjects[$label];
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_merchant(name,review_status,accept_orders,fulfillment_enabled,add_time,update_time) VALUES(?,1,0,0,?,?)');
            $stmt->execute([$s['merchant_name'],$now,$now]); $s['mer_id']=(int)$pdo->lastInsertId();
            $owners['merchant'][$s['mer_id']]=['id'=>$s['mer_id'],'names'=>[$s['merchant_name']]];
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_role(mer_id,name,permissions,add_time,update_time) VALUES(?,?,?,?,?)');
            $stmt->execute([$s['mer_id'],$s['role_name'],json_encode($s['permissions'],JSON_THROW_ON_ERROR),$now,$now]); $s['role_id']=(int)$pdo->lastInsertId();
            $owners['role'][$s['role_id']]=['id'=>$s['role_id'],'mer_id'=>$s['mer_id'],'names'=>[$s['role_name']]];
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_employee(mer_id,role_id,account,name,avatar,add_time,update_time) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$s['mer_id'],$s['role_id'],$s['account'],$s['name'],$s['avatar'],$now,$now]); $s['employee_id']=(int)$pdo->lastInsertId();
            $owners['employee'][$s['employee_id']]=['id'=>$s['employee_id'],'mer_id'=>$s['mer_id'],'accounts'=>[$s['account']]];
            $stmt=$pdo->prepare('INSERT INTO eb_marketplace_password_setup(mer_id,employee_id,token_hash,expires_at,add_time) VALUES(?,?,?,?,?)');
            $stmt->execute([$s['mer_id'],$s['employee_id'],hash('sha256',$cap,true),$now+86400,$now]); $s['setup_id']=(int)$pdo->lastInsertId();
            $owners['password_setup'][$s['setup_id']]=['id'=>$s['setup_id'],'mer_id'=>$s['mer_id'],'employee_id'=>$s['employee_id'],'token_hash'=>hash('sha256',$cap,true)]; unset($s);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function m5h_identity_rows(): array
{
    $rows=[]; $pdo=audit_db();
    foreach (['merchant','role','employee','password_setup'] as $table) {
        $pk=$table==='merchant' ? 'mer_id' : 'id'; $rows[$table]=[];
        foreach ($pdo->query('SELECT * FROM eb_marketplace_'.$table.' ORDER BY `'.$pk.'`')->fetchAll() as $row) $rows[$table][(int)$row[$pk]]=$row;
    }
    return $rows;
}

function m5h_value(mixed $actual,mixed $expected): bool
{
    return is_int($expected) ? ((is_int($actual) || is_string($actual)) && (string)$actual===(string)$expected) : $actual===$expected;
}

function m5h_change(array $before,string $table,int $id,array $fields,int $start,int $end,bool $created=false): array
{
    $after=m5h_identity_rows(); audit_expect(isset($after[$table][$id]),'normal target row exists'); $row=$after[$table][$id]; $expected=$before;
    if ($created) {
        audit_expect(!isset($before[$table][$id]),'new actual target ID was originally absent');
        $rowKeys=array_keys($row); $fieldKeys=array_keys($fields); $fieldKeys=array_merge($fieldKeys,['add_time','update_time']); sort($rowKeys); sort($fieldKeys);
        audit_expect($rowKeys===$fieldKeys,'new normal row has exactly the frozen table fields'); $expected[$table][$id]=$row;
        audit_expect((int)$row['add_time']>=$start && (int)$row['add_time']<=$end,'normal new row has server creation time');
    }
    foreach ($fields as $field=>$value) {
        if ($field==='permissions') audit_expect(is_string($row[$field]) && json_decode($row[$field],true,32,JSON_THROW_ON_ERROR)===$value,'current role permission list differs');
        else audit_expect(m5h_value($row[$field] ?? null,$value),'normal row field differs from intended business value');
        $expected[$table][$id][$field]=$row[$field];
    }
    audit_expect((int)$row['update_time']>=$start && (int)$row['update_time']<=$end,'normal write has server update time');
    $expected[$table][$id]['update_time']=$row['update_time'];
    foreach ($expected as &$tableRows) ksort($tableRows,SORT_NUMERIC); unset($tableRows);
    audit_expect($after===$expected,'normal write changed another identity row or a non-whitelisted column'); return $after;
}

function m5h_setup(array $s,array &$state,array $config,string $v2,array &$ownedKeys,array &$secrets,array &$evidence): void
{
    $account=$v2.'account:'.$s['employee_id']; audit_expect(audit_redis()->exists($account)===0,'new normal employee has no initialized account'); $ownedKeys[]=$account;
    $before=m5h_snapshot(); $start=time(); audit_expect(m5h_http('POST','/merchantapi/password/setup',null,['token'=>$s['cap'],'password'=>$s['password']],$evidence)===[],'normal setup response is empty'); $end=time();
    $after=m5h_identity_rows(); $employee=$after['employee'][$s['employee_id']]; $setup=$after['password_setup'][$s['setup_id']];
    audit_expect($state['employee'][$s['employee_id']]['pwd']===null && $state['password_setup'][$s['setup_id']]['consumed_at']===null,'normal capability and password were initially unused');
    audit_expect(is_string($employee['pwd']) && $employee['pwd']!==$s['password'] && password_verify($s['password'],$employee['pwd']),'normal setup stores an actual verified password hash'); $secrets[]=$employee['pwd'];
    audit_expect((int)$employee['update_time']>=$start && (int)$employee['update_time']<=$end && $setup['consumed_at']!==null
        && (int)$setup['consumed_at']>=$start && (int)$setup['consumed_at']<=$end && $setup['token_hash']===hash('sha256',$s['cap'],true)
        && (int)$setup['expires_at']===(int)$setup['add_time']+86400,'normal capability binding and consumption time differ');
    $expected=$state; $expected['employee'][$s['employee_id']]['pwd']=$employee['pwd']; $expected['employee'][$s['employee_id']]['update_time']=$employee['update_time'];
    $expected['password_setup'][$s['setup_id']]['consumed_at']=$setup['consumed_at']; audit_expect($after===$expected,'normal setup changed another identity field'); $state=$after;
    $record=audit_redis()->hGetAll($account); ksort($record); audit_expect($record===['blocked'=>'0','initialized'=>'0'] && audit_redis()->pttl($account)===-1,'first setup completes without minting an authorization version');
    m5h_same($before,m5h_snapshot(),['eb_marketplace_employee','eb_marketplace_password_setup'],[$account]);
}

function m5h_login(array $s,array $config,string $v2,array &$ownedKeys,array &$secrets,array &$evidence,\think\App $app): array
{
    $before=m5h_snapshot(); $data=m5h_http('POST','/merchantapi/login',null,['account'=>$s['account'],'password'=>$s['password']],$evidence);
    $bound=m5h_binding($data,$s,$config,$v2,$ownedKeys,$secrets); m5h_same($before,m5h_snapshot(),[],[$bound['account'],$bound['session']]);
    $before=m5h_snapshot(); $ctx=$app->make(\app\services\marketplace\EmployeeAuthServices::class)->authenticate($bound['token']);
    audit_expect($ctx instanceof \app\services\marketplace\context\MerchantContext && $ctx->merId===$s['mer_id'] && $ctx->employeeId===$s['employee_id']
        && $ctx->permissions===$s['permissions'] && $ctx->sessionVersion===$bound['claims']['session_version'] && trim($ctx->requestId)!=='','new valid credential login produces the current real context');
    m5h_same($before,m5h_snapshot()); return $bound;
}

function m5h_me(array $s,array $bound,array &$evidence): void
{
    $before=m5h_snapshot(); m5h_dto(m5h_http('GET','/merchantapi/me',$bound['token'],null,$evidence),$s); m5h_same($before,m5h_snapshot());
}

function m5h_employee_dto(array $actual,array $row): void
{
    $fields=['id','mer_id','role_id','account','name','avatar','status','add_time','update_time']; $keys=array_keys($actual); $wanted=$fields; sort($keys); sort($wanted);
    audit_expect($keys===$wanted,'employee DTO contains exactly its public nine fields');
    foreach ($fields as $field) {
        $expected=in_array($field,['id','mer_id','role_id','status','add_time','update_time'],true) ? (int)$row[$field] : $row[$field];
        audit_expect(m5h_value($actual[$field],$expected),'employee DTO differs from current database row');
    }
}

function m5h_role_dto(array $actual,array $row): void
{
    $fields=['id','mer_id','name','permissions','add_time','update_time']; $keys=array_keys($actual); $wanted=$fields; sort($keys); sort($wanted);
    audit_expect($keys===$wanted,'role DTO contains exactly its public six fields');
    foreach ($fields as $field) {
        $expected=in_array($field,['id','mer_id','add_time','update_time'],true) ? (int)$row[$field] : $row[$field];
        audit_expect($field==='permissions' ? $actual[$field]===json_decode($row[$field],true,32,JSON_THROW_ON_ERROR) : m5h_value($actual[$field],$expected),'role DTO differs from current database row');
    }
}

function m5h_list(string $resource,array $s,array $bound,array &$evidence,int $page=1,int $limit=20,?int $status=null,?int $roleId=null,bool $defaults=false): void
{
    $path='/merchantapi/'.($resource==='employee' ? 'employees' : 'roles');
    $query=['page'=>(string)$page,'limit'=>(string)$limit]; if ($status!==null) $query['status']=(string)$status; if ($roleId!==null) $query['role_id']=(string)$roleId;
    if (!$defaults) $path.='?'.http_build_query($query);
    $before=m5h_snapshot(); $data=m5h_http('GET',$path,$bound['token'],null,$evidence); $keys=array_keys($data); sort($keys);
    audit_expect($keys===['count','list'] && is_int($data['count']) && is_array($data['list']) && array_is_list($data['list']),'normal page contains its exact list/count contract');
    $rows=m5h_identity_rows()[$resource]; $matching=[];
    foreach ($rows as $row) {
        if ((int)$row['mer_id']!==$s['mer_id']) continue;
        if ($resource==='employee' && ((int)$row['is_del']!==0 || ($status!==null && (int)$row['status']!==$status) || ($roleId!==null && (int)$row['role_id']!==$roleId))) continue;
        $matching[]=$row;
    }
    $expected=array_slice($matching,($page-1)*$limit,$limit); audit_expect($data['count']===count($matching) && count($data['list'])===count($expected),'normal page count or range differs from own current rows');
    foreach ($expected as $index=>$row) { if ($resource==='employee') m5h_employee_dto($data['list'][$index],$row); else m5h_role_dto($data['list'][$index],$row); }
    m5h_same($before,m5h_snapshot()); $evidence['list_observations'][]=['resource'=>$resource,'mer_id'=>$s['mer_id'],'page'=>$page,'limit'=>$limit,'count'=>$data['count'],'returned_ids'=>array_column($expected,'id'),'explicit_defaults_verified'=>$defaults];
}

function m5h_accounts(string $v2,array $ids): array
{
    $accounts=[]; foreach ($ids as $id) { $key=$v2.'account:'.$id; $row=audit_redis()->hGetAll($key); ksort($row);
        audit_expect(($row['initialized'] ?? '')==='1' && ($row['blocked'] ?? '')==='0' && !isset($row['owner']) && preg_match('/^[a-f0-9]{32}$/D',$row['session_version'] ?? '')===1 && audit_redis()->pttl($key)===-1,'normal current account is initialized and persistent'); $accounts[(int)$id]=$row; }
    return $accounts;
}

function m5h_rotated(string $v2,array $before,array &$evidence,string $operation): array
{
    $after=m5h_accounts($v2,array_keys($before));
    foreach ($before as $id=>$row) { audit_expect($after[$id]['session_version']!==$row['session_version'],'normal identity or permission change rotates current server version');
        $expected=$row; $expected['session_version']=$after[$id]['session_version']; audit_expect($after[$id]===$expected,'normal account completion changes only server version'); }
    $evidence['version_observations'][]=['operation'=>$operation,'affected_employee_ids'=>array_keys($before),'changed_and_unblocked'=>true,'old_token_requests'=>0]; return $after;
}

function m5h_discover(PDO $pdo,array $plannedRoles,array $plannedEmployees,array &$owners): void
{
    foreach ($plannedRoles as $plan) foreach ($plan['names'] as $name) {
        $stmt=$pdo->prepare('SELECT id,mer_id,name FROM eb_marketplace_role WHERE mer_id=? AND name=?'); $stmt->execute([$plan['mer_id'],$name]);
        foreach ($stmt->fetchAll() as $row) { $id=(int)$row['id']; $owners['role'][$id] ??= ['id'=>$id,'mer_id'=>$plan['mer_id'],'names'=>[]]; $owners['role'][$id]['names']=array_values(array_unique(array_merge($owners['role'][$id]['names'],$plan['names']))); }
    }
    foreach ($plannedEmployees as $plan) foreach ($plan['accounts'] as $account) {
        $stmt=$pdo->prepare('SELECT id,mer_id,account FROM eb_marketplace_employee WHERE mer_id=? AND account=?'); $stmt->execute([$plan['mer_id'],$account]);
        foreach ($stmt->fetchAll() as $row) { $id=(int)$row['id']; $owners['employee'][$id] ??= ['id'=>$id,'mer_id'=>$plan['mer_id'],'accounts'=>[]]; $owners['employee'][$id]['accounts']=array_values(array_unique(array_merge($owners['employee'][$id]['accounts'],$plan['accounts']))); }
    }
    foreach ($owners['employee'] as $owner) {
        $stmt=$pdo->prepare('SELECT id,mer_id,employee_id,token_hash FROM eb_marketplace_password_setup WHERE mer_id=? AND employee_id=?'); $stmt->execute([$owner['mer_id'],$owner['id']]);
        foreach ($stmt->fetchAll() as $row) { $id=(int)$row['id']; $owners['password_setup'][$id]=['id'=>$id,'mer_id'=>(int)$row['mer_id'],'employee_id'=>(int)$row['employee_id'],'token_hash'=>$row['token_hash']]; }
    }
}

function m5h_session_cleanup_keys(string $v2,array $owners,array &$ownedKeys): void
{
    $ids=array_map('strval',array_keys($owners['employee'])); foreach ($ids as $id) $ownedKeys[]=$v2.'account:'.$id;
    foreach (m5h_scan($v2.'session:*') as $key) {
        if (preg_match('/^'.preg_quote($v2.'session:','/').'[a-f0-9]{32}$/D',$key)!==1 || audit_redis()->type($key)!==Redis::REDIS_HASH) continue;
        $row=audit_redis()->hGetAll($key); if (in_array($row['subject_id'] ?? '',$ids,true) && ($row['type'] ?? '')==='merchant' && ($row['audience'] ?? '')==='tianli:merchantapi') $ownedKeys[]=$key;
    }
}

function m5h_cleanup(PDO $pdo,array $owners,array $ownedKeys): int
{
    foreach (['password_setup','employee','role','merchant'] as $table) foreach (array_reverse($owners[$table],true) as $owner) {
        if ($table==='password_setup') { $sql='DELETE FROM eb_marketplace_password_setup WHERE id=? AND mer_id=? AND employee_id=? AND token_hash=?'; $args=[$owner['id'],$owner['mer_id'],$owner['employee_id'],$owner['token_hash']]; }
        elseif ($table==='merchant') { $sql='DELETE FROM eb_marketplace_merchant WHERE mer_id=? AND name IN ('.implode(',',array_fill(0,count($owner['names']),'?')).')'; $args=array_merge([$owner['id']],$owner['names']); }
        else { $column=$table==='employee' ? 'account' : 'name'; $values=$owner[$table==='employee' ? 'accounts' : 'names'];
            $sql='DELETE FROM eb_marketplace_'.$table.' WHERE id=? AND mer_id=? AND `'.$column.'` IN ('.implode(',',array_fill(0,count($values),'?')).')'; $args=array_merge([$owner['id'],$owner['mer_id']],$values); }
        $stmt=$pdo->prepare($sql); $stmt->execute($args);
    }
    $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0;
    audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys),'precise key cleanup has a native count');
    foreach ($keys as $key) audit_expect(audit_redis()->exists($key)===0,'precise owned key cleanup completes'); return $removed;
}

audit_case('T5G01','normal_employee_role_management','INTEGRATION',static function(): array {
    $evidence=['run_id'=>getenv('AUDIT_RUN_ID'),'requests'=>[],'observations'=>[],'negative_cases_run'=>false,'old_token_requests'=>0];
    $owners=['merchant'=>[],'role'=>[],'employee'=>[],'password_setup'=>[]]; $subjects=[]; $ownedKeys=[]; $secrets=[];
    $plannedRoles=[]; $plannedEmployees=[]; $failure=null; $cleanupFailure=null; $pdo=null; $v2=null; $started=false;
    $counterTables=['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup'];
    try {
        $initial=m5h_snapshot(); $evidence['before']=m5h_public_snapshot($initial);
        foreach (['crmeb/app/merchantapi/controller/Employee.php','crmeb/app/merchantapi/controller/Role.php',
            'crmeb/app/services/marketplace/EmployeeServices.php','crmeb/app/services/marketplace/RoleServices.php',
            'crmeb/app/services/marketplace/MerchantManagementInput.php'] as $file) audit_expect(is_file(audit_repo_dir().'/'.$file),'Task5 management implementation unavailable');
        audit_expect(count($initial['names'])===163,'normal management requires the installed base and five identity tables');
        foreach (array_merge($counterTables,['eb_marketplace_audit_log']) as $table) audit_expect(($initial['rows'][$table]['count'] ?? -1)===0,'all identity tables must start empty');
        $config=audit_config(); $secrets=[$config['signing_key'],$config['auth']['stamp_key'],$config['redis_password'],$config['database']['password']];
        $prefix='audit:http-lang:'.getenv('AUDIT_RUN_ID').':'.gmdate('YmdHis').random_int(100000,999999).':'; $v2='auth:v2:'.$config['auth']['session_epoch'].':merchantapi:';
        audit_expect(m5h_scan($prefix.'*')===[] && m5h_scan($v2.'*')===[],'new normal language and merchant namespaces begin empty');
        $app=m5h_app($config,$prefix); m5h_routes($app,$evidence); m5h_warm_language($app,$prefix,$ownedKeys,$evidence);
        $slug='ms1_manage_'.substr(hash('sha256',(string)getenv('AUDIT_RUN_ID')),0,12); $pdo=audit_db();
        m5h_seeds($pdo,$slug,$subjects,$owners,$secrets); $state=m5h_identity_rows(); $started=true; audit_app_request('start',['CACHE_PREFIX'=>$prefix]);
        $admins=[];
        foreach (['A','B'] as $label) {
            m5h_setup($subjects[$label],$state,$config,$v2,$ownedKeys,$secrets,$evidence);
            $admins[$label]=m5h_login($subjects[$label],$config,$v2,$ownedKeys,$secrets,$evidence,$app);
            m5h_list('employee',$subjects[$label],$admins[$label],$evidence,1,20,null,null,true);
            m5h_list('role',$subjects[$label],$admins[$label],$evidence,1,20,null,null,true);
        }
        audit_expect(m5h_identity_rows()===$state,'normal administrator preparation and own lists preserve identity data');

        $roleName=$slug.'_role'; $roleRenamed=$slug.'_role_updated'; $roleBoundName=$slug.'_role_bound';
        $plannedRoles[]=['mer_id'=>$subjects['A']['mer_id'],'names'=>[$roleName,$roleRenamed,$roleBoundName]];
        $newPermissions=['merchant.profile.read','product.read'];
        $before=m5h_snapshot(); $start=time(); $data=m5h_http('POST','/merchantapi/roles',$admins['A']['token'],['name'=>'  '.$roleName.'  ','permissions'=>$newPermissions],$evidence); $end=time();
        $stmt=$pdo->prepare('SELECT id FROM eb_marketplace_role WHERE mer_id=? AND name=?'); $stmt->execute([$subjects['A']['mer_id'],$roleName]); $roleIds=$stmt->fetchAll(PDO::FETCH_COLUMN);
        audit_expect(count($roleIds)===1,'normal created role has one exact owned source identity'); $roleId=(int)$roleIds[0];
        $owners['role'][$roleId]=['id'=>$roleId,'mer_id'=>$subjects['A']['mer_id'],'names'=>[$roleName,$roleRenamed,$roleBoundName]];
        $state=m5h_change($state,'role',$roleId,['id'=>$roleId,'mer_id'=>$subjects['A']['mer_id'],'name'=>$roleName,'permissions'=>$newPermissions],$start,$end,true);
        m5h_role_dto($data,$state['role'][$roleId]); m5h_same($before,m5h_snapshot(),['eb_marketplace_role'],[],['eb_marketplace_role']);

        $newPermissions=['merchant.profile.read','product.read','product.write']; $before=m5h_snapshot(); $start=time();
        $data=m5h_http('PUT','/merchantapi/roles/'.$roleId,$admins['A']['token'],['name'=>$roleRenamed,'permissions'=>$newPermissions],$evidence); $end=time();
        $state=m5h_change($state,'role',$roleId,['name'=>$roleRenamed,'permissions'=>$newPermissions],$start,$end);
        m5h_role_dto($data,$state['role'][$roleId]); m5h_same($before,m5h_snapshot(),['eb_marketplace_role']);

        $createdAccount=$slug.'_employee'; $newAccount=$slug.'_employee_updated';
        $plannedEmployees[]=['mer_id'=>$subjects['A']['mer_id'],'accounts'=>[$createdAccount,$newAccount]];
        $createdName='Management employee'; $createdAvatar='/statics/ms1-management-employee.png';
        $before=m5h_snapshot(); $start=time();
        $data=m5h_http('POST','/merchantapi/employees',$admins['A']['token'],['account'=>'  '.strtoupper($createdAccount).'  ','role_id'=>$roleId,'name'=>$createdName,'avatar'=>$createdAvatar],$evidence); $end=time();
        $stmt=$pdo->prepare('SELECT id FROM eb_marketplace_employee WHERE mer_id=? AND account=?'); $stmt->execute([$subjects['A']['mer_id'],$createdAccount]); $employeeIds=$stmt->fetchAll(PDO::FETCH_COLUMN);
        audit_expect(count($employeeIds)===1,'normal created employee has one exact owned account identity'); $employeeId=(int)$employeeIds[0];
        $owners['employee'][$employeeId]=['id'=>$employeeId,'mer_id'=>$subjects['A']['mer_id'],'accounts'=>[$createdAccount,$newAccount]];
        $keys=array_keys($data); sort($keys); audit_expect($keys===['employee','password_setup'] && is_array($data['employee']) && is_array($data['password_setup']),'normal employee creation returns exactly employee and its setup delivery');
        $delivery=$data['password_setup']; $keys=array_keys($delivery); sort($keys); audit_expect($keys===['endpoint','expires_at','token'] && $delivery['endpoint']==='/merchantapi/password/setup'
            && is_string($delivery['token']) && is_int($delivery['expires_at']),'normal capability delivery contains only its contracted fields');
        m5h_valid_capability($delivery['token']); $secrets=array_merge($secrets,[$delivery['token'],hash('sha256',$delivery['token']),hash('sha256',$delivery['token'],true)]);
        $stmt=$pdo->prepare('SELECT * FROM eb_marketplace_password_setup WHERE mer_id=? AND employee_id=?'); $stmt->execute([$subjects['A']['mer_id'],$employeeId]); $setupRows=$stmt->fetchAll();
        audit_expect(count($setupRows)===1,'new employee receives exactly one owned setup record'); $setup=$setupRows[0]; $setupId=(int)$setup['id'];
        $owners['password_setup'][$setupId]=['id'=>$setupId,'mer_id'=>$subjects['A']['mer_id'],'employee_id'=>$employeeId,'token_hash'=>$setup['token_hash']];
        audit_expect($setup['token_hash']===hash('sha256',$delivery['token'],true) && $setup['consumed_at']===null && (int)$setup['add_time']>=$start && (int)$setup['add_time']<=$end
            && (int)$setup['expires_at']===(int)$setup['add_time']+86400 && (int)$setup['expires_at']===$delivery['expires_at'],'normal delivery agrees with exact32-byte hash and24-hour DB expiry');
        $stateWithSetup=$state; $stateWithSetup['password_setup'][$setupId]=$setup; ksort($stateWithSetup['password_setup'],SORT_NUMERIC);
        $state=m5h_change($stateWithSetup,'employee',$employeeId,['id'=>$employeeId,'mer_id'=>$subjects['A']['mer_id'],'role_id'=>$roleId,'account'=>$createdAccount,
            'pwd'=>null,'name'=>$createdName,'avatar'=>$createdAvatar,'status'=>1,'is_del'=>0],$start,$end,true);
        m5h_employee_dto($data['employee'],$state['employee'][$employeeId]); m5h_same($before,m5h_snapshot(),['eb_marketplace_employee','eb_marketplace_password_setup'],[],['eb_marketplace_employee','eb_marketplace_password_setup']);
        audit_expect(audit_redis()->exists($v2.'account:'.$employeeId)===0,'creating a normal employee does not preinitialize its authorization version');
        $password='M5h-'.bin2hex(random_bytes(20)).'-x'; $secrets[]=$password;
        $subjects['C']=['label'=>'C','mer_id'=>$subjects['A']['mer_id'],'merchant_name'=>$subjects['A']['merchant_name'],'role_id'=>$roleId,'role_name'=>$roleRenamed,
            'employee_id'=>$employeeId,'setup_id'=>$setupId,'account'=>$createdAccount,'name'=>$createdName,'avatar'=>$createdAvatar,'permissions'=>$newPermissions,'cap'=>$delivery['token'],'password'=>$password];
        m5h_setup($subjects['C'],$state,$config,$v2,$ownedKeys,$secrets,$evidence);
        $employeeLogin=m5h_login($subjects['C'],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m5h_me($subjects['C'],$employeeLogin,$evidence);
        m5h_list('employee',$subjects['A'],$admins['A'],$evidence); m5h_list('role',$subjects['A'],$admins['A'],$evidence);

        // A bound-role name change is an ordinary write with no version rotation.
        $before=m5h_snapshot(); $start=time(); $data=m5h_http('PUT','/merchantapi/roles/'.$roleId,$admins['A']['token'],['name'=>$roleBoundName],$evidence); $end=time();
        $state=m5h_change($state,'role',$roleId,['name'=>$roleBoundName],$start,$end); m5h_role_dto($data,$state['role'][$roleId]); m5h_same($before,m5h_snapshot(),['eb_marketplace_role']);
        $subjects['C']['role_name']=$roleBoundName;
        usleep(1100000); $before=m5h_snapshot();
        $data=m5h_http('PUT','/merchantapi/roles/'.$roleId,$admins['A']['token'],
            ['name'=>$roleBoundName,'permissions'=>array_reverse($newPermissions)],$evidence);
        m5h_role_dto($data,$state['role'][$roleId]);
        audit_expect(m5h_identity_rows()===$state,'valid role permission-order no-op preserves original rows and times');
        m5h_same($before,m5h_snapshot());
        $evidence['valid_noops'][]=['resource'=>'role_permission_order','id'=>$roleId,'rows_times_and_versions_unchanged'=>true];
        $before=m5h_snapshot(); $start=time(); $updatedName='Management employee updated'; $updatedAvatar='/statics/ms1-management-employee-updated.png';
        $data=m5h_http('PUT','/merchantapi/employees/'.$employeeId,$admins['A']['token'],['name'=>$updatedName,'avatar'=>$updatedAvatar],$evidence); $end=time();
        $state=m5h_change($state,'employee',$employeeId,['name'=>$updatedName,'avatar'=>$updatedAvatar],$start,$end); m5h_employee_dto($data,$state['employee'][$employeeId]); m5h_same($before,m5h_snapshot(),['eb_marketplace_employee']);
        $subjects['C']['name']=$updatedName; $subjects['C']['avatar']=$updatedAvatar;
        // Let the real clock advance so an unnecessary timestamp write is visible.
        usleep(1100000); $before=m5h_snapshot();
        $data=m5h_http('PUT','/merchantapi/employees/'.$employeeId,$admins['A']['token'],['account'=>$createdAccount,'role_id'=>$roleId,'name'=>$updatedName,'avatar'=>$updatedAvatar],$evidence);
        m5h_employee_dto($data,$state['employee'][$employeeId]); audit_expect(m5h_identity_rows()===$state,'valid employee no-op preserves all rows and times'); m5h_same($before,m5h_snapshot());
        $evidence['valid_noops'][]=['resource'=>'employee','id'=>$employeeId,'rows_times_and_versions_unchanged'=>true];

        $before=m5h_snapshot(); $prior=m5h_accounts($v2,[$employeeId]); $start=time();
        $data=m5h_http('PUT','/merchantapi/employees/'.$employeeId,$admins['A']['token'],['account'=>'  '.strtoupper($newAccount).'  ','role_id'=>$subjects['A']['role_id']],$evidence); $end=time();
        $state=m5h_change($state,'employee',$employeeId,['account'=>$newAccount,'role_id'=>$subjects['A']['role_id']],$start,$end); m5h_employee_dto($data,$state['employee'][$employeeId]);
        m5h_rotated($v2,$prior,$evidence,'normal_account_and_same_merchant_role_change'); m5h_same($before,m5h_snapshot(),['eb_marketplace_employee'],[$v2.'account:'.$employeeId]);
        $subjects['C']['account']=$newAccount; $subjects['C']['role_id']=$subjects['A']['role_id']; $subjects['C']['role_name']=$subjects['A']['role_name']; $subjects['C']['permissions']=$subjects['A']['permissions'];
        $employeeLogin=m5h_login($subjects['C'],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m5h_me($subjects['C'],$employeeLogin,$evidence);
        m5h_list('employee',$subjects['A'],$admins['A'],$evidence,1,1,1,$subjects['A']['role_id']);
        m5h_list('employee',$subjects['A'],$admins['A'],$evidence,2,1,1,$subjects['A']['role_id']);
        m5h_list('role',$subjects['A'],$admins['A'],$evidence,2,1);

        $before=m5h_snapshot(); $prior=m5h_accounts($v2,[$employeeId]); $start=time();
        $data=m5h_http('PUT','/merchantapi/employees/'.$employeeId.'/status',$admins['A']['token'],['status'=>0],$evidence); $end=time();
        $state=m5h_change($state,'employee',$employeeId,['status'=>0],$start,$end); m5h_employee_dto($data,$state['employee'][$employeeId]);
        m5h_rotated($v2,$prior,$evidence,'normal_admin_disable'); m5h_same($before,m5h_snapshot(),['eb_marketplace_employee'],[$v2.'account:'.$employeeId]);
        // Only the current administrator reads the disabled employee; no employee request.
        m5h_list('employee',$subjects['A'],$admins['A'],$evidence,1,20,0,null);
        audit_expect((int)m5h_identity_rows()['employee'][$employeeId]['status']===0,'normal admin list agrees with disabled DB state');
        $before=m5h_snapshot(); $prior=m5h_accounts($v2,[$employeeId]); $start=time();
        $data=m5h_http('PUT','/merchantapi/employees/'.$employeeId.'/status',$admins['A']['token'],['status'=>1],$evidence); $end=time();
        $state=m5h_change($state,'employee',$employeeId,['status'=>1],$start,$end); m5h_employee_dto($data,$state['employee'][$employeeId]);
        m5h_rotated($v2,$prior,$evidence,'normal_admin_enable'); m5h_same($before,m5h_snapshot(),['eb_marketplace_employee'],[$v2.'account:'.$employeeId]);
        $employeeLogin=m5h_login($subjects['C'],$config,$v2,$ownedKeys,$secrets,$evidence,$app); m5h_me($subjects['C'],$employeeLogin,$evidence);
        usleep(1100000); $before=m5h_snapshot();
        $data=m5h_http('PUT','/merchantapi/employees/'.$employeeId.'/status',$admins['A']['token'],['status'=>1],$evidence);
        m5h_employee_dto($data,$state['employee'][$employeeId]); audit_expect(m5h_identity_rows()===$state,'valid enabled-status no-op preserves all rows and times'); m5h_same($before,m5h_snapshot());
        $evidence['valid_noops'][]=['resource'=>'employee_status','id'=>$employeeId,'rows_times_and_versions_unchanged'=>true];

        $finalPermissions=array_values(array_diff(\app\services\marketplace\MerchantPermissions::ALL,['freight.manage']));
        $affectedIds=[$subjects['A']['employee_id'],$employeeId]; sort($affectedIds,SORT_NUMERIC); $before=m5h_snapshot(); $prior=m5h_accounts($v2,$affectedIds); $start=time();
        $data=m5h_http('PUT','/merchantapi/roles/'.$subjects['A']['role_id'],$admins['A']['token'],['permissions'=>$finalPermissions],$evidence); $end=time();
        $state=m5h_change($state,'role',$subjects['A']['role_id'],['permissions'=>$finalPermissions],$start,$end); m5h_role_dto($data,$state['role'][$subjects['A']['role_id']]);
        $currentAccounts=m5h_rotated($v2,$prior,$evidence,'normal_bound_role_permissions_including_current_actor');
        $keys=array_map(static fn(int $id): string=>$v2.'account:'.$id,$affectedIds); m5h_same($before,m5h_snapshot(),['eb_marketplace_role'],$keys);
        // Both affected old sessions are retired from this script's request flow.
        $subjects['A']['permissions']=$finalPermissions; $subjects['C']['permissions']=$finalPermissions;
        $admins['A']=m5h_login($subjects['A'],$config,$v2,$ownedKeys,$secrets,$evidence,$app);
        $employeeLogin=m5h_login($subjects['C'],$config,$v2,$ownedKeys,$secrets,$evidence,$app);
        audit_expect($admins['A']['claims']['session_version']===$currentAccounts[$subjects['A']['employee_id']]['session_version']
            && $employeeLogin['claims']['session_version']===$currentAccounts[$employeeId]['session_version'],'new credential logins retain the actual newly rotated account versions');
        m5h_me($subjects['A'],$admins['A'],$evidence); m5h_me($subjects['C'],$employeeLogin,$evidence);
        m5h_list('employee',$subjects['A'],$admins['A'],$evidence); m5h_list('role',$subjects['A'],$admins['A'],$evidence);
        m5h_list('employee',$subjects['B'],$admins['B'],$evidence); m5h_list('role',$subjects['B'],$admins['B'],$evidence);
        audit_expect(m5h_identity_rows()===$state,'final normal current reads preserve the verified identity state');
        audit_expect(count($evidence['requests'])===41,'complete declared normal management flow has forty-one successful HTTP requests');
        $evidence['observations']=['merchant_ids'=>[$subjects['A']['mer_id'],$subjects['B']['mer_id']],
            'administrator_employee_ids'=>[$subjects['A']['employee_id'],$subjects['B']['employee_id']],'created_employee_id'=>$employeeId,'created_role_id'=>$roleId,'created_setup_id'=>$setupId,
            'created_capability_exact_hash_and_expiry_verified'=>true,'profile_and_bound_role_name_do_not_rotate'=>true,'valid_noop_count'=>3,
            'updated_account_and_same_merchant_role_verified'=>true,'disabled_phase_employee_requests'=>0,'bound_permission_member_count'=>count($affectedIds),
            'fresh_latest_permissions'=>count($finalPermissions),'normal_actor_self_protection_completed'=>true,'old_token_requests'=>0];
    } catch (Throwable $error) { $failure=m5h_diag($error); $evidence['diagnostic']=$failure; }
    finally {
        if ($started) { try { audit_app_request('stop'); $evidence['app_stopped']=true; } catch (Throwable $error) { $cleanupFailure=m5h_diag($error); $evidence['stop_diagnostic']=$cleanupFailure; } }
        try {
            if ($pdo instanceof PDO) {
                // Only the operation's exact unique names/accounts identify a lost reply.
                m5h_discover($pdo,$plannedRoles,$plannedEmployees,$owners);
                if ($v2!==null) m5h_session_cleanup_keys($v2,$owners,$ownedKeys);
                $removed=m5h_cleanup($pdo,$owners,$ownedKeys);
            } else {
                $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0;
                audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys),'precise preparation cleanup receives a native count');
                foreach ($keys as $key) audit_expect(audit_redis()->exists($key)===0,'precise preparation cleanup completes');
            }
            $evidence['owned_keys_registered_count']=count(array_unique($ownedKeys)); $evidence['owned_keys_removed_count']=$removed;
        } catch (Throwable $error) { $cleanupFailure ??= m5h_diag($error); $evidence['cleanup_diagnostic']=m5h_diag($error); }
    }
    if (isset($initial)) {
        try {
            $after=m5h_snapshot(); $evidence['after']=m5h_public_snapshot($after); $hasRows=array_sum(array_map('count',$owners))>0;
            m5h_same($initial,$after,[],[],$hasRows ? $counterTables : []); audit_guard_assert();
            $evidence['preservation']=['tables'=>count($initial['names']),'original_rows_schema_checksums_unchanged'=>true,'cache_values_and_absolute_expiry_unchanged'=>true,'counter_changes_allowed_only'=>$hasRows ? $counterTables : []];
            audit_expect(m5h_logs_private($secrets),'ordinary runtime logs contain no private material'); $evidence['private_logs_verified_in_normal_configuration']=true;
        } catch (Throwable $error) { $failure ??= m5h_diag($error); $evidence['preservation_diagnostic']=m5h_diag($error); }
    }
    $evidence['fixtures_cleaned']=array_sum(array_map('count',$owners))>0 && $cleanupFailure===null;
    $evidence['owned_row_ids']=array_map(static fn(array $rows): array=>array_keys($rows),$owners);
    $ref='evidence/ms1-management-'.(getenv('AUDIT_PHASE') ?: 'after').'.json'; $serialized=json_encode($evidence,JSON_THROW_ON_ERROR);
    foreach ($secrets as $secret) audit_expect($secret==='' || !str_contains($serialized,$secret),'ordinary evidence contains no private material');
    audit_write_json(audit_run_dir().'/'.$ref,$evidence); $passed=$failure===null && $cleanupFailure===null;
    return ['status'=>$passed ? 'PASS' : 'FAIL','actual'=>$passed ? 'valid administrator employee/role flows, current identity and version changes, precise cleanup and original state preserved' : ($failure ?? $cleanupFailure)['class'].'@'.($failure ?? $cleanupFailure)['file'].':'.($failure ?? $cleanupFailure)['line'],
        'expected'=>'normal Task5 owned management only','evidence_ref'=>$ref,'notes'=>'no denied actor/foreign target/invalid permission/old-token request/fault; ordinary result does not replace original M02 acceptance'];
});

foreach ([['M02','operator_employee_create'],['M02','operator_role_update'],['M02','operator_own_role_change'],['M02','operator_merchant_change'],
    ['M02','foreign_employee_target'],['M02','invalid_role_permission_code']] as [$id,$subcase]) {
    audit_case($id,$subcase,'INTEGRATION',static fn(): array=>['status'=>'NOT RUN','actual'=>'skipped at user request; no denied actor, foreign target or invalid permission request executed','expected'=>'original M02 refusal acceptance remains unverified']);
}
audit_finish();
