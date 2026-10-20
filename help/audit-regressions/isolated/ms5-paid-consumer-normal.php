<?php
declare(strict_types=1);

/** SOURCE CANDIDATE: one same-App paid producer; no paid HTTP endpoint or driver restoration. */
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceOrderCreateServices;
use app\services\marketplace\order\MarketplaceTradePolicy;
use app\services\marketplace\payment\MarketplacePaymentServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderServices;
use app\services\user\UserServices;
use crmeb\services\CacheService;
use crmeb\services\pay\marketplace\PaymentAccountResolver;
use crmeb\services\pay\marketplace\PaymentAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use think\facade\Db;

require_once __DIR__.'/ms4-customer-http-normal.php';
const M5PC_ENTRY='help/audit-regressions/isolated/ms5-paid-consumer-normal.php';
const M5PC_RULE=['role'=>'audit-ms5-paid-producer','proof'=>'paid-producer','binding'=>'paid-producer-source-binding.json',
    'binding_scope'=>'MS5_PAID_PRODUCER_SOURCE','environment'=>'paid-producer-environment.json',
    'result'=>'paid-producer-result.json','scope'=>'MS5_PAID_PRODUCER_INTERNAL_ONLY','status'=>'VERIFIED_MS5_PAID_PRODUCER_INTERNAL_ONLY'];
const M5PC_BASELINE='/run/audit-fixtures/ms5-paid-baseline.json';
const M5PC_AUX_TABLES=['eb_theme','eb_lang_type','eb_lang_country','eb_lang_code','eb_store_visit','eb_user_visit','eb_message_system'];

function m5pc_hex(mixed $value,int $length=64): bool
{
    return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;
}
function m5pc_environment(array $binding,array $context): array
{
    $base='/run/audit/evidence/';$receipt=m4c_entry_read($base.M5PC_RULE['environment']);
    m4c_entry_keys($receipt,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4c_expect($receipt['schema']===1&&$receipt['scope']==='MS5_PAID_PRODUCER_REAL_ENVIRONMENT'
        &&$receipt['run_id']===$binding['run_id']&&$receipt['source_sha']===$binding['source_sha']
        &&$receipt['container_id']===$binding['container_id']&&$receipt['freeze_sha256']===$context['freeze_sha256']
        &&getenv('MS5_PROOF_NAME')===M5PC_RULE['proof'],'M4C_PAID_REAL_ENVIRONMENT_IDENTITY');
    $native=m4c_entry_read($base.M5PC_RULE['proof'].'-environment-native.json');
    m4c_entry_keys($native,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
    m4c_expect($receipt['native_projection_sha256']===$native['stdout_sha256'],'M4C_PAID_REAL_NATIVE_ENVIRONMENT_FULL_HASH');
    return m4c_entry_native_environment($receipt['environment'],$native);
}
function m5pc_inventory(string $root): array
{
    $paths=array_merge(M4C_EXTRA,[M5PC_ENTRY]);
    foreach(M4C_ROOTS as $directory){m4c_expect(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory),'M4C_SOURCE_ROOT');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){m4c_expect(!$file->isLink(),'M4C_SOURCE_NO_LINK');if(!$file->isFile())continue;
            $paths[]=substr($file->getPathname(),strlen($root)+1);}}
    sort($paths,SORT_STRING);m4c_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'M4C_SOURCE_UNIQUE_CLOSED_PATHS');
    return $paths;
}
function m5pc_full_source(array $source,bool $beforePublicCopy=false): void
{
    $allowed=[];
    $rows=$source['source_files'];m4c_expect(array_is_list($rows),'M4C_SOURCE_DESCRIPTOR_LIST');$paths=[];
    foreach($rows as $row){m4c_expect(is_array($row),'M4C_SOURCE_DESCRIPTOR_OBJECT');m4c_entry_keys($row,['path','bytes','sha256']);
        m4c_expect(is_string($row['path'])&&$row['path']!==''&&$row['path'][0]!=='/'&&!str_contains($row['path'],'\\')
            &&!str_contains($row['path'],':')&&!in_array('..',explode('/',$row['path']),true)
            &&is_int($row['bytes'])&&$row['bytes']>=0&&is_string($row['sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,'M4C_CANONICAL_SOURCE_DESCRIPTOR');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);
    m4c_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),'M4C_SORTED_EXACT_SOURCE_DESCRIPTORS');
    foreach(['/source','/workspace'] as $root){m4c_expect(m5pc_inventory($root)===$paths,'M4C_ALL_SOURCE_FILES_CLOSED_SET');
        foreach($rows as $row){
            if($beforePublicCopy&&$root==='/workspace'&&in_array($row['path'],['crmeb/public/index.php','crmeb/public/router.php'],true))continue;
            $path=$root.'/'.$row['path'];m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'M4C_FULL_SOURCE_ACTUAL_BYTES');
            $allowed[$path]=true;}}
    foreach(get_included_files()as$path){$real=realpath($path);m4c_expect(is_string($real)&&isset($allowed[$real]),'M4C_PAID_INCLUDED_COMPLETE_SOURCE_CLOSURE');}
}
function m5pc_source(): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'produce']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_ACTUAL_FINITE_NATIVE_ENTRY');
    $rule=M5PC_RULE;$evidence='/run/audit/evidence/';
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
    $roles=array_keys($manifest['services']);sort($roles,SORT_STRING);$wanted=[$rule['role'],'audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
    m4c_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'M4C_ONLY_THREE_ACTUAL_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    $paidExtra=array_merge($sortedExtra,[M5PC_ENTRY]);sort($paidExtra,SORT_STRING);
    m4c_expect($freeze['paid_source_roots']===M4C_ROOTS&&$freeze['paid_source_extra']===$paidExtra
        &&array_values(array_filter($freeze['paid_source_files'],static fn(array $row):bool=>$row['path']!==M5PC_ENTRY))
            ===$freeze['customer_source_files'],'M4C_PAID_ONE_EXPLICIT_TENTH_EXTRA_AND_ORIGINAL_CUSTOMER_CLOSURE');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
    m4c_expect($binding['schema']===1&&$binding['scope']===$rule['binding_scope']&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$rule['proof'].'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['paid_source_files']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha
        &&$created['proof_name']===$rule['proof']&&$created['hostname']===gethostname()&&$created['read_only']===true
        &&$created['user']==='0:0'&&posix_geteuid()===0&&posix_getegid()===0
        &&$created['privileged']===false&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M5PC_ENTRY,'produce']&&$created['workdir']==='/workspace'
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
    m4c_expect($created['stop_signal']==='SIGTERM'
        &&in_array('audit-app',$created['networks'][$network['Name']]['Aliases']??[],true)
        &&filter_var(gethostbyname('audit-app'),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false
        &&gethostbyname('audit-app')===gethostbyname(gethostname()),'M4C_REAL_SELF_HTTP_NETWORK_ALIAS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',true]];
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
    $values=m5pc_environment($binding,$context);
    audit_guard_assert();
    $source=$binding+['rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment']),
        'environment_native_sha256'=>m4c_entry_hash($evidence.$rule['proof'].'-environment-native.json')];
    m5pc_full_source($source,true);
    m5pc_prerequisites($source);
    return $source;
}
function m5pc_app(array $source): \think\App
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
    $runtime='/tmp/ms5-paid-producer-'.$source['run_id'].'/';
    m4c_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4C_DISTINCT_OWN_NATIVE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'REDIS_REDIS_HOSTNAME'=>'audit-redis',
        'REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,'CACHE_CACHE_PREFIX'=>'c:',
        'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]);
    $app->register(\app\AppService::class);$app->bootService($app->getService(\app\AppService::class));
    $app->bind(require '/workspace/crmeb/app/provider.php');
    foreach(['app','cache','log','marketplace','auth']as$name)$app->config->set(require '/workspace/crmeb/config/'.$name.'.php',$name);
    $database=require '/workspace/crmeb/config/database.php';
    $database['connections']['mysql']['params']=[PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    $database['connections']['mysql']['break_reconnect']=false;$app->config->set($database,'database');
    require_once '/workspace/crmeb/app/common.php';$app->register(\think\service\ModelService::class);
    $app->bootService($app->getService(\think\service\ModelService::class));date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));
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

function m5pc_native_shape(array $snapshot): void
{
    m4c_entry_keys($snapshot,['facts','auxiliary_rows']);
    m4c_expect(count(M4CB_TABLES)===27&&count(M5PC_AUX_TABLES)===7
        &&array_keys($snapshot['facts'])===M4CB_TABLES&&array_keys($snapshot['auxiliary_rows'])===M5PC_AUX_TABLES,
        'M4C_H5_STAGE_EXACT_THIRTY_FOUR_TABLES');$cells=0;
    foreach([...$snapshot['facts'],...$snapshot['auxiliary_rows']]as$rows){
        m4c_expect(is_array($rows)&&array_is_list($rows)&&count($rows)<=100000,'M4C_H5_STAGE_NATIVE_ROW_LIST');
        foreach($rows as$row){m4c_expect(is_array($row)&&!array_is_list($row)&&count($row)<=512,'M4C_H5_STAGE_NATIVE_COLUMN_MAP');
            foreach($row as$column=>$value){m4c_expect(is_string($column)&&preg_match('/\A[a-z_][a-z0-9_]*\z/D',$column)===1
                &&($value===null||is_string($value)||is_int($value)||is_bool($value)||(is_float($value)&&is_finite($value))),
                'M4C_H5_STAGE_NATIVE_SCALAR_TYPES');m4c_expect(++$cells<=1000000,'M4C_H5_STAGE_BOUNDED_NATIVE_CELLS');}
        }
    }
}
function m5pc_native_snapshot(PDO $pdo): array
{
    m4c_expect(!$pdo->inTransaction(),'M4C_H5_STAGE_REAL_NATIVE_READER_OUTSIDE_TRANSACTION');$auxiliary=[];
    foreach(M5PC_AUX_TABLES as$table)$auxiliary[$table]=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`');
    $snapshot=['facts'=>m4cb_snapshot($pdo),'auxiliary_rows'=>$auxiliary];m5pc_native_shape($snapshot);return $snapshot;
}
function m5pc_native_serialized(array $snapshot): array
{
    m5pc_native_shape($snapshot);$bytes=serialize($snapshot);
    m4c_expect(strlen($bytes)<=1572864,'M4C_H5_STAGE_BOUNDED_NATIVE_SERIALIZATION');
    return ['snapshot_base64'=>base64_encode($bytes),'snapshot_sha256'=>hash('sha256',$bytes)];
}
function m5pc_native_unserialize(array $stage): array
{
    m4c_expect(is_string($stage['snapshot_base64']??null)&&strlen($stage['snapshot_base64'])<=2097152
        &&m5pc_hex($stage['snapshot_sha256']??null),'M4C_H5_STAGE_SEALED_NATIVE_ENCODING');
    $bytes=base64_decode($stage['snapshot_base64'],true);
    m4c_expect(is_string($bytes)&&strlen($bytes)>0&&strlen($bytes)<=1572864
        &&base64_encode($bytes)===$stage['snapshot_base64']&&hash('sha256',$bytes)===$stage['snapshot_sha256'],
        'M4C_H5_STAGE_FULL_NATIVE_SERIALIZED_DIGEST');
    $value=unserialize($bytes,['allowed_classes'=>false,'max_depth'=>16]);
    m4c_expect(is_array($value)&&serialize($value)===$bytes,'M4C_H5_STAGE_NATIVE_UNSERIALIZE_NO_OBJECT_OR_REWRITE');
    m5pc_native_shape($value);return $value;
}
/** Exact native baseline envelope; the existing stage wire and size bounds stay unchanged. */
function m5pc_native_snapshot_archive(array $snapshot): array
{
    $stage=m5pc_native_serialized($snapshot);$raw=base64_decode($stage['snapshot_base64'],true);
    m4c_expect(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=1572864,'M4C_H5_BASELINE_NATIVE_ARCHIVE_BYTES');
    $archive=['encoding'=>'php-serialize-base64-v1','bytes'=>strlen($raw),
        'sha256'=>$stage['snapshot_sha256'],'base64'=>$stage['snapshot_base64']];
    m4c_expect(m5pc_native_snapshot_restore($archive)===$snapshot,'M4C_H5_BASELINE_NATIVE_ARCHIVE_FULL_READBACK');
    return $archive;
}
function m5pc_native_snapshot_restore(array $archive): array
{
    m4c_entry_keys($archive,['encoding','bytes','sha256','base64']);
    m4c_expect($archive['encoding']==='php-serialize-base64-v1'&&is_int($archive['bytes'])
        &&$archive['bytes']>0&&$archive['bytes']<=1572864,'M4C_H5_BASELINE_EXACT_NATIVE_ARCHIVE');
    $snapshot=m5pc_native_unserialize(['snapshot_base64'=>$archive['base64'],'snapshot_sha256'=>$archive['sha256']]);
    $raw=base64_decode($archive['base64'],true);
    m4c_expect(is_string($raw)&&strlen($raw)===$archive['bytes'],'M4C_H5_BASELINE_ACTUAL_NATIVE_ARCHIVE_LENGTH');
    return $snapshot;
}


function m5pc_prerequisites(array $source): void
{
    $base='/run/audit/evidence/';$names=array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES);
    $hashes=$source['prerequisites_sha256'];m4c_entry_keys($hashes,$names);
    foreach($names as$name)m4c_expect($hashes[$name]===m4c_entry_hash($base.$name),'M4C_PAID_ALL_FIFTEEN_ACTUAL_PREREQUISITES');
    $history=m4c_entry_ms5_history($source);$manifest=audit_manifest();$formal=$history['formal'];
    foreach(['run_id','commit_sha','project','guard_mode','isolation','network_internal','network','volumes','volume_identities']as$key)
        m4c_entry_same($formal[$key],$manifest[$key]);
    foreach(['audit-mysql','audit-redis']as$role)foreach(['id','image_id','address']as$key)
        m4c_expect($formal['services'][$role][$key]===$manifest['services'][$role][$key],'M4C_PAID_ORIGINAL_DATABASE_ENDPOINTS');
    $prepare=m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);
    m4c_expect(count(array_unique([$history['initializer_id'],$history['migration_id'],$history['files_id'],$prepare['id'],$source['container_id']],SORT_STRING))===5,
        'M4C_PAID_DISTINCT_REAL_FIVE_CIDS');
    $prepareBinding=m4c_entry_read($base.M4C_MODE['prepare']['binding']);
    $wanted=['schema'=>1,'scope'=>M4C_MODE['prepare']['binding_scope'],'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'container_id'=>$prepare['id'],'native_created_sha256'=>$hashes['customer-prepare-created.json'],'freeze_sha256'=>$source['freeze_sha256'],
        'requested_repo_source'=>$source['requested_repo_source'],'requested_run_source'=>$source['requested_run_source'],
        'requested_prep_source'=>$source['requested_prep_source'],'entry_sha256'=>m4c_entry_hash('/workspace/'.M4C_ENTRY),
        'source_files'=>$source['freeze']['customer_source_files'],'prerequisites_sha256'=>array_intersect_key($hashes,
            array_flip(array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES)))];
    m4c_entry_same($prepareBinding,$wanted);
    m4c_entry_same(m4c_entry_environment($prepareBinding,$source['context'],'customer-prepare'),$source['environment']);
    $result=m4c_entry_read($base.'ms4-customer-prepare.json');
    m4c_expect($result['schema']===1&&$result['scope']===M4C_MODE['prepare']['scope']&&$result['status']===M4C_MODE['prepare']['status']
        &&$result['run_id']===$source['run_id']&&$result['source_sha']===$source['source_sha']&&$result['container_id']===$prepare['id']
        &&$result['freeze_sha256']===$source['freeze_sha256']&&$result['native_created_sha256']===$hashes['customer-prepare-created.json']
        &&$result['source_binding_sha256']===m4c_entry_hash($base.M4C_MODE['prepare']['binding'])
        &&$result['environment_sha256']===m4c_entry_hash($base.M4C_MODE['prepare']['environment'])
        &&$result['phase']==='final_source'&&$result['native_exit']===0&&$result['full_source_unchanged']===true&&$result['child']===null
        &&is_array($result['business'])&&$result['business']['HTTP_executed']===false&&$result['business']['order_creation_executed']===false
        &&$result['business']['fixture_counts']===['customers'=>1,'merchants'=>1,'products'=>2,'skus'=>3,'cart_rows'=>0,'orders'=>0],
        'M4C_PAID_ORIGINAL_SOURCE_BOUND_C3_RESULT');
    foreach($names as$name)m4c_expect($hashes[$name]===m4c_entry_hash($base.$name),'M4C_PAID_ALL_FIFTEEN_PREREQUISITES_UNCHANGED');
}
function m5pc_observer_configure(PDO $db): array
{
    m4c_expect($db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false),'M4C_PAID_NATIVE_INDEPENDENT_PREPARES');
    $before=m5pcp_observer_modes($db);
    m4c_expect($db->exec('SET SESSION information_schema_stats_expiry=0')===0,'M4C_PAID_ONLY_INDEPENDENT_OBSERVER_SESSION_SET');
    $configured=m5pcp_observer_modes($db);$expected=$before;
    $expected[0]['session_stats_expiry']=$configured[0]['session_stats_expiry'];
    m4c_expect(m5pcp_int($configured[0]['session_stats_expiry'],0,0)===0&&$configured===$expected,
        'M4C_PAID_ONLY_SESSION_EXPIRY_CHANGED_FIVE_OTHER_NATIVE_MODES_IDENTICAL');
    return ['scope'=>'independent_pdo_session_only','before'=>$before,'configured'=>$configured];
}
/** Complete saved shape, first admitted by the existing exact two-item unpaid customer oracle. */
function m5pcp_saved(PDO $db,int $id): array
{
    $rows=m5pcp_rows($db,'SELECT * FROM `eb_store_order` WHERE `id` = ?',[$id]);
    m5pcp_expect(count($rows)===1,'ONE_REAL_SAVED_PAYMENT_ROOT');$root=$rows[0];
    m5pcp_expect(m5pcp_int($root['id'],1)===$id&&m5pcp_int($root['pid'],0,0)===0
        &&m5pcp_int($root['marketplace_trade_version'],1,1)===1,'ACTUAL_CUSTOMER_V1_COMMERCIAL_ROOT');
    $items=m5pcp_rows($db,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]);
    $ledgers=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `order_id` = ?',[$id]);
    $reserves=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `id`',[$id]);
    $invoice=m5pcp_rows($db,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id]);
    m5pcp_expect(count($items)===2&&count($ledgers)===1&&count($reserves)===2&&count($invoice)===1
        &&m5pcp_int($invoice[0]['is_pay'],0,0)===0,'REAL_TWO_ITEMS_TWO_RESERVES_ONE_UNPAID_INVOICE');$ledger=$ledgers[0];
    foreach(['uid','mer_id']as$field)m5pcp_expect(m5pcp_u64($ledger[$field])===m5pcp_u64($root[$field]),'SAVED_LEDGER_SAME_ACTUAL_OWNER_MERCHANT');
    m5pcp_expect(m5pcp_int($ledger['order_id'],1)===$id&&m5pcp_int($ledger['state'],1,1)===1
        &&m5pcp_int($ledger['post_commit_done'],1,1)===1&&$ledger['source_kind']==='persistent'
        &&$ledger['confirmation_ref']===hash('sha256',$root['unique'],true),'COMPLETE_ACTUAL_COMMITTED_LEDGER');
    $refs=json_decode($root['cart_id'],true,32,JSON_THROW_ON_ERROR);
    m5pcp_expect(is_array($refs)&&array_is_list($refs)&&count($refs)===2
        &&$refs===json_decode($ledger['cart_refs'],true,32,JSON_THROW_ON_ERROR),'TWO_ORIGINAL_SAVED_REFERENCE_ORDER');
    foreach($refs as$ref)m5pcp_expect(is_string($ref)&&m5pcp_u64($ref)===$ref,'SAVED_CART_REFERENCE_STAYS_CANONICAL_STRING');
    foreach($reserves as$reserve)m5pcp_expect($reserve['kind']==='reserve'&&m5pcp_u64($reserve['id'])!==''
        &&$reserve['parent_reserve_id']===null,'REAL_ORIGINAL_UNRELEASED_RESERVES');
    return ['order'=>$root,'items'=>$items,'ledger'=>$ledger,'refs'=>$refs,'reserves'=>$reserves,'invoice'=>$invoice];
}
/** Only this genuine persistent order number generated a Redis snowflake sequence key. */
function m5pcp_sequence_keys(PDO $db,\think\App $app,array $saved): array
{
    m5pcp_expect(PHP_INT_SIZE===8&&app()===$app&&date_default_timezone_get()==='Asia/Shanghai'
        &&$app->config->get('app.default_timezone')==='Asia/Shanghai'
        &&$app->config->get('cache.default')==='redis'&&$app->config->get('cache.stores.redis.type')==='redis',
        'ACTUAL_SEQUENCE_GENERATOR_ENVIRONMENT');
    $epochSeconds=strtotime('2022-01-01');m5pcp_expect(is_int($epochSeconds)&&$epochSeconds>0,'ACTUAL_SEQUENCE_EPOCH');
    $epochMs=bcmul((string)$epochSeconds,'1000',0);m5pcp_expect($epochMs==='1640966400000','ACTUAL_SHANGHAI_SEQUENCE_EPOCH_MS');
    m5pcp_expect(m5pcp_saved($db,m5pcp_int($saved['order']['id'],1))===$saved&&$saved['ledger']['source_kind']==='persistent'
        &&count($saved['refs'])===2,'ACTUAL_SINGLE_PERSISTENT_SEQUENCE_PRODUCER');
    $value=$saved['order']['order_id'];$prefix='cp';
    m5pcp_expect(is_string($value)&&str_starts_with($value,$prefix)&&strlen($value)>2,'EXACT_SAVED_SEQUENCE_PREFIX');
    $suffix=substr($value,2);m5pcp_expect($prefix.$suffix===$value&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$suffix)===1
        &&bccomp($suffix,'9223372036854775807',0)<=0,'EXACT_NATIVE_POSITIVE_SNOWFLAKE_SUFFIX');
    $offset=bcdiv($suffix,'4194304',0);$key=bcadd($epochMs,$offset,0);
    m5pcp_expect(bccomp($offset,'2199023255551',0)<=0&&preg_match('/\A[1-9][0-9]{12}\z/D',$key)===1,
        'EXACT_BC_SEQUENCE_UNIX_MILLISECOND_KEY');
    return ['epoch_ms'=>$epochMs,'source_id_count'=>1,'keys'=>[hash('sha256',$key)=>['key'=>$key,
        'sources'=>[['source_kind'=>'persistent_order','identifier_sha256'=>hash('sha256',$value)]]]]];
}
const M5PCP_REGISTRY=['order_id','uid','mer_id','payment_contract_version','born_at','attempt_generation',
    'winner_attempt_id','fulfillment_hold','hold_reason','hold_attempt_id','hold_at'];
const M5PCP_ATTEMPT=['id','order_id','uid','mer_id','payment_contract_version','admitted_generation','order_no',
    'idem_key','amount','currency','account_snapshot','request_digest','binding_digest','account_domain_digest',
    'created_at','attempt_ref','applied_trade_no','evidence'];
const M5PCP_TARGETS=['eb_store_order','eb_marketplace_payment_registry','eb_marketplace_payment_attempt'];


function m5pcp_expect(bool $ok,string $code): void
{
    $GLOBALS['m5pcp_assertions']=($GLOBALS['m5pcp_assertions']??0)+1;
    if(!$ok){$GLOBALS['m5pcp_failed_assertion']=$code;throw new RuntimeException('M5PN_ASSERTION');}
}

function m5pcp_keys(array $value,array $keys): bool
{
    $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);return $actual===$keys;
}

function m5pcp_same(mixed $actual,mixed $expected): void
{
    if(!is_array($expected)){m5pcp_expect($actual===$expected,'EXACT_VALUE_AND_TYPE');return;}
    m5pcp_expect(is_array($actual)&&array_is_list($actual)===array_is_list($expected)
        &&m5pcp_keys($actual,array_keys($expected)),'EXACT_ARRAY_SHAPE');
    foreach($expected as $key=>$value)m5pcp_same($actual[$key],$value);
}

function m5pcp_canonical(mixed $value,int $depth=0): string
{
    $normalize=static function(mixed $item,int $level)use(&$normalize): mixed{
        m5pcp_expect($level<=24,'CANONICAL_DEPTH');
        if(is_array($item)){
            if(!array_is_list($item)){
                foreach(array_keys($item)as$key)m5pcp_expect(is_string($key),'CANONICAL_OBJECT_KEY');
                ksort($item,SORT_STRING);
            }
            foreach($item as$key=>$child)$item[$key]=$normalize($child,$level+1);
            return $item;
        }
        m5pcp_expect(is_int($item)||is_bool($item)||$item===null||is_string($item),'CANONICAL_SCALAR');
        if(is_string($item))m5pcp_expect(preg_match('//u',$item)===1&&!str_contains($item,"\0"),'CANONICAL_TEXT');
        return $item;
    };
    return json_encode($normalize($value,$depth),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR,32);
}

function m5pcp_u64(mixed $value,bool $zero=false): string
{
    m5pcp_expect((is_int($value)||is_string($value))&&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',(string)$value)===1,
        'NATIVE_U64_TEXT');
    $value=(string)$value;
    m5pcp_expect(($zero||$value!=='0')&&bccomp($value,'18446744073709551615',0)<=0,'NATIVE_U64_RANGE');return $value;
}

function m5pcp_int(mixed $value,int $minimum=0,int $maximum=2147483647): int
{
    $text=m5pcp_u64($value,true);m5pcp_expect(bccomp($text,(string)$minimum,0)>=0
        &&bccomp($text,(string)$maximum,0)<=0,'BOUNDED_NATIVE_INTEGER');return (int)$text;
}

function m5pcp_quote(string $name): string
{
    m5pcp_expect(preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D',$name)===1,'EXACT_SQL_IDENTIFIER');return '`'.$name.'`';
}

function m5pcp_rows(PDO $db,string $sql,array $values=[]): array
{
    $statement=$db->prepare($sql);m5pcp_expect($statement instanceof PDOStatement,'NATIVE_PREPARE');
    foreach(array_values($values)as$i=>$value){
        m5pcp_expect(is_int($value)||is_string($value),'READ_ONLY_BOUND_SCALAR');
        m5pcp_expect($statement->bindValue($i+1,(string)$value,PDO::PARAM_STR),'NATIVE_STRING_BIND');
    }
    m5pcp_expect($statement->execute(),'NATIVE_READ');return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function m5pcp_metadata(PDO $db,string $table): array
{
    $args=[$table];
    $tables=m5pcp_rows($db,'SELECT TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',$args);
    m5pcp_expect(count($tables)===1&&$tables[0]['TABLE_TYPE']==='BASE TABLE','COMPLETE_BASE_TABLE_METADATA');$columns=[];
    foreach(m5pcp_rows($db,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',$args)as$row)$columns[$row['COLUMN_NAME']]=$row;
    $indexes=[];foreach(m5pcp_rows($db,'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',$args)as$row)$indexes[$row['INDEX_NAME']][]=$row;
    ksort($indexes,SORT_STRING);
    return ['table'=>$tables[0],'columns'=>$columns,'indexes'=>$indexes,
        'constraints'=>m5pcp_rows($db,'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME',$args),
        'checks'=>m5pcp_rows($db,"SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME",$args),
        'foreign'=>m5pcp_rows($db,'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION',$args),
        'triggers'=>m5pcp_rows($db,'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME',$args)];
}

function m5pcp_projection(PDO $db,string $table,array $columns): array
{
    m5pcp_expect($columns!==[],'COMPLETE_COLUMN_PROJECTION');
    $statement=$db->query('SELECT '.implode(',',array_map('m5pcp_quote',$columns)).' FROM '.m5pcp_quote($table));
    m5pcp_expect($statement instanceof PDOStatement,'COMPLETE_NATIVE_ROW_PROJECTION');$hashes=[];
    while(($row=$statement->fetch(PDO::FETCH_ASSOC))!==false)$hashes[]=hash('sha256',serialize($row));
    sort($hashes,SORT_STRING);
    return ['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
}
/** Read the original native modes together with the independent metadata-cache policy. */

function m5pcp_observer_modes(PDO $db): array
{
    $rows=m5pcp_rows($db,'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb,@@SESSION.information_schema_stats_expiry AS session_stats_expiry,@@GLOBAL.information_schema_stats_expiry AS global_stats_expiry');
    m5pcp_expect(count($rows)===1&&array_keys($rows[0])===['session_mode','global_mode','session_innodb','global_innodb',
        'session_stats_expiry','global_stats_expiry'],'COMPLETE_NATIVE_SIX_OBSERVER_MODES');
    foreach(['session_stats_expiry','global_stats_expiry']as$field)m5pcp_int($rows[0][$field],0,4294967295);
    return $rows;
}

function m5pcp_database_snapshot(PDO $db): array
{
    m5pcp_expect(!$db->inTransaction()&&$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false),'INDEPENDENT_NATIVE_SNAPSHOT');
    $modes=m5pcp_observer_modes($db);
    m5pcp_expect(m5pcp_int($modes[0]['session_stats_expiry'],0,0)===0,'FRESH_INDEPENDENT_METADATA_STATISTICS');
    $names=$db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    sort($names,SORT_STRING);m5pcp_expect(count($names)===count(array_unique($names,SORT_STRING)),'COMPLETE_UNIQUE_TABLE_NAMES');
    $snapshot=['names'=>$names,'tables'=>[],'redis'=>[],
        'modes'=>$modes,
        'increment'=>m5pcp_rows($db,'SELECT @@SESSION.auto_increment_increment AS increment_value,@@SESSION.auto_increment_offset AS offset_value,@@GLOBAL.auto_increment_increment AS global_increment,@@GLOBAL.auto_increment_offset AS global_offset'),
        'target_rows'=>[]];
    foreach($snapshot['increment'][0]as$value)m5pcp_expect(m5pcp_int($value,1,65535)===1,'ACTUAL_UNIT_INCREMENT_AND_OFFSET');
    foreach($names as$name){$metadata=m5pcp_metadata($db,$name);$columns=array_keys($metadata['columns']);
        $ddl=$db->query('SHOW CREATE TABLE '.m5pcp_quote($name))->fetch(PDO::FETCH_NUM);
        m5pcp_expect(is_array($ddl)&&is_string($ddl[1]??null),'ACTUAL_COMPLETE_SHOW_CREATE');
        $snapshot['tables'][$name]=['metadata'=>$metadata,'ddl'=>$ddl[1],'projected_columns'=>$columns,
            'projection'=>m5pcp_projection($db,$name,$columns)];
        if(in_array($name,M5PCP_TARGETS,true))$snapshot['target_rows'][$name]=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($name)
            .' ORDER BY '.($name==='eb_marketplace_payment_registry'?'`order_id`':'`id`'));
    }
    m5pcp_expect(m5pcp_observer_modes($db)===$modes,'COMPLETE_OBSERVER_MODES_UNCHANGED_DURING_DATABASE_SCAN');
    return $snapshot;
}

function m5pcp_snapshot(PDO $db): array
{
    $snapshot=m5pcp_database_snapshot($db);
    $raw=audit_snapshot(['tables'=>[],'redis'=>['*']]);$redis=audit_redis();
    foreach($raw['redis']['*']as$key=>$hash){$expiry=$redis->rawCommand('PEXPIRETIME',$key);$keyHash=hash('sha256',(string)$key);
        m5pcp_expect(is_int($expiry)&&($expiry===-1||$expiry>0)&&!isset($snapshot['redis'][$keyHash]),'REAL_REDIS_ABSOLUTE_EXPIRY');
        $snapshot['redis'][$keyHash]=['sha256'=>$hash,'expires'=>$expiry];}
    ksort($snapshot['redis'],SORT_STRING);return $snapshot;
}
/** Decode only the two real saved order numbers and three real temporary references. */

function m5pcp_atomic_redis(Redis $redis): array
{
    $lua=<<<'LUA'
local now = redis.call('TIME')
local names = redis.call('KEYS', '*')
table.sort(names)
local rows = {}
for _, key in ipairs(names) do
    local kind = redis.call('TYPE', key).ok
    local expiry = redis.call('PEXPIRETIME', key)
    local value
    if kind == 'set' then
        value = redis.call('SMEMBERS', key)
    else
        value = redis.call('DUMP', key)
    end
    rows[#rows + 1] = {key, kind, expiry, value}
end
return {now, rows}
LUA;
    $raw=$redis->rawCommand('EVAL_RO',$lua,0);
    m5pcp_expect(is_array($raw)&&array_is_list($raw)&&count($raw)===2
        &&is_array($raw[0])&&array_is_list($raw[0])&&count($raw[0])===2
        &&is_array($raw[1])&&array_is_list($raw[1]),'ATOMIC_REDIS_COMPLETE_NATIVE_REPLY');
    [$seconds,$microseconds]=$raw[0];
    m5pcp_expect(is_string($seconds)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$seconds)===1
        &&is_string($microseconds)&&preg_match('/\A[0-9]{1,6}\z/D',$microseconds)===1
        &&bccomp($microseconds,'999999',0)<=0,'ACTUAL_REDIS_TIME_NATIVE_DECIMALS');
    $nowMs=bcadd(bcmul($seconds,'1000',0),bcdiv($microseconds,'1000',0),0);$keys=[];
    foreach($raw[1]as$row){
        m5pcp_expect(is_array($row)&&array_is_list($row)&&count($row)===4&&is_string($row[0])
            &&is_string($row[1])&&$row[1]!==''&&$row[1]!=='none'&&is_int($row[2])
            &&($row[2]===-1||$row[2]>0),'ATOMIC_REDIS_COMPLETE_NATIVE_ROW');
        [$key,$type,$expiry,$value]=$row;$keyHash=hash('sha256',$key);
        m5pcp_expect(!isset($keys[$keyHash]),'ATOMIC_REDIS_UNIQUE_ALL_KEYS');
        if($type==='set'){
            m5pcp_expect(is_array($value)&&array_is_list($value),'ATOMIC_REDIS_COMPLETE_SET');
            foreach($value as$member)m5pcp_expect(is_string($member),'ATOMIC_REDIS_NATIVE_SET_MEMBER');
            m5pcp_expect(count($value)===count(array_unique($value,SORT_STRING)),'ATOMIC_REDIS_UNIQUE_SET_MEMBERS');
            sort($value,SORT_STRING);$hash=hash('sha256',"set\0".serialize($value));
        }else{m5pcp_expect(is_string($value),'ATOMIC_REDIS_COMPLETE_NATIVE_DUMP');$hash=hash('sha256',$value);}
        $keys[$keyHash]=['type'=>$type,'sha256'=>$hash,'expires'=>$expiry];
    }
    ksort($keys,SORT_STRING);return ['time'=>$raw[0],'time_ms'=>$nowMs,'keys'=>$keys];
}
/** Compare complete atomic observations while retaining each original absolute deadline. */
function m5pcp_redis_deadline_compatible(array $expected,array $current):bool
{
    $times=[];
    foreach([$expected,$current]as$snapshot){
        if(!m5pcp_keys($snapshot,['time','time_ms','keys'])||!is_array($snapshot['time'])
            ||!array_is_list($snapshot['time'])||count($snapshot['time'])!==2||!is_array($snapshot['keys']))return false;
        [$seconds,$microseconds]=$snapshot['time'];
        if(!is_string($seconds)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$seconds)!==1
            ||!is_string($microseconds)||preg_match('/\A[0-9]{1,6}\z/D',$microseconds)!==1
            ||bccomp($microseconds,'999999',0)>0||!is_string($snapshot['time_ms'])
            ||$snapshot['time_ms']!==bcadd(bcmul($seconds,'1000',0),bcdiv($microseconds,'1000',0),0))return false;
        $times[]=bcadd(bcmul($seconds,'1000000',0),$microseconds,0);
        $order=array_keys($snapshot['keys']);$sorted=$order;sort($sorted,SORT_STRING);if($order!==$sorted)return false;
        foreach($snapshot['keys']as$key=>$row){
            if(!m5pc_hex($key)||!is_array($row)||!m5pcp_keys($row,['type','sha256','expires'])
                ||!is_string($row['type'])||$row['type']===''||$row['type']==='none'||!m5pc_hex($row['sha256'])
                ||!is_int($row['expires'])||($row['expires']!==-1&&$row['expires']<=0))return false;
        }
    }
    if(bccomp($times[1],$times[0],0)<0)return false;
    foreach($expected['keys']as$key=>$row){
        if(!array_key_exists($key,$current['keys'])&&$row['expires']>0
            &&bccomp((string)$row['expires'],$current['time_ms'],0)<0)unset($expected['keys'][$key]);
    }
    return $expected['keys']===$current['keys'];
}

/** Only exact producer-derived keys may disappear, after their first observed real absolute expiry. */

function m5pcp_sequence_expiry_barrier(PDO $db,array $sequence): array
{
    m5pcp_expect(m5pcp_keys($sequence,['epoch_ms','source_id_count','keys'])&&$sequence['epoch_ms']==='1640966400000'
        &&$sequence['source_id_count']===1&&is_array($sequence['keys'])&&count($sequence['keys'])>=1
        &&count($sequence['keys'])<=1,'EXACT_SEQUENCE_BARRIER_INPUT');
    $started=hrtime(true);m5pcp_expect(is_int($started)&&$started>0,'REAL_MONOTONIC_SEQUENCE_BARRIER_START');$lastNative=$started;
    $bounded=static function()use($started,&$lastNative):int{
        $now=hrtime(true);m5pcp_expect(is_int($now)&&$now>=$lastNative&&$now-$started<=10000000000,
            'TEN_SECOND_MONOTONIC_SEQUENCE_BARRIER_BOUND');$lastNative=$now;return $now;
    };
    $redis=audit_redis();$bounded();$first=m5pcp_atomic_redis($redis);$bounded();
    $others=$first['keys'];$states=[];
    foreach($sequence['keys']as$keyHash=>$target){
        m5pcp_expect(is_string($keyHash)&&is_array($target)&&m5pcp_keys($target,['key','sources'])
            &&is_string($target['key'])&&hash('sha256',$target['key'])===$keyHash
            &&is_array($target['sources'])&&array_is_list($target['sources'])&&$target['sources']!==[],
            'EXACT_INTERNAL_SEQUENCE_TARGET');
        $initial=$first['keys'][$keyHash]??null;
        if($initial!==null)m5pcp_expect($initial['type']==='string'&&$initial['expires']>0
            &&bccomp((string)$initial['expires'],$first['time_ms'],0)>=0
            &&bccomp(bcsub((string)$initial['expires'],$first['time_ms'],0),'1000',0)<=0,
            'REAL_SEQUENCE_STRING_AND_FIRST_OBSERVED_ONE_SECOND_EXPIRY');
        $states[$keyHash]=['initial'=>$initial,'missing'=>$initial===null,
            'first_absence_ms'=>$initial===null?$first['time_ms']:null];unset($others[$keyHash]);
    }
    $lastServer=$first['time_ms'];$samples=[];
    $observe=static function(array $current)use(&$states,$others,&$lastServer,&$samples):bool{
        m5pcp_expect(bccomp($current['time_ms'],$lastServer,0)>=0,'SEQUENCE_BARRIER_REAL_SERVER_TIME_NONDECREASING');
        $lastServer=$current['time_ms'];$currentOthers=$current['keys'];$allMissing=true;
        foreach($states as$keyHash=>&$state){$value=$current['keys'][$keyHash]??null;
            if($value!==null){
                m5pcp_expect(!$state['missing']&&$state['initial']!==null&&$value===$state['initial'],
                    'SEQUENCE_ORIGINAL_VALUE_EXPIRY_AND_NO_REAPPEARANCE');
                m5pcp_expect(bccomp($current['time_ms'],(string)$value['expires'],0)<=0,'SEQUENCE_PRESENT_BEFORE_ORIGINAL_EXPIRY');
                $allMissing=false;
            }elseif(!$state['missing']){
                m5pcp_expect($state['initial']!==null
                    &&bccomp($current['time_ms'],(string)$state['initial']['expires'],0)>=0,
                    'SEQUENCE_ABSENT_ONLY_AFTER_OBSERVED_ORIGINAL_EXPIRY');
                $state['missing']=true;$state['first_absence_ms']=$current['time_ms'];
            }
            unset($currentOthers[$keyHash]);
        }unset($state);
        m5pcp_expect($currentOthers===$others,'ALL_NON_TARGET_REDIS_VALUES_TYPES_AND_ABSOLUTE_EXPIRIES_UNCHANGED');
        $samples[]=['time'=>$current['time'],'time_ms'=>$current['time_ms'],'key_count'=>count($current['keys']),
            'complete_keys_sha256'=>hash('sha256',serialize($current['keys']))];return $allMissing;
    };
    $observe($first);$before=m5pcp_database_snapshot($db);$bounded();
    $current=m5pcp_atomic_redis($redis);$bounded();$complete=$observe($current);
    while(!$complete){$bounded();usleep(50000);$bounded();$current=m5pcp_atomic_redis($redis);$bounded();$complete=$observe($current);}
    $after=m5pcp_database_snapshot($db);$bounded();
    m5pcp_expect($after===$before,'SEQUENCE_BARRIER_COMPLETE_DATABASE_STRICTLY_UNCHANGED');
    $final=m5pcp_atomic_redis($redis);$finished=$bounded();
    m5pcp_expect($observe($final),'SEQUENCE_BARRIER_FINAL_ALL_EXACT_TARGET_KEYS_ABSENT');$targets=[];
    foreach($states as$keyHash=>$state)$targets[]=['key_sha256'=>$keyHash,'sources'=>$sequence['keys'][$keyHash]['sources'],
        'initial_observation'=>$state['initial']===null?'ALREADY_ABSENT':'PRESENT_WITH_OBSERVED_EXPIRY',
        'initial_expires_at_ms'=>$state['initial']['expires']??null,'initial_value_sha256'=>$state['initial']['sha256']??null,
        'first_absence_time_ms'=>$state['first_absence_ms'],'natural_expiry_observed'=>$state['initial']!==null,'final_absent'=>true];
    return ['scope'=>'READONLY_REAL_PRODUCER_SEQUENCE_EXPIRY_BARRIER_ONLY','status'=>'VERIFIED_SEQUENCE_KEYS_ABSENT_BEFORE_ACCOUNT',
        'epoch_ms'=>$sequence['epoch_ms'],'source_id_count'=>$sequence['source_id_count'],'key_count'=>count($states),
        'targets'=>$targets,'server_samples'=>$samples,'monotonic_elapsed_ns'=>$finished-$started,
        'complete_database_before_sha256'=>hash('sha256',serialize($before)),
        'complete_database_after_sha256'=>hash('sha256',serialize($after)),
        'non_target_redis_sha256'=>hash('sha256',serialize($others)),
        'all_non_target_redis_values_and_absolute_expiries_unchanged'=>true,
        'complete_database_strictly_unchanged'=>true,'original_account_payment_assertions_retained'=>true];
}
/** Failure metadata only; snapshot values and the strict equality assertion remain unchanged. */

function m5pcp_index_rows(array $rows,string $key): array
{
    $out=[];foreach($rows as$row){m5pcp_expect(array_key_exists($key,$row),'FULL_TARGET_PRIMARY_KEY');$id=m5pcp_u64($row[$key]);
        m5pcp_expect(!isset($out['id:'.$id]),'UNIQUE_NATIVE_TARGET_PK');$out['id:'.$id]=$row;}return $out;
}
/** The sole metadata exception is one actual INSERT into the target attempt table. */

function m5pcp_attempt_metadata_advance(array $before,array $after,string $inserted): void
{
    $old=m5pcp_u64($before['metadata']['table']['AUTO_INCREMENT']);$next=bcadd($old,'1',0);
    m5pcp_expect($inserted===$old&&m5pcp_u64($after['metadata']['table']['AUTO_INCREMENT'])===$next,'EXACT_NATIVE_ATTEMPT_AUTO_INCREMENT');
    $expected=$before['metadata'];$expected['table']['AUTO_INCREMENT']=$after['metadata']['table']['AUTO_INCREMENT'];
    m5pcp_expect($after['metadata']===$expected&&$after['projected_columns']===$before['projected_columns'],'ALL_OTHER_ATTEMPT_METADATA_IDENTICAL');
    $oldOption=' AUTO_INCREMENT='.$old;$newOption=' AUTO_INCREMENT='.$next;
    if(str_contains($before['ddl'],$oldOption)){
        m5pcp_expect(substr_count($before['ddl'],$oldOption)===1,'ONE_ORIGINAL_AUTO_INCREMENT_OPTION');
        $ddl=str_replace($oldOption,$newOption,$before['ddl']);
    }else{
        m5pcp_expect($old==='1'&&substr_count($before['ddl'],' DEFAULT CHARSET=')===1,'INITIAL_OMITTED_UNIT_AUTO_INCREMENT_ONLY');
        $ddl=str_replace(' DEFAULT CHARSET=',$newOption.' DEFAULT CHARSET=',$before['ddl']);
    }
    m5pcp_expect($after['ddl']===$ddl,'ONLY_EXACT_ATTEMPT_AUTO_INCREMENT_DDL_CHANGE');
}
/** Every target row has first been checked by the independent field/JSON oracle below. */

function m5pcp_delta(array $before,array $after,array $approvedRows,?string $newAttempt=null): void
{
    m5pcp_expect($after['names']===$before['names']&&$after['modes']===$before['modes']&&$after['increment']===$before['increment']
        &&$after['redis']===$before['redis'],'ALL_TABLE_NAMES_MODES_REDIS_ABSOLUTE_TTLS_PRESERVED');
    m5pcp_expect(m5pcp_keys($approvedRows,M5PCP_TARGETS),'EXACT_PAYMENT_TARGET_SET');
    foreach($before['tables']as$name=>$old){$current=$after['tables'][$name];
        if(!in_array($name,M5PCP_TARGETS,true)){m5pcp_expect($current===$old,'ALL_NON_TARGET_METADATA_AND_ROWS_UNCHANGED');continue;}
        if($name==='eb_marketplace_payment_attempt'&&$newAttempt!==null)m5pcp_attempt_metadata_advance($old,$current,$newAttempt);
        else m5pcp_expect($current['metadata']===$old['metadata']&&$current['ddl']===$old['ddl']
            &&$current['projected_columns']===$old['projected_columns'],'ALL_TARGET_METADATA_UNCHANGED');
        m5pcp_expect($after['target_rows'][$name]===$approvedRows[$name],'EXACT_APPROVED_TARGET_ROWS_ONLY');
        $hashes=[];foreach($approvedRows[$name]as$row)$hashes[]=hash('sha256',serialize($row));sort($hashes,SORT_STRING);
        m5pcp_expect($current['projection']===['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>count($hashes),
            'sha256'=>hash('sha256',implode("\n",$hashes))],'COMPLETE_TARGET_ROW_MULTISET');
    }
}
/** Check JSON object/list distinctions as well as every scalar's native JSON type. */

function m5pcp_json_expected(string $raw,array $expected): array
{
    $node=json_decode($raw,false,64,JSON_THROW_ON_ERROR);
    $check=static function(mixed $actual,mixed $wanted)use(&$check): void{
        if(!is_array($wanted)){m5pcp_expect($actual===$wanted,'JSON_EXACT_SCALAR_TYPE_AND_VALUE');return;}
        if(array_is_list($wanted)){
            m5pcp_expect(is_array($actual)&&array_is_list($actual)&&count($actual)===count($wanted),'JSON_EXACT_LIST');
            foreach($wanted as$i=>$value)$check($actual[$i],$value);
        }else{
            m5pcp_expect($actual instanceof stdClass,'JSON_EXACT_OBJECT');$fields=get_object_vars($actual);
            m5pcp_expect(m5pcp_keys($fields,array_keys($wanted)),'JSON_EXACT_OBJECT_FIELDS');
            foreach($wanted as$key=>$value)$check($fields[$key],$value);
        }
    };
    $check($node,$expected);$decoded=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    m5pcp_expect(is_array($decoded),'JSON_EXPECTED_CONTAINER');return $decoded;
}

function m5pcp_context(\think\App $app,\think\db\ConnectionInterface $connection,PDO $default,PDO $independent): void
{
    m5pcp_expect(app()===$app&&Db::connect()===$connection&&$connection->getPdo()===$default
        &&$default!==$independent&&!$default->inTransaction()&&!$independent->inTransaction()
        &&$default->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&$independent->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        &&$default->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
        &&in_array($default->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'ACTUAL_SAME_DEFAULT_AND_INDEPENDENT_PDO');
    $fixed=$GLOBALS['m5pc_factories']??null;
    m5pcp_expect(is_array($fixed)&&$app->make(PaymentAdapter::class)===$fixed['driver']
        &&$app->make(TestPaymentDriver::class)===$fixed['driver']
        &&$app->make(MarketplacePaymentServices::class)===$fixed['service']
        &&$app->make(PaymentAccountResolver::class)===$fixed['resolver'],'ACTUAL_SAME_FOUR_APP_FACTORY_INSTANCES');
}

function m5pcp_registry(array $row): array
{
    m5pcp_expect(array_keys($row)===M5PCP_REGISTRY,'COMPLETE_NATIVE_REGISTRY_COLUMN_ORDER');
    return ['order_id'=>m5pcp_int($row['order_id'],1),'uid'=>m5pcp_int($row['uid'],1),
        'mer_id'=>m5pcp_int($row['mer_id'],1,4294967295),'payment_contract_version'=>m5pcp_int($row['payment_contract_version'],1,1),
        'born_at'=>m5pcp_int($row['born_at'],1,4294967295),'attempt_generation'=>m5pcp_u64($row['attempt_generation'],true),
        'winner_attempt_id'=>$row['winner_attempt_id']===null?null:m5pcp_u64($row['winner_attempt_id']),
        'fulfillment_hold'=>m5pcp_int($row['fulfillment_hold'],0,1),'hold_reason'=>$row['hold_reason'],
        'hold_attempt_id'=>$row['hold_attempt_id']===null?null:m5pcp_u64($row['hold_attempt_id']),
        'hold_at'=>$row['hold_at']===null?null:m5pcp_int($row['hold_at'],1,4294967295)];
}
function m5pcp_registry_expected(array $root,string $generation,?string $winner): array
{
    return ['order_id'=>m5pcp_int($root['id'],1),'uid'=>m5pcp_int($root['uid'],1),'mer_id'=>m5pcp_int($root['mer_id'],1,4294967295),
        'payment_contract_version'=>1,'born_at'=>m5pcp_int($root['add_time'],1,4294967295),'attempt_generation'=>$generation,
        'winner_attempt_id'=>$winner,'fulfillment_hold'=>0,'hold_reason'=>null,'hold_attempt_id'=>null,'hold_at'=>null];
}
function m5pcp_registry_row(PDO $db,int $id): array
{
    $rows=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_payment_registry` WHERE `order_id` = ?',[$id]);
    m5pcp_expect(count($rows)===1,'ACTUAL_SINGLE_BORN_REGISTRY');return $rows[0];
}
function m5pcp_birth(PDO $db,array $saved): array
{
    $root=$saved['order'];$id=m5pcp_int($root['id'],1);$registry=m5pcp_registry_row($db,$id);
    m5pcp_same(m5pcp_registry($registry),m5pcp_registry_expected($root,'0',null));
    m5pcp_expect(m5pcp_rows($db,'SELECT * FROM `eb_marketplace_payment_attempt` WHERE `order_id` = ? ORDER BY `id`',[$id])===[]
        &&m5pcp_int($root['paid'],0,0)===0&&m5pcp_int($root['pay_time'],0,0)===0&&$root['pay_type']===''&&$root['trade_no']===''
        &&m5pcp_int($root['is_cancel'],0,0)===0&&m5pcp_int($root['status'],0,0)===0,'ACTUAL_G0_UNPAID_ROOT_AND_EMPTY_COLLECTION');
    return $registry;
}
function m5pcp_binding(array $root,array $account): array
{
    return ['order_pk'=>m5pcp_int($root['id'],1),'order_no'=>$root['order_id'],'uid'=>m5pcp_int($root['uid'],1),
        'mer_id'=>m5pcp_int($root['mer_id'],1,4294967295),'amount'=>$root['pay_price'],'currency'=>'CNY','channel'=>'test',
        'account_source'=>'test_config','account_id'=>null,'account_ref'=>$account['account_ref'],'account_version'=>1];
}
function m5pcp_result_expected(array $actual,array $binding,string $state,string $reference,?string $trade): array
{
    m5pcp_expect(preg_match('/\Atest-attempt:[a-f0-9]{32}\z/D',$reference)===1,'REAL_TEST_ATTEMPT_REFERENCE');
    if($trade!==null)m5pcp_expect(preg_match('/\Atest-payment:[a-f0-9]{32}\z/D',$trade)===1,'REAL_TEST_TRADE_REFERENCE');
    $expected=['state'=>$state,'scope'=>'testing_process_only','attempt_ref'=>$reference,
        'binding_sha256'=>hash('sha256',m5pcp_canonical($binding)),'binding'=>$binding,'provider_trade_no'=>$trade];
    m5pcp_same($actual,$expected);return $expected;
}
function m5pcp_dto(array $actual,array $root,array $account,string $attemptId,string $state,array $originalCreate): void
{
    m5pcp_same($actual,['schema'=>1,'scope'=>'testing_persisted_payment_v1','order_pk'=>m5pcp_int($root['id'],1),
        'order_no'=>$root['order_id'],'attempt_id'=>$attemptId,'admitted_generation'=>'1','account_snapshot'=>$account,
        'state'=>$state,'create_result'=>$originalCreate]);
}
function m5pcp_observation(string $operation,string $attemptId,array $account,array $result,int $received): array
{
    $fact=['op'=>$operation,'attempt_id'=>$attemptId,'generation'=>'1','account_sha256'=>hash('sha256',m5pcp_canonical($account)),
        'result'=>$result];
    return ['key'=>hash('sha256',"marketplace-payment-fact:v1\n".m5pcp_canonical($fact)),'received_at'=>$received,'fact'=>$fact];
}
function m5pcp_evidence(array $raw): array
{
    m5pcp_expect(is_string($raw['evidence']??null),'NATIVE_EVIDENCE_JSON');
    $evidence=json_decode($raw['evidence'],true,64,JSON_THROW_ON_ERROR);
    m5pcp_expect(is_array($evidence)&&m5pcp_keys($evidence,['schema','observations','first_closed','first_succeeded','application','exceptions'])
        &&$evidence['schema']===1&&is_array($evidence['observations'])&&array_is_list($evidence['observations'])
        &&$evidence['first_closed']===null&&$evidence['exceptions']===[],'NORMAL_EVIDENCE_EXACT_ENVELOPE');return $evidence;
}
/** All 18 native fields are checked independently; no production Evidence oracle is invoked. */
function m5pcp_attempt_expected(array $raw,array $root,array $account,string $idem,string $attemptId,string $reference,
    int $createdAt,array $evidence,?string $trade): void
{
    m5pcp_expect(array_keys($raw)===M5PCP_ATTEMPT,'COMPLETE_NATIVE_ATTEMPT_COLUMN_ORDER');$binding=m5pcp_binding($root,$account);
    $expected=['id'=>$attemptId,'order_id'=>m5pcp_int($root['id'],1),'uid'=>m5pcp_int($root['uid'],1),
        'mer_id'=>m5pcp_int($root['mer_id'],1,4294967295),'payment_contract_version'=>1,'admitted_generation'=>'1',
        'order_no'=>$root['order_id'],'idem_key'=>$idem,'amount'=>$root['pay_price'],'currency'=>'CNY','account_snapshot'=>$account,
        'request_digest'=>hash('sha256',m5pcp_canonical(['binding'=>$binding,'idempotency_key'=>$idem]),true),
        'binding_digest'=>hash('sha256',m5pcp_canonical($binding),true),
        'account_domain_digest'=>hash('sha256',m5pcp_canonical(['source'=>'test_config','channel'=>'test','account_ref'=>$account['account_ref']]),true),
        'created_at'=>$createdAt,'attempt_ref'=>$reference,'applied_trade_no'=>$trade,'evidence'=>$evidence];
    $actual=$raw;
    foreach(['order_id','uid','payment_contract_version','created_at']as$field)$actual[$field]=m5pcp_int($raw[$field],1,
        $field==='created_at'?4294967295:($field==='payment_contract_version'?1:2147483647));
    $actual['mer_id']=m5pcp_int($raw['mer_id'],1,4294967295);
    foreach(['id','admitted_generation']as$field)$actual[$field]=m5pcp_u64($raw[$field]);
    foreach(['request_digest','binding_digest','account_domain_digest']as$field)m5pcp_expect(is_string($raw[$field])&&strlen($raw[$field])===32,
        'THREE_NATIVE_BINARY32_DIGESTS');
    m5pcp_expect(is_string($raw['account_snapshot']),'NATIVE_ACCOUNT_JSON');
    $actual['account_snapshot']=m5pcp_json_expected($raw['account_snapshot'],$account);
    $actual['evidence']=m5pcp_json_expected($raw['evidence'],$evidence);m5pcp_same($actual,$expected);
}
function m5pcp_like_integer(mixed $original,int $value): int|string
{
    m5pcp_expect(is_int($original)||is_string($original),'NATIVE_INTEGER_REPRESENTATION');return is_int($original)?$value:(string)$value;
}
function m5pcp_replace(array $rows,string $key,string $id,array $replacement): array
{
    $found=0;foreach($rows as&$row)if(m5pcp_u64($row[$key])===$id){$row=$replacement;++$found;}unset($row);
    m5pcp_expect($found===1,'ONE_EXACT_TARGET_ROW_REPLACED');return $rows;
}
function m5pcp_single_attempt(PDO $db,int $id,string $attemptId): array
{
    $rows=m5pcp_rows($db,'SELECT * FROM `eb_marketplace_payment_attempt` WHERE `order_id` = ? ORDER BY `id`',[$id]);
    m5pcp_expect(count($rows)===1&&m5pcp_u64($rows[0]['id'])===$attemptId,'ONE_REAL_NATIVE_ATTEMPT_IN_COMPLETE_COLLECTION');return $rows[0];
}
function m5pcp_time(int $started,int $ended,mixed $value): int
{
    m5pcp_expect(is_int($value)&&$started>0&&$ended>=$started&&$value>=$started&&$value<=$ended&&$value<=4294967295,
        'REAL_LOCAL_TIME_WITHIN_ACTUAL_CALL');return $value;
}
/** A normal real clock tick makes timestamp-preserving replays observable. */
function m5pcp_after_second(int $applied): void
{
    $started=time();m5pcp_expect($started>=$applied,'MONOTONIC_NORMAL_WALL_CLOCK');
    while(time()<=$applied){m5pcp_expect(time()-$started<5,'BOUNDED_REAL_CLOCK_TICK');usleep(50000);}
    m5pcp_expect(time()>$applied,'ACTUAL_LATER_SECOND_BEFORE_REPLAY');
}
/** The two new roots stay separate from the original MS4 unpaid/planner/cancellation cases. */
function m5pcp_payment_case(\think\App $app,\think\db\ConnectionInterface $connection,PDO $default,PDO $db,
    MarketplacePaymentServices $service,TestPaymentDriver $driver,array $case,array &$snapshot): array
{
    $root=$case['saved']['order'];$id=m5pcp_int($root['id'],1);$uid=m5pcp_int($root['uid'],1);$account=$case['account'];
    $binding=m5pcp_binding($root,$account);$idem='payment-normal:'.$case['kind'].':'.bin2hex(random_bytes(16));
    m5pcp_context($app,$connection,$default,$db);$GLOBALS['m4c_phase']='payment_'.$case['kind'].'_actual_create';
    $started=time();$dto=$service->createForCustomer($uid,$root['order_id'],$idem);$ended=time();
    m5pcp_context($app,$connection,$default,$db);
    m5pcp_expect(is_string($dto['attempt_id']??null)&&is_array($dto['create_result']??null),'REAL_PERSISTED_CREATE_DTO');
    $attemptId=m5pcp_u64($dto['attempt_id']);$create=$dto['create_result'];
    m5pcp_expect(is_string($create['attempt_ref']??null),'ACTUAL_ORIGINAL_CREATE_REFERENCE');$reference=$create['attempt_ref'];
    $createExpected=m5pcp_result_expected($create,$binding,'pending',$reference,null);
    m5pcp_dto($dto,$root,$account,$attemptId,'pending',$createExpected);
    // The descriptor comes from the original service result; no private driver map is reconstructed.
    $actualPending=$driver->queryPayment($create);m5pcp_same($actualPending,$createExpected);
    $raw=m5pcp_single_attempt($db,$id,$attemptId);$evidence=m5pcp_evidence($raw);
    m5pcp_expect(count($evidence['observations'])===1&&$evidence['first_succeeded']===null&&$evidence['application']===null,
        'ONE_REAL_CREATE_OBSERVATION_WITHOUT_PAYMENT_APPLICATION');
    $createdAt=m5pcp_time($started,$ended,m5pcp_int($raw['created_at'],1,4294967295));
    $received=m5pcp_time($started,$ended,$evidence['observations'][0]['received_at']??null);
    $createObservation=m5pcp_observation('createPayment',$attemptId,$account,$createExpected,$received);
    $pendingEvidence=['schema'=>1,'observations'=>[$createObservation],'first_closed'=>null,'first_succeeded'=>null,
        'application'=>null,'exceptions'=>[]];
    m5pcp_attempt_expected($raw,$root,$account,$idem,$attemptId,$reference,$createdAt,$pendingEvidence,null);
    $registry=m5pcp_registry_row($db,$id);m5pcp_same(m5pcp_registry($registry),m5pcp_registry_expected($root,'1',null));
    $oldRegistry=m5pcp_index_rows($snapshot['target_rows']['eb_marketplace_payment_registry'],'order_id')['id:'.$id];
    $expectedRegistry=$oldRegistry;$expectedRegistry['attempt_generation']=m5pcp_like_integer($oldRegistry['attempt_generation'],1);
    m5pcp_expect($registry===$expectedRegistry,'ONLY_G0_TO_G1_REGISTRY_CHANGE');
    $approved=$snapshot['target_rows'];
    $approved['eb_marketplace_payment_registry']=m5pcp_replace($approved['eb_marketplace_payment_registry'],'order_id',(string)$id,$expectedRegistry);
    $approved['eb_marketplace_payment_attempt'][]=$raw;
    $afterCreate=m5pcp_snapshot($db);m5pcp_delta($snapshot,$afterCreate,$approved,$attemptId);
    m5pcp_expect(m5pcp_saved($db,$id)===$case['saved'],'CREATE_PRESERVES_FULL_ORIGINAL_SAVED_ORDER');
    $snapshot=$afterCreate;$pendingRaw=$raw;

    $GLOBALS['m4c_phase']='payment_'.$case['kind'].'_internal_complete';m5pcp_context($app,$connection,$default,$db);
    $completeStarted=time();$complete=$driver->completeTestPayment($create);$completeEnded=time();
    m5pcp_context($app,$connection,$default,$db);
    m5pcp_expect(is_string($complete['provider_trade_no']??null)
        &&$complete['provider_trade_no']==='test-payment:'.substr($reference,strlen('test-attempt:')),'REAL_INTERNAL_TEST_COMPLETION');
    m5pcp_result_expected($complete,$binding,'succeeded',$reference,$complete['provider_trade_no']);
    $afterComplete=m5pcp_snapshot($db);m5pcp_expect($afterComplete===$snapshot,'INTERNAL_COMPLETE_ALL_SQL_AND_REDIS_UNCHANGED');

    $GLOBALS['m4c_phase']='payment_'.$case['kind'].'_actual_query_apply';
    $queryStarted=time();$queried=$service->queryForCustomer($uid,$root['order_id'],$attemptId);$queryEnded=time();
    m5pcp_context($app,$connection,$default,$db);
    // Obtain the normal succeeded query result from the exact same App's original driver.
    $actualQuery=$driver->queryPayment($create);m5pcp_expect(is_string($actualQuery['provider_trade_no']??null),'ACTUAL_DRIVER_SUCCEEDED_QUERY');
    $queryResult=m5pcp_result_expected($actualQuery,$binding,'succeeded',$reference,$actualQuery['provider_trade_no']);
    m5pcp_same($complete,$actualQuery);m5pcp_dto($queried,$root,$account,$attemptId,'applied',$createExpected);
    $appliedRaw=m5pcp_single_attempt($db,$id,$attemptId);$applied=m5pcp_evidence($appliedRaw);
    m5pcp_expect(count($applied['observations'])===2&&is_array($applied['application']),'ONE_REAL_QUERY_AND_ONE_APPLICATION');
    $queryReceived=m5pcp_time($queryStarted,$queryEnded,$applied['observations'][1]['received_at']??null);
    $applicationAt=m5pcp_time($queryStarted,$queryEnded,$applied['application']['at']??null);
    $queryObservation=m5pcp_observation('queryPayment',$attemptId,$account,$queryResult,$queryReceived);
    $payment=['paid'=>1,'pay_time'=>$applicationAt,'pay_type'=>'marketplace_test','trade_no'=>$queryResult['provider_trade_no']];
    $appliedEvidence=['schema'=>1,'observations'=>[$createObservation,$queryObservation],'first_closed'=>null,
        'first_succeeded'=>$queryObservation['key'],'application'=>['fact'=>$queryObservation['key'],'at'=>$applicationAt,
            'order_pk'=>$id,'attempt_id'=>$attemptId,'root_payment'=>$payment],'exceptions'=>[]];
    m5pcp_attempt_expected($appliedRaw,$root,$account,$idem,$attemptId,$reference,$createdAt,$appliedEvidence,$payment['trade_no']);
    $expectedAttempt=$pendingRaw;$expectedAttempt['applied_trade_no']=$payment['trade_no'];$expectedAttempt['evidence']=$appliedRaw['evidence'];
    m5pcp_expect($appliedRaw===$expectedAttempt,'ALL_ORIGINAL_INTENT_AND_REFERENCE_BYTES_IMMUTABLE');
    $appliedRegistry=m5pcp_registry_row($db,$id);m5pcp_same(m5pcp_registry($appliedRegistry),m5pcp_registry_expected($root,'1',$attemptId));
    $expectedRegistry=$registry;$expectedRegistry['winner_attempt_id']=$appliedRegistry['winner_attempt_id'];
    m5pcp_expect($appliedRegistry===$expectedRegistry,'ONLY_REAL_WINNER_REGISTRY_FIELD_CHANGED');
    $expectedRoot=$root;$expectedRoot['paid']=m5pcp_like_integer($root['paid'],1);
    $expectedRoot['pay_time']=m5pcp_like_integer($root['pay_time'],$applicationAt);
    $expectedRoot['pay_type']='marketplace_test';$expectedRoot['trade_no']=$payment['trade_no'];
    $expectedSaved=$case['saved'];$expectedSaved['order']=$expectedRoot;
    m5pcp_expect(m5pcp_saved($db,$id)===$expectedSaved,'ROOT_FOUR_FIELDS_ONLY_ITEMS_RESERVES_LEDGER_INVOICE_UNCHANGED');
    $approved=$snapshot['target_rows'];
    $approved['eb_store_order']=m5pcp_replace($approved['eb_store_order'],'id',(string)$id,$expectedRoot);
    $approved['eb_marketplace_payment_registry']=m5pcp_replace($approved['eb_marketplace_payment_registry'],'order_id',(string)$id,$expectedRegistry);
    $approved['eb_marketplace_payment_attempt']=m5pcp_replace($approved['eb_marketplace_payment_attempt'],'id',$attemptId,$expectedAttempt);
    $afterQuery=m5pcp_snapshot($db);m5pcp_delta($snapshot,$afterQuery,$approved);$snapshot=$afterQuery;

    $GLOBALS['m4c_phase']='payment_'.$case['kind'].'_later_second_replays';m5pcp_after_second($applicationAt);
    m5pcp_context($app,$connection,$default,$db);$replayStarted=time();
    $createReplay=$service->createForCustomer($uid,$root['order_id'],$idem);$queryReplay=$service->queryForCustomer($uid,$root['order_id'],$attemptId);
    $replayEnded=time();m5pcp_context($app,$connection,$default,$db);
    m5pcp_expect($replayStarted>$applicationAt&&$replayEnded>=$replayStarted,'ACTUAL_LATER_REPLAY_SECOND');
    m5pcp_same($createReplay,$queried);m5pcp_same($queryReplay,$queried);m5pcp_same($driver->queryPayment($create),$actualQuery);
    $afterReplay=m5pcp_snapshot($db);m5pcp_expect($afterReplay===$snapshot,'FULL_DATABASE_REDIS_AND_APPLICATION_TIMES_UNCHANGED_ON_REPLAY');
    m5pcp_expect(m5pcp_saved($db,$id)===$expectedSaved,'LATER_REPLAY_ORIGINAL_FULL_SAVED_FACTS');
    $snapshot=$afterReplay;
    return ['verified'=>true,'source_kind'=>$case['kind'],'order_id'=>$id,'attempt_id'=>$attemptId,'generation'=>'1',
        'registry_complete'=>true,'attempt_complete'=>true,'independent_json_expected'=>true,'same_app_real_driver'=>true,
        'create_interval'=>[$started,$ended],'complete_interval'=>[$completeStarted,$completeEnded],
        'query_interval'=>[$queryStarted,$queryEnded],'replay_interval'=>[$replayStarted,$replayEnded],
        'internal_complete_sql_unchanged'=>true,'root_four_fields_only'=>true,'full_non_targets_preserved'=>true,
        'original_invoice_is_pay_zero'=>true,'later_second_replay_unchanged'=>true,
        'application_sha256'=>hash('sha256',m5pcp_canonical($appliedEvidence['application'])),
                'complete_saved_payment_sha256'=>hash('sha256',serialize([$appliedRegistry,$appliedRaw,$expectedRoot])),
        'original_create_result'=>$create,'account_snapshot'=>$account,'application'=>$appliedEvidence['application'],
        'order_no'=>$root['order_id'],'root_payment'=>$payment];
}


function m5pc_fixture_unchanged(array $loaded): void
{
    $stat=m4cb_private_meta();
    m4c_expect(hash_equals($loaded['sha256'],m4c_entry_hash(M4CB_FIXTURE))
        &&array_intersect_key($stat,array_fill_keys(['dev','ino','uid','mode','size'],true))===$loaded['stat'],
        'M4C_PAID_ORIGINAL_PRIVATE_FIXTURE_COMPLETE_BYTES_AND_IDENTITY');
}
function m5pc_native_auth_delta(array $before,array $after,int $uid,bool $login=false): void
{
    m4cb_preserved($before['facts'],$after['facts'],$uid,[],$login);
    m4c_expect($before['auxiliary_rows']===$after['auxiliary_rows'],'M4C_PAID_AUTH_ALL_SEVEN_AUXILIARY_TABLES_UNCHANGED');
}
/** Broader SQL observer keeps every table outside the explicitly verified business delta. */
function m5pc_non_target_database(array $before,array $after,array $changed): void
{
    m4c_expect($before['names']===$after['names']&&$before['modes']===$after['modes']&&$before['increment']===$after['increment'],
        'M4C_PAID_FULL_DATABASE_NAMES_MODES_AND_INCREMENT_RETAINED');
    foreach($before['tables']as$name=>$old)if(!in_array($name,$changed,true))
        m4c_expect($after['tables'][$name]===$old,'M4C_PAID_ALL_OTHER_NATIVE_DATABASE_TABLES_UNCHANGED');
}
function m5pc_projection_rows(array $rows): array
{
    $hashes=[];foreach($rows as$row)$hashes[]=hash('sha256',serialize($row));sort($hashes,SORT_STRING);
    return ['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>count($hashes),
        'sha256'=>hash('sha256',implode("\n",$hashes))];
}
/** Real producer inserts may advance only these observed AUTO_INCREMENT values by their exact counts. */
function m5pc_created_database(array $before,array $after,array $facts): void
{
    $inserts=['eb_store_order'=>1,'eb_store_order_invoice'=>1,'eb_store_order_cart_info'=>2,'eb_marketplace_order_idempotency'=>1,
        'eb_marketplace_stock_movement'=>2,'eb_store_cart'=>3,'eb_store_product_log'=>5,'eb_store_order_status'=>1,
        'eb_marketplace_payment_registry'=>1,'eb_store_product'=>0,'eb_store_product_attr_value'=>0];
    m5pc_non_target_database($before,$after,array_keys($inserts));
    foreach($inserts as$name=>$count){$old=$before['tables'][$name];$current=$after['tables'][$name];
        m4c_expect($current['projection']===m5pc_projection_rows($facts[$name])
            &&$current['projected_columns']===$old['projected_columns'],'M4C_PAID_VERIFIED_CREATED_NATIVE_ROW_MULTISET');
        if($old['metadata']['table']['AUTO_INCREMENT']===null||$count===0){
            m4c_expect($current['metadata']===$old['metadata']&&$current['ddl']===$old['ddl'],
                'M4C_PAID_CREATED_TABLE_ALL_NON_INCREMENT_METADATA_UNCHANGED');continue;}
        $value=m5pcp_u64($old['metadata']['table']['AUTO_INCREMENT']);$next=bcadd($value,(string)$count,0);
        m4c_expect(m5pcp_u64($current['metadata']['table']['AUTO_INCREMENT'])===$next,'M4C_PAID_EXACT_REAL_PRODUCER_INSERT_INCREMENT');
        $expected=$old['metadata'];$expected['table']['AUTO_INCREMENT']=$current['metadata']['table']['AUTO_INCREMENT'];
        m4c_expect($current['metadata']===$expected,'M4C_PAID_CREATED_TABLE_OTHER_NATIVE_METADATA_IDENTICAL');
        $option=' AUTO_INCREMENT='.$value;$replacement=' AUTO_INCREMENT='.$next;
        if(str_contains($old['ddl'],$option)){m4c_expect(substr_count($old['ddl'],$option)===1,'M4C_PAID_ONE_ORIGINAL_INCREMENT_OPTION');
            $ddl=str_replace($option,$replacement,$old['ddl']);}
        else{m4c_expect($value==='1'&&substr_count($old['ddl'],' DEFAULT CHARSET=')===1,'M4C_PAID_ONLY_INITIAL_OMITTED_INCREMENT_OPTION');
            $ddl=str_replace(' DEFAULT CHARSET=',$replacement.' DEFAULT CHARSET=',$old['ddl']);}
        m4c_expect($current['ddl']===$ddl,'M4C_PAID_ONLY_EXACT_CREATED_INCREMENT_DDL_CHANGE');
    }
}
/** The only HTTP use is one genuine credential login and one genuine /api/user. */
function m5pc_authenticated_owner(array $source,array $loaded,PDO $db): array
{
    $app=$GLOBALS['m4c_application']??null;m4c_expect($app instanceof \think\App&&app()===$app,'M4C_PAID_ORIGINAL_PARENT_APP_BEFORE_AUTH');
    $private=$loaded['private'];$fixture=$private['fixture'];$expectedUid=m5pcp_int($fixture['user']['uid'],1);
    m4cb_guard_readonly();m4cb_events($db);$before=m5pc_native_snapshot($db);$beforeDatabase=m5pcp_database_snapshot($db);
    $actual=m4cb_rows($db,'SELECT * FROM `eb_user` WHERE `uid` = ?',[$expectedUid]);
    m4c_expect(count($actual)===1,'M4C_PAID_REAL_PREPARED_AUTH_OWNER');m4cb_equal_row($actual[0],$fixture['user']);
    $process=null;$group=null;$sent=false;$attempted=false;$child=null;$failure=null;$failureState=null;$owner=null;
    $supervisor=audit_app_proc_stat(getmypid());m4c_expect($supervisor!==null,'M4C_PAID_REAL_AUTH_SUPERVISOR');
    try{
        m4cb_phase('paid_identity_http_child');
        $process=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],
            [['file','/dev/null','r'],['file','/workspace/crmeb/runtime/ms5-paid-auth-native.log','a'],
             ['file','/workspace/crmeb/runtime/ms5-paid-auth-native.log','a']],$pipes,'/workspace/crmeb',m4c_entry_child_environment($source));
        m4c_expect(is_resource($process),'M4C_PAID_ACTUAL_AUTH_HTTP_CHILD');$group=audit_app_register_group($process,$supervisor);
        $until=microtime(true)+5.0;
        do{$observation=audit_app_process_observation($group,$supervisor);
            if($observation['members']!==[]&&$observation['listener_open'])break;usleep(10000);
        }while(microtime(true)<$until);
        m4c_expect($observation['members']!==[]&&$observation['listener_open'],'M4C_PAID_REAL_AUTH_HTTP_LISTENER');
        $GLOBALS['m4c_http_call_count']=0;m4cb_phase('paid_identity_real_login');
        $login=m4cb_http('POST','/api/login',[],['account'=>$private['account'],'password'=>$private['password'],'spread'=>0,'agent_id'=>0]);
        m4c_expect(is_string($login['token']??null)&&$login['token']!==''&&is_int($login['expires_time']??null)
            &&$login['expires_time']>time(),'M4C_PAID_REAL_LOGIN_TOKEN_AND_EXPIRY');
        $headers=['Authori-zation'=>'Bearer '.$login['token']];unset($login,$private['password']);
        $afterLogin=m5pc_native_snapshot($db);m5pc_native_auth_delta($before,$afterLogin,$expectedUid,true);
        m4cb_phase('paid_identity_real_user_get');$started=time();
        $reply=audit_http('GET','http://audit-app:8000/api/user',$headers);$ended=time();
        ++$GLOBALS['m4c_http_call_count'];
        m4c_expect($reply['http']===200&&is_array($reply['json'])&&($reply['json']['status']??null)===200
            &&is_array($reply['json']['data']??null)&&is_string($reply['raw'])&&strlen($reply['raw'])>0
            &&strlen($reply['raw'])<=1048576,'M4C_PAID_REAL_FULL_USER_HTTP_RESPONSE');
        $data=$reply['json']['data'];$uid=m5pcp_int($data['uid']??null,1);
        m4c_expect($uid===$expectedUid,'M4C_PAID_REAL_USER_UID_EQUALS_PREPARED_OWNER');
        m4c_expect($GLOBALS['m4c_http_call_count']===2,'M4C_PAID_EXACT_REAL_LOGIN_AND_USER_CALLS');
        m4c_expect(!array_key_exists('account',$data)&&!array_key_exists('pwd',$data),
            'M4C_PAID_PUBLIC_USER_CREDENTIAL_FIELDS_HIDDEN');
        $afterUser=m5pc_native_snapshot($db);m5pc_native_auth_delta($afterLogin,$afterUser,$uid);
        m5pc_native_auth_delta($before,$afterUser,$uid,true);
        $afterDatabase=m5pcp_database_snapshot($db);m5pc_non_target_database($beforeDatabase,$afterDatabase,['eb_user']);
        m4c_expect($afterDatabase['tables']['eb_user']['metadata']===$beforeDatabase['tables']['eb_user']['metadata']
            &&$afterDatabase['tables']['eb_user']['ddl']===$beforeDatabase['tables']['eb_user']['ddl']
            &&$afterDatabase['tables']['eb_user']['projected_columns']===$beforeDatabase['tables']['eb_user']['projected_columns']
            &&$afterDatabase['tables']['eb_user']['projection']===m5pc_projection_rows($afterUser['facts']['eb_user']),
            'M4C_PAID_AUTH_USER_METADATA_AND_SCHEMA_UNCHANGED');
        $row=m4cb_rows($db,'SELECT * FROM `eb_user` WHERE `uid` = ?',[$uid]);
        m4c_expect(count($row)===1&&$row[0]['account']===$private['account']
            &&$row[0]['account']===$fixture['user']['account'],'M4C_PAID_GET_UID_SAME_REAL_SAVED_USER');
        $owner=['schema'=>1,'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
            'freeze_sha256'=>$source['freeze_sha256'],'uid'=>$uid,'account'=>$row[0]['account'],'actual_user'=>$row[0],
            'fixture_sha256'=>$loaded['sha256'],'prepare_receipt_sha256'=>$loaded['prepare_receipt_sha256'],
            'http_user_response'=>['method'=>'GET','path'=>'/api/user','http'=>200,'bytes'=>strlen($reply['raw']),
                'sha256'=>hash('sha256',$reply['raw']),'raw'=>$reply['raw'],'actual_interval'=>[$started,$ended]],
            'http_call_count'=>2,'only_original_owner_auth_delta'=>true];
        m5pc_fixture_unchanged($loaded);unset($headers,$reply,$data);
        m4c_expect(empty($GLOBALS['m4c_terminate_requested']),'M4C_PAID_IDENTITY_NOT_INTERRUPTED');
    }catch(Throwable $error){$failure=$error;$failureState=[$GLOBALS['m4c_phase']??null,$GLOBALS['m4c_failed_assertion']??null];}
    finally{
        if(is_resource($process)||$group!==null){do{try{$child=m4c_entry_stop($process,$group,$supervisor,$sent,$attempted);}
            catch(Throwable $stopError){if($failure===null){$failure=$stopError;$failureState=[$GLOBALS['m4c_phase']??null,$GLOBALS['m4c_failed_assertion']??null];}usleep(20000);}
        }while($child===null||!$child['stopped']);}
        $GLOBALS['m5pc_child_proof']=$child;unset($headers,$reply,$login,$private);
    }
    if($failure!==null){[$GLOBALS['m4c_phase'],$GLOBALS['m4c_failed_assertion']]=$failureState;throw $failure;}
    m4c_expect(is_array($child)&&$child['stopped']===true&&$child['remaining_members']===[]&&$child['listener_open']===false
        &&$child['stop_signal']===SIGTERM&&$child['term_attempted']===true&&$child['normal_only']===true
        &&is_array($child['native_child'])&&$child['native_child']['running']===false&&$child['native_child']['termsig']!==9
        &&is_int($child['native_proc_close']),'M4C_PAID_AUTH_SAME_CHILD_NORMALLY_CLOSED');
    $final=m5pc_native_snapshot($db);m4c_expect($final===$afterUser,'M4C_PAID_AUTH_STOP_COMPLETE_NATIVE_FACTS_UNCHANGED');
    m5pc_fixture_unchanged($loaded);$owner['child']=$child;return $owner;
}
/** Genuine persistent setCart/confirmation/create; the original saved-row oracle admits its G0. */
function m5pc_produce(\think\App $app,PDO $db,array $source,array $owner,array $fixture): array
{
    m4cb_phase('paid_real_parent_factory');m4c_expect(app()===$app&&($GLOBALS['m4c_application']??null)===$app
        &&$owner['uid']===m5pcp_int($fixture['user']['uid'],1),'M4C_PAID_REAL_PARENT_APP_AND_AUTHENTICATED_UID');
    $uid=$owner['uid'];$fixture['user']=$owner['actual_user'];$mer=m5pcp_int($fixture['merchant']['mer_id'],1,4294967295);
    m4c_expect(m5pcp_int($fixture['merchant']['fulfillment_enabled'],1,1)===1,'M4C_PAID_REAL_MERCHANT_FULFILLMENT_ENABLED');
    $connection=Db::connect();$default=$connection->getPdo();
    if(!$default instanceof PDO){$connection->query('SELECT 1',[],true);$default=$connection->getPdo();}
    m4c_expect($default instanceof PDO&&$default!==$db,'M4C_PAID_REAL_DIFFERENT_COMMITTED_READER');
    $driver=$app->make(TestPaymentDriver::class);$service=$app->make(MarketplacePaymentServices::class);$resolver=$app->make(PaymentAccountResolver::class);
    m4c_expect($driver instanceof TestPaymentDriver&&$service instanceof MarketplacePaymentServices&&$resolver instanceof PaymentAccountResolver,
        'M4C_PAID_REAL_PAYMENT_FACTORY_TYPES');
    $GLOBALS['m5pc_factories']=['driver'=>$driver,'service'=>$service,'resolver'=>$resolver];
    m5pcp_context($app,$connection,$default,$db);m4cb_events($db);
    $before=m5pc_native_snapshot($db);$broadBefore=m5pcp_database_snapshot($db);
    foreach(['eb_store_cart','eb_store_order','eb_store_order_invoice','eb_store_order_cart_info','eb_marketplace_order_idempotency',
        'eb_marketplace_stock_movement','eb_store_product_log']as$table)m4c_expect($before['facts'][$table]===[],'M4C_PAID_FRESH_UNCONSUMED_CUSTOMER_FIXTURE');
    m4cb_phase('paid_real_persistent_carts');$refs=[];$started=time();
    foreach([...$fixture['selected'],$fixture['unselected']]as$line){$sku=$fixture['skus'][$line['sku_id']];
        $ref=MarketplaceOrderInput::reference($app->make(StoreCartServices::class)->setCart($uid,$line['product_id'],$line['qty'],$sku['unique'],0,false),'persistent');
        m4c_expect(!in_array($ref,$refs,true),'M4C_PAID_THREE_REAL_DISTINCT_PERSISTENT_CART_REFS');$refs[]=$ref;
    }
    $cart=m5pc_native_snapshot($db);m4cb_preserved($before['facts'],$cart['facts'],$uid,['eb_store_cart','eb_store_product_log']);
    m4c_expect($cart['auxiliary_rows']===$before['auxiliary_rows']&&count($cart['facts']['eb_store_cart'])===3,
        'M4C_PAID_REAL_THREE_CARTS_NO_AUXILIARY_CHANGE');
    m4c_expect($cart['facts']['eb_user']===$before['facts']['eb_user'],'M4C_PAID_INTERNAL_CART_HAS_NO_FURTHER_AUTH_WRITE');
    foreach([...$fixture['selected'],$fixture['unselected']]as$i=>$line){$rows=m4cb_rows($db,'SELECT * FROM `eb_store_cart` WHERE `id` = ?',[$refs[$i]]);
        m4c_expect(count($rows)===1&&(int)$rows[0]['uid']===$uid&&(int)$rows[0]['product_id']===$line['product_id']
            &&$rows[0]['product_attr_unique']===$fixture['skus'][$line['sku_id']]['unique']&&(int)$rows[0]['cart_num']===$line['qty']
            &&(int)$rows[0]['is_pay']===0&&(int)$rows[0]['is_del']===0&&(int)$rows[0]['is_new']===0&&(int)$rows[0]['status']===1,
            'M4C_PAID_ACTUAL_PERSISTENT_CART_SELECTION');}
    m4cb_cart_stats($cart['facts']['eb_store_product_log'],$fixture,$started,time());
    $selectedRefs=MarketplaceOrderInput::references(implode(',',array_slice($refs,0,2)),'persistent');$csv=implode(',',$selectedRefs);
    $address=m5pcp_int($fixture['address']['id'],1,16777215);m4cb_phase('paid_real_confirmation');
    $choice=MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>$csv,'addressId'=>$address,'new'=>false]);
    $user=$app->make(UserServices::class)->getUserInfo($uid);m4c_expect($user!==null&&m5pcp_int($user->getData('uid'),1)===$uid,'M4C_PAID_ACTUAL_CONFIRMATION_USER_MODEL');
    $confirmation=$app->make(StoreOrderServices::class)->getOrderConfirmData($user->toArray(),$csv,false,$address,1,0,$choice);
    $key=MarketplaceOrderInput::confirmationKey($confirmation['orderKey']);$cached=CacheService::get('user_order_'.$uid.$key);
    m4c_expect(is_array($cached),'M4C_PAID_REAL_SERVICE_CONFIRMATION_FRAME');$frame=MarketplaceCartSelection::frame($cached,$uid);
    m4c_expect($frame['mer_id']===$mer&&$frame['source_kind']==='persistent'&&$frame['cart_refs']===$selectedRefs
        &&count($frame['selection'])===2&&count($frame['custom_form'])===2,'M4C_PAID_REAL_TWO_SELECTED_ITEMS_AND_FORMS');
    $answers=[];foreach($frame['custom_form']as$definition){$product=$fixture['products'][$definition['product_id']]??null;
        m4c_expect(is_array($product)&&$definition['product_name']===$product['store_name'],'M4C_PAID_FORM_REAL_SAVED_PRODUCT');
        $rawFields=json_decode($product['custom_form'],true,32,JSON_THROW_ON_ERROR);$fields=$values=[];
        foreach($rawFields as$i=>$field){$fields[]=['field_index'=>$i]+$field;$values[]=['field_index'=>$i,'value'=>$field['label']==='number'?'0':'正常HTTP填写'.$definition['product_name']];}
        m4c_expect($definition['fields']===$fields&&$definition['definition_sha256']===hash('sha256',MarketplaceOrderInput::canonical([
            'product_id'=>$definition['product_id'],'fields'=>$fields])),'M4C_PAID_INDEPENDENT_PRODUCT_FORM_DEFINITION');
        $answers[]=['product_id'=>$definition['product_id'],'definition_sha256'=>$definition['definition_sha256'],'values'=>$values];
    }
    $beforeCreate=m4cb_snapshot($db);m4cb_preserved($cart['facts'],$beforeCreate,$uid);
    m4c_expect($beforeCreate['eb_user']===$before['facts']['eb_user'],'M4C_PAID_INTERNAL_CONFIRMATION_HAS_NO_FURTHER_AUTH_WRITE');
    $raw=['cartId'=>$csv,'mer_id'=>$mer,'new'=>0,'idem_key'=>'confirm:'.$key,'addressId'=>$address,'invoice_id'=>m5pcp_int($fixture['invoice']['id'],1),
        'payType'=>'','mark'=>'普通HTTP下单','gift_mark'=>'普通HTTP文本','real_name'=>'','phone'=>'','shipping_type'=>1,'is_gift'=>0,'store_id'=>0,
        'couponId'=>0,'useIntegral'=>false,'combinationId'=>0,'pinkId'=>0,'seckill_id'=>0,'bargainId'=>0,'advanceId'=>0,'custom_form'=>$answers];
    $input=MarketplaceOrderInput::create($uid,$key,$raw);m4cb_phase('paid_real_create_service');m5pcp_context($app,$connection,$default,$db);
    $created=$app->make(MarketplaceOrderCreateServices::class)->create($uid,$key,$raw);
    m4c_expect(is_string($created['order_id']??null)&&($created['key']??null)===$key,'M4C_PAID_REAL_CREATED_ORDER_NUMBER');
    $createdFacts=m4cb_saved($db,$fixture,$input['request'],$input['idem_key'],$frame,$input['request']['options']['custom_form'],$created['order_id'],$beforeCreate);
    m4c_expect(m5pc_native_snapshot($db)['auxiliary_rows']===$before['auxiliary_rows'],'M4C_PAID_CREATE_ALL_SEVEN_AUXILIARY_TABLES_UNCHANGED');
    m4c_expect($createdFacts['eb_user']===$before['facts']['eb_user'],'M4C_PAID_INTERNAL_CREATE_HAS_NO_FURTHER_AUTH_WRITE');
    $broadCreated=m5pcp_database_snapshot($db);m5pc_created_database($broadBefore,$broadCreated,$createdFacts);
    $roots=m4cb_rows($db,'SELECT * FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$uid,$created['order_id']]);
    m4c_expect(count($roots)===1,'M4C_PAID_ACTUAL_ROOT_PK_FROM_COMMITTED_ORDER');$saved=m5pcp_saved($db,m5pcp_int($roots[0]['id'],1));
    m5pcp_birth($db,$saved);m5pcp_context($app,$connection,$default,$db);
    m4cb_phase('paid_producer_sequence_natural_expiry');$sequence=m5pcp_sequence_keys($db,$app,$saved);
    $barrier=m5pcp_sequence_expiry_barrier($db,$sequence);$snapshot=m5pcp_snapshot($db);
    m4cb_phase('paid_real_test_account');$reference='test:paid-consumer:'.$source['run_id'].':'.$mer;
    $account=$resolver->registerTestAccount($mer,$reference,1,'CNY');$accountSnapshot=$account->snapshot();
    m5pcp_same($accountSnapshot,['source'=>'test_config','account_id'=>null,'account_ref'=>$reference,'version'=>1,
        'mer_id'=>$mer,'channel'=>'test','currency'=>'CNY','verification_scope'=>'test_driver_only']);
    m4c_expect($resolver->forOrder($saved['order'])===$account&&m5pcp_snapshot($db)===$snapshot,
        'M4C_PAID_ACCOUNT_REGISTRATION_COMPLETE_DATABASE_REDIS_UNCHANGED');
    $case=['kind'=>'persistent','saved'=>$saved,'account'=>$accountSnapshot];
    m4cb_phase('paid_same_factory_payment');$proof=m5pcp_payment_case($app,$connection,$default,$db,$service,$driver,$case,$snapshot);
    m5pcp_context($app,$connection,$default,$db);m4cb_guard_readonly();
    $native=m5pc_native_snapshot($db);
    m4c_expect($native['auxiliary_rows']===$before['auxiliary_rows'],'M4C_PAID_PAYMENT_ALL_SEVEN_AUXILIARY_TABLES_UNCHANGED');
    return ['proof'=>$proof,'snapshot'=>$native,'sequence_barrier'=>$barrier,
        'full_database_table_count'=>count($snapshot['names']),'full_database_redis_sha256'=>hash('sha256',serialize($snapshot)),
        'fixture_runtime_uid'=>$uid,'actual_form_and_invoice_verified'=>true,'all_non_targets_preserved'=>true];
}

function m5pc_seal(array $source,array $loaded,array $owner,array $produced,PDO $db): array
{
    m4cb_phase('paid_native_private_seal');$snapshot=$produced['snapshot'];
    m4c_expect(m5pc_native_snapshot($db)===$snapshot,'M4C_PAID_COMPLETE_NATIVE_INDEPENDENT_PRESEAL_READBACK');
    m5pc_fixture_unchanged($loaded);$archive=m5pc_native_snapshot_archive($snapshot);
    $prepare=$loaded['private'];$proof=$produced['proof'];
    m4c_entry_keys($proof['original_create_result'],['state','scope','attempt_ref','binding_sha256','binding','provider_trade_no']);
    m4c_expect($proof['original_create_result']['state']==='pending'&&$proof['original_create_result']['scope']==='testing_process_only'
        &&$proof['original_create_result']['provider_trade_no']===null,'M4C_PAID_ORIGINAL_PENDING_DESCRIPTOR_NOT_REWRITTEN');
    $value=['schema'=>1,'scope'=>'MS5_PAID_CONSUMER_NATIVE_BASELINE',
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
        'container_id'=>$source['container_id'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'environment_sha256'=>$source['environment_sha256'],
        'environment_native_sha256'=>$source['environment_native_sha256'],'environment'=>$source['environment'],
        'self_operated_mer_id'=>$source['self_operated_mer_id'],
        'prepare_identity'=>['container_id'=>$prepare['prepare_container_id'],'native_created_sha256'=>$prepare['prepare_native_created_sha256'],
            'source_binding_sha256'=>$prepare['prepare_source_binding_sha256'],'prepare_receipt_sha256'=>$loaded['prepare_receipt_sha256']],
        'fixture_descriptor'=>['path'=>M4CB_FIXTURE,'bytes'=>$loaded['stat']['size'],'sha256'=>$loaded['sha256'],'stat'=>$loaded['stat']],
        'authenticated_owner'=>$owner,'order_id'=>$proof['order_id'],'order_no'=>$proof['order_no'],'attempt_id'=>$proof['attempt_id'],
        'original_create_result'=>$proof['original_create_result'],'account_snapshot'=>$proof['account_snapshot'],
        'payment_application'=>$proof['application'],'root_payment'=>$proof['root_payment'],
        'full_database_table_count'=>$produced['full_database_table_count'],
        'full_database_redis_sha256'=>$produced['full_database_redis_sha256'],'native_snapshot'=>$archive];
    $raw=m4cb_json($value);m4c_expect(strlen($raw)>0&&strlen($raw)<=2097152,'M4C_PAID_ENTIRE_PRIVATE_BASELINE_JSON_BOUND');
    $parent=dirname(M5PC_BASELINE);$parentStat=lstat($parent);
    m4c_expect(is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent&&is_array($parentStat)
        &&($parentStat['mode']&0170000)===0040000&&($parentStat['mode']&0777)===0700&&$parentStat['uid']===posix_geteuid()
        &&!file_exists(M5PC_BASELINE)&&!is_link(M5PC_BASELINE),'M4C_PAID_PRIVATE_BASELINE_CREATE_ONLY_PARENT');
    $mask=umask(0077);$stream=fopen(M5PC_BASELINE,'xb');
    try{m4c_expect(is_resource($stream)&&chmod(M5PC_BASELINE,0600),'M4C_PAID_PRIVATE_BASELINE_NATIVE_OPEN_MODE');
        $offset=0;while($offset<strlen($raw)){$count=fwrite($stream,substr($raw,$offset));
            m4c_expect(is_int($count)&&$count>0,'M4C_PAID_PRIVATE_BASELINE_COMPLETE_NATIVE_WRITE');$offset+=$count;}
        m4c_expect(fflush($stream),'M4C_PAID_PRIVATE_BASELINE_FLUSH');
    }finally{if(is_resource($stream))fclose($stream);umask($mask);}
    clearstatcache(true,M5PC_BASELINE);$before=lstat(M5PC_BASELINE);
    m4c_expect(is_array($before)&&is_file(M5PC_BASELINE)&&!is_link(M5PC_BASELINE)&&realpath(M5PC_BASELINE)===M5PC_BASELINE
        &&($before['mode']&0170000)===0100000&&($before['mode']&0777)===0600&&$before['uid']===posix_geteuid()
        &&$before['nlink']===1&&$before['size']===strlen($raw),'M4C_PAID_PRIVATE_BASELINE_ACTUAL_STAT_OWNER_MODE');
    $read=file_get_contents(M5PC_BASELINE);clearstatcache(true,M5PC_BASELINE);$after=lstat(M5PC_BASELINE);
    m4c_expect(is_string($read)&&$read===$raw&&is_array($after),'M4C_PAID_PRIVATE_BASELINE_FULL_BYTE_READBACK');
    foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key)
        m4c_expect($before[$key]===$after[$key],'M4C_PAID_PRIVATE_BASELINE_UNCHANGED_STAT');
    $restored=json_decode($read,true,128,JSON_THROW_ON_ERROR);m4c_entry_keys($restored,array_keys($value));
    m4c_expect($restored===$value&&m5pc_native_snapshot_restore($restored['native_snapshot'])===$snapshot
        &&m5pc_native_snapshot($db)===$snapshot,'M4C_PAID_SEALED_FULL_NATIVE_RESTORE_EQUALS_INDEPENDENT_PDO');
    m5pc_fixture_unchanged($loaded);m4cb_guard_readonly();m5pc_full_source($source);m5pc_prerequisites($source);
    m4c_expect(m5pc_source()===$source,'M4C_PAID_ENTIRE_SOURCE_CURRENT_ROLE_ENVIRONMENT_UNCHANGED');
    return ['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'fact_table_count'=>27,'auxiliary_table_count'=>7,
        'native_archive_bytes'=>$archive['bytes'],'native_archive_sha256'=>$archive['sha256'],
        'full_database_table_count'=>$produced['full_database_table_count'],
        'full_database_redis_sha256'=>$produced['full_database_redis_sha256'],
        'original_fixture_unchanged'=>true,'private_native_readback_verified'=>true];
}
function m5pc_run(): int
{
    m4cb_phase('paid_source');$source=m5pc_source();$GLOBALS['m5pc_source']=$source;
    m4c_entry_public_materials($source);m5pc_full_source($source);$app=m5pc_app($source);
    $db=audit_db();m4c_expect(!$db->inTransaction(),'M4C_PAID_REAL_INDEPENDENT_OBSERVER_OUTSIDE_TRANSACTION');
    $observer=m5pc_observer_configure($db);$loaded=m4cb_fixture_load($source);m5pc_fixture_unchanged($loaded);
    $owner=m5pc_authenticated_owner($source,$loaded,$db);
    m4c_expect($GLOBALS['m5pc_child_proof']['stopped']===true&&!audit_app_listener_open(),'M4C_PAID_AUTH_CHILD_STOPPED_BEFORE_PRODUCTION');
    $produced=m5pc_produce($app,$db,$source,$owner,$loaded['private']['fixture']);
    $sealed=m5pc_seal($source,$loaded,$owner,$produced,$db);
    m4c_expect(empty($GLOBALS['m4c_terminate_requested']),'M4C_PAID_PRODUCER_NOT_INTERRUPTED');
    m4cb_phase('final_source');m5pc_full_source($source);m5pc_prerequisites($source);
    m4c_expect(m5pc_source()===$source,'M4C_PAID_ALL_ACTUAL_SOURCE_VALUES_UNCHANGED');
    $result=['schema'=>1,'scope'=>M5PC_RULE['scope'],'status'=>M5PC_RULE['status'],
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
        'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'environment_sha256'=>$source['environment_sha256'],
        'environment_native_sha256'=>$source['environment_native_sha256'],'phase'=>'final_source',
        'entry_return_code'=>0,'termination'=>'NORMAL_RETURN','native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
        'full_source_unchanged'=>true,'child'=>$owner['child'],'metadata_observer'=>$observer,
        'business'=>['orders'=>1,'items'=>2,'reserves'=>2,'invoices'=>1,'payment_attempts'=>1,'generation'=>'1',
            'real_credential_login_and_user_get'=>true,'http_identity_call_count'=>2,'real_persistent_cart_confirm_create'=>true,
            'actual_saved_g0_before_payment'=>true,'same_app_factory_driver_connection'=>true,
            'original_create_descriptor_retained'=>true,'winner_application_root_four_hold_zero_verified'=>true,
            'later_second_replay_unchanged'=>true,'full_database_non_targets_and_redis_preserved'=>true,
            'baseline'=>$sealed,'sequence_expiry'=>$produced['sequence_barrier']],
        'customer_paid_API_DOM'=>'NOT RUN','merchant_paid_API_DOM'=>'NOT RUN','platform_paid_API_DOM'=>'NOT RUN',
        'full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN',
        'assertions'=>($GLOBALS['m4c_assertions']??0)+($GLOBALS['m5pcp_assertions']??0)];
    m4c_entry_publish('/run/audit/evidence/'.M5PC_RULE['result'],$result);
    $GLOBALS['m5pc_completed']=true;unset($owner,$loaded,$produced,$GLOBALS['m5pc_factories']);
    echo json_encode(['status'=>M5PC_RULE['status'],'scope'=>M5PC_RULE['scope'],'phase'=>'final_source',
        'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    $m5pcFatalReserve=str_repeat(' ',65536);
    register_shutdown_function(static function()use(&$m5pcFatalReserve):void{
        $m5pcFatalReserve=null;
        $last=error_get_last();
        if(!is_array($last)||!is_int($last['type']??null)
            ||!in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true))return;
        set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{return true;});
        try{
            $code='M5PC_NATIVE_FATAL_GENERAL';$message=$last['message']??null;
            if($last['type']===E_ERROR&&is_string($message)&&str_starts_with($message,'Allowed memory size of '))
                $code='M5PC_NATIVE_FATAL_MEMORY_LIMIT';
            elseif(in_array($last['type'],[E_ERROR,E_COMPILE_ERROR],true)&&is_string($message)
                &&(str_starts_with($message,'Cannot declare ')||str_starts_with($message,'Cannot redeclare ')
                    ||str_starts_with($message,'Declaration of ')))$code='M5PC_NATIVE_FATAL_DECLARATION';
            $file=$last['file']??null;
            if(is_string($file))$file=basename(str_replace('\\','/',$file));
            if(!is_string($file)||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\z/D',$file)!==1)$file=null;
            $line=$last['line']??null;if(!is_int($line)||$line<0)$line=null;
            $phase=$GLOBALS['m4c_phase']??null;
            if(!is_string($phase)||preg_match('/\A[a-z][a-z0-9_]{0,95}\z/D',$phase)!==1)$phase=null;
            $limit=ini_get('memory_limit');
            if(!is_string($limit)||strlen($limit)>32||preg_match('/\A(?:-1|[0-9]+[KMG]?)\z/Di',$limit)!==1)$limit=null;
            $json=json_encode(['scope'=>'MS5_PAID_PRODUCER_NATIVE_FATAL_DIAGNOSTIC','code'=>$code,'type'=>$last['type'],
                'file'=>$file,'line'=>$line,'phase'=>$phase,'memory_limit'=>$limit,'peak_bytes'=>memory_get_peak_usage(true)],
                JSON_UNESCAPED_SLASHES);
            if(!is_string($json)||!defined('STDERR')||!is_resource(STDERR))return;
            $raw=$json."\n";$offset=0;
            while($offset<strlen($raw)){$written=fwrite(STDERR,substr($raw,$offset));
                if(!is_int($written)||$written<=0)return;$offset+=$written;}
        }catch(Throwable $diagnosticError){}
        finally{restore_error_handler();}
    });
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4c_warning']=['severity'=>$severity,'file'=>basename($file),'line'=>$line];
        $GLOBALS['m4c_failed_assertion']='M4C_PHP_WARNING';throw new RuntimeException('M4C_PHP_WARNING');});
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
    try{exit(m5pc_run());}
    catch(Throwable $error){
        $code=$GLOBALS['m5pcp_failed_assertion']??$GLOBALS['m4c_failed_assertion']??'M4C_PAID_UNCLASSIFIED_SOURCE_FAILURE';
        if(!is_string($code)||preg_match('/\A[A-Z0-9_]+\z/D',$code)!==1)$code='M4C_PAID_FIXED_FAILURE';
        $source=$GLOBALS['m5pc_source']??null;
        if(is_array($source)){
            try{m4c_entry_publish('/run/audit/evidence/'.M5PC_RULE['result'],['schema'=>1,'scope'=>M5PC_RULE['scope'],
                'status'=>'INCOMPLETE_MS5_PAID_PRODUCER_INTERNAL_ONLY',
                'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
                'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
                'source_binding_sha256'=>$source['source_binding_sha256'],'phase'=>$GLOBALS['m4c_phase']??'source',
                'entry_return_code'=>1,'termination'=>'EXCEPTION','native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
                'failed_assertion'=>$code,'child'=>$GLOBALS['m5pc_child_proof']??null,
                'customer_paid_API_DOM'=>'NOT RUN','merchant_paid_API_DOM'=>'NOT RUN','platform_paid_API_DOM'=>'NOT RUN',
                'full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN']);}catch(Throwable $publishError){}
        }
        echo json_encode(['status'=>'INCOMPLETE_MS5_PAID_PRODUCER_INTERNAL_ONLY','failed_assertion'=>$code,
            'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";exit(1);
    }
}
