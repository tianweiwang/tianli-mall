<?php
declare(strict_types=1);

/** Dedicated source-bound natural non-due lifecycle; no product replacement or stopped samples. */
require_once __DIR__.'/ms4-aftersale-review-normal.php';
const M4W_ENTRY='help/audit-regressions/isolated/ms4-worker-normal.php';
const M4W_RULE=['role'=>'audit-ms4-worker-normal','proof'=>'worker-normal','binding'=>'ms4-worker-normal-source-binding.json',
 'binding_scope'=>'MS4_WORKER_NORMAL_SOURCE','environment'=>'worker-normal-environment.json','result'=>'ms4-worker-normal.json'];
const M4W_WINDOW_SECONDS=180;
const M4W_CACHE_KEYS=['c:crontabCache','c:marketplace_fulfillment_receive_cursor','c:CRMEB:827bf7a6b0b8d160bd116898762da4c5'];
function m4w_expect(bool $ok,string $code):void
{++$GLOBALS['m4w_assertions'];if(!$ok){$GLOBALS['m4w_failed_assertion']=$code;throw new RuntimeException('M4W_ASSERTION');}}
function m4w_phase(string $phase):void
{m4w_expect(empty($GLOBALS['m4w_termination_requested']),'M4W_NOT_INTERRUPTED');$GLOBALS['m4w_phase']=$phase;}
function m4w_extra():array
{$paths=array_merge(m4ar_extra(),[M4W_ENTRY]);sort($paths,SORT_STRING);
 m4w_expect(count($paths)===30&&count($paths)===count(array_unique($paths,SORT_STRING)),'M4W_ORIGINAL_TWENTY_NINE_PLUS_ONE');return $paths;}
function m4w_predecessor_names(bool $withRetirement=true):array
{
 $names=array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES,
 ['ms4-customer-prepare-source-binding.json','customer-prepare-environment.json','customer-prepare-environment-native.json'],
 M4FN_PAID_FILES,['driver-created.json']);
 foreach(['register-paid-producer','register-paid-after-c3']as$proof)foreach(['-created.json','-terminal.json','-result.json','-shutdown.json']as$suffix)$names[]=$proof.$suffix;
 $names=array_merge($names,['paid-producer-formal-manifest.json','paid-producer-predecessor-history.json'],M4AR_EARLY,
 ['fulfillment-migration-created.json','fulfillment-migration-terminal.json','ms4-fulfillment-migration-formal-manifest.json','fulfillment-normal-formal-manifest.json',
 'fulfillment-normal-created.json','fulfillment-normal-terminal.json','ms4-fulfillment-normal-source-binding.json','fulfillment-normal-environment.json',
 'fulfillment-normal-environment-native.json','ms4-fulfillment-normal.json','fulfillment-normal-shutdown.json','ms4-three-read-normal.json']);
 foreach(['register-fulfillment-migration','register-fulfillment-normal','authorize-fulfillment-cleanup']as$proof)
  foreach(['-created.json','-terminal.json','-result.json','-shutdown.json']as$suffix)$names[]=$proof.$suffix;
 $names=array_merge($names,['files-bootstrap-paid-unstarted.json','customer-prepare-paid-unstarted.json','fulfillment-normal-predecessor-history.json',
 'fulfillment-normal-remove-native.json','fulfillment-normal-removed.json','aftersale-review-normal-formal-manifest.json','aftersale-review-normal-created.json',
 'aftersale-review-normal-terminal.json','ms4-aftersale-review-normal-source-binding.json','aftersale-review-normal-environment.json',
 'aftersale-review-normal-environment-native.json','ms4-aftersale-review-normal.json','aftersale-review-normal-shutdown.json']);
 foreach(['register-aftersale-review-normal','authorize-aftersale-review-cleanup']as$proof)
  foreach(['-created.json','-terminal.json','-result.json','-shutdown.json']as$suffix)$names[]=$proof.$suffix;
 $names[]='aftersale-review-public-file-keep.json';
 m4w_expect(count($names)===count(array_unique($names,SORT_STRING)),'M4W_COMPLETE_PREDECESSOR_MEMBER_UNIQUENESS');
 if($withRetirement)$names=array_merge($names,['aftersale-review-normal-predecessor-history.json','aftersale-review-normal-remove-native.json','aftersale-review-normal-removed.json']);
 sort($names,SORT_STRING);return $names;
}
function m4w_public_hashes(array $names):array
{$out=[];foreach($names as$name){m4w_expect(preg_match('/\A[a-z0-9][a-z0-9.-]*\.json\z/D',$name)===1&&!isset($out[$name]),'M4W_FIXED_PUBLIC_MEMBER');
 $out[$name]=m4c_entry_hash('/run/audit/evidence/'.$name);}return $out;}
function m4w_profile_unchanged(array $source):void
{
 $wanted=['README.md','compose.yml','ms4-host.py','ms4-initialize.php','seed-source-inverse.json','interface-v1.json','source-derivation.json',
 'normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py'];$rows=$source['freeze']['artifact_files'];
 m4w_expect(array_column($rows,'path')===$wanted,'M4W_EXACT_NINE_FROZEN_PROFILE_ARTIFACTS');
 foreach($rows as$row){m4c_entry_keys($row,['path','bytes','sha256']);$path='/run/ms4-prep/'.$row['path'];
  m4w_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'M4W_COMPLETE_FROZEN_PROFILE_BYTES');}
}
function m4w_environment(array $binding,array $context):array
{
 $base='/run/audit/evidence/';$e=m4c_entry_read($base.M4W_RULE['environment']);
 m4c_entry_keys($e,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
 m4w_expect($e['schema']===1&&$e['scope']==='MS4_WORKER_NORMAL_REAL_ENVIRONMENT'&&$e['run_id']===$binding['run_id']
  &&$e['source_sha']===$binding['source_sha']&&$e['container_id']===$binding['container_id']&&$e['freeze_sha256']===$context['freeze_sha256']
  &&getenv('MS4_WORKER_NORMAL_PROOF_NAME')===M4W_RULE['proof'],'M4W_ACTUAL_WORKER_ENVIRONMENT_IDENTITY');
 $v=$e['environment'];m4c_entry_keys($v,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED',
 'PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER','PHP_MARKETPLACE_AFTERSALE_ENABLED','PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT']);
 m4w_expect($v['APP_ENV']==='testing'&&$v['PHP_CACHE_DRIVER']==='redis'&&$v['PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT']===M4AR_PRIVATE,'M4W_ORIGINAL_PRIVATE_CONFIG');
 foreach(['PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_AFTERSALE_ENABLED']as$key)
  m4w_expect($v[$key]==='true','M4W_ORIGINAL_EIGHT_ENVIRONMENT_VALUES');
 m5pcp_int($v['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],1,4294967295);
 $n=m4c_entry_read($base.'worker-normal-environment-native.json');m4c_entry_keys($n,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
 m4w_expect($n['stage']==='worker-normal-public-environment-inspect'&&$n['native_exit']===0&&$n['stderr_bytes']===0
  &&$n['stderr_sha256']===hash('sha256','')&&$n['private_environment_logs_omitted']===true&&$e['native_projection_sha256']===$n['stdout_sha256'],'M4W_NATIVE_ENVIRONMENT_RECEIPT');
 ksort($v,SORT_STRING);$lines=[];foreach($v as$key=>$value){m4w_expect(is_string($value)&&getenv($key)===$value,'M4W_NATIVE_ENVIRONMENT_VALUE');
  $lines[]=json_encode($key.'='.$value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 $raw=implode("\n",$lines)."\n";m4w_expect(strlen($raw)===$n['stdout_bytes']&&hash('sha256',$raw)===$n['stdout_sha256'],'M4W_NATIVE_SORTED_EIGHT_LINE_BYTES');return $v;
}
function m4w_inventory(string $root):array
{
    $paths=m4w_extra();
    foreach(M4C_ROOTS as$directory){
        m4w_expect(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory),'TASK6_SOURCE_DIRECTORY');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as$file){m4w_expect(!$file->isLink(),'TASK6_SOURCE_NO_LINK');
            if($file->isFile())$paths[]=substr($file->getPathname(),strlen($root)+1);}
    }
    sort($paths,SORT_STRING);m4w_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_UNIQUE_PATHS');return $paths;
}
function m4w_full_source(array $source,bool $beforePublicCopy=false):void
{
    $paths=[];$allowed=[];
    m4w_expect(is_array($source['source_files'])&&array_is_list($source['source_files']),'TASK6_SOURCE_DESCRIPTOR_LIST');
    foreach($source['source_files']as$row){m4c_entry_keys($row,['path','bytes','sha256']);
        m4w_expect(is_string($row['path'])&&$row['path']!==''&&$row['path'][0]!=='/'&&!str_contains($row['path'],'\\')
            &&!str_contains($row['path'],':')&&!in_array('..',explode('/',$row['path']),true)
            &&is_int($row['bytes'])&&$row['bytes']>=0&&m5pc_hex($row['sha256']),'TASK6_SOURCE_DESCRIPTOR');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);
    m4w_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_SORTED_CLOSED_SET');
    foreach(['/source','/workspace']as$root){m4w_expect(m4w_inventory($root)===$paths,'TASK6_SOURCE_INVENTORY');
        foreach($source['source_files']as$row){
            if($beforePublicCopy&&$root==='/workspace'&&in_array($row['path'],['crmeb/public/index.php','crmeb/public/router.php'],true))continue;
            $path=$root.'/'.$row['path'];m4w_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'TASK6_SOURCE_ALL_BYTES');$allowed[$path]=true;}}
    foreach(get_included_files()as$path)m4w_expect(isset($allowed[realpath($path)]),'TASK6_INCLUDED_COMPLETE_SOURCE');
}
function m4w_source(): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'normal']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_ACTUAL_FINITE_NATIVE_ENTRY');
    $rule=M4W_RULE;$evidence='/run/audit/evidence/';
    $context=m4c_entry_read('/run/audit/actual-run-context.json');$freeze=m4c_entry_read('/run/audit/preparation-source-binding.json');
    $created=m4c_entry_read($evidence.$rule['proof'].'-created.json');$binding=m4c_entry_read($evidence.$rule['binding']);
    $environment=m4c_entry_read($evidence.$rule['environment']);$manifest=audit_manifest();
    m4c_entry_keys($created,['id','image','hostname','user','stop_signal','labels','mounts','tmpfs','read_only','privileged',
        'network_mode','status','command','entrypoint','workdir','restart_count','pid','exit_code','networks','ports',
        'port_bindings','cap_drop','security_options','started_at','finished_at','created_at','run_id','source_sha','proof_name']);
    $run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');$cid=$created['id']??null;
    m4c_expect(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1&&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$manifest['run_id']===$run&&$manifest['commit_sha']===$sha
        &&$manifest['project']===$context['project']&&$manifest['isolation']==='ISO-FULL'
        &&$manifest['guard_mode']==='read-only'&&$manifest['network_internal']===true,'M4C_SAME_REAL_ISOLATION_SOURCE');
    $roles=array_keys($manifest['services']);sort($roles,SORT_STRING);$wanted=[$rule['role'],'audit-ms5-paid-producer','audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
    m4w_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'TASK6_ONLY_FOUR_ACTUAL_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    m4w_expect($freeze['worker_source_roots']===M4C_ROOTS&&$freeze['worker_source_extra']===m4w_extra(),
        'TASK6_REAL_FOUR_ROOTS_FINITE_EXTRA_CLOSURE');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
    m4c_expect($binding['schema']===1&&$binding['scope']===$rule['binding_scope']&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$rule['proof'].'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['worker_source_files']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha
        &&$created['proof_name']===$rule['proof']&&$created['hostname']===gethostname()&&$created['read_only']===true
        &&$created['user']==='0:0'&&posix_geteuid()===0&&posix_getegid()===0
        &&$created['privileged']===false&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4W_ENTRY,'normal']&&$created['workdir']==='/workspace'
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
    m4w_expect($created['stop_signal']==='SIGTERM'&&getmypid()===1
        &&!in_array('audit-app',$created['networks'][$network['Name']]['Aliases']??[],true),'M4W_REAL_PID_ONE_NO_HTTP_ALIAS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',true],
        M4AR_PRIVATE=>['aftersale-evidence',false]];
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
    $values=m4w_environment($binding,$context);
    audit_guard_assert();
    $source=$binding+['rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment']),
        'environment_native_sha256'=>m4c_entry_hash($evidence.$rule['proof'].'-environment-native.json')];
    m4w_full_source($source,true);
    m4w_prerequisites($source);
    return $source;
}

function m4w_prerequisites(array $source):void
{
 $base='/run/audit/evidence/';$hashes=m4w_public_hashes(m4w_predecessor_names());
 m4c_entry_same($source['prerequisites_sha256'],$hashes);$GLOBALS['m4w_predecessor_hashes']=$hashes;
 $history=m4c_entry_read($base.'aftersale-review-normal-predecessor-history.json');
 m4c_entry_keys($history,['schema','scope','run_id','source_sha','freeze_sha256','context_sha256','manifest_sha256','public_sha256']);
 m4w_expect($history['schema']===1&&$history['scope']==='MS4_AFTERSALE_REVIEW_NORMAL_REAL_PREDECESSOR_HISTORY'
  &&$history['run_id']===$source['run_id']&&$history['source_sha']===$source['source_sha']&&$history['freeze_sha256']===$source['freeze_sha256']
  &&$history['context_sha256']===m4c_entry_hash('/run/audit/actual-run-context.json')
  &&$history['manifest_sha256']===$hashes['aftersale-review-normal-formal-manifest.json'],'M4W_EXACT_ORIGINAL_NORMAL_HISTORY');
 m4c_entry_same($history['public_sha256'],m4w_public_hashes(m4w_predecessor_names(false)));
 $normal=m4c_entry_terminal($source,'aftersale-review-normal',M4AR_RULE['role'],[M4AR_ENTRY,'normal']);
 $created=m4c_entry_read($base.'aftersale-review-normal-created.json');$formal=m4c_entry_read($base.'aftersale-review-normal-formal-manifest.json');
 $roles=array_keys($formal['services']);sort($roles,SORT_STRING);$wanted=[M4AR_RULE['role'],'audit-ms5-paid-producer','audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
 m4w_expect($roles===$wanted&&$formal['services'][M4AR_RULE['role']]['id']===$normal['id']&&$normal['id']!==$source['container_id'],'M4W_ORIGINAL_NORMAL_FOUR_ROLE_FORMAL');
 foreach(['run_id','project','commit_sha','isolation','guard_mode','network_internal','network','volumes','volume_identities']as$key)m4c_entry_same($formal[$key],audit_manifest()[$key]);
 foreach(['audit-ms5-paid-producer','audit-mysql','audit-redis']as$role)m4c_entry_same($formal['services'][$role],audit_manifest()['services'][$role]);
 $result=m4c_entry_read($base.M4AR_RULE['result']);m4c_entry_keys($result,['schema','scope','status','run_id','source_sha','container_id','freeze_sha256',
 'native_created_sha256','source_binding_sha256','environment_sha256','environment_native_sha256','prerequisites_sha256','phase','entry_return_code',
 'termination','native_exit','native_exit_observation','full_source_unchanged','metadata_observer','business','child','diagnostic','mobile_workbench_HTTP_DOM',
 'three_end_DOM','worker','finance','full_acceptance','stopped_samples','assertions']);
 m4w_expect($result['schema']===1&&$result['scope']===M4AR_RULE['scope']&&$result['status']===M4AR_RULE['status']
  &&$result['run_id']===$source['run_id']&&$result['source_sha']===$source['source_sha']&&$result['freeze_sha256']===$source['freeze_sha256']
  &&$result['container_id']===$normal['id']&&$result['native_created_sha256']===$hashes['aftersale-review-normal-created.json']
  &&$result['source_binding_sha256']===$hashes[M4AR_RULE['binding']]&&$result['phase']==='final_source'&&$result['entry_return_code']===0
  &&$result['termination']==='NORMAL_RETURN'&&$result['native_exit']===null&&$result['native_exit_observation']==='HOST_NATIVE_REQUIRED'
  &&$result['full_source_unchanged']===true&&$result['diagnostic']===null&&$result['full_acceptance']==='INCOMPLETE'
  &&$result['stopped_samples']==='NOT RUN'&&$result['worker']==='NOT RUN','M4W_ORIGINAL_NORMAL_NATIVE_AND_RESULT');
 m4c_entry_keys($result['business'],['authenticated_owner_uid','merchant_id','employee_id','limited_admin_id','original_root_id','new_root_id','new_items','new_quantity',
 'normal_action_counts','s2_operations','same_body_replays','new_refunds','private_evidence','review','http','paid_baseline','final_baseline',
 'independent_database_oracle','independent_redis_oracle','independent_files_oracle','prior_task6_preserved','original_private_inputs_preserved',
 'normal_business_decisions','excluded_acceptance']);
 foreach(['independent_database_oracle','independent_redis_oracle','independent_files_oracle','prior_task6_preserved','original_private_inputs_preserved']as$key)
  m4w_expect($result['business'][$key]===true,'M4W_ORIGINAL_NORMAL_ORACLE_OBLIGATIONS_RETAINED');
 $shutdown=m4c_entry_read($base.'aftersale-review-normal-shutdown.json');
 m4c_entry_keys($shutdown,['schema','scope','run_id','source_sha','container_id','freeze_sha256','phase','completed','result_published','fatal',
 'termination_requested','primary_failure','cleanup_failure','native_exit','native_exit_observation']);
 m4w_expect($shutdown['schema']===1&&$shutdown['scope']==='MS4_AFTERSALE_REVIEW_NORMAL_SHUTDOWN'&&$shutdown['run_id']===$source['run_id']
  &&$shutdown['source_sha']===$source['source_sha']&&$shutdown['container_id']===$normal['id']&&$shutdown['freeze_sha256']===$source['freeze_sha256']
  &&$shutdown['phase']==='final_source'&&$shutdown['completed']===true&&$shutdown['result_published']===true&&$shutdown['fatal']===false
  &&$shutdown['termination_requested']===false&&$shutdown['primary_failure']===null&&$shutdown['cleanup_failure']===null
  &&$shutdown['native_exit']===null&&$shutdown['native_exit_observation']==='HOST_NATIVE_REQUIRED','M4W_ORIGINAL_NORMAL_SHUTDOWN');
 $binding=m4c_entry_read($base.M4AR_RULE['binding']);m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256',
 'freeze_sha256','requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
 m4w_expect($binding['schema']===1&&$binding['scope']===M4AR_RULE['binding_scope']&&$binding['container_id']===$normal['id']
  &&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']&&$binding['freeze_sha256']===$source['freeze_sha256']
  &&$binding['native_created_sha256']===$hashes['aftersale-review-normal-created.json']
  &&$binding['requested_repo_source']===$source['requested_repo_source']&&$binding['requested_run_source']===$source['requested_run_source']
  &&$binding['requested_prep_source']===$source['requested_prep_source']&&$binding['source_files']===$source['freeze']['aftersale_review_source_files']
  &&$binding['entry_sha256']===m4c_entry_hash('/source/'.M4AR_ENTRY),'M4W_ORIGINAL_NORMAL_BINDING');
 m4c_entry_same($binding['prerequisites_sha256'],$result['prerequisites_sha256']);
 $old=$binding+['context'=>$source['context'],'freeze'=>$source['freeze'],'environment'=>$source['environment'],
 'self_operated_mer_id'=>$source['self_operated_mer_id']];m4ar_prerequisites($old);
 $removed=m4c_entry_read($base.'aftersale-review-normal-removed.json');
 m4c_entry_keys($removed,['schema','scope','run_id','source_sha','container_id','created_sha256','terminal_sha256','native_remove','absent_after','target_role']);
 m4w_expect($removed['schema']===1&&$removed['scope']==='MS4_AFTERSALE_REVIEW_NORMAL_EXACT_REMOVAL'&&$removed['run_id']===$source['run_id']
  &&$removed['source_sha']===$source['source_sha']&&$removed['container_id']===$normal['id']&&$removed['created_sha256']===$hashes['aftersale-review-normal-created.json']
  &&$removed['terminal_sha256']===$hashes['aftersale-review-normal-terminal.json']&&$removed['absent_after']===true&&$removed['target_role']===M4AR_RULE['role'],'M4W_REAL_NORMAL_EXACT_REMOVAL');
 $native=$removed['native_remove'];m4c_entry_keys($native,['argv','native_exit','stdout_sha256','stderr_sha256','same_handle_terminal']);
 $terminal=$native['same_handle_terminal'];m4c_entry_keys($terminal,['pid','returncode','wait_returncode']);
 m4w_expect($native['argv']===['docker','rm',$normal['id']]&&$native['native_exit']===0&&$native['stdout_sha256']===hash('sha256',$normal['id']."\n")
  &&$native['stderr_sha256']===hash('sha256','')&&is_int($terminal['pid'])&&$terminal['pid']>0&&$terminal['returncode']===0&&$terminal['wait_returncode']===0,
  'M4W_REAL_NORMAL_NONFORCE_NATIVE_REMOVAL');
 $removeNative=m4c_entry_read($base.'aftersale-review-normal-remove-native.json');m4c_entry_keys($removeNative,['schema','native','stdout_bytes','stderr_bytes']);
 m4w_expect($removeNative['schema']===1&&$removeNative['native']===$native&&$removeNative['stdout_bytes']===strlen($normal['id']."\n")
  &&$removeNative['stderr_bytes']===0,'M4W_FULL_NORMAL_REMOVE_NATIVE_WRAPPER');
 $GLOBALS['m4w_normal_result']=$result;$GLOBALS['m4w_normal_binding']=$binding;
 m4w_profile_unchanged($source);
}


function m4w_files_capture(string $root):array
{
 m4w_expect(in_array($root,['/workspace/crmeb/runtime','/workspace/crmeb/public','/run/audit-fixtures',M4AR_PRIVATE],true)
  &&is_dir($root)&&!is_link($root)&&realpath($root)===$root,'M4W_FIXED_CANONICAL_CAPTURE_ROOT');
 $files=[];$map=[];$total=0;$fields=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];$fieldKeys=array_flip($fields);
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
 foreach($iterator as$file){$path=$file->getPathname();m4w_expect(str_starts_with($path,$root.'/'),'M4W_CAPTURE_DESCENDANT');
  $relative=substr($path,strlen($root)+1);m4w_relative($relative);$parent=$root;
  foreach(array_slice(explode('/',$relative),0,-1)as$part){$parent.='/'.$part;
   m4w_expect(is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent,'M4W_CAPTURE_CANONICAL_PARENT');}
  clearstatcache(true,$path);$a=lstat($path);
  m4w_expect(is_array($a)&&!is_link($path)&&realpath($path)===$path&&realpath(dirname($path))===dirname($path),'M4W_CAPTURE_CANONICAL_MEMBER');
  if(($a['mode']&0170000)===0040000){m4w_expect(is_dir($path),'M4W_CAPTURE_REAL_DIRECTORY');continue;}
  m4w_expect(($a['mode']&0170000)===0100000&&is_file($path)&&$a['uid']===posix_geteuid()&&$a['nlink']===1
   &&is_int($a['size'])&&$a['size']>=0,'M4W_CAPTURE_OWNER_SINGLE_REGULAR');
  if($root==='/run/audit-fixtures'&&in_array($path,[M4W_BEFORE,M4W_AFTER],true))continue;
  m4w_expect($a['size']<=67108864-$total,'M4W_CAPTURE_ORIGINAL_COMPLETE_BOUND');
  $raw=file_get_contents($path);clearstatcache(true,$path);$b=lstat($path);
  $stable=array_intersect_key($a,$fieldKeys);$after=is_array($b)?array_intersect_key($b,$fieldKeys):[];
  m4w_expect(is_string($raw)&&strlen($raw)===$a['size']&&count($stable)===9&&count($after)===9&&$stable===$after
   &&!is_link($path)&&realpath($path)===$path,'M4W_CAPTURE_COMPLETE_STABLE_FIELDS');
  $metadata=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'mode'=>$a['mode']&0777,'uid'=>$a['uid'],'nlink'=>$a['nlink']];
  $encoded=base64_encode($raw);m4w_expect(base64_decode($encoded,true)===$raw,'M4W_CAPTURE_STRICT_BYTES');
  $map[$relative]=$metadata;$files[$relative]=['metadata'=>$metadata,'base64'=>$encoded];$total+=strlen($raw);
 }ksort($map,SORT_STRING);ksort($files,SORT_STRING);return ['file_map'=>$map,'files'=>$files];
}
function m4w_relative(string $relative):void
{
 m4w_expect($relative!==''&&!str_starts_with($relative,'/')&&!str_contains($relative,"\0")&&!str_contains($relative,'\\'),'M4W_STRICT_RELATIVE_MEMBER');
 foreach(explode('/',$relative)as$part)m4w_expect($part!==''&&$part!=='.'&&$part!=='..','M4W_NO_DOT_MEMBER');
}
function m4w_config_capture(array $source):array
{
 $path='/run/audit-app/config.json';$parent=dirname($path);$ps=lstat($parent);clearstatcache(true,$path);$a=lstat($path);
 m4w_expect(is_array($ps)&&($ps['mode']&0777)===0700&&$ps['uid']===posix_geteuid()&&!is_link($parent)&&realpath($parent)===$parent
  &&is_array($a)&&($a['mode']&0170000)===0100000&&($a['mode']&0777)===0600&&$a['uid']===posix_geteuid()&&$a['nlink']===1
  &&!is_link($path)&&realpath($path)===$path&&$a['size']>0&&$a['size']<=1048576,'M4W_REAL_PRIVATE_CONFIG');
 $raw=file_get_contents($path);clearstatcache(true,$path);$b=lstat($path);$keys=array_flip(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']);
 $stat=array_intersect_key($a,$keys);m4w_expect(is_string($raw)&&strlen($raw)===$a['size']&&count($stat)===9&&is_array($b)
  &&array_intersect_key($b,$keys)===$stat,'M4W_CONFIG_COMPLETE_STABLE_READ');
 $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);m4c_entry_keys($v,['run_id','database','redis_password','signing_key','auth']);
 m4c_entry_keys($v['database'],['username','password']);m4c_entry_keys($v['auth'],['session_epoch','stamp_key','issuer']);
 m4w_expect($v['run_id']===$source['run_id']&&$v['database']['username']==='audit_app'
  &&preg_match('/\A[0-9a-f]{48}\z/D',$v['database']['password'])===1&&preg_match('/\A[0-9a-f]{48}\z/D',$v['redis_password'])===1
  &&m5pc_hex($v['signing_key'])&&m5pc_hex($v['auth']['stamp_key'])&&$v['signing_key']!==$v['auth']['stamp_key']
  &&$v['auth']['session_epoch']==='audit-http-'.$source['run_id']&&$v['auth']['issuer']==='tianli-mall','M4W_ORIGINAL_CONFIG_PRODUCER_SCHEMA');
 return ['path'=>$path,'raw'=>$raw,'sha256'=>hash('sha256',$raw),'stat'=>$stat];
}
function m4w_old_inputs():array
{
 $out=[];foreach([M4CB_FIXTURE=>1048576,M5PC_BASELINE=>2097152,M4FN_BASELINE=>67108864,M4AR_PAID_SEAL=>100663296,
  M4AR_SEAL=>100663296,M4AR_PUBLIC_KEEP=>100663296]as$path=>$max)$out[$path]=['maximum'=>$max,'input'=>m4ar_private_input($path,$max)];return $out;
}
function m4w_old_inputs_unchanged(array $inputs):void
{foreach($inputs as$path=>$v)m4w_expect(m4ar_private_input($path,$v['maximum'])===$v['input'],'M4W_ALL_ORIGINAL_PRIVATE_BYTES_STAT_UNCHANGED');}
function m4w_input_public(array $inputs):array
{
 $out=[];foreach($inputs as$path=>$v)$out[]=['path_basename'=>basename($path),'bytes'=>strlen($v['input']['raw']),'sha256'=>$v['input']['sha256']];return $out;
}
function m4w_archive_load(string $path,string $scope,array $identity,array $descriptor):array
{
 $saved=m4ar_private_input($path,100663296);$outer=$saved['value'];
 m4c_entry_keys($outer,['schema','scope','run_id','source_sha','freeze_sha256','container_id','encoding','bytes','sha256','payload']);
 m4w_expect($outer['schema']===1&&$outer['scope']===$scope.'_ARCHIVE'&&$outer['run_id']===$identity['run_id']
  &&$outer['source_sha']===$identity['source_sha']&&$outer['freeze_sha256']===$identity['freeze_sha256']&&$outer['container_id']===$identity['container_id']
  &&$outer['encoding']==='php-serialize-base64-v1'&&is_int($outer['bytes'])&&$outer['bytes']>0&&$outer['bytes']<=67108864&&is_string($outer['payload']),
  'M4W_ORIGINAL_ENVELOPE_IDENTITY_BOUND');
 $bytes=base64_decode($outer['payload'],true);m4w_expect(is_string($bytes)&&base64_encode($bytes)===$outer['payload']
  &&strlen($bytes)===$outer['bytes']&&hash('sha256',$bytes)===$outer['sha256'],'M4W_ORIGINAL_ENVELOPE_COMPLETE_NATIVE');
 $expected=['path_basename'=>basename($path),'scope'=>$scope,'bytes'=>strlen($saved['raw']),'sha256'=>$saved['sha256'],
  'native_archive_bytes'=>strlen($bytes),'native_archive_sha256'=>hash('sha256',$bytes)];
 m4w_expect($descriptor===$expected,'M4W_SIX_DESCRIPTOR_EXACT_NATIVE_INPUT');
 $payload=unserialize($bytes,['allowed_classes'=>false,'max_depth'=>128]);
 m4w_expect(is_array($payload)&&serialize($payload)===$bytes,'M4W_ORIGINAL_NATIVE_STRICT_RESTORE');
 foreach(['schema'=>1,'scope'=>$scope,'run_id'=>$identity['run_id'],'source_sha'=>$identity['source_sha'],'freeze_sha256'=>$identity['freeze_sha256'],
  'container_id'=>$identity['container_id'],'source_binding_sha256'=>$identity['source_binding_sha256']]as$k=>$v)
  m4w_expect(array_key_exists($k,$payload)&&$payload[$k]===$v,'M4W_PAYLOAD_EXACT_IDENTITY');
 return $payload;
}
function m4w_public_restore(array $source,array $inputs):array
{
 $old=$GLOBALS['m4w_normal_result'];$finalSeal=$old['business']['final_baseline'];
 $final=m4w_archive_load(M4AR_SEAL,'MS4_AFTERSALE_REVIEW_FINAL_NATIVE_FACTS',$old,$finalSeal);
 m4c_entry_keys($final,['schema','scope','run_id','source_sha','freeze_sha256','container_id','source_binding_sha256','original_inputs','paid_baseline','model','native']);
 $descriptor=m4c_entry_read('/run/audit/evidence/aftersale-review-public-file-keep.json');
 m4c_entry_keys($descriptor,['schema','scope','run_id','source_sha','freeze_sha256','container_id','source_binding_sha256','final_baseline_sha256','archive','file_map_sha256']);
 m4w_expect($descriptor['schema']===1&&$descriptor['scope']==='MS4_AFTERSALE_REVIEW_PUBLIC_FILE_KEEP'
  &&$descriptor['run_id']===$old['run_id']&&$descriptor['source_sha']===$old['source_sha']&&$descriptor['freeze_sha256']===$old['freeze_sha256']
  &&$descriptor['container_id']===$old['container_id']&&$descriptor['source_binding_sha256']===$old['source_binding_sha256']
  &&$descriptor['final_baseline_sha256']===$finalSeal['sha256']&&$descriptor['file_map_sha256']===hash('sha256',serialize($final['native']['public'])),
  'M4W_REAL_NORMAL_KEEP_DESCRIPTOR_IDENTITY');
 $keep=m4w_archive_load(M4AR_PUBLIC_KEEP,'MS4_AFTERSALE_REVIEW_PUBLIC_FILE_KEEP_NATIVE_FACTS',$old,$descriptor['archive']);
 m4c_entry_keys($keep,['schema','scope','run_id','source_sha','freeze_sha256','container_id','source_binding_sha256','final_baseline','file_map','files']);
 m4w_expect($keep['final_baseline']===$finalSeal&&$keep['file_map']===$final['native']['public']
  &&array_keys($keep['files'])===array_keys($keep['file_map']),'M4W_KEEP_EXACT_FULL_PUBLIC_MAP');
 m4c_entry_public_materials($source);$root='/workspace/crmeb/public';$existing=m4w_files_capture($root);$protected=['install/crmeb.sql','index.php','router.php','install.lock'];
 foreach($existing['files']as$relative=>$file)m4w_expect(in_array($relative,$protected,true)&&isset($keep['files'][$relative])
  &&$file===$keep['files'][$relative],'M4W_INSTALL_MEMBERS_ONLY_VERIFY_SAVED_BYTES');
 m4w_expect(count($existing['files'])===4,'M4W_FOUR_INSTALL_MEMBERS_PRESERVED');
 $total=0;foreach($keep['files']as$relative=>$file){m4w_relative($relative);m4c_entry_keys($file,['metadata','base64']);$meta=$file['metadata'];
  m4c_entry_keys($meta,['bytes','sha256','mode','uid','nlink']);$raw=base64_decode($file['base64'],true);
  m4w_expect(is_string($raw)&&base64_encode($raw)===$file['base64']&&is_int($meta['bytes'])&&$meta['bytes']>=0&&strlen($raw)===$meta['bytes']
   &&hash('sha256',$raw)===$meta['sha256']&&$meta===$keep['file_map'][$relative]&&$meta['uid']===posix_geteuid()&&$meta['nlink']===1
   &&is_int($meta['mode'])&&$meta['mode']>=0&&$meta['mode']<=0777&&strlen($raw)<=67108864-$total,'M4W_RESTORE_STRICT_ACTUAL_KEPT_BYTES');
  $total+=strlen($raw);if(isset($existing['files'][$relative]))continue;
  $parent=$root;foreach(array_slice(explode('/',$relative),0,-1)as$part){$parent.='/'.$part;
   if(!file_exists($parent))m4w_expect(mkdir($parent,0700),'M4W_CREATE_ONLY_KEPT_PARENT');
   m4w_expect(is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent,'M4W_RESTORE_CANONICAL_PARENT');}
  $path=$root.'/'.$relative;m4w_expect(!file_exists($path)&&!is_link($path),'M4W_RESTORE_NEW_MEMBER_ONLY');$mask=umask(0077);$h=fopen($path,'xb');
  try{m4w_expect(is_resource($h),'M4W_RESTORE_REAL_CREATE_ONLY');$offset=0;while($offset<strlen($raw)){$n=fwrite($h,substr($raw,$offset));
    m4w_expect(is_int($n)&&$n>0,'M4W_RESTORE_COMPLETE_NATIVE_WRITE');$offset+=$n;}
   m4w_expect(fflush($h)&&fsync($h)&&chmod($path,$meta['mode']),'M4W_RESTORE_FSYNC_SAVED_MODE');}
  finally{if(is_resource($h))fclose($h);umask($mask);}
 }
 $read=m4w_files_capture($root);m4w_expect($read['file_map']===$keep['file_map']&&$read['files']===$keep['files'],'M4W_COMPLETE_KEEP_MATERIALIZATION_READBACK');
 m4w_old_inputs_unchanged($inputs);return ['final'=>$final,'descriptor'=>$descriptor,'keep'=>$keep];
}
function m4w_cache_native():array
{
 $lua=<<<'LUA'
local out={redis.call('TIME')}
for _,key in ipairs(KEYS) do
 local kind=redis.call('TYPE',key).ok
 local value=false
 local dump=false
 if kind=='string' then value=redis.call('GET',key);dump=redis.call('DUMP',key)
 elseif kind=='set' then value=redis.call('SMEMBERS',key);table.sort(value) end
 out[#out+1]={key,kind,redis.call('PEXPIRETIME',key),value,dump}
end
return out
LUA;
 $reply=audit_redis()->rawCommand('EVAL_RO',$lua,3,...M4W_CACHE_KEYS);$GLOBALS['m4w_cache_reply_history'][]=$reply;
 m4w_expect(is_array($reply)&&count($reply)===4&&is_array($reply[0])&&count($reply[0])===2,'M4W_FIXED_EVAL_RO_FULL_REPLY');
 $sec=m5pcp_int($reply[0][0],0);$micro=m5pcp_int($reply[0][1],0,999999);$time=(string)($sec*1000+intdiv($micro,1000));$rows=[];
 foreach(M4W_CACHE_KEYS as$i=>$key){$row=$reply[$i+1];m4w_expect(is_array($row)&&count($row)===5&&$row[0]===$key
   &&is_string($row[1])&&is_int($row[2]),'M4W_FIXED_EVAL_RO_NATIVE_MEMBER');
  $rows[$key]=['type'=>$row[1],'expires'=>$row[2],'value'=>$row[3],'dump'=>$row[4]];
 }return ['reply'=>$reply,'time_ms'=>$time,'rows'=>$rows];
}
function m4w_cache_public(array $capture):array
{
 $out=[];foreach($capture['rows']as$key=>$row){$value=$row['value'];$native=is_array($value)?serialize($value):(is_string($value)?$value:'');
  $out[$key]=['type'=>$row['type'],'expires'=>$row['expires'],'bytes'=>strlen($native),'sha256'=>hash('sha256',$native)];}
 return ['time_ms'=>$capture['time_ms'],'keys'=>$out,'reply_sha256'=>hash('sha256',serialize($capture['reply']))];
}
function m4w_cache_snapshot_match(array $redis,array $capture):void
{
 foreach($capture['rows']as$key=>$row){$hash=hash('sha256',$key);
  if($row['type']==='none'){m4w_expect($row['expires']===-2&&$row['value']===false&&$row['dump']===false&&!isset($redis['keys'][$hash]),'M4W_ACTUAL_MISSING_CACHE');continue;}
  m4w_expect(isset($redis['keys'][$hash]),'M4W_CACHE_KEY_IN_COMPLETE_SNAPSHOT');$expected=$redis['keys'][$hash];
  if($row['type']==='string'){m4w_expect(is_string($row['value'])&&is_string($row['dump']),'M4W_ACTUAL_NATIVE_STRING_DUMP');$digest=hash('sha256',$row['dump']);}
  elseif($row['type']==='set'){m4w_expect(is_array($row['value'])&&$row['dump']===false,'M4W_ACTUAL_NATIVE_SET');$sorted=$row['value'];sort($sorted,SORT_STRING);
   m4w_expect($sorted===$row['value']&&count(array_unique($sorted,SORT_STRING))===count($sorted),'M4W_NATIVE_SORTED_UNIQUE_SET');
   foreach($sorted as$member)m4w_expect(is_string($member),'M4W_NATIVE_SET_STRING');$digest=hash('sha256',"set\0".serialize($sorted));}
  else throw new RuntimeException('M4W_UNSUPPORTED_NATURAL_TIMER_CACHE_TYPE');
  m4w_expect($expected===['type'=>$row['type'],'sha256'=>$digest,'expires'=>$row['expires']],'M4W_FIXED_CACHE_ACTUAL_FULL_SNAPSHOT_BINDING');
 }
}
function m4w_cache_preflight(array $capture):void
{
 $list=$capture['rows'][M4W_CACHE_KEYS[0]];
 m4w_expect($list['type']==='none'||($list['type']==='string'&&is_string($list['value'])
  &&unserialize($list['value'],['allowed_classes'=>false,'max_depth'=>16])===[]&&$list['value']===serialize([])),'M4W_NATURAL_TASK_CACHE_ABSENT_OR_STRICT_EMPTY');
 $cursor=$capture['rows'][M4W_CACHE_KEYS[1]];m4w_expect(in_array($cursor['type'],['none','string'],true),'M4W_NATIVE_CURSOR_TYPE');
 if($cursor['type']==='string'){$v=unserialize($cursor['value'],['allowed_classes'=>false,'max_depth'=>16]);m4w_expect(is_array($v)&&serialize($v)===$cursor['value'],'M4W_STRICT_EXISTING_CURSOR_NATIVE');
  m4c_entry_keys($v,['due_at','ship_id']);m4w_expect(is_int($v['due_at'])&&$v['due_at']>=0&&$v['due_at']<=4294967295&&is_string($v['ship_id'])
   &&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$v['ship_id'])===1
   &&(strlen($v['ship_id'])<20||strcmp($v['ship_id'],'18446744073709551615')<=0)
   &&(($v['due_at']===0)===($v['ship_id']==='0')),'M4W_VALID_ORIGINAL_CURSOR');}
 m4w_expect(in_array($capture['rows'][M4W_CACHE_KEYS[2]]['type'],['none','set'],true),'M4W_NATIVE_TAG_ABSENT_OR_SET');
}
function m4w_preflight(PDO $db,array $snapshot,array $cache):array
{
 $tasks=$db->query('SELECT * FROM eb_system_timer ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $ship=$db->query("SELECT fo.*,c.status AS actual_child_status FROM eb_marketplace_fulfillment_operation fo INNER JOIN eb_store_order c ON c.id=fo.child_order_id WHERE fo.action='ship' AND fo.state=1 ORDER BY fo.id")->fetchAll(PDO::FETCH_ASSOC);
 $now=time();return ['tasks'=>$tasks,'saved_ship_rows'=>$ship,'wall_started'=>$now,'wall_through'=>$now+M4W_WINDOW_SECONDS,
  'redis_started_ms'=>$cache['time_ms'],'redis_through_ms'=>(string)((int)$cache['time_ms']+M4W_WINDOW_SECONDS*1000),'seconds'=>M4W_WINDOW_SECONDS];
}
function m4w_launch_preconditions(array $snapshot,array $cache,array $window):void
{
 m4w_expect($window['tasks']===[],'M4W_NATURALLY_EMPTY_COMPLETE_TASK_TABLE');m4w_cache_snapshot_match($snapshot['redis'],$cache);m4w_cache_preflight($cache);
 foreach($window['saved_ship_rows']as$row){$due=m5pcp_int($row['receipt_due_at'],0,4294967295);$status=$row['actual_child_status'];m4w_expect((is_string($status)||is_int($status))&&preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D',(string)$status)===1,'M4W_ACTUAL_SAVED_CHILD_STATUS');
  m4w_expect((string)$status!=='1'||$due===0||$due>$window['wall_through'],'M4W_SAVED_RECEIPT_DEADLINE_OUTLASTS_NORMAL_WINDOW');}
 foreach($snapshot['redis']['keys']as$row)m4w_expect($row['expires']<=0||$row['expires']>(int)$window['redis_through_ms'],'M4W_ALL_REDIS_ABSOLUTE_DEADLINES_OUTLAST_WINDOW');
}
function m4w_timer_changes(array $before,array $after,array $cacheBefore,array $cacheAfter):array
{
 m4ar_database_delta($before,$after,[]);m4w_cache_snapshot_match($before['redis'],$cacheBefore);m4w_cache_snapshot_match($after['redis'],$cacheAfter);
 $rows=$cacheAfter['rows'];m4w_expect($rows[M4W_CACHE_KEYS[0]]['type']==='string'&&$rows[M4W_CACHE_KEYS[0]]['value']===serialize([])
  &&$rows[M4W_CACHE_KEYS[0]]['expires']===-1,'M4W_REAL_TIMER_EMPTY_LIST_NO_TTL');
 m4w_expect($rows[M4W_CACHE_KEYS[1]]['type']==='string'&&$rows[M4W_CACHE_KEYS[1]]['value']===serialize(['due_at'=>0,'ship_id'=>'0'])
  &&$rows[M4W_CACHE_KEYS[1]]['expires']===-1,'M4W_REAL_ZERO_SCAN_CURSOR_NATIVE_NO_TTL');
 $old=$cacheBefore['rows'][M4W_CACHE_KEYS[2]];$members=$old['type']==='none'?[]:$old['value'];$members[]=M4W_CACHE_KEYS[1];
 $members=array_values(array_unique($members,SORT_STRING));sort($members,SORT_STRING);
 m4w_expect($rows[M4W_CACHE_KEYS[2]]['type']==='set'&&$rows[M4W_CACHE_KEYS[2]]['value']===$members
  &&$rows[M4W_CACHE_KEYS[2]]['expires']===($old['type']==='none'?-1:$old['expires']),'M4W_ORIGINAL_TAG_ONLY_REAL_CURSOR_MEMBER');
 $changes=[];foreach(M4W_CACHE_KEYS as$key)$changes[$key]=$after['redis']['keys'][hash('sha256',$key)];
 m4ar_redis_delta($before,$after,$changes);
 m4w_expect($before['private']===$after['private']&&$before['public']===$after['public'],'M4W_ALL_ORIGINAL_PRIVATE_PUBLIC_FILES_UNCHANGED');
 return ['before'=>m4w_cache_public($cacheBefore),'after'=>m4w_cache_public($cacheAfter),'changes'=>$changes];
}

/* Source-only process fragment. The entry owns the outer shutdown handler. */
$GLOBALS['m4w_process_state'] ??= null;

function m4w_proc_keys(array $actual, array $expected, string $code): void
{
    $keys = array_keys($actual);
    sort($keys, SORT_STRING);
    sort($expected, SORT_STRING);
    m4w_expect($keys === $expected, $code);
}

function m4w_proc_same(array $a, array $b): bool
{
    foreach (['pid', 'ppid', 'pgid', 'sid', 'starttime'] as $key) {
        if (!array_key_exists($key, $a) || !array_key_exists($key, $b) || $a[$key] !== $b[$key]) {
            return false;
        }
    }
    return true;
}

function m4w_proc_live(array $stat): bool
{
    return !in_array($stat['state'], ['Z', 'X', 'x', 'T', 't'], true);
}

/** The original parser remains the only /proc/stat field parser. */
function m4w_proc_inventory(): array
{
    $paths = glob('/proc/[0-9]*/stat');
    m4w_expect(is_array($paths), 'M4W_PROC_INVENTORY_AVAILABLE');
    $members = [];
    $native = [];
    foreach ($paths as $path) {
        m4w_expect(preg_match('~\A/proc/([1-9][0-9]*)/stat\z~D', $path, $match) === 1
            && strlen($match[1]) < 11, 'M4W_PROC_NUMERIC_PATH');
        $pid = (int)$match[1];
        $first = audit_app_proc_stat($pid);
        if ($first === null) {
            continue;
        }
        $raw = @file_get_contents($path);
        $exe = @readlink('/proc/' . $pid . '/exe');
        $cwd = @readlink('/proc/' . $pid . '/cwd');
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        $last = audit_app_proc_stat($pid);
        m4w_expect($last === null || m4w_proc_same($first, $last), 'M4W_PROC_NO_REPLACED_IDENTITY_DURING_READ');
        m4w_expect($raw === false || (is_string($raw) && strlen($raw) <= 8192
            && str_starts_with($raw, $pid . ' (')), 'M4W_PROC_COMPLETE_NATIVE_STAT');
        $members[$pid] = $last ?? $first;
        $native[$pid] = ['before' => $first, 'raw_stat' => $raw, 'exe' => $exe,
            'cwd' => $cwd, 'cmdline' => $cmdline, 'after' => $last];
    }
    ksort($members, SORT_NUMERIC);
    ksort($native, SORT_NUMERIC);
    return ['members' => $members, 'native' => $native];
}

/** Validate complete original argv/title bytes; only trailing native NUL padding is allowed. */
function m4w_proc_exec(array $inventory, int $pid, string $role, bool $ready): array
{
    m4w_expect(in_array($role, ['supervisor', 'master', 'child'], true), 'M4W_FIXED_PROCESS_EXEC_ROLE');
    $native = $inventory['native'][$pid] ?? null;
    m4w_expect(is_array($native) && is_array($native['before']) && is_array($native['after'])
        && m4w_proc_same($native['before'], $native['after']) && m4w_proc_live($native['after']),
        'M4W_EXEC_NATIVE_SAME_LIVE_IDENTITY');
    $exe = $native['exe'];
    $cwd = $native['cwd'];
    $raw = $native['cmdline'];
    m4w_expect(is_string($exe) && $exe === realpath(PHP_BINARY)
        && is_string($cwd) && $cwd === ($role === 'supervisor' ? '/workspace' : '/workspace/crmeb')
        && is_string($raw) && strlen($raw) > 0 && strlen($raw) <= 8192 && str_ends_with($raw, "\0"),
        'M4W_ACTUAL_NATIVE_EXE_CWD_AND_COMPLETE_NUL_CMDLINE');
    $body = rtrim($raw, "\0");
    $launch = implode("\0", [PHP_BINARY, '/workspace/crmeb/think', 'timer', 'start']);
    $masterTitle = 'WorkerMan: master process  start_file=/workspace/crmeb/think';
    $childTitle = 'WorkerMan: worker process  none none';
    if ($role === 'supervisor') {
        m4w_expect($body === implode("\0", ['php', M4W_ENTRY, 'normal']), 'M4W_SUPERVISOR_EXACT_HOST_CREATED_ARGV');
    } elseif ($role === 'master') {
        m4w_expect($body === $launch || $body === $masterTitle, 'M4W_MASTER_EXACT_LAUNCH_OR_ORIGINAL_FRAMEWORK_TITLE');
    } else {
        m4w_expect($body === $launch || $body === $childTitle || (!$ready && $body === $masterTitle),
            'M4W_CHILD_EXACT_LAUNCH_OR_ORIGINAL_FRAMEWORK_TITLE');
    }
    return ['exe' => $exe, 'cwd' => $cwd, 'cmdline' => $raw];
}

/** Startup title evolution is allowed; ready-to-preTERM native executable facts are stable. */
function m4w_proc_exec_observe(array $observation, bool $ready): array
{
    $state =& $GLOBALS['m4w_process_state'];
    $inventory = $observation['inventory'];
    $supervisor = m4w_proc_exec($inventory, $state['supervisor']['pid'], 'supervisor', true);
    m4w_expect($supervisor === $state['exec_supervisor'], 'M4W_SUPERVISOR_NATIVE_EXEC_STABLE');
    $master = m4w_proc_exec($inventory, $state['master_initial']['pid'], 'master', $ready);
    if ($state['exec_initial_master'] === null) {
        $state['exec_initial_master'] = $master;
    }
    $child = null;
    if ($state['child_initial'] !== null) {
        $child = m4w_proc_exec($inventory, $state['child_initial']['pid'], 'child', $ready);
        if ($state['exec_initial_child'] === null) {
            $state['exec_initial_child'] = $child;
        }
    }
    $facts = ['master' => $master, 'child' => $child];
    if ($ready) {
        m4w_expect(is_array($child), 'M4W_READY_ORIGINAL_CHILD_NATIVE_EXEC');
        if ($state['exec_ready'] === null) {
            $state['exec_ready'] = $facts;
        } else {
            m4w_expect($facts === $state['exec_ready'], 'M4W_READY_TO_PRETERM_SAME_NATIVE_EXE_CWD_FULL_CMDLINE');
        }
    }
    return $facts;
}
function m4w_proc_preflight(): array
{
    m4w_expect(PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300 && PHP_INT_SIZE === 8
        && PHP_OS_FAMILY === 'Linux' && PHP_SAPI === 'cli', 'M4W_ACTUAL_PHP82_64BIT_LINUX_CLI');
    $functions = ['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'get_resource_id',
        'stream_set_blocking', 'stream_select', 'fread', 'feof', 'fclose', 'hrtime', 'usleep',
        'getmypid', 'posix_getpid', 'posix_getppid', 'posix_getuid', 'posix_getpwuid', 'posix_geteuid', 'posix_getpgid', 'posix_getsid',
        'posix_kill', 'pcntl_fork', 'pcntl_signal', 'pcntl_signal_dispatch', 'pcntl_wait', 'pcntl_alarm',
        'file_get_contents', 'readlink', 'realpath', 'lstat', 'glob', 'json_decode', 'json_encode', 'serialize', 'base64_decode'];
    $disabled = array_filter(array_map('trim', explode(',', strtolower((string)ini_get('disable_functions')))));
    foreach ($functions as $function) {
        m4w_expect(function_exists($function) && !in_array(strtolower($function), $disabled, true),
            'M4W_REQUIRED_NATIVE_FUNCTION_' . strtoupper($function));
    }
    m4w_expect(extension_loaded('pcntl') && extension_loaded('posix') && defined('SIGTERM') && SIGTERM === 15
        && is_dir('/proc') && !is_link('/proc') && realpath('/proc') === '/proc', 'M4W_ACTUAL_PROCESS_CAPABILITY');
    $supervisor = audit_app_proc_stat(getmypid());
    m4w_expect(is_array($supervisor) && $supervisor['pid'] === posix_getpid()
        && $supervisor['ppid'] === posix_getppid() && $supervisor['pgid'] === posix_getpgid(0)
        && $supervisor['sid'] === posix_getsid(0) && m4w_proc_live($supervisor)
        && preg_match('/\A[1-9][0-9]*\z/D', $supervisor['starttime']) === 1, 'M4W_REAL_SUPERVISOR_IDENTITY');
    $inventory = m4w_proc_inventory();
    m4w_expect(array_keys($inventory['members']) === [$supervisor['pid']]
        && m4w_proc_same($supervisor, $inventory['members'][$supervisor['pid']]), 'M4W_INITIAL_NAMESPACE_ONLY_SUPERVISOR');
    return ['supervisor' => $supervisor, 'inventory' => $inventory,
        'exec_supervisor' => m4w_proc_exec($inventory, $supervisor['pid'], 'supervisor', true)];
}

/** Preserve every sample; live identity checks are separate from cleanup collection. */
function m4w_proc_observe(string $stage, bool $requireLive): array
{
    $state =& $GLOBALS['m4w_process_state'];
    $inventory = m4w_proc_inventory();
    $observed = ['stage' => $stage, 'monotonic_ns' => hrtime(true), 'inventory' => $inventory];
    $state['proc_observations'][] = $observed;
    $supervisor = $inventory['members'][$state['supervisor']['pid']] ?? null;
    m4w_expect(is_array($supervisor) && m4w_proc_same($state['supervisor'], $supervisor)
        && m4w_proc_live($supervisor), 'M4W_SAME_LIVE_SUPERVISOR');
    if (!$requireLive) {
        foreach ($inventory['members'] as $pid => $member) {
            if ($pid === $supervisor['pid']) {
                continue;
            }
            $known = ($state['master_initial'] !== null && m4w_proc_same($state['master_initial'], $member))
                || ($state['child_initial'] !== null && m4w_proc_same($state['child_initial'], $member));
            m4w_expect($known, 'M4W_DRAIN_ONLY_SAME_KNOWN_IDENTITIES_INCLUDING_POSSIBLE_ZOMBIE_MASTER');
        }
        return $observed;
    }
    $master = $inventory['members'][$state['master_initial']['pid']] ?? null;
    m4w_expect(is_array($master) && m4w_proc_same($state['master_initial'], $master)
        && m4w_proc_live($master), 'M4W_SAME_LIVE_MASTER_BEFORE_TERM');
    $children = [];
    foreach ($inventory['members'] as $pid => $member) {
        if ($pid === $supervisor['pid'] || $pid === $master['pid']) {
            continue;
        }
        m4w_expect($member['ppid'] === $master['pid'] && m4w_proc_live($member), 'M4W_ONLY_ORIGINAL_LIVE_MASTER_CHILD');
        $children[$pid] = $member;
    }
    m4w_expect(count($children) <= 1, 'M4W_ONE_ORIGINAL_WORKER_CHILD');
    if ($state['child_initial'] === null && count($children) === 1) {
        $state['child_initial'] = array_values($children)[0];
    }
    if ($state['child_initial'] !== null) {
        $child = $children[$state['child_initial']['pid']] ?? null;
        m4w_expect(count($children) === 1 && is_array($child)
            && m4w_proc_same($state['child_initial'], $child), 'M4W_CHILD_NEVER_REPLACED_OR_EARLY_TERMINAL');
    }
    return $observed;
}

function m4w_proc_pidfile(bool $required): ?array
{
    $state =& $GLOBALS['m4w_process_state'];
    $path = '/workspace/crmeb/runtime/timer.pid';
    clearstatcache(true, $path);
    $first = @lstat($path);
    if ($first === false) {
        m4w_expect(!$required && !is_link($path), 'M4W_ORIGINAL_MASTER_PID_FILE_PRESENT');
        return null;
    }
    // Only the actual not-ready optional create-before-write window can return no PID.
    if (!$required && $state['exec_ready'] === null && $first['size'] === 0) {
        m4w_expect(($first['mode'] & 0170000) === 0100000 && $first['nlink'] === 1
            && $first['uid'] === posix_geteuid() && !is_link($path) && realpath($path) === $path,
            'M4W_OPTIONAL_NOT_READY_EMPTY_PID_REAL_OWNER_CANONICAL_FILE');
        $pendingRaw = file_get_contents($path);
        clearstatcache(true, $path);
        $pendingLast = lstat($path);
        $pendingFields = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
        $pendingBefore = array_intersect_key($first, array_flip($pendingFields));
        $pendingAfter = is_array($pendingLast) ? array_intersect_key($pendingLast, array_flip($pendingFields)) : [];
        $GLOBALS['m4w_optional_pid_pending_reads'][] = ['path' => $path, 'raw' => $pendingRaw,
            'before' => $first, 'after' => $pendingLast, 'required' => $required, 'ready' => false];
        m4w_expect(is_string($pendingRaw) && $pendingRaw === '' && count($pendingBefore) === 9
            && count($pendingAfter) === 9 && $pendingBefore === $pendingAfter
            && !is_link($path) && realpath($path) === $path,
            'M4W_OPTIONAL_NOT_READY_EMPTY_PID_COMPLETE_STABLE_NATIVE_READ');
        return null;
    }
    m4w_expect(($first['mode'] & 0170000) === 0100000 && $first['nlink'] === 1
        && $first['uid'] === posix_geteuid() && !is_link($path) && realpath($path) === $path
        && $first['size'] > 0 && $first['size'] <= 32, 'M4W_ORIGINAL_PID_FILE_REGULAR_COMPLETE');
    $raw = file_get_contents($path);
    clearstatcache(true, $path);
    $last = lstat($path);
    $fields = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'];
    m4w_expect(is_string($raw) && strlen($raw) === $first['size'] && is_array($last)
        && array_intersect_key($first, array_flip($fields)) === array_intersect_key($last, array_flip($fields))
        && $raw === (string)$state['master_initial']['pid'], 'M4W_PID_FILE_EXACT_SAME_MASTER_READBACK');
    return ['path' => $path, 'raw' => $raw, 'metadata' => ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw),
        'mode' => $first['mode'] & 0777, 'uid' => $first['uid'], 'nlink' => $first['nlink']],
        'stat' => array_intersect_key($first, array_flip($fields))];
}

/** Record both pipe results and the sole startup status before live assertions. */
function m4w_proc_start_capture(): void
{
    $state =& $GLOBALS['m4w_process_state'];
    if (!is_resource($state['process'])) {
        return;
    }
    if ($state['resource_id'] === null) {
        $state['resource_id'] = get_resource_id($state['process']);
        $state['signal']['resource_id'] = $state['resource_id'];
    }
    foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $name) {
        if ($state['pipe_nonblocking'][$name] !== null) {
            continue;
        }
        $state['pipe_nonblocking'][$name] = false;
        try {
            if (isset($state['pipes'][$index]) && is_resource($state['pipes'][$index])) {
                $state['pipe_nonblocking'][$name] = stream_set_blocking($state['pipes'][$index], false);
            }
        } catch (Throwable $error) {
            $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
        }
    }
    if (!$state['startup_status_read']) {
        $state['startup_status_read'] = true;
        try {
            $state['status_start'] = proc_get_status($state['process']);
        } catch (Throwable $error) {
            $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
        }
    }
    $status = $state['status_start'];
    if ($state['master_initial'] === null && is_array($status)
        && is_int($status['pid'] ?? null) && $status['pid'] > 1) {
        try {
            $state['master_initial'] = audit_app_proc_stat($status['pid']);
        } catch (Throwable $error) {
            $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
        }
    }
}

/** Ready-pipe reads also preserve ordinary cleanup when nonblocking setup failed. */
function m4w_proc_drain(): void
{
    $state =& $GLOBALS['m4w_process_state'];
    foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $name) {
        if (!isset($state['pipes'][$index]) || !is_resource($state['pipes'][$index])) {
            $state['stream_eof'][$name] = true;
            $state['cleanup_problems'][] = 'M4W_NATIVE_' . strtoupper($name) . '_PIPE_UNAVAILABLE';
            continue;
        }
        for ($chunk = 0; $chunk < 32; ++$chunk) {
            $read = [$state['pipes'][$index]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 0);
            if ($ready === false) {
                $state['cleanup_problems'][] = 'M4W_NATIVE_' . strtoupper($name) . '_SELECT_FAILED';
                break;
            }
            if ($ready === 0) {
                break;
            }
            $raw = @fread($state['pipes'][$index], 8192);
            if (!is_string($raw)) {
                $state['cleanup_problems'][] = 'M4W_NATIVE_' . strtoupper($name) . '_READ_FAILED';
                break;
            }
            $state['streams'][$name] .= $raw;
            $state['stream_eof'][$name] = feof($state['pipes'][$index]);
            if ($state['stream_eof'][$name] || $raw === '') {
                break;
            }
        }
    }
    if (strlen($state['streams']['stdout']) + strlen($state['streams']['stderr']) > 67108864) {
        $state['oversized'] = true;
    }
}
function m4w_proc_status_live(array $status, int $pid): bool
{
    return ($status['pid'] ?? null) === $pid && ($status['running'] ?? null) === true
        && ($status['stopped'] ?? null) === false && ($status['signaled'] ?? null) === false
        && ($status['exitcode'] ?? null) === -1;
}

/** One pre-TERM status, then at most one TERM on the original owned resource. */
function m4w_proc_signal(bool $strict = true): void
{
    $state =& $GLOBALS['m4w_process_state'];
    m4w_expect(is_resource($state['process']) && get_resource_id($state['process']) === $state['resource_id'],
        'M4W_SIGNAL_SAME_OWNED_RESOURCE');
    m4w_expect(!$state['signal']['attempted'], 'M4W_ONE_NORMAL_SIGNAL_ATTEMPT');
    if (!$state['pre_signal_status_read']) {
        $state['pre_signal_status_read'] = true;
        try {
            $state['status_before_signal'] = proc_get_status($state['process']);
        } catch (Throwable $error) {
            $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
            if ($strict) {
                throw $error;
            }
        }
    }
    $status = $state['status_before_signal'];
    $master = null;
    $child = null;
    try {
        if (is_array($status) && is_int($status['pid'] ?? null) && $status['pid'] > 1) {
            $master = audit_app_proc_stat($status['pid']);
            if ($state['master_initial'] === null && is_array($master)) {
                $state['master_initial'] = $master;
            }
        }
        if ($state['child_initial'] !== null) {
            $child = audit_app_proc_stat($state['child_initial']['pid']);
        }
    } catch (Throwable $error) {
        $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
        if ($strict) {
            throw $error;
        }
    }
    $state['master_before_signal'] = $master;
    $state['child_before_signal'] = $child;
    $live = is_array($status) && is_array($state['master_initial'])
        && m4w_proc_status_live($status, $state['master_initial']['pid']) && is_array($master)
        && m4w_proc_same($state['master_initial'], $master) && m4w_proc_live($master)
        && $master['ppid'] === $state['supervisor']['pid']
        && ($state['child_initial'] === null || (is_array($child)
            && m4w_proc_same($state['child_initial'], $child) && m4w_proc_live($child)));
    if ($strict) {
        m4w_expect($live, 'M4W_REAL_SAME_RESOURCE_MASTER_CHILD_LIVE_IMMEDIATELY_BEFORE_TERM');
        $observation = m4w_proc_observe('immediately_before_term_native_exec', true);
        $state['exec_before_signal'] = m4w_proc_exec_observe($observation, true);
    } elseif (!$live) {
        $state['cleanup_problems'][] = 'M4W_EXCEPTION_CLEANUP_NOT_PROVED_LIVE_BY_SAVED_PRETERM_STATUS';
    }
    $state['signal']['monotonic_ns'] = hrtime(true);
    $state['signal']['attempted'] = true;
    $returned = proc_terminate($state['process'], SIGTERM);
    $state['signal']['returned'] = $returned;
    if ($strict) {
        m4w_expect(is_bool($returned) && $returned, 'M4W_REAL_ONCE_NORMAL_TERM_RETURN');
    } elseif ($returned !== true) {
        $state['cleanup_problems'][] = 'M4W_EXCEPTION_CLEANUP_NATIVE_TERM_RETURNED_FALSE';
    }
}
function m4w_proc_stream_summaries(): array
{
    $state =& $GLOBALS['m4w_process_state'];
    $out = [];
    foreach (['stdout', 'stderr'] as $name) {
        $out[$name] = ['bytes' => strlen($state['streams'][$name]), 'sha256' => hash('sha256', $state['streams'][$name]),
            'eof' => $state['stream_eof'][$name], 'closed' => $state['stream_closed'][$name]];
    }
    return $out;
}

/** No resource, object, or child environment is returned by this projection. */
function m4w_proc_private(): array
{
    $state =& $GLOBALS['m4w_process_state'];
    if (!is_array($state)) {
        return ['owned' => false];
    }
    $keys = ['command', 'cwd', 'resource_id', 'started_ns', 'finished_ns', 'supervisor', 'initial_inventory',
        'exec_supervisor', 'exec_initial_master', 'exec_initial_child', 'exec_ready', 'exec_before_signal',
        'startup_status_read', 'status_start', 'pipe_nonblocking', 'master_initial', 'child_initial', 'status_before_signal', 'master_before_signal',
        'child_before_signal', 'pid_file_before_signal', 'signal', 'close_attempted', 'proc_close_exit',
        'streams', 'stream_eof', 'stream_closed', 'proc_observations', 'absence_after_close', 'runtime_initial',
        'runtime_at_tick', 'runtime_final', 'cache_at_tick', 'cache_at_tick_public', 'event_records_at_tick',
        'event_records_final', 'log_evidence', 'oversized', 'cleanup_window_exceeded', 'cleanup_problems'];
    $out = ['owned' => true];
    foreach ($keys as $key) {
        $out[$key] = $state[$key];
    }
    return $out;
}

/**
 * Idempotent shutdown hook. Failure does not permit force, retry, terminal status polling,
 * or waiting for a zombie master to disappear before reaping the original resource.
 */
function m4w_process_cleanup(): array
{
    $state =& $GLOBALS['m4w_process_state'];
    if (!is_array($state) || $state['closed'] || $state['cleanup_running']) {
        return m4w_proc_private();
    }
    $state['cleanup_running'] = true;
    try {
        if (is_resource($state['process'])) {
            m4w_proc_start_capture();
        }
        if (is_resource($state['process']) && !$state['signal']['attempted']) {
            try {
                m4w_proc_signal(false);
            } catch (Throwable $error) {
                $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
            }
        }
        if (is_resource($state['process'])) {
            do {
                m4w_proc_drain();
                try {
                    m4w_proc_observe('normal_drain', false);
                } catch (Throwable $error) {
                    $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
                }
                if (hrtime(true) - $state['started_ns'] > M4W_WINDOW_SECONDS * 1000000000) {
                    $state['cleanup_window_exceeded'] = true;
                }
                if ($state['stream_eof']['stdout'] && $state['stream_eof']['stderr']) {
                    break;
                }
                usleep(100000);
            } while (true);
            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $name) {
                if (isset($state['pipes'][$index]) && is_resource($state['pipes'][$index])) {
                    $state['stream_closed'][$name] = fclose($state['pipes'][$index]);
                }
                $state['pipes'][$index] = null;
            }
            $state['close_attempted'] = true;
            $code = proc_close($state['process']);
            $state['proc_close_exit'] = $code;
            $state['process'] = null;
            $state['closed'] = true;
            $state['finished_ns'] = hrtime(true);
            try {
                $observation = m4w_proc_observe('after_actual_proc_close', false);
                $masterAbsent = $state['master_initial'] === null ? null
                    : !is_dir('/proc/' . $state['master_initial']['pid']);
                $childAbsent = $state['child_initial'] === null ? null
                    : !is_dir('/proc/' . $state['child_initial']['pid']);
                $pidPath = '/workspace/crmeb/runtime/timer.pid';
                clearstatcache(true, $pidPath);
                $state['absence_after_close'] = ['monotonic_ns' => hrtime(true), 'master_absent' => $masterAbsent,
                    'child_absent' => $childAbsent, 'pid_file_absent' => @lstat($pidPath) === false && !is_link($pidPath),
                    'namespace_only_supervisor' => array_keys($observation['inventory']['members']) === [$state['supervisor']['pid']]];
            } catch (Throwable $error) {
                $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
            }
        }
        try {
            $state['runtime_final'] = m4w_files_capture('/workspace/crmeb/runtime');
        } catch (Throwable $error) {
            $state['cleanup_problems'][] = get_class($error) . ':' . $error->getMessage();
        }
    } finally {
        $state['cleanup_running'] = false;
    }
    return m4w_proc_private();
}

function m4w_log_native_file(array $capture, string $relative): string
{
    m4w_proc_keys($capture, ['file_map', 'files'], 'M4W_RUNTIME_CAPTURE_EXACT_TWO');
    m4w_expect(array_keys($capture['file_map']) === array_keys($capture['files'])
        && array_key_exists($relative, $capture['files']), 'M4W_RUNTIME_COMPLETE_SAME_MEMBER_SET');
    $file = $capture['files'][$relative];
    m4w_proc_keys($file, ['metadata', 'base64'], 'M4W_RUNTIME_NATIVE_FILE_EXACT_TWO');
    $metadata = $capture['file_map'][$relative];
    m4w_proc_keys($metadata, ['bytes', 'sha256', 'mode', 'uid', 'nlink'], 'M4W_RUNTIME_ORIGINAL_FIVE_METADATA');
    $raw = base64_decode($file['base64'], true);
    m4w_expect($file['metadata'] === $metadata && is_string($raw) && base64_encode($raw) === $file['base64']
        && strlen($raw) === $metadata['bytes'] && hash('sha256', $raw) === $metadata['sha256'], 'M4W_RUNTIME_ALL_ACTUAL_BYTES_BOUND');
    return $raw;
}

/** Validate objects temporarily, persist only original JSON and native arrays/scalars. */
function m4w_log_event(string $json): array
{
    m4w_expect(strlen($json) > 0 && strlen($json) <= 8192 && !str_contains($json, "\r")
        && !str_contains($json, "\n"), 'M4W_ACTUAL_PRODUCT_JSON_BOUND');
    $event = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
    m4w_expect($event instanceof stdClass, 'M4W_PRODUCT_EVENT_ACTUAL_OBJECT');
    m4w_proc_keys(get_object_vars($event), ['schema', 'phase', 'pid', 'worker_id', 'loop_class', 'loop_object_id',
        'timer_id', 'monotonic_ns', 'active', 'busy', 'closed', 'owned', 'worker_count', 'slot', 'count', 'socketless', 'details'],
        'M4W_PRODUCT_EVENT_EXACT_SEVENTEEN');
    m4w_expect($event->schema === 1 && in_array($event->phase, ['timer_started', 'scan_returned', 'timer_removed'], true)
        && is_int($event->pid) && $event->pid > 1 && is_string($event->worker_id)
        && strlen($event->worker_id) > 0 && strlen($event->worker_id) <= 128
        && is_string($event->loop_class) && preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]*\z/D', $event->loop_class) === 1
        && strlen($event->loop_class) <= 256 && is_int($event->loop_object_id) && $event->loop_object_id > 0
        && is_int($event->timer_id) && $event->timer_id > 0 && is_int($event->monotonic_ns) && $event->monotonic_ns > 0
        && $event->owned === true && $event->worker_count === 1 && $event->slot === 0 && $event->count === 1
        && $event->socketless === true && $event->details instanceof stdClass, 'M4W_ACTUAL_OWNED_ORIGINAL_TIMER_FIELDS');
    $flags = ['timer_started' => [true, false, false], 'scan_returned' => [true, true, false],
        'timer_removed' => [false, false, true]][$event->phase];
    m4w_expect([$event->active, $event->busy, $event->closed] === $flags, 'M4W_PHASE_ACTUAL_ACTIVE_BUSY_CLOSED');
    $details = get_object_vars($event->details);
    if ($event->phase === 'timer_started') {
        m4w_proc_keys($details, ['period_seconds', 'batch', 'add_started_ns'], 'M4W_STARTED_DETAILS_EXACT_THREE');
        m4w_expect($event->details->period_seconds === 60 && $event->details->batch === 100
            && is_int($event->details->add_started_ns) && $event->details->add_started_ns > 0
            && $event->details->add_started_ns <= $event->monotonic_ns, 'M4W_ORIGINAL_PERIOD_BATCH_AND_REAL_ADD_TIME');
    } elseif ($event->phase === 'scan_returned') {
        m4w_proc_keys($details, ['scan', 'cursor_saved'], 'M4W_SCAN_DETAILS_EXACT_TWO');
        m4w_expect($event->details->cursor_saved === true && $event->details->scan instanceof stdClass,
            'M4W_REAL_SCAN_AND_CURSOR_SAVED_OBJECT');
        $scan = get_object_vars($event->details->scan);
        m4w_proc_keys($scan, ['processed', 'received', 'already_received', 'not_due', 'pending', 'next_due_at',
            'next_ship_id', 'wrapped', 'aftersale_paused', 'fulfillment_closed'], 'M4W_ORIGINAL_SCAN_EXACT_TEN');
        foreach (['processed', 'received', 'already_received', 'not_due', 'pending', 'next_due_at',
            'aftersale_paused', 'fulfillment_closed'] as $key) {
            m4w_expect($scan[$key] === 0, 'M4W_ORIGINAL_ZERO_SCAN_' . strtoupper($key));
        }
        m4w_expect($scan['next_ship_id'] === '0' && $scan['wrapped'] === true, 'M4W_ORIGINAL_ZERO_SCAN_CURSOR_AND_WRAP');
    } else {
        m4w_proc_keys($details, [], 'M4W_REMOVED_DETAILS_ACTUAL_EMPTY_OBJECT');
    }
    m4w_expect(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) === $json,
        'M4W_ORIGINAL_CANONICAL_JSON_WITHOUT_DUPLICATE_KEYS');
    return json_decode($json, true, 128, JSON_THROW_ON_ERROR);
}

/** All marked complete lines from all actual runtime members, including rotations. */
function m4w_log_records(array $capture, bool $complete): array
{
    m4w_proc_keys($capture, ['file_map', 'files'], 'M4W_LOG_COMPLETE_CAPTURE_SHAPE');
    m4w_expect(array_keys($capture['file_map']) === array_keys($capture['files']), 'M4W_LOG_ALL_RUNTIME_MEMBERS_PRESENT');
    $marker = 'MARKETPLACE_FULFILLMENT_WORKER_EVENT ';
    $records = [];
    foreach ($capture['file_map'] as $relative => $metadata) {
        $raw = m4w_log_native_file($capture, $relative);
        $offset = 0;
        while ($offset < strlen($raw)) {
            $lf = strpos($raw, "\n", $offset);
            if ($lf === false) {
                m4w_expect(!$complete || !str_contains(substr($raw, $offset), $marker), 'M4W_PRODUCT_EVENT_COMPLETE_FINAL_LINE');
                break;
            }
            $line = substr($raw, $offset, $lf - $offset + 1);
            if (str_contains($line, $marker)) {
                m4w_expect(preg_match('~\Alog/(?:[0-9]+-)?[0-9]{8}\.log\z~D', $relative) === 1
                    && substr_count($line, $marker) === 1 && !str_contains($line, "\r"), 'M4W_ORIGINAL_FILE_CHANNEL_EVENT_LOCATION');
                $body = substr($line, 0, -1);
                $first = strpos($body, '|');
                $second = $first === false ? false : strpos($body, '|', $first + 1);
                m4w_expect($first !== false && $second !== false
                    && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', substr($body, 0, $first)) === 1
                    && substr($body, $first + 1, $second - $first - 1) === 'info'
                    && str_starts_with(substr($body, $second + 1), $marker), 'M4W_ORIGINAL_TIME_INFO_MESSAGE_FORMAT');
                $json = substr($body, $second + 1 + strlen($marker));
                $event = m4w_log_event($json);
                $records[] = ['locator' => ['file' => $relative, 'file_sha256' => $metadata['sha256'], 'offset' => $offset,
                    'bytes' => strlen($line), 'sha256' => hash('sha256', $line), 'event_json' => $json], 'event' => $event];
            }
            $offset = $lf + 1;
        }
    }
    usort($records, static fn(array $a, array $b): int => $a['event']['monotonic_ns'] <=> $b['event']['monotonic_ns']);
    return $records;
}

function m4w_log_sequence(array $records, bool $complete): array
{
    $state =& $GLOBALS['m4w_process_state'];
    $started = null;
    $scans = [];
    $removed = [];
    $lastNs = 0;
    $observedNs = hrtime(true);
    foreach ($records as $record) {
        $event = $record['event'];
        m4w_expect(is_array($state['child_initial']) && $event['pid'] === $state['child_initial']['pid']
            && $event['monotonic_ns'] > $lastNs && $event['monotonic_ns'] <= $observedNs,
            'M4W_ALL_EVENTS_SAME_REAL_CHILD_AND_STRICT_TIME_ORDER');
        $lastNs = $event['monotonic_ns'];
        if ($event['phase'] === 'timer_started') {
            m4w_expect($started === null && $scans === [] && $removed === [], 'M4W_ONE_FIRST_ORIGINAL_TIMER_START');
            $started = $record;
        } else {
            m4w_expect($started !== null, 'M4W_EVENT_AFTER_REAL_START');
            foreach (['pid', 'worker_id', 'loop_class', 'loop_object_id', 'timer_id'] as $key) {
                m4w_expect($event[$key] === $started['event'][$key], 'M4W_SAME_OWNED_LIFECYCLE_' . strtoupper($key));
            }
            if ($event['phase'] === 'scan_returned') {
                m4w_expect($removed === [] && $event['monotonic_ns'] - $started['event']['details']['add_started_ns'] >= 60000000000,
                    'M4W_REAL_NATURAL_SCAN_AFTER_ORIGINAL_SIXTY_SECONDS');
                $scans[] = $record;
            } else {
                m4w_expect($complete && $state['signal']['attempted'] && is_int($state['signal']['monotonic_ns'])
                    && $event['monotonic_ns'] >= $state['signal']['monotonic_ns'] && $removed === [] && $scans !== [],
                    'M4W_ONE_ORIGINAL_REMOVAL_AFTER_OWNED_TERM_AND_NATURAL_SCAN');
                $removed[] = $record;
            }
        }
    }
    if ($complete) {
        m4w_expect($started !== null && $scans !== [] && count($removed) === 1
            && $records[count($records) - 1]['event']['phase'] === 'timer_removed', 'M4W_COMPLETE_ORIGINAL_THREE_PHASE_LIFECYCLE');
    }
    return ['started' => $started, 'scans' => $scans, 'removed' => $removed];
}

function m4w_log_retained_tick(array $finalRecords): void
{
    $state =& $GLOBALS['m4w_process_state'];
    foreach ($state['event_records_at_tick'] as $original) {
        $matches = [];
        foreach ($finalRecords as $final) {
            if ($final['locator']['event_json'] === $original['locator']['event_json']) {
                $matches[] = $final;
            }
        }
        m4w_expect(count($matches) === 1 && $matches[0]['locator']['bytes'] === $original['locator']['bytes']
            && $matches[0]['locator']['sha256'] === $original['locator']['sha256'], 'M4W_ALL_OBSERVED_TICK_RECORD_BYTES_RETAINED');
    }
}

function m4w_log_master_stop(array $capture): array
{
    $state =& $GLOBALS['m4w_process_state'];
    $raw = m4w_log_native_file($capture, 'timer.log');
    m4w_expect($raw !== '' && str_ends_with($raw, "\n"), 'M4W_COMPLETE_ORIGINAL_WORKERMAN_LOG');
    $prefix = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} pid:'
        . preg_quote((string)$state['master_initial']['pid'], '/') . ' /D';
    $stopping = [];
    $stopped = [];
    $offset = 0;
    foreach (explode("\n", substr($raw, 0, -1)) as $body) {
        $line = $body . "\n";
        if (str_contains($body, ' exit with status ')) {
            m4w_expect(false, 'M4W_ORIGINAL_MASTER_REPORTED_NONZERO_CHILD_STATUS');
        }
        if (preg_match($prefix, $body, $match) === 1) {
            $message = substr($body, strlen($match[0]));
            if ($message === 'Workerman[think] stopping ...') {
                $stopping[] = ['offset' => $offset, 'bytes' => strlen($line), 'sha256' => hash('sha256', $line)];
            }
            if ($message === 'Workerman[think] has been stopped') {
                $stopped[] = ['offset' => $offset, 'bytes' => strlen($line), 'sha256' => hash('sha256', $line)];
            }
        }
        $offset += strlen($line);
    }
    m4w_expect(count($stopping) === 1 && count($stopped) === 1 && $stopping[0]['offset'] < $stopped[0]['offset'],
        'M4W_SAME_MASTER_ORIGINAL_NORMAL_STOP_LOG_SEQUENCE');
    return ['file' => 'timer.log', 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw),
        'stopping' => $stopping[0], 'stopped' => $stopped[0]];
}

function m4w_proc_cache_tick(): array
{
    $capture = m4w_cache_native();
    $GLOBALS['m4w_process_state']['cache_at_tick'] = $capture;
    m4w_proc_keys($capture, ['reply', 'time_ms', 'rows'], 'M4W_TICK_NATIVE_CACHE_EXACT_THREE');
    m4w_expect(is_string($capture['time_ms']) && preg_match('/\A[1-9][0-9]*\z/D', $capture['time_ms']) === 1,
        'M4W_TICK_ACTUAL_REDIS_TIME');
    $keys = array_keys($capture['rows']);
    $wanted = M4W_CACHE_KEYS;
    sort($keys, SORT_STRING);
    sort($wanted, SORT_STRING);
    m4w_expect($keys === $wanted, 'M4W_TICK_EXACT_THREE_PHYSICAL_KEYS');
    foreach ($capture['rows'] as $row) {
        m4w_proc_keys($row, ['type', 'expires', 'value', 'dump'], 'M4W_TICK_CACHE_NATIVE_ROW_EXACT_FOUR');
        m4w_expect(is_string($row['type']) && is_int($row['expires'])
            && (($row['type'] === 'string' && is_string($row['dump']))
                || ($row['type'] === 'set' && $row['dump'] === false)), 'M4W_TICK_ACTUAL_NATIVE_TYPE_EXPIRY_DUMP');
    }
    $crontab = $capture['rows']['c:crontabCache'];
    $cursor = $capture['rows']['c:marketplace_fulfillment_receive_cursor'];
    $tag = $capture['rows']['c:CRMEB:827bf7a6b0b8d160bd116898762da4c5'];
    m4w_expect($crontab['type'] === 'string' && $crontab['expires'] === -1 && $crontab['value'] === serialize([]),
        'M4W_TICK_REAL_EMPTY_TASK_CACHE_NO_TTL');
    m4w_expect($cursor['type'] === 'string' && $cursor['expires'] === -1
        && $cursor['value'] === serialize(['due_at' => 0, 'ship_id' => '0']), 'M4W_TICK_REAL_NATIVE_CURSOR_ZERO_NO_TTL');
    m4w_expect($tag['type'] === 'set' && is_array($tag['value'])
        && in_array('c:marketplace_fulfillment_receive_cursor', $tag['value'], true), 'M4W_TICK_REAL_NATIVE_CURSOR_TAG_MEMBER');
    return $capture;
}

/** Finite runtime members from the unchanged Timer/SystemCrontab/File/Worker sources only. */
function m4w_runtime_bytes(array $capture,string $relative):string
{
 m4w_proc_keys($capture,['file_map','files'],'M4W_RUNTIME_DELTA_COMPLETE_CAPTURE');
 m4w_expect(array_keys($capture['file_map'])===array_keys($capture['files'])&&isset($capture['files'][$relative]),'M4W_RUNTIME_DELTA_COMPLETE_MEMBER');
 $file=$capture['files'][$relative];m4w_proc_keys($file,['metadata','base64'],'M4W_RUNTIME_DELTA_ORIGINAL_FILE_FIELDS');
 $raw=base64_decode($file['base64'],true);m4w_expect(is_string($raw)&&base64_encode($raw)===$file['base64']
  &&$file['metadata']===$capture['file_map'][$relative]&&strlen($raw)===$file['metadata']['bytes']
  &&hash('sha256',$raw)===$file['metadata']['sha256'],'M4W_RUNTIME_DELTA_ACTUAL_COMPLETE_BYTES');return $raw;
}
function m4w_runtime_directories():array
{
 $root='/workspace/crmeb/runtime';$paths=[''=>$root];
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
 foreach($iterator as$file){$path=$file->getPathname();m4w_expect(!is_link($path)&&str_starts_with($path,$root.'/'),'M4W_RUNTIME_DIRECTORIES_NONLINK_DESCENDANTS');
  if(is_dir($path))$paths[substr($path,strlen($root)+1)]=$path;}
 $out=[];$fields=array_flip(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']);
 foreach($paths as$relative=>$path){if($relative!=='')m4w_relative($relative);clearstatcache(true,$path);$before=lstat($path);
  m4w_expect(is_array($before)&&($before['mode']&0170000)===0040000&&!is_link($path)&&realpath($path)===$path
   &&$before['uid']===posix_geteuid(),'M4W_RUNTIME_DIRECTORY_ACTUAL_OWNER_IDENTITY');
  $members=scandir($path);clearstatcache(true,$path);$after=lstat($path);$stable=array_intersect_key($before,$fields);
  m4w_expect(is_array($members)&&is_array($after)&&count($stable)===9&&array_intersect_key($after,$fields)===$stable,
   'M4W_RUNTIME_DIRECTORY_COMPLETE_STABLE_READ');
  $out[$relative]=['stable'=>$stable,'members'=>$members,'before'=>$before,'after'=>$after];
 }ksort($out,SORT_STRING);return $out;
}
function m4w_runtime_directory_projection(array $directories):array
{
 $out=[];foreach($directories as$name=>$row)$out[$name]=['stable'=>$row['stable'],'members'=>$row['members']];return $out;
}
function m4w_runtime_day(int $wall):string
{return (new DateTimeImmutable('@'.$wall))->setTimezone(new DateTimeZone('PRC'))->format('Ymd');}
function m4w_runtime_kind(string $relative,int $start,int $end):array
{
 if(in_array($relative,['.timer','timer.pid','timer.log'],true))return ['kind'=>$relative,'base'=>$relative,'level'=>null,'rotation'=>null];
 if(preg_match('~\Alog/(?:(0|[1-9][0-9]{0,9})-)?([0-9]{8})(?:_(error|fail|success|crontab|crmeb|listener))?\.log\z~D',$relative,$m)!==1)
  return ['kind'=>'unrelated','base'=>$relative,'level'=>null,'rotation'=>null];
 $day=$m[2];$date=DateTimeImmutable::createFromFormat('!Ymd',$day,new DateTimeZone('PRC'));
 if(!$date instanceof DateTimeImmutable||$date->format('Ymd')!==$day
  ||!in_array($day,array_values(array_unique([m4w_runtime_day($start),m4w_runtime_day($end)])),true))
  return ['kind'=>'unrelated','base'=>$relative,'level'=>null,'rotation'=>null];
 $rotation=isset($m[1])&&$m[1]!==''?(int)$m[1]:null;
 if($rotation!==null&&($rotation<$start||$rotation>$end))
  return ['kind'=>'unrelated','base'=>$relative,'level'=>null,'rotation'=>null];
 $level=isset($m[3])&&$m[3]!==''?$m[3]:null;$base='log/'.$day.($level===null?'':'_'.$level).'.log';
 return ['kind'=>$rotation===null?'channel':'rotation','base'=>$base,'level'=>$level,'rotation'=>$rotation];
}
function m4w_runtime_format_lines(string $raw,string $kind,?string $level,int $start,int $end):void
{
 // File::save and Worker::log do not escape newlines in message bodies.
 // Validate the native first record framing and final write newline; retain the entire opaque body.
 m4w_expect($raw===''||str_ends_with($raw,"\n"),'M4W_RUNTIME_COMPLETE_NATIVE_APPEND_WITH_OPAQUE_MESSAGE_BYTES');
 if($raw==='')return;$line=explode("\n",$raw,2)[0];$date=substr($line,0,19);
 $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new DateTimeZone('PRC'));
 m4w_expect($parsed instanceof DateTimeImmutable&&$parsed->format('Y-m-d H:i:s')===$date
  &&$parsed->getTimestamp()>=$start&&$parsed->getTimestamp()<=$end,'M4W_RUNTIME_FIRST_NATIVE_RECORD_REAL_PRC_WINDOW');
 if($kind==='timer.log'){
  $pids=[];$state=$GLOBALS['m4w_process_state']??null;
  if(is_array($state))foreach(['master_initial','child_initial']as$key)if(is_array($state[$key]??null))$pids[]=$state[$key]['pid'];
  m4w_expect(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} pid:([1-9][0-9]*) (.*)\z/D',$line,$match)===1
   &&in_array((int)$match[1],$pids,true),'M4W_RUNTIME_FIRST_WORKERMAN_RECORD_REAL_OWNED_PID');
 }else{
  $allowed=$level===null?['warning','info','notice']:[$level];$rest=substr($line,19);$second=strpos($rest,'|',1);
  m4w_expect(str_starts_with($rest,'|')&&$second!==false&&in_array(substr($rest,1,$second-1),$allowed,true),
   'M4W_RUNTIME_FIRST_FILE_RECORD_EXACT_MAIN_OR_APART_LEVEL');
 }
}
function m4w_runtime_complete_logs(array $capture,array $initial,int $start,int $end):void
{
 $groups=[];
 foreach($capture['file_map']as$name=>$metadata){
  $kind=m4w_runtime_kind($name,$start,$end);
  if($name==='timer.log'){
   $raw=m4w_runtime_bytes($capture,$name);$old=isset($initial['file_map'][$name])?m4w_runtime_bytes($initial,$name):'';
   m4w_expect(str_starts_with($raw,$old)&&$metadata['mode']===0622,'M4W_RUNTIME_COMPLETE_WORKERMAN_LOG_ORIGINAL_BYTES_MODE');
   m4w_runtime_format_lines(substr($raw,strlen($old)),'timer.log',null,$start,$end);continue;
  }
  if($kind['kind']==='channel')$groups[$kind['base']]['active']=$name;
  elseif($kind['kind']==='rotation'&&!isset($initial['file_map'][$name]))$groups[$kind['base']]['rotations'][$name]=$kind;
 }
 foreach($groups as$base=>$group){
  $rotations=$group['rotations']??[];uasort($rotations,static fn(array $a,array $b):int=>$a['rotation']<=>$b['rotation']);
  $segments=array_keys($rotations);if(isset($group['active']))$segments[]=$group['active'];$first=true;
  $original=isset($initial['file_map'][$base])?m4w_runtime_bytes($initial,$base):'';
  foreach($segments as$name){
   $raw=m4w_runtime_bytes($capture,$name);$kind=m4w_runtime_kind($name,$start,$end);
   if($first){m4w_expect(str_starts_with($raw,$original),'M4W_RUNTIME_COMPLETE_LOG_ORIGINAL_PREFIX_AFTER_ROTATION');
    $raw=substr($raw,strlen($original));$first=false;}
   m4w_runtime_format_lines($raw,'channel',$kind['level'],$start,$end);
  }
 }
}
function m4w_runtime_directory_members(array $directories,array $capture):void
{
 foreach($directories as$name=>$row){
  $native=array_values(array_diff($row['members'],['.','..']));sort($native,SORT_STRING);
  m4w_expect($native===m4w_runtime_direct_children($capture,$directories,$name),'M4W_RUNTIME_EVERY_DIRECTORY_COMPLETE_CAPTURE_MEMBER_SET');
 }
}
function m4w_runtime_append_metadata(array $before,array $after):void
{
 foreach(['mode','uid','nlink']as$key)m4w_expect($before[$key]===$after[$key],'M4W_RUNTIME_APPEND_OR_RENAME_PRESERVES_FILE_METADATA');
}
function m4w_runtime_direct_children(array $capture,array $directories,string $directory):array
{
 $names=[];$prefix=$directory===''?'':$directory.'/';
 foreach(array_keys($capture['file_map'])as$relative){if(!str_starts_with($relative,$prefix))continue;
  $leaf=substr($relative,strlen($prefix));if(!str_contains($leaf,'/'))$names[]=$leaf;}
 foreach(array_keys($directories)as$relative){if($relative===''||$relative===$directory||!str_starts_with($relative,$prefix))continue;
  $leaf=substr($relative,strlen($prefix));if(!str_contains($leaf,'/'))$names[]=$leaf;}
 sort($names,SORT_STRING);return $names;
}
function m4w_runtime_directories_delta(array $before,array $after,array $oldCapture,array $capture,bool $closed):void
{
 foreach($before as$name=>$old)m4w_expect(isset($after[$name]),'M4W_RUNTIME_NO_OLD_DIRECTORY_DELETION');
 foreach($after as$name=>$new){
  if(!isset($before[$name])){
   m4w_expect($name==='log'&&($new['stable']['mode']&0777)===0700&&$new['stable']['nlink']===2,
    'M4W_RUNTIME_ONLY_SOURCE_FILE_DRIVER_LOG_PARENT_CREATION');
   $start=$GLOBALS['m4w_runtime_delta']['wall_started'];$end=time();
   m4w_expect($new['stable']['dev']===$after['']['stable']['dev']&&$new['stable']['gid']===$after['']['stable']['gid']
    &&$new['stable']['mtime']>=$start&&$new['stable']['mtime']<=$end&&$new['stable']['ctime']>=$start&&$new['stable']['ctime']<=$end,
    'M4W_RUNTIME_NEW_LOG_PARENT_ACTUAL_TMPFS_IDENTITY_AND_WINDOW');
   if($closed)m4w_expect(m4w_runtime_direct_children($capture,$after,'log')!==[],'M4W_RUNTIME_FINAL_LOG_PARENT_HAS_REAL_CHANNEL_FILES');
   continue;
  }
  $old=$before[$name];
  if(!in_array($name,['','log'],true)){m4w_expect($new['stable']===$old['stable']&&$new['members']===$old['members'],
    'M4W_RUNTIME_UNRELATED_DIRECTORY_FULL_METADATA_MEMBERS_UNCHANGED');continue;}
  $beforeNames=m4w_runtime_direct_children($oldCapture,$before,$name);$afterNames=m4w_runtime_direct_children($capture,$after,$name);
  $nativeOld=array_values(array_diff($old['members'],['.','..']));$nativeNew=array_values(array_diff($new['members'],['.','..']));
  sort($nativeOld,SORT_STRING);sort($nativeNew,SORT_STRING);
  m4w_expect($nativeOld===$beforeNames&&$nativeNew===$afterNames,'M4W_RUNTIME_DIRECTORY_NATIVE_COMPLETE_CHILD_SET');
  if($beforeNames===$afterNames){m4w_expect($new['stable']===$old['stable'],'M4W_RUNTIME_PARENT_METADATA_UNCHANGED_WITHOUT_ENTRY_CHANGE');continue;}
  foreach(['dev','ino','mode','uid','gid']as$key)m4w_expect($new['stable'][$key]===$old['stable'][$key],'M4W_RUNTIME_SOURCE_PARENT_FIXED_IDENTITY');
  $newLog=!isset($before['log'])&&isset($after['log']);$links=$old['stable']['nlink']+(($name===''&&$newLog)?1:0);
  $start=$GLOBALS['m4w_runtime_delta']['wall_started'];$end=time();
  m4w_expect($new['stable']['nlink']===$links&&$new['stable']['mtime']>=$old['stable']['mtime']&&$new['stable']['ctime']>=$old['stable']['ctime']
   &&$new['stable']['mtime']>=$start&&$new['stable']['mtime']<=$end&&$new['stable']['ctime']>=$start&&$new['stable']['ctime']<=$end,
   'M4W_RUNTIME_PARENT_SIZE_TIME_ONLY_ACTUAL_SUPPORTED_CHILD_ENTRY_DELTA');
 }
}
function m4w_runtime_initial(array $capture):void
{
 m4w_expect(!isset($GLOBALS['m4w_runtime_delta']),'M4W_RUNTIME_INITIAL_ONCE');
 $directories=m4w_runtime_directories();m4w_runtime_directory_members($directories,$capture);$wall=time();
 $GLOBALS['m4w_runtime_delta']=['schema'=>1,'scope'=>'PRIVATE_SOURCE_FINITE_RUNTIME_MEMBER_DELTA','wall_started'=>$wall,
  'initial'=>$capture,'initial_directories'=>$directories,'previous'=>$capture,'previous_directories'=>$directories,
  'pid_seen'=>false,'timer_started'=>false,'last_heartbeat'=>null,'closed'=>false,'observations'=>[]];
 // No existing member is excluded by extension or directory: all are full original bytes.
 foreach(array_keys($capture['file_map'])as$name)m4w_runtime_bytes($capture,$name);
}
function m4w_runtime_step(array $capture,string $stage,bool $closed=false):void
{
 $proof=&$GLOBALS['m4w_runtime_delta'];m4w_expect(is_array($proof),'M4W_RUNTIME_REAL_INITIAL_BASELINE_REQUIRED');
 $directories=m4w_runtime_directories();$end=time();$start=$proof['wall_started'];$previous=$proof['previous'];$oldDirectories=$proof['previous_directories'];
 m4w_expect(in_array($stage,['natural_observation','natural_tick','normal_closed','sealed_after'],true),'M4W_RUNTIME_FIXED_NATIVE_OBSERVATION_STAGE');
 $complete=$closed||$stage==='natural_tick';
 $record=['stage'=>$stage,'wall'=>$end,'capture'=>$capture,'directories'=>$directories,'verified'=>false];
 $index=count($proof['observations']);$proof['observations'][]=$record;
 m4w_expect($end>=$start,'M4W_RUNTIME_NATIVE_FORWARD_WALL_OBSERVATION');
 m4w_runtime_directory_members($directories,$capture);
 if($proof['closed']){
  m4w_expect($capture===$previous&&m4w_runtime_directory_projection($directories)===m4w_runtime_directory_projection($oldDirectories),
   'M4W_RUNTIME_ALL_MEMBERS_BYTES_METADATA_FIXED_AFTER_REAL_CLOSE');
  $proof['observations'][$index]['verified']=true;return;
 }
 $kinds=[];foreach(array_keys($capture['file_map'])as$name)$kinds[$name]=m4w_runtime_kind($name,$start,$end);
 $rotations=[];foreach($kinds as$name=>$kind)if($kind['kind']==='rotation'&&!isset($previous['file_map'][$name]))$rotations[$kind['base']][$name]=$kind;
 foreach($rotations as$base=>&$items)uasort($items,static fn(array $a,array $b):int=>$a['rotation']<=>$b['rotation']);unset($items);
 $processed=[];
 foreach($rotations as$base=>$items){
  $oldRaw=isset($previous['file_map'][$base])?m4w_runtime_bytes($previous,$base):'';$first=true;
  foreach($items as$name=>$kind){$raw=m4w_runtime_bytes($capture,$name);
   m4w_expect(strlen($raw)>=2097152,'M4W_RUNTIME_ROTATION_REAL_PREAPPEND_FILE_SIZE_THRESHOLD');
   if($first&&isset($previous['file_map'][$base])){
    m4w_expect(str_starts_with($raw,$oldRaw),'M4W_RUNTIME_ROTATION_PRESERVES_ALL_PREVIOUS_BASE_BYTES');
    m4w_runtime_append_metadata($previous['file_map'][$base],$capture['file_map'][$name]);
    $addition=substr($raw,strlen($oldRaw));
   }else{$addition=$raw;m4w_expect($capture['file_map'][$name]['mode']===0600,'M4W_RUNTIME_NEW_ROTATED_FILE_ORIGINAL_UMASK_MODE');}
   $processed[$name]=true;$first=false;
  }
  if(isset($capture['file_map'][$base])){
   $raw=m4w_runtime_bytes($capture,$base);m4w_expect($capture['file_map'][$base]['mode']===0600,'M4W_RUNTIME_REAL_RECREATED_CHANNEL_FILE_MODE');
   $processed[$base]=true;
  }else m4w_expect(!$closed,'M4W_RUNTIME_ROTATION_COMPLETE_BASE_RECREATION_BEFORE_FINAL_CLOSE');
 }
 foreach($previous['file_map']as$name=>$metadata){
  if(isset($capture['file_map'][$name]))continue;
  $kind=m4w_runtime_kind($name,$start,$end);
  if($name==='timer.pid'){
   m4w_expect($closed&&$proof['pid_seen']===true,'M4W_RUNTIME_ONLY_ACTUAL_SEEN_MASTER_PID_REMOVED_AFTER_CLOSE');continue;}
  m4w_expect($kind['kind']==='channel'&&isset($rotations[$name])&&!$closed,
   'M4W_RUNTIME_NO_UNRELATED_OR_RETENTION_OLD_MEMBER_DELETION');
 }
 foreach($capture['file_map']as$name=>$metadata){
  $raw=m4w_runtime_bytes($capture,$name);$kind=$kinds[$name];$old=isset($previous['file_map'][$name])?$previous['file_map'][$name]:null;
  if(isset($processed[$name]))continue;
  if($old!==null&&$capture['files'][$name]===$previous['files'][$name])continue;
  if($kind['kind']==='unrelated'||$kind['kind']==='rotation'){
   m4w_expect(false,'M4W_RUNTIME_NO_UNRELATED_CHANGE_OR_OLD_ROTATION_OVERWRITE');
  }elseif($name==='timer.pid'){
   $state=$GLOBALS['m4w_process_state'];$validPid=is_array($state['master_initial'])&&$raw===(string)$state['master_initial']['pid'];
   m4w_expect(!$closed&&$metadata['mode']===0600&&($validPid||(!$complete&&!$proof['pid_seen']&&$raw==='')),
    'M4W_RUNTIME_PID_FILE_REAL_MASTER_ONLY_OR_INITIAL_WRITE_PENDING');
   if($old!==null){m4w_runtime_append_metadata($old,$metadata);
    m4w_expect(!$proof['pid_seen']||$capture['files'][$name]===$previous['files'][$name],'M4W_RUNTIME_PID_NEVER_REWRITTEN_AFTER_REAL_MASTER_READBACK');}
   if($validPid)$proof['pid_seen']=true;
  }elseif($name==='.timer'){
   $valid=preg_match('/\A[1-9][0-9]{0,9}\z/D',$raw)===1&&(int)$raw>=$start&&(int)$raw<=$end;
   $pending=false;
   if(!$complete&&$stage==='natural_observation'&&!$valid){
    if($raw==='')$pending=true;
    elseif(preg_match('/\A[1-9][0-9]{0,8}\z/D',$raw)===1){
     // A proper prefix must extend to at least one canonical time in this actual interval.
     // At most nine decimal-length steps; never enumerate elapsed seconds.
     $low=(int)$raw;$high=$low;$minimum=max(1,$start);$maximum=min(9999999999,$end);
     for($digits=strlen($raw)+1;$digits<=10;$digits++){
      $low*=10;$high=$high*10+9;
      if($minimum<=$maximum&&$low<=$maximum&&$high>=$minimum){$pending=true;break;}
     }
    }
   }
   m4w_expect($metadata['mode']===0600&&($valid||$pending),'M4W_RUNTIME_SYSTEM_CRONTAB_COMPLETE_ASCII_TIME_ONLY');
   if($old!==null)m4w_runtime_append_metadata($old,$metadata);
   if($valid){
    if($proof['last_heartbeat']!==null)m4w_expect((int)$raw>=$proof['last_heartbeat']+61,
     'M4W_RUNTIME_ORIGINAL_HEARTBEAT_ONLY_AFTER_MORE_THAN_SIXTY_SECONDS');
    $proof['timer_started']=true;$proof['last_heartbeat']=(int)$raw;
   }
  }else{
   m4w_expect($kind['kind']==='channel'||$name==='timer.log','M4W_RUNTIME_ONLY_EXACT_SOURCE_LOG_MEMBER');
   if($old!==null){$oldRaw=m4w_runtime_bytes($previous,$name);m4w_expect(str_starts_with($raw,$oldRaw),'M4W_RUNTIME_ORIGINAL_LOG_ALL_BYTES_APPEND_ONLY');
    if(!($name==='timer.log'&&$oldRaw===''&&$old['mode']===0600&&$metadata['mode']===0622))m4w_runtime_append_metadata($old,$metadata);$raw=substr($raw,strlen($oldRaw));}
   else m4w_expect($metadata['mode']===($name==='timer.log'?0622:0600)||($name==='timer.log'&&!$complete&&$raw===''&&$metadata['mode']===0600),'M4W_RUNTIME_NEW_SOURCE_LOG_FILE_ACTUAL_MODE');
   // Complete framing is checked at real tick/final against the original full baseline, not a partial polling tail.
  }
 }
 // Unchanged target bytes must also carry their real ownership / never disappear silently.
 if(isset($capture['file_map']['timer.pid'])){
  $state=$GLOBALS['m4w_process_state'];$pidRaw=m4w_runtime_bytes($capture,'timer.pid');
  $validPid=is_array($state['master_initial'])&&$pidRaw===(string)$state['master_initial']['pid'];
  m4w_expect(!$closed&&($validPid||(!$complete&&!$proof['pid_seen']&&$pidRaw==='')),'M4W_RUNTIME_ACTUAL_PID_OR_INITIAL_NATIVE_WRITE_PENDING');
  if($validPid)$proof['pid_seen']=true;
 }
 if($complete){
  if(!$closed)m4w_expect($proof['pid_seen']===true&&isset($capture['file_map']['timer.pid']),'M4W_RUNTIME_REAL_MASTER_PID_PRESENT_AT_NATURAL_TICK');
  m4w_expect(isset($capture['file_map']['.timer']),'M4W_RUNTIME_REAL_COMMAND_HEARTBEAT_PRESENT_AT_TICK');
  $heartbeat=m4w_runtime_bytes($capture,'.timer');
  m4w_expect(preg_match('/\A[1-9][0-9]{0,9}\z/D',$heartbeat)===1&&(int)$heartbeat>=$start&&(int)$heartbeat<=$end
   &&$capture['file_map']['.timer']['mode']===0600,'M4W_RUNTIME_COMPLETE_REAL_HEARTBEAT_NATIVE_TIME');
  $proof['timer_started']=true;$proof['last_heartbeat']=(int)$heartbeat;m4w_runtime_complete_logs($capture,$proof['initial'],$start,$end);
 }
 if($closed)m4w_expect($proof['pid_seen']===true&&!isset($capture['file_map']['timer.pid'])
  &&isset($capture['file_map']['.timer'])&&isset($capture['file_map']['timer.log']),'M4W_RUNTIME_FINAL_REAL_HEARTBEAT_LOG_AND_OWNED_PID_REMOVAL');
 m4w_runtime_directories_delta($oldDirectories,$directories,$previous,$capture,$closed);
 $proof['previous']=$capture;$proof['previous_directories']=$directories;$proof['closed']=$closed;$proof['observations'][$index]['verified']=true;
}
function m4w_runtime_evidence():?array
{
 $proof=$GLOBALS['m4w_runtime_delta']??null;
 if(is_array($proof))$proof['optional_pid_pending_reads']=$GLOBALS['m4w_optional_pid_pending_reads']??[];
 return $proof;
}

function m4w_lifecycle(array $source): array
{
    m4w_expect($GLOBALS['m4w_process_state'] === null, 'M4W_NO_PREVIOUS_PROCESS_OR_RESTART');
    m4w_expect(M4W_WINDOW_SECONDS === 180 && M4W_CACHE_KEYS === ['c:crontabCache',
        'c:marketplace_fulfillment_receive_cursor', 'c:CRMEB:827bf7a6b0b8d160bd116898762da4c5'], 'M4W_FIXED_NATURAL_WINDOW_AND_KEYS');
    $preflight = m4w_proc_preflight();
    $runtime = m4w_files_capture('/workspace/crmeb/runtime');
    m4w_runtime_initial($runtime);
    m4w_expect(m4w_log_records($runtime, true) === [] && !array_key_exists('timer.pid', $runtime['file_map'])
        && !array_key_exists('timer.log', $runtime['file_map']), 'M4W_NO_PREVIOUS_TIMER_PID_LOG_OR_EVENT');
    $command = [PHP_BINARY, '/workspace/crmeb/think', 'timer', 'start'];
    $environment = m4c_entry_child_environment($source);
    $state = ['process' => null, 'pipes' => [], 'resource_id' => null, 'command' => $command, 'cwd' => '/workspace/crmeb',
        'started_ns' => hrtime(true), 'finished_ns' => null, 'supervisor' => $preflight['supervisor'],
        'initial_inventory' => $preflight['inventory'], 'startup_status_read' => false, 'status_start' => null,
        'pipe_nonblocking' => ['stdout' => null, 'stderr' => null], 'master_initial' => null,
        'exec_supervisor' => $preflight['exec_supervisor'], 'exec_initial_master' => null, 'exec_initial_child' => null,
        'exec_ready' => null, 'exec_before_signal' => null,
        'child_initial' => null, 'pre_signal_status_read' => false, 'status_before_signal' => null,
        'master_before_signal' => null, 'child_before_signal' => null, 'pid_file_before_signal' => null,
        'signal' => ['resource_id' => null, 'number' => SIGTERM, 'attempted' => false, 'returned' => null, 'monotonic_ns' => null],
        'close_attempted' => false, 'proc_close_exit' => null, 'closed' => false, 'cleanup_running' => false,
        'streams' => ['stdout' => '', 'stderr' => ''], 'stream_eof' => ['stdout' => false, 'stderr' => false],
        'stream_closed' => ['stdout' => false, 'stderr' => false], 'proc_observations' => [], 'absence_after_close' => null,
        'runtime_initial' => $runtime, 'runtime_at_tick' => null, 'runtime_final' => null, 'cache_at_tick' => null,
        'cache_at_tick_public' => null, 'event_records_at_tick' => [], 'event_records_final' => [], 'log_evidence' => null,
        'oversized' => false, 'cleanup_window_exceeded' => false, 'cleanup_problems' => []];
    $GLOBALS['m4w_process_state'] = $state;
    unset($state);
    $state =& $GLOBALS['m4w_process_state'];
    try {
        m4w_phase('worker_start');
        $state['process'] = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $state['pipes'], '/workspace/crmeb', $environment, ['bypass_shell' => true]);
        unset($environment);
        m4w_proc_start_capture();
        m4w_expect(is_resource($state['process']), 'M4W_ORIGINAL_FOREGROUND_PROCESS_OPENED');
        m4w_expect($state['cleanup_problems'] === []
            && $state['pipe_nonblocking'] === ['stdout' => true, 'stderr' => true], 'M4W_ORIGINAL_TWO_NONBLOCKING_PIPES');
        $status = $state['status_start'];
        m4w_expect(is_array($status) && is_int($status['pid'] ?? null) && $status['pid'] > 1
            && m4w_proc_status_live($status, $status['pid']), 'M4W_REAL_STARTUP_RESOURCE_RUNNING');
        $master = $state['master_initial'];
        m4w_expect(is_array($master) && $master['pid'] === $status['pid'] && $master['ppid'] === $state['supervisor']['pid']
            && m4w_proc_live($master), 'M4W_REAL_DIRECT_FOREGROUND_MASTER_CHILD');
        $startupObservation = m4w_proc_observe('startup_native_exec', true);
        m4w_proc_exec_observe($startupObservation, false);
        m4w_phase('worker_observe');
        do {
            m4w_proc_drain();
            m4w_expect(!$state['oversized'] && $state['cleanup_problems'] === []
                && !$state['stream_eof']['stdout'] && !$state['stream_eof']['stderr'], 'M4W_NO_EARLY_EOF_OR_LOST_NATIVE_STREAM');
            m4w_expect(empty($GLOBALS['m4w_termination_requested']), 'M4W_NATURAL_OBSERVATION_NOT_INTERRUPTED');
            $capture = m4w_files_capture('/workspace/crmeb/runtime');
            $records = m4w_log_records($capture, false);
            $observation = m4w_proc_observe('natural_observation', true);
            m4w_proc_exec_observe($observation, $records !== []);
            m4w_proc_pidfile($records !== []);
            m4w_runtime_step($capture, 'natural_observation');
            $sequence = m4w_log_sequence($records, false);
            $elapsed = hrtime(true) - $state['started_ns'];
            m4w_expect($elapsed <= 120000000000, 'M4W_NATURAL_FIRST_SCAN_WITHIN_120_SECONDS');
            if ($sequence['scans'] !== []) {
                $state['runtime_at_tick'] = $capture;
                $state['event_records_at_tick'] = $records;
                $state['cache_at_tick'] = m4w_proc_cache_tick();
                $state['cache_at_tick_public'] = m4w_cache_public($state['cache_at_tick']);
                m4w_runtime_step($capture, 'natural_tick');
                break;
            }
            usleep(100000);
        } while (true);
        m4w_proc_drain();
        m4w_expect(!$state['stream_eof']['stdout'] && !$state['stream_eof']['stderr']
            && empty($GLOBALS['m4w_termination_requested']), 'M4W_STILL_NATURALLY_LIVE_BEFORE_NORMAL_TERM');
        m4w_proc_observe('immediately_before_normal_term', true);
        m4w_expect(is_array($state['child_initial']), 'M4W_REAL_WORKER_CHILD_REGISTERED');
        $state['pid_file_before_signal'] = m4w_proc_pidfile(true);
        m4w_phase('worker_signal');
        m4w_proc_signal();
        m4w_phase('worker_close');
        m4w_process_cleanup();
        m4w_expect($state['closed'] && $state['close_attempted'] && is_int($state['proc_close_exit'])
            && $state['proc_close_exit'] === 0, 'M4W_ACTUAL_PROC_CLOSE_ZERO_NO_STATUS_TRANSLATION');
        m4w_expect($state['cleanup_problems'] === [] && !$state['oversized'] && !$state['cleanup_window_exceeded']
            && $state['stream_eof'] === ['stdout' => true, 'stderr' => true]
            && $state['stream_closed'] === ['stdout' => true, 'stderr' => true], 'M4W_COMPLETE_ORIGINAL_TWO_STREAM_DRAIN_CLOSE');
        $absence = $state['absence_after_close'];
        m4w_expect(is_array($absence) && $absence['master_absent'] === true && $absence['child_absent'] === true
            && $absence['pid_file_absent'] === true && $absence['namespace_only_supervisor'] === true,
            'M4W_POST_PROC_CLOSE_MASTER_CHILD_PIDFILE_AND_EXTRA_PROCESSES_ABSENT');
        m4w_expect(is_array($state['runtime_final']), 'M4W_ALL_FINAL_RUNTIME_BYTES_CAPTURED');
        $records = m4w_log_records($state['runtime_final'], true);
        $state['event_records_final'] = $records;
        $sequence = m4w_log_sequence($records, true);
        m4w_log_retained_tick($records);
        $state['log_evidence'] = m4w_log_master_stop($state['runtime_final']);
        m4w_runtime_step($state['runtime_final'], 'normal_closed', true);
        m4w_expect(hrtime(true) - $state['started_ns'] <= M4W_WINDOW_SECONDS * 1000000000,
            'M4W_SUCCESSFUL_FULL_LIFECYCLE_WITHIN_180_SECONDS');
        $private = m4w_proc_private();
        m4w_expect(strlen(serialize($private)) <= 67108864, 'M4W_COMPLETE_PRIVATE_LIFECYCLE_WITHIN_ORIGINAL_NATIVE_BOUND');
        $public = ['command' => $command, 'cwd' => '/workspace/crmeb',
            'supervisor' => $state['supervisor'] + $state['exec_supervisor'],
            'master_initial' => $state['master_initial'] + $state['exec_initial_master'],
            'child_initial' => $state['child_initial'] + $state['exec_initial_child'],
            'master_before_signal' => $state['master_before_signal'] + $state['exec_before_signal']['master'],
            'child_before_signal' => $state['child_before_signal'] + $state['exec_before_signal']['child'],
            'pid_file' => ['path' => '/workspace/crmeb/runtime/timer.pid',
                'before_signal' => $state['pid_file_before_signal']['metadata'],
                'master_pid' => $state['master_initial']['pid'], 'exact_master_pid_bytes' => true,
                'absent_after_close' => $absence['pid_file_absent'], 'absence_monotonic_ns' => $absence['monotonic_ns']],
            'signal' => $state['signal'], 'proc_close_exit' => $state['proc_close_exit'],
            'streams' => m4w_proc_stream_summaries(), 'event_records' => array_column($records, 'locator'),
            'runtime' => $state['runtime_final']['file_map'],
            'child_exit_observation' => ['observation' => 'MASTER_NORMAL_EXIT_OWNED_STOP_TRACE_NO_RAW_CHILD_WAIT_STATUS',
                'raw_child_wait_status' => null, 'child_absent_after_close' => $absence['child_absent'],
                'removed_event_count' => count($sequence['removed']), 'master_proc_close_exit' => $state['proc_close_exit']]];
        m4w_proc_keys($public, ['command', 'cwd', 'supervisor', 'master_initial', 'child_initial', 'master_before_signal',
            'child_before_signal', 'pid_file', 'signal', 'proc_close_exit', 'streams', 'event_records', 'runtime',
            'child_exit_observation'], 'M4W_PUBLIC_LIFECYCLE_EXACT_FOURTEEN');
        return ['public' => $public, 'private' => $private];
    } catch (Throwable $error) {
        m4w_process_cleanup();
        throw $error;
    }
}


function m4w_diagnostic(Throwable $error):array
{
 $code=$GLOBALS['m4w_failed_assertion']??$GLOBALS['m4ar_failed_assertion']??$GLOBALS['m4fn_failed_assertion']
  ??$GLOBALS['m5pcp_failed_assertion']??$GLOBALS['m4c_failed_assertion']??'M4W_UNCLASSIFIED';
 if(!is_string($code)||preg_match('/\A[A-Z0-9_]{1,160}\z/D',$code)!==1)$code='M4W_FIXED_FAILURE';
 return ['code'=>$code,'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()];
}
function m4w_result(array $source,bool $success,?array $diagnostic):array
{
 return ['schema'=>1,'scope'=>'NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
 'status'=>$success?'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY':'INCOMPLETE_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
 'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],
 'native_created_sha256'=>$source['native_created_sha256'],'source_binding_sha256'=>$source['source_binding_sha256'],
 'environment_sha256'=>$source['environment_sha256'],'environment_native_sha256'=>$source['environment_native_sha256'],
 'prerequisites_sha256'=>$source['prerequisites_sha256'],'phase'=>$GLOBALS['m4w_phase']??'source','entry_return_code'=>$success?0:1,
 'termination'=>$success?'NORMAL_RETURN':'EXCEPTION','native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
 'full_source_unchanged'=>$success,'metadata_observer'=>$GLOBALS['m4w_observer']??null,
 'lifecycle'=>$GLOBALS['m4w_lifecycle_public']??null,'preservation'=>$GLOBALS['m4w_preservation']??null,'diagnostic'=>$diagnostic,
 'full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN',
 'assertions'=>($GLOBALS['m4w_assertions']??0)+($GLOBALS['m4ar_assertions']??0)+($GLOBALS['m4fn_assertions']??0)
  +($GLOBALS['m5pcp_assertions']??0)+($GLOBALS['m4c_assertions']??0)];
}
function m4w_stable_unchanged(array $source,array $inputs,array $config,array $fixtures):void
{
 m4w_old_inputs_unchanged($inputs);m4w_expect(m4w_config_capture($source)===$config,'M4W_APP_SECRETS_FULL_ORIGINAL_CONFIG_UNCHANGED');
 m4w_expect(m4w_files_capture('/run/audit-fixtures')===$fixtures,'M4W_ALL_OLD_FIXTURE_FILES_BYTES_METADATA_UNCHANGED');
 m4w_expect(m4w_public_hashes(m4w_predecessor_names())===$GLOBALS['m4w_predecessor_hashes'],'M4W_ALL_OLD_PUBLIC_RECEIPT_BYTES_UNCHANGED');
 m4w_full_source($source);m4w_profile_unchanged($source);
}
function m4w_seal_after_failure():void
{
 $source=$GLOBALS['m4w_source']??null;if(!is_array($source)||file_exists(M4W_AFTER)||is_link(M4W_AFTER))return;
 $snapshot=null;$cache=null;$runtime=null;
 try{$db=$GLOBALS['m4w_db']??null;if($db instanceof PDO)$snapshot=m4ar_snapshot($db);}catch(Throwable $captureError){$GLOBALS['m4w_failure_snapshot_diagnostic']=m4w_diagnostic($captureError);}
 try{$cache=m4w_cache_native();}catch(Throwable $captureError){$GLOBALS['m4w_failure_cache_diagnostic']=m4w_diagnostic($captureError);}
 try{$runtime=m4w_files_capture('/workspace/crmeb/runtime');}catch(Throwable $captureError){$GLOBALS['m4w_failure_runtime_diagnostic']=m4w_diagnostic($captureError);}
 $process=function_exists('m4w_proc_private')?m4w_proc_private():null;
 $payload=['outcome'=>'INCOMPLETE','diagnostic'=>$GLOBALS['m4w_primary_failure']??null,'cleanup_failure'=>$GLOBALS['m4w_cleanup_failure']??null,
  'before'=>$GLOBALS['m4w_before_seal']??null,'snapshot'=>$snapshot,'cache'=>$cache,'runtime'=>$runtime,'process'=>$process,
  'preflight'=>$GLOBALS['m4w_preflight']??null,'predecessor_hashes'=>$GLOBALS['m4w_predecessor_hashes']??null,
  'runtime_delta'=>m4w_runtime_evidence(),
  'raw_cache_reply_history'=>$GLOBALS['m4w_cache_reply_history']??[],'capture_diagnostics'=>['snapshot'=>$GLOBALS['m4w_failure_snapshot_diagnostic']??null,'cache'=>$GLOBALS['m4w_failure_cache_diagnostic']??null,
   'runtime'=>$GLOBALS['m4w_failure_runtime_diagnostic']??null]];
 $GLOBALS['m4w_failure_after_seal']=m4ar_private_seal(M4W_AFTER,'MS4_WORKER_NORMAL_AFTER_NATIVE_FACTS',$source,$payload);
 m4w_archive_load(M4W_AFTER,'MS4_WORKER_NORMAL_AFTER_NATIVE_FACTS',$source,$GLOBALS['m4w_failure_after_seal']);
}
function m4w_run():int
{
 m4w_phase('source');$source=m4w_source();$GLOBALS['m4w_source']=$source;
 m4w_expect(!file_exists(M4W_BEFORE)&&!is_link(M4W_BEFORE)&&!file_exists(M4W_AFTER)&&!is_link(M4W_AFTER),'M4W_NEW_FIXED_SEALS_CREATE_ONLY');
 $config=m4w_config_capture($source);$inputs=m4w_old_inputs();$fixtures=m4w_files_capture('/run/audit-fixtures');
 $db=audit_db();$GLOBALS['m4w_db']=$db;m4w_expect(!$db->inTransaction(),'M4W_REAL_OBSERVER_OUTSIDE_TRANSACTION');
 $GLOBALS['m4w_observer']=m5pc_observer_configure($db);m4w_phase('restore_public');
 $restored=m4w_public_restore($source,$inputs);$before=m4ar_snapshot($db);$GLOBALS['m4w_before_snapshot']=$before;
 m4ar_zero($restored['final']['native'],$before);
 m4w_expect(m4w_files_capture(M4AR_PRIVATE)['file_map']===$before['private'],'M4W_COMPLETE_READONLY_OLD_PRIVATE_FILES');
 $cache=m4w_cache_native();$GLOBALS['m4w_before_cache']=$cache;$window=m4w_preflight($db,$before,$cache);$GLOBALS['m4w_preflight']=$window;
 $beforePayload=['snapshot'=>$before,'cache'=>$cache,'preflight'=>$window,'predecessor_hashes'=>$GLOBALS['m4w_predecessor_hashes'],
  'original_inputs'=>m4w_input_public($inputs),'fixtures'=>$fixtures,'config'=>['path_basename'=>basename($config['path']),
   'bytes'=>strlen($config['raw']),'sha256'=>$config['sha256'],'stat'=>$config['stat']],
  'normal_final'=>$restored['final'],'normal_keep_descriptor'=>$restored['descriptor']];
 $beforeSeal=m4ar_private_seal(M4W_BEFORE,'MS4_WORKER_NORMAL_BEFORE_NATIVE_FACTS',$source,$beforePayload);$GLOBALS['m4w_before_seal']=$beforeSeal;
 $read=m4w_archive_load(M4W_BEFORE,'MS4_WORKER_NORMAL_BEFORE_NATIVE_FACTS',$source,$beforeSeal);
 foreach($beforePayload as$k=>$v)m4w_expect(array_key_exists($k,$read)&&$read[$k]===$v,'M4W_COMPLETE_BEFORE_NATIVE_READBACK');
 m4w_phase('launch_preconditions');m4w_launch_preconditions($before,$cache,$window);
 m4w_stable_unchanged($source,$inputs,$config,$fixtures);m4cb_guard_readonly();
 m4w_expect(time()+120<=$window['wall_through'],'M4W_PRELAUNCH_FULL_OBSERVATION_FITS_SAVED_WINDOW');
 $lifecycle=m4w_lifecycle($source);$GLOBALS['m4w_lifecycle_public']=$lifecycle['public'];$GLOBALS['m4w_lifecycle_private']=$lifecycle['private'];
 m4w_phase('after_snapshot');$after=m4ar_snapshot($db);$cacheAfter=m4w_cache_native();
 $GLOBALS['m4w_after_snapshot']=$after;$GLOBALS['m4w_after_cache']=$cacheAfter;
 m4w_expect(time()<=$window['wall_through']&&(int)$cacheAfter['time_ms']<=(int)$window['redis_through_ms'],'M4W_REAL_FINITE_WINDOW_NOT_EXPIRED');
 $changes=m4w_timer_changes($before,$after,$cache,$cacheAfter);
 foreach($before['rows']as$table=>$rows)m4w_expect(m4fn_row_multiset($rows)===m4fn_row_multiset($after['rows'][$table]),'M4W_ORIGINAL_NATIVE_ROW_MULTISETS_UNCHANGED');
 m4w_expect(m4w_files_capture(M4AR_PRIVATE)['file_map']===$after['private']
  &&m4w_files_capture('/workspace/crmeb/public')['file_map']===$after['public'],'M4W_COMPLETE_REAL_PRIVATE_PUBLIC_FILE_MAPS');
 m4w_stable_unchanged($source,$inputs,$config,$fixtures);$runtime=m4w_files_capture('/workspace/crmeb/runtime');
 m4w_expect($runtime['file_map']===$lifecycle['public']['runtime'],'M4W_FULL_RUNTIME_UNCHANGED_AFTER_NATIVE_CLOSE');
 m4w_expect($runtime===$lifecycle['private']['runtime_final'],'M4W_FULL_RUNTIME_RAW_BYTES_FIXED_AFTER_NATIVE_CLOSE');
 m4w_runtime_step($runtime,'sealed_after',true);
 $preserved=['predecessor_hashes'=>$GLOBALS['m4w_predecessor_hashes'],'original_inputs'=>m4w_input_public($inputs),
  'all_database_unchanged'=>true,'non_target_redis_unchanged'=>true,'timer_redis_changes'=>$changes,
  'private_files_unchanged'=>true,'public_files_unchanged'=>true,'runtime'=>$runtime['file_map'],'full_source_unchanged'=>true,'window'=>['wall_started'=>$window['wall_started'],'wall_through'=>$window['wall_through'],'redis_started_ms'=>$window['redis_started_ms'],'redis_through_ms'=>$window['redis_through_ms'],'seconds'=>$window['seconds'],'tasks_count'=>count($window['tasks']),'saved_ship_rows_count'=>count($window['saved_ship_rows']),'preflight_sha256'=>hash('sha256',serialize($window))]];
 $afterPayload=['outcome'=>'NORMAL_NON_DUE_LIFECYCLE','snapshot'=>$after,'cache'=>$cacheAfter,'runtime'=>$runtime,
  'process'=>$lifecycle['private'],'lifecycle'=>$lifecycle['public'],'before'=>$beforeSeal,'preservation'=>$preserved,'runtime_delta'=>m4w_runtime_evidence()];
 $afterSeal=m4ar_private_seal(M4W_AFTER,'MS4_WORKER_NORMAL_AFTER_NATIVE_FACTS',$source,$afterPayload);
 $readAfter=m4w_archive_load(M4W_AFTER,'MS4_WORKER_NORMAL_AFTER_NATIVE_FACTS',$source,$afterSeal);
 foreach($afterPayload as$k=>$v)m4w_expect(array_key_exists($k,$readAfter)&&$readAfter[$k]===$v,'M4W_COMPLETE_AFTER_NATIVE_READBACK');
 $GLOBALS['m4w_preservation']=['before'=>$beforeSeal,'after'=>$afterSeal]+$preserved;
 m4w_phase('final_source');m4w_stable_unchanged($source,$inputs,$config,$fixtures);
 m4w_expect(m4w_source()===$source,'M4W_CURRENT_FULL_GATE_SOURCE_ENV_ROLE_BINDINGS_UNCHANGED');
 m4w_expect(m4ar_private_input(M4W_BEFORE,100663296)['sha256']===$beforeSeal['sha256']
  &&m4ar_private_input(M4W_AFTER,100663296)['sha256']===$afterSeal['sha256'],'M4W_BOTH_NEW_NATIVE_ARCHIVES_RETAINED_FULL_BYTES');
 m4w_expect(empty($GLOBALS['m4w_cleanup_failure'])&&empty($GLOBALS['m4w_termination_requested']),'M4W_NO_ABNORMAL_TERMINATION_OR_CLEANUP_FAILURE');
 $result=m4w_result($source,true,null);m4c_entry_publish('/run/audit/evidence/'.M4W_RULE['result'],$result);
 $GLOBALS['m4w_result_published']=true;$GLOBALS['m4w_completed']=true;
 echo json_encode(['scope'=>'NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY','status'=>'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
  'phase'=>'final_source','entry_return_code'=>0,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
 if(($argv??[])===[$_SERVER['SCRIPT_FILENAME'],'--help']){echo "Usage: php help/audit-regressions/isolated/ms4-worker-normal.php normal\nDedicated isolated natural non-due Worker lifecycle only; independent Host native receipt required.\n";exit(0);}
 $GLOBALS['m4w_assertions']=0;$GLOBALS['m4ar_assertions']=0;$GLOBALS['m4w_termination_requested']=false;
 ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
 set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
  if((error_reporting()&$severity)===0)return false;$GLOBALS['m4w_failed_assertion']='M4W_PHP_WARNING';throw new ErrorException('M4W_PHP_WARNING',0,$severity,$file,$line);});
 register_shutdown_function(static function():void{
  $source=$GLOBALS['m4w_source']??null;if(!is_array($source))return;$last=error_get_last();
  $fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
  try{m4w_process_cleanup();}catch(Throwable $cleanupError){$GLOBALS['m4w_cleanup_failure']=m4w_diagnostic($cleanupError);}
  if(empty($GLOBALS['m4w_completed'])){try{m4w_seal_after_failure();}catch(Throwable $sealError){$GLOBALS['m4w_cleanup_failure']??=m4w_diagnostic($sealError);}}
  $shutdown=['schema'=>1,'scope'=>'MS4_WORKER_NORMAL_SHUTDOWN','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
   'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],'phase'=>$GLOBALS['m4w_phase']??'source',
   'completed'=>($GLOBALS['m4w_completed']??false)===true,'result_published'=>($GLOBALS['m4w_result_published']??false)===true,'fatal'=>$fatal,
   'termination_requested'=>($GLOBALS['m4w_termination_requested']??false)===true,'primary_failure'=>$GLOBALS['m4w_primary_failure']??null,
   'cleanup_failure'=>$GLOBALS['m4w_cleanup_failure']??null,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'];
  try{m4c_entry_publish('/run/audit/evidence/worker-normal-shutdown.json',$shutdown);}
  catch(Throwable $publicationError){fwrite(STDERR,"M4W shutdown receipt unavailable; Host native observation required\n");}
 });
 try{
  m4w_expect(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')&&defined('SIGTERM'),'M4W_REAL_SIGNAL_CAPABILITY');
  pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4w_termination_requested']=true;});
  exit(m4w_run());
 }catch(Throwable $error){
  $diagnostic=m4w_diagnostic($error);$GLOBALS['m4w_primary_failure']=$diagnostic;
  try{m4w_process_cleanup();}catch(Throwable $cleanupError){$GLOBALS['m4w_cleanup_failure']=m4w_diagnostic($cleanupError);}
  try{m4w_seal_after_failure();}catch(Throwable $sealError){$GLOBALS['m4w_cleanup_failure']??=m4w_diagnostic($sealError);}
  $source=$GLOBALS['m4w_source']??null;if(is_array($source)&&empty($GLOBALS['m4w_result_published'])){
   try{m4c_entry_publish('/run/audit/evidence/'.M4W_RULE['result'],m4w_result($source,false,$diagnostic));$GLOBALS['m4w_result_published']=true;}
   catch(Throwable $publicationError){fwrite(STDERR,"M4W incomplete receipt unavailable; Host native observation required\n");}}
  echo json_encode(['scope'=>'NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY','status'=>'INCOMPLETE_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY',
   'phase'=>$GLOBALS['m4w_phase']??'source','entry_return_code'=>1,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";exit(1);
 }
}
