<?php
declare(strict_types=1);
const M4UICG_SOURCE_TREE_ENTRY_PINS_STATUS = 'UNBOUND_NEW_B_SOURCE_QUALIFICATION';
if (M4UICG_SOURCE_TREE_ENTRY_PINS_STATUS !== 'BOUND_FROZEN_PHP_SOURCE') { throw new RuntimeException('B_SOURCE_TREE_SOURCE_UNBOUND'); }

/** SOURCE candidate: native-gated public copy only; no App/bootstrap owner or child. */
const M4UICG_ENTRY='/run/ms4-prep/ms4-ui-copy.php';
const M4UICG_BINDING_KEYS=['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256','requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256','manifest','history','volumes_native','copy_profile','dist_sources'];
const M4UICG_ARTIFACTS=['compose.yml','interface-v1.json','ms4-host.py','ms4-initialize.php','normal-close-ms4-fulfillment.py','README.md','seed-source-inverse.json','source-derivation.json','verify-zero-ms4-fulfillment.py','ms4-ui-nginx.conf','ms4-ui-public-copy.php','ms4-ui-copy.php','ms4-order.png','manifests/ui-h5-dist.json','manifests/ui-merchant-dist.json','manifests/ui-admin-dist.json','manifests/ui-public-copy-profile.json'];
const M4UICG_SHADOW=['crmeb/public/index.php','crmeb/public/router.php','crmeb/public/install/crmeb.sql'];
const M4UICG_RESULT_KEYS=['schema','scope','status','run_id','source_sha','container_id','freeze_sha256','native_created_sha256','source_binding_sha256','phase','entry_return_code','termination','native_exit','native_exit_observation','full_source_unchanged','materials_candidate','diagnostic','App_HTTP_DOM','business_acceptance','full_acceptance','stopped_samples','assertions'];
const M4UICG_SHUTDOWN_KEYS=['schema','scope','run_id','source_sha','container_id','freeze_sha256','phase','completed','result_published','fatal','termination_requested','primary_failure','cleanup_failure','native_exit','native_exit_observation'];
function m4uicg_assert(bool $ok,string $code):void
{
    $GLOBALS['m4uicg_assertions']=($GLOBALS['m4uicg_assertions']??0)+1;
    if(!$ok){$GLOBALS['m4uicg_code']=$code;throw new RuntimeException('M4UICG_ASSERTION');}
}
function m4uicg_stat(array $stat):array
{
    $out=[];foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key){
        m4uicg_assert(isset($stat[$key])&&is_int($stat[$key]),'ACTUAL_STAT9');$out[$key]=$stat[$key];}
    return $out;
}
/** Stable complete read. Only fixed public paths or declared source descriptors call this. */
function m4uicg_file(string $path,int $maximum=67108864,bool $read=false):array
{
    clearstatcache(true,$path);$before=@lstat($path);
    m4uicg_assert(is_array($before)&&!is_link($path)&&realpath($path)===$path
        &&($before['mode']&0170000)===0100000&&$before['size']>=0&&$before['size']<=$maximum,'REGULAR_COMPLETE_FILE');
    $before=m4uicg_stat($before);$stream=null;$raw='';$extra=null;
    try{
        $stream=@fopen($path,'rb');m4uicg_assert(is_resource($stream),'READ_OPEN');
        $open=@fstat($stream);m4uicg_assert(is_array($open)&&m4uicg_stat($open)===$before,'SAME_READ_HANDLE');
        $hash=hash_init('sha256');$bytes=0;
        while(!feof($stream)){$chunk=fread($stream,65536);m4uicg_assert(is_string($chunk),'COMPLETE_READ');
            if($chunk===''){m4uicg_assert(feof($stream),'REAL_EOF');break;}
            $bytes+=strlen($chunk);m4uicg_assert($bytes<=$maximum&&$bytes<=$before['size'],'READ_BOUNDED');
            hash_update($hash,$chunk);if($read)$raw.=$chunk;unset($chunk);}
        $extra=fgetc($stream);$end=fstat($stream);
        m4uicg_assert($extra===false&&feof($stream)&&$bytes===$before['size']&&is_array($end)
            &&m4uicg_stat($end)===$before,'READ_EOF_STAT');
        $closing=$stream;$stream=null;m4uicg_assert(fclose($closing),'NATIVE_FILE_CLOSE');unset($closing);
        clearstatcache(true,$path);$after=lstat($path);
        m4uicg_assert(is_array($after)&&!is_link($path)&&realpath($path)===$path&&m4uicg_stat($after)===$before,'READ_PATH_STABLE');
        return ['descriptor'=>['path'=>$path,'bytes'=>$bytes,'sha256'=>hash_final($hash)],'raw'=>$read?$raw:null,'stat'=>$before];
    }finally{
        if(is_resource($stream))fclose($stream);
        // Public/source raw only. Private credential bytes are handled solely by the unchanged metadata helper.
        unset($raw,$extra);
    }
}

function m4uicg_bootstrap():void
{
    $relative='help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php';
    foreach(['/source','/workspace']as$root){$file=m4uicg_file($root.'/'.$relative);
        m4uicg_assert($file['descriptor']['bytes']===132938
            &&$file['descriptor']['sha256']==='a517ca25cfe2d86d89e0630b35398a56fa07c67f92c0ef54ed3bf265bee994b0','PINNED_DECLARATIONS_ONLY_HELPER');}
    require_once '/workspace/'.$relative;
    m4uib_bootstrap(); // Original C3/harness/guard/lib dispatch only for their genuine own filename.
}
function m4uicg_audit_environment(array $source=[]):array
{
    m4uicg_assert(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&getmypid()===1&&posix_geteuid()===0&&posix_getegid()===0&&getcwd()==='/workspace'
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===M4UICG_ENTRY
        &&($_SERVER['argv']??null)===[M4UICG_ENTRY,'copy'],'ACTUAL_COPY_PROCESS_AND_ARGV');
    $values=[];foreach(['AUDIT_ISOLATED'=>'1','AUDIT_GUARD_MODE'=>'read-only','AUDIT_REPO_DIR'=>'/workspace',
        'AUDIT_RUN_DIR'=>'/run/audit','MS4_UI_COPY_PROOF_NAME'=>'ui-public-copy']as$key=>$value){
        m4uicg_assert(getenv($key)===$value,'OWN_AUDIT_ENVIRONMENT');$values[$key]=$value;}
    foreach(['AUDIT_RUN_ID','AUDIT_SOURCE_SHA','AUDIT_PROJECT']as$key){
        $value=getenv($key);m4uicg_assert(is_string($value)&&$value!=='','OWN_AUDIT_IDENTITY_ENVIRONMENT');$values[$key]=$value;}
    if($source!==[])m4uicg_assert($values['AUDIT_RUN_ID']===$source['run_id']
        &&$values['AUDIT_SOURCE_SHA']===$source['source_sha']&&$values['AUDIT_PROJECT']===$source['context']['project'],'SAME_AUDIT_PROCESS_BINDING');
    return $values;
}
function m4uicg_full_source(array $source,bool $after):array
{
    $freeze=$source['freeze'];$ar=$freeze['aftersale_review_source_extra'];$prepare=$freeze['ui_prepare_source_extra'];
    $wanted=array_values(array_unique(array_merge($ar,[
        'help/audit-regressions/isolated/ms4-ui-prepare.php','help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
        'help/audit-regressions/isolated/lib/ms4-ui-credentials.php','help/migrations/marketplace-ms4-order-read-menus.php']),SORT_STRING));
    sort($wanted,SORT_STRING);
    m4uib_assert(count($ar)===30&&count(array_unique($ar,SORT_STRING))===30&&count($wanted)===34
        &&$prepare===$wanted&&$freeze['ui_prepare_source_roots']===M4UIB_ROOTS,'EXACT_PREPARE34_FROM_REAL_AR30');
    $extra=array_values(array_unique(array_merge($prepare,[M4UIB_ENTRY,M4UIB_HELPER,M4UIB_META]),SORT_STRING));sort($extra,SORT_STRING);
    m4uib_assert(count($extra)===37&&$freeze['ui_backend_source_roots']===M4UIB_ROOTS
        &&$freeze['ui_backend_source_extra']===$extra&&$source['source_files']===$freeze['ui_backend_source_files'],'EXACT_BACKEND37_SOURCE_GROUP');
    $rows=$source['source_files'];m4uib_assert(is_array($rows)&&array_is_list($rows),'COMPLETE_MAIN_SOURCE_ROWS');$paths=[];$allowed=[];$snapshot=[];
    foreach($rows as$row){m4uib_keys($row,['path','bytes','sha256']);m4uib_relative($row['path']);
        m4uib_assert(is_int($row['bytes'])&&$row['bytes']>=0&&m4uib_hex($row['sha256']),'COMPLETE_MAIN_ROW');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);m4uib_assert($paths===$sorted&&count(array_unique($paths,SORT_STRING))===count($paths),'SORTED_UNIQUE_MAIN_ROWS');
    foreach(M4UICG_SHADOW as$path)m4uib_assert(in_array($path,$paths,true),'ALL_THREE_SHADOW_ROWS_RETAINED');
    foreach(['/source','/workspace']as$root){
        m4uib_assert(m4uib_inventory($root,$extra)===$paths,'WHOLE_FOUR_ROOTS_PLUS36_INVENTORY');
        foreach($rows as$row){$path=$root.'/'.$row['path'];$shadow=$root==='/workspace'&&in_array($row['path'],M4UICG_SHADOW,true);
            if($shadow&&!$after){clearstatcache(true,$path);m4uib_assert(@lstat($path)===false,'ONLY_THREE_REAL_WORKSPACE_PUBLIC_ABSENCES');continue;}
            $actual=m4uib_file($path,max(67108864,$row['bytes']));
            m4uib_assert($actual['descriptor']['bytes']===$row['bytes']&&$actual['descriptor']['sha256']===$row['sha256']
                &&$actual['stat']['nlink']===1,'ALL_COMPLETE_SOURCE_BYTES_NO_LINK');
            if($shadow)m4uib_assert(($actual['stat']['mode']&07777)===0644&&$actual['stat']['uid']===posix_geteuid(),'THREE_COPIED_PUBLIC_FILES_REGULAR0644');
            else $snapshot[$path]=$actual;
            $allowed[$path]=true;
        }
    }
    m4uib_assert(is_array($freeze['artifact_files'])&&array_is_list($freeze['artifact_files'])
        &&array_column($freeze['artifact_files'],'path')===M4UICG_ARTIFACTS,'EXACT_ORDERED_FULL17_P_ARTIFACTS');
    foreach($freeze['artifact_files']as$row){m4uib_keys($row,['path','bytes','sha256']);m4uib_relative($row['path']);
        m4uib_assert(is_int($row['bytes'])&&$row['bytes']>0&&m4uib_hex($row['sha256']),'ARTIFACT_FULL_ROW');
        $path='/run/ms4-prep/'.$row['path'];$actual=m4uib_file($path,max(67108864,$row['bytes']));
        m4uib_assert($actual['descriptor']['bytes']===$row['bytes']&&$actual['descriptor']['sha256']===$row['sha256']
            &&$actual['stat']['nlink']===1,'FULL_ACTUAL_FROZEN_P_ARTIFACT');
        $allowed[$path]=true;$snapshot[$path]=$actual;
    }
    foreach(get_included_files()as$path)m4uib_assert(isset($allowed[realpath($path)]),'INCLUDES_ONLY_FULL_MAIN_OR_ACTUAL_P17');
    foreach(['/source/crmeb/.env','/workspace/crmeb/.env','/source/crmeb/public/install.lock']as$path)
        m4uib_assert(@lstat($path)===false,'NO_MUTABLE_HOST_ENV_OR_INSTALL_MARKER');
    return $snapshot;
}
function m4uicg_historical_environment(array $wrapper,array $native,string $cid,array $source):array
{
    m4uib_keys($wrapper,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4uib_assert($wrapper['schema']===1&&$wrapper['scope']==='MS4_UI_PREPARE_REAL_ENVIRONMENT'
        &&$wrapper['run_id']===$source['run_id']&&$wrapper['source_sha']===$source['source_sha']
        &&$wrapper['container_id']===$cid&&$wrapper['freeze_sha256']===$source['freeze_sha256'],'REAL_ARCHIVED_PREPARE_ENV_IDENTITY');
    $values=$wrapper['environment'];m4uib_keys($values,M4UIB_ENV_KEYS);
    foreach(['APP_ENV'=>'testing','PHP_CACHE_DRIVER'=>'redis','PHP_MARKETPLACE_AFTERSALE_ENABLED'=>'true',
        'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT'=>'/var/lib/crmeb-aftersale-evidence',
        'PHP_MARKETPLACE_CUSTOMER_FORM_PRIVATE_ROOT'=>'/run/audit-customer-form','PHP_MARKETPLACE_ENABLED'=>'true',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED'=>'true','PHP_MARKETPLACE_TRADE_ENABLED'=>'true']as$key=>$value)
        m4uib_assert($values[$key]===$value,'ARCHIVED_EXACT_NINE_VALUES');
    $self=$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'];
    m4uib_assert(is_string($self)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$self)===1&&(int)$self<=4294967295,'ARCHIVED_ACTUAL_SELF_ID');
    $lines=[];foreach(M4UIB_ENV_KEYS as$key){m4uib_assert(is_string($values[$key]),'ARCHIVED_NATIVE_ENV_STRING');
        $lines[]=json_encode($key.'='.$values[$key],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    $raw=implode("\n",$lines)."\n";
    m4uib_keys($native,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
    m4uib_assert($native['stage']==='ui-prepare-public-environment-inspect'&&$native['native_exit']===0
        &&$native['stdout_bytes']===strlen($raw)&&$native['stdout_sha256']===hash('sha256',$raw)
        &&$native['stderr_bytes']===0&&$native['stderr_sha256']===hash('sha256','')
        &&$native['private_environment_logs_omitted']===true&&$wrapper['native_projection_sha256']===$native['stdout_sha256'],'ARCHIVED_REAL_NATIVE_NINE_ENV_BYTES');
    ksort($values,SORT_STRING);return $values;
}
function m4uicg_mounts(array $source,array $native,bool $current):void
{
    $context=$source['context'];$network=$context['initial_network'];$name=$network['Name'];
    m4uib_assert($network['Id']===$context['network_id']&&$network['Internal']===true&&$native['network_mode']===$name
        &&array_keys($native['networks'])===[$name]
        &&($current?$native['networks'][$name]['NetworkID']===$network['Id']:in_array($native['networks'][$name]['NetworkID'],['',$network['Id']],true)),'ONLY_ORIGINAL_INTERNAL_COPY_NETWORK');
    $aliases=$native['networks'][$name]['Aliases']??[];
    foreach(['audit-app','audit-ms4-ui-backend','audit-ms4-ui-gateway','audit-mysql','audit-redis']as$alias)
        m4uib_assert(!in_array($alias,$aliases,true),'NO_BACKEND_OR_SECRET_ALIAS');
    $binds=['/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true]];
    m4uib_keys($source['dist_sources'],['h5','merchant','admin']);
    foreach(['h5','merchant','admin']as$part){$path=$source['dist_sources'][$part];
        m4uib_assert(is_string($path)&&preg_match('~\A(?:[A-Za-z]:/|/)~D',$path)===1
            &&!str_contains($path,"\0"),'ACTUAL_FROZEN_ABSOLUTE_BUILD_SOURCE');$binds['/run/ms4-ui-dist/'.$part]=[$path,false];}
    $volumes=['/workspace'=>['source-tree',false],'/source'=>['source-tree',false],
        '/workspace/crmeb/public'=>['ui-public',true],'/workspace/crmeb/public/uploads/store/comment'=>['ui-comments',true],
        '/workspace/crmeb/public/uploads/customer'=>['ui-customer-assets',true]];
    $mounts=[];$tmp=[];m4uib_assert(is_array($native['mounts'])&&array_is_list($native['mounts']),'FULL_NATIVE_MOUNT_LIST');
    foreach($native['mounts']as$mount){m4uib_assert(is_array($mount),'NATIVE_MOUNT_OBJECT');
        if($mount['Type']==='tmpfs'){m4uib_assert($mount['Destination']==='/tmp'&&$mount['RW']===true&&!isset($tmp['/tmp']),'ONLY_PRIVATE_TMPFS_MOUNT');$tmp['/tmp']=$mount;}
        else $mounts[]=$mount;}
    m4uib_assert(count($mounts)===10,'EXACT_FIVE_BINDS_FIVE_VOLUMES');
    foreach(array_merge($binds,$volumes)as$target=>$expected){
        $matches=array_values(array_filter($mounts,static fn(array $row):bool=>$row['Destination']===$target));
        m4uib_assert(count($matches)===1&&$matches[0]['RW']===$expected[1],'ONE_EXACT_COPY_MOUNT_AND_ACCESS');$mount=$matches[0];
        if(isset($binds[$target]))m4uib_assert($mount['Type']==='bind'
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($expected[0]),'REAL_CANONICAL_FROZEN_BIND');
        else m4uib_assert($mount['Type']==='volume'&&$mount['Name']===$context['project'].'_'.$expected[0],'REAL_EXACT_SOURCE_AND_OUTPUT_VOLUMES');
    }
    m4uib_keys($native['tmpfs'],['/tmp']);$options=explode(',',$native['tmpfs']['/tmp']);
    m4uib_assert(in_array('rw',$options,true)&&in_array('mode=0700',$options,true),'EXACT_PRIVATE_TMPFS_RW0700_CONFIG');
    $wanted=[];foreach($binds as$target=>$expected)$wanted[$target]=$expected[1];
    foreach($volumes as$target=>$expected)$wanted[$target]=$expected[1];$wanted['/tmp']=true;$observed=[];
    $lines=file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES);m4uib_assert(is_array($lines)&&$lines!==[],'ACTUAL_COPY_KERNEL_MOUNTINFO');
    foreach($lines as$line){$halves=explode(' - ',$line,2);$left=explode(' ',$halves[0]);$right=explode(' ',$halves[1]??'');$target=$left[4]??'';
        if(!array_key_exists($target,$wanted))continue;
        m4uib_assert(!isset($observed[$target])&&in_array($wanted[$target]?'rw':'ro',explode(',',$left[5]??''),true),'KERNEL_EXACT_COPY_MOUNT_ACCESS');
        if($target==='/tmp')m4uib_assert(($right[0]??'')==='tmpfs','KERNEL_PRIVATE_TMPFS');$observed[$target]=true;}
    $keys=array_keys($wanted);$actual=array_keys($observed);sort($keys,SORT_STRING);sort($actual,SORT_STRING);
    m4uib_assert($keys===$actual,'ALL_COPY_MOUNTS_OBSERVED_IN_KERNEL');m4uib_dir('/tmp');
}
function m4uicg_source():array
{
    m4uicg_audit_environment();m4uicg_bootstrap();$base='/run/audit/evidence/';
    $context=m4uib_json('/run/audit/actual-run-context.json');
    $freeze=audit_read_immutable_source_freeze(67108864,static function(bool $ok,string $code):void{m4uib_assert($ok,'COPY_FREEZE_'.$code);});
    $hash=m4uib_hash('/run/audit/preparation-source-binding.json');$run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');
    m4uib_assert(is_string($run)&&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',$run)===1&&m4uib_hex($sha,40)
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$context['freeze_sha256']===$hash
        &&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY','SAME_REAL_RUN_SOURCE_FREEZE');
    $created=m4uib_json($base.'ui-public-copy-created.json');m4uib_keys($created,array_merge(M4UIB_NATIVE_KEYS,['run_id','source_sha','proof_name']));
    $cid=$created['id'];m4uib_assert(m4uib_hex($cid)&&gethostname()===substr($cid,0,12),'ACTUAL_OWN_COPY_CID');
    $binding=m4uib_json($base.'ms4-ui-public-copy-source-binding.json');m4uib_keys($binding,M4UICG_BINDING_KEYS);
    m4uib_assert($binding['schema']===1&&$binding['scope']==='MS4_UI_PUBLIC_COPY_SOURCE'&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$hash
        &&$binding['native_created_sha256']===m4uib_hash($base.'ui-public-copy-created.json')
        &&$binding['entry_sha256']===m4uib_hash(M4UICG_ENTRY),'OWN_COPY_CREATED_AND_SOURCE_BINDING');
    foreach(['requested_repo_source'=>'repo_dir','requested_run_source'=>'run_dir','requested_prep_source'=>'prep_dir']as$key=>$ctx)
        m4uib_assert($binding[$key]===$context[$ctx],'ACTUAL_ORIGINAL_REQUESTED_PATHS');
    m4uib_assert($created['run_id']===$run&&$created['source_sha']===$sha&&$created['proof_name']==='ui-public-copy'
        &&$created['image']===M4UIB_IMAGE&&$created['hostname']===gethostname()&&$created['user']==='0:0'
        &&$created['stop_signal']==='SIGTERM'&&$created['read_only']===true&&$created['privileged']===false
        &&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4UICG_ENTRY,'copy']&&$created['workdir']==='/workspace'
        &&$created['cap_drop']===['ALL']&&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'REAL_CREATED_COPY_ROLE_NATIVE');
    foreach($created['ports']??[]as$mapping)m4uib_assert(in_array($mapping,[null,[]],true),'NO_COPY_HOST_PORT');
    foreach(['tianli.audit'=>'1','tianli.audit.run'=>$run,'com.docker.compose.project'=>$context['project'],
        'com.docker.compose.service'=>'audit-ms4-ui-public-copy']as$key=>$value)m4uib_assert($created['labels'][$key]===$value,'OWN_COPY_NATIVE_LABELS');
    $formal=m4uib_descriptor($binding['manifest'],'/run/audit/manifest.json');
    m4uib_assert($formal['run_id']===$run&&$formal['commit_sha']===$sha&&$formal['project']===$context['project']
        &&$formal['isolation']==='ISO-FULL'&&$formal['guard_mode']==='read-only'&&$formal['network_internal']===true
        &&$formal['network']['id']===$context['network_id']&&$formal['network']['name']===$context['initial_network']['Name'],'ACTUAL_COPY_FORMAL_INTERNAL_NETWORK');
    $roles=array_keys($formal['services']);sort($roles,SORT_STRING);
    m4uib_assert($roles===['audit-ms4-ui-public-copy','audit-ms5-paid-producer','audit-mysql','audit-redis']
        &&$formal['services']['audit-ms4-ui-public-copy']['id']===$cid
        &&$formal['services']['audit-ms4-ui-public-copy']['image_id']===M4UIB_IMAGE,'EXACT_CURRENT_FOUR_ROLES');
    $source=array_replace($binding,['context'=>$context,'freeze'=>$freeze,'formal'=>$formal,'created'=>$created,
        'source_binding_sha256'=>m4uib_hash($base.'ms4-ui-public-copy-source-binding.json')]);
    m4uib_assert(m4uib_same($binding['dist_sources'],$freeze['ui_copy_dist_sources']),'FROZEN_REAL_THREE_BUILD_HOST_SOURCES');
    $source['copy_profile_value']=m4uib_descriptor($binding['copy_profile'],'/run/ms4-prep/manifests/ui-public-copy-profile.json');
    m4uib_assert(m4uib_same(m4uib_json('/run/audit/network.json'),$context['initial_network']),'ORIGINAL_NETWORK_FULL_OBJECT');
    $source['source_snapshot']=m4uicg_full_source($source,false);m4uicg_mounts($source,$created,false);m4uib_volumes($source);
    $path=$base.'ui-public-copy-current.json';$until=microtime(true)+10.0;
    while(!file_exists($path)){m4uib_assert(empty($GLOBALS['m4uicg_terminate_requested']),'COPY_PROOF_WAIT_NOT_INTERRUPTED');
        m4uib_assert(microtime(true)<$until,'COPY_CURRENT_NATIVE_PROOF_NOT_YET_PUBLISHED');usleep(10000);}
    $current=m4uib_json($path);m4uib_keys($current,M4UIB_CURRENT_KEYS);
    m4uib_assert($current['schema']===1&&$current['scope']==='MS4_UI_PUBLIC_COPY_CURRENT_NATIVE'
        &&$current['run_id']===$run&&$current['source_sha']===$sha&&$current['freeze_sha256']===$hash
        &&$current['container_id']===$cid&&$current['native_created_sha256']===$source['native_created_sha256']
        &&$current['source_binding_sha256']===$source['source_binding_sha256']
        &&$current['projection']['path']===$base.'ui-public-copy-current.stdout','REAL_COPY_POSTSTART_NATIVE_PROOF');
    $raw=m4uib_native($current['native'],$current['projection'],['docker','inspect','--type','container','--format',M4UIB_INSPECT_FORMAT,$cid],'ui-public-copy-current');
    $actual=json_decode($raw,true,64,JSON_THROW_ON_ERROR);unset($raw);m4uib_keys($actual,M4UIB_NATIVE_KEYS);m4uib_same_created($created,$actual);
    m4uib_assert($actual['status']==='running'&&is_int($actual['pid'])&&$actual['pid']>0&&$actual['restart_count']===0,'ACTUAL_SAME_CID_RUNNING_COPY');
    m4uicg_mounts($source,$actual,true);$source['current']=$current;
    $source['prepare_history']=m4uicg_history($source);
    m4uib_source_tree($source);
    $source['audit_environment']=m4uicg_audit_environment($source);
    return $source;
}
function m4uicg_history(array $source):array
{
    $base='/run/audit/evidence/';$seal=m4uib_descriptor($source['history'],$base.'ui-prepare-predecessor-history.json');
    m4uib_keys($seal,M4UIB_SEAL_KEYS);
    m4uib_assert($seal['schema']===1&&$seal['scope']==='MS4_UI_PREPARE_PREDECESSOR_HISTORY'
        &&$seal['run_id']===$source['run_id']&&$seal['source_sha']===$source['source_sha']
        &&$seal['freeze_sha256']===$source['freeze_sha256']&&m4uib_hex($seal['prepare_container_id'])
        &&$seal['prepare_container_id']!==$source['container_id'],'REAL_PREPARE_HISTORY_IDENTITY');
    $context=m4uib_descriptor($seal['prepare_context'],$base.'ui-prepare-context.json');
    $formal=m4uib_descriptor($seal['original_formal_manifest'],$base.'ui-prepare-formal-manifest.json');
    m4uib_assert(m4uib_hash('/run/audit/actual-run-context.json')===$seal['prepare_context']['sha256']
        &&m4uib_same($context,$source['context']),'ORIGINAL_PREPARE_CONTEXT_BYTES_UNCHANGED');
    foreach(['run_id'=>'run_id','commit_sha'=>'source_sha','project'=>'project']as$left=>$right)
        m4uib_assert($formal[$left]===$context[$right],'ORIGINAL_PREPARE_FORMAL_IDENTITY');
    m4uib_assert($formal['isolation']==='ISO-FULL'&&$formal['guard_mode']==='read-only'
        &&$formal['network_internal']===true,'ORIGINAL_PREPARE_FORMAL_ISOLATION');
    $roles=array_keys($formal['services']);sort($roles,SORT_STRING);
    m4uib_assert($roles===['audit-ms4-ui-prepare','audit-ms5-paid-producer','audit-mysql','audit-redis']
        &&$formal['services']['audit-ms4-ui-prepare']['id']===$seal['prepare_container_id'],'ACTUAL_FOUR_ROLE_PREPARE_FORMAL');
    $originalNames=[];foreach(M4UIB_ORIGINAL_VOLUMES as$suffix)$originalNames[]=$context['project'].'_'.$suffix;sort($originalNames,SORT_STRING);
    $volumes=$formal['volumes'];sort($volumes,SORT_STRING);
    $identityNames=array_keys($formal['volume_identities']);sort($identityNames,SORT_STRING);
    m4uib_assert($volumes===$originalNames&&$identityNames===$originalNames,'ORIGINAL_NINE_PREPARE_VOLUMES');
    m4uib_assert(is_array($context['initial_volumes'])&&array_is_list($context['initial_volumes'])
        &&count($context['initial_volumes'])===9,'ORIGINAL_CONTEXT_NINE_COMPLETE_IDENTITIES');
    $original=[];
    foreach($context['initial_volumes']as$row){
        m4uib_assert(is_array($row),'ORIGINAL_CONTEXT_VOLUME_OBJECT');m4uib_keys($row,['name','created_at','labels']);
        m4uib_assert(is_string($row['name'])&&in_array($row['name'],$originalNames,true)&&!isset($original[$row['name']])
            &&is_string($row['created_at'])&&$row['created_at']!==''&&is_array($row['labels']),'UNIQUE_COMPLETE_ORIGINAL_CONTEXT_VOLUME');
        $original[$row['name']]=$row;
        m4uib_assert($formal['volume_identities'][$row['name']]===$row['created_at']
            &&$source['formal']['volume_identities'][$row['name']]===$row['created_at'],'ORIGINAL_PREPARE_VOLUME_NATIVE_IDENTITIES');
    }
    $originalMapNames=array_keys($original);sort($originalMapNames,SORT_STRING);
    m4uib_assert($originalMapNames===$originalNames,'COMPLETE_ORIGINAL_CONTEXT_VOLUME_MAP');
    m4uib_keys($seal['fixture_volume'],['name','created_at','labels']);
    $fixture=$context['project'].'_fixture-secrets';
    m4uib_assert($seal['fixture_volume']['name']===$fixture&&m4uib_same($seal['fixture_volume'],$original[$fixture])
        &&$seal['fixture_volume']['created_at']===$formal['volume_identities'][$fixture]
        &&$seal['fixture_volume']['created_at']===$source['formal']['volume_identities'][$fixture],'SAME_ACTUAL_READONLY_FIXTURE_VOLUME');
    foreach(['audit-ms5-paid-producer','audit-mysql','audit-redis']as$role)
        m4uib_assert(m4uib_same($formal['services'][$role],$source['formal']['services'][$role]),'ORIGINAL_PAID_DATABASE_NATIVE_ROLES_UNCHANGED');

    $paidNames=m4uib_paid_names();$paths=[];$all=[];
    m4uib_assert(is_array($seal['paid_predecessor_files'])&&array_is_list($seal['paid_predecessor_files'])
        &&count($seal['paid_predecessor_files'])===39,'REAL_PAID39_DESCRIPTOR_LIST');
    foreach($seal['paid_predecessor_files']as$index=>$descriptor){
        $name=$paidNames[$index];$path=$base.$name;
        m4uib_descriptor($descriptor,$path);$paths[]=$path;$all[$name]=$descriptor['sha256'];}
    m4uib_assert(count(array_unique($paths,SORT_STRING))===39,'PAID39_EXACT_ORDER_AND_UNIQUENESS');
    $task2Present=array_key_exists('task2_observer_source',$source['freeze']);$prepareNames=M4UIB_PREPARE_NAMES;
    if($task2Present)$prepareNames[]='ms5-task2-target-preparation.json';
    m4uib_assert(is_array($seal['prepare_public_files'])&&array_is_list($seal['prepare_public_files'])
        &&count($seal['prepare_public_files'])===($task2Present?8:7),'REAL_EXACT_PREPARE_DESCRIPTOR_LIST');
    foreach($prepareNames as$index=>$name){
        $descriptor=$seal['prepare_public_files'][$index];m4uib_descriptor($descriptor,$base.$name);$all[$name]=$descriptor['sha256'];}
    $all['ui-prepare-context.json']=$seal['prepare_context']['sha256'];
    $all['ui-prepare-formal-manifest.json']=$seal['original_formal_manifest']['sha256'];
    $all['ui-prepare-predecessor-history.json']=$source['history']['sha256'];
    m4uib_assert(count($all)===($task2Present?50:49)&&m4uib_same($all,$source['prerequisites_sha256']),'OWN_EXACT_ACTUAL_FULL_PREREQUISITE_HASHES');

    $paidFormal=m4uib_json($base.'paid-producer-formal-manifest.json');
    $paidHistory=m4uib_json($base.'paid-producer-predecessor-history.json');
    m4uib_keys($paidHistory,['schema','run_id','source_sha','freeze_sha256','context_sha256','manifest_sha256','public_sha256']);
    m4uib_assert($paidHistory['schema']===1&&$paidHistory['run_id']===$source['run_id']
        &&$paidHistory['source_sha']===$source['source_sha']&&$paidHistory['freeze_sha256']===$source['freeze_sha256']
        &&$paidHistory['context_sha256']===$seal['prepare_context']['sha256']
        &&$paidHistory['manifest_sha256']===$all['paid-producer-formal-manifest.json'],'ORIGINAL_PAID_HISTORY_AND_FORMAL_BYTES');
    $history33=$paidNames;
    foreach(['ms4-fulfillment-migration-container-created.json','ms4-fulfillment-migration-source-binding.json',
        'ms4-aftersale-early-migration-normal.json','ms4-fulfillment-migration-terminal.json',
        'paid-producer-formal-manifest.json','paid-producer-predecessor-history.json']as$name)
        $history33=array_values(array_diff($history33,[$name]));
    m4uib_keys($paidHistory['public_sha256'],$history33);
    foreach($history33 as$name)m4uib_assert($paidHistory['public_sha256'][$name]===$all[$name],'ALL_ORIGINAL_PAID33_BYTES');
    $paidRoles=array_keys($paidFormal['services']);sort($paidRoles,SORT_STRING);
    m4uib_assert($paidRoles===['audit-ms5-paid-producer','audit-mysql','audit-redis'],'ORIGINAL_PAID_THREE_FORMAL_ROLES');
    foreach(['run_id','commit_sha','project','isolation','guard_mode','network_internal','network','volumes','volume_identities']as$key)
        m4uib_assert(m4uib_same($paidFormal[$key],$formal[$key]),'ORIGINAL_PAID_PREPARE_FORMAL_UNCHANGED_FIELDS');
    foreach($paidRoles as$role)m4uib_assert(m4uib_same($paidFormal['services'][$role],$formal['services'][$role]),'ORIGINAL_PAID_PREPARE_SERVICE_IDENTITIES');
    $paidCreated=m4uib_terminal($source,'paid-producer','audit-ms5-paid-producer',
        ['help/audit-regressions/isolated/ms5-paid-consumer-normal.php','produce']);
    m4uib_assert($paidCreated['id']===$paidFormal['services']['audit-ms5-paid-producer']['id'],'ORIGINAL_SAME_PAID_NATIVE_ZERO');
    $paid=m4uib_json($base.'paid-producer-result.json');
    m4uib_assert($paid['schema']===1&&$paid['run_id']===$source['run_id']&&$paid['source_sha']===$source['source_sha']
        &&$paid['container_id']===$paidCreated['id']&&$paid['freeze_sha256']===$source['freeze_sha256']
        &&$paid['native_created_sha256']===$all['paid-producer-created.json']
        &&$paid['source_binding_sha256']===$all['paid-producer-source-binding.json']
        &&$paid['environment_sha256']===$all['paid-producer-environment.json']
        &&$paid['environment_native_sha256']===$all['paid-producer-environment-native.json']
        &&$paid['phase']==='final_source'&&$paid['entry_return_code']===0&&$paid['termination']==='NORMAL_RETURN'
        &&$paid['native_exit']===null&&$paid['native_exit_observation']==='HOST_NATIVE_REQUIRED'
        &&$paid['full_source_unchanged']===true,'ORIGINAL_PAID_RESULT_JOINT_NATIVE_ZERO');

    $created=m4uib_terminal($source,'ui-prepare','audit-ms4-ui-prepare',
        ['help/audit-regressions/isolated/ms4-ui-prepare.php','prepare']);
    m4uib_assert($created['id']===$seal['prepare_container_id']&&$created['id']!==$paidCreated['id']
        &&$created['id']!==$source['container_id'],'REAL_DISTINCT_SAME_PREPARE_NATIVE_ZERO');
    $binding=m4uib_json($base.'ms4-ui-prepare-source-binding.json');
    m4uib_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
    m4uib_assert($binding['schema']===1&&$binding['scope']==='MS4_UI_PREPARE_SOURCE'
        &&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']
        &&$binding['container_id']===$created['id']&&$binding['native_created_sha256']===$all['ui-prepare-created.json']
        &&$binding['freeze_sha256']===$source['freeze_sha256']
        &&$binding['source_files']===$source['freeze']['ui_prepare_source_files']
        &&$binding['entry_sha256']===m4uib_hash('/workspace/help/audit-regressions/isolated/ms4-ui-prepare.php'),'REAL_COMPLETE_PREPARE_SOURCE_BINDING');
    foreach(['requested_repo_source'=>'repo_dir','requested_run_source'=>'run_dir','requested_prep_source'=>'prep_dir']as$key=>$ctx)
        m4uib_assert($binding[$key]===$context[$ctx],'PREPARE_SAME_ACTUAL_SOURCE_PATHS');
    $expected=[];foreach($paidNames as$name)$expected[$name]=$all[$name];
    m4uib_assert(m4uib_same($binding['prerequisites_sha256'],$expected),'ORIGINAL_PREPARE_EXACT39_INPUTS');
    $env=m4uicg_historical_environment(m4uib_json($base.'ui-prepare-environment.json'),
        m4uib_json($base.'ui-prepare-environment-native.json'),$created['id'],$source);
    m4uib_assert(count($env)===9,'REAL_ARCHIVED_PREPARE_NINE_ENVIRONMENT');

    $prepared=m4uib_json($base.'ms4-ui-prepare.json');m4uib_keys($prepared,M4UIB_PREPARE_KEYS);
    m4uib_assert($prepared['schema']===1&&$prepared['scope']==='NORMAL_MS4_UI_PREPARATION_ONLY'
        &&$prepared['status']==='VERIFIED_NORMAL_MS4_UI_PREPARATION_ONLY'&&$prepared['run_id']===$source['run_id']
        &&$prepared['source_sha']===$source['source_sha']&&$prepared['container_id']===$created['id']
        &&$prepared['freeze_sha256']===$source['freeze_sha256']&&$prepared['native_created_sha256']===$all['ui-prepare-created.json']
        &&$prepared['source_binding_sha256']===$all['ms4-ui-prepare-source-binding.json']
        &&$prepared['environment_sha256']===$all['ui-prepare-environment.json']
        &&$prepared['environment_native_sha256']===$all['ui-prepare-environment-native.json']
        &&$prepared['phase']==='final_source'&&$prepared['entry_return_code']===0&&$prepared['termination']==='NORMAL_RETURN'
        &&$prepared['native_exit']===null&&$prepared['native_exit_observation']==='HOST_NATIVE_REQUIRED'
        &&$prepared['full_source_unchanged']===true&&$prepared['diagnostic']===null
        &&$prepared['three_end_login_DOM']==='NOT RUN'&&$prepared['N1_fulfillment_review_business']==='NOT RUN'
        &&$prepared['full_acceptance']==='INCOMPLETE'&&$prepared['stopped_samples']==='NOT RUN'
        &&is_int($prepared['assertions'])&&$prepared['assertions']>0,'ACTUAL_COMPLETE_PREPARE_RESULT_NOT_EXPECTED_DEFAULT');
    $business=$prepared['business'];m4uib_keys($business,M4UIB_BUSINESS_KEYS);
    m4uib_assert($business['prepared_only']===true&&$business['independent_full_window_verified']===true
        &&$business['post_child_close_verified']===true&&is_array($business['observer'])
        &&$business['observer']!==[]&&m4uib_hex($business['source_fingerprint']),'ACTUAL_COMPLETE_PREPARE_WINDOW');
    m4uib_positive($business['paid_order_id']);m4uib_positive($business['merchant_id'],4294967295);m4uib_positive($business['customer_id']);
    m4uib_assert(is_string($business['paid_order_no'])&&$business['paid_order_no']!==''&&strlen($business['paid_order_no'])<=191,'ACTUAL_PAID_ORDER_NO');
    m4uib_keys($business['actor_ids'],['customer','merchant','platform']);
    foreach($business['actor_ids']as$id)m4uib_positive($id);
    m4uib_assert($business['actor_ids']['customer']===$business['customer_id'],'ACTUAL_CUSTOMER_IDENTITY');
    $items=$business['item_quantities'];m4uib_assert(is_array($items)&&array_is_list($items)&&count($items)===2,'ACTUAL_TWO_PAID_ITEMS');
    foreach($items as$row){m4uib_keys($row,['item_id','quantity']);m4uib_positive($row['item_id']);}
    m4uib_assert($items[0]['quantity']===2&&$items[1]['quantity']===1&&$items[0]['item_id']!==$items[1]['item_id'],'ORIGINAL_REAL_Q2_PLUS_Q1');
    foreach(['window_before','window_final']as$key){$window=$business[$key];
        m4uib_keys($window,['sha256','database_sha256','redis_sha256','files_sha256','table_count']);
        foreach(['sha256','database_sha256','redis_sha256','files_sha256']as$hash)m4uib_assert(m4uib_hex($window[$hash]),'COMPLETE_WINDOW_HASHES');
        m4uib_positive($window['table_count']);}
    m4uib_keys($business['menus'],['check','apply','postcheck','reapply']);
    foreach($business['menus']as$value)m4uib_assert(is_array($value)&&$value!==[],'ACTUAL_MIGRATION_RESULT_OBJECTS');

    $writer=$business['writer'];m4uib_keys($writer,M4UIB_WRITER_KEYS);
    m4uib_assert($writer['path']==='/run/audit-fixtures/ms4-ui/credentials.json'&&is_int($writer['bytes'])&&$writer['bytes']>0
        &&$writer['bytes']<=16384&&m4uib_hex($writer['sha256'])&&$writer['mode']==='0600'&&$writer['directory_mode']==='0700'
        &&$writer['nlink']===1&&$writer['uid']===posix_geteuid()&&is_int($writer['gid'])&&$writer['gid']>=0
        &&is_int($writer['dev'])&&$writer['dev']>0&&is_int($writer['ino'])&&$writer['ino']>0,'ACTUAL_PRIVATE_WRITER_DESCRIPTOR');
    // Public copy has no fixture mount. The genuine full native fixture identity and
    // sealed public writer descriptor stay bound; backend/private transport read bytes later.
    m4uib_assert(m4uib_same($seal['fixture_volume'],$original[$fixture]),'PUBLIC_HISTORY_FULL_ORIGINAL_FIXTURE_IDENTITY');

    $child=$prepared['child'];
    m4uib_assert(is_array($child)&&$child['stopped']===true&&$child['remaining_members']===[]&&$child['listener_open']===false
        &&is_array($child['group'])&&is_array($child['first_terminal'])&&$child['first_terminal']['running']===false
        &&$child['first_terminal']['stopped']===false&&$child['first_terminal']['stopsig']===0
        &&in_array($child['first_terminal']['exitcode'],[-1,0],true)&&in_array($child['first_terminal']['termsig'],[0,SIGTERM],true)
        &&in_array($child['proc_close_exit'],[-1,0],true)&&$child['term_attempted']===true&&$child['stop_signal']===SIGTERM
        &&$child['first_terminal']['pid']===$child['group']['leader_pid']&&$child['group']['leader_pid']>1
        &&$child['group']['pgid']===$child['group']['leader_pid']&&$child['group']['sid']===$child['group']['leader_pid']
        &&is_array($child['streams'])&&count($child['streams'])===2,'PREPARE_ORIGINAL_SAME_CHILD_NORMALLY_CLOSED');
    $shutdown=m4uib_json($base.'ui-prepare-shutdown.json');m4uib_keys($shutdown,M4UIB_PREPARE_SHUTDOWN_KEYS);
    m4uib_assert($shutdown['schema']===1&&$shutdown['scope']==='MS4_UI_PREPARE_SHUTDOWN'
        &&$shutdown['run_id']===$source['run_id']&&$shutdown['source_sha']===$source['source_sha']
        &&$shutdown['container_id']===$created['id']&&$shutdown['freeze_sha256']===$source['freeze_sha256']
        &&$shutdown['phase']==='final_source'&&$shutdown['completed']===true&&$shutdown['result_published']===true
        &&$shutdown['fatal']===false&&$shutdown['termination_requested']===false
        &&$shutdown['primary_failure']===null&&$shutdown['cleanup_failure']===null
        &&$shutdown['native_exit']===null&&$shutdown['native_exit_observation']==='HOST_NATIVE_REQUIRED','ACTUAL_NONFATAL_PREPARE_SHUTDOWN_AND_NATIVE_ZERO');
    $ids=$business['actor_ids'];$actorMap=['customer'=>['uid'=>$ids['customer']],
        'merchant'=>['id'=>$ids['merchant'],'mer_id'=>$business['merchant_id']],'platform'=>['id'=>$ids['platform']]];
    $verified=['seal'=>$seal,'prepare'=>$prepared,'business'=>$business,'writer'=>$writer,'actor_ids'=>$ids,'actor_map'=>$actorMap];
    if($task2Present)$verified['task2']=m4uib_task2_prepare($source,$prepared,$business,$all);
    return $verified;
}


function m4uicg_protected(array $source):array
{
    $base='/run/audit/evidence/';$files=[];
    foreach($source['prerequisites_sha256']as$name=>$hash){
        m4uib_assert(is_string($name)&&basename($name)===$name&&m4uib_hex($hash),'FIXED49_PUBLIC_NAME');
        $path=$base.$name;$value=m4uib_file($path);m4uib_assert($value['descriptor']['sha256']===$hash,'ALL49_REAL_BYTES');$files[$path]=$value;}
    if(array_key_exists('task2_observer_source',$source['freeze'])){
        $tools=m4uib_task2_source($source);
        foreach($tools['members']as$row){$path=$tools['container_root'].'/'.$row['path'];$files[$path]=m4uib_file($path);}
        $path='/run/audit/'.$source['context']['task2_observer_copy']['path'];$files[$path]=m4uib_file($path);
    }
    foreach(['/run/audit/actual-run-context.json','/run/audit/preparation-source-binding.json','/run/audit/manifest.json','/run/audit/network.json',
        $base.'ui-public-copy-created.json',$base.'ms4-ui-public-copy-source-binding.json',$base.'ui-public-copy-current.json',
        $source['current']['projection']['path'],$source['volumes_native']['path'],$source['copy_profile']['path']]as$path)$files[$path]=m4uib_file($path);
    $volumes=m4uib_descriptor($source['volumes_native'],$base.'ui-volume-native.json');
    foreach($volumes['volumes']as$row)$files[$row['projection']['path']]=m4uib_file($row['projection']['path']);
    ksort($files,SORT_STRING);return $files;
}
function m4uicg_preserved(array $source,array $protected,bool $after):void
{
    m4uib_assert(m4uib_same(m4uicg_protected($source),$protected),'ALL_INPUT_PROOFS_RAW_AND_NATIVE_STAT_UNCHANGED');
    m4uib_assert(m4uicg_audit_environment($source)===$source['audit_environment'],'AUDIT_ENVIRONMENT_UNCHANGED');
    m4uib_assert(m4uib_same(m4uicg_full_source($source,$after),$source['source_snapshot']),'ALL_ORIGINAL_SOURCE_AND_P17_UNCHANGED');
    m4uib_volumes($source);m4uicg_mounts($source,$source['created'],false);
}
function m4uicg_publish(string $name,array $value):array
{
    m4uib_assert(in_array($name,['ui-public-materials-produced.json','ui-public-copy-result.json','ui-public-copy-shutdown.json'],true),'ONLY_OWN_THREE_PUBLICATIONS');
    $path='/run/audit/evidence/'.$name;m4uib_assert(@lstat($path)===false&&umask()===0077,'EXCLUSIVE_FRESH_OUTPUT');
    $parent=lstat(dirname($path));m4uib_assert(is_array($parent)&&!is_link(dirname($path))&&realpath(dirname($path))===dirname($path)
        &&($parent['mode']&0170000)===0040000&&$parent['uid']===posix_geteuid(),'REAL_OWN_EVIDENCE_DIRECTORY');
    $raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";$stream=fopen($path,'xb');
    m4uib_assert(is_resource($stream),'EXCLUSIVE_PUBLIC_OUTPUT_OPEN');
    try{$offset=0;while($offset<strlen($raw)){$written=fwrite($stream,substr($raw,$offset));m4uib_assert(is_int($written)&&$written>0,'FULL_PUBLIC_WRITE');$offset+=$written;}
        m4uib_assert(fflush($stream),'PUBLIC_OUTPUT_FLUSH');$open=fstat($stream);clearstatcache(true,$path);$named=lstat($path);
        m4uib_assert(is_array($open)&&is_array($named)&&m4uib_stat($open)===m4uib_stat($named)
            &&($open['mode']&07777)===0600&&$open['nlink']===1&&$open['uid']===posix_geteuid(),'OWN_SINGLE_REGULAR0600_OUTPUT');}
    finally{m4uib_assert(fclose($stream),'PUBLIC_OUTPUT_NATIVE_CLOSE');}
    $actual=m4uib_file($path,max(67108864,strlen($raw)),true);
    m4uib_assert($actual['raw']===$raw,'COMPLETE_PUBLIC_OUTPUT_READBACK');return $actual['descriptor'];
}
function m4uicg_diagnostic(Throwable $error):array
{
    return ['class'=>get_class($error),'code'=>$GLOBALS['m4uicg_code']??$GLOBALS['m4uib_code']??'COPY_LIBRARY_OR_NATIVE_EXCEPTION',
        'file'=>basename($error->getFile()),'line'=>$error->getLine()];
}
function m4uicg_result(array $source,bool $success,?array $materials,?array $diagnostic):array
{
    $value=['schema'=>1,'scope'=>'NORMAL_MS4_UI_PUBLIC_COPY_ONLY',
        'status'=>$success?'VERIFIED_NORMAL_MS4_UI_PUBLIC_COPY_ONLY':'INCOMPLETE',
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
        'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'phase'=>$success?'final_source':($GLOBALS['m4uicg_phase']??'source'),
        'entry_return_code'=>$success?0:1,'termination'=>$success?'NORMAL_RETURN':'INCOMPLETE',
        'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED','full_source_unchanged'=>$success,
        'materials_candidate'=>$materials,'diagnostic'=>$diagnostic,'App_HTTP_DOM'=>'NOT RUN','business_acceptance'=>'NOT RUN',
        'full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN',
        'assertions'=>($GLOBALS['m4uicg_assertions']??0)+($GLOBALS['m4uib_assertions']??0)];
    m4uib_keys($value,M4UICG_RESULT_KEYS);return $value;
}
function m4uicg_shutdown():void
{
    $source=$GLOBALS['m4uicg_source']??null;if(!is_array($source))return;
    $last=error_get_last();$fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
    $diagnostic=$GLOBALS['m4uicg_primary']??null;
    if($fatal&&$diagnostic===null)$diagnostic=['class'=>'FatalError','code'=>'ACTUAL_FATAL','file'=>basename($last['file']),'line'=>$last['line']];
    $value=['schema'=>1,'scope'=>'MS4_UI_PUBLIC_COPY_SHUTDOWN','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],'phase'=>$GLOBALS['m4uicg_phase']??'source',
        'completed'=>($GLOBALS['m4uicg_completed']??false)&&!$fatal,
        'result_published'=>$GLOBALS['m4uicg_result_published']??false,'fatal'=>$fatal,
        'termination_requested'=>$GLOBALS['m4uicg_terminate_requested']??false,'primary_failure'=>$diagnostic,
        'cleanup_failure'=>null,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'];
    try{m4uib_keys($value,M4UICG_SHUTDOWN_KEYS);m4uicg_publish('ui-public-copy-shutdown.json',$value);}
    catch(Throwable $error){fwrite(STDERR,json_encode(['shutdown_publication'=>'INCOMPLETE','diagnostic'=>m4uicg_diagnostic($error)],JSON_UNESCAPED_SLASHES)."\n");}
}
function m4uicg_main():int
{
    umask(0077);$GLOBALS['m4uicg_phase']='source';$GLOBALS['m4uicg_completed']=false;
    $GLOBALS['m4uicg_result_published']=false;$GLOBALS['m4uicg_terminate_requested']=false;
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if(!(error_reporting()&$severity))return false;throw new ErrorException('COPY_NATIVE_WARNING',0,$severity,$file,$line);});
    register_shutdown_function('m4uicg_shutdown');
    $source=null;
    try{
        m4uicg_assert(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal'),'NATIVE_SIGNAL_OBSERVATION');
        pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4uicg_terminate_requested']=true;});
        $source=m4uicg_source();$GLOBALS['m4uicg_source']=$source;$protected=m4uicg_protected($source);
        m4uicg_preserved($source,$protected,false);m4uib_assert(empty($GLOBALS['m4uicg_terminate_requested']),'COPY_NOT_TERMINATED_BEFORE_WRITE');
        $library='/run/ms4-prep/ms4-ui-public-copy.php';$pin=m4uib_file($library)['descriptor'];
        m4uib_assert($pin['bytes']===25847&&$pin['sha256']==='0f2a7944b25db42cbf935816b4b9366a599049a28ac77e32bab167b08fec47d0','UNCHANGED_COPIER_LIBRARY_PIN');
        require_once $library;m4uicg_preserved($source,$protected,false);$GLOBALS['m4uicg_phase']='copy';
        $identity=['source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],'source_identity'=>[
            'proof_name'=>'ui-public-copy','role'=>'audit-ms4-ui-public-copy','command'=>[M4UICG_ENTRY,'copy'],
            'container_id'=>$source['container_id'],'native_created_sha256'=>$source['native_created_sha256']]];
        $materials=m4uic_copy($source['copy_profile_value'],$source['run_id'],$identity);
        m4uib_keys($materials,['schema','scope','run_id','source_sha','freeze_sha256','files','directories','builds']);
        m4uib_assert($materials['schema']===1&&$materials['scope']==='MS4_UI_PUBLIC_MATERIALS_COMPLETE'
            &&$materials['run_id']===$source['run_id']&&$materials['source_sha']===$source['source_sha']
            &&$materials['freeze_sha256']===$source['freeze_sha256'],'ACTUAL_LIBRARY_COMPLETE_MATERIALS');
        $tree=m4uic_tree('/workspace/crmeb/public',true);
        m4uib_assert(array_values($tree['files'])===$materials['files']&&$tree['directories']===$materials['directories']
            &&m4uib_same($materials['builds'],$source['copy_profile_value']['builds']),'ACTUAL_MATERIALS_FULL_TREE_AND_BUILD_DESCRIPTORS');
        $GLOBALS['m4uicg_phase']='final_source';m4uicg_preserved($source,$protected,true);
        m4uib_assert(empty($GLOBALS['m4uicg_terminate_requested']),'COPY_NOT_TERMINATED_BEFORE_PUBLICATION');
        $candidate=m4uicg_publish('ui-public-materials-produced.json',$materials);
        m4uicg_publish('ui-public-copy-result.json',m4uicg_result($source,true,$candidate,null));
        $GLOBALS['m4uicg_result_published']=true;$GLOBALS['m4uicg_completed']=true;
        fwrite(STDOUT,"NORMAL_MS4_UI_PUBLIC_COPY_ONLY_NATIVE_EXIT_REQUIRES_HOST\n");return 0;
    }catch(Throwable $error){
        $diagnostic=m4uicg_diagnostic($error);$GLOBALS['m4uicg_primary']=$diagnostic;
        if(is_array($source)&&empty($GLOBALS['m4uicg_result_published'])){
            try{m4uicg_publish('ui-public-copy-result.json',m4uicg_result($source,false,null,$diagnostic));$GLOBALS['m4uicg_result_published']=true;}
            catch(Throwable $publication){fwrite(STDERR,json_encode(['result_publication'=>'INCOMPLETE','diagnostic'=>m4uicg_diagnostic($publication)],JSON_UNESCAPED_SLASHES)."\n");}}
        fwrite(STDERR,json_encode(['public_copy'=>'INCOMPLETE','diagnostic'=>$diagnostic],JSON_UNESCAPED_SLASHES)."\n");return 1;
    }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(m4uicg_main());
