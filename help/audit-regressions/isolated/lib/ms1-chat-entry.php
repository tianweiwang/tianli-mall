<?php
declare(strict_types=1);
/* Dedicated Task7 ordinary runtime; only registered isolated processes. */
require __DIR__ . '/harness.php';
function m7c_app(array $config,string $prefix): \think\App
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

function m7c_mode(): void
{
    audit_expect((audit_manifest()['guard_mode'] ?? '')==='read-only','ordinary chat requires read-only guard mode');
    audit_guard_assert();
}
function m7c_prefix(string $prefix): void
{
    audit_expect(preg_match('/^audit:http-lang:'.preg_quote((string)getenv('AUDIT_RUN_ID'),'/').':[0-9]+:$/D',$prefix)===1,'ordinary chat cache prefix differs');
}
function m7c_self(): void
{
    $manifest=audit_manifest(); $run=(string)getenv('AUDIT_RUN_ID'); $service=$manifest['services']['audit-chat'] ?? [];
    $volume=($manifest['project'] ?? '').'_chat-runtime'; $id=$service['id'] ?? ''; $image=$service['image_id'] ?? ''; $address=$service['address'] ?? '';
    audit_expect(getenv('AUDIT_ISOLATED')==='1' && preg_match('/^[0-9]{14}[a-f0-9]{6}$/D',$run)===1
        && ($manifest['run_id'] ?? '')===$run && is_string($id) && preg_match('/^[a-f0-9]{64}$/D',$id)===1
        && is_string($image) && preg_match('/^sha256:[a-f0-9]{64}$/D',$image)===1
        && filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false && gethostname()===substr($id,0,12)
        && gethostbyname(gethostname())===$address && gethostbyname('audit-chat')===$address
        && ($service['runtime_volume'] ?? '')===$volume && in_array($volume,$manifest['volumes'] ?? [],true)
        && isset($manifest['volume_identities'][$volume]) && is_dir(audit_repo_dir().'/crmeb/runtime'),'registered dedicated chat identity differs');
}
function m7c_preflight(): array
{
    // Never make RecordDao/Services here: its constructor deletes last-calendar-week guests.
    $pdo=audit_db(); $facts=[];
    foreach (['eb_store_service','eb_store_service_record'] as $table) {
        $facts[$table.'_nonzero_online']=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'` WHERE online<>0')->fetchColumn();
        audit_expect($facts[$table.'_nonzero_online']===0,'ordinary chat starts only with every old online field already zero');
    }
    $from=strtotime('last week 00:00:00'); $to=strtotime('this week 00:00:00 -1second');
    audit_expect(is_int($from) && is_int($to) && $from<=$to,'calendar-week bounds are available');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM eb_store_service_record WHERE is_tourist=1 AND update_time BETWEEN ? AND ?'); $stmt->execute([$from,$to]);
    $facts['calendar_last_week_guest_records']=(int)$stmt->fetchColumn();
    audit_expect($facts['calendar_last_week_guest_records']===0,'ordinary chat requires an empty production automatic-delete range');
    $facts['timezone']=date_default_timezone_get(); $facts['calendar_last_week_from']=$from; $facts['calendar_last_week_to']=$to;
    $pdo=null; return $facts;
}
function m7c_listeners(): array
{
    $ports=[]; foreach (audit_app_tcp_rows() as $row) if ($row['state']==='0A' && in_array($row['local_port'],[40001,40002,40003],true)) $ports[]=$row['local_port'];
    $ports=array_values(array_unique($ports)); sort($ports,SORT_NUMERIC); return $ports;
}
function m7c_observe(array $group,array $supervisor): array
{
    $members=array_values(array_filter(audit_app_group_members($group,$supervisor),static fn(array $m): bool=>!in_array($m['state'],['Z','X','x'],true)));
    $inodes=[]; foreach (audit_app_tcp_rows() as $row) if ($row['state']==='0A' && in_array($row['local_port'],[40001,40002,40003],true)) $inodes[$row['inode']]=$row['local_port'];
    $owned=[];
    foreach ($members as &$member) {
        $member['listener_ports']=[]; $check=audit_app_proc_stat($member['pid']);
        if ($check===null || $check['starttime']!==$member['starttime'] || $check['pgid']!==$group['pgid'] || $check['sid']!==$group['sid']) continue;
        foreach (glob('/proc/'.$member['pid'].'/fd/*') ?: [] as $file) {
            $target=@readlink($file);
            if (is_string($target) && preg_match('/^socket:\[(\d+)\]$/D',$target,$m)===1 && isset($inodes[$m[1]])) $member['listener_ports'][]=$inodes[$m[1]];
        }
        $check=audit_app_proc_stat($member['pid']);
        audit_expect($check!==null && $check['starttime']===$member['starttime'] && $check['pgid']===$group['pgid'] && $check['sid']===$group['sid'],'chat process changed during listener observation');
        $member['listener_ports']=array_values(array_unique($member['listener_ports'])); sort($member['listener_ports'],SORT_NUMERIC);
        $owned=array_merge($owned,$member['listener_ports']);
    } unset($member);
    $owned=array_values(array_unique($owned)); sort($owned,SORT_NUMERIC);
    $visible=m7c_listeners(); audit_expect(!in_array(40001,$visible,true),'ordinary chat does not start the admin notice listener');
    audit_expect($owned===$visible,'dedicated chat listeners must belong to the registered generation');
    return ['group'=>$group,'supervisor'=>array_intersect_key($supervisor,array_flip(['pid','pgid','sid','starttime'])),
        'members'=>$members,'listener_ports'=>$owned,'admin_listener_open'=>false];
}
function m7c_stop(&$process,?array &$group,array $supervisor): array
{
    if ($group===null && is_resource($process) && proc_get_status($process)['running']) $group=audit_app_register_group($process,$supervisor);
    $stopped=$group; $remaining=[];
    if ($group!==null) {
        // Ordinary registered-generation teardown, never a failure/recovery scenario.
        foreach ([[SIGTERM,3.0],[SIGKILL,2.0]] as [$signal,$seconds]) {
            $remaining=audit_app_group_members($group,$supervisor);
            if ($remaining!==[] && !posix_kill(-$group['pgid'],$signal)) audit_expect(audit_app_group_members($group,$supervisor)===[],'registered chat stop did not complete');
            $deadline=microtime(true)+$seconds;
            do {
                if (is_resource($process)) proc_get_status($process);
                while (pcntl_waitpid(-$group['pgid'],$status,WNOHANG)>0) {}
                $remaining=audit_app_group_members($group,$supervisor);
                if ($remaining===[] && m7c_listeners()===[]) break 2;
                usleep(20000);
            } while (microtime(true)<$deadline);
        }
    }
    if (is_resource($process)) { audit_expect(!proc_get_status($process)['running'],'dedicated chat child remains after ordinary stop'); proc_close($process); $process=null; }
    audit_expect($remaining===[] && m7c_listeners()===[],'registered chat group or listener remains'); $group=null;
    return ['stopped_group'=>$stopped,'remaining_members'=>[],'listener_ports'=>[],'listeners_closed'=>true];
}
function m7c_serve(): never
{
    // Container can wait for registration without constructing application services.
    $permission=audit_run_dir().'/ms1-chat-start.json'; $deadline=microtime(true)+60;
    while (!is_file($permission) && microtime(true)<$deadline) usleep(100000);
    audit_expect(is_file($permission) && !is_link($permission),'chat registration permission is unavailable');
    $start=audit_read_control_json($permission); $manifest=audit_manifest();
    audit_expect(($start['run_id'] ?? '')===getenv('AUDIT_RUN_ID')
        && ($start['container_id'] ?? '')===($manifest['services']['audit-chat']['id'] ?? null)
        && ($start['image_id'] ?? '')===($manifest['services']['audit-chat']['image_id'] ?? null),'chat registration permission differs');
    m7c_mode(); m7c_self(); $supervisor=audit_app_proc_stat(getmypid()); audit_expect($supervisor!==null,'chat supervisor identity unavailable');
    $process=null; $group=null; $revision=''; $generation='';
    $stop=static function() use (&$process,&$group,&$generation,$supervisor): array { $r=m7c_stop($process,$group,$supervisor); $generation=''; return $r; };
    pcntl_async_signals(true); pcntl_signal(SIGTERM,static function() use ($stop): void { try { $stop(); exit(0); } catch (Throwable $e) { exit(2); } });
    while (true) {
        $control=audit_read_control_json(audit_run_dir().'/ms1-chat-control.json');
        if (($control['run_id'] ?? '')===getenv('AUDIT_RUN_ID') && ($control['revision'] ?? '')!==$revision) {
            $revision=$control['revision']; $action=$control['action'] ?? '';
            try {
                audit_expect(is_string($revision) && preg_match('/^[a-f0-9]{16}$/D',$revision)===1 && in_array($action,['start','observe','stop'],true),'ordinary chat control differs');
                $options=$control['options'] ?? []; audit_expect(is_array($options),'ordinary chat control options differ');
                if ($action==='observe') { audit_expect($options===[] && $group!==null && $generation!=='','chat observation requires its current generation'); $facts=m7c_observe($group,$supervisor); }
                else $facts=$stop();
                if ($action==='start') {
                    audit_expect(array_keys($options)===['cache_prefix'] && is_string($options['cache_prefix']),'chat start has only its normal cache prefix'); m7c_prefix($options['cache_prefix']);
                    m7c_mode(); m7c_self(); $log=audit_run_dir().'/evidence/ms1-chat-runtime.log';
                    $env=['PATH'=>(string)getenv('PATH'),'AUDIT_ISOLATED'=>'1','AUDIT_RUN_ID'=>(string)getenv('AUDIT_RUN_ID'),
                        'AUDIT_RUN_DIR'=>audit_run_dir(),'AUDIT_REPO_DIR'=>audit_repo_dir(),'AUDIT_PROJECT'=>(string)getenv('AUDIT_PROJECT'),
                        'AUDIT_SOURCE_SHA'=>(string)getenv('AUDIT_SOURCE_SHA'),'AUDIT_GUARD_MODE'=>'read-only'];
                    $process=proc_open(['setsid','php',__FILE__,'__runtime',$options['cache_prefix']],[['file','/dev/null','r'],['file',$log,'a'],['file',$log,'a']],$pipes,audit_repo_dir().'/crmeb',$env);
                    audit_expect(is_resource($process),'ordinary chat child unavailable'); $group=audit_app_register_group($process,$supervisor); $generation=$revision;
                    $deadline=microtime(true)+8;
                    do { $facts=m7c_observe($group,$supervisor); if ($facts['listener_ports']===[40002,40003]) break; usleep(10000); } while (microtime(true)<$deadline);
                    audit_expect($facts['listener_ports']===[40002,40003] && $facts['members']!==[],'actual chat and Channel listeners unavailable');
                }
                audit_write_json(audit_run_dir().'/ms1-chat-status.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,'status'=>$action,'generation_revision'=>$generation]+$facts);
            } catch (Throwable $error) {
                $cleanup=null; if ($action!=='observe') { try { $stop(); } catch (Throwable $e) { $cleanup=get_class($e); } }
                audit_write_json(audit_run_dir().'/ms1-chat-status.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,'status'=>'failed',
                    'error_class'=>get_class($error),'error_file'=>basename($error->getFile()),'error_line'=>$error->getLine(),'cleanup_error_class'=>$cleanup]);
            }
        }
        usleep(50000);
    }
}
function m7c_runtime(string $prefix): never
{
    m7c_mode(); m7c_self(); m7c_prefix($prefix); require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
    $app=m7c_app(audit_config(),$prefix); $facts=m7c_preflight();
    audit_write_json(audit_run_dir().'/ms1-chat-preflight.json',$facts+['run_id'=>getenv('AUDIT_RUN_ID'),'guard_mode'=>'read-only']);
    $app->config->set(['channel'=>['ip'=>'audit-chat','port'=>40003],'chat'=>['protocol'=>'websocket','ip'=>'0.0.0.0','port'=>40002,'serverCount'=>1]],'workerman');
    \Workerman\Worker::$daemonize=false; \Workerman\Worker::$pidFile=$app->getRuntimePath().'ms1-chat.pid';
    \Workerman\Worker::$logFile=$app->getRuntimePath().'ms1-chat-workerman.log'; \Workerman\Worker::$stdoutFile=$app->getRuntimePath().'ms1-chat-stdout.log';
    $event=(new ReflectionClass(\Workerman\Events\Select::class))->getFileName();
    audit_expect(is_string($event) && realpath($event)===realpath(audit_repo_dir().'/crmeb/crmeb/compat/workerman/Events/Select.php'),'actual PHP8.2 Workerman compatibility autoload differs');
    $channel=new \Channel\Server('0.0.0.0',40003); $worker=new \Workerman\Worker('websocket://0.0.0.0:40002'); $worker->name='AuditOrdinaryChat'; $worker->count=1;
    $service=new \crmeb\services\workerman\chat\ChatService($worker);
    $worker->onConnect=[$service,'onConnect']; $worker->onMessage=[$service,'onMessage']; $worker->onWorkerStart=[$service,'onWorkerStart']; $worker->onClose=[$service,'onClose'];
    // Only actual production Channel and Chat, no command that also constructs admin40001.
    global $argv; $argv=[__FILE__,'start']; $_SERVER['argv']=$argv; \Workerman\Worker::runAll(); exit(0);
}
function m7c_publish(string $prefix): void
{
    m7c_mode(); m7c_prefix($prefix); require_once audit_repo_dir().'/crmeb/vendor/autoload.php';
    $app=m7c_app(audit_config(),$prefix); $app->config->set(['channel'=>['ip'=>'audit-chat','port'=>40003]],'workerman');
    audit_expect(!class_exists('Workerman\\Worker',false),'ordinary one-shot Channel producer requires its real blocking Client path');
    $bytes=stream_get_contents(STDIN,8193); audit_expect(is_string($bytes) && strlen($bytes)<=8192,'normal Channel input is bounded');
    $input=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
    audit_expect(is_array($input) && array_keys($input)===['destination_uid','customer_uid','record','marker']
        && is_int($input['destination_uid']) && $input['destination_uid']>0 && is_int($input['customer_uid']) && $input['customer_uid']>0
        && is_array($input['record']) && is_string($input['marker']) && preg_match('/^[a-f0-9]{32}$/D',$input['marker'])===1,'normal Channel input has its exact owned staff and customer destination');
    $record=$input['record'];
    audit_expect((int)($record['id'] ?? 0)>0 && (int)($record['user_id'] ?? 0)===$input['destination_uid']
        && (int)($record['to_uid'] ?? 0)===$input['customer_uid'] && (int)($record['is_tourist'] ?? -1)===0
        && (int)($record['mssage_num'] ?? -1)===0,'normal Channel consumer receives its actual current customer conversation record');
    \crmeb\services\workerman\ChannelService::instance()->setTrigger('crmeb_chat')->send('mssage_num',
        ['uid'=>$input['customer_uid'],'num'=>0,'recored'=>$record,'marker'=>$input['marker']],[$input['destination_uid']]);
    echo "ordinary Channel publish returned; actual delivery requires recipient WS evidence\n";
}
try {
    match ($argv[1] ?? '') { '__serve'=>m7c_serve(),'__runtime'=>m7c_runtime((string)($argv[2] ?? '')),
        '__publish'=>m7c_publish((string)($argv[2] ?? '')),default=>throw new RuntimeException('unknown ordinary chat entry mode') };
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_THROW_ON_ERROR)."\n"); exit(2);
}
