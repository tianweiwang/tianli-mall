<?php
declare(strict_types=1);

/** Finite ordinary persisted Task6 only. No HTTP child, payment production, Worker or after-sale samples. */
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceTradePolicy;
use app\services\marketplace\order\MerchantOrderServices;
use think\facade\Db;

require_once __DIR__.'/ms5-paid-consumer-normal.php';
require_once __DIR__.'/lib/ms4-three-legacy-producer.php';
require_once __DIR__.'/lib/ms4-three-legacy-reference.php';
require_once __DIR__.'/lib/ms4-three-read-normal.php';

const M4FN_ENTRY='help/audit-regressions/isolated/ms4-fulfillment-normal.php';
const M4FN_RULE=['role'=>'audit-ms4-fulfillment-normal','proof'=>'fulfillment-normal',
    'binding'=>'ms4-fulfillment-normal-source-binding.json','binding_scope'=>'MS4_FULFILLMENT_NORMAL_SOURCE',
    'environment'=>'fulfillment-normal-environment.json','result'=>'ms4-fulfillment-normal.json',
    'scope'=>'NORMAL_MS4_FULFILLMENT_INTERNAL_ONLY','status'=>'VERIFIED_NORMAL_MS4_FULFILLMENT_INTERNAL_ONLY'];
const M4FN_BASELINE='/run/audit-fixtures/ms4-fulfillment-stage6.json';
const M4FN_TABLES=['eb_store_order','eb_store_order_cart_info','eb_store_order_status',
    'eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range',
    'eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup','eb_system_config'];
const M4FN_MIGRATION_FILES=['ms4-fulfillment-migration-container-created.json',
    'ms4-fulfillment-migration-source-binding.json','ms4-aftersale-early-migration-normal.json',
    'ms4-fulfillment-migration-terminal.json'];
const M4FN_PAID_FILES=['paid-producer-created.json','paid-producer-terminal.json','paid-producer-result.json',
    'paid-producer-source-binding.json','paid-producer-environment.json','paid-producer-environment-native.json'];

function m4fn_expect(bool $ok,string $code):void
{
    $GLOBALS['m4fn_assertions']=($GLOBALS['m4fn_assertions']??0)+1;
    if(!$ok){$GLOBALS['m4fn_failed_assertion']=$code;throw new RuntimeException('M4FN_ASSERTION');}
}
function m4fn_phase(string $phase):void
{
    m4fn_expect(empty($GLOBALS['m4c_terminate_requested']),'TASK6_NOT_INTERRUPTED');m4cb_phase($phase);
}
function m4fn_extra():array
{
    $paths=array_values(array_unique(array_merge(M4C_EXTRA,M4C_MIGRATION_SOURCES,
        [M5PC_ENTRY,M4FN_ENTRY,'help/audit-regressions/isolated/ms4-fulfillment-migration.php',
         'help/migrations/marketplace-ms4-fulfillment.php','help/migrations/marketplace-ms4-assets.php',
         'help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php',
         'help/audit-regressions/isolated/lib/ms4-three-legacy-reference.php',
         'help/audit-regressions/isolated/lib/ms4-three-read-normal.php']),SORT_STRING));
    sort($paths,SORT_STRING);m4fn_expect(count($paths)===26,'TASK6_FINITE_TWENTY_SIX_EXTRAS');return $paths;
}
function m4fn_prerequisite_names():array
{
    return array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES,
        M4FN_PAID_FILES,M4FN_MIGRATION_FILES);
}
function m4fn_inventory(string $root):array
{
    $paths=m4fn_extra();
    foreach(M4C_ROOTS as$directory){
        m4fn_expect(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory),'TASK6_SOURCE_DIRECTORY');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as$file){m4fn_expect(!$file->isLink(),'TASK6_SOURCE_NO_LINK');
            if($file->isFile())$paths[]=substr($file->getPathname(),strlen($root)+1);}
    }
    sort($paths,SORT_STRING);m4fn_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_UNIQUE_PATHS');return $paths;
}
function m4fn_full_source(array $source,bool $beforePublicCopy=false):void
{
    $paths=[];$allowed=[];
    m4fn_expect(is_array($source['source_files'])&&array_is_list($source['source_files']),'TASK6_SOURCE_DESCRIPTOR_LIST');
    foreach($source['source_files']as$row){m4c_entry_keys($row,['path','bytes','sha256']);
        m4fn_expect(is_string($row['path'])&&$row['path']!==''&&$row['path'][0]!=='/'&&!str_contains($row['path'],'\\')
            &&!str_contains($row['path'],':')&&!in_array('..',explode('/',$row['path']),true)
            &&is_int($row['bytes'])&&$row['bytes']>=0&&m5pc_hex($row['sha256']),'TASK6_SOURCE_DESCRIPTOR');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);
    m4fn_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),'TASK6_SOURCE_SORTED_CLOSED_SET');
    foreach(['/source','/workspace']as$root){m4fn_expect(m4fn_inventory($root)===$paths,'TASK6_SOURCE_INVENTORY');
        foreach($source['source_files']as$row){
            if($beforePublicCopy&&$root==='/workspace'&&in_array($row['path'],['crmeb/public/index.php','crmeb/public/router.php'],true))continue;
            $path=$root.'/'.$row['path'];m4fn_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'TASK6_SOURCE_ALL_BYTES');$allowed[$path]=true;}}
    $allowed+=m4cf_import_allowed();
    foreach(get_included_files()as$path)m4fn_expect(isset($allowed[realpath($path)]),'TASK6_INCLUDED_COMPLETE_SOURCE');
}
function m4fn_environment(array $binding,array $context):array
{
    $base='/run/audit/evidence/';$receipt=m4c_entry_read($base.M4FN_RULE['environment']);
    m4c_entry_keys($receipt,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4fn_expect($receipt['schema']===1&&$receipt['scope']==='MS4_FULFILLMENT_NORMAL_REAL_ENVIRONMENT'
        &&$receipt['run_id']===$binding['run_id']&&$receipt['source_sha']===$binding['source_sha']
        &&$receipt['container_id']===$binding['container_id']&&$receipt['freeze_sha256']===$context['freeze_sha256']
        &&getenv('MS4_FULFILLMENT_PROOF_NAME')===M4FN_RULE['proof'],'TASK6_REAL_ENVIRONMENT_IDENTITY');
    $native=m4c_entry_read($base.M4FN_RULE['proof'].'-environment-native.json');
    m4c_entry_keys($native,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
    m4fn_expect($receipt['native_projection_sha256']===$native['stdout_sha256'],'TASK6_FULL_NATIVE_ENVIRONMENT_HASH');
    return m4c_entry_native_environment($receipt['environment'],$native);
}


function m4fn_source(): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'normal']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_ACTUAL_FINITE_NATIVE_ENTRY');
    $rule=M4FN_RULE;$evidence='/run/audit/evidence/';
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
    m4fn_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'TASK6_ONLY_FOUR_ACTUAL_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    m4fn_expect($freeze['fulfillment_source_roots']===M4C_ROOTS&&$freeze['fulfillment_source_extra']===m4fn_extra(),
        'TASK6_REAL_FOUR_ROOTS_FINITE_EXTRA_CLOSURE');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
    m4c_expect($binding['schema']===1&&$binding['scope']===$rule['binding_scope']&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$rule['proof'].'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['fulfillment_source_files']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha
        &&$created['proof_name']===$rule['proof']&&$created['hostname']===gethostname()&&$created['read_only']===true
        &&$created['user']==='0:0'&&posix_geteuid()===0&&posix_getegid()===0
        &&$created['privileged']===false&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4FN_ENTRY,'normal']&&$created['workdir']==='/workspace'
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
    m4fn_expect($created['stop_signal']==='SIGTERM'&&getmypid()===1,'TASK6_REAL_PID_ONE_SIGTERM_NO_HTTP_CHILD');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',true],
        '/run/audit-customer-form'=>['customer-form-evidence',false]];
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
    $values=m4fn_environment($binding,$context);
    m4c_expect(($values['PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT']??null)==='/run/audit-customer-form','CU_FORM_FIXED_ROLE_ROOT');
    audit_guard_assert();
    $source=$binding+['rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment']),
        'environment_native_sha256'=>m4c_entry_hash($evidence.$rule['proof'].'-environment-native.json')];
    m4fn_full_source($source,true);
    m4fn_prerequisites($source);
    return $source;
}
function m4fn_app(array $source): \think\App
{
    m4cb_phase('business_app');m4c_expect(empty($GLOBALS['m4c_app_started']),'M4C_ONE_REAL_APP_PER_NATIVE_PROCESS');
    $GLOBALS['m4c_app_started']=true;require_once '/workspace/crmeb/vendor/autoload.php';
    $config=audit_config();m4c_expect(($config['run_id']??null)===$source['run_id']&&($config['database']['username']??null)==='audit_app'
        &&getenv('APP_ENV')==='testing'&&getenv('PHP_MARKETPLACE_ENABLED')==='true'&&getenv('PHP_MARKETPLACE_TRADE_ENABLED')==='true'
        &&getenv('PHP_MARKETPLACE_PAYMENT_TEST_ENABLED')==='true'&&getenv('PHP_CACHE_DRIVER')==='redis',
        'M4C_REAL_ENABLED_PRIVATE_APP_CONFIGURATION');
    foreach($source['environment']as$name=>$value)m4c_expect(getenv($name)===$value,'M4C_SOURCE_BOUND_REAL_ENVIRONMENT_VALUE');
    m4c_expect(($config['auth']['session_epoch']??null)==='audit-http-'.$source['run_id']
        &&is_string($config['signing_key']??null)&&strlen($config['signing_key'])>=32
        &&is_string($config['auth']['stamp_key']??null)&&strlen($config['auth']['stamp_key'])>=32
        &&!hash_equals($config['signing_key'],$config['auth']['stamp_key'])&&is_string($config['auth']['issuer']??null),'M4C_REAL_PRIVATE_AUTH_BINDING');
    $app=new \think\App('/workspace/crmeb/');require_once '/workspace/crmeb/vendor/topthink/framework/src/helper.php';
    m4c_expect(function_exists('app')&&app()===$app&&!$app->initialized(),'M4C_REAL_APP_WITHOUT_SYNTHETIC_BOOTSTRAP');
    $runtime='/tmp/ms4-fulfillment-normal-'.$source['run_id'].'/';
    m4c_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4C_DISTINCT_OWN_NATIVE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'REDIS_REDIS_HOSTNAME'=>'audit-redis',
        'REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,'CACHE_CACHE_PREFIX'=>'c:',
        'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]);
    m4cf_expect(!file_exists('/workspace/crmeb/.env')&&!is_link('/workspace/crmeb/.env'),'OFFICIAL_INITIALIZE_NO_UNBOUND_ENV');
    $configured=$app->env->get();$app->initialize();
    foreach($configured as$name=>$value)m4cf_expect($app->env->get($name)===$value,'OFFICIAL_INITIALIZE_PRESERVES_ACTUAL_ENV');
    m4cf_expect($app->initialized(),'OFFICIAL_INITIALIZED_BEFORE_DATABASE_SETTINGS');
    $database=require '/workspace/crmeb/config/database.php';
    $database['connections']['mysql']['params']=[PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    $database['connections']['mysql']['break_reconnect']=false;$app->config->set($database,'database');
    date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));
    m4cf_expect($app->config->get('marketplace.customer_form.private_root')===M4CF_ROOT&&m4cf_enabled(),'LOADED_PRIVATE_ROOT_FROM_REAL_ENV');
    $self=MarketplaceOrderInput::unsigned($app->config->get('marketplace.self_operated_mer_id'),'self_operated_mer_id');
    m4c_expect($app->db instanceof \crmeb\compat\think\Db&&$app->config->get('marketplace.enabled')===true
        &&$app->config->get('marketplace.trade.enabled')===true&&$app->config->get('marketplace.payment.test_enabled')===true
        &&MarketplaceTradePolicy::v1CreateEnabled()===true
        &&$self>0&&$self===$source['self_operated_mer_id'],'M4C_LOADED_REAL_ENABLED_BOOLS_AND_SELF_ID');
    m4c_expect($app->config->get('cache.default')==='redis'&&$app->config->get('cache.stores.redis.type')==='redis'
        &&$app->config->get('cache.stores.redis.host')==='audit-redis'&&(string)$app->config->get('cache.stores.redis.port')==='6379'
        &&$app->config->get('cache.stores.redis.select')===0&&$app->config->get('cache.stores.redis.prefix')==='c:'
        &&$app->config->get('cache.stores.redis.password')===$config['redis_password']
        &&$app->env->get('app.app_key')===$config['signing_key']&&$app->config->get('auth.session_epoch')===$config['auth']['session_epoch']
        &&$app->config->get('auth.stamp_key')===$config['auth']['stamp_key']&&$app->config->get('auth.issuer')===$config['auth']['issuer'],
        'M4C_REAL_SAME_REDIS_AUTH_AND_CACHE_PREFIX');
    $row=m4cb_rows(audit_db(),'SELECT `mer_id` FROM `eb_marketplace_merchant` WHERE `mer_id` = ?',[$self]);
    m4c_expect(count($row)===1&&(int)$row[0]['mer_id']===$self,'M4C_ACTUAL_MS2_SELF_ROW_PRESENT');
    $GLOBALS['m4c_application']=$app;return $app;
}


function m4fn_public_unchanged(array $source):void
{
    foreach($source['prerequisites_sha256']as$name=>$hash)
        m4fn_expect(m4c_entry_hash('/run/audit/evidence/'.$name)===$hash,'TASK6_ALL_ORIGINAL_PUBLIC_BYTES_PRESERVED');
}
function m4fn_prerequisites(array $source):void
{
    $base='/run/audit/evidence/';$hashes=$source['prerequisites_sha256'];
    m4c_entry_keys($hashes,m4fn_prerequisite_names());m4fn_public_unchanged($source);
    $history=m4c_entry_ms5_history($source);$manifest=audit_manifest();$formal=$history['formal'];
    foreach(['run_id','commit_sha','project','guard_mode','isolation','network_internal','network','volumes','volume_identities']as$key)
        m4c_entry_same($formal[$key],$manifest[$key]);
    foreach(['audit-mysql','audit-redis']as$role)foreach(['id','image_id','address']as$key)
        m4fn_expect($formal['services'][$role][$key]===$manifest['services'][$role][$key],'TASK6_ORIGINAL_DATABASE_ENDPOINTS');
    $prepare=m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);
    $c3=m4c_entry_read($base.M4C_MODE['prepare']['binding']);
    $expected=['schema'=>1,'scope'=>M4C_MODE['prepare']['binding_scope'],'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'container_id'=>$prepare['id'],'native_created_sha256'=>$hashes['customer-prepare-created.json'],'freeze_sha256'=>$source['freeze_sha256'],
        'requested_repo_source'=>$source['requested_repo_source'],'requested_run_source'=>$source['requested_run_source'],
        'requested_prep_source'=>$source['requested_prep_source'],'entry_sha256'=>m4c_entry_hash('/workspace/'.M4C_ENTRY),
        'source_files'=>$source['freeze']['customer_source_files'],'prerequisites_sha256'=>array_intersect_key($hashes,
            array_flip(array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES)))];
    m4c_entry_same($c3,$expected);m4c_entry_same(m4cf_base_environment(m4c_entry_environment($c3,$source['context'],'customer-prepare')),m4cf_base_environment($source['environment']));
    $prepared=m4c_entry_read($base.'ms4-customer-prepare.json');
    m4fn_expect($prepared['schema']===1&&$prepared['scope']===M4C_MODE['prepare']['scope']&&$prepared['status']===M4C_MODE['prepare']['status']
        &&$prepared['run_id']===$source['run_id']&&$prepared['source_sha']===$source['source_sha']&&$prepared['container_id']===$prepare['id']
        &&$prepared['freeze_sha256']===$source['freeze_sha256']&&$prepared['native_created_sha256']===$hashes['customer-prepare-created.json']
        &&$prepared['source_binding_sha256']===m4c_entry_hash($base.M4C_MODE['prepare']['binding'])
        &&$prepared['environment_sha256']===m4c_entry_hash($base.M4C_MODE['prepare']['environment'])
        &&$prepared['phase']==='final_source'&&$prepared['native_exit']===0&&$prepared['full_source_unchanged']===true
        &&$prepared['child']===null&&$prepared['business']['HTTP_executed']===false&&$prepared['business']['order_creation_executed']===false
        &&$prepared['business']['fixture_counts']===['customers'=>1,'merchants'=>1,'products'=>2,'skus'=>3,'cart_rows'=>0,'orders'=>0],
        'TASK6_ACTUAL_UNCONSUMED_C3_PREPARATION');
    $paidCreated=m4c_entry_terminal($source,'paid-producer','audit-ms5-paid-producer',[M5PC_ENTRY,'produce']);
    m4fn_expect($manifest['services']['audit-ms5-paid-producer']['id']===$paidCreated['id']
        &&$manifest['services']['audit-ms5-paid-producer']['image_id']===M4C_IMAGE,'TASK6_REGISTERED_ORIGINAL_PAID_PRODUCER');
    $paidBinding=m4c_entry_read($base.'paid-producer-source-binding.json');
    $paidExpected=$expected;$paidExpected['scope']='MS5_PAID_PRODUCER_SOURCE';$paidExpected['container_id']=$paidCreated['id'];
    $paidExpected['native_created_sha256']=$hashes['paid-producer-created.json'];
    $paidExpected['entry_sha256']=m4c_entry_hash('/workspace/'.M5PC_ENTRY);
    $paidExpected['source_files']=$source['freeze']['paid_source_files'];
    $paidExpected['prerequisites_sha256']=array_intersect_key($hashes,array_flip(array_merge(M4C_COMMON_PREREQUISITES,
        M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES)));
    m4c_entry_same($paidBinding,$paidExpected);
    $paidExtra=array_merge(M4C_EXTRA,[M5PC_ENTRY]);sort($paidExtra,SORT_STRING);
    m4fn_expect($source['freeze']['paid_source_roots']===M4C_ROOTS&&$source['freeze']['paid_source_extra']===$paidExtra
        &&array_values(array_filter($source['freeze']['paid_source_files'],static fn(array $row):bool=>$row['path']!==M5PC_ENTRY))
            ===$source['freeze']['customer_source_files'],'TASK6_PAID_HISTORICAL_TEN_EXTRA_CLOSURE');
    $paidEnv=m4c_entry_read($base.'paid-producer-environment.json');$paidNative=m4c_entry_read($base.'paid-producer-environment-native.json');
    m4c_entry_keys($paidEnv,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4fn_expect($paidEnv['schema']===1&&$paidEnv['scope']==='MS5_PAID_PRODUCER_REAL_ENVIRONMENT'
        &&$paidEnv['run_id']===$source['run_id']&&$paidEnv['source_sha']===$source['source_sha']&&$paidEnv['container_id']===$paidCreated['id']
        &&$paidEnv['freeze_sha256']===$source['freeze_sha256']&&$paidEnv['native_projection_sha256']===$paidNative['stdout_sha256'],
        'TASK6_PAID_REAL_HISTORICAL_ENVIRONMENT');
    m4c_entry_same(m4c_entry_native_environment($paidEnv['environment'],$paidNative),$source['environment']);
    $paid=m4c_entry_read($base.'paid-producer-result.json');
    m4c_entry_keys($paid,['schema','scope','status','run_id','source_sha','container_id','freeze_sha256','native_created_sha256',
        'source_binding_sha256','environment_sha256','environment_native_sha256','phase','entry_return_code','termination','native_exit',
        'native_exit_observation','full_source_unchanged','child','metadata_observer','business','customer_paid_API_DOM','merchant_paid_API_DOM',
        'platform_paid_API_DOM','full_acceptance','stopped_samples','assertions','customer_form']);
    m4cf_paid_receipt_check($paid['customer_form']);
    m4fn_expect($paid['customer_form']['upload_child']['group']['leader_pid']!==$paid['child']['group']['leader_pid'],'TASK6_DISTINCT_NORMAL_UPLOAD_CHILD');
    m4fn_expect($paid['schema']===1&&$paid['scope']===M5PC_RULE['scope']&&$paid['status']===M5PC_RULE['status']
        &&$paid['run_id']===$source['run_id']&&$paid['source_sha']===$source['source_sha']&&$paid['container_id']===$paidCreated['id']
        &&$paid['freeze_sha256']===$source['freeze_sha256']&&$paid['native_created_sha256']===$hashes['paid-producer-created.json']
        &&$paid['source_binding_sha256']===$hashes['paid-producer-source-binding.json']
        &&$paid['environment_sha256']===$hashes['paid-producer-environment.json']
        &&$paid['environment_native_sha256']===$hashes['paid-producer-environment-native.json']
        &&$paid['phase']==='final_source'&&$paid['entry_return_code']===0&&$paid['termination']==='NORMAL_RETURN'
        &&$paid['native_exit']===null&&$paid['native_exit_observation']==='HOST_NATIVE_REQUIRED'
        &&$paid['full_source_unchanged']===true&&$paid['full_acceptance']==='INCOMPLETE'
        &&is_int($paid['assertions'])&&$paid['assertions']>0,'TASK6_PAID_RESULT_AND_HOST_TERMINAL_JOINT_PROOF');
    foreach(['customer_paid_API_DOM','merchant_paid_API_DOM','platform_paid_API_DOM','stopped_samples']as$key)
        m4fn_expect($paid[$key]==='NOT RUN','TASK6_PAID_SCOPE_NOT_REWRITTEN');
    $child=$paid['child'];m4c_entry_keys($child,['stopped','group','remaining_members','listener_open','stop_signal',
        'term_attempted','native_child','native_proc_close','normal_only']);
    m4fn_expect($child['stopped']===true&&$child['remaining_members']===[]&&$child['listener_open']===false
        &&$child['stop_signal']===SIGTERM&&$child['term_attempted']===true&&$child['normal_only']===true
        &&is_array($child['native_child'])&&$child['native_child']['running']===false&&$child['native_child']['termsig']!==9
        &&is_int($child['native_proc_close']),'TASK6_ORIGINAL_AUTH_CHILD_ALREADY_NORMALLY_CLOSED');
    m4c_entry_keys($child['group'],['leader_pid','pgid','sid','leader_starttime']);
    m4c_entry_keys($child['native_child'],['pid','running','signaled','stopped','exitcode','termsig','stopsig']);
    $leader=m5pcp_int($child['group']['leader_pid'],1);
    m4fn_expect($child['group']['pgid']===$leader&&$child['group']['sid']===$leader&&$child['native_child']['pid']===$leader
        &&m5pcp_u64($child['group']['leader_starttime'])!=='0'&&is_bool($child['native_child']['signaled'])
        &&$child['native_child']['stopped']===false&&$child['native_child']['stopsig']===0
        &&in_array($child['native_child']['exitcode'],[-1,0],true)&&in_array($child['native_child']['termsig'],[0,SIGTERM],true)
        &&in_array($child['native_proc_close'],[-1,0],true),'TASK6_ORIGINAL_AUTH_CHILD_SAME_REGISTERED_PID_NATIVE_NORMAL_TERMINAL');
    m4fn_migration($source,$paidCreated['id']);m4fn_public_unchanged($source);
    m4fn_expect(count(array_unique([$history['initializer_id'],$history['migration_id'],$history['files_id'],$prepare['id'],
        $paidCreated['id'],$source['container_id']],SORT_STRING))===6,'TASK6_DISTINCT_REAL_HISTORICAL_AND_CURRENT_CIDS');
}
function m4fn_migration(array $source,string $producerId):void
{
    $history=m4c_entry_early_history($source);$created=$history['created'];$terminal=$history['terminal'];$result=$history['result'];
    $id=$created['id'];
    m4fn_expect(m5pc_hex($id)&&$id!==$producerId&&$id!==$source['container_id']
        &&!isset(audit_manifest()['services']['audit-ms4-fulfillment-migration']),
        'TASK6_EARLY_MIGRATION_REAL_NATIVE_ZERO_REMOVED_BEFORE_FILES_AND_BUSINESS');
    m4c_entry_keys($history['hashes'],M4FN_MIGRATION_FILES);
    foreach(M4FN_MIGRATION_FILES as$name)m4fn_expect($history['hashes'][$name]===$source['prerequisites_sha256'][$name],
        'TASK6_SAME_FOUR_ACTUAL_EARLY_PUBLIC_INPUT_HASHES');
    // Keep the original complete four-command receipt oracle at its actual 24-column timepoint.
    foreach($result['children']as$index=>$child)m4fn_migration_child($child,$index,$history['binding']['cli_sha256']);
}

function m4fn_migration_child(array $child,int $index,string $cliHash):void
{
    m4c_entry_keys($child,['mode','pid','native_exit','proc_close_exit','same_handle_terminal','capture_error_class',
        'stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','result']);
    $mode=['check','apply-structure','check','apply-structure'][$index];$result=$child['result'];
    m4fn_expect($child['mode']===$mode&&is_int($child['pid'])&&$child['pid']>0&&$child['native_exit']===0
        &&is_int($child['proc_close_exit'])&&in_array($child['proc_close_exit'],[-1,0],true)&&$child['capture_error_class']===null
        &&is_int($child['stdout_bytes'])&&$child['stdout_bytes']>0&&$child['stdout_bytes']<=1048576&&m5pc_hex($child['stdout_sha256'])
        &&$child['stderr_bytes']===0&&$child['stderr_sha256']===hash('sha256','')&&is_array($result),
        'TASK6_MIGRATION_FULL_NATIVE_CAPTURE_ZERO');
    if($child['same_handle_terminal']!==null){
        m4c_entry_keys($child['same_handle_terminal'],['running','signaled','stopped','exitcode']);
        m4fn_expect($child['same_handle_terminal']===['running'=>false,'signaled'=>false,'stopped'=>false,'exitcode'=>0],
            'TASK6_MIGRATION_ACTUAL_SAME_HANDLE_TERMINAL');
    }
    else m4fn_expect($child['proc_close_exit']===0,'TASK6_MIGRATION_ACTUAL_PROC_CLOSE_ZERO');
    m4c_entry_keys($result,['mode','state','native_exit','changed','applied_steps','pending_steps','pending_steps_known','issues',
        'failure_phase','marker','source_sha256','scope','prerequisite_source_sha256','writer','acceptance']);
    $steps=['marketplace_fulfillment_operation','marketplace_fulfillment_range'];
    m4fn_expect($result['mode']===$mode&&$result['native_exit']===0&&$result['scope']==='MS4_FULFILLMENT_STRUCTURE_ONLY'
        &&$result['marker']==='marketplace-ms4-fulfillment-v1'&&$result['source_sha256']===$cliHash
        &&$result['state']===($index===0?'applicable':'completed')&&$result['changed']===($index===1)
        &&$result['applied_steps']===($index===1?$steps:[])&&$result['pending_steps']===($index===0?$steps:[])
        &&$result['pending_steps_known']===true&&$result['issues']===[]&&$result['failure_phase']===null
        &&$result['writer']==='NOT RUN'&&$result['acceptance']==='NOT RUN','TASK6_MIGRATION_FOUR_EXACT_CLI_RESULTS');
    $expected=[];foreach(['marketplace-ms1.php','marketplace-ms4.php','marketplace-ms5-payment.php']as$name)
        $expected['help/migrations/'.$name]=m4c_entry_hash('/workspace/help/migrations/'.$name);
    m4c_entry_same($result['prerequisite_source_sha256'],$expected);
    $stdout=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    m4fn_expect($child['stdout_bytes']===strlen($stdout)&&$child['stdout_sha256']===hash('sha256',$stdout),
        'TASK6_MIGRATION_COMPLETE_CLI_STDOUT_RECONSTRUCTION');
}
function m4fn_private_read(string $path,int $maximum):array
{
    m4fn_expect(in_array($path,[M4CB_FIXTURE,M5PC_BASELINE],true),'TASK6_FIXED_ORIGINAL_PRIVATE_INPUT');
    $parent=dirname($path);$parentStat=lstat($parent);clearstatcache(true,$path);$before=lstat($path);
    m4fn_expect(is_array($parentStat)&&is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent
        &&($parentStat['mode']&0777)===0700&&$parentStat['uid']===posix_geteuid()
        &&is_array($before)&&is_file($path)&&!is_link($path)&&realpath($path)===$path&&($before['mode']&0170000)===0100000
        &&($before['mode']&0777)===0600&&$before['uid']===posix_geteuid()&&$before['nlink']===1
        &&$before['size']>0&&$before['size']<=$maximum,'TASK6_ACTUAL_PRIVATE_ORIGINAL_INPUT_STAT');
    $raw=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);
    m4fn_expect(is_string($raw)&&strlen($raw)===$before['size']&&is_array($after),'TASK6_FULL_PRIVATE_ORIGINAL_INPUT');
    $keys=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    foreach($keys as$key)m4fn_expect($after[$key]===$before[$key],'TASK6_PRIVATE_INPUT_STABLE_DURING_READ');
    return ['path'=>$path,'raw'=>$raw,'sha256'=>hash('sha256',$raw),'stat'=>array_intersect_key($before,array_flip($keys)),
        'value'=>json_decode($raw,true,128,JSON_THROW_ON_ERROR)];
}
function m4fn_inputs_unchanged(array $inputs):void
{
    foreach($inputs as$input){$again=m4fn_private_read($input['path'],$input['path']===M5PC_BASELINE?2097152:1048576);
        m4fn_expect($again===$input,'TASK6_ALL_ORIGINAL_PRIVATE_STAT_HASH_BYTES_PRESERVED');}
}

function m4fn_owner(array $source,PDO $db):array
{
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
    $formOwner=m4cf_owner_after($value['customer_form'],$owner['actual_user'],$uid);
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



function m4fn_full_metadata(PDO $db, string $table): array
{
    $args = [$table];
    $tables = m5pcp_rows($db, 'SELECT TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $args);
    m4fn_expect(count($tables) === 1 && $tables[0]['TABLE_TYPE'] === 'BASE TABLE', 'COMPLETE_BASE_TABLE_METADATA');
    $columns = [];
    foreach (m5pcp_rows($db, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $row) $columns[$row['COLUMN_NAME']] = $row;
    $indexes = [];
    foreach (m5pcp_rows($db, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $args) as $row) $indexes[$row['INDEX_NAME']][] = $row;
    ksort($indexes, SORT_STRING);
    return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
        'constraints' => m5pcp_rows($db, 'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY BINARY CONSTRAINT_NAME', $args),
        'checks' => m5pcp_rows($db, "SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", $args),
        'foreign' => m5pcp_rows($db, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.POSITION_IN_UNIQUE_CONSTRAINT,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UNIQUE_CONSTRAINT_SCHEMA,r.UNIQUE_CONSTRAINT_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', $args),
        'triggers' => m5pcp_rows($db, 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', $args)];
}

function m4fn_restore_metadata(array $table):array
{
    m4c_entry_keys($table,['ddl_sha256','projected_columns','projection','metadata_sha256','metadata_serialized_base64']);
    $raw=base64_decode($table['metadata_serialized_base64'],true);
    m4fn_expect(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=2097152
        &&base64_encode($raw)===$table['metadata_serialized_base64']&&hash('sha256',$raw)===$table['metadata_sha256'],
        'TASK6_COMPLETE_ORIGINAL_NATIVE_METADATA_BYTES');
    $metadata=unserialize($raw,['allowed_classes'=>false,'max_depth'=>32]);
    m4fn_expect(is_array($metadata)&&serialize($metadata)===$raw,'TASK6_ORIGINAL_METADATA_NATIVE_TYPES_NO_OBJECTS');return $metadata;
}
function m4fn_migration_database(PDO $db,array $owner):void
{
    $result=m4c_entry_read('/run/audit/evidence/ms4-aftersale-early-migration-normal.json');
    $after=$result['snapshots']['assets'];
    m4c_entry_keys($after,['names','tables','redis','modes','metadata_observer_modes','native_snapshot_sha256']);$restored=[];
    $full=m5pcp_snapshot($db);m4fn_expect($full['names']===$after['names'],'TASK6_ACTUAL_POST_EARLY_ASSETS_TABLE_NAMESPACE');
    $targets=['eb_marketplace_aftersale_action','eb_marketplace_aftersale_file','eb_store_order_refund',
        'eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range'];
    foreach($after['tables']as$name=>$table){
        $metadata=m4fn_restore_metadata($table);
        $restored[$name]=['metadata'=>$metadata,'ddl_sha256'=>$table['ddl_sha256'],'projected_columns'=>$table['projected_columns'],
            'projection'=>$table['projection']];
        if(in_array($name,$targets,true))m4fn_expect(m4fn_full_metadata($db,$name)===$metadata
            &&hash('sha256',$full['tables'][$name]['ddl'])===$table['ddl_sha256']
            &&$full['tables'][$name]['projected_columns']===$table['projected_columns']
            &&$full['tables'][$name]['projection']===$table['projection'],'TASK6_FIVE_FINAL_STAGE20_TARGETS_NATIVE_SCHEMA_AND_ROWS_UNCHANGED');
    }
    $original=['names'=>$after['names'],'tables'=>$restored,'redis'=>$after['redis'],'modes'=>$after['modes'],
        'metadata_observer_modes'=>$after['metadata_observer_modes']];
    m4fn_expect(hash('sha256',serialize($original))===$after['native_snapshot_sha256'],
        'TASK6_COMPLETE_REAL_EARLY_METADATA_ARCHIVE_NATIVE_TYPES');
    $modes=m5pcp_rows($db,'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb');
    m4fn_expect($modes===$after['modes']&&m5pcp_observer_modes($db)===$after['metadata_observer_modes'],
        'TASK6_ORIGINAL_ALL_SIX_NATIVE_OBSERVER_MODES_UNCHANGED');
    foreach(['eb_marketplace_aftersale_action','eb_marketplace_aftersale_file','eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range']as$name)
        m4fn_expect($full['tables'][$name]['projection']['rows']===0&&m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($name))===[],
            'TASK6_ACTUAL_FOUR_PREBUSINESS_EMPTY_TABLES');
    // All four final tables already existed in the real paid producer's full native archive.
    // Compare the complete current DB/Redis to that post-paid baseline; never subtract tables or use pre-App rows as current facts.
    m4fn_expect(count($full['names'])===$owner['baseline']['full_database_table_count']
        &&hash('sha256',serialize($full))===$owner['baseline']['full_database_redis_sha256'],
        'TASK6_COMPLETE_ACTUAL_POSTPAID_DATABASE_AND_ABSOLUTE_REDIS_BASELINE');
}

function m4fn_snapshot(PDO $db):array
{
    $database=m5pcp_database_snapshot($db);$rows=[];
    foreach(M4FN_TABLES as$name){$order=$name==='eb_store_order_status'?'': ' ORDER BY `id`';
        $rows[$name]=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($name).$order);}
    return ['database'=>$database,'rows'=>$rows,'redis'=>m5pcp_atomic_redis(audit_redis()),'customer_form_private'=>m4cf_files()];
}
function m4fn_row_multiset(array $rows):array
{
    $hashes=[];foreach($rows as$row)$hashes[]=hash('sha256',serialize($row));sort($hashes,SORT_STRING);return $hashes;
}
function m4fn_projection(array $rows):array
{
    $hashes=m4fn_row_multiset($rows);return ['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>count($hashes),
        'sha256'=>hash('sha256',implode("\n",$hashes))];
}
function m4fn_zero(array $before,array $after):void
{
    m4fn_expect($before['customer_form_private']===$after['customer_form_private'],'TASK6_PRIVATE_ORIGINAL_IMAGES_UNCHANGED');
    m4fn_expect($before['database']===$after['database']&&$before['rows']===$after['rows']
        &&$before['redis']['keys']===$after['redis']['keys'],'TASK6_READ_OR_REPLAY_EXACT_ZERO_DATABASE_REDIS_DELTA');
}
function m4fn_delta(array $before,array $after,array $expectedRows,array $inserted=[],array $redisChanges=[]):void
{
    m4fn_expect($before['customer_form_private']===$after['customer_form_private'],'TASK6_PRIVATE_ORIGINAL_IMAGES_UNCHANGED');
    m4c_entry_keys($expectedRows,M4FN_TABLES);$expected=$before['database'];
    foreach(M4FN_TABLES as$name){$old=$before['database']['tables'][$name];$new=$after['database']['tables'][$name];
        if(isset($inserted[$name]))m5pcp_attempt_metadata_advance($old,$new,$inserted[$name]);
        else m4fn_expect($new['metadata']===$old['metadata']&&$new['ddl']===$old['ddl']
            &&$new['projected_columns']===$old['projected_columns'],'TASK6_TARGET_SCHEMA_COUNTERS_UNCHANGED');
        m4fn_expect(m4fn_row_multiset($after['rows'][$name])===m4fn_row_multiset($expectedRows[$name])
            &&$new['projection']===m4fn_projection($expectedRows[$name]),'TASK6_EVERY_NEW_AND_OLD_TARGET_ROW_EXACT');
        $expected['tables'][$name]=$new;
    }
    $expected['target_rows']['eb_store_order']=$after['rows']['eb_store_order'];
    m4fn_expect($after['database']===$expected,'TASK6_ALL_NON_TARGET_TABLES_PAYMENT_ROWS_MODES_METADATA_ABSOLUTELY_UNCHANGED');
    $redis=$before['redis']['keys'];
    foreach($redisChanges as$hash=>$change){
        m4fn_expect(m5pc_hex($hash)&&array_key_exists($hash,$after['redis']['keys'])&&$after['redis']['keys'][$hash]===$change,
            'TASK6_ONLY_EXACT_INDEPENDENTLY_VERIFIED_REDIS_TARGET');$redis[$hash]=$change;}
    ksort($redis,SORT_STRING);m4fn_expect($after['redis']['keys']===$redis,'TASK6_ALL_OTHER_REDIS_TYPES_VALUES_ABSOLUTE_EXPIRIES_UNCHANGED');
}
function m4fn_native_value(array $column,mixed $value):mixed
{
    if($value===null){m4fn_expect($column['IS_NULLABLE']==='YES','TASK6_REAL_NULLABLE_COLUMN');return null;}
    $type=strtolower($column['COLUMN_TYPE']);
    if(preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?\z/D',$type)===1){
        $digits=m5pcp_u64($value,true);return bccomp($digits,'9223372036854775807',0)<=0?(int)$digits:$digits;}
    m4fn_expect(is_string($value),'TASK6_SAVED_NONINTEGER_NATIVE_STRING');return $value;
}
function m4fn_expected_row(array $metadata,array $actual,array $values):array
{
    m4c_entry_keys($actual,array_keys($metadata['columns']));
    foreach(array_keys($values)as$key)m4fn_expect(isset($metadata['columns'][$key]),'TASK6_NO_UNDECLARED_EXPECTED_COLUMN');
    $expected=[];
    foreach($metadata['columns']as$key=>$column){
        $value=array_key_exists($key,$values)?$values[$key]:$column['COLUMN_DEFAULT'];
        if(is_array($value)){
            m4c_entry_keys($value,['json']);m5pcp_json_expected($actual[$key],$value['json']);$expected[$key]=$actual[$key];
        }else $expected[$key]=m4fn_native_value($column,$value);
    }
    m4fn_expect($actual===$expected,'TASK6_FULL_NATIVE_ROW_WITH_INDEPENDENT_VALUES_AND_ALL_DECLARED_DEFAULTS');return $expected;
}
function m4fn_insert_fixture(PDO $db,string $table,array $values,array $before):array
{
    m4fn_expect(in_array($table,['eb_marketplace_role','eb_marketplace_employee','eb_marketplace_password_setup','eb_system_config'],true)
        &&!$db->inTransaction(),'TASK6_FIXED_ORDINARY_FIXTURE_ONLY');
    $columns=array_keys($values);$sql='INSERT INTO '.m5pcp_quote($table).' ('.implode(',',array_map('m5pcp_quote',$columns)).') VALUES ('
        .implode(',',array_fill(0,count($columns),'?')).')';
    $statement=$db->prepare($sql);m4fn_expect($statement instanceof PDOStatement,'TASK6_FIXTURE_PREPARE');
    foreach(array_values($values)as$i=>$value)m4fn_expect($statement->bindValue($i+1,$value===null?null:(string)$value,
        $value===null?PDO::PARAM_NULL:PDO::PARAM_STR),'TASK6_FIXTURE_NATIVE_BIND');
    m4fn_expect($statement->execute()&&$statement->rowCount()===1,'TASK6_FIXTURE_REAL_ONE_INSERT');
    $id=m5pcp_u64($db->lastInsertId());m4fn_expect($id===m5pcp_u64($before['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']),
        'TASK6_NATURAL_FIXTURE_PRIMARY_KEY');
    $rows=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table).' WHERE `id` = ?',[$id]);m4fn_expect(count($rows)===1,'TASK6_REAL_FIXTURE_READBACK');
    if(isset($values['permissions']))$values['permissions']=['json'=>json_decode($values['permissions'],true,32,JSON_THROW_ON_ERROR)];
    return m4fn_expected_row($before['database']['tables'][$table]['metadata'],$rows[0],['id'=>$id]+$values);
}
function m4fn_context(\think\App $app,\think\db\ConnectionInterface $connection,PDO $default,PDO $db):void
{
    m4fn_expect(app()===$app&&$GLOBALS['m4c_application']===$app&&$connection instanceof \crmeb\compat\think\orm\db\connector\Mysql
        &&Db::connect()===$connection&&$connection->getPdo()===$default&&$connection->transactionDepth()===0
        &&$default!==$db&&!$default->inTransaction()&&!$db->inTransaction(),'TASK6_ACTUAL_ORIGINAL_APP_DEFAULT_INDEPENDENT_PDO_DEPTH_ZERO');
}


function m4fn_money(mixed $value):string
{
    m4fn_expect(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D',$value)===1,'TASK6_SAVED_EXACT_DECIMAL');return $value;
}
function m4fn_text(mixed $value):string
{
    m4fn_expect(is_string($value)&&preg_match('//u',$value)===1&&!str_contains($value,"\0"),'TASK6_SAVED_TEXT');return $value;
}
function m4fn_forms(string $raw,array $products):array
{
    $groups=json_decode($raw,true,32,JSON_THROW_ON_ERROR);m4fn_expect(is_array($groups)&&array_is_list($groups),'TASK6_SAVED_FORM_LIST');
    $out=[];$seen=[];
    foreach($groups as$group){$id=m5pcp_int($group['product_id']??null,1);
        m4fn_expect(in_array($id,$products,true)&&!isset($seen[$id])&&m5pc_hex($group['definition_sha256']??null)
            &&is_array($group['fields'])&&array_is_list($group['fields'])&&count($group['fields'])>=1&&count($group['fields'])<=10,
            'TASK6_SAVED_FORM_IDENTITY');$fields=[];
        foreach($group['fields']as$index=>$field){m4fn_expect(($field['field_index']??null)===$index&&is_bool($field['status']??null)
            &&in_array($field['label']??null,['text','number','email','data','time','id','phone','img'],true),'TASK6_SAVED_FORM_FIELD');
            $value=$field['value'];if($field['label']==='img'){m4fn_expect(is_array($value)&&array_is_list($value)&&count($value)<=8,
                'TASK6_SAVED_IMAGE_LIST');foreach($value as$url)m4fn_text($url);}else $value=m4fn_text($value);
            $fields[]=['field_index'=>$index,'title'=>m4fn_text($field['title']),'label'=>$field['label'],'status'=>$field['status'],'value'=>$value];}
        $out[]=['product_id'=>$id,'product_name'=>m4fn_text($group['product_name']),'definition_sha256'=>$group['definition_sha256'],'fields'=>$fields];$seen[$id]=true;
    }return $out;
}
function m4fn_source_oracle(array $saved):array
{
    $root=$saved['order'];$uid=m5pcp_int($root['uid'],1);$mer=m5pcp_int($root['mer_id'],1,4294967295);$id=m5pcp_int($root['id'],1);
    $identity=['id'=>$id,'order_id'=>$root['order_id'],'uid'=>$uid,'mer_id'=>$mer,'total_num'=>m5pcp_int($root['total_num'],1,4294967295),
        'total_price'=>m4fn_money($root['total_price']),'pay_price'=>m4fn_money($root['pay_price']),'pay_postage'=>m4fn_money($root['pay_postage']),
        'total_postage'=>m4fn_money($root['total_postage']),'cost'=>m4fn_money($root['cost'])];
    m4fn_expect(count($saved['items'])===2&&count($saved['reserves'])===2&&$identity['total_num']===3,'TASK6_ACTUAL_TWO_PLUS_ONE_SOURCE');
    $lines=[];$display=[];$products=[];$quantity=0;$totals=['goods'=>'0.00','postage'=>'0.00','cost'=>'0.00'];$q2=$q1=null;
    foreach($saved['items']as$item){$itemId=m5pcp_int($item['id'],1);$product=m5pcp_int($item['product_id'],1,8388607);$q=m5pcp_int($item['cart_num'],1);
        m4fn_expect(in_array($q,[1,2],true)&&m5pcp_int($item['oid'],1)===$id&&m5pcp_int($item['uid'],1)===$uid
            &&m5pcp_int($item['mer_id'],1,4294967295)===$mer&&m5pcp_int($item['refund_num'],0,0)===0
            &&m5pcp_int($item['surplus_num'],1)===$q&&m5pcp_int($item['split_status'],0,0)===0,'TASK6_ORIGINAL_ITEM_NATIVE_OWNER_QUANTITY_FLAGS');
        $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$p=$cart['productInfo'];$sku=$p['attrInfo'];$lineage=$cart['marketplace'];
        m4c_entry_keys($lineage,['cart_ref','sku_id','sku_type','sku_unique','reserve_id']);
        $skuId=m5pcp_int($sku['id'],1);$ref=m4fn_text($item['cart_id']);$reserve=m5pcp_u64($lineage['reserve_id']);
        m4fn_expect($cart['id']===$ref&&$lineage['cart_ref']===$ref&&m5pcp_int($cart['cart_num'],1)===$q
            &&m5pcp_int($cart['uid'],1)===$uid&&m5pcp_int($cart['product_id'],1)===$product&&m5pcp_int($p['id'],1)===$product
            &&m5pcp_int($p['mer_id'],1,4294967295)===$mer&&m5pcp_int($sku['product_id'],1)===$product
            &&m5pcp_int($lineage['sku_id'],1)===$skuId&&m5pcp_int($sku['type'],0,0)===0
            &&m5pcp_int($lineage['sku_type'],0,0)===0&&$lineage['sku_unique']===$sku['unique']
            &&is_string($lineage['reserve_id'])&&preg_match('/\A[A-Za-z0-9]{8}\z/D',$sku['unique'])===1,'TASK6_ORIGINAL_SAVED_CART_SKU_RESERVE_LINEAGE');
        $unit=m4fn_money($cart['truePrice']);$goods=m4fn_money($cart['sum_true_price']);$postage=m4fn_money($cart['postage_price']);
        $base=m4fn_money($cart['sum_price']);$discount=m4fn_money($cart['vip_truePrice']);$cost=m4fn_money($cart['costPrice']);
        m4fn_expect(bcmul($unit,(string)$q,2)===$goods&&bcadd($unit,$discount,2)===$base&&$sku['price']===$base&&$sku['cost']===$cost
            &&$cart['origin_postage_price']===$postage&&in_array($cart['price_type'],['level','member'],true),'TASK6_ORIGINAL_Q_BUDGETS_NOT_REPRICED');
        $line=['source_item_id'=>$itemId,'product_id'=>$product,'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$sku['unique'],
            'cart_ref'=>$ref,'reserve_id'=>$reserve,'original_quantity'=>$q,'unit_price'=>$unit,'price_type'=>$cart['price_type'],
            'goods'=>$goods,'postage'=>$postage,'origin_postage'=>$postage,'cost'=>bcmul($cost,(string)$q,2),
            'base_goods'=>bcmul($base,(string)$q,2),'discount'=>bcmul($discount,(string)$q,2)];
        $image=m4fn_text($sku['image']);$public=['id'=>$itemId,'product_id'=>$product,'product_name'=>m4fn_text($p['store_name']),
            'sku_name'=>m4fn_text($sku['suk']),'image'=>$image!==''?$image:m4fn_text($p['image']),'quantity'=>$q,
            'unit_price'=>$unit,'line_price'=>$goods,'postage_price'=>$postage];
        $matched=[];foreach($saved['reserves']as$row)if(m5pcp_u64($row['id'])===$reserve)$matched[]=$row;
        m4fn_expect(count($matched)===1,'TASK6_ONE_REAL_ORIGINAL_RESERVE_PER_SAVED_LINE');$r=$matched[0];
        foreach(['mer_id'=>$mer,'order_id'=>$id,'product_id'=>$product,'sku_id'=>$skuId,'sku_type'=>0,'qty'=>$q,'actor_id'=>$uid]as$key=>$value)
            m4fn_expect(m5pcp_int($r[$key],0,4294967295)===$value,'TASK6_ORIGINAL_RESERVE_ALL_QUANTITY_AND_IDENTITY');
        m4fn_expect($r['kind']==='reserve'&&$r['actor_type']==='customer'&&$r['sku_unique']===$sku['unique']
            &&$r['parent_reserve_id']===null&&$r['order_item_id']===null&&$r['refund_id']===null,'TASK6_ORIGINAL_RESERVE_NOT_RELEASED_OR_REUSED');
        if($q===2){m4fn_expect($q2===null,'TASK6_ONLY_ONE_Q2_ITEM');$q2=$itemId;}else{m4fn_expect($q1===null,'TASK6_ONLY_ONE_Q1_ITEM');$q1=$itemId;}
        $lines[$itemId]=$line;$display[$itemId]=$public;$products[$product]=true;$quantity+=$q;
        foreach(['goods','postage','cost']as$key)$totals[$key]=bcadd($totals[$key],$line[$key],2);
    }
    m4fn_expect($q2!==null&&$q1!==null&&$quantity===3&&$totals['goods']===$identity['total_price']
        &&$totals['postage']===$identity['pay_postage']&&$identity['total_postage']===$identity['pay_postage']
        &&bcadd($totals['goods'],$totals['postage'],2)===$identity['pay_price']&&$totals['cost']===$identity['cost'],
        'TASK6_INDEPENDENT_FULL_ROOT_CONSERVATION');
    ksort($lines,SORT_NUMERIC);ksort($display,SORT_NUMERIC);$forms=m4fn_forms($root['custom_form'],array_keys($products));
    $fingerprint=hash('sha256',m5pcp_canonical(['root'=>$identity,'items'=>array_values($lines),'display'=>array_values($display),'custom_form'=>$forms]));
    return ['saved'=>$saved,'identity'=>$identity,'lines'=>$lines,'display'=>array_values($display),'forms'=>$forms,
        'fingerprint'=>$fingerprint,'q2'=>$q2,'q1'=>$q1];
}
function m4fn_slice(string $budget,int $q,int $start,int $end):string
{
    m4fn_money($budget);m4fn_expect($q>0&&$start>=0&&$end>$start&&$end<=$q,'TASK6_ORIGINAL_Q_ABSOLUTE_INTERVAL');
    $cents=bcmul($budget,'100',0);$base=bcdiv($cents,(string)$q,0);$extra=bcmod($cents,(string)$q,0);
    $a=bcadd(bcmul($base,(string)$start,0),bccomp((string)$start,$extra,0)<0?(string)$start:$extra,0);
    $b=bcadd(bcmul($base,(string)$end,0),bccomp((string)$end,$extra,0)<0?(string)$end:$extra,0);
    return bcdiv(bcsub($b,$a,0),'100',2);
}
function m4fn_plan(array $source,int $itemId,int $start,int $end):array
{
    $line=$source['lines'][$itemId];$a=[];
    foreach(['source_item_id','product_id','sku_id','sku_type','sku_unique','cart_ref','reserve_id','original_quantity','unit_price','price_type']as$key)$a[$key]=$line[$key];
    $a+=['unit_start'=>$start,'unit_end'=>$end,'quantity'=>$end-$start];
    foreach(['goods','postage','origin_postage','cost','base_goods','discount']as$key)$a[$key]=m4fn_slice($line[$key],$line['original_quantity'],$start,$end);
    $a['payable']=bcadd($a['goods'],$a['postage'],2);
    $totals=['quantity'=>$a['quantity'],'goods'=>$a['goods'],'postage'=>$a['postage'],'origin_postage'=>$a['origin_postage'],'payable'=>$a['payable'],
        'cost'=>$a['cost'],'base_goods'=>$a['base_goods'],'discount'=>$a['discount'],
        'level_discount'=>$a['price_type']==='level'?$a['discount']:'0.00','member_discount'=>$a['price_type']==='member'?$a['discount']:'0.00'];
    return ['plan_version'=>1,'root_order_id'=>$source['identity']['id'],'source_snapshot_sha256'=>$source['fingerprint'],'allocations'=>[$a],'totals'=>$totals];
}
function m4fn_summary(int $shipped,int $received,int $children):array
{
    return ['shipping_state'=>$shipped===0?'unshipped':($shipped<3?'partial':'shipped'),
        'receipt_state'=>$shipped===0?'not_shipped':($received===$shipped?'received':($received===0?'pending':'partial')),
        'closed'=>$shipped===3&&$received===3,'original'=>3,'shipped'=>$shipped,'received'=>$received,'cancelled_unshipped'=>0,
        'returned_accepted'=>0,'to_ship'=>3-$shipped,'to_receive'=>$shipped-$received,'child_count'=>$children];
}
function m4fn_merchant_oracle(array $source,array $root,array $summary,bool $detail):array
{
    $dto=['id'=>$source['identity']['id'],'order_id'=>$root['order_id'],'marketplace_trade_version'=>1,
        'add_time'=>m5pcp_int($root['add_time'],1,4294967295),'paid'=>1,'pay_type'=>'marketplace_test','status'=>m5pcp_int($root['status'],0,2),
        'is_cancel'=>0,'refund_status'=>0,'shipping_type'=>1,'total_num'=>3,'total_price'=>$source['identity']['total_price'],
        'pay_price'=>$source['identity']['pay_price'],'pay_postage'=>$source['identity']['pay_postage'],'items'=>$source['display']];
    if($detail){$dto['recipient']=['real_name'=>$root['real_name'],'user_phone'=>$root['user_phone'],'user_address'=>$root['user_address']];
        $dto['mark']=$root['mark'];$dto['custom_form']=$source['forms'];}
    $dto['fulfillment_summary']=$summary;$dto['allowed_actions']=['ship'=>$summary['to_ship']>0,'receive'=>false];return $dto;
}


function m4fn_replace_row(array $rows,array $replacement):array
{
    $found=0;foreach($rows as$i=>$row)if(m5pcp_u64($row['id'])===m5pcp_u64($replacement['id'])){$rows[$i]=$replacement;$found++;}
    m4fn_expect($found===1,'TASK6_ONE_ACTUAL_SAVED_REPLACEMENT');return $rows;
}
function m4fn_redis_hash(string $key,array $fields,int $expiry,array $snapshot):array
{
    $hash=hash('sha256',$key);$native=$snapshot['keys'][$hash]??null;
    m4fn_expect(is_array($native)&&$native['type']==='hash'&&$native['expires']===$expiry,'TASK6_EXACT_AUTH_KEY_TYPE_ABSOLUTE_EXPIRY');
    $actual=audit_redis()->hGetAll($key);m4fn_expect(is_array($actual),'TASK6_NATIVE_AUTH_HASH_REPLY');
    ksort($actual,SORT_STRING);ksort($fields,SORT_STRING);m4fn_expect($actual===$fields,'TASK6_COMPLETE_AUTH_HASH_FIELDS');
    $again=m5pcp_atomic_redis(audit_redis());m4fn_expect($again['keys']===$snapshot['keys'],'TASK6_AUTH_READS_NO_REDIS_WRITES_OR_TTL_RENEWAL');return $native;
}
function m4fn_actor(\think\App $app,PDO $db,array $source,\think\db\ConnectionInterface $connection,PDO $default):array
{
    $mer=$source['identity']['mer_id'];$permissions=['merchant.profile.read','order.read','order.ship'];$now=time();
    $before=m4fn_snapshot($db);$rows=$before['rows'];$suffix=bin2hex(random_bytes(8));
    $role=m4fn_insert_fixture($db,'eb_marketplace_role',['mer_id'=>$mer,'name'=>'普通履约 '.$suffix,
        'permissions'=>json_encode($permissions,JSON_THROW_ON_ERROR),'add_time'=>$now,'update_time'=>$now],$before);
    $rows['eb_marketplace_role'][]=$role;$after=m4fn_snapshot($db);
    m4fn_delta($before,$after,$rows,['eb_marketplace_role'=>m5pcp_u64($role['id'])]);$before=$after;$rows=$before['rows'];
    $employee=m4fn_insert_fixture($db,'eb_marketplace_employee',['mer_id'=>$mer,'role_id'=>$role['id'],
        'account'=>'ms4_fulfillment_'.$suffix,'pwd'=>null,'name'=>'普通履约','avatar'=>'','status'=>1,'is_del'=>0,
        'add_time'=>$now,'update_time'=>$now],$before);
    $rows['eb_marketplace_employee'][]=$employee;$after=m4fn_snapshot($db);
    m4fn_delta($before,$after,$rows,['eb_marketplace_employee'=>m5pcp_u64($employee['id'])]);$before=$after;$rows=$before['rows'];
    $capability=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$password='Ms4Fulfillment-'.bin2hex(random_bytes(24));
    $setup=m4fn_insert_fixture($db,'eb_marketplace_password_setup',['mer_id'=>$mer,'employee_id'=>$employee['id'],
        'token_hash'=>hash('sha256',$capability,true),'expires_at'=>$now+86400,'consumed_at'=>null,'add_time'=>$now],$before);
    $rows['eb_marketplace_password_setup'][]=$setup;$after=m4fn_snapshot($db);
    m4fn_delta($before,$after,$rows,['eb_marketplace_password_setup'=>m5pcp_u64($setup['id'])]);$before=$after;
    $epoch=$app->config->get('auth.session_epoch');$stamp=$app->config->get('auth.stamp_key');
    m4fn_expect(is_string($epoch)&&$epoch!==''&&is_string($stamp)&&$stamp!=='','TASK6_REAL_LOADED_AUTH_CONFIGURATION');
    $accountKey='auth:v2:'.$epoch.':merchantapi:account:'.m5pcp_u64($employee['id']);$accountHash=hash('sha256',$accountKey);
    m4fn_expect(!isset($before['redis']['keys'][$accountHash]),'TASK6_TRULY_NEW_EMPLOYEE_ACCOUNT');
    $auth=$app->make(EmployeeAuthServices::class);$started=time();$auth->setupPassword($capability,$password);$ended=time();
    m4fn_context($app,$connection,$default,$db);$after=m4fn_snapshot($db);$rows=$before['rows'];
    $fresh=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_employee` WHERE `id` = ?',[$employee['id']]);
    $freshSetup=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_password_setup` WHERE `id` = ?',[$setup['id']]);
    m4fn_expect(count($fresh)===1&&count($freshSetup)===1,'TASK6_REAL_PASSWORD_SETUP_SAVED_ROWS');
    $employeeNow=$fresh[0];$setupNow=$freshSetup[0];
    m4fn_expect(is_string($employeeNow['pwd'])&&password_verify($password,$employeeNow['pwd'])
        &&m5pcp_int($employeeNow['update_time'],1,4294967295)>=$started&&m5pcp_int($employeeNow['update_time'],1,4294967295)<=$ended
        &&m5pcp_int($setupNow['consumed_at'],1,4294967295)>=$started&&m5pcp_int($setupNow['consumed_at'],1,4294967295)<=$ended,
        'TASK6_REAL_PASSWORD_HASH_AND_SINGLE_CAPABILITY_CONSUMPTION');
    $expectedEmployee=$employee;$expectedEmployee['pwd']=$employeeNow['pwd'];$expectedEmployee['update_time']=$employeeNow['update_time'];
    $expectedSetup=$setup;$expectedSetup['consumed_at']=$setupNow['consumed_at'];
    m4fn_expect($employeeNow===$expectedEmployee&&$setupNow===$expectedSetup,'TASK6_SETUP_ONLY_OWN_FRESH_PASSWORD_AND_CONSUMPTION_FIELDS');
    $rows['eb_marketplace_employee']=m4fn_replace_row($rows['eb_marketplace_employee'],$employeeNow);
    $rows['eb_marketplace_password_setup']=m4fn_replace_row($rows['eb_marketplace_password_setup'],$setupNow);
    $account=m4fn_redis_hash($accountKey,['initialized'=>'0','blocked'=>'0'],-1,$after['redis']);
    m4fn_delta($before,$after,$rows,[],[$accountHash=>$account]);$before=$after;
    $started=time();$issued=$auth->login($employee['account'],$password);$ended=time();m4c_entry_keys($issued,['token','exp']);
    m4fn_expect(is_string($issued['token'])&&is_int($issued['exp']),'TASK6_ACTUAL_CREDENTIAL_LOGIN_RESULT');
    $pieces=explode('.',$issued['token']);m4fn_expect(count($pieces)===3,'TASK6_ACTUAL_JWT_THREE_PARTS');
    $decode=static function(string $part):array{$raw=base64_decode(strtr($part,'-_','+/').str_repeat('=',(4-strlen($part)%4)%4),true);
        m4fn_expect(is_string($raw)&&rtrim(strtr(base64_encode($raw),'+/','-_'),'=')===$part,'TASK6_CANONICAL_JWT_PART');
        $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);m4fn_expect(is_array($value)&&!array_is_list($value),'TASK6_NATIVE_JWT_OBJECT');return $value;};
    $claims=$decode($pieces[1]);$sid=$claims['sid']??null;$ttl=$app->config->get('auth.apps.merchantapi.ttl');
    m4fn_expect(is_string($sid)&&preg_match('/\A[a-f0-9]{32}\z/D',$sid)===1&&is_int($ttl)&&$ttl>0
        &&is_int($claims['iat']??null)&&$claims['iat']>=$started&&$claims['iat']<=$ended&&$claims['nbf']===$claims['iat']
        &&$claims['exp']===$claims['iat']+$ttl&&$issued['exp']===$claims['exp'],'TASK6_REAL_JWT_NATIVE_SID_AND_ACTUAL_LOADED_TTL');
    $after=m4fn_snapshot($db);$accountActual=audit_redis()->hGetAll($accountKey);$version=$accountActual['session_version']??null;
    m4fn_expect(is_string($version)&&preg_match('/\A[a-f0-9]{32}\z/D',$version)===1,'TASK6_REAL_RANDOM_ACCOUNT_SESSION_VERSION');
    $account=m4fn_redis_hash($accountKey,['initialized'=>'1','blocked'=>'0','session_version'=>$version],-1,$after['redis']);
    $sessionKey='auth:v2:'.$epoch.':merchantapi:session:'.$sid;$sessionHash=hash('sha256',$sessionKey);
    m4fn_expect(!isset($before['redis']['keys'][$sessionHash]),'TASK6_ONLY_NEW_ACTUAL_LOGIN_SESSION');
    $session=m4fn_redis_hash($sessionKey,['subject_id'=>m5pcp_u64($employee['id']),'session_version'=>$version,'type'=>'merchant',
        'audience'=>'tianli:merchantapi','token_digest'=>hash('sha256',$issued['token']),
        'credential_stamp'=>hash_hmac('sha256','merchantapi|'.m5pcp_u64($employee['id']).'|'.$employeeNow['pwd'],$stamp),
        'expires_at'=>(string)$claims['exp'],'status'=>'active'],($claims['exp']+60)*1000,$after['redis']);
    m4fn_delta($before,$after,$before['rows'],[],[$accountHash=>$account,$sessionHash=>$session]);$before=$after;
    $ctx=$auth->authenticate($issued['token']);m4fn_context($app,$connection,$default,$db);
    m4fn_expect($ctx instanceof MerchantContext&&$ctx->merId===$mer&&$ctx->employeeId===m5pcp_int($employee['id'],1,4294967295)
        &&$ctx->permissions===$permissions&&$ctx->sessionVersion===$version&&$ctx->requestId!=='','TASK6_REAL_AUTHENTICATED_MERCHANT_CONTEXT');
    m4fn_zero($before,m4fn_snapshot($db));unset($password,$capability,$issued,$claims,$pieces,$stamp);
    return ['context'=>$ctx,'employee_id'=>$ctx->employeeId,'role_id'=>m5pcp_int($role['id'],1,4294967295),
        'account_key_sha256'=>$accountHash,'session_key_sha256'=>$sessionHash];
}
function m4fn_configure(PDO $db):array
{
    m4fn_expect(m5pcp_rows($db,'SELECT * FROM `eb_system_config` WHERE `menu_name` = ?',['system_delivery_time'])===[],
        'TASK6_ACTUAL_CONFIG_KEY_ABSENT_BEFORE_INSERT');$before=m4fn_snapshot($db);
    $row=m4fn_insert_fixture($db,'eb_system_config',['menu_name'=>'system_delivery_time','type'=>'text','value'=>'0'],$before);
    $rows=$before['rows'];$rows['eb_system_config'][]=$row;$after=m4fn_snapshot($db);
    m4fn_delta($before,$after,$rows,['eb_system_config'=>m5pcp_u64($row['id'])]);
    $actual=app()->make(\app\dao\system\config\SystemConfigDao::class)->getConfigAll(['system_delivery_time']);
    m4fn_expect($actual===['system_delivery_time'=>'0'],'TASK6_REAL_JSON_ZERO_DUE_TIME_SQL_CONFIGURATION');
    m4fn_zero($after,m4fn_snapshot($db));return ['id'=>m5pcp_int($row['id'],1,4294967295),'raw_value'=>'0','receipt_due_at'=>0];
}

function m4fn_cf_sources(\think\App $app,array $children):array
{
    m4fn_expect(PHP_INT_SIZE===8&&app()===$app&&date_default_timezone_get()==='Asia/Shanghai'
        &&$app->config->get('app.default_timezone')==='Asia/Shanghai'&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('cache.stores.redis.type')==='redis'&&count($children)>=1&&count($children)<=2,
        'TASK6_ACTUAL_CF_SEQUENCE_ENVIRONMENT_AND_PRODUCER_COUNT');
    $epoch=bcmul((string)strtotime('2022-01-01'),'1000',0);m4fn_expect($epoch==='1640966400000','TASK6_REAL_CF_EPOCH');$keys=[];
    foreach($children as$child){$number=$child['order_id'];m4fn_expect(is_string($number)&&str_starts_with($number,'cf'),'TASK6_ACTUAL_CF_SAVED_PREFIX');
        $suffix=substr($number,2);m4fn_expect(preg_match('/\A[1-9][0-9]{0,18}\z/D',$suffix)===1
            &&bccomp($suffix,'9223372036854775807',0)<=0,'TASK6_ACTUAL_CF_SUFFIX');
        $offset=bcdiv($suffix,'4194304',0);$key=bcadd($epoch,$offset,0);$hash=hash('sha256',$key);
        m4fn_expect(bccomp($offset,'2199023255551',0)<=0&&preg_match('/\A[1-9][0-9]{12}\z/D',$key)===1,'TASK6_EXACT_CF_UNPREFIXED_MILLISECOND_KEY');
        $prior=count($keys[$hash]['sources']??[]);$sequence=bcmod($suffix,'4096',0);
        m4fn_expect($sequence===($prior===0?'0':'2'),'TASK6_REAL_SEQUENCE_ZERO_THEN_TWO_PER_MILLISECOND');
        if(!isset($keys[$hash]))$keys[$hash]=['key'=>$key,'sources'=>[]];
        $keys[$hash]['sources'][]=['source_kind'=>'fulfillment_child','identifier_sha256'=>hash('sha256',$number),
            'child_order_id'=>m5pcp_int($child['id'],1),'sequence'=>$sequence];
    }
    return ['epoch_ms'=>$epoch,'source_id_count'=>count($children),'keys'=>$keys];
}

function m4fn_cf_barrier(PDO $db,array $sequence): array
{
    m4fn_expect(m5pcp_keys($sequence,['epoch_ms','source_id_count','keys'])&&$sequence['epoch_ms']==='1640966400000'
        &&$sequence['source_id_count']>=1&&$sequence['source_id_count']<=2&&is_array($sequence['keys'])&&count($sequence['keys'])>=1
        &&count($sequence['keys'])<=2,'EXACT_SEQUENCE_BARRIER_INPUT');
    $started=hrtime(true);m4fn_expect(is_int($started)&&$started>0,'REAL_MONOTONIC_SEQUENCE_BARRIER_START');$lastNative=$started;
    $bounded=static function()use($started,&$lastNative):int{
        $now=hrtime(true);m4fn_expect(is_int($now)&&$now>=$lastNative&&$now-$started<=10000000000,
            'TEN_SECOND_MONOTONIC_SEQUENCE_BARRIER_BOUND');$lastNative=$now;return $now;
    };
    $redis=audit_redis();$bounded();$first=m5pcp_atomic_redis($redis);$bounded();
    $others=$first['keys'];$states=[];
    foreach($sequence['keys']as$keyHash=>$target){
        m4fn_expect(is_string($keyHash)&&is_array($target)&&m5pcp_keys($target,['key','sources'])
            &&is_string($target['key'])&&hash('sha256',$target['key'])===$keyHash
            &&is_array($target['sources'])&&array_is_list($target['sources'])&&$target['sources']!==[],
            'EXACT_INTERNAL_SEQUENCE_TARGET');
        $initial=$first['keys'][$keyHash]??null;
        if($initial!==null)m4fn_expect($initial['type']==='string'&&$initial['expires']>0
            &&bccomp((string)$initial['expires'],$first['time_ms'],0)>=0
            &&bccomp(bcsub((string)$initial['expires'],$first['time_ms'],0),'1000',0)<=0,
            'REAL_SEQUENCE_STRING_AND_FIRST_OBSERVED_ONE_SECOND_EXPIRY');
        if($initial!==null){
            $counter=$redis->rawCommand('EVAL_RO',"local t=redis.call('TIME'); return {t,redis.call('GET',KEYS[1]),redis.call('DUMP',KEYS[1]),redis.call('PEXPIRETIME',KEYS[1])}",1,$target['key']);
            m4fn_expect(is_array($counter)&&array_is_list($counter)&&count($counter)===4&&is_array($counter[0])
                &&count($counter[0])===2&&is_string($counter[0][0])&&is_string($counter[0][1]),'TASK6_CF_NATIVE_ATOMIC_COUNTER_OBSERVATION');
            $counterTime=bcadd(bcmul($counter[0][0],'1000',0),bcdiv($counter[0][1],'1000',0),0);
            if(is_string($counter[1]))m4fn_expect($counter[1]===(string)count($target['sources'])&&is_string($counter[2])
                &&hash('sha256',$counter[2])===$initial['sha256']&&$counter[3]===$initial['expires']
                &&bccomp($counterTime,(string)$initial['expires'],0)<=0,'TASK6_REAL_CF_COUNTER_AND_ORIGINAL_DUMP_EXPIRY');
            else m4fn_expect(in_array($counter[1],[false,null],true)&&in_array($counter[2],[false,null],true)&&$counter[3]===-2
                &&bccomp($counterTime,(string)$initial['expires'],0)>=0,'TASK6_CF_COUNTER_ONLY_NATURALLY_EXPIRED_DURING_READ');
        }
        $states[$keyHash]=['initial'=>$initial,'missing'=>$initial===null,
            'first_absence_ms'=>$initial===null?$first['time_ms']:null];unset($others[$keyHash]);
    }
    $lastServer=$first['time_ms'];$samples=[];
    $observe=static function(array $current)use(&$states,$others,&$lastServer,&$samples):bool{
        m4fn_expect(bccomp($current['time_ms'],$lastServer,0)>=0,'SEQUENCE_BARRIER_REAL_SERVER_TIME_NONDECREASING');
        $lastServer=$current['time_ms'];$currentOthers=$current['keys'];$allMissing=true;
        foreach($states as$keyHash=>&$state){$value=$current['keys'][$keyHash]??null;
            if($value!==null){
                m4fn_expect(!$state['missing']&&$state['initial']!==null&&$value===$state['initial'],
                    'SEQUENCE_ORIGINAL_VALUE_EXPIRY_AND_NO_REAPPEARANCE');
                m4fn_expect(bccomp($current['time_ms'],(string)$value['expires'],0)<=0,'SEQUENCE_PRESENT_BEFORE_ORIGINAL_EXPIRY');
                $allMissing=false;
            }elseif(!$state['missing']){
                m4fn_expect($state['initial']!==null
                    &&bccomp($current['time_ms'],(string)$state['initial']['expires'],0)>=0,
                    'SEQUENCE_ABSENT_ONLY_AFTER_OBSERVED_ORIGINAL_EXPIRY');
                $state['missing']=true;$state['first_absence_ms']=$current['time_ms'];
            }
            unset($currentOthers[$keyHash]);
        }unset($state);
        m4fn_expect($currentOthers===$others,'ALL_NON_TARGET_REDIS_VALUES_TYPES_AND_ABSOLUTE_EXPIRIES_UNCHANGED');
        $samples[]=['time'=>$current['time'],'time_ms'=>$current['time_ms'],'key_count'=>count($current['keys']),
            'complete_keys_sha256'=>hash('sha256',serialize($current['keys']))];return $allMissing;
    };
    $observe($first);$before=m5pcp_database_snapshot($db);$bounded();
    $current=m5pcp_atomic_redis($redis);$bounded();$complete=$observe($current);
    while(!$complete){$bounded();usleep(50000);$bounded();$current=m5pcp_atomic_redis($redis);$bounded();$complete=$observe($current);}
    $after=m5pcp_database_snapshot($db);$bounded();
    m4fn_expect($after===$before,'SEQUENCE_BARRIER_COMPLETE_DATABASE_STRICTLY_UNCHANGED');
    $final=m5pcp_atomic_redis($redis);$finished=$bounded();
    m4fn_expect($observe($final),'SEQUENCE_BARRIER_FINAL_ALL_EXACT_TARGET_KEYS_ABSENT');$targets=[];
    foreach($states as$keyHash=>$state)$targets[]=['key_sha256'=>$keyHash,'sources'=>$sequence['keys'][$keyHash]['sources'],
        'initial_observation'=>$state['initial']===null?'ALREADY_ABSENT':'PRESENT_WITH_OBSERVED_EXPIRY',
        'initial_expires_at_ms'=>$state['initial']['expires']??null,'initial_value_sha256'=>$state['initial']['sha256']??null,
        'first_absence_time_ms'=>$state['first_absence_ms'],'natural_expiry_observed'=>$state['initial']!==null,'final_absent'=>true];
    return ['scope'=>'READONLY_REAL_PRODUCER_SEQUENCE_EXPIRY_BARRIER_ONLY','status'=>'VERIFIED_CF_SEQUENCE_KEYS_NATURALLY_ABSENT',
        'epoch_ms'=>$sequence['epoch_ms'],'source_id_count'=>$sequence['source_id_count'],'key_count'=>count($states),
        'targets'=>$targets,'server_samples'=>$samples,'monotonic_elapsed_ns'=>$finished-$started,
        'complete_database_before_sha256'=>hash('sha256',serialize($before)),
        'complete_database_after_sha256'=>hash('sha256',serialize($after)),
        'non_target_redis_sha256'=>hash('sha256',serialize($others)),
        'all_non_target_redis_values_and_absolute_expiries_unchanged'=>true,
        'complete_database_strictly_unchanged'=>true,'original_paid_account_payment_facts_retained'=>true];
}

function m4fn_new_row(array $before,array $after,array $metadata):array
{
    $old=m5pcp_index_rows($before,'id');$new=m5pcp_index_rows($after,'id');$added=array_diff_key($new,$old);
    m4fn_expect(count($added)===1&&count($new)===count($old)+1,'TASK6_EXACTLY_ONE_NATURAL_NEW_ROW');$row=array_values($added)[0];
    m4fn_expect(m5pcp_u64($row['id'])===m5pcp_u64($metadata['table']['AUTO_INCREMENT']),'TASK6_NATURAL_NEW_PRIMARY_KEY');return $row;
}
function m4fn_child_values(array $source,array $plan,array $request,array $raw,int $now):array
{
    $root=$source['saved']['order'];m4fn_expect(is_string($raw['order_id'])&&preg_match('/\Acf[1-9][0-9]{0,18}\z/D',$raw['order_id'])===1
        &&is_string($raw['unique'])&&preg_match('/\A[a-f0-9]{32}\z/D',$raw['unique'])===1&&$raw['unique']!==$root['unique'],
        'TASK6_REAL_NEW_CF_NUMBER_AND_UNIQUE');$products=[$plan['allocations'][0]['product_id']];$forms=[];
    foreach($source['forms']as$form)if(in_array($form['product_id'],$products,true))$forms[]=$form;
    $values=['id'=>m5pcp_u64($raw['id']),'pid'=>$source['identity']['id'],'order_id'=>$raw['order_id'],'uid'=>$source['identity']['uid'],
        'mer_id'=>$source['identity']['mer_id'],'marketplace_trade_version'=>1,'unique'=>$raw['unique'],
        'cart_id'=>['json'=>[$plan['allocations'][0]['cart_ref']]],'total_num'=>$plan['totals']['quantity'],
        'total_price'=>$plan['totals']['goods'],'total_postage'=>$plan['totals']['postage'],'pay_price'=>$plan['totals']['payable'],
        'pay_postage'=>$plan['totals']['postage'],'cost'=>$plan['totals']['cost'],'add_time'=>$now,'pay_uid'=>$source['identity']['uid'],
        'shipping_type'=>1,'is_gift'=>0,'status'=>1,'paid'=>0,'pay_time'=>0,'pay_type'=>'','trade_no'=>'',
        'delivery_type'=>'express','delivery_code'=>$request['delivery']['carrier_code'],'delivery_name'=>$request['delivery']['carrier_name'],
        'delivery_id'=>$request['delivery']['tracking_no'],'custom_form'=>['json'=>$forms]];
    foreach(['real_name','user_phone','user_address','channel_type','province','mark','merchant_name_snapshot',
        'merchant_after_sale_contact_snapshot','is_channel']as$key)$values[$key]=$root[$key];return $values;
}
function m4fn_item_values(array $source,array $allocation,array $child,string $operationId,array $actual):array
{
    $matches=[];foreach($source['saved']['items']as$row)if(m5pcp_int($row['id'],1)===$allocation['source_item_id'])$matches[]=$row;
    m4fn_expect(count($matches)===1,'TASK6_ONE_ORIGINAL_ITEM_FOR_CHILD');$original=$matches[0];
    $cart=json_decode($original['cart_info'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    m4fn_expect(!isset($cart['marketplace_fulfillment']),'TASK6_NO_PREVIOUS_CHILD_LINEAGE_ON_ORIGINAL');
    $cart['cart_num']=$allocation['quantity'];$cart['sum_true_price']=$allocation['goods'];$cart['postage_price']=$allocation['postage'];
    $cart['origin_postage_price']=$allocation['origin_postage'];$cart['marketplace_fulfillment']=['schema'=>1,
        'root_order_id'=>$source['identity']['id'],'source_item_id'=>$allocation['source_item_id'],'ship_operation_id'=>$operationId];
    $childId=m5pcp_int($child['id'],1);return ['id'=>m5pcp_u64($actual['id']),'oid'=>$childId,'uid'=>$source['identity']['uid'],
        'mer_id'=>$source['identity']['mer_id'],'cart_id'=>$original['cart_id'],'product_id'=>$allocation['product_id'],
        'cart_num'=>$allocation['quantity'],'surplus_num'=>$allocation['quantity'],'refund_num'=>0,'split_status'=>0,
        'unique'=>md5($original['cart_id'].$childId),'cart_info'=>['json'=>$cart]];
}
function m4fn_operation(array $source,array $before,array $after,array $case,array $returned,array $actor,int $started,int $ended,array &$history):array
{
    $orders='eb_store_order';$items='eb_store_order_cart_info';$ops='eb_marketplace_fulfillment_operation';$ranges='eb_marketplace_fulfillment_range';
    $raw=m4fn_new_row($before['rows'][$ops],$after['rows'][$ops],$before['database']['tables'][$ops]['metadata']);
    $id=m5pcp_u64($raw['id']);$now=m5pcp_int($raw['created_at'],1,4294967295);$commit=m5pcp_int($raw['committed_at'],1,4294967295);
    m4fn_expect($now>=$started&&$now<=$commit&&$commit<=$ended,'TASK6_REAL_OPERATION_NATIVE_TIME_INTERVAL');
    $plan=m4fn_plan($source,$case['item_id'],$case['start'],$case['end']);$allocation=$plan['allocations'][0];$ship=$case['action']==='ship';
    $request=['schema'=>1,'action'=>$case['action'],'root_order_id'=>$source['identity']['id']];$expectedRows=$before['rows'];
    $inserted=[$ops=>$id];$target=null;$parent=null;
    if($ship){
        $childRaw=m4fn_new_row($before['rows'][$orders],$after['rows'][$orders],$before['database']['tables'][$orders]['metadata']);
        $childId=m5pcp_int($childRaw['id'],1);$request['quantities']=[['source_item_id'=>$case['item_id'],'quantity'=>$case['end']-$case['start']]];
        $request['delivery']=$case['raw']['delivery'];
        $child=m4fn_expected_row($before['database']['tables'][$orders]['metadata'],$childRaw,
            m4fn_child_values($source,$plan,$request,$childRaw,$now));
        foreach($before['rows'][$orders]as$old)m4fn_expect($old['order_id']!==$child['order_id']&&$old['unique']!==$child['unique'],
            'TASK6_CF_NUMBER_UNIQUE_AGAINST_ALL_SAVED_ORDERS');
        $itemRaw=m4fn_new_row($before['rows'][$items],$after['rows'][$items],$before['database']['tables'][$items]['metadata']);
        $childItem=m4fn_expected_row($before['database']['tables'][$items]['metadata'],$itemRaw,m4fn_item_values($source,$allocation,$child,$id,$itemRaw));
        $expectedRows[$orders][]=$child;$expectedRows[$items][]=$childItem;$inserted[$orders]=m5pcp_u64($child['id']);$inserted[$items]=m5pcp_u64($childItem['id']);
    }else{
        $target=$history['ships'][$case['ship_index']];$child=$target['child'];$childItem=$target['item'];$parent=$target['range_id'];
        $childId=m5pcp_int($child['id'],1);$request['child_order_id']=$childId;
        $request['selection']=$case['raw']['selection']??['mode'=>'all_eligible'];
    }
    $actorId=$ship?$actor['employee_id']:$source['identity']['uid'];$actorType=$ship?'employee':'customer';
    $digest=hash('sha256',m5pcp_canonical(['schema'=>1,'action'=>$case['action'],'root_order_id'=>$source['identity']['id'],
        'child_order_id'=>$childId,'source_snapshot_sha256'=>$source['fingerprint'],'target_operation_id'=>$target['operation_id']??null,
        'allocations'=>[$allocation+['source_ship_range_id'=>$parent]]]),true);
    $summary=m4fn_summary($case['shipped'],$case['received'],$case['children']);
    $public=['schema'=>1,'operation_id'=>$id,'action'=>$case['action'],'root_order_id'=>$source['identity']['id'],
        'root_order_no'=>$source['identity']['order_id'],'child_order_id'=>$childId,'child_order_no'=>$child['order_id'],
        'quantity'=>$plan['totals']['quantity'],'created_at'=>$now,'committed_at'=>$commit,'shipped_at'=>$ship?$now:null,
        'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$now,'fulfillment_summary'=>$summary];
    m5pcp_same($returned,$public);
    $rangeRaw=m4fn_new_row($before['rows'][$ranges],$after['rows'][$ranges],$before['database']['tables'][$ranges]['metadata']);
    $rangeId=m5pcp_u64($rangeRaw['id']);$childItemId=m5pcp_int($childItem['id'],1);
    $rangeValues=['id'=>$rangeId,'operation_id'=>$id,'operation_action'=>$case['action'],'root_order_id'=>$source['identity']['id'],
        'child_order_id'=>$childId,'uid'=>$source['identity']['uid'],'mer_id'=>$source['identity']['mer_id'],
        'source_item_id'=>$case['item_id'],'child_item_id'=>$childItemId,'reserve_id'=>$allocation['reserve_id'],
        'source_ship_range_id'=>$parent,'source_range_action'=>$ship?null:'ship'];
    foreach(['product_id','sku_id','sku_type','sku_unique','cart_ref','original_quantity','unit_start','unit_end','quantity',
        'unit_price','price_type','goods','postage','origin_postage','payable','cost','base_goods','discount']as$key)$rangeValues[$key]=$allocation[$key];
    $range=m4fn_expected_row($before['database']['tables'][$ranges]['metadata'],$rangeRaw,$rangeValues);
    $refs=[['range_id'=>$rangeId,'source_item_id'=>$case['item_id'],'child_item_id'=>$childItemId,
        'source_ship_range_id'=>$parent,'unit_start'=>$case['start'],'unit_end'=>$case['end']]];
    $internal=['schema'=>1,'operation_id'=>$id,'action'=>$case['action'],'root_order_id'=>$source['identity']['id'],
        'root_order_no'=>$source['identity']['order_id'],'child_order_id'=>$childId,'child_order_no'=>$child['order_id'],
        'target_operation_id'=>$target['operation_id']??null,'actor_type'=>$actorType,'actor_id'=>$actorId,'created_at'=>$now,
        'committed_at'=>$commit,'shipped_at'=>$ship?$now:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$now,
        'plan'=>$plan,'ranges'=>$refs,'summary'=>$summary,'public_result'=>$public];
    $operationValues=['id'=>$id,'contract_version'=>1,'root_order_id'=>$source['identity']['id'],'child_order_id'=>$childId,
        'uid'=>$source['identity']['uid'],'mer_id'=>$source['identity']['mer_id'],'action'=>$case['action'],'idem_key'=>$case['raw']['idem_key'],
        'request_digest'=>hash('sha256',m5pcp_canonical($request),true),'source_snapshot_digest'=>hex2bin($source['fingerprint']),
        'allocation_digest'=>$digest,'request_payload'=>['json'=>$request],'result_payload'=>['json'=>$internal],'state'=>1,
        'ship_child_order_id'=>$ship?$childId:null,'target_operation_id'=>$target['operation_id']??null,'target_action'=>$ship?null:'ship',
        'actor_type'=>$actorType,'actor_id'=>$actorId,'created_at'=>$now,'committed_at'=>$commit,
        'shipped_at'=>$ship?$now:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$now,'aftersale_cut_id'=>null];
    $operation=m4fn_expected_row($before['database']['tables'][$ops]['metadata'],$raw,$operationValues);
    $expectedRows[$ops][]=$operation;$expectedRows[$ranges][]=$range;$inserted[$ranges]=$rangeId;
    $rootId=$source['identity']['id'];$rootOld=null;
    foreach($expectedRows[$orders]as$i=>$row){$rowId=m5pcp_int($row['id'],1);
        if($rowId===$rootId){$rootOld=$row;$row['status']=m4fn_native_value($before['database']['tables'][$orders]['metadata']['columns']['status'],$case['root_status']);$expectedRows[$orders][$i]=$row;}
        elseif(m5pcp_int($row['pid'],0)===$rootId){$received=0;$shipped=m5pcp_int($row['total_num'],1);
            foreach($expectedRows[$ranges]as$r)if(m5pcp_int($r['child_order_id'],1)===$rowId&&$r['operation_action']==='receive')$received+=m5pcp_int($r['quantity'],1);
            $row['status']=m4fn_native_value($before['database']['tables'][$orders]['metadata']['columns']['status'],$received===$shipped?2:1);$expectedRows[$orders][$i]=$row;}}
    m4fn_expect(is_array($rootOld),'TASK6_STABLE_ORIGINAL_COMMERCIAL_ROOT');
    if(m5pcp_int($rootOld['status'],0,2)!==$case['root_status'])$expectedRows['eb_store_order_status'][]=[
        'oid'=>$rootOld['id'],'change_type'=>$ship?'marketplace_fulfillment_ship':'marketplace_fulfillment_receive',
        'change_message'=>$ship?'多商户履约发货状态更新':'多商户履约收货状态更新','change_time'=>$now];
    m4fn_delta($before,$after,$expectedRows,$inserted);
    $record=['operation_id'=>$id,'range_id'=>$rangeId,'action'=>$case['action'],'request'=>$request,'plan'=>$plan,
        'public'=>$public,'internal'=>$internal,'operation'=>$operation,'range'=>$range,'child'=>$child,'item'=>$childItem];
    $history['operations'][]=$record;if($ship)$history['ships'][]=$record;
    m4fn_history($source,$after,$history,$case);return $record;
}
function m4fn_history(array $source,array $snapshot,array $history,array $case):void
{
    $root=$source['identity']['id'];$children=[];$items=[];$ops=[];$ranges=[];
    foreach($snapshot['rows']['eb_store_order']as$row)if(m5pcp_int($row['pid'],0)===$root)$children[]=m5pcp_int($row['id'],1);
    foreach($snapshot['rows']['eb_store_order_cart_info']as$row)if(in_array(m5pcp_int($row['oid'],1),$children,true))$items[]=m5pcp_int($row['id'],1);
    foreach($snapshot['rows']['eb_marketplace_fulfillment_operation']as$row)if(m5pcp_int($row['root_order_id'],1)===$root)$ops[]=m5pcp_u64($row['id']);
    foreach($snapshot['rows']['eb_marketplace_fulfillment_range']as$row)if(m5pcp_int($row['root_order_id'],1)===$root)$ranges[]=m5pcp_u64($row['id']);
    m4fn_expect(count($children)===$case['children']&&count($items)===$case['children']&&count($ops)===count($history['operations'])
        &&count($ranges)===count($history['operations']),'TASK6_COMPLETE_HISTORY_COUNTS_NO_EXTRA_CHILD_ITEM_OPERATION_RANGE');
    $occupied=['ship'=>[],'receive'=>[]];$shipped=$received=0;
    foreach($history['operations']as$record){$a=$record['plan']['allocations'][0];$action=$record['action'];$key=$a['source_item_id'];
        m4fn_expect(in_array($record['operation_id'],$ops,true)&&in_array($record['range_id'],$ranges,true),'TASK6_ALL_COMMITTED_HISTORY_NATIVE_PRIMARY_KEYS');
        foreach($occupied[$action][$key]??[]as$prior)m4fn_expect($a['unit_end']<=$prior[0]||$a['unit_start']>=$prior[1],
            'TASK6_ABSOLUTE_ORIGINAL_UNIT_INTERVALS_NONOVERLAPPING');$occupied[$action][$key][]=[$a['unit_start'],$a['unit_end']];
        if($action==='ship')$shipped+=$a['quantity'];else{$received+=$a['quantity'];$parent=m5pcp_u64($record['range']['source_ship_range_id']);$matched=[];
            foreach($history['ships']as$ship)if($ship['range_id']===$parent)$matched[]=$ship;
            m4fn_expect(count($matched)===1&&$matched[0]['plan']['allocations'][0]['source_item_id']===$key
                &&$a['unit_start']>=$matched[0]['plan']['allocations'][0]['unit_start']&&$a['unit_end']<=$matched[0]['plan']['allocations'][0]['unit_end']
                &&$record['public']['child_order_id']===$matched[0]['public']['child_order_id'],'TASK6_RECEIVE_REAL_SAME_CHILD_SHIP_RANGE_PARENT');}}
    m4fn_expect($shipped===$case['shipped']&&$received===$case['received']&&$received<=$shipped&&$shipped<=3,
        'TASK6_INDEPENDENT_COMPLETE_ORIGINAL_QUANTITY_CONSERVATION');
}

function m4fn_merchant_read(\think\App $app,PDO $db,\think\db\ConnectionInterface $connection,PDO $default,
    MerchantContext $ctx,array $source,array $summary):array
{
    $before=m4fn_snapshot($db);$roots=m5pcp_rows($db,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$source['identity']['id']]);
    m4fn_expect(count($roots)===1,'TASK6_SAME_REAL_COMMERCIAL_ROOT_FOR_READ');$orders=$app->make(MerchantOrderServices::class);
    $page=$orders->list($ctx,['order_id'=>$source['identity']['order_id'],'paid'=>1,'is_cancel'=>0,'page'=>1,'limit'=>20]);
    m4fn_context($app,$connection,$default,$db);$detail=$orders->detail($ctx,$source['identity']['id']);
    m4fn_context($app,$connection,$default,$db);
    m5pcp_same($page,['list'=>[m4fn_merchant_oracle($source,$roots[0],$summary,false)],'count'=>1]);
    m5pcp_same($detail,m4fn_merchant_oracle($source,$roots[0],$summary,true));$after=m4fn_snapshot($db);m4fn_zero($before,$after);
    return ['same_saved_root'=>true,'independent_saved_DTO'=>true,'page_count'=>1,'detail_count'=>1,
        'summary'=>$summary,'database_sha256'=>hash('sha256',serialize($after['database'])),
        'redis_sha256'=>hash('sha256',serialize($after['redis']['keys'])),'same_default_connection_pdo_depth_zero'=>true];
}
function m4fn_early_cf(\think\App $app,PDO $db,array $before,array $source,array $case,array $result,int $start,int $end,array $history):array
{
    $id=m5pcp_u64($before['database']['tables']['eb_store_order']['metadata']['table']['AUTO_INCREMENT']);
    $rows=m5pcp_rows($db,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$id]);m4fn_expect(count($rows)===1,'TASK6_SAVED_CHILD_EARLY_NATIVE_READ');
    $now=m5pcp_int($result['created_at']??null,1,4294967295);
    m4fn_expect($now>=$start&&$now<=$end,'TASK6_EARLY_CF_ACTUAL_OPERATION_INTERVAL');$plan=m4fn_plan($source,$case['item_id'],$case['start'],$case['end']);
    $request=['delivery'=>$case['raw']['delivery']];$child=m4fn_expected_row($before['database']['tables']['eb_store_order']['metadata'],
        $rows[0],m4fn_child_values($source,$plan,$request,$rows[0],$now));$children=[];
    foreach($history['ships']as$ship)$children[]=$ship['child'];$children[]=$child;
    $sequence=m4fn_cf_sources($app,$children);$matched=0;
    foreach($sequence['keys']as$hash=>$target)foreach($target['sources']as$origin)
        if($origin['child_order_id']===m5pcp_int($child['id'],1)){$matched++;
            m4fn_expect(!isset($before['redis']['keys'][$hash]),'TASK6_CF_TARGET_ABSENT_BEFORE_REAL_PRODUCER');}
    m4fn_expect($matched===1,'TASK6_ONE_REAL_CF_PRODUCER_SOURCE_IN_COMPLETE_SET');
    // First atomic Redis observation precedes the slower complete DB after-snapshot. Expiry is never inferred from the PHP key.
    return m4fn_cf_barrier($db,$sequence);
}
/** Best-effort failure side evidence only; original assertion bookkeeping and original error are preserved. */
function m4fn_failure_source_side_evidence(MarketplaceOrderFulfillmentServices $service,array $source):void
{
    $saved=[];foreach(['m4fn_failed_assertion','m5pcp_failed_assertion','m4c_failed_assertion',
        'm4fn_assertions','m5pcp_assertions','m4c_assertions','m4c_assertions_by_phase']as$name)
        $saved[$name]=[array_key_exists($name,$GLOBALS),$GLOBALS[$name]??null];
    try{
        $require=static function(bool $ok):void{if(!$ok)throw new RuntimeException('TASK6_SAFE_SOURCE_DIAGNOSTIC_UNAVAILABLE');};
        $keys=static function(array $value,array $wanted)use($require):void{$actual=array_keys($value);sort($actual,SORT_STRING);
            sort($wanted,SORT_STRING);$require($actual===$wanted);};
        $paths=[
            'MarketplaceOrderFulfillmentServices.php'=>'crmeb/app/services/marketplace/order/MarketplaceOrderFulfillmentServices.php',
            'MarketplaceOrderFulfillmentDao.php'=>'crmeb/app/dao/marketplace/order/MarketplaceOrderFulfillmentDao.php',
            'MarketplacePaymentDao.php'=>'crmeb/app/dao/marketplace/payment/MarketplacePaymentDao.php',
            'MarketplaceOrderDao.php'=>'crmeb/app/dao/marketplace/order/MarketplaceOrderDao.php',
            'MarketplaceOrderRefundDao.php'=>'crmeb/app/dao/marketplace/order/MarketplaceOrderRefundDao.php',
            'MarketplaceOrderRefundFacts.php'=>'crmeb/app/services/marketplace/order/MarketplaceOrderRefundFacts.php',
            'MarketplaceOrderFulfillmentPlan.php'=>'crmeb/app/services/marketplace/order/MarketplaceOrderFulfillmentPlan.php',
            'MarketplaceOrderFulfillmentInput.php'=>'crmeb/app/services/marketplace/order/MarketplaceOrderFulfillmentInput.php',
            'MarketplaceOrderSnapshotProjection.php'=>'crmeb/app/services/marketplace/order/MarketplaceOrderSnapshotProjection.php',
            'EmployeeAuthServices.php'=>'crmeb/app/services/marketplace/EmployeeAuthServices.php'];
        $read=static function(string $relative)use($source,$require,$keys):string{
            $rows=array_values(array_filter($source['source_files'],static fn(array $row):bool=>($row['path']??null)===$relative));
            $require(count($rows)===1);$row=$rows[0];$keys($row,['path','bytes','sha256']);
            $require(is_int($row['bytes'])&&$row['bytes']>0&&is_string($row['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1);
            $raw=null;foreach(['/source','/workspace']as$root){$path=$root.'/'.$relative;
                $require(is_file($path)&&!is_link($path)&&realpath($path)===$path);$bytes=file_get_contents($path);
                $require(is_string($bytes)&&strlen($bytes)===$row['bytes']&&hash('sha256',$bytes)===$row['sha256']);
                if($raw!==null)$require($raw===$bytes);$raw=$bytes;}
            return $raw;
        };
        $raw=$read($paths['MarketplaceOrderFulfillmentServices.php']);$serviceLines=explode("\n",$raw);
        $require(preg_match('/private const FAILURE_SOURCE_CALLER_LINES=\[([0-9,]+)\];/',$raw,$match)===1);
        $declared=[];foreach(explode(',',$match[1])as$value){$require(preg_match('/\A[1-9][0-9]{0,5}\z/D',$value)===1);$declared[]=(int)$value;}
        $derived=[];foreach($serviceLines as$i=>$line)if(str_contains($line,'self::unavailable('))$derived[]=$i+1;
        $require($declared!==[]&&$declared===$derived&&count($declared)===count(array_unique($declared,SORT_NUMERIC)));
        $diagnostic=$service->lastFailureSourceDiagnostic();
        if($diagnostic===null)$diagnostic=['schema'=>1,'scope'=>'TASK6_FULFILLMENT_FAILURE_SOURCE_DIAGNOSTIC',
            'phase'=>'not_captured','origin'=>['class'=>'OTHER','file'=>null,'line'=>null],'caller'=>null];
        $keys($diagnostic,['schema','scope','phase','origin','caller']);
        $require($diagnostic['schema']===1&&$diagnostic['scope']==='TASK6_FULFILLMENT_FAILURE_SOURCE_DIAGNOSTIC'
            &&in_array($diagnostic['phase'],['locked_payment','locked_complete_native_snapshot','saved_replay','new_action_admission',
                'new_child_number','insert_child','insert_operation','insert_ranges','native_readback','finish_operation',
                'commit_outcome_unknown','post_commit_boundary','unknown','not_captured'],true));
        $origin=$diagnostic['origin'];$require(is_array($origin));$keys($origin,['class','file','line']);
        $require(in_array($origin['class'],['crmeb\exceptions\AuthUnavailableException','crmeb\exceptions\AuthSessionException',
            'crmeb\exceptions\MerchantForbiddenException','crmeb\exceptions\ApiException','RuntimeException','InvalidArgumentException',
            'UnexpectedValueException','LogicException','DomainException','PDOException','TypeError','ValueError','Error','JsonException',
            'think\db\exception\PDOException','think\db\exception\DbException','OTHER'],true));
        if($origin['file']===null)$require($origin['line']===null);
        else{$require(is_string($origin['file'])&&isset($paths[$origin['file']])&&is_int($origin['line'])&&$origin['line']>=1);
            $originRaw=$origin['file']==='MarketplaceOrderFulfillmentServices.php'?$raw:$read($paths[$origin['file']]);
            $require($origin['line']<=count(explode("\n",$originRaw)));}
        $caller=$diagnostic['caller'];if($caller!==null){$require(is_array($caller));$keys($caller,['file','line']);
            $require($caller['file']==='MarketplaceOrderFulfillmentServices.php'&&is_int($caller['line'])
                &&in_array($caller['line'],$declared,true)&&str_contains($serviceLines[$caller['line']-1],'self::unavailable('));}
        if($diagnostic['phase']==='not_captured')$require($origin===['class'=>'OTHER','file'=>null,'line'=>null]&&$caller===null);
        foreach(['run_id'=>'/\A[0-9]{14}[a-f0-9]{6}\z/D','source_sha'=>'/\A[a-f0-9]{40}\z/D',
            'container_id'=>'/\A[a-f0-9]{64}\z/D','freeze_sha256'=>'/\A[a-f0-9]{64}\z/D']as$name=>$pattern)
            $require(is_string($source[$name]??null)&&preg_match($pattern,$source[$name])===1);
        $record=['schema'=>1,'scope'=>'MS4_TASK6_SAFE_FAILURE_SOURCE_DIAGNOSTIC_ONLY',
            'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
            'freeze_sha256'=>$source['freeze_sha256'],'diagnostic'=>$diagnostic];
        m4c_entry_publish('/run/audit/evidence/ms4-fulfillment-failure-source-diagnostic.json',$record);
    }catch(Throwable $diagnosticUnavailable){
        // Never replace the original service error or emit a second failure message.
    }finally{
        foreach($saved as$name=>[$existed,$value]){if($existed)$GLOBALS[$name]=$value;else unset($GLOBALS[$name]);}
    }
}

function m4fn_produce(\think\App $app,PDO $db,array $source,array $owner):array
{
    $connection=Db::connect();$default=$connection->getPdo();
    if(!$default instanceof PDO){$connection->query('SELECT 1',[],true);$default=$connection->getPdo();}
    m4fn_expect($default instanceof PDO,'TASK6_REAL_APP_DEFAULT_PDO');m4fn_context($app,$connection,$default,$db);
    m4cb_events($db);$oracle=m4fn_source_oracle($owner['saved']);$initial=m4fn_snapshot($db);$stages=[];
    m4fn_phase('merchant_actual_credentials');$actor=m4fn_actor($app,$db,$oracle,$connection,$default);
    m4fn_phase('merchant_initial_saved_read');$reads=[m4fn_merchant_read($app,$db,$connection,$default,$actor['context'],$oracle,m4fn_summary(0,0,0))];
    m4fn_phase('normal_due_configuration');$configuration=m4fn_configure($db);m4fn_context($app,$connection,$default,$db);
    $history=['operations'=>[],'ships'=>[]];$sequence=[];$service=$app->make(MarketplaceOrderFulfillmentServices::class);
    m4fn_phase('three_read_initial');$GLOBALS['m4tr_read_stages']=[m4tr_read_stage($app,$db,$owner,$GLOBALS['m4tr_prepared'],$oracle,$history,0)];
    $suffix=bin2hex(random_bytes(8));$delivery=['type'=>'express','carrier_code'=>'task6-normal','carrier_name'=>'普通履约验证','tracking_no'=>'task6-'.$suffix];
    $cases=[
        ['action'=>'ship','item_id'=>$oracle['q2'],'start'=>0,'end'=>2,'shipped'=>2,'received'=>0,'children'=>1,'root_status'=>0,
            'raw'=>['idem_key'=>'normal:ship:q2:'.$suffix,'quantities'=>[['source_item_id'=>$oracle['q2'],'quantity'=>2]],'delivery'=>$delivery]],
        ['action'=>'receive','ship_index'=>0,'item_id'=>$oracle['q2'],'start'=>0,'end'=>1,'shipped'=>2,'received'=>1,'children'=>1,'root_status'=>0,
            'raw'=>['idem_key'=>'normal:receive:first:'.$suffix,'selection'=>['mode'=>'ranges','ranges'=>[
                ['source_item_id'=>$oracle['q2'],'unit_start'=>0,'unit_end'=>1]]]]],
        ['action'=>'receive','ship_index'=>0,'item_id'=>$oracle['q2'],'start'=>1,'end'=>2,'shipped'=>2,'received'=>2,'children'=>1,'root_status'=>0,
            'raw'=>['idem_key'=>'normal:receive:remaining:'.$suffix,'selection'=>['mode'=>'all_eligible']]],
        ['action'=>'ship','item_id'=>$oracle['q1'],'start'=>0,'end'=>1,'shipped'=>3,'received'=>2,'children'=>2,'root_status'=>1,
            'raw'=>['idem_key'=>'normal:ship:q1:'.$suffix,'quantities'=>[['source_item_id'=>$oracle['q1'],'quantity'=>1]],'delivery'=>$delivery]],
        ['action'=>'receive','ship_index'=>1,'item_id'=>$oracle['q1'],'start'=>0,'end'=>1,'shipped'=>3,'received'=>3,'children'=>2,'root_status'=>2,
            'raw'=>['idem_key'=>'normal:receive:other:'.$suffix,'selection'=>['mode'=>'all_eligible']]],
    ];
    foreach($cases as$index=>$case){m4fn_phase('normal_action_'.($index+1));m4fn_context($app,$connection,$default,$db);
        $before=m4fn_snapshot($db);$started=time();
        try{
            if($case['action']==='ship')$result=$service->ship($actor['context'],$oracle['identity']['id'],$case['raw']);
            else $result=$service->receiveForCustomer($owner['uid'],$history['ships'][$case['ship_index']]['child']['order_id'],$case['raw']);
        }catch(Throwable $error){m4fn_failure_source_side_evidence($service,$source);throw $error;}
        $ended=time();m4fn_context($app,$connection,$default,$db);
        if($case['action']==='ship')$sequence[]=m4fn_early_cf($app,$db,$before,$oracle,$case,$result,$started,$ended,$history);
        $after=m4fn_snapshot($db);$record=m4fn_operation($oracle,$before,$after,$case,$result,$actor,$started,$ended,$history);
        $stages[]=['index'=>$index+1,'case'=>$case,'interval'=>[$started,$ended],'record'=>$record,'snapshot'=>$after];
        // A genuine same-key replay is read-only and returns its own immutable committed result, never remaining eligibility.
        m4fn_phase('normal_replay_'.($index+1));$replayBefore=m4fn_snapshot($db);
        if($case['action']==='ship')$replayed=$service->ship($actor['context'],$oracle['identity']['id'],$case['raw']);
        else $replayed=$service->receiveForCustomer($owner['uid'],$record['child']['order_id'],$case['raw']);
        m5pcp_same($replayed,$record['public']);m4fn_context($app,$connection,$default,$db);m4fn_zero($replayBefore,m4fn_snapshot($db));
        m4fn_phase('merchant_saved_read_'.($index+1));$reads[]=m4fn_merchant_read($app,$db,$connection,$default,$actor['context'],
            $oracle,m4fn_summary($case['shipped'],$case['received'],$case['children']));m4fn_inputs_unchanged($owner['inputs']);
        m4fn_phase('three_read_after_action_'.($index+1));$GLOBALS['m4tr_read_stages'][]=
            m4tr_read_stage($app,$db,$owner,$GLOBALS['m4tr_prepared'],$oracle,$history,$index+1);
    }
    $final=m4fn_snapshot($db);m4fn_history($oracle,$final,$history,$cases[4]);m4cb_events($db);
    m4fn_expect(count($history['operations'])===5&&count($history['ships'])===2&&count($sequence)===2&&count($reads)===6,
        'TASK6_ACTUAL_TWO_SHIP_THREE_RECEIVE_FIVE_REAL_COMMIT_AND_REPLAY_SIX_READS');
    $root=m5pcp_saved($db,$oracle['identity']['id']);$expected=$owner['saved'];$expected['order']['status']=$root['order']['status'];
    m4fn_expect(m5pcp_int($root['order']['status'],2,2)===2&&$root===$expected,
        'TASK6_ORIGINAL_ROOT_PAYMENT_ITEMS_RESERVES_LEDGER_INVOICE_ONLY_ROOT_STATUS_CHANGED');
    m4fn_context($app,$connection,$default,$db);m4fn_inputs_unchanged($owner['inputs']);
    return ['initial'=>$initial,'stages'=>$stages,'final'=>$final,'history'=>$history,'reads'=>$reads,'sequence'=>$sequence,
        'configuration'=>$configuration,'source_snapshot_sha256'=>$oracle['fingerprint'],'employee_id'=>$actor['employee_id'],
        'role_id'=>$actor['role_id'],'original_root_id'=>$oracle['identity']['id'],'original_root_no'=>$oracle['identity']['order_id']];
}

function m4fn_seal(array $source,array $owner,array $produced,PDO $db):array
{
    m4fn_phase('stage6_private_seal');m4fn_inputs_unchanged($owner['inputs']);
    m4fn_expect(m4fn_snapshot($db)['database']===$produced['final']['database'],'TASK6_FINAL_DATABASE_STILL_EXACT_BEFORE_SEAL');
    $native=m5pc_native_snapshot($db);$archive=m5pc_native_snapshot_archive($native);
    $payload=['schema'=>1,'scope'=>'MS4_FULFILLMENT_NORMAL_STAGE6_NATIVE_FACTS','run_id'=>$source['run_id'],
        'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],'container_id'=>$source['container_id'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'environment_sha256'=>$source['environment_sha256'],
        'environment_native_sha256'=>$source['environment_native_sha256'],'prerequisites_sha256'=>$source['prerequisites_sha256'],
        'original_paid_baseline'=>['path'=>M5PC_BASELINE,'bytes'=>$owner['inputs'][1]['stat']['size'],
            'sha256'=>$owner['inputs'][1]['sha256'],'stat'=>$owner['inputs'][1]['stat'],
            'container_id'=>$owner['baseline']['container_id'],'authenticated_owner_uid'=>$owner['uid']],
        'original_fixture'=>['path'=>M4CB_FIXTURE,'bytes'=>$owner['inputs'][0]['stat']['size'],
            'sha256'=>$owner['inputs'][0]['sha256'],'stat'=>$owner['inputs'][0]['stat']],
        'produced'=>$produced,'final_native_34_table_archive'=>$archive];
    $bytes=serialize($payload);m4fn_expect(strlen($bytes)>0&&strlen($bytes)<=67108864,'TASK6_FINITE_PRIVATE_NATIVE_STAGE6_ARCHIVE');
    $envelope=['schema'=>1,'scope'=>'MS4_FULFILLMENT_NORMAL_STAGE6_NATIVE_ARCHIVE','run_id'=>$source['run_id'],
        'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],'container_id'=>$source['container_id'],
        'encoding'=>'php-serialize-base64-v1','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'payload'=>base64_encode($bytes)];
    $raw=m4cb_json($envelope);$parent=dirname(M4FN_BASELINE);$parentStat=lstat($parent);
    m4fn_expect(is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent&&is_array($parentStat)
        &&($parentStat['mode']&0170000)===0040000&&($parentStat['mode']&0777)===0700&&$parentStat['uid']===posix_geteuid()
        &&!file_exists(M4FN_BASELINE)&&!is_link(M4FN_BASELINE),'TASK6_PRIVATE_STAGE6_CREATE_ONLY_PARENT');
    $mask=umask(0077);$stream=fopen(M4FN_BASELINE,'xb');
    try{m4fn_expect(is_resource($stream)&&chmod(M4FN_BASELINE,0600),'TASK6_PRIVATE_STAGE6_OPEN_MODE');$offset=0;
        while($offset<strlen($raw)){$written=fwrite($stream,substr($raw,$offset));m4fn_expect(is_int($written)&&$written>0,
            'TASK6_PRIVATE_STAGE6_COMPLETE_WRITE');$offset+=$written;}m4fn_expect(fflush($stream),'TASK6_PRIVATE_STAGE6_FLUSH');
    }finally{if(is_resource($stream))fclose($stream);umask($mask);}
    clearstatcache(true,M4FN_BASELINE);$before=lstat(M4FN_BASELINE);$read=file_get_contents(M4FN_BASELINE);
    clearstatcache(true,M4FN_BASELINE);$after=lstat(M4FN_BASELINE);
    m4fn_expect(is_array($before)&&is_array($after)&&is_file(M4FN_BASELINE)&&!is_link(M4FN_BASELINE)
        &&realpath(M4FN_BASELINE)===M4FN_BASELINE&&($before['mode']&0170000)===0100000&&($before['mode']&0777)===0600
        &&$before['uid']===posix_geteuid()&&$before['nlink']===1&&$before['size']===strlen($raw)&&$read===$raw,
        'TASK6_PRIVATE_STAGE6_FULL_READBACK_STAT_MODE_OWNER');
    foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)m4fn_expect($before[$key]===$after[$key],
        'TASK6_PRIVATE_STAGE6_STABLE_STAT');
    $decoded=json_decode($read,true,32,JSON_THROW_ON_ERROR);m4fn_expect($decoded===$envelope,'TASK6_STAGE6_EXACT_OUTER_JSON');
    $nativeBytes=base64_decode($decoded['payload'],true);m4fn_expect(is_string($nativeBytes)&&base64_encode($nativeBytes)===$decoded['payload']
        &&strlen($nativeBytes)===$decoded['bytes']&&hash('sha256',$nativeBytes)===$decoded['sha256'],'TASK6_STAGE6_STRICT_COMPLETE_NATIVE_ARCHIVE_BYTES');
    $restored=unserialize($nativeBytes,['allowed_classes'=>false,'max_depth'=>128]);
    m4fn_expect(is_array($restored)&&serialize($restored)===$nativeBytes&&$restored===$payload
        &&m5pc_native_snapshot_restore($restored['final_native_34_table_archive'])===$native&&m5pc_native_snapshot($db)===$native,
        'TASK6_STAGE6_FULL_NATIVE_RESTORE_AND_INDEPENDENT_PDO');
    m4fn_inputs_unchanged($owner['inputs']);m4fn_zero($produced['final'],m4fn_snapshot($db));
    return ['path_basename'=>basename(M4FN_BASELINE),'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),
        'native_archive_bytes'=>strlen($bytes),'native_archive_sha256'=>hash('sha256',$bytes),
        'original_fixture_unchanged'=>true,'original_paid_baseline_unchanged'=>true,'private_native_readback_verified'=>true];
}
function m4fn_business(array $owner,array $produced,array $seal):array
{
    $operations=[];foreach($produced['history']['operations']as$record){$a=$record['plan']['allocations'][0];$raw=$record['operation'];
        $operations[]=['operation_id'=>$record['operation_id'],'range_id'=>$record['range_id'],'action'=>$record['action'],
            'child_order_id'=>$record['public']['child_order_id'],'source_item_id'=>$a['source_item_id'],
            'source_ship_range_id'=>$record['internal']['ranges'][0]['source_ship_range_id'],
            'unit_start'=>$a['unit_start'],'unit_end'=>$a['unit_end'],'quantity'=>$a['quantity'],
            'request_digest_sha256'=>bin2hex($raw['request_digest']),'source_snapshot_sha256'=>bin2hex($raw['source_snapshot_digest']),
            'allocation_digest_sha256'=>bin2hex($raw['allocation_digest']),
            'saved_result_sha256'=>hash('sha256',m5pcp_canonical($record['internal']))];}
    return ['original_root_id'=>$produced['original_root_id'],'authenticated_owner_uid'=>$owner['uid'],
        'authenticated_owner_producer_container_id'=>$owner['baseline']['container_id'],'original_items'=>2,'original_quantity'=>3,
        'new_roles'=>1,'new_employees'=>1,'new_password_setups'=>1,'new_configuration_rows'=>1,
        'ships'=>2,'receives'=>3,'committed_operations'=>5,'persisted_ranges'=>5,'children'=>2,'child_items'=>2,
        'same_key_zero_delta_replays'=>5,'merchant_saved_read_pairs'=>6,'root_status_sequence'=>[0,0,0,1,2],
        'shipped_received_sequence'=>[[2,0],[2,1],[2,2],[3,2],[3,3]],'final_summary'=>m4fn_summary(3,3,2),
        'same_run_original_authenticated_owner_verified'=>true,'original_paid_result_native_null_terminal_zero_verified'=>true,
        'real_employee_setup_login_authenticate'=>true,'same_default_connection_pdo_depth_zero'=>true,
        'all_saved_history_three_digests_native_types_verified'=>true,'original_Q_integer_cent_allocations_verified'=>true,
        'all_previous_and_non_target_rows_metadata_redis_preserved'=>true,'original_payment_stock_rewards_unchanged'=>true,
        'independent_merchant_saved_DTO_verified'=>true,'source_snapshot_sha256'=>$produced['source_snapshot_sha256'],
        'operations'=>$operations,'sequence_expiry'=>$produced['sequence'],'baseline'=>$seal];
}
function m4fn_diagnostic(?Throwable $error=null):?array
{
    if($error===null)return null;
    $code=$GLOBALS['m4fn_failed_assertion']??$GLOBALS['m5pcp_failed_assertion']??$GLOBALS['m4c_failed_assertion']??'TASK6_UNCLASSIFIED_FAILURE';
    if(!is_string($code)||preg_match('/\A[A-Z0-9_]+\z/D',$code)!==1)$code='TASK6_FIXED_FAILURE';
    return ['code'=>$code,'class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()];
}
function m4fn_result(array $source,?array $business,?array $observer,?array $diagnostic,bool $success):array
{
    return ['schema'=>1,'scope'=>M4FN_RULE['scope'],'status'=>$success?M4FN_RULE['status']:'INCOMPLETE_MS4_FULFILLMENT_INTERNAL_ONLY',
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
        'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'environment_sha256'=>$source['environment_sha256'],
        'environment_native_sha256'=>$source['environment_native_sha256'],'prerequisites_sha256'=>$source['prerequisites_sha256'],
        'phase'=>$GLOBALS['m4c_phase']??'source','entry_return_code'=>$success?0:1,
        'termination'=>$success?'NORMAL_RETURN':'EXCEPTION','native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
        'full_source_unchanged'=>$success,'metadata_observer'=>$observer,'business'=>$business,'diagnostic'=>$diagnostic,
        'customer_fulfillment_API_DOM'=>'NOT RUN','merchant_fulfillment_API_DOM'=>'NOT RUN','platform_fulfillment_API_DOM'=>'NOT RUN',
        'worker'=>'NOT RUN','task7'=>'NOT RUN','full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN',
        'assertions'=>($GLOBALS['m4fn_assertions']??0)+($GLOBALS['m4c_assertions']??0)+($GLOBALS['m5pcp_assertions']??0)];
}
function m4fn_run():int
{
    m4fn_phase('fulfillment_source');$source=m4fn_source();$GLOBALS['m4fn_source']=$source;
    m4c_entry_public_materials($source);m4fn_full_source($source);$app=m4fn_app($source);$db=audit_db();
    m4fn_expect(!$db->inTransaction(),'TASK6_ONE_INDEPENDENT_OBSERVER');$observer=m5pc_observer_configure($db);
    $GLOBALS['m4fn_observer']=$observer;m4fn_phase('original_paid_owner_and_native_archive');$owner=m4fn_owner($source,$db);
    m4fn_migration_database($db,$owner);m4cb_guard_readonly();
    $GLOBALS['m4tr_read_stages']=[];$GLOBALS['m4tr_legacy_child']=[];
    $GLOBALS['m4tr_prepared']=m4tr_prepare_native($app,$db,$source,$owner,$GLOBALS['m4tr_legacy_child']);
    $produced=m4fn_produce($app,$db,$source,$owner);$sealed=m4fn_seal($source,$owner,$produced,$db);
    m4fn_phase('final_source');m4fn_full_source($source);m4fn_prerequisites($source);m4fn_inputs_unchanged($owner['inputs']);
    m4fn_expect(m4fn_source()===$source,'TASK6_FULL_ACTUAL_SOURCE_ENV_ROLES_PREREQUISITES_UNCHANGED');m4cb_guard_readonly();
    m4fn_zero($produced['final'],m4fn_snapshot($db));$business=m4fn_business($owner,$produced,$sealed);
    m4tr_publish_result($source,true,null);
    $result=m4fn_result($source,$business,$observer,null,true);m4c_entry_publish('/run/audit/evidence/'.M4FN_RULE['result'],$result);
    $GLOBALS['m4fn_result_published']=true;$GLOBALS['m4fn_completed']=true;
    unset($owner,$produced,$business,$result,$db,$app);
    echo json_encode(['scope'=>M4FN_RULE['scope'],'status'=>M4FN_RULE['status'],'phase'=>'final_source',
        'entry_return_code'=>0,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    $m4fnFatalReserve=str_repeat(' ',65536);
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4fn_failed_assertion']='TASK6_PHP_WARNING';throw new RuntimeException('TASK6_PHP_WARNING');});
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
    register_shutdown_function(static function()use(&$m4fnFatalReserve):void{
        $last=error_get_last();$m4fnFatalReserve=null;
        if(is_array($last)&&is_int($last['type']??null)
            &&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true)){
            set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{return true;});
            try{
                $category='TASK6_NATIVE_FATAL_GENERAL';$message=$last['message']??null;
                if($last['type']===E_ERROR&&is_string($message)&&str_starts_with($message,'Allowed memory size of '))
                    $category='TASK6_NATIVE_FATAL_MEMORY_LIMIT';
                elseif(in_array($last['type'],[E_ERROR,E_COMPILE_ERROR],true)&&is_string($message)
                    &&(str_starts_with($message,'Cannot declare ')||str_starts_with($message,'Cannot redeclare ')
                        ||str_starts_with($message,'Declaration of ')))$category='TASK6_NATIVE_FATAL_DECLARATION';
                unset($message);
                $file=$last['file']??null;
                if(is_string($file))$file=basename(str_replace('\\','/',$file));
                if(!is_string($file)||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\z/D',$file)!==1)$file=null;
                $line=$last['line']??null;if(!is_int($line)||$line<0)$line=null;
                $phase=$GLOBALS['m4c_phase']??null;
                if(!is_string($phase)||preg_match('/\A[a-z][a-z0-9_]{0,95}\z/D',$phase)!==1)$phase=null;
                $limit=ini_get('memory_limit');
                if(!is_string($limit)||strlen($limit)>32||preg_match('/\A(?:-1|[0-9]+[KMG]?)\z/Di',$limit)!==1)$limit=null;
                $json=json_encode(['scope'=>'MS4_FULFILLMENT_NATIVE_FATAL_DIAGNOSTIC','category'=>$category,'type'=>$last['type'],
                    'file'=>$file,'line'=>$line,'phase'=>$phase,'memory_limit'=>$limit,'peak_bytes'=>memory_get_peak_usage(true)],
                    JSON_UNESCAPED_SLASHES);
                if(is_string($json)&&strlen($json)+1<=4096&&defined('STDERR')&&is_resource(STDERR)){
                    $raw=$json."\n";$offset=0;
                    while($offset<strlen($raw)){$written=fwrite(STDERR,substr($raw,$offset));
                        if(!is_int($written)||$written<=0)break;$offset+=$written;}
                }
            }catch(Throwable $fatalDiagnosticError){}
            finally{restore_error_handler();}
        }
        $source=$GLOBALS['m4fn_source']??null;if(!is_array($source))return;
        m4tr_close_legacy_on_failure();
        $fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
        $diagnostic=$GLOBALS['m4fn_primary_failure']??null;
        $shutdown=['schema'=>1,'scope'=>'MS4_FULFILLMENT_NORMAL_SHUTDOWN','run_id'=>$source['run_id'],
            'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],
            'phase'=>$GLOBALS['m4c_phase']??'source','completed'=>($GLOBALS['m4fn_completed']??false)===true,
            'result_published'=>($GLOBALS['m4fn_result_published']??false)===true,'fatal'=>$fatal,
            'termination_requested'=>($GLOBALS['m4c_terminate_requested']??false)===true,'primary_failure'=>$diagnostic,
            'cleanup_failure'=>$GLOBALS['m4tr_cleanup_failure']??null,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'];
        try{m4c_entry_publish('/run/audit/evidence/fulfillment-normal-shutdown.json',$shutdown);}
        catch(Throwable $error){fwrite(STDERR,"TASK6 shutdown receipt unavailable; host native observation required\n");}
    });
    try{exit(m4fn_run());}
    catch(Throwable $error){$diagnostic=m4fn_diagnostic($error);$GLOBALS['m4fn_primary_failure']=$diagnostic;$source=$GLOBALS['m4fn_source']??null;
        m4tr_close_legacy_on_failure();
        if(is_array($source)&&empty($GLOBALS['m4tr_result_published'])){
            try{m4tr_publish_result($source,false,$diagnostic);}catch(Throwable $supplementError){}
        }
        if(is_array($source)&&empty($GLOBALS['m4fn_result_published'])){
            try{m4c_entry_publish('/run/audit/evidence/'.M4FN_RULE['result'],m4fn_result($source,null,$GLOBALS['m4fn_observer']??null,$diagnostic,false));
                $GLOBALS['m4fn_result_published']=true;}catch(Throwable $publicationError){}
        }
        echo json_encode(['scope'=>M4FN_RULE['scope'],'status'=>'INCOMPLETE_MS4_FULFILLMENT_INTERNAL_ONLY',
            'failed_assertion'=>$diagnostic['code'],'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";exit(1);
    }
}
