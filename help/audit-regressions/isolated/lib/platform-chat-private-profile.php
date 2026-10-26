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
function m4pc_profile_observe(array $group,array $supervisor,?array &$startup=null):array
{
    if($startup!==null)$startup['stage']='observe_group';
    $members=audit_app_group_members($group,$supervisor);$inodes=[];
    if($startup!==null){$startup['facts']['member_count']=count($members);$startup['stage']='observe_tcp_first';}
    foreach(audit_app_tcp_rows()as$row)if($row['state']==='0A'&&in_array($row['local_port'],[8000,40001,40002,40003],true))$inodes[$row['inode']]=$row['local_port'];
    $firstPorts=array_values(array_unique(array_values($inodes)));sort($firstPorts,SORT_NUMERIC);
    if($startup!==null)$startup['facts']['first_listener_ports']=$firstPorts;
    $ports=[];foreach($members as&$member){
        if($startup!==null){$startup['stage']='observe_member_before';$startup['facts']['member_pid']=$member['pid'];
            foreach(['member_before_present','member_euid','member_owned_count','member_after_present']as$key)$startup['facts'][$key]=null;}
        $before=audit_app_proc_stat($member['pid']);
        if($startup!==null){$startup['facts']['member_before_present']=(int)($before!==null);$startup['stage']='observe_member_uid';}
        $uids=m4pc_profile_process_uid($member['pid']);$owned=[];
        if($startup!==null){$startup['facts']['member_euid']=$uids[1];$startup['stage']='observe_member_identity';}
        audit_expect($before!==null&&$before['starttime']===$member['starttime']&&$before['pgid']===$group['pgid']&&$before['sid']===$group['sid'],'same platform chat group member required');
        if($startup!==null)$startup['stage']='observe_member_fd';
        foreach(glob('/proc/'.$member['pid'].'/fd/*')?:[]as$fd){$target=@readlink($fd);if(is_string($target)&&preg_match('/\Asocket:\[(\d+)\]\z/D',$target,$m)===1&&isset($inodes[$m[1]]))$owned[]=$inodes[$m[1]];}
        if($startup!==null){$startup['facts']['member_owned_count']=count($owned);$startup['stage']='observe_member_after';}
        $after=audit_app_proc_stat($member['pid']);
        if($startup!==null)$startup['facts']['member_after_present']=(int)($after!==null);
        audit_expect($after!==null&&$after['pid']===$before['pid']&&$after['starttime']===$before['starttime']
            &&$after['pgid']===$before['pgid']&&$after['sid']===$before['sid']&&m4pc_profile_process_uid($member['pid'])===$uids,'member identity changed during socket ownership observation');
        $member['listener_ports']=array_values(array_unique($owned));sort($member['listener_ports'],SORT_NUMERIC);$ports=array_merge($ports,$owned);
    }unset($member);$ports=array_values(array_unique($ports));sort($ports,SORT_NUMERIC);
    if($startup!==null){$startup['stage']='observe_ports';$startup['facts']['owned_listener_ports']=$ports;}
    $globalPorts=m4pc_profile_ports();
    if($startup!==null)$startup['facts']['last_listener_ports']=$globalPorts;
    audit_expect(!in_array(40001,$firstPorts,true)&&!in_array(40001,$ports,true)&&!in_array(40001,$globalPorts,true),
        'forbidden notice listener in platform chat observation');
    if($startup!==null&&$firstPorts!==$globalPorts){
        return ['group'=>$group,'members'=>$members,'listener_ports'=>$ports,'force_signal_sent'=>false,'startup_pending'=>true];
    }
    audit_expect($ports===$globalPorts&&!in_array(40001,$ports,true),'all HTTP Chat Channel listeners must belong to the registered group');
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
    $m=m4pc_profile_guard();m4pc_profile_mount();$id=$m['services']['audit-app']['id']??'';
    audit_expect(is_string($id)&&preg_match('/\A[a-f0-9]{64}\z/D',$id)===1&&gethostname()===substr($id,0,12),
        'registered platform chat passive supervisor container required');
    $supervisor=audit_app_proc_stat(getmypid());audit_expect($supervisor!==null,'real supervisor required');
    $process=null;$group=null;$termSent=false;$generation='';$revision='';$shutdown=false;$stopBlocked=false;$meta=null;$firstTerminal=null;$startup=null;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$shutdown):void{$shutdown=true;});
    // Fixed safe projection only; diagnostic I/O must never replace the original failed ACK or TERM.
    $firstFailure=static function(array $context,?int $ownedLine,string $revision):string{
        $handle=null;
        try{
            if(preg_match('/\A[a-f0-9]{16}\z/D',$revision)!==1)return 'NOT_WRITTEN_REVISION';
            $reasons=[
                'start_self'=>'START_SELF_GUARD_FAILED','start_control'=>'START_CONTROL_GUARD_FAILED',
                'start_generation'=>'START_GENERATION_GUARD_FAILED','start_options'=>'START_OPTIONS_GUARD_FAILED',
                'start_receipts'=>'START_RECEIPT_GUARD_FAILED','start_process'=>'START_PROCESS_FAILED',
                'start_group'=>'START_GROUP_FAILED','start_created'=>'START_CREATED_RECEIPT_FAILED',
                'start_observe'=>'START_OBSERVE_FAILED','observe_group'=>'OBSERVE_GROUP_FAILED',
                'observe_tcp_first'=>'OBSERVE_FIRST_TCP_FAILED','observe_member_before'=>'OBSERVE_MEMBER_BEFORE_FAILED',
                'observe_member_uid'=>'OBSERVE_MEMBER_UID_FAILED','observe_member_identity'=>'OBSERVE_MEMBER_IDENTITY_FAILED',
                'observe_member_fd'=>'OBSERVE_MEMBER_FD_FAILED','observe_member_after'=>'OBSERVE_MEMBER_AFTER_FAILED',
                'observe_ports'=>'OBSERVE_PORT_OWNERSHIP_FAILED','start_child_receipt'=>'START_CHILD_RECEIPT_FAILED',
                'start_role_matrix'=>'START_ROLE_MATRIX_FAILED','start_deadline'=>'START_ROLE_DEADLINE_FAILED'];
            $stage=is_string($context['stage']??null)&&isset($reasons[$context['stage']])?$context['stage']:'unknown';
            $observed=is_array($context['facts']??null)?$context['facts']:[];$facts=[];
            foreach(['poll_index','leader_pid','pgid','sid','member_count','member_pid','member_before_present','member_euid',
                'member_owned_count','member_after_present','child_created_count','child_role_index','http_role_present',
                'master_role_present','channel_worker_count','chat_worker_count']as$key){
                $value=$observed[$key]??null;$facts[$key]=is_int($value)&&$value>=0?$value:null;
            }
            foreach(['first_listener_ports','owned_listener_ports','last_listener_ports']as$key){
                $value=$observed[$key]??null;$valid=is_array($value)&&array_is_list($value)&&count($value)<=4;
                if($valid)foreach($value as$port)if(!is_int($port)||!in_array($port,[8000,40001,40002,40003],true)){$valid=false;break;}
                $facts[$key]=$valid?$value:null;
            }
            $record=['schema'=>1,'scope'=>'PLATFORM_CHAT_FIRST_START_FAILURE_SAFE_FACTS','revision'=>$revision,
                'stage'=>$stage,'reason_code'=>$reasons[$stage]??'UNKNOWN_START_FAILURE',
                'source'=>'help/audit-regressions/isolated/lib/platform-chat-private-profile.php',
                'source_line'=>is_int($ownedLine)&&$ownedLine>0?$ownedLine:null,'facts'=>$facts];
            $raw=json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(strlen($raw)>65536)return 'NOT_WRITTEN_SIZE';
            $path=audit_run_dir().'/evidence/platform-chat-'.$revision.'-start-failure.json';
            $handle=@fopen($path,'xb');if(!is_resource($handle))return 'NOT_WRITTEN_CREATE';
            if(!@chmod($path,0600))return 'NOT_WRITTEN_IO';
            $offset=0;$length=strlen($raw);while($offset<$length){$written=@fwrite($handle,substr($raw,$offset));
                if(!is_int($written)||$written<=0)return 'NOT_WRITTEN_IO';$offset+=$written;}
            if(!@fflush($handle))return 'NOT_WRITTEN_IO';$closed=@fclose($handle);$handle=null;
            return $closed?'WRITTEN':'NOT_WRITTEN_IO';
        }catch(Throwable $diagnosticFailure){return 'NOT_WRITTEN_IO';}
        finally{if(is_resource($handle))try{@fclose($handle);}catch(Throwable $diagnosticCloseFailure){}}
    };
    while(!$shutdown){$control=audit_read_control_json(audit_run_dir().'/app-control.json');
        if(($control['run_id']??null)===getenv('AUDIT_RUN_ID')&&($control['revision']??'')!==$revision){
            $revision=$control['revision']??'';$action=$control['action']??'';
            $startup=$action==='start'?['stage'=>'start_self','facts'=>['poll_index'=>0]]:null;
            try{
                m4pc_profile_self();
                if($startup!==null)$startup['stage']='start_control';
                audit_expect(is_string($revision)&&preg_match('/\A[a-f0-9]{16}\z/D',$revision)===1&&in_array($action,['start','stop','observe'],true),'fixed control operation required');
                if($action==='observe'){audit_expect($group!==null&&$generation!==''&&($control['overrides']??null)===[],'current generation required');$facts=m4pc_profile_observe($group,$supervisor);}
                elseif($action==='stop'){audit_expect(!$stopBlocked,'prior incomplete stop remains blocked');try{$facts=m4pc_profile_stop($process,$group,$supervisor,$termSent,$meta,$firstTerminal);}catch(Throwable $e){$stopBlocked=true;throw $e;}$generation='';$termSent=false;}
                else{
                    $startup['stage']='start_generation';
                    audit_expect($group===null&&!is_resource($process)&&$generation===''&&!$termSent,'no restart or existing generation permitted');
                    if($startup!==null)$startup['stage']='start_options';
                    $options=$control['overrides']??null;audit_expect(!$stopBlocked&&is_array($options),'exact unblocked start options required');$env=m4pc_profile_environment($options);$env['M4PC_GENERATION']=$revision;
                    if($startup!==null)$startup['stage']='start_receipts';
                    $base=audit_run_dir().'/evidence/platform-chat-'.$revision.'-combination';foreach(['.stdout','.stderr','.created.json','.terminal.json']as$suffix)audit_expect(!file_exists($base.$suffix)&&!is_link($base.$suffix),'fresh combination receipts only');$command=['setsid',PHP_BINARY,__FILE__,'__runtime'];
                    if($startup!==null)$startup['stage']='start_process';
                    $process=proc_open($command,[['file','/dev/null','r'],['file',audit_run_dir().'/evidence/platform-chat-'.$revision.'-combination.stdout','xb'],['file',audit_run_dir().'/evidence/platform-chat-'.$revision.'-combination.stderr','xb']],$pipes,audit_repo_dir().'/crmeb',$env);
                    audit_expect(is_resource($process),'actual combination process required');$startup['stage']='start_group';$group=audit_app_register_group($process,$supervisor);$generation=$revision;$firstTerminal=null;
                    if($startup!==null)$startup['stage']='start_created';
                    $meta=['generation'=>$revision,'pid'=>$group['leader_pid'],'argv'=>$command,'group'=>$group,'base'=>$base];audit_write_json($base.'.created.json',$meta);
                    $deadline=microtime(true)+12;$ready=false;do{
                        $startup['stage']='start_observe';$startup['facts']=['poll_index'=>$startup['facts']['poll_index']+1,
                            'leader_pid'=>$group['leader_pid'],'pgid'=>$group['pgid'],'sid'=>$group['sid']];
                        $facts=m4pc_profile_observe($group,$supervisor,$startup);
                        if(($facts['startup_pending']??false)===true){usleep(20000);continue;}
                        $created=[];$startup['facts']['child_created_count']=0;
                        foreach(['http','chat']as$role){$startup['stage']='start_child_receipt';$startup['facts']['child_role_index']=$role==='http'?1:2;$path=audit_run_dir().'/evidence/platform-chat-'.$revision.'-'.$role.'.created.json';
                            audit_expect(!is_link($path),'original child creation receipt cannot be a link');if(!file_exists($path))continue;
                            $pin=m4pc_profile_raw($path);$raw=file_get_contents($path);audit_expect(is_string($raw)&&strlen($raw)===$pin['bytes']&&hash('sha256',$raw)===$pin['sha256']&&m4pc_profile_raw($path)===$pin,'stable original child creation receipt required');
                            $row=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$expectedArgv=$role==='http'?[PHP_BINARY,'-S','0.0.0.0:8000','-t',audit_repo_dir().'/crmeb/public',__FILE__]:[PHP_BINARY,__FILE__,'__chat'];
                            audit_expect(is_array($row)&&($row['role']??null)===$role&&is_int($row['pid']??null)&&$row['pid']>0&&($row['argv']??null)===$expectedArgv
                                &&($row['group']['pid']??null)===$row['pid']&&($row['group']['ppid']??null)===$group['leader_pid']
                                &&($row['group']['pgid']??null)===$group['pgid']&&($row['group']['sid']??null)===$group['sid'],
                                'same original runtime child creation identity required');$created[$role]=$row;$startup['facts']['child_created_count']=count($created);
                        }
                        if($startup!==null)$startup['stage']='start_role_matrix';
                        if(count($created)===2&&$facts['listener_ports']===[8000,40002,40003]&&count($facts['members'])===5){
                            $members=[];foreach($facts['members']as$member)$members[$member['pid']]=$member;
                            $http=$members[$created['http']['pid']]??null;$master=$members[$created['chat']['pid']]??null;$leader=$members[$group['leader_pid']]??null;$roles=[];
                            foreach($facts['members']as$member)if($member['ppid']===$created['chat']['pid']){
                                if($member['listener_ports']===[40003])$roles['channel'][]=$member;elseif($member['listener_ports']===[40002])$roles['chat'][]=$member;
                            }
                            $startup['facts']['http_role_present']=(int)is_array($http);$startup['facts']['master_role_present']=(int)is_array($master);
                            $startup['facts']['channel_worker_count']=count($roles['channel']??[]);$startup['facts']['chat_worker_count']=count($roles['chat']??[]);
                            $ready=count($members)===5&&is_array($leader)&&$leader['listener_ports']===[]&&$leader['starttime']===$group['leader_starttime']
                                &&is_array($http)&&$http['ppid']===$group['leader_pid']&&$http['starttime']===$created['http']['group']['starttime']&&$http['listener_ports']===[8000]
                                &&is_array($master)&&$master['ppid']===$group['leader_pid']&&$master['starttime']===$created['chat']['group']['starttime']&&$master['listener_ports']===[40002,40003]
                                &&count($roles['channel']??[])===1&&count($roles['chat']??[])===1;
                            if($ready){$expected=[$group['leader_pid'],$http['pid'],$master['pid'],$roles['channel'][0]['pid'],$roles['chat'][0]['pid']];sort($expected,SORT_NUMERIC);$actual=array_keys($members);sort($actual,SORT_NUMERIC);$ready=$expected===$actual;}
                        }
                        if($ready)break;usleep(20000);
                    }while(microtime(true)<$deadline);
                    if($startup!==null)$startup['stage']='start_deadline';
                    audit_expect($ready,'actual complete five-process HTTP Chat Channel role matrix not ready');
                }
                audit_write_json(audit_run_dir().'/app-status.json',['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,'status'=>$action,'generation_revision'=>$generation]+$facts);
            }catch(Throwable $e){
                $diagnosticStatus=null;
                if($action==='start'&&is_array($startup)){
                    $diagnosticStatus='NOT_WRITTEN_IO';
                    try{
                        $ownedLine=null;
                        // Read only file/line from original frames; never retain/serialize a trace or inspect its arguments.
                        foreach($e->getTrace()as$frame)if(($frame['file']??null)===__FILE__&&is_int($frame['line']??null)&&$frame['line']>0){$ownedLine=$frame['line'];break;}
                        unset($frame);$diagnosticStatus=$firstFailure($startup,$ownedLine,$revision);
                    }catch(Throwable $diagnosticFailure){}
                }
                $failedStatus=['run_id'=>getenv('AUDIT_RUN_ID'),'revision'=>$revision,
                    'status'=>'failed','generation_revision'=>$generation,'error_class'=>get_class($e),'force_signal_sent'=>false,'retained'=>true];
                if($diagnosticStatus!==null)$failedStatus['startup_diagnostic_status']=$diagnosticStatus;
                audit_write_json(audit_run_dir().'/app-status.json',$failedStatus);
            }
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
    $channel=new \Channel\Server('0.0.0.0',40003);$channelWorkers=[];
    foreach(\Workerman\Worker::getAllWorkers()as$candidate)if($candidate->onMessage===[$channel,'onMessage'])$channelWorkers[]=$candidate;
    audit_expect(count($channelWorkers)===1&&$channelWorkers[0]->getSocketName()==='frame://0.0.0.0:40003'
        &&$channelWorkers[0]->onClose===[$channel,'onClose']&&$channelWorkers[0]->count===1,'one original Channel Server worker required');
    $channelWorker=$channelWorkers[0];$originalStop=$channelWorker->onWorkerStop;$channelStopping=false;
    audit_expect($originalStop===null||is_callable($originalStop),'original Channel stop callback required');
    $channelWorker->onWorkerStop=static function(\Workerman\Worker $stopping)use($channelWorker,$channel,$originalStop,&$channelStopping):void{
        audit_expect($stopping===$channelWorker&&$stopping->onMessage===[$channel,'onMessage']
            &&$stopping->onClose===[$channel,'onClose'],'same original Channel worker stop required');
        if($channelStopping)return;$channelStopping=true;
        if($originalStop!==null)call_user_func($originalStop,$stopping);
        foreach($stopping->connections as$connection){
            audit_expect($connection instanceof \Workerman\Connection\TcpConnection&&$connection->worker===$stopping
                &&$connection->onClose===[$channel,'onClose']&&$connection->getLocalIp()==='127.0.0.1'
                &&$connection->getLocalPort()===40003&&$connection->getRemoteIp()==='127.0.0.1',
                'only original accepted internal Channel connections may close normally');
            $connection->close();
        }
    };
    $worker=new \Workerman\Worker('websocket://0.0.0.0:40002');$worker->name='AuditPlatformChatOrdinary';$worker->count=1;
    $service=new \crmeb\services\workerman\chat\ChatService($worker);$worker->onConnect=[$service,'onConnect'];$worker->onMessage=[$service,'onMessage'];
    $worker->onWorkerStart=[$service,'onWorkerStart'];$worker->onClose=[$service,'onClose'];
    $chatOriginalStop=$worker->onWorkerStop;$chatStopping=false;
    audit_expect($chatOriginalStop===null||is_callable($chatOriginalStop),'original Chat stop callback required');
    $worker->onWorkerStop=static function(\Workerman\Worker $stopping)use($worker,$service,$chatOriginalStop,&$chatStopping):void{
        audit_expect($stopping===$worker&&$stopping->name==='AuditPlatformChatOrdinary'&&$stopping->count===1
            &&$stopping->onConnect===[$service,'onConnect']&&$stopping->onMessage===[$service,'onMessage']
            &&$stopping->onWorkerStart===[$service,'onWorkerStart']&&$stopping->onClose===[$service,'onClose'],
            'same original Chat worker stop required');
        if($chatStopping)return;$chatStopping=true;
        $clientOriginalClose=\Channel\Client::$onClose;
        audit_expect($clientOriginalClose===null||is_callable($clientOriginalClose),'original Channel Client close callback required');
        \Channel\Client::clearTimer();
        \Channel\Client::$onClose=static function()use($clientOriginalClose):void{
            try{if($clientOriginalClose!==null)call_user_func($clientOriginalClose);}
            finally{\Channel\Client::clearTimer();}
        };
        if($chatOriginalStop!==null)call_user_func($chatOriginalStop,$stopping);
        foreach(\Workerman\Connection\TcpConnection::$connections as$connection){
            if(!$connection instanceof \Workerman\Connection\AsyncTcpConnection)continue;
            audit_expect(get_class($connection)===\Workerman\Connection\AsyncTcpConnection::class&&$connection->worker===null
                &&$connection->transport==='tcp'&&$connection->protocol==='\\Workerman\\Protocols\\Frame'
                &&$connection->getRemoteHost()==='127.0.0.1'&&$connection->getRemotePort()===40003
                &&$connection->onConnect==='Channel\Client::onRemoteConnect'&&$connection->onMessage==='Channel\Client::onRemoteMessage'
                &&$connection->onClose==='Channel\Client::onRemoteClose',
                'only original outgoing internal Channel Client may close normally');
            $connection->close();unset($connection);
        }
    };
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
