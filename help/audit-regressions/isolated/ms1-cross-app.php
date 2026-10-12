<?php
declare(strict_types=1);
/* Task7 ordinary verification: only current valid owned clients. */
require __DIR__ . '/lib/harness.php';
if ((audit_manifest()['guard_mode'] ?? '') !== 'read-only') {
    fwrite(STDERR, "BLOCKED ordinary Task7 requires read-only isolation checks\n"); exit(2);
}
audit_guard_assert();
putenv('AUDIT_MILESTONE=MS1');
require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
function m7h_diag(Throwable $error): array
{
    $caller=$error->getTrace()[0] ?? [];
    return ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'caller_file'=>basename($caller['file'] ?? ''),'caller_line'=>$caller['line'] ?? null];
}

function m7h_scan(string $pattern): array
{
    $cursor=null; $keys=[]; $redis=audit_redis();
    do { $keys=array_merge($keys,$redis->scan($cursor,$pattern,200) ?: []); } while ($cursor !== 0);
    $keys=array_values(array_unique($keys)); sort($keys,SORT_STRING); return $keys;
}

function m7h_deadlines(array $keys): array
{
    if (!$keys) return [];
    $values=audit_redis()->eval("local t=redis.call('TIME'); local n=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local r={}; for i,k in ipairs(KEYS) do local p=redis.call('PTTL',k); r[i]=p>=0 and n+p or p end; return r",$keys,count($keys));
    audit_expect(is_array($values) && count($values)===count($keys),'every native cache deadline is available');
    $result=[]; foreach ($keys as $i=>$key) { audit_expect(is_int($values[$i]),'cache deadline is integral'); $result[$key]=$values[$i]; }
    return $result;
}

function m7h_snapshot(): array
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
    $redis=audit_redis(); $keys=m7h_scan('*'); $deadlines=m7h_deadlines($keys);
    foreach ($keys as $key) {
        if ($redis->type($key)===Redis::REDIS_SET) { $members=$redis->sMembers($key); sort($members,SORT_STRING); $value="set\0".serialize($members); }
        else { $value=(string)$redis->dump($key); }
        $snapshot['redis'][$key]=['sha256'=>hash('sha256',$value),'deadline'=>$deadlines[$key]];
    }
    return $snapshot;
}

function m7h_public_snapshot(array $snapshot): array
{
    $redis=[]; foreach ($snapshot['redis'] as $key=>$value) $redis[hash('sha256',$key)]=$value;
    ksort($redis); $snapshot['redis']=$redis; return $snapshot;
}

function m7h_same(array $before,array $after,array $changedTables=[],array $changedKeys=[],array $counterTables=[]): void
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

function m7h_app(array $config,string $prefix): \think\App
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

function m7h_warm_language(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): void
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
    $before=m7h_snapshot();
    foreach ($cleanupKeys as $key) audit_expect(!isset($before['redis'][$key]),'exact language preparation key was already present');
    // Register before the real normal producer, for precise partial-run cleanup.
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,$cleanupKeys)));
    $start=time(); getLang('操作成功'); $end=time(); $after=m7h_snapshot(); $redis=audit_redis();
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
    m7h_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['language_preparation']=['source'=>'real getLang and installed LangCode/LangType/LangCountry services',
        'logical_keys'=>array_keys($values),'added_key_hashes'=>array_map(static fn(string $key): string=>hash('sha256',$key),$expectedNew),
        'exact_values_and_source_expiry_verified'=>true,'unrelated_state_preserved'=>true];
}

function m7h_value(mixed $got,mixed $wanted): bool
{ return is_int($wanted) ? ((is_int($got)||is_string($got)) && (string)$got===(string)$wanted) : $got===$wanted; }
function m7h_row_hashes(PDO $pdo,string $table,string $pk,array $omit=[]): array
{
    $rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY `'.$pk.'`')->fetchAll(); $result=[];
    foreach ($rows as $row) if (!in_array((string)$row[$pk],array_map('strval',$omit),true)) $result[(string)$row[$pk]]=hash('sha256',serialize($row));
    return $result;
}
function m7h_old_rows(PDO $pdo,array $old,array $owners,?array $sequenceBefore): void
{
    foreach (['user'=>'uid','store_service'=>'id','store_service_log'=>'id','store_service_record'=>'id'] as $table=>$pk)
        audit_expect(m7h_row_hashes($pdo,'eb_'.$table,$pk,array_keys($owners[$table]))===$old[$table],'every original chat fixture table row remains unchanged');
    $cache=m7h_row_hashes($pdo,'eb_cache','key',['kefu_tourist_uid_seq']);
    audit_expect($cache===$old['cache_without_sequence'],'every unrelated original SQL cache row remains unchanged');
    $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kefu_tourist_uid_seq']); $row=$stmt->fetch();
    if ($sequenceBefore!==null) {
        audit_expect(is_array($row),'existing visitor ledger must remain present');
        foreach ($sequenceBefore as $field=>$value) if ($field!=='result') audit_expect($row[$field]===$value,'existing permanent visitor sequence metadata remains unchanged');
        audit_expect(preg_match('/^(0|[1-9][0-9]*)$/D',(string)$row['result'])===1 && (int)$row['result']>=(int)$sequenceBefore['result'],'existing visitor sequence only moves forward');
    }
}
function m7h_preflight(PDO $pdo,array &$evidence): void
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
function m7h_register_keys(array $keys,array &$ownedKeys): void
{
    $redis=audit_redis(); foreach ($keys as $key) if (!in_array($key,$ownedKeys,true)) {
        audit_expect($redis->exists($key)===0,'exact newly owned native key starts absent'); $ownedKeys[]=$key;
    }
}
function m7h_system_warm(\think\App $app,string $prefix,array &$ownedKeys,array &$evidence): array
{
    $names=['site_name','site_url','tourist_avatar']; $before=m7h_snapshot(); $values=[];
    $keys=array_map(static fn(string $n): string=>$prefix.'system_config_'.$n,$names); $tag=$prefix.'CRMEB:'.md5('crmeb');
    m7h_register_keys(array_merge($keys,array_map(static fn(string $k): string=>$k.'_lock',$keys)),$ownedKeys);
    foreach ($names as $name) {
        $want=$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($name);
        $got=\crmeb\services\SystemConfigService::get($name); audit_expect($got===$want,'normal system cache matches its installed actual DB producer'); $values[$name]=$want;
    }
    $after=m7h_snapshot(); $new=array_values(array_diff(array_keys($after['redis']),array_keys($before['redis']))); sort($new); $wantNew=$keys; sort($wantNew);
    audit_expect($new===$wantNew,'system warmup adds exactly three known physical keys');
    foreach ($names as $name) {
        $key=$prefix.'system_config_'.$name; $want=is_numeric($values[$name]) ? (string)$values[$name] : serialize($values[$name]);
        audit_expect(audit_redis()->type($key)===Redis::REDIS_STRING && audit_redis()->get($key)===$want && $after['redis'][$key]['deadline']===-1,'system config cache exact value and persistent expiry match producer');
    }
    $members=audit_redis()->sMembers($tag); sort($members); $expected=array_merge($keys,array_map(static fn(string $n): string=>$prefix.$n,
        ['range_name','sys_lang_source_map','type_id_zh-CN','lang_type_data','lang_zh_CN'])); sort($expected);
    audit_expect($members===$expected,'normal shared cache tag contains precisely warm language and system keys');
    m7h_same($before,$after,[],array_merge($keys,[$tag]));
    $evidence['system_config_preparation']=['logical_keys'=>array_map(static fn(string $n): string=>'system_config_'.$n,$names),
        'source'=>'real SystemConfigService::get and installed SystemConfigServices::getConfigValue','values_and_source_expiry_verified'=>true];
    return $values;
}
function m7h_http(string $path,?string $token,array &$evidence): array
{
    $headers=['cb-lang'=>'zh-CN']; if ($token!==null) $headers['Authori-zation']='Bearer '.$token;
    $response=audit_http('GET','http://audit-app:8000'.$path,$headers);
    $evidence['requests'][]=['method'=>'GET','path'=>$path,'http'=>$response['http'],'business_status'=>$response['json']['status'] ?? null];
    audit_expect($response['http']===200 && is_array($response['json']) && ($response['json']['status'] ?? null)===200 && is_array($response['json']['data'] ?? null),'normal current kefu/API-header HTTP returns real success');
    $keys=array_keys($response['json']); sort($keys); audit_expect($keys===['data','msg','status'] && is_string($response['json']['msg']),'actual normal HTTP envelope differs');
    return $response['json']['data'];
}
function m7h_seed(PDO $pdo,string $slug,array &$owners,array &$secrets): array
{
    $users=[];
    foreach (['kefu','customer'] as $kind) {
        $account='m7_'.substr(hash('sha256',$slug.'|'.$kind),0,24); $name='M7 '.ucfirst($kind); $avatar='https://example.invalid/m7/'.$kind.'.png';
        $password='M7-'.bin2hex(random_bytes(24)).'-x'; $digest=md5($password); $now=time();
        $secrets=array_merge($secrets,[$account,$name,$avatar,$password,$digest]);
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_user WHERE account=?'); $stmt->execute([$account]); audit_expect((int)$stmt->fetchColumn()===0,'new synthetic customer account is absent');
        $stmt=$pdo->prepare('INSERT INTO eb_user(account,pwd,nickname,avatar,add_time,last_time,status,is_del,user_type) VALUES(?,?,?,?,?,?,1,0,?)');
        $stmt->execute([$account,$digest,$name,$avatar,$now,$now,'h5']); $uid=(int)$pdo->lastInsertId();
        audit_expect($uid>0,'owned User has its actual allocated UID'); $owners['user'][$uid]=['uid'=>$uid,'account'=>$account,'nickname'=>$name,'avatar'=>$avatar,'pwd'=>$digest];
        // Fresh User UID can still equal an orphan reference in old signed chat tables.
        foreach (['eb_store_service'=>'uid=?','eb_store_service_record'=>'user_id=? OR to_uid=?','eb_store_service_log'=>'uid=? OR to_uid=?','eb_wechat_user'=>'uid=?'] as $table=>$where) {
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE '.$where); $stmt->execute(substr_count($where,'?')===1 ? [$uid] : [$uid,$uid]);
            audit_expect((int)$stmt->fetchColumn()===0,'fresh owned UID has no pre-existing chat or WeChat references');
        }
        $users[$kind]=['uid'=>$uid,'account'=>$account,'name'=>$name,'avatar'=>$avatar,'password'=>$password,'digest'=>$digest];
    }
    $account='m7s_'.substr(hash('sha256',$slug),0,24); $digest=password_hash($users['kefu']['password'],PASSWORD_BCRYPT);
    audit_expect(is_string($digest),'owned service has real password digest'); $secrets=array_merge($secrets,[$account,$digest]);
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_store_service WHERE account=?'); $stmt->execute([$account]); audit_expect((int)$stmt->fetchColumn()===0,'new synthetic service account is absent');
    $now=time(); $stmt=$pdo->prepare('INSERT INTO eb_store_service(mer_id,uid,online,account,password,avatar,nickname,phone,add_time,status,notify,customer,uniqid) VALUES(0,?,0,?,?,?,?,?,?,1,0,0,?)');
    $stmt->execute([$users['kefu']['uid'],$account,$digest,$users['kefu']['avatar'],$users['kefu']['name'],'',$now,'']);
    $id=(int)$pdo->lastInsertId(); audit_expect($id>0,'owned service has actual allocated ID');
    $owners['store_service'][$id]=['id'=>$id,'uid'=>$users['kefu']['uid'],'account'=>$account,'password'=>$digest]; $users['kefu']['service_id']=$id;
    return $users;
}
function m7h_issue_legacy(\think\App $app,string $prefix,string $type,int $id,array &$ownedKeys,array &$secrets,array &$evidence): string
{
    $before=m7h_snapshot(); $tag=$prefix.'CRMEB:'.md5($type); $issuer=$app->make(\crmeb\utils\JwtAuth::class);
    // Pure production getToken permits registering the exact key before production createToken IO.
    $candidate=$issuer->getToken($id,$type); $planned=$prefix.md5($candidate['token']);
    m7h_register_keys([$tag,$planned],$ownedKeys); $secrets[]=$candidate['token'];
    // Actual lower-level issuer and current real whitelist; no BaseServices notification events.
    $start=time(); $issued=$issuer->createToken($id,$type,$candidate['params']); $end=time(); $token=$issued['token'] ?? null; $claims=$issued['params'] ?? null;
    audit_expect($token===$candidate['token'],'normal producer uses its exact pre-registered current claim set');
    audit_expect(is_string($token)&&is_array($claims)&&($claims['jti'] ?? null)===['id'=>$id,'type'=>$type],'real current legacy issuer binds the exact owned row and application type');
    $secrets[]=$token; $key=$prefix.md5($token); audit_expect(!isset($before['redis'][$key]),'issued exact legacy key starts absent'); $ownedKeys[]=$key;
    $ttl=$claims['exp']-$claims['iat']+60; $wanted=['uid'=>$id,'type'=>$type,'token'=>$token,'exp'=>$ttl];
    audit_expect(\crmeb\services\SessionWhitelist::read($token)===$wanted && audit_redis()->get($key)===serialize($wanted),'real whitelist fully equals issued production current binding');
    $after=m7h_snapshot(); audit_expect($after['redis'][$key]['deadline']>=($start+$ttl)*1000 && $after['redis'][$key]['deadline']<=($end+$ttl)*1000+999,'real legacy TTL is bounded by actual producer times');
    $members=audit_redis()->sMembers($tag); audit_expect(count($members)===1&&$members[0]===$key&&$after['redis'][$tag]['deadline']===-1,'real legacy issuer has exactly its owned type tag');
    m7h_same($before,$after,[],[$key,$tag]);
    $evidence['legacy_issuance'][]=['type'=>$type,'subject_id'=>$id,'producer'=>'JwtAuth::createToken','exact_whitelist_and_source_expiry_verified'=>true]; return $token;
}
function m7h_chat_control(string $action,array $options=[]): array
{
    $revision=bin2hex(random_bytes(8)); audit_write_json(audit_run_dir().'/ms1-chat-control.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,'action'=>$action,'options'=>$options]);
    $deadline=microtime(true)+15;
    do {
        $status=audit_read_control_json(audit_run_dir().'/ms1-chat-status.json');
        if (($status['run_id'] ?? '')===getenv('AUDIT_RUN_ID') && ($status['revision'] ?? '')===$revision) {
            audit_expect(($status['status'] ?? '')===$action,'normal registered chat lifecycle failed'); return $status;
        }
        usleep(10000);
    } while (microtime(true)<$deadline);
    throw new RuntimeException('normal registered chat lifecycle ACK unavailable');
}
function m7h_write_all($stream,string $bytes): void
{
    $at=0; while ($at<strlen($bytes)) { $n=fwrite($stream,substr($bytes,$at)); audit_expect(is_int($n)&&$n>0,'normal socket write incomplete'); $at+=$n; }
}
function m7h_read_exact($stream,int $count): string
{
    $bytes=''; while (strlen($bytes)<$count) { $chunk=fread($stream,$count-strlen($bytes)); $meta=stream_get_meta_data($stream); audit_expect(is_string($chunk)&&$chunk!==''&&!($meta['timed_out'] ?? false),'normal websocket frame unavailable'); $bytes.=$chunk; }
    return $bytes;
}
function m7h_frame($stream,int $opcode,string $payload): void
{
    audit_expect(strlen($payload)<=65535,'normal websocket message is bounded'); $length=strlen($payload); $mask=random_bytes(4); $masked='';
    for ($i=0;$i<$length;$i++) $masked.=$payload[$i]^$mask[$i%4];
    $head=chr(0x80|$opcode).($length<126 ? chr(0x80|$length) : chr(0x80|126).pack('n',$length)); m7h_write_all($stream,$head.$mask.$masked);
}
function m7h_connect(string $label,array &$sockets,array &$evidence)
{
    $manifest=audit_manifest(); $service=$manifest['services']['audit-chat'] ?? [];
    audit_expect(filter_var($service['address'] ?? '',FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false && gethostbyname('audit-chat')===$service['address'],'ordinary WS destination is registered dedicated chat');
    $stream=stream_socket_client('tcp://audit-chat:40002',$code,$message,3); audit_expect(is_resource($stream),'real normal websocket connection unavailable');
    $sockets[$label]=$stream; stream_set_timeout($stream,8); $key=base64_encode(random_bytes(16));
    m7h_write_all($stream,"GET / HTTP/1.1\r\nHost: audit-chat:40002\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: ".$key."\r\n\r\n");
    $headers=''; while (!str_ends_with($headers,"\r\n\r\n")) { audit_expect(strlen($headers)<8192,'normal handshake is bounded'); $headers.=m7h_read_exact($stream,1); }
    audit_expect(preg_match('~^HTTP/1\.[01] 101\b~',$headers)===1,'actual production websocket upgrades normally');
    audit_expect(preg_match('/^Sec-WebSocket-Accept:\s*([^\r\n]+)\r$/mi',$headers,$m)===1 && trim($m[1])===base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true)),'normal websocket handshake has real accept binding');
    $evidence['ws_connections'][]=['label'=>$label,'port'=>40002,'upgrade'=>101]; return $stream;
}
function m7h_send($stream,array $body,string $label,array &$evidence): void
{
    m7h_frame($stream,1,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $evidence['ws_sent'][]=['label'=>$label,'type'=>$body['type'],'source'=>'normal valid current client'];
}
function m7h_receive($stream): array
{
    do {
        $head=m7h_read_exact($stream,2); $b=ord($head[0]); $mask=ord($head[1]); $opcode=$b&15;
        audit_expect(($b&0x80)!==0&&($b&0x70)===0&&($mask&0x80)===0,'ordinary server frame is unfragmented and unmasked');
        $len=$mask&127; audit_expect($len!==127,'normal server frame uses its bounded length format'); if ($len===126) $len=unpack('n',m7h_read_exact($stream,2))[1];
        audit_expect($len<=65535,'ordinary server frame length is bounded'); $payload=$len ? m7h_read_exact($stream,$len) : '';
        if ($opcode===9) { m7h_frame($stream,10,$payload); continue; } if ($opcode===10) continue;
        audit_expect($opcode===1,'normal server returns text messages'); $data=json_decode($payload,true,32,JSON_THROW_ON_ERROR);
        audit_expect(is_array($data)&&is_string($data['type'] ?? null)&&!isset($data['close']),'normal current websocket message has real success transport'); return $data;
    } while (true);
}
function m7h_wait($stream,string $type): array
{
    for ($i=0;$i<30;$i++) { $message=m7h_receive($stream); if ($message['type']===$type) return $message; audit_expect(in_array($message['type'],['user_online','online','mssage_num','ping'],true),'unexpected message in normal current flow'); }
    throw new RuntimeException('normal expected websocket response unavailable');
}
function m7h_login($stream,string $label,string $kind,int $uid,string $credential,array &$evidence,?int $expires=null): void
{
    $type=['kefu'=>'kefu_login','api'=>'login','guest'=>'guest_login'][$kind];
    m7h_send($stream,['type'=>$type,'data'=>$credential,'form_type'=>3],$label,$evidence); $reply=m7h_wait($stream,'success');
    $data=$reply['data'] ?? null; $expected=['kind'=>$kind,'uid'=>$uid]; if ($kind==='guest') { audit_expect(is_int($expires),'normal guest expects its server expiry'); $expected['expires_at']=$expires; }
    audit_expect(is_array($data) && $data===$expected,'current websocket login ACK has exact server identity metadata');
    $evidence['ws_identity_acks'][]=['label'=>$label,'kind'=>$kind,'uid'=>$uid];
}
function m7h_room($stream,string $label,int $target,array &$evidence): void
{
    m7h_send($stream,['type'=>'to_chat','data'=>['id'=>$target]],$label,$evidence); $reply=m7h_wait($stream,'mssage_num');
    audit_expect(is_array($reply['data'] ?? null)&&($reply['data']['uid'] ?? null)===$target&&($reply['data']['num'] ?? null)===0,'normal confirmed room or zero room has its real ACK');
    $evidence['ws_rooms'][]=['label'=>$label,'target'=>$target,'normal_room_ack_verified'=>true];
}
function m7h_close(string $label,array &$sockets,array &$evidence): void
{
    if (is_resource($sockets[$label] ?? null)) { m7h_frame($sockets[$label],8,pack('n',1000)); fclose($sockets[$label]); }
    unset($sockets[$label]); $evidence['normal_closes'][]=$label;
}
function m7h_guest_key(string $token,array $config): string
{
    audit_expect(preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)===1,'normal guest credential is canonical opaque32-byte base64url');
    $raw=base64_decode(strtr($token,'-_','+/').'=',true);
    audit_expect(is_string($raw)&&strlen($raw)===32&&rtrim(strtr(base64_encode($raw),'+/','-_'),'=')===$token,'normal guest credential canonical roundtrip matches');
    return 'chat:guest:v1:'.$config['auth']['session_epoch'].':'.hash('sha256',$raw);
}
function m7h_guest(array $data,array $config,array $users,array &$owners,array &$ownedKeys,array &$secrets,array &$evidence,int $start,int $end): array
{
    $required=['nickname','avatar','real_name','uid','tourist_uid','tourist_avatar','is_tourist','guest_token','guest_expires_at'];
    $keys=array_keys($data); sort($keys); sort($required); audit_expect($keys===$required,'anonymous ordinary Common response has exact retained and new guest fields');
    audit_expect(m7h_value($data['uid'],$users['kefu']['uid'])&&is_int($data['tourist_uid'])&&$data['tourist_uid']<0
        &&$data['tourist_uid']>=-2147483647&&$data['is_tourist']===true&&is_string($data['guest_token'])&&is_int($data['guest_expires_at'])
        &&$data['guest_expires_at']>=$start+3600&&$data['guest_expires_at']<=$end+3600&&is_string($data['tourist_avatar']),'actual anonymous guest identity is server-issued negative with one-hour expiry');
    $uid=$data['tourist_uid']; $token=$data['guest_token']; $secrets[]=$token; $key=m7h_guest_key($token,$config); $digest=substr($key,strrpos($key,':')+1);
    $index='chat:guest:v1:'.$config['auth']['session_epoch'].':uid:'.$uid;
    audit_expect(!in_array($uid,array_keys($owners['guest']),true),'each normal anonymous issue has a new real negative UID');
    $owners['guest'][$uid]=['uid'=>$uid,'avatar'=>$data['tourist_avatar'],'expires_at'=>$data['guest_expires_at'],'record_key'=>$key,'index_key'=>$index,'digest'=>$digest];
    $ownedKeys=array_values(array_unique(array_merge($ownedKeys,[$key,$index]))); $secrets[]=$digest;
    $record=audit_redis()->get($key); audit_expect(is_string($record),'actual opaque guest record exists in native Redis'); $row=json_decode($record,true,16,JSON_THROW_ON_ERROR);
    $wanted=['token_digest'=>$digest,'uid'=>$uid,'avatar'=>$data['tourist_avatar'],'purpose'=>'tianli:kefu-chat:guest','expires_at'=>$data['guest_expires_at']];
    ksort($row); ksort($wanted); audit_expect($row===$wanted&&audit_redis()->get($index)===$digest,'actual guest native record and exact UID index match their complete producer binding');
    foreach ([$key,$index] as $physical) { $deadline=audit_redis()->rawCommand('PEXPIRETIME',$physical); audit_expect(is_int($deadline) && $deadline===$data['guest_expires_at']*1000,'native guest capability and UID index share exact stored absolute expiry'); }
    $stmt=audit_db()->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kefu_tourist_uid_seq']); $sequence=$stmt->fetch();
    audit_expect(is_array($sequence)&&preg_match('/^[1-9][0-9]*$/D',(string)$sequence['result'])===1&&(int)$sequence['result']===-$uid&&(int)$sequence['expire_time']===0,'actual permanent guest counter equals real issued UID high-water');
    $evidence['guest_issuance'][]=['uid'=>$uid,'expires_at'=>$data['guest_expires_at'],'producer'=>'normal Common.getServiceUser/GuestChatSession::issue',
        'physical_key_hashes'=>[hash('sha256',$key),hash('sha256',$index)],'full_native_binding_and_absolute_expiry_verified'=>true,'counter_value'=>(int)$sequence['result']];
    return ['uid'=>$uid,'token'=>$token,'expires_at'=>$data['guest_expires_at'],'avatar'=>$data['tourist_avatar']];
}
function m7h_guest_discover(PDO $pdo,array $config,?array $sequenceBefore,array &$owners,array &$ownedKeys,array &$secrets): void
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
function m7h_discover_chat(PDO $pdo,array $users,array $messages,array &$owners): void
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
function m7h_text($sender,$recipient,string $senderLabel,string $recipientLabel,int $senderUid,int $recipientUid,int $classification,
    string $message,PDO $pdo,array $users,array $messages,array &$owners,array &$evidence): void
{
    // Both actual peers selected one another before each send; only ordinary type1 text.
    m7h_send($sender,['type'=>'chat','data'=>['to_uid'=>$recipientUid,'type'=>1,'msn'=>$message],'form_type'=>3],$senderLabel,$evidence);
    $echo=m7h_wait($sender,'chat'); $reply=m7h_wait($recipient,'reply');
    foreach ([$echo,$reply] as $frame) {
        $data=$frame['data'] ?? null; audit_expect(is_array($data)&&m7h_value($data['uid'] ?? null,$senderUid)&&m7h_value($data['to_uid'] ?? null,$recipientUid)
            &&m7h_value($data['msn_type'] ?? null,1)&&($data['msn'] ?? null)===$message&&m7h_value($data['is_tourist'] ?? null,$classification)
            &&m7h_value($data['type'] ?? null,1)&&isset($data['id']),'normal echo and peer reply carry server identity and classification');
    }
    audit_expect(m7h_value($echo['data']['id'],$reply['data']['id']),'normal actual peer reply shares the real persisted log ID');
    m7h_discover_chat($pdo,$users,$messages,$owners); $id=(int)$echo['data']['id'];
    audit_expect(isset($owners['store_service_log'][$id]),'normal message has one of its exact newly owned stored log IDs');
    $stmt=$pdo->prepare('SELECT * FROM eb_store_service_log WHERE id=? AND uid=? AND to_uid=? AND msn=? AND msn_type=1 AND is_tourist=?');
    $stmt->execute([$id,$senderUid,$recipientUid,$message,$classification]); $log=$stmt->fetch();
    audit_expect(is_array($log)&&(int)$log['type']===1,'normal currently open peer-room message is persisted read with exact current identities');
    $stmt=$pdo->prepare('SELECT * FROM eb_store_service_record WHERE user_id=? AND to_uid=? AND is_tourist=?'); $stmt->execute([$recipientUid,$senderUid,$classification]); $rows=$stmt->fetchAll();
    audit_expect(count($rows)===1&&$rows[0]['message']===$message&&(int)$rows[0]['message_type']===1&&(int)$rows[0]['type']===3&&(int)$rows[0]['mssage_num']===0,'normal message saves its real server-classified reverse conversation record');
    $evidence['normal_texts'][]=['log_id'=>$id,'sender_uid'=>$senderUid,'recipient_uid'=>$recipientUid,'is_tourist'=>$classification,
        'message_sha256'=>hash('sha256',$message),'real_echo_reply_and_stored_record_verified'=>true];
}
function m7h_heartbeat(array $sockets,array &$evidence): void
{
    foreach ($sockets as $label=>$stream) if (is_resource($stream)) {
        m7h_send($stream,['type'=>'ping','data'=>[]],$label,$evidence); $reply=m7h_wait($stream,'ping');
        audit_expect(is_array($reply['data'] ?? null)&&array_keys($reply['data'])===['now','datetime']&&is_int($reply['data']['now'])&&$reply['data']['now']>0
            &&is_string($reply['data']['datetime'])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$reply['data']['datetime'])===1,'normal connected peers retain actual now/datetime production heartbeat');
    }
}
function m7h_channel(string $prefix,PDO $pdo,int $kefuUid,int $customerUid,$recipient,array &$evidence): void
{
    $stmt=$pdo->prepare('SELECT * FROM eb_store_service_record WHERE user_id=? AND to_uid=? AND is_tourist=0'); $stmt->execute([$kefuUid,$customerUid]); $rows=$stmt->fetchAll();
    audit_expect(count($rows)===1&&(int)$rows[0]['id']>0&&(int)$rows[0]['mssage_num']===0,'Channel event uses the actual owned staff/customer record from normal delivered texts'); $record=$rows[0];
    $marker=bin2hex(random_bytes(16)); $process=proc_open(['php',__DIR__.'/lib/ms1-chat-entry.php','__publish',$prefix],
        [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,audit_repo_dir()); audit_expect(is_resource($process),'real normal one-shot Channel producer unavailable');
    m7h_write_all($pipes[0],json_encode(['destination_uid'=>$kefuUid,'customer_uid'=>$customerUid,'record'=>$record,'marker'=>$marker],JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1],8193); $err=stream_get_contents($pipes[2],8193); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    audit_expect($exit===0&&is_string($out)&&strlen($out)<8193&&$err==='','real production Channel publisher completes normally');
    $frame=m7h_wait($recipient,'mssage_num'); $expected=['uid'=>$customerUid,'num'=>0,'recored'=>$record,'marker'=>$marker];
    audit_expect(($frame['data'] ?? null)===$expected,'real permitted Channel40003 mssage_num reaches its actual current authenticated owned staff recipient');
    $evidence['channel_delivery']=['native_exit_code'=>$exit,'recipient_uid'=>$kefuUid,'customer_uid'=>$customerUid,'conversation_id'=>(int)$record['id'],
        'event_type'=>'mssage_num','marker_sha256'=>hash('sha256',$marker),'publisher'=>'real ChannelService::instance()->setTrigger(crmeb_chat)->send',
        'actual_ws_delivery_and_real_record_verified'=>true,'external_notification_requests'=>0,'counts_as_room_ack'=>false];
}
function m7h_runtime_logs(array $secrets): bool
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
function m7h_cleanup(PDO $pdo,array $owners,array $ownedKeys,?array $sequenceBefore): int
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
function m7h_sources(array &$evidence): void
{
    $files=[
        'crmeb/crmeb/services/workerman/chat/GuestChatSession.php','crmeb/crmeb/services/workerman/chat/ChatIdentity.php',
        'crmeb/crmeb/services/workerman/chat/ChatService.php','crmeb/crmeb/services/workerman/chat/ChatHandle.php',
        'crmeb/crmeb/services/auth/AuthTokenV2.php','crmeb/app/services/kefu/LoginServices.php','crmeb/app/services/user/UserAuthServices.php',
        'crmeb/app/kefuapi/controller/Common.php','crmeb/app/kefuapi/route/route.php','crmeb/app/kefuapi/KefuApiExceptionHandle.php',
        'crmeb/app/listener/http/HttpEndListener.php','crmeb/app/services/kefu/KefuServices.php',
        'crmeb/app/services/kefu/service/StoreServiceRecordServices.php','crmeb/app/services/system/SystemClearServices.php',
        'template/admin/src/api/kefu.js','template/admin/src/libs/request.js','template/admin/src/libs/socket.js',
        'template/admin/src/pages/kefu/appChat/index.vue','template/admin/src/pages/kefu/appChat/mobile/index.vue',
        'template/admin/src/pages/kefu/mobile/chat_list.vue','template/admin/src/pages/kefu/mobile/index.vue','template/admin/src/pages/kefu/pc/index.vue',
        'template/uni-app/libs/new_chat.js','template/uni-app/pages/extension/customer_list/chat.vue',
        'help/audit-regressions/isolated/lib/ms1-chat-entry.php','help/audit-regressions/isolated/ms1-cross-app.php',
        'help/audit-regressions/isolated/compose.yml','help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/lib/guard.php',
        'crmeb/crmeb/compat/workerman/Events/Select.php','docs/multi-merchant/03-test-environment.md',
        'docs/multi-merchant/06-plan-ms1.md','help/audit-regressions/isolated/README.md'];
    $actual=[]; foreach ($files as $file) {
        $path=audit_repo_dir().'/'.$file;
        if (!is_file($path)) { $evidence['source_availability']=['missing_path'=>$file,'fixtures'=>0,'business_requests'=>0,'chat_startup'=>0]; throw new RuntimeException('ordinary Task7 implementation source is not yet available'); }
        $actual[$file]=hash_file('sha256',$path);
    }
    $handle=file_get_contents(audit_repo_dir().'/crmeb/crmeb/services/workerman/chat/ChatHandle.php');
    audit_expect(is_string($handle)&&str_contains($handle,'function guest_login('),'ordinary guest login implementation is not yet available');
    $evidence['source_inventory']=['actual_files_sha256'=>$actual,'file_count'=>count($actual),
        'layer'=>'SOURCE inventory only; original cross-application denial and browser checks are NOT RUN'];
}
audit_case('T7C01','ordinary_current_kefu_api_guest_chat','INTEGRATION',static function(): array {
    $evidence=['guard_mode'=>'read-only','ddl_denial_probe'=>'NOT RUN','requests'=>[],'ws_connections'=>[],'ws_identity_acks'=>[],
        'ws_rooms'=>[],'ws_sent'=>[],'normal_texts'=>[],'normal_closes'=>[],'guest_issuance'=>[],
        'stopped_cases'=>'A07/M07/K01 refusals, invalid/old credentials, fault/concurrency/disconnect/recovery NOT RUN'];
    $failure=null; $cleanupFailure=null; $pdo=null; $appStarted=false; $chatStarted=false; $chatAvailable=false; $sockets=[];
    $owners=['user'=>[],'store_service'=>[],'store_service_log'=>[],'store_service_record'=>[],'guest'=>[],'sequence'=>null];
    $ownedKeys=[]; $secrets=[]; $users=[]; $messages=[]; $sequenceBefore=null; $sequenceCaptured=false; $old=[]; $config=[];
    $counterTables=['eb_user','eb_store_service','eb_store_service_log','eb_store_service_record'];
    try {
        $initial=m7h_snapshot(); $evidence['before']=m7h_public_snapshot($initial);
        // Natural source availability baseline must precede app/chat start, any fixture or business IO.
        m7h_sources($evidence);
        audit_expect(count($initial['names'])===163,'ordinary after contains five real MS1 tables and all original installed tables');
        $pdo=audit_db(); $config=audit_config(); $slug=(string)getenv('AUDIT_RUN_ID').':'.bin2hex(random_bytes(8)); $prefix='audit:http-lang:'.getenv('AUDIT_RUN_ID').':'.time().':';
        $app=m7h_app($config,$prefix); m7h_preflight($pdo,$evidence);
        foreach (['user'=>'uid','store_service'=>'id','store_service_log'=>'id','store_service_record'=>'id'] as $table=>$pk) $old[$table]=m7h_row_hashes($pdo,'eb_'.$table,$pk);
        $old['cache_without_sequence']=m7h_row_hashes($pdo,'eb_cache','key',['kefu_tourist_uid_seq']);
        $stmt=$pdo->prepare('SELECT * FROM eb_cache WHERE `key`=?'); $stmt->execute(['kefu_tourist_uid_seq']); $value=$stmt->fetch(); $sequenceBefore=is_array($value) ? $value : null; $sequenceCaptured=true;
        if ($sequenceBefore!==null) audit_expect((int)$sequenceBefore['expire_time']===0&&preg_match('/^(0|[1-9][0-9]*)$/D',(string)$sequenceBefore['result'])===1,'existing visitor ledger starts permanent and canonical');
        if ($sequenceBefore!==null) {
            audit_expect(strlen($sequenceBefore['result'])<=10&&(int)$sequenceBefore['result']<=2147483645,'normal existing visitor ledger has room for both real issues');
        } else foreach (['eb_store_service_log'=>'uid<0 OR to_uid<0','eb_store_service_record'=>'user_id<0 OR to_uid<0'] as $table=>$where) {
            audit_expect((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'` WHERE '.$where)->fetchColumn()===0,'new ledger initial state has no existing negative visitor history');
        }
        m7h_warm_language($app,$prefix,$ownedKeys,$evidence); $system=m7h_system_warm($app,$prefix,$ownedKeys,$evidence);
        $users=m7h_seed($pdo,$slug,$owners,$secrets); m7h_old_rows($pdo,$old,$owners,$sequenceBefore);
        $kefuToken=m7h_issue_legacy($app,$prefix,'kefu',$users['kefu']['service_id'],$ownedKeys,$secrets,$evidence);
        $apiToken=m7h_issue_legacy($app,$prefix,'api',$users['customer']['uid'],$ownedKeys,$secrets,$evidence);
        // New current service has a User and notifications off. Production auto replies cannot match these ordinary unique texts.
        for ($i=0;$i<8;$i++) {
            $message='M7 ordinary '.substr(hash('sha256',$slug.'|message|'.$i),0,40); $messages[]=$message; $secrets[]=$message;
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_wechat_key WHERE key_type=1 AND (`keys`=? OR `keys` LIKE ?)'); $stmt->execute([$message,'%'.$message.'%']);
            audit_expect((int)$stmt->fetchColumn()===0,'ordinary synthetic text does not match any installed automatic response producer');
        }
        $service=audit_manifest()['services']['audit-chat'] ?? [];
        audit_expect(is_string($service['id'] ?? null)&&is_string($service['image_id'] ?? null)&&isset($service['runtime_volume']),'dedicated chat service must be registered before ordinary start');
        $chatAvailable=true; audit_write_json(audit_run_dir().'/ms1-chat-start.json',['run_id'=>getenv('AUDIT_RUN_ID'),'container_id'=>$service['id'],'image_id'=>$service['image_id']]);
        $start=m7h_chat_control('start',['cache_prefix'=>$prefix]); $chatStarted=true;
        audit_expect(($start['listener_ports'] ?? null)===[40002,40003]&&($start['admin_listener_open'] ?? null)===false,'real dedicated chat starts only its two production listeners');
        $evidence['chat_start']=$start; $evidence['chat_runtime_preflight']=audit_read_control_json(audit_run_dir().'/ms1-chat-preflight.json');
        audit_expect(($evidence['chat_runtime_preflight']['guard_mode'] ?? null)==='read-only'&&($evidence['chat_runtime_preflight']['calendar_last_week_guest_records'] ?? null)===0,'runtime repeats true read-only preflight before production timers');
        $appStarted=true; audit_app_start(['CACHE_PREFIX'=>$prefix]);
        $evidence['startup_readiness_request']=['method'=>'GET','path'=>'/adminapi/login/info','source'=>'unchanged normal audit_app_start readiness','count'=>1];
        $kefu=m7h_connect('kefu',$sockets,$evidence); m7h_login($kefu,'kefu','kefu',$users['kefu']['uid'],$kefuToken,$evidence);
        $info=m7h_http('/kefuapi/service/info',$kefuToken,$evidence);
        audit_expect(m7h_value($info['id'] ?? null,$users['kefu']['service_id'])&&m7h_value($info['uid'] ?? null,$users['kefu']['uid'])
            &&($info['account'] ?? null)===$owners['store_service'][$users['kefu']['service_id']]['account']&&($info['type'] ?? null)==='kefu'
            &&m7h_value($info['online'] ?? null,1)&&($info['site_name'] ?? null)===sys_config('site_name')&&!array_key_exists('password',$info)&&!array_key_exists('ip',$info)&&!array_key_exists('status',$info),'normal kefu HTTP resolves its own current enabled actual service only');
        $customer=m7h_connect('customer',$sockets,$evidence); m7h_login($customer,'customer','api',$users['customer']['uid'],$apiToken,$evidence);
        $selected=m7h_http('/kefuapi/tourist/user',$apiToken,$evidence);
        audit_expect(m7h_value($selected['uid'] ?? null,$users['kefu']['uid'])&&m7h_value($selected['tourist_uid'] ?? null,$users['customer']['uid'])
            &&($selected['is_tourist'] ?? null)===false&&!array_key_exists('guest_token',$selected)&&!array_key_exists('guest_expires_at',$selected),'normal valid API header selects current customer without issuing anonymous capability');
        $selectedKeys=array_keys($selected); sort($selectedKeys); $selectedFields=['nickname','avatar','real_name','uid','tourist_uid','tourist_avatar','is_tourist']; sort($selectedFields);
        audit_expect($selectedKeys===$selectedFields,'authenticated customer Common response retains exactly its seven fields without anonymous credential delivery');
        m7h_room($customer,'customer',$users['kefu']['uid'],$evidence); m7h_room($kefu,'kefu',$users['customer']['uid'],$evidence);
        m7h_text($customer,$kefu,'customer','kefu',$users['customer']['uid'],$users['kefu']['uid'],0,$messages[0],$pdo,$users,$messages,$owners,$evidence);
        m7h_text($kefu,$customer,'kefu','customer',$users['kefu']['uid'],$users['customer']['uid'],0,$messages[1],$pdo,$users,$messages,$owners,$evidence);
        m7h_heartbeat($sockets,$evidence); m7h_old_rows($pdo,$old,$owners,$sequenceBefore);
        $startTime=time(); $data=m7h_http('/kefuapi/tourist/user',null,$evidence); $endTime=time();
        $guest1=m7h_guest($data,$config,$users,$owners,$ownedKeys,$secrets,$evidence,$startTime,$endTime);
        audit_expect(in_array($guest1['avatar'],is_array(sys_config('tourist_avatar')) ? sys_config('tourist_avatar') : [],true)||$guest1['avatar']==='','guest avatar comes from its real server configuration');
        $guest=m7h_connect('guest1',$sockets,$evidence); m7h_login($guest,'guest1','guest',$guest1['uid'],$guest1['token'],$evidence,$guest1['expires_at']);
        m7h_room($guest,'guest1',$users['kefu']['uid'],$evidence); m7h_room($kefu,'kefu',$guest1['uid'],$evidence);
        m7h_text($guest,$kefu,'guest1','kefu',$guest1['uid'],$users['kefu']['uid'],1,$messages[2],$pdo,$users,$messages,$owners,$evidence);
        m7h_text($kefu,$guest,'kefu','guest1',$users['kefu']['uid'],$guest1['uid'],1,$messages[3],$pdo,$users,$messages,$owners,$evidence);
        m7h_heartbeat($sockets,$evidence); m7h_old_rows($pdo,$old,$owners,$sequenceBefore);
        // Ordinary user exit and a new normal connection with the same still-current unconsumed guest capability.
        // No fault disconnect, replayed/expired/consumed token or recovery scenario.
        m7h_room($guest,'guest1',0,$evidence); m7h_close('guest1',$sockets,$evidence);
        $guest=m7h_connect('guest1_new_connection',$sockets,$evidence); m7h_login($guest,'guest1_new_connection','guest',$guest1['uid'],$guest1['token'],$evidence,$guest1['expires_at']);
        m7h_room($guest,'guest1_new_connection',$users['kefu']['uid'],$evidence); m7h_room($kefu,'kefu',$guest1['uid'],$evidence);
        m7h_text($guest,$kefu,'guest1_new_connection','kefu',$guest1['uid'],$users['kefu']['uid'],1,$messages[4],$pdo,$users,$messages,$owners,$evidence);
        m7h_text($kefu,$guest,'kefu','guest1_new_connection',$users['kefu']['uid'],$guest1['uid'],1,$messages[5],$pdo,$users,$messages,$owners,$evidence);
        m7h_heartbeat($sockets,$evidence); m7h_old_rows($pdo,$old,$owners,$sequenceBefore);
        $startTime=time(); $data=m7h_http('/kefuapi/tourist/user',null,$evidence); $endTime=time();
        $guest2=m7h_guest($data,$config,$users,$owners,$ownedKeys,$secrets,$evidence,$startTime,$endTime);
        audit_expect($guest2['uid']!==$guest1['uid'],'normal refreshed anonymous issue has its new globally allocated negative UID');
        $guestNew=m7h_connect('guest2',$sockets,$evidence); m7h_login($guestNew,'guest2','guest',$guest2['uid'],$guest2['token'],$evidence,$guest2['expires_at']);
        m7h_room($guestNew,'guest2',$users['kefu']['uid'],$evidence); m7h_room($kefu,'kefu',$guest2['uid'],$evidence);
        m7h_text($guestNew,$kefu,'guest2','kefu',$guest2['uid'],$users['kefu']['uid'],1,$messages[6],$pdo,$users,$messages,$owners,$evidence);
        m7h_text($kefu,$guestNew,'kefu','guest2',$users['kefu']['uid'],$guest2['uid'],1,$messages[7],$pdo,$users,$messages,$owners,$evidence);
        m7h_heartbeat($sockets,$evidence); m7h_channel($prefix,$pdo,$users['kefu']['uid'],$users['customer']['uid'],$kefu,$evidence);
        m7h_old_rows($pdo,$old,$owners,$sequenceBefore); m7h_guest_discover($pdo,$config,$sequenceBefore,$owners,$ownedKeys,$secrets);
        audit_expect(count($evidence['requests'])===4&&count($evidence['ws_identity_acks'])===5&&count($evidence['ws_rooms'])===9
            &&count($evidence['normal_texts'])===8&&count($owners['guest'])===2&&count($owners['user'])===2&&count($owners['store_service'])===1
            &&count($owners['store_service_log'])===8&&count($owners['store_service_record'])===6,'actual ordinary scope has its fixed normal requests and exact real log/conversation inventory');
        $after=m7h_snapshot(); $changed=['eb_user','eb_store_service','eb_store_service_log','eb_store_service_record','eb_cache'];
        m7h_same($initial,$after,$changed,array_values(array_unique($ownedKeys)),$counterTables);
        $evidence['observations']=['business_http_requests'=>count($evidence['requests']),'normal_ws_client_frames'=>count($evidence['ws_sent']),
            'ws_identity_acks'=>count($evidence['ws_identity_acks']),'normal_room_acks'=>count($evidence['ws_rooms']),'ordinary_text_messages'=>count($evidence['normal_texts']),
            'native_guest_sessions'=>count($owners['guest']),'guest_new_connection_auth_is_current_unconsumed'=>true,'normal_zero_room_exit_verified'=>true,
            'external_notification_requests'=>0,'invalid_or_wrong_application_requests'=>0,'old_expired_consumed_token_requests'=>0,
            'fault_concurrency_disconnect_recovery_scenarios'=>0,'browser_acceptance'=>'NOT RUN','ordinary_channel_delivery_verified'=>true];
    } catch (Throwable $error) { $failure=m7h_diag($error); $evidence['failure_diagnostic']=$failure; }
    finally {
        foreach (array_keys($sockets) as $label) { try { m7h_close($label,$sockets,$evidence); } catch (Throwable $error) { $cleanupFailure ??= m7h_diag($error); } }
        $stopped=true;
        if ($chatAvailable) { try { $evidence['chat_stop']=m7h_chat_control('stop'); $chatStarted=false; } catch (Throwable $error) { $stopped=false; $cleanupFailure ??= m7h_diag($error); $evidence['chat_stop_diagnostic']=m7h_diag($error); } }
        try { if ($appStarted) audit_app_stop(); } catch (Throwable $error) { $stopped=false; $cleanupFailure ??= m7h_diag($error); }
        if ($stopped) { try {
            if ($pdo instanceof PDO) {
                if ($sequenceCaptured) m7h_guest_discover($pdo,$config,$sequenceBefore,$owners,$ownedKeys,$secrets); m7h_discover_chat($pdo,$users,$messages,$owners);
                if ($old) try { m7h_old_rows($pdo,$old,$owners,$sequenceBefore); } catch (Throwable $error) { $failure ??= m7h_diag($error); $evidence['before_cleanup_preservation_diagnostic']=m7h_diag($error); }
                $removed=m7h_cleanup($pdo,$owners,$ownedKeys,$sequenceBefore);
            } else { $keys=array_values(array_unique($ownedKeys)); $removed=$keys ? audit_redis()->del($keys) : 0; }
            $evidence['owned_keys_registered_count']=count(array_unique($ownedKeys)); $evidence['owned_keys_removed_count']=$removed;
        } catch (Throwable $error) { $cleanupFailure ??= m7h_diag($error); $evidence['cleanup_diagnostic']=m7h_diag($error); } }
        else $evidence['cleanup_blocked_until_own_services_stopped']=true;
    }
    if (isset($initial)) try {
        $after=m7h_snapshot(); $evidence['after']=m7h_public_snapshot($after); $counters=[];
        foreach ($counterTables as $table) if (count($owners[substr($table,3)])) $counters[]=$table;
        $forwardCounter=$sequenceBefore!==null&&count($owners['guest'])>0;
        m7h_same($initial,$after,$forwardCounter ? ['eb_cache'] : [],[],$counters);
        if ($old) m7h_old_rows(audit_db(),$old,['user'=>[],'store_service'=>[],'store_service_log'=>[],'store_service_record'=>[]],$sequenceBefore);
        audit_guard_assert();
        $evidence['preservation']=['tables'=>count($initial['names']),'original_rows_and_schema_checksums_preserved'=>true,'preexisting_visitor_sequence_result_exception'=>$forwardCounter,
            'complete_original_rows_and_schema_checksums_preserved'=>!$forwardCounter,'old_cache_values_and_absolute_expiry_unchanged'=>true,
            'counter_changes_allowed_only'=>$counters,'preexisting_visitor_sequence_restored'=>false,'new_sequence_removed'=>$sequenceBefore===null&&is_array($owners['sequence']),
            'snapshot_restore_used'=>false];
        if ($secrets) { audit_expect(m7h_runtime_logs($secrets),'normal runtime logs must omit owned credentials and text bodies'); $evidence['private_logs_verified_in_normal_configuration']=true; }
    } catch (Throwable $error) { $failure ??= m7h_diag($error); $evidence['preservation_diagnostic']=m7h_diag($error); }
    $evidence['fixtures_created']=array_sum(array_map('count',array_intersect_key($owners,array_flip(['user','store_service','store_service_log','store_service_record']))));
    $evidence['fixtures_cleaned']=$cleanupFailure===null&&$evidence['fixtures_created']>0;
    $evidence['owned_row_ids']=array_map(static fn(array $rows): array=>array_keys($rows),array_intersect_key($owners,array_flip(['user','store_service','store_service_log','store_service_record','guest'])));
    $ref='evidence/ms1-cross-app-'.(getenv('AUDIT_PHASE') ?: 'after').'.json'; $bytes=json_encode($evidence,JSON_THROW_ON_ERROR);
    foreach ($secrets as $secret) audit_expect($secret===''||!str_contains($bytes,$secret),'ordinary evidence contains no owned private payload');
    audit_write_json(audit_run_dir().'/'.$ref,$evidence); $passed=$failure===null&&$cleanupFailure===null;
    return ['status'=>$passed ? 'PASS' : 'FAIL','actual'=>$passed ? 'normal current kefu/API customer and opaque guests use real Chat/Channel, server identity and text rooms with precise cleanup'
        : ($failure ?? $cleanupFailure)['class'].'@'.($failure ?? $cleanupFailure)['file'].':'.($failure ?? $cleanupFailure)['line'],
        'evidence_ref'=>$ref,'expected'=>'ordinary owned current Task7 flows only','notes'=>'original A07/M07/K01 and all stopped invalid/old credential, fault, concurrency, disconnect and recovery checks remain NOT RUN; no browser or complete security acceptance'];
});
foreach ([
    ['A07','merchant_on_platform_configuration'],['A07','merchant_on_database_backup'],['A07','merchant_on_file_edit'],['A07','merchant_on_user_profile'],['A07','merchant_on_crud_generation'],
    ['M07','merchant_on_adminapi'],['M07','merchant_on_outapi'],['M07','merchant_on_kefuapi'],['M07','merchant_on_api'],
    ['M07','admin_on_merchantapi'],['M07','out_on_merchantapi'],['M07','kefu_on_merchantapi'],['M07','api_on_merchantapi'],
    ['K01','merchant_on_kefu_http'],['K01','merchant_on_kefu_ws_authentication'],['K01','merchant_on_kefu_room_subscription'],
    ['T7C01','invalid_credentials'],['T7C01','old_expired_consumed_credentials'],['T7C01','fault_injection'],['T7C01','concurrency'],
    ['T7C01','fault_disconnect'],['T7C01','recovery'],['ISO','ddl_denial_probe']
] as [$case,$subcase]) audit_case($case,$subcase,'INTEGRATION',static fn(): array=>['status'=>'NOT RUN','actual'=>'user-stopped validation; not invoked by the ordinary flow','notes'=>'retained acceptance gap']);
audit_finish();
