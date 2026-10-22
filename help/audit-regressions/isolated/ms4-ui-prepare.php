<?php
declare(strict_types=1);

/** Independent finite positive UI fixture preparation. Importing performs no application action. */
require_once __DIR__.'/ms4-aftersale-review-normal.php';
require_once __DIR__.'/lib/ms4-ui-credentials.php';
require_once __DIR__.'/lib/ms4-ui-prepare-oracle.php';
require_once dirname(__DIR__,2).'/migrations/marketplace-ms4-order-read-menus.php';

const M4UP_ENTRY='help/audit-regressions/isolated/ms4-ui-prepare.php';
const M4UP_RULE=['role'=>'audit-ms4-ui-prepare','proof'=>'ui-prepare',
 'binding'=>'ms4-ui-prepare-source-binding.json','binding_scope'=>'MS4_UI_PREPARE_SOURCE',
 'environment'=>'ui-prepare-environment.json','result'=>'ms4-ui-prepare.json',
 'scope'=>'NORMAL_MS4_UI_PREPARATION_ONLY','status'=>'VERIFIED_NORMAL_MS4_UI_PREPARATION_ONLY'];
const M4UP_HELPER='help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php';
function m4up_expect(bool $ok,string $code):void
{
 ++$GLOBALS['m4up_assertions'];
 if(!$ok){$GLOBALS['m4up_failed_assertion']=$code;throw new RuntimeException('M4UP_ASSERTION');}
}
function m4up_phase(string $phase):void
{m4up_expect(empty($GLOBALS['m4c_terminate_requested']),'UI_PREPARE_NOT_INTERRUPTED');$GLOBALS['m4c_phase']=$phase;}
function m4up_extra():array
{
 $ar=m4ar_extra();$expectedAR=[
  'crmeb/composer.json',
  'crmeb/composer.lock',
  'crmeb/public/index.php',
  'crmeb/public/install/crmeb.sql',
  'crmeb/public/router.php',
  'crmeb/think',
  'help/audit-regressions/isolated/lib/guard.php',
  'help/audit-regressions/isolated/lib/harness.php',
  'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php',
  'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php',
  'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php',
  'help/audit-regressions/isolated/lib/ms4-three-legacy-reference.php',
  'help/audit-regressions/isolated/lib/ms4-three-read-normal.php',
  'help/audit-regressions/isolated/ms4-aftersale-review-normal.php',
  'help/audit-regressions/isolated/ms4-customer-http-normal.php',
  'help/audit-regressions/isolated/ms4-fulfillment-migration.php',
  'help/audit-regressions/isolated/ms4-fulfillment-normal.php',
  'help/audit-regressions/isolated/ms5-paid-consumer-normal.php',
  'help/audit-regressions/isolated/ms5-payment-migration.php',
  'help/migrations/marketplace-ms1.php',
  'help/migrations/marketplace-ms2.php',
  'help/migrations/marketplace-ms4-aftersale-menus.php',
  'help/migrations/marketplace-ms4-aftersale.php',
  'help/migrations/marketplace-ms4-assets.php',
  'help/migrations/marketplace-ms4-fulfillment.php',
  'help/migrations/marketplace-ms4-review-menus.php',
  'help/migrations/marketplace-ms4.md',
  'help/migrations/marketplace-ms4.php',
  'help/migrations/marketplace-ms5-payment.md',
  'help/migrations/marketplace-ms5-payment.php',
 ];
 m4up_expect($ar===$expectedAR,'UI_PREPARE_EXACT_CURRENT_AR_THIRTY_SOURCE_MEMBERS');
 $v=array_values(array_unique(array_merge($ar,[M4UP_ENTRY,M4UP_HELPER,
 'help/audit-regressions/isolated/lib/ms4-ui-credentials.php',
 'help/migrations/marketplace-ms4-order-read-menus.php']),SORT_STRING));
 sort($v,SORT_STRING);m4up_expect(count($v)===34,'UI_PREPARE_EXACT_SOURCE_EXTRA_MEMBERS');return $v;
}
function m4up_inventory(string $root):array
{
    $paths=m4up_extra();
    foreach(M4C_ROOTS as$directory){
        m4up_expect(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory),'TASK6_SOURCE_DIRECTORY');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as$file){m4up_expect(!$file->isLink(),'TASK6_SOURCE_NO_LINK');
            if($file->isFile())$paths[]=substr($file->getPathname(),strlen($root)+1);}
    }
    sort($paths,SORT_STRING);m4up_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_UNIQUE_PATHS');return $paths;
}
function m4up_full_source(array $source,bool $beforePublicCopy=false):void
{
    $paths=[];$allowed=[];
    m4up_expect(is_array($source['source_files'])&&array_is_list($source['source_files']),'TASK6_SOURCE_DESCRIPTOR_LIST');
    foreach($source['source_files']as$row){m4c_entry_keys($row,['path','bytes','sha256']);
        m4up_expect(is_string($row['path'])&&$row['path']!==''&&$row['path'][0]!=='/'&&!str_contains($row['path'],'\\')
            &&!str_contains($row['path'],':')&&!in_array('..',explode('/',$row['path']),true)
            &&is_int($row['bytes'])&&$row['bytes']>=0&&m5pc_hex($row['sha256']),'TASK6_SOURCE_DESCRIPTOR');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);
    m4up_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_SORTED_CLOSED_SET');
    foreach(['/source','/workspace']as$root){m4up_expect(m4up_inventory($root)===$paths,'TASK6_SOURCE_INVENTORY');
        foreach($source['source_files']as$row){
            if($beforePublicCopy&&$root==='/workspace'&&in_array($row['path'],['crmeb/public/index.php','crmeb/public/router.php'],true))continue;
            $path=$root.'/'.$row['path'];m4up_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'TASK6_SOURCE_ALL_BYTES');$allowed[$path]=true;}}
    foreach(get_included_files()as$path)m4up_expect(isset($allowed[realpath($path)]),'TASK6_INCLUDED_COMPLETE_SOURCE');
}
function m4up_source(bool $beforePublicCopy=true): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'prepare']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_ACTUAL_FINITE_NATIVE_ENTRY');
    $rule=M4UP_RULE;$evidence='/run/audit/evidence/';
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
    m4up_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'TASK6_ONLY_FOUR_ACTUAL_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    m4up_expect($freeze['ui_prepare_source_roots']===M4C_ROOTS&&$freeze['ui_prepare_source_extra']===m4up_extra(),
        'TASK6_REAL_FOUR_ROOTS_FINITE_EXTRA_CLOSURE');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
    m4c_expect($binding['schema']===1&&$binding['scope']===$rule['binding_scope']&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$rule['proof'].'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['ui_prepare_source_files']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha
        &&$created['proof_name']===$rule['proof']&&$created['hostname']===gethostname()&&$created['read_only']===true
        &&$created['user']==='0:0'&&posix_geteuid()===0&&posix_getegid()===0
        &&$created['privileged']===false&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4UP_ENTRY,'prepare']&&$created['workdir']==='/workspace'
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
    m4up_expect($created['stop_signal']==='SIGTERM'&&getmypid()===1
        &&in_array('audit-app',$created['networks'][$network['Name']]['Aliases']??[],true)
        &&gethostbyname('audit-app')===gethostbyname(gethostname()),'TASK78_REAL_PID_ONE_AND_SELF_HTTP_ALIAS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',true],
        M4AR_PRIVATE=>['aftersale-evidence',true],M4CF_ROOT=>['customer-form-evidence',false]];
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
    $cuKernel=file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES);
    m4up_expect(is_array($cuKernel)&&$cuKernel!==[],'UI_CU_ACTUAL_KERNEL_MOUNTINFO');$cuMounts=[];
    foreach($cuKernel as$line){$halves=explode(' - ',$line,2);$left=explode(' ',$halves[0]);
        if(($left[4]??'')===M4CF_ROOT)$cuMounts[]=explode(',',$left[5]??'');}
    m4up_expect(count($cuMounts)===1&&in_array('ro',$cuMounts[0],true)&&!in_array('rw',$cuMounts[0],true),
        'UI_CU_SINGLE_ACTUAL_PRIVATE_ROOT_READONLY_MOUNT');
    $tmpfs=$created['tmpfs'];$tmpKeys=array_keys($tmpfs);sort($tmpKeys,SORT_STRING);
    m4c_expect($tmpKeys===['/tmp','/workspace/crmeb/public','/workspace/crmeb/runtime'],'M4C_EXACT_THREE_PRIVATE_TMPFS');
    foreach($tmpfs as $options)m4c_expect(is_string($options)&&in_array('rw',explode(',',$options),true),'M4C_WRITABLE_PRIVATE_TMPFS');
    m4c_expect(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'M4C_NO_HOST_RUNTIME_CONFIGURATION');
    $values=m4up_environment($binding,$context);
    audit_guard_assert();
    $source=$binding+['rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment']),
        'environment_native_sha256'=>m4c_entry_hash($evidence.$rule['proof'].'-environment-native.json')];
    m4up_full_source($source,$beforePublicCopy);
    m4up_prerequisites($source);
    return $source;
}

function m4up_environment(array $binding,array $context):array
{
 $base='/run/audit/evidence/';$e=m4c_entry_read($base.M4UP_RULE['environment']);
 m4c_entry_keys($e,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
 m4up_expect($e['schema']===1&&$e['scope']==='MS4_UI_PREPARE_REAL_ENVIRONMENT'
  &&$e['run_id']===$binding['run_id']&&$e['source_sha']===$binding['source_sha']&&$e['container_id']===$binding['container_id']
  &&$e['freeze_sha256']===$context['freeze_sha256']&&getenv('MS4_UI_PREPARE_PROOF_NAME')===M4UP_RULE['proof'],'TASK78_REAL_ENVIRONMENT_IDENTITY');
 $v=$e['environment'];m4c_entry_keys($v,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER',
 'PHP_MARKETPLACE_AFTERSALE_ENABLED','PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT','PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT']);
 m4up_expect($v['APP_ENV']==='testing'&&$v['PHP_CACHE_DRIVER']==='redis'&&$v['PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT']===M4AR_PRIVATE
  &&$v['PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT']===M4CF_ROOT,
 'TASK78_ONLY_ACTUAL_NORMAL_ROLE_PRIVATE_CONFIGURATION');
 foreach(['PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_AFTERSALE_ENABLED']as$k)
  m4up_expect($v[$k]==='true','TASK78_ENABLED_TRUE_STRINGS');
 m5pcp_int($v['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],1,4294967295);
 $n=m4c_entry_read($base.M4UP_RULE['proof'].'-environment-native.json');
 m4c_entry_keys($n,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
 m4up_expect($n['stage']==='ui-prepare-public-environment-inspect'&&$n['native_exit']===0&&$n['stderr_bytes']===0
  &&$n['stderr_sha256']===hash('sha256','')&&$n['private_environment_logs_omitted']===true
  &&$e['native_projection_sha256']===$n['stdout_sha256'],'TASK78_REAL_NINE_VALUE_NATIVE_RECEIPT');
 // Host emits exactly these nine sorted JSON-string lines; no six-value permutation proof is reused.
 ksort($v,SORT_STRING);$lines=[];foreach($v as$k=>$value){m4up_expect(is_string($value)&&getenv($k)===$value,'TASK78_EVERY_ACTUAL_ENVIRONMENT_VALUE');
  $lines[]=json_encode($k.'='.$value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 $raw=implode("\n",$lines)."\n";
 m4up_expect(strlen($raw)===$n['stdout_bytes']&&hash('sha256',$raw)===$n['stdout_sha256'],'TASK78_EXACT_SORTED_NINE_LINE_NATIVE_PROJECTION');return $v;
}

function m4up_paid_history_names():array
{
 $v=array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES,
 ['ms4-customer-prepare-source-binding.json','customer-prepare-environment.json','customer-prepare-environment-native.json'],
 M4FN_PAID_FILES);
 foreach(['register-paid-producer','register-paid-after-c3']as$p)foreach(['-created.json','-terminal.json','-result.json','-shutdown.json']as$s)$v[]=$p.$s;
 $v[]='driver-created.json';m4up_expect(count($v)===33&&count(array_unique($v,SORT_STRING))===33,'UI_ORIGINAL_PAID_THIRTY_THREE');return $v;
}
function m4up_prerequisite_names():array
{
 $v=array_values(array_unique(array_merge(m4up_paid_history_names(),M4FN_MIGRATION_FILES,
 ['paid-producer-formal-manifest.json','paid-producer-predecessor-history.json']),SORT_STRING));
 sort($v,SORT_STRING);m4up_expect(count($v)===39,'UI_EXACT_THIRTY_NINE_PREDECESSOR_FILES');return $v;
}
function m4up_old_view(array $source):array
{
 $base='/run/audit/evidence/';$paid=m4c_entry_read($base.'paid-producer-environment.json');
 $native=m4c_entry_read($base.'paid-producer-environment-native.json');
 $values=m4c_entry_native_environment($paid['environment'],$native);
 m4c_entry_same($values,array_intersect_key($source['environment'],$values));
 $view=$source;$view['environment']=$values;
 $view['prerequisites_sha256']=array_intersect_key($source['prerequisites_sha256'],array_flip(m4fn_prerequisite_names()));
 m4c_entry_keys($view['prerequisites_sha256'],m4fn_prerequisite_names());return $view;
}
function m4up_prerequisites(array $source):void
{
 $base='/run/audit/evidence/';$hashes=$source['prerequisites_sha256'];
 m4c_entry_keys($hashes,m4up_prerequisite_names());
 foreach($hashes as$name=>$hash)m4up_expect(m5pc_hex($hash)&&m4c_entry_hash($base.$name)===$hash,'UI_EVERY_ORIGINAL_PREDECESSOR_BYTE');
 $history=m4c_entry_read($base.'paid-producer-predecessor-history.json');
 m4c_entry_keys($history,['schema','run_id','source_sha','freeze_sha256','context_sha256','manifest_sha256','public_sha256']);
 m4up_expect($history['schema']===1&&$history['run_id']===$source['run_id']&&$history['source_sha']===$source['source_sha']
  &&$history['freeze_sha256']===$source['freeze_sha256']&&$history['context_sha256']===m4c_entry_hash('/run/audit/actual-run-context.json')
  &&$history['manifest_sha256']===$hashes['paid-producer-formal-manifest.json'],'UI_ORIGINAL_PAID_FORMAL_HISTORY_SEAL');
 m4c_entry_keys($history['public_sha256'],m4up_paid_history_names());
 foreach($history['public_sha256']as$name=>$hash)m4up_expect($hash===$hashes[$name],'UI_THIRTY_THREE_ORIGINAL_PUBLIC_SEAL_HASHES');
 $old=m4c_entry_read($base.'paid-producer-formal-manifest.json');$actual=audit_manifest();
 foreach(['run_id','commit_sha','project','guard_mode','isolation','network_internal','network','volumes','volume_identities']as$k)m4c_entry_same($actual[$k],$old[$k]);
 $oldRoles=array_keys($old['services']);sort($oldRoles,SORT_STRING);
 m4up_expect($oldRoles===['audit-ms5-paid-producer','audit-mysql','audit-redis'],'UI_SAVED_ORIGINAL_THREE_ROLE_PAID_FORMAL');
 foreach($old['services']as$role=>$service)m4c_entry_same($actual['services'][$role],$service);
 $wanted=[];foreach(['mysql-data','redis-data','bootstrap-secrets','redis-secrets','app-secrets','fixture-secrets','aftersale-evidence','customer-form-evidence']as$s)$wanted[]=$source['context']['project'].'_'.$s;
 $vols=$actual['volumes'];sort($vols,SORT_STRING);sort($wanted,SORT_STRING);m4up_expect($vols===$wanted,'UI_ONLY_CURRENT_EIGHT_VOLUMES');
 m4fn_prerequisites(m4up_old_view($source));
}
function m4up_paid_owner_before_app(array $source,PDO $db):array
{
    m4up_expect(empty($GLOBALS['m4c_app_started'])&&!isset($GLOBALS['m4c_application'])&&m4cf_enabled(),
        'UI_NATIVE_PAID_OWNER_BEFORE_REAL_APP_WITH_ENABLED_CU_ROOT');
    $loaded=m4cb_fixture_load($source);$fixtureInput=m4fn_private_read(M4CB_FIXTURE,1048576);
    $input=m4fn_private_read(M5PC_BASELINE,2097152);$value=$input['value'];$base='/run/audit/evidence/';
    m4c_entry_keys($value,['schema','scope','run_id','source_sha','freeze_sha256','container_id','native_created_sha256',
        'source_binding_sha256','environment_sha256','environment_native_sha256','environment','self_operated_mer_id',
        'prepare_identity','fixture_descriptor','authenticated_owner','customer_form','order_id','order_no','attempt_id','original_create_result',
        'account_snapshot','payment_application','root_payment','full_database_table_count','full_database_redis_sha256','native_snapshot']);
    $paid=m4c_entry_read($base.'paid-producer-result.json');$producer=$paid['container_id'];$hashes=$source['prerequisites_sha256'];
    m4fn_expect($value['schema']===1&&$value['scope']==='MS5_PAID_CONSUMER_NATIVE_BASELINE'
        &&$value['run_id']===$source['run_id']&&$value['source_sha']===$source['source_sha']&&$value['freeze_sha256']===$source['freeze_sha256']
        &&$value['container_id']===$producer&&$value['native_created_sha256']===$hashes['paid-producer-created.json']
        &&$value['source_binding_sha256']===$hashes['paid-producer-source-binding.json']
        &&$value['environment_sha256']===$hashes['paid-producer-environment.json']
        &&$value['environment_native_sha256']===$hashes['paid-producer-environment-native.json']
        &&$value['self_operated_mer_id']===$source['self_operated_mer_id'],'TASK6_ORIGINAL_PAID_PRIVATE_IDENTITY');
    m4c_entry_same($value['environment'],$source['environment']);
    m4c_entry_same($value['prepare_identity'],['container_id'=>$loaded['private']['prepare_container_id'],
        'native_created_sha256'=>$loaded['private']['prepare_native_created_sha256'],
        'source_binding_sha256'=>$loaded['private']['prepare_source_binding_sha256'],'prepare_receipt_sha256'=>$loaded['prepare_receipt_sha256']]);
    $descriptor=$value['fixture_descriptor'];m4c_entry_keys($descriptor,['path','bytes','sha256','stat']);
    m4fn_expect($descriptor['path']===M4CB_FIXTURE&&$descriptor['bytes']===$fixtureInput['stat']['size']
        &&$descriptor['sha256']===$fixtureInput['sha256']&&$descriptor['stat']===$loaded['stat']
        &&$descriptor['sha256']===$loaded['sha256'],'TASK6_ACTUAL_ORIGINAL_FIXTURE_DESCRIPTOR');
    $owner=$value['authenticated_owner'];
    m4c_entry_keys($owner,['schema','run_id','source_sha','container_id','freeze_sha256','uid','account','actual_user',
        'fixture_sha256','prepare_receipt_sha256','http_user_response','http_call_count','only_original_owner_auth_delta','child']);
    $response=$owner['http_user_response'];m4c_entry_keys($response,['method','path','http','bytes','sha256','raw','actual_interval']);
    m4fn_expect($owner['schema']===1&&$owner['run_id']===$source['run_id']&&$owner['source_sha']===$source['source_sha']
        &&$owner['container_id']===$producer&&$owner['freeze_sha256']===$source['freeze_sha256']
        &&$owner['fixture_sha256']===$fixtureInput['sha256']&&$owner['prepare_receipt_sha256']===$loaded['prepare_receipt_sha256']
        &&$owner['http_call_count']===2&&$owner['only_original_owner_auth_delta']===true&&$owner['child']===$paid['child']
        &&$response['method']==='GET'&&$response['path']==='/api/user'&&$response['http']===200
        &&is_string($response['raw'])&&strlen($response['raw'])>0&&strlen($response['raw'])<=1048576
        &&$response['bytes']===strlen($response['raw'])&&$response['sha256']===hash('sha256',$response['raw']),
        'TASK6_TRUE_SAME_RUN_GET_OWNER_SOURCE');
    $interval=$response['actual_interval'];m4fn_expect(is_array($interval)&&array_is_list($interval)&&count($interval)===2
        &&is_int($interval[0])&&is_int($interval[1])&&$interval[0]>0&&$interval[1]>=$interval[0]
        &&$interval[1]<=$input['stat']['mtime']&&$input['stat']['mtime']<=time(),'TASK6_ACTUAL_ORIGINAL_GET_INTERVAL');
    $reply=json_decode($response['raw'],true,64,JSON_THROW_ON_ERROR);
    m4fn_expect(is_array($reply)&&($reply['status']??null)===200&&is_array($reply['data']??null)
        &&!array_key_exists('account',$reply['data'])&&!array_key_exists('pwd',$reply['data']),'TASK6_ORIGINAL_GET_PUBLIC_CREDENTIALS_HIDDEN');
    $uid=m5pcp_int($reply['data']['uid']??null,1);
    m4fn_expect($uid===$owner['uid']&&$uid===m5pcp_int($loaded['private']['fixture']['user']['uid'],1)
        &&$owner['account']===$loaded['private']['account']&&$owner['account']===$loaded['private']['fixture']['user']['account'],
        'TASK6_ORIGINAL_HTTP_UID_AND_REAL_FIXTURE_ACCOUNT');
    $users=m5pcp_rows($db,'SELECT * FROM `eb_user` WHERE `uid` = ?',[$uid]);
    $nativeForm=m4cf_restore($value['customer_form']);
    m4cf_expect($nativeForm['uid']===$uid&&$nativeForm['complete34_verified']===true&&$nativeForm['only_three_bound_columns']===true
        &&$nativeForm['original_bytes_retained']===true,'PRIVATE_FORM_PROOF_COMPLETE');
    m4cb_owner_auth_only([$owner['actual_user']],[$nativeForm['owner_after']],$uid,true);
    m4cf_expect(m4cf_native_files()===$nativeForm['private'],'SEALED_PAID_PRIVATE_ORIGINALS_STILL_IDENTICAL');
    $formOwner=$nativeForm['owner_after'];
    m4c_entry_same(m4cf_paid_receipt(m4cf_restore($value['customer_form'])),$paid['customer_form']);
    m4fn_expect(count($users)===1&&$users[0]===$formOwner&&$users[0]['account']===$owner['account']
        &&m5pcp_int($users[0]['status'],1,1)===1&&m5pcp_int($users[0]['is_del'],0,0)===0,'TASK6_ACTUAL_SAVED_BUYER_STILL_VALID');
    $archive=m5pc_native_snapshot_restore($value['native_snapshot']);
    m4fn_expect(m5pc_native_snapshot($db)===$archive&&m5pcp_int($value['order_id'],1)>0
        &&is_string($value['attempt_id'])&&m5pcp_u64($value['attempt_id'])===$value['attempt_id'],
        'TASK6_ORIGINAL_THIRTY_FOUR_TABLE_NATIVE_ARCHIVE_EQUALS_PDO');
    $seal=$paid['business']['baseline'];m4fn_expect($seal['bytes']===$input['stat']['size']&&$seal['sha256']===$input['sha256']
        &&$seal['native_archive_bytes']===$value['native_snapshot']['bytes']&&$seal['native_archive_sha256']===$value['native_snapshot']['sha256']
        &&$seal['fact_table_count']===27&&$seal['auxiliary_table_count']===7,'TASK6_PUBLIC_PAID_SEAL_FULL_BYTES_AND_ARCHIVE');
    m4fn_expect($paid['business']['orders']===1&&$paid['business']['items']===2&&$paid['business']['reserves']===2
        &&$paid['business']['real_credential_login_and_user_get']===true&&$paid['business']['http_identity_call_count']===2
        &&$paid['business']['winner_application_root_four_hold_zero_verified']===true,'TASK6_ORIGINAL_PAID_BUSINESS_PROOF');
    $saved=m5pcp_saved($db,m5pcp_int($value['order_id'],1));$root=$saved['order'];
    m4fn_expect(m5pcp_int($root['uid'],1)===$uid&&$root['order_id']===$value['order_no']&&m5pcp_int($root['paid'],1,1)===1
        &&$root['pay_type']==='marketplace_test'&&m5pcp_int($root['status'],0,0)===0,'TASK6_REAL_EXISTING_PAID_ROOT_NOT_RECREATED');
    foreach(['paid','pay_time']as$key)m4fn_expect(m5pcp_int($root[$key],0,4294967295)===$value['root_payment'][$key],
        'TASK6_ORIGINAL_ROOT_NUMERIC_PAYMENT_VALUES');
    foreach(['pay_type','trade_no']as$key)m4fn_expect($root[$key]===$value['root_payment'][$key],
        'TASK6_ORIGINAL_ROOT_TEXT_PAYMENT_VALUES');
    $registry=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_payment_registry` WHERE `order_id` = ?',[$value['order_id']]);
    $attempt=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_payment_attempt` WHERE `order_id` = ? ORDER BY `id`',[$value['order_id']]);
    m4fn_expect(count($registry)===1&&count($attempt)===1&&m5pcp_u64($registry[0]['winner_attempt_id'])===$value['attempt_id']
        &&m5pcp_int($registry[0]['fulfillment_hold'],0,0)===0&&m5pcp_u64($attempt[0]['id'])===$value['attempt_id'],
        'TASK6_ACTUAL_EXISTING_WINNER_HOLD_ZERO');
    $evidence=json_decode($attempt[0]['evidence'],true,64,JSON_THROW_ON_ERROR);
    m4c_entry_same($evidence['application'],$value['payment_application']);
    $inputs=[$fixtureInput,$input];m4fn_inputs_unchanged($inputs);
    return ['uid'=>$uid,'saved'=>$saved,'baseline'=>$value,'loaded'=>$loaded,'inputs'=>$inputs];
}
function m4up_owner(array $source,PDO $db):array
{
 $view=m4up_old_view($source);$owner=m4up_paid_owner_before_app($view,$db);
 m4fn_migration_database($db,$owner); // Includes complete original post-paid DB/absolute Redis equality.
 $root=$owner['saved']['order'];$registry=m5pcp_rows($db,'SELECT * FROM eb_marketplace_payment_registry WHERE order_id=?',[$root['id']]);
 m4up_expect(count($registry)===1&&m5pcp_int($registry[0]['attempt_generation'],1,1)===1
  &&m5pcp_int($registry[0]['fulfillment_hold'],0,0)===0&&m5pcp_int($root['is_cancel'],0,0)===0,'UI_FRESH_PAID_GENERATION_AND_UNCANCELLED_ROOT');
 foreach(['hold_reason','hold_attempt_id','hold_at']as$k)m4up_expect($registry[0][$k]===null,'UI_ALL_PAYMENT_HOLD_FIELDS_CLEAR');
 foreach(['eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range','eb_marketplace_aftersale_action','eb_marketplace_aftersale_file']as$t)
  m4up_expect(m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($t))===[],'UI_NO_PREEXISTING_FULFILLMENT_AFTERSALE_FACTS');
 m4up_expect(m5pcp_rows($db,'SELECT * FROM eb_store_order_refund WHERE store_order_id=?',[$root['id']])===[],'UI_FRESH_ROOT_NO_REFUND');
 foreach($owner['saved']['items']as$item)m4up_expect(m5pcp_rows($db,'SELECT * FROM eb_store_product_reply WHERE oid=? AND '.m5pcp_quote('unique').'=?',
  [$root['id'],$item['unique']])===[],'UI_FRESH_ITEMS_NO_REVIEW');
 $owner['fixture']=$owner['loaded']['private']['fixture'];$owner['root']=$root;
 $owner['user']=$owner['baseline']['authenticated_owner']['actual_user'];return $owner;
}
function m4up_cold(\think\App $app):string
{
 $store=$app->cache->store();
 m4up_expect($store instanceof \think\cache\driver\Redis&&$app->config->get('cache.default')==='redis','UI_REAL_DEFAULT_REDIS_DRIVER');
 $prefix=$app->config->get('cache.stores.redis.prefix');m4up_expect(is_string($prefix)&&$prefix==='c:','UI_SOURCE_CACHE_PREFIX');
 $key=$store->getCacheKey('all_auth');m4up_expect($key===$prefix.'all_auth','UI_SOURCE_DERIVED_PHYSICAL_AUTH_KEY');
 $r=audit_redis();$type=$r->rawCommand('EVAL_RO',"return redis.call('TYPE',KEYS[1]).ok",1,$key);
 m4up_expect($type==='none'&&$r->exists($key)===0,'UI_ACTUAL_AUTH_CACHE_COLD_NO_DELETION');return $key;
}
function m4up_menus(\think\App $app,PDO $db):array
{
 m4up_phase('normal_two_native_order_read_menus');m4up_cold($app);
 $connection=\think\facade\Db::connect();$default=$connection->getPdo();
 m4up_expect($default instanceof PDO,'UI_ACTUAL_DML_DEFAULT_PDO');m4fn_context($app,$connection,$default,$db);
 $migration=new MarketplaceMs4OrderReadMenus($default,'eb_');$check=$migration->check();
 m4up_expect($check['mode']==='check'&&$check['state']==='applicable'&&$check['changed']===false
  &&$check['menu_ids']===[]&&$check['issues']===[],'UI_FRESH_TWO_MENU_CHECK');
 $apply=$migration->apply();m4up_expect($apply['mode']==='apply'&&$apply['state']==='completed'
  &&$apply['changed']===true&&$apply['issues']===[]&&count($apply['created_menu_ids'])===2,'UI_ACTUAL_TWO_MENU_APPLY');
 m4up_cold($app);$post=$migration->check();$again=$migration->apply();
 foreach([$post,$again]as$r)m4up_expect($r['state']==='completed'&&$r['changed']===false&&$r['issues']===[]
  &&$r['menu_ids']===$apply['menu_ids']&&$r['anchors']===$check['anchors'],'UI_NORMAL_CHECK_AND_REAPPLY_UNCHANGED');
 m4up_expect($again['created_menu_ids']===[],'UI_REAPPLY_NO_NEW_NATIVE_ID');m4up_cold($app);
 m4fn_context($app,$connection,$default,$db);return ['check'=>$check,'apply'=>$apply,'postcheck'=>$post,'reapply'=>$again];
}
function m4up_diagnostic(Throwable $e):array
{
 return ['code'=>$GLOBALS['m4up_failed_assertion']??$GLOBALS['m4ar_failed_assertion']??$GLOBALS['m4fn_failed_assertion']??'UI_PREPARE_UNPROVED',
 'class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()];
}
function m4up_result(array $s,?array $business,?array $diagnostic,bool $ok):array
{
 return ['schema'=>1,'scope'=>M4UP_RULE['scope'],'status'=>$ok?M4UP_RULE['status']:'INCOMPLETE_MS4_UI_PREPARATION_ONLY',
 'run_id'=>$s['run_id'],'source_sha'=>$s['source_sha'],'container_id'=>$s['container_id'],'freeze_sha256'=>$s['freeze_sha256'],
 'native_created_sha256'=>$s['native_created_sha256'],'source_binding_sha256'=>$s['source_binding_sha256'],
 'environment_sha256'=>$s['environment_sha256'],'environment_native_sha256'=>$s['environment_native_sha256'],
 'phase'=>$GLOBALS['m4c_phase']??'source','entry_return_code'=>$ok?0:1,'termination'=>$ok?'NORMAL_RETURN':'FAILED',
 'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED','full_source_unchanged'=>$ok,'business'=>$business,
 'child'=>$GLOBALS['m4ar_child_proof']??null,'diagnostic'=>$diagnostic,'three_end_login_DOM'=>'NOT RUN',
 'N1_fulfillment_review_business'=>'NOT RUN','full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN',
 'assertions'=>$GLOBALS['m4up_assertions']];
}
function m4up_run():int
{
 m4up_phase('source');$s=m4up_source();$GLOBALS['m4up_source']=$s;
 $db=audit_db();m4up_expect(!$db->inTransaction(),'UI_INDEPENDENT_OBSERVER_NO_TRANSACTION');
 $observer=m5pc_observer_configure($db);$cuPrivateBefore=m4cf_native_files();$cuTreeBefore=m4upo_tree(M4CF_ROOT);
 m4up_expect(is_array($cuTreeBefore),'UI_CU_ORIGINAL_PRIVATE_ROOT_DIRECTORY_FILE_WINDOW_PRESENT');$owner=m4up_owner($s,$db);
 $languagePaidSnapshot=m5pcp_snapshot($db);
 m4up_expect(hash('sha256',serialize($languagePaidSnapshot))===$owner['baseline']['full_database_redis_sha256']
  &&count($languagePaidSnapshot['names'])===$owner['baseline']['full_database_table_count'],
  'UI_LANGUAGE_REAL_FULL_POSTPAID_SNAPSHOT_HASH_AND_COMPLETE_NAMES');
 m4ar_language_prepare($db,$s,$languagePaidSnapshot['names']);unset($languagePaidSnapshot);
 $before=m4upo_snapshot($db);$started=time();$credentials=null;$writer=null;$actors=null;$business=null;$failure=null;
 try{
  m4up_phase('owned_public_private_preparation');$private=m4ar_private_prepare();m4c_entry_public_materials($s);m4up_full_source($s);
  $app=m4fn_app($s);m4up_expect($app->config->get('marketplace.aftersale.enabled')===true
   &&$app->config->get('marketplace.aftersale.private_root')===M4AR_PRIVATE,'UI_REAL_AFTERSALE_CONFIGURATION');
  m4cf_owner_after($owner['baseline']['customer_form'],$owner['baseline']['authenticated_owner']['actual_user'],$owner['uid']);
  $menus=m4up_menus($app,$db);m4up_cold($app);m4ar_warm($app,$db);m4up_cold($app);
  m4ar_child_start($s);
  $sink=static function(#[\SensitiveParameter] array $real)use(&$credentials,&$writer):void{
   m4up_expect($credentials===null&&$writer===null,'UI_ONE_REAL_PRIVATE_HANDOFF');
   m4up_expect(strlen($real['customer']['password'])===24&&strlen($real['merchant']['password'])===49
    &&strlen($real['platform']['password'])===31,'UI_ORIGINAL_THREE_PASSWORD_LENGTHS');
   $credentials=$real;$writer=m4ui_credentials_write($real);
  };
  $actors=m4ar_actors($app,$db,$s,$owner,$sink,true);$ended=time();
  m4up_expect(is_array($writer)&&is_array($credentials),'UI_REAL_WRITER_DESCRIPTOR_CAPTURED');
  $window=m4upo_verify($db,$app,$s,$owner,$before,$actors,$credentials,$writer,$menus,[$started,$ended]);
 }catch(Throwable $e){$failure=$e;$GLOBALS['m4up_primary_failure']=m4up_diagnostic($e);}
 finally{
  if(is_resource($GLOBALS['m4ar_http_process']??null)||is_array($GLOBALS['m4ar_http_group']??null)){
   do{try{$child=m4ar_child_close();}catch(Throwable $close){$GLOBALS['m4up_cleanup_failure']=m4up_diagnostic($close);$child=null;if($failure===null)$failure=$close;}
    if($child===null||!$child['stopped'])usleep(20000);
   }while($child===null||!$child['stopped']);
  }
 }
 if($failure!==null)throw $failure;
 $child=$GLOBALS['m4ar_child_proof']??null;
 m4up_expect(is_array($child)&&$child['stopped']===true&&$child['remaining_members']===[]&&$child['listener_open']===false
  &&is_array($child['group'])&&is_array($child['first_terminal'])&&$child['first_terminal']['running']===false
  &&$child['first_terminal']['stopped']===false&&$child['first_terminal']['stopsig']===0
  &&in_array($child['first_terminal']['exitcode'],[-1,0],true)&&in_array($child['first_terminal']['termsig'],[0,SIGTERM],true)
  &&in_array($child['proc_close_exit'],[-1,0],true)&&$child['term_attempted']===true&&$child['stop_signal']===SIGTERM
  &&$child['first_terminal']['pid']===$child['group']['leader_pid']
  &&$child['group']['pgid']===$child['group']['leader_pid']&&$child['group']['sid']===$child['group']['leader_pid']
  &&is_array($child['streams'])&&count($child['streams'])===2&&empty($GLOBALS['m4ar_cleanup_failure'])
  &&empty($GLOBALS['m4up_cleanup_failure']),'UI_ORIGINAL_SAME_CHILD_NORMAL_SHUTDOWN');
 $final=m4upo_verify($db,$app,$s,$owner,$before,$actors,$credentials,$writer,$menus,[$started,$ended]);
 $task2=null;if(m5pt_observer_source($s)!==null){
  $task2=m5pt_prepare($db,$app,$s,$owner,$actors,$final);$final['after']=$task2['after'];
 }
 $sourceOracle=m4fn_source_oracle($owner['saved']);m4fn_inputs_unchanged($owner['inputs']);
 m4up_expect(m4cf_files()===$cuPrivateBefore,'UI_CU_COMPLETE_ORIGINAL_PRIVATE_TREE_NATIVE_BEFORE_FINAL_UNCHANGED');
 m4up_expect(m4upo_tree(M4CF_ROOT)===$cuTreeBefore,'UI_CU_ORIGINAL_PRIVATE_ROOT_DIRECTORIES_FILES_NATIVE_BEFORE_FINAL_UNCHANGED');
 m4up_phase('final_source');m4up_full_source($s);m4up_expect(m4up_source(false)===$s,'UI_CURRENT_SAME_SOURCE_ENV_NATIVE_PREDECESSORS');
 m4cb_guard_readonly();
 $business=['prepared_only'=>true,'paid_order_id'=>(int)$owner['root']['id'],'paid_order_no'=>$owner['root']['order_id'],
  'merchant_id'=>(int)$owner['root']['mer_id'],'customer_id'=>$owner['uid'],
  'item_quantities'=>[['item_id'=>$sourceOracle['q2'],'quantity'=>2],['item_id'=>$sourceOracle['q1'],'quantity'=>1]],
  'source_fingerprint'=>$sourceOracle['fingerprint'],'actor_ids'=>['customer'=>$actors['customer']['id'],
   'merchant'=>$actors['merchant']['id'],'platform'=>$actors['platform']['id']],
  'writer'=>$writer,'menus'=>$menus,'window_before'=>$final['before'],'window_final'=>$final['after'],
  'independent_full_window_verified'=>true,'post_child_close_verified'=>true,'observer'=>$observer];
 if($task2!==null)m4c_entry_publish('/run/audit/evidence/ms5-task2-target-preparation.json',$task2['public']);
 unset($credentials,$actors,$owner,$before,$window,$final,$sink,$task2);
 m4c_entry_publish('/run/audit/evidence/'.M4UP_RULE['result'],m4up_result($s,$business,null,true));
 $GLOBALS['m4up_result_published']=true;$GLOBALS['m4up_completed']=true;
 echo json_encode(['scope'=>M4UP_RULE['scope'],'status'=>M4UP_RULE['status'],'phase'=>'final_source',
  'entry_return_code'=>0,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
 if(($argv??[])===[$_SERVER['SCRIPT_FILENAME'],'--help']){echo "Usage: php help/audit-regressions/isolated/ms4-ui-prepare.php prepare\nIsolated ordinary paid-owner UI fixture preparation only; host native 0 required.\n";exit(0);}
 $GLOBALS['m4up_assertions']=0;$GLOBALS['m4ar_assertions']=0;$GLOBALS['m4ar_http_calls']=[];
 ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
 set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
  if((error_reporting()&$severity)===0)return false;$GLOBALS['m4up_failed_assertion']='UI_PHP_WARNING';throw new ErrorException('UI_PHP_WARNING',0,$severity,$file,$line);
 });
 pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
 register_shutdown_function(static function():void{
  $s=$GLOBALS['m4up_source']??null;if(!is_array($s))return;
  if(is_resource($GLOBALS['m4ar_http_process']??null)||is_array($GLOBALS['m4ar_http_group']??null)){
   do{try{$c=m4ar_child_close();}catch(Throwable $e){$GLOBALS['m4up_cleanup_failure']=m4up_diagnostic($e);$c=null;}
    if($c===null||!$c['stopped'])usleep(20000);
   }while($c===null||!$c['stopped']);
  }
  $last=error_get_last();$fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
  $v=['schema'=>1,'scope'=>'MS4_UI_PREPARE_SHUTDOWN','run_id'=>$s['run_id'],'source_sha'=>$s['source_sha'],
   'container_id'=>$s['container_id'],'freeze_sha256'=>$s['freeze_sha256'],'phase'=>$GLOBALS['m4c_phase']??'source',
   'completed'=>($GLOBALS['m4up_completed']??false)===true,'result_published'=>($GLOBALS['m4up_result_published']??false)===true,
   'fatal'=>$fatal,'termination_requested'=>($GLOBALS['m4c_terminate_requested']??false)===true,
   'primary_failure'=>$GLOBALS['m4up_primary_failure']??null,'cleanup_failure'=>$GLOBALS['m4up_cleanup_failure']??$GLOBALS['m4ar_cleanup_failure']??null,
   'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'];
  try{m4c_entry_publish('/run/audit/evidence/ui-prepare-shutdown.json',$v);}catch(Throwable $e){fwrite(STDERR,"UI shutdown receipt unavailable; host native observation required\n");}
 });
 try{exit(m4up_run());}catch(Throwable $e){
  $d=$GLOBALS['m4up_primary_failure']??m4up_diagnostic($e);$GLOBALS['m4up_primary_failure']=$d;$s=$GLOBALS['m4up_source']??null;
  if(is_array($s)&&empty($GLOBALS['m4up_result_published']))try{m4c_entry_publish('/run/audit/evidence/'.M4UP_RULE['result'],m4up_result($s,null,$d,false));$GLOBALS['m4up_result_published']=true;}catch(Throwable $ignored){}
  echo json_encode(['scope'=>M4UP_RULE['scope'],'status'=>'INCOMPLETE_MS4_UI_PREPARATION_ONLY','phase'=>$GLOBALS['m4c_phase']??'source',
   'entry_return_code'=>1,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";exit(1);
 }
}
