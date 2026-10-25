<?php
declare(strict_types=1);

/** One explicit isolated normal profile. No scheduler, provider substitute, or automatic cleanup. */
if (!function_exists('audit_expect')) require_once '/media-environment/lib/harness.php';

const AUDIT_MEDIA_ROOTS = ['/run/audit-media-video', '/run/audit-media-scan'];
function audit_media_stat(string $path): array
{
    clearstatcache(true,$path);$s=lstat($path);
    audit_expect(is_array($s)&&!is_link($path)&&realpath($path)===$path,'normal private root identity unavailable');
    $out=[];foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)$out[$key]=$s[$key];return $out;
}
function audit_media_normal_guard(): array
{
    audit_expect(getenv('AUDIT_MEDIA_NORMAL')==='1'&&PHP_SAPI==='cli'&&PHP_OS_FAMILY==='Linux'
        &&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE===8,'normal media Linux PHP82 profile required');
    audit_guard_assert();$m=audit_manifest();
    audit_expect(($m['isolation']??null)==='ISO-FULL'&&($m['guard_mode']??null)==='read-only'
        &&($m['commit_sha']??null)===getenv('AUDIT_SOURCE_SHA')&&preg_match('/\A[a-f0-9]{40}\z/D',$m['commit_sha'])===1
        &&!file_exists(audit_repo_dir().'/crmeb/.env')&&!is_link(audit_repo_dir().'/crmeb/.env'),'normal current isolated source required');return $m;
}
function audit_media_normal_prepared(): array
{
    $m=audit_media_normal_guard();$p=json_decode(file_get_contents(audit_run_dir().'/media-preparation.json'),true,512,JSON_THROW_ON_ERROR);
    $proof=json_decode(file_get_contents(audit_run_dir().'/media-ms2-final-check.json'),true,512,JSON_THROW_ON_ERROR);
    audit_expect(array_keys($p)===['schema','run_id','source_sha','self_operated_mer_id','upload_max_bytes','status']
        &&$p['schema']===1&&$p['run_id']===$m['run_id']&&$p['source_sha']===$m['commit_sha']&&$p['status']==='PREPARED_NORMAL_MEDIA_SCHEMA_AND_ACTORS'
        &&is_int($p['self_operated_mer_id'])&&$p['self_operated_mer_id']>0&&$p['self_operated_mer_id']<=4294967295,'normal media preparation required');
    audit_expect(array_keys($proof)===['schema','run_id','source_sha','self_operated_mer_id','actual_cli_receipt','native_exit','cli_control']
        &&$proof['run_id']===$p['run_id']&&$proof['source_sha']===$p['source_sha']&&$proof['self_operated_mer_id']===$p['self_operated_mer_id']&&$proof['native_exit']===0
        &&$proof['actual_cli_receipt']['state']==='completed'&&$proof['actual_cli_receipt']['self_mer_id']===$p['self_operated_mer_id']
        &&$proof['actual_cli_receipt']['ownership_phase']==='enforced'&&$proof['actual_cli_receipt']['migration_complete']===true,'normal full MS2 proof required');return $p;
}
function audit_media_roots_configure(): void
{
    $p=audit_media_normal_prepared();$roots=[];
    foreach(AUDIT_MEDIA_ROOTS as$path){$before=audit_media_stat($path);audit_expect(($before['mode']&0170000)===0040000&&scandir($path)===['.','..']
        &&$before['uid']===posix_geteuid()&&chmod($path,0700),'fresh dedicated private root required');$after=audit_media_stat($path);
        audit_expect($after['dev']===$before['dev']&&$after['ino']===$before['ino']&&($after['mode']&0777)===0700,'dedicated root mode identity differs');$roots[$path]=$after;}
        $runtime=audit_media_stat('/run/audit-media-timer-runtime');audit_expect(($runtime['mode']&0170000)===0040000&&scandir('/run/audit-media-timer-runtime')===['.','..']&&$runtime['uid']===posix_geteuid()&&chmod('/run/audit-media-timer-runtime',0700),'fresh private Timer runtime required');
    $runtime=audit_media_stat('/run/audit-media-timer-runtime');
    $runtimeBefore=$runtime;$logPath='/run/audit-media-timer-runtime/timer.log';
    audit_expect(($runtime['mode']&0777)===0700&&!file_exists($logPath)&&!is_link($logPath),'fresh precise Timer log destination required');
    $log=fopen($logPath,'xb');audit_expect(is_resource($log),'exclusive real empty Timer log create');
    try{
        audit_expect(chmod($logPath,0600)&&fflush($log),'initial private Timer log mode and flush');$logNative=audit_media_stat($logPath);$fd=fstat($log);
        audit_expect(($logNative['mode']&0170000)===0100000&&($logNative['mode']&0777)===0600&&$logNative['uid']===posix_geteuid()
            &&$logNative['nlink']===1&&$logNative['size']===0&&is_array($fd),'real initial empty private Timer log');
        foreach($logNative as$key=>$value)audit_expect($fd[$key]===$value,'initial Timer log named/fd identity');
    }finally{audit_expect(fclose($log),'initial Timer log create handle closed');}
    $read=fopen($logPath,'rb');audit_expect(is_resource($read),'initial Timer log readback handle');
    try{$fd=fstat($read);foreach($logNative as$key=>$value)audit_expect(is_array($fd)&&$fd[$key]===$value,'initial Timer log readback same inode');
        $empty=fread($read,1);audit_expect($empty===''&&feof($read)&&audit_media_stat($logPath)===$logNative,'initial Timer log full empty readback');
        $fd=fstat($read);foreach($logNative as$key=>$value)audit_expect(is_array($fd)&&$fd[$key]===$value,'initial Timer log readback fd unchanged');
    }finally{audit_expect(fclose($read),'initial Timer log readback handle closed');}
    $runtime=audit_media_stat('/run/audit-media-timer-runtime');foreach(['dev','ino','mode','uid','gid','nlink']as$key)audit_expect($runtime[$key]===$runtimeBefore[$key],'initial Timer log keeps same private root');
    audit_expect(scandir('/run/audit-media-timer-runtime')===['.','..','timer.log'],'only precise real initial Timer log created');
    $initialLog=['name'=>'timer.log','stat'=>$logNative,'bytes'=>strlen($empty),'sha256'=>hash('sha256',$empty)];
    $identities=[];foreach(array_merge(array_values($roots),[$runtime])as$root)$identities[]=$root['dev'].':'.$root['ino'];
    audit_expect(count(array_unique($identities))===3,'normal video scan and Timer runtime roots must be distinct');
    $file=audit_run_dir().'/media-private-roots.json';audit_expect(!file_exists($file)&&!is_link($file),'normal roots receipt create-only');
    audit_write_json($file,['schema'=>1,'run_id'=>$p['run_id'],'source_sha'=>$p['source_sha'],'roots'=>$roots,'timer_runtime'=>$runtime,'timer_log'=>$initialLog]);
}
function audit_media_normal_roots(): void
{
    $p=audit_media_normal_prepared();$v=json_decode(file_get_contents(audit_run_dir().'/media-private-roots.json'),true,512,JSON_THROW_ON_ERROR);
    audit_expect(($v['schema']??null)===1&&$v['run_id']===$p['run_id']&&$v['source_sha']===$p['source_sha']&&array_keys($v['roots'])===AUDIT_MEDIA_ROOTS,'current dedicated root receipt required');
    foreach(AUDIT_MEDIA_ROOTS as$path){$s=audit_media_stat($path);$old=$v['roots'][$path];audit_expect(($s['mode']&0170000)===0040000&&($s['mode']&0777)===0700
        &&$s['uid']===posix_geteuid()&&$s['dev']===$old['dev']&&$s['ino']===$old['ino']&&$s['uid']===$old['uid'],'shared dedicated root ownership changed');}
}
function audit_media_normal_overrides(bool $timer=false): array
{
    $p=audit_media_normal_prepared();audit_media_normal_roots();$out=['MARKETPLACE_ENABLED'=>'true','MARKETPLACE_SELF_OPERATED_MER_ID'=>(string)$p['self_operated_mer_id'],
        'PRODUCT_VIDEO_UPLOAD_ENABLED'=>'true','PRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT'=>AUDIT_MEDIA_ROOTS[0],'PRODUCT_MEDIA_SCAN_PRIVATE_ROOT'=>AUDIT_MEDIA_ROOTS[1]];
    if($timer)$out['LOG_TIMER_LOG']='true';return $out;
}
function audit_media_normal_override_mapping(): array
{
    return ['MARKETPLACE_ENABLED'=>'PHP_MARKETPLACE_ENABLED','MARKETPLACE_SELF_OPERATED_MER_ID'=>'PHP_MARKETPLACE_SELF_OPERATED_MER_ID',
        'PRODUCT_VIDEO_UPLOAD_ENABLED'=>'PHP_MARKETPLACE_PRODUCT_VIDEO_UPLOAD_ENABLED','PRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT'=>'PHP_MARKETPLACE_PRODUCT_VIDEO_UPLOAD_PRIVATE_ROOT',
        'PRODUCT_MEDIA_SCAN_PRIVATE_ROOT'=>'PHP_MARKETPLACE_PRODUCT_MEDIA_SCAN_PRIVATE_ROOT','LOG_TIMER_LOG'=>'PHP_LOG_TIMER_LOG'];
}
function audit_media_normal_override_approved(string $key,string $value): bool
{
    $allowed=audit_media_normal_overrides(true);return isset($allowed[$key])&&$allowed[$key]===$value;
}
function audit_media_private_write(string $file,array|string $value): array
{
    audit_expect(str_starts_with($file,'/run/audit-bootstrap/media-env7-')&&!file_exists($file)&&!is_link($file),'normal private create-only output');
    $bytes=is_array($value)?json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n":$value;
    $h=fopen($file,'xb');audit_expect(is_resource($h),'normal private output open');
    try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($h,substr($bytes,$offset));audit_expect(is_int($n)&&$n>0,'normal complete private write');$offset+=$n;}audit_expect(fflush($h),'normal private flush');}
    finally{audit_expect(fclose($h),'normal private output close');}
    audit_expect(chmod($file,0600)&&file_get_contents($file)===$bytes,'normal private mode and readback');return ['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
}
function audit_media_child_environment(bool $migrator=false): array
{
    $c=audit_config();$user=$c['database']['username'];$password=$c['database']['password'];
    if($migrator){$migration=json_decode(file_get_contents('/run/audit-bootstrap/migrator.json'),true,512,JSON_THROW_ON_ERROR);$user=$migration['username'];$password=$migration['password'];audit_expect($user==='audit_migrator','normal existing migrator required');}
    $env=['PATH'=>getenv('PATH'),'PHP_APP_DEBUG'=>'false','PHP_APP_APP_KEY'=>$c['signing_key'],'PHP_DATABASE_HOSTNAME'=>'audit-mysql','PHP_DATABASE_HOSTPORT'=>'3306',
        'PHP_DATABASE_DATABASE'=>'audit_auth','PHP_DATABASE_USERNAME'=>$user,'PHP_DATABASE_PASSWORD'=>$password,'PHP_DATABASE_PREFIX'=>'eb_','PHP_DATABASE_DEBUG'=>'false',
        'PHP_CACHE_DRIVER'=>'redis','PHP_REDIS_REDIS_HOSTNAME'=>'audit-redis','PHP_REDIS_PORT'=>'6379','PHP_REDIS_REDIS_PASSWORD'=>$c['redis_password'],
        'PHP_CACHE_CACHE_PREFIX'=>'c:','PHP_CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','PHP_AUTH_SESSION_EPOCH'=>$c['auth']['session_epoch'],'PHP_AUTH_STAMP_KEY'=>$c['auth']['stamp_key'],'PHP_AUTH_ISSUER'=>$c['auth']['issuer']];
    $map=audit_media_normal_override_mapping();foreach(audit_media_normal_overrides()as$key=>$value)$env[$map[$key]]=$value;return $env;
}
function audit_media_cli(string $script,string $mode,array &$children): array
{
    audit_media_normal_guard();$install=$script==='think';
    audit_expect($install?$mode==='marketplace:product-scan-gc-install':in_array($script,['marketplace-ms4.php','marketplace-ms5-payment.php','marketplace-ms4-fulfillment.php','marketplace-ms4-aftersale.php'],true)
        &&in_array($mode,['check','apply-structure'],true),'normal finite original CLI');
    $path=audit_repo_dir().($install?'/crmeb/think':'/help/migrations/'.$script);$argv=[PHP_BINARY,$path,$mode];$env=null;
    if($install)$env=audit_media_child_environment(true);else{$config='/run/audit-bootstrap/media-ms1-config.json';$meta=lstat($config);audit_expect(is_array($meta)&&!is_link($config)&&($meta['mode']&0777)===0600,'existing private migrator config');$argv[]='--config-file='.$config;}
    $process=proc_open($argv,[['file','/dev/null','r'],['pipe','w'],['pipe','w']],$pipes,$install?audit_repo_dir().'/crmeb':audit_repo_dir(),$env);audit_expect(is_resource($process),'normal original CLI started');$first=proc_get_status($process);$out='';$err='';
    try{audit_expect(is_array($first)&&is_int($first['pid'])&&$first['pid']>0,'normal real CLI PID');$out=stream_get_contents($pipes[1],33554433);$err=stream_get_contents($pipes[2],1048577);}
    finally{foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);$close=proc_close($process);}
    $native=$close;$origin='proc_close';if($close===-1&&$first['running']===false&&$first['signaled']===false&&is_int($first['exitcode'])&&$first['exitcode']>=0){$native=$first['exitcode'];$origin='actual_first_terminal_status';}
    $index=count($children)+1;$stdout=audit_media_private_write('/run/audit-bootstrap/media-env7-'.($install?'timer-install':'schema').'-cli-'.$index.'.stdout',(string)$out);$stderr=audit_media_private_write('/run/audit-bootstrap/media-env7-'.($install?'timer-install':'schema').'-cli-'.$index.'.stderr',(string)$err);
    $control=['script'=>$script,'script_sha256'=>hash_file('sha256',$path),'mode'=>$mode,'pid'=>$first['pid'],'proc_close_exit'=>$close,'native_exit'=>$native,'native_exit_origin'=>$origin,'stdout'=>$stdout,'stderr'=>$stderr,'private_config_and_argv_omitted'=>true];$children[]=$control;
    $manifest=audit_manifest();audit_write_json(audit_run_dir().'/evidence/media-env7-'.($install?'timer-install':'schema').'-cli-progress.json',
        ['schema'=>1,'scope'=>'ACTUAL_NATIVE_CHILD_PROGRESS_NOT_ACCEPTANCE','run_id'=>$manifest['run_id'],'source_sha'=>$manifest['commit_sha'],'children'=>$children]);
    audit_expect(is_string($out)&&strlen($out)<=33554432&&$native===0&&$err==='','normal original CLI native zero');$v=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    audit_expect(is_array($v)&&($install||(($v['mode']??null)===$mode&&in_array($v['state']??'', ['applicable','completed'],true))),'normal original CLI receipt');return $v;
}
function audit_media_db(): PDO
{
    $db=audit_db();audit_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)&&$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false)
        &&!$db->inTransaction()&&(string)$db->query('SELECT @@session.autocommit')->fetchColumn()==='1','normal independent native observer');return $db;
}
function audit_media_rows(PDO $db,string $sql,array $args=[]): array
{
    $s=$db->prepare($sql);audit_expect($s instanceof PDOStatement&&$s->execute($args),'normal native observation');try{return $s->fetchAll(PDO::FETCH_ASSOC);}finally{audit_expect($s->closeCursor(),'normal native cursor closed');}
}
function audit_media_schema_snapshot(array $projection=[]): array
{
    $db=audit_media_db();$names=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($names,SORT_STRING);$out=['names'=>$names,'tables'=>[],
        'modes'=>audit_media_rows($db,'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS innodb_session,@@GLOBAL.innodb_strict_mode AS innodb_global')];
    foreach($names as$name){audit_expect(preg_match('/\A(?:eb_[a-z0-9_]+|audit_sentinel)\z/D',$name)===1,'normal original table namespace');$cols=array_column(audit_media_rows($db,'SHOW COLUMNS FROM `'.$name.'`'),'Field');$pick=$projection[$name]??$cols;
        foreach($pick as$col)audit_expect(preg_match('/\A[A-Za-z0-9_]+\z/D',$col)===1&&in_array($col,$cols,true),'normal full original columns');
        $rows=audit_media_rows($db,'SELECT `'.implode('`,`',$pick).'` FROM `'.$name.'`');$hashes=array_map('serialize',$rows);sort($hashes,SORT_STRING);$ddl=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);
        audit_expect(is_array($ddl)&&is_string($ddl[1]??null),'normal complete native DDL');$out['tables'][$name]=['columns'=>$cols,'projected_columns'=>$pick,'rows'=>count($rows),'sha256'=>hash('sha256',serialize($hashes)),
            'ddl_sha256'=>hash('sha256',preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl[1]))];}return $out;
}
function audit_media_schema_prepare(): void
{
    audit_media_normal_prepared();audit_media_normal_roots();$children=[];$steps20=['create.marketplace_aftersale_action','refund.column.marketplace_root_order_id','refund.column.marketplace_payment_attempt_id',
        'refund.column.marketplace_kind','refund.column.marketplace_state','refund.column.marketplace_physical_state','refund.column.marketplace_finance_state','refund.column.marketplace_apply_action_id','refund.column.marketplace_last_action_id',
        'refund.index.idx_ms4a_refund_root','refund.index.uq_ms4a_refund_apply','refund.index.idx_ms4a_refund_last','refund.foreign.mm4a_refund_apply_fk','refund.foreign.mm4a_refund_last_fk','refund.check.mm4a_refund_v1',
        'operation.column.aftersale_cut_id','operation.index.idx_fop_aftersale_cut','operation.foreign.mm4a_fop_cut_fk','operation.checks.replace_known_ids_and_add_cut','create.marketplace_aftersale_file'];
    $plans=[['marketplace-ms4.php',13,['eb_marketplace_order_idempotency','eb_marketplace_stock_movement'],['eb_store_order','eb_store_order_cart_info','eb_store_order_refund','eb_store_product_reply']],
        ['marketplace-ms5-payment.php',['marketplace_payment_registry','marketplace_payment_attempt'],['eb_marketplace_payment_attempt','eb_marketplace_payment_registry'],[]],
        ['marketplace-ms4-fulfillment.php',['marketplace_fulfillment_operation','marketplace_fulfillment_range'],['eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range'],[]],
        ['marketplace-ms4-aftersale.php',$steps20,['eb_marketplace_aftersale_action','eb_marketplace_aftersale_file'],['eb_store_order_refund','eb_marketplace_fulfillment_operation']]];
    foreach($plans as[$script,$steps,$newNames,$targets]){
        $before=audit_media_schema_snapshot();$projection=array_map(static fn(array$r):array=>$r['columns'],$before['tables']);
        foreach($newNames as$table)audit_expect(!in_array($table,$before['names'],true),'normal fresh original migration targets absent');
        $check=audit_media_cli($script,'check',$children);$pending=$check['pending_steps']??null;
        audit_expect($check['state']==='applicable'&&$check['changed']===false&&($check['applied_steps']??[])===[]
            &&(is_int($steps)?is_array($pending)&&count($pending)===$steps:$pending===$steps)&&audit_media_schema_snapshot()===$before,'normal original first check unchanged');
        $apply=audit_media_cli($script,'apply-structure',$children);audit_expect($apply['state']==='completed'&&$apply['changed']===true&&$apply['pending_steps']===[]
            &&(is_int($steps)?count($apply['applied_steps'])===$steps:$apply['applied_steps']===$steps),'normal original apply exact steps');
        if($script==='marketplace-ms4-aftersale.php')audit_expect($check['structure_stage']===0&&$apply['structure_stage']===20,'normal final Task7 stage20');
        $after=audit_media_schema_snapshot($projection);$added=array_values(array_diff($after['names'],$before['names']));sort($added,SORT_STRING);sort($newNames,SORT_STRING);
        audit_expect($added===$newNames&&count($after['names'])===count($before['names'])+2&&$after['modes']===$before['modes'],'normal only original schema tables and modes');
        foreach($before['tables']as$table=>$row){$actual=$after['tables'][$table];audit_expect($actual['rows']===$row['rows']&&$actual['sha256']===$row['sha256'],'normal all original row projections preserved');
            if(!in_array($table,$targets,true))audit_expect($actual['columns']===$row['columns']&&$actual['ddl_sha256']===$row['ddl_sha256'],'normal nontarget complete DDL preserved');}
        foreach($newNames as$table)audit_expect($after['tables'][$table]['rows']===0,'normal empty original new schema tables');
        foreach(['check','apply-structure']as$mode){$again=audit_media_cli($script,$mode,$children);audit_expect($again['state']==='completed'&&$again['changed']===false&&$again['pending_steps']===[]&&$again['applied_steps']===[]
                &&audit_media_schema_snapshot($projection)===$after,'normal original repeat zero writes and complete snapshot unchanged');
            if($script==='marketplace-ms4-aftersale.php')audit_expect($again['structure_stage']===20,'normal stage20 repeat');}
    }
    $m=audit_manifest();$file=audit_run_dir().'/media-timer-schema.json';audit_expect(!file_exists($file),'normal schema receipt create-only');
    audit_write_json($file,['schema'=>1,'run_id'=>$m['run_id'],'source_sha'=>$m['commit_sha'],'children'=>$children,'original_four_CLI_sequence'=>true,'final_aftersale_stage'=>20,'no_orders_or_business_fixture_created'=>true]);
}
function audit_media_live_room_fixture(): void
{
    $m=audit_media_normal_guard();$db=audit_media_db();$source=hash_file('sha256',audit_repo_dir().'/crmeb/public/install/crmeb.sql');audit_expect($source===$m['fixture_sha256'],'normal unchanged original SQL source');
    $expected=['id'=>'5','name'=>'自动更新直播间状态','mark'=>'liveRoomStatus','content'=>'每隔3分钟执行更新直播间状态','type'=>'2','month'=>'0','week'=>'1','day'=>'1','hour'=>'1','minute'=>'3','second'=>'0',
        'last_execution_time'=>'0','next_execution_time'=>'1670642709','add_time'=>'1670642529','update_time'=>'0','is_del'=>'0','is_open'=>'1','customCode'=>'','timeStr'=>''];
    $db->beginTransaction();try{$before=audit_media_rows($db,'SELECT * FROM eb_system_timer ORDER BY id FOR UPDATE');$matched=array_values(array_filter($before,static fn(array$r):bool=>(string)$r['id']==='5'));
        audit_expect(count($matched)===1&&array_map('strval',$matched[0])===$expected,'normal original complete liveRoomStatus identity and cycle');$old=$matched[0];$s=$db->prepare('UPDATE eb_system_timer SET is_open=0 WHERE id=5 AND BINARY mark=BINARY ? AND is_open=1');
        audit_expect($s->execute(['liveRoomStatus'])&&$s->rowCount()===1,'normal explicit sole live room fixture switch');$s->closeCursor();$after=audit_media_rows($db,'SELECT * FROM eb_system_timer ORDER BY id');$wanted=$before;
        foreach($wanted as&$row)if((string)$row['id']==='5')$row['is_open']=0;unset($row);audit_expect($after===$wanted,'normal all other timer fields and rows unchanged');audit_expect($db->commit(),'normal fixture switch committed');}
    catch(Throwable$error){if($db->inTransaction())$db->rollBack();throw$error;}
    audit_expect(audit_media_rows(audit_media_db(),'SELECT * FROM eb_system_timer ORDER BY id')===$after,'normal fixture switch fresh-connection committed visibility');
    $new=array_values(array_filter($after,static fn(array$r):bool=>(string)$r['id']==='5'))[0];$private=audit_media_private_write('/run/audit-bootstrap/media-env7-live-room-fixture.json',
        ['scope'=>'NEW_DEDICATED_NORMAL_FIXTURE_ONLY','purpose'=>'avoid unconfigured real WeChat request while keeping original scheduler and all other tasks','run_id'=>$m['run_id'],'source_sha'=>$m['commit_sha'],'installation_sql_sha256'=>$source,'before'=>$old,'after'=>$new,'only_changed_field'=>'is_open']);
    audit_write_json(audit_run_dir().'/media-timer-fixture-config.json',['schema'=>1,'scope'=>'NEW_DEDICATED_NORMAL_FIXTURE_ONLY','run_id'=>$m['run_id'],'source_sha'=>$m['commit_sha'],
        'timer_id'=>5,'mark'=>'liveRoomStatus','only_changed_field'=>'is_open','before_row_sha256'=>hash('sha256',serialize($old)),'after_row_sha256'=>hash('sha256',serialize($new)),'private_complete_receipt'=>$private,'production_default_and_real_provider_acceptance'=>'NOT RUN']);
}
function audit_media_app(): \think\App
{
    static $app=null;audit_expect($app===null,'normal profile one framework initialization');require_once audit_repo_dir().'/crmeb/vendor/autoload.php';require_once audit_repo_dir().'/crmeb/vendor/topthink/framework/src/helper.php';
    $app=new \think\App(audit_repo_dir().'/crmeb/');$app->setRuntimePath('/tmp/media-env7-profile-'.bin2hex(random_bytes(8)).'/');
    foreach(audit_media_child_environment()as$key=>$value)if(str_starts_with($key,'PHP_'))$app->env->set(substr($key,4),$value);
    $app->initialize();audit_expect($app->initialized()&&app()===$app,'normal actual framework instance');date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));return $app;
}
function audit_media_timer_preflight(): array
{
    $p=audit_media_normal_prepared();audit_media_normal_roots();
    foreach(['proc_open','proc_get_status','proc_terminate','proc_close','get_resource_id','stream_set_blocking','stream_select','fread','feof','fclose','hrtime','usleep',
        'getmypid','posix_getpid','posix_getppid','posix_getuid','posix_getpwuid','posix_geteuid','posix_getpgid','posix_getsid','posix_kill','pcntl_fork','pcntl_async_signals','pcntl_signal','pcntl_signal_dispatch','pcntl_wait','pcntl_waitpid','pcntl_alarm','pcntl_wifexited','pcntl_wexitstatus',
        'file_get_contents','readlink','realpath','lstat','glob','json_decode','json_encode','serialize','base64_decode','bccomp','bcmul','bcsub','socket_create']as$f)
        audit_expect(function_exists($f)&&!in_array(strtolower($f),array_map('trim',explode(',',strtolower((string)ini_get('disable_functions')))),true),'normal actual original Timer native function required');
    audit_expect(is_file('/usr/bin/setsid')&&is_executable('/usr/bin/setsid')&&is_dir('/proc')&&!is_link('/proc')&&realpath('/proc')==='/proc','normal actual original process-group facilities required');
    foreach(['pcntl','posix','pdo_mysql','bcmath','redis','sockets']as$extension)audit_expect(extension_loaded($extension),'normal actual original Timer extension required');
    $schema=json_decode(file_get_contents(audit_run_dir().'/media-timer-schema.json'),true,512,JSON_THROW_ON_ERROR);audit_expect($schema['run_id']===$p['run_id']&&$schema['source_sha']===$p['source_sha']&&$schema['final_aftersale_stage']===20&&count($schema['children'])===16,'normal actual complete schema preparation required');
    $app=audit_media_app();audit_expect($app->config->get('cache.default')==='redis'&&$app->config->get('marketplace.enabled')===true
        &&(string)$app->config->get('marketplace.self_operated_mer_id')===(string)$p['self_operated_mer_id']&&$app->config->get('marketplace.product_video_upload.enabled')===true
        &&$app->config->get('marketplace.product_video_upload.private_root')===AUDIT_MEDIA_ROOTS[0]&&$app->config->get('marketplace.product_media_scan.private_root')===AUDIT_MEDIA_ROOTS[1]
        &&$app->config->get('marketplace.product_video_upload.ttl_seconds')===600&&$app->config->get('marketplace.product_media_scan.gc_period_seconds')===60,'normal actual framework profile and unmodified TTL cadence');
    $cacheOptions=$app->config->get('cache.stores.redis');audit_expect($cacheOptions['prefix']==='c:'&&$cacheOptions['tag_prefix']==='CRMEB:'&&$cacheOptions['select']===0,'normal actual original cache codec namespace');
    $connection=\think\facade\Db::connect();$native=$connection->getPdo();audit_expect($native instanceof PDO&&!$native->inTransaction(),'normal actual scheduler native connection');
    app()->make(\app\dao\marketplace\order\MarketplaceOrderFulfillmentDao::class)->assertAftersaleStructure($native);
    $db=audit_media_db();$empty=['store_order','store_order_cart_info','store_order_refund','store_order_status','store_order_invoice','store_product_reply','store_pink','live_goods','live_room','user_sign','store_cart',
        'marketplace_order_idempotency','marketplace_stock_movement','marketplace_payment_registry','marketplace_payment_attempt','marketplace_fulfillment_operation','marketplace_fulfillment_range','marketplace_aftersale_action','marketplace_aftersale_file'];$counts=[];
    foreach($empty as$table){$count=$db->query('SELECT COUNT(*) FROM `eb_'.$table.'`')->fetchColumn();audit_expect((string)$count==='0','normal all future financial/provider candidates absent');$counts[$table]=0;}
    foreach(['sign_remind'=>'SELECT COUNT(*) FROM eb_user WHERE sign_remind=1','spread'=>'SELECT COUNT(*) FROM eb_user WHERE status=1 AND spread_uid<>0','posters'=>'SELECT COUNT(*) FROM eb_system_attachment WHERE module_type=2']as$key=>$query){audit_expect((string)$db->query($query)->fetchColumn()==='0','normal original reminder spread poster candidates absent');$counts[$key]=0;}
    $s=$db->prepare('SELECT COUNT(*) FROM eb_store_product WHERE mer_id=? AND presale=1');$s->execute([$p['self_operated_mer_id']]);audit_expect((string)$s->fetchColumn()==='0','normal actual SELF future presale candidates absent');$s->closeCursor();$counts['self_presale']=0;
    $keys=['order_cancel_time','order_activity_time','order_bargain_time','order_seckill_time','order_pink_time','brokerage_func_status','store_brokerage_binding_status','store_brokerage_binding_time','system_delivery_time','system_comment_time','elec_invoice','auto_invoice','sign_remind_time'];$configuration=[];
    foreach($keys as$key){$rows=audit_media_rows($db,'SELECT id,menu_name,value FROM eb_system_config WHERE BINARY menu_name=BINARY ? ORDER BY id',[$key]);audit_expect(count($rows)===1,'normal actual original job configuration exists');$value=json_decode($rows[0]['value'],true,64,JSON_THROW_ON_ERROR);
        $cacheKey='c:system_config_'.$key;$redis=audit_redis();$had=$redis->exists($cacheKey);$result=\crmeb\services\SystemConfigService::get($key,'NORMAL_CONFIG_NOT_READY');
        $encoded=is_numeric($value)?(string)$value:serialize($value);$decoded=is_numeric($value)?(string)$value:$value;
        audit_expect($result===($had?$decoded:$value)&&$redis->type($cacheKey)===Redis::REDIS_STRING&&$redis->get($cacheKey)===$encoded&&$redis->rawCommand('PEXPIRETIME',$cacheKey)===-1,'normal exact original cache codec and native JSON value');
        if($key==='sign_remind_time')audit_expect(is_string($value)&&preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/D',$value)===1,'normal actual reminder time');
        else audit_expect((is_int($value)||is_string($value))&&ctype_digit((string)$value),'normal actual numeric job configuration');$configuration[$key]=hash('sha256',serialize($value));}
    $orders=array_slice($keys,0,5);$all=\crmeb\services\SystemConfigService::more($orders);foreach($orders as$key)audit_expect(isset($all[$key])&&hash('sha256',serialize($all[$key]))===$configuration[$key],'normal original combined order configuration agrees');
    $timers=audit_media_rows($db,'SELECT * FROM eb_system_timer WHERE is_open=1 AND is_del=0 ORDER BY id');$marks=array_column($timers,'mark');$expected=['orderCancel','pinkExpiration','agentUnbind','liveProductStatus','takeDelivery','advanceOff','productReplay','clearPoster','autoInvoice','signRemind','marketplaceProductScanGc'];sort($marks,SORT_STRING);sort($expected,SORT_STRING);
    audit_expect($marks===$expected&&count(array_filter($timers,static fn(array$r):bool=>$r['customCode']!==''))===0,'normal all configured original open tasks plus registered GC');
    $result=['schema'=>1,'scope'=>'DEDICATED_MEDIA_NORMAL_CONFIGURED_TIMER_ONLY','run_id'=>$p['run_id'],'source_sha'=>$p['source_sha'],'actual_native_extensions_ready'=>true,'final_Task7_structure_ready'=>true,
        'zero_candidates'=>$counts,'configuration_value_hashes'=>$configuration,'actual_open_marks'=>$marks,'actual_open_rows_sha256'=>hash('sha256',serialize($timers)),'video_ttl_seconds'=>600,'original_gc_period_seconds'=>60,
        'original_default_11_and_real_WeChat'=>'NOT RUN','scheduled_execution_and_actual_Timer_log_config'=>'PENDING_REAL_TIMER_WINDOW'];return $result;
}
function audit_media_install_timer(): void
{
    $p=audit_media_normal_prepared();audit_media_normal_roots();$schema=json_decode(file_get_contents(audit_run_dir().'/media-timer-schema.json'),true,512,JSON_THROW_ON_ERROR);
    audit_expect($schema['run_id']===$p['run_id']&&$schema['source_sha']===$p['source_sha']&&$schema['final_aftersale_stage']===20,'normal actual final schema before original Timer install');
    audit_media_live_room_fixture();$before=audit_media_rows(audit_media_db(),'SELECT * FROM eb_system_timer ORDER BY id');$children=[];$first=audit_media_cli('think','marketplace:product-scan-gc-install',$children);
    audit_expect(is_int($first['timer_id']??null)&&$first['timer_id']>0&&($first['changed']??null)===true&&($first['mark']??null)==='marketplaceProductScanGc'
        &&$first['timeStr']==='*/1 * * * * *'&&$first['wake_seconds']===1&&$first['gc_period_seconds']===60&&$first['gc_batch']===16,'normal actual original GC install');
    $after=audit_media_rows(audit_media_db(),'SELECT * FROM eb_system_timer ORDER BY id');$old=array_values(array_filter($after,static fn(array$r):bool=>(int)$r['id']!==$first['timer_id']));audit_expect($old===$before&&count($after)===count($before)+1,'normal original timer install one row only');
    $second=audit_media_cli('think','marketplace:product-scan-gc-install',$children);$wanted=$first;$wanted['changed']=false;audit_expect($second===$wanted
        &&audit_media_rows(audit_media_db(),'SELECT * FROM eb_system_timer ORDER BY id')===$after,'normal original GC register repeat zero row changes');
    $file=audit_run_dir().'/media-timer-install.json';audit_expect(!file_exists($file),'normal Timer install create-only receipt');audit_write_json($file,['schema'=>1,'run_id'=>$p['run_id'],'source_sha'=>$p['source_sha'],'original_result'=>$first,'repeat_result'=>$second,'children'=>$children,'all_other_original_rows_preserved'=>true]);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    umask(0077);$action=$argv[1]??'';
    try{audit_expect(count($argv)===2&&in_array($action,['configure','schema','install','preflight','preflight-timer','timer-start','timer-observe','timer-stop','serve'],true),'normal finite media profile action');
        if($action==='configure')audit_media_roots_configure();elseif($action==='schema')audit_media_schema_prepare();elseif($action==='install')audit_media_install_timer();
        elseif(in_array($action,['preflight','preflight-timer'],true)){$v=audit_media_timer_preflight();$file=audit_run_dir().($action==='preflight'?'/media-timer-preflight.json':'/media-timer-preflight-before-start.json');audit_expect(!file_exists($file),'normal Timer preflight create-only');audit_write_json($file,$v);}
        elseif($action==='serve'){
            audit_expect(getenv('AUDIT_MEDIA_TIMER_ROLE')==='1','normal dedicated Timer role required');audit_media_normal_roots();
            $roots=json_decode(file_get_contents(audit_run_dir().'/media-private-roots.json'),true,512,JSON_THROW_ON_ERROR);$runtime=audit_media_stat(audit_repo_dir().'/crmeb/runtime');$before=$roots['timer_runtime'];
            audit_expect($runtime===$before&&($runtime['mode']&0777)===0700&&$runtime['uid']===posix_geteuid()&&scandir(audit_repo_dir().'/crmeb/runtime')===['.','..','timer.log'],'normal exact original private Timer runtime before start');
            $initial=$roots['timer_log'];$logPath=audit_repo_dir().'/crmeb/runtime/timer.log';$named=audit_media_stat($logPath);
            audit_expect($initial['name']==='timer.log'&&$initial['bytes']===0&&$initial['sha256']===hash('sha256','')&&$named===$initial['stat']
                &&($named['mode']&0170000)===0100000&&($named['mode']&0777)===0600&&$named['uid']===posix_geteuid()&&$named['nlink']===1&&$named['size']===0,'same precise precreated empty private Timer log before original Worker');
            $read=fopen($logPath,'rb');audit_expect(is_resource($read),'prestart real Timer log read handle');
            try{$fd=fstat($read);foreach($named as$key=>$value)audit_expect(is_array($fd)&&$fd[$key]===$value,'prestart Timer log same named/fd identity');
                $bytes=fread($read,1);audit_expect($bytes===''&&feof($read)&&hash('sha256',$bytes)===$initial['sha256']&&audit_media_stat($logPath)===$named,'prestart Timer log full empty readback unchanged');
                $fd=fstat($read);foreach($named as$key=>$value)audit_expect(is_array($fd)&&$fd[$key]===$value,'prestart Timer log fd unchanged');
            }finally{audit_expect(fclose($read),'prestart Timer log read handle closed');}
            audit_expect(audit_media_stat(audit_repo_dir().'/crmeb/runtime')===$runtime,'prestart Timer runtime unchanged');audit_serve('media-timer');
        }
        else{audit_media_normal_roots();audit_app_request(substr($action,6),$action==='timer-start'?audit_media_normal_overrides(true):[],'media-timer');}
        echo json_encode(['status'=>'NORMAL_MEDIA_PROFILE_ACTION_COMPLETE','action'=>$action],JSON_THROW_ON_ERROR)."\n";exit(0);
    }catch(Throwable$error){echo json_encode(['status'=>'NORMAL_MEDIA_PROFILE_ACTION_FAILED','action'=>$action,'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_THROW_ON_ERROR)."\n";exit(1);}
}
