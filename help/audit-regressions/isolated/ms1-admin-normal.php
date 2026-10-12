<?php
declare(strict_types=1);

/* Independent current-v2 normal HTTP entry. No other regression suite is loaded. */
require __DIR__ . '/lib/harness.php';
if ((audit_manifest()['guard_mode'] ?? '') !== 'read-only') {
    fwrite(STDERR, "BLOCKED normal admin entry requires the read-only ISO guard\n"); exit(2);
}
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
audit_expect(count($argv) === 1, 'normal admin entry has no case-selection or alternate-suite arguments');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';

function a9_state(array $ownedRows = []): array
{
    $spec = ['tables'=>[], 'redis'=>['*']]; $pdo = audit_db();
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        audit_expect(preg_match('/^(eb_[a-z0-9_]+|audit_sentinel)$/D', $table) === 1, 'actual table identity differs');
        $ids = $ownedRows[$table] ?? [];
        $spec['tables'][$table] = $ids ? 'id NOT IN ('.implode(',', array_map('intval', $ids)).')' : null;
    }
    audit_expect(in_array(count($spec['tables']), [158,163], true), 'complete installed 158 or migrated 163 table inventory is required');
    $marketplace=['eb_marketplace_merchant','eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup','eb_marketplace_audit_log'];
    audit_expect(count($spec['tables'])===158 ? array_intersect($marketplace,array_keys($spec['tables']))===[] : array_diff($marketplace,array_keys($spec['tables']))===[], 'actual full inventory must be installed158 or the precise five-table MS1 migration');
    $state = audit_snapshot($spec); $state['ddl'] = $state['checksums'] = [];
    foreach (array_keys($spec['tables']) as $table) {
        $ddl = (string)$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $counter = preg_match('/\) ENGINE=InnoDB AUTO_INCREMENT=([0-9]+) /', $ddl, $match) ? (int)$match[1] : null;
        $state['ddl'][$table] = ['hash'=>hash('sha256',$ddl), 'without_counter'=>hash('sha256',preg_replace('/(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+ /','$1 ',$ddl)), 'counter'=>$counter];
        if (!$spec['tables'][$table]) $state['checksums'][$table] = $pdo->query('CHECKSUM TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    }
    $keys = array_keys($state['redis']['*']); $state['expires'] = [];
    if ($keys) {
        $lua="local t=redis.call('TIME');local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000);local r={};for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k);if p>=0 then r[i]=n+p else r[i]=p end end;return r";
        $times = audit_redis()->eval($lua,$keys,count($keys));
        audit_expect(is_array($times) && count($times) === count($keys), 'absolute expiry observation differs');
        foreach ($keys as $i=>$key) { audit_expect(is_int($times[$i]), 'absolute expiry must be integral'); $state['expires'][$key]=$times[$i]; }
    }
    $state['runtime'] = [];
    foreach (['/run/audit-app-runtime',audit_repo_dir().'/crmeb/runtime'] as $root) {
        $files=[];
        if (is_dir($root)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) {
            audit_expect(!$file->isLink(), 'runtime symbolic link differs');
            if ($file->isFile()) $files[substr($file->getPathname(),strlen($root)+1)] = [filesize($file->getPathname()),hash_file('sha256',$file->getPathname())];
        }
        ksort($files); $state['runtime'][]=$files;
    }
    return $state;
}
function a9_preserve(array $before,array $after,array $ownedKeys,bool $clean = false): void
{
    audit_expect(array_keys($before['tables']) === array_keys($after['tables']), 'full table inventory changed');
    foreach ($before['tables'] as $table=>$rows) {
        audit_expect($rows === $after['tables'][$table], 'original table rows changed');
        $counterTable=in_array($table,['eb_system_admin','eb_system_role','eb_system_log'],true);
        audit_expect($before['ddl'][$table][$counterTable?'without_counter':'hash'] === $after['ddl'][$table][$counterTable?'without_counter':'hash'], 'original table schema changed');
        if (array_key_exists($table,$after['checksums']) && (!$counterTable || $clean)) audit_expect($before['checksums'][$table] === $after['checksums'][$table], 'original checksum changed');
        $a=$before['ddl'][$table]['counter']; $b=$after['ddl'][$table]['counter'];
        if ($counterTable && $a !== null && $b !== null) audit_expect($b >= $a, 'natural synthetic counters may not be rolled back');
    }
    foreach (array_unique(array_merge(array_keys($before['redis']['*']),array_keys($after['redis']['*']))) as $key) {
        if (!$clean && in_array($key,$ownedKeys,true)) continue;
        audit_expect(($before['redis']['*'][$key]??null) === ($after['redis']['*'][$key]??null), 'unrelated Redis value/inventory changed');
        $a=$before['expires'][$key]??-2; $b=$after['expires'][$key]??-2;
        audit_expect(($a<0 || $b<0) ? $a===$b : abs($a-$b)<=1, 'unrelated absolute Redis expiry changed');
    }
    audit_expect($before['runtime'] === $after['runtime'], 'original runtime files changed');
}
function a9_row(string $table,int $id): array
{
    audit_expect(in_array($table,['system_admin','system_role'],true) && $id>0, 'exact synthetic row target differs');
    $q=audit_db()->prepare('SELECT * FROM eb_'.$table.' WHERE id=?'); $q->execute([$id]); $row=$q->fetch();
    audit_expect(is_array($row), 'owned synthetic row is missing'); return $row;
}
function a9_cache(array $context): void
{
    $redis=audit_redis(); $present=[];
    foreach ($context['cache_values'] as $key=>$rule) {
        if (!$redis->exists($key)) continue;
        $value=is_numeric($rule['value']) ? (string)$rule['value'] : serialize($rule['value']);
        audit_expect($redis->type($key)===Redis::REDIS_STRING && $redis->get($key)===$value, 'exact cache differs from real source');
        $ttl=$redis->ttl($key); audit_expect($rule['ttl']===0 ? $ttl===-1 : $ttl>0 && $ttl<=3600, 'ordinary source cache lifetime differs');
        audit_expect(!$redis->exists($key.'_lock'), 'real ordinary cache producer left its temporary lock'); $present[]=$key;
    }
    if ($redis->exists($context['tag'])) {
        $members=$redis->sMembers($context['tag']); sort($members); sort($present);
        audit_expect($members===$present && $redis->ttl($context['tag'])===-1, 'exact source cache tag differs');
    } else audit_expect($present===[], 'tagged source values require their actual tag');
}
function a9_http(string $method,string $path,?string $token,?array $body,array &$context,array &$evidence): array
{
    $allowed=['POST /adminapi/login','GET /adminapi/product/product/rule','GET /adminapi/menusList','GET /adminapi/menus',
        'GET /adminapi/setting/menus/unique','GET /adminapi/setting/info','PUT /adminapi/setting/update_admin','GET /adminapi/setting/admin/logout','GET /adminapi/login/info'];
    audit_expect(in_array($method.' '.$path,$allowed,true), 'only fixed valid normal admin routes are available');
    audit_guard_assert(); $start=time(); $headers=['Cb-lang'=>'zh-CN'];
    if ($token!==null) $headers['Authori-zation']='Bearer '.$token;
    $response=audit_http($method,'http://audit-app:8000'.$path,$headers,$body); $end=time();
    $captureKey=$path==='/adminapi/login/info' ? 'readiness_requests' : 'requests';
    $evidence[$captureKey][]=['method'=>$method,'path'=>$path,'http'=>$response['http'],'business_status'=>$response['json']['status']??null];
    if ($token!==null) {
        $q=audit_db()->prepare('SELECT * FROM eb_system_log WHERE admin_id=? AND admin_name=? ORDER BY id'); $q->execute([$context['admin_id'],$context['account']]);
        $new=array_filter($q->fetchAll(),static fn(array $r): bool=>!isset($context['logs'][(int)$r['id']]));
        // Claim every actual row of this new exact synthetic actor before any
        // quantity/field assertion, so an ordinary failure can still clean it.
        foreach ($new as $row) { $context['logs'][(int)$row['id']]=$row; $context['owned_rows']['eb_system_log'][]=(int)$row['id']; }
        audit_expect(count($new)===1, 'each authenticated normal request must produce its one actual visit log');
        $row=array_values($new)[0]; $rule=strtolower(substr($path,strlen('/adminapi/')));
        audit_expect($row['path']===$rule && $row['method']==='adminapi' && $row['type']==='system' && (int)$row['add_time']>=$start && (int)$row['add_time']<=$end
            && $row['ip']===gethostbyname(gethostname()), 'actual visit log source/time/identity differs');
    }
    $json=$response['json'];
    audit_expect($response['http']===200 && is_array($json) && ($json['status']??null)===200 && is_string($json['msg']??null), 'real normal HTTP and business status must both be200');
    $noData=($method==='PUT' && $path==='/adminapi/setting/update_admin') || ($method==='GET' && $path==='/adminapi/setting/admin/logout');
    $shape=array_keys($json); sort($shape);
    if ($noData) { audit_expect($shape===['msg','status'] && !array_key_exists('data',$json), 'normal own write/logout success must omit data exactly as Json::make'); return []; }
    audit_expect($shape===['data','msg','status'] && is_array($json['data']), 'normal read/login success must contain its exact array DTO');
    return $json['data'];
}
function a9_login(string $password,array &$context,array &$evidence): array
{
    $before=a9_row('system_admin',$context['admin_id']); $start=time();
    $data=a9_http('POST','/adminapi/login',null,['account'=>$context['account'],'pwd'=>$password,'key'=>''],$context,$evidence);
    $token=$data['token']??null; audit_expect(is_string($token) && $token!=='', 'real current login must return its own token'); $context['secrets'][]=$token;
    $claims=(array)\Firebase\JWT\JWT::decode($token,$context['config']['signing_key'],['HS256']); $id=(string)$context['admin_id'];
    $binding=\crmeb\services\AuthConfigGuard::authConfig()['apps']['adminapi'];
    audit_expect(($claims['auth_schema']??null)===2 && ($claims['session_epoch']??null)===$context['config']['auth']['session_epoch'] && ($claims['iss']??null)===$context['config']['auth']['issuer']
        && ($claims['aud']??null)===$binding['audience'] && ($claims['jti']->type??null)===$binding['type'] && ($claims['jti']->id??null)===$id
        && preg_match('/^[a-f0-9]{32}$/D',$claims['sid']??'')===1 && preg_match('/^[a-f0-9]{32}$/D',$claims['session_version']??'')===1
        && is_int($claims['iat']??null) && ($claims['nbf']??null)===$claims['iat'] && ($claims['exp']??0)-$claims['iat']===$binding['ttl'] && ($data['expires_time']??null)===$claims['exp'], 'actual current v2 login binding differs');
    foreach (['pwd','password','appsecret','credential_stamp','token_digest','stamp_key'] as $name) audit_expect(!isset($claims[$name]) && !isset($claims['jti']->$name) && !array_key_exists($name,$data['user_info']??[]), 'normal identity must omit private fields');
    $session=$context['v2'].'session:'.$claims['sid']; audit_expect(!isset($context['baseline']['redis']['*'][$session]), 'new signed session must not preexist'); $context['owned_keys'][]=$session;
    $after=a9_row('system_admin',$context['admin_id']); $end=time();
    foreach ($before as $name=>$value) if (!in_array($name,['last_time','last_ip','login_count'],true)) audit_expect($after[$name]===$value, 'normal login modified another subject column');
    audit_expect((int)$after['login_count']===(int)$before['login_count']+1 && (int)$after['last_time']>=$start && (int)$after['last_time']<=$end && $after['last_ip']===gethostbyname(gethostname()), 'actual successful login statistics differ');
    $expected=['subject_id'=>$id,'type'=>$binding['type'],'audience'=>$binding['audience'],'session_version'=>$claims['session_version'],'token_digest'=>hash('sha256',$token),
        'credential_stamp'=>\crmeb\services\auth\CredentialStamp::for('adminapi',$id,$after['pwd']),'expires_at'=>(string)$claims['exp'],'status'=>'active'];
    $actual=audit_redis()->hGetAll($session); ksort($actual); ksort($expected);
    $account=audit_redis()->hGetAll($context['account_key']); ksort($account); $wanted=['blocked'=>'0','initialized'=>'1','session_version'=>$claims['session_version']];
    audit_expect($actual===$expected && $account===$wanted && audit_redis()->pttl($context['account_key'])===-1, 'current session and permanent account record differ');
    $expiry=a9_state($context['owned_rows'])['expires'][$session]??-2;
    audit_expect(abs($expiry-($claims['exp']+60)*1000)<=1 && ($data['menus']??null)===[] && is_array($data['unique_auth']??null) && (int)($data['user_info']['id']??0)===$context['admin_id'], 'normal session expiry and role-filtered login DTO differ');
    audit_expect(is_bool($data['queue']??null) && is_bool($data['timer']??null), 'normal enabled reminder listener must return its real queue and timer flags');
    $context['admin_row']=$after; $evidence['current_v2_bindings'][]=['subject_id'=>$context['admin_id'],'verified'=>true,'fixed_lifetime_seconds'=>$binding['ttl'],'session_key_hash'=>hash('sha256',$session),'queue_state_flag'=>$data['queue'],'timer_state_flag'=>$data['timer']];
    return ['token'=>$token,'session'=>$session,'claims'=>$claims,'unique'=>$data['unique_auth']];
}
function a9_info(array $data,array $row): void
{
    audit_expect((int)($data['id']??0)===(int)$row['id'] && ($data['account']??null)===$row['account'] && ($data['real_name']??null)===$row['real_name'] && ($data['head_pic']??null)===$row['head_pic'], 'own info must match the persisted current synthetic profile');
    foreach (['pwd','is_del','status'] as $field) audit_expect(!array_key_exists($field,$data), 'own info must omit hidden credential/state fields');
}
function a9_failure_location(Throwable $failure): string
{
    $frame=$failure->getTrace()[0]??[];
    return get_class($failure).'@'.basename($failure->getFile()).':'.$failure->getLine()
        .'/caller='.basename((string)($frame['file']??$failure->getFile())).':'.(int)($frame['line']??$failure->getLine());
}
function a9_cleanup(array &$context): array
{
    audit_guard_assert(); $pdo=audit_db(); $removedRows=0;
    // The caller has normally stopped the app. Also claim an actual visit row
    // produced before a natural transport exception prevented a9_http's return.
    if (isset($context['admin_id'],$context['account'])) {
        $q=$pdo->prepare('SELECT * FROM eb_system_log WHERE admin_id=? AND admin_name=? ORDER BY id'); $q->execute([$context['admin_id'],$context['account']]);
        foreach ($q->fetchAll() as $row) if (!isset($context['logs'][(int)$row['id']])) {
            $context['logs'][(int)$row['id']]=$row; $context['owned_rows']['eb_system_log'][]=(int)$row['id'];
        }
    }
    foreach ($context['logs'] as $row) {
        $columns=array_keys($row); foreach ($columns as $name) audit_expect(preg_match('/^[a-z_]+$/D',$name)===1, 'actual visit log column identity differs');
        $q=$pdo->prepare('DELETE FROM eb_system_log WHERE '.implode(' AND ',array_map(static fn(string $c): string=>'`'.$c.'` <=> ?',$columns)));
        $q->execute(array_values($row)); audit_expect($q->rowCount()===1, 'exact complete owned visit log cleanup differs'); $removedRows++;
    }
    if (isset($context['admin_id'])) {
        $row=a9_row('system_admin',$context['admin_id']);
        audit_expect($row['account']===$context['account'] && $row['roles']===(string)$context['role_id'] && (int)$row['level']===1 && (int)$row['status']===1 && (int)$row['is_del']===0
            && in_array($row['real_name'],$context['names'],true) && in_array($row['head_pic'],['',$context['avatar']],true)
            && (password_verify($context['password'],$row['pwd']) || password_verify($context['new_password'],$row['pwd'])), 'cleanup identity must still be the exact owned active synthetic row');
        $columns=array_keys($row); foreach ($columns as $name) audit_expect(preg_match('/^[a-z_]+$/D',$name)===1, 'installed synthetic column identity differs');
        $q=$pdo->prepare('DELETE FROM eb_system_admin WHERE '.implode(' AND ',array_map(static fn(string $c): string=>'`'.$c.'` <=> ?',$columns)));
        $q->execute(array_values($row)); audit_expect($q->rowCount()===1, 'exact complete owned administrator cleanup differs'); $removedRows++;
    }
    if (isset($context['role_id'])) {
        audit_expect(a9_row('system_role',$context['role_id'])===$context['role_row'], 'owned role may not change during normal flow');
        $r=$context['role_row']; $q=$pdo->prepare('DELETE FROM eb_system_role WHERE id=? AND role_name=? AND rules=? AND level=? AND status=?');
        $q->execute([$r['id'],$r['role_name'],$r['rules'],$r['level'],$r['status']]); audit_expect($q->rowCount()===1, 'exact synthetic role cleanup differs'); $removedRows++;
    }
    if (isset($context['v2'],$context['admin_id'])) {
        $cursor=null; $redis=audit_redis();
        do { foreach ($redis->scan($cursor,$context['v2'].'session:*',200)?:[] as $key) {
            if (preg_match('/^'.preg_quote($context['v2'].'session:','/').'[a-f0-9]{32}$/D',$key)!==1 || $redis->type($key)!==Redis::REDIS_HASH) continue;
            $record=$redis->hGetAll($key);
            if (($record['subject_id']??'')===(string)$context['admin_id'] && ($record['type']??'')==='admin' && ($record['audience']??'')==='adminapi') {
                audit_expect(!isset($context['baseline']['redis']['*'][$key]), 'owned cleanup session may not have existed before this synthetic actor');
                $context['owned_keys'][]=$key;
            }
        } } while ($cursor!==0);
    }
    $keys=array_values(array_unique($context['owned_keys'])); $removed=$keys ? audit_redis()->del($keys) : 0;
    audit_expect(is_int($removed) && $removed>=0 && $removed<=count($keys), 'exact owned key cleanup must receive its native Redis removed count');
    foreach ($keys as $key) audit_expect(!audit_redis()->exists($key), 'exact owned key cleanup did not finish');
    return ['rows_removed'=>$removedRows,'registered_key_count'=>count($keys),'keys_removed'=>$removed];
}

audit_case('T9A01','current_v2_granted_rule_and_own_profile','INTEGRATION',static function(): array {
    $context=['owned_rows'=>[],'owned_keys'=>[],'logs'=>[],'secrets'=>[]]; $evidence=['status'=>'RUNNING','requests'=>[],'readiness_requests'=>[],'current_v2_bindings'=>[],
        'old_token_requests'=>0,'wrong_credential_requests'=>0,'captcha_solutions'=>0,'negative_fault_recovery_concurrent_cases'=>'NOT RUN']; $started=false; $error=null; $cleanupError=null;
    try {
        $config=audit_config(); $context['config']=$config; audit_expect(($config['auth']['session_epoch']??'')==='audit-http-'.getenv('AUDIT_RUN_ID'), 'dedicated current v2 configuration differs');
        audit_expect((audit_manifest()['isolation']??'')==='ISO-FULL' && (audit_manifest()['commit_sha']??'')===getenv('AUDIT_SOURCE_SHA'), 'actual ISO-FULL source commit differs'); $context['baseline']=a9_state();
        $executionMillis=(string)(int)floor(microtime(true)*1000); $prefix='audit:http-lang:'.getenv('AUDIT_RUN_ID').':'.$executionMillis.':';
        audit_expect(preg_match('/^audit:http-lang:'.preg_quote((string)getenv('AUDIT_RUN_ID'),'/').':[0-9]+:$/D',$prefix)===1, 'actual decimal-millisecond prefix must satisfy the unchanged supervisor contract');
        $cursor=null; do { audit_expect((audit_redis()->scan($cursor,$prefix.'*',200)?:[])===[], 'actual fresh millisecond cache namespace must be unique and empty'); } while ($cursor!==0);
        $context['tag']=$prefix.'CRMEB:'.md5('crmeb'); $evidence['execution_millis']=$executionMillis;
        $configNames=['site_name','site_url','tourist_avatar','site_logo','site_logo_square','nncnL_crmeb_copyright','login_logo','queue_open','wechat_open_app_id','nncnL_crmeb_copyright_image','custom_admin_js','param_filter_type','param_filter_data','new_order_audio_link','model_checkbox'];
        $names=array_merge(array_map(static fn(string $n): string=>'system_config_'.$n,$configNames),['data_admin_login_slide','range_name','sys_lang_source_map','type_id_zh-CN','lang_type_data','lang_zh_CN','all_auth']);
        foreach ($names as $name) { $context['owned_keys'][]=$prefix.$name; $context['owned_keys'][]=$prefix.$name.'_lock'; } $context['owned_keys'][]=$context['tag'];
        foreach ($context['owned_keys'] as $key) audit_expect(!isset($context['baseline']['redis']['*'][$key]), 'exact new source cache key must start absent');
        $app=new \think\App(audit_repo_dir().'/crmeb/'); $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],
            'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306','DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],'DATABASE_PREFIX'=>'eb_','DATABASE_DEBUG'=>false,
            'CACHE_DRIVER'=>'redis','REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
            'AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]); $app->initialize();
        $consoleRemind=$app->config->get('app.console_remind');
        audit_expect($consoleRemind===true, 'ordinary login must retain the actual enabled console-reminder source configuration');
        $evidence['console_reminder_source_enabled']=$consoleRemind;
        audit_expect((int)audit_db()->query("SELECT COUNT(*) FROM eb_system_event WHERE mark='admin_login' AND is_del=0 AND is_open=1")->fetchColumn()===0, 'installed normal login must have no executable custom event');
        $q=audit_db()->query("SELECT id FROM eb_system_menus WHERE auth_type=2 AND LOWER(api_url)='product/product/rule' AND LOWER(methods)='get' AND is_del=0 ORDER BY id"); $rules=$q->fetchAll(PDO::FETCH_COLUMN);
        audit_expect(count($rules)===1, 'exact existing granted product rule registration is required'); $permission=(string)$rules[0];
        $context['account']='m9a_'.bin2hex(random_bytes(7)); $context['password']='M9a-'.bin2hex(random_bytes(10)).'-X7'; $context['new_password']='M9b-'.bin2hex(random_bytes(10)).'-X8';
        $context['names']=['MS1 normal admin','MS1 updated admin']; $context['avatar']='/statics/system_images/default_avatar.jpeg';
        audit_expect(is_file(audit_repo_dir().'/crmeb/public'.$context['avatar']), 'actual existing local profile avatar asset must exist');
        $context['secrets']=[$config['signing_key'],$config['auth']['stamp_key'],$config['redis_password'],$config['database']['password'],$context['account'],$context['password'],$context['new_password']];
        $roleName='M9 role '.bin2hex(random_bytes(6)); $pdo=audit_db();
        $q=$pdo->prepare('SELECT COUNT(*) FROM eb_system_admin WHERE account=?'); $q->execute([$context['account']]); audit_expect((int)$q->fetchColumn()===0, 'new normal synthetic administrator account must not exist');
        $q=$pdo->prepare('SELECT COUNT(*) FROM eb_system_role WHERE role_name=?'); $q->execute([$roleName]); audit_expect((int)$q->fetchColumn()===0, 'new normal synthetic role name must not exist');
        $q=$pdo->prepare('INSERT INTO eb_system_role(role_name,rules,level,status) VALUES(?,?,1,1)'); $q->execute([$roleName,$permission]);
        $context['role_id']=(int)$pdo->lastInsertId(); $context['owned_rows']['eb_system_role']=[$context['role_id']]; $context['role_row']=a9_row('system_role',$context['role_id']);
        $digest=password_hash($context['password'],PASSWORD_BCRYPT); audit_expect(is_string($digest), 'normal synthetic password digest differs'); $context['secrets'][]=$digest;
        $q=$pdo->prepare('INSERT INTO eb_system_admin(account,pwd,real_name,roles,add_time,level,status,is_del) VALUES(?,?,?,?,?,1,1,0)'); $q->execute([$context['account'],$digest,$context['names'][0],(string)$context['role_id'],time()]);
        $context['admin_id']=(int)$pdo->lastInsertId(); $context['owned_rows']['eb_system_admin']=[$context['admin_id']]; $context['admin_row']=a9_row('system_admin',$context['admin_id']);
        $q=$pdo->prepare('SELECT COUNT(*) FROM eb_system_log WHERE admin_id=? AND admin_name=?'); $q->execute([$context['admin_id'],$context['account']]); audit_expect((int)$q->fetchColumn()===0, 'new exact synthetic actor must have no original visit logs');
        $context['v2']='auth:v2:'.$config['auth']['session_epoch'].':adminapi:'; $context['account_key']=$context['v2'].'account:'.$context['admin_id']; $context['owned_keys'][]=$context['account_key'];
        $roleKey=$prefix.md5(\app\services\system\admin\SystemRoleServices::ADMIN_RULES_LEVEL.'_2_'.$context['role_id']); $context['owned_keys'][]=$roleKey; $context['owned_keys'][]=$roleKey.'_lock';
        $context['cache_values']=[]; foreach ($configNames as $name) $context['cache_values'][$prefix.'system_config_'.$name]=['value'=>$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($name),'ttl'=>0];
        $code=$app->make(\app\services\system\lang\LangCodeServices::class); $type=$app->make(\app\services\system\lang\LangTypeServices::class); $country=$app->make(\app\services\system\lang\LangCountryServices::class);
        $range=$type->value(['is_default'=>1],'file_name'); $typeId=$country->value(['code'=>$range],'type_id')?:1; $types=$type->getColumn(['status'=>1,'is_del'=>0],'file_name','id'); audit_expect($range==='zh-CN' && ($types[$typeId]??null)==='zh-CN', 'normal installed language source differs');
        $lang=['range_name'=>$range,'sys_lang_source_map'=>$code->getColumn(['type_id'=>1],'code','remarks'),'type_id_zh-CN'=>$typeId,'lang_type_data'=>$types,'lang_zh_CN'=>$code->getColumn(['type_id'=>$typeId],'lang_explain','code')];
        foreach ($lang as $name=>$value) $context['cache_values'][$prefix.$name]=['value'=>$value,'ttl'=>$name==='range_name'?0:3600];
        $all=[]; foreach ($pdo->query("SELECT api_url,methods FROM eb_system_menus WHERE api_url<>'' AND auth_type=2")->fetchAll() as $r) $all[strtolower(trim($r['methods']))][]=strtolower(trim(str_replace(' ','',$r['api_url'])));
        $context['cache_values'][$prefix.'all_auth']=['value'=>$all,'ttl'=>0]; $context['cache_values'][$roleKey]=['value'=>['get'=>['product/product/rule']],'ttl'=>0];
        foreach (array_unique($context['owned_keys']) as $key) audit_expect(!isset($context['baseline']['redis']['*'][$key]) && !audit_redis()->exists($key), 'registered owned key must be new and absent before its producer');
        $queueOpen=$context['cache_values'][$prefix.'system_config_queue_open']['value'];
        audit_expect(in_array($queueOpen,[0,'0'],true), 'actual installed queue_open must be0; do not modify its source or enqueue a readiness job');
        $warmBefore=a9_state($context['owned_rows']); getLang('操作成功'); $warmedKeys=[];
        foreach ($configNames as $name) {
            $key=$prefix.'system_config_'.$name; $got=\crmeb\services\SystemConfigService::get($name);
            audit_expect($got===$context['cache_values'][$key]['value'], 'actual normal system producer differs from its source'); $warmedKeys[]=$key;
        }
        $wantSlide=$app->make(\app\services\system\config\SystemGroupDataServices::class)->getConfigNameValue('admin_login_slide',0);
        $context['cache_values'][$prefix.'data_admin_login_slide']=['value'=>$wantSlide,'ttl'=>0];
        audit_expect(is_array($wantSlide) && sys_data('admin_login_slide')===$wantSlide, 'real slide producer including source upload URL normalization differs'); $warmedKeys[]=$prefix.'data_admin_login_slide';
        foreach (array_keys($lang) as $name) $warmedKeys[]=$prefix.$name;
        $warmAfter=a9_state($context['owned_rows']); $actualAdded=array_values(array_diff(array_keys($warmAfter['redis']['*']),array_keys($warmBefore['redis']['*']))); $expectedAdded=array_merge($warmedKeys,[$context['tag']]); sort($actualAdded); sort($expectedAdded);
        audit_expect($actualAdded===$expectedAdded, 'normal prewarm must produce exactly15 configuration values,one slide,five language values and their one shared tag');
        a9_cache($context); a9_preserve($context['baseline'],$warmAfter,$context['owned_keys']);
        $evidence['source_preparation']=['config_value_count'=>15,'slide_value_count'=>1,'language_value_count'=>5,'value_key_count'=>21,'actual_added_key_count'=>count($actualAdded),
            'registered_value_and_temporary_lock_key_hashes'=>array_map(static fn(string $k): string=>hash('sha256',$k),array_merge($warmedKeys,array_map(static fn(string $k): string=>$k.'_lock',$warmedKeys))),
            'source'=>'real getLang/SystemConfigService/sys_data and installed Lang/Config/GroupData DB producers','queue_open_source_is0'=>true,'source_configuration_modified'=>false,'exact_tag_membership_verified'=>true];
        $started=true; audit_app_start(['CACHE_PREFIX'=>$prefix]);
        $evidence['helper_readiness']=['helper'=>'unchanged audit_app_start','route'=>'GET /adminapi/login/info','transport'=>'real original HTTP readiness producer','internal_response_or_attempt_count'=>'NOT CAPTURED by helper'];
        // getLoginInfo returns sys_config's normalized image URLs, while the
        // independently checked underlying Redis config retains its raw value.
        $readinessLogos=['logo_square'=>sys_config('site_logo_square'),'logo_rectangle'=>sys_config('site_logo'),'login_logo'=>sys_config('login_logo')];
        $ready=a9_http('GET','/adminapi/login/info',null,null,$context,$evidence);
        audit_expect(($ready['slide']??null)===$wantSlide && ($ready['logo_square']??null)===$readinessLogos['logo_square']
            && ($ready['logo_rectangle']??null)===$readinessLogos['logo_rectangle'] && ($ready['login_logo']??null)===$readinessLogos['login_logo']
            && ($ready['site_name']??null)===$context['cache_values'][$prefix.'system_config_site_name']['value'] && ($ready['login_captcha']??null)===0 && is_string($ready['key']??null) && $ready['key']!=='', 'explicit real login/info200 source readiness DTO differs');
        a9_cache($context); a9_preserve($context['baseline'],a9_state($context['owned_rows']),$context['owned_keys']);
        $login=a9_login($context['password'],$context,$evidence); $token=$login['token'];
        $data=a9_http('GET','/adminapi/product/product/rule',$token,null,$context,$evidence);
        $ruleIds=array_map('intval',$pdo->query('SELECT id FROM eb_store_product_rule ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN));
        audit_expect(is_array($data['list']??null) && (int)($data['count']??-1)===count($ruleIds) && array_map(static fn(array $r): int=>(int)$r['id'],$data['list'])===$ruleIds, 'granted actual rule read must return the installed source count and ordered identities');
        $search=a9_http('GET','/adminapi/menusList',$token,null,$context,$evidence);
        $searchRows=$pdo->query('SELECT id,pid FROM eb_system_menus WHERE is_show=1 AND auth_type=1 AND is_del=0 AND is_show_path=0')->fetchAll();
        $seen=[]; $collect=static function(array $items) use (&$collect,&$seen): void { foreach ($items as $r) { audit_expect(is_array($r) && isset($r['id'],$r['menu_name'],$r['menu_path'],$r['type']), 'actual search metadata item differs'); $seen[]=(int)$r['id']; if (isset($r['children'])) $collect($r['children']); } }; $collect($search);
        $known=array_map(static fn(array $r): int=>(int)$r['id'],$searchRows); $roots=array_map(static fn(array $r): int=>(int)$r['id'],array_filter($searchRows,static fn(array $r): bool=>(int)$r['pid']===0));
        audit_expect($seen!==[] && array_diff($seen,$known)===[] && array_diff($roots,$seen)===[], 'global search metadata must come from the installed visible menu source including every actual root');
        $menus=a9_http('GET','/adminapi/menus',$token,null,$context,$evidence); audit_expect(($menus['menus']??null)===[] && ($menus['unique']??null)===$login['unique'], 'own menus remain filtered to the API-only synthetic role');
        $unique=a9_http('GET','/adminapi/setting/menus/unique',$token,null,$context,$evidence); audit_expect(($unique['menus']??null)===[] && ($unique['uniqueAuth']??null)===$login['unique'], 'own unique permissions remain filtered to the API-only role');
        a9_info(a9_http('GET','/adminapi/setting/info',$token,null,$context,$evidence),$context['admin_row']); a9_cache($context); a9_preserve($context['baseline'],a9_state($context['owned_rows']),$context['owned_keys']);
        $before=a9_row('system_admin',$context['admin_id']); a9_http('PUT','/adminapi/setting/update_admin',$token,['real_name'=>$context['names'][1],'head_pic'=>$context['avatar']],$context,$evidence); $after=a9_row('system_admin',$context['admin_id']);
        foreach ($before as $field=>$value) audit_expect($after[$field]===($field==='real_name'?$context['names'][1]:($field==='head_pic'?$context['avatar']:$value)), 'normal profile update changes only own name and avatar'); $context['admin_row']=$after;
        a9_info(a9_http('GET','/adminapi/setting/info',$token,null,$context,$evidence),$after); a9_cache($context); a9_preserve($context['baseline'],a9_state($context['owned_rows']),$context['owned_keys']);
        a9_http('PUT','/adminapi/setting/update_admin',$token,['real_name'=>$after['real_name'],'head_pic'=>$after['head_pic'],'pwd'=>$context['password'],'new_pwd'=>$context['new_password'],'conf_pwd'=>$context['new_password']],$context,$evidence); $changed=a9_row('system_admin',$context['admin_id']);
        foreach ($after as $field=>$value) if ($field!=='pwd') audit_expect($changed[$field]===$value, 'correct password change preserves every other own column'); audit_expect(password_verify($context['new_password'],$changed['pwd']), 'correct new password must match actual persisted digest'); $context['secrets'][]=$changed['pwd'];
        unset($token,$login); /* Never send the pre-change credential again. */
        $fresh=a9_login($context['new_password'],$context,$evidence); a9_info(a9_http('GET','/adminapi/setting/info',$fresh['token'],null,$context,$evidence),$context['admin_row']);
        a9_http('GET','/adminapi/setting/admin/logout',$fresh['token'],null,$context,$evidence); audit_expect(!audit_redis()->exists($fresh['session']), 'normal logout must consume its exact current session'); unset($fresh);
        audit_expect(count($evidence['requests'])===12 && count($evidence['readiness_requests'])===1 && count($context['logs'])===10 && count($evidence['current_v2_bindings'])===2, 'complete declared normal flow requires12 business requests,one explicit readiness verification,two current logins and10 actual visit logs');
        a9_cache($context); a9_preserve($context['baseline'],a9_state($context['owned_rows']),$context['owned_keys']);
    } catch (Throwable $failure) { $error=a9_failure_location($failure); }
    finally {
        try { if ($started) audit_app_stop(); $evidence['cleanup']=a9_cleanup($context); if (isset($context['baseline'])) { $final=a9_state(); a9_preserve($context['baseline'],$final,[],true); $evidence['original_state_preserved']=true; $evidence['full_table_count']=count($final['tables']); $evidence['natural_counter_changes']=[];
            foreach (['eb_system_admin','eb_system_role','eb_system_log'] as $t) $evidence['natural_counter_changes'][$t]=[$context['baseline']['ddl'][$t]['counter'],$final['ddl'][$t]['counter']];
            $evidence['full_preservation_receipt']=['before_rows_sha256'=>hash('sha256',serialize($context['baseline']['tables'])),'after_rows_sha256'=>hash('sha256',serialize($final['tables'])),
                'before_redis_values_sha256'=>hash('sha256',serialize($context['baseline']['redis'])),'after_redis_values_sha256'=>hash('sha256',serialize($final['redis'])),
                'before_runtime_sha256'=>hash('sha256',serialize($context['baseline']['runtime'])),'after_runtime_sha256'=>hash('sha256',serialize($final['runtime'])),
                'all_schema_and_checksums_verified'=>true,'all_unrelated_absolute_deadlines_verified'=>true,'no_state_restore_or_counter_rollback'=>true]; }
            foreach ([audit_run_dir().'/evidence/app-runtime.log'] as $log) if (is_file($log)) { $bytes=file_get_contents($log); foreach ($context['secrets'] as $secret) audit_expect($secret==='' || !str_contains($bytes,$secret), 'actual normal application log contains private credential material'); }
        } catch (Throwable $failure) { $cleanupError=a9_failure_location($failure); }
    }
    $evidence['status']=$error===null && $cleanupError===null ? 'PASS':'FAIL'; $evidence['failure']=$error; $evidence['cleanup_failure']=$cleanupError;
    $ref='evidence/ms1-admin-normal-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)).'.json'; audit_write_json(audit_run_dir().'/'.$ref,$evidence);
    return ['status'=>$evidence['status'],'expected'=>'current v2 valid login, granted rule, six own routes, exact own profile/password writes, fresh login and current logout with full preservation',
        'actual'=>count($evidence['requests']).' real normal HTTP requests; cleanup '.($cleanupError===null?'completed':'failed'), 'evidence_ref'=>$ref,
        'notes'=>'Independent A05/A06 normal coverage only. No blocked suite is imported or bypassed; skipped security/negative/fault/recovery/concurrency/DDL probes remain NOT RUN.'];
});
audit_finish();
