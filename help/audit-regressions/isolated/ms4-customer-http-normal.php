<?php
declare(strict_types=1);

/** Finite ordinary customer HTTP only; importing this file runs no application. */
require_once __DIR__.'/lib/harness.php';
require_once __DIR__.'/lib/ms4-customer-http-normal.php';

const M4C_ENTRY='help/audit-regressions/isolated/ms4-customer-http-normal.php';
const M4C_IMAGE='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4C_ROOTS=['crmeb/app','crmeb/crmeb','crmeb/vendor','crmeb/config'];
const M4C_EXTRA=['crmeb/composer.json','crmeb/composer.lock','crmeb/public/index.php','crmeb/public/router.php','crmeb/think',
    'help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/lib/guard.php',
    M4C_ENTRY,'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php'];
const M4C_MODE=[
    'prepare'=>['role'=>'audit-ms4-customer-prepare','proof'=>'customer-prepare','binding'=>'ms4-customer-prepare-source-binding.json',
        'binding_scope'=>'MS4_CUSTOMER_PREPARE_SOURCE','environment'=>'customer-prepare-environment.json',
        'result'=>'ms4-customer-prepare.json','scope'=>'CUSTOMER_HTTP_FIXTURE_PREPARATION_ONLY',
        'status'=>'VERIFIED_CUSTOMER_HTTP_FIXTURE_PREPARED_ONLY'],
    'verify'=>['role'=>'audit-ms4-customer-http','proof'=>'customer','binding'=>'ms4-customer-http-source-binding.json',
        'binding_scope'=>'MS4_CUSTOMER_HTTP_SOURCE','environment'=>'customer-environment.json',
        'result'=>'ms4-customer-http.json','scope'=>'NORMAL_CUSTOMER_HTTP_PERSISTENT_ONLY',
        'status'=>'VERIFIED_NORMAL_CUSTOMER_HTTP_PERSISTENT_ONLY']
];

function m4c_entry_keys(array $value,array $keys): void
{
    $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
    m4c_expect($actual===$keys,'M4C_EXACT_OBJECT_KEYS');
}
function m4c_entry_read(string $path,int $maximum=16777216): array
{
    m4c_expect(is_file($path)&&!is_link($path)&&filesize($path)>0&&filesize($path)<=$maximum,'M4C_REGULAR_BOUND_JSON');
    $value=json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    m4c_expect(is_array($value)&&!array_is_list($value),'M4C_BOUND_JSON_OBJECT');return $value;
}
function m4c_entry_publish(string $path,array $value): void
{
    m4c_expect(!file_exists($path)&&!is_link($path)&&is_dir(dirname($path)),'M4C_CREATE_ONLY_PUBLIC_RECEIPT');
    $raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $stream=fopen($path,'xb');m4c_expect(is_resource($stream),'M4C_PUBLIC_RECEIPT_OPEN');
    try{$offset=0;while($offset<strlen($raw)){$count=fwrite($stream,substr($raw,$offset));
        m4c_expect(is_int($count)&&$count>0,'M4C_PUBLIC_RECEIPT_WRITE');$offset+=$count;}
        m4c_expect(fflush($stream),'M4C_PUBLIC_RECEIPT_FLUSH');}
    finally{fclose($stream);}
    m4c_expect(chmod($path,0600),'M4C_PUBLIC_RECEIPT_MODE');clearstatcache(true,$path);
    $stat=lstat($path);m4c_expect(is_array($stat)&&($stat['mode']&0777)===0600&&$stat['uid']===posix_geteuid()
        &&file_get_contents($path)===$raw,'M4C_PUBLIC_RECEIPT_ACTUAL_READBACK');
}
function m4c_entry_inventory(string $root): array
{
    $paths=M4C_EXTRA;
    foreach(M4C_ROOTS as $directory){m4c_expect(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory),'M4C_SOURCE_ROOT');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){m4c_expect(!$file->isLink(),'M4C_SOURCE_NO_LINK');if(!$file->isFile())continue;
            $paths[]=substr($file->getPathname(),strlen($root)+1);}}
    sort($paths,SORT_STRING);m4c_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'M4C_SOURCE_UNIQUE_CLOSED_PATHS');
    return $paths;
}
function m4c_entry_full_source(array $source,bool $beforePublicCopy=false): void
{
    $rows=$source['source_files'];m4c_expect(array_is_list($rows),'M4C_SOURCE_DESCRIPTOR_LIST');$paths=[];
    foreach($rows as $row){m4c_expect(is_array($row),'M4C_SOURCE_DESCRIPTOR_OBJECT');m4c_entry_keys($row,['path','bytes','sha256']);
        m4c_expect(is_string($row['path'])&&$row['path']!==''&&$row['path'][0]!=='/'&&!str_contains($row['path'],'\\')
            &&!str_contains($row['path'],':')&&!in_array('..',explode('/',$row['path']),true)
            &&is_int($row['bytes'])&&$row['bytes']>=0&&is_string($row['sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,'M4C_CANONICAL_SOURCE_DESCRIPTOR');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);
    m4c_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),'M4C_SORTED_EXACT_SOURCE_DESCRIPTORS');
    foreach(['/source','/workspace'] as $root){m4c_expect(m4c_entry_inventory($root)===$paths,'M4C_ALL_SOURCE_FILES_CLOSED_SET');
        foreach($rows as $row){
            if($beforePublicCopy&&$root==='/workspace'&&in_array($row['path'],['crmeb/public/index.php','crmeb/public/router.php'],true))continue;
            $path=$root.'/'.$row['path'];m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'M4C_FULL_SOURCE_ACTUAL_BYTES');}}
}
/** Original raw native terminal stays raw; its identity is bound through the original created receipt. */
function m4c_entry_terminal(array $source,string $proof,string $role,array $command): array
{
    $base='/run/audit/evidence/';$created=m4c_entry_read($base.$proof.'-created.json');
    $terminal=m4c_entry_read($base.$proof.'-terminal.json');
    $keys=array_values(array_diff(array_keys($created),['run_id','source_sha','proof_name']));m4c_entry_keys($terminal,$keys);
    m4c_expect($created['run_id']===$source['run_id']&&$created['source_sha']===$source['source_sha']
        &&$created['proof_name']===$proof&&$created['status']==='created'&&$created['pid']===0
        &&is_string($created['id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$created['id'])===1
        &&$terminal['id']===$created['id']&&$terminal['status']==='exited'&&$terminal['pid']===0
        &&$terminal['exit_code']===0&&$terminal['restart_count']===0&&$terminal['image']===M4C_IMAGE
        &&$terminal['entrypoint']===['php']&&$terminal['command']===$command
        &&$terminal['labels']['tianli.audit.run']===$source['run_id']
        &&$terminal['labels']['com.docker.compose.project']===$source['context']['project']
        &&$terminal['labels']['com.docker.compose.service']===$role,'M4C_ORIGINAL_NATIVE_TERMINAL_ZERO');
    foreach(['id','image','hostname','labels','tmpfs','read_only','privileged','network_mode','command','entrypoint',
        'workdir','port_bindings','cap_drop','security_options','created_at','user','stop_signal']as$key)
        m4c_expect($terminal[$key]===$created[$key],'M4C_TERMINAL_ALL_IMMUTABLE_FIELDS');
    $canonical=static function(array $rows):array{$out=[];foreach($rows as$row){ksort($row,SORT_STRING);
        $out[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}sort($out,SORT_STRING);return $out;};
    m4c_expect($canonical($terminal['mounts'])===$canonical($created['mounts'])
        &&array_keys($terminal['networks'])===array_keys($created['networks']),'M4C_TERMINAL_NATIVE_MOUNTS_AND_NETWORK_SET');
    return $created;
}
function m4c_entry_source(string $mode): array
{
    m4c_expect(isset(M4C_MODE[$mode])&&PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],$mode]
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_ACTUAL_FINITE_NATIVE_ENTRY');
    $rule=M4C_MODE[$mode];$evidence='/run/audit/evidence/';
    $context=m4c_entry_read('/run/audit/actual-run-context.json');$freeze=m4c_entry_read('/run/audit/preparation-source-binding.json');
    $created=m4c_entry_read($evidence.$rule['proof'].'-created.json');$binding=m4c_entry_read($evidence.$rule['binding']);
    $environment=m4c_entry_read($evidence.$rule['environment']);$manifest=audit_manifest();
    $run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');$cid=$created['id']??null;
    m4c_expect(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1&&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$manifest['run_id']===$run&&$manifest['commit_sha']===$sha
        &&$manifest['project']===$context['project']&&$manifest['isolation']==='ISO-FULL'
        &&$manifest['guard_mode']==='read-only'&&$manifest['network_internal']===true,'M4C_SAME_REAL_ISOLATION_SOURCE');
    $roles=array_keys($manifest['services']);sort($roles,SORT_STRING);$wanted=[$rule['role'],'audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
    m4c_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'M4C_ONLY_THREE_ACTUAL_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files']);
    m4c_expect($binding['schema']===1&&$binding['scope']===$rule['binding_scope']&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$rule['proof'].'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['customer_source_files']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha
        &&$created['proof_name']===$rule['proof']&&$created['hostname']===gethostname()&&$created['read_only']===true
        &&$created['privileged']===false&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4C_ENTRY,$mode]&&$created['workdir']==='/workspace'
        &&$created['cap_drop']===['ALL']&&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true),
        'M4C_REAL_CREATED_PHP_ROLE');
    $labels=$created['labels'];m4c_expect($labels['tianli.audit']==='1'&&$labels['tianli.audit.run']===$run
        &&$labels['com.docker.compose.project']===$context['project']&&$labels['com.docker.compose.service']===$rule['role'],
        'M4C_ORIGINAL_NATIVE_ROLE_LABELS');
    $network=m4c_entry_read('/run/audit/network.json');
    m4c_expect($network===$context['initial_network']&&$network['Internal']===true&&$network['Id']===$context['network_id']
        &&$created['network_mode']===$network['Name']&&array_keys($created['networks'])===[$network['Name']]
        &&in_array($created['networks'][$network['Name']]['NetworkID'],['',$network['Id']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'M4C_ONE_ORIGINAL_INTERNAL_NETWORK_NO_PORT_BINDINGS');
    m4c_expect($created['ports']===null||is_array($created['ports']),'M4C_NATIVE_PORTS_OBJECT_OR_NULL');
    foreach($created['ports']??[] as $mapping)m4c_expect(in_array($mapping,[null,[]],true),'M4C_NO_PUBLISHED_NATIVE_PORT');
    if($mode==='verify')m4c_expect($created['stop_signal']==='SIGTERM'
        &&in_array('audit-app',$created['networks'][$network['Name']]['Aliases']??[],true)
        &&filter_var(gethostbyname('audit-app'),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false
        &&gethostbyname('audit-app')===gethostbyname(gethostname()),'M4C_REAL_SELF_HTTP_NETWORK_ALIAS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',$mode==='prepare']];
    $nativeMounts=[];$tmpMounts=[];
    foreach($created['mounts']as$mount){if($mount['Type']!=='tmpfs'){$nativeMounts[]=$mount;continue;}
        m4c_expect(in_array($mount['Destination'],['/tmp','/workspace/crmeb/public','/workspace/crmeb/runtime'],true)
            &&$mount['RW']===true&&!isset($tmpMounts[$mount['Destination']]),'M4C_ONLY_DECLARED_NATIVE_TMPFS_MOUNTS');
        $tmpMounts[$mount['Destination']]=true;}
    m4c_expect(count($nativeMounts)===count($binds)+count($volumes),'M4C_CLOSED_NATIVE_MOUNT_SET');
    foreach(array_merge($binds,$volumes) as $target=>$expected){$matches=array_values(array_filter($nativeMounts,static fn(array $row):bool=>$row['Destination']===$target));
        m4c_expect(count($matches)===1,'M4C_ONE_EXACT_NATIVE_MOUNT');$mount=$matches[0];
        m4c_expect($mount['RW']===$expected[1],'M4C_ORIGINAL_MOUNT_ACCESS');
        if(isset($binds[$target]))m4c_expect($mount['Type']==='bind'&&audit_canonical_host_bind_source($mount['Source'])
            ===audit_canonical_host_bind_source($expected[0]),'M4C_ORIGINAL_HOST_SOURCE_BIND');
        else m4c_expect($mount['Type']==='volume'&&$mount['Name']===$context['project'].'_'.$expected[0],'M4C_ORIGINAL_PRIVATE_VOLUME');}
    $tmpfs=$created['tmpfs'];$tmpKeys=array_keys($tmpfs);sort($tmpKeys,SORT_STRING);
    m4c_expect($tmpKeys===['/tmp','/workspace/crmeb/public','/workspace/crmeb/runtime'],'M4C_EXACT_THREE_PRIVATE_TMPFS');
    foreach($tmpfs as $options)m4c_expect(is_string($options)&&in_array('rw',explode(',',$options),true),'M4C_WRITABLE_PRIVATE_TMPFS');
    m4c_expect(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'M4C_NO_HOST_RUNTIME_CONFIGURATION');
    m4c_entry_keys($environment,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4c_expect($environment['schema']===1&&$environment['scope']==='MS4_CUSTOMER_REAL_ENVIRONMENT'
        &&$environment['run_id']===$run&&$environment['source_sha']===$sha&&$environment['container_id']===$cid
        &&$environment['freeze_sha256']===$freezeHash,'M4C_ACTUAL_ENVIRONMENT_BINDING');
    $values=$environment['environment'];m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
        'PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    m4c_expect($values['APP_ENV']==='testing'&&$values['PHP_MARKETPLACE_ENABLED']==='true'
        &&$values['PHP_MARKETPLACE_TRADE_ENABLED']==='true'&&$values['PHP_CACHE_DRIVER']==='redis'
        &&is_string($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0,'M4C_REAL_ENABLED_CONFIGURATION_VALUES');
    foreach($values as $key=>$value)m4c_expect(getenv($key)===$value,'M4C_ACTUAL_PROCESS_ENVIRONMENT');
    $native=m4c_entry_read($evidence.$rule['proof'].'-environment-native.json');
    m4c_expect($native['native_exit']===0&&$native['stderr_bytes']===0&&$native['stderr_sha256']===hash('sha256','')
        &&is_int($native['stdout_bytes'])&&$native['stdout_bytes']>0
        &&$native['stdout_sha256']===$environment['native_projection_sha256'],'M4C_REAL_NATIVE_ENVIRONMENT_PROJECTION');
    audit_guard_assert();
    $source=$binding+['mode'=>$mode,'rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment'])];
    m4c_entry_full_source($source,true);
    $bootstrap=m4c_entry_terminal($source,'files-bootstrap','audit-bootstrap',['/run/ms4-prep/ms4-initialize.php','files-bootstrap']);
    $installed=m4c_entry_read($evidence.'files-bootstrap-result.json');$installation=$installed['installation'];
    $shutdown=m4c_entry_read($evidence.'files-bootstrap-shutdown.json');$initializer=m4c_entry_read($evidence.'initializer-result.json');
    m4c_expect($installed['schema']===1&&$installed['scope']==='MS4_PREREQUISITES_ONLY'
        &&$installed['status']==='VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY'&&$installed['native_exit']===0
        &&$installed['run_id']===$run&&$installed['source_sha']===$sha&&$installed['binding']['source_sha']===$sha
        &&$installed['binding']['container_id']===$bootstrap['id']&&$installed['binding']['freeze_sha256']===$freezeHash
        &&$installed['binding']['created_sha256']===hash_file('sha256',$evidence.'files-bootstrap-created.json')
        &&$installed['actual_self_mer_id']===$source['self_operated_mer_id']
        &&$initializer['actual_self_mer_id']===$source['self_operated_mer_id']
        &&$installed['initializer_result_sha256']===hash_file('sha256',$evidence.'initializer-result.json')
        &&$installed['migration_result_sha256']===hash_file('sha256',$evidence.'ms4-migration-normal.json')
        &&$installation['account']==='audit_migrator'&&is_int($installation['checksum_rows'])&&$installation['checksum_rows']>0
        &&$installation['source_matches']===true&&$installation['version_marker_matches']===true
        &&$installation['application_account']==='audit_app'&&$installation['application_dml_only']===true
        &&$shutdown['phase']==='files-bootstrap'&&$shutdown['fatal']===false
        &&$shutdown['primary_failure']===null&&$shutdown['cleanup_failure']===null,'M4C_REAL_DEDICATED_FILES_BOOTSTRAP');
    if($mode==='verify')m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);
    return $source;
}
function m4c_entry_public_materials(): void
{
    foreach(['/workspace/crmeb/public','/workspace/crmeb/runtime'] as $directory){
        m4c_expect(is_dir($directory)&&!is_link($directory)&&count(scandir($directory))===2,'M4C_OWN_EMPTY_TMPFS');
        m4c_expect(chmod($directory,0700),'M4C_OWN_TMPFS_MODE');}
    foreach(['index.php','router.php'] as $file){$path='/workspace/crmeb/public/'.$file;$raw=file_get_contents('/source/crmeb/public/'.$file);
        m4c_expect(is_string($raw)&&file_put_contents($path,$raw,LOCK_EX)===strlen($raw)
            &&chmod($path,0600)&&file_get_contents($path)===$raw,'M4C_ACTUAL_PUBLIC_ENTRY_COPY');}
    $lock='/workspace/crmeb/public/install.lock';$value='MS4_CUSTOMER_HTTP_INSTALLED '.getenv('AUDIT_RUN_ID')."\n";
    m4c_expect(file_put_contents($lock,$value,LOCK_EX)===strlen($value)&&chmod($lock,0600)
        &&file_get_contents($lock)===$value,'M4C_OWN_PUBLIC_INSTALL_LOCK');
}
function m4c_entry_child_environment(array $source): array
{
    $config=audit_config();m4c_expect($config['database']['username']==='audit_app','M4C_CHILD_DML_CONFIGURATION');
    $env=['PATH'=>getenv('PATH'),'PHP_APP_DEBUG'=>'false','PHP_APP_APP_KEY'=>$config['signing_key'],
        'PHP_DATABASE_HOSTNAME'=>'audit-mysql','PHP_DATABASE_HOSTPORT'=>'3306','PHP_DATABASE_DATABASE'=>'audit_auth',
        'PHP_DATABASE_USERNAME'=>'audit_app','PHP_DATABASE_PASSWORD'=>$config['database']['password'],
        'PHP_DATABASE_PREFIX'=>'eb_','PHP_DATABASE_CHARSET'=>'utf8mb4','PHP_DATABASE_DEBUG'=>'false',
        'PHP_CACHE_DRIVER'=>'redis','PHP_REDIS_REDIS_HOSTNAME'=>'audit-redis','PHP_REDIS_PORT'=>'6379',
        'PHP_REDIS_REDIS_PASSWORD'=>$config['redis_password'],'PHP_CACHE_CACHE_PREFIX'=>'c:','PHP_CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
        'PHP_AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'PHP_AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],
        'PHP_AUTH_ISSUER'=>$config['auth']['issuer']];
    foreach($source['environment'] as $key=>$value){m4c_expect(getenv($key)===$value,'M4C_CHILD_REAL_SOURCE_BOUND_ENV');$env[$key]=$value;}
    return $env;
}
function m4c_entry_child_status($process): ?array
{
    if(!is_resource($process))return null;$status=proc_get_status($process);
    return array_intersect_key($status,array_flip(['pid','running','signaled','stopped','exitcode','termsig','stopsig']));
}
/** Observe the same registered group after at most one TERM; never escalate or restart. */
function m4c_entry_stop(&$process,?array &$group,array $supervisor,bool &$sent,bool &$attempted): array
{
    if($group===null&&is_resource($process)&&proc_get_status($process)['running'])$group=audit_app_register_group($process,$supervisor);
    $registered=$group;$child=m4c_entry_child_status($process);$remaining=[];
    if($group!==null){$remaining=audit_app_group_members($group,$supervisor);
        if(!$attempted&&$remaining!==[]){$attempted=true;if(posix_kill(-$group['pgid'],SIGTERM))$sent=true;
            else m4c_expect(audit_app_group_members($group,$supervisor)===[],'M4C_NORMAL_TERM_FAILED');}
        $until=microtime(true)+5.0;do{$child=m4c_entry_child_status($process);
            while(pcntl_waitpid(-$group['pgid'],$status,WNOHANG)>0){}
            $remaining=audit_app_group_members($group,$supervisor);
            if($remaining===[]&&!audit_app_listener_open())break;usleep(20000);
        }while(microtime(true)<$until);}
    $closed=$remaining===[]&&!audit_app_listener_open();$nativeClose=null;
    if($closed&&is_resource($process)){$child=m4c_entry_child_status($process);
        m4c_expect($child!==null&&!$child['running'],'M4C_DIRECT_CHILD_RUNNING');$nativeClose=proc_close($process);$process=null;$group=null;}
    return ['stopped'=>$closed,'group'=>$registered,'remaining_members'=>$remaining,'listener_open'=>audit_app_listener_open(),
        'stop_signal'=>$sent?SIGTERM:null,'term_attempted'=>$attempted,
        'native_child'=>$child,'native_proc_close'=>$nativeClose,'normal_only'=>true];
}
function m4c_entry_run(string $mode): int
{
    $GLOBALS['m4c_phase']='source';$source=m4c_entry_source($mode);$GLOBALS['m4c_entry_source']=$source;
    m4c_entry_public_materials();m4c_entry_full_source($source);$GLOBALS['m4c_phase']='app';m4c_app($mode,$source);
    $process=null;$group=null;$sent=false;$attempted=false;$childProof=null;$business=null;$failure=null;$failureMeta=null;
    $supervisor=audit_app_proc_stat(getmypid());
    m4c_expect($supervisor!==null,'M4C_REAL_SUPERVISOR');
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
    try{if($mode==='prepare'){$GLOBALS['m4c_phase']='fixture_prepare';$business=m4c_prepare_fixture($source);}
        else{$GLOBALS['m4c_phase']='http_child_start';
            $process=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],
                [['file','/dev/null','r'],['file','/workspace/crmeb/runtime/ms4-customer-http-native.log','a'],
                 ['file','/workspace/crmeb/runtime/ms4-customer-http-native.log','a']],$pipes,'/workspace/crmeb',m4c_entry_child_environment($source));
            m4c_expect(is_resource($process),'M4C_ACTUAL_HTTP_CHILD_STARTED');$group=audit_app_register_group($process,$supervisor);
            $deadline=microtime(true)+5.0;do{$facts=audit_app_process_observation($group,$supervisor);
                if($facts['members']!==[]&&$facts['listener_open'])break;usleep(10000);
            }while(microtime(true)<$deadline);
            m4c_expect($facts['members']!==[]&&$facts['listener_open'],'M4C_REAL_REGISTERED_HTTP_LISTENER');
            $GLOBALS['m4c_phase']='http_normal';$business=m4c_verify_http($source);}
        m4c_expect(empty($GLOBALS['m4c_terminate_requested']),'M4C_NOT_INTERRUPTED');
    }catch(Throwable $error){$failure=$error;$failureMeta=[$GLOBALS['m4c_phase']??null,
        $GLOBALS['m4c_failed_assertion']??null,$GLOBALS['m4c_warning']??null];}
    finally{if($mode==='verify'&&(is_resource($process)||$group!==null)){
        do{try{$childProof=m4c_entry_stop($process,$group,$supervisor,$sent,$attempted);}
            catch(Throwable $cleanupError){if($failure===null){$failure=$cleanupError;
                $failureMeta=[$GLOBALS['m4c_phase']??null,$GLOBALS['m4c_failed_assertion']??null,$GLOBALS['m4c_warning']??null];}
                usleep(20000);}
        }while($childProof===null||!$childProof['stopped']);}}
    if($failure!==null){[$GLOBALS['m4c_phase'],$GLOBALS['m4c_failed_assertion'],$GLOBALS['m4c_warning']]=$failureMeta;throw $failure;}
    $GLOBALS['m4c_phase']='final_source';audit_guard_assert();m4c_entry_full_source($source);
    $rule=$source['rule'];$result=['schema'=>1,'scope'=>$rule['scope'],'status'=>$rule['status'],
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
        'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'environment_sha256'=>$source['environment_sha256'],
        'phase'=>'final_source','native_exit'=>0,'full_source_unchanged'=>true,'business'=>$business,'child'=>$childProof,
        'assertions'=>$GLOBALS['m4c_assertions']??0,'assertions_by_phase'=>$GLOBALS['m4c_assertions_by_phase']??[]];
    m4c_entry_publish('/run/audit/evidence/'.$rule['result'],$result);
    echo json_encode(['status'=>$rule['status'],'scope'=>$rule['scope'],'phase'=>'final_source'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4c_warning']=['severity'=>$severity,'file'=>basename($file),'line'=>$line];
        $GLOBALS['m4c_failed_assertion']='M4C_PHP_WARNING';throw new RuntimeException('M4C_PHP_WARNING');});
    try{exit(m4c_entry_run($argv[1]??''));}
    catch(Throwable $error){$mode=$argv[1]??'';$rule=M4C_MODE[$mode]??null;$source=$GLOBALS['m4c_entry_source']??null;
        $code=$GLOBALS['m4c_failed_assertion']??'';
        $safe=['schema'=>1,'status'=>'FAIL_PRESERVED','scope'=>$rule['scope']??'MS4_CUSTOMER_HTTP_SOURCE_ONLY',
            'phase'=>$GLOBALS['m4c_phase']??'source','class'=>get_class($error),
            'code'=>is_string($code)&&preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)===1?$code:'M4C_UNCLASSIFIED',
            'native_exit'=>1,'actual_customer_HTTP'=>'INCOMPLETE','browser_visual_acceptance'=>'NOT RUN'];
        if(is_array($source)){foreach(['run_id','source_sha','container_id','freeze_sha256'] as $key)$safe[$key]=$source[$key];
            if(isset($GLOBALS['m4c_warning']))$safe['warning']=$GLOBALS['m4c_warning'];
            try{m4c_entry_publish('/run/audit/evidence/'.$source['rule']['proof'].'-failure.json',$safe);}catch(Throwable $ignored){}}
        echo json_encode($safe,JSON_THROW_ON_ERROR)."\n";exit(1);}
}
