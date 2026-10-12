<?php
declare(strict_types=1);
/* Normal T7B01 browser helper for the dedicated isolated RUN.
 * __serve holds owner/secrets in memory; browser reads only its dedicated secret.
 * No replay, refused request, DDL probe, fault or recovery check is included.
 */
require __DIR__ . '/lib/harness.php';
if (($argv[1] ?? '')!=='__serve') { fwrite(STDERR,"BLOCKED ordinary browser helper requires __serve\n"); exit(2); }
if ((audit_manifest()['guard_mode'] ?? '') !== 'read-only') {
    fwrite(STDERR, "BLOCKED ordinary T7B01 requires read-only isolation checks\n"); exit(2);
}
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
function m7b_diag(Throwable $error): array
{
    $caller=$error->getTrace()[0] ?? [];
    return ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'caller_file'=>basename($caller['file'] ?? ''),'caller_line'=>$caller['line'] ?? null];
}


function m7b_scan(string $pattern): array
{
    $cursor=null; $keys=[]; $redis=audit_redis();
    do { $keys=array_merge($keys,$redis->scan($cursor,$pattern,200) ?: []); } while ($cursor !== 0);
    $keys=array_values(array_unique($keys)); sort($keys,SORT_STRING); return $keys;
}


function m7b_deadlines(array $keys): array
{
    if (!$keys) return [];
    $values=audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r",$keys,count($keys));
    audit_expect(is_array($values) && count($values)===count($keys),'every native cache deadline is available');
    $result=[]; foreach ($keys as $i=>$key) { audit_expect(is_int($values[$i]),'cache deadline is integral'); $result[$key]=$values[$i]; }
    return $result;
}


function m7b_snapshot(): array
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
    $redis=audit_redis(); $keys=m7b_scan('*'); $deadlines=m7b_deadlines($keys);
    foreach ($keys as $key) {
        if ($redis->type($key)===Redis::REDIS_SET) { $members=$redis->sMembers($key); sort($members,SORT_STRING); $value="set\0".serialize($members); }
        else { $value=(string)$redis->dump($key); }
        $snapshot['redis'][$key]=['sha256'=>hash('sha256',$value),'deadline'=>$deadlines[$key]];
    }
    return $snapshot;
}


function m7b_public_snapshot(array $snapshot): array
{
    $redis=[]; foreach ($snapshot['redis'] as $key=>$value) $redis[hash('sha256',$key)]=$value;
    ksort($redis); $snapshot['redis']=$redis; return $snapshot;
}


function m7b_same(array $before,array $after,array $changedTables=[],array $changedKeys=[],array $counterTables=[]): void
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


function m7b_app(array $config,string $prefix): \think\App
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


function m7b_warm_language(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): void
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
    $before=m7b_snapshot();
    foreach ($cleanupKeys as $key) audit_expect(!isset($before['redis'][$key]),'exact language preparation key was already present');
    // Register before the real normal producer, for precise partial-run cleanup.
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,$cleanupKeys)));
    $start=time(); getLang('操作成功'); $end=time(); $after=m7b_snapshot(); $redis=audit_redis();
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
    m7b_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['language_preparation']=['source'=>'real getLang and installed LangCode/LangType/LangCountry services',
        'logical_keys'=>array_keys($values),'added_key_hashes'=>array_map(static fn(string $key): string=>hash('sha256',$key),$expectedNew),
        'exact_values_and_source_expiry_verified'=>true,'unrelated_state_preserved'=>true];
}


function m7b_value(mixed $got,mixed $wanted): bool
{ return is_int($wanted) ? ((is_int($got)||is_string($got)) && (string)$got===(string)$wanted) : $got===$wanted; }

function m7b_row_hashes(PDO $pdo,string $table,string $pk,array $omit=[]): array
{
    $rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY `'.$pk.'`')->fetchAll(); $result=[];
    foreach ($rows as $row) if (!in_array((string)$row[$pk],array_map('strval',$omit),true)) $result[(string)$row[$pk]]=hash('sha256',serialize($row));
    return $result;
}

function m7b_old_rows(PDO $pdo,array $old,array $owners,?array $sequenceBefore): void
{
    foreach (['user'=>'uid','store_service'=>'id','store_service_log'=>'id','store_service_record'=>'id'] as $table=>$pk)
        audit_expect(m7b_row_hashes($pdo,'eb_'.$table,$pk,array_keys($owners[$table]))===$old[$table],'every original chat fixture table row remains unchanged');
    $cache=m7b_row_hashes($pdo,'eb_cache','key',array_merge(['kefu_tourist_uid_seq'],array_keys($owners['sql_cache'] ?? [])));
    audit_expect($cache===$old['cache_without_sequence'],'every unrelated original SQL cache row remains unchanged');
    $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kefu_tourist_uid_seq']); $row=$stmt->fetch();
    if ($sequenceBefore!==null) {
        audit_expect(is_array($row),'existing visitor ledger must remain present');
        foreach ($sequenceBefore as $field=>$value) if ($field!=='result') audit_expect($row[$field]===$value,'existing permanent visitor sequence metadata remains unchanged');
        audit_expect(preg_match('/^(0|[1-9][0-9]*)$/D',(string)$row['result'])===1 && (int)$row['result']>=(int)$sequenceBefore['result'],'existing visitor sequence only moves forward');
    }
}

function m7b_preflight(PDO $pdo,array &$evidence): void
{
    $facts=[];
    foreach (['eb_store_service','eb_store_service_record'] as $table) {
        $facts[$table.'_nonzero_online']=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'` WHERE online<>0')->fetchColumn();
        audit_expect($facts[$table.'_nonzero_online']===0,'old chat online fields must already be zero before startup');
    }
    $from=strtotime('last week 00:00:00'); $to=strtotime('this week 00:00:00 -1second');
    audit_expect(is_int($from)&&is_int($to)&&$from<=$to,'normal calendar week is available');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_store_service_record WHERE is_tourist=1 AND update_time BETWEEN ? AND ?'); $stmt->execute([$from,$to]);
    $facts['calendar_last_week_guest_records']=(int)$stmt->fetchColumn();
    audit_expect($facts['calendar_last_week_guest_records']===0,'production automatic guest-record deletion range starts empty');
    $facts['timezone']=date_default_timezone_get(); $facts['calendar_last_week_from']=$from; $facts['calendar_last_week_to']=$to;
    $facts['source']='raw PDO; RecordDao is not constructed before this preflight'; $evidence['preflight']=$facts;
}

function m7b_register_keys(array $keys,array &$ownedKeys): void
{
    $redis=audit_redis(); foreach ($keys as $key) if (!in_array($key,$ownedKeys,true)) {
        audit_expect($redis->exists($key)===0,'exact newly owned native key starts absent'); $ownedKeys[]=$key;
    }
}

function m7b_system_warm(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): array
{
    // Exact production keys for chat and the unchanged admin login/info readiness.
    // Do not call the complete login service here: it can dispatch CheckQueueJob.
    $names=['site_name','site_url','tourist_avatar','site_logo','site_logo_square','nncnL_crmeb_copyright','login_logo','queue_open','wechat_open_app_id','nncnL_crmeb_copyright_image','custom_admin_js','param_filter_type','param_filter_data'];
    $configKeys=array_map(static fn(string $name): string=>$prefix.'system_config_'.$name,$names);
    $slideKey=$prefix.'data_admin_login_slide'; $keys=array_merge($configKeys,[$slideKey]); $tag=$prefix.'CRMEB:'.md5('crmeb');
    $before=m7b_snapshot(); $values=[];
    m7b_register_keys(array_merge($keys,array_map(static fn(string $key): string=>$key.'_lock',$keys)),$ownedKeys);
    foreach ($names as $name) {
        $want=$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($name);
        $got=\crmeb\services\SystemConfigService::get($name);
        audit_expect($got===$want,'normal exact system cache matches its installed actual DB producer'); $values[$name]=$want;
    }
    $wantSlide=$app->make(\app\services\system\config\SystemGroupDataServices::class)->getConfigNameValue('admin_login_slide',0);
    $actualSlide=sys_data('admin_login_slide');
    audit_expect(is_array($wantSlide)&&$actualSlide===$wantSlide,'normal login slide cache matches the real group-data producer including upload URL normalization');
    $after=m7b_snapshot(); $new=array_values(array_diff(array_keys($after['redis']),array_keys($before['redis']))); sort($new); $expectedNew=$keys; sort($expectedNew);
    audit_expect($new===$expectedNew,'normal readiness/chat preparation adds exactly its fourteen known physical value keys');
    $redis=audit_redis();
    foreach ($names as $name) {
        $key=$prefix.'system_config_'.$name; $want=is_numeric($values[$name]) ? (string)$values[$name] : serialize($values[$name]);
        audit_expect($redis->type($key)===Redis::REDIS_STRING&&$redis->get($key)===$want&&$after['redis'][$key]['deadline']===-1,'normal exact config serialization and persistent deadline match actual producer');
    }
    audit_expect($redis->type($slideKey)===Redis::REDIS_STRING&&$redis->get($slideKey)===serialize($wantSlide)&&$after['redis'][$slideKey]['deadline']===-1,'normal exact slide serialization and persistent deadline match actual producer');
    foreach ($keys as $key) audit_expect($redis->exists($key.'_lock')===0,'normal remember producer removed its precise temporary value lock');
    $members=$redis->sMembers($tag); sort($members); $expected=array_merge($keys,array_map(static fn(string $name): string=>$prefix.$name,
        ['range_name','sys_lang_source_map','type_id_zh-CN','lang_type_data','lang_zh_CN'])); sort($expected);
    audit_expect($redis->type($tag)===Redis::REDIS_SET&&$members===$expected&&$after['redis'][$tag]['deadline']===-1,'normal shared tag contains exactly nineteen language/config/slide members and its persistent deadline');
    m7b_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['system_config_preparation']=['logical_keys'=>array_merge(array_map(static fn(string $name): string=>'system_config_'.$name,$names),['data_admin_login_slide']),
        'exact_value_key_count'=>count($keys),'exact_temporary_lock_key_count'=>count($keys),
        'source'=>'real SystemConfigService::get and sys_data/GroupDataService; installed SystemConfigServices and SystemGroupDataServices DB producers',
        'readiness_source'=>'SystemAdminServices::getLoginInfo and QueueTrait::dispatchSecs queue_open read',
        'values_serialization_and_source_persistent_expiry_verified'=>true,'new_key_inventory_and_exact_shared_tag_verified'=>true,'whole_prefix_ignored'=>false];
    return $values;
}

function m7b_chat_control(string $action,array $options=[]): array
{
    $revision=bin2hex(random_bytes(8)); audit_write_json(audit_run_dir().'/ms1-chat-control.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,'action'=>$action,'options'=>$options]);
    $deadline=microtime(true)+15;
    do {
        $status=audit_read_control_json(audit_run_dir().'/ms1-chat-status.json');
        if (($status['run_id'] ?? '')===getenv('AUDIT_RUN_ID') && ($status['revision'] ?? '')===$revision) {
            $receipt=audit_run_dir().'/evidence/ms1-chat-control-'.$revision.'.json';
            audit_expect(!file_exists($receipt),'normal lifecycle receipt already exists');
            audit_write_json($receipt,$status);
            audit_expect(($status['status'] ?? '')===$action,'normal registered chat lifecycle failed'); return $status;
        }
        usleep(10000);
    } while (microtime(true)<$deadline);
    throw new RuntimeException('normal registered chat lifecycle ACK unavailable');
}

function m7b_guest_discover(PDO $pdo,array $config,?array $sequenceBefore,array &$owners,array &$ownedKeys,array &$secrets): void
{
    $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kefu_tourist_uid_seq']); $sequence=$stmt->fetch();
    if (!is_array($sequence)) return;
    audit_expect((int)$sequence['expire_time']===0&&preg_match('/^(0|[1-9][0-9]*)$/D',(string)$sequence['result'])===1,'current guest sequence keeps its real permanent decimal format');
    $first=$sequenceBefore===null ? 0 : (int)$sequenceBefore['result']; $last=(int)$sequence['result'];
    audit_expect($last>=$first&&$last-$first<=2,'ordinary scope issues only its two normal guest capabilities');
    // Source-owned UID allocation range, exact deterministic UID indices; no wildcard adoption.
    for ($value=$first+1;$value<=$last;$value++) {
        $uid=-$value; if (isset($owners['guest'][$uid])) continue;
        $index='chat:guest:v1:'.$config['auth']['session_epoch'].':uid:'.$uid; $digest=audit_redis()->get($index);
        if ($digest===false) continue;
        audit_expect(is_string($digest)&&preg_match('/^[a-f0-9]{64}$/D',$digest)===1,'lost-response owned UID index has actual canonical digest');
        $key='chat:guest:v1:'.$config['auth']['session_epoch'].':'.$digest; $bytes=audit_redis()->get($key); audit_expect(is_string($bytes),'lost-response owned UID record is available');
        $row=json_decode($bytes,true,16,JSON_THROW_ON_ERROR); $wanted=['token_digest','uid','avatar','purpose','expires_at']; $keys=array_keys($row); sort($wanted); sort($keys);
        audit_expect($keys===$wanted&&($row['token_digest'] ?? null)===$digest&&($row['uid'] ?? null)===$uid
            &&($row['purpose'] ?? null)==='tianli:kefu-chat:guest'&&is_string($row['avatar'] ?? null)&&is_int($row['expires_at'] ?? null),'lost-response record belongs to this exact normally allocated UID');
        $owners['guest'][$uid]=['uid'=>$uid,'avatar'=>$row['avatar'],'expires_at'=>$row['expires_at'],'record_key'=>$key,'index_key'=>$index,'digest'=>$digest];
        $ownedKeys=array_values(array_unique(array_merge($ownedKeys,[$key,$index]))); $secrets[]=$digest;
    }
    $owners['sequence']=$sequenceBefore===null ? $sequence : null;
}

function m7b_discover_chat(PDO $pdo,array $users,array $messages,array &$owners): void
{
    if (!isset($users['kefu']['uid'])) return; $service=$users['kefu']['uid']; $peers=[];
    if (isset($users['customer']['uid'])) $peers[]=$users['customer']['uid']; $peers=array_merge($peers,array_keys($owners['guest']));
    foreach ($peers as $peer) {
        $stmt=$pdo->prepare('SELECT * FROM eb_store_service_record WHERE (user_id=? AND to_uid=?) OR (user_id=? AND to_uid=?)'); $stmt->execute([$service,$peer,$peer,$service]);
        foreach ($stmt->fetchAll() as $row) {
            $id=(int)$row['id']; $owners['store_service_record'][$id]=['id'=>$id,'user_id'=>(int)$row['user_id'],'to_uid'=>(int)$row['to_uid'],'is_tourist'=>(int)$row['is_tourist']];
        }
        foreach ($messages as $message) {
            $stmt=$pdo->prepare('SELECT * FROM eb_store_service_log WHERE msn=? AND msn_type=1 AND ((uid=? AND to_uid=?) OR (uid=? AND to_uid=?))'); $stmt->execute([$message,$service,$peer,$peer,$service]);
            foreach ($stmt->fetchAll() as $row) {
                $id=(int)$row['id']; $owners['store_service_log'][$id]=['id'=>$id,'uid'=>(int)$row['uid'],'to_uid'=>(int)$row['to_uid'],'msn'=>$message,'msn_type'=>1,'is_tourist'=>(int)$row['is_tourist']];
            }
        }
    }
}

function m7b_runtime_logs(array $secrets): bool
{
    $paths=[audit_run_dir().'/evidence/app-runtime.log',audit_run_dir().'/evidence/ms1-chat-runtime.log'];
    foreach (['/run/audit-app-runtime','/run/audit-chat-runtime',audit_repo_dir().'/crmeb/runtime'] as $root) {
        if (!is_dir($root)) continue; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file)
            if ($file->isFile()&&str_ends_with($file->getFilename(),'.log')) $paths[]=$file->getPathname();
    }
    foreach (array_unique($paths) as $path) { if (!is_file($path)) continue; $bytes=file_get_contents($path); audit_expect(is_string($bytes),'ordinary logs are readable');
        foreach (array_unique($secrets) as $secret) if ($secret!==''&&str_contains($bytes,$secret)) return false; }
    return true;
}

function m7b_cleanup(PDO $pdo,array $owners,array $ownedKeys,?array $sequenceBefore): int
{
    foreach (['store_service_log','store_service_record','store_service','user'] as $table) foreach (array_reverse($owners[$table],true) as $owner) {
        if ($table==='store_service_log') { $sql='DELETE FROM eb_store_service_log WHERE id=? AND uid=? AND to_uid=? AND msn=? AND msn_type=? AND is_tourist=?'; $args=[$owner['id'],$owner['uid'],$owner['to_uid'],$owner['msn'],$owner['msn_type'],$owner['is_tourist']]; }
        elseif ($table==='store_service_record') { $sql='DELETE FROM eb_store_service_record WHERE id=? AND user_id=? AND to_uid=? AND is_tourist=?'; $args=[$owner['id'],$owner['user_id'],$owner['to_uid'],$owner['is_tourist']]; }
        elseif ($table==='store_service') { $sql='DELETE FROM eb_store_service WHERE id=? AND uid=? AND account=? AND password=?'; $args=[$owner['id'],$owner['uid'],$owner['account'],$owner['password']]; }
        else { $sql='DELETE FROM eb_user WHERE uid=? AND account=? AND pwd=? AND nickname=? AND avatar=?'; $args=[$owner['uid'],$owner['account'],$owner['pwd'],$owner['nickname'],$owner['avatar']]; }
        $stmt=$pdo->prepare($sql); $stmt->execute($args); audit_expect($stmt->rowCount()===1,'each owned actual chat fixture cleans precisely one captured row');
    }
    if ($sequenceBefore===null && is_array($owners['sequence'] ?? null)) {
        $row=$owners['sequence']; $stmt=$pdo->prepare('DELETE FROM eb_cache WHERE `key`=? AND result=? AND expire_time=? AND add_time=?');
        $stmt->execute(['kefu_tourist_uid_seq',$row['result'],$row['expire_time'],$row['add_time']]); audit_expect($stmt->rowCount()===1,'only the new exact permanent visitor sequence row is removed');
    }
    // Existing sequence keeps its real forward progress. Never restore original rows or counter values.
    $keys=array_values(array_unique($ownedKeys)); $redis=audit_redis(); $removed=$keys ? $redis->del($keys) : 0;
    audit_expect(is_int($removed)&&$removed>=0&&$removed<=count($keys),'exact current native keys receive actual removal receipt');
    foreach ($keys as $key) audit_expect($redis->exists($key)===0,'each exact newly owned native key has been removed'); return $removed;
}

function m7b_sources(array &$evidence): void
{
    $files=[
        'crmeb/crmeb/services/workerman/chat/GuestChatSession.php','crmeb/crmeb/services/workerman/chat/ChatIdentity.php',
        'crmeb/crmeb/services/workerman/chat/ChatService.php','crmeb/crmeb/services/workerman/chat/ChatHandle.php',
        'crmeb/crmeb/services/auth/AuthTokenV2.php','crmeb/app/services/kefu/LoginServices.php','crmeb/app/services/user/UserAuthServices.php',
        'crmeb/app/kefuapi/controller/Common.php','crmeb/app/kefuapi/route/route.php','crmeb/app/kefuapi/KefuApiExceptionHandle.php',
        'crmeb/app/listener/http/HttpEndListener.php','crmeb/app/services/kefu/KefuServices.php',
        'crmeb/app/services/kefu/service/StoreServiceRecordServices.php','crmeb/app/services/system/SystemClearServices.php',
        'template/admin/src/api/kefu.js','template/admin/src/libs/request.js','template/admin/src/libs/socket.js',
        'template/admin/src/assets/images/moren.jpg','template/admin/src/main.js',
        'template/admin/src/layout/navBars/breadcrumb/userNews.vue',
        'template/admin/src/pages/kefu/appChat/index.vue','template/admin/src/pages/kefu/appChat/mobile/index.vue',
        'template/admin/src/pages/kefu/mobile/chat_list.vue','template/admin/src/pages/kefu/mobile/index.vue','template/admin/src/pages/kefu/pc/index.vue',
        'template/admin/src/pages/kefu/pc/components/chatList.vue','template/admin/src/pages/kefu/pc/components/rightMenu.vue',
        'template/admin/src/pages/kefu/index.vue','crmeb/app/kefuapi/validate/LoginValidate.php','crmeb/crmeb/utils/JwtAuth.php',
        'crmeb/app/services/other/CacheServices.php','crmeb/app/dao/other/CacheDao.php',
        'help/audit-regressions/isolated/ms1-chat-browser.php',
        'help/audit-regressions/isolated/lib/ms1-chat-browser.mjs','help/audit-regressions/isolated/lib/ms1-chat-browser-nginx.conf',
        'help/audit-regressions/isolated/bootstrap.sh',
        'template/uni-app/libs/new_chat.js','template/uni-app/pages/extension/customer_list/chat.vue',
        'help/audit-regressions/isolated/lib/ms1-chat-entry.php','help/audit-regressions/isolated/ms1-cross-app.php',
        'help/audit-regressions/isolated/compose.yml','help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/lib/guard.php',
        'crmeb/crmeb/compat/workerman/Events/Select.php',
        'crmeb/vendor/workerman/workerman/Worker.php','crmeb/vendor/workerman/workerman/Connection/TcpConnection.php',
        'docs/multi-merchant/03-test-environment.md',
        'docs/multi-merchant/06-plan-ms1.md','help/audit-regressions/isolated/README.md'];
    $actual=[]; foreach ($files as $file) {
        $path=audit_repo_dir().'/'.$file;
        if (!is_file($path)) { $evidence['source_availability']=['missing_path'=>$file,'fixtures'=>0,'business_requests'=>0,'chat_startup'=>0]; throw new RuntimeException('ordinary Task7 implementation source is not yet available'); }
        $actual[$file]=hash_file('sha256',$path);
    }
    $handle=file_get_contents(audit_repo_dir().'/crmeb/crmeb/services/workerman/chat/ChatHandle.php');
    audit_expect(is_string($handle)&&str_contains($handle,'function guest_login('),'ordinary guest login implementation is not yet available');
    $evidence['source_inventory']=['actual_files_sha256'=>$actual,'file_count'=>count($actual),
        'layer'=>'SOURCE inventory only; T7B01 is normal browser UI; original stopped matrix remains NOT RUN'];
}

function m7b_io(): array
{
    // Runner sees its original RUN mount; browser receives only these two child binds.
    $secret=audit_run_dir().'/browser-secret'; $exchange=audit_run_dir().'/browser-evidence';
    audit_expect(is_dir($secret)&&!is_link($secret)&&is_writable($secret),'dedicated browser secret mount unavailable');
    audit_expect(is_dir($exchange)&&!is_link($exchange)&&is_writable($exchange),'dedicated browser exchange mount unavailable');
    return [$secret,$exchange];
}

function m7b_resources(): array
{
    $manifest=audit_manifest(); $network=$manifest['network']['name'] ?? ''; $result=[];
    $records=[]; $anchor='';
    foreach (file(audit_run_dir().'/containers.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        $row=json_decode($line,true,512,JSON_THROW_ON_ERROR); $name=$row['labels']['com.docker.compose.service'] ?? '';
        if (in_array($name,['audit-ui','audit-browser'],true)) $records[$name]=$row;
        if ($name==='audit-app') foreach ($row['mounts'] ?? [] as $mount) if (($mount['Destination'] ?? '')==='/run/audit') {
            audit_expect(($mount['Type'] ?? '')==='bind'&&($mount['RW'] ?? null)===true,'RUN source anchor must be the registered audit-app bind');
            audit_expect(($row['id'] ?? null)===($manifest['services']['audit-app']['id'] ?? false)
                &&($row['image_id'] ?? null)===($manifest['services']['audit-app']['image_id'] ?? false),
                'RUN source anchor container matches current registered app');
            $anchor=audit_canonical_host_bind_source((string)$mount['Source']);
        }
    }
    audit_expect($anchor!==''&&($manifest['services']['audit-app']['id'] ?? '')!=='','registered RUN bind source anchor is available');
    foreach (['audit-ui','audit-browser'] as $name) {
        $row=$records[$name] ?? []; $registered=$manifest['services'][$name] ?? [];
        audit_expect(($row['labels']['tianli.audit'] ?? '')==='1'
            &&($row['labels']['tianli.audit.run'] ?? '')===$manifest['run_id']
            &&($row['labels']['com.docker.compose.project'] ?? '')===$manifest['project']
            &&($row['id'] ?? null)===($registered['id'] ?? false)
            &&($row['image_id'] ?? null)===($registered['image_id'] ?? false)
            &&is_string($registered['id'] ?? null)&&preg_match('/^[a-f0-9]{64}$/D',$registered['id'])===1
            &&preg_match('/^sha256:[a-f0-9]{64}$/D',(string)($registered['image_id'] ?? ''))===1
            &&count($row['networks'] ?? [])===1&&isset($row['networks'][$network])
            &&($row['networks'][$network]['IPAddress'] ?? null)===($registered['address'] ?? false)
            &&filter_var($registered['address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,
            'browser/UI resource differs from registered exclusive internal identity');
        foreach ($row['ports'] ?? [] as $binding) audit_expect($binding===null,'browser/UI has a published host port');
        // Root exports these additional HostConfig fields with its resource capture.
        audit_expect(($row['read_only'] ?? null)===true&&($row['privileged'] ?? null)===false
            &&($row['network_mode'] ?? '')!=='host','browser/UI rootfs or host-network setting differs');
        $mounts=[];
        foreach ($row['mounts'] ?? [] as $mount) {
            $destination=$mount['Destination'] ?? ''; $source=(string)($mount['Source'] ?? '');
            audit_expect(!str_contains(strtolower($source.'|'.$destination),'docker.sock')
                &&!str_contains(strtolower($source.'|'.$destination),'docker_engine'),'Docker control mount is prohibited');
            if ($mount['Type']==='volume') {
                audit_expect(str_starts_with((string)$mount['Name'],$manifest['project'].'_')
                    &&isset($manifest['volume_identities'][$mount['Name']]),'browser volume is not a registered exclusive RUN volume');
            } else {
                $normalSource=audit_canonical_host_bind_source($source);
                if ($name==='audit-browser'&&in_array($destination,['/run/audit-browser-secret','/run/audit-browser-evidence'],true)) {
                    $suffix=$destination==='/run/audit-browser-secret' ? '/browser-secret' : '/browser-evidence';
                    audit_expect($normalSource===$anchor.$suffix&&($mount['RW'] ?? null)===($suffix==='/browser-evidence'),
                        'browser input/output must be exactly its registered RUN child directory');
                } else audit_expect(($mount['RW'] ?? null)===false,'browser/UI bind inputs must be read only');
            }
            $mounts[$destination]=['type'=>$mount['Type'],'rw'=>$mount['RW'],'name'=>$mount['Name'] ?? null];
        }
        if ($name==='audit-browser') {
            foreach (['/run/audit-browser-secret'=>false,'/run/audit-browser-evidence'=>true] as $destination=>$rw)
                audit_expect(($mounts[$destination]['type'] ?? '')==='bind'&&($mounts[$destination]['rw'] ?? null)===$rw,
                    'browser dedicated secret/evidence mount differs');
            foreach (['/run/audit','/run/audit-app','/workspace'] as $forbidden) audit_expect(!isset($mounts[$forbidden]),
                'browser must not mount RUN, app secrets or repository');
            audit_expect(count($mounts)===4&&($mounts['/opt/playwright-core']['rw'] ?? null)===false&&($mounts['/opt/ms1-chat-browser.mjs']['rw'] ?? null)===false,
                'browser script and Playwright inputs must be read only');
        } else audit_expect(count($mounts)===2&&($mounts['/srv/admin']['rw'] ?? null)===false
            &&($mounts['/etc/nginx/nginx.conf']['rw'] ?? null)===false,'UI dist/config are the exact two read-only inputs');
        $result[$name]=['id'=>$registered['id'],'image_id'=>$registered['image_id'],'address'=>$registered['address'],
            'network_id'=>$manifest['network']['id'],'mounts'=>$mounts,'host_ports'=>0];
    }
    return $result;
}

function m7b_cache_preflight(PDO $pdo,array $initial,int $deadline,array &$evidence): ?array
{
    audit_expect($deadline>time()&&$deadline<=time()+900,'ordinary browser deadline must be within fifteen minutes');
    audit_expect(strtotime('this week 00:00:00',time())===strtotime('this week 00:00:00',$deadline),
        'ordinary browser window must not cross production calendar-week deletion bounds');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_cache WHERE expire_time<>0 AND expire_time<=?'); $stmt->execute([$deadline]);
    audit_expect((int)$stmt->fetchColumn()===0,'old SQL cache has expired or will expire inside the ordinary browser window');
    foreach ($initial['redis'] as $value) audit_expect($value['deadline']===-1||$value['deadline']>$deadline*1000,
        'original Redis keys must remain current throughout ordinary browser verification');
    $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kf_adv']); $row=$stmt->fetch();
    if (is_array($row)) audit_expect((bool)$row['result'],'existing kf_adv result must be truthy to avoid the legacy default rewrite');
    $evidence['sql_cache_preflight']=['read_only'=>true,'normal_deadline'=>$deadline,'old_expiring_rows_before_deadline'=>0,
        'kf_adv_preexisting'=>is_array($row),'legacy_cleanup_is_empty'=>true,'legacy_default_rewrite_is_empty'=>true];
    return is_array($row) ? $row : null;
}

function m7b_seed_browser(PDO $pdo,string $slug,string $avatar,array &$owners,array &$secrets): array
{
    audit_expect(str_starts_with($avatar,'http://audit-ui:8080/admin/'),'owned avatar must use real internal dist');
    $account='M7B'.substr(hash('sha256',$slug),0,24); $password='M7B'.bin2hex(random_bytes(24)).'x'; $nickname='M7 Browser Kefu'; $now=time();
    audit_expect(preg_match('/^[a-zA-Z0-9]{4,30}$/D',$account)===1,'browser account matches actual LoginValidate');
    $userAccount='M7U'.substr(hash('sha256',$slug.'user'),0,24); $digest=md5($password);
    $secrets=array_merge($secrets,[$account,$userAccount,$password,$digest]);
    foreach (['eb_user'=>$userAccount,'eb_store_service'=>$account] as $table=>$check) {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE account=?'); $stmt->execute([$check]);
        audit_expect((int)$stmt->fetchColumn()===0,'owned browser account starts absent');
    }
    $stmt=$pdo->prepare('INSERT INTO eb_user(account,pwd,nickname,avatar,add_time,last_time,status,is_del,user_type) VALUES(?,?,?,?,?,?,1,0,?)');
    $stmt->execute([$userAccount,$digest,$nickname,$avatar,$now,$now,'h5']); $uid=(int)$pdo->lastInsertId();
    $owners['user'][$uid]=['uid'=>$uid,'account'=>$userAccount,'pwd'=>$digest,'nickname'=>$nickname,'avatar'=>$avatar];
    audit_expect($uid>0,'browser User has a real allocated UID');
    foreach (['eb_store_service'=>'uid=?','eb_store_service_record'=>'user_id=? OR to_uid=?','eb_store_service_log'=>'uid=? OR to_uid=?','eb_wechat_user'=>'uid=?'] as $table=>$where) {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE '.$where); $stmt->execute(substr_count($where,'?')===1 ? [$uid] : [$uid,$uid]);
        audit_expect((int)$stmt->fetchColumn()===0,'owned browser UID has no original orphan chat or WeChat references');
    }
    $hash=password_hash($password,PASSWORD_BCRYPT); audit_expect(is_string($hash),'real browser password has BCRYPT digest'); $secrets[]=$hash;
    $stmt=$pdo->prepare('INSERT INTO eb_store_service(mer_id,uid,online,account,password,avatar,nickname,phone,add_time,status,notify,customer,uniqid) VALUES(0,?,0,?,?,?,?,?,?,1,0,0,?)');
    $stmt->execute([$uid,$account,$hash,$avatar,$nickname,'',$now,'']); $id=(int)$pdo->lastInsertId();
    $owners['store_service'][$id]=['id'=>$id,'uid'=>$uid,'account'=>$account,'password'=>$hash];
    audit_expect($id>0,'browser service has a real allocated ID');
    return ['uid'=>$uid,'service_id'=>$id,'account'=>$account,'password'=>$password,'avatar'=>$avatar];
}

function m7b_kefu_sessions(\think\App $app,string $prefix,int $serviceId,array $initial,array &$ownedKeys,array &$sessions,array &$secrets,array &$evidence): void
{
    $redis=audit_redis(); $tag=$prefix.'CRMEB:'.md5('kefu');
    if ($redis->exists($tag)===0) return;
    audit_expect($redis->type($tag)===Redis::REDIS_SET&&$redis->rawCommand('PEXPIRETIME',$tag)===-1,
        'real UI kefu producer uses its exact permanent type tag');
    $members=$redis->sMembers($tag); audit_expect(count($members)<=2,'ordinary browser owns at most its two UI login issuances');
    foreach ($members as $key) {
        audit_expect(is_string($key)&&!isset($initial['redis'][$key]),'UI session key must start absent from old snapshot');
        $bytes=$redis->get($key);
        if ($bytes===false) { audit_expect(isset($sessions[$key]),'a deleted session must have been registered while its real login was current'); continue; }
        audit_expect(is_string($bytes),'current owned UI session has actual string record');
        $row=unserialize($bytes,['allowed_classes'=>false]);
        audit_expect(is_array($row)&&array_keys($row)===['uid','type','token','exp']&&(int)$row['uid']===$serviceId
            &&$row['type']==='kefu'&&is_string($row['token'])&&is_int($row['exp'])&&$row['exp']>0
            &&$key===$prefix.md5($row['token']),'real UI token cache binds exactly its newly owned service');
        $verified=$app->make(\crmeb\utils\JwtAuth::class)->decodeVerified($row['token']);
        audit_expect(($verified['type'] ?? null)==='kefu'&&(int)($verified['id'] ?? 0)===$serviceId,
            'actual UI issuance has a valid production signature and service subject');
        $payload=json_decode(base64_decode(strtr(explode('.',$row['token'])[1],'-_','+/'),true),true,32,JSON_THROW_ON_ERROR);
        audit_expect(is_int($payload['iat'] ?? null)&&is_int($payload['exp'] ?? null)
            &&$row['exp']===$payload['exp']-$payload['iat']+60,'actual UI whitelist expiry matches JwtAuth source claims');
        $absolute=$redis->rawCommand('PEXPIRETIME',$key);
        audit_expect(is_int($absolute)&&$absolute>=($payload['exp']+60)*1000&&$absolute<=($payload['exp']+60)*1000+1999,
            'actual UI session native deadline matches real issuer times');
        if (!isset($sessions[$key])) {
            $sessions[$key]=['subject'=>$serviceId,'deadline'=>$absolute]; $ownedKeys[]=$key; $secrets[]=$row['token'];
            $evidence['ui_session_registration'][]=['key_sha256'=>hash('sha256',$key),'subject_service_id'=>$serviceId,
                'producer'=>'real UI LoginServices::authLogin -> JwtAuth::createToken','signed_binding_verified'=>true,'absolute_deadline'=>$absolute];
        } else audit_expect($sessions[$key]['deadline']===$absolute,'current UI session deadline stays unchanged');
    }
}

function m7b_guest_checks(array $owners,int $preparedAt,int $deadline,mixed $configuredAvatars): void
{
    $redis=audit_redis();
    foreach ($owners['guest'] as $row) {
        audit_expect($row['expires_at']>=$preparedAt+3600&&$row['expires_at']<=$deadline+3600,
            'ordinary browser guest issue is one hour from this controlled window');
        audit_expect($row['avatar']===''||in_array($row['avatar'],is_array($configuredAvatars) ? $configuredAvatars : [],true),
            'actual guest avatar originates from its unchanged real server configuration');
        foreach ([$row['record_key'],$row['index_key']] as $key) audit_expect($redis->rawCommand('PEXPIRETIME',$key)===$row['expires_at']*1000,
            'actual owned guest cap/index retain exact producer absolute expiry');
    }
}

function m7b_client_http(array $event,array &$evidence): void
{
    $keys=array_keys($event); sort($keys); $wanted=['business_status','http','method','path','stage']; sort($wanted);
    audit_expect($keys===$wanted&&in_array($event['stage'],['desktop','mobile'],true)&&is_int($event['http'])&&$event['http']===200
        &&(is_int($event['business_status'])||$event['business_status']===null),'browser HTTP handshake accepts only white-listed successful metadata');
    $allowedGet=['/adminapi/get_workerman_url','/adminapi/custom_admin_js','/kefuapi/config','/kefuapi/service/info',
        '/kefuapi/user/record','/kefuapi/service/list','/kefuapi/service/cate','/kefuapi/service/speechcraft','/kefuapi/service/transfer_list',
        '/kefuapi/tourist/user','/kefuapi/tourist/chat','/kefuapi/tourist/adv'];
    audit_expect(($event['method']==='GET'&&in_array($event['path'],$allowedGet,true))
        ||($event['method']==='POST'&&in_array($event['path'],['/kefuapi/login','/kefuapi/user/logout'],true)),
        'ordinary UI handshake cannot introduce another business request');
    audit_expect($event['path']==='/adminapi/custom_admin_js' ? $event['business_status']===null : $event['business_status']===200,
        'normal browser HTTP source returns its real success envelope');
    $evidence['client_http'][]=$event;
}

function m7b_control_geometry(array $checks): void
{
    $controls=array_values(array_filter($checks,static fn(array $row): bool=>isset($row['buttons'])));
    audit_expect(count($controls)===3&&count(array_filter($controls,static fn(array $row): bool=>($row['stage'] ?? '')==='desktop'))===1
        &&count(array_filter($controls,static fn(array $row): bool=>($row['stage'] ?? '')==='mobile'))===2,'browser records desktop and both actual mobile list control states');
    foreach ($controls as $row) {
        $parent=$row['parent'] ?? []; $buttons=$row['buttons'] ?? [];
        audit_expect(count($buttons)===2&&array_column($buttons,'label')===['用户列表','游客列表'],
            'both actual normal tab buttons are present in their source order');
        audit_expect(($buttons[1]['pressed'] ?? null)==='true','actual visitor tab is visibly selected');
        foreach ($buttons as $button) {
            audit_expect(($button['type'] ?? '')==='button'&&($button['display'] ?? '')==='flex'
                &&($button['align_items'] ?? '')==='center'&&($button['justify_content'] ?? '')==='center',
                'actual button semantics and label group use centered layout');
            $bounds=$button['bounds'] ?? []; $label=$button['label_bounds'] ?? [];
            foreach ([$parent,$bounds,$label] as $rect) foreach (['left','right','top','bottom'] as $axis)
                audit_expect((is_int($rect[$axis] ?? null)||is_float($rect[$axis] ?? null))&&is_finite((float)$rect[$axis]),'actual geometry contains finite browser coordinates');
            audit_expect(($bounds['width'] ?? 0)>0&&($bounds['height'] ?? 0)>0
                &&$bounds['left']>=$parent['left']-1&&$bounds['right']<=$parent['right']+1
                &&$bounds['top']>=$parent['top']-1&&$bounds['bottom']<=$parent['bottom']+1,'actual control bounds fit the parent content area');
            $tolerance=max(2,(float)($button['font_size'] ?? 0)*0.2);
            audit_expect(abs(($label['left']+$label['right']-$bounds['left']-$bounds['right'])/2)<=$tolerance
                &&abs(($label['top']+$label['bottom']-$bounds['top']-$bounds['bottom'])/2)<=$tolerance,'actual labels are centered on both axes');
            if (($button['pressed'] ?? '')==='true') audit_expect((int)($button['font_weight'] ?? 0)>=600
                &&(($button['box_shadow'] ?? 'none')!=='none'||(($button['indicator_display'] ?? 'none')!=='none'&&(float)($button['indicator_height'] ?? 0)>0)),
                'actual selected tab feedback includes shape and text weight');
        }
    }
    $avatars=array_values(array_filter($checks,static fn(array $row): bool=>($row['check'] ?? '')==='actual_staff_message_avatar'));
    audit_expect(count($avatars)===4,'actual internal staff message avatar is observed in all four normal pages');
    foreach (['desktop','mobile'] as $stage) foreach (['kefu','guest'] as $kind) {
        $rows=array_values(array_filter($avatars,static fn(array $row): bool=>($row['stage'] ?? '')===$stage&&($row['kind'] ?? '')===$kind));
        audit_expect(count($rows)===1&&($rows[0]['complete'] ?? null)===true&&($rows[0]['natural_width_positive'] ?? null)===true,
            'actual message staff avatar loaded successfully from its internal source');
    }
}

function m7b_avatar_source_ref(array $row,string $siteUrl,array $sources): string
{
    if ($row['source_kind']==='staff') return $sources['path'];
    if ($row['source_kind']==='guest_default') {
        $default=$sources['default_avatar'];
        return $default['representation']==='inline' ? 'inline:image/jpeg:'.$default['sha256'] : $default['path'];
    }
    $avatar=$row['avatar'];
    $produced=str_starts_with($avatar,'http')||str_starts_with($avatar,'//') ? $avatar : $siteUrl.str_replace('\\','/',$avatar);
    if (str_starts_with($produced,'//')) $produced='http:'.$produced;
    elseif (str_starts_with($produced,'/')) $produced='http://audit-ui:8080'.$produced;
    elseif (!str_starts_with($produced,'http')) $produced='http://audit-ui:8080/kefu/'.$produced;
    $path=parse_url($produced,PHP_URL_PATH);
    audit_expect(str_starts_with($produced,'http://audit-ui:8080/')&&is_string($path)
        &&parse_url($produced,PHP_URL_QUERY)===null&&parse_url($produced,PHP_URL_FRAGMENT)===null
        &&!preg_match('~(?:^|/)\.{1,2}(?:/|$)~D',$path),'normal configured guest avatar resolves to its actual internal resource');
    return $path;
}

function m7b_browser_result(string $exchange,string $run,string $source,array $users,array $owners,array $messages,array $evidence,string $avatarSiteUrl): array
{
    $file=$exchange.'/browser.json'; audit_expect(is_file($file)&&!is_link($file)&&filesize($file)<131072,'completed ordinary browser result is available');
    $result=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    audit_expect(is_array($result)&&($result['run_id'] ?? '')===$run&&($result['source_sha'] ?? '')===$source
        &&($result['status'] ?? '')==='PASS'&&($result['contexts_closed'] ?? null)===true,'ordinary browser reports actual success and all normal contexts closed');
    audit_expect(($result['browser_container']['id'] ?? '')===$evidence['resources']['audit-browser']['id']
        &&($result['browser_container']['image_id'] ?? '')===$evidence['resources']['audit-browser']['image_id']
        &&is_int($result['node_pid'] ?? null)&&$result['node_pid']>0,'real browser worker reports its registered container and current Node PID');
    $stages=$result['stages'] ?? []; $wantedStages=[['name'=>'desktop','width'=>1440,'height'=>900],['name'=>'mobile','width'=>390,'height'=>844]];
    audit_expect(is_array($stages)&&count($stages)===2,'ordinary browser has exactly two viewport stages');
    foreach ($wantedStages as $i=>$wantedStage) { $actualStage=$stages[$i] ?? []; ksort($actualStage); ksort($wantedStage);
        audit_expect($actualStage===$wantedStage,'ordinary browser has actual two required viewport stages'); }
    audit_expect(($result['login_http_success'] ?? null)===2&&($result['guest_http_success'] ?? null)===2,'browser reports its two real UI logins and two guest issues');
    $logins=array_values(array_filter($evidence['client_http'],static fn(array $row): bool=>$row['path']==='/kefuapi/login'));
    $guests=array_values(array_filter($evidence['client_http'],static fn(array $row): bool=>$row['path']==='/kefuapi/tourist/user'));
    audit_expect(count($logins)===2&&count($guests)===2&&array_column($logins,'stage')===['desktop','mobile']&&array_column($guests,'stage')===['desktop','mobile'],
        'real-time handshake independently recorded exactly the normal two-stage login and guest HTTP');
    $guestIds=array_keys($owners['guest']); sort($guestIds,SORT_NUMERIC); $guestByStage=['desktop'=>max($guestIds),'mobile'=>min($guestIds)];
    $acks=$result['identity_acks'] ?? []; audit_expect(count($acks)===4,'browser records exact four current connection identity ACKs');
    foreach (['desktop','mobile'] as $stage) foreach (['kefu','guest'] as $kind) {
        $rows=array_values(array_filter($acks,static fn(array $row): bool=>($row['stage'] ?? null)===$stage&&($row['kind'] ?? null)===$kind));
        $id=$kind==='kefu' ? $users['kefu']['uid'] : $guestByStage[$stage];
        audit_expect(count($rows)===1&&($rows[0]['uid'] ?? null)===$id,'normal browser ACK equals the actual source-owned identity');
        if ($kind==='guest'&&isset($rows[0]['expires_at'])) audit_expect($rows[0]['expires_at']===$owners['guest'][$id]['expires_at'],'guest ACK expiry equals native record');
        $room=array_filter($result['room_acks'] ?? [],static fn(array $row): bool=>($row['stage'] ?? null)===$stage
            &&($row['uid'] ?? null)===($kind==='kefu' ? $guestByStage[$stage] : $users['kefu']['uid'])&&($row['num'] ?? null)===0);
        audit_expect(count($room)>=1,'each actual browser peer confirms its selected normal target room');
    }
    audit_expect(count($result['normal_texts'] ?? [])===4&&count($owners['store_service_log'])===4&&count($owners['store_service_record'])===4,
        'browser four ordinary texts have exactly four real log and four conversation rows');
    foreach ($guestByStage as $stage=>$peer) foreach ([[$users['kefu']['uid'],$peer],[$peer,$users['kefu']['uid']]] as [$actor,$target]) {
        $records=array_values(array_filter($owners['store_service_record'],static fn(array $row): bool=>$row['user_id']===$actor&&$row['to_uid']===$target&&$row['is_tourist']===1));
        audit_expect(count($records)===1,'each browser guest has exactly its two real server-classified conversation directions');
        $stmt=audit_db()->prepare('SELECT * FROM eb_store_service_record WHERE id=?'); $stmt->execute([$records[0]['id']]); $actual=$stmt->fetch();
        audit_expect(is_array($actual)&&$actual['message']===$messages[$stage.'_kefu']&&(int)$actual['message_type']===1
            &&(int)$actual['type']===3&&(int)$actual['mssage_num']===0,'each actual guest conversation retains the delivered normal reply without unread messages');
    }
    $preview=$result['list_preview'] ?? null;
    audit_expect(is_array($preview),'actual mobile own-row HTTP preview metadata is present');
    $previewKeys=array_keys($preview); sort($previewKeys);
    audit_expect($previewKeys===['client_http_sequence','is_tourist','message_type','path','preview_sha256','received_via','record_id','stage','to_uid','user_id'],
        'mobile preview evidence contains only its finite metadata fields');
    $mobileRecords=array_values(array_filter($owners['store_service_record'],static fn(array $row): bool=>
        $row['user_id']===$users['kefu']['uid']&&$row['to_uid']===$guestByStage['mobile']&&$row['is_tourist']===1));
    audit_expect(count($mobileRecords)===1&&$preview['stage']==='mobile'&&$preview['record_id']===$mobileRecords[0]['id']
        &&$preview['user_id']===$users['kefu']['uid']&&$preview['to_uid']===$guestByStage['mobile']
        &&$preview['is_tourist']===1&&$preview['message_type']===1&&$preview['received_via']==='http'
        &&$preview['path']==='/kefuapi/user/record'&&is_int($preview['client_http_sequence'])
        &&$preview['client_http_sequence']>0&&$preview['client_http_sequence']<=count($evidence['client_http']),
        'mobile preview is bound to the current RUN staff and unique guest record');
    $previewHttp=$evidence['client_http'][$preview['client_http_sequence']-1];
    audit_expect($previewHttp['stage']==='mobile'&&$previewHttp['method']==='GET'&&$previewHttp['path']==='/kefuapi/user/record'
        &&$previewHttp['http']===200&&$previewHttp['business_status']===200,'mobile preview sequence is its actual registered successful HTTP response');
    $stmt=audit_db()->prepare('SELECT id,uid,to_uid,msn,msn_type,add_time FROM eb_store_service_log '
        . 'WHERE is_tourist=1 AND ((uid=? AND to_uid=?) OR (uid=? AND to_uid=?)) ORDER BY id DESC LIMIT 1');
    $stmt->execute([$users['kefu']['uid'],$guestByStage['mobile'],$guestByStage['mobile'],$users['kefu']['uid']]);
    $last=$stmt->fetch();
    audit_expect(is_array($last)&&isset($owners['store_service_log'][(int)$last['id']])
        &&(int)$last['uid']===$users['kefu']['uid']&&(int)$last['to_uid']===$guestByStage['mobile']
        &&(int)$last['msn_type']===1&&$last['msn']===$messages['mobile_kefu'],'independent DB last message is the delivered current mobile staff reply');
    $stmt=audit_db()->prepare('SELECT message,message_type,update_time FROM eb_store_service_record WHERE id=? AND user_id=? AND to_uid=? AND is_tourist=1 LIMIT 1');
    $stmt->execute([$mobileRecords[0]['id'],$users['kefu']['uid'],$guestByStage['mobile']]); $storedPreview=$stmt->fetch();
    audit_expect(is_array($storedPreview)&&$storedPreview['message']===$last['msn']&&(int)$storedPreview['message_type']===1
        &&(int)$storedPreview['update_time']>=(int)$last['add_time'],'current staff conversation preview and ordering time follow the real last bidirectional message');
    $expectedPreview=mb_substr($last['msn'],0,10,'UTF-8');
    audit_expect(is_string($preview['preview_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$preview['preview_sha256'])===1
        &&hash_equals(hash('sha256',$expectedPreview),$preview['preview_sha256']),'HTTP own-row preview equals the native first ten Unicode characters without suffix');
    foreach ($messages as $name=>$message) {
        [$stage,$kind]=explode('_',$name); $from=$kind==='guest' ? $guestByStage[$stage] : $users['kefu']['uid'];
        $to=$kind==='guest' ? $users['kefu']['uid'] : $guestByStage[$stage];
        $reported=array_values(array_filter($result['normal_texts'],static fn(array $row): bool=>($row['stage'] ?? null)===$stage
            &&($row['sender_uid'] ?? null)===$from&&($row['recipient_uid'] ?? null)===$to
            &&($row['is_tourist'] ?? null)===1&&($row['message_sha256'] ?? null)===hash('sha256',$message)
            &&($row['received_via'] ?? null)===($kind==='guest' ? 'history' : 'websocket')));
        $stored=array_values(array_filter($owners['store_service_log'],static fn(array $row): bool=>$row['uid']===$from&&$row['to_uid']===$to
            &&$row['msn']===$message&&$row['msn_type']===1&&$row['is_tourist']===1));
        audit_expect(count($reported)===1&&count($stored)===1,'each real browser message is server classified and matches its exact persisted log');
    }
    $avatars=$result['message_avatars'] ?? null;
    audit_expect(is_array($avatars)&&count($avatars)===8,'the two actual messages have loaded avatars in all four normal chat views');
    foreach ($messages as $name=>$message) {
        [$stage,$senderKind]=explode('_',$name); $guest=$guestByStage[$stage];
        $from=$senderKind==='guest' ? $guest : $users['kefu']['uid']; $to=$senderKind==='guest' ? $users['kefu']['uid'] : $guest;
        $logs=array_values(array_filter($owners['store_service_log'],static fn(array $row): bool=>$row['uid']===$from&&$row['to_uid']===$to
            &&$row['msn']===$message&&$row['msn_type']===1&&$row['is_tourist']===1));
        audit_expect(count($logs)===1,'avatar sender and message bind to one independently owned native log');
        $sourceKind=$senderKind==='kefu' ? 'staff' : ($owners['guest'][$guest]['avatar']==='' ? 'guest_default' : 'guest_config');
        $sourceRef=m7b_avatar_source_ref(['source_kind'=>$sourceKind,'avatar'=>$owners['guest'][$guest]['avatar']],$avatarSiteUrl,$evidence['staff_avatar_source']);
        foreach (['kefu','guest'] as $kind) {
            $rows=array_values(array_filter($avatars,static fn(array $row): bool=>($row['stage'] ?? '')===$stage&&($row['kind'] ?? '')===$kind
                &&($row['sender_uid'] ?? null)===$from&&($row['recipient_uid'] ?? null)===$to));
            audit_expect(count($rows)===1,'each actual view has one avatar for each source-owned sender');
            $row=$rows[0]; $keys=array_keys($row); sort($keys);
            audit_expect($keys===['bounds','complete','kind','lazy_state','log_id','message_sha256','natural_height','natural_width',
                'recipient_uid','screenshot_path','sender_uid','source_kind','source_ref_sha256','stage'],'message avatar evidence contains only finite metadata');
            audit_expect(($row['log_id'] ?? null)===(int)$logs[0]['id']&&$row['message_sha256']===hash('sha256',$message)
                &&$row['source_kind']===$sourceKind&&$row['source_ref_sha256']===hash('sha256',$sourceRef)
                &&$row['complete']===true&&is_int($row['natural_width'])&&$row['natural_width']>0
                &&is_int($row['natural_height'])&&$row['natural_height']>0
                &&$row['lazy_state']===($stage==='mobile'&&$kind==='guest' ? 'native' : 'loaded')
                &&$row['screenshot_path']===$stage.'-'.$kind.'.png','actual image binds the native actor, loaded source, full text and rendered screenshot');
            $bounds=$row['bounds']; $axes=array_keys($bounds); sort($axes);
            audit_expect($axes===['bottom','height','left','right','top','width'],'message avatar has finite rectangle metadata');
            foreach ($bounds as $coordinate) audit_expect((is_int($coordinate)||is_float($coordinate))&&is_finite((float)$coordinate),'message avatar coordinates are finite');
            $width=$stage==='desktop' ? 1440 : 390; $height=$stage==='desktop' ? 900 : 844;
            audit_expect($bounds['width']>0&&$bounds['height']>0&&$bounds['left']>=-1&&$bounds['top']>=-1
                &&$bounds['right']<=$width+1&&$bounds['bottom']<=$height+1,'actual message avatar fits the rendered screenshot viewport');
        }
    }
    $brands=$result['brand_checks'] ?? null;
    audit_expect(is_array($brands)&&count($brands)===3,'three existing header logos are separate branding observations');
    foreach ([['desktop','kefu'],['mobile','kefu'],['desktop','guest']] as [$stage,$kind]) {
        $rows=array_values(array_filter($brands,static fn(array $row): bool=>($row['stage'] ?? '')===$stage&&($row['kind'] ?? '')===$kind));
        audit_expect(count($rows)===1&&($rows[0]['check'] ?? '')==='header_branding'&&($rows[0]['complete'] ?? null)===true
            &&($rows[0]['natural_width_positive'] ?? null)===true,'header branding loads normally and is not counted as message evidence');
        $keys=array_keys($rows[0]); sort($keys);
        audit_expect($keys===['check','complete','kind','natural_width_positive','stage'],'header evidence contains only finite branding metadata');
    }
    $shots=$result['screenshots'] ?? []; audit_expect(count($shots)===4,'actual browser provides the four required rendered screenshots');
    foreach (['desktop','mobile'] as $stage) foreach (['kefu','guest'] as $kind) {
        $rows=array_values(array_filter($shots,static fn(array $row): bool=>($row['stage'] ?? null)===$stage&&($row['kind'] ?? null)===$kind));
        audit_expect(count($rows)===1&&preg_match('/^[a-z0-9-]+\.png$/D',(string)($rows[0]['path'] ?? ''))===1,'render evidence has exact safe screenshot metadata');
        $path=$exchange.'/'.$rows[0]['path']; audit_expect(is_file($path)&&!is_link($path)&&filesize($path)>64,'render screenshot exists');
        audit_expect(file_get_contents($path,false,null,0,8)==="\x89PNG\r\n\x1a\n",'render evidence is a real PNG');
    }
    $controlShot=$result['control_screenshot'] ?? []; ksort($controlShot);
    audit_expect($controlShot===['kind'=>'kefu_list','path'=>'mobile-kefu-list.png','stage'=>'mobile']
        &&is_file($exchange.'/mobile-kefu-list.png')&&!is_link($exchange.'/mobile-kefu-list.png')
        &&filesize($exchange.'/mobile-kefu-list.png')>64
        &&file_get_contents($exchange.'/mobile-kefu-list.png',false,null,0,8)==="\x89PNG\r\n\x1a\n",'actual mobile list control screenshot is separate from the four chat screenshots');
    audit_expect(is_array($result['control_checks'] ?? null),'real browser geometry observations are present');
    m7b_control_geometry($result['control_checks']);
    $actions=$result['action_controls'] ?? [];
    audit_expect(is_array($actions)&&count($actions)===3,'actual mobile actions record both sends and normal return');
    foreach ([['guest','发送','#iconfasong'],['kefu','发送','#iconfasong'],['kefu','返回会话列表','#iconfanhui']] as [$kind,$label,$symbol]) {
        $rows=array_values(array_filter($actions,static fn(array $row): bool=>($row['stage'] ?? null)==='mobile'
            &&($row['kind'] ?? null)===$kind&&($row['label'] ?? null)===$label));
        audit_expect(count($rows)===1,'actual modified action has one finite geometry observation');
        $row=$rows[0]; $b=$row['bounds'] ?? []; $p=$row['parent'] ?? []; $i=$row['icon'] ?? [];
        audit_expect(($row['type'] ?? null)==='button'&&($row['disabled'] ?? null)===false&&($row['symbol'] ?? null)===$symbol
            &&($row['display'] ?? null)==='flex'&&($row['align_items'] ?? null)==='center'&&($row['justify_content'] ?? null)==='center',
            'actual modified action is an enabled centered native button with its local symbol');
        foreach ([$b,$p,$i] as $rect) foreach (['left','right','top','bottom','width','height'] as $axis)
            audit_expect((is_int($rect[$axis] ?? null)||is_float($rect[$axis] ?? null))&&is_finite((float)$rect[$axis]),'action coordinates are finite');
        audit_expect($b['width']>=44&&$b['height']>=44&&$b['left']>=$p['left']-1&&$b['right']<=$p['right']+1
            &&$b['top']>=$p['top']-1&&$b['bottom']<=$p['bottom']+1&&$i['width']>0&&$i['height']>0,
            'actual mobile action fits its parent and remains a full click target');
        audit_expect(abs(($i['left']+$i['right']-$b['left']-$b['right'])/2)<=2
            &&abs(($i['top']+$i['bottom']-$b['top']-$b['bottom'])/2)<=2,'actual local SVG is centered on both axes');
    }
    audit_expect(is_int($result['external_requests_blocked_count'] ?? null)&&$result['external_requests_blocked_count']>=0,
        'external requests are blocked before send and counted separately');
    return ['browser_result_sha256'=>hash_file('sha256',$file),'actual_http'=>count($evidence['client_http']),
        'ui_logins'=>2,'guest_issues'=>2,'identity_acks'=>4,'ordinary_texts'=>4,'real_log_rows'=>4,'real_record_rows'=>4,
        'rendered_chat_screenshots'=>4,'rendered_control_screenshots'=>1,'controls_geometry_checked'=>true,'manual_screenshot_review'=>'PENDING ROOT',
        'list_preview'=>$preview,'list_preview_independently_verified'=>true,
        'message_avatar_count'=>8,'staff_message_avatar_count'=>4,'guest_message_avatar_count'=>4,
        'message_avatars'=>$avatars,'header_branding_not_message_evidence'=>true,
        'external_requests_blocked_count'=>$result['external_requests_blocked_count']];
}

function m7b_write_private(string $file,array $value): void
{
    audit_expect(!file_exists($file)&&!is_link($file),'browser private input already exists');
    $stream=fopen($file,'xb'); audit_expect(is_resource($stream),'browser private input cannot be created');
    try { audit_expect(chmod($file,0600),'private input mode cannot be enforced'); $bytes=json_encode($value,JSON_THROW_ON_ERROR);
        audit_expect(fwrite($stream,$bytes)===strlen($bytes)&&fflush($stream),'private input write incomplete'); }
    finally { fclose($stream); }
}

function m7b_serve(): array
{
    [$secretDir,$exchange]=m7b_io(); $run=(string)getenv('AUDIT_RUN_ID'); $source=(string)getenv('AUDIT_SOURCE_SHA');
    $preparedAt=time(); $deadline=$preparedAt+900; $evidence=['run_id'=>$run,'source_sha'=>$source,'guard_mode'=>'read-only',
        'ddl_denial_probe'=>'NOT RUN','client_http'=>[],'ui_session_registration'=>[],'stopped_cases'=>'A07/M07/K01 invalid/old fault/concurrency/disconnect/recovery NOT RUN'];
    $owners=['user'=>[],'store_service'=>[],'store_service_log'=>[],'store_service_record'=>[],'guest'=>[],'sequence'=>null,'sql_cache'=>[]];
    $ownedKeys=[]; $secrets=[]; $sessions=[]; $old=[]; $users=[]; $messages=[]; $sequenceBefore=null; $pdo=null; $config=[];
    $appAvailable=false; $chatAvailable=false; $sequenceCaptured=false; $failure=null; $cleanupFailure=null; $finishRequested=false;
    $appGeneration=''; $chatGeneration=''; $revision=''; $lastAck=''; $initial=null;
    $counterTables=['eb_user','eb_store_service','eb_store_service_log','eb_store_service_record'];
    try {
        $initial=m7b_snapshot(); $evidence['before']=m7b_public_snapshot($initial); m7b_sources($evidence);
        audit_expect(count($initial['names'])===163,'ordinary browser uses five MS1 tables plus all installed tables');
        $evidence['resources']=m7b_resources(); $pdo=audit_db(); m7b_preflight($pdo,$evidence);
        $kfBefore=m7b_cache_preflight($pdo,$initial,$deadline,$evidence);
        foreach (['user'=>'uid','store_service'=>'id','store_service_log'=>'id','store_service_record'=>'id'] as $table=>$pk) $old[$table]=m7b_row_hashes($pdo,'eb_'.$table,$pk);
        $old['cache_without_sequence']=m7b_row_hashes($pdo,'eb_cache','key',['kefu_tourist_uid_seq']);
        $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kefu_tourist_uid_seq']); $row=$stmt->fetch();
        $sequenceBefore=is_array($row) ? $row : null; $sequenceCaptured=true;
        if ($sequenceBefore!==null) audit_expect((int)$row['expire_time']===0&&preg_match('/^(0|[1-9][0-9]*)$/D',(string)$row['result'])===1
            &&strlen($row['result'])<=10&&(int)$row['result']<=2147483645,'existing permanent sequence has normal room for exactly two browser guests');
        else foreach (['eb_store_service_log'=>'uid<0 OR to_uid<0','eb_store_service_record'=>'user_id<0 OR to_uid<0'] as $table=>$where)
            audit_expect((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'` WHERE '.$where)->fetchColumn()===0,'new guest ledger has no old negative history');
        $avatarFile=$secretDir.'/dist-avatar.json'; audit_expect(is_file($avatarFile)&&!is_link($avatarFile),'actual final dist avatar source receipt is available');
        $avatarSource=json_decode(file_get_contents($avatarFile),true,16,JSON_THROW_ON_ERROR); $avatarPath=$avatarSource['path'] ?? '';
        audit_expect(preg_match('~^/admin/system_static/img/[a-zA-Z0-9_.-]+\.png$~D',$avatarPath)===1
            &&($avatarSource['run_id'] ?? '')===$run&&($avatarSource['source_sha'] ?? '')===$source
            &&preg_match('/^[a-f0-9]{64}$/D',(string)($avatarSource['sha256'] ?? ''))===1
            &&is_int($avatarSource['bytes'] ?? null)&&$avatarSource['bytes']>64&&($avatarSource['png'] ?? null)===true,
            'browser staff avatar source is a real PNG from final dist for this RUN/source');
        $evidence['staff_avatar_source']=$avatarSource;
        $default=$avatarSource['default_avatar'] ?? null; $defaultFile=audit_repo_dir().'/template/admin/src/assets/images/moren.jpg';
        audit_expect(is_array($default),'actual build receipt includes the normal local default avatar');
        $defaultKeys=array_keys($default); sort($defaultKeys);
        audit_expect($defaultKeys===['bytes','jpeg','path','representation','sha256','source_sha256']
            &&in_array($default['representation'],['inline','file'],true)&&$default['jpeg']===true
            &&is_int($default['bytes'])&&$default['bytes']>64&&preg_match('/^[a-f0-9]{64}$/D',(string)$default['sha256'])===1
            &&is_file($defaultFile)&&!is_link($defaultFile)&&$default['source_sha256']===hash_file('sha256',$defaultFile)
            &&file_get_contents($defaultFile,false,null,0,3)==="\xff\xd8\xff"
            &&($default['representation']==='inline' ? $default['path']===null :
                preg_match('~^/admin/system_static/img/[a-zA-Z0-9_.-]+\.jpe?g$~D',(string)$default['path'])===1),
            'normal default receipt binds the actual compiled JPEG representation to the unchanged public source asset');
        $config=audit_config(); $prefix='audit:http-lang:'.$run.':'.$preparedAt.':'; $app=m7b_app($config,$prefix);
        m7b_warm_language($app,$prefix,$ownedKeys,$evidence); $system=m7b_system_warm($app,$prefix,$ownedKeys,$evidence);
        if ($kfBefore===null) {
            $created=['key'=>'kf_adv','result'=>json_encode(''),'expire_time'=>0,'add_time'=>time()];
            $owners['sql_cache']['kf_adv']=$created; // Exact source default registered before insert; no getDbCache/purge.
            $stmt=$pdo->prepare('INSERT INTO eb_cache(`key`,result,expire_time,add_time) VALUES(?,?,?,?)'); $stmt->execute(array_values($created));
            $evidence['kf_adv_preparation']=['new_owned_default_row'=>true,'producer'=>'Common::getKfAdv / CacheServices source default JSON empty string / expire 0',
                'legacy_getDbCache_invoked_in_prepare'=>false];
        } else $evidence['kf_adv_preparation']=['new_owned_default_row'=>false,'preexisting_row_untouched'=>true];
        $slug=$run.':'.bin2hex(random_bytes(8)); $users['kefu']=m7b_seed_browser($pdo,$slug,'http://audit-ui:8080'.$avatarPath,$owners,$secrets);
        foreach (['desktop_guest','desktop_kefu','mobile_guest','mobile_kefu'] as $name) {
            $text=$name.' '.substr(hash('sha256',$slug.'|'.$name),0,40); $messages[$name]=$text; $secrets[]=$text;
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_wechat_key WHERE key_type=1 AND (`keys`=? OR `keys` LIKE ?)'); $stmt->execute([$text,'%'.$text.'%']);
            audit_expect((int)$stmt->fetchColumn()===0,'normal unique browser text does not match an installed automatic response');
        }
        audit_expect(count(array_unique(array_map(static fn(string $text): string=>mb_substr($text,0,10,'UTF-8'),$messages)))===4,
            'the four normal text previews have distinct first ten Unicode characters');
        m7b_register_keys([$prefix.'CRMEB:'.md5('kefu')],$ownedKeys); // Token never issued during prepare.
        m7b_old_rows($pdo,$old,$owners,$sequenceBefore);
        $service=audit_manifest()['services']['audit-chat'] ?? []; audit_expect(isset($service['id'],$service['image_id'],$service['runtime_volume']),'registered chat is available');
        $chatAvailable=true; audit_write_json(audit_run_dir().'/ms1-chat-start.json',['run_id'=>$run,'container_id'=>$service['id'],'image_id'=>$service['image_id']]);
        $evidence['chat_start']=m7b_chat_control('start',['cache_prefix'=>$prefix]); $chatGeneration=$evidence['chat_start']['generation_revision'];
        audit_expect($evidence['chat_start']['listener_ports']===[40002,40003]&&$evidence['chat_start']['admin_listener_open']===false,'ordinary browser only has Chat/Channel listeners');
        $appAvailable=true; audit_app_start(['CACHE_PREFIX'=>$prefix]); $appGeneration=audit_read_control_json(audit_run_dir().'/app-status.json')['generation_revision'] ?? '';
        $evidence['startup_readiness_source']='existing audit_app_start GET /adminapi/login/info; separate from browser client_http measurements';
        audit_expect(is_string($chatGeneration)&&$chatGeneration!==''&&is_string($appGeneration)&&$appGeneration!=='','browser has actual registered app/chat generations');
        m7b_write_private($secretDir.'/browser-login.json',['run_id'=>$run,'source_sha'=>$source,'url'=>'http://audit-ui:8080',
            'account'=>$users['kefu']['account'],'password'=>$users['kefu']['password'],'staff_uid'=>$users['kefu']['uid'],
            'staff_avatar'=>$users['kefu']['avatar'],'default_avatar'=>$default,'guest_avatar_site_url'=>(string)$system['site_url'],'texts'=>$messages]);
        audit_write_json($secretDir.'/ready.json',['run_id'=>$run,'source_sha'=>$source,'guard_mode'=>'read-only','staff_uid'=>$users['kefu']['uid'],
            'generation'=>$chatGeneration,'app_generation'=>$appGeneration,'normal_deadline'=>$deadline,
            'browser_identity'=>$evidence['resources']['audit-browser']]);
        while (!$finishRequested) {
            audit_expect(time()<=$deadline,'ordinary browser verification exceeded its preflight window');
            $control=audit_read_control_json($exchange.'/control.json');
            if (($control['run_id'] ?? null)===$run&&($control['revision'] ?? '')!==$revision) {
                $revision=$control['revision'] ?? ''; audit_expect(is_string($revision)&&preg_match('/^[a-f0-9]{16}$/D',$revision)===1,'browser control revision differs');
                $action=$control['action'] ?? ''; audit_expect(in_array($action,['client_http','finish'],true),'ordinary browser control action differs');
                $allowed=$action==='client_http' ? ['action','event','revision','run_id'] : ['action','revision','run_id']; $keys=array_keys($control); sort($keys); sort($allowed);
                audit_expect($keys===$allowed,'browser control contains only metadata and never private response data');
                if ($action==='client_http') m7b_client_http($control['event'],$evidence);
                $observation=m7b_snapshot(); $ownedBefore=$ownedKeys;
                m7b_kefu_sessions($app,$prefix,$users['kefu']['service_id'],$initial,$ownedKeys,$sessions,$secrets,$evidence);
                m7b_guest_discover($pdo,$config,$sequenceBefore,$owners,$ownedKeys,$secrets); m7b_guest_checks($owners,$preparedAt,$deadline,$system['tourist_avatar']);
                m7b_discover_chat($pdo,$users,array_values($messages),$owners);
                m7b_old_rows($pdo,$old,$owners,$sequenceBefore);
                $unregisteredBefore=array_values(array_diff(array_keys($observation['redis']),array_keys($initial['redis']),$ownedBefore));
                $newVerifiedKeys=array_values(array_diff($ownedKeys,$ownedBefore));
                $evidence['last_ownership_observation']=['snapshot_new_key_hashes_before_registration'=>array_map(static fn(string $key): string=>hash('sha256',$key),$unregisteredBefore),
                    'verified_producer_registration_added_key_hashes'=>array_map(static fn(string $key): string=>hash('sha256',$key),$newVerifiedKeys),
                    'unregistered_snapshot_key_hashes_after_registration'=>array_map(static fn(string $key): string=>hash('sha256',$key),array_values(array_diff($unregisteredBefore,$ownedKeys)))];
                m7b_same($initial,$observation,['eb_user','eb_store_service','eb_store_service_log','eb_store_service_record','eb_cache'],$ownedKeys,$counterTables);
                if ($action==='finish') {
                    $finishRequested=true; audit_expect(count($owners['guest'])===2,'ordinary browser has exactly its two real guests');
                    $evidence['browser_observations']=m7b_browser_result($exchange,$run,$source,$users,$owners,$messages,$evidence,(string)$system['site_url']);
                    audit_app_request('observe'); $appNow=audit_read_control_json(audit_run_dir().'/app-status.json'); $chatNow=m7b_chat_control('observe');
                    audit_expect(($appNow['generation_revision'] ?? '')===$appGeneration&&($chatNow['generation_revision'] ?? '')===$chatGeneration,
                        'finish observes the same registered normal app/chat generation');
                    $evidence['app_generation_observation']=$appNow; $evidence['chat_generation_observation']=$chatNow;
                } else audit_write_json($exchange.'/status.json',['run_id'=>$run,'revision'=>$revision,'status'=>'client_http','registered_current_source'=>true]);
                $lastAck=$revision;
            }
            if (!$finishRequested) usleep(50000);
        }
    } catch (Throwable $error) { $failure=m7b_diag($error); $evidence['failure_diagnostic']=$failure; }
    finally {
        if (!$finishRequested&&file_exists($secretDir.'/ready.json')) {
            // JS receives abort, closes its own normal contexts, then writes closed.json.
            audit_write_json($exchange.'/status.json',['run_id'=>$run,'revision'=>$revision,'status'=>'abort','diagnostic'=>$failure]);
            $wait=microtime(true)+15;
            do { $closed=audit_read_control_json($exchange.'/closed.json'); if (($closed['run_id'] ?? '')===$run&&($closed['contexts_closed'] ?? null)===true) break; usleep(50000); } while (microtime(true)<$wait);
        }
        $stopped=true;
        if ($chatAvailable) try { $evidence['chat_stop']=m7b_chat_control('stop'); } catch (Throwable $error) { $stopped=false; $cleanupFailure ??=m7b_diag($error); }
        if ($appAvailable) try { audit_app_stop(); $evidence['app_stop']=audit_read_control_json(audit_run_dir().'/app-status.json'); } catch (Throwable $error) { $stopped=false; $cleanupFailure ??=m7b_diag($error); }
        if ($stopped) try {
            if ($pdo instanceof PDO) {
                if ($sequenceCaptured) m7b_guest_discover($pdo,$config,$sequenceBefore,$owners,$ownedKeys,$secrets);
                if ($users) { m7b_kefu_sessions($app,$prefix,$users['kefu']['service_id'],$initial,$ownedKeys,$sessions,$secrets,$evidence); m7b_discover_chat($pdo,$users,array_values($messages),$owners); }
                if ($old) try { m7b_old_rows($pdo,$old,$owners,$sequenceBefore); }
                    catch (Throwable $error) { $failure ??=m7b_diag($error); $evidence['before_cleanup_preservation_diagnostic']=m7b_diag($error); }
                foreach ($owners['sql_cache'] as $key=>$owner) {
                    $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute([$key]); $actual=$stmt->fetch();
                    if ($actual===false) continue;
                    foreach ($owner as $field=>$value) audit_expect(array_key_exists($field,$actual)&&m7b_value($actual[$field],$value),
                        'new source-default SQL cache row still equals its exact registered fields');
                    $stmt=$pdo->prepare('DELETE FROM eb_cache WHERE `key`=? AND result=? AND expire_time=? AND add_time=?'); $stmt->execute(array_values($owner));
                    audit_expect($stmt->rowCount()===1,'one newly owned SQL default cache row is removed precisely');
                }
                $removed=m7b_cleanup($pdo,$owners,$ownedKeys,$sequenceBefore);
            } else $removed=0;
            $evidence['owned_keys_registered_count']=count(array_unique($ownedKeys)); $evidence['owned_keys_removed_count']=$removed;
        } catch (Throwable $error) { $cleanupFailure ??=m7b_diag($error); $evidence['cleanup_diagnostic']=m7b_diag($error); }
        else $evidence['cleanup_blocked_until_own_services_stopped']=true;
        foreach (['ready.json','browser-login.json'] as $name) if (is_file($secretDir.'/'.$name)) {
            if (!unlink($secretDir.'/'.$name)) $cleanupFailure ??=['class'=>'RuntimeException','file'=>basename(__FILE__),'line'=>__LINE__];
        }
    }
    if (is_array($initial)) try {
        $after=m7b_snapshot(); $evidence['after']=m7b_public_snapshot($after); $counterChanges=[];
        foreach ($counterTables as $table) if ($owners[substr($table,3)]) $counterChanges[]=$table;
        $forward=$sequenceBefore!==null&&count($owners['guest'])>0;
        m7b_same($initial,$after,$forward ? ['eb_cache'] : [],[],$counterChanges);
        if ($old) m7b_old_rows(audit_db(),$old,['user'=>[],'store_service'=>[],'store_service_log'=>[],'store_service_record'=>[],'sql_cache'=>[]],$sequenceBefore);
        audit_guard_assert();
        $evidence['preservation']=['tables'=>count($initial['names']),'original_rows_and_schema_checksums_preserved'=>true,
            'old_cache_values_and_absolute_expiry_unchanged'=>true,'counter_changes_allowed_only'=>$counterChanges,
            'preexisting_sequence_forward_exception'=>$forward,'snapshot_restore_used'=>false,'sequence_rollback_used'=>false];
        audit_expect(m7b_runtime_logs($secrets),'normal app/chat logs must omit own credentials and message bodies');
        $evidence['known_fixture_credentials_omitted_from_normal_logs']=true;
        $evidence['guest_raw_capability_read_by_php']=false;
    } catch (Throwable $error) { $failure ??=m7b_diag($error); $evidence['preservation_diagnostic']=m7b_diag($error); }
    $evidence['owned_row_ids']=array_map(static fn(array $rows): array=>array_keys($rows),array_intersect_key($owners,array_flip(['user','store_service','store_service_log','store_service_record','guest','sql_cache'])));
    $evidence['fixtures_created']=array_sum(array_map('count',array_intersect_key($owners,array_flip(['user','store_service','store_service_log','store_service_record','sql_cache']))));
    $evidence['fixtures_cleaned']=$cleanupFailure===null&&$evidence['fixtures_created']>0;
    $evidence['pending_other_acceptance']='uni-app runtime and Task8/9; stopped safety matrix NOT RUN';
    $bytes=json_encode($evidence,JSON_THROW_ON_ERROR); foreach ($secrets as $value) audit_expect($value===''||!str_contains($bytes,$value),'public browser evidence contains no owned private input');
    audit_write_json(audit_run_dir().'/evidence/ms1-chat-browser-after.json',$evidence);
    $passed=$finishRequested&&$failure===null&&$cleanupFailure===null;
    audit_write_json($exchange.'/status.json',['run_id'=>$run,'revision'=>$revision ?: $lastAck,'status'=>'finish','result'=>$passed ? 'PASS' : 'FAIL',
        'diagnostic'=>$failure ?? $cleanupFailure,'ordinary_cleanup_complete'=>$cleanupFailure===null]);
    return ['status'=>$passed ? 'PASS' : 'FAIL','actual'=>$passed ? 'real ordinary desktop/mobile UI kefu login and opaque guest chat, source-owned records and exact cleanup'
        : (($failure ?? $cleanupFailure)['class'] ?? 'RuntimeException').'@'.(($failure ?? $cleanupFailure)['file'] ?? basename(__FILE__)).':'.(($failure ?? $cleanupFailure)['line'] ?? 0),
        'evidence_ref'=>'evidence/ms1-chat-browser-after.json','notes'=>'ordinary T7B01 only; old Task7 results and all stopped validation remain unchanged'];
}

audit_case('T7B01','ordinary_desktop_mobile_browser_chat','BROWSER',static fn(): array=>m7b_serve());
foreach ([
    ['A07','merchant_on_platform_configuration'],['A07','merchant_on_database_backup'],['A07','merchant_on_file_edit'],['A07','merchant_on_user_profile'],['A07','merchant_on_crud_generation'],
    ['M07','merchant_on_adminapi'],['M07','merchant_on_outapi'],['M07','merchant_on_kefuapi'],['M07','merchant_on_api'],
    ['M07','admin_on_merchantapi'],['M07','out_on_merchantapi'],['M07','kefu_on_merchantapi'],['M07','api_on_merchantapi'],
    ['K01','merchant_on_kefu_http'],['K01','merchant_on_kefu_ws_authentication'],['K01','merchant_on_kefu_room_subscription'],
    ['T7B01','invalid_credentials'],['T7B01','old_expired_consumed_credentials'],['T7B01','fault_injection'],['T7B01','concurrency'],
    ['T7B01','fault_disconnect'],['T7B01','recovery'],['ISO','ddl_denial_probe']
] as [$case,$subcase]) audit_case($case,$subcase,'BROWSER',static fn(): array=>['status'=>'NOT RUN','actual'=>'user-stopped validation not invoked','notes'=>'retained acceptance gap']);
audit_finish();
