<?php
declare(strict_types=1);

/** Finite Task7/8 positive HTTP continuation. No production or stopped cases. */
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderCreateServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\marketplace\payment\MarketplacePaymentServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use crmeb\services\CacheService;
use crmeb\services\pay\marketplace\PaymentAccountResolver;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use think\facade\Db;

require_once __DIR__.'/ms4-fulfillment-normal.php';
const M4AR_ENTRY='help/audit-regressions/isolated/ms4-aftersale-review-normal.php';
const M4AR_RULE=['role'=>'audit-ms4-aftersale-review-normal','proof'=>'aftersale-review-normal',
 'binding'=>'ms4-aftersale-review-normal-source-binding.json','binding_scope'=>'MS4_AFTERSALE_REVIEW_NORMAL_SOURCE',
 'environment'=>'aftersale-review-normal-environment.json','result'=>'ms4-aftersale-review-normal.json',
 'scope'=>'NORMAL_MS4_AFTERSALE_REVIEW_HTTP_ONLY','status'=>'VERIFIED_NORMAL_MS4_AFTERSALE_REVIEW_HTTP_ONLY'];
const M4AR_PRIVATE='/var/lib/crmeb-aftersale-evidence';
const M4AR_SEAL='/run/audit-fixtures/ms4-aftersale-review-stage-final.json';
const M4AR_PAID_SEAL='/run/audit-fixtures/ms4-aftersale-review-paid-baseline.json';
const M4AR_PUBLIC_KEEP='/run/audit-fixtures/ms4-aftersale-review-public-file-keep.json';
const M4W_BEFORE='/run/audit-fixtures/ms4-worker-normal-before.json';
const M4W_AFTER='/run/audit-fixtures/ms4-worker-normal-after.json';
const M4AR_EARLY=['ms4-fulfillment-migration-container-created.json','ms4-fulfillment-migration-source-binding.json',
 'ms4-aftersale-early-migration-normal.json','ms4-fulfillment-migration-terminal.json'];
const M4AR_TASK6=['fulfillment-normal-created.json','ms4-fulfillment-normal-source-binding.json',
 'fulfillment-normal-environment.json','fulfillment-normal-environment-native.json','ms4-fulfillment-normal.json',
 'fulfillment-normal-shutdown.json','fulfillment-normal-terminal.json','fulfillment-normal-removed.json','ms4-three-read-normal.json'];
const M4AR_TABLES=['eb_store_order','eb_store_order_cart_info','eb_store_order_status','eb_store_order_refund',
 'eb_marketplace_aftersale_action','eb_marketplace_aftersale_file','eb_marketplace_fulfillment_operation',
 'eb_marketplace_fulfillment_range','eb_marketplace_stock_movement','eb_store_product','eb_store_product_attr_value',
 'eb_store_product_reply','eb_system_attachment','eb_marketplace_customer_asset','eb_marketplace_audit_log','eb_system_log','eb_user',
 'eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup','eb_system_role','eb_system_admin'];
function m4ar_expect(bool $ok,string $code):void
{++$GLOBALS['m4ar_assertions'];if(!$ok){$GLOBALS['m4ar_failed_assertion']=$code;throw new RuntimeException('M4AR_ASSERTION');}}
function m4ar_phase(string $phase):void
{m4ar_expect(empty($GLOBALS['m4c_terminate_requested']),'TASK78_NOT_INTERRUPTED');$GLOBALS['m4c_phase']=$phase;}
function m4ar_extra():array
{
 $paths=array_values(array_unique(array_merge(m4fn_extra(),[M4AR_ENTRY,
 'help/migrations/marketplace-ms4-aftersale.php','help/migrations/marketplace-ms4-aftersale-menus.php',
 'help/migrations/marketplace-ms4-review-menus.php']),SORT_STRING));sort($paths,SORT_STRING);
 m4ar_expect(count($paths)===28,'TASK78_FINITE_TWENTY_EIGHT_EXTRAS');return $paths;
}
function m4ar_prerequisite_names():array
{return array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES,
 M4FN_PAID_FILES,M4AR_EARLY,M4AR_TASK6);}
function m4ar_inventory(string $root):array
{
    $paths=m4ar_extra();
    foreach(M4C_ROOTS as$directory){
        m4ar_expect(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory),'TASK6_SOURCE_DIRECTORY');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as$file){m4ar_expect(!$file->isLink(),'TASK6_SOURCE_NO_LINK');
            if($file->isFile())$paths[]=substr($file->getPathname(),strlen($root)+1);}
    }
    sort($paths,SORT_STRING);m4ar_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_UNIQUE_PATHS');return $paths;
}
function m4ar_full_source(array $source,bool $beforePublicCopy=false):void
{
    $paths=[];$allowed=[];
    m4ar_expect(is_array($source['source_files'])&&array_is_list($source['source_files']),'TASK6_SOURCE_DESCRIPTOR_LIST');
    foreach($source['source_files']as$row){m4c_entry_keys($row,['path','bytes','sha256']);
        m4ar_expect(is_string($row['path'])&&$row['path']!==''&&$row['path'][0]!=='/'&&!str_contains($row['path'],'\\')
            &&!str_contains($row['path'],':')&&!in_array('..',explode('/',$row['path']),true)
            &&is_int($row['bytes'])&&$row['bytes']>=0&&m5pc_hex($row['sha256']),'TASK6_SOURCE_DESCRIPTOR');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);
    m4ar_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_SORTED_CLOSED_SET');
    foreach(['/source','/workspace']as$root){m4ar_expect(m4ar_inventory($root)===$paths,'TASK6_SOURCE_INVENTORY');
        foreach($source['source_files']as$row){
            if($beforePublicCopy&&$root==='/workspace'&&in_array($row['path'],['crmeb/public/index.php','crmeb/public/router.php'],true))continue;
            $path=$root.'/'.$row['path'];m4ar_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'TASK6_SOURCE_ALL_BYTES');$allowed[$path]=true;}}
    foreach(get_included_files()as$path)m4ar_expect(isset($allowed[realpath($path)]),'TASK6_INCLUDED_COMPLETE_SOURCE');
}
function m4ar_source(): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'normal']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_ACTUAL_FINITE_NATIVE_ENTRY');
    $rule=M4AR_RULE;$evidence='/run/audit/evidence/';
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
    m4ar_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'TASK6_ONLY_FOUR_ACTUAL_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    m4ar_expect($freeze['aftersale_review_source_roots']===M4C_ROOTS&&$freeze['aftersale_review_source_extra']===m4ar_extra(),
        'TASK6_REAL_FOUR_ROOTS_FINITE_EXTRA_CLOSURE');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
    m4c_expect($binding['schema']===1&&$binding['scope']===$rule['binding_scope']&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$rule['proof'].'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['aftersale_review_source_files']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha
        &&$created['proof_name']===$rule['proof']&&$created['hostname']===gethostname()&&$created['read_only']===true
        &&$created['user']==='0:0'&&posix_geteuid()===0&&posix_getegid()===0
        &&$created['privileged']===false&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4AR_ENTRY,'normal']&&$created['workdir']==='/workspace'
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
    m4ar_expect($created['stop_signal']==='SIGTERM'&&getmypid()===1
        &&in_array('audit-app',$created['networks'][$network['Name']]['Aliases']??[],true)
        &&gethostbyname('audit-app')===gethostbyname(gethostname()),'TASK78_REAL_PID_ONE_AND_SELF_HTTP_ALIAS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',true],
        M4AR_PRIVATE=>['aftersale-evidence',true]];
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
    $values=m4ar_environment($binding,$context);
    audit_guard_assert();
    $source=$binding+['rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment']),
        'environment_native_sha256'=>m4c_entry_hash($evidence.$rule['proof'].'-environment-native.json')];
    m4ar_full_source($source,true);
    m4ar_prerequisites($source);
    return $source;
}

function m4ar_environment(array $binding,array $context):array
{
 $base='/run/audit/evidence/';$e=m4c_entry_read($base.M4AR_RULE['environment']);
 m4c_entry_keys($e,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
 m4ar_expect($e['schema']===1&&$e['scope']==='MS4_AFTERSALE_REVIEW_NORMAL_REAL_ENVIRONMENT'
  &&$e['run_id']===$binding['run_id']&&$e['source_sha']===$binding['source_sha']&&$e['container_id']===$binding['container_id']
  &&$e['freeze_sha256']===$context['freeze_sha256']&&getenv('MS4_AFTERSALE_REVIEW_PROOF_NAME')===M4AR_RULE['proof'],'TASK78_REAL_ENVIRONMENT_IDENTITY');
 $v=$e['environment'];m4c_entry_keys($v,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
 'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER',
 'PHP_MARKETPLACE_AFTERSALE_ENABLED','PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT']);
 m4ar_expect($v['APP_ENV']==='testing'&&$v['PHP_CACHE_DRIVER']==='redis'&&$v['PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT']===M4AR_PRIVATE,
 'TASK78_ONLY_ACTUAL_NORMAL_ROLE_PRIVATE_CONFIGURATION');
 foreach(['PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_AFTERSALE_ENABLED']as$k)
  m4ar_expect($v[$k]==='true','TASK78_ENABLED_TRUE_STRINGS');
 m5pcp_int($v['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],1,4294967295);
 $n=m4c_entry_read($base.M4AR_RULE['proof'].'-environment-native.json');
 m4c_entry_keys($n,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
 m4ar_expect($n['stage']==='aftersale-review-normal-public-environment-inspect'&&$n['native_exit']===0&&$n['stderr_bytes']===0
  &&$n['stderr_sha256']===hash('sha256','')&&$n['private_environment_logs_omitted']===true
  &&$e['native_projection_sha256']===$n['stdout_sha256'],'TASK78_REAL_EIGHT_VALUE_NATIVE_RECEIPT');
 // Host emits exactly these eight sorted JSON-string lines; no six-value permutation proof is reused.
 ksort($v,SORT_STRING);$lines=[];foreach($v as$k=>$value){m4ar_expect(is_string($value)&&getenv($k)===$value,'TASK78_EVERY_ACTUAL_ENVIRONMENT_VALUE');
  $lines[]=json_encode($k.'='.$value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 $raw=implode("\n",$lines)."\n";
 m4ar_expect(strlen($raw)===$n['stdout_bytes']&&hash('sha256',$raw)===$n['stdout_sha256'],'TASK78_EXACT_SORTED_EIGHT_LINE_NATIVE_PROJECTION');return $v;
}
function m4ar_prerequisites(array $source):void
{
 $base='/run/audit/evidence/';$hashes=$source['prerequisites_sha256'];m4c_entry_keys($hashes,m4ar_prerequisite_names());
 m4ar_expect(count($hashes)===34,'TASK78_EXACT_THIRTY_FOUR_PUBLIC_PREDECESSORS');
 foreach($hashes as$name=>$hash)m4ar_expect(m5pc_hex($hash)&&m4c_entry_hash($base.$name)===$hash,'TASK78_ALL_ORIGINAL_PREDECESSOR_BYTES');
 $old=m4c_entry_terminal($source,'fulfillment-normal',M4FN_RULE['role'],[M4FN_ENTRY,'normal']);
 $result=m4c_entry_read($base.M4FN_RULE['result']);$shutdown=m4c_entry_read($base.'fulfillment-normal-shutdown.json');
 m4ar_expect($result['schema']===1&&$result['scope']===M4FN_RULE['scope']&&$result['status']===M4FN_RULE['status']
  &&$result['native_exit']===null&&$result['native_exit_observation']==='HOST_NATIVE_REQUIRED'
  &&$result['entry_return_code']===0&&$result['termination']==='NORMAL_RETURN'&&$result['phase']==='final_source'
  &&$result['full_source_unchanged']===true&&$result['diagnostic']===null&&$result['task7']==='NOT RUN'
  &&$result['container_id']===$old['id']&&$result['source_sha']===$source['source_sha']&&$result['run_id']===$source['run_id']
  &&$result['freeze_sha256']===$source['freeze_sha256']&&$result['native_created_sha256']===$hashes['fulfillment-normal-created.json']
  &&$result['source_binding_sha256']===$hashes['ms4-fulfillment-normal-source-binding.json'],'TASK78_ORIGINAL_TASK6_COMPLETED_SCOPE_NOT_UPGRADED');
 m4ar_expect($shutdown['scope']==='MS4_FULFILLMENT_NORMAL_SHUTDOWN'&&$shutdown['container_id']===$old['id']
  &&$shutdown['completed']===true&&$shutdown['result_published']===true&&$shutdown['fatal']===false
  &&$shutdown['termination_requested']===false&&$shutdown['primary_failure']===null&&$shutdown['cleanup_failure']===null,
  'TASK78_ORIGINAL_TASK6_GENUINE_NORMAL_SHUTDOWN');
 $removed=m4c_entry_read($base.'fulfillment-normal-removed.json');m4c_entry_keys($removed,['schema','scope','run_id','source_sha',
 'container_id','created_sha256','terminal_sha256','native_remove','absent_after','target_role']);
 m4ar_expect($removed['schema']===1&&$removed['scope']==='MS4_FULFILLMENT_NORMAL_EXACT_REMOVAL'
  &&$removed['run_id']===$source['run_id']&&$removed['source_sha']===$source['source_sha']&&$removed['container_id']===$old['id']
  &&$removed['created_sha256']===$hashes['fulfillment-normal-created.json']&&$removed['terminal_sha256']===$hashes['fulfillment-normal-terminal.json']
  &&$removed['absent_after']===true&&$removed['target_role']===M4FN_RULE['role'],'TASK78_ORIGINAL_TASK6_PRECEDES_DISTINCT_NORMAL_CID');
 $rm=$removed['native_remove'];m4c_entry_keys($rm,['argv','native_exit','stdout_sha256','stderr_sha256','same_handle_terminal']);
 $term=$rm['same_handle_terminal'];m4c_entry_keys($term,['pid','returncode','wait_returncode']);
 m4ar_expect($rm['argv']===['docker','rm',$old['id']]&&$rm['native_exit']===0&&$rm['stdout_sha256']===hash('sha256',$old['id']."\n")
  &&$rm['stderr_sha256']===hash('sha256','')&&is_int($term['pid'])&&$term['pid']>0&&$term['returncode']===0&&$term['wait_returncode']===0
  &&$old['id']!==$source['container_id'],'TASK78_EXACT_NONFORCE_OLD_CID_REMOVAL_NATIVE_SOURCE');
 $early=m4c_entry_early_history($source);m4c_entry_keys($early,['hashes','result','binding','created','terminal']);
 foreach(M4AR_EARLY as$name)m4ar_expect(($early['hashes'][$name]??null)===$hashes[$name],'TASK78_SHARED_REAL_EARLY_SIXTEEN_FULL_VALIDATION');
 $three=m4c_entry_read($base.'ms4-three-read-normal.json');
 m4ar_expect($three['run_id']===$source['run_id']&&$three['source_sha']===$source['source_sha']
  &&$three['container_id']===$old['id']&&$three['diagnostic']===null,'TASK78_ORIGINAL_TASK6_THREE_READ_SOURCE');
 $wanted=[];foreach(['mysql-data','redis-data','bootstrap-secrets','redis-secrets','app-secrets','fixture-secrets','aftersale-evidence']as$suffix)
  $wanted[]=$source['context']['project'].'_'.$suffix;
 $actual=audit_manifest()['volumes'];sort($wanted,SORT_STRING);sort($actual,SORT_STRING);
 m4ar_expect($actual===$wanted,'TASK78_EXACT_SEVEN_CURRENT_VOLUMES');
}
function m4ar_private_input(string $path,int $max):array
{
 m4ar_expect(in_array($path,[M4CB_FIXTURE,M5PC_BASELINE,M4FN_BASELINE,M4AR_PAID_SEAL,M4AR_SEAL,M4AR_PUBLIC_KEEP,M4W_BEFORE,M4W_AFTER],true),'TASK78_FIXED_PRIVATE_PATHS');
 $parent=dirname($path);$ps=lstat($parent);clearstatcache(true,$path);$a=lstat($path);
 m4ar_expect(is_array($ps)&&is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent&&($ps['mode']&0777)===0700
  &&$ps['uid']===posix_geteuid()&&is_array($a)&&is_file($path)&&!is_link($path)&&realpath($path)===$path
  &&($a['mode']&0170000)===0100000&&($a['mode']&0777)===0600&&$a['nlink']===1&&$a['uid']===posix_geteuid()
  &&$a['size']>0&&$a['size']<=$max,'TASK78_PRIVATE_OWNER_MODE_COMPLETE_REGULAR');
 $raw=file_get_contents($path);clearstatcache(true,$path);$b=lstat($path);$fields=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
 m4ar_expect(is_string($raw)&&strlen($raw)===$a['size']&&is_array($b),'TASK78_PRIVATE_COMPLETE_READ');
 $stat=array_intersect_key($a,array_flip($fields));m4ar_expect(array_intersect_key($b,array_flip($fields))===$stat,'TASK78_PRIVATE_STABLE_STAT');
 return ['path'=>$path,'raw'=>$raw,'sha256'=>hash('sha256',$raw),'stat'=>$stat,'value'=>json_decode($raw,true,128,JSON_THROW_ON_ERROR)];
}
function m4ar_inputs_unchanged(array $inputs):void
{foreach($inputs as$i)m4ar_expect(m4ar_private_input($i['path'],$i['path']===M4CB_FIXTURE?1048576:67108864)===$i,'TASK78_ORIGINAL_PRIVATE_INPUTS_UNCHANGED');}
function m4ar_owner(array $source,PDO $db):array
{
 $old=$source;$old['environment']=array_diff_key($source['environment'],array_flip(['PHP_MARKETPLACE_AFTERSALE_ENABLED','PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT']));
 $loaded=m4cb_fixture_load($old);$inputs=[m4ar_private_input(M4CB_FIXTURE,1048576),m4ar_private_input(M5PC_BASELINE,2097152),
  m4ar_private_input(M4FN_BASELINE,67108864)];$paid=$inputs[1]['value'];$envelope=$inputs[2]['value'];
 m4c_entry_keys($envelope,['schema','scope','run_id','source_sha','freeze_sha256','container_id','encoding','bytes','sha256','payload']);
 $public=m4c_entry_read('/run/audit/evidence/'.M4FN_RULE['result']);$seal=$public['business']['baseline'];
 m4ar_expect($envelope['schema']===1&&$envelope['scope']==='MS4_FULFILLMENT_NORMAL_STAGE6_NATIVE_ARCHIVE'
  &&$envelope['run_id']===$source['run_id']&&$envelope['source_sha']===$source['source_sha']&&$envelope['freeze_sha256']===$source['freeze_sha256']
  &&$envelope['container_id']===$public['container_id']&&$envelope['encoding']==='php-serialize-base64-v1'
  &&$seal['path_basename']===basename(M4FN_BASELINE)&&$seal['bytes']===$inputs[2]['stat']['size']&&$seal['sha256']===$inputs[2]['sha256'],
 'TASK78_REAL_STAGE6_PUBLIC_AND_PRIVATE_FULL_SOURCE');
 $bytes=base64_decode($envelope['payload'],true);m4ar_expect(is_string($bytes)&&base64_encode($bytes)===$envelope['payload']
  &&strlen($bytes)===$envelope['bytes']&&hash('sha256',$bytes)===$envelope['sha256']&&$envelope['bytes']===$seal['native_archive_bytes']
  &&$envelope['sha256']===$seal['native_archive_sha256'],'TASK78_STAGE6_STRICT_FULL_ARCHIVE_BYTES');
 $stage=unserialize($bytes,['allowed_classes'=>false,'max_depth'=>128]);
 m4ar_expect(is_array($stage)&&serialize($stage)===$bytes&&$stage['schema']===1&&$stage['scope']==='MS4_FULFILLMENT_NORMAL_STAGE6_NATIVE_FACTS'
  &&$stage['container_id']===$public['container_id']&&$stage['source_binding_sha256']===$public['source_binding_sha256']
  &&$stage['prerequisites_sha256']===$public['prerequisites_sha256'],'TASK78_STAGE6_NATIVE_PAYLOAD_EXACT_SOURCE');
 m4ar_expect($stage['produced']['final']['database']===m5pcp_database_snapshot($db)
  &&m5pc_native_snapshot_restore($stage['final_native_34_table_archive'])===m5pc_native_snapshot($db)
  &&m5pcp_redis_deadline_compatible($stage['produced']['final']['redis'],m5pcp_atomic_redis(audit_redis())),'TASK78_TRUE_STAGE6_IS_CURRENT_COMPLETE_BASELINE');
 foreach(['original_fixture'=>0,'original_paid_baseline'=>1]as$k=>$i){$d=$stage[$k];
  m4ar_expect($d['path']===$inputs[$i]['path']&&$d['bytes']===$inputs[$i]['stat']['size']&&$d['sha256']===$inputs[$i]['sha256']
   &&$d['stat']===$inputs[$i]['stat'],'TASK78_STAGE6_ORIGINAL_PRIVATE_DESCRIPTOR');}
 $uid=m5pcp_int($loaded['private']['fixture']['user']['uid'],1);$root=m4cb_rows($db,'SELECT * FROM eb_store_order WHERE id=?',[$paid['order_id']]);
 m4ar_expect(count($root)===1&&(int)$root[0]['uid']===$uid&&(int)$root[0]['paid']===1&&(int)$root[0]['status']===2
  &&$root[0]['order_id']===$paid['order_no']&&$paid['authenticated_owner']['uid']===$uid
  &&$paid['run_id']===$source['run_id']&&$paid['source_sha']===$source['source_sha'],
 'TASK78_ORIGINAL_ACTUAL_PAID_AND_RECEIVED_ROOT');
 m4c_entry_same($paid['environment'],$old['environment']);
 $user=m4cb_rows($db,'SELECT * FROM eb_user WHERE uid=?',[$uid]);m4ar_expect(count($user)===1&&(int)$user[0]['status']===1&&(int)$user[0]['is_del']===0
  &&$user[0]['account']===$loaded['private']['account'],'TASK78_STAGE6_CURRENT_REAL_OWNER');
 m4ar_inputs_unchanged($inputs);return ['uid'=>$uid,'root'=>$root[0],'loaded'=>$loaded,'inputs'=>$inputs,'stage6'=>$stage,
  'fixture'=>$loaded['private']['fixture'],'user'=>$user[0]];
}
function m4ar_private_prepare():array
{
 m4ar_expect(is_dir(M4AR_PRIVATE)&&!is_link(M4AR_PRIVATE)&&realpath(M4AR_PRIVATE)===M4AR_PRIVATE,'TASK78_EXACT_PRIVATE_MOUNT_ROOT');
 $s=lstat(M4AR_PRIVATE);$names=scandir(M4AR_PRIVATE);
 m4ar_expect(is_array($s)&&($s['mode']&0170000)===0040000&&$s['uid']===posix_geteuid()&&$names===['.','..'],
 'TASK78_NEW_PRIVATE_VOLUME_EMPTY_ACTUAL_OWNER');
 foreach(['/var','/var/lib']as$p){$v=lstat($p);m4ar_expect(is_array($v)&&is_dir($p)&&!is_link($p)&&realpath($p)===$p
  &&($v['mode']&0022)===0,'TASK78_PRIVATE_PARENT_CHAIN_NOT_OTHER_WRITABLE');}
 m4ar_expect(chmod(M4AR_PRIVATE,0700),'TASK78_ONLY_NEW_EMPTY_VOLUME_ROOT_MODE');clearstatcache(true,M4AR_PRIVATE);$a=lstat(M4AR_PRIVATE);
 m4ar_expect(is_array($a)&&($a['mode']&0777)===0700&&$a['uid']===posix_geteuid()&&$a['ino']===$s['ino']&&$a['dev']===$s['dev']
  &&scandir(M4AR_PRIVATE)===['.','..'],'TASK78_REAL_PRIVATE_PREPARATION_READBACK');return array_intersect_key($a,array_flip(['dev','ino','mode','uid','gid','nlink']));
}
function m4ar_files(string $root):array
{
 m4ar_expect(is_dir($root)&&!is_link($root),'TASK78_FILE_INVENTORY_ROOT');$out=[];
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
 foreach($it as$f){m4ar_expect(!$f->isLink(),'TASK78_FILE_INVENTORY_NO_SYMLINK');if(!$f->isFile())continue;
  $p=$f->getPathname();$s=lstat($p);$out[substr($p,strlen($root)+1)]=['bytes'=>$s['size'],'sha256'=>hash_file('sha256',$p),
  'mode'=>$s['mode']&0777,'uid'=>$s['uid'],'nlink'=>$s['nlink']];}ksort($out,SORT_STRING);return $out;
}
function m4ar_tables():array
{
 $names=array_values(array_unique(array_merge(M4AR_TABLES,M4CB_TABLES,M5PC_AUX_TABLES),SORT_STRING));sort($names,SORT_STRING);return $names;
}
function m4ar_snapshot(PDO $db):array
{
 $database=m5pcp_database_snapshot($db);$rows=[];
 foreach(m4ar_tables()as$table){m4ar_expect(in_array($table,$database['names'],true),'TASK78_ACTUAL_ORACLE_TABLE_EXISTS');
  $rows[$table]=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table));}
 return ['database'=>$database,'rows'=>$rows,'redis'=>m5pcp_atomic_redis(audit_redis()),'private'=>m4ar_files(M4AR_PRIVATE),
  'public'=>m4ar_files('/workspace/crmeb/public')];
}
function m4ar_native_rows(array $snapshot,string $table):array
{return $snapshot['rows'][$table];}
function m4ar_new_rows(array $before,array $after):array
{
 $bag=[];foreach($before as$r){$k=hash('sha256',serialize($r));$bag[$k]=($bag[$k]??0)+1;}$out=[];
 foreach($after as$r){$k=hash('sha256',serialize($r));if(($bag[$k]??0)>0)--$bag[$k];else $out[]=$r;}
 foreach($bag as$n)m4ar_expect($n===0,'TASK78_NO_UNDECLARED_REMOVAL_OR_ROW_REWRITE');return $out;
}
function m4ar_one(array $rows,string $pk,mixed $id):array
{$found=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r[$pk]===(string)$id));m4ar_expect(count($found)===1,'TASK78_UNIQUE_NATIVE_ROW');return $found[0];}
function m4ar_replaced(array $rows,string $pk,mixed $id,array $replacement):array
{$n=0;foreach($rows as&$r)if((string)$r[$pk]===(string)$id){$r=$replacement;++$n;}unset($r);m4ar_expect($n===1,'TASK78_EXACT_ONE_OWN_ROW_REPLACEMENT');return $rows;}
/** Every changed table has already independently constructed full expected rows and exact insert count. */
function m4ar_database_delta(array $before,array $after,array $expectedRows,array $insertCounts=[]):void
{
 $expected=$before['database'];
 foreach($expectedRows as$table=>$rows){m4ar_expect(in_array($table,m4ar_tables(),true),'TASK78_FINITE_DB_ORACLE_LEAF');
  $old=$before['database']['tables'][$table];$new=$after['database']['tables'][$table];$n=$insertCounts[$table]??0;
  m4ar_expect(m4fn_row_multiset($after['rows'][$table])===m4fn_row_multiset($rows)&&$new['projection']===m4fn_projection($rows),
   'TASK78_COMPLETE_EXPECTED_NATIVE_ROWS');
  if($n===0||$old['metadata']['table']['AUTO_INCREMENT']===null)m4ar_expect($new['metadata']===$old['metadata']&&$new['ddl']===$old['ddl'],
   'TASK78_UNCHANGED_TARGET_COMPLETE_METADATA');
  else{m4ar_expect($n>0,'TASK78_POSITIVE_ACTUAL_INSERT_COUNT');$v=m5pcp_u64($old['metadata']['table']['AUTO_INCREMENT']);
   m4ar_expect(m5pcp_u64($new['metadata']['table']['AUTO_INCREMENT'])===bcadd($v,(string)$n,0),'TASK78_EXACT_NATURAL_COUNTER_ADVANCE');
   $meta=$old['metadata'];$meta['table']['AUTO_INCREMENT']=$new['metadata']['table']['AUTO_INCREMENT'];m4ar_expect($meta===$new['metadata'],'TASK78_ONLY_COUNTER_METADATA');
   $o=' AUTO_INCREMENT='.$v;$r=' AUTO_INCREMENT='.bcadd($v,(string)$n,0);
   $ddl=str_contains($old['ddl'],$o)?str_replace($o,$r,$old['ddl']):str_replace(' DEFAULT CHARSET=',$r.' DEFAULT CHARSET=',$old['ddl']);
   m4ar_expect($ddl===$new['ddl'],'TASK78_ONLY_COUNTER_DDL');}
  m4ar_expect($new['projected_columns']===$old['projected_columns'],'TASK78_COMPLETE_COLUMN_ORDER_UNCHANGED');$expected['tables'][$table]=$new;
  if(array_key_exists($table,$expected['target_rows']))$expected['target_rows'][$table]=$after['database']['target_rows'][$table];
 }
 m4ar_expect($after['database']===$expected,'TASK78_ALL_OTHER_TABLES_ROWS_MODES_METADATA_DDL_PRESERVED');
}
function m4ar_redis_delta(array $before,array $after,array $changes=[]):void
{
 $expected=$before['redis']['keys'];foreach($changes as$rawKey=>$value){
  m4ar_expect(is_string($rawKey)||is_int($rawKey),'TASK78_REDIS_CHANGE_KEY_NATIVE_STRING_OR_INTEGER');$h=hash('sha256',(string)$rawKey);
  if($value===null){m4ar_expect(!isset($after['redis']['keys'][$h]),'TASK78_EXACT_REDIS_CONSUMED');unset($expected[$h]);}
  else{m4ar_expect(isset($after['redis']['keys'][$h])&&$after['redis']['keys'][$h]===$value,'TASK78_EXACT_REDIS_NATIVE_VALUE_EXPIRY');$expected[$h]=$value;}}
 ksort($expected,SORT_STRING);$expectedRedis=$before['redis'];$expectedRedis['keys']=$expected;
 m4ar_expect(m5pcp_redis_deadline_compatible($expectedRedis,$after['redis']),'TASK78_ALL_OTHER_REDIS_TYPES_VALUES_EXPIRIES_UNCHANGED');
}
function m4ar_zero(array $before,array $after,bool $redis=true):void
{m4ar_database_delta($before,$after,[]);if($redis)m4ar_redis_delta($before,$after);m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_FILES_ZERO_DELTA');}
function m4ar_time(int $start,int $end,mixed $value):int
{$n=m5pcp_int($value,1,4294967295);m4ar_expect($n>=$start&&$n<=$end,'TASK78_REAL_TIME_WITHIN_ACTUAL_COMMAND');return $n;}
function m4ar_cents(string $amount):int
{m4ar_expect(preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$amount)===1,'TASK78_SAVED_DECIMAL_CENTS');[$a,$b]=explode('.',$amount);return (int)$a*100+(int)$b;}
function m4ar_decimal(int $cents):string
{m4ar_expect($cents>=0,'TASK78_NONNEGATIVE_CENT_RESULT');return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
function m4ar_slice(string $amount,int $q,int $first,int $through):string
{m4ar_expect($q>0&&$first>=0&&$through>$first&&$through<=$q,'TASK78_INDEPENDENT_UNIT_BOUNDS');$n=m4ar_cents($amount);
 $base=intdiv($n,$q);$extra=$n%$q;return m4ar_decimal($base*($through-$first)+min($through,$extra)-min($first,$extra));}

function m4ar_transport(string $method,string $path,?string $token=null,mixed $body=null,?string $multipart=null,?string $ticket=null,string $operationId='non_action',string $attempt='single'):array
{
 unset($GLOBALS['m4ar_http_failure'],$GLOBALS['m4ar_http_gate']);
 $fixed=['POST /api/login','POST /merchantapi/login','POST /adminapi/login','POST /merchantapi/password/setup',
  'POST /api/upload/image'];$leaf=$method.' '.explode('?',$path,2)[0];
 $patterns=['~\AGET /api/order/refund/cart_info/[1-9][0-9]*\z~','~\APOST /api/order/refund/apply/[1-9][0-9]*\z~',
  '~\AGET /api/order/refund/list\z~','~\AGET /api/order/refund/detail/[A-Za-z0-9]+\z~',
  '~\APOST /api/order/refund/cancel/[A-Za-z0-9]+\z~','~\APOST /api/order/refund/express\z~',
  '~\APOST /api/order/refund/files/[1-9][0-9]*\z~','~\APOST /api/order/refund/files/[1-9][0-9]*/read-ticket\z~',
  '~\AGET /api/order/refund/files/[1-9][0-9]*/content\z~','~\A(?:GET|POST) /api/order/reviews/[1-9][0-9]*/[1-9][0-9]*\z~',
  '~\APOST /api/order/reviews/[1-9][0-9]*/[1-9][0-9]*/files\z~',
  '~\A(?:GET|POST) /(?:merchantapi|adminapi/marketplace)/aftersales(?:/[1-9][0-9]*(?:/(?:approve|reject|return-accept))?)?\z~',
  '~\A(?:GET|POST) /(?:merchantapi|adminapi/marketplace)/aftersale-files/[1-9][0-9]*/(?:read-ticket|content)\z~',
  '~\AGET /(?:merchantapi|adminapi/marketplace)/reviews(?:/[1-9][0-9]*)?\z~',
  '~\APUT /merchantapi/reviews/[1-9][0-9]*/reply\z~','~\APUT /adminapi/marketplace/reviews/[1-9][0-9]*/review\z~',
  '~\APOST /merchantapi/orders/[1-9][0-9]*/shipments\z~','~\AGET /merchantapi/orders/[1-9][0-9]*/packages\z~',
  '~\AGET /merchantapi/packages/[1-9][0-9]*\z~','~\AGET /api/order/fulfillment/packages/[A-Za-z0-9]+\z~',
  '~\APOST /api/order/fulfillment/packages/[A-Za-z0-9]+/receipt\z~','~\AGET /api/reply/(?:list|config)/[1-9][0-9]*\z~',
  '~\AGET /uploads/store/comment/[A-Za-z0-9/._-]+\z~',
  '~\AGET /uploads/customer/[1-9][0-9]*/[a-f0-9]{32}/[a-f0-9]{32}\.png\z~'];
 $ok=in_array($leaf,$fixed,true);foreach($patterns as$p)if(preg_match($p,$leaf)===1)$ok=true;
 m4ar_expect($ok&&!str_contains($path,'..')&&!str_contains($path,'\\')&&!str_contains($path,'#')&&strlen($path)<2048,'TASK78_FIXED_NORMAL_HTTP_ROUTE');
 if(str_contains($path,'?')){parse_str(explode('?',$path,2)[1],$q);foreach(array_keys($q)as$k)
  m4ar_expect(in_array($k,['contract','page','limit','fulfillment_page','fulfillment_limit','business_state','mer_id','status','is_reply','product_id','type'],true),'TASK78_FIXED_QUERY_KEYS');}
 $headers=['Cb-lang: zh-CN'];if($token!==null)$headers[]=(str_starts_with($path,'/merchantapi/')?'Authorization':'Authori-zation').': Bearer '.$token;
 if($ticket!==null)$headers[]='X-Aftersale-Read-Ticket: '.$ticket;
 if($multipart!==null){m4ar_expect(is_string($body),'TASK78_REAL_MULTIPART_BYTES');$headers[]='Content-Type: multipart/form-data; boundary='.$multipart;}
 elseif($body!==null){m4ar_expect(is_array($body),'TASK78_EXACT_JSON_BODY_ARRAY');$body=m4cb_json($body);$headers[]='Content-Type: application/json';}
 $requestSequence=($GLOBALS['m4ar_http_request_sequence']??0)+1;$GLOBALS['m4ar_http_request_sequence']=$requestSequence;
 $responseHeaders=[];$curl=curl_init('http://audit-app:8000'.$path);$started=time();
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
 CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',
 CURLOPT_HEADERFUNCTION=>static function($h,string $line)use(&$responseHeaders):int{$v=explode(':',$line,2);if(count($v)===2)$responseHeaders[strtolower(trim($v[0]))]=trim($v[1]);return strlen($line);}]);
 if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);$raw=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);$ended=time();
 $json=null;$jsonError=null;if(is_string($raw)&&strlen($raw)<=6291456){$json=json_decode($raw,true);$jsonError=json_last_error();}
 $hasStatus=is_array($json)&&array_key_exists('status',$json);$status=$hasStatus?$json['status']:null;
 $GLOBALS['m4ar_http_gate']=[
  'operation_id'=>in_array($operationId,['money-withdraw','withdraw','money-merchant','business-reject-merchant','money-platform','business-reject-platform','cancel-apply','cancel-approve','return-apply','return-approve','return-express','accept-merchant','accept-platform','ship-two','receive-one','file-ticket-customer','file-ticket-merchant','file-ticket-platform'],true)?$operationId:'non_action',
  'attempt'=>in_array($attempt,['first','replay','single'],true)?$attempt:'single','request_sequence'=>$requestSequence,
  'method'=>$method,'route_family'=>str_starts_with($path,'/merchantapi/')?'merchantapi':(str_starts_with($path,'/adminapi/')?'adminapi':'api'),
  'http_status'=>$http,'curl_errno'=>$errno,'json_error'=>$jsonError,'status_type'=>$hasStatus?gettype($status):'missing',
  'status_int'=>is_int($status)&&$status>=-2147483648&&$status<=2147483647?$status:null,
  'msg_is_string'=>is_array($json)&&array_key_exists('msg',$json)&&is_string($json['msg']),
  'data_is_array'=>is_array($json)&&array_key_exists('data',$json)&&is_array($json['data']),
  'failure_class'=>$errno!==0?'CURL_ERROR':(!is_string($raw)?'RESPONSE_TYPE':(strlen($raw)>6291456?'RESPONSE_LIMIT':($http!==200?'HTTP_STATUS':'NONE')))];
 if(!($errno===0&&is_string($raw)&&strlen($raw)<=6291456&&$http===200))$GLOBALS['m4ar_http_failure']=[
  'method'=>$method,'route_family'=>str_starts_with($path,'/merchantapi/')?'merchantapi':(str_starts_with($path,'/adminapi/')?'adminapi':'api'),
  'http_status'=>$http,'curl_errno'=>$errno,'response_is_string'=>is_string($raw),'response_bytes'=>is_string($raw)?strlen($raw):null];
 m4ar_expect($errno===0&&is_string($raw)&&strlen($raw)<=6291456&&$http===200,'TASK78_REAL_NORMAL_HTTP_TRANSPORT_200');
 $record=['method'=>$method,'path'=>$path,'http'=>$http,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),
  'request_bytes'=>$body===null?0:strlen($body),'request_sha256'=>$body===null?hash('sha256',''):hash('sha256',$body),'actual_interval'=>[$started,$ended]];
 $GLOBALS['m4ar_http_calls'][]=$record;return ['http'=>$http,'raw'=>$raw,'json'=>$json,'headers'=>$responseHeaders,'actual_interval'=>[$started,$ended]];
}
function m4ar_json(string $method,string $path,array $actor,?array $body=null,string $operationId='non_action',string $attempt='single'):array
{
 $r=m4ar_transport($method,$path,$actor['token']??null,$body,null,null,$operationId,$attempt);
 $gate=$GLOBALS['m4ar_http_gate'];$keys=is_array($r['json'])?array_keys($r['json']):[];sort($keys,SORT_STRING);
 $gate['failure_class']=$gate['json_error']!==JSON_ERROR_NONE?'JSON_PARSE':(!is_array($r['json'])?'JSON_NOT_ARRAY':
  ($keys!==['data','msg','status']?'ENVELOPE_KEYS':($r['json']['status']!==200?'BUSINESS_STATUS':
   (!is_string($r['json']['msg'])?'MSG_TYPE':(!is_array($r['json']['data'])?'DATA_TYPE':'NONE')))));
 $GLOBALS['m4ar_http_gate']=$gate;m4c_entry_keys($r['json'],['status','msg','data']);
 m4ar_expect($r['json']['status']===200&&is_string($r['json']['msg'])&&is_array($r['json']['data']),'TASK78_REAL_HTTP_BUSINESS_SUCCESS');
 unset($GLOBALS['m4ar_http_gate']);return $r;
}
function m4ar_multipart(string $path,array $actor,array $fields,string $bytes):array
{
 $boundary='AuditNormal'.bin2hex(random_bytes(16));$raw='';
 foreach($fields as$k=>$v){m4ar_expect(in_array($k,['schema','upload_key','filename'],true)&&is_string($v),'TASK78_MULTIPART_FIXED_FIELDS');
  $raw.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$k."\"\r\n\r\n".$v."\r\n";}
 $raw.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"normal-evidence.png\"\r\nContent-Type: image/png\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
 $r=m4ar_transport('POST',$path,$actor['token'],$raw,$boundary);m4c_entry_keys($r['json'],['status','msg','data']);
 m4ar_expect($r['json']['status']===200&&is_array($r['json']['data']),'TASK78_REAL_MULTIPART_PRODUCER_200');return $r;
}
function m4ar_auth_delta(array $before,array $after,array $actor,array $interval,array &$expected):void
{
 if(($actor['app']??null)==='api'){$uid=$actor['id'];$old=m4ar_one($before['rows']['eb_user'],'uid',$uid);$now=m4ar_one($after['rows']['eb_user'],'uid',$uid);
  $want=$old;$want['last_time']=$now['last_time'];m4ar_time($interval[0],$interval[1],$now['last_time']);m4ar_expect($now===$want,'TASK78_CUSTOMER_REQUEST_ONLY_REAL_LAST_TIME');
  $expected['eb_user']=m4ar_replaced($before['rows']['eb_user'],'uid',$uid,$want);}
}
function m4ar_cache_deletes(array $before,array $after,array $actor):array
{
 $out=[];if(($actor['app']??null)==='adminapi')foreach(['c:all_auth','c:'.md5('Admin_rules_level__2_'.$actor['role_id'])]as$key){
  m4ar_expect(!isset($after['redis']['keys'][hash('sha256',$key)]),'TASK78_PLATFORM_SOURCE_DELETES_ONLY_CURRENT_AUTH_CACHE');$out[$key]=null;}
 return $out;
}
function m4ar_read_finish(PDO $db,array $before,array $actor,array $reply,?array $audit=null):array
{
 $after=m4ar_snapshot($db);$rows=[];$counts=[];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$rows);
 if($audit!==null)m4ar_audit_delta($before,$after,$actor,$audit,$reply['actual_interval'],$rows,$counts);
 m4ar_database_delta($before,$after,$rows,$counts);m4ar_redis_delta($before,$after,m4ar_cache_deletes($before,$after,$actor));
 m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_OWNED_READ_FILE_BYTES_UNCHANGED');return $after;
}
function m4ar_insert(PDO $db,string $table,array $values):array
{
 m4ar_expect(in_array($table,['eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup','eb_system_role','eb_system_admin'],true),'TASK78_SYNTHETIC_FIXTURE_ONLY');
 $before=m4ar_snapshot($db);$q=$db->prepare('INSERT INTO '.m5pcp_quote($table).' ('.implode(',',array_map('m5pcp_quote',array_keys($values))).') VALUES ('
 .implode(',',array_fill(0,count($values),'?')).')');m4ar_expect($q instanceof PDOStatement&&$q->execute(array_values($values))&&$q->rowCount()===1,'TASK78_REAL_ONE_FIXTURE_INSERT');
 $id=m5pcp_u64($db->lastInsertId());m4ar_expect($id===m5pcp_u64($before['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']),'TASK78_FIXTURE_NATURAL_PK');
 $after=m4ar_snapshot($db);$row=m4ar_one($after['rows'][$table],'id',$id);if(isset($values['permissions']))$values['permissions']=['json'=>json_decode($values['permissions'],true,32,JSON_THROW_ON_ERROR)];
 $expected=m4fn_expected_row($before['database']['tables'][$table]['metadata'],$row,['id'=>$id]+$values);
 m4ar_database_delta($before,$after,[$table=>array_merge($before['rows'][$table],[$expected])],[$table=>1]);m4ar_redis_delta($before,$after);
 m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_FIXTURE_NO_FILE_EFFECT');return $row;
}
function m4ar_session(array $reply,string $application,int $id,string $digest,array $before,array $after):array
{
 $data=$reply['json']['data'];$token=$data['token']??null;$config=audit_config();m4ar_expect(is_string($token)&&$token!==''&&count(explode('.',$token))===3,'TASK78_TRUE_CREDENTIAL_LOGIN_TOKEN');
 $claims=(array)\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']);$bind=\crmeb\services\AuthConfigGuard::authConfig()['apps'][$application];
 m4ar_expect(($claims['auth_schema']??null)===2&&$claims['session_epoch']===$config['auth']['session_epoch']&&$claims['iss']===$config['auth']['issuer']
  &&$claims['aud']===$bind['audience']&&($claims['jti']->type??null)===$bind['type']&&($claims['jti']->id??null)===(string)$id,
 'TASK78_REAL_VERIFIED_ISSUED_V2_IDENTITY');
 $sid=$claims['sid'];$version=$claims['session_version'];m4ar_expect(preg_match('/\A[a-f0-9]{32}\z/D',$sid)===1&&preg_match('/\A[a-f0-9]{32}\z/D',$version)===1
  &&$claims['nbf']===$claims['iat']&&$claims['exp']===$claims['iat']+$bind['ttl'],'TASK78_REAL_SESSION_VERSION_AND_LIFETIME');
 m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$claims['iat']);
 $prefix='auth:v2:'.$config['auth']['session_epoch'].':'.$application.':';$key=$prefix.'session:'.$sid;$account=$prefix.'account:'.$id;
 m4ar_expect(!isset($before['redis']['keys'][hash('sha256',$key)]),'TASK78_NEW_NATIVE_SESSION');
 $wanted=['subject_id'=>(string)$id,'type'=>$bind['type'],'audience'=>$bind['audience'],'session_version'=>$version,
  'token_digest'=>hash('sha256',$token),'credential_stamp'=>hash_hmac('sha256',$application.'|'.$id.'|'.$digest,$config['auth']['stamp_key']),
  'expires_at'=>(string)$claims['exp'],'status'=>'active'];
 $actual=audit_redis()->hGetAll($key);ksort($actual,SORT_STRING);ksort($wanted,SORT_STRING);m4ar_expect($actual===$wanted,'TASK78_EXACT_NATIVE_ISSUED_SESSION_HASH');
 $a=audit_redis()->hGetAll($account);ksort($a,SORT_STRING);m4ar_expect($a===['blocked'=>'0','initialized'=>'1','session_version'=>$version]
  &&audit_redis()->pttl($account)===-1&&abs(audit_redis()->rawCommand('PEXPIRETIME',$key)-($claims['exp']+60)*1000)<=1,'TASK78_EXACT_ACCOUNT_AND_SESSION_ABSOLUTE_DEADLINE');
 return ['app'=>$application,'id'=>$id,'token'=>$token,'claims'=>$claims,'redis_changes'=>[
  $account=>$after['redis']['keys'][hash('sha256',$account)],$key=>$after['redis']['keys'][hash('sha256',$key)]]];
}
/** Optional UI fixture: exact native order-read leaves and their two navigation rows. */
function m4ar_ui_order_read_grants(PDO $db,array $original):array
{
 $rows=[];foreach(['order/list','order/chart','order/info/<id>']as$path){
  $r=m5pcp_rows($db,'SELECT * FROM eb_system_menus WHERE auth_type=2 AND is_del=0 AND LOWER(api_url)=? AND LOWER(methods)=?',[$path,'get']);
  m4ar_expect(count($r)===1&&(string)$r[0]['auth_type']==='2'&&strtolower((string)$r[0]['api_url'])===$path
   &&strtolower((string)$r[0]['methods'])==='get','TASK78_UI_ORDER_READ_EXACT_API');$rows[]=$r[0];
 }
 $r=m5pcp_rows($db,'SELECT * FROM eb_system_menus WHERE auth_type=1 AND is_del=0 AND unique_auth=?',['admin-order']);
 m4ar_expect(count($r)===1&&(string)$r[0]['auth_type']==='1'&&(string)$r[0]['unique_auth']==='admin-order'
  &&(string)$r[0]['menu_path']==='/order'&&(string)$r[0]['pid']==='0'&&(string)$r[0]['api_url']===''&&(string)$r[0]['methods']==='',
  'TASK78_UI_ORDER_READ_EXACT_ROOT');$root=$r[0];
 m4ar_expect(preg_match('/\A[1-9][0-9]{0,4}\z/D',(string)$root['id'])===1&&(int)$root['id']<=65535,'TASK78_UI_ORDER_READ_NATIVE_ROOT_ID');
 $r=m5pcp_rows($db,'SELECT * FROM eb_system_menus WHERE auth_type=1 AND is_del=0 AND unique_auth=?',['admin-order-storeOrder-index']);
 m4ar_expect(count($r)===1&&(string)$r[0]['auth_type']==='1'&&(string)$r[0]['unique_auth']==='admin-order-storeOrder-index'
  &&(string)$r[0]['menu_path']==='/order/list'&&(string)$r[0]['pid']===(string)$root['id']&&(string)$r[0]['api_url']===''&&(string)$r[0]['methods']==='',
  'TASK78_UI_ORDER_READ_EXACT_PAGE_PARENT');$rows[]=$root;$rows[]=$r[0];
 $ids=[];foreach($rows as$row){
  m4ar_expect(preg_match('/\A[1-9][0-9]{0,4}\z/D',(string)$row['id'])===1&&(int)$row['id']<=65535
   &&(string)$row['is_del']==='0'&&(string)$row['access']==='1'&&(string)$row['is_show']==='1'&&(string)$row['is_show_path']==='1',
   'TASK78_UI_ORDER_READ_ACTIVE_NATIVE_ID');$ids[]=(int)$row['id'];
 }
 m4ar_expect(count(array_unique($ids))===5&&array_intersect($ids,$original)===[]&&count(array_unique(array_merge($original,$ids)))===15,
  'TASK78_UI_ORDER_READ_FIVE_DISTINCT_DISJOINT_FINAL_FIFTEEN');
 return $ids;
}

function m4ar_actors(\think\App $app,PDO $db,array $source,array $owner,?callable $credentialSink=null,bool $uiOrderRead=false):array
{
 $uid=$owner['uid'];$mer=(int)$owner['fixture']['merchant']['mer_id'];$suffix=bin2hex(random_bytes(7));$now=time();
 $permissions=['merchant.profile.read','order.read','order.ship','aftersale.handle','product.read','product.write'];
 $role=m4ar_insert($db,'eb_marketplace_role',['mer_id'=>$mer,'name'=>'Normal '.$suffix,'permissions'=>json_encode($permissions,JSON_THROW_ON_ERROR),'add_time'=>$now,'update_time'=>$now]);
 $employee=m4ar_insert($db,'eb_marketplace_employee',['mer_id'=>$mer,'role_id'=>$role['id'],'account'=>'m78_'.$suffix,'pwd'=>null,'name'=>'Normal','avatar'=>'','status'=>1,'is_del'=>0,'add_time'=>$now,'update_time'=>$now]);
 $cap=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$password='Normal78-'.bin2hex(random_bytes(20));
 $setup=m4ar_insert($db,'eb_marketplace_password_setup',['mer_id'=>$mer,'employee_id'=>$employee['id'],'token_hash'=>hash('sha256',$cap,true),'expires_at'=>$now+86400,'consumed_at'=>null,'add_time'=>$now]);
 $before=m4ar_snapshot($db);$start=time();$app->make(EmployeeAuthServices::class)->setupPassword($cap,$password);$end=time();$after=m4ar_snapshot($db);
 $fresh=m4ar_one($after['rows']['eb_marketplace_employee'],'id',$employee['id']);$freshSetup=m4ar_one($after['rows']['eb_marketplace_password_setup'],'id',$setup['id']);
 m4ar_expect(password_verify($password,$fresh['pwd']),'TASK78_REAL_SETUP_PASSWORD_HASH');$wanted=$employee;$wanted['pwd']=$fresh['pwd'];$wanted['update_time']=$fresh['update_time'];
 m4ar_time($start,$end,$fresh['update_time']);$ws=$setup;$ws['consumed_at']=$freshSetup['consumed_at'];m4ar_time($start,$end,$ws['consumed_at']);
 m4ar_expect($fresh===$wanted&&$freshSetup===$ws,'TASK78_SETUP_ONLY_ACTUAL_PASSWORD_AND_CONSUMPTION');
 m4ar_database_delta($before,$after,['eb_marketplace_employee'=>m4ar_replaced($before['rows']['eb_marketplace_employee'],'id',$employee['id'],$wanted),
 'eb_marketplace_password_setup'=>m4ar_replaced($before['rows']['eb_marketplace_password_setup'],'id',$setup['id'],$ws)]);
 // The actual loaded configuration, not an invented context field, binds account setup.
 $accountKey='auth:v2:'.$app->config->get('auth.session_epoch').':merchantapi:account:'.$employee['id'];$a=audit_redis()->hGetAll($accountKey);ksort($a,SORT_STRING);
 m4ar_expect($a===['blocked'=>'0','initialized'=>'0']&&audit_redis()->pttl($accountKey)===-1,'TASK78_REAL_SETUP_ACCOUNT_RECORD');
 m4ar_redis_delta($before,$after,[$accountKey=>$after['redis']['keys'][hash('sha256',$accountKey)]]);
 $before=$after;$login=m4ar_json('POST','/merchantapi/login',[],['account'=>$employee['account'],'password'=>$password]);$after=m4ar_snapshot($db);
 $merchant=m4ar_session($login,'merchantapi',(int)$employee['id'],$fresh['pwd'],$before,$after);$merchant['role_id']=(int)$role['id'];$merchant['mer_id']=$mer;
 m4ar_database_delta($before,$after,[]);m4ar_redis_delta($before,$after,$merchant['redis_changes']);
 $grantSpec=[['GET','marketplace/aftersales'],['GET','marketplace/aftersales/<id>'],['POST','marketplace/aftersales/<id>/approve'],
  ['POST','marketplace/aftersales/<id>/reject'],['POST','marketplace/aftersales/<id>/return-accept'],['POST','marketplace/aftersale-files/<file_id>/read-ticket'],
  ['GET','marketplace/aftersale-files/<file_id>/content'],['GET','marketplace/reviews'],['GET','marketplace/reviews/<id>'],['PUT','marketplace/reviews/<id>/review']];
 $grants=[];foreach($grantSpec as[$method,$path]){$r=m5pcp_rows($db,'SELECT * FROM eb_system_menus WHERE auth_type=2 AND is_del=0 AND LOWER(api_url)=? AND LOWER(methods)=?',
 [strtolower($path),strtolower($method)]);m4ar_expect(count($r)===1&&(int)$r[0]['id']>0,'TASK78_EXACT_REAL_TEN_MENU_LEAVES');$grants[]=(int)$r[0]['id'];}
 m4ar_expect(count(array_unique($grants))===10,'TASK78_EXACT_TEN_DISTINCT_GRANTS');if($uiOrderRead)$grants=array_merge($grants,m4ar_ui_order_read_grants($db,$grants));sort($grants,SORT_NUMERIC);
 $adminRole=m4ar_insert($db,'eb_system_role',['role_name'=>'N78 '.$suffix,'rules'=>implode(',',$grants),'level'=>1,'status'=>1]);
 $adminPassword='Normal78-'.bin2hex(random_bytes(11));$hash=password_hash($adminPassword,PASSWORD_BCRYPT);m4ar_expect(is_string($hash),'TASK78_REAL_ADMIN_PASSWORD_HASH');
 $admin=m4ar_insert($db,'eb_system_admin',['account'=>'a78_'.$suffix,'pwd'=>$hash,'real_name'=>'Normal78','roles'=>(string)$adminRole['id'],'add_time'=>time(),'level'=>1,'status'=>1,'is_del'=>0]);
 $before=m4ar_snapshot($db);$login=m4ar_json('POST','/adminapi/login',[],['account'=>$admin['account'],'pwd'=>$adminPassword,'key'=>'']);$after=m4ar_snapshot($db);
 $platform=m4ar_session($login,'adminapi',(int)$admin['id'],$hash,$before,$after);$platform['role_id']=(int)$adminRole['id'];$platform['account']=$admin['account'];
 $actual=m4ar_one($after['rows']['eb_system_admin'],'id',$admin['id']);$want=$admin;$want['login_count']=(int)$admin['login_count']+1;
 $want['last_time']=$actual['last_time'];$want['last_ip']=gethostbyname(gethostname());m4ar_time($login['actual_interval'][0],$login['actual_interval'][1],$actual['last_time']);
 m4ar_expect($actual===$want,'TASK78_ADMIN_LOGIN_ONLY_REAL_STATS');m4ar_database_delta($before,$after,['eb_system_admin'=>m4ar_replaced($before['rows']['eb_system_admin'],'id',$admin['id'],$want)]);
 m4ar_login_cache_delta($before,$after,$platform,$login);$before=m4ar_snapshot($db);
 $login=m4ar_json('POST','/api/login',[],['account'=>$owner['loaded']['private']['account'],'password'=>$owner['loaded']['private']['password'],'spread'=>0,'agent_id'=>0]);
 $after=m4ar_snapshot($db);$customer=['app'=>'api','id'=>$uid,'token'=>$login['json']['data']['token']??null];
 m4ar_expect(is_string($customer['token'])&&$customer['token']!==''&&is_int($login['json']['data']['expires_time']??null),'TASK78_REAL_CUSTOMER_CREDENTIAL_LOGIN');
 m4ar_customer_login_delta($before,$after,$customer,$login);
 if($credentialSink!==null)$credentialSink([
  'customer'=>['account'=>$owner['loaded']['private']['account'],'password'=>$owner['loaded']['private']['password'],'uid'=>$uid],
  'merchant'=>['account'=>$employee['account'],'password'=>$password,'id'=>(int)$employee['id'],'mer_id'=>$mer],
  'platform'=>['account'=>$admin['account'],'password'=>$adminPassword,'id'=>(int)$admin['id']]]);
 unset($cap,$password,$adminPassword,$hash);
 return ['customer'=>$customer,'merchant'=>$merchant,'platform'=>$platform,'grants'=>$grants];
}

function m4ar_created_saved(PDO $pdo,array $fixture,array $request,string $idem,array $frame,array $answers,string $number,array $before,array $createWindow,?array $secondStoreLine=null): array
{
    $uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];
    $connection=Db::connect();$parentPdo=$connection->getPdo();
    if(!$parentPdo instanceof PDO){$connection->query('SELECT 1',[],true);$parentPdo=$connection->getPdo();}
    m4c_expect($parentPdo instanceof PDO&&!$pdo->inTransaction()&&!$parentPdo->inTransaction()
        &&$connection===Db::connect()&&$parentPdo===Db::connect()->getPdo()&&$pdo!==$parentPdo,'M4C_NATIVE_INDEPENDENT_COMMITTED_ORACLE');
    $orders=m4cb_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$uid,$number]);
    m4c_expect(count($orders)===1,'M4C_EXACTLY_ONE_REAL_SAVED_ORDER');$order=$orders[0];$id=(int)$order['id'];
    m4c_expect($id>0&&(int)$order['uid']===$uid&&(int)$order['pay_uid']===$uid&&(int)$order['mer_id']===$mer
        &&(int)$order['marketplace_trade_version']===1&&$order['unique']===$request['confirm_key']&&$order['order_id']===$number
        &&json_decode($order['cart_id'],true,32,JSON_THROW_ON_ERROR)===$request['cart_refs'],'M4C_SAVED_NATIVE_ORDER_IDENTITY');
    foreach(['paid','is_cancel','is_gift','status','refund_status']as$field)m4c_expect((int)$order[$field]===0,'M4C_SAVED_ORDER_STILL_UNPAID_AND_UNFULFILLED');
    // Independent native readback of insertOrder's same-transaction payment birth; never synthesize a missing registry.
    $births=m4cb_rows($pdo,'SELECT * FROM `eb_marketplace_payment_registry` WHERE `order_id` = ?',[$id]);
    m4c_expect(count($births)===1,'M4C_PAYMENT_BIRTH_ONE_REAL_REGISTRY');$birth=$births[0];
    $birthFields=['order_id','uid','mer_id','payment_contract_version','born_at','attempt_generation',
        'winner_attempt_id','fulfillment_hold','hold_reason','hold_attempt_id','hold_at'];
    $birthKeys=array_keys($birth);sort($birthKeys,SORT_STRING);sort($birthFields,SORT_STRING);
    m4c_expect($birthKeys===$birthFields,'M4C_PAYMENT_BIRTH_COMPLETE_ELEVEN_COLUMNS');
    $bornAt=(int)$order['add_time'];m4c_expect($bornAt>0&&$bornAt<=4294967295,'M4C_PAYMENT_BIRTH_REAL_ROOT_TIME');
    foreach(['order_id'=>$id,'uid'=>$uid,'mer_id'=>$mer,'payment_contract_version'=>1,'born_at'=>$bornAt,'fulfillment_hold'=>0]as$field=>$expected)
        m4c_expect($birth[$field]===$expected||$birth[$field]===(string)$expected,'M4C_PAYMENT_BIRTH_NATIVE_IDENTITY_VALUE');
    m4c_expect($birth['attempt_generation']===0||$birth['attempt_generation']==='0','M4C_PAYMENT_BIRTH_CANONICAL_ZERO_GENERATION');
    foreach(['winner_attempt_id','hold_reason','hold_attempt_id','hold_at']as$field)
        m4c_expect($birth[$field]===null,'M4C_PAYMENT_BIRTH_ACTUAL_NULL_POINTERS');
    m4c_expect(m4cb_rows($pdo,'SELECT * FROM `eb_marketplace_payment_attempt` WHERE `order_id` = ? ORDER BY `id`',[$id])===[],
        'M4C_PAYMENT_BIRTH_REAL_EMPTY_ATTEMPT_SET');
    // Task6 retained this buyer's real membership; verify the quote inputs before comparing saved money.
    $memberBuyer=m4ar_one($before['eb_user'],'uid',$uid);
    m4ar_expect($memberBuyer===$fixture['user']&&m5pcp_int($memberBuyer['is_money_level'],1,1)===1
        &&m5pcp_int($memberBuyer['is_ever_level'],0,0)===0&&bccomp(m5pcp_u64($memberBuyer['overdue_time']),(string)time(),0)>0,
        'TASK78_BEFORE_CREATE_CURRENT_MEMBER_POSTAGE_BUYER');
    foreach(['member_func_status','member_card_status','member_price_status']as$name){
        $memberConfig=m4ar_one($before['eb_system_config'],'menu_name',$name);
        m4ar_expect($memberConfig['value']==='1','TASK78_BEFORE_CREATE_MEMBER_SWITCH_RAW_ONE');
    }
    $express=m4ar_one($before['eb_member_right'],'right_type','express');
    m4ar_expect(m5pcp_int($express['status'],1,1)===1&&m5pcp_int($express['number'],80,80)===80,
        'TASK78_BEFORE_CREATE_UNIQUE_EXPRESS_EIGHTY_PERCENT');
    foreach($fixture['selected']as$line){$shippingProduct=m4ar_one($before['eb_store_product'],'id',$line['product_id']);
        m4ar_expect($shippingProduct===$fixture['products'][$line['product_id']]&&m5pcp_int($shippingProduct['freight'],2,2)===2
            &&$shippingProduct['postage']==='2.67'&&m5pcp_int($shippingProduct['is_vip'],0,0)===0,
            'TASK78_BEFORE_CREATE_ORIGINAL_NONVIP_FIXED_POSTAGE');
    }
    $freePostage=m4ar_one($before['eb_system_config'],'menu_name','store_free_postage');
    m4ar_expect($freePostage['value']==='"10000.00"','TASK78_BEFORE_CREATE_ORIGINAL_FREE_POSTAGE_THRESHOLD');
    // 8.01 x 80% truncates to 640 cents; weights 2:1 give 426/213, then the larger remainder gets one cent.
    foreach(['total_price'=>'70.55','total_postage'=>'6.40','pay_postage'=>'6.40','pay_price'=>'76.95','cost'=>'24.25']as$field=>$value)
        m4c_expect($order[$field]===$value,'M4C_INDEPENDENT_SAVED_DECIMAL_TOTAL');
    $address=$fixture['address'];m4c_expect((int)$order['total_num']===3&&(int)$order['shipping_type']===1&&$order['pay_type']===''
        &&$order['channel_type']==='h5'&&$order['mark']===$request['options']['mark']&&$order['gift_mark']===$request['options']['gift_mark']
        &&$order['real_name']===$address['real_name']&&$order['user_phone']===$address['phone']
        &&$order['user_address']===$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail']
        &&$order['merchant_name_snapshot']===$fixture['merchant']['name']
        &&$order['merchant_after_sale_contact_snapshot']===$fixture['merchant']['after_sale_contact'],'M4C_SAVED_INPUT_AND_SHOP_SNAPSHOTS');
    $moves=m4cb_rows($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `sku_id`',[$id]);
    $items=m4cb_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]);
    m4c_expect(count($moves)===2&&count($items)===2,'M4C_TWO_REAL_ITEMS_TWO_REAL_RESERVES');
    $selection=[];foreach($frame['selection']as$line)$selection[$line['cart_ref']]=$line;$movementById=[];$rootState=[];
    foreach($fixture['products']as$productId=>$product)$rootState[$productId]=['stock'=>(int)$product['stock'],'sales'=>(int)$product['sales']];
    foreach($moves as$move){$skuId=(int)$move['sku_id'];$sku=$fixture['skus'][$skuId]??null;$productId=(int)$move['product_id'];$matching=[];
        foreach($selection as$ref=>$line)if($line['sku_id']===$skuId)$matching[(string)$ref]=$line;
        m4c_expect(is_array($sku)&&count($matching)===1,'M4C_RESERVE_HAS_ONE_ACTUAL_SELECTED_SKU');$line=array_values($matching)[0];$qty=$line['qty'];
        $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$productId,'sku_id'=>$skuId,
            'sku_type'=>0,'sku_unique'=>$sku['unique'],'qty'=>$qty,'kind'=>'reserve','cart_refs'=>array_map('strval',array_keys($matching))];
        $expected=['mer_id'=>$mer,'order_id'=>$id,'product_id'=>$line['product_id'],'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$sku['unique'],
            'qty'=>$qty,'kind'=>'reserve','idem_key'=>'reserve:'.$id.':'.$skuId,'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,
            'actor_type'=>'customer','actor_id'=>$uid,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($action),true),
            'sku_stock_before'=>$sku['stock'],'sku_stock_after'=>(int)$sku['stock']-$qty,'sku_sales_before'=>$sku['sales'],'sku_sales_after'=>(int)$sku['sales']+$qty,
            'root_stock_before'=>$rootState[$productId]['stock'],'root_stock_after'=>$rootState[$productId]['stock']-$qty,
            'root_sales_before'=>$rootState[$productId]['sales'],'root_sales_after'=>$rootState[$productId]['sales']+$qty,'add_time'=>$order['add_time']];
        m4c_expect(preg_match('/\A[1-9][0-9]*\z/D',(string)$move['id'])===1,'M4C_REAL_RESERVE_PK');
        foreach($expected as$field=>$value)m4c_expect($value===null?$move[$field]===null:(string)$move[$field]===(string)$value,'M4C_COMPLETE_RESERVE_FACT');
        m4c_expect(count($move)===count($expected)+1,'M4C_COMPLETE_RESERVE_FIELD_SET');
        $rootState[$productId]=['stock'=>$expected['root_stock_after'],'sales'=>$expected['root_sales_after']];$movementById[(string)$move['id']]=$move;
    }
    $seen=[];foreach($items as$item){$ref=(string)$item['cart_id'];$line=$selection[$ref]??null;m4c_expect(is_array($line)&&!isset($seen[$ref]),'M4C_EACH_SELECTED_ITEM_ONCE');$seen[$ref]=true;
        $sku=$fixture['skus'][$line['sku_id']];$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$lineage=$cart['marketplace']??null;
        m4c_expect((int)$item['uid']===$uid&&(int)$item['mer_id']===$mer&&(int)$item['product_id']===$line['product_id']
            &&(int)$item['cart_num']===$line['qty']&&(int)$item['surplus_num']===$line['qty']&&(int)$item['refund_num']===0
            &&$item['unique']===md5($ref.$id)&&($cart['id']??null)===$ref&&($cart['cart_num']??null)===$line['qty'],'M4C_NATIVE_ITEM_AND_CART_SNAPSHOT');
        m4c_expect(is_array($lineage)&&$lineage['cart_ref']===$ref&&$lineage['sku_id']===$line['sku_id']&&$lineage['sku_type']===0
            &&$lineage['sku_unique']===$sku['unique']&&is_string($lineage['reserve_id'])&&isset($movementById[$lineage['reserve_id']]),'M4C_ITEM_REAL_RESERVE_LINEAGE');
        m4c_expect((int)$cart['productInfo']['id']===$line['product_id']&&(int)$cart['productInfo']['mer_id']===$mer
            &&(int)$cart['productInfo']['attrInfo']['id']===$line['sku_id']&&(int)$cart['productInfo']['attrInfo']['type']===0
            &&(int)$cart['productInfo']['attrInfo']['product_id']===$line['product_id']&&$cart['productInfo']['attrInfo']['unique']===$sku['unique']
            &&$cart['sum_price']===$sku['price']&&$cart['truePrice']===$sku['price']&&$cart['costPrice']===$sku['cost']
            &&$cart['productInfo']['attrInfo']['price']===$sku['price']&&$cart['productInfo']['attrInfo']['cost']===$sku['cost']
            &&$cart['sum_true_price']===bcmul($sku['price'],(string)$line['qty'],2),'M4C_ACTUAL_SELECTED_SKU_PRICE_COST_SNAPSHOT');
        $postage=$line['qty']===2?'4.27':'2.13';m4c_expect($cart['postage_price']===$postage&&$cart['origin_postage_price']===$postage,'M4C_INDEPENDENT_WHOLE_LINE_POSTAGE');
        foreach(['coupon_price','integral_price','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage']as$field)
            m4c_expect($cart[$field]==='0.00','M4C_NO_DISABLED_RIGHTS_OR_BROKERAGE');
    }m4c_expect(count($seen)===2,'M4C_COMPLETE_SELECTED_ITEM_SET');
    foreach($before['eb_store_product']as$old){$expected=$old;$productId=(int)$old['id'];
        if(isset($rootState[$productId]))foreach($rootState[$productId]as$field=>$value)$expected[$field]=$value;
        $read=m4cb_rows($pdo,'SELECT * FROM `eb_store_product` WHERE `id` = ?',[$old['id']]);m4c_expect(count($read)===1,'M4C_ROOT_RETAINED');m4cb_equal_row($read[0],$expected);
    }
    foreach($before['eb_store_product_attr_value']as$old){$expected=$old;foreach($selection as$line)if((int)$old['id']===$line['sku_id']){
            $expected['stock']=(int)$old['stock']-$line['qty'];$expected['sales']=(int)$old['sales']+$line['qty'];}
        $read=m4cb_rows($pdo,'SELECT * FROM `eb_store_product_attr_value` WHERE `id` = ?',[$old['id']]);m4c_expect(count($read)===1,'M4C_SKU_RETAINED');m4cb_equal_row($read[0],$expected);
    }
    // Task6's genuine one-hour configuration remains the input to this later real creation.
    $cancelConfig=array_values(array_filter($before['eb_system_config'],static fn(array $row):bool=>$row['menu_name']==='order_cancel_time'));
    m4ar_expect(count($cancelConfig)===1&&$cancelConfig[0]['type']==='text'&&$cancelConfig[0]['value']===json_encode('1',JSON_THROW_ON_ERROR),
        'TASK78_UNIQUE_REAL_BEFORE_CREATE_ONE_HOUR_CONFIG');
    m4c_entry_keys($createWindow,['start','end']);$createdAt=m5pcp_int($order['add_time'],1,4294963695);
    m4ar_expect(is_int($createWindow['start'])&&is_int($createWindow['end'])&&$createWindow['start']>0
        &&$createWindow['end']>=$createWindow['start']&&$createdAt>=$createWindow['start']&&$createdAt<=$createWindow['end'],
        'TASK78_REAL_CREATE_TIME_WINDOW_AND_UINT_DEADLINE');
    $cancelAt=$createdAt+3600;
    $ledger=m4cb_rows($pdo,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `uid` = ? AND `idem_key` = ?',[$uid,$idem]);
    $expected=['uid'=>$uid,'mer_id'=>$mer,'idem_key'=>$idem,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($request),true),
        'confirmation_ref'=>hash('sha256',$request['confirm_key'],true),'source_kind'=>'persistent','state'=>1,'order_id'=>$id,'post_commit_done'=>1,
        'cancel_at'=>$cancelAt,'add_time'=>$order['add_time']];
    m4c_expect(count($ledger)===1&&count($ledger[0])===12&&json_decode($ledger[0]['cart_refs'],true,32,JSON_THROW_ON_ERROR)===$request['cart_refs'],'M4C_REAL_COMMITTED_LEDGER_AND_REFERENCES');
    foreach($expected as$field=>$value)m4c_expect((string)$ledger[0][$field]===(string)$value,'M4C_COMPLETE_LEDGER_FACT');
    $invoices=m4cb_rows($pdo,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id]);
    m4c_expect(count($invoices)===1&&(int)$invoices[0]['uid']===$uid&&$invoices[0]['category']==='order'
        &&(int)$invoices[0]['id']>0&&(int)$invoices[0]['order_id']===$id&&(int)$invoices[0]['add_time']===(int)$order['add_time']
        &&(int)$invoices[0]['invoice_id']===(int)$fixture['invoice']['id'],'M4C_REAL_ORIGINAL_INVOICE_SNAPSHOT');
    foreach(['is_pay','is_refund','is_invoice']as$field)m4c_expect((int)$invoices[0][$field]===0,'M4C_INVOICE_STILL_UNPAID_UNREFUNDED_UNISSUED');
    foreach(['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number']as$field)
        m4c_expect((string)$invoices[0][$field]===(string)$fixture['invoice'][$field],'M4C_COMPLETE_ORIGINAL_INVOICE_HEADING');
    $stored=json_decode($order['custom_form'],true,32,JSON_THROW_ON_ERROR);$expectedForms=$frame['custom_form'];
    foreach($expectedForms as$i=>&$group){foreach($group['fields']as$j=>&$field)$field['value']=$answers[$i]['values'][$j]['value'];unset($field);}unset($group);
    m4c_expect($stored===$expectedForms&&count($stored)===2&&$stored[0]['fields'][1]['value']==='0','M4C_COMPLETE_NONEMPTY_SAVED_PRODUCT_FORMS');
    if($secondStoreLine===null){
    $remaining=m4cb_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    $oldUnselected=array_values(array_filter($before['eb_store_cart'],static fn(array $row):bool=>$row['product_attr_unique']===$fixture['skus'][$fixture['unselected']['sku_id']]['unique']));
    m4c_expect(count($remaining)===count($oldUnselected)&&count($remaining)===1,'M4C_ONLY_UNSELECTED_CART_REMAINS');m4cb_equal_row($remaining[0],$oldUnselected[0]);
    }else{
    $remaining=m4cb_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    $lineKeys=array_keys($secondStoreLine);sort($lineKeys,SORT_STRING);
    m4c_expect($lineKeys===['product_id','qty','sku_id']&&is_int($secondStoreLine['product_id'])&&$secondStoreLine['product_id']>0
        &&is_int($secondStoreLine['sku_id'])&&$secondStoreLine['sku_id']>0&&is_int($secondStoreLine['qty'])&&$secondStoreLine['qty']>0,
        'M4C_H5_ACTUAL_SECOND_STORE_INTENT_LINE');
    $otherProduct=$fixture['products'][$secondStoreLine['product_id']]??null;$otherSku=$fixture['skus'][$secondStoreLine['sku_id']]??null;
    $sameStoreLine=$fixture['unselected'];$sameStoreProduct=$fixture['products'][$sameStoreLine['product_id']]??null;
    $sameStoreSku=$fixture['skus'][$sameStoreLine['sku_id']]??null;
    m4c_expect(is_array($otherProduct)&&is_array($otherSku)&&is_array($sameStoreProduct)&&is_array($sameStoreSku),
        'M4C_H5_ACTUAL_TWO_DISTINCT_UNSELECTED_SKU_SOURCES');
    $otherMer=(int)$otherProduct['mer_id'];$otherMerchant=$fixture['merchants'][$otherMer]??null;
    m4c_expect((int)$otherProduct['id']===$secondStoreLine['product_id']&&(int)$otherSku['id']===$secondStoreLine['sku_id']
        &&(int)$otherSku['product_id']===$secondStoreLine['product_id']&&(int)$otherSku['type']===0
        &&is_string($otherSku['unique'])&&$otherSku['unique']!==''&&$otherMer>0&&$otherMer!==$mer
        &&is_array($otherMerchant)&&(int)$otherMerchant['mer_id']===$otherMer
        &&($fixture['merchants'][$mer]??null)===$fixture['merchant'],'M4C_H5_REAL_SECOND_STORE_ROOT_SKU_AND_MERCHANT');
    m4c_expect((int)$sameStoreProduct['id']===$sameStoreLine['product_id']&&(int)$sameStoreProduct['mer_id']===$mer
        &&(int)$sameStoreSku['id']===$sameStoreLine['sku_id']&&(int)$sameStoreSku['product_id']===$sameStoreLine['product_id']
        &&(int)$sameStoreSku['type']===0&&is_string($sameStoreSku['unique'])&&$sameStoreSku['unique']!==''
        &&$secondStoreLine['product_id']!==$sameStoreLine['product_id']&&$secondStoreLine['sku_id']!==$sameStoreLine['sku_id'],
        'M4C_H5_ORIGINAL_SAME_STORE_UNSELECTED_SKU_SEPARATE');
    $expectedSelection=$actualSelection=[];
    foreach($fixture['selected']as$line)$expectedSelection[]=$line['product_id'].':'.$line['sku_id'].':'.$line['qty'];
    foreach($selection as$line)$actualSelection[]=$line['product_id'].':'.$line['sku_id'].':'.$line['qty'];
    sort($expectedSelection,SORT_STRING);sort($actualSelection,SORT_STRING);
    m4c_expect(count($expectedSelection)===2&&count($actualSelection)===2&&$actualSelection===$expectedSelection,
        'M4C_H5_ORIGINAL_ACTUAL_SELECTED_PRODUCT_SKU_QUANTITIES');
    $oldUnselected=array_values(array_filter($before['eb_store_cart'],static fn(array $row):bool=>(int)$row['uid']===$uid
        &&(int)$row['product_id']===$secondStoreLine['product_id']&&(int)$row['type']===0&&$row['product_attr_unique']===$otherSku['unique']));
    m4c_expect(count($oldUnselected)===1,'M4C_H5_ONE_REAL_SECOND_STORE_CART_BEFORE_CREATE');
    $nativeUnselected=m4cb_rows($pdo,'SELECT c.* FROM `eb_store_cart` c'
        .' INNER JOIN `eb_store_product` p ON p.`id` = c.`product_id`'
        .' INNER JOIN `eb_store_product_attr_value` s ON s.`product_id` = p.`id` AND s.`unique` = c.`product_attr_unique` AND s.`type` = c.`type`'
        .' INNER JOIN `eb_marketplace_merchant` m ON m.`mer_id` = p.`mer_id`'
        .' WHERE c.`id` = ? AND c.`uid` = ? AND c.`product_id` = ? AND c.`product_attr_unique` = ? AND s.`id` = ? AND m.`mer_id` = ? AND c.`cart_num` = ?'
        .' AND c.`type` = 0 AND c.`status` = 1 AND c.`is_del` = 0 AND c.`is_pay` = 0 AND c.`is_new` = 0'
        .' AND c.`seckill_id` = 0 AND c.`bargain_id` = 0 AND c.`combination_id` = 0 AND c.`advance_id` = 0',
        [$oldUnselected[0]['id'],$uid,$secondStoreLine['product_id'],$otherSku['unique'],$secondStoreLine['sku_id'],$otherMer,$secondStoreLine['qty']]);
    m4c_expect(count($nativeUnselected)===1&&count($remaining)===1,'M4C_H5_ONLY_REAL_SECOND_STORE_CART_REMAINS');
    m4cb_equal_row($nativeUnselected[0],$oldUnselected[0]);m4cb_equal_row($remaining[0],$oldUnselected[0]);
    $sameStoreBefore=array_values(array_filter($before['eb_store_cart'],static fn(array $row):bool=>(int)$row['uid']===$uid
        &&(int)$row['product_id']===$sameStoreLine['product_id']&&$row['product_attr_unique']===$sameStoreSku['unique']));
    $sameStoreAfter=m4cb_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? AND `product_id` = ? AND `product_attr_unique` = ? ORDER BY `id`',
        [$uid,$sameStoreLine['product_id'],$sameStoreSku['unique']]);
    m4c_expect($sameStoreBefore===[]&&$sameStoreAfter===[],'M4C_H5_ORIGINAL_SAME_STORE_UNSELECTED_SKU_HAS_NO_CART');
    }
    $status=m4cb_rows($pdo,'SELECT * FROM `eb_store_order_status` WHERE `oid` = ?',[$id]);
    m4c_expect(count($status)===1&&(int)$status[0]['oid']===$id&&$status[0]['change_type']==='cache_key_create_order'
        &&$status[0]['change_message']==='订单生成'&&(int)$status[0]['change_time']===(int)$order['add_time'],'M4C_ONE_PRODUCTION_POST_COMMIT_STATUS');
    $allOrderLogs=m4cb_rows($pdo,'SELECT * FROM `eb_store_product_log` WHERE `type` = ? AND `uid` = ? ORDER BY `id`',['order',$uid]);
    $oldIds=array_column($before['eb_store_product_log'],'id');$logs=array_values(array_filter($allOrderLogs,static fn(array $r):bool=>!in_array($r['id'],$oldIds,true)));
    m4c_expect(count($logs)===2,'M4C_TWO_PRODUCTION_ORDER_STAT_ROWS');$actualStats=$expectedStats=[];
    foreach($selection as$line)$expectedStats[]=$line['product_id'].':'.$line['qty'];
    foreach($logs as$log){foreach(['visit_num','cart_num','pay_num','pay_uid','refund_num','collect_num']as$field)m4c_expect((int)$log[$field]===0,'M4C_ORDER_STATS_NO_OTHER_ACTION');
        foreach(['pay_price','cost_price','refund_price']as$field)m4c_expect($log[$field]==='0.00','M4C_ORDER_STATS_NO_FINANCIAL_EFFECT');
        m4c_expect((int)$log['add_time']===(int)$order['add_time'],'M4C_ORDER_STATS_CREATION_TIME');$actualStats[]=(int)$log['product_id'].':'.(int)$log['order_num'];}
    sort($actualStats,SORT_STRING);sort($expectedStats,SORT_STRING);m4c_expect($actualStats===$expectedStats,'M4C_EXACT_ORDER_STAT_MULTISET');
    $after=m4cb_snapshot($pdo);$newCounts=['eb_store_order'=>1,'eb_store_order_invoice'=>1,'eb_store_order_cart_info'=>2,
        'eb_marketplace_order_idempotency'=>1,'eb_marketplace_stock_movement'=>2,'eb_store_order_status'=>1];
    // Preserve every original native payment row; the only insertion is this actual root's verified G0 birth.
    $remainingRegistries=[];$birthCount=0;
    foreach($after['eb_marketplace_payment_registry']as$row){
        if($row['order_id']===$id||$row['order_id']===(string)$id){
            ++$birthCount;m4c_expect($row===$birth,'M4C_PAYMENT_BIRTH_FULL_NATIVE_ROW_STABLE');
        }else $remainingRegistries[]=$row;
    }
    m4c_expect($birthCount===1&&count($after['eb_marketplace_payment_registry'])===count($before['eb_marketplace_payment_registry'])+1
        &&$remainingRegistries===$before['eb_marketplace_payment_registry'],'M4C_PAYMENT_BIRTH_ONLY_NEW_TARGET_REGISTRY');
    m4c_expect($after['eb_marketplace_payment_attempt']===$before['eb_marketplace_payment_attempt'],
        'M4C_PAYMENT_BIRTH_ALL_ORIGINAL_NATIVE_ATTEMPTS_UNCHANGED');
    foreach($newCounts as$table=>$count){$added=m4ar_new_rows($before[$table],$after[$table]);
        m4ar_expect(count($added)===$count&&count($after[$table])===count($before[$table])+$count,'TASK78_EXACT_NEW_ORDER_DELTA_OVER_STAGE6');}
    m4c_expect(count($after['eb_store_product_log'])===count($before['eb_store_product_log'])+2,'M4C_ONLY_TWO_NEW_ORDER_STATS');
    m4c_expect(count($after['eb_store_product'])===count($before['eb_store_product'])
        &&count($after['eb_store_product_attr_value'])===count($before['eb_store_product_attr_value'])
        &&$after['eb_store_cart']===$remaining,'M4C_EXISTING_PRODUCT_AND_REMAINING_CART_SET');
    $newLogIds=array_column($logs,'id');$oldLogs=array_values(array_filter($after['eb_store_product_log'],static fn(array $row):bool=>!in_array($row['id'],$newLogIds,true)));
    m4ar_expect($oldLogs===$before['eb_store_product_log'],'TASK78_PRIOR_ALL_CART_AND_ORDER_STATS_FULLY_PRESERVED');
    m4cb_preserved($before,$after,$uid,[...array_keys($newCounts),'eb_store_product','eb_store_product_attr_value','eb_store_cart','eb_store_product_log',
        'eb_marketplace_payment_registry']);
    return $after;
}

function m4ar_produce_paid(\think\App $app,PDO $db,array $source,array $owner):array
{
 m4ar_phase('new_root_real_cart_confirm_create');$fixture=$owner['fixture'];$uid=$owner['uid'];$mer=(int)$fixture['merchant']['mer_id'];
 $user=m4cb_rows($db,'SELECT * FROM eb_user WHERE uid=?',[$uid]);m4ar_expect(count($user)===1,'TASK78_CURRENT_BUYER_BEFORE_NEW_ROOT');$fixture['user']=$user[0];
 foreach($fixture['products']as$id=>$old){$a=m4cb_rows($db,'SELECT * FROM eb_store_product WHERE id=?',[$id]);m4ar_expect(count($a)===1,'TASK78_REAL_CURRENT_ORIGINAL_PRODUCT');
  foreach(['id','mer_id','price','cost','postage','freight','is_vip','custom_form','store_name']as$k)m4ar_expect($a[0][$k]===$old[$k],'TASK78_NEW_ROOT_COMMERCIAL_INPUT_NOT_REPRICED');$fixture['products'][$id]=$a[0];}
 foreach($fixture['skus']as$id=>$old){$a=m4cb_rows($db,'SELECT * FROM eb_store_product_attr_value WHERE id=?',[$id]);m4ar_expect(count($a)===1,'TASK78_REAL_CURRENT_ORIGINAL_SKU');
  foreach(['id','product_id','type','unique','price','cost','vip_price']as$k)m4ar_expect($a[0][$k]===$old[$k],'TASK78_ACTUAL_SKU_COMMERCIAL_INPUT');$fixture['skus'][$id]=$a[0];}
 $connection=Db::connect();$default=$connection->getPdo();if(!$default instanceof PDO){$connection->query('SELECT 1',[],true);$default=$connection->getPdo();}
 m4ar_expect($default instanceof PDO&&$default!==$db,'TASK78_SEPARATE_NATIVE_OBSERVER_AND_REAL_DEFAULT');
 $driver=$app->make(TestPaymentDriver::class);$service=$app->make(MarketplacePaymentServices::class);$resolver=$app->make(PaymentAccountResolver::class);
 m4ar_expect($driver instanceof TestPaymentDriver&&$service instanceof MarketplacePaymentServices&&$resolver instanceof PaymentAccountResolver,
 'TASK78_REAL_PARENT_PAYMENT_FACTORY_TYPES');
 $GLOBALS['m5pc_factories']=['driver'=>$driver,'service'=>$service,'resolver'=>$resolver];
 m5pcp_context($app,$connection,$default,$db);
 $before=m4ar_snapshot($db);
 $originalRoots=array_values(array_filter($before['rows']['eb_store_order'],static fn(array $row):bool=>(int)$row['pid']===0));
 $originalV1=0;$originalLegacy=0;foreach($originalRoots as$row){
  m4ar_expect(m5pcp_int($row['pid'],0,0)===0&&m5pcp_int($row['uid'],1)===$uid,'TASK78_ORIGINAL_ROOT_OWNER_AND_PARENT');
  $version=m5pcp_int($row['marketplace_trade_version'],0,1);if($version===1)++$originalV1;else ++$originalLegacy;}
 m4ar_expect(count($originalRoots)===101&&$originalV1===100&&$originalLegacy===1,'TASK78_ORIGINAL_OVERALL_101_V1_100_LEGACY_1');
 $refs=[];$start=time();
 foreach($fixture['selected']as$line){$sku=$fixture['skus'][$line['sku_id']];$ref=$app->make(StoreCartServices::class)->setCart($uid,$line['product_id'],$line['qty'],$sku['unique'],0,false);
  $refs[]=MarketplaceOrderInput::reference($ref,'persistent');}
 m4ar_expect(count($refs)===2&&count(array_unique($refs))===2,'TASK78_TWO_REAL_CART_REFERENCES');$end=time();$cart=m4ar_snapshot($db);
 $rows=$before['rows'];$newCart=m4ar_new_rows($rows['eb_store_cart'],$cart['rows']['eb_store_cart']);
 m4ar_expect(count($newCart)===2,'TASK78_ONLY_TWO_NEW_SELECTED_CARTS');
 foreach($fixture['selected']as$i=>$line){$row=m4ar_one($newCart,'id',$refs[$i]);$values=['id'=>$refs[$i],'uid'=>$uid,'product_id'=>$line['product_id'],
  'product_attr_unique'=>$fixture['skus'][$line['sku_id']]['unique'],'cart_num'=>$line['qty'],'is_new'=>0,'is_pay'=>0,'is_del'=>0,'status'=>1,'type'=>'0',
  'add_time'=>m4ar_time($start,$end,$row['add_time'])];m4fn_expected_row($before['database']['tables']['eb_store_cart']['metadata'],$row,$values);}
 $newLogs=m4ar_new_rows($rows['eb_store_product_log'],$cart['rows']['eb_store_product_log']);m4ar_expect(count($newLogs)===2,'TASK78_TWO_REAL_NEW_CART_STATS');
 $wanted=[];foreach($fixture['selected']as$line)$wanted[$line['product_id']]=$line['qty'];
 foreach($newLogs as$log){$pid=(int)$log['product_id'];m4ar_expect(isset($wanted[$pid]),'TASK78_CART_STAT_SELECTED_PRODUCT');
  m4fn_expected_row($before['database']['tables']['eb_store_product_log']['metadata'],$log,['id'=>$log['id'],'product_id'=>$pid,'uid'=>$uid,
   'type'=>'cart','cart_num'=>$wanted[$pid],'add_time'=>m4ar_time($start,$end,$log['add_time'])]);unset($wanted[$pid]);}
 m4ar_expect($wanted===[],'TASK78_EXACT_CART_STAT_MULTISET');
 m4ar_database_delta($before,$cart,['eb_store_cart'=>array_merge($rows['eb_store_cart'],$newCart),'eb_store_product_log'=>array_merge($rows['eb_store_product_log'],$newLogs)],
 ['eb_store_cart'=>2,'eb_store_product_log'=>2]);m4ar_redis_delta($before,$cart);
 $csv=implode(',',$refs);$address=(int)$fixture['address']['id'];$choice=MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>$csv,'addressId'=>$address,'new'=>false]);
 $model=$app->make(UserServices::class)->getUserInfo($uid);m4ar_expect($model!==null&&(int)$model->getData('uid')===$uid,'TASK78_TRUE_CURRENT_CUSTOMER_MODEL');
 $confirmationStart=time();$confirmation=$app->make(StoreOrderServices::class)->getOrderConfirmData($model->toArray(),$csv,false,$address,1,0,$choice);$confirmationEnd=time();
 $key=MarketplaceOrderInput::confirmationKey($confirmation['orderKey']);$cached=CacheService::get('user_order_'.$uid.$key);
 m4ar_expect(is_array($cached),'TASK78_REAL_CONFIRMATION_FRAME');$frame=MarketplaceCartSelection::frame($cached,$uid);
 m4ar_expect($frame['mer_id']===$mer&&$frame['source_kind']==='persistent'&&$frame['cart_refs']===$refs&&count($frame['selection'])===2,'TASK78_REAL_Q2_Q1_CONFIRMATION_SOURCE');
 $answers=[];foreach($frame['custom_form']as$def){$product=$fixture['products'][$def['product_id']];$fields=json_decode($product['custom_form'],true,32,JSON_THROW_ON_ERROR);$definitions=$values=[];
  foreach($fields as$i=>$field){$definitions[]=['field_index'=>$i]+$field;$values[]=['field_index'=>$i,'value'=>$field['label']==='number'?'0':'正常HTTP填写'.$def['product_name']];}
  m4ar_expect($def['fields']===$definitions&&$def['definition_sha256']===hash('sha256',m5pcp_canonical(['product_id'=>$def['product_id'],'fields'=>$definitions])),
  'TASK78_INDEPENDENT_SAVED_NONEMPTY_FORM_DEFINITION');$answers[]=['product_id'=>$def['product_id'],'definition_sha256'=>$def['definition_sha256'],'values'=>$values];}
 $confirmed=m4ar_snapshot($db);m4ar_database_delta($cart,$confirmed,[]);
 m4ar_expect($cart['private']===$confirmed['private']&&$cart['public']===$confirmed['public'],'TASK78_CONFIRMATION_NO_FILE_OR_NATIVE_BUSINESS_WRITE');
 $physical='c:user_order_'.$uid.$key;$cacheEntry=$confirmed['redis']['keys'][hash('sha256',$physical)]??null;
 m4ar_expect(is_array($cacheEntry)&&$cacheEntry['type']==='string'&&!isset($cart['redis']['keys'][hash('sha256',$physical)])
  &&audit_redis()->get($physical)===serialize($cached)&&$cacheEntry['expires']>=$confirmationStart*1000+600000
  &&$cacheEntry['expires']<=($confirmationEnd+1)*1000+600000,'TASK78_TRUE_NEW_CONFIRMATION_BYTES_AND_SIX_HUNDRED_SECOND_DEADLINE');
 m4c_entry_keys($cached,['cartInfo','priceGroup','other','marketplace_confirmation_version','marketplace_uid','mer_id','source_kind','cart_refs',
  'selection','store','custom_form','marketplace_postage_weights']);
 m4ar_expect($cached['marketplace_confirmation_version']===1&&$cached['marketplace_uid']===$uid&&$cached['other']===['offlinePostage'=>'0','integralRatio'=>'0.00'],
  'TASK78_REAL_CONFIRMATION_OWNER_AND_INDEPENDENT_INSTALLED_OPTIONS');
 foreach(['totalPrice'=>'70.55','costPrice'=>'24.25']as$field=>$expected)m4ar_expect($cached['priceGroup'][$field]===$expected,'TASK78_CONFIRMATION_INDEPENDENT_FIXED_COMMERCIAL_TOTALS');
 $tag='c:CRMEB:'.md5('crmeb');m4ar_redis_delta($cart,$confirmed,[$physical=>$cacheEntry]+m4ar_tag_change($cart,$confirmed,$tag,[$physical]));
 $beforeCreate=m4cb_snapshot($db);$raw=['cartId'=>$csv,'mer_id'=>$mer,'new'=>0,'idem_key'=>'confirm:'.$key,'addressId'=>$address,'invoice_id'=>(int)$fixture['invoice']['id'],
 'payType'=>'','mark'=>'普通HTTP下单','gift_mark'=>'普通HTTP文本','real_name'=>'','phone'=>'','shipping_type'=>1,'is_gift'=>0,'store_id'=>0,
 'couponId'=>0,'useIntegral'=>false,'combinationId'=>0,'pinkId'=>0,'seckill_id'=>0,'bargainId'=>0,'advanceId'=>0,'custom_form'=>$answers];
 $input=MarketplaceOrderInput::create($uid,$key,$raw);$createStart=time();
 $created=$app->make(MarketplaceOrderCreateServices::class)->create($uid,$key,$raw);$createEnd=time();
 m4ar_expect(is_string($created['order_id']??null)&&$created['key']===$key,'TASK78_REAL_COMMITTED_NEW_ORDER_NUMBER');
 $createdFacts=m4ar_created_saved($db,$fixture,$input['request'],$input['idem_key'],$frame,$input['request']['options']['custom_form'],$created['order_id'],$beforeCreate,
  ['start'=>$createStart,'end'=>$createEnd]);
 $roots=m4cb_rows($db,'SELECT * FROM eb_store_order WHERE uid=? AND order_id=?',[$uid,$created['order_id']]);m4ar_expect(count($roots)===1,'TASK78_NEW_ROOT_NATURAL_PK');
 $saved=m5pcp_saved($db,(int)$roots[0]['id']);m5pcp_birth($db,$saved);
 $createdSnapshot=m4ar_snapshot($db);$expectedRows=[];$inserts=['eb_store_order'=>1,'eb_store_order_invoice'=>1,'eb_store_order_cart_info'=>2,
 'eb_marketplace_order_idempotency'=>1,'eb_marketplace_stock_movement'=>2,'eb_store_cart'=>2,'eb_store_product_log'=>4,'eb_store_order_status'=>1,
 'eb_marketplace_payment_registry'=>1,'eb_store_product'=>0,'eb_store_product_attr_value'=>0];
 foreach($inserts as$table=>$n)$expectedRows[$table]=$createdFacts[$table];m4ar_database_delta($before,$createdSnapshot,$expectedRows,$inserts);
 m4ar_expect(audit_redis()->get($physical)===false&&$createdSnapshot['private']===$confirmed['private']&&$createdSnapshot['public']===$confirmed['public'],
 'TASK78_REAL_POSTCOMMIT_CONSUMED_ONLY_OWN_CONFIRMATION_AND_NO_FILES');
 m4ar_redis_delta($confirmed,$createdSnapshot,[$physical=>null]+m4ar_sequence_delta($confirmed,$createdSnapshot,$created['order_id']));
 $sequence=m5pcp_sequence_keys($db,$app,$saved);$barrier=m5pcp_sequence_expiry_barrier($db,$sequence);$snapshot=m5pcp_snapshot($db);
 $resolver=$app->make(PaymentAccountResolver::class);$driver=$app->make(TestPaymentDriver::class);$service=$app->make(MarketplacePaymentServices::class);
 m4ar_expect($resolver instanceof PaymentAccountResolver&&$driver instanceof TestPaymentDriver&&$service instanceof MarketplacePaymentServices,
 'TASK78_ONE_REAL_PARENT_FACTORY_PAYMENT_OBJECTS');$account=$resolver->registerTestAccount($mer,'test:aftersale-review:'.$source['run_id'].':'.$mer,1,'CNY');
 m4ar_expect($resolver->forOrder($saved['order'])===$account&&m5pcp_snapshot($db)===$snapshot,'TASK78_ACCOUNT_REGISTRATION_NO_DATABASE_REDIS_MUTATION');
 $proof=m5pcp_payment_case($app,$connection,$default,$db,$service,$driver,['kind'=>'persistent','saved'=>$saved,'account'=>$account->snapshot()],$snapshot);
 $saved=m5pcp_saved($db,(int)$roots[0]['id']);$oracle=m4fn_source_oracle($saved);
 $afterRoots=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE pid=0');$addedRoots=m4ar_new_rows($originalRoots,$afterRoots);
 $afterV1=0;$afterLegacy=0;foreach($afterRoots as$row){$version=m5pcp_int($row['marketplace_trade_version'],0,1);
  if($version===1)++$afterV1;else ++$afterLegacy;}
 m4ar_expect(count($afterRoots)===count($originalRoots)+1&&count($afterRoots)===102&&$afterV1===$originalV1+1&&$afterV1===101
  &&$afterLegacy===$originalLegacy&&$afterLegacy===1&&count($addedRoots)===1&&$addedRoots[0]===$saved['order'],
  'TASK78_ORIGINAL_FULL_ROOT_ROWS_AND_ONLY_ONE_TRUE_ADDITIONAL_V1_ROOT');
 m4ar_expect((int)$saved['order']['paid']===1&&(int)$saved['order']['is_cancel']===0&&$saved['order']['pay_type']==='marketplace_test',
  'TASK78_ONE_TRUE_ADDITIONAL_PAID_ROOT_OVER_101');
 return ['saved'=>$saved,'oracle'=>$oracle,'payment'=>$proof,'sequence'=>$barrier,'created_facts'=>$createdFacts,
  'confirmation_producer'=>['cache_key_sha256'=>hash('sha256',$physical),'complete_serialized_bytes'=>strlen(serialize($cached)),
   'complete_serialized_sha256'=>hash('sha256',serialize($cached)),'absolute_expires_at_ms'=>$cacheEntry['expires'],'consumed_after_commit'=>true]];
}

function m4ar_tag_change(array $before,array $after,string $tag,array $add=[]):array
{
 $redis=audit_redis();$old=$GLOBALS['m4ar_tag_members'][$tag]??null;
 m4ar_expect(is_array($old),'TASK78_TAG_MEMBERS_OBSERVED_BEFORE_PRODUCER');
 $wanted=array_values(array_unique(array_merge($old,$add),SORT_STRING));sort($wanted,SORT_STRING);$actual=$redis->sMembers($tag);sort($actual,SORT_STRING);
 m4ar_expect($actual===$wanted&&$redis->pttl($tag)===-1,'TASK78_EXACT_NATIVE_PRODUCER_TAG_MEMBERS');
 $entry=$after['redis']['keys'][hash('sha256',$tag)]??null;m4ar_expect($entry===['type'=>'set','sha256'=>hash('sha256',"set\0".serialize($wanted)),'expires'=>-1],
 'TASK78_TAG_RAW_ALL_MEMBERS_NO_EXPIRY');$GLOBALS['m4ar_tag_members'][$tag]=$actual;return [$tag=>$entry];
}
/** Decode only the frozen export's finite language INSERT literal grammar; never execute installation SQL. */
function m4ar_language_decode(string $sql):array
{
 $columns=['eb_lang_type'=>['id','language_name','file_name','status','is_default','is_del'],
  'eb_lang_country'=>['id','type_id','code','name'],'eb_lang_code'=>['id','type_id','code','remarks','lang_explain','is_admin']];
 $pattern='~^INSERT INTO `(eb_lang_(?:code|country|type))` \(([^\r\n]+)\) VALUES\r?\n~m';
 m4ar_expect(preg_match('//u',$sql)===1&&preg_match_all($pattern,$sql,$blocks,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)===31,
  'TASK78_LANGUAGE_EXACT_THIRTY_ONE_LITERAL_INSERTS');
 $counts=array_fill_keys(array_keys($columns),0);$totals=$counts;$selected=array_fill_keys(array_keys($columns),[]);
 $seen=array_fill_keys(array_keys($columns),[]);$length=strlen($sql);
 foreach($blocks as$block){
  $table=$block[1][0];$names=$columns[$table];++$counts[$table];
  m4ar_expect($block[2][0]==='`'.implode('`, `',$names).'`','TASK78_LANGUAGE_SOURCE_COLUMN_ORDER');
  $at=$block[0][1]+strlen($block[0][0]);
  do{
   $at+=strspn($sql," \t\r\n",$at);m4ar_expect($at<$length&&$sql[$at++]==='(','TASK78_LANGUAGE_LITERAL_TUPLE_OPEN');$values=[];
   foreach($names as$i=>$name){
    $at+=strspn($sql," \t\r\n",$at);m4ar_expect($at<$length,'TASK78_LANGUAGE_LITERAL_AVAILABLE');
    if($sql[$at]==="'"){
     ++$at;$value='';$closed=false;
     while($at<$length){
      $byte=$sql[$at++];
      if($byte==="\\"){
       m4ar_expect($at<$length&&in_array($sql[$at],["\\",'"'],true),'TASK78_LANGUAGE_ONLY_ACTUAL_EXPORT_ESCAPES');
       $value.=$sql[$at++];
      }elseif($byte==="'"){$closed=true;break;}else $value.=$byte;
     }
     m4ar_expect($closed&&preg_match('//u',$value)===1,'TASK78_LANGUAGE_COMPLETE_UTF8_STRING_LITERAL');
    }else{
     m4ar_expect(preg_match('/\G(?:0|[1-9][0-9]*)/',$sql,$number,0,$at)===1
      &&(strlen($number[0])<10||(strlen($number[0])===10&&strcmp($number[0],'2147483647')<=0)),
      'TASK78_LANGUAGE_ONLY_ACTUAL_BARE_INTEGER_LITERAL');
     $value=(int)$number[0];$at+=strlen($number[0]);
    }
    $values[$name]=$value;$at+=strspn($sql," \t\r\n",$at);$separator=$i===count($names)-1?')':',';
    m4ar_expect($at<$length&&$sql[$at++]===$separator,'TASK78_LANGUAGE_EXACT_LITERAL_ARITY');
   }
   m4ar_expect(is_int($values['id'])&&$values['id']>0&&!isset($seen[$table][$values['id']]),'TASK78_LANGUAGE_SOURCE_UNIQUE_EXPLICIT_PK');
   $seen[$table][$values['id']]=true;++$totals[$table];
   if(($table==='eb_lang_type'&&$values['id']===1)||($table==='eb_lang_country'&&$values['id']===347)
    ||($table==='eb_lang_code'&&$values['type_id']===1))$selected[$table][]=$values;
   $at+=strspn($sql," \t\r\n",$at);m4ar_expect($at<$length&&in_array($sql[$at],[',',';'],true),'TASK78_LANGUAGE_TUPLE_OR_STATEMENT_END');
   $next=$sql[$at++];
  }while($next===',');
 }
 m4ar_expect($counts===['eb_lang_type'=>1,'eb_lang_country'=>1,'eb_lang_code'=>29]
  &&$totals===['eb_lang_type'=>10,'eb_lang_country'=>350,'eb_lang_code'=>25150],'TASK78_ALL_SOURCE_LANGUAGE_TUPLES_PARSED');
 foreach($selected as&$rows)usort($rows,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);unset($rows);
 m4ar_expect($selected['eb_lang_type']===[['id'=>1,'language_name'=>'中文','file_name'=>'zh-CN','status'=>1,'is_default'=>1,'is_del'=>0]]
  &&$selected['eb_lang_country']===[['id'=>347,'type_id'=>1,'code'=>'zh-CN','name'=>'中文(简体) ']]
  &&count($selected['eb_lang_code'])===2515,'TASK78_CANONICAL_SOURCE_CHINESE_TYPE_COUNTRY_AND_ALL_CODES');
 $flags=[0=>0,1=>0];$submit=false;
 foreach($selected['eb_lang_code']as$row){
  m4ar_expect(is_string($row['code'])&&$row['code']!==''&&is_string($row['remarks'])&&is_string($row['lang_explain'])
   &&is_int($row['is_admin'])&&in_array($row['is_admin'],[0,1],true),'TASK78_LANGUAGE_SOURCE_TEXT_AND_ADMIN_TYPES');
  ++$flags[$row['is_admin']];if($row['code']==='提交订单')$submit=true;
 }
 m4ar_expect($flags===[0=>1229,1=>1286]&&$submit,'TASK78_FULL_ADMIN_AND_USER_SOURCE_PACKAGES_NO_REMARKS_UNIQUENESS');
 return $selected;
}
/** Explicit source-PK language changes have their own counter oracle, never old+n. */
function m4ar_language_delta(array $before,array $after,array $rows,array $nativeBefore,array $nativeAfter):void
{
 $expected=$before['database'];$native=$nativeBefore;
 foreach($rows as$table=>$wanted){
  $old=$before['database']['tables'][$table];$tableExpected=$old;
  m4ar_expect(m4fn_row_multiset($after['rows'][$table])===m4fn_row_multiset($wanted),'TASK78_LANGUAGE_ALL_SOURCE_FIELDS_NATIVE_EXACT');
  $tableExpected['projection']=m4fn_projection($wanted);$oldAI=$old['metadata']['table']['AUTO_INCREMENT'];
  $oldText=m5pcp_u64($oldAI);$max=0;foreach($wanted as$row)$max=max($max,$row['id']);
  $next=bcadd((string)$max,'1',0);if(bccomp($next,$oldText,0)<0)$next=$oldText;
  m4ar_expect(is_int($oldAI)||is_string($oldAI),'TASK78_LANGUAGE_ORIGINAL_NATIVE_COUNTER_TYPE');
  $tableExpected['metadata']['table']['AUTO_INCREMENT']=is_int($oldAI)?(int)$next:$next;
  if($next!==$oldText){
   $from=' AUTO_INCREMENT='.$oldText.' ';$to=' AUTO_INCREMENT='.$next.' ';
   m4ar_expect(substr_count($old['ddl'],$from)===1,'TASK78_LANGUAGE_ONE_SOURCE_COUNTER_DDL_OPTION');
   $tableExpected['ddl']=str_replace($from,$to,$old['ddl']);
  }
  m4ar_expect($after['database']['tables'][$table]===$tableExpected,'TASK78_LANGUAGE_ONLY_DERIVED_COUNTER_METADATA_DDL_AND_ROW_PROJECTION');
  $expected['tables'][$table]=$tableExpected;
  if(array_key_exists($table,$expected['target_rows']))$expected['target_rows'][$table]=$wanted;
  $native['auxiliary_rows'][$table]=$wanted;
 }
 m4ar_expect($after['database']===$expected,'TASK78_LANGUAGE_ALL_OTHER_169_TABLES_AND_MODES_INCREMENT_UNCHANGED');
 m4ar_expect(array_keys($after['rows'])===array_keys($before['rows']),'TASK78_LANGUAGE_COMPLETE_ORIGINAL_ROW_TABLE_SET');
 foreach($before['rows']as$table=>$oldRows)if(!isset($rows[$table]))
  m4ar_expect($after['rows'][$table]===$oldRows,'TASK78_LANGUAGE_ALL_OTHER_FULL_NATIVE_ROWS_UNCHANGED');
 m4ar_expect($nativeAfter===$native,'TASK78_LANGUAGE_NATIVE34_ONLY_THREE_SOURCE_PK_ORDERED_AUXILIARY_ROWS');
 m4ar_redis_delta($before,$after);
 m4ar_expect($after['private']===$before['private']&&$after['public']===$before['public'],'TASK78_LANGUAGE_NO_PRIVATE_OR_PUBLIC_FILE_CHANGE');
}
/** Called explicitly after the real owner gate and before this process creates its App. */
function m4ar_language_prepare(PDO $db,array $source):void
{
 m4ar_phase('installed_language_preparation');
 m4ar_expect(empty($GLOBALS['m4c_app_started'])&&!isset($GLOBALS['m4c_application'])&&!$db->inTransaction()
  &&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&$db->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_app@%',
  'TASK78_LANGUAGE_REAL_APP_ACCOUNT_PDO_BEFORE_APP_OUTSIDE_TRANSACTION');
 $path='crmeb/public/install/crmeb.sql';$matches=array_values(array_filter($source['source_files'],
  static fn(array $row):bool=>$row['path']===$path));
 m4ar_expect(count($matches)===1&&$matches[0]===['path'=>$path,'bytes'=>10237372,
  'sha256'=>'7af188f42c622db523d8000c0cf50f95fa48df2fe41123969ca2dbb65ffb5eb2'],'TASK78_LANGUAGE_ORIGINAL_FROZEN_SQL_DESCRIPTOR');
 $sql=null;
 foreach(['/source','/workspace']as$root){
  $file=$root.'/'.$path;m4ar_expect(is_file($file)&&!is_link($file)&&realpath($file)===$file,'TASK78_LANGUAGE_EXACT_SOURCE_SQL_FILE');
  $raw=file_get_contents($file);m4ar_expect(is_string($raw)&&strlen($raw)===$matches[0]['bytes']
   &&hash('sha256',$raw)===$matches[0]['sha256'],'TASK78_LANGUAGE_COMPLETE_FROZEN_SQL_BYTES');
  if($sql===null)$sql=$raw;else m4ar_expect($sql===$raw,'TASK78_LANGUAGE_BOTH_SOURCE_ROOTS_SAME_COMPLETE_SQL');unset($raw);
 }
 $literal=m4ar_language_decode($sql);unset($sql);$before=m4ar_snapshot($db);$nativeBefore=m5pc_native_snapshot($db);
 m4ar_expect(count($before['database']['names'])===172,'TASK78_LANGUAGE_COMPLETE_ORIGINAL_172_TABLE_DATABASE');
 $initial=['eb_lang_type'=>'11','eb_lang_country'=>'351','eb_lang_code'=>'25001'];$rows=[];
 foreach($literal as$table=>$values){
  $old=$before['database']['tables'][$table];$metadata=$old['metadata'];$columns=array_keys($values[0]);
  m4ar_expect($before['rows'][$table]===[]&&$nativeBefore['auxiliary_rows'][$table]===[]
   &&$metadata['table']['ENGINE']==='InnoDB'&&$metadata['table']['TABLE_TYPE']==='BASE TABLE'
   &&array_keys($metadata['columns'])===$columns&&$old['projected_columns']===$columns
   &&($metadata['columns']['id']['EXTRA']??null)==='auto_increment'
   &&m5pcp_u64($metadata['table']['AUTO_INCREMENT'])===$initial[$table],'TASK78_LANGUAGE_EMPTY_ORIGINAL_SCHEMA_COLUMNS_AND_SOURCE_COUNTERS');
  $pk=$metadata['indexes']['PRIMARY']??null;
  m4ar_expect(is_array($pk)&&count($pk)===1&&$pk[0]['COLUMN_NAME']==='id'
   &&m5pcp_int($pk[0]['SEQ_IN_INDEX'],1,1)===1&&m5pcp_int($pk[0]['NON_UNIQUE'],0,0)===0,'TASK78_LANGUAGE_REAL_SINGLE_COLUMN_PRIMARY_KEY');
  $rows[$table]=[];
  foreach($values as$value){
   m4ar_expect(array_keys($value)===$columns,'TASK78_LANGUAGE_SOURCE_EVERY_COLUMN_NO_DEFAULT_REFILL');$expected=[];
   foreach($metadata['columns']as$name=>$column)$expected[$name]=m4fn_native_value($column,$value[$name]);
   $rows[$table][]=$expected;
  }
 }
 m4ar_expect($db->beginTransaction(),'TASK78_LANGUAGE_ONE_OWNED_DML_TRANSACTION');
 try{
  foreach($rows as$table=>$values){
   $columns=array_keys($values[0]);$statement=$db->prepare('INSERT INTO '.m5pcp_quote($table).' ('
    .implode(',',array_map('m5pcp_quote',$columns)).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
   m4ar_expect($statement instanceof PDOStatement,'TASK78_LANGUAGE_PARAMETERIZED_NATIVE_INSERT');
   foreach($values as$value){
    foreach(array_values($value)as$i=>$field)m4ar_expect($statement->bindValue($i+1,$field,is_int($field)?PDO::PARAM_INT:PDO::PARAM_STR),
     'TASK78_LANGUAGE_EXACT_NATIVE_PARAMETER_TYPE');
    m4ar_expect($statement->execute()&&$statement->rowCount()===1,'TASK78_LANGUAGE_ONE_EXPLICIT_SOURCE_PK_INSERT');
   }
  }
  m4ar_expect($db->commit()&&!$db->inTransaction(),'TASK78_LANGUAGE_REAL_COMMITTED_SINGLE_TRANSACTION');
 }catch(Throwable $error){
  if($db->inTransaction())try{$db->rollBack();}catch(Throwable $rollbackError){}
  throw $error;
 }
 $after=m4ar_snapshot($db);$nativeAfter=m5pc_native_snapshot($db);
 m4ar_language_delta($before,$after,$rows,$nativeBefore,$nativeAfter);
}
function m4ar_warm(\think\App $app,PDO $db):void
{
 m4ar_phase('real_source_cache_preparation');$before=m4ar_snapshot($db);$redis=audit_redis();$tag='c:CRMEB:'.md5('crmeb');
 $GLOBALS['m4ar_tag_members'][$tag]=$redis->sMembers($tag);$apiTag='c:CRMEB:'.md5('api');$GLOBALS['m4ar_tag_members'][$apiTag]=$redis->sMembers($apiTag);$known=[];$names=['site_name','site_url','tourist_avatar','site_logo','site_logo_square',
 'nncnL_crmeb_copyright','login_logo','queue_open','wechat_open_app_id','nncnL_crmeb_copyright_image','custom_admin_js','param_filter_type','param_filter_data',
 'new_order_audio_link','model_checkbox','upload_type','image_thumb_status','member_func_status','member_card_status','member_price_status',
 'store_brokerage_binding_status','store_brokerage_binding_time','system_delivery_time','h5_avatar','offline_postage','integral_ratio',
 'offline_pay_status','balance_func_status','yue_pay_status','pay_weixin_open','friend_pay_status','store_self_mention','ali_pay_status',
 'invoice_func_status','special_invoice_status'];
 foreach($names as$name){$expected=$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($name);
  $key='c:system_config_'.$name;$cacheType=m4tr_redis_type($redis,$key);
  m4ar_expect(in_array($cacheType,['none','string'],true),'TASK78_SOURCE_CONFIG_NATIVE_CACHE_TYPE_BEFORE_GETTER');
  $cacheRaw=$redis->get($key);
  m4ar_expect(($cacheType==='none'&&$cacheRaw===false)||($cacheType==='string'&&is_string($cacheRaw)
   &&$cacheRaw===m4tr_cache_wire($expected)),'TASK78_SOURCE_CONFIG_NATIVE_CACHE_BYTES_BEFORE_GETTER');
  $expectedLogical=$cacheType==='string'&&is_numeric($expected)?(string)$expected:$expected;
  $actual=\crmeb\services\SystemConfigService::get($name);m4ar_expect($actual===$expectedLogical,'TASK78_SOURCE_CONFIG_WARM_EQUALS_NATIVE_DB_PRODUCER');
  $known['system_config_'.$name]=['value'=>$expected,'ttl'=>0];}
 $code=$app->make(\app\services\system\lang\LangCodeServices::class);$type=$app->make(\app\services\system\lang\LangTypeServices::class);
 $country=$app->make(\app\services\system\lang\LangCountryServices::class);$range=$type->value(['is_default'=>1],'file_name');
 $typeId=$country->value(['code'=>'zh-CN'],'type_id')?:1;$types=$type->getColumn(['status'=>1,'is_del'=>0],'file_name','id');
 m4ar_expect($range==='zh-CN'&&($types[$typeId]??null)==='zh-CN','TASK78_REAL_INSTALLED_NORMAL_LANGUAGE');
 $lang=['range_name'=>$range,'sys_lang_source_map'=>$code->getColumn(['type_id'=>1],'code','remarks'),'type_id_zh-CN'=>$typeId,
 'lang_type_data'=>$types,'lang_zh_CN'=>$code->getColumn(['type_id'=>$typeId],'lang_explain','code')];getLang('操作成功');
 foreach($lang as$name=>$value)$known[$name]=['value'=>$value,'ttl'=>$name==='range_name'?0:3600];$after=m4ar_snapshot($db);$changes=[];$added=[];
 foreach($known as$name=>$item){$key='c:'.$name;$added[]=$key;$raw=$redis->get($key);if($item['value']===null&&$raw===false)continue;
  m4ar_expect(is_string($raw)&&$raw===m4tr_cache_wire($item['value']),'TASK78_WARM_EXACT_SERIALIZED_SOURCE_VALUE');
  $entry=$after['redis']['keys'][hash('sha256',$key)]??null;m4ar_expect(is_array($entry)&&$entry['type']==='string','TASK78_WARM_NATIVE_STRING');
  if($item['ttl']===0)m4ar_expect($entry['expires']===-1,'TASK78_CONFIG_PERMANENT_DEADLINE');
  else m4ar_expect($entry['expires']>=$before['redis']['time_ms']+1&&$entry['expires']<=$after['redis']['time_ms']+3600001,'TASK78_REAL_LANGUAGE_SOURCE_DEADLINE');
  if(($before['redis']['keys'][hash('sha256',$key)]??null)!==$entry)$changes[$key]=$entry;}
 $changes+=m4ar_tag_change($before,$after,$tag,$added);m4ar_database_delta($before,$after,[]);m4ar_redis_delta($before,$after,$changes);
 m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_WARM_NO_BUSINESS_OR_FILE_MUTATION');
 $GLOBALS['m4ar_warmed_values']=$known;
}
function m4ar_login_cache_delta(array $before,array $after,array $actor,array $reply):void
{
 // Admin login has no role check route; real getMenusList uses native grants, warmed model_checkbox and language.
 m4ar_redis_delta($before,$after,$actor['redis_changes']);
 m4ar_expect(is_array($reply['json']['data']['unique_auth']??null)&&($reply['json']['data']['user_info']['id']??null)===$actor['id'],
 'TASK78_LIMITED_ADMIN_ISSUED_OWN_IDENTITY');
}
function m4ar_customer_login_delta(array $before,array $after,array $actor,array $reply):void
{
 $uid=$actor['id'];$old=m4ar_one($before['rows']['eb_user'],'uid',$uid);$actual=m4ar_one($after['rows']['eb_user'],'uid',$uid);$expected=$old;
 $expected['last_time']=$actual['last_time'];$expected['last_ip']=gethostbyname(gethostname());m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$actual['last_time']);
 m4ar_expect($actual===$expected,'TASK78_CUSTOMER_LOGIN_ONLY_REAL_STATS');m4ar_database_delta($before,$after,['eb_user'=>m4ar_replaced($before['rows']['eb_user'],'uid',$uid,$expected)]);
 $claims=(array)\Firebase\JWT\JWT::decode($actor['token'],audit_config()['signing_key'],['HS256']);
 m4ar_expect(($claims['jti']->type??null)==='api'&&(string)($claims['jti']->id??null)===(string)$uid&&$claims['exp']===$reply['json']['data']['expires_time']
  &&$claims['nbf']===$claims['iat']&&$claims['exp']-$claims['iat']===30*86400,'TASK78_REAL_CUSTOMER_SIGNED_TOKEN_IDENTITY');
 m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$claims['iat']);$ttl=$claims['exp']-$claims['iat']+60;
 $key='c:'.md5($actor['token']);$value=['uid'=>$uid,'type'=>'api','token'=>$actor['token'],'exp'=>$ttl];$redis=audit_redis();
 m4ar_expect($redis->get($key)===serialize($value)&&!isset($before['redis']['keys'][hash('sha256',$key)]),'TASK78_EXACT_NEW_CUSTOMER_SESSION_RECORD');
 $entry=$after['redis']['keys'][hash('sha256',$key)];m4ar_expect($entry['type']==='string'&&$entry['expires']>=$reply['actual_interval'][0]*1000+$ttl*1000
  &&$entry['expires']<=($reply['actual_interval'][1]+1)*1000+$ttl*1000,'TASK78_CUSTOMER_SESSION_REAL_ABSOLUTE_DEADLINE');
 $tag='c:CRMEB:'.md5('api');$changes=[$key=>$entry]+m4ar_tag_change($before,$after,$tag,[$key]);m4ar_redis_delta($before,$after,$changes);
}
function m4ar_audit_delta(array $before,array $after,array $actor,array $spec,array $interval,array &$expected,array &$counts):void
{
 $table=$spec['global']??false?'eb_system_log':'eb_marketplace_audit_log';$new=m4ar_new_rows($before['rows'][$table],$after['rows'][$table]);
 m4ar_expect(count($new)===1,'TASK78_ONE_REAL_OWNED_AUDIT');$row=$new[0];$at=m4ar_time($interval[0],$interval[1],$row['add_time']);
 if($table==='eb_system_log'){
  $rule=$spec['rule'];$names=m5pcp_rows(audit_db(),'SELECT menu_name FROM eb_system_menus WHERE api_url=? LIMIT 2',[$rule]);
  m4ar_expect(count($names)<=1,'TASK78_UNAMBIGUOUS_INSTALLED_VISIT_NAME');$values=['id'=>$row['id'],'method'=>'adminapi','admin_id'=>$actor['id'],
  'add_time'=>$at,'admin_name'=>$actor['account'],'path'=>$rule,'page'=>$names===[]?'未知':($names[0]['menu_name']?:'未知'),
  'ip'=>gethostbyname(gethostname()),'type'=>'system'];
 }else{$values=['id'=>$row['id'],'mer_id'=>$spec['mer_id'],'actor_type'=>$actor['app']==='merchantapi'?'merchant':'admin','actor_id'=>$actor['id'],
  'action'=>$spec['action'],'resource_type'=>$spec['resource_type'],'resource_id'=>(string)$spec['resource_id'],'result'=>'success',
  'detail'=>$row['detail'],'add_time'=>$at];
  $detail=json_decode($row['detail'],false,32,JSON_THROW_ON_ERROR);m4ar_expect($detail instanceof stdClass,'TASK78_REAL_AUDIT_DETAIL_JSON_OBJECT');
  m4c_entry_same(get_object_vars($detail),$spec['detail']??[]);}
 m4fn_expected_row($before['database']['tables'][$table]['metadata'],$row,$values);$expected[$table]=array_merge($before['rows'][$table],[$row]);$counts[$table]=1;
}
function m4ar_sequence_delta(array $before,array $after,string $number):array
{
 $suffix=preg_replace('/\A(?:cp|cf)/','',$number);m4ar_expect(is_string($suffix)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$suffix)===1,'TASK78_TRUE_SNOWFLAKE_PRODUCER_ID');
 $millis=bcadd('1640966400000',bcdiv($suffix,'4194304',0),0);$key=(string)$millis;$hash=hash('sha256',$key);
 $entry=$after['redis']['keys'][$hash]??null;if($entry===null){
  m4ar_expect($after['redis']['time_ms']>=$millis+1000&&!isset($before['redis']['keys'][$hash]),'TASK78_ALREADY_NATURALLY_EXPIRED_NEW_SEQUENCE_KEY');return [];
 }
 $raw=audit_redis()->get($key);$seq=bcmod($suffix,'4096',0);m4ar_expect(is_string($raw)&&preg_match('/\A[1-9][0-9]*\z/D',$raw)===1
 &&($seq==='0'?$raw==='1':$raw===$seq)&&$entry['type']==='string'&&$entry['expires']>=$millis+1000&&$entry['expires']>=$before['redis']['time_ms']+1000&&$entry['expires']<=$after['redis']['time_ms']+1000,
 'TASK78_EXACT_SNOWFLAKE_RESOLVER_VALUE_AND_ONE_SECOND_DEADLINE');return [$key=>$entry];
}

function m4ar_settle_sequence(PDO $db,string $identifier):array
{
 // This only waits for this actual normal producer's one-second counter. It is no ticket/session expiry sample.
 $suffix=preg_replace('/\A(?:cp|cf)/','',$identifier);m4ar_expect(is_string($suffix)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$suffix)===1,
  'TASK78_ONLY_ACTUAL_SAVED_SEQUENCE_IDENTIFIER');$key=bcadd('1640966400000',bcdiv($suffix,'4194304',0),0);$hash=hash('sha256',$key);
 $before=m4ar_snapshot($db);$others=$before['redis']['keys'];$initial=$others[$hash]??null;unset($others[$hash]);$samples=[];$last=$before['redis']['time_ms'];$start=hrtime(true);
 if($initial!==null)m4ar_expect($initial['type']==='string'&&$initial['expires']>$last&&$initial['expires']<=$last+1000,'TASK78_REAL_SAVED_PRODUCER_COUNTER_ONE_SECOND_ONLY');
 do{$current=m5pcp_atomic_redis(audit_redis());m4ar_expect(bccomp($current['time_ms'],$last,0)>=0&&hrtime(true)-$start<=10000000000,
   'TASK78_PASSIVE_NATIVE_COUNTER_MONOTONIC_BOUND');$last=$current['time_ms'];$other=$current['keys'];$value=$other[$hash]??null;unset($other[$hash]);
  m4ar_expect($other===$others,'TASK78_PASSIVE_COUNTER_ALL_NON_TARGET_NATIVE_REDIS_UNCHANGED');
  if($value!==null)m4ar_expect($initial!==null&&$value===$initial&&$current['time_ms']<$initial['expires'],'TASK78_COUNTER_ORIGINAL_VALUE_AND_DEADLINE');
  else m4ar_expect($initial===null?$current['time_ms']>=$key+1000:$current['time_ms']>=$initial['expires'],'TASK78_COUNTER_MISSING_ONLY_AT_NATURAL_DEADLINE');
  $samples[]=['time_ms'=>$current['time_ms'],'present'=>$value!==null,'complete_keys_sha256'=>hash('sha256',serialize($current['keys']))];
  if($value!==null)usleep(20000);
 }while($value!==null);
 $after=m4ar_snapshot($db);m4ar_database_delta($before,$after,[]);m4ar_redis_delta($before,$after,[$key=>null]);
 m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_PASSIVE_COUNTER_NO_FILE_OR_NATIVE_BUSINESS_MUTATION');
 return ['scope'=>'PASSIVE_REAL_NORMAL_PRODUCER_COUNTER_ONLY','identifier_sha256'=>hash('sha256',$identifier),'key_sha256'=>$hash,
  'initial'=>$initial,'samples'=>$samples,'final_absent'=>true,'monotonic_elapsed_ns'=>hrtime(true)-$start];
}

function m4ar_model(array $produced):array
{
 $o=$produced['oracle'];$r=$produced['saved']['order'];return ['source'=>$o,'root'=>$r,'payment_attempt_id'=>$produced['payment']['attempt_id'],
 'refunds'=>[],'last_action'=>null,'last_operation'=>null,'cancel'=>[],'shipped'=>[],'received'=>[],'returned'=>[],'child'=>null,'ship_ranges'=>[],
 'actions'=>[],'operations'=>[],'command_counts'=>[],'replays'=>0];
}
function m4ar_part(array $model,int $item,int $start,int $end,?array $ship=null):array
{
 $line=$model['source']['lines'][$item];$quantity=$end-$start;
 return ['source_item_id'=>$item,'reserve_id'=>$line['reserve_id'],'source_ship_range_id'=>$ship===null?null:(string)$ship['id'],
 'child_order_id'=>$ship===null?null:(int)$ship['child_order_id'],'child_item_id'=>$ship===null?null:(int)$ship['child_item_id'],
 'unit_start'=>$start,'unit_end'=>$end,'quantity'=>$quantity,'goods'=>m4ar_slice($line['goods'],$line['original_quantity'],$start,$end),
 'postage'=>m4ar_slice($line['postage'],$line['original_quantity'],$start,$end),'requested_amount'=>'0.00','confirmed_amount'=>'0.00'];
}
function m4ar_apply_allocations(array $model,array $body):array
{
 $out=[];if($body['operation_kind']==='return_goods'){
  foreach($body['selection']['items']as$item){$parents=array_values(array_filter($model['ship_ranges'],static fn(array $r):bool=>(int)$r['child_item_id']===$item['item_id']));
   m4ar_expect(count($parents)===1,'TASK78_FIXED_REAL_ONE_RANGE_PER_CHILD_ITEM');$ship=$parents[0];
   foreach($item['segments']as$segment){$start=(int)$ship['unit_start']+$segment['first']-1;$end=(int)$ship['unit_start']+$segment['last'];
    m4ar_expect($start>=(int)$ship['unit_start']&&$end<=(int)$ship['unit_end'],'TASK78_REAL_CHILD_ORDINAL_TO_ORIGINAL_Q');$out[]=m4ar_part($model,(int)$ship['source_item_id'],$start,$end,$ship);}}
 }else foreach($body['selection']['items']as$item){m4ar_expect($body['operation_kind']==='money_only'||$model['cancel']===[],'TASK78_FIXED_FIRST_ASCENDING_CANCEL_UNIT');
  $out[]=m4ar_part($model,$item['item_id'],0,$item['quantity']);}
 usort($out,static fn(array $a,array $b):int=>($a['source_item_id']<=>$b['source_item_id'])?:($a['unit_start']<=>$b['unit_start']));
 $remaining=m4ar_cents($body['amount']);foreach($out as&$part){$budget=m4ar_cents($part['goods'])+m4ar_cents($part['postage']);$n=min($remaining,$budget);$part['requested_amount']=m4ar_decimal($n);$remaining-=$n;}unset($part);
 m4ar_expect($remaining===0,'TASK78_INDEPENDENT_ORDERED_CENT_CAPACITY_ALLOCATION');return $out;
}
function m4ar_refund_state(?array $old,string $action,array $body,string $kind,int $accepted,int $selected):array
{
 if($action==='apply')return ['business_state'=>'REQUESTED','physical_state'=>match($kind){'money_only'=>'NONE','cancel_unshipped'=>'CANCEL_PENDING','return_goods'=>'RETURN_PENDING'},
 'finance_state'=>'NOT_SUBMITTED','requested_amount'=>$body['amount'],'confirmed_amount'=>'0.00','held_amount'=>$body['amount'],
 'refund_type'=>$kind==='return_goods'?2:1,'is_cancel'=>0,'return_delivery'=>null];
 m4ar_expect(is_array($old),'TASK78_SAVED_REFUND_STATE_PREDECESSOR');$next=$old;
 if(in_array($action,['withdraw','reject'],true)){$next['business_state']=$action==='withdraw'?'WITHDRAWN':'REJECTED';$next['physical_state']='NONE';
  $next['held_amount']='0.00';$next['is_cancel']=$action==='withdraw'?1:0;if($action==='reject')$next['refund_type']=3;}
 elseif($action==='approve'){$next['business_state']='APPROVED';$next['finance_state']='UNSUPPORTED';if($kind==='cancel_unshipped')$next['physical_state']='CANCELLED';
  if($kind==='return_goods')$next['refund_type']=4;}
 elseif($action==='express'){$next['return_delivery']=$body['return_delivery'];$next['refund_type']=5;}
 elseif($action==='accept_return'){$next['physical_state']=$accepted===$selected?'RETURN_ACCEPTED':'RETURN_PARTIAL';$next['refund_type']=5;}
 else m4ar_expect(false,'TASK78_NO_FINANCE_OR_UNKNOWN_COMMAND');return $next;
}
function m4ar_quantity(array $parts):int
{$n=0;foreach($parts as$p)$n+=$p['unit_end']-$p['unit_start'];return $n;}
function m4ar_units(array $parts):array
{$out=[];foreach($parts as$p)for($i=$p['unit_start'];$i<$p['unit_end'];$i++){$key=$p['source_item_id'].':'.$i;m4ar_expect(!isset($out[$key]),'TASK78_NO_DOUBLE_PHYSICAL_UNIT');$out[$key]=true;}return $out;}
function m4ar_summary(array $model):array
{
 $s=m4ar_units($model['shipped']);$r=m4ar_units($model['received']);$c=m4ar_units($model['cancel']);$t=m4ar_units($model['returned']);
 m4ar_expect(array_diff_key($r,$s)===[]&&array_diff_key($t,$s)===[]&&array_intersect_key($s,$c)===[],'TASK78_PHYSICAL_SUBSETS_AND_CANCEL_DISJOINT');
 $toShip=3-count($s)-count($c);$toReceive=count(array_diff_key($s,$r+$t));
 return ['shipping_state'=>$s===[]?'unshipped':($toShip>0?'partial':'shipped'),
 'receipt_state'=>$s===[]?'not_shipped':(count($r)===count($s)?'received':($r===[]?'pending':'partial')),
 'closed'=>$toShip===0&&$toReceive===0,'original'=>3,'shipped'=>count($s),'received'=>count($r),'cancelled_unshipped'=>count($c),
 'returned_accepted'=>count($t),'to_ship'=>$toShip,'to_receive'=>$toReceive,'child_count'=>$model['child']===null?0:1];
}
function m4ar_public_items(array $model,array $allocations,array $accepted):array
{
 $items=[];$display=[];foreach($model['source']['display']as$item)$display[$item['id']]=$item;
 foreach($allocations as$p){$id=$p['source_item_id'];if(!isset($items[$id])){$item=$display[$id];$item['quantity']=0;$item['line_price']='0.00';$item['postage_price']='0.00';
  $items[$id]=['item'=>$item,'selected_quantity'=>0,'accepted_quantity'=>0,'confirmed_amount'=>'0.00'];}
  $items[$id]['item']['quantity']+=$p['quantity'];$items[$id]['selected_quantity']+=$p['quantity'];
  $items[$id]['item']['line_price']=m4ar_decimal(m4ar_cents($items[$id]['item']['line_price'])+m4ar_cents($p['goods']));
  $items[$id]['item']['postage_price']=m4ar_decimal(m4ar_cents($items[$id]['item']['postage_price'])+m4ar_cents($p['postage']));}
 foreach($accepted as$p)$items[$p['source_item_id']]['accepted_quantity']+=$p['unit_end']-$p['unit_start'];ksort($items,SORT_NUMERIC);return array_values($items);
}
function m4ar_return_view(array $model,array $record,array $state):?array
{
 if($record['kind']!=='return_goods')return null;$out=[];
 foreach($record['body']['selection']['items']as$item){$parents=array_values(array_filter($model['ship_ranges'],static fn(array $r):bool=>(int)$r['child_item_id']===$item['item_id']));
  m4ar_expect(count($parents)===1,'TASK78_RETURN_VIEW_TRUE_PARENT');$parent=$parents[0];$done=[];
  foreach($record['accepted']as$p)if($p['child_item_id']===$item['item_id'])$done[]=['first'=>$p['unit_start']-(int)$parent['unit_start']+1,'last'=>$p['unit_end']-(int)$parent['unit_start']];
  $eligible=$state['business_state']==='APPROVED'&&$done===[]?$item['segments']:[];
  $out[]=['item_id'=>$item['item_id'],'quantity'=>(int)$parent['quantity'],'selected_quantity'=>1,'selected_segments'=>$item['segments'],
   'accepted_quantity'=>count($done),'accepted_segments'=>$done,'eligible_quantity'=>count($eligible),'eligible_segments'=>$eligible];}
 return ['schema'=>1,'coordinate'=>'child_item_ordinal','child_order_no'=>$record['body']['selection']['child_order_no'],'items'=>$out];
}
function m4ar_refund_view(array $model,array $record,array $state,array $actor,int $at):array
{
 $customer=$actor['app']==='api';$handler=in_array($actor['app'],['merchantapi','adminapi'],true);$requested=$state['business_state']==='REQUESTED';
 $approved=$state['business_state']==='APPROVED'&&$record['kind']==='return_goods';$root=$model['root'];
 return ['schema'=>2,'scope'=>'marketplace_aftersale_v1','id'=>$record['id'],'refund_no'=>$record['number'],'root_order_id'=>(int)$root['id'],
 'root_order_no'=>$root['order_id'],'merchant_name_snapshot'=>$root['merchant_name_snapshot'],'operation_kind'=>$record['kind'],
 'mode'=>$record['kind']==='return_goods'?'return_goods':'money_only','business_state'=>$state['business_state'],'physical_state'=>$state['physical_state'],
 'finance_state'=>$state['finance_state'],'requested_amount'=>$state['requested_amount'],'confirmed_amount'=>'0.00','held_amount'=>$state['held_amount'],
 'created_at'=>$record['created_at'],'updated_at'=>$at,'reason'=>$record['body']['reason'],'images'=>$record['body']['images'],
 'return_delivery'=>$state['return_delivery'],'items'=>m4ar_public_items($model,$record['allocations'],$record['accepted']),
 'allowed_actions'=>['withdraw'=>$customer&&$requested,'submit_return'=>$customer&&$approved,'approve'=>$handler&&$requested,'reject'=>$handler&&$requested,
 'accept_return'=>$handler&&$approved&&m4ar_quantity($record['accepted'])<m4ar_quantity($record['allocations'])],
 'return_selection'=>m4ar_return_view($model,$record,$state)];
}
function m4ar_credit_oracle(array $before,array $after,array $model,array $actor,int $refund,string $actionId,string $kind,array $physical,int $at,array &$rows,array &$counts):array
{
 if($physical===[])return [];$new=m4ar_new_rows($before['rows']['eb_marketplace_stock_movement'],$after['rows']['eb_marketplace_stock_movement']);
 m4ar_expect(count($new)===count($physical),'TASK78_ONE_TRUE_CREDIT_PER_SELECTED_SOURCE');$products=$before['rows']['eb_store_product'];$skus=$before['rows']['eb_store_product_attr_value'];$refs=[];
 usort($physical,static fn(array $a,array $b):int=>($model['source']['lines'][$a['source_item_id']]['sku_id']<=>$model['source']['lines'][$b['source_item_id']]['sku_id'])?:($a['source_item_id']<=>$b['source_item_id']));
 foreach($physical as$p){$item=$p['source_item_id'];$line=$model['source']['lines'][$item];$qty=$p['unit_end']-$p['unit_start'];
  $matches=array_values(array_filter($new,static fn(array $r):bool=>(int)$r['order_item_id']===$item));m4ar_expect(count($matches)===1,'TASK78_CREDIT_NATIVE_SOURCE_ITEM');$row=$matches[0];
  $sku=m4ar_one($skus,'id',$line['sku_id']);$product=m4ar_one($products,'id',$line['product_id']);$contract=$kind==='release'?'release_v2':'return_in_v1';
  $digest=['schema'=>1,'credit_contract'=>$contract,'action_id'=>$actionId,'refund_id'=>$refund,'root_order_id'=>(int)$model['root']['id'],
   'uid'=>(int)$model['root']['uid'],'mer_id'=>(int)$model['root']['mer_id'],'source_item_id'=>$item,'parent_reserve_id'=>$line['reserve_id'],
   'product_id'=>$line['product_id'],'sku_id'=>$line['sku_id'],'sku_type'=>0,'sku_unique'=>$line['sku_unique'],'kind'=>$kind,'quantity'=>$qty,
   'segments'=>[['unit_start'=>$p['unit_start'],'unit_end'=>$p['unit_end']]],'actor_type'=>$actor['app']==='merchantapi'?'employee':'admin','actor_id'=>$actor['id']];
  $values=['id'=>$row['id'],'mer_id'=>(int)$model['root']['mer_id'],'order_id'=>(int)$model['root']['id'],'product_id'=>$line['product_id'],'sku_id'=>$line['sku_id'],
   'sku_type'=>0,'sku_unique'=>$line['sku_unique'],'qty'=>$qty,'kind'=>$kind,'idem_key'=>$contract.':'.$actionId.':'.$item,
   'parent_reserve_id'=>$line['reserve_id'],'order_item_id'=>$item,'refund_id'=>$refund,'actor_type'=>$digest['actor_type'],'actor_id'=>$actor['id'],
   'request_digest'=>hash('sha256',m5pcp_canonical($digest),true),'sku_stock_before'=>(int)$sku['stock'],'sku_stock_after'=>(int)$sku['stock']+$qty,
   'sku_sales_before'=>(int)$sku['sales'],'sku_sales_after'=>(int)$sku['sales']-$qty,'root_stock_before'=>(int)$product['stock'],
   'root_stock_after'=>(int)$product['stock']+$qty,'root_sales_before'=>(int)$product['sales'],'root_sales_after'=>(int)$product['sales']-$qty,'add_time'=>$at];
  m4fn_expected_row($before['database']['tables']['eb_marketplace_stock_movement']['metadata'],$row,$values);
  $nextSku=$sku;$nextSku['stock']=(int)$sku['stock']+$qty;$nextSku['sales']=(int)$sku['sales']-$qty;$nextProduct=$product;
  $nextProduct['stock']=(int)$product['stock']+$qty;$nextProduct['sales']=(int)$product['sales']-$qty;
  $skus=m4ar_replaced($skus,'id',$line['sku_id'],$nextSku);$products=m4ar_replaced($products,'id',$line['product_id'],$nextProduct);
  $refs[]=['movement_id'=>(string)$row['id'],'kind'=>$kind,'source_item_id'=>$item,'reserve_id'=>$line['reserve_id'],
   'unit_start'=>$p['unit_start'],'unit_end'=>$p['unit_end'],'quantity'=>$qty];}
 $rows['eb_marketplace_stock_movement']=array_merge($before['rows']['eb_marketplace_stock_movement'],$new);$counts['eb_marketplace_stock_movement']=count($new);
 $rows['eb_store_product']=$products;$rows['eb_store_product_attr_value']=$skus;return $refs;
}

function m4ar_action(PDO $db,array &$model,array $actor,string $action,string $path,array $body,?int $refundId=null,array $accepted=[]):array
{
 m4ar_phase('normal_aftersale_'.$action);$operationId='non_action';
 foreach(['money-withdraw'=>'apply','withdraw'=>'withdraw','money-merchant'=>'apply','business-reject-merchant'=>'reject',
  'money-platform'=>'apply','business-reject-platform'=>'reject','cancel-apply'=>'apply','cancel-approve'=>'approve',
  'return-apply'=>'apply','return-approve'=>'approve','return-express'=>'express','accept-merchant'=>'accept_return','accept-platform'=>'accept_return']as$name=>$expectedAction)
  if($action===$expectedAction&&is_string($body['idem_key']??null)&&str_starts_with($body['idem_key'],'normal78:'.$name.':')){$operationId=$name;break;}
 $before=m4ar_snapshot($db);$reply=m4ar_json('POST',$path,$actor,$body,$operationId,'first');$after=m4ar_snapshot($db);$answer=$reply['json']['data'];
 m4c_entry_keys($answer,['schema','scope','action_id','action','refund','committed_at','acceptance_ids']);
 $new=m4ar_new_rows($before['rows']['eb_marketplace_aftersale_action'],$after['rows']['eb_marketplace_aftersale_action']);
 m4ar_expect(count($new)===1,'TASK78_ONE_REAL_NEW_COMMITTED_ACTION');$native=$new[0];$actionId=m5pcp_u64($native['id']);
 m4ar_expect($actionId===$answer['action_id']&&$actionId===m5pcp_u64($before['database']['tables']['eb_marketplace_aftersale_action']['metadata']['table']['AUTO_INCREMENT']),
 'TASK78_ACTUAL_ACTION_PK_FROM_NATIVE_INSERT');$at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$native['created_at']);
 $committed=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$native['committed_at']);m4ar_expect($committed>=$at,'TASK78_COMMIT_NOT_BEFORE_ACTION');
 if($action==='apply'){$refundId=(int)$native['refund_id'];$refund=m4ar_one($after['rows']['eb_store_order_refund'],'id',$refundId);
  m4ar_expect((string)$refundId===m5pcp_u64($before['database']['tables']['eb_store_order_refund']['metadata']['table']['AUTO_INCREMENT']),
  'TASK78_REFUND_NATURAL_ID');$record=['id'=>$refundId,'number'=>$refund['order_id'],'body'=>$body,'kind'=>$body['operation_kind'],
   'allocations'=>m4ar_apply_allocations($model,$body),'accepted'=>[],'created_at'=>$at,'apply_action'=>$actionId,'last_action'=>null,'state'=>null];
 }else{m4ar_expect($refundId!==null&&isset($model['refunds'][$refundId]),'TASK78_EXISTING_INDEPENDENT_REFUND_HISTORY');$record=$model['refunds'][$refundId];
  $refund=m4ar_one($after['rows']['eb_store_order_refund'],'id',$refundId);}
 $previous=$record['state'];$record['accepted']=array_merge($record['accepted'],$accepted);
 $state=m4ar_refund_state($previous,$action,$body,$record['kind'],m4ar_quantity($record['accepted']),m4ar_quantity($record['allocations']));
 $physical=$action==='approve'&&$record['kind']==='cancel_unshipped'?$record['allocations']:($action==='accept_return'?$accepted:[]);
 $rows=[];$counts=[];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$rows);
 $movementRefs=m4ar_credit_oracle($before,$after,$model,$actor,$refundId,$actionId,$action==='approve'?'release':'return_in',$physical,$at,$rows,$counts);
 if($action==='approve'&&$record['kind']==='cancel_unshipped')$model['cancel']=array_merge($model['cancel'],$physical);
 if($action==='accept_return')$model['returned']=array_merge($model['returned'],$physical);
 $view=m4ar_refund_view($model,$record,$state,$actor,$at);$acceptance=$action==='accept_return'?array_values(array_unique(array_column($movementRefs,'movement_id'))):[];
 $public=['schema'=>2,'scope'=>'marketplace_aftersale_action_v1','action_id'=>$actionId,'action'=>$action,'refund'=>$view,'committed_at'=>$committed,'acceptance_ids'=>$acceptance];
 m4c_entry_same($answer,$public);$internal=['schema'=>1,'before'=>$previous,'after'=>$state,'allocations'=>$record['allocations'],
 'movements'=>$movementRefs,'public_result'=>$public];
 $type=match($actor['app']){'api'=>'customer','merchantapi'=>'employee','adminapi'=>'admin'};$actorRef=$type.':'.$actor['id'];
 $request=['schema'=>1,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'refund_id'=>$refundId,'public_body'=>$body];
 $values=['id'=>$native['id'],'contract_version'=>1,'root_order_id'=>(int)$model['root']['id'],'uid'=>(int)$model['root']['uid'],
 'mer_id'=>(int)$model['root']['mer_id'],'refund_id'=>$refundId,'payment_attempt_id'=>$model['payment_attempt_id'],'action'=>$action,
 'actor_type'=>$type,'actor_id'=>$actor['id'],'actor_ref'=>$actorRef,'idem_key'=>$body['idem_key'],
 'request_digest'=>hash('sha256',m5pcp_canonical(['actor_ref'=>$actorRef,'request_payload'=>$request]),true),
 'request_payload'=>['json'=>$request],'result_payload'=>['json'=>$internal],'state'=>1,'previous_refund_action_id'=>$record['last_action'],
 'fulfillment_cut_id'=>$model['last_operation'],'apply_refund_id'=>$action==='apply'?$refundId:null,'created_at'=>$at,'committed_at'=>$committed];
 m4fn_expected_row($before['database']['tables']['eb_marketplace_aftersale_action']['metadata'],$native,$values);
 $rows['eb_marketplace_aftersale_action']=array_merge($before['rows']['eb_marketplace_aftersale_action'],[$native]);$counts['eb_marketplace_aftersale_action']=1;
 if($action==='apply'){$items=m4ar_public_items($model,$record['allocations'],[]);$cart=[];foreach($items as$i)$cart[]=$i['item'];
  $refundValues=['id'=>$refundId,'store_order_id'=>(int)$model['root']['id'],'store_id'=>0,'order_id'=>$record['number'],'uid'=>(int)$model['root']['uid'],
   'refund_type'=>$state['refund_type'],'refund_num'=>m4ar_quantity($record['allocations']),'refund_price'=>$body['amount'],'refunded_price'=>'0.00',
   'refund_phone'=>'','refund_express'=>'','refund_express_name'=>'','refund_explain'=>'','refund_img'=>m5pcp_canonical($body['images']),
   'refund_reason'=>$body['reason'],'refuse_reason'=>'','remark'=>'','refunded_time'=>0,'cart_info'=>m5pcp_canonical($cart),'is_cancel'=>0,
   'is_pink_cancel'=>0,'is_del'=>0,'add_time'=>$at,'is_system_del'=>0,'mer_id'=>(int)$model['root']['mer_id'],
   'marketplace_refund_mode'=>$record['kind']==='return_goods'?'return_goods':'money_only','marketplace_root_order_id'=>(int)$model['root']['id'],
   'marketplace_payment_attempt_id'=>$model['payment_attempt_id'],'marketplace_kind'=>$record['kind'],'marketplace_state'=>$state['business_state'],
   'marketplace_physical_state'=>$state['physical_state'],'marketplace_finance_state'=>$state['finance_state'],
   'marketplace_apply_action_id'=>$actionId,'marketplace_last_action_id'=>$actionId];
  m4fn_expected_row($before['database']['tables']['eb_store_order_refund']['metadata'],$refund,$refundValues);
  $rows['eb_store_order_refund']=array_merge($before['rows']['eb_store_order_refund'],[$refund]);$counts['eb_store_order_refund']=1;
 }else{$old=m4ar_one($before['rows']['eb_store_order_refund'],'id',$refundId);$want=$old;$delivery=$state['return_delivery'];
  $changes=['refund_type'=>$state['refund_type'],'refunded_price'=>'0.00','refund_express'=>$delivery===null?'':$delivery['tracking_no'],
   'refund_express_name'=>$delivery===null?'':$delivery['carrier_name'],'refunded_time'=>$at,'is_cancel'=>$state['is_cancel'],
   'marketplace_root_order_id'=>(int)$model['root']['id'],'marketplace_payment_attempt_id'=>$model['payment_attempt_id'],
   'marketplace_kind'=>$record['kind'],'marketplace_state'=>$state['business_state'],'marketplace_physical_state'=>$state['physical_state'],
   'marketplace_finance_state'=>$state['finance_state'],'marketplace_apply_action_id'=>$record['apply_action'],'marketplace_last_action_id'=>$actionId];
  if($action==='reject')$changes['refuse_reason']=$body['reason'];foreach($changes as$k=>$v)$want[$k]=m4fn_native_value($before['database']['tables']['eb_store_order_refund']['metadata']['columns'][$k],$v);
  m4ar_expect($want===$refund,'TASK78_FULL_NATIVE_REFUND_MIRROR_ONLY_INDEPENDENT_STATE');$rows['eb_store_order_refund']=m4ar_replaced($before['rows']['eb_store_order_refund'],'id',$refundId,$want);
 }
 if($action==='apply'&&$body['images']!==[]){$files=$before['rows']['eb_marketplace_aftersale_file'];foreach($body['images']as$position=>$id){
   $old=m4ar_one($files,'id',$id);$now=m4ar_one($after['rows']['eb_marketplace_aftersale_file'],'id',$id);$want=$old;$want['refund_id']=$refundId;$want['image_position']=$position;
   $want['claimed_at']=$now['claimed_at'];$want['state']='CLAIMED';m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$now['claimed_at']);
   m4ar_expect($now===$want,'TASK78_REAL_FILE_CLAIM_ONLY_FOUR_NATIVE_COLUMNS');$files=m4ar_replaced($files,'id',$id,$want);}$rows['eb_marketplace_aftersale_file']=$files;}
 $summary=m4ar_summary($model);$newStatus=$summary['to_ship']>0?0:($summary['to_receive']>0?1:($summary['shipped']>0?2:0));$orderRows=$before['rows']['eb_store_order'];
 $root=m4ar_one($orderRows,'id',$model['root']['id']);$wanted=$root;$wanted['status']=$newStatus;$orderRows=m4ar_replaced($orderRows,'id',$root['id'],$wanted);
 if($model['child']!==null){$child=m4ar_one($orderRows,'id',$model['child']['id']);$wantChild=$child;$wantChild['status']=$summary['to_receive']===0?2:1;
  $orderRows=m4ar_replaced($orderRows,'id',$child['id'],$wantChild);$model['child']=$wantChild;}
 $rows['eb_store_order']=$orderRows;
 if((int)$root['status']!==$newStatus){$logs=m4ar_new_rows($before['rows']['eb_store_order_status'],$after['rows']['eb_store_order_status']);m4ar_expect(count($logs)===1,'TASK78_ONE_ACTUAL_AFTERSALE_STATUS_LOG');
  m4fn_expected_row($before['database']['tables']['eb_store_order_status']['metadata'],$logs[0],['oid'=>(int)$root['id'],'change_type'=>'marketplace_aftersale',
   'change_message'=>'多商户售后状态更新','change_time'=>$at]);$rows['eb_store_order_status']=array_merge($before['rows']['eb_store_order_status'],$logs);$counts['eb_store_order_status']=1;}
 if($actor['app']!=='api'){$old=$action==='apply'?null:m4ar_one($before['rows']['eb_store_order_refund'],'id',$refundId);$changed=[];
  if($old!==null)foreach($old as$k=>$v)if($refund[$k]!==$v)$changed[]=$k;
  m4ar_audit_delta($before,$after,$actor,['mer_id'=>(int)$root['mer_id'],'action'=>$actor['app']==='merchantapi'?'aftersale.'.$action:
   match($action){'approve'=>'marketplace-aftersales-approve','reject'=>'marketplace-aftersales-reject','accept_return'=>'marketplace-aftersales-return-accept'},
   'resource_type'=>'aftersale','resource_id'=>$refundId,'detail'=>['changed_fields'=>$changed]],$reply['actual_interval'],$rows,$counts);}
 m4ar_database_delta($before,$after,$rows,$counts);$redis=m4ar_cache_deletes($before,$after,$actor);if($action==='apply')$redis+=m4ar_sequence_delta($before,$after,$record['number']);
 m4ar_redis_delta($before,$after,$redis);m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_ACTION_CLAIM_RETAINS_SAME_REAL_OBJECT_BYTES');
 $record['state']=$state;$record['last_action']=$actionId;$record['updated_at']=$at;$record['native']=$refund;$model['refunds'][$refundId]=$record;
 $model['last_action']=$actionId;$model['actions'][]=$native;$model['root']=$wanted;$model['command_counts'][$action]=($model['command_counts'][$action]??0)+1;
 // Immediately replay this same actual actor and exact original body; A2 is immutable, business/files stay exact.
 if($action==='apply'){$record['sequence_barrier']=m4ar_settle_sequence($db,$record['number']);$model['refunds'][$refundId]=$record;$after=m4ar_snapshot($db);}
 $replayBefore=$after;$replayed=m4ar_json('POST',$path,$actor,$body,$operationId,'replay');m4c_entry_same($replayed['json']['data'],$public);$replayAfter=m4ar_snapshot($db);
 $replayRows=[];m4ar_auth_delta($replayBefore,$replayAfter,$actor,$replayed['actual_interval'],$replayRows);
 m4ar_database_delta($replayBefore,$replayAfter,$replayRows);m4ar_redis_delta($replayBefore,$replayAfter,m4ar_cache_deletes($replayBefore,$replayAfter,$actor));
 m4ar_expect($replayBefore['private']===$replayAfter['private']&&$replayBefore['public']===$replayAfter['public'],'TASK78_A2_REPLAY_NO_FILE_RECLAIM_OR_AUDIT');++$model['replays'];return $record;
}

function m4ar_aftersale(array $model):array
{
 $held=0;$pendingCancel=0;$pendingReturn=0;foreach($model['refunds']as$r){$held+=m4ar_cents($r['state']['held_amount']);
  if($r['state']['business_state']==='REQUESTED'&&$r['kind']==='cancel_unshipped')$pendingCancel+=m4ar_quantity($r['allocations']);
  if(in_array($r['state']['business_state'],['REQUESTED','APPROVED'],true)&&$r['kind']==='return_goods')$pendingReturn+=m4ar_quantity($r['allocations'])-m4ar_quantity($r['accepted']);}
 return ['schema'=>1,'revision'=>$model['last_action'],'confirmed_amount'=>'0.00','held_amount'=>m4ar_decimal($held),
 'available_amount'=>m4ar_decimal(m4ar_cents($model['root']['pay_price'])-$held),'pending_cancel_quantity'=>$pendingCancel,
 'pending_return_quantity'=>$pendingReturn,'returned_after_receipt'=>count(array_intersect_key(m4ar_units($model['received']),m4ar_units($model['returned']))),
 'fulfillment_completed_at'=>$model['completed_at']??null];
}
function m4ar_plan(array $model,array $parts):array
{
 $allocations=[];$totals=['quantity'=>0,'goods'=>'0.00','postage'=>'0.00','origin_postage'=>'0.00','payable'=>'0.00','cost'=>'0.00','base_goods'=>'0.00','discount'=>'0.00',
 'level_discount'=>'0.00','member_discount'=>'0.00'];
 foreach($parts as$p){$one=m4fn_plan($model['source'],$p['source_item_id'],$p['unit_start'],$p['unit_end']);$allocations[]=$one['allocations'][0];
  foreach($totals as$k=>$v)$totals[$k]=$k==='quantity'?$totals[$k]+$one['totals'][$k]:m4ar_decimal(m4ar_cents($totals[$k])+m4ar_cents($one['totals'][$k]));}
 return ['plan_version'=>1,'root_order_id'=>(int)$model['root']['id'],'source_snapshot_sha256'=>$model['source']['fingerprint'],'allocations'=>$allocations,'totals'=>$totals];
}
function m4ar_operation(PDO $db,array &$model,array $actor,string $action,string $path,array $body,array $parts):array
{
 m4ar_phase('normal_s2_'.$action);$operationId='non_action';
 foreach(['ship-two'=>'ship','receive-one'=>'receive']as$name=>$expectedAction)
  if($action===$expectedAction&&is_string($body['idem_key']??null)&&str_starts_with($body['idem_key'],'normal78:'.$name.':')){$operationId=$name;break;}
 $before=m4ar_snapshot($db);$reply=m4ar_json('POST',$path,$actor,$body,$operationId,'first');$after=m4ar_snapshot($db);$ship=$action==='ship';
 $ops='eb_marketplace_fulfillment_operation';$ranges='eb_marketplace_fulfillment_range';$orders='eb_store_order';$items='eb_store_order_cart_info';
 $added=m4ar_new_rows($before['rows'][$ops],$after['rows'][$ops]);m4ar_expect(count($added)===1,'TASK78_ONE_REAL_S2_OPERATION');$raw=$added[0];$id=m5pcp_u64($raw['id']);
 m4ar_expect($id===m5pcp_u64($before['database']['tables'][$ops]['metadata']['table']['AUTO_INCREMENT']),'TASK78_S2_NATURAL_OPERATION_PK');
 $at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$raw['created_at']);$commit=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$raw['committed_at']);
 m4ar_expect($commit>=$at,'TASK78_S2_NATIVE_COMMIT_INTERVAL');$plan=m4ar_plan($model,$parts);$rows=[];$counts=[];
 $request=['schema'=>1,'action'=>$action,'root_order_id'=>(int)$model['root']['id']];$newChildren=[];$newItems=[];$target=null;
 if($ship){$child=m4fn_new_row($before['rows'][$orders],$after['rows'][$orders],$before['database']['tables'][$orders]['metadata']);$newChildren=[$child];m4ar_expect(count($newChildren)===1,'TASK78_ONE_TRUE_TWO_ITEM_CHILD');
  $request['quantities']=[];foreach($plan['allocations']as$a)$request['quantities'][]=['source_item_id'=>$a['source_item_id'],'quantity'=>$a['quantity']];$request['delivery']=$body['delivery'];
  $values=m4fn_child_values($model['source'],$plan,$request,$child,$at);$values['cart_id']=['json'=>array_column($plan['allocations'],'cart_ref')];
  $values['custom_form']=['json'=>$model['source']['forms']];m4fn_expected_row($before['database']['tables'][$orders]['metadata'],$child,$values);
  $newItems=m4ar_new_rows($before['rows'][$items],$after['rows'][$items]);m4ar_expect(count($newItems)===2,'TASK78_TWO_REAL_NATIVE_CHILD_ITEMS');
  foreach($plan['allocations']as$a){$found=array_values(array_filter($newItems,static fn(array $r):bool=>(int)$r['product_id']===$a['product_id']));
   m4ar_expect(count($found)===1,'TASK78_CHILD_ITEM_ORIGINAL_SOURCE');m4fn_expected_row($before['database']['tables'][$items]['metadata'],$found[0],m4fn_item_values($model['source'],$a,$child,$id,$found[0]));}
  $model['child']=$child;$counts[$orders]=1;$counts[$items]=2;$rows[$items]=array_merge($before['rows'][$items],$newItems);
 }else{$child=$model['child'];m4ar_expect(is_array($child),'TASK78_REAL_EXISTING_CHILD_FOR_RECEIVE');$request['child_order_id']=(int)$child['id'];
  $request['selection']=['mode'=>'ranges','ranges'=>[]];foreach($parts as$p)$request['selection']['ranges'][]=['source_item_id'=>$p['source_item_id'],'unit_start'=>$p['unit_start'],'unit_end'=>$p['unit_end']];
  $target=$model['ship_operation'];}
 $childId=(int)$child['id'];$newRanges=m4ar_new_rows($before['rows'][$ranges],$after['rows'][$ranges]);m4ar_expect(count($newRanges)===count($parts),'TASK78_S2_EXACT_ACTUAL_RANGES');
 $refs=[];$digestParts=[];foreach($plan['allocations']as$i=>$a){$found=array_values(array_filter($newRanges,static fn(array $r):bool=>(int)$r['source_item_id']===$a['source_item_id']));
  m4ar_expect(count($found)===1,'TASK78_ONE_TRUE_RANGE_PER_SELECTED_ITEM');$range=$found[0];$parent=null;
  if(!$ship){$parents=array_values(array_filter($model['ship_ranges'],static fn(array $r):bool=>(int)$r['source_item_id']===$a['source_item_id']));m4ar_expect(count($parents)===1,'TASK78_RECEIVE_ORIGINAL_SHIP_PARENT');$parent=(string)$parents[0]['id'];}
  $childItem=m4ar_one($after['rows'][$items],'id',$range['child_item_id']);m4ar_expect((int)$childItem['oid']===$childId&&(int)$childItem['product_id']===$a['product_id'],'TASK78_RANGE_TRUE_CHILD_ITEM');
  $values=['id'=>$range['id'],'operation_id'=>$id,'operation_action'=>$action,'root_order_id'=>(int)$model['root']['id'],'child_order_id'=>$childId,
   'uid'=>(int)$model['root']['uid'],'mer_id'=>(int)$model['root']['mer_id'],'source_item_id'=>$a['source_item_id'],'child_item_id'=>(int)$childItem['id'],
   'reserve_id'=>$a['reserve_id'],'source_ship_range_id'=>$parent,'source_range_action'=>$ship?null:'ship'];
  foreach(['product_id','sku_id','sku_type','sku_unique','cart_ref','original_quantity','unit_start','unit_end','quantity','unit_price','price_type',
   'goods','postage','origin_postage','payable','cost','base_goods','discount']as$k)$values[$k]=$a[$k];
  m4fn_expected_row($before['database']['tables'][$ranges]['metadata'],$range,$values);$digestParts[]=$a+['source_ship_range_id'=>$parent];
  $refs[]=['range_id'=>(string)$range['id'],'source_item_id'=>$a['source_item_id'],'child_item_id'=>(int)$childItem['id'],'source_ship_range_id'=>$parent,
   'unit_start'=>$a['unit_start'],'unit_end'=>$a['unit_end']];}
 if($ship){$model['shipped']=array_merge($model['shipped'],$parts);$model['ship_ranges']=$newRanges;$model['ship_operation']=$id;}
 else $model['received']=array_merge($model['received'],$parts);$summary=m4ar_summary($model);$aftersale=m4ar_aftersale($model);
 $public=['schema'=>2,'operation_id'=>$id,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'root_order_no'=>$model['root']['order_id'],
 'child_order_id'=>$childId,'child_order_no'=>$child['order_id'],'quantity'=>$plan['totals']['quantity'],'created_at'=>$at,'committed_at'=>$commit,
 'shipped_at'=>$ship?$at:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$at,'fulfillment_summary'=>$summary,'aftersale'=>$aftersale];
 m4c_entry_same($reply['json']['data'],$public);
 $internal=['schema'=>2,'operation_id'=>$id,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'root_order_no'=>$model['root']['order_id'],
 'child_order_id'=>$childId,'child_order_no'=>$child['order_id'],'target_operation_id'=>$target,'actor_type'=>$ship?'employee':'customer','actor_id'=>$actor['id'],
 'created_at'=>$at,'committed_at'=>$commit,'shipped_at'=>$ship?$at:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$at,
 'plan'=>$plan,'ranges'=>$refs,'summary'=>$summary,'public_result'=>$public,'aftersale_cut_id'=>$model['last_action'],'aftersale'=>$aftersale];
 $base=['schema'=>1,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'child_order_id'=>$childId,
 'source_snapshot_sha256'=>$model['source']['fingerprint'],'target_operation_id'=>$target,'allocations'=>$digestParts];
 $values=['id'=>$raw['id'],'contract_version'=>2,'root_order_id'=>(int)$model['root']['id'],'child_order_id'=>$childId,'uid'=>(int)$model['root']['uid'],
 'mer_id'=>(int)$model['root']['mer_id'],'action'=>$action,'idem_key'=>$body['idem_key'],'request_digest'=>hash('sha256',m5pcp_canonical($request),true),
 'source_snapshot_digest'=>hex2bin($model['source']['fingerprint']),'allocation_digest'=>hash('sha256',m5pcp_canonical(['schema'=>2,'base'=>$base,
 'aftersale_cut_id'=>$model['last_action'],'aftersale'=>$aftersale]),true),'request_payload'=>['json'=>$request],'result_payload'=>['json'=>$internal],
 'state'=>1,'ship_child_order_id'=>$ship?$childId:null,'target_operation_id'=>$target,'target_action'=>$ship?null:'ship','actor_type'=>$ship?'employee':'customer',
 'actor_id'=>$actor['id'],'created_at'=>$at,'committed_at'=>$commit,'shipped_at'=>$ship?$at:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$at,
 'aftersale_cut_id'=>$model['last_action']];m4fn_expected_row($before['database']['tables'][$ops]['metadata'],$raw,$values);
 $rows[$ops]=array_merge($before['rows'][$ops],[$raw]);$counts[$ops]=1;$rows[$ranges]=array_merge($before['rows'][$ranges],$newRanges);$counts[$ranges]=count($newRanges);
 $orderRows=array_merge($before['rows'][$orders],$newChildren);$root=m4ar_one($orderRows,'id',$model['root']['id']);$want=$root;
 $want['status']=$summary['to_ship']>0?0:($summary['to_receive']>0?1:2);$orderRows=m4ar_replaced($orderRows,'id',$root['id'],$want);
 $wantChild=$child;$wantChild['status']=$summary['to_receive']===0?2:1;$orderRows=m4ar_replaced($orderRows,'id',$childId,$wantChild);$rows[$orders]=$orderRows;
 if((int)$root['status']!==$want['status']){$newLogs=m4ar_new_rows($before['rows']['eb_store_order_status'],$after['rows']['eb_store_order_status']);m4ar_expect(count($newLogs)===1,'TASK78_S2_ONE_REAL_STATUS_TRANSITION');
  m4fn_expected_row($before['database']['tables']['eb_store_order_status']['metadata'],$newLogs[0],['oid'=>(int)$root['id'],
  'change_type'=>$ship?'marketplace_fulfillment_ship':'marketplace_fulfillment_receive','change_message'=>$ship?'多商户履约发货状态更新':'多商户履约收货状态更新','change_time'=>$at]);
  $rows['eb_store_order_status']=array_merge($before['rows']['eb_store_order_status'],$newLogs);$counts['eb_store_order_status']=1;}
 m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$rows);m4ar_database_delta($before,$after,$rows,$counts);
 $changes=$ship?m4ar_sequence_delta($before,$after,$child['order_id']):[];m4ar_redis_delta($before,$after,$changes);
 m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK78_S2_NO_INVENTORY_PAYMENT_OR_FILE_MUTATION');
 $model['root']=$want;$model['child']=$wantChild;$model['last_operation']=$id;$model['operations'][]=$raw;
 if($ship){$model['sequence_barriers'][]=m4ar_settle_sequence($db,$child['order_id']);$after=m4ar_snapshot($db);}
 $replayed=m4ar_json('POST',$path,$actor,$body,$operationId,'replay');m4c_entry_same($replayed['json']['data'],$public);$last=m4ar_snapshot($db);$rr=[];m4ar_auth_delta($after,$last,$actor,$replayed['actual_interval'],$rr);
 m4ar_database_delta($after,$last,$rr);m4ar_redis_delta($after,$last);m4ar_expect($after['private']===$last['private']&&$after['public']===$last['public'],'TASK78_S2_SAME_BODY_REPLAY_FILES_STABLE');
 ++$model['replays'];return $public;
}

function m4ar_package(array $model,array $snapshot,bool $detail):array
{
 $child=$model['child'];m4ar_expect(is_array($child),'TASK78_REAL_PACKAGE_MODEL');$summary=m4ar_summary($model);$items=[];
 foreach($model['ship_ranges']as$range){$source=$model['source']['lines'][(int)$range['source_item_id']];$display=[];
  foreach($model['source']['display']as$d)if($d['id']===(int)$range['source_item_id'])$display=$d;
  $item=$display;$item['id']=(int)$range['child_item_id'];$item['quantity']=(int)$range['quantity'];$item['line_price']=$range['goods'];$item['postage_price']=$range['postage'];$items[]=$item;}
 usort($items,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);$ship=m4ar_one($snapshot['rows']['eb_marketplace_fulfillment_operation'],'id',$model['ship_operation']);
 $last=null;foreach($model['operations']as$op)if($op['action']==='receive')$last=max($last??0,(int)$op['received_at']);$q=2;$r=$summary['received'];$t=$summary['returned_accepted'];
 $as=m4ar_aftersale($model);$as['pending_cancel_quantity']=0;$as['fulfillment_completed_at']=$summary['closed']?($model['completed_at']??null):null;
 $out=['schema'=>2,'scope'=>'marketplace_fulfillment_child_aftersale_v1','id'=>(int)$child['id'],'order_id'=>$child['order_id'],
 'root_order_id'=>(int)$model['root']['id'],'root_order_no'=>$model['root']['order_id'],'marketplace_trade_version'=>1,
 'merchant_name_snapshot'=>$child['merchant_name_snapshot'],'shipping_type'=>1,'carrier_code'=>$child['delivery_code'],'carrier_name'=>$child['delivery_name'],
 'tracking_no'=>$child['delivery_id'],'shipped_at'=>(int)$ship['shipped_at'],'receipt_due_at'=>0,'last_received_at'=>$last,'received_at'=>$r===$q?$last:null,
 'shipping_state'=>'shipped','receipt_state'=>$r===0?'pending':($r===$q?'received':'partial'),'closed'=>$summary['to_receive']===0,'original'=>$q,'shipped'=>$q,
 'received'=>$r,'cancelled_unshipped'=>0,'returned_accepted'=>$t,'to_ship'=>0,'to_receive'=>$summary['to_receive'],'items'=>$items,
 'payment_owner_order_id'=>(int)$model['root']['id'],'paid'=>1,'pay_type'=>$model['root']['pay_type'],'pay_time'=>(int)$model['root']['pay_time'],
 'pay_type_name'=>'测试支付','aftersale'=>$as];
 if($detail){$out['recipient']=[];foreach(['real_name','user_phone','user_address']as$k)$out['recipient'][$k]=$child[$k];$out['mark']=$child['mark'];
  $out['custom_form']=json_decode($child['custom_form'],true,64,JSON_THROW_ON_ERROR);}
 return $out;
}
function m4ar_quote_returns(array $model):array
{
 $out=[];foreach($model['ship_ranges']as$range){$item=(int)$range['child_item_id'];$source=(int)$range['source_item_id'];$free=true;
  foreach($model['returned']as$p)if($p['child_item_id']===$item)$free=false;
  foreach($model['refunds']as$r)if($r['kind']==='return_goods'&&in_array($r['state']['business_state'],['REQUESTED','APPROVED'],true))
   foreach($r['allocations']as$p)if($p['child_item_id']===$item)$free=false;
  $out[]=['item_id'=>$item,'quantity'=>1,'eligible_quantity'=>$free?1:0,'eligible_segments'=>$free?[['first'=>1,'last'=>1]]:[]];}
 usort($out,static fn(array $a,array $b):int=>$a['item_id']<=>$b['item_id']);return ['schema'=>1,'coordinate'=>'child_item_ordinal','items'=>$out];
}
function m4ar_quote(array $model,array $snapshot,int $page,int $limit):array
{
 $as=m4ar_aftersale($model);$items=[];$occupied=[];foreach($model['refunds']as$r)if($r['state']['held_amount']!=='0.00'||$r['state']['confirmed_amount']!=='0.00')
  foreach($r['allocations']as$p)$occupied[$p['source_item_id']]=($occupied[$p['source_item_id']]??0)+m4ar_cents($p['requested_amount']);
 $physical=m4ar_units(array_merge($model['shipped'],$model['cancel']));
 foreach($model['source']['display']as$item){$line=$model['source']['lines'][$item['id']];$cancel=0;for($i=0;$i<$line['original_quantity'];$i++)if(!isset($physical[$item['id'].':'.$i]))++$cancel;
  foreach($model['refunds']as$r)if($r['kind']==='cancel_unshipped'&&$r['state']['business_state']==='REQUESTED')foreach($r['allocations']as$p)if($p['source_item_id']===$item['id'])$cancel-=$p['quantity'];
  $items[]=['item'=>$item,'available_cancel_quantity'=>$cancel,'available_money_amount'=>m4ar_decimal(m4ar_cents($line['goods'])+m4ar_cents($line['postage'])-($occupied[$item['id']]??0))];}
 $list=[];$count=$model['child']===null?0:1;if($count===1&&($page-1)*$limit===0)$list[]=['package'=>m4ar_package($model,$snapshot,false),'return_selection'=>m4ar_quote_returns($model)];
 return ['schema'=>2,'scope'=>'marketplace_aftersale_quote_v1','root'=>['id'=>(int)$model['root']['id'],'order_no'=>$model['root']['order_id'],
 'merchant_name_snapshot'=>$model['root']['merchant_name_snapshot']],
 'capacity'=>['paid'=>$model['root']['pay_price'],'confirmed'=>'0.00','held'=>$as['held_amount'],'available'=>$as['available_amount'],'currency'=>'CNY'],
 'root_items'=>$items,'packages'=>['page'=>$page,'limit'=>$limit,'count'=>$count,'list'=>$list],'allowed_actions'=>['apply'=>$as['available_amount']!=='0.00']];
}
function m4ar_reads(PDO $db,array $model,array $actors):void
{
 foreach([1,2]as$page){$before=m4ar_snapshot($db);$path='/api/order/refund/cart_info/'.$model['root']['id'].'?contract=2&fulfillment_page='.$page.'&fulfillment_limit=1';
  $reply=m4ar_json('GET',$path,$actors['customer']);m4c_entry_same($reply['json']['data'],m4ar_quote($model,$before,$page,1));m4ar_read_finish($db,$before,$actors['customer'],$reply);}
 foreach($actors as$name=>$actor){if(!in_array($name,['customer','merchant','platform'],true))continue;
  $base=match($name){'customer'=>'/api/order/refund','merchant'=>'/merchantapi/aftersales','platform'=>'/adminapi/marketplace/aftersales'};
  foreach($model['refunds']as$record){$path=$name==='customer'?$base.'/detail/'.$record['number']:$base.'/'.$record['id'];$before=m4ar_snapshot($db);
   $reply=m4ar_json('GET',$path,$actor);m4c_entry_same($reply['json']['data'],m4ar_refund_view($model,$record,$record['state'],$actor,$record['updated_at']));
   $audit=$name==='platform'?['mer_id'=>(int)$model['root']['mer_id'],'action'=>'marketplace-aftersales-read','resource_type'=>'aftersale','resource_id'=>$record['id']]:null;
   m4ar_read_finish($db,$before,$actor,$reply,$audit);}
  $records=array_values($model['refunds']);usort($records,static fn(array $a,array $b):int=>$b['id']<=>$a['id']);$pages=(int)ceil(count($records)/2);
  foreach(array_values(array_unique([1,$pages,$pages+1]))as$page){$path=$name==='customer'?$base.'/list?contract=2&page='.$page.'&limit=2':$base.'?page='.$page.'&limit=2';
   $before=m4ar_snapshot($db);$reply=m4ar_json('GET',$path,$actor);$list=[];foreach(array_slice($records,($page-1)*2,2)as$r)$list[]=m4ar_refund_view($model,$r,$r['state'],$actor,$r['updated_at']);
   m4c_entry_same($reply['json']['data'],['schema'=>2,'page'=>$page,'limit'=>2,'count'=>count($records),'list'=>$list]);
   $audit=$name==='platform'?['global'=>true,'rule'=>'marketplace/aftersales']:null;m4ar_read_finish($db,$before,$actor,$reply,$audit);}
 }
 if($model['child']!==null){$before=m4ar_snapshot($db);$reply=m4ar_json('GET','/merchantapi/packages/'.$model['child']['id'],$actors['merchant']);
  m4c_entry_same($reply['json']['data'],['package'=>m4ar_package($model,$before,true)]);m4ar_read_finish($db,$before,$actors['merchant'],$reply);
  $before=m4ar_snapshot($db);$reply=m4ar_json('GET','/api/order/fulfillment/packages/'.$model['child']['order_id'],$actors['customer']);$selection=[];
  foreach($model['ship_ranges']as$p){$id=(int)$p['child_item_id'];$source=(int)$p['source_item_id'];$received=0;$returned=0;$paused=0;
   foreach($model['received']as$r)if($r['source_item_id']===$source)$received+=$r['unit_end']-$r['unit_start'];
   foreach($model['returned']as$r)if($r['child_item_id']===$id)$returned+=$r['unit_end']-$r['unit_start'];
   foreach($model['refunds']as$r)if($r['kind']==='return_goods'&&in_array($r['state']['business_state'],['REQUESTED','APPROVED'],true))
    foreach($r['allocations']as$a)if($a['child_item_id']===$id&&$received===0&&$returned===0)$paused=1;
   $overlap=min($received,$returned);$eligible=1-$received-$returned+$overlap-$paused;
   $selection[]=['item_id'=>$id,'quantity'=>1,'received_quantity'=>$received,'returned_quantity'=>$returned,'returned_after_receipt'=>$overlap,
    'paused_quantity'=>$paused,'eligible_quantity'=>$eligible,'eligible_segments'=>$eligible===1?[['first'=>1,'last'=>1]]:[]];}
  usort($selection,static fn(array $a,array $b):int=>$a['item_id']<=>$b['item_id']);$eligible=array_sum(array_column($selection,'eligible_quantity'));
  m4c_entry_same($reply['json']['data'],['package'=>m4ar_package($model,$before,true),'allowed_actions'=>['receive'=>$eligible>0],
   'receipt_selection'=>['schema'=>2,'coordinate'=>'child_item_ordinal','items'=>$selection]]);m4ar_read_finish($db,$before,$actors['customer'],$reply);}
}

function m4ar_png():string
{
 // Deterministic ordinary 1x1 RGBA fixture from this frozen source, decoded by real GD/finfo before transport.
 $chunk=static function(string $kind,string $payload):string{return pack('N',strlen($payload)).$kind.$payload.hex2bin(hash('crc32b',$kind.$payload));};
 $bytes="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0)).$chunk('IDAT',gzcompress("\x00\x44\x88\xcc\xff",9)).$chunk('IEND','');
 m4ar_expect(function_exists('fsync')&&function_exists('imagecreatefromstring')&&class_exists(finfo::class),'TASK78_REAL_PHP_IMAGE_STORAGE_CAPABILITIES');
 $image=imagecreatefromstring($bytes);$size=getimagesizefromstring($bytes);m4ar_expect($image instanceof GdImage&&imagesx($image)===1&&imagesy($image)===1
  &&$size[0]===1&&$size[1]===1&&$size[2]===IMAGETYPE_PNG&&(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)==='image/png','TASK78_REAL_NORMAL_PNG_DECODE');
 imagedestroy($image);return $bytes;
}
function m4ar_file_view(array $row):array
{return ['schema'=>1,'scope'=>'marketplace_aftersale_file_v1','id'=>(string)$row['id'],'root_order_id'=>(int)$row['root_order_id'],'media_type'=>'image/png',
 'byte_size'=>(int)$row['byte_size'],'width'=>1,'height'=>1,'sha256'=>bin2hex($row['content_sha256']),'created_at'=>(int)$row['created_at'],'claim_expires_at'=>(int)$row['claim_expires_at']];}
function m4ar_private_upload(PDO $db,array $model,array $actor,string $bytes):array
{
 m4ar_phase('real_nonempty_private_multipart_upload');$before=m4ar_snapshot($db);$upload='normal-private:'.bin2hex(random_bytes(16));$fields=['schema'=>'1','upload_key'=>$upload];
 $path='/api/order/refund/files/'.$model['root']['id'];$reply=m4ar_multipart($path,$actor,$fields,$bytes);$after=m4ar_snapshot($db);
 $new=m4ar_new_rows($before['rows']['eb_marketplace_aftersale_file'],$after['rows']['eb_marketplace_aftersale_file']);m4ar_expect(count($new)===1,'TASK78_REAL_ONE_STAGED_FILE');$row=$new[0];
 m4ar_expect(preg_match('/\A[a-f0-9]{64}\z/D',$row['object_key'])===1,'TASK78_NATIVE_RANDOM_OBJECT_KEY');$at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$row['created_at']);
 m4fn_expected_row($before['database']['tables']['eb_marketplace_aftersale_file']['metadata'],$row,['id'=>$row['id'],'uid'=>$actor['id'],'root_order_id'=>(int)$model['root']['id'],
 'mer_id'=>(int)$model['root']['mer_id'],'refund_id'=>null,'image_position'=>null,'object_key'=>$row['object_key'],'media_type'=>'image/png','byte_size'=>strlen($bytes),
 'width'=>1,'height'=>1,'content_sha256'=>hash('sha256',$bytes,true),'upload_key'=>$upload,'state'=>'STAGED','created_at'=>$at,'claim_expires_at'=>$at+86400,'claimed_at'=>null,'purged_at'=>null]);
 $expected=[];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$expected);$expected['eb_marketplace_aftersale_file']=array_merge($before['rows']['eb_marketplace_aftersale_file'],[$row]);
 m4ar_database_delta($before,$after,$expected,['eb_marketplace_aftersale_file'=>1]);m4ar_redis_delta($before,$after);m4c_entry_same($reply['json']['data'],m4ar_file_view($row));
 $relative=$model['root']['id'].'/'.$row['object_key'].'/content';$want=$before['private'];$want[$relative]=['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mode'=>0600,'uid'=>posix_geteuid(),'nlink'=>1];ksort($want,SORT_STRING);
 m4ar_expect($after['private']===$want&&file_get_contents(M4AR_PRIVATE.'/'.$relative)===$bytes&&$before['public']===$after['public'],
 'TASK78_ONE_REAL_PRIVATE_OBJECT_FULL_BYTES_AND_MODE');
 foreach([M4AR_PRIVATE.'/'.$model['root']['id'],M4AR_PRIVATE.'/'.$model['root']['id'].'/'.$row['object_key']]as$p){$stat=lstat($p);
  m4ar_expect(is_array($stat)&&is_dir($p)&&!is_link($p)&&realpath($p)===$p&&($stat['mode']&0777)===0700&&$stat['uid']===posix_geteuid(),
 'TASK78_TRUE_PRIVATE_DIRECTORY_0700_OWNER');}
 $again=m4ar_multipart($path,$actor,$fields,$bytes);m4c_entry_same($again['json']['data'],m4ar_file_view($row));$last=m4ar_snapshot($db);$expected=[];
 m4ar_auth_delta($after,$last,$actor,$again['actual_interval'],$expected);m4ar_database_delta($after,$last,$expected);m4ar_redis_delta($after,$last);
 m4ar_expect($after['private']===$last['private']&&$after['public']===$last['public'],'TASK78_SAME_UPLOAD_KEY_BYTES_NO_SECOND_OBJECT_OR_RECORD');return $row;
}
function m4ar_tickets(PDO $db,array $model,array $actors,array $file,string $bytes,int $refundId):array
{
 $result=[];foreach(['customer','merchant','platform']as$name){$actor=$actors[$name];$base=match($name){'customer'=>'/api/order/refund/files/','merchant'=>'/merchantapi/aftersale-files/','platform'=>'/adminapi/marketplace/aftersale-files/'};
  $before=m4ar_snapshot($db);$reply=m4ar_json('POST',$base.$file['id'].'/read-ticket',$actor,['schema'=>1],match($name){'customer'=>'file-ticket-customer','merchant'=>'file-ticket-merchant','platform'=>'file-ticket-platform'});$after=m4ar_snapshot($db);$data=$reply['json']['data'];
  m4c_entry_keys($data,['schema','scope','file','refund_id','read_ticket','issued_at','expires_at']);$ticket=$data['read_ticket'];$issued=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$data['issued_at']);
  m4ar_expect($data['schema']===1&&$data['scope']==='marketplace_aftersale_read_ticket_v1'&&$data['refund_id']===$refundId&&is_string($ticket)
   &&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$ticket)===1&&$data['expires_at']===$issued+300,'TASK78_REAL_NORMAL_TICKET_300_SECONDS');
  m4c_entry_same($data['file'],m4ar_file_view($file));$key='c:mp_aftersale_read:'.hash('sha256',$ticket);$raw=audit_redis()->get($key);
  $wanted=['schema'=>1,'app'=>$actor['app'],'actor_type'=>match($actor['app']){'api'=>'customer','merchantapi'=>'employee','adminapi'=>'admin'},
   'actor_id'=>$actor['id'],'session_sha256'=>hash('sha256',$actor['token']),'uid'=>(int)$model['root']['uid'],'mer_id'=>(int)$model['root']['mer_id'],
   'root_order_id'=>(int)$model['root']['id'],'refund_id'=>$refundId,'file_id'=>(string)$file['id'],'content_sha256'=>hash('sha256',$bytes),
   'issued_at'=>$issued,'expires_at'=>$issued+300,'purpose'=>'aftersale_file_read'];
  m4ar_expect(!isset($before['redis']['keys'][hash('sha256',$key)])&&is_string($raw)&&$raw===m5pcp_canonical($wanted)
   &&audit_redis()->ttl($key)>=1&&audit_redis()->ttl($key)<=300,'TASK78_NATIVE_NEW_NX_TICKET_EXACT_FULL_BOUND_PAYLOAD');
  $expected=[];$counts=[];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$expected);if($name==='platform')m4ar_audit_delta($before,$after,$actor,
   ['mer_id'=>(int)$model['root']['mer_id'],'action'=>'marketplace-aftersale-files-ticket','resource_type'=>'aftersale-file','resource_id'=>(string)$file['id']],$reply['actual_interval'],$expected,$counts);
  m4ar_database_delta($before,$after,$expected,$counts);$entry=$after['redis']['keys'][hash('sha256',$key)];m4ar_expect($entry['type']==='string'
   &&$entry['expires']>=$issued*1000+300000&&$entry['expires']<=($issued+1)*1000+300000,'TASK78_TICKET_REAL_ABSOLUTE_REDIS_DEADLINE');
  m4ar_redis_delta($before,$after,[$key=>$entry]+m4ar_cache_deletes($before,$after,$actor));m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],
   'TASK78_TICKET_PUBLISH_NO_FILE_MUTATION');
  $reply=m4ar_transport('GET',$base.$file['id'].'/content',$actor['token'],null,null,$ticket);$last=m4ar_snapshot($db);
  m4ar_expect($reply['raw']===$bytes&&($reply['headers']['content-type']??null)==='image/png'
   &&($reply['headers']['content-length']??null)===(string)strlen($bytes)&&($reply['headers']['cache-control']??null)==='private, no-store, max-age=0'
   &&($reply['headers']['x-content-type-options']??null)==='nosniff'&&!audit_redis()->exists($key),'TASK78_REAL_AUTHENTICATED_BYTES_AND_CONSUMED_TICKET');
  $expected=[];$counts=[];m4ar_auth_delta($after,$last,$actor,$reply['actual_interval'],$expected);if($name==='platform')m4ar_audit_delta($after,$last,$actor,
   ['mer_id'=>(int)$model['root']['mer_id'],'action'=>'marketplace-aftersale-files-read','resource_type'=>'aftersale-file','resource_id'=>(string)$file['id']],$reply['actual_interval'],$expected,$counts);
  m4ar_database_delta($after,$last,$expected,$counts);m4ar_redis_delta($after,$last,[$key=>null]+m4ar_cache_deletes($after,$last,$actor));
  m4ar_expect($after['private']===$last['private']&&$after['public']===$last['public'],'TASK78_CLAIMED_OBJECT_RETAINED_AFTER_TICKET_READ');
  $result[$name]=['ticket_created'=>true,'content_http'=>200,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'consumed'=>true];unset($ticket,$raw,$wanted);}
 return $result;
}

function m4ar_show_url(string $reference,string $site):string
{return str_starts_with($reference,'http')||str_starts_with($reference,'//')?$reference:$site.str_replace('\\','/',$reference);}
function m4ar_review_view(array $row,int $sourceItem,string $site):array
{
 $nickname=mb_strlen($row['nickname'],'UTF-8')===1?'*':mb_substr($row['nickname'],0,1,'UTF-8').'**'.mb_substr($row['nickname'],-1,1,'UTF-8');
 $pics=json_decode($row['pics'],true,16,JSON_THROW_ON_ERROR);$display=[];foreach($pics as$p)$display[]=m4ar_show_url($p,$site);
 return ['id'=>(int)$row['id'],'root_order_id'=>(int)$row['oid'],'source_item_id'=>$sourceItem,'product_id'=>(int)$row['product_id'],
 'product_score'=>(int)$row['product_score'],'service_score'=>(int)$row['service_score'],'comment'=>$row['comment'],'pics'=>$display,'add_time'=>(int)$row['add_time'],
 'merchant_reply_content'=>$row['merchant_reply_content'],'merchant_reply_time'=>(int)$row['merchant_reply_time'],'is_reply'=>(int)$row['is_reply'],
 'status'=>(int)$row['status'],'nickname'=>$nickname,'avatar'=>$row['avatar']===''?'':m4ar_show_url($row['avatar'],$site),'suk'=>$row['suk']];
}
function m4ar_review_quote(array $root,array $item,?array $review,string $site,bool $eligible):array
{
 $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$p=$cart['productInfo'];$sku=$p['attrInfo'];
 $public=['id'=>(int)$item['id'],'product_id'=>(int)$item['product_id'],'product_name'=>$p['store_name'],'sku_name'=>$sku['suk'],
 'image'=>$sku['image']!==''?$sku['image']:$p['image'],'quantity'=>(int)$item['cart_num'],'unit_price'=>$cart['truePrice'],
 'line_price'=>$cart['sum_true_price'],'postage_price'=>$cart['postage_price']];
 return ['schema'=>1,'scope'=>'marketplace_order_review_v1','root_order_id'=>(int)$root['id'],'source_item_id'=>(int)$item['id'],'item'=>$public,
 'review'=>$review===null?null:m4ar_review_view($review,(int)$item['id'],$site),'allowed_actions'=>['submit'=>$review===null&&$eligible]];
}
function m4ar_public_upload(PDO $db,array $actor,string $bytes):array
{
 m4ar_phase('real_public_nonempty_image_upload');m4ar_expect((int)sys_config('upload_type',1)===1,'TASK8_TRUE_LOCAL_PUBLIC_UPLOAD_PRODUCER');
 $before=m4ar_snapshot($db);$tag='c:CRMEB:'.md5('crmeb');$GLOBALS['m4ar_tag_members'][$tag]=audit_redis()->sMembers($tag);
 $counter='c:start_uploads_'.$actor['id'];$counterType=m4tr_redis_type(audit_redis(),$counter);
 m4ar_expect(in_array($counterType,['none','string'],true),'TASK8_NATIVE_PUBLIC_UPLOAD_COUNTER_TYPE_BEFORE');
 $oldRaw=audit_redis()->get($counter);
 m4ar_expect(($counterType==='none'&&$oldRaw===false)||($counterType==='string'&&is_string($oldRaw)
  &&preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$oldRaw)===1),'TASK8_CANONICAL_PUBLIC_UPLOAD_COUNTER_WIRE_BEFORE');
 $old=$oldRaw===false?0:(int)$oldRaw;
 m4ar_expect($oldRaw===false||$oldRaw===m4tr_cache_wire($old),'TASK8_PUBLIC_UPLOAD_COUNTER_EXACT_WIRE_BEFORE');
 m4ar_expect(is_int($old)&&$old>=0&&$old<100,'TASK8_TRUE_PUBLIC_UPLOAD_COUNTER_BEFORE');
 $reply=m4ar_multipart('/api/upload/image',$actor,['filename'=>'file'],$bytes);$after=m4ar_snapshot($db);$data=$reply['json']['data'];
 m4c_entry_keys($data,['name','url','attachment_ref']);$ref=$data['attachment_ref'];m4ar_expect(is_string($ref)&&strlen($ref)<=200
  &&preg_match('~\A/uploads/store/comment/[A-Za-z0-9/._-]+\.png\z~D',$ref)===1&&!str_contains($ref,'..'),'TASK8_REAL_LOCAL_RAW_ATTACHMENT_REFERENCE');
 $new=m4ar_new_rows($before['rows']['eb_system_attachment'],$after['rows']['eb_system_attachment']);m4ar_expect(count($new)===1,'TASK8_ONE_TRUE_MODULE3_ATTACHMENT');$row=$new[0];
 $rows=m5pcp_rows($db,'SELECT * FROM eb_system_attachment WHERE BINARY att_dir=BINARY ? AND module_type=3',[$ref]);m4ar_expect(count($rows)===1&&$rows[0]===$row,
 'TASK8_RAW_REFERENCE_BINARY_CORRESPONDENCE');$name=basename($ref);m4ar_expect($data['name']===$name&&$data['url']==='http://audit-app:8000'.$ref,'TASK8_PUBLIC_URL_FROM_REAL_REQUEST_ORIGIN');
 m4ar_expect((string)$row['att_id']===m5pcp_u64($before['database']['tables']['eb_system_attachment']['metadata']['table']['AUTO_INCREMENT']),
 'TASK8_REAL_ATTACHMENT_NATURAL_ID');$at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$row['time']);
 m4fn_expected_row($before['database']['tables']['eb_system_attachment']['metadata'],$row,['att_id'=>$row['att_id'],'name'=>$name,'att_dir'=>$ref,'satt_dir'=>$ref,
 'att_size'=>(string)strlen($bytes),'att_type'=>'image/png','pid'=>1,'time'=>$at,'image_type'=>1,'module_type'=>3,'real_name'=>$name,'scan_token'=>'','type'=>0]);
 $expected=[];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$expected);$expected['eb_system_attachment']=array_merge($before['rows']['eb_system_attachment'],[$row]);
 m4ar_database_delta($before,$after,$expected,['eb_system_attachment'=>1]);m4ar_expect(audit_redis()->get($counter)===m4tr_cache_wire($old+1),'TASK8_EXACT_REAL_PUBLIC_UPLOAD_COUNTER');
 $entry=$after['redis']['keys'][hash('sha256',$counter)];m4ar_expect($entry['type']==='string'&&$entry['expires']>=$reply['actual_interval'][0]*1000+86400000
  &&$entry['expires']<=($reply['actual_interval'][1]+1)*1000+86400000,'TASK8_REAL_PUBLIC_UPLOAD_COUNTER_DEADLINE');
 $changes=[$counter=>$entry]+m4ar_tag_change($before,$after,$tag,[$counter]);m4ar_redis_delta($before,$after,$changes);
 $file='/workspace/crmeb/public'.$ref;clearstatcache(true,$file);$stat=lstat($file);
 m4ar_expect(is_array($stat)&&is_file($file)&&!is_link($file)&&realpath($file)===$file&&$stat['size']===strlen($bytes)
  &&$stat['nlink']===1&&$stat['uid']===posix_geteuid()&&file_get_contents($file)===$bytes,'TASK8_REAL_PUBLIC_LOCAL_FILE_FULL_BYTES');
 $newFiles=$after['public'];$relative=ltrim($ref,'/');m4ar_expect(isset($newFiles[$relative])&&!isset($before['public'][$relative]),'TASK8_TRUE_NEW_PUBLIC_PATH');
 unset($newFiles[$relative]);m4ar_expect($newFiles===$before['public']&&$after['private']===$before['private'],'TASK8_EXACT_PUBLIC_FILE_DELTA_ONLY');
 return ['row'=>$row,'reference'=>$ref,'display_url'=>$data['url']];
}
function m4ar_review_reads(PDO $db,array $root,array $item,array $review,array $actors,string $site):void
{
 $path='/api/order/reviews/'.$root['id'].'/'.$item['id'];$before=m4ar_snapshot($db);$reply=m4ar_json('GET',$path,$actors['customer']);
 m4c_entry_same($reply['json']['data'],m4ar_review_quote($root,$item,$review,$site,true));m4ar_read_finish($db,$before,$actors['customer'],$reply);
 foreach(['merchant','platform']as$name){$actor=$actors[$name];$base=$name==='merchant'?'/merchantapi/reviews':'/adminapi/marketplace/reviews';
  $before=m4ar_snapshot($db);$reply=m4ar_json('GET',$base.'/'.$review['id'],$actor);m4c_entry_same($reply['json']['data'],m4ar_review_view($review,(int)$item['id'],$site));
  m4ar_read_finish($db,$before,$actor,$reply,$name==='platform'?['mer_id'=>(int)$root['mer_id'],'action'=>'marketplace-reviews-read','resource_type'=>'review','resource_id'=>$review['id']]:null);
  foreach([1,2]as$page){$before=m4ar_snapshot($db);$reply=m4ar_json('GET',$base.'?page='.$page.'&limit=1&product_id='.$item['product_id'],$actor);
   $expected=['page'=>$page,'limit'=>1,'count'=>1,'list'=>$page===1?[m4ar_review_view($review,(int)$item['id'],$site)]:[]];m4c_entry_same($reply['json']['data'],$expected);
   m4ar_read_finish($db,$before,$actor,$reply,$name==='platform'?['global'=>true,'rule'=>'marketplace/reviews']:null);}}
}
function m4ar_pending_public_review(PDO $db,array $item,array $review):array
{
 $native=m4ar_snapshot($db);$saved=m4ar_one($native['rows']['eb_store_product_reply'],'id',$review['id']);
 m4ar_expect($saved===$review&&(int)$saved['status']===0&&(int)$saved['is_del']===0
  &&(int)$saved['product_id']===(int)$item['product_id'],'TASK8_PENDING_EXACT_ORIGINAL_STATUS_ZERO_NATIVE_ROW');
 $qualifying=[];
 foreach($native['rows']['eb_store_product_reply']as$row)if((int)$row['product_id']===(int)$item['product_id']&&(int)$row['is_del']===0&&(int)$row['status']===1)$qualifying[]=$row;
 m4ar_expect($qualifying===[],'TASK8_PENDING_INDEPENDENT_COMPLETE_NATIVE_PUBLIC_PREDICATE_EMPTY');
 $before=$native;$response=m4ar_json('GET','/api/reply/list/'.$item['product_id'].'?type=0&page=1&limit=1',[]);
 m4ar_expect($response['json']['data']===[],'TASK8_PENDING_REAL_ANONYMOUS_PUBLIC_LIST_EMPTY');
 m4ar_zero($before,m4ar_snapshot($db));
 unset($native,$before);
 $before=m4ar_snapshot($db);$response=m4ar_json('GET','/api/reply/config/'.$item['product_id'],[]);
 $expected=['sum_count'=>0,'good_count'=>0,'in_count'=>0,'poor_count'=>0,'reply_chance'=>100,'reply_star'=>5];
 m4c_entry_keys($response['json']['data'],array_keys($expected));
 foreach($expected as$key=>$value)m4ar_expect(is_int($response['json']['data'][$key])&&$response['json']['data'][$key]===$value,
  'TASK8_PENDING_REAL_ANONYMOUS_ZERO_COUNTS_AND_DEFAULT_INTEGER_RATING');
 m4ar_zero($before,m4ar_snapshot($db));
 return ['review_id'=>(int)$saved['id'],'status'=>(int)$saved['status'],'native_predicate_count'=>count($qualifying),
  'list_requests'=>1,'count_requests'=>1,'anonymous_requests'=>2,'full_native_zero_diff'=>true];
}

/** Real named review upload, independently observed as a complete customer34 row. */
function m4ar_customer_review_upload(PDO $db,array $root,array $item,array $actor,string $bytes):array
{
 m4ar_phase('real_customer_review_nonempty_image_upload');
 m4ar_expect((int)sys_config('upload_type',1)===1,'TASK8_TRUE_LOCAL_CUSTOMER_REVIEW_UPLOAD_PRODUCER');
 $uid=m5pcp_int($actor['id'],1);$rootId=m5pcp_int($root['id'],1,4294967295);$itemId=m5pcp_int($item['id'],1);
 m4c_entry_keys($item,['id','oid','uid','mer_id','cart_id','old_cart_id','product_id','cart_num','refund_num','surplus_num','split_status','cart_info','unique']);
 m4ar_expect(($actor['app']??null)==='api'&&m5pcp_int($root['uid'],1)===$uid&&m5pcp_int($item['uid'],1)===$uid
  &&m5pcp_int($item['oid'],1,4294967295)===$rootId
  &&m5pcp_int($root['mer_id'],1,4294967295)===m5pcp_int($item['mer_id'],1,4294967295)
  &&m5pcp_int($root['pid'],0)===0&&m5pcp_int($root['marketplace_trade_version'],1,1)===1
  &&m5pcp_int($root['paid'],1,1)===1&&m5pcp_int($root['status'],2,2)===2,
  'TASK8_REAL_CUSTOMER_ORIGINAL_ROOT_FULL_ITEM_CORRESPONDENCE');
 $before=m4ar_snapshot($db);$table='eb_marketplace_customer_asset';
 m4ar_expect(m4ar_one($before['rows']['eb_store_order'],'id',$rootId)===$root
  &&m4ar_one($before['rows']['eb_store_order_cart_info'],'id',$itemId)===$item,
  'TASK8_COMPLETE_ORIGINAL_ROOT_AND_NATIVE13_ITEM_BEFORE_UPLOAD');
 $metadata=$before['database']['tables'][$table]['metadata'];$id=m5pcp_u64($metadata['table']['AUTO_INCREMENT']);
 $tag='c:CRMEB:'.md5('crmeb');$GLOBALS['m4ar_tag_members'][$tag]=audit_redis()->sMembers($tag);
 $counter='c:start_uploads_'.$actor['id'];$counterType=m4tr_redis_type(audit_redis(),$counter);
 m4ar_expect(in_array($counterType,['none','string'],true),'TASK8_NATIVE_PUBLIC_UPLOAD_COUNTER_TYPE_BEFORE');
 $oldRaw=audit_redis()->get($counter);
 m4ar_expect(($counterType==='none'&&$oldRaw===false)||($counterType==='string'&&is_string($oldRaw)
  &&preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$oldRaw)===1),'TASK8_CANONICAL_PUBLIC_UPLOAD_COUNTER_WIRE_BEFORE');
 $old=$oldRaw===false?0:(int)$oldRaw;
 m4ar_expect($oldRaw===false||$oldRaw===m4tr_cache_wire($old),'TASK8_PUBLIC_UPLOAD_COUNTER_EXACT_WIRE_BEFORE');
 m4ar_expect(is_int($old)&&$old>=0&&$old<100,'TASK8_TRUE_PUBLIC_UPLOAD_COUNTER_BEFORE');
 $path='/api/order/reviews/'.$rootId.'/'.$itemId.'/files';$reply=m4ar_multipart($path,$actor,[],$bytes);
 $after=m4ar_snapshot($db);$data=$reply['json']['data'];m4c_entry_keys($data,['name','url','attachment_ref']);$ref=$data['attachment_ref'];
 m4ar_expect(is_string($ref)&&strlen($ref)<=200
  &&preg_match('~\A/uploads/customer/'.$uid.'/[a-f0-9]{32}/[a-f0-9]{32}\.png\z~D',$ref)===1,
  'TASK8_ACTUAL_CUSTOMER_UID_AND_GENERATED_PNG_REFERENCE');
 $name=basename($ref);m4ar_expect($data['name']===$name&&$data['url']==='http://audit-app:8000'.$ref,
  'TASK8_CUSTOMER_IMAGE_URL_FROM_REAL_REQUEST_ORIGIN');
 $new=m4ar_new_rows($before['rows'][$table],$after['rows'][$table]);m4ar_expect(count($new)===1,'TASK8_ONE_TRUE_CUSTOMER_REVIEW_ASSET34');
 $row=$new[0];$at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$row['created_at']);
 $values=['id'=>$id,'uid'=>$uid,'purpose'=>'review_public_image','state'=>'STAGED','storage_type'=>1,
  'object_key'=>$ref,'thumbnail_key'=>$ref,'name'=>$name,'real_name'=>'normal-evidence.png','media_type'=>'image/png',
  'byte_size'=>(string)strlen($bytes),'width'=>1,'height'=>1,'content_sha256'=>hash('sha256',$bytes,true),'created_at'=>$at,
  'expires_at'=>null,'bound_at'=>null,'retired_at'=>null,'root_order_id'=>$rootId,'order_item_id'=>$itemId,
  'refund_id'=>null,'review_id'=>null,'confirmation_ref'=>null,'form_product_id'=>null,'form_definition_sha256'=>null,
  'form_field_index'=>null,'extract_id'=>null,'agent_apply_id'=>null,'source_product_id'=>null,'source_qrcode_id'=>null,
  'created_by_uid'=>$uid,'created_by_admin_id'=>null,'created_by_job'=>null,'legacy_att_id'=>null];
 m4ar_expect(count($values)===34&&array_keys($metadata['columns'])===array_keys($values),'TASK8_EXACT_CUSTOMER_ASSET_NATIVE34_COLUMNS');
 $row=m4fn_expected_row($metadata,$row,$values);
 $observed=m5pcp_rows($db,'SELECT * FROM eb_marketplace_customer_asset WHERE id=? AND BINARY object_key=BINARY ?',[$id,$ref]);
 m4ar_expect($observed===[$row],'TASK8_CUSTOMER_FULL34_NATURAL_ID_BINARY_REFERENCE_READBACK');
 $expected=[$table=>array_merge($before['rows'][$table],[$row])];m4ar_auth_delta($before,$after,$actor,$reply['actual_interval'],$expected);
 m4ar_database_delta($before,$after,$expected,[$table=>1]);
 m4ar_expect(audit_redis()->get($counter)===m4tr_cache_wire($old+1),'TASK8_EXACT_REAL_PUBLIC_UPLOAD_COUNTER');
 $entry=$after['redis']['keys'][hash('sha256',$counter)];m4ar_expect($entry['type']==='string'&&$entry['expires']>=$reply['actual_interval'][0]*1000+86400000
  &&$entry['expires']<=($reply['actual_interval'][1]+1)*1000+86400000,'TASK8_REAL_PUBLIC_UPLOAD_COUNTER_DEADLINE');
 $changes=[$counter=>$entry]+m4ar_tag_change($before,$after,$tag,[$counter]);m4ar_redis_delta($before,$after,$changes);
 $file='/workspace/crmeb/public'.$ref;clearstatcache(true,$file);$stat=lstat($file);
 m4ar_expect(is_array($stat)&&is_file($file)&&!is_link($file)&&realpath($file)===$file&&$stat['size']===strlen($bytes)
  &&$stat['nlink']===1&&$stat['uid']===posix_geteuid()&&file_get_contents($file)===$bytes,'TASK8_REAL_PUBLIC_LOCAL_FILE_FULL_BYTES');
 m4ar_expect(hash_file('sha256',$file)===hash('sha256',$bytes),'TASK8_CUSTOMER_PUBLIC_IMAGE_ACTUAL_FULL_SHA256');
 $decoded=imagecreatefromstring($bytes);m4ar_expect($decoded instanceof GdImage&&imagesx($decoded)===1&&imagesy($decoded)===1,
  'TASK8_CUSTOMER_UPLOADED_BYTES_ACTUAL_GD_ONE_PIXEL');imagedestroy($decoded);
 $newFiles=$after['public'];$relative=ltrim($ref,'/');m4ar_expect(isset($newFiles[$relative])&&!isset($before['public'][$relative]),'TASK8_TRUE_NEW_PUBLIC_PATH');
 unset($newFiles[$relative]);m4ar_expect($newFiles===$before['public']&&$after['private']===$before['private'],'TASK8_EXACT_PUBLIC_FILE_DELTA_ONLY');
 return ['row'=>$row,'reference'=>$ref,'display_url'=>$data['url']];
}

function m4ar_review(PDO $db,array $owner,array $actors,string $bytes):array
{
 m4ar_phase('normal_received_original_item_review');$rootId=(int)$owner['root']['id'];$root=m4ar_one(m4ar_snapshot($db)['rows']['eb_store_order'],'id',$rootId);
 $items=m5pcp_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid=? ORDER BY id',[$rootId]);m4ar_expect(count($items)===2,'TASK8_ACTUAL_ORIGINAL_A_ITEMS');$item=$items[0];
 $ranges=m5pcp_rows($db,"SELECT * FROM eb_marketplace_fulfillment_range WHERE root_order_id=? AND operation_action='receive' ORDER BY id",[$rootId]);
 $received=[];foreach($ranges as$r)if((int)$r['source_item_id']===(int)$item['id'])for($i=(int)$r['unit_start'];$i<(int)$r['unit_end'];$i++){
  m4ar_expect(!isset($received[$i]),'TASK8_ORIGINAL_REAL_RECEIPT_NO_OVERLAP');$received[$i]=true;}
 m4ar_expect(count($received)===(int)$item['cart_num']&&(int)$root['paid']===1&&(int)$root['status']===2,'TASK8_REVIEW_ELIGIBILITY_INDEPENDENT_ORIGINAL_Q_ALL_RECEIVED');
 $site=(string)sys_config('site_url');$path='/api/order/reviews/'.$rootId.'/'.$item['id'];$before=m4ar_snapshot($db);$quote=m4ar_json('GET',$path,$actors['customer']);
 m4c_entry_same($quote['json']['data'],m4ar_review_quote($root,$item,null,$site,true));m4ar_read_finish($db,$before,$actors['customer'],$quote);
 $upload=m4ar_customer_review_upload($db,$root,$item,$actors['customer'],$bytes);$body=['schema'=>1,'product_score'=>5,'service_score'=>5,'comment'=>'通用商城正常购买评价','pics'=>[$upload['reference']]];
 $before=m4ar_snapshot($db);$reply=m4ar_json('POST',$path,$actors['customer'],$body);$after=m4ar_snapshot($db);$new=m4ar_new_rows($before['rows']['eb_store_product_reply'],$after['rows']['eb_store_product_reply']);
 m4ar_expect(count($new)===1,'TASK8_ONE_REAL_REVIEW_INSERT');$review=$new[0];$at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$review['add_time']);
 $reviewId=m5pcp_u64($before['database']['tables']['eb_store_product_reply']['metadata']['table']['AUTO_INCREMENT']);
 m4ar_expect((string)$review['id']===$reviewId,'TASK8_REAL_REVIEW_NATURAL_ID_FOR_CUSTOMER_BINDING');
 $assetTable='eb_marketplace_customer_asset';$assetId=m5pcp_u64($upload['row']['id']);
 $staged=m4ar_one($before['rows'][$assetTable],'id',$assetId);m4ar_expect($staged===$upload['row'],'TASK8_FULL34_STAGED_BEFORE_ACTUAL_REVIEW');
 $bound=m4ar_one($after['rows'][$assetTable],'id',$assetId);$boundAt=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$bound['bound_at']);
 $boundExpected=$staged;$boundExpected['state']='BOUND';$boundExpected['review_id']=$reviewId;$boundExpected['bound_at']=$boundAt;
 $boundExpected=m4fn_expected_row($before['database']['tables'][$assetTable]['metadata'],$bound,$boundExpected);
 $user=m4ar_one($before['rows']['eb_user'],'uid',$owner['uid']);$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$sku=$cart['productInfo']['attrInfo'];
 m4fn_expected_row($before['database']['tables']['eb_store_product_reply']['metadata'],$review,['id'=>$review['id'],'uid'=>$owner['uid'],'oid'=>$rootId,'unique'=>$item['unique'],
 'product_id'=>(int)$item['product_id'],'reply_type'=>'product','product_score'=>5,'service_score'=>5,'comment'=>$body['comment'],'pics'=>json_encode($body['pics'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
 'add_time'=>$at,'merchant_reply_content'=>'','merchant_reply_time'=>0,'is_del'=>0,'is_reply'=>0,'nickname'=>$user['nickname'],'avatar'=>$user['avatar'],'suk'=>$sku['suk'],'status'=>0,'mer_id'=>(int)$root['mer_id']]);
 $expected=['eb_store_product_reply'=>array_merge($before['rows']['eb_store_product_reply'],[$review]),
  $assetTable=>m4ar_replaced($before['rows'][$assetTable],'id',$assetId,$boundExpected)];m4ar_auth_delta($before,$after,$actors['customer'],$reply['actual_interval'],$expected);
 m4ar_database_delta($before,$after,$expected,['eb_store_product_reply'=>1]);m4ar_redis_delta($before,$after);m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],
 'TASK8_REVIEW_USES_EXISTING_PUBLIC_IMAGE_NO_FILE_WRITE');m4c_entry_same($reply['json']['data'],m4ar_review_view($review,(int)$item['id'],$site));
 $again=m4ar_json('POST',$path,$actors['customer'],$body);m4c_entry_same($again['json']['data'],m4ar_review_view($review,(int)$item['id'],$site));
 m4ar_read_finish($db,$after,$actors['customer'],$again);m4ar_review_reads($db,$root,$item,$review,$actors,$site);
 $pendingPublic=m4ar_pending_public_review($db,$item,$review);
 foreach(['moderate','reply']as$operation){$actor=$operation==='moderate'?$actors['platform']:$actors['merchant'];$request=$operation==='moderate'?['schema'=>1,'status'=>1]:['schema'=>1,'content'=>'感谢您的正常购买评价'];
  $url=$operation==='moderate'?'/adminapi/marketplace/reviews/'.$review['id'].'/review':'/merchantapi/reviews/'.$review['id'].'/reply';
  $before=m4ar_snapshot($db);$reply=m4ar_json('PUT',$url,$actor,$request);$after=m4ar_snapshot($db);$now=m4ar_one($after['rows']['eb_store_product_reply'],'id',$review['id']);$want=$review;
  if($operation==='moderate')$want['status']=1;else{$want['merchant_reply_content']=$request['content'];$want['merchant_reply_time']=$now['merchant_reply_time'];
   m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$now['merchant_reply_time']);$want['is_reply']=1;}
  m4ar_expect($now===$want,'TASK8_ONLY_ONE_MODERATION_OR_THREE_REPLY_NATIVE_COLUMNS');$changed=[];foreach($review as$k=>$v)if($want[$k]!==$v)$changed[]=$k;
  $expected=['eb_store_product_reply'=>m4ar_replaced($before['rows']['eb_store_product_reply'],'id',$review['id'],$want)];$counts=[];
  m4ar_audit_delta($before,$after,$actor,['mer_id'=>(int)$root['mer_id'],'action'=>$operation==='moderate'?'marketplace-reviews-moderate':'review.reply',
   'resource_type'=>'review','resource_id'=>$review['id'],'detail'=>['changed_fields'=>$changed]],$reply['actual_interval'],$expected,$counts);
  m4ar_database_delta($before,$after,$expected,$counts);m4ar_redis_delta($before,$after,m4ar_cache_deletes($before,$after,$actor));
  m4ar_expect($before['private']===$after['private']&&$before['public']===$after['public'],'TASK8_MODERATION_REPLY_NO_FILE_OR_COMMERCE_MUTATION');
  m4c_entry_same($reply['json']['data'],m4ar_review_view($want,(int)$item['id'],$site));$review=$want;m4ar_review_reads($db,$root,$item,$review,$actors,$site);}
 $public=m4ar_public_review($db,$item,$review,$owner['uid'],$upload,$bytes);
 return ['root_order_id'=>$rootId,'source_item_id'=>(int)$item['id'],'review_id'=>(int)$review['id'],'image_source'=>'customer_review','customer_asset_id'=>m5pcp_u64($upload['row']['id']),
 'image_bytes'=>strlen($bytes),'image_sha256'=>hash('sha256',$bytes),'create_same_body_replay'=>true,'status_sequence'=>[0,1],'merchant_reply_changed_columns'=>3,'public'=>$public,'pending_public'=>$pendingPublic];
}

function m4ar_relative_time(int $created,int $observed):?string
{
 $age=$observed-$created;foreach([31536000=>'年',2592000=>'个月',604800=>'星期',86400=>'天',3600=>'小时',60=>'分钟',1=>'秒']as$seconds=>$label){
  $n=(int)floor($age/$seconds);if($n!==0)return $n.$label.'前';}return null;
}
function m4ar_public_review(PDO $db,array $item,array $review,int $uid,array $upload,string $bytes):array
{
 m4ar_phase('normal_anonymous_twelve_field_review_and_image');$native=m4ar_snapshot($db);$qualifying=[];
 foreach($native['rows']['eb_store_product_reply']as$row)if((int)$row['product_id']===(int)$item['product_id']&&(int)$row['is_del']===0&&(int)$row['status']===1)$qualifying[]=$row;
 m4ar_expect($qualifying===[$review]&&(int)$review['product_score']===5&&(int)$review['service_score']===5,
 'TASK8_INDEPENDENT_NATIVE_SAME_PUBLIC_PREDICATE_SINGLE_REVIEW');
 $user=m4ar_one($native['rows']['eb_user'],'uid',$uid);$own=m4ar_review_view($review,(int)$item['id'],(string)sys_config('site_url'));
 $fixed=['id'=>(int)$review['id'],'product_id'=>(int)$item['product_id'],'nickname'=>$own['nickname'],'avatar'=>$review['avatar'],
 'is_money_level'=>(int)$user['is_money_level'],'star'=>'5','suk'=>$review['suk'],'comment'=>$review['comment'],'pics'=>$own['pics'],
 'merchant_reply_content'=>$review['merchant_reply_content'],'merchant_reply_time'=>date('Y-m-d H:i',(int)$review['merchant_reply_time'])];
 foreach([[0,1],[0,2],[1,1],[2,1],[3,1]]as[$type,$page]){
  $before=m4ar_snapshot($db);$response=m4ar_json('GET','/api/reply/list/'.$item['product_id'].'?type='.$type.'&page='.$page.'&limit=1',[]);
  $rows=$response['json']['data'];$visible=$page===1&&in_array($type,[0,1],true);m4ar_expect(array_is_list($rows)&&count($rows)===($visible?1:0),
   'TASK8_REAL_PUBLIC_LIST_SAME_FILTER_AND_PAGE');
  if($visible){$actual=$rows[0];m4c_entry_keys($actual,['id','product_id','nickname','avatar','is_money_level','star','add_time','suk','comment','pics',
    'merchant_reply_content','merchant_reply_time']);$times=[];foreach(range($response['actual_interval'][0],$response['actual_interval'][1])as$at)
    $times[]=m4ar_relative_time((int)$review['add_time'],$at);
   m4ar_expect(in_array($actual['add_time'],$times,true),'TASK8_PUBLIC_RELATIVE_TIME_FROM_REAL_NATIVE_CREATED_AND_REQUEST_INTERVAL');
   $expected=$fixed;$expected['add_time']=$actual['add_time'];m4c_entry_same($actual,$expected);}
  m4ar_zero($before,m4ar_snapshot($db));
 }
 $before=m4ar_snapshot($db);$response=m4ar_json('GET','/api/reply/config/'.$item['product_id'],[]);
 m4c_entry_same($response['json']['data'],['sum_count'=>1,'good_count'=>1,'in_count'=>0,'poor_count'=>0,'reply_chance'=>'100','reply_star'=>'5']);
 m4ar_zero($before,m4ar_snapshot($db));
 $before=m4ar_snapshot($db);$image=m4ar_transport('GET',$upload['reference']);
 m4ar_expect(($image['headers']['content-type']??null)==='image/png'&&$image['raw']===$bytes&&strlen($image['raw'])===strlen($bytes)
  &&hash('sha256',$image['raw'])===hash('sha256',$bytes),'TASK8_ANONYMOUS_ACTUAL_PUBLIC_IMAGE_FULL_BYTES');
 $decoded=imagecreatefromstring($image['raw']);m4ar_expect($decoded instanceof GdImage&&imagesx($decoded)===1&&imagesy($decoded)===1,
  'TASK8_ANONYMOUS_REAL_GD_PNG_DECODE');imagedestroy($decoded);m4ar_zero($before,m4ar_snapshot($db));
 return ['projection_fields'=>12,'native_predicate_count'=>1,'list_requests'=>5,'count_requests'=>1,'anonymous_image_requests'=>1,
  'image_bytes'=>strlen($bytes),'image_sha256'=>hash('sha256',$bytes),'same_native_predicate'=>true,'full_raw_image_bytes'=>true];
}

/** This is the sole status reader for the one owned HTTP handle. */
function m4ar_child_native($process):array
{
 if(is_array($GLOBALS['m4ar_http_first_terminal']??null))return $GLOBALS['m4ar_http_first_terminal'];
 $status=proc_get_status($process);
 if(is_array($status)){
  $status=array_intersect_key($status,array_flip(['pid','running','signaled','stopped','exitcode','termsig','stopsig']));
  $GLOBALS['m4ar_http_last_native']=$status;$GLOBALS['m4ar_http_first_native']??=$status;
  if(($status['running']??null)===false)$GLOBALS['m4ar_http_first_terminal']=$status;
 }
 m4ar_expect(is_array($status),'TASK78_HTTP_ACTUAL_NATIVE_STATUS');
 m4c_entry_keys($status,['pid','running','signaled','stopped','exitcode','termsig','stopsig']);
 m4ar_expect(is_int($status['pid'])&&$status['pid']>1&&is_bool($status['running'])
  &&is_bool($status['signaled'])&&is_bool($status['stopped'])&&is_int($status['exitcode'])
  &&is_int($status['termsig'])&&is_int($status['stopsig']),'TASK78_HTTP_COMPLETE_NATIVE_STATUS_TYPES');
 $GLOBALS['m4ar_http_native_pid']??=$status['pid'];
 m4ar_expect($GLOBALS['m4ar_http_native_pid']===$status['pid'],'TASK78_HTTP_SAME_OWNED_HANDLE_PID');return $status;
}
function m4ar_child_try_group(array $status,array $supervisor):?array
{
 if(!$status['running'])return null;
 $leader=audit_app_proc_stat($status['pid']);
 if($leader===null||$leader['ppid']!==$supervisor['pid']||$leader['pgid']!==$status['pid']||$leader['sid']!==$status['pid'])return null;
 $group=['leader_pid'=>$status['pid'],'pgid'=>$leader['pgid'],'sid'=>$leader['sid'],'leader_starttime'=>$leader['starttime']];
 audit_app_group_members($group,$supervisor);return $group;
}
/** No group is inferred: an unregistered failure can close only with no other process. */
function m4ar_child_other_processes(array $supervisor):array
{
 $self=audit_app_proc_stat(getmypid());
 foreach(['pid','pgid','sid','starttime']as$key)m4ar_expect($self!==null&&$self[$key]===$supervisor[$key],
  'TASK78_UNREGISTERED_CLOSE_SAME_REAL_SUPERVISOR');
 $files=glob('/proc/[0-9]*/stat');m4ar_expect(is_array($files)&&$files!==[],'TASK78_FRESH_NATIVE_PROCESS_INVENTORY');
 $others=[];$seen=false;
 foreach($files as$file){$pid=(int)basename(dirname($file));$row=audit_app_proc_stat($pid);if($row===null)continue;
  if($pid===$supervisor['pid']){$seen=true;foreach(['pid','pgid','sid','starttime']as$key)
    m4ar_expect($row[$key]===$supervisor[$key],'TASK78_INVENTORY_REAL_SUPERVISOR_IDENTITY');}
  else $others[]=$row;
 }
 m4ar_expect($seen,'TASK78_FRESH_INVENTORY_CONTAINS_SUPERVISOR');
 usort($others,static fn(array $a,array $b):int=>$a['pid']<=>$b['pid']);return $others;
}
function m4ar_child_failure(string $code):void
{
 $GLOBALS['m4ar_cleanup_failure']??=['code'=>$code,'class'=>'RuntimeException','file'=>basename(__FILE__),'line'=>__LINE__];
}
function m4ar_child_streams():?array
{
 try{$streams=[];
  foreach($GLOBALS['m4ar_http_streams']as$p){clearstatcache(true,$p);
   m4ar_expect(is_file($p)&&!is_link($p)&&filesize($p)<=16777216,'TASK78_HTTP_COMPLETE_BOUNDED_NATIVE_STREAM');
   $hash=hash_file('sha256',$p);m4ar_expect(is_string($hash)&&m5pc_hex($hash),'TASK78_HTTP_REAL_COMPLETE_STREAM_HASH');
   $streams[]=['bytes'=>filesize($p),'sha256'=>$hash];
  }
  return $streams;
 }catch(Throwable $error){$GLOBALS['m4ar_cleanup_failure']??=m4ar_diagnostic($error);return null;}
}
function m4ar_child_closed_receipt(array $closed):array
{
 $supervisor=$GLOBALS['m4ar_http_supervisor'];$group=$closed['group'];
 $remaining=$group===null?m4ar_child_other_processes($supervisor):audit_app_group_members($group,$supervisor);
 $listener=audit_app_listener_open();
 return $GLOBALS['m4ar_child_proof']=['stopped'=>$remaining===[]&&!$listener,'group'=>$group,'remaining_members'=>$remaining,
  'listener_open'=>$listener,'first_terminal'=>$closed['first_terminal'],
  'term_attempted'=>($GLOBALS['m4ar_http_term_attempted']??false),
  'stop_signal'=>($GLOBALS['m4ar_http_term_sent']??false)?SIGTERM:null,
  'proc_close_exit'=>$closed['proc_close_exit'],'streams'=>$closed['streams']];
}
function m4ar_child_start(array $source):void
{
 m4ar_phase('normal_registered_http_child');$supervisor=audit_app_proc_stat(getmypid());
 m4ar_expect(is_array($supervisor),'TASK78_REAL_HTTP_PARENT_NATIVE_IDENTITY');
 $stdout='/workspace/crmeb/runtime/ms4-aftersale-review-http.stdout';$stderr='/workspace/crmeb/runtime/ms4-aftersale-review-http.stderr';
 m4ar_expect(!file_exists($stdout)&&!file_exists($stderr)&&!audit_app_listener_open(),'TASK78_NEW_HTTP_STREAMS_AND_NO_OLD_LISTENER');
 $GLOBALS['m4ar_http_supervisor']=$supervisor;$GLOBALS['m4ar_http_streams']=[$stdout,$stderr];
 $process=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],[['file','/dev/null','r'],['file',$stdout,'x'],['file',$stderr,'x']],
  $pipes,'/workspace/crmeb',m4c_entry_child_environment($source));
 // Own the actual return before validating it or observing group registration.
 $GLOBALS['m4ar_http_process']=$process;m4ar_expect(is_resource($process),'TASK78_REAL_HTTP_NATIVE_PROCESS_HANDLE');
 $group=null;$until=microtime(true)+1.0;
 do{$status=m4ar_child_native($process);$group=m4ar_child_try_group($status,$supervisor);
  if($group!==null){$GLOBALS['m4ar_http_group']=$group;break;}
  if(!$status['running'])break;usleep(5000);
 }while(microtime(true)<$until);
 m4ar_expect($group!==null,'TASK78_REAL_HTTP_GROUP_OBSERVED_BEFORE_TERMINAL');
 $until=microtime(true)+5;
 do{$observation=audit_app_process_observation($group,$supervisor);$status=m4ar_child_native($process);
  if(!$status['running']||($observation['listener_open']&&$observation['members']!==[]))break;usleep(10000);
 }while(microtime(true)<$until);
 m4ar_expect($status['running']&&$observation['listener_open']&&$observation['members']!==[],
  'TASK78_REGISTERED_SAME_HANDLE_REAL_HTTP_LISTENER');
}
function m4ar_child_close():?array
{
 if(is_array($GLOBALS['m4ar_http_closed']??null))return m4ar_child_closed_receipt($GLOBALS['m4ar_http_closed']);
 $process=$GLOBALS['m4ar_http_process']??null;$group=$GLOBALS['m4ar_http_group']??null;
 if(!is_resource($process)&&$group===null)return $GLOBALS['m4ar_child_proof']??null;
 $supervisor=$GLOBALS['m4ar_http_supervisor'];$status=m4ar_child_native($process);
 if($group===null&&$status['running']){
  $group=m4ar_child_try_group($status,$supervisor);if($group!==null)$GLOBALS['m4ar_http_group']=$group;
 }
 if($group===null){
  m4ar_child_failure('TASK78_HTTP_GROUP_NEVER_REGISTERED');
  if($status['running'])return ['stopped'=>false,'group'=>null,'remaining_members'=>m4ar_child_other_processes($supervisor),
   'listener_open'=>audit_app_listener_open(),'first_terminal'=>null,'term_attempted'=>false,'stop_signal'=>null,'proc_close_exit'=>null,'streams'=>null];
  // An already terminal handle is never registered or signalled again.
  $exit=proc_close($process);$closed=['group'=>null,'first_terminal'=>$GLOBALS['m4ar_http_first_terminal'],
   'proc_close_exit'=>$exit,'streams'=>null];
  $GLOBALS['m4ar_http_closed']=$closed;$GLOBALS['m4ar_http_process']=null;
  $GLOBALS['m4ar_http_closed']['streams']=m4ar_child_streams();
  return m4ar_child_closed_receipt($GLOBALS['m4ar_http_closed']);
 }
 $remaining=audit_app_group_members($group,$supervisor);
 if(empty($GLOBALS['m4ar_http_term_attempted'])&&$remaining!==[]){$GLOBALS['m4ar_http_term_attempted']=true;
  $sent=posix_kill(-$group['pgid'],SIGTERM);$GLOBALS['m4ar_http_term_sent']=$sent;
  m4ar_expect($sent||audit_app_group_members($group,$supervisor)===[],'TASK78_ONLY_REGISTERED_HTTP_GROUP_ONE_TERM');}
 $first=$GLOBALS['m4ar_http_first_terminal']??null;$until=microtime(true)+5;
 do{$status=m4ar_child_native($process);$first=$GLOBALS['m4ar_http_first_terminal']??null;
  if($first!==null)while(pcntl_waitpid(-$group['pgid'],$wait,WNOHANG)>0){}
  $remaining=audit_app_group_members($group,$supervisor);
  if($remaining===[]&&!audit_app_listener_open()&&$first!==null)break;usleep(20000);
 }while(microtime(true)<$until);
 if($remaining!==[]||audit_app_listener_open()||$first===null){m4ar_child_failure('TASK78_HTTP_GROUP_REMAINS');
  return ['stopped'=>false,'group'=>$group,'remaining_members'=>$remaining,'listener_open'=>audit_app_listener_open(),'first_terminal'=>$first,
   'term_attempted'=>($GLOBALS['m4ar_http_term_attempted']??false),'stop_signal'=>($GLOBALS['m4ar_http_term_sent']??false)?SIGTERM:null,
   'proc_close_exit'=>null,'streams'=>null];}
 $exit=proc_close($process);$closed=['group'=>$group,'first_terminal'=>$first,'proc_close_exit'=>$exit,'streams'=>null];
 // Save irreversible native closure before any stream or later identity check can fail.
 $GLOBALS['m4ar_http_closed']=$closed;$GLOBALS['m4ar_http_process']=null;$GLOBALS['m4ar_http_group']=null;
 $GLOBALS['m4ar_http_closed']['streams']=m4ar_child_streams();
 m4ar_expect($first['running']===false&&$first['termsig']!==SIGKILL&&is_int($exit),'TASK78_HTTP_REAL_FIRST_TERMINAL_NO_ESCALATION');
 return m4ar_child_closed_receipt($GLOBALS['m4ar_http_closed']);
}

function m4ar_private_seal(string $path,string $scope,array $source,array $payload):array
{
 m4ar_expect(in_array($path,[M4AR_SEAL,M4AR_PAID_SEAL,M4AR_PUBLIC_KEEP,M4W_BEFORE,M4W_AFTER],true)&&!file_exists($path)&&!is_link($path),'TASK78_NEW_INCREMENTAL_PRIVATE_SEAL_CREATE_ONLY');
 $payload=['schema'=>1,'scope'=>$scope,'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
 'container_id'=>$source['container_id'],'source_binding_sha256'=>$source['source_binding_sha256']]+$payload;
 $bytes=serialize($payload);m4ar_expect(strlen($bytes)>0&&strlen($bytes)<=67108864,'TASK78_BOUND_COMPLETE_INCREMENTAL_NATIVE_ARCHIVE');
 $outer=['schema'=>1,'scope'=>$scope.'_ARCHIVE','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
 'container_id'=>$source['container_id'],'encoding'=>'php-serialize-base64-v1','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'payload'=>base64_encode($bytes)];
 $raw=m4cb_json($outer);$mask=umask(0077);$stream=fopen($path,'xb');
 try{m4ar_expect(is_resource($stream)&&chmod($path,0600),'TASK78_INCREMENTAL_PRIVATE_OPEN');$offset=0;while($offset<strlen($raw)){$n=fwrite($stream,substr($raw,$offset));
   m4ar_expect(is_int($n)&&$n>0,'TASK78_INCREMENTAL_PRIVATE_COMPLETE_WRITE');$offset+=$n;}m4ar_expect(fflush($stream)&&fsync($stream),'TASK78_INCREMENTAL_PRIVATE_REAL_FSYNC');}
 finally{if(is_resource($stream))fclose($stream);umask($mask);}
 $read=m4ar_private_input($path,100663296);m4ar_expect($read['raw']===$raw&&$read['value']===$outer,'TASK78_INCREMENTAL_PRIVATE_COMPLETE_STAT_AND_READBACK');
 $restored=unserialize(base64_decode($outer['payload'],true),['allowed_classes'=>false,'max_depth'=>128]);m4ar_expect(is_array($restored)&&$restored===$payload&&serialize($restored)===$bytes,
 'TASK78_INCREMENTAL_PRIVATE_FULL_NATIVE_RESTORE');
 return ['path_basename'=>basename($path),'scope'=>$scope,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'native_archive_bytes'=>strlen($bytes),'native_archive_sha256'=>hash('sha256',$bytes)];
}
function m4ar_public_file_keep(PDO $db,array $source,array $final,array $finalSeal):void
{
 $root='/workspace/crmeb/public';$scope='MS4_AFTERSALE_REVIEW_PUBLIC_FILE_KEEP_NATIVE_FACTS';
 m4ar_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'TASK78_KEEP_CANONICAL_PUBLIC_ROOT');
 m4ar_zero($final,m4ar_snapshot($db));$inputs=[];
 foreach([M4CB_FIXTURE=>1048576,M5PC_BASELINE=>2097152,M4FN_BASELINE=>67108864,M4AR_PAID_SEAL=>100663296,M4AR_SEAL=>100663296]as$path=>$maximum)
  $inputs[$path]=['maximum'=>$maximum,'input'=>m4ar_private_input($path,$maximum)];
 $saved=$inputs[M4AR_SEAL]['input'];$outer=$saved['value'];
 m4c_entry_keys($outer,['schema','scope','run_id','source_sha','freeze_sha256','container_id','encoding','bytes','sha256','payload']);
 m4ar_expect($outer['schema']===1&&$outer['scope']==='MS4_AFTERSALE_REVIEW_FINAL_NATIVE_FACTS_ARCHIVE'
  &&$outer['run_id']===$source['run_id']&&$outer['source_sha']===$source['source_sha']&&$outer['freeze_sha256']===$source['freeze_sha256']
  &&$outer['container_id']===$source['container_id']&&$outer['encoding']==='php-serialize-base64-v1'
  &&is_int($outer['bytes'])&&$outer['bytes']>0&&$outer['bytes']<=67108864&&is_string($outer['payload']),'TASK78_KEEP_REAL_FINAL_ENVELOPE');
 $native=base64_decode($outer['payload'],true);
 m4ar_expect(is_string($native)&&base64_encode($native)===$outer['payload']&&strlen($native)===$outer['bytes']
  &&hash('sha256',$native)===$outer['sha256'],'TASK78_KEEP_FINAL_STRICT_NATIVE_BYTES');
 m4ar_expect($finalSeal===['path_basename'=>basename(M4AR_SEAL),'scope'=>'MS4_AFTERSALE_REVIEW_FINAL_NATIVE_FACTS','bytes'=>strlen($saved['raw']),
  'sha256'=>$saved['sha256'],'native_archive_bytes'=>strlen($native),'native_archive_sha256'=>hash('sha256',$native)],
  'TASK78_KEEP_EXACT_ORIGINAL_FINAL_SIX_FIELDS');
 $restoredFinal=unserialize($native,['allowed_classes'=>false,'max_depth'=>128]);
 m4ar_expect(is_array($restoredFinal)&&serialize($restoredFinal)===$native&&$restoredFinal['schema']===1
  &&$restoredFinal['scope']==='MS4_AFTERSALE_REVIEW_FINAL_NATIVE_FACTS'&&$restoredFinal['run_id']===$source['run_id']
  &&$restoredFinal['source_sha']===$source['source_sha']&&$restoredFinal['freeze_sha256']===$source['freeze_sha256']
  &&$restoredFinal['container_id']===$source['container_id']&&$restoredFinal['source_binding_sha256']===$source['source_binding_sha256']
  &&$restoredFinal['native']===$final,'TASK78_KEEP_TRUE_FINAL_SNAPSHOT_BINDING');
 $files=[];$map=[];$total=0;
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
 foreach($iterator as$file){
  $path=$file->getPathname();m4ar_expect(str_starts_with($path,$root.'/'),'TASK78_KEEP_ONLY_PUBLIC_MEMBERS');
  $relative=substr($path,strlen($root)+1);$parts=explode('/',$relative);
  m4ar_expect($relative!==''&&!str_starts_with($relative,'/')&&!str_contains($relative,"\0")&&!str_contains($relative,'\\'),
   'TASK78_KEEP_STRICT_RELATIVE_PATH');
  foreach($parts as$part)m4ar_expect($part!==''&&$part!=='.'&&$part!=='..','TASK78_KEEP_NO_EMPTY_OR_DOT_SEGMENTS');
  $parent=$root;
  foreach(array_slice($parts,0,-1)as$part){$parent.='/'.$part;
   m4ar_expect(is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent,'TASK78_KEEP_CANONICAL_PARENT_CHAIN');}
  clearstatcache(true,$path);$before=lstat($path);
  m4ar_expect(is_array($before)&&!$file->isLink()&&!is_link($path)&&realpath($path)===$path
   &&realpath(dirname($path))===dirname($path),'TASK78_KEEP_CANONICAL_NONLINK_MEMBER');
  if(($before['mode']&0170000)===0040000){m4ar_expect(is_dir($path),'TASK78_KEEP_REAL_DIRECTORY');continue;}
  m4ar_expect(($before['mode']&0170000)===0100000&&is_file($path)&&$before['uid']===posix_geteuid()&&$before['nlink']===1
   &&is_int($before['size'])&&$before['size']>=0&&$before['size']<=67108864-$total,'TASK78_KEEP_OWNER_SINGLE_LINK_BOUNDED_REGULAR');
  m4ar_expect(isset($final['public'][$relative])&&!isset($files[$relative]),'TASK78_KEEP_EXACT_UNIQUE_FINAL_MEMBER');
  $raw=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);
  $fields=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];$fieldKeys=array_flip($fields);
  $beforeStable=array_intersect_key($before,$fieldKeys);$afterStable=is_array($after)?array_intersect_key($after,$fieldKeys):[];
  m4ar_expect(is_string($raw)&&strlen($raw)===$before['size']&&is_array($after)
   &&count($beforeStable)===count($fields)&&count($afterStable)===count($fields)&&$afterStable===$beforeStable
   &&!is_link($path)&&realpath($path)===$path&&realpath(dirname($path))===dirname($path),'TASK78_KEEP_COMPLETE_READ_ORIGINAL_STABLE_FIELDS');
  $metadata=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'mode'=>$before['mode']&0777,'uid'=>$before['uid'],'nlink'=>$before['nlink']];
  m4ar_expect($metadata===$final['public'][$relative],'TASK78_KEEP_ORIGINAL_FILE_METADATA_EXACT');
  $encoded=base64_encode($raw);$decoded=base64_decode($encoded,true);
  m4ar_expect(is_string($decoded)&&$decoded===$raw&&base64_encode($decoded)===$encoded&&strlen($decoded)===$metadata['bytes']
   &&hash('sha256',$decoded)===$metadata['sha256'],'TASK78_KEEP_PER_FILE_STRICT_BASE64_BYTES');
  $files[$relative]=['metadata'=>$metadata,'base64'=>$encoded];$map[$relative]=$metadata;$total+=strlen($raw);
 }
 ksort($files,SORT_STRING);ksort($map,SORT_STRING);
 m4ar_expect($map===$final['public']&&array_keys($files)===array_keys($map)&&$total<=67108864,'TASK78_KEEP_COMPLETE_FINAL_PUBLIC_MAP');
 $archive=m4ar_private_seal(M4AR_PUBLIC_KEEP,$scope,$source,['final_baseline'=>$finalSeal,'file_map'=>$map,'files'=>$files]);
 $kept=m4ar_private_input(M4AR_PUBLIC_KEEP,100663296);$envelope=$kept['value'];
 m4c_entry_keys($envelope,['schema','scope','run_id','source_sha','freeze_sha256','container_id','encoding','bytes','sha256','payload']);
 m4ar_expect($envelope['schema']===1&&$envelope['scope']===$scope.'_ARCHIVE'&&$envelope['run_id']===$source['run_id']
  &&$envelope['source_sha']===$source['source_sha']&&$envelope['freeze_sha256']===$source['freeze_sha256']
  &&$envelope['container_id']===$source['container_id']&&$envelope['encoding']==='php-serialize-base64-v1'
  &&is_int($envelope['bytes'])&&$envelope['bytes']>0&&$envelope['bytes']<=67108864&&is_string($envelope['payload']),
  'TASK78_KEEP_ACTUAL_PRIVATE_ENVELOPE');
 $bytes=base64_decode($envelope['payload'],true);
 m4ar_expect(is_string($bytes)&&base64_encode($bytes)===$envelope['payload']&&strlen($bytes)===$envelope['bytes']
  &&hash('sha256',$bytes)===$envelope['sha256'],'TASK78_KEEP_ACTUAL_STRICT_NATIVE_BYTES');
 m4ar_expect($archive===['path_basename'=>basename(M4AR_PUBLIC_KEEP),'scope'=>$scope,'bytes'=>strlen($kept['raw']),'sha256'=>$kept['sha256'],
  'native_archive_bytes'=>strlen($bytes),'native_archive_sha256'=>hash('sha256',$bytes)],'TASK78_KEEP_ACTUAL_ARCHIVE_SIX_FIELDS');
 $restored=unserialize($bytes,['allowed_classes'=>false,'max_depth'=>128]);
 $payload=['schema'=>1,'scope'=>$scope,'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
  'container_id'=>$source['container_id'],'source_binding_sha256'=>$source['source_binding_sha256'],'final_baseline'=>$finalSeal,'file_map'=>$map,'files'=>$files];
 m4ar_expect(is_array($restored)&&$restored===$payload&&serialize($restored)===$bytes&&$restored['file_map']===$final['public']
  &&array_keys($restored['files'])===array_keys($final['public']),'TASK78_KEEP_COMPLETE_ACTUAL_PAYLOAD_READBACK');
 foreach($restored['files']as$relative=>$file){m4c_entry_keys($file,['metadata','base64']);$decoded=base64_decode($file['base64'],true);
  m4ar_expect(is_string($decoded)&&base64_encode($decoded)===$file['base64']&&$file['metadata']===$final['public'][$relative]
   &&strlen($decoded)===$file['metadata']['bytes']&&hash('sha256',$decoded)===$file['metadata']['sha256'],'TASK78_KEEP_ACTUAL_EACH_FILE_READBACK');}
 m4ar_zero($final,m4ar_snapshot($db));
 foreach($inputs as$input)m4ar_expect(m4ar_private_input($input['input']['path'],$input['maximum'])===$input['input'],'TASK78_KEEP_ALL_ORIGINAL_PRIVATE_INPUTS_UNCHANGED');
 $descriptor=['schema'=>1,'scope'=>'MS4_AFTERSALE_REVIEW_PUBLIC_FILE_KEEP','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
  'freeze_sha256'=>$source['freeze_sha256'],'container_id'=>$source['container_id'],'source_binding_sha256'=>$source['source_binding_sha256'],
  'final_baseline_sha256'=>$finalSeal['sha256'],'archive'=>$archive,'file_map_sha256'=>hash('sha256',serialize($final['public']))];
 $descriptorPath='/run/audit/evidence/aftersale-review-public-file-keep.json';m4c_entry_publish($descriptorPath,$descriptor);
 m4ar_expect(m4c_entry_read($descriptorPath)===$descriptor,'TASK78_KEEP_FIXED_PUBLIC_DESCRIPTOR_READBACK');
 m4ar_zero($final,m4ar_snapshot($db));
 foreach($inputs as$input)m4ar_expect(m4ar_private_input($input['input']['path'],$input['maximum'])===$input['input'],'TASK78_KEEP_ORIGINAL_INPUTS_RETAINED_AFTER_PUBLICATION');
 m4ar_expect(m4ar_private_input(M4AR_PUBLIC_KEEP,100663296)===$kept,'TASK78_KEEP_PRIVATE_ARCHIVE_RETAINED_AFTER_PUBLICATION');
}
function m4ar_business(\think\App $app,PDO $db,array $source,array $owner,array $privatePreparation):array
{
 m4ar_warm($app,$db);m4ar_child_start($source);$actors=m4ar_actors($app,$db,$source,$owner);
 $produced=m4ar_produce_paid($app,$db,$source,$owner);$model=m4ar_model($produced);$GLOBALS['m4ar_model']=$model;
 $paidSeal=m4ar_private_seal(M4AR_PAID_SEAL,'MS4_AFTERSALE_REVIEW_NEW_PAID_NATIVE_FACTS',$source,
 ['original_stage6'=>['sha256'=>$owner['inputs'][2]['sha256'],'stat'=>$owner['inputs'][2]['stat']],'produced'=>$produced,'native'=>m4ar_snapshot($db)]);
 $root=(int)$model['root']['id'];$q2=$model['source']['q2'];$q1=$model['source']['q1'];$key=static fn(string $name):string=>'normal78:'.$name.':'.bin2hex(random_bytes(12));
 $money=static fn(string $idem):array=>['schema'=>2,'idem_key'=>$idem,'operation_kind'=>'money_only','amount'=>'0.01','reason'=>'正常售后申请',
 'images'=>[],'selection'=>['mode'=>'root_quantities','items'=>[['item_id'=>$q2,'quantity'=>1]]]];
 $record=m4ar_action($db,$model,$actors['customer'],'apply','/api/order/refund/apply/'.$root,$money($key('money-withdraw')));
 m4ar_action($db,$model,$actors['customer'],'withdraw','/api/order/refund/cancel/'.$record['number'],['schema'=>2,'idem_key'=>$key('withdraw')],$record['id']);
 foreach(['merchant','platform']as$name){$record=m4ar_action($db,$model,$actors['customer'],'apply','/api/order/refund/apply/'.$root,$money($key('money-'.$name)));
  $base=$name==='merchant'?'/merchantapi/aftersales/':'/adminapi/marketplace/aftersales/';
  m4ar_action($db,$model,$actors[$name],'reject',$base.$record['id'].'/reject',['schema'=>2,'idem_key'=>$key('business-reject-'.$name),'reason'=>'正常业务审核决定'],$record['id']);}
 $line=$model['source']['lines'][$q2];$cancelAmount=m4ar_decimal(m4ar_cents(m4ar_slice($line['goods'],2,0,1))+m4ar_cents(m4ar_slice($line['postage'],2,0,1)));
 $cancel=['schema'=>2,'idem_key'=>$key('cancel-apply'),'operation_kind'=>'cancel_unshipped','amount'=>$cancelAmount,'reason'=>'取消一件未发商品','images'=>[],
 'selection'=>['mode'=>'root_quantities','items'=>[['item_id'=>$q2,'quantity'=>1]]]];
 $record=m4ar_action($db,$model,$actors['customer'],'apply','/api/order/refund/apply/'.$root,$cancel);
 m4ar_action($db,$model,$actors['merchant'],'approve','/merchantapi/aftersales/'.$record['id'].'/approve',['schema'=>2,'idem_key'=>$key('cancel-approve')],$record['id']);
 m4ar_reads($db,$model,$actors);$parts=[['source_item_id'=>$q2,'unit_start'=>1,'unit_end'=>2],['source_item_id'=>$q1,'unit_start'=>0,'unit_end'=>1]];
 usort($parts,static fn(array $a,array $b):int=>$a['source_item_id']<=>$b['source_item_id']);$shipItems=[];foreach($parts as$p)$shipItems[]=['item_id'=>$p['source_item_id'],'quantity'=>1];
 $ship=['idem_key'=>$key('ship-two'),'items'=>$shipItems,'delivery'=>['type'=>'express','carrier_code'=>'NORMAL','carrier_name'=>'正常物流','tracking_no'=>'NORMAL78'.bin2hex(random_bytes(8))]];
 m4ar_operation($db,$model,$actors['merchant'],'ship','/merchantapi/orders/'.$root.'/shipments',$ship,$parts);$shipRange=null;
 foreach($model['ship_ranges']as$r)if((int)$r['source_item_id']===$q2)$shipRange=$r;m4ar_expect(is_array($shipRange),'TASK78_REAL_Q2_CHILD_ITEM_FOR_RECEIPT');
 $receive=['idem_key'=>$key('receive-one'),'selection'=>['mode'=>'items','items'=>[['item_id'=>(int)$shipRange['child_item_id'],'segments'=>[['first'=>1,'last'=>1]]]]]];
 m4ar_operation($db,$model,$actors['customer'],'receive','/api/order/fulfillment/packages/'.$model['child']['order_id'].'/receipt',$receive,
 [['source_item_id'=>$q2,'unit_start'=>1,'unit_end'=>2]]);m4ar_reads($db,$model,$actors);
 $png=m4ar_png();$file=m4ar_private_upload($db,$model,$actors['customer'],$png);$returnItems=[];$returnParts=[];$returnCents=0;
 foreach($model['ship_ranges']as$r){$returnItems[]=['item_id'=>(int)$r['child_item_id'],'segments'=>[['first'=>1,'last'=>1]]];
  $part=m4ar_part($model,(int)$r['source_item_id'],(int)$r['unit_start'],(int)$r['unit_end'],$r);$returnParts[]=$part;$returnCents+=m4ar_cents($part['goods'])+m4ar_cents($part['postage']);}
 usort($returnItems,static fn(array $a,array $b):int=>$a['item_id']<=>$b['item_id']);usort($returnParts,static fn(array $a,array $b):int=>$a['child_item_id']<=>$b['child_item_id']);
 $apply=['schema'=>2,'idem_key'=>$key('return-apply'),'operation_kind'=>'return_goods','amount'=>m4ar_decimal($returnCents),'reason'=>'正常退货申请',
 'images'=>[(string)$file['id']],'selection'=>['mode'=>'child_items','child_order_no'=>$model['child']['order_id'],'items'=>$returnItems]];
 $record=m4ar_action($db,$model,$actors['customer'],'apply','/api/order/refund/apply/'.$root,$apply);
 $ticketProof=m4ar_tickets($db,$model,$actors,$file,$png,$record['id']);
 m4ar_action($db,$model,$actors['platform'],'approve','/adminapi/marketplace/aftersales/'.$record['id'].'/approve',['schema'=>2,'idem_key'=>$key('return-approve')],$record['id']);
 m4ar_action($db,$model,$actors['customer'],'express','/api/order/refund/express',['schema'=>2,'idem_key'=>$key('return-express'),'refund_no'=>$record['number'],
 'return_delivery'=>['carrier_name'=>'正常退货物流','tracking_no'=>'NORMALRETURN'.bin2hex(random_bytes(7))]],$record['id']);m4ar_reads($db,$model,$actors);
 foreach(['merchant','platform']as$i=>$name){$base=$name==='merchant'?'/merchantapi/aftersales/':'/adminapi/marketplace/aftersales/';
  m4ar_action($db,$model,$actors[$name],'accept_return',$base.$record['id'].'/return-accept',['schema'=>2,'idem_key'=>$key('accept-'.$name),'items'=>[$returnItems[$i]]],
  $record['id'],[$returnParts[$i]]);if(m4ar_summary($model)['closed'])$model['completed_at']=$model['refunds'][$record['id']]['updated_at'];m4ar_reads($db,$model,$actors);}
 $summary=m4ar_summary($model);m4ar_expect($summary['shipped']===2&&$summary['received']===1&&$summary['returned_accepted']===2&&$summary['cancelled_unshipped']===1
 &&$summary['closed']===true&&$summary['to_ship']===0&&$summary['to_receive']===0,'TASK78_RETURN_CLOSE_NEVER_FABRICATES_RECEIPT');
 m4c_entry_same($model['command_counts'],['apply'=>5,'withdraw'=>1,'reject'=>2,'approve'=>2,'express'=>1,'accept_return'=>2]);
 m4ar_expect(count($model['actions'])===13&&count($model['operations'])===2&&$model['replays']===15&&count($model['refunds'])===5,
 'TASK78_COMPLETE_FIXED_NORMAL_COMMAND_AND_REPLAY_COUNTS');
 $review=m4ar_review($db,$owner,$actors,$png);m4ar_inputs_unchanged($owner['inputs']);$final=m4ar_snapshot($db);
 $finalSeal=m4ar_private_seal(M4AR_SEAL,'MS4_AFTERSALE_REVIEW_FINAL_NATIVE_FACTS',$source,['original_inputs'=>array_map(static fn(array $i):array=>
 ['path'=>$i['path'],'sha256'=>$i['sha256'],'stat'=>$i['stat']],$owner['inputs']),'paid_baseline'=>$paidSeal,'model'=>$model,'native'=>$final]);
 m4ar_zero($final,m4ar_snapshot($db));$GLOBALS['m4ar_model']=$model;
 m4ar_public_file_keep($db,$source,$final,$finalSeal);
 return ['authenticated_owner_uid'=>$owner['uid'],'merchant_id'=>(int)$model['root']['mer_id'],'employee_id'=>$actors['merchant']['id'],
 'limited_admin_id'=>$actors['platform']['id'],'original_root_id'=>(int)$owner['root']['id'],'new_root_id'=>$root,'new_items'=>2,'new_quantity'=>3,
 'normal_action_counts'=>$model['command_counts'],'s2_operations'=>count($model['operations']),'same_body_replays'=>$model['replays'],'new_refunds'=>count($model['refunds']),
 'private_evidence'=>['files'=>1,'bytes'=>strlen($png),'sha256'=>hash('sha256',$png),'private_preparation'=>$privatePreparation,'tickets'=>$ticketProof],
 'review'=>$review,'http'=>['calls'=>count($GLOBALS['m4ar_http_calls']),'requests'=>$GLOBALS['m4ar_http_calls']],
 'paid_baseline'=>$paidSeal,'final_baseline'=>$finalSeal,'independent_database_oracle'=>true,'independent_redis_oracle'=>true,'independent_files_oracle'=>true,
 'prior_task6_preserved'=>true,'original_private_inputs_preserved'=>true,'normal_business_decisions'=>['merchant_reject'=>'HTTP200_SUCCESS','platform_reject'=>'HTTP200_SUCCESS'],
 'excluded_acceptance'=>['mobile_workbench'=>'NOT RUN','three_end_DOM'=>'NOT RUN','worker'=>'NOT RUN','finance_real_channel'=>'NOT RUN','nonempty_history'=>'NOT RUN',
 'stopped_samples'=>'NOT RUN','push_deploy'=>'NOT RUN','full_acceptance'=>'INCOMPLETE']];
}

function m4ar_diagnostic(Throwable $error):array
{
 $code=$GLOBALS['m4ar_failed_assertion']??$GLOBALS['m4fn_failed_assertion']??$GLOBALS['m5pcp_failed_assertion']??$GLOBALS['m4c_failed_assertion']??'TASK78_UNCLASSIFIED';
 if(!is_string($code)||preg_match('/\A[A-Z0-9_]{1,160}\z/D',$code)!==1)$code='TASK78_FIXED_FAILURE';
 $diagnostic=['code'=>$code,'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()];
 $transport=$GLOBALS['m4ar_http_failure']??null;
 if($code==='TASK78_REAL_NORMAL_HTTP_TRANSPORT_200'&&is_array($transport)
  &&array_keys($transport)===['method','route_family','http_status','curl_errno','response_is_string','response_bytes']
  &&in_array($transport['method'],['GET','POST','PUT'],true)&&in_array($transport['route_family'],['api','merchantapi','adminapi'],true)
  &&is_int($transport['http_status'])&&$transport['http_status']>=0&&is_int($transport['curl_errno'])&&$transport['curl_errno']>=0
  &&is_bool($transport['response_is_string'])&&($transport['response_is_string']?(is_int($transport['response_bytes'])&&$transport['response_bytes']>=0):$transport['response_bytes']===null))
  $diagnostic['http_transport']=$transport;
 $gate=$GLOBALS['m4ar_http_gate']??null;
 if(is_array($gate)&&array_keys($gate)===['operation_id','attempt','request_sequence','method','route_family','http_status','curl_errno','json_error','status_type','status_int','msg_is_string','data_is_array','failure_class']
  &&in_array($gate['operation_id'],['non_action','money-withdraw','withdraw','money-merchant','business-reject-merchant','money-platform','business-reject-platform','cancel-apply','cancel-approve','return-apply','return-approve','return-express','accept-merchant','accept-platform','ship-two','receive-one','file-ticket-customer','file-ticket-merchant','file-ticket-platform'],true)
  &&in_array($gate['attempt'],['first','replay','single'],true)&&is_int($gate['request_sequence'])&&$gate['request_sequence']>=1&&$gate['request_sequence']<=2147483647
  &&in_array($gate['method'],['GET','POST','PUT'],true)&&in_array($gate['route_family'],['api','merchantapi','adminapi'],true)
  &&is_int($gate['http_status'])&&$gate['http_status']>=0&&$gate['http_status']<=999
  &&is_int($gate['curl_errno'])&&$gate['curl_errno']>=0&&$gate['curl_errno']<=65535
  &&($gate['json_error']===null||(is_int($gate['json_error'])&&$gate['json_error']>=0&&$gate['json_error']<=65535))
  &&in_array($gate['status_type'],['missing','NULL','boolean','integer','double','string','array'],true)
  &&($gate['status_int']===null||($gate['status_type']==='integer'&&is_int($gate['status_int'])&&$gate['status_int']>=-2147483648&&$gate['status_int']<=2147483647))
  &&is_bool($gate['msg_is_string'])&&is_bool($gate['data_is_array'])
  &&in_array($gate['failure_class'],['CURL_ERROR','RESPONSE_TYPE','RESPONSE_LIMIT','HTTP_STATUS','JSON_PARSE','JSON_NOT_ARRAY','ENVELOPE_KEYS','BUSINESS_STATUS','MSG_TYPE','DATA_TYPE'],true))
  $diagnostic['http_gate']=$gate;
 return $diagnostic;
}
function m4ar_result(array $source,?array $business,?array $observer,?array $diagnostic,bool $success):array
{
 return ['schema'=>1,'scope'=>M4AR_RULE['scope'],'status'=>$success?M4AR_RULE['status']:'INCOMPLETE_MS4_AFTERSALE_REVIEW_HTTP_ONLY',
 'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],
 'native_created_sha256'=>$source['native_created_sha256'],'source_binding_sha256'=>$source['source_binding_sha256'],
 'environment_sha256'=>$source['environment_sha256'],'environment_native_sha256'=>$source['environment_native_sha256'],
 'prerequisites_sha256'=>$source['prerequisites_sha256'],'phase'=>$GLOBALS['m4c_phase']??'source','entry_return_code'=>$success?0:1,
 'termination'=>$success?'NORMAL_RETURN':'EXCEPTION','native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
 'full_source_unchanged'=>$success,'metadata_observer'=>$observer,'business'=>$business,'child'=>$GLOBALS['m4ar_child_proof']??null,'diagnostic'=>$diagnostic,
 'mobile_workbench_HTTP_DOM'=>'NOT RUN','three_end_DOM'=>'NOT RUN','worker'=>'NOT RUN','finance'=>'NOT RUN','full_acceptance'=>'INCOMPLETE',
 'stopped_samples'=>'NOT RUN','assertions'=>($GLOBALS['m4ar_assertions']??0)+($GLOBALS['m4fn_assertions']??0)+($GLOBALS['m5pcp_assertions']??0)+($GLOBALS['m4c_assertions']??0)];
}
function m4ar_run():int
{
 m4ar_phase('normal_source');$source=m4ar_source();$GLOBALS['m4ar_source']=$source;$db=audit_db();m4ar_expect(!$db->inTransaction(),'TASK78_NATIVE_OBSERVER_OUTSIDE_TRANSACTION');
 $observer=m5pc_observer_configure($db);$GLOBALS['m4ar_observer']=$observer;$owner=m4ar_owner($source,$db);$private=m4ar_private_prepare();
 m4c_entry_public_materials($source);m4ar_full_source($source);m4ar_language_prepare($db,$source);$app=m4fn_app($source);
 m4ar_expect($app->config->get('marketplace.aftersale.enabled')===true&&$app->config->get('marketplace.aftersale.private_root')===M4AR_PRIVATE,
 'TASK78_REAL_APP_ONLY_NORMAL_ROLE_AFTERSALE_PRIVATE_CONFIG');
 $business=null;$failure=null;
 try{$business=m4ar_business($app,$db,$source,$owner,$private);}
 catch(Throwable $error){$failure=$error;$GLOBALS['m4ar_primary_failure']=m4ar_diagnostic($error);}
 finally{if(is_resource($GLOBALS['m4ar_http_process']??null)||is_array($GLOBALS['m4ar_http_group']??null)){
   do{try{$child=m4ar_child_close();}catch(Throwable $cleanupError){if($failure===null)$failure=$cleanupError;$child=null;}
    // The one registered server is observed until closed. No repeated signal, escalation, restart or substitute child.
    if($child===null||!$child['stopped'])usleep(20000);
   }while($child===null||!$child['stopped']);}}
 if($failure!==null)throw $failure;
 m4ar_expect(is_array($GLOBALS['m4ar_child_proof']??null)&&$GLOBALS['m4ar_child_proof']['stopped']===true
  &&$GLOBALS['m4ar_child_proof']['remaining_members']===[]&&$GLOBALS['m4ar_child_proof']['listener_open']===false
  &&is_array($GLOBALS['m4ar_child_proof']['group'])&&is_array($GLOBALS['m4ar_child_proof']['streams'])
  &&count($GLOBALS['m4ar_child_proof']['streams'])===2&&empty($GLOBALS['m4ar_cleanup_failure']),
  'TASK78_HTTP_COMPLETE_NATIVE_SHUTDOWN');
 m4ar_phase('final_source');m4ar_full_source($source);m4ar_prerequisites($source);m4ar_inputs_unchanged($owner['inputs']);
 m4ar_expect(m4ar_source()===$source,'TASK78_REAL_CURRENT_SOURCE_ENVIRONMENT_ROLES_PREREQUISITES_UNCHANGED');m4cb_guard_readonly();
 $result=m4ar_result($source,$business,$observer,null,true);m4c_entry_publish('/run/audit/evidence/'.M4AR_RULE['result'],$result);
 $GLOBALS['m4ar_result_published']=true;$GLOBALS['m4ar_completed']=true;
 echo json_encode(['scope'=>M4AR_RULE['scope'],'status'=>M4AR_RULE['status'],'phase'=>'final_source','entry_return_code'=>0,
  'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
 if(($argv??[])===[$_SERVER['SCRIPT_FILENAME'],'--help']){echo "Usage: php help/audit-regressions/isolated/ms4-aftersale-review-normal.php normal\nDedicated isolated positive Task7/Task8 HTTP continuation only; host native receipt required.\n";exit(0);}
 $GLOBALS['m4ar_assertions']=0;$GLOBALS['m4ar_http_calls']=[];ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
 set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
  if((error_reporting()&$severity)===0)return false;$GLOBALS['m4ar_failed_assertion']='TASK78_PHP_WARNING';throw new ErrorException('TASK78_PHP_WARNING',0,$severity,$file,$line);});
 pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
 register_shutdown_function(static function():void{
  $source=$GLOBALS['m4ar_source']??null;if(!is_array($source))return;$last=error_get_last();$fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
  if(is_resource($GLOBALS['m4ar_http_process']??null)||is_array($GLOBALS['m4ar_http_group']??null)){
   do{try{$child=m4ar_child_close();}catch(Throwable $cleanupError){$GLOBALS['m4ar_cleanup_failure']=m4ar_diagnostic($cleanupError);$child=null;}
    if($child===null||!$child['stopped'])usleep(20000);
   }while($child===null||!$child['stopped']);}
  $shutdown=['schema'=>1,'scope'=>'MS4_AFTERSALE_REVIEW_NORMAL_SHUTDOWN','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
  'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],'phase'=>$GLOBALS['m4c_phase']??'source',
  'completed'=>($GLOBALS['m4ar_completed']??false)===true,'result_published'=>($GLOBALS['m4ar_result_published']??false)===true,'fatal'=>$fatal,
  'termination_requested'=>($GLOBALS['m4c_terminate_requested']??false)===true,'primary_failure'=>$GLOBALS['m4ar_primary_failure']??null,
  'cleanup_failure'=>$GLOBALS['m4ar_cleanup_failure']??null,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'];
  try{m4c_entry_publish('/run/audit/evidence/aftersale-review-normal-shutdown.json',$shutdown);}catch(Throwable $error){fwrite(STDERR,"TASK78 shutdown receipt unavailable; host native observation required\n");}
 });
 try{exit(m4ar_run());}catch(Throwable $error){$diagnostic=$GLOBALS['m4ar_primary_failure']??m4ar_diagnostic($error);$GLOBALS['m4ar_primary_failure']=$diagnostic;$source=$GLOBALS['m4ar_source']??null;
  if(is_array($source)&&empty($GLOBALS['m4ar_result_published'])){try{m4c_entry_publish('/run/audit/evidence/'.M4AR_RULE['result'],
   m4ar_result($source,null,$GLOBALS['m4ar_observer']??null,$diagnostic,false));$GLOBALS['m4ar_result_published']=true;}catch(Throwable $publicationError){}}
  echo json_encode(['scope'=>M4AR_RULE['scope'],'status'=>'INCOMPLETE_MS4_AFTERSALE_REVIEW_HTTP_ONLY','phase'=>$GLOBALS['m4c_phase']??'source',
  'entry_return_code'=>1,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";exit(1);
 }
}
