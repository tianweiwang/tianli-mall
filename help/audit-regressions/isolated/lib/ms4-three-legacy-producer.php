<?php
declare(strict_types=1);

/** SOURCE DRAFT ONLY: the full 101-root/six-read suite and profile are not wired or run. */
/** Dedicated normal legacy CLI child only; parent factory and six native ENV values remain true. */
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceTradePolicy;
use think\facade\Db;

require_once dirname(__DIR__).'/ms4-fulfillment-normal.php';
require_once __DIR__.'/ms4-three-read-normal.php';

function m4trp_source(): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'legacy']
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
        &&$binding['entry_sha256']===hash_file('sha256','/workspace/'.M4FN_ENTRY),'M4C_ORIGINAL_CREATED_AND_COMPLETE_SOURCE_BINDING');
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
    m4fn_expect($created['stop_signal']==='SIGTERM'&&getmypid()>1&&posix_getppid()===1
        &&posix_getpgid(0)===getmypid()&&posix_getsid(0)===getmypid(),'TASK6_THREE_REAL_LEGACY_CLI_OWN_GROUP_PARENT_ONE');
    $binds=['/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $volumes=['/workspace'=>['source-tree',false],'/source'=>['source-tree',false],
        '/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',true],
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
    audit_guard_assert();
    $source=$binding+['rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment']),
        'environment_native_sha256'=>m4c_entry_hash($evidence.$rule['proof'].'-environment-native.json')];
    m4fn_full_source($source);
    m4fn_prerequisites($source);
    return $source;
}
function m4trp_app(array $source): \think\App
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
    $runtime='/tmp/ms4-three-legacy-'.$source['run_id'].'/';
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
    $marketplace=$app->config->get('marketplace');
    m4fn_expect(is_array($marketplace)&&($marketplace['enabled']??null)===true
        &&is_array($marketplace['trade']??null)&&($marketplace['trade']['enabled']??null)===true,'TASK6_THREE_ORIGINAL_CHILD_CONFIGURATION_LOADED');
    $marketplace['enabled']=false;$marketplace['trade']['enabled']=false;$app->config->set($marketplace,'marketplace');
    $database=require '/workspace/crmeb/config/database.php';
    $database['connections']['mysql']['params']=[PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    $database['connections']['mysql']['break_reconnect']=false;$app->config->set($database,'database');
    require_once '/workspace/crmeb/app/common.php';$app->register(\think\service\ModelService::class);
    $app->bootService($app->getService(\think\service\ModelService::class));date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));
    $self=MarketplaceOrderInput::unsigned($app->config->get('marketplace.self_operated_mer_id'),'self_operated_mer_id');
    m4fn_expect($app->db instanceof \crmeb\compat\think\Db&&$app->config->get('marketplace.enabled')===false
        &&$app->config->get('marketplace.trade.enabled')===false&&$app->config->get('marketplace.payment.test_enabled')===true
        &&MarketplaceTradePolicy::active()===false&&MarketplaceTradePolicy::v1CreateEnabled()===false
        &&$self>0&&$self===$source['self_operated_mer_id'],'TASK6_THREE_CHILD_DOUBLE_FALSE_REAL_POLICY_AND_SELF_ID');
    m4c_expect($app->config->get('cache.default')==='redis'&&$app->config->get('cache.stores.redis.type')==='redis'
        &&$app->config->get('cache.stores.redis.host')==='audit-redis'&&(string)$app->config->get('cache.stores.redis.port')==='6379'
        &&$app->config->get('cache.stores.redis.select')===0&&$app->config->get('cache.stores.redis.prefix')==='c:'
        &&$app->config->get('cache.stores.redis.password')===$config['redis_password']
        &&$app->env->get('app.app_key')===$config['signing_key']&&$app->config->get('auth.session_epoch')===$config['auth']['session_epoch']
        &&$app->config->get('auth.stamp_key')===$config['auth']['stamp_key']&&$app->config->get('auth.issuer')===$config['auth']['issuer'],
        'M4C_REAL_SAME_REDIS_AUTH_AND_CACHE_PREFIX');
    $row=m4cb_rows(audit_db(),'SELECT `mer_id` FROM `eb_marketplace_merchant` WHERE `mer_id` = ?',[$self]);
    m4c_expect(count($row)===1&&(int)$row[0]['mer_id']===$self,'M4C_ACTUAL_MS2_SELF_ROW_PRESENT');
    $events=require '/workspace/crmeb/app/event.php';
    m4fn_expect(is_array($events)&&is_array($events['bind']??null)&&is_array($events['listen']??null),
        'TASK6_THREE_ORIGINAL_COMPLETE_EVENT_MAPPING');
    foreach(['OrderCreateAfterListener','OutPushListener','CustomEventListener']as$event)
        m4fn_expect(!$app->event->hasListener($event),'TASK6_THREE_NO_DUPLICATE_EVENT_REGISTRATION');
    $app->loadEvent($events);
    foreach($events['listen']as$event=>$listeners)
        m4fn_expect(is_string($event)&&is_array($listeners)&&$app->event->hasListener($event),
            'TASK6_THREE_FULL_REAL_EVENT_MAPPING_INSTALLED');
    m4fn_expect(!$app->initialized()&&MarketplaceTradePolicy::active()===false&&MarketplaceTradePolicy::v1CreateEnabled()===false,
        'TASK6_THREE_CHILD_NO_APP_INIT_OR_ENABLED_POLICY');
    $GLOBALS['m4trp_event_mapping_sha256']=hash_file('sha256','/workspace/crmeb/app/event.php');
    $GLOBALS['m4c_application']=$app;return $app;
}



/** Native preparatory rows plus the original complete database projection. */
function m4trp_snapshot(PDO $db):array
{
    $database=m5pcp_database_snapshot($db);$rows=[];
    foreach(M4CB_TABLES as$table)$rows[$table]=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($table));
    return ['database'=>$database,'rows'=>$rows,'redis'=>m5pcp_atomic_redis(audit_redis())];
}
function m4trp_expected_row(array $metadata,array $actual,array $values):array
{
    m4c_entry_keys($actual,array_keys($metadata['columns']));$expected=[];
    foreach(array_keys($values)as$key)m4fn_expect(isset($metadata['columns'][$key]),'TASK6_THREE_DECLARED_EXPECTED_COLUMN');
    foreach($metadata['columns']as$key=>$column){$value=array_key_exists($key,$values)?$values[$key]:$column['COLUMN_DEFAULT'];
        if($key==='spread_uid'&&$value===-1){
            m4fn_expect(preg_match('/\\A(?:tinyint|smallint|mediumint|int|bigint)(?:\\([0-9]+\\))?\\z/D',strtolower($column['COLUMN_TYPE']))===1,
                'TASK6_THREE_ORIGINAL_NEGATIVE_SPREAD_COLUMN_IS_SIGNED');$expected[$key]=-1;
        }elseif(is_array($value)){
            m4c_entry_keys($value,['json']);m5pcp_json_expected($actual[$key],$value['json']);$expected[$key]=$actual[$key];
        }else $expected[$key]=m4fn_native_value($column,$value);
    }
    m4fn_expect($actual===$expected,'TASK6_THREE_ALL_NATIVE_COLUMNS_WITH_INDEPENDENT_DEFAULTS');return $expected;
}
function m4trp_metadata(array $before,array $after,int $inserts):void
{
    m4fn_expect($inserts>=0&&$inserts<=2,'TASK6_THREE_FINITE_NATIVE_INSERT_COUNT');
    if($inserts===0){m4fn_expect($after['metadata']===$before['metadata']&&$after['ddl']===$before['ddl']
        &&$after['projected_columns']===$before['projected_columns'],'TASK6_THREE_NO_SCHEMA_OR_COUNTER_CHANGE');return;}
    $old=m5pcp_u64($before['metadata']['table']['AUTO_INCREMENT']);$next=bcadd($old,(string)$inserts,0);$metadata=$before['metadata'];
    m4fn_expect(m5pcp_u64($after['metadata']['table']['AUTO_INCREMENT'])===$next,'TASK6_THREE_EXACT_NATIVE_COUNTER_ADVANCE');
    $metadata['table']['AUTO_INCREMENT']=$after['metadata']['table']['AUTO_INCREMENT'];
    m4fn_expect($after['metadata']===$metadata&&$after['projected_columns']===$before['projected_columns'],'TASK6_THREE_ALL_OTHER_METADATA_UNCHANGED');
    $option=' AUTO_INCREMENT='.$old;$replacement=' AUTO_INCREMENT='.$next;
    if(str_contains($before['ddl'],$option)){
        m4fn_expect(substr_count($before['ddl'],$option)===1,'TASK6_THREE_ONE_NATIVE_COUNTER_OPTION');$ddl=str_replace($option,$replacement,$before['ddl']);
    }else{
        m4fn_expect($old==='1'&&substr_count($before['ddl'],' DEFAULT CHARSET=')===1,'TASK6_THREE_ONLY_OMITTED_INITIAL_COUNTER');
        $ddl=str_replace(' DEFAULT CHARSET=',$replacement.' DEFAULT CHARSET=',$before['ddl']);
    }
    m4fn_expect($after['ddl']===$ddl,'TASK6_THREE_ONLY_EXACT_COUNTER_DDL_CHANGE');
}
/** Expected rows are fixed producer inputs and declared defaults; Redis is verified separately. */
function m4trp_database_delta(array $before,array $after,array $changes,array $inserts=[]):void
{
    m4fn_expect(array_keys($before['rows'])===M4CB_TABLES&&array_keys($after['rows'])===M4CB_TABLES,
        'TASK6_THREE_COMPLETE_NATIVE_PREPARE_ROW_SET');$expected=$before['database'];
    foreach($changes as$table=>$rows){
        m4fn_expect(in_array($table,M4CB_TABLES,true)&&is_array($rows)&&array_is_list($rows),'TASK6_THREE_FIXED_CHANGED_TABLE_AND_ROWS');
        $old=$before['database']['tables'][$table];$new=$after['database']['tables'][$table];m4trp_metadata($old,$new,$inserts[$table]??0);
        m4fn_expect(m4fn_row_multiset($after['rows'][$table])===m4fn_row_multiset($rows)&&$new['projection']===m4fn_projection($rows),
            'TASK6_THREE_EVERY_CHANGED_NATIVE_ROW_EXACT');$expected['tables'][$table]=$new;
        if(array_key_exists($table,$expected['target_rows'])){
            $target=$rows;$pk=$table==='eb_marketplace_payment_registry'?'order_id':'id';
            usort($target,static fn(array $a,array $b):int=>bccomp((string)$a[$pk],(string)$b[$pk],0));$expected['target_rows'][$table]=$target;
        }
    }
    foreach($before['rows']as$table=>$rows)if(!array_key_exists($table,$changes))
        m4fn_expect($after['rows'][$table]===$rows,'TASK6_THREE_ALL_OTHER_FULL_NATIVE_ROWS_UNCHANGED');
    m4fn_expect($after['database']===$expected,'TASK6_THREE_FULL_NON_TARGET_DATABASE_MODES_METADATA_COUNTERS_PRESERVED');
}
function m4trp_insert(PDO $db,string $table,array $values):array
{
    m4fn_expect(in_array($table,['eb_system_config','eb_store_product','eb_store_product_attr','eb_store_product_attr_value'],true),
        'TASK6_THREE_ONLY_DEDICATED_LEGACY_FIXTURE_TABLES');$before=m4trp_snapshot($db);$row=m4cb_insert($db,$table,$values);
    $id=m5pcp_u64($row['id']);m4fn_expect($id===m5pcp_u64($before['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']),
        'TASK6_THREE_DATABASE_GENERATED_FIXTURE_ID');
    $checked=m4trp_expected_row($before['database']['tables'][$table]['metadata'],$row,['id'=>$id]+$values);
    $rows=$before['rows'][$table];$rows[]=$checked;$after=m4trp_snapshot($db);
    m4trp_database_delta($before,$after,[$table=>$rows],[$table=>1]);
    m4fn_expect($before['redis']['keys']===$after['redis']['keys'],'TASK6_THREE_NATIVE_FIXTURE_NO_CACHE_WRITES');return $checked;
}

/** Preserve every source row; never disable a real handler to make creation pass. */
function m4trp_event_preconditions(\think\App $app,PDO $db):array
{
    m4fn_expect(app()===$app&&$app->config->get('marketplace.enabled')===false&&$app->config->get('marketplace.trade.enabled')===false
        &&MarketplaceTradePolicy::active()===false&&MarketplaceTradePolicy::v1CreateEnabled()===false,
        'TASK6_THREE_REAL_LEGACY_DOUBLE_FALSE_BEFORE_ANY_BUSINESS_SERVICE');
    foreach(['OrderCreateAfterListener','OutPushListener','CustomEventListener']as$name)
        m4fn_expect($app->event->hasListener($name),'TASK6_THREE_REAL_ROOT_EVENT_LISTENER_INSTALLED');
    m4fn_expect(hash_file('sha256','/workspace/crmeb/app/event.php')===($GLOBALS['m4trp_event_mapping_sha256']??null),
        'TASK6_THREE_REAL_COMPLETE_EVENT_SOURCE_UNCHANGED');$configuration=[];
    foreach(['brokerage_func_status','division_status']as$name){
        $rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($rows)<=1,'TASK6_THREE_UNIQUE_DEDICATED_ZERO_CONFIGURATION');$inserted=$rows===[];
        if($inserted)$rows=[m4trp_insert($db,'eb_system_config',['menu_name'=>$name,'type'=>'text','value'=>json_encode(0,JSON_THROW_ON_ERROR)])];
        m4fn_expect($rows[0]['value']==='0'&&json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR)===0,
            'TASK6_THREE_EXISTING_ZERO_PRESERVED_NONZERO_NEVER_REWRITTEN');
        m4fn_expect($app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($name)===0
            &&(string)sys_config($name)==='0','TASK6_THREE_REAL_JSON_AND_SYSTEM_ZERO_GETTERS');
        $configuration[$name]=['row'=>$rows[0],'inserted'=>$inserted];
    }
    $queue=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',['queue_open']);
    m4fn_expect(count($queue)===1&&$queue[0]['value']==='0'&&(string)sys_config('queue_open')==='0',
        'TASK6_THREE_ORIGINAL_QUEUE_ZERO_REAL_SYNCHRONOUS_DISPATCH_NO_DELAYED_ENQUEUE');
    $push=m5pcp_rows($db,'SELECT * FROM eb_out_account WHERE is_del = 0 AND status = 1 AND push_open = 1');
    $printers=m5pcp_rows($db,'SELECT * FROM eb_system_ticket WHERE is_del = 0 AND status = 1 AND print_type = 2');
    $events=m5pcp_rows($db,'SELECT * FROM eb_system_event WHERE mark IN (?,?) AND is_del = 0 AND is_open = 1',['user_add_cart','order_create']);
    m4fn_expect($push===[]&&$printers===[]&&$events===[],'TASK6_THREE_ACTUAL_NO_PUSH_PRINTER_OR_CUSTOM_OUTBOUND_CANDIDATES');
    m4fn_expect($app->make(\app\dao\system\SystemTicketDao::class)->ticketList(['status'=>1,'print_type'=>2])===[],
        'TASK6_THREE_ORIGINAL_PRINT_DAO_ACTUAL_EMPTY_SELECTION');
    $coupons=m5pcp_rows($db,'SELECT * FROM eb_store_coupon_issue');
    m4fn_expect($coupons===[],'TASK6_THREE_REAL_OLD_CONFIRMATION_HAS_NO_AUTORECEIVE_COUPON_SOURCE');
    $log=$app->config->get('log');
    m4fn_expect(is_array($log)&&$log['default']==='file'&&$log['type_channel']===[]&&in_array('error',$log['level'],true)
        &&$log['channels']['file']['type']==='File'&&$log['channels']['file']['path']===$app->getRuntimePath().'log/'
        &&($log['channels']['file']['lazy']??true)===true,'TASK6_THREE_REAL_ERROR_CHANNEL_IS_OBSERVABLE');
    return ['queue_open'=>0,'outbound_push_candidates'=>0,'print_candidates'=>0,'custom_event_candidates'=>0,
        'auto_coupon_source_rows'=>0,'complete_event_mapping_sha256'=>$GLOBALS['m4trp_event_mapping_sha256'],'configuration'=>$configuration];
}
/** This inventory covers only the dedicated child's runtime, never the whole filesystem. */
function m4trp_runtime_files(\think\App $app):array
{
    $root=$app->getRuntimePath();m4fn_expect($root==='/tmp/ms4-three-legacy-'.getenv('AUDIT_RUN_ID').'/'
        &&is_dir($root)&&!is_link($root),'TASK6_THREE_ONE_OWN_LEGACY_RUNTIME');$out=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as$file){$name=$file->getPathname();
        m4fn_expect(!$file->isLink()&&$file->isFile()&&$file->getSize()<=2097152&&str_starts_with($name,$root),
            'TASK6_THREE_ONLY_OWN_BOUNDED_REGULAR_RUNTIME_FILES');$relative=substr($name,strlen($root));
        m4fn_expect($relative!==''&&!isset($out[$relative]),'TASK6_THREE_UNIQUE_RUNTIME_PATH');
        $raw=file_get_contents($name);m4fn_expect(is_string($raw)&&strlen($raw)===$file->getSize(),'TASK6_THREE_REAL_COMPLETE_RUNTIME_BYTES');
        $out[$relative]=['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];
    }ksort($out,SORT_STRING);return $out;
}
function m4trp_no_caught_error(\think\App $app,array $logBefore,array $filesBefore):void
{
    m4fn_expect($app->log->getLog()===$logBefore,'TASK6_THREE_NO_SWALLOWED_PRODUCTLOG_PRINT_OR_OTHER_NEW_LOG');
    m4fn_expect(m4trp_runtime_files($app)===$filesBefore,'TASK6_THREE_NO_HIDDEN_LOG_OR_UNREGISTERED_RUNTIME_WRITE');
}

/** One real free-shipping product; original paid Q2/Q1 products and owner/address are retained. */
function m4trp_fixture(\think\App $app,PDO $db,array $owner):array
{
    $uid=$owner['uid'];$merchant=\app\services\marketplace\publiccatalog\PublicCatalogQuery::positive($app->config->get('marketplace.self_operated_mer_id'));$now=time();
    $selfRows=m5pcp_rows($db,'SELECT * FROM eb_marketplace_merchant WHERE mer_id = ?',[$merchant]);
    m4fn_expect(count($selfRows)===1&&m5pcp_int($selfRows[0]['mer_id'],1)===$merchant
        &&m5pcp_int($selfRows[0]['review_status'],1,1)===1&&m5pcp_int($selfRows[0]['security_locked'],0,0)===0
        &&$selfRows[0]['closed_at']===null&&m5pcp_int($selfRows[0]['accept_orders'],1,1)===1,
        'TASK6_THREE_LEGACY_ACTUAL_CONFIGURED_SELF_QUALIFIED_BEFORE_NEW_CATALOG');
    m4fn_expect($app->config->get('marketplace.enabled')===false&&$app->config->get('marketplace.trade.enabled')===false
        &&MarketplaceTradePolicy::active()===false&&MarketplaceTradePolicy::v1CreateEnabled()===false,
        'TASK6_THREE_LEGACY_CONFIGURED_SELF_DOES_NOT_ENABLE_MARKETPLACE');
    $users=m5pcp_rows($db,'SELECT * FROM eb_user WHERE uid = ?',[$uid]);
    m4fn_expect(count($users)===1&&$users[0]===$owner['baseline']['authenticated_owner']['actual_user'],
        'TASK6_THREE_LEGACY_REUSES_TRUE_PAID_HTTP_OWNER');
    foreach(['member_func_status','member_card_status','member_price_status']as$name){
        $rows=m5pcp_rows($db,'SELECT * FROM eb_system_config WHERE menu_name = ?',[$name]);
        m4fn_expect(count($rows)===1&&$rows[0]['value']==='0'&&(string)sys_config($name)==='0',
            'TASK6_THREE_LEGACY_ORIGINAL_NONMEMBER_CONFIGURATION_BEFORE_LATER_PREPARATION');
    }
    $addressId=m5pcp_int($owner['loaded']['private']['fixture']['address']['id'],1);
    $addresses=m5pcp_rows($db,'SELECT * FROM eb_user_address WHERE id = ?',[$addressId]);
    m4fn_expect(count($addresses)===1&&m5pcp_int($addresses[0]['uid'],1)===$uid
        &&m5pcp_int($addresses[0]['is_del'],0,0)===0&&m5pcp_int($addresses[0]['is_default'],1,1)===1,
        'TASK6_THREE_TRUE_OWNER_EXISTING_DEFAULT_ADDRESS');
    $product=m4trp_insert($db,'eb_store_product',['mer_id'=>$merchant,'store_name'=>'通用旧订单验证商品','unit_name'=>'件',
        'spec_type'=>1,'stock'=>10,'sales'=>0,'price'=>'20.15','cost'=>'7.45','postage'=>'0.00','freight'=>1,'temp_id'=>0,
        'is_show'=>1,'review_status'=>2,'is_vip'=>0,'logistics'=>'1','min_qty'=>1,'is_limit'=>0,
        'image'=>'/__ms4_fixture__/product.png','slider_image'=>'["/__ms4_fixture__/product.png"]','custom_form'=>'[]','add_time'=>$now]);
    $attr=m4trp_insert($db,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>'普通']);
    $sku=m4trp_insert($db,'eb_store_product_attr_value',['product_id'=>$product['id'],'type'=>0,'unique'=>bin2hex(random_bytes(4)),
        'suk'=>'普通','stock'=>10,'sales'=>0,'price'=>'20.15','cost'=>'7.45','is_show'=>1,'is_virtual'=>0,
        'weight'=>'0.00','volume'=>'0.00','image'=>'/__ms4_fixture__/product.png']);
    $definition=$app->make(\app\services\marketplace\publiccatalog\PublicCatalogSku::class)->read(m5pcp_int($product['id'],1));
    m4fn_expect(is_array($definition)&&is_array($definition['root']??null)&&$definition['definition_complete']===true
        &&$definition['stock']===10&&$definition['raw_values']===[$sku['suk']=>$sku],
        'TASK6_THREE_LEGACY_REAL_PUBLIC_SELF_PRODUCT_COMPLETE_NATIVE_SKU');
    $expectedRoot=$product;
    foreach(['mer_id'=>'_public_store_mer_id','name'=>'_public_store_name','logo'=>'_public_store_logo',
        'after_sale_contact'=>'_public_store_after_sale_contact','shipping_note'=>'_public_store_shipping_note']as$field=>$joined)
        $expectedRoot[$joined]=$selfRows[0][$field];
    $actualFields=array_keys($definition['root']);$expectedFields=array_keys($expectedRoot);
    sort($actualFields,SORT_STRING);sort($expectedFields,SORT_STRING);
    m4fn_expect($actualFields===$expectedFields,'TASK6_THREE_LEGACY_PUBLIC_ROOT_EXACT_NATIVE_FIELD_SET');
    foreach($expectedRoot as$field=>$value)m4fn_expect($definition['root'][$field]===$value,
        'TASK6_THREE_LEGACY_PUBLIC_ROOT_ALL_NATIVE_VALUES_AND_TYPES');
    $purchase=$app->make(\app\services\marketplace\publiccatalog\PublicCatalogQuery::class)->purchasableRoot(m5pcp_int($product['id'],1));
    m4fn_expect($purchase===$definition['root'],'TASK6_THREE_LEGACY_REAL_SELF_CURRENT_PURCHASE_ROOT_MATCHES_PUBLIC_READ');
    return ['user'=>$users[0],'address'=>$addresses[0],'product'=>$product,'attr'=>$attr,'sku'=>$sku];
}
/** All timestamps used as expected values must be actual native saved observations in the real call interval. */
function m4trp_time(mixed $value,int $begin,int $end):int
{
    $time=m5pcp_int($value,1,4294967295);m4fn_expect($time>=$begin&&$time<=$end,'TASK6_THREE_REAL_SAVED_TIME_IN_PRODUCER_INTERVAL');return $time;
}
function m4trp_replaced_row(array $rows,string $pk,array $replacement):array
{
    $seen=0;foreach($rows as$i=>$row)if($row[$pk]===$replacement[$pk]){$rows[$i]=$replacement;++$seen;}
    m4fn_expect($seen===1,'TASK6_THREE_EXACT_ONE_EXISTING_NATIVE_ROW_REPLACED');return $rows;
}
/** Real original cart -> old confirmation -> old create -> complete native row readback. No HTTP auth is inferred. */
function m4trp_produce(\think\App $app,PDO $db,array $owner,array $fixture):array
{
    $uid=$owner['uid'];$product=$fixture['product'];$sku=$fixture['sku'];$address=$fixture['address'];
    m4fn_expect(MarketplaceTradePolicy::active()===false&&MarketplaceTradePolicy::v1CreateEnabled()===false,
        'TASK6_THREE_OLD_PRODUCER_POLICY_STILL_DOUBLE_FALSE');
    $logs=$app->log->getLog();$files=m4trp_runtime_files($app);$before=m4trp_snapshot($db);$begin=time();
    $cartId=$app->make(\app\services\order\StoreCartServices::class)->setCart($uid,m5pcp_int($product['id'],1),1,$sku['unique'],0,false);
    $end=time();$cartId=m5pcp_u64($cartId);$cart=m5pcp_rows($db,'SELECT * FROM eb_store_cart WHERE id = ?',[$cartId]);
    m4fn_expect(count($cart)===1&&$cartId===m5pcp_u64($before['database']['tables']['eb_store_cart']['metadata']['table']['AUTO_INCREMENT']),
        'TASK6_THREE_REAL_PERSISTENT_CART_NATURAL_ID');
    $cart=m4trp_expected_row($before['database']['tables']['eb_store_cart']['metadata'],$cart[0],['id'=>$cartId,'uid'=>$uid,
        'product_id'=>$product['id'],'product_attr_unique'=>$sku['unique'],'cart_num'=>1,'type'=>'0',
        'add_time'=>m4trp_time($cart[0]['add_time'],$begin,$end)]);
    $statistics=m5pcp_rows($db,'SELECT * FROM eb_store_product_log WHERE id = ?',
        [$before['database']['tables']['eb_store_product_log']['metadata']['table']['AUTO_INCREMENT']]);
    m4fn_expect(count($statistics)===1,'TASK6_THREE_REAL_SYNCHRONOUS_CART_PRODUCT_LOG_EXISTS');
    $statistics=m4trp_expected_row($before['database']['tables']['eb_store_product_log']['metadata'],$statistics[0],
        ['id'=>$before['database']['tables']['eb_store_product_log']['metadata']['table']['AUTO_INCREMENT'],
        'type'=>'cart','product_id'=>$product['id'],'uid'=>$uid,'cart_num'=>1,
        'add_time'=>m4trp_time($statistics[0]['add_time'],$begin,$end)]);
    $cartRows=$before['rows']['eb_store_cart'];$cartRows[]=$cart;$logRows=$before['rows']['eb_store_product_log'];$logRows[]=$statistics;
    $afterCart=m4trp_snapshot($db);m4trp_database_delta($before,$afterCart,['eb_store_cart'=>$cartRows,'eb_store_product_log'=>$logRows],
        ['eb_store_cart'=>1,'eb_store_product_log'=>1]);m4trp_no_caught_error($app,$logs,$files);
    $user=$app->make(\app\services\user\UserServices::class)->getUserInfo($uid);
    m4fn_expect($user instanceof \app\model\user\User,'TASK6_THREE_ORIGINAL_LEGACY_USER_MODEL_EXISTS');
    foreach($fixture['user']as$key=>$value)m4fn_expect($user->getData($key)===$value,'TASK6_THREE_REAL_MODEL_MATCHES_NATIVE_OWNER');
    $orders=$app->make(\app\services\order\StoreOrderServices::class);$confirmBegin=time();
    $confirmation=$orders->getOrderConfirmData($user->toArray(),$cartId,false,m5pcp_int($address['id'],1));$confirmEnd=time();
    $key=$confirmation['orderKey']??null;m4fn_expect(is_string($key)&&$key!==''&&!$orders->hasMarketplaceConfirmation($uid,$key),
        'TASK6_THREE_ACTUAL_OLD_CONFIRMATION_NO_MARKETPLACE_FRAME');
    $group=$orders->getCacheOrderInfo($uid,$key);m4c_entry_keys($group,['cartInfo','priceGroup','other']);
    m4fn_expect(count($group['cartInfo'])===1,'TASK6_THREE_ONE_REAL_OLD_CONFIRMATION_ITEM');$input=$group['cartInfo'][0];
    m4fn_expect((string)$input['id']===$cartId&&m5pcp_int($input['uid'],1)===$uid&&m5pcp_int($input['cart_num'],1,1)===1
        &&m5pcp_int($input['product_id'],1)===m5pcp_int($product['id'],1)
        &&m5pcp_int($input['productInfo']['id'],1)===m5pcp_int($product['id'],1)
        &&m5pcp_int($input['productInfo']['attrInfo']['id'],1)===m5pcp_int($sku['id'],1)
        &&$input['productInfo']['attrInfo']['unique']===$sku['unique']&&$input['sum_price']==='20.15'
        &&$input['truePrice']===20.15&&$input['vip_truePrice']===0.0&&$input['price_type']==='level'
        &&$input['costPrice']==='7.45'&&bccomp((string)$input['postage_price'],'0',2)===0,
        'TASK6_THREE_REAL_OLD_QUOTE_MATCHES_INDEPENDENT_SAVED_PRICE_INPUTS');
    m4fn_expect(m5pcp_int($input['productInfo']['mer_id']??null,1)===m5pcp_int($product['mer_id'],1)
        &&m5pcp_int($product['mer_id'],1)===\app\services\marketplace\publiccatalog\PublicCatalogQuery::positive($app->config->get('marketplace.self_operated_mer_id')),
        'TASK6_THREE_REAL_OLD_QUOTE_PRODUCT_OWNER_MATCHES_CONFIGURED_SELF');
    foreach(['totalPrice'=>'20.15','sumPrice'=>'20.15','costPrice'=>'7.45','vipPrice'=>'0.00','levelPrice'=>'0.00','memberPrice'=>0]as$field=>$value)
        m4fn_expect($group['priceGroup'][$field]===$value,'TASK6_THREE_INDEPENDENT_OLD_QUOTE_ALL_AMOUNTS');
    m4fn_expect(bccomp((string)$group['priceGroup']['storePostage'],'0',2)===0,'TASK6_THREE_REAL_FREE_SHIPPING_QUOTE');
    $beforeCreate=m4trp_snapshot($db);m4trp_database_delta($afterCart,$beforeCreate,[]);$createBegin=time();
    $created=$app->make(\app\services\order\StoreOrderCreateServices::class)->createOrder($uid,$key,$user->toArray(),m5pcp_int($address['id'],1),'');
    $createEnd=time();m4fn_expect($created instanceof \app\model\order\StoreOrder,'TASK6_THREE_REAL_OLD_CREATE_RETURNS_ORIGINAL_MODEL');
    $orderId=m5pcp_u64($created->getData('id'));$rows=m5pcp_rows($db,'SELECT * FROM eb_store_order WHERE id = ?',[$orderId]);
    m4fn_expect(count($rows)===1&&$orderId===m5pcp_u64($beforeCreate['database']['tables']['eb_store_order']['metadata']['table']['AUTO_INCREMENT']),
        'TASK6_THREE_REAL_OLD_ORDER_NATURAL_PRIMARY_KEY');$root=$rows[0];
    m4fn_expect(is_string($root['order_id'])&&preg_match('/\\Acp[1-9][0-9]{0,18}\\z/D',$root['order_id'])===1,
        'TASK6_THREE_REAL_OLD_CP_ORDER_NUMBER');
    $root=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_order']['metadata'],$root,
        ['id'=>$orderId,'uid'=>$uid,'order_id'=>$root['order_id'],'real_name'=>$address['real_name'],'user_phone'=>$address['phone'],
        'user_address'=>$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail'],
        'cart_id'=>['json'=>[$input['id']]],'total_num'=>1,'total_price'=>'20.15','total_postage'=>'0.00','coupon_id'=>0,'coupon_price'=>'0.00',
        'pay_price'=>'20.15','pay_postage'=>'0.00','deduction_price'=>'0.00','gift_price'=>'0.00','paid'=>0,'pay_type'=>'','use_integral'=>'0.00',
        'gain_integral'=>'0.00','cost'=>'7.45','add_time'=>m4trp_time($root['add_time'],$createBegin,$createEnd),'unique'=>$key,'shipping_type'=>1,
        'channel_type'=>$fixture['user']['user_type'],'province'=>'','spread_uid'=>0,'spread_two_uid'=>0,'staff_brokerage'=>'0.00',
        'agent_brokerage'=>'0.00','division_brokerage'=>'0.00','pay_uid'=>$uid,'custom_form'=>['json'=>[]],
        'division_id'=>$fixture['user']['division_id'],'agent_id'=>$fixture['user']['agent_id'],'staff_id'=>$fixture['user']['staff_id']]);
    $items=m5pcp_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid = ?',[$orderId]);
    m4fn_expect(count($items)===1,'TASK6_THREE_REAL_OLD_FULL_SAVED_ITEM');$cartExpected=$input;
    $cartExpected['attrInfo']=$sku;
    $cartExpected['use_integral']=0;$cartExpected['integral_price']=0.00;$cartExpected['coupon_price']=0.00;$cartExpected['sum_true_price']='20.15';
    foreach(['one_brokerage','two_brokerage']as$field)$cartExpected[$field]='0';
    foreach(['staff_brokerage','agent_brokerage','division_brokerage']as$field)$cartExpected[$field]='0.00';
    $item=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_order_cart_info']['metadata'],$items[0],
        ['id'=>$beforeCreate['database']['tables']['eb_store_order_cart_info']['metadata']['table']['AUTO_INCREMENT'],
        'oid'=>$orderId,'uid'=>$uid,'cart_id'=>(string)$input['id'],'product_id'=>$product['id'],
        'cart_info'=>['json'=>json_decode(json_encode($cartExpected,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR)],
        'cart_num'=>1,'surplus_num'=>1,'unique'=>md5($input['id'].''.$orderId)]);
    $statuses=m5pcp_rows($db,'SELECT * FROM eb_store_order_status WHERE oid = ?',[$orderId]);
    m4fn_expect(count($statuses)===1,'TASK6_THREE_REAL_OLD_AFTER_EVENT_STATUS_EXISTS');
    $status=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_order_status']['metadata'],$statuses[0],
        ['oid'=>$orderId,'change_type'=>'cache_key_create_order','change_message'=>'订单生成',
        'change_time'=>m4trp_time($statuses[0]['change_time'],$createBegin,$createEnd)]);
    $statistics=m5pcp_rows($db,'SELECT * FROM eb_store_product_log WHERE id = ?',
        [$beforeCreate['database']['tables']['eb_store_product_log']['metadata']['table']['AUTO_INCREMENT']]);
    m4fn_expect(count($statistics)===1,'TASK6_THREE_REAL_SYNCHRONOUS_ORDER_PRODUCT_LOG_EXISTS');
    $statistics=m4trp_expected_row($beforeCreate['database']['tables']['eb_store_product_log']['metadata'],$statistics[0],
        ['id'=>$beforeCreate['database']['tables']['eb_store_product_log']['metadata']['table']['AUTO_INCREMENT'],
        'type'=>'order','product_id'=>$product['id'],'uid'=>$uid,'order_num'=>1,'add_time'=>m4trp_time($statistics[0]['add_time'],$createBegin,$createEnd)]);
    $expected=$beforeCreate['rows'];$expected['eb_store_order'][]=$root;$expected['eb_store_order_cart_info'][]=$item;
    $expected['eb_store_order_status'][]=$status;$expected['eb_store_product_log'][]=$statistics;
    $remaining=[];$seen=0;foreach($expected['eb_store_cart']as$row){if($row['id']===$cart['id'])++$seen;else$remaining[]=$row;}
    m4fn_expect($seen===1,'TASK6_THREE_REAL_AFTER_EVENT_DELETES_ONLY_OWN_NEW_CART');$expected['eb_store_cart']=$remaining;
    foreach(['eb_store_product'=>$product,'eb_store_product_attr_value'=>$sku]as$table=>$original){
        $changed=$original;$changed['stock']=9;$changed['sales']=1;$expected[$table]=m4trp_replaced_row($expected[$table],'id',$changed);
    }
    if($fixture['user']['real_name']===''){
        $changedUser=$fixture['user'];$changedUser['real_name']=$address['real_name'];$changedUser['record_phone']=$address['phone'];
        $expected['eb_user']=m4trp_replaced_row($expected['eb_user'],'uid',$changedUser);
    }
    $after=m4trp_snapshot($db);$changes=[];
    foreach(['eb_store_order','eb_store_order_cart_info','eb_store_order_status','eb_store_product_log','eb_store_cart','eb_store_product','eb_store_product_attr_value','eb_user']as$table)
        $changes[$table]=$expected[$table];
    m4trp_database_delta($beforeCreate,$after,$changes,['eb_store_order'=>1,'eb_store_order_cart_info'=>1,'eb_store_product_log'=>1]);
    m4fn_expect(\crmeb\services\CacheService::get('user_order_'.$uid.$key)==='',
        'TASK6_THREE_REAL_AFTER_EVENT_REMOVES_TRUE_CONFIRMATION_CACHE');m4trp_no_caught_error($app,$logs,$files);
    return ['fixture'=>$fixture,'cart'=>$cart,'root'=>$root,'item'=>$item,'confirmation_input'=>$input,
        'confirmation_interval'=>[$confirmBegin,$confirmEnd],'create_interval'=>[$createBegin,$createEnd],
        'before'=>$before,'before_create'=>$beforeCreate,'after'=>$after,'native_log_rows'=>2,'old_status_rows'=>1,'original_paid_owner_identity_preserved'=>true,
        'original_real_name_fill'=>$fixture['user']['real_name']===''];
}

/** Verify all preparatory cache mutations from real native sources; never publish raw names/values. */
function m4trp_verify_cache(\think\App $app,PDO $db,array $wholeBefore,array $tagBefore,array $warm,array $produced):array
{
    $root=$produced['root'];$item=$produced['item'];$uid=m5pcp_int($root['uid'],1);$oid=m5pcp_int($root['id'],1);
    $cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$product=$cart['productInfo'];$attr=$product['attrInfo'];
    m4fn_expect((string)sys_config('image_thumb_status')==='0','TASK6_THREE_REAL_DISABLED_THUMBNAIL_SOURCE');
    m4fn_expect(get_thumb_water($attr)===$attr&&get_thumb_water($product)===$product,
        'TASK6_THREE_REAL_CLOSED_THUMBNAIL_ENGINE_EXACT_PASSTHROUGH');
    $refunds=m5pcp_rows($db,'SELECT refund_num FROM eb_store_order_cart_info WHERE cart_id = ?',[$item['cart_id']]);$sum=0;
    foreach($refunds as$row)$sum+=m5pcp_int($row['refund_num'],0,2147483647);
    m4fn_expect($sum===0,'TASK6_THREE_REAL_LEGACY_REFUND_AGGREGATE_ZERO');$cart['refund_num']=0.0;
    $info=[$item['cart_id']=>['cart_info'=>$cart]];
    $cartKey='c:'.md5('store_order_cart_info_'.$oid);$titleKey='c:'.md5('store_order_cart_product_title_'.$oid);
    $confirmationKey='c:user_order_'.$uid.$root['unique'];
    m4fn_expect(!array_key_exists(hash('sha256',$confirmationKey),$wholeBefore['redis']['keys']),
        'TASK6_THREE_REAL_NEW_CONFIRMATION_DID_NOT_REPLACE_AN_OLD_KEY');
    $beforeCreate=$produced['before_create']['redis'];$frame=$beforeCreate['keys'][hash('sha256',$confirmationKey)]??null;
    m4fn_expect(is_array($frame)&&$frame['type']==='string'&&$frame['expires']>0
        &&bccomp((string)$frame['expires'],bcmul((string)($produced['confirmation_interval'][0]+600),'1000',0),0)>=0
        &&bccomp((string)$frame['expires'],bcmul((string)($produced['confirmation_interval'][1]+601),'1000',0),0)<0,
        'TASK6_THREE_REAL_CONFIRMATION_NATIVE_TYPE_AND_ORIGINAL_SIX_HUNDRED_SECOND_EXPIRY');
    $sequence=m4tr_sequence_sources($db,$app,[$root]);
    foreach(array_keys($sequence['keys'])as$hash)m4fn_expect(!array_key_exists($hash,$wholeBefore['redis']['keys']),
        'TASK6_THREE_TRUE_NEW_CP_KEY_NOT_AN_OLD_CACHE_EXCEPTION');
    $barrier=m4tr_sequence_expiry_barrier($db,$sequence);$after=m4trp_snapshot($db);
    m4trp_database_delta($produced['after'],$after,[]);
    $expected=$warm['expected_physical_raw'];$expected[$cartKey]=m4tr_cache_wire($info);
    $expected[$titleKey]=m4tr_cache_wire($produced['fixture']['product']['store_name']);
    m4tr_cache_delta($wholeBefore['redis'],$after['redis'],$expected,$tagBefore,
        array_merge($warm['physical_tag_names'],[$cartKey,$titleKey,$confirmationKey]),[$confirmationKey]);
    return ['schema'=>1,'scope'=>'TRUE_LEGACY_PREPARATION_FULL_REDIS_SOURCE_CLOSURE','complete'=>true,
        'known_system_cache_count'=>count($warm['expected_physical_raw']),'saved_cart_cache_count'=>1,'saved_title_cache_count'=>1,
        'real_confirmation_consumed'=>true,'exact_crmeb_tag_membership'=>true,'all_other_cache_preserved'=>true,
        'sequence'=>$barrier,'before_redis_sha256'=>hash('sha256',serialize($wholeBefore['redis']['keys'])),
        'after_redis_sha256'=>hash('sha256',serialize($after['redis']['keys'])),'after'=>$after];
}

const M4TRP_ARCHIVE='/run/audit-fixtures/ms4-three-legacy-native.json';
/** One create-only private native archive. It is not an identity/token input. */
function m4trp_archive_write(array $source,array $state):array
{
    $directory=dirname(M4TRP_ARCHIVE);m4fn_expect(is_dir($directory)&&!is_link($directory)
        &&realpath($directory)===$directory&&fileowner($directory)===posix_geteuid()&&(fileperms($directory)&0777)===0700
        &&!file_exists(M4TRP_ARCHIVE)&&!is_link(M4TRP_ARCHIVE),'TASK6_THREE_PRIVATE_LEGACY_ARCHIVE_CREATE_ONLY');
    $native=serialize($state);m4fn_expect(strlen($native)>0&&strlen($native)<=16777216,'TASK6_THREE_BOUNDED_COMPLETE_LEGACY_NATIVE_ARCHIVE');
    $value=['schema'=>1,'scope'=>'MS4_THREE_LEGACY_NATIVE_PRIVATE','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'freeze_sha256'=>$source['freeze_sha256'],'container_id'=>$source['container_id'],'source_binding_sha256'=>$source['source_binding_sha256'],
        'native'=>['encoding'=>'php-serialize-array-base64-v1','bytes'=>strlen($native),'sha256'=>hash('sha256',$native),
            'serialized_base64'=>base64_encode($native)]];$raw=m4cb_json($value);$mask=umask(0077);$handle=fopen(M4TRP_ARCHIVE,'xb');
    try{m4fn_expect(is_resource($handle)&&chmod(M4TRP_ARCHIVE,0600)&&fwrite($handle,$raw)===strlen($raw)&&fflush($handle),
        'TASK6_THREE_COMPLETE_PRIVATE_LEGACY_ARCHIVE_WRITE');}
    finally{if(is_resource($handle))fclose($handle);umask($mask);}
    clearstatcache(true,M4TRP_ARCHIVE);$stat=lstat(M4TRP_ARCHIVE);$read=file_get_contents(M4TRP_ARCHIVE);
    m4fn_expect(is_array($stat)&&($stat['mode']&0170000)===0100000&&($stat['mode']&0777)===0600
        &&$stat['uid']===posix_geteuid()&&$stat['nlink']===1&&!is_link(M4TRP_ARCHIVE)&&realpath(M4TRP_ARCHIVE)===M4TRP_ARCHIVE
        &&$stat['size']===strlen($raw)&&$read===$raw,
        'TASK6_THREE_EXACT_PRIVATE_LEGACY_BYTES_MODE_OWNER_READBACK');
    $decoded=json_decode($read,true,64,JSON_THROW_ON_ERROR);m4fn_expect($decoded===$value,'TASK6_THREE_EXACT_PRIVATE_LEGACY_JSON_FRAME');
    $decodedNative=base64_decode($decoded['native']['serialized_base64'],true);
    m4fn_expect(is_string($decodedNative)&&$decodedNative===$native&&base64_encode($decodedNative)===$decoded['native']['serialized_base64'],
        'TASK6_THREE_EXACT_PRIVATE_LEGACY_NATIVE_ENCODING');
    $restored=unserialize($decodedNative,['allowed_classes'=>false,'max_depth'=>64]);
    m4fn_expect(is_array($restored)&&$restored===$state&&serialize($restored)===$native,'TASK6_THREE_COMPLETE_NATIVE_ARRAY_NO_OBJECTS');
    clearstatcache(true,M4TRP_ARCHIVE);$again=lstat(M4TRP_ARCHIVE);$keys=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];
    m4fn_expect(is_array($again),'TASK6_THREE_PRIVATE_ARCHIVE_FINAL_NATIVE_STAT');
    foreach($keys as$key)m4fn_expect($again[$key]===$stat[$key],'TASK6_THREE_PRIVATE_ARCHIVE_STAT_STABLE_DURING_READBACK');
    return ['path'=>M4TRP_ARCHIVE,'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'stat'=>array_intersect_key($stat,array_flip($keys)),
        'native_bytes'=>strlen($native),'native_sha256'=>hash('sha256',$native)];
}
function m4trp_run():int
{
    m4fn_phase('three_legacy_source');$source=m4trp_source();$GLOBALS['m4trp_source']=$source;$app=m4trp_app($source);
    $db=audit_db();m4fn_expect(!$db->inTransaction(),'TASK6_THREE_LEGACY_INDEPENDENT_NATIVE_OBSERVER');$observer=m5pc_observer_configure($db);
    m4fn_phase('three_legacy_original_paid_owner');$owner=m4fn_owner($source,$db);m4fn_migration_database($db,$owner);m4cb_guard_readonly();
    $connection=Db::connect();$default=$connection->getPdo();
    if(!$default instanceof PDO){$connection->query('SELECT 1',[],true);$default=$connection->getPdo();}
    m4fn_expect($default instanceof PDO,'TASK6_REAL_APP_DEFAULT_PDO');m4fn_context($app,$connection,$default,$db);
    $wholeBefore=m4trp_snapshot($db);$tagBefore=m4tr_crmeb_tag_members();m4fn_phase('three_legacy_original_events_and_fixture');
    $events=m4trp_event_preconditions($app,$db);$fixture=m4trp_fixture($app,$db,$owner);
    m4fn_phase('three_legacy_real_warm');$warm=m4tr_warm_legacy_configuration($app,$db);
    m4fn_expect(get_thumb_water($fixture['product'])===$fixture['product']&&get_thumb_water($fixture['sku'])===$fixture['sku'],
        'TASK6_THREE_LEGACY_ORIGINAL_CLOSED_THUMB_ENGINE_WARM');
    m4fn_phase('three_legacy_real_create_and_events');$produced=m4trp_produce($app,$db,$owner,$fixture);
    $cache=m4trp_verify_cache($app,$db,$wholeBefore,$tagBefore,$warm,$produced);m4fn_context($app,$connection,$default,$db);
    m4fn_phase('three_legacy_final_native_source');m4fn_full_source($source);m4fn_prerequisites($source);m4fn_inputs_unchanged($owner['inputs']);
    m4fn_expect(m4trp_source()===$source,'TASK6_THREE_CHILD_COMPLETE_SOURCE_ENVIRONMENT_PREREQUISITES_UNCHANGED');m4cb_guard_readonly();
    $final=m4trp_snapshot($db);m4trp_database_delta($cache['after'],$final,[]);
    m4fn_expect($cache['after']['redis']['keys']===$final['redis']['keys'],'TASK6_THREE_CHILD_FINAL_ALL_CACHE_VALUES_ABSOLUTE_EXPIRIES_UNCHANGED');
    $inputs=[];foreach($owner['inputs']as$input)$inputs[]=['path'=>$input['path'],'stat'=>$input['stat'],'sha256'=>$input['sha256']];
    $state=['schema'=>1,'scope'=>'TRUE_LEGACY_PREPARATION_NATIVE_ONLY','authenticated_owner_uid'=>$owner['uid'],
        'original_inputs'=>$inputs,'observer'=>$observer,'whole_before'=>$wholeBefore,'whole_after'=>$final,
        'event_preconditions'=>$events,'warm'=>$warm,'produced'=>[
            'fixture'=>$produced['fixture'],'cart'=>$produced['cart'],'root'=>$produced['root'],'item'=>$produced['item'],
            'confirmation_input'=>$produced['confirmation_input'],'confirmation_interval'=>$produced['confirmation_interval'],
            'create_interval'=>$produced['create_interval'],'native_log_rows'=>$produced['native_log_rows'],
            'old_status_rows'=>$produced['old_status_rows'],'original_paid_owner_identity_preserved'=>$produced['original_paid_owner_identity_preserved'],
            'original_real_name_fill'=>$produced['original_real_name_fill'],
            'before_sha256'=>hash('sha256',serialize($produced['before'])),
            'before_create_sha256'=>hash('sha256',serialize($produced['before_create'])),
            'after_sha256'=>hash('sha256',serialize($produced['after']))],
        'cache_verification'=>array_diff_key($cache,['after'=>true])];
    $archive=m4trp_archive_write($source,$state);m4fn_inputs_unchanged($owner['inputs']);
    $reply=['schema'=>1,'scope'=>'MS4_THREE_LEGACY_NATIVE_PRODUCER','status'=>'PREPARED_TRUE_LEGACY_ONLY',
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
        'container_id'=>$source['container_id'],'archive'=>$archive,'native_exit'=>null,'native_exit_observation'=>'PARENT_SAME_HANDLE_REQUIRED'];
    $raw=m4cb_json($reply);m4fn_expect(strlen($raw)<=16384,'TASK6_THREE_FINITE_LEGACY_PUBLIC_STDOUT');echo $raw;return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4fn_failed_assertion']='TASK6_THREE_LEGACY_PHP_WARNING';throw new ErrorException('TASK6_THREE_LEGACY_PHP_WARNING',0,$severity,$file,$line);});
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
    try{exit(m4trp_run());}
    catch(Throwable $error){
        $diagnostic=m4fn_diagnostic($error);$source=$GLOBALS['m4trp_source']??null;
        echo m4cb_json(['schema'=>1,'scope'=>'MS4_THREE_LEGACY_NATIVE_PRODUCER','status'=>'INCOMPLETE_TRUE_LEGACY_ONLY',
            'phase'=>$GLOBALS['m4c_phase']??'source','diagnostic'=>$diagnostic,'native_exit'=>null,
            'native_exit_observation'=>'PARENT_SAME_HANDLE_REQUIRED']);exit(1);
    }
}
