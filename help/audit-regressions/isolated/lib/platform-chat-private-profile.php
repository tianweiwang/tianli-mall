<?php
declare(strict_types=1);
/** Exact ordinary platform-chat profile. Importing declares functions only. */
require_once __DIR__.'/harness.php';
const M4PC_PRIVATE_ROOT='/run/audit-platform-chat';

function m4pc_profile_guard():array
{
    audit_expect(getenv('AUDIT_PLATFORM_CHAT_NORMAL')==='1','platform chat exact normal profile required');
    foreach(['AUDIT_MEDIA_NORMAL','AUDIT_LICENCE_NORMAL','AUDIT_LEGACY_REFUND_NORMAL','AUDIT_WITHDRAWAL_QR_NORMAL']as$name)
        audit_expect(getenv($name)!=='1','platform chat cannot mix normal profiles');
    $m=audit_manifest();audit_expect(($m['guard_mode']??null)==='read-only'&&($m['isolation']??null)==='ISO-FULL'
        &&($m['run_id']??null)===getenv('AUDIT_RUN_ID')&&($m['commit_sha']??null)===getenv('AUDIT_SOURCE_SHA')
        &&($m['project']??null)==='tianli-audit-'.getenv('AUDIT_RUN_ID'),'platform chat registered source run required');
    audit_guard_assert();return $m;
}
function m4pc_profile_mount():void
{
    $m=m4pc_profile_guard();$project=$m['project'];$volume=$project.'_platform-chat-private';
    audit_expect(in_array($volume,$m['volumes']??[],true)&&isset($m['volume_identities'][$volume]),'registered platform chat private volume required');
    $id=$m['services']['audit-app']['id']??null;$matches=[];
    foreach(file(audit_run_dir().'/containers.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)as$line){
        $x=json_decode($line,true,512,JSON_THROW_ON_ERROR);if(($x['id']??null)!==$id)continue;
        audit_expect(($x['labels']['com.docker.compose.project']??null)===$project
            &&($x['labels']['tianli.audit.run']??null)===getenv('AUDIT_RUN_ID')
            &&($x['read_only']??null)===true&&($x['privileged']??null)===false,'registered platform chat application required');
        foreach($x['mounts']??[]as$mount)if(($mount['Destination']??null)===M4PC_PRIVATE_ROOT)$matches[]=$mount;
    }
    audit_expect(count($matches)===1&&($matches[0]['Type']??null)==='volume'&&($matches[0]['Name']??null)===$volume
        &&($matches[0]['RW']??null)===true,'exact real platform chat private mount required');
}
function m4pc_profile_self():void
{
    $m=m4pc_profile_guard();$s=$m['services']['audit-app']??[];$id=$s['id']??'';$address=$s['address']??'';
    audit_expect(is_string($id)&&preg_match('/\A[a-f0-9]{64}\z/D',$id)===1&&gethostname()===substr($id,0,12)
        &&is_string($address)&&gethostbyname(gethostname())===$address&&gethostbyname('audit-app')===$address,
        'actual platform chat app container required');m4pc_profile_mount();
}
function m4pc_profile_options(array $options):void
{
    audit_expect(array_keys($options)===['CACHE_PREFIX','PRIVATE_WRITES']&&is_string($options['CACHE_PREFIX'])
        &&preg_match('/\Aaudit:platform-chat:'.preg_quote((string)getenv('AUDIT_RUN_ID'),'/').':[a-f0-9]{16}:\z/D',$options['CACHE_PREFIX'])===1
        &&in_array($options['PRIVATE_WRITES'],['true','false'],true),'exact platform chat startup options required');
}
/** Same real App configuration in HTTP and original Chat/Channel; no message/notification stub. */
function m4pc_profile_app(string $prefix,bool $writes):\think\App
{
    m4pc_profile_options(['CACHE_PREFIX'=>$prefix,'PRIVATE_WRITES'=>$writes?'true':'false']);m4pc_profile_self();
    require_once audit_repo_dir().'/crmeb/vendor/autoload.php';require_once audit_repo_dir().'/crmeb/vendor/topthink/framework/src/helper.php';
    $c=audit_config();$app=new \think\App(audit_repo_dir().'/crmeb/');
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$c['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$c['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis',
        'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$c['redis_password'],'REDIS_SELECT'=>0,
        'CACHE_CACHE_PREFIX'=>$prefix,'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$c['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$c['auth']['stamp_key'],'AUTH_ISSUER'=>$c['auth']['issuer']]);
    $app->initialize();$database=$app->config->get('database.connections.mysql');
    audit_expect(is_array($database),'actual default mysql configuration required');
    $database['charset']='utf8mb4';$database['debug']=false;$database['break_reconnect']=false;$database['trigger_sql']=false;
    $app->config->set(['connections'=>['mysql'=>$database]],'database');
    $marketplace=$app->config->get('marketplace');audit_expect(is_array($marketplace),'actual marketplace configuration required');
    $marketplace['platform_chat']=['private_root'=>M4PC_PRIVATE_ROOT,'private_writes_enabled'=>$writes];$app->config->set($marketplace,'marketplace');
    audit_expect($app->config->get('workerman.channel.ip')==='127.0.0.1'&&$app->config->get('workerman.channel.port')===40003,
        'original local Channel endpoint must remain');return $app;
}
function m4pc_profile_ports():array
{
    $ports=[];foreach(audit_app_tcp_rows()as$row)if($row['state']==='0A'&&in_array($row['local_port'],[8000,40001,40002,40003],true))$ports[]=$row['local_port'];
    $ports=array_values(array_unique($ports));sort($ports,SORT_NUMERIC);return $ports;
}
function m4pc_profile_process_uid(int $pid):array
{
    $raw=@file_get_contents('/proc/'.$pid.'/status');audit_expect(is_string($raw)&&preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)$/m',$raw,$m)===1,'actual own process UID tuple required');
    $uids=array_map('intval',array_slice($m,1));audit_expect($uids===array_fill(0,4,posix_geteuid()),'foreign process UID rejected');return $uids;
}
function m4pc_profile_observe(array $group,array $supervisor):array
{
    $members=audit_app_group_members($group,$supervisor);$inodes=[];
    foreach(audit_app_tcp_rows()as$row)if($row['state']==='0A'&&in_array($row['local_port'],[8000,40001,40002,40003],true))$inodes[$row['inode']]=$row['local_port'];
    $ports=[];foreach($members as&$member){$before=audit_app_proc_stat($member['pid']);$uids=m4pc_profile_process_uid($member['pid']);$owned=[];
        audit_expect($before!==null&&$before['starttime']===$member['starttime']&&$before['pgid']===$group['pgid']&&$before['sid']===$group['sid'],'same platform chat group member required');
        foreach(glob('/proc/'.$member['pid'].'/fd/*')?:[]as$fd){$target=@readlink($fd);if(is_string($target)&&preg_match('/\Asocket:\[(\d+)\]\z/D',$target,$m)===1&&isset($inodes[$m[1]]))$owned[]=$inodes[$m[1]];}
        $after=audit_app_proc_stat($member['pid']);audit_expect($after!==null&&$after['pid']===$before['pid']&&$after['starttime']===$before['starttime']
            &&$after['pgid']===$before['pgid']&&$after['sid']===$before['sid']&&m4pc_profile_process_uid($member['pid'])===$uids,'member identity changed during socket ownership observation');
        $member['listener_ports']=array_values(array_unique($owned));sort($member['listener_ports'],SORT_NUMERIC);$ports=array_merge($ports,$owned);
    }unset($member);$ports=array_values(array_unique($ports));sort($ports,SORT_NUMERIC);
    audit_expect($ports===m4pc_profile_ports()&&!in_array(40001,$ports,true),'all HTTP Chat Channel listeners must belong to the registered group');
    return ['group'=>$group,'members'=>$members,'listener_ports'=>$ports,'force_signal_sent'=>false];
}
/** One signal attempt for this original handle/generation. Failed observation retains the handle. */
function m4pc_profile_raw(string $path):array
{
    clearstatcache(true,$path);$before=lstat($path);audit_expect(is_array($before)&&!is_link($path)&&($before['mode']&0170000)===0100000&&$before['nlink']===1,'regular original raw stream required');
    $h=fopen($path,'rb');audit_expect(is_resource($h),'actual raw stream handle required');$hash=hash_init('sha256');$count=0;
    try{$opened=fstat($h);foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)audit_expect($opened[$key]===$before[$key],'same named raw stream handle required');
        while(!feof($h)){$part=fread($h,65536);audit_expect(is_string($part),'complete original raw stream required');$count+=strlen($part);hash_update($hash,$part);}
        $after=fstat($h);clearstatcache(true,$path);$named=lstat($path);foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)audit_expect($after[$key]===$before[$key]&&$named[$key]===$before[$key],'raw stream changed during full read');
        audit_expect($count===$before['size'],'raw stream full EOF required');return ['bytes'=>$count,'sha256'=>hash_final($hash)];
    }finally{audit_expect(fclose($h),'raw stream handle closure required');}
}
function m4pc_profile_children(array $meta):array
{
    $out=[];foreach(['http','chat']as$role){$base=audit_run_dir().'/evidence/platform-chat-'.$meta['generation'].'-'.$role;
        foreach(['.created.json','.terminal.json']as$suffix){m4pc_profile_raw($base.$suffix);}
        $created=json_decode(file_get_contents($base.'.created.json'),true,32,JSON_THROW_ON_ERROR);$terminal=json_decode(file_get_contents($base.'.terminal.json'),true,32,JSON_THROW_ON_ERROR);
        $argv=$role==='http'?[PHP_BINARY,'-S','0.0.0.0:8000','-t',audit_repo_dir().'/crmeb/public',__FILE__]:[PHP_BINARY,__FILE__,'__chat'];
        audit_expect(($created['role']??null)===$role&&($terminal['role']??null)===$role&&is_int($created['pid']??null)&&$created['pid']>0
            &&($terminal['pid']??null)===$created['pid']&&($created['argv']??null)===$argv&&($terminal['argv']??null)===$argv
            &&($created['group']['pid']??null)===$created['pid']&&($created['group']['pgid']??null)===$meta['group']['pgid']
            &&($created['group']['sid']??null)===$meta['group']['sid']&&($terminal['term_received']??null)===true,'original same-group child receipts required');
        $native=$terminal['native_exit']??null;$wait=$terminal['wait_returncode']??null;
        audit_expect(is_int($native)&&is_int($wait)&&($wait===$native||$wait===-1)
            &&(($native===0&&($terminal['signaled']??null)===false)||($native===-1&&($terminal['signaled']??null)===true&&($terminal['termsig']??null)===SIGTERM)),
            'child actual native normal or original TERM signal terminal required');
        foreach(['stdout','stderr']as$name)audit_expect(($terminal['streams'][$name]??null)===m4pc_profile_raw($base.'.'.$name),'complete child raw receipt mismatch');$out[$role]=$terminal;
    }return $out;
}
function m4pc_profile_stop(&$process,?array &$group,array $supervisor,bool &$termSent,?array $meta,?array &$firstTerminal):array
{
    if($group===null&&is_resource($process)&&proc_get_status($process)['running'])$group=audit_app_register_group($process,$supervisor);
    $stopped=$group;$remaining=[];$leaderReceipt=null;
    if($group!==null){audit_expect(is_resource($process)&&is_array($meta)&&$meta['pid']===$group['leader_pid']&&$meta['group']===$group,'same original combination handle and receipt required');
        $remaining=audit_app_group_members($group,$supervisor);foreach($remaining as$member)m4pc_profile_process_uid($member['pid']);
        if($remaining!==[]&&!$termSent){$termSent=true;if(!posix_kill(-$group['pgid'],SIGTERM))audit_expect(audit_app_group_members($group,$supervisor)===[],'TERM result unknown; retain generation');}
        $deadline=microtime(true)+8.0;do{
            $native=proc_get_status($process);audit_expect($native['pid']===$meta['pid'],'same original combination PID required');if(!$native['running']&&$firstTerminal===null)$firstTerminal=$native;
            $remaining=audit_app_group_members($group,$supervisor);if($remaining===[]&&m4pc_profile_ports()===[]&&$firstTerminal!==null)break;usleep(20000);
        }while(microtime(true)<$deadline);
        audit_expect($remaining===[]&&m4pc_profile_ports()===[]&&is_array($firstTerminal)&&$firstTerminal['pid']===$meta['pid']
            &&$firstTerminal['running']===false&&$firstTerminal['exitcode']===0&&$firstTerminal['signaled']===false,
            'original combination natural terminal not successful; retain failed generation');
        $children=m4pc_profile_children($meta);$streams=[];foreach(['stdout','stderr']as$name)$streams[$name]=m4pc_profile_raw($meta['base'].'.'.$name);
        $wait=proc_close($process);$process=null;$leaderReceipt=['pid'=>$meta['pid'],'argv'=>$meta['argv'],'native_exit'=>$firstTerminal['exitcode'],
            'wait_returncode'=>$wait,'signaled'=>$firstTerminal['signaled'],'termsig'=>$firstTerminal['termsig'],'streams'=>$streams,'children'=>$children];
        audit_write_json($meta['base'].'.terminal.json',$leaderReceipt);
        audit_expect(is_int($wait)&&in_array($wait,[0,-1],true),'actual combination handle wait mismatch');
        m4pc_profile_raw($meta['base'].'.created.json');audit_expect(json_decode(file_get_contents($meta['base'].'.created.json'),true,32,JSON_THROW_ON_ERROR)===$meta,'original combination creation receipt mismatch');
        m4pc_profile_raw($meta['base'].'.terminal.json');audit_expect(json_decode(file_get_contents($meta['base'].'.terminal.json'),true,64,JSON_THROW_ON_ERROR)===$leaderReceipt,'full combination terminal receipt mismatch');
    }
    audit_expect($remaining===[]&&m4pc_profile_ports()===[],'TERM closure incomplete; no retry or escalation');$group=null;
    return ['stopped_group'=>$stopped,'members'=>[],'remaining_members'=>[],'listener_ports'=>[],'listener_open'=>false,'listener_closed'=>true,
        'stop_mode'=>'term-only','term_sent'=>$termSent,'force_signal_sent'=>false,'combination_terminal'=>$leaderReceipt];
}
function m4pc_profile_environment(array $options):array
{
    m4pc_profile_options($options);return ['PATH'=>(string)getenv('PATH'),'AUDIT_ISOLATED'=>'1','AUDIT_PLATFORM_CHAT_NORMAL'=>'1',
        'AUDIT_RUN_ID'=>(string)getenv('AUDIT_RUN_ID'),'AUDIT_RUN_DIR'=>audit_run_dir(),'AUDIT_REPO_DIR'=>audit_repo_dir(),
        'AUDIT_PROJECT'=>(string)getenv('AUDIT_PROJECT'),'AUDIT_SOURCE_SHA'=>(string)getenv('AUDIT_SOURCE_SHA'),'AUDIT_GUARD_MODE'=>'read-only',
        'M4PC_CACHE_PREFIX'=>$options['CACHE_PREFIX'],'M4PC_PRIVATE_WRITES'=>$options['PRIVATE_WRITES']];
}
function m4pc_profile_serve():never
{
    m4pc_profile_self();$supervisor=audit_app_proc_stat(getmypid());audit_expect($supervisor!==null,'real supervisor required');
    $process=null;$group=null;$termSent=false;$generation='';$revision='';$shutdown=false;$stopBlocked=false;$meta=null;$firstTerminal=null;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$shutdown):void{$shutdown=true;});
    while(!$shutdown){$control=audit_read_control_json(audit_run_dir().'/app-control.json');
        if(($control['run_id']??null)===getenv('AUDIT_RUN_ID')&&($control['revision']??'')!==$revision){
            $revision=$control['revision']??'';$action=$control['action']??'';
            try{
                audit_expect(is_string($revision)&&preg_match('/\A[a-f0-9]{16}\z/D',$revision)===1&&in_array($action,['start','stop','observe'],true),'fixed control operation required');
                if($action==='observe'){audit_expect($group!==null&&$generation!==''&&($control['overrides']??null)===[],'current generation required');$facts=m4pc_profile_observe($group,$supervisor);}
                elseif($action==='stop'){audit_expect(!$stopBlocked,'prior incomplete stop remains blocked');try{$facts=m4pc_profile_stop($process,$group,$supervisor,$termSent,$meta,$firstTerminal);}catch(Throwable $e){$stopBlocked=true;throw $e;}$generation='';$termSent=false;}
                else{
                    audit_expect($group===null&&!is_resource($process)&&$generation===''&&!$termSent,'no restart or existing generation permitted');
                    $options=$control['overrides']??null;audit_expect(!$stopBlocked&&is_array($options),'exact unblocked start options required');$env=m4pc_profile_environment($options);$env['M4PC_GENERATION']=$revision;
                    $base=audit_run_dir().'/evidence/platform-chat-'.$revision.'-combination';foreach(['.stdout','.stderr','.created.json','.terminal.json']as$suffix)audit_expect(!file_exists($base.$suffix)&&!is_link($base.$suffix),'fresh combination receipts only');$command=['setsid',PHP_BINARY,__FILE__,'__runtime'];
                    $process=proc_open($command,[['file','/dev/null','r'],['file',audit_run_dir().'/evidence/platform-chat-'.$revision.'-combination.stdout','xb'],['file',audit_run_dir().'/evidence/platform-chat-'.$revision.'-combination.stderr','xb']],$pipes,audit_repo_dir().'/crmeb',$env);
                    audit_expect(is_resource($process),'actual combination process required');$group=audit_app_register_group($process,$supervisor);$generation=$revision;$firstTerminal=null;
                    $meta=['generation'=>$revision,'pid'=>$group['leader_pid'],'argv'=>$command,'group'=>$group,'base'=>$base];audit_write_json($base.'.created.json',$meta);
                    $deadline=microtime(true)+12;do{$facts=m4pc_profile_observe($group,$supervisor);if($facts['listener_ports']===[8000,40002,40003])break;usleep(20000);}while(microtime(true)<$deadline);
                    audit_expect($facts['listener_ports']===[8000,40002,40003],'actual three listeners not ready');
                }
                audit_write_json(audit_run_dir().'/app-status.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,'status'=>$action,'generation_revision'=>$generation]+$facts);
            }catch(Throwable $e){audit_write_json(audit_run_dir().'/app-status.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,
                'status'=>'failed','generation_revision'=>$generation,'error_class'=>get_class($e),'force_signal_sent'=>false,'retained'=>true]);}
        }usleep(50000);
    }
    if($stopBlocked){while(true){usleep(100000);}}m4pc_profile_stop($process,$group,$supervisor,$termSent,$meta,$firstTerminal);exit(0);
}
/** Child receipts are private: command/real PID/wait and both original streams, never credential environment. */
function m4pc_profile_child(string $label,array $argv,array $env):array
{
    audit_expect(in_array($label,['http','chat'],true),'fixed child role required');$generation=(string)getenv('M4PC_GENERATION');audit_expect(preg_match('/\A[a-f0-9]{16}\z/D',$generation)===1,'exact registered generation required');$base=audit_run_dir().'/evidence/platform-chat-'.$generation.'-'.$label;
    foreach(['.created.json','.terminal.json','.stdout','.stderr']as$suffix)audit_expect(!file_exists($base.$suffix)&&!is_link($base.$suffix),'new child evidence only');
    $process=proc_open($argv,[['file','/dev/null','r'],['file',$base.'.stdout','xb'],['file',$base.'.stderr','xb']],$pipes,audit_repo_dir().'/crmeb',$env);
    audit_expect(is_resource($process),'actual child handle required');$status=proc_get_status($process);audit_expect(is_int($status['pid'])&&$status['pid']>0,'actual child PID required');
    audit_write_json($base.'.created.json',['role'=>$label,'pid'=>$status['pid'],'argv'=>$argv,'group'=>audit_app_proc_stat($status['pid'])]);
    return ['role'=>$label,'process'=>$process,'pid'=>$status['pid'],'argv'=>$argv,'base'=>$base];
}
function m4pc_profile_runtime():never
{
    m4pc_profile_self();$options=['CACHE_PREFIX'=>(string)getenv('M4PC_CACHE_PREFIX'),'PRIVATE_WRITES'=>(string)getenv('M4PC_PRIVATE_WRITES')];$env=m4pc_profile_environment($options);$generation=(string)getenv('M4PC_GENERATION');audit_expect(preg_match('/\A[a-f0-9]{16}\z/D',$generation)===1,'exact runtime generation required');$env['M4PC_GENERATION']=$generation;
    $children=[];$term=false;pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$term):void{$term=true;});
    $children[]=m4pc_profile_child('http',[PHP_BINARY,'-S','0.0.0.0:8000','-t',audit_repo_dir().'/crmeb/public',__FILE__],$env+['M4PC_HTTP'=>'1']);
    $children[]=m4pc_profile_child('chat',[PHP_BINARY,__FILE__,'__chat'],$env);$failed=false;
    while(true){$live=0;foreach($children as&$child){if(isset($child['terminal']))continue;$s=proc_get_status($child['process']);
            audit_expect($s['pid']===$child['pid'],'same original child PID required');if($s['running']){$live++;continue;}
            $native=$s['exitcode'];$wait=proc_close($child['process']);$child['terminal']=true;
            audit_expect(is_int($native)&&is_int($wait)&&($wait===$native||$wait===-1),'same original child terminal required');
            $streams=[];foreach(['stdout','stderr']as$name)$streams[$name]=m4pc_profile_raw($child['base'].'.'.$name);
            audit_write_json($child['base'].'.terminal.json',['role'=>$child['role'],'pid'=>$child['pid'],'argv'=>$child['argv'],'native_exit'=>$native,'wait_returncode'=>$wait,'signaled'=>$s['signaled'],'termsig'=>$s['termsig'],'streams'=>$streams,'term_received'=>$term]);
            if(!$term||!($native===0||($native===-1&&$s['signaled']===true&&$s['termsig']===SIGTERM)))$failed=true;
        }unset($child);if($live===0)break;usleep(20000);}
    exit($failed?2:0);
}
function m4pc_profile_chat():never
{
    $app=m4pc_profile_app((string)getenv('M4PC_CACHE_PREFIX'),getenv('M4PC_PRIVATE_WRITES')==='true');
    // The original production callbacks and Channel server own all actual messages and timers.
    \Workerman\Worker::$daemonize=false;\Workerman\Worker::$pidFile=$app->getRuntimePath().'platform-chat.pid';
    \Workerman\Worker::$logFile=$app->getRuntimePath().'platform-chat-workerman.log';\Workerman\Worker::$stdoutFile=$app->getRuntimePath().'platform-chat-stdout.log';
    $log=\Workerman\Worker::$logFile;if(!file_exists($log)){audit_expect(!is_link($log),'new own Workerman log only');$h=fopen($log,'xb');audit_expect(is_resource($h)&&fflush($h)&&fclose($h)&&chmod($log,0600),'own empty Workerman log preparation required');}
    clearstatcache(true,$log);$logStat=lstat($log);audit_expect(is_array($logStat)&&!is_link($log)&&($logStat['mode']&0170000)===0100000&&($logStat['mode']&07777)===0600&&$logStat['uid']===posix_geteuid()&&$logStat['nlink']===1,'own Workerman log identity required');
    $actual=(new ReflectionClass(\Workerman\Events\Select::class))->getFileName();
    audit_expect(is_string($actual)&&realpath($actual)===realpath(audit_repo_dir().'/crmeb/crmeb/compat/workerman/Events/Select.php'),'original compatibility event loop required');
    $channel=new \Channel\Server('0.0.0.0',40003);$worker=new \Workerman\Worker('websocket://0.0.0.0:40002');$worker->name='AuditPlatformChatOrdinary';$worker->count=1;
    $service=new \crmeb\services\workerman\chat\ChatService($worker);$worker->onConnect=[$service,'onConnect'];$worker->onMessage=[$service,'onMessage'];
    $worker->onWorkerStart=[$service,'onWorkerStart'];$worker->onClose=[$service,'onClose'];
    global $argv;$argv=[__FILE__,'start'];$_SERVER['argv']=$argv;\Workerman\Worker::runAll();exit(0);
}
function m4pc_profile_http():void
{
    $app=m4pc_profile_app((string)getenv('M4PC_CACHE_PREFIX'),getenv('M4PC_PRIVATE_WRITES')==='true');
    $http=$app->http;$response=$http->run();$response->send();$http->end($response);
}
if(PHP_SAPI==='cli-server'&&getenv('M4PC_HTTP')==='1'){
    umask(0077);m4pc_profile_self();
    // The two fixed pre-existing public media objects stay PHP's actual static-file responses; private routes always run the real App.
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'&&in_array($_SERVER['REQUEST_URI']??'',['/statics/system_images/admin_head_pic.png','/admin/system_static/media/notice.367f95a9.mp3'],true)){
        $file=audit_repo_dir().'/crmeb/public'.$_SERVER['REQUEST_URI'];audit_expect(is_file($file)&&!is_link($file)&&realpath($file)===$file,'fixed actual public image required');return false;
    }
    m4pc_profile_http();
}
elseif(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    umask(0077);try{match($argv[1]??''){'__serve'=>m4pc_profile_serve(),'__runtime'=>m4pc_profile_runtime(),'__chat'=>m4pc_profile_chat(),default=>throw new RuntimeException('unknown platform chat profile mode')};}
    catch(Throwable $e){fwrite(STDERR,json_encode(['class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()],JSON_THROW_ON_ERROR)."\n");exit(2);}
}
